<?php

use App\Enums\MaskStrategy;
use App\Models\AuditLog;
use App\Models\MaskingRule;
use App\Models\Team;
use App\Services\Masking\Masker;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

function hmacRule(Team $team, string $pattern = '*tckn*'): MaskingRule
{
    return MaskingRule::create([
        'team_id' => $team->id,
        'name' => 'hmac '.$pattern,
        'match_type' => 'column',
        'pattern' => $pattern,
        'strategy' => 'hmac',
    ]);
}

test('a new team gets an encrypted masking key', function () {
    $team = Team::factory()->create();

    $stored = DB::table('teams')->where('id', $team->id)->value('masking_key');

    expect($team->fresh()->masking_key)->toBeString()->not->toBeEmpty()
        ->and($stored)->not->toBe($team->fresh()->masking_key)
        ->and(decrypt($stored, false))->toBe($team->fresh()->masking_key);
});

test('every team gets its own key', function () {
    expect(Team::factory()->create()->masking_key)->not->toBe(Team::factory()->create()->masking_key);
});

test('the masking key is never serialised', function () {
    $team = Team::factory()->create();

    expect($team->toArray())->not->toHaveKey('masking_key')
        ->and($team->toJson())->not->toContain($team->masking_key);
});

test('hmac output has the hmac prefix and 16 hex characters', function () {
    $team = Team::factory()->create();

    expect(app(Masker::class)->apply(MaskStrategy::Hmac, '10000000146', $team->id))
        ->toMatch('/\Ahmac:[0-9a-f]{16}\z/');
});

test('hmac is deterministic inside a team', function () {
    $team = Team::factory()->create();

    expect(app(Masker::class)->apply(MaskStrategy::Hmac, 'ada', $team->id))
        ->toBe(app(Masker::class)->apply(MaskStrategy::Hmac, 'ada', $team->id))
        ->not->toBe(app(Masker::class)->apply(MaskStrategy::Hmac, 'grace', $team->id));
});

test('hmac is bound to the team key', function () {
    $first = Team::factory()->create();
    $second = Team::factory()->create();

    expect(app(Masker::class)->apply(MaskStrategy::Hmac, 'ada', $first->id))
        ->not->toBe(app(Masker::class)->apply(MaskStrategy::Hmac, 'ada', $second->id));
});

test('hmac output is not a plain sha256 prefix of the value', function () {
    $team = Team::factory()->create();
    $masked = app(Masker::class)->apply(MaskStrategy::Hmac, '10000000146', $team->id);

    expect($masked)->not->toContain(substr(hash('sha256', '10000000146'), 0, 12))
        ->and(substr($masked, 5))->not->toBe(substr(hash('sha256', '10000000146'), 0, 16));
});

test('hmac fails closed without a team or a key', function () {
    $team = Team::factory()->create();
    DB::table('teams')->where('id', $team->id)->update(['masking_key' => null]);

    expect(app(Masker::class)->apply(MaskStrategy::Hmac, 'ada'))->toBe('*****')
        ->and(app(Masker::class)->apply(MaskStrategy::Hmac, 'ada', 999999))->toBe('*****')
        ->and(app(Masker::class)->apply(MaskStrategy::Hmac, 'ada', $team->id))->toBe('*****');
});

test('an hmac column rule masks rows with the team key and keeps joins possible', function () {
    $team = Team::factory()->create();
    $rules = collect([hmacRule($team)]);
    $masker = app(Masker::class);

    $one = $masker->maskRow(['tckn' => '10000000146', 'name' => 'Ada'], $rules);
    $two = $masker->maskRow(['tckn' => '10000000146', 'name' => 'Ada'], $rules);

    expect($one['tckn'])->toStartWith('hmac:')->and($one['tckn'])->toBe($two['tckn'])
        ->and($one['name'])->toBe('Ada');
});

test('hmac masking is idempotent on already-masked values in text', function () {
    $team = Team::factory()->create();
    $rules = collect([hmacRule($team)]);
    $masker = app(Masker::class);

    $token = $masker->apply(MaskStrategy::Hmac, '10000000146', $team->id);

    expect($masker->maskText("Key (tckn)=({$token}) already exists", $rules))
        ->toBe("Key (tckn)=({$token}) already exists");
});

