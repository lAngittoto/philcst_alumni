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
    public int    $alumniId       = 0;
    public int    $trackingId     = 0;

    // ── Records ───────────────────────────────────────────────────────────────
    public ?array $currentRecord = null;
    public ?array $previousRecord = null;

    // ── Alumni Course Context ─────────────────────────────────────────────────
    public string $alumniCourse     = '';
    public string $alumniCourseName = '';
    public array  $jobOptions       = [];

    // ── Core Status ───────────────────────────────────────────────────────────
    public string $employment_status = '';

    // ── Employed / Self-Employed Fields ───────────────────────────────────────
    public string $company_name      = '';
    public string $job_title         = '';
    public string $custom_job_title  = '';
    public string $employment_type   = '';
    public string $work_location     = '';
    public array  $career_path       = [];
    public string $course_relevance  = '';

    // ── Unemployed Fields ─────────────────────────────────────────────────────
    public string $unemployment_status = '';

    // ── Common ────────────────────────────────────────────────────────────────
    public string $education_status = '';

    protected array $snapshot = [];

    // ═════════════════════════════════════════════════════════════════════════
    //  Course Group Detection
    // ═════════════════════════════════════════════════════════════════════════

    protected function getCourseGroup(string $code): string
    {
        $c = strtoupper(trim($code));
        if (preg_match('/\b(BSIT|BSCS|BSIS|BSCPE|BSICT|IT|CS|CPE|ICT)\b/', $c)) return 'technology';
        if (preg_match('/\b(BSN|BSMT)\b/', $c) || str_contains($c, 'NURS'))         return 'nursing';
        if (preg_match('/\b(BSED|BEED|BELTE|BTTE|MAED)\b/', $c)
            || str_contains($c, 'EDUC') || str_contains($c, 'TEACH'))               return 'education';
        if (preg_match('/\b(BSACCT|BSAC|BSMA|BSA)\b/', $c)
            || str_contains($c, 'ACCOUNT'))                                          return 'accounting';
        if (preg_match('/\b(BSBA|BSBM|BSENT|BSMGT|BSIB|BSHRM)\b/', $c)
            || str_contains($c, 'BUSINESS') || str_contains($c, 'MARKET'))          return 'business';
        if (preg_match('/\b(BSCE|BSME|BSEE|BSECE|BSIE|BSCHE|BSEM|BSCPE)\b/', $c)
            || str_contains($c, 'ENGINEER'))                                         return 'engineering';
        if (preg_match('/\b(BSPT|BSOT|BSRT|BSMLS|BSPHARM|BSRAD|BSMED)\b/', $c)
            || str_contains($c, 'PHARM') || str_contains($c, 'THERAP'))             return 'healthcare';
        if (str_contains($c, 'CRIM'))                                                return 'criminology';
        if (preg_match('/\b(BSHTM|BSHM|BSTM|BSTHM)\b/', $c)
            || str_contains($c, 'HOSP') || str_contains($c, 'TOURISM')
            || str_contains($c, 'HOTEL'))                                            return 'hospitality';
        if (str_contains($c, 'PSYCH'))                                               return 'psychology';
        if (str_contains($c, 'COMM') || str_contains($c, 'JOURN')
            || str_contains($c, 'MEDIA') || str_contains($c, 'BROADCAST'))          return 'communications';
        if (str_contains($c, 'ARCH'))                                                return 'architecture';
        if (str_contains($c, 'LAW') || str_contains($c, 'LLB') || $c === 'JD')     return 'law';
        return 'general';
    }

    protected function detectJobRelevance(string $title): string
    {
        $t = strtolower(trim($title));
        if (empty($t)) return '';
        $group = $this->getCourseGroup($this->alumniCourse);

        $yesKw = [
            'technology'     => ['developer','programmer','software','web dev','mobile app','network engineer','database admin','sysadmin','devops','cloud engineer','cybersecurity','data scientist','data analyst','ui/ux','it support','qa engineer','ml engineer','ai engineer','tech lead','systems analyst','ict','computer engineer','full stack','backend','frontend','it officer','helpdesk','network admin','it manager','it specialist','information technology','computer science','system developer','software engineer'],
            'nursing'        => ['nurse','nursing','rn ','registered nurse','icu','er nurse','surgical nurse','ward nurse','dialysis nurse','pediatric nurse','public health nurse','head nurse','charge nurse','clinical nurse','operating room nurse','or nurse'],
            'education'      => ['teacher','instructor','professor','tutor','faculty','educator','academic coordinator','school principal','curriculum developer','lecturer','teaching','special education','classroom teacher','school admin','school head','subject teacher','grade school','high school teacher','college instructor','tesda trainer','tesda teacher','vocational trainer','skills trainer'],
            'accounting'     => ['accountant','auditor','cpa','tax specialist','bookkeeper','accounting','finance officer','budget analyst','payroll','internal auditor','external auditor','financial analyst','management accountant','cost accountant','revenue officer'],
            'business'       => ['marketing manager','sales manager','business analyst','hr officer','operations manager','management trainee','business owner','entrepreneur','brand manager','product manager','account manager','business development','merchandising','trade marketing','retail manager','commercial manager'],
            'engineering'    => ['engineer','civil engineer','mechanical engineer','electrical engineer','structural engineer','construction manager','project engineer','quality engineer','process engineer','industrial engineer','plant engineer','design engineer','site engineer','engineering manager','chief engineer'],
            'healthcare'     => ['pharmacist','physical therapist','radiologic technologist','medical technologist','occupational therapist','respiratory therapist','dentist','dental','midwife','radiographer','med tech','pharmacy','therapist','clinical'],
            'criminology'    => ['police officer','pnp','nbi agent','forensic analyst','criminologist','jail officer','fire officer','law enforcement','detective','intelligence officer','criminal investigator','bureau of corrections','bfp','bucor'],
            'hospitality'    => ['hotel manager','chef','sous chef','restaurant manager','front desk officer','tour guide','event coordinator','flight attendant','travel agent','catering manager','hospitality manager','food and beverage','f&b manager','banquet manager','concierge','housekeeping manager','rooms division'],
            'psychology'     => ['psychologist','guidance counselor','social worker','mental health','psychiatry','behavior analyst','clinical psychologist','counseling','psychology','welfare officer','rehabilitation counselor'],
            'communications' => ['journalist','reporter','broadcast journalist','public relations','pr officer','content writer','copywriter','social media manager','advertising','media planner','editor','communications officer','news writer','feature writer','anchor','media relations','communications specialist'],
            'architecture'   => ['architect','interior designer','urban planner','draftsman','cad operator','architectural','landscape architect','space planner','master planner','building designer','architectural designer'],
            'law'            => ['lawyer','attorney','legal officer','paralegal','court interpreter','judge','prosecutor','public attorney','legal counsel','law practitioner','notary public','legal consultant','solicitor'],
            'general'        => [],
        ];
        $partialKw = [
            'technology'     => ['it ','tech ','digital','computer','online','system','app ','platform','encoder','data entry','web','virtual','it-related','tech support','technical','network','technical writer','technical support','it coordinator','it staff'],
            'nursing'        => ['health','clinic','hospital','patient','caregiver','medical','lab','healthcare','home care','nursing aide','orderly','health worker'],
            'education'      => ['trainer','training','coach','mentor','facilitator','tesda','reviewer','subject matter','tutorial','educational','school','learning','academic','instruction','development officer'],
            'accounting'     => ['finance','billing','cashier','collections','admin','accounts','financial','treasury','comptroller','budgeting','disbursement'],
            'business'       => ['admin','officer','coordinator','supervisor','team lead','store manager','operations','logistics','supply chain','purchasing','procurement','inventory','warehouse','distribution'],
            'engineering'    => ['technician','maintenance','inspector','surveyor','estimator','drafter','foreman','supervisor','technical','construction','fabricator'],
            'healthcare'     => ['health','clinic','hospital','medical','lab','pharmacy','dental assistant','health aide','nursing aide','care worker','healthcare worker'],
            'criminology'    => ['security guard','security officer','investigator','enforcement','warden','safety officer','risk','compliance','guard','patrol'],
            'hospitality'    => ['food','service','hospitality','accommodation','barista','waiter','bartender','concierge','receptionist','housekeeping','tourism'],
            'psychology'     => ['hr','recruiter','training officer','social services','welfare','employee relations','organizational','people','talent'],
            'communications' => ['writer','editor','media','content','marketing','communications','social media','digital marketing','creative','blogger','vlogger'],
            'architecture'   => ['design','planning','drafting','construction','estimator','3d','rendering','visualization','autocad','revit','sketchup'],
            'law'            => ['compliance','regulatory','policy','governance','legal assistant','court','justice','contracts','documentation','corporate secretary'],
            'general'        => ['officer','staff','coordinator','supervisor','manager','specialist'],
        ];

        if (str_contains($t, 'tesda')) return $group === 'education' ? 'yes' : 'partially';
        foreach ($yesKw[$group] ?? [] as $kw) { if (str_contains($t, strtolower($kw))) return 'yes'; }
        foreach ($partialKw[$group] ?? [] as $kw) { if (str_contains($t, strtolower($kw))) return 'partially'; }
        return 'no';
    }

    protected function buildJobOptions(): array
    {
        $group = $this->getCourseGroup($this->alumniCourse);
        $map = [
            'technology'     => ['Software Developer','Web Developer','Mobile App Developer','Systems Analyst','Database Administrator','Network Engineer','IT Support Specialist','Cybersecurity Analyst','Data Analyst / Data Scientist','UI / UX Designer','QA / Test Engineer','DevOps / Cloud Engineer','AI / ML Engineer','Technical Project Manager'],
            'nursing'        => ['Registered Nurse (RN)','ICU / Critical Care Nurse','ER / Emergency Nurse','Head Nurse / Supervisor','OR / Surgical Nurse','Pediatric Nurse','Public Health Nurse','Dialysis Nurse','OFW / International Nurse'],
            'education'      => ['Elementary School Teacher','High School Teacher','Special Education Teacher','College Instructor','School Principal / Admin','Academic / Curriculum Coordinator','Tutor / Review Center Instructor'],
            'accounting'     => ['Certified Public Accountant (CPA)','Auditor','Financial Analyst','Tax Specialist','Budget Analyst','Bookkeeper','Accounting Officer / Staff','Internal Auditor','Finance Manager'],
            'business'       => ['Marketing Manager / Officer','Sales Manager','Operations Manager','Business Analyst','HR Officer / HR Manager','Management Trainee','Administrative Officer','Entrepreneur / Business Owner'],
            'engineering'    => ['Civil Engineer','Mechanical Engineer','Electrical Engineer','Electronics Engineer','Chemical Engineer','Industrial Engineer','Project Engineer','Quality Assurance Engineer','Construction Engineer / Manager'],
            'healthcare'     => ['Pharmacist','Physical Therapist','Radiologic Technologist','Medical Technologist','Occupational Therapist','Respiratory Therapist','Midwife','Dentist'],
            'criminology'    => ['PNP Officer / Police Officer','NBI Agent','Criminologist','Jail Officer / BuCor','Forensic Analyst','Security Officer / Supervisor','Fire Officer (BFP)'],
            'hospitality'    => ['Hotel Manager','Front Desk Officer','Restaurant Manager','Chef / Sous Chef','Tour Guide','Event Coordinator','Flight Attendant / Cabin Crew','Travel Agent'],
            'psychology'     => ['Psychologist','Guidance Counselor','HR Officer / Recruiter','Social Worker','Mental Health Counselor','Training & Development Officer'],
            'communications' => ['Journalist / Reporter','Public Relations Officer','Broadcast Journalist','Content Creator / Writer','Social Media Manager','Copywriter','Advertising Specialist','Media Planner'],
            'architecture'   => ['Architect','Interior Designer','Urban Planner','Draftsman / CAD Operator','Construction Manager'],
            'law'            => ['Lawyer / Attorney','Legal Officer','Court Interpreter','Paralegal','Legal Researcher'],
            'general'        => ['Administrative Officer','Office Staff','Customer Service Representative','Sales Representative'],
        ];
        $titles   = $map[$group] ?? $map['general'];
        $titles[] = 'Other';
        return $titles;
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function mount(): void
    {
        $user = auth()->user();
        if (!$user || $user->role !== 'alumni') { $this->redirect(route('login')); return; }

        $alumni = Alumni::where('user_id', $user->id)
            ->select(['id', 'course_code', 'course_name'])->first();
        if (!$alumni) { $this->redirect(route('login')); return; }

        $this->alumniId         = $alumni->id;
        $this->alumniCourse     = $alumni->course_code ?? '';
        $this->alumniCourseName = $alumni->course_name ?? '';
        $this->jobOptions       = $this->buildJobOptions();

        $this->loadRecord();
        $this->loadTwoRecords();
    }

    protected function loadRecord(): void
    {
        $record = DB::table('employment_trackings')
            ->where('alumni_id', $this->alumniId)
            ->whereNull('deleted_at')
            ->latest('created_at')
            ->first();

        if ($record) {
            $this->trackingId          = $record->id;
            $this->employment_status   = $record->employment_status   ?? '';
            $this->company_name        = $record->company_name        ?? '';
            $this->employment_type     = $record->employment_type     ?? '';
            $this->work_location       = $record->work_location       ?? '';
            $this->career_path         = $record->career_path ? json_decode($record->career_path, true) : [];
            $this->education_status    = $record->education_status    ?? '';
            $this->course_relevance    = $record->course_relevance    ?? '';
            $this->unemployment_status = $record->unemployment_status ?? '';

            $loadedJobTitle = $record->job_title ?? '';
            if ($loadedJobTitle && !in_array($loadedJobTitle, $this->jobOptions, true)) {
                $this->job_title        = 'Other';
                $this->custom_job_title = $loadedJobTitle;
            } else {
                $this->job_title        = $loadedJobTitle;
                $this->custom_job_title = '';
            }

            $this->hasRecord = true;
            $this->editing   = false;
        } else {
            $this->trackingId       = 0;
            $this->job_title        = '';
            $this->custom_job_title = '';
            $this->hasRecord        = false;
            $this->editing          = true;
        }
    }

    protected function loadTwoRecords(): void
    {
        $typeLabels   = ['full_time'=>'Full-Time','part_time'=>'Part-Time','contractual'=>'Contractual','project_based'=>'Project-Based','internship'=>'Internship'];
        $careerLabels = ['ofw'=>'OFW','freelancer'=>'Freelancer','entrepreneur'=>'Entrepreneur','career_shifter'=>'Career Shifter','industry_professional'=>'Industry Professional'];
        $eduLabels    = ['none'=>'None','pursuing_masteral'=>'Pursuing Masteral','pursuing_doctorate'=>'Pursuing Doctorate'];
        $relLabels    = ['yes'=>'Related to Course','no'=>'Not Related','partially'=>'Partially Related'];
        $unLabels     = ['seeking_employment'=>'Actively Seeking Employment','not_looking'=>'Not Currently Looking'];
        $statusLabels = ['employed'=>'Employed','self_employed'=>'Self-Employed','unemployed'=>'Unemployed'];

        $mapRecord = function ($r) use ($typeLabels, $careerLabels, $eduLabels, $relLabels, $unLabels, $statusLabels) {
            $cp = $r->career_path ? json_decode($r->career_path, true) : [];
            return [
                'id'                  => $r->id,
                'employment_status'   => $statusLabels[$r->employment_status ?? ''] ?? ucfirst($r->employment_status ?? ''),
                'is_working'          => in_array($r->employment_status ?? '', ['employed','self_employed']),
                'company_name'        => $r->company_name ?? '',
                'job_title'           => $r->job_title ?? '',
                'employment_type'     => $typeLabels[$r->employment_type ?? ''] ?? '',
                'work_location'       => ucfirst($r->work_location ?? ''),
                'career_path_labels'  => array_values(array_filter(array_map(fn($v) => $careerLabels[$v] ?? null, $cp))),
                'course_relevance'    => $relLabels[$r->course_relevance ?? ''] ?? '',
                'unemployment_status' => $unLabels[$r->unemployment_status ?? ''] ?? '',
                'education_status'    => $eduLabels[$r->education_status ?? ''] ?? '',
                'submitted_at'        => $r->created_at ? \Carbon\Carbon::parse($r->created_at)->format('F j, Y') : '',
            ];
        };

        // Current = not soft-deleted
        $current = DB::table('employment_trackings')
            ->where('alumni_id', $this->alumniId)
            ->whereNull('deleted_at')
            ->latest('created_at')
            ->first();
        $this->currentRecord = $current ? $mapRecord($current) : null;

        // Previous = most recent soft-deleted record
        $previous = DB::table('employment_trackings')
            ->where('alumni_id', $this->alumniId)
            ->whereNotNull('deleted_at')
            ->latest('created_at')
            ->first();
        $this->previousRecord = $previous ? $mapRecord($previous) : null;
    }

    // ── Edit / Cancel ─────────────────────────────────────────────────────────

    public function startEditing(): void
    {
        $this->errorMessage = $this->successMessage = '';
        $this->snapshot = [];
        foreach (['employment_status','company_name','job_title','custom_job_title',
                  'employment_type','work_location','career_path','education_status',
                  'course_relevance','unemployment_status'] as $k) {
            $this->snapshot[$k] = $this->$k;
        }
        $this->editing = true;
    }

    public function cancelEditing(): void
    {
        $this->errorMessage = $this->successMessage = '';
        $this->resetValidation();
        foreach ($this->snapshot as $k => $v) { $this->$k = $v; }
        $this->editing = false;
    }

    // ── Reactive Hooks ────────────────────────────────────────────────────────

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
            $this->course_relevance = '';
            $this->custom_job_title = '';
        } elseif ($this->job_title !== '') {
            $this->course_relevance = 'yes';
            $this->custom_job_title = '';
        } else {
            $this->course_relevance = '';
            $this->custom_job_title = '';
        }
    }

    public function updatedCustomJobTitle(): void
    {
        if ($this->job_title === 'Other') {
            $this->course_relevance = $this->detectJobRelevance($this->custom_job_title);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    protected function statusLabel(string $status): string
    {
        return match ($status) {
            'employed'      => 'Employed',
            'self_employed' => 'Self-Employed',
            'unemployed'    => 'Unemployed',
            default         => ucfirst(str_replace('_', ' ', $status)),
        };
    }

    protected function saveRegistrarNotification(array $payload): void
    {
        try {
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
        } catch (\Throwable $e) {
            Log::warning('Registrar notification insert failed: ' . $e->getMessage());
        }
    }

    // ── Save ─────────────────────────────────────────────────────────────────

    public function saveEmployment(): void
    {
        $this->errorMessage = $this->successMessage = '';
        $this->company_name = strtoupper(trim($this->company_name));

        $isOther = ($this->job_title === 'Other');
        if ($isOther) {
            $this->custom_job_title = trim($this->custom_job_title);
            if ($this->custom_job_title && !$this->course_relevance) {
                $this->course_relevance = $this->detectJobRelevance($this->custom_job_title);
            }
        }

        $working = in_array($this->employment_status, ['employed', 'self_employed']);
        if ($working && $this->job_title && !$isOther) {
            $this->course_relevance = 'yes';
        }

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
            $now  = now();
            $data = [
                'alumni_id'           => $this->alumniId,
                'employment_status'   => $this->employment_status,
                'education_status'    => $this->education_status   ?: null,
                'company_name'        => $working ? ($this->company_name    ?: null) : null,
                'job_title'           => $working ? ($finalJobTitle         ?: null) : null,
                'employment_type'     => $working ? ($this->employment_type ?: null) : null,
                'work_location'       => $working ? ($this->work_location   ?: null) : null,
                'date_hired'          => null,
                'career_path'         => $working && count($this->career_path) ? json_encode(array_values($this->career_path)) : null,
                'course_relevance'    => $finalRelevance,
                'unemployment_status' => $this->employment_status === 'unemployed' ? ($this->unemployment_status ?: null) : null,
                'created_at'          => $now,
                'updated_at'          => $now,
            ];

            $isNewRecord = ($this->trackingId === 0);
            $oldStatus   = $this->snapshot['employment_status'] ?? null;

            DB::transaction(function () use ($data, $now) {
                if ($this->trackingId) {
                    DB::table('employment_trackings')
                        ->where('id', $this->trackingId)
                        ->update(['deleted_at' => $now, 'updated_at' => $now]);
                }
                $this->trackingId = DB::table('employment_trackings')->insertGetId($data);
            });

            $this->hasRecord      = true;
            $this->editing        = false;
            $this->successMessage = 'Employment information updated successfully!';

            $alumni = \App\Models\Alumni::find($this->alumniId);
            $name   = trim(($alumni->first_name ?? '') . ' ' . ($alumni->last_name ?? ''));

            if ($isNewRecord) {
                $this->saveRegistrarNotification([
                    'icon'       => 'briefcase',
                    'title'      => 'New Employment Record',
                    'message'    => $name . ' submitted a new employment record as ' . $this->statusLabel($this->employment_status) . '.',
                    'link_route' => 'registrar.employment.tracking',
                    'link_label' => 'View Tracking',
                    'dedup_key'  => 'recorded::' . $this->employment_status,
                ]);
            } else {
                $from = $oldStatus ? ' from ' . $this->statusLabel($oldStatus) : '';
                $this->saveRegistrarNotification([
                    'icon'       => 'arrow-rotate-right',
                    'title'      => 'Employment Status Updated',
                    'message'    => $name . ' updated their employment status' . $from . ' to ' . $this->statusLabel($this->employment_status) . '.',
                    'link_route' => 'registrar.employment.tracking',
                    'link_label' => 'View Tracking',
                    'dedup_key'  => 'updated::' . $this->employment_status,
                ]);
            }

            $this->loadRecord();
            $this->loadTwoRecords();

            Log::info("Employment saved | alumni_id:{$this->alumniId} | status:{$this->employment_status}");

        } catch (\Throwable $e) {
            Log::error('Employment save error: ' . $e->getMessage());
            $this->errorMessage = 'Failed to save. Please try again.';
        }
    }
}; ?>

