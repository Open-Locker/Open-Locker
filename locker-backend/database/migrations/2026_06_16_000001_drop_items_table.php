<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('items');
    }

    /**
     * Recreate the table in its final pre-drop shape. The Item domain has been
     * removed (see ADR-0024), so this is a best-effort rollback only.
     */
    public function down(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('name');
            $table->string('description');
            $table->string('image_path')->nullable();
            $table->foreignUuid('compartment_id')->nullable()->constrained('compartments');
        });
    }
};
