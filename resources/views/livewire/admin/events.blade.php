<?php
/**
 * FILE: resources/views/livewire/admin/event-management.blade.php
 */

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use App\Models\AdminEvent;
use App\Http\Controllers\AdminEventController;
use Illuminate\Support\Facades\Storage;
use App\Models\Alumni;
use App\Models\Organizer;

new class extends Component {
    use WithPagination, WithFileUploads;

    protected string $paginationTheme = 'tailwind';

    public string $search        = '';
    public string $filterStatus  = '';
    public string $filterSort    = 'recent';
    public string $filterCollege = '';

    public bool   $showFormModal           = false;
    public bool   $isEditing               = false;
    public ?int   $editingEventId          = null;
    public bool   $editingIsOrganizerEvent = false;

    public string $title          = '';
    public string $description    = '';
    public string $event_date     = '';
    public string $start_time     = '';
    public string $end_time       = '';
    public string $venue          = '';
    public string $venue_address  = '';
    public string $contact_person = '';
    public string $contact_email  = '';
    public string $contact_phone  = '';
    public string $notes          = '';

    public string $targetMode       = 'all';
    public array  $selectedColleges = [];
    public string $batchYear        = '';

    public $photo                    = null;
    public ?string $existingPhotoUrl = null;
    public bool   $removePhoto       = false;

    public bool   $showViewModal  = false;
    public ?int   $viewingEventId = null;

    public bool   $showApproveModal  = false;
    public ?int   $approveEventId    = null;
    public string $approveEventTitle = '';
    public string $approveRemarks    = '';

    public bool   $showRejectModal   = false;
    public ?int   $rejectEventId     = null;
    public string $rejectEventTitle  = '';
    public string $rejectRemarks     = '';

    public bool   $showDeleteModal   = false;
    public ?int   $deleteEventId     = null;
    public string $deleteEventTitle  = '';

    public bool   $showRestoreModal   = false;
    public ?int   $restoreEventId     = null;
    public string $restoreEventTitle  = '';

    public array  $formErrors = [];

    public function updatingSearch(): void        { $this->resetPage(); }
    public function updatingFilterStatus(): void  { $this->resetPage(); }
    public function updatingFilterSort(): void    { $this->resetPage(); }
    public function updatingFilterCollege(): void { $this->resetPage(); }

    public function updatedTargetMode(): void
    {
        $this->selectedColleges = [];
        $this->batchYear        = '';
    }

    public function updatedSelectedColleges(): void
    {
        $this->batchYear = '';
    }

    // ── KEY FIX: withTrashed() so ORGANIZER_DELETED events are visible ──
    #[Computed]
    public function events()
    {
        $q = AdminEvent::withTrashed()  // <-- THE FIX
            ->with('organizer')
            ->withCount([
                'rsvps as confirmed_count' => fn($r) => $r->where('response', 'CONFIRMED'),
                'rsvps as declined_count'  => fn($r) => $r->where('response', 'DECLINED'),
                'rsvps as tentative_count' => fn($r) => $r->where('response', 'TENTATIVE'),
            ]);

        if ($this->search !== '') {
            $s = $this->search;
            $q->where(fn($sub) =>
                $sub->where('title', 'like', "%{$s}%")
                    ->orWhere('venue', 'like', "%{$s}%")
                    ->orWhere('target_participants', 'like', "%{$s}%")
            );
        }

        if ($this->filterStatus !== '') {
            $q->where('status', $this->filterStatus);
        }

        if ($this->filterCollege !== '') {
            $q->where('target_participants', 'like', "%{$this->filterCollege}%");
        }

        $q->orderBy('created_at', $this->filterSort === 'oldest' ? 'asc' : 'desc');
        return $q->paginate(20);
    }

    #[Computed]
    public function viewingEvent(): ?AdminEvent
    {
        if (!$this->viewingEventId) return null;
        return AdminEvent::withTrashed()  // <-- also here
            ->with('organizer')
            ->withCount([
                'rsvps as confirmed_count' => fn($r) => $r->where('response', 'CONFIRMED'),
                'rsvps as declined_count'  => fn($r) => $r->where('response', 'DECLINED'),
                'rsvps as tentative_count' => fn($r) => $r->where('response', 'TENTATIVE'),
            ])->find($this->viewingEventId);
    }

    #[Computed]
    public function colleges(): array
    {
        return app(AdminEventController::class)->getColleges();
    }

    #[Computed]
    public function organizersForSelectedColleges(): array
    {
        if ($this->targetMode !== 'college' || empty($this->selectedColleges)) {
            return [];
        }
        return Organizer::whereIn('department', $this->selectedColleges)
            ->orderBy('name')
            ->get(['id', 'name', 'department', 'email'])
            ->toArray();
    }

    public function resetFilters(): void
    {
        $this->search        = '';
        $this->filterStatus  = '';
        $this->filterCollege = '';
        $this->filterSort    = 'recent';
        $this->resetPage();
    }

    public function openEditModal(int $id): void
    {
        $event = app(AdminEventController::class)->getEvent($id);

        $this->isEditing               = true;
        $this->editingEventId          = $id;
        $this->editingIsOrganizerEvent = $event->organizer_id !== null;
        $this->title                   = $event->title;
        $this->description             = $event->description ?? '';
        $this->event_date              = $event->event_date->setTimezone('Asia/Manila')->format('Y-m-d');
        $this->start_time              = $event->event_date->setTimezone('Asia/Manila')->format('g:i A');
        $this->end_time                = $event->event_end_date?->setTimezone('Asia/Manila')->format('g:i A') ?? '';
        $this->venue                   = $event->venue;
        $this->venue_address           = $event->venue_address ?? '';
        $this->contact_person          = $event->contact_person ?? '';
        $this->contact_email           = $event->contact_email ?? '';
        $this->contact_phone           = $event->contact_phone ?? '';
        $this->notes                   = $event->notes ?? '';
        $this->existingPhotoUrl        = $event->photo_url;
        $this->removePhoto             = false;
        $this->photo                   = null;
        $this->formErrors              = [];

        $tp           = $event->target_participants ?? '';
        $parts        = explode(' · Batch ', $tp, 2);
        $collegesPart = trim($parts[0] ?? '');
        $this->batchYear = trim($parts[1] ?? '');
        if (!$collegesPart || $collegesPart === 'All Colleges') {
            $this->targetMode       = 'all';
            $this->selectedColleges = [];
        } else {
            $this->targetMode       = 'college';
            $this->selectedColleges = array_map('trim', explode(',', $collegesPart));
        }

        $this->showFormModal = true;
        $this->showViewModal = false;
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
        if (!trim($this->venue))      $errors['venue']      = 'Venue / Location is required.';

        if (!trim($this->start_time)) {
            $errors['start_time'] = 'Start time is required.';
        } else {
            try { \Carbon\Carbon::parse(trim($this->start_time)); }
            catch (\Exception $e) {
                $errors['start_time'] = 'Invalid start time. Use a format like "8:00 AM" or "13:00".';
            }
        }

        if (trim($this->end_time)) {
            try {
                $endDt = \Carbon\Carbon::createFromFormat('Y-m-d g:i A', $this->event_date . ' ' . trim($this->end_time), 'Asia/Manila');
                if (!isset($errors['start_time'])) {
                    $startDt = \Carbon\Carbon::createFromFormat('Y-m-d g:i A', $this->event_date . ' ' . trim($this->start_time), 'Asia/Manila');
                    if ($endDt->lte($startDt)) {
                        $errors['end_time'] = 'End time must be after start time.';
                    }
                }
            } catch (\Exception $e) {
                $errors['end_time'] = 'Invalid end time. Use a format like "5:00 PM" or "17:00".';
            }
        }

        if ($this->targetMode === 'college' && empty($this->selectedColleges)) {
            $errors['target'] = 'Please select at least one college.';
        }

        if ($this->targetMode === 'college' && !empty($this->selectedColleges) && !isset($errors['target'])) {
            $colleges  = $this->selectedColleges;
            $hasAlumni = Alumni::where('status', 'VERIFIED')
                ->whereHas('course', fn($c) => $c->whereIn('college', $colleges))
                ->exists();
            if (!$hasAlumni) {
                $errors['target'] = "Cannot create event — no verified alumni under " . implode(', ', $colleges) . ".";
            }
        }

        if (trim($this->batchYear) !== '' && !isset($errors['target'])) {
            $inputYear = (int) trim($this->batchYear);
            $q = Alumni::where('status', 'VERIFIED')->where('batch', $inputYear);
            if ($this->targetMode === 'college' && !empty($this->selectedColleges)) {
                $colleges = $this->selectedColleges;
                $q->whereHas('course', fn($c) => $c->whereIn('college', $colleges));
            }
            if (!$q->exists()) {
                $suggQ = Alumni::where('status', 'VERIFIED');
                if ($this->targetMode === 'college' && !empty($this->selectedColleges)) {
                    $colleges = $this->selectedColleges;
                    $suggQ->whereHas('course', fn($c) => $c->whereIn('college', $colleges));
                }
                $available  = $suggQ->distinct()->orderBy('batch', 'desc')->pluck('batch')->map(fn($b) => (int)$b)->toArray();
                $scopeLabel = $this->targetMode === 'college' && !empty($this->selectedColleges)
                    ? implode(', ', $this->selectedColleges) : 'all colleges';
                if (empty($available)) {
                    $errors['batch_year'] = "No verified alumni found for {$scopeLabel}.";
                } else {
                    $nearest   = collect($available)->sortBy(fn($y) => abs($y - $inputYear))->first();
                    $batchList = implode(', ', array_slice($available, 0, 8));
                    if (count($available) > 8) $batchList .= '…';
                    $errors['batch_year'] = "No verified alumni for batch {$inputYear} in {$scopeLabel}."
                        . ($nearest ? " Nearest: {$nearest}." : '')
                        . " Available: {$batchList}.";
                }
            }
        }

        if (!empty($errors)) { $this->formErrors = $errors; return; }

        $collegesStr = $this->targetMode === 'all' ? 'All Colleges' : implode(', ', $this->selectedColleges);
        $yearSuffix  = trim($this->batchYear) ? ' · Batch ' . trim($this->batchYear) : '';
        $targetStr   = $collegesStr . $yearSuffix;

        $startDt = \Carbon\Carbon::createFromFormat('Y-m-d g:i A', $this->event_date . ' ' . $this->start_time, 'Asia/Manila')->utc();
        $endDt   = ($this->event_date && trim($this->end_time))
            ? \Carbon\Carbon::createFromFormat('Y-m-d g:i A', $this->event_date . ' ' . $this->end_time, 'Asia/Manila')->utc()
            : null;

        $data = [
            'title'               => trim($this->title),
            'description'         => trim($this->description) ?: null,
            'event_date'          => $startDt->format('Y-m-d H:i:s'),
            'event_end_date'      => $endDt ? $endDt->format('Y-m-d H:i:s') : null,
            'venue'               => trim($this->venue),
            'venue_address'       => trim($this->venue_address) ?: null,
            'target_participants' => $targetStr,
            'notes'               => trim($this->notes) ?: null,
        ];

        if (!$this->editingIsOrganizerEvent) {
            $data['contact_person'] = trim($this->contact_person) ?: null;
            $data['contact_email']  = trim($this->contact_email)  ?: null;
            $data['contact_phone']  = trim($this->contact_phone)  ?: null;
        }

        $ctrl  = app(AdminEventController::class);
        $photo = $this->photo;

        if ($this->isEditing) {
            if ($this->removePhoto && !$photo) {
                $event = $ctrl->getEvent($this->editingEventId);
                if ($event->photo && $event->photo !== AdminEvent::DEFAULT_PHOTO) {
                    Storage::disk('public')->delete($event->photo);
                }
                $data['photo'] = null;
                $event->update(array_merge($data, [
                    'updated_by'      => auth()->user()?->name,
                    'updated_by_role' => 'admin',
                ]));
            } else {
                $ctrl->updateEvent($this->editingEventId, $data, $photo ?: null);
            }
            $this->dispatch('flash-message', type: 'success', message: 'Event updated successfully!');
        }

        $this->showFormModal = false;
        $this->resetFormFields();
    }

    public function viewEvent(int $id): void { $this->viewingEventId = $id; $this->showViewModal = true; }
    public function closeViewModal(): void   { $this->showViewModal = false; $this->viewingEventId = null; }

    public function confirmApprove(int $id): void
    {
        $event = app(AdminEventController::class)->getEvent($id);
        $this->approveEventId    = $id;
        $this->approveEventTitle = $event->title;
        $this->approveRemarks    = '';
        $this->showApproveModal  = true;
    }

    public function executeApprove(): void
    {
        if ($this->approveEventId) {
            app(AdminEventController::class)->approveEvent($this->approveEventId, trim($this->approveRemarks) ?: null);
            $this->dispatch('flash-message', type: 'success', message: "'{$this->approveEventTitle}' approved!");
        }
        $this->showApproveModal  = false;
        $this->approveEventId    = null;
        $this->approveEventTitle = '';
        $this->approveRemarks    = '';
        if ($this->showViewModal) { $this->showViewModal = false; $this->viewingEventId = null; }
    }

    public function cancelApprove(): void
    {
        $this->showApproveModal = false;
        $this->approveEventId   = null;
        $this->approveRemarks   = '';
    }

    public function confirmReject(int $id): void
    {
        $event = app(AdminEventController::class)->getEvent($id);
        $this->rejectEventId    = $id;
        $this->rejectEventTitle = $event->title;
        $this->rejectRemarks    = '';
        $this->showRejectModal  = true;
    }

    public function executeReject(): void
    {
        if (!trim($this->rejectRemarks)) {
            $this->dispatch('flash-message', type: 'error', message: 'Please provide a reason for rejection.');
            return;
        }
        if ($this->rejectEventId) {
            app(AdminEventController::class)->rejectEvent($this->rejectEventId, trim($this->rejectRemarks));
            $this->dispatch('flash-message', type: 'success', message: "'{$this->rejectEventTitle}' rejected.");
        }
        $this->showRejectModal  = false;
        $this->rejectEventId    = null;
        $this->rejectEventTitle = '';
        $this->rejectRemarks    = '';
        if ($this->showViewModal) { $this->showViewModal = false; $this->viewingEventId = null; }
    }

    public function cancelReject(): void
    {
        $this->showRejectModal = false;
        $this->rejectEventId   = null;
        $this->rejectRemarks   = '';
    }

    public function confirmDelete(int $id): void
    {
        $event = app(AdminEventController::class)->getEvent($id);
        $this->deleteEventId    = $id;
        $this->deleteEventTitle = $event->title;
        $this->showDeleteModal  = true;
    }

    public function executeDelete(): void
    {
        if ($this->deleteEventId) {
            app(AdminEventController::class)->deleteEvent($this->deleteEventId);
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

    public function confirmRestore(int $id): void
    {
        $event = app(AdminEventController::class)->getEvent($id);
        $this->restoreEventId    = $id;
        $this->restoreEventTitle = $event->title;
        $this->showRestoreModal  = true;
    }

    public function executeRestore(): void
    {
        if ($this->restoreEventId) {
            $event = app(AdminEventController::class)->getEvent($this->restoreEventId);
            // Restore soft-delete AND reset status back to PENDING
            if ($event->trashed()) {
                $event->restore();
            }
            $event->update([
                'status'          => 'PENDING',
                'deleted_by'      => null,
                'deleted_by_role' => null,
                'updated_by'      => auth()->user()?->name,
                'updated_by_role' => 'admin',
            ]);
            $this->dispatch('flash-message', type: 'success', message: "'{$this->restoreEventTitle}' restored!");
        }
        $this->showRestoreModal  = false;
        $this->restoreEventId    = null;
        $this->restoreEventTitle = '';
        if ($this->showViewModal) { $this->showViewModal = false; $this->viewingEventId = null; }
    }

    public function cancelRestore(): void
    {
        $this->showRestoreModal = false;
        $this->restoreEventId   = null;
        $this->restoreEventTitle = '';
    }

    private function resetFormFields(): void
    {
        $this->title = $this->description = $this->event_date = $this->start_time = $this->end_time = '';
        $this->venue = $this->venue_address = $this->contact_person = $this->contact_email = '';
        $this->contact_phone = $this->notes = '';
        $this->targetMode       = 'all';
        $this->selectedColleges = [];
        $this->batchYear        = '';
        $this->photo            = null;
        $this->existingPhotoUrl = null;
        $this->removePhoto      = false;
        $this->formErrors       = [];
        $this->editingEventId   = null;
        $this->isEditing        = false;
        $this->editingIsOrganizerEvent = false;
    }
};
?>

<div>

{{-- ══ FLASH TOAST ══ --}}
<div
    x-data="{show:false,type:'success',msg:'',timer:null,display(t,m){this.type=t;this.msg=m;this.show=true;clearTimeout(this.timer);this.timer=setTimeout(()=>this.show=false,5000);}}"
    @flash-message.window="display($event.detail.type,$event.detail.message)"
    x-show="show"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 translate-x-8 scale-95"
    x-transition:enter-end="opacity-100 translate-x-0 scale-100"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0 translate-x-8"
    class="fixed top-5 right-4 sm:right-6 z-[100] flex items-start gap-3 px-5 py-4 rounded-2xl shadow-2xl max-w-xs sm:max-w-sm border w-full"
    :class="{'bg-white border-emerald-300 text-emerald-800':type==='success','bg-white border-red-300 text-red-800':type==='error','bg-white border-blue-300 text-blue-800':type==='info'}"
    style="display:none">
    <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
         :class="{'bg-emerald-100':type==='success','bg-red-100':type==='error','bg-blue-100':type==='info'}">
        <i class="fas text-sm" :class="{'fa-check text-emerald-600':type==='success','fa-exclamation text-red-600':type==='error','fa-info text-blue-600':type==='info'}"></i>
    </div>
    <div class="flex-1 min-w-0">
        <p class="font-bold text-sm" x-text="type==='success'?'Success':type==='info'?'Info':'Error'"></p>
        <p class="text-xs mt-0.5 opacity-80 leading-snug break-words" x-text="msg"></p>
    </div>
    <button @click="show=false" class="opacity-40 hover:opacity-80 transition shrink-0"><i class="fas fa-xmark text-sm"></i></button>
</div>

<div class="flex flex-col px-4 sm:px-6 lg:px-8 pt-6 max-w-screen-2xl mx-auto bg-white">

    {{-- ══ HEADER ══ --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 sm:w-14 sm:h-14 rounded-xl bg-[#7a3f91] flex items-center justify-center shadow-lg flex-shrink-0" style="box-shadow:0 4px 14px rgba(122,63,145,.35);">
                <i class="fas fa-calendar-days text-white text-lg sm:text-xl"></i>
            </div>
            <div>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-gray-800 tracking-tight">Event Management</h1>
                <p class="text-gray-500 text-xs sm:text-sm mt-0.5">Review, moderate, and manage all event postings across the platform.</p>
            </div>
        </div>
    </div>

    {{-- ══ TABLE CARD ══ --}}
    <div class="bg-white rounded-2xl shadow-md border border-gray-100 flex flex-col overflow-hidden" style="min-height:0;height:calc(100vh - 210px);">

        {{-- Filter Bar --}}
        <div class="px-4 sm:px-6 py-3 border-b border-gray-100 bg-gray-50/80 flex flex-wrap gap-2 items-center">
            <div class="relative flex-1 min-w-[160px] sm:min-w-[200px] max-w-sm"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                <input type="text" x-model="q" @input.debounce.400ms="$wire.set('search',q)"
                       placeholder="Search title, venue…"
                       class="w-full pl-9 pr-4 py-2 border border-gray-200 rounded-lg text-sm bg-white text-gray-800 transition focus:outline-none focus:border-purple-400 focus:ring-2 focus:ring-purple-100"
                       autocomplete="off">
            </div>
            <select wire:model.live="filterStatus" class="px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white text-gray-700 focus:outline-none focus:border-purple-400 focus:ring-2 focus:ring-purple-100 transition">
                <option value="">All Statuses</option>
                <option value="PENDING">Pending</option>
                <option value="APPROVED">Approved</option>
                <option value="REJECTED">Rejected</option>
                <option value="ORGANIZER_DELETED">Deleted by Organizer</option>
            </select>
            <select wire:model.live="filterCollege" class="px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white text-gray-700 focus:outline-none focus:border-purple-400 focus:ring-2 focus:ring-purple-100 transition hidden sm:block">
                <option value="">All Colleges</option>
                @foreach($this->colleges as $col)<option value="{{ $col }}">{{ $col }}</option>@endforeach
            </select>
            <select wire:model.live="filterSort" class="px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white text-gray-700 focus:outline-none focus:border-purple-400 focus:ring-2 focus:ring-purple-100 transition hidden sm:block">
                <option value="recent">Recent First</option>
                <option value="oldest">Oldest First</option>
            </select>
            <button wire:click="resetFilters" class="px-3 py-2 rounded-lg border border-gray-200 bg-white text-sm font-medium text-gray-600 hover:bg-gray-50 transition flex items-center gap-1.5">
                <i class="fas fa-rotate-left text-xs"></i><span class="hidden sm:inline">Reset</span>
            </button>
        </div>

        {{-- Mobile row 2 --}}
        <div class="px-4 py-2 border-b border-gray-100 bg-gray-50/80 flex gap-2 sm:hidden">
            <select wire:model.live="filterCollege" class="flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white text-gray-700 focus:outline-none focus:border-purple-400 transition">
                <option value="">All Colleges</option>
                @foreach($this->colleges as $col)<option value="{{ $col }}">{{ $col }}</option>@endforeach
            </select>
            <select wire:model.live="filterSort" class="flex-1 px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white text-gray-700 focus:outline-none focus:border-purple-400 transition">
                <option value="recent">Recent First</option>
                <option value="oldest">Oldest First</option>
            </select>
        </div>

        {{-- Table --}}
        <div class="relative flex-1 min-h-0">
            <div class="h-full overflow-y-auto overflow-x-auto"
                 style="scrollbar-width:thin;scrollbar-color:#d1d5db #f3f4f6;"
                 wire:loading.class="opacity-40 pointer-events-none"
                 wire:target="search,filterStatus,filterCollege,filterSort,resetFilters,previousPage,nextPage,executeApprove,executeReject,executeDelete,executeRestore">
                <table class="w-full border-collapse min-w-[700px]">
                    <thead>
                        <tr class="bg-gray-100 border-b border-gray-200 sticky top-0 z-10">
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-bold text-gray-800 uppercase tracking-wider">Event</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-bold text-gray-800 uppercase tracking-wider">Date & Time</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-bold text-gray-800 uppercase tracking-wider hidden md:table-cell">Organizer</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-bold text-gray-800 uppercase tracking-wider hidden lg:table-cell">College</th>
                            <th class="px-4 sm:px-5 py-3.5 text-center text-xs font-bold text-gray-800 uppercase tracking-wider hidden lg:table-cell">RSVPs</th>
                            <th class="px-4 sm:px-5 py-3.5 text-center text-xs font-bold text-gray-800 uppercase tracking-wider">Status</th>
                            <th class="px-4 sm:px-5 py-3.5 text-center text-xs font-bold text-gray-800 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-400">
                        @forelse($this->events as $event)
                        @php
                            $isOrgDeleted = $event->status === 'ORGANIZER_DELETED';
                            if ($event->organizer_id && $event->organizer) {
                                $displayCollege = $event->organizer->department ?? '—';
                            } else {
                                $tp = $event->target_participants ?? '';
                                $parts = explode(' · Batch ', $tp, 2);
                                $displayCollege = trim($parts[0]) ?: 'All Colleges';
                            }
                        @endphp
                        <tr class="{{ $isOrgDeleted ? 'bg-red-50/60 hover:bg-red-50' : 'bg-white hover:bg-gray-50' }} transition-colors duration-100">
                            <td class="px-4 sm:px-5 py-3.5 max-w-[180px] sm:max-w-[220px]">
                                <p class="font-semibold text-sm truncate {{ $isOrgDeleted ? 'text-red-400 line-through' : 'text-gray-800' }}">{{ $event->title }}</p>
                            </td>
                            <td class="px-4 sm:px-5 py-3.5 whitespace-nowrap">
                                <span class="text-sm font-bold {{ $isOrgDeleted ? 'text-red-400' : 'text-gray-700' }}">{{ $event->event_date->setTimezone('Asia/Manila')->format('M d, Y') }}</span>
                                <p class="text-xs {{ $isOrgDeleted ? 'text-red-300' : 'text-gray-700' }} mt-0.5">{{ $event->event_date->setTimezone('Asia/Manila')->format('g:i A') }}@if($event->event_end_date)<span class="{{ $isOrgDeleted ? 'text-red-200' : 'text-gray-700' }} mx-1">–</span>{{ $event->event_end_date->setTimezone('Asia/Manila')->format('g:i A') }}@endif</p>
                            </td>
                            <td class="px-4 sm:px-5 py-3.5 hidden md:table-cell">
                                @if($event->organizer)
                                    <p class="text-xs font-bold {{ $isOrgDeleted ? 'text-red-400' : 'text-gray-700' }}">{{ $event->organizer->name }}</p>
                                @else
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-purple-50 text-purple-700 border border-purple-200 rounded-full text-xs font-bold">
                                        <i class="fas fa-shield-halved text-[9px]"></i> Admin
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 sm:px-5 py-3.5 hidden lg:table-cell">
                                <p class="text-xs {{ $isOrgDeleted ? 'text-red-400' : 'text-gray-700' }} font-bold max-w-[130px] truncate" title="{{ $displayCollege }}">{{ $displayCollege }}</p>
                            </td>
                            <td class="px-4 sm:px-5 py-3.5 text-center hidden lg:table-cell">
                                <div class="flex items-center justify-center gap-1.5">
                                    <span class="relative group">
                                        <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-bold">
                                            <i class="fas fa-circle-check text-[9px]"></i>{{ $event->confirmed_count }}
                                        </span>
                                        <span class="absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5 px-2 py-1 bg-gray-800 text-white text-[10px] font-semibold rounded whitespace-nowrap opacity-0 group-hover:opacity-100 transition pointer-events-none z-20">Confirmed<span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-gray-800"></span></span>
                                    </span>
                                    <span class="relative group">
                                        <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-red-50 border border-red-200 text-red-600 text-xs font-bold">
                                            <i class="fas fa-circle-xmark text-[9px]"></i>{{ $event->declined_count }}
                                        </span>
                                        <span class="absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5 px-2 py-1 bg-gray-800 text-white text-[10px] font-semibold rounded whitespace-nowrap opacity-0 group-hover:opacity-100 transition pointer-events-none z-20">Not Attending<span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-gray-800"></span></span>
                                    </span>
                                    <span class="relative group">
                                        <span class="inline-flex items-center gap-1 px-2 py-1 rounded-lg bg-amber-50 border border-amber-200 text-amber-600 text-xs font-bold">
                                            <i class="fas fa-circle-question text-[9px]"></i>{{ $event->tentative_count }}
                                        </span>
                                        <span class="absolute bottom-full left-1/2 -translate-x-1/2 mb-1.5 px-2 py-1 bg-gray-800 text-white text-[10px] font-semibold rounded whitespace-nowrap opacity-0 group-hover:opacity-100 transition pointer-events-none z-20">Maybe<span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-gray-800"></span></span>
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 sm:px-5 py-3.5 text-center whitespace-nowrap">
                                @if($isOrgDeleted)
                                    <span class="inline-block px-2.5 py-1 bg-red-100 text-red-700 border border-red-300 rounded-full text-[11px] font-bold">Deleted by Organizer</span>
                                @elseif($event->status==='PENDING')
                                    <span class="inline-block px-2.5 py-1 bg-yellow-50 text-yellow-700 border border-yellow-200 rounded-full text-[11px] font-bold">Pending</span>
                                @elseif($event->status==='APPROVED')
                                    <span class="inline-block px-2.5 py-1 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full text-[11px] font-bold">Approved</span>
                                @else
                                    <span class="inline-block px-2.5 py-1 bg-red-50 text-red-700 border border-red-200 rounded-full text-[11px] font-bold">Rejected</span>
                                @endif
                            </td>
                            <td class="px-4 sm:px-5 py-3.5 text-center">
                                <div class="flex items-center justify-center gap-1.5 flex-wrap">
                                    <button wire:click="viewEvent({{ $event->id }})" class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-bold text-purple-700 bg-purple-50 border border-purple-200 hover:bg-purple-100 rounded-lg transition">
                                        <i class="fas fa-eye text-[10px]"></i><span class="hidden sm:inline">View</span>
                                    </button>
                                    @if($isOrgDeleted)
                                        <button wire:click="confirmRestore({{ $event->id }})" class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-bold text-orange-600 bg-white border border-orange-200 hover:bg-orange-50 rounded-lg transition">
                                            <i class="fas fa-rotate-left text-[10px]"></i><span class="hidden sm:inline">Restore</span>
                                        </button>
                                    @elseif($event->status==='PENDING')
                                        <button wire:click="confirmApprove({{ $event->id }})" class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-bold text-emerald-600 bg-white border border-emerald-200 hover:bg-emerald-50 rounded-lg transition">
                                            <i class="fas fa-check text-[10px]"></i><span class="hidden sm:inline">Approve</span>
                                        </button>
                                        <button wire:click="confirmReject({{ $event->id }})" class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-bold text-red-600 bg-white border border-red-200 hover:bg-red-50 rounded-lg transition">
                                            <i class="fas fa-xmark text-[10px]"></i><span class="hidden sm:inline">Reject</span>
                                        </button>
                                    @elseif($event->status==='APPROVED')
                                        <button wire:click="openEditModal({{ $event->id }})" class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-bold text-blue-600 bg-white border border-blue-200 hover:bg-blue-50 rounded-lg transition">
                                            <i class="fas fa-pen-to-square text-[10px]"></i><span class="hidden sm:inline">Edit</span>
                                        </button>
                                    @elseif($event->status==='REJECTED')
                                        <button wire:click="confirmApprove({{ $event->id }})" class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-bold text-emerald-600 bg-white border border-emerald-200 hover:bg-emerald-50 rounded-lg transition">
                                            <i class="fas fa-rotate-left text-[10px]"></i><span class="hidden sm:inline">Re-Approve</span>
                                        </button>
                                    @endif
                                    <button wire:click="confirmDelete({{ $event->id }})" class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-bold text-red-600 bg-white border border-red-200 hover:bg-red-50 rounded-lg transition">
                                        <i class="fas fa-trash text-[10px]"></i><span class="hidden lg:inline">Delete</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="py-20 text-center">
                                <div class="flex flex-col items-center gap-3">
                                    <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center">
                                        <i class="fas fa-calendar-days text-2xl text-gray-300"></i>
                                    </div>
                                    <p class="font-semibold text-gray-400">No events found</p>
                                    <p class="text-sm text-gray-400">@if($search||$filterStatus||$filterCollege)Try adjusting your filters.@else No events have been submitted yet.@endif</p>
                                </div>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Pagination --}}
        <div class="px-4 sm:px-5 py-3.5 border-t border-gray-100 bg-[#2b0d3e] shrink-0">
            @php
                $total = $this->events->total();
                $pp    = $this->events->perPage();
                $cp    = $this->events->currentPage();
                $from  = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
                $to    = min($cp * $pp, $total);
            @endphp
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                <p class="text-white text-xs sm:text-sm">Showing <span class="font-bold text-white">{{ $from }}–{{ $to }}</span> of <span class="font-bold text-white">{{ $total }}</span> events</p>
                <div class="flex items-center gap-1.5">
                    @if($this->events->onFirstPage())
                        <button disabled class="px-3 sm:px-4 py-2 bg-gray-100 text-gray-400 rounded-lg text-xs sm:text-sm font-semibold cursor-not-allowed">← Prev</button>
                    @else
                        <button wire:click="previousPage" class="px-3 sm:px-4 py-2 text-white rounded-lg text-xs sm:text-sm font-semibold transition" style="background:#7a3f91;" onmouseover="this.style.background='#5e2f72'" onmouseout="this.style.background='#7a3f91'">← Prev</button>
                    @endif
                    <span class="px-3 py-2 bg-white border border-gray-200 rounded-lg text-gray-600 text-xs sm:text-sm font-semibold shadow-sm">{{ $cp }} / {{ $this->events->lastPage() }}</span>
                    @if($this->events->hasMorePages())
                        <button wire:click="nextPage" class="px-3 sm:px-4 py-2 text-white rounded-lg text-xs sm:text-sm font-semibold transition" style="background:#7a3f91;" onmouseover="this.style.background='#5e2f72'" onmouseout="this.style.background='#7a3f91'">Next →</button>
                    @else
                        <button disabled class="px-3 sm:px-4 py-2 bg-gray-100 text-gray-400 rounded-lg text-xs sm:text-sm font-semibold cursor-not-allowed">Next →</button>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ════ MODAL: Edit ════ --}}
@if($showFormModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-2 sm:p-4 bg-black/50 backdrop-blur-sm" @keydown.escape.window="$wire.closeFormModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[95vh] sm:max-h-[92vh] flex flex-col overflow-hidden"
         x-data="{}"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95 translate-y-2"
         x-transition:enter-end="opacity-100 scale-100 translate-y-0"
         x-effect="if($wire.formErrors && Object.keys($wire.formErrors).length>0){$nextTick(()=>{const el=$refs.formScroll;if(el)el.scrollTo({top:0,behavior:'smooth'});});}">

        <div class="flex items-center justify-between px-5 sm:px-7 py-5 text-white flex-shrink-0" style="background:#7a3f91;">
            <div>
                <h2 class="text-xl font-extrabold flex items-center gap-3"><i class="fas fa-pen-to-square"></i> Edit Event</h2>
            </div>
            <button wire:click="closeFormModal" class="text-white/70 hover:text-white text-2xl leading-none transition">×</button>
        </div>

        @if(count($formErrors))
        <div class="bg-red-50 border-b border-red-200 px-5 sm:px-7 py-4 flex-shrink-0">
            <p class="font-bold text-red-800 text-sm mb-2 flex items-center gap-2"><i class="fas fa-triangle-exclamation"></i> Please fix the following:</p>
            <ul class="text-red-700 text-sm space-y-1">
                @foreach($formErrors as $err)<li class="flex items-start gap-2"><span class="text-red-400 mt-0.5">•</span><span>{{ $err }}</span></li>@endforeach
            </ul>
        </div>
        @endif

        <div class="flex-1 min-h-0 overflow-y-auto px-5 sm:px-7 py-5 space-y-5" x-ref="formScroll" style="scrollbar-width:thin;scrollbar-color:#d1d5db #f3f4f6;">

            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase tracking-wide mb-2">Event Photo <span class="text-gray-400 font-normal normal-case">(Optional)</span></label>
                <div x-data="{isDragging:false}" @dragover.prevent="isDragging=true" @dragleave.prevent="isDragging=false" @drop.prevent="isDragging=false"
                     class="border-2 rounded-xl p-5 text-center cursor-pointer transition-all"
                     :class="isDragging?'border-purple-400 bg-purple-50':'{{ ($photo||($existingPhotoUrl&&!$removePhoto))?'border-purple-400 border-solid bg-purple-50/50':'border-dashed border-gray-300 hover:border-purple-400 hover:bg-purple-50/30' }}'">
                    <label class="cursor-pointer block">
                        <input type="file" wire:model="photo" accept="image/*" class="hidden">
                        @if($photo)
                            <div class="flex flex-col items-center gap-3"><img src="{{ $photo->temporaryUrl() }}" class="w-32 h-24 object-cover rounded-xl shadow border border-purple-200"><p class="text-xs font-semibold text-purple-600"><i class="fas fa-check-circle mr-1"></i>New photo selected</p></div>
                        @elseif($existingPhotoUrl&&!$removePhoto)
                            <div class="flex flex-col items-center gap-3"><img src="{{ $existingPhotoUrl }}" class="w-32 h-24 object-cover rounded-xl shadow border border-gray-200"><p class="text-xs font-semibold text-gray-500">Current photo — click to change</p></div>
                        @else
                            <div class="flex flex-col items-center gap-2 py-2"><i class="fas fa-cloud-arrow-up text-3xl text-gray-300"></i><p class="font-semibold text-gray-500 text-sm">Click to upload or drag & drop</p><p class="text-xs text-gray-400">JPG, PNG, WEBP — max 5MB</p></div>
                        @endif
                    </label>
                </div>
                @if($existingPhotoUrl&&!$removePhoto&&!$photo)
                    <div class="mt-2 flex items-center gap-2">
                        <button type="button" wire:click="$set('removePhoto',true)" class="text-xs text-red-500 hover:text-red-700 font-semibold flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-red-200 hover:bg-red-50 transition"><i class="fas fa-trash text-[10px]"></i> Remove photo</button>
                        <span class="text-xs text-gray-400">(uses default)</span>
                    </div>
                @endif
                @if($removePhoto)
                    <div class="mt-2 flex items-center gap-2"><span class="text-xs text-amber-600 font-semibold"><i class="fas fa-exclamation-circle mr-1"></i>Photo will be removed on save</span><button type="button" wire:click="$set('removePhoto',false)" class="text-xs text-blue-500 underline">Undo</button></div>
                @endif
                <div wire:loading wire:target="photo" class="mt-2 text-xs text-purple-600 flex items-center gap-2"><i class="fas fa-spinner animate-spin"></i> Uploading…</div>
            </div>

            <div class="border border-gray-200 rounded-xl overflow-hidden">
                <div class="bg-gray-50 px-4 py-3 border-b border-gray-200 flex items-center gap-2">
                    <i class="fas fa-circle-info text-purple-500 text-sm"></i>
                    <span class="text-sm font-bold text-gray-700">Event Details</span>
                </div>
                <div class="p-4 sm:p-5 space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-600 uppercase tracking-wide mb-1.5">Event Title <span class="text-red-500">*</span></label>
                        <input wire:model.defer="title" type="text" placeholder="e.g. PHILCST Alumni Homecoming 2026"
                               class="w-full px-4 py-2.5 border rounded-lg text-sm text-gray-800 bg-white transition focus:outline-none focus:ring-2 {{ isset($formErrors['title'])?'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100':'border-gray-200 focus:border-purple-400 focus:ring-purple-100' }}">
                        @if(isset($formErrors['title']))<p class="mt-1.5 text-xs text-red-600 flex items-start gap-1.5"><i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i><span>{{ $formErrors['title'] }}</span></p>@endif
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 uppercase tracking-wide mb-1.5">Description</label>
                        <textarea wire:model.defer="description" rows="3" placeholder="Describe the event, agenda, highlights…"
                                  class="w-full px-4 py-2.5 border border-gray-200 rounded-lg text-sm text-gray-800 bg-white focus:outline-none focus:border-purple-400 focus:ring-2 focus:ring-purple-100 transition resize-none"></textarea>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 uppercase tracking-wide mb-1.5">Event Date <span class="text-red-500">*</span></label>
                        <input wire:model="event_date" type="date"
                               class="w-full px-4 py-2.5 border rounded-lg text-sm text-gray-800 bg-white transition focus:outline-none focus:ring-2 {{ isset($formErrors['event_date'])?'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100':'border-gray-200 focus:border-purple-400 focus:ring-purple-100' }}">
                        @if(isset($formErrors['event_date']))<p class="mt-1.5 text-xs text-red-600 flex items-start gap-1.5"><i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i><span>{{ $formErrors['event_date'] }}</span></p>@endif
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-600 uppercase tracking-wide mb-1.5">Start Time <span class="text-red-500">*</span></label>
                            <input wire:model="start_time" type="text" placeholder="e.g. 8:00 AM"
                                   class="w-full px-4 py-2.5 border rounded-lg text-sm text-gray-800 bg-white transition focus:outline-none focus:ring-2 {{ isset($formErrors['start_time'])?'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100':'border-gray-200 focus:border-purple-400 focus:ring-purple-100' }}">
                            @if(isset($formErrors['start_time']))<p class="mt-1.5 text-xs text-red-600 flex items-start gap-1.5"><i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i><span>{{ $formErrors['start_time'] }}</span></p>@endif
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 uppercase tracking-wide mb-1.5">End Time <span class="text-gray-400 font-normal normal-case">(Optional)</span></label>
                            <input wire:model="end_time" type="text" placeholder="e.g. 5:00 PM"
                                   class="w-full px-4 py-2.5 border rounded-lg text-sm text-gray-800 bg-white transition focus:outline-none focus:ring-2 {{ isset($formErrors['end_time'])?'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100':'border-gray-200 focus:border-purple-400 focus:ring-purple-100' }}">
                            @if(isset($formErrors['end_time']))<p class="mt-1.5 text-xs text-red-600 flex items-start gap-1.5"><i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i><span>{{ $formErrors['end_time'] }}</span></p>@endif
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-600 uppercase tracking-wide mb-1.5">Venue / Location <span class="text-red-500">*</span></label>
                            <input wire:model.defer="venue" type="text" placeholder="e.g. PHILCST Main Gym"
                                   class="w-full px-4 py-2.5 border rounded-lg text-sm text-gray-800 bg-white transition focus:outline-none focus:ring-2 {{ isset($formErrors['venue'])?'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100':'border-gray-200 focus:border-purple-400 focus:ring-purple-100' }}">
                            @if(isset($formErrors['venue']))<p class="mt-1.5 text-xs text-red-600 flex items-start gap-1.5"><i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i><span>{{ $formErrors['venue'] }}</span></p>@endif
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-600 uppercase tracking-wide mb-1.5">Full Address <span class="text-gray-400 font-normal normal-case">(Optional)</span></label>
                            <input wire:model.defer="venue_address" type="text"
                                   placeholder="e.g. Old Nalsian Road, Nalsian, Calasiao, Pangasinan"
                                   class="w-full px-4 py-2.5 border border-gray-200 rounded-lg text-sm text-gray-800 bg-white focus:outline-none focus:border-purple-400 focus:ring-2 focus:ring-purple-100 transition">
                        </div>
                    </div>
                </div>
            </div>

            <div class="border border-gray-200 rounded-xl overflow-hidden">
                <div class="bg-gray-50 px-4 py-3 border-b border-gray-200 flex items-center gap-2">
                    <i class="fas fa-users text-purple-500 text-sm"></i>
                    <span class="text-sm font-bold text-gray-700">Target Participants</span>
                </div>
                <div class="p-4 sm:p-5 space-y-4">
                    <div class="flex gap-3">
                        <button type="button" wire:click="$set('targetMode','all')"
                                class="flex-1 py-3 px-3 border rounded-lg text-xs font-bold transition flex flex-col items-center gap-1.5 {{ $targetMode==='all'?'border-purple-500 text-white':'border-gray-200 text-gray-500 hover:border-purple-400 hover:text-purple-600 hover:bg-purple-50/50 bg-white' }}"
                                style="{{ $targetMode==='all'?'background:#7a3f91;':'' }}">
                            <i class="fas fa-globe text-base"></i><span>All Colleges</span>
                        </button>
                        <button type="button" wire:click="$set('targetMode','college')"
                                class="flex-1 py-3 px-3 border rounded-lg text-xs font-bold transition flex flex-col items-center gap-1.5 {{ $targetMode==='college'?'border-purple-500 text-white':'border-gray-200 text-gray-500 hover:border-purple-400 hover:text-purple-600 hover:bg-purple-50/50 bg-white' }}"
                                style="{{ $targetMode==='college'?'background:#7a3f91;':'' }}">
                            <i class="fas fa-building-columns text-base"></i><span>Specific College(s)</span>
                        </button>
                    </div>
                    @if($targetMode === 'all')
                        <div class="flex items-center gap-3 bg-purple-50 border border-purple-200 rounded-xl px-4 py-3">
                            <i class="fas fa-globe text-purple-500 text-lg"></i>
                            <div><div class="text-sm font-bold text-purple-800">All Colleges</div><div class="text-xs text-purple-600 mt-0.5">Visible to all alumni regardless of college.</div></div>
                        </div>
                    @elseif($targetMode === 'college')
                        <div>
                            @if(isset($formErrors['target']))<p class="text-xs text-red-600 flex items-start gap-1.5 mb-2"><i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i><span>{{ $formErrors['target'] }}</span></p>@endif
                            @if(count($this->colleges) > 0)
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-xs font-bold text-gray-500 uppercase tracking-wide">Select College(s)</span>
                                    <div class="flex gap-3">
                                        <button type="button" wire:click="$set('selectedColleges', {{ json_encode($this->colleges) }})" class="text-xs text-purple-600 font-bold hover:underline"><i class="fas fa-check-double mr-1"></i>Select All</button>
                                        @if(count($selectedColleges) > 0)<button type="button" wire:click="$set('selectedColleges', [])" class="text-xs text-gray-400 hover:text-red-500 font-bold hover:underline">Clear</button>@endif
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                    @foreach($this->colleges as $col)
                                    <label class="flex items-center gap-2 px-3 py-2 border rounded-lg cursor-pointer transition text-xs font-semibold {{ in_array($col,$selectedColleges)?'border-purple-400 bg-purple-50 text-purple-700':'border-gray-200 text-gray-600 hover:border-purple-300 hover:bg-purple-50/40' }}">
                                        <input type="checkbox" wire:model.live="selectedColleges" value="{{ $col }}" class="accent-purple-600 w-3.5 h-3.5"><span>{{ $col }}</span>
                                    </label>
                                    @endforeach
                                </div>
                                @if(count($selectedColleges) > 0)
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @foreach($selectedColleges as $col)
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-purple-50 border border-purple-200 text-purple-700 text-xs font-bold rounded-lg"><i class="fas fa-building-columns text-[9px]"></i>{{ $col }}</span>
                                    @endforeach
                                </div>
                                @endif
                                @if(count($this->organizersForSelectedColleges) > 0)
                                <div class="mt-4 border border-purple-100 rounded-xl overflow-hidden">
                                    <div class="bg-purple-50 px-4 py-2.5 border-b border-purple-100 flex items-center gap-2">
                                        <i class="fas fa-user-tie text-purple-500 text-xs"></i>
                                        <span class="text-xs font-bold text-purple-700 uppercase tracking-wide">Organizers in Selected College(s)</span>
                                    </div>
                                    <div class="divide-y divide-gray-100">
                                        @foreach($this->organizersForSelectedColleges as $org)
                                        <div class="flex items-center gap-3 px-4 py-2.5">
                                            <div class="w-7 h-7 rounded-full bg-purple-100 flex items-center justify-center shrink-0"><i class="fas fa-user text-purple-500 text-[10px]"></i></div>
                                            <div class="flex-1 min-w-0"><p class="text-xs font-bold text-gray-700 truncate">{{ $org['name'] }}</p><p class="text-[11px] text-gray-400 truncate">{{ $org['department'] }}@if(!empty($org['email'])) · {{ $org['email'] }}@endif</p></div>
                                        </div>
                                        @endforeach
                                    </div>
                                </div>
                                @elseif($targetMode === 'college' && count($selectedColleges) > 0)
                                <div class="mt-3 flex items-center gap-2 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2.5">
                                    <i class="fas fa-triangle-exclamation text-amber-500 shrink-0"></i>
                                    <span>No organizers are assigned to the selected college(s).</span>
                                </div>
                                @endif
                            @else
                                <div class="text-center py-4 text-gray-400 text-sm"><i class="fas fa-triangle-exclamation text-amber-400 mr-2"></i>No colleges found.</div>
                            @endif
                        </div>
                    @endif
                    <div class="pt-3 border-t border-gray-100">
                        <label class="block text-xs font-bold text-gray-600 uppercase tracking-wide mb-1.5">Batch Year <span class="text-gray-400 font-normal normal-case text-xs">(Optional)</span></label>
                        <input wire:model.defer="batchYear" type="number" min="1990" max="{{ now()->year + 5 }}" placeholder="e.g. {{ now()->year - 2 }}"
                               class="w-full sm:max-w-xs px-4 py-2.5 border rounded-lg text-sm text-gray-800 bg-white transition focus:outline-none focus:ring-2 {{ isset($formErrors['batch_year'])?'border-red-400 bg-red-50 focus:border-red-400 focus:ring-red-100':'border-gray-200 focus:border-purple-400 focus:ring-purple-100' }}">
                        @if(isset($formErrors['batch_year']))<p class="mt-1.5 text-xs text-red-600 flex items-start gap-1.5"><i class="fas fa-circle-exclamation mt-0.5 flex-shrink-0"></i><span>{{ $formErrors['batch_year'] }}</span></p>@endif
                    </div>
                </div>
            </div>

            <div class="border border-gray-200 rounded-xl overflow-hidden">
                <div class="bg-gray-50 px-4 py-3 border-b border-gray-200 flex items-center gap-2">
                    <i class="fas fa-address-card text-purple-500 text-sm"></i>
                    <span class="text-sm font-bold text-gray-700">Contact Person</span>
                    @if($editingIsOrganizerEvent)<span class="inline-flex items-center gap-1.5 text-xs font-bold text-amber-700 bg-amber-50 border border-amber-200 px-2.5 py-1 rounded-lg ml-auto shrink-0"><i class="fas fa-lock text-[10px]"></i> Organizer's contact — read only</span>@endif
                </div>
                <div class="p-4 sm:p-5 grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-600 uppercase tracking-wide mb-1.5">Name</label>
                        <input wire:model.defer="contact_person" type="text" placeholder="Full name" @if($editingIsOrganizerEvent) readonly @endif
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-lg text-sm text-gray-800 bg-white focus:outline-none focus:border-purple-400 focus:ring-2 focus:ring-purple-100 transition {{ $editingIsOrganizerEvent?'cursor-not-allowed bg-gray-50':'' }}">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 uppercase tracking-wide mb-1.5">Email</label>
                        <input wire:model.defer="contact_email" type="email" placeholder="contact@example.com" @if($editingIsOrganizerEvent) readonly @endif
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-lg text-sm text-gray-800 bg-white focus:outline-none focus:border-purple-400 focus:ring-2 focus:ring-purple-100 transition {{ $editingIsOrganizerEvent?'cursor-not-allowed bg-gray-50':'' }}">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-600 uppercase tracking-wide mb-1.5">Phone</label>
                        <input wire:model.defer="contact_phone" type="text" placeholder="+63 9XX XXX XXXX" @if($editingIsOrganizerEvent) readonly @endif
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-lg text-sm text-gray-800 bg-white focus:outline-none focus:border-purple-400 focus:ring-2 focus:ring-purple-100 transition {{ $editingIsOrganizerEvent?'cursor-not-allowed bg-gray-50':'' }}">
                    </div>
                    @if($editingIsOrganizerEvent)<div class="col-span-1 sm:col-span-3"><p class="text-xs text-gray-400"><i class="fas fa-circle-info text-[10px] mr-1"></i>Contact details belong to the organizer and cannot be edited by admin.</p></div>@endif
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-600 uppercase tracking-wide mb-1.5">Additional Notes / Requirements</label>
                <textarea wire:model.defer="notes" rows="3" placeholder="Dress code, special instructions…"
                          class="w-full px-4 py-2.5 border border-gray-200 rounded-lg text-sm text-gray-800 bg-white focus:outline-none focus:border-purple-400 focus:ring-2 focus:ring-purple-100 transition resize-none"></textarea>
            </div>
        </div>

        <div class="px-5 sm:px-7 py-4 border-t border-gray-100 bg-gray-50/70 flex-shrink-0 flex gap-3">
            <button type="button" wire:click="closeFormModal" class="flex-1 px-4 py-2.5 border border-gray-200 text-gray-700 rounded-xl text-sm font-bold hover:bg-gray-100 transition">Cancel</button>
            <button type="button" wire:click="saveEvent" wire:loading.attr="disabled" wire:target="saveEvent"
                    class="flex-1 px-4 py-2.5 text-white rounded-xl text-sm font-extrabold flex items-center justify-center gap-2 transition shadow-md disabled:opacity-50 disabled:cursor-not-allowed"
                    style="background:#7a3f91;" onmouseover="this.style.background='#5e2f72'" onmouseout="this.style.background='#7a3f91'">
                <span wire:loading wire:target="saveEvent"><i class="fas fa-spinner animate-spin"></i> Saving…</span>
                <span wire:loading.remove wire:target="saveEvent"><i class="fas fa-floppy-disk mr-1"></i>Save Changes</span>
            </button>
        </div>
    </div>
</div>
@endif

{{-- ════ MODAL: View Event ════ --}}
@if($showViewModal && $this->viewingEvent)
@php $ev=$this->viewingEvent;$totalRsvp=$ev->confirmed_count+$ev->declined_count+$ev->tentative_count;$isOrgDeleted=$ev->status==='ORGANIZER_DELETED'; @endphp
<div class="fixed inset-0 z-50 flex items-center justify-center p-2 sm:p-4 bg-black/50 backdrop-blur-sm" @keydown.escape.window="$wire.closeViewModal()">
    <div class="bg-white rounded-2xl shadow-2xl flex flex-col w-full max-w-2xl max-h-[95vh] sm:max-h-[92vh] overflow-hidden relative"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95 translate-y-2"
         x-transition:enter-end="opacity-100 scale-100 translate-y-0">
        <button wire:click="closeViewModal" class="absolute top-3 right-3 z-10 w-8 h-8 rounded-full bg-black/40 hover:bg-black/60 text-white flex items-center justify-center text-lg leading-none transition">×</button>
        <img src="{{ $ev->photo_url }}" alt="{{ $ev->title }}" class="w-full h-44 sm:h-64 object-cover flex-shrink-0 {{ $isOrgDeleted?'opacity-60':'' }}">
        <div class="px-5 sm:px-8 pt-5 pb-4 border-b border-gray-100 flex-shrink-0">
            @if($isOrgDeleted)
                <div class="flex items-center gap-2 bg-red-50 border border-red-200 rounded-xl px-3 py-2.5 mb-3 text-xs font-bold text-red-700">
                    <i class="fas fa-trash text-red-500 shrink-0"></i>
                    Deleted by <strong>{{ $ev->deleted_by ?? $ev->organizer?->name ?? 'Organizer' }}</strong> · {{ $ev->updated_at->setTimezone('Asia/Manila')->format('M d, Y · g:i A') }}
                </div>
            @endif
            <div class="flex items-start justify-between gap-3 mb-4">
                <h2 class="text-lg sm:text-xl font-bold leading-snug {{ $isOrgDeleted?'line-through text-red-400':'text-gray-900' }}">{{ $ev->title }}</h2>
                @if($isOrgDeleted)<span class="flex-shrink-0 px-2.5 py-1 bg-red-100 text-red-700 border border-red-300 rounded-full text-[11px] font-bold">Deleted by Organizer</span>
                @elseif($ev->status==='PENDING')<span class="flex-shrink-0 px-2.5 py-1 bg-yellow-50 text-yellow-700 border border-yellow-200 rounded-full text-[11px] font-bold">Pending</span>
                @elseif($ev->status==='APPROVED')<span class="flex-shrink-0 px-2.5 py-1 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-full text-[11px] font-bold">Approved</span>
                @else<span class="flex-shrink-0 px-2.5 py-1 bg-red-50 text-red-700 border border-red-200 rounded-full text-[11px] font-bold">Rejected</span>@endif
            </div>
            <ul class="space-y-2">
                <li class="flex items-start gap-3 text-sm text-gray-700"><i class="fas fa-calendar text-purple-500 mt-0.5 w-4 flex-shrink-0"></i><span>{{ $ev->event_date->setTimezone('Asia/Manila')->format('F d, Y') }}</span></li>
                <li class="flex items-start gap-3 text-sm text-gray-700"><i class="fas fa-clock text-purple-500 mt-0.5 w-4 flex-shrink-0"></i>
                    <span>{{ $ev->event_date->setTimezone('Asia/Manila')->format('g:i A') }}@if($ev->event_end_date)<span class="text-gray-400 mx-1">–</span>{{ $ev->event_end_date->setTimezone('Asia/Manila')->format('g:i A') }}@else<span class="text-gray-400 italic ml-1">· End time not set</span>@endif</span>
                </li>
                <li class="flex items-start gap-3 text-sm text-gray-700"><i class="fas fa-location-dot text-purple-500 mt-0.5 w-4 flex-shrink-0"></i><span>{{ $ev->venue }}@if($ev->venue_address) · <span class="text-gray-500">{{ $ev->venue_address }}</span>@endif</span></li>
                @if($ev->target_participants)<li class="flex items-start gap-3 text-sm text-gray-700"><i class="fas fa-users text-purple-500 mt-0.5 w-4 flex-shrink-0"></i><span>{{ $ev->target_participants }}</span></li>@endif
                <li class="flex items-start gap-3 text-sm text-gray-700"><i class="fas fa-{{ $ev->organizer ? 'user-tie' : 'shield-halved' }} text-purple-500 mt-0.5 w-4 flex-shrink-0"></i><span>{{ $ev->organizer ? $ev->organizer->name.' · '.$ev->organizer->department : 'Posted by Admin' }}</span></li>
            </ul>
            <p class="text-xs text-gray-400 mt-3">Posted {{ $ev->created_at->diffForHumans() }}</p>
        </div>
        <div class="flex-1 min-h-0 overflow-y-auto" style="scrollbar-width:thin;scrollbar-color:#d1d5db #f3f4f6;">
            <div class="px-5 sm:px-8 py-5 border-b border-gray-100">
                <h3 class="text-sm font-bold text-gray-800 mb-3">Attendee Responses @if($totalRsvp>0)<span class="text-gray-400 font-normal text-xs ml-1">{{ $totalRsvp }} total</span>@endif</h3>
                @if($totalRsvp===0)
                    <div class="text-center py-6 text-gray-400 text-sm"><i class="fas fa-inbox text-2xl block mb-2 text-gray-200"></i>No responses yet.</div>
                @else
                    <div class="grid grid-cols-3 gap-3">
                        <div class="bg-emerald-50 border border-emerald-200 rounded-xl p-4 text-center"><i class="fas fa-circle-check text-emerald-500 text-lg mb-1"></i><div class="text-2xl font-black text-emerald-700">{{ $ev->confirmed_count }}</div><div class="text-[11px] font-bold text-emerald-600 uppercase tracking-wide mt-1">Confirmed</div></div>
                        <div class="bg-red-50 border border-red-200 rounded-xl p-4 text-center"><i class="fas fa-circle-xmark text-red-500 text-lg mb-1"></i><div class="text-2xl font-black text-red-700">{{ $ev->declined_count }}</div><div class="text-[11px] font-bold text-red-600 uppercase tracking-wide mt-1">Not Attending</div></div>
                        <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-center"><i class="fas fa-circle-question text-amber-500 text-lg mb-1"></i><div class="text-2xl font-black text-amber-700">{{ $ev->tentative_count }}</div><div class="text-[11px] font-bold text-amber-600 uppercase tracking-wide mt-1">Maybe</div></div>
                    </div>
                @endif
            </div>
            <div class="px-5 sm:px-8 py-5 border-b border-gray-100">
                <h3 class="text-sm font-bold text-gray-800 mb-3">Status</h3>
                @if($isOrgDeleted)
                    <div class="bg-red-50 border border-red-200 rounded-xl px-4 py-3"><p class="text-sm font-bold text-red-800"><i class="fas fa-trash mr-2 text-red-500"></i>Deleted by Organizer</p><p class="text-xs text-red-700 mt-1">You can restore this event to put it back to Pending review.</p></div>
                @elseif($ev->status==='PENDING')
                    <div class="bg-yellow-50 border border-yellow-200 rounded-xl px-4 py-3"><p class="text-sm font-bold text-yellow-800"><i class="fas fa-hourglass-half mr-2 text-yellow-500"></i>Pending Admin Review</p><p class="text-xs text-yellow-700 mt-1">This event is waiting for your approval.</p></div>
                @elseif($ev->status==='APPROVED')
                    <div class="bg-emerald-50 border border-emerald-200 rounded-xl px-4 py-3"><p class="text-sm font-bold text-emerald-800"><i class="fas fa-circle-check mr-2 text-emerald-500"></i>Approved</p>@if($ev->reviewed_at)<p class="text-xs text-emerald-700 mt-1">{{ $ev->reviewed_at->setTimezone('Asia/Manila')->format('M d, Y · g:i A') }}</p>@endif@if($ev->review_remarks)<p class="text-xs text-emerald-600 mt-1 italic">"{{ $ev->review_remarks }}"</p>@endif</div>
                @else
                    <div class="bg-red-50 border border-red-200 rounded-xl px-4 py-3"><p class="text-sm font-bold text-red-800"><i class="fas fa-circle-xmark mr-2 text-red-500"></i>Rejected</p>@if($ev->review_remarks)<p class="text-xs text-red-600 mt-2"><span class="font-semibold">Reason:</span> {{ $ev->review_remarks }}</p>@endif</div>
                @endif
            </div>
            @if($ev->description)<div class="px-5 sm:px-8 py-5 border-b border-gray-100"><h3 class="text-sm font-bold text-gray-800 mb-3">About This Event</h3><p class="text-sm text-gray-700 leading-relaxed whitespace-pre-wrap">{{ $ev->description }}</p></div>@endif
            @if($ev->notes)<div class="px-5 sm:px-8 py-5 border-b border-gray-100"><h3 class="text-sm font-bold text-gray-800 mb-3">Additional Notes</h3><p class="text-sm text-gray-700 leading-relaxed whitespace-pre-wrap">{{ $ev->notes }}</p></div>@endif
            <div class="px-5 sm:px-8 py-5">
                <p class="text-[10px] font-bold text-gray-400 uppercase tracking-widest mb-3">Posting Details</p>
                <div class="grid grid-cols-2 sm:grid-cols-3 border border-gray-100 rounded-xl overflow-hidden divide-x divide-y divide-gray-100">
                    <div class="px-4 py-3"><p class="text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1">Submitted</p><p class="text-sm font-semibold text-gray-800">{{ $ev->created_at->setTimezone('Asia/Manila')->format('M d, Y') }}</p><p class="text-xs text-gray-400">{{ $ev->created_at->setTimezone('Asia/Manila')->format('g:i A') }}</p></div>
                    <div class="px-4 py-3"><p class="text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1">College</p><p class="text-sm font-semibold text-gray-800">{{ $ev->target_participants ?? 'All Colleges' }}</p></div>
                    <div class="px-4 py-3 col-span-2 sm:col-span-1"><p class="text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-1">Status</p>
                        @if($isOrgDeleted)<p class="text-sm font-semibold text-red-600">Deleted by Organizer</p>
                        @elseif($ev->status==='PENDING')<p class="text-sm font-semibold text-yellow-600">Pending</p>
                        @elseif($ev->status==='APPROVED')<p class="text-sm font-semibold text-emerald-600">Approved</p>
                        @else<p class="text-sm font-semibold text-red-600">Rejected</p>@endif
                    </div>
                    <div class="px-4 py-3 col-span-2 sm:col-span-3">
                        <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wide mb-2">Last Updated</p>
                        <div class="flex items-center gap-3 flex-wrap">
                            <div><p class="text-sm font-semibold text-gray-800">{{ $ev->updated_at->setTimezone('Asia/Manila')->format('M d, Y · g:i A') }}</p><p class="text-xs text-gray-400">{{ $ev->updated_at->diffForHumans() }}</p></div>
                            @if($ev->deleted_by)
                                <span class="inline-flex items-center gap-1.5 text-xs font-bold px-2.5 py-1 bg-red-50 text-red-700 border border-red-200 rounded-lg"><i class="fas fa-trash text-[9px]"></i> {{ $ev->deleted_by }}@if($ev->deleted_by_role) <span class="opacity-60 font-normal">· {{ ucfirst($ev->deleted_by_role) }}</span>@endif</span>
                            @elseif(isset($ev->was_edited)&&$ev->was_edited&&$ev->updated_by)
                                @if($ev->updated_by_role==='admin')<span class="inline-flex items-center gap-1.5 text-xs font-bold px-2.5 py-1 bg-purple-50 text-purple-700 border border-purple-200 rounded-lg"><i class="fas fa-shield-halved text-[9px]"></i> {{ $ev->updated_by }} <span class="opacity-60 font-normal">· Admin</span></span>
                                @else<span class="inline-flex items-center gap-1.5 text-xs font-bold px-2.5 py-1 bg-emerald-50 text-emerald-700 border border-emerald-200 rounded-lg"><i class="fas fa-user-pen text-[9px]"></i> {{ $ev->updated_by }} <span class="opacity-60 font-normal">· Organizer</span></span>@endif
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="px-5 sm:px-8 py-4 border-t border-gray-100 flex items-center justify-end gap-2 flex-wrap bg-white flex-shrink-0">
            <button wire:click="closeViewModal" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-bold text-gray-600 border border-gray-200 bg-white hover:bg-gray-50 rounded-xl transition"><i class="fas fa-xmark text-xs"></i> Close</button>
            <button wire:click="confirmDelete({{ $ev->id }})" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-bold text-red-600 border border-red-200 bg-white hover:bg-red-50 rounded-xl transition"><i class="fas fa-trash text-xs"></i> Delete</button>
            @if($isOrgDeleted)
                <button wire:click="confirmRestore({{ $ev->id }})" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-bold text-orange-600 border border-orange-200 bg-white hover:bg-orange-50 rounded-xl transition"><i class="fas fa-rotate-left text-xs"></i> Restore</button>
            @else
                @if($ev->status==='PENDING')
                    <button wire:click="confirmReject({{ $ev->id }})" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-bold text-red-600 border border-red-200 bg-white hover:bg-red-50 rounded-xl transition"><i class="fas fa-xmark text-xs"></i> Reject</button>
                    <button wire:click="confirmApprove({{ $ev->id }})" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-bold text-emerald-600 border border-emerald-200 bg-white hover:bg-emerald-50 rounded-xl transition"><i class="fas fa-check text-xs"></i> Approve</button>
                @elseif($ev->status==='APPROVED')
                    <button wire:click="confirmReject({{ $ev->id }})" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-bold text-red-600 border border-red-200 bg-white hover:bg-red-50 rounded-xl transition"><i class="fas fa-ban text-xs"></i> Revoke</button>
                @elseif($ev->status==='REJECTED')
                    <button wire:click="confirmApprove({{ $ev->id }})" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-bold text-emerald-600 border border-emerald-200 bg-white hover:bg-emerald-50 rounded-xl transition"><i class="fas fa-rotate-left text-xs"></i> Re-Approve</button>
                @endif
                <button wire:click="openEditModal({{ $ev->id }})" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-bold text-blue-600 border border-blue-200 bg-white hover:bg-blue-50 rounded-xl transition"><i class="fas fa-pen-to-square text-xs"></i> Edit</button>
            @endif
        </div>
    </div>
</div>
@endif

{{-- ════ MODAL: Approve ════ --}}
@if($showApproveModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" @keydown.escape.window="$wire.cancelApprove()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95 translate-y-2" x-transition:enter-end="opacity-100 scale-100 translate-y-0">
        <div class="px-6 py-5 bg-emerald-50 border-b border-emerald-100"><h2 class="text-lg font-extrabold text-emerald-800 flex items-center gap-2.5"><div class="w-8 h-8 bg-emerald-100 rounded-xl flex items-center justify-center"><i class="fas fa-circle-check text-emerald-600 text-sm"></i></div>Approve Event</h2></div>
        <div class="p-6">
            <p class="text-gray-500 text-sm mb-1">You are about to approve:</p>
            <p class="font-extrabold text-emerald-700 text-base mb-4">"{{ $approveEventTitle }}"</p>
            <div class="mb-5">
                <label class="block text-xs font-bold text-gray-600 uppercase tracking-wide mb-1.5">Remarks <span class="text-gray-400 font-normal normal-case">(Optional)</span></label>
                <textarea wire:model.defer="approveRemarks" rows="2" placeholder="e.g. Approved. Great event proposal!" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm text-gray-800 bg-white focus:outline-none focus:border-emerald-400 focus:ring-2 focus:ring-emerald-100 transition resize-none"></textarea>
            </div>
            <div class="flex gap-3">
                <button wire:click="cancelApprove" class="flex-1 px-4 py-2.5 border border-gray-200 text-gray-700 rounded-xl text-sm font-bold hover:bg-gray-50 transition">Cancel</button>
                <button wire:click="executeApprove" wire:loading.attr="disabled" wire:target="executeApprove" class="flex-1 px-4 py-2.5 bg-emerald-600 hover:bg-emerald-700 disabled:bg-emerald-300 text-white rounded-xl text-sm font-extrabold flex items-center justify-center gap-2 transition">
                    <span wire:loading wire:target="executeApprove"><i class="fas fa-spinner animate-spin"></i></span>
                    <span wire:loading.remove wire:target="executeApprove"><i class="fas fa-circle-check mr-1"></i> Yes, Approve</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ════ MODAL: Reject ════ --}}
@if($showRejectModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" @keydown.escape.window="$wire.cancelReject()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95 translate-y-2" x-transition:enter-end="opacity-100 scale-100 translate-y-0">
        <div class="px-6 py-5 bg-red-50 border-b border-red-100"><h2 class="text-lg font-extrabold text-red-800 flex items-center gap-2.5"><div class="w-8 h-8 bg-red-100 rounded-xl flex items-center justify-center"><i class="fas fa-circle-xmark text-red-500 text-sm"></i></div>Reject Event</h2></div>
        <div class="p-6">
            <p class="text-gray-500 text-sm mb-1">You are about to reject:</p>
            <p class="font-extrabold text-red-700 text-base mb-4">"{{ $rejectEventTitle }}"</p>
            <div class="mb-5">
                <label class="block text-xs font-bold text-gray-600 uppercase tracking-wide mb-1.5">Reason for Rejection <span class="text-red-500">*</span></label>
                <textarea wire:model.defer="rejectRemarks" rows="3" placeholder="e.g. Missing required details." class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm text-gray-800 bg-white focus:outline-none focus:border-red-400 focus:ring-2 focus:ring-red-100 transition resize-none"></textarea>
                <p class="mt-1.5 text-xs text-gray-400"><i class="fas fa-circle-info text-[10px] mr-1"></i>Required — organizer will see this reason.</p>
            </div>
            <div class="flex gap-3">
                <button wire:click="cancelReject" class="flex-1 px-4 py-2.5 border border-gray-200 text-gray-700 rounded-xl text-sm font-bold hover:bg-gray-50 transition">Cancel</button>
                <button wire:click="executeReject" wire:loading.attr="disabled" wire:target="executeReject" class="flex-1 px-4 py-2.5 bg-red-600 hover:bg-red-700 disabled:bg-red-300 text-white rounded-xl text-sm font-extrabold flex items-center justify-center gap-2 transition">
                    <span wire:loading wire:target="executeReject"><i class="fas fa-spinner animate-spin"></i></span>
                    <span wire:loading.remove wire:target="executeReject"><i class="fas fa-circle-xmark mr-1"></i> Yes, Reject</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ════ MODAL: Restore ════ --}}