{{-- ══ ROOT ══════════════════════════════════════════════════════════════════ --}}
<div
    x-data="{ showHistoryModal: false, showCvModal: false }"
    class="space-y-6"
>

{{-- ── Page header ──────────────────────────────────────────────────────────── --}}
<div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
    <div class="flex items-center gap-3">
        <div class="w-[42px] h-[42px] rounded-xl bg-[#7a3f91] flex items-center justify-center flex-shrink-0">
            <i class="fa-solid fa-briefcase text-white text-base"></i>
        </div>
        <div>
            <h1 class="text-2xl font-semibold text-gray-900 tracking-tight">Employment Tracking</h1>
            <p class="text-sm text-gray-500 mt-0.5">
                Keep your employment status up to date.
                Fields marked <span class="text-red-500 font-semibold">*</span> are required.
                @if($alumniCourse)
                    <span class="font-semibold text-purple-700 ml-1">{{ $alumniCourse }}</span>
                @endif
            </p>
        </div>
    </div>
    <div class="flex items-center gap-2 flex-wrap">
        @if(!$editing)
        <button wire:click="startEditing"
                class="inline-flex items-center justify-center gap-1.5 px-6 py-2.5 rounded-lg
                       bg-[#7a3f91] text-white text-sm font-semibold cursor-pointer
                       hover:opacity-90 active:scale-[.98] transition">
            Update Employment
        </button>
        @endif
    </div>
</div>

{{-- ── Alerts ──────────────────────────────────────────────────────────────── --}}
@if($errorMessage)
    <div class="rounded-xl px-4 py-3 text-sm border bg-red-50 text-red-600 border-red-200">{{ $errorMessage }}</div>
@endif
@if($successMessage)
    <div class="rounded-xl px-4 py-3 text-sm border bg-green-50 text-green-700 border-green-200">{{ $successMessage }}</div>
@endif

{{-- ════════════════════════════════════════════════════════════════════════════
     EDIT FORM
════════════════════════════════════════════════════════════════════════════ --}}
@if($editing)

{{-- SECTION 1 — Employment Status --}}
<div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
    <div class="px-5 py-3.5 border-b border-gray-100">
        <span class="text-base font-semibold text-gray-900">Employment Status</span>
    </div>
    <div class="p-5">
        <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">
            Current Status <span class="text-red-500">*</span>
        </label>
        <div class="flex flex-wrap gap-3">
            <label class="flex items-center gap-2 cursor-pointer">
                <input wire:model.live="employment_status" type="radio" value="employed"
                       class="w-4 h-4 accent-[#7a3f91] cursor-pointer">
                <span class="text-sm font-semibold text-gray-900">Employed</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
                <input wire:model.live="employment_status" type="radio" value="self_employed"
                       class="w-4 h-4 accent-[#7a3f91] cursor-pointer">
                <span class="text-sm font-semibold text-gray-900">Self-Employed</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
                <input wire:model.live="employment_status" type="radio" value="unemployed"
                       class="w-4 h-4 accent-[#7a3f91] cursor-pointer">
                <span class="text-sm font-semibold text-gray-900">Unemployed</span>
            </label>
        </div>
        @error('employment_status')
            <p class="text-xs text-red-500 mt-1.5">{{ $message }}</p>
        @enderror
    </div>
</div>

{{-- SECTION 2 — Employment Details (Employed / Self-Employed) --}}
@if(in_array($employment_status, ['employed','self_employed']))
<div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
    <div class="px-5 py-3.5 border-b border-gray-100">
        <span class="text-base font-semibold text-gray-900">Employment Details</span>
    </div>
    <div class="p-5 space-y-5">

        {{-- Company / Business Name --}}
        <div class="max-w-lg">
            <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1.5">
                {{ $employment_status === 'self_employed' ? 'Business Name' : 'Company Name' }}
                <span class="text-red-500">*</span>
            </label>
            <input wire:model="company_name" type="text"
                   placeholder="{{ $employment_status === 'self_employed' ? 'E.G. ABC TRADING' : 'E.G. JOLLIBEE FOODS CORP.' }}"
                   oninput="this.value=this.value.toUpperCase()"
                   class="w-full box-border text-base font-normal text-gray-900 bg-gray-50
                          border border-gray-200 rounded-lg px-3 py-2 uppercase tracking-wide
                          transition hover:border-gray-300 focus:outline-none focus:border-[#7a3f91]
                          focus:ring-2 focus:ring-[#7a3f91]/10 focus:bg-white
                          {{ $errors->has('company_name') ? 'border-red-500' : '' }}">
            @error('company_name') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
        </div>

        {{-- Job Title --}}
        <div class="max-w-lg">
            <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1.5">
                {{ $employment_status === 'self_employed' ? 'Occupation / Role' : 'Job Title' }}
                <span class="text-red-500">*</span>
            </label>
            <select wire:model.live="job_title"
                    class="w-full box-border text-base font-normal text-gray-900 bg-gray-50
                           border border-gray-200 rounded-lg px-3 py-2
                           transition hover:border-gray-300 focus:outline-none focus:border-[#7a3f91]
                           focus:ring-2 focus:ring-[#7a3f91]/10 focus:bg-white
                           {{ $errors->has('job_title') ? 'border-red-500' : '' }}">
                <option value="">— Select Job Title —</option>
                @foreach($jobOptions as $title)
                    <option value="{{ $title }}">{{ $title }}</option>
                @endforeach
            </select>
            @error('job_title') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror

            @if($job_title && $job_title !== 'Other')
                <p class="text-xs text-emerald-600 mt-1.5 font-medium">
                    Auto-detected: Related to your course.
                </p>
            @endif

            @if($job_title === 'Other')
            <div class="mt-3 space-y-3">
                <div>
                    <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1.5">
                        Please Specify <span class="text-red-500">*</span>
                    </label>
                    <input wire:model.live="custom_job_title" type="text" maxlength="255"
                           placeholder="e.g. Marine Engineer, Fashion Designer…"
                           class="w-full box-border text-base font-normal text-gray-900 bg-gray-50
                                  border border-gray-200 rounded-lg px-3 py-2
                                  transition hover:border-gray-300 focus:outline-none focus:border-[#7a3f91]
                                  focus:ring-2 focus:ring-[#7a3f91]/10 focus:bg-white
                                  {{ $errors->has('custom_job_title') ? 'border-red-500' : '' }}">
                    @error('custom_job_title') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                </div>
                @if($custom_job_title)
                <div>
                    <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Auto-Detected Relevance: </span>
                    @php
                        $relText = match($course_relevance) {
                            'yes'       => 'Related to Course',
                            'partially' => 'Partially Related',
                            'no'        => 'Not Related',
                            default     => 'Detecting…',
                        };
                        $relColor = match($course_relevance) {
                            'yes'       => 'text-emerald-700',
                            'partially' => 'text-amber-700',
                            'no'        => 'text-red-700',
                            default     => 'text-gray-400',
                        };
                    @endphp
                    <span class="text-xs font-semibold {{ $relColor }}">{{ $relText }}</span>
                </div>
                @endif
            </div>
            @endif
        </div>

        {{-- Employment Type --}}
        <div>
            <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">
                Employment Type <span class="text-red-500">*</span>
            </label>
            <div class="flex flex-wrap gap-4">
                @foreach(['full_time'=>'Full-Time','part_time'=>'Part-Time','contractual'=>'Contractual','project_based'=>'Project-Based','internship'=>'Internship'] as $val=>$lbl)
                <label class="flex items-center gap-2 cursor-pointer">
                    <input wire:model="employment_type" type="radio" value="{{ $val }}"
                           class="w-4 h-4 accent-[#7a3f91] cursor-pointer">
                    <span class="text-sm font-semibold text-gray-900">{{ $lbl }}</span>
                </label>
                @endforeach
            </div>
            @error('employment_type') <p class="text-xs text-red-500 mt-1.5">{{ $message }}</p> @enderror
        </div>

        {{-- Work Location --}}
        <div>
            <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">
                Work Location <span class="text-red-500">*</span>
            </label>
            <div class="flex flex-wrap gap-4">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input wire:model="work_location" type="radio" value="local"
                           class="w-4 h-4 accent-[#7a3f91] cursor-pointer">
                    <span class="text-sm font-semibold text-gray-900">Local</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input wire:model="work_location" type="radio" value="abroad"
                           class="w-4 h-4 accent-[#7a3f91] cursor-pointer">
                    <span class="text-sm font-semibold text-gray-900">Abroad</span>
                </label>
            </div>
            @error('work_location') <p class="text-xs text-red-500 mt-1.5">{{ $message }}</p> @enderror
        </div>

        {{-- Career Path --}}
        <div>
            <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">
                Career Path
                <span class="normal-case font-normal text-gray-400 text-[11px] tracking-normal ml-1">(optional, select all that apply)</span>
            </label>
            <div class="flex flex-wrap gap-4">
                @foreach(['ofw'=>'OFW','freelancer'=>'Freelancer','entrepreneur'=>'Entrepreneur','career_shifter'=>'Career Shifter','industry_professional'=>'Industry Professional'] as $val=>$lbl)
                <label class="flex items-center gap-2 cursor-pointer">
                    <input wire:model="career_path" type="checkbox" value="{{ $val }}"
                           class="w-4 h-4 accent-[#7a3f91] cursor-pointer">
                    <span class="text-sm font-semibold text-gray-900">{{ $lbl }}</span>
                </label>
                @endforeach
            </div>
        </div>

    </div>
</div>
@endif

{{-- SECTION 3 — Unemployment Status --}}
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
            <label class="flex items-center gap-2 cursor-pointer">
                <input wire:model="unemployment_status" type="radio" value="seeking_employment"
                       class="w-4 h-4 accent-[#7a3f91] cursor-pointer">
                <span class="text-sm font-semibold text-gray-900">Actively Seeking Employment</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
                <input wire:model="unemployment_status" type="radio" value="not_looking"
                       class="w-4 h-4 accent-[#7a3f91] cursor-pointer">
                <span class="text-sm font-semibold text-gray-900">Not Currently Looking</span>
            </label>
        </div>
        @error('unemployment_status') <p class="text-xs text-red-500 mt-1.5">{{ $message }}</p> @enderror
    </div>
</div>
@endif

{{-- SECTION 4 — Further Education --}}
<div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
    <div class="px-5 py-3.5 border-b border-gray-100">
        <span class="text-base font-semibold text-gray-900">Further Education</span>
    </div>
    <div class="p-5">
        <label class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">
            Education Status <span class="text-red-500">*</span>
        </label>
        <div class="flex flex-wrap gap-4">
            <label class="flex items-center gap-2 cursor-pointer">
                <input wire:model="education_status" type="radio" value="none"
                       class="w-4 h-4 accent-[#7a3f91] cursor-pointer">
                <span class="text-sm font-semibold text-gray-900">None</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
                <input wire:model="education_status" type="radio" value="pursuing_masteral"
                       class="w-4 h-4 accent-[#7a3f91] cursor-pointer">
                <span class="text-sm font-semibold text-gray-900">Pursuing Masteral</span>
            </label>
            <label class="flex items-center gap-2 cursor-pointer">
                <input wire:model="education_status" type="radio" value="pursuing_doctorate"
                       class="w-4 h-4 accent-[#7a3f91] cursor-pointer">
                <span class="text-sm font-semibold text-gray-900">Pursuing Doctorate</span>
            </label>
        </div>
        @error('education_status') <p class="text-xs text-red-500 mt-1.5">{{ $message }}</p> @enderror
    </div>
</div>

{{-- Action buttons --}}
<div class="flex gap-3 flex-wrap">
    <button wire:click="saveEmployment"
            wire:loading.attr="disabled"
            wire:target="saveEmployment"
            class="inline-flex items-center justify-center gap-1.5 px-6 py-2.5 rounded-lg
                   bg-[#7a3f91] text-white text-sm font-semibold cursor-pointer
                   hover:opacity-90 active:scale-[.98] transition
                   disabled:opacity-55 disabled:cursor-not-allowed">
        <span wire:loading.remove wire:target="saveEmployment">Save Employment</span>
        <span wire:loading wire:target="saveEmployment">Saving…</span>
    </button>
    @if($hasRecord)
    <button wire:click="cancelEditing"
            class="inline-flex items-center justify-center gap-1.5 px-6 py-2.5 rounded-lg
                   bg-transparent text-gray-900 text-sm font-semibold cursor-pointer
                   border border-gray-200 hover:bg-gray-50 active:scale-[.98] transition">
        Cancel
    </button>
    @endif
</div>

@endif {{-- end $editing --}}

{{-- ════════════════════════════════════════════════════════════════════════════
     RECORD VIEW  (shown only when not editing)
════════════════════════════════════════════════════════════════════════════ --}}
@if(!$editing)

    @if(!$currentRecord && !$previousRecord)
    {{-- Empty state --}}
    <div class="bg-white border border-gray-200 rounded-xl p-12 flex flex-col items-center justify-center text-center">
        <p class="font-semibold text-base text-gray-900">No Employment Record Yet</p>
        <p class="text-sm text-gray-500 mt-1">
            Click <strong class="text-[#7a3f91]">Update Employment</strong> to submit your information.
        </p>
    </div>
    @else

    {{-- ── CURRENT RECORD ──────────────────────────────────────────────────── --}}
    @if($currentRecord)
    <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
        <div class="px-5 py-3.5 border-b border-gray-100 flex items-center justify-between gap-2">
            <span class="text-base font-semibold text-gray-900">Current Employment Status</span>
        </div>
        <div class="p-5 space-y-5">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Employment Status</span>
                    <p class="text-base font-semibold text-gray-900">{{ $currentRecord['employment_status'] }}</p>
                </div>
                <div>
                    <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Further Education</span>
                    <p class="text-base font-semibold text-gray-900">{{ $currentRecord['education_status'] ?: '—' }}</p>
                </div>
            </div>

            @if($currentRecord['is_working'])
            <div class="pt-4 border-t border-gray-100 space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">
                            {{ str_contains(strtolower($currentRecord['employment_status']), 'self') ? 'Business Name' : 'Company Name' }}
                        </span>
                        <p class="text-base font-semibold text-gray-900 uppercase">{{ $currentRecord['company_name'] ?: '—' }}</p>
                    </div>
                    <div>
                        <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">
                            {{ str_contains(strtolower($currentRecord['employment_status']), 'self') ? 'Occupation / Role' : 'Job Title' }}
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

    {{-- ── ACTION BUTTONS ROW ──────────────────────────────────────────────── --}}
    <div class="flex flex-wrap gap-3">

        {{-- View Previous Record button --}}
        @if($previousRecord)
        <button
            @click="showHistoryModal = true"
            class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-lg
                   bg-white text-gray-700 text-sm font-semibold cursor-pointer
                   border border-gray-200 hover:bg-gray-50 active:scale-[.98] transition">
            <i class="fa-solid fa-clock-rotate-left text-gray-500 text-xs"></i>
            Previous Record
        </button>
        @endif

        {{-- View CV button --}}
        @if($currentRecord)
        <button
            @click="showCvModal = true"
            class="inline-flex items-center justify-center gap-2 px-5 py-2.5 rounded-lg
                   bg-[#7a3f91] text-white text-sm font-semibold cursor-pointer
                   hover:opacity-90 active:scale-[.98] transition">
            <i class="fa-solid fa-file-user text-white text-xs"></i>
            View CV
        </button>
        @endif

    </div>

    @endif {{-- end empty check --}}

@endif {{-- end !$editing --}}


{{-- ════════════════════════════════════════════════════════════════════════════
     PREVIOUS RECORD MODAL
════════════════════════════════════════════════════════════════════════════ --}}
<div
    x-show="showHistoryModal"
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    style="display: none;"
>
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="showHistoryModal = false"></div>

    <div
        x-show="showHistoryModal"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 scale-95 translate-y-2"
        x-transition:enter-end="opacity-100 scale-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 scale-100 translate-y-0"
        x-transition:leave-end="opacity-0 scale-95 translate-y-2"
        class="relative bg-white rounded-2xl shadow-2xl w-full max-w-xl max-h-[80vh] flex flex-col overflow-hidden"
    >
        {{-- Header --}}
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 flex-shrink-0">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-[#7a3f91]/10 flex items-center justify-center">
                    <i class="fa-solid fa-clock-rotate-left text-[#7a3f91] text-sm"></i>
                </div>
                <h2 class="text-base font-semibold text-gray-900">Previous Record</h2>
            </div>
            <button @click="showHistoryModal = false"
                    class="w-8 h-8 flex items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition cursor-pointer">
                <i class="fa-solid fa-xmark text-base"></i>
            </button>
        </div>

        {{-- Body --}}
        <div class="overflow-y-auto flex-1 p-6">
            @if($previousRecord)
            <div class="space-y-4">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Employment Status</span>
                        <p class="text-base font-semibold text-gray-900">{{ $previousRecord['employment_status'] }}</p>
                    </div>
                    <div>
                        <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Further Education</span>
                        <p class="text-base font-semibold text-gray-900">{{ $previousRecord['education_status'] ?: '—' }}</p>
                    </div>
                </div>

                @if($previousRecord['is_working'])
                <div class="pt-4 border-t border-gray-100 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">
                                {{ str_contains(strtolower($previousRecord['employment_status']), 'self') ? 'Business Name' : 'Company Name' }}
                            </span>
                            <p class="text-base font-semibold text-gray-900 uppercase">{{ $previousRecord['company_name'] ?: '—' }}</p>
                        </div>
                        <div>
                            <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">
                                {{ str_contains(strtolower($previousRecord['employment_status']), 'self') ? 'Occupation / Role' : 'Job Title' }}
                            </span>
                            <p class="text-base font-semibold text-gray-900">{{ $previousRecord['job_title'] ?: '—' }}</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Employment Type</span>
                            <p class="text-base font-semibold text-gray-900">{{ $previousRecord['employment_type'] ?: '—' }}</p>
                        </div>
                        <div>
                            <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Work Location</span>
                            <p class="text-base font-semibold text-gray-900">{{ $previousRecord['work_location'] ?: '—' }}</p>
                        </div>
                        <div>
                            <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Course Relevance</span>
                            <p class="text-base font-semibold text-gray-900">{{ $previousRecord['course_relevance'] ?: '—' }}</p>
                        </div>
                    </div>
                    @if(!empty($previousRecord['career_path_labels']))
                    <div>
                        <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Career Path</span>
                        <p class="text-base font-semibold text-gray-900">{{ implode(', ', $previousRecord['career_path_labels']) }}</p>
                    </div>
                    @endif
                </div>
                @endif

                @if(!$previousRecord['is_working'] && $previousRecord['unemployment_status'])
                <div class="pt-4 border-t border-gray-100">
                    <span class="block text-xs font-semibold uppercase tracking-wide text-gray-500 mb-1">Job Search Status</span>
                    <p class="text-base font-semibold text-gray-900">{{ $previousRecord['unemployment_status'] }}</p>
                </div>
                @endif

                <div class="pt-3 border-t border-gray-100">
                    <span class="text-xs text-gray-400">Submitted: {{ $previousRecord['submitted_at'] }}</span>
                </div>

            </div>
            @endif
        </div>

        {{-- Footer --}}
        <div class="px-6 py-4 border-t border-gray-100 flex justify-end flex-shrink-0">
            <button @click="showHistoryModal = false"
                    class="inline-flex items-center justify-center px-5 py-2 rounded-lg
                           bg-gray-100 text-gray-700 text-sm font-semibold cursor-pointer
                           hover:bg-gray-200 active:scale-[.98] transition">
                Close
            </button>
        </div>
    </div>
</div>


{{-- ════════════════════════════════════════════════════════════════════════════
     CV MODAL
════════════════════════════════════════════════════════════════════════════ --}}
<div
    x-show="showCvModal"
    x-transition:enter="transition ease-out duration-200"
    x-transition:enter-start="opacity-0"
    x-transition:enter-end="opacity-100"
    x-transition:leave="transition ease-in duration-150"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0"
    class="fixed inset-0 z-50 flex items-center justify-center p-4"
    style="display: none;"
>
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="showCvModal = false"></div>

    <div
        x-show="showCvModal"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 scale-95 translate-y-2"
        x-transition:enter-end="opacity-100 scale-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 scale-100 translate-y-0"
        x-transition:leave-end="opacity-0 scale-95 translate-y-2"
        class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[88vh] flex flex-col overflow-hidden"
    >
        {{-- Header --}}
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 flex-shrink-0">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-lg bg-[#7a3f91]/10 flex items-center justify-center">
                    <i class="fa-solid fa-file-user text-[#7a3f91] text-sm"></i>
                </div>
                <div>
                    <h2 class="text-base font-semibold text-gray-900">Employment CV</h2>
                    <p class="text-xs text-gray-500">Your employment record summary</p>
                </div>
            </div>
            <button @click="showCvModal = false"
                    class="w-8 h-8 flex items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600 transition cursor-pointer">
                <i class="fa-solid fa-xmark text-base"></i>
            </button>
        </div>

        {{-- CV Body --}}
        <div class="overflow-y-auto flex-1 p-6 space-y-6" id="cv-print-area">

            {{-- CV Header --}}
            <div class="flex items-start gap-4 pb-5 border-b-2 border-[#7a3f91]">
                <div class="w-16 h-16 rounded-full bg-[#7a3f91] flex items-center justify-center flex-shrink-0">
                    <i class="fa-solid fa-user text-white text-2xl"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="text-xl font-bold text-gray-900 truncate">
                        {{ auth()->user()->name ?? 'Alumni' }}
                    </h3>
                    @if($currentRecord && $currentRecord['is_working'])
                        <p class="text-sm font-semibold text-[#7a3f91] mt-0.5">{{ $currentRecord['job_title'] ?: '' }}</p>
                        <p class="text-sm text-gray-500">{{ $currentRecord['company_name'] ?: '' }}</p>
                    @elseif($currentRecord)
                        <p class="text-sm text-gray-500 mt-0.5">{{ $currentRecord['employment_status'] }}</p>
                    @endif
                    @if($alumniCourseName)
                        <p class="text-xs text-gray-400 mt-1">{{ $alumniCourseName }}
                            @if($alumniCourse) · <span class="font-semibold">{{ $alumniCourse }}</span> @endif
                        </p>
                    @endif
                </div>
            </div>

            {{-- Current Position --}}
            @if($currentRecord)
            <div>
                <h4 class="text-xs font-bold uppercase tracking-widest text-[#7a3f91] mb-3">Current Status</h4>

                @if($currentRecord['is_working'])
                <div class="bg-gray-50 rounded-xl p-4 space-y-3">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <p class="text-sm font-bold text-gray-900">{{ $currentRecord['job_title'] ?: '—' }}</p>
                            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mt-0.5">{{ $currentRecord['company_name'] ?: '—' }}</p>
                        </div>
                        <span class="flex-shrink-0 inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                            {{ $currentRecord['employment_status'] }}
                        </span>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 pt-2 border-t border-gray-200">
                        @if($currentRecord['employment_type'])
                        <div>
                            <span class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-0.5">Type</span>
                            <p class="text-xs font-semibold text-gray-700">{{ $currentRecord['employment_type'] }}</p>
                        </div>
                        @endif
                        @if($currentRecord['work_location'])
                        <div>
                            <span class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-0.5">Location</span>
                            <p class="text-xs font-semibold text-gray-700">{{ $currentRecord['work_location'] }}</p>
                        </div>
                        @endif
                        @if($currentRecord['course_relevance'])
                        <div>
                            <span class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-0.5">Relevance</span>
                            <p class="text-xs font-semibold text-gray-700">{{ $currentRecord['course_relevance'] }}</p>
                        </div>
                        @endif
                    </div>
                    @if(!empty($currentRecord['career_path_labels']))
                    <div class="pt-2 border-t border-gray-200">
                        <span class="block text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-1.5">Career Path</span>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach($currentRecord['career_path_labels'] as $cp)
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-purple-50 text-purple-700 border border-purple-200">{{ $cp }}</span>
                            @endforeach
                        </div>
                    </div>
                    @endif
                </div>
                @else
                <div class="bg-gray-50 rounded-xl p-4 flex items-center justify-between">
                    <p class="text-sm font-semibold text-gray-700">{{ $currentRecord['employment_status'] }}</p>
                    @if($currentRecord['unemployment_status'])
                    <span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-semibold bg-amber-50 text-amber-700 border border-amber-200">
                        {{ $currentRecord['unemployment_status'] }}
                    </span>
                    @endif
                </div>
                @endif

                @if($currentRecord['education_status'] && $currentRecord['education_status'] !== 'None')
                <div class="mt-3 flex items-center gap-2">
                    <i class="fa-solid fa-graduation-cap text-[#7a3f91] text-sm"></i>
                    <span class="text-sm font-semibold text-gray-700">{{ $currentRecord['education_status'] }}</span>
                </div>
                @endif
            </div>
            @endif

            {{-- Previous Position in CV --}}
            @if($previousRecord)
            <div>
                <h4 class="text-xs font-bold uppercase tracking-widest text-[#7a3f91] mb-3">Previous Status</h4>
                <div class="pl-6 relative">
                    <div class="absolute left-0 top-1.5 w-3.5 h-3.5 rounded-full border-2 border-gray-300 bg-white"></div>
                    <div class="absolute left-[6px] top-4 bottom-0 w-px bg-gray-200"></div>
                    <div class="bg-gray-50 rounded-xl p-3.5">
                        <div class="flex items-start justify-between gap-2 mb-1.5">
                            <div>
                                @if($previousRecord['is_working'] && $previousRecord['job_title'])
                                    <p class="text-sm font-bold text-gray-900">{{ $previousRecord['job_title'] }}</p>
                                    @if($previousRecord['company_name'])
                                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">{{ $previousRecord['company_name'] }}</p>
                                    @endif
                                @else
                                    <p class="text-sm font-bold text-gray-900">{{ $previousRecord['employment_status'] }}</p>
                                @endif
                            </div>
                            <span class="flex-shrink-0 text-xs text-gray-400 whitespace-nowrap">{{ $previousRecord['submitted_at'] }}</span>
                        </div>
                        @if($previousRecord['is_working'])
                        <div class="flex flex-wrap gap-2 mt-1.5">
                            @if($previousRecord['employment_type'])
                            <span class="text-[11px] font-semibold text-gray-500 bg-white border border-gray-200 px-2 py-0.5 rounded">{{ $previousRecord['employment_type'] }}</span>
                            @endif
                            @if($previousRecord['work_location'])
                            <span class="text-[11px] font-semibold text-gray-500 bg-white border border-gray-200 px-2 py-0.5 rounded">{{ $previousRecord['work_location'] }}</span>
                            @endif
                            @if($previousRecord['course_relevance'])
                            <span class="text-[11px] font-semibold text-gray-500 bg-white border border-gray-200 px-2 py-0.5 rounded">{{ $previousRecord['course_relevance'] }}</span>
                            @endif
                        </div>
                        @endif
                        @if(!$previousRecord['is_working'] && $previousRecord['unemployment_status'])
                        <p class="text-xs text-gray-500 mt-1">{{ $previousRecord['unemployment_status'] }}</p>
                        @endif
                    </div>
                </div>
            </div>
            @endif

        </div>

        {{-- CV Footer --}}
        <div class="px-6 py-4 border-t border-gray-100 flex items-center justify-between flex-shrink-0">
            <p class="text-xs text-gray-400">Generated {{ now()->format('F j, Y') }}</p>
            <div class="flex gap-2">
                <button
                    onclick="
                        const area = document.getElementById('cv-print-area');
                        const win = window.open('', '_blank');
                        win.document.write('<html><head><title>Employment CV</title><style>body{font-family:sans-serif;padding:32px;max-width:700px;margin:auto}h4{color:#7a3f91;font-size:10px;text-transform:uppercase;letter-spacing:.1em;margin-bottom:12px}p,span{font-size:13px}@media print{body{padding:0}}</style></head><body>' + area.innerHTML + '</body></html>');
                        win.document.close();
                        win.focus();
                        win.print();
                    "
                    class="inline-flex items-center justify-center gap-1.5 px-4 py-2 rounded-lg
                           bg-gray-100 text-gray-700 text-sm font-semibold cursor-pointer
                           hover:bg-gray-200 active:scale-[.98] transition">
                    <i class="fa-solid fa-print text-xs"></i>
                    Print
                </button>
                <button @click="showCvModal = false"
                        class="inline-flex items-center justify-center px-4 py-2 rounded-lg
                               bg-gray-100 text-gray-700 text-sm font-semibold cursor-pointer
                               hover:bg-gray-200 active:scale-[.98] transition">
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

</div>