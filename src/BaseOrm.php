<?php

/*
* This file is part of the powerorm package.
*
* (c) Eddilbert Macharia <edd.cowan@gmail.com>
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Eddmash\PowerOrm;

use DebugBar\JavascriptRenderer;
use DebugBar\StandardDebugBar;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Eddmash\PowerOrm\App\Registry;
use Eddmash\PowerOrm\Checks\ChecksRegistry;
use Eddmash\PowerOrm\Checks\Tags;
use Eddmash\PowerOrm\Console\Manager;
use Eddmash\PowerOrm\Exception\AppRegistryNotReady;
use Eddmash\PowerOrm\Exception\FileHandlerException;
use Eddmash\PowerOrm\Exception\OrmException;
use Eddmash\PowerOrm\Helpers\ArrayHelper;
use Eddmash\PowerOrm\Helpers\ClassHelper;
use Eddmash\PowerOrm\Model\Model;
use Eddmash\PowerOrm\Model\Query\Q;

define('NOT_PROVIDED', 'POWERORM_NOT_PROVIDED');

/**
 * @since 1.1.0
 *
 * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
 */
class BaseOrm extends BaseObject
{
    const RECURSIVE_RELATIONSHIP_CONSTANT = 'this';

    /**
     * @var JavascriptRenderer
     */
    public static $debugbarRenderer;
    private static $checkRegistry;
    public $modelsNamespace;
    public $migrationNamespace = 'App\Migrations';
    public $dbMappingTypes = [];
    public $dbTypes = [];
    public $dateFormats = [
        'Y-m-d',      // '2006-10-25'
        'm/d/Y',      // '10/25/2006'
        'm/d/y',     // '10/25/06'
    ];
    public $timeFormats = [
        'H:i:s',     // '14:30:59'
        'H:i:s.u',  // '14:30:59.000200'
        'H:i',        // '14:30'
    ];
    private $timezone = '';

    /**
     * Url to use to load css/js/image used by ORM resource e.g. debugtoolbar.
     *
     * @var string
     */
    private $staticBaseUrl = '';
    /**
     * The configurations to use to connect to the database.
     *
     * It should be an array which must contain at least one of the following.
     *
     * Either 'driver' with one of the following values:
     *
     *     pdo_mysql
     *     pdo_sqlite
     *     pdo_pgsql
     *     pdo_oci (unstable)
     *     pdo_sqlsrv
     *     pdo_sqlsrv
     *     mysqli
     *     sqlanywhere
     *     sqlsrv
     *     ibm_db2 (unstable)
     *     drizzle_pdo_mysql
     *
     * OR 'driverClass' that contains the full class name (with namespace) of the
     * driver class to instantiate.
     *
     * Other (optional) parameters:
     *
     * <b>user (string)</b>:
     * The username to use when connecting.
     *
     * <b>password (string)</b>:
     * The password to use when connecting.
     *
     * <b>driverOptions (array)</b>:
     * Any additional driver-specific options for the driver. These are just passed
     * through to the driver.
     *
     * <b>pdo</b>:
     * You can pass an existing PDO instance through this parameter. The PDO
     * instance will be wrapped in a Doctrine\DBAL\Connection.
     *
     * <b>wrapperClass</b>:
     * You may specify a custom wrapper class through the 'wrapperClass'
     * parameter but this class MUST inherit from Doctrine\DBAL\Connection.
     *
     * <b>driverClass</b>:
     * The driver class to use.
     *
     * <strong>USAGE:</strong>
     *
     * [
     *       'dbname' => 'tester',
     *       'user' => 'root',
     *       'password' => 'root1.',
     *       'host' => 'localhost',
     *       'driver' => 'pdo_mysql',
     * ]
     *
     * @var array
     */
    private $database;

    /**
     * @var
     */
    public $charset = 'utf-8';

    /**
     * path from where to get and put migration files.
     *
     * @var string
     */
    public $migrationPath;

    /**
     * Path from where to get the models.
     *
     * @var string
     */
    public $modelsPath;

    /**
     * The value to prefix the database tables with.
     *
     * @var string
     */
    public $dbPrefix;

    /**
     * A list of identifiers of messages generated by the system check (e.g. ["models.W001"]) that you wish to
     * permanently acknowledge and ignore.
     *
     * Silenced warnings will no longer be output to the console;
     * silenced errors will still be printed, but will not prevent management commands from running.
     *
     * @var array
     */
    public $silencedChecks = [];

    /**
     * The namespace to check for the application models and migrations.
     *
     * @var string
     */
    public $appNamespace = 'App\\';

