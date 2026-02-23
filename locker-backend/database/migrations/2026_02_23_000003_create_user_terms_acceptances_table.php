<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_terms_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->foreignId('terms_document_id')
                ->constrained('terms_documents')
                ->cascadeOnDelete();
            $table->foreignId('terms_document_version_id')
                ->constrained('terms_document_versions')
                ->cascadeOnDelete();
            $table->timestamp('accepted_at');
            $table->timestamps();

            $table->unique(
                ['user_id', 'terms_document_id', 'terms_document_version_id'],
                'user_terms_acceptances_user_document_version_unique'
            );
            $table->index(['user_id', 'terms_document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_terms_acceptances');
    }
};
