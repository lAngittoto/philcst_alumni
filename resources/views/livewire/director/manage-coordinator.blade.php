<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use App\Models\Organizer;
use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

new class extends Component {
    use WithPagination, WithFileUploads;

    protected function queryString(): array { return []; }

    // ── modal ─────────────────────────────────────────────────────
    public string $activeModal = '';

    // ── coordinator filters ───────────────────────────────────────
    public string $coordSearch  = '';
    public string $coordCollege = '';
    public string $coordSort    = 'recent';

    // ── register coordinator ──────────────────────────────────────
    public string $coordFirstName         = '';
    public string $coordMiddleInitial     = '';
    public string $coordLastName          = '';
    public string $coordSuffix            = '';
    public string $coordTeacherId         = '';
    public string $coordEmail             = '';
    public string $coordDept              = '';
    public string $coordCollegeSelect     = '';
    public        $coordPhoto             = null;
    public bool   $registeringCoordinator = false;
    public array  $coordinatorErrors      = [];
    public string $coordinatorSuccess     = '';

    // ── manage colleges ───────────────────────────────────────────
    public array   $orgCoursesList         = [];
    public string  $orgNewCollegeName      = '';
    public ?string $orgAddingToCollege     = null;
    public array   $orgSelectedCourseCodes = [];
    public bool    $savingOrgCourse        = false;
    public string  $orgCourseAlert         = '';
    public string  $orgCourseAlertType     = '';
    public ?string $deleteOrgCollegeName   = null;
    public string  $deleteOrgCourseName    = '';
    public bool    $deletingOrgCourse      = false;
    public ?string $orgRenamingCollege     = null;
    public string  $orgRenameCollegeName   = '';

    // ── coordinator toggle ────────────────────────────────────────
    public ?int   $pendingToggleId     = null;
    public string $pendingToggleAction = '';
    public string $pendingToggleName   = '';

    // ── coordinator delete ────────────────────────────────────────
    public ?int   $pendingDeleteId   = null;
    public string $pendingDeleteName = '';
    public bool   $deletingCoord     = false;

    // ── view / edit profile ───────────────────────────────────────
    public ?int   $viewingProfileId     = null;
    public        $viewingProfile       = null;
    public        $updatingProfilePhoto = null;
    public bool   $updatingProfile      = false;

    protected string $paginationTheme = 'tailwind';

    // ─────────────────────────────────────────────────────────────
    //  BOOT / MOUNT
    // ─────────────────────────────────────────────────────────────
    public function mount(): void
    {
        $this->loadOrgCourses();

        if (session()->has('success'))
            $this->dispatch('showFlash', type: 'success', message: session()->pull('success'));
        if (session()->has('error'))
            $this->dispatch('showFlash', type: 'error',   message: session()->pull('error'));
    }

    // ─────────────────────────────────────────────────────────────
    //  EVENT HANDLERS
    // ─────────────────────────────────────────────────────────────
    #[On('showFlash')]
    public function handleShowFlash(string $type, string $message): void
    {
        $this->flash($type, $message);
    }

    private function flash(string $type, string $msg): void
    {
        $this->dispatch('flash-message', type: $type, message: $msg);
    }

    // ─────────────────────────────────────────────────────────────
    //  FILTER RESETTERS
    // ─────────────────────────────────────────────────────────────
    public function updatingCoordSearch()  { $this->resetPage('coordPage'); }
    public function updatingCoordCollege() { $this->resetPage('coordPage'); }
    public function updatingCoordSort()    { $this->resetPage('coordPage'); }

    // ─────────────────────────────────────────────────────────────
    //  COMPUTED — COORDINATOR RECORDS
    // ─────────────────────────────────────────────────────────────
    #[Computed]
    public function coordinatorRecords()
    {
        $q = Organizer::withoutTrashed();

        if ($this->coordSearch) {
            $term = '%' . $this->coordSearch . '%';
            $q->where(fn($s) => $s
                ->where('first_name', 'like', $term)
                ->orWhere('last_name',  'like', $term)
                ->orWhere('email',      'like', $term)
                ->orWhere('id_number',  'like', $term));
        }

        if ($this->coordCollege) $q->where('department', $this->coordCollege);

        $q->when(
            $this->coordSort === 'oldest',
            fn($q) => $q->orderBy('created_at'),
            fn($q) => $q->orderByDesc('created_at')
        );

        return $q->paginate(10, ['*'], 'coordPage');
    }

    // ─────────────────────────────────────────────────────────────
    //  COMPUTED — MISC LOOKUPS
    // ─────────────────────────────────────────────────────────────
    #[Computed] public function orgColleges()
    {
        return Course::whereNotNull('college')->where('college','!=','')->distinct()->orderBy('college')->pluck('college');
    }

    #[Computed]
    public function orgDepartmentsGrouped()
    {
        return Course::whereNotNull('college')
            ->where('college','!=','')
            ->orderBy('college')->orderBy('code')
            ->get()->groupBy('college');
    }

    #[Computed]
    public function allCoursesForAssign() { return Course::orderBy('code')->get(); }

    #[Computed]
    public function occupiedColleges(): array
    {
        $result = [];
        Organizer::withoutTrashed()
            ->where('status', 'ACTIVE')
            ->select('department','first_name','middle_initial','last_name','suffix')
            ->get()
            ->each(function ($org) use (&$result) {
                $collegeName = Course::where('college', $org->department)->exists()
                    ? $org->department
                    : (Course::where('code', $org->department)->value('college') ?? $org->department);
                if ($collegeName && !isset($result[$collegeName])) {
                    $result[$collegeName] = $org->getFullName();
                }
            });
        return $result;
    }

    // ─────────────────────────────────────────────────────────────
    //  UTILITY METHODS
    // ─────────────────────────────────────────────────────────────
    public function getCollegeForCourse(string $code): string
    {
        return Course::where('college', $code)->exists()
            ? $code
            : (Course::where('code', $code)->value('college') ?? $code);
    }

    public function getPhotoUrl(?string $path): string
    {
        if (!$path || str_contains($path, 'default.png'))
            return asset('storage/alumni-photos/default.png');
        if (str_starts_with($path, 'alumni-photos/') || str_starts_with($path, 'organizers/'))
            return Storage::disk('public')->exists($path)
                ? asset('storage/' . $path)
                : asset('storage/alumni-photos/default.png');
        return asset('storage/alumni-photos/default.png');
    }

    public function formatDisplayName(string $f, string $m, string $l, string $s): string
    {
        $parts = [trim($f)];
        if (trim($m) !== '') $parts[] = strtoupper(substr(trim($m), 0, 1)) . '.';
        $parts[] = trim($l);
        if (trim($s) !== '') $parts[] = trim($s);
        return implode(' ', array_filter($parts));
    }

    private function validateName(string $n): bool
    {
        return (bool) preg_match('/^[a-zA-Z\s\-\.\']+$/', $n);
    }

    private function buildFullName(string $f, string $m, string $l, string $s): string
    {
        return implode(' ', array_filter(array_map('trim', [$f, $m, $l, $s])));
    }

    private function coordinatorFullNameExists(string $f, string $m, string $l, string $s, ?int $exceptId = null): bool
    {
        $q = Organizer::withoutTrashed()
                      ->whereRaw('LOWER(TRIM(first_name))=?', [strtolower(trim($f))])
                      ->whereRaw('LOWER(TRIM(last_name))=?', [strtolower(trim($l))])
                      ->whereRaw('LOWER(TRIM(COALESCE(middle_initial,"")))=?', [strtolower(trim($m))])
                      ->whereRaw('LOWER(TRIM(COALESCE(suffix,"")))=?', [strtolower(trim($s))]);
        if ($exceptId) $q->where('id','!=',$exceptId);
        return $q->exists();
    }

    // ─────────────────────────────────────────────────────────────
    //  MODAL CONTROL
    // ─────────────────────────────────────────────────────────────
    public function openModal(string $modal): void
    {
        if ($modal === 'manageOrgCourses')     { $this->loadOrgCourses(); $this->resetOrgCourseForm(); }
        if ($modal === 'registerCoordinator')  { $this->coordinatorSuccess = ''; $this->coordinatorErrors = []; }
        $this->activeModal = $modal;
    }

    public function closeModal(): void
    {
        $this->activeModal          = '';
        $this->pendingToggleId      = null;
        $this->pendingToggleAction  = '';
        $this->pendingToggleName    = '';
        $this->pendingDeleteId      = null;
        $this->pendingDeleteName    = '';
        $this->deletingCoord        = false;
        $this->viewingProfileId     = null;
        $this->updatingProfilePhoto = null;
        $this->coordinatorSuccess   = '';
    }

    public function resetCoordFilters(): void
    {
        $this->coordSearch = $this->coordCollege = '';
        $this->coordSort   = 'recent';
        $this->resetPage('coordPage');
    }

    // ─────────────────────────────────────────────────────────────
    //  REGISTER COORDINATOR
    // ─────────────────────────────────────────────────────────────
    public function registerCoordinator(): void
    {
        $this->coordinatorErrors      = [];
        $this->coordinatorSuccess     = '';
        $this->registeringCoordinator = true;

        try {
            if (!$this->validateName(trim($this->coordFirstName)))
                throw new \Exception('First name may only contain letters, spaces, hyphens, or apostrophes.');
            if (!$this->validateName(trim($this->coordLastName)))
                throw new \Exception('Last name may only contain letters, spaces, hyphens, or apostrophes.');

            $mid = trim($this->coordMiddleInitial);
            if ($mid !== '') {
                if (!preg_match('/^[a-zA-Z]+$/', $mid))
                    throw new \Exception('Middle name must contain letters only.');
                if (strlen($mid) < 2)
                    throw new \Exception('Middle name must be a full word (e.g. Santos, not S).');
            }

            if (trim($this->coordSuffix) !== '' && !preg_match('/^[a-zA-Z\.\s]+$/', trim($this->coordSuffix)))
                throw new \Exception('Suffix may only contain letters and periods.');

            if ($this->coordinatorFullNameExists(
                trim($this->coordFirstName), trim($this->coordMiddleInitial),
                trim($this->coordLastName),  trim($this->coordSuffix)
            )) throw new \Exception("A coordinator with that full name already exists.");

            $fullName = $this->buildFullName(
                $this->coordFirstName, $this->coordMiddleInitial,
                $this->coordLastName,  $this->coordSuffix
            );

            $college = trim($this->coordCollegeSelect);
            if (!$college) throw new \Exception('Please select a college.');

            $occupied = $this->occupiedColleges();
            if (isset($occupied[$college]))
                throw new \Exception("College \"{$college}\" already has an active coordinator ({$occupied[$college]}). Deactivate them first.");

            $this->coordDept = $college;

            $this->validate([
                'coordFirstName'     => ['required','string','max:100'],
                'coordLastName'      => ['required','string','max:100'],
                'coordMiddleInitial' => ['nullable','string','min:2','max:50','regex:/^[a-zA-Z]+$/'],
                'coordSuffix'        => ['nullable','string','max:10'],
                'coordTeacherId'     => ['required','string','regex:/^\d{1,8}$/','unique:organizer,id_number'],
                'coordEmail'         => ['required','email','max:255','unique:organizer,email','unique:users,email'],
                'coordCollegeSelect' => ['required','string'],
                'coordPhoto'         => ['nullable','image','mimes:jpeg,png,jpg,webp','max:5120'],
            ], [
                'coordTeacherId.unique'       => 'This Teacher ID is already registered.',
                'coordTeacherId.regex'        => 'Teacher ID must be 1–8 digits.',
                'coordEmail.unique'           => 'This email address is already taken.',
                'coordCollegeSelect.required' => 'Please select a college.',
                'coordPhoto.max'              => 'Profile photo must not exceed 5 MB.',
            ]);

            $paddedId  = str_pad($this->coordTeacherId, 8, '0', STR_PAD_LEFT);
            $photoPath = $this->coordPhoto ? $this->storeCoordinatorPhoto($this->coordPhoto) : null;
            $tmp       = Str::random(12);

            $user = User::create([
                'name'     => $fullName,
                'email'    => $this->coordEmail,
                'role'     => 'organizer',
                'password' => Hash::make($tmp),
            ]);

            $coordinator = Organizer::create([
                'user_id'        => $user->id,
                'first_name'     => trim($this->coordFirstName),
                'middle_initial' => trim($this->coordMiddleInitial) ?: null,
                'last_name'      => trim($this->coordLastName),
                'suffix'         => trim($this->coordSuffix)        ?: null,
                'email'          => $this->coordEmail,
                'id_number'      => $paddedId,
                'department'     => $college,
                'profile_photo'  => $photoPath,
                'status'         => 'ACTIVE',
            ]);

            try {
                Mail::to($coordinator->email)->send(new \App\Mail\OrganizerRegistered($coordinator, $tmp));
            } catch (\Exception $e) {
                Log::warning('Coordinator email failed: ' . $e->getMessage());
            }

            $this->coordinatorSuccess = "Coordinator '{$fullName}' registered! Login credentials sent to {$coordinator->email}.";
            $this->resetCoordForm();

        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->coordinatorErrors = $e->errors();
        } catch (\Exception $e) {
            Log::error('Coordinator register: ' . $e->getMessage());
            $this->coordinatorErrors = ['general' => [$e->getMessage()]];
        } finally {
            $this->registeringCoordinator = false;
        }
    }

    private function storeCoordinatorPhoto($p): ?string
    {
        if (!$p) return null;
        try {
            $f = 'organizer-' . Str::uuid() . '.' . $p->getClientOriginalExtension();
            $r = $p->storeAs('organizers', $f, 'public');
            return $r === false ? null : "organizers/{$f}";
        } catch (\Exception $e) {
            Log::error('CoordPhoto: ' . $e->getMessage());
            return null;
        }
    }

    private function resetCoordForm(): void
    {
        $this->coordFirstName = $this->coordMiddleInitial = $this->coordLastName = $this->coordSuffix = '';
        $this->coordTeacherId = $this->coordEmail = $this->coordDept = $this->coordCollegeSelect = '';
        $this->coordPhoto        = null;
        $this->coordinatorErrors = [];
    }

    // ─────────────────────────────────────────────────────────────
    //  MANAGE COLLEGES
    // ─────────────────────────────────────────────────────────────
    private function loadOrgCourses(): void
    {
        $grouped = [];
        foreach (Course::orderByDesc('updated_at')->orderBy('code')->get() as $c) {
            if ($c->college) $grouped[$c->college][] = $c->toArray();
        }
        $this->orgCoursesList = $grouped;
    }

    public function resetOrgCourseForm(): void
    {
        $this->orgNewCollegeName      = '';
        $this->orgAddingToCollege     = null;
        $this->orgSelectedCourseCodes = [];
        $this->savingOrgCourse        = false;
        $this->orgCourseAlert         = '';
        $this->orgCourseAlertType     = '';
        $this->deleteOrgCollegeName   = null;
        $this->deleteOrgCourseName    = '';
        $this->orgRenamingCollege     = null;
        $this->orgRenameCollegeName   = '';
    }

    public function addCollege(): void
    {
        $name = trim($this->orgNewCollegeName);
        if (!$name)                              { $this->orgCourseAlert = 'College name is required.';           $this->orgCourseAlertType = 'error'; return; }
        if (isset($this->orgCoursesList[$name])) { $this->orgCourseAlert = "College '{$name}' already exists.";  $this->orgCourseAlertType = 'error'; return; }
        $this->orgAddingToCollege     = $name;
        $this->orgSelectedCourseCodes = [];
        $this->orgNewCollegeName      = '';
        $this->orgCourseAlert         = '';
    }

    public function startEditingCollege(string $college): void
    {
        $this->orgAddingToCollege     = $college;
        $this->orgSelectedCourseCodes = Course::where('college', $college)->pluck('code')->toArray();
        $this->orgCourseAlert         = '';
    }

    public function cancelAddingCourses(): void
    {
        $this->orgAddingToCollege     = null;
        $this->orgSelectedCourseCodes = [];
        $this->orgNewCollegeName      = '';
        $this->orgCourseAlert         = '';
    }

    public function startRenamingCollege(string $college): void
    {
        $this->orgRenamingCollege   = $college;
        $this->orgRenameCollegeName = $college;
        $this->orgCourseAlert       = '';
    }

    public function cancelRenamingCollege(): void
    {
        $this->orgRenamingCollege   = null;
        $this->orgRenameCollegeName = '';
    }

    public function renameCollege(): void
    {
        $old = trim($this->orgRenamingCollege ?? '');
        $new = trim($this->orgRenameCollegeName);
        if (!$new)                              { $this->orgCourseAlert = 'New name is required.';               $this->orgCourseAlertType = 'error'; return; }
        if ($new === $old)                      { $this->cancelRenamingCollege(); return; }
        if (isset($this->orgCoursesList[$new])) { $this->orgCourseAlert = "College \"{$new}\" already exists."; $this->orgCourseAlertType = 'error'; return; }

        try {
            Course::where('college', $old)->update(['college' => $new]);
            Organizer::where('department', $old)->update(['department' => $new]);
            $this->cancelRenamingCollege();
            $this->loadOrgCourses();
            $this->orgCourseAlert     = "College renamed to \"{$new}\"!";
            $this->orgCourseAlertType = 'success';
        } catch (\Exception $e) {
            $this->orgCourseAlert     = 'Failed: ' . $e->getMessage();
            $this->orgCourseAlertType = 'error';
        }
    }

    public function saveCollegeCourses(): void
    {
        $this->savingOrgCourse = true;
        $college = trim($this->orgAddingToCollege ?? '');
        if (!$college)                            { $this->orgCourseAlert = 'College name missing.';        $this->orgCourseAlertType = 'error'; $this->savingOrgCourse = false; return; }
        if (empty($this->orgSelectedCourseCodes)) { $this->orgCourseAlert = 'Select at least one course.'; $this->orgCourseAlertType = 'error'; $this->savingOrgCourse = false; return; }

        try {
            Course::where('college', $college)->whereNotIn('code', $this->orgSelectedCourseCodes)->update(['college' => null]);
            Course::whereIn('code', $this->orgSelectedCourseCodes)->update(['college' => $college]);
            $count                        = count($this->orgSelectedCourseCodes);
            $this->orgAddingToCollege     = null;
            $this->orgSelectedCourseCodes = [];
            $this->loadOrgCourses();
            $this->orgCourseAlert     = "College '{$college}' saved with {$count} dept(s)!";
            $this->orgCourseAlertType = 'success';
        } catch (\Exception $e) {
            $this->orgCourseAlert     = 'Failed: ' . $e->getMessage();
            $this->orgCourseAlertType = 'error';
        } finally {
            $this->savingOrgCourse = false;
        }
    }

    public function confirmDeleteCollege(string $college): void
    {
        $this->deleteOrgCollegeName = $college;
        $this->deleteOrgCourseName  = $college;
        $this->activeModal          = 'deleteOrgCollegeConfirm';
    }

    public function deleteOrgCollege(): void
    {
        $this->deletingOrgCourse = true;
        try {
            Course::where('college', $this->deleteOrgCollegeName)->update(['college' => null]);
            $deleted = $this->deleteOrgCollegeName;
            $this->deleteOrgCollegeName = null;
            $this->deleteOrgCourseName  = '';
            $this->loadOrgCourses();
            $this->orgCourseAlert     = "College '{$deleted}' removed!";
            $this->orgCourseAlertType = 'success';
            $this->activeModal        = 'manageOrgCourses';
        } catch (\Exception) {
            $this->orgCourseAlert     = 'Failed to delete college.';
            $this->orgCourseAlertType = 'error';
            $this->activeModal        = 'manageOrgCourses';
        } finally {
            $this->deletingOrgCourse = false;
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  COORDINATOR STATUS TOGGLE
    // ─────────────────────────────────────────────────────────────
    public function confirmToggleCoordinatorStatus(int $id, string $action): void
    {
        try {
            $coordinator = Organizer::findOrFail($id);
            $this->pendingToggleId     = $id;
            $this->pendingToggleAction = $action;
            $this->pendingToggleName   = $coordinator->getFullName();
            $this->activeModal         = 'toggleCoordinatorConfirm';
        } catch (\Exception) {
            $this->flash('error', 'Could not find coordinator.');
        }
    }

    public function executeToggleCoordinatorStatus(): void
    {
        if (!$this->pendingToggleId) return;

        try {
            $coordinator = Organizer::findOrFail($this->pendingToggleId);
            $newStatus   = $this->pendingToggleAction === 'deactivate' ? 'INACTIVE' : 'ACTIVE';

            if ($newStatus === 'ACTIVE') {
                $collegeName = Course::where('college', $coordinator->department)->exists()
                    ? $coordinator->department
                    : (Course::where('code', $coordinator->department)->value('college') ?? $coordinator->department);

                $conflict = Organizer::withoutTrashed()
                    ->where('status', 'ACTIVE')
                    ->where('department', $coordinator->department)
                    ->where('id', '!=', $coordinator->id)
                    ->first();

                if ($conflict) {
                    $this->flash('error',
                        "Cannot activate: college \"{$collegeName}\" already has an active coordinator ({$conflict->getFullName()}). Deactivate them first."
                    );
                    $this->activeModal         = '';
                    $this->pendingToggleId     = null;
                    $this->pendingToggleAction = '';
                    $this->pendingToggleName   = '';
                    return;
                }
            }

            $coordinator->update(['status' => $newStatus]);
            $verb = $newStatus === 'ACTIVE' ? 'activated' : 'deactivated';
            $this->flash('success', "{$coordinator->getFullName()} has been {$verb}.");

        } catch (\Exception $e) {
            $this->flash('error', 'Could not update status: ' . $e->getMessage());
        } finally {
            $this->pendingToggleId     = null;
            $this->pendingToggleAction = '';
            $this->pendingToggleName   = '';
            $this->activeModal         = '';
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  COORDINATOR DELETE
    // ─────────────────────────────────────────────────────────────
    public function confirmDeleteCoordinator(int $id): void
    {
        try {
            $coordinator           = Organizer::findOrFail($id);
            $this->pendingDeleteId   = $id;
            $this->pendingDeleteName = $coordinator->getFullName();
            $this->activeModal       = 'deleteCoordinatorConfirm';
        } catch (\Exception) {
            $this->flash('error', 'Could not find coordinator.');
        }
    }

    public function deleteCoordinator(): void
    {
        if (!$this->pendingDeleteId) return;
        $this->deletingCoord = true;

        try {
            $coordinator = Organizer::findOrFail($this->pendingDeleteId);
            $name        = $coordinator->getFullName();

            if ($coordinator->user) {
                $coordinator->user->delete(); // cascades via FK to organizer
            } else {
                $coordinator->forceDelete();
            }

            $this->flash('success', "Coordinator \"{$name}\" has been permanently deleted.");

        } catch (\Exception $e) {
            Log::error('Coordinator delete: ' . $e->getMessage());
            $this->flash('error', 'Could not delete coordinator: ' . $e->getMessage());
        } finally {
            $this->pendingDeleteId   = null;
            $this->pendingDeleteName = '';
            $this->deletingCoord     = false;
            $this->activeModal       = '';
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  VIEW PROFILE
    // ─────────────────────────────────────────────────────────────
    public function viewProfile(int $id): void
    {
        try {
            $this->viewingProfile   = Organizer::findOrFail($id)->toArray();
            $this->viewingProfileId = $id;
            $this->activeModal      = 'viewProfile';
        } catch (\Exception) {
            $this->flash('error', 'Failed to load profile.');
        }
    }

    public function updateProfilePhoto(): void
    {
        if (!$this->updatingProfilePhoto || !$this->viewingProfileId) return;
        $this->updatingProfile = true;

        try {
            $coord = Organizer::findOrFail($this->viewingProfileId);
            if ($coord->profile_photo && !str_contains($coord->profile_photo, 'default.png'))
                Storage::disk('public')->delete($coord->profile_photo);
            $p = $this->storeCoordinatorPhoto($this->updatingProfilePhoto);
            $coord->update(['profile_photo' => $p]);
            $this->viewingProfile['profile_photo'] = $p;
            $this->updatingProfilePhoto = null;
            $this->flash('success', 'Photo updated!');
        } catch (\Exception) {
            $this->flash('error', 'Failed to update photo.');
        } finally {
            $this->updatingProfile = false;
        }
    }
};
?>

<div>

{{-- ══════════════════════ FLASH TOAST ══════════════════════ --}}
<div x-data="{
        show:false, type:'success', msg:'', timer:null,
        display(t,m){ this.type=t; this.msg=m; this.show=true; clearTimeout(this.timer); this.timer=setTimeout(()=>this.show=false,8000); }
     }"
     @flash-message.window="display($event.detail.type,$event.detail.message)"
     x-show="show"
     x-transition:enter="transition ease-out duration-250"
     x-transition:enter-start="opacity-0 translate-x-6 scale-95"
     x-transition:enter-end="opacity-100 translate-x-0 scale-100"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0 translate-x-6"
     class="fixed top-4 right-4 sm:right-5 z-[200] flex items-start gap-3 px-4 py-3.5 rounded-xl shadow-xl max-w-[90vw] sm:max-w-sm border"
     :class="{ 'bg-white border-emerald-200': type==='success', 'bg-white border-blue-200': type==='info', 'bg-white border-red-200': type==='error' }"
     style="display:none">
    <i class="fas mt-0.5 text-sm flex-shrink-0"
       :class="{ 'fa-circle-check text-emerald-500': type==='success', 'fa-circle-info text-blue-500': type==='info', 'fa-circle-exclamation text-red-500': type==='error' }"></i>
    <div class="flex-1 min-w-0">
        <p class="font-bold text-sm text-gray-900" x-text="type==='success'?'Success':type==='info'?'Info':'Error'"></p>
        <p class="text-sm mt-0.5 text-gray-600 leading-snug break-words" x-text="msg"></p>
    </div>
    <button @click="show=false" class="text-gray-400 hover:text-gray-600 transition shrink-0"><i class="fas fa-xmark text-sm"></i></button>
</div>

{{-- ══════════════════════ MAIN LAYOUT ══════════════════════ --}}
<div class="flex flex-col px-3 sm:px-5 lg:px-7 pt-5 max-w-screen-2xl mx-auto" style="background:min-height:90vh;">

    {{-- HEADER --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5 animate-in fade-in slide-in-from-top-2 duration-300">
        <div class="flex items-center gap-3">
            <div class="w-12 h-12 sm:w-13 sm:h-13 rounded-xl flex items-center justify-center shadow-md shrink-0 bg-[#7a3f91]">
                <i class="fas fa-users-gear text-white text-lg"></i>
            </div>
            <div>
                <h1 class="text-2xl sm:text-3xl font-extrabold text-gray-900 leading-tight">Manage Coordinator</h1>
                <p class="text-gray-600 text-sm mt-0.5">Manage coordinator records and college assignments</p>
            </div>
        </div>
        <div class="flex flex-wrap gap-2 shrink-0">
            <button wire:click="openModal('registerCoordinator')"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg font-bold text-sm bg-[#7a3f91] hover:bg-[#5e2f72] text-white transition shadow-md">
                <i class="fas fa-users-gear text-sm"></i><span>Register Coordinator</span>
            </button>
            <button wire:click="openModal('manageOrgCourses')"
                    class="inline-flex items-center gap-2 px-3.5 py-2.5 rounded-lg font-bold text-sm bg-white border border-gray-300 text-gray-800 hover:bg-gray-50 transition">
                <i class="fas fa-building-columns text-sm"></i><span class="hidden sm:inline">Colleges</span>
            </button>
        </div>
    </div>

    {{-- TABLE CARD --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 flex flex-col overflow-hidden" style="min-height:0;height:calc(100vh - 175px);">

        {{-- Filters --}}
        <div class="px-3 sm:px-5 py-3 border-b border-gray-200 bg-white flex flex-wrap gap-2 items-center">
            <div class="relative flex-1 min-w-[160px] max-w-xs" wire:ignore
                 x-data="{ q:'', timer:null, init(){ this.q=$wire.coordSearch??''; $wire.$watch('coordSearch',val=>{ if(val!==this.q) this.q=val; }); } }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none"></i>
                <input type="text" x-model="q" @input.debounce.80ms="$wire.set('coordSearch',q)"
                       placeholder="Search name, ID, email..."
                       class="w-full pl-9 pr-3 py-2.5 border border-gray-200 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                       autocomplete="off" spellcheck="false">
            </div>
            <select wire:model.live="coordCollege" class="px-3 py-2.5 border border-gray-200 rounded-lg text-sm bg-white text-gray-900 min-w-[130px] focus:outline-none focus:border-[#7a3f91]">
                <option value="">All Colleges</option>
                @foreach($this->orgColleges as $col)<option value="{{ $col }}">{{ $col }}</option>@endforeach
            </select>
            <select wire:model.live="coordSort" class="px-3 py-2.5 border border-gray-200 rounded-lg text-sm bg-white text-gray-900 min-w-[120px] focus:outline-none focus:border-[#7a3f91]">
                <option value="recent">Recent First</option>
                <option value="oldest">Oldest First</option>
            </select>
            <button wire:click="resetCoordFilters" class="bg-white border border-gray-300 hover:bg-gray-50 text-gray-800 px-3 py-2.5 rounded-lg text-sm font-semibold flex items-center gap-1.5 transition">
                <i class="fas fa-rotate-left text-sm"></i><span class="hidden sm:inline">Reset</span>
            </button>
        </div>

        {{-- Table --}}
        <div class="relative flex-1 min-h-0" x-data="{ showTop: false }">
            <div id="coord-scroll"
                 @scroll.passive="showTop = $event.target.scrollTop > 200"
                 class="h-full overflow-y-auto overflow-x-auto scroll-custom"
                 wire:loading.class="opacity-40 pointer-events-none"
                 wire:target="coordSearch,coordCollege,coordSort,resetCoordFilters,executeToggleCoordinatorStatus,deleteCoordinator">
                <table class="w-full border-collapse" style="min-width:700px;">
                    <thead>
                        <tr class="bg-[#f5f0fa] border-b border-[#e2d3ef] sticky top-0 z-10">
                            <th class="px-4 sm:px-5 py-3.5 text-left text-sm font-bold text-gray-700 uppercase tracking-wider">Name</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-sm font-bold text-gray-700 uppercase tracking-wider">Teacher ID</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-sm font-bold text-gray-700 uppercase tracking-wider hidden md:table-cell">Email</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-sm font-bold text-gray-700 uppercase tracking-wider">College</th>
                            <th class="px-4 sm:px-5 py-3.5 text-center text-sm font-bold text-gray-700 uppercase tracking-wider">Status</th>
                            <th class="px-4 sm:px-5 py-3.5 text-center text-sm font-bold text-gray-700 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($this->coordinatorRecords as $item)
                        <tr class="bg-white hover:bg-[#faf7fd] transition">
                            <td class="px-4 sm:px-5 py-4">
                                <div class="flex items-center gap-3">
                                    <img src="{{ $this->getPhotoUrl($item->profile_photo) }}" alt="{{ $item->first_name }}"
                                         class="w-10 h-10 rounded-xl object-cover shrink-0 shadow-sm ring-1 ring-gray-200">
                                    <span class="font-bold text-gray-900 text-base leading-tight">
                                        {{ $this->formatDisplayName($item->first_name??'', $item->middle_initial??'', $item->last_name??'', $item->suffix??'') }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 sm:px-5 py-4">
                                <span class="font-mono text-gray-800 text-sm font-bold">{{ $item->id_number }}</span>
                            </td>
                            <td class="px-4 sm:px-5 py-4 hidden md:table-cell">
                                <span class="text-gray-700 text-sm">{{ $item->email }}</span>
                            </td>
                            <td class="px-4 sm:px-5 py-4">
                                @php
                                    $dept        = $item->department;
                                    $directMatch = \App\Models\Course::where('college',$dept)->exists();
                                    $collegeName = $directMatch ? $dept : (\App\Models\Course::where('code',$dept)->value('college') ?? $dept);
                                    $deptCodes   = \App\Models\Course::where('college',$collegeName)->orderBy('code')->pluck('code')->toArray();
                                @endphp
                                <span class="block font-bold text-gray-900 text-sm leading-snug">{{ $collegeName }}</span>
                                @if(count($deptCodes))
                                <div class="flex flex-wrap gap-1 mt-1">
                                    @foreach($deptCodes as $dc)
                                        <span class="text-sm font-mono font-bold text-[#7a3f91]">{{ $dc }}</span>
                                        @if(!$loop->last)<span class="text-gray-300 text-sm">·</span>@endif
                                    @endforeach
                                </div>
                                @endif
                            </td>
                            <td class="px-4 sm:px-5 py-4 text-center">
                                @php
                                    $sc = match($item->status) {
                                        'ACTIVE'    => 'bg-green-50 text-green-700 border-green-200',
                                        'INACTIVE'  => 'bg-amber-50 text-amber-700 border-amber-200',
                                        'SUSPENDED' => 'bg-red-50 text-red-700 border-red-200',
                                        default     => 'bg-gray-50 text-gray-700 border-gray-200'
                                    };
                                @endphp
                                <span class="inline-block px-3 py-1.5 border rounded-full text-sm font-bold {{ $sc }}">{{ $item->status }}</span>
                            </td>
                            <td class="px-4 sm:px-5 py-4 text-center">
                                <div class="flex items-center justify-center gap-1.5 flex-wrap">
                                    {{-- View --}}
                                    <button wire:click="viewProfile({{ $item->id }})"
                                            title="View Profile"
                                            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-bold bg-[#f5eef9] text-[#7a3f91] border border-[#d4aaeb] hover:bg-[#e9d5f3] transition">
                                        <i class="fas fa-eye text-sm"></i><span class="hidden lg:inline">View</span>
                                    </button>
                                    {{-- Activate / Deactivate --}}
                                    @if($item->status === 'ACTIVE')
                                        <button wire:click="confirmToggleCoordinatorStatus({{ $item->id }},'deactivate')"
                                                title="Deactivate"
                                                class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-bold bg-white text-amber-600 border border-amber-200 hover:bg-amber-50 transition">
                                            <i class="fas fa-ban text-sm"></i><span class="hidden lg:inline">Deactivate</span>
                                        </button>
                                    @else
                                        <button wire:click="confirmToggleCoordinatorStatus({{ $item->id }},'activate')"
                                                title="Activate"
                                                class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-bold bg-white text-green-600 border border-green-200 hover:bg-green-50 transition">
                                            <i class="fas fa-circle-check text-sm"></i><span class="hidden lg:inline">Activate</span>
                                        </button>
                                    @endif
                                    {{-- Delete --}}
                                    <button wire:click="confirmDeleteCoordinator({{ $item->id }})"
                                            title="Delete Account"
                                            class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-bold bg-white text-red-600 border border-red-200 hover:bg-red-50 transition">
                                        <i class="fas fa-trash text-sm"></i><span class="hidden lg:inline">Delete</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="py-20 text-center">
                                <div class="flex flex-col items-center gap-3">
                                    <div class="w-16 h-16 bg-[#f5eef9] rounded-full flex items-center justify-center">
                                        <i class="fas fa-users-gear text-2xl text-[#c49dd8]"></i>
                                    </div>
                                    <p class="font-bold text-gray-500 text-base">No coordinators found</p>
                                    <p class="text-sm text-gray-400">Try adjusting filters or register a new coordinator</p>
                                </div>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <button x-show="showTop"
                    @click="document.getElementById('coord-scroll').scrollTo({top:0,behavior:'smooth'})"
                    class="absolute bottom-4 right-4 z-20 w-9 h-9 rounded-full flex items-center justify-center shadow-lg bg-[#7a3f91] text-white hover:bg-[#5e2f72] transition"
                    style="display:none">
                <i class="fas fa-arrow-up text-sm"></i>
            </button>
        </div>

        {{-- Pagination --}}
        <div class="px-4 sm:px-5 py-3.5 border-t border-gray-200 shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 bg-[#2b0d3e]">
            @php
                $total = $this->coordinatorRecords->total();
                $pp    = $this->coordinatorRecords->perPage();
                $cp    = $this->coordinatorRecords->currentPage();
                $from  = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
                $to    = min($cp * $pp, $total);
            @endphp
            <p class="text-white text-sm">Showing <strong>{{ $from }}–{{ $to }}</strong> of <strong>{{ $total }}</strong> coordinators</p>
            <div class="flex items-center gap-2">
                @if($this->coordinatorRecords->onFirstPage())
                    <button disabled class="px-4 py-2 bg-white/10 text-white/40 rounded-lg text-sm font-bold cursor-not-allowed">← Prev</button>
                @else
                    <button wire:click="previousPage('coordPage')" class="px-4 py-2 bg-[#7a3f91] hover:bg-[#5e2f72] text-white rounded-lg text-sm font-bold transition">← Prev</button>
                @endif
                <span class="px-4 py-2 text-gray-900 text-sm font-bold bg-white rounded-lg">{{ $this->coordinatorRecords->currentPage() }} / {{ $this->coordinatorRecords->lastPage() }}</span>
                @if($this->coordinatorRecords->hasMorePages())
                    <button wire:click="nextPage('coordPage')" class="px-4 py-2 bg-[#7a3f91] hover:bg-[#5e2f72] text-white rounded-lg text-sm font-bold transition">Next →</button>
                @else
                    <button disabled class="px-4 py-2 bg-white/10 text-white/40 rounded-lg text-sm font-bold cursor-not-allowed">Next →</button>
                @endif
            </div>
        </div>

    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════════ --}}
{{--  MODALS                                                        --}}
{{-- ═══════════════════════════════════════════════════════════════ --}}

{{-- ── REGISTER COORDINATOR ────────────────────── --}}
@if($activeModal==='registerCoordinator')
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5 bg-black/50 backdrop-blur-sm overflow-y-auto"
     @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl my-4 sm:my-8 animate-in fade-in zoom-in-95 duration-200">
        <div class="flex items-center justify-between px-6 py-4 bg-[#7a3f91] border-b border-[#5e2f72] rounded-t-2xl shrink-0">
            <h2 class="text-white font-extrabold text-xl flex items-center gap-2">
                <i class="fas fa-users-gear"></i> Register Coordinator
            </h2>
            <button wire:click="closeModal" class="text-white/60 hover:text-white text-xl transition"><i class="fas fa-xmark"></i></button>
        </div>

        @if($coordinatorSuccess)
        <div class="mx-5 sm:mx-7 mt-5 flex items-start gap-3 p-4 rounded-xl bg-green-50 border border-green-200">
            <i class="fas fa-circle-check text-green-500 mt-0.5 shrink-0 text-lg"></i>
            <div class="flex-1">
                <p class="font-bold text-base text-green-900">Registration Successful!</p>
                <p class="text-sm mt-0.5 text-green-800">{{ $coordinatorSuccess }}</p>
            </div>
            <button wire:click="closeModal" class="bg-[#7a3f91] text-white px-3.5 py-2 rounded-lg text-sm font-bold shrink-0 hover:bg-[#5e2f72] transition">Done</button>
        </div>
        @endif

        @if(count($coordinatorErrors) > 0)
        <div class="mx-5 sm:mx-7 mt-5 p-4 rounded-xl bg-red-50 border border-red-200">
            <p class="font-bold text-sm text-red-900 mb-2"><i class="fas fa-triangle-exclamation mr-1.5"></i>Please fix the following:</p>
            <ul class="text-sm space-y-1 text-red-800">
                @foreach($coordinatorErrors as $ms)
                    @foreach($ms as $m)
                    <li class="flex items-start gap-2"><span class="mt-0.5 shrink-0">•</span>{{ $m }}</li>
                    @endforeach
                @endforeach
            </ul>
        </div>
        @endif

        <form wire:submit="registerCoordinator" class="p-5 sm:p-7 space-y-5 overflow-y-auto" style="max-height:calc(100vh - 180px);">

            {{-- Photo --}}
            <div>
                <label class="block text-sm font-bold text-gray-900 uppercase tracking-wide mb-2">Profile Photo</label>
                <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center cursor-pointer hover:border-[#7a3f91] hover:bg-[#f5eef9] transition"
                     onclick="document.getElementById('coordPhotoInput').click()">
                    @if($coordPhoto)
                        <img src="{{ $coordPhoto->temporaryUrl() }}" class="w-24 h-24 rounded-xl mx-auto mb-2 object-cover shadow-md">
                        <p class="text-sm text-green-600 font-semibold"><i class="fas fa-check mr-1"></i>Photo Selected</p>
                    @else
                        <i class="fas fa-cloud-arrow-up text-3xl text-gray-300 block mb-2"></i>
                        <p class="text-base text-gray-700 font-semibold">Click to Upload</p>
                        <p class="text-sm text-gray-400 mt-0.5">JPG, PNG, WebP · max 5 MB</p>
                    @endif
                    <input type="file" id="coordPhotoInput" wire:model="coordPhoto" accept="image/*" class="hidden">
                </div>
            </div>

            {{-- Full Name --}}
            <div>
                <label class="block text-sm font-bold text-gray-900 uppercase tracking-wide mb-2">Full Name <span class="text-red-500">*</span></label>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <input wire:model.defer="coordFirstName" type="text" placeholder="First Name"
                               class="w-full px-3.5 py-3 border border-gray-300 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                        <p class="text-xs text-gray-500 mt-1">First Name <span class="text-red-400">*</span></p>
                    </div>
                    <div>
                        <input wire:model.defer="coordLastName" type="text" placeholder="Last Name"
                               class="w-full px-3.5 py-3 border border-gray-300 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                        <p class="text-xs text-gray-500 mt-1">Last Name <span class="text-red-400">*</span></p>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3 mt-3">
                    <div>
                        <input wire:model.defer="coordMiddleInitial" type="text" placeholder="e.g. Santos" maxlength="50"
                               class="w-full px-3.5 py-3 border border-gray-300 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                        <p class="text-xs text-gray-500 mt-1">Middle Name <span class="text-gray-400">(full word)</span></p>
                    </div>
                    <div>
                        <input wire:model.defer="coordSuffix" type="text" placeholder="e.g. Jr." maxlength="10"
                               class="w-full px-3.5 py-3 border border-gray-300 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                        <p class="text-xs text-gray-500 mt-1">Suffix</p>
                    </div>
                </div>
            </div>

            {{-- Teacher ID + Email --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-bold text-gray-900 uppercase tracking-wide mb-2">Teacher ID <span class="text-red-500">*</span></label>
                    <input wire:model.defer="coordTeacherId" type="text" placeholder="e.g. 12345" maxlength="8" inputmode="numeric"
                           class="w-full px-3.5 py-3 border border-gray-300 rounded-lg text-sm bg-white text-gray-900 font-mono focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                    <p class="text-xs text-gray-500 mt-1">Numbers only · padded to 8 digits</p>
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-900 uppercase tracking-wide mb-2">Email <span class="text-red-500">*</span></label>
                    <input wire:model.defer="coordEmail" type="email" placeholder="coordinator@example.com"
                           class="w-full px-3.5 py-3 border border-gray-300 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                </div>
            </div>

            {{-- College --}}
            <div>
                <label class="block text-sm font-bold text-gray-900 uppercase tracking-wide mb-2">College <span class="text-red-500">*</span></label>
                @if($this->orgDepartmentsGrouped->isEmpty())
                    <div class="p-4 rounded-xl bg-blue-50 border border-blue-200 text-sm flex items-start gap-2">
                        <i class="fas fa-triangle-exclamation text-amber-500 mt-0.5 shrink-0"></i>
                        <span class="text-gray-800">No colleges configured yet. Set up colleges via <strong>Manage Colleges</strong>.</span>
                    </div>
                @else
                    @php
                        $collegeDeptsMap  = [];
                        $occupiedColleges = $this->occupiedColleges();
                        foreach ($this->orgDepartmentsGrouped as $cN => $depts) {
                            $collegeDeptsMap[$cN] = $depts->pluck('code')->toArray();
                        }
                    @endphp
                    <div x-data="{ map: {{ Js::from($collegeDeptsMap) }}, get depts(){ return $wire.coordCollegeSelect?(this.map[$wire.coordCollegeSelect]??[]):[]; } }">
                        <select wire:model.live="coordCollegeSelect"
                                class="w-full px-3.5 py-3 border border-gray-300 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10">
                            <option value="">Select College</option>
                            @foreach($this->orgDepartmentsGrouped->keys() as $cN)
                                @php $isOccupied = isset($occupiedColleges[$cN]); @endphp
                                <option value="{{ $cN }}" {{ $isOccupied ? 'disabled' : '' }}>
                                    {{ $cN }}{{ $isOccupied ? ' — occupied by '.$occupiedColleges[$cN] : '' }}
                                </option>
                            @endforeach
                        </select>
                        @if(count($occupiedColleges) > 0)
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            @foreach($occupiedColleges as $oC => $oN)
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1.5 bg-red-50 text-red-700 border border-red-200 rounded-lg text-sm font-medium">
                                <i class="fas fa-lock text-xs text-red-400"></i>
                                <strong>{{ $oC }}</strong><span class="text-red-300">·</span>{{ $oN }}
                            </span>
                            @endforeach
                        </div>
                        @endif
                        <div x-show="depts.length > 0" x-cloak class="mt-3">
                            <p class="text-sm text-gray-500 mb-1.5 font-medium">Departments under this college:</p>
                            <div class="flex flex-wrap gap-1.5">
                                <template x-for="code in depts" :key="code">
                                    <span class="inline-block px-2.5 py-1 bg-[#f5eef9] text-[#7a3f91] border border-[#d4aaeb] rounded-full text-sm font-bold font-mono" x-text="code"></span>
                                </template>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Buttons --}}
            <div class="flex gap-3 pt-2">
                <button type="button" wire:click="closeModal"
                        class="flex-1 px-5 py-3 rounded-xl text-sm font-bold bg-white border border-gray-300 text-gray-900 hover:bg-gray-50 transition">
                    Cancel
                </button>
                <button type="submit" wire:loading.attr="disabled" wire:target="registerCoordinator"
                        class="flex-1 px-5 py-3 rounded-xl text-sm font-bold bg-[#7a3f91] hover:bg-[#5e2f72] text-white transition flex items-center justify-center gap-2">
                    <span wire:loading wire:target="registerCoordinator"><i class="fas fa-spinner animate-spin"></i> Registering...</span>
                    <span wire:loading.remove wire:target="registerCoordinator"><i class="fas fa-users-gear"></i> Register Coordinator</span>
                </button>
            </div>
        </form>
    </div>
</div>
@endif

{{-- ── VIEW PROFILE ─────────────────────────────── --}}
@if($activeModal === 'viewProfile' && $viewingProfile)
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5 bg-black/50 backdrop-blur-sm overflow-y-auto"
     @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl my-4 sm:my-8 animate-in fade-in zoom-in-95 duration-200">
        <div class="flex items-center justify-between px-6 py-4 bg-[#7a3f91] border-b border-[#5e2f72] rounded-t-2xl sticky top-0 z-10">
            <h2 class="text-white font-extrabold text-xl flex items-center gap-2">
                <i class="fas fa-users-gear"></i> Coordinator Profile
            </h2>
            <button wire:click="closeModal" class="text-white/60 hover:text-white text-xl transition"><i class="fas fa-xmark"></i></button>
        </div>

        <div class="p-5 sm:p-6 space-y-5 overflow-y-auto" style="max-height:84vh;">

            {{-- Avatar + Quick Info --}}
            <div class="flex items-center gap-5 p-4 bg-[#f5f0fa] rounded-xl border border-[#e2d3ef]">
                @if($updatingProfilePhoto)
                    <img src="{{ $updatingProfilePhoto->temporaryUrl() }}" class="w-20 h-20 rounded-2xl object-cover shadow-lg ring-2 ring-[#7a3f91]/20 shrink-0">
                @else
                    <img src="{{ $this->getPhotoUrl($viewingProfile['profile_photo'] ?? null) }}" alt="{{ $viewingProfile['first_name'] ?? '' }}"
                         class="w-20 h-20 rounded-2xl object-cover shadow-lg ring-2 ring-[#d4aaeb] shrink-0">
                @endif
                <div>
                    <p class="text-lg font-extrabold text-gray-900 leading-tight">
                        {{ $this->formatDisplayName($viewingProfile['first_name']??'', $viewingProfile['middle_initial']??'', $viewingProfile['last_name']??'', $viewingProfile['suffix']??'') }}
                    </p>
                    <p class="text-gray-600 text-sm mt-0.5">{{ $viewingProfile['email'] }}</p>
                    @php
                        $sc = match($viewingProfile['status'] ?? '') {
                            'ACTIVE'    => 'bg-green-50 text-green-700 border-green-200',
                            'INACTIVE'  => 'bg-amber-50 text-amber-700 border-amber-200',
                            'SUSPENDED' => 'bg-red-50 text-red-700 border-red-200',
                            default     => 'bg-gray-50 text-gray-700 border-gray-200'
                        };
                    @endphp
                    <span class="inline-block mt-2 px-3 py-1.5 border rounded-full text-sm font-bold {{ $sc }}">{{ $viewingProfile['status'] ?? 'N/A' }}</span>
                </div>
            </div>

            {{-- Personal Information --}}
            <div>
                <h3 class="text-sm font-bold text-gray-500 uppercase tracking-wider mb-3">Personal Information</h3>
                <div class="grid grid-cols-2 gap-3">
                    @foreach([
                        ['First Name',  $viewingProfile['first_name']    ?? '—'],
                        ['Last Name',   $viewingProfile['last_name']     ?? '—'],
                        ['Middle Name', $viewingProfile['middle_initial'] ?? '—'],
                        ['Suffix',      $viewingProfile['suffix']         ?: '—'],
                    ] as [$lbl, $val])
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                        <p class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">{{ $lbl }}</p>
                        <p class="text-base font-semibold text-gray-800">{{ $val }}</p>
                    </div>
                    @endforeach
                </div>
            </div>

            {{-- Assignment --}}
            <div>
                <h3 class="text-sm font-bold text-gray-500 uppercase tracking-wider mb-3">Assignment</h3>
                <div class="grid grid-cols-2 gap-3">
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                        <p class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Teacher ID</p>
                        <p class="text-base font-semibold text-gray-800 font-mono">{{ $viewingProfile['id_number'] ?? '—' }}</p>
                    </div>
                    <div class="bg-[#f5eef9] border border-[#d4aaeb] rounded-lg p-3">
                        <p class="text-xs font-bold text-[#7a3f91] uppercase tracking-wide mb-1">College</p>
                        <p class="text-base font-semibold text-[#5e2f72]">{{ $this->getCollegeForCourse($viewingProfile['department'] ?? '') }}</p>
                    </div>
                </div>
            </div>

            {{-- Account --}}
            <div>
                <h3 class="text-sm font-bold text-gray-500 uppercase tracking-wider mb-3">Account</h3>
                <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                    <p class="text-xs font-bold text-gray-500 uppercase tracking-wide mb-1">Email Address</p>
                    <p class="text-base font-semibold text-gray-800">{{ $viewingProfile['email'] ?? '—' }}</p>
                </div>
            </div>

            {{-- Update Photo --}}
            <div class="border-t border-gray-100 pt-4">
                <p class="block text-sm font-bold text-gray-900 uppercase tracking-wide mb-2">Update Profile Photo</p>
                <div class="border-2 border-dashed border-gray-300 rounded-lg p-5 text-center cursor-pointer hover:border-[#7a3f91] hover:bg-[#f5eef9] transition"
                     @click="document.getElementById('profilePhotoInput').click()">
                    <i class="fas fa-camera text-2xl text-gray-300 block mb-1.5"></i>
                    <p class="text-gray-700 font-semibold text-base">{{ $updatingProfilePhoto ? 'Change Photo' : 'Click to Upload New Photo' }}</p>
                    <p class="text-sm text-gray-400 mt-0.5">JPG, PNG, WebP · max 5 MB</p>
                    <input type="file" id="profilePhotoInput" wire:model="updatingProfilePhoto" accept="image/*" class="hidden">
                </div>
                @if($updatingProfilePhoto)
                <button wire:click="updateProfilePhoto" wire:loading.attr="disabled" wire:target="updateProfilePhoto"
                        class="w-full mt-3 bg-[#7a3f91] hover:bg-[#5e2f72] text-white px-5 py-3 rounded-xl text-sm font-bold transition flex items-center justify-center gap-2">
                    <span wire:loading wire:target="updateProfilePhoto"><i class="fas fa-spinner animate-spin"></i> Saving...</span>
                    <span wire:loading.remove wire:target="updateProfilePhoto"><i class="fas fa-floppy-disk"></i> Save Photo</span>
                </button>
                @endif
            </div>

            <button wire:click="closeModal"
                    class="w-full bg-white border border-gray-300 text-gray-900 px-5 py-3 rounded-xl text-sm font-bold hover:bg-gray-50 transition">
                Close
            </button>
        </div>
    </div>
</div>
@endif

{{-- ── MANAGE COLLEGES ──────────────────────────── --}}
@if($activeModal === 'manageOrgCourses')
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5 bg-black/50 backdrop-blur-sm overflow-y-auto"
     @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl my-4 sm:my-8 animate-in fade-in zoom-in-95 duration-200 flex flex-col" style="max-height:92vh;">
        <div class="flex items-center justify-between px-6 py-4 bg-[#7a3f91] border-b border-[#5e2f72] rounded-t-2xl shrink-0">
            <h2 class="text-white font-extrabold text-xl flex items-center gap-2">
                <i class="fas fa-building-columns"></i>
                <span class="hidden sm:inline">Manage Colleges &amp; Departments</span>
                <span class="sm:hidden">Colleges</span>
            </h2>
            <button wire:click="closeModal" class="text-white/60 hover:text-white text-xl transition"><i class="fas fa-xmark"></i></button>
        </div>

        @if($orgCourseAlert)
        <div class="mx-5 sm:mx-7 mt-4 shrink-0 flex items-start gap-2.5 p-3.5 rounded-xl
             {{ $orgCourseAlertType === 'success' ? 'bg-green-50 border border-green-200' : 'bg-red-50 border border-red-200' }}">
            <i class="fas mt-0.5 text-base {{ $orgCourseAlertType === 'success' ? 'fa-circle-check text-green-500' : 'fa-circle-xmark text-red-500' }}"></i>
            <p class="text-sm font-semibold {{ $orgCourseAlertType === 'success' ? 'text-green-900' : 'text-red-900' }}">{{ $orgCourseAlert }}</p>
        </div>
        @endif

        <div class="flex-1 overflow-y-auto px-5 sm:px-7 py-5 space-y-5">

            {{-- Add New College --}}
            @if(!$orgAddingToCollege && !$orgRenamingCollege)
            <div class="bg-gray-50 border border-gray-200 rounded-lg p-4">
                <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wide mb-3 flex items-center gap-2">
                    <i class="fas fa-plus-circle text-[#7a3f91]"></i> Add New College
                </h3>
                <div class="flex gap-2">
                    <input wire:model.defer="orgNewCollegeName" type="text" placeholder="e.g. College of Computer Studies"
                           class="flex-1 px-3.5 py-3 border border-gray-300 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10"
                           @keydown.enter.prevent="$wire.addCollege()">
                    <button wire:click="addCollege" class="bg-[#7a3f91] hover:bg-[#5e2f72] text-white px-4 py-3 rounded-xl text-sm font-bold whitespace-nowrap transition flex items-center gap-1.5">
                        <i class="fas fa-plus text-sm"></i><span class="hidden sm:inline">Add College</span><span class="sm:hidden">Add</span>
                    </button>
                </div>
            </div>
            @endif

            {{-- Rename College --}}
            @if($orgRenamingCollege)
            <div class="border-2 rounded-xl p-5 border-[#d4aaeb] bg-[#f5eef9]">
                <div class="flex items-center gap-2 mb-3">
                    <i class="fas fa-pen-to-square text-[#7a3f91]"></i>
                    <h3 class="text-base font-bold text-gray-900">Rename College</h3>
                </div>
                <p class="text-sm text-gray-600 mb-2">Current: <strong class="text-gray-800">{{ $orgRenamingCollege }}</strong></p>
                <div class="flex gap-2">
                    <input wire:model.defer="orgRenameCollegeName" type="text" placeholder="New college name"
                           class="flex-1 px-3.5 py-3 border border-gray-300 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10"
                           @keydown.enter.prevent="$wire.renameCollege()">
                    <button wire:click="cancelRenamingCollege" class="bg-white border border-gray-300 text-gray-900 px-3.5 py-3 rounded-xl text-sm font-bold hover:bg-gray-50 transition">Cancel</button>
                    <button wire:click="renameCollege" wire:loading.attr="disabled" wire:target="renameCollege"
                            class="bg-[#7a3f91] hover:bg-[#5e2f72] text-white px-4 py-3 rounded-xl text-sm font-bold transition flex items-center gap-1.5 whitespace-nowrap">
                        <span wire:loading wire:target="renameCollege"><i class="fas fa-spinner animate-spin"></i></span>
                        <span wire:loading.remove wire:target="renameCollege"><i class="fas fa-floppy-disk"></i> Save</span>
                    </button>
                </div>
            </div>
            @endif

            {{-- Assign Departments --}}
            @if($orgAddingToCollege)
            <div class="border-2 rounded-xl p-5 border-[#d4aaeb] bg-[#f5eef9]">
                <div class="flex items-start justify-between mb-4">
                    <div>
                        <h3 class="text-base font-bold text-gray-900 flex items-center gap-2">
                            <i class="fas fa-{{ isset($orgCoursesList[$orgAddingToCollege]) ? 'pencil' : 'plus' }} text-[#7a3f91]"></i>
                            {{ isset($orgCoursesList[$orgAddingToCollege]) ? 'Edit Departments' : 'Assign Departments' }}
                        </h3>
                        <p class="text-sm text-gray-500 mt-0.5">College: <strong class="text-gray-800">{{ $orgAddingToCollege }}</strong></p>
                    </div>
                    <span class="inline-block px-2.5 py-1.5 bg-[#7a3f91] text-white rounded-full text-sm font-bold">{{ count($orgSelectedCourseCodes) }} selected</span>
                </div>
                @if($this->allCoursesForAssign->count() > 0)
                <p class="text-sm text-gray-600 mb-2.5">Check all courses belonging to this college:</p>
                <div class="space-y-1.5 overflow-y-auto pr-1 mb-4" style="max-height:220px;">
                    @foreach($this->allCoursesForAssign as $c)
                    @php
                        $isSelected   = in_array($c->code, $orgSelectedCourseCodes);
                        $otherCollege = ($c->college && $c->college !== $orgAddingToCollege) ? $c->college : null;
                        $isTaken      = $otherCollege !== null;
                    @endphp
                    <label class="flex items-center gap-3 p-3 border rounded-xl cursor-pointer transition bg-white
                        {{ $isTaken ? 'opacity-50 cursor-not-allowed border-gray-100' : ($isSelected ? 'border-[#7a3f91]/40 shadow-sm' : 'border-gray-100 hover:border-gray-300') }}">
                        <input type="checkbox" wire:model="orgSelectedCourseCodes" value="{{ $c->code }}"
                               class="w-4 h-4 shrink-0 rounded" style="accent-color:#7a3f91;" {{ $isTaken ? 'disabled' : '' }}>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-bold text-gray-900 text-sm font-mono">{{ $c->code }}</span>
                                <span class="text-gray-500 text-sm truncate">{{ $c->name }}</span>
                            </div>
                            @if($isTaken)
                            <p class="text-sm text-amber-700 mt-0.5"><i class="fas fa-lock mr-1"></i>Assigned to: <em>{{ $otherCollege }}</em></p>
                            @endif
                        </div>
                        @if($isSelected && !$isTaken)<i class="fas fa-circle-check shrink-0 text-[#7a3f91]"></i>@endif
                    </label>
                    @endforeach
                </div>
                @else
                <div class="text-center py-5">
                    <i class="fas fa-book text-3xl text-gray-200 block mb-2"></i>
                    <p class="text-gray-500 text-sm">No courses available. Add courses via <strong>Manage Courses</strong>.</p>
                </div>
                @endif
                <div class="flex gap-3">
                    <button wire:click="cancelAddingCourses" class="flex-1 bg-white border border-gray-300 text-gray-900 px-4 py-3 rounded-xl text-sm font-bold hover:bg-gray-50 transition">Cancel</button>
                    <button wire:click="saveCollegeCourses" wire:loading.attr="disabled" wire:target="saveCollegeCourses"
                            class="flex-1 bg-[#7a3f91] hover:bg-[#5e2f72] text-white px-4 py-3 rounded-xl text-sm font-bold transition flex items-center justify-center gap-2">
                        <span wire:loading wire:target="saveCollegeCourses"><i class="fas fa-spinner animate-spin"></i> Saving...</span>
                        <span wire:loading.remove wire:target="saveCollegeCourses"><i class="fas fa-floppy-disk"></i> Save Departments</span>
                    </button>
                </div>
            </div>
            @endif

            {{-- College List --}}
            <div>
                <h3 class="text-sm font-bold text-gray-900 uppercase tracking-wide mb-2 flex items-center gap-2">
                    <i class="fas fa-list text-gray-400"></i>Colleges &amp; Departments
                    <span class="ml-auto text-sm font-bold text-gray-700 bg-gray-100 px-2.5 py-1 rounded-full border border-gray-200">{{ count($orgCoursesList) }}</span>
                </h3>
                @if(count($orgCoursesList) === 0)
                <div class="text-center py-8 border-2 border-dashed border-gray-100 rounded-xl">
                    <i class="fas fa-building-columns text-3xl text-gray-200 block mb-2"></i>
                    <p class="text-gray-400 font-semibold text-base">No colleges yet</p>
                </div>
                @else
                <div class="space-y-2.5">
                    @foreach($orgCoursesList as $college => $departments)
                    @php $collegeOcc = $this->occupiedColleges(); $collegeOrg = $collegeOcc[$college] ?? null; @endphp
                    <div class="border border-gray-200 rounded-xl overflow-hidden">
                        <div class="flex items-center justify-between px-4 py-3 bg-[#f5eef9]">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0 bg-[#e9d5f3]">
                                    <i class="fas fa-building-columns text-sm text-[#7a3f91]"></i>
                                </div>
                                <div>
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <p class="font-bold text-base text-gray-900">{{ $college }}</p>
                                        @if($collegeOrg)
                                            <span class="inline-block px-2.5 py-1 bg-green-50 text-green-700 border border-green-200 rounded-full text-sm font-bold">
                                                <i class="fas fa-circle-check mr-1 text-xs"></i>{{ $collegeOrg }}
                                            </span>
                                        @else
                                            <span class="inline-block px-2.5 py-1 bg-gray-100 text-gray-600 border border-gray-200 rounded-full text-sm font-bold">No Coordinator</span>
                                        @endif
                                    </div>
                                    <p class="text-sm text-gray-500 mt-0.5">{{ count($departments) }} dept{{ count($departments) !== 1 ? 's' : '' }}</p>
                                </div>
                            </div>
                            <div class="flex gap-1.5">
                                @if(!$orgAddingToCollege && !$orgRenamingCollege)
                                <button wire:click="startRenamingCollege('{{ addslashes($college) }}')"
                                        class="bg-[#f5eef9] border border-[#d4aaeb] text-[#7a3f91] hover:bg-[#e9d5f3] px-3 py-2 rounded-lg text-sm font-bold transition flex items-center gap-1.5">
                                    <i class="fas fa-pen-to-square text-sm"></i><span class="hidden sm:inline">Rename</span>
                                </button>
                                <button wire:click="startEditingCollege('{{ addslashes($college) }}')"
                                        class="bg-[#f5eef9] border border-[#d4aaeb] text-[#7a3f91] hover:bg-[#e9d5f3] px-3 py-2 rounded-lg text-sm font-bold transition flex items-center gap-1.5">
                                    <i class="fas fa-pencil text-sm"></i><span class="hidden sm:inline">Depts</span>
                                </button>
                                @endif
                                <button wire:click="confirmDeleteCollege('{{ addslashes($college) }}')"
                                        class="bg-white border border-red-200 text-red-600 hover:bg-red-50 px-3 py-2 rounded-lg text-sm font-bold transition flex items-center gap-1.5">
                                    <i class="fas fa-trash text-sm"></i><span class="hidden sm:inline">Delete</span>
                                </button>
                            </div>
                        </div>
                        <div class="divide-y divide-gray-50 bg-white">
                            @foreach($departments as $dept)
                            <div class="flex items-center px-4 py-3">
                                <span class="w-8 h-8 rounded-lg flex items-center justify-center text-xs font-bold shrink-0 bg-[#f5eef9] text-[#7a3f91]">
                                    {{ strtoupper(substr($dept['code'], 0, 2)) }}
                                </span>
                                <div class="ml-3">
                                    <p class="font-bold text-gray-900 text-sm">{{ $dept['code'] }}</p>
                                    <p class="text-gray-500 text-sm">{{ $dept['name'] }}</p>
                                </div>
                            </div>
                            @endforeach
                        </div>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>
        </div>

        <div class="px-5 sm:px-7 py-4 border-t border-gray-100 bg-gray-50 rounded-b-2xl shrink-0">
            <button wire:click="closeModal" class="w-full bg-white border border-gray-300 text-gray-900 px-5 py-3 rounded-xl text-sm font-bold hover:bg-gray-50 transition">Close</button>
        </div>
    </div>
</div>
@endif

{{-- ── DELETE COLLEGE CONFIRM ───────────────────── --}}
@if($activeModal === 'deleteOrgCollegeConfirm')
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5 bg-black/50 backdrop-blur-sm">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm animate-in fade-in zoom-in-95 duration-200">
        <div class="px-6 py-4 bg-red-50 border-b border-red-100 rounded-t-2xl">
            <h2 class="text-lg font-extrabold text-red-900 flex items-center gap-2"><i class="fas fa-triangle-exclamation"></i> Delete College</h2>
        </div>
        <div class="p-6">
            <p class="text-gray-800 text-base mb-1">Remove <strong class="text-red-700">{{ $deleteOrgCourseName }}</strong>?</p>
            <p class="text-gray-500 text-sm mb-5"><i class="fas fa-circle-info mr-1"></i>Courses will be unassigned but <strong>not deleted</strong>.</p>
            <div class="flex gap-3">
                <button wire:click="openModal('manageOrgCourses')" class="flex-1 bg-white border border-gray-300 text-gray-900 px-5 py-3 rounded-xl text-sm font-bold hover:bg-gray-50 transition">Cancel</button>
                <button wire:click="deleteOrgCollege" wire:loading.attr="disabled" wire:target="deleteOrgCollege"
                        class="flex-1 bg-red-600 hover:bg-red-700 text-white px-5 py-3 rounded-xl text-sm font-bold transition flex items-center justify-center gap-2">
                    <span wire:loading wire:target="deleteOrgCollege"><i class="fas fa-spinner animate-spin"></i></span>
                    <span wire:loading.remove wire:target="deleteOrgCollege"><i class="fas fa-trash"></i> Delete College</span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ── TOGGLE COORDINATOR STATUS ────────────────── --}}
@if($activeModal === 'toggleCoordinatorConfirm')
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5 bg-black/50 backdrop-blur-sm"
     @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm animate-in fade-in zoom-in-95 duration-200">
        <div class="p-6">
            <div class="flex items-center gap-4 mb-4">
                <div class="w-12 h-12 rounded-full flex items-center justify-center shrink-0
                     {{ $pendingToggleAction === 'deactivate' ? 'bg-amber-100' : 'bg-green-100' }}">
                    <i class="{{ $pendingToggleAction === 'deactivate' ? 'fas fa-ban text-amber-600 text-lg' : 'fas fa-circle-check text-green-600 text-lg' }}"></i>
                </div>
                <div>
                    <p class="font-extrabold text-gray-900 text-lg">
                        {{ $pendingToggleAction === 'deactivate' ? 'Deactivate Coordinator?' : 'Activate Coordinator?' }}
                    </p>
                    <p class="text-sm text-gray-500 mt-0.5">{{ $pendingToggleName }}</p>
                </div>
            </div>
            <p class="text-sm text-gray-600 mb-2">
                @if($pendingToggleAction === 'deactivate')
                    This coordinator will no longer be able to log in. You can reactivate them anytime.
                @else
                    This coordinator will regain login access.
                @endif
            </p>
            @if($pendingToggleAction === 'activate')
            <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2.5 mb-4">
                <i class="fas fa-triangle-exclamation mr-1"></i>
                If this college already has another active coordinator, activation will be blocked.
            </p>
            @else
            <p class="mb-4"></p>
            @endif
            <div class="flex gap-3">
                <button wire:click="closeModal" class="flex-1 bg-white border border-gray-300 text-gray-900 px-4 py-3 rounded-xl text-sm font-bold hover:bg-gray-50 transition">Cancel</button>
                <button wire:click="executeToggleCoordinatorStatus"
                        wire:loading.attr="disabled" wire:target="executeToggleCoordinatorStatus"
                        class="flex-1 px-4 py-3 rounded-xl text-sm font-bold transition flex items-center justify-center gap-2
                            {{ $pendingToggleAction === 'deactivate' ? 'bg-amber-500 hover:bg-amber-600 text-white' : 'bg-green-600 hover:bg-green-700 text-white' }}">
                    <span wire:loading wire:target="executeToggleCoordinatorStatus"><i class="fas fa-spinner animate-spin"></i></span>
                    <span wire:loading.remove wire:target="executeToggleCoordinatorStatus">
                        {{ $pendingToggleAction === 'deactivate' ? 'Yes, Deactivate' : 'Yes, Activate' }}
                    </span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ── DELETE COORDINATOR CONFIRM ───────────────── --}}
@if($activeModal === 'deleteCoordinatorConfirm')
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5 bg-black/50 backdrop-blur-sm"
     @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm animate-in fade-in zoom-in-95 duration-200">

        {{-- Header --}}
        <div class="px-6 py-4 bg-red-600 rounded-t-2xl flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center shrink-0">
                <i class="fas fa-trash text-white text-base"></i>
            </div>
            <h2 class="text-white font-extrabold text-lg">Delete Account</h2>
        </div>

        <div class="p-6">
            {{-- Warning icon + message --}}
            <div class="flex flex-col items-center text-center mb-5">
                <div class="w-16 h-16 bg-red-50 border-2 border-red-200 rounded-full flex items-center justify-center mb-3">
                    <i class="fas fa-triangle-exclamation text-red-500 text-2xl"></i>
                </div>
                <p class="text-gray-900 font-bold text-base">Are you sure you want to permanently delete</p>
                <p class="text-red-700 font-extrabold text-lg mt-1">"{{ $pendingDeleteName }}"?</p>
            </div>

            {{-- Warning box --}}
            <div class="bg-red-50 border border-red-200 rounded-xl px-4 py-3.5 mb-5 space-y-1.5">
                <p class="text-sm font-bold text-red-800 flex items-center gap-2">
                    <i class="fas fa-circle-exclamation text-red-500"></i> This action cannot be undone.
                </p>
                <p class="text-sm text-red-700 pl-5">The coordinator's account and login credentials will be <strong>permanently removed</strong>.</p>
                <p class="text-sm text-red-700 pl-5">All associated data linked to this account will be <strong>deleted</strong>.</p>
            </div>

            {{-- Actions --}}
            <div class="flex gap-3">
                <button wire:click="closeModal"
                        class="flex-1 bg-white border border-gray-300 text-gray-900 px-4 py-3 rounded-xl text-sm font-bold hover:bg-gray-50 transition flex items-center justify-center gap-2">
                    <i class="fas fa-xmark"></i> Cancel
                </button>
                <button wire:click="deleteCoordinator"
                        wire:loading.attr="disabled"
                        wire:target="deleteCoordinator"
                        class="flex-1 bg-red-600 hover:bg-red-700 text-white px-4 py-3 rounded-xl text-sm font-bold transition flex items-center justify-center gap-2">
                    <span wire:loading wire:target="deleteCoordinator">
                        <i class="fas fa-spinner animate-spin"></i> Deleting...
                    </span>
                    <span wire:loading.remove wire:target="deleteCoordinator">
                        <i class="fas fa-trash"></i> Yes, Delete Account
                    </span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

</div>