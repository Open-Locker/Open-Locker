<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('terms_document_versions', function (Blueprint $table): void {
            $table->string('document_name_snapshot')
                ->nullable()
                ->after('terms_document_id');
        });
    }

    public function down(): void
    {
        Schema::table('terms_document_versions', function (Blueprint $table): void {
            $table->dropColumn('document_name_snapshot');
        });
    }
};
