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

use Eddmash\PowerOrm\Exception\KeyError;
use Eddmash\PowerOrm\Exception\ValueError;
use Eddmash\PowerOrm\Helpers\ArrayHelper;
use Eddmash\PowerOrm\Model\Delete;
use Eddmash\PowerOrm\Model\Field\RelatedObjects\ForeignObjectRel;
use Eddmash\PowerOrm\Model\Field\RelatedObjects\ManyToOneRel;
use Eddmash\PowerOrm\Model\Model;

class ForeignKey extends RelatedField
{
    public $manyToOne = true;
    public $dbConstraint = true;
    public $dbIndex = true;

    /**
     * {@inheritdoc}
     *
     * @var ManyToOneRel
     */
    public $relation;

    public function __construct($kwargs)
    {
        if (!isset($kwargs['rel']) || (isset($kwargs['rel']) && $kwargs['rel'] == null)):
            $kwargs['rel'] = ManyToOneRel::createObject(
                [
                    'fromField' => $this,
                    'to' => ArrayHelper::getValue($kwargs, 'to'),
                    'relatedName' => ArrayHelper::getValue($kwargs, 'relatedName'),
                    'relatedQueryName' => ArrayHelper::getValue($kwargs, 'relatedQueryName'),
                    'toField' => ArrayHelper::getValue($kwargs, 'toField'),
                    'parentLink' => ArrayHelper::getValue($kwargs, 'parentLink'),
                    'onDelete' => ArrayHelper::getValue($kwargs, 'onDelete', Delete::CASCADE),
                ]
            );
        endif;

        $this->toField = ArrayHelper::getValue($kwargs, 'toField');
        $this->fromField = 'this';

        parent::__construct($kwargs);
    }

    /**
     * Gets the field on the related model that is related to this one.
     *
     * @since 1.1.0
     *
     * @return Field
     *
     * @throws ValueError
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function getRelatedField()
    {
        $fields = $this->getRelatedFields();

        return $fields[1];
    }

    /**
     * {@inheritdoc}
     */
    public function contributeToInverseClass(Model $relatedModel, ForeignObjectRel $relation)
    {
        parent::contributeToInverseClass($relatedModel, $relation);
        if ($this->relation->fieldName == null):
            $this->relation->fieldName = $relatedModel->meta->primaryKey->getName();
        endif;
    }

    /**
     * {@inheritdoc}
     */
    public function dbType($connection)
    {

        // The database column type of a ForeignKey is the column type
        // of the field to which it points.
        return $this->getRelatedField()->dbType($connection);
    }

    public function getAttrName()
    {
        return sprintf('%s_id', $this->getName());
    }

    /**
     * {@inheritdoc}
     */
    public function getConstructorArgs()
    {
        $kwargs = parent::getConstructorArgs();

        if ($this->dbIndex) :
            unset($kwargs['dbIndex']);
        else:
            $kwargs['dbIndex'] = false;
        endif;
        if ($this->dbConstraint === false) :
            $kwargs['dbConstraint'] = $this->dbConstraint;
        endif;

        return $kwargs;
    }

    public function getReverseRelatedFields()
    {
        list($fromField, $toField) = $this->getRelatedFields();

        return [$toField, $fromField];
    }

    public function getJoinColumns($reverse = false)
    {
        if ($reverse):
            return $this->getReverseRelatedFields();
        endif;

        return $this->getRelatedFields();
    }

    public function getReverseJoinColumns()
    {
        return $this->getJoinColumns(true);
    }

    /**
     * {@inheritdoc}
     */
    public function getColExpression($alias, $outputField = null)
    {
        if (is_null($outputField)):
            $outputField = $this->getRelatedField();
        endif;

        return parent::getColExpression($alias, $outputField);
    }

    /**
     * @param Model $modelInstance
     * @author: Eddilbert Macharia (http://eddmash.com)<edd.cowan@gmail.com>
     *
     * @return mixed
     */
    public function getValue(Model $modelInstance)
    {
        $result = null;

        try {
            // incase the value has been set
            $result = ArrayHelper::getValue($modelInstance->_fieldCache, $this->getName(), ArrayHelper::STRICT);
        } catch (KeyError $e) {
            $result = $this->queryset(null, $modelInstance);

            /* @var $fromField RelatedField */
            $fromField = $this->getRelatedFields()[0];
            // cache the value of the model
            $modelInstance->_fieldCache[$fromField->getName()] = $result;
        }

        return $result;
    }

    public function setValue(Model $modelInstance, $value)
    {
        if (!$value instanceof $this->relation->toModel):
            throw new ValueError(
                sprintf(
                    'Cannot assign "%s": "%s.%s" must be a "%s" instance.',
                    $value,
                    $this->scopeModel->meta->modelName,
                    $this->getName(),
                    $this->relation->toModel->meta->modelName
                )
            );
        endif;
        /** @var $fromField RelatedField */

        /** @var $toField RelatedField */

        /* @var $field RelatedField */
        list($fromField, $toField) = $this->getRelatedFields();

        // cache the value of the model
        $modelInstance->_fieldCache[$fromField->getName()] = $value;

        // set the attrib value
        $modelInstance->{$fromField->getAttrName()} = $value->{$toField->getAttrName()};
    }

    /**
     * {@inheritdoc}
     */
    public function queryset($modelName, $modelInstance)
    {
        if (is_null($modelName)) :
            $modelName = $this->getRelatedModel()->meta->modelName;
        endif;

        /* @var $modelName Model */
        $qs = $modelName::objects()->all();

        return $qs->filter($this->getRelatedFilter($modelInstance))->get();
    }

}
