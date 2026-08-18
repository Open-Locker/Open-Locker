<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locker_banks', function (Blueprint $table): void {
            $table->dropUnique(['provisioning_token']);
            $table->dropColumn('provisioning_token');
        });

        Schema::table('locker_banks', function (Blueprint $table): void {
            $table->string('provisioning_token_hmac', 64)->nullable()->unique()->after('id');
            $table->uuid('provisioning_generation')->nullable()->after('provisioning_token_hmac');
        });
    }

    public function down(): void
    {
        Schema::table('locker_banks', function (Blueprint $table): void {
            $table->dropUnique(['provisioning_token_hmac']);
            $table->dropColumn(['provisioning_token_hmac', 'provisioning_generation']);
        });

        Schema::table('locker_banks', function (Blueprint $table): void {
            $table->string('provisioning_token', 64)->nullable()->unique()->after('id');
        });
    }
};
