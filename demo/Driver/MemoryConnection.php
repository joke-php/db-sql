<?php

declare(strict_types=1);

namespace Vasoft\Joke\Db\Sql\Demo\Driver;

use Vasoft\Joke\Db\Sql\Contract\ConnectionInterface;

/**
 * Демонстрационная реализация ConnectionInterface на основе внутреннего массива.
 *
 * Не использует реальную БД.
 * Данные хранятся в памяти, транзакции эмулируются через копию состояния.
 *
 * «SQL» интерпретируется упрощённо: поддерживаются базовые операции SELECT, INSERT, CREATE TABLE.
 */
class MemoryConnection extends BaseConnection implements ConnectionInterface
{
    public static bool $exceptionOnDisconnect = false;

    public function isConnected(): bool
    {
        // JsonConnection всегда «подключён» — данные в памяти
        return true;
    }

    public function disconnect(): void
    {
        if (self::$exceptionOnDisconnect) {
            throw new \Exception('Connection not is disconnected');
        }
    }
}
