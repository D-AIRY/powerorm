<?php

/*
* This file is part of the powerorm package.
*
* (c) Eddilbert Macharia <edd.cowan@gmail.com>
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Eddmash\PowerOrm\Model\Query;

use Doctrine\DBAL\Connection;
use Eddmash\PowerOrm\BaseOrm;
use Eddmash\PowerOrm\Db\ConnectionInterface;
use Eddmash\PowerOrm\Exception\InvalidArgumentException;
use Eddmash\PowerOrm\Exception\MultipleObjectsReturned;
use Eddmash\PowerOrm\Exception\NotImplemented;
use Eddmash\PowerOrm\Exception\NotSupported;
use Eddmash\PowerOrm\Exception\ObjectDoesNotExist;
use Eddmash\PowerOrm\Exception\TypeError;
use Eddmash\PowerOrm\Exception\ValueError;
use Eddmash\PowerOrm\Helpers\ArrayHelper;
use Eddmash\PowerOrm\Helpers\Node;
use Eddmash\PowerOrm\Helpers\Tools;
use Eddmash\PowerOrm\Model\Field\Field;
use Eddmash\PowerOrm\Model\Model;
use Eddmash\PowerOrm\Model\Query\Results\ArrayFlatValueMapper;
use Eddmash\PowerOrm\Model\Query\Results\ArrayMapper;
use Eddmash\PowerOrm\Model\Query\Results\ArrayValueMapper;
use Eddmash\PowerOrm\Model\Query\Results\ModelMapper;
use Eddmash\PowerOrm\Serializer\SimpleObjectSerializer;
use function Eddmash\PowerOrm\Model\Query\Expression\not_;
use function Eddmash\PowerOrm\Model\Query\Expression\q_;

const PRIMARY_KEY_ID = 'pk';

/**
 * Represents a lazy database lookup for a set of objects.
 *
 * @since  1.1.0
 *
 * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
 */
class Queryset implements QuerysetInterface, \JsonSerializable
{
    /**
     * @var ConnectionInterface
     */
    public $connection;

    /**
     * @var Model
     */
    public $model;

    public $resultMapper;

    /**
     * @var Query
     */
    public $query;

    public $_evaluated = false;

    /**
     * @var mixed Holds the Queryset Result when Queryset evaluates
     *
     * @internal
     */
    protected $_resultsCache;

    /**
     * @var array Holds fields that will be used in the asArray()
     */
    private $_fields;

    protected $kwargs = [];

    public function __construct(
        ConnectionInterface $connection = null,
        Model $model = null,
        Query $query = null,
        $kwargs = []
    ) {
        $this->connection = (is_null($connection)) ? $this->getConnection() : $connection;
        $this->model = $model;
        $this->query = (null == $query) ? $this->getQueryBuilder() : $query;
        $this->resultMapper = ArrayHelper::pop(
            $kwargs,
            'resultMapper',
            ModelMapper::class
        );
        $this->kwargs = $kwargs;
    }

    /**
     * @return ConnectionInterface
     *
     * @since  1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    private function getConnection()
    {
        return BaseOrm::getDbConnection();
    }

    /**
     * @param ConnectionInterface $connection
     * @param Model               $model
     * @param Query               $query
     * @param array               $kwargs
     *
     * @return self
     *
     * @since  1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public static function createObject(
        Connection $connection = null,
        Model $model = null,
        Query $query = null,
        $kwargs = []
    ) {
        return new static($connection, $model, $query, $kwargs);
    }

    /**
     * Specifies an item that is to be returned in the query result.
     * Replaces any previously specified selections, if any.
     *
     * <code>
     *     $qb = $conn->createQueryBuilder()
     *         ->select('u.id', 'p.id')
     *         ->from('users', 'u')
     *         ->leftJoin('u', 'phonenumbers', 'p', 'u.id = p.user_id');
     * </code>
     */
    public function only($select = null)
    {
        $selects = is_array($select) ? $select : func_get_args();
        $this->query->addSelect($selects, true);
    }

    /**
     * @return mixed
     *
     * @throws MultipleObjectsReturned
     * @throws ObjectDoesNotExist
     */
    public function get()
    {
        $queryset = $this->_filterOrExclude(
            false,
            static::formatConditions(__METHOD__, func_get_args())
        );

        $resultCount = count($queryset);

        if (1 == $resultCount):
            return $queryset->getResults()[0];
        elseif (!$resultCount):
            throw new ObjectDoesNotExist(
                sprintf(
                    '%s matching query does not exist.',
                    $this->model->getMeta()->getNSModelName()
                )
            );
        endif;

        throw new MultipleObjectsReturned(
            sprintf(
                '"get() returned more than one %s -- it returned %s!"',
                $this->model->getMeta()->getNSModelName(),
                $resultCount
            )
        );
    }

