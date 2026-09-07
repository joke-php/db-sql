<?php

declare(strict_types=1);

use Vasoft\Joke\Db\Sql\Demo\DemoController;
use Vasoft\Joke\Routing\Router;

/**
 * @var Router $router
 */
$router->get('/', [DemoController::class, 'index']);
$router->post('/', [DemoController::class, 'add']);
