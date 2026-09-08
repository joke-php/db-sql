# Работа с транзакциями

Одна из важных задач при работе с базами данных это обеспечение консистентного состояния данных. При этом возможна
ситуация, когда из двух связанных запросов мы выполнили первый успешно, а второй упал из-за ошибки - в результате в базе
сохранилось не корректное состояния. Для предотвращения таких ситуаций существует механизм транзакций.

Выполняемые в ходе работы приложения запросы можно заключать в транзакции при помощи методов объекта подключения
`\Vasoft\Joke\Db\Sql\ConnectionInterface`.

Для работы с транзакциями интерфейс имеет три основных метода

- `ConnectionInterface::beginTransaction(): void;` - старт транзакции
- `ConnectionInterface::commit(): void;` - фиксация транзакции
- `ConnectionInterface::rollBack(): void;` - откат транзакции. Отменяются результаты всех запросов выполнявшихся с
  момента старта транзакции.

Общий алгоритм работы выглядит следующим образом:

```php
use Vasoft\Joke\Db\Sql\Contract\ConnectionInterface;
/** @var ConnectionInterface $connection */

try {
    $connection->beginTransaction();
    $this->connection->execute('UPDATE accounts SET balance = balance - 100 WHERE id = 1;');
    $connection->commit();
} catch (\Throwable $exception) {
    $connection->rollBack();
    throw $exception;
}
```

Реализация этого интерфейса включенная в состав модуля `\Vasoft\Joke\Db\Driver\Pdo\Connection` поддерживает вложенные
транзакции за счет `savepoint`. Таким образом алгоритм может быть таким.

```php
use Vasoft\Joke\Db\Sql\Contract\ConnectionInterface;
/** @var ConnectionInterface $connection */

$connection->beginTransaction();
$this->connection->execute('UPDATE accounts SET balance = balance - 100 WHERE id = 1;');
$connection->beginTransaction();
$this->connection->execute('UPDATE other_accounts SET balance = balance + 100 WHERE account_id = 1;');
$connection->commit();
$connection->commit();
```

Для удобства работы можно воспользоваться методом оберткой, который выполняет запуск, фиксацию и, при ошибках, откат
транзакции. Метод возвращает результат работы функции переданной в качестве параметра.

```php
use Vasoft\Joke\Db\Sql\Contract\ConnectionInterface;
/** @var ConnectionInterface $connection */

function foo(): int {
    return 10;
};
$result1 = $connection->transaction(foo(...));
$result2 = $connection->transaction(static fn(): int => $connection->execute(
            'INSERT INTO users (name, age) VALUES (:name, :age)',
            ['name' => 'Unknown', 'age' => 123],
        ));
```

В то же время не все СУБД и драйверы подключений могут поддерживать транзакции. По умолчанию, при попытке работать с
транзакцией объект подключения выбрасывает исключение. Однако можно настроить поведение при котором обращение к методам
транзакций будет проигнорировано. Для этого необходимо произвести настройку подключения при помощи метода
`\Vasoft\Joke\Db\Sql\SqlConnectionConfig::setTransactionSupportPolicy(TransactionSupportPolicy $transactionSupportPolicy): static`.

Например:

```php
// config/database.php
/** @var Environment $env */

declare(strict_types=1);

use Vasoft\Joke\Db\Sql\SqlConnectionConfig;
use Vasoft\Joke\Db\Sql\DatabaseConfig;
use Vasoft\Joke\Db\Sql\Driver\Pdo\Connection;
use Vasoft\Joke\Db\Sql\TransactionSupportPolicy;

return new DatabaseConfig()
    ->addConnection(
        'default',
        static function () {
            $connectionConfig = new SqlConnectionConfig('sqlite', $databasePath);
            $config = new SqlConnectionConfig('mysql','example','user','pass')
                ->setTransactionSupportPolicy(TransactionSupportPolicy::SILENCE);

            return new Connection($connectionConfig);
        },
    );
```