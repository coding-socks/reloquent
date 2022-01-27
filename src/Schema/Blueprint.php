<?php

namespace CodingSocks\Reloquent\Schema;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint as BaseBlueprint;
use Illuminate\Database\Schema\ForeignKeyDefinition;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Support\Fluent;
use Illuminate\Support\Traits\Macroable;
use LogicException;

class Blueprint extends BaseBlueprint
{
    use Macroable;

    /**
     * The table the blueprint describes.
     *
     * @var string
     */
    protected $table;

    /**
     * The prefix of the table.
     *
     * @var string
     */
    protected $prefix;

    /**
     * The columns that should be added to the table.
     *
     * @var \CodingSocks\Reloquent\Schema\ColumnDefinition[]
     */
    protected $columns = [];

    /**
     * The commands that should be run for the table.
     *
     * @var \Illuminate\Support\Fluent[]
     */
    protected $commands = [];

    /**
     * The storage engine that should be used for the table.
     *
     * @var string
     */
    public $engine;

    /**
     * The default character set that should be used for the table.
     *
     * @var string
     */
    public $charset;

    /**
     * The data structure of the records.
     *
     * @var string
     */
    public $dataStructure;

    /**
     * The collation that should be used for the table.
     *
     * @var string
     */
    public $collation;

    /**
     * Whether to make the table temporary.
     *
     * @var bool
     */
    public $temporary = false;

    /**
     * The column to add new columns after.
     *
     * @var string
     */
    public $after;

    /**
     * Create a new schema blueprint.
     *
     * @param  string  $table
     * @param  \Closure|null  $callback
     * @param  string  $prefix
     * @return void
     */
    public function __construct($table, Closure $callback = null, $prefix = '')
    {
        $this->table = $table;
        $this->prefix = $prefix;

        if (! is_null($callback)) {
            $callback($this);
        }
    }

    /**
     * Execute the blueprint against the database.
     *
     * @param  \Illuminate\Database\Connection  $connection
     * @param  \Illuminate\Database\Schema\Grammars\Grammar  $grammar
     * @return void
     */
    public function build(Connection $connection, Grammar $grammar)
    {
        foreach ($this->toCommand($connection, $grammar) as $statement) {
            $connection->statement($statement);
        }
    }

    /**
     * Get the raw Redis command for the blueprint.
     *
     * @param  \Illuminate\Database\Connection  $connection
     * @param  \Illuminate\Database\Schema\Grammars\Grammar  $grammar
     * @return array
     */
    public function toCommand(Connection $connection, Grammar $grammar)
    {
        $this->addImpliedCommands($grammar);

        $statements = [];

        // Each type of command has a corresponding compiler function on the schema
        // grammar which is used to build the necessary SQL statements to build
        // the blueprint element, so we'll just call that compilers function.
        $this->ensureCommandsAreValid($connection);

        foreach ($this->commands as $command) {
            $method = 'compile'.ucfirst($command->name);

            if (method_exists($grammar, $method) || $grammar::hasMacro($method)) {
                if (! is_null($cmd = $grammar->$method($this, $command, $connection))) {
                    $statements = array_merge($statements, [$cmd]);
                }
            }
        }

        return $statements;
    }

    /**
     * Ensure the commands on the blueprint are valid for the connection type.
     *
     * @param  \Illuminate\Database\Connection  $connection
     * @return void
     *
     * @throws \BadMethodCallException
     */
    protected function ensureCommandsAreValid(Connection $connection)
    {
    }

    /**
     * Get all of the commands matching the given names.
     *
     * @param  array  $names
     * @return \Illuminate\Support\Collection
     */
    protected function commandsNamed(array $names)
    {
        return collect($this->commands)->filter(function ($command) use ($names) {
            return in_array($command->name, $names);
        });
    }

    /**
     * Add the commands that are implied by the blueprint's state.
     *
     * @param  \Illuminate\Database\Schema\Grammars\Grammar  $grammar
     * @return void
     */
    protected function addImpliedCommands(Grammar $grammar)
    {
        if (count($this->getAddedColumns()) > 0 && ! $this->creating()) {
            array_unshift($this->commands, $this->createCommand('add'));
        }

        if (count($this->getChangedColumns()) > 0 && ! $this->creating()) {
            array_unshift($this->commands, $this->createCommand('change'));
        }

        $this->addFluentIndexes();

        $this->addFluentCommands($grammar);
    }

