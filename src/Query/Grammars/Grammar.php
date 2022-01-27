<?php

namespace CodingSocks\Reloquent\Query\Grammars;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\Grammar as BaseGrammar;
use Illuminate\Support\Arr;
use LogicException;

class Grammar extends BaseGrammar
{
    /**
     * The grammar specific operators.
     *
     * @var array
     */
    protected $operators = [
        '=', '<', '>', '<=', '>=', '<>', '!=',
        'contain', 'contains',
        'match', 'matches', 'match exactly', 'matches exactly',
    ];

    /**
     * The components that make up a select clause.
     *
     * @var string[]
     */
    protected $selectComponents = [
        'from',
        'wheres',
        'aggregate',
        'columns',
        'orders',
        'limit',
    ];

    /**
     * Get the format for database stored dates.
     *
     * @return string
     */
    public function getDateFormat()
    {
        return 'Uu'; // Unix timestamp + Microseconds
    }

    /**
     * Compile a select query into Redis command.
     *
     * @param  \CodingSocks\Reloquent\Query\Builder  $query
     * @return array
     */
    public function compileSearch(Builder $query)
    {
        $cmd = $this->compileComponents($query);
        return Arr::flatten($cmd);
    }

    protected function compileComponents(Builder $query)
    {
        // let matchPunctuation = /[,.<>{}[\]"':;!@#$%^&*()\-+=~|]/g;
        // let escapedValue = this.value.replace(matchPunctuation, '\\$&');
        return array_filter(parent::compileComponents($query));
    }

    /**
     * Compile the "select *" portion of the query.
     *
     * @param  \CodingSocks\Reloquent\Query\Builder  $query
     * @param  array  $columns
     * @return array|null
     */
    protected function compileColumns(Builder $query, $columns)
    {
        // If the query is actually performing an aggregating select, we will let that
        // compiler handle the building of the select clauses, as it will need some
        // more syntax that is best handled by that function to keep things neat.
        if (! is_null($query->aggregate)) {
            return null;
        }
        if (empty($columns) || $columns == ['*']) {
            return null;
        }

        return array_merge(['RETURN', count($columns)], $columns);
    }


    protected function compileAggregate(Builder $query, $aggregate)
    {
        $columns = $aggregate['columns'];

        $segment = ['GROUPBY', 0];

        foreach ($columns as $column) {
            array_push($segment, 'REDUCE', $aggregate['function'], 3, '@'.$column, 'AS', 'aggregate');
        }

        return $segment;
    }

    /**
     * Compile the "from" portion of the query.
     *
     * @param  \CodingSocks\Reloquent\Query\Builder  $query
     * @param  string  $table
     * @return array
     */
    protected function compileFrom(Builder $query, $table)
    {
        if (! is_null($query->aggregate)) {
            return ['FT.AGGREGATE', $table];
        }
        return ['FT.SEARCH', $table];
    }

    /**
     * Compile the "where" portions of the query.
     *
     * @param  \CodingSocks\Reloquent\Query\Builder  $query
     * @return array
     */
    public function compileWheres(Builder $query)
    {
        // Each type of where clauses has its own compiler function which is responsible
        // for actually creating the where clauses SQL. This helps keep the code nice
        // and maintainable since each clause has a very small method that it uses.
        if (empty($query->wheres)) {
            return ['*'];
        }

        return [implode(' ', $this->compileWheresToArray($query))];
    }

    /**
     * Get an array of all the where clauses for the query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @return array
     */
    protected function compileWheresToArray($query)
    {
        return collect($query->wheres)->map(function ($where) use ($query) {
            return $this->compileBoolean($where['boolean']).$this->{"where{$where['type']}"}($query, $where);
        })->all();
    }

    protected function compileBoolean($boolean)
    {
        switch ($boolean) {
            case 'and':
                return '';
            case 'or':
                return '|';
            default:
                return throw new LogicException("Unknown boolean {$boolean}");
        }
    }

