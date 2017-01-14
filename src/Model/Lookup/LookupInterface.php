<?php

/*
* This file is part of the powerorm package.
*
* (c) Eddilbert Macharia <edd.cowan@gmail.com>
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

/*
* This file is part of the ci306 package.
*
* (c) Eddilbert Macharia <edd.cowan@gmail.com>
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Eddmash\PowerOrm\Model\Lookup;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Class Filter.
 *
 * @since 1.1.0
 *
 * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
 */
interface LookupInterface
{
    public static function createObject($rhs, $lhs);

    public function processLHS(Connection $connection, QueryBuilder $queryBuilder);

    public function processRHS(Connection $connection, QueryBuilder $queryBuilder);

    public function getLookupOperation($rhs);

    public function asSql(Connection $connection, QueryBuilder $queryBuilder);
}