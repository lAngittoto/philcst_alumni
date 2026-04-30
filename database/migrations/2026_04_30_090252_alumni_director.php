<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds:
     *  - director.middle_name  — for full name display in View Profile
     *  - director.suffix       — e.g. Jr., Sr., III
     *  - [director.email](http://director.email)        — real email address stored on record
     *  - users.user_status     — allows Activate / Deactivate for registrar & admin roles
     */
    public function up(): void
    {
        // ── Director table additions ──────────────────────────────────────────
        Schema::table('director', function (Blueprint $table) {
            // middle_name goes after first_name (which already exists)
            if (! Schema::hasColumn('director', 'middle_name')) {
                $table->string('middle_name')->nullable()->after('first_name');
            }
            // suffix goes after last_name (which already exists)
            if (! Schema::hasColumn('director', 'suffix')) {
                $table->string('suffix', 20)->nullable()->after('last_name');
            }
            // real email address for the director (not the @director.internal login)
            if (! Schema::hasColumn('director', 'email')) {
                $table->string('email')->nullable()->after('suffix');
            }
        });
        // ── Users table: add user_status for registrar / admin toggling ───────
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'user_status')) {
                $table->string('user_status', 20)
                      ->default('ACTIVE')
                      ->after('role')
                      ->comment('Used for roles that lack their own profile table (registrar, admin)');
            }
        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('director', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('director', 'middle_name')) $cols[] = 'middle_name';
            if (Schema::hasColumn('director', 'suffix'))      $cols[] = 'suffix';
            if (Schema::hasColumn('director', 'email'))       $cols[] = 'email';
            if (! empty($cols)) $table->dropColumn($cols);
        });
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'user_status')) {
                $table->dropColumn('user_status');
            }
        });
    }
};