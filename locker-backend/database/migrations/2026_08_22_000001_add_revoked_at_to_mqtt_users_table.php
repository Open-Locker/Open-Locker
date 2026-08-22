<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mqtt_users', function (Blueprint $table): void {
            // Revoked identities are disabled and kept, never deleted: the unique
            // index on `username` is what stops a retired identity being issued
            // again. Null means "not revoked"; existing rows are unaffected.
            $table->timestamp('revoked_at')->nullable()->after('enabled');
        });
    }

    public function down(): void
    {
        Schema::table('mqtt_users', function (Blueprint $table): void {
            $table->dropColumn('revoked_at');
        });
    }
};
