<?php

use App\Support\SecretFile;
use Symfony\Component\Process\Process;

function secretFileDir(): string
{
    $directory = rtrim((string) (getenv('TMPDIR') ?: sys_get_temp_dir()), '/').'/qp-secret-'.bin2hex(random_bytes(4));
    mkdir($directory, 0700, true);

    return $directory;
}

function secretLookup(array $env): Closure
{
    return fn (string $name) => $env[$name] ?? null;
}

test('SecretFile returns the plain variable or the default', function () {
    expect(SecretFile::get('DB_PASSWORD', null, secretLookup(['DB_PASSWORD' => 'plain'])))->toBe('plain')
        ->and(SecretFile::get('DB_PASSWORD', 'fallback', secretLookup([])))->toBe('fallback')
        ->and(SecretFile::get('DB_PASSWORD', null, secretLookup([])))->toBeNull()
        ->and(SecretFile::get('DB_PASSWORD', 'fallback', secretLookup(['DB_PASSWORD' => ''])))->toBe('fallback');
});

test('SecretFile reads NAME_FILE and strips trailing line breaks only', function (string $contents, string $expected) {
    $directory = secretFileDir();
    file_put_contents($directory.'/secret', $contents);

    expect(SecretFile::get('DB_PASSWORD', null, secretLookup(['DB_PASSWORD_FILE' => $directory.'/secret'])))->toBe($expected);

    unlink($directory.'/secret');
    rmdir($directory);
})->with([
    'trailing newline' => ["s3cr3t\n", 's3cr3t'],
    'crlf' => ["s3cr3t\r\n", 's3cr3t'],
    'no newline' => ['s3cr3t', 's3cr3t'],
    'inner spaces and leading space kept' => [" p@ss word \n", ' p@ss word '],
    'metacharacters' => ['$HOME "q" `x` ; \\', '$HOME "q" `x` ; \\'],
]);

test('SecretFile refuses NAME together with NAME_FILE without leaking the value', function () {
    $directory = secretFileDir();
    file_put_contents($directory.'/secret', 'from-file');

    try {
        SecretFile::get('DB_PASSWORD', null, secretLookup(['DB_PASSWORD' => 'from-env', 'DB_PASSWORD_FILE' => $directory.'/secret']));
        $this->fail('Expected a RuntimeException.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Both DB_PASSWORD and DB_PASSWORD_FILE')
            ->and($e->getMessage())->not->toContain('from-env')
            ->and($e->getMessage())->not->toContain('from-file');
    }

    unlink($directory.'/secret');
    rmdir($directory);
});

test('SecretFile rejects a missing, empty or directory path', function () {
    $directory = secretFileDir();
    file_put_contents($directory.'/empty', "\n");

    foreach ([$directory.'/missing', $directory.'/empty', $directory] as $path) {
        expect(fn () => SecretFile::get('DB_PASSWORD', null, secretLookup(['DB_PASSWORD_FILE' => $path])))
            ->toThrow(RuntimeException::class, 'DB_PASSWORD_FILE');
    }

    unlink($directory.'/empty');
    rmdir($directory);
});

test('SecretFile treats an empty NAME_FILE as not set', function () {
    expect(SecretFile::get('DB_PASSWORD', 'x', secretLookup(['DB_PASSWORD_FILE' => ''])))->toBe('x');
});

test('SecretFile reads the real environment', function () {
    $directory = secretFileDir();
    file_put_contents($directory.'/secret', "from-process\n");
    putenv('MAIL_PASSWORD_FILE='.$directory.'/secret');

    try {
        expect(SecretFile::get('MAIL_PASSWORD'))->toBe('from-process');
    } finally {
        putenv('MAIL_PASSWORD_FILE');
        unlink($directory.'/secret');
        rmdir($directory);
    }
});

test('SecretFile reads the environment like env(): null and (empty) placeholders mean not set', function (string $placeholder, mixed $expected) {
    putenv('REDIS_PASSWORD='.$placeholder);

    try {
        expect(SecretFile::get('REDIS_PASSWORD'))->toBe($expected)
            ->and(SecretFile::get('REDIS_PASSWORD', 'fallback'))->toBe($expected ?? 'fallback');
    } finally {
        putenv('REDIS_PASSWORD');
    }
})->with([
    'null' => ['null', null],
    '(null)' => ['(null)', null],
    'NULL' => ['NULL', null],
    '(empty)' => ['(empty)', null],
    'literal password' => ['hunter2', 'hunter2'],
    'true stays text' => ['true', 'true'],
    'false stays text' => ['false', 'false'],
]);

