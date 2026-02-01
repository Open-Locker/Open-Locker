<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compartments', function (Blueprint $table) {
            $table->dateTime('last_opened_at')->nullable()->after('address');
            $table->dateTime('last_open_failed_at')->nullable()->after('last_opened_at');
            $table->string('last_open_transaction_id')->nullable()->after('last_open_failed_at');
            $table->string('last_open_error_code')->nullable()->after('last_open_transaction_id');
            $table->text('last_open_error_message')->nullable()->after('last_open_error_code');
        });
    }

    public function down(): void
    {
        Schema::table('compartments', function (Blueprint $table) {
            $table->dropColumn([
                'last_opened_at',
                'last_open_failed_at',
                'last_open_transaction_id',
                'last_open_error_code',
                'last_open_error_message',
            ]);
        });
    }
};
