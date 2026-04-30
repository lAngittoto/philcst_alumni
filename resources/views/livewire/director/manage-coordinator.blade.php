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
    public ?string $orgRenamingCollege     = null;
    public string  $orgRenameCollegeName   = '';

    // ── coordinator toggle ────────────────────────────────────────
    public ?int   $pendingToggleId     = null;
    public string $pendingToggleAction = '';
    public string $pendingToggleName   = '';

    // ── view / edit profile ───────────────────────────────────────
    public ?int   $viewingProfileId     = null;
    public        $viewingProfile       = null;
    public        $updatingProfilePhoto = null;
    public bool   $updatingProfile      = false;

    // ── valid suffixes whitelist ──────────────────────────────────
    protected array $validSuffixes = [
        'Jr', 'Jr.', 'Sr', 'Sr.',
        'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII',
        'PhD', 'MD', 'DDS', 'DMD', 'DO', 'JD', 'LLB', 'LLM',
        'Esq', 'Esq.', 'RN', 'CPA', 'MBA', 'MSc', 'BSc',
    ];

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
    //  FLASH
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

        if ($this->coordCollege) {
            $q->where('department', $this->coordCollege);
        }

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
    #[Computed]
    public function orgColleges()
    {
        return Course::whereNotNull('college')
            ->where('college', '!=', '')
            ->distinct()
            ->orderBy('college')
            ->pluck('college');
    }

    #[Computed]
    public function orgDepartmentsGrouped()
    {
        return Course::whereNotNull('college')
            ->where('college', '!=', '')
            ->orderBy('college')
            ->orderBy('code')
            ->get()
            ->groupBy('college');
    }

    #[Computed]
    public function allCoursesForAssign()
    {
        return Course::orderBy('code')->get();
    }

    #[Computed]
    public function occupiedColleges(): array
    {
        $result = [];
        Organizer::withoutTrashed()
            ->where('status', 'ACTIVE')
            ->select('department', 'first_name', 'middle_initial', 'last_name', 'suffix')
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
    //  UTILITY
    // ─────────────────────────────────────────────────────────────
    public function getCollegeForCourse(string $code): string
    {
        return Course::where('college', $code)->exists()
            ? $code
            : (Course::where('code', $code)->value('college') ?? $code);
    }

    public function getPhotoUrl(?string $path): string
    {
        if (!$path || str_contains($path, 'default.png')) {
            return asset('storage/alumni-photos/default.png');
        }
        if (str_starts_with($path, 'alumni-photos/') || str_starts_with($path, 'organizers/')) {
            return Storage::disk('public')->exists($path)
                ? asset('storage/' . $path)
                : asset('storage/alumni-photos/default.png');
        }
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
            ->whereRaw('LOWER(TRIM(first_name))=?',                    [strtolower(trim($f))])
            ->whereRaw('LOWER(TRIM(last_name))=?',                     [strtolower(trim($l))])
            ->whereRaw('LOWER(TRIM(COALESCE(middle_initial,"")))=?',   [strtolower(trim($m))])
            ->whereRaw('LOWER(TRIM(COALESCE(suffix,"")))=?',           [strtolower(trim($s))]);
        if ($exceptId) $q->where('id', '!=', $exceptId);
        return $q->exists();
    }

    // ─────────────────────────────────────────────────────────────
    //  MODAL CONTROL
    // ─────────────────────────────────────────────────────────────
    public function openModal(string $modal): void
    {
        if ($modal === 'manageOrgCourses')    { $this->loadOrgCourses(); $this->resetOrgCourseForm(); }
        if ($modal === 'registerCoordinator') { $this->coordinatorSuccess = ''; $this->coordinatorErrors = []; }
        $this->activeModal = $modal;
    }

    public function closeModal(): void
    {
        $this->activeModal          = '';
        $this->pendingToggleId      = null;
        $this->pendingToggleAction  = '';
        $this->pendingToggleName    = '';
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
            $firstName = trim($this->coordFirstName);
            $lastName  = trim($this->coordLastName);
            $mid       = trim($this->coordMiddleInitial);
            $suffix    = trim($this->coordSuffix);
            $college   = trim($this->coordCollegeSelect);

            if (!$this->validateName($firstName))
                throw new \Exception('First name may only contain letters, spaces, hyphens, or apostrophes.');

            if (!$this->validateName($lastName))
                throw new \Exception('Last name may only contain letters, spaces, hyphens, or apostrophes.');

            if ($mid !== '') {
                if (!preg_match('/^[a-zA-Z]+$/', $mid))
                    throw new \Exception('Middle name must contain letters only.');
                if (strlen($mid) < 2)
                    throw new \Exception('Middle name must be a full word (e.g. Santos, not S).');
            }

            if ($suffix !== '' && !in_array($suffix, $this->validSuffixes, true)) {
                $examples = implode(', ', array_slice($this->validSuffixes, 0, 8));
                throw new \Exception("Invalid suffix \"{$suffix}\". Accepted values: {$examples}, etc.");
            }

            if ($this->coordinatorFullNameExists($firstName, $mid, $lastName, $suffix))
                throw new \Exception('A coordinator with that full name already exists.');

            if (!$college)
                throw new \Exception('Please select a college.');

            $occupied = $this->occupiedColleges();
            if (isset($occupied[$college]))
                throw new \Exception("College \"{$college}\" already has an active coordinator ({$occupied[$college]}). Deactivate them first.");

            $this->coordDept = $college;

            $this->validate([
                'coordFirstName'     => ['required', 'string', 'max:100'],
                'coordLastName'      => ['required', 'string', 'max:100'],
                'coordMiddleInitial' => ['nullable', 'string', 'min:2', 'max:50', 'regex:/^[a-zA-Z]+$/'],
                'coordSuffix'        => ['nullable', 'string', 'max:10'],
                'coordTeacherId'     => ['required', 'string', 'regex:/^\d{8}$/', 'unique:organizer,id_number'],
                'coordEmail'         => ['required', 'email', 'max:255', 'unique:organizer,email', 'unique:users,email'],
                'coordCollegeSelect' => ['required', 'string'],
                'coordPhoto'         => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
            ], [
                'coordTeacherId.unique'       => 'This Teacher ID is already registered.',
                'coordTeacherId.regex'        => 'Teacher ID must be exactly 8 digits (e.g. 20240001).',
                'coordEmail.unique'           => 'This email address is already taken.',
                'coordCollegeSelect.required' => 'Please select a college.',
                'coordPhoto.max'              => 'Profile photo must not exceed 5 MB.',
            ]);

            $fullName  = $this->buildFullName($firstName, $mid, $lastName, $suffix);
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
                'first_name'     => $firstName,
                'middle_initial' => $mid ?: null,
                'last_name'      => $lastName,
                'suffix'         => $suffix ?: null,
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
        $this->coordFirstName     = '';
        $this->coordMiddleInitial = '';
        $this->coordLastName      = '';
        $this->coordSuffix        = '';
        $this->coordTeacherId     = '';
        $this->coordEmail         = '';
        $this->coordDept          = '';
        $this->coordCollegeSelect = '';
        $this->coordPhoto         = null;
        $this->coordinatorErrors  = [];
    }

    public function resetCoordFormPublic(): void
    {
        $this->resetCoordForm();
        $this->coordinatorSuccess = '';
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
        $this->orgRenamingCollege     = null;
        $this->orgRenameCollegeName   = '';
    }

    public function addCollege(): void
    {
        $name = trim($this->orgNewCollegeName);
        if (!$name) {
            $this->orgCourseAlert = 'College name is required.';
            $this->orgCourseAlertType = 'error';
            return;
        }
        if (isset($this->orgCoursesList[$name])) {
            $this->orgCourseAlert = "College '{$name}' already exists.";
            $this->orgCourseAlertType = 'error';
            return;
        }
        $this->orgAddingToCollege     = $name;
        $this->orgSelectedCourseCodes = [];
        $this->orgNewCollegeName      = '';
        $this->orgCourseAlert         = '';
        $this->dispatch('org-modal-scroll-top');
    }

    public function startEditingCollege(string $college): void
    {
        $this->orgAddingToCollege     = $college;
        $this->orgSelectedCourseCodes = Course::where('college', $college)->pluck('code')->toArray();
        $this->orgCourseAlert         = '';
        $this->dispatch('org-modal-scroll-top');
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
        $this->dispatch('org-modal-scroll-top');
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

        if (!$new) {
            $this->orgCourseAlert = 'New name is required.';
            $this->orgCourseAlertType = 'error';
            return;
        }
        if ($new === $old) { $this->cancelRenamingCollege(); return; }
        if (isset($this->orgCoursesList[$new])) {
            $this->orgCourseAlert = "College \"{$new}\" already exists.";
            $this->orgCourseAlertType = 'error';
            return;
        }

        try {
            Course::where('college', $old)->update(['college' => $new]);
            Organizer::where('department', $old)->update(['department' => $new]);
            $this->cancelRenamingCollege();
            $this->loadOrgCourses();
            $this->orgCourseAlert     = "College renamed to \"{$new}\".";
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

        if (!$college) {
            $this->orgCourseAlert = 'College name missing.';
            $this->orgCourseAlertType = 'error';
            $this->savingOrgCourse = false;
            return;
        }
        if (empty($this->orgSelectedCourseCodes)) {
            $this->orgCourseAlert = 'Select at least one course.';
            $this->orgCourseAlertType = 'error';
            $this->savingOrgCourse = false;
            return;
        }

        try {
            Course::where('college', $college)
                ->whereNotIn('code', $this->orgSelectedCourseCodes)
                ->update(['college' => null]);
            Course::whereIn('code', $this->orgSelectedCourseCodes)
                ->update(['college' => $college]);
            $count                        = count($this->orgSelectedCourseCodes);
            $this->orgAddingToCollege     = null;
            $this->orgSelectedCourseCodes = [];
            $this->loadOrgCourses();
            $this->orgCourseAlert     = "College '{$college}' saved with {$count} department(s).";
            $this->orgCourseAlertType = 'success';
        } catch (\Exception $e) {
            $this->orgCourseAlert     = 'Failed: ' . $e->getMessage();
            $this->orgCourseAlertType = 'error';
        } finally {
            $this->savingOrgCourse = false;
        }
    }

    // ─────────────────────────────────────────────────────────────
    //  COORDINATOR STATUS TOGGLE
    // ─────────────────────────────────────────────────────────────
    public function confirmToggleCoordinatorStatus(int $id, string $action): void
    {
        try {
            $coordinator               = Organizer::findOrFail($id);
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
            if ($coord->profile_photo && !str_contains($coord->profile_photo, 'default.png')) {
                Storage::disk('public')->delete($coord->profile_photo);
            }
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
        show: false, type: 'success', msg: '', timer: null,
        display(t, m) { this.type = t; this.msg = m; this.show = true; clearTimeout(this.timer); this.timer = setTimeout(() => this.show = false, 8000); }
     }"
     @flash-message.window="display($event.detail.type, $event.detail.message)"
     x-show="show"
     x-transition:enter="transition ease-out duration-250"
     x-transition:enter-start="opacity-0 translate-x-6 scale-95"
     x-transition:enter-end="opacity-100 translate-x-0 scale-100"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0 translate-x-6"
     class="fixed top-4 right-4 sm:right-5 z-[200] flex items-start gap-3 px-4 py-3.5 rounded-xl shadow-xl max-w-[90vw] sm:max-w-sm border"
     :class="{ 'bg-white border-emerald-200': type === 'success', 'bg-white border-blue-200': type === 'info', 'bg-white border-red-200': type === 'error' }"
     style="display:none">
    <i class="fas mt-0.5 text-sm flex-shrink-0"
       :class="{ 'fa-circle-check text-emerald-500': type === 'success', 'fa-circle-info text-blue-500': type === 'info', 'fa-circle-exclamation text-red-500': type === 'error' }"></i>
    <div class="flex-1 min-w-0">
        <p class="font-semibold text-sm text-gray-900" x-text="type === 'success' ? 'Success' : type === 'info' ? 'Info' : 'Error'"></p>
        <p class="text-sm mt-0.5 text-gray-600 leading-snug break-words" x-text="msg"></p>
    </div>
    <button @click="show = false" class="text-gray-400 hover:text-gray-600 transition shrink-0">
        <i class="fas fa-xmark text-sm"></i>
    </button>
</div>

{{-- ══════════════════════ MAIN LAYOUT ══════════════════════ --}}
<div class="flex flex-col px-3 sm:px-5 lg:px-7 pt-5 max-w-screen-2xl mx-auto space-y-5">

    {{-- HEADER --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-[#7a3f91] flex items-center justify-center flex-shrink-0"
                 style="box-shadow:0 4px 14px rgba(122,63,145,.25)">
                <i class="fas fa-users-gear text-white text-sm"></i>
            </div>
            <div>
                <h1 class="text-2xl font-semibold leading-tight" style="color:#333333;">Manage Coordinator</h1>
                <p class="text-sm mt-0.5" style="color:#999999;">Manage coordinator records and college assignments</p>
            </div>
        </div>
        <div class="flex flex-wrap gap-2 shrink-0">
            <button wire:click="openModal('registerCoordinator')"
                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl font-semibold text-sm bg-[#7a3f91] hover:bg-[#5e2f72] text-white transition shadow-md">
                <i class="fas fa-users-gear text-xs"></i><span>Register Coordinator</span>
            </button>
            <button wire:click="openModal('manageOrgCourses')"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl font-semibold text-sm bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 transition">
                <i class="fas fa-building-columns text-sm"></i><span class="hidden sm:inline">Colleges</span>
            </button>
        </div>
    </div>

    {{-- TABLE CARD --}}
    <div class="bg-white rounded-2xl shadow-sm border border-gray-200 flex flex-col overflow-hidden"
         style="height: calc(100vh - 175px); min-height: 500px;">

        {{-- Filters --}}
        <div class="px-3 sm:px-5 py-3 border-b border-gray-200 bg-white flex flex-wrap gap-2 items-center">
            <div class="relative flex-1 min-w-[160px] max-w-xs" wire:ignore
                 x-data="{ q: '', timer: null, init() { this.q = $wire.coordSearch ?? ''; $wire.$watch('coordSearch', val => { if (val !== this.q) this.q = val; }); } }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none"></i>
                <input type="text" x-model="q" @input.debounce.80ms="$wire.set('coordSearch', q)"
                       placeholder="Search name, ID, email..."
                       class="w-full pl-9 pr-3 py-2.5 border border-gray-200 rounded-lg text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                       autocomplete="off" spellcheck="false">
            </div>
            <select wire:model.live="coordCollege"
                    class="px-3 py-2.5 border border-gray-200 rounded-lg text-sm bg-white text-gray-700 min-w-[130px] focus:outline-none focus:border-[#7a3f91] transition">
                <option value="">All Colleges</option>
                @foreach($this->orgColleges as $col)
                    <option value="{{ $col }}">{{ $col }}</option>
                @endforeach
            </select>
            <select wire:model.live="coordSort"
                    class="px-3 py-2.5 border border-gray-200 rounded-lg text-sm bg-white text-gray-700 min-w-[120px] focus:outline-none focus:border-[#7a3f91] transition">
                <option value="recent">Recent First</option>
                <option value="oldest">Oldest First</option>
            </select>
            <button wire:click="resetCoordFilters"
                    class="bg-white border border-gray-200 hover:bg-gray-50 text-gray-600 px-3 py-2.5 rounded-lg text-sm font-semibold flex items-center gap-1.5 transition">
                <i class="fas fa-rotate-left text-sm"></i><span class="hidden sm:inline">Reset</span>
            </button>
        </div>

        {{-- Table --}}
        <div class="relative flex-1 min-h-0" x-data="{ showTop: false }">
            <div id="coord-scroll"
                 @scroll.passive="showTop = $event.target.scrollTop > 200"
                 class="h-full overflow-y-auto overflow-x-auto"
                 style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;"
                 wire:loading.class="opacity-40 pointer-events-none"
                 wire:target="coordSearch,coordCollege,coordSort,resetCoordFilters,executeToggleCoordinatorStatus">
                <table class="w-full border-collapse" style="min-width:650px;">
                    <thead>
                        <tr class="bg-[#f5f0fa] border-b border-[#e2d3ef] sticky top-0 z-10">
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Name</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Teacher ID</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider hidden md:table-cell">Email</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">College</th>
                            <th class="px-4 sm:px-5 py-3.5 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                            <th class="px-4 sm:px-5 py-3.5 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse($this->coordinatorRecords as $item)
                        @php
                            $dept        = $item->department;
                            $directMatch = \App\Models\Course::where('college', $dept)->exists();
                            $collegeName = $directMatch ? $dept : (\App\Models\Course::where('code', $dept)->value('college') ?? $dept);
                            $deptCodes   = \App\Models\Course::where('college', $collegeName)->orderBy('code')->pluck('code')->toArray();
                        @endphp
                        <tr class="bg-white hover:bg-[#faf7fd] transition-colors duration-100">
                            <td class="px-4 sm:px-5 py-4">
                                <div class="flex items-center gap-3">
                                    <img src="{{ $this->getPhotoUrl($item->profile_photo) }}"
                                         alt="{{ $item->first_name }}"
                                         class="w-10 h-10 rounded-xl object-cover shrink-0 shadow-sm ring-1 ring-gray-200">
                                    <span class="font-semibold text-gray-900 text-sm leading-tight">
                                        {{ $this->formatDisplayName($item->first_name ?? '', $item->middle_initial ?? '', $item->last_name ?? '', $item->suffix ?? '') }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 sm:px-5 py-4">
                                <span class="font-mono text-gray-700 text-sm">{{ $item->id_number }}</span>
                            </td>
                            <td class="px-4 sm:px-5 py-4 hidden md:table-cell">
                                <span class="text-gray-600 text-sm">{{ $item->email }}</span>
                            </td>
                            <td class="px-4 sm:px-5 py-4">
                                <span class="block font-semibold text-gray-800 text-sm leading-snug">{{ $collegeName }}</span>
                                @if(count($deptCodes))
                                <div class="flex flex-wrap gap-1 mt-1">
                                    @foreach($deptCodes as $dc)
                                        <span class="text-xs font-mono text-[#7a3f91]">{{ $dc }}</span>
                                        @if(!$loop->last)<span class="text-gray-300 text-xs">·</span>@endif
                                    @endforeach
                                </div>
                                @endif
                            </td>
                            <td class="px-4 sm:px-5 py-4 text-center whitespace-nowrap">
                                @php
                                    $sc = match($item->status) {
                                        'ACTIVE'    => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                                        'INACTIVE'  => 'bg-amber-50 text-amber-700 border-amber-200',
                                        'SUSPENDED' => 'bg-red-50 text-red-700 border-red-200',
                                        default     => 'bg-gray-50 text-gray-600 border-gray-200',
                                    };
                                @endphp
                                <span class="inline-block px-2.5 py-1.5 border rounded-full text-xs font-semibold {{ $sc }}">{{ $item->status }}</span>
                            </td>
                            <td class="px-4 sm:px-5 py-4 text-center">
                                <div class="flex items-center justify-center gap-1.5 flex-wrap">
                                    <button wire:click="viewProfile({{ $item->id }})"
                                            class="inline-flex items-center gap-1 px-3 py-2 rounded-lg text-xs font-semibold bg-[#f5eef9] text-[#7a3f91] border border-[#d4aaeb] hover:bg-[#e9d5f3] transition">
                                        <i class="fas fa-eye text-xs"></i><span>View</span>
                                    </button>
                                    @if($item->status === 'ACTIVE')
                                        <button wire:click="confirmToggleCoordinatorStatus({{ $item->id }}, 'deactivate')"
                                                class="inline-flex items-center gap-1 px-3 py-2 rounded-lg text-xs font-semibold bg-white text-amber-600 border border-amber-200 hover:bg-amber-50 transition">
                                            <i class="fas fa-ban text-xs"></i><span>Deactivate</span>
                                        </button>
                                    @else
                                        <button wire:click="confirmToggleCoordinatorStatus({{ $item->id }}, 'activate')"
                                                class="inline-flex items-center gap-1 px-3 py-2 rounded-lg text-xs font-semibold bg-white text-emerald-600 border border-emerald-200 hover:bg-emerald-50 transition">
                                            <i class="fas fa-circle-check text-xs"></i><span>Activate</span>
                                        </button>
                                    @endif
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
                                    <p class="font-semibold text-gray-500 text-base">No coordinators found</p>
                                    <p class="text-sm text-gray-400">Try adjusting filters or register a new coordinator.</p>
                                </div>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Scroll-to-top button --}}
            <button x-show="showTop"
                    @click="document.getElementById('coord-scroll').scrollTo({ top: 0, behavior: 'smooth' })"
                    class="absolute bottom-4 right-4 z-20 w-9 h-9 rounded-full flex items-center justify-center shadow-lg bg-[#7a3f91] text-white hover:bg-[#5e2f72] transition"
                    style="display:none">
                <i class="fas fa-arrow-up text-sm"></i>
            </button>
        </div>

        {{-- Pagination --}}
        <div class="px-4 sm:px-5 py-3.5 border-t border-gray-200 shrink-0" style="background:#7a3f91;">
            @php
                $total = $this->coordinatorRecords->total();
                $pp    = $this->coordinatorRecords->perPage();
                $cp    = $this->coordinatorRecords->currentPage();
                $from  = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
                $to    = min($cp * $pp, $total);
            @endphp
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                <p class="text-sm" style="color:rgba(255,255,255,.8);">
                    Showing <strong class="text-white">{{ $from }}–{{ $to }}</strong>
                    of <strong class="text-white">{{ $total }}</strong>
                    coordinator{{ $total !== 1 ? 's' : '' }}
                    @if($coordCollege || $coordSearch)
                        <span class="text-xs ml-1" style="color:rgba(255,255,255,.5);">(filtered)</span>
                    @endif
                </p>
                <div class="flex items-center gap-1.5">
                    @if($this->coordinatorRecords->onFirstPage())
                        <button disabled class="px-4 py-2 rounded-lg text-sm font-semibold cursor-not-allowed" style="color:rgba(255,255,255,.3);background:rgba(255,255,255,.08);">← Prev</button>
                    @else
                        <button wire:click="previousPage('coordPage')" class="px-4 py-2 rounded-lg text-sm font-semibold text-white transition hover:opacity-80" style="background:rgba(255,255,255,.2);">← Prev</button>
                    @endif
                    <span class="px-4 py-2 bg-white rounded-lg text-sm font-semibold shadow-sm" style="color:#333333;">
                        {{ $cp }} / {{ $this->coordinatorRecords->lastPage() }}
                    </span>
                    @if($this->coordinatorRecords->hasMorePages())
                        <button wire:click="nextPage('coordPage')" class="px-4 py-2 rounded-lg text-sm font-semibold text-white transition hover:opacity-80" style="background:rgba(255,255,255,.2);">Next →</button>
                    @else
                        <button disabled class="px-4 py-2 rounded-lg text-sm font-semibold cursor-not-allowed" style="color:rgba(255,255,255,.3);background:rgba(255,255,255,.08);">Next →</button>
                    @endif
                </div>
            </div>
        </div>

    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════════ --}}
{{--  OVERLAYS                                                       --}}
{{-- ═══════════════════════════════════════════════════════════════ --}}

{{-- ── REGISTER COORDINATOR — FULL SCREEN ─────── --}}
@if($activeModal === 'registerCoordinator')
<div class="fixed inset-0 z-50 flex flex-col bg-gray-50"
     @keydown.escape.window="$wire.closeModal()">

    {{-- Top bar --}}
    <div class="flex items-center justify-between px-6 lg:px-10 py-4 bg-[#7a3f91] shrink-0 shadow-lg">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center">
                <i class="fas fa-users-gear text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">Register Coordinator</h2>
                <p class="text-white/60 text-xs">Fill in all required fields below</p>
            </div>
        </div>
        <button wire:click="closeModal"
                class="flex items-center gap-2 px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 text-white text-sm font-semibold transition">
            <i class="fas fa-xmark"></i><span class="hidden sm:inline">Close</span>
        </button>
    </div>

    {{-- Scrollable body --}}
    <div class="flex-1 overflow-y-auto" style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-10 py-7 space-y-5">

            {{-- Success banner --}}
            @if($coordinatorSuccess)
            <div class="flex items-start gap-4 p-5 rounded-2xl bg-emerald-50 border border-emerald-200 shadow-sm">
                <div class="w-10 h-10 bg-emerald-100 rounded-xl flex items-center justify-center shrink-0">
                    <i class="fas fa-circle-check text-emerald-600 text-lg"></i>
                </div>
                <div class="flex-1">
                    <p class="font-semibold text-base text-emerald-900">Registration Successful!</p>
                    <p class="text-sm mt-0.5 text-emerald-800">{{ $coordinatorSuccess }}</p>
                </div>
                <button wire:click="closeModal"
                        class="bg-[#7a3f91] text-white px-4 py-2 rounded-xl text-sm font-semibold shrink-0 hover:bg-[#5e2f72] transition">
                    Done
                </button>
            </div>
            @endif

            {{-- Error banner --}}
            @if(count($coordinatorErrors) > 0)
            <div class="p-4 rounded-2xl bg-red-50 border border-red-200 shadow-sm">
                <p class="font-semibold text-sm text-red-900 mb-2 flex items-center gap-2">
                    <i class="fas fa-triangle-exclamation text-red-500"></i>Please fix the following:
                </p>
                <ul class="text-sm space-y-1 text-red-800">
                    @foreach($coordinatorErrors as $ms)
                        @foreach($ms as $m)
                        <li class="flex items-start gap-2"><span class="mt-0.5 shrink-0">•</span>{{ $m }}</li>
                        @endforeach
                    @endforeach
                </ul>
            </div>
            @endif

            <form wire:submit="registerCoordinator" class="space-y-5">

                {{-- Personal Information --}}
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                        <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide flex items-center gap-2">
                            <i class="fas fa-user text-[#7a3f91] text-xs"></i> Personal Information
                        </h3>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">

                            {{-- Photo upload --}}
                            <div class="lg:col-span-1 flex flex-col items-center gap-3">
                                <div class="border-2 border-dashed border-gray-300 rounded-2xl p-5 text-center cursor-pointer hover:border-[#7a3f91] hover:bg-[#f5eef9] transition w-full"
                                     onclick="document.getElementById('coordPhotoInput').click()">
                                    @if($coordPhoto)
                                        <img src="{{ $coordPhoto->temporaryUrl() }}" class="w-20 h-20 rounded-xl mx-auto mb-2 object-cover shadow-md">
                                        <p class="text-xs text-emerald-600 font-semibold"><i class="fas fa-check mr-1"></i>Selected</p>
                                    @else
                                        <i class="fas fa-cloud-arrow-up text-3xl text-gray-300 block mb-2"></i>
                                        <p class="text-sm text-gray-600 font-semibold">Profile Photo</p>
                                        <p class="text-xs text-gray-400 mt-0.5">JPG, PNG, WebP · 5 MB</p>
                                    @endif
                                    <input type="file" id="coordPhotoInput" wire:model="coordPhoto" accept="image/jpeg,image/png,image/webp" class="hidden">
                                </div>
                                <p class="text-xs text-gray-400 text-center">Optional — leave blank for default</p>
                            </div>

                            {{-- Name fields --}}
                            <div class="lg:col-span-3 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                                <div class="sm:col-span-1 xl:col-span-2">
                                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">
                                        First Name <span class="text-red-500">*</span>
                                    </label>
                                    <input wire:model.defer="coordFirstName" type="text" placeholder="e.g. Juan"
                                           class="w-full px-3.5 py-3 border border-gray-300 rounded-xl text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition @error('coordFirstName') border-red-400 @enderror">
                                    @error('coordFirstName')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                                </div>

                                <div class="sm:col-span-1 xl:col-span-2">
                                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">
                                        Last Name <span class="text-red-500">*</span>
                                    </label>
                                    <input wire:model.defer="coordLastName" type="text" placeholder="e.g. dela Cruz"
                                           class="w-full px-3.5 py-3 border border-gray-300 rounded-xl text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition @error('coordLastName') border-red-400 @enderror">
                                    @error('coordLastName')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                                </div>

                                <div class="sm:col-span-1 xl:col-span-2">
                                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Middle Name</label>
                                    <input wire:model.defer="coordMiddleInitial" type="text" placeholder="e.g. Santos" maxlength="50"
                                           class="w-full px-3.5 py-3 border border-gray-300 rounded-xl text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition @error('coordMiddleInitial') border-red-400 @enderror">
                                    <p class="text-xs text-gray-400 mt-1"></p>
                                    @error('coordMiddleInitial')<p class="text-xs text-red-500 mt-0.5">{{ $message }}</p>@enderror
                                </div>

                                <div class="sm:col-span-1 xl:col-span-2">
                                    <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">Suffix</label>
                                    <input wire:model.defer="coordSuffix" type="text" placeholder="e.g. Jr., Sr., III" maxlength="10"
                                           class="w-full px-3.5 py-3 border border-gray-300 rounded-xl text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition @error('coordSuffix') border-red-400 @enderror">
                                    <p class="text-xs text-gray-400 mt-1">Jr., Sr., II, III, IV, MD, PhD…</p>
                                    @error('coordSuffix')<p class="text-xs text-red-500 mt-0.5">{{ $message }}</p>@enderror
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Account Credentials --}}
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                        <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide flex items-center gap-2">
                            <i class="fas fa-id-card text-[#7a3f91] text-xs"></i> Account Credentials
                        </h3>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">
                                    Teacher ID <span class="text-red-500">*</span>
                                </label>
                                <input wire:model.defer="coordTeacherId"
                                       type="text"
                                       placeholder="e.g. 20240001"
                                       maxlength="8"
                                       inputmode="numeric"
                                       pattern="\d{8}"
                                       oninput="this.value=this.value.replace(/\D/g,'')"
                                       class="w-full px-3.5 py-3 border border-gray-300 rounded-xl text-sm bg-white text-gray-900 font-mono focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition @error('coordTeacherId') border-red-400 @enderror">
                                <p class="text-xs text-gray-400 mt-1">Must be exactly 8 digits</p>
                                @error('coordTeacherId')<p class="text-xs text-red-500 mt-0.5">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wide mb-1.5">
                                    Email Address <span class="text-red-500">*</span>
                                </label>
                                <input wire:model.defer="coordEmail" type="email" placeholder="coordinator@example.com"
                                       class="w-full px-3.5 py-3 border border-gray-300 rounded-xl text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition @error('coordEmail') border-red-400 @enderror">
                                <p class="text-xs text-gray-400 mt-1">Login credentials will be sent here</p>
                                @error('coordEmail')<p class="text-xs text-red-500 mt-0.5">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </div>
                </div>

                {{-- College Assignment --}}
                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50/60">
                        <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide flex items-center gap-2">
                            <i class="fas fa-building-columns text-[#7a3f91] text-xs"></i> College Assignment <span class="text-red-500 text-xs">*</span>
                        </h3>
                    </div>
                    <div class="p-6">
                        @if($this->orgDepartmentsGrouped->isEmpty())
                            <div class="p-4 rounded-xl bg-blue-50 border border-blue-200 text-sm flex items-start gap-2">
                                <i class="fas fa-triangle-exclamation text-amber-500 mt-0.5 shrink-0"></i>
                                <span class="text-gray-700">No colleges configured yet. Set up colleges via <strong>Manage Colleges</strong>.</span>
                            </div>
                        @else
                            @php
                                $collegeDeptsMap  = [];
                                $occupiedColleges = $this->occupiedColleges();
                                foreach ($this->orgDepartmentsGrouped as $cN => $depts) {
                                    $collegeDeptsMap[$cN] = $depts->pluck('code')->toArray();
                                }
                            @endphp
                            <div x-data="{
                                    map: {{ Js::from($collegeDeptsMap) }},
                                    get depts() { return $wire.coordCollegeSelect ? (this.map[$wire.coordCollegeSelect] ?? []) : []; }
                                 }"
                                 class="space-y-4">
                                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4 items-start">
                                    <div class="xl:col-span-1">
                                        <select wire:model.live="coordCollegeSelect"
                                                class="w-full px-3.5 py-3 border border-gray-300 rounded-xl text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition @error('coordCollegeSelect') border-red-400 @enderror">
                                            <option value=""> Select College </option>
                                            @foreach($this->orgDepartmentsGrouped->keys() as $cN)
                                                @php $isOccupied = isset($occupiedColleges[$cN]); @endphp
                                                <option value="{{ $cN }}" {{ $isOccupied ? 'disabled' : '' }}>
                                                    {{ $cN }}{{ $isOccupied ? ' — occupied' : '' }}
                                                </option>
                                            @endforeach
                                        </select>
                                        @error('coordCollegeSelect')
                                            <p class="text-xs text-red-500 mt-1 flex items-center gap-1">
                                                <i class="fas fa-circle-exclamation"></i>{{ $message }}
                                            </p>
                                        @enderror
                                    </div>

                                    <div class="xl:col-span-2" x-show="depts.length > 0" x-cloak>
                                        <p class="text-xs text-gray-500 font-semibold mb-2 uppercase tracking-wide">Departments under this college:</p>
                                        <div class="flex flex-wrap gap-1.5">
                                            <template x-for="code in depts" :key="code">
                                                <span class="inline-block px-3 py-1.5 bg-[#f5eef9] text-[#7a3f91] border border-[#d4aaeb] rounded-full text-xs font-semibold font-mono" x-text="code"></span>
                                            </template>
                                        </div>
                                    </div>

                                    <div class="xl:col-span-2" x-show="!$wire.coordCollegeSelect" x-cloak>
                                        <div class="flex items-center gap-2 p-3 bg-gray-50 border border-gray-200 rounded-xl">
                                            <i class="fas fa-hand-pointer text-gray-300 text-base shrink-0"></i>
                                            <p class="text-xs text-gray-400">Select a college to preview its departments.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Action Buttons --}}
                <div class="flex flex-wrap gap-3 pb-2">
                    <button type="button" wire:click="closeModal"
                            class="flex-1 sm:flex-none sm:w-36 px-6 py-3.5 rounded-xl text-sm font-semibold bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 transition">
                        <i class="fas fa-xmark mr-2"></i>Cancel
                    </button>
                    <button type="button" wire:click="resetCoordFormPublic"
                            class="flex-1 sm:flex-none sm:w-36 px-6 py-3.5 rounded-xl text-sm font-semibold bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 transition">
                        <i class="fas fa-rotate-left mr-2"></i>Reset
                    </button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="registerCoordinator"
                            class="flex-1 px-6 py-3.5 rounded-xl text-sm font-semibold bg-[#7a3f91] hover:bg-[#5e2f72] text-white transition flex items-center justify-center gap-2 shadow-md disabled:opacity-50">
                        <span wire:loading wire:target="registerCoordinator">
                            <i class="fas fa-spinner animate-spin"></i> Registering...
                        </span>
                        <span wire:loading.remove wire:target="registerCoordinator">
                            <i class="fas fa-users-gear"></i> Register Coordinator
                        </span>
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>
@endif

