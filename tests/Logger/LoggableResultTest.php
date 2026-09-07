<?php

declare(strict_types=1);

namespace Vasoft\Joke\Db\Sql\Tests\Logger;

use PHPUnit\Framework\Attributes\CoversClass;
use Vasoft\Joke\Db\Sql\Logger\LoggableResult;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\TestDox;
use Vasoft\Joke\Db\Sql\Contract\ResultInterface;
use Vasoft\Joke\Db\Sql\Contract\SqlLoggerInterface;

/**
 * @internal
 *
 * @coversDefaultClass \Vasoft\Joke\Db\Sql\Logger\LoggableResult
 */
#[TestDox('LoggableResult - декоратор логирующий выборку')]
#[CoversClass(LoggableResult::class)]
final class LoggableResultTest extends TestCase
{
    private const string SQL = 'SELECT * FROM users WHERE id IN (:ids)';
    private const array PARAMS = ['ids' => [1, 2, 3]];

    private ResultInterface $inner;
    private SqlLoggerInterface $logger;

    protected function setUp(): void
    {
        $this->inner = self::createStub(ResultInterface::class);
        $this->logger = $this->createMock(SqlLoggerInterface::class);
    }

    private function makeResult(?ResultInterface $inner = null, ?SqlLoggerInterface $logger = null): LoggableResult
    {
        return new LoggableResult($inner ?? $this->inner, $logger ?? $this->logger, self::SQL, self::PARAMS);
    }

    #[TestDox('all() логирует количество строк и длительность выборки')]
    public function testAllLogsRowCountAndDuration(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'Olga'],
            ['id' => 2, 'name' => 'Alex'],
        ];
        $this->inner->method('all')->willReturn($rows);

        $this->logger->expects(self::once())
            ->method('query')
            ->with(self::SQL, self::PARAMS, self::isFloat(), 2, null);

        $result = $this->makeResult();
        self::assertSame($rows, $result->all());
    }

    #[TestDox('one() логирует одну найденную строку')]
    public function testOneLogsSingleRow(): void
    {
        $this->inner->method('one')->willReturn(['id' => 1]);

        $this->logger->expects(self::once())
            ->method('query')
            ->with(self::SQL, self::PARAMS, self::isFloat(), 1, null);

        $result = $this->makeResult();
        self::assertSame(['id' => 1], $result->one());
    }

    #[TestDox('one() логирует ноль строк, если строка не найдена')]
    public function testOneReturnsFalseLogsZero(): void
    {
        $this->inner->method('one')->willReturn(false);

        $this->logger->expects(self::once())
            ->method('query')
            ->with(self::SQL, self::PARAMS, self::isFloat(), 0, null);

        $result = $this->makeResult();
        self::assertFalse($result->one());
    }

    #[TestDox('count() логирует количество строк')]
    public function testCountLogsRowCount(): void
    {
        $this->inner->method('count')->willReturn(42);

        $this->logger->expects(self::once())
            ->method('query')
            ->with(self::SQL, self::PARAMS, self::isFloat(), 42, null);

        $result = $this->makeResult();
        self::assertSame(42, $result->count());
    }

    #[TestDox('итерация логирует результат после полного прохода')]
    public function testIteratorLogsAfterFullIteration(): void
    {
        $this->inner->method('getIterator')
            ->willReturn(
                new \ArrayIterator([
                    ['id' => 1],
                    ['id' => 2],
                    ['id' => 3],
                ]),
            );

        $this->logger->expects(self::once())
            ->method('query')
            ->with(self::SQL, self::PARAMS, self::isFloat(), 3, null);

        $result = $this->makeResult();
        $collected = [];
        foreach ($result as $row) {
            $collected[] = $row;
        }

        self::assertCount(3, $collected);
    }

    #[TestDox('итерация логирует результат даже при досрочном выходе (break)')]
    public function testIteratorLogsEvenWhenBrokenEarly(): void
    {
        $this->inner->method('getIterator')
            ->willReturn(
                new \ArrayIterator([
                    ['id' => 1],
                    ['id' => 2],
                    ['id' => 3],
                ]),
            );

        // finally должен сработать при досрочном выходе по break
        $this->logger->expects(self::once())
            ->method('query')
            ->with(self::SQL, self::PARAMS, self::isFloat(), 1, null);

        $result = $this->makeResult();
        foreach ($result as $row) {
            break; // выходим после первой строки
        }
    }

    #[TestDox('итерация логирует результат, даже если внутренний генератор бросает исключение')]
    public function testIteratorLogsEvenWhenInnerThrows(): void
    {
        $innerIterator = (static function () {
            yield ['id' => 1];

            throw new \RuntimeException('boom');
        })();

        $this->inner->method('getIterator')->willReturn($innerIterator);

        $this->logger->expects(self::once())
            ->method('query')
            ->with(self::SQL, self::PARAMS, self::isFloat(), 1, null);

        $result = $this->makeResult();

        $this->expectException(\RuntimeException::class);
        foreach ($result as $_row) {
            // исключение пробрасывается из генератора наружу
        }
    }

    #[TestDox('логирование происходит только один раз при множественных чтениях')]
    public function testLoggingHappensOnlyOnce(): void
    {
        $this->inner->method('all')->willReturn([['id' => 1], ['id' => 2]]);
        $this->inner->method('one')->willReturn(['id' => 1]);
        $this->inner->method('count')->willReturn(5);

        // несколько чтений -> логер вызывается только один раз
        $this->logger->expects(self::once())
            ->method('query')
            ->with(self::SQL, self::PARAMS, self::isFloat(), 2, null);

        $result = $this->makeResult();
        $result->all();
        $result->one();
        $result->count();
    }

    #[TestDox('неиспользованный декоратор не пишет в лог при уничтожении')]
    public function testDestructWithoutAnyReadingDoesNotLog(): void
    {
        // Ничего не читали -> touched === false, деструктор не должен логировать
        $this->logger->expects(self::never())->method('query');

        $result = $this->makeResult();
        unset($result);
    }

    #[TestDox('созданный, но не запущенный итератор логирует 0 строк при уничтожении')]
    public function testCreatedIteratorButNeverIteratedLogsZeroRowsOnDestruct(): void
    {
        $this->inner->method('getIterator')
            ->willReturn(
                new \ArrayIterator([
                    ['id' => 1],
                    ['id' => 2],
                ]),
            );

        $this->logger->expects(self::once())
            ->method('query')
            ->with(self::SQL, self::PARAMS, self::isFloat(), 0, null);

        $result = $this->makeResult();
        $result->getIterator(); // создали итератор, но не начали чтение
        unset($result);
    }

    #[TestDox('частичная итерация логируется через страховку деструктора')]
    public function testPartialIterationThenUnsetLogsViaDestructorSafetyNet(): void
    {
        $this->inner->method('getIterator')
            ->willReturn(
                new \ArrayIterator([
                    ['id' => 1],
                    ['id' => 2],
                ]),
            );

        $this->logger->expects(self::once())
            ->method('query')
            ->with(self::SQL, self::PARAMS, self::isFloat(), 1, null);

        $result = $this->makeResult();
        $iter = $result->getIterator();
        $iter->current(); // запустили генератор, считали одну строку, затем уничтожаем
        unset($iter, $result);
    }
}
