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
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Types\Type;
use Eddmash\PowerOrm\BaseObject;
use Eddmash\PowerOrm\CloneInterface;
use Eddmash\PowerOrm\Exception\FieldDoesNotExist;
use Eddmash\PowerOrm\Exception\FieldError;
use Eddmash\PowerOrm\Exception\ValueError;
use Eddmash\PowerOrm\Helpers\ArrayHelper;
use Eddmash\PowerOrm\Helpers\Node;
use Eddmash\PowerOrm\Helpers\StringHelper;
use Eddmash\PowerOrm\Helpers\Tools;
use Eddmash\PowerOrm\Model\Field\Field;
use Eddmash\PowerOrm\Model\Field\RelatedField;
use Eddmash\PowerOrm\Model\Field\RelatedObjects\ForeignObjectRel;
use Eddmash\PowerOrm\Model\Lookup\BaseLookup;
use Eddmash\PowerOrm\Model\Lookup\LookupInterface;
use Eddmash\PowerOrm\Model\Meta;
use Eddmash\PowerOrm\Model\Model;
use Eddmash\PowerOrm\Model\Query\Aggregates\BaseAggregate;
use Eddmash\PowerOrm\Model\Query\Compiler\SqlFetchBaseCompiler;
use Eddmash\PowerOrm\Model\Query\Expression\BaseExpression;
use Eddmash\PowerOrm\Model\Query\Expression\Col;
use Eddmash\PowerOrm\Model\Query\Expression\ExpResolverInterface;
use Eddmash\PowerOrm\Model\Query\Expression\ResolvableExpInterface;
use Eddmash\PowerOrm\Model\Query\Joinable\BaseJoin;
use Eddmash\PowerOrm\Model\Query\Joinable\BaseTable;
use Eddmash\PowerOrm\Model\Query\Joinable\Join;
use Eddmash\PowerOrm\Model\Query\Joinable\WhereNode;
use const Eddmash\PowerOrm\Model\Query\Expression\AND_CONNECTOR;
use const Eddmash\PowerOrm\Model\Query\Expression\ORDER_PATTERN;
use function Eddmash\PowerOrm\Model\Query\Expression\count_;

const INNER = 'INNER JOIN';
const LOUTER = 'LEFT OUTER JOIN';
const ORDER_DIR = [
    'ASC' => ['ASC', 'DESC'],
    'DESC' => ['DESC', 'ASC'],
];

class Query extends BaseObject implements ExpResolverInterface, CloneInterface
{
    //[
    //  BaseLookup::AND_CONNECTOR => [],
    //  BaseLookup::OR_CONNECTOR => [],
    //];
    public $offset;
    public $limit;

    /** @var WhereNode */
    public $where;
    public $tablesAlias = [];
    public $tableAliasMap = [];
    public $selectRelected = [];
    /**
     * @var BaseJoin[]
     */
    public $tableAlias = [];
    public $aliasRefCount = [];

    /**
     * @var Col[]
     */
    public $select = [];
    public $valueSelect = [];

    public $isSubQuery = false;

    /**
     * @var Model
     */
    public $model;

    /**
     * if true, get the columns to fetch from the model itself.
     *
     * @var
     */
    public $useDefaultCols = true;

    /**
     * @var BaseAggregate[]
     */
    public $annotations = [];
    public $distict;
    public $distictFields = [];
    public $orderBy = [];
    public $defaultOrdering = true;

    // Arbitrary limit for select_related to prevents infinite recursion.
    public $maxDepth = 5;
    public $columnInfoCache;
    public $usedTableAlias = [];
    public $groupBy;

    /**
     * @var bool Dictates if the order by is done in the asc or desc manner,
     *           true indicates the asc manner
     */
    public $standardOrdering = true;
    /**
     * @var string
     */
    private $whereClass;

    /**
     * Query constructor.
     *
     * @param Model     $model
     * @param WhereNode $whereClass
     *
     * @internal param string $where
     */
    public function __construct(Model $model, $whereClass = WhereNode::class)
    {
        $this->selectRelected = false;
        $this->model = $model;
        $this->where = $whereClass::createObject();
        $this->whereClass = $whereClass;
    }

