<?php

declare(strict_types=1);

namespace Vasoft\Joke\Db\Sql\Contract;

use Vasoft\Joke\Contract\Logging\LoggerInterface;

/**
 * Логирование активности базы данных.
 *
 * Реализации этого интерфейса получают уведомления о выполненных SQL-запросах
 * и событиях управления транзакциями.
 *
 * Для полноценного использования в качестве системного логгера реализации рекомендуется
 * также имплементировать интерфейс {@see LoggerInterface}.
 */
interface SqlLoggerInterface
{
    /**
     * Вызывается после выполнения SQL-запроса (SELECT/INSERT/UPDATE/DELETE).
     *
     * ВАЖНО: Реализация логгера несет ответственность за обработку чувствительных данных
     * (PII, пароли, токены, номера карт и т.д.) перед записью в логи.
     * Рекомендуется использовать маскирование или удаление таких значений из $params.
     *
     * @param non-empty-string     $sql        Выполненный SQL-запрос (с плейсхолдерами или подставленными значениями, зависит от драйвера)
     * @param array<string, mixed> $params     Переданные параметры с реальными значениями
     * @param null|float           $durationMs Длительность выполнения запроса на стороне сервера в миллисекундах или null, если не измерялась
     * @param null|int             $rows       Число затронутых строк. Для INSERT/UPDATE/DELETE — точное число.
     *                                         Для SELECT — null (количество строк фиксируется отдельно при чтении через LoggableResult)
     * @param null|string          $error      Текст ошибки или null при успешном выполнении
     */
    public function query(string $sql, array $params, ?float $durationMs, ?int $rows, ?string $error): void;

    /**
     * Вызывается при начале новой транзакции.
     *
     * @param int $level Текущий уровень вложенности транзакции (начинается с 1)
     */
    public function transactionStarted(int $level): void;

    /**
     * Вызывается при успешном завершении (фиксации) транзакции.
     *
     * @param int $level Уровень транзакции, который был завершен
     */
    public function transactionCommitted(int $level): void;

    /**
     * Вызывается при откате транзакции.
     *
     * @param int $level Уровень транзакции, который был отменен
     */
    public function transactionRolledBack(int $level): void;
}
