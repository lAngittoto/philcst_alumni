{{-- resources/views/livewire/alumni/employment.blade.php --}}

<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\Alumni;

new class extends Component {

    public string $errorMessage   = '';
    public string $successMessage = '';
    public bool   $editing        = false;
    public bool   $hasRecord      = false;
    public int    $alumniId       = 0;
    public int    $trackingId     = 0;

    public ?array $currentRecord  = null;
    public ?array $previousRecord = null;

    public string $alumniCourse       = '';
    public string $alumniCourseName   = '';
    public string $alumniFullName     = '';
    public string $alumniFirstName    = '';
    public string $alumniLastName     = '';
    public string $alumniEmail        = '';
    public string $alumniContact      = '';
    public string $alumniBatch        = '';
    public string $alumniAddress      = '';
    public string $alumniPhoto        = '';
    public string $alumniGender       = '';
    public string $alumniDob          = '';
    public array  $jobOptions         = [];

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

    public array $snapshot = [];

    private const SNAP_KEYS = [
        'employment_status', 'company_name', 'job_title', 'custom_job_title',
        'employment_type', 'work_location', 'career_path', 'education_status',
        'course_relevance', 'unemployment_status',
    ];

    public function getProfilePhotoUrl(): string
    {
        $path = $this->alumniPhoto;
        if (!$path || str_contains($path, 'default.png')) return asset('storage/alumni-photos/default.png');
        if (str_starts_with($path, 'alumni-photos/') || str_starts_with($path, 'organizers/')) {
            return Storage::disk('public')->exists($path)
                ? asset('storage/' . $path)
                : asset('storage/alumni-photos/default.png');
        }
        return asset('storage/alumni-photos/default.png');
    }

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

    protected function detectJobRelevance(string $title): string
    {
        $t = strtolower(trim($title));
        if (empty($t)) return '';
        $group = $this->getCourseGroup($this->alumniCourse);
        $yes = [
            'technology'=>['developer','programmer','software','web dev','mobile app','network engineer','database admin','sysadmin','devops','cloud engineer','cybersecurity','data scientist','data analyst','ui/ux','it support','qa engineer','ml engineer','ai engineer','tech lead','systems analyst','ict','computer engineer','full stack','backend','frontend','it officer','helpdesk','network admin','it manager','it specialist','information technology','computer science','system developer','software engineer'],
            'nursing'=>['nurse','nursing','rn ','registered nurse','icu','er nurse','surgical nurse','ward nurse','dialysis nurse','pediatric nurse','public health nurse','head nurse','charge nurse','clinical nurse','operating room nurse','or nurse'],
            'education'=>['teacher','instructor','professor','tutor','faculty','educator','academic coordinator','school principal','curriculum developer','lecturer','teaching','special education','classroom teacher','school admin','school head','subject teacher','grade school','high school teacher','college instructor','tesda trainer','tesda teacher','vocational trainer','skills trainer'],
            'accounting'=>['accountant','auditor','cpa','tax specialist','bookkeeper','accounting','finance officer','budget analyst','payroll','internal auditor','external auditor','financial analyst','management accountant','cost accountant','revenue officer'],
            'business'=>['marketing manager','sales manager','business analyst','hr officer','operations manager','management trainee','business owner','entrepreneur','brand manager','product manager','account manager','business development','merchandising','trade marketing','retail manager','commercial manager'],
            'engineering'=>['engineer','civil engineer','mechanical engineer','electrical engineer','structural engineer','construction manager','project engineer','quality engineer','process engineer','industrial engineer','plant engineer','design engineer','site engineer','engineering manager','chief engineer'],
            'healthcare'=>['pharmacist','physical therapist','radiologic technologist','medical technologist','occupational therapist','respiratory therapist','dentist','dental','midwife','radiographer','med tech','pharmacy','therapist','clinical'],
            'criminology'=>['police officer','pnp','nbi agent','forensic analyst','criminologist','jail officer','fire officer','law enforcement','detective','intelligence officer','criminal investigator','bureau of corrections','bfp','bucor'],
            'hospitality'=>['hotel manager','chef','sous chef','restaurant manager','front desk officer','tour guide','event coordinator','flight attendant','travel agent','catering manager','hospitality manager','food and beverage','f&b manager','banquet manager','concierge','housekeeping manager','rooms division'],
            'psychology'=>['psychologist','guidance counselor','social worker','mental health','psychiatry','behavior analyst','clinical psychologist','counseling','psychology','welfare officer','rehabilitation counselor'],
            'communications'=>['journalist','reporter','broadcast journalist','public relations','pr officer','content writer','copywriter','social media manager','advertising','media planner','editor','communications officer','news writer','feature writer','anchor','media relations','communications specialist'],
            'architecture'=>['architect','interior designer','urban planner','draftsman','cad operator','architectural','landscape architect','space planner','master planner','building designer','architectural designer'],
            'law'=>['lawyer','attorney','legal officer','paralegal','court interpreter','judge','prosecutor','public attorney','legal counsel','law practitioner','notary public','legal consultant','solicitor'],
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
        return 'no';
    }

    protected function buildJobOptions(): array
    {
        $map = [
            'technology'=>['Software Developer','Web Developer','Mobile App Developer','Systems Analyst','Database Administrator','Network Engineer','IT Support Specialist','Cybersecurity Analyst','Data Analyst / Data Scientist','UI / UX Designer','QA / Test Engineer','DevOps / Cloud Engineer','AI / ML Engineer','Technical Project Manager'],
            'nursing'=>['Registered Nurse (RN)','ICU / Critical Care Nurse','ER / Emergency Nurse','Head Nurse / Supervisor','OR / Surgical Nurse','Pediatric Nurse','Public Health Nurse','Dialysis Nurse','OFW / International Nurse'],
            'education'=>['Elementary School Teacher','High School Teacher','Special Education Teacher','College Instructor','School Principal / Admin','Academic / Curriculum Coordinator','Tutor / Review Center Instructor'],
            'accounting'=>['Certified Public Accountant (CPA)','Auditor','Financial Analyst','Tax Specialist','Budget Analyst','Bookkeeper','Accounting Officer / Staff','Internal Auditor','Finance Manager'],
            'business'=>['Marketing Manager / Officer','Sales Manager','Operations Manager','Business Analyst','HR Officer / HR Manager','Management Trainee','Administrative Officer','Entrepreneur / Business Owner'],
            'engineering'=>['Civil Engineer','Mechanical Engineer','Electrical Engineer','Electronics Engineer','Chemical Engineer','Industrial Engineer','Project Engineer','Quality Assurance Engineer','Construction Engineer / Manager'],
            'healthcare'=>['Pharmacist','Physical Therapist','Radiologic Technologist','Medical Technologist','Occupational Therapist','Respiratory Therapist','Midwife','Dentist'],
            'criminology'=>['PNP Officer / Police Officer','NBI Agent','Criminologist','Jail Officer / BuCor','Forensic Analyst','Security Officer / Supervisor','Fire Officer (BFP)'],
            'hospitality'=>['Hotel Manager','Front Desk Officer','Restaurant Manager','Chef / Sous Chef','Tour Guide','Event Coordinator','Flight Attendant / Cabin Crew','Travel Agent'],
            'psychology'=>['Psychologist','Guidance Counselor','HR Officer / Recruiter','Social Worker','Mental Health Counselor','Training & Development Officer'],
            'communications'=>['Journalist / Reporter','Public Relations Officer','Broadcast Journalist','Content Creator / Writer','Social Media Manager','Copywriter','Advertising Specialist','Media Planner'],
            'architecture'=>['Architect','Interior Designer','Urban Planner','Draftsman / CAD Operator','Construction Manager'],
            'law'=>['Lawyer / Attorney','Legal Officer','Court Interpreter','Paralegal','Legal Researcher'],
            'general'=>['Administrative Officer','Office Staff','Customer Service Representative','Sales Representative'],
        ];
        $titles = $map[$this->getCourseGroup($this->alumniCourse)] ?? $map['general'];
        $titles[] = 'Other';
        return $titles;
    }

    public function mount(): void
    {
        $user = auth()->user();
        if (!$user || $user->role !== 'alumni') { $this->redirect(route('login')); return; }
        $alumni = Alumni::where('user_id', $user->id)->first();
        if (!$alumni) { $this->redirect(route('login')); return; }
        $this->alumniId         = $alumni->id;
        $this->alumniCourse     = $alumni->course_code  ?? '';
        $this->alumniCourseName = $alumni->course_name  ?? '';
        $this->alumniFirstName  = $alumni->first_name   ?? '';
        $this->alumniLastName   = $alumni->last_name    ?? '';
        $this->alumniFullName   = trim(
            ($alumni->first_name ?? '') . ' ' .
            ($alumni->middle_initial ? $alumni->middle_initial . '. ' : '') .
            ($alumni->last_name ?? '') .
            ($alumni->suffix ? ' ' . $alumni->suffix : '')
        );
        $this->alumniEmail   = $alumni->email          ?? '';
        $this->alumniContact = $alumni->contact_number ?? '';
        $this->alumniBatch   = (string)($alumni->batch ?? '');
        $this->alumniGender  = $alumni->gender         ?? '';
        $this->alumniDob     = $alumni->date_of_birth
            ? \Carbon\Carbon::parse($alumni->date_of_birth)->format('F j, Y')
            : '';
        $this->alumniAddress = implode(', ', array_filter([
            $alumni->address_street       ?? '',
            $alumni->address_barangay     ?? '',
            $alumni->address_municipality ?? '',
            $alumni->address_province     ?? '',
        ]));
        $this->alumniPhoto = $alumni->profile_photo ?? '';
        $this->jobOptions  = $this->buildJobOptions();
        $this->loadRecords();
    }

    protected function loadRecords(): void
    {
        $typeLabels   = ['full_time'=>'Full-Time','part_time'=>'Part-Time','contractual'=>'Contractual','project_based'=>'Project-Based','internship'=>'Internship'];
        $careerLabels = ['ofw'=>'OFW','freelancer'=>'Freelancer','entrepreneur'=>'Entrepreneur','career_shifter'=>'Career Shifter','industry_professional'=>'Industry Professional'];
        $eduLabels    = ['none'=>'None','pursuing_masteral'=>'Pursuing Masteral','pursuing_doctorate'=>'Pursuing Doctorate'];
        $relLabels    = ['yes'=>'Related to Course','no'=>'Not Related','partially'=>'Partially Related'];
        $unLabels     = ['seeking_employment'=>'Actively Seeking Employment','not_looking'=>'Not Currently Looking'];
        $statusLabels = ['employed'=>'Employed','self_employed'=>'Self-Employed','unemployed'=>'Unemployed'];

        $mapRecord = function ($r) use ($typeLabels,$careerLabels,$eduLabels,$relLabels,$unLabels,$statusLabels) {
            $cp = $r->career_path ? json_decode($r->career_path, true) : [];
            return [
                'id'                  => $r->id,
                'employment_status'   => $statusLabels[$r->employment_status ?? ''] ?? ucfirst($r->employment_status ?? ''),
                'employment_status_raw' => $r->employment_status ?? '',
                'is_working'          => in_array($r->employment_status ?? '', ['employed','self_employed']),
                'company_name'        => $r->company_name ?? '',
                'job_title'           => $r->job_title ?? '',
                'employment_type'     => $typeLabels[$r->employment_type ?? ''] ?? '',
                'work_location'       => ucfirst($r->work_location ?? ''),
                'career_path_labels'  => array_values(array_filter(array_map(fn($v) => $careerLabels[$v] ?? null, $cp))),
                'course_relevance'    => $relLabels[$r->course_relevance ?? ''] ?? '',
                'course_relevance_raw'=> $r->course_relevance ?? '',
                'unemployment_status' => $unLabels[$r->unemployment_status ?? ''] ?? '',
                'education_status'    => $eduLabels[$r->education_status ?? ''] ?? '',
                'submitted_at'        => $r->created_at ? \Carbon\Carbon::parse($r->created_at)->format('F j, Y') : '',
            ];
        };

        $current  = DB::table('employment_trackings')->where('alumni_id', $this->alumniId)->whereNull('deleted_at')->latest('created_at')->first();
        $previous = DB::table('employment_trackings')->where('alumni_id', $this->alumniId)->whereNotNull('deleted_at')->latest('created_at')->first();

        $this->currentRecord  = $current  ? $mapRecord($current)  : null;
        $this->previousRecord = $previous ? $mapRecord($previous) : null;

        if ($current) {
            $this->trackingId          = $current->id;
            $this->employment_status   = $current->employment_status   ?? '';
            $this->company_name        = $current->company_name        ?? '';
            $this->employment_type     = $current->employment_type     ?? '';
            $this->work_location       = $current->work_location       ?? '';
            $this->career_path         = $current->career_path ? json_decode($current->career_path, true) : [];
            $this->education_status    = $current->education_status    ?? '';
            $this->course_relevance    = $current->course_relevance    ?? '';
            $this->unemployment_status = $current->unemployment_status ?? '';
            $loaded = $current->job_title ?? '';
            if ($loaded && !in_array($loaded, $this->jobOptions, true)) {
                $this->job_title = 'Other'; $this->custom_job_title = $loaded;
            } else {
                $this->job_title = $loaded; $this->custom_job_title = '';
            }
            $this->hasRecord = true;
            $this->editing   = false;
        } else {
            $this->trackingId = 0; $this->job_title = ''; $this->custom_job_title = '';
            $this->hasRecord  = false; $this->editing = true;
        }
    }

    public function startEditing(): void
    {
        $this->errorMessage = $this->successMessage = '';
        $this->snapshot = [];
        foreach (self::SNAP_KEYS as $k) { $this->snapshot[$k] = $this->$k; }
        $this->editing = true;
    }

    public function cancelEditing(): void
    {
        $this->errorMessage = $this->successMessage = '';
        $this->resetValidation();
        foreach ($this->snapshot as $k => $v) { $this->$k = $v; }
        $this->editing = false;
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

    protected function statusLabel(string $status): string
    {
        return match ($status) {
            'employed'      => 'Employed',
            'self_employed' => 'Self-Employed',
            'unemployed'    => 'Unemployed',
            default         => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    protected function hasChanged(): bool
    {
        if (empty($this->snapshot)) return true;
        $isOther  = ($this->job_title === 'Other');
        $finalJob = $isOther ? $this->custom_job_title : $this->job_title;
        $snapJob  = $this->snapshot['job_title'] === 'Other'
            ? ($this->snapshot['custom_job_title'] ?? '')
            : ($this->snapshot['job_title'] ?? '');

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
            'employment_status'   => $this->snapshot['employment_status']   ?? '',
            'company_name'        => strtoupper(trim($this->snapshot['company_name'] ?? '')),
            'job_title'           => $snapJob,
            'employment_type'     => $this->snapshot['employment_type']     ?? '',
            'work_location'       => $this->snapshot['work_location']       ?? '',
            'career_path'         => $this->snapshot['career_path']         ?? [],
            'education_status'    => $this->snapshot['education_status']    ?? '',
            'course_relevance'    => $this->snapshot['course_relevance']    ?? '',
            'unemployment_status' => $this->snapshot['unemployment_status'] ?? '',
        ];
        sort($current['career_path']);
        sort($snap['career_path']);
        return $current !== $snap;
    }

    // ── Alumni notification: ONE per alumni per day (dedup_key has no status suffix) ──
    protected function saveAlumniNotification(array $payload): void
    {
        try {
            $today    = now()->toDateString();
            $existing = DB::table('alumni_notifications')
                ->where('alumni_id', $this->alumniId)
                ->where('dedup_key', $payload['dedup_key'])
                ->whereDate('created_at', $today)
                ->first();

            if ($existing) {
                DB::table('alumni_notifications')
                    ->where('id', $existing->id)
                    ->update([
                        'count'      => DB::raw('count + 1'),
                        'read'       => false,
                        'message'    => $payload['message'],
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('alumni_notifications')->insert([
                    'alumni_id'  => $this->alumniId,
                    'icon'       => $payload['icon'],
                    'title'      => $payload['title'],
                    'message'    => $payload['message'],
                    'link_route' => $payload['link_route'],
                    'link_label' => $payload['link_label'],
                    'dedup_key'  => $payload['dedup_key'],
                    'read'       => false,
                    'count'      => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Alumni notification upsert failed: ' . $e->getMessage());
        }
    }

    // ── Registrar notification: ONE per alumni per day (dedup_key has no status suffix) ──
    protected function saveRegistrarNotification(array $payload): void
    {
        try {
            $today    = now()->toDateString();
            $existing = DB::table('registrar_notifications')
                ->where('dedup_key', $payload['dedup_key'])
                ->whereDate('created_at', $today)
                ->first();

            if ($existing) {
                DB::table('registrar_notifications')
                    ->where('id', $existing->id)
                    ->update([
                        'read'       => false,
                        'message'    => $payload['message'],
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('registrar_notifications')->insert([
                    'icon'       => $payload['icon'],
                    'title'      => $payload['title'],
                    'message'    => $payload['message'],
                    'link_route' => $payload['link_route'],
                    'link_label' => $payload['link_label'],
                    'dedup_key'  => $payload['dedup_key'],
                    'read'       => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Registrar notification upsert failed: ' . $e->getMessage());
        }
    }

    public function saveEmployment(): void
    {
        $this->errorMessage = $this->successMessage = '';

        // ── No-change guard (only for existing records) ──────────────────────
        if ($this->trackingId !== 0 && !$this->hasChanged()) {
            $this->dispatch('show-emp-toast', type: 'error', message: 'No changes were made. Please edit a field before saving.');
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
            ];
            $msgs += [
                'company_name.required'    => 'Company / Business name is required.',
                'job_title.required'       => 'Please select a job title.',
                'employment_type.required' => 'Please select employment type.',
                'work_location.required'   => 'Please select work location.',
            ];
            if ($isOther) {
                $rules['custom_job_title'] = 'required|string|max:255';
                $msgs['custom_job_title.required'] = 'Please specify your job title.';
            }
        }
        if ($this->employment_status === 'unemployed') {
            $rules['unemployment_status'] = 'required|in:seeking_employment,not_looking';
            $msgs['unemployment_status.required'] = 'Please select your unemployment status.';
        }
        $this->validate($rules, $msgs);

        $finalJobTitle  = $isOther ? $this->custom_job_title : $this->job_title;
        $finalRelevance = $working ? ($this->course_relevance ?: 'no') : null;

        try {
            $now       = now();
            $isNew     = ($this->trackingId === 0);
            $oldStatus = $this->snapshot['employment_status'] ?? null;

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

            $this->hasRecord = true;
            $this->editing   = false;
            $this->successMessage = 'Employment information updated successfully!';

            $alumni = \App\Models\Alumni::find($this->alumniId);
            $name   = trim(($alumni->first_name ?? '') . ' ' . ($alumni->last_name ?? ''));
            $newStatusLabel = $this->statusLabel($this->employment_status);
            $oldStatusLabel = $oldStatus ? $this->statusLabel($oldStatus) : '';

            // ── REGISTRAR NOTIFICATION ──
            // dedup_key: "employment::{alumni_id}" — NO status suffix → one entry per alumni per day
            if ($isNew) {
                $registrarMsg = $name . ' submitted their first employment record as ' . $newStatusLabel . '.';
            } else {
                $registrarMsg = $name . ' updated their employment status'
                    . ($oldStatusLabel ? ' from ' . $oldStatusLabel : '')
                    . ' to ' . $newStatusLabel . '.';
            }

            $this->saveRegistrarNotification([
                'icon'       => $isNew ? 'briefcase' : 'arrow-rotate-right',
                'title'      => $isNew ? 'New Employment Record' : 'Employment Status Updated',
                'message'    => $registrarMsg,
                'link_route' => 'registrar.employment.tracking',
                'link_label' => 'View Tracking',
                // ONE key per alumni per day — no status suffix
                'dedup_key'  => 'employment::' . $this->alumniId,
            ]);

            // ── ALUMNI NOTIFICATION ──
            // dedup_key: "employment-tracking" — NO status suffix → one entry per alumni per day
            if ($isNew) {
                $alumniMsg = 'Your employment record has been submitted successfully as ' . $newStatusLabel . '. Pending admin review.';
            } else {
                $alumniMsg = 'Your employment status has been updated'
                    . ($oldStatusLabel ? ' from ' . $oldStatusLabel : '')
                    . ' to ' . $newStatusLabel . '.';
            }

            $this->saveAlumniNotification([
                'icon'       => 'chart-line',
                'title'      => $isNew ? 'Employment Record Submitted' : 'Employment Status Updated',
                'message'    => $alumniMsg,
                'link_route' => 'alumni.employment',
                'link_label' => 'View Employment',
                // ONE key per day — no status suffix so all daily updates merge here
                'dedup_key'  => 'employment-tracking',
            ]);

            $this->dispatch('show-emp-toast', type: 'success', message: $this->successMessage);
            $this->dispatch('refresh-alumni-notifs');
            $this->loadRecords();

            Log::info("Employment saved | alumni_id:{$this->alumniId} | status:{$this->employment_status}");

        } catch (\Throwable $e) {
            Log::error('Employment save error: ' . $e->getMessage());
            $this->errorMessage = 'Failed to save. Please try again.';
            $this->dispatch('show-emp-toast', type: 'error', message: $this->errorMessage);
        }
    }
}; ?>

<div x-data="{ showCvModal: false, showStatusModal: false, cvTab: 'current' }" class="space-y-6">

{{-- ── TOAST ── --}}
<style>
.emp-tooltip {
    position: absolute;
    bottom: calc(100% + 6px);
    right: 0;
    background: #111827;
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .05em;
    padding: 4px 10px;
    border-radius: 6px;
    white-space: nowrap;
    pointer-events: none;
    opacity: 0;
    transition: opacity .15s ease;
    z-index: 200;
    box-shadow: 0 2px 8px rgba(0,0,0,.18);
}
.emp-tooltip::after {
    content: '';
    position: absolute;
    top: 100%;
    right: 10px;
    border: 4px solid transparent;
    border-top-color: #111827;
}
.emp-btn-group:hover .emp-tooltip { opacity: 1; }

@keyframes emp-spin { to { transform: rotate(360deg); } }
.emp-spin { animation: emp-spin .7s linear infinite; }

#emp-toast {
    position: fixed;
    top: 20px;
    left: 50%;
    transform: translateX(-50%) translateY(-90px);
    z-index: 9999;
    min-width: 300px;
    max-width: 480px;
    pointer-events: none;
    opacity: 0;
    transition: transform .35s cubic-bezier(.34,1.56,.64,1), opacity .3s ease;
}
#emp-toast.emp-toast-visible {
    transform: translateX(-50%) translateY(0);
    opacity: 1;
    pointer-events: auto;
}
#emp-toast.emp-toast-hiding {
    transform: translateX(-50%) translateY(-90px);
    opacity: 0;
    pointer-events: none;
}
.emp-toast-inner {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 18px;
    border-radius: 12px;
    font-size: 13.5px;
    font-weight: 600;
    line-height: 1.4;
    background: #ffffff;
    box-shadow: none;
}
.emp-toast-success {
    color: #15803d;
    border: 1.5px solid #16a34a;
}
.emp-toast-success .emp-toast-icon { display: none; }
.emp-toast-success .emp-toast-close { color: rgba(21,128,61,.45); }
.emp-toast-success .emp-toast-close:hover { color: #15803d; }

.emp-toast-error {
    color: #b91c1c;
    border: 1.5px solid #dc2626;
}
.emp-toast-error .emp-toast-icon { display: none; }
.emp-toast-error .emp-toast-close { color: rgba(185,28,28,.45); }
.emp-toast-error .emp-toast-close:hover { color: #b91c1c; }

.emp-toast-close {
    margin-left: auto; flex-shrink: 0; background: none; border: none;
    cursor: pointer; font-size: 14px;
    padding: 2px 4px; border-radius: 4px; transition: color .15s; line-height: 1;
}
</style>

<div id="emp-toast" role="alert" aria-live="polite">
    <div class="emp-toast-inner emp-toast-success" id="emp-toast-inner">
        <i class="fas fa-circle-check emp-toast-icon" id="emp-toast-icon"></i>
        <span id="emp-toast-msg">Employment information updated successfully!</span>
        <button class="emp-toast-close" onclick="hideEmpToast()" aria-label="Dismiss">
            <i class="fas fa-xmark"></i>
        </button>
    </div>
</div>

<script>
(function () {
    let _empTimer = null;
    window.showEmpToast = function (type, message) {
        const toast = document.getElementById('emp-toast');
        const inner = document.getElementById('emp-toast-inner');
        const icon  = document.getElementById('emp-toast-icon');
        const msg   = document.getElementById('emp-toast-msg');
        if (!toast) return;
        clearTimeout(_empTimer);
        toast.classList.remove('emp-toast-visible', 'emp-toast-hiding');
        msg.textContent = message;
        inner.className = 'emp-toast-inner ' + (type === 'success' ? 'emp-toast-success' : 'emp-toast-error');
        icon.className  = 'emp-toast-icon fas ' + (type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation');
        void toast.offsetWidth;
        toast.classList.add('emp-toast-visible');
        _empTimer = setTimeout(window.hideEmpToast, 3500);
    };
    window.hideEmpToast = function () {
        const toast = document.getElementById('emp-toast');
        if (!toast) return;
        clearTimeout(_empTimer);
        toast.classList.remove('emp-toast-visible');
        toast.classList.add('emp-toast-hiding');
        setTimeout(() => toast.classList.remove('emp-toast-hiding'), 400);
    };
})();

document.addEventListener('livewire:initialized', () => {
    Livewire.on('show-emp-toast', ({ type, message }) => {
        window.showEmpToast(type, message);
    });

    Livewire.on('refresh-alumni-notifs', () => {
        const s = window.__safeAlumniNotifsStore ? window.__safeAlumniNotifsStore() : null;
        if (s) s._fetch();
    });
});
</script>

{{-- ══ PAGE HEADER ══ --}}
<div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
    <div class="flex items-center gap-3">
        <div class="w-[42px] h-[42px] rounded-xl bg-[#7a3f91] flex items-center justify-center flex-shrink-0">
            <i class="fa-solid fa-briefcase text-white text-base"></i>
        </div>
        <div>
            <div class="flex items-center gap-2.5 flex-wrap">
                <h1 class="text-2xl font-semibold text-gray-900 tracking-tight">Employment Tracking</h1>
                @if($editing)
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold uppercase tracking-widest bg-amber-100 text-amber-700 border border-amber-300">
                        <i class="fas fa-pen text-[9px]"></i> Edit Mode
                    </span>
                @endif
            </div>
            <p class="text-sm text-gray-500 mt-0.5">
                Keep your employment status up to date.
                Fields marked <span class="text-red-500 font-semibold">*</span> are required.
                @if($alumniCourse)
                    <span class="font-semibold text-gray-700 ml-1">{{ $alumniCourse }}</span>
                @endif
            </p>
        </div>
    </div>

    {{-- ── HEADER BUTTONS ── --}}
    <div class="flex items-center gap-2 flex-shrink-0">

        @if(!$editing)
            @if($currentRecord || $previousRecord)
            <button @click="showStatusModal = true; cvTab = 'current'"
                    type="button"
                    class="emp-btn-group relative group w-10 h-10 rounded-lg flex items-center justify-center
                           bg-white border border-gray-200 text-gray-600
                           hover:bg-gray-50 hover:text-gray-800 transition active:scale-95 cursor-pointer shadow-sm">
                <span class="emp-tooltip">Employment Status</span>
                <i class="fa-solid fa-chart-bar text-base"></i>
            </button>
            @endif

            @if($currentRecord)
            <button @click="showCvModal = true"
                    type="button"
                    class="emp-btn-group relative group w-10 h-10 rounded-lg flex items-center justify-center
                           bg-teal-500 border border-teal-600 text-white
                           hover:bg-teal-600 transition active:scale-95 cursor-pointer shadow-sm">
                <span class="emp-tooltip">View CV</span>
                <i class="fa-solid fa-file-user text-base"></i>
            </button>
            @endif

            <button wire:click="startEditing"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-wait"
                    wire:target="startEditing"
                    type="button"
                    class="emp-btn-group relative group w-10 h-10 rounded-lg flex items-center justify-center
                           bg-blue-500 border border-blue-600 text-white
                           hover:bg-blue-600 transition active:scale-95 cursor-pointer shadow-sm">
                <span class="emp-tooltip">Update Employment</span>
                <span wire:loading.remove wire:target="startEditing">
                    <i class="fas fa-pen-to-square text-base"></i>
                </span>
                <span wire:loading wire:target="startEditing">
                    <svg class="emp-spin w-4 h-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>
                </span>
            </button>

        @else
            <button wire:click="saveEmployment"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-wait"
                    wire:target="saveEmployment"
                    type="button"
                    class="emp-btn-group relative group w-10 h-10 rounded-lg flex items-center justify-center
                           bg-emerald-500 border border-emerald-600 text-white
                           hover:bg-emerald-600 transition active:scale-95 cursor-pointer shadow-sm">
                <span class="emp-tooltip">Save Employment</span>
                <span wire:loading.remove wire:target="saveEmployment">
                    <i class="fas fa-floppy-disk text-base"></i>
                </span>
                <span wire:loading wire:target="saveEmployment">
                    <svg class="emp-spin w-4 h-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>
                </span>
            </button>

            @if($hasRecord)
            <button wire:click="cancelEditing"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-wait"
                    wire:target="cancelEditing"
                    type="button"
                    class="emp-btn-group relative group w-10 h-10 rounded-lg flex items-center justify-center
                           bg-red-50 border border-red-200 text-red-500
                           hover:bg-red-100 hover:border-red-300 transition active:scale-95 cursor-pointer shadow-sm">
                <span class="emp-tooltip">Cancel</span>
                <span wire:loading.remove wire:target="cancelEditing">
                    <i class="fas fa-xmark text-base"></i>
                </span>
                <span wire:loading wire:target="cancelEditing">
                    <svg class="emp-spin w-4 h-4 text-red-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>
                </span>
            </button>
            @endif
        @endif

    </div>
</div>

{{-- ══ EDIT FORM ══ --}}
@if($editing)

<div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
    <div class="px-5 py-3.5 border-b border-gray-100">
        <span class="text-base font-semibold text-gray-900">Employment Status</span>
    </div>
    <div class="p-5">
        <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">
            Current Status <span class="text-red-500">*</span>
        </label>
        <div class="flex flex-wrap gap-3">
            @foreach(['employed'=>'Employed','self_employed'=>'Self-Employed','unemployed'=>'Unemployed'] as $val=>$lbl)
            <label class="flex items-center gap-2 cursor-pointer">
                <input wire:model.live="employment_status" type="radio" value="{{ $val }}" class="w-4 h-4 accent-[#7a3f91] cursor-pointer">
                <span class="text-sm font-semibold text-gray-900">{{ $lbl }}</span>
            </label>
            @endforeach
        </div>
        @error('employment_status') <p class="text-xs text-red-500 mt-1.5">{{ $message }}</p> @enderror
    </div>
</div>

@if(in_array($employment_status, ['employed','self_employed']))
@php $isSelf = $employment_status === 'self_employed'; @endphp
<div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
    <div class="px-5 py-3.5 border-b border-gray-100">
        <span class="text-base font-semibold text-gray-900">Employment Details</span>
    </div>
    <div class="p-5 space-y-5">
        <div class="max-w-lg">
            <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1.5">
                {{ $isSelf ? 'Business Name' : 'Company Name' }} <span class="text-red-500">*</span>
            </label>
            <input wire:model="company_name" type="text"
                   placeholder="{{ $isSelf ? 'E.G. ABC TRADING' : 'E.G. JOLLIBEE FOODS CORP.' }}"
                   oninput="this.value=this.value.toUpperCase()"
                   class="w-full box-border text-base font-normal text-gray-900 bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 uppercase tracking-wide transition hover:border-gray-300 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 focus:bg-white {{ $errors->has('company_name') ? 'border-red-500' : '' }}">
            @error('company_name') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
        </div>
        <div class="max-w-lg">
            <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1.5">
                {{ $isSelf ? 'Occupation / Role' : 'Job Title' }} <span class="text-red-500">*</span>
            </label>
            <select wire:model.live="job_title"
                    class="w-full box-border text-base font-normal text-gray-900 bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 transition hover:border-gray-300 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 focus:bg-white {{ $errors->has('job_title') ? 'border-red-500' : '' }}">
                <option value="">Select Job Title</option>
                @foreach($jobOptions as $title)
                    <option value="{{ $title }}">{{ $title }}</option>
                @endforeach
            </select>
            @error('job_title') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
            @if($job_title && $job_title !== 'Other')
                <p class="text-xs text-gray-500 mt-1.5 font-medium">Auto-detected: Related to your course.</p>
            @endif
            @if($job_title === 'Other')
            <div class="mt-3 space-y-3">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1.5">
                        Please Specify <span class="text-red-500">*</span>
                    </label>
                    <input wire:model.live="custom_job_title" type="text" maxlength="255"
                           placeholder="e.g. Marine Engineer, Fashion Designer"
                           class="w-full box-border text-base font-normal text-gray-900 bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 transition hover:border-gray-300 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 focus:bg-white {{ $errors->has('custom_job_title') ? 'border-red-500' : '' }}">
                    @error('custom_job_title') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
                @if($custom_job_title)
                @php
                    $relText = match($course_relevance) {
                        'yes'       => 'Related to Course',
                        'partially' => 'Partially Related',
                        'no'        => 'Not Related',
                        default     => 'Detecting…',
                    };
                @endphp
                <div>
                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Auto-Detected Relevance: </span>
                    <span class="text-xs font-semibold text-gray-700">{{ $relText }}</span>
                </div>
                @endif
            </div>
            @endif
        </div>
        <div>
            <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">
                Employment Type <span class="text-red-500">*</span>
            </label>
            <div class="flex flex-wrap gap-4">
                @foreach(['full_time'=>'Full-Time','part_time'=>'Part-Time','contractual'=>'Contractual','project_based'=>'Project-Based','internship'=>'Internship'] as $val=>$lbl)
                <label class="flex items-center gap-2 cursor-pointer">
                    <input wire:model="employment_type" type="radio" value="{{ $val }}" class="w-4 h-4 accent-[#7a3f91] cursor-pointer">
                    <span class="text-sm font-semibold text-gray-900">{{ $lbl }}</span>
                </label>
                @endforeach
            </div>
            @error('employment_type') <p class="text-xs text-red-500 mt-1.5">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">
                Work Location <span class="text-red-500">*</span>
            </label>
            <div class="flex flex-wrap gap-4">
                @foreach(['local'=>'Local','abroad'=>'Abroad'] as $val=>$lbl)
                <label class="flex items-center gap-2 cursor-pointer">
                    <input wire:model="work_location" type="radio" value="{{ $val }}" class="w-4 h-4 accent-[#7a3f91] cursor-pointer">
                    <span class="text-sm font-semibold text-gray-900">{{ $lbl }}</span>
                </label>
                @endforeach
            </div>
            @error('work_location') <p class="text-xs text-red-500 mt-1.5">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">
                Career Path
                <span class="normal-case font-normal text-gray-400 text-[11px] tracking-normal ml-1">(optional, select all that apply)</span>
            </label>
            <div class="flex flex-wrap gap-4">
                @foreach(['ofw'=>'OFW','freelancer'=>'Freelancer','entrepreneur'=>'Entrepreneur','career_shifter'=>'Career Shifter','industry_professional'=>'Industry Professional'] as $val=>$lbl)
                <label class="flex items-center gap-2 cursor-pointer">
                    <input wire:model="career_path" type="checkbox" value="{{ $val }}" class="w-4 h-4 accent-[#7a3f91] cursor-pointer">
                    <span class="text-sm font-semibold text-gray-900">{{ $lbl }}</span>
                </label>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endif

@if($employment_status === 'unemployed')
<div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
    <div class="px-5 py-3.5 border-b border-gray-100">
        <span class="text-base font-semibold text-gray-900">Unemployment Status</span>
    </div>
    <div class="p-5">
        <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">
            Job Search Status <span class="text-red-500">*</span>
        </label>
        <div class="flex flex-wrap gap-4">
            @foreach(['seeking_employment'=>'Actively Seeking Employment','not_looking'=>'Not Currently Looking'] as $val=>$lbl)
            <label class="flex items-center gap-2 cursor-pointer">
                <input wire:model="unemployment_status" type="radio" value="{{ $val }}" class="w-4 h-4 accent-[#7a3f91] cursor-pointer">
                <span class="text-sm font-semibold text-gray-900">{{ $lbl }}</span>
            </label>
            @endforeach
        </div>
        @error('unemployment_status') <p class="text-xs text-red-500 mt-1.5">{{ $message }}</p> @enderror
    </div>
</div>
@endif

<div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
    <div class="px-5 py-3.5 border-b border-gray-100">
        <span class="text-base font-semibold text-gray-900">Further Education</span>
    </div>
    <div class="p-5">
        <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">
            Education Status <span class="text-red-500">*</span>
        </label>
        <div class="flex flex-wrap gap-4">
            @foreach(['none'=>'None','pursuing_masteral'=>'Pursuing Masteral','pursuing_doctorate'=>'Pursuing Doctorate'] as $val=>$lbl)
            <label class="flex items-center gap-2 cursor-pointer">
                <input wire:model="education_status" type="radio" value="{{ $val }}" class="w-4 h-4 accent-[#7a3f91] cursor-pointer">
                <span class="text-sm font-semibold text-gray-900">{{ $lbl }}</span>
            </label>
            @endforeach
        </div>
        @error('education_status') <p class="text-xs text-red-500 mt-1.5">{{ $message }}</p> @enderror
    </div>
</div>

@endif
{{-- End edit form --}}

{{-- ══ RECORD VIEW ══ --}}
@if(!$editing)

@if(!$currentRecord && !$previousRecord)
<div class="bg-white border border-gray-200 rounded-xl p-12 flex flex-col items-center justify-center text-center">
    <p class="font-semibold text-base text-gray-900">No Employment Record Yet</p>
    <p class="text-sm text-gray-500 mt-1">Click <strong class="text-gray-900">Update Employment</strong> to submit your information.</p>
</div>
@else

@if($currentRecord)
@php
    $statusRaw = $currentRecord['employment_status_raw'] ?? '';
    $statusDot = match($statusRaw) {
        'employed'      => 'bg-gray-700',
        'self_employed' => 'bg-gray-700',
        'unemployed'    => 'bg-gray-400',
        default         => 'bg-gray-400',
    };
    $statusIcon = match($statusRaw) {
        'employed'      => 'fa-briefcase',
        'self_employed' => 'fa-store',
        'unemployed'    => 'fa-circle-xmark',
        default         => 'fa-circle-question',
    };
@endphp
<div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
    <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between gap-2">
        <span class="text-base font-semibold text-gray-900">Current Employment Status</span>
        <span class="text-xs text-gray-400">Submitted: {{ $currentRecord['submitted_at'] }}</span>
    </div>
    <div class="p-5 space-y-5">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">Employment Status</span>
                <p class="text-base font-semibold text-gray-900">{{ $currentRecord['employment_status'] }}</p>
            </div>
            <div>
                <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">Further Education</span>
                <p class="text-base font-semibold text-gray-900">{{ $currentRecord['education_status'] ?: '—' }}</p>
            </div>
        </div>
        @if($currentRecord['is_working'])
        @php $isSelfView = str_contains(strtolower($currentRecord['employment_status']), 'self'); @endphp
        <div class="pt-4 border-t border-gray-100 space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">
                        {{ $isSelfView ? 'Business Name' : 'Company Name' }}
                    </span>
                    <p class="text-base font-semibold text-gray-900 uppercase">{{ $currentRecord['company_name'] ?: '—' }}</p>
                </div>
                <div>
                    <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">
                        {{ $isSelfView ? 'Occupation / Role' : 'Job Title' }}
                    </span>
                    <p class="text-base font-semibold text-gray-900">{{ $currentRecord['job_title'] ?: '—' }}</p>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Employment Type</span>
                    <p class="text-base font-semibold text-gray-900">{{ $currentRecord['employment_type'] ?: '—' }}</p>
                </div>
                <div>
                    <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Work Location</span>
                    <p class="text-base font-semibold text-gray-900">{{ $currentRecord['work_location'] ?: '—' }}</p>
                </div>
                <div>
                    <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Course Relevance</span>
                    <p class="text-base font-semibold text-gray-900">{{ $currentRecord['course_relevance'] ?: '—' }}</p>
                </div>
            </div>
            @if(!empty($currentRecord['career_path_labels']))
            <div>
                <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Career Path</span>
                <p class="text-base font-semibold text-gray-900">{{ implode(', ', $currentRecord['career_path_labels']) }}</p>
            </div>
            @endif
        </div>
        @endif
        @if(!$currentRecord['is_working'] && $currentRecord['unemployment_status'])
        <div class="pt-4 border-t border-gray-100">
            <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Job Search Status</span>
            <p class="text-base font-semibold text-gray-900">{{ $currentRecord['unemployment_status'] }}</p>
        </div>
        @endif
    </div>
</div>
@endif

@endif
@endif


{{-- ══ EMPLOYMENT STATUS MODAL ══ --}}
<div x-show="showStatusModal"
     x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
     class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display:none;">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="showStatusModal = false"></div>
    <div x-show="showStatusModal"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95 translate-y-2" x-transition:enter-end="opacity-100 scale-100 translate-y-0"
         x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100 translate-y-0" x-transition:leave-end="opacity-0 scale-95 translate-y-2"
         class="relative bg-white rounded-2xl shadow-2xl w-full max-w-xl max-h-[90vh] flex flex-col overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 flex-shrink-0">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-gray-100 flex items-center justify-center">
                    <i class="fa-solid fa-chart-bar text-gray-600 text-sm"></i>
                </div>
                <div>
                    <h2 class="text-base font-semibold text-gray-900">Employment Status</h2>
                    <p class="text-xs text-gray-500">Current and previous records</p>
                </div>
            </div>
            <button @click="showStatusModal = false" class="w-8 h-8 flex items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition cursor-pointer">
                <i class="fa-solid fa-xmark text-base"></i>
            </button>
        </div>
        <div class="flex border-b border-gray-100 flex-shrink-0 px-6 pt-3 gap-1">
            <button @click="cvTab = 'current'"
                    :class="cvTab === 'current' ? 'border-b-2 border-[#7a3f91] text-[#7a3f91] font-semibold' : 'text-gray-400 hover:text-gray-600'"
                    class="px-4 py-2 text-sm transition cursor-pointer">
                <i class="fa-solid fa-briefcase text-xs mr-1.5"></i>Current
            </button>
            @if($previousRecord)
            <button @click="cvTab = 'previous'"
                    :class="cvTab === 'previous' ? 'border-b-2 border-[#7a3f91] text-[#7a3f91] font-semibold' : 'text-gray-400 hover:text-gray-600'"
                    class="px-4 py-2 text-sm transition cursor-pointer">
                <i class="fa-solid fa-clock-rotate-left text-xs mr-1.5"></i>Previous
            </button>
            @else
            <button disabled class="px-4 py-2 text-sm text-gray-300 cursor-not-allowed">
                <i class="fa-solid fa-clock-rotate-left text-xs mr-1.5"></i>Previous
            </button>
            @endif
        </div>
        <div class="overflow-y-auto flex-1 p-6">
            <div x-show="cvTab === 'current'">
                @if($currentRecord)
                @php
                    $mStatusRaw = $currentRecord['employment_status_raw'] ?? '';
                    $mDot = match($mStatusRaw) {
                        'employed','self_employed' => 'bg-gray-700',
                        default => 'bg-gray-400',
                    };
                @endphp
                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-gray-50 rounded-xl p-4">
                            <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">Status</span>
                            <span class="text-sm font-bold text-gray-900">
                                {{ $currentRecord['employment_status'] }}
                            </span>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-4">
                            <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Education</span>
                            <p class="text-sm font-bold text-gray-900">{{ $currentRecord['education_status'] ?: '—' }}</p>
                        </div>
                    </div>
                    @if($currentRecord['is_working'])
                    <div class="bg-gray-50 rounded-xl p-4 space-y-3">
                        <div>
                            <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Company / Business</span>
                            <p class="text-sm font-bold text-gray-900 uppercase">{{ $currentRecord['company_name'] ?: '—' }}</p>
                        </div>
                        <div>
                            <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Job Title</span>
                            <p class="text-sm font-bold text-gray-900">{{ $currentRecord['job_title'] ?: '—' }}</p>
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Type</span>
                                <p class="text-sm font-bold text-gray-900">{{ $currentRecord['employment_type'] ?: '—' }}</p>
                            </div>
                            <div>
                                <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Location</span>
                                <p class="text-sm font-bold text-gray-900">{{ $currentRecord['work_location'] ?: '—' }}</p>
                            </div>
                            <div>
                                <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Relevance</span>
                                <p class="text-sm font-bold text-gray-900">{{ $currentRecord['course_relevance'] ?: '—' }}</p>
                            </div>
                        </div>
                        @if(!empty($currentRecord['career_path_labels']))
                        <div>
                            <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Career Path</span>
                            <p class="text-sm font-bold text-gray-900">{{ implode(', ', $currentRecord['career_path_labels']) }}</p>
                        </div>
                        @endif
                    </div>
                    @else
                    <div class="bg-gray-50 rounded-xl p-4">
                        <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Job Search Status</span>
                        <p class="text-sm font-bold text-gray-900">{{ $currentRecord['unemployment_status'] ?: '—' }}</p>
                    </div>
                    @endif
                    <p class="text-xs text-gray-400 text-right">Submitted: {{ $currentRecord['submitted_at'] }}</p>
                </div>
                @else
                <p class="text-sm text-gray-400 text-center py-8">No current employment record found.</p>
                @endif
            </div>
            <div x-show="cvTab === 'previous'">
                @if($previousRecord)
                @php
                    $pStatusRaw = $previousRecord['employment_status_raw'] ?? '';
                    $pDot = match($pStatusRaw) {
                        'employed','self_employed' => 'bg-gray-700',
                        default => 'bg-gray-400',
                    };
                @endphp
                <div class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-gray-50 rounded-xl p-4">
                            <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">Status</span>
                            <span class="text-sm font-bold text-gray-900">
                                {{ $previousRecord['employment_status'] }}
                            </span>
                        </div>
                        <div class="bg-gray-50 rounded-xl p-4">
                            <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Education</span>
                            <p class="text-sm font-bold text-gray-900">{{ $previousRecord['education_status'] ?: '—' }}</p>
                        </div>
                    </div>
                    @if($previousRecord['is_working'])
                    <div class="bg-gray-50 rounded-xl p-4 space-y-3">
                        <div>
                            <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Company / Business</span>
                            <p class="text-sm font-bold text-gray-900 uppercase">{{ $previousRecord['company_name'] ?: '—' }}</p>
                        </div>
                        <div>
                            <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Job Title</span>
                            <p class="text-sm font-bold text-gray-900">{{ $previousRecord['job_title'] ?: '—' }}</p>
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            <div>
                                <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Type</span>
                                <p class="text-sm font-bold text-gray-900">{{ $previousRecord['employment_type'] ?: '—' }}</p>
                            </div>
                            <div>
                                <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Location</span>
                                <p class="text-sm font-bold text-gray-900">{{ $previousRecord['work_location'] ?: '—' }}</p>
                            </div>
                            <div>
                                <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Relevance</span>
                                <p class="text-sm font-bold text-gray-900">{{ $previousRecord['course_relevance'] ?: '—' }}</p>
                            </div>
                        </div>
                        @if(!empty($previousRecord['career_path_labels']))
                        <div>
                            <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Career Path</span>
                            <p class="text-sm font-bold text-gray-900">{{ implode(', ', $previousRecord['career_path_labels']) }}</p>
                        </div>
                        @endif
                    </div>
                    @else
                    <div class="bg-gray-50 rounded-xl p-4">
                        <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Job Search Status</span>
                        <p class="text-sm font-bold text-gray-900">{{ $previousRecord['unemployment_status'] ?: '—' }}</p>
                    </div>
                    @endif
                    <p class="text-xs text-gray-400 text-right">Submitted: {{ $previousRecord['submitted_at'] }}</p>
                </div>
                @else
                <p class="text-sm text-gray-400 text-center py-8">No previous employment record found.</p>
                @endif
            </div>
        </div>
        <div class="px-6 py-4 border-t border-gray-100 flex items-center justify-end flex-shrink-0">
            <button @click="showStatusModal = false"
                    class="inline-flex items-center justify-center px-5 py-2.5 rounded-lg bg-gray-100 text-gray-700 text-sm font-semibold cursor-pointer hover:bg-gray-200 active:scale-[.98] transition">
                Close
            </button>
        </div>
    </div>
</div>


{{-- ══ CV MODAL ══ --}}
<div x-show="showCvModal"
     x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
     class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display:none;">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="showCvModal = false"></div>
    <div x-show="showCvModal"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95 translate-y-2" x-transition:enter-end="opacity-100 scale-100 translate-y-0"
         x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 scale-100 translate-y-0" x-transition:leave-end="opacity-0 scale-95 translate-y-2"
         class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col overflow-hidden">

        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 flex-shrink-0">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-blue-50 flex items-center justify-center">
                    <i class="fa-solid fa-file-user text-blue-600 text-sm"></i>
                </div>
                <div>
                    <h2 class="text-base font-semibold text-gray-900">Alumni Employment CV</h2>
                    <p class="text-xs text-gray-500">Employment record summary</p>
                </div>
            </div>
            <button @click="showCvModal = false" class="w-8 h-8 flex items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition cursor-pointer">
                <i class="fa-solid fa-xmark text-base"></i>
            </button>
        </div>

        <div class="overflow-y-auto flex-1" id="cv-print-area">
        @if($currentRecord)
        @php $cvPhoto = $this->getProfilePhotoUrl(); @endphp

        <div style="padding:32px 36px;font-family:'Times New Roman',Times,serif;font-size:12pt;color:#111;line-height:1.6;">

            <div style="display:flex;align-items:center;gap:18px;padding-bottom:14px;border-bottom:1.5px solid #111;margin-bottom:20px;">
                <div style="width:58px;height:58px;border-radius:50%;flex-shrink:0;overflow:hidden;background:#eee;display:flex;align-items:center;justify-content:center;border:1px solid #bbb;">
                    <img src="{{ $cvPhoto }}" style="width:100%;height:100%;object-fit:cover;" alt="{{ $alumniFirstName }}"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                    <span style="display:none;width:100%;height:100%;align-items:center;justify-content:center;font-size:1.1rem;font-weight:bold;color:#555;font-family:'Times New Roman',Times,serif;">
                        {{ strtoupper(substr($alumniFirstName,0,1)) }}{{ strtoupper(substr($alumniLastName,0,1)) }}
                    </span>
                </div>
                <div>
                    <p style="font-size:14pt;font-weight:bold;text-transform:uppercase;letter-spacing:.04em;margin:0 0 2px;">
                        {{ $alumniFullName ?: (auth()->user()->name ?? 'Alumni') }}
                    </p>
                    @if($alumniCourseName)
                    <p style="font-size:11pt;font-style:italic;color:#444;margin:0;">{{ $alumniCourseName }}</p>
                    @endif
                </div>
            </div>

            <div style="display:flex;gap:28px;align-items:flex-start;">
                <div style="width:33%;flex-shrink:0;">
                    <p style="font-size:9pt;font-weight:bold;letter-spacing:.14em;text-transform:uppercase;border-bottom:1px solid #111;padding-bottom:2px;margin:0 0 8px;">Contact</p>
                    @foreach([['Email',$alumniEmail],['Mobile',$alumniContact],['Address',$alumniAddress],['Date of Birth',$alumniDob]] as [$lbl,$val])
                    @if($val)
                    <div style="margin-bottom:7px;">
                        <span style="font-size:8pt;font-weight:bold;text-transform:uppercase;letter-spacing:.1em;color:#666;display:block;margin-bottom:1px;">{{ $lbl }}</span>
                        <span style="font-size:12pt;word-break:break-word;">{{ $val }}</span>
                    </div>
                    @endif
                    @endforeach
                    <p style="font-size:9pt;font-weight:bold;letter-spacing:.14em;text-transform:uppercase;border-bottom:1px solid #111;padding-bottom:2px;margin:16px 0 8px;">Education</p>
                    @if($alumniCourseName)
                    <div style="margin-bottom:7px;">
                        <span style="font-size:8pt;font-weight:bold;text-transform:uppercase;letter-spacing:.1em;color:#666;display:block;margin-bottom:1px;">Degree</span>
                        <span style="font-size:12pt;">{{ $alumniCourseName }}</span>
                    </div>
                    @endif
                    @if($alumniBatch)
                    <div style="margin-top:6px;">
                        <span style="font-size:8pt;font-weight:bold;text-transform:uppercase;letter-spacing:.1em;color:#666;display:block;margin-bottom:1px;">Batch</span>
                        <span style="font-size:12pt;">{{ $alumniBatch }}</span>
                    </div>
                    @endif
                </div>

                <div style="width:1px;background:#ccc;align-self:stretch;flex-shrink:0;"></div>

                <div style="flex:1;min-width:0;">
                    <p style="font-size:9pt;font-weight:bold;letter-spacing:.14em;text-transform:uppercase;border-bottom:1px solid #111;padding-bottom:2px;margin:0 0 10px;">Current Employment</p>
                    @if($currentRecord['is_working'])
                    <p style="font-size:13pt;font-weight:bold;margin:0 0 2px;">{{ $currentRecord['job_title'] ?: '' }}</p>
                    @if($currentRecord['company_name'])
                    <p style="font-size:12pt;font-style:italic;color:#333;text-transform:uppercase;margin:0 0 8px;">{{ $currentRecord['company_name'] }}</p>
                    @endif
                    <p style="font-size:12pt;color:#444;margin:0 0 10px;">
                        {{ implode(' · ', array_filter([$currentRecord['employment_status'],$currentRecord['employment_type'],$currentRecord['work_location'],$currentRecord['course_relevance']])) }}
                    </p>
                    @if(!empty($currentRecord['career_path_labels']))
                    <div style="margin-bottom:10px;">
                        <span style="font-size:8pt;font-weight:bold;text-transform:uppercase;letter-spacing:.1em;color:#666;display:block;margin-bottom:2px;">Career Path</span>
                        <span style="font-size:12pt;">{{ implode(', ', $currentRecord['career_path_labels']) }}</span>
                    </div>
                    @endif
                    @else
                    <p style="font-size:13pt;font-weight:bold;margin:0 0 4px;">{{ $currentRecord['employment_status'] }}</p>
                    @if($currentRecord['unemployment_status'])
                    <p style="font-size:12pt;color:#555;margin:0 0 6px;">{{ $currentRecord['unemployment_status'] }}</p>
                    @endif
                    @endif

                    <p style="font-size:9pt;font-weight:bold;letter-spacing:.14em;text-transform:uppercase;border-bottom:1px solid #111;padding-bottom:2px;margin:16px 0 8px;">Professional Summary</p>
                    <p style="font-size:12pt;color:#333;margin:0;line-height:1.7;">
                        @if($alumniBatch)Batch {{ $alumniBatch }} graduate of @endif{{ $alumniCourseName ?: strtoupper($alumniCourse) }}
                        @if($currentRecord['is_working'])
                            currently working as {{ $currentRecord['job_title'] }}@if($currentRecord['company_name']) at {{ $currentRecord['company_name'] }}@endif.
                            @if($currentRecord['employment_type']) Engaged on a {{ strtolower($currentRecord['employment_type']) }}@if($currentRecord['work_location']), {{ strtolower($currentRecord['work_location']) }}@endif arrangement.@endif
                            @if($currentRecord['course_relevance']) Role is {{ strtolower($currentRecord['course_relevance']) }}.@endif
                        @else
                            currently seeking employment opportunities.
                        @endif
                    </p>
                </div>
            </div>

            <p style="font-size:8pt;color:#bbb;text-align:center;margin-top:24px;padding-top:10px;border-top:1px solid #eee;">
                Generated {{ now()->format('F j, Y') }} &nbsp;&middot;&nbsp; Alumni Employment Tracking System
            </p>
        </div>
        @endif
        </div>

        <div class="px-6 py-4 border-t border-gray-100 flex items-center justify-between flex-shrink-0">
            <p class="text-xs text-gray-400">Generated {{ now()->format('F j, Y') }}</p>
            <div class="flex gap-2">
                <button id="btn-save-word" onclick="downloadCvAsWord()"
                        class="inline-flex items-center justify-center gap-1.5 px-4 py-2 rounded-lg
                               bg-blue-50 text-blue-700 border border-blue-200 text-sm font-semibold
                               cursor-pointer hover:bg-blue-100 active:scale-[.98] transition">
                    <i class="fa-solid fa-file-word text-xs text-blue-600"></i>
                    Save as Word
                </button>
                <button @click="showCvModal = false"
                        class="inline-flex items-center justify-center px-4 py-2 rounded-lg bg-gray-100 text-gray-700 text-sm font-semibold cursor-pointer hover:bg-gray-200 active:scale-[.98] transition">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

</div>

{{-- DOCX SCRIPT --}}
@if($currentRecord)
<script>
const _cvData = {
    fullName:      @json(strtoupper($alumniFullName ?: (auth()->user()->name ?? 'Alumni'))),
    firstName:     @json($alumniFirstName),
    lastName:      @json($alumniLastName),
    email:         @json($alumniEmail),
    contact:       @json($alumniContact),
    address:       @json($alumniAddress),
    dob:           @json($alumniDob),
    course:        @json(strtoupper($alumniCourse)),
    courseName:    @json($alumniCourseName),
    batch:         @json($alumniBatch),
    current: {
        isWorking:    @json($currentRecord['is_working']),
        empStatus:    @json($currentRecord['employment_status']),
        jobTitle:     @json($currentRecord['job_title'] ?? ''),
        company:      @json($currentRecord['company_name'] ?? ''),
        empType:      @json($currentRecord['employment_type'] ?? ''),
        workLocation: @json($currentRecord['work_location'] ?? ''),
        relevance:    @json($currentRecord['course_relevance'] ?? ''),
        careerPaths:  @json($currentRecord['career_path_labels'] ?? []),
        unempStatus:  @json($currentRecord['unemployment_status'] ?? ''),
        submittedAt:  @json($currentRecord['submitted_at']),
    },
    generatedDate: @json(now()->format('F j, Y')),
};

async function downloadCvAsWord() {
    const btn = document.getElementById('btn-save-word');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-xs"></i> Generating...';
    try {
        if (!window.docx) {
            await new Promise((resolve, reject) => {
                const s = document.createElement('script');
                s.src = 'https://cdnjs.cloudflare.com/ajax/libs/docx/8.5.0/docx.umd.min.js';
                s.onload = resolve; s.onerror = reject;
                document.head.appendChild(s);
            });
        }

        const { Document, Packer, Paragraph, TextRun, Table, TableRow, TableCell,
                AlignmentType, BorderStyle, WidthType, VerticalAlign } = window.docx;

        const d   = _cvData;
        const rec = d.current;

        const TNR  = 'Times New Roman';
        const PT12 = 24; const PT9 = 18; const PT8 = 16;
        const PT13 = 26; const PT14 = 28; const PT11 = 22;

        const NONE      = { style: BorderStyle.NONE, size: 0, color: 'FFFFFF' };
        const noBorders = { top: NONE, bottom: NONE, left: NONE, right: NONE };
        const thinBlack = { style: BorderStyle.SINGLE, size: 6, color: '111111' };
        const thinGray  = { style: BorderStyle.SINGLE, size: 4, color: 'EEEEEE' };

        const secHead = (text) => new Paragraph({
            spacing: { before: 180, after: 60 },
            border: { bottom: thinBlack },
            children: [new TextRun({ text, font: TNR, size: PT9, bold: true, allCaps: true, color: '111111' })],
        });
        const lbl = (text) => new Paragraph({
            spacing: { before: 100, after: 16 },
            children: [new TextRun({ text, font: TNR, size: PT8, bold: true, allCaps: true, color: '666666' })],
        });
        const body = (text, italic = false, color = '111111') => new Paragraph({
            spacing: { before: 0, after: 80 },
            children: [new TextRun({ text: text || '', font: TNR, size: PT12, italic, color })],
        });
        const dotLine = (parts) => {
            const f = parts.filter(Boolean);
            return new Paragraph({
                spacing: { before: 0, after: 80 },
                children: f.map((p, i) => new TextRun({
                    text: i < f.length - 1 ? p + '  ·  ' : p,
                    font: TNR, size: PT12, color: '444444',
                })),
            });
        };

        const leftChildren = [secHead('Contact')];
        [['Email', d.email], ['Mobile', d.contact], ['Address', d.address], ['Date of Birth', d.dob]]
            .forEach(([label, val]) => { if (val) { leftChildren.push(lbl(label), body(val)); } });
        leftChildren.push(secHead('Education'));
        if (d.courseName) { leftChildren.push(lbl('Degree'), body(d.courseName)); }
        if (d.batch)      { leftChildren.push(lbl('Batch'),  body(d.batch)); }

        const rightChildren = [secHead('Current Employment')];
        if (rec.isWorking) {
            rightChildren.push(new Paragraph({
                spacing: { before: 60, after: 20 },
                children: [new TextRun({ text: rec.jobTitle, font: TNR, size: PT13, bold: true, color: '111111' })],
            }));
            if (rec.company) {
                rightChildren.push(new Paragraph({
                    spacing: { before: 0, after: 80 },
                    children: [new TextRun({ text: rec.company, font: TNR, size: PT12, italic: true, color: '333333', allCaps: true })],
                }));
            }
            rightChildren.push(dotLine([rec.empStatus, rec.empType, rec.workLocation, rec.relevance]));
            if (rec.careerPaths && rec.careerPaths.length) {
                rightChildren.push(lbl('Career Path'), body(rec.careerPaths.join(', '), false, '444444'));
            }
        } else {
            rightChildren.push(new Paragraph({
                spacing: { before: 60, after: 40 },
                children: [new TextRun({ text: rec.empStatus, font: TNR, size: PT13, bold: true, color: '111111' })],
            }));
            if (rec.unempStatus) { rightChildren.push(body(rec.unempStatus, false, '555555')); }
        }

        rightChildren.push(secHead('Professional Summary'));
        let summary = '';
        if (d.batch) summary += `Batch ${d.batch} graduate of `;
        summary += d.courseName || d.course;
        if (rec.isWorking) {
            summary += ` currently working as ${rec.jobTitle}`;
            if (rec.company) summary += ` at ${rec.company}`;
            summary += '.';
            if (rec.empType) {
                summary += ` Engaged on a ${rec.empType.toLowerCase()}`;
                if (rec.workLocation) summary += `, ${rec.workLocation.toLowerCase()}`;
                summary += ' arrangement.';
            }
            if (rec.relevance) summary += ` Role is ${rec.relevance.toLowerCase()}.`;
        } else {
            summary += ' currently seeking employment opportunities.';
        }
        rightChildren.push(new Paragraph({
            spacing: { before: 60, after: 0 },
            children: [new TextRun({ text: summary, font: TNR, size: PT12, color: '333333' })],
        }));

        const nameP = new Paragraph({
            spacing: { before: 0, after: 30 },
            children: [new TextRun({ text: d.fullName, font: TNR, size: PT14, bold: true, allCaps: true, color: '111111' })],
        });
        const subP = d.courseName ? new Paragraph({
            spacing: { before: 0, after: 0 },
            children: [new TextRun({ text: d.courseName, font: TNR, size: PT11, italic: true, color: '444444' })],
        }) : null;
        const divider = new Paragraph({
            spacing: { before: 120, after: 120 },
            border: { bottom: { style: BorderStyle.SINGLE, size: 8, color: '111111' } },
            children: [],
        });
        const headerChildren = [nameP];
        if (subP) headerChildren.push(subP);
        headerChildren.push(divider);

        const twoCol = new Table({
            width: { size: 9360, type: WidthType.DXA },
            columnWidths: [3000, 6360],
            borders: { top: NONE, bottom: NONE, left: NONE, right: NONE, insideH: NONE, insideV: NONE },
            rows: [new TableRow({ children: [
                new TableCell({
                    borders: { top: NONE, bottom: NONE, left: NONE, right: { style: BorderStyle.SINGLE, size: 4, color: 'CCCCCC' } },
                    width: { size: 3000, type: WidthType.DXA },
                    margins: { top: 0, bottom: 0, left: 0, right: 180 },
                    verticalAlign: VerticalAlign.TOP,
                    children: leftChildren,
                }),
                new TableCell({
                    borders: noBorders,
                    width: { size: 6360, type: WidthType.DXA },
                    margins: { top: 0, bottom: 0, left: 220, right: 0 },
                    verticalAlign: VerticalAlign.TOP,
                    children: rightChildren,
                }),
            ]})],
        });

        const footer = new Paragraph({
            alignment: AlignmentType.CENTER,
            spacing: { before: 280, after: 0 },
            border: { top: thinGray },
            children: [new TextRun({
                text: `Generated ${d.generatedDate}  —  Alumni Employment Tracking System`,
                font: TNR, size: PT8, color: 'BBBBBB',
            })],
        });

        const doc = new Document({
            sections: [{
                properties: { page: { size: { width: 12240, height: 15840 }, margin: { top: 1080, right: 1080, bottom: 1080, left: 1080 } } },
                children: [...headerChildren, twoCol, footer],
            }],
        });

        const buffer = await Packer.toBlob(doc);
        const url = URL.createObjectURL(buffer);
        const a = document.createElement('a');
        a.href = url;
        a.download = `CV_${d.fullName.replace(/\s+/g, '_')}.docx`;
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
        URL.revokeObjectURL(url);

    } catch (err) {
        console.error('CV generation error:', err);
        alert('Failed to generate Word document. Please try again.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-file-word text-xs text-blue-600"></i> Save as Word';
    }
}
</script>
@endif