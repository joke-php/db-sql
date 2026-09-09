# Работа с транзакциями

Одна из важных задач при работе с базами данных это обеспечение консистентного состояния данных. При этом возможна
ситуация, когда из двух связанных запросов мы выполнили первый успешно, а второй упал из-за ошибки - в результате в базе
сохранилось некорректное состояние. Для предотвращения таких ситуаций существует механизм транзакций.

Выполняемые в ходе работы приложения запросы можно заключать в транзакции при помощи методов объекта подключения
`\Vasoft\Joke\Db\Sql\ConnectionInterface`.

Для работы с транзакциями интерфейс имеет три основных метода

- `ConnectionInterface::beginTransaction(): void;` - старт транзакции
- `ConnectionInterface::commit(): void;` - фиксация транзакции
- `ConnectionInterface::rollBack(): void;` - отмена транзакции. Отменяет результаты всех запросов, выполненных с момента
  старта транзакции.

Общий алгоритм работы выглядит следующим образом:

```php
use Vasoft\Joke\Db\Sql\Contract\ConnectionInterface;
/** @var ConnectionInterface $connection */

try {
    $connection->beginTransaction();
    $connection->execute('UPDATE accounts SET balance = balance - 100 WHERE id = 1;');
    $connection->commit();
} catch (\Throwable $exception) {
    $connection->rollBack();
    throw $exception;
}
```

Реализация `\Vasoft\Joke\Db\Sql\Driver\Pdo\Connection` поддерживает вложенные транзакции с использованием механизма
`SAVEPOINT`. Это позволяет вкладывать вызовы `beginTransaction()` друг в друга.

```php
use Vasoft\Joke\Db\Sql\Contract\ConnectionInterface;
/** @var ConnectionInterface $connection */

$connection->beginTransaction();
$connection->execute('UPDATE accounts SET balance = balance - 100 WHERE id = 1;');
$connection->beginTransaction();
$connection->execute('UPDATE other_accounts SET balance = balance + 100 WHERE account_id = 1;');
$connection->commit();
$connection->commit();
```

Для удобства работы можно воспользоваться методом-оберткой, который выполняет запуск, фиксацию и, при ошибках, откат
транзакции. Метод возвращает значение любого типа (mixed), которое вернула переданная функция.

```php
use Vasoft\Joke\Db\Sql\Contract\ConnectionInterface;
/** @var ConnectionInterface $connection */

function foo(): int {
    return 10;
}
$result1 = $connection->transaction(foo(...));
$result2 = $connection->transaction(static fn(): int => $connection->execute(
            'INSERT INTO users (name, age) VALUES (:name, :age)',
            ['name' => 'Unknown', 'age' => 123],
        ));
```

В то же время не все СУБД и драйверы подключений могут поддерживать транзакции. Предлагаемая модулем реализация
подключения `Vasoft\Joke\Db\Sql\Driver\Pdo\Connection` по умолчанию, при попытке работать с транзакцией выбрасывает
исключение если транзакции не поддерживаются. Однако можно настроить поведение при котором обращение к методам
транзакций будет проигнорировано. Для этого необходимо произвести настройку подключения при помощи метода
`Connection::setTransactionSupportPolicy(TransactionSupportPolicy $transactionSupportPolicy): static`.

Настройка поведения при отсутствии поддержки транзакций (политики REQUIRED и OPTIONAL) подробно описана в
разделе [Конфигурирование подключений](configure.md).

---
Далее: [Логирование запросов](../dev/logging.md) 
