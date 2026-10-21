<?php

use App\Enums\AuditEvent;
use App\Models\AuditLog;

test('every audit event used in app code is in the catalog', function () {
    $used = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all("/record\(\s*'([^']+)'/", file_get_contents($file->getPathname()), $matches);

        foreach ($matches[1] as $name) {
            $used[$name] = $file->getFilename();
        }
    }

    expect($used)->not->toBeEmpty()
        ->and(array_diff(array_keys($used), AuditEvent::values()))->toBe([]);
});

test('every catalog event is recorded somewhere in app code', function () {
    $source = '';

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php' && $file->getFilename() !== 'AuditEvent.php') {
            $source .= file_get_contents($file->getPathname());
        }
    }

    $unused = array_filter(
        AuditEvent::cases(),
        fn (AuditEvent $event) => ! str_contains($source, "'{$event->value}'")
            && ! str_contains($source, 'AuditEvent::'.$event->name),
    );

    expect(array_map(fn (AuditEvent $e) => $e->value, $unused))->toBe([]);
});

test('catalog names are unique, dotted lower-case and described', function () {
    $values = AuditEvent::values();

    expect($values)->toHaveCount(count(array_unique($values)));

    foreach (AuditEvent::cases() as $event) {
        expect($event->value)->toMatch('/^[a-z_]+\.[a-z_]+$/')
            ->and($event->description())->not->toBe('')
            ->and($event->category())->toBe(explode('.', $event->value)[0]);
    }

    expect(AuditEvent::catalog())->toHaveCount(count($values));
});

test('the recorder accepts a catalog event', function () {
    $log = audit()->record(AuditEvent::AuthLogin);

    expect($log->action)->toBe('auth.login')
        ->and(AuditLog::where('action', 'auth.login')->count())->toBe(1);
});
