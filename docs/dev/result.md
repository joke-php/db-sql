# Реализация результата запроса

Для создания собственного объекта результата запроса необходимо реализовать интерфейс
`Vasoft\Joke\Db\Sql\Contract\ResultInterface`.

Реализации могут быть буферизованными или небуферизированными. Небуферизованные результаты являются однопоточными:
итерация или извлечение данных расходует курсор, повторное чтение невозможно без повторного выполнения запроса.

## Методы которые должны быть реализованы

- Метод `public function all(): array;` - Должен возвращать все строки результата в виде списка ассоциативных массивов.

- Метод `public function fetch(): false|array;` - Должен возвращать следующую строку результата в виде ассоциативного
  массива или false, если строк больше нет.

- Метод `public function count(): int;` - Должен возвращать количество строк в результате или может выбрасывать
  исключение типа `Vasoft\Joke\Db\Sql\Exceptions\QueryException`, так как количество строк недоступно без полной
  выборки.
- Метод `public function getIterator(): \Traversable` - Должен возвращать итератор для перебора всех строк результата.

## Пример реализации

Пример реализации ответа для [демонстрационного Json подключения](connection.md).

```php
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
     * 
     * Полезен для сброса курсора (например, перед началом новой итерации)
     */
    public function rewind(): void
    {
        $this->cursor = 0;
    }
}
```