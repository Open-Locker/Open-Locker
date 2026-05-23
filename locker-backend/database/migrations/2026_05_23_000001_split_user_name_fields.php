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
        Schema::table('users', function (Blueprint $table): void {
            $table->string('first_name')->default('')->after('id');
            $table->string('last_name')->nullable()->after('first_name');
        });

        DB::table('users')->update([
            'first_name' => DB::raw('name'),
        ]);

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('name')->default('')->after('id');
        });

        DB::table('users')->update([
            'name' => DB::raw("trim(first_name || ' ' || coalesce(last_name, ''))"),
        ]);

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['first_name', 'last_name']);
        });
    }
};
