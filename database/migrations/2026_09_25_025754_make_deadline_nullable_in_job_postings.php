<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
public function up(): void
{
    Schema::table('job_postings', function (Blueprint $table) {
        $table->date('deadline')->nullable()->change();
    });
}

public function down(): void
{
    Schema::table('job_postings', function (Blueprint $table) {
        $table->date('deadline')->nullable(false)->change();
    });
}
};
