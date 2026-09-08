<?php

declare(strict_types=1);

namespace Vasoft\Joke\Db\Sql\Demo\Driver;

use Vasoft\Joke\Db\Sql\Contract\ResultInterface;

/**
 * Реализация ResultInterface для хранилища (демонстрационная).
 *
 * Данные полностью буферизуются в памяти при создании объекта.
 * Поддерживает многократную итерацию и чтение.
 */
class Result implements ResultInterface
{
    /**
     * @param list<array<string,mixed>> $data записи результата
     */
    public function __construct(private readonly array $data) {}

    /**
     * @return \Traversable<int, array<string, mixed>>
     */
    public function getIterator(): \Traversable
    {
        while ($row = $this->data) {
            yield $row;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * @return null|array<string, mixed>
     */
    public function fetch(): ?array
    {
        return $this->data[0] ?? null;
    }

    public function count(): int
    {
        return count($this->data);
    }
}
