<?php

declare(strict_types=1);

namespace Vasoft\Joke\Db\Sql\Driver\Pdo;

use Vasoft\Joke\Config\Exceptions\ConfigException;

/**
 * Перечисление поддерживаемых PDO-драйверов и генератор DSN-строк.
 *
 * Отвечает за формирование корректной строки подключения (Data Source Name)
 * в зависимости от типа СУБД. Учитывает специфичные требования каждого драйвера
 * к форматированию параметров (разделители, обязательные поля, порядок опций).
 */
enum Driver: string
{
    case DBLIB = 'dblib';
    case CUBRID = 'cubrid';
    case ODBC = 'odbc';
    case MYSQL = 'mysql';
    case PGSQL = 'pgsql';
    case SQLITE = 'sqlite';
    case FIREBIRD = 'firebird';
    case IBM = 'ibm';
    case INFORMIX = 'informix';
    case OCI = 'oci';
    case SQLSRV = 'sqlsrv';

    /**
     * Безопасно генерирует DSN-строку из конфигурации.
     *
     * В отличие от прямого вызова dsn(), этот метод предварительно проверяет,
     * поддерживается ли указанный драйвер, и приводит неизвестные драйверы
     * к единому исключению ConfigException. А так же обеспечивает консистентность построения.
     *
     * @param array<string,null|bool|float|int|string> $specificOptions
     *
     * @return non-empty-string
     *
     * @throws ConfigException Если драйвер не распознан или отсутствуют обязательные параметры
     */
    public static function buildDsn(
        string $driver,
        string $database,
        string $host = '',
        ?int $port = null,
        array $specificOptions = [],
    ): string {
        return self::tryFrom($driver)?->dsn(
            $database,
            $host,
            $port,
            $specificOptions,
        )
            ?? throw new ConfigException("Unknown database driver: '{$driver}'.");
    }

    /**
     * Генерирует DSN-строку на основе конфигурации подключения.
     *
     * Выполняет валидацию обязательных параметров (хост, имя БД) перед сборкой строки.
     * Если драйвер не поддерживается или отсутствуют критические параметры,
     * выбрасывается {@see ConfigException}.
     *
     * @param array<string,null|bool|float|int|string> $specificOptions
     *
     * @return non-empty-string Сформированная DSN-строка для PDO
     *
     * @throws ConfigException В случае отсутствия обязательных параметров
     */
    public function dsn(
        string $database,
        string $host = '',
        ?int $port = null,
        array $specificOptions = [],
    ): string {
        $dto = $specificOptions;
        $dto['database'] = $database;
        $dto['host'] = $host;
        $dto['port'] = $port;

        return match ($this) {
            self::DBLIB => $this->dblib($dto),
            self::CUBRID => $this->cubrid($dto),
            self::MYSQL => $this->mysql($dto),
            self::PGSQL => $this->pgsql($dto),
            self::SQLITE => $this->sqlite($dto),
            self::FIREBIRD => $this->firebird($dto),
            self::IBM => $this->ibm($dto),
            self::INFORMIX => $this->informix($dto),
            self::OCI => $this->oci($dto),
            self::SQLSRV => $this->sqlsrv($dto),
            self::ODBC => $this->odbc($dto),
        };
    }

    /**
     * @param array<string,null|bool|float|int|string> $dto
     *
     * @return non-empty-string
     *
     * @throws ConfigException При ошибке конфигурации
     */
    private function odbc(array $dto): string
    {
        if (!empty($dto['DSN'])) {
            return "odbc:{$dto['DSN']}";
        }

        self::ensureRequiredAlternative($dto, ['database', 'host']);

        $parts = [];

        if (!empty($dto['driver'])) {
            $parts[] = 'Driver={' . $dto['driver'] . '}';
        }
        if (!empty($dto['host'])) {
            $server = $dto['host'];
            if (!empty($dto['port'])) {
                $server .= ',' . $dto['port'];
            }
            $parts[] = "Server={$server}";
        }
        if (!empty($dto['database'])) {
            $parts[] = "Database={$dto['database']}";
        }

        foreach ($dto as $key => $value) {
            if (!in_array($key, ['driver', 'host', 'port', 'database'], true)
                && null !== $value && '' !== $value
            ) {
                $parts[] = "{$key}={$value}";
            }
        }

        return 'odbc:' . implode(';', $parts);
    }

