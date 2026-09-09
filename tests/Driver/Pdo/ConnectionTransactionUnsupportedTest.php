<?php

declare(strict_types=1);

namespace Vasoft\Joke\Db\Sql\Tests\Driver\Pdo;

use Vasoft\Joke\Db\Sql\Driver\Pdo\Connection;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Vasoft\Joke\Db\Sql\Exceptions\QueryException;
use Vasoft\Joke\Db\Sql\TransactionSupportPolicy;

/**
 * @internal
 *
 * @coversDefaultClass \Vasoft\Joke\Db\Sql\Driver\Pdo\Connection
 */
#[CoversClass(Connection::class)]
#[TestDox('Connection: поведение при отсутствии поддержки транзакций')]
final class ConnectionTransactionUnsupportedTest extends TestCase
{
    /**
     * Создаёт Connection, который бросает исключение на beginTransaction().
     */
    private function createConnection(
        TransactionSupportPolicy $policy,
    ): Connection {
        $connection = new Connection(
            driver: 'mysql',
            database: 'test',
            host: 'localhost',
        );
        $connection->setTransactionSupportPolicy($policy);

        $pdo = self::createStub(\PDO::class);
        $pdo->method('beginTransaction')
            ->willThrowException(new \PDOException('This driver does not support transactions'));
        $pdo->method('setAttribute')->willReturn(true);

        $ref = new \ReflectionProperty(Connection::class, 'pdo');
        $ref->setValue($connection, $pdo);

        $refCache = new \ReflectionProperty(Connection::class, 'supportsTransactions');
        $refCache->setValue($connection, null);

        return $connection;
    }

    #[TestDox('REQUIRED: beginTransaction() выбрасывает QueryException')]
    public function testRequiredBeginTransactionThrows(): void
    {
        $connection = $this->createConnection(TransactionSupportPolicy::REQUIRED);

        $this->expectException(QueryException::class);

        $connection->beginTransaction();
    }

    #[TestDox('OPTIONAL: beginTransaction() выполняется без ошибок')]
    public function testOptionalBeginTransaction(): void
    {
        $connection = $this->createConnection(TransactionSupportPolicy::OPTIONAL);

        $connection->beginTransaction();

        self::assertSame(1, $connection->transactionLevel);
    }

    #[TestDox('OPTIONAL: commit() после beginTransaction() выполняется без ошибок')]
    public function testOptionalCommitAfterBegin(): void
    {
        $connection = $this->createConnection(TransactionSupportPolicy::OPTIONAL);

        $connection->beginTransaction();
        $connection->commit();

        self::assertSame(0, $connection->transactionLevel);
    }

    #[TestDox('OPTIONAL: rollBack() после beginTransaction() выполняется без ошибок')]
    public function testOptionalRollBackAfterBegin(): void
    {
        $connection = $this->createConnection(TransactionSupportPolicy::OPTIONAL);

        $connection->beginTransaction();
        $connection->rollBack();

        self::assertSame(0, $connection->transactionLevel);
    }

    #[TestDox('OPTIONAL: commit() без beginTransaction() выбрасывает QueryException')]
    public function testOptionalCommitWithoutBeginThrows(): void
    {
        $connection = $this->createConnection(TransactionSupportPolicy::OPTIONAL);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageIs('No active transaction.');

        $connection->commit();
    }

    #[TestDox('OPTIONAL: rollBack() без beginTransaction() выбрасывает QueryException')]
    public function testOptionalRollBackWithoutBeginThrows(): void
    {
        $connection = $this->createConnection(TransactionSupportPolicy::OPTIONAL);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageIs('No active transaction.');

        $connection->rollBack();
    }

    #[TestDox('OPTIONAL: вложенные транзакции корректно ведут счётчик уровня')]
    public function testOptionalNestedTransactionsLevelTracking(): void
    {
        $connection = $this->createConnection(TransactionSupportPolicy::OPTIONAL);

        $connection->beginTransaction();
        self::assertSame(1, $connection->transactionLevel);

        $connection->beginTransaction();
        self::assertSame(2, $connection->transactionLevel);

        $connection->commit();
        self::assertSame(1, $connection->transactionLevel);

        $connection->rollBack();
        self::assertSame(0, $connection->transactionLevel);
    }

    #[TestDox('OPTIONAL: transaction() выполняет callback без обёртки в транзакцию')]
    public function testOptionalTransactionExecutesCallbackDirectly(): void
    {
        $connection = $this->createConnection(TransactionSupportPolicy::OPTIONAL);

        $executed = false;
        $result = $connection->transaction(function (Connection $conn) use (&$executed): string {
            $executed = true;
            $this->assertSame(0, $conn->transactionLevel);

            return 'ok';
        });

        self::assertTrue($executed);
        self::assertSame('ok', $result);
        self::assertSame(0, $connection->transactionLevel);
    }

    #[TestDox('OPTIONAL: transaction() пробрасывает исключение из callback без обёртки')]
    public function testOptionalTransactionRethrowsCallbackException(): void
    {
        $connection = $this->createConnection(TransactionSupportPolicy::OPTIONAL);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIs('callback error');

        $connection->transaction(static function (): never {
            throw new \RuntimeException('callback error');
        });
    }

    #[TestDox('disconnect() сбрасывает уровень транзакций и кэш поддержки')]
    public function testDisconnectResetsState(): void
    {
        $connection = $this->createConnection(TransactionSupportPolicy::OPTIONAL);

        $connection->beginTransaction();
        self::assertSame(1, $connection->transactionLevel);

        $connection->disconnect();

        self::assertSame(0, $connection->transactionLevel);
        self::assertFalse($connection->isConnected());
    }
}