    /**
     * Add the index commands fluently specified on columns.
     *
     * @return void
     */
    protected function addFluentIndexes()
    {
        foreach ($this->columns as $column) {
            foreach (['primary', 'unique', 'index', 'fulltext', 'fullText', 'spatialIndex'] as $index) {
                // If the index has been specified on the given column, but is simply equal
                // to "true" (boolean), no name has been specified for this index so the
                // index method can be called without a name and it will generate one.
                if ($column->{$index} === true) {
                    $this->{$index}($column->name);
                    $column->{$index} = false;

                    continue 2;
                }

                // If the index has been specified on the given column, and it has a string
                // value, we'll go ahead and call the index method and pass the name for
                // the index since the developer specified the explicit name for this.
                elseif (isset($column->{$index})) {
                    $this->{$index}($column->name, $column->{$index});
                    $column->{$index} = false;

                    continue 2;
                }
            }
        }
    }

    /**
     * Add the fluent commands specified on any columns.
     *
     * @param  \Illuminate\Database\Schema\Grammars\Grammar  $grammar
     * @return void
     */
    public function addFluentCommands(Grammar $grammar)
    {
        foreach ($this->columns as $column) {
            foreach ($grammar->getFluentCommands() as $commandName) {
                $attributeName = lcfirst($commandName);

                if (! isset($column->{$attributeName})) {
                    continue;
                }

                $value = $column->{$attributeName};

                $this->addCommand(
                    $commandName, compact('value', 'column')
                );
            }
        }
    }

    /**
     * Determine if the blueprint has a create command.
     *
     * @return bool
     */
    public function creating()
    {
        return collect($this->commands)->contains(function ($command) {
            return $command->name === 'create';
        });
    }

    /**
     * Indicate that the table needs to be created.
     *
     * @return \Illuminate\Support\Fluent
     */
    public function create()
    {
        return $this->addCommand('create');
    }

    /**
     * Indicate that the table needs to be temporary.
     *
     * @return void
     */
    public function temporary()
    {
        $this->temporary = true;
    }

    /**
     * Indicate that the table will use a specific data source.
     *
     * @param string $dataStructure
     * @return void
     */
    public function dataStructure($dataStructure)
    {
        $this->dataStructure = $dataStructure;
    }

    /**
     * Indicate that the table should be dropped.
     *
     * @param  bool  $dropData
     * @return \Illuminate\Support\Fluent
     */
    public function drop($dropData = false)
    {
        return $this->addCommand('drop', compact('dropData'));
    }

    /**
     * Indicate that the table should be dropped if it exists.
     *
     * @return \Illuminate\Support\Fluent
     */
    public function dropIfExists()
    {
        throw new LogicException('This database driver does not support drop operation.');
    }

    /**
     * Indicate that the given columns should be dropped.
     *
     * @param  array|mixed  $columns
     * @return \Illuminate\Support\Fluent
     */
    public function dropColumn($columns)
    {
        throw new LogicException('This database driver does not support drop column operation.');
    }

    /**
     * Indicate that the given columns should be renamed.
     *
     * @param  string  $from
     * @param  string  $to
     * @return \Illuminate\Support\Fluent
     */
    public function renameColumn($from, $to)
    {
        throw new LogicException('This database driver does not support rename column operation.');
    }

    /**
     * Indicate that the given primary key should be dropped.
     *
     * @param  string|array|null  $index
     * @return \Illuminate\Support\Fluent
     */
    public function dropPrimary($index = null)
    {
        throw new LogicException('This database driver does not support drop primary key operation.');
    }

    /**
     * Indicate that the given unique key should be dropped.
     *
     * @param  string|array  $index
     * @return \Illuminate\Support\Fluent
     */
    public function dropUnique($index)
    {
        throw new LogicException('This database driver does not support drop unique key operation.');
    }

    /**
     * Indicate that the given index should be dropped.
     *
     * @param  string|array  $index
     * @return \Illuminate\Support\Fluent
     */
    public function dropIndex($index)
    {
        throw new LogicException('This database driver does not support drop index operation.');
    }

    /**
     * Indicate that the given fulltext index should be dropped.
     *
     * @param  string|array  $index
     * @return \Illuminate\Support\Fluent
     */
    public function dropFullText($index)
    {
        throw new LogicException('This database driver does not support drop full text index operation.');
    }

