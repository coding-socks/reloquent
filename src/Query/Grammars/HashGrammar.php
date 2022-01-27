<?php

namespace CodingSocks\Reloquent\Query\Grammars;

use Illuminate\Database\Query\Builder;
use Ulid\Ulid;

class HashGrammar extends Grammar
{
    /**
     * Compile a fetch into Redis command.
     *
     * @param  \CodingSocks\Reloquent\Query\Builder  $query
     * @return array
     */
    public function compileFetch(Builder $query)
    {
        $cmdName = empty($query->columns) ? 'HGETALL' : 'HMGET';

        return [$cmdName, "{$query->from}:{$query->id}"];
    }

    /**
     * Compile an exists statement into Redis command.
     *
     * @param  \CodingSocks\Reloquent\Query\Builder  $query
     * @return array
     */
    public function compileExists(Builder $query)
    {
        return ['EXISTS', "{$query->from}:{$query->id}"];
    }

    /**
     * Compile a set statement into Redis command.
     *
     * @param  \CodingSocks\Reloquent\Query\Builder  $query
     * @param  array  $values
     * @return array
     */
    public function compileSet(Builder $query, array $values)
    {
        $table = $query->from;

        if (empty($values)) {
            return [];
        }

        if (! is_array(reset($values))) {
            $values = [$values];
        }

        $cmd = [];
        foreach ($values as $record) {
            $id = $query->newId();
            $segment = ["HSET", "{$table}:{$id}"];
            foreach ($record as $field => $value) {
                switch ($query->schema[$field]['type']) {
                    case 'number':
                        array_push($segment, $field, (float)$value);
                        break;
                    case 'bool':
                        array_push($segment, $field, (int)(bool)$value);
                        break;
                    default:
                        array_push($segment, $field, (string)$value);
                        break;
                }
            }
            $cmd[] = $segment;
        }

        return $cmd;
    }

    /**
     * Compile a set statement into Redis command.
     *
     * @param  \CodingSocks\Reloquent\Query\Builder  $query
     * @param  array  $values
     * @return array
     */
    public function compileInsert(Builder $query, array $values)
    {
        return $this->compileSet($query, $values);
    }

    /**
     * Compile an update statement into Redis command.
     *
     * @param  \CodingSocks\Reloquent\Query\Builder  $query
     * @param  array  $values
     * @return array
     */
    public function compileUpdate(Builder $query, array $values)
    {
        return $this->compileSet($query, $values);
    }

    /**
     * Compile a delete statement into Redis command.
     *
     * @param  \CodingSocks\Reloquent\Query\Builder  $query
     * @return array
     */
    public function compileDelete(Builder $query)
    {
        return ['DEL', "{$query->from}:{$query->id}"];
    }
}