    public static function createObject(Model $model)
    {
        return new self($model);
    }

    public static function getOrderDirection($orderName, $defaultOrder = 'ASC')
    {
        $order = ORDER_DIR['ASC'];

        if (StringHelper::startsWith($orderName, '-')):
            return [str_replace('-', '', $orderName), $order[1]];
        endif;

        return [$orderName, $order[0]];
    }

    public function getNestedSql(Connection $connection)
    {

        return $this->asSql($connection, true);
    }

    public function addSelect(Col $col)
    {
        $this->useDefaultCols = false;
        $this->select[] = $col;
    }

    public function addFields($fieldNames, $allowM2M = true)
    {
        $alias = $this->getInitialAlias();
        $meta = $this->getMeta();

        foreach ($fieldNames as $fieldName) :
            $names = StringHelper::split(BaseLookup::$lookupPattern, $fieldName);
            list($field, $targets, $joinList, $paths) = $this->setupJoins($names, $meta, $alias);

            /** @var $targets Field[] */
            list($targets, $finalAlias, $joinList) = $this->trimJoins($targets, $joinList, $paths);

            foreach ($targets as $target) :
                $this->addSelect($target->getColExpression($finalAlias));
            endforeach;

        endforeach;

    }

    public function clearSelectedFields()
    {
        $this->select = [];
        $this->valueSelect = [];
    }

    /**
     * @return WhereNode
     */
    public function getWhere()
    {
        return $this->where;
    }

    /**
     * @param $lookup
     * @param $rhs
     * @param $lhs Col
     *
     * @return LookupInterface
     *
     * @since 1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    private function buildCondition($lookup, $lhs, $rhs)
    {
        $lookup = (array) $lookup;
        $lookup = $lhs->getLookup($lookup[0]);
        /* @var $lookup LookupInterface */
        $lookup = $lookup::createObject($lhs, $rhs);

        return $lookup;
    }

    /**
     * Builds a WhereNode for a single filter clause, but doesn't add it to this Query. Query.add_q() will then add
     * this filter to the where Node.
     *
     * @param $condition
     *
     * @return array
     *
     * @since 1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    private function buildFilter($conditions, $connector = AND_CONNECTOR, $allowJoins = true, &$canReuse = null)
    {
        reset($conditions);
        $name = key($conditions);
        $value = current($conditions);
        list($lookups, $fieldParts) = $this->solveLookupType($name);
        list($value, $lookups, $usedJoins) = $this->prepareLookupValue($value, $lookups, $canReuse, $allowJoins);
        //todo joins
        $whereClass = ($this->whereClass);
        $clause = $whereClass::createObject();
        $meta = $this->getMeta();
        $alias = $this->getInitialAlias();

        list($field, $targets, $joinList, $paths) = $this->setupJoins(
            $fieldParts,
            $meta,
            $alias
        );

        if (!is_null($canReuse)):
            foreach ($joinList as $list):
                $canReuse[] = $list;
            endforeach;
        endif;
        $usedJoins = array_merge(array_unique($usedJoins), array_unique($joinList));

        /* @var $targets Field[] */
        /* @var $field Field */
        list($targets, $alias, $joinList) = $this->trimJoins($targets, $joinList, $paths);

        if ($field->isRelation) :
            $lookupClass = $field->getLookup($lookups[0]);
            $col = $targets[0]->getColExpression($alias, $field);
            $condition = $lookupClass::createObject($col, $value);
        else:
            $col = $targets[0]->getColExpression($alias, $field);
            $condition = $this->buildCondition($lookups, $col, $value);
        endif;

        $clause->add($condition, AND_CONNECTOR);

        //todo joins
        return [$clause, $usedJoins];
    }

    /**
     * @param array $valueSelect
     */
    public function setValueSelect($valueSelect)
    {
        $this->valueSelect[] = $valueSelect;
    }