    /**
     * Indicate that the given spatial index should be dropped.
     *
     * @param  string|array  $index
     * @return \Illuminate\Support\Fluent
     */
    public function dropSpatialIndex($index)
    {
        throw new LogicException('This database driver does not support drop spatial index operation.');
    }

    /**
     * Indicate that the given foreign key should be dropped.
     *
     * @param  string|array  $index
     * @return \Illuminate\Support\Fluent
     */
    public function dropForeign($index)
    {
        throw new LogicException('This database driver does not support drop foreign key operation.');
    }

    /**
     * Indicate that the given indexes should be renamed.
     *
     * @param  string  $from
     * @param  string  $to
     * @return \Illuminate\Support\Fluent
     */
    public function renameIndex($from, $to)
    {
        throw new LogicException('This database driver does not support rename index operation.');
    }

    /**
     * Indicate that the polymorphic columns should be dropped.
     *
     * @param  string  $name
     * @param  string|null  $indexName
     * @return void
     */
    public function dropMorphs($name, $indexName = null)
    {
        throw new LogicException('This database driver does not support drop polymorphic columns operation.');
    }

    /**
     * Rename the table to a given name.
     *
     * @param  string  $to
     * @return \Illuminate\Support\Fluent
     */
    public function rename($to)
    {
        throw new LogicException('This database driver does not support rename operation.');
    }

    /**
     * Specify the primary key(s) for the table.
     *
     * @param  string|array  $columns
     * @param  string|null  $name
     * @param  string|null  $algorithm
     * @return \Illuminate\Support\Fluent
     */
    public function primary($columns, $name = null, $algorithm = null)
    {
        return $this->indexCommand('primary', $columns, $name, $algorithm);
    }

    /**
     * Specify a unique index for the table.
     *
     * @param  string|array  $columns
     * @param  string|null  $name
     * @param  string|null  $algorithm
     * @return \Illuminate\Support\Fluent
     */
    public function unique($columns, $name = null, $algorithm = null)
    {
        throw new LogicException('This database driver does not support unique column.');
    }

    /**
     * Specify an index for the table.
     *
     * @param  string|array  $columns
     * @param  string|null  $name
     * @param  string|null  $algorithm
     * @return \Illuminate\Support\Fluent
     */
    public function index($columns, $name = null, $algorithm = null)
    {
        throw new LogicException('This database driver does not support index column.');
    }

    /**
     * Specify an fulltext for the table.
     *
     * @param  string|array  $columns
     * @param  string|null  $name
     * @param  string|null  $algorithm
     * @return \Illuminate\Support\Fluent
     */
    public function fullText($columns, $name = null, $algorithm = null)
    {
        return $this->text($columns);
    }

    /**
     * Specify a spatial index for the table.
     *
     * @param  string|array  $columns
     * @param  string|null  $name
     * @return \Illuminate\Support\Fluent
     */
    public function spatialIndex($columns, $name = null)
    {
        throw new LogicException('This database driver does not support spatial index column.');
    }

    /**
     * Specify a raw index for the table.
     *
     * @param  string  $expression
     * @param  string  $name
     * @return \Illuminate\Support\Fluent
     */
    public function rawIndex($expression, $name)
    {
        throw new LogicException('This database driver does not support raw index.');
    }

    /**
     * Specify a foreign key for the table.
     *
     * @param  string|array  $columns
     * @param  string|null  $name
     * @return \Illuminate\Database\Schema\ForeignKeyDefinition
     */
    public function foreign($columns, $name = null)
    {
        throw new LogicException('This database driver does not support foreign key column.');
    }

