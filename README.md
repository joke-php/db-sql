# Joke DB SQL

---

**В разработке**

---

Библиотека предоставляет базовые возможности взаимодействия с реляционными базами данных, необходимые интерфейсы, а
также реализацию на базе PDO. Поддерживаются драйверы: `dblib`, `cubrid`, `odbc`, `mysql`,
`pgsql`, `sqlite`, `firebird`, `ibm`, `informix`, `oci`, `sqlsrv`.

Модуль предполагает три уровня взаимодействия с базами данных:

- **Низкий уровень (реализовано)** — работа с сырыми SQL-запросами.
- **Построитель запросов (планируется)** — программное построение SQL-запросов.
- **ORM (планируется)** — работа с объектами и связями между ними с максимальной абстракцией от SQL.

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

И настройте подключения:

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
        'main',
        static function (Environment $env, FileSystem $fs) {
            $databasePath = $fs->atBase($env->get('SQLITEDATABASE'));

            return new Connection('sqlite', $databasePath);
        },
    )
    ->setDefaultConnectionName('main');

```

## Использование в приложении

```php
use Vasoft\Joke\Db\Sql\Contract\ConnectionInterface;
use Vasoft\Joke\Db\Sql\Contract\ResultInterface;
use Vasoft\Joke\Db\Sql\ConnectionManager;
use Vasoft\Joke\Db\Sql\Exceptions\QueryException;

class UserRepository {
    private ConnectionInterface $connection; 
    public function __construct(ConnectionManager $manager) {
        $this->connection = $manager->connection();
    } 
    /**
    * Возвращает пользователей с именем содержащим заданную строку 
    * 
    * @throws QueryException
    */
    public function nameContain(string $name): ResultInterface {
        $value = '%' . $name . '%';
        return $this->connection->query('SELECT id, name, age FROM users WHERE name LIKE :name', ['name' => $value]);
    }
}

```

## В планах

- [ ] Построитель запросов
- [ ] ORM
- [ ] абстрактный класс логера реализующий основной функционал, но оставляющий на ответственность разработчика
  подготовку запроса и данных перед записью в лог 
