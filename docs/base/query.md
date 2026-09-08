# Выполнение SQL запросов

Модуль дает возможность работать с базой на уровне непосредственно запросов (в дальнейшем планируется добавить
построитель запросов и ORM). После [настройки подключений](configure.md) можно сразу переходить к работе с базой
данных.

Для выполнения запросов необходимо использовать объект соединения реализующий интерфейс
`Vasoft\Joke\Db\Sql\Contract\ConnectionInterface` в данном модуле доступна реализация подключения через PDO
`Vasoft\Joke\Db\Sql\Driver\Pdo\Connection`. При необходимости возможно выполнять свои реализации об этом можно почитать
в статье [Реализация подключения](../dev/connection.md).

## Запрос не возвращающий данные

Этот тип используется когда необходим SQL запрос, который не возвращает набор данных (например: INSERT, UPDATE, CREATE и
т.п.). В результате возвращается количество затронутых данных.

Для выполнения таких запросов используется метод
`Vasoft\Joke\Db\Sql\Contract\ConnectionInterface::execute(string $sql, array $params = []): int;`.
Где:

- `sql` - строка SQL-запроса с плейсхолдерами
- `params` - ассоциативный массив параметров для подстановки в запрос

Метод возвращает количество затронутых строк или `false`.

Пример запросов:

```php
use Vasoft\Joke\Db\Sql\Contract\ConnectionInterface;
use Vasoft\Joke\Db\Sql\ConnectionManager;
use \Vasoft\Joke\Db\Sql\Exceptions\QueryException;

class UserRepository{
    private ConnectionInterface $connection; 
    public function __construct(ConnectionManager $manager) {
        $this->connection = $manager->connection();
    } 
    /**
    * Создает таблицу в базе данных
    * 
    * @throws QueryException
    */
    public function createTable():void{
        $this->connection->execute(<<<'SQL'
                    CREATE TABLE IF NOT EXISTS users (
                        id INTEGER PRIMARY KEY,
                        name VARCHAR(64) NOT NULL,
                        age INTEGER NOT NULL
                    )
                SQL);
    }
    /**
    * Удаляет пользователей по имени
    * 
    * @throws QueryException
    */
    public function remove(string $name): int {
        return $this->connection->execute('DELETE FROM users WHERE name = :name', ['name' => $name]);
    }
}
```

## Запрос возвращающий данные

Этот тип используется когда необходим SQL запрос, возвращающий набор данных (например: SELECT).

Для выполнения таки запросов используется метод
`Vasoft\Joke\Db\Sql\Contract\ConnectionInterface::query(string $sql, array $params = []): ResultInterface;`.
Где:

- `sql` - строка SQL-запроса с плейсхолдерами
- `params` - ассоциативный массив параметров для подстановки в запрос

Метод возвращает объект [результата выборки](result.md).

Пример запросов:

```php
use Vasoft\Joke\Db\Sql\Contract\ConnectionInterface;
use Vasoft\Joke\Db\Sql\Contract\ResultInterface;
use Vasoft\Joke\Db\Sql\ConnectionManager;
use Vasoft\Joke\Db\Sql\Exceptions\QueryException;

class UserRepository{
    private ConnectionInterface $connection; 
    public function __construct(ConnectionManager $manager) {
        $this->connection = $manager->connection();
    } 
    /**
    * Возвращает 10 записей из таблицы
    * 
    * @throws QueryException
    */
    public function all():ResultInterface {
        return $this->connection->query('SELECT id, name, age FROM users limit 10');
    }
    /**
    * Возвращает пользователей с именем содержащим заданную строку 
    * 
    * @throws QueryException
    */
    public function nameContain(string $name): ResultInterface {
        $value = '%' . $name '%';
        return $this->connection->query('SELECT id, name, age FROM users WHERE name LIKE :name', ['name' => $value]);
    }
}
```

---
Далее: [Результат выборки](result.md)
