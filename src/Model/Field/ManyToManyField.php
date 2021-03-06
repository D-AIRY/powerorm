<?php

/*
* This file is part of the powerorm package.
*
* (c) Eddilbert Macharia <edd.cowan@gmail.com>
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Eddmash\PowerOrm\Model\Field;

use Eddmash\PowerOrm\Checks\CheckWarning;
use Eddmash\PowerOrm\Form\Fields\ModelMultipleChoiceField;
use Eddmash\PowerOrm\Helpers\ArrayHelper;
use Eddmash\PowerOrm\Helpers\StringHelper;
use Eddmash\PowerOrm\Helpers\Tools;
use Eddmash\PowerOrm\Migration\FormatFileContent;
use Eddmash\PowerOrm\Model\Delete;
use Eddmash\PowerOrm\Model\Field\Inverse\HasManyField;
use Eddmash\PowerOrm\Model\Field\Inverse\InverseField;
use Eddmash\PowerOrm\Model\Field\RelatedObjects\ForeignObjectRel;
use Eddmash\PowerOrm\Model\Field\RelatedObjects\ManyToManyRel;
use Eddmash\PowerOrm\Model\Meta;
use Eddmash\PowerOrm\Model\Model;

/**
 * Provide a many-to-many relation by using an intermediary model that holds two ForeignKey fields pointed at the two
 * sides of the relation.
 *
 * Unless a ``through`` model was provided, ManyToManyField will use the createManyToManyIntermediaryModel factory
 * to automatically generate the intermediary model.
 *
 * @since  1.1.0
 *
 * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
 */
class ManyToManyField extends RelatedField
{
    /**
     * {@inheritdoc}
     */
    public $manyToMany = true;

    public $dbTable;

    /**
     * {@inheritdoc}
     *
     * @var ManyToManyRel
     */
    public $relation;

    public $m2mField;

    public $m2mReverseField;

    private $hasNullKwarg;

    protected $descriptor = '\Eddmash\PowerOrm\Model\Field\Descriptors\ManyToManyDescriptor';

    public $inverseField = '\Eddmash\PowerOrm\Model\Field\Inverse\HasManyField';

    public function __construct($kwargs)
    {
        if (!isset($kwargs['rel']) || (isset($kwargs['rel']) && is_null($kwargs['rel']))):
            $kwargs['rel'] = ManyToManyRel::createObject(
                [
                    'fromField' => $this,
                    'to' => ArrayHelper::getValue($kwargs, 'to'),
                    'relatedName' => ArrayHelper::getValue($kwargs, 'relatedName'),
                    'relatedQueryName' => ArrayHelper::getValue($kwargs, 'relatedQueryName'),
                    'through' => ArrayHelper::getValue($kwargs, 'through'),
                    'throughFields' => ArrayHelper::getValue($kwargs, 'throughFields'),
                    'dbConstraint' => ArrayHelper::getValue($kwargs, 'dbConstraint', true),
                ]
            );
        endif;

        $this->hasNullKwarg = ArrayHelper::hasKey($kwargs, 'null');

        parent::__construct($kwargs);
    }

    /**
     * {@inheritdoc}
     */
    public function contributeToClass($fieldName, $modelObject)
    {
        parent::contributeToClass($fieldName, $modelObject);

        // if through model is set
        if (!is_null($this->relation->through)):
            $callback = function ($kwargs) {
                /* @var $field RelatedField */
                /** @var $related Model */
                $related = $kwargs['relatedModel'];
                $field = $kwargs['fromField'];

                $field->relation->through = $related;
                $field->doRelatedClass($related, $this->relation);
            };

            Tools::lazyRelatedOperation(
                $callback,
                $this->scopeModel,
                $this->relation->through,
                ['fromField' => $this]
            );
        else:
            $this->relation->through = $this->createM2MIntermediaryModel(
                $this,
                $this->scopeModel
            );
        endif;
    }

    /**
     * {@inheritdoc}
     */
    public function contributeToInverseClass(Model $relatedModel, ForeignObjectRel $relation)
    {
        $inverseFields = $relatedModel->getMeta()->getFields(
            false,
            true,
            false
        );
        $rM = $this->relation->toModel;
        if ($rM instanceof Model):
            $rM = $this->relation->toModel->getMeta()->getNSModelName();
        endif;
        $relName = null;
        foreach ($inverseFields as $inverseField) :
            $sM = $inverseField->scopeModel->getMeta()->getNSModelName();
            if ($inverseField instanceof InverseField):
                if ($sM === $rM && $this->name == $inverseField->toField):
                    $relName = $inverseField->getName();
                    $relation->relatedName = $relName;
                    break;
                endif;
            endif;

        endforeach;
        $hasMany = HasManyField::createObject(
            [
                'to' => $this->scopeModel->getMeta()->getNSModelName(),
                'toField' => $relation->fromField->getName(),
                'fromField' => $this,
                'autoCreated' => true,
            ]
        );

        $relatedModel->addToClass($relation->getAccessorName(), $hasMany);

        $this->m2mField = function () use ($relation) {
            return $this->getM2MAttr($relation, 'name');
        };
        $this->m2mReverseField = function () use ($relation) {
            return $this->getM2MReverseAttr($relation, 'name');
        };
    }

