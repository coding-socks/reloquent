<?php

namespace CodingSocks\Reloquent\Schema;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\Grammar as BaseGrammar;
use Illuminate\Support\Arr;
use Illuminate\Support\Fluent;
use RuntimeException;
use function collect;

class Grammar extends BaseGrammar
{
    /**
     * The possible column modifiers.
     *
     * @var string[]
     */
    protected $modifiers = [
        'Sortable', 'NoIndex',
    ];

    /**
     * The possible column serials.
     *
     * @var string[]
     */
    protected $serials = ['numeric'];

    /**
     * Compile an info RediSearch command.
     *
     * @param $name
     * @return array
     */
    public function compileInfo($name)
    {
        return ['FT.INFO', $name];
    }

    /**
     * Compile a create table command.
     *
     * @param  \CodingSocks\Reloquent\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @param  \Illuminate\Database\Connection  $connection
     * @return array
     */
    public function compileCreate(Blueprint $blueprint, Fluent $command, Connection $connection)
    {
        $table = $blueprint->getTable();
        return array_merge([
            'FT.CREATE', $table,
            'ON', $blueprint->dataStructure ?? 'hash',
            'PREFIX', 1, $table.':',
            'SCHEMA',
        ], $this->getColumns($blueprint));
    }
    /**
     * Compile the blueprint's column definitions.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @return array
     */
    protected function getColumns(Blueprint $blueprint)
    {
        $columns = [];

        foreach ($blueprint->getAddedColumns() as $column) {
            // Each of the column types have their own compiler functions which are tasked
            // with turning the column definition into its SQL format for this platform
            // used by the connection. The column's modifiers are compiled and added.
            array_push($columns, ...$this->addModifiers(
                array_merge([$column->name], $this->getType($column)), $blueprint, $column));
        }

        return $columns;
    }

    /**
     * Add the column modifiers to the definition.
     *
     * @param  array  $cmd
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $column
     * @return array
     */
    protected function addModifiers($cmd, Blueprint $blueprint, Fluent $column)
    {
        foreach ($this->modifiers as $modifier) {
            if (method_exists($this, $method = "modify{$modifier}")) {
                array_push($cmd, ...Arr::wrap($this->{$method}($blueprint, $column)));
            }
        }

        return $cmd;
    }

    /**
     * Compile an add column command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return array
     */
    public function compileAdd(Blueprint $blueprint, Fluent $command)
    {
        // TODO: text/tag/numeric column.
        $columns = $this->prefixArray('add', $this->getColumns($blueprint));

        return array_values(
            ['alter table '.$this->wrapTable($blueprint).' '.implode(', ', $columns)]
        );
    }

    /**
     * Compile a drop table command.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $command
     * @return array
     */
    public function compileDrop(Blueprint $blueprint, Fluent $command)
    {
        $cmd = ['FT.DROPINDEX', $blueprint->getTable()];

        if ($command->get('dropData', false)) {
            $cmd[] = 'DD';
        }

        return $cmd;
    }

    /**
     * Create the column definition for an array type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return array
     */
    protected function typeArray(Fluent $column)
    {
        return $this->typeTag($column, $column->get('separator', '|'));
    }

    /**
     * Create the column definition for a string type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return array
     */
    protected function typeString(Fluent $column)
    {
        if ($column->get('textSearch', false)) {
            return $this->typeText($column);
        }
        return $this->typeTag($column);
    }

    /**
     * Create the column definition for a text type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return array
     */
    protected function typeText(Fluent $column)
    {
        $segment = ['TEXT'];
        if (! is_null($column->get('noStem'))) {
            $segment[] = 'NOSTEM';
        }
        if (! is_null($weight = $column->get('weight'))) {
            array_push($segment, 'WEIGHT', $weight);
        }
        if (! is_null($phonetic = $column->get('phonetic'))) {
            array_push($segment, 'PHONETIC', $phonetic);
        }
        return $segment;
    }

    /**
     * Create the column definition for an integer type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return array
     */
    protected function typeNumeric(Fluent $column)
    {
        return ['NUMERIC'];
    }

    /**
     * Create the column definition for a tag type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return array
     */
    protected function typeTag(Fluent $column, $separator = null)
    {
        $segment = ['TAG'];
        if (! is_null($separator = $column->get('separator', $separator))) {
            array_push($segment, 'SEPARATOR', $separator);
        }
        if (! is_null($column->get('caseSensitive'))) {
            $segment[] = 'CASESENSITIVE';
        }
        return $segment;
    }

    /**
     * Create the column definition for a geo type.
     *
     * @param  \Illuminate\Support\Fluent  $column
     * @return array
     */
    protected function typeGeo(Fluent $column)
    {
        return ['GEO'];
    }

    /**
     * Get the SQL for a SORTABLE column modifier.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $column
     * @return array
     */
    protected function modifySortable(Blueprint $blueprint, Fluent $column)
    {
        if (! is_null($column->sortable)) {
            return array_merge(
                ['SORTABLE'],
                $column->get('sortable') ? [] : ['UNF'] // UNF = un-normalized form
            );
        }
        return [];
    }

    /**
     * Get the SQL for a NOINDEX column modifier.
     *
     * @param  \Illuminate\Database\Schema\Blueprint  $blueprint
     * @param  \Illuminate\Support\Fluent  $column
     * @return array
     */
    protected function modifyNoIndex(Blueprint $blueprint, Fluent $column)
    {
        if (! is_null($column->get('noIndex'))) {
            return ['NOINDEX'];
        }
        return [];
    }

    /**
     * Wrap a single string in keyword identifiers.
     *
     * @param  string  $value
     * @return string
     */
    protected function wrapValue($value)
    {
        if ($value !== '*') {
            return '`'.str_replace('`', '``', $value).'`';
        }

        return $value;
    }
}
