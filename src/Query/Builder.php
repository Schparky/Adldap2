<?php

namespace Adldap\Query;

use Adldap\Exceptions\ModelNotFoundException;
use Adldap\Exceptions\InvalidQueryOperatorException;
use Adldap\Models\Entry;
use Adldap\Objects\Paginator;
use Adldap\Schemas\ActiveDirectory;
use Adldap\Classes\Utilities;
use Adldap\Connections\ConnectionInterface;

class Builder
{
    /**
     * The field key for a where statement.
     *
     * @var string
     */
    public static $whereFieldKey = 'field';

    /**
     * The operator key for a where statement.
     *
     * @var string
     */
    public static $whereOperatorKey = 'operator';

    /**
     * The value key for a where statement.
     *
     * @var string
     */
    public static $whereValueKey = 'value';

    /**
     * Stores the column selects to use in the query when assembled.
     *
     * @var array
     */
    public $selects = [];

    /**
     * Stores the current where filters
     * on the current query.
     *
     * @var array
     */
    public $wheres = [];

    /**
     * Stores the current or where filters
     * on the current query.
     *
     * @var array
     */
    public $orWheres = [];

    /**
     * Stores the field to sort search results by.
     *
     * @var string
     */
    protected $sortByField = '';

    /**
     * The distinguished name to perform searches upon.
     *
     * @var string|null
     */
    protected $dn;

    /**
     * The model class mappings.
     *
     * @var array
     */
    protected $mappings = [
        ActiveDirectory::OBJECT_CATEGORY_COMPUTER   => 'Adldap\Models\Computer',
        ActiveDirectory::OBJECT_CATEGORY_PERSON     => 'Adldap\Models\User',
        ActiveDirectory::OBJECT_CATEGORY_GROUP      => 'Adldap\Models\Group',
        ActiveDirectory::MS_EXCHANGE_SERVER         => 'Adldap\Models\ExchangeServer',
        ActiveDirectory::OBJECT_CATEGORY_CONTAINER  => 'Adldap\Models\Container',
        ActiveDirectory::OBJECT_CATEGORY_PRINTER    => 'Adldap\Models\Printer',
    ];

    /**
     * Stores the bool to determine whether or not
     * to search LDAP recursively.
     *
     * @var bool
     */
    protected $recursive = true;

    /**
     * Stores the bool to determine whether or not
     * to search LDAP on the base scope.
     *
     * @var bool
     */
    protected $read = false;

    /**
     * Stores the bool to determine whether or not
     * to return LDAP results in their raw format.
     *
     * @var bool
     */
    protected $raw = false;

    /**
     * Stores the current connection instance.
     *
     * @var ConnectionInterface
     */
    protected $connection;

    /**
     * Stores the current grammar instance.
     *
     * @var Grammar
     */
    protected $grammar;

    /**
     * Constructor.
     *
     * @param ConnectionInterface $connection
     * @param Grammar             $grammar
     */
    public function __construct(ConnectionInterface $connection, Grammar $grammar)
    {
        $this->connection = $connection;
        $this->grammar = $grammar;
    }

    /**
     * Returns a new Query Builder instance.
     *
     * @return Builder
     */
    public function newInstance()
    {
        return new self($this->connection, $this->grammar);
    }

    /**
     * Returns the current query.
     *
     * @return string
     */
    public function get()
    {
        return $this->query($this->getQuery());
    }

    /**
     * Compiles and returns the current query string.
     *
     * @return string
     */
    public function getQuery()
    {
        return $this->grammar->compileQuery($this);
    }

    /**
     * Returns the current Grammar instance.
     *
     * @return Grammar
     */
    public function getGrammar()
    {
        return $this->grammar;
    }

    /**
     * Returns the current Connection instance.
     *
     * @return ConnectionInterface
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Returns the builders DN to perform
     * searches upon.
     *
     * @return string
     */
    public function getDn()
    {
        return $this->dn;
    }

    /**
     * Sets the DN to perform searches upon.
     *
     * @param string|null $dn
     *
     * @return Builder
     */
    public function setDn($dn = null)
    {
        $this->dn = $dn;

        return $this;
    }

