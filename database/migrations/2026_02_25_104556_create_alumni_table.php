<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alumni', function (Blueprint $table) {
            $table->id();
            $table->string('student_id')->unique()->index();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('course_code')->index();
            $table->string('course_name');
            $table->integer('batch')->index();
            $table->enum('status', ['VERIFIED', 'PENDING', 'REJECTED'])->default('PENDING')->index();
            $table->string('profile_photo')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alumni');
    }
};