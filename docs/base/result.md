# Результат выполнения SQL запросов

Метод `ConnectionInterface::query()` подключения при [выполнении запроса](query.md) возвращает объект результата
реализующий `Vasoft\Joke\Db\Sql\Contract\ResultInterface`, который предоставляет доступ к данным полученным в результате
запроса.

В модуле есть реализация для драйверов PDO `Vasoft\Joke\Db\Sql\Driver\Pdo\Result`. Эта реализация использует
небуферизированный режим работы. Данные считываются по мере обращения к ним (потоково), поэтому повторная итерация по
результату невозможна без повторного выполнения запроса.

Интерфейс расширяет `\IteratorAggregate` и `\Countable`, благодаря чему объект результата можно использовать в циклах
foreach и получать количество строк через функцию count().

```php
use Vasoft\Joke\Db\Sql\Driver\Pdo\Connection;

/** @var Connection $connection */

$res = $connection->query('SELECT name, age FROM users');
foreach ($res as $row) {
    echo $row['name'], ':', $row['age'], PHP_EOL;
}
```

Кроме того интерфейс определяет методы:

- `ResultInterface::all(): array` - возвращает все строки результата в виде массива. Каждая строка представлена как
  ассоциативный массив, где ключами являются имена столбцов.
- `ResultInterface::fetch(): false|array` - возвращает следующую строку результата в виде ассоциативного массива или
  `false`, если результат пуст.

```php
use Vasoft\Joke\Db\Sql\Driver\Pdo\Connection;

/** @var Connection $connection */

// Получение всех записей сразу
$res = $connection->query('SELECT name, age FROM users');
$users = $res->all();

// Построчное чтение
$res = $connection->query('SELECT name, age FROM users');
while ($row = $res->fetch()) {
    echo $row['name'];
}
```

Т.к. интерфейс расширяет `\Countable` то результат можно подставлять в функцию `\count()`:

```php
use Vasoft\Joke\Db\Sql\Driver\Pdo\Connection;

/** @var Connection $connection */

// Получение всех записей сразу
$res = $connection->query('SELECT name, age FROM users');
echo count($res);
```

Обратите внимание, что в реализации `Vasoft\Joke\Db\Sql\Driver\Pdo\Result` вычитываются все строки из курсора для
подсчета. Используйте только для небольших результатов или когда данные всё равно будут использованы. После вызова этого
метода курсор считается исчерпанным.

При реализации своего драйвера подключения вы так же можете [создать свою реализацию результата](../dev/result.md).

---
Далее: [Работа с транзакциями](transaction.md)

