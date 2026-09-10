<?php

declare(strict_types=1);

namespace Vasoft\Joke\Db\Sql\Demo\Driver;

use Vasoft\Joke\Db\Sql\Contract\ResultInterface;

/**
 * Демонстрационная реализация ResultInterface для файловых хранилищ.
 *
 * Данные полностью буферизуются в памяти при создании объекта.
 * Поддерживает многократную итерацию, подсчет количества и построчное чтение.
 *
 * @implements ResultInterface<array<string, mixed>>
 */
class Result implements ResultInterface
{
    /**
     * Внутренний указатель текущей позиции курсора.
     */
    private int $cursor = 0;

    /**
     * @param list<array<string, mixed>> $data массив записей результата выборки
     */
    public function __construct(private readonly array $data) {}

    /**
     * Возвращает итератор для перебора всех строк результата.
     *
     * @return \Traversable<int, array<string, mixed>>
     */
    public function getIterator(): \Traversable
    {
        yield from $this->data;
    }

    /**
     * Возвращает все строки результата в виде массива.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->data;
    }

    /**
     * Возвращает следующую строку результата и сдвигает курсор.
     *
     * @return array<string, mixed>|false следующая строка или false, если данные закончились
     */
    public function fetch(): false|array
    {
        if (!isset($this->data[$this->cursor])) {
            return false;
        }

        return $this->data[$this->cursor++];
    }

    /**
     * Возвращает количество строк в результате.
     */
    public function count(): int
    {
        return count($this->data);
    }

    /**
     * Сбрасывает курсор в начало набора данных.
     */
    public function rewind(): void
    {
        $this->cursor = 0;
    }
}
