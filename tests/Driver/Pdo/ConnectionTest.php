<?php

/** @noinspection SqlNoDataSourceInspection */

declare(strict_types=1);

namespace Vasoft\Joke\Db\Sql\Tests\Driver\Pdo;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Vasoft\Joke\Db\Sql\Driver\Pdo\Connection;
use Vasoft\Joke\Db\Sql\Exceptions\QueryException;
use Vasoft\Joke\Db\Sql\Exceptions\TransactionException;

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
        $connection = new Connection('sqlite', $database);
        $connection->execute(
            'CREATE TABLE IF NOT EXISTS users (
                id   INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL
            )',
        );

        return $connection;
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

    #[TestDox('transaction() откатывает изменения при исключении в колбэке и не скрывает исключение')]
    public function testTransactionRollsBackOnException(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIs('boom');

        try {
            $this->connection->transaction(static function (Connection $connection): void {
                $connection->execute("INSERT INTO users (name) VALUES ('Olga')");

                throw new \RuntimeException('boom');
            });
        } finally {
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
        $this->expectException(TransactionException::class);
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

        $this->expectException(TransactionException::class);

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

        $this->expectException(TransactionException::class);
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

        $this->expectException(TransactionException::class);
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
        $this->expectException(TransactionException::class);
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

    #[TestDox('execute() Бросает исключение если PDO exec вернул false ')]
    public function testExecuteThrowExceptionPdoExecReturnFalse(): void
    {
        $connection = $this->makeConnection(':memory:');

        $mockPdo = self::createStub(\PDO::class);
        $mockPdo->method('exec')
            ->willReturn(false);
        $originalPdo = $this->replacePdoWithMock($connection, $mockPdo);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageIs("Failed to execute SQL query: 'SELECT 1'.");

        try {
            $connection->execute('SELECT 1');
        } finally {
            $originalPdo = $this->replacePdoWithMock($connection, $originalPdo);
        }
    }

    #[TestDox('transaction() сохраняет оригинальное исключение при ошибке rollBack')]
    public function testTransactionPreservesOriginalExceptionWhenRollbackFails(): void
    {
        $connection = $this->makeConnection(':memory:');

        $mockPdo = self::createMock(\PDO::class);
        // beginTransaction() должен пройти успешно (вызывается внутри transaction())
        $mockPdo->method('beginTransaction')->willReturn(true);
        // rollBack() должен бросить исключение при втором вызове (первый при автоопределении поддержки)

        $mockPdo->expects(self::exactly(2))
            ->method('rollBack')
            ->willReturnCallback(static function (): bool {
                static $counter = 0;

                if (1 === $counter) {
                    throw new \PDOException('Rollback failed', 99000);
                }
                ++$counter;

                return true;
            });

        $originalPdo = $this->replacePdoWithMock($connection, $mockPdo);

        // Сбрасываем кэш supportsTransactions, чтобы property hook
        // выполнил проверку на mock (и закэшировал true, т.к. beginTransaction не бросает)
        $refCache = new \ReflectionProperty(Connection::class, 'supportsTransactions');
        $refCache->setValue($connection, null);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageIs('original error');

            $connection->transaction(static function (Connection $conn): never {
                throw new \RuntimeException('original error');
            });
        } finally {
            $this->replacePdoWithMock($connection, $originalPdo);
        }
    }

    #[TestDox('getLastInsertId() возвращает идентификатор')]
    public function testGetLastInsertId(): void
    {
        $this->connection->execute("INSERT INTO users (name) VALUES ('Olga')");

        self::assertSame('1', $this->connection->getLastInsertId());
        $this->connection->execute("INSERT INTO users (name) VALUES ('Alex')");

        self::assertSame('2', $this->connection->getLastInsertId());
    }

    #[TestDox('getLastInsertId() возвращает последний идентификатор при множественной вставки')]
    public function testGetLastInsertIdMultiple(): void
    {
        $rows = $this->connection->execute(
            "INSERT INTO users (name) VALUES ('Olga'),('Alex');",
        );

        self::assertSame(2, $rows);
        self::assertSame('2', $this->connection->getLastInsertId());
    }

    #[TestDox('getLastInsertId() сохраняет оригинальное исключение при ошибке rollBack')]
    #[AllowMockObjectsWithoutExpectations]
    public function testGetLastInsertIdPreservesOriginalException(): void
    {
        $connection = $this->makeConnection(':memory:');
        $connection->execute(
            "INSERT INTO users (name) VALUES ('Olga');",
        );

        $mockPdo = self::createMock(\PDO::class);
        $mockPdo->method('lastInsertId')->willThrowException(new \PDOException('Example error'));
        $originalPdo = $this->replacePdoWithMock($connection, $mockPdo);

        try {
            $this->expectException(QueryException::class);
            $this->expectExceptionMessageIs('Example error');

            $connection->getLastInsertId();
        } finally {
            $this->replacePdoWithMock($connection, $originalPdo);
        }
    }

    #[TestDox('getLastInsertId() бросает исключение при невозможности получить ID')]
    #[AllowMockObjectsWithoutExpectations]
    #[DataProvider('provideGetLastInsertExceptionCases')]
    public function testGetLastInsertException(false|string $value): void
    {
        $connection = $this->makeConnection(':memory:');

        $mockPdo = self::createMock(\PDO::class);
        $mockPdo->method('lastInsertId')->willReturn($value);
        $originalPdo = $this->replacePdoWithMock($connection, $mockPdo);

        try {
            $this->expectException(QueryException::class);
            $this->expectExceptionMessageIs('Unable to retrieve last insert ID...');

            $connection->getLastInsertId();
        } finally {
            $this->replacePdoWithMock($connection, $originalPdo);
        }
    }

    public static function provideGetLastInsertExceptionCases(): iterable
    {
        yield [false];
        yield [''];
        yield ['0'];
    }

    #[TestDox('getLastInsertId() передает имя последовательности в PDO')]
    public function testGetLastInsertIdWithSequenceName(): void
    {
        $connection = $this->makeConnection(':memory:');

        $mockPdo = self::createMock(\PDO::class);

        $mockPdo->expects(self::once())
            ->method('lastInsertId')
            ->with('users_id_seq')
            ->willReturn('42');

        $originalPdo = $this->replacePdoWithMock($connection, $mockPdo);

        try {
            $id = $connection->getLastInsertId('users_id_seq');

            self::assertSame('42', $id);
        } finally {
            $this->replacePdoWithMock($connection, $originalPdo);
        }
    }
}
