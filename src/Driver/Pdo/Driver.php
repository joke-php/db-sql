<?php

declare(strict_types=1);

namespace Vasoft\Joke\Db\Sql\Driver\Pdo;

use Vasoft\Joke\Config\Exceptions\ConfigException;
use Vasoft\Joke\Db\Sql\SqlConnectionConfig;

/**
 * Перечисление поддерживаемых PDO-драйверов и генератор DSN-строк.
 *
 * Отвечает за формирование корректной строки подключения (Data Source Name)
 * в зависимости от типа СУБД. Учитывает специфичные требования каждого драйвера
 * к форматированию параметров (разделители, обязательные поля, порядок опций).
 */
enum Driver: string
{
    case SYBASE = 'sybase';
    case MSSQL = 'mssql';
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
     * @return non-empty-string
     *
     * @throws ConfigException Если драйвер не распознан или отсутствуют обязательные параметры
     */
    public static function buildDsn(SqlConnectionConfig $config): string
    {
        return self::tryFrom($config->driver)?->dsn($config)
            ?? throw new ConfigException("Unknown database driver: '{$config->driver}'.");
    }

    /**
     * Генерирует DSN-строку на основе конфигурации подключения.
     *
     * Выполняет валидацию обязательных параметров (хост, имя БД) перед сборкой строки.
     * Если драйвер не поддерживается или отсутствуют критические параметры,
     * выбрасывается {@see ConfigException}.
     *
     * @param SqlConnectionConfig $config Конфигурация SQL-подключения
     *
     * @return non-empty-string Сформированная DSN-строка для PDO
     *
     * @throws ConfigException В случае отсутствия обязательных параметров
     */
    public function dsn(SqlConnectionConfig $config): string
    {
        return match ($this) {
            self::SYBASE, self::MSSQL, self::DBLIB => $this->dblibFamily($config),
            self::CUBRID => $this->cubrid($config),
            self::MYSQL => $this->mysql($config),
            self::PGSQL => $this->pgsql($config),
            self::SQLITE => $this->sqlite($config),
            self::FIREBIRD => $this->firebird($config),
            self::IBM => $this->ibm($config),
            self::INFORMIX => $this->informix($config),
            self::OCI => $this->oci($config),
            self::SQLSRV => $this->sqlsrv($config),
            self::ODBC => $this->odbc($config),
        };
    }

    /**
     * @return non-empty-string
     *
     * @throws ConfigException При ошибке конфигурации
     */
    private function odbc(SqlConnectionConfig $config): string
    {
        if ($dsnName = $config->getSpecificOption('DSN')) {
            return "odbc:{$dsnName}";
        }

        if (!empty($config->specificOptions)) {
            return 'odbc:' . self::formatOptions($config->specificOptions);
        }

        if (!empty($config->database)) {
            return "odbc:{$config->database}";
        }

        throw new ConfigException("DSN for 'odbc' requires at least one of: database, DSN.");
    }

    /**
     * @return non-empty-string
     *
     * @throws ConfigException При ошибке конфигурации
     */
    private function oci(SqlConnectionConfig $config): string
    {
        self::ensureRequiredAlternative(
            $config,
            ['dbname' => $config->database, 'tns' => $config->getSpecificOption('tns')],
        );

        $parts = [
            'dbname' => $config->database,
            'charset' => $config->charset,
            'tns' => $config->getSpecificOption('tns'),
        ];

        return 'oci:' . self::formatOptions($parts);
    }

    /**
     * @return non-empty-string
     *
     * @throws ConfigException При ошибке конфигурации
     */
    private function sqlsrv(SqlConnectionConfig $config): string
    {
        self::ensureRequired($config, 'database');

        $server = $config->host;
        if ($config->port) {
            $server .= ",{$config->port}";
        }

        $parts = [
            'Server' => $server,
            'Database' => $config->database,
            'UID' => $config->username,
            'PWD' => $config->password,
            'CharacterSet' => $config->charset,
            'Encrypt' => $config->getSpecificOption('Encrypt'),
            'TrustServerCertificate' => $config->getSpecificOption('TrustServerCertificate'),
        ];

        return 'sqlsrv:' . self::formatOptions($parts);
    }

    /**
     * @return non-empty-string
     *
     * @throws ConfigException При ошибке конфигурации
     */
    private function informix(SqlConnectionConfig $config): string
    {
        $parts = [
            'DSN' => $config->getSpecificOption('DSN'),
            'host' => $config->host,
            'service' => $config->port,
            'database' => $config->database,
            'server' => $config->getSpecificOption('server'),
            'protocol' => $config->getSpecificOption('protocol'),
            'EnableScrollableCursors' => $config->getSpecificOption('EnableScrollableCursors'),
        ];

        return 'informix:' . self::formatOptions($parts);
    }

