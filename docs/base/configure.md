# Конфигурирование подключений и модуля

После [установки и первичной настройки](installation.md) модуля можно сразу переходить к настройке подключений.

## Конфигурация подключения

Конфигурирование подключения определяется конкретной реализацией контракта
`Vasoft\Joke\Db\Sql\Contract\ConnectionInterface`.

В модуль входит реализация для драйверов PDO `Vasoft\Joke\Db\Sql\Driver\Pdo\Connection`. В этом модуле основные
параметры передаются через конструктор

### Параметры конструктора

Данный класс в конструктор получает следующие параметры:

- `driver` (обязательный) - строка, наименование драйвера подключения. При использовании реализованного в модуле
  драйвере PDO `Vasoft\Joke\Db\Sql\Driver\Pdo\Connection` варианты следующие: `dblib`, `cubrid`, `odbc`, `mysql`,
  `pgsql`, `sqlite`, `firebird`, `ibm`, `informix`, `oci`, `sqlsrv`. Для того чтобы не допустить опечаток можно
  использовать enum `Vasoft\Joke\Db\Sql\Driver\Pdo\Driver`
- `database` (обязательный) - имя базы данных
- `host` (не обязательный) - имя или адрес сервера
- `port` (не обязательный) - порт сервера
- `username` (не обязательный) - имя пользователя
- `password` (не обязательный) - пароль пользователя
- `specificOptions` (не обязательный) - опции зависящие от конкретного драйвера

### Дополнительные параметры

Кроме того есть возможность настроить политику действий для подключений не поддерживающих транзакции.
Выполняется это следующим образом:

```php
// config/database.php
<?php

/** @var Environment $env */

declare(strict_types=1);

use Vasoft\Joke\Config\Environment;
use Vasoft\Joke\Db\Sql\DatabaseConfig;
use Vasoft\Joke\Db\Sql\Driver\Pdo\Connection;
use Vasoft\Joke\Db\Sql\TransactionSupportPolicy;

$databasePath = dirname(__DIR__) . '/' . $env->get('SQLITEDATABASE');

// Пример: подключение к legacy MySQL базе на движке MyISAM,
// который не поддерживает транзакции. Политика OPTIONAL позволяет
// использовать единый код с транзакциями без ошибок.
return new DatabaseConfig()
    ->addConnection('legacy', static fn() => new Connection(
        driver: 'mysql',
        database: 'legacy_db',
        host: 'localhost',
        username: 'root',
        password: '',
    )->setTransactionSupportPolicy(TransactionSupportPolicy::OPTIONAL))
    ->setDefaultConnectionName('legacy');

```

Для подключений поддерживающих транзакции (это определяется автоматически), настройка не имеет значения. Она играет роль
только для подключений, не поддерживающих транзакции.

Существует два типа поведения

- `TransactionSupportPolicy::REQUIRED` - поведение по умолчанию, выбрасывать исключение при попытке запустить, откатить
  или зафиксировать транзакцию
- `TransactionSupportPolicy::OPTIONAL` - запросы будут выполняться не вызывая исключений. Эту политику необходимо применять осторожно и обоснованно, т.к. в случае ошибок откатов транзакций фактически не будет и есть возможность получить неконсистентное состояние базы данных. 

## Конфигурация модуля

Регистрировать необходимые приложению подключения необходимо в конфигурации модуля:

```php
// config/database.php
/** @var Environment $env */

declare(strict_types=1);

use Vasoft\Joke\Config\Environment;
use Vasoft\Joke\Db\Sql\DatabaseConfig;
use Vasoft\Joke\Db\Sql\Driver\Pdo\Connection;
use Vasoft\Joke\Support\FileSystem;

return new DatabaseConfig()
    ->addConnection(
        'default',
        static function (Environment $env, FileSystem $fs) {
            $databasePath = $fs->atBase($env->get('SQLITEDATABASE'));

            return new Connection('sqlite', $databasePath);
        },
    );
```

> При передаче готового объекта соединение создается сразу при загрузке конфигурации. При использовании closure (
> анонимной функции) соединение создается лениво — только при первом обращении к нему, что экономит ресурсы.

Метод addConnection можно вызывать даже после инициализации модуля. Он перезапишет существующее подключение с таким же
именем. Важно: единственное исключение — нельзя перезаписать подключение, которое уже назначено как подключение по
умолчанию, если конфигурация модуля уже была заморожена.

Подключение по умолчанию задается специальным методом конфигурации модуля. Его необходимо вызывать уже после регистрации
самого подключения:

```php
// config/database.php
/** @var Environment $env */

declare(strict_types=1);

use Vasoft\Joke\Config\Environment;
use Vasoft\Joke\Db\Sql\DatabaseConfig;
use Vasoft\Joke\Db\Sql\Driver\Pdo\Connection;
use Vasoft\Joke\Support\FileSystem;

$connection = new Connection('sqlite', ':memory:');

return new DatabaseConfig()
    ->addConnection('memory', $connection)
    ->setDefaultConnectionName('memory');
```

Если этот метод не был вызван, то при заморозке конфигурации модуля системой основным соединением будет назначено первое
зарегистрированное. Тем не менее, для исключения неожиданного поведения, рекомендую всегда явно указывать имя соединения
по умолчанию.

---
Далее: [Выполнение запросов](query.md)
