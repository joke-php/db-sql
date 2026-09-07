<?php

declare(strict_types=1);

namespace Vasoft\Joke\Db\Sql\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Vasoft\Joke\Config\Exceptions\ConfigException;
use Vasoft\Joke\Container\ServiceContainer;
use Vasoft\Joke\Db\Sql\ConnectionManager;
use Vasoft\Joke\Db\Sql\DatabaseConfig;
use Vasoft\Joke\Db\Sql\Demo\Driver\JsonConnection;
use Vasoft\Joke\Db\Sql\Demo\Driver\MemoryConnection;
use Vasoft\Joke\Db\Sql\Exceptions\ConnectionException;
use Vasoft\Joke\Support\FileSystem;

/**
 * @internal
 *
 * @coversDefaultClass \Vasoft\Joke\Db\Sql\ConnectionManager
 */
#[CoversClass(ConnectionManager::class)]
#[TestDox('ConnectionManager - менеджер соединений с базами данных')]
final class ConnectionManagerTest extends TestCase
{
    #[TestDox('Конструктор замораживает конфиг')]
    public function testFreezeConfig(): void
    {
        $config = new DatabaseConfig();
        $config->addConnection('default', MemoryConnection::class);
        new ConnectionManager($config, new ServiceContainer());
        self::assertTrue($config->isFrozen());
    }

    #[TestDox('Использует различные типы описывающие подключение')]
    public function testConnectionVariants(): void
    {
        $entity = new MemoryConnection();
        $config = new DatabaseConfig()
            ->addConnection('example1', MemoryConnection::class)
            ->addConnection('example2', $entity)
            ->addConnection('example3', static fn() => new MemoryConnection());

        $manager = new ConnectionManager($config, new ServiceContainer());


        self::assertInstanceOf(
            MemoryConnection::class,
            $manager->connection('example1'),
            'Не создал экземпляр из имени класса',
        );
        self::assertInstanceOf(
            MemoryConnection::class,
            $manager->connection('example3'),
            'Не создал экземпляр из замыкания',
        );
        self::assertSame(
            $entity,
            $manager->connection('example2'),
            'Не вернул экземпляр',
        );
    }

    #[TestDox('Экземпляр создается с использованием контейнера')]
    public function testAutowiringUsed(): void
    {
        $di = new ServiceContainer();
        $di->registerSingleton(FileSystem::class, new FileSystem(__DIR__));
        $config = new DatabaseConfig()
            ->addConnection('example1', JsonConnection::class)
            ->addConnection('example3', static fn(FileSystem $fs) => new JsonConnection($fs));

        $manager = new ConnectionManager($config, $di);

        self::assertInstanceOf(
            JsonConnection::class,
            $manager->connection('example1'),
            'Не создал экземпляр из имени класса',
        );
        self::assertInstanceOf(
            JsonConnection::class,
            $manager->connection('example3'),
            'Не создал экземпляр из замыкания',
        );
    }

    #[TestDox('Менеджер кеширует подключения')]
    public function testCaching(): void
    {
        $config = new DatabaseConfig()
            ->addConnection('example1', MemoryConnection::class);

        $manager = new ConnectionManager($config, new ServiceContainer());
        $entity1 = $manager->connection('example1');
        $entity2 = $manager->connection('example1');

        self::assertSame($entity1, $entity2);
    }
    #[TestDox('Сброс закешированных подключений')]
    public function testReset(): void
    {
        $config = new DatabaseConfig()
            ->addConnection('example1', MemoryConnection::class);

        $manager = new ConnectionManager($config, new ServiceContainer());
        $entity1 = $manager->connection('example1');
        $manager->reset();
        $entity2 = $manager->connection('example1');

        self::assertNotSame($entity1, $entity2);
    }

    #[TestDox('Возвращает подключение по умолчанию при пустом имени')]
    public function testDefaultConnection(): void
    {
        $config = new DatabaseConfig();
        $config->addConnection('other', MemoryConnection::class);
        $config->addConnection('main', MemoryConnection::class);
        $config->setDefaultConnectionName('main');

        $manager = new ConnectionManager($config, new ServiceContainer());

        $default = $manager->connection();
        $explicit = $manager->connection('main');

        self::assertSame($default, $explicit, 'Не возвращается подключение по умолчанию');
    }

    #[TestDox('Выбрасывает исключение для незарегистрированного подключения')]
    public function testNonExistentConnection(): void
    {
        $config = new DatabaseConfig();
        $config->addConnection('existing', MemoryConnection::class);

        $manager = new ConnectionManager($config, new ServiceContainer());

        self::expectException(ConfigException::class);
        self::expectExceptionMessageIs("Connection 'nonexistent' is not registered.");

        $manager->connection('nonexistent');
    }

    #[TestDox('Выбрасывает исключение если класс не реализует ConnectionInterface')]
    public function testWrongConnectionClass(): void
    {
        $config = new DatabaseConfig()
            ->addConnection('example1', \stdClass::class);
        $manager = new ConnectionManager($config, new ServiceContainer());

        self::expectException(ConnectionException::class);
        self::expectExceptionMessageIs(
            "Connection 'example1' does not implement 'Vasoft\\Joke\\Db\\Sql\\Contract\\ConnectionInterface'.",
        );
        $manager->connection('example1');
    }
}
