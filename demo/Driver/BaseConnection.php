<?php

declare(strict_types=1);

namespace Vasoft\Joke\Db\Sql\Demo\Driver;

use Vasoft\Joke\Db\Sql\Contract\ConnectionInterface;
use Vasoft\Joke\Db\Sql\Contract\ResultInterface;
use Vasoft\Joke\Db\Sql\Exceptions\QueryException;
use Vasoft\Joke\Db\Sql\Exceptions\TransactionException;

/**
 * Демонстрационная реализация ConnectionInterface на основе внутреннего массива.
 *
 * Не использует реальную БД.
 * Данные хранятся в памяти, транзакции эмулируются через копию состояния.
 *
 * «SQL» интерпретируется упрощённо: поддерживаются базовые операции SELECT, INSERT, CREATE TABLE.
 */
abstract class BaseConnection implements ConnectionInterface
{
    public int $transactionLevel = 0 {
        get {
            return $this->transactionLevel;
        }
    }
    /**
     * @var array<string, list<array<string, mixed>>> хранилище данных по коллекциям
     */
    protected array $store = [];

    /**
     * @var array<string, null|list<array<string, mixed>>> Снимок состояния для транзакций.
     *                                                     null означает отсутствие активной транзакции.
     */
    private ?array $transactionSnapshot = null;

    public function query(string $sql, array $params = []): ResultInterface
    {
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
     * Выполняет SQL-запросы, изменяющие данные.
     *
     * Поддерживаемые операции:
     * - CREATE TABLE table_name (column1, column2, ...)
     * - INSERT INTO table_name VALUES (value1, value2, ...)
     * - INSERT INTO table_name (col1, col2) VALUES (value1, value2)
     *
     * @param string $sql    SQL-запрос
     * @param array  $params Параметры запроса (пока не используются)
     *
     * @return int Количество затронутых строк
     *
     * @throws QueryException Если запрос не поддерживается
     */
    public function execute(string $sql, array $params = []): int
    {
        $trimmedSql = trim($sql);

        if (preg_match('/^\s*CREATE\s+TABLE\s+(\w+)\s*\((.+?)\)\s*$/is', $trimmedSql, $matches)) {
            $tableName = strtolower($matches[1]);

            if (isset($this->store[$tableName])) {
                throw new QueryException("Таблица '{$tableName}' уже существует.");
            }

            $this->store[$tableName] = [];

            return 0;
        }

        // INSERT INTO table_name VALUES (...)
        if (preg_match('/^\s*INSERT\s+INTO\s+(\w+)\s+VALUES\s*\((.+?)\)\s*$/is', $trimmedSql, $matches)) {
            $tableName = strtolower($matches[1]);
            $valuesStr = $matches[2];

            $values = $this->parseValues($valuesStr);

            if (!isset($this->store[$tableName])) {
                throw new QueryException("Таблица '{$tableName}' не существует.");
            }

            $this->store[$tableName][] = $values;

            return 1;
        }

        // INSERT INTO table_name (col1, col2) VALUES (val1, val2)
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
     * Парсит строку значений из SQL-запроса.
     *
     * @param string $valuesStr Строка значений в формате: value1, value2, ...
     *
     * @return array Массив распарсенных значений
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
     * Приводит строковое значение к appropriate типу.
     *
     * @param string $value Строковое значение
     *
     * @return mixed Приведённое значение
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

    public function beginTransaction(): void
    {
        if (null !== $this->transactionSnapshot) {
            throw new TransactionException('Вложенные транзакции не поддерживаются в JsonConnection.');
        }

        $this->transactionSnapshot = $this->store;
    }

    public function commit(): void
    {
        if (null === $this->transactionSnapshot) {
            throw new TransactionException('Нет активной транзакции.');
        }

        $this->transactionSnapshot = null;
    }

    public function rollBack(): void
    {
        if (null === $this->transactionSnapshot) {
            throw new TransactionException('Нет активной транзакции.');
        }

        $this->store = $this->transactionSnapshot;
        $this->transactionSnapshot = null;
    }

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

    abstract public function isConnected(): bool;

    abstract public function disconnect(): void;
}
