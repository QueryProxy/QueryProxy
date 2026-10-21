<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-team key for the `hmac` masking strategy. Stored encrypted (the
     * model's `encrypted` cast reads it back); existing teams get a fresh
     * key here so `hmac` works for them without a manual step.
     */
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->text('masking_key')->nullable()->after('slug');
        });

        DB::table('teams')->whereNull('masking_key')->eachById(function (object $team) {
            DB::table('teams')->where('id', $team->id)->update([
                'masking_key' => Crypt::encryptString(base64_encode(random_bytes(32))),
            ]);
        });
    }

    /**
     * Rolling back drops every team's masking key for good. Running `up`
     * again generates new keys, so `hmac:` values stored before the rollback
     * (saved results, exports) no longer match results made afterwards. Unlike
     * `queryproxy:masking:rotate-key` this leaves no audit entry.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('masking_key');
        });
    }
};