    /**
     * Compile a raw where clause.
     *
     * @param  \CodingSocks\Reloquent\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereRaw(Builder $query, $where)
    {
        return $where['sql'];
    }

    /**
     * Compile a basic where clause.
     *
     * @param  \CodingSocks\Reloquent\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereBasic(Builder $query, $where)
    {
        return $this->{"where{$where['schema']['type']}"}($query, $where);
    }

    /**
     * Compile an array where clause.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereArray(Builder $query, $where)
    {
        $column = $where['column'];
        $operator = $where['operator'];
        $value = $where['value'];

        switch ($operator) {
            case 'contain':
            case 'contains':
                return $this->wrap($column) . ':{' . $this->wrapValue($value) . '}';
            default:
                return throw new LogicException("Unknown operator {$operator}");
        }
    }

    /**
     * Compile a boolean where clause.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereBoolean(Builder $query, $where)
    {
        return $this->whereTag($query, array_merge($where, ['value' => (bool) $where['value']]));
    }

    /**
     * Compile a number where clause.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereNumber(Builder $query, $where)
    {
        return $this->whereNumeric($query, $where);
    }

    /**
     * Compile a numeric where clause.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereNumeric(Builder $query, $where)
    {
        $operator = $where['operator'];
        $value = $where['value'];

        switch ($operator) {
            case '=':
                return $this->whereBetween($query, array_merge($where, ['values' => [$value, $value]]));
            case '!=':
                return '-' . $this->whereBetween($query, array_merge($where, ['values' => [$value, $value]]));
            case '>':
                return $this->whereBetween($query, array_merge($where, ['values' => ['(' . $value, INF]]));
            case '>=':
                return $this->whereBetween($query, array_merge($where, ['values' => [$value, INF]]));
            case '<':
                return $this->whereBetween($query, array_merge($where, ['values' => [-INF, '(' . $value]]));
            case '<=':
                return $this->whereBetween($query, array_merge($where, ['values' => [-INF, $value]]));
            default:
                return throw new LogicException("Unknown operator {$operator}");
        }
    }

    /**
     * Compile a string where clause.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereString(Builder $query, $where)
    {
        if ($where['schema']['textSearch'] ?? false) {
            return $this->whereText($query, $where);
        }

        return $this->whereTag($query, $where);
    }

    /**
     * Compile a tag where clause.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereTag(Builder $query, $where)
    {
        $column = $where['column'];
        $operator = $where['operator'];
        $value = $where['value'];

        switch ($operator) {
            case '=':
                return $this->wrap($column) . ':{' . $this->wrapValue($value) . '}';
            case '!=':
            case '<>':
                return '-' . $this->wrap($column) . ':{' . $this->wrapValue($value) . '}';
            default:
                return throw new LogicException("Unknown boolean operator {$operator}");
        }
    }

    /**
     * Compile a text where clause.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereText(Builder $query, $where)
    {
        $column = $where['column'];
        $operator = $where['operator'];
        $value = $where['value'];

        switch ($operator) {
            case 'match':
            case 'matches':
                return $this->wrap($column) . ':\'' . $this->wrapValue($value) . '\'';
            case 'match exactly':
            case 'matches exactly':
                return $this->wrap($column) . ':"' . $this->wrapValue($value) . '"';
            default:
                return throw new LogicException("Unknown operator {$operator}");
        }
    }

    /**
     * Compile a "between" where clause.
     *
     * @param  \CodingSocks\Reloquent\Query\Builder  $query
     * @param  array  $where
     * @return string
     */
    protected function whereBetween(Builder $query, $where)
    {
        $not = (! empty($where['not'])) ? '-' : '';

        $min = reset($where['values']);
        if ($min === -INF) {
            $min = '-inf';
        }

        $max = end($where['values']);
        if ($max === INF) {
            $min = '+inf';
        }

        return $not.$this->wrap($where['column']).':['.$min.' '.$max.']';
    }

    /**
     * Compile the "order by" portions of the query.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $orders
     * @return array
     */
    protected function compileOrders(Builder $query, $orders)
    {
        if (empty($orders)) {
            return [];
        }

        // Only one SORTBY is allowed in a search command.
        // https://github.com/RediSearch/RediSearch/issues/1615
        return array_merge(['SORTBY'], ...$this->compileOrdersToArray($query, [end($orders)]));
    }

    /**
     * Compile the query orders to an array.
     *
     * @param  \Illuminate\Database\Query\Builder  $query
     * @param  array  $orders
     * @return array
     */
    protected function compileOrdersToArray(Builder $query, $orders)
    {
        return array_map(function ($order) {
            return array_key_exists('sql', $order)
                ? explode(' ', $order['sql'])
                : [$order['column'], $order['direction']];
        }, $orders);
    }

    protected function compileLimit(Builder $query, $limit)
    {
        return ['LIMIT', (int) $query->offset, (int) $limit];
    }

    /**
     * Wrap a value in keyword identifiers.
     *
     * @param  \Illuminate\Database\Query\Expression|string  $value
     * @param  bool  $prefixAlias
     * @return string
     */
    public function wrap($value, $prefixAlias = false)
    {
        return '@' . parent::wrap($value, $prefixAlias);
    }

    /**
     * Wrap a single string in keyword identifiers.
     *
     * @param  string  $value
     * @return string
     */
    protected function wrapValue($value)
    {
        return collect($value)->map(function ($value) {
            return preg_replace("/[,.<>{}\[\]\"':;!@#$%^&*()\-+=~| ]/", '\\\\$0', $value);
        })->join('|');
    }
}
