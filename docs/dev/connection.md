# Собственные подключения

Для создания собственного драйвера подключения необходимо реализовать интерфейс
`Vasoft\Joke\Db\Sql\Contract\ConnectionInterface`. Так же может быть полезным создать
соответсвующую [реализацию результата запроса](result.md).

## Методы которые должны быть реализованы

- Метод `query(string $sql, array $params = []): ResultInterface;` - который должен выполнять запросы возвращающие
  данные (SELECT). В качестве параметров должен получать SQL-запрос с плейсхолдерами и ассоциативный массив параметров,
  где ключи — это имена плейсхолдеров, а значения — подставляемые данные. Должен возвращать объект результата запроса
  реализующий
  `Vasoft\Joke\Db\Sql\Contract\ResultInterface` (см [Реализация результата](result.md)). В случае ошибок метод должен
  бросать исключение типа `Vasoft\Joke\Db\Sql\Exceptions\QueryException`.
- Метод `execute(string $sql, array $params = []): int;` - который должен выполнять запросы-действия не возвращающие
  данные (INSERT/UPDATE/DELETE/DDL-запросы). В качестве параметров должен получать SQL-запрос с плейсхолдерами и
  ассоциативный массив параметров, где ключи — это имена плейсхолдеров, а значения — подставляемые данные. Метод должен
  возвращать количество
  затронутых строк. В случае ошибок метод должен бросать исключение типа `Vasoft\Joke\Db\Sql\Exceptions\QueryException`.
- Метод `public function beginTransaction(): void;` - метод должен запускать транзакцию или вызывать исключение
  `Vasoft\Joke\Db\Sql\Exceptions\TransactionException` в случае ошибок. В случае успешного запуска транзакции должен
  увеличивать счетчик уровня транзакции.
- Метод `public function commit(): void;` - метод должен фиксировать текущую транзакцию или вызывать исключение
  `Vasoft\Joke\Db\Sql\Exceptions\TransactionException` в случае ошибок. В случае успешного фиксирования - уменьшать
  счетчик уровня транзакции.
- Метод `public function rollBack(): void;` - метод должен отменять текущую транзакцию или вызывать исключение
  `Vasoft\Joke\Db\Sql\Exceptions\TransactionException` в случае ошибок. В случае успешной отмены - уменьшать счетчик
  уровня транзакции.
- Метод `public function transaction(callable $callback): mixed;` - Должен выполнять полученное замыкание внутри
  транзакции с автоматическим commit/rollback. В случае ошибок выбрасывать исключения
  `Vasoft\Joke\Db\Sql\Exceptions\QueryException`,`Vasoft\Joke\Db\Sql\Exceptions\TransactionException`, а исключения,
  возникшие внутри замыкания, должны пробрасываться наружу без изменений после выполнения отката транзакции.
- Метод `public function isConnected(): bool;` - Проверяет, установлено ли физическое соединение с БД. Возвращает false,
  если соединение ещё не было установлено или было закрыто через disconnect().
- Метод `public function disconnect(): void;` - Закрывает соединение с БД и сбрасывает состояние транзакций.

## Свойства

- `transactionLevel` - публичное свойство доступное только для чтения содержащее текущий уровень вложенности транзакций.

## Пример реализации

Упрощенный демонстрационный драйвер для работы с JSON-файлом. В примере используется класс `Result` пример создания,
которого показан в статье [Реализация результата](result.md).

