<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('item_loans');
    }

    public function down(): void
    {
        Schema::create('item_loans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('borrowed_at');
            $table->timestamp('returned_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['item_id', 'returned_at']);
            $table->index(['user_id', 'returned_at']);
            $table->index('borrowed_at');
        });
    }
};
