{{-- resources/views/livewire/director/dashboard.blade.php --}}

<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Renderless;
use App\Models\AdminEvent;
use App\Models\JobPosting;
use App\Models\Organizer;
use Carbon\Carbon;

new class extends Component {

    public int $totalCoordinators  = 0;
    public int $activeCoordinators = 0;
    public int $totalEvents        = 0;
    public int $pendingEvents      = 0;
    public int $approvedEvents     = 0;
    public int $completedEvents    = 0;
    public int $rejectedEvents     = 0;
    public int $totalJobs          = 0;
    public int $activeJobs         = 0;
    public int $inactiveJobs       = 0;
    public int $newJobsThisMonth   = 0;

    public string $activeModal      = '';
    public array  $modalCoords      = [];

    public string $coordSearch  = '';

    public int $coordModalPage    = 1;
    public int $coordModalSize    = 20;

    public string $greeting    = '';
    public string $currentDate = '';

    public string $directorName  = '';
    public string $directorEmail = '';
    public string $directorPhoto = '';

    public bool $editingEmail = false;
    public bool $savingEmail  = false;

    public function mount(): void
    {
        abort_unless(auth()->check() && auth()->user()->role === 'director', 403);

        $this->currentDate = now('Asia/Manila')->format('l, F j, Y');
        $hour = (int) now('Asia/Manila')->format('H');
        $this->greeting = match(true) {
            $hour < 12 => 'Good Morning',
            $hour < 17 => 'Good Afternoon',
            default    => 'Good Evening',
        };

        $dir = DB::table('director')->where('user_id', auth()->id())->first();
        $this->directorName = $dir
            ? trim(implode(' ', array_filter([$dir->first_name ?? '', $dir->middle_name ?? '', $dir->last_name ?? '', $dir->suffix ?? ''])))
            : '';
        if ($this->directorName === '') $this->directorName = auth()->user()->name ?? 'Director';

        $this->directorEmail = ($dir && !empty($dir->email)) ? $dir->email : (auth()->user()->email ?? '—');
        $this->directorPhoto = $this->photoUrl($dir->profile_photo ?? '');

        $this->loadStats();
    }

    public function photoUrl(?string $p): string
    {
        $default = asset('storage/alumni-photos/default.png');
        if (!$p || str_contains($p, 'default.png')) return $default;
        // Cloudinary / full URL — return as-is (same as Alumni Records, Manage
        // Coordinator and the Coordinator dashboard).
        if (preg_match('#^https?://#i', $p)) return $p;
        if (str_starts_with($p, 'alumni-photos/') || str_starts_with($p, 'organizers/') || str_starts_with($p, 'directors/') || str_starts_with($p, 'registrars/'))
            return Storage::disk('public')->exists($p) ? asset('storage/'.$p) : $default;
        return $default;
    }

    /**
     * ── Change own profile photo (click the banner) ─────────────────────
     * Same flow as the Coordinator dashboard / Alumni Records: the browser
     * aligns + compresses the image -> base64 -> Cloudinary. The URL is saved
     * in director.profile_photo, so User Management (Admin) shows it too.
     */
    #[Renderless]
    public function receiveDirectorPhoto(string $filename, string $base64): void
    {
        try {
            $dir = DB::table('director')->where('user_id', auth()->id())->first();
            if (!$dir) {
                $this->dispatch('dir-photo-failed', message: 'Could not find your profile.');
                return;
            }

            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || $base64 === '' || strlen($base64) > 2_800_000) {
                $this->dispatch('dir-photo-failed', message: 'Photo must be JPG, PNG, or WebP and under 2MB.');
                return;
            }

            $result = cloudinary()->uploadApi()->upload(
                'data:image/' . $ext . ';base64,' . $base64,
                [
                    'folder'        => 'director-photos',
                    'public_id'     => 'director_' . $dir->id . '_' . uniqid(),
                    'overwrite'     => true,
                    'resource_type' => 'image',
                ]
            );

            $this->deleteDirectorPhotoAsset($dir);

            $upd = ['profile_photo' => $result['secure_url'], 'updated_at' => now()];
            try {
                if (Schema::hasColumn('director', 'profile_photo_public_id')) {
                    $upd['profile_photo_public_id'] = $result['public_id'];
                }
            } catch (\Throwable $e) {
                Log::warning('Director photo public_id: ' . $e->getMessage());
            }
            DB::table('director')->where('id', $dir->id)->update($upd);

            $this->directorPhoto = $result['secure_url'];
            $this->dispatch('dir-photo-saved', newSrc: $result['secure_url']);
        } catch (\Throwable $e) {
            Log::error('Director photo upload failed: ' . $e->getMessage());
            $this->dispatch('dir-photo-failed', message: 'Failed to upload photo. Please try again.');
        }
    }

    /**
     * ── Back to default photo ───────────────────────────────────────────
     * Deletes the current custom photo (Cloudinary asset / local file) and
     * clears director.profile_photo so the default avatar is used again.
     */
    #[Renderless]
    public function resetDirectorPhoto(): void
    {
        try {
            $dir = DB::table('director')->where('user_id', auth()->id())->first();
            if (!$dir) {
                $this->dispatch('dir-photo-failed', message: 'Could not find your profile.');
                return;
            }

            $this->deleteDirectorPhotoAsset($dir);

            $upd = ['profile_photo' => null, 'updated_at' => now()];
            try {
                if (Schema::hasColumn('director', 'profile_photo_public_id')) {
                    $upd['profile_photo_public_id'] = null;
                }
            } catch (\Throwable $e) {
                Log::warning('Director photo public_id reset: ' . $e->getMessage());
            }
            DB::table('director')->where('id', $dir->id)->update($upd);

            $this->directorPhoto = asset('storage/alumni-photos/default.png');
            $this->dispatch('dir-photo-saved', newSrc: asset('storage/alumni-photos/default.png'));
        } catch (\Throwable $e) {
            Log::error('Director photo reset failed: ' . $e->getMessage());
            $this->dispatch('dir-photo-failed', message: 'Failed to reset photo. Please try again.');
        }
    }

    /** Removes the previous photo (Cloudinary asset or legacy local file). */
    private function deleteDirectorPhotoAsset(object $dir): void
    {
        $photo = $dir->profile_photo ?? null;
        if (!$photo || str_contains($photo, 'default.png')) return;

        try {
            if (preg_match('#^https?://#i', $photo)) {
                $publicId = $dir->profile_photo_public_id ?? null;
                if (!$publicId && preg_match('#/upload/(?:v\d+/)?(.+)\.[a-z0-9]+$#i', $photo, $m)) {
                    $publicId = $m[1];
                }
                if ($publicId) cloudinary()->uploadApi()->destroy($publicId);
            } elseif (Storage::disk('public')->exists($photo)) {
                Storage::disk('public')->delete($photo);
            }
        } catch (\Throwable $e) {
            Log::warning('Director photo delete failed: ' . $e->getMessage());
        }
    }

    public function startEditEmail(): void
    {
        $this->editingEmail = true;
    }

    public function cancelEditEmail(): void
    {
        $dir = DB::table('director')->where('user_id', auth()->id())->first();
        $this->directorEmail = ($dir && !empty($dir->email)) ? $dir->email : (auth()->user()->email ?? '—');
        $this->editingEmail = false;
        $this->resetErrorBag('directorEmail');
    }

    public function saveEmail(): void
    {
        $this->savingEmail = true;

        try {
            $this->validate([
                'directorEmail' => [
                    'required',
                    'email',
                    'max:255',
                    \Illuminate\Validation\Rule::unique('users', 'email')->ignore(auth()->id()),
                ],
            ]);

            DB::table('director')->where('user_id', auth()->id())
                ->update(['email' => $this->directorEmail]);

            // EMAIL ONLY — raw query-builder update on the single `email`
            // column. Deliberately NOT auth()->user()->update([...]): going
            // through the Eloquent model fires model events / observers /
            // mutators (saving, updating, password cast, notifications…)
            // that can touch the password. This bypasses all of that.
            DB::table('users')
                ->where('id', auth()->id())
                ->update(['email' => $this->directorEmail]);

            // Keep the "saving" dots on screen long enough to be seen —
            // the DB write is near-instant, so without this the loader
            // flashes and disappears before the user notices it.
            usleep(600000);

            $this->editingEmail = false;
        } finally {
            // Always reset, even if validation throws (previously the flag
            // stayed true after a validation error).
            $this->savingEmail = false;
        }
    }

    private function loadStats(): void
    {
        $this->totalCoordinators  = Organizer::withoutTrashed()->count();
        $this->activeCoordinators = Organizer::withoutTrashed()->where('status', 'ACTIVE')->count();

        $this->totalEvents     = AdminEvent::withTrashed()->count();
        $this->pendingEvents   = AdminEvent::withoutTrashed()->where('status', 'PENDING')->count();
        $this->approvedEvents  = AdminEvent::withoutTrashed()->where('status', 'APPROVED')->count();
        $this->completedEvents = AdminEvent::withoutTrashed()->where('status', 'COMPLETED')->count();
        $this->rejectedEvents  = AdminEvent::withoutTrashed()->where('status', 'REJECTED')->count();

        $this->totalJobs    = JobPosting::whereNotIn('status', ['ADMIN_DELETED'])->count();
        $this->activeJobs   = JobPosting::where('status', 'ACTIVE')->count();
        $this->inactiveJobs = JobPosting::where('status', 'INACTIVE')->count();

        $monthStart = now('Asia/Manila')->startOfMonth()->utc();
        $this->newJobsThisMonth = JobPosting::where('created_at', '>=', $monthStart)
            ->whereNotIn('status', ['ADMIN_DELETED'])->count();
    }

    protected function buildCoordRows(string $status = ''): array
    {
        $q = Organizer::withoutTrashed();
        if ($status) $q->where('status', $status);
        return $q->orderBy('name')
            ->get(['id', 'name', 'email', 'department', 'status', 'created_at'])
            ->map(fn($o) => [
                'id'         => $o->id,
                'name'       => strtoupper($o->name),
                'email'      => $o->email,
                'department' => $o->department ?? '—',
                'status'     => $o->status,
                'created_at' => $o->created_at->format('M d, Y'),
            ])->toArray();
    }

    public function openCoordsModal(string $status = ''): void
    {
        $this->modalCoords    = $this->buildCoordRows($status);
        $this->coordSearch    = '';
        $this->coordModalPage = 1;
        $this->activeModal    = 'coords';
    }

    public function closeModal(): void { $this->activeModal = ''; }

    /**
     * Sends the director to the coordinator management page with the
     * "Active" status filter pre-applied. We use the session (instead of
     * a route/query parameter) so the URL stays clean:
     *   /director/coordinator/management
     * The management page's mount() pulls this value once, applies it
     * to the filter, then clears it — so a plain refresh afterwards goes
     * back to showing all statuses, which is expected.
     */
    public function goToActiveCoordinators()
    {
        session()->put('director_coord_status', 'ACTIVE');
        return $this->redirect(route('director.coordinator/management'), navigate: true);
    }

    // ─────────────────────────────────────────────────────────────────────
    // NEW: Job stat card / mini-tile clicks now navigate straight to the
    // Job Management page (director.job/management) instead of opening a
    // modal on the dashboard — same clean-URL + session pattern already
    // used above for goToActiveCoordinators().
    //
    // 'director_job_status' is read once in manage-job.blade.php's mount()
    // via session()->pull(), applied to $filterStatus, then cleared — so a
    // plain refresh of the job management page afterwards goes back to
    // showing every status (ACTIVE + INACTIVE + ORGANIZER_DELETED), same
    // as normal. This mirrors the existing coordinator-filter pattern.
    //
    //   goToAllJobs()      -> Total Jobs card      -> no filter (all)
    //   goToActiveJobs()   -> Active mini-tile      -> filterStatus=ACTIVE
    //   goToInactiveJobs() -> Inactive mini-tile    -> filterStatus=INACTIVE
    // ─────────────────────────────────────────────────────────────────────
    public function goToAllJobs()
    {
        session()->put('director_job_status', '');
        return $this->redirect(route('director.job/management'), navigate: true);
    }

    public function goToActiveJobs()
    {
        session()->put('director_job_status', 'ACTIVE');
        return $this->redirect(route('director.job/management'), navigate: true);
    }

    public function goToInactiveJobs()
    {
        session()->put('director_job_status', 'INACTIVE');
        return $this->redirect(route('director.job/management'), navigate: true);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Event stat card / mini-tile clicks — same clean-URL + session pattern
    // as goToAllJobs()/goToActiveJobs() above. Previously these links built
    // a "?status=pending" query string straight into the event management
    // URL; now the status is stashed in the session and pulled once by
    // manage-event.blade.php's mount(), so the address bar always lands on
    // the plain /director/event/management URL, filter still applied.
    //
    //   goToAllEvents()       -> Total Events card / "Manage" link -> no filter
    //   goToPendingEvents()   -> Pending Events card / mini-tile   -> PENDING
    //   goToApprovedEvents()  -> Approved mini-tile                -> APPROVED
    //   goToCompletedEvents() -> Completed mini-tile               -> COMPLETED
    //   goToRejectedEvents()  -> Rejected mini-tile                -> REJECTED
    // ─────────────────────────────────────────────────────────────────────
    public function goToAllEvents()
    {
        session()->put('director_event_status', '');
        return $this->redirect(route('director.event/management'), navigate: true);
    }

    public function goToPendingEvents()
    {
        session()->put('director_event_status', 'pending');
        return $this->redirect(route('director.event/management'), navigate: true);
    }

    public function goToApprovedEvents()
    {
        session()->put('director_event_status', 'approved');
        return $this->redirect(route('director.event/management'), navigate: true);
    }

    public function goToCompletedEvents()
    {
        session()->put('director_event_status', 'completed');
        return $this->redirect(route('director.event/management'), navigate: true);
    }

    public function goToRejectedEvents()
    {
        session()->put('director_event_status', 'rejected');
        return $this->redirect(route('director.event/management'), navigate: true);
    }

    public function updatingCoordSearch(): void { $this->coordModalPage = 1; }

    public function coordPrevPage(): void { if ($this->coordModalPage > 1) $this->coordModalPage--; }
    public function coordNextPage(int $last): void { if ($this->coordModalPage < $last) $this->coordModalPage++; }
};
?>

