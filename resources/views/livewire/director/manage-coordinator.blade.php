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

    public string $activeModal = '';
    public string $coordSearch  = '';
    public string $coordCollege = '';
    public string $coordStatus  = '';
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
    public array   $orgCoursesList         = [];
    public string  $orgNewCollegeName      = '';
    public ?string $orgAddingToCollege     = null;
    public array   $orgSelectedCourseCodes = [];
    public bool    $savingOrgCourse        = false;
    public string  $orgCourseAlert         = '';
    public string  $orgCourseAlertType     = '';
    public ?string $orgRenamingCollege     = null;
    public string  $orgRenameCollegeName   = '';
    public ?int   $pendingToggleId     = null;
    public string $pendingToggleAction = '';
    public string $pendingToggleName   = '';
    public ?int   $viewingProfileId     = null;
    public        $viewingProfile       = null;
    public bool   $editingProfileEmail  = false;
    public string $profileEmailInput    = '';
    public bool   $savingProfileEmail   = false;

    protected array $validSuffixes = [
        'Jr', 'Jr.', 'Sr', 'Sr.',
        'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII',
        'PhD', 'MD', 'DDS', 'DMD', 'DO', 'JD', 'LLB', 'LLM',
        'Esq', 'Esq.', 'RN', 'CPA', 'MBA', 'MSc', 'BSc',
    ];

    protected string $paginationTheme = 'tailwind';

    /**
     * The dashboard's "Active Coordinators" card stores 'ACTIVE' in the
     * session (director_coord_status) instead of putting it in the URL,
     * so the URL here always stays clean: /director/coordinator/management
     *
     * session()->pull() reads the value AND removes it in one step, so a
     * plain refresh afterwards goes back to showing all statuses — only
     * navigating from the dashboard card pre-applies the Active filter.
     */
    public function mount(): void
    {
        if (session()->has('director_coord_status')) {
            $this->coordStatus = session()->pull('director_coord_status');
        }

        $this->loadOrgCourses();
        if (session()->has('success'))
            $this->dispatch('showFlash', type: 'success', message: session()->pull('success'));
        if (session()->has('error'))
            $this->dispatch('showFlash', type: 'error', message: session()->pull('error'));
    }

    #[On('showFlash')]
    public function handleShowFlash(string $type, string $message): void { $this->flash($type, $message); }

    private function flash(string $type, string $msg): void { $this->dispatch('flash-message', type: $type, message: $msg); }

    public function updatingCoordSearch()  { $this->resetPage('coordPage'); }
    public function updatingCoordCollege() { $this->resetPage('coordPage'); }
    public function updatingCoordStatus()  { $this->resetPage('coordPage'); }

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
        if ($this->coordStatus)  $q->where('status', $this->coordStatus);
        $q->orderByDesc('created_at');
        return $q->paginate(10, ['*'], 'coordPage');
    }

    #[Computed]
    public function orgColleges()
    {
        return Course::whereNotNull('college')->where('college', '!=', '')->distinct()->orderBy('college')->pluck('college');
    }

    #[Computed]
    public function orgDepartmentsGrouped()
    {
        return Course::whereNotNull('college')->where('college', '!=', '')->orderBy('college')->orderBy('code')->get()->groupBy('college');
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
        Organizer::withoutTrashed()->where('status', 'ACTIVE')->select('department', 'first_name', 'middle_initial', 'last_name', 'suffix')->get()
            ->each(function ($org) use (&$result) {
                $collegeName = Course::where('college', $org->department)->exists()
                    ? $org->department
                    : (Course::where('code', $org->department)->value('college') ?? $org->department);
                if ($collegeName && !isset($result[$collegeName])) $result[$collegeName] = $org->getFullName();
            });
        return $result;
    }

    public function getCollegeForCourse(string $code): string
    {
        return Course::where('college', $code)->exists()
            ? $code
            : (Course::where('code', $code)->value('college') ?? $code);
    }

    public function getPhotoUrl(?string $path): string
    {
        if (!$path || str_contains($path, 'default.png')) return asset('storage/alumni-photos/default.png');
        if (str_starts_with($path, 'alumni-photos/') || str_starts_with($path, 'organizers/')) {
            return Storage::disk('public')->exists($path) ? asset('storage/' . $path) : asset('storage/alumni-photos/default.png');
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

    private function validateName(string $n): bool { return (bool) preg_match('/^[a-zA-Z\s\-\.\']+$/', $n); }

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
        if ($exceptId) $q->where('id', '!=', $exceptId);
        return $q->exists();
    }

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
        $this->editingProfileEmail  = false;
        $this->profileEmailInput    = '';
        $this->coordinatorSuccess   = '';
    }

    public function resetCoordFilters(): void
    {
        $this->coordSearch = $this->coordCollege = '';
        $this->coordStatus = '';
        $this->resetPage('coordPage');
    }

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

            if (!$this->validateName($firstName)) throw new \Exception('First name may only contain letters, spaces, hyphens, or apostrophes.');
            if (!$this->validateName($lastName))  throw new \Exception('Last name may only contain letters, spaces, hyphens, or apostrophes.');

            if ($mid !== '') {
                if (!preg_match('/^[a-zA-Z]+$/', $mid)) throw new \Exception('Middle name must contain letters only.');
                if (strlen($mid) < 2) throw new \Exception('Middle name must be a full word (e.g. Santos, not S).');
            }

            if ($suffix !== '' && !in_array($suffix, $this->validSuffixes, true)) {
                $examples = implode(', ', array_slice($this->validSuffixes, 0, 8));
                throw new \Exception("Invalid suffix \"{$suffix}\". Accepted values: {$examples}, etc.");
            }

            if ($this->coordinatorFullNameExists($firstName, $mid, $lastName, $suffix))
                throw new \Exception('A coordinator with that full name already exists.');

            if (!$college) throw new \Exception('Please select a college.');

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

            $this->dispatch('dir-coordinator-updated',
                id: $coordinator->id,
                name: $fullName,
                action: 'created'
            );

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
        $this->coordFirstName = $this->coordMiddleInitial = $this->coordLastName = '';
        $this->coordSuffix = $this->coordTeacherId = $this->coordEmail = '';
        $this->coordDept = $this->coordCollegeSelect = '';
        $this->coordPhoto = null;
        $this->coordinatorErrors = [];
    }

    public function resetCoordFormPublic(): void { $this->resetCoordForm(); $this->coordinatorSuccess = ''; }

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
        $this->orgNewCollegeName = $this->orgCourseAlert = $this->orgCourseAlertType = '';
        $this->orgRenameCollegeName = '';
        $this->orgAddingToCollege = $this->orgRenamingCollege = null;
        $this->orgSelectedCourseCodes = [];
        $this->savingOrgCourse = false;
    }

    public function addCollege(): void
    {
        $name = trim($this->orgNewCollegeName);
        if (!$name) { $this->orgCourseAlert = 'College name is required.'; $this->orgCourseAlertType = 'error'; return; }
        if (isset($this->orgCoursesList[$name])) { $this->orgCourseAlert = "College '{$name}' already exists."; $this->orgCourseAlertType = 'error'; return; }
        $this->orgAddingToCollege = $name;
        $this->orgSelectedCourseCodes = [];
        $this->orgNewCollegeName = '';
        $this->orgCourseAlert = '';
        $this->dispatch('org-modal-scroll-top');
    }

    public function startEditingCollege(string $college): void
    {
        $this->orgAddingToCollege = $college;
        $this->orgSelectedCourseCodes = Course::where('college', $college)->pluck('code')->toArray();
        $this->orgCourseAlert = '';
        $this->dispatch('org-modal-scroll-top');
    }

    public function cancelAddingCourses(): void
    {
        $this->orgAddingToCollege = null;
        $this->orgSelectedCourseCodes = [];
        $this->orgNewCollegeName = '';
        $this->orgCourseAlert = '';
    }

    public function startRenamingCollege(string $college): void
    {
        $this->orgRenamingCollege = $college;
        $this->orgRenameCollegeName = $college;
        $this->orgCourseAlert = '';
        $this->dispatch('org-modal-scroll-top');
    }

    public function cancelRenamingCollege(): void { $this->orgRenamingCollege = null; $this->orgRenameCollegeName = ''; }

    public function renameCollege(): void
    {
        $old = trim($this->orgRenamingCollege ?? '');
        $new = trim($this->orgRenameCollegeName);
        if (!$new) { $this->orgCourseAlert = 'New name is required.'; $this->orgCourseAlertType = 'error'; return; }
        if ($new === $old) { $this->cancelRenamingCollege(); return; }
        if (isset($this->orgCoursesList[$new])) { $this->orgCourseAlert = "College \"{$new}\" already exists."; $this->orgCourseAlertType = 'error'; return; }
        try {
            Course::where('college', $old)->update(['college' => $new]);
            Organizer::where('department', $old)->update(['department' => $new]);
            $this->cancelRenamingCollege();
            $this->loadOrgCourses();
            $this->orgCourseAlert = "College renamed to \"{$new}\".";
            $this->orgCourseAlertType = 'success';
        } catch (\Exception $e) {
            $this->orgCourseAlert = 'Failed: ' . $e->getMessage();
            $this->orgCourseAlertType = 'error';
        }
    }

    public function saveCollegeCourses(): void
    {
        $this->savingOrgCourse = true;
        $college = trim($this->orgAddingToCollege ?? '');
        if (!$college) { $this->orgCourseAlert = 'College name missing.'; $this->orgCourseAlertType = 'error'; $this->savingOrgCourse = false; return; }
        if (empty($this->orgSelectedCourseCodes)) { $this->orgCourseAlert = 'Select at least one course.'; $this->orgCourseAlertType = 'error'; $this->savingOrgCourse = false; return; }
        try {
            Course::where('college', $college)->whereNotIn('code', $this->orgSelectedCourseCodes)->update(['college' => null]);
            Course::whereIn('code', $this->orgSelectedCourseCodes)->update(['college' => $college]);
            $count = count($this->orgSelectedCourseCodes);
            $this->orgAddingToCollege = null;
            $this->orgSelectedCourseCodes = [];
            $this->loadOrgCourses();
            $this->orgCourseAlert = "College '{$college}' saved with {$count} department(s).";
            $this->orgCourseAlertType = 'success';
        } catch (\Exception $e) {
            $this->orgCourseAlert = 'Failed: ' . $e->getMessage();
            $this->orgCourseAlertType = 'error';
        } finally {
            $this->savingOrgCourse = false;
        }
    }

    public function confirmToggleCoordinatorStatus(int $id, string $action): void
    {
        try {
            $coordinator = Organizer::findOrFail($id);
            $this->pendingToggleId = $id;
            $this->pendingToggleAction = $action;
            $this->pendingToggleName = $coordinator->getFullName();
            $this->activeModal = 'toggleCoordinatorConfirm';
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
                $conflict = Organizer::withoutTrashed()->where('status', 'ACTIVE')->where('department', $coordinator->department)->where('id', '!=', $coordinator->id)->first();
                if ($conflict) {
                    $this->flash('error', "Cannot activate: college \"{$collegeName}\" already has an active coordinator ({$conflict->getFullName()}). Deactivate them first.");
                    $this->activeModal = '';
                    $this->pendingToggleId = null;
                    $this->pendingToggleAction = '';
                    $this->pendingToggleName = '';
                    return;
                }
            }
            $coordinator->update(['status' => $newStatus]);
            $verb = $newStatus === 'ACTIVE' ? 'activated' : 'deactivated';
            $this->flash('success', "{$coordinator->getFullName()} has been {$verb}.");

            $this->dispatch('dir-coordinator-updated',
                id: $coordinator->id,
                name: $coordinator->getFullName(),
                action: $verb
            );

        } catch (\Exception $e) {
            $this->flash('error', 'Could not update status: ' . $e->getMessage());
        } finally {
            $this->pendingToggleId = null;
            $this->pendingToggleAction = '';
            $this->pendingToggleName = '';
            $this->activeModal = '';
        }
    }

    public function viewProfile(int $id): void
    {
        try {
            $this->viewingProfile     = Organizer::findOrFail($id)->toArray();
            $this->viewingProfileId   = $id;
            $this->editingProfileEmail = false;
            $this->profileEmailInput   = '';
            $this->activeModal        = 'viewProfile';
        } catch (\Exception) {
            $this->flash('error', 'Failed to load profile.');
        }
    }

    public function startEditingProfileEmail(): void
    {
        if (!$this->viewingProfileId) return;
        $this->profileEmailInput   = $this->viewingProfile['email'] ?? '';
        $this->editingProfileEmail = true;
        $this->resetErrorBag('profileEmailInput');
    }

    public function cancelEditingProfileEmail(): void
    {
        $this->editingProfileEmail = false;
        $this->profileEmailInput   = '';
        $this->resetErrorBag('profileEmailInput');
    }

    public function updateProfileEmail(): void
    {
        if (!$this->viewingProfileId) return;
        $this->savingProfileEmail = true;

        try {
            $newEmail = trim($this->profileEmailInput);

            $this->validate([
                'profileEmailInput' => [
                    'required',
                    'email',
                    'max:255',
                    'unique:organizer,email,' . $this->viewingProfileId,
                ],
            ], [
                'profileEmailInput.required' => 'Email address is required.',
                'profileEmailInput.email'    => 'Please enter a valid email address.',
                'profileEmailInput.unique'   => 'This email address is already taken.',
            ]);

            $coordinator = Organizer::findOrFail($this->viewingProfileId);

            if (User::where('email', $newEmail)->where('id', '!=', $coordinator->user_id)->exists()) {
                throw new \Exception('This email address is already taken.');
            }

            $coordinator->update(['email' => $newEmail]);

            if ($coordinator->user_id) {
                User::where('id', $coordinator->user_id)->update(['email' => $newEmail]);
            }

            $this->viewingProfile['email'] = $newEmail;
            $this->editingProfileEmail     = false;
            $this->profileEmailInput       = '';

            $this->flash('success', 'Email address updated successfully.');

            $this->dispatch('dir-coordinator-updated',
                id: $coordinator->id,
                name: $coordinator->getFullName(),
                action: 'email_updated'
            );

        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->flash('error', $e->getMessage());
        } finally {
            $this->savingProfileEmail = false;
        }
    }
};
?>

