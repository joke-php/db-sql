<?php

declare(strict_types=1);

namespace Vasoft\Joke\Db\Sql\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Vasoft\Joke\Container\ServiceContainer;
use Vasoft\Joke\Db\Sql\ConnectionManager;
use Vasoft\Joke\Db\Sql\DatabaseProvider;

/**
 * @internal
 *
 * @coversDefaultClass \Vasoft\Joke\Db\Sql\DatabaseProvider
 */
#[CoversClass(DatabaseProvider::class)]
#[TestDox('DatabaseProvider - провайдер модуля')]
final class DatabaseProviderTest extends TestCase
{
    #[TestDox('Сообщает о предоставляемых сервисах')]
    public function testProvide(): void
    {
        $container = new ServiceContainer();
        $provider = new DatabaseProvider($container);
        self::assertSame([ConnectionManager::class], $provider->provides());
    }

    #[TestDox('Регистрирует необходимые сервисы')]
    public function testRegister(): void
    {
        $container = new ServiceContainer();
        $provider = new DatabaseProvider($container);
        $provider->register();
        self::assertTrue($container->has(ConnectionManager::class));
    }
}
