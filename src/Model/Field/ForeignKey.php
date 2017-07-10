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

use Doctrine\DBAL\Connection;
use Eddmash\PowerOrm\Exception\ValueError;
use Eddmash\PowerOrm\Form\Fields\ModelChoiceField;
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

    /**{inheritdoc}*/
    protected $descriptor = '\Eddmash\PowerOrm\Model\Field\Descriptors\ForwardManyToOneDescriptor';
    public $inverseField = '\Eddmash\PowerOrm\Model\Field\Inverse\HasManyField';

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
    public function dbType(Connection $connection)
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
     * {@inheritdoc}
     */
    public function formField($kwargs = [])
    {
        $kwargs['fieldClass'] = ArrayHelper::getValue(
            $kwargs,
            'fieldClass',
            ModelChoiceField::class
        );
        if (!ArrayHelper::hasKey($kwargs, 'queryset')) :
            $model = $this->relation->getToModel();
            $kwargs['queryset'] = $model::objects();
        endif;

        $kwargs['valueField'] = $this->relation->fieldName;

        return parent::formField($kwargs);
    }

    /**
     * {@inheritdoc}
     */
    public function prepareValueBeforeSave($value, $connection)
    {
        if(is_null($value) || $value === ''):
           return;
        endif;

        return $this->getRelatedField()->prepareValueBeforeSave($value, $connection, false);
    }
}