<div class="flex flex-col" style="min-height: calc(100vh - 120px);">

{{-- ══ MOUSE-FOLLOWING CURSOR LABEL ══ --}}
<div id="coord-cursor-label"
     class="fixed z-[99999] pointer-events-none flex items-center gap-1.5 bg-gray-900 text-white text-[11px] font-bold uppercase tracking-wider px-3 py-1.5 rounded-lg shadow-xl whitespace-nowrap select-none opacity-0 invisible transition-[opacity,visibility] duration-100"
     style="left:-999px;top:-999px;transform:translateY(-100%);">
    <svg class="w-2.5 h-2.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 16 16">
        <path d="M1 8s3-5 7-5 7 5 7 5-3 5-7 5-7-5-7-5z"/><circle cx="8" cy="8" r="2.5"/>
    </svg>
    View Profile
    <span class="absolute top-full left-1/2 -translate-x-1/2 border-[6px] border-transparent border-t-gray-900"></span>
</div>

{{-- ══ FLASH TOAST ══ --}}
<div x-data="{
        show: false, type: 'success', msg: '', timer: null,
        display(t, m) { this.type = t; this.msg = m; this.show = true; clearTimeout(this.timer); this.timer = setTimeout(() => this.show = false, 8000); }
     }"
     @flash-message.window="display($event.detail.type, $event.detail.message)"
     x-show="show"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 translate-x-8 scale-95"
     x-transition:enter-end="opacity-100 translate-x-0 scale-100"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0 translate-x-8"
     class="fixed top-5 right-4 sm:right-6 z-[200] flex items-start gap-3 px-5 py-4 rounded-2xl shadow-2xl max-w-xs sm:max-w-sm border w-full bg-white"
     :class="{ 'border-emerald-300': type==='success', 'border-blue-300': type==='info', 'border-red-300': type==='error' }"
     style="display:none">
    <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
         :class="{ 'bg-emerald-100': type==='success', 'bg-blue-100': type==='info', 'bg-red-100': type==='error' }">
        <i class="fas text-sm" :class="{ 'fa-check text-emerald-600': type==='success', 'fa-info text-blue-600': type==='info', 'fa-exclamation text-red-600': type==='error' }"></i>
    </div>
    <div class="flex-1 min-w-0">
        <p class="font-semibold text-sm text-gray-900" x-text="type==='success'?'Success':type==='info'?'Info':'Error'"></p>
        <p class="text-sm mt-0.5 opacity-80 leading-snug break-words text-gray-600" x-text="msg"></p>
    </div>
    <button @click="show=false" class="opacity-40 hover:opacity-80 transition shrink-0">
        <i class="fas fa-xmark text-sm text-gray-600"></i>
    </button>
</div>