    /**
     * This method takes associative array as parameters. or an assocative array
     * whose first item is the connector to
     * use for the generated where conditions, Valid choices are :.
     *
     * <code>
     *   Role::objects()->filter(
     *      ["name"=>"asdfasdf"],
     *      ["or","name__not"=>"tr"],
     *      ["and","name__in"=>"we"],
     *      ["or", "name__contains"=>"qwe"]
     *  );
     * </code>
     *
     * @return Queryset
     *
     * @since  1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function filter()
    {
        return $this->_filterOrExclude(
            false,
            static::formatConditions(__METHOD__, func_get_args())
        );
    }

    /**
     * Return a query set in which the returned objects have been annotated
     * with extra data or aggregations.
     *
     * @return Queryset
     *
     * @since  1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function annotate()
    {
        $args = static::formatConditions(__METHOD__, func_get_args());

        $names = $this->_fields;
        if (is_null($this->_fields)):
            $names = [];
            foreach ($this->model->getMeta()->getFields() as $field) :
                $names[] = $field->getName();
            endforeach;
        endif;
        $clone = $this->_clone();
        foreach ($args as $alias => $arg) :
            if (in_array($alias, $names)):
                throw new ValueError(
                    sprintf("The annotation '%s' conflicts with a field on the model.", $alias)
                );
            endif;
            $clone->query->addAnnotation(['annotation' => $arg, 'alias' => $alias, 'isSummary' => false]);
        endforeach;

        foreach ($clone->query->annotations as $alias => $annotation) :
            if ($annotation->containsAggregates() && array_key_exists($alias, $args)):
                if (is_null($clone->_fields)):
                    $clone->query->groupBy = true;
                else:
                    $clone->query->setGroupBy();
                endif;
            endif;
        endforeach;

        return $clone;
    }

    /**
     * Returns a new QuerySet instance with the ordering changed.
     *
     * @since  1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     *
     * @param array $fieldNames
     *
     * @return Queryset
     */
    public function orderBy($fieldNames = [])
    {
        assert(
            $this->query->isFilterable(),
            'Cannot reorder a query once a limiting has been done.'
        );
        $clone = $this->_clone();
        $clone->query->clearOrdering(false);
        $clone->query->addOrdering($fieldNames);

        return $clone;
    }

    /**
     * @param array $kwargs
     *
     * @return array
     *
     * @throws TypeError
     *
     * @since  1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function aggregate($kwargs = [])
    {
        //todo accept non associative items
        $query = $this->query->deepClone();
        foreach ($kwargs as $alias => $annotation) :
            $query->addAnnotation(
                [
                    'annotation' => $annotation,
                    'alias' => $alias,
                    'isSummary' => true,
                ]
            );
            // ensure we have an aggregated function
            if (!$query->annotations[$alias]->containsAggregates()) :
                throw new TypeError(
                    sprintf('%s is not an aggregate expression', $alias)
                );
            endif;
        endforeach;

        return $query->getAggregation($this->connection, array_keys($kwargs));
    }

    /**
     * Returns a new QuerySet instance that will select related objects.
     *
     * If fields are specified, they must be ForeignKey fields and only those related objects are included in the
     * selection.
     *
     * If select_related(null) is called, the list is cleared.
     *
     *
     * @param array $fields
     *
     * @return Queryset
     *
     * @throws InvalidArgumentException
     * @throws TypeError
     * @author: Eddilbert Macharia (http://eddmash.com)<edd.cowan@gmail.com>
     */
    public function selectRelated($fields = [])
    {
        Tools::ensureParamIsArray($fields);

        if ($this->_fields) :
            throw new TypeError('Cannot call selectRelated() after .values() or .asArray()');
        endif;
        $obj = $this->_clone();

        if (empty($fields)):
            $obj->query->selectRelected = false;
        elseif ($fields):
            $obj->query->addSelectRelected($fields);
        else:
            $obj->query->selectRelected = true;
        endif;

        return $obj;
    }

    public function prefetchRelated()
    {
        throw new NotImplemented(__METHOD__.' NOT IMPLEMENTED');
    }

    public function exclude()
    {
        return $this->_filterOrExclude(
            true,
            static::formatConditions(__METHOD__, func_get_args())
        );
    }

