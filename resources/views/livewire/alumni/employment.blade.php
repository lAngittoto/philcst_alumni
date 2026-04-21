{{-- resources/views/livewire/alumni/employment.blade.php --}}

<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\Alumni;

new class extends Component {

    // ── UI State ──────────────────────────────────────────────────────────────
    public string $errorMessage   = '';
    public string $successMessage = '';
    public bool   $editing        = false;
    public bool   $hasRecord      = false;
    public bool   $showHistory    = false;
    public int    $alumniId       = 0;
    public int    $trackingId     = 0;
    public array  $history        = [];

    // ── Core Status ───────────────────────────────────────────────────────────
    public string $employment_status = '';

    // ── Employed / Self-Employed Fields ───────────────────────────────────────
    public string $company_name      = '';
    public string $job_title         = '';
    public string $employment_type   = '';
    public string $work_location     = '';
    public string $date_hired        = '';
    public array  $career_path       = [];
    public string $course_relevance  = '';

    // ── Unemployed Fields ─────────────────────────────────────────────────────
    public string $unemployment_status = '';

    // ── Common ────────────────────────────────────────────────────────────────
    public string $education_status = '';

    protected array $snapshot = [];

    public function mount(): void
    {
        $user = auth()->user();
        if (!$user || $user->role !== 'alumni') {
            $this->redirect(route('login'));
            return;
        }
        $alumni = Alumni::where('user_id', $user->id)->select(['id'])->first();
        if (!$alumni) {
            $this->redirect(route('login'));
            return;
        }
        $this->alumniId = $alumni->id;
        $this->loadRecord();
    }

    protected function loadRecord(): void
    {
        // Only the CURRENT (non-deleted) record is the active one
        $record = DB::table('employment_trackings')
            ->where('alumni_id', $this->alumniId)
            ->whereNull('deleted_at')
            ->latest('created_at')
            ->first();

        if ($record) {
            $this->trackingId          = $record->id;
            $this->employment_status   = $record->employment_status   ?? '';
            $this->company_name        = $record->company_name        ?? '';
            $this->job_title           = $record->job_title           ?? '';
            $this->employment_type     = $record->employment_type     ?? '';
            $this->work_location       = $record->work_location       ?? '';
            $this->date_hired          = $record->date_hired
                ? \Carbon\Carbon::parse($record->date_hired)->format('Y-m-d') : '';
            $this->career_path         = $record->career_path
                ? json_decode($record->career_path, true) : [];
            $this->education_status    = $record->education_status    ?? '';
            $this->course_relevance    = $record->course_relevance    ?? '';
            $this->unemployment_status = $record->unemployment_status ?? '';
            $this->hasRecord           = true;
            $this->editing             = false;
        } else {
            $this->trackingId = 0;
            $this->hasRecord  = false;
            $this->editing    = true;
        }
    }

    // ── Edit / Cancel ─────────────────────────────────────────────────────────

    public function startEditing(): void
    {
        $this->errorMessage = $this->successMessage = '';
        $this->snapshot = [];
        foreach (['employment_status','company_name','job_title','employment_type',
                  'work_location','date_hired','career_path','education_status',
                  'course_relevance','unemployment_status'] as $k) {
            $this->snapshot[$k] = $this->$k;
        }
        $this->editing = true;
    }

    public function cancelEditing(): void
    {
        $this->errorMessage = $this->successMessage = '';
        $this->resetValidation();
        foreach ($this->snapshot as $k => $v) {
            $this->$k = $v;
        }
        $this->editing = false;
    }

    // ── History Modal ─────────────────────────────────────────────────────────

    public function openHistory(): void
    {
        // Fetch ALL records (including soft-deleted) — the full history chain
        $records = DB::table('employment_trackings')
            ->where('alumni_id', $this->alumniId)
            ->orderByDesc('created_at')
            ->get();

        $typeLabels = [
            'full_time'    => 'Full-Time',
            'part_time'    => 'Part-Time',
            'contractual'  => 'Contractual',
            'project_based'=> 'Project-Based',
            'internship'   => 'Internship',
        ];
        $careerLabels = [
            'ofw'                   => 'OFW',
            'freelancer'            => 'Freelancer',
            'entrepreneur'          => 'Entrepreneur',
            'career_shifter'        => 'Career Shifter',
            'industry_professional' => 'Industry Professional',
        ];
        $eduLabels = [
            'none'               => 'None',
            'pursuing_masteral'  => 'Pursuing Masteral',
            'pursuing_doctorate' => 'Pursuing Doctorate',
        ];
        $relLabels = [
            'yes'       => 'Related to Course',
            'no'        => 'Not Related',
            'partially' => 'Partially Related',
        ];
        $unLabels = [
            'seeking_employment' => 'Actively Seeking Employment',
            'not_looking'        => 'Not Currently Looking',
        ];

        $this->history = $records->map(function ($r) use (
            $typeLabels, $careerLabels, $eduLabels, $relLabels, $unLabels
        ) {
            $cp = $r->career_path ? json_decode($r->career_path, true) : [];
            return [
                'id'                  => $r->id,
                'is_current'          => is_null($r->deleted_at),
                'employment_status'   => $r->employment_status   ?? '',
                'company_name'        => $r->company_name        ?? '',
                'job_title'           => $r->job_title           ?? '',
                'employment_type'     => $typeLabels[$r->employment_type ?? ''] ?? '',
                'work_location'       => ucfirst($r->work_location ?? ''),
                'date_hired'          => $r->date_hired
                    ? \Carbon\Carbon::parse($r->date_hired)->format('M j, Y') : '',
                'career_path_labels'  => array_values(array_filter(
                    array_map(fn($v) => $careerLabels[$v] ?? null, $cp)
                )),
                'course_relevance'    => $relLabels[$r->course_relevance ?? ''] ?? '',
                'unemployment_status' => $unLabels[$r->unemployment_status ?? ''] ?? '',
                'education_status'    => $eduLabels[$r->education_status ?? ''] ?? '',
                'submitted_at'        => $r->created_at
                    ? \Carbon\Carbon::parse($r->created_at)->format('F j, Y — g:i A') : '',
                'replaced_at'         => $r->deleted_at
                    ? \Carbon\Carbon::parse($r->deleted_at)->format('F j, Y') : null,
            ];
        })->toArray();

        $this->showHistory = true;
    }

    public function closeHistory(): void
    {
        $this->showHistory = false;
    }

    // ── Reactive: clear irrelevant fields when status switches ────────────────

    public function updatedEmploymentStatus(): void
    {
        if ($this->employment_status === 'unemployed') {
            $this->company_name = $this->job_title = $this->employment_type =
            $this->work_location = $this->date_hired = $this->course_relevance = '';
            $this->career_path = [];
        } else {
            $this->unemployment_status = '';
        }
        $this->resetValidation();
    }

    // ── Save: soft-delete current → insert new (builds history chain) ─────────

    public function saveEmployment(): void
    {
        $this->errorMessage = $this->successMessage = '';
        $this->company_name = strtoupper(trim($this->company_name));
        $this->job_title    = strtoupper(trim($this->job_title));

        $working = in_array($this->employment_status, ['employed', 'self_employed']);

        $rules = [
            'employment_status' => 'required|in:employed,self_employed,unemployed',
            'education_status'  => 'required|in:none,pursuing_masteral,pursuing_doctorate',
        ];
        $msgs = [
            'employment_status.required' => 'Please select your employment status.',
            'education_status.required'  => 'Please select your education status.',
        ];

        if ($working) {
            $rules += [
                'company_name'    => 'required|string|max:255',
                'job_title'       => 'required|string|max:255',
                'employment_type' => 'required|in:full_time,part_time,contractual,project_based,internship',
                'work_location'   => 'required|in:local,abroad',
                'date_hired'      => 'required|date|before_or_equal:today',
                'course_relevance'=> 'required|in:yes,no,partially',
            ];
            $msgs += [
                'company_name.required'    => 'Company / Business name is required.',
                'job_title.required'       => 'Job title / Position is required.',
                'employment_type.required' => 'Please select employment type.',
                'work_location.required'   => 'Please select work location.',
                'date_hired.required'      => 'Date hired is required.',
                'course_relevance.required'=> 'Please indicate if your job is related to your course.',
            ];
        }

        if ($this->employment_status === 'unemployed') {
            $rules['unemployment_status'] = 'required|in:seeking_employment,not_looking';
            $msgs['unemployment_status.required'] = 'Please select your unemployment status.';
        }

        $this->validate($rules, $msgs);

        try {
            $now  = now();
            $data = [
                'alumni_id'           => $this->alumniId,
                'employment_status'   => $this->employment_status,
                'education_status'    => $this->education_status   ?: null,
                'company_name'        => $working ? ($this->company_name     ?: null) : null,
                'job_title'           => $working ? ($this->job_title        ?: null) : null,
                'employment_type'     => $working ? ($this->employment_type  ?: null) : null,
                'work_location'       => $working ? ($this->work_location    ?: null) : null,
                'date_hired'          => $working ? ($this->date_hired       ?: null) : null,
                'career_path'         => $working && count($this->career_path)
                                            ? json_encode(array_values($this->career_path)) : null,
                'course_relevance'    => $working ? ($this->course_relevance  ?: null) : null,
                'unemployment_status' => $this->employment_status === 'unemployed'
                                            ? ($this->unemployment_status ?: null) : null,
                'created_at'          => $now,
                'updated_at'          => $now,
            ];

            DB::transaction(function () use ($data, $now) {
                // Soft-delete the old current record → it becomes history
                if ($this->trackingId) {
                    DB::table('employment_trackings')
                        ->where('id', $this->trackingId)
                        ->update(['deleted_at' => $now, 'updated_at' => $now]);
                }
                // Insert a fresh record → becomes the new current
                $this->trackingId = DB::table('employment_trackings')->insertGetId($data);
            });

            $this->hasRecord      = true;
            $this->editing        = false;
            $this->successMessage = 'Employment information updated successfully!';
            $this->dispatch('employment-updated', alumniId: $this->alumniId);

            Log::info("Employment saved | alumni_id:{$this->alumniId} | status:{$this->employment_status}");

        } catch (\Throwable $e) {
            Log::error('Employment save error: ' . $e->getMessage());
            $this->errorMessage = 'Failed to save. Please try again.';
        }
    }
}; ?>

