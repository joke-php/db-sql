# Создание логера запросов

Для логирования запросов и времени их выполнения необходимо реализовать интерфейс
`\Vasoft\Joke\Db\Sql\Contract\SqlLoggerInterface`. Затем эту реализацию можно использовать при
вызове [логируемого подключения](../base/logging.md)

## Методы логирования транзакций

Интерфейс определяет необходимость реализаций трех методов для работы с транзакциями:

- `public function transactionStarted(int $level): void;`
- `public function transactionCommitted(int $level): void;`
- `public function transactionRolledBack(int $level): void;`

Методы вызываются на соответствующие их именам события и в качестве параметра получают уровень транзакции.

## Метод протоколирования запроса

Метод `public function query(string $sql, array $params, ?float $durationMs, ?int $rows, ?string $error): void;`
является очень ответственным, именно в нем необходимо предусмотреть защиту от утечки важных данных, а также обеспечить
оптимальный объем записываемых данных (например, при больших INSERT-запросах не всегда целесообразно логировать значения
полностью).

## Пример реализации логера

```php
<?php

declare(strict_types=1);

namespace App;

use Vasoft\Joke\Db\Sql\Contract\SqlLoggerInterface;
use Vasoft\Joke\Support\FileSystem;
/**
* Логер записывающий лог в файл, в подкаталог логов проекта  
*/
class DemoLogger implements SqlLoggerInterface
{
    private const string SECTION_QUERY = 'QUERY';
    private const string SECTION_TRANSACTION = 'TRANSACTION';
    private string $logName;

    public function __construct(private readonly FileSystem $fs, string $name)
    {
        $dir = $fs->atLog('/database/');
        $fs->ensureDirectory($dir);
        $this->logName = $dir . $name . '.log';
    }

    private function write(string $section, string|array $message): void
    {
        $logMessage = is_array($message) ? print_r($message, true) : $message;
        $this->fs->writeFileAppendSafe(
            $this->logName,
            sprintf("[%s] %s: %s\n", $section, date('Y-m-d H:i:s'), $logMessage),
            FILE_APPEND,
        );
    }
    /**
    * Чтобы не допустить утечки важных данных, записываем только имена параметров   
    */
    public function query(string $sql, array $params, ?float $durationMs, ?int $rows, ?string $error): void
    {
        $this->write(self::SECTION_QUERY, [
            'query' => $sql,
            'params' => array_keys($params),
            'time' => $durationMs,
            'rows' => $rows,
            'error' => $error,
        ]);
    }

    public function transactionStarted(int $level): void
    {
        $this->write(self::SECTION_TRANSACTION, 'Started transaction level: ' . $level);
    }

    public function transactionCommitted(int $level): void
    {
        $this->write(self::SECTION_TRANSACTION, 'Committed transaction level: ' . $level);
    }

    public function transactionRolledBack(int $level): void
    {
        $this->write(self::SECTION_TRANSACTION, 'Rolled back transaction level: ' . $level);
    }
}
```

---
Далее: [Создание собственных соединений](connection.md)