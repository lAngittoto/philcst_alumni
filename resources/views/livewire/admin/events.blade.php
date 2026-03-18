<?php
/**
 * FILE: resources/views/livewire/admin/event-management.blade.php
 *
 * Admin Event Manager — TALL Stack / Livewire Volt
 * - View ALL events (from all organizers + admin-posted)
 * - Approve / Reject with confirmation popup + optional remarks
 * - Create / Edit / Delete events (admin-posted auto-approved)
 * - Target participants: select specific college(s) OR all colleges OR specific batch year
 * - Same design as organizer event-management
 */

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use App\Models\AdminEvent;
use App\Http\Controllers\AdminEventController;
use Illuminate\Support\Facades\Storage;

new class extends Component {
    use WithPagination, WithFileUploads;

    protected string $paginationTheme = 'tailwind';

    // ── Filters ───────────────────────────────────────────────
    public string $search        = '';
    public string $filterStatus  = '';
    public string $filterSort    = 'recent';
    public string $filterYear    = '';
    public string $filterCollege = '';

    // ── Form ──────────────────────────────────────────────────
    public bool   $showFormModal  = false;
    public bool   $isEditing      = false;
    public ?int   $editingEventId = null;

    public string $title         = '';
    public string $description   = '';
    public string $event_date    = '';
    public string $start_time    = '';
    public string $end_time      = '';
    public string $venue         = '';
    public string $venue_address = '';
    public string $contact_person= '';
    public string $contact_email = '';
    public string $contact_phone = '';
    public string $notes         = '';

    // ── Target Participants (admin-specific) ──────────────────
    // 'all'     = All Colleges
    // 'college' = Specific colleges (multi-select)
    // 'year'    = Specific batch year
    public string $targetMode     = 'all';
    public array  $selectedColleges = [];
    public string $batchYear      = '';

    public $photo           = null;
    public ?string $existingPhotoUrl = null;
    public bool   $removePhoto  = false;

    // ── View Modal ────────────────────────────────────────────
    public bool   $showViewModal  = false;
    public ?int   $viewingEventId = null;

    // ── Approve Modal ─────────────────────────────────────────
    public bool   $showApproveModal  = false;
    public ?int   $approveEventId    = null;
    public string $approveEventTitle = '';
    public string $approveRemarks    = '';

    // ── Reject Modal ──────────────────────────────────────────
    public bool   $showRejectModal   = false;
    public ?int   $rejectEventId     = null;
    public string $rejectEventTitle  = '';
    public string $rejectRemarks     = '';

    // ── Delete Modal ──────────────────────────────────────────
    public bool   $showDeleteModal   = false;
    public ?int   $deleteEventId     = null;
    public string $deleteEventTitle  = '';

    // ── Restore Modal ─────────────────────────────────────────
    public bool   $showRestoreModal   = false;
    public ?int   $restoreEventId     = null;
    public string $restoreEventTitle  = '';

    // ── Deleted tab ───────────────────────────────────────────
    public bool   $showDeletedTab     = false;

    public array  $formErrors = [];

    // ── Lifecycle ─────────────────────────────────────────────
    public function updatingSearch()        { $this->resetPage(); }
    public function updatingFilterStatus()  { $this->resetPage(); }
    public function updatingFilterSort()    { $this->resetPage(); }
    public function updatingFilterYear()    { $this->resetPage(); }
    public function updatingFilterCollege() { $this->resetPage(); }

    public function updatedTargetMode(): void
    {
        $this->selectedColleges = [];
        $this->batchYear        = '';
    }

    public function updatedShowDeletedTab(): void { $this->resetPage(); }

    // ── Computed ──────────────────────────────────────────────
    #[Computed]
    public function events()
    {
        $q = AdminEvent::with('organizer')
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
        if ($this->filterStatus  !== '') $q->where('status', $this->filterStatus);
        if ($this->filterCollege !== '') $q->where('target_participants', 'like', "%{$this->filterCollege}%");
        if ($this->filterYear    !== '') $q->whereYear('event_date', $this->filterYear);

        $q->orderBy('created_at', $this->filterSort === 'oldest' ? 'asc' : 'desc');
        return $q->paginate(20);
    }

    #[Computed]
    public function viewingEvent(): ?AdminEvent
    {
        if (!$this->viewingEventId) return null;
        return AdminEvent::with('organizer')
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
    public function eventYears(): array
    {
        $dbYears = app(AdminEventController::class)->getEventYears();
        // Also include current and next year in case no events yet
        $current = now()->year;
        return collect(array_merge($dbYears, [$current, $current + 1]))
            ->unique()->sortDesc()->values()->toArray();
    }

    #[Computed]
    public function deletedEvents()
    {
        return AdminEvent::onlyTrashed()
            ->with('organizer')
            ->orderByDesc('deleted_at')
            ->paginate(20);
    }

    // ── Stats ─────────────────────────────────────────────────
    #[Computed]
    public function stats(): array
    {
        return [
            'total'    => AdminEvent::count(),
            'pending'  => AdminEvent::where('status', 'PENDING')->count(),
            'approved' => AdminEvent::where('status', 'APPROVED')->count(),
            'rejected' => AdminEvent::where('status', 'REJECTED')->count(),
            'deleted'  => AdminEvent::onlyTrashed()->count(),
        ];
    }

    // ── Filters reset ─────────────────────────────────────────
    public function resetFilters(): void
    {
        $this->search = $this->filterStatus = $this->filterYear = $this->filterCollege = '';
        $this->filterSort = 'recent';
        $this->resetPage();
    }

    // ── Create ────────────────────────────────────────────────
    public function openCreateModal(): void
    {
        $this->resetFormFields();
        $this->event_date     = now()->addWeek()->format('Y-m-d');
        $this->contact_person = auth()->user()?->name ?? '';
        $this->showFormModal  = true;
    }

    // ── Edit ──────────────────────────────────────────────────
    public function openEditModal(int $id): void
    {
        $event = app(AdminEventController::class)->getEvent($id);

        $this->isEditing        = true;
        $this->editingEventId   = $id;
        $this->title            = $event->title;
        $this->description      = $event->description ?? '';
        $this->event_date       = $event->event_date->format('Y-m-d');
        $this->start_time       = $event->event_date->format('g:i A');
        $this->end_time         = $event->event_end_date?->format('g:i A') ?? '';
        $this->venue            = $event->venue;
        $this->venue_address    = $event->venue_address ?? '';
        $this->contact_person   = $event->contact_person ?? '';
        $this->contact_email    = $event->contact_email ?? '';
        $this->contact_phone    = $event->contact_phone ?? '';
        $this->notes            = $event->notes ?? '';
        $this->existingPhotoUrl = $event->photo_url;
        $this->removePhoto      = false;
        $this->photo            = null;
        $this->formErrors       = [];

        // Parse "BSX, BSIT · Batch 2022" or "All Colleges · Batch 2022" or "All Colleges"
        $tp = $event->target_participants ?? '';
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

    // ── Save ──────────────────────────────────────────────────
    public function saveEvent(): void
    {
        $this->formErrors = [];
        $errors = [];

        if (!trim($this->title))      $errors['title']      = 'Event title is required.';
        if (!trim($this->event_date)) $errors['event_date'] = 'Event date is required.';
        if (!trim($this->start_time)) $errors['start_time'] = 'Start time is required.';
        if (!trim($this->venue))      $errors['venue']      = 'Venue / Location is required.';
        if ($this->targetMode === 'college' && empty($this->selectedColleges)) {
            $errors['target'] = 'Please select at least one college.';
        }

        if (!empty($errors)) { $this->formErrors = $errors; return; }

        // Build target_participants string: "BSX, BSIT · Batch 2022" or "All Colleges"
        $collegesStr = $this->targetMode === 'all' ? 'All Colleges' : implode(', ', $this->selectedColleges);
        $yearSuffix  = trim($this->batchYear) ? ' · Batch ' . trim($this->batchYear) : '';
        $targetStr   = $collegesStr . $yearSuffix;

        $data = [
            'title'               => trim($this->title),
            'description'         => trim($this->description) ?: null,
            'event_date'          => $this->event_date . ' ' . $this->start_time,
            'event_end_date'      => ($this->event_date && trim($this->end_time))
                                        ? $this->event_date . ' ' . $this->end_time
                                        : null,
            'venue'               => trim($this->venue),
            'venue_address'       => trim($this->venue_address) ?: null,
            'target_participants' => $targetStr,
            'contact_person'      => trim($this->contact_person) ?: null,
            'contact_email'       => trim($this->contact_email) ?: null,
            'contact_phone'       => trim($this->contact_phone) ?: null,
            'notes'               => trim($this->notes) ?: null,
        ];

        $ctrl  = app(AdminEventController::class);
        $photo = $this->photo;

        if ($this->isEditing) {
            if ($this->removePhoto && !$photo) {
                $event = $ctrl->getEvent($this->editingEventId);
                if ($event->photo && $event->photo !== AdminEvent::DEFAULT_PHOTO) {
                    Storage::disk('public')->delete($event->photo);
                }
                $data['photo'] = null;
                $event->update(array_merge($data, ['updated_by' => auth()->user()?->name, 'updated_by_role' => 'admin']));
            } else {
                $ctrl->updateEvent($this->editingEventId, $data, $photo ?: null);
            }
            $this->dispatch('flash-message', type: 'success', message: 'Event updated successfully!');
        } else {
            $ctrl->createEvent($data, $photo ?: null);
            $this->dispatch('flash-message', type: 'success', message: 'Event created and approved!');
        }

        $this->showFormModal = false;
        $this->resetFormFields();
    }

    // ── View ──────────────────────────────────────────────────
    public function viewEvent(int $id): void
    {
        $this->viewingEventId = $id;
        $this->showViewModal  = true;
    }

    public function closeViewModal(): void
    {
        $this->showViewModal  = false;
        $this->viewingEventId = null;
    }

    // ── Approve ───────────────────────────────────────────────
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
        $this->showApproveModal  = false;
        $this->approveEventId    = null;
        $this->approveRemarks    = '';
    }

    // ── Reject ────────────────────────────────────────────────
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
        $this->showRejectModal  = false;
        $this->rejectEventId    = null;
        $this->rejectRemarks    = '';
    }

    // ── Delete ────────────────────────────────────────────────
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

    // ── Restore ───────────────────────────────────────────────
    public function confirmRestore(int $id): void
    {
        $event = AdminEvent::onlyTrashed()->findOrFail($id);
        $this->restoreEventId    = $id;
        $this->restoreEventTitle = $event->title;
        $this->showRestoreModal  = true;
    }

    public function executeRestore(): void
    {
        if ($this->restoreEventId) {
            $event = AdminEvent::onlyTrashed()->findOrFail($this->restoreEventId);
            $event->restore();
            $event->update([
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
    }

    public function cancelRestore(): void
    {
        $this->showRestoreModal  = false;
        $this->restoreEventId    = null;
        $this->restoreEventTitle = '';
    }

    // ── Helpers ───────────────────────────────────────────────
    private function resetFormFields(): void
    {
        $this->title = $this->description = $this->event_date = $this->start_time = $this->end_time = '';
        $this->venue = $this->venue_address = $this->contact_person = $this->contact_email = '';
        $this->contact_phone = $this->notes = '';
        $this->targetMode = 'all';
        $this->selectedColleges = [];
        $this->batchYear = '';
        $this->photo = null;
        $this->existingPhotoUrl = null;
        $this->removePhoto = false;
        $this->formErrors = [];
        $this->editingEventId = null;
        $this->isEditing = false;
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
    thead.btn-primary:hover,thead.btn-primary th:hover{background:linear-gradient(135deg,#7a3f91,#6a3580)!important;box-shadow:none!important}
    .btn-primary:disabled{background:linear-gradient(135deg,#cbd5e1,#94a3b8);cursor:not-allowed}
    .input-focus:focus{border-color:#7a3f91!important;box-shadow:0 0 0 3px rgba(122,63,145,.1)!important;outline:none!important}
    .tbl-row{}
    .tbl-container{transition:opacity .2s ease}
    .tbl-loading{opacity:.45;pointer-events:none}
    .form-label{display:block;font-size:.8rem;font-weight:700;color:#374151;margin-bottom:.5rem}
    .form-input{width:100%;padding:.625rem 1rem;border:1.5px solid #d1d5db;border-radius:.5rem;font-size:.875rem;color:#1e293b;background:#fff;transition:border-color .15s,box-shadow .15s}
    .form-input:focus{border-color:#7a3f91!important;box-shadow:0 0 0 3px rgba(122,63,145,.1)!important;outline:none!important}
    .form-error{font-size:.75rem;color:#ef4444;margin-top:.375rem;display:flex;align-items:center;gap:.3rem}
    .field-error{border-color:#ef4444!important;background:#fff8f8!important}
    .field-hint{font-size:.72rem;color:#94a3b8;margin-top:.3rem}
    .photo-upload-area{border:2px dashed #d1d5db;border-radius:10px;padding:28px 20px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s;background:#fafafa}
    .photo-upload-area:hover{border-color:#7a3f91;background:#faf5ff}
    .photo-upload-area.has-preview{border-style:solid;border-color:#7a3f91;background:#faf5ff}
    .badge-pending{background:#fef9c3;color:#a16207;border:1px solid #fde68a;font-size:11px;font-weight:700;border-radius:4px;padding:3px 10px}
    .badge-approved{background:#dcfce7;color:#15803d;border:1px solid #bbf7d0;font-size:11px;font-weight:700;border-radius:4px;padding:3px 10px}
    .badge-rejected{background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5;font-size:11px;font-weight:700;border-radius:4px;padding:3px 10px}

    /* ── Target mode buttons ── */
    .target-btn{flex:1;padding:11px 10px;border:1.5px solid #e2e8f0;border-radius:8px;background:#fff;cursor:pointer;transition:all .18s;text-align:center;font-size:.8rem;font-weight:700;color:#64748b;display:flex;flex-direction:column;align-items:center;gap:6px}
    .target-btn:hover{border-color:#7a3f91;color:#7a3f91;background:#faf5ff}
    .target-btn.active{border-color:#7a3f91;background:linear-gradient(135deg,#7a3f91,#6a3580);color:#fff;box-shadow:0 3px 12px rgba(122,63,145,.35)}

    /* ── College checkboxes ── */
    .college-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px;margin-top:10px}
    .college-check{display:flex;align-items:center;gap:8px;padding:8px 12px;border:1.5px solid #e2e8f0;border-radius:8px;cursor:pointer;transition:all .15s;font-size:.8rem;font-weight:600;color:#374151}
    .college-check:hover{border-color:#7a3f91;background:#faf5ff}
    .college-check.checked{border-color:#7a3f91;background:#f5f0ff;color:#6d28d9}
    .college-check input[type=checkbox]{accent-color:#7a3f91;width:15px;height:15px}

    /* ── Stat cards ── */
    .stat-card{background:#fff;border-radius:10px;padding:16px 20px;border:1px solid #e2e8f0;display:flex;align-items:center;gap:14px}
    .stat-icon{width:40px;height:40px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}

    /* ── View modal ── */
    .ev-modal{background:#fff;border-radius:12px;box-shadow:0 16px 56px rgba(0,0,0,.22);display:flex;flex-direction:column;width:780px;max-width:96vw;max-height:92vh;overflow:hidden;border-top:5px solid #7a3f91}
    .ev-header{background:#fff;padding:0;border-bottom:1px solid #ebebeb;flex-shrink:0;position:relative}
    .ev-cover{width:100%;height:320px;object-fit:cover;display:block}
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
    .review-box{border-radius:8px;padding:14px 16px;border:1.5px solid}
    .review-box-pending{background:#fffbeb;border-color:#fde68a}
    .review-box-approved{background:#f0fdf4;border-color:#bbf7d0}
    .review-box-rejected{background:#fff1f2;border-color:#fecdd3}
    .ev-footer{padding:14px 28px;border-top:1px solid #ebebeb;display:flex;align-items:center;justify-content:flex-end;background:#fff;flex-shrink:0;gap:8px}
    .ev-btn{display:inline-flex;align-items:center;gap:7px;padding:10px 20px;border-radius:4px;font-size:13.5px;font-weight:700;cursor:pointer;transition:background .15s,box-shadow .15s;border:none;font-family:inherit}
    .ev-btn-close{background:#fff;border:1.5px solid #ddd;color:#444}
    .ev-btn-close:hover{background:#f5f5f5}
    .ev-btn-edit{background:#2557a7;color:#fff}
    .ev-btn-edit:hover{background:#1c4487}
    .ev-btn-approve{background:#16a34a;color:#fff}
    .ev-btn-approve:hover{background:#15803d}
    .ev-btn-reject{background:#fff;border:1.5px solid #fca5a5;color:#be123c}
    .ev-btn-reject:hover{background:#fff1f2}
    .ev-btn-delete{background:#fff;border:1.5px solid #e2e8f0;color:#64748b}
    .ev-btn-delete:hover{background:#f8fafc}
    .ev-close-x{position:absolute;top:12px;right:14px;width:30px;height:30px;border-radius:50%;border:none;background:rgba(0,0,0,.35);color:#fff;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .12s;line-height:1;z-index:2}
    .ev-close-x:hover{background:rgba(0,0,0,.55)}
</style>

{{-- ── FLASH ────────────────────────────────────────────────── --}}
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

<div class="flex flex-col flex-1 min-h-0 px-8 pt-7 pb-6 overflow-y-auto scrollbar-custom">

    {{-- ── PAGE HEADER ── --}}
    <div class="flex items-center justify-between mb-5 shrink-0" style="animation:slideInDown .5s ease-out;">
        <h1 class="text-3xl font-bold text-slate-800 flex items-center gap-3">
            <div class="w-11 h-11 btn-primary rounded-lg flex items-center justify-center shadow-md shrink-0">
                <i class="fas fa-calendar-days text-base"></i>
            </div>
            Event Management
        </h1>
        <div class="flex items-center gap-3">
            <button wire:click="$toggle('showDeletedTab')"
                    class="inline-flex items-center gap-2 px-4 py-3 rounded-lg font-semibold text-sm border transition-all
                    {{ $showDeletedTab ? 'bg-slate-700 text-white border-slate-700' : 'bg-white text-slate-600 border-slate-300 hover:bg-slate-50' }}">
                <i class="fas fa-trash-can-arrow-up"></i> Deleted
                @if($this->stats['deleted'] > 0)
                <span class="inline-flex items-center justify-center min-w-5 h-5 px-1 text-xs font-extrabold rounded-full {{ $showDeletedTab ? 'bg-white text-slate-700' : 'bg-red-500 text-white' }}">{{ $this->stats['deleted'] }}</span>
                @endif
            </button>
            <button wire:click="openCreateModal"
                    class="inline-flex items-center gap-2 px-5 py-3 btn-primary rounded-lg font-semibold text-sm hover:shadow-lg transition-all">
                <i class="fas fa-plus"></i> Create Event
            </button>
        </div>
    </div>

    {{-- ── STAT CARDS ── --}}
    <div class="grid grid-cols-4 gap-4 mb-5 shrink-0">
        <div class="stat-card">
            <div class="stat-icon btn-primary"><i class="fas fa-calendar-days text-white"></i></div>
            <div><div class="text-2xl font-extrabold text-slate-800">{{ $this->stats['total'] }}</div><div class="text-xs font-semibold text-slate-500 mt-0.5">Total Events</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:#fef9c3;"><i class="fas fa-hourglass-half text-yellow-600"></i></div>
            <div><div class="text-2xl font-extrabold text-yellow-700">{{ $this->stats['pending'] }}</div><div class="text-xs font-semibold text-slate-500 mt-0.5">Pending Review</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:#dcfce7;"><i class="fas fa-circle-check text-green-600"></i></div>
            <div><div class="text-2xl font-extrabold text-green-700">{{ $this->stats['approved'] }}</div><div class="text-xs font-semibold text-slate-500 mt-0.5">Approved</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon" style="background:#fee2e2;"><i class="fas fa-circle-xmark text-red-500"></i></div>
            <div><div class="text-2xl font-extrabold text-red-600">{{ $this->stats['rejected'] }}</div><div class="text-xs font-semibold text-slate-500 mt-0.5">Rejected</div></div>
        </div>
    </div>

    {{-- ── TABLE PANEL ── --}}
    <div class="bg-white rounded-lg shadow-sm flex flex-col border border-slate-200 shrink-0" style="height:calc(90vh - 280px);">

        {{-- Filters --}}
        <div class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex flex-wrap gap-3 items-center shrink-0">
            <div class="relative flex-1 min-w-[180px] max-w-xs">
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
            <select wire:model.live="filterCollege" class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
                <option value="">All Colleges</option>
                @foreach($this->colleges as $col)
                    <option value="{{ $col }}">{{ $col }}</option>
                @endforeach
            </select>
            <select wire:model.live="filterYear" class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
                <option value="">All Years</option>
                @foreach($this->eventYears as $yr)
                    <option value="{{ $yr }}">{{ $yr }}</option>
                @endforeach
            </select>
            <select wire:model.live="filterSort" class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
                <option value="recent">Recent First</option>
                <option value="oldest">Oldest First</option>
            </select>
            <button wire:click="resetFilters" class="px-4 py-2.5 text-slate-700 hover:bg-slate-100 rounded-lg border border-slate-200 transition text-sm font-medium">
                <i class="fas fa-rotate-left mr-2"></i>Reset
            </button>
            <span wire:loading wire:target="search,filterStatus,filterCollege,filterYear,filterSort,resetFilters">
                <i class="fas fa-spinner spin-icon text-purple-500 text-sm"></i>
            </span>
        </div>

        {{-- Table --}}
        <div class="flex-1 min-h-0 overflow-y-auto overflow-x-auto scrollbar-custom tbl-container"
             wire:loading.class="tbl-loading"
             wire:target="search,filterStatus,filterCollege,filterYear,filterSort,resetFilters,previousPage,nextPage">
            <table class="w-full border-separate border-spacing-0">
                <thead class="btn-primary text-white" style="position:sticky;top:0;z-index:10;">
                    <tr>
                        <th class="px-4 py-4 text-left text-xs font-semibold uppercase tracking-wide w-14">Photo</th>
                        <th class="px-4 py-4 text-left text-xs font-semibold uppercase tracking-wide">Event</th>
                        <th class="px-4 py-4 text-left text-xs font-semibold uppercase tracking-wide">Date & Time</th>
                        <th class="px-4 py-4 text-left text-xs font-semibold uppercase tracking-wide">Organizer</th>
                        <th class="px-4 py-4 text-left text-xs font-semibold uppercase tracking-wide">Target</th>
                        <th class="px-4 py-4 text-center text-xs font-semibold uppercase tracking-wide">RSVPs</th>
                        <th class="px-4 py-4 text-center text-xs font-semibold uppercase tracking-wide">Status</th>
                        <th class="px-4 py-4 text-center text-xs font-semibold uppercase tracking-wide">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($this->events as $event)
                    <tr class="tbl-row">
                        <td class="px-4 py-3">
                            <img src="{{ $event->photo_url }}" alt="{{ $event->title }}"
                                 class="w-11 h-11 rounded-lg object-cover border border-slate-200 shadow-sm">
                        </td>
                        <td class="px-4 py-4 max-w-[200px]">
                            <p class="font-semibold text-slate-900 text-sm truncate">{{ $event->title }}</p>
                            <p class="text-xs text-slate-400 mt-0.5 truncate">{{ $event->venue }}</p>
                        </td>
                        <td class="px-4 py-4">
                            <p class="text-sm font-semibold text-slate-700">{{ $event->event_date->format('M d, Y') }}</p>
                            <p class="text-xs text-slate-400 mt-0.5">
                                {{ $event->event_date->format('g:i A') }}
                                @if($event->event_end_date)<span class="text-slate-300 mx-1">–</span>{{ $event->event_end_date->format('g:i A') }}@endif
                            </p>
                        </td>
                        <td class="px-4 py-4">
                            @if($event->organizer)
                                <p class="text-xs font-semibold text-slate-700">{{ $event->organizer->name }}</p>
                                <p class="text-xs text-slate-400">{{ $event->organizer->department }}</p>
                            @else
                                <span class="inline-flex items-center gap-1 text-xs font-bold text-purple-600 bg-purple-50 px-2 py-1 rounded">
                                    <i class="fas fa-shield-halved text-[9px]"></i> Admin
                                </span>
                            @endif
                        </td>
                        <td class="px-4 py-4">
                            <p class="text-xs text-slate-600 font-medium max-w-[120px] truncate" title="{{ $event->target_participants }}">
                                {{ $event->target_participants ?? 'All' }}
                            </p>
                        </td>
                        <td class="px-4 py-4 text-center">
                            <div class="flex items-center justify-center gap-1.5 text-xs font-bold">
                                <span class="text-green-600"><i class="fas fa-check-circle mr-0.5"></i>{{ $event->confirmed_count }}</span>
                                <span class="text-slate-300">|</span>
                                <span class="text-red-400"><i class="fas fa-times-circle mr-0.5"></i>{{ $event->declined_count }}</span>
                                <span class="text-slate-300">|</span>
                                <span class="text-yellow-500"><i class="fas fa-question-circle mr-0.5"></i>{{ $event->tentative_count }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-4 text-center">
                            @if($event->status === 'PENDING')
                                <span class="badge-pending">⏳ Pending</span>
                            @elseif($event->status === 'APPROVED')
                                <span class="badge-approved">✓ Approved</span>
                            @else
                                <span class="badge-rejected">✗ Rejected</span>
                            @endif
                        </td>
                        <td class="px-4 py-4 text-center">
                            <div class="flex items-center justify-center gap-1.5">
                                <button wire:click="viewEvent({{ $event->id }})"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-bold text-purple-700 hover:bg-purple-50 rounded-lg transition border border-purple-200">
                                    <i class="fas fa-eye"></i> View
                                </button>
                                @if($event->status === 'PENDING')
                                <button wire:click="confirmApprove({{ $event->id }})"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-bold text-green-700 hover:bg-green-50 rounded-lg transition border border-green-200">
                                    Approve
                                </button>
                                <button wire:click="confirmReject({{ $event->id }})"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-bold text-red-600 hover:bg-red-50 rounded-lg transition border border-red-200">
                                    Reject
                                </button>
                                @endif
                                <button wire:click="confirmDelete({{ $event->id }})"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-bold text-slate-500 hover:bg-slate-50 rounded-lg transition border border-slate-200">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="8" class="py-16 text-center">
                            <i class="fas fa-calendar-days text-5xl text-slate-200 block mb-4"></i>
                            <p class="font-semibold text-slate-400">No events found</p>
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
                <p class="text-slate-600 text-sm">Showing <span class="font-semibold">{{ $from }}–{{ $to }}</span> of <span class="font-semibold">{{ $total }}</span></p>
                <div class="flex gap-2 items-center">
                    @if($this->events->onFirstPage())
                        <button disabled class="px-4 py-2 bg-slate-200 text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">← Prev</button>
                    @else
                        <button wire:click="previousPage" class="px-4 py-2 btn-primary rounded-lg text-sm font-medium">← Prev</button>
                    @endif
                    <span class="px-3 py-2 text-slate-700 text-sm font-medium">{{ $this->events->currentPage() }} / {{ $this->events->lastPage() }}</span>
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

{{-- ══════════════════════════════════════════════════════
     MODAL: Create / Edit
══════════════════════════════════════════════════════ --}}
@if($showFormModal)
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm backdrop-animate">
    <div class="bg-white rounded-xl shadow-2xl modal-animate w-full flex flex-col" style="max-width:840px;max-height:92vh;border-top:5px solid #7a3f91;">

        {{-- Purple header --}}
        <div class="flex items-center justify-between px-7 py-5 shrink-0 rounded-t-xl" style="background:linear-gradient(135deg,#7a3f91,#5c2d6e);">
            <div class="flex items-center gap-3">
                <i class="fas fa-{{ $isEditing ? 'pen' : 'calendar-plus' }} text-white text-lg"></i>
                <div>
                    <h2 class="text-lg font-bold text-white">{{ $isEditing ? 'Edit Event' : 'Create New Event' }}</h2>
                    <p class="text-xs mt-0.5" style="color:rgba(255,255,255,.7)"><i class="fas fa-shield-halved mr-1"></i>Admin — event will be auto-approved.</p>
                </div>
            </div>
            <button wire:click="closeFormModal" class="w-8 h-8 flex items-center justify-center rounded-lg text-white/70 hover:text-white hover:bg-white/20 transition text-xl leading-none">&times;</button>
        </div>

        @if(count($formErrors))
        <div class="bg-red-50 border-b border-red-200 px-7 py-4 shrink-0">
            <p class="font-semibold text-red-800 text-sm mb-2"><i class="fas fa-triangle-exclamation mr-2"></i>Please fix the following:</p>
            <ul class="text-red-700 text-sm space-y-1">
                @foreach($formErrors as $err)<li class="flex items-start gap-2"><span class="text-red-400">•</span>{{ $err }}</li>@endforeach
            </ul>
        </div>
        @endif

        <div class="flex-1 min-h-0 overflow-y-auto scrollbar-custom px-7 py-6 space-y-5">

            {{-- Photo --}}
            <div class="rounded-xl border border-slate-200 overflow-hidden">
                <div class="bg-slate-50 px-5 py-3 border-b border-slate-200 flex items-center gap-2">
                    <i class="fas fa-image text-purple-500 text-sm"></i>
                    <span class="text-sm font-bold text-slate-700">Event Photo</span>
                    <span class="text-xs text-slate-400 font-normal">(Optional)</span>
                </div>
                <div class="p-5">
                    <div x-data="{isDragging:false}" @dragover.prevent="isDragging=true" @dragleave.prevent="isDragging=false" @drop.prevent="isDragging=false"
                         class="photo-upload-area {{ ($photo || ($existingPhotoUrl && !$removePhoto)) ? 'has-preview' : '' }}"
                         :class="isDragging?'border-purple-400 bg-purple-50':''">
                        <label class="cursor-pointer block">
                            <input type="file" wire:model="photo" accept="image/*" class="hidden">
                            @if($photo)
                                <div class="flex flex-col items-center gap-3">
                                    <img src="{{ $photo->temporaryUrl() }}" class="w-40 h-32 object-cover rounded-lg shadow border border-purple-200">
                                    <p class="text-xs font-semibold text-purple-600"><i class="fas fa-check-circle mr-1"></i>New photo selected</p>
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
                                    <p class="text-xs text-purple-400 font-medium mt-1"><i class="fas fa-image mr-1"></i>Default photo used if blank</p>
                                </div>
                            @endif
                        </label>
                    </div>
                    @if($existingPhotoUrl && !$removePhoto && !$photo)
                    <div class="mt-3 flex items-center gap-2">
                        <button type="button" wire:click="$set('removePhoto',true)"
                                class="text-xs text-red-500 hover:text-red-700 font-semibold flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-red-200 hover:bg-red-50">
                            <i class="fas fa-trash text-[10px]"></i> Remove photo
                        </button>
                        <span class="text-xs text-slate-400">(uses default)</span>
                    </div>
                    @endif
                    @if($removePhoto)
                    <div class="mt-3 flex items-center gap-2">
                        <span class="text-xs text-amber-600 font-semibold"><i class="fas fa-exclamation-circle mr-1"></i>Photo will be removed on save</span>
                        <button type="button" wire:click="$set('removePhoto',false)" class="text-xs text-blue-500 underline">Undo</button>
                    </div>
                    @endif
                    <div wire:loading wire:target="photo" class="mt-3 text-xs text-purple-600 flex items-center gap-2">
                        <i class="fas fa-spinner spin-icon"></i> Uploading...
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
                        <input wire:model.defer="title" type="text" placeholder="e.g. PHILCST Alumni Homecoming 2026"
                               class="form-input {{ isset($formErrors['title']) ? 'field-error' : '' }}">
                        @if(isset($formErrors['title']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['title'] }}</p>@endif
                    </div>
                    <div>
                        <label class="form-label">Description</label>
                        <textarea wire:model.defer="description" rows="3" placeholder="Describe the event, agenda, highlights..."
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
                            <input wire:model="start_time" type="text" placeholder="e.g. 8:00 AM or 08:00"
                                   class="form-input {{ isset($formErrors['start_time']) ? 'field-error' : '' }}">
                            @if(isset($formErrors['start_time']))<p class="form-error"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['start_time'] }}</p>@endif
                        </div>
                        <div>
                            <label class="form-label">End Time <span class="text-slate-400 font-normal">(Optional)</span></label>
                            <input wire:model="end_time" type="text" placeholder="e.g. 5:00 PM or 17:00" class="form-input">
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

            {{-- Target Participants ── Admin version with college/year selector ── --}}
            <div class="rounded-xl border border-slate-200 overflow-hidden">
                <div class="bg-slate-50 px-5 py-3 border-b border-slate-200 flex items-center gap-2">
                    <i class="fas fa-users text-purple-500 text-sm"></i>
                    <span class="text-sm font-bold text-slate-700">Target Participants</span>
                </div>
                <div class="p-5 space-y-4">

                    {{-- Mode selector: 2 options only --}}
                    <div class="flex gap-3">
                        <button type="button" wire:click="$set('targetMode','all')" class="target-btn {{ $targetMode === 'all' ? 'active' : '' }}">
                            <i class="fas fa-globe text-base"></i><span>All Colleges</span>
                        </button>
                        <button type="button" wire:click="$set('targetMode','college')" class="target-btn {{ $targetMode === 'college' ? 'active' : '' }}">
                            <i class="fas fa-building-columns text-base"></i><span>Specific College(s)</span>
                        </button>
                    </div>

                    @if($targetMode === 'all')
                    <div class="flex items-center gap-3 bg-purple-50 border border-purple-200 rounded-lg px-4 py-3">
                        <i class="fas fa-globe text-purple-500 text-lg"></i>
                        <div>
                            <div class="text-sm font-bold text-purple-800">All Colleges</div>
                            <div class="text-xs text-purple-600 mt-0.5">This event will be visible to all alumni regardless of college.</div>
                        </div>
                    </div>

                    @elseif($targetMode === 'college')
                    <div>
                        @if(isset($formErrors['target']))<p class="form-error mb-2"><i class="fas fa-circle-exclamation text-xs"></i>{{ $formErrors['target'] }}</p>@endif
                        @if(count($this->colleges) > 0)
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-bold text-slate-500 uppercase tracking-wide">Select College(s)</span>
                            <button type="button"
                                    wire:click="$set('selectedColleges', {{ json_encode($this->colleges) }})"
                                    class="text-xs text-purple-600 font-bold hover:underline">
                                <i class="fas fa-check-double mr-1"></i>Select All
                            </button>
                            @if(count($selectedColleges) > 0)
                            <button type="button" wire:click="$set('selectedColleges', [])"
                                    class="text-xs text-slate-400 hover:text-red-500 font-bold hover:underline ml-3">
                                Clear
                            </button>
                            @endif
                        </div>
                        <div class="college-grid">
                            @foreach($this->colleges as $col)
                            <label class="college-check {{ in_array($col, $selectedColleges) ? 'checked' : '' }}">
                                <input type="checkbox" wire:model.live="selectedColleges" value="{{ $col }}">
                                <span>{{ $col }}</span>
                            </label>
                            @endforeach
                        </div>
                        @if(count($selectedColleges) > 0)
                        <div class="mt-3 flex flex-wrap gap-2">
                            @foreach($selectedColleges as $col)
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 bg-purple-50 border border-purple-200 text-purple-700 text-xs font-bold rounded-lg">
                                <i class="fas fa-building-columns text-[9px]"></i>{{ $col }}
                            </span>
                            @endforeach
                        </div>
                        @endif
                        @else
                        <div class="text-center py-4 text-slate-400 text-sm">
                            <i class="fas fa-triangle-exclamation text-amber-400 mr-2"></i>No colleges found. Add courses with college names first.
                        </div>
                        @endif
                    </div>

                    @endif

                    {{-- Batch Year — below college selection, always shown --}}
                    <div class="pt-3 border-t border-slate-100 mt-1">
                        <label class="form-label">Batch Year <span class="text-slate-400 font-normal text-xs">(Optional — leave blank for all batches)</span></label>
                        <input wire:model.defer="batchYear" type="number" min="1990" max="{{ now()->year + 5 }}"
                               placeholder="e.g. {{ now()->year - 2 }}"
                               class="form-input max-w-xs">
                        <p class="field-hint mt-1"><i class="fas fa-circle-info text-[10px] mr-1"></i>Enter a graduation year to target a specific batch only.</p>
                    </div>

                </div>
            </div>

            {{-- Contact --}}
            <div class="rounded-xl border border-slate-200 overflow-hidden">
                <div class="bg-slate-50 px-5 py-3 border-b border-slate-200 flex items-center gap-2">
                    <i class="fas fa-address-card text-purple-500 text-sm"></i>
                    <span class="text-sm font-bold text-slate-700">Contact Person</span>
                </div>
                <div class="p-5 grid grid-cols-3 gap-4">
                    <div>
                        <label class="form-label">Name</label>
                        <input wire:model.defer="contact_person" type="text" placeholder="Full name" class="form-input">
                    </div>
                    <div>
                        <label class="form-label">Email</label>
                        <input wire:model.defer="contact_email" type="email" placeholder="admin@philcst.edu.ph" class="form-input">
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
                <textarea wire:model.defer="notes" rows="3" placeholder="Dress code, special instructions..."
                          class="form-input resize-none"></textarea>
            </div>

        </div>

        <div class="px-7 py-5 border-t border-slate-200 bg-slate-50 rounded-b-xl shrink-0 flex gap-3">
            <button wire:click="closeFormModal" class="flex-1 px-6 py-3 border border-slate-300 text-slate-700 rounded-xl text-sm font-semibold hover:bg-slate-100 transition">Cancel</button>
            <button wire:click="saveEvent" wire:loading.attr="disabled" wire:target="saveEvent"
                    class="flex-1 px-6 py-3 btn-primary rounded-xl text-sm font-bold flex items-center justify-center gap-2">
                <span wire:loading wire:target="saveEvent"><i class="fas fa-spinner spin-icon"></i> Saving...</span>
                <span wire:loading.remove wire:target="saveEvent">
                    <i class="fas fa-{{ $isEditing ? 'floppy-disk' : 'circle-check' }} mr-1.5"></i>
                    {{ $isEditing ? 'Save Changes' : 'Create & Approve' }}
                </span>
            </button>
        </div>
    </div>
</div>
@endif

{{-- ══════════════════════════════════════════════════════
     MODAL: View Event
══════════════════════════════════════════════════════ --}}
@if($showViewModal && $this->viewingEvent)
@php
    $ev = $this->viewingEvent;
    $totalRsvp = $ev->confirmed_count + $ev->declined_count + $ev->tentative_count;
@endphp
<div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm backdrop-animate">
    <div class="ev-modal modal-animate relative">
        <button wire:click="closeViewModal" class="ev-close-x">&times;</button>

        <div class="ev-header">
            <img src="{{ $ev->photo_url }}" alt="{{ $ev->title }}" class="ev-cover">
            <div class="ev-header-body">
                <div class="flex items-start justify-between gap-4 mb-3">
                    <div class="ev-title">{{ $ev->title }}</div>
                    @if($ev->status==='PENDING')<span class="badge-pending shrink-0">⏳ Pending</span>
                    @elseif($ev->status==='APPROVED')<span class="badge-approved shrink-0">✓ Approved</span>
                    @else<span class="badge-rejected shrink-0">✗ Rejected</span>@endif
                </div>
                <ul class="ev-meta-list">
                    <li class="ev-meta-item"><span class="ev-meta-icon"><i class="fas fa-calendar"></i></span><span>{{ $ev->event_date->format('F d, Y') }}</span></li>
                    <li class="ev-meta-item"><span class="ev-meta-icon"><i class="fas fa-clock"></i></span>
                        <span>{{ $ev->event_date->format('g:i A') }}
                        @if($ev->event_end_date)<span style="color:#aaa;margin:0 4px;">–</span>{{ $ev->event_end_date->format('g:i A') }}
                        @else<span style="color:#aaa;font-style:italic;margin-left:4px;">· End time not set</span>@endif
                        </span>
                    </li>
                    <li class="ev-meta-item"><span class="ev-meta-icon"><i class="fas fa-location-dot"></i></span>
                        <span>{{ $ev->venue }}@if($ev->venue_address) &middot; <span style="color:#888">{{ $ev->venue_address }}</span>@endif</span>
                    </li>
                    @if($ev->target_participants)
                    <li class="ev-meta-item"><span class="ev-meta-icon"><i class="fas fa-users"></i></span><span>{{ $ev->target_participants }}</span></li>
                    @endif
                    <li class="ev-meta-item"><span class="ev-meta-icon"><i class="fas fa-{{ $ev->organizer ? 'user-tie' : 'shield-halved' }}"></i></span>
                        <span>{{ $ev->organizer ? $ev->organizer->name . ' · ' . $ev->organizer->department : 'Posted by Admin' }}</span>
                    </li>
                </ul>
            </div>
        </div>

        <div class="ev-body scrollbar-custom">

            {{-- RSVP --}}
            <div class="ev-section">
                <div class="ev-section-title">Attendee Responses
                    @if($totalRsvp > 0)<span style="font-size:12px;font-weight:400;color:#888;margin-left:6px;">{{ $totalRsvp }} total</span>@endif
                </div>
                @if($totalRsvp === 0)
                <div class="text-center py-5 text-slate-400 text-sm">
                    <i class="fas fa-inbox text-2xl block mb-2 text-slate-200"></i>No responses yet.
                </div>
                @else
                <div class="rsvp-grid">
                    <div class="rsvp-card rsvp-card-confirmed"><div class="rsvp-icon rsvp-confirmed-color"><i class="fas fa-circle-check"></i></div><div class="rsvp-count rsvp-confirmed-color">{{ $ev->confirmed_count }}</div><div class="rsvp-label rsvp-confirmed-color">Going</div></div>
                    <div class="rsvp-card rsvp-card-declined"><div class="rsvp-icon rsvp-declined-color"><i class="fas fa-circle-xmark"></i></div><div class="rsvp-count rsvp-declined-color">{{ $ev->declined_count }}</div><div class="rsvp-label rsvp-declined-color">Not Going</div></div>
                    <div class="rsvp-card rsvp-card-tentative"><div class="rsvp-icon rsvp-tentative-color"><i class="fas fa-circle-question"></i></div><div class="rsvp-count rsvp-tentative-color">{{ $ev->tentative_count }}</div><div class="rsvp-label rsvp-tentative-color">Maybe</div></div>
                </div>
                @endif
            </div>

            {{-- Review Status --}}
            <div class="ev-section">
                <div class="ev-section-title">Review Status</div>
                @if($ev->status==='PENDING')
                <div class="review-box review-box-pending">
                    <p class="text-sm font-bold text-yellow-800"><i class="fas fa-hourglass-half mr-2 text-yellow-500"></i>Pending Admin Review</p>
                    <p class="text-xs text-yellow-700 mt-1">This event is waiting for your approval.</p>
                </div>
                @elseif($ev->status==='APPROVED')
                <div class="review-box review-box-approved">
                    <p class="text-sm font-bold text-green-800"><i class="fas fa-circle-check mr-2 text-green-500"></i>Approved</p>
                    @if($ev->reviewed_at)<p class="text-xs text-green-700 mt-1">{{ $ev->reviewed_at->format('M d, Y · g:i A') }}</p>@endif
                    @if($ev->review_remarks)<p class="text-xs text-green-600 mt-1 italic">"{{ $ev->review_remarks }}"</p>@endif
                </div>
                @else
                <div class="review-box review-box-rejected">
                    <p class="text-sm font-bold text-red-800"><i class="fas fa-circle-xmark mr-2 text-red-500"></i>Rejected</p>
                    @if($ev->review_remarks)<p class="text-xs text-red-600 mt-2 font-semibold">Reason: <span class="font-normal">{{ $ev->review_remarks }}</span></p>@endif
                </div>
                @endif
            </div>

            @if($ev->description)
            <div class="ev-section"><div class="ev-section-title">About This Event</div><div class="ev-description">{{ $ev->description }}</div></div>
            @endif

            @if($ev->notes)
            <div class="ev-section"><div class="ev-section-title">Additional Notes</div><div class="ev-description">{{ $ev->notes }}</div></div>
            @endif

        </div>

        <div class="ev-footer">
            <button wire:click="closeViewModal" class="ev-btn ev-btn-close">Close</button>
            <button wire:click="confirmDelete({{ $ev->id }})" class="ev-btn ev-btn-delete"><i class="fas fa-trash" style="font-size:11px;"></i> Delete</button>
            @if($ev->status==='PENDING')
            <button wire:click="confirmReject({{ $ev->id }})" class="ev-btn ev-btn-reject"><i class="fas fa-xmark" style="font-size:11px;"></i> Reject</button>
            <button wire:click="confirmApprove({{ $ev->id }})" class="ev-btn ev-btn-approve"><i class="fas fa-check" style="font-size:11px;"></i> Approve</button>
            @elseif($ev->status==='APPROVED')
            <button wire:click="confirmReject({{ $ev->id }})" class="ev-btn ev-btn-reject"><i class="fas fa-ban" style="font-size:11px;"></i> Revoke Approval</button>
            @elseif($ev->status==='REJECTED')
            <button wire:click="confirmApprove({{ $ev->id }})" class="ev-btn ev-btn-approve"><i class="fas fa-rotate-left" style="font-size:11px;"></i> Re-Approve</button>
            @endif
            <button wire:click="openEditModal({{ $ev->id }})" class="ev-btn ev-btn-edit"><i class="fas fa-pen-to-square" style="font-size:11px;"></i> Edit</button>
        </div>
    </div>
</div>
@endif

{{-- ══════════════════════════════════════════════════════
     MODAL: Approve Confirmation
══════════════════════════════════════════════════════ --}}
@if($showApproveModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm backdrop-animate">
    <div class="relative bg-white rounded-2xl shadow-2xl modal-animate border-t-4 border-green-500" style="width:440px;max-width:95vw;">
        <div class="px-7 py-6 bg-green-50 border-b border-green-200 flex items-center gap-3">
            <div class="w-10 h-10 bg-green-100 rounded-xl flex items-center justify-center shrink-0"><i class="fas fa-circle-check text-green-600 text-lg"></i></div>
            <h2 class="text-lg font-extrabold text-green-800">Approve Event</h2>
        </div>
        <div class="p-7">
            <p class="text-slate-600 text-sm mb-1">You are about to approve:</p>
            <p class="font-extrabold text-green-700 text-base mb-4">"{{ $approveEventTitle }}"</p>
            <div class="mb-5">
                <label class="form-label">Remarks <span class="text-slate-400 font-normal">(Optional)</span></label>
                <textarea wire:model.defer="approveRemarks" rows="2"
                          placeholder="e.g. Approved. Great event proposal!"
                          class="form-input resize-none text-sm"></textarea>
            </div>
            <div class="flex gap-3">
                <button wire:click="cancelApprove" class="flex-1 px-5 py-2.5 border-2 border-slate-300 text-slate-700 rounded-xl text-sm font-bold hover:bg-slate-50 transition">
                    <i class="fas fa-xmark mr-1"></i> Cancel
                </button>
                <button wire:click="executeApprove" wire:loading.attr="disabled" wire:target="executeApprove"
                        class="flex-1 px-5 py-2.5 bg-green-600 text-white rounded-xl text-sm font-extrabold hover:bg-green-700 transition flex items-center justify-center gap-2 shadow-md">
                    <span wire:loading wire:target="executeApprove"><i class="fas fa-spinner spin-icon"></i></span>
                    <span wire:loading.remove wire:target="executeApprove"><i class="fas fa-circle-check mr-1"></i> Yes, Approve</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ══════════════════════════════════════════════════════
     MODAL: Reject Confirmation
══════════════════════════════════════════════════════ --}}
@if($showRejectModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm backdrop-animate">
    <div class="relative bg-white rounded-2xl shadow-2xl modal-animate border-t-4 border-red-500" style="width:440px;max-width:95vw;">
        <div class="px-7 py-6 bg-red-50 border-b border-red-200 flex items-center gap-3">
            <div class="w-10 h-10 bg-red-100 rounded-xl flex items-center justify-center shrink-0"><i class="fas fa-circle-xmark text-red-500 text-lg"></i></div>
            <h2 class="text-lg font-extrabold text-red-800">Reject Event</h2>
        </div>
        <div class="p-7">
            <p class="text-slate-600 text-sm mb-1">You are about to reject:</p>
            <p class="font-extrabold text-red-700 text-base mb-4">"{{ $rejectEventTitle }}"</p>
            <div class="mb-5">
                <label class="form-label">Reason for Rejection <span class="text-red-500">*</span></label>
                <textarea wire:model.defer="rejectRemarks" rows="3"
                          placeholder="e.g. Missing required details. Please provide complete venue information."
                          class="form-input resize-none text-sm"></textarea>
                <p class="field-hint"><i class="fas fa-circle-info text-[10px] mr-1"></i>Required — organizer will see this reason.</p>
            </div>
            <div class="flex gap-3">
                <button wire:click="cancelReject" class="flex-1 px-5 py-2.5 border-2 border-slate-300 text-slate-700 rounded-xl text-sm font-bold hover:bg-slate-50 transition">
                    <i class="fas fa-xmark mr-1"></i> Cancel
                </button>
                <button wire:click="executeReject" wire:loading.attr="disabled" wire:target="executeReject"
                        class="flex-1 px-5 py-2.5 bg-red-600 text-white rounded-xl text-sm font-extrabold hover:bg-red-700 transition flex items-center justify-center gap-2 shadow-md">
                    <span wire:loading wire:target="executeReject"><i class="fas fa-spinner spin-icon"></i></span>
                    <span wire:loading.remove wire:target="executeReject"><i class="fas fa-circle-xmark mr-1"></i> Yes, Reject</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif


{{-- Restore Modal --}}
@if($showRestoreModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm backdrop-animate">
    <div class="relative bg-white rounded-2xl shadow-2xl modal-animate border-t-4 border-blue-500" style="width:440px;max-width:95vw;">
        <div class="px-7 py-6 bg-blue-50 border-b border-blue-200 flex items-center gap-3">
            <div class="w-10 h-10 bg-blue-100 rounded-xl flex items-center justify-center shrink-0"><i class="fas fa-rotate-left text-blue-600 text-lg"></i></div>
            <h2 class="text-lg font-extrabold text-blue-800">Restore Event</h2>
        </div>
        <div class="p-7">
            <p class="text-slate-600 text-sm mb-1">You are about to restore:</p>
            <p class="font-extrabold text-blue-700 text-base mb-4">"{{ $restoreEventTitle }}"</p>
            <p class="text-slate-500 text-sm mb-5">The event will be restored and set back to <strong>PENDING</strong> for review.</p>
            <div class="flex gap-3">
                <button wire:click="cancelRestore" class="flex-1 px-5 py-2.5 border-2 border-slate-300 text-slate-700 rounded-xl text-sm font-bold hover:bg-slate-50 transition">Cancel</button>
                <button wire:click="executeRestore" wire:loading.attr="disabled" wire:target="executeRestore"
                        class="flex-1 px-5 py-2.5 bg-blue-600 text-white rounded-xl text-sm font-extrabold hover:bg-blue-700 transition flex items-center justify-center gap-2 shadow-md">
                    <span wire:loading wire:target="executeRestore"><i class="fas fa-spinner spin-icon"></i></span>
                    <span wire:loading.remove wire:target="executeRestore"><i class="fas fa-rotate-left mr-1"></i> Yes, Restore</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- Deleted Events Panel — inline, appears below the main table --}}
@if($showDeletedTab)
<div class="px-8 pb-6 shrink-0" wire:key="deleted-panel">
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden" style="border-top:4px solid #334155;">
        {{-- Header --}}
        <div class="px-6 py-4 bg-slate-700 flex items-center gap-3">
            <i class="fas fa-trash-can text-slate-300 text-sm"></i>
            <span class="text-sm font-bold text-white">Deleted Events</span>
            <span class="text-xs text-slate-400 ml-1">— admin can restore these</span>
            <button wire:click="$set('showDeletedTab',false)"
                    class="ml-auto w-7 h-7 rounded-full bg-slate-600 hover:bg-slate-500 flex items-center justify-center text-slate-300 hover:text-white transition text-sm">
                <i class="fas fa-xmark"></i>
            </button>
        </div>
        {{-- Table --}}
        <div class="overflow-x-auto scrollbar-custom" style="max-height:320px;overflow-y:auto;">
            <table class="w-full border-separate border-spacing-0">
                <thead style="position:sticky;top:0;z-index:5;background:#475569;">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-white uppercase tracking-wide">Event</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-white uppercase tracking-wide">Date</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-white uppercase tracking-wide">Organizer</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-white uppercase tracking-wide">Deleted By</th>
                        <th class="px-5 py-3 text-left text-xs font-semibold text-white uppercase tracking-wide">Deleted At</th>
                        <th class="px-5 py-3 text-center text-xs font-semibold text-white uppercase tracking-wide">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($this->deletedEvents as $ev)
                    <tr class="hover:bg-slate-50 transition-colors">
                        <td class="px-5 py-3">
                            <p class="font-semibold text-slate-800 text-sm">{{ $ev->title }}</p>
                            <p class="text-xs text-slate-400 mt-0.5">{{ $ev->venue }}</p>
                        </td>
                        <td class="px-5 py-3">
                            <p class="text-sm font-medium text-slate-700">{{ $ev->event_date->format('M d, Y') }}</p>
                            <p class="text-xs text-slate-400">{{ $ev->event_date->format('g:i A') }}</p>
                        </td>
                        <td class="px-5 py-3">
                            @if($ev->organizer)
                                <p class="text-sm font-semibold text-slate-700">{{ $ev->organizer->name }}</p>
                                <p class="text-xs text-slate-400">{{ $ev->organizer->department }}</p>
                            @else
                                <span class="inline-flex items-center gap-1 text-xs font-bold text-purple-600 bg-purple-50 px-2 py-1 rounded">
                                    <i class="fas fa-shield-halved text-[9px]"></i> Admin
                                </span>
                            @endif
                        </td>
                        <td class="px-5 py-3">
                            @if($ev->deleted_by)
                                <p class="text-sm font-semibold text-slate-700">{{ $ev->deleted_by }}</p>
                                <p class="text-xs text-slate-400">{{ ucfirst($ev->deleted_by_role ?? '') }}</p>
                            @else
                                <span class="text-sm text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="px-5 py-3">
                            <p class="text-sm text-slate-700">{{ $ev->deleted_at->format('M d, Y') }}</p>
                            <p class="text-xs text-slate-400">{{ $ev->deleted_at->diffForHumans() }}</p>
                        </td>
                        <td class="px-5 py-3 text-center">
                            <button wire:click="confirmRestore({{ $ev->id }})"
                                    class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-bold text-blue-600 hover:bg-blue-50 rounded-lg transition border border-blue-200">
                                <i class="fas fa-rotate-left text-[10px]"></i> Restore
                            </button>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="py-10 text-center">
                            <i class="fas fa-inbox text-3xl text-slate-200 block mb-2"></i>
                            <p class="text-slate-400 text-sm font-medium">No deleted events.</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif

{{-- ══════════════════════════════════════════════════════
     MODAL: Delete Confirmation
══════════════════════════════════════════════════════ --}}
@if($showDeleteModal)
<div class="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm backdrop-animate">
    <div class="relative bg-white rounded-2xl shadow-2xl modal-animate border-t-4 border-slate-400" style="width:420px;max-width:95vw;">
        <div class="px-7 py-6 bg-slate-50 border-b border-slate-200 flex items-center gap-3">
            <div class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center shrink-0"><i class="fas fa-triangle-exclamation text-slate-500 text-lg"></i></div>
            <h2 class="text-lg font-extrabold text-slate-800">Delete Event</h2>
        </div>
        <div class="p-7">
            <p class="text-slate-600 text-sm mb-1">You are about to delete:</p>
            <p class="font-extrabold text-slate-800 text-base mb-4">"{{ $deleteEventTitle }}"</p>
            <p class="text-slate-500 text-sm mb-5">This event will be permanently removed along with its photo.</p>
            <div class="flex gap-3">
                <button wire:click="cancelDelete" class="flex-1 px-5 py-2.5 border-2 border-slate-300 text-slate-700 rounded-xl text-sm font-bold hover:bg-slate-50 transition">
                    <i class="fas fa-xmark mr-1"></i> Cancel
                </button>
                <button wire:click="executeDelete" wire:loading.attr="disabled" wire:target="executeDelete"
                        class="flex-1 px-5 py-2.5 bg-slate-700 text-white rounded-xl text-sm font-extrabold hover:bg-slate-800 transition flex items-center justify-center gap-2 shadow-md">
                    <span wire:loading wire:target="executeDelete"><i class="fas fa-spinner spin-icon"></i></span>
                    <span wire:loading.remove wire:target="executeDelete"><i class="fas fa-trash mr-1"></i> Yes, Delete</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

</div>