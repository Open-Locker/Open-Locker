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
            $table->string('content_note', 80)->nullable()->after('last_open_error_message');
            $table->dateTime('content_note_updated_at')->nullable()->after('content_note');
            $table->foreignId('content_note_updated_by_user_id')
                ->nullable()
                ->after('content_note_updated_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('compartments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('content_note_updated_by_user_id');
            $table->dropColumn(['content_note', 'content_note_updated_at']);
        });
    }
};
