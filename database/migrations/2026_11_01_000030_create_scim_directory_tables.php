<?php

use App\Support\SqlServerReferentialActions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What SCIM (ADR-026) needs to remember about the identity provider's
 * directory: the groups it pushes, who is in them, and per user the SCIM-side
 * identifiers. A user created through SCIM is told apart by
 * scim_provisioned_at; local accounts never get it, so SCIM cannot see or
 * change them.
 *
 * suspended_by_scim records who put the current suspension there: SCIM lifts
 * only the suspension it set itself, never one an administrator set by hand.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('scim_provisioned_at')->nullable();
            // SCIM `externalId`: the identity provider's own id for the user.
            // Not the OIDC subject, which stays in external_id.
            $table->string('scim_external_id')->nullable()->index();
            // SCIM `userName` as the provider sent it, when it differs from the
            // e-mail address (Entra ID sends the UPN).
            $table->string('scim_user_name')->nullable();
            // True while the suspension in suspended_at was set by SCIM
            // (`active=false` or DELETE); false for a manual one.
            $table->boolean('suspended_by_scim')->default(false);
        });

        Schema::create('scim_groups', function (Blueprint $table) {
            $table->id();
            $table->string('display_name')->unique();
            $table->string('external_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('scim_group_user', function (Blueprint $table) {
            $table->id();
            SqlServerReferentialActions::cascadeOnDelete($table, $table->foreignId('scim_group_id')->constrained('scim_groups'));
            SqlServerReferentialActions::cascadeOnDelete($table, $table->foreignId('user_id')->constrained('users'));
            $table->timestamps();

            $table->unique(['scim_group_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scim_group_user');
        Schema::dropIfExists('scim_groups');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['scim_external_id']);
            $table->dropColumn(['scim_provisioned_at', 'scim_external_id', 'scim_user_name', 'suspended_by_scim']);
        });
    }
};
