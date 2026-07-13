{{-- resources/views/livewire/registrar/alumni-records.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use App\Models\Alumni;
use App\Models\Course;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

new class extends Component {
    use WithPagination, WithFileUploads;

    protected function queryString(): array { return []; }

    public string $alumniSearch      = '';
    public string $alumniBatch       = '';
    public string $alumniCourse      = '';
    public string $alumniProfileFilter = 'all';

    public ?int   $viewingProfileId  = null;
    public        $viewingProfile    = null;
    public        $viewingEmployment = null;

    public $newAlumniPhoto = null;

    public string $activeModal = '';

    protected string $paginationTheme = 'tailwind';

    public function mount(): void
    {
        $filter = request()->query('profile_filter', 'all');
        if (in_array($filter, ['all', 'complete', 'incomplete'])) {
            $this->alumniProfileFilter = $filter;
        }

        $batch = request()->query('batch', '');
        if ($batch !== '') {
            $this->alumniBatch = (string) intval($batch);
        }

        if (session()->has('success'))
            $this->dispatch('flash-message', type: 'success', message: session()->pull('success'));
        if (session()->has('error'))
            $this->dispatch('flash-message', type: 'error', message: session()->pull('error'));
    }

    public function updatingAlumniSearch()        { $this->resetPage('alumniPage'); }
    public function updatingAlumniBatch()         { $this->resetPage('alumniPage'); }
    public function updatingAlumniCourse()        { $this->resetPage('alumniPage'); }
    public function updatingAlumniProfileFilter() { $this->resetPage('alumniPage'); }

    #[Computed]
    public function alumniRecords()
    {
        $q = Alumni::query()->select([
            'id', 'user_id',
            'first_name', 'middle_initial', 'last_name', 'suffix',
            'student_id', 'course_code', 'course_name', 'batch',
            'email', 'profile_photo', 'status', 'profile_completed',
            'password_changed_at', 'created_at',
        ]);

        // NOTE: text search is intentionally limited to Name, Student ID,
        // and Email. Batch and Program Code already have their own
        // dedicated dropdown filters above the table, so they are excluded
        // here on purpose — typing "2006" or "BSIT" in the search box
        // should NOT match by batch/course; use the dropdowns for that.
        if ($this->alumniSearch) {
            $term = '%' . $this->alumniSearch . '%';
            $q->where(fn($s) => $s
                ->where('first_name',   'like', $term)
                ->orWhere('last_name',  'like', $term)
                ->orWhere('student_id', 'like', $term)
                ->orWhere('email',      'like', $term));
        }

        if ($this->alumniBatch)  $q->where('batch', $this->alumniBatch);
        if ($this->alumniCourse) $q->where('course_code', $this->alumniCourse);

        if ($this->alumniProfileFilter === 'complete')
            $q->where('profile_completed', 1);
        elseif ($this->alumniProfileFilter === 'incomplete')
            $q->where('profile_completed', 0);

        $hasActiveFilter = $this->alumniSearch !== ''
            || $this->alumniBatch !== ''
            || $this->alumniCourse !== ''
            || $this->alumniProfileFilter !== 'all';

        // FIX (Paolo-on-screen-but-Jose-in-export bug): without a final
        // deterministic tie-breaker, rows sharing the same course_code /
        // last_name / first_name — or, in the "no filter" branch, the
        // exact same created_at (very common with bulk-imported alumni)
        // — have NO guaranteed order. MySQL can return ties in a
        // different order on every separate query, so the on-screen
        // table and the export could show completely different first
        // rows even with identical filters applied. Adding `id` as the
        // last sort key makes the order 100% deterministic — and the
        // export controller (RegistrarAlumniExportController) uses this
        // EXACT same ordering so what's on screen is always what gets
        // exported, in the same order, every time.
        if ($hasActiveFilter) {
            $q->orderBy('course_code')
              ->orderBy('last_name')
              ->orderBy('first_name')
              ->orderBy('id');
        } else {
            $q->orderByDesc('created_at')
              ->orderByDesc('id');
        }

        return $q->paginate(100, ['*'], 'alumniPage');
    }

    #[Computed] public function courses() { return Course::orderBy('code')->get(); }
    #[Computed] public function batches() { return Alumni::distinct()->orderByDesc('batch')->pluck('batch'); }

    public function getPhotoUrl(?string $path): string
    {
        if (!$path || str_contains($path, 'default.png'))
            return asset('storage/alumni-photos/default.png');
        if (str_starts_with($path, 'alumni-photos/') || str_starts_with($path, 'organizers/'))
            return asset('storage/' . $path);
        return asset('storage/alumni-photos/default.png');
    }

    public function formatDisplayName(string $f, string $m, string $l, string $s): string
    {
        $parts = [trim($f)];
        if (trim($m) !== '') $parts[] = ucfirst(strtolower(substr(trim($m), 0, 1))) . '.';
        $parts[] = trim($l);
        if (trim($s) !== '') $parts[] = trim($s);
        return implode(' ', array_filter($parts));
    }

    public function highlight(string $text, string $search): string
    {
        if (!$search || !$text) return e($text);
        $pattern = '/(' . preg_quote($search, '/') . ')/iu';
        $parts   = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out     = '';
        foreach ($parts as $i => $part) {
            $out .= ($i % 2 === 1)
                ? '<mark class="ar-hl">' . e($part) . '</mark>'
                : e($part);
        }
        return $out;
    }

    /**
     * Derive profile completeness from required fields regardless of DB flag.
     * Required: first_name, last_name, middle_initial, student_id, course_code, batch, email
     */
    public function isProfileComplete(array $profile): bool
    {
        if (!empty($profile['profile_completed'])) return true;

        $required = ['first_name', 'last_name', 'middle_initial', 'student_id', 'course_code', 'batch', 'email'];
        foreach ($required as $field) {
            if (empty(trim($profile[$field] ?? ''))) return false;
        }
        return true;
    }

    #[Computed]
    public function activeFilterSummary(): string
    {
        $parts = [];

        if ($this->alumniProfileFilter === 'complete') $parts[] = 'Complete profiles only';
        elseif ($this->alumniProfileFilter === 'incomplete') $parts[] = 'Pending profiles only';

        if ($this->alumniBatch !== '') $parts[] = 'Batch ' . $this->alumniBatch;
        if ($this->alumniCourse !== '') $parts[] = 'Program Code ' . $this->alumniCourse;
        if ($this->alumniSearch !== '') $parts[] = 'Search: "' . $this->alumniSearch . '"';

        return count($parts) ? implode(' · ', $parts) : 'All alumni records (no filters applied)';
    }

    public function resetAlumniFilters(): void
    {
        $this->alumniSearch        = '';
        $this->alumniBatch         = '';
        $this->alumniCourse        = '';
        $this->alumniProfileFilter = 'all';
        $this->resetPage('alumniPage');
    }

    public function viewProfile(int $id): void
    {
        try {
            $this->viewingProfile = Alumni::select([
                'id', 'user_id',
                'first_name', 'middle_initial', 'last_name', 'suffix',
                'student_id', 'course_code', 'course_name', 'batch', 'year_level',
                'email', 'profile_photo', 'status', 'profile_completed',
                'password_changed_at',
                'gender', 'date_of_birth',
                'contact_number',
                'father_last_name', 'father_given_name', 'father_middle_name',
                'mother_last_name',  'mother_given_name',  'mother_middle_name',
                'dswd_household_no', 'disability',
                'address_street', 'address_barangay',
                'address_municipality', 'address_province',
                'created_at', 'updated_at',
            ])->findOrFail($id)->toArray();

            $emp = DB::table('employment_trackings')
                ->where('alumni_id', $id)
                ->whereNull('deleted_at')
                ->latest('created_at')
                ->first();

            $this->viewingEmployment = $emp ? (array) $emp : null;
            $this->viewingProfileId  = $id;
            $this->newAlumniPhoto    = null;
            $this->activeModal       = 'viewProfile';
        } catch (\Exception $e) {
            $this->dispatch('flash-message', type: 'error', message: 'Failed to load profile.');
        }
    }

    public function uploadAlumniPhoto(): void
    {
        if (!$this->viewingProfileId || !$this->newAlumniPhoto) return;

        try {
            $alumni = Alumni::findOrFail($this->viewingProfileId);

            if ($alumni->profile_photo
                && !str_contains($alumni->profile_photo, 'default.png')
                && Storage::disk('public')->exists($alumni->profile_photo)) {
                Storage::disk('public')->delete($alumni->profile_photo);
            }

            $path = $this->newAlumniPhoto->store('alumni-photos', 'public');
            $alumni->update(['profile_photo' => $path]);

            $this->viewingProfile['profile_photo'] = $path;
            $this->newAlumniPhoto = null;

            $this->dispatch('flash-message', type: 'success', message: 'Profile photo updated successfully.');
            $this->dispatch('photo-saved');
        } catch (\Exception $e) {
            $this->dispatch('flash-message', type: 'error', message: 'Failed to upload photo.');
        }
    }

    public function resetAlumniPhoto(): void
    {
        if (!$this->viewingProfileId) return;

        try {
            $alumni = Alumni::findOrFail($this->viewingProfileId);

            if ($alumni->profile_photo
                && !str_contains($alumni->profile_photo, 'default.png')
                && Storage::disk('public')->exists($alumni->profile_photo)) {
                Storage::disk('public')->delete($alumni->profile_photo);
            }

            $alumni->update(['profile_photo' => null]);
            $this->viewingProfile['profile_photo'] = null;

            $this->dispatch('flash-message', type: 'success', message: 'Profile photo reset to default.');
            $this->dispatch('photo-saved');
        } catch (\Exception $e) {
            $this->dispatch('flash-message', type: 'error', message: 'Failed to reset photo.');
        }
    }

    public function closeModal(): void
    {
        $this->activeModal       = '';
        $this->viewingProfileId  = null;
        $this->viewingProfile    = null;
        $this->viewingEmployment = null;
        $this->newAlumniPhoto    = null;
    }
};
?>

<div>

<style>
    /* ── Search highlight ────────────────────────────────────────── */
    mark.ar-hl {
        background: #BFDBFE;
        color: inherit;
        border-radius: 2px;
        padding: 0 1px;
        font-weight: 700;
    }

    /* ── Hover tooltip (desktop only — see JS + media query below) ── */
    .ar-hover-tip {
        position: fixed;
        background: #1a1a1a;
        color: #fff;
        font-size: 10px;
        font-weight: 600;
        letter-spacing: .05em;
        padding: 5px 11px;
        border-radius: 7px;
        white-space: nowrap;
        pointer-events: none;
        opacity: 0;
        transition: opacity .15s ease;
        z-index: 99999;
        box-shadow: 0 4px 14px rgba(0,0,0,.30);
        transform: translate(12px, -110%);
    }
    .ar-hover-tip.visible { opacity: 1; }
    .ar-hover-tip::after {
        content: '';
        position: absolute;
        top: 100%;
        left: 14px;
        border: 5px solid transparent;
        border-top-color: #1a1a1a;
    }
    @media (max-width: 768px), (hover: none) {
        .ar-hover-tip { display: none !important; }
    }

    /* ── Table rows ──────────────────────────────────────────────── */
    .ar-row {
        cursor: pointer;
        user-select: none;
        -webkit-user-select: none;
        transition: background .12s ease;
    }
    .ar-row:hover { background: #F0ECF5 !important; }

    /* ── Mobile card rows (no horizontal scroll) ───────────────── */
    .ar-mrow {
        cursor: pointer;
        user-select: none;
        -webkit-user-select: none;
        background: #fff;
        border-bottom: 1px solid #F0ECF5;
        padding: 12px 14px;
        display: flex;
        align-items: center;
        gap: 10px;
        transition: background .12s ease;
    }
    .ar-mrow:active { background: #F0ECF5; }

    /* ── Pagination ──────────────────────────────────────────────── */
    .ar-pg-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 32px;
        height: 32px;
        padding: 0 10px;
        border-radius: 8px;
        font-size: .75rem;
        font-weight: 600;
        transition: all .15s;
        border: 1.5px solid transparent;
    }
    .ar-pg-active { background: #fff; color: #7A3F91; border-color: #fff; }
    .ar-pg-nav { background: rgba(255,255,255,.15); color: #fff; border-color: rgba(255,255,255,.25); }
    .ar-pg-nav:hover:not(:disabled) { background: rgba(255,255,255,.28); border-color: rgba(255,255,255,.5); }
    .ar-pg-nav:disabled { opacity: .35; cursor: not-allowed; }

    /* ── Close button ────────────────────────────────────────────── */
    .ar-close-btn {
        position: relative;
        display: flex; align-items: center; justify-content: center;
        width: 36px; height: 36px;
        border-radius: 10px;
        background: rgba(255,255,255,.12);
        border: 1px solid rgba(255,255,255,.2);
        color: #fff; cursor: pointer;
        transition: background .15s;
        overflow: visible;
    }
    .ar-close-btn:hover { background: rgba(255,255,255,.22); }
    .ar-close-tip {
        position: absolute;
        top: calc(100% + 8px); right: 0;
        background: rgba(27,6,46,.88);
        color: #fff; font-size: 10px; font-weight: 600;
        letter-spacing: .08em; text-transform: uppercase;
        padding: 4px 10px; border-radius: 7px;
        white-space: nowrap; pointer-events: none;
        opacity: 0; transition: opacity .15s ease;
        z-index: 200; box-shadow: 0 4px 12px rgba(0,0,0,.28);
    }
    .ar-close-tip::before {
        content: '';
        position: absolute; bottom: 100%; right: 10px;
        border: 5px solid transparent;
        border-bottom-color: rgba(27,6,46,.88);
    }
    .ar-close-btn:hover .ar-close-tip { opacity: 1; }
    @media (max-width: 768px), (hover: none) {
        .ar-close-tip { display: none !important; }
    }

    /* ── Generate Reports button — neutral color, always top-right ──── */
    .ar-report-btn {
        position: relative;
        display: flex; align-items: center; justify-content: center;
        width: 40px; height: 40px;
        border-radius: 10px;
        background: linear-gradient(135deg,#475569,#64748b);
        border: 1.5px solid transparent;
        color: #fff;
        cursor: pointer;
        transition: all .15s;
        font-size: 15px;
        flex-shrink: 0;
        box-shadow: 0 2px 8px rgba(71,85,105,.35);
    }
    .ar-report-btn:hover,
    .ar-report-btn-active { background: linear-gradient(135deg,#334155,#475569); box-shadow: 0 3px 10px rgba(71,85,105,.5); }
    .ar-report-btn:disabled { opacity: .7; cursor: wait; }
    .ar-report-tip {
        position: absolute;
        top: calc(100% + 8px); right: 0;
        background: #1a1a1a; color: #fff;
        font-size: 10px; font-weight: 600; letter-spacing: .05em;
        padding: 5px 11px; border-radius: 7px; white-space: nowrap;
        pointer-events: none; opacity: 0; transition: opacity .15s ease;
        z-index: 9999; box-shadow: 0 4px 14px rgba(0,0,0,.30);
    }
    .ar-report-tip::before {
        content: '';
        position: absolute; bottom: 100%; right: 12px;
        border: 5px solid transparent; border-bottom-color: #1a1a1a;
    }
    .ar-report-btn:hover .ar-report-tip { opacity: 1; }
    @media (max-width: 768px), (hover: none) {
        .ar-report-tip { display: none !important; }
    }
    .ar-report-menu {
        position: absolute; top: calc(100% + 8px); right: 0;
        width: min(260px, calc(100vw - 24px));
        background: #fff;
        border: 1.5px solid #E8E0F0; border-radius: 12px;
        box-shadow: 0 10px 30px rgba(122,63,145,.18);
        z-index: 500; padding: 6px;
    }
    .ar-report-menu-message {
        padding: 10px 10px 11px;
        margin-bottom: 4px;
        border-bottom: 1px solid #F0ECF5;
    }
    .ar-report-menu-message .lbl {
        font-size: .6rem; font-weight: 700; text-transform: uppercase;
        letter-spacing: .07em; color: #7A3F91; display: block; margin-bottom: 3px;
    }
    .ar-report-menu-message .txt {
        font-size: .75rem; font-weight: 600; color: #111111; line-height: 1.35;
    }
    .ar-report-menu-message .cnt {
        font-size: .68rem; font-weight: 500; color: #333333; margin-top: 3px; display: block;
    }

    .ar-report-menu-item {
        display: flex; align-items: center; gap: 9px; width: 100%;
        padding: 9px 10px; border-radius: 8px;
        margin-bottom: 4px;
        font-size: .82rem; font-weight: 600; color: #333333;
        border: 1.5px solid transparent; cursor: pointer; text-align: left;
        transition: background .12s, border-color .12s, opacity .12s;
    }
    .ar-report-menu-item:last-child { margin-bottom: 0; }
    .ar-report-menu-item .ar-item-icon {
        width: 22px; height: 22px; border-radius: 6px;
        display: flex; align-items: center; justify-content: center;
        flex-shrink: 0; font-size: 11px;
    }
    .ar-report-menu-item .ar-item-label { flex: 1; }

    .ar-report-menu-item.item-pdf   { background: #FEF2F2; border-color: #FEE2E2; }
    .ar-report-menu-item.item-pdf:hover   { background: #FEE2E2; border-color: #FECACA; }
    .ar-report-menu-item.item-pdf .ar-item-icon { background: #DC2626; color: #fff; }

    .ar-report-menu-item.item-excel { background: #ECFDF5; border-color: #D1FAE5; }
    .ar-report-menu-item.item-excel:hover { background: #D1FAE5; border-color: #A7F3D0; }
    .ar-report-menu-item.item-excel .ar-item-icon { background: #059669; color: #fff; }

    .ar-report-menu-item.item-print { background: #F5F5F5; border-color: #EDEDED; }
    .ar-report-menu-item.item-print:hover { background: #ECECEC; border-color: #E0E0E0; }
    .ar-report-menu-item.item-print .ar-item-icon { background: #555555; color: #fff; }

    .ar-report-menu-item:disabled { opacity: .55; cursor: wait; }

    /* ── Header row: always single row, button pinned top-right ──── */
    .ar-header-row {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 10px;
    }
    .ar-header-left {
        display: flex;
        align-items: center;
        gap: 10px;
        min-width: 0;
    }

    /* ── Filter bar ──────────────────────────────────────────────── */
    .ar-filter-label { pointer-events: none; }
    .ar-dropdown { position: relative; }
    .ar-dropdown-menu {
        position: absolute; top: calc(100% + 4px); left: 0;
        min-width: 100%; max-height: 220px; overflow-y: auto;
        background: #fff; border: 1.5px solid #E8E0F0;
        border-radius: 10px; box-shadow: 0 8px 24px rgba(122,63,145,.13);
        z-index: 500; padding: 4px;
        scrollbar-width: thin; scrollbar-color: #d4b8e8 transparent;
    }
    .ar-dropdown-menu::-webkit-scrollbar { width: 5px; }
    .ar-dropdown-menu::-webkit-scrollbar-thumb { background: #d4b8e8; border-radius: 99px; }
    .ar-dropdown-item {
        display: block; width: 100%; padding: 7px 10px; border-radius: 7px;
        font-size: .8rem; font-weight: 600; text-align: left; color: #333;
        transition: background .1s; cursor: pointer; white-space: nowrap;
        border: none; background: transparent;
    }
    .ar-dropdown-item:hover { background: #F5F0FA; color: #7A3F91; }
    .ar-dropdown-item.active { background: #F0E6F8; color: #7A3F91; }
    .ar-dropdown-trigger {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 8px 11px; border: 1.5px solid #E8E0F0; border-radius: 8px;
        font-size: .8rem; font-weight: 600; background: #fff; color: #333;
        cursor: pointer; transition: border-color .15s, background .15s, color .15s;
        white-space: nowrap; user-select: none;
    }
    .ar-dropdown-trigger:hover { border-color: #c49ed8; }
    .ar-dropdown-trigger.has-value { border-color: #7A3F91; background: #F9F7FC; color: #7A3F91; }
    .ar-dropdown-trigger .ar-chevron { transition: transform .18s; font-size: .65rem; opacity: .6; }
    .ar-dropdown-trigger.open .ar-chevron { transform: rotate(180deg); }

    /* ── Profile status filter pills ────────────────────────────── */
    .ar-status-pill {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 7px 13px; border-radius: 8px; border: 1.5px solid #E8E0F0;
        font-size: .78rem; font-weight: 600; cursor: pointer;
        transition: all .15s; background: #fff; color: #333333;
        white-space: nowrap;
    }
    .ar-status-pill:hover { border-color: #c49ed8; color: #7A3F91; }
    .ar-status-pill.active-all      { background: linear-gradient(135deg,#7A3F91,#9b59b6); color:#fff; border-color:transparent; }
    .ar-status-pill.active-complete { background: #ecfdf5; color:#059669; border-color:#6ee7b7; }
    .ar-status-pill.active-incomplete { background: #fffbeb; color:#d97706; border-color:#fcd34d; }

    /* ── Profile field labels ─────────── */
    .ar-field-label {
        font-size: .7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .05em;
        color: #333333;
        margin-bottom: 4px;
    }
    .ar-field-value {
        font-size: .95rem;
        font-weight: 600;
        color: #333333;
        word-break: break-word;
    }

    /* ── Profile info cards ── WHITE background ───────────────── */
    .ar-card {
        background: #ffffff;
        border: 1.5px solid #E4E4E4;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0,0,0,.06);
    }
    .ar-card-header {
        padding: 8px 12px;
        border-bottom: 1.5px solid #EEEEEE;
        background: #F7F7F7;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 7px;
    }
    .ar-card-header p {
        font-size: .72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .06em;
        color: #333333;
        margin: 0;
    }

    /* ── Cells — WHITE background ─────────────────────────────── */
    .ar-cell {
        padding: 8px 11px;
        border: 1.5px solid #EEEEEE;
        background: #ffffff;
        border-radius: 8px;
    }

    /* ── Info chips ── */
    .ar-info-chip {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 4px 10px;
        border-radius: 999px;
        font-size: .72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .03em;
        white-space: nowrap;
    }

    /* ── Mobile responsiveness ─────────────────────────────────── */
    .ar-profile-body {
        padding: 10px 14px;
        background: #F2F2F2;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
    }
    .ar-profile-grid {
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        grid-template-rows: auto auto auto auto auto auto;
        gap: 8px;
        height: 100%;
        box-sizing: border-box;
    }
    @media (max-width: 768px) {
        .ar-profile-body {
            padding: 10px 12px 20px;
        }
        .ar-profile-grid {
            grid-template-columns: 1fr;
            grid-template-rows: none;
            height: auto;
            gap: 10px;
        }
        .ar-profile-grid > div { grid-column: 1 / -1 !important; }
        .ar-avatar-strip { flex-direction: column; }
        .ar-photo-col {
            border-right: none;
            border-bottom: 1.5px solid #EEEEEE;
            width: 100%;
            min-width: 0;
        }
        .ar-info-col { padding: 12px 14px; }
    }

    /* ── Panel animation ─────────────────────────────────────────── */
    @keyframes arPanelIn {
        from { opacity:0; transform:translateY(10px) scale(.99); }
        to   { opacity:1; transform:none; }
    }
    .ar-panel { animation: arPanelIn .18s cubic-bezier(.4,0,.2,1) both; }

    /* ── Photo action buttons ────────────────────────────────── */
    .ar-photo-action-btn {
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 30px;
        height: 30px;
        border-radius: 7px;
        border: 1.5px solid #E0E0E0;
        background: #fff;
        cursor: pointer;
        transition: all .15s;
        font-size: 12px;
        flex-shrink: 0;
    }
    .ar-photo-save-btn { background: #7A3F91; border-color: #7A3F91; color: #fff; }
    .ar-photo-save-btn:hover { background: #6a3280; border-color: #6a3280; }
    .ar-photo-save-btn:disabled { opacity: .55; cursor: not-allowed; }
    .ar-photo-cancel-btn { color: #555555; }
    .ar-photo-cancel-btn:hover { background: #FEF2F2; border-color: #FECACA; color: #DC2626; }
    .ar-photo-default-btn { color: #555555; }
    .ar-photo-default-btn:hover { background: #F5F5F5; border-color: #BBBBBB; color: #333333; }

    /* ── Upload shimmer ─────────────────────────────────────────── */
    @keyframes arShimmer {
        0%   { background-position: -200% 0; }
        100% { background-position:  200% 0; }
    }
    .ar-uploading-overlay {
        position: absolute;
        inset: 0;
        border-radius: 12px;
        background: linear-gradient(90deg,rgba(122,63,145,.15) 25%,rgba(122,63,145,.35) 50%,rgba(122,63,145,.15) 75%);
        background-size: 200% 100%;
        animation: arShimmer 1.2s infinite linear;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    /* ── Avatar strip — WHITE background ─────────────────────── */
    .ar-avatar-strip {
        display: flex;
        align-items: stretch;
        gap: 0;
        background: #ffffff;
        border: 1.5px solid #E4E4E4;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0,0,0,.06);
    }
    .ar-photo-col {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        justify-content: center;
        flex-shrink: 0;
        padding: 12px 14px;
        border-right: 1.5px solid #EEEEEE;
        background: #F7F7F7;
        gap: 5px;
        min-width: 160px;
    }
    .ar-info-col {
        flex: 1;
        min-width: 0;
        display: flex;
        flex-direction: column;
        justify-content: center;
        padding: 12px 16px;
        gap: 6px;
        background: #ffffff;
    }

    /* ── Employment 2-col grid ────────────────────────────────── */
    .ar-emp-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 6px;
        padding: 8px;
    }
    @media (max-width: 600px) {
        .ar-emp-grid { grid-template-columns: 1fr; }
    }

    /* ── Mobile: darker, readable text on card rows ─────────────── */
    @media (max-width: 768px) {
        .ar-mrow .font-mono.text-\[\#666666\],
        .ar-mrow p.text-\[\#666666\] {
            color: #333333 !important;
        }
    }

    /* ── Pagination footer: keep it above the very bottom edge on mobile ── */
    .ar-pg-footer {
        padding-bottom: calc(0.625rem + env(safe-area-inset-bottom, 0px));
    }
    @media (max-width: 640px) {
        .ar-pg-footer {
            margin-bottom: 0;
            border-radius: 0;
            padding-bottom: calc(14px + env(safe-area-inset-bottom, 0px));
        }
    }

    /* ── Mobile: true full-screen — reduce reserved offset so the
       pagination bar sits flush at the bottom, no leftover gray gap ── */
    @media (max-width: 640px) {
        .ar-page-wrap {
            height: calc(100dvh - 118px) !important;
            max-height: calc(100dvh - 118px) !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
            padding-top: 10px !important;
            padding-bottom: 0 !important;
        }
        .ar-table-card {
            border-radius: 0 !important;
            border-left: none !important;
            border-right: none !important;
            border-bottom: none !important;
            box-shadow: none !important;
        }
    }
</style>

<div id="ar-hover-tip" class="ar-hover-tip">
    <i class="fas fa-eye mr-1.5" style="font-size:.65rem;"></i>View Details
</div>

{{-- ══ FLASH TOAST ══════════════════════════════════════════════════ --}}
<div x-data="{
        show:false, type:'success', msg:'', timer:null,
        display(t,m){ this.type=t; this.msg=m; this.show=true; clearTimeout(this.timer); this.timer=setTimeout(()=>this.show=false,5000); }
     }"
     @flash-message.window="display($event.detail.type,$event.detail.message)"
     x-show="show"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0 translate-y-2 scale-95"
     x-transition:enter-end="opacity-100 translate-y-0 scale-100"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0 scale-95"
     class="fixed top-4 right-4 z-[200] flex items-start gap-3 px-4 py-3 rounded-xl shadow-2xl max-w-sm border-l-4 bg-white"
     :class="{ 'border-emerald-500':type==='success','border-red-500':type==='error','border-blue-500':type==='info' }"
     style="display:none">
    <i class="fas mt-0.5 text-base shrink-0"
       :class="{ 'fa-circle-check text-emerald-500':type==='success','fa-circle-exclamation text-red-500':type==='error','fa-circle-info text-blue-500':type==='info' }"></i>
    <div class="flex-1 min-w-0">
        <p class="font-semibold text-sm text-[#333333]" x-text="type==='success'?'Success':type==='info'?'Info':'Error'"></p>
        <p class="text-sm mt-0.5 text-[#666666] leading-snug break-words font-normal" x-text="msg"></p>
    </div>
    <button @click="show=false" class="text-[#999999] hover:text-[#666666] transition shrink-0 mt-0.5">
        <i class="fas fa-xmark text-sm"></i>
    </button>
</div>

{{-- ══ PAGE ══════════════════════════════════════════════════════════ --}}
<div class="ar-page-wrap flex flex-col px-3 sm:px-5 lg:px-6 pt-4 pb-3 max-w-screen-2xl mx-auto" style="height:calc(100vh - 180px);max-height:calc(100vh - 180px);overflow:hidden;">

    {{-- Header --}}
    <div class="ar-header-row mb-3 shrink-0">
        <div class="ar-header-left">
            <div class="w-10 h-10 sm:w-11 sm:h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
                 style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                <i class="fas fa-graduation-cap text-white text-base"></i>
            </div>
            <div class="min-w-0">
                <h1 class="text-2xl sm:text-2xl font-semibold text-[#333333] leading-tight">Alumni Records</h1>
                <p class="text-[#666666] text-xs sm:text-sm font-normal">View and manage alumni information and records.</p>
            </div>
        </div>

        <div class="relative shrink-0" wire:ignore
             x-data
             x-init="window.__arEnsureReportStore && window.__arEnsureReportStore()"
             @click.outside="$store.report.open=false" wire:key="ar-report-dropdown">
            <button type="button" @click.stop="$store.report.toggle()" class="ar-report-btn"
                    :disabled="$store.report.exporting"
                    :class="{ 'ar-report-btn-active': $store.report.open }">
                <i class="fas fa-chart-column"></i>
                <span class="ar-report-tip">Generate Reports</span>
            </button>

            <div x-show="$store.report.open"
                 x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95 -translate-y-1" x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                 x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                 class="ar-report-menu" style="display:none;">

                <div class="ar-report-menu-message">
                    <span class="lbl"><i class="fas fa-circle-info mr-1"></i>Report will include</span>
                    <span class="txt">{{ $this->activeFilterSummary }}</span>
                    <span class="cnt">{{ number_format($this->alumniRecords->total()) }} matching record(s)</span>
                    
                </div>

                <button type="button" @click="$store.report.doExport('pdf', $wire)"
                        :disabled="$store.report.exporting" class="ar-report-menu-item item-pdf">
                    <span class="ar-item-icon"><i class="fas fa-file-pdf"></i></span>
                    <span class="ar-item-label">Export as PDF</span>
                </button>

                <button type="button" @click="$store.report.doExport('excel', $wire)"
                        :disabled="$store.report.exporting" class="ar-report-menu-item item-excel">
                    <span class="ar-item-icon"><i class="fas fa-file-excel"></i></span>
                    <span class="ar-item-label">Export as Excel</span>
                </button>

                <button type="button" @click="$store.report.doExport('print', $wire)"
                        :disabled="$store.report.exporting" class="ar-report-menu-item item-print">
                    <span class="ar-item-icon"><i class="fas fa-print"></i></span>
                    <span class="ar-item-label">Print Current View</span>
                </button>
            </div>
        </div>
    </div>

    {{-- Table Card --}}
    <div class="ar-table-card bg-white rounded-2xl shadow-sm border border-[#E8E0F0] flex flex-col overflow-hidden flex-1 min-h-0">

        {{-- ── Filter bar ── --}}
        <div class="ar-filter-bar px-3 sm:px-4 py-2.5 border-b border-[#E8E0F0] bg-[#F5F5F5] flex flex-wrap gap-2 items-center shrink-0 transition-opacity duration-200"
             wire:loading.class="opacity-60" wire:target="alumniSearch,alumniBatch,alumniCourse,alumniProfileFilter">

            <span class="ar-filter-label text-xs font-semibold tracking-widest uppercase shrink-0 select-none" style="color:#7A3F91;">FILTERS</span>

            <div class="flex items-center gap-1.5 shrink-0">
                <button type="button" wire:click="$set('alumniProfileFilter','all')"
                        class="ar-status-pill {{ $alumniProfileFilter === 'all' ? 'active-all' : '' }}">All</button>
                <button type="button" wire:click="$set('alumniProfileFilter','complete')"
                        class="ar-status-pill {{ $alumniProfileFilter === 'complete' ? 'active-complete' : '' }}">Complete</button>
                <button type="button" wire:click="$set('alumniProfileFilter','incomplete')"
                        class="ar-status-pill {{ $alumniProfileFilter === 'incomplete' ? 'active-incomplete' : '' }}">Pending</button>
            </div>

            <div class="h-5 w-px bg-[#E8E0F0] shrink-0 hidden sm:block"></div>

            <div class="relative flex-1 min-w-[150px] max-w-xs" wire:ignore
                 x-data="{ q:'', init(){ this.q=$wire.alumniSearch??''; $wire.$watch('alumniSearch',v=>{ if(v!==this.q)this.q=v; }); } }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-[#999999] text-sm pointer-events-none"></i>
                <input type="text" x-model="q" @input.debounce.300ms="$wire.set('alumniSearch',q)"
                       placeholder="Search name, ID, email…"
                       class="w-full pl-8 pr-3 py-2 border border-[#E8E0F0] rounded-lg text-sm bg-white text-[#333333]
                              placeholder-[#999999] focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/10 transition font-normal"
                       autocomplete="off" spellcheck="false">
            </div>

            <div class="ar-dropdown"
                 x-data="{ open:false, toggle(){ this.open=!this.open; }, close(){ this.open=false; }, select(val){ $wire.set('alumniBatch',val); this.close(); } }"
                 @click.outside="close()" wire:key="batch-dropdown">
                <button type="button" @click="toggle()" :class="{ 'has-value':$wire.alumniBatch!=='','open':open }" class="ar-dropdown-trigger">
                    <span>@if($alumniBatch){{ $alumniBatch }}@else All Batch Years @endif</span>
                    <i class="fas fa-chevron-down ar-chevron"></i>
                </button>
                <div x-show="open"
                     x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95 -translate-y-1" x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                     class="ar-dropdown-menu" style="display:none;">
                    <button type="button" @click="select('')" :class="{'active':$wire.alumniBatch===''}" class="ar-dropdown-item">All Batch Years</button>
                    @foreach($this->batches as $b)
                    <button type="button" @click="select('{{ $b }}')" :class="{'active':$wire.alumniBatch==='{{ $b }}'}" class="ar-dropdown-item">{{ $b }}</button>
                    @endforeach
                </div>
            </div>

            <div class="ar-dropdown"
                 x-data="{ open:false, toggle(){ this.open=!this.open; }, close(){ this.open=false; }, select(val){ $wire.set('alumniCourse',val); this.close(); } }"
                 @click.outside="close()" wire:key="course-dropdown">
                <button type="button" @click="toggle()" :class="{ 'has-value':$wire.alumniCourse!=='','open':open }" class="ar-dropdown-trigger">
                    <span>@if($alumniCourse){{ $alumniCourse }}@else All Program Codes @endif</span>
                    <i class="fas fa-chevron-down ar-chevron"></i>
                </button>
                <div x-show="open"
                     x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 scale-95 -translate-y-1" x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                     x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                     class="ar-dropdown-menu" style="display:none;">
                    <button type="button" @click="select('')" :class="{'active':$wire.alumniCourse===''}" class="ar-dropdown-item">All Program Codes</button>
                    @foreach($this->courses as $c)
                    <button type="button" @click="select('{{ $c->code }}')" :class="{'active':$wire.alumniCourse==='{{ $c->code }}'}" class="ar-dropdown-item">{{ $c->code }}</button>
                    @endforeach
                </div>
            </div>

            <button wire:click="resetAlumniFilters" wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-wait" wire:target="resetAlumniFilters"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold bg-white border border-[#E8E0F0] text-[#333333] hover:bg-[#F5F5F5] transition active:scale-95 disabled:pointer-events-none">
                <i class="fas fa-rotate-left text-sm"></i>
                <span class="hidden sm:inline">Reset</span>
            </button>

            @if($alumniProfileFilter !== 'all')
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold border
                         {{ $alumniProfileFilter === 'complete' ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-amber-50 text-amber-700 border-amber-200' }}">
                Showing {{ $alumniProfileFilter === 'complete' ? 'Complete' : 'Pending' }} only
                &mdash; {{ number_format($this->alumniRecords->total()) }} result(s)
            </span>
            @endif
            @if($alumniBatch !== '')
            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold border bg-[#F9F7FC] text-[#7A3F91] border-[#E8E0F0]">
                <i class="fas fa-calendar text-[10px]"></i>Batch {{ $alumniBatch }}
                &mdash; {{ number_format($this->alumniRecords->total()) }} result(s)
            </span>
            @endif
        </div>

        <div class="ar-filter-progress-track" wire:loading wire:target="alumniSearch,alumniBatch,alumniCourse,alumniProfileFilter">
            <div class="ar-filter-progress-bar"></div>
        </div>
        <style>
            .ar-filter-progress-track { height:2px;width:100%;overflow:hidden;background:transparent;position:relative; }
            .ar-filter-progress-bar { position:absolute;top:0;left:0;height:100%;width:40%;border-radius:99px;background:linear-gradient(135deg,#7A3F91,#9b59b6);animation:arFilterProgress 1s ease-in-out infinite; }
            @keyframes arFilterProgress { 0%{left:-40%} 100%{left:100%} }
        </style>

        <div class="relative flex-1 min-h-0" x-data="{ showTop:false }">
            <div id="alumni-scroll" @scroll.passive="showTop=$event.target.scrollTop>200"
                 class="h-full overflow-y-auto transition-opacity duration-200"
                 wire:loading.class="opacity-40 pointer-events-none" wire:target="alumniSearch,alumniBatch,alumniCourse,alumniProfileFilter">

                {{-- ── DESKTOP / TABLET: table view ── --}}
                <table class="w-full border-collapse table-fixed hidden md:table">
                    <colgroup>
                        <col style="width:28%;"><col style="width:18%;"><col style="width:14%;"><col style="width:12%;"><col style="width:28%;">
                    </colgroup>
                    <thead>
                        <tr class="bg-[#F5F5F5] border-b-2 border-[#E8E0F0] sticky top-0 z-10">
                            <th class="px-4 py-3 text-left text-xs font-semibold text-[#555555] uppercase tracking-widest">Name</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-[#555555] uppercase tracking-widest">Student ID</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-[#555555] uppercase tracking-widest">Program Code</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold text-[#555555] uppercase tracking-widest">Batch</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-[#555555] uppercase tracking-widest">Email</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F0ECF5]">
                        @forelse($this->alumniRecords as $item)
                        @php
                            $displayName = $this->formatDisplayName(
                                $item->first_name ?? '', $item->middle_initial ?? '',
                                $item->last_name ?? '', $item->suffix ?? ''
                            );
                        @endphp
                        <tr class="ar-row bg-white" wire:key="ar-row-{{ $item->id }}" data-ar-id="{{ $item->id }}" wire:click="viewProfile({{ $item->id }})">
                            <td class="px-4 py-3 overflow-hidden">
                                <div class="flex items-center gap-2.5">
                                    <img src="{{ $this->getPhotoUrl($item->profile_photo) }}" alt="{{ $item->first_name }}"
                                         class="w-8 h-8 rounded-lg object-cover shrink-0 ring-1 ring-[#E8E0F0]" draggable="false"
                                         onerror="this.onerror=null;this.src='{{ asset('storage/alumni-photos/default.png') }}';">
                                    <span class="font-semibold text-[#333333] text-sm uppercase truncate block">
                                        {!! $this->highlight($displayName, $this->alumniSearch) !!}
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-3 overflow-hidden">
                                <span class="font-mono text-[#333333] text-sm font-semibold uppercase truncate block">
                                    {!! $this->highlight($item->student_id ?? '', $this->alumniSearch) !!}
                                </span>
                            </td>
                            <td class="px-4 py-3 overflow-hidden">
                                <span class="inline-block px-2.5 py-1 rounded-full text-xs font-semibold uppercase truncate max-w-full"
                                      style="background:#F9F7FC;color:#7A3F91;border:1px solid #E8E0F0;">
                                    {{ $item->course_code ?? '—' }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center overflow-hidden">
                                <span class="font-mono text-[#333333] text-sm font-semibold uppercase">{{ $item->batch }}</span>
                            </td>
                            <td class="px-4 py-3 overflow-hidden">
                                <span class="text-[#333333] text-sm font-normal truncate block">
                                    {!! $this->highlight($item->email ?? '', $this->alumniSearch) !!}
                                </span>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="5" class="py-24 text-center">
                                <div class="flex flex-col items-center gap-3">
                                    <div class="w-14 h-14 rounded-2xl flex items-center justify-center" style="background:#f0e6f8;">
                                        <i class="fas fa-users text-2xl" style="color:#c89de0;"></i>
                                    </div>
                                    <p class="font-semibold text-[#666666] text-xl">No alumni found</p>
                                    <p class="text-sm text-[#999999] font-normal">Try adjusting your filters</p>
                                </div>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>

                {{-- ── MOBILE: stacked card list ── --}}
                <div class="block md:hidden">
                    @forelse($this->alumniRecords as $item)
                    @php
                        $displayName = $this->formatDisplayName(
                            $item->first_name ?? '', $item->middle_initial ?? '',
                            $item->last_name ?? '', $item->suffix ?? ''
                        );
                    @endphp
                    <div class="ar-mrow" wire:key="ar-mrow-{{ $item->id }}" data-ar-id="{{ $item->id }}" wire:click="viewProfile({{ $item->id }})">
                        <img src="{{ $this->getPhotoUrl($item->profile_photo) }}" alt="{{ $item->first_name }}"
                             class="w-10 h-10 rounded-lg object-cover shrink-0 ring-1 ring-[#E8E0F0]" draggable="false"
                             onerror="this.onerror=null;this.src='{{ asset('storage/alumni-photos/default.png') }}';">
                        <div class="flex-1 min-w-0">
                            <p class="font-semibold text-[#333333] text-sm uppercase truncate">
                                {!! $this->highlight($displayName, $this->alumniSearch) !!}
                            </p>
                            <div class="flex items-center gap-1.5 mt-1 flex-wrap">
                                <span class="font-mono text-[#333333] text-xs font-semibold uppercase">
                                    {!! $this->highlight($item->student_id ?? '', $this->alumniSearch) !!}
                                </span>
                                <span class="text-[#CCCCCC] text-xs">&bull;</span>
                                <span class="inline-block px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase"
                                      style="background:#F9F7FC;color:#7A3F91;border:1px solid #E8E0F0;">
                                    {{ $item->course_code ?? '—' }}
                                </span>
                                <span class="text-[#CCCCCC] text-xs">&bull;</span>
                                <span class="font-mono text-[#333333] text-xs font-semibold">Batch {{ $item->batch }}</span>
                            </div>
                        </div>
                        <i class="fas fa-chevron-right text-[#CCCCCC] text-xs shrink-0"></i>
                    </div>
                    @empty
                    <div class="py-24 text-center px-4">
                        <div class="flex flex-col items-center gap-3">
                            <div class="w-14 h-14 rounded-2xl flex items-center justify-center" style="background:#f0e6f8;">
                                <i class="fas fa-users text-2xl" style="color:#c89de0;"></i>
                            </div>
                            <p class="font-semibold text-[#666666] text-xl">No alumni found</p>
                            <p class="text-sm text-[#999999] font-normal">Try adjusting your filters</p>
                        </div>
                    </div>
                    @endforelse
                </div>

            </div>

            <button x-show="showTop" @click="document.getElementById('alumni-scroll').scrollTo({top:0,behavior:'smooth'})"
                    class="absolute bottom-4 right-4 z-20 w-8 h-8 rounded-full flex items-center justify-center shadow-lg text-white transition hover:opacity-90"
                    style="background:#7A3F91;display:none;">
                <i class="fas fa-arrow-up text-sm"></i>
            </button>
        </div>

        @php
            $total    = $this->alumniRecords->total();
            $pp       = $this->alumniRecords->perPage();
            $cp       = $this->alumniRecords->currentPage();
            $lastPage = $this->alumniRecords->lastPage();
            $from     = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
            $to       = min($cp * $pp, $total);
            $pgStart  = max(1, $cp - 2);
            $pgEnd    = min($lastPage, $cp + 2);
        @endphp
        <div class="px-4 py-2.5 border-t border-[#7A3F91]/30 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 ar-pg-footer"
             style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
            <p class="text-white/70 text-sm font-normal">
                Showing <strong class="text-white font-semibold">{{ $from }}–{{ $to }}</strong>
                of <strong class="text-white font-semibold">{{ $total }}</strong> alumni
                @if($alumniProfileFilter !== 'all')
                    <span class="text-white/50 font-normal">&nbsp;({{ $alumniProfileFilter === 'complete' ? 'complete profiles' : 'pending profiles' }})</span>
                @endif
                @if($alumniBatch !== '')
                    <span class="text-white/50 font-normal">&nbsp;&bull; Batch {{ $alumniBatch }}</span>
                @endif
            </p>
            @if($lastPage > 1)
            <div class="flex items-center gap-1.5 flex-wrap">
                @if($this->alumniRecords->onFirstPage())
                    <button disabled class="ar-pg-btn ar-pg-nav"><i class="fas fa-chevron-left text-xs"></i></button>
                @else
                    <button wire:click="previousPage('alumniPage')" class="ar-pg-btn ar-pg-nav"><i class="fas fa-chevron-left text-xs"></i></button>
                @endif
                @if($pgStart > 1)
                    <button wire:click="gotoPage(1,'alumniPage')" class="ar-pg-btn ar-pg-nav">1</button>
                    @if($pgStart > 2)<span class="text-white/50 text-sm font-bold px-1">…</span>@endif
                @endif
                @for($p = $pgStart; $p <= $pgEnd; $p++)
                    @if($p === $cp)
                        <span class="ar-pg-btn ar-pg-active">{{ $p }}</span>
                    @else
                        <button wire:click="gotoPage({{ $p }},'alumniPage')" class="ar-pg-btn ar-pg-nav">{{ $p }}</button>
                    @endif
                @endfor
                @if($pgEnd < $lastPage)
                    @if($pgEnd < $lastPage - 1)<span class="text-white/50 text-sm font-bold px-1">…</span>@endif
                    <button wire:click="gotoPage({{ $lastPage }},'alumniPage')" class="ar-pg-btn ar-pg-nav">{{ $lastPage }}</button>
                @endif
                @if($this->alumniRecords->hasMorePages())
                    <button wire:click="nextPage('alumniPage')" class="ar-pg-btn ar-pg-nav"><i class="fas fa-chevron-right text-xs"></i></button>
                @else
                    <button disabled class="ar-pg-btn ar-pg-nav"><i class="fas fa-chevron-right text-xs"></i></button>
                @endif
                <span class="text-white/60 text-xs font-semibold ml-1 hidden sm:inline">Page {{ $cp }}/{{ $lastPage }}</span>
            </div>
            @endif
        </div>

    </div>{{-- end table card --}}
</div>{{-- end page --}}


{{-- ══ VIEW PROFILE PANEL ══════════════════════════════════════════ --}}
@if($activeModal === 'viewProfile' && $viewingProfile)
@php
    $up = fn(?string $v): string => strtoupper(trim($v ?? ''));

    $emp = $viewingEmployment;

    $empStatusMap = [
        'employed'      => 'Employed',
        'self_employed' => 'Self-Employed',
        'unemployed'    => 'Unemployed',
    ];
    $empTypeMap = [
        'full_time'     => 'Full-Time',
        'part_time'     => 'Part-Time',
        'contractual'   => 'Contractual',
        'project_based' => 'Project-Based',
        'internship'    => 'Internship',
    ];
    $relevanceMap = [
        'yes'       => 'Related to Course',
        'no'        => 'Not Related to Course',
        'partially' => 'Partially Related',
    ];
    $unempMap = [
        'seeking_employment' => 'Actively Seeking Employment',
        'not_looking'        => 'Not Currently Looking',
    ];
    $workLocMap = [
        'local'  => 'Local',
        'abroad' => 'Abroad',
        'remote' => 'Remote',
        'hybrid' => 'Hybrid / Mixed',
    ];
    $cpLabels = [
        'ofw'                   => 'OFW',
        'freelancer'            => 'Freelancer',
        'entrepreneur'          => 'Entrepreneur',
        'career_shifter'        => 'Career Shifter',
        'industry_professional' => 'Industry Professional',
    ];
    $eduMap = [
        'pursuing_masteral'  => 'Pursuing Masteral',
        'pursuing_doctorate' => 'Pursuing Doctorate',
    ];

    $empStatus   = $emp['employment_status'] ?? null;
    $isWorking   = in_array($empStatus, ['employed', 'self_employed']);
    $empTypeLbl  = $empTypeMap[$emp['employment_type'] ?? ''] ?? null;
    $dateHired   = !empty($emp['date_hired'])
        ? \Carbon\Carbon::parse($emp['date_hired'])->format('F j, Y') : null;
    $updatedAt   = !empty($emp['updated_at'])
        ? \Carbon\Carbon::parse($emp['updated_at'])->format('M j, Y g:i A') : null;
    $submittedAt = !empty($emp['created_at'])
        ? \Carbon\Carbon::parse($emp['created_at'])->format('M j, Y') : null;
    $careerPath  = !empty($emp['career_path']) ? json_decode($emp['career_path'], true) : [];

    $dob = !empty($viewingProfile['date_of_birth'])
        ? strtoupper(\Carbon\Carbon::parse($viewingProfile['date_of_birth'])->format('F j, Y'))
        : '—';

    $hasCustomPhoto = !empty($viewingProfile['profile_photo'])
        && !str_contains($viewingProfile['profile_photo'], 'default.png');

    $profileIsComplete = $this->isProfileComplete($viewingProfile);
@endphp

<div class="fixed inset-0 z-50"
     style="background:rgba(27,6,46,0.55);backdrop-filter:blur(3px);"
     @keydown.escape.window="$wire.closeModal()">

    <div class="w-full h-full flex flex-col ar-panel" style="background:#F2F2F2;overflow:hidden;">

        <div class="flex items-center justify-between px-5 sm:px-6 py-3 shrink-0"
             style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
            <div class="flex items-center gap-3 min-w-0">
                <div class="w-7 h-7 rounded-lg bg-white/20 flex items-center justify-center shrink-0">
                    <i class="fas fa-user text-white text-xs"></i>
                </div>
                <div class="min-w-0">
                    <h2 class="text-white font-semibold text-sm leading-tight">Alumni Profile</h2>
                    <p class="text-white/60 text-xs font-normal truncate">
                        {{ $this->formatDisplayName($viewingProfile['first_name']??'',$viewingProfile['middle_initial']??'',$viewingProfile['last_name']??'',$viewingProfile['suffix']??'') }}
                    </p>
                </div>
            </div>
            <button wire:click="closeModal" class="ar-close-btn">
                <span class="ar-close-tip">Close</span>
                <i class="fas fa-xmark text-sm"></i>
            </button>
        </div>

        <div class="flex-1 min-h-0 ar-profile-body">
            <div class="ar-profile-grid">

                <div style="grid-column:1/-1;"
                     x-data="{
                         previewSrc: '{{ $this->getPhotoUrl($viewingProfile['profile_photo'] ?? null) }}',
                         originalSrc: '{{ $this->getPhotoUrl($viewingProfile['profile_photo'] ?? null) }}',
                         defaultSrc: '{{ asset('storage/alumni-photos/default.png') }}',
                         pendingFile: null,
                         hasFile: false,
                         saving: false,
                         isDefaultPending: false,
                         get isShowingDefault() { return this.previewSrc === this.defaultSrc; },
                         init() {
                             $wire.$on('photo-saved', () => {
                                 this.pendingFile = null; this.hasFile = false;
                                 this.saving = false; this.isDefaultPending = false;
                                 this.originalSrc = this.previewSrc;
                             });
                         },
                         onFileChange(event) {
                             const file = event.target.files[0];
                             if (!file) return;
                             this.pendingFile = file; this.hasFile = true; this.isDefaultPending = false;
                             const reader = new FileReader();
                             reader.onload = (e) => { this.previewSrc = e.target.result; };
                             reader.readAsDataURL(file);
                         },
                         setDefault() {
                             this.pendingFile = null; this.hasFile = true;
                             this.isDefaultPending = true; this.previewSrc = this.defaultSrc;
                             if (this.$refs.photoInput) this.$refs.photoInput.value = '';
                         },
                         savePhoto() {
                             if (this.saving) return;
                             this.saving = true;
                             if (this.isDefaultPending) {
                                 $wire.resetAlumniPhoto();
                             } else if (this.pendingFile) {
                                 $wire.upload('newAlumniPhoto', this.pendingFile,
                                     () => { $wire.uploadAlumniPhoto(); },
                                     () => {
                                         this.saving = false; this.hasFile = false;
                                         this.isDefaultPending = false; this.pendingFile = null;
                                         this.previewSrc = this.originalSrc;
                                         if (this.$refs.photoInput) this.$refs.photoInput.value = '';
                                     },
                                     () => {}
                                 );
                             } else { this.saving = false; }
                         },
                         cancelPhoto() {
                             this.pendingFile = null; this.hasFile = false;
                             this.isDefaultPending = false; this.previewSrc = this.originalSrc;
                             if (this.$refs.photoInput) this.$refs.photoInput.value = '';
                         }
                     }"
                     class="ar-avatar-strip">

                    <div class="ar-photo-col">
                        <div class="flex items-start gap-2">
                            <div class="relative group" style="width:108px;height:108px;flex-shrink:0;">
                                <img :src="previewSrc" alt="{{ $viewingProfile['first_name'] ?? '' }}"
                                     class="w-full h-full object-cover shadow-md transition-all" style="border-radius:12px;"
                                     :class="hasFile ? 'ring-2 ring-[#7A3F91] ring-offset-2' : 'ring-2 ring-[#E0E0E0]'"
                                     onerror="this.onerror=null;this.src='{{ asset('storage/alumni-photos/default.png') }}';">
                                <div x-show="saving" class="ar-uploading-overlay">
                                    <i class="fas fa-spinner fa-spin text-[#7A3F91] text-base"></i>
                                </div>
                                <label x-show="!saving"
                                       class="absolute inset-0 flex flex-col items-center justify-center gap-1 bg-black/55 opacity-0 group-hover:opacity-100 transition-opacity cursor-pointer"
                                       style="border-radius:12px;">
                                    <i class="fas fa-camera text-white" style="font-size:16px;"></i>
                                    <input type="file" x-ref="photoInput" class="hidden"
                                           accept="image/jpeg,image/png,image/webp" @change="onFileChange($event)">
                                </label>
                                <span x-show="hasFile && !saving"
                                      class="absolute -top-1.5 -right-1.5 w-3.5 h-3.5 rounded-full bg-[#7A3F91] border-2 border-white shadow"
                                      style="display:none;"></span>
                            </div>

                            <div class="flex flex-col gap-1.5" x-show="!saving" style="padding-top:2px;">
                                <div class="relative group/rst">
                                    <button type="button" class="ar-photo-action-btn ar-photo-default-btn"
                                            x-show="!hasFile && !isShowingDefault" @click="setDefault()" style="display:none;">
                                        <i class="fas fa-rotate-left"></i>
                                    </button>
                                    <div class="absolute left-[calc(100%+8px)] top-1/2 -translate-y-1/2 pointer-events-none
                                                bg-[#1a1a1a] text-white font-semibold whitespace-nowrap shadow-lg
                                                opacity-0 group-hover/rst:opacity-100 transition-opacity duration-150 z-[9999]"
                                         style="font-size:10px;padding:4px 9px;border-radius:6px;letter-spacing:.04em;">
                                        Back to default photo
                                        <span class="absolute right-full top-1/2 -translate-y-1/2 border-4 border-transparent border-r-[#1a1a1a]"></span>
                                    </div>
                                </div>
                                <div class="relative group/sav">
                                    <button type="button" class="ar-photo-action-btn ar-photo-save-btn"
                                            x-show="hasFile" @click="savePhoto()" style="display:none;">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <div class="absolute left-[calc(100%+8px)] top-1/2 -translate-y-1/2 pointer-events-none
                                                bg-[#1a1a1a] text-white font-semibold whitespace-nowrap shadow-lg
                                                opacity-0 group-hover/sav:opacity-100 transition-opacity duration-150 z-[9999]"
                                         style="font-size:10px;padding:4px 9px;border-radius:6px;letter-spacing:.04em;">
                                        Save photo
                                        <span class="absolute right-full top-1/2 -translate-y-1/2 border-4 border-transparent border-r-[#1a1a1a]"></span>
                                    </div>
                                </div>
                                <div class="relative group/can">
                                    <button type="button" class="ar-photo-action-btn ar-photo-cancel-btn"
                                            x-show="hasFile" @click="cancelPhoto()" style="display:none;">
                                        <i class="fas fa-xmark"></i>
                                    </button>
                                    <div class="absolute left-[calc(100%+8px)] top-1/2 -translate-y-1/2 pointer-events-none
                                                bg-[#1a1a1a] text-white font-semibold whitespace-nowrap shadow-lg
                                                opacity-0 group-hover/can:opacity-100 transition-opacity duration-150 z-[9999]"
                                         style="font-size:10px;padding:4px 9px;border-radius:6px;letter-spacing:.04em;">
                                        Cancel
                                        <span class="absolute right-full top-1/2 -translate-y-1/2 border-4 border-transparent border-r-[#1a1a1a]"></span>
                                    </div>
                                </div>
                            </div>

                            <span x-show="saving" class="inline-flex items-center gap-1.5 font-semibold self-start"
                                  style="font-size:.72rem;color:#7A3F91;display:none;padding-top:6px;">
                                <i class="fas fa-spinner fa-spin" style="font-size:10px;"></i> Saving…
                            </span>
                        </div>

                        <p x-show="!hasFile && !saving" class="text-center font-semibold leading-tight select-none"
                           style="font-size:.72rem;color:#666666;max-width:108px;display:block;">
                            Hover photo to change
                        </p>
                    </div>

                    <div class="ar-info-col">
                        <p class="font-bold uppercase leading-tight" style="font-size:1.15rem;color:#111111;">
                            {{ $this->formatDisplayName($viewingProfile['first_name']??'',$viewingProfile['middle_initial']??'',$viewingProfile['last_name']??'',$viewingProfile['suffix']??'') }}
                        </p>
                        <p class="font-mono" style="font-size:.82rem;color:#444444;letter-spacing:.03em;">
                            {{ $viewingProfile['student_id'] ?? '—' }}
                        </p>
                        <div class="flex flex-wrap items-center gap-1.5 mt-0.5">
                            <span class="ar-info-chip" style="background:transparent;color:#333333;border:none;padding-left:0;padding-right:0;font-size:.8rem;">
                                {{ $viewingProfile['course_code'] ?? '—' }}
                            </span>
                            <span style="color:#CCCCCC;font-size:.8rem;">•</span>
                            <span class="ar-info-chip" style="background:transparent;color:#333333;border:none;padding-left:0;padding-right:0;font-size:.8rem;">
                                Batch {{ $viewingProfile['batch'] ?? '—' }}
                            </span>
                            <span style="color:#CCCCCC;font-size:.8rem;">•</span>
                            @if($profileIsComplete)
                                <span class="ar-info-chip" style="background:#ECFDF5;color:#059669;border:1px solid #6ee7b7;">Complete</span>
                            @else
                                <span class="ar-info-chip" style="background:#FFFBEB;color:#D97706;border:1px solid #fcd34d;">Incomplete</span>
                            @endif
                        </div>
                        <p style="font-size:.9rem;color:#444444;font-weight:500;">
                            {{ $viewingProfile['email'] ?? '—' }}
                        </p>
                    </div>
                </div>{{-- end avatar strip --}}

                <div class="ar-card" style="grid-column:1/2;">
                    <div class="ar-card-header"><p>Student ID</p></div>
                    <div class="p-2">
                        <div class="ar-cell">
                            <p class="ar-field-label">Student ID</p>
                            <p class="ar-field-value font-mono">{{ $up($viewingProfile['student_id'] ?? '') ?: '—' }}</p>
                        </div>
                    </div>
                </div>

                <div class="ar-card" style="grid-column:2/-1;">
                    <div class="ar-card-header"><p>Student's Name</p></div>
                    <div class="p-2 grid grid-cols-2 sm:grid-cols-4 gap-1.5">
                        <div class="ar-cell">
                            <p class="ar-field-label">Last Name</p>
                            <p class="ar-field-value">{{ $up($viewingProfile['last_name'] ?? '') ?: '—' }}</p>
                        </div>
                        <div class="ar-cell">
                            <p class="ar-field-label">Given Name</p>
                            <p class="ar-field-value">{{ $up($viewingProfile['first_name'] ?? '') ?: '—' }}</p>
                        </div>
                        <div class="ar-cell">
                            <p class="ar-field-label">Middle Name</p>
                            <p class="ar-field-value">{{ $up($viewingProfile['middle_initial'] ?? '') ?: '—' }}</p>
                        </div>
                        <div class="ar-cell">
                            <p class="ar-field-label">Ext.</p>
                            <p class="ar-field-value">{{ $up($viewingProfile['suffix'] ?? '') ?: '—' }}</p>
                        </div>
                    </div>
                </div>

                <div class="ar-card">
                    <div class="ar-card-header"><p>Student's Data</p></div>
                    <div class="p-2 grid grid-cols-2 gap-1.5">
                        <div class="ar-cell">
                            <p class="ar-field-label">Sex</p>
                            <p class="ar-field-value">{{ $up($viewingProfile['gender'] ?? '') ?: '—' }}</p>
                        </div>
                        <div class="ar-cell">
                            <p class="ar-field-label">Birthdate</p>
                            <p class="ar-field-value">{{ $dob }}</p>
                        </div>
                        <div class="ar-cell">
                            <p class="ar-field-label">Program Name</p>
                            <p class="ar-field-value">{{ $up($viewingProfile['course_name'] ?? $viewingProfile['course_code'] ?? '') ?: '—' }}</p>
                        </div>
                    </div>
                </div>

                <div class="ar-card">
                    <div class="ar-card-header"><p>Father's Name</p></div>
                    <div class="p-2 grid grid-cols-3 gap-1.5">
                        <div class="ar-cell">
                            <p class="ar-field-label">Last Name</p>
                            <p class="ar-field-value">{{ $up($viewingProfile['father_last_name'] ?? '') ?: '—' }}</p>
                        </div>
                        <div class="ar-cell">
                            <p class="ar-field-label">Given Name</p>
                            <p class="ar-field-value">{{ $up($viewingProfile['father_given_name'] ?? '') ?: '—' }}</p>
                        </div>
                        <div class="ar-cell">
                            <p class="ar-field-label">Middle Name</p>
                            <p class="ar-field-value">{{ $up($viewingProfile['father_middle_name'] ?? '') ?: '—' }}</p>
                        </div>
                    </div>
                </div>

                <div class="ar-card">
                    <div class="ar-card-header"><p>Mother's Maiden Name</p></div>
                    <div class="p-2 grid grid-cols-3 gap-1.5">
                        <div class="ar-cell">
                            <p class="ar-field-label">Last Name</p>
                            <p class="ar-field-value">{{ $up($viewingProfile['mother_last_name'] ?? '') ?: '—' }}</p>
                        </div>
                        <div class="ar-cell">
                            <p class="ar-field-label">Given Name</p>
                            <p class="ar-field-value">{{ $up($viewingProfile['mother_given_name'] ?? '') ?: '—' }}</p>
                        </div>
                        <div class="ar-cell">
                            <p class="ar-field-label">Middle Name</p>
                            <p class="ar-field-value">{{ $up($viewingProfile['mother_middle_name'] ?? '') ?: '—' }}</p>
                        </div>
                    </div>
                </div>

                <div class="ar-card" style="grid-column:1/-1;">
                    <div class="ar-card-header"><p>Permanent Address</p></div>
                    <div class="p-2 grid grid-cols-2 sm:grid-cols-4 gap-1.5">
                        <div class="ar-cell">
                            <p class="ar-field-label">Street</p>
                            <p class="ar-field-value">{{ $up($viewingProfile['address_street'] ?? '') ?: '—' }}</p>
                        </div>
                        <div class="ar-cell">
                            <p class="ar-field-label">Barangay</p>
                            <p class="ar-field-value">{{ $up($viewingProfile['address_barangay'] ?? '') ?: '—' }}</p>
                        </div>
                        <div class="ar-cell">
                            <p class="ar-field-label">Municipality / City</p>
                            <p class="ar-field-value">{{ $up($viewingProfile['address_municipality'] ?? '') ?: '—' }}</p>
                        </div>
                        <div class="ar-cell">
                            <p class="ar-field-label">Province</p>
                            <p class="ar-field-value">{{ $up($viewingProfile['address_province'] ?? '') ?: '—' }}</p>
                        </div>
                    </div>
                </div>

                <div class="ar-card" style="grid-column:1/-1;">
                    <div class="ar-card-header"><p>Additional Information</p></div>
                    <div class="p-2 grid grid-cols-2 sm:grid-cols-4 gap-1.5">
                        <div class="ar-cell">
                            <p class="ar-field-label">DSWD Household No.</p>
                            <p class="ar-field-value">{{ $up($viewingProfile['dswd_household_no'] ?? '') ?: '—' }}</p>
                        </div>
                        <div class="ar-cell">
                            <p class="ar-field-label">Disability</p>
                            <p class="ar-field-value">{{ $up($viewingProfile['disability'] ?? '') ?: '—' }}</p>
                        </div>
                        <div class="ar-cell">
                            <p class="ar-field-label">Contact Number</p>
                            <p class="ar-field-value">{{ $up($viewingProfile['contact_number'] ?? '') ?: '—' }}</p>
                        </div>
                        <div class="ar-cell">
                            <p class="ar-field-label">Email Address</p>
                            <p class="ar-field-value">{{ trim($viewingProfile['email'] ?? '') ?: '—' }}</p>
                        </div>
                    </div>
                </div>

                <div class="ar-card" style="grid-column:1/-1; margin-bottom:4px;">

                    <div class="ar-card-header">
                        <p>Employment</p>
                        @if($emp && $updatedAt)
                            <span style="font-size:.7rem;font-weight:600;color:#555555;text-transform:none;letter-spacing:0;font-family:inherit;">
                                Last updated {{ $updatedAt }}
                            </span>
                        @endif
                    </div>

                    @if(!$emp)
                        <div class="p-3">
                            <div class="ar-cell">
                                <p class="ar-field-label">Employment Record</p>
                                <p class="ar-field-value">No employment information submitted yet.</p>
                            </div>
                        </div>
                    @else
                        <div class="ar-emp-grid">

                            <div class="flex flex-col gap-1.5">

                                <div class="ar-cell">
                                    <p class="ar-field-label">Status</p>
                                    <p class="ar-field-value">{{ $empStatusMap[$empStatus] ?? '—' }}</p>
                                </div>

                                @if($isWorking && $empTypeLbl)
                                <div class="ar-cell">
                                    <p class="ar-field-label">Employment Type</p>
                                    <p class="ar-field-value">{{ $empTypeLbl }}</p>
                                </div>
                                @endif

                                @if($isWorking && !empty($emp['work_location']))
                                <div class="ar-cell">
                                    <p class="ar-field-label">Work Location</p>
                                    <p class="ar-field-value">{{ $workLocMap[$emp['work_location']] ?? ucfirst($emp['work_location']) }}</p>
                                </div>
                                @endif

                                @if($empStatus === 'unemployed' && !empty($emp['unemployment_status']))
                                <div class="ar-cell">
                                    <p class="ar-field-label">Unemployment Reason</p>
                                    <p class="ar-field-value">{{ $unempMap[$emp['unemployment_status']] ?? '—' }}</p>
                                </div>
                                @endif

                                @if(!empty($emp['education_status']) && $emp['education_status'] !== 'none' && isset($eduMap[$emp['education_status']]))
                                <div class="ar-cell">
                                    <p class="ar-field-label">Further Studies</p>
                                    <p class="ar-field-value">{{ $eduMap[$emp['education_status']] }}</p>
                                </div>
                                @endif

                                @if($submittedAt)
                                <div class="ar-cell">
                                    <p class="ar-field-label">Date Submitted</p>
                                    <p class="ar-field-value">{{ $submittedAt }}</p>
                                </div>
                                @endif

                            </div>

                            <div class="flex flex-col gap-1.5">

                                @if($isWorking)
                                    @if(!empty($emp['job_title']))
                                    <div class="ar-cell">
                                        <p class="ar-field-label">{{ $empStatus === 'self_employed' ? 'Position / Role' : 'Job Title' }}</p>
                                        <p class="ar-field-value" style="text-transform:uppercase;">{{ strtoupper($emp['job_title']) }}</p>
                                    </div>
                                    @endif

                                    @if(!empty($emp['company_name']))
                                    <div class="ar-cell">
                                        <p class="ar-field-label">{{ $empStatus === 'self_employed' ? 'Business Name' : 'Company' }}</p>
                                        <p class="ar-field-value" style="text-transform:uppercase;">{{ strtoupper($emp['company_name']) }}</p>
                                    </div>
                                    @endif

                                    @if($dateHired)
                                    <div class="ar-cell">
                                        <p class="ar-field-label">Date Hired</p>
                                        <p class="ar-field-value">{{ $dateHired }}</p>
                                    </div>
                                    @endif

                                    @if(!empty($emp['course_relevance']) && isset($relevanceMap[$emp['course_relevance']]))
                                    <div class="ar-cell">
                                        <p class="ar-field-label">Course Relevance</p>
                                        <p class="ar-field-value">{{ $relevanceMap[$emp['course_relevance']] }}</p>
                                    </div>
                                    @endif

                                    @if(count($careerPath))
                                    <div class="ar-cell">
                                        <p class="ar-field-label">Career Path</p>
                                        <p class="ar-field-value">
                                            {{ implode(', ', array_map(fn($k) => $cpLabels[$k] ?? $k, $careerPath)) }}
                                        </p>
                                    </div>
                                    @endif
                                @endif

                            </div>

                        </div>
                    @endif
                </div>{{-- end employment card --}}

            </div>{{-- end grid --}}
        </div>{{-- end body --}}
    </div>{{-- end panel --}}
</div>{{-- end overlay --}}
@endif

</div>{{-- end root --}}

<script>
(function () {
    // ══ FIX #3 (original): suppress clicks landing right after a
    // Livewire DOM update (timing window). ══
    var arSuppressRowClicksUntil = 0;
    document.addEventListener('livewire:updated', function () {
        arSuppressRowClicksUntil = Date.now() + 280;
    });

    // ══ FIX #3b (identity-based — closes the gap the timer alone
    // missed): the old fix only relied on a fixed 280ms timer. On a
    // slower device/connection, Livewire's morph can still finish AFTER
    // that window closes, and the phantom click slips through — this is
    // the "auto-opens View Details by itself sometimes" bug.
    //
    // This adds a second, timing-independent guard: we remember WHICH
    // alumni row (by data-ar-id) was actually under the pointer at
    // pointerdown. If, by the time the click event fires, that exact
    // screen position now belongs to a DIFFERENT alumni's row (because
    // Livewire swapped the row's content in between mousedown and
    // mouseup), that's proof the click is phantom — regardless of how
    // much time passed — and we swallow it. A genuine, intentional click
    // always has the SAME row id at pointerdown and at click. ══
    var arPointerDownRowId = null;

    document.addEventListener('pointerdown', function (e) {
        var row = e.target.closest('.ar-row, .ar-mrow');
        arPointerDownRowId = row ? row.getAttribute('data-ar-id') : null;
    }, true);

    document.addEventListener('click', function (e) {
        var row = e.target.closest('.ar-row, .ar-mrow');
        if (!row) { arPointerDownRowId = null; return; }

        var rowId = row.getAttribute('data-ar-id');
        var idChangedUnderneath = arPointerDownRowId !== null && arPointerDownRowId !== rowId;
        var withinTimedWindow = Date.now() < arSuppressRowClicksUntil;

        if (idChangedUnderneath || withinTimedWindow) {
            e.stopImmediatePropagation();
            e.preventDefault();
        }
        arPointerDownRowId = null;
    }, true);

    function registerReportStore() {
        if (!window.Alpine) return;
        if (window.Alpine.store('report')) return;

        window.Alpine.store('report', {
            open: false,
            exporting: false,
            _lastToggle: 0,
            _lastExport: 0,

            toggle() {
                const now = Date.now();
                if (now - this._lastToggle < 150) return;
                this._lastToggle = now;
                this.open = !this.open;
            },

            async readErrorMessage(res, fallback) {
                try {
                    const data = await res.clone().json();
                    if (data && data.message) return data.message;
                } catch (e) {
                    // response wasn't JSON — fall through to generic message
                }
                return fallback;
            },

            async doExport(type, wire) {
                const now = Date.now();
                if (this.exporting || now - this._lastExport < 400) return;
                this._lastExport = now;
                this.exporting = true;
                this.open = false;

                const label = type === 'excel' ? 'Excel file' : type === 'print' ? 'print view' : 'PDF';
                window.dispatchEvent(new CustomEvent('flash-message', {
                    detail: { type: 'info', message: 'Generating your ' + label + '… this only takes a moment.' }
                }));

                const params = new URLSearchParams({
                    type: type,
                    search: (wire && wire.alumniSearch) || '',
                    batch: (wire && wire.alumniBatch) || '',
                    course: (wire && wire.alumniCourse) || '',
                    profile_filter: (wire && wire.alumniProfileFilter) || 'all',
                });
                const url = '/registrar/alumni-records/export?' + params.toString();

                try {
                    if (type === 'print') {
                        const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                        if (!res.ok) {
                            const msg = await this.readErrorMessage(res, 'Print generation failed. Please try again.');
                            throw new Error(msg);
                        }
                        const html = await res.text();

                        const oldFrame = document.getElementById('ar-print-frame');
                        if (oldFrame) oldFrame.remove();

                        const frame = document.createElement('iframe');
                        frame.id = 'ar-print-frame';
                        frame.style.position = 'fixed';
                        frame.style.right = '0';
                        frame.style.bottom = '0';
                        frame.style.width = '0';
                        frame.style.height = '0';
                        frame.style.border = '0';

                        let printFired = false;
                        const firePrintOnce = () => {
                            if (printFired) return;
                            printFired = true;
                            frame.contentWindow.focus();
                            frame.contentWindow.print();
                        };
                        frame.onload = () => setTimeout(firePrintOnce, 150);

                        document.body.appendChild(frame);

                        const doc = frame.contentWindow.document;
                        doc.open();
                        doc.write(html);
                        doc.close();

                        setTimeout(firePrintOnce, 200);
                    } else {
                        const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
                        if (!res.ok) {
                            const msg = await this.readErrorMessage(
                                res,
                                type === 'excel' ? 'Excel export failed. Please try again.' : 'PDF export failed. Please try again.'
                            );
                            throw new Error(msg);
                        }

                        const blob = await res.blob();
                        const disposition = res.headers.get('Content-Disposition') || '';
                        let filename = type === 'excel' ? 'alumni-records.xlsx' : 'alumni-records.pdf';
                        const match = disposition.match(/filename="?([^"]+)"?/);
                        if (match) filename = match[1];

                        const blobUrl = window.URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = blobUrl;
                        a.download = filename;
                        document.body.appendChild(a);
                        a.click();
                        a.remove();
                        window.URL.revokeObjectURL(blobUrl);
                    }
                } catch (e) {
                    window.dispatchEvent(new CustomEvent('flash-message', {
                        detail: { type: 'error', message: e && e.message ? e.message : 'Export failed. Please try again.' }
                    }));
                } finally {
                    this.exporting = false;
                }
            }
        });
    }

    window.__arEnsureReportStore = registerReportStore;

    if (window.Alpine) {
        registerReportStore();
    }
    document.addEventListener('alpine:init', registerReportStore);
    document.addEventListener('livewire:init', registerReportStore);
    document.addEventListener('livewire:navigated', registerReportStore);

    var tip = document.getElementById('ar-hover-tip');

    function isHoverCapable() {
        return window.matchMedia('(hover: hover) and (pointer: fine)').matches
            && window.innerWidth > 768;
    }

    function bindRows() {
        document.querySelectorAll('.ar-row').forEach(function (row) {
            if (row._arTipBound) return;
            row._arTipBound = true;
            row.addEventListener('mousemove', function (e) {
                if (!tip || !isHoverCapable()) return;
                tip.style.left = e.clientX + 'px';
                tip.style.top  = e.clientY + 'px';
                tip.classList.add('visible');
            });
            row.addEventListener('mouseleave', function () { if (tip) tip.classList.remove('visible'); });
            row.addEventListener('click',      function () { if (tip) tip.classList.remove('visible'); });
        });
    }

    bindRows();
    document.addEventListener('livewire:updated', bindRows);

    function cleanAlumniPageParam() {
        var url = new URL(window.location.href);
        var changed = false;
        if (url.searchParams.has('alumniPage'))     { url.searchParams.delete('alumniPage');     changed = true; }
        if (url.searchParams.has('profile_filter')) { url.searchParams.delete('profile_filter'); changed = true; }
        if (url.searchParams.has('batch'))          { url.searchParams.delete('batch');          changed = true; }
        if (changed) history.replaceState(null, '', url.pathname + (url.search || ''));
    }
    cleanAlumniPageParam();
    document.addEventListener('livewire:updated', cleanAlumniPageParam);
})();
</script>