<?php
/**
 * FILE: resources/views/livewire/organizer/event-management.blade.php
 */

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use App\Models\OrganizerEvent;
use App\Http\Controllers\OrganizerEventController;
use Illuminate\Support\Facades\Storage;

new class extends Component {
    use WithPagination, WithFileUploads;

    protected string $paginationTheme = 'tailwind';

    public string $search       = '';
    public string $filterStatus = '';
    public string $filterSort   = 'recent';

    public bool   $showFormModal = false;
    public bool   $isEditing     = false;
    public ?int   $editingEventId = null;

    public string $title              = '';
    public string $description        = '';
    public string $event_date         = '';
    public string $start_time         = '';
    public string $end_time           = '';
    public string $venue              = '';
    public string $venue_address      = '';
    public string $target_participants= '';
    public string $batch_year         = '';
    public string $contact_person     = '';
    public string $contact_email      = '';
    public string $contact_phone      = '';
    public string $notes              = '';

    public $photo          = null;
    public ?string $existingPhotoUrl = null;
    public bool   $removePhoto       = false;

    public bool   $showViewModal    = false;
    public ?int   $viewingEventId   = null;
    public bool   $showDeleteModal  = false;
    public ?int   $deleteEventId    = null;
    public string $deleteEventTitle = '';

    public array  $formErrors = [];

    public function updatingSearch()       { $this->resetPage(); }
    public function updatingFilterStatus() { $this->resetPage(); }
    public function updatingFilterSort()   { $this->resetPage(); }

    #[Computed]
    public function events()
    {
        $org = auth()->user()?->organizer;
        if (!$org) return OrganizerEvent::whereRaw('0=1')->paginate(20);

        $q = OrganizerEvent::forOrganizer($org->id)
            ->withCount([
                'rsvps as confirmed_count' => fn($r) => $r->where('response','CONFIRMED'),
                'rsvps as declined_count'  => fn($r) => $r->where('response','DECLINED'),
                'rsvps as tentative_count' => fn($r) => $r->where('response','TENTATIVE'),
            ]);

        if ($this->search !== '') {
            $s = $this->search;
            $q->where(fn($sub) =>
                $sub->where('title', 'like', "%{$s}%")
                    ->orWhere('venue', 'like', "%{$s}%")
            );
        }
        if ($this->filterStatus !== '') $q->where('status', $this->filterStatus);

        $q->orderBy('created_at', $this->filterSort === 'oldest' ? 'asc' : 'desc');
        return $q->paginate(20);
    }

    #[Computed]
    public function viewingEvent(): ?OrganizerEvent
    {
        if (!$this->viewingEventId) return null;
        return OrganizerEvent::withCount([
            'rsvps as confirmed_count' => fn($r) => $r->where('response','CONFIRMED'),
            'rsvps as declined_count'  => fn($r) => $r->where('response','DECLINED'),
            'rsvps as tentative_count' => fn($r) => $r->where('response','TENTATIVE'),
        ])->find($this->viewingEventId);
    }

    public function resetFilters(): void
    {
        $this->search = $this->filterStatus = '';
        $this->filterSort = 'recent';
        $this->resetPage();
    }

    #[Computed]
    public function organizerCollege(): ?string
    {
        $org = auth()->user()?->organizer;
        if (!$org) return null;
        $college = \App\Models\Course::where('college', $org->department)->value('college');
        return $college ?? $org->department ?? null;
    }

    public function openCreateModal(): void
    {
        $this->resetFormFields();
        $this->event_date          = now()->addWeek()->format('Y-m-d');
        $this->contact_person      = auth()->user()?->organizer?->name ?? '';
        $this->contact_email       = auth()->user()?->organizer?->email ?? '';
        $this->target_participants = $this->organizerCollege ?? '';
        $this->batch_year          = '';
        $this->showFormModal       = true;
    }

    public function openEditModal(int $id): void
    {
        $event = app(OrganizerEventController::class)->getEvent($id);

        $this->isEditing        = true;
        $this->editingEventId   = $id;
        $this->title            = $event->title;
        $this->description      = $event->description ?? '';
        $this->event_date       = $event->event_date->format('Y-m-d');
        $this->start_time       = $event->event_date->format('g:i A');
        $this->end_time         = $event->event_end_date?->format('g:i A') ?? '';
        $this->venue            = $event->venue;
        $this->venue_address    = $event->venue_address ?? '';
        $tp = $event->target_participants ?? '';
        $tparts = explode(' · Batch ', $tp, 2);
        $this->target_participants = trim($tparts[0]);
        $this->batch_year          = trim($tparts[1] ?? '');
        $this->contact_person   = $event->contact_person ?? '';
        $this->contact_email    = $event->contact_email ?? '';
        $this->contact_phone    = $event->contact_phone ?? '';
        $this->notes            = $event->notes ?? '';
        $this->existingPhotoUrl = $event->photo_url;
        $this->removePhoto      = false;
        $this->photo            = null;
        $this->formErrors       = [];
        $this->showFormModal    = true;
        $this->showViewModal    = false;
    }

    public function closeFormModal(): void
    {
        $this->showFormModal = false;
        $this->resetFormFields();
    }

    public function saveEvent(): void
    {
        $this->formErrors = [];
        $errors = [];

        if (!trim($this->title))      $errors['title']      = 'Event title is required.';
        if (!trim($this->event_date)) $errors['event_date'] = 'Event date is required.';
        if (!trim($this->start_time))  $errors['start_time']  = 'Start time is required.';
        if (!trim($this->venue))      $errors['venue']      = 'Venue / Location is required.';

        if (!empty($errors)) { $this->formErrors = $errors; return; }

        $data = [
            'title'               => trim($this->title),
            'description'         => trim($this->description) ?: null,
            'event_date'          => $this->event_date . ' ' . $this->start_time,
            'event_end_date'      => ($this->event_date && trim($this->end_time)) ? $this->event_date . ' ' . $this->end_time : null,
            'venue'               => trim($this->venue),
            'venue_address'       => trim($this->venue_address) ?: null,
            'target_participants' => trim($this->target_participants)
                ? (trim($this->batch_year) ? trim($this->target_participants) . ' · Batch ' . trim($this->batch_year) : trim($this->target_participants))
                : null,
            'contact_person'      => trim($this->contact_person) ?: null,
            'contact_email'       => trim($this->contact_email) ?: null,
            'contact_phone'       => trim($this->contact_phone) ?: null,
            'notes'               => trim($this->notes) ?: null,
        ];

        $ctrl  = app(OrganizerEventController::class);
        $photo = $this->photo;

        if ($this->isEditing) {
            if ($this->removePhoto && !$photo) {
                $event = $ctrl->getEvent($this->editingEventId);
                if ($event->photo && $event->photo !== OrganizerEvent::DEFAULT_PHOTO) {
                    Storage::disk('public')->delete($event->photo);
                }
                $data['photo'] = null;
                $event->update($data);
            } else {
                $ctrl->updateEvent($this->editingEventId, $data, $photo ?: null);
            }
            $this->dispatch('flash-message', type: 'success', message: 'Event updated! Resubmitted for admin review.');
        } else {
            $ctrl->createEvent($data, $photo ?: null);
            $this->dispatch('flash-message', type: 'success', message: 'Event submitted for admin approval!');
        }

        $this->showFormModal = false;
        $this->resetFormFields();
    }

    public function viewEvent(int $id): void  { $this->viewingEventId = $id; $this->showViewModal = true; }
    public function closeViewModal(): void    { $this->showViewModal = false; $this->viewingEventId = null; }

    public function confirmDelete(int $id): void
    {
        $event = app(OrganizerEventController::class)->getEvent($id);
        $this->deleteEventId    = $id;
        $this->deleteEventTitle = $event->title;
        $this->showDeleteModal  = true;
    }

    public function executeDelete(): void
    {
        if ($this->deleteEventId) {
            app(OrganizerEventController::class)->deleteEvent($this->deleteEventId);
            $this->dispatch('flash-message', type: 'success', message: "'{$this->deleteEventTitle}' deleted.");
        }
        $this->showDeleteModal  = false;
        $this->deleteEventId    = null;
        $this->deleteEventTitle = '';
        if ($this->showViewModal) { $this->showViewModal = false; $this->viewingEventId = null; }
    }

    public function cancelDelete(): void
    {
        $this->showDeleteModal  = false;
        $this->deleteEventId    = null;
        $this->deleteEventTitle = '';
    }

    private function resetFormFields(): void
    {
        $this->title = $this->description = $this->event_date = $this->start_time = $this->end_time = '';
        $this->venue = $this->venue_address = $this->target_participants = $this->batch_year = '';
        $this->contact_person = $this->contact_email = $this->contact_phone = $this->notes = '';
        $this->photo = null;
        $this->existingPhotoUrl = null;
        $this->removePhoto      = false;
        $this->formErrors       = [];
        $this->editingEventId   = null;
        $this->isEditing        = false;
    }
};
?>