    /**
     * @param array<string,null|bool|float|int|string> $dto
     *
     * @return non-empty-string
     *
     * @throws ConfigException При ошибке конфигурации
     */
    private function oci(array $dto): string
    {
        self::ensureRequiredAlternative($dto, ['database', 'tns']);

        $parts = [
            'dbname' => $dto['database'] ?? null,
            'charset' => $dto['charset'] ?? null,
            'tns' => $dto['tns'] ?? null,
        ];

        return 'oci:' . self::formatOptions($parts);
    }

    /**
     * @param array<string,null|bool|float|int|string> $dto
     *
     * @return non-empty-string
     *
     * @throws ConfigException При ошибке конфигурации
     */
    private function sqlsrv(array $dto): string
    {
        self::ensureRequired($dto, 'database');

        $server = $dto['host'] ?? null;
        if (null !== $server && !empty($dto['port'])) {
            $server .= ",{$dto['port']}";
        }

        $parts = [
            'Server' => $server,
            'Database' => $dto['database'],
            'UID' => $dto['username'] ?? null,
            'PWD' => $dto['password'] ?? null,
            'CharacterSet' => $dto['charset'] ?? null,
            'Encrypt' => $dto['Encrypt'] ?? null,
            'TrustServerCertificate' => $dto['TrustServerCertificate'] ?? null,
        ];

        return 'sqlsrv:' . self::formatOptions($parts);
    }

    /**
     * @param array<string,null|bool|float|int|string> $dto
     *
     * @return non-empty-string
     *
     * @throws ConfigException При ошибке конфигурации
     */
    private function informix(array $dto): string
    {
        if (empty($dto['DSN'])) {
            self::ensureRequired($dto, 'host');
            self::ensureRequired($dto, 'database');
            self::ensureRequired($dto, 'server');
        }

        $parts = [
            'DSN' => $dto['DSN'] ?? null,
            'host' => $dto['host'] ?? null,
            'service' => $dto['port'] ?? null,
            'database' => $dto['database'] ?? null,
            'server' => $dto['server'] ?? null,
            'protocol' => $dto['protocol'] ?? null,
            'EnableScrollableCursors' => $dto['EnableScrollableCursors'] ?? null,
        ];

        return 'informix:' . self::formatOptions($parts);
    }

    /**
     * @param array<string,null|bool|float|int|string> $dto
     *
     * @return non-empty-string
     *
     * @throws ConfigException При ошибке конфигурации
     */
    private function ibm(array $dto): string
    {
        self::ensureRequired($dto, 'host');
        self::ensureRequired($dto, 'database');
        $parts = [
            'DSN' => $dto['DSN'] ?? null,
            'hostname' => $dto['host'],
            'port' => $dto['port'] ?? null,
            'database' => $dto['database'],
        ];

        return 'ibm:' . self::formatOptions($parts);
    }

    /**
     * @param array<string,null|bool|float|int|string> $dto
     *
     * @return non-empty-string
     *
     * @throws ConfigException При ошибке конфигурации
     */
    private function firebird(array $dto): string
    {
        self::ensureRequired($dto, 'database');
        $host = $dto['host'];
        if ('' !== $host) {
            if (null !== $dto['port']) {
                $host .= '/' . $dto['port'];
            }
            $database = $host . ':' . $dto['database'];
        } else {
            $database = $dto['database'];
        }

        $parts = [
            'dbname' => $database,
            'charset' => $dto['charset'] ?? null,
            'role' => $dto['role'] ?? null,
            'dialect' => $dto['dialect'] ?? null,
        ];

        return 'firebird:' . self::formatOptions($parts);
    }

    /**
     * @param array<string,null|bool|float|int|string> $dto
     *
     * @return non-empty-string
     *
     * @throws ConfigException При ошибке конфигурации
     */
    private function dblib(array $dto): string
    {
        self::ensureRequired($dto, 'host');
        self::ensureRequired($dto, 'database');
        $host = $dto['host'];
        if (!empty($dto['port'])) {
            $host .= ':' . $dto['port'];
        }

        $parts = [
            'host' => $host,
            'dbname' => $dto['database'],
            'charset' => $dto['charset'] ?? null,
            'appname' => $dto['appname'] ?? null,
            'secure' => $dto['secure'] ?? null,
        ];

        return $this->value . ':' . self::formatOptions($parts);
    }