{{-- ── VIEW PROFILE ─────────────────────────────── --}}
@if($activeModal === 'viewProfile' && $viewingProfile)
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5 bg-black/50 backdrop-blur-sm overflow-y-auto"
     @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl my-4 sm:my-8">
        <div class="flex items-center justify-between px-6 py-4 bg-[#7a3f91] border-b border-[#5e2f72] rounded-t-2xl sticky top-0 z-10">
            <h2 class="text-white font-semibold text-xl flex items-center gap-2">
                <i class="fas fa-users-gear"></i> Coordinator Profile
            </h2>
            <button wire:click="closeModal" class="text-white/60 hover:text-white text-xl transition">
                <i class="fas fa-xmark"></i>
            </button>
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
                    <p class="text-lg font-semibold text-gray-900 leading-tight">
                        {{ $this->formatDisplayName($viewingProfile['first_name'] ?? '', $viewingProfile['middle_initial'] ?? '', $viewingProfile['last_name'] ?? '', $viewingProfile['suffix'] ?? '') }}
                    </p>
                    <p class="text-gray-600 text-sm mt-0.5">{{ $viewingProfile['email'] }}</p>
                    @php
                        $sc = match($viewingProfile['status'] ?? '') {
                            'ACTIVE'    => 'bg-emerald-50 text-emerald-700 border-emerald-200',
                            'INACTIVE'  => 'bg-amber-50 text-amber-700 border-amber-200',
                            'SUSPENDED' => 'bg-red-50 text-red-700 border-red-200',
                            default     => 'bg-gray-50 text-gray-600 border-gray-200',
                        };
                    @endphp
                    <span class="inline-block mt-2 px-3 py-1.5 border rounded-full text-xs font-semibold {{ $sc }}">{{ $viewingProfile['status'] ?? 'N/A' }}</span>
                </div>
            </div>

            {{-- Personal Information --}}
            <div>
                <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Personal Information</h3>
                <div class="grid grid-cols-2 gap-3">
                    @foreach([
                        ['First Name',  $viewingProfile['first_name']     ?? '—'],
                        ['Last Name',   $viewingProfile['last_name']      ?? '—'],
                        ['Middle Name', $viewingProfile['middle_initial']  ?? '—'],
                        ['Suffix',      $viewingProfile['suffix']          ?: '—'],
                    ] as [$lbl, $val])
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">{{ $lbl }}</p>
                        <p class="text-sm text-gray-800">{{ $val }}</p>
                    </div>
                    @endforeach
                </div>
            </div>

            {{-- Assignment --}}
            <div>
                <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Assignment</h3>
                <div class="grid grid-cols-2 gap-3">
                    <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                        <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">Teacher ID</p>
                        <p class="text-sm text-gray-800 font-mono">{{ $viewingProfile['id_number'] ?? '—' }}</p>
                    </div>
                    <div class="bg-[#f5eef9] border border-[#d4aaeb] rounded-lg p-3">
                        <p class="text-xs font-semibold text-[#7a3f91] uppercase tracking-wide mb-1">College</p>
                        <p class="text-sm text-[#5e2f72]">{{ $this->getCollegeForCourse($viewingProfile['department'] ?? '') }}</p>
                    </div>
                </div>
            </div>

            {{-- Account --}}
            <div>
                <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">Account</h3>
                <div class="bg-gray-50 border border-gray-200 rounded-lg p-3">
                    <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-1">Email Address</p>
                    <p class="text-sm text-gray-800">{{ $viewingProfile['email'] ?? '—' }}</p>
                </div>
            </div>

            {{-- Update Photo --}}
            <div class="border-t border-gray-100 pt-4">
                <p class="block text-sm font-semibold text-gray-700 uppercase tracking-wide mb-2">Update Profile Photo</p>
                <div class="border-2 border-dashed border-gray-300 rounded-lg p-5 text-center cursor-pointer hover:border-[#7a3f91] hover:bg-[#f5eef9] transition"
                     @click="document.getElementById('profilePhotoInput').click()">
                    <i class="fas fa-camera text-2xl text-gray-300 block mb-1.5"></i>
                    <p class="text-gray-600 font-semibold text-sm">{{ $updatingProfilePhoto ? 'Change Photo' : 'Click to Upload New Photo' }}</p>
                    <p class="text-xs text-gray-400 mt-0.5">JPG, PNG, WebP · max 5 MB</p>
                    <input type="file" id="profilePhotoInput" wire:model="updatingProfilePhoto" accept="image/jpeg,image/png,image/webp" class="hidden">
                </div>
                @if($updatingProfilePhoto)
                <button wire:click="updateProfilePhoto" wire:loading.attr="disabled" wire:target="updateProfilePhoto"
                        class="w-full mt-3 bg-[#7a3f91] hover:bg-[#5e2f72] text-white px-5 py-3 rounded-xl text-sm font-semibold transition flex items-center justify-center gap-2">
                    <span wire:loading wire:target="updateProfilePhoto"><i class="fas fa-spinner animate-spin"></i> Saving...</span>
                    <span wire:loading.remove wire:target="updateProfilePhoto"><i class="fas fa-floppy-disk"></i> Save Photo</span>
                </button>
                @endif
            </div>

            <button wire:click="closeModal"
                    class="w-full bg-white border border-gray-300 text-gray-700 px-5 py-3 rounded-xl text-sm font-semibold hover:bg-gray-50 transition">
                Close
            </button>
        </div>
    </div>
