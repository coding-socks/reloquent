<?php

namespace CodingSocks\Reloquent\Tests\Models;

use CodingSocks\Reloquent\Eloquent\Model;

/**
 * @property string $id
 * @property string $title
 * @property int $year
 * @property string[] $directors
 */
class Movie extends Model
{
    protected $schema = [
        'title' => ['type' => 'string', 'textSearch' => true],
        'year' => ['type' => 'number'],
        'directors' => ['type' => 'array'],
    ];
}