<div
    id="dir-dashboard-root"
    class="px-3 sm:px-5 lg:px-6 pt-4 pb-6 max-w-screen-2xl mx-auto w-full"
>

{{-- ── Navigation blocker ────────────────────────────────────────────
     Invisible fixed overlay placed over the entire page while a stat /
     mini-card is navigating (spinner active). Blocks ALL clicks and
     shows cursor:default everywhere so the user cannot accidentally
     start a second navigation mid-flight. Sits below the coordinator
     modal (z-[9999]) so the modal itself stays interactive when open.
     Cleared by clearAllDirCardSpinners() once livewire:navigated fires. ── --}}
<div id="dir-nav-blocker"
     style="display:none; position:fixed; inset:0; z-index:800;
            background:transparent; pointer-events:all; cursor:default;"
     onclick="return false;"
     oncontextmenu="return false;"></div>

<style>
/* ── Disable text selection/copy across the whole dashboard ──
   Covers stat numbers, labels, table rows, chips, account info,
   everything. Buttons/links/inputs still work fine since this
   only blocks text selection, not clicks. ── */
#dir-dashboard-root,
#dir-dashboard-root * {
    -webkit-user-select: none;
    -moz-user-select: none;
    -ms-user-select: none;
    user-select: none;
}

/* ── Stat card tooltip (desktop only — no tooltip text on mobile) ── */
.dir-stat-card { position: relative; overflow: visible; }
.dir-stat-card .dir-card-tip {
    position: absolute; bottom: calc(100% + 8px); left: 50%; transform: translateX(-50%);
    background: #000; color: #fff; font-size: 10px; font-weight: 700; letter-spacing: 0.05em;
    padding: 5px 11px; border-radius: 7px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity 0.15s; z-index: 9999;
}
.dir-stat-card .dir-card-tip::after {
    content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%);
    border: 5px solid transparent; border-top-color: #000;
}
@media (min-width: 1024px) {
    .dir-stat-card:hover .dir-card-tip { opacity: 1; }
}
@media (max-width: 1023px) {
    .dir-stat-card .dir-card-tip { display: none !important; }
}

/* ── Mini cards (clickable stat tiles) ── */
.dir-mini-card { position: relative; overflow: visible; cursor: pointer; transition: transform .12s ease, box-shadow .15s ease; }
.dir-mini-card:hover { transform: translateY(-2px); box-shadow: 0 4px 16px rgba(0,0,0,.10); }
.dir-mini-card:active { transform: scale(.97); }
.dir-mini-card .dir-mini-tip {
    position: absolute; bottom: calc(100% + 7px); left: 50%; transform: translateX(-50%);
    background: #000; color: #fff; font-size: 9px; font-weight: 700; letter-spacing: 0.05em;
    padding: 4px 10px; border-radius: 6px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity 0.15s; z-index: 9999;
}
.dir-mini-card .dir-mini-tip::after {
    content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%);
    border: 4px solid transparent; border-top-color: #000;
}
@media (min-width: 1024px) {
    .dir-mini-card:hover .dir-mini-tip { opacity: 1; }
}
@media (max-width: 1023px) {
    .dir-mini-card .dir-mini-tip { display: none !important; }
}

