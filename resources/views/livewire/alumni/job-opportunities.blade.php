{{-- resources/views/livewire/alumni/job-opportunities.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use App\Models\JobPosting;
use App\Models\Alumni;
use App\Models\Course;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

new class extends Component {
    use WithPagination;

    protected string $paginationTheme = 'tailwind';

    public string $search         = '';
    public string $filterType     = '';
    public string $filterLevel    = '';
    public string $filterSort     = 'deadline_asc';

    public bool $showDetail    = false;
    public ?int $viewingJobId  = null;

    public string $alumniCollege   = '';
    public string $alumniCourse    = '';
    public string $alumniFirstName = '';
    public int    $alumniId        = 0;
    public int    $alumniRoomId    = 0;

    // The alumni's chat rooms (batch chat + course-wide chat), used by the
    // "forward"-style destination picker in the Share modal.
    public array  $alumniChatRooms = [];

    public bool   $showShareModal   = false;
    public ?int   $shareJobId       = null;

    // Forward-to-chat destination picker (Messenger-forward style)
    public bool   $showForwardModal = false;
    public array  $selectedRoomIds  = [];
    public string $shareJobTitle    = '';
    public string $shareCompany     = '';
    public string $shareEmpType     = '';
    public string $shareLocation    = '';
    public string $shareExpLevel    = '';
    public string $shareSalary      = '';
    public string $shareDeadline    = '';
    public string $shareDescription = '';
    public string $shareCollege     = '';
    public string $shareImageUrl    = '';

    public function mount(): void
    {
        $user = auth()->user();
        if (!$user || $user->role !== 'alumni') {
            $this->redirect(route('login'));
            return;
        }

        $alumni = Alumni::where('user_id', $user->id)
            ->select(['id', 'first_name', 'course_code', 'course_name', 'batch'])
            ->first();

        if (!$alumni) {
            $this->redirect(route('login'));
            return;
        }

        $this->alumniId        = $alumni->id;
        $this->alumniFirstName = $alumni->first_name ?? '';
        $this->alumniCourse    = $alumni->course_name ?? $alumni->course_code ?? '';

        $this->alumniCollege = Cache::remember(
            'alumni_college_' . $alumni->course_code,
            600,
            fn() => Course::where('code', $alumni->course_code)->value('college') ?? ''
        );

        $room = DB::table('chat_rooms')
            ->where('course_code', $alumni->course_code)
            ->where('batch', $alumni->batch)
            ->first();
        $this->alumniRoomId = $room ? (int) $room->id : 0;

        // Build the list of chat rooms this alumni can forward a job post to.
        // Room 1: their own batch chat (course_code + batch).
        // Room 2: the COLLEGE-wide chat — stored the same way
        // messenger.blade.php's ensureRoomsExist() creates it: `department`
        // = college name, `course_code` = the CLG_ marker, `batch` = 0.
        $chatRooms = collect();
        if ($this->alumniRoomId) {
            $chatRooms->push([
                'id'    => $this->alumniRoomId,
                'label' => 'Batch ' . ($alumni->batch ?? '') . ' Chat',
            ]);
        }
        if ($this->alumniCollege) {
            $marker = $this->collegeMarker($this->alumniCollege);
            $generalRoom = DB::table('chat_rooms')
                ->where('department', $this->alumniCollege)
                ->where('course_code', $marker)
                ->where('batch', 0)
                ->first();
            if ($generalRoom && (int) $generalRoom->id !== $this->alumniRoomId) {
                $chatRooms->push([
                    'id'    => (int) $generalRoom->id,
                    'label' => $generalRoom->name ?? ($this->alumniCollege . ' Chat'),
                ]);
            }
        }
        $this->alumniChatRooms = $chatRooms->values()->toArray();

        // Deep link support — lets a "View Post" link shared in chat
        // (e.g. /job/opportunities?job=123) jump straight into that job's
        // detail view, with all other filters cleared so it's guaranteed
        // to be visible in the list underneath.
        //
        // NOTE (clean URL): the actual address-bar cleanup lives entirely
        // client-side now — see the small <script> right below the root
        // <div> that listens for the `livewire:navigated` event. It's
        // intentionally NOT done here in PHP (no dispatch()/$this->js()),
        // and it's intentionally NOT an Alpine x-init either — both of
        // those ran *before* Livewire's own `wire:navigate` history
        // handling finished, so Livewire ended up re-pushing the original
        // "?job=35" URL right after we cleaned it. Listening for
        // `livewire:navigated` guarantees our cleanup runs last.
        $jobParam = request()->query('job');
        if ($jobParam !== null && ctype_digit((string) $jobParam)) {
            $exists = JobPosting::where('id', (int) $jobParam)
                ->where('status', 'ACTIVE')
                ->exists();
            if ($exists) {
                // Reset filters so the job is guaranteed to show under the list.
                $this->search      = '';
                $this->filterType  = '';
                $this->filterLevel = '';
                $this->filterSort  = 'deadline_asc';

                $this->viewingJobId = (int) $jobParam;
                $this->showDetail   = true;
            }
        }
    }

    public function updatingSearch()      { $this->resetPage(); }
    public function updatingFilterType()  { $this->resetPage(); }
    public function updatingFilterLevel() { $this->resetPage(); }
    public function updatingFilterSort()  { $this->resetPage(); }

    public function resetFilters(): void
    {
        $this->search = $this->filterType = $this->filterLevel = '';
        $this->filterSort = 'deadline_asc';
        $this->resetPage();
    }

    #[Computed]
    public function jobPostings()
    {
        $college = $this->alumniCollege;
        $today   = now('Asia/Manila')->toDateString();

        $q = JobPosting::select([
                'id', 'organizer_id', 'job_title', 'company_name', 'company_type',
                'location', 'employment_type', 'experience_level',
                'target_college', 'salary', 'deadline', 'status', 'job_image',
                'description', 'qualifications', 'application_instructions', 'created_at',
            ])
            ->where('status', 'ACTIVE')
            ->where(function ($q) use ($college) {
                $q->whereNull('target_college')
                  ->orWhere('target_college', '')
                  ->orWhere('target_college', 'like', "%{$college}%");
            })
            ->where('deadline', '>=', $today);

        if ($this->search !== '') {
            $s = strip_tags(trim($this->search));
            $q->where(fn($sub) =>
                $sub->where('job_title',     'like', "%{$s}%")
                    ->orWhere('company_name', 'like', "%{$s}%")
                    ->orWhere('location',     'like', "%{$s}%")
            );
        }

        if ($this->filterType  !== '') $q->where('employment_type',  $this->filterType);
        if ($this->filterLevel !== '') $q->where('experience_level', $this->filterLevel);

        match ($this->filterSort) {
            'deadline_asc'  => $q->orderBy('deadline', 'asc'),
            'recent'        => $q->orderBy('created_at', 'desc'),
            default         => $q->orderBy('deadline', 'asc'),
        };

        return $q->paginate(20);
    }

    public function viewJob(int $id): void
    {
        $this->viewingJobId = $id;
        $this->showDetail   = true;
    }

    public function closeDetail(): void
    {
        $this->showDetail   = false;
        $this->viewingJobId = null;
    }

    #[Computed]
    public function viewingJob(): ?JobPosting
    {
        if (!$this->viewingJobId) return null;
        return JobPosting::where('id', $this->viewingJobId)
            ->where('status', 'ACTIVE')
            ->first();
    }

    public static function jobImageUrl(?string $path): string
    {
        if ($path && Storage::disk('public')->exists($path)) {
            return Storage::url($path);
        }
        return asset('storage/job/default-photo-job.jpg');
    }

    // Same marker scheme used in messenger.blade.php's chat_rooms table:
    // the college-wide room is stored with course_code = 'CLG_' + a short
    // hash of the college name, and batch = 0.
    private function collegeMarker(string $college): string
    {
        return 'CLG_' . substr(md5($college), 0, 12);
    }

    public function openShareModal(int $id): void
    {
        $job = JobPosting::findOrFail($id);

        $deadlinePassed = \Carbon\Carbon::parse($job->deadline)
            ->setTimezone('Asia/Manila')->startOfDay()
            ->lt(now('Asia/Manila')->startOfDay());

        if ($deadlinePassed) {
            $this->dispatch('flash-message', type: 'warning', message: 'This job posting can no longer be shared — the deadline has already passed.');
            return;
        }

        $this->shareJobId       = $id;
        $this->shareJobTitle    = $job->job_title;
        $this->shareCompany     = $job->company_name;
        $this->shareEmpType     = $job->employment_type;
        $this->shareLocation    = $job->location ?? '';
        $this->shareExpLevel    = $job->experience_level ?? '';
        $this->shareSalary      = $job->salary ?? '';
        $this->shareDeadline    = $job->deadline ?? '';
        $this->shareDescription = $job->description ?? '';
        $this->shareCollege     = $job->target_college ?? '';
        $this->shareImageUrl    = $this::jobImageUrl($job->job_image ?? null);
        $this->showShareModal   = true;
    }

    public function closeShareModal(): void
    {
        $this->showShareModal   = false;
        $this->shareJobId       = null;
        $this->shareJobTitle    = '';
        $this->shareCompany     = '';
        $this->shareEmpType     = '';
        $this->shareLocation    = '';
        $this->shareExpLevel    = '';
        $this->shareSalary      = '';
        $this->shareDeadline    = '';
        $this->shareDescription = '';
        $this->shareCollege     = '';
        $this->shareImageUrl    = '';
    }

    public function jobsBaseUrl(): string
    {
        $base = rtrim(config('app.url'), '/');
        try {
            // FIXED: route name was 'jobs.index' (doesn't exist) so it was
            // always falling through to '/jobs'. The actual named route
            // for this page is 'job.opportunities' (see routes/web.php).
            $path = route('job.opportunities', [], false);
        } catch (\Throwable) {
            $path = '/job/opportunities';
        }
        return $base . $path;
    }

    // Direct link back to a specific job's detail view — used by the
    // "View Post" link shared in Batch Chat and by the Facebook/Messenger
    // share targets, so clicking it opens straight into that job.
    public function jobDetailUrl(int $id): string
    {
        return $this->jobsBaseUrl() . '?job=' . $id;
    }

    /**
     * ── Messenger-style share (UPDATED) ─────────────────────────────────
     * The chat message body used to be a giant emoji-formatted paragraph
     * with the job's title/company/salary/etc. duplicated as raw text,
     * plus a raw URL at the bottom. That's why chat showed an ugly wall
     * of text instead of a real Messenger-style link-preview card.
     *
     * Now the body is just a short marker: "[[JOB:{id}]]". messenger.
     * blade.php looks the job up fresh from the DB using that marker and
     * renders an actual image+text preview card — no duplicated data,
     * no visible raw link, and it always reflects the job's *current*
     * info (title/photo/etc.) even if it changes after the share.
     */
    // Opens the "forward to chat" destination picker (Messenger-forward style).
    public function openForwardModal(): void
    {
        if (empty($this->alumniChatRooms)) {
            $this->dispatch('flash-message', type: 'error', message: 'No chat rooms found to share to.');
            return;
        }

        $deadlinePassed = $this->shareDeadline
            && \Carbon\Carbon::parse($this->shareDeadline)
                ->setTimezone('Asia/Manila')->startOfDay()
                ->lt(now('Asia/Manila')->startOfDay());

        if ($deadlinePassed) {
            $this->dispatch('flash-message', type: 'warning', message: 'This job posting can no longer be shared — the deadline has already passed.');
            return;
        }

        // Preselect their own batch chat by default.
        $this->selectedRoomIds  = $this->alumniRoomId ? [$this->alumniRoomId] : [];
        $this->showForwardModal = true;
    }

    public function closeForwardModal(): void
    {
        $this->showForwardModal = false;
        $this->selectedRoomIds  = [];
    }

    public function toggleRoomSelection(int $roomId): void
    {
        if (in_array($roomId, $this->selectedRoomIds, true)) {
            $this->selectedRoomIds = array_values(array_diff($this->selectedRoomIds, [$roomId]));
        } else {
            $this->selectedRoomIds[] = $roomId;
        }
    }

    // Sends the job post to every chat the alumni selected — one tap for
    // one chat, or tap both to forward to both at once.
    public function confirmSendToChat(): void
    {
        if (empty($this->selectedRoomIds)) {
            $this->dispatch('flash-message', type: 'warning', message: 'Pumili muna ng chat kung saan mo ipapadala.');
            return;
        }

        if (! $this->shareJobId) {
            $this->dispatch('flash-message', type: 'error', message: 'Could not find the job to share.');
            return;
        }

        $deadlinePassed = $this->shareDeadline
            && \Carbon\Carbon::parse($this->shareDeadline)
                ->setTimezone('Asia/Manila')->startOfDay()
                ->lt(now('Asia/Manila')->startOfDay());

        if ($deadlinePassed) {
            $this->dispatch('flash-message', type: 'warning', message: 'This job posting can no longer be shared — the deadline has already passed.');
            return;
        }

        $body = "@everyone [[JOB:{$this->shareJobId}]]";
        $now  = now();

        foreach ($this->selectedRoomIds as $roomId) {
            DB::table('chat_messages')->insert([
                'room_id'     => $roomId,
                'sender_type' => 'alumni',
                'sender_id'   => $this->alumniId,
                'body'        => $body,
                'reply_to_id' => null,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }

        $count = count($this->selectedRoomIds);

        $this->closeForwardModal();
        $this->closeShareModal();
        $this->dispatch('flash-message', type: 'success', message: $count > 1
            ? "Job shared to {$count} chats!"
            : 'Job shared to the chat!');
    }

}; ?>

<div class="flex flex-col" style="height:calc(100vh - 180px);max-height:calc(100vh - 180px);overflow:hidden;">

{{-- ── Clean-URL cleanup script ──────────────────────────────────────────
     Strips the "?job=123" query param from the address bar once the job
     detail view has been opened. Runs on `livewire:navigated` (fires both
     on a normal full page load AND after a `wire:navigate` SPA-style
     transition) plus a DOMContentLoaded fallback, so it always runs AFTER
     Livewire finishes its own history/URL handling — that ordering is
     what makes this actually stick instead of getting overwritten. --}}
<script>
(function () {
    function jbStripJobQuery() {
        if (new URLSearchParams(window.location.search).has('job')) {
            window.history.replaceState(null, '', window.location.origin + window.location.pathname);
        }
    }
    document.addEventListener('livewire:navigated', jbStripJobQuery);
    document.addEventListener('DOMContentLoaded', jbStripJobQuery);
    if (document.readyState !== 'loading') jbStripJobQuery();
})();
</script>

<style>
/* ─────────────────────────────────────────────
   FILTER SELECTS
───────────────────────────────────────────── */
select.filter-input {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
    background-position: right 0.6rem center;
    background-repeat: no-repeat;
    background-size: 1.1em 1.1em;
    padding-right: 2.1rem;
    -webkit-appearance: none;
    appearance: none;
}

@keyframes detailIn { from { opacity: 0; } to { opacity: 1; } }
.detail-page { animation: detailIn .18s cubic-bezier(.4,0,.2,1) both; }

@keyframes panelIn {
    from { opacity: 0; transform: scale(.97) translateY(8px); }
    to   { opacity: 1; transform: none; }
}
.share-sheet { animation: panelIn .2s cubic-bezier(.25,.8,.25,1) both; }

.scroll-thin::-webkit-scrollbar       { width: 4px; }
.scroll-thin::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }

.pre-wrap { white-space: pre-wrap; }

.share-modal-wrapper {
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* Mouse-following "View Details" label — desktop only, hidden on mobile below */
#jb-cursor-label {
    position: fixed;
    z-index: 99999;
    pointer-events: none;
    display: flex;
    align-items: center;
    gap: 5px;
    background: #111827;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .05em;
    text-transform: uppercase;
    padding: 6px 12px;
    border-radius: 8px;
    white-space: nowrap;
    box-shadow: 0 4px 16px rgba(0,0,0,.28);
    user-select: none;
    font-family: ui-sans-serif, system-ui, sans-serif;
    opacity: 0;
    visibility: hidden;
    transition: opacity .1s ease, visibility .1s ease;
    left: -999px;
    top: -999px;
}
#jb-cursor-label svg {
    width: 11px; height: 11px; flex-shrink: 0;
    fill: none; stroke: #fff; stroke-width: 2;
    stroke-linecap: round; stroke-linejoin: round;
}

[data-jb-card] { transition: border-color .15s ease, box-shadow .15s ease; }
[data-jb-card]:hover {
    border-color: #c4b5d4 !important;
    box-shadow: 0 4px 20px rgba(122,63,145,.12) !important;
}

.card-share-btn {
    position: relative;
    display: inline-flex; align-items: center; justify-content: center;
    width: 2rem; height: 2rem; border-radius: 0.5rem;
    background: #eff6ff; border: 1px solid #bfdbfe; color: #1d4ed8;
    cursor: pointer;
    transition: background .15s, border-color .15s, transform .1s;
    flex-shrink: 0; z-index: 2;
}
.card-share-btn:hover { background: #dbeafe; border-color: #93c5fd; transform: scale(1.08); }
.card-share-btn .tip {
    position: absolute; bottom: calc(100% + 7px); right: 0;
    background: #111827; color: #fff;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
    padding: 4px 10px; border-radius: 6px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity .15s; z-index: 9999;
    font-family: ui-sans-serif, system-ui, sans-serif;
}
.card-share-btn .tip::after {
    content: ''; position: absolute; top: 100%; right: 10px;
    border: 4px solid transparent; border-top-color: #111827;
}
.card-share-btn:hover .tip { opacity: 1; }

.detail-top-btn {
    position: relative;
    display: inline-flex; align-items: center; justify-content: center;
    width: 2rem; height: 2rem; border-radius: 0.5rem;
    cursor: pointer; transition: background .15s, transform .1s;
    flex-shrink: 0; border: none; outline: none;
}
.detail-top-btn:active { transform: scale(.93); }
.detail-top-btn .tip {
    position: absolute; top: calc(100% + 6px); right: 0;
    background: #111827; color: #fff;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
    padding: 4px 10px; border-radius: 6px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity .15s; z-index: 9999;
    font-family: ui-sans-serif, system-ui, sans-serif;
}
.detail-top-btn .tip::before {
    content: ''; position: absolute; bottom: 100%; right: 10px;
    border: 4px solid transparent; border-bottom-color: #111827;
}
.detail-top-btn:hover .tip { opacity: 1; }

.detail-top-btn.share-btn { background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.2); color: #fff; }
.detail-top-btn.share-btn:hover { background: rgba(255,255,255,.24); }
.detail-top-btn.close-btn { background: rgba(255,255,255,.10); border: 1px solid rgba(255,255,255,.15); }
.detail-top-btn.close-btn:hover { background: rgba(255,255,255,.22); }
.detail-top-btn.close-btn svg { width: 13px; height: 13px; stroke: #fff; stroke-width: 2.5; stroke-linecap: round; }

/* ─────────────────────────────────────────────
   SHARE MODAL — clean / flat, no gradients.
───────────────────────────────────────────── */
.share-close-btn {
    position: relative;
    display: inline-flex; align-items: center; justify-content: center;
    width: 2rem; height: 2rem; border-radius: 0.5rem;
    background: #f3f4f6; border: 1px solid #e5e7eb;
    cursor: pointer; transition: background .15s, border-color .15s, transform .1s;
    flex-shrink: 0;
}
.share-close-btn:hover  { background: #e5e7eb; border-color: #d1d5db; }
.share-close-btn:active { transform: scale(.93); }
.share-close-btn svg    { width: 14px; height: 14px; stroke: #4b5563; stroke-width: 2.25; stroke-linecap: round; }
.share-close-btn .tip {
    position: absolute; top: calc(100% + 6px); right: 0;
    background: #111827; color: #fff;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
    padding: 4px 10px; border-radius: 6px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity .15s; z-index: 9999;
    font-family: ui-sans-serif, system-ui, sans-serif;
}
.share-close-btn .tip::before {
    content: ''; position: absolute; bottom: 100%; right: 10px;
    border: 4px solid transparent; border-bottom-color: #111827;
}
.share-close-btn:hover .tip { opacity: 1; }

.share-option-btn {
    width: 100%; display: flex; align-items: center; gap: 0.75rem;
    padding: 0.75rem 1rem; border-radius: 0.75rem;
    font-weight: 600; font-size: 0.8125rem; color: #fff;
    cursor: pointer; transition: filter .15s, transform .1s; border: none;
}
.share-option-btn:hover  { filter: brightness(0.94); }
.share-option-btn:active { transform: scale(.98); }
.share-option-btn .icon-wrap {
    width: 2rem; height: 2rem; border-radius: 0.5rem;
    background: rgba(255,255,255,.92);
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}

/* ─────────────────────────────────────────────
   PHILCST "OFFICIAL POST" DETAIL STYLING
───────────────────────────────────────────── */
.philcst-post-card { background: #fff; border: 1px solid #E8E0F0; border-radius: 14px; overflow: hidden; }
.philcst-post-banner { width: 100%; height: 180px; object-fit: cover; display: block; background: #f3f4f6; }
.philcst-post-ribbon {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 11px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
    color: #7a3f91; background: #f5eef9; border: 1px solid #e3cdf0;
    padding: 4px 10px; border-radius: 999px;
}
.philcst-checklist { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.philcst-checklist li { display: flex; align-items: flex-start; gap: 8px; font-size: 14px; line-height: 1.55; color: #333333; }
.philcst-checklist li .chk {
    flex-shrink: 0; width: 18px; height: 18px; border-radius: 5px;
    background: #f5eef9; color: #7a3f91;
    display: flex; align-items: center; justify-content: center; font-size: 10px; margin-top: 1px;
}

/* ─────────────────────────────────────────────
   DETAIL VIEW — fixed-height, no page scroll.
   Two columns: left sidebar (meta/info), right
   content. Only the two inner panels scroll on
   their own if their content is long — the page
   itself never grows past the viewport.
───────────────────────────────────────────── */
.detail-side-item { display: flex; align-items: flex-start; gap: 10px; }
.detail-side-icon {
    flex-shrink: 0; width: 28px; height: 28px; border-radius: 8px;
    background: #f5eef9; color: #7a3f91;
    display: flex; align-items: center; justify-content: center; font-size: 12px;
}
.detail-side-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #666; margin: 0; }
.detail-side-value { font-size: 13.5px; font-weight: 600; color: #333333; margin: 2px 0 0; line-height: 1.4; }

/* ─────────────────────────────────────────────
   RESPONSIVE — icon-only on small / touch screens:
   tooltips and the mouse-follow label disappear.
───────────────────────────────────────────── */
@media (max-width: 767px) {
    #jb-cursor-label { display: none !important; }
    .card-share-btn .tip,
    .detail-top-btn .tip,
    .share-close-btn .tip { display: none !important; }
}

.detail-page * {
    font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont,
                 "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif !important;
    font-style: normal !important;
}
.detail-header-title {
    font-size: 15px; font-weight: 600; color: #fff; line-height: 1.3;
    overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.detail-label { font-style: italic; }
</style>

{{-- Mouse-following cursor label — hidden on mobile via CSS + JS guard below --}}
<div id="jb-cursor-label">
    <svg viewBox="0 0 16 16"><path d="M1 8s3-5 7-5 7 5 7 5-3 5-7 5-7-5-7-5z"/><circle cx="8" cy="8" r="2.5"/></svg>
    View Details
</div>

{{-- ── FLASH TOAST ── --}}
<div x-data="{show:false,type:'success',msg:'',timer:null,display(t,m){this.type=t;this.msg=m;this.show=true;clearTimeout(this.timer);this.timer=setTimeout(()=>this.show=false,5000);}}"
     @flash-message.window="display($event.detail.type,$event.detail.message)"
     x-show="show" x-cloak
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 translate-x-8 scale-95"
     x-transition:enter-end="opacity-100 translate-x-0 scale-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0 translate-x-8"
     class="fixed top-5 right-4 sm:right-6 z-[999999] flex items-start gap-3 px-5 py-4 rounded-2xl shadow-2xl max-w-xs sm:max-w-sm border w-full bg-white"
     :class="{'border-emerald-300 text-emerald-800':type==='success','border-blue-300 text-blue-800':type==='info','border-amber-300 text-amber-800':type==='warning','border-red-300 text-red-800':type==='error'}"
     style="display:none">
    <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
         :class="{'bg-emerald-100':type==='success','bg-blue-100':type==='info','bg-amber-100':type==='warning','bg-red-100':type==='error'}">
        <i class="fas text-sm" :class="{'fa-check text-emerald-600':type==='success','fa-info text-blue-600':type==='info','fa-triangle-exclamation text-amber-600':type==='warning','fa-exclamation text-red-600':type==='error'}"></i>
    </div>
    <div class="flex-1 min-w-0">
        <p class="font-semibold text-sm" x-text="type==='success'?'Success':type==='info'?'Info':type==='warning'?'Warning':'Error'"></p>
        <p class="text-sm mt-0.5 opacity-80 leading-snug break-words" x-text="msg"></p>
    </div>
    <button @click="show=false" class="opacity-40 hover:opacity-80 transition shrink-0">
        <i class="fas fa-xmark text-sm"></i>
    </button>
</div>

{{-- ══ MAIN LAYOUT ══ --}}
<div class="flex flex-col flex-1 gap-4 px-5 sm:px-7 lg:px-10 pt-6 pb-6 max-w-screen-2xl mx-auto w-full min-h-0">

    {{-- PAGE HEADER --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 flex-shrink-0">
        <div class="flex items-center gap-4">
            <div class="w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md bg-gradient-to-br from-[#7a3f91] to-[#5e2f72]">
                <i class="fas fa-briefcase text-white text-lg"></i>
            </div>
            <div>
                <h1 class="text-xl font-semibold tracking-tight text-gray-900">Job Opportunities</h1>
                <p class="text-sm leading-relaxed mt-0.5 text-gray-700">
                    Openings available for
                    <span class="font-semibold inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-violet-50 text-violet-700 border border-violet-200">
                        {{ $alumniCollege ?: 'your college' }}
                    </span>
                </p>
            </div>
        </div>
    </div>

    {{-- ══ CONTENT BLOCK ══ --}}
    <div class="flex-1 min-h-0 flex flex-col rounded-xl overflow-hidden border border-[#E8E0F0] shadow-sm">

        {{-- ── FILTER BAR ── --}}
        <div class="bg-gray-100 border-b border-[#E8E0F0] px-3.5 py-2.5 flex flex-wrap gap-2 items-center flex-shrink-0">

            <span class="text-xs font-bold uppercase tracking-widest text-[#7a3f91] select-none px-1">Filters</span>

            <div class="relative flex-1 min-w-[160px] max-w-xs"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 pointer-events-none"></i>
                <input type="text" x-model="q" @input.debounce.350ms="$wire.set('search',q)"
                       placeholder="Title, company, location…"
                       class="filter-input w-full pl-8 pr-3 py-[7px] text-[13px] font-medium text-gray-900 bg-white border border-gray-200 rounded-lg
                              hover:border-gray-300 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                       autocomplete="off" maxlength="100" spellcheck="false">
            </div>

            <select wire:model.live="filterType"
                    class="filter-input py-[7px] px-3 text-[13px] font-medium text-gray-900 bg-white border border-gray-200 rounded-lg
                           hover:border-gray-300 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition cursor-pointer">
                <option value="">All Types</option>
                <option value="Full-Time">Full-Time</option>
                <option value="Part-Time">Part-Time</option>
                <option value="Contract">Contract</option>
                <option value="Internship">Internship</option>
                <option value="Freelance">Freelance</option>
            </select>

            <select wire:model.live="filterLevel"
                    class="filter-input py-[7px] px-3 text-[13px] font-medium text-gray-900 bg-white border border-gray-200 rounded-lg
                           hover:border-gray-300 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition cursor-pointer">
                <option value="">All Experience</option>
                <option value="No Experience Required">No Experience Required</option>
                <option value="Entry Level (At Least 1 Year)">Entry Level (At Least 1 Year)</option>
                <option value="Mid Level (2-3 Years)">Mid Level (2-3 Years)</option>
                <option value="Senior Level (4-5 Years)">Senior Level (4-5 Years)</option>
                <option value="Expert Level (5+ Years)">Expert Level (5+ Years)</option>
            </select>

            <button wire:click="resetFilters"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-wait"
                    wire:target="resetFilters"
                    class="inline-flex items-center gap-1.5 px-3 py-[7px] rounded-lg text-xs font-semibold
                           bg-white border border-gray-200 text-gray-600 hover:text-gray-900 hover:border-gray-300
                           transition active:scale-95 cursor-pointer">
                <span wire:loading.remove wire:target="resetFilters">
                    <i class="fas fa-rotate-left text-xs"></i>
                </span>
                <span wire:loading wire:target="resetFilters">
                    <svg class="animate-spin w-3.5 h-3.5 text-[#7a3f91]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>
                </span>
                <span class="hidden sm:inline">Reset</span>
            </button>

        </div>

        {{-- Filtering progress bar — mirrors the Alumni Records loading effect --}}
        <div class="jb-filter-progress-track" wire:loading wire:target="search,filterType,filterLevel,filterSort">
            <div class="jb-filter-progress-bar"></div>
        </div>
        <style>
            .jb-filter-progress-track { height:2px; width:100%; overflow:hidden; background:transparent; position:relative; }
            .jb-filter-progress-bar { position:absolute; top:0; left:0; height:100%; width:40%; border-radius:99px; background:linear-gradient(135deg,#7a3f91,#9b59b6); animation:jbFilterProgress 1s ease-in-out infinite; }
            @keyframes jbFilterProgress { 0%{left:-40%} 100%{left:100%} }
        </style>

        {{-- ── CARDS BODY ── --}}
        <div class="bg-gray-100 p-4 relative flex-1 min-h-0 overflow-y-auto transition-opacity duration-200"
             wire:loading.class="opacity-40" wire:target="search,filterType,filterLevel,filterSort">

            @if($this->jobPostings->count() > 0)
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                @foreach($this->jobPostings as $job)
                @php
                    $dl       = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
                    $today    = now('Asia/Manila')->startOfDay();
                    $daysLeft = (int) $today->diffInDays($dl->copy()->startOfDay(), false);

                    if ($daysLeft === 0)     $dlLabel = 'Closes today';
                    elseif ($daysLeft === 1) $dlLabel = '1 day left';
                    else                     $dlLabel = $daysLeft . ' days left';

                    if ($daysLeft <= 3)       { $dlClass = 'text-red-600 font-bold'; $dlIcon = 'fa-fire'; }
                    elseif ($daysLeft <= 14)  { $dlClass = 'text-orange-700 font-semibold'; $dlIcon = 'fa-clock'; }
                    else                      { $dlClass = 'text-gray-600 font-medium'; $dlIcon = 'fa-calendar'; }

                    $descPreview = $job->description ? Str::limit(strip_tags($job->description), 90) : null;
                    $displayType = ($job->company_type === $job->company_name) ? 'PHILCST' : $job->company_type;
                @endphp

                <div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden
                            cursor-pointer relative select-none flex flex-col group"
                     data-jb-card
                     wire:click="viewJob({{ $job->id }})"
                     role="button" tabindex="0"
                     onkeypress="if(event.key==='Enter')this.click()">

                    <div class="flex flex-col flex-1 p-4 gap-2.5">

                        <div class="flex items-start justify-between gap-2">
                            <div class="flex-1 min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-widest mb-1" style="color:#333333;">{{ $job->company_name }}</p>
                                <h3 class="font-semibold text-[15px] leading-snug line-clamp-2" style="color:#333333;">{{ $job->job_title }}</h3>
                            </div>
                            @if($displayType)
                            <span class="inline-flex shrink-0 text-[11px] font-medium px-2 py-0.5 rounded-md border border-gray-200 bg-gray-50 mt-0.5 whitespace-nowrap" style="color:#333333;">
                                {{ Str::limit($displayType, 14) }}
                            </span>
                            @endif
                        </div>

                        <div class="flex flex-wrap gap-1.5">
                            <span class="inline-flex items-center text-[12px] font-medium px-2.5 py-0.5 rounded-md bg-purple-50 border border-purple-100 text-purple-700">
                                {{ $job->employment_type }}
                            </span>
                            <span class="inline-flex items-center text-[12px] font-medium px-2.5 py-0.5 rounded-md bg-gray-100 border border-gray-200" style="color:#333333;">
                                {{ Str::words($job->experience_level, 3, '') }}
                            </span>
                        </div>

                        @if($job->location)
                        <p class="text-[13px] truncate flex items-center gap-1.5" style="color:#333333;">
                            <i class="fas fa-location-dot text-[11px]" style="color:#999;"></i>{{ $job->location }}
                        </p>
                        @endif

                        @if($job->salary)
                        <p class="text-[13px] font-semibold text-emerald-600 flex items-center gap-1.5">
                            <i class="fas fa-money-bill-wave text-emerald-400 text-[11px]"></i>{{ $job->salary }}
                        </p>
                        @else
                        <p class="text-[13px] italic" style="color:#333333;">Salary not disclosed</p>
                        @endif

                        @if($descPreview)
                        <p class="text-[13px] line-clamp-2 leading-relaxed" style="color:#333333;">{{ $descPreview }}</p>
                        @endif

                        <div class="flex items-center justify-between pt-2.5 border-t border-gray-100 mt-auto">
                            <span class="text-[13px] {{ $dlClass }} flex items-center gap-1.5">
                                <i class="fas {{ $dlIcon }} text-[11px]"></i>
                                {{ $dlLabel }}
                            </span>

                            <button type="button"
                                    data-jb-share
                                    wire:click.stop="openShareModal({{ $job->id }})"
                                    class="card-share-btn">
                                <i class="fas fa-share-nodes text-[11px]"></i>
                                <span class="tip">Share</span>
                            </button>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>

            @else
            <div class="flex flex-col items-center justify-center gap-4 text-center px-6 py-16 h-full">
                <div class="w-14 h-14 rounded-2xl flex items-center justify-center bg-gray-100">
                    <i class="fas fa-briefcase text-xl text-gray-400"></i>
                </div>
                <div>
                    <p class="font-semibold text-base text-gray-700">
                        @if($search || $filterType || $filterLevel) No jobs match your filters
                        @else No job openings yet @endif
                    </p>
                    <p class="text-sm mt-1 text-gray-500">
                        @if($search || $filterType || $filterLevel) Try clearing your filters to see all available jobs.
                        @else Check back soon — new opportunities will be posted here for <span class="font-medium">{{ $alumniCollege ?: 'your college' }}</span>. @endif
                    </p>
                </div>
                @if($search || $filterType || $filterLevel)
                <button wire:click="resetFilters"
                        class="px-4 py-2 rounded-xl text-sm font-semibold text-white transition uppercase tracking-widest cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72]">
                    Clear Filters
                </button>
                @endif
            </div>
            @endif
        </div>

        {{-- ══ PAGINATION BAR ══ --}}
        @php
            $total   = $this->jobPostings->total();
            $pp      = $this->jobPostings->perPage();
            $cp      = $this->jobPostings->currentPage();
            $lp      = $this->jobPostings->lastPage();
            $from    = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
            $to      = min($cp * $pp, $total);
            $pgStart = max(1, $cp - 2);
            $pgEnd   = min($lp, $cp + 2);
        @endphp
        <div class="flex items-center justify-between gap-2 flex-wrap px-5 min-h-[48px]
                    bg-gradient-to-r from-[#7a3f91] to-[#9b59b6] border-t border-[#7a3f91]/30 flex-shrink-0">

            <p class="text-white/80 text-xs font-normal whitespace-nowrap">
                Showing <strong class="text-white font-bold">{{ $from }}–{{ $to }}</strong>
                of <strong class="text-white font-bold">{{ $total }}</strong>
                {{ $total !== 1 ? 'records' : 'record' }}
            </p>

            <div class="flex items-center gap-1 flex-wrap">
                <button wire:click="previousPage"
                        class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                               bg-white/15 border border-white/25 text-white
                               hover:bg-white/28 hover:border-white/50 disabled:opacity-35 disabled:cursor-not-allowed transition"
                        @if($this->jobPostings->onFirstPage()) disabled @endif
                        aria-label="Previous">
                    <i class="fas fa-chevron-left text-[9px]"></i>
                </button>

                @if($pgStart > 1)
                    <button wire:click="$set('page', 1)"
                            class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                   bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">1</button>
                    @if($pgStart > 2)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
                @endif

                @for($p = $pgStart; $p <= $pgEnd; $p++)
                    @if($p === $cp)
                        <span class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                     bg-white text-[#7a3f91] border border-white">{{ $p }}</span>
                    @else
                        <button wire:click="$set('page', {{ $p }})"
                                class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                       bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">{{ $p }}</button>
                    @endif
                @endfor

                @if($pgEnd < $lp)
                    @if($pgEnd < $lp - 1)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
                    <button wire:click="$set('page', {{ $lp }})"
                            class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                   bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">{{ $lp }}</button>
                @endif

                <button wire:click="nextPage"
                        class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                               bg-white/15 border border-white/25 text-white
                               hover:bg-white/28 hover:border-white/50 disabled:opacity-35 disabled:cursor-not-allowed transition"
                        @if(!$this->jobPostings->hasMorePages()) disabled @endif
                        aria-label="Next">
                    <i class="fas fa-chevron-right text-[9px]"></i>
                </button>

                <span class="hidden sm:inline text-white/60 text-xs font-normal whitespace-nowrap ml-1">
                    Page {{ $cp }}/{{ $lp }}
                </span>
            </div>
        </div>

    </div>{{-- end content-block --}}
</div>


{{-- ══ FULL-SCREEN JOB DETAIL — fixed height, no page scroll ══ --}}
@if($showDetail && $this->viewingJob)
@php
    $job      = $this->viewingJob;
    $dl       = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
    $daysLeft = (int) now('Asia/Manila')->startOfDay()->diffInDays($dl->copy()->startOfDay(), false);

    if ($daysLeft === 0)     $dlLabel = 'Closes today';
    elseif ($daysLeft === 1) $dlLabel = '1 day left';
    else                     $dlLabel = $daysLeft . ' days left';

    $dlIsUrgent = $daysLeft <= 3;
    $dlIsSoon   = !$dlIsUrgent && $daysLeft <= 14;
    $dlValueClass = $dlIsUrgent ? 'text-red-600 font-bold' : ($dlIsSoon ? 'text-orange-700 font-bold' : 'text-gray-900 font-semibold');

    $isUrgent    = $daysLeft <= 7;
    $createdPH   = \Carbon\Carbon::parse($job->created_at)->setTimezone('Asia/Manila');
    $displayType = ($job->company_type === $job->company_name) ? 'PHILCST' : $job->company_type;
    $hasQual     = !empty($job->qualifications);
    $hasInstr    = !empty($job->application_instructions);
    $isPhilcst   = $displayType === 'PHILCST';
    $philcstImg  = $isPhilcst ? $this::jobImageUrl($job->job_image ?? null) : null;

    $qualLines = $hasQual
        ? array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $job->qualifications)), fn($l) => $l !== ''))
        : [];
    $instrLines = $hasInstr
        ? array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $job->application_instructions)), fn($l) => $l !== ''))
        : [];
@endphp

<div class="detail-page fixed inset-0 z-[9000] flex flex-col bg-gray-100 overflow-y-auto lg:overflow-hidden"
     @keydown.escape.window="$wire.closeDetail()">

    {{-- Purple top bar --}}
    <div class="flex items-center justify-between px-6 h-[52px] bg-gradient-to-r from-[#7a3f91] to-[#9b59b6] flex-shrink-0 gap-4">

        <div class="flex items-center gap-3 flex-1 min-w-0">
            <div class="w-8 h-8 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-briefcase text-white text-sm"></i>
            </div>
            <span class="detail-header-title">Job Details</span>
        </div>

        <div class="flex items-center gap-1.5 flex-shrink-0">
            <button type="button"
                    wire:click="openShareModal({{ $job->id }})"
                    class="detail-top-btn share-btn"
                    aria-label="Share">
                <i class="fas fa-share-nodes text-[13px] text-white"></i>
                <span class="tip">Share</span>
            </button>
            <button type="button"
                    wire:click="closeDetail"
                    class="detail-top-btn close-btn"
                    aria-label="Close">
                <svg viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M2 2L12 12M12 2L2 12"/>
                </svg>
                <span class="tip">Close</span>
            </button>
        </div>
    </div>

    {{-- Body: fixed-height two-column layout. The overall page never
         scrolls — only the sidebar / main content panels scroll on
         their own if they genuinely have more content than fits. --}}
    <div class="flex-1 lg:min-h-0 flex flex-col lg:flex-row lg:overflow-hidden">

        {{-- LEFT: sidebar — title, badges, and all the "meta" info that
             used to sit stacked at the top now lives here instead. --}}
        <div class="w-full lg:w-[340px] lg:flex-none bg-white border-b lg:border-b-0 lg:border-r border-gray-200 lg:overflow-y-auto lg:scroll-thin flex flex-col">

            @if($isPhilcst)
                <img src="{{ $philcstImg }}" alt="{{ $job->job_title }}"
                     class="w-full h-48 sm:h-56 object-contain bg-[#f5eef9] flex-shrink-0"
                     onerror="this.style.display='none'">
            @endif

            <div class="p-5 flex flex-col gap-4">
                @if($isPhilcst)
                    <span class="philcst-post-ribbon self-start"><i class="fas fa-school text-[10px]"></i> Official PHILCST Posting</span>
                @endif

                <div>
                    <p class="text-[9px] font-bold uppercase tracking-[.16em] mb-1" style="color:#666;">Job Title</p>
                    <h2 class="text-xl font-semibold leading-snug mb-1.5" style="color:#333333;">{{ $job->job_title }}</h2>
                    <p class="text-sm font-semibold uppercase tracking-[.08em]" style="color:#333333;">{{ $job->company_name }}</p>
                </div>

                <div class="flex flex-wrap gap-1.5">
                    @if($displayType)
                        <span class="inline-flex items-center text-xs font-medium px-2.5 py-1 rounded border border-gray-200 bg-white" style="color:#333333;">{{ $displayType }}</span>
                    @endif
                    <span class="inline-flex items-center text-xs font-medium px-2.5 py-1 rounded border border-gray-200 bg-white" style="color:#333333;">{{ $job->employment_type }}</span>
                    <span class="inline-flex items-center text-xs font-medium px-2.5 py-1 rounded border border-gray-200 bg-white" style="color:#333333;">{{ $job->experience_level }}</span>
                    @if($job->target_college)
                        @foreach(explode(',', $job->target_college) as $col)
                            <span class="inline-flex items-center text-xs font-medium px-2.5 py-1 rounded border border-gray-200 bg-white" style="color:#333333;">{{ trim($col) }}</span>
                        @endforeach
                    @endif
                    @if($isUrgent)
                        <span class="inline-flex items-center text-xs font-medium px-2.5 py-1 rounded border border-red-200 bg-white text-red-700">
                            <i class="fas fa-fire mr-1 text-[10px]"></i>{{ $dlLabel }}
                        </span>
                    @endif
                </div>

                <div class="border-t border-gray-100"></div>

                <div class="flex flex-col gap-4">
                    <div class="detail-side-item">
                        <span class="detail-side-icon"><i class="fas fa-building"></i></span>
                        <div class="min-w-0">
                            <p class="detail-side-label">Company</p>
                            <p class="detail-side-value">{{ $job->company_name }}</p>
                        </div>
                    </div>
                    <div class="detail-side-item">
                        <span class="detail-side-icon"><i class="fas fa-location-dot"></i></span>
                        <div class="min-w-0">
                            <p class="detail-side-label">Location</p>
                            <p class="detail-side-value">{{ $job->location ?: '—' }}</p>
                        </div>
                    </div>
                    <div class="detail-side-item">
                        <span class="detail-side-icon"><i class="fas fa-money-bill-wave"></i></span>
                        <div class="min-w-0">
                            <p class="detail-side-label">Salary</p>
                            @if($job->salary)
                                <p class="detail-side-value text-emerald-600">{{ $job->salary }}</p>
                            @else
                                <p class="detail-side-value italic font-normal" style="color:#666;">Not disclosed</p>
                            @endif
                        </div>
                    </div>
                    <div class="detail-side-item">
                        <span class="detail-side-icon"><i class="fas fa-calendar-days"></i></span>
                        <div class="min-w-0">
                            <p class="detail-side-label">Deadline</p>
                            <p class="detail-side-value {{ $dlValueClass }}">{{ $dl->format('M d, Y') }}</p>
                            <p class="text-xs {{ $dlValueClass }} mt-0.5">
                                @if($dlIsUrgent)<i class="fas fa-fire mr-0.5"></i>@endif{{ $dlLabel }}
                            </p>
                        </div>
                    </div>
                    <div class="detail-side-item">
                        <span class="detail-side-icon"><i class="fas fa-clock-rotate-left"></i></span>
                        <div class="min-w-0">
                            <p class="detail-side-label">Posted</p>
                            <p class="detail-side-value">{{ $createdPH->format('M d, Y') }}</p>
                            <p class="text-xs mt-0.5" style="color:#666;">{{ $createdPH->diffForHumans() }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- RIGHT: description / qualifications / how-to-apply --}}
        <div class="flex-1 min-w-0 lg:overflow-y-auto lg:scroll-thin bg-gray-100">
            <div class="max-w-[900px] mx-auto px-5 py-4 pb-8 flex flex-col gap-4">

                @if($isUrgent)
                <div class="bg-red-50 border border-red-200 border-l-4 border-l-red-600 rounded-lg px-4 py-3 text-sm text-gray-900 leading-relaxed">
                    @if($daysLeft === 0) Deadline is <strong class="text-red-600">today</strong>. Apply before it's too late.
                    @elseif($daysLeft === 1) Only <strong class="text-red-600">1 day</strong> left — apply now.
                    @else Only <strong class="text-red-600">{{ $daysLeft }} days</strong> left. Closes {{ $dl->format('F d, Y') }}.
                    @endif
                </div>
                @endif

                @if($isPhilcst)
                    {{-- ═══ PHILCST "OFFICIAL POST" LAYOUT ═══ --}}
                    <div class="philcst-post-card">
                        <div class="px-5 py-4 flex flex-col gap-4">
                            <div>
                                <p class="text-lg font-bold" style="color:#333333;">🎉 WE'RE HIRING: {{ strtoupper($job->job_title) }}</p>
                                <p class="text-sm mt-1 leading-relaxed" style="color:#333333;">
                                    The Philippine College of Science and Technology is looking for passionate, dedicated individuals to join our growing academic community! ✨
                                </p>
                            </div>

                            <div class="pre-wrap text-[15px] leading-relaxed" style="color:#333333;">{{ trim($job->description) }}</div>

                            @if($hasQual)
                            <div>
                                <p class="text-sm font-bold mb-2" style="color:#333333;">📌 Requirements &amp; Qualifications:</p>
                                <ul class="philcst-checklist">
                                    @foreach($qualLines as $line)
                                        <li><span class="chk"><i class="fas fa-check"></i></span><span>{{ $line }}</span></li>
                                    @endforeach
                                </ul>
                            </div>
                            @endif

                            @if($hasInstr)
                            <div class="bg-emerald-50/60 border border-emerald-100 rounded-xl px-4 py-3">
                                <p class="text-sm font-bold text-emerald-800 mb-2">📝 How to Apply:</p>
                                <ul class="philcst-checklist">
                                    @foreach($instrLines as $line)
                                        <li><span class="chk" style="background:#d1fae5;color:#047857;"><i class="fas fa-arrow-right"></i></span><span>{{ $line }}</span></li>
                                    @endforeach
                                </ul>
                            </div>
                            @endif

                            <p class="text-sm font-semibold" style="color:#333333;">
                                📅 Deadline: {{ $dl->format('F d, Y') }} &nbsp;•&nbsp; 🏫 For: {{ $job->target_college ?: 'All Colleges' }}
                            </p>
                        </div>
                    </div>
                @else
                    <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                        <div class="px-5 py-3 border-b border-gray-100 bg-gray-50">
                            <span class="text-[9px] font-bold uppercase tracking-[.14em] detail-label" style="color:#333333;">Job Description</span>
                        </div>
                        <div class="px-5 py-4 text-[15px] leading-relaxed pre-wrap" style="color:#333333;">{{ $job->description }}</div>
                    </div>

                    @if($hasQual || $hasInstr)
                    <div class="{{ ($hasQual && $hasInstr) ? 'grid grid-cols-1 md:grid-cols-2 gap-4' : '' }}">
                        @if($hasQual)
                        <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                            <div class="px-5 py-3 border-b border-gray-100 bg-gray-50">
                                <span class="text-[9px] font-bold uppercase tracking-[.14em] detail-label" style="color:#333333;">Qualifications</span>
                            </div>
                            <div class="px-5 py-4 text-[15px] leading-relaxed pre-wrap" style="color:#333333;">{{ $job->qualifications }}</div>
                        </div>
                        @endif
                        @if($hasInstr)
                        <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                            <div class="px-5 py-3 border-b border-gray-100 bg-emerald-50">
                                <span class="text-[9px] font-bold uppercase tracking-[.14em] detail-label text-emerald-700">How to Apply</span>
                            </div>
                            <div class="px-5 py-4 text-[15px] leading-relaxed pre-wrap" style="color:#333333;">{{ $job->application_instructions }}</div>
                        </div>
                        @endif
                    </div>
                    @endif
                @endif

                <p class="text-center text-xs" style="color:#333333;">Posted {{ $createdPH->format('M d, Y \a\t g:i A') }}</p>
            </div>
        </div>

    </div>

</div>
@endif


{{-- ══ SHARE MODAL — native share sheet first, no copy/paste needed ══ --}}
@if($showShareModal)
@php
    $shareBaseUrl     = $this->jobDetailUrl($shareJobId);
    $shareDlFormatted = $shareDeadline
        ? \Carbon\Carbon::parse($shareDeadline)->setTimezone('Asia/Manila')->format('F d, Y')
        : '';

    $fieldCount = (int)(bool)$shareEmpType + (int)(bool)$shareLocation + (int)(bool)$shareExpLevel + (int)(bool)$shareSalary + (int)(bool)$shareDlFormatted;
    $descLimit  = $fieldCount >= 4 ? 100 : ($fieldCount >= 2 ? 140 : 180);
    $shareDescPreview = mb_strlen($shareDescription) > $descLimit
        ? mb_substr($shareDescription, 0, $descLimit) . '…'
        : $shareDescription;

    // NOTE: this text is meant to be posted directly (via the native share
    // sheet / pasted into Facebook) as the post's own caption — it does
    // NOT include the job link, since the alumni portal isn't deployed
    // publicly yet and a raw "alumniphilcst.com" link would just show up
    // as a dead/unusable link box in the post composer.
    $fbLines   = [];
    $fbLines[] = "🎯 Job Opening: {$shareJobTitle}";
    $fbLines[] = "🏢 {$shareCompany}";
    if ($shareLocation)    $fbLines[] = "📍 {$shareLocation}";
    if ($shareEmpType)     $fbLines[] = "💼 {$shareEmpType}";
    if ($shareExpLevel)    $fbLines[] = "📊 {$shareExpLevel}";
    if ($shareSalary)      $fbLines[] = "💰 {$shareSalary}";
    if ($shareDlFormatted) $fbLines[] = "📅 Deadline: {$shareDlFormatted}";
    if ($shareCollege)     $fbLines[] = "🏫 For: {$shareCollege}";
    $fbLines[] = '';
    $fbLines[] = "Apply now through the PHILCST Alumni Portal 👇";
    $fbPostText = implode("\n", $fbLines);
@endphp

<div class="fixed inset-0 z-[10002] flex items-center justify-center p-4 bg-black/45"
     x-data="{
         copied:false,
         nativeShareSupported: (typeof navigator !== 'undefined' && !!navigator.share),
         shareText: {{ json_encode($fbPostText) }},
         jobTitle:  {{ json_encode($shareJobTitle) }},
         baseUrl:   {{ json_encode($shareBaseUrl) }},
         imageUrl:  {{ json_encode($shareImageUrl) }},
         // Fetches the job photo and turns it into a File so the native
         // share sheet can attach it, same as posting a photo + caption
         // directly — no link required.
         async buildImageFile() {
             if (!this.imageUrl) return null;
             try {
                 const resp = await fetch(this.imageUrl);
                 const blob = await resp.blob();
                 const ext  = (blob.type.split('/')[1] || 'jpg').split('+')[0];
                 return new File([blob], 'job-photo.' + ext, { type: blob.type });
             } catch (e) { return null; }
         },
         async nativeShare() {
             try {
                 const shareData = { title: this.jobTitle, text: this.shareText };
                 const file = await this.buildImageFile();
                 if (file && navigator.canShare && navigator.canShare({ files: [file] })) {
                     shareData.files = [file];
                 }
                 await navigator.share(shareData);
             } catch (e) { /* cancelled by user — nothing to do */ }
         },
         async shareOnFacebook() {
             // No deployed site yet, so we deliberately do NOT pass a `u`
             // link param to Facebook's sharer — that's what was causing
             // the dead 'alumniphilcst.com' link box to show up in the
             // post composer. If this device supports the native share
             // sheet (photo + caption, no link), use that instead since
             // it behaves exactly like a normal FB post.
             if (this.nativeShareSupported) { await this.nativeShare(); return; }
             const w=620,h=520,l=Math.round((screen.width-w)/2),t=Math.round((screen.height-h)/2);
             const url = 'https://www.facebook.com/sharer/sharer.php?quote=' + encodeURIComponent(this.shareText);
             window.open(url,'fb_share','width='+w+',height='+h+',left='+l+',top='+t+',toolbar=0,menubar=0,location=0,status=0,scrollbars=1,resizable=1');
         },
         async shareOnMessenger() {
             if (this.nativeShareSupported) { await this.nativeShare(); return; }
             window.open('https://www.messenger.com/new','_blank','noopener,noreferrer');
         },
         async copyLinkFn() {
             try {
                 if (navigator.clipboard && window.isSecureContext) { await navigator.clipboard.writeText(this.shareText); }
                 else {
                     const ta = document.createElement('textarea');
                     ta.value = this.shareText; ta.setAttribute('readonly','');
                     ta.style.cssText = 'position:fixed;top:-9999px;opacity:0;';
                     document.body.appendChild(ta); ta.focus(); ta.select();
                     document.execCommand('copy'); document.body.removeChild(ta);
                 }
                 this.copied = true; setTimeout(() => this.copied = false, 2500);
             } catch(e) { console.warn('Copy failed', e); }
         }
     }"
     x-transition:enter="transition ease-out duration-150"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     @keydown.escape.window="$wire.closeShareModal()">

    <div class="share-sheet bg-white rounded-2xl w-full max-w-[920px] shadow-xl border border-gray-200 share-modal-wrapper">

        <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100 flex-shrink-0">
            <h2 class="text-sm font-semibold flex items-center gap-2" style="color:#333333;">
                <i class="fas fa-share-nodes text-[#7a3f91] text-xs"></i> Share Job Posting
            </h2>
            <button wire:click="closeShareModal" type="button" class="share-close-btn" aria-label="Close">
                <svg viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M2 2L12 12M12 2L2 12"/>
                </svg>
                <span class="tip">Close</span>
            </button>
        </div>

        <div class="flex flex-col md:flex-row flex-1 min-h-0 overflow-hidden">

            {{-- LEFT: Preview --}}
            <div class="flex-1 min-w-0 px-5 py-4 border-b md:border-b-0 md:border-r border-gray-100 flex flex-col gap-3 overflow-y-auto scroll-thin">
                <p class="text-[10px] font-bold uppercase tracking-widest flex-shrink-0" style="color:#333333;">Post Preview</p>

                <div class="rounded-xl border border-gray-200 overflow-hidden flex-shrink-0">
                    <div class="border-b border-gray-100 px-4 py-3 bg-gray-50">
                        <p class="font-semibold leading-tight" style="font-size:clamp(12px,1.2vw,14px);color:#333333;">{{ $shareJobTitle }}</p>
                        <p class="font-medium mt-0.5" style="font-size:clamp(10px,1vw,12px);color:#333333;">{{ $shareCompany }}</p>
                        <div class="flex flex-wrap gap-1 mt-1.5">
                            @if($shareEmpType)     <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-gray-100" style="font-size:clamp(9px,0.85vw,11px);color:#333333;">{{ $shareEmpType }}</span> @endif
                            @if($shareLocation)    <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-gray-100" style="font-size:clamp(9px,0.85vw,11px);color:#333333;">{{ $shareLocation }}</span> @endif
                            @if($shareExpLevel)    <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-gray-100" style="font-size:clamp(9px,0.85vw,11px);color:#333333;">{{ $shareExpLevel }}</span> @endif
                            @if($shareSalary)      <span class="inline-flex items-center px-1.5 py-0.5 rounded text-emerald-700 bg-emerald-50" style="font-size:clamp(9px,0.85vw,11px);">{{ $shareSalary }}</span> @endif
                            @if($shareDlFormatted) <span class="inline-flex items-center px-1.5 py-0.5 rounded text-red-600 bg-red-50" style="font-size:clamp(9px,0.85vw,11px);">Deadline: {{ $shareDlFormatted }}</span> @endif
                        </div>
                    </div>
                    @if($shareDescPreview)
                    <div class="px-4 py-2">
                        <p class="leading-relaxed" style="font-size:clamp(10px,0.9vw,12px);color:#333333;">{{ $shareDescPreview }}</p>
                    </div>
                    @endif
                </div>

                <div class="bg-gray-50 border border-gray-200 rounded-xl px-3 py-2.5 flex items-start gap-2.5 flex-shrink-0">
                    <i class="fas fa-circle-info text-xs flex-shrink-0 mt-0.5" style="color:#333333;"></i>
                    <p class="text-xs leading-relaxed" style="color:#333333;">
                        Sharing sends the job's photo and caption straight into the post —
                        no link needed. Use <strong>Share</strong> to open your device's
                        share sheet and pick Messenger, Facebook, or any app.
                    </p>
                </div>
            </div>

            {{-- RIGHT: Share buttons --}}
            <div class="w-full md:w-[280px] flex-shrink-0 px-5 py-4 flex flex-col gap-2.5 overflow-y-auto scroll-thin">
                <p class="text-[10px] font-bold uppercase tracking-widest" style="color:#333333;">Share via</p>

                {{-- Native share sheet — sends title+text+photo directly to
                     Messenger, Facebook, or any app the person picks, no
                     copy/paste step at all. This is the primary option on
                     phones and most modern browsers. --}}
                <template x-if="nativeShareSupported">
                    <button type="button" @click="nativeShare()" class="share-option-btn" style="background:#7a3f91;">
                        <span class="icon-wrap">
                            <i class="fas fa-arrow-up-from-bracket text-[#7a3f91] text-sm"></i>
                        </span>
                        <div class="text-left flex-1">
                            <p class="text-xs font-semibold">Share</p>
                            <p class="text-[10px] text-white/70 mt-0.5">Send photo + caption via Messenger, Facebook, or any app</p>
                        </div>
                    </button>
                </template>

                {{-- Facebook — no link, just the caption; uses native share
                     (photo + text) automatically when supported --}}
                <button type="button" @click="shareOnFacebook()" class="share-option-btn" style="background:#1877F2;">
                    <span class="icon-wrap">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4" fill="#1877F2"><path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.791-4.697 4.532-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/></svg>
                    </span>
                    <div class="text-left flex-1">
                        <p class="text-xs font-semibold">Share on Facebook</p>
                        <p class="text-[10px] text-white/70 mt-0.5">Posts the photo + caption directly</p>
                    </div>
                </button>

                {{-- Messenger --}}
                <button type="button" @click="shareOnMessenger()" class="share-option-btn" style="background:#0084FF;">
                    <span class="icon-wrap">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4" fill="#0084FF">
                            <path d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
                        </svg>
                    </span>
                    <div class="text-left flex-1">
                        <p class="text-xs font-semibold">Send via Messenger</p>
                        <p class="text-[10px] text-white/70 mt-0.5">Opens Messenger to pick a contact</p>
                    </div>
                    <i class="fas fa-arrow-right text-[10px] opacity-70"></i>
                </button>

                {{-- Batch Chat — opens the forward-style destination picker
                     (like Messenger's forward), instead of sending straight away --}}
                <button type="button" wire:click="openForwardModal"
                        class="share-option-btn" style="background:#7a3f91;">
                    <span class="icon-wrap" style="background:rgba(255,255,255,.20);">
                        <i class="fas fa-comments text-white text-sm"></i>
                    </span>
                    <div class="text-left flex-1">
                        <p class="text-xs font-semibold">Share to Batch Chat</p>
                        <p class="text-[10px] text-white/70 mt-0.5">Choose which of your chats to send to</p>
                    </div>
                    <i class="fas fa-arrow-right text-[10px] opacity-70"></i>
                </button>

                <div class="relative my-0.5">
                    <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-gray-200"></div></div>
                    <div class="relative flex justify-center">
                        <span class="px-3 text-[10px] font-semibold uppercase tracking-widest bg-white" style="color:#333333;">or copy caption</span>
                    </div>
                </div>

                <button type="button" @click="copyLinkFn()"
                        class="w-full flex items-center gap-3 px-4 py-2.5 rounded-xl border border-gray-200 hover:border-gray-300
                               hover:bg-gray-50 text-sm transition cursor-pointer bg-white" style="color:#333333;">
                    <span class="w-8 h-8 bg-gray-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <i :class="copied ? 'fas fa-check text-emerald-500' : 'fas fa-copy'" class="text-sm" :style="copied ? '' : 'color:#333333;'"></i>
                    </span>
                    <div class="flex-1 text-left min-w-0">
                        <p class="text-xs font-semibold" :class="copied ? 'text-emerald-600' : ''" :style="copied ? '' : 'color:#333333;'" x-text="copied ? 'Caption copied!' : 'Copy Caption'"></p>
                        <p class="text-[10px] truncate" style="color:#333333;">Copies the post text (photo not included)</p>
                    </div>
                </button>

                <p class="text-[10px] text-center" style="color:#333333;">Sharing is disabled for expired postings.</p>
            </div>
        </div>
    </div>
</div>
@endif


{{-- ══ FORWARD-TO-CHAT MODAL — pick one or both of your chats, Messenger-forward style ══ --}}
@if($showForwardModal)
<div class="fixed inset-0 z-[10003] flex items-center justify-center p-4 bg-black/45"
     @keydown.escape.window="$wire.closeForwardModal()">
    <div class="share-sheet bg-white rounded-2xl w-full max-w-[420px] shadow-xl border border-gray-200 flex flex-col" style="max-height:85vh;">

        <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100 flex-shrink-0">
            <h2 class="text-sm font-semibold flex items-center gap-2" style="color:#333333;">
                <i class="fas fa-paper-plane text-[#7a3f91] text-xs"></i> Send to Chat
            </h2>
            <button wire:click="closeForwardModal" type="button" class="share-close-btn" aria-label="Close">
                <svg viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M2 2L12 12M12 2L2 12"/>
                </svg>
                <span class="tip">Close</span>
            </button>
        </div>

        <div class="px-5 py-3 flex-shrink-0">
            <p class="text-xs" style="color:#333333;">Tap to select — pick one chat, or both to send to everyone at once.</p>
        </div>

        <div class="flex-1 min-h-0 overflow-y-auto scroll-thin px-5 pb-3 flex flex-col gap-2">
            @forelse($alumniChatRooms as $room)
            @php $isSelected = in_array($room['id'], $selectedRoomIds); @endphp
            <button type="button"
                    wire:click="toggleRoomSelection({{ $room['id'] }})"
                    class="w-full flex items-center gap-3 px-3.5 py-3 rounded-xl border transition text-left cursor-pointer
                           {{ $isSelected ? 'border-[#7a3f91] bg-[#f5eef9]' : 'border-gray-200 bg-white hover:bg-gray-50' }}">
                <span class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0
                             {{ $isSelected ? 'bg-[#7a3f91]' : 'bg-gray-100' }}">
                    <i class="fas fa-users text-sm {{ $isSelected ? 'text-white' : 'text-gray-400' }}"></i>
                </span>
                <span class="flex-1 min-w-0">
                    <span class="block text-sm font-semibold truncate" style="color:#333333;">{{ $room['label'] }}</span>
                </span>
                <span class="w-5 h-5 rounded-full border-2 flex items-center justify-center flex-shrink-0
                             {{ $isSelected ? 'bg-[#7a3f91] border-[#7a3f91]' : 'border-gray-300' }}">
                    @if($isSelected)<i class="fas fa-check text-white text-[10px]"></i>@endif
                </span>
            </button>
            @empty
            <p class="text-xs text-center py-6" style="color:#333333;">No chats found to send to.</p>
            @endforelse
        </div>

        <div class="px-5 py-3.5 border-t border-gray-100 flex-shrink-0 flex items-center gap-2">
            <button type="button" wire:click="closeForwardModal"
                    class="flex-1 px-4 py-2.5 rounded-xl border border-gray-200 text-xs font-semibold hover:bg-gray-50 transition cursor-pointer" style="color:#333333;">
                Cancel
            </button>
            <button type="button" wire:click="confirmSendToChat"
                    wire:loading.attr="disabled"
                    wire:target="confirmSendToChat"
                    @if(empty($selectedRoomIds)) disabled @endif
                    class="flex-1 px-4 py-2.5 rounded-xl text-xs font-semibold text-white transition cursor-pointer
                           bg-[#7a3f91] hover:bg-[#5e2f72] disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-1.5">
                <span wire:loading.remove wire:target="confirmSendToChat">
                    <i class="fas fa-paper-plane text-[11px]"></i>
                    Send{{ count($selectedRoomIds) > 1 ? ' to Both' : '' }}
                </span>
                <span wire:loading wire:target="confirmSendToChat">
                    <i class="fas fa-spinner fa-spin text-[11px]"></i> Sending…
                </span>
            </button>
        </div>
    </div>
</div>
@endif

{{-- ── Mouse-following cursor label logic (desktop / mouse devices only) ──
     MOVED inside the root <div> — this used to sit AFTER the root closed,
     which made it a second top-level sibling and triggered Livewire's
     MultipleRootElementsDetectedException. --}}
<script>
(function () {
    function isTouchOrSmall() {
        return window.matchMedia('(max-width: 767px)').matches ||
               (window.matchMedia('(pointer: coarse)').matches);
    }

    function init() {
        const label = document.getElementById('jb-cursor-label');
        if (!label) return;

        if (isTouchOrSmall()) return;

        let activeCard = null;
        let mouseX = 0;
        let mouseY = 0;

        function show() {
            if (isTouchOrSmall()) return;
            label.style.opacity    = '1';
            label.style.visibility = 'visible';
        }

        function hide() {
            label.style.opacity    = '0';
            label.style.visibility = 'hidden';
        }

        function onMouseMove(e) {
            mouseX = e.clientX;
            mouseY = e.clientY;
            label.style.left = (mouseX + 16) + 'px';
            label.style.top  = (mouseY + 14) + 'px';
        }

        function onCardEnter(e) {
            if (e.relatedTarget && e.currentTarget.contains(e.relatedTarget)) return;
            activeCard = e.currentTarget;
            document.addEventListener('mousemove', onMouseMove);
            show();
        }

        function onCardLeave(e) {
            if (e.relatedTarget && e.currentTarget.contains(e.relatedTarget)) return;
            activeCard = null;
            hide();
            document.removeEventListener('mousemove', onMouseMove);
        }

        function onShareEnter() { hide(); }
        function onShareLeave() { if (activeCard) show(); }

        function attachListeners() {
            document.querySelectorAll('[data-jb-card]').forEach(card => {
                if (card._jbBound) return;
                card._jbBound = true;

                card.addEventListener('mouseenter', onCardEnter);
                card.addEventListener('mouseleave', onCardLeave);

                const shareBtn = card.querySelector('[data-jb-share]');
                if (shareBtn) {
                    shareBtn.addEventListener('mouseenter', onShareEnter);
                    shareBtn.addEventListener('mouseleave', onShareLeave);
                }
            });
        }

        attachListeners();

        document.addEventListener('livewire:navigated', () => {
            document.querySelectorAll('[data-jb-card]').forEach(c => { c._jbBound = false; });
            attachListeners();
        });

        if (window.Livewire) {
            window.Livewire.hook('morph.updated', ({ el }) => {
                requestAnimationFrame(() => {
                    document.querySelectorAll('[data-jb-card]').forEach(c => { c._jbBound = false; });
                    attachListeners();
                });
            });
            try {
                window.Livewire.hook('commit', ({ succeed }) => {
                    succeed(() => {
                        requestAnimationFrame(() => {
                            document.querySelectorAll('[data-jb-card]').forEach(c => { c._jbBound = false; });
                            attachListeners();
                        });
                    });
                });
            } catch(e) {}
        }

        document.addEventListener('livewire:update', () => {
            hide();
            activeCard = null;
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>

</div>{{-- end root --}}