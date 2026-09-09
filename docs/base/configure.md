# Настройка

После [установки и первичной настройки](installation.md) модуля можно сразу переходить к настройке подключений.

## Конфигурация подключения

Для конфигурации подключений необходимо использовать класс `Vasoft\Joke\Db\Sql\SqlConnectionConfig`.

### Параметры конструктора

Данный класс в конструктор получает следующие параметры:

- `driver` (обязательный) - строка, наименование драйвера подключения. При использовании реализованного в модуле
  драйвере PDO `Vasoft\Joke\Db\Sql\Driver\Pdo\Connection` варианты следующие: `sybase`, `mssql`, `dblib`, `cubrid`,
  `odbc`, `mysql`, `pgsql`, `sqlite`, `firebird`, `ibm`, `informix`, `oci`, `sqlsrv`. Для того чтобы не допустить
  опечаток можно использовать enum `Vasoft\Joke\Db\Sql\Driver\Pdo\Driver`
- `database` (обязательный) - имя базы данных
- `host` (не обязательный) - имя или адрес сервера
- `port` (не обязательный) - порт сервера
- `username` (не обязательный) - имя пользователя
- `password` (не обязательный) - пароль пользователя
- `specificOptions` (не обязательный) - опции зависящие от конкретного драйвера

### Дополнительные параметры

Кроме того есть возможность настроить политику поддержки транзакций. Выполнять это необходимо до
заморозки (конфигурация становится неизменяемой после первого обращения к менеджеру соединений) конфигурации системой.
Это выполняется при первом подключении (подключение, в свою очередь, выполняется при
первом запросе).

#### Политика поддержки транзакций

Не все СУБД одинаково поддерживают транзакции по этому можно управлять политикой поведения транзакций соединения.
Выполняется это следующим образом:

```php
use Vasoft\Joke\Db\Sql\TransactionSupportPolicy;
use Vasoft\Joke\Db\Sql\SqlConnectionConfig;
/** @var SqlConnectionConfig $config */
$config->setTransactionSupportPolicy(TransactionSupportPolicy::SILENCE);
// обращение к значению
if ($config->transactionSupportPolicy === TransactionSupportPolicy::THROW) {
    // ...
}
```

Существует два типа поведения

- `TransactionSupportPolicy::THROW` - поведение по умолчанию, выбрасывать исключение при попытке запустить, откатить или
  зафиксировать
  транзакцию
- `TransactionSupportPolicy::SILENCE` - игнорировать вызовы методов работы с транзакциями

### Пример конфигурации подключения

```php
use Vasoft\Joke\Db\Sql;

$config = new Sql\SqlConnectionConfig('mysql','example','user','pass')
    ->setTransactionSupportPolicy(Sql\TransactionSupportPolicy::SILENCE);
```

## Конфигурация модуля

Регистрировать необходимые приложению подключения необходимо в конфигурации модуля:

```php
// config/database.php
/** @var Environment $env */

declare(strict_types=1);

use Vasoft\Joke\Config\Environment;
use Vasoft\Joke\Db\Sql\SqlConnectionConfig;
use Vasoft\Joke\Db\Sql\DatabaseConfig;
use Vasoft\Joke\Db\Sql\Driver\Pdo\Connection;
use Vasoft\Joke\Support\FileSystem;

return new DatabaseConfig()
    ->addConnection(
        'default',
        static function (Environment $env, FileSystem $fs) {
            $databasePath = $fs->atBase($env->get('SQLITEDATABASE'));
            $connectionConfig = new SqlConnectionConfig('sqlite', $databasePath);

            return new Connection($connectionConfig);
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
    ->addConnection('memory', $connection);
```

Если этот метод не был вызван, то при заморозке конфигурации модуля системой основным соединением будет назначено первое
зарегистрированное. Тем не менее, для исключения неожиданного поведения, рекомендую всегда явно указывать имя соединения
по умолчанию.

---
Далее: [Выполнение запросов](query.md)
