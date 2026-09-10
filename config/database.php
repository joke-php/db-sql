<?php

/** @var Environment $env */

declare(strict_types=1);

use Vasoft\Joke\Config\Environment;
use Vasoft\Joke\Db\Sql\DatabaseConfig;
use Vasoft\Joke\Db\Sql\Demo\Driver\JsonConnection;
use Vasoft\Joke\Db\Sql\Driver\Pdo\Connection;
use Vasoft\Joke\Support\FileSystem;

return new DatabaseConfig()
    ->addConnection(
        'default',
        static function (Environment $env, FileSystem $fs) {
            $databasePath = $fs->atBase($env->get('SQLITEDATABASE'));

            return new Connection('sqlite', $databasePath);
        },
    )
    ->addConnection('json', JsonConnection::class);
