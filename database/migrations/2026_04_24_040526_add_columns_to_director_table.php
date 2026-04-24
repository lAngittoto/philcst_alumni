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
    Schema::table('director', function (Blueprint $table) {
        $table->string('first_name')->nullable()->after('user_id');
        $table->string('last_name')->nullable()->after('first_name');
        $table->string('profile_photo')->nullable()->after('last_name');
    });
}

public function down(): void
{
    Schema::table('director', function (Blueprint $table) {
        $table->dropColumn(['first_name', 'last_name', 'profile_photo']);
    });
}
};