    /**
     * Performs the specified query on the current LDAP connection.
     *
     * @param string $query
     *
     * @return array|bool
     */
    public function query($query)
    {
        $dn = $this->getDn();

        $selects = $this->getSelects();

        if ($this->read) {
            // If read is true, we'll perform a read search, retrieving one record
            $results = $this->connection->read($dn, $query, $selects);
        } elseif ($this->recursive) {
            // If recursive is true, we'll perform a recursive search
            $results = $this->connection->search($dn, $query, $selects);
        } else {
            // Read and recursive is false, we'll return a listing
            $results = $this->connection->listing($dn, $query, $selects);
        }

        if ($results) {
            if (!empty($this->sortByField)) {
                $this->connection->sort($results, $this->sortByField);
            }

            return $this->processResults($results);
        }

        return false;
    }

    /**
     * Paginates the current LDAP query.
     *
     * @param int  $perPage
     * @param int  $currentPage
     * @param bool $isCritical
     *
     * @return Paginator|bool
     */
    public function paginate($perPage = 50, $currentPage = 0, $isCritical = true)
    {
        // Stores all LDAP entries in a page array
        $pages = [];

        $cookie = '';

        do {
            $this->connection->controlPagedResult($perPage, $isCritical, $cookie);

            $results = $this->connection->search($this->getDn(), $this->getQuery(), $this->getSelects());

            if ($results) {
                $this->connection->controlPagedResultResponse($results, $cookie);

                $pages[] = $results;
            }
        } while ($cookie !== null && !empty($cookie));

        if (count($pages) > 0) {
            return $this->processPaginatedResults($pages, $perPage, $currentPage);
        }

        return false;
    }

    /**
     * Returns the first entry in a search result.
     *
     * @return array|bool
     */
    public function first()
    {
        $results = $this->get();

        if (is_array($results) && array_key_exists(0, $results)) {
            return $results[0];
        }

        // No entries were returned, return false
        return false;
    }

    /**
     * Returns the first entry in a search result.
     *
     * If no entry is found, an exception is thrown.
     *
     * @throws ModelNotFoundException
     *
     * @return array|bool
     */
    public function firstOrFail()
    {
        $record = $this->first();

        if (!$record) {
            $message = 'Unable to find record in Active Directory.';

            throw new ModelNotFoundException($message);
        }

        return $record;
    }

    /**
     * Finds a record using ambiguous name resolution.
     *
     * @param string $anr
     *
     * @return bool|Entry
     */
    public function find($anr)
    {
        return $this->whereEquals(ActiveDirectory::ANR, $anr)->first();
    }

    /**
     * Finds a record using ambiguous name resolution. If a record
     * is not found, an exception is thrown.
     *
     * @param string $anr
     *
     * @throws ModelNotFoundException
     *
     * @return array|bool
     */
    public function findOrFail($anr)
    {
        $entry = $this->find($anr);

        // Make sure we check if the result is an entry or an array before
        // we throw an exception in case the user wants raw results.
        if (!$entry instanceof Entry && !is_array($entry)) {
            $message = 'Unable to find record in Active Directory.';

            throw new ModelNotFoundException($message);
        }

        return $entry;
    }

    /**
     * Finds a record by its distinguished name.
     *
     * @param string $dn
     *
     * @return bool|Entry
     */
    public function findByDn($dn)
    {
        return $this->setDn($dn)
            ->read(true)
            ->where(ActiveDirectory::OBJECT_CLASS, '*')
            ->first();
    }

    /**
     * Finds the Base DN of your domain controller.
     *
     * @return string|bool
     */
    public function findBaseDn()
    {
        $result = $this
            ->setDn(null)
            ->read()
            ->raw()
            ->where(ActiveDirectory::OBJECT_CLASS, '*')
            ->first();

        $key = ActiveDirectory::DEFAULT_NAMING_CONTEXT;

        if (is_array($result) && array_key_exists($key, $result)) {
            if (array_key_exists(0, $result[$key])) {
                return $result[$key][0];
            }
        }

        return false;
    }

    /**
     * Adds the inserted fields to query on the current LDAP connection.
     *
     * @param array|string $fields
     *
     * @return Builder
     */
    public function select($fields = [])
    {
        if (is_array($fields)) {
            foreach ($fields as $field) {
                $this->addSelect($field);
            }
        } elseif (is_string($fields)) {
            $this->addSelect($fields);
        }

        return $this;
    }

    /**
     * Adds a where clause to the current query.
     *
     * @param string      $field
     * @param string|null $operator
     * @param string|null $value
     *
     * @return Builder
     */
    public function where($field, $operator = null, $value = null)
    {
        $this->addWhere($field, $operator, $value);

        return $this;
    }

