<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retain revoked role->permission bindings instead of deleting them, so the
 * admin UI can show a grant/revoke audit trail (granted/revoked at + by), like
 * the compartment-access read models. Active = revoked_at IS NULL. See ADR-0026
 * (supersedes the delete-on-revoke behaviour of ADR-0021).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_permissions', function (Blueprint $table): void {
            $table->timestamp('revoked_at')->nullable()->after('granted_at');
            $table->foreignId('revoked_by_user_id')->nullable()->after('revoked_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('role_permissions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('revoked_by_user_id');
            $table->dropColumn('revoked_at');
        });
    }
};