    public $components;

    public static $instance;
    public static $SET_NULL = 'set_null';
    public static $CASCADE = 'cascade';
    public static $PROTECT = 'protect';
    public static $SET_DEFAULT = 'set_default';

    /**
     * @var Registry
     */
    public $registryCache;

    /**
     * Namespace used in migration.
     *
     * @internal
     *
     * @var string
     */
    public static $fakeNamespace = 'Eddmash\PowerOrm\__Fake';

    /**
     * @var Connection
     */
    public static $connection;

    /**
     * @param array $config
     * @ignore
     */
    public function __construct($config = [])
    {
        $models = ArrayHelper::pop($config, 'models', null);
        $migrations = ArrayHelper::pop($config, 'migrations', null);

        $this->modelsPath = ArrayHelper::getValue($models, 'path', null);
        $this->autoloadModels = ArrayHelper::getValue($models, 'autoload', true);
        $this->modelsNamespace = ArrayHelper::getValue($models, 'namespace');
        $this->migrationPath = ArrayHelper::getValue($migrations, 'path');
        self::configure($this, $config);

        // setup the registry
        $this->registryCache = Registry::createObject();
    }

    public static function getModelsPath()
    {
        $path = null;
        if (self::getInstance()->modelsPath):
            $path = realpath(self::getInstance()->modelsPath);
            if (!$path && !file_exists(self::getInstance()->modelsPath)):
                throw new FileHandlerException(
                    sprintf("The path '%s' does not exist please check the path is correct",
                        self::getInstance()->modelsPath));
            endif;
        endif;

        return $path;
    }

    public static function getMigrationsPath()
    {
        $path = null;
        if (self::getInstance()->migrationPath):
            $path = realpath(self::getInstance()->migrationPath);
            if (!$path && !file_exists(self::getInstance()->migrationPath)):
                throw new FileHandlerException(
                    sprintf("The path '%s' does not exist please check the path is correct",
                        self::getInstance()->migrationPath));
            endif;
        endif;

        return $path;
    }

    public static function getCharset()
    {
        return self::getInstance()->charset;
    }

    //********************************** ORM Registry*********************************

    /**
     * @param array $configs
     *
     * @return BaseOrm
     * @author: Eddilbert Macharia (http://eddmash.com)<edd.cowan@gmail.com>
     */
    public static function setup($configs = [])
    {

        static::getInstance($configs);

        static::loadRegistry();

        return static::$instance;
    }

    public static function showDebugToolBar()
    {
        static::$debugbarRenderer = self::getInstance()->loadToolbar();
    }

    /**
     * @return \DebugBar\JavascriptRenderer
     *
     * @since 1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function loadToolbar()
    {
        $debugbar = new StandardDebugBar();
        $debugbarRenderer = $debugbar->getJavascriptRenderer($this->staticBaseUrl);

        $debugbar['messages']->addMessage('hello world!');
        $debugStack = new \Doctrine\DBAL\Logging\DebugStack();
        $connection = \Eddmash\PowerOrm\BaseOrm::getDbConnection();

        $connection->getConfiguration()->setSQLLogger($debugStack);

        $debugbar->addCollector(new \DebugBar\Bridge\DoctrineCollector($debugStack));

        return $debugbarRenderer;
    }

    /**
     * @return JavascriptRenderer
     */
    public static function getDebugbarRenderer()
    {
        return self::$debugbarRenderer;
    }

    /**
     * Returns the numeric version of the orm.
     *
     * @return string
     */
    public function getVersion()
    {
        if (defined('POWERORM_VERSION')):
            return POWERORM_VERSION;
        endif;
    }

    /**
     * @deprecated
     *
     * @return string
     *
     * @since 1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function version()
    {
        return $this->getVersion();
    }

    /**
     * @return Connection
     *
     * @throws OrmException
     * @throws \Doctrine\DBAL\DBALException
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function getDatabaseConnection()
    {
        if (empty($this->database)):

            $message = 'The database configuration have no been provided, consult documentation for options';
            throw new OrmException($message);
        endif;
        if (static::$connection == null):
            $config = new Configuration();

            static::$connection = DriverManager::getConnection($this->database, $config);
        endif;

        return static::$connection;
    }

    /**
     * Returns the application registry. This method populates the registry the first time its invoked and caches
     * it since
     * its a very expensive method. subsequent calls get the cached registry.
     *
     * @return Registry
     *
     * @since 1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public static function &getRegistry($load = false)
    {
        $orm = static::getInstance();

        if ($load):
            self::loadRegistry();
        endif;

        return $orm->registryCache;
    }

    public static function instantiate($config = [])
    {
        $orm = new static($config);
        self::loadRegistry($orm);
    }

    public static function loadRegistry(&$ormInstance = null)
    {
        if (is_null($ormInstance)) :
            $ormInstance = self::getInstance([]);
        endif;

        try {
            $ormInstance->registryCache->isAppReady();
        } catch (AppRegistryNotReady $e) {
            $ormInstance->registryCache->populate();
        }
    }

    /**
     * This is just a shortcut method. get the current instance of the orm.
     *
     * @return BaseOrm
     */
    public static function &getInstance($config = null)
    {
        $instance = self::createObject($config);

        return $instance;
    }

