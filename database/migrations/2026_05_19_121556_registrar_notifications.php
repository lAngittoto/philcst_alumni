<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registrar_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('icon')->default('bell');
            $table->string('title');
            $table->text('message');
            $table->string('link_route')->nullable();   // e.g. 'registrar.alumni'
            $table->string('link_label')->nullable();   // e.g. 'View Alumni'
            $table->json('link_params')->nullable();    // route params if needed
            $table->boolean('read')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registrar_notifications');
    }
};