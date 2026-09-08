<?php

declare(strict_types=1);

namespace Vasoft\Joke\Db\Sql\Tests\Logger;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Vasoft\Joke\Db\Sql\Contract\ConnectionInterface;
use Vasoft\Joke\Db\Sql\Contract\ResultInterface;
use Vasoft\Joke\Db\Sql\Contract\SqlLoggerInterface;
use Vasoft\Joke\Db\Sql\Exceptions\DatabaseException;
use Vasoft\Joke\Db\Sql\Exceptions\QueryException;
use Vasoft\Joke\Db\Sql\Logger\LoggableConnection;
use Vasoft\Joke\Db\Sql\Logger\LoggableResult;
use Vasoft\Joke\Db\Sql\Logger\LogMode;

/**
 * @internal
 *
 * @coversDefaultClass \Vasoft\Joke\Db\Sql\Logger\LoggableConnection
 */
#[CoversClass(LoggableConnection::class)]
#[TestDox('LoggableConnection - декоратор логирования соединения')]
final class LoggableConnectionTest extends TestCase
{
    private const string SQL = 'SELECT * FROM users WHERE id = :id';
    private const array PARAMS = ['id' => 1];

    private ConnectionInterface $inner;
    private SqlLoggerInterface $logger;
    private ResultInterface $result;

    protected function setUp(): void
    {
        $this->inner = self::createStub(ConnectionInterface::class);
        $this->logger = self::createStub(SqlLoggerInterface::class);
        $this->result = self::createStub(ResultInterface::class);
    }

    private function makeConnection(
        LogMode $mode = LogMode::All,
        ?ConnectionInterface $inner = null,
        ?SqlLoggerInterface $logger = null,
    ): LoggableConnection {
        return new LoggableConnection($inner ?? $this->inner, $logger ?? $this->logger, $mode);
    }

    /**
     * Создаёт объект соединения с настраиваемым transactionLevel.
     */
    private function stubConnection(int $level = 0): ConnectionInterface
    {
        return new class ($level) implements ConnectionInterface {
            public int $transactionLevel {
                get => $this->level;
            }
            private int $level;

            public function __construct(int $level)
            {
                $this->level = $level;
            }

            public function query(string $sql, array $params = []): ResultInterface
            {
                throw new \LogicException('not used in test');
            }

            public function execute(string $sql, array $params = []): int
            {
                throw new \LogicException('not used in test');
            }

            public function beginTransaction(): void {}

            public function commit(): void {}

            public function rollBack(): void {}

            public function transaction(callable $callback): mixed
            {
                return $callback($this);
            }

            public function isConnected(): bool
            {
                return true;
            }

            public function disconnect(): void {}
        };
    }

    #[TestDox('query() логирует время выполнения и возвращает декоратор результата')]
    public function testQueryLogsAndReturnsLoggableResult(): void
    {
        $inner = self::createMock(ConnectionInterface::class);
        $inner->expects(self::once())->method('query')->with(self::SQL, self::PARAMS)->willReturn($this->result);
        $logger = self::createMock(SqlLoggerInterface::class);
        $logger->expects(self::once())
            ->method('query')
            ->with(self::SQL, self::PARAMS, self::isFloat(), null, null);

        $result = $this->makeConnection(inner: $inner, logger: $logger)->query(self::SQL, self::PARAMS);

        self::assertInstanceOf(LoggableResult::class, $result);
    }

    #[TestDox('query() в режиме ExecutionOnly возвращает сырой результат без обёртки')]
    public function testQueryExecutionOnlyReturnsRawResult(): void
    {
        $this->inner->method('query')->willReturn($this->result);
        $logger = self::createMock(SqlLoggerInterface::class);

        $logger->expects(self::once())->method('query');

        $result = $this->makeConnection(LogMode::ExecutionOnly, logger: $logger)->query(self::SQL, self::PARAMS);

        self::assertSame($this->result, $result);
    }

