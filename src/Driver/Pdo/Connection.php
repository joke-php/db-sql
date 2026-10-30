<?php

declare(strict_types=1);

namespace Vasoft\Joke\Db\Sql\Driver\Pdo;

use Vasoft\Joke\Db\Sql\Contract\ConnectionInterface;
use Vasoft\Joke\Db\Sql\Contract\ResultInterface;
use Vasoft\Joke\Db\Sql\Exceptions\QueryException;
use Vasoft\Joke\Db\Sql\Exceptions\TransactionException;
use Vasoft\Joke\Db\Sql\TransactionSupportPolicy;

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

    public private(set) TransactionSupportPolicy $transactionSupportPolicy = TransactionSupportPolicy::REQUIRED {
        get => $this->transactionSupportPolicy;
    }

    /**
     * Кэш DSN-строки. Формируется один раз при первом обращении.
     */
    protected string $dsn = '';
    /**
     * Поддержка транзакций текущим соединением.
     */
    protected ?bool $supportsTransactions = null {
        get {
            if (null === $this->supportsTransactions) {
                try {
                    $pdo = $this->ensureConnection();
                    $pdo->beginTransaction();
                    $pdo->rollBack();
                    $this->supportsTransactions = true;
                } catch (\PDOException) {
                    $this->supportsTransactions = false;
                }
            }

            return $this->supportsTransactions;
        }
    }

    /**
     * @param non-empty-string                         $driver          Название драйвера БД (mysql, pgsql, sqlite и т.д.)
     * @param non-empty-string                         $database        Имя базы данных или путь к файлу (для SQLite)
     * @param string                                   $host            Хост сервера БД (пустая строка для локальных/Unix-сокетов)
     * @param null|int                                 $port            Порт сервера БД (null для порта по умолчанию)
     * @param null|string                              $username        Имя пользователя для аутентификации
     * @param null|string                              $password        Пароль для аутентификации
     * @param array<string,null|bool|float|int|string> $specificOptions Специфичные опции драйвера (например, параметры для DSN-строки)
     */
    public function __construct(
        public readonly string $driver,
        public readonly string $database,
        public readonly string $host = '',
        public readonly ?int $port = null,
        public readonly ?string $username = null,
        public readonly ?string $password = null,
        public readonly array $specificOptions = [],
    ) {}

    /**
     * Возвращает DSN-строку для PDO.
     *
     * Формируется через {@see Driver::buildDsn()} при первом вызове и кэшируется.
     */
    public function dsn(): string
    {
        if ('' === $this->dsn) {
            $this->dsn = Driver::buildDsn(
                $this->driver,
                $this->database,
                $this->host,
                $this->port,
                $this->specificOptions,
            );
        }

        return $this->dsn;
    }

    public function setTransactionSupportPolicy(TransactionSupportPolicy $transactionSupportPolicy): static
    {
        $this->transactionSupportPolicy = $transactionSupportPolicy;

        return $this;
    }

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
            $this->pdo = new \PDO($this->dsn(), $this->username, $this->password, []);
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
                foreach ($params as $key => $value) {
                    $type = is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR;
                    $statement->bindValue($key, $value, $type);
                }
                $statement->execute();
            }

            return new Result($statement);
        } catch (\PDOException $e) {
            throw new QueryException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @return int количество затронутых строк или false при ошибке (для exec без параметров)
     *
     * @throws QueryException при ошибке выполнения запроса
     */
    public function execute(string $sql, array $params = []): int
    {
        try {
            $pdo = $this->ensureConnection();
            if (empty($params)) {
                $result = $pdo->exec($sql);
                if (false === $result) {
                    throw new QueryException("Failed to execute SQL query: '{$sql}'.");
                }

                return $result;
            }
            $statement = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $type = is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR;
                $statement->bindValue($key, $value, $type);
            }
            $statement->execute();

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
     * @throws TransactionException при ошибке начала транзакции или создания savepoint
     */
    public function beginTransaction(): void
    {
        if (!$this->supportsTransactions && TransactionSupportPolicy::OPTIONAL === $this->transactionSupportPolicy) {
            ++$this->transactionLevel;

            return;
        }

        try {
            $pdo = $this->ensureConnection();
            if (0 === $this->transactionLevel) {
                $pdo->beginTransaction();
            } else {
                $pdo->exec('SAVEPOINT ' . static::SAVE_POINT_PREFIX . ($this->transactionLevel + 1));
            }
            ++$this->transactionLevel;
        } catch (\PDOException $e) {
            throw new TransactionException($e->getMessage(), 0, $e);
        }
    }

    /**
     * {@inheritDoc}
     *
     * На уровне 1 выполняет реальный COMMIT.
     * На уровнях >1 освобождает savepoint текущего уровня.
     * Уровень уменьшается в finally, гарантируя корректное состояние даже при ошибке.
     *
     * @throws TransactionException если нет активной транзакции или ошибка фиксации/savepoint
     */
    public function commit(): void
    {
        if (0 === $this->transactionLevel) {
            throw new TransactionException('No active transaction.');
        }

        if (!$this->supportsTransactions && TransactionSupportPolicy::OPTIONAL === $this->transactionSupportPolicy) {
            --$this->transactionLevel;

            return;
        }

        try {
            $pdo = $this->ensureConnection();
            if (1 === $this->transactionLevel) {
                $pdo->commit();
            } else {
                $pdo->exec('RELEASE SAVEPOINT ' . static::SAVE_POINT_PREFIX . $this->transactionLevel);
            }
        } catch (\PDOException $e) {
            throw new TransactionException($e->getMessage(), 0, $e);
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
     * @throws TransactionException если нет активной транзакции или ошибка отката/savepoint
     */
    public function rollBack(): void
    {
        if (0 === $this->transactionLevel) {
            throw new TransactionException('No active transaction.');
        }

        if (!$this->supportsTransactions && TransactionSupportPolicy::OPTIONAL === $this->transactionSupportPolicy) {
            --$this->transactionLevel;

            return;
        }

        try {
            $pdo = $this->ensureConnection();
            if (1 === $this->transactionLevel) {
                $pdo->rollBack();
            } else {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . static::SAVE_POINT_PREFIX . $this->transactionLevel);
            }
        } catch (\PDOException $e) {
            throw new TransactionException($e->getMessage(), 0, $e);
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
        if (!$this->supportsTransactions && TransactionSupportPolicy::OPTIONAL === $this->transactionSupportPolicy) {
            return $callback($this);
        }

        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (\Throwable $e) {
            // Откатываемся только если транзакция ещё активна
            // (например, commit() сам упал и уже сбросил уровень в finally).
            if ($this->transactionLevel > 0) {
                try {
                    $this->rollBack();
                } catch (TransactionException $internal) {
                    // Ошибка отката не должна скрывать оригинальное исключение
                }
            }

            throw $e;
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
        $this->supportsTransactions = null;
    }

    /**
     * @throws QueryException Если невозможно определить идентификатор
     */
    public function getLastInsertId(?string $sequenceName = null): string
    {
        $pdo = $this->ensureConnection();

        try {
            $id = null === $sequenceName
                ? $pdo->lastInsertId()
                : $pdo->lastInsertId($sequenceName);
            if (false === $id || '' === $id || '0' === $id) {
                throw new QueryException('Unable to retrieve last insert ID...');
            }

            return $id;
        } catch (\PDOException $e) {
            throw new QueryException($e->getMessage(), 0, $e);
        }
    }
}