    public function exists()
    {
        if (!$this->_resultsCache):
            $instance = $this->all()->limit(0, 1);

            return $instance->query->hasResults($this->connection);
        endif;

        return (bool) $this->_resultsCache;
    }

    /**
     * @param $offset
     * @param $size
     *
     * @return $this
     *
     * @since  1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function limit($offset, $size)
    {
        $this->query->setLimit($offset, $size);

        return $this;
    }

    /**
     * Returns the number of rows affected by the last DELETE, INSERT, or UPDATE statement
     * executed by the corresponding object.
     *
     * If the last SQL statement executed by the associated Statement object was a SELECT statement,
     * some databases may return the number of rows returned by that statement. However,
     * this behaviour is not guaranteed for all databases and should not be
     * relied on for portable applications.
     *
     * @throws \Doctrine\DBAL\DBALException
     *
     * @since  1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function size()
    {
        if ($this->_resultsCache):
            return count($this->_resultsCache);
        endif;

        return $this->query->getCount($this->connection);
    }

    public function update()
    {
    }

    public function _update($records)
    {
        /** @var $clone UpdateQuery */
        $clone = $this->query->deepClone(UpdateQuery::class);
        $clone->addUpdateFields($records);

        return $clone->getSqlCompiler($this->connection)->executeSql();
    }

    /**
     * @param Model $model
     * @param       $fields
     * @param       $returnId
     *
     * @return mixed
     */
    public function _insert(Model $model, $fields, $returnId)
    {
        $qb = $this->connection->createQueryBuilder();

        $qb->insert($model->getMeta()->getDbTable());

        /** @var $field Field */
        foreach ($fields as $name => $field) :
            $value = $this->prepareValueForDatabaseSave(
                $field,
                $field->preSave($model, true)
            );

            $qb->setValue($field->getColumnName(), $qb->createNamedParameter($value));
        endforeach;

        // save to db
        $qb->execute();

        if ($returnId):
            return $this->connection->lastInsertId();
        endif;
    }

    /**
     * @param Field $field
     * @param       $preSave
     * @author: Eddilbert Macharia (http://eddmash.com)<edd.cowan@gmail.com>
     *
     * @return mixed
     */
    private function prepareValueForDatabaseSave(Field $field, $value)
    {
        return $field->prepareValueBeforeSave($value, $this->connection);
    }

    protected function _filterOrExclude($negate, $conditions)
    {
        $instance = $this->_clone();

        if ($negate):
            $instance->query->addQ(not_($conditions));
        else:
            $instance->query->addQ(q_($conditions));
        endif;

        return $instance;
    }

    /**
     * Ensure the conditions passed in are ready to used to perform query operations.
     *
     * @param $methondname
     * @param $conditions
     *
     * @return mixed
     *
     * @throws InvalidArgumentException
     *
     * @since  1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public static function formatConditions($methondname, $conditions)
    {
        if (count($conditions) > 1):
            throw new InvalidArgumentException(
                sprintf("Method '%s' supports a single array input", $methondname)
            );
        endif;

        if (1 == count($conditions)):
            if ($conditions[0] instanceof Node):
                return $conditions;
            endif;
        endif;

        $conditions = (empty($conditions)) ? [[]] : $conditions;

        return call_user_func_array('array_merge', $conditions);
    }

    /**
     * Returns a new QuerySet that is a copy of the current one.
     *
     * This allows a QuerySet to proxy for a model manager in some cases.
     *
     * @return $this
     *
     * @since  1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function all()
    {
        return $this->_clone();
    }

    /**
     * Gets the complete SQL string formed by the current specifications of this QueryBuilder.
     *
     * <code>
     *     $qb = User::objects()
     *         ->select('u')
     *         ->from('User', 'u')
     *     echo $qb->getSQL(); // SELECT u FROM User u
     * </code>
     *
     * @return string
     *
     * @since  1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function getSql()
    {
        $instance = $this->_clone();

        list($sql, $params) = $instance->query->getSqlCompiler($instance->connection)->asSql();

        $sql = str_replace('?', '%s', $sql);

        return vsprintf($sql, $params);
    }

    /**
     * @return mixed
     *
     * @since  1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function getResults()
    {
        if (false === $this->_evaluated):
            $this->_resultsCache = call_user_func($this->getMapper());

            $this->_evaluated = true;
        endif;

        return $this->_resultsCache;
    }

    public function getMapper()
    {
        return new $this->resultMapper($this);
    }

    /**
     * Returns the results as an array of associative array that represents a
     * record in the database.
     *
     * The orm does not try map the into  there  respective models.
     *
     * @param array $fields     the fields to select, if null all fields in the
     *                          model are selected
     * @param bool  $valuesOnly if true return
     * @param bool  $flat       if true returns the results as one array others
     *                          it returns results as array of arrays each
     *                          which represents a record in the database for the
     *                          selected field.
     *                          (only works when valueOnly is true)
     *
     * @return Queryset
     *
     * @since  1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function asArray($fields = [], $valuesOnly = false, $flat = false)
    {
        if ($flat && 1 != count($fields)):
            throw new TypeError(
                "'flat' is valid when asArray is called".
                ' with exactly one field.'
            );
        endif;
        if ($flat && !$valuesOnly):
            throw new TypeError(
                "'flat' is valid when asArray ".
                'is called with valuesOnly=true.'
            );
        endif;
        $clone = $this->_clone();
        $clone->_fields = $fields;
        $clone->query->setValueSelect($fields);

        $clone->resultMapper = ($valuesOnly) ? ArrayValueMapper::class :
            ArrayMapper::class;
        if ($flat):
            $clone->resultMapper = ArrayFlatValueMapper::class;
        endif;

        return $clone;
    }

    /**
     * @return Query
     *
     * @throws \Eddmash\PowerOrm\Exception\NotImplemented
     *
     * @since  1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    private function getQueryBuilder()
    {
        return Query::createObject($this->model);
    }

    // **************************************************************************************************

    // ************************************** MAGIC METHODS Overrides ***********************************

    // **************************************************************************************************

    /**
     * Returns the number of rows affected by the last DELETE, INSERT, or UPDATE statement
     * executed by the corresponding object.
     *
     * If the last SQL statement executed by the associated Statement object was a SELECT statement,
     * some databases may return the number of rows returned by that statement. However,
     * this behaviour is not guaranteed for all databases and should not be
     * relied on for portable applications.
     *
     * @internal
     *
     * @throws \Doctrine\DBAL\DBALException
     *
     * @since  1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function count()
    {
        $this->getResults();

        return count($this->_resultsCache);
    }

    /**
     * Evaluates the Queryset when Queryset Result is used in a foreach.
     *
     * @ignore
     *
     * @internal
     *
     * @return \ArrayIterator
     */
    public function getIterator()
    {
        $this->getResults();

        return new \ArrayIterator($this->_resultsCache);
    }

    /**
     * {@inheritdoc}
     */
    public function offsetExists($offset)
    {
        $this->getResults();

        return isset($this->_resultsCache[$offset]);
    }

    /**
     * {@inheritdoc}
     */
    public function offsetGet($offset)
    {
        $exists = $this->offsetExists($offset);

        return isset($exists) ? $this->_resultsCache[$offset] : null;
    }

    /**
     * {@inheritdoc}
     */
    public function offsetSet($offset, $value)
    {
        throw new NotSupported('set/unset operations are not supported by Queryset');
    }

    /**
     * {@inheritdoc}
     */
    public function offsetUnset($offset)
    {
        throw new NotSupported('set/unset operations are not supported by Queryset');
    }

    /**
     * @return Queryset
     *
     * @since  1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function _clone()
    {
        $qb = $this->query->deepClone();

        $kwargs = array_merge(['resultMapper' => $this->resultMapper], $this->kwargs);

        return self::createObject($this->connection, $this->model, $qb, $kwargs);
    }

    public function __toString()
    {
        $results = $this->_clone();
        if (!$results->query->limit && !$results->query->offset) :
            $results = $results->limit(1, 6);
        endif;

        $results = $results->getResults();

        $ellipse = count($results) > 5 ? ', ... ' : '';

        return sprintf('< %s (%s %s) >', get_class($this), implode(', ', $results), $ellipse);
    }

    public function __debugInfo()
    {
        return $this->_clone()->getResults();
    }

    /**
     * Ready this instance for use as argument in filter.
     *
     * @since  1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function _prepareAsFilterValue()
    {
        if (is_null($this->_fields)):
            $queryset = $this->asArray(['pk']);
        else:
            if (count($this->_fields) > 1):
                throw new TypeError('Cannot use multi-field values as a filter value.');
            endif;
            $queryset = $this->_clone();
        endif;

        return $queryset->query->toSubQuery($queryset->connection);
    }

    /**
     * Specify data which should be serialized to JSON.
     *
     * @see  http://php.net/manual/en/jsonserializable.jsonserialize.php
     *
     * @return mixed data which can be serialized by <b>json_encode</b>,
     *               which is a value of any type other than a resource
     *
     * @since 5.4.0
     */
    public function jsonSerialize()
    {
        return SimpleObjectSerializer::serialize($this);
    }
}