@if($showRestoreModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" @keydown.escape.window="$wire.cancelRestore()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95 translate-y-2" x-transition:enter-end="opacity-100 scale-100 translate-y-0">
        <div class="px-6 py-5 bg-orange-50 border-b border-orange-100"><h2 class="text-lg font-extrabold text-orange-800 flex items-center gap-2.5"><div class="w-8 h-8 bg-orange-100 rounded-xl flex items-center justify-center"><i class="fas fa-rotate-left text-orange-500 text-sm"></i></div>Restore Event</h2></div>
        <div class="p-6">
            <p class="text-gray-500 text-sm mb-1">You are about to restore:</p>
            <p class="font-extrabold text-orange-700 text-base mb-4">"{{ $restoreEventTitle }}"</p>
            <div class="bg-blue-50 border border-blue-200 rounded-xl px-4 py-3 mb-5 text-xs text-blue-800 font-semibold flex items-start gap-2"><i class="fas fa-info-circle text-blue-500 mt-0.5 shrink-0"></i><span>The event will be set back to <strong>PENDING</strong> for review. The organizer will see it in their list again.</span></div>
            <div class="flex gap-3">
                <button wire:click="cancelRestore" class="flex-1 px-4 py-2.5 border border-gray-200 text-gray-700 rounded-xl text-sm font-bold hover:bg-gray-50 transition">Cancel</button>
                <button wire:click="executeRestore" wire:loading.attr="disabled" wire:target="executeRestore" class="flex-1 px-4 py-2.5 bg-orange-500 hover:bg-orange-600 disabled:bg-orange-300 text-white rounded-xl text-sm font-extrabold flex items-center justify-center gap-2 transition">
                    <span wire:loading wire:target="executeRestore"><i class="fas fa-spinner animate-spin"></i></span>
                    <span wire:loading.remove wire:target="executeRestore"><i class="fas fa-rotate-left mr-1"></i> Yes, Restore</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ════ MODAL: Delete ════ --}}
