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
        if (str_contains($c, 'LAW') || str_contains($c, 'LLB')
            || $c === 'JD')                                                          return 'law';

        return 'general';
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  Smart Job Relevance Auto-Detection
    // ═════════════════════════════════════════════════════════════════════════

    protected function detectJobRelevance(string $title): string
    {
        $t = strtolower(trim($title));
        if (empty($t)) return '';

        $group = $this->getCourseGroup($this->alumniCourse);

        // ── Strong match keywords → "yes" ─────────────────────────────────────
        $yesKw = [
            'technology'     => [
                'developer','programmer','software','web dev','mobile app','network engineer',
                'database admin','sysadmin','devops','cloud engineer','cybersecurity','data scientist',
                'data analyst','ui/ux','it support','qa engineer','ml engineer','ai engineer',
                'tech lead','systems analyst','ict','computer engineer','full stack','backend',
                'frontend','it officer','helpdesk','network admin','it manager','it specialist',
                'information technology','computer science','system developer','software engineer',
            ],
            'nursing'        => [
                'nurse','nursing','rn ','registered nurse','icu','er nurse','surgical nurse',
                'ward nurse','dialysis nurse','pediatric nurse','public health nurse','head nurse',
                'charge nurse','clinical nurse','operating room nurse','or nurse',
            ],
            'education'      => [
                'teacher','instructor','professor','tutor','faculty','educator',
                'academic coordinator','school principal','curriculum developer','lecturer',
                'teaching','special education','classroom teacher','school admin','school head',
                'subject teacher','grade school','high school teacher','college instructor',
                'tesda trainer','tesda teacher','vocational trainer','skills trainer',
            ],
            'accounting'     => [
                'accountant','auditor','cpa','tax specialist','bookkeeper','accounting',
                'finance officer','budget analyst','payroll','internal auditor','external auditor',
                'financial analyst','management accountant','cost accountant','revenue officer',
            ],
            'business'       => [
                'marketing manager','sales manager','business analyst','hr officer',
                'operations manager','management trainee','business owner','entrepreneur',
                'brand manager','product manager','account manager','business development',
                'merchandising','trade marketing','retail manager','commercial manager',
            ],
            'engineering'    => [
                'engineer','civil engineer','mechanical engineer','electrical engineer',
                'structural engineer','construction manager','project engineer',
                'quality engineer','process engineer','industrial engineer','plant engineer',
                'design engineer','site engineer','engineering manager','chief engineer',
            ],
            'healthcare'     => [
                'pharmacist','physical therapist','radiologic technologist','medical technologist',
                'occupational therapist','respiratory therapist','dentist','dental',
                'midwife','radiographer','med tech','pharmacy','therapist','clinical',
            ],
            'criminology'    => [
                'police officer','pnp','nbi agent','forensic analyst','criminologist',
                'jail officer','fire officer','law enforcement','detective','intelligence officer',
                'criminal investigator','bureau of corrections','bfp','bucor',
            ],
            'hospitality'    => [
                'hotel manager','chef','sous chef','restaurant manager','front desk officer',
                'tour guide','event coordinator','flight attendant','travel agent',
                'catering manager','hospitality manager','food and beverage','f&b manager',
                'banquet manager','concierge','housekeeping manager','rooms division',
            ],
            'psychology'     => [
                'psychologist','guidance counselor','social worker','mental health',
                'psychiatry','behavior analyst','clinical psychologist','counseling',
                'psychology','welfare officer','rehabilitation counselor',
            ],
            'communications' => [
                'journalist','reporter','broadcast journalist','public relations','pr officer',
                'content writer','copywriter','social media manager','advertising','media planner',
                'editor','communications officer','news writer','feature writer','anchor',
                'media relations','communications specialist',
            ],
            'architecture'   => [
                'architect','interior designer','urban planner','draftsman','cad operator',
                'architectural','landscape architect','space planner','master planner',
                'building designer','architectural designer',
            ],
            'law'            => [
                'lawyer','attorney','legal officer','paralegal','court interpreter',
                'judge','prosecutor','public attorney','legal counsel','law practitioner',
                'notary public','legal consultant','solicitor',
            ],
            'general'        => [],
        ];

        // ── Partial match keywords → "partially" ──────────────────────────────
        $partialKw = [
            'technology'     => [
                'it ','tech ','digital','computer','online','system','app ','platform','encoder',
                'data entry','web','virtual','it-related','tech support','technical','network',
                'technical writer','technical support','it coordinator','it staff',
            ],
            'nursing'        => [
                'health','clinic','hospital','patient','caregiver','medical','lab',
                'healthcare','home care','nursing aide','orderly','health worker',
            ],
            'education'      => [
                'trainer','training','coach','mentor','facilitator','tesda',
                'reviewer','subject matter','tutorial','educational','school',
                'learning','academic','instruction','development officer',
            ],
            'accounting'     => [
                'finance','billing','cashier','collections','admin','accounts',
                'financial','treasury','comptroller','budgeting','disbursement',
            ],
            'business'       => [
                'admin','officer','coordinator','supervisor','team lead','store manager',
                'operations','logistics','supply chain','purchasing','procurement',
                'inventory','warehouse','distribution',
            ],
            'engineering'    => [
                'technician','maintenance','inspector','surveyor','estimator',
                'drafter','foreman','supervisor','technical','construction','fabricator',
            ],
            'healthcare'     => [
                'health','clinic','hospital','medical','lab','pharmacy','dental assistant',
                'health aide','nursing aide','care worker','healthcare worker',
            ],
            'criminology'    => [
                'security guard','security officer','investigator','enforcement','warden',
                'safety officer','risk','compliance','guard','patrol',
            ],
            'hospitality'    => [
                'food','service','hospitality','accommodation','barista','waiter',
                'bartender','concierge','receptionist','housekeeping','tourism',
            ],
            'psychology'     => [
                'hr','recruiter','training officer','social services','welfare',
                'employee relations','organizational','people','talent',
            ],
            'communications' => [
                'writer','editor','media','content','marketing','communications',
                'social media','digital marketing','creative','blogger','vlogger',
            ],
            'architecture'   => [
                'design','planning','drafting','construction','estimator','3d','rendering',
                'visualization','autocad','revit','sketchup',
            ],
            'law'            => [
                'compliance','regulatory','policy','governance','legal assistant',
                'court','justice','contracts','documentation','corporate secretary',
            ],
            'general'        => [
                'officer','staff','coordinator','supervisor','manager','specialist',
            ],
        ];

        // ── Global cross-cutting overrides ────────────────────────────────────
        // TESDA instructor/trainer: relevant if education, partial for all others
        if (str_contains($t, 'tesda')) {
            return $group === 'education' ? 'yes' : 'partially';
        }

        // Overseas/OFW with a known role prefix — preserve group match
        // (no special override needed; falls through to group keywords)

        $yesKeys     = $yesKw[$group]     ?? [];
        $partialKeys = $partialKw[$group] ?? [];

        foreach ($yesKeys as $kw) {
            if (str_contains($t, strtolower($kw))) return 'yes';
        }
        foreach ($partialKeys as $kw) {
            if (str_contains($t, strtolower($kw))) return 'partially';
        }

        return 'no';
    }

    // ═════════════════════════════════════════════════════════════════════════
    //  Job Title Options
    // ═════════════════════════════════════════════════════════════════════════

    protected function buildJobOptions(): array
    {
        $group = $this->getCourseGroup($this->alumniCourse);

        $map = [
            'technology' => [
                'Software Developer','Web Developer','Mobile App Developer','Systems Analyst',
                'Database Administrator','Network Engineer','IT Support Specialist',
                'Cybersecurity Analyst','Data Analyst / Data Scientist','UI / UX Designer',
                'QA / Test Engineer','DevOps / Cloud Engineer','AI / ML Engineer',
                'Technical Project Manager',
            ],
            'nursing' => [
                'Registered Nurse (RN)','ICU / Critical Care Nurse','ER / Emergency Nurse',
                'Head Nurse / Supervisor','OR / Surgical Nurse','Pediatric Nurse',
                'Public Health Nurse','Dialysis Nurse','OFW / International Nurse',
            ],
            'education' => [
                'Elementary School Teacher','High School Teacher','Special Education Teacher',
                'College Instructor','School Principal / Admin',
                'Academic / Curriculum Coordinator','Tutor / Review Center Instructor',
            ],
            'accounting' => [
                'Certified Public Accountant (CPA)','Auditor','Financial Analyst',
                'Tax Specialist','Budget Analyst','Bookkeeper',
                'Accounting Officer / Staff','Internal Auditor','Finance Manager',
            ],
            'business' => [
                'Marketing Manager / Officer','Sales Manager','Operations Manager',
                'Business Analyst','HR Officer / HR Manager','Management Trainee',
                'Administrative Officer','Entrepreneur / Business Owner',
            ],
            'engineering' => [
                'Civil Engineer','Mechanical Engineer','Electrical Engineer',
                'Electronics Engineer','Chemical Engineer','Industrial Engineer',
                'Project Engineer','Quality Assurance Engineer','Construction Engineer / Manager',
            ],
            'healthcare' => [
                'Pharmacist','Physical Therapist','Radiologic Technologist',
                'Medical Technologist','Occupational Therapist',
                'Respiratory Therapist','Midwife','Dentist',
            ],
            'criminology' => [
                'PNP Officer / Police Officer','NBI Agent','Criminologist',
                'Jail Officer / BuCor','Forensic Analyst',
                'Security Officer / Supervisor','Fire Officer (BFP)',
            ],
            'hospitality' => [
                'Hotel Manager','Front Desk Officer','Restaurant Manager',
                'Chef / Sous Chef','Tour Guide','Event Coordinator',
                'Flight Attendant / Cabin Crew','Travel Agent',
            ],
            'psychology' => [
                'Psychologist','Guidance Counselor','HR Officer / Recruiter',
                'Social Worker','Mental Health Counselor','Training & Development Officer',
            ],
            'communications' => [
                'Journalist / Reporter','Public Relations Officer','Broadcast Journalist',
                'Content Creator / Writer','Social Media Manager','Copywriter',
                'Advertising Specialist','Media Planner',
            ],
            'architecture' => [
                'Architect','Interior Designer','Urban Planner',
                'Draftsman / CAD Operator','Construction Manager',
            ],
            'law' => [
                'Lawyer / Attorney','Legal Officer','Court Interpreter',
                'Paralegal','Legal Researcher',
            ],
            'general' => [
                'Administrative Officer','Office Staff',
                'Customer Service Representative','Sales Representative',
            ],
        ];

        $titles   = $map[$group] ?? $map['general'];
        $titles[] = 'Other';
        return $titles;
    }

    // ─────────────────────────────────────────────────────────────────────────

    public function mount(): void
    {
        $user = auth()->user();
        if (!$user || $user->role !== 'alumni') {
            $this->redirect(route('login'));
            return;
        }

        $alumni = Alumni::where('user_id', $user->id)
            ->select(['id', 'course_code', 'course_name'])
            ->first();

        if (!$alumni) {
            $this->redirect(route('login'));
            return;
        }

        $this->alumniId         = $alumni->id;
        $this->alumniCourse     = $alumni->course_code ?? '';
        $this->alumniCourseName = $alumni->course_name ?? '';
        $this->jobOptions       = $this->buildJobOptions();

        $this->loadRecord();
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
            $this->career_path         = $record->career_path
                ? json_decode($record->career_path, true) : [];
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

    // ── Edit / Cancel ─────────────────────────────────────────────────────────

    public function startEditing(): void
    {
        $this->errorMessage = $this->successMessage = '';
        $this->snapshot     = [];

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
        foreach ($this->snapshot as $k => $v) {
            $this->$k = $v;
        }
        $this->editing = false;
    }

    // ── History Modal ─────────────────────────────────────────────────────────

    public function openHistory(): void
    {
        $records = DB::table('employment_trackings')
            ->where('alumni_id', $this->alumniId)
            ->orderByDesc('created_at')
            ->get();

        $typeLabels = [
            'full_time'     => 'Full-Time',
            'part_time'     => 'Part-Time',
            'contractual'   => 'Contractual',
            'project_based' => 'Project-Based',
            'internship'    => 'Internship',
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

    // ── Reactive Hooks ────────────────────────────────────────────────────────

    public function updatedEmploymentStatus(): void
    {
        if ($this->employment_status === 'unemployed') {
            $this->company_name     = $this->job_title = $this->employment_type =
            $this->work_location    = $this->course_relevance = $this->custom_job_title = '';
            $this->career_path      = [];
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

    /**
     * Auto-detect relevance as user types the custom job title.
     * No manual selection needed — system figures it out.
     */
    public function updatedCustomJobTitle(): void
    {
        if ($this->job_title === 'Other') {
            $this->course_relevance = $this->detectJobRelevance($this->custom_job_title);
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
            // Ensure relevance is auto-detected at save time too
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
                // Relevance is auto-detected — no manual validation needed
            }
        }

        if ($this->employment_status === 'unemployed') {
            $rules['unemployment_status'] = 'required|in:seeking_employment,not_looking';
            $msgs['unemployment_status.required'] = 'Please select your unemployment status.';
        }

        $this->validate($rules, $msgs);

        $finalJobTitle  = $isOther ? $this->custom_job_title : $this->job_title;
        $finalRelevance = $working
            ? ($this->course_relevance ?: 'no')
            : null;

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
                'career_path'         => $working && count($this->career_path)
                    ? json_encode(array_values($this->career_path)) : null,
                'course_relevance'    => $finalRelevance,
                'unemployment_status' => $this->employment_status === 'unemployed'
                    ? ($this->unemployment_status ?: null) : null,
                'created_at'          => $now,
                'updated_at'          => $now,
            ];

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
            $this->dispatch('employment-updated', alumniId: $this->alumniId);

            Log::info("Employment saved | alumni_id:{$this->alumniId} | status:{$this->employment_status}");

        } catch (\Throwable $e) {
            Log::error('Employment save error: ' . $e->getMessage());
            $this->errorMessage = 'Failed to save. Please try again.';
        }
    }
}; ?>

<div class="flex flex-col" style="height: 90vh; overflow: hidden;">

<style>
/* ── Design tokens ──────────────────────────────────────────────────────── */
:root {
    --brand:          #7a3f91;
    --brand-dark:     #5e2f72;
    --brand-light:    #f9f7fc;
    --brand-mid:      #ede9fe;
    --text-primary:   #333333;
    --text-secondary: #555555;
    --text-muted:     #777777;
}

/* ── Animations ─────────────────────────────────────────────────────────── */
@keyframes histSlideIn {
    from { opacity:0; transform:translateY(20px) scale(.98); }
    to   { opacity:1; transform:none; }
}
@keyframes specifySlideDown {
    from { opacity:0; transform:translateY(-8px); }
    to   { opacity:1; transform:translateY(0); }
}
@keyframes relevancePop {
    0%   { opacity:0; transform:scale(.85) translateY(-4px); }
    60%  { transform:scale(1.04) translateY(0); }
    100% { opacity:1; transform:scale(1); }
}

/* ── Scrollbar ───────────────────────────────────────────────────────────── */
.scroll-c::-webkit-scrollbar { width: 5px; }
.scroll-c::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb:hover { background: #7a3f91; }

/* ── Content block ───────────────────────────────────────────────────────── */
.content-block {
    display: flex;
    flex-direction: column;
    border-radius: 1rem;
    overflow: hidden;
    border: 1px solid #E8E0F0;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
    flex: 1;
    min-height: 0;
}
.content-block-filter {
    background: #F5F5F5;
    border-bottom: 1px solid #E8E0F0;
    padding: 0.6rem 0.875rem;
    flex-shrink: 0;
}
.content-block-body {
    flex: 1;
    min-height: 0;
    background: #fafafa;
    padding: 1rem;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: #d1d5db transparent;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}
.content-block-body::-webkit-scrollbar { width: 5px; }
.content-block-body::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }

/* ── Save footer ─────────────────────────────────────────────────────────── */
.save-footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.5rem;
    flex-wrap: wrap;
    padding: 0.75rem 1.25rem;
    background: linear-gradient(135deg, #7A3F91, #9b59b6);
    flex-shrink: 0;
}

/* ── 2-column form grid ─────────────────────────────────────────────────── */
.emp-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
}
@media (max-width: 768px) {
    .emp-grid { grid-template-columns: 1fr; }
}

/* ── Inputs ─────────────────────────────────────────────────────────────── */
.f-edit {
    border: 1.5px solid #d1d5db;
    background: #fff;
    color: #333333;
    transition: border-color .15s, box-shadow .15s;
    font-size: .875rem;
}
.f-edit:hover { border-color: var(--brand); }
.f-edit:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px rgba(122,63,145,.12); }
.f-edit.err   { border-color: #ef4444; }
.f-view {
    border: 1.5px solid #e5e7eb;
    background: #f9fafb;
    color: #333333;
    cursor: default;
    pointer-events: none;
    font-size: .875rem;
}

/* ── Job title select chevron ────────────────────────────────────────────── */
.job-select {
    appearance: none; -webkit-appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%237a3f91' d='M1 1l5 5 5-5'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 14px center;
    padding-right: 2.5rem;
}

/* ── Slide-in animations ─────────────────────────────────────────────────── */
.specify-wrap { animation: specifySlideDown .2s cubic-bezier(.34,1.56,.64,1); }

/* ── Auto-relevance badge pop ────────────────────────────────────────────── */
.auto-rel-badge { animation: relevancePop .25s cubic-bezier(.34,1.56,.64,1); }

/* ── Radio pills ─────────────────────────────────────────────────────────── */
.r-pill {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 13px; border: 1.5px solid #e5e7eb; border-radius: .75rem;
    cursor: pointer; transition: border-color .15s, background .15s; font-size: .8125rem;
}
.r-pill:hover              { border-color: var(--brand); background: var(--brand-light); }
.r-pill:has(input:checked) { border-color: var(--brand); background: var(--brand-light); }
.r-pill input:checked ~ span { color: var(--brand); font-weight: 600; }

/* ── Checkbox pills ──────────────────────────────────────────────────────── */
.c-pill {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 12px; border: 1.5px solid #e5e7eb; border-radius: .75rem;
    cursor: pointer; transition: border-color .15s, background .15s; font-size: .75rem; white-space: nowrap;
}
.c-pill:hover              { border-color: var(--brand); background: var(--brand-light); }
.c-pill:has(input:checked) { border-color: var(--brand); background: var(--brand-light); }
.c-pill input:checked ~ span { color: var(--brand); font-weight: 600; }

/* ── Section cards ───────────────────────────────────────────────────────── */
.s-card  { background: #fff; border: 1px solid #E8E0F0; border-radius: .875rem; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.05); }
.s-head  { display: flex; align-items: center; gap: 8px; padding: 10px 14px; border-bottom: 1px solid #f3f4f6; background: var(--brand-light); }
.s-icon  { width: 34px; height: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: center; background: var(--brand); flex-shrink: 0; }
.s-label { font-size: .7rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: #555555; }
.e-msg   { font-size: .75rem; color: #ef4444; display: flex; align-items: center; gap: 4px; margin-top: 3px; }
.b-pill  { display: inline-flex; align-items: center; gap: 5px; font-size: .75rem; font-weight: 600; padding: 5px 10px; border-radius: 99px; border: 1px solid; }
.s-body  { padding: 10px 14px; }

/* ── History modal ───────────────────────────────────────────────────────── */
.hist-overlay {
    position: fixed; inset: 0; z-index: 9999;
    background: rgba(0,0,0,.60); backdrop-filter: blur(5px);
    display: flex; align-items: center; justify-content: center; padding: 16px;
}
.hist-modal {
    background: #fff; border-radius: 1.25rem; width: 100%; max-width: 700px;
    max-height: 88vh; display: flex; flex-direction: column;
    box-shadow: 0 28px 72px rgba(122,63,145,.25), 0 4px 20px rgba(0,0,0,.14);
    animation: histSlideIn .22s cubic-bezier(.34,1.56,.64,1);
}
.hist-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px 14px; border-bottom: 1px solid #f3f4f6; flex-shrink: 0;
}
.hist-body { overflow-y: auto; padding: 18px 20px; flex: 1; scrollbar-width: thin; scrollbar-color: #d1d5db transparent; }
.hist-foot { padding: 12px 20px; border-top: 1px solid #f3f4f6; flex-shrink: 0; display: flex; justify-content: flex-end; }

/* ── Timeline ────────────────────────────────────────────────────────────── */
.tl-wrap { position: relative; padding-left: 38px; }
.tl-line {
    position: absolute; left: 13px; top: 14px; bottom: 14px; width: 2px;
    background: linear-gradient(to bottom, #7a3f91 0%, #e5e7eb 100%); border-radius: 2px;
}
.tl-entry { position: relative; margin-bottom: 16px; }
.tl-entry:last-child { margin-bottom: 0; }
.tl-dot {
    position: absolute; left: -38px; top: 14px;
    width: 28px; height: 28px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 10px; border: 3px solid #fff; flex-shrink: 0;
}
.tl-dot.cur  { background: var(--brand); box-shadow: 0 0 0 2px var(--brand); color: #fff; }
.tl-dot.past { background: #e5e7eb; box-shadow: 0 0 0 2px #d1d5db; color: #9ca3af; }
.tl-card     { background: #fafafa; border: 1.5px solid #e5e7eb; border-radius: .875rem; padding: 14px 16px; }
.tl-card.tl-cur { background: var(--brand-light); border-color: #c4b5d9; box-shadow: 0 2px 14px rgba(122,63,145,.11); }
.tl-meta     { font-size: .75rem; color: #777777; font-weight: 600; letter-spacing: .04em; margin-bottom: 6px; }
.tl-meta.cur-meta { color: var(--brand); }
.hist-empty  { text-align: center; padding: 40px 0; color: #555555; }
</style>

{{-- ══ MAIN LAYOUT ══════════════════════════════════════════════════════════ --}}
<div class="flex flex-col gap-3 px-5 sm:px-7 lg:px-10 pt-5 pb-4 max-w-screen-2xl mx-auto w-full"
     style="height: 90vh; overflow: hidden;">

    {{-- ── PAGE HEADER ─────────────────────────────────────────────────────── --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 flex-shrink-0">
        <div class="flex items-center gap-4">
            <div class="w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md"
                 style="background: linear-gradient(135deg, #7a3f91, #5e2f72);">
                <i class="fas fa-briefcase text-white text-lg"></i>
            </div>
            <div>
                <h1 class="text-xl font-semibold tracking-tight" style="color: #333333;">Employment Tracking</h1>
                <p class="text-xs leading-relaxed mt-0.5" style="color: #555555;">
                    Keep your status up to date — registrars and organizers can view this.
                    @if($alumniCourse)
                        <span class="font-semibold inline-flex items-center gap-1 px-2 py-0.5 bg-purple-50 text-purple-700 border border-purple-200 rounded-full text-xs ml-1">
                            <i class="fas fa-graduation-cap text-[9px]"></i>
                            {{ $alumniCourse }}
                            @if($alumniCourseName)
                                — {{ $alumniCourseName }}
                            @endif
                        </span>
                    @endif
                </p>
            </div>
        </div>
        <div class="flex items-center gap-2.5 flex-wrap">
            @if($hasRecord)
                <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3.5 py-2 rounded-xl border border-emerald-200 bg-emerald-50 text-emerald-700 uppercase tracking-wide">
                    <i class="fa-solid fa-circle-check text-emerald-600 text-[10px]"></i> Submitted
                </span>
            @else
                <span class="inline-flex items-center gap-1.5 text-xs font-semibold px-3.5 py-2 rounded-xl border border-amber-200 bg-amber-50 text-amber-700 uppercase tracking-wide">
                    <i class="fa-solid fa-triangle-exclamation text-amber-600 text-[10px]"></i> No Record Yet
                </span>
            @endif
        </div>
    </div>

    {{-- ── ALERT BANNERS ────────────────────────────────────────────────────── --}}
    @if($errorMessage)
    <div class="flex-shrink-0 p-3 bg-red-50 border border-red-200 rounded-xl flex items-center gap-2.5">
        <i class="fa-solid fa-circle-exclamation text-red-500 text-sm flex-shrink-0"></i>
        <p class="text-sm font-medium" style="color: #333333;">{{ $errorMessage }}</p>
    </div>
    @endif
    @if($successMessage)
    <div class="flex-shrink-0 p-3 bg-emerald-50 border border-emerald-200 rounded-xl flex items-center gap-2.5">
        <i class="fa-solid fa-circle-check text-emerald-500 text-sm flex-shrink-0"></i>
        <p class="text-sm font-medium" style="color: #333333;">{{ $successMessage }}</p>
    </div>
    @endif

    {{-- ══ CONTENT BLOCK ══════════════════════════════════════════════════════ --}}
    <div class="content-block">

        {{-- ── ACTION BAR ───────────────────────────────────────────────────── --}}
        <div class="content-block-filter flex flex-wrap gap-2 items-center justify-between">

            {{-- Left: mode badge --}}
            <div class="flex items-center gap-2">
                <div class="flex items-center gap-2 px-3 h-[38px] rounded-xl shrink-0 text-white font-semibold text-sm"
                     style="background: linear-gradient(135deg, #7A3F91, #9b59b6);">
                    <i class="fas fa-id-card text-white text-sm"></i>
                    <span class="hidden sm:inline">Employment</span>
                </div>
                <span class="text-sm font-semibold" style="color: #333333;">
                    {{ $editing ? 'Editing Mode' : 'View Mode' }}
                </span>
            </div>

            {{-- Right: History + Edit buttons --}}
            <div class="flex items-center gap-2">
                @if($hasRecord)
                <button wire:click="openHistory"
                        wire:loading.attr="disabled" wire:target="openHistory"
                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold
                               bg-white border border-[#E8E0F0] transition active:scale-95 cursor-pointer
                               disabled:opacity-60 disabled:cursor-wait"
                        style="color: #333333;">
                    <span wire:loading.remove wire:target="openHistory">
                        <i class="fa-solid fa-clock-rotate-left text-xs"></i>
                        <span class="hidden sm:inline ml-1">History</span>
                    </span>
                    <span wire:loading wire:target="openHistory">
                        <svg class="animate-spin w-3.5 h-3.5 inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                        </svg>
                        <span class="hidden sm:inline ml-1">Loading…</span>
                    </span>
                </button>
                @endif

                @if(!$editing)
                <button wire:click="startEditing"
                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold
                               text-white transition hover:opacity-90 active:scale-95 cursor-pointer"
                        style="background-color: #7a3f91;">
                    <i class="fa-solid fa-pen text-xs"></i>
                    <span class="hidden sm:inline">Update Employment</span>
                    <span class="sm:hidden">Update</span>
                </button>
                @endif
            </div>
        </div>

        {{-- ── SCROLLABLE BODY ───────────────────────────────────────────────── --}}
        <div class="content-block-body">

            {{-- ── ROW 1: Employment Status + Education Status ─────────────── --}}
            <div class="emp-grid">

                {{-- Employment Status --}}
                <div class="s-card">
                    <div class="s-head">
                        <div class="s-icon"><i class="fa-solid fa-briefcase text-white text-xs"></i></div>
                        <div class="flex-1">
                            <p class="text-xs font-bold" style="color: #333333;">Employment Status</p>
                            <p class="text-[10px]" style="color: #555555;">Your current work situation</p>
                        </div>
                        @if(!$editing)
                            <span class="text-[10px] font-semibold px-2 py-0.5 rounded bg-gray-100" style="color: #555555;">
                                <i class="fa-solid fa-eye text-[9px]"></i> View
                            </span>
                        @endif
                    </div>
                    <div class="s-body">
                        <label class="block s-label mb-2">
                            Status @if($editing)<span class="text-red-500">*</span>@endif
                        </label>
                        @if($editing)
                            <div class="flex flex-wrap gap-1.5">
                                <label class="r-pill">
                                    <input wire:model.live="employment_status" type="radio" value="employed" class="w-3.5 h-3.5 accent-violet-600">
                                    <i class="fa-solid fa-user-tie text-[10px]" style="color: #555555;"></i>
                                    <span class="font-medium" style="color: #333333;">Employed</span>
                                </label>
                                <label class="r-pill">
                                    <input wire:model.live="employment_status" type="radio" value="self_employed" class="w-3.5 h-3.5 accent-blue-600">
                                    <i class="fa-solid fa-store text-[10px]" style="color: #555555;"></i>
                                    <span class="font-medium" style="color: #333333;">Self-Employed</span>
                                </label>
                                <label class="r-pill">
                                    <input wire:model.live="employment_status" type="radio" value="unemployed" class="w-3.5 h-3.5 accent-orange-500">
                                    <i class="fa-solid fa-magnifying-glass text-[10px]" style="color: #555555;"></i>
                                    <span class="font-medium" style="color: #333333;">Unemployed</span>
                                </label>
                            </div>
                            @error('employment_status')
                                <p class="e-msg mt-1.5"><i class="fa-solid fa-circle-exclamation text-xs"></i> {{ $message }}</p>
                            @enderror
                        @else
                            @php
                                $sMap = [
                                    'employed'      => ['Employed',     'fa-user-tie',        'text-violet-700', 'bg-violet-50 border-violet-200'],
                                    'self_employed' => ['Self-Employed','fa-store',           'text-blue-700',   'bg-blue-50 border-blue-200'],
                                    'unemployed'    => ['Unemployed',   'fa-magnifying-glass','text-orange-700', 'bg-orange-50 border-orange-200'],
                                ];
                                $s = $sMap[$employment_status] ?? null;
                            @endphp
                            @if($s)
                                <span class="b-pill {{ $s[2] }} {{ $s[3] }}">
                                    <i class="fa-solid {{ $s[1] }} text-xs"></i> {{ $s[0] }}
                                </span>
                            @else
                                <span class="text-xs" style="color: #555555;">—</span>
                            @endif
                        @endif
                    </div>
                </div>

                {{-- Education Status --}}
                <div class="s-card">
                    <div class="s-head" style="background: #d1fae5;">
                        <div class="s-icon" style="background: #059669;"><i class="fa-solid fa-book-open text-white text-xs"></i></div>
                        <div class="flex-1">
                            <p class="text-xs font-bold" style="color: #333333;">Further Education</p>
                            <p class="text-[10px]" style="color: #555555;">Graduate degree pursuit</p>
                        </div>
                        @if(!$editing)
                            <span class="text-[10px] font-semibold px-2 py-0.5 rounded bg-gray-100" style="color: #555555;">
                                <i class="fa-solid fa-eye text-[9px]"></i> View
                            </span>
                        @endif
                    </div>
                    <div class="s-body">
                        <label class="block s-label mb-2">
                            Education Status @if($editing)<span class="text-red-500">*</span>@endif
                        </label>
                        @if($editing)
                            <div class="flex flex-wrap gap-1.5">
                                <label class="r-pill">
                                    <input wire:model="education_status" type="radio" value="none" class="w-3.5 h-3.5 accent-gray-500">
                                    <i class="fa-solid fa-minus text-[10px]" style="color: #555555;"></i>
                                    <span class="font-medium" style="color: #333333;">None</span>
                                </label>
                                <label class="r-pill">
                                    <input wire:model="education_status" type="radio" value="pursuing_masteral" class="w-3.5 h-3.5 accent-blue-600">
                                    <i class="fa-solid fa-scroll text-[10px]" style="color: #555555;"></i>
                                    <span class="font-medium" style="color: #333333;">Masteral</span>
                                </label>
                                <label class="r-pill">
                                    <input wire:model="education_status" type="radio" value="pursuing_doctorate" class="w-3.5 h-3.5 accent-violet-600">
                                    <i class="fa-solid fa-hat-wizard text-[10px]" style="color: #555555;"></i>
                                    <span class="font-medium" style="color: #333333;">Doctorate</span>
                                </label>
                            </div>
                            @error('education_status')
                                <p class="e-msg mt-1.5"><i class="fa-solid fa-circle-exclamation text-xs"></i> {{ $message }}</p>
                            @enderror
                        @else
                            @php
                                $eMap = [
                                    'none'               => ['None',               'fa-minus',     'text-gray-700',   'bg-gray-100 border-gray-300'],
                                    'pursuing_masteral'  => ['Pursuing Masteral',  'fa-scroll',    'text-blue-700',   'bg-blue-50 border-blue-200'],
                                    'pursuing_doctorate' => ['Pursuing Doctorate', 'fa-hat-wizard','text-violet-700', 'bg-violet-50 border-violet-200'],
                                ];
                                $e = $eMap[$education_status] ?? null;
                            @endphp
                            @if($e)
                                <span class="b-pill {{ $e[2] }} {{ $e[3] }}">
                                    <i class="fa-solid {{ $e[1] }} text-xs"></i> {{ $e[0] }}
                                </span>
                            @else
                                <span class="text-xs" style="color: #555555;">—</span>
                            @endif
                        @endif
                    </div>
                </div>

            </div>{{-- end row 1 --}}

            {{-- ── ROW 2: Employment Details + Career Path ─────────────────── --}}
            @if($editing && in_array($employment_status, ['employed','self_employed']))
            <div class="emp-grid">

                {{-- Employment Details (left) --}}
                <div class="s-card">
                    <div class="s-head" style="background: #dbeafe;">
                        <div class="s-icon" style="background: #2563eb;"><i class="fa-solid fa-building text-white text-xs"></i></div>
                        <div class="flex-1">
                            <p class="text-xs font-bold" style="color: #333333;">Employment Details</p>
                            <p class="text-[10px]" style="color: #555555;">Company, title, type &amp; location</p>
                        </div>
                    </div>
                    <div class="s-body space-y-3">

                        {{-- Company Name --}}
                        <div>
                            <label class="block s-label mb-1">
                                {{ $employment_status === 'self_employed' ? 'Business Name' : 'Company Name' }}
                                <span class="text-red-500">*</span>
                            </label>
                            <input wire:model="company_name" type="text"
                                   placeholder="{{ $employment_status === 'self_employed' ? 'E.G. ABC TRADING' : 'E.G. JOLLIBEE FOODS CORP.' }}"
                                   oninput="this.value=this.value.toUpperCase()"
                                   class="w-full px-3 py-2 text-xs rounded-lg transition-all f-edit{{ $errors->has('company_name') ? ' err' : '' }}"
                                   style="text-transform: uppercase; letter-spacing: .03em;">
                            @error('company_name')
                                <p class="e-msg mt-1"><i class="fa-solid fa-circle-exclamation text-xs"></i> {{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Job Title --}}
                        <div>
                            <label class="block s-label mb-1">
                                {{ $employment_status === 'self_employed' ? 'Occupation Type' : 'Job Title' }}
                                <span class="text-red-500">*</span>
                            </label>
                            <select wire:model.live="job_title"
                                    class="w-full px-3 py-2 text-xs rounded-lg transition-all f-edit job-select{{ $errors->has('job_title') ? ' err' : '' }}">
                                <option value="">— Select Job Title —</option>
                                @foreach($jobOptions as $title)
                                    <option value="{{ $title }}">{{ $title }}</option>
                                @endforeach
                            </select>
                            @error('job_title')
                                <p class="e-msg mt-1"><i class="fa-solid fa-circle-exclamation text-xs"></i> {{ $message }}</p>
                            @enderror

                            {{-- Auto-related badge for known titles --}}
                            @if($job_title && $job_title !== 'Other')
                                <div class="mt-1.5 flex items-center gap-1.5">
                                    <span class="text-[10px]" style="color: #555555;">
                                        <i class="fa-solid fa-wand-magic-sparkles text-violet-400 mr-0.5"></i>Auto:
                                    </span>
                                    <span class="b-pill text-emerald-700 bg-emerald-50 border-emerald-200 auto-rel-badge">
                                        <i class="fa-solid fa-check-circle text-[9px]"></i> Related to Course
                                    </span>
                                </div>
                            @endif

                            {{-- ── "Other" expanded section ── --}}
                            @if($job_title === 'Other')
                            <div class="specify-wrap mt-3 p-3 rounded-xl border border-dashed border-violet-200 bg-violet-50/40 space-y-3">

                                {{-- Specify job title --}}
                                <div>
                                    <label class="block s-label mb-1">
                                        Please Specify <span class="text-red-500">*</span>
                                    </label>
                                    <div class="relative">
                                        <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-violet-400 pointer-events-none">
                                            <i class="fa-solid fa-pen-nib text-[10px]"></i>
                                        </span>
                                        <input wire:model.live="custom_job_title" type="text" maxlength="255"
                                               placeholder="e.g. Marine Engineer, Fashion Designer…"
                                               class="w-full pl-7 pr-3 py-2 text-xs rounded-lg transition-all f-edit{{ $errors->has('custom_job_title') ? ' err' : '' }}">
                                    </div>
                                    @error('custom_job_title')
                                        <p class="e-msg mt-1"><i class="fa-solid fa-circle-exclamation text-xs"></i> {{ $message }}</p>
                                    @enderror
                                    <p class="mt-0.5 text-[10px]" style="color: #555555;">
                                        <i class="fa-solid fa-circle-info text-blue-400 mr-0.5"></i>
                                        This will be saved exactly as entered.
                                    </p>
                                </div>

                                {{-- ── Smart auto-detected relevance ── --}}
                                @if($custom_job_title)
                                <div class="pt-2 border-t border-violet-200/60">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span class="s-label flex items-center gap-1">
                                            <i class="fa-solid fa-wand-magic-sparkles text-violet-400"></i>
                                            Auto-Detected Relevance:
                                        </span>
                                        @php
                                            $relData = [
                                                'yes'       => [
                                                    'Related to Course',
                                                    'text-emerald-700 bg-emerald-50 border-emerald-200',
                                                    'fa-check-circle',
                                                    'Your job title is recognized as related to your course.',
                                                ],
                                                'partially' => [
                                                    'Partially Related',
                                                    'text-amber-700 bg-amber-50 border-amber-200',
                                                    'fa-circle-half-stroke',
                                                    'Your job title appears partially related to your course.',
                                                ],
                                                'no'        => [
                                                    'Not Related',
                                                    'text-red-700 bg-red-50 border-red-200',
                                                    'fa-circle-xmark',
                                                    'Your job title does not appear related to your course.',
                                                ],
                                            ];
                                            $rd = $relData[$course_relevance] ?? null;
                                        @endphp
                                        @if($rd)
                                            <span class="b-pill {{ $rd[1] }} auto-rel-badge">
                                                <i class="fa-solid {{ $rd[2] }} text-xs"></i>
                                                {{ $rd[0] }}
                                            </span>
                                        @else
                                            <span class="text-[10px] italic" style="color:#999;">
                                                <i class="fa-solid fa-ellipsis fa-beat text-violet-300 text-xs mr-1"></i>
                                                Detecting…
                                            </span>
                                        @endif
                                    </div>
                                    @if($rd ?? null)
                                    <p class="mt-1 text-[10px]" style="color: #777777;">
                                        <i class="fa-solid fa-circle-info text-blue-400 mr-0.5"></i>
                                        {{ $rd[3] }}
                                        <span class="font-semibold" style="color: var(--brand);">Detected automatically — no manual selection needed.</span>
                                    </p>
                                    @endif
                                </div>
                                @else
                                <div class="pt-2 border-t border-violet-200/60">
                                    <p class="text-[10px] italic" style="color: #999999;">
                                        <i class="fa-solid fa-wand-magic-sparkles text-violet-300 mr-1"></i>
                                        Start typing your job title — relevance will be detected automatically.
                                    </p>
                                </div>
                                @endif

                            </div>
                            @endif
                        </div>

                        {{-- Employment Type --}}
                        <div>
                            <label class="block s-label mb-1.5">Employment Type <span class="text-red-500">*</span></label>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach([
                                    'full_time'     => ['Full-Time',     'fa-clock'],
                                    'part_time'     => ['Part-Time',     'fa-clock-rotate-left'],
                                    'contractual'   => ['Contractual',   'fa-file-contract'],
                                    'project_based' => ['Project-Based', 'fa-diagram-project'],
                                    'internship'    => ['Internship',    'fa-graduation-cap'],
                                ] as $val => [$lbl, $ico])
                                <label class="r-pill">
                                    <input wire:model="employment_type" type="radio" value="{{ $val }}" class="w-3.5 h-3.5 accent-violet-600">
                                    <i class="fa-solid {{ $ico }} text-[10px]" style="color: #555555;"></i>
                                    <span class="font-medium" style="color: #333333;">{{ $lbl }}</span>
                                </label>
                                @endforeach
                            </div>
                            @error('employment_type')
                                <p class="e-msg mt-1"><i class="fa-solid fa-circle-exclamation text-xs"></i> {{ $message }}</p>
                            @enderror
                        </div>

                        {{-- Work Location --}}
                        <div>
                            <label class="block s-label mb-1.5">Work Location <span class="text-red-500">*</span></label>
                            <div class="flex gap-1.5 flex-wrap">
                                <label class="r-pill">
                                    <input wire:model="work_location" type="radio" value="local" class="w-3.5 h-3.5 accent-emerald-600">
                                    <i class="fa-solid fa-location-dot text-[10px]" style="color: #555555;"></i>
                                    <span class="font-medium" style="color: #333333;">Local</span>
                                </label>
                                <label class="r-pill">
                                    <input wire:model="work_location" type="radio" value="abroad" class="w-3.5 h-3.5 accent-sky-600">
                                    <i class="fa-solid fa-earth-asia text-[10px]" style="color: #555555;"></i>
                                    <span class="font-medium" style="color: #333333;">Abroad</span>
                                </label>
                            </div>
                            @error('work_location')
                                <p class="e-msg mt-1"><i class="fa-solid fa-circle-exclamation text-xs"></i> {{ $message }}</p>
                            @enderror
                        </div>

                    </div>
                </div>

                {{-- Career Path (right) --}}
                <div class="s-card">
                    <div class="s-head" style="background: #cffafe;">
                        <div class="s-icon" style="background: #0891b2;"><i class="fa-solid fa-road text-white text-xs"></i></div>
                        <div class="flex-1">
                            <p class="text-xs font-bold" style="color: #333333;">Career Path</p>
                            <p class="text-[10px]" style="color: #555555;">Select all that apply (optional)</p>
                        </div>
                    </div>
                    <div class="s-body">
                        <div class="flex flex-wrap gap-1.5">
                            @foreach([
                                'ofw'                   => ['OFW',                  'fa-plane'],
                                'freelancer'            => ['Freelancer',            'fa-laptop'],
                                'entrepreneur'          => ['Entrepreneur',          'fa-lightbulb'],
                                'career_shifter'        => ['Career Shifter',        'fa-arrows-rotate'],
                                'industry_professional' => ['Industry Professional', 'fa-industry'],
                            ] as $val => [$lbl, $ico])
                            <label class="c-pill">
                                <input wire:model="career_path" type="checkbox" value="{{ $val }}" class="w-3.5 h-3.5 accent-cyan-600">
                                <i class="fa-solid {{ $ico }} text-[10px]" style="color: #555555;"></i>
                                <span style="color: #333333;">{{ $lbl }}</span>
                            </label>
                            @endforeach
                        </div>
                        <p class="text-[10px] mt-2" style="color: #555555;">
                            <i class="fa-solid fa-circle-info text-blue-400 mr-0.5"></i>
                            Optional — check all that describe your path.
                        </p>
                    </div>
                </div>

            </div>{{-- end row 2 --}}
            @endif

            {{-- ── Unemployment Status ──────────────────────────────────────── --}}
            @if($editing && $employment_status === 'unemployed')
            <div class="s-card">
                <div class="s-head" style="background: #fed7aa;">
                    <div class="s-icon" style="background: #d97706;"><i class="fa-solid fa-magnifying-glass text-white text-xs"></i></div>
                    <div class="flex-1">
                        <p class="text-xs font-bold" style="color: #333333;">Unemployment Status</p>
                        <p class="text-[10px]" style="color: #555555;">Are you actively looking for work?</p>
                    </div>
                </div>
                <div class="s-body">
                    <label class="block s-label mb-1.5">Job Search Status <span class="text-red-500">*</span></label>
                    <div class="flex flex-wrap gap-1.5">
                        <label class="r-pill">
                            <input wire:model="unemployment_status" type="radio" value="seeking_employment" class="w-3.5 h-3.5 accent-violet-600">
                            <i class="fa-solid fa-person-walking text-[10px]" style="color: #555555;"></i>
                            <span class="font-medium" style="color: #333333;">Actively Seeking Employment</span>
                        </label>
                        <label class="r-pill">
                            <input wire:model="unemployment_status" type="radio" value="not_looking" class="w-3.5 h-3.5 accent-gray-500">
                            <i class="fa-solid fa-pause text-[10px]" style="color: #555555;"></i>
                            <span class="font-medium" style="color: #333333;">Not Currently Looking</span>
                        </label>
                    </div>
                    @error('unemployment_status')
                        <p class="e-msg mt-1.5"><i class="fa-solid fa-circle-exclamation text-xs"></i> {{ $message }}</p>
                    @enderror
                </div>
            </div>
            @endif

            {{-- ── VIEW SUMMARY — Employed / Self-Employed ─────────────────── --}}
            @if(!$editing && $hasRecord && in_array($employment_status, ['employed','self_employed']))
            <div class="s-card">
                <div class="s-head" style="background: #dbeafe;">
                    <div class="s-icon" style="background: #2563eb;"><i class="fa-solid fa-id-badge text-white text-xs"></i></div>
                    <div class="flex-1">
                        <p class="text-xs font-bold" style="color: #333333;">Employment &amp; Career Summary</p>
                        <p class="text-[10px]" style="color: #555555;">Your current submitted details</p>
                    </div>
                    <span class="text-[10px] font-semibold px-2 py-0.5 rounded bg-gray-100" style="color: #555555;">
                        <i class="fa-solid fa-eye text-[9px]"></i> View
                    </span>
                </div>
                <div class="s-body space-y-3">
                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <p class="s-label mb-1">{{ $employment_status === 'self_employed' ? 'Business Name' : 'Company Name' }}</p>
                            <div class="px-3 py-2 rounded-lg f-view text-xs font-semibold uppercase">{{ $company_name ?: '—' }}</div>
                        </div>
                        <div>
                            <p class="s-label mb-1">{{ $employment_status === 'self_employed' ? 'Occupation Type' : 'Job Title' }}</p>
                            @php
                                $displayJobTitle = ($job_title === 'Other' && $custom_job_title)
                                    ? $custom_job_title
                                    : ($job_title ?: '—');
                            @endphp
                            <div class="px-3 py-2 rounded-lg f-view text-xs font-semibold">{{ $displayJobTitle }}</div>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-2">
                        @php $tM=['full_time'=>'Full-Time','part_time'=>'Part-Time','contractual'=>'Contractual','project_based'=>'Project-Based','internship'=>'Internship']; @endphp
                        <div>
                            <p class="s-label mb-1">Employment Type</p>
                            <div class="px-3 py-2 rounded-lg f-view text-xs font-semibold">{{ $tM[$employment_type] ?? '—' }}</div>
                        </div>
                        <div>
                            <p class="s-label mb-1">Work Location</p>
                            <div class="px-3 py-2 rounded-lg f-view text-xs font-semibold flex items-center gap-1.5">
                                @if($work_location === 'local')
                                    <i class="fa-solid fa-location-dot text-emerald-500 text-[10px]"></i> Local
                                @elseif($work_location === 'abroad')
                                    <i class="fa-solid fa-earth-asia text-sky-500 text-[10px]"></i> Abroad
                                @else
                                    —
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-1.5 pt-1">
                        @if($course_relevance)
                            @php
                                $rM = [
                                    'yes'       => ['Related to Course','text-emerald-700','bg-emerald-50 border-emerald-200','fa-check-circle'],
                                    'no'        => ['Not Related',      'text-red-700',   'bg-red-50 border-red-200',        'fa-times-circle'],
                                    'partially' => ['Partially Related','text-amber-700', 'bg-amber-50 border-amber-200',    'fa-circle-half-stroke'],
                                ];
                                $r = $rM[$course_relevance] ?? null;
                            @endphp
                            @if($r)
                                <span class="b-pill {{ $r[1] }} {{ $r[2] }}">
                                    <i class="fa-solid {{ $r[3] }} text-xs"></i> {{ $r[0] }}
                                </span>
                            @endif
                        @endif

                        @php $cpL=['ofw'=>'OFW','freelancer'=>'Freelancer','entrepreneur'=>'Entrepreneur','career_shifter'=>'Career Shifter','industry_professional'=>'Industry Professional']; @endphp
                        @foreach($career_path as $cp)
                            <span class="b-pill text-cyan-700 bg-cyan-50 border-cyan-200">
                                <i class="fa-solid fa-check text-xs"></i> {{ $cpL[$cp] ?? $cp }}
                            </span>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- VIEW SUMMARY — Unemployed --}}
            @elseif(!$editing && $hasRecord && $employment_status === 'unemployed')
            <div class="s-card">
                <div class="s-head" style="background: #fed7aa;">
                    <div class="s-icon" style="background: #d97706;"><i class="fa-solid fa-magnifying-glass text-white text-xs"></i></div>
                    <div class="flex-1">
                        <p class="text-xs font-bold" style="color: #333333;">Unemployment Details</p>
                    </div>
                    <span class="text-[10px] font-semibold px-2 py-0.5 rounded bg-gray-100" style="color: #555555;">
                        <i class="fa-solid fa-eye text-[9px]"></i> View
                    </span>
                </div>
                <div class="s-body">
                    @php
                        $uM = [
                            'seeking_employment' => ['Actively Seeking Employment','text-violet-700','bg-violet-50 border-violet-200'],
                            'not_looking'        => ['Not Currently Looking',      'text-gray-700',  'bg-gray-100 border-gray-300'],
                        ];
                        $u = $uM[$unemployment_status] ?? null;
                    @endphp
                    @if($u)
                        <span class="b-pill {{ $u[1] }} {{ $u[2] }}">{{ $u[0] }}</span>
                    @else
                        <span class="text-xs" style="color: #555555;">—</span>
                    @endif
                </div>
            </div>
            @endif

            {{-- Empty state --}}
            @if(!$hasRecord && !$editing)
            <div class="flex flex-col items-center justify-center gap-3 py-16 text-center">
                <div class="w-14 h-14 rounded-2xl bg-gray-100 flex items-center justify-center">
                    <i class="fas fa-briefcase text-2xl" style="color: #555555;"></i>
                </div>
                <div>
                    <p class="font-semibold text-base" style="color: #333333;">No Employment Record Yet</p>
                    <p class="text-sm mt-1" style="color: #555555;">
                        Click <strong style="color: #7a3f91;">Update Employment</strong> above to submit your information.
                    </p>
                </div>
            </div>
            @endif

        </div>{{-- /content-block-body --}}

        {{-- ── SAVE / CANCEL FOOTER ─────────────────────────────────────────── --}}
        @if($editing)
        <div class="save-footer">
            @if($hasRecord)
            <button wire:click="cancelEditing"
                    class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-semibold
                           text-white border border-white/30 hover:bg-white/10 transition active:scale-95 cursor-pointer uppercase tracking-widest">
                <i class="fa-solid fa-xmark text-xs"></i>
                <span>Cancel</span>
            </button>
            @endif
            <button wire:click="saveEmployment"
                    wire:loading.attr="disabled" wire:target="saveEmployment"
                    class="inline-flex items-center gap-1.5 px-5 py-2 rounded-lg text-sm font-bold
                           text-white bg-white/15 border border-white/30 hover:bg-white/25
                           disabled:opacity-60 active:scale-[0.98] transition cursor-pointer uppercase tracking-widest">
                <span wire:loading.remove wire:target="saveEmployment">
                    <i class="fa-solid fa-floppy-disk text-xs mr-1"></i> Save Employment
                </span>
                <span wire:loading wire:target="saveEmployment">
                    <svg class="animate-spin w-4 h-4 inline mr-1" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                    </svg>
                    Saving…
                </span>
            </button>
        </div>
        @endif

    </div>{{-- /content-block --}}

</div>{{-- /main layout --}}


{{-- ══════════════════════════════════════════════════════════════════════════
     EMPLOYMENT HISTORY MODAL
══════════════════════════════════════════════════════════════════════════════ --}}
@if($showHistory)
<div class="hist-overlay" wire:click.self="closeHistory">
    <div class="hist-modal">

        <div class="hist-head">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0"
                     style="background: var(--brand);">
                    <i class="fa-solid fa-clock-rotate-left text-white text-sm"></i>
                </div>
                <div>
                    <p class="font-bold text-sm leading-tight" style="color: #333333;">Employment History</p>
                    <p class="text-xs mt-0.5" style="color: #555555;">
                        {{ count($history) }} {{ count($history) === 1 ? 'record' : 'records' }} on file
                        &nbsp;·&nbsp;
                        <span style="color: #7a3f91;" class="font-semibold">Newest first</span>
                    </p>
                </div>
            </div>
            <button wire:click="closeHistory"
                    class="w-8 h-8 flex items-center justify-center rounded-lg bg-gray-100
                           hover:bg-red-100 hover:text-red-600 transition-all flex-shrink-0 cursor-pointer"
                    style="color: #555555;">
                <i class="fa-solid fa-xmark text-sm"></i>
            </button>
        </div>

        <div class="hist-body scroll-c">
            @if(empty($history))
                <div class="hist-empty">
                    <i class="fa-solid fa-folder-open text-4xl mb-3 block text-gray-300"></i>
                    <p class="font-semibold text-sm" style="color: #555555;">No employment records found.</p>
                </div>
            @else
                <div class="tl-wrap">
                    <div class="tl-line"></div>
                    @foreach($history as $idx => $entry)
                    @php
                        $isCur  = $entry['is_current'];
                        $isWork = in_array($entry['employment_status'], ['employed','self_employed']);
                        $sLabel = ['employed'=>'Employed','self_employed'=>'Self-Employed','unemployed'=>'Unemployed'][$entry['employment_status']] ?? '';
                        $sBadge = match($entry['employment_status']) {
                            'employed'      => ['text-violet-700','bg-violet-100 border-violet-200','fa-user-tie'],
                            'self_employed' => ['text-blue-700',  'bg-blue-100 border-blue-200',    'fa-store'],
                            'unemployed'    => ['text-orange-700','bg-orange-100 border-orange-200','fa-magnifying-glass'],
                            default         => ['text-gray-600',  'bg-gray-100 border-gray-300',    'fa-circle'],
                        };
                        $entryNum = count($history) - $idx;
                    @endphp
                    <div class="tl-entry">
                        <div class="tl-dot {{ $isCur ? 'cur' : 'past' }}">
                            @if($isCur)
                                <i class="fa-solid fa-circle-dot text-[9px]"></i>
                            @else
                                <i class="fa-solid fa-circle text-[7px]"></i>
                            @endif
                        </div>
                        <div class="tl-card {{ $isCur ? 'tl-cur' : '' }}">
                            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2 mb-2">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span class="b-pill {{ $sBadge[0] }} {{ $sBadge[1] }}">
                                        <i class="fa-solid {{ $sBadge[2] }} text-xs"></i> {{ $sLabel }}
                                    </span>
                                    @if($isCur)
                                        <span class="b-pill text-emerald-700 bg-emerald-100 border-emerald-200">
                                            <i class="fa-solid fa-circle text-[7px] text-emerald-500"></i> Current
                                        </span>
                                    @endif
                                </div>
                                <span class="text-[10px] font-bold flex-shrink-0" style="color: #555555;">#{{ $entryNum }}</span>
                            </div>

                            <div class="flex flex-wrap gap-x-4 gap-y-0.5 mb-2">
                                <p class="tl-meta {{ $isCur ? 'cur-meta' : '' }}">
                                    <i class="fa-solid fa-calendar-plus text-xs mr-1"></i>
                                    Submitted: {{ $entry['submitted_at'] }}
                                </p>
                                @if(!$isCur && $entry['replaced_at'])
                                    <p class="tl-meta">
                                        <i class="fa-solid fa-calendar-xmark text-xs mr-1"></i>
                                        Replaced: {{ $entry['replaced_at'] }}
                                    </p>
                                @endif
                            </div>

                            @if($isWork && ($entry['company_name'] || $entry['job_title']))
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1.5 mb-2
                                        p-2.5 rounded-lg border border-gray-200"
                                 style="background: {{ $isCur ? 'rgba(255,255,255,0.6)' : '#fff' }};">
                                @if($entry['company_name'])
                                <div>
                                    <p class="s-label mb-0.5">{{ $entry['employment_status'] === 'self_employed' ? 'Business' : 'Company' }}</p>
                                    <p class="text-xs font-bold uppercase tracking-wide" style="color: #333333;">{{ $entry['company_name'] }}</p>
                                </div>
                                @endif
                                @if($entry['job_title'])
                                <div>
                                    <p class="s-label mb-0.5">{{ $entry['employment_status'] === 'self_employed' ? 'Occupation' : 'Job Title' }}</p>
                                    <p class="text-xs font-bold" style="color: #333333;">{{ $entry['job_title'] }}</p>
                                </div>
                                @endif
                            </div>
                            @endif

                            <div class="flex flex-wrap gap-1">
                                @if($isWork && $entry['employment_type'])
                                    <span class="b-pill bg-gray-100 border-gray-200" style="color: #333333;">
                                        <i class="fa-solid fa-clock text-xs"></i> {{ $entry['employment_type'] }}
                                    </span>
                                @endif
                                @if($isWork && $entry['work_location'])
                                    @php
                                        $lc = $entry['work_location'] === 'Abroad'
                                            ? 'text-sky-700 bg-sky-50 border-sky-200'
                                            : 'text-emerald-700 bg-emerald-50 border-emerald-200';
                                        $li = $entry['work_location'] === 'Abroad' ? 'fa-earth-asia' : 'fa-location-dot';
                                    @endphp
                                    <span class="b-pill {{ $lc }}">
                                        <i class="fa-solid {{ $li }} text-xs"></i> {{ $entry['work_location'] }}
                                    </span>
                                @endif
                                @if($isWork && $entry['course_relevance'])
                                    @php
                                        $rc = match($entry['course_relevance']) {
                                            'Related to Course' => 'text-emerald-700 bg-emerald-50 border-emerald-200',
                                            'Not Related'       => 'text-red-700 bg-red-50 border-red-200',
                                            default             => 'text-amber-700 bg-amber-50 border-amber-200',
                                        };
                                    @endphp
                                    <span class="b-pill {{ $rc }}">
                                        <i class="fa-solid fa-graduation-cap text-xs"></i> {{ $entry['course_relevance'] }}
                                    </span>
                                @endif
                                @if(!$isWork && $entry['unemployment_status'])
                                    <span class="b-pill text-orange-700 bg-orange-50 border-orange-200">
                                        <i class="fa-solid fa-person-walking text-xs"></i> {{ $entry['unemployment_status'] }}
                                    </span>
                                @endif
                                @if($entry['education_status'] && $entry['education_status'] !== 'None')
                                    <span class="b-pill text-blue-700 bg-blue-50 border-blue-200">
                                        <i class="fa-solid fa-scroll text-xs"></i> {{ $entry['education_status'] }}
                                    </span>
                                @endif
                            </div>

                            @if($isWork && count($entry['career_path_labels'] ?? []))
                            <div class="flex flex-wrap gap-1 mt-2 pt-2 border-t border-gray-200/60">
                                <span class="s-label self-center mr-1">Path:</span>
                                @foreach($entry['career_path_labels'] as $cp)
                                    <span class="b-pill text-cyan-700 bg-cyan-50 border-cyan-200">
                                        <i class="fa-solid fa-check text-xs"></i> {{ $cp }}
                                    </span>
                                @endforeach
                            </div>
                            @endif

                        </div>
                    </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="hist-foot">
            <button wire:click="closeHistory"
                    class="px-4 py-2 rounded-lg font-bold text-xs border border-gray-300
                           bg-white hover:bg-gray-50 active:scale-95 transition-all cursor-pointer"
                    style="color: #333333;">
                <i class="fa-solid fa-xmark text-xs mr-1.5"></i> Close
            </button>
        </div>
    </div>
</div>
@endif

</div>