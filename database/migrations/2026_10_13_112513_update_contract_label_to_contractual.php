<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

return new class extends Migration
{
    /**
     * Renames the existing "Contract" employment_type option to "Contractual".
     * This does NOT touch the job_options table structure — it only updates
     * the row's label value, so any job postings already using this option
     * (stored by label text, e.g. employment_type = 'Contract') are also
     * updated to keep them in sync.
     */
    public function up(): void
    {
        // Update the option label itself.
        DB::table('job_options')
            ->where('type', 'employment_type')
            ->where('label', 'Contract')
            ->update(['label' => 'Contractual', 'updated_at' => now()]);

        // Keep existing job postings consistent — job_postings.employment_type
        // stores the label text directly (not a foreign key), so any job
        // already posted as "Contract" needs to be updated too, otherwise
        // it would silently become an orphaned/unmatched value.
        DB::table('job_postings')
            ->where('employment_type', 'Contract')
            ->update(['employment_type' => 'Contractual']);

        // The blade reads these options through Cache::remember('job_options_grouped_v3', 600, ...)
        // — clear it so the new label shows up immediately instead of waiting
        // up to 10 minutes for the cache to expire on its own.
        Cache::forget('job_options_grouped_v3');
    }

    /**
     * Reverses the rename, restoring "Contract" — run automatically if this
     * migration is ever rolled back (php artisan migrate:rollback).
     */
    public function down(): void
    {
        DB::table('job_options')
            ->where('type', 'employment_type')
            ->where('label', 'Contractual')
            ->update(['label' => 'Contract', 'updated_at' => now()]);

        DB::table('job_postings')
            ->where('employment_type', 'Contractual')
            ->update(['employment_type' => 'Contract']);

        Cache::forget('job_options_grouped_v3');
    }
};