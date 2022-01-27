<?php

namespace CodingSocks\Reloquent\Schema;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Builder as BaseBuilder;
use Illuminate\Support\Str;

class Builder extends BaseBuilder
{

    /**
     * The schema grammar instance.
     *
     * @var \CodingSocks\Reloquent\Schema\Grammar
     */
    protected $grammar;

    /**
     * Create a new database Schema manager.
     *
     * @param  \Illuminate\Database\Connection  $connection
     * @return void
     */
    public function __construct(Connection $connection)
    {
        parent::__construct($connection);

        $this->blueprintResolver(function ($table, $callback, $prefix) {
            return new Blueprint($table, $callback, $prefix);
        });
    }

    /**
     * Create a new table on the schema.
     *
     * @param  string  $table
     * @param  \Closure  $callback
     * @return void
     */
    public function create($table, Closure $callback)
    {
        $this->build(tap($this->createBlueprint($table), function ($blueprint) use ($table, $callback) {
            /** @var \CodingSocks\Reloquent\Schema\Blueprint $blueprint */
            $blueprint->create();

            $callback($blueprint);
        }));
    }

    /**
     * Determine if the given index exists.
     *
     * @param $name
     * @return bool
     */
    public function hasIndex($name)
    {
        return ! is_null($this->info($name));
    }

    public function info($name)
    {
        $cmd = $this->grammar->compileInfo($name);

        try {
            $result = $this->connection->execute($cmd);
        } catch (\Exception $e) {
            $result = $e->getMessage();
        }
        return is_string($result) ? null : $result;
    }

    /**
     * Determine if the given table exists.
     *
     * @param  string  $table
     * @return bool
     */
    public function hasTable($table)
    {
        return $this->hasIndex($table);
    }

    /**
     * Get the column listing for a given table.
     *
     * @param  string  $table
     * @return array
     */
    public function getColumnListing($table)
    {
        $table = $this->connection->getTablePrefix().$table;

        $results = $this->connection->select(
            $this->grammar->compileColumnListing(), [$this->connection->getDatabaseName(), $table]
        );

        return $this->connection->getPostProcessor()->processColumnListing($results);
    }

    /**
     * Drop a table from the schema.
     *
     * @param  string  $table
     * @param  boolean  $dropData
     * @return void
     */
    public function drop($table, $dropData = false)
    {
        $this->build(tap($this->createBlueprint($table), function ($blueprint) use ($dropData) {
            $blueprint->drop($dropData);
        }));
    }

    /**
     * Drop a table from the schema if it exists.
     *
     * @param  string  $table
     * @param  boolean  $dropData
     * @return void
     */
    public function dropIfExists($table, $dropData = false)
    {
        try {
            $this->drop($table, $dropData);
        } catch (\Exception $e) {
            if (Str::startsWith($e->getMessage(), 'Unknown Index name')) {
                // Swallow exceptions
                return;
            }
            throw $e;
        }
    }

    /**
     * Drop all tables from the database.
     *
     * @return void
     */
    public function dropAllTables()
    {
        $tables = [];

        foreach ($this->getAllTables() as $row) {
            $row = (array) $row;

            $tables[] = reset($row);
        }

        if (empty($tables)) {
            return;
        }

        $this->connection->statement(
            $this->grammar->compileDropAllTables($tables)
        );
    }

    /**
     * Drop all views from the database.
     *
     * @return void
     */
    public function dropAllViews()
    {
        $views = [];

        foreach ($this->getAllViews() as $row) {
            $row = (array) $row;

            $views[] = reset($row);
        }

        if (empty($views)) {
            return;
        }

        $this->connection->statement(
            $this->grammar->compileDropAllViews($views)
        );
    }

    /**
     * Get all of the table names for the database.
     *
     * @return array
     */
    public function getAllTables()
    {
        return $this->connection->select(
            $this->grammar->compileGetAllTables()
        );
    }

    /**
     * Get all of the view names for the database.
     *
     * @return array
     */
    public function getAllViews()
    {
        return $this->connection->select(
            $this->grammar->compileGetAllViews()
        );
    }
}
