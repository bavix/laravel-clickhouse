<?php

declare(strict_types=1);

namespace Bavix\LaravelClickHouse;

use Bavix\LaravelClickHouse\Database\Connection;
use Bavix\LaravelClickHouse\Database\Eloquent\Model;
use Bavix\LaravelClickHouse\Database\Query\Pdo;
use Bavix\LaravelClickHouse\Database\Query\PdoInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;

class ClickHouseServiceProvider extends ServiceProvider
{
    /**
     * @throws
     */
    public function boot(): void
    {
        Model::setConnectionResolver($this->app['db']);
        Model::setEventDispatcher($this->app['events']);
    }

    public function register(): void
    {
        $this->app->singleton(PdoInterface::class, Pdo::class);
        $this->app->resolving('db', static function (DatabaseManager $db) {
            $db->extend('bavix::clickhouse', static function ($config, $name) {
                return new Connection(\array_merge($config, [
                    'name' => $name,
                ]));
            });
        });
    }
}