{{-- ══ MAIN LAYOUT ══ --}}
<div class="flex flex-col flex-1 gap-4 px-5 sm:px-7 lg:px-10 pt-6 pb-6 max-w-screen-2xl mx-auto w-full min-h-0">

    {{-- PAGE HEADER --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 flex-shrink-0">
        <div class="flex items-center gap-4">
            <div class="w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md bg-gradient-to-br from-[#7a3f91] to-[#5e2f72]">
                <i class="fas fa-users-gear text-white text-lg"></i>
            </div>
            <div>
                <h1 class="text-xl font-semibold tracking-tight text-gray-900">Manage Coordinator</h1>
                <p class="text-sm leading-relaxed mt-0.5 text-gray-700">
                    Manage coordinator records and
                    <span class="font-semibold inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs bg-violet-50 text-violet-700 border border-violet-200">college assignments</span>
                </p>
            </div>
        </div>

        <div class="flex items-center gap-2 shrink-0">
            <div class="relative group">
                <button wire:click="openModal('registerCoordinator')"
                        class="inline-flex items-center justify-center w-[38px] h-[38px] rounded-xl bg-[#7a3f91] hover:bg-[#5e2f72] shadow-md hover:shadow-lg transition-all duration-150 active:scale-95"
                        aria-label="Register Coordinator">
                    <i class="fas fa-user-plus text-white text-sm"></i>
                </button>
                <div class="absolute bottom-full left-1/2 -translate-x-1/2 mb-2 pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity duration-150 z-[100]">
                    <div class="bg-gray-900 text-white text-[10px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-md whitespace-nowrap relative shadow-lg">
                        <span class="absolute top-full left-1/2 -translate-x-1/2 border-4 border-transparent border-t-gray-900"></span>
                        Register Coordinator
                    </div>
                </div>
            </div>

            <div class="relative group">
                <button wire:click="openModal('manageOrgCourses')"
                        class="inline-flex items-center justify-center w-[38px] h-[38px] rounded-xl bg-white border border-gray-200 hover:bg-gray-50 hover:border-gray-300 shadow-sm hover:shadow-md transition-all duration-150 active:scale-95"
                        aria-label="Manage Colleges">
                    <i class="fas fa-building-columns text-gray-700 text-sm"></i>
                </button>
                <div class="absolute top-full left-1/2 -translate-x-1/2 mt-2 pointer-events-none opacity-0 group-hover:opacity-100 transition-opacity duration-150 z-50">
                    <div class="bg-gray-900 text-white text-[10px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-md whitespace-nowrap relative">
                        <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-gray-900"></span>
                        Manage Colleges
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ══ CONTENT BLOCK ══ --}}
    <div class="flex-1 min-h-0 flex flex-col rounded-xl overflow-hidden border border-[#E8E0F0] shadow-sm">

        {{-- FILTER BAR --}}
        <div class="bg-gray-100 border-b border-[#E8E0F0] px-3.5 py-2.5 flex flex-wrap gap-2 items-center flex-shrink-0">
            <span class="text-xs font-bold uppercase tracking-widest text-[#7a3f91] select-none px-1">Filters</span>

            <div class="relative flex-1 min-w-[160px] max-w-xs"
                 wire:ignore
                 x-data="{ q: '', init() { this.q = $wire.coordSearch ?? ''; $wire.$watch('coordSearch', val => { if (val !== this.q) this.q = val; }); } }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs text-gray-500 pointer-events-none"></i>
                <input type="text" x-model="q" @input.debounce.80ms="$wire.set('coordSearch', q)"
                       placeholder="Search name, ID, email..."
                       class="w-full pl-8 pr-3 py-[7px] text-[13px] font-medium text-gray-900 bg-white border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-gray-300 transition"
                       autocomplete="off" spellcheck="false">
            </div>

            <select wire:model.live="coordCollege"
                    class="py-[7px] px-3 pr-8 text-[13px] font-medium text-gray-900 bg-white border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-gray-300 transition cursor-pointer appearance-none bg-no-repeat"
                    style="background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E\");background-position:right 0.5rem center;background-size:1.1em;">
                <option value="">All Colleges</option>
                @foreach($this->orgColleges as $col)
                    <option value="{{ $col }}">{{ $col }}</option>
                @endforeach
            </select>

            <select wire:model.live="coordStatus"
                    class="py-[7px] px-3 pr-8 text-[13px] font-medium text-gray-900 bg-white border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-gray-300 transition cursor-pointer appearance-none bg-no-repeat"
                    style="background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E\");background-position:right 0.5rem center;background-size:1.1em;">
                <option value="">All Status</option>
                <option value="ACTIVE">Active</option>
                <option value="INACTIVE">Inactive</option>
            </select>

            <button wire:click="resetCoordFilters"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-wait"
                    wire:target="resetCoordFilters"
                    class="inline-flex items-center gap-1.5 px-3 py-[7px] rounded-lg text-xs font-semibold bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 transition active:scale-95 cursor-pointer">
                <span wire:loading.remove wire:target="resetCoordFilters"><i class="fas fa-rotate-left text-xs"></i></span>
                <span wire:loading wire:target="resetCoordFilters">
                    <svg class="animate-spin w-3.5 h-3.5 text-gray-700" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>
                </span>
                <span class="hidden sm:inline">Reset</span>
            </button>
        </div>

        {{-- TABLE BODY --}}
        <div class="bg-gray-100 flex-1 flex flex-col relative" x-data="{ showTop: false }">

            <div id="coord-scroll"
                 class="flex-1"
                 wire:loading.class="opacity-40 pointer-events-none"
                 wire:target="coordSearch,coordCollege,coordStatus,resetCoordFilters,executeToggleCoordinatorStatus">

                @if($this->coordinatorRecords->count() > 0)
                <table class="w-full border-collapse bg-white">
                    <thead>
                        <tr class="bg-[#f5f0fa] border-b border-[#e2d3ef] sticky top-0 z-10">
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Name</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Teacher ID</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider hidden md:table-cell">Email</th>
                            <th class="px-4 sm:px-5 py-3.5 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">College</th>
                            <th class="px-4 sm:px-5 py-3.5 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                            <th class="px-4 sm:px-5 py-3.5 text-center text-xs font-semibold text-gray-600 uppercase tracking-wider">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($this->coordinatorRecords as $item)
                        @php
                            $dept        = $item->department;
                            $directMatch = \App\Models\Course::where('college', $dept)->exists();
                            $collegeName = $directMatch ? $dept : (\App\Models\Course::where('code', $dept)->value('college') ?? $dept);
                            $deptCodes   = \App\Models\Course::where('college', $collegeName)->orderBy('code')->pluck('code')->toArray();
                        @endphp
                        <tr class="bg-white hover:bg-[#faf7fd] transition-colors duration-100 cursor-pointer"
                            data-coord-row
                            wire:click="viewProfile({{ $item->id }})"
                            role="button"
                            tabindex="0"
                            onkeypress="if(event.key==='Enter')this.click()">
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
                                <div class="flex items-center justify-center" data-coord-actions>
                                    @if($item->status === 'ACTIVE')
                                        <div class="relative group/btn">
                                            <button type="button"
                                                    data-coord-action
                                                    wire:click.stop="confirmToggleCoordinatorStatus({{ $item->id }}, 'deactivate')"
                                                    class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-amber-50 border border-amber-300 text-amber-600 hover:bg-amber-100 hover:border-amber-400 transition-all duration-150 active:scale-95"
                                                    aria-label="Deactivate">
                                                <i class="fas fa-ban text-xs"></i>
                                            </button>
                                            <div class="absolute top-full left-1/2 -translate-x-1/2 mt-1.5 pointer-events-none opacity-0 group-hover/btn:opacity-100 transition-opacity duration-150 z-50">
                                                <div class="bg-gray-900 text-white text-[10px] font-bold uppercase tracking-wider px-2 py-1 rounded whitespace-nowrap relative">
                                                    <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-gray-900"></span>
                                                    Deactivate
                                                </div>
                                            </div>
                                        </div>
                                    @else
                                        <div class="relative group/btn">
                                            <button type="button"
                                                    data-coord-action
                                                    wire:click.stop="confirmToggleCoordinatorStatus({{ $item->id }}, 'activate')"
                                                    class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-emerald-50 border border-emerald-300 text-emerald-600 hover:bg-emerald-100 hover:border-emerald-400 transition-all duration-150 active:scale-95"
                                                    aria-label="Activate">
                                                <i class="fas fa-circle-check text-xs"></i>
                                            </button>
                                            <div class="absolute top-full left-1/2 -translate-x-1/2 mt-1.5 pointer-events-none opacity-0 group-hover/btn:opacity-100 transition-opacity duration-150 z-50">
                                                <div class="bg-gray-900 text-white text-[10px] font-bold uppercase tracking-wider px-2 py-1 rounded whitespace-nowrap relative">
                                                    <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-gray-900"></span>
                                                    Activate
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>

                @else
                <div class="flex flex-col items-center justify-center gap-4 text-center px-6 py-20 bg-white">
                    <div class="w-14 h-14 rounded-2xl flex items-center justify-center bg-[#f5eef9]">
                        <i class="fas fa-users-gear text-xl text-[#c49dd8]"></i>
                    </div>
                    <div>
                        <p class="font-semibold text-base text-gray-700">No coordinators found</p>
                        <p class="text-sm mt-1 text-gray-500">
                            @if($coordCollege || $coordSearch || $coordStatus) Try adjusting your filters or clearing them.
                            @else Register a new coordinator to get started. @endif
                        </p>
                    </div>
                    @if($coordCollege || $coordSearch || $coordStatus)
                    <button wire:click="resetCoordFilters"
                            class="px-4 py-2 rounded-xl text-sm font-semibold text-white transition uppercase tracking-widest cursor-pointer bg-[#7a3f91] hover:bg-[#5e2f72]">
                        Clear Filters
                    </button>
                    @endif
                </div>
                @endif

            </div>
        </div>

        {{-- PAGINATION BAR --}}
        @php
            $total   = $this->coordinatorRecords->total();
            $pp      = $this->coordinatorRecords->perPage();
            $cp      = $this->coordinatorRecords->currentPage();
            $lp      = $this->coordinatorRecords->lastPage();
            $from    = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
            $to      = min($cp * $pp, $total);
            $pgStart = max(1, $cp - 2);
            $pgEnd   = min($lp, $cp + 2);
        @endphp
        <div class="flex items-center justify-between gap-2 flex-wrap px-5 min-h-[48px] bg-gradient-to-r from-[#7a3f91] to-[#9b59b6] border-t border-[#7a3f91]/30 flex-shrink-0">
            <p class="text-white/80 text-xs font-normal whitespace-nowrap">
                Showing <strong class="text-white font-bold">{{ $from }}–{{ $to }}</strong>
                of <strong class="text-white font-bold">{{ $total }}</strong>
                coordinator{{ $total !== 1 ? 's' : '' }}
                @if($coordCollege || $coordSearch || $coordStatus)<span class="text-white/50 text-xs ml-1">(filtered)</span>@endif
            </p>
            <div class="flex items-center gap-1 flex-wrap">
                <button wire:click="previousPage('coordPage')"
                        class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold bg-white/15 border border-white/25 text-white hover:bg-white/28 hover:border-white/50 disabled:opacity-35 disabled:cursor-not-allowed transition"
                        @if($this->coordinatorRecords->onFirstPage()) disabled @endif aria-label="Previous">
                    <i class="fas fa-chevron-left text-[9px]"></i>
                </button>
                @if($pgStart > 1)
                    <button wire:click="$set('page', 1)" class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">1</button>
                    @if($pgStart > 2)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
                @endif
                @for($p = $pgStart; $p <= $pgEnd; $p++)
                    @if($p === $cp)
                        <span class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold bg-white text-[#7a3f91] border border-white">{{ $p }}</span>
                    @else
                        <button wire:click="gotoPage({{ $p }}, 'coordPage')" class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">{{ $p }}</button>
                    @endif
                @endfor
                @if($pgEnd < $lp)
                    @if($pgEnd < $lp - 1)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
                    <button wire:click="gotoPage({{ $lp }}, 'coordPage')" class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold bg-white/15 border border-white/25 text-white hover:bg-white/28 transition">{{ $lp }}</button>
                @endif
                <button wire:click="nextPage('coordPage')"
                        class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold bg-white/15 border border-white/25 text-white hover:bg-white/28 hover:border-white/50 disabled:opacity-35 disabled:cursor-not-allowed transition"
                        @if(!$this->coordinatorRecords->hasMorePages()) disabled @endif aria-label="Next">
                    <i class="fas fa-chevron-right text-[9px]"></i>
                </button>
                <span class="hidden sm:inline text-white/60 text-xs font-normal whitespace-nowrap ml-1">Page {{ $cp }}/{{ $lp }}</span>
            </div>
        </div>

    </div>{{-- end content-block --}}
</div>


{{-- ═══════════════════════════════════════════════════════════════ --}}
{{--  OVERLAYS                                                       --}}
{{-- ═══════════════════════════════════════════════════════════════ --}}

{{-- ── REGISTER COORDINATOR — FULL SCREEN ─────── --}}
@if($activeModal === 'registerCoordinator')
<div class="fixed inset-0 z-50 flex flex-col bg-gray-50"
     @keydown.escape.window="$wire.closeModal()">

    <div class="flex items-center justify-between px-6 lg:px-10 h-[52px] bg-gradient-to-r from-[#7a3f91] to-[#9b59b6] shrink-0 shadow-lg gap-4">
        <div class="flex items-center gap-3 flex-1 min-w-0">
            <div class="w-7 h-7 rounded-lg bg-white/20 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-user-plus text-white text-xs"></i>
            </div>
            <span class="text-white font-semibold text-sm truncate">Register Coordinator</span>
        </div>
        <div class="relative group/close">
            <button wire:click="closeModal"
                    class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-white/10 border border-white/20 hover:bg-white/20 transition-all active:scale-95"
                    aria-label="Close">
                <svg class="w-3.5 h-3.5 stroke-white" stroke-width="2.5" stroke-linecap="round" viewBox="0 0 14 14" fill="none">
                    <path d="M2 2L12 12M12 2L2 12"/>
                </svg>
            </button>
            <div class="absolute top-full right-0 mt-2 pointer-events-none opacity-0 group-hover/close:opacity-100 transition-opacity duration-150 z-50">
                <div class="bg-gray-900 text-white text-[10px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-md whitespace-nowrap relative">
                    <span class="absolute bottom-full right-3 border-4 border-transparent border-b-gray-900"></span>
                    Close
                </div>
            </div>
        </div>
    </div>

    <div class="flex-1 overflow-y-auto [&::-webkit-scrollbar]:w-1 [&::-webkit-scrollbar-thumb]:bg-gray-300 [&::-webkit-scrollbar-thumb]:rounded-full">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-10 py-7 space-y-5">

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

            @if(count($coordinatorErrors) > 0)
            <div class="p-4 rounded-2xl bg-red-50 border border-red-200 shadow-sm">
                <p class="font-semibold text-sm text-red-900 mb-2">Please fix the following:</p>
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

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50">
                        <h3 class="text-xs font-semibold text-gray-700 uppercase tracking-wider">Personal Information</h3>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
                            <div class="lg:col-span-1 flex flex-col items-center gap-3">
                                <div class="border-2 border-dashed border-gray-300 rounded-2xl p-5 text-center cursor-pointer hover:border-gray-400 hover:bg-gray-50 transition w-full"
                                     onclick="document.getElementById('coordPhotoInput').click()">
                                    @if($coordPhoto)
                                        <img src="{{ $coordPhoto->temporaryUrl() }}" class="w-20 h-20 rounded-xl mx-auto mb-2 object-cover shadow-md">
                                        <p class="text-xs text-emerald-600 font-semibold">Selected</p>
                                    @else
                                        <svg class="w-8 h-8 mx-auto mb-2 text-gray-300" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/>
                                        </svg>
                                        <p class="text-sm text-gray-700 font-semibold">Profile Photo</p>
                                        <p class="text-xs text-gray-400 mt-0.5">JPG, PNG, WebP · 5 MB</p>
                                    @endif
                                    <input type="file" id="coordPhotoInput" wire:model="coordPhoto" accept="image/jpeg,image/png,image/webp" class="hidden">
                                </div>
                                <p class="text-xs text-gray-400 text-center">Optional — leave blank for default</p>
                            </div>

                            <div class="lg:col-span-3 grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                                <div class="sm:col-span-1 xl:col-span-2">
                                    <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wide mb-1.5">First Name <span class="text-red-500">*</span></label>
                                    <input wire:model.defer="coordFirstName" type="text" placeholder="e.g. Juan"
                                           class="w-full px-3.5 py-3 border border-gray-300 rounded-xl text-sm bg-white text-gray-900 focus:outline-none focus:border-gray-500 focus:ring-2 focus:ring-gray-200 transition @error('coordFirstName') border-red-400 @enderror">
                                    @error('coordFirstName')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                                </div>
                                <div class="sm:col-span-1 xl:col-span-2">
                                    <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wide mb-1.5">Last Name <span class="text-red-500">*</span></label>
                                    <input wire:model.defer="coordLastName" type="text" placeholder="e.g. dela Cruz"
                                           class="w-full px-3.5 py-3 border border-gray-300 rounded-xl text-sm bg-white text-gray-900 focus:outline-none focus:border-gray-500 focus:ring-2 focus:ring-gray-200 transition @error('coordLastName') border-red-400 @enderror">
                                    @error('coordLastName')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
                                </div>
                                <div class="sm:col-span-1 xl:col-span-2">
                                    <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wide mb-1.5">Middle Name</label>
                                    <input wire:model.defer="coordMiddleInitial" type="text" placeholder="e.g. Santos" maxlength="50"
                                           class="w-full px-3.5 py-3 border border-gray-300 rounded-xl text-sm bg-white text-gray-900 focus:outline-none focus:border-gray-500 focus:ring-2 focus:ring-gray-200 transition @error('coordMiddleInitial') border-red-400 @enderror">
                                    @error('coordMiddleInitial')<p class="text-xs text-red-500 mt-0.5">{{ $message }}</p>@enderror
                                </div>
                                <div class="sm:col-span-1 xl:col-span-2">
                                    <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wide mb-1.5">Suffix</label>
                                    <input wire:model.defer="coordSuffix" type="text" placeholder="e.g. Jr., Sr., III" maxlength="10"
                                           class="w-full px-3.5 py-3 border border-gray-300 rounded-xl text-sm bg-white text-gray-900 focus:outline-none focus:border-gray-500 focus:ring-2 focus:ring-gray-200 transition @error('coordSuffix') border-red-400 @enderror">
                                    <p class="text-xs text-gray-400 mt-1">Jr., Sr., II, III, IV, MD, PhD…</p>
                                    @error('coordSuffix')<p class="text-xs text-red-500 mt-0.5">{{ $message }}</p>@enderror
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50">
                        <h3 class="text-xs font-semibold text-gray-700 uppercase tracking-wider">Account Credentials</h3>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wide mb-1.5">Teacher ID <span class="text-red-500">*</span></label>
                                <input wire:model.defer="coordTeacherId" type="text" placeholder="e.g. 20240001" maxlength="8"
                                       inputmode="numeric" pattern="\d{8}" oninput="this.value=this.value.replace(/\D/g,'')"
                                       class="w-full px-3.5 py-3 border border-gray-300 rounded-xl text-sm bg-white text-gray-900 font-mono focus:outline-none focus:border-gray-500 focus:ring-2 focus:ring-gray-200 transition @error('coordTeacherId') border-red-400 @enderror">
                                <p class="text-xs text-gray-400 mt-1">Must be exactly 8 digits</p>
                                @error('coordTeacherId')<p class="text-xs text-red-500 mt-0.5">{{ $message }}</p>@enderror
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wide mb-1.5">Email Address <span class="text-red-500">*</span></label>
                                <input wire:model.defer="coordEmail" type="email" placeholder="coordinator@example.com"
                                       class="w-full px-3.5 py-3 border border-gray-300 rounded-xl text-sm bg-white text-gray-900 focus:outline-none focus:border-gray-500 focus:ring-2 focus:ring-gray-200 transition @error('coordEmail') border-red-400 @enderror">
                                <p class="text-xs text-gray-400 mt-1">Login credentials will be sent here</p>
                                @error('coordEmail')<p class="text-xs text-red-500 mt-0.5">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                    <div class="px-6 py-3.5 border-b border-gray-100 bg-gray-50">
                        <h3 class="text-xs font-semibold text-gray-700 uppercase tracking-wider">
                            College Assignment <span class="text-red-500">*</span>
                        </h3>
                    </div>
                    <div class="p-6">
                        @if($this->orgDepartmentsGrouped->isEmpty())
                            <div class="p-4 rounded-xl bg-amber-50 border border-amber-200 text-sm flex items-start gap-2">
                                <svg class="w-4 h-4 text-amber-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                                </svg>
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
                            <div x-data="{ map: {{ Js::from($collegeDeptsMap) }}, get depts() { return $wire.coordCollegeSelect ? (this.map[$wire.coordCollegeSelect] ?? []) : []; } }"
                                 class="space-y-4">
                                <div class="flex flex-col gap-4">
                                    <div class="flex-1">
                                        <label class="block text-xs font-semibold text-gray-700 uppercase tracking-wide mb-1.5">Select College</label>
                                        <select wire:model.live="coordCollegeSelect"
                                                class="w-full px-3.5 py-3 pr-8 border border-gray-300 rounded-xl text-sm bg-white text-gray-900 focus:outline-none focus:border-gray-500 focus:ring-2 focus:ring-gray-200 transition appearance-none bg-no-repeat cursor-pointer @error('coordCollegeSelect') border-red-400 @enderror"
                                                style="background-image:url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E\");background-position:right 0.5rem center;background-size:1.1em;">
                                            <option value="">Select College</option>
                                            @foreach($this->orgDepartmentsGrouped->keys() as $cN)
                                                @php $isOccupied = isset($occupiedColleges[$cN]); @endphp
                                                <option value="{{ $cN }}" {{ $isOccupied ? 'disabled' : '' }}>
                                                    {{ $cN }}{{ $isOccupied ? ' — occupied' : '' }}
                                                </option>
                                            @endforeach
                                        </select>
                                        @error('coordCollegeSelect')
                                            <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                                        @enderror
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div x-show="depts.length > 0" x-cloak>
                                            <p class="text-xs text-gray-500 font-semibold mb-2 uppercase tracking-wide">Departments under this college:</p>
                                            <div class="flex flex-wrap gap-1.5">
                                                <template x-for="code in depts" :key="code">
                                                    <span class="inline-block px-3 py-1.5 bg-gray-100 text-gray-700 border border-gray-300 rounded-full text-xs font-semibold font-mono" x-text="code"></span>
                                                </template>
                                            </div>
                                        </div>
                                        <div x-show="!$wire.coordCollegeSelect" x-cloak>
                                            <p class="text-xs text-gray-400 font-semibold mb-2 uppercase tracking-wide">&nbsp;</p>
                                            <div class="flex items-center gap-2 p-3 bg-white border border-gray-300 rounded-xl">
                                                <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15.042 21.672L13.684 16.6m0 0l-2.51 2.225.569-9.47 5.227 7.917-3.286-.672zm-7.518-.267A8.25 8.25 0 1120.25 10.5M8.288 14.212A5.25 5.25 0 1117.25 10.5"/>
                                                </svg>
                                                <p class="text-xs text-gray-600">Select a college to preview its departments.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>

                <div class="flex flex-wrap gap-3 pb-2">
                    <button type="button" wire:click="closeModal"
                            class="flex-1 sm:flex-none sm:w-36 px-6 py-3.5 rounded-xl text-sm font-semibold bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 transition">
                        Cancel
                    </button>
                    <button type="button" wire:click="resetCoordFormPublic"
                            class="flex-1 sm:flex-none sm:w-36 px-6 py-3.5 rounded-xl text-sm font-semibold bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 transition">
                        Reset
                    </button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="registerCoordinator"
                            class="flex-1 px-6 py-3.5 rounded-xl text-sm font-semibold bg-[#7a3f91] hover:bg-[#5e2f72] text-white transition flex items-center justify-center gap-2 shadow-md disabled:opacity-50">
                        <span wire:loading wire:target="registerCoordinator">
                            <svg class="animate-spin w-4 h-4 text-white inline mr-1" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                            </svg>
                            Registering...
                        </span>
                        <span wire:loading.remove wire:target="registerCoordinator">Register Coordinator</span>
                    </button>
                </div>

            </form>
        </div>
    </div>
</div>
@endif


{{-- ── VIEW PROFILE — FULL SCREEN — PURPLE HEADER + GLASSMORPHISM X ── --}}
@if($activeModal === 'viewProfile' && $viewingProfile)
@php
    $profileStatus   = $viewingProfile['status'] ?? 'INACTIVE';
    $profileId       = $viewingProfile['id'] ?? $viewingProfileId;
    $isProfileActive = $profileStatus === 'ACTIVE';
    $collegeName     = $this->getCollegeForCourse($viewingProfile['department'] ?? '');
    $fullDisplayName = $this->formatDisplayName(
        $viewingProfile['first_name'] ?? '',
        $viewingProfile['middle_initial'] ?? '',
        $viewingProfile['last_name'] ?? '',
        $viewingProfile['suffix'] ?? ''
    );
    $statusCls = match($profileStatus) {
        'ACTIVE'   => 'bg-emerald-500 text-white',
        'INACTIVE' => 'bg-amber-400 text-white',
        default    => 'bg-gray-400 text-white',
    };
@endphp
<div class="fixed inset-0 z-[9000] flex flex-col font-sans"
     style="background:#f8f7fb; animation: vpFadeIn .18s ease both;"
     @keydown.escape.window="$wire.closeModal()">

<style>
@keyframes vpFadeIn { from { opacity:0; } to { opacity:1; } }

.vp-detail-card {
    background: #ffffff;
    border: 1px solid #e8e0f0;
    border-radius: 1rem;
    overflow: hidden;
}
.vp-detail-card-header {
    padding: 0.625rem 1.25rem;
    border-bottom: 1px solid #f0eaf7;
    background: #faf7fd;
}
.vp-detail-card-header p {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #7a3f91;
}
.vp-field-label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: #9b8aab;
    margin-bottom: 2px;
}
.vp-field-value {
    font-size: 0.875rem;
    font-weight: 600;
    color: #1a1a2e;
}
.vp-dept-chip {
    display: inline-flex;
    flex-direction: column;
    align-items: flex-start;
    padding: 0.5rem 0.75rem;
    background: #faf7fd;
    border: 1px solid #ddd0f0;
    border-radius: 0.75rem;
    min-width: 80px;
}
</style>

    {{-- Top bar — purple gradient, glassmorphism X --}}
    <div class="flex items-center justify-between px-6 lg:px-10 h-[56px] shrink-0 gap-4"
         style="background: linear-gradient(to right, #7a3f91, #9b59b6); box-shadow: 0 2px 12px rgba(122,63,145,0.25);">

        {{-- Left: photo + name --}}
        <div class="flex items-center gap-3 flex-1 min-w-0">
            <img src="{{ $this->getPhotoUrl($viewingProfile['profile_photo'] ?? null) }}"
                 alt="{{ $viewingProfile['first_name'] ?? '' }}"
                 class="w-9 h-9 rounded-xl object-cover shrink-0 ring-2 ring-white/30">
            <div class="min-w-0">
                <p class="text-white font-bold text-sm leading-snug truncate uppercase">{{ $fullDisplayName }}</p>
                <p class="text-white/60 text-xs font-mono truncate">ID {{ $viewingProfile['id_number'] ?? '—' }}</p>
            </div>
        </div>

        {{-- Right: glassmorphism close button --}}
        <button type="button" wire:click="closeModal"
                class="relative group/x inline-flex items-center justify-center w-9 h-9 rounded-xl transition-all active:scale-95 shrink-0"
                style="background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.25); backdrop-filter: blur(8px);"
                onmouseover="this.style.background='rgba(255,255,255,0.28)'; this.style.borderColor='rgba(255,255,255,0.5)';"
                onmouseout="this.style.background='rgba(255,255,255,0.15)'; this.style.borderColor='rgba(255,255,255,0.25)';"
                aria-label="Close">
            <svg class="w-3.5 h-3.5" fill="none" stroke="white" stroke-width="2.5" stroke-linecap="round" viewBox="0 0 14 14">
                <path d="M2 2L12 12M12 2L2 12"/>
            </svg>
            {{-- Tooltip below --}}
            <div class="absolute top-full right-0 mt-2 pointer-events-none opacity-0 group-hover/x:opacity-100 transition-opacity duration-150 z-50">
                <div class="bg-gray-900 text-white text-[10px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-md whitespace-nowrap relative shadow-lg">
                    <span class="absolute bottom-full right-3 border-4 border-transparent border-b-gray-900"></span>
                    Close
                </div>
            </div>
        </button>
    </div>

    {{-- Main scrollable body --}}
    <div class="flex-1 overflow-y-auto [&::-webkit-scrollbar]:w-1.5 [&::-webkit-scrollbar-thumb]:bg-purple-200 [&::-webkit-scrollbar-thumb]:rounded-full">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-10 py-6 flex flex-col lg:flex-row gap-5">

            {{-- ── LEFT SIDEBAR ── --}}
            <div class="w-full lg:w-72 xl:w-80 flex flex-col gap-4 flex-shrink-0">

                {{-- Profile card --}}
                <div class="bg-white rounded-2xl border border-[#e8e0f0] overflow-hidden shadow-sm">
                    {{-- Photo + identity --}}
                    <div class="px-6 pt-7 pb-5 flex flex-col items-center text-center gap-3"
                         style="background: linear-gradient(180deg, #faf7fd 0%, #ffffff 100%);">
                        <div class="relative">
                            <img src="{{ $this->getPhotoUrl($viewingProfile['profile_photo'] ?? null) }}"
                                 alt="{{ $viewingProfile['first_name'] ?? '' }}"
                                 class="w-24 h-24 rounded-2xl object-cover shadow-md ring-4 ring-white">
                            {{-- Status dot --}}
                            <span class="absolute -bottom-1 -right-1 w-5 h-5 rounded-full border-2 border-white shadow {{ $isProfileActive ? 'bg-emerald-400' : 'bg-amber-400' }}"></span>
                        </div>
                        <div>
                            <p class="font-bold text-gray-900 text-sm leading-tight">{{ $fullDisplayName }}</p>
                            <p class="text-gray-500 text-xs mt-0.5 font-mono">{{ $viewingProfile['id_number'] ?? '—' }}</p>
                        </div>
                        {{-- Status badge --}}
                        <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold {{ $statusCls }}">
                            <span class="w-1.5 h-1.5 rounded-full bg-white/70"></span>
                            {{ $profileStatus }}
                        </span>
                    </div>

                    {{-- College badge --}}
                    <div class="px-5 py-3 border-t border-[#f0eaf7] bg-[#faf7fd]">
                        <p class="text-[10px] font-bold uppercase tracking-widest text-[#9b8aab] mb-1">College</p>
                        <p class="text-sm font-semibold text-gray-800">{{ $collegeName }}</p>
                    </div>

                    {{-- Status action --}}
                    <div class="px-5 py-4 border-t border-[#f0eaf7]">
                        <p class="text-[10px] font-bold uppercase tracking-widest text-[#9b8aab] mb-1.5">Account Access</p>
                        <p class="text-xs text-gray-500 mb-3 leading-relaxed">
                            @if($isProfileActive)
                                Active · can log in and manage their college.
                            @else
                                Inactive · login access is disabled.
                            @endif
                        </p>
                        @if($isProfileActive)
                            <button type="button"
                                    wire:click="confirmToggleCoordinatorStatus({{ $profileId }}, 'deactivate')"
                                    class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold bg-amber-50 text-amber-700 border border-amber-200 hover:bg-amber-100 hover:border-amber-300 transition active:scale-95">
                                <i class="fas fa-ban text-xs"></i> Deactivate
                            </button>
                        @else
                            <button type="button"
                                    wire:click="confirmToggleCoordinatorStatus({{ $profileId }}, 'activate')"
                                    class="w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl text-sm font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100 hover:border-emerald-300 transition active:scale-95">
                                <i class="fas fa-circle-check text-xs"></i> Activate
                            </button>
                        @endif
                    </div>

                    {{-- Registered date --}}
                    <div class="px-5 py-3 border-t border-[#f0eaf7]">
                        <p class="text-xs text-gray-400 text-center">
                            Registered {{ isset($viewingProfile['created_at']) ? \Carbon\Carbon::parse($viewingProfile['created_at'])->format('M d, Y \a\t g:i A') : '—' }}
                        </p>
                    </div>
                </div>

            </div>

            {{-- ── RIGHT CONTENT ── --}}
            <div class="flex-1 min-w-0 flex flex-col gap-4">

                {{-- Name breakdown --}}
                <div class="vp-detail-card">
                    <div class="vp-detail-card-header">
                        <p>Name Details</p>
                    </div>
                    <div class="grid grid-cols-2 divide-x divide-y divide-[#f0eaf7]">
                        <div class="px-5 py-4">
                            <p class="vp-field-label">First Name</p>
                            <p class="vp-field-value">{{ $viewingProfile['first_name'] ?? '—' }}</p>
                        </div>
                        <div class="px-5 py-4">
                            <p class="vp-field-label">Last Name</p>
                            <p class="vp-field-value">{{ $viewingProfile['last_name'] ?? '—' }}</p>
                        </div>
                        <div class="px-5 py-4">
                            <p class="vp-field-label">Middle Name</p>
                            <p class="vp-field-value">{{ $viewingProfile['middle_initial'] ?? '—' }}</p>
                        </div>
                        <div class="px-5 py-4">
                            <p class="vp-field-label">Suffix</p>
                            <p class="vp-field-value">{{ $viewingProfile['suffix'] ?: '—' }}</p>
                        </div>
                    </div>
                </div>

                {{-- Account details --}}
                <div class="vp-detail-card">
                    <div class="vp-detail-card-header">
                        <p>Account Details</p>
                    </div>
                    <div class="divide-y divide-[#f0eaf7]">

                        <div class="px-5 py-4">
                            <p class="vp-field-label">Teacher ID</p>
                            <p class="vp-field-value font-mono">{{ $viewingProfile['id_number'] ?? '—' }}</p>
                        </div>

                        {{-- Editable Email --}}
                        <div class="px-5 py-4">
                            <div class="flex items-center justify-between mb-1.5">
                                <p class="vp-field-label" style="margin-bottom:0;">Email Address</p>
                                @if(!$editingProfileEmail)
                                <button type="button" wire:click="startEditingProfileEmail"
                                        class="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-[#7a3f91] border border-[#d4aaeb] bg-[#faf5ff] rounded-md px-2 py-1 hover:bg-[#ead5f5] hover:border-[#b47fd4] transition">
                                    <i class="fas fa-pen text-[9px]"></i> Edit
                                </button>
                                @endif
                            </div>

                            @if($editingProfileEmail)
                                <div class="flex flex-col sm:flex-row gap-2 mt-2">
                                    <input wire:model.defer="profileEmailInput" type="email" autofocus
                                           class="flex-1 px-3 py-2.5 border border-[#d4aaeb] rounded-lg text-sm text-gray-900 bg-white focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition @error('profileEmailInput') border-red-400 @enderror"
                                           @keydown.enter.prevent="$wire.updateProfileEmail()">
                                    <div class="flex gap-2 shrink-0">
                                        <button type="button" wire:click="cancelEditingProfileEmail"
                                                class="px-3 py-2.5 rounded-lg text-xs font-semibold border border-gray-200 text-gray-600 hover:bg-gray-50 transition">
                                            Cancel
                                        </button>
                                        <button type="button" wire:click="updateProfileEmail"
                                                wire:loading.attr="disabled" wire:target="updateProfileEmail"
                                                class="px-3 py-2.5 rounded-lg text-xs font-semibold bg-[#7a3f91] text-white hover:bg-[#5e2f72] transition disabled:opacity-50 flex items-center gap-1.5">
                                            <span wire:loading wire:target="updateProfileEmail"><i class="fas fa-spinner animate-spin"></i></span>
                                            <span wire:loading.remove wire:target="updateProfileEmail">Save</span>
                                        </button>
                                    </div>
                                </div>
                                @error('profileEmailInput')<p class="text-xs text-red-500 mt-1.5">{{ $message }}</p>@enderror
                            @else
                                <p class="vp-field-value mt-1">{{ $viewingProfile['email'] ?? '—' }}</p>
                            @endif
                        </div>

                        <div class="px-5 py-4">
                            <p class="vp-field-label">College Assignment</p>
                            <p class="vp-field-value">{{ $collegeName }}</p>
                        </div>

                    </div>
                </div>

                {{-- Departments under college --}}
                @php
                    $deptCodesForProfile = \App\Models\Course::where('college', $collegeName)->orderBy('code')->get();
                @endphp
                @if($deptCodesForProfile->count())
                <div class="vp-detail-card">
                    <div class="vp-detail-card-header">
                        <p>Departments Under This College</p>
                    </div>
                    <div class="px-5 py-4 flex flex-wrap gap-2">
                        @foreach($deptCodesForProfile as $dept)
                            <div class="vp-dept-chip">
                                <span class="text-xs font-bold text-[#7a3f91] font-mono">{{ $dept->code }}</span>
                                <span class="text-[10px] text-gray-500 mt-0.5 leading-tight">{{ $dept->name }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
                @endif

            </div>{{-- /right --}}
        </div>{{-- /flex row --}}
    </div>{{-- /scrollable body --}}

</div>
@endif


{{-- ── MANAGE COLLEGES — FULL SCREEN ───────────── --}}
@if($activeModal === 'manageOrgCourses')
<div class="fixed inset-0 z-50 flex flex-col bg-gray-50"
     @keydown.escape.window="$wire.closeModal()">

    <div class="flex items-center justify-between px-6 lg:px-10 h-[52px] bg-gradient-to-r from-[#7a3f91] to-[#9b59b6] shrink-0 shadow-lg gap-4">
        <div class="flex items-center gap-3 flex-1 min-w-0">
            <div class="w-7 h-7 rounded-lg bg-white/20 flex items-center justify-center flex-shrink-0">
                <i class="fas fa-building-columns text-white text-xs"></i>
            </div>
            <span class="text-white font-semibold text-sm truncate">Manage Colleges &amp; Departments</span>
        </div>
        <div class="relative group/close">
            <button wire:click="closeModal"
                    class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-white/10 border border-white/20 hover:bg-white/20 transition-all active:scale-95"
                    aria-label="Close">
                <svg class="w-3.5 h-3.5 stroke-white" stroke-width="2.5" stroke-linecap="round" viewBox="0 0 14 14" fill="none">
                    <path d="M2 2L12 12M12 2L2 12"/>
                </svg>
            </button>
            <div class="absolute top-full right-0 mt-2 pointer-events-none opacity-0 group-hover/close:opacity-100 transition-opacity duration-150 z-50">
                <div class="bg-gray-900 text-white text-[10px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-md whitespace-nowrap relative">
                    <span class="absolute bottom-full right-3 border-4 border-transparent border-b-gray-900"></span>
                    Close
                </div>
            </div>
        </div>
    </div>

    <div id="org-modal-scroll"
         class="flex-1 overflow-y-auto overflow-x-hidden [&::-webkit-scrollbar]:w-1 [&::-webkit-scrollbar-thumb]:bg-gray-300 [&::-webkit-scrollbar-thumb]:rounded-full"
         @org-modal-scroll-top.window="$nextTick(() => $el.scrollTo({ top: 0, behavior: 'smooth' }))">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-10 py-7 space-y-5">

            @if($orgCourseAlert)
            <div class="flex items-start gap-2.5 p-4 rounded-2xl shadow-sm {{ $orgCourseAlertType === 'success' ? 'bg-emerald-50 border border-emerald-200' : 'bg-red-50 border border-red-200' }}">
                <i class="fas mt-0.5 text-base {{ $orgCourseAlertType === 'success' ? 'fa-circle-check text-emerald-500' : 'fa-circle-xmark text-red-500' }}"></i>
                <p class="text-sm font-semibold {{ $orgCourseAlertType === 'success' ? 'text-emerald-900' : 'text-red-900' }}">{{ $orgCourseAlert }}</p>
            </div>
            @endif

            @if($orgRenamingCollege)
            <div class="bg-white rounded-2xl border-2 border-[#d4aaeb] shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-[#e2d3ef] bg-[#f5eef9]">
                    <h3 class="text-xs font-semibold text-[#7a3f91] uppercase tracking-wider flex items-center gap-2">
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
                            <button wire:click="cancelRenamingCollege" class="px-5 py-3 bg-white border border-gray-300 text-gray-700 rounded-xl text-sm font-semibold hover:bg-gray-50 transition">Cancel</button>
                            <button wire:click="renameCollege" wire:loading.attr="disabled" wire:target="renameCollege"
                                    class="px-5 py-3 bg-[#7a3f91] hover:bg-[#5e2f72] text-white rounded-xl text-sm font-semibold transition flex items-center gap-2 disabled:opacity-50">
                                <span wire:loading wire:target="renameCollege"><i class="fas fa-spinner animate-spin"></i></span>
                                <span wire:loading.remove wire:target="renameCollege">Save</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            @endif

            @if($orgAddingToCollege)
            <div class="bg-white rounded-2xl border-2 border-[#d4aaeb] shadow-sm overflow-hidden">
                <div class="px-6 py-3.5 border-b border-[#e2d3ef] bg-[#f5eef9] flex items-center justify-between">
                    <h3 class="text-xs font-semibold text-[#7a3f91] uppercase tracking-wider flex items-center gap-2">
                        <i class="fas fa-{{ isset($orgCoursesList[$orgAddingToCollege]) ? 'pencil' : 'plus' }}"></i>
                        {{ isset($orgCoursesList[$orgAddingToCollege]) ? 'Edit Departments' : 'Assign Departments' }}
                        — <span class="normal-case text-gray-800 font-normal">{{ $orgAddingToCollege }}</span>
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
                                @if($isTaken)<p class="text-xs text-amber-700 mt-0.5"><i class="fas fa-lock mr-1"></i>{{ $otherCollege }}</p>@endif
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
                        <button wire:click="cancelAddingCourses" class="flex-1 sm:flex-none sm:w-36 bg-white border border-gray-300 text-gray-700 px-4 py-3 rounded-xl text-sm font-semibold hover:bg-gray-50 transition">Cancel</button>
                        <button wire:click="saveCollegeCourses" wire:loading.attr="disabled" wire:target="saveCollegeCourses"
                                class="flex-1 bg-[#7a3f91] hover:bg-[#5e2f72] text-white px-4 py-3 rounded-xl text-sm font-semibold transition flex items-center justify-center gap-2 disabled:opacity-50">
                            <span wire:loading wire:target="saveCollegeCourses"><i class="fas fa-spinner animate-spin"></i> Saving...</span>
                            <span wire:loading.remove wire:target="saveCollegeCourses">Save Departments</span>
                        </button>
                    </div>
                </div>
            </div>
            @endif

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

                @if(!$orgAddingToCollege && !$orgRenamingCollege)
                <div class="lg:col-span-1">
                    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden lg:sticky lg:top-5">
                        <div class="px-5 py-3.5 border-b border-gray-100 bg-gray-50">
                            <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Add New College</h3>
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

                <div class="{{ (!$orgAddingToCollege && !$orgRenamingCollege) ? 'lg:col-span-2' : 'lg:col-span-3' }}">
                    <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden flex flex-col" style="min-height: 700px; max-height: calc(100vh - 220px);">
                        <div class="px-5 py-3.5 border-b border-gray-100 bg-gray-50 flex items-center gap-2 flex-shrink-0">
                            <h3 class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Colleges &amp; Departments</h3>
                            <span class="ml-auto text-xs font-semibold text-[#7a3f91] bg-[#f5eef9] px-2.5 py-1 rounded-full border border-[#d4aaeb]">{{ count($orgCoursesList) }}</span>
                        </div>

                        <div class="flex-1 overflow-y-auto overflow-x-hidden min-h-0">
                            @if(count($orgCoursesList) === 0)
                            <div class="flex flex-col items-center justify-center h-full text-center py-16">
                                <i class="fas fa-building-columns text-4xl text-gray-200 block mb-3"></i>
                                <p class="text-gray-400 font-semibold">No colleges yet</p>
                                <p class="text-xs text-gray-400 mt-1">Add one using the panel on the left.</p>
                            </div>
                            @else
                            <div class="divide-y divide-gray-100">
                                @foreach($orgCoursesList as $college => $departments)
                                @php $occupied = $this->occupiedColleges(); $coordName = $occupied[$college] ?? null; @endphp
                                <div class="bg-white hover:bg-[#faf7fd] transition-colors duration-100 px-5 py-4">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="flex items-start gap-3 flex-1 min-w-0">
                                            <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-[#7a3f91] to-[#9b59b6] flex items-center justify-center shrink-0 mt-0.5 shadow-sm">
                                                <i class="fas fa-building-columns text-xs text-white"></i>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <p class="font-semibold text-gray-800 text-sm leading-snug">{{ $college }}</p>
                                                @if(count($departments) > 0)
                                                    <div class="flex flex-wrap gap-1 mt-1.5">
                                                        @foreach($departments as $dept)
                                                            <span class="inline-block px-2 py-1 bg-[#f5eef9] text-[#7a3f91] border border-[#d4aaeb] rounded-md text-xs font-mono font-semibold">{{ $dept['code'] }}</span>
                                                        @endforeach
                                                    </div>
                                                    <p class="text-xs text-gray-400 mt-1">{{ count($departments) }} department{{ count($departments) !== 1 ? 's' : '' }}</p>
                                                @else
                                                    <span class="text-xs text-gray-400 mt-1 block">No departments</span>
                                                @endif
                                                @if($coordName)
                                                    <div class="flex items-center gap-1.5 mt-1.5">
                                                        <span class="w-2 h-2 rounded-full bg-emerald-400 shrink-0"></span>
                                                        <span class="text-xs text-gray-600">{{ $coordName }}</span>
                                                    </div>
                                                @else
                                                    <span class="text-xs text-gray-400 italic mt-1.5 block">Unassigned</span>
                                                @endif
                                            </div>
                                        </div>
                                        @if(!$orgAddingToCollege && !$orgRenamingCollege)
                                        <div class="flex items-center gap-1.5 shrink-0">
                                            <div class="relative group/a">
                                                <button wire:click="startRenamingCollege('{{ addslashes($college) }}')"
                                                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-blue-50 border border-blue-200 text-blue-600 hover:bg-blue-100 hover:border-blue-400 transition-all duration-150 active:scale-95"
                                                        aria-label="Rename">
                                                    <i class="fas fa-pen-to-square text-xs"></i>
                                                </button>
                                                <div class="absolute top-full left-1/2 -translate-x-1/2 mt-1.5 pointer-events-none opacity-0 group-hover/a:opacity-100 transition-opacity z-50">
                                                    <div class="bg-gray-900 text-white text-[10px] font-bold uppercase tracking-wider px-2 py-1 rounded whitespace-nowrap relative">
                                                        <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-gray-900"></span>
                                                        Rename
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="relative group/b">
                                                <button wire:click="startEditingCollege('{{ addslashes($college) }}')"
                                                        class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-[#f5eef9] border border-[#d4aaeb] text-[#7a3f91] hover:bg-[#ead5f5] hover:border-[#b47fd4] transition-all duration-150 active:scale-95"
                                                        aria-label="Edit Departments">
                                                    <i class="fas fa-pencil text-xs"></i>
                                                </button>
                                                <div class="absolute top-full left-1/2 -translate-x-1/2 mt-1.5 pointer-events-none opacity-0 group-hover/b:opacity-100 transition-opacity z-50">
                                                    <div class="bg-gray-900 text-white text-[10px] font-bold uppercase tracking-wider px-2 py-1 rounded whitespace-nowrap relative">
                                                        <span class="absolute bottom-full left-1/2 -translate-x-1/2 border-4 border-transparent border-b-gray-900"></span>
                                                        Departments
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        @endif
                                    </div>
                                </div>
                                @endforeach
                            </div>
                            @endif
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
@endif


{{-- ── TOGGLE COORDINATOR STATUS CONFIRM ───────── --}}
@if($activeModal === 'toggleCoordinatorConfirm')
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5 bg-black/50 backdrop-blur-sm"
     @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100">
        <div class="p-6">
            <div class="flex items-center gap-4 mb-4">
                <div class="w-12 h-12 rounded-full flex items-center justify-center shrink-0 {{ $pendingToggleAction === 'deactivate' ? 'bg-amber-100' : 'bg-emerald-100' }}">
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

{{-- ── Mouse-following cursor label logic ── --}}
<script>
(function () {
    function init() {
        const label = document.getElementById('coord-cursor-label');
        if (!label) return;

        let activeRow = null, rafId = null, pendingX = 0, pendingY = 0;

        function show() { label.style.opacity = '1'; label.style.visibility = 'visible'; }
        function hide() { label.style.opacity = '0'; label.style.visibility = 'hidden'; }

        function positionLabel() {
            label.style.left = (pendingX - label.offsetWidth / 2) + 'px';
            label.style.top  = (pendingY - 14) + 'px';
            rafId = null;
        }

        function onMouseMove(e) {
            pendingX = e.clientX; pendingY = e.clientY;
            if (!rafId) rafId = requestAnimationFrame(positionLabel);
        }

        function onRowEnter(e) {
            if (e.relatedTarget && e.currentTarget.contains(e.relatedTarget)) return;
            activeRow = e.currentTarget;
            document.addEventListener('mousemove', onMouseMove);
            show();
        }

        function onRowLeave(e) {
            if (e.relatedTarget && e.currentTarget.contains(e.relatedTarget)) return;
            activeRow = null;
            hide();
            document.removeEventListener('mousemove', onMouseMove);
        }

        function attachListeners() {
            document.querySelectorAll('[data-coord-row]').forEach(row => {
                if (row._coordBound) return;
                row._coordBound = true;
                row.addEventListener('mouseenter', onRowEnter);
                row.addEventListener('mouseleave', onRowLeave);
                row.querySelectorAll('[data-coord-action]').forEach(btn => {
                    btn.addEventListener('mouseenter', () => hide());
                    btn.addEventListener('mouseleave', () => { if (activeRow) show(); });
                });
            });
        }

        attachListeners();

        document.addEventListener('livewire:navigated', () => {
            document.querySelectorAll('[data-coord-row]').forEach(r => { r._coordBound = false; });
            attachListeners();
        });

        if (window.Livewire) {
            window.Livewire.hook('morph.updated', () => {
                requestAnimationFrame(() => {
                    document.querySelectorAll('[data-coord-row]').forEach(r => { r._coordBound = false; });
                    attachListeners();
                });
            });
            try {
                window.Livewire.hook('commit', ({ succeed }) => {
                    succeed(() => {
                        requestAnimationFrame(() => {
                            document.querySelectorAll('[data-coord-row]').forEach(r => { r._coordBound = false; });
                            attachListeners();
                        });
                    });
                });
            } catch(e) {}
        }

        document.addEventListener('livewire:update', () => { hide(); activeRow = null; });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
</script>

</div>{{-- end root --}}