    #[TestDox('query() в режиме FetchOnly не логирует время выполнения')]
    public function testQueryFetchOnlyDoesNotLogExecution(): void
    {
        $this->inner->method('query')->willReturn($this->result);
        $logger = self::createMock(SqlLoggerInterface::class);

        $logger->expects(self::never())->method('query');

        $result = $this->makeConnection(LogMode::FetchOnly, logger: $logger)->query(self::SQL, self::PARAMS);

        self::assertInstanceOf(LoggableResult::class, $result);
    }

    #[TestDox('query() логирует ошибку и пробрасывает DatabaseException')]
    public function testQueryLogsDatabaseExceptionAndRethrows(): void
    {
        $dbException = new DatabaseException('db down');
        $this->inner->method('query')->willThrowException($dbException);
        $logger = self::createMock(SqlLoggerInterface::class);

        $logger->expects(self::once())
            ->method('query')
            ->with(self::SQL, self::PARAMS, self::isFloat(), null, 'db down');

        $conn = $this->makeConnection(logger: $logger);

        $this->expectException(DatabaseException::class);
        $conn->query(self::SQL, self::PARAMS);
    }

    #[TestDox('query() оборачивает сторонние исключения в QueryException')]
    public function testQueryWrapsUnknownExceptionIntoQueryException(): void
    {
        $this->inner->method('query')->willThrowException(new \RuntimeException('boom'));
        $logger = self::createMock(SqlLoggerInterface::class);

        $logger->expects(self::once())
            ->method('query')
            ->with(self::SQL, self::PARAMS, self::isFloat(), null, 'boom');

        $conn = $this->makeConnection(logger: $logger);

        $this->expectException(QueryException::class);
        $conn->query(self::SQL, self::PARAMS);
    }

    #[TestDox('execute() логирует количество затронутых строк')]
    public function testExecuteLogsAffectedRows(): void
    {
        $inner = self::createMock(ConnectionInterface::class);
        $inner->expects(self::once())->method('execute')->with(self::SQL, self::PARAMS)->willReturn(5);
        $logger = self::createMock(SqlLoggerInterface::class);

        $logger->expects(self::once())
            ->method('query')
            ->with(self::SQL, self::PARAMS, self::isFloat(), 5, null);

        $result = $this->makeConnection(inner: $inner, logger: $logger)->execute(self::SQL, self::PARAMS);

        self::assertSame(5, $result);
    }

    #[TestDox('execute() логирует ошибку и пробрасывает DatabaseException')]
    public function testExecuteLogsDatabaseExceptionAndRethrows(): void
    {
        $this->inner->method('execute')->willThrowException(new DatabaseException('constraint'));
        $logger = self::createMock(SqlLoggerInterface::class);

        $logger->expects(self::once())
            ->method('query')
            ->with(self::SQL, self::PARAMS, self::isFloat(), null, 'constraint');

        $this->expectException(DatabaseException::class);
        $this->makeConnection(logger: $logger)->execute(self::SQL, self::PARAMS);
    }

    #[TestDox('execute() оборачивает сторонние исключения в QueryException')]
    public function testExecuteWrapsUnknownExceptionIntoQueryException(): void
    {
        $this->inner->method('execute')->willThrowException(new \RuntimeException('boom'));
        $logger = self::createMock(SqlLoggerInterface::class);

        $logger->expects(self::once())
            ->method('query')
            ->with(self::SQL, self::PARAMS, self::isFloat(), null, 'boom');

        $this->expectException(QueryException::class);
        $this->makeConnection(logger: $logger)->execute(self::SQL, self::PARAMS);
    }

    #[TestDox('beginTransaction() делегирует и логирует уровень')]
    public function testBeginTransactionLogsLevel(): void
    {
        $inner = $this->stubConnection(1);

        $logger = self::createMock(SqlLoggerInterface::class);
        $logger->expects(self::once())->method('transactionStarted')->with(1);

        $conn = new LoggableConnection($inner, $logger);
        $conn->beginTransaction();
    }