    /**
     * Create a new auto-incrementing big integer (8-byte) column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function id($column = 'id')
    {
        throw new LogicException('This database driver does not support auto-incrementing column.');
    }

    /**
     * Create a new auto-incrementing integer (4-byte) column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function increments($column)
    {
        throw new LogicException('This database driver does not support auto-incrementing column.');
    }

    /**
     * Create a new auto-incrementing integer (4-byte) column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function integerIncrements($column)
    {
        throw new LogicException('This database driver does not support auto-incrementing column.');
    }

    /**
     * Create a new auto-incrementing tiny integer (1-byte) column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function tinyIncrements($column)
    {
        throw new LogicException('This database driver does not support auto-incrementing column.');
    }

    /**
     * Create a new auto-incrementing small integer (2-byte) column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function smallIncrements($column)
    {
        throw new LogicException('This database driver does not support auto-incrementing column.');
    }

    /**
     * Create a new auto-incrementing medium integer (3-byte) column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function mediumIncrements($column)
    {
        throw new LogicException('This database driver does not support auto-incrementing column.');
    }

    /**
     * Create a new auto-incrementing big integer (8-byte) column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function bigIncrements($column)
    {
        throw new LogicException('This database driver does not support auto-incrementing column.');
    }

    /**
     * Create a new char column on the table.
     *
     * @param  string  $column
     * @param  int|null  $length
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function char($column, $length = null)
    {
        throw new LogicException('This database driver does not support char column type.');
    }

    /**
     * Create a new tag column on the table.
     *
     * @param  string  $column
     * @param  int|null  $length
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function tag($column)
    {
        return $this->addColumn('tag', $column);
    }

    /**
     * Create a new array column on the table.
     *
     * @param  string  $column
     * @param  int|null  $length
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function array($column, $separator = '|')
    {
        return $this->addColumn('tag', $column, [
            'separator' => $separator,
        ]);
    }

    /**
     * Create a new string column on the table.
     *
     * @param  string  $column
     * @param  int|null  $length
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function string($column, $length = null)
    {
        return $this->addColumn('string', $column);
    }

    /**
     * Create a new tiny text column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function tinyText($column)
    {
        throw new LogicException('This database driver does not support tiny text column type.');
    }

    /**
     * Create a new text column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function text($column)
    {
        return $this->addColumn('text', $column);
    }

    /**
     * Create a new medium text column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function mediumText($column)
    {
        throw new LogicException('This database driver does not support medium text column type.');
    }

    /**
     * Create a new long text column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function longText($column)
    {
        throw new LogicException('This database driver does not support long text column type.');
    }

    /**
     * Create a new number column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function number($column)
    {
        return $this->numeric($column);
    }

    /**
     * Create a new numeric column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function numeric($column)
    {
        return $this->addColumn('numeric', $column);
    }

    /**
     * Create a new integer (4-byte) column on the table.
     *
     * @param  string  $column
     * @param  bool  $autoIncrement
     * @param  bool  $unsigned
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function integer($column, $autoIncrement = false, $unsigned = false)
    {
        return $this->numeric($column);
    }

    /**
     * Create a new tiny integer (1-byte) column on the table.
     *
     * @param  string  $column
     * @param  bool  $autoIncrement
     * @param  bool  $unsigned
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function tinyInteger($column, $autoIncrement = false, $unsigned = false)
    {
        throw new LogicException('This database driver does not support tiny integer column type.');
    }

    /**
     * Create a new small integer (2-byte) column on the table.
     *
     * @param  string  $column
     * @param  bool  $autoIncrement
     * @param  bool  $unsigned
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function smallInteger($column, $autoIncrement = false, $unsigned = false)
    {
        throw new LogicException('This database driver does not support small integer column type.');
    }

    /**
     * Create a new medium integer (3-byte) column on the table.
     *
     * @param  string  $column
     * @param  bool  $autoIncrement
     * @param  bool  $unsigned
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function mediumInteger($column, $autoIncrement = false, $unsigned = false)
    {
        throw new LogicException('This database driver does not support medium integer column type.');
    }

    /**
     * Create a new big integer (8-byte) column on the table.
     *
     * @param  string  $column
     * @param  bool  $autoIncrement
     * @param  bool  $unsigned
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function bigInteger($column, $autoIncrement = false, $unsigned = false)
    {
        throw new LogicException('This database driver does not support big integer column type.');
    }

    /**
     * Create a new unsigned integer (4-byte) column on the table.
     *
     * @param  string  $column
     * @param  bool  $autoIncrement
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function unsignedInteger($column, $autoIncrement = false)
    {
        throw new LogicException('This database driver does not support unsigned integer column type.');
    }

    /**
     * Create a new unsigned tiny integer (1-byte) column on the table.
     *
     * @param  string  $column
     * @param  bool  $autoIncrement
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function unsignedTinyInteger($column, $autoIncrement = false)
    {
        throw new LogicException('This database driver does not support unsigned tiny integer column type.');
    }

    /**
     * Create a new unsigned small integer (2-byte) column on the table.
     *
     * @param  string  $column
     * @param  bool  $autoIncrement
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function unsignedSmallInteger($column, $autoIncrement = false)
    {
        throw new LogicException('This database driver does not support unsigned small integer column type.');
    }

    /**
     * Create a new unsigned medium integer (3-byte) column on the table.
     *
     * @param  string  $column
     * @param  bool  $autoIncrement
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function unsignedMediumInteger($column, $autoIncrement = false)
    {
        throw new LogicException('This database driver does not support unsigned medium integer column type.');
    }

    /**
     * Create a new unsigned big integer (8-byte) column on the table.
     *
     * @param  string  $column
     * @param  bool  $autoIncrement
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function unsignedBigInteger($column, $autoIncrement = false)
    {
        throw new LogicException('This database driver does not support unsigned big integer column type.');
    }

    /**
     * Create a new unsigned big integer (8-byte) column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function foreignId($column)
    {
        return $this->numeric($column);
    }

    /**
     * Create a foreign ID column for the given model.
     *
     * @param  \Illuminate\Database\Eloquent\Model|string  $model
     * @param  string|null  $column
     * @return \Illuminate\Database\Schema\ForeignIdColumnDefinition
     */
    public function foreignIdFor($model, $column = null)
    {
        if (is_string($model)) {
            $model = new $model;
        }

        return $this->foreignId($column ?: $model->getForeignKey());
    }

