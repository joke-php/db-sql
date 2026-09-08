<?php

declare(strict_types=1);

namespace Vasoft\Joke\Db\Sql\Tests\Driver\Pdo;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Vasoft\Joke\Db\Sql\Driver\Pdo\Result;
use PHPUnit\Framework\TestCase;
use Vasoft\Joke\Db\Sql\Exceptions\QueryException;

/**
 * @internal
 *
 * @coversDefaultClass \Vasoft\Joke\Db\Sql\Driver\Pdo\Result
 */
#[CoversClass(Result::class)]
#[TestDox('Result - обертка над PDOStatement')]
final class ResultTest extends TestCase
{
    #[TestDox('Метод all возвращает все строки результата')]
    public function testAll(): void
    {
        $expectedData = [
            ['id' => 1, 'name' => 'Alex'],
            ['id' => 2, 'name' => 'Olga'],
        ];

        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())
            ->method('fetchAll')
            ->with(\PDO::FETCH_ASSOC)
            ->willReturn($expectedData);

        $result = new Result($statement);
        self::assertSame($expectedData, $result->all());
    }

    #[TestDox('Метод one возвращает первую строку или null')]
    public function testOne(): void
    {
        $row = ['id' => 1, 'name' => 'Alex'];

        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())
            ->method('fetch')
            ->with(\PDO::FETCH_ASSOC)
            ->willReturn($row);

        $result = new Result($statement);
        self::assertSame($row, $result->fetch());
    }

    #[TestDox('Метод one возвращает null если строк нет')]
    public function testOneEmpty(): void
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())
            ->method('fetch')
            ->with(\PDO::FETCH_ASSOC)
            ->willReturn(false);

        $result = new Result($statement);
        self::assertFalse($result->fetch());
    }

    #[TestDox('Метод count возвращает количество затронутых строк')]
    public function testCount(): void
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())
            ->method('rowCount')
            ->willReturn(5);

        $result = new Result($statement);
        self::assertSame(5, $result->count());
    }

    #[TestDox('Итератор корректно перебирает строки')]
    public function testIterator(): void
    {
        $data = [
            ['id' => 1],
            ['id' => 2],
            ['id' => 3],
        ];

        $statement = $this->createMock(\PDOStatement::class);

        $statement->expects(self::exactly(4)) // 3 строки + 1 раз false в конце
            ->method('fetch')
            ->with(\PDO::FETCH_ASSOC)
            ->willReturnOnConsecutiveCalls(
                $data[0],
                $data[1],
                $data[2],
                false,
            );

        $result = new Result($statement);
        $iteratedData = [];

        foreach ($result as $row) {
            $iteratedData[] = $row;
        }

        self::assertSame($data, $iteratedData);
    }

    #[TestDox('Выбрасывает QueryException при ошибке PDO в all')]
    public function testAllThrowsException(): void
    {
        $statement = self::createStub(\PDOStatement::class);
        $statement->method('fetchAll')
            ->willThrowException(new \PDOException('SQL Error', 100));

        $result = new Result($statement);

        self::expectException(QueryException::class);
        self::expectExceptionMessageIs('SQL Error');

        $result->all();
    }

    #[TestDox('Выбрасывает QueryException при ошибке PDO в one')]
    public function testOneThrowsException(): void
    {
        $statement = self::createStub(\PDOStatement::class);
        $statement->method('fetch')
            ->willThrowException(new \PDOException('Fetch failed', 100));

        $result = new Result($statement);

        self::expectException(QueryException::class);
        self::expectExceptionMessageIs('Fetch failed');

        $result->fetch();
    }

    #[TestDox('Выбрасывает QueryException при ошибке PDO в getIterator')]
    public function testGetIteratorThrowsException(): void
    {
        $statement = self::createStub(\PDOStatement::class);
        $statement->method('fetch')
            ->willThrowException(new \PDOException('Fetch failed', 100));

        $result = new Result($statement);

        self::expectException(QueryException::class);
        self::expectExceptionMessageIs('Fetch failed');
        $rows = [];
        foreach ($result as $row) {
            $rows[] = $row;
        }
    }

    #[TestDox('Выбрасывает QueryException при ошибке PDO в count')]
    public function testCountThrowsException(): void
    {
        $statement = self::createStub(\PDOStatement::class);
        $statement->method('rowCount')
            ->willThrowException(new \PDOException('Count failed', 100));

        $result = new Result($statement);

        self::expectException(QueryException::class);
        self::expectExceptionMessageIs('Count failed');

        $result->count();
    }
}
