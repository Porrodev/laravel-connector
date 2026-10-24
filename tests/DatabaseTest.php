<?php

use Illuminate\Database\MySqlConnection;
use Porro\Laravel\Reporter;

beforeEach(function () {
    $this->app->setBasePath(__DIR__.'/fixtures/app');
});

/**
 * Make the default connection a MySQL one whose server reports $version,
 * or that can't connect when $version is null.
 *
 * @param  array<string, mixed>  $config
 */
function fakeMysql(?string $version, array $config = []): void
{
    $pdo = $version === null
        ? fn () => throw new PDOException('SQLSTATE[HY000] [2002] Connection refused')
        : new class($version) extends PDO
        {
            public function __construct(private string $version)
            {
                parent::__construct('sqlite::memory:');
            }

            public function getAttribute(int $attribute): mixed
            {
                return $attribute === PDO::ATTR_SERVER_VERSION ? $this->version : parent::getAttribute($attribute);
            }
        };

    config(['database.connections.fake' => $config += ['driver' => 'mysql', 'host' => 'db.example.com', 'database' => 'shop', 'username' => 'forge', 'password' => 'hunter2']]);
    config(['database.default' => 'fake']);
    app('db')->extend('fake', fn ($config, $name) => new MySqlConnection($pdo, $config['database'], '', $config + ['name' => $name]));
}

it('reports the database engine and version', function () {
    expect(app(Reporter::class)->build()->toArray()['database'])->toBe([
        'engine' => 'sqlite',
        'version' => (new PDO('sqlite::memory:'))->getAttribute(PDO::ATTR_SERVER_VERSION),
        'local' => null,
        'managed' => null,
    ]);
});

it('tells MariaDB from MySQL', function () {
    fakeMysql('5.5.5-10.11.6-MariaDB-0ubuntu0.24.04.1', ['host' => 'localhost']);

    expect(app(Reporter::class)->build()->toArray()['database'])
        ->toBe(['engine' => 'mariadb', 'version' => '10.11.6', 'local' => true, 'managed' => null]);
});

it('says whether the database is local or managed, but never where it is', function () {
    fakeMysql('8.0.36', ['host' => 'shop-db.c9akciq32.eu-west-2.rds.amazonaws.com']);

    $report = app(Reporter::class)->build()->toArray();

    expect($report['database'])->toBe(['engine' => 'mysql', 'version' => '8.0.36', 'local' => false, 'managed' => 'Amazon RDS'])
        ->and(json_encode($report))->not->toContain('shop-db')->not->toContain('forge')->not->toContain('hunter2');
});

it('counts a unix socket as local', function () {
    fakeMysql('8.0.36-0ubuntu0.22.04.1', ['host' => 'db.example.com', 'unix_socket' => '/var/run/mysqld/mysqld.sock']);

    expect(app(Reporter::class)->build()->toArray()['database'])
        ->toBe(['engine' => 'mysql', 'version' => '8.0.36', 'local' => true, 'managed' => null]);
});

it('sends null when it can’t reach the database', function () {
    fakeMysql(null);

    $report = app(Reporter::class)->build()->toArray();

    expect($report)->toHaveKey('database', null)
        ->and($report['platform']['name'])->toBe('laravel');
});
