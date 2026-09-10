<?php

declare(strict_types=1);

namespace Vasoft\Joke\Db\Sql\Demo\Driver;

use Vasoft\Joke\Db\Sql\Contract\ConnectionInterface;
use Vasoft\Joke\Db\Sql\Contract\ResultInterface;
use Vasoft\Joke\Support\FileSystem;

/**
 * Демонстрационная реализация ConnectionInterface на основе JSON-файла.
 */
class JsonConnection extends BaseConnection implements ConnectionInterface
{
    private string $file = '';

    public function __construct(private readonly FileSystem $fs) {}

    private function ensureConnection(): void
    {
        if ('' !== $this->file) {
            return;
        }
        $this->file = $this->fs->atBase('/storage/demo.json');
        if (file_exists($this->file)) {
            $data = $this->fs->readFile($this->file);
            $this->store = json_decode($data, true);
        }
    }

    public function isConnected(): bool
    {
        return '' !== $this->file;
    }

    public function disconnect(): void
    {
        $this->fs->writeFileSafe($this->file, json_encode($this->store, JSON_PRETTY_PRINT));
        $this->file = '';
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->ensureConnection();

        return parent::execute($sql, $params);
    }

    public function query(string $sql, array $params = []): ResultInterface
    {
        $this->ensureConnection();

        return parent::query($sql, $params);
    }

    public function beginTransaction(): void
    {
        $this->ensureConnection();
        parent::beginTransaction();
    }

    public function commit(): void
    {
        $this->ensureConnection();
        parent::commit();
        $this->fs->writeFileSafe($this->file, json_encode($this->store, JSON_PRETTY_PRINT));
    }
}