    /**
     * Create a new float column on the table.
     *
     * @param  string  $column
     * @param  int  $total
     * @param  int  $places
     * @param  bool  $unsigned
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function float($column, $total = 8, $places = 2, $unsigned = false)
    {
        return $this->numeric($column);
    }

    /**
     * Create a new double column on the table.
     *
     * @param  string  $column
     * @param  int|null  $total
     * @param  int|null  $places
     * @param  bool  $unsigned
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function double($column, $total = null, $places = null, $unsigned = false)
    {
        return $this->numeric($column);
    }

    /**
     * Create a new decimal column on the table.
     *
     * @param  string  $column
     * @param  int  $total
     * @param  int  $places
     * @param  bool  $unsigned
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function decimal($column, $total = 8, $places = 2, $unsigned = false)
    {
        return $this->numeric($column);
    }

    /**
     * Create a new unsigned float column on the table.
     *
     * @param  string  $column
     * @param  int  $total
     * @param  int  $places
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function unsignedFloat($column, $total = 8, $places = 2)
    {
        throw new LogicException('This database driver does not support unsigned float column type.');
    }

    /**
     * Create a new unsigned double column on the table.
     *
     * @param  string  $column
     * @param  int  $total
     * @param  int  $places
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function unsignedDouble($column, $total = null, $places = null)
    {
        throw new LogicException('This database driver does not support unsigned double column type.');
    }

    /**
     * Create a new unsigned decimal column on the table.
     *
     * @param  string  $column
     * @param  int  $total
     * @param  int  $places
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function unsignedDecimal($column, $total = 8, $places = 2)
    {
        throw new LogicException('This database driver does not support unsigned decimal column type.');
    }

    /**
     * Create a new boolean column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function boolean($column)
    {
        return $this->tag($column);
    }

    /**
     * Create a new enum column on the table.
     *
     * @param  string  $column
     * @param  array  $allowed
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function enum($column, array $allowed)
    {
        throw new LogicException('This database driver does not support enum column type.');
    }

    /**
     * Create a new set column on the table.
     *
     * @param  string  $column
     * @param  array  $allowed
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function set($column, array $allowed)
    {
        throw new LogicException('This database driver does not support set column type.');
    }

    /**
     * Create a new json column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function json($column)
    {
        throw new LogicException('This database driver does not support json column type.');
    }

    /**
     * Create a new jsonb column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function jsonb($column)
    {
        throw new LogicException('This database driver does not support jsonb column type.');
    }

    /**
     * Create a new date column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function date($column)
    {
        throw new LogicException('This database driver does not support date column type.');
    }

    /**
     * Create a new date-time column on the table.
     *
     * @param  string  $column
     * @param  int  $precision
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function dateTime($column, $precision = 0)
    {
        throw new LogicException('This database driver does not support date-time column type.');
    }

    /**
     * Create a new date-time column (with time zone) on the table.
     *
     * @param  string  $column
     * @param  int  $precision
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function dateTimeTz($column, $precision = 0)
    {
        throw new LogicException('This database driver does not support date-time column type.');
    }

    /**
     * Create a new time column on the table.
     *
     * @param  string  $column
     * @param  int  $precision
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function time($column, $precision = 0)
    {
        throw new LogicException('This database driver does not support time column type.');
    }

    /**
     * Create a new time column (with time zone) on the table.
     *
     * @param  string  $column
     * @param  int  $precision
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function timeTz($column, $precision = 0)
    {
        throw new LogicException('This database driver does not support time column type.');
    }

    /**
     * Create a new timestamp column on the table.
     *
     * @param  string  $column
     * @param  int  $precision
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function timestamp($column, $precision = 0)
    {
        throw new LogicException('This database driver does not support timestamp column type.');
    }

    /**
     * Create a new timestamp (with time zone) column on the table.
     *
     * @param  string  $column
     * @param  int  $precision
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function timestampTz($column, $precision = 0)
    {
        throw new LogicException('This database driver does not support timestamp column type.');
    }

    /**
     * Add nullable creation and update timestamps to the table.
     *
     * @param  int  $precision
     * @return void
     */
    public function timestamps($precision = 0)
    {
        $this->numeric('created_at')->sortable();

        $this->numeric('updated_at')->sortable();
    }

