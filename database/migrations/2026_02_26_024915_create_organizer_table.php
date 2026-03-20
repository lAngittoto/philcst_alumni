<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizer', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->onDelete('cascade');
            $table->string('id_number')->unique()->index();

            // ── Split name fields ──────────────────────────────────────────
            $table->string('first_name');
            $table->string('middle_initial')->nullable();
            $table->string('last_name');
            $table->string('suffix')->nullable();

            // ── Computed full name (virtual) ───────────────────────────────
            $table->string('name')->virtualAs("TRIM(CONCAT_WS(' ', first_name, NULLIF(middle_initial,''), last_name, NULLIF(suffix,'')))");

            $table->string('email')->unique();
            $table->string('department');
            $table->string('profile_photo')->nullable();
            $table->enum('status', ['ACTIVE', 'INACTIVE', 'SUSPENDED'])->default('ACTIVE')->index();
            $table->text('notes')->nullable();
            $table->timestamp('last_login')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizer');
    }
};