```php
<?php

declare(strict_types=1);

namespace App;

use Vasoft\Joke\Db\Sql\Contract\ConnectionInterface;
use Vasoft\Joke\Db\Sql\Contract\ResultInterface;
use Vasoft\Joke\Db\Sql\Exceptions\QueryException;
use Vasoft\Joke\Db\Sql\Exceptions\TransactionException;
use Vasoft\Joke\Support\FileSystem;

/**
 * Особенности реализации:
 * - Хранение данных осуществляется в JSON-файле через {@see FileSystem}.
 * - Транзакции эмулируются через создание снимка состояния (snapshot) хранилища перед началом операции.
 * - Поддерживается упрощенный парсинг SQL-подобных запросов:
 *   - SELECT * FROM <collection>
 *   - INSERT INTO <collection> (...) VALUES (...)
 *   - CREATE TABLE <collection> (...)
 */
class JConnection implements ConnectionInterface
{
    /**
     * Текущий уровень вложенности транзакций.
     * В данной реализации поддерживается только один уровень (0 или 1).
     */
     public private(set) int $transactionLevel = 0 {
        get {
            return $this->transactionLevel;
        }
    }
    
    /**
     *  Основное хранилище данных.
     *  Ключ — имя коллекции (таблицы), значение — массив записей.
     *
     * @var array<string, list<array<string, mixed>>>
     */
    protected array $store = [];

    /**
     *  Снимок состояния хранилища для обеспечения отката транзакций.
     *
     *  null означает, что активная транзакция отсутствует.
     *
     * @var array<string, null|list<array<string, mixed>>>
     */
    private ?array $transactionSnapshot = null;
    /**
     * Путь к файлу хранения данных.
     * Пустая строка означает, что соединение еще не инициализировано.
     */
    private string $file = '';

    /**
     * @param FileSystem $fs сервис для работы с файловой системой
     */
    public function __construct(private readonly FileSystem $fs) {}

    /**
     * Выполняет запрос на выборку данных.
     *
     * Поддерживает только синтаксис: SELECT * FROM <table_name>.
     *
     * @param string $sql    SQL-запрос
     * @param array  $params параметры запроса (в данной реализации игнорируются)
     *
     * @return ResultInterface результат выполнения запроса
     *
     * @throws QueryException если формат запроса не соответствует поддерживаемому
     */
    public function query(string $sql, array $params = []): ResultInterface
    {
        $this->ensureConnection();

        if (!preg_match('/^\s*SELECT\s+\*\s+FROM\s+(\w+)\s*$/i', trim($sql), $matches)) {
            throw new QueryException(
                "JsonConnection поддерживает только запросы вида 'SELECT * FROM <collection>'. Получен: {$sql}",
            );
        }

        $collection = strtolower($matches[1]);
        $data = $this->store[$collection] ?? [];

        return new Result($data);
    }

    /**
     * Выполняет SQL-запросы, изменяющие данные или структуру.
     *
     * Поддерживаемые операции:
     * - CREATE TABLE table_name (column1, column2, ...) — создает пустую коллекцию.
     * - INSERT INTO table_name (col1, col2) VALUES (value1, value2) — добавляет запись.
     *
     * @param string $sql    SQL-запрос
     * @param array  $params параметры запроса (в данной реализации игнорируются)
     *
     * @return int количество затронутых строк
     *
     * @throws QueryException если запрос не поддерживается или нарушена целостность данных
     */
    public function execute(string $sql, array $params = []): int
    {
        $this->ensureConnection();

        $trimmedSql = trim($sql);

        // Обработка CREATE TABLE
        if (preg_match('/^\s*CREATE\s+TABLE\s+(\w+)\s*\((.+?)\)\s*$/is', $trimmedSql, $matches)) {
            $tableName = strtolower($matches[1]);

            if (isset($this->store[$tableName])) {
                return 0;
            }

            $this->store[$tableName] = [];

            return 0;
        }

        // Обработка INSERT INTO
        if (preg_match('/^\s*INSERT\s+INTO\s+(\w+)\s*\(([^)]+)\)\s+VALUES\s*\((.+?)\)\s*$/is', $trimmedSql, $matches)) {
            $tableName = strtolower($matches[1]);
            $columnsStr = $matches[2];
            $valuesStr = $matches[3];

            $columns = array_map('trim', explode(',', $columnsStr));
            $values = $this->parseValues($valuesStr);

            if (count($columns) !== count($values)) {
                throw new QueryException(
                    'Количество колонок (' . count($columns) . ') не совпадает с количеством значений (' . count(
                        $values,
                    ) . ').',
                );
            }

            if (!isset($this->store[$tableName])) {
                throw new QueryException("Таблица '{$tableName}' не существует.");
            }

            $record = [];
            foreach ($columns as $index => $column) {
                $record[$column] = $values[$index];
            }

            $this->store[$tableName][] = $record;

            return 1;
        }

        throw new QueryException(
            "Неподдерживаемый SQL-запрос: {$sql}. Поддерживаются: CREATE TABLE, INSERT INTO.",
        );
    }

    /**
     * Парсит строку значений из SQL-запроса, учитывая кавычки и типы данных.
     *
     * @param string $valuesStr Строка значений в формате: value1, value2, ...
     *
     * @return array массив распарсенных значений с приведением типов
     */
    private function parseValues(string $valuesStr): array
    {
        $values = [];
        $current = '';
        $inQuotes = false;
        $quoteChar = '';

        for ($i = 0; $i < strlen($valuesStr); ++$i) {
            $char = $valuesStr[$i];

            if (!$inQuotes && ("'" === $char || '"' === $char)) {
                $inQuotes = true;
                $quoteChar = $char;
            } elseif ($inQuotes && $char === $quoteChar) {
                $inQuotes = false;
                $quoteChar = '';
            } elseif (!$inQuotes && ',' === $char) {
                $values[] = $this->castValue(trim($current));
                $current = '';

                continue;
            }

            if (!$inQuotes && ' ' === $char && empty(trim($current))) {
                continue;
            }

            $current .= $char;
        }

        // Добавляем последнее значение
        if ('' !== trim($current)) {
            $values[] = $this->castValue(trim($current));
        }

        return $values;
    }

    /**
     * Приводит строковое значение к соответствующему PHP-типу.
     *
     * Поддерживает преобразование в: int, float, bool, null, string.
     *
     * @param string $value строковое значение из SQL-запроса
     *
     * @return mixed приведённое значение
     */
    private function castValue(string $value): mixed
    {
        // Убираем кавычки, если они есть
        if ((str_starts_with($value, "'") && str_ends_with($value, "'"))
            || (str_starts_with($value, '"') && str_ends_with($value, '"'))) {
            return substr($value, 1, -1);
        }

        // Пробуем привести к числу
        if (is_numeric($value)) {
            // Если содержит точку, то float
            if (str_contains($value, '.')) {
                return (float) $value;
            }

            return (int) $value;
        }

        // NULL
        if ('NULL' === strtoupper($value)) {
            return null;
        }

        // BOOLEAN
        if ('TRUE' === strtoupper($value)) {
            return true;
        }
        if ('FALSE' === strtoupper($value)) {
            return false;
        }

        // Возвращаем как строку
        return $value;
    }

    /**
     * Откатывает изменения, выполненные в рамках текущей транзакции.
     *
     * Восстанавливает состояние хранилища из сохраненного снимка.
     *
     * @throws TransactionException если активная транзакция отсутствует
     */
    public function rollBack(): void
    {
        if (null === $this->transactionSnapshot) {
            throw new TransactionException('Нет активной транзакции.');
        }

        $this->store = $this->transactionSnapshot;
        $this->transactionSnapshot = null;
    }

    /**
     * Выполняет callable-функцию внутри транзакции.
     *
     * Автоматически фиксирует изменения при успешном выполнении или производит откат
     * в случае возникновения исключения.
     *
     * @template T
     *
     * @param callable(self): T $callback функция, выполняемая в контексте транзакции
     *
     * @return T результат выполнения callback-функции
     *
     * @throws \Throwable пробрасывает исключение, возникшее внутри callback, после отката
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->rollBack();

            throw $e;
        }
    }

    /**
     * Гарантирует инициализацию соединения и загрузку данных из файла.
     *
     * @throws \RuntimeException если не удалось прочитать файл хранилища
     */
    private function ensureConnection(): void
    {
        if ('' !== $this->file) {
            return;
        }
        $this->file = $this->fs->atBase('/storage/demo.json');
        if (file_exists($this->file)) {
            $data = $this->fs->readFile($this->file);
            $this->store = json_decode($data, true);
        }
    }

    /**
     * Проверяет статус подключения.
     *
     * @return bool true, если путь к файлу хранилища определен
     */
    public function isConnected(): bool
    {
        return '' !== $this->file;
    }

    /**
     * Завершает работу с соединением и сохраняет текущее состояние в файл.
     */
    public function disconnect(): void
    {
        $this->fs->writeFileSafe($this->file, json_encode($this->store, JSON_PRETTY_PRINT));
        $this->file = '';
    }

    /**
     * Начинает новую транзакцию.
     *
     * Создает снимок текущего состояния хранилища для возможности последующего отката.
     *
     * @throws TransactionException если уже имеется активная транзакция (вложенные транзакции не поддерживаются)
     */
    public function beginTransaction(): void
    {
        $this->ensureConnection();
        if (null !== $this->transactionSnapshot) {
            throw new TransactionException('Вложенные транзакции не поддерживаются в JsonConnection.');
        }

        $this->transactionSnapshot = $this->store;
    }

    /**
     * Фиксирует изменения, внесенные в рамках транзакции.
     *
     * Сохраняет актуальное состояние хранилища в файл и очищает снимок.
     *
     * @throws TransactionException если активная транзакция отсутствует
     */
    public function commit(): void
    {
        $this->ensureConnection();
        if (null === $this->transactionSnapshot) {
            throw new TransactionException('Нет активной транзакции.');
        }

        $this->transactionSnapshot = null;
        $this->fs->writeFileSafe($this->file, json_encode($this->store, JSON_PRETTY_PRINT));
    }
}

```

---
Далее: [Реализация результата](result.md)