/* Keep Alpine x-cloak elements (photo action bar / overlays) hidden until Alpine boots */
[x-cloak] { display: none !important; }

/* ── Profile photo: hidden tooltip, shows only on hover (desktop) ── */
.dir-photo-pick { display: block; }
.dir-photo-pick .dir-photo-tip {
    position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
    background: #000; color: #fff; font-size: 10px; font-weight: 700; letter-spacing: 0.05em;
    padding: 5px 11px; border-radius: 7px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity 0.15s; z-index: 9999;
}
@media (min-width: 1024px) {
    .dir-photo-pick:hover .dir-photo-tip { opacity: 1; }
}
@media (max-width: 1023px) {
    .dir-photo-pick .dir-photo-tip { display: none !important; }
}

/* ── Dashboard card click spinner ───────────────────────────
   Purple "..." dot loader — same loading interface used on the
   Alumni Dashboard, applied here for consistency. Card content
   blurs + dims underneath instead of being fully covered, so it
   still reads as "this card is busy" not an empty gap. Uses plain
   CSS dots instead of an icon font glyph so the color is never at
   the mercy of icon-font fallback rendering. */
.dir-card-clickable { position: relative; }
.dir-card-clickable.is-loading > *:not(.dir-card-spinner) {
    filter: blur(4px);
    opacity: 0.5;
    pointer-events: none;
    user-select: none;
}
.dir-card-spinner {
    position: absolute;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    gap: 6px;
    z-index: 40;
}
.dir-card-spinner span {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #7A3F91;
    animation: dirDotPulse 1.1s ease-in-out infinite;
}
.dir-card-spinner span:nth-child(2) { animation-delay: 0.15s; }
.dir-card-spinner span:nth-child(3) { animation-delay: 0.3s; }
.dir-card-spinner--sm span {
    width: 5px;
    height: 5px;
}
@keyframes dirDotPulse {
    0%, 80%, 100% { transform: scale(0.6); opacity: 0.4; }
    40% { transform: scale(1); opacity: 1; }
}
.dir-card-clickable.is-loading .dir-card-spinner {
    display: flex;
}
.dir-card-clickable.is-loading {
    pointer-events: none;
}

/* ── Block all other cards while one is navigating ──
   When .dir-nav-active is on the root, every nav button
   that is NOT the loading one gets default cursor + no hover
   lift/shadow, so it's clear only one action is in flight. ── */
#dir-dashboard-root.dir-nav-active .dir-card-nav-btn:not(.is-loading) {
    pointer-events: none !important;
    cursor: default !important;
    transform: none !important;
    box-shadow: none !important;
}
#dir-dashboard-root.dir-nav-active .dir-card-nav-btn:not(.is-loading):hover {
    transform: none !important;
    box-shadow: none !important;
}

/* ── Main grid ── */
.dir-main-grid { display: grid; grid-template-columns: 300px 1fr; gap: 1rem; align-items: start; }
/* Desktop: nudge the profile/stat cards down a bit so the block sits
   visually centered under the greeting (greeting itself is untouched). */
@media (min-width: 1024px) {
    .dir-main-grid { margin-top: clamp(0.75rem, 7vh, 4.5rem); }
}
@media (max-width: 1023px) {
    .dir-main-grid { grid-template-columns: 1fr; gap: 0.85rem; }
}

.dir-account-col { display: flex; flex-direction: column; }
.dir-account-card { display: flex; flex-direction: column; }

.dir-right-col { display: flex; flex-direction: column; gap: 1rem; }

.dir-stat-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; }
.dir-stat-grid .dir-stat-card { display: flex; flex-direction: column; justify-content: center; }
@media (max-width: 639px) {
    .dir-stat-grid { grid-template-columns: 1fr; gap: 0.65rem; }
    .dir-stat-grid .dir-stat-card { padding: 1rem !important; }
    .dir-stat-grid .dir-stat-card .dir-stat-num { font-size: 2.1rem !important; }
}
@media (min-width: 640px) and (max-width: 1023px) {
    .dir-stat-grid .dir-stat-card .dir-stat-num { font-size: 2.4rem !important; }
}

