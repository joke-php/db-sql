<?php

/** @var Environment $env */

declare(strict_types=1);

use Vasoft\Joke\Config\Environment;
use Vasoft\Joke\Db\Sql\SqlConnectionConfig;
use Vasoft\Joke\Db\Sql\DatabaseConfig;
use Vasoft\Joke\Db\Sql\Driver\Pdo\Connection;

$databasePath = dirname(__DIR__) . '/' . $env->get('SQLITEDATABASE');
$connectionConfig = new SqlConnectionConfig('sqlite', $databasePath);

return new DatabaseConfig()
    ->addConnection('default', static fn() => new Connection($connectionConfig));
