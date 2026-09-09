<?php

declare(strict_types=1);

namespace Vasoft\Joke\Db\Sql\Tests\Driver\Pdo;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Vasoft\Joke\Config\Exceptions\ConfigException;
use Vasoft\Joke\Db\Sql\Driver\Pdo\Driver;

/**
 * @internal
 *
 * @coversDefaultClass \Vasoft\Joke\Db\Sql\Driver\Pdo\Driver
 */
#[CoversClass(Driver::class)]
#[TestDox('Driver - генератор DSN строк для PDO')]
final class DriverTest extends TestCase
{
    #[DataProvider('provideDsnGenerationCases')]
    #[TestDox('Генерирует корректный DSN для {0}')]
    public function testDsnGeneration(string $driverName, array $options, string $expectedDsn): void
    {
        $driver = Driver::from($driverName);

        self::assertSame($expectedDsn, $driver->dsn(...$options));
    }

    public static function provideDsnGenerationCases(): iterable
    {
        yield 'Firebird' => [
            'firebird',
            [
                'database' => 'example',
                'host' => 'localhost',
                'port' => 3306,
                'specificOptions' => ['role' => 'admin', 'dialect' => 1],
            ],
            'firebird:dbname=localhost/3306:example;role=admin;dialect=1',
        ];
        yield 'Firebird local path' => [
            'firebird',
            ['database' => '/opt/firebird/data/test.fdb', 'host' => '', 'specificOptions' => ['charset' => 'utf8']],
            'firebird:dbname=/opt/firebird/data/test.fdb;charset=utf8',
        ];
        yield 'IBM' => [
            'ibm',
            [
                'database' => 'example',
                'host' => 'localhost',
                'port' => 3306,
            ],
            'ibm:hostname=localhost;port=3306;database=example',
        ];
        yield 'Informix via DSN' => [
            'informix',
            [
                'database' => '',
                'specificOptions' => ['DSN' => 'MyLegacyDB'],
            ],
            'informix:DSN=MyLegacyDB',
        ];
        yield 'Informix via params' => [
            'informix',
            [
                'host' => 'localhost',
                'database' => 'example',
                'specificOptions' => ['server' => 'myinstance'],
            ],
            'informix:host=localhost;database=example;server=myinstance',
        ];
        yield 'OCI' => [
            'oci',
            [
                'database' => '//localhost:1521/mydb',
                'specificOptions' => ['charset' => 'utf8'],
            ],
            'oci:dbname=//localhost:1521/mydb;charset=utf8',
        ];
        yield 'OCI via TNS' => [
            'oci',
            [
                'database' => '',
                'specificOptions' => ['tns' => 'MY_TNS_ALIAS'],
            ],
            'oci:tns=MY_TNS_ALIAS',
        ];
        yield 'Cubrid' => [
            'cubrid',
            [
                'database' => 'denodb',
                'host' => 'localhost',
                'port' => 33000,
            ],
            'cubrid:host=localhost;port=33000;dbname=denodb',
        ];
        yield 'DBLib' => [
            'dblib',
            [
                'database' => 'denodb',
                'host' => 'localhost',
                'port' => 33000,
            ],
            'dblib:host=localhost:33000;dbname=denodb',
        ];
        yield 'ODBC via DSN name' => [
            'odbc',
            ['database' => 'ignored', 'specificOptions' => ['DSN' => 'MyLegacyDB']],
            'odbc:MyLegacyDB',
        ];
        yield 'ODBC via DSN' => [
            'odbc',
            [
                'database' => '',
                'specificOptions' => [
                    'DSN' => 'my-db',
                ],
            ],
            'odbc:my-db',
        ];
        yield 'ODBC via Driver options' => [
            'odbc',
            [
                'database' => 'test',
                'host' => 'localhost',
                'port' => 3306,
                'specificOptions' => [
                    'driver' => 'SQL Server',
                    'extended' => 'test',
                ],
            ],
            'odbc:Driver={SQL Server};Server=localhost,3306;Database=test;extended=test',
        ];
        yield 'MySQL basic' => [
            'mysql',
            ['database' => 'test_db', 'host' => 'localhost'],
            'mysql:host=localhost;dbname=test_db',
        ];

        yield 'MySQL with port and charset' => [
            'mysql',
            [
                'database' => 'test_db',
                'host' => '127.0.0.1',
                'port' => 3307,
                'specificOptions' => ['charset' => 'utf8'],
            ],
            'mysql:host=127.0.0.1;port=3307;dbname=test_db;charset=utf8',
        ];

        yield 'SQLite file' => [
            'sqlite',
            ['database' => '/var/db/app.sqlite'],
            'sqlite:/var/db/app.sqlite',
        ];

        yield 'PostgreSQL' => [
            'pgsql',
            [
                'database' => 'myapp',
                'host' => 'db.example.com',
                'port' => 5432,
                'specificOptions' => ['sslmode' => 'require'],
            ],
            'pgsql:host=db.example.com;port=5432;dbname=myapp;sslmode=require',
        ];

        yield 'SQLSRV (Microsoft)' => [
            'sqlsrv',
            [
                'database' => 'AdventureWorks',
                'host' => 'MSSQLSERVER',
                'port' => 3306,
                'specificOptions' => [
                    'username' => 'sa',
                    'password' => 'secret',
                    'charset' => 'UTF-8',
                ],
            ],
            'sqlsrv:Server=MSSQLSERVER,3306;Database=AdventureWorks;UID=sa;PWD=secret;CharacterSet=UTF-8',
        ];

        yield 'Escapes special chars in values' => [
            'mysql',
            [
                'database' => 'db;name=x',
                'host' => 'localhost',
            ],
            'mysql:host=localhost;dbname=db\;name\=x',
        ];
    }

