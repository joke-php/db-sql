<?php

declare(strict_types=1);

namespace Vasoft\Joke\Db\Sql\Driver\Pdo;

use Vasoft\Joke\Db\Sql\Contract\ConnectionInterface;
use Vasoft\Joke\Db\Sql\Contract\ResultInterface;
use Vasoft\Joke\Db\Sql\Exceptions\QueryException;
use Vasoft\Joke\Db\Sql\SqlConnectionConfig;

/**
 * PDO-реализация подключения к базе данных.
 *
 * Поддерживает ленивое подключение: физическое соединение устанавливается
 * только при первом вызове метода, требующего доступа к БД.
 *
 * Вложенные транзакции реализуются через savepoints с префиксом {@see SAVE_POINT_PREFIX}.
 * При ошибке внутри {@see transaction()} выполняется автоматический откат.
 * Если commit() уже сбросил уровень транзакции (через finally), повторный rollBack не выполняется.
 */
class Connection implements ConnectionInterface
{
    /**
     * Префикс имён savepoint'ов для вложенных транзакций.
     */
    public const string SAVE_POINT_PREFIX = 'joke_sp_std_';
    private ?\PDO $pdo = null;
    /**
     * Текущий уровень вложенности транзакций.
     * 0 — нет активной транзакции, 1 — реальная транзакция, >1 — savepoints.
     */
    public protected(set) int $transactionLevel = 0 {
        get => $this->transactionLevel;
    }

    /**
     * Кэш DSN-строки. Формируется один раз при первом обращении.
     */
    protected string $dsn = '';

    /**
     * Возвращает DSN-строку для PDO.
     *
     * Формируется через {@see Driver::buildDsn()} при первом вызове и кэшируется.
     */
    public function dsn(): string
    {
        if ('' === $this->dsn) {
            $this->dsn = Driver::buildDsn($this->config);
        }

        return $this->dsn;
    }

    /**
     * @param SqlConnectionConfig $config конфигурация подключения
     */
    public function __construct(protected readonly SqlConnectionConfig $config) {}

    /**
     * Обеспечивает наличие активного PDO-соединения.
     *
     * При первом вызове создаёт PDO-объект, замораживает конфигурацию
     * и устанавливает режим ошибок ERRMODE_EXCEPTION.
     *
     * @return \PDO активное PDO-соединение
     */
    private function ensureConnection(): \PDO
    {
        if (null === $this->pdo) {
            $this->config->freeze();
            $this->pdo = new \PDO($this->dsn(), $this->config->username, $this->config->password, []);
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        }

        return $this->pdo;
    }

    /**
     * @throws QueryException при ошибке выполнения запроса
     */
    public function query(string $sql, array $params = []): ResultInterface
    {
        try {
            $pdo = $this->ensureConnection();
            if (empty($params)) {
                $statement = $pdo->query($sql);
            } else {
                $statement = $pdo->prepare($sql);
                $statement->execute($params);
            }

            return new Result($statement);
        } catch (\PDOException $e) {
            throw new QueryException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @return false|int количество затронутых строк или false при ошибке (для exec без параметров)
     *
     * @throws QueryException при ошибке выполнения запроса
     */
    public function execute(string $sql, array $params = []): int|false
    {
        try {
            $pdo = $this->ensureConnection();
            if (empty($params)) {
                return $pdo->exec($sql);
            }
            $statement = $pdo->prepare($sql);
            $statement->execute($params);

            return $statement->rowCount();
        } catch (\PDOException $e) {
            throw new QueryException($e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritDoc}
     *
     * На уровне 1 выполняет реальный старт.
     * На уровнях >1 создаёт savepoint с именем {@see SAVE_POINT_PREFIX}{level}.
     *
     * @throws QueryException при ошибке начала транзакции или создания savepoint
     */
    public function beginTransaction(): void
    {
        try {
            $pdo = $this->ensureConnection();
            if (0 === $this->transactionLevel) {
                $pdo->beginTransaction();
            } else {
                $pdo->exec('SAVEPOINT ' . static::SAVE_POINT_PREFIX . ($this->transactionLevel + 1));
            }
            ++$this->transactionLevel;
        } catch (\PDOException $e) {
            throw new QueryException($e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritDoc}
     *
     * На уровне 1 выполняет реальный COMMIT.
     * На уровнях >1 освобождает savepoint текущего уровня.
     * Уровень уменьшается в finally, гарантируя корректное состояние даже при ошибке.
     *
     * @throws QueryException если нет активной транзакции или ошибка фиксации/savepoint
     */
    public function commit(): void
    {
        if (0 === $this->transactionLevel) {
            throw new QueryException('No active transaction.');
        }

        try {
            $pdo = $this->ensureConnection();
            if (1 === $this->transactionLevel) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . static::SAVE_POINT_PREFIX . $this->transactionLevel);
            }
        } catch (\PDOException $e) {
            throw new QueryException($e->getMessage(), 0, $e);
        } finally {
            --$this->transactionLevel;
        }
    }

    /**
     * {@inheritDoc}
     *
     * На уровне 1 выполняет реальный ROLLBACK.
     * На уровнях >1 откатывает до savepoint текущего уровня.
     * Уровень уменьшается в finally, гарантируя корректное состояние даже при ошибке.
     *
     * @throws QueryException если нет активной транзакции или ошибка отката/savepoint
     */
    public function rollBack(): void
    {
        if (0 === $this->transactionLevel) {
            throw new QueryException('No active transaction.');
        }

        try {
            $pdo = $this->ensureConnection();
            if (1 === $this->transactionLevel) {
                $pdo->rollBack();
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . static::SAVE_POINT_PREFIX . $this->transactionLevel);
            }
        } catch (\PDOException $e) {
            throw new QueryException($e->getMessage(), 0, $e);
        } finally {
            --$this->transactionLevel;
        }
    }

    /**
     * {@inheritDoc}
     *
     * При успешном выполнении callback вызывает commit().
     * При выбросе Throwable выполняет rollBack() (если транзакция ещё активна)
     * и оборачивает исключение в {@see QueryException}, если оно ещё не является таковым.
     *
     * @template T
     *
     * @param callable(self): T $callback функция, выполняемая внутри транзакции
     *
     * @return T возвращаемое значение callback
     *
     * @throws QueryException при ошибке транзакции или исключении из callback
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (\Throwable $e) {
            // Откатываемся только если транзакция ещё активна
            // (например, commit() сам упал и уже сбросил уровень в finally).
            if ($this->transactionLevel > 0) {
                $this->rollBack();
            }
            if ($e instanceof QueryException) {
                throw $e;
            }

            throw new QueryException($e->getMessage(), $e->getCode(), $e);
        }
    }

    public function isConnected(): bool
    {
        return null !== $this->pdo;
    }

    /**
     * {@inheritDoc}
     *
     * Сбрасывает уровень транзакций в 0. Последующие вызовы методов,
     * требующих подключения, инициируют новое ленивое соединение.
     *
     * Внимание: для SQLite :memory: это приведёт к потере всех данных,
     * так как in-memory база уничтожается вместе с PDO-объектом.
     */
    public function disconnect(): void
    {
        $this->pdo = null;
        $this->transactionLevel = 0;
    }
}