    /**
     * Creates an intermediary model.
     *
     * @param ManyToManyField $field
     * @param Model           $model
     *
     * @return Model
     *
     * @since  1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function createM2MIntermediaryModel($field, $model)
    {
        $modelName = $model->getMeta()->getNSModelName();

        if (is_string($field->relation->toModel)):
            $toModelName = Tools::resolveRelation(
                $model,
                $field->relation->toModel
            );
            $ref = new \ReflectionClass($toModelName);
            $toModelName = $ref->getShortName();
            $toNamespacedModelName = $ref->getName();
        else:
            $toModelName = $field->relation->toModel->getMeta()->getModelName();
            $toNamespacedModelName = $field->relation->toModel
                ->getMeta()->getNSModelName();
        endif;

        $className = sprintf(
            '%1$s_%2$s',
            $model->getMeta()->getModelName(),
            $field->getName()
        );
        $from = strtolower($model->getMeta()->getModelName());
        $to = strtolower($toModelName);
        if ($from == $to):
            $to = sprintf('to_%s', $to);
            $from = sprintf('from_%s', $from);
        endif;
        $fields = [
            $from => ForeignKey::createObject(
                [
                    'to' => $modelName,
                    'relatedName' => sprintf('%s+', $className),
                    'dbConstraint' => $field->relation->dbConstraint,
                    'onDelete' => Delete::CASCADE,
                ]
            ),
            $to => ForeignKey::createObject(
                [
                    'to' => $toNamespacedModelName,
                    'relatedName' => sprintf('%s+', $className),
                    'dbConstraint' => $field->relation->dbConstraint,
                    'onDelete' => Delete::CASCADE,
                ]
            ),
        ];

        /* @var $intermediaryObj Model */
        $intermediaryClass = FormatFileContent::createObject();

        $intermediaryClass->addItem(
            sprintf(
                'class %1$s extends \%2$s{',
                $className,
                Model::class
            )
        );
        $intermediaryClass->addItem('public function fields(){');
        $intermediaryClass->addItem('}');
        $intermediaryClass->addItem('public function getMetaSettings(){');
        $intermediaryClass->addItem('return [');
        $intermediaryClass->addItem(
            sprintf(
                "'dbTable' => '%s',",
                $field->getM2MDbTable($model->getMeta())
            )
        );
        $intermediaryClass->addItem(
            sprintf(
                "'verboseName' => '%s',",
                sprintf('%s-%s relationship', $from, $to)
            )
        );
        $intermediaryClass->addItem(
            sprintf(
                "'uniqueTogether' => ['%s','%s'],",
                $from,
                $to
            )
        );
        $intermediaryClass->addItem("'autoCreated' => true");
        $intermediaryClass->addItem('];');
        $intermediaryClass->addItem('}');
        $intermediaryClass->addItem('}');

        if (!class_exists($className, false)):
            eval($intermediaryClass->toString());
        endif;

        /** @var $obj Model */
        $obj = new $className();

        $obj->setupClassInfo(
            $fields,
            [
                'meta' => ['appName' => $model->getMeta()->getAppName()],
            ]
        );

