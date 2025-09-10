<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vmq_auth_acl', function (Blueprint $table): void {
            $table->string('mountpoint', 10);
            $table->string('client_id', 128);
            $table->string('username', 128);
            $table->string('password', 128)->nullable();
            $table->text('publish_acl')->nullable();
            $table->text('subscribe_acl')->nullable();
            $table->primary(['mountpoint', 'client_id', 'username'], 'vmq_auth_acl_primary_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vmq_auth_acl');
    }
};
