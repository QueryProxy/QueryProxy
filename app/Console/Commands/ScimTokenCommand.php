<?php

namespace App\Console\Commands;

use App\Models\ScimToken;
use App\Models\User;
use App\Services\Scim\ScimTokenService;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class ScimTokenCommand extends Command
{
    protected $signature = 'queryproxy:scim-token
        {action : create, revoke or list}
        {--name= : Name of the token (create)}
        {--id= : Id of the token (revoke)}
        {--by= : E-mail of the administrator doing it, for the audit log}';

    protected $description = 'Create, revoke or list the bearer tokens of SCIM clients (ADR-026)';

    public function handle(ScimTokenService $tokens): int
    {
        $actor = null;

        if ($this->option('by')) {
            $actor = User::query()->where('email', $this->option('by'))->first();

            if ($actor === null) {
                $this->components->error('No user has the e-mail address '.$this->option('by').'.');

                return self::FAILURE;
            }
        }

        return match ($this->argument('action')) {
            'create' => $this->create($tokens, $actor),
            'revoke' => $this->revoke($tokens, $actor),
            'list' => $this->list($tokens),
            default => $this->unknown(),
        };
    }

    private function create(ScimTokenService $tokens, ?User $actor): int
    {
        try {
            [$token, $plaintext] = $tokens->create((string) $this->option('name'), $actor);
        } catch (ValidationException $e) {
            $this->components->error($e->validator->errors()->first());

            return self::FAILURE;
        }

        $this->components->info("SCIM token #{$token->id} ({$token->name}) created. It is shown only now:");
        $this->line($plaintext);

        if (! config('queryproxy.scim.enabled')) {
            $this->components->warn('SCIM is off: set QUERYPROXY_SCIM_ENABLED=true for /scim/v2 to answer.');
        }

        return self::SUCCESS;
    }

    private function revoke(ScimTokenService $tokens, ?User $actor): int
    {
        $token = ctype_digit((string) $this->option('id')) ? ScimToken::query()->find((int) $this->option('id')) : null;

        if ($token === null) {
            $this->components->error('Give the id of an existing token with --id.');

            return self::FAILURE;
        }

        if (! $tokens->revoke($token, $actor)) {
            $this->components->warn("SCIM token #{$token->id} was already revoked.");

            return self::SUCCESS;
        }

        $this->components->info("SCIM token #{$token->id} ({$token->name}) revoked.");

        return self::SUCCESS;
    }

    private function list(ScimTokenService $tokens): int
    {
        $this->table(
            ['Id', 'Name', 'Created', 'Last used', 'Status'],
            $tokens->all()->map(fn (ScimToken $token) => [
                $token->id,
                $token->name,
                $token->created_at?->toDateTimeString(),
                $token->last_used_at?->toDateTimeString() ?? 'never',
                $token->isRevoked() ? 'revoked '.$token->revoked_at->toDateTimeString() : 'active',
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function unknown(): int
    {
        $this->components->error('The action must be create, revoke or list.');

        return self::FAILURE;
    }
}