    /**
     * Add nullable creation and update timestamps to the table.
     *
     * Alias for self::timestamps().
     *
     * @param  int  $precision
     * @return void
     */
    public function nullableTimestamps($precision = 0)
    {
        throw new LogicException('This database driver does not support timestamp column type.');
    }

    /**
     * Add creation and update timestampTz columns to the table.
     *
     * @param  int  $precision
     * @return void
     */
    public function timestampsTz($precision = 0)
    {
        throw new LogicException('This database driver does not support timestamp column type.');
    }

    /**
     * Add a "deleted at" timestamp for the table.
     *
     * @param  string  $column
     * @param  int  $precision
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function softDeletes($column = 'deleted_at', $precision = 0)
    {
        return $this->numeric($column)->nullable();
    }

    /**
     * Add a "deleted at" timestampTz for the table.
     *
     * @param  string  $column
     * @param  int  $precision
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function softDeletesTz($column = 'deleted_at', $precision = 0)
    {
        throw new LogicException('This database driver does not support timestamp column type.');
    }

    /**
     * Create a new year column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function year($column)
    {
        throw new LogicException('This database driver does not support year column type.');
    }

    /**
     * Create a new binary column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function binary($column)
    {
        throw new LogicException('This database driver does not support binary column type.');
    }

    /**
     * Create a new uuid column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function uuid($column)
    {
        throw new LogicException('This database driver does not support uuid column type.');
    }

    /**
     * Create a new UUID column on the table with a foreign key constraint.
     *
     * @param  string  $column
     * @return \Illuminate\Database\Schema\ForeignIdColumnDefinition
     */
    public function foreignUuid($column)
    {
        throw new LogicException('This database driver does not support uuid column type.');
    }