@if($showDeleteModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm" @keydown.escape.window="$wire.cancelDelete()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95 translate-y-2" x-transition:enter-end="opacity-100 scale-100 translate-y-0">
        <div class="px-6 py-5 bg-red-50 border-b border-red-100"><h2 class="text-lg font-extrabold text-red-800 flex items-center gap-2.5"><div class="w-8 h-8 bg-red-100 rounded-xl flex items-center justify-center"><i class="fas fa-triangle-exclamation text-red-500 text-sm"></i></div>Permanently Delete</h2></div>
        <div class="p-6">
            <p class="text-gray-500 text-sm mb-1">You are about to permanently delete:</p>
            <p class="font-extrabold text-red-700 text-base mb-3">"{{ $deleteEventTitle }}"</p>
            <div class="bg-red-50 border border-red-100 rounded-xl px-4 py-3 mb-5 text-xs text-gray-600 flex items-start gap-2"><i class="fas fa-exclamation-circle text-red-400 mt-0.5 shrink-0"></i><span>This action <strong>cannot be undone</strong>. The event and its photo will be permanently removed.</span></div>
            <div class="flex gap-3">
                <button wire:click="cancelDelete" class="flex-1 px-4 py-2.5 border border-gray-200 text-gray-700 rounded-xl text-sm font-bold hover:bg-gray-50 transition">Cancel</button>
                <button wire:click="executeDelete" wire:loading.attr="disabled" wire:target="executeDelete" class="flex-1 px-4 py-2.5 bg-red-600 hover:bg-red-700 disabled:bg-red-300 text-white rounded-xl text-sm font-extrabold flex items-center justify-center gap-2 transition shadow-md">
                    <span wire:loading wire:target="executeDelete"><i class="fas fa-spinner animate-spin"></i></span>
                    <span wire:loading.remove wire:target="executeDelete"><i class="fas fa-trash mr-1"></i> Yes, Delete</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

</div>