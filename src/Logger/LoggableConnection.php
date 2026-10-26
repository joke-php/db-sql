<?php

declare(strict_types=1);

namespace Vasoft\Joke\Db\Sql\Logger;

use Vasoft\Joke\Db\Sql\Contract\ConnectionInterface;
use Vasoft\Joke\Db\Sql\Contract\SqlLoggerInterface;
use Vasoft\Joke\Db\Sql\Contract\ResultInterface;
use Vasoft\Joke\Db\Sql\ConnectionManager;
use Vasoft\Joke\Db\Sql\Exceptions\DatabaseException;
use Vasoft\Joke\Db\Sql\Exceptions\QueryException;
use Vasoft\Joke\Db\Sql\Exceptions\TransactionException;

/**
 * Декоратор соединения, добавляющий логирование SQL-запросов и транзакций.
 *
 * Оборачивает реальное соединение и перехватывает вызовы методов query/execute/transaction.
 * Позволяет гибко настраивать глубину логирования через {@see LogMode}.
 *
 * Нулевой оверхед для основного приложения: декоратор создается только тогда,
 * когда логирование действительно необходимо (например, через {@see ConnectionManager::loggable()}).
 */
final class LoggableConnection implements ConnectionInterface
{
    /**
     * Текущий уровень вложенности транзакций.
     * Делегирует чтение внутреннему соединению.
     */
    public int $transactionLevel {
        get {
            return $this->inner->transactionLevel;
        }
    }

    public function __construct(
        private readonly ConnectionInterface $inner,
        private readonly SqlLoggerInterface $logger,
        private readonly LogMode $logMode = LogMode::All,
    ) {}

    /**
     * Выполняет SELECT-запрос и возвращает результат.
     *
     * В зависимости от {@see LogMode}:
     * - Логирует время выполнения запроса на стороне сервера.
     * - Оборачивает результат в {@see LoggableResult} для замера времени чтения данных (fetch time).
     *
     * @param non-empty-string     $sql    SQL-запрос с плейсхолдерами
     * @param array<string, mixed> $params Параметры для привязки
     *
     * @throws QueryException В случае ошибок запроса
     */
    public function query(string $sql, array $params = []): ResultInterface
    {
        $start = hrtime(true);

        try {
            $result = $this->inner->query($sql, $params);
            if (LogMode::FetchOnly !== $this->logMode) {
                $this->logger->query($sql, $params, $this->elapsedMs($start), null, null);
            }
            if (LogMode::ExecutionOnly !== $this->logMode) {
                return new LoggableResult($result, $this->logger, $sql, $params);
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logger->query($sql, $params, $this->elapsedMs($start), null, $e->getMessage());
            if ($e instanceof DatabaseException) {
                throw $e;
            }

            throw new QueryException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Выполняет запрос на изменение данных (INSERT/UPDATE/DELETE).
     *
     * Логирует время выполнения и количество затронутых строк.
     *
     * @param non-empty-string     $sql    SQL-запрос с плейсхолдерами
     * @param array<string, mixed> $params Параметры для привязки
     *
     * @throws QueryException
     */
    public function execute(string $sql, array $params = []): int
    {
        $start = hrtime(true);

        try {
            $rows = $this->inner->execute($sql, $params);
            $this->logger->query($sql, $params, $this->elapsedMs($start), (int) $rows, null);

            return $rows;
        } catch (\Throwable $e) {
            $this->logger->query($sql, $params, $this->elapsedMs($start), null, $e->getMessage());

            if ($e instanceof DatabaseException) {
                throw $e;
            }

            throw new QueryException($e->getMessage(), 0, $e);
        }
    }

    /**
     * Начинает новую транзакцию.
     *
     * @throws TransactionException В случае ошибок запросов и транзакций
     */
    public function beginTransaction(): void
    {
        $this->inner->beginTransaction();
        $this->logger->transactionStarted($this->inner->transactionLevel);
    }

    /**
     * Фиксирует текущую транзакцию.
     *
     * @throws TransactionException В случае ошибок запросов и транзакций
     */
    public function commit(): void
    {
        $level = $this->inner->transactionLevel;
        $this->inner->commit();
        $this->logger->transactionCommitted($level);
    }

    /**
     * Откатывает текущую транзакцию.
     *
     * @throws TransactionException В случае ошибок запросов и транзакций
     */
    public function rollBack(): void
    {
        $level = $this->inner->transactionLevel;
        $this->inner->rollBack();
        $this->logger->transactionRolledBack($level);
    }

    /**
     * Выполняет callback внутри транзакции.
     *
     * Автоматически фиксирует транзакцию при успехе или откатывает при исключении.
     * Корректно логирует уровень вложенности транзакции.
     *
     * @template T
     *
     * @param callable(ConnectionInterface): T $callback функция, выполняемая внутри транзакции
     *
     * @return T возвращаемое значение callback
     *
     * @throws \Throwable           исключение, выброшенное callback
     * @throws QueryException       при ошибках запросов
     * @throws TransactionException при ошибках транзакций
     */
    public function transaction(callable $callback): mixed
    {
        $newLevel = $this->inner->transactionLevel + 1;
        $this->logger->transactionStarted($newLevel);

        try {
            $result = $this->inner->transaction($callback);
            $this->logger->transactionCommitted($newLevel);

            return $result;
        } catch (\Throwable $e) {
            $this->logger->transactionRolledBack($newLevel);

            throw $e;
        }
    }

    public function isConnected(): bool
    {
        return $this->inner->isConnected();
    }

    public function disconnect(): void
    {
        $this->inner->disconnect();
    }

    private function elapsedMs(int $start): float
    {
        return (hrtime(true) - $start) / 1e6;
    }

    public function getLastInsertId(?string $sequenceName = null): string
    {
        return $this->inner->getLastInsertId($sequenceName);
    }
}
