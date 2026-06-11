<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('director_notifications', function (Blueprint $table) {
            $table->id();

            // Use unsignedBigInteger instead of foreignId()->constrained()
            // to avoid "referenced table not found" if directors migrates after this file.
            // The foreign key is added separately below so it only runs when directors exists.
            $table->unsignedBigInteger('director_id')->index();

            $table->string('icon')->default('bell');
            $table->string('title');
            $table->text('message');

            $table->string('link_route')->nullable();
            $table->string('link_label')->nullable();

            $table->string('dedup_key')->nullable()->index();
            $table->unsignedInteger('count')->default(1);
            $table->boolean('read')->default(false)->index();

            $table->timestamps();

            $table->index(['director_id', 'read']);
            $table->index(['director_id', 'created_at']);
        });

        // Only add the FK constraint if the directors table already exists
        if (Schema::hasTable('directors')) {
            Schema::table('director_notifications', function (Blueprint $table) {
                $table->foreign('director_id')
                      ->references('id')
                      ->on('directors')
                      ->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('director_notifications');
    }
};