<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use App\Models\Alumni;

new class extends Component {

    // ══════════ PROFILE FIELDS ══════════
    public string $last_name      = '';
    public string $first_name     = '';
    public string $middle_initial = '';
    public string $suffix         = '';
    public string $student_id     = '';
    public string $course_code    = '';
    public string $course_name    = '';
    public string $batch          = '';
    public string $email          = '';
    public ?string $email_changed_at = null;

    public string $gender        = '';
    public string $date_of_birth = '';

    public string $father_last_name   = '';
    public string $father_given_name  = '';
    public string $father_middle_name = '';
    public string $father_suffix      = '';

    public string $mother_last_name   = '';
    public string $mother_given_name  = '';
    public string $mother_middle_name = '';

    public string $dswd_household_no    = '';
    public string $address_street       = '';
    public string $address_barangay     = '';
    public string $address_municipality = '';
    public string $address_province     = '';
    public string $disability           = '';
    public string $contact_number       = '';

    public string $errorMessage    = '';
    public string $successMessage  = '';
    public bool   $profileComplete = false;
    public bool   $editingProfile  = false;
    public int    $alumniId        = 0;
    public bool   $hasEmailColumn  = false;

    public bool    $hasProfileChangedAtColumn = false;
    public ?string $profile_changed_at        = null;

    public array $snapshot = [];

    // ══════════ EMPLOYMENT FIELDS ══════════
    public bool   $editingEmployment    = false;
    public bool   $hasEmploymentRecord  = false;
    public bool   $hasEmpChangedColumn  = false;
    public int    $trackingId           = 0;
    public ?array $currentRecord        = null;
    public ?string $employment_changed_at = null;
    public array  $jobOptions           = [];

    public string $employment_status   = '';
    public string $company_name        = '';
    public string $job_title           = '';
    public string $custom_job_title    = '';
    public string $employment_type     = '';
    public string $work_location       = '';
    public array  $career_path         = [];
    public string $course_relevance    = '';
    public string $unemployment_status = '';
    public string $education_status    = '';

    public array $employmentSnapshot = [];

    private const EMP_SNAP_KEYS = [
        'employment_status', 'company_name', 'job_title', 'custom_job_title',
        'employment_type', 'work_location', 'career_path', 'education_status',
        'course_relevance', 'unemployment_status',
    ];

    // Fields that must NOT contain digits (people's names / place names).
    // Regex allows letters (incl. accented/ñ), spaces, periods, hyphens, apostrophes.
    private const NAME_REGEX = '/^[\pL\s\.\-\']+$/u';

    private function editableKeys(): array
    {
        return [
            'email',
            'gender', 'date_of_birth',
            'father_last_name', 'father_given_name', 'father_middle_name', 'father_suffix',
            'mother_last_name',  'mother_given_name',  'mother_middle_name',
            'dswd_household_no',
            'address_street', 'address_barangay', 'address_municipality', 'address_province',
            'disability', 'contact_number',
        ];
    }

    private function upperCaseFields(): array
    {
        return [
            'father_last_name', 'father_given_name', 'father_middle_name', 'father_suffix',
            'mother_last_name',  'mother_given_name',  'mother_middle_name',
            'dswd_household_no',
            'address_street', 'address_barangay', 'address_municipality', 'address_province',
            'disability', 'contact_number',
        ];
    }

    // ══════════ MOUNT ══════════
    public function mount(): void
    {
        $user = auth()->user();
        if (!$user || $user->role !== 'alumni') {
            $this->redirect(route('login'));
            return;
        }

        $this->hasEmailColumn            = Schema::hasColumn('alumni', 'email_changed_at');
        $this->hasProfileChangedAtColumn = Schema::hasColumn('alumni', 'profile_changed_at');
        $this->hasEmpChangedColumn       = Schema::hasColumn('employment_trackings', 'created_at');

        $columns = [
            'id', 'first_name', 'middle_initial', 'last_name', 'suffix',
            'student_id', 'course_code', 'course_name', 'batch',
            'email', 'gender', 'date_of_birth',
            'father_last_name', 'father_given_name', 'father_middle_name', 'father_suffix',
            'mother_last_name',  'mother_given_name',  'mother_middle_name',
            'dswd_household_no',
            'address_street', 'address_barangay', 'address_municipality', 'address_province',
            'disability', 'contact_number', 'profile_completed',
        ];
        if ($this->hasEmailColumn) $columns[] = 'email_changed_at';
        if ($this->hasProfileChangedAtColumn) $columns[] = 'profile_changed_at';

        $alumni = Alumni::where('user_id', $user->id)->select($columns)->first();

        if (!$alumni) {
            $this->redirect(route('login'));
            return;
        }

        $this->alumniId           = $alumni->id;
        $this->last_name          = $alumni->last_name      ?? '';
        $this->first_name         = $alumni->first_name     ?? '';
        $this->middle_initial     = $alumni->middle_initial ?? '';
        $this->suffix             = $alumni->suffix         ?? '';
        $this->student_id         = $alumni->student_id     ?? '';
        $this->course_code        = $alumni->course_code    ?? '';
        $this->course_name        = $alumni->course_name    ?? '';
        $this->batch              = (string)($alumni->batch ?? '');
        $this->email              = $alumni->email          ?? '';
        $this->email_changed_at   = $this->hasEmailColumn && $alumni->email_changed_at
            ? \Carbon\Carbon::parse($alumni->email_changed_at)->toDateTimeString()
            : null;
        $this->profile_changed_at = $this->hasProfileChangedAtColumn && $alumni->profile_changed_at
            ? \Carbon\Carbon::parse($alumni->profile_changed_at)->toDateTimeString()
            : null;

        $this->gender        = $alumni->gender ?? '';
        $this->date_of_birth = $alumni->date_of_birth
            ? \Carbon\Carbon::parse($alumni->date_of_birth)->format('Y-m-d') : '';

        $this->father_last_name   = $alumni->father_last_name   ?? '';
        $this->father_given_name  = $alumni->father_given_name  ?? '';
        $this->father_middle_name = $alumni->father_middle_name ?? '';
        $this->father_suffix      = $alumni->father_suffix      ?? '';

        $this->mother_last_name   = $alumni->mother_last_name   ?? '';
        $this->mother_given_name  = $alumni->mother_given_name  ?? '';
        $this->mother_middle_name = $alumni->mother_middle_name ?? '';

        $this->dswd_household_no    = $alumni->dswd_household_no    ?? '';
        $this->address_street       = $alumni->address_street       ?? '';
        $this->address_barangay     = $alumni->address_barangay     ?? '';
        $this->address_municipality = $alumni->address_municipality ?? '';
        $this->address_province     = $alumni->address_province     ?? '';
        $this->disability           = $alumni->disability           ?? '';
        $this->contact_number       = $alumni->contact_number       ?? '';

        $this->profileComplete = (bool)($alumni->profile_completed ?? false);
        $this->editingProfile  = !$this->profileComplete;

        $keys = $this->editableKeys();
        $this->snapshot = array_combine($keys, array_map(fn($k) => $this->$k, $keys));

        // ── employment ──
        $this->jobOptions = $this->buildJobOptions();
        $this->loadEmploymentRecord();

        // ── Auto-open the Update Employment editor when the alumni was sent
        //    here directly from the Dashboard's "Update"/"Add Employment
        //    Record" buttons (flashed via session by goToUpdateEmployment()
        //    on the dashboard component). This respects the normal 30-day
        //    cooldown — if locked, startEditingEmployment() will just show
        //    the "locked" toast instead of opening the editor. ──
        if (session()->pull('open_employment')) {
            $this->startEditingEmployment();
        }
    }

    // ══════════ PROFILE COOLDOWN ══════════
    public function getCanEditEmailProperty(): bool
    {
        if (!$this->hasEmailColumn || !$this->email_changed_at) return true;
        return \Carbon\Carbon::parse($this->email_changed_at)->addDays(30)->isPast();
    }

    public function getEmailCooldownDaysLeftProperty(): int
    {
        if (!$this->hasEmailColumn || !$this->email_changed_at) return 0;
        $unlockAt = \Carbon\Carbon::parse($this->email_changed_at)->addDays(30);
        if ($unlockAt->isPast()) return 0;
        return (int) ceil(now()->diffInSeconds($unlockAt) / 86400);
    }

    public function getCanEditProfileProperty(): bool
    {
        if (!$this->hasProfileChangedAtColumn || !$this->profile_changed_at) return true;
        return \Carbon\Carbon::parse($this->profile_changed_at)->addDays(30)->isPast();
    }

    public function getProfileCooldownDaysLeftProperty(): int
    {
        if (!$this->hasProfileChangedAtColumn || !$this->profile_changed_at) return 0;
        $unlockAt = \Carbon\Carbon::parse($this->profile_changed_at)->addDays(30);
        if ($unlockAt->isPast()) return 0;
        return (int) ceil(now()->diffInSeconds($unlockAt) / 86400);
    }

    // ══════════ EMPLOYMENT COOLDOWN ══════════
    public function getCanEditEmploymentProperty(): bool
    {
        if (!$this->employment_changed_at) return true;
        return \Carbon\Carbon::parse($this->employment_changed_at)->addDays(30)->isPast();
    }

    public function getEmploymentCooldownDaysLeftProperty(): int
    {
        if (!$this->employment_changed_at) return 0;
        $unlockAt = \Carbon\Carbon::parse($this->employment_changed_at)->addDays(30);
        if ($unlockAt->isPast()) return 0;
        return (int) ceil(now()->diffInSeconds($unlockAt) / 86400);
    }

    // ══════════ PROFILE ACTIONS ══════════
    public function startEditingProfile(): void
    {
        $this->errorMessage = $this->successMessage = '';

        if ($this->profileComplete && !$this->canEditProfile) {
            $this->dispatch('show-toast', type: 'error', message: "You can only update your profile once every 30 days. Please try again in {$this->profileCooldownDaysLeft} day(s).");
            return;
        }

        $keys = $this->editableKeys();
        $this->snapshot = array_combine($keys, array_map(fn($k) => $this->$k, $keys));
        $this->editingProfile = true;
    }

    public function cancelEditingProfile(): void
    {
        $this->errorMessage = $this->successMessage = '';
        $this->resetValidation();
        foreach ($this->snapshot as $k => $v) $this->$k = $v;
        $this->editingProfile = false;
    }

    public function saveProfile(): void
    {
        $this->errorMessage = $this->successMessage = '';

        if ($this->profileComplete && !$this->canEditProfile) {
            $this->errorMessage = "You can only update your profile once every 30 days. Please try again in {$this->profileCooldownDaysLeft} day(s).";
            $this->dispatch('show-toast', type: 'error', message: $this->errorMessage);
            return;
        }

        foreach ($this->upperCaseFields() as $field) {
            $this->$field = strtoupper(trim($this->$field));
        }
        $this->email = trim($this->email);

        $isDirty = false;
        foreach ($this->editableKeys() as $key) {
            $snap    = trim((string)($this->snapshot[$key] ?? ''));
            $current = trim((string)($this->$key ?? ''));
            if (strtoupper($snap) !== strtoupper($current)) { $isDirty = true; break; }
        }

        if (!$isDirty) {
            $this->dispatch('show-toast', type: 'error', message: 'No changes were made. Please edit a field before saving.');
            return;
        }

        $emailChanged = strtoupper(trim((string)($this->snapshot['email'] ?? ''))) !== strtoupper($this->email);

        if ($emailChanged && !$this->canEditEmail) {
            $this->errorMessage = "You can only change your email once every 30 days. Please try again in {$this->emailCooldownDaysLeft} day(s).";
            $this->dispatch('show-toast', type: 'error', message: $this->errorMessage);
            return;
        }

        try {
            $this->validate([
                'email'                 => 'required|email:filter|max:255|unique:alumni,email,' . $this->alumniId,
                'gender'               => 'required|string|in:Male,Female',
                'date_of_birth'        => 'required|date|before:today',
                'father_last_name'     => ['required', 'string', 'max:100', 'regex:' . self::NAME_REGEX],
                'father_given_name'    => ['required', 'string', 'max:100', 'regex:' . self::NAME_REGEX],
                'father_middle_name'   => ['required', 'string', 'max:100', 'regex:' . self::NAME_REGEX],
                'father_suffix'        => ['nullable', 'string', 'max:20', 'regex:' . self::NAME_REGEX],
                'mother_last_name'     => ['required', 'string', 'max:100', 'regex:' . self::NAME_REGEX],
                'mother_given_name'    => ['required', 'string', 'max:100', 'regex:' . self::NAME_REGEX],
                'mother_middle_name'   => ['required', 'string', 'max:100', 'regex:' . self::NAME_REGEX],
                'dswd_household_no'    => 'nullable|string|max:50',
                'address_street'       => 'required|string|max:255',
                'address_barangay'     => ['required', 'string', 'max:255', 'regex:' . self::NAME_REGEX],
                'address_municipality' => ['required', 'string', 'max:255', 'regex:' . self::NAME_REGEX],
                'address_province'     => ['required', 'string', 'max:255', 'regex:' . self::NAME_REGEX],
                'disability'           => 'nullable|string|max:255',
                'contact_number'       => 'required|string|max:20|regex:/^[0-9\-\+\s]+$/',
            ], [
                'email.required'                => 'Email address is required.',
                'email.email'                   => 'Please enter a valid email address.',
                'email.unique'                  => 'This email is already used by another account.',
                'gender.required'               => 'Please select your sex/gender.',
                'date_of_birth.required'        => 'Birth date is required.',
                'date_of_birth.before'          => 'Birth date must be in the past.',
                'father_last_name.required'     => "Father's last name is required.",
                'father_last_name.regex'        => "Father's last name must not contain numbers.",
                'father_given_name.required'    => "Father's given name is required.",
                'father_given_name.regex'       => "Father's given name must not contain numbers.",
                'father_middle_name.required'   => "Father's middle name is required.",
                'father_middle_name.regex'      => "Father's middle name must not contain numbers.",
                'father_suffix.regex'           => "Father's suffix must not contain numbers.",
                'mother_last_name.required'     => "Mother's last name is required.",
                'mother_last_name.regex'        => "Mother's last name must not contain numbers.",
                'mother_given_name.required'    => "Mother's given name is required.",
                'mother_given_name.regex'       => "Mother's given name must not contain numbers.",
                'mother_middle_name.required'   => "Mother's middle name is required.",
                'mother_middle_name.regex'      => "Mother's middle name must not contain numbers.",
                'address_street.required'       => 'Street is required.',
                'address_barangay.required'     => 'Barangay is required.',
                'address_barangay.regex'        => 'Barangay must not contain numbers.',
                'address_municipality.required' => 'Town/City/Municipality is required.',
                'address_municipality.regex'    => 'Town/City/Municipality must not contain numbers.',
                'address_province.required'     => 'Province is required.',
                'address_province.regex'        => 'Province must not contain numbers.',
                'contact_number.required'       => 'Contact number is required.',
                'contact_number.regex'          => 'Contact number must contain digits only.',
            ]);
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first();
            $this->dispatch('show-toast', type: 'error', message: $first ?: 'Please check the highlighted fields.');
            throw $e;
        }

        try {
            $profileComplete =
                !empty($this->email)
                && !empty($this->gender) && !empty($this->date_of_birth)
                && !empty($this->father_last_name) && !empty($this->father_given_name)
                && !empty($this->father_middle_name)
                && !empty($this->mother_last_name) && !empty($this->mother_given_name)
                && !empty($this->mother_middle_name)
                && !empty($this->address_street) && !empty($this->address_barangay)
                && !empty($this->address_municipality) && !empty($this->address_province)
                && !empty($this->contact_number);

            $updateData = [
                'email'                 => $this->email ?: null,
                'gender'               => $this->gender,
                'date_of_birth'        => $this->date_of_birth ?: null,
                'father_last_name'     => $this->father_last_name     ?: null,
                'father_given_name'    => $this->father_given_name    ?: null,
                'father_middle_name'   => $this->father_middle_name   ?: null,
                'father_suffix'        => $this->father_suffix        ?: null,
                'mother_last_name'     => $this->mother_last_name     ?: null,
                'mother_given_name'    => $this->mother_given_name    ?: null,
                'mother_middle_name'   => $this->mother_middle_name   ?: null,
                'dswd_household_no'    => $this->dswd_household_no    ?: null,
                'address_street'       => $this->address_street       ?: null,
                'address_barangay'     => $this->address_barangay     ?: null,
                'address_municipality' => $this->address_municipality ?: null,
                'address_province'     => $this->address_province     ?: null,
                'disability'           => $this->disability           ?: null,
                'contact_number'       => $this->contact_number       ?: null,
                'profile_completed'    => $profileComplete,
                'updated_at'           => now(),
            ];

            if ($emailChanged && $this->hasEmailColumn) {
                $updateData['email_changed_at'] = now();
                $this->email_changed_at = now()->toDateTimeString();
            }
            if ($this->hasProfileChangedAtColumn) {
                $updateData['profile_changed_at'] = now();
                $this->profile_changed_at = now()->toDateTimeString();
            }

            DB::table('alumni')->where('id', $this->alumniId)->update($updateData);

            $this->profileComplete = $profileComplete;
            $this->editingProfile  = false;

            $keys = $this->editableKeys();
            $this->snapshot = array_combine($keys, array_map(fn($k) => $this->$k, $keys));

            $this->successMessage = $profileComplete
                ? 'Profile saved successfully.'
                : 'Progress saved. Complete all required fields to finish your profile.';

            $this->dispatch('show-toast', type: 'success', message: $this->successMessage);

            $fullName = trim($this->first_name . ' ' . $this->last_name);
            $this->dispatch(
                'profile-updated',
                completed: $profileComplete,
                name: $fullName !== '' ? $fullName : 'An alumnus',
                student_id: $this->student_id,
            );

            Log::info("Alumni profile saved | student_id: {$this->student_id} | complete: " . ($profileComplete ? 'yes' : 'no'));

        } catch (\Throwable $e) {
            Log::error('Alumni saveProfile error: ' . $e->getMessage());
            $this->errorMessage = 'Failed to save profile. Please try again.';
            $this->dispatch('show-toast', type: 'error', message: $this->errorMessage);
        }
    }

    // ══════════ EMPLOYMENT HELPERS ══════════
    protected function getCourseGroup(string $code): string
    {
        $c = strtoupper(trim($code));
        if (preg_match('/\b(BSIT|BSCS|BSIS|BSCPE|BSICT|IT|CS|CPE|ICT)\b/', $c))                    return 'technology';
        if (preg_match('/\b(BSN|BSMT)\b/', $c) || str_contains($c, 'NURS'))                          return 'nursing';
        if (preg_match('/\b(BSED|BEED|BELTE|BTTE|MAED)\b/', $c) || str_contains($c, 'EDUC') || str_contains($c, 'TEACH')) return 'education';
        if (preg_match('/\b(BSACCT|BSAC|BSMA|BSA)\b/', $c) || str_contains($c, 'ACCOUNT'))          return 'accounting';
        if (preg_match('/\b(BSBA|BSBM|BSENT|BSMGT|BSIB|BSHRM)\b/', $c) || str_contains($c, 'BUSINESS') || str_contains($c, 'MARKET')) return 'business';
        if (preg_match('/\b(BSCE|BSME|BSEE|BSECE|BSIE|BSCHE|BSEM|BSCPE)\b/', $c) || str_contains($c, 'ENGINEER')) return 'engineering';
        if (preg_match('/\b(BSPT|BSOT|BSRT|BSMLS|BSPHARM|BSRAD|BSMED)\b/', $c) || str_contains($c, 'PHARM') || str_contains($c, 'THERAP')) return 'healthcare';
        if (str_contains($c, 'CRIM'))  return 'criminology';
        if (preg_match('/\b(BSHTM|BSHM|BSTM|BSTHM)\b/', $c) || str_contains($c, 'HOSP') || str_contains($c, 'TOURISM') || str_contains($c, 'HOTEL')) return 'hospitality';
        if (str_contains($c, 'PSYCH')) return 'psychology';
        if (str_contains($c, 'COMM') || str_contains($c, 'JOURN') || str_contains($c, 'MEDIA') || str_contains($c, 'BROADCAST')) return 'communications';
        if (str_contains($c, 'ARCH'))  return 'architecture';
        if (str_contains($c, 'LAW') || str_contains($c, 'LLB') || $c === 'JD') return 'law';
        return 'general';
    }

    /**
     * Detects whether a job title is related to the alumni's program.
     *
     * Smarter handling for teaching / training roles: an alumnus who becomes
     * an instructor, professor, trainer, or teacher of a subject that matches
     * their own field is USING their degree — that should never come out as
     * "Not Related" just because the literal job title says "Instructor"
     * instead of, say, "Software Developer".
     *   - If the title clearly names the subject taught (e.g. "IT Instructor",
     *     "Accounting Professor") → fully related ('yes').
     *   - If it's an educator-type title with no explicit subject mentioned
     *     (e.g. plain "College Instructor", "Faculty") → 'partially', since we
     *     can't be 100% sure what they teach, but teaching itself still counts
     *     as applying their education.
     */
    protected function detectJobRelevance(string $title): string
    {
        $t = strtolower(trim($title));
        if (empty($t)) return '';
        $group = $this->getCourseGroup($this->course_code);

        $yes = [
            'technology'=>['developer','programmer','software','web dev','mobile app','network engineer','database admin','sysadmin','devops','cloud engineer','cybersecurity','data scientist','data analyst','ui/ux','it support','qa engineer','ml engineer','ai engineer','tech lead','systems analyst','ict','computer engineer','full stack','backend','frontend','it officer','helpdesk','network admin','it manager','it specialist','information technology','computer science','system developer','software engineer','it instructor','computer instructor','computer science instructor','it teacher','computer teacher','computer science teacher','programming instructor','ict instructor'],
            'nursing'=>['nurse','nursing','rn ','registered nurse','icu','er nurse','surgical nurse','ward nurse','dialysis nurse','pediatric nurse','public health nurse','head nurse','charge nurse','clinical nurse','operating room nurse','or nurse','nursing instructor','clinical instructor'],
            'education'=>['teacher','instructor','professor','tutor','faculty','educator','academic coordinator','school principal','curriculum developer','lecturer','teaching','special education','classroom teacher','school admin','school head','subject teacher','grade school','high school teacher','college instructor','tesda trainer','tesda teacher','vocational trainer','skills trainer'],
            'accounting'=>['accountant','auditor','cpa','tax specialist','bookkeeper','accounting','finance officer','budget analyst','payroll','internal auditor','external auditor','financial analyst','management accountant','cost accountant','revenue officer','accounting instructor','accounting professor'],
            'business'=>['marketing manager','sales manager','business analyst','hr officer','operations manager','management trainee','business owner','entrepreneur','brand manager','product manager','account manager','business development','merchandising','trade marketing','retail manager','commercial manager','business instructor','marketing instructor'],
            'engineering'=>['engineer','civil engineer','mechanical engineer','electrical engineer','structural engineer','construction manager','project engineer','quality engineer','process engineer','industrial engineer','plant engineer','design engineer','site engineer','engineering manager','chief engineer','engineering instructor','engineering professor'],
            'healthcare'=>['pharmacist','physical therapist','radiologic technologist','medical technologist','occupational therapist','respiratory therapist','dentist','dental','midwife','radiographer','med tech','pharmacy','therapist','clinical','allied health instructor'],
            'criminology'=>['police officer','pnp','nbi agent','forensic analyst','criminologist','jail officer','fire officer','law enforcement','detective','intelligence officer','criminal investigator','bureau of corrections','bfp','bucor','criminology instructor','criminology professor'],
            'hospitality'=>['hotel manager','chef','sous chef','restaurant manager','front desk officer','tour guide','event coordinator','flight attendant','travel agent','catering manager','hospitality manager','food and beverage','f&b manager','banquet manager','concierge','housekeeping manager','rooms division','hospitality instructor','culinary instructor'],
            'psychology'=>['psychologist','guidance counselor','social worker','mental health','psychiatry','behavior analyst','clinical psychologist','counseling','psychology','welfare officer','rehabilitation counselor','psychology instructor','psychology professor'],
            'communications'=>['journalist','reporter','broadcast journalist','public relations','pr officer','content writer','copywriter','social media manager','advertising','media planner','editor','communications officer','news writer','feature writer','anchor','media relations','communications specialist','communications instructor','journalism instructor'],
            'architecture'=>['architect','interior designer','urban planner','draftsman','cad operator','architectural','landscape architect','space planner','master planner','building designer','architectural designer','architecture instructor'],
            'law'=>['lawyer','attorney','legal officer','paralegal','court interpreter','judge','prosecutor','public attorney','legal counsel','law practitioner','notary public','legal consultant','solicitor','law professor','bar reviewer'],
            'general'=>[],
        ];
        $partial = [
            'technology'=>['it ','tech ','digital','computer','online','system','app ','platform','encoder','data entry','web','virtual','it-related','tech support','technical','network','technical writer','technical support','it coordinator','it staff'],
            'nursing'=>['health','clinic','hospital','patient','caregiver','medical','lab','healthcare','home care','nursing aide','orderly','health worker'],
            'education'=>['trainer','training','coach','mentor','facilitator','tesda','reviewer','subject matter','tutorial','educational','school','learning','academic','instruction','development officer'],
            'accounting'=>['finance','billing','cashier','collections','admin','accounts','financial','treasury','comptroller','budgeting','disbursement'],
            'business'=>['admin','officer','coordinator','supervisor','team lead','store manager','operations','logistics','supply chain','purchasing','procurement','inventory','warehouse','distribution'],
            'engineering'=>['technician','maintenance','inspector','surveyor','estimator','drafter','foreman','supervisor','technical','construction','fabricator'],
            'healthcare'=>['health','clinic','hospital','medical','lab','pharmacy','dental assistant','health aide','nursing aide','care worker','healthcare worker'],
            'criminology'=>['security guard','security officer','investigator','enforcement','warden','safety officer','risk','compliance','guard','patrol'],
            'hospitality'=>['food','service','hospitality','accommodation','barista','waiter','bartender','concierge','receptionist','housekeeping','tourism'],
            'psychology'=>['hr','recruiter','training officer','social services','welfare','employee relations','organizational','people','talent'],
            'communications'=>['writer','editor','media','content','marketing','communications','social media','digital marketing','creative','blogger','vlogger'],
            'architecture'=>['design','planning','drafting','construction','estimator','3d','rendering','visualization','autocad','revit','sketchup'],
            'law'=>['compliance','regulatory','policy','governance','legal assistant','court','justice','contracts','documentation','corporate secretary'],
            'general'=>['officer','staff','coordinator','supervisor','manager','specialist'],
        ];

        if (str_contains($t, 'tesda')) return $group === 'education' ? 'yes' : 'partially';

        foreach ($yes[$group] ?? [] as $kw) { if (str_contains($t, strtolower($kw))) return 'yes'; }
        foreach ($partial[$group] ?? [] as $kw) { if (str_contains($t, strtolower($kw))) return 'partially'; }

        // ── Cross-field teaching detection ──────────────────────────
        // Catches titles like "College Instructor" or "IT Trainer" for an
        // alumnus whose program group's own $yes/$partial lists above didn't
        // already match (e.g. a BSIT grad using a generic "Instructor" title
        // instead of "IT Instructor"). If the role is clearly an educator
        // role, check whether the subject matches their field; if the subject
        // is named, mark fully related, otherwise mark partially related
        // instead of falling through to "Not Related".
        $educatorKeywords = ['teacher','instructor','professor','faculty','lecturer','trainer','tutor','coach','mentor','educator','reviewer'];
        $isEducatorRole = false;
        foreach ($educatorKeywords as $kw) {
            if (str_contains($t, $kw)) { $isEducatorRole = true; break; }
        }

        if ($isEducatorRole) {
            $subjectKeywords = [
                'technology'     => ['it','ict','information technology','computer','computing','programming','software','system','network','cybersecurity','coding','web','database'],
                'nursing'        => ['nursing','health','clinical'],
                'education'      => [],
                'accounting'     => ['accounting','bookkeeping','finance','taxation'],
                'business'       => ['business','marketing','management','entrepreneurship'],
                'engineering'    => ['engineering','mechanical','electrical','civil','industrial'],
                'healthcare'     => ['pharmacy','therapy','medical','health'],
                'criminology'    => ['criminology','criminal justice','law enforcement'],
                'hospitality'    => ['hospitality','tourism','culinary','hotel'],
                'psychology'     => ['psychology','counseling','guidance'],
                'communications' => ['communication','journalism','media','broadcasting'],
                'architecture'   => ['architecture','design','drafting'],
                'law'            => ['law','legal'],
                'general'        => [],
            ];

            foreach ($subjectKeywords[$group] ?? [] as $kw) {
                if (str_contains($t, $kw)) return 'yes';
            }

            // Generic educator title (e.g. "College Instructor") with no named
            // subject — still applying their education by teaching, so treat
            // as partially related rather than outright "Not Related".
            return 'partially';
        }

        return 'no';
    }

    protected function buildJobOptions(): array
    {
        $map = [
            'technology'=>['Software Developer','Web Developer','Mobile App Developer','Systems Analyst','Database Administrator','Network Engineer','IT Support Specialist','Cybersecurity Analyst','Data Analyst / Data Scientist','UI / UX Designer','QA / Test Engineer','DevOps / Cloud Engineer','AI / ML Engineer','Technical Project Manager','IT Instructor / Computer Teacher'],
            'nursing'=>['Registered Nurse (RN)','ICU / Critical Care Nurse','ER / Emergency Nurse','Head Nurse / Supervisor','OR / Surgical Nurse','Pediatric Nurse','Public Health Nurse','Dialysis Nurse','OFW / International Nurse','Nursing Instructor / Clinical Instructor'],
            'education'=>['Elementary School Teacher','High School Teacher','Special Education Teacher','College Instructor','School Principal / Admin','Academic / Curriculum Coordinator','Tutor / Review Center Instructor'],
            'accounting'=>['Certified Public Accountant (CPA)','Auditor','Financial Analyst','Tax Specialist','Budget Analyst','Bookkeeper','Accounting Officer / Staff','Internal Auditor','Finance Manager','Accounting Instructor / Professor'],
            'business'=>['Marketing Manager / Officer','Sales Manager','Operations Manager','Business Analyst','HR Officer / HR Manager','Management Trainee','Administrative Officer','Entrepreneur / Business Owner','Business / Marketing Instructor'],
            'engineering'=>['Civil Engineer','Mechanical Engineer','Electrical Engineer','Electronics Engineer','Chemical Engineer','Industrial Engineer','Project Engineer','Quality Assurance Engineer','Construction Engineer / Manager','Engineering Instructor / Professor'],
            'healthcare'=>['Pharmacist','Physical Therapist','Radiologic Technologist','Medical Technologist','Occupational Therapist','Respiratory Therapist','Midwife','Dentist','Allied Health Instructor'],
            'criminology'=>['PNP Officer / Police Officer','NBI Agent','Criminologist','Jail Officer / BuCor','Forensic Analyst','Security Officer / Supervisor','Fire Officer (BFP)','Criminology Instructor / Professor'],
            'hospitality'=>['Hotel Manager','Front Desk Officer','Restaurant Manager','Chef / Sous Chef','Tour Guide','Event Coordinator','Flight Attendant / Cabin Crew','Travel Agent','Hospitality / Culinary Instructor'],
            'psychology'=>['Psychologist','Guidance Counselor','HR Officer / Recruiter','Social Worker','Mental Health Counselor','Training & Development Officer','Psychology Instructor / Professor'],
            'communications'=>['Journalist / Reporter','Public Relations Officer','Broadcast Journalist','Content Creator / Writer','Social Media Manager','Copywriter','Advertising Specialist','Media Planner','Communications / Journalism Instructor'],
            'architecture'=>['Architect','Interior Designer','Urban Planner','Draftsman / CAD Operator','Construction Manager','Architecture Instructor'],
            'law'=>['Lawyer / Attorney','Legal Officer','Court Interpreter','Paralegal','Legal Researcher','Law Professor / Bar Reviewer'],
            'general'=>['Administrative Officer','Office Staff','Customer Service Representative','Sales Representative'],
        ];
        $titles = $map[$this->getCourseGroup($this->course_code)] ?? $map['general'];
        $titles[] = 'Other';
        return $titles;
    }

    protected function loadEmploymentRecord(): void
    {
        $typeLabels   = ['full_time'=>'Full-Time','part_time'=>'Part-Time','contractual'=>'Contractual','project_based'=>'Project-Based','internship'=>'Internship'];
        $workLocLabels= ['local'=>'Local / PH','abroad'=>'OFW / Abroad'];
        $careerLabels = ['ofw'=>'OFW','freelancer'=>'Freelancer','entrepreneur'=>'Entrepreneur','career_shifter'=>'Career Shifter','industry_professional'=>'Industry Professional'];
        $eduLabels    = ['none'=>'None','pursuing_masteral'=>'Pursuing Masteral','pursuing_doctorate'=>'Pursuing Doctorate'];
        $relLabels    = ['yes'=>'Related to Program','no'=>'Not Related','partially'=>'Partially Related'];
        $unLabels     = ['seeking_employment'=>'Actively Seeking Employment','not_looking'=>'Not Currently Looking'];
        $statusLabels = ['employed'=>'Employed','self_employed'=>'Self-Employed','unemployed'=>'Unemployed'];

        $current = DB::table('employment_trackings')
            ->where('alumni_id', $this->alumniId)
            ->whereNull('deleted_at')
            ->latest('created_at')
            ->first();

        if ($current) {
            $cp = $current->career_path ? json_decode($current->career_path, true) : [];
            $this->currentRecord = [
                'employment_status'     => $statusLabels[$current->employment_status ?? ''] ?? ucfirst($current->employment_status ?? ''),
                'employment_status_raw' => $current->employment_status ?? '',
                'is_working'            => in_array($current->employment_status ?? '', ['employed','self_employed']),
                'company_name'          => $current->company_name ?? '',
                'job_title'             => $current->job_title ?? '',
                'employment_type'       => $typeLabels[$current->employment_type ?? ''] ?? '',
                'work_location'         => $workLocLabels[$current->work_location ?? ''] ?? ucfirst($current->work_location ?? ''),
                'career_path_labels'    => array_values(array_filter(array_map(fn($v) => $careerLabels[$v] ?? null, $cp))),
                'course_relevance'      => $relLabels[$current->course_relevance ?? ''] ?? '',
                'unemployment_status'   => $unLabels[$current->unemployment_status ?? ''] ?? '',
                'education_status'      => $eduLabels[$current->education_status ?? ''] ?? '',
                'submitted_at'          => $current->created_at ? \Carbon\Carbon::parse($current->created_at)->format('F j, Y') : '',
            ];

            $this->trackingId             = $current->id;
            $this->employment_changed_at  = $current->created_at ? \Carbon\Carbon::parse($current->created_at)->toDateTimeString() : null;
            $this->employment_status      = $current->employment_status   ?? '';
            $this->company_name           = $current->company_name        ?? '';
            $this->employment_type        = $current->employment_type     ?? '';
            $this->work_location          = $current->work_location       ?? '';
            $this->career_path            = $current->career_path ? json_decode($current->career_path, true) : [];
            $this->education_status       = $current->education_status    ?? '';
            $this->course_relevance       = $current->course_relevance    ?? '';
            $this->unemployment_status    = $current->unemployment_status ?? '';

            $loaded = $current->job_title ?? '';
            if ($loaded && !in_array($loaded, $this->jobOptions, true)) {
                $this->job_title = 'Other'; $this->custom_job_title = $loaded;
            } else {
                $this->job_title = $loaded; $this->custom_job_title = '';
            }
            $this->hasEmploymentRecord = true;
            $this->editingEmployment   = false;
        } else {
            $this->currentRecord         = null;
            $this->trackingId            = 0;
            $this->employment_changed_at = null;
            $this->job_title             = '';
            $this->custom_job_title      = '';
            $this->hasEmploymentRecord   = false;
            $this->editingEmployment     = false; // opened manually via icon
        }
    }

    public function startEditingEmployment(): void
    {
        $this->errorMessage = $this->successMessage = '';

        if ($this->hasEmploymentRecord && !$this->canEditEmployment) {
            $this->dispatch('show-toast', type: 'error', message: "You can only update your employment info once every 30 days. Please try again in {$this->employmentCooldownDaysLeft} day(s).");
            return;
        }

        $this->employmentSnapshot = [];
        foreach (self::EMP_SNAP_KEYS as $k) { $this->employmentSnapshot[$k] = $this->$k; }
        $this->editingEmployment = true;
    }

    public function cancelEditingEmployment(): void
    {
        $this->errorMessage = $this->successMessage = '';
        $this->resetValidation();
        if (!empty($this->employmentSnapshot)) {
            foreach ($this->employmentSnapshot as $k => $v) { $this->$k = $v; }
        }
        $this->editingEmployment = false;
    }

    public function updatedEmploymentStatus(): void
    {
        if ($this->employment_status === 'unemployed') {
            $this->company_name = $this->job_title = $this->employment_type =
            $this->work_location = $this->course_relevance = $this->custom_job_title = '';
            $this->career_path = [];
        } else {
            $this->unemployment_status = '';
        }
        $this->resetValidation();
    }

    public function updatedJobTitle(): void
    {
        if ($this->job_title === 'Other') {
            $this->course_relevance = $this->custom_job_title = '';
        } elseif ($this->job_title !== '') {
            $this->course_relevance = 'yes'; $this->custom_job_title = '';
        } else {
            $this->course_relevance = $this->custom_job_title = '';
        }
    }

    public function updatedCustomJobTitle(): void
    {
        if ($this->job_title === 'Other') {
            $this->course_relevance = $this->detectJobRelevance($this->custom_job_title);
        }
    }

    protected function hasEmploymentChanged(): bool
    {
        if (empty($this->employmentSnapshot)) return true;
        $isOther  = ($this->job_title === 'Other');
        $finalJob = $isOther ? $this->custom_job_title : $this->job_title;
        $snapJob  = $this->employmentSnapshot['job_title'] === 'Other'
            ? ($this->employmentSnapshot['custom_job_title'] ?? '')
            : ($this->employmentSnapshot['job_title'] ?? '');

        $current = [
            'employment_status'   => $this->employment_status,
            'company_name'        => strtoupper(trim($this->company_name)),
            'job_title'           => $finalJob,
            'employment_type'     => $this->employment_type,
            'work_location'       => $this->work_location,
            'career_path'         => $this->career_path,
            'education_status'    => $this->education_status,
            'course_relevance'    => $this->course_relevance,
            'unemployment_status' => $this->unemployment_status,
        ];
        $snap = [
            'employment_status'   => $this->employmentSnapshot['employment_status']   ?? '',
            'company_name'        => strtoupper(trim($this->employmentSnapshot['company_name'] ?? '')),
            'job_title'           => $snapJob,
            'employment_type'     => $this->employmentSnapshot['employment_type']     ?? '',
            'work_location'       => $this->employmentSnapshot['work_location']       ?? '',
            'career_path'         => $this->employmentSnapshot['career_path']         ?? [],
            'education_status'    => $this->employmentSnapshot['education_status']    ?? '',
            'course_relevance'    => $this->employmentSnapshot['course_relevance']    ?? '',
            'unemployment_status' => $this->employmentSnapshot['unemployment_status'] ?? '',
        ];
        sort($current['career_path']);
        sort($snap['career_path']);
        return $current !== $snap;
    }

    public function saveEmployment(): void
    {
        $this->errorMessage = $this->successMessage = '';

        if ($this->hasEmploymentRecord && !$this->canEditEmployment) {
            $this->errorMessage = "You can only update your employment info once every 30 days. Please try again in {$this->employmentCooldownDaysLeft} day(s).";
            $this->dispatch('show-toast', type: 'error', message: $this->errorMessage);
            return;
        }

        if ($this->trackingId !== 0 && !$this->hasEmploymentChanged()) {
            $this->dispatch('show-toast', type: 'error', message: 'No changes were made. Please edit a field before saving.');
            return;
        }

        $this->company_name = strtoupper(trim($this->company_name));
        $isOther = ($this->job_title === 'Other');
        if ($isOther) {
            $this->custom_job_title = trim($this->custom_job_title);
            if ($this->custom_job_title && !$this->course_relevance) {
                $this->course_relevance = $this->detectJobRelevance($this->custom_job_title);
            }
        }
        $working = in_array($this->employment_status, ['employed', 'self_employed']);
        if ($working && $this->job_title && !$isOther) { $this->course_relevance = 'yes'; }

        // NOTE: education_status is intentionally OPTIONAL — the alumnus can
        // simply leave "Further Education" unanswered if it doesn't apply.
        $rules = [
            'employment_status' => 'required|in:employed,self_employed,unemployed',
            'education_status'  => 'nullable|in:none,pursuing_masteral,pursuing_doctorate',
        ];
        $msgs = [
            'employment_status.required' => 'Please select your employment status.',
        ];
        if ($working) {
            $rules += [
                'company_name'    => 'required|string|max:255',
                'job_title'       => 'required|string|max:255',
                'employment_type' => 'required|in:full_time,part_time,contractual,project_based,internship',
                'work_location'   => 'required|in:local,abroad',
            ];
            $msgs += [
                'company_name.required'    => 'Company / Business name is required.',
                'job_title.required'       => 'Please select a job title.',
                'employment_type.required' => 'Please select employment type.',
                'work_location.required'   => 'Please select work location.',
            ];
            if ($isOther) {
                $rules['custom_job_title'] = ['required', 'string', 'max:255', 'regex:/[A-Za-z]/'];
                $msgs['custom_job_title.required'] = 'Please specify your job title.';
                $msgs['custom_job_title.regex']    = 'Job title cannot be just numbers — please enter a valid job title.';
            }
        }
        if ($this->employment_status === 'unemployed') {
            $rules['unemployment_status'] = 'required|in:seeking_employment,not_looking';
            $msgs['unemployment_status.required'] = 'Please select your unemployment status.';
        }

        try {
            $this->validate($rules, $msgs);
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first();
            $this->dispatch('show-toast', type: 'error', message: $first ?: 'Please check the highlighted fields.');
            throw $e;
        }

        $finalJobTitle  = $isOther ? $this->custom_job_title : $this->job_title;
        $finalRelevance = $working ? ($this->course_relevance ?: 'no') : null;

        try {
            $now   = now();
            $isNew = ($this->trackingId === 0);

            $data = [
                'alumni_id'           => $this->alumniId,
                'employment_status'   => $this->employment_status,
                'education_status'    => $this->education_status ?: null,
                'company_name'        => $working ? ($this->company_name ?: null) : null,
                'job_title'           => $working ? ($finalJobTitle ?: null) : null,
                'employment_type'     => $working ? ($this->employment_type ?: null) : null,
                'work_location'       => $working ? ($this->work_location ?: null) : null,
                'date_hired'          => null,
                'career_path'         => $working && count($this->career_path) ? json_encode(array_values($this->career_path)) : null,
                'course_relevance'    => $finalRelevance,
                'unemployment_status' => $this->employment_status === 'unemployed' ? ($this->unemployment_status ?: null) : null,
                'created_at'          => $now,
                'updated_at'          => $now,
            ];

            DB::transaction(function () use ($data, $now) {
                if ($this->trackingId) {
                    DB::table('employment_trackings')->where('id', $this->trackingId)->update(['deleted_at' => $now, 'updated_at' => $now]);
                }
                $this->trackingId = DB::table('employment_trackings')->insertGetId($data);
            });

            $this->successMessage = $isNew
                ? 'Employment information submitted successfully!'
                : 'Employment information updated successfully!';

            $this->dispatch('show-toast', type: 'success', message: $this->successMessage);

            // ── FIX: this used to dispatch a bare 'refresh-alumni-notifs'
            //    event with no payload, and the layout's matching listener
            //    only called _fetch() (re-reads existing notifications) —
            //    it never actually SAVED a new notification row. That's why
            //    updating employment never produced a bell notification.
            //
            //    Now we dispatch 'employment-updated' with the actual
            //    status/company/job details, which the layout's dedicated
            //    listener uses to create a real notification via
            //    _saveAlumniNotif(), the same way profile-updated and
            //    event-announced already do. ──
            $statusLabel = match ($this->employment_status) {
                'employed'      => 'Employed',
                'self_employed' => 'Self-Employed',
                'unemployed'    => 'Unemployed',
                default         => ucfirst($this->employment_status),
            };

            $this->dispatch(
                'employment-updated',
                is_new: $isNew,
                status: $statusLabel,
                company: $working ? ($this->company_name ?: '') : '',
                job_title: $working ? ($finalJobTitle ?: '') : '',
            );

            $this->loadEmploymentRecord();

            Log::info("Employment saved | alumni_id:{$this->alumniId} | status:{$this->employment_status}");

        } catch (\Throwable $e) {
            Log::error('Employment save error: ' . $e->getMessage());
            $this->errorMessage = 'Failed to save employment info. Please try again.';
            $this->dispatch('show-toast', type: 'error', message: $this->errorMessage);
        }
    }
}; ?>

