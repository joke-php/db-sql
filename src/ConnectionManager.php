<?php

declare(strict_types=1);

namespace Vasoft\Joke\Db\Sql;

use Vasoft\Joke\Config\Exceptions\ConfigException;
use Vasoft\Joke\Container\Exceptions\ParameterResolveException;
use Vasoft\Joke\Container\ServiceContainer;
use Vasoft\Joke\Db\Sql\Contract\ConnectionInterface;
use Vasoft\Joke\Db\Sql\Exceptions\ConnectionException;

/**
 * Менеджер подключений к базе данных.
 *
 * Управляет созданием и кэшированием экземпляров подключений.
 * Поддерживает три способа регистрации подключений в конфигурации:
 * - готовый экземпляр {@see ConnectionInterface}
 * - имя класса, реализующего {@see ConnectionInterface}
 * - callable-фабрика, возвращающая {@see ConnectionInterface}
 */
class ConnectionManager
{
    /**
     * @var array<string, ConnectionInterface> Кэш созданных экземпляров подключений
     */
    private array $instances = [];

    /**
     * @param DatabaseConfig   $config    Конфигурация подключений
     * @param ServiceContainer $container DI-контейнер для создания экземпляров из имён классов
     *
     * @throws ConfigException Если нет зарегистрированных подключений
     */
    public function __construct(
        private readonly DatabaseConfig $config,
        private readonly ServiceContainer $container,
    ) {
        $this->config->freeze();
    }

    /**
     * Возвращает экземпляр подключения по имени.
     *
     * Если подключение уже было создано ранее, возвращается закэшированный экземпляр.
     * В противном случае создаётся новый экземпляр на основе конфигурации:
     * - если зарегистрирован готовый экземпляр — используется он
     * - если зарегистрировано имя класса — создаётся через DI-контейнер
     * - если зарегистрирована callable-фабрика — вызывается для получения экземпляра
     *
     * @param string $connectionName Имя подключения. Пустая строка означает использование подключения по умолчанию
     *
     * @return ConnectionInterface Экземпляр подключения к базе данных
     *
     * @throws ConnectionException       Если полученный объект не реализует {@see ConnectionInterface}
     * @throws ConfigException           Если подключение с указанным именем не зарегистрировано в конфигурации
     * @throws ParameterResolveException При ошибках разрешения зависимостей через DI-контейнер
     *
     * @see DatabaseConfig::getConnection()
     * @see DatabaseConfig::registerConnection()
     */
    public function connection(string $connectionName = ''): ConnectionInterface
    {
        $name = '' === trim($connectionName) ? $this->config->defaultConnectionName : $connectionName;
        if (array_key_exists($name, $this->instances)) {
            return $this->instances[$name];
        }
        $factory = $this->config->getConnection($connectionName);
        if ($factory instanceof ConnectionInterface) {
            $this->instances[$name] = $factory;

            return $this->instances[$name];
        }
        $connection = $this->container->make($factory);
        if ($connection instanceof ConnectionInterface) {
            $this->instances[$name] = $connection;

            return $this->instances[$name];
        }

        throw new ConnectionException(
            "Connection '{$name}' does not implement '" . ConnectionInterface::class . "'.",
        );
    }
}
