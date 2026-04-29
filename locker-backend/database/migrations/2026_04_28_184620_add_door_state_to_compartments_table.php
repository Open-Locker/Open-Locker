<?php

declare(strict_types=1);

use App\Enums\CompartmentDoorState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('compartments', function (Blueprint $table): void {
            $table->string('door_state', 16)
                ->default(CompartmentDoorState::Unknown->value);
            $table->timestamp('door_state_changed_at')->nullable();
        });

        Schema::table('locker_banks', function (Blueprint $table): void {
            $table->timestamp('last_compartment_state_change_at')->nullable()->after('connection_status_changed_at');
        });
    }

    public function down(): void
    {
        Schema::table('locker_banks', function (Blueprint $table): void {
            $table->dropColumn('last_compartment_state_change_at');
        });

        Schema::table('compartments', function (Blueprint $table): void {
            $table->dropColumn([
                'door_state',
                'door_state_changed_at',
            ]);
        });
    }
};
