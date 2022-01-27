<?php

namespace CodingSocks\Reloquent\Schema;

use Illuminate\Support\Fluent;

/**
 * @method $this textSearch() Enables full text search. (string)
 * @method $this noStem() Disable stemming when indexing. (text)
 * @method $this weight(float $weight) Declares the importance of a text when calculating result accuracy. (text)
 * @method $this phonetic($matcher) Enables phonetic matching. (text)
 * @method $this separator($separator = '|') Indicates how the text is to be split into individual tags. (tag)
 * @method $this caseSensitive() Keeps the original letter cases. (tag)
 * @method $this sortable($normalization = true) Enables sorting the results by the value. (text/tag/numeric)
 * @method $this noIndex() Disables indexing. (text/tag/numeric)
 */
class ColumnDefinition extends Fluent
{
    //
}
