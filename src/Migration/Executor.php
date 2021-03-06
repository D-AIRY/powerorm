<?php

/*
* This file is part of the powerorm package.
*
* (c) Eddilbert Macharia <edd.cowan@gmail.com>
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Eddmash\PowerOrm\Migration;

use Eddmash\PowerOrm\BaseObject;
use Eddmash\PowerOrm\Console\Console;
use Eddmash\PowerOrm\Db\ConnectionInterface;
use Eddmash\PowerOrm\Migration\State\ProjectState;

/**
 * End-to-end migration execution - loads migrations, and runs them up or down to a specified set of targets.
 *
 * @since  1.0.1
 *
 * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
 */
class Executor extends BaseObject
{
    /**
     * @var Loader
     */
    public $loader;

    /**
     * @var ConnectionInterface
     */
    private $connection;

    /**
     * @var Recorder
     */
    private $recorder;

    /**
     * Executor constructor.
     *
     * @param ConnectionInterface $connection
     */
    public function __construct(ConnectionInterface $connection)
    {
        $this->connection = $connection;
        $this->loader = Loader::createObject($this->connection);
        $this->recorder = Recorder::createObject($this->connection);
    }

    /**
     * @param ConnectionInterface $connection
     *
     * @return static
     *
     * @since  1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public static function createObject($connection)
    {
        return new static($connection);
    }

    /**
     * Given a set of targets, returns a list of (Migration instance, backwards?).
     *
     * @param      $targets
     * @param bool $cleanStart
     *
     * @return array
     *
     * @since  1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     *
     * @throws \Eddmash\PowerOrm\Exception\NodeNotFoundError
     */
    public function getMigrationPlan($targets, $cleanStart = false)
    {
        $plan = [];

        if ($cleanStart):
            $applied = [];
        else:
            $applied = $this->loader->appliedMigrations;
        endif;

        /** @var $target ["appName"=> "migrationName"] */
        foreach ($targets as $appName => $target) :
            // if target is 'zero' unmigrate all
            if ('zero' == $target):

                $rootNodes = $this->loader->graph->getRootNodes();
                foreach ($rootNodes as $rootAppName => $rootNode) :
                    $descs = $this->loader->graph
                        ->getDecedentsTree($rootAppName, $rootNode);

                    foreach ($descs as $migrationName => $migrApp) :

                        if (!empty($applied[$migrApp][$migrationName])):
                            $plan[$migrApp][$migrationName] = [
                                'migration' => $this->loader->graph
                                    ->getMigration($migrApp, $migrationName),
                                'unapply' => true,
                            ];
                            unset($applied[$migrApp][$migrationName]);
                        endif;

                    endforeach;
                endforeach;
            elseif (!empty($applied[$appName][$target])):
                // if its applied then we need to unapply it.

                /** @var $childNode Node */
                $children = $this->loader->graph
                    ->getNodeFamilyTree($appName, $target)->children;

                foreach ($children as $childNode) :

                    $descedants = $this->loader->graph->getDecedentsTree(
                        $childNode->appName,
                        $childNode->name
                    );

                    foreach ($descedants as $migrationName => $descAppName) :

                        if (!empty($applied[$descAppName][$migrationName])):
                            $plan[$descAppName][$migrationName] = [
                                'migration' => $this->loader->graph
                                    ->getMigration($descAppName, $migrationName),
                                'unapply' => true,
                            ];
                            unset($applied[$descAppName][$migrationName]);
                        endif;
                    endforeach;

                endforeach;
            else:
                $ancestries = $this->loader->graph
                    ->getAncestryTree($appName, $target);

                // if not applied and its not target is not zero, then apply it.
                foreach ($ancestries as $migrationName => $migrationApp) :
                    if (empty($applied[$migrationApp][$migrationName])):
                        $plan[$migrationApp][$migrationName] = [
                            'migration' => $this->loader->graph
                                ->getMigration($migrationApp, $migrationName),
                            'unapply' => false,
                        ];
                        $applied[$migrationApp][] = $migrationName;
                    endif;
                endforeach;
            endif;
        endforeach;

        return $plan;
    }

    /**
     * Migrates the database up to the given targets.
     *
     * @param $targets
     * @param $plan
     * @param $fake
     *
     * @throws \Eddmash\PowerOrm\Exception\NotImplemented
     * @throws \Exception
     *
     * @since  1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function migrate($targets, $plan, $fake)
    {
        if (empty($plan)):
            $plan = $this->getMigrationPlan($targets);
        endif;

        $migrationsToRun = $this->getMigrationsFromPlan($plan);

        $targets = [];
        $leaves = $this->loader->graph->getLeafNodes();
        foreach ($leaves as $app => $appLeaves) :
            $targets[$app] = $appLeaves[0];
        endforeach;

        // the full plan that would be executed if we to run on a new database
        $fullPlan = $this->getMigrationsFromPlan(
            $this->getMigrationPlan($targets, true)
        );

        // Holds all states right before a migration is applied
        // if the migration is being run.
        /** @var $states ProjectState[][] */
        $states = [];
        $state = ProjectState::createObject();