    #[TestDox('Выбрасывает исключение если отсутствует обязательный хост')]
    public function testMissingHostThrowsException(): void
    {
        self::expectException(ConfigException::class);
        self::expectExceptionMessageIs(
            "DSN for 'pgsql' requires at least one of: host, unix_socket.",
        );

        Driver::PGSQL->dsn(database: 'db');
    }

    #[TestDox('Выбрасывает исключение если отсутствует имя базы данных')]
    public function testMissingDatabaseThrowsException(): void
    {
        self::expectException(ConfigException::class);
        self::expectExceptionMessageIs(
            "Property 'database' cannot be empty for '" . Driver::MYSQL->value . "'.",
        );

        Driver::MYSQL->dsn('', 'localhost');
    }

    #[TestDox('Выбрасывает исключение если у OCI нет ни dbname, ни tns')]
    public function testOciRequiresDbnameOrTns(): void
    {
        self::expectException(ConfigException::class);
        self::expectExceptionMessageIs("DSN for 'oci' requires at least one of: database, tns.");

        Driver::OCI->dsn('');
    }

    #[TestDox('Корректно обрабатывает альтернативные параметры (Unix Socket)')]
    public function testAlternativeParamsSuccess(): void
    {
        $dsn = Driver::MYSQL->dsn(
            database: 'test',
            specificOptions: ['unix_socket' => '/var/run/mysqld/mysqld.sock'],
        );
        self::assertStringContainsString('unix_socket=/var/run/mysqld/mysqld.sock', $dsn);
    }

    #[TestDox('Игнорирует null и пустые значения в опциях')]
    public function testSkipsEmptyOptions(): void
    {
        $dsn = Driver::MYSQL->dsn(
            database: 'test',
            host: 'localhost',
            specificOptions: ['charset' => '', 'sslmode' => null],
        );
        self::assertSame('mysql:host=localhost;dbname=test', $dsn);
    }

    #[TestDox('Выбрасывает исключение если у ODBC нет обязательных параметров')]
    public function testOdbcRequires(): void
    {
        self::expectException(ConfigException::class);
        self::expectExceptionMessageIs("DSN for 'odbc' requires at least one of: database, host.");

        Driver::ODBC->dsn('');
    }

    #[TestDox('buildDsn Выбрасывает исключение если запрошен неизвестный драйвер')]
    public function testBuildDsnUnknownDriver(): void
    {
        self::expectException(ConfigException::class);
        self::expectExceptionMessageIs("Unknown database driver: 'unknown'.");

        Driver::buildDsn('unknown', '');
    }

    #[TestDox('buildDsn возвращает строку на основе параметров')]
    public function testBuildDsn(): void
    {
        $dsn = Driver::buildDsn('mysql', 'db', '192.168.1.50', 1433, ['charset' => 'utf8']);
        self::assertSame('mysql:host=192.168.1.50;port=1433;dbname=db;charset=utf8', $dsn);
    }
}