</div>
@endif

{{-- ── MANAGE COLLEGES — FULL SCREEN ───────────── --}}
@if($activeModal === 'manageOrgCourses')
<div class="fixed inset-0 z-50 flex flex-col bg-gray-50"
     @keydown.escape.window="$wire.closeModal()">

    {{-- Top bar --}}
    <div class="flex items-center justify-between px-6 lg:px-10 py-4 bg-[#7a3f91] shrink-0 shadow-lg">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-white/20 flex items-center justify-center">
                <i class="fas fa-building-columns text-white text-sm"></i>
            </div>
            <div>
                <h2 class="text-white font-semibold text-lg leading-tight">Manage Colleges &amp; Departments</h2>
                <p class="text-white/60 text-xs">Add, edit, or rename colleges and their assigned departments</p>
            </div>
        </div>
        <button wire:click="closeModal"
                class="flex items-center gap-2 px-4 py-2 rounded-xl bg-white/10 hover:bg-white/20 text-white text-sm font-semibold transition">
            <i class="fas fa-xmark"></i><span class="hidden sm:inline">Close</span>
        </button>
    </div>

    {{-- Scrollable body --}}
    <div id="org-modal-scroll"
         class="flex-1 overflow-y-auto"
         style="scrollbar-width:thin;scrollbar-color:#d1d5db #f9fafb;"
         @org-modal-scroll-top.window="$nextTick(() => $el.scrollTo({ top: 0, behavior: 'smooth' }))">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-10 py-7 space-y-5">

            {{-- Alert --}}
            @if($orgCourseAlert)
            <div class="flex items-start gap-2.5 p-4 rounded-2xl shadow-sm
                 {{ $orgCourseAlertType === 'success' ? 'bg-emerald-50 border border-emerald-200' : 'bg-red-50 border border-red-200' }}">
                <i class="fas mt-0.5 text-base {{ $orgCourseAlertType === 'success' ? 'fa-circle-check text-emerald-500' : 'fa-circle-xmark text-red-500' }}"></i>
                <p class="text-sm font-semibold {{ $orgCourseAlertType === 'success' ? 'text-emerald-900' : 'text-red-900' }}">{{ $orgCourseAlert }}</p>
            </div>
            @endif

            {{-- Rename College Panel --}}
            @if($orgRenamingCollege)
            <div class="bg-white rounded-2xl border-2 border-[#d4aaeb] shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-[#e2d3ef] bg-[#f5eef9]">
                    <h3 class="text-sm font-semibold text-[#7a3f91] uppercase tracking-wide flex items-center gap-2">
                        <i class="fas fa-pen-to-square"></i> Rename College
                    </h3>
                </div>
                <div class="p-6">
                    <p class="text-sm text-gray-600 mb-3">Current: <strong class="text-gray-900">{{ $orgRenamingCollege }}</strong></p>
                    <div class="flex flex-col sm:flex-row gap-3">
                        <input wire:model.defer="orgRenameCollegeName" type="text" placeholder="New college name"
                               class="flex-1 px-3.5 py-3 border border-gray-300 rounded-xl text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                               @keydown.enter.prevent="$wire.renameCollege()">
                        <div class="flex gap-2 shrink-0">
                            <button wire:click="cancelRenamingCollege"
                                    class="px-5 py-3 bg-white border border-gray-300 text-gray-700 rounded-xl text-sm font-semibold hover:bg-gray-50 transition">Cancel</button>
                            <button wire:click="renameCollege" wire:loading.attr="disabled" wire:target="renameCollege"
                                    class="px-5 py-3 bg-[#7a3f91] hover:bg-[#5e2f72] text-white rounded-xl text-sm font-semibold transition flex items-center gap-2 disabled:opacity-50">
                                <span wire:loading wire:target="renameCollege"><i class="fas fa-spinner animate-spin"></i></span>
                                <span wire:loading.remove wire:target="renameCollege"><i class="fas fa-floppy-disk"></i> Save</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            @endif

            {{-- Assign Departments Panel --}}
            @if($orgAddingToCollege)
            <div class="bg-white rounded-2xl border-2 border-[#d4aaeb] shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-[#e2d3ef] bg-[#f5eef9] flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-[#7a3f91] uppercase tracking-wide flex items-center gap-2">
                        <i class="fas fa-{{ isset($orgCoursesList[$orgAddingToCollege]) ? 'pencil' : 'plus' }}"></i>
                        {{ isset($orgCoursesList[$orgAddingToCollege]) ? 'Edit Departments' : 'Assign Departments' }}
                        — <span class="normal-case text-gray-800">{{ $orgAddingToCollege }}</span>
                    </h3>
                    <span class="px-3 py-1.5 bg-[#7a3f91] text-white rounded-full text-xs font-semibold">{{ count($orgSelectedCourseCodes) }} selected</span>
                </div>
                <div class="p-6">
                    @if($this->allCoursesForAssign->count() > 0)
                    <p class="text-sm text-gray-600 mb-3">Select all courses belonging to this college:</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-2 mb-5">
                        @foreach($this->allCoursesForAssign as $c)
                        @php
                            $isSelected   = in_array($c->code, $orgSelectedCourseCodes);
                            $otherCollege = ($c->college && $c->college !== $orgAddingToCollege) ? $c->college : null;
                            $isTaken      = $otherCollege !== null;
                        @endphp
                        <label class="flex items-center gap-3 p-3 border rounded-xl cursor-pointer transition bg-white
                            {{ $isTaken ? 'opacity-50 cursor-not-allowed border-gray-100' : ($isSelected ? 'border-[#7a3f91]/40 bg-[#faf5ff] shadow-sm' : 'border-gray-200 hover:border-gray-300') }}">
                            <input type="checkbox" wire:model="orgSelectedCourseCodes" value="{{ $c->code }}"
                                   class="w-4 h-4 shrink-0 rounded" style="accent-color:#7a3f91;" {{ $isTaken ? 'disabled' : '' }}>
                            <div class="flex-1 min-w-0">
                                <p class="font-semibold text-gray-900 text-sm font-mono leading-tight">{{ $c->code }}</p>
                                <p class="text-gray-500 text-xs truncate">{{ $c->name }}</p>
                                @if($isTaken)
                                <p class="text-xs text-amber-700 mt-0.5"><i class="fas fa-lock mr-1"></i>{{ $otherCollege }}</p>
                                @endif
                            </div>
                            @if($isSelected && !$isTaken)<i class="fas fa-circle-check shrink-0 text-[#7a3f91]"></i>@endif
                        </label>
                        @endforeach
                    </div>
                    @else
                    <div class="text-center py-8">
                        <i class="fas fa-book text-3xl text-gray-200 block mb-2"></i>
                        <p class="text-gray-500 text-sm">No courses available.</p>
                    </div>
                    @endif
                    <div class="flex gap-3">
                        <button wire:click="cancelAddingCourses"
                                class="flex-1 sm:flex-none sm:w-36 bg-white border border-gray-300 text-gray-700 px-4 py-3 rounded-xl text-sm font-semibold hover:bg-gray-50 transition">Cancel</button>
                        <button wire:click="saveCollegeCourses" wire:loading.attr="disabled" wire:target="saveCollegeCourses"
                                class="flex-1 bg-[#7a3f91] hover:bg-[#5e2f72] text-white px-4 py-3 rounded-xl text-sm font-semibold transition flex items-center justify-center gap-2 disabled:opacity-50">
                            <span wire:loading wire:target="saveCollegeCourses"><i class="fas fa-spinner animate-spin"></i> Saving...</span>
                            <span wire:loading.remove wire:target="saveCollegeCourses"><i class="fas fa-floppy-disk"></i> Save Departments</span>
                        </button>
                    </div>
                </div>
            </div>
            @endif

            {{-- Main layout: Add College + Table --}}
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

                {{-- Add New College (left panel) --}}
                @if(!$orgAddingToCollege && !$orgRenamingCollege)
                <div class="lg:col-span-1">
                    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden lg:sticky lg:top-5">
                        <div class="px-5 py-3.5 border-b border-gray-100 bg-gray-50/60">
                            <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide flex items-center gap-2">
                                <i class="fas fa-plus-circle text-[#7a3f91] text-xs"></i> Add New College
                            </h3>
                        </div>
                        <div class="p-5 space-y-3">
                            <input wire:model.defer="orgNewCollegeName" type="text"
                                   placeholder="e.g. College of Computer Studies"
                                   class="w-full px-3.5 py-3 border border-gray-300 rounded-xl text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                                   @keydown.enter.prevent="$wire.addCollege()">
                            <button wire:click="addCollege"
                                    class="w-full bg-[#7a3f91] hover:bg-[#5e2f72] text-white px-4 py-3 rounded-xl text-sm font-semibold transition flex items-center justify-center gap-2">
                                <i class="fas fa-plus text-sm"></i> Add College
                            </button>
                            <p class="text-xs text-gray-400 text-center">After adding, assign departments to it.</p>
                        </div>
                    </div>
                </div>
                @endif

                {{-- College Table --}}
                <div class="{{ (!$orgAddingToCollege && !$orgRenamingCollege) ? 'lg:col-span-2' : 'lg:col-span-3' }}">
                    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                        <div class="px-5 py-3.5 border-b border-gray-100 bg-gray-50/60 flex items-center gap-2">
                            <i class="fas fa-list text-gray-400 text-xs"></i>
                            <h3 class="text-sm font-semibold text-gray-600 uppercase tracking-wide">Colleges &amp; Departments</h3>
                            <span class="ml-auto text-xs font-semibold text-gray-600 bg-gray-100 px-2.5 py-1 rounded-full border border-gray-200">{{ count($orgCoursesList) }}</span>
                        </div>

                        @if(count($orgCoursesList) === 0)
                        <div class="text-center py-16">
                            <i class="fas fa-building-columns text-4xl text-gray-200 block mb-3"></i>
                            <p class="text-gray-400 font-semibold">No colleges yet</p>
                            <p class="text-xs text-gray-400 mt-1">Add one using the panel on the left.</p>
                        </div>
                        @else
                        <div class="overflow-x-auto">
                            <table class="w-full border-collapse" style="min-width:550px;">
                                <thead>
                                    <tr class="bg-[#f5f0fa] border-b border-[#e2d3ef]">
                                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">College</th>
                                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Departments</th>
                                        <th class="px-5 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Coordinator</th>
                                        <th class="px-5 py-3 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    @foreach($orgCoursesList as $college => $departments)
                                    @php $occupied = $this->occupiedColleges(); $coordName = $occupied[$college] ?? null; @endphp
                                    <tr class="bg-white hover:bg-[#faf7fd] transition-colors duration-100">
                                        <td class="px-5 py-4">
                                            <div class="flex items-center gap-3">
                                                <div class="w-8 h-8 rounded-lg bg-[#e9d5f3] flex items-center justify-center shrink-0">
                                                    <i class="fas fa-building-columns text-xs text-[#7a3f91]"></i>
                                                </div>
                                                <span class="font-semibold text-gray-800 text-sm">{{ $college }}</span>
                                            </div>
                                        </td>
                                        <td class="px-5 py-4">
                                            @if(count($departments) > 0)
                                                <div class="flex flex-wrap gap-1">
                                                    @foreach($departments as $dept)
                                                        <span class="inline-block px-2 py-1 bg-[#f5eef9] text-[#7a3f91] border border-[#d4aaeb] rounded-md text-xs font-mono">{{ $dept['code'] }}</span>
                                                    @endforeach
                                                </div>
                                                <p class="text-xs text-gray-400 mt-1">{{ count($departments) }} department{{ count($departments) !== 1 ? 's' : '' }}</p>
                                            @else
                                                <span class="text-xs text-gray-400">No departments</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4">
                                            @if($coordName)
                                                <div class="flex items-center gap-1.5">
                                                    <span class="w-2 h-2 rounded-full bg-emerald-400 shrink-0"></span>
                                                    <span class="text-sm text-gray-700">{{ $coordName }}</span>
                                                </div>
                                            @else
                                                <span class="text-xs text-gray-400 italic">Unassigned</span>
                                            @endif
                                        </td>
                                        <td class="px-5 py-4 text-center">
                                            <div class="flex items-center justify-center gap-1.5">
                                                @if(!$orgAddingToCollege && !$orgRenamingCollege)
                                                <button wire:click="startRenamingCollege('{{ addslashes($college) }}')"
                                                        class="inline-flex items-center gap-1 px-3 py-2 rounded-lg text-xs font-semibold bg-white border border-[#d4aaeb] text-[#7a3f91] hover:bg-[#f5eef9] transition">
                                                    <i class="fas fa-pen-to-square text-xs"></i><span class="hidden sm:inline">Rename</span>
                                                </button>
                                                <button wire:click="startEditingCollege('{{ addslashes($college) }}')"
                                                        class="inline-flex items-center gap-1 px-3 py-2 rounded-lg text-xs font-semibold bg-white border border-[#d4aaeb] text-[#7a3f91] hover:bg-[#f5eef9] transition">
                                                    <i class="fas fa-pencil text-xs"></i><span class="hidden sm:inline">Departments</span>
                                                </button>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        @endif
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
@endif

