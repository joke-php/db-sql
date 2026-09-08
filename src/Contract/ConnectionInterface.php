<?php

declare(strict_types=1);

namespace Vasoft\Joke\Db\Sql\Contract;

use Vasoft\Joke\Db\Sql\Exceptions\QueryException;

/**
 * Контракт подключения к базе данных.
 */
interface ConnectionInterface
{
    /**
     * Текущий уровень вложенности транзакций.
     * 0 — нет активной транзакции, 1 — реальная транзакция, >1 — savepoints.
     *
     * @readonly
     */
    public int $transactionLevel {
        get;
    }

    /**
     * Выполняет SELECT-запрос и возвращает результат.
     *
     * @param string                   $sql    SQL-запрос с плейсхолдерами
     * @param array<int|string, mixed> $params параметры для подстановки в запрос
     *
     * @return ResultInterface результат запроса
     *
     * @throws QueryException при ошибке выполнения запроса
     */
    public function query(string $sql, array $params = []): ResultInterface;

    /**
     * Выполняет INSERT/UPDATE/DELETE/DDL-запрос и возвращает количество затронутых строк.
     *
     * @param string                   $sql    SQL-запрос с плейсхолдерами
     * @param array<int|string, mixed> $params параметры для подстановки в запрос
     *
     * @return int количество затронутых строк
     *
     * @throws QueryException при ошибке выполнения запроса
     */
    public function execute(string $sql, array $params = []): int;

    /**
     * Начинает транзакцию.
     *
     * @throws QueryException если транзакции не поддерживаются
     *                        и режим configured как "throw"
     */
    public function beginTransaction(): void;

    /**
     * Фиксирует текущую транзакцию.
     *
     * @throws QueryException если нет активной транзакции
     */
    public function commit(): void;

    /**
     * Откатывает текущую транзакцию.
     *
     * @throws QueryException если нет активной транзакции
     */
    public function rollBack(): void;

    /**
     * Выполняет callable внутри транзакции с автоматическим commit/rollback.
     *
     * При успешном выполнении callback вызывает commit().
     * При выбросе любого Throwable — вызывает rollBack() и пробрасывает исключение.
     *
     * @template T
     *
     * @param callable(ConnectionInterface): T $callback Функция, выполняемая внутри транзакции.
     *                                                   Получает текущее подключение в качестве аргумента.
     *
     * @return T возвращаемое значение callback
     *
     * @throws \Throwable исключение, выброшенное callback (после rollBack)
     */
    public function transaction(callable $callback): mixed;

    /**
     * Проверяет, установлено ли физическое соединение с БД.
     *
     * Возвращает false, если соединение ещё не было установлено или было закрыто через disconnect().
     */
    public function isConnected(): bool;

    /**
     * Закрывает соединение с БД и сбрасывает состояние транзакций.
     *
     * Последующие вызовы методов, требующих подключения, инициируют
     * новое ленивое соединение.
     */
    public function disconnect(): void;
}
