<?php

namespace CodingSocks\Reloquent\Tests;

use CodingSocks\Reloquent\Tests\Models\Movie;

class ModelPhpRedisTest extends ModelTestTemplate
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('database.redis.client', 'phpredis');
    }
}
