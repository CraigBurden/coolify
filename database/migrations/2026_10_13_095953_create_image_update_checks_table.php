<?php

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
        Schema::create('image_update_checks', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id');
            $table->morphs('resource');
            $table->string('service_name');
            $table->string('frequency')->nullable();
            $table->string('on_update')->default('notify');
            $table->string('image')->nullable();
            $table->string('current_digest')->nullable();
            $table->string('latest_digest')->nullable();
            $table->string('auto_deployed_digest')->nullable();
            $table->boolean('update_available')->default(false);
            $table->timestampTz('last_checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['resource_type', 'resource_id', 'service_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('image_update_checks');
    }
};