    /**
     * @param array<string,null|bool|float|int|string> $dto
     *
     * @return non-empty-string
     *
     * @throws ConfigException При ошибке конфигурации
     */
    private function cubrid(array $dto): string
    {
        self::ensureRequired($dto, 'host');
        self::ensureRequired($dto, 'database');
        $parts = [
            'host' => $dto['host'],
            'port' => $dto['port'],
            'dbname' => $dto['database'],
            'charset' => $dto['charset'] ?? null,
            'autocommit' => $dto['autocommit'] ?? null,
            'loginslowtime' => $dto['loginslowtime'] ?? null,
        ];

        return 'cubrid:' . self::formatOptions($parts);
    }

    /**
     * @param array<string,null|bool|float|int|string> $dto
     *
     * @return non-empty-string
     *
     * @throws ConfigException При ошибке конфигурации
     */
    private function mysql(array $dto): string
    {
        self::ensureRequiredAlternative($dto, ['host', 'unix_socket']);
        self::ensureRequired($dto, 'database');
        $parts = [
            'host' => $dto['host'],
            'unix_socket' => $dto['unix_socket'] ?? null,
            'port' => $dto['port'],
            'dbname' => $dto['database'],
            'charset' => $dto['charset'] ?? null,
        ];

        return 'mysql:' . self::formatOptions($parts);
    }

    /**
     * @param array<string,null|bool|float|int|string> $dto
     * @param array<string,null|bool|float|int|string> $dto
     *
     * @return non-empty-string
     *
     * @throws ConfigException При ошибке конфигурации
     */
    private function pgsql(array $dto): string
    {
        self::ensureRequiredAlternative($dto, ['host', 'unix_socket']);
        self::ensureRequired($dto, 'database');
        $parts = [
            'host' => $dto['host'] ?? $dto['unix_socket'],
            'port' => $dto['port'] ?? null,
            'dbname' => $dto['database'],
            'sslmode' => $dto['sslmode'] ?? null,
        ];

        return 'pgsql:' . self::formatOptions($parts);
    }

    /**
     * @param array<string,null|bool|float|int|string> $dto
     *
     * @return non-empty-string
     *
     * @throws ConfigException При ошибке конфигурации
     */
    private function sqlite(array $dto): string
    {
        self::ensureRequired($dto, 'database');

        return "sqlite:{$dto['database']}";
    }

    /**
     * @param array<string,null|bool|float|int|string> $options
     */
    private function formatOptions(array $options): string
    {
        $result = [];
        foreach ($options as $option => $value) {
            if (null !== $value && '' !== $value) {
                $result[] = self::escape($option) . '=' . self::escape((string) $value);
            }
        }

        return implode(';', $result);
    }

    /**
     * Экранирует спецсимволы в имени/значении параметра DSN.
     *
     * Символы ';' (разделитель параметров), '=' (разделитель ключ/значение)
     * и сам '\\' экранируются обратным слэшем.
     */
    private static function escape(string $value): string
    {
        return str_replace(['\\', ';', '='], ['\\\\', '\;', '\='], $value);
    }

    /**
     * Проверяет наличие обязательного свойства в конфиге.
     *
     * @param array<string,null|bool|float|int|string> $dto
     * @param string                                   $propertyName Имя свойства
     *
     * @throws ConfigException Если свойство отсутствует или пусто
     */
    private function ensureRequired(array $dto, string $propertyName): void
    {
        if (empty($dto[$propertyName])) {
            throw new ConfigException("Property '{$propertyName}' cannot be empty for '{$this->value}'.");
        }
    }

    /**
     * Проверяет наличие хотя бы одного из альтернативных параметров.
     *
     * @param array<string,null|bool|float|int|string> $dto
     * @param list<string>                             $options Ассоциативный массив [имя_параметра => значение]
     *
     * @throws ConfigException Если все переданные параметры пусты
     */
    private function ensureRequiredAlternative(array $dto, array $options): void
    {
        if (array_any($options, static fn($option) => !empty($dto[$option]))) {
            return;
        }
        $keys = implode(', ', $options);

        throw new ConfigException("DSN for '{$this->value}' requires at least one of: {$keys}.");
    }
}