.dir-info-body { display: flex; flex-direction: column; }
.dir-info-row {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0.6rem 1rem; border-bottom: 1px solid #EDE0F5; gap: 0.5rem;
}
.dir-info-row:last-child { border-bottom: none; }
.dir-info-label { font-size: 0.70rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; color: #333333; flex-shrink: 0; }
.dir-info-value { font-size: 0.875rem; font-weight: 600; color: #111111; text-align: right; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 160px; }
.dir-info-value-sm { font-size: 0.80rem; font-weight: 600; color: #111111; text-align: right; word-break: break-all; max-width: 160px; }

.dir-chips-section { padding: 0.65rem 1rem; }
.dir-chips-label { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em; color: #333333; margin-bottom: 0.4rem; }

/* ── Update Email save button dot loader — same purple "..." loading
   interface used across the dashboard cards, applied here too. ── */
.dir-email-save-btn { min-width: 62px; }
.dir-email-save-spinner { z-index: 5; }
.dir-email-save-dot {
    display: inline-block;
    flex-shrink: 0;
    width: 5px;
    height: 5px;
    border-radius: 50%;
    background: #ffffff;
    animation: dirEmailSaveDotPulse 1.1s ease-in-out infinite;
}
.dir-email-save-dot:nth-child(2) { animation-delay: 0.15s; }
.dir-email-save-dot:nth-child(3) { animation-delay: 0.3s; }
@keyframes dirEmailSaveDotPulse {
    0%, 80%, 100% { transform: scale(0.6); opacity: 0.4; }
    40% { transform: scale(1); opacity: 1; }
}

.dir-chip {
    font-size: 0.72rem; font-weight: 700; padding: 3px 10px; border-radius: 999px;
    display: inline-flex; align-items: center; gap: 4px;
    border: 1px solid transparent; margin: 2px 4px 2px 0;
}
.dir-chip i { font-size: 7px; }

.dir-chip-approved  { background: #E8F8F0; color: #0F7A4E; border-color: #BEEBD4; }
.dir-chip-pending   { background: #FEF6E7; color: #B5750A; border-color: #FBE4B4; }
.dir-chip-completed { background: #EAF1FE; color: #1D4ED8; border-color: #C9DBFC; }
.dir-chip-rejected  { background: #FDECEC; color: #C0311A; border-color: #F8C9C2; }

.dir-chip-active   { background: #E8F8F0; color: #0F7A4E; border-color: #BEEBD4; }
.dir-chip-inactive { background: #F1F1F3; color: #52525B; border-color: #E1E1E5; }

.dir-chip-coord-active   { background: #E8F8F0; color: #0F7A4E; border-color: #BEEBD4; }
.dir-chip-coord-inactive { background: #F1F1F3; color: #52525B; border-color: #E1E1E5; }

.dir-scroll { scrollbar-width: thin; scrollbar-color: #d4b8e8 #f9f7fc; }
.dir-scroll::-webkit-scrollbar { width: 4px; }
.dir-scroll::-webkit-scrollbar-thumb { background: #d4b8e8; border-radius: 99px; }

.dir-mini-tile { padding: 0.45rem 0.6rem !important; }
.dir-mini-tile .dir-mini-num { font-size: 1.1rem !important; line-height: 1 !important; }
.dir-mini-tile .dir-mini-label { font-size: 0.6rem !important; margin-top: 0.15rem !important; }

.dir-close-btn {
    display: flex; align-items: center; justify-content: center; gap: 6px;
    padding: 6px 16px; border-radius: 10px; background: rgba(255,255,255,.12);
    border: 1px solid rgba(255,255,255,.2); color: #fff; font-size: .875rem;
    font-weight: 600; cursor: pointer; transition: background .15s;
}
.dir-close-btn:hover { background: rgba(255,255,255,.22); }

.dir-pg-btn {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 32px; height: 32px; padding: 0 10px; border-radius: 8px;
    font-size: .75rem; font-weight: 700; transition: all .15s; border: 1.5px solid transparent;
}
.dir-pg-active { background: #fff; color: #7A3F91; border-color: #fff; }
.dir-pg-nav { background: rgba(255,255,255,.15); color: #fff; border-color: rgba(255,255,255,.25); }
.dir-pg-nav:hover:not(:disabled) { background: rgba(255,255,255,.28); border-color: rgba(255,255,255,.5); }
.dir-pg-nav:disabled { opacity: .35; cursor: not-allowed; }

.dir-table-row { transition: background .10s; }
.dir-table-row:hover { background: #F5F0FA !important; }

@keyframes dirModalIn { from { opacity:0; transform: translateY(8px); } to { opacity:1; transform: translateY(0); } }
.dir-modal-enter { animation: dirModalIn .2s cubic-bezier(.4,0,.2,1) both; }

@keyframes dirFadeUp { from { opacity:0; transform:translateY(14px) } to { opacity:1; transform:none } }
.dir-fade-up { animation: dirFadeUp .4s cubic-bezier(.25,.8,.25,1) both; }
.dir-fade-1 { animation-delay:.04s } .dir-fade-2 { animation-delay:.08s }
.dir-fade-3 { animation-delay:.12s } .dir-fade-4 { animation-delay:.16s }
</style>

{{-- ── PAGE HEADER ── --}}
<div class="flex items-center gap-3 mb-5 dir-fade-up">
    <div class="w-11 h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
        <i class="fas fa-gauge-high text-white text-base"></i>
    </div>
    <div>
        <h1 class="text-2xl font-semibold text-[#111111] leading-tight">
            {{ $greeting }}, Director
        </h1>
        <p class="text-sm text-[#7A3F91] font-normal flex flex-wrap items-center gap-x-1.5">
            <i class="fas fa-circle text-[5px] text-emerald-500 align-middle"></i>
            <span>{{ $currentDate }}</span>
        </p>
    </div>
</div>

{{-- ══ MAIN GRID ══ --}}
<div class="dir-main-grid">

    {{-- ══ LEFT: Director Account Card ══ --}}
    <div class="dir-account-col dir-fade-up">
        <div class="dir-account-card rounded-xl overflow-hidden border border-[#E8E0F0] shadow-sm bg-white">

            <div class="shrink-0"
                 x-data="{
                     previewSrc: @js($directorPhoto),
                     originalSrc: @js($directorPhoto),
                     defaultSrc: @js(asset('storage/alumni-photos/default.png')),
                     pendingFile: null,
                     hasFile: false,
                     resetPending: false,
                     aligning: false,
                     alignInfo: '',
                     saving: false,
                     msg: '',
                     msgType: '',
                     _t: null,
                     init() {
                         $wire.$on('dir-photo-saved', (event) => {
                             const e = Array.isArray(event) ? event[0] : event;
                             const wasReset = this.resetPending;
                             this.pendingFile = null; this.hasFile = false; this.resetPending = false; this.saving = false; this.alignInfo = '';
                             if (this.$refs.photoInput) this.$refs.photoInput.value = '';
                             if (e && e.newSrc) this.previewSrc = e.newSrc + '?t=' + Date.now();
                             this.originalSrc = this.previewSrc;
                             this.notify(wasReset ? 'Photo reset to default' : 'Profile photo updated', 'ok');
                         });
                         $wire.$on('dir-photo-failed', (event) => {
                             const e = Array.isArray(event) ? event[0] : event;
                             this.failCleanup();
                             this.notify((e && e.message) || 'Failed to upload photo.', 'err');
                         });
                     },
                     notify(m, t) {
                         this.msg = m; this.msgType = t;
                         clearTimeout(this._t);
                         this._t = setTimeout(() => this.msg = '', 3500);
                     },
                     failCleanup() {
                         this.saving = false; this.aligning = false; this.hasFile = false; this.resetPending = false; this.pendingFile = null; this.alignInfo = '';
                         this.previewSrc = this.originalSrc;
                         if (this.$refs.photoInput) this.$refs.photoInput.value = '';
                     },
                     warmUp() {
                         if (window.dirPhotoFace) window.dirPhotoFace.warmUp();
                     },
                     async onFileChange(event) {
                         const file = event.target.files[0];
                         if (!file) return;
                         if (file.size > 8 * 1024 * 1024) { this.notify('Photo is too large (max 8MB).', 'err'); event.target.value = ''; return; }
                         if (!window.dirPhotoFace) { this.notify('Photo tools are still loading. Please try again.', 'err'); event.target.value = ''; return; }
                         this.aligning = true;
                         try {
                             // Detects the face, crops a square that frames it for the card,
                             // and returns a compressed JPEG data URL (what you preview = what gets saved).
                             const out = await window.dirPhotoFace.process(file, 600, 0.85);
                             this.pendingFile = { name: file.name.replace(/\.\w+$/, '') + '.jpg', base64: out.dataUrl.split(',')[1] };
                             this.previewSrc = out.dataUrl;
                             this.alignInfo = out.faceFound ? 'face' : 'fallback';
                             this.resetPending = false;
                             this.hasFile = true;
                         } catch (err) {
                             this.notify('Could not read that image.', 'err');
                             event.target.value = '';
                         } finally {
                             this.aligning = false;
                         }
                     },
                     savePhoto() {
                         if (this.saving || (!this.pendingFile && !this.resetPending)) return;
                         this.saving = true;
                         const call = this.resetPending
                             ? $wire.resetDirectorPhoto()
                             : $wire.receiveDirectorPhoto(this.pendingFile.name, this.pendingFile.base64);
                         call.catch(() => { this.failCleanup(); this.notify('Failed to save photo.', 'err'); });
                     },
                     useDefault() {
                         this.pendingFile = null; this.resetPending = true; this.hasFile = true; this.alignInfo = '';
                         this.previewSrc = this.defaultSrc;
                         if (this.$refs.photoInput) this.$refs.photoInput.value = '';
                     },
                     cancelPhoto() {
                         this.pendingFile = null; this.hasFile = false; this.resetPending = false; this.alignInfo = '';
                         this.previewSrc = this.originalSrc;
                         if (this.$refs.photoInput) this.$refs.photoInput.value = '';
                     }
                 }">

                <div class="relative w-full overflow-hidden h-[420px] sm:h-[270px]"
                     style="background:linear-gradient(135deg,#7A3F91,#9b59b6);"
                     @pointerenter.once="warmUp()">
                    <div x-show="previewSrc.includes('default.png')"
                         class="w-full h-full flex items-center justify-center font-black text-white text-[4.5rem] sm:text-[3.6rem]"
                         style="background:rgba(255,255,255,0.16);">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <img x-show="!previewSrc.includes('default.png')" :src="previewSrc"
                         alt="{{ $directorName }}"
                         draggable="false"
                         class="w-full h-full object-cover object-top"
                         onerror="this.onerror=null; this.src='{{ asset('storage/alumni-photos/default.png') }}';">
                    <div class="absolute inset-0 pointer-events-none" style="background:linear-gradient(to bottom, transparent 35%, rgba(0,0,0,.55) 100%);"></div>

                    {{-- Click anywhere on the photo to pick a new one.
                         No visible text — only a tooltip on hover (desktop). --}}
                    <label x-show="!hasFile && !saving && !aligning"
                           class="dir-photo-pick absolute inset-0 z-10 cursor-pointer bg-black/0 hover:bg-black/20 transition-colors">
                        <span class="dir-photo-tip"><i class="fas fa-camera mr-1.5"></i>Click to change photo</span>
                        <input type="file" x-ref="photoInput" class="hidden"
                               accept="image/jpeg,image/png,image/webp" @change="onFileChange($event)">
                    </label>

                    {{-- Detecting the face / aligning the photo to the card --}}
                    <div x-show="aligning" x-cloak
                         class="absolute inset-0 z-20 flex flex-col items-center justify-center gap-2 bg-black/50">
                        <i class="fas fa-spinner fa-spin text-white text-2xl"></i>
                        <span class="text-white text-[0.72rem] font-bold tracking-wide">Aligning face…</span>
                    </div>

                    {{-- Saving overlay --}}
                    <div x-show="saving" x-cloak class="absolute inset-0 z-20 flex flex-col items-center justify-center gap-2 bg-black/50">
                        <i class="fas fa-spinner fa-spin text-white text-2xl"></i>
                        <span class="text-white text-[0.72rem] font-bold tracking-wide">Saving…</span>
                    </div>

                    {{-- Result message --}}
                    <div x-show="msg" x-cloak x-transition
                         class="absolute top-3 left-3 right-3 z-20 w-fit max-w-full px-2.5 py-1 rounded-full text-[0.68rem] font-bold shadow"
                         :class="msgType === 'ok' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-red-50 text-red-700 border border-red-200'"
                         x-text="msg"></div>

                    <div class="absolute bottom-0 left-0 right-0 px-4 pb-4 pointer-events-none z-[11]">
                        <p class="text-white font-bold uppercase leading-tight tracking-wide text-[1.1rem] sm:text-[1.15rem]"
                           style="text-shadow:0 1px 5px rgba(0,0,0,.6);">
                            {{ $directorName ?: 'Director' }}
                        </p>
                    </div>
                </div>

                {{-- After picking a photo: status line + Save / Cancel / Default photo --}}
                <div x-show="hasFile && !saving && !aligning" x-cloak x-transition
                     class="px-3 py-2.5 bg-[#FAF6FD]" style="border-bottom:1px solid #E5E7EB;">
                    <p x-show="resetPending" class="flex items-start gap-1.5 text-[0.72rem] font-semibold text-[#333333] leading-snug mb-2">
                        <template x-if="resetPending">
                            <span class="flex items-start gap-1.5"><i class="fas fa-rotate-left text-[#7A3F91] mt-[2px]"></i><span>This will reset your photo to the default avatar.</span></span>
                        </template>
                    </p>
                    <div class="flex flex-wrap gap-1.5">
                        <button type="button" @click="savePhoto()"
                                class="flex-1 min-w-[84px] inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg text-[0.75rem] font-bold text-white shadow-sm active:scale-95 transition"
                                style="background:#7A3F91;">
                            <i class="fas fa-check text-[10px]"></i> Save photo
                        </button>
                        <button type="button" @click="cancelPhoto()"
                                class="flex-1 min-w-[84px] inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg text-[0.75rem] font-bold bg-white text-[#333333] border border-[#E8E0F0] hover:bg-gray-50 active:scale-95 transition">
                            <i class="fas fa-xmark text-[10px]"></i> Cancel
                        </button>
                        <button type="button" x-show="!resetPending" @click="useDefault()"
                                class="flex-1 min-w-[104px] inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-lg text-[0.75rem] font-bold bg-white text-[#7A3F91] border border-[#D8B4FE] hover:bg-[#F9F5FC] active:scale-95 transition">
                            <i class="fas fa-user text-[10px]"></i> Default photo
                        </button>
                    </div>
                </div>
            </div>


            <div class="dir-info-body dir-scroll">

                <div class="dir-info-row">
                    <span class="dir-info-label">Name</span>
                    <span class="dir-info-value">{{ $directorName ?: 'Director' }}</span>
                </div>

                <div class="dir-info-row" wire:key="dir-email-row">
                    @if(!$editingEmail)
                        <span class="dir-info-label">Email</span>
                        <span class="flex items-center gap-2 min-w-0">
                            <span class="dir-info-value" style="max-width:220px;" title="{{ $directorEmail }}">{{ $directorEmail }}</span>
                            <button type="button"
                                    wire:click="startEditEmail"
                                    class="dir-email-edit-btn inline-flex items-center gap-1 px-2 py-1 rounded-md
                                           border border-[#E8E0F0] bg-[#F9F7FC] text-[#7A3F91] font-semibold
                                           hover:bg-[#F0E8F7] hover:border-[#7A3F91]/40 transition-all duration-150
                                           active:scale-[.97] cursor-pointer shrink-0"
                                    style="font-size:10.5px;">
                                <i class="fas fa-pen" style="font-size:8.5px;"></i>
                                Update
                            </button>
                        </span>
                    @else
                        <div class="w-full">
                            <input type="email"
                                   wire:model="directorEmail"
                                   wire:keydown.enter="saveEmail"
                                   {{ $savingEmail ? 'disabled' : '' }}
                                   autofocus
                                   class="w-full px-2.5 py-1.5 rounded-lg border font-bold text-[#111111]
                                          focus:outline-none focus:ring-2 transition-all duration-150
                                          {{ $errors->has('directorEmail') ? 'border-red-400 focus:ring-red-200' : 'border-[#7A3F91]/50 focus:ring-[#7A3F91]/20' }}"
                                   style="font-size:12.5px;">

                            @error('directorEmail')
                                <p class="text-red-600 font-semibold mt-1" style="font-size:10.5px;">{{ $message }}</p>
                            @enderror

                            <div class="flex items-center justify-end gap-1.5 mt-1.5">
                                <button type="button"
                                        wire:click="cancelEditEmail"
                                        {{ $savingEmail ? 'disabled' : '' }}
                                        class="inline-flex items-center gap-1 px-2 py-1 rounded-md
                                               border border-[#E8E0F0] bg-white text-[#333333] font-semibold
                                               hover:bg-[#F5F5F5] transition-all duration-150
                                               active:scale-[.97] cursor-pointer disabled:opacity-50 disabled:pointer-events-none"
                                        style="font-size:10.5px;">
                                    <i class="fas fa-xmark" style="font-size:8.5px;"></i>
                                    Cancel
                                </button>

                                <button type="button"
                                        wire:click="saveEmail"
                                        wire:loading.attr="disabled"
                                        wire:target="saveEmail"
                                        class="dir-email-save-btn relative inline-flex items-center gap-1 px-2 py-1 rounded-md
                                               text-white font-semibold bg-[#7A3F91]
                                               hover:opacity-90 transition-all duration-150
                                               active:scale-[.97] cursor-pointer disabled:opacity-70 disabled:pointer-events-none"
                                        style="font-size:10.5px;">

                                    {{-- Purple "..." dot loader — shown while wire:click="saveEmail" is in flight --}}
                                    <span wire:loading.flex wire:target="saveEmail"
                                          class="dir-email-save-spinner absolute inset-0 flex items-center justify-center gap-1 rounded-md bg-[#7A3F91]">
                                        <span class="dir-email-save-dot"></span>
                                        <span class="dir-email-save-dot"></span>
                                        <span class="dir-email-save-dot"></span>
                                    </span>

                                    <span wire:loading.remove wire:target="saveEmail" class="inline-flex items-center gap-1">
                                        <i class="fas fa-check" style="font-size:8.5px;"></i>
                                        Save
                                    </span>
                                </button>
                            </div>
                        </div>
                    @endif
                </div>

                <div class="dir-info-row">
                    <span class="dir-info-label">Role</span>
                    <span class="dir-info-value text-[#7A3F91] font-bold">Director</span>
                </div>

                <div class="dir-info-row">
                    <span class="dir-info-label">Active Coordinators</span>
                    <span class="dir-info-value">
                        {{ $activeCoordinators }}
                        <span class="text-[#333333] font-normal text-xs ml-1">/ {{ $totalCoordinators }}</span>
                    </span>
                </div>

                <div class="dir-chips-section">
                    <p class="dir-chips-label">Events Overview</p>
                    <div>
                        <span class="dir-chip dir-chip-approved"><i class="fas fa-circle"></i>Approved · {{ $approvedEvents }}</span>
                        <span class="dir-chip dir-chip-pending"><i class="fas fa-circle"></i>Pending · {{ $pendingEvents }}</span>
                        <span class="dir-chip dir-chip-completed"><i class="fas fa-circle"></i>Completed · {{ $completedEvents }}</span>
                        @if($rejectedEvents > 0)
                            <span class="dir-chip dir-chip-rejected"><i class="fas fa-circle"></i>Rejected · {{ $rejectedEvents }}</span>
                        @endif
                    </div>
                </div>

                <div class="dir-chips-section">
                    <p class="dir-chips-label">Job Postings</p>
                    <div>
                        <span class="dir-chip dir-chip-active">
                            <i class="fas fa-circle"></i>Active · {{ $activeJobs }}
                            @if($newJobsThisMonth > 0)
                                <span class="font-normal">(+{{ $newJobsThisMonth }} this month)</span>
                            @endif
                        </span>
                        <span class="dir-chip dir-chip-inactive"><i class="fas fa-circle"></i>Inactive · {{ $inactiveJobs }}</span>
                    </div>
                </div>

            </div>
        </div>
    </div>

    {{-- ══ RIGHT: Stats + Breakdown Panels ══ --}}
    <div class="dir-right-col">

        {{-- 2x2 Stat Cards --}}
        <div class="dir-stat-grid dir-fade-up dir-fade-1">

            {{-- Active Coordinators — clean URL, filter pre-set via session --}}
            <button type="button" wire:click="goToActiveCoordinators"
               class="dir-stat-card dir-card-clickable dir-card-nav-btn bg-white rounded-xl border border-[#E8E0F0] shadow-sm p-5
                      hover:shadow-md hover:border-[#7A3F91]/40 transition-all duration-200
                      active:scale-[.985] cursor-pointer block text-left w-full">
                <div class="dir-card-spinner"><span></span><span></span><span></span></div>
                <span class="dir-card-tip"><i class="fas fa-eye mr-1.5"></i>View Active Coordinators</span>
                <div class="flex items-start justify-between mb-3 sm:mb-4">
                    <div class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center shadow"
                         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                        <i class="fas fa-user-tie text-white text-base sm:text-lg"></i>
                    </div>
                    <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-[#333333]
                                 border border-[#E8E0F0] bg-[#F9F7FC] text-[0.7rem] sm:text-[0.75rem]">Coordinators</span>
                </div>
                <p class="dir-stat-num text-[#111111] font-extrabold leading-none tracking-tight text-[2.6rem] sm:text-[3rem]">{{ number_format($activeCoordinators) }}</p>
                <p class="text-[#111111] font-semibold mt-2 text-[0.98rem] sm:text-[1.05rem]">Active Coordinators</p>
                <p class="font-semibold mt-1 flex items-center gap-1 text-[0.85rem]" style="color:#7A3F91;">
                    <i class="fas fa-users text-xs"></i> {{ $totalCoordinators }} total
                    @if(($totalCoordinators - $activeCoordinators) > 0)
                        <span class="text-[#333333] font-normal">· {{ $totalCoordinators - $activeCoordinators }} inactive</span>
                    @endif
                </p>
            </button>

            {{-- Total Events — clean URL: /director/event/management (no status segment) --}}
            <button type="button" wire:click="goToAllEvents"
               class="dir-stat-card dir-card-clickable dir-card-nav-btn bg-white rounded-xl border border-[#E8E0F0] shadow-sm p-5
                      hover:shadow-md hover:border-emerald-300 transition-all duration-200
                      active:scale-[.985] cursor-pointer block text-left w-full">
                <div class="dir-card-spinner"><span></span><span></span><span></span></div>
                <span class="dir-card-tip"><i class="fas fa-eye mr-1.5"></i>View All Events</span>
                <div class="flex items-start justify-between mb-3 sm:mb-4">
                    <div class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center shadow bg-emerald-600">
                        <i class="fas fa-calendar-days text-white text-base sm:text-lg"></i>
                    </div>
                    <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-emerald-700
                                 border border-emerald-200 bg-emerald-50 text-[0.7rem] sm:text-[0.75rem]">Events</span>
                </div>
                <p class="dir-stat-num text-[#111111] font-extrabold leading-none tracking-tight text-[2.6rem] sm:text-[3rem]">{{ number_format($totalEvents) }}</p>
                <p class="text-[#111111] font-semibold mt-2 text-[0.98rem] sm:text-[1.05rem]">Total Events</p>
                @if($approvedEvents > 0)
                    <p class="text-emerald-600 font-semibold mt-1 flex items-center gap-1 text-[0.85rem]">
                        <i class="fas fa-circle-check text-xs"></i> {{ $approvedEvents }} Approved
                    </p>
                @else
                    <p class="text-[#333333] font-normal mt-1 text-[0.85rem]">No approved events yet</p>
                @endif
            </button>

            {{-- Pending Events — clean URL: /director/event/management (session-based filter) --}}
            <button type="button" wire:click="goToPendingEvents"
               class="dir-stat-card dir-card-clickable dir-card-nav-btn bg-white rounded-xl border border-[#E8E0F0] shadow-sm p-5
                      hover:shadow-md hover:border-amber-300 transition-all duration-200
                      active:scale-[.985] cursor-pointer block text-left w-full">
                <div class="dir-card-spinner"><span></span><span></span><span></span></div>
                <span class="dir-card-tip"><i class="fas fa-eye mr-1.5"></i>View Pending Events</span>
                <div class="flex items-start justify-between mb-3 sm:mb-4">
                    <div class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center shadow bg-amber-500">
                        <i class="fas fa-hourglass-end text-white text-base sm:text-lg"></i>
                    </div>
                    <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-amber-700
                                 border border-amber-200 bg-amber-50 text-[0.7rem] sm:text-[0.75rem]">Pending</span>
                </div>
                <p class="dir-stat-num text-amber-600 font-extrabold leading-none tracking-tight text-[2.6rem] sm:text-[3rem]">{{ number_format($pendingEvents) }}</p>
                <p class="text-[#111111] font-semibold mt-2 text-[0.98rem] sm:text-[1.05rem]">Pending Events</p>
                @if($pendingEvents > 0)
                    <p class="text-amber-600 font-semibold mt-1 flex items-center gap-1 text-[0.85rem]">
                        <i class="fas fa-circle-exclamation text-xs"></i> Needs attention
                    </p>
                @else
                    <p class="text-[#333333] font-normal mt-1 text-[0.85rem]">All clear</p>
                @endif
            </button>

            {{-- Job Postings — clean URL, filter pre-set via session (same pattern as Active Coordinators) --}}
            <button type="button" wire:click="goToAllJobs"
                    class="dir-stat-card dir-card-clickable dir-card-nav-btn bg-white rounded-xl border border-[#E8E0F0] shadow-sm p-5
                           hover:shadow-md hover:border-blue-300 transition-all duration-200
                           active:scale-[.985] cursor-pointer block text-left w-full">
                <div class="dir-card-spinner"><span></span><span></span><span></span></div>
                <span class="dir-card-tip"><i class="fas fa-eye mr-1.5"></i>View All Job Postings</span>
                <div class="flex items-start justify-between mb-3 sm:mb-4">
                    <div class="w-11 h-11 sm:w-12 sm:h-12 rounded-xl flex items-center justify-center shadow bg-blue-600">
                        <i class="fas fa-briefcase text-white text-base sm:text-lg"></i>
                    </div>
                    <span class="font-semibold px-2.5 py-1 rounded-full uppercase text-blue-700
                                 border border-blue-200 bg-blue-50 text-[0.7rem] sm:text-[0.75rem]">Jobs</span>
                </div>
                <p class="dir-stat-num text-[#111111] font-extrabold leading-none tracking-tight text-[2.6rem] sm:text-[3rem]">{{ number_format($totalJobs) }}</p>
                <p class="text-[#111111] font-semibold mt-2 text-[0.98rem] sm:text-[1.05rem]">Job Postings</p>
                <p class="text-emerald-600 font-semibold mt-1 flex items-center gap-1 text-[0.85rem]">
                    <i class="fas fa-circle text-[8px]"></i> {{ $activeJobs }} Active
                    <span class="text-[#333333] font-normal">· {{ $inactiveJobs }} Inactive</span>
                </p>
            </button>

        </div>

        {{-- Side-by-side breakdown panels (chart cards removed) --}}
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 dir-fade-up dir-fade-2">

            {{-- Events Overview Panel — mini tiles now link to clean filtered URLs --}}
            @php
                $evtCards = [
                    ['label'=>'Pending',   'count'=>$pendingEvents,   'icon'=>'fa-hourglass-end',  'bg'=>'bg-amber-50 border-amber-200',     'color'=>'text-amber-700',   'status'=>'pending',   'ctip'=>'View Pending Events',   'method'=>'goToPendingEvents'],
                    ['label'=>'Approved',  'count'=>$approvedEvents,  'icon'=>'fa-calendar-check', 'bg'=>'bg-emerald-50 border-emerald-200', 'color'=>'text-emerald-700', 'status'=>'approved',  'ctip'=>'View Approved Events',  'method'=>'goToApprovedEvents'],
                    ['label'=>'Completed', 'count'=>$completedEvents, 'icon'=>'fa-flag-checkered', 'bg'=>'bg-blue-50 border-blue-200',       'color'=>'text-blue-700',    'status'=>'completed', 'ctip'=>'View Completed Events', 'method'=>'goToCompletedEvents'],
                    ['label'=>'Rejected',  'count'=>$rejectedEvents,  'icon'=>'fa-circle-xmark',   'bg'=>'bg-red-50 border-red-200',         'color'=>'text-red-700',     'status'=>'rejected',  'ctip'=>'View Rejected Events',  'method'=>'goToRejectedEvents'],
                ];
            @endphp
            <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden flex flex-col">
                <div class="px-4 py-2 border-b border-[#E8E0F0] flex items-center justify-between"
                     style="background:linear-gradient(to right,#F9F7FC,#ffffff);">
                    <div class="flex items-center gap-2">
                        <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                             style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                            <i class="fas fa-calendar-days text-white text-[10px]"></i>
                        </div>
                        <p class="text-xs font-semibold text-[#333333] uppercase tracking-wide">Events Overview</p>
                    </div>
                </div>

                <div class="p-3 flex-1">
                    <div class="grid grid-cols-2 gap-2">
                        @foreach($evtCards as $card)
                        <button type="button" wire:click="{{ $card['method'] }}"
                           class="dir-mini-card dir-mini-tile dir-card-clickable dir-card-nav-btn rounded-xl border {{ $card['bg'] }} block w-full text-left">
                            <div class="dir-card-spinner dir-card-spinner--sm"><span></span><span></span><span></span></div>
                            <span class="dir-mini-tip"><i class="fas fa-eye mr-1"></i>{{ $card['ctip'] }}</span>
                            <div class="flex items-center gap-1.5 mb-1">
                                <i class="fas {{ $card['icon'] }} text-[10px] {{ $card['color'] }}"></i>
                                <span class="text-[.68rem] font-bold text-[#333333] uppercase tracking-wide">{{ $card['label'] }}</span>
                            </div>
                            <p class="dir-mini-num font-extrabold leading-none {{ $card['color'] }}">{{ number_format($card['count']) }}</p>
                        </button>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- Job Postings Panel — mini tiles now navigate to Job Management with auto-filter (session pattern) --}}
            <div class="bg-white rounded-2xl border border-[#E8E0F0] shadow-sm overflow-hidden flex flex-col">
                <div class="px-4 py-2 border-b border-[#E8E0F0] flex items-center justify-between"
                     style="background:linear-gradient(to right,#F9F7FC,#ffffff);">
                    <div class="flex items-center gap-2">
                        <div class="w-6 h-6 rounded-lg flex items-center justify-center bg-blue-600">
                            <i class="fas fa-briefcase text-white text-[10px]"></i>
                        </div>
                        <p class="text-xs font-semibold text-[#333333] uppercase tracking-wide">Job Postings</p>
                    </div>
                </div>

                <div class="p-3 grid grid-cols-2 gap-2 content-start flex-1">
                    <button type="button" wire:click="goToActiveJobs"
                            class="dir-mini-card dir-mini-tile dir-card-clickable dir-card-nav-btn rounded-xl border bg-emerald-50 border-emerald-200 block w-full text-left">
                        <div class="dir-card-spinner dir-card-spinner--sm"><span></span><span></span><span></span></div>
                        <span class="dir-mini-tip"><i class="fas fa-eye mr-1"></i>View Active Jobs</span>
                        <p class="dir-mini-num font-extrabold leading-none text-emerald-700">{{ number_format($activeJobs) }}</p>
                        <p class="dir-mini-label font-bold text-[#333333] uppercase tracking-wide">Active</p>
                    </button>
                    <button type="button" wire:click="goToInactiveJobs"
                            class="dir-mini-card dir-mini-tile dir-card-clickable dir-card-nav-btn rounded-xl border bg-gray-50 border-gray-200 block w-full text-left">
                        <div class="dir-card-spinner dir-card-spinner--sm"><span></span><span></span><span></span></div>
                        <span class="dir-mini-tip"><i class="fas fa-eye mr-1"></i>View Inactive Jobs</span>
                        <p class="dir-mini-num font-extrabold leading-none text-[#333333]">{{ number_format($inactiveJobs) }}</p>
                        <p class="dir-mini-label font-bold text-[#333333] uppercase tracking-wide">Inactive</p>
                    </button>
                </div>
            </div>

        </div>{{-- end side-by-side grid --}}

    </div>{{-- end right col --}}
</div>{{-- end main grid --}}


{{-- ════════════════════════════════════════════════════════════════
     MODAL: COORDINATORS
════════════════════════════════════════════════════════════════ --}}
@if($activeModal === 'coords')
@php
    $filteredCoords = collect($modalCoords)
        ->when($coordSearch !== '', fn($c) => $c->filter(fn($o) =>
            str_contains(strtolower($o['name']),       strtolower($coordSearch)) ||
            str_contains(strtolower($o['email']),      strtolower($coordSearch)) ||
            str_contains(strtolower($o['department']), strtolower($coordSearch))
        ))
        ->values();
    $coordTotal    = $filteredCoords->count();
    $coordLastPage = max((int) ceil($coordTotal / $coordModalSize), 1);
    $coordSafePage = min($coordModalPage, $coordLastPage);
    $coordFrom     = $coordTotal > 0 ? ($coordSafePage - 1) * $coordModalSize + 1 : 0;
    $coordTo       = min($coordSafePage * $coordModalSize, $coordTotal);
    $displayCoords = $filteredCoords->slice(($coordSafePage - 1) * $coordModalSize, $coordModalSize)->values()->toArray();
    $coordStatuses   = collect($modalCoords)->pluck('status')->unique()->toArray();
    $coordModalTitle = count($coordStatuses) === 1 && $coordStatuses[0] === 'ACTIVE'
        ? 'Active Coordinators' : 'All Coordinators';
@endphp
<div class="fixed inset-0 z-[9999] flex flex-col bg-gray-50 dir-modal-enter"
     @keydown.escape.window="$wire.closeModal()">
    <div class="flex items-center justify-between px-6 lg:px-10 py-4 shrink-0 shadow" style="background:#7A3F91;">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-user-tie text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">{{ $coordModalTitle }}</h2>
                <p class="text-white/60 text-xs">{{ $coordFrom }}–{{ $coordTo }} of {{ $coordTotal }} coordinator(s)</p>
            </div>
        </div>
        <button wire:click="closeModal" class="dir-close-btn"><i class="fas fa-xmark"></i><span class="hidden sm:inline">Close</span></button>
    </div>
    <div class="px-6 lg:px-10 py-3 bg-white border-b border-gray-200 shrink-0">
        <div class="flex items-center gap-3">
            <div class="relative flex-1 max-w-sm" wire:ignore
                 x-data="{ q:'', init(){ this.q=$wire.coordSearch??''; $wire.$watch('coordSearch',v=>{if(v!==this.q)this.q=v;}); } }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input type="text" x-model="q" @input.debounce.300ms="$wire.set('coordSearch', q)"
                       placeholder="Search name, email, department…"
                       class="w-full pl-8 pr-3 py-2 border border-gray-200 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:ring-2 focus:ring-[#7A3F91]/30 transition-all"
                       autocomplete="off">
            </div>
            <span class="text-xs text-gray-400 hidden sm:inline">Showing <strong class="text-gray-600">{{ $coordFrom }}–{{ $coordTo }}</strong> of <strong class="text-gray-600">{{ $coordTotal }}</strong></span>
        </div>
    </div>
    <div class="flex-1 overflow-auto min-h-0 dir-scroll overscroll-contain" style="-webkit-overflow-scrolling:touch;">
        <table class="w-full border-collapse" style="min-width:500px;">
            <thead class="sticky top-0 z-10" style="background:#f5f0fa;">
                <tr class="border-b-2 border-[#E8E0F0]">
                    <th class="pl-6 lg:pl-10 pr-3 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider w-14">#</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Name</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider hidden sm:table-cell">Email</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider hidden md:table-cell">Department</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider hidden sm:table-cell">Registered</th>
                    <th class="px-4 py-3 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($displayCoords as $idx => $coord)
                <tr class="dir-table-row bg-white">
                    <td class="pl-6 lg:pl-10 pr-3 py-3.5"><span class="text-xs font-semibold" style="color:#c0a0d8;">{{ str_pad($coordFrom + $idx, 2, '0', STR_PAD_LEFT) }}</span></td>
                    <td class="px-4 py-3.5"><p class="text-sm font-semibold text-gray-900">{{ $coord['name'] ?: '—' }}</p></td>
                    <td class="px-4 py-3.5 hidden sm:table-cell"><p class="text-sm text-gray-500 truncate" style="max-width:200px;">{{ $coord['email'] }}</p></td>
                    <td class="px-4 py-3.5 hidden md:table-cell">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold border" style="background:#F9F7FC; color:#7A3F91; border-color:#E8E0F0;">{{ $coord['department'] }}</span>
                    </td>
                    <td class="px-4 py-3.5 hidden sm:table-cell"><p class="text-xs text-gray-400">{{ $coord['created_at'] }}</p></td>
                    <td class="px-4 py-3.5 text-center">
                        @if($coord['status'] === 'ACTIVE')
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border text-emerald-700 bg-emerald-50 border-emerald-200"><i class="fas fa-circle text-[8px]"></i> Active</span>
                        @else
                            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-semibold border text-gray-500 bg-gray-50 border-gray-200"><i class="fas fa-circle text-[8px]"></i> {{ $coord['status'] }}</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="py-20 text-center">
                    <div class="flex flex-col items-center gap-3">
                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center" style="background:#f0e6f8;"><i class="fas fa-user-tie text-2xl" style="color:#c89de0;"></i></div>
                        <p class="text-sm font-semibold text-gray-400">No coordinators found</p>
                    </div>
                </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3" style="background:#7A3F91;">
        <p class="text-white text-sm">Showing <strong class="font-bold text-base">{{ $coordFrom }}–{{ $coordTo }}</strong> of <strong class="font-bold text-base">{{ $coordTotal }}</strong> coordinator(s)</p>
        @if($coordLastPage > 1)
        <div class="flex items-center gap-1.5">
            <button wire:click="coordPrevPage" {{ $coordSafePage <= 1 ? 'disabled' : '' }} class="dir-pg-btn dir-pg-nav"><i class="fas fa-chevron-left text-xs"></i></button>
            @for($p = max(1, $coordSafePage - 2); $p <= min($coordLastPage, $coordSafePage + 2); $p++)
                @if($p === $coordSafePage)<span class="dir-pg-btn dir-pg-active">{{ $p }}</span>
                @else<button wire:click="$set('coordModalPage', {{ $p }})" class="dir-pg-btn dir-pg-nav">{{ $p }}</button>@endif
            @endfor
            <button wire:click="coordNextPage({{ $coordLastPage }})" {{ $coordSafePage >= $coordLastPage ? 'disabled' : '' }} class="dir-pg-btn dir-pg-nav"><i class="fas fa-chevron-right text-xs"></i></button>
            <span class="text-xs font-semibold text-white/80 ml-1">Page {{ $coordSafePage }}/{{ $coordLastPage }}</span>
        </div>
        @endif
    </div>
</div>
@endif

<script>
(function () {
    'use strict';

    // ─── CARD CLICK SPINNER (nav cards — wire:click buttons that redirect
    //     with navigate:true) ─────────────────────────────────────────────
    // Shows a spinner inside the clicked stat/mini card the instant it's
    // tapped, and keeps it spinning until the NEW page has actually finished
    // loading (livewire:navigated) — not just until the Livewire request
    // that kicked off the redirect finishes. wire:loading.class was tried
    // first but it clears as soon as the component's action call returns,
    // which happens well before the wire:navigate page swap completes, so
    // the spinner was flashing off immediately instead of staying on
    // through the whole transition. Plain click listeners + livewire:navigated
    // don't have that gap. Same pattern as the Alumni Dashboard, ported here
    // with dir- prefixed classes for consistency.
    function getDirRoot()    { return document.getElementById('dir-dashboard-root'); }
    function getDirBlocker() { return document.getElementById('dir-nav-blocker'); }

    function lockDirNav() {
        var root    = getDirRoot();
        var blocker = getDirBlocker();
        if (root)    root.classList.add('dir-nav-active');
        if (blocker) blocker.style.display = 'block';
    }

    function unlockDirNav() {
        var root    = getDirRoot();
        var blocker = getDirBlocker();
        if (root)    root.classList.remove('dir-nav-active');
        if (blocker) blocker.style.display = 'none';
    }

    function initDirCardSpinners() {
        document.querySelectorAll('button.dir-card-nav-btn').forEach(function (card) {
            if (card.__dirSpinnerBound) return;
            card.__dirSpinnerBound = true;
            card.addEventListener('click', function () {
                clearOtherDirCardSpinners(card);
                card.classList.add('is-loading');
                // Show the transparent full-page blocker so the user cannot
                // accidentally click another card mid-navigation.  The cursor
                // is already cursor:default on the blocker itself; dir-nav-active
                // on the root resets every other nav button's hover state too.
                lockDirNav();
            });
        });
    }

    function clearOtherDirCardSpinners(except) {
        document.querySelectorAll('.dir-card-clickable.is-loading').forEach(function (el) {
            if (el !== except) el.classList.remove('is-loading');
        });
    }

    function clearAllDirCardSpinners() {
        document.querySelectorAll('.dir-card-clickable.is-loading').forEach(function (el) {
            el.classList.remove('is-loading');
        });
        unlockDirNav();
    }

    // Safety net: if navigation fails or the page is restored from bfcache,
    // don't leave a card stuck spinning / the page locked forever.
    window.addEventListener('pageshow', clearAllDirCardSpinners);

    // Bind card spinners right away so clicks right after page load feel
    // responsive.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initDirCardSpinners);
    } else {
        initDirCardSpinners();
    }
    document.addEventListener('livewire:navigated', function () {
        clearAllDirCardSpinners();
        initDirCardSpinners();
    });
})();
</script>


<script>
(function () {
    'use strict';
    if (window.dirPhotoFace) return;

    // Where the face should land inside the saved square photo, so it looks
    // right in the profile card (name overlay at the bottom, circle avatars elsewhere):
    //   FACE_W  = face width as a fraction of the square
    //   FACE_CY = face centre, measured from the top of the square
    var FACE_W  = 0.34;
    var FACE_CY = 0.38;

    var MP_VERSION = '1.0.1';
    var MP_BASE    = 'https://cdn.jsdelivr.net/npm/@@mediapipe/tasks-vision@@' + MP_VERSION;
    var MP_MODEL   = 'https://storage.googleapis.com/mediapipe-models/face_detector/blaze_face_short_range/float16/1/blaze_face_short_range.tflite';
    var mpPromise  = null;

    function withTimeout(promise, ms) {
        return new Promise(function (resolve, reject) {
            var t = setTimeout(function () { reject(new Error('timeout')); }, ms);
            promise.then(function (v) { clearTimeout(t); resolve(v); },
                         function (e) { clearTimeout(t); reject(e); });
        });
    }

    function loadImage(file) {
        return new Promise(function (resolve, reject) {
            var url = URL.createObjectURL(file);
            var img = new Image();
            img.onload  = function () { URL.revokeObjectURL(url); resolve(img); };
            img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('bad image')); };
            img.src = url;
        });
    }

    function toCanvas(img, maxSide) {
        var s = Math.min(1, maxSide / Math.max(img.naturalWidth, img.naturalHeight));
        var c = document.createElement('canvas');
        c.width  = Math.max(1, Math.round(img.naturalWidth  * s));
        c.height = Math.max(1, Math.round(img.naturalHeight * s));
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        return { canvas: c, scale: s };
    }

    // 1) Browser-native FaceDetector (Chromium builds that ship it): instant, no download.
    async function detectNative(canvas) {
        if (!('FaceDetector' in window)) return null;
        var d = new window.FaceDetector({ fastMode: true, maxDetectedFaces: 5 });
        var faces = await d.detect(canvas);
        return (faces || []).map(function (f) {
            var b = f.boundingBox;
            return { x: b.x, y: b.y, w: b.width, h: b.height };
        });
    }

    // 2) MediaPipe Face Detector, loaded lazily (only when a photo is actually picked,
    //    or warmed up when the pointer first enters the photo).
    function getMediaPipe() {
        if (!mpPromise) {
            mpPromise = (async function () {
                var vision  = await import(MP_BASE + '/vision_bundle.mjs');
                var fileset = await vision.FilesetResolver.forVisionTasks(MP_BASE + '/wasm');
                return vision.FaceDetector.createFromOptions(fileset, {
                    baseOptions: { modelAssetPath: MP_MODEL },
                    runningMode: 'IMAGE',
                    minDetectionConfidence: 0.5
                });
            })();
            mpPromise.catch(function () { mpPromise = null; }); // allow a retry next time
        }
        return mpPromise;
    }

    async function detectMediaPipe(canvas) {
        var det = await withTimeout(getMediaPipe(), 12000);
        var res = det.detect(canvas);
        return (res.detections || [])
            .filter(function (d) { return d.boundingBox; })
            .map(function (d) {
                var b = d.boundingBox;
                return { x: b.originX, y: b.originY, w: b.width, h: b.height };
            });
    }

    async function detectFaces(canvas) {
        var found = null;
        try { found = await detectNative(canvas); } catch (e) { found = null; }
        if (found && found.length) return found;
        try { return await detectMediaPipe(canvas); } catch (e) { return []; }
    }

    // Square crop (in ORIGINAL image pixels) that frames the face for the card.
    // No face → centred crop that favours the upper part of the picture (where heads usually are).
    function computeCrop(W, H, face) {
        var S, cx, cy;
        if (face) {
            S  = Math.max(face.w / FACE_W, face.h / 0.46);
            cx = face.x + face.w / 2;
            cy = face.y + face.h / 2;
        } else {
            S  = Math.min(W, H);
            cx = W / 2;
            cy = H * 0.30;
        }
        S = Math.min(S, W, H);
        var sx = Math.min(Math.max(cx - S / 2, 0), W - S);
        var sy = Math.min(Math.max(cy - S * FACE_CY, 0), H - S);
        return { sx: sx, sy: sy, size: S };
    }

    function render(img, crop, outSize, quality) {
        var size = Math.max(64, Math.min(outSize, Math.round(crop.size)));
        var c = document.createElement('canvas');
        c.width = c.height = size;
        var ctx = c.getContext('2d');
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        ctx.fillStyle = '#ffffff'; // JPEG has no alpha — transparent PNGs get a white background
        ctx.fillRect(0, 0, size, size);
        ctx.drawImage(img, crop.sx, crop.sy, crop.size, crop.size, 0, 0, size, size);
        return c.toDataURL('image/jpeg', quality);
    }

    async function processFile(file, outSize, quality) {
        var img = await loadImage(file);
        var W = img.naturalWidth, H = img.naturalHeight;
        var det = toCanvas(img, 640);

        var faces = [];
        try { faces = await detectFaces(det.canvas); } catch (e) { faces = []; }

        var face = null;
        if (faces.length) {
            var best = faces.reduce(function (a, b) { return (b.w * b.h > a.w * a.h) ? b : a; });
            face = { x: best.x / det.scale, y: best.y / det.scale, w: best.w / det.scale, h: best.h / det.scale };
        }

        var crop = computeCrop(W, H, face);
        return { dataUrl: render(img, crop, outSize || 600, quality || 0.85), faceFound: !!face };
    }

    window.dirPhotoFace = {
        process: processFile,
        warmUp: function () {
            // Only needed when the browser has no native FaceDetector.
            if (!('FaceDetector' in window)) { getMediaPipe().catch(function () {}); }
        },
        _computeCrop: computeCrop
    };
})();
</script>

</div>{{-- end root --}}