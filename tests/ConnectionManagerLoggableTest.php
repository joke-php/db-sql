<?php

declare(strict_types=1);

namespace Vasoft\Joke\Db\Sql\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Vasoft\Joke\Config\Exceptions\ConfigException;
use Vasoft\Joke\Container\Exceptions\ParameterResolveException;
use Vasoft\Joke\Container\ServiceContainer;
use Vasoft\Joke\Db\Sql\ConnectionManager;
use Vasoft\Joke\Db\Sql\Contract\ConnectionInterface;
use Vasoft\Joke\Db\Sql\Contract\ResultInterface;
use Vasoft\Joke\Db\Sql\Contract\SqlLoggerInterface;
use Vasoft\Joke\Db\Sql\DatabaseConfig;
use Vasoft\Joke\Db\Sql\Demo\Driver\MemoryConnection;
use Vasoft\Joke\Db\Sql\Exceptions\ConnectionException;
use Vasoft\Joke\Db\Sql\Logger\LoggableConnection;
use Vasoft\Joke\Db\Sql\Logger\LogMode;

/**
 * @internal
 *
 * @coversDefaultClass \Vasoft\Joke\Db\Sql\ConnectionManager
 */
#[CoversClass(ConnectionManager::class)]
#[TestDox('ConnectionManager - запрос логируемых соединений')]
final class ConnectionManagerLoggableTest extends TestCase
{
    private DatabaseConfig $config;
    private ServiceContainer $container;
    private SqlLoggerInterface $logger;

    protected function setUp(): void
    {
        $this->config = self::createStub(DatabaseConfig::class);
        $this->container = self::createStub(ServiceContainer::class);
        $this->logger = self::createStub(SqlLoggerInterface::class);
    }

    private function makeManager(?DatabaseConfig $config = null, ?ServiceContainer $container = null): ConnectionManager
    {
        return new ConnectionManager($config ?? $this->config, $container ?? $this->container);
    }

    #[TestDox('loggable() оборачивает зарегистрированный экземпляр соединения в LoggableConnection')]
    public function testLoggableWrapsRegisteredInstance(): void
    {
        $connection = self::createStub(ConnectionInterface::class);
        $config = self::createMock(DatabaseConfig::class);

        $config->expects(self::once())->method('getConnection')->with('main')->willReturn($connection);

        $result = $this->makeManager($config)->loggable($this->logger, 'main', LogMode::FetchOnly);

        self::assertInstanceOf(LoggableConnection::class, $result);
    }

    #[TestDox('loggable() с пустым именем использует подключение по умолчанию')]
    public function testLoggableWithEmptyNameUsesDefaultConnection(): void
    {
        $connection = self::createStub(ConnectionInterface::class);
        $config = self::createMock(DatabaseConfig::class);
        $manager = new ConnectionManager($config, $this->container);

        $config->expects(self::once())->method('getConnection')->with('')->willReturn($connection);

        $result = $manager->loggable($this->logger);

        self::assertInstanceOf(LoggableConnection::class, $result);
    }

    #[TestDox('loggable() передаёт логер и режим логирования в декоратор')]
    public function testLoggablePassesLoggerAndMode(): void
    {
        $logger = self::createMock(SqlLoggerInterface::class);
        $connection = self::createMock(ConnectionInterface::class);
        $this->config->method('getConnection')->willReturn($connection);

        $result = $this->makeManager()->loggable($logger, 'main', LogMode::ExecutionOnly);

        self::assertInstanceOf(LoggableConnection::class, $result);

        // Проверяем поведение декоратора через реальный вызов: он должен использовать наш логер
        // (например, query() в режиме ExecutionOnly залогирует время исполнения)
        $sql = 'SELECT 1';
        $logger->expects(self::once())
            ->method('query')
            ->with($sql, [], self::isFloat(), null, null);

        // репозиторий результата
        $resultInterface = self::createStub(ResultInterface::class);
        $connection->expects(self::once())->method('query')->with($sql, [])->willReturn($resultInterface);

        $result->query($sql);
    }

    #[TestDox('loggable() кэширует реальную сессию между обычным и логируемым доступом')]
    public function testLoggableSharesCachedSessionWithConnection(): void
    {
        $connection = self::createStub(ConnectionInterface::class);
        $this->config->addConnection('default', $connection);
        $config = self::createMock(DatabaseConfig::class);
        $manager = new ConnectionManager($config, $this->container);

        // getConnection вызывается один раз (кэш в ConnectionManager работает)
        $config->expects(self::once())->method('getConnection')->with('')->willReturn($connection);

        $plain = $manager->connection('');        // первый вызов — создаёт и кэширует
        $wrapped = $manager->loggable($this->logger); // второй — берёт из кэша

        // вторая "логируемая" обёртка поверх ТОЙ ЖЕ закэшированной сессии
        self::assertNotSame($plain, $wrapped);
        self::assertInstanceOf(LoggableConnection::class, $wrapped);
    }

    #[TestDox('loggable() создаёт соединение через DI-контейнер по имени класса')]
    public function testLoggableCreatesViaContainerFromClassName(): void
    {
        $connection = self::createStub(ConnectionInterface::class);
        $container = self::createMock(ServiceContainer::class);
        $config = self::createMock(DatabaseConfig::class);
        $config->addConnection('default', MemoryConnection::class);

        // конфигурация возвращает имя класса (строку), контейнер его инстанцирует
        $config->expects(self::once())->method('getConnection')->with('')->willReturn(MemoryConnection::class);
        $container->expects(self::once())->method('make')->with(MemoryConnection::class)->willReturn($connection);

        $result = $this->makeManager($config, $container)->loggable($this->logger);

        self::assertInstanceOf(LoggableConnection::class, $result);
    }

    #[TestDox('loggable() создаёт соединение через callable-фабрику')]
    public function testLoggableCreatesViaCallableFactory(): void
    {
        $config = new DatabaseConfig();
        $config->addConnection('factory', static fn() => new MemoryConnection());
        $manager = new ConnectionManager($config, new ServiceContainer());

        $result = $manager->loggable($this->logger, 'factory');

        self::assertInstanceOf(LoggableConnection::class, $result);
    }

    #[TestDox('loggable() пробрасывает ConnectionException при некорректном объекте')]
    public function testLoggableThrowsConnectionExceptionWhenNotImplementingInterface(): void
    {
        $config = new DatabaseConfig();
        $config->addConnection('wrong', \stdClass::class);
        $manager = new ConnectionManager($config, new ServiceContainer());

        $this->expectException(ConnectionException::class);

        $manager->loggable($this->logger);
    }

    #[TestDox('loggable() пробрасывает ConfigException при неизвестном подключении')]
    public function testLoggablePropagatesConfigException(): void
    {
        $config = new DatabaseConfig();
        $config->addConnection('factory', static fn() => new MemoryConnection());
        $manager = new ConnectionManager($config, new ServiceContainer());

        $this->expectException(ConfigException::class);

        $manager->loggable($this->logger, 'unknown');
    }

    #[TestDox('loggable() пробрасывает ParameterResolveException при ошибке DI')]
    public function testLoggablePropagatesParameterResolveException(): void
    {
        $config = new DatabaseConfig();
        $config->addConnection('factory', static fn(UnknownClass $manager) => new MemoryConnection());
        $manager = new ConnectionManager($config, new ServiceContainer());
        $this->expectException(ParameterResolveException::class);

        $manager->loggable($this->logger);
    }
}
