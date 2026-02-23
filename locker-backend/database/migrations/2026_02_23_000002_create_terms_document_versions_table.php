<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terms_document_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('terms_document_id')
                ->constrained('terms_documents')
                ->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->longText('content');
            $table->boolean('is_published')->default(false);
            $table->boolean('is_active')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(['terms_document_id', 'version']);
            $table->index(['terms_document_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('terms_document_versions');
    }
};