        return $obj;
    }

    /**
     * provides the m2m table name for this relation.
     *
     * @param Meta $meta
     *
     * @return string
     *
     * @since  1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    private function getM2MDbTable($meta)
    {
        if (null !== $this->relation->through):
            return $this->relation->through->getMeta()->getDbTable();
        elseif ($this->dbTable):
            return $this->dbTable;
        else:
            // oracle allows identifier of 30 chars max
            return StringHelper::truncate(
                sprintf('%s_%s', $meta->getDbTable(), $this->getName()),
                30
            );
        endif;
    }

    public function checks()
    {
        $checks = parent::checks();
        $checks = array_merge($checks, $this->checkIgnoredKwargOptions());

        return $checks;
    }

    private function checkIgnoredKwargOptions()
    {
        $warnings = [];
        if ($this->hasNullKwarg):
            $warnings = [
                CheckWarning::createObject(
                    [
                        'message' => sprintf('null has no effect on ManyToManyField.'),
                        'hint' => null,
                        'context' => $this,
                        'id' => 'fields.W340',
                    ]
                ),
            ];
        endif;

        return $warnings;
    }

    /***
     * Gets the m2m relationship field on the through model.
     * @param Model $model
     * @param       $attr
     * @return mixed
     * @author: Eddilbert Macharia (http://eddmash.com)<edd.cowan@gmail.com>
     */
    public function getM2MAttr(ForeignObjectRel $relation, $attr)
    {
        $cache_attr = sprintf('_m2m_%s_cache', $attr);
        if ($this->hasProperty($cache_attr)) :
            return $this->{$cache_attr};
        endif;

        $linkName = null;
        if ($this->relation->through_fields) :
            $linkName = $this->relation->through_fields[0];
        endif;

        /** @var $field RelatedField */
        foreach ($this->relation->through->getMeta()->getFields() as $field) :
            if ($field->isRelation &&
                $field->relation->toModel->getMeta()->getNSModelName() == $relation->getFromModel()
                                                                                   ->getMeta()
                                                                                   ->getNSModelName() &&
                (is_null($linkName) || $linkName == $field->getName())
            ) :

                $this->{$cache_attr} = ('name' == $attr) ? call_user_func([$field, 'getName']) : $field->{$attr};

                return $this->{$cache_attr};
            endif;
        endforeach;
    }

    /***
     * Gets the m2m relationship field on the through model.
     * @param Model $model
     * @param       $attr
     * @return mixed
     * @author: Eddilbert Macharia (http://eddmash.com)<edd.cowan@gmail.com>
     */
    public function getM2MReverseAttr(ForeignObjectRel $relation, $attr)
    {
        $cache_attr = sprintf('_m2m_reverse_%s_cache', $attr);
        if ($this->hasProperty($cache_attr)) :
            return $this->{$cache_attr};
        endif;

        $linkName = null;
        if ($this->relation->through_fields) :
            $linkName = $this->relation->through_fields[1];
        endif;

        /** @var $field RelatedField */
        foreach ($this->relation->through->getMeta()->getFields() as $field) :
            if ($field->isRelation &&
                $field->relation->toModel->getMeta()->getNSModelName() == $relation->toModel->getMeta()
                                                                                            ->getNSModelName() &&
                (is_null($linkName) || $linkName == $field->getName())
            ) :
                $this->{$cache_attr} = ('name' == $attr) ? call_user_func([$field, 'getName']) : $field->{$attr};

                return $this->{$cache_attr};
            endif;
        endforeach;
    }

    /**
     * Get path from this field to the related model.
     *
     * @return array
     * @author: Eddilbert Macharia (http://eddmash.com)<edd.cowan@gmail.com>
     */
    private function pathInfo($direct = false)
    {
        $paths = [];
        $model = $this->relation->through;

        /* @var $field RelatedField */
        /* @var $reverseField RelatedField */

        $m2mField = call_user_func($this->m2mField);
        $m2mReverseField = call_user_func($this->m2mReverseField);

        $field = $model->getMeta()->getField($m2mField);
        $reverseField = $model->getMeta()->getField($m2mReverseField);

        if ($direct):
            $paths = array_merge($paths, $field->getReversePathInfo());
            $paths = array_merge($paths, $reverseField->getPathInfo());
        else:
            $paths = array_merge($paths, $reverseField->getReversePathInfo());
            $paths = array_merge($paths, $field->getPathInfo());
        endif;

        return $paths;
    }

    public function getPathInfo()
    {
        return $this->pathInfo(true);
    }

    public function getReversePathInfo()
    {
        return $this->pathInfo(false);
    }

    /**
     * {@inheritdoc}
     */
    public function formField($kwargs = [])
    {
        $kwargs['fieldClass'] = ArrayHelper::getValue(
            $kwargs,
            'fieldClass',
            ModelMultipleChoiceField::class
        );
        if (!ArrayHelper::hasKey($kwargs, 'queryset')) :
            $model = $this->relation->getToModel();
            $kwargs['queryset'] = $model::objects();
        endif;

        return parent::formField($kwargs);
    }

    /**
     * {@inheritdoc}
     */
    public function valueFromObject($obj)
    {
        if (!$obj->pk):
            return [];
        endif;

        return $obj->{$this->getName()}->all(); // TODO: Change the autogenerated stub
    }

    /**
     * {@inheritdoc}
     */
    public function saveFromForm(Model $model, $value)
    {
        $model->{$this->getName()}->set($value);
    }
}
