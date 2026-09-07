<?php

declare(strict_types=1);

/** @var Application $app */

use Vasoft\Joke\Application\Application;
use Vasoft\Joke\Http\HttpRequest;

$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->handle(HttpRequest::fromGlobals());
