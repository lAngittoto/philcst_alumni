<?php

// ============================================================
// FILE: app/Http/Controllers/JobController.php
// ============================================================

namespace App\Http\Controllers;

use App\Models\JobPosting;
use App\Models\JobOption;
use Illuminate\Http\Request;

class JobController extends Controller
{
    // ── Job Postings ─────────────────────────────────────────

    public function toggleStatus(int $id): string
    {
        $job       = JobPosting::findOrFail($id);
        $newStatus = $job->status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE';
        $job->update(['status' => $newStatus]);
        return $newStatus;
    }

    public function deleteJob(int $id): void
    {
        JobPosting::findOrFail($id)->delete();
    }

    public function getJob(int $id): JobPosting
    {
        return JobPosting::with('organizer')->findOrFail($id);
    }

    // ── Job Options ───────────────────────────────────────────

    public function saveOption(array $data, ?int $id = null): JobOption
    {
        if ($id) {
            $option = JobOption::findOrFail($id);
            $option->update([
                'type'  => $data['type'],
                'label' => trim($data['label']),
            ]);
            return $option;
        }

        return JobOption::create([
            'type'  => $data['type'],
            'label' => trim($data['label']),
        ]);
    }

    public function deleteOption(int $id): void
    {
        JobOption::findOrFail($id)->delete();
    }
}