<?php

declare(strict_types=1);

namespace Vasoft\Joke\Db\Sql\Demo;

use Vasoft\Joke\Db\Sql\Contract\SqlLoggerInterface;
use Vasoft\Joke\Support\FileSystem;

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
        $this->fs->writeFile(
            $this->logName,
            sprintf("[%s] %s: %s\n", $section, date('Y-m-d H:i:s'), $logMessage),
            FILE_APPEND,
        );
    }

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
        $this->write(self::SECTION_TRANSACTION, 'Commited transaction level: ' . $level);
    }

    public function transactionRolledBack(int $level): void
    {
        $this->write(self::SECTION_TRANSACTION, 'Rolled back transaction level: ' . $level);
    }
}
