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
    Schema::create('director', function (Blueprint $table) {
        $table->id();

        $table->foreignId('user_id')
              ->unique()
              ->constrained('users')
              ->onDelete('cascade');

        // Add whatever other columns your Director model uses
        // Check your Director model's $fillable for clues
        $table->string('status')->nullable();
        $table->timestamp('last_seen_at')->nullable()->index();

        $table->timestamps();
        $table->softDeletes(); // needed because query checks deleted_at
    });
}

};
