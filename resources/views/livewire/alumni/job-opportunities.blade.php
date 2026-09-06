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
    public string $filterSort     = 'recent';

    public bool $showDetail    = false;
    public ?int $viewingJobId  = null;
    public bool $deepLinkedJob = false;

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
    public array  $sentRoomIds      = []; // rooms already sent to in this modal session — keeps their Send button disabled to prevent spam
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
    public bool   $shareIsPhilcst   = false;

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
            $job = JobPosting::where('id', (int) $jobParam)
                ->where('status', 'ACTIVE')
                ->first(['id', 'deadline']);
            if ($job) {
                $isExpired = $job->deadline
                    && \Illuminate\Support\Carbon::parse($job->deadline)->lt(now('Asia/Manila')->startOfDay());

                // Reset filters so the job is guaranteed to show under the list.
                // If it's already expired, switch to Job History so it doesn't
                // vanish from the list the moment the detail modal is closed.
                $this->search      = '';
                $this->filterType  = $isExpired ? '__job_history' : '';
                $this->filterLevel = '';
                $this->filterSort  = 'recent';

                $this->viewingJobId  = (int) $jobParam;
                $this->showDetail    = true;
                $this->deepLinkedJob = true;
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
        $this->filterSort = 'recent';
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
            });

        // "Job History" shows every posting regardless of deadline, so
        // expired postings remain visible with an Expired badge. All other
        // filter selections (including "All Types") keep the normal
        // still-open-only behavior.
        if ($this->filterType !== '__job_history') {
            $q->where('deadline', '>=', $today);
        }

        if ($this->search !== '') {
            $s = strip_tags(trim($this->search));
            $q->where(fn($sub) =>
                $sub->where('job_title',     'like', "%{$s}%")
                    ->orWhere('company_name', 'like', "%{$s}%")
                    ->orWhere('location',     'like', "%{$s}%")
            );
        }

        if ($this->filterType !== '' && $this->filterType !== '__job_history') {
            $q->where('employment_type', $this->filterType);
        }
        if ($this->filterLevel !== '') $q->where('experience_level', $this->filterLevel);

        // Latest-posted job always leads, regardless of deadline.
        $q->orderBy('created_at', 'desc');

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

        if ($this->deepLinkedJob) {
            $this->resetPage();
            $this->deepLinkedJob = false;
        }
    }

    #[Computed]
    public function viewingJob(): ?JobPosting
    {
        if (!$this->viewingJobId) return null;
        return JobPosting::where('id', $this->viewingJobId)
            ->where('status', 'ACTIVE')
            ->first();
    }

    #[Computed]
    public function viewingJobExpired(): bool
    {
        $job = $this->viewingJob;
        if (!$job || !$job->deadline) return false;
        return \Illuminate\Support\Carbon::parse($job->deadline)->lt(now('Asia/Manila')->startOfDay());
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
        // Same rule used in the detail view: it's an "official PHILCST
        // post" when company_type was left equal to company_name.
        $this->shareIsPhilcst   = ($job->company_type === $job->company_name);
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
        $this->shareIsPhilcst   = false;
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
        $this->sentRoomIds      = [];
        $this->showForwardModal = true;
    }

    public function closeForwardModal(): void
    {
        $this->showForwardModal = false;
        $this->selectedRoomIds  = [];
        $this->sentRoomIds      = [];
    }

    public function toggleRoomSelection(int $roomId): void
    {
        if (in_array($roomId, $this->selectedRoomIds, true)) {
            $this->selectedRoomIds = array_values(array_diff($this->selectedRoomIds, [$roomId]));
        } else {
            $this->selectedRoomIds[] = $roomId;
        }
    }

    // Sends the job post to a single chat room, right from that room's own
    // "Send" button — keeps the modal open afterward so the alumni can
    // still send to the other chat too if they want. Once sent, that
    // room's button stays disabled so it can't be spammed.
    public function sendToRoom(int $roomId): void
    {
        if (in_array($roomId, $this->sentRoomIds, true)) {
            return; // already sent — ignore repeat clicks
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

        DB::table('chat_messages')->insert([
            'room_id'     => $roomId,
            'sender_type' => 'alumni',
            'sender_id'   => $this->alumniId,
            'body'        => "@everyone [[JOB:{$this->shareJobId}]]",
            'reply_to_id' => null,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        $this->sentRoomIds[] = $roomId;
        $this->dispatch('flash-message', type: 'success', message: 'Job shared to the chat!');
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
    max-height: 100vh;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
@media (min-width: 640px) {
    .share-modal-wrapper { max-height: 90vh; }
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

/* Simplified share option row — icon + label only (no subtext paragraph) */
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
.share-option-btn .label-text { flex: 1; text-align: left; }

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
@media (max-width: 767px), (hover: none) and (pointer: coarse) {
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

/* ─────────────────────────────────────────────
   SHARE MODAL — image thumbnail preview
───────────────────────────────────────────── */
.share-photo-preview {
    width: 100%;
    height: 140px;
    border-radius: 0.75rem;
    overflow: hidden;
    background: #f3f4f6;
    border: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    position: relative;
}
.share-photo-preview img {
    width: 100%; height: 100%; object-fit: contain;
}
.share-photo-preview .dl-badge {
    position: absolute; bottom: 6px; right: 6px;
    background: rgba(17,24,39,.75); color: #fff;
    font-size: 10px; font-weight: 700; letter-spacing: .03em;
    padding: 3px 8px; border-radius: 999px;
    display: flex; align-items: center; gap: 4px;
    pointer-events: none;
}

/* ─────────────────────────────────────────────
   PRE-SHARE "DOWNLOAD IMAGE?" CONFIRM MODAL
───────────────────────────────────────────── */
.dl-confirm-icon {
    width: 3rem; height: 3rem; border-radius: 0.9rem;
    background: #f5eef9; color: #7a3f91;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem; flex-shrink: 0;
}
.dl-confirm-btn {
    flex: 1; padding: 0.65rem 1rem; border-radius: 0.75rem;
    font-size: 0.8125rem; font-weight: 700; cursor: pointer;
    transition: filter .15s, transform .1s; border: none;
}
.dl-confirm-btn:active { transform: scale(.97); }
.dl-confirm-btn.primary { background: #7a3f91; color: #fff; }
.dl-confirm-btn.primary:hover { filter: brightness(0.95); }
.dl-confirm-btn.secondary { background: #f3f4f6; color: #333333; border: 1px solid #e5e7eb; }
.dl-confirm-btn.secondary:hover { background: #e5e7eb; }
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
                <h1 class="text-xl font-semibold tracking-tight text-gray-900" style="user-select:none;-webkit-user-select:none;">Job Opportunities</h1>
                <p class="text-sm leading-relaxed mt-0.5 text-gray-700" style="user-select:none;-webkit-user-select:none;">
                    Openings available for
                    <span class="font-semibold inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-violet-50 text-violet-700 border border-violet-200">
                        {{ $alumniCollege ?: 'your college' }}
                    </span>
                </p>
            </div>
        </div>
    </div>

    {{-- ══ CONTENT BLOCK ══ --}}
    <div class="flex-1 min-h-0 flex flex-col rounded-xl overflow-hidden border border-[#E8E0F0] shadow-sm relative">

        {{-- ── FILTER BAR ── --}}
        <div class="bg-white border-b border-[#E8E0F0] px-3.5 py-2.5 flex flex-wrap gap-2 items-center flex-shrink-0">

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
                <option value="__job_history" style="background:#7a3f91; color:#ffffff;">Job History</option>
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
                    class="ml-auto inline-flex items-center gap-1.5 px-3 py-[7px] rounded-lg text-xs font-semibold
                           bg-white border border-gray-200 text-gray-600 hover:text-gray-900 hover:border-gray-300
                           transition active:scale-95 cursor-pointer">
                <span wire:loading.remove wire:target="resetFilters">
                    <i class="fas fa-rotate-left text-xs"></i>
                </span>
                <span wire:loading wire:target="resetFilters">
                    <i class="fas fa-spinner fa-spin text-xs" style="color:#7a3f91;"></i>
                </span>
                <span class="hidden sm:inline">Reset</span>
            </button>

        </div>

        {{-- ── CARDS BODY ──
             CHANGED: was `flex-1 min-h-0 overflow-y-auto`, which forced this
             block to stretch and fill 100% of the remaining panel height no
             matter how few job cards there were — that's what pushed the
             pagination bar all the way down to the bottom with a big empty
             gray gap above it (see image 1). Now it just hugs its own
             content and caps out with a max-height (so it still scrolls
             normally when there ARE many results) — pagination sits right
             under the cards instead of far below them. --}}
        <div class="bg-white p-4 relative overflow-y-auto transition-opacity duration-200 flex-1 min-h-0"
             wire:loading.class="opacity-40 pointer-events-none" wire:target="search,filterType,filterLevel,filterSort">

            <div class="hidden absolute inset-0 z-[9999] items-center justify-center pointer-events-none"
                 wire:loading.flex wire:target="search,filterType,filterLevel,filterSort">
                <i class="fas fa-spinner fa-spin" style="font-size:38px; color:#7a3f91;"></i>
            </div>

            @if($this->jobPostings->count() > 0)
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                @foreach($this->jobPostings as $job)
                @php
                    $dl       = \Carbon\Carbon::parse($job->deadline)->setTimezone('Asia/Manila');
                    $today    = now('Asia/Manila')->startOfDay();
                    $daysLeft = (int) $today->diffInDays($dl->copy()->startOfDay(), false);
                    $isExpired = $daysLeft < 0;

                    $descPreview = $job->description ? Str::limit(strip_tags($job->description), 90) : null;
                    $cardImageUrl = $this::jobImageUrl($job->job_image ?? null);
                @endphp

                <div wire:key="job-card-{{ $job->id }}"
                     class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden
                            cursor-pointer relative select-none flex flex-col group
                            {{ $isExpired ? 'opacity-70' : '' }}"
                     data-jb-card
                     wire:click="viewJob({{ $job->id }})"
                     role="button" tabindex="0"
                     onkeypress="if(event.key==='Enter')this.click()">

                    <div class="relative w-full h-40 bg-gray-50 flex-shrink-0 overflow-hidden pointer-events-none">
                        <img src="{{ $cardImageUrl }}" alt="{{ $job->job_title }}"
                             loading="lazy"
                             class="w-full h-full object-contain"
                             onerror="this.onerror=null;this.src='{{ asset('storage/job/default-photo-job.jpg') }}';">
                        @if($isExpired)
                            <span class="absolute top-2.5 right-2.5 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wide bg-gray-700/90 text-white">
                                <i class="fas fa-ban text-[9px]"></i> Expired
                            </span>
                        @endif
                    </div>

                    <div class="flex flex-col flex-1 p-4 gap-2.5">

                        <h3 class="font-semibold text-[15px] leading-snug line-clamp-2" style="color:#333333;">{{ $job->job_title }}</h3>

                        @if($descPreview)
                        <p class="text-[13px] line-clamp-2 leading-relaxed" style="color:#333333;">{{ $descPreview }}</p>
                        @endif

                        <div class="flex items-center justify-end pt-2.5 border-t border-gray-100 mt-auto">
                            <button type="button"
                                    data-jb-share
                                    wire:click.stop="openShareModal({{ $job->id }})"
                                    wire:loading.attr="disabled"
                                    wire:target="openShareModal({{ $job->id }})"
                                    class="card-share-btn">
                                <span wire:loading.remove wire:target="openShareModal({{ $job->id }})">
                                    <i class="fas fa-share-nodes text-[11px]"></i>
                                </span>
                                <span wire:loading wire:target="openShareModal({{ $job->id }})">
                                    <i class="fas fa-spinner fa-spin text-[11px]"></i>
                                </span>
                                <span class="tip">Share</span>
                            </button>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>

            @else
            <div class="flex flex-col items-center justify-center gap-4 text-center px-6 py-16">
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
        <div class="flex items-center justify-between gap-2 flex-wrap px-5 py-2.5 min-h-[48px] mt-auto
                    bg-gradient-to-r from-[#7a3f91] to-[#9b59b6] border-t border-[#7a3f91]/30 flex-shrink-0"
             style="padding-bottom: calc(0.625rem + env(safe-area-inset-bottom, 0px));">

            <p class="text-white/80 text-xs font-normal whitespace-nowrap">
                Showing <strong class="text-white font-bold">{{ $from }}–{{ $to }}</strong>
                of <strong class="text-white font-bold">{{ $total }}</strong>
                {{ $total !== 1 ? 'records' : 'record' }}
            </p>

            <div class="flex items-center gap-1 flex-wrap">
                <button wire:click="previousPage"
                        wire:loading.attr="disabled"
                        wire:target="previousPage,nextPage,page"
                        class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                               bg-white/15 border border-white/25 text-white
                               hover:bg-white/28 hover:border-white/50 disabled:opacity-35 disabled:cursor-not-allowed transition"
                        @if($this->jobPostings->onFirstPage()) disabled @endif
                        aria-label="Previous">
                    <span wire:loading.remove wire:target="previousPage"><i class="fas fa-chevron-left text-[9px]"></i></span>
                    <span wire:loading wire:target="previousPage"><i class="fas fa-spinner fa-spin text-[9px]"></i></span>
                </button>

                @if($pgStart > 1)
                    <button wire:click="$set('page', 1)"
                            wire:loading.attr="disabled"
                            wire:target="previousPage,nextPage,page"
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
                                wire:loading.attr="disabled"
                                wire:target="previousPage,nextPage,page"
                                class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                       bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">{{ $p }}</button>
                    @endif
                @endfor

                @if($pgEnd < $lp)
                    @if($pgEnd < $lp - 1)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
                    <button wire:click="$set('page', {{ $lp }})"
                            wire:loading.attr="disabled"
                            wire:target="previousPage,nextPage,page"
                            class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                   bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">{{ $lp }}</button>
                @endif

                <button wire:click="nextPage"
                        wire:loading.attr="disabled"
                        wire:target="previousPage,nextPage,page"
                        class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                               bg-white/15 border border-white/25 text-white
                               hover:bg-white/28 hover:border-white/50 disabled:opacity-35 disabled:cursor-not-allowed transition"
                        @if(!$this->jobPostings->hasMorePages()) disabled @endif
                        aria-label="Next">
                    <span wire:loading.remove wire:target="nextPage"><i class="fas fa-chevron-right text-[9px]"></i></span>
                    <span wire:loading wire:target="nextPage"><i class="fas fa-spinner fa-spin text-[9px]"></i></span>
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
    $isExpired   = $daysLeft < 0;
    $createdPH   = \Carbon\Carbon::parse($job->created_at)->setTimezone('Asia/Manila');
    $displayType = ($job->company_type === $job->company_name) ? 'PHILCST' : $job->company_type;
    $hasQual     = !empty($job->qualifications);
    $hasInstr    = !empty($job->application_instructions);
    $isPhilcst   = $displayType === 'PHILCST';
    $detailImg   = $this::jobImageUrl($job->job_image ?? null);

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
                    wire:loading.attr="disabled"
                    wire:target="openShareModal({{ $job->id }})"
                    class="detail-top-btn share-btn"
                    aria-label="Share">
                <span wire:loading.remove wire:target="openShareModal({{ $job->id }})">
                    <i class="fas fa-share-nodes text-[13px] text-white"></i>
                </span>
                <span wire:loading wire:target="openShareModal({{ $job->id }})">
                    <i class="fas fa-spinner fa-spin text-[13px] text-white"></i>
                </span>
                <span class="tip">Share</span>
            </button>
            <button type="button"
                    wire:click="closeDetail"
                    wire:loading.attr="disabled"
                    wire:target="closeDetail"
                    class="detail-top-btn close-btn"
                    aria-label="Close">
                <svg viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg" wire:loading.remove wire:target="closeDetail">
                    <path d="M2 2L12 12M12 2L2 12"/>
                </svg>
                <i class="fas fa-spinner fa-spin text-[13px] text-white" wire:loading wire:target="closeDetail"></i>
                <span class="tip">Close</span>
            </button>
        </div>
    </div>

    <div class="flex-1 lg:min-h-0 flex flex-col lg:flex-row lg:overflow-hidden">

        <div class="w-full lg:w-[340px] lg:flex-none bg-white border-b lg:border-b-0 lg:border-r border-gray-200 lg:overflow-y-auto lg:scroll-thin flex flex-col">

            <img src="{{ $detailImg }}" alt="{{ $job->job_title }}"
                 class="w-full h-48 sm:h-56 object-contain flex-shrink-0"
                 onerror="this.onerror=null;this.src='{{ asset('storage/job/default-photo-job.jpg') }}';">

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
                    @if($isExpired)
                        <span class="inline-flex items-center text-xs font-bold px-2.5 py-1 rounded-full bg-gray-100 text-gray-500 border border-gray-200">
                            <i class="fas fa-ban mr-1 text-[10px]"></i>Expired
                        </span>
                    @endif
                    @if($displayType)
                        <span class="inline-flex items-center text-xs font-medium px-2.5 py-1 rounded border border-gray-200 bg-white" style="color:#333333;">{{ $displayType }}</span>
                    @endif
                    <span class="inline-flex items-center text-xs font-medium px-2.5 py-1 rounded border border-gray-200 bg-white" style="color:#333333;">{{ $job->employment_type }}</span>
                    <span class="inline-flex items-center text-xs font-medium px-2.5 py-1 rounded border border-gray-200 bg-white" style="color:#333333;">{{ $job->experience_level }}</span>
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

                            <div>
                                <p class="text-sm font-bold mb-2" style="color:#333333;">📄 Job Description:</p>
                                <div class="pre-wrap text-[15px] leading-relaxed" style="color:#333333;">{{ trim($job->description) }}</div>
                            </div>

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
                        </div>
                    </div>
                @else
                    <div class="bg-white border border-gray-200 rounded-xl px-5 py-4">
                        <p class="text-lg font-bold" style="color:#333333;">🎉 WE'RE HIRING: {{ strtoupper($job->job_title) }}</p>
                        <p class="text-sm mt-1 leading-relaxed" style="color:#333333;">
                            {{ $job->company_name }} is looking for passionate, dedicated individuals to join their growing team! ✨
                        </p>
                    </div>

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


{{-- ══ SHARE MODAL — simplified: icon + label only per option (no
     subtext). Facebook/Messenger no longer auto-download the image —
     instead, a small confirm modal asks the alumni if they want to
     download the photo first (Download / Skip), THEN the Facebook or
     Messenger tab opens. The caption is still auto-copied to clipboard
     either way, since that part isn't disruptive. ══ --}}
@if($showShareModal)
@php
    $shareBaseUrl     = $this->jobDetailUrl($shareJobId);
    $shareDlFormatted = $shareDeadline
        ? \Carbon\Carbon::parse($shareDeadline)->setTimezone('Asia/Manila')->format('F d, Y')
        : '';

    $shareDescPreview = mb_strlen($shareDescription) > 160
        ? mb_substr($shareDescription, 0, 160) . '…'
        : $shareDescription;

    // Qualifications / instructions for the currently-shared job, needed
    // here because the detail-view's $hasQual/$qualLines/$hasInstr/
    // $instrLines only exist inside the @if($showDetail) block above —
    // this is a separate @if scope with its own $job-less context, so we
    // recompute them from the share* properties captured in openShareModal().
    $shareJobModel = \App\Models\JobPosting::find($shareJobId);
    $hasQual  = $shareJobModel && !empty($shareJobModel->qualifications);
    $hasInstr = $shareJobModel && !empty($shareJobModel->application_instructions);

    $qualLines = $hasQual
        ? array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $shareJobModel->qualifications)), fn($l) => $l !== ''))
        : [];
    $instrLines = $hasInstr
        ? array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $shareJobModel->application_instructions)), fn($l) => $l !== ''))
        : [];

    // NOTE: this text is meant to be posted directly (via the native share
    // sheet / pasted into Facebook) as the post's own caption — it does
    // NOT include the job link, since the alumni portal isn't deployed
    // publicly yet and a raw "alumniphilcst.com" link would just show up
    // as a dead/unusable link box in the post composer.
    //
    // SMART CAPTION:
    // - PHILCST posting  → alumni already know it's PHILCST, so the meta
    //   line (company/location/employment/experience/deadline) is skipped
    //   entirely. Goes straight from "WE ARE HIRING" into the description.
    // - Partner-company posting → the meta line IS included (company,
    //   location, employment type, experience level, deadline) right
    //   after the opener, since it's not obviously a PHILCST post and
    //   readers need that context before the description.
    $fbLines   = [];
    $fbLines[] = "WE ARE HIRING: " . strtoupper($shareJobTitle);

    if (! $shareIsPhilcst) {
        $metaBits = [];
        if ($shareCompany)      $metaBits[] = $shareCompany;
        if ($shareLocation)     $metaBits[] = $shareLocation;
        if ($shareEmpType)      $metaBits[] = $shareEmpType;
        if ($shareExpLevel)     $metaBits[] = $shareExpLevel;
        if ($metaBits) {
            $fbLines[] = implode(' • ', $metaBits);
        }
        if ($shareDlFormatted) {
            $fbLines[] = 'Deadline: ' . $shareDlFormatted;
        }
    }

    if (trim($shareDescription) !== '') {
        $fbLines[] = '';
        $fbLines[] = trim($shareDescription);
    }

    if ($hasQual) {
        $fbLines[] = '';
        $fbLines[] = '📌 Requirements & Qualifications:';
        foreach ($qualLines as $line) {
            $fbLines[] = "✅ {$line}";
        }
    }

    if ($hasInstr) {
        $fbLines[] = '';
        $fbLines[] = 'How to Apply:';
        foreach ($instrLines as $line) {
            $fbLines[] = "- {$line}";
        }
    }

    // PHILCST postings skip the meta line up top, so give partner-company
    // postings that already showed the deadline once a plain closing —
    // no need to repeat it again down here for either case.
    $fbLines[] = '';
    $fbLines[] = "Apply now through PHILCST Alumni Connect 💜";
    $fbLines[] = "#YourFutureStarsHere";
    $fbPostText = implode("\n", $fbLines);
@endphp

<div class="fixed inset-0 z-[10002] flex items-center justify-center p-0 sm:p-4 bg-black/45"
     x-data="{
         copied:false,
         nativeShareSupported: (typeof navigator !== 'undefined' && !!navigator.share),
         downloading:false,
         downloaded:false,
         shareText: {{ json_encode($fbPostText) }},
         jobTitle:  {{ json_encode($shareJobTitle) }},
         baseUrl:   {{ json_encode($shareBaseUrl) }},
         imageUrl:  {{ json_encode($shareImageUrl) }},

         // Pre-share confirm modal state
         showDlConfirm: false,
         pendingTarget: null, // 'facebook' | 'messenger'

         async buildImageFile() {
             if (!this.imageUrl) return null;
             try {
                 const resp = await fetch(this.imageUrl);
                 const blob = await resp.blob();
                 const ext  = (blob.type.split('/')[1] || 'jpg').split('+')[0];
                 return new File([blob], 'job-photo.' + ext, { type: blob.type });
             } catch (e) { return null; }
         },

         async autoCopyCaption() {
             try {
                 if (navigator.clipboard && window.isSecureContext) {
                     await navigator.clipboard.writeText(this.shareText);
                 } else {
                     const ta = document.createElement('textarea');
                     ta.value = this.shareText; ta.setAttribute('readonly','');
                     ta.style.cssText = 'position:fixed;top:-9999px;opacity:0;';
                     document.body.appendChild(ta); ta.focus(); ta.select();
                     document.execCommand('copy'); document.body.removeChild(ta);
                 }
                 return true;
             } catch (e) { return false; }
         },

         async downloadImage() {
             if (!this.imageUrl) return false;
             this.downloading = true;
             try {
                 const resp = await fetch(this.imageUrl);
                 const blob = await resp.blob();
                 const ext  = (blob.type.split('/')[1] || 'jpg').split('+')[0];
                 const url  = URL.createObjectURL(blob);
                 const a = document.createElement('a');
                 a.href = url;
                 a.download = 'job-photo.' + ext;
                 document.body.appendChild(a);
                 a.click();
                 document.body.removeChild(a);
                 setTimeout(() => URL.revokeObjectURL(url), 4000);
                 this.downloading = false;
                 this.downloaded  = true;
                 setTimeout(() => this.downloaded = false, 4000);
                 return true;
             } catch (e) {
                 this.downloading = false;
                 return false;
             }
         },

         async nativeShare() {
             try {
                 const shareData = { title: this.jobTitle, text: this.shareText };
                 const file = await this.buildImageFile();
                 if (file && navigator.canShare && navigator.canShare({ files: [file] })) {
                     shareData.files = [file];
                 }
                 await navigator.share(shareData);
             } catch (e) { /* cancelled by user, nothing to do */ }
         },

         // Step 1: user taps Facebook or Messenger -> open the download
         // choice modal first (no auto-download anymore).
         askShare(target) {
             if (this.nativeShareSupported) { this.nativeShare(); return; }
             this.pendingTarget = target;
             this.showDlConfirm = true;
         },

         // Step 2a: user chose to download the image in the confirm modal.
         async confirmDownloadThenGo() {
             await this.downloadImage();
             this.proceedToTarget();
         },

         // Step 2b: user chose to skip the download.
         proceedToTarget() {
             this.showDlConfirm = false;
             const target = this.pendingTarget;
             this.pendingTarget = null;
             if (target === 'facebook') this.openFacebook();
             else if (target === 'messenger') this.openMessenger();
         },

         cancelDlConfirm() {
             this.showDlConfirm = false;
             this.pendingTarget = null;
         },

         // Copies the caption FIRST, while this page still has focus, then
         // opens the Facebook/Messenger window. This order matters: some
         // browsers (Firefox especially) silently fail
         // navigator.clipboard.writeText() once focus has already moved to
         // another window/tab, which left the user's OLD clipboard content
         // in place instead of the caption — that's why the wrong text
         // (page source) was showing up pasted into Facebook's composer.
         // Copying before opening/focusing the popup guarantees the write
         // happens while this document is still the focused one.
         async openFacebook() {
             const copyOk = await this.autoCopyCaption();
             const w=680,h=560,l=Math.round((screen.width-w)/2),t=Math.round((screen.height-h)/2);
             const url = 'https://www.facebook.com/sharer/sharer.php?quote=' + encodeURIComponent(this.shareText);
             const win = window.open(url, 'philcst_fb_share', 'width='+w+',height='+h+',left='+l+',top='+t+',toolbar=0,menubar=0,location=0,status=0,scrollbars=1,resizable=1');
             if (win) { try { win.focus(); } catch(e) {} }
             $wire.dispatch('flash-message', {
                 type: copyOk ? 'success' : 'warning',
                 message: copyOk
                     ? 'Caption copied! Paste it (Ctrl+V) into the Facebook post box that just opened.'
                     : 'Could not copy the caption automatically — use the Copy Caption button below, then paste it into Facebook.'
             });
         },

         async openMessenger() {
             const copyOk = await this.autoCopyCaption();
             const win = window.open('https://www.messenger.com/new', 'philcst_messenger_share', 'noopener,noreferrer');
             if (win) { try { win.focus(); } catch(e) {} }
             $wire.dispatch('flash-message', {
                 type: copyOk ? 'success' : 'warning',
                 message: copyOk
                     ? 'Caption copied! Paste it (Ctrl+V) into Messenger.'
                     : 'Could not copy the caption automatically — use the Copy Caption button below, then paste it into Messenger.'
             });
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
     @keydown.escape.window="if(showDlConfirm){cancelDlConfirm()}else{$wire.closeShareModal()}">

    <div class="share-sheet bg-white w-full h-full sm:h-auto max-w-full sm:max-w-[920px] rounded-none sm:rounded-2xl shadow-xl border-0 sm:border border-gray-200 share-modal-wrapper">

        <div class="flex items-center justify-between px-5 py-3 border-b border-gray-100 flex-shrink-0">
            <h2 class="text-sm font-semibold flex items-center gap-2" style="color:#333333;">
                <i class="fas fa-share-nodes text-[#7a3f91] text-xs"></i> Share Job Posting
            </h2>
            <button wire:click="closeShareModal" wire:loading.attr="disabled" wire:target="closeShareModal" type="button" class="share-close-btn" aria-label="Close">
                <svg viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg" wire:loading.remove wire:target="closeShareModal">
                    <path d="M2 2L12 12M12 2L2 12"/>
                </svg>
                <i class="fas fa-spinner fa-spin text-xs" style="color:#4b5563;" wire:loading wire:target="closeShareModal"></i>
                <span class="tip">Close</span>
            </button>
        </div>

        <div class="flex flex-col md:flex-row md:flex-1 md:min-h-0 overflow-y-auto md:overflow-hidden">

            {{-- LEFT: Preview --}}
            <div class="md:flex-1 min-w-0 px-5 py-4 border-b md:border-b-0 md:border-r border-gray-100 flex flex-col gap-3 md:overflow-y-auto scroll-thin">
                <p class="text-[10px] font-bold uppercase tracking-widest flex-shrink-0" style="color:#333333;">Post Preview</p>

                @if($shareImageUrl)
                <div class="share-photo-preview">
                    <img src="{{ $shareImageUrl }}" alt="{{ $shareJobTitle }}"
                         onerror="this.style.display='none'">
                    <span class="dl-badge" x-show="downloading || downloaded" x-cloak>
                        <i class="fas" :class="downloading ? 'fa-spinner fa-spin' : 'fa-check'"></i>
                        <span x-text="downloading ? 'Downloading…' : 'Downloaded'"></span>
                    </span>
                </div>
                @endif

                <div class="rounded-xl border border-gray-200 overflow-hidden flex-shrink-0 relative">
                    <div class="px-4 py-3 overflow-y-auto scroll-thin" style="max-height:140px;">
                        <p class="pre-wrap leading-relaxed" style="font-size:clamp(11px,1vw,13px);color:#333333;">{{ rtrim(preg_replace('/#YourFutureStarsHere\s*$/', '', $fbPostText)) }}</p>
                        <p class="pre-wrap leading-relaxed font-semibold mt-1" style="font-size:clamp(11px,1vw,13px);color:#1877F2;">#YourFutureStarsHere</p>
                    </div>
                    <div class="pointer-events-none absolute bottom-0 left-0 right-0 h-6" style="background:linear-gradient(to bottom, rgba(255,255,255,0), rgba(255,255,255,.95));"></div>
                </div>

                <div class="bg-gray-50 border border-gray-200 rounded-xl px-3 py-2.5 flex items-start gap-2.5 flex-shrink-0">
                    <i class="fas fa-circle-info text-xs flex-shrink-0 mt-0.5" style="color:#333333;"></i>
                    <p class="text-xs leading-relaxed" style="color:#333333;">
                        The caption is copied to your clipboard automatically — just paste it (Ctrl+V)
                        into the Facebook or Messenger window that opens.
                    </p>
                </div>
            </div>

            {{-- RIGHT: Share buttons --}}
            <div class="w-full md:w-[280px] flex-shrink-0 px-5 py-4 flex flex-col gap-2.5 md:overflow-y-auto scroll-thin">
                <p class="text-[10px] font-bold uppercase tracking-widest" style="color:#333333;">Share via</p>

                <template x-if="nativeShareSupported">
                    <button type="button" @click="nativeShare()" class="share-option-btn" style="background:#7a3f91;">
                        <span class="icon-wrap">
                            <i class="fas fa-arrow-up-from-bracket text-[#7a3f91] text-sm"></i>
                        </span>
                        <span class="label-text text-xs font-semibold">Share</span>
                    </button>
                </template>

                <button type="button" @click="askShare('facebook')" class="share-option-btn" style="background:#1877F2;">
                    <span class="icon-wrap">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4" fill="#1877F2"><path d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073C0 18.1 4.388 23.094 10.125 24v-8.437H7.078v-3.49h3.047V9.41c0-3.025 1.791-4.697 4.532-4.697 1.313 0 2.686.236 2.686.236v2.97h-1.514c-1.491 0-1.956.93-1.956 1.886v2.268h3.328l-.532 3.49h-2.796V24C19.612 23.094 24 18.1 24 12.073z"/></svg>
                    </span>
                    <span class="label-text text-xs font-semibold">Share on Facebook</span>
                </button>

                <button type="button" @click="askShare('messenger')" class="share-option-btn" style="background:#0084FF;">
                    <span class="icon-wrap">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" class="w-4 h-4" fill="#0084FF">
                            <path d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.652V24l4.088-2.242c1.092.3 2.246.464 3.443.464 6.627 0 12-4.974 12-11.111S18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26 6.559-6.963 3.13 3.26 5.889-3.26-6.56 6.963z"/>
                        </svg>
                    </span>
                    <span class="label-text text-xs font-semibold">Send via Messenger</span>
                </button>

                <button type="button" wire:click="openForwardModal"
                        class="share-option-btn" style="background:#7a3f91;">
                    <span class="icon-wrap" style="background:rgba(255,255,255,.20);">
                        <i class="fas fa-comments text-white text-sm"></i>
                    </span>
                    <span class="label-text text-xs font-semibold">Share to Batch Chat</span>
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
                    <div class="flex-1 min-w-0 text-left">
                        <p class="text-xs font-semibold" :class="copied ? 'text-emerald-600' : ''" :style="copied ? '' : 'color:#333333;'" x-text="copied ? 'Caption copied!' : 'Copy Caption'"></p>
                        <p class="text-[10px] truncate" style="color:#333333;">Copies the post text (photo not included)</p>
                    </div>
                </button>

                <p class="text-[10px] text-center" style="color:#333333;">Sharing is disabled for expired postings.</p>
            </div>
        </div>
    </div>

    {{-- ── PRE-SHARE "Download the photo?" CONFIRM MODAL ──
         Shown right after tapping Facebook/Messenger, before opening the
         target window. Lets the alumni choose Download or Skip (they may
         already have the photo saved from a previous share). --}}
    <div x-show="showDlConfirm" x-cloak
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         class="fixed inset-0 z-[10010] flex items-center justify-center p-4 bg-black/55"
         @click.self="cancelDlConfirm()">
        <div class="share-sheet bg-white w-full max-w-[360px] rounded-2xl shadow-xl border border-gray-200 p-5 flex flex-col gap-4">
            <div class="flex items-start gap-3">
                <span class="dl-confirm-icon"><i class="fas fa-image"></i></span>
                <div class="min-w-0 pt-0.5">
                    <p class="text-sm font-semibold" style="color:#333333;">Download the job photo?</p>
                    <p class="text-xs mt-1 leading-relaxed" style="color:#333333;">
                        You'll need to attach a photo to your post. Download it now, or skip if you already have it saved.
                    </p>
                </div>
            </div>

            @if($shareImageUrl)
            <div class="share-photo-preview" style="height:110px;">
                <img src="{{ $shareImageUrl }}" alt="{{ $shareJobTitle }}" onerror="this.style.display='none'">
            </div>
            @endif

            <div class="flex items-center gap-2">
                <button type="button" @click="proceedToTarget()" class="dl-confirm-btn secondary">
                    Skip
                </button>
                <button type="button" @click="confirmDownloadThenGo()" class="dl-confirm-btn primary" :disabled="downloading">
                    <span x-show="!downloading"><i class="fas fa-download mr-1"></i>Download</span>
                    <span x-show="downloading" x-cloak><i class="fas fa-spinner fa-spin mr-1"></i>Downloading…</span>
                </button>
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
            <button wire:click="closeForwardModal" wire:loading.attr="disabled" wire:target="closeForwardModal" type="button" class="share-close-btn" aria-label="Close">
                <svg viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg" wire:loading.remove wire:target="closeForwardModal">
                    <path d="M2 2L12 12M12 2L2 12"/>
                </svg>
                <i class="fas fa-spinner fa-spin text-xs" style="color:#4b5563;" wire:loading wire:target="closeForwardModal"></i>
                <span class="tip">Close</span>
            </button>
        </div>

        <div class="px-5 py-3 flex-shrink-0">
            <p class="text-xs" style="color:#333333;">Tap Send on a chat to share this job posting there.</p>
        </div>

        <div class="flex-1 min-h-0 overflow-y-auto scroll-thin px-5 pb-3 flex flex-col gap-2">
            @forelse($alumniChatRooms as $room)
            @php $isSent = in_array($room['id'], $sentRoomIds); @endphp
            <div class="w-full flex items-center gap-3 px-3.5 py-3 rounded-xl border border-gray-200 bg-white">
                <span class="w-9 h-9 rounded-full flex items-center justify-center flex-shrink-0 bg-gray-100">
                    <i class="fas fa-users text-sm text-gray-400"></i>
                </span>
                <span class="flex-1 min-w-0">
                    <span class="block text-sm font-semibold truncate" style="color:#333333;">{{ $room['label'] }}</span>
                </span>

                <button type="button"
                        wire:click="sendToRoom({{ $room['id'] }})"
                        wire:loading.attr="disabled"
                        wire:target="sendToRoom({{ $room['id'] }})"
                        @if($isSent) disabled @endif
                        class="px-3.5 py-1.5 rounded-lg text-xs font-semibold transition flex-shrink-0
                               disabled:cursor-not-allowed flex items-center justify-center gap-1.5
                               {{ $isSent ? 'bg-gray-100 text-gray-400' : 'text-white bg-[#7a3f91] hover:bg-[#5e2f72] cursor-pointer disabled:opacity-60 disabled:cursor-wait' }}">
                    @if($isSent)
                        <i class="fas fa-check text-[10px]"></i> Sent
                    @else
                        <span wire:loading.remove wire:target="sendToRoom({{ $room['id'] }})">
                            <i class="fas fa-paper-plane text-[10px]"></i> Send
                        </span>
                        <span wire:loading wire:target="sendToRoom({{ $room['id'] }})">
                            <i class="fas fa-spinner fa-spin text-[10px]"></i> Sending…
                        </span>
                    @endif
                </button>
            </div>
            @empty
            <p class="text-xs text-center py-6" style="color:#333333;">No chats found to send to.</p>
            @endforelse
        </div>

        <div class="px-5 py-3.5 border-t border-gray-100 flex-shrink-0">
            <button type="button" wire:click="closeForwardModal"
                    wire:loading.attr="disabled"
                    wire:target="closeForwardModal"
                    class="w-full px-4 py-2.5 rounded-xl border border-gray-200 text-xs font-semibold hover:bg-gray-50 transition cursor-pointer disabled:opacity-50 flex items-center justify-center gap-1.5" style="color:#333333;">
                <span wire:loading.remove wire:target="closeForwardModal">Cancel</span>
                <span wire:loading wire:target="closeForwardModal"><i class="fas fa-spinner fa-spin text-[11px]"></i></span>
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

        // ─────────────────────────────────────────────────────────────────
        // Rebind pass is coalesced into a single rAF tick per settle.
        //
        // livewire:navigated, morph.updated (fires per morphed element —
        // can be several times for one commit), and commit's succeed
        // callback used to each independently re-run rebind work. Firing
        // that 2-3x back-to-back for the SAME navigation is what produced
        // the visible double-open/flash ("kidyam") when a "View Post" link
        // deep-links straight into the job detail view: the details panel
        // would paint, then visibly re-settle a beat later. One coalesced
        // call per settle = one smooth paint.
        // ─────────────────────────────────────────────────────────────────
        var jbRebindQueued = false;
        function queueRebind() {
            if (jbRebindQueued) return;
            jbRebindQueued = true;
            requestAnimationFrame(() => {
                jbRebindQueued = false;
                document.querySelectorAll('[data-jb-card]').forEach(c => { c._jbBound = false; });
                attachListeners();
            });
        }

        document.addEventListener('livewire:navigated', queueRebind);

        if (window.Livewire) {
            window.Livewire.hook('morph.updated', () => queueRebind());
            try {
                window.Livewire.hook('commit', ({ succeed }) => {
                    succeed(() => queueRebind());
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