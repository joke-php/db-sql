<?php

declare(strict_types=1);

namespace Vasoft\Joke\Db\Sql;

use Vasoft\Joke\Config\AbstractConfig;
use Vasoft\Joke\Db\Sql\Contract\ConnectionInterface;
use Vasoft\Joke\Config\Exceptions\ConfigException;

/**
 * Конфигурация модуля базы данных.
 *
 * Управляет коллекцией подключений и определяет подключение по умолчанию.
 * Поддерживает три способа регистрации подключения:
 * - Готовый экземпляр {@see ConnectionInterface}.
 * - Имя класса, реализующего {@see ConnectionInterface} (создаётся через DI-контейнер).
 * - Фабричный callable, возвращающий {@see ConnectionInterface}.
 *
 * Если подключение по умолчанию не задано явно, им становится первое зарегистрированное подключение.
 */
class DatabaseConfig extends AbstractConfig
{
    /**
     *  Коллекция зарегистрированных подключений.
     *
     *  Ключ — уникальное имя подключения (non-empty-string).
     *  Значение — объект подключения, имя класса или фабрика.
     *
     * @var array<non-empty-string, callable():ConnectionInterface|class-string<ConnectionInterface>|ConnectionInterface> Коллекция подключений
     */
    private array $connections = [];
    /**
     * Имя подключения, используемого по умолчанию.
     *
     * Пустая строка означает, что подключение по умолчанию ещё не определено.
     * Будет автоматически установлено при {@see freeze()}, если не задано явно.
     */
    public private(set) string $defaultConnectionName = '';

    /**
     * Устанавливает имя подключения по умолчанию.
     *
     * @param non-empty-string $connectionName имя ранее зарегистрированного подключения
     *
     * @return $this
     *
     * @throws ConfigException если конфигурация уже заморожена или соединение с заданным именем не зарегистрировано
     */
    public function setDefaultConnectionName(string $connectionName): static
    {
        $this->guard();
        if (!array_key_exists($connectionName, $this->connections)) {
            throw new ConfigException(
                "Connection '{$connectionName}' is not registered. Cannot set as default.",
            );
        }
        $this->defaultConnectionName = $connectionName;

        return $this;
    }

    /**
     *  Регистрирует подключение в коллекции.
     *
     * После заморозки конфигурации добавлять соединения возможно
     * Повторная регистрация с тем же именем перезаписывает предыдущее значение. После заморозки замена подключения по умолчанию запрещена.
     *
     * @param non-empty-string                                                                     $name       Наименование подключения
     * @param callable():ConnectionInterface|class-string<ConnectionInterface>|ConnectionInterface $connection Объект подключения, фабрика создающая подключение или имя класса подключения
     *
     * @return $this
     *
     * @throws ConfigException При попытке заменить подключение по умолчанию после замораживания конфигурации
     */
    public function addConnection(
        string $name,
        string|callable|ConnectionInterface $connection,
    ): static {
        if ($this->isFrozen() && $name === $this->defaultConnectionName) {
            throw new ConfigException("Cannot replace the default connection '{$name}' after configuration is frozen.");
        }
        $this->connections[$name] = $connection;

        return $this;
    }

    /**
     * Замораживает конфигурацию, делая её неизменяемой.
     *
     * Если подключение по умолчанию не было задано явно, подключением по умолчанию становится первое зарегистрированное подключение.
     *
     * @throws ConfigException Если не зарегистрировано ни одно подключение
     */
    public function freeze(): static
    {
        if (empty($this->connections)) {
            throw new ConfigException(
                'At least one database connection must be registered before freezing the configuration.',
            );
        }
        if ('' === $this->defaultConnectionName) {
            $this->defaultConnectionName = array_key_first($this->connections);
        }

        return parent::freeze();
    }

    /**
     * Возвращает зарегистрированное подключение по имени.
     *
     * Возвращает значение в том виде, в котором оно было зарегистрировано:
     * экземпляр {@see ConnectionInterface}, имя класса или фабричный callable.
     * Создание экземпляра из класса/callable — ответственность вызывающего кода
     * (например, {@see ConnectionManager}).
     *
     * @param string $connectionName имя зарегистрированного подключения
     *
     * @return callable():ConnectionInterface|class-string<ConnectionInterface>|ConnectionInterface
     *
     * @throws ConfigException если подключение с указанным именем не зарегистрировано
     */
    public function getConnection(string $connectionName = ''): string|callable|ConnectionInterface
    {
        $name = '' !== $connectionName ? $connectionName : $this->defaultConnectionName;
        if (!array_key_exists($name, $this->connections)) {
            if ('' === $name) {
                throw new ConfigException(
                    'Default connection is not configured. Register at least one connection or set default explicitly.',
                );
            }

            throw new ConfigException("Connection '{$name}' is not registered.");
        }

        return $this->connections[$name];
    }
}