// where (
//      blog_text='men are' and (
//                              headline='matt' or
//                              name = 'adsd' or
//                              not (pub_date=2012)
//                              )
// )
//$users = \App\Models\Entry::objects()->filter([
//    "blog_text" => "men are",
//    or_([
//        'headline' => 'matt',
//        "n_comments" => "adsd"
//    ])
//]);
    public function addQ(Q $q)
    {
        $clause = $this->_addQ($q, $this->usedTableAlias)[0];

        if ($clause):
            $this->where->add($clause, AND_CONNECTOR);
        endif;
        //todo work on joins
    }

    private function _addQ(Q $q, &$usedAliases, $allowJoins = true, $currentNegated = false)
    {
        $connector = $q->getConnector();

        // current is true only if one and only is true.
        $currentNegated = $currentNegated ^ $q->isNegated();

        $whereClass = ($this->whereClass);
        $targetClause = $whereClass::createObject(null, $connector, $q->isNegated());

        $joinpromoter = new JoinPromoter($connector, count($q->getChildren()), $currentNegated);
        foreach ($q->getChildren() as $child) :
            if ($child instanceof Node):
                list($childClause, $neededInner) = $this->_addQ($child, $usedAliases, $allowJoins);
            else:
                list($childClause, $neededInner) = $this->buildFilter($child, $connector, $allowJoins, $usedAliases);
            endif;

            if ($childClause):
                $targetClause->add($childClause, $connector);
            endif;
            $joinpromoter->addVotes($neededInner);
        endforeach;
        //todo join
//        $neededInner = $joinpromoter->updateJoinType($this);

        return [$targetClause, null];
    }

    public function setGroupBy()
    {
    }

    /**
     * Adds items from the 'ordering' sequence to the query's "order by" clause. These items are either
     * field names (not column names) -- possibly with a direction prefix ('-' or '?') -- or OrderBy
     * expressions.
     *
     * If 'ordering' is empty, all ordering is cleared from the query.
     *
     * @param $fieldNames
     *
     * @since 1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function addOrdering($fieldNames = [])
    {
        $errors = [];

        foreach ($fieldNames as $fieldName) :
            if (!$fieldName instanceof ResolvableExpInterface &&
                !preg_match(ORDER_PATTERN, $fieldName)
            ):
                $errors[] = $fieldName;
            endif;

            if (property_exists($fieldName, 'containsAggregate')):
                throw new FieldError(
                    sprintf(
                        'Using an aggregate in order_by() without also including '.
                        'it in annotate() is not allowed: %s',
                        $fieldName
                    )
                );
            endif;
        endforeach;

        if ($errors):
            throw new FieldError(sprintf('Invalid order_by arguments: %s', json_encode($errors)));
        endif;

        if ($fieldNames):
            $this->orderBy = array_merge($this->orderBy, $fieldNames);
        else:
            $this->defaultOrdering = false;
        endif;
    }

    public function getMeta()
    {
        return $this->model->meta;
    }

    /**
     * @param Connection $connection
     *
     * @return SqlFetchBaseCompiler
     *
     * @since 1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function getSqlCompiler(Connection $connection)
    {
        $compiler = $this->getCompilerClass();

        return new $compiler($this, $connection);
    }

    /**
     * Return the class to use when compiling this query into an sql string.
     *
     * @return string
     *
     * @since 1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    protected function getCompilerClass()
    {
        return SqlFetchBaseCompiler::class;
    }

    private function solveLookupType($name)
    {
        // get lookupand field
        $split_names = StringHelper::split(BaseLookup::$lookupPattern, $name);

        $paths = $this->getNamesPath($split_names, $this->getMeta());
        $lookup = $paths['others'];

        $fieldParts = [];
        foreach ($split_names as $name) :
            if (in_array($name, $lookup)) :
                continue;
            endif;
            $fieldParts[] = $name;
        endforeach;

        if (count($lookup) === 0) :
            $lookup[] = 'exact';
        elseif (count($fieldParts) > 1):
            if (!$fieldParts) :
                throw new FieldError(
                    sprintf(
                        'Invalid lookup "%s" for model %s".',
                        $name,
                        $this->getMeta()->getNamespacedModelName()
                    )
                );
            endif;
        endif;

        return [$lookup, $fieldParts];
    }

    private function prepareLookupValue($value, $lookups, &$canReuse, $allowJoins = true)
    {
        $usedJoins = [];
        if (empty($lookups)):
            $lookups = ['exact'];
        endif;

        // Interpret '__exact=None' as the sql 'is NULL'; otherwise, reject all
        // uses of null as a query value.
        if (is_null($value)):
            if (!in_array(array_pop($lookups), ['exact'])):
                throw new ValueError('Cannot use "null" as a query value');
            endif;

            return [true, ['isnull']];
        elseif ($value instanceof ResolvableExpInterface):
            $preJoins = $this->aliasRefCount;
            $value = $value->resolveExpression($this, $allowJoins, $canReuse);
            foreach ($this->aliasRefCount as $key => $count) :
                if ($count > ArrayHelper::getValue($preJoins, $key, 0)):
                    $usedJoins[] = $key;
                endif;
            endforeach;
        endif;

        //todo if value is array
        return [$value, $lookups, $usedJoins];
    }

    /**
     * @param $names
     * @param Meta $meta
     * @param bool $failOnMissing
     *
     * @return array
     *
     * @throws FieldError
     */
    public function getNamesPath($names, Meta $meta, $failOnMissing = false)
    {
        $paths = $targets = [];
        $finalField = null;

        $posReached = 0;

        /* @var $field Field|RelatedField|ForeignObjectRel */
        foreach ($names as $pos => $name) :

            $posReached = $pos;

            if ($name === PRIMARY_KEY_ID):
                $name = $meta->primaryKey->getName();
            endif;

            $field = null;

            try {
                $field = $meta->getField($name);

            } catch (FieldDoesNotExist $e) {
                //todo check in annotations to
                $available = getFieldNamesFromMeta($meta);
                if ($failOnMissing) :

                    throw new FieldError(
                        sprintf(
                            "Cannot resolve keyword '%s.%s' into field. Choices are: [ %s ]",
                            $meta->getNamespacedModelName(),
                            $name,
                            implode(', ', $available)
                        )
                    );
                else:
                    break;
                endif;
            }

            // todo Check if we need any joins for concrete inheritance cases (the
            // field lives in parent, but we are currently in one of its
            // children)

            if ($field->hasMethod('getPathInfo')) :

                $pathsInfos = $field->getPathInfo();
                $last = $pathsInfos[count($pathsInfos) - 1];

                $finalField = ArrayHelper::getValue($last, 'joinField');
                $targets = ArrayHelper::getValue($last, 'targetFields');
                $meta = ArrayHelper::getValue($last, 'toMeta');
                $paths = array_merge($paths, $pathsInfos);
            else:
                // none relational field
                $finalField = null;
                $finalField = $field;

                $targets = [$field];
                // no need to go on since this is a none relation field.
                break;
            endif;
        endforeach;

        return [
            'paths' => $paths,
            'finalField' => $finalField,
            'targets' => $targets,
            'others' => array_slice($names, $posReached + 1),
        ];
    }

    public function setLimit($offset, $limit)
    {
        $this->offset = $offset;
        $this->limit = $limit;
    }

    public function getInitialAlias()
    {
        if ($this->tablesAlias):
            // get the first one
            $alias = $this->tablesAlias[0];
        else:
            $alias = $this->join(new BaseTable($this->getMeta()->dbTable, null));
        endif;

        return $alias;
    }

    /**
     * @param $names
     * @param Meta $meta
     * @param $alias
     *
     * @return array
     *
     * @since 1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function setupJoins($names, Meta $meta, $alias)
    {
        if (is_null($alias)):
            $alias = $this->getInitialAlias();
        endif;
        $joins = [$alias];

        $namesPaths = $this->getNamesPath($names, $meta, true);

        $pathInfos = $namesPaths['paths'];

        /* @var $meta Meta */
        foreach ($pathInfos as $pathInfo) :
            $meta = $pathInfo['toMeta'];

            if ($pathInfo['direct']):
                $nullable = $this->isNullable($pathInfo['joinField']);
            else:
                $nullable = true;
            endif;
            $join = new Join();
            $join->setTableName($meta->dbTable);
            $join->setParentAlias($alias);
            $join->setJoinType(INNER);
            $join->setJoinField($pathInfo['joinField']);
            $join->setNullable($nullable);

            $alias = $this->join($join);

            $joins[] = $alias;
        endforeach;

        return [$namesPaths['finalField'], $namesPaths['targets'], $joins, $pathInfos, $meta];
    }

    public function join(BaseJoin $join, $reuse = null)
    {
        // check if we can resuse an alias
        $resuableAliases = [];
        foreach ($this->tableAliasMap as $key => $item) :
            if (($reuse == null || ArrayHelper::hasKey($reuse, $key)) && $join->equal($item)):
                $resuableAliases[] = $key;
            endif;
        endforeach;

        if ($resuableAliases):
            $this->aliasRefCount[$resuableAliases[0]] += 1;

            return $resuableAliases[0];
        endif;

        list($alias) = $this->getTableAlias($join->getTableName(), false);
        if ($join->getJoinType()):
            if ($this->tableAliasMap[$join->getParentAlias()]->getJoinType() === LOUTER || $join->getNullable()):

                $joinType = LOUTER;
            else:
                $joinType = INNER;
            endif;
            $join->setJoinType($joinType);
        endif;

        $join->setTableAlias($alias);
        $this->tableAliasMap[$alias] = $join;
        $this->tablesAlias[] = $alias;

        return $alias;
    }

    /**
     * Change join type from LOUTER to INNER for all joins in aliases.
     *
     * Similarly to promoteJoins(), this method must ensure no join chains containing first an outer, then an inner
     * join are generated.
     * If we are demoting {A->C} join in chain {A LOUTER B LOUTER C} then we must demote {A->B} automatically, or
     * otherwise the demotion of {A->B} doesn't actually change anything in the query results. .
     *
     * @param array $aliases
     *
     * @since 1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function demoteJoins($aliases = [])
    {
        /* @var $join Join */
        /* @var $parent Join */
        while ($aliases):
            $alias = array_pop($aliases);
            $join = $this->tableAliasMap[$alias];
            if ($join->getJoinType() == LOUTER):
                $this->tableAliasMap[$alias] = $join->demote();
                $parent = $this->tableAliasMap[$join->getParentAlias()];
                if ($parent->getJoinType() == INNER):
                    $aliases[] = $join->getParentAlias();
                endif;
            endif;
        endwhile;
    }

    public function prmoteJoins($aliases)
    {

    }

    /**
     * @param $targets
     * @param $joinList
     * @param $path
     *
     * @return array
     *
     * @since 1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function trimJoins($targets, $joinList, $path)
    {
        /* @var $joinField RelatedField */
        /* @var $field Field */
        /* @var $relField Field[] */

        foreach (array_reverse($path) as $info) :
            if (!$info['direct'] || count($joinList) === 1):
                break;
            endif;

            $joinTargets = [];
            $currentTargets = [];
            $joinField = $info['joinField'];

            foreach ($joinField->getForeignRelatedFields() as $field) :
                $joinTargets[] = $field->getColumnName();
            endforeach;

            foreach ($targets as $field) :
                $currentTargets[] = $field->getColumnName();
            endforeach;

            if (!array_intersect($joinTargets, $currentTargets)):
                break;
            endif;

            $relFields = [$joinField->getRelatedFields()];
            $relMap = [];
            foreach ($relFields as $relField) :
                if (in_array($relField[1]->getColumnName(), $currentTargets)):
                    $relMap[$relField[1]->getColumnName()] = $relField[0];
                endif;
            endforeach;

            $targetsNew = [];
            foreach ($targets as $target) :
                $targetsNew[] = $relMap[$target->getColumnName()];
            endforeach;
            $targets = $targetsNew;

            $this->unrefAlias(array_pop($joinList));
        endforeach;

        $alias = array_slice($joinList, -1)[0];

        return [$targets, $alias, $joinList];
    }

    private function unrefAlias($alias, $amount = 1)
    {
        $this->aliasRefCount[$alias] -= $amount;
    }

    public function getTableAlias($tableName, $create = false)
    {
        if (ArrayHelper::hasKey($this->tableAlias, $tableName) && false === $create):
            $alias = ArrayHelper::getValue($this->tableAlias, $tableName);
            $this->aliasRefCount[$alias] += 1;

            return [$alias, false];
        endif;

        $alias = $tableName;
        $this->tableAlias[$alias] = $alias;
        $this->aliasRefCount[$alias] = 1;

        return [$alias, true];
    }

    public function addAnnotation($kwargs = [])
    {
        /** @var $annotation BaseExpression */
        $annotation = ArrayHelper::getValue($kwargs, 'annotation');
        $alias = ArrayHelper::getValue($kwargs, 'alias');
        $isSummary = ArrayHelper::getValue($kwargs, 'isSummary', false);

        $annotation = $annotation->resolveExpression($this, true, null, $isSummary);

        $this->annotations[$alias] = $annotation;

    }

    /**
     * Sets up the selectRelated data structure so that we only select certain related models
     * (as opposed to all models, when $this->selectRelated=true).
     *
     * @param array $fields
     *
     * @since 1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function addSelectRelected($fields = [])
    {
        if (is_bool($this->selectRelected)):
            $relatedFields = [];
        else:
            $relatedFields = $this->selectRelected;
        endif;

        foreach ($fields as $field) :
            $names = StringHelper::split(BaseLookup::$lookupPattern, $field);
            // we use by reference so that we assigned the values back to the original array
            $d = &$relatedFields;
            foreach ($names as $name) :
                $d = &$d[$name];
                if (empty($d)):
                    $d = [];
                endif;
            endforeach;
        endforeach;
        $this->selectRelected = $relatedFields;
    }

    /**
     * @return array
     *
     * @since 1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function getAggregation(Connection $connection, $addedAggregateNames = [])
    {
        if (!$this->annotations):
            return [];
        endif;
        $hasLimit = ($this->offset || $this->limit);
        $hasExistingAnnotations = false;
        foreach ($this->annotations as $alias => $annotation) :

            $hasExistingAnnotations = ($hasExistingAnnotations || !in_array($alias, $addedAggregateNames));
        endforeach;

        // we have of this we need to make the core query a subquery and aggregate over it.
        if ($hasExistingAnnotations || $hasLimit || $this->distict):
            $outQuery = new AggregateQuery($this->model);
            $innerQuery = $this->deepClone();

            $innerQuery->selectRelected = false;

            if (!$hasLimit && !$this->distictFields):
                // Queries with distinct_fields need ordering and when a limit
                // is applied we must take the slice from the ordered query.
                // Otherwise no need for ordering, so clear.
                $innerQuery->clearOrdering(true);
            endif;

            if (!$innerQuery->distict):
                // if we are using default columns and we already have aggregate annotations existing
                // then we must make sure the inner
                // query is grouped by the main model's primary key. However,
                // clearing the select clause can alter results if distinct is
                // used.
                if ($innerQuery->useDefaultCols && $hasExistingAnnotations):
                    $innerQuery->groupBy = [
                        $this->getMeta()->primaryKey->getColExpression($innerQuery->getInitialAlias()),
                    ];
                endif;
                $innerQuery->useDefaultCols = false;
            endif;

            // add annotations to the outerquery todo
            foreach ($this->annotations as $alias => $annotation) :
                $outQuery->annotations[$alias] = $annotation;
                unset($innerQuery->annotations[$alias]);
            endforeach;

            if ($innerQuery->select == [] && !$innerQuery->useDefaultCols):
                $innerQuery->select = [
                    $this->getMeta()->primaryKey->getColExpression(
                        $innerQuery->getInitialAlias()
                    ),
                ];
            endif;

            $outQuery->addSubQuery($innerQuery, $connection);
        else:
            $outQuery = $this;
            $outQuery->select = [];
            $outQuery->useDefaultCols = false;
        endif;

        $outQuery->clearOrdering(true);
        $outQuery->clearLimits();
        $outQuery->selectRelected = false;

        $results = $outQuery->getSqlCompiler($connection)->executeSql()->fetch();

        $result = [];
        foreach (array_combine(array_keys($this->annotations), array_values($results)) as $key => $item) {
            $result[$key] = $item;
        }

        return $result;
    }

    /**
     * Removes any ordering settings.
     *
     * @param bool $forceEmpty If True, there will be no ordering in the resulting query (not even the model's
     *                         default)
     *
     * @since 1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function clearOrdering($forceEmpty = false)
    {
        $this->orderBy = [];
        if ($forceEmpty):
            $this->defaultOrdering = false;
        endif;
    }

    /**
     * Clears any existing limits.
     *
     * @since 1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function clearLimits()
    {
        $this->limit = $this->offset = null;
    }

    /**
     * Returns True if adding filters to this instance is still possible.
     *
     * Typically, this means no limits or offsets have been put on the results.
     *
     * @return bool
     *
     * @since 1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function isFilterable()
    {
        return empty($this->offset) && empty($this->limit);
    }

    public function getCount(Connection $connection)
    {
        $obj = $this->deepClone();
        $obj->addAnnotation(['annotation' => count_('*'), 'alias' => '_count', 'isSummary' => true]);
        $alias = '_count';
        $result = $obj->getAggregation($connection, [$alias]);

        return ArrayHelper::getValue($result, $alias, 0);
    }

    /**
     * @param string $class
     *
     * @return $this
     *
     * @since 1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function deepClone($class = null)
    {

        $class = (is_null($class)) ? static::class : $class;
        /** @var $obj Query */
        $obj = new $class($this->model);
        $obj->aliasRefCount = $this->aliasRefCount;
        $obj->useDefaultCols = $this->useDefaultCols;
        $obj->tableAlias = $this->tableAlias;
        $obj->tableMap = $this->tableAliasMap;
        $obj->select = $this->select;
        $obj->selectRelected = $this->selectRelected;
        $obj->standardOrdering = $this->standardOrdering;
        $obj->annotations = $this->annotations;
        $obj->defaultOrdering = $this->defaultOrdering;
        $obj->orderBy = $this->orderBy;
        $obj->offset = $this->offset;
        $obj->limit = $this->limit;
        $obj->where = $this->where->deepClone();

        return $obj;
    }

    public function getResultsIterator(Connection $connection)
    {
        $preparedResults = [];

        // since php pdo normally returns an assoc array, we ask it return the values in form an array indexed
        // by column number as returned in the corresponding result set, starting at column 0.
        // this to avoid issues where joins result in columns with the same name e.g. user.id joined by blog.id
        $results = $this->execute($connection)->fetchAll(\PDO::FETCH_NUM);
        foreach ($results as $row) :
            $preparedResults[] = $this->preparedResults($connection, $row);
        endforeach;

        return $preparedResults;
    }

    /**
     * Gets all names for the fields in the query model, inclusing reverse fields.
     *
     * @since 1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function getFieldChoices()
    {
        $fields = [];
        foreach ($this->getMeta()->getFields() as $field) :
            if (!$field->isRelation):
                continue;
            endif;
            $fields[] = $field->getName();
        endforeach;

        foreach ($this->getMeta()->getReverseRelatedObjects() as $reverseRelatedObject) :
            if ($reverseRelatedObject->relation->fromField->isUnique()):
                $fields[] = $reverseRelatedObject->relation->fromField->getRelatedQueryName();
            endif;
        endforeach;

        return $fields;
    }

    /**
     * We check if a field is nullable.
     *
     * @param Field $joinField
     *
     * @since 1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     *
     * @return bool
     */
    private function isNullable($joinField)
    {
        return $joinField->isNull();
    }

    /**
     * Ensure results are converted back to there respective php types.
     *
     * @param $values
     *
     * @return array
     *
     * @since 1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function preparedResults(Connection $connection, $values)
    {
        $preparedValues = [];

        /* @var $col Col */
        /* @var $field Field */
        foreach ($this->select as $pos => $selectColumn) :
            $col = $selectColumn[0];
            $field = $col->getOutputField();
            $val = ArrayHelper::getValue($values, $pos);
            // first use the inbuilt converters
            try {
                $val = Type::getType(
                    $field->dbType($connection)
                )->convertToPHPValue($val, $connection->getDatabasePlatform());
            } catch (DBALException $exception) {
            }

            // use the field converters if any were provided by the user.
            $converters = $field->getDbConverters($connection);

            if ($converters):
                foreach ($converters as $converter) :
                    $val = call_user_func($converter, $connection, $val, $field);
                endforeach;
            endif;
            $preparedValues[] = $val;

        endforeach;

        return $preparedValues;
    }

    public function resolveExpression($name, $allowJoins = true, &$reuse = null, $summarize = false)
    {
        if (!$allowJoins && StringHelper::contains($name, BaseLookup::LOOKUP_SEPARATOR)):
            throw new FieldError('Joined field references are not permitted in this query');
        endif;

        if (ArrayHelper::hasKey($this->annotations, $name)):
            if ($summarize):
                //todo
            else:
                return ArrayHelper::getValue($this->annotations, $name);
            endif;
        else:
            $splitNames = StringHelper::split(BaseLookup::$lookupPattern, $name);

            list($field, $sources, $joinList, $paths) = $this->setupJoins(
                $splitNames,
                $this->getMeta(),
                $this->getInitialAlias()
            );

            /* @var $targets Field[] */
            /* @var $field Field */

            list($targets, $finalAlias, $joinList) = $this->trimJoins($sources, $joinList, $paths);
            if (count($targets) > 1):
                throw new FieldError("Referencing multicolumn fields with F() objects isn't supported");
            endif;

            if (!is_null($reuse)):
                foreach ($joinList as $item) :
                    $reuse[] = $item;
                endforeach;
            endif;

            $col = $targets[0]->getColExpression(array_pop($joinList), $sources[0]);

            return $col;
        endif;

    }

}

