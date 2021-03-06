<?php
/**
 * Created by PhpStorm.
 * User: edd
 * Date: 1/29/18
 * Time: 11:51 PM.
 */

namespace Eddmash\PowerOrm\Tests;

use Eddmash\PowerOrm\App\Settings;
use Eddmash\PowerOrm\BaseOrm;
use Eddmash\PowerOrm\Tests\TestApp\Test;

define(BASEPATH, dirname(dirname(__FILE__)));

abstract class PowerormTest extends \PHPUnit_Framework_TestCase
{
    protected function setUp()
    {
        BaseOrm::setup(
            new Settings(
                [
                    'components' => [
                        Test::class,
                    ],
                ]
            )
        );
    }
}