    /**
     * @return Connection
     *
     * @since 1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public static function getDbConnection()
    {
        return self::getInstance()->getDatabaseConnection();
    }

    /**
     * Returns the prefix to use on database tables.
     *
     * @return string
     *
     * @since 1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public static function getDbPrefix()
    {
        return self::getInstance()->dbPrefix;
    }

    /**
     * Configures an object with the initial property values.
     *
     * @param object $object     the object to be configured
     * @param array  $properties the property initial values given in terms of name-value pairs
     * @param array  $map        if set the the key should be a key on the $properties and the value should a a property on
     *                           the $object to which the the values of $properties will be assigned to
     *
     * @return object the object itself
     */
    public static function configure($object, $properties, $map = [])
    {
        if (empty($properties)):
            return $object;
        endif;

        foreach ($properties as $name => $value) :

            if (ArrayHelper::hasKey($map, $name)):

                $name = $map[$name];
            endif;

            $setterMethod = sprintf('set%s', ucfirst($name));
            if (method_exists($object, $setterMethod)):
                call_user_func([$object, $setterMethod], $value);
            elseif (property_exists($object, $name)):

                $object->{$name} = $value;
            endif;

        endforeach;

        return $object;
    }

    /**
     * @param array $config
     *
     * @return static
     * @author: Eddilbert Macharia (http://eddmash.com)<edd.cowan@gmail.com>
     */
    public static function createObject($config = [])
    {
        if (static::$instance == null):

            static::$instance = new static($config);
        endif;

        return static::$instance;
    }

    public static function consoleRunner($config = [])
    {
        // register model checks
        self::loadRegistry();
        self::registerModelChecks();
        Manager::run();
    }

    /**
     * Runs checks on the application models.
     *
     * @internal
     *
     * @since 1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function registerModelChecks()
    {
        $models = self::getRegistry()->getModels();

        /** @var $modelObj Model */
        foreach ($models as $name => $modelObj) :

            if (!$modelObj->hasMethod('checks')):
                continue;
            endif;

            self::getCheckRegistry()->register([$modelObj, 'checks'], [Tags::Model]);

        endforeach;
    }

    /**
     * @param bool|false $recreate
     *
     * @return ChecksRegistry
     *
     * @since 1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public static function getCheckRegistry($recreate = false)
    {
        if (self::$checkRegistry === null || ($recreate && self::$checkRegistry !== null)):
            self::$checkRegistry = ChecksRegistry::createObject();
        endif;

        return self::$checkRegistry;
    }

    /**
     * The fake namespace to use in migration.
     *
     * @return string
     *
     * @since 1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public static function getFakeNamespace()
    {
        return self::$fakeNamespace;
    }

    public static function getModelsNamespace()
    {
        return ClassHelper::getFormatNamespace(self::getInstance()->modelsNamespace, true, false);
    }

    public static function getMigrationsNamespace()
    {
        return ClassHelper::getFormatNamespace(self::getInstance()->migrationNamespace, true, false);
    }

    public static function getQueryBuilder()
    {
        return self::getDbConnection()->createQueryBuilder();
    }

    public static function signalDispatch($signal, $object)
    {
        self::getInstance()->dispatchSignal($signal, $object);
    }

    /**
     * Register custom Doctrine dbal types.
     *
     * @since 1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public static function presetup()
    {

        $orm = self::getInstance();
        static::showDebugToolBar();

        foreach ($orm->dbTypes as $name => $type) {
            Type::addType($name, $type);
        }

        foreach ($orm->dbMappingTypes as $name => $mappingType) {
            static::getDbConnection()->getDatabasePlatform()->registerDoctrineTypeMapping($name, $mappingType);
        }

    }

    /**
     * @return string
     */
    public function getTimezone()
    {
        return ($this->timezone) ? $this->timezone : date_default_timezone_get();
    }
}
