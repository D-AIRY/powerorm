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

use Doctrine\DBAL\Types\Type;
use Eddmash\PowerOrm\Db\ConnectionInterface;

/**
 * Class FloatField.
 *
 * @since 1.1.0
 *
 * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
 */
class FloatField extends Field
{
    /**
     * {@inheritdoc}
     */
    public function dbType(ConnectionInterface $connection)
    {
        return Type::FLOAT;
    }
}
