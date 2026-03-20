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

            // ← TANGGALIN ang nullable() para palaging may user_id
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');

            $table->string('student_id')->unique()->index();

            // ── Split name fields ──────────────────────────────────────────
            $table->string('first_name');
            $table->string('middle_initial')->nullable();
            $table->string('last_name');
            $table->string('suffix')->nullable();

            // ── Computed full name (virtual) ───────────────────────────────
            $table->string('name')->virtualAs("TRIM(CONCAT_WS(' ', first_name, NULLIF(middle_initial,''), last_name, NULLIF(suffix,'')))");

            $table->string('email')->unique();
            $table->string('course_code')->nullable()->index();
            $table->string('course_name')->nullable();
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