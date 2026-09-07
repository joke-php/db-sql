<?php

declare(strict_types=1);

use Vasoft\Joke\Db\Sql\DatabaseProvider;
use Vasoft\Joke\Application\KernelConfig;

return new KernelConfig()
    ->addProvider(DatabaseProvider::class);
