<?php

namespace CodingSocks\Reloquent;

use Closure;
use CodingSocks\Reloquent\Query\Builder as QueryBuilder;
use CodingSocks\Reloquent\Query\Grammars\HashGrammar;
use CodingSocks\Reloquent\Query\Processor;
use Exception;
use Illuminate\Contracts\Redis\Factory;
use CodingSocks\Reloquent\Schema\Grammar as SchemaGrammar;
use CodingSocks\Reloquent\Schema\Builder as SchemaBuilder;
use Illuminate\Database\Connection as BaseConnection;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

class Connection extends BaseConnection
{
    /**
     * The active Redis factory.
     *
     * @var \Illuminate\Contracts\Redis\Factory
     */
    private Factory $factory;

    /**
     * Create a new database connection instance.
     *
     * @param \Illuminate\Contracts\Redis\Factory $factory
     * @param array $config
     */
    public function __construct(Factory $factory, array $config = [])
    {
        parent::__construct(null, '', '', $config);

        $this->factory = $factory;

        $this->useDefaultSchemaGrammar();
    }

    /**
     * Execute a raw command.
     *
     * @param array $cmd
     * @return mixed
     */
    public function execute($cmd)
    {
        return $this->getConnection()->executeRaw($cmd);
    }

    /**
     * Returns the current redis connection.
     *
     * @return \Illuminate\Redis\Connections\Connection
     */
    protected function getConnection()
    {
        return $this->getFactory()->connection($this->getConfig('connection'));
    }

    /**
     * Execute an SQL statement and return the boolean result.
     *
     * @param string $query
     * @param array $bindings
     * @return bool
     */
    public function statement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return true;
            }

            $result = 0;
            if (is_array(reset($query))) {
                foreach ($query as $q) {
                    $result += $this->execute($q);
                }
            } else {
                $result = $this->execute($query);
            }

            $this->recordsHaveBeenModified();

            return $result > 0;
        });
    }

    /**
     * Run an SQL statement and get the number of rows affected.
     *
     * @param  string  $query
     * @param  array  $bindings
     * @return int
     */
    public function affectingStatement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return 0;
            }

            $result = 0;
            if (is_array(reset($query))) {
                foreach ($query as $q) {
                    $result += $this->execute($q);
                }
            } else {
                $result = $this->execute($query);
            }

            $this->recordsHaveBeenModified();

            return $result > 0;
        });
    }

    /**
     * Begin a fluent query against am index.
     *
     * @param string $index
     * @return \CodingSocks\Reloquent\Query\Builder
     */
    public function search($index)
    {
        $query = new QueryBuilder($this, null, $this->getPostProcessor());

        return $query->from($index);
    }

    /**
     * Run a SQL statement.
     *
     * @param array $query
     * @param array $bindings
     * @param \Closure $callback
     * @return mixed
     *
     * @throws \Illuminate\Database\QueryException
     */
    protected function runQueryCallback($query, $bindings, Closure $callback)
    {
        // To execute the statement, we'll simply call the callback, which will actually
        // run the SQL against the PDO connection. Then we can calculate the time it
        // took to execute and log the query SQL, bindings and time in our memory.
        try {
            return $callback($query, $bindings);
        }

            // If an exception occurs when attempting to run a query, we'll format the error
            // message to include the bindings with SQL, which will make this exception a
            // lot more helpful to the developer instead of just the database's errors.
        catch (Exception $e) {
            if (is_array(reset($query))) {
                $query = array_merge(...$query);
            }
            throw new QueryException(
                "['" . implode("', '", $query) . "']", $this->prepareBindings($bindings), $e
            );
        }
    }

    /**
     * Begin a fluent query against a database collection.
     * @param string $table
     * @param string|null $as
     * @return \CodingSocks\Reloquent\Query\Builder
     */
    public function table($table, $as = null)
    {
        return $this->search($table);
    }

    /**
     * Get a new query builder instance.
     *
     * @return \CodingSocks\Reloquent\Query\Builder
     */
    public function query()
    {
        return new QueryBuilder(
            $this, $this->getQueryGrammar(), $this->getPostProcessor()
        );
    }

    /**
     * Get a schema builder instance for the connection.
     *
     * @return \CodingSocks\Reloquent\Schema\Builder
     */
    public function getSchemaBuilder()
    {
        return new SchemaBuilder($this);
    }

    /**
     * Get the default post processor instance.
     *
     * @return \CodingSocks\Reloquent\Query\Processor
     */
    protected function getDefaultPostProcessor()
    {
        return new Processor;
    }

    /**
     * Get the name of the connected database.
     *
     * @return string
     */
    public function getDatabaseName()
    {
        return $this->getFactory()->connection()->getDbNum();
    }

    /**
     * Get the default query grammar instance.
     *
     * @return \CodingSocks\Reloquent\Query\Grammars\HashGrammar
     */
    protected function getDefaultQueryGrammar()
    {
        return new HashGrammar;
    }

    /**
     * Get the default schema grammar instance.
     *
     * @return \CodingSocks\Reloquent\Schema\Grammar
     */
    protected function getDefaultSchemaGrammar()
    {
        return new SchemaGrammar;
    }

    /**
     * @return \Illuminate\Contracts\Redis\Factory
     */
    public function getFactory(): Factory
    {
        return $this->factory;
    }

    /**
     * @param \Illuminate\Contracts\Redis\Factory $factory
     */
    public function setFactory(Factory $factory): void
    {
        $this->factory = $factory;
    }
}