<div class="flex flex-col" style="height:calc(100vh - 180px);max-height:calc(100vh - 180px);overflow:hidden;">

<style>
.ai-tooltip {
    position: absolute; top: calc(100% + 8px); right: 0;
    background: #111827; color: #fff; font-size: 10px; font-weight: 700;
    letter-spacing: .05em; padding: 4px 10px; border-radius: 6px; white-space: nowrap;
    pointer-events: none; opacity: 0; transform: translateY(-4px);
    transition: opacity .15s ease, transform .15s ease; z-index: 200;
    box-shadow: 0 2px 8px rgba(0,0,0,.18);
}
.ai-tooltip::after {
    content: ''; position: absolute; bottom: 100%; right: 10px;
    border: 4px solid transparent; border-bottom-color: #111827;
}
.group:hover .ai-tooltip { opacity: 1; transform: translateY(0); }

@media (max-width: 1023px), (hover: none) {
    .ai-tooltip { display: none !important; }
}

#profile-toast {
    position: fixed; top: 20px; left: 50%;
    transform: translateX(-50%) translateY(-90px); z-index: 9999;
    width: calc(100% - 32px); max-width: 420px; pointer-events: none; opacity: 0;
    transition: transform .35s cubic-bezier(.34,1.56,.64,1), opacity .3s ease;
}
#profile-toast.toast-visible { transform: translateX(-50%) translateY(0); opacity: 1; pointer-events: auto; }
#profile-toast.toast-hiding  { transform: translateX(-50%) translateY(-90px); opacity: 0; pointer-events: none; }
.toast-inner {
    display: flex; align-items: center; gap: 10px; padding: 12px 18px; border-radius: 12px;
    font-size: 13.5px; font-weight: 600; line-height: 1.4; background: #ffffff; box-shadow: 0 8px 24px rgba(0,0,0,.08);
}
.toast-success { color: #15803d; border: 1.5px solid #16a34a; }
.toast-success .toast-icon { display: none; }
.toast-success .toast-close { color: rgba(21,128,61,.45); }
.toast-success .toast-close:hover { color: #15803d; }
.toast-error { color: #b91c1c; border: 1.5px solid #dc2626; }
.toast-error .toast-icon { display: none; }
.toast-error .toast-close { color: rgba(185,28,28,.45); }
.toast-error .toast-close:hover { color: #b91c1c; }
.toast-close {
    margin-left: auto; flex-shrink: 0; background: none; border: none; cursor: pointer;
    font-size: 14px; padding: 2px 4px; border-radius: 4px; transition: color .15s; line-height: 1;
}

.field-label {
    font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
    color: #333333; margin: 0; line-height: 1.4; padding-top: 1px;
}
.field-value { font-size: 1.05rem; font-weight: 600; color: #333333; word-break: break-word; margin: 0; }
.field-value-empty { font-size: 0.95rem; font-style: italic; font-weight: 400; color: #333333; margin: 0; }

.addr-toggle {
    display: inline-flex; align-items: center; background: #f3f4f6; border: 1px solid #dcdcdc;
    border-radius: 999px; padding: 2px; gap: 2px; flex-shrink: 0;
}
.addr-toggle-opt {
    display: inline-flex; align-items: center; font-size: 10.5px; font-weight: 700;
    letter-spacing: .02em; color: #333333; background: transparent; border: none; border-radius: 999px;
    padding: 4px 11px; cursor: pointer; transition: background .15s, color .15s;
}
.addr-toggle-opt:hover { color: #333333; }
.addr-toggle-opt.is-active { background: #7a3f91; color: #ffffff; box-shadow: 0 1px 3px rgba(122,63,145,.35); }
.addr-select {
    width: 100%; box-sizing: border-box; font-size: 1.05rem; font-weight: 600; color: #333333;
    background: #ffffff; border: 1.5px solid #d6d6d6; border-radius: 0.5rem; padding: 0.45rem 0.65rem;
    outline: none; transition: border-color .15s, background .15s, box-shadow .15s;
}
.addr-select:hover { border-color: #b9b9b9; }
.addr-select:focus { border-color: #7a3f91; box-shadow: 0 0 0 3px rgba(122,63,145,.12); }
.addr-select:disabled { background: #f3f4f6; color: #333333; cursor: not-allowed; border-color: #e5e7eb; }
.field-input {
    width: 100%; box-sizing: border-box; font-size: 1.05rem; font-weight: 600; color: #333333;
    background: #ffffff; border: 1.5px solid #d6d6d6; border-radius: 0.5rem; padding: 0.45rem 0.65rem;
    outline: none; transition: border-color .15s, background .15s, box-shadow .15s;
}
.field-input:hover { border-color: #b9b9b9; }
.field-input:focus { border-color: #7a3f91; box-shadow: 0 0 0 3px rgba(122,63,145,.12); }
.field-input.field-error { border-color: #f87171; }
.field-input:disabled { background: #f3f4f6; color: #333333; cursor: not-allowed; -webkit-text-fill-color: #333333; opacity: 1; }

input[type="date"].field-input {
    position: relative;
    display: block;
    width: 100%;
    min-width: 100%;
    box-sizing: border-box;
    font-variant-numeric: tabular-nums;
    font-size: 0.95rem;
    padding: 0.45rem 2rem 0.45rem 0.55rem;
    color: #333333;
    color-scheme: light;
}
input[type="date"].field-input::-webkit-datetime-edit {
    padding-right: 0.15rem;
}
input[type="date"].field-input::-webkit-datetime-edit-fields-wrapper {
    display: flex;
}
input[type="date"].field-input::-webkit-calendar-picker-indicator {
    position: absolute;
    right: 0.4rem;
    top: 50%;
    transform: translateY(-50%);
    width: 15px;
    height: 15px;
    padding: 0;
    margin: 0;
    cursor: pointer;
    opacity: 0.7;
    background: transparent;
}
input[type="date"].field-input:disabled {
    color: #333333;
    -webkit-text-fill-color: #333333;
}

.field-block { padding-top: 2px; }

/* ═══════════ Compact suffix dropdown (Father's Name) ═══════════
   Same short height as .field-input / .addr-select — NOT the tall
   60px floating-label trigger used on the Register Alumni page. */
.suffix-compact-wrap { position: relative; }
.suffix-compact-trigger {
    width: 100%; box-sizing: border-box; display: flex; align-items: center; justify-content: center;
    gap: 6px; font-size: 1.05rem; font-weight: 600; color: #333333; text-align: center;
    background: #ffffff; border: 1.5px solid #d6d6d6; border-radius: 0.5rem; padding: 0.45rem 0.65rem;
    outline: none; cursor: pointer; transition: border-color .15s, background .15s, box-shadow .15s;
}
.suffix-compact-trigger:hover { border-color: #b9b9b9; }
.suffix-compact-trigger.open,
.suffix-compact-trigger.has-value { border-color: #7a3f91; }
.suffix-compact-trigger.open { box-shadow: 0 0 0 3px rgba(122,63,145,.12); }
.suffix-compact-trigger .sfx-placeholder { color: #333333; font-weight: 400; font-style: italic; font-size: 0.95rem; }
.suffix-compact-trigger .sfx-chevron { font-size: 0.65rem; opacity: .55; transition: transform .18s; margin-left: auto; }
.suffix-compact-trigger.open .sfx-chevron { transform: rotate(180deg); }

.suffix-compact-panel {
    position: absolute; top: calc(100% + 6px); left: 0; width: 100%;
    background: #fff; border: 1.5px solid #E8E0F0; border-radius: 12px;
    box-shadow: 0 10px 28px rgba(122,63,145,.16);
    z-index: 220; overflow: hidden;
}
.suffix-compact-list {
    max-height: 176px; overflow-y: auto; padding: 5px;
    scrollbar-width: thin; scrollbar-color: #d4b8e8 transparent;
}
.suffix-compact-list::-webkit-scrollbar { width: 5px; }
.suffix-compact-list::-webkit-scrollbar-thumb { background: #d4b8e8; border-radius: 99px; }
.suffix-compact-item {
    width: 100%; text-align: left; display: flex; align-items: center; gap: 8px;
    padding: 6px 9px; border-radius: 7px; border: none; background: transparent;
    font-size: .82rem; font-weight: 600; color: #333; cursor: pointer;
    transition: background .1s, color .1s;
}
.suffix-compact-item:hover { background: #F5F0FA; color: #7A3F91; }
.suffix-compact-item.is-selected { background: #7A3F91; color: #fff; }
.suffix-compact-footer {
    padding: 5px 8px; border-top: 1px solid #F0E6F8; background: #FDFAFF;
}
.suffix-compact-clear {
    width: 100%; text-align: center; font-size: .7rem; font-weight: 700; color: #333333;
    background: none; border: none; cursor: pointer; padding: 4px 8px; border-radius: 6px;
    transition: color .12s, background .12s;
}
.suffix-compact-clear:hover { color: #dc2626; background: #fef2f2; }

/* ═══════════ Employment Fullscreen Editor ═══════════
   Solid purple header with icon-only info button (no tooltip — see JS
   below which strips hover text on all viewports per user request).
   Light gray body sized to content (no forced full-height stretch). */
.emp-editor-header {
    background: #7a3f91;
}
.emp-editor-header h2,
.emp-editor-header p { color: #ffffff; }
.emp-editor-header p { color: rgba(255,255,255,.75); }
.emp-editor-body {
    background: #f3f4f6;
}

.emp-info-wrap { position: relative; display: inline-flex; }
.emp-info-btn {
    width: 30px; height: 30px; border-radius: 9999px;
    display: inline-flex; align-items: center; justify-content: center;
    background: rgba(255,255,255,.15); color: #ffffff; border: none; cursor: default;
}

.emp-hdr-btn-wrap { position: relative; display: inline-flex; }
.emp-hdr-btn {
    width: 38px; height: 38px; border-radius: 0.5rem;
    display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer; transition: background .15s, transform .1s;
}
.emp-hdr-btn:active { transform: scale(.95); }
.emp-hdr-tip {
    position: absolute; top: calc(100% + 8px); left: 50%; transform: translateX(-50%) translateY(-4px);
    background: #111827; color: #ffffff; font-size: 11px; font-weight: 700;
    letter-spacing: .03em; padding: 5px 11px; border-radius: 8px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity .15s ease, transform .15s ease;
    z-index: 210; box-shadow: 0 2px 8px rgba(0,0,0,.18);
}
.emp-hdr-tip::after {
    content: ''; position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%);
    border: 4px solid transparent; border-bottom-color: #111827;
}
.emp-hdr-btn-wrap:hover .emp-hdr-tip { opacity: 1; transform: translateX(-50%) translateY(0); }

@media (max-width: 1023px), (hover: none) {
    .emp-hdr-tip { display: none !important; }
}


.emp-card {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 0.75rem;
    display: flex;
    flex-direction: column;
}
/* Section headers — clearly bigger than the field labels inside the card,
   so it reads as a header, not just another label. */
.emp-card-title {
    font-size: 1rem;
    font-weight: 700;
    text-transform: none;
    letter-spacing: 0;
    color: #3d2a49;
    padding: 0.7rem 0.9rem;
    border-bottom: 1px solid #f0eaf4;
    flex-shrink: 0;
    display: flex;
    align-items: baseline;
    gap: 6px;
}
/* Back button — plain, sits at the very bottom of column 1 (after the
   Further Education card), small and left-aligned (not full-width).
   No hover-shift/box-shadow effects — just the icon swapping to a
   spinner while $set is loading. */
.emp-back-btn-bottom {
    display: inline-flex; align-items: center; justify-content: center; gap: 6px;
    width: auto; height: 32px;
    padding: 0 14px;
    border-radius: 0.6rem;
    background: #7a3f91; color: #ffffff;
    font-size: 12px; font-weight: 700;
    border: none; cursor: pointer;
    align-self: flex-start;
}
.emp-back-btn-bottom:disabled { opacity: .7; cursor: wait; }
.emp-card-body { padding: 0.7rem 0.85rem; }
.emp-radio-tile {
    display: flex; align-items: center; gap: 6px; cursor: pointer;
    font-size: 0.8rem; font-weight: 500; color: #333333;
    padding: 5px 9px; border-radius: 999px; border: 1.5px solid #e5e7eb;
    transition: border-color .15s, background .15s;
    white-space: nowrap;
}
.emp-radio-tile:hover { border-color: #c9b3d6; }
.emp-radio-tile input:checked ~ span { color: #5e2f72; font-weight: 600; }
.emp-input-sm {
    width: 100%; box-sizing: border-box; font-size: 0.85rem; font-weight: 500; color: #1f2937;
    background: #f9fafb; border: 1.5px solid #e5e7eb; border-radius: 0.5rem; padding: 0.4rem 0.6rem;
    outline: none; transition: border-color .15s, background .15s, box-shadow .15s;
}
.emp-input-sm:hover { border-color: #cbd5e1; }
.emp-input-sm:focus { border-color: #7a3f91; box-shadow: 0 0 0 3px rgba(122,63,145,.1); background: #fff; }
/* Softer, more readable field labels — no longer overly bold/loud */
.emp-label-sm {
    display: block; font-size: 0.72rem; font-weight: 600; text-transform: uppercase;
    letter-spacing: .025em; color: #333333; margin-bottom: 0.35rem;
}
</style>

{{-- ── TOAST ── --}}
<div id="profile-toast" role="alert" aria-live="polite">
    <div class="toast-inner toast-success" id="profile-toast-inner">
        <i class="fas fa-circle-check toast-icon" id="profile-toast-icon"></i>
        <span id="profile-toast-msg">Profile saved successfully.</span>
        <button class="toast-close" onclick="hideProfileToast()" aria-label="Dismiss"><i class="fas fa-xmark"></i></button>
    </div>
</div>

<script>
(function () {
    let _toastTimer = null;
    window.showProfileToast = function (type, message) {
        const toast = document.getElementById('profile-toast');
        const inner = document.getElementById('profile-toast-inner');
        const icon  = document.getElementById('profile-toast-icon');
        const msg   = document.getElementById('profile-toast-msg');
        if (!toast) return;
        clearTimeout(_toastTimer);
        toast.classList.remove('toast-visible', 'toast-hiding');
        msg.textContent = message;
        inner.className = 'toast-inner ' + (type === 'success' ? 'toast-success' : 'toast-error');
        icon.className  = 'toast-icon fas ' + (type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation');
        void toast.offsetWidth;
        toast.classList.add('toast-visible');
        _toastTimer = setTimeout(window.hideProfileToast, 3500);
    };
    window.hideProfileToast = function () {
        const toast = document.getElementById('profile-toast');
        if (!toast) return;
        clearTimeout(_toastTimer);
        toast.classList.remove('toast-visible');
        toast.classList.add('toast-hiding');
        setTimeout(() => toast.classList.remove('toast-hiding'), 400);
    };
})();

(function bindAlumniProfileLivewireListeners() {
    if (window.__philcstProfileListenersBound) return;
    window.__philcstProfileListenersBound = true;

    function bind() {
        Livewire.on('show-toast', ({ type, message }) => { window.showProfileToast(type, message); });
        Livewire.on('refresh-alumni-notifs', () => {
            const s = window.__safeAlumniNotifsStore ? window.__safeAlumniNotifsStore() : null;
            if (s) s._fetch();
        });
    }

    if (window.Livewire && typeof window.Livewire.on === 'function') {
        bind();
    } else {
        document.addEventListener('livewire:initialized', bind, { once: true });
    }
})();

window._phAddressDataPromise = null;
function loadPhAddressData(force) {
    if (force) window._phAddressDataPromise = null;
    if (!window._phAddressDataPromise) {
        const bases = [
            'https://cdn.jsdelivr.net/gh/isaacdarcilla/philippine-addresses@main/',
            'https://raw.githubusercontent.com/isaacdarcilla/philippine-addresses/main/',
        ];
        const bust = '?t=' + Date.now();

        const tryBase = (i) => {
            const base = bases[i];
            const getJson = (file) => fetch(base + file + bust).then(r => {
                if (!r.ok) throw new Error('Failed to fetch ' + file + ' (HTTP ' + r.status + ') from ' + base);
                return r.json();
            });
            return Promise.all([
                getJson('province.json'), getJson('city.json'), getJson('barangay.json'),
            ]).then(([provinces, cities, barangays]) => {
                if (!Array.isArray(provinces) || !provinces.length) throw new Error('Province list came back empty.');
                const cmp = (a, b) => String(a).localeCompare(String(b), undefined, { sensitivity: 'base' });
                provinces.sort((a, b) => cmp(a.province_name, b.province_name));
                cities.sort((a, b) => cmp(a.city_name, b.city_name));
                barangays.sort((a, b) => cmp(a.brgy_name, b.brgy_name));
                return { provinces, cities, barangays };
            }).catch((err) => {
                console.warn('PH address source failed (' + base + '):', err);
                if (i + 1 < bases.length) return tryBase(i + 1);
                throw err;
            });
        };

        window._phAddressDataPromise = tryBase(0);
    }
    return window._phAddressDataPromise;
}

function phAddressEscapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
}

function phAddress(initial) {
    return {
        loading: true, loadFailed: false, provinces: [], cities: [], barangays: [],
        mode: 'dropdown',
        selected: { provinceCode: '', cityCode: '', barangayCode: '' },
        initialValues: initial,

        get filteredCities() {
            if (!this.selected.provinceCode) return [];
            return this.cities.filter(c => c.province_code === this.selected.provinceCode);
        },
        get filteredBarangays() {
            if (!this.selected.cityCode) return [];
            return this.barangays.filter(b => b.city_code === this.selected.cityCode);
        },

        provinceOptionsHtml() {
            let html = '<option value="">Select Province</option>';
            for (const p of this.provinces) {
                html += `<option value="${phAddressEscapeHtml(p.province_code)}">${phAddressEscapeHtml(p.province_name)}</option>`;
            }
            return html;
        },
        cityOptionsHtml() {
            let html = '<option value="">Select Municipality / City</option>';
            for (const c of this.filteredCities) {
                html += `<option value="${phAddressEscapeHtml(c.city_code)}">${phAddressEscapeHtml(c.city_name)}</option>`;
            }
            return html;
        },
        barangayOptionsHtml() {
            let html = '<option value="">Select Barangay</option>';
            for (const b of this.filteredBarangays) {
                html += `<option value="${phAddressEscapeHtml(b.brgy_code)}">${phAddressEscapeHtml(b.brgy_name)}</option>`;
            }
            return html;
        },
        applyOptions(el, html, value) {
            if (el.__lastHtml !== html) { el.innerHTML = html; el.__lastHtml = html; }
            if (el.value !== (value || '')) el.value = value || '';
        },

        setMode(newMode) {
            if (this.mode === newMode) return;
            this.mode = newMode;
            if (newMode === 'manual') {
                this.selected.provinceCode = '';
                this.selected.cityCode = '';
                this.selected.barangayCode = '';
            }
        },

        onProvinceChange() {
            const p = this.provinces.find(p => p.province_code === this.selected.provinceCode);
            this.$wire.set('address_province', p ? p.province_name.toUpperCase() : '');
            this.selected.cityCode = ''; this.selected.barangayCode = '';
            this.$wire.set('address_municipality', ''); this.$wire.set('address_barangay', '');
        },
        onCityChange() {
            const c = this.cities.find(c => c.city_code === this.selected.cityCode);
            this.$wire.set('address_municipality', c ? c.city_name.toUpperCase() : '');
            this.selected.barangayCode = ''; this.$wire.set('address_barangay', '');
        },
        onBarangayChange() {
            const b = this.barangays.find(b => b.brgy_code === this.selected.barangayCode);
            this.$wire.set('address_barangay', b ? b.brgy_name.toUpperCase() : '');
        },
        retryLoad() { this.loading = true; this.loadFailed = false; this.loadData(true); },
        loadData(force) {
            loadPhAddressData(force).then(({ provinces, cities, barangays }) => {
                this.provinces = provinces; this.cities = cities; this.barangays = barangays;
                this.loading = false; this.loadFailed = false;
                const norm = v => (v || '').trim().toUpperCase();
                const savedProvince = norm(this.initialValues.province);
                const savedCity     = norm(this.initialValues.municipality);
                const savedBarangay = norm(this.initialValues.barangay);
                let matchedProvince = false, matchedCity = false, matchedBarangay = false;
                if (savedProvince) {
                    const p = provinces.find(p => norm(p.province_name) === savedProvince);
                    if (p) { this.selected.provinceCode = p.province_code; matchedProvince = true; }
                }
                if (savedCity && this.selected.provinceCode) {
                    const c = cities.find(c => c.province_code === this.selected.provinceCode && norm(c.city_name) === savedCity);
                    if (c) { this.selected.cityCode = c.city_code; matchedCity = true; }
                }
                if (savedBarangay && this.selected.cityCode) {
                    const b = barangays.find(b => b.city_code === this.selected.cityCode && norm(b.brgy_name) === savedBarangay);
                    if (b) { this.selected.barangayCode = b.brgy_code; matchedBarangay = true; }
                }
                if ((savedProvince && !matchedProvince) || (savedCity && !matchedCity) || (savedBarangay && !matchedBarangay)) {
                    this.mode = 'manual';
                }
            }).catch((err) => {
                console.error('PH address list failed to load:', err);
                this.loading = false; this.loadFailed = true;
                this.mode = 'manual';
            });
        },
        init() { this.loadData(false); },
    };
}
</script>

{{-- ══ MAIN LAYOUT ══ --}}
<div class="flex flex-col flex-1 gap-3 px-4 sm:px-6 lg:px-10 pt-3 sm:pt-4 pb-3 max-w-screen-2xl mx-auto w-full min-h-0"
     x-data="{ showProfileConfirm: false, showEmpConfirm: false }">

    {{-- ── PAGE HEADER ── --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 flex-shrink-0">

        <div class="flex items-center gap-3 sm:gap-4">
            <div class="w-9 h-9 sm:w-10 sm:h-10 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md bg-gradient-to-br from-[#7a3f91] to-[#5e2f72]">
                <i class="fa-solid fa-id-card-clip text-white text-sm sm:text-base"></i>
            </div>
            <div>
                <div class="flex items-center gap-2.5 flex-wrap">
                    <h1 class="text-base sm:text-lg font-semibold tracking-tight text-gray-900">Professional &amp; Personal Information</h1>
                    @if($editingProfile)
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase tracking-widest bg-amber-100 text-amber-700 border border-amber-300">
                            <i class="fas fa-pen text-[9px]"></i> Edit Mode
                        </span>
                    @endif
                </div>
                <p class="text-[11px] sm:text-xs leading-relaxed mt-0.5 text-gray-800">
                    @if($editingProfile)
                        Complete your details below. Fields marked <span class="text-red-500 font-semibold">*</span> are required.
                    @else
                        Keep your alumni profile accurate and up to date.
                    @endif
                </p>
            </div>
        </div>

        {{-- Right: action buttons --}}
        <div class="flex items-center gap-2 flex-shrink-0 self-end sm:self-auto">
            @if(!$editingProfile)
                {{-- Edit Profile --}}
                <button wire:click="startEditingProfile"
                        wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-wait" wire:target="startEditingProfile"
                        type="button"
                        class="group relative w-9 h-9 rounded-lg flex items-center justify-center
                               bg-[#7a3f91] border border-[#5e2f72] text-white
                               hover:bg-[#6c3680] transition active:scale-95 cursor-pointer shadow-sm">
                    <span class="ai-tooltip">Edit Profile</span>
                    <span wire:loading.remove wire:target="startEditingProfile"><i class="fas fa-pen text-sm"></i></span>
                    <span wire:loading wire:target="startEditingProfile">
                        <i class="fas fa-spinner fa-spin text-sm"></i>
                    </span>
                </button>

                {{-- Update Employment icon --}}
                <button wire:click="startEditingEmployment"
                        wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-wait" wire:target="startEditingEmployment"
                        type="button"
                        class="group relative w-9 h-9 rounded-lg flex items-center justify-center
                               bg-blue-500 border border-blue-600 text-white
                               hover:bg-blue-600 transition active:scale-95 cursor-pointer shadow-sm">
                    <span class="ai-tooltip">Update Employment</span>
                    <span wire:loading.remove wire:target="startEditingEmployment"><i class="fa-solid fa-briefcase text-sm"></i></span>
                    <span wire:loading wire:target="startEditingEmployment">
                        <i class="fas fa-spinner fa-spin text-sm"></i>
                    </span>
                </button>
            @else
                <button type="button" @click="showProfileConfirm = true"
                        class="group relative w-9 h-9 rounded-lg flex items-center justify-center
                               bg-emerald-500 border border-emerald-600 text-white
                               hover:bg-emerald-600 transition active:scale-95 cursor-pointer shadow-sm">
                    <span class="ai-tooltip">Save Profile</span>
                    <i class="fas fa-floppy-disk text-sm"></i>
                </button>
                @if($profileComplete)
                    <button wire:click="cancelEditingProfile"
                            wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-wait" wire:target="cancelEditingProfile"
                            type="button"
                            class="group relative w-9 h-9 rounded-lg flex items-center justify-center
                                   bg-red-50 border border-red-200 text-red-500
                                   hover:bg-red-100 hover:border-red-300 transition active:scale-95 cursor-pointer shadow-sm">
                        <span class="ai-tooltip">Cancel</span>
                        <span wire:loading.remove wire:target="cancelEditingProfile"><i class="fas fa-xmark text-sm"></i></span>
                        <span wire:loading wire:target="cancelEditingProfile">
                            <i class="fas fa-spinner fa-spin text-sm"></i>
                        </span>
                    </button>
                @endif
            @endif
        </div>

    </div>

    {{-- ══ INLINE ALERTS ══ --}}
    @if($errorMessage)
        <div class="rounded-xl px-4 py-2 text-xs border bg-red-50 text-red-600 border-red-200 flex items-center gap-2 flex-shrink-0">
            <i class="fas fa-circle-exclamation flex-shrink-0"></i><span>{{ $errorMessage }}</span>
        </div>
    @endif
    @if(!$profileComplete && !$editingProfile)
        <div class="rounded-xl px-4 py-2 text-xs border bg-yellow-50 text-yellow-800 border-yellow-200 flex items-center gap-2 flex-shrink-0">
            <i class="fas fa-triangle-exclamation flex-shrink-0"></i>
            <span>Your profile is incomplete. Click the <strong>edit</strong> button to fill in all required fields.</span>
        </div>
    @endif
    @if($profileComplete && !$editingProfile && !$this->canEditProfile)
        <div class="rounded-xl px-4 py-2 text-xs border bg-gray-50 text-gray-600 border-gray-200 flex items-center gap-2 flex-shrink-0">
            <i class="fas fa-lock flex-shrink-0"></i>
            <span>Profile is locked. You can update it again in {{ $this->profileCooldownDaysLeft }} day(s).</span>
        </div>
    @endif

    {{-- ══ CONTENT BLOCK ══ --}}
    <div class="flex-1 min-h-0 flex flex-col rounded-xl overflow-hidden border border-[#E8E0F0] shadow-sm">

        <div class="bg-white flex-1 overflow-y-auto">

            {{-- ROW 1: Student ID | Student's Name --}}
            <div class="flex flex-col lg:flex-row border-b border-gray-200">
                <div class="w-full lg:w-[200px] lg:flex-none lg:border-r border-b lg:border-b-0 border-gray-200">
                    <div class="px-3 py-1.5 bg-gray-50 border-b border-gray-100"><p class="field-label">Student ID</p></div>
                    <div class="px-3 py-2.5">
                        <div class="flex flex-col gap-1 field-block">
                            <p class="field-label"></p>
                            @if($student_id)<p class="field-value font-mono tracking-wide">{{ strtoupper($student_id) }}</p>
                            @else<p class="field-value-empty">Not provided</p>@endif
                        </div>
                    </div>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="px-3 py-1.5 bg-gray-50 border-b border-gray-100"><p class="field-label">Student's Name</p></div>
                    <div class="px-3 py-2.5">
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                            <div class="flex flex-col gap-1 field-block">
                                <p class="field-label">Last Name</p>
                                @if($last_name)<p class="field-value">{{ strtoupper($last_name) }}</p>@else<p class="field-value-empty">Not provided</p>@endif
                            </div>
                            <div class="flex flex-col gap-1 field-block">
                                <p class="field-label">Given Name</p>
                                @if($first_name)<p class="field-value">{{ strtoupper($first_name) }}</p>@else<p class="field-value-empty">Not provided</p>@endif
                            </div>
                            <div class="flex flex-col gap-1 field-block">
                                <p class="field-label">Middle Name</p>
                                @if($middle_initial)<p class="field-value">{{ strtoupper($middle_initial) }}</p>@else<p class="field-value-empty">Not provided</p>@endif
                            </div>
                            <div class="flex flex-col gap-1 field-block">
                                <p class="field-label">Ext.</p>
                                @if($suffix)<p class="field-value">{{ strtoupper($suffix) }}</p>@else<p class="field-value-empty">Not provided</p>@endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ROW 2: Student's Data | Father's Name | Mother's Maiden Name --}}
            <div class="flex flex-col xl:flex-row border-b border-gray-200">

                <div class="w-full xl:w-[340px] xl:flex-none xl:border-r border-b xl:border-b-0 border-gray-200">
                    <div class="px-3 py-1.5 bg-gray-50 border-b border-gray-100"><p class="field-label">Student's Data</p></div>
                    <div class="px-3 py-2.5 flex flex-col gap-2.5">
                        <div class="grid grid-cols-1 sm:grid-cols-[auto_1fr] gap-2.5">
                            <div class="flex flex-col gap-1 field-block sm:min-w-[92px]">
                                <p class="field-label">Sex @if($editingProfile)<span class="text-red-500">*</span>@endif</p>
                                @if($editingProfile)
                                    <div class="flex sm:flex-col gap-2 sm:gap-1.5 flex-wrap pt-1">
                                        <label class="inline-flex items-center gap-1.5 text-sm font-semibold text-gray-900 cursor-pointer">
                                            <input wire:model="gender" type="radio" value="Male" class="w-4 h-4 accent-[#7a3f91] cursor-pointer"> Male
                                        </label>
                                        <label class="inline-flex items-center gap-1.5 text-sm font-semibold text-gray-900 cursor-pointer">
                                            <input wire:model="gender" type="radio" value="Female" class="w-4 h-4 accent-[#7a3f91] cursor-pointer"> Female
                                        </label>
                                    </div>
                                    @error('gender') <p class="text-xs text-red-400 font-medium mt-0.5 m-0">{{ $message }}</p> @enderror
                                @else
                                    @if($gender)<p class="field-value">{{ strtoupper($gender) }}</p>@else<p class="field-value-empty">Not provided</p>@endif
                                @endif
                            </div>
                            <div class="flex flex-col gap-1 field-block min-w-0">
                                <p class="field-label">Birthdate @if($editingProfile)<span class="text-red-500">*</span>@endif</p>
                                @if($editingProfile)
                                    <input wire:model="date_of_birth" type="date" max="{{ date('Y-m-d') }}"
                                        class="field-input {{ $errors->has('date_of_birth') ? 'field-error' : '' }}">
                                    @error('date_of_birth') <p class="text-xs text-red-400 font-medium mt-0.5 m-0">{{ $message }}</p> @enderror
                                @else
                                    @if($date_of_birth)<p class="field-value">{{ \Carbon\Carbon::parse($date_of_birth)->format('m/d/Y') }}</p>@else<p class="field-value-empty">Not provided</p>@endif
                                @endif
                            </div>
                        </div>
                        <div class="flex flex-col gap-1 field-block">
                            <p class="field-label">Program</p>
                            @if($course_name)<p class="field-value">{{ $course_name }}</p>@elseif($course_code)<p class="field-value">{{ strtoupper($course_code) }}</p>@else<p class="field-value-empty">Not provided</p>@endif
                        </div>
                    </div>
                </div>

                <div class="flex-1 min-w-0 xl:border-r border-b xl:border-b-0 border-gray-200">
                    <div class="px-3 py-1.5 bg-gray-50 border-b border-gray-100"><p class="field-label">Father's Name</p></div>
                    <div class="px-3 py-2.5">
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                            <div class="flex flex-col items-center gap-1 text-center field-block">
                                <p class="field-label">Last Name @if($editingProfile)<span class="text-red-500">*</span>@endif</p>
                                @if($editingProfile)
                                    <input wire:model="father_last_name" type="text" oninput="this.value=this.value.toUpperCase()"
                                        class="field-input text-center uppercase {{ $errors->has('father_last_name') ? 'field-error' : '' }}">
                                    @error('father_last_name') <p class="text-xs text-red-400 font-medium mt-0.5 m-0">{{ $message }}</p> @enderror
                                @else
                                    @if($father_last_name)<p class="field-value">{{ strtoupper($father_last_name) }}</p>@else<p class="field-value-empty">Not provided</p>@endif
                                @endif
                            </div>
                            <div class="flex flex-col items-center gap-1 text-center field-block">
                                <p class="field-label">Given Name @if($editingProfile)<span class="text-red-500">*</span>@endif</p>
                                @if($editingProfile)
                                    <input wire:model="father_given_name" type="text" oninput="this.value=this.value.toUpperCase()"
                                        class="field-input text-center uppercase {{ $errors->has('father_given_name') ? 'field-error' : '' }}">
                                    @error('father_given_name') <p class="text-xs text-red-400 font-medium mt-0.5 m-0">{{ $message }}</p> @enderror
                                @else
                                    @if($father_given_name)<p class="field-value">{{ strtoupper($father_given_name) }}</p>@else<p class="field-value-empty">Not provided</p>@endif
                                @endif
                            </div>
                            <div class="flex flex-col items-center gap-1 text-center field-block">
                                <p class="field-label">Middle Name @if($editingProfile)<span class="text-red-500">*</span>@endif</p>
                                @if($editingProfile)
                                    <input wire:model="father_middle_name" type="text" oninput="this.value=this.value.toUpperCase()"
                                        class="field-input text-center uppercase {{ $errors->has('father_middle_name') ? 'field-error' : '' }}">
                                    @error('father_middle_name') <p class="text-xs text-red-400 font-medium mt-0.5 m-0">{{ $message }}</p> @enderror
                                @else
                                    @if($father_middle_name)<p class="field-value">{{ strtoupper($father_middle_name) }}</p>@else<p class="field-value-empty">Not provided</p>@endif
                                @endif
                            </div>
                            <div class="flex flex-col items-center gap-1 text-center field-block">
                                <p class="field-label">Ext.</p>
                                @if($editingProfile)
                                    <div class="suffix-compact-wrap w-full"
                                         x-data="{
                                             open: false,
                                             suffixes: ['Jr.','Sr.','II','III','IV','V','VI','VII','VIII','IX','X'],
                                             toggle() { this.open = !this.open; },
                                             close()  { this.open = false; },
                                             select(val) { $wire.set('father_suffix', val); this.close(); },
                                             clear()  { $wire.set('father_suffix', ''); this.close(); },
                                         }"
                                         @click.outside="close()">
                                        <button type="button" @click="toggle()"
                                                :class="{ 'has-value': $wire.father_suffix !== '', 'open': open }"
                                                class="suffix-compact-trigger {{ $errors->has('father_suffix') ? 'field-error' : '' }}">
                                            <span x-show="$wire.father_suffix !== ''" x-text="$wire.father_suffix" style="display:none;"></span>
                                            <span class="sfx-placeholder" x-show="$wire.father_suffix === ''">None</span>
                                            <i class="fas fa-chevron-down sfx-chevron"></i>
                                        </button>
                                        <div x-show="open"
                                             x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 -translate-y-1 scale-95" x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                                             x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                                             class="suffix-compact-panel" style="display:none;">
                                            <div class="suffix-compact-list">
                                                <template x-for="s in suffixes" :key="s">
                                                    <button type="button" @click.stop="select(s)"
                                                            :class="{ 'is-selected': $wire.father_suffix === s }"
                                                            class="suffix-compact-item" x-text="s"></button>
                                                </template>
                                            </div>
                                            <div class="suffix-compact-footer">
                                                <button type="button" @click.stop="clear()" class="suffix-compact-clear">Clear</button>
                                            </div>
                                        </div>
                                    </div>
                                    @error('father_suffix') <p class="text-xs text-red-400 font-medium mt-0.5 m-0">{{ $message }}</p> @enderror
                                @else
                                    @if($father_suffix)<p class="field-value">{{ strtoupper($father_suffix) }}</p>@else<p class="field-value-empty">Not provided</p>@endif
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex-1 min-w-0">
                    <div class="px-3 py-1.5 bg-gray-50 border-b border-gray-100"><p class="field-label">Mother's Maiden Name</p></div>
                    <div class="px-3 py-2.5">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                            <div class="flex flex-col items-center gap-1 text-center field-block">
                                <p class="field-label">Last Name @if($editingProfile)<span class="text-red-500">*</span>@endif</p>
                                @if($editingProfile)
                                    <input wire:model="mother_last_name" type="text" oninput="this.value=this.value.toUpperCase()"
                                        class="field-input text-center uppercase {{ $errors->has('mother_last_name') ? 'field-error' : '' }}">
                                    @error('mother_last_name') <p class="text-xs text-red-400 font-medium mt-0.5 m-0">{{ $message }}</p> @enderror
                                @else
                                    @if($mother_last_name)<p class="field-value">{{ strtoupper($mother_last_name) }}</p>@else<p class="field-value-empty">Not provided</p>@endif
                                @endif
                            </div>
                            <div class="flex flex-col items-center gap-1 text-center field-block">
                                <p class="field-label">Given Name @if($editingProfile)<span class="text-red-500">*</span>@endif</p>
                                @if($editingProfile)
                                    <input wire:model="mother_given_name" type="text" oninput="this.value=this.value.toUpperCase()"
                                        class="field-input text-center uppercase {{ $errors->has('mother_given_name') ? 'field-error' : '' }}">
                                    @error('mother_given_name') <p class="text-xs text-red-400 font-medium mt-0.5 m-0">{{ $message }}</p> @enderror
                                @else
                                    @if($mother_given_name)<p class="field-value">{{ strtoupper($mother_given_name) }}</p>@else<p class="field-value-empty">Not provided</p>@endif
                                @endif
                            </div>
                            <div class="flex flex-col items-center gap-1 text-center field-block">
                                <p class="field-label">Middle Name @if($editingProfile)<span class="text-red-500">*</span>@endif</p>
                                @if($editingProfile)
                                    <input wire:model="mother_middle_name" type="text" oninput="this.value=this.value.toUpperCase()"
                                        class="field-input text-center uppercase {{ $errors->has('mother_middle_name') ? 'field-error' : '' }}">
                                    @error('mother_middle_name') <p class="text-xs text-red-400 font-medium mt-0.5 m-0">{{ $message }}</p> @enderror
                                @else
                                    @if($mother_middle_name)<p class="field-value">{{ strtoupper($mother_middle_name) }}</p>@else<p class="field-value-empty">Not provided</p>@endif
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            {{-- ROW 3: Permanent Address --}}
            <div class="flex border-b border-gray-200"
                 x-data="phAddress({
                     province: @js($address_province),
                     municipality: @js($address_municipality),
                     barangay: @js($address_barangay),
                 })" x-init="init()">
                <div class="flex-1 min-w-0">
                    <div class="px-3 py-1.5 bg-gray-50 border-b border-gray-100 flex items-center justify-between gap-2 flex-wrap">
                        <p class="field-label">Permanent Address</p>
                        @if($editingProfile)
                            <div class="flex items-center gap-3">
                                <p class="text-[10px] font-semibold text-[#333333] flex items-center gap-1" x-show="loading">
                                    <i class="fas fa-circle-notch fa-spin"></i> Loading location list…
                                </p>
                                <button type="button" x-show="loadFailed" @click="retryLoad()"
                                        class="text-[10px] font-semibold text-amber-600 flex items-center gap-1 hover:text-amber-700">
                                    <i class="fas fa-triangle-exclamation"></i>
                                    Location list unavailable. Click to retry.
                                </button>
                                <div class="addr-toggle" x-show="!loading">
                                    <button type="button" @click="setMode('dropdown')" class="addr-toggle-opt" :class="mode === 'dropdown' ? 'is-active' : ''">List</button>
                                    <button type="button" @click="setMode('manual')" class="addr-toggle-opt" :class="mode === 'manual' ? 'is-active' : ''">Type</button>
                                </div>
                            </div>
                        @endif
                    </div>
                    <div class="px-3 py-2.5">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2.5">

                            {{-- Province --}}
                            <div class="flex flex-col gap-1 field-block">
                                <p class="field-label">Province @if($editingProfile)<span class="text-red-500">*</span>@endif</p>
                                @if($editingProfile)
                                    <select x-show="mode === 'dropdown'" wire:ignore
                                            x-effect="applyOptions($el, provinceOptionsHtml(), selected.provinceCode)"
                                            class="addr-select {{ $errors->has('address_province') ? 'field-error' : '' }}"
                                            @change="selected.provinceCode = $event.target.value; onProvinceChange()" :disabled="loading">
                                    </select>
                                    <input x-show="mode === 'manual'" wire:model="address_province" type="text" oninput="this.value=this.value.toUpperCase()"
                                        class="field-input uppercase {{ $errors->has('address_province') ? 'field-error' : '' }}">
                                    <p class="text-[11px] text-[#333333] m-0" x-show="mode === 'dropdown' && !loading && provinces.length === 0">No provinces loaded. Switch to Type instead.</p>
                                    @error('address_province') <p class="text-xs text-red-400 font-medium mt-0.5 m-0">{{ $message }}</p> @enderror
                                @else
                                    @if($address_province)<p class="field-value">{{ strtoupper($address_province) }}</p>@else<p class="field-value-empty">Not provided</p>@endif
                                @endif
                            </div>

                            {{-- Municipality / City --}}
                            <div class="flex flex-col gap-1 field-block">
                                <p class="field-label">Municipality / City @if($editingProfile)<span class="text-red-500">*</span>@endif</p>
                                @if($editingProfile)
                                    <select x-show="mode === 'dropdown'" wire:ignore
                                            class="addr-select {{ $errors->has('address_municipality') ? 'field-error' : '' }}"
                                            x-model="selected.cityCode" @change="onCityChange()" :disabled="loading || !selected.provinceCode">
                                        <option value="">Select Municipality / City</option>
                                        <template x-for="c in filteredCities" :key="c.city_code"><option :value="c.city_code" x-text="c.city_name"></option></template>
                                    </select>
                                    <input x-show="mode === 'manual'" wire:model="address_municipality" type="text" oninput="this.value=this.value.toUpperCase()"
                                        class="field-input uppercase {{ $errors->has('address_municipality') ? 'field-error' : '' }}">
                                    <p class="text-[11px] text-[#333333] m-0" x-show="mode === 'dropdown' && !selected.provinceCode">Select province first.</p>
                                    @error('address_municipality') <p class="text-xs text-red-400 font-medium mt-0.5 m-0">{{ $message }}</p> @enderror
                                @else
                                    @if($address_municipality)<p class="field-value">{{ strtoupper($address_municipality) }}</p>@else<p class="field-value-empty">Not provided</p>@endif
                                @endif
                            </div>

                            {{-- Barangay --}}
                            <div class="flex flex-col gap-1 field-block">
                                <p class="field-label">Barangay @if($editingProfile)<span class="text-red-500">*</span>@endif</p>
                                @if($editingProfile)
                                    <select x-show="mode === 'dropdown'" wire:ignore
                                            class="addr-select {{ $errors->has('address_barangay') ? 'field-error' : '' }}"
                                            x-model="selected.barangayCode" @change="onBarangayChange()" :disabled="loading || !selected.cityCode">
                                        <option value="">Select Barangay</option>
                                        <template x-for="b in filteredBarangays" :key="b.brgy_code"><option :value="b.brgy_code" x-text="b.brgy_name"></option></template>
                                    </select>
                                    <input x-show="mode === 'manual'" wire:model="address_barangay" type="text" oninput="this.value=this.value.toUpperCase()"
                                        class="field-input uppercase {{ $errors->has('address_barangay') ? 'field-error' : '' }}">
                                    <p class="text-[11px] text-[#333333] m-0" x-show="mode === 'dropdown' && !selected.cityCode">Select municipality/city first.</p>
                                    @error('address_barangay') <p class="text-xs text-red-400 font-medium mt-0.5 m-0">{{ $message }}</p> @enderror
                                @else
                                    @if($address_barangay)<p class="field-value">{{ strtoupper($address_barangay) }}</p>@else<p class="field-value-empty">Not provided</p>@endif
                                @endif
                            </div>

                            {{-- Street --}}
                            <div class="flex flex-col gap-1 field-block">
                                <p class="field-label">Street Name, Building, House No. @if($editingProfile)<span class="text-red-500">*</span>@endif</p>
                                @if($editingProfile)
                                    <input wire:model="address_street" type="text" oninput="this.value=this.value.toUpperCase()"
                                        placeholder="Street Name, Building, House No."
                                        class="field-input uppercase {{ $errors->has('address_street') ? 'field-error' : '' }}">
                                    @error('address_street') <p class="text-xs text-red-400 font-medium mt-0.5 m-0">{{ $message }}</p> @enderror
                                @else
                                    @if($address_street)<p class="field-value">{{ strtoupper($address_street) }}</p>@else<p class="field-value-empty">Not provided</p>@endif
                                @endif
                            </div>

                        </div>
                    </div>
                </div>
            </div>

            {{-- ROW 4: Additional Information --}}
            <div class="flex">
                <div class="flex-1 min-w-0">
                    <div class="px-3 py-1.5 bg-gray-50 border-b border-gray-100"><p class="field-label">Additional Information</p></div>
                    <div class="px-3 py-2.5">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2.5">
                            <div class="flex flex-col gap-1 field-block">
                                <p class="field-label">DSWD Household No.</p>
                                @if($editingProfile)
                                    <input wire:model="dswd_household_no" type="text" oninput="this.value=this.value.toUpperCase()" class="field-input uppercase">
                                    <p class="text-xs text-[#333333] font-normal mt-0.5 m-0">Leave blank if not applicable.</p>
                                @else
                                    @if($dswd_household_no)<p class="field-value">{{ strtoupper($dswd_household_no) }}</p>@else<p class="field-value-empty">Not provided</p>@endif
                                @endif
                            </div>
                            <div class="flex flex-col gap-1 field-block">
                                <p class="field-label">Disability</p>
                                @if($editingProfile)
                                    <input wire:model="disability" type="text" oninput="this.value=this.value.toUpperCase()" class="field-input uppercase">
                                    <p class="text-xs text-[#333333] font-normal mt-0.5 m-0">Leave blank if not applicable.</p>
                                @else
                                    @if($disability)<p class="field-value">{{ strtoupper($disability) }}</p>@else<p class="field-value-empty">Not provided</p>@endif
                                @endif
                            </div>
                            <div class="flex flex-col gap-1 field-block">
                                <p class="field-label">Contact Number @if($editingProfile)<span class="text-red-500">*</span>@endif</p>
                                @if($editingProfile)
                                    <input wire:model="contact_number" type="tel" oninput="this.value=this.value.toUpperCase()"
                                        class="field-input uppercase {{ $errors->has('contact_number') ? 'field-error' : '' }}">
                                    @error('contact_number') <p class="text-xs text-red-400 font-medium mt-0.5 m-0">{{ $message }}</p> @enderror
                                @else
                                    @if($contact_number)<p class="field-value">{{ strtoupper($contact_number) }}</p>@else<p class="field-value-empty">Not provided</p>@endif
                                @endif
                            </div>

                            <div class="flex flex-col gap-1 field-block">
                                <p class="field-label">Email Address @if($editingProfile)<span class="text-red-500">*</span>@endif</p>
                                @if($editingProfile)
                                    <input wire:model="email" type="email"
                                        @disabled(!$this->canEditEmail)
                                        class="field-input {{ $errors->has('email') ? 'field-error' : '' }}">
                                    @error('email') <p class="text-xs text-red-400 font-medium mt-0.5 m-0">{{ $message }}</p> @enderror
                                    @if(!$this->canEditEmail)
                                        <p class="text-xs text-amber-600 font-medium mt-0.5 m-0 flex items-center gap-1">
                                            <i class="fas fa-lock text-[10px]"></i> Locked. Changeable again in {{ $this->emailCooldownDaysLeft }} day(s).
                                        </p>
                                    @else
                                        <p class="text-xs text-[#333333] font-normal mt-0.5 m-0">Can only be changed once every 30 days.</p>
                                    @endif
                                @else
                                    @if($email)<p class="field-value break-all">{{ $email }}</p>@else<p class="field-value-empty">Not provided</p>@endif
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ROW 5: Employment Snapshot (read-only) --}}
            <div class="flex border-t border-gray-200">
                <div class="flex-1 min-w-0">
                    <div class="px-3 py-1.5 bg-gray-50 border-b border-gray-100 flex items-center justify-between gap-2 flex-wrap">
                        <p class="field-label">Employment Information</p>
                        @if($hasEmploymentRecord && !$this->canEditEmployment)
                            <span class="text-[10px] font-semibold text-[#333333] flex items-center gap-1">
                                <i class="fas fa-lock"></i> Locked for {{ $this->employmentCooldownDaysLeft }} day(s)
                            </span>
                        @endif
                    </div>
                    <div class="px-3 py-2.5">
                        @if(!$currentRecord)
                           <p class="field-value-empty">No employment record yet. You can update your employment details once you've finished updating your <strong>Alumni Information</strong>.</p>
                        @else
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2.5">
                                <div class="flex flex-col gap-1 field-block">
                                    <p class="field-label">Status</p>
                                    <p class="field-value">{{ $currentRecord['employment_status'] }}</p>
                                </div>
                                @if($currentRecord['is_working'])
                                    <div class="flex flex-col gap-1 field-block">
                                        <p class="field-label">Company / Business</p>
                                        <p class="field-value">{{ strtoupper($currentRecord['company_name']) ?: '—' }}</p>
                                    </div>
                                    <div class="flex flex-col gap-1 field-block">
                                        <p class="field-label">Job Title</p>
                                        <p class="field-value">{{ $currentRecord['job_title'] ?: '—' }}</p>
                                    </div>
                                    <div class="flex flex-col gap-1 field-block">
                                        <p class="field-label">Type / Location</p>
                                        <p class="field-value">{{ implode(' · ', array_filter([$currentRecord['employment_type'], $currentRecord['work_location']])) ?: '—' }}</p>
                                    </div>
                                @else
                                    <div class="flex flex-col gap-1 sm:col-span-3 field-block">
                                        <p class="field-label">Job Search Status</p>
                                        <p class="field-value">{{ $currentRecord['unemployment_status'] ?: '—' }}</p>
                                    </div>
                                @endif
                            </div>
                            <p class="text-xs text-[#333333] mt-3">Submitted: {{ $currentRecord['submitted_at'] }}</p>
                        @endif
                    </div>
                </div>
            </div>

        </div>{{-- end card body --}}

        {{-- ══ FOOTER BAR ══ --}}
        <div class="flex items-center px-4 sm:px-5 py-2 min-h-[38px] sm:min-h-[42px] bg-[#f6effa] border-t border-[#E8E0F0] flex-shrink-0">
            <p class="text-[12px] font-medium m-0">
                @if($profileComplete)
                    <span class="inline-flex items-center gap-1.5 text-emerald-700">
                        <i class="fas fa-circle-check"></i>
                        <strong class="font-bold">Profile complete.</strong> All required fields are filled.
                    </span>
                @else

                @endif
            </p>
        </div>

    </div>{{-- end content block --}}

    {{-- ══ PROFILE SAVE CONFIRM MODAL ══ --}}
    <div x-show="showProfileConfirm" x-cloak
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-[60] flex items-center justify-center p-4" style="display:none;">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="showProfileConfirm = false"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
            <div class="p-6 text-center">
                <div class="w-12 h-12 rounded-full bg-amber-100 flex items-center justify-center mx-auto mb-3">
                    <i class="fas fa-triangle-exclamation text-amber-600 text-lg"></i>
                </div>
                <h3 class="text-base font-semibold text-gray-900">Confirm Profile Update</h3>
                <p class="text-sm text-gray-500 mt-2">
                    Once saved, you won't be able to update your profile again for
                    <strong class="text-gray-800">30 days</strong>. Make sure all information is correct before continuing.
                </p>
            </div>
            <div class="px-6 pb-6 flex gap-2">
                <button type="button" @click="showProfileConfirm = false"
                        class="flex-1 px-4 py-2.5 rounded-lg bg-gray-100 text-gray-700 text-sm font-semibold cursor-pointer hover:bg-gray-200 transition">
                    Cancel
                </button>
                <button type="button" wire:click="saveProfile" @click="showProfileConfirm = false"
                        class="flex-1 px-4 py-2.5 rounded-lg bg-emerald-500 text-white text-sm font-semibold cursor-pointer hover:bg-emerald-600 transition">
                    Yes, Save
                </button>
            </div>
        </div>
    </div>

    {{-- ══ EMPLOYMENT FULLSCREEN EDITOR ══ --}}
    <div x-show="$wire.editingEmployment" x-cloak
         class="fixed inset-0 z-50 flex flex-col overflow-hidden">

        {{-- Header: solid purple, icon-only info button (no hover tooltip) --}}
        <div class="emp-editor-header flex-shrink-0 px-4 sm:px-8 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-white/15 flex items-center justify-center">
                    <i class="fa-solid fa-briefcase text-white text-sm"></i>
                </div>
                <div>
                    <h2 class="text-base font-semibold leading-tight">Update Employment Information</h2>
                    <p class="text-[11px] leading-tight">Fields marked <span class="font-bold">*</span> are required.</p>
                </div>
                <div class="emp-info-wrap">
                    <span class="emp-info-btn"><i class="fas fa-circle-info text-xs"></i></span>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <div class="emp-hdr-btn-wrap">
                    <button type="button" @click="showEmpConfirm = true"
                            class="emp-hdr-btn bg-emerald-500 text-white hover:bg-emerald-600">
                        <i class="fas fa-floppy-disk text-sm"></i>
                    </button>
                    <span class="emp-hdr-tip">Save</span>
                </div>
                <div class="emp-hdr-btn-wrap">
                    <button wire:click="cancelEditingEmployment" type="button"
                            wire:loading.attr="disabled" wire:loading.class="opacity-60 cursor-wait" wire:target="cancelEditingEmployment"
                            class="emp-hdr-btn bg-white/15 text-white hover:bg-white/25">
                        <span wire:loading.remove wire:target="cancelEditingEmployment"><i class="fas fa-xmark text-sm"></i></span>
                        <span wire:loading wire:target="cancelEditingEmployment"><i class="fas fa-spinner fa-spin text-sm"></i></span>
                    </button>
                    <span class="emp-hdr-tip">Close</span>
                </div>
            </div>
        </div>

        {{-- Body: light gray, sized to content, landscape grid --}}
        <div class="emp-editor-body flex-1 min-h-0 px-4 sm:px-8 py-4 overflow-y-auto">

            @if($errorMessage)
                <div class="rounded-lg px-3 py-2 text-xs border bg-red-50 text-red-600 border-red-200 flex items-center gap-2 mb-3">
                    <i class="fas fa-circle-exclamation flex-shrink-0"></i><span>{{ $errorMessage }}</span>
                </div>
            @endif

            <div class="grid grid-cols-1 {{ in_array($employment_status, ['employed','self_employed']) ? 'lg:grid-cols-3' : 'lg:grid-cols-2' }} gap-3 items-start">

                {{-- Column 1: Status + Education + Unemployment (always present, compact) --}}
                <div class="flex flex-col gap-3">
                    <div class="emp-card" id="emp-status-card">
                        <div class="emp-card-title">Employment Status</div>
                        <div class="emp-card-body">
                            <label class="emp-label-sm">Current Status <span class="text-red-500">*</span></label>
                            <div class="flex flex-wrap gap-2">
                                @foreach(['employed'=>'Employed','self_employed'=>'Self-Employed','unemployed'=>'Unemployed'] as $val=>$lbl)
                                <label class="emp-radio-tile">
                                    <input wire:model.live="employment_status" type="radio" value="{{ $val }}" class="w-3.5 h-3.5 accent-[#7a3f91] cursor-pointer">
                                    <span>{{ $lbl }}</span>
                                </label>
                                @endforeach
                            </div>
                            @error('employment_status') <p class="text-[11px] text-red-400 mt-1.5">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    @if($employment_status === 'unemployed')
                    <div class="emp-card">
                        <div class="emp-card-title">Unemployment Status</div>
                        <div class="emp-card-body">
                            <label class="emp-label-sm">Job Search Status <span class="text-red-500">*</span></label>
                            <div class="flex flex-wrap gap-2">
                                @foreach(['seeking_employment'=>'Actively Seeking Employment','not_looking'=>'Not Currently Looking'] as $val=>$lbl)
                                <label class="emp-radio-tile">
                                    <input wire:model="unemployment_status" type="radio" value="{{ $val }}" class="w-3.5 h-3.5 accent-[#7a3f91] cursor-pointer">
                                    <span>{{ $lbl }}</span>
                                </label>
                                @endforeach
                            </div>
                            @error('unemployment_status') <p class="text-[11px] text-red-400 mt-1.5">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    @endif

                    <div class="emp-card">
                        <div class="emp-card-title">Further Education <span class="emp-card-optional">(optional)</span></div>
                        <div class="emp-card-body">
                            <label class="emp-label-sm">Education Status</label>
                            <div class="flex flex-wrap gap-2">
                                @foreach(['none'=>'None','pursuing_masteral'=>'Pursuing Masteral','pursuing_doctorate'=>'Pursuing Doctorate'] as $val=>$lbl)
                                <label class="emp-radio-tile">
                                    <input wire:model="education_status" type="radio" value="{{ $val }}" class="w-3.5 h-3.5 accent-[#7a3f91] cursor-pointer">
                                    <span>{{ $lbl }}</span>
                                </label>
                                @endforeach
                            </div>
                            <p class="text-[11px] text-[#333333] mt-1.5">You may leave this unanswered if it doesn't apply to you.</p>
                            @error('education_status') <p class="text-[11px] text-red-400 mt-1.5">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    @if($employment_status)
                    <button type="button"
                            wire:click="$set('employment_status', '')"
                            wire:loading.attr="disabled"
                            wire:target="$set('employment_status', '')"
                            class="emp-back-btn-bottom">
                        <span wire:loading.remove wire:target="$set('employment_status', '')"><i class="fas fa-arrow-left"></i></span>
                        <span wire:loading wire:target="$set('employment_status', '')"><i class="fas fa-spinner fa-spin"></i></span>
                        Back
                    </button>
                    @endif                </div>

                @if(in_array($employment_status, ['employed','self_employed']))
                @php $isSelf = $employment_status === 'self_employed'; @endphp

                {{-- Column 2: Company + Job Title --}}
                <div class="flex flex-col gap-3">
                    <div class="emp-card">
                        <div class="emp-card-title">Employment Details</div>
                        <div class="emp-card-body flex flex-col gap-3">
                            <div>
                                <label class="emp-label-sm">{{ $isSelf ? 'Business Name' : 'Company Name' }} <span class="text-red-500">*</span></label>
                                <input wire:model="company_name" type="text"
                                       oninput="this.value=this.value.toUpperCase()"
                                       class="emp-input-sm uppercase {{ $errors->has('company_name') ? 'field-error' : '' }}">
                                @error('company_name') <p class="text-[11px] text-red-400 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="emp-label-sm">{{ $isSelf ? 'Occupation / Role' : 'Job Title' }} <span class="text-red-500">*</span></label>
                                <select wire:model.live="job_title"
                                        class="emp-input-sm {{ $errors->has('job_title') ? 'field-error' : '' }}">
                                    <option value="">Select Job Title</option>
                                    @foreach($jobOptions as $title)<option value="{{ $title }}">{{ $title }}</option>@endforeach
                                </select>
                                @error('job_title') <p class="text-[11px] text-red-400 mt-1">{{ $message }}</p> @enderror
                                @if($job_title && $job_title !== 'Other')
                                    <p class="text-[11px] text-[#333333] mt-1 font-medium">Auto-detected: Related to your program.</p>
                                @endif
                                @if($job_title === 'Other')
                                <div class="mt-2 space-y-2">
                                    <div>
                                        <label class="emp-label-sm">Please Specify <span class="text-red-500">*</span></label>
                                        <input wire:model.live="custom_job_title" type="text" maxlength="255"
                                               class="emp-input-sm {{ $errors->has('custom_job_title') ? 'field-error' : '' }}">
                                        @error('custom_job_title') <p class="text-[11px] text-red-400 mt-1">{{ $message }}</p> @enderror
                                    </div>
                                    @if($custom_job_title)
                                    @php
                                        $relText = match($course_relevance) {
                                            'yes'       => 'Related to Program',
                                            'partially' => 'Partially Related',
                                            'no'        => 'Not Related',
                                            default     => 'Detecting…',
                                        };
                                    @endphp
                                    <div>
                                        <span class="text-[11px] font-semibold uppercase tracking-wide text-[#333333]">Relevance: </span>
                                        <span class="text-[11px] font-semibold text-[#333333]">{{ $relText }}</span>
                                    </div>
                                    @endif
                                </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Column 3: Type, Location, Career Path --}}
                <div class="flex flex-col gap-3">
                    <div class="emp-card">
                        <div class="emp-card-title">Type &amp; Location</div>
                        <div class="emp-card-body flex flex-col gap-3">
                            <div>
                                <label class="emp-label-sm">Employment Type <span class="text-red-500">*</span></label>
                                <div class="flex flex-wrap gap-2">
                                    @foreach(['full_time'=>'Full-Time','part_time'=>'Part-Time','contractual'=>'Contractual','project_based'=>'Project-Based','internship'=>'Internship'] as $val=>$lbl)
                                    <label class="emp-radio-tile">
                                        <input wire:model="employment_type" type="radio" value="{{ $val }}" class="w-3.5 h-3.5 accent-[#7a3f91] cursor-pointer">
                                        <span>{{ $lbl }}</span>
                                    </label>
                                    @endforeach
                                </div>
                                @error('employment_type') <p class="text-[11px] text-red-400 mt-1.5">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="emp-label-sm">Work Location <span class="text-red-500">*</span></label>
                                <div class="flex flex-wrap gap-2">
                                    @foreach(['local'=>'Local / PH','abroad'=>'OFW / Abroad'] as $val=>$lbl)
                                    <label class="emp-radio-tile">
                                        <input wire:model="work_location" type="radio" value="{{ $val }}" class="w-3.5 h-3.5 accent-[#7a3f91] cursor-pointer">
                                        <span>{{ $lbl }}</span>
                                    </label>
                                    @endforeach
                                </div>
                                @error('work_location') <p class="text-[11px] text-red-400 mt-1.5">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="emp-label-sm">Career Path <span class="normal-case font-normal text-[#333333]">(optional)</span></label>
                                <div class="flex flex-wrap gap-2">
                                    @foreach(['ofw'=>'OFW','freelancer'=>'Freelancer','entrepreneur'=>'Entrepreneur','career_shifter'=>'Career Shifter','industry_professional'=>'Industry Pro'] as $val=>$lbl)
                                    <label class="emp-radio-tile">
                                        <input wire:model="career_path" type="checkbox" value="{{ $val }}" class="w-3.5 h-3.5 accent-[#7a3f91] cursor-pointer">
                                        <span>{{ $lbl }}</span>
                                    </label>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                @endif

            </div>
        </div>
    </div>

    {{-- ══ EMPLOYMENT SAVE CONFIRM MODAL ══ --}}
    <div x-show="showEmpConfirm" x-cloak
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-[70] flex items-center justify-center p-4" style="display:none;">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="showEmpConfirm = false"></div>
        <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
            <div class="p-6 text-center">
                <div class="w-12 h-12 rounded-full bg-amber-100 flex items-center justify-center mx-auto mb-3">
                    <i class="fas fa-triangle-exclamation text-amber-600 text-lg"></i>
                </div>
                <h3 class="text-base font-semibold text-gray-900">Confirm Employment Update</h3>
                <p class="text-sm text-gray-500 mt-2">
                    Once saved, you won't be able to update your employment information again for
                    <strong class="text-gray-800">30 days</strong>. Make sure all information is correct before continuing.
                </p>
            </div>
            <div class="px-6 pb-6 flex gap-2">
                <button type="button" @click="showEmpConfirm = false"
                        class="flex-1 px-4 py-2.5 rounded-lg bg-gray-100 text-gray-700 text-sm font-semibold cursor-pointer hover:bg-gray-200 transition">
                    Cancel
                </button>
                <button type="button" wire:click="saveEmployment" @click="showEmpConfirm = false"
                        class="flex-1 px-4 py-2.5 rounded-lg bg-emerald-500 text-white text-sm font-semibold cursor-pointer hover:bg-emerald-600 transition">
                    Yes, Save
                </button>
            </div>
        </div>
    </div>

</div>{{-- end main layout --}}

</div>