    #[TestDox('commit() делегирует и логирует уровень')]
    public function testCommitLogsLevel(): void
    {
        $inner = $this->stubConnection(2);

        $logger = self::createMock(SqlLoggerInterface::class);
        $logger->expects(self::once())->method('transactionCommitted')->with(2);

        $conn = new LoggableConnection($inner, $logger);
        $conn->commit();
    }

    #[TestDox('rollBack() логирует уровень до отката')]
    public function testRollBackLogsLevelBeforeRollback(): void
    {
        $inner = $this->stubConnection(3);

        $logger = self::createMock(SqlLoggerInterface::class);
        $logger->expects(self::once())->method('transactionRolledBack')->with(3);

        $conn = new LoggableConnection($inner, $logger);
        $conn->rollBack();
    }

    #[TestDox('transaction() логирует старт и коммит и возвращает результат callback')]
    public function testTransactionLogsStartAndCommitAndReturnsCallbackResult(): void
    {
        $inner = $this->stubConnection(0);

        $logger = self::createMock(SqlLoggerInterface::class);
        $logger->expects(self::once())->method('transactionStarted')->with(1);
        $logger->expects(self::once())->method('transactionCommitted')->with(1);

        $conn = new LoggableConnection($inner, $logger);

        $result = $conn->transaction(static fn() => 'done');

        self::assertSame('done', $result);
    }

    #[TestDox('transaction() логирует старт и откат и пробрасывает DatabaseException')]
    public function testTransactionRollsBackOnDatabaseException(): void
    {
        // inner->transaction бросает DatabaseException (после собственного rollback)
        $inner = new class implements ConnectionInterface {
            public int $transactionLevel {
                get => 0;
            }

            public function query(string $sql, array $params = []): ResultInterface
            {
                throw new \LogicException('not used');
            }

            public function execute(string $sql, array $params = []): int
            {
                throw new \LogicException('not used');
            }

            public function beginTransaction(): void {}

            public function commit(): void {}

            public function rollBack(): void {}

            public function transaction(callable $callback): mixed
            {
                throw new DatabaseException('fail');
            }

            public function isConnected(): bool
            {
                return true;
            }

            public function disconnect(): void {}
        };

        $logger = self::createMock(SqlLoggerInterface::class);
        $logger->expects(self::once())->method('transactionStarted')->with(1);
        $logger->expects(self::once())->method('transactionRolledBack')->with(1);

        $conn = new LoggableConnection($inner, $logger);

        $this->expectException(DatabaseException::class);
        $conn->transaction(static fn() => null);
    }

    #[TestDox('transaction() логирует старт и откат и оборачивает сторонние исключения')]
    public function testTransactionRollsBackAndWrapsUnknownException(): void
    {
        $inner = new class implements ConnectionInterface {
            public int $transactionLevel {
                get => 0;
            }

            public function query(string $sql, array $params = []): ResultInterface
            {
                throw new \LogicException('not used');
            }

            public function execute(string $sql, array $params = []): int
            {
                throw new \LogicException('not used');
            }

            public function beginTransaction(): void {}

            public function commit(): void {}

            public function rollBack(): void {}

            public function transaction(callable $callback): mixed
            {
                throw new \RuntimeException('boom');
            }

            public function isConnected(): bool
            {
                return true;
            }

            public function disconnect(): void {}
        };

        $logger = self::createMock(SqlLoggerInterface::class);
        $logger->expects(self::once())->method('transactionStarted')->with(1);
        $logger->expects(self::once())->method('transactionRolledBack')->with(1);

        $conn = new LoggableConnection($inner, $logger);

        $this->expectException(QueryException::class);
        $conn->transaction(static fn() => null);
    }

    #[TestDox('isConnected() делегирует внутреннему соединению')]
    public function testIsConnectedDelegates(): void
    {
        $this->inner->method('isConnected')->willReturn(true);

        self::assertTrue($this->makeConnection()->isConnected());
    }

    #[TestDox('disconnect() делегирует внутреннему соединению')]
    public function testDisconnectDelegates(): void
    {
        $inner = self::createMock(ConnectionInterface::class);

        $inner->expects(self::once())->method('disconnect');

        $this->makeConnection(inner: $inner)->disconnect();
    }
}
