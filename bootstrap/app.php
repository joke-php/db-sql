<?php

declare(strict_types=1);

use Vasoft\Joke\Application\Application;
use Vasoft\Joke\Container\ServiceContainer;

require __DIR__ . '/../vendor/autoload.php';


session_set_cookie_params([
    'samesite' => 'Lax',
    'secure' => $_SERVER['HTTPS'] ?? false,
    'httponly' => true,
    'lifetime' => 3600 * 24 * 7,
    'path' => '/',
    'domain' => '',
]);

return new Application(dirname(__DIR__), new ServiceContainer());