{{-- ── TOGGLE COORDINATOR STATUS ────────────────── --}}
@if($activeModal === 'toggleCoordinatorConfirm')
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5 bg-black/50 backdrop-blur-sm"
     @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm">
        <div class="p-6">
            <div class="flex items-center gap-4 mb-4">
                <div class="w-12 h-12 rounded-full flex items-center justify-center shrink-0
                     {{ $pendingToggleAction === 'deactivate' ? 'bg-amber-100' : 'bg-emerald-100' }}">
                    <i class="{{ $pendingToggleAction === 'deactivate' ? 'fas fa-ban text-amber-600 text-lg' : 'fas fa-circle-check text-emerald-600 text-lg' }}"></i>
                </div>
                <div>
                    <p class="font-semibold text-gray-900 text-lg">
                        {{ $pendingToggleAction === 'deactivate' ? 'Deactivate Coordinator?' : 'Activate Coordinator?' }}
                    </p>
                    <p class="text-sm text-gray-500 mt-0.5">{{ $pendingToggleName }}</p>
                </div>
            </div>
            <p class="text-sm text-gray-600 mb-4">
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
            @endif
            <div class="flex gap-3">
                <button wire:click="closeModal"
                        class="flex-1 bg-white border border-gray-300 text-gray-700 px-4 py-3 rounded-xl text-sm font-semibold hover:bg-gray-50 transition">Cancel</button>
                <button wire:click="executeToggleCoordinatorStatus"
                        wire:loading.attr="disabled" wire:target="executeToggleCoordinatorStatus"
                        class="flex-1 px-4 py-3 rounded-xl text-sm font-semibold transition flex items-center justify-center gap-2 disabled:opacity-50
                            {{ $pendingToggleAction === 'deactivate' ? 'bg-amber-500 hover:bg-amber-600 text-white' : 'bg-emerald-600 hover:bg-emerald-700 text-white' }}">
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

</div>