        //Phase 1 -- create all project states before a migration is (un)applied
        /* @var $migration Migration */
        foreach ($fullPlan as $appName => $appMigrations) :
            foreach ($appMigrations as $migName => $migration) :
                // we use the migration to mutate state
                // after we mutate we remove the migration from the
                // $migrationsToRun list.
                // so if we get to a point where we don't have any more
                // $migrationsToRun break
                // this is to avoid any further mutations by other migrations
                // not in the list.
                if (empty($migrationsToRun)):
                    break;
                endif;

                $run = !empty($migrationsToRun[$appName][$migName]);
                if ($run):
                    $states[$appName][$migName] = $state->deepClone();
                    unset($migrationsToRun[$appName][$migName]);
                endif;

                // $run will be false if the migration is not in the
                // $migrationsToRun list
                // so there is no need to preserve state else if its in the list
                // we will get a new state object
                // that has been altered by the migration.
                // we do this because we need the object stored in the states
                // array in the condition it was right before
                // the migration was applied.
                // remember in PHP objects are passed by reference.
                $state = $migration->updateState($state, $run);
            endforeach;
        endforeach;

        // Phase 2 -- Run the migrations
        foreach ($plan as $appName => $actionPlan) :
            foreach ($actionPlan as $mName => $migrationMeta) :
                if ($migrationMeta['unapply']):
                    $this->unApplyMigration(
                        $states[$appName][$mName],
                        $migrationMeta['migration'],
                        $fake
                    );
                elseif (false === $migrationMeta['unapply']):

                    $this->applyMigration(
                        $states[$appName][$mName],
                        $migrationMeta['migration'],
                        $fake
                    );
                endif;
            endforeach;
        endforeach;
    }

    /**
     * Rolls back the migrations on the database.
     *
     * @param ProjectState $state     this is the state before the migration is applied
     * @param Migration    $migration the migration to apply
     * @param bool         $fake
     *
     * @return mixed
     *
     * @throws \Eddmash\PowerOrm\Exception\NotImplemented
     * @throws \Exception
     *
     * @since  1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function unApplyMigration($state, $migration, $fake = false)
    {
        Console::stdout(sprintf(' UnApplying %s...', $migration->getName()));
        if (!$fake):
            $state = $migration->unApply(
                $state,
                $this->connection->getSchemaEditor()
            );
        endif;

        $this->recorder->recordUnApplied(
            [
                'name' => $migration->getName(),
                'app' => $migration->getAppLabel(),
            ]
        );

        if ($fake):
            $end = Console::ansiFormat('FAKED', [Console::FG_GREEN]);
        else:
            $end = Console::ansiFormat('OK', [Console::FG_GREEN]);
        endif;

        Console::stdout($end.PHP_EOL);

        return $state;
    }

    /**
     * Applies the migration to the database.
     *
     * @param ProjectState $state     this is the state before the migration is applied
     * @param Migration    $migration the migration to apply
     * @param bool         $fake
     *
     * @return mixed
     *
     * @throws \Eddmash\PowerOrm\Exception\NotImplemented
     * @throws \Exception
     *
     * @since  1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function applyMigration($state, $migration, $fake = false)
    {
        Console::stdout(sprintf(' Applying %s...', $migration->getName()));
        if (!$fake):
            $state = $migration->apply(
                $state,
                $this->connection->getSchemaEditor()
            );
        endif;

        $this->recorder->recordApplied(
            [
                'name' => $migration->getName(),
                'app' => $migration->getAppLabel(),
            ]
        );

        if ($fake):
            $end = Console::ansiFormat('FAKED', [Console::FG_GREEN]);
        else:
            $end = Console::ansiFormat('OK', [Console::FG_GREEN]);
        endif;

        Console::stdout($end.PHP_EOL);

        return $state;
    }

    /**
     * @param $plan
     *
     * @return Migration[]
     */
    private function getMigrationsFromPlan($plan)
    {
        /** @var $migration Migration */
        $migrations = [];
        foreach ($plan as $appName => $actionPlan) :
            foreach ($actionPlan as $name => $migrationArr) :
                $migration = $migrationArr['migration'];
                $migrations[$migration->getAppLabel()][$name] = $migration;
            endforeach;
        endforeach;

        return $migrations;
    }

    public function getSql($plan)
    {
        /** @var $migration Migration */
        $state = null;
        $statements = [];
        foreach ($plan as $backward => $migration) :
            if (is_null($state)):
                $state = $this->loader->getProjectState(
                    [$migration->getAppLabel() => [$migration->getName()]],
                    false
                );
            endif;

            if ($backward):
                $editor = $this->connection->getSchemaEditor(true);
                $migration->unApply(
                    $state,
                    $editor
                );
                $statements = $editor->getSqls();
            else:
                $editor = $this->connection->getSchemaEditor(true);
                $migration->apply(
                    $state,
                    $editor
                );
                $statements = $editor->getSqls();
            endif;
        endforeach;

        return $statements;
    }
}
