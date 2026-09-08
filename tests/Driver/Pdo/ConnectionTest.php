<?php

/** @noinspection SqlNoDataSourceInspection */

declare(strict_types=1);

namespace Vasoft\Joke\Db\Sql\Tests\Driver\Pdo;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Vasoft\Joke\Db\Sql\Driver\Pdo\Connection;
use Vasoft\Joke\Db\Sql\Exceptions\QueryException;
use Vasoft\Joke\Db\Sql\SqlConnectionConfig;

/**
 * @internal
 *
 * @coversDefaultClass \Vasoft\Joke\Db\Sql\Driver\Pdo\Connection
 */
#[TestDox('PDO\Connection - обертка над PDO')]
#[CoversClass(Connection::class)]
final class ConnectionTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = $this->makeConnection(':memory:');
    }

    private function makeConnection(string $database): Connection
    {
        $connection = new Connection($this->createConfig($database));
        $connection->execute(
            'CREATE TABLE IF NOT EXISTS users (
                id   INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL
            )',
        );

        return $connection;
    }

    private function createConfig(string $database): SqlConnectionConfig
    {
        return new SqlConnectionConfig('sqlite', $database);
    }

    #[TestDox('DSN корректно собирается для SQLite-подключения')]
    public function testDsnBuildsSqliteConnection(): void
    {
        self::assertSame('sqlite::memory:', $this->connection->dsn());
    }

    #[TestDox('execute() возвращает число затронутых строк')]
    public function testExecuteReturnsAffectedRows(): void
    {
        $affected = $this->connection->execute(
            "INSERT INTO users (name) VALUES ('Olga')",
        );

        self::assertSame(1, $affected);
    }

    #[TestDox('query() возвращает строки в виде Result')]
    public function testQueryReturnsRowsAsResult(): void
    {
        $this->connection->execute('INSERT INTO users (name) VALUES (:name)', ['name' => 'Olga']);
        $this->connection->execute('INSERT INTO users (name) VALUES (:name)', ['name' => 'Alex']);

        $result = $this->connection->query('SELECT * FROM users ORDER BY id');

        self::assertSame([
            ['id' => 1, 'name' => 'Olga'],
            ['id' => 2, 'name' => 'Alex'],
        ], $result->all());
    }

    #[TestDox('query() с параметрами использует prepared statement')]
    public function testQueryWithParametersUsesPreparedStatement(): void
    {
        $this->connection->execute("INSERT INTO users (name) VALUES ('Olga')");

        $result = $this->connection->query(
            'SELECT * FROM users WHERE name = :name',
            ['name' => 'Olga'],
        );

        self::assertSame([['id' => 1, 'name' => 'Olga']], $result->all());
    }

    #[TestDox('execute() с параметрами')]
    public function testExecuteWithParameters(): void
    {
        $affected = $this->connection->execute(
            'INSERT INTO users (name) VALUES (:name)',
            ['name' => 'Carol'],
        );

        self::assertSame(1, $affected);
        self::assertSame(1, $this->connection->query('SELECT COUNT(*) AS cnt FROM users')->fetch()['cnt']);
    }

    #[TestDox('commit() фиксирует изменения')]
    public function testCommitPersistsChanges(): void
    {
        $this->connection->beginTransaction();
        $this->connection->execute("INSERT INTO users (name) VALUES ('Olga')");
        $this->connection->commit();

        self::assertSame(1, $this->connection->query('SELECT COUNT(*) AS cnt FROM users')->fetch()['cnt']);
    }

    #[TestDox('rollBack() откатывает изменения')]
    public function testRollbackDiscardsChanges(): void
    {
        $this->connection->beginTransaction();
        $this->connection->execute("INSERT INTO users (name) VALUES ('Olga')");
        $this->connection->rollBack();

        self::assertSame(0, $this->connection->query('SELECT COUNT(*) AS cnt FROM users')->fetch()['cnt']);
    }

    #[TestDox('Вложенные транзакции коммитятся на обоих уровнях')]
    public function testNestedTransactionsCommitBothLevels(): void
    {
        $this->connection->beginTransaction();
        $this->connection->execute("INSERT INTO users (name) VALUES ('Olga')");

        $this->connection->beginTransaction(); // savepoint
        $this->connection->execute("INSERT INTO users (name) VALUES ('Alex')");
        $this->connection->commit(); // release savepoint

        $this->connection->commit(); // final commit

        self::assertSame(2, $this->connection->query('SELECT COUNT(*) AS cnt FROM users')->fetch()['cnt']);
    }

    #[TestDox('Внутренний rollBack сохраняет изменения внешней транзакции')]
    public function testNestedRollbackKeepsOuterChanges(): void
    {
        $this->connection->beginTransaction();
        $this->connection->execute("INSERT INTO users (name) VALUES ('Olga')");

        $this->connection->beginTransaction(); // savepoint
        $this->connection->execute("INSERT INTO users (name) VALUES ('Alex')");
        $this->connection->rollBack(); // rollback to savepoint

        $this->connection->commit(); // final commit

        $names = $this->connection->query('SELECT name FROM users')->all();
        self::assertSame([['name' => 'Olga']], $names);
    }

    #[TestDox('transaction() коммитит и возвращает результат колбэка')]
    public function testTransactionCallbackCommitsResult(): void
    {
        $result = $this->connection->transaction(static function (Connection $connection): int {
            $connection->execute("INSERT INTO users (name) VALUES ('Olga')");

            return 42;
        });

        self::assertSame(42, $result);
        self::assertSame(1, $this->connection->query('SELECT COUNT(*) AS cnt FROM users')->fetch()['cnt']);
    }

    #[TestDox('transaction() откатывает изменения при исключении в колбэке')]
    public function testTransactionRollsBackOnException(): void
    {
        $this->expectException(QueryException::class);

        try {
            $this->connection->transaction(static function (Connection $connection): void {
                $connection->execute("INSERT INTO users (name) VALUES ('Olga')");

                throw new \RuntimeException('boom');
            });
        } finally {
            // Вставка должна быть откачена
            self::assertSame(0, $this->connection->query('SELECT COUNT(*) AS cnt FROM users')->fetch()['cnt']);
        }
    }

    #[TestDox('transaction() не оборачивает QueryException повторно')]
    public function testTransactionDoesNotWrapQueryExceptionTwice(): void
    {
        $this->expectException(QueryException::class);

        $this->connection->transaction(static function (Connection $connection): void {
            // Некорректный SQL внутри транзакции -> QueryException
            $connection->query('SELECT * FROM nonexistent_table');
        });
    }

    #[TestDox('commit() без активной транзакции выбрасывает исключение')]
    public function testCommitWithoutTransactionThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageIs('No active transaction.');

        $this->connection->commit();
    }

    #[TestDox('rollBack() обрабатывает ошибки PDO')]
    public function testRollbackHandlesPdoErrors(): void
    {
        $connection = $this->makeConnection(':memory:');
        $connection->beginTransaction();
        $reflection = new \ReflectionClass($connection);
        $pdoProperty = $reflection->getProperty('pdo');
        $pdoProperty->setValue($connection, null);

        $this->expectException(QueryException::class);

        $connection->rollBack();
    }

    private function replacePdoWithMock(Connection $connection, \PDO $mockPdo): \PDO
    {
        $reflection = new \ReflectionClass($connection);
        $property = $reflection->getProperty('pdo');

        $originalPdo = $property->getValue($connection);
        $property->setValue($connection, $mockPdo);

        return $originalPdo;
    }

    #[TestDox('execute() оборачивает PDOException в QueryException')]
    public function testExecuteWrapsPDOException(): void
    {
        $connection = $this->makeConnection(':memory:');
        $mockPdo = self::createStub(\PDO::class);
        $originalPdo = $this->replacePdoWithMock($connection, $mockPdo);

        $mockStatement = self::createStub(\PDOStatement::class);
        $mockStatement->method('execute')
            ->willThrowException(new \PDOException('Constraint violation', 23000));

        $mockPdo->method('prepare')
            ->willReturn($mockStatement);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageIs('Constraint violation');

        try {
            $connection->execute('INSERT INTO users (name) VALUES (:name)', ['name' => 'Test']);
        } finally {
            $this->replacePdoWithMock($connection, $originalPdo);
        }
    }

    #[TestDox('commit() обрабатывает ошибки PDO')]
    public function testCommitHandlesPdoErrors(): void
    {
        $connection = $this->makeConnection(':memory:');
        $connection->beginTransaction();

        $mockPdo = self::createStub(\PDO::class);
        $mockPdo->method('commit')
            ->willThrowException(new \PDOException('Simulated commit error', 11000));

        $originalPdo = $this->replacePdoWithMock($connection, $mockPdo);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageIs('Simulated commit error');

        try {
            $connection->commit();
        } finally {
            $this->replacePdoWithMock($connection, $originalPdo);
        }
    }

    #[TestDox('beginTransaction() обрабатывает ошибки PDO')]
    public function testBeginTransactionHandlesPdoErrors(): void
    {
        $connection = $this->makeConnection(':memory:');

        $mockPdo = self::createStub(\PDO::class);
        $mockPdo->method('beginTransaction')
            ->willThrowException(new \PDOException('Cannot start transaction', 21000));
        $originalPdo = $this->replacePdoWithMock($connection, $mockPdo);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageIs('Cannot start transaction');

        try {
            $connection->beginTransaction();
        } finally {
            $originalPdo = $this->replacePdoWithMock($connection, $originalPdo);
        }
    }

    #[TestDox('rollBack() без активной транзакции выбрасывает исключение')]
    public function testRollbackWithoutTransactionThrows(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageIs('No active transaction.');

        $this->connection->rollBack();
    }

    #[TestDox('Некорректный SQL выбрасывает QueryException')]
    public function testInvalidSqlThrowsQueryException(): void
    {
        $this->expectException(QueryException::class);

        $this->connection->query('SELECT * FROM nonexistent_table');
    }

    #[TestDox('disconnect() сбрасывает состояние подключения')]
    public function testDisconnectResetsConnectionState(): void
    {
        $this->connection->query('SELECT 1'); // инициализирует подключение
        self::assertTrue($this->connection->isConnected());

        $this->connection->disconnect();
        self::assertFalse($this->connection->isConnected());
    }

    #[TestDox('После disconnect() подключение пересоздаётся при новом запросе')]
    public function testReconnectAfterDisconnectWorks(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'joke_test_');
        $connection = $this->makeConnection($tempFile);

        try {
            $connection->beginTransaction();
            $connection->execute("INSERT INTO users (name) VALUES ('Olga')");
            $connection->commit();

            $connection->disconnect();
            self::assertFalse($connection->isConnected());

            // Повторный запрос должен пересоздать подключение
            self::assertSame(1, $connection->query('SELECT COUNT(*) AS cnt FROM users')->fetch()['cnt']);
            self::assertTrue($connection->isConnected());
        } finally {
            unlink($tempFile);
        }
    }
}
