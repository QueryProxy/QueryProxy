<?php

use App\Database\ReadOnlyAwareLostConnectionDetector;
use Illuminate\Contracts\Database\LostConnectionDetector;

/**
 * The framework hands the detector the driver exception, not the
 * QueryException wrapping it (Connection::tryAgainIfCausedByLostConnection
 * passes $e->getPrevious()), so the fixture is the bare PDOException.
 */
function readOnlyAwareDetectorError(string $message): PDOException
{
    return new PDOException($message);
}

test('read-only errors are not a lost connection, so Laravel does not retry them', function (string $message) {
    $detector = app(LostConnectionDetector::class);

    expect($detector->causedByLostConnection(readOnlyAwareDetectorError($message)))->toBeFalse();
})->with([
    'pgsql 25006' => 'SQLSTATE[25006]: Read only sql transaction: 7 ERROR:  cannot execute INSERT in a read-only transaction',
    'mysql --read-only' => 'SQLSTATE[HY000]: General error: 1290 The MySQL server is running with the --read-only option so it cannot execute this statement',
    'mysql --super-read-only' => 'SQLSTATE[HY000]: General error: 1290 The MySQL server is running with the --super-read-only option so it cannot execute this statement',
    'mariadb --read-only' => 'SQLSTATE[HY000]: General error: 1290 The MariaDB server is running with the --read-only option so it cannot execute this statement',
    'mysql read only transaction' => 'SQLSTATE[25006]: Read-only SQL-transaction: 1792 Cannot execute statement in a READ ONLY transaction.',
]);

test('real lost connections are still detected', function (string $message) {
    $detector = app(LostConnectionDetector::class);

    expect($detector->causedByLostConnection(readOnlyAwareDetectorError($message)))->toBeTrue();
})->with([
    'mysql gone away' => 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away',
    'pgsql ssl closed' => 'SQLSTATE[08006] [7] SSL connection has been closed unexpectedly',
]);

test('an ordinary query error is not a lost connection', function () {
    $detector = app(LostConnectionDetector::class);

    expect($detector->causedByLostConnection(readOnlyAwareDetectorError('SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value')))->toBeFalse();
});

test('the container hands Laravel the read-only aware detector', function () {
    expect(app(LostConnectionDetector::class))->toBeInstanceOf(ReadOnlyAwareLostConnectionDetector::class)
        ->and(app(LostConnectionDetector::class))->toBe(app(LostConnectionDetector::class));
});
