<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locker_banks', function (Blueprint $table): void {
            $table->timestamp('provisioning_token_issued_at')->nullable()->after('provisioning_generation');
        });

        // Tokens outstanding at deploy time get 24 hours from now.
        DB::table('locker_banks')
            ->whereNotNull('provisioning_token_hmac')
            ->update(['provisioning_token_issued_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('locker_banks', function (Blueprint $table): void {
            $table->dropColumn('provisioning_token_issued_at');
        });
    }
};