<div class="flex flex-col bg-gradient-to-br from-slate-50 to-slate-50" style="height:90vh;overflow:hidden;">

<style>
    .scrollbar-custom::-webkit-scrollbar{width:6px;height:6px}
    .scrollbar-custom::-webkit-scrollbar-track{background:transparent}
    .scrollbar-custom::-webkit-scrollbar-thumb{background:rgba(122,63,145,.3);border-radius:10px}
    .scrollbar-custom::-webkit-scrollbar-thumb:hover{background:rgba(122,63,145,.6)}
    @keyframes slideInDown{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:translateY(0)}}
    @keyframes modalSlideIn{from{opacity:0;transform:scale(.95) translateY(10px)}to{opacity:1;transform:scale(1) translateY(0)}}
    @keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}
    @keyframes fadeIn{from{opacity:0}to{opacity:1}}
    .modal-animate{animation:modalSlideIn .3s cubic-bezier(.16,1,.3,1)}
    .backdrop-animate{animation:fadeIn .18s ease}
    .spin-icon{animation:spin 1s linear infinite}
    .btn-primary{background:linear-gradient(135deg,#7a3f91,#6a3580);color:white;border:none;transition:background .2s,box-shadow .2s}
    .btn-primary:hover:not(:disabled){background:linear-gradient(135deg,#8b4aa5,#7a3f91);box-shadow:0 4px 14px rgba(122,63,145,.35)}
    .btn-primary:disabled{background:linear-gradient(135deg,#cbd5e1,#94a3b8);cursor:not-allowed}
    .input-focus:focus{border-color:#7a3f91!important;box-shadow:0 0 0 3px rgba(122,63,145,.1)!important;outline:none!important}
    .tbl-container{transition:opacity .2s ease}
    .tbl-loading{opacity:.45;pointer-events:none}
    .form-label{display:block;font-size:.8rem;font-weight:700;color:#374151;margin-bottom:.5rem}
    .form-input{width:100%;padding:.625rem 1rem;border:1.5px solid #d1d5db;border-radius:.5rem;font-size:.875rem;color:#1e293b;background:#fff;transition:border-color .15s,box-shadow .15s}
    .form-input:focus{border-color:#7a3f91!important;box-shadow:0 0 0 3px rgba(122,63,145,.1)!important;outline:none!important}
    .form-input:disabled,.form-input[readonly]{background:#f1f5f9;color:#64748b;cursor:not-allowed}
    .form-error{font-size:.75rem;color:#ef4444;margin-top:.375rem;display:flex;align-items:center;gap:.3rem}
    .field-error{border-color:#ef4444!important;background:#fff8f8!important}
    .field-hint{font-size:.72rem;color:#94a3b8;margin-top:.3rem}

    /* ── Photo upload area ── */
    .photo-upload-area{border:2px dashed #d1d5db;border-radius:10px;padding:28px 20px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s;background:#fafafa}
    .photo-upload-area:hover{border-color:#7a3f91;background:#faf5ff}
    .photo-upload-area.has-preview{border-style:solid;border-color:#7a3f91;background:#faf5ff}

    /* ── Status badges ── */
    .badge-pending{background:#fef9c3;color:#a16207;border:1px solid #fde68a;font-size:11px;font-weight:700;border-radius:4px;padding:3px 10px}
    .badge-approved{background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;font-size:11px;font-weight:700;border-radius:4px;padding:3px 10px}
    .badge-rejected{background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5;font-size:11px;font-weight:700;border-radius:4px;padding:3px 10px}

    /* ── Table action buttons — OUTLINED ── */
    .tbl-btn{display:inline-flex;align-items:center;gap:5px;padding:6px 13px;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;transition:all .15s;border:1.5px solid;background:#fff;font-family:inherit;white-space:nowrap}
    .tbl-btn-view{color:#7a3f91;border-color:#7a3f91}
    .tbl-btn-view:hover{background:#faf5ff}
    .tbl-btn-edit{color:#2557a7;border-color:#2557a7}
    .tbl-btn-edit:hover{background:#eff6ff}
    .tbl-btn-delete{color:#dc2626;border-color:#dc2626}
    .tbl-btn-delete:hover{background:#fff5f5}

    /* ── View modal ── */
    .ev-modal{background:#fff;border-radius:12px;box-shadow:0 16px 56px rgba(0,0,0,.22);display:flex;flex-direction:column;width:900px;max-width:96vw;max-height:92vh;overflow:hidden}
    .ev-header{background:#fff;padding:0;border-bottom:1px solid #ebebeb;flex-shrink:0;position:relative}
    .ev-cover{width:100%;height:420px;object-fit:cover;display:block;background:#f8fafc}
    .ev-header-body{padding:20px 28px 18px}
    .ev-title{font-size:22px;font-weight:700;color:#111;line-height:1.25;margin-bottom:8px}
    .ev-meta-list{list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:8px}
    .ev-meta-item{display:flex;align-items:flex-start;gap:10px;font-size:13.5px;color:#333}
    .ev-meta-icon{width:18px;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px;color:#7a3f91;font-size:12px}
    .ev-body{flex:1;min-height:0;overflow-y:auto;background:#fff}
    .ev-section{padding:20px 28px;border-bottom:1px solid #f0f0f0}
    .ev-section:last-child{border-bottom:none}
    .ev-section-title{font-size:15px;font-weight:700;color:#111;margin-bottom:12px}
    .ev-description{font-size:14px;color:#333;line-height:1.8;white-space:pre-wrap}

    /* ── RSVP summary cards ── */
    .rsvp-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
    .rsvp-card{border-radius:10px;padding:16px;text-align:center;border:1px solid}
    .rsvp-card-confirmed{background:#f0fdf4;border-color:#bbf7d0}
    .rsvp-card-declined{background:#fff1f2;border-color:#fecdd3}
    .rsvp-card-tentative{background:#fffbeb;border-color:#fde68a}
    .rsvp-count{font-size:28px;font-weight:800;line-height:1}
    .rsvp-label{font-size:11.5px;font-weight:600;margin-top:4px;text-transform:uppercase;letter-spacing:.05em}
    .rsvp-icon{font-size:18px;margin-bottom:8px}
    .rsvp-confirmed-color{color:#15803d}
    .rsvp-declined-color{color:#be123c}
    .rsvp-tentative-color{color:#b45309}

    /* ── Review status box ── */
    .review-box{border-radius:8px;padding:14px 16px;border:1.5px solid}
    .review-box-pending{background:#fffbeb;border-color:#fde68a}
    .review-box-approved{background:#f0fdf4;border-color:#bbf7d0}
    .review-box-rejected{background:#fff1f2;border-color:#fecdd3}

    /* ── View modal footer buttons — OUTLINED ── */
    .ev-footer{padding:14px 28px;border-top:1px solid #ebebeb;display:flex;align-items:center;justify-content:flex-end;background:#fff;flex-shrink:0;gap:8px}
    .ev-btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer;transition:all .15s;border:1.5px solid;background:#fff;font-family:inherit}
    .ev-btn-close{color:#374151;border-color:#cbd5e1}
    .ev-btn-close:hover{background:#f8fafc}
    .ev-btn-edit{color:#2557a7;border-color:#2557a7}
    .ev-btn-edit:hover{background:#eff6ff}
    .ev-btn-delete{color:#dc2626;border-color:#dc2626}
    .ev-btn-delete:hover{background:#fff5f5}
    .ev-close-x{position:absolute;top:12px;right:14px;width:30px;height:30px;border-radius:50%;border:none;background:rgba(0,0,0,.35);color:#fff;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .12s;line-height:1;z-index:2}
    .ev-close-x:hover{background:rgba(0,0,0,.55)}

    /* ── Confirm modal buttons — OUTLINED ── */
    .confirm-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 20px;border-radius:10px;font-size:13.5px;font-weight:700;cursor:pointer;transition:all .15s;font-family:inherit;border:1.5px solid;background:#fff;flex:1}
    .confirm-btn-cancel{color:#374151;border-color:#cbd5e1}
    .confirm-btn-cancel:hover{background:#f8fafc}
    .confirm-btn-delete{color:#dc2626;border-color:#dc2626}
    .confirm-btn-delete:hover{background:#fff5f5}

    /* ── RSVP pill tooltips (table) ── */
    .rsvp-pill{position:relative;display:inline-flex;align-items:center;gap:4px;cursor:default}
    .rsvp-pill .rsvp-tip{position:absolute;bottom:calc(100% + 6px);left:50%;transform:translateX(-50%);background:#1e293b;color:#fff;font-size:10.5px;font-weight:600;padding:4px 9px;border-radius:5px;white-space:nowrap;pointer-events:none;opacity:0;transition:opacity .15s;z-index:20}
    .rsvp-pill .rsvp-tip::after{content:'';position:absolute;top:100%;left:50%;transform:translateX(-50%);border:4px solid transparent;border-top-color:#1e293b}
    .rsvp-pill:hover .rsvp-tip{opacity:1}

    /* ── Updated/Deleted by tag ── */
    .updated-by-tag{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;padding:3px 10px;border-radius:3px}
    .updated-by-admin{background:#f5f0ff;color:#6d28d9;border:1px solid #e5d9ff}
    .updated-by-organizer{background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0}
    .deleted-by-tag{display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:600;padding:3px 10px;border-radius:3px;background:#fff7ed;color:#c2410c;border:1px solid #fed7aa}
</style>

{{-- ── FLASH ── --}}
<div x-data="{show:false,type:'success',msg:'',timer:null,display(t,m){this.type=t;this.msg=m;this.show=true;clearTimeout(this.timer);this.timer=setTimeout(()=>this.show=false,4500);}}"
     @flash-message.window="display($event.detail.type,$event.detail.message)"
     x-show="show"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 translate-x-6"
     x-transition:enter-end="opacity-100 translate-x-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100 translate-x-0"
     x-transition:leave-end="opacity-0 translate-x-6"
     class="fixed top-5 right-6 z-[60] flex items-start gap-3 px-6 py-4 rounded-lg shadow-xl max-w-sm border backdrop-blur-sm"
     :class="type==='success'?'bg-emerald-50 border-emerald-200 text-emerald-800':'bg-red-50 border-red-200 text-red-800'"
     style="display:none">
    <i class="fas mt-0.5 text-lg flex-shrink-0" :class="type==='success'?'fa-check-circle text-emerald-500':'fa-exclamation-circle text-red-500'"></i>
    <div class="flex-1 min-w-0">
        <div class="font-semibold text-sm" x-text="type==='success'?'Success':'Error'"></div>
        <div class="text-sm mt-0.5 opacity-90" x-text="msg"></div>
    </div>
    <button @click="show=false" class="opacity-40 hover:opacity-100 transition"><i class="fas fa-times text-sm"></i></button>
</div>

<div class="flex flex-col px-8 pt-7 pb-6" style="height:90vh;overflow:hidden;">

    {{-- PAGE HEADER --}}
    <div class="flex items-center justify-between mb-5 shrink-0" style="animation:slideInDown .5s ease-out;">
        <div class="flex items-center gap-3">
            <div class="w-11 h-11 btn-primary rounded-lg flex items-center justify-center shadow-md shrink-0">
                <i class="fas fa-calendar-days text-base"></i>
            </div>
            <div>
                <h1 class="text-3xl font-bold text-slate-800 leading-tight">My Events</h1>
                <p class="text-sm text-slate-500 mt-0.5">Create and manage your event submissions for admin approval.</p>
            </div>
        </div>
        <button wire:click="openCreateModal"
                class="inline-flex items-center gap-2 px-5 py-3 btn-primary rounded-lg font-semibold text-sm hover:shadow-lg transition-all">
            <i class="fas fa-plus"></i> Create Event
        </button>
    </div>

    {{-- TABLE PANEL --}}
    <div class="bg-white rounded-lg shadow-sm flex flex-col border border-slate-200" style="height:calc(90vh - 160px);">

        {{-- Filters --}}
        <div class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex flex-wrap gap-3 items-center shrink-0">
            <div class="relative flex-1 min-w-[200px] max-w-sm">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm pointer-events-none"></i>
                <input type="text" wire:model.live.debounce.200ms="search"
                       placeholder="Search title, venue..."
                       class="w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus"
                       autocomplete="off">
            </div>
            <select wire:model.live="filterStatus" class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
                <option value="">All Status</option>
                <option value="PENDING">Pending</option>
                <option value="APPROVED">Approved</option>
                <option value="REJECTED">Rejected</option>
            </select>
            <select wire:model.live="filterSort" class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
                <option value="recent">Recent First</option>
                <option value="oldest">Oldest First</option>
            </select>
            <button wire:click="resetFilters" class="px-4 py-2.5 text-slate-700 hover:bg-slate-100 rounded-lg border border-slate-200 transition text-sm font-medium">
                <i class="fas fa-rotate-left mr-2"></i>Reset
            </button>
            <span wire:loading wire:target="search,filterStatus,filterSort,resetFilters">
                <i class="fas fa-spinner spin-icon text-purple-500 text-sm"></i>
            </span>
        </div>

        {{-- Table --}}
        <div class="flex-1 min-h-0 overflow-y-auto overflow-x-auto scrollbar-custom tbl-container"
             wire:loading.class="tbl-loading"
             wire:target="search,filterStatus,filterSort,resetFilters,previousPage,nextPage">
            <table class="w-full border-separate border-spacing-0">
                <thead class="btn-primary text-white" style="position:sticky;top:0;z-index:10;pointer-events:none;">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide w-16">Photo</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Event Title</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Date & Time</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Venue</th>
                        <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wide">RSVPs</th>
                        <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wide">Status</th>
                        <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wide">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($this->events as $event)
                    <tr class="hover:bg-purple-50/30 transition-colors">
                        <td class="px-6 py-3">
                            <img src="{{ $event->photo_url }}" alt="{{ $event->title }}"
                                 class="w-12 h-12 rounded-lg object-cover border border-slate-200 shadow-sm">
                        </td>
                        <td class="px-6 py-4">
                            <p class="font-semibold text-slate-900 text-sm">{{ $event->title }}</p>
                            @if($event->target_participants)
                                <p class="text-xs text-slate-400 mt-0.5">{{ $event->target_participants }}</p>
                            @endif
                        </td>
                        <td class="px-6 py-4">
                            <p class="text-sm font-semibold text-slate-700">{{ $event->event_date->format('M d, Y') }}</p>
                            <p class="text-xs text-slate-400 mt-0.5">
                                {{ $event->event_date->format('g:i A') }}
                                @if($event->event_end_date)<span class="text-slate-300 mx-1">—</span>{{ $event->event_end_date->format('g:i A') }}@endif
                            </p>
                        </td>
                        <td class="px-6 py-4">
                            <p class="text-sm text-slate-700 font-medium">{{ $event->venue }}</p>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <div class="flex items-center justify-center gap-1.5">
                                <span class="rsvp-pill">
                                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-green-50 border border-green-200 text-green-700 text-xs font-bold">
                                        <i class="fas fa-circle-check text-[10px]"></i>{{ $event->confirmed_count }}
                                    </span>
                                    <span class="rsvp-tip">Confirmed</span>
                                </span>
                                <span class="rsvp-pill">
                                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-red-50 border border-red-200 text-red-600 text-xs font-bold">
                                        <i class="fas fa-circle-xmark text-[10px]"></i>{{ $event->declined_count }}
                                    </span>
                                    <span class="rsvp-tip">Not Attending</span>
                                </span>
                                <span class="rsvp-pill">
                                    <span class="inline-flex items-center gap-1 px-2 py-1 rounded-md bg-amber-50 border border-amber-200 text-amber-600 text-xs font-bold">
                                        <i class="fas fa-circle-question text-[10px]"></i>{{ $event->tentative_count }}
                                    </span>
                                    <span class="rsvp-tip">Maybe</span>
                                </span>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-center">
                            @if($event->status === 'PENDING')
                                <span class="badge-pending"><i class="fas fa-hourglass-half mr-1"></i>Pending</span>
                            @elseif($event->status === 'APPROVED')
                                <span class="badge-approved"><i class="fas fa-circle-check mr-1"></i>Approved</span>
                            @else
                                <span class="badge-rejected"><i class="fas fa-circle-xmark mr-1"></i>Rejected</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-center">
                            <div class="flex items-center justify-center gap-1.5">
                                <button wire:click="viewEvent({{ $event->id }})" class="tbl-btn tbl-btn-view">
                                    <i class="fas fa-eye text-[11px]"></i> View
                                </button>
                                <button wire:click="openEditModal({{ $event->id }})" class="tbl-btn tbl-btn-edit">
                                    <i class="fas fa-pen text-[11px]"></i> Edit
                                </button>
                                <button wire:click="confirmDelete({{ $event->id }})" class="tbl-btn tbl-btn-delete">
                                    <i class="fas fa-trash text-[11px]"></i> Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="py-16 text-center">
                            <i class="fas fa-calendar-days text-5xl text-slate-200 block mb-4"></i>
                            <p class="font-semibold text-slate-400">No events yet</p>
                            <p class="text-sm text-slate-400 mt-1">Click <strong>Create Event</strong> to get started</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        <div class="px-6 py-4 border-t border-slate-200 bg-slate-50 shrink-0">
            <div class="flex items-center justify-between">
                @php $total=$this->events->total();$pp=$this->events->perPage();$cp=$this->events->currentPage();$from=$total>0?($cp-1)*$pp+1:0;$to=min($cp*$pp,$total); @endphp
                <p class="text-slate-600 text-sm">Showing <span class="font-semibold text-slate-800">{{ $from }}–{{ $to }}</span> of <span class="font-semibold text-slate-800">{{ $total }}</span></p>
                <div class="flex gap-2 items-center">
                    @if($this->events->onFirstPage())
                        <button disabled class="px-4 py-2 bg-slate-200 text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">← Prev</button>
                    @else
                        <button wire:click="previousPage" class="px-4 py-2 btn-primary rounded-lg text-sm font-medium">← Prev</button>
                    @endif
                    <span class="px-4 py-2 text-slate-700 text-sm font-medium">{{ $this->events->currentPage() }} / {{ $this->events->lastPage() }}</span>
                    @if($this->events->hasMorePages())
                        <button wire:click="nextPage" class="px-4 py-2 btn-primary rounded-lg text-sm font-medium">Next →</button>
                    @else
                        <button disabled class="px-4 py-2 bg-slate-200 text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">Next →</button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ════════════════════════════════════════════════
     MODAL: Create / Edit Event
════════════════════════════════════════════════ --}}
@if($showFormModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm backdrop-animate">
    <div class="bg-white rounded-xl shadow-2xl modal-animate w-full flex flex-col" style="max-width:820px;max-height:92vh;">

        <div class="flex items-center justify-between px-7 py-5 shrink-0 rounded-t-xl" style="background:linear-gradient(135deg,#7a3f91,#5c2d6e);">
            <div class="flex items-center gap-3">
                <i class="fas fa-{{ $isEditing ? 'pen' : 'calendar-plus' }} text-white text-lg"></i>
                <div>
                    <h2 class="text-lg font-bold text-white">{{ $isEditing ? 'Edit Event' : 'Create New Event' }}</h2>
                    @if($isEditing)
                    <p class="text-xs text-purple-200 mt-0.5"><i class="fas fa-info-circle mr-1"></i>Editing will resubmit the event for admin review.</p>
                    @else
                    <p class="text-xs text-purple-200 mt-0.5">Event will be submitted for admin approval before going live.</p>
                    @endif
                </div>
            </div>
            <button wire:click="closeFormModal" class="w-8 h-8 flex items-center justify-center rounded-lg text-white/70 hover:text-white hover:bg-white/20 transition text-xl leading-none">&times;</button>
        </div>

        @if(count($formErrors))
        <div class="bg-red-50 border-b border-red-200 px-7 py-4 shrink-0">
            <p class="font-semibold text-red-800 text-sm mb-2"><i class="fas fa-triangle-exclamation mr-2"></i>Please fix the following:</p>
            <ul class="text-red-700 text-sm space-y-1">
                @foreach($formErrors as $err)<li class="flex items-start gap-2"><span class="text-red-400 mt-0.5">•</span>{{ $err }}</li>@endforeach
            </ul>
        </div>
        @endif

        <div class="flex-1 min-h-0 overflow-y-auto scrollbar-custom px-7 py-6 space-y-5">

            {{-- Photo Upload --}}
            <div class="rounded-xl border border-slate-200 overflow-hidden">
                <div class="bg-slate-50 px-5 py-3 border-b border-slate-200 flex items-center gap-2">
                    <i class="fas fa-image text-purple-500 text-sm"></i>
                    <span class="text-sm font-bold text-slate-700">Event Photo</span>
                    <span class="text-xs text-slate-400 font-normal">(Optional — uses default if not set)</span>
                </div>
                <div class="p-5">
                    <div x-data="{ isDragging: false }"
                         @dragover.prevent="isDragging=true"
                         @dragleave.prevent="isDragging=false"
                         @drop.prevent="isDragging=false"
                         class="photo-upload-area {{ ($photo || ($existingPhotoUrl && !$removePhoto)) ? 'has-preview' : '' }}"
                         :class="isDragging ? 'border-purple-400 bg-purple-50' : ''">
                        <label class="cursor-pointer block">
                            <input type="file" wire:model="photo" accept="image/*" class="hidden">
                            @if($photo)
                                <div class="flex flex-col items-center gap-3">
                                    <img src="{{ $photo->temporaryUrl() }}" class="w-40 h-32 object-cover rounded-lg shadow border border-purple-200">
                                    <p class="text-xs font-semibold text-purple-600"><i class="fas fa-check-circle mr-1"></i>New photo selected</p>
                                    <p class="text-xs text-slate-400">Click or drag to change</p>
                                </div>
                            @elseif($existingPhotoUrl && !$removePhoto)
                                <div class="flex flex-col items-center gap-3">
                                    <img src="{{ $existingPhotoUrl }}" class="w-40 h-32 object-cover rounded-lg shadow border border-slate-200">
                                    <p class="text-xs font-semibold text-slate-500">Current photo — click to change</p>
                                </div>
                            @else
                                <div class="flex flex-col items-center gap-2 py-2">
                                    <i class="fas fa-cloud-arrow-up text-3xl text-slate-300"></i>
                                    <p class="font-semibold text-slate-500 text-sm">Click to upload or drag & drop</p>
                                    <p class="text-xs text-slate-400">JPG, PNG, WEBP — max 5MB</p>
                                    <p class="text-xs text-purple-400 font-medium mt-1"><i class="fas fa-image mr-1"></i>Default photo will be used if left blank</p>
                                </div>
                            @endif
                        </label>
                    </div>
                    @if($existingPhotoUrl && !$removePhoto && !$photo)
                    <div class="mt-3 flex items-center gap-2">
                        <button type="button" wire:click="$set('removePhoto',true)"
                                class="text-xs text-red-500 hover:text-red-700 font-semibold transition flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-red-200 hover:bg-red-50">
                            <i class="fas fa-trash text-[10px]"></i> Remove current photo
                        </button>
                        <span class="text-xs text-slate-400">(will use default photo)</span>
                    </div>
                    @endif
                    @if($removePhoto)
                    <div class="mt-3 flex items-center gap-2">
                        <span class="text-xs text-amber-600 font-semibold"><i class="fas fa-exclamation-circle mr-1"></i>Photo will be removed on save</span>
                        <button type="button" wire:click="$set('removePhoto',false)" class="text-xs text-blue-500 underline">Undo</button>
                    </div>
                    @endif
                    <div wire:loading wire:target="photo" class="mt-3 text-xs text-purple-600 flex items-center gap-2">
                        <i class="fas fa-spinner spin-icon"></i> Uploading photo...
                    </div>
                </div>
            </div>

            {{-- Event Details --}}
            <div class="rounded-xl border border-slate-200 overflow-hidden">
                <div class="bg-slate-50 px-5 py-3 border-b border-slate-200 flex items-center gap-2">
                    <i class="fas fa-circle-info text-purple-500 text-sm"></i>
                    <span class="text-sm font-bold text-slate-700">Event Details</span>
                </div>
                <div class="p-5 space-y-4">
                    <div>
                        <label class="form-label">Event Title <span class="text-red-500">*</span></label>
                        <input wire:model.defer="title" type="text" placeholder="e.g. BSIT Alumni Homecoming 2025"
                               class="form-input {{ isset($formErrors['title']) ? 'field-error' : '' }}">
                        @if(isset($formErrors['title']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['title'] }}</p>@endif
                    </div>
                    <div>
                        <label class="form-label">Description</label>
                        <textarea wire:model.defer="description" rows="4" placeholder="Describe the event, agenda, highlights..."
                                  class="form-input resize-none"></textarea>
                    </div>
                    <div>
                        <label class="form-label">Event Date <span class="text-red-500">*</span></label>
                        <input wire:model="event_date" type="date"
                               class="form-input {{ isset($formErrors['event_date']) ? 'field-error' : '' }}">
                        @if(isset($formErrors['event_date']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['event_date'] }}</p>@endif
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="form-label">Start Time <span class="text-red-500">*</span></label>
                            <input wire:model="start_time" type="text" placeholder="e.g. 07:00 PM or 19:00"
                                   class="form-input {{ isset($formErrors['start_time']) ? 'field-error' : '' }}">
                            @if(isset($formErrors['start_time']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['start_time'] }}</p>@endif
                        </div>
                        <div>
                            <label class="form-label">End Time <span class="text-slate-400 font-normal">(Optional)</span></label>
                            <input wire:model="end_time" type="text" placeholder="e.g. 09:00 PM or 21:00" class="form-input">
                            <p class="field-hint"><i class="fas fa-circle-info text-[10px] mr-1"></i>Leave blank if end time is not set.</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="form-label">Venue / Location <span class="text-red-500">*</span></label>
                            <input wire:model.defer="venue" type="text" placeholder="e.g. PHILCST Main Gym"
                                   class="form-input {{ isset($formErrors['venue']) ? 'field-error' : '' }}">
                            @if(isset($formErrors['venue']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['venue'] }}</p>@endif
                        </div>
                        <div>
                            <label class="form-label">Full Address <span class="text-slate-400 font-normal">(Optional)</span></label>
                            <input wire:model.defer="venue_address" type="text" placeholder="e.g. Carig Sur, Tuguegarao City" class="form-input">
                        </div>
                    </div>
                </div>
            </div>

            {{-- Participants --}}
            <div class="rounded-xl border border-slate-200 overflow-hidden">
                <div class="bg-slate-50 px-5 py-3 border-b border-slate-200 flex items-center gap-2">
                    <i class="fas fa-users text-purple-500 text-sm"></i>
                    <span class="text-sm font-bold text-slate-700">Target Participants</span>
                </div>
                <div class="p-5 space-y-4">
                    <div>
                        <label class="form-label">Target College / Department</label>
                        @if($this->organizerCollege)
                        <div class="flex items-center gap-3">
                            <div class="flex-1 form-input bg-slate-50 text-slate-700 flex items-center gap-2 cursor-not-allowed select-none">
                                <i class="fas fa-building-columns text-purple-400 text-sm"></i>
                                <span class="font-semibold">{{ $this->organizerCollege }}</span>
                            </div>
                            <span class="inline-flex items-center gap-1.5 text-xs font-bold text-purple-600 bg-purple-50 border border-purple-200 px-3 py-2 rounded-lg shrink-0">
                                <i class="fas fa-lock text-[10px]"></i> Auto-set
                            </span>
                        </div>
                        <p class="field-hint mt-2"><i class="fas fa-circle-info text-[10px] mr-1"></i>Based on your assigned college. Contact admin to change.</p>
                        @else
                        <input wire:model.defer="target_participants" type="text"
                               placeholder="e.g. All Alumni, BSIT Batch 2020" class="form-input">
                        <p class="field-hint"><i class="fas fa-circle-info text-[10px] mr-1"></i>No college assigned yet. You may type it manually.</p>
                        @endif
                    </div>
                    <div>
                        <label class="form-label">Batch Year <span class="text-slate-400 font-normal text-xs">(Optional — leave blank for all batches)</span></label>
                        <input wire:model.defer="batch_year" type="number" min="1990" max="{{ now()->year + 5 }}"
                               placeholder="e.g. {{ now()->year - 2 }}" class="form-input max-w-xs">
                        <p class="field-hint mt-1"><i class="fas fa-circle-info text-[10px] mr-1"></i>Enter a graduation year to target a specific batch only.</p>
                    </div>
                </div>
            </div>

            {{-- Contact --}}
            <div class="rounded-xl border border-slate-200 overflow-hidden">
                <div class="bg-slate-50 px-5 py-3 border-b border-slate-200 flex items-center gap-2">
                    <i class="fas fa-address-card text-purple-500 text-sm"></i>
                    <span class="text-sm font-bold text-slate-700">Organizer / Contact Person</span>
                </div>
                <div class="p-5 grid grid-cols-3 gap-4">
                    <div>
                        <label class="form-label">Contact Person</label>
                        <input wire:model.defer="contact_person" type="text" placeholder="Full name" class="form-input">
                    </div>
                    <div>
                        <label class="form-label">Email</label>
                        <input wire:model.defer="contact_email" type="email" placeholder="contact@example.com" class="form-input">
                    </div>
                    <div>
                        <label class="form-label">Phone</label>
                        <input wire:model.defer="contact_phone" type="text" placeholder="+63 9XX XXX XXXX" class="form-input">
                    </div>
                </div>
            </div>

            {{-- Notes --}}
            <div>
                <label class="form-label">Additional Notes / Requirements</label>
                <textarea wire:model.defer="notes" rows="3" placeholder="Dress code, special instructions, requirements..."
                          class="form-input resize-none"></textarea>
            </div>

        </div>

        <div class="px-7 py-5 border-t border-slate-200 bg-slate-50 rounded-b-xl shrink-0 flex gap-3">
            <button wire:click="closeFormModal" class="flex-1 px-6 py-3 border border-slate-300 text-slate-700 rounded-xl text-sm font-semibold hover:bg-slate-100 transition">Cancel</button>
            <button wire:click="saveEvent" wire:loading.attr="disabled" wire:target="saveEvent"
                    class="flex-1 px-6 py-3 btn-primary rounded-xl text-sm font-bold flex items-center justify-center gap-2">
                <span wire:loading wire:target="saveEvent"><i class="fas fa-spinner spin-icon"></i> Saving...</span>
                <span wire:loading.remove wire:target="saveEvent">
                    <i class="fas fa-{{ $isEditing ? 'floppy-disk' : 'paper-plane' }} mr-1.5"></i>
                    {{ $isEditing ? 'Save Changes' : 'Submit for Approval' }}
                </span>
            </button>
        </div>
    </div>
</div>
@endif

{{-- ════════════════════════════════════════════════
     MODAL: View Event
════════════════════════════════════════════════ --}}
@if($showViewModal && $this->viewingEvent)
@php
    $ev = $this->viewingEvent;
    $totalRsvp = $ev->confirmed_count + $ev->declined_count + $ev->tentative_count;
@endphp
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm backdrop-animate">
    <div class="ev-modal modal-animate relative">
        <button wire:click="closeViewModal" class="ev-close-x" title="Close">&times;</button>

        <div class="ev-header">
            <img src="{{ $ev->photo_url }}" alt="{{ $ev->title }}" class="ev-cover">
            <div class="ev-header-body">
                <div class="flex items-start justify-between gap-4 mb-3">
                    <div class="ev-title">{{ $ev->title }}</div>
                    @if($ev->status === 'PENDING')
                        <span class="badge-pending shrink-0"><i class="fas fa-hourglass-half mr-1"></i>Pending Review</span>
                    @elseif($ev->status === 'APPROVED')
                        <span class="badge-approved shrink-0"><i class="fas fa-circle-check mr-1"></i>Approved</span>
                    @else
                        <span class="badge-rejected shrink-0"><i class="fas fa-circle-xmark mr-1"></i>Rejected</span>
                    @endif
                </div>
                <ul class="ev-meta-list">
                    <li class="ev-meta-item"><span class="ev-meta-icon"><i class="fas fa-calendar"></i></span>
                        <span>{{ $ev->event_date->format('F d, Y') }}</span>
                    </li>
                    <li class="ev-meta-item"><span class="ev-meta-icon"><i class="fas fa-clock"></i></span>
                        <span>{{ $ev->event_date->format('g:i A') }}
                        @if($ev->event_end_date)<span style="color:#aaa;margin:0 4px;">—</span>{{ $ev->event_end_date->format('g:i A') }}
                        @else<span style="color:#aaa;font-style:italic;margin-left:4px;">· End time not set</span>@endif
                        </span>
                    </li>
                    <li class="ev-meta-item"><span class="ev-meta-icon"><i class="fas fa-location-dot"></i></span>
                        <span>{{ $ev->venue }}@if($ev->venue_address) &middot; <span style="color:#888">{{ $ev->venue_address }}</span>@endif</span>
                    </li>
                    @if($ev->target_participants)
                    <li class="ev-meta-item"><span class="ev-meta-icon"><i class="fas fa-users"></i></span><span>{{ $ev->target_participants }}</span></li>
                    @endif
                    @if($ev->contact_person)
                    <li class="ev-meta-item"><span class="ev-meta-icon"><i class="fas fa-user-tie"></i></span>
                        <span>{{ $ev->contact_person }}
                        @if($ev->contact_email) &middot; <a href="mailto:{{ $ev->contact_email }}" style="color:#7a3f91">{{ $ev->contact_email }}</a>@endif
                        @if($ev->contact_phone) &middot; {{ $ev->contact_phone }}@endif
                        </span>
                    </li>
                    @endif
                </ul>
            </div>
        </div>

        <div class="ev-body scrollbar-custom">

            {{-- RSVP Summary --}}
            <div class="ev-section">
                <div class="ev-section-title">Attendee Responses
                    @if($totalRsvp > 0)<span style="font-size:12px;font-weight:400;color:#888;margin-left:6px;">{{ $totalRsvp }} total</span>@endif
                </div>
                @if($totalRsvp === 0)
                <div class="text-center py-6 text-slate-400 text-sm">
                    <i class="fas fa-inbox text-2xl block mb-2 text-slate-200"></i>
                    No responses yet. Share your event to start collecting RSVPs.
                </div>
                @else
                <div class="rsvp-grid">
                    <div class="rsvp-card rsvp-card-confirmed">
                        <div class="rsvp-icon rsvp-confirmed-color"><i class="fas fa-circle-check"></i></div>
                        <div class="rsvp-count rsvp-confirmed-color">{{ $ev->confirmed_count }}</div>
                        <div class="rsvp-label rsvp-confirmed-color">Going</div>
                    </div>
                    <div class="rsvp-card rsvp-card-declined">
                        <div class="rsvp-icon rsvp-declined-color"><i class="fas fa-circle-xmark"></i></div>
                        <div class="rsvp-count rsvp-declined-color">{{ $ev->declined_count }}</div>
                        <div class="rsvp-label rsvp-declined-color">Not Going</div>
                    </div>
                    <div class="rsvp-card rsvp-card-tentative">
                        <div class="rsvp-icon rsvp-tentative-color"><i class="fas fa-circle-question"></i></div>
                        <div class="rsvp-count rsvp-tentative-color">{{ $ev->tentative_count }}</div>
                        <div class="rsvp-label rsvp-tentative-color">Maybe</div>
                    </div>
                </div>
                @endif
            </div>

            {{-- Admin Review Status --}}
            <div class="ev-section">
                <div class="ev-section-title">Admin Review Status</div>
                @if($ev->status === 'PENDING')
                <div class="review-box review-box-pending">
                    <p class="text-sm font-bold text-yellow-800"><i class="fas fa-hourglass-half mr-2 text-yellow-500"></i>Awaiting Admin Review</p>
                    <p class="text-xs text-yellow-700 mt-1">Your event has been submitted and is waiting for admin approval.</p>
                </div>
                @elseif($ev->status === 'APPROVED')
                <div class="review-box review-box-approved">
                    <p class="text-sm font-bold text-green-800"><i class="fas fa-circle-check mr-2 text-green-500"></i>Approved by Admin</p>
                    @if($ev->reviewed_at)<p class="text-xs text-green-700 mt-1">Reviewed {{ $ev->reviewed_at->diffForHumans() }}</p>@endif
                    @if($ev->review_remarks)<p class="text-xs text-green-600 mt-1 italic">"{{ $ev->review_remarks }}"</p>@endif
                </div>
                @else
                <div class="review-box review-box-rejected">
                    <p class="text-sm font-bold text-red-800"><i class="fas fa-circle-xmark mr-2 text-red-500"></i>Rejected by Admin</p>
                    @if($ev->review_remarks)<p class="text-xs text-red-600 mt-2 font-semibold">Reason: <span class="font-normal">{{ $ev->review_remarks }}</span></p>@endif
                    <p class="text-xs text-red-500 mt-2">You can edit your event and resubmit for review.</p>
                </div>
                @endif
            </div>

            @if($ev->description)
            <div class="ev-section">
                <div class="ev-section-title">About This Event</div>
                <div class="ev-description">{{ $ev->description }}</div>
            </div>
            @endif

            @if($ev->notes)
            <div class="ev-section">
                <div class="ev-section-title">Additional Notes / Requirements</div>
                <div class="ev-description">{{ $ev->notes }}</div>
            </div>
            @endif

            {{-- Posting Details --}}
            <div class="ev-section">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#aaa;margin-bottom:12px;">Posting Details</div>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0;border:1px solid #e8e8e8;border-radius:8px;overflow:hidden;">
                    <div style="padding:12px 16px;border-right:1px solid #e8e8e8;border-bottom:1px solid #e8e8e8;">
                        <div style="font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#aaa;margin-bottom:3px;">Submitted</div>
                        <div style="font-size:13px;font-weight:600;color:#111;">{{ $ev->created_at->format('M d, Y') }}</div>
                        <div style="font-size:11px;color:#888;">{{ $ev->created_at->format('g:i A') }}</div>
                    </div>
                    <div style="padding:12px 16px;border-right:1px solid #e8e8e8;border-bottom:1px solid #e8e8e8;">
                        <div style="font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#aaa;margin-bottom:3px;">Target College</div>
                        <div style="font-size:13px;font-weight:600;color:#111;">{{ $ev->target_participants ?? '—' }}</div>
                    </div>
                    <div style="padding:12px 16px;border-bottom:1px solid #e8e8e8;">
                        <div style="font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#aaa;margin-bottom:3px;">Status</div>
                        <div style="font-size:13px;font-weight:600;color:#111;">{{ $ev->status }}</div>
                    </div>

                    {{-- Last Updated / Updated By (full row) --}}
                    <div style="grid-column:span 3;padding:13px 16px;{{ $ev->was_edited ? 'background:#faf5ff;' : '' }}">
                        <div style="font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#aaa;margin-bottom:6px;">Last Updated</div>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <div>
                                <div style="font-size:13px;font-weight:600;color:#111;">{{ $ev->updated_at->format('M d, Y · g:i A') }}</div>
                                <div style="font-size:11px;color:#888;">{{ $ev->updated_at->diffForHumans() }}</div>
                            </div>
                            @if($ev->was_edited && $ev->updated_by)
                                @if($ev->updated_by_role === 'admin')
                                <span class="updated-by-tag updated-by-admin">
                                    <i class="fas fa-shield-halved" style="font-size:9px;"></i>
                                    Updated by {{ $ev->updated_by }}
                                    <span style="opacity:.6;font-weight:400;">· Admin</span>
                                </span>
                                @else
                                <span class="updated-by-tag updated-by-organizer">
                                    <i class="fas fa-user-pen" style="font-size:9px;"></i>
                                    Updated by {{ $ev->updated_by }}
                                    <span style="opacity:.6;font-weight:400;">· Organizer</span>
                                </span>
                                @endif
                            @elseif(!$ev->was_edited)
                            <div style="display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:500;color:#aaa;padding:3px 10px;border-radius:3px;background:#f8fafc;border:1px solid #e2e8f0;">
                                <i class="fas fa-clock" style="font-size:9px;"></i> No edits since creation
                            </div>
                            @endif
                        </div>
                    </div>

                    {{-- Deleted By row — only show if soft-deleted / deleted_by is set --}}
                    @if($ev->deleted_by)
                    <div style="grid-column:span 3;padding:13px 16px;background:#fff7ed;border-top:1px solid #fed7aa;">
                        <div style="font-size:10.5px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#aaa;margin-bottom:6px;">Deleted By</div>
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <span class="deleted-by-tag">
                                <i class="fas fa-trash" style="font-size:9px;"></i>
                                {{ $ev->deleted_by }}
                                @if($ev->deleted_by_role)
                                <span style="opacity:.6;font-weight:400;">· {{ ucfirst($ev->deleted_by_role) }}</span>
                                @endif
                            </span>
                        </div>
                    </div>
                    @endif

                </div>
            </div>

        </div>

        <div class="ev-footer">
            <button wire:click="closeViewModal" class="ev-btn ev-btn-close">
                <i class="fas fa-xmark" style="font-size:11px;"></i> Close
            </button>
            <button wire:click="confirmDelete({{ $ev->id }})" class="ev-btn ev-btn-delete">
                <i class="fas fa-trash" style="font-size:11px;"></i> Delete
            </button>
            <button wire:click="openEditModal({{ $ev->id }})" class="ev-btn ev-btn-edit">
                <i class="fas fa-pen-to-square" style="font-size:11px;"></i>
                {{ $ev->status === 'REJECTED' ? 'Edit & Resubmit' : 'Edit Event' }}
            </button>
        </div>
    </div>
</div>
@endif

{{-- ════════════════════════════════════════════════
     MODAL: Confirm Delete
════════════════════════════════════════════════ --}}
@if($showDeleteModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm backdrop-animate">
    <div class="relative bg-white rounded-2xl shadow-2xl modal-animate" style="width:420px;max-width:95vw;">
        <div class="px-7 py-6 bg-red-50 border-b border-red-200 flex items-center gap-3 rounded-t-2xl">
            <div class="w-10 h-10 bg-red-100 rounded-xl flex items-center justify-center shrink-0">
                <i class="fas fa-triangle-exclamation text-red-500 text-lg"></i>
            </div>
            <h2 class="text-lg font-extrabold text-red-800">Delete Event</h2>
        </div>
        <div class="p-7">
            <p class="text-slate-600 text-sm mb-1">You are about to delete:</p>
            <p class="font-extrabold text-red-700 text-base mb-3">"{{ $deleteEventTitle }}"</p>
            <p class="text-xs mb-5 bg-red-50 rounded-lg px-3 py-2 border border-red-100 text-slate-500">
                <i class="fas fa-exclamation-circle text-red-400 mr-1.5"></i>This event and its photo will be permanently removed.
            </p>
            <div class="flex gap-3">
                <button wire:click="cancelDelete" class="confirm-btn confirm-btn-cancel">
                    <i class="fas fa-xmark"></i> Cancel
                </button>
                <button wire:click="executeDelete" wire:loading.attr="disabled" wire:target="executeDelete" class="confirm-btn confirm-btn-delete">
                    <span wire:loading wire:target="executeDelete"><i class="fas fa-spinner spin-icon"></i> Deleting...</span>
                    <span wire:loading.remove wire:target="executeDelete"><i class="fas fa-trash"></i> Yes, Delete</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

</div>