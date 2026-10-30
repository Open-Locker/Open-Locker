<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firmware_releases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Semantic versioning, at most the 31 characters of the image's version field (ADR-0076).
            $table->string('version', 31)->unique();
            $table->string('file_path');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            // A release marked invalid is never offered or served again (ADR-0077).
            $table->boolean('invalid')->default(false);
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // created_at is when it was uploaded.
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firmware_releases');
    }
};
