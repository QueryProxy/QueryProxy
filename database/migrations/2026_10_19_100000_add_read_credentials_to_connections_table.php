<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Optional read-only identity: SELECTs run as this database user when
        // set. Stored with the same `encrypted` cast as the other credentials.
        Schema::table('connections', function (Blueprint $table) {
            $table->text('read_username')->nullable()->after('password');
            $table->text('read_password')->nullable()->after('read_username');
        });
    }

    public function down(): void
    {
        Schema::table('connections', function (Blueprint $table) {
            $table->dropColumn(['read_username', 'read_password']);
        });
    }
};
