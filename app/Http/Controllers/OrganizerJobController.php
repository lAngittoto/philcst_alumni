<?php

namespace App\Http\Controllers;

use App\Models\OrganizerJob;
use App\Models\JobOption;
use App\Models\Course;
use App\Models\Organizer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class OrganizerJobController extends Controller
{
    // ── Auth helper ────────────────────────────────────────────

    public function getOrganizer(): ?Organizer
    {
        return Auth::user()?->organizer;
    }

    // ── Profile photo URL ──────────────────────────────────────

    public function getPhotoUrl(?string $path): string
    {
        $default = asset('storage/alumni-photos/default.png');

        if (!$path || str_contains($path, 'default')) {
            return $default;
        }

        $disk = Storage::disk('public');
        if ($disk->exists($path)) {
            return asset('storage/' . $path);
        }

        return $default;
    }

    // ── Queries ────────────────────────────────────────────────

    /**
     * Paginated list of the organizer's own jobs.
     * ✅ FIX: added $filterSort parameter (was missing, caused "too few arguments" error)
     */
    public function getJobs(
        string $search       = '',
        string $filterStatus = '',
        string $filterType   = '',
        int    $perPage      = 15,
        string $filterSort   = 'recent'   // ← was missing
    ) {
        $org = $this->getOrganizer();
        if (!$org) return OrganizerJob::whereRaw('0=1')->paginate($perPage);

        $q = OrganizerJob::forOrganizer($org->id);

        if ($search) {
            $q->where(fn($sub) =>
                $sub->where('job_title',     'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%")
            );
        }

        if ($filterStatus !== '') $q->where('status', $filterStatus);
        if ($filterType   !== '') $q->where('employment_type', $filterType);

        $direction = $filterSort === 'oldest' ? 'asc' : 'desc';
        $q->orderBy('created_at', $direction);

        return $q->paginate($perPage);
    }

    /**
     * Single job — ownership enforced.
     */
    public function getJob(int $id): OrganizerJob
    {
        $org = $this->getOrganizer();
        return OrganizerJob::where('id', $id)
            ->where('organizer_id', $org?->id)
            ->firstOrFail();
    }

    /**
     * All dropdown options grouped by type.
     */
    public function getOptions(): \Illuminate\Support\Collection
    {
        return JobOption::orderBy('type')->orderBy('label')
            ->get()
            ->groupBy('type');
    }

    /**
     * Colleges with their dept course codes — for target college picker.
     */
    public function getCollegesWithDepts(): array
    {
        $grouped = Course::whereNotNull('college')
            ->where('college', '!=', '')
            ->orderBy('college')
            ->orderBy('code')
            ->get()
            ->groupBy('college');

        $result = [];
        foreach ($grouped as $collegeName => $courses) {
            $result[] = [
                'name'  => $collegeName,
                'codes' => $courses->pluck('code')->toArray(),
            ];
        }
        return $result;
    }

    /**
     * Dept codes for a single college name.
     */
    public function getDeptsForCollege(string $collegeName): array
    {
        return Course::where('college', $collegeName)
            ->orderBy('code')
            ->pluck('code')
            ->toArray();
    }

    // ── Mutations ──────────────────────────────────────────────

    public function createJob(array $data): OrganizerJob
    {
        $org = $this->getOrganizer();
        if (!$org) throw new \RuntimeException('No organizer profile for current user.');

        return OrganizerJob::create(array_merge($data, [
            'organizer_id' => $org->id,
            'status'       => 'ACTIVE',
        ]));
    }

    public function updateJob(int $id, array $data): OrganizerJob
    {
        $job = $this->getJob($id);
        $job->update($data);   // updated_by/updated_by_role now save correctly via $fillable
        return $job->fresh();
    }

    public function deleteJob(int $id): void
    {
        $this->getJob($id)->delete();
    }

    /**
     * Toggle ACTIVE ↔ INACTIVE — returns new status string.
     */
    public function toggleStatus(int $id): string
    {
        $job       = $this->getJob($id);
        $newStatus = $job->status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE';
        $job->update(['status' => $newStatus]);
        return $newStatus;
    }

    // ── Stats (dashboard widgets) ──────────────────────────────

    public function getStats(): array
    {
        $org = $this->getOrganizer();
        if (!$org) return ['total' => 0, 'active' => 0, 'inactive' => 0, 'expired' => 0];

        $base = OrganizerJob::forOrganizer($org->id);

        return [
            'total'    => (clone $base)->count(),
            'active'   => (clone $base)->where('status', 'ACTIVE')->count(),
            'inactive' => (clone $base)->where('status', 'INACTIVE')->count(),
            'expired'  => (clone $base)->where('deadline', '<', now()->toDateString())->count(),
        ];
    }
}