<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Splits command acknowledgement from door-open detection.
 *
 * `opened_at` keeps its column but changes meaning: it now records when the door
 * was observed open, not when the pulse was sent. Rows written before this
 * migration carry the old meaning and are deliberately not reinterpreted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compartment_open_requests', function (Blueprint $table): void {
            $table->dateTime('acknowledged_at')
                ->nullable()
                ->after('sent_at');

            $table->unsignedInteger('open_detection_ms')
                ->nullable()
                ->after('opened_at');
        });
    }

    public function down(): void
    {
        Schema::table('compartment_open_requests', function (Blueprint $table): void {
            $table->dropColumn(['acknowledged_at', 'open_detection_ms']);
        });
    }
};