    /**
     * @return non-empty-string
     *
     * @throws ConfigException При ошибке конфигурации
     */
    private function ibm(SqlConnectionConfig $config): string
    {
        self::ensureRequired($config, 'host');
        self::ensureRequired($config, 'database');
        $parts = [
            'DSN' => $config->getSpecificOption('DSN'),
            'hostname' => $config->host,
            'port' => $config->port,
            'database' => $config->database,
        ];

        return 'ibm:' . self::formatOptions($parts);
    }

    /**
     * @return non-empty-string
     *
     * @throws ConfigException При ошибке конфигурации
     */
    private function firebird(SqlConnectionConfig $config): string
    {
        self::ensureRequired($config, 'database');
        $host = $config->host;
        if ('' !== $host) {
            if (null !== $config->port) {
                $host .= '/' . $config->port;
            }
            $database = $host . ':' . $config->database;
        } else {
            $database = $config->database;
        }

        $parts = [
            'dbname' => $database,
            'charset' => $config->charset,
            'role' => $config->getSpecificOption('role'),
            'dialect' => $config->getSpecificOption('dialect'),
        ];

        return 'firebird:' . self::formatOptions($parts);
    }

    /**
     * @return non-empty-string
     *
     * @throws ConfigException При ошибке конфигурации
     */
    private function dblibFamily(SqlConnectionConfig $config): string
    {
        self::ensureRequired($config, 'host');
        self::ensureRequired($config, 'database');
        $parts = [
            'host' => $config->host,
            'dbname' => $config->database,
            'charset' => $config->charset,
            'appname' => $config->getSpecificOption('appname'),
            'secure' => $config->getSpecificOption('secure'),
        ];

        return $config->driver . ':' . self::formatOptions($parts);
    }

    /**
     * @return non-empty-string
     *
     * @throws ConfigException При ошибке конфигурации
     */
    private function cubrid(SqlConnectionConfig $config): string
    {
        self::ensureRequired($config, 'host');
        self::ensureRequired($config, 'database');
        $parts = [
            'host' => $config->host,
            'port' => $config->port,
            'dbname' => $config->database,
        ];

        return 'cubrid:' . self::formatOptions($parts);
    }

    /**
     * @return non-empty-string
     *
     * @throws ConfigException При ошибке конфигурации
     */
    private function mysql(SqlConnectionConfig $config): string
    {
        self::ensureRequiredAlternative(
            $config,
            ['host' => $config->host, 'unix_socket' => $config->getSpecificOption('unix_socket')],
        );
        self::ensureRequired($config, 'database');
        $parts = [
            'host' => $config->host,
            'unix_socket' => $config->getSpecificOption('unix_socket'),
            'port' => $config->port,
            'dbname' => $config->database,
            'charset' => $config->getSpecificOption('charset'),
        ];

        return 'mysql:' . self::formatOptions($parts);
    }

    /**
     * @return non-empty-string
     *
     * @throws ConfigException При ошибке конфигурации
     */
    private function pgsql(SqlConnectionConfig $config): string
    {
        self::ensureRequired($config, 'host');
        self::ensureRequired($config, 'database');
        $parts = [
            'host' => $config->host,
            'port' => $config->port,
            'dbname' => $config->database,
            'sslmode' => $config->getSpecificOption('sslmode'),
        ];

        return 'pgsql:' . self::formatOptions($parts);
    }

    /**
     * @return non-empty-string
     *
     * @throws ConfigException При ошибке конфигурации
     */
    private function sqlite(SqlConnectionConfig $config): string
    {
        self::ensureRequired($config, 'database');

        return "sqlite:{$config->database}";
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
     * @param string $propertyName Имя свойства
     *
     * @throws ConfigException Если свойство отсутствует или пусто
     */
    private function ensureRequired(SqlConnectionConfig $config, string $propertyName): void
    {
        $value = $config->{$propertyName};
        if (null === $value || '' === $value) {
            throw new ConfigException("Property '{$propertyName}' cannot be empty for '{$config->driver}'.");
        }
    }

    /**
     * Проверяет наличие хотя бы одного из альтернативных параметров.
     *
     * @param array<string, mixed> $options Ассоциативный массив [имя_параметра => значение]
     *
     * @throws ConfigException Если все переданные параметры пусты
     */
    private function ensureRequiredAlternative(SqlConnectionConfig $config, array $options): void
    {
        if (array_any($options, static fn($option) => null !== $option && '' !== $option)) {
            return;
        }
        $keys = implode(',', array_keys($options));

        throw new ConfigException("DSN for '{$config->driver}' requires at least one of: {$keys}.");
    }
}
