<?php

namespace CodingSocks\Reloquent;

use CodingSocks\Reloquent\Migrations\DatabaseMigrationRepository;
use Illuminate\Support\ServiceProvider;
use CodingSocks\Reloquent\Eloquent\Model;

class ReloquentServiceProvider extends ServiceProvider
{
    /**
     * Boot any application services.
     *
     * @return void
     */
    public function boot()
    {
        Model::setConnectionResolver($this->app['db']);

        Model::setEventDispatcher($this->app['events']);
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        Model::clearBootedModels();

        $this->registerMigrationRepository();

        $this->registerDatabaseDriver();

    }

    /**
     * Register the migration repository service.
     *
     * @return void
     */
    protected function registerMigrationRepository()
    {
        $this->app->extend('migration.repository', function ($originalRepository, $app) {
            if ($app['config']['database.default'] !== 'redis') {
                return $originalRepository;
            }

            $table = $app['config']['database.migrations'];

            return new DatabaseMigrationRepository($app['db'], $table);
        });
    }

    /**
     * Register the database driver.
     *
     * @return void
     */
    protected function registerDatabaseDriver()
    {
        $this->app->resolving('db', function ($db) {
            $db->extend('redis', function ($config, $name) {
                $config['name'] = $name;
                $redis = $this->app->make('redis');

                // PhpRedis by default treats simple string results as `true`. We have to set
                // an option which changes this for `rawCommand` and `EVAL`.
                // See: https://github.com/phpredis/phpredis/issues/1550
                $client = $this->app->make('config')->get('database.redis.client');
                if ($client === 'phpredis') {
                    $redis->client()->setOption(\Redis::OPT_REPLY_LITERAL, true);
                }

                return new Connection($redis, $config);
            });
        });
    }

}
