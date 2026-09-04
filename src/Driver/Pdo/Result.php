<?php

declare(strict_types=1);

namespace Vasoft\Joke\Db\Sql\Driver\Pdo;

use Vasoft\Joke\Db\Sql\Contract\ResultInterface;
use Vasoft\Joke\Db\Sql\Exceptions\QueryException;

/**
 * Обёртка над PDOStatement, реализующая ResultInterface.
 *
 * Предоставляет удобный API для получения результатов запроса,
 * скрывая детали работы с PDO и унифицируя обработку ошибок.
 * Все исключения PDO преобразуются в {@see QueryException}.
 *
 * Важно: PDOStatement является курсором. После полного чтения результатов
 * через {@see all()}, {@see one()} или итерацию, повторное чтение вернёт пустой результат.
 */
class Result implements ResultInterface
{
    /**
     * @param \PDOStatement $statement Выполненный PDOStatement с результатами запроса
     */
    public function __construct(private readonly \PDOStatement $statement) {}

    /**
     * Возвращает итератор для построчного чтения результатов.
     *
     * Каждая итерация возвращает ассоциативный массив (FETCH_ASSOC).
     * Подходит для обработки больших наборов данных без загрузки всего
     * результата в память.
     *
     * @return \Traversable<int, array<string, mixed>>
     *
     * @throws QueryException При ошибке чтения из PDOStatement
     */
    public function getIterator(): \Traversable
    {
        try {
            while ($row = $this->statement->fetch(\PDO::FETCH_ASSOC)) {
                yield $row;
            }
        } catch (\PDOException $e) {
            throw new QueryException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Возвращает количество строк, затронутых последним SQL-запросом.
     *
     * Внимание: Для SELECT-запросов поведение зависит от драйвера PDO.
     * Некоторые драйверы (например, MySQL с буферизацией) возвращают корректное
     * количество строк, другие могут вернуть 0 или -1.
     * Для надёжного подсчёта строк SELECT используйте COUNT(*) в запросе.
     *
     * @return int Количество затронутых строк
     *
     * @throws QueryException При ошибке получения rowCount
     */
    public function count(): int
    {
        try {
            return $this->statement->rowCount();
        } catch (\PDOException $e) {
            throw new QueryException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Возвращает все строки результата в виде массива ассоциативных массивов.
     *
     * Загружает весь результат в память. Для больших наборов данных
     * рекомендуется использовать {@see getIterator()} вместо этого метода.
     *
     * @return list<array<string, mixed>> Массив строк результата
     *
     * @throws QueryException При ошибке чтения из PDOStatement
     */
    public function all(): array
    {
        try {
            return array_values($this->statement->fetchAll(\PDO::FETCH_ASSOC));
        } catch (\PDOException $e) {
            throw new QueryException($e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Возвращает первую строку результата или false, если результат пуст.
     *
     * Сдвигает внутренний курсор PDOStatement. Последующие вызовы
     * вернут следующие строки, а не ту же самую.
     *
     * @return array<string, mixed>|false Первая строка или false
     *
     * @throws QueryException При ошибке чтения из PDOStatement
     */
    public function one(): false|array
    {
        try {
            return $this->statement->fetch(\PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            throw new QueryException($e->getMessage(), $e->getCode(), $e);
        }
    }
}
