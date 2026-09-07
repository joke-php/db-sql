<?php

declare(strict_types=1);

namespace Vasoft\Joke\Db\Sql\Logger;

use Vasoft\Joke\Db\Sql\Contract\SqlLoggerInterface;
use Vasoft\Joke\Db\Sql\Contract\ResultInterface;

/**
 * Декоратор Result, логирующий фактическую выборку строк.
 *
 * PDO::query() возвращает объект результата сразу, но реальные данные извлекаются
 * из курсора только при вызове all(), one(), count() или итерации.
 * Этот декоратор фиксирует длительность именно процесса чтения данных и их преобразования в PHP.
 *
 * ВАЖНО: Курсор PDOStatement является одноразовым (forward-only).
 * Повторное чтение после полного извлечения данных вернет пустой результат.
 * Логирование происходит однократно при первом полном чтении или уничтожении объекта.
 */
final class LoggableResult implements ResultInterface
{
    private bool $logged = false;
    private bool $touched = false;
    private ?int $rowCount = null;
    private ?float $durationMs = null;

    /**
     * @param ResultInterface      $inner  Объект результата запроса
     * @param SqlLoggerInterface   $logger Логер
     * @param non-empty-string     $sql    SQL-запрос с плейсхолдерами
     * @param array<string, mixed> $params Параметры для привязки
     */
    public function __construct(
        private readonly ResultInterface $inner,
        private readonly SqlLoggerInterface $logger,
        private readonly string $sql,
        private readonly array $params,
    ) {}

    /**
     * Возвращает все строки результата в виде массива.
     *
     * Логирует время полной выборки всех строк из курсора.
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $this->touched = true;
        $start = hrtime(true);
        $rows = $this->inner->all();
        $this->log(count($rows), $this->elapsedMs($start));

        return $rows;
    }

    /**
     * Возвращает первую строку результата или false, если строк нет.
     *
     * Логирует время выборки одной строки.
     *
     * @return array<string,mixed>|false
     */
    public function one(): false|array
    {
        $this->touched = true;
        $start = hrtime(true);
        $row = $this->inner->one();
        $this->log(false === $row ? 0 : 1, $this->elapsedMs($start));

        return $row;
    }

    /**
     * Возвращает количество строк в результате.
     *
     * ВНИМАНИЕ: Эта реализация вычитывает все строки из курсора для подсчета.
     * Используйте только для небольших результатов или когда данные всё равно будут использованы.
     * После вызова этого метода курсор считается исчерпанным.
     */
    public function count(): int
    {
        $this->touched = true;
        $start = hrtime(true);
        $count = $this->inner->count();
        $this->log($count, $this->elapsedMs($start));

        return $count;
    }

    /**
     * Возвращает генератор для построчной итерации.
     *
     * Логирует общее время итерации даже при досрочном выходе (break) или исключении,
     * благодаря использованию блока finally.
     */
    public function getIterator(): \Traversable
    {
        $this->touched = true;

        // Для корректной работы getIterator не должен сам быть генератором
        // Иначе он ни разу не вызовется без обращения и touched не будет установлена
        return (function () {
            $start = hrtime(true);
            $rows = 0;

            try {
                foreach ($this->inner as $row) {
                    ++$rows;
                    yield $row;
                }
            } finally {
                $this->rowCount = $rows;
                $this->durationMs = $this->elapsedMs($start);
                $this->tryLog();
            }
        })();
    }

    /**
     * Пытается залогировать результат, если это еще не было сделано.
     * Используется в деструкторе как "страховка" для случаев,
     * когда итератор был создан, но не использовался.
     */
    private function tryLog(): void
    {
        if (!$this->logged && null !== $this->rowCount && null !== $this->durationMs) {
            $this->logged = true;
            $this->logger->query($this->sql, $this->params, $this->durationMs, $this->rowCount, null);
        }
    }

    /**
     * Гарантирует логирование при уничтожении объекта.
     *
     * Если к данным обращались, но выборка так и не началась (например, создан итератор,
     * но ни разу не был запущен) — логируем результат как 0 строк.
     */
    public function __destruct()
    {
        // Страховка: логируем всегда, когда декоратор реально использовался,
        // но по какой-то причине ещё не залогирован.
        if ($this->touched && !$this->logged) {
            $this->rowCount ??= 0;
            $this->durationMs ??= 0.0;
            $this->tryLog();
        }
    }

    private function elapsedMs(int $start): float
    {
        return (hrtime(true) - $start) / 1e6;
    }

    /**
     * Выполняет логирование с защитой от повторного вызова.
     */
    private function log(int $rows, float $durationMs): void
    {
        if ($this->logged) {
            return;
        }
        $this->logged = true;
        $this->logger->query($this->sql, $this->params, $durationMs, $rows, null);
    }
}