test('a null placeholder next to NAME_FILE is not a conflict', function () {
    $directory = secretFileDir();
    file_put_contents($directory.'/secret', "from-file\n");
    putenv('REDIS_PASSWORD=null');
    putenv('REDIS_PASSWORD_FILE='.$directory.'/secret');

    try {
        expect(SecretFile::get('REDIS_PASSWORD'))->toBe('from-file');
    } finally {
        putenv('REDIS_PASSWORD');
        putenv('REDIS_PASSWORD_FILE');
        unlink($directory.'/secret');
        rmdir($directory);
    }
});

test('a NAME_FILE path is taken verbatim, like the entrypoint does', function () {
    $directory = secretFileDir();
    file_put_contents($directory.'/null', "file-called-null\n");
    file_put_contents($directory.'/secret', "plain-path\n");
    $previous = getcwd();
    chdir($directory);

    try {
        // "null" is a relative path to a file named null, not "unset".
        putenv('MAIL_PASSWORD_FILE=null');
        expect(SecretFile::get('MAIL_PASSWORD'))->toBe('file-called-null');

        // Quotes in a process environment value are part of the path.
        putenv('MAIL_PASSWORD_FILE="'.$directory.'/secret"');
        expect(fn () => SecretFile::get('MAIL_PASSWORD'))->toThrow(RuntimeException::class, 'not a readable file');
    } finally {
        chdir($previous);
        putenv('MAIL_PASSWORD_FILE');
        unlink($directory.'/null');
        unlink($directory.'/secret');
        rmdir($directory);
    }
});

test('config does not turn REDIS_PASSWORD=null into the password "null"', function () {
    $process = new Process(
        [PHP_BINARY, '-r', 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo json_encode([config("database.redis.default.password"), config("mail.mailers.smtp.password")]);'],
        base_path(),
        ['REDIS_PASSWORD' => 'null', 'MAIL_PASSWORD' => '(null)', 'APP_KEY' => config('app.key'), 'APP_ENV' => 'testing'],
    );
    $process->run();

    expect(trim($process->getOutput()))->toBe('[null,null]');
});

test('SecretFile inspect lists what is in use and what is broken', function () {
    $directory = secretFileDir();
    file_put_contents($directory.'/ok', 'value');

    $report = SecretFile::inspect(secretLookup([
        'MAIL_PASSWORD_FILE' => $directory.'/ok',
        'DB_PASSWORD_FILE' => $directory.'/missing',
        'REDIS_PASSWORD' => 'plain',
        'REDIS_PASSWORD_FILE' => $directory.'/ok',
    ]));

    expect($report['in_use'])->toBe(['MAIL_PASSWORD'])
        ->and($report['problems'])->toHaveCount(2);

    unlink($directory.'/ok');
    rmdir($directory);
});

test('config reads the secrets from files in a fresh process', function () {
    $directory = secretFileDir();
    file_put_contents($directory.'/db', "db-secret\n");
    file_put_contents($directory.'/mail', "mail-secret\n");

    $process = new Process(
        [PHP_BINARY, '-r', 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo json_encode([config("database.connections.pgsql.password"), config("mail.mailers.smtp.password")]);'],
        base_path(),
        ['DB_PASSWORD_FILE' => $directory.'/db', 'MAIL_PASSWORD_FILE' => $directory.'/mail', 'APP_KEY' => config('app.key'), 'APP_ENV' => 'testing'],
    );
    $process->run();

    expect(trim($process->getOutput()))->toBe(json_encode(['db-secret', 'mail-secret']));

    unlink($directory.'/db');
    unlink($directory.'/mail');
    rmdir($directory);
});

test('config fails loudly when a secret is given twice', function () {
    $directory = secretFileDir();
    file_put_contents($directory.'/db', 'db-secret');

    $process = new Process(
        [PHP_BINARY, 'artisan', 'about', '--only=environment'],
        base_path(),
        ['DB_PASSWORD' => 'plain', 'DB_PASSWORD_FILE' => $directory.'/db', 'APP_KEY' => config('app.key'), 'APP_ENV' => 'testing'],
    );
    $process->run();

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getOutput().$process->getErrorOutput())->toContain('Both DB_PASSWORD and DB_PASSWORD_FILE')
        ->and($process->getOutput().$process->getErrorOutput())->not->toContain('db-secret');

    unlink($directory.'/db');
    rmdir($directory);
});

test('the entrypoint secret loader passes its shell test', function () {
    $process = new Process(['sh', base_path('tests/Shell/secret-files.test.sh')], base_path());
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput())
        ->and($process->getOutput())->toContain('checks passed');
});

test('docker/entrypoint.sh and secret-files.sh are valid shell and agree with SecretFile', function () {
    foreach (['docker/entrypoint.sh', 'docker/secret-files.sh'] as $script) {
        $process = new Process(['sh', '-n', base_path($script)]);
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
    }

    preg_match('/SECRET_FILE_VARIABLES="([^"]+)"/', file_get_contents(base_path('docker/secret-files.sh')), $matches);

    expect(explode(' ', $matches[1]))->toBe(SecretFile::VARIABLES);
});