    /**
     * Adds a where equals clause to the current query.
     *
     * @param string $field
     * @param string $value
     *
     * @return Builder
     */
    public function whereEquals($field, $value)
    {
        $this->where($field, '=', $value);

        return $this;
    }

    /**
     * Adds a where has clause to the current query.
     *
     * @param string $field
     *
     * @return Builder
     */
    public function whereHas($field)
    {
        $this->where($field, '*');

        return $this;
    }

    /**
     * Adds a where contains clause to the current query.
     *
     * @param string $field
     * @param string $value
     *
     * @return Builder
     */
    public function whereContains($field, $value)
    {
        $this->where($field, Operator::$contains, $value);

        return $this;
    }

    /**
     * Adds a where starts with clause to the current query.
     *
     * @param string $field
     * @param string $value
     *
     * @return Builder
     */
    public function whereStartsWith($field, $value)
    {
        $this->where($field, Operator::$startsWith, $value);

        return $this;
    }

    /**
     * Adds a where ends with clause to the current query.
     *
     * @param string $field
     * @param string $value
     *
     * @return Builder
     */
    public function whereEndsWith($field, $value)
    {
        $this->where($field, Operator::$endsWith, $value);

        return $this;
    }

    /**
     * Adds an or where clause to the current query.
     *
     * @param string      $field
     * @param string|null $operator
     * @param string|null $value
     *
     * @return Builder
     */
    public function orWhere($field, $operator = null, $value = null)
    {
        $this->addOrWhere($field, $operator, $value);

        return $this;
    }

    /**
     * Adds an or where equals clause to the current query.
     *
     * @param string $field
     * @param string $value
     *
     * @return Builder
     */
    public function orWhereEquals($field, $value)
    {
        $this->orWhere($field, '=', $value);

        return $this;
    }

    /**
     * Adds an or where contains clause to the current query.
     *
     * @param string $field
     * @param string $value
     *
     * @return Builder
     */
    public function orWhereContains($field, $value)
    {
        $this->orWhere($field, Operator::$contains, $value);

        return $this;
    }

    /**
     * Adds an or where starts with clause to the current query.
     *
     * @param string $field
     * @param string $value
     *
     * @return Builder
     */
    public function orWhereStartsWith($field, $value)
    {
        $this->orWhere($field, Operator::$startsWith, $value);

        return $this;
    }

    /**
     * Adds an or where ends with clause to the current query.
     *
     * @param string $field
     * @param string $value
     *
     * @return Builder
     */
    public function orWhereEndsWith($field, $value)
    {
        $this->orWhere($field, Operator::$endsWith, $value);

        return $this;
    }

    /**
     * Returns true / false depending if the current object
     * contains selects.
     *
     * @return bool
     */
    public function hasSelects()
    {
        if (count($this->selects) > 0) {
            return true;
        }

        return false;
    }

    /**
     * Returns the current selected fields to retrieve.
     *
     * @return array
     */
    public function getSelects()
    {
        $selects = $this->selects;

        if (count($selects) > 0) {
            // Always make sure object category and distinguished
            // name are included in the selected fields
            $selects[] = ActiveDirectory::OBJECT_CATEGORY;
            $selects[] = ActiveDirectory::DISTINGUISHED_NAME;
        }

        return $selects;
    }

    /**
     * Returns the wheres on the current search object.
     *
     * @return array
     */
    public function getWheres()
    {
        return $this->wheres;
    }

    /**
     * Returns the or wheres on the current search object.
     *
     * @return array
     */
    public function getOrWheres()
    {
        return $this->orWheres;
    }

    /**
     * Sorts the LDAP search results by the
     * specified field and direction.
     *
     * @param string $field
     *
     * @return Builder
     */
    public function sortBy($field)
    {
        $this->sortByField = $field;

        return $this;
    }

    /**
     * Sets the recursive property to tell the search
     * whether or not to search recursively.
     *
     * @param bool $recursive
     *
     * @return Builder
     */
    public function recursive($recursive = true)
    {
        $this->recursive = (bool) $recursive;

        return $this;
    }

    /**
     * Sets the recursive property to tell the search
     * whether or not to search on the base scope and
     * return a single entry.
     *
     * @param bool $read
     *
     * @return Builder
     */
    public function read($read = true)
    {
        $this->read = (bool) $read;

        return $this;
    }