<div class="space-y-5">

<style>
/* ── Base tokens ─────────────────────────────────────────────────── */
:root {
    --brand:       #7a3f91;
    --brand-light: #f3eef8;
    --brand-mid:   #ede9fe;
}
input[type="text"],input[type="tel"],input[type="search"] {
    text-transform:uppercase; letter-spacing:.03em;
}
.f-edit {
    border:1.5px solid #d1d5db; background:#fff; color:#111827;
    transition:border-color .15s,box-shadow .15s; font-size:1rem;
}
.f-edit:hover { border-color:var(--brand); }
.f-edit:focus { outline:none; border-color:var(--brand); box-shadow:0 0 0 3px rgba(122,63,145,.12); }
.f-edit.err   { border-color:#ef4444; }
.f-view {
    border:1.5px solid #e5e7eb; background:#f9fafb; color:#111827;
    cursor:default; pointer-events:none; font-size:1rem;
}
.r-pill {
    display:inline-flex; align-items:center; gap:8px;
    padding:10px 18px; border:1.5px solid #e5e7eb; border-radius:.75rem;
    cursor:pointer; transition:border-color .15s,background .15s; font-size:1rem;
}
.r-pill:hover                { border-color:var(--brand); background:var(--brand-light); }
.r-pill:has(input:checked)   { border-color:var(--brand); background:var(--brand-light); }
.r-pill input:checked ~ span { color:var(--brand); font-weight:600; }
.c-pill {
    display:inline-flex; align-items:center; gap:8px;
    padding:8px 16px; border:1.5px solid #e5e7eb; border-radius:.75rem;
    cursor:pointer; transition:border-color .15s,background .15s; font-size:.9375rem; white-space:nowrap;
}
.c-pill:hover                { border-color:var(--brand); background:var(--brand-light); }
.c-pill:has(input:checked)   { border-color:var(--brand); background:var(--brand-light); }
.c-pill input:checked ~ span { color:var(--brand); font-weight:600; }
.s-card  { background:#fff; border:1px solid #e5e7eb; border-radius:1rem; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,.06); }
.s-head  { display:flex; align-items:center; gap:10px; padding:12px 20px; border-bottom:1px solid #f3f4f6; background:var(--brand-light); }
.s-icon  { width:36px; height:36px; border-radius:10px; display:flex; align-items:center; justify-content:center; background:var(--brand); flex-shrink:0; }
.s-label { font-size:.8125rem; font-weight:700; letter-spacing:.06em; text-transform:uppercase; color:#6b7280; }
.e-msg   { font-size:.875rem; color:#ef4444; display:flex; align-items:center; gap:4px; margin-top:3px; }
.b-pill  { display:inline-flex; align-items:center; gap:5px; font-size:.875rem; font-weight:600; padding:6px 12px; border-radius:99px; border:1px solid; }

/* ── History Modal ───────────────────────────────────────────────── */
.hist-overlay {
    position:fixed; inset:0; z-index:9999;
    background:rgba(15,10,30,.55); backdrop-filter:blur(5px);
    display:flex; align-items:center; justify-content:center; padding:16px;
    animation:hFadeIn .18s ease;
}
@keyframes hFadeIn { from{opacity:0} to{opacity:1} }
.hist-modal {
    background:#fff; border-radius:1.25rem; width:100%; max-width:700px;
    max-height:88vh; display:flex; flex-direction:column;
    box-shadow:0 28px 72px rgba(122,63,145,.25),0 4px 20px rgba(0,0,0,.14);
    animation:hSlideUp .22s cubic-bezier(.34,1.56,.64,1);
}
@keyframes hSlideUp { from{transform:translateY(30px);opacity:0} to{transform:translateY(0);opacity:1} }
.hist-head {
    display:flex; align-items:center; justify-content:space-between;
    padding:20px 24px 18px; border-bottom:1px solid #f3f4f6; flex-shrink:0;
}
.hist-body { overflow-y:auto; padding:22px 24px; flex:1; }
.hist-foot { padding:16px 24px; border-top:1px solid #f3f4f6; flex-shrink:0; display:flex; justify-content:flex-end; }

/* ── Timeline ────────────────────────────────────────────────────── */
.tl-wrap { position:relative; padding-left:38px; }
.tl-line {
    position:absolute; left:13px; top:14px; bottom:14px; width:2px;
    background:linear-gradient(to bottom,#7a3f91 0%,#e5e7eb 100%);
    border-radius:2px;
}
.tl-entry { position:relative; margin-bottom:20px; }
.tl-entry:last-child { margin-bottom:0; }
.tl-dot {
    position:absolute; left:-38px; top:14px;
    width:28px; height:28px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    font-size:10px; border:3px solid #fff; flex-shrink:0;
}
.tl-dot.cur  { background:var(--brand); box-shadow:0 0 0 2px var(--brand); color:#fff; }
.tl-dot.past { background:#e5e7eb; box-shadow:0 0 0 2px #d1d5db; color:#9ca3af; }
.tl-card {
    background:#fafafa; border:1.5px solid #e5e7eb;
    border-radius:.875rem; padding:16px 18px;
}
.tl-card.tl-cur {
    background:var(--brand-light); border-color:#c4b5d9;
    box-shadow:0 2px 14px rgba(122,63,145,.11);
}
.tl-meta { font-size:.8125rem; color:#9ca3af; font-weight:600; letter-spacing:.04em; margin-bottom:8px; }
.tl-meta.cur-meta { color:var(--brand); }
.hist-empty { text-align:center; padding:40px 0; color:#9ca3af; }
</style>

{{-- ══ PAGE HEADER ══════════════════════════════════════════════════════════ --}}
<div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
    <div>
        <h1 class="text-3xl font-extrabold text-[#2b0d3e] tracking-tight">Employment Tracking</h1>
        <p class="text-sm text-gray-500 mt-0.5">
            Fields marked <span class="text-red-500 font-semibold">*</span> are required.
            Keep your status up to date — the registrar and organizers can view this.
        </p>
    </div>
    <div class="flex items-center gap-2 flex-wrap">
        @if($hasRecord)
            <span class="inline-flex items-center gap-2 bg-emerald-100 border border-emerald-300
                         text-emerald-800 px-4 py-2.5 rounded-xl text-base font-semibold">
                <i class="fa-solid fa-circle-check text-emerald-600"></i> Record Submitted
            </span>
            <button wire:click="openHistory"
                    wire:loading.attr="disabled" wire:target="openHistory"
                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-base font-bold
                           border-2 border-violet-200 bg-white text-violet-700 hover:bg-violet-50
                           active:scale-95 transition-all shadow-sm">
                <span wire:loading.remove wire:target="openHistory">
                    <i class="fa-solid fa-clock-rotate-left"></i> History
                </span>
                <span wire:loading wire:target="openHistory">
                    <i class="fa-solid fa-circle-notch fa-spin"></i> Loading…
                </span>
            </button>
        @else
            <span class="inline-flex items-center gap-2 bg-amber-50 border border-amber-300
                         text-amber-800 px-4 py-2.5 rounded-xl text-base font-semibold">
                <i class="fa-solid fa-triangle-exclamation text-amber-500"></i> No Record Yet
            </span>
        @endif
        @if(!$editing)
            <button wire:click="startEditing"
                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-base font-bold
                           text-white shadow-sm transition hover:opacity-90 active:scale-95"
                    style="background-color:#7a3f91;">
                <i class="fa-solid fa-pen"></i> Update Employment
            </button>
        @endif
    </div>
</div>

{{-- ── Alerts ── --}}
@if($errorMessage)
    <div class="p-4 bg-red-50 border border-red-300 rounded-xl text-red-800 flex items-start gap-2">
        <i class="fa-solid fa-circle-exclamation mt-0.5 text-red-600 text-base flex-shrink-0"></i>
        <p class="text-base font-medium">{{ $errorMessage }}</p>
    </div>
@endif
@if($successMessage)
    <div class="p-4 bg-emerald-50 border border-emerald-300 rounded-xl text-emerald-800 flex items-start gap-2">
        <i class="fa-solid fa-circle-check mt-0.5 text-emerald-600 text-base flex-shrink-0"></i>
        <p class="text-base font-medium">{{ $successMessage }}</p>
    </div>
@endif

{{-- ══ SECTION 1 — EMPLOYMENT STATUS ══════════════════════════════════════ --}}
<div class="s-card">
    <div class="s-head">
        <div class="s-icon"><i class="fa-solid fa-briefcase text-white text-sm"></i></div>
        <div class="flex-1">
            <p class="text-base font-bold text-gray-900">Employment Status</p>
            <p class="text-sm text-gray-500">Your current employment situation</p>
        </div>
        @if(!$editing)
            <span class="inline-flex items-center gap-1.5 text-sm font-semibold px-3 py-2 rounded-full bg-gray-100 text-gray-500">
                <i class="fa-solid fa-eye text-sm"></i> View Only
            </span>
        @endif
    </div>
    <div class="p-4">
        <label class="block s-label mb-3">Current Status @if($editing)<span class="text-red-500">*</span>@endif</label>
        @if($editing)
            <div class="flex flex-wrap gap-3">
                <label class="r-pill">
                    <input wire:model.live="employment_status" type="radio" value="employed" class="w-4 h-4 accent-violet-600">
                    <i class="fa-solid fa-user-tie text-violet-500 text-sm"></i>
                    <span class="font-medium text-gray-700">Employed</span>
                </label>
                <label class="r-pill">
                    <input wire:model.live="employment_status" type="radio" value="self_employed" class="w-4 h-4 accent-blue-600">
                    <i class="fa-solid fa-store text-blue-500 text-sm"></i>
                    <span class="font-medium text-gray-700">Self-Employed</span>
                </label>
                <label class="r-pill">
                    <input wire:model.live="employment_status" type="radio" value="unemployed" class="w-4 h-4 accent-orange-500">
                    <i class="fa-solid fa-magnifying-glass text-orange-400 text-sm"></i>
                    <span class="font-medium text-gray-700">Unemployed</span>
                </label>
            </div>
            @error('employment_status')
                <p class="e-msg mt-2"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>
            @enderror
        @else
            @php
                $sMap = [
                    'employed'      => ['Employed',     'fa-user-tie',       'text-violet-600','bg-violet-50 border-violet-200'],
                    'self_employed' => ['Self-Employed','fa-store',          'text-blue-600',  'bg-blue-50 border-blue-200'],
                    'unemployed'    => ['Unemployed',   'fa-magnifying-glass','text-orange-600','bg-orange-50 border-orange-200'],
                ];
                $s = $sMap[$employment_status] ?? null;
            @endphp
            @if($s)
                <span class="b-pill {{ $s[2] }} {{ $s[3] }}">
                    <i class="fa-solid {{ $s[1] }} text-sm"></i> {{ $s[0] }}
                </span>
            @else
                <span class="text-gray-400 text-base">—</span>
            @endif
        @endif
    </div>
</div>

{{-- ══ SECTION 2 — EMPLOYMENT DETAILS (Employed / Self-Employed only) ═════ --}}
@if($editing && in_array($employment_status, ['employed','self_employed']))
<div class="s-card">
    <div class="s-head">
        <div class="s-icon" style="background:#2563eb;"><i class="fa-solid fa-building text-white text-sm"></i></div>
        <div class="flex-1">
            <p class="text-base font-bold text-gray-900">Employment Details</p>
            <p class="text-sm text-gray-500">Company, position, type &amp; location</p>
        </div>
    </div>
    <div class="p-4 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block s-label mb-1">
                    {{ $employment_status === 'self_employed' ? 'Business Name' : 'Company Name' }}
                    <span class="text-red-500">*</span>
                </label>
                <input wire:model="company_name" type="text"
                       placeholder="{{ $employment_status === 'self_employed' ? 'E.G. ABC TRADING' : 'E.G. JOLLIBEE FOODS CORP.' }}"
                       oninput="this.value=this.value.toUpperCase()"
                       class="w-full px-3 py-3 text-base rounded-xl transition-all f-edit{{ $errors->has('company_name') ? ' err' : '' }}">
                @error('company_name')<p class="e-msg"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block s-label mb-1">Job Title / Position <span class="text-red-500">*</span></label>
                <input wire:model="job_title" type="text" placeholder="E.G. SOFTWARE DEVELOPER"
                       oninput="this.value=this.value.toUpperCase()"
                       class="w-full px-3 py-3 text-base rounded-xl transition-all f-edit{{ $errors->has('job_title') ? ' err' : '' }}">
                @error('job_title')<p class="e-msg"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>@enderror
            </div>
        </div>
        <div>
            <label class="block s-label mb-2">Employment Type <span class="text-red-500">*</span></label>
            <div class="flex flex-wrap gap-2">
                @foreach([
                    'full_time'    => ['Full-Time',     'fa-clock'],
                    'part_time'    => ['Part-Time',     'fa-clock-rotate-left'],
                    'contractual'  => ['Contractual',   'fa-file-contract'],
                    'project_based'=> ['Project-Based', 'fa-diagram-project'],
                    'internship'   => ['Internship',    'fa-graduation-cap'],
                ] as $val => [$lbl, $ico])
                    <label class="r-pill">
                        <input wire:model="employment_type" type="radio" value="{{ $val }}" class="w-4 h-4 accent-violet-600">
                        <i class="fa-solid {{ $ico }} text-violet-400 text-sm"></i>
                        <span class="font-medium text-gray-700">{{ $lbl }}</span>
                    </label>
                @endforeach
            </div>
            @error('employment_type')<p class="e-msg mt-1"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>@enderror
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block s-label mb-2">Work Location <span class="text-red-500">*</span></label>
                <div class="flex gap-2">
                    <label class="r-pill">
                        <input wire:model="work_location" type="radio" value="local" class="w-4 h-4 accent-emerald-600">
                        <i class="fa-solid fa-location-dot text-emerald-500 text-sm"></i>
                        <span class="font-medium text-gray-700">Local</span>
                    </label>
                    <label class="r-pill">
                        <input wire:model="work_location" type="radio" value="abroad" class="w-4 h-4 accent-sky-600">
                        <i class="fa-solid fa-earth-asia text-sky-500 text-sm"></i>
                        <span class="font-medium text-gray-700">Abroad</span>
                    </label>
                </div>
                @error('work_location')<p class="e-msg mt-1"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block s-label mb-2">Date Hired <span class="text-red-500">*</span></label>
                <input wire:model="date_hired" type="date" max="{{ date('Y-m-d') }}"
                       class="w-full px-3 py-3 text-base rounded-xl transition-all f-edit{{ $errors->has('date_hired') ? ' err' : '' }}">
                @error('date_hired')<p class="e-msg"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>@enderror
            </div>
        </div>
    </div>
</div>

{{-- ══ SECTION 3 — CAREER PATH ══════════════════════════════════════════════ --}}
<div class="s-card">
    <div class="s-head">
        <div class="s-icon" style="background:#0891b2;"><i class="fa-solid fa-road text-white text-sm"></i></div>
        <div class="flex-1">
            <p class="text-base font-bold text-gray-900">Career Path</p>
            <p class="text-sm text-gray-500">Select all that apply</p>
        </div>
    </div>
    <div class="p-4">
        <div class="flex flex-wrap gap-2">
            @foreach([
                'ofw'                   => ['OFW',                  'fa-plane'],
                'freelancer'            => ['Freelancer',            'fa-laptop'],
                'entrepreneur'          => ['Entrepreneur',          'fa-lightbulb'],
                'career_shifter'        => ['Career Shifter',        'fa-arrows-rotate'],
                'industry_professional' => ['Industry Professional', 'fa-industry'],
            ] as $val => [$lbl, $ico])
                <label class="c-pill">
                    <input wire:model="career_path" type="checkbox" value="{{ $val }}" class="w-4 h-4 accent-cyan-600">
                    <i class="fa-solid {{ $ico }} text-cyan-500 text-sm"></i>
                    <span class="text-gray-700">{{ $lbl }}</span>
                </label>
            @endforeach
        </div>
        <p class="text-sm text-gray-400 mt-2">
            <i class="fa-solid fa-circle-info text-blue-400 mr-1"></i> Optional — select all that describe your career path.
        </p>
    </div>
</div>

{{-- ══ SECTION 4 — COURSE RELEVANCE ═════════════════════════════════════════ --}}
<div class="s-card">
    <div class="s-head">
        <div class="s-icon" style="background:#7c3aed;"><i class="fa-solid fa-graduation-cap text-white text-sm"></i></div>
        <div class="flex-1">
            <p class="text-base font-bold text-gray-900">Course Relevance</p>
            <p class="text-sm text-gray-500">Is your job related to your college course?</p>
        </div>
    </div>
    <div class="p-4">
        <label class="block s-label mb-2">Job Related to Course? <span class="text-red-500">*</span></label>
        <div class="flex flex-wrap gap-2">
            <label class="r-pill">
                <input wire:model="course_relevance" type="radio" value="yes" class="w-4 h-4 accent-emerald-600">
                <i class="fa-solid fa-check text-emerald-500 text-sm"></i>
                <span class="font-medium text-gray-700">Yes — Related</span>
            </label>
            <label class="r-pill">
                <input wire:model="course_relevance" type="radio" value="no" class="w-4 h-4 accent-red-500">
                <i class="fa-solid fa-xmark text-red-400 text-sm"></i>
                <span class="font-medium text-gray-700">No — Not Related</span>
            </label>
            <label class="r-pill">
                <input wire:model="course_relevance" type="radio" value="partially" class="w-4 h-4 accent-amber-500">
                <i class="fa-solid fa-circle-half-stroke text-amber-400 text-sm"></i>
                <span class="font-medium text-gray-700">Partially</span>
            </label>
        </div>
        @error('course_relevance')<p class="e-msg mt-1"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>@enderror
    </div>
</div>
@endif

{{-- ══ SECTION 5 — UNEMPLOYMENT (unemployed only) ═══════════════════════════ --}}
@if($editing && $employment_status === 'unemployed')
<div class="s-card">
    <div class="s-head">
        <div class="s-icon" style="background:#d97706;"><i class="fa-solid fa-magnifying-glass text-white text-sm"></i></div>
        <div class="flex-1">
            <p class="text-base font-bold text-gray-900">Unemployment Status</p>
            <p class="text-sm text-gray-500">Are you actively looking for work?</p>
        </div>
    </div>
    <div class="p-4">
        <label class="block s-label mb-2">Current Job Search Status <span class="text-red-500">*</span></label>
        <div class="flex flex-wrap gap-2">
            <label class="r-pill">
                <input wire:model="unemployment_status" type="radio" value="seeking_employment" class="w-4 h-4 accent-violet-600">
                <i class="fa-solid fa-person-walking text-violet-500 text-sm"></i>
                <span class="font-medium text-gray-700">Actively Seeking Employment</span>
            </label>
            <label class="r-pill">
                <input wire:model="unemployment_status" type="radio" value="not_looking" class="w-4 h-4 accent-gray-500">
                <i class="fa-solid fa-pause text-gray-400 text-sm"></i>
                <span class="font-medium text-gray-700">Not Currently Looking</span>
            </label>
        </div>
        @error('unemployment_status')<p class="e-msg mt-1"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>@enderror
    </div>
</div>
@endif

{{-- ══ SECTION 6 — EDUCATION STATUS (always shown) ═════════════════════════ --}}
<div class="s-card">
    <div class="s-head">
        <div class="s-icon" style="background:#059669;"><i class="fa-solid fa-book-open text-white text-sm"></i></div>
        <div class="flex-1">
            <p class="text-base font-bold text-gray-900">Further Education</p>
            <p class="text-sm text-gray-500">Are you pursuing a graduate degree?</p>
        </div>
        @if(!$editing)
            <span class="inline-flex items-center gap-1.5 text-sm font-semibold px-3 py-2 rounded-full bg-gray-100 text-gray-500">
                <i class="fa-solid fa-eye text-sm"></i> View Only
            </span>
        @endif
    </div>
    <div class="p-4">
        <label class="block s-label mb-2">
            Graduate Education Status @if($editing)<span class="text-red-500">*</span>@endif
        </label>
        @if($editing)
            <div class="flex flex-wrap gap-2">
                <label class="r-pill">
                    <input wire:model="education_status" type="radio" value="none" class="w-4 h-4 accent-gray-500">
                    <i class="fa-solid fa-minus text-gray-400 text-sm"></i>
                    <span class="font-medium text-gray-700">None</span>
                </label>
                <label class="r-pill">
                    <input wire:model="education_status" type="radio" value="pursuing_masteral" class="w-4 h-4 accent-blue-600">
                    <i class="fa-solid fa-scroll text-blue-500 text-sm"></i>
                    <span class="font-medium text-gray-700">Pursuing Masteral</span>
                </label>
                <label class="r-pill">
                    <input wire:model="education_status" type="radio" value="pursuing_doctorate" class="w-4 h-4 accent-violet-600">
                    <i class="fa-solid fa-hat-wizard text-violet-500 text-sm"></i>
                    <span class="font-medium text-gray-700">Pursuing Doctorate</span>
                </label>
            </div>
            @error('education_status')<p class="e-msg mt-1"><i class="fa-solid fa-circle-exclamation"></i> {{ $message }}</p>@enderror
        @else
            @php
                $eMap = [
                    'none'               => ['None',               'fa-minus',     'text-gray-600',  'bg-gray-100 border-gray-300'],
                    'pursuing_masteral'  => ['Pursuing Masteral',  'fa-scroll',    'text-blue-700',  'bg-blue-50 border-blue-200'],
                    'pursuing_doctorate' => ['Pursuing Doctorate', 'fa-hat-wizard','text-violet-700','bg-violet-50 border-violet-200'],
                ];
                $e = $eMap[$education_status] ?? null;
            @endphp
            @if($e)
                <span class="b-pill {{ $e[2] }} {{ $e[3] }}">
                    <i class="fa-solid {{ $e[1] }} text-sm"></i> {{ $e[0] }}
                </span>
            @else
                <span class="text-gray-400 text-base">—</span>
            @endif
        @endif
    </div>
</div>

{{-- ══ VIEW SUMMARY (not editing, has record) ════════════════════════════════ --}}
@if(!$editing && $hasRecord && in_array($employment_status, ['employed','self_employed']))
<div class="s-card">
    <div class="s-head">
        <div class="s-icon" style="background:#2563eb;"><i class="fa-solid fa-id-badge text-white text-sm"></i></div>
        <div class="flex-1">
            <p class="text-base font-bold text-gray-900">Employment &amp; Career Summary</p>
            <p class="text-sm text-gray-500">Your current submitted details</p>
        </div>
        <span class="inline-flex items-center gap-1.5 text-sm font-semibold px-3 py-2 rounded-full bg-gray-100 text-gray-500">
            <i class="fa-solid fa-eye text-sm"></i> View Only
        </span>
    </div>
    <div class="p-4 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <p class="s-label mb-1">{{ $employment_status === 'self_employed' ? 'Business Name' : 'Company Name' }}</p>
                <div class="px-3 py-3 rounded-xl f-view text-base font-semibold uppercase">{{ $company_name ?: '—' }}</div>
            </div>
            <div>
                <p class="s-label mb-1">Job Title / Position</p>
                <div class="px-3 py-3 rounded-xl f-view text-base font-semibold uppercase">{{ $job_title ?: '—' }}</div>
            </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            @php $tM=['full_time'=>'Full-Time','part_time'=>'Part-Time','contractual'=>'Contractual','project_based'=>'Project-Based','internship'=>'Internship']; @endphp
            <div>
                <p class="s-label mb-1">Employment Type</p>
                <div class="px-3 py-3 rounded-xl f-view text-base font-semibold">{{ $tM[$employment_type] ?? '—' }}</div>
            </div>
            <div>
                <p class="s-label mb-1">Work Location</p>
                <div class="px-3 py-3 rounded-xl f-view text-base font-semibold flex items-center gap-1.5">
                    @if($work_location==='local') <i class="fa-solid fa-location-dot text-emerald-500 text-sm"></i> Local
                    @elseif($work_location==='abroad') <i class="fa-solid fa-earth-asia text-sky-500 text-sm"></i> Abroad
                    @else — @endif
                </div>
            </div>
            <div>
                <p class="s-label mb-1">Date Hired</p>
                <div class="px-3 py-3 rounded-xl f-view text-base font-semibold">
                    {{ $date_hired ? \Carbon\Carbon::parse($date_hired)->format('F j, Y') : '—' }}
                </div>
            </div>
        </div>
        @php $cpL=['ofw'=>'OFW','freelancer'=>'Freelancer','entrepreneur'=>'Entrepreneur','career_shifter'=>'Career Shifter','industry_professional'=>'Industry Professional']; @endphp
        @if(count($career_path))
        <div>
            <p class="s-label mb-2">Career Path</p>
            <div class="flex flex-wrap gap-2">
                @foreach($career_path as $cp)
                    <span class="b-pill text-cyan-700 bg-cyan-50 border-cyan-200">
                        <i class="fa-solid fa-check text-sm"></i> {{ $cpL[$cp] ?? $cp }}
                    </span>
                @endforeach
            </div>
        </div>
        @endif
        <div>
            <p class="s-label mb-1">Job Related to Course?</p>
            @php $rM=['yes'=>['Related to Course','text-emerald-700','bg-emerald-50 border-emerald-200'],'no'=>['Not Related','text-red-700','bg-red-50 border-red-200'],'partially'=>['Partially Related','text-amber-700','bg-amber-50 border-amber-200']]; $r=$rM[$course_relevance]??null; @endphp
            @if($r) <span class="b-pill {{ $r[1] }} {{ $r[2] }}">{{ $r[0] }}</span>
            @else <span class="text-gray-400 text-base">—</span> @endif
        </div>
    </div>
</div>
@elseif(!$editing && $hasRecord && $employment_status === 'unemployed')
<div class="s-card">
    <div class="s-head">
        <div class="s-icon" style="background:#d97706;"><i class="fa-solid fa-magnifying-glass text-white text-sm"></i></div>
        <div class="flex-1">
            <p class="text-base font-bold text-gray-900">Unemployment Details</p>
        </div>
        <span class="inline-flex items-center gap-1.5 text-sm font-semibold px-3 py-2 rounded-full bg-gray-100 text-gray-500">
            <i class="fa-solid fa-eye text-sm"></i> View Only
        </span>
    </div>
    <div class="p-4">
        @php $uM=['seeking_employment'=>['Actively Seeking Employment','text-violet-700','bg-violet-50 border-violet-200'],'not_looking'=>['Not Currently Looking','text-gray-700','bg-gray-100 border-gray-300']]; $u=$uM[$unemployment_status]??null; @endphp
        @if($u) <span class="b-pill {{ $u[1] }} {{ $u[2] }}">{{ $u[0] }}</span>
        @else <span class="text-gray-400 text-base">—</span> @endif
    </div>
</div>
@endif

{{-- ══ ACTION BUTTONS ════════════════════════════════════════════════════════ --}}
@if($editing)
<div class="flex flex-col sm:flex-row gap-3">
    <button wire:click="saveEmployment"
            wire:loading.attr="disabled" wire:target="saveEmployment"
            class="flex-1 text-white py-4 rounded-xl font-bold text-base shadow-md hover:opacity-90
                   disabled:opacity-70 active:scale-[0.98] transition-all flex items-center justify-center gap-2"
            style="background-color:#7a3f91;">
        <span wire:loading.remove wire:target="saveEmployment">
            <i class="fa-solid fa-floppy-disk mr-1.5"></i> Save Employment Info
        </span>
        <span wire:loading wire:target="saveEmployment">
            <i class="fa-solid fa-circle-notch fa-spin mr-1.5"></i> Saving…
        </span>
    </button>
    @if($hasRecord)
    <button wire:click="cancelEditing"
            class="flex-1 py-4 rounded-xl font-bold text-base border-2 border-gray-300 bg-white
                   text-gray-700 hover:bg-gray-100 active:scale-[0.98] transition-all
                   flex items-center justify-center gap-2">
        <i class="fa-solid fa-xmark mr-1.5"></i> Cancel
    </button>
    @endif
</div>
@endif

@if(!$hasRecord && !$editing)
<p class="text-sm text-center text-gray-400 pb-2">
    <i class="fa-solid fa-circle-info mr-1 text-blue-400"></i>
    Click <strong>Update Employment</strong> to submit your employment information.
</p>
@endif

{{-- ══════════════════════════════════════════════════════════════════════════
     EMPLOYMENT HISTORY MODAL
══════════════════════════════════════════════════════════════════════════════ --}}
@if($showHistory)
<div class="hist-overlay" wire:click.self="closeHistory">
    <div class="hist-modal">

        {{-- ── Modal Header ── --}}
        <div class="hist-head">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl flex items-center justify-content-center flex-shrink-0 flex items-center justify-center"
                     style="background:var(--brand);">
                    <i class="fa-solid fa-clock-rotate-left text-white"></i>
                </div>
                <div>
                    <p class="font-extrabold text-gray-900 text-lg leading-tight">Employment History</p>
                    <p class="text-sm text-gray-400 mt-0.5">
                        {{ count($history) }} {{ count($history) === 1 ? 'record' : 'records' }} on file
                        &nbsp;·&nbsp;
                        <span class="text-violet-600 font-semibold">Newest first</span>
                    </p>
                </div>
            </div>
            <button wire:click="closeHistory"
                    class="w-8 h-8 flex items-center justify-center rounded-xl bg-gray-100
                           hover:bg-red-100 text-gray-500 hover:text-red-600 transition-all flex-shrink-0">
                <i class="fa-solid fa-xmark text-sm"></i>
            </button>
        </div>

        {{-- ── Modal Body / Timeline ── --}}
        <div class="hist-body">
            @if(empty($history))
                <div class="hist-empty">
                    <i class="fa-solid fa-folder-open text-4xl mb-3 block text-gray-300"></i>
                    <p class="font-semibold text-gray-400 text-base">No employment records found.</p>
                </div>
            @else
                <div class="tl-wrap">
                    {{-- Vertical line --}}
                    <div class="tl-line"></div>

                    @foreach($history as $idx => $entry)
                    @php
                        $isCur   = $entry['is_current'];
                        $isWork  = in_array($entry['employment_status'], ['employed','self_employed']);
                        $sLabel  = ['employed'=>'Employed','self_employed'=>'Self-Employed','unemployed'=>'Unemployed'][$entry['employment_status']] ?? '';
                        $sBadge  = match($entry['employment_status']) {
                            'employed'      => ['text-violet-700','bg-violet-100 border-violet-200','fa-user-tie'],
                            'self_employed' => ['text-blue-700',  'bg-blue-100 border-blue-200',    'fa-store'],
                            'unemployed'    => ['text-orange-700','bg-orange-100 border-orange-200','fa-magnifying-glass'],
                            default         => ['text-gray-600',  'bg-gray-100 border-gray-300',    'fa-circle'],
                        };
                        $entryNum = count($history) - $idx;
                    @endphp

                    <div class="tl-entry">
                        {{-- Dot --}}
                        <div class="tl-dot {{ $isCur ? 'cur' : 'past' }}">
                            @if($isCur)
                                <i class="fa-solid fa-circle-dot text-[9px]"></i>
                            @else
                                <i class="fa-solid fa-circle text-[7px]"></i>
                            @endif
                        </div>

                        {{-- Card --}}
                        <div class="tl-card {{ $isCur ? 'tl-cur' : '' }}">

                            {{-- ── Card Header ── --}}
                            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2 mb-3">
                                <div class="flex flex-wrap items-center gap-2">
                                    {{-- Status badge --}}
                                    <span class="b-pill {{ $sBadge[0] }} {{ $sBadge[1] }}">
                                        <i class="fa-solid {{ $sBadge[2] }} text-sm"></i>
                                        {{ $sLabel }}
                                    </span>
                                    {{-- Current / Replaced indicator --}}
                                    @if($isCur)
                                        <span class="b-pill text-emerald-700 bg-emerald-100 border-emerald-200">
                                            <i class="fa-solid fa-circle text-[7px] text-emerald-500"></i> Current
                                        </span>
                                    @endif
                                </div>
                                {{-- Entry number --}}
                                <span class="text-sm font-bold text-gray-300 flex-shrink-0">#{{ $entryNum }}</span>
                            </div>

                            {{-- ── Timestamp row ── --}}
                            <div class="flex flex-wrap gap-x-5 gap-y-1 mb-3">
                                <p class="tl-meta {{ $isCur ? 'cur-meta' : '' }}">
                                    <i class="fa-solid fa-calendar-plus mr-1"></i>
                                    Submitted: {{ $entry['submitted_at'] }}
                                </p>
                                @if(!$isCur && $entry['replaced_at'])
                                    <p class="tl-meta">
                                        <i class="fa-solid fa-calendar-xmark mr-1"></i>
                                        Replaced: {{ $entry['replaced_at'] }}
                                    </p>
                                @endif
                            </div>

                            {{-- ── Job / Company ── --}}
                            @if($isWork && ($entry['company_name'] || $entry['job_title']))
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-2 mb-3
                                        p-3 rounded-xl {{ $isCur ? 'bg-white/60' : 'bg-white' }} border border-gray-100">
                                @if($entry['company_name'])
                                <div>
                                    <p class="s-label mb-0.5">
                                        {{ $entry['employment_status'] === 'self_employed' ? 'Business' : 'Company' }}
                                    </p>
                                    <p class="text-base font-extrabold text-gray-800 uppercase tracking-wide">
                                        {{ $entry['company_name'] }}
                                    </p>
                                </div>
                                @endif
                                @if($entry['job_title'])
                                <div>
                                    <p class="s-label mb-0.5">Position</p>
                                    <p class="text-base font-extrabold text-gray-800 uppercase tracking-wide">
                                        {{ $entry['job_title'] }}
                                    </p>
                                </div>
                                @endif
                            </div>
                            @endif

                            {{-- ── Meta badges row ── --}}
                            <div class="flex flex-wrap gap-1.5">
                                @if($isWork && $entry['employment_type'])
                                    <span class="b-pill text-gray-600 bg-gray-100 border-gray-200">
                                        <i class="fa-solid fa-clock text-sm"></i>
                                        {{ $entry['employment_type'] }}
                                    </span>
                                @endif
                                @if($isWork && $entry['work_location'])
                                    @php $lc = $entry['work_location']==='Abroad' ? 'text-sky-700 bg-sky-50 border-sky-200' : 'text-emerald-700 bg-emerald-50 border-emerald-200';
                                         $li = $entry['work_location']==='Abroad' ? 'fa-earth-asia' : 'fa-location-dot'; @endphp
                                    <span class="b-pill {{ $lc }}">
                                        <i class="fa-solid {{ $li }} text-sm"></i>
                                        {{ $entry['work_location'] }}
                                    </span>
                                @endif
                                @if($isWork && $entry['date_hired'])
                                    <span class="b-pill text-gray-600 bg-gray-100 border-gray-200">
                                        <i class="fa-solid fa-calendar-check text-sm"></i>
                                        Hired {{ $entry['date_hired'] }}
                                    </span>
                                @endif
                                @if($isWork && $entry['course_relevance'])
                                    @php $rc = match($entry['course_relevance']) {
                                        'Related to Course' => 'text-emerald-700 bg-emerald-50 border-emerald-200',
                                        'Not Related'       => 'text-red-700 bg-red-50 border-red-200',
                                        default             => 'text-amber-700 bg-amber-50 border-amber-200',
                                    }; @endphp
                                    <span class="b-pill {{ $rc }}">
                                        <i class="fa-solid fa-graduation-cap text-sm"></i>
                                        {{ $entry['course_relevance'] }}
                                    </span>
                                @endif
                                @if(!$isWork && $entry['unemployment_status'])
                                    <span class="b-pill text-orange-700 bg-orange-50 border-orange-200">
                                        <i class="fa-solid fa-person-walking text-sm"></i>
                                        {{ $entry['unemployment_status'] }}
                                    </span>
                                @endif
                                @if($entry['education_status'] && $entry['education_status'] !== 'None')
                                    <span class="b-pill text-blue-700 bg-blue-50 border-blue-200">
                                        <i class="fa-solid fa-scroll text-sm"></i>
                                        {{ $entry['education_status'] }}
                                    </span>
                                @endif
                            </div>

                            {{-- ── Career path tags ── --}}
                            @if($isWork && count($entry['career_path_labels'] ?? []))
                            <div class="flex flex-wrap gap-1.5 mt-2.5 pt-2.5 border-t border-gray-200/60">
                                <span class="s-label self-center mr-1">Path:</span>
                                @foreach($entry['career_path_labels'] as $cp)
                                    <span class="b-pill text-cyan-700 bg-cyan-50 border-cyan-200">
                                        <i class="fa-solid fa-check text-sm"></i> {{ $cp }}
                                    </span>
                                @endforeach
                            </div>
                            @endif

                        </div>{{-- .tl-card --}}
                    </div>{{-- .tl-entry --}}
                    @endforeach
                </div>{{-- .tl-wrap --}}
            @endif
        </div>{{-- .hist-body --}}

        {{-- ── Modal Footer ── --}}
        <div class="hist-foot">
            <button wire:click="closeHistory"
                    class="px-6 py-2.5 rounded-xl font-bold text-base border-2 border-gray-300
                           bg-white text-gray-700 hover:bg-gray-100 active:scale-95 transition-all">
                <i class="fa-solid fa-xmark mr-1.5"></i> Close
            </button>
        </div>

    </div>{{-- .hist-modal --}}
</div>{{-- .hist-overlay --}}
@endif

</div>