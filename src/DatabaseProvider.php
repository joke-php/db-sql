<?php

declare(strict_types=1);

namespace Vasoft\Joke\Db\Sql;

use Vasoft\Joke\Container\ServiceContainer;
use Vasoft\Joke\Provider\AbstractProvider;

/**
 * Сервис-провайдер модуля SQL-баз данных.
 *
 * Регистрирует {@see ConnectionManager} как singleton в DI-контейнере,
 * делая его доступным для внедрения зависимостей во все компоненты приложения.
 */
class DatabaseProvider extends AbstractProvider
{
    public function __construct(
        private readonly ServiceContainer $serviceContainer,
    ) {}

    public function register(): void
    {
        $this->serviceContainer->registerSingleton(ConnectionManager::class, ConnectionManager::class);
    }

    /**
     * @codeCoverageIgnore
     */
    public function boot(): void
    {
        // Нет необходимых действий
    }

    /**
     * @return list<class-string>
     */
    public function provides(): array
    {
        return [ConnectionManager::class];
    }
}
