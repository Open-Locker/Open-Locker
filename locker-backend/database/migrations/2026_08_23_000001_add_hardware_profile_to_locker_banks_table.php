<?php

declare(strict_types=1);

use App\Enums\LockerAdapterType;
use App\Enums\LockerFeedbackType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locker_banks', function (Blueprint $table) {
            $table->string('adapter_type')
                ->default(LockerAdapterType::WaveshareModbus->value)
                ->after('location_description');
            $table->unsignedSmallInteger('channel_count')
                ->default(8)
                ->after('adapter_type');
            $table->string('feedback_type')
                ->default(LockerFeedbackType::DoorClosing->value)
                ->after('channel_count');
        });
    }

    public function down(): void
    {
        Schema::table('locker_banks', function (Blueprint $table) {
            $table->dropColumn([
                'adapter_type',
                'channel_count',
                'feedback_type',
            ]);
        });
    }
};
