# Установка и настройка

## Требования

- **PHP 8.5+** — используются возможности введенные с версии 8.5, на более ранних версиях пакет не запустится.
- **Расширения PHP** — Необходимы расширение PDO и в режиме разработки sqlite.
- **Фреймворк** — `voral/joke` ^1.5.

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
// config/database.php
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
    ->addConnection('default', static fn() => new Connection($connectionConfig))
    // Можно использовать свой класс, реализующий интерфейс соединения
    ->addConnection('second', CustomConnection::class)
    ->setDefaultConnectionName('default');

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
