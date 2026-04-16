<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizer', function (Blueprint $table) {
            $table->string('batch', 20)->nullable()->after('department');
            $table->string('course_code')->nullable()->after('batch');

            $table->index('batch',       'idx_org_batch');
            $table->index('course_code', 'idx_org_course');
        });
    }

    public function down(): void
    {
        Schema::table('organizer', function (Blueprint $table) {
            $table->dropIndex('idx_org_batch');
            $table->dropIndex('idx_org_course');
            $table->dropColumn(['batch', 'course_code']);
        });
    }
};