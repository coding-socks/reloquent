<?php

namespace CodingSocks\Reloquent\Query;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Processors\Processor as BaseProcessor;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class Processor extends BaseProcessor
{
    /**
     * Process the results of a "search" query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $results
     * @param  string  $keyName
     * @return array
     */
    public function processCommand(Builder $query, $results, $keyName = null)
    {
        // The first element of a RediSearch result is a count and the following
        // elements are arrays or keys and arrays. We can use that to tell
        // the difference between a search result and a get result.
        if (empty($results) || (count($results) % 2 === 0 && ! is_array($results[1]))) {
            return [array_merge(is_null($keyName) ? [] : [$keyName => $query->id], $this->processResult($results))];
        }
        $count = array_shift($results);
        if (is_array(reset($results))) {
            $results = $this->processCommandResultWithoutId($query, $results);
        } else {
            $results = $this->processCommandResultWithId($query, $results, $keyName);
        }
        return $results;
    }

    protected function processCommandResultWithoutId(Builder $query, $original)
    {
        $results = [];
        foreach ($original as $result) {
            $results[] = $this->processResult($result);
        }
        return $results;
    }

    protected function processCommandResultWithId(Builder $query, $original, $keyName = null)
    {
        $results = [];
        $n = count($original);
        for ($i = 0; $i < $n; $i += 2) {
            $key = $this->unwrapKey($original[$i]);
            $result = $original[$i + 1];
            $results[] = array_merge(is_null($keyName) ? [] : [$keyName => $key], $this->processResult($result));
        }
        return $results;
    }

    /**
     * Process a single result of key-value pairs.
     *
     * @param $result
     * @return array
     */
    protected function processResult($result): array
    {
        $item = [];
        $n = count($result);
        for ($i = 0; $i < $n; $i += 2) {
            $k = $result[$i];
            $v = $result[$i + 1];
            if (Str::startsWith($k, '__generated_alias')) {
                $k = Arr::last(explode(',', $k));
            }
            $item[$k] = $v;
        }
        return $item;
    }

    /**
     * Process an  "insert get ID" query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  string  $cmd
     * @param  array  $values
     * @param  string|null  $sequence
     * @return int
     */
    public function processInsertGetId(Builder $query, $cmd, $values, $sequence = null)
    {
        $query->getConnection()->insert($cmd, $values);

        $id = $this->unwrapKey($cmd[0][1]);

        return is_numeric($id) ? (int) $id : $id;
    }

    /**
     * Process the results of a column listing query.
     *
     * @param  array  $results
     * @return array
     */
    public function processColumnListing($results)
    {
        return $results;
    }

    protected function unwrapKey($key)
    {
        return Arr::last(explode(':', $key));
    }
}