test('rotating the key changes the pseudonyms', function () {
    $team = Team::factory()->create();
    $before = app(Masker::class)->apply(MaskStrategy::Hmac, 'ada', $team->id);
    $oldKey = $team->fresh()->masking_key;

    $team->fresh()->rotateMaskingKey();

    // A new Masker instance: the key cache lives per instance, i.e. per job.
    $after = (new Masker)->apply(MaskStrategy::Hmac, 'ada', $team->id);

    expect($team->fresh()->masking_key)->not->toBe($oldKey)
        ->and($after)->not->toBe($before);
});

test('rotate command rotates by id and by slug', function () {
    $team = Team::factory()->create();
    $key = $team->masking_key;

    $this->artisan('queryproxy:masking:rotate-key', ['team' => (string) $team->id, '--force' => true])
        ->expectsOutputToContain('rotated')
        ->assertExitCode(0);

    $afterId = $team->fresh()->masking_key;

    $this->artisan('queryproxy:masking:rotate-key', ['team' => $team->slug, '--force' => true])->assertExitCode(0);

    expect($afterId)->not->toBe($key)
        ->and($team->fresh()->masking_key)->not->toBe($afterId);
});

test('rotate command warns that old and new pseudonyms stop matching', function () {
    $team = Team::factory()->create();

    $this->artisan('queryproxy:masking:rotate-key', ['team' => $team->slug, '--force' => true])
        ->expectsOutputToContain('no longer match')
        ->assertExitCode(0);
});

test('rotate command asks for confirmation and leaves the key on refusal', function () {
    $team = Team::factory()->create();
    $key = $team->masking_key;

    $this->artisan('queryproxy:masking:rotate-key', ['team' => $team->slug])
        ->expectsConfirmation("Rotate the masking key of team \"{$team->name}\"?", 'no')
        ->assertExitCode(1);

    expect($team->fresh()->masking_key)->toBe($key);
});

test('rotate command rotates after confirmation', function () {
    $team = Team::factory()->create();
    $key = $team->masking_key;

    $this->artisan('queryproxy:masking:rotate-key', ['team' => $team->slug])
        ->expectsConfirmation("Rotate the masking key of team \"{$team->name}\"?", 'yes')
        ->assertExitCode(0);

    expect($team->fresh()->masking_key)->not->toBe($key);
});

test('rotate command prefers an exact id over a team whose slug is the same digits', function () {
    $idTeam = Team::factory()->create();
    $slugTeam = Team::factory()->create(['slug' => (string) $idTeam->id]);
    $idKey = $idTeam->masking_key;
    $slugKey = $slugTeam->masking_key;

    $this->artisan('queryproxy:masking:rotate-key', ['team' => (string) $idTeam->id, '--force' => true])->assertExitCode(0);

    expect($idTeam->fresh()->masking_key)->not->toBe($idKey)
        ->and($slugTeam->fresh()->masking_key)->toBe($slugKey);
});

test('a key that no longer decrypts can still be rotated', function () {
    $team = Team::factory()->create();
    DB::table('teams')->where('id', $team->id)->update(['masking_key' => 'not-a-valid-payload']);

    expect(fn () => Team::find($team->id)->masking_key)->toThrow(DecryptException::class);

    $this->artisan('queryproxy:masking:rotate-key', ['team' => $team->slug, '--force' => true])->assertExitCode(0);

    expect(Team::find($team->id)->masking_key)->toBeString()->not->toBeEmpty()
        ->and((new Masker)->apply(MaskStrategy::Hmac, 'ada', $team->id))->toStartWith('hmac:');
});

test('rotate command fails for an unknown team', function () {
    $this->artisan('queryproxy:masking:rotate-key', ['team' => 'no-such-team', '--force' => true])
        ->assertExitCode(1);
});

test('rotate command records an audit entry without the key and never prints it', function () {
    $team = Team::factory()->create();

    Artisan::call('queryproxy:masking:rotate-key', ['team' => $team->slug, '--force' => true]);

    $newKey = $team->fresh()->masking_key;
    $entry = AuditLog::where('action', 'masking_key.rotated')->where('team_id', $team->id)->firstOrFail();

    expect(Artisan::output())->not->toContain($newKey)
        ->and(json_encode($entry->metadata))->not->toContain($newKey);
});

test('the migration backfills a key for teams that have none', function () {
    $team = Team::factory()->create();
    DB::table('teams')->where('id', $team->id)->update(['masking_key' => null]);

    $migration = require base_path('database/migrations/2026_10_20_000002_add_masking_key_to_teams_table.php');
    Schema::table('teams', fn ($table) => $table->dropColumn('masking_key'));
    $migration->up();

    expect($team->fresh()->masking_key)->toBeString()->not->toBeEmpty();
});