    /**
     * Create a new IP address column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function ipAddress($column)
    {
        throw new LogicException('This database driver does not support ip address column type.');
    }

    /**
     * Create a new MAC address column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function macAddress($column)
    {
        throw new LogicException('This database driver does not support amc address column type.');
    }

    /**
     * Create a new geometry column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function geo($column)
    {
        return $this->addColumn('geo', $column);
    }

    /**
     * Create a new geometry column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function geometry($column)
    {
        return $this->geo($column);
    }

    /**
     * Create a new point column on the table.
     *
     * @param  string  $column
     * @param  int|null  $srid
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function point($column, $srid = null)
    {
        throw new LogicException('This database driver does not support point column type.');
    }

    /**
     * Create a new linestring column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function lineString($column)
    {
        throw new LogicException('This database driver does not support linestring column type.');
    }

    /**
     * Create a new polygon column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function polygon($column)
    {
        throw new LogicException('This database driver does not support polygon column type.');
    }

    /**
     * Create a new geometrycollection column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function geometryCollection($column)
    {
        throw new LogicException('This database driver does not support geometrycollection column type.');
    }

    /**
     * Create a new multipoint column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function multiPoint($column)
    {
        throw new LogicException('This database driver does not support multipoint column type.');
    }

    /**
     * Create a new multilinestring column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function multiLineString($column)
    {
        throw new LogicException('This database driver does not support multilinestring column type.');
    }

    /**
     * Create a new multipolygon column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function multiPolygon($column)
    {
        throw new LogicException('This database driver does not support multipolygon column type.');
    }

    /**
     * Create a new multipolygon column on the table.
     *
     * @param  string  $column
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function multiPolygonZ($column)
    {
        throw new LogicException('This database driver does not support multipolygon column type.');
    }

    /**
     * Create a new generated, computed column on the table.
     *
     * @param  string  $column
     * @param  string  $expression
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function computed($column, $expression)
    {
        throw new LogicException('This database driver does not support computed column type.');
    }

    /**
     * Add the proper columns for a polymorphic table.
     *
     * @param  string  $name
     * @param  string|null  $indexName
     * @return void
     */
    public function morphs($name, $indexName = null)
    {
        if (Builder::$defaultMorphKeyType === 'uuid') {
            $this->uuidMorphs($name, $indexName);
        } else {
            $this->numericMorphs($name, $indexName);
        }
    }

    /**
     * Add nullable columns for a polymorphic table.
     *
     * @param  string  $name
     * @param  string|null  $indexName
     * @return void
     */
    public function nullableMorphs($name, $indexName = null)
    {
        if (Builder::$defaultMorphKeyType === 'uuid') {
            $this->nullableUuidMorphs($name, $indexName);
        } else {
            $this->nullableNumericMorphs($name, $indexName);
        }
    }

    /**
     * Add the proper columns for a polymorphic table using numeric IDs (incremental).
     *
     * @param  string  $name
     * @param  string|null  $indexName
     * @return void
     */
    public function numericMorphs($name, $indexName = null)
    {
        $this->string("{$name}_type");

        $this->unsignedBigInteger("{$name}_id");

        $this->index(["{$name}_type", "{$name}_id"], $indexName);
    }

    /**
     * Add nullable columns for a polymorphic table using numeric IDs (incremental).
     *
     * @param  string  $name
     * @param  string|null  $indexName
     * @return void
     */
    public function nullableNumericMorphs($name, $indexName = null)
    {
        $this->string("{$name}_type")->nullable();

        $this->unsignedBigInteger("{$name}_id")->nullable();

        $this->index(["{$name}_type", "{$name}_id"], $indexName);
    }

    /**
     * Add the proper columns for a polymorphic table using UUIDs.
     *
     * @param  string  $name
     * @param  string|null  $indexName
     * @return void
     */
    public function uuidMorphs($name, $indexName = null)
    {
        $this->string("{$name}_type");

        $this->uuid("{$name}_id");

        $this->index(["{$name}_type", "{$name}_id"], $indexName);
    }

    /**
     * Add nullable columns for a polymorphic table using UUIDs.
     *
     * @param  string  $name
     * @param  string|null  $indexName
     * @return void
     */
    public function nullableUuidMorphs($name, $indexName = null)
    {
        $this->string("{$name}_type")->nullable();

        $this->uuid("{$name}_id")->nullable();

        $this->index(["{$name}_type", "{$name}_id"], $indexName);
    }

    /**
     * Adds the `remember_token` column to the table.
     *
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function rememberToken()
    {
        return $this->string('remember_token', 100)->nullable();
    }

    /**
     * Add a new index command to the blueprint.
     *
     * @param  string  $type
     * @param  string|array  $columns
     * @param  string  $index
     * @param  string|null  $algorithm
     * @return \Illuminate\Support\Fluent
     */
    protected function indexCommand($type, $columns, $index, $algorithm = null)
    {
        $columns = (array) $columns;

        // If no name was specified for this index, we will create one using a basic
        // convention of the table name, followed by the columns, followed by an
        // index type, such as primary or index, which makes the index unique.
        $index = $index ?: $this->createIndexName($type, $columns);

        return $this->addCommand(
            $type, compact('index', 'columns', 'algorithm')
        );
    }