    /**
     * Sets the recursive property to tell the search
     * whether or not to return the LDAP results in
     * their raw format.
     *
     * @param bool $raw
     *
     * @return Builder
     */
    public function raw($raw = true)
    {
        $this->raw = (bool) $raw;

        return $this;
    }

    /**
     * Returns a new LDAP Entry instance.
     *
     * @param array $attributes
     *
     * @return Entry
     */
    public function newLdapEntry(array $attributes)
    {
        $attribute = ActiveDirectory::OBJECT_CATEGORY;

        if (array_key_exists($attribute, $attributes) && array_key_exists(0, $attributes[$attribute])) {
            // We'll explode the DN so we can grab it's object category.
            $category = Utilities::explodeDn($attributes[$attribute][0]);

            // Make sure the category string exists in the attribute array
            if(array_key_exists(0, $category)) {
                $category = strtolower($category[0]);

                if(array_key_exists($category, $this->mappings)) {
                    $model = $this->mappings[$category];

                    return (new $model([], $this))->setRawAttributes($attributes);
                }
            }
        }

        // A default entry object if the object category isn't recognized.
        return (new Entry([], $this))->setRawAttributes($attributes);
    }

    /**
     * Adds the inserted field to the selects property.
     *
     * @param string $field
     */
    private function addSelect($field)
    {
        // We'll make sure the field isn't empty
        // before we add it to the selects
        if (!empty($field)) {
            $this->selects[] = $field;
        }
    }

    /**
     * Adds the inserted field, operator and value
     * to the wheres property array.
     *
     * @param string $field
     * @param string $operator
     * @param null   $value
     *
     * @throws InvalidQueryOperatorException
     */
    private function addWhere($field, $operator, $value = null)
    {
        $this->wheres[] = [
            self::$whereFieldKey    => $field,
            self::$whereOperatorKey => $this->getOperator($operator),
            self::$whereValueKey    => Utilities::escape($value),
        ];
    }

    /**
     * Adds the inserted field, operator and value
     * to the orWheres property array.
     *
     * @param string $field
     * @param string $operator
     * @param null   $value
     *
     * @throws InvalidQueryOperatorException
     */
    private function addOrWhere($field, $operator, $value = null)
    {
        $this->orWheres[] = [
            self::$whereFieldKey    => $field,
            self::$whereOperatorKey => $this->getOperator($operator),
            self::$whereValueKey    => Utilities::escape($value),
        ];
    }

    /**
     * Processes LDAP search results into a nice array.
     *
     * @param resource $results
     *
     * @return array
     */
    private function processResults($results)
    {
        $entries = $this->connection->getEntries($results);

        if ($this->raw) {
            return $entries;
        } else {
            $objects = [];

            if (array_key_exists('count', $entries)) {
                for ($i = 0; $i < $entries['count']; $i++) {
                    $objects[] = $this->newLdapEntry($entries[$i]);
                }
            }

            return $objects;
        }
    }

    /**
     * Processes paginated LDAP results.
     *
     * @param array $pages
     * @param int   $perPage
     * @param int   $currentPage
     *
     * @return Paginator|bool
     */
    private function processPaginatedResults($pages, $perPage = 50, $currentPage = 0)
    {
        // Make sure we have at least one page of results
        if (count($pages) > 0) {
            $objects = [];

            // Go through each page and process the results into an objects array
            foreach ($pages as $results) {
                $objects = array_merge($objects, $this->processResults($results));
            }

            // Return a new Paginator instance
            return new Paginator($objects, $perPage, $currentPage, count($pages));
        }

        // Looks like we don't have any results, return false
        return false;
    }

    /**
     * Retrieves an operator from the available operators.
     *
     * Throws an AdldapException if no operator is found.
     *
     * @param string $operator
     *
     * @throws InvalidQueryOperatorException
     *
     * @return string
     */
    private function getOperator($operator)
    {
        $operators = Operator::all();

        $key = array_search(strtolower($operator), $operators);

        if ($key !== false && array_key_exists($key, $operators)) {
            return $operators[$key];
        }

        $operators = implode(', ', $operators);

        $message = "Operator: $operator cannot be used in an LDAP query. Available operators are: $operators";

        throw new InvalidQueryOperatorException($message);
    }
}
