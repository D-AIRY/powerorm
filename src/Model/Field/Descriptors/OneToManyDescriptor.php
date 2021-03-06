<?php

/**
 * This file is part of the powercomponents package.
 *
 * (c) Eddilbert Macharia (http://eddmash.com)<edd.cowan@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Eddmash\PowerOrm\Model\Field\Descriptors;

use Eddmash\PowerOrm\Model\Manager\O2MManager;
use Eddmash\PowerOrm\Model\Model;

/**
 * {@inheritdoc}
 *
 * Gets related data from the one side of the relationship
 *
 * user has many cars so this will query cars related to a particular user in
 * this the default attribute to be used will be ::
 *
 *  $user->car_set->all()
 *
 * Class ManyToOneDescriptor
 *
 * @author: Eddilbert Macharia (http://eddmash.com)<edd.cowan@gmail.com>
 */
class OneToManyDescriptor extends BaseDescriptor
{
    /**
     * {@inheritdoc}
     */
    public function getValue(Model $modelInstance)
    {
        return $this->queryset($modelInstance);
    }

    /**
     * {@inheritdoc}
     */
    public function setValue(Model $modelInstance, $value)
    {
        return $this->getValue($modelInstance)->set($value);
    }

    /**
     * Creates the queryset to retrieve data for the relationship that relates
     * to this field.
     *
     * @param      $modelInstance
     * @param bool $reverse
     *
     * @internal param $modelName
     *
     * @return O2MManager
     *
     * @author   : Eddilbert Macharia (http://eddmash.com)<edd.cowan@gmail.com>
     */
    public function queryset($modelInstance, $reverse = false)
    {
        if ($reverse) :
            $model = $this->field->getRelatedModel();
        else:
            $model = $this->field->scopeModel;
        endif;

        // define BaseM2MQueryset
        if (!class_exists('\Eddmash\PowerOrm\Model\Manager\BaseM2OManager', false)):
            $baseClass = $model::getManagerClass();
            $class = sprintf('namespace Eddmash\PowerOrm\Model\Manager;class BaseM2OManager extends \%s{}', $baseClass);
            eval($class);
        endif;

        $manager = O2MManager::createObject(
            [
                'model' => $model,
                'rel' => $this->field->relation,
                'instance' => $modelInstance,
                'reverse' => true,
            ]
        );

        return $manager;
    }
}
