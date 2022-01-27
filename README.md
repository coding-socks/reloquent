# Reloquent

Redis + Eloquent = Reloquent

## Introduction

Reloquent is an **experimental object-relational mapper** (ORM) that makes it enjoyable to interact with Redis. In addition to managing Redis hashes, Reloquent models allow you to search records with RediSearch.

This library is heavily inspired by [Redis OM][redis-om-url].

Define a model:

```php
use CodingSocks\Reloquent\Model;

class Movie extends Model
{
    protected $schema = [
        'title' => ['type' => 'string', 'textSearch' => true],
        'year' => ['type' => 'number'],
        'directors' => ['type' => 'array'],
    ];
}
```

Create a new model and save it:

```php
$movie = new Movie()
$movie->title = "Alice in Wonderland";
$movie->year = 1951;
$movie->directors = ['Clyde Geronimi', 'Wilfred Jackson', 'Hamilton Luske'];
$movie->save()
```

Search for models:

```php
Movie::query()
    ->where('title', 'matches', ['Alice', 'Wonderland'])
    ->where('year', '<=', 2000)
    ->where('directors', 'contains', ['Clyde Geronimi', 'Hamilton Luske'])
    ->get();
```

## Getting Started

First things first, get yourself a Laravel project.

    composer create-project laravel/laravel example-app

Once you have a `composer.json`, add our package to it:

    composer require coding-socks/reloquent

You'll need Redis, preferably with [RediSearch][redisearch-url]. The easiest way to do this is to set up a free [Redis Cloud][redis-cloud-url] instance. But, you can also use Docker:

    docker run -p 6379:6379 --name reloquent redislabs/redismod:preview

## Configure Redis as a database

Open `config/database.php` and add a Redis driver based connection.

```php
    // [...]

    'connections' => [

        // [...]

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'data_structure' => 'hash',
        ],

        // [...]

    ],
    
    // [...]
```

Everything else will come from your redis configuration.

## Create, Fetch, Update, and Delete a model

A model only needs a schema when RediSearch is available to manage indexes. When only HASH or JSON data types are managed then it's not necessary.

```php
<?php

namespace App\Models;

use CodingSocks\Reloquent\Model;

class Movie extends Model
{
}
```

Once it's done we can already do some simple query.

```php
<?php

use App\Models\Movie;

// Create

$movie = new Movie();
$movie->title = "Matrix";
$movie->year = 1999;
$movie->directors = ['Lana Wachowski', 'Lilly Wachowski'];
$movie->save();

// Fetch

$movie = Movie::find('01FTDC7A39ZGTCNH2D3DN5RPKR');

// Update

$movie = Movie::find('01FTDC7A39ZGTCNH2D3DN5RPKR');
$movie->title = "The Matrix";
$movie->save();

// Delete

$movie = Movie::find('01FTDC7A39ZGTCNH2D3DN5RPKR');
$movie->delete()
```

## Using RediSearch

### Querying a model

When schema is not defined Reloquent will try to guess the type of the field from the type of the query value. In most cases it is better to define a schema for each model.

```php
<?php

namespace App\Models;

use CodingSocks\Reloquent\Model;

class Movie extends Model
{
    protected $schema = [
    'title' => ['type' => 'string', 'textSearch' => true],
    'year' => ['type' => 'number'],
    'directors' => ['type' => 'array'],
];
}
```

Migrations define the index of your models. It is managed separately to be able to change it separately:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMoviesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('movies', function (Blueprint $table) {
            $table->string('title')->textSearch();
            $table->integer('year');
            $table->array('directors');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('movies');
    }
}
```

After running a migration we can query the newly created index.

```php
Movie::query()
    ->where('title', 'matches', ['Alice', 'Wonderland'])
    ->where('year', '<=', 2000)
    ->where('directors', 'contains', ['Clyde Geronimi', 'Hamilton Luske'])
    ->get();
```

### Pagination

Not supported yet.

### Counting

Not supported yet.

## Using RedisJSON

Not supported yet.

## Caveats

### Config

`prefix` inside Redis config is ignored.

### PhpRedis

`\Redis::OPT_REPLY_LITERAL` is set to true for PhpRedis which disables the basic string to boolean value transformation for `rawCommand`. If you use `rawCommand` in your application be aware of this.

### Missing records

Redis, and by extension Reloquent, doesn't differentiate between missing and null. Missing fields in Redis are returned as `null`, and missing keys return `null`. So, if you fetch an entity that doesn't exist, it will happily return you an entity full of nulls:

```php
$movie = Movie::find('DOES_NOT_EXIST');
$movie->title; // null
$movie->year; // null
$movie->directors; // null

$exists = Movie::exists($movie->id) // false
```

It does this because Redis doesn't distinguish between missing and null. You could have an entity that is all nulls. Or you could not. Redis doesn't know which is your intention, and so always returns *something* when you call `find`.

### Ordering

Only the latest `orderBy` is taken into account because of a RediSearch limitation. There is already [an issue open](https://github.com/RediSearch/RediSearch/issues/1615) which asks for multiple sort.

## Production readiness

This project is still in alpha phase. In this stage the public API can change multiple times a day.

Beta version will be considered when the feature set covers most of the eloquent methods.

## Contribution

Any type of contribution is welcome; from features, bug fixes, documentation improvements, feedbacks, questions. While GitHub uses the word "issue" feel free to open up a GitHub issue for any of these.

[redis-om-url]: https://redis.com/blog/introducing-redis-om-client-libraries/

[redis-cloud-url]: https://redis.com/try-free/
[redisearch-url]: https://oss.redis.com/redisearch/
