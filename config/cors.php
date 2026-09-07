<?php

declare(strict_types=1);

use Vasoft\Joke\Http\Cors\CorsConfig;

return $corsConfig = new CorsConfig()
    ->setAllowedCors(true)
    ->setOrigins(['*'])
    ->setAllowCredentials(false);