    /**
     * Create a new drop index command on the blueprint.
     *
     * @param  string  $command
     * @param  string  $type
     * @param  string|array  $index
     * @return \Illuminate\Support\Fluent
     */
    protected function dropIndexCommand($command, $type, $index)
    {
        $columns = [];

        // If the given "index" is actually an array of columns, the developer means
        // to drop an index merely by specifying the columns involved without the
        // conventional name, so we will build the index name from the columns.
        if (is_array($index)) {
            $index = $this->createIndexName($type, $columns = $index);
        }

        return $this->indexCommand($command, $columns, $index);
    }

    /**
     * Create a default index name for the table.
     *
     * @param  string  $type
     * @param  array  $columns
     * @return string
     */
    protected function createIndexName($type, array $columns)
    {
        $index = strtolower($this->prefix.$this->table.'_'.implode('_', $columns).'_'.$type);

        return str_replace(['-', '.'], '_', $index);
    }

    /**
     * Add a new column to the blueprint.
     *
     * @param  string  $type
     * @param  string  $name
     * @param  array  $parameters
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    public function addColumn($type, $name, array $parameters = [])
    {
        return $this->addColumnDefinition(new ColumnDefinition(
            array_merge(compact('type', 'name'), $parameters)
        ));
    }

    /**
     * Add a new column definition to the blueprint.
     *
     * @param  \CodingSocks\Reloquent\Schema\ColumnDefinition  $definition
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition
     */
    protected function addColumnDefinition($definition)
    {
        $this->columns[] = $definition;

        if ($this->after) {
            $definition->after($this->after);

            $this->after = $definition->name;
        }

        return $definition;
    }

    /**
     * Add the columns from the callback after the given column.
     *
     * @param  string  $column
     * @param  \Closure  $callback
     * @return void
     */
    public function after($column, Closure $callback)
    {
        $this->after = $column;

        $callback($this);

        $this->after = null;
    }

    /**
     * Remove a column from the schema blueprint.
     *
     * @param  string  $name
     * @return $this
     */
    public function removeColumn($name)
    {
        $this->columns = array_values(array_filter($this->columns, function ($c) use ($name) {
            return $c['name'] != $name;
        }));

        return $this;
    }

    /**
     * Add a new command to the blueprint.
     *
     * @param  string  $name
     * @param  array  $parameters
     * @return \Illuminate\Support\Fluent
     */
    protected function addCommand($name, array $parameters = [])
    {
        $this->commands[] = $command = $this->createCommand($name, $parameters);

        return $command;
    }

    /**
     * Create a new Fluent command.
     *
     * @param  string  $name
     * @param  array  $parameters
     * @return \Illuminate\Support\Fluent
     */
    protected function createCommand($name, array $parameters = [])
    {
        return new Fluent(array_merge(compact('name'), $parameters));
    }

    /**
     * Get the table the blueprint describes.
     *
     * @return string
     */
    public function getTable()
    {
        return $this->table;
    }

    /**
     * Get the columns on the blueprint.
     *
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition[]
     */
    public function getColumns()
    {
        return $this->columns;
    }

    /**
     * Get the commands on the blueprint.
     *
     * @return \Illuminate\Support\Fluent[]
     */
    public function getCommands()
    {
        return $this->commands;
    }

    /**
     * Get the columns on the blueprint that should be added.
     *
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition[]
     */
    public function getAddedColumns()
    {
        return array_filter($this->columns, function ($column) {
            return ! $column->change;
        });
    }

    /**
     * Get the columns on the blueprint that should be changed.
     *
     * @return \CodingSocks\Reloquent\Schema\ColumnDefinition[]
     */
    public function getChangedColumns()
    {
        return array_filter($this->columns, function ($column) {
            return (bool) $column->change;
        });
    }

    /**
     * Determine if the blueprint has auto-increment columns.
     *
     * @return bool
     */
    public function hasAutoIncrementColumn()
    {
        return ! is_null(collect($this->getAddedColumns())->first(function ($column) {
            return $column->autoIncrement === true;
        }));
    }

    /**
     * Get the auto-increment column starting values.
     *
     * @return array
     */
    public function autoIncrementingStartingValues()
    {
        if (! $this->hasAutoIncrementColumn()) {
            return [];
        }

        return collect($this->getAddedColumns())->mapWithKeys(function ($column) {
            return $column->autoIncrement === true
                        ? [$column->name => $column->get('startingValue', $column->get('from'))]
                        : [$column->name => null];
        })->filter()->all();
    }
}
