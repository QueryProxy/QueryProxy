<?php

namespace App\Services\Doctor\Checks;

use App\Models\Connection;
use App\Services\Doctor\CheckResult;
use App\Services\Doctor\NeedsDatabase;
use App\Services\Secrets\SecretReference;
use App\Services\Secrets\SecretResolver;
use Throwable;

/**
 * External secret references (ADR-028): are the stores that stored references
 * point at configured, and are the stored references well formed?
 *
 * Static only: no secret store is contacted, so the report is safe to run on a
 * host that cannot reach them. A stored reference outside its team's allowed
 * prefixes (ADR-028 amendment) is reported too: it can no longer be resolved. "Test connection" in the UI resolves for real.
 */
class SecretStoreCheck extends BaseCheck implements NeedsDatabase
{
    public function __construct(private readonly ?SecretResolver $resolver = null) {}

    public function name(): string
    {
        return 'secret_stores';
    }

    public function run(): CheckResult
    {
        $resolver = $this->resolver ?? app(SecretResolver::class);

        /** @var array<string, int> $used connections per scheme */
        $used = [];
        $malformed = [];
        $notAllowed = [];

        Connection::query()->with('team')->orderBy('id')->each(function (Connection $connection) use (&$used, &$malformed, &$notAllowed) {
            try {
                $values = [$connection->password, $connection->read_password];
            } catch (Throwable) {
                return; // not decryptable: the application key check reports that
            }

            $schemes = [];

            foreach ($values as $value) {
                if (! SecretReference::looksLikeReference($value)) {
                    continue;
                }

                try {
                    $reference = SecretReference::parse((string) $value);
                } catch (Throwable) {
                    $malformed[] = $connection->name;

                    continue;
                }

                $schemes[$reference->scheme] = true;

                if ($connection->team === null || ! $connection->team->allowsSecretReference($reference)) {
                    $notAllowed[] = $connection->name;
                }
            }

            foreach (array_keys($schemes) as $scheme) {
                $used[$scheme] = ($used[$scheme] ?? 0) + 1;
            }
        });

        $failures = [];
        $warnings = [];
        $summary = [];

        if ($malformed !== []) {
            $failures[] = 'malformed secret reference on '.$this->nameList(array_unique($malformed));
        }

        if ($notAllowed !== []) {
            $failures[] = 'reference outside the team\'s secret reference prefixes (these connections fail closed; set the prefixes with queryproxy:team:secret-prefixes) on '.$this->nameList(array_unique($notAllowed));
        }

        foreach (SecretReference::schemes() as $scheme) {
            $driver = $resolver->driver($scheme);
            $inUse = $used[$scheme] ?? 0;

            if ($inUse === 0 && ! $driver->isConfigured()) {
                continue;
            }

            $diagnosis = $driver->diagnose();
            $summary[] = $scheme.($inUse > 0 ? " ({$inUse} connection(s))" : ' (configured, unused)');

            foreach ($diagnosis['problems'] as $problem) {
                if ($inUse > 0) {
                    $failures[] = "{$scheme}: {$problem}";
                } else {
                    $warnings[] = "{$scheme}: {$problem}";
                }
            }

            foreach ($diagnosis['warnings'] as $warning) {
                $warnings[] = "{$scheme}: {$warning}";
            }
        }

        if ($failures !== []) {
            return $this->fail(implode(' ', array_map(fn ($line) => rtrim($line, '.').'.', array_merge($failures, $warnings))));
        }

        if ($warnings !== []) {
            return $this->warn(implode(' ', array_map(fn ($line) => rtrim($line, '.').'.', $warnings)));
        }

        if ($summary === []) {
            return $this->skip('No connection uses an external secret reference and no secret store is configured.');
        }

        return $this->ok('Secret stores ready: '.implode(', ', $summary).'.');
    }
}
