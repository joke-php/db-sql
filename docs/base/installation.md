# Установка и настройка

## Требования

- **PHP 8.5+** — используются возможности введенные с версии 8.5, на более ранних версиях пакет не запустится.
- **Расширения PHP** — обязательно `pdo`, а также `pdo_sqlite3` для режима разработки.
- **Фреймворк** — `voral/joke` ^1.6.

## Установка

```bash
composer require joke-php/db-sql
```

Затем зарегистрируйте `DatabaseProvider` в `bootstrap/kernel.php`:

```php
<?php

declare(strict_types=1);

use Vasoft\Joke\Db\Sql\DatabaseProvider;
use Vasoft\Joke\Application\KernelConfig;

return new KernelConfig()
    ->addProvider(DatabaseProvider::class);
```

Провайдер регистрирует в контейнере менеджер соединений `ConnectionManager`.

## Базовая конфигурация

В конфигурации модуля регистрируйте необходимые подключения

```php
<?php
// config/database.php

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

```

## Как использовать

В приложении к подключению можно обращаться при помощи менеджера соединений

```php
/**
 * @var \Vasoft\Joke\Container\ServiceContainer $container
 * @var \Vasoft\Joke\Db\Sql\ConnectionManager $manager 
 */
$manager = $container->get(ConnectionManager::class);
// запрос соединения по умолчанию
$connection = $manager->connection();
// запрос соединения по имени
$connection = $manager->connection('default');

```

---
Далее [Настройки подключения и модуля](configure.md).