/**
 * @param Model[]        $instances
 * @param Prefetch|array $lookups
 *
 * @since 1.1.0
 *
 * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
 */
function prefetchRelatedObjects($instances, $lookups)
{
    if (!$lookups instanceof Prefetch):
        $msg = sprintf("method '%s()' expects parameter 'lookup' to be an array", __FUNCTION__);
        Tools::ensureParamIsArray($lookups, $msg);
    endif;

    if (count($instances) == 0):
        return;
    endif;

    //We need to be able to dynamically add to the list of prefetch_related
    //lookups that we look up (see below).  So we need some book keeping to
    //ensure we don't do duplicate work.
    $doneQueries = [];  // assoc_array of things like 'foo__bar': [results]
    $lookups = normalizePrefetchLookup($lookups);

    /* @var $lookup Prefetch */
    while ($lookups):
        $lookup = array_shift($lookups);

        // have already worked on a lookup that has similar name
        if (array_key_exists($lookup->prefetchTo, $doneQueries)):

            // does this lookup contain a queryset
            // this means its not a duplication but a different request just containing the same name
            if ($lookup->queryset):
                throw new ValueError(
                    sprintf(
                        "'%s' lookup was already seen with a different queryset. ".
                        'You may need to adjust the ordering of your lookups.',
                        $lookup->prefetchTo
                    )
                );

            endif;

            // just pass this is just a duplication
            continue;
        endif;

        $objList = $instances;

        $throughtAttrs = StringHelper::split(BaseLookup::$lookupPattern, $lookup->prefetchThrough);
        foreach ($throughtAttrs as $level => $throughtAttr) :
            if (count($objList) == 0):
                break;
            endif;

            $prefetchTo = $lookup->getCurrentPrefetchTo($level);

            if (array_key_exists($prefetchTo, $doneQueries)):
                $objList = ArrayHelper::getValue($doneQueries, $prefetchTo);
                continue; //if its already fetched skip it
            endif;
        endforeach;
    endwhile;

}

/**
 * Enusures all prefetch looks are of the same form i.e. instance of Prefetch.
 *
 * @since 1.1.0
 *
 * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
 *
 * @param $lookups
 * @param null $prefix
 *
 * @return Prefetch[]
 */
function normalizePrefetchLookup($lookups, $prefix = null)
{

    $results = [];
    foreach ($lookups as $lookup) :
        if (!$lookup instanceof Prefetch):
            $lookup = new Prefetch($lookup);
        endif;
        if ($prefix):
            $lookup->addPrefix($prefix);
        endif;
        $results[] = $lookup;
    endforeach;

    return $results;
}
