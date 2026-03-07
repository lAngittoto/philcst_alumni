<?php
use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use App\Models\Alumni;
use App\Models\Course;
use App\Models\Organizer;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

new class extends Component {
    use WithPagination, WithFileUploads;

    public string $activeTab = 'alumni';
    public string $alumniSearch = '';
    public string $alumniBatch = '';
    public string $alumniCourse = '';
    public string $alumniSort = 'recent';
    public string $orgSearch = '';
    public string $orgCollege = '';   // filter by college name
    public string $orgSort = 'recent';

    public string $regFirstName = '';
    public string $regMiddleInitial = '';
    public string $regLastName = '';
    public string $regSuffix = '';
    public string $regStudentId = '';
    public string $regEmail = '';
    public string $regCourseCode = '';
    public string $regYear = '';
    public $regPhoto = null;
    public bool $registeringAlumni = false;
    public array $alumniErrors = [];

    public string $orgFirstName = '';
    public string $orgMiddleInitial = '';
    public string $orgLastName = '';
    public string $orgSuffix = '';
    public string $orgTeacherId = '';
    public string $orgEmail = '';
    public string $orgDept = '';      // stores course CODE
    public $orgPhoto = null;
    public bool $registeringOrganizer = false;
    public array $organizerErrors = [];

    // ---- Alumni Courses ----
    public array $coursesList = [];
    public string $courseCode = '';
    public string $courseName = '';
    public ?int $editingCourseId = null;
    public bool $savingCourse = false;
    public string $courseAlert = '';
    public string $courseAlertType = '';
    public ?int $deleteCourseId = null;
    public string $deleteCourseName = '';
    public bool $deletingCourse = false;

    // ---- Organizer Colleges ----
    public array $orgCoursesList = [];     // [college => [course rows]]
    public string $orgNewCollegeName = ''; // input for new college name
    public ?string $orgAddingToCollege = null;       // college currently being edited/assigned
    public array $orgSelectedCourseCodes = [];       // checkboxes
    public bool $savingOrgCourse = false;
    public string $orgCourseAlert = '';
    public string $orgCourseAlertType = '';
    public ?string $deleteOrgCollegeName = null;
    public string $deleteOrgCourseName = '';
    public bool $deletingOrgCourse = false;

    // ---- Shared ----
    public string $flashMessage = '';
    public string $flashType = '';
    public bool $showFlash = false;
    public string $activeModal = '';
    public $importFile = null;
    public bool $importingFile = false;
    public string $importStatus = '';
    public int $importProgress = 0;
    public int $importTotal = 0;
    public int $importSuccessCount = 0;
    public int $importFailCount = 0;
    public array $importErrors = [];

    public ?int $viewingProfileId = null;
    public string $viewingProfileType = 'alumni';
    public $viewingProfile = null;
    public $updatingProfilePhoto = null;
    public bool $updatingProfile = false;

    protected string $paginationTheme = 'tailwind';

    #[On('showFlash')]
    public function handleShowFlash(string $type, string $message): void
    {
        $this->flash($type, $message);
    }

    public function mount(): void
    {
        $this->coursesList = Course::all()->toArray();
        $this->regYear = (string) date('Y');
        $this->showFlash = false;
        $this->loadOrgCourses();

        if (session()->has('success')) { $msg=session('success'); session()->forget('success'); $this->dispatch('showFlash',type:'success',message:$msg); }
        if (session()->has('error'))   { $msg=session('error');   session()->forget('error');   $this->dispatch('showFlash',type:'error',message:$msg); }
    }

    private function loadOrgCourses(): void
    {
        $courses = Course::whereNotNull('college')->where('college','!=','')->orderBy('college')->orderBy('code')->get();
        $grouped = [];
        foreach ($courses as $c) {
            $col = $c->college;
            if (!isset($grouped[$col])) $grouped[$col] = [];
            $grouped[$col][] = $c->toArray();
        }
        $this->orgCoursesList = $grouped;
    }

    public function updatingAlumniSearch() { $this->resetPage('alumniPage'); }
    public function updatingOrgSearch()    { $this->resetPage('orgPage'); }
    public function updatingAlumniBatch()  { $this->resetPage('alumniPage'); }
    public function updatingAlumniCourse() { $this->resetPage('alumniPage'); }
    public function updatingAlumniSort()   { $this->resetPage('alumniPage'); }
    public function updatingOrgCollege()   { $this->resetPage('orgPage'); }
    public function updatingOrgSort()      { $this->resetPage('orgPage'); }

    #[Computed]
    public function alumniRecords()
    {
        $q = Alumni::query();
        if ($this->alumniSearch) {
            $q->where(fn($s) => $s->where('name','like',"%{$this->alumniSearch}%")->orWhere('student_id','like',"%{$this->alumniSearch}%")->orWhere('email','like',"%{$this->alumniSearch}%"));
        }
        if ($this->alumniBatch)  $q->where('batch',$this->alumniBatch);
        if ($this->alumniCourse) $q->where('course_code',$this->alumniCourse);
        $q->when($this->alumniSort==='oldest',fn($q)=>$q->orderBy('created_at'),fn($q)=>$q->orderByDesc('created_at'));
        return $q->paginate(100,['*'],'alumniPage');
    }

    #[Computed]
    public function organizerRecords()
    {
        $q = Organizer::withoutTrashed();
        if ($this->orgSearch) {
            $q->where(fn($s) => $s->where('name','like',"%{$this->orgSearch}%")->orWhere('email','like',"%{$this->orgSearch}%")->orWhere('id_number','like',"%{$this->orgSearch}%"));
        }
        if ($this->orgCollege) {
            $codes = Course::where('college',$this->orgCollege)->pluck('code')->toArray();
            $q->whereIn('department',$codes);
        }
        $q->when($this->orgSort==='oldest',fn($q)=>$q->orderBy('created_at'),fn($q)=>$q->orderByDesc('created_at'));
        return $q->paginate(100,['*'],'orgPage');
    }

    public function getCollegeForCourse(string $code): string
    {
        return Course::where('code',$code)->value('college') ?? $code;
    }

    public function getCollegeDepts(string $code): array
    {
        $college = Course::where('code',$code)->value('college');
        if (!$college) return [$code];
        return Course::where('college',$college)->orderBy('code')->pluck('code')->toArray();
    }

    #[Computed] public function courses()    { return Course::orderBy('code')->get(); }
    #[Computed] public function batches()    { return Alumni::distinct()->orderByDesc('batch')->pluck('batch'); }
    #[Computed] public function orgColleges(){ return Course::whereNotNull('college')->where('college','!=','')->distinct()->orderBy('college')->pluck('college'); }

    #[Computed]
    public function orgDepartmentsGrouped()
    {
        return Course::whereNotNull('college')->where('college','!=','')->orderBy('college')->orderBy('code')->get()->groupBy('college');
    }

    #[Computed]
    public function unassignedCourses()
    {
        return Course::where(fn($q)=>$q->whereNull('college')->orWhere('college',''))->orderBy('code')->get();
    }

    #[Computed]
    public function allCoursesForAssign()
    {
        return Course::orderBy('code')->get();
    }

    public function switchTab(string $tab): void { $this->activeTab=$tab; }

    public function openModal(string $modal): void
    {
        $this->activeModal=$modal;
        if ($modal==='importModal') $this->resetImportState();
        if ($modal==='manageOrgCourses') { $this->loadOrgCourses(); $this->resetOrgCourseForm(); }
    }

    public function closeModal(): void
    {
        $this->activeModal=''; $this->resetImportState();
        $this->viewingProfileId=null; $this->updatingProfilePhoto=null;
    }

    public function resetImportState(): void
    {
        $this->importFile=null; $this->importingFile=false; $this->importStatus='';
        $this->importProgress=0; $this->importTotal=0; $this->importSuccessCount=0;
        $this->importFailCount=0; $this->importErrors=[];
    }

    public function cancelImport(): void { $this->resetImportState(); $this->activeModal=''; $this->flash('info','Import cancelled.'); }

    public function resetAlumniFilters(): void { $this->alumniSearch=$this->alumniBatch=$this->alumniCourse=''; $this->alumniSort='recent'; $this->resetPage('alumniPage'); }
    public function resetOrgFilters(): void    { $this->orgSearch=$this->orgCollege=''; $this->orgSort='recent'; $this->resetPage('orgPage'); }

    private function validateName(string $n): bool { return preg_match('/^[a-zA-Z\s\-\.\']+$/',$n)===1; }

    private function buildFullName(string $f,string $m,string $l,string $s): string
    {
        $p=array_filter([trim($f),trim($m),trim($l)]);
        $n=implode(' ',$p);
        if(trim($s)!=='') $n.=' '.trim($s);
        return $n;
    }

    // =====================================================
    // ALUMNI REGISTRATION
    // =====================================================

    public function registerAlumni(): void
    {
        $this->alumniErrors=[]; $this->registeringAlumni=true;
        try {
            if(!$this->validateName(trim($this->regFirstName))) throw new \Exception('First name must contain only letters, spaces, hyphens, or apostrophes');
            if(!$this->validateName(trim($this->regLastName)))  throw new \Exception('Last name must contain only letters, spaces, hyphens, or apostrophes');
            if(trim($this->regSuffix)!==''&&!preg_match('/^[a-zA-Z\s\.\,]+$/',$this->regSuffix)) throw new \Exception('Suffix must contain only letters, spaces, periods, or commas');
            $fullName=$this->buildFullName($this->regFirstName,$this->regMiddleInitial,$this->regLastName,$this->regSuffix);
            $this->validate([
                'regFirstName'=>['required','string','max:100'],'regLastName'=>['required','string','max:100'],
                'regMiddleInitial'=>['nullable','string','max:5'],'regSuffix'=>['nullable','string','max:10'],
                'regStudentId'=>['required','string','regex:/^\d{1,8}$/','unique:alumni,student_id'],
                'regEmail'=>['required','email','max:255','unique:alumni,email','unique:users,email'],
                'regCourseCode'=>['required','string','exists:courses,code'],
                'regYear'=>['required','integer','min:2000','max:'.date('Y')],
                'regPhoto'=>['nullable','image','mimes:jpg,jpeg,png,webp','max:5120'],
            ]);
            $paddedId=str_pad($this->regStudentId,8,'0',STR_PAD_LEFT);
            $course=Course::where('code',$this->regCourseCode)->firstOrFail();
            $photoPath=$this->regPhoto?$this->storeAlumniPhoto($this->regPhoto):null;
            $alumni=Alumni::create(['name'=>$fullName,'student_id'=>$paddedId,'email'=>$this->regEmail,'course_code'=>$this->regCourseCode,'course_name'=>$course->name,'batch'=>(int)$this->regYear,'status'=>'VERIFIED','profile_photo'=>$photoPath]);
            $tmp=Str::random(10); User::create(['name'=>$fullName,'email'=>$this->regEmail,'password'=>Hash::make($tmp),'role'=>'alumni']);
            try{Mail::to($alumni->email)->send(new \App\Mail\AlumniRegistered($alumni,$tmp));}catch(\Exception $e){Log::warning('Email:'.$e->getMessage());}
            $this->resetRegAlumniForm(); $this->flash('success',"Alumni '{$fullName}' registered successfully!"); $this->activeModal='';
        }catch(\Illuminate\Validation\ValidationException $e){$this->alumniErrors=$e->errors();}
        catch(\Exception $e){Log::error('Alumni:'.$e->getMessage());$this->alumniErrors=['general'=>[$e->getMessage()]];}
        finally{$this->registeringAlumni=false;}
    }

    private function storeAlumniPhoto($p): ?string
    {
        if(!$p) return null;
        try{$f="alumni-".Str::uuid().".".$p->getClientOriginalExtension();$r=$p->storeAs('alumni-photos',$f,'public');return $r===false?null:"alumni-photos/{$f}";}
        catch(\Exception $e){Log::error('Photo:'.$e->getMessage());return null;}
    }

    private function resetRegAlumniForm(): void
    {
        $this->regFirstName=$this->regMiddleInitial=$this->regLastName=$this->regSuffix='';
        $this->regStudentId=$this->regEmail=$this->regCourseCode='';
        $this->regPhoto=null;$this->regYear=(string)date('Y');$this->alumniErrors=[];
    }

    // =====================================================
    // ORGANIZER REGISTRATION
    // =====================================================

    public function registerOrganizer(): void
    {
        $this->organizerErrors=[]; $this->registeringOrganizer=true;
        try {
            if(!$this->validateName(trim($this->orgFirstName))) throw new \Exception('First name must contain only letters, spaces, hyphens, or apostrophes');
            if(!$this->validateName(trim($this->orgLastName)))  throw new \Exception('Last name must contain only letters, spaces, hyphens, or apostrophes');
            if(trim($this->orgSuffix)!==''&&!preg_match('/^[a-zA-Z\s\.\,]+$/',$this->orgSuffix)) throw new \Exception('Suffix must contain only letters, spaces, periods, or commas');
            $fullName=$this->buildFullName($this->orgFirstName,$this->orgMiddleInitial,$this->orgLastName,$this->orgSuffix);
            $this->validate([
                'orgFirstName'=>['required','string','max:100'],'orgLastName'=>['required','string','max:100'],
                'orgMiddleInitial'=>['nullable','string','max:5'],'orgSuffix'=>['nullable','string','max:10'],
                'orgTeacherId'=>['required','string','regex:/^\d{1,8}$/','unique:organizer,id_number'],
                'orgEmail'=>['required','email','unique:organizer,email','unique:users,email'],
                'orgDept'=>['required','string','exists:courses,code'],
                'orgPhoto'=>['nullable','image','mimes:jpeg,png,jpg,webp','max:5120'],
            ]);
            $paddedId=str_pad($this->orgTeacherId,8,'0',STR_PAD_LEFT);
            $photoPath=$this->orgPhoto?$this->storeOrganizerPhoto($this->orgPhoto):null;
            $tmp=Str::random(10);
            $user=User::create(['name'=>$fullName,'email'=>$this->orgEmail,'role'=>'organizer','password'=>Hash::make($tmp)]);
            $organizer=Organizer::create(['user_id'=>$user->id,'name'=>$fullName,'email'=>$this->orgEmail,'id_number'=>$paddedId,'department'=>strtoupper($this->orgDept),'profile_photo'=>$photoPath,'status'=>'ACTIVE']);
            try{Mail::to($organizer->email)->send(new \App\Mail\OrganizerRegistered($organizer,$tmp));}catch(\Exception $e){Log::warning('Email:'.$e->getMessage());}
            $this->resetOrgForm(); $this->flash('success',"Organizer '{$fullName}' registered successfully!"); $this->activeModal='';
        }catch(\Illuminate\Validation\ValidationException $e){$this->organizerErrors=$e->errors();}
        catch(\Exception $e){Log::error('Organizer:'.$e->getMessage());$this->organizerErrors=['general'=>[$e->getMessage()]];}
        finally{$this->registeringOrganizer=false;}
    }

    private function storeOrganizerPhoto($p): ?string
    {
        if(!$p) return null;
        try{$f="organizer-".Str::uuid().".".$p->getClientOriginalExtension();$r=$p->storeAs('organizers',$f,'public');return $r===false?null:"organizers/{$f}";}
        catch(\Exception $e){Log::error('OrgPhoto:'.$e->getMessage());return null;}
    }

    private function resetOrgForm(): void
    {
        $this->orgFirstName=$this->orgMiddleInitial=$this->orgLastName=$this->orgSuffix='';
        $this->orgTeacherId=$this->orgEmail=$this->orgDept='';
        $this->orgPhoto=null;$this->organizerErrors=[];
    }

    // =====================================================
    // ALUMNI COURSE MANAGEMENT
    // =====================================================

    public function openEditCourse(int $id): void
    {
        try{$c=Course::findOrFail($id);$this->editingCourseId=$c->id;$this->courseCode=$c->code;$this->courseName=$c->name;$this->courseAlert='';$this->courseAlertType='';}
        catch(\Exception $e){$this->courseAlert='Failed to load course.';$this->courseAlertType='error';}
    }

    public function resetCourseForm(): void { $this->editingCourseId=null;$this->courseCode=$this->courseName='';$this->courseAlert='';$this->courseAlertType='';$this->savingCourse=false; }

    public function saveCourse(): void
    {
        $this->savingCourse=true;
        $code=strtoupper(trim($this->courseCode));$name=trim($this->courseName);
        if(!$code||!$name){$this->courseAlert='Code and Name are required.';$this->courseAlertType='error';$this->savingCourse=false;return;}
        try{
            if($this->editingCourseId){Course::findOrFail($this->editingCourseId)->update(['code'=>$code,'name'=>$name]);$this->courseAlert='✓ Course updated!';}
            else{Course::create(['code'=>$code,'name'=>$name]);$this->courseAlert='✓ Course added!';}
            $this->courseAlertType='success';$this->coursesList=Course::all()->toArray();$this->resetCourseForm();
        }catch(\Exception $e){$this->courseAlert=str_contains($e->getMessage(),'Duplicate')? 'Code already exists.':'Failed to save.';$this->courseAlertType='error';}
        finally{$this->savingCourse=false;}
    }

    public function confirmDeleteCourse(int $id): void
    {
        try{$c=Course::findOrFail($id);$this->deleteCourseId=$id;$this->deleteCourseName=$c->name;$this->activeModal='deleteCourseConfirm';}
        catch(\Exception $e){$this->courseAlert='Failed.';$this->courseAlertType='error';}
    }

    public function deleteCourse(): void
    {
        $this->deletingCourse=true;
        try{Course::findOrFail($this->deleteCourseId)->delete();$this->courseAlert='✓ Deleted!';$this->courseAlertType='success';$this->coursesList=Course::all()->toArray();$this->deleteCourseId=null;$this->deleteCourseName='';$this->activeModal='manageCourses';}
        catch(\Exception $e){$this->courseAlert='Failed.';$this->courseAlertType='error';$this->activeModal='manageCourses';}
        finally{$this->deletingCourse=false;}
    }

    // =====================================================
    // ORGANIZER COLLEGE MANAGEMENT
    // Colleges are virtual — they are just the `college` field on Course rows
    // =====================================================

    public function resetOrgCourseForm(): void
    {
        $this->orgNewCollegeName='';$this->orgAddingToCollege=null;
        $this->orgSelectedCourseCodes=[];$this->savingOrgCourse=false;
        $this->orgCourseAlert='';$this->orgCourseAlertType='';
        $this->deleteOrgCollegeName=null;$this->deleteOrgCourseName='';
    }

    // Step 1: type college name → opens assign-courses panel
    public function addCollege(): void
    {
        $name=trim($this->orgNewCollegeName);
        if(!$name){$this->orgCourseAlert='College name is required.';$this->orgCourseAlertType='error';return;}
        if(isset($this->orgCoursesList[$name])){$this->orgCourseAlert="College '{$name}' already exists.";$this->orgCourseAlertType='error';return;}
        $this->orgAddingToCollege=$name;
        $this->orgSelectedCourseCodes=[];
        $this->orgNewCollegeName='';
        $this->orgCourseAlert='';$this->orgCourseAlertType='';
    }

    // Edit existing college: open assign-courses panel pre-checked
    public function startEditingCollege(string $college): void
    {
        $this->orgAddingToCollege=$college;
        $this->orgSelectedCourseCodes=Course::where('college',$college)->pluck('code')->toArray();
        $this->orgCourseAlert='';$this->orgCourseAlertType='';
    }

    public function cancelAddingCourses(): void { $this->orgAddingToCollege=null;$this->orgSelectedCourseCodes=[];$this->orgNewCollegeName='';$this->orgCourseAlert='';$this->orgCourseAlertType=''; }

    // Save: set college field on selected courses, clear it from unselected
    public function saveCollegeCourses(): void
    {
        $this->savingOrgCourse=true;
        $college=trim($this->orgAddingToCollege??'');
        if(!$college){$this->orgCourseAlert='College name missing.';$this->orgCourseAlertType='error';$this->savingOrgCourse=false;return;}
        if(empty($this->orgSelectedCourseCodes)){$this->orgCourseAlert='Select at least one course.';$this->orgCourseAlertType='error';$this->savingOrgCourse=false;return;}
        try{
            // Remove this college from courses that were deselected
            Course::where('college',$college)->whereNotIn('code',$this->orgSelectedCourseCodes)->update(['college'=>null]);
            // Assign college to selected
            Course::whereIn('code',$this->orgSelectedCourseCodes)->update(['college'=>$college]);
            $this->orgCourseAlert="✓ Saved '{$college}' with ".count($this->orgSelectedCourseCodes)." department(s)!";
            $this->orgCourseAlertType='success';
            $this->orgAddingToCollege=null;$this->orgSelectedCourseCodes=[];
            $this->loadOrgCourses();$this->coursesList=Course::all()->toArray();
        }catch(\Exception $e){$this->orgCourseAlert='Failed: '.$e->getMessage();$this->orgCourseAlertType='error';}
        finally{$this->savingOrgCourse=false;}
    }

    // Remove single course from college
    public function removeCourseFromCollege(int $id): void
    {
        try{Course::findOrFail($id)->update(['college'=>null]);$this->orgCourseAlert='✓ Department removed.';$this->orgCourseAlertType='success';$this->loadOrgCourses();$this->coursesList=Course::all()->toArray();}
        catch(\Exception $e){$this->orgCourseAlert='Failed.';$this->orgCourseAlertType='error';}
    }

    public function confirmDeleteCollege(string $college): void { $this->deleteOrgCollegeName=$college;$this->deleteOrgCourseName=$college;$this->activeModal='deleteOrgCollegeConfirm'; }

    public function deleteOrgCollege(): void
    {
        $this->deletingOrgCourse=true;
        try{
            Course::where('college',$this->deleteOrgCollegeName)->update(['college'=>null]);
            $this->orgCourseAlert="✓ College '{$this->deleteOrgCollegeName}' removed.";$this->orgCourseAlertType='success';
            $this->deleteOrgCollegeName=null;$this->deleteOrgCourseName='';
            $this->loadOrgCourses();$this->coursesList=Course::all()->toArray();$this->activeModal='manageOrgCourses';
        }catch(\Exception $e){$this->orgCourseAlert='Failed.';$this->orgCourseAlertType='error';$this->activeModal='manageOrgCourses';}
        finally{$this->deletingOrgCourse=false;}
    }

    // =====================================================
    // PROFILE
    // =====================================================

    public function toggleOrganizerStatus(int $id): void
    {
        try {
            $organizer = Organizer::findOrFail($id);
            $newStatus = $organizer->status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE';
            $organizer->update(['status' => $newStatus]);
            $verb = $newStatus === 'ACTIVE' ? 'activated' : 'deactivated';
            $this->flash('success', "{$organizer->name} has been {$verb}.");
        } catch (\Exception $e) {
            Log::error('Toggle status: '.$e->getMessage());
            $this->flash('error', 'Could not update status: '.$e->getMessage());
        }
    }

    public function viewProfile(int $id,string $type): void
    {
        try{$this->viewingProfileType=$type;$this->viewingProfile=$type==='alumni'?Alumni::findOrFail($id)->toArray():Organizer::findOrFail($id)->toArray();$this->viewingProfileId=$id;$this->activeModal='viewProfile';}
        catch(\Exception $e){$this->flash('error','Failed to load profile');}
    }

    public function updateProfilePhoto(): void
    {
        if(!$this->updatingProfilePhoto||!$this->viewingProfileId) return;
        $this->updatingProfile=true;
        try{
            if($this->viewingProfileType==='alumni'){
                $a=Alumni::findOrFail($this->viewingProfileId);
                if($a->profile_photo&&strpos($a->profile_photo,'default.png')===false) Storage::disk('public')->delete($a->profile_photo);
                $p=$this->storeAlumniPhoto($this->updatingProfilePhoto);$a->update(['profile_photo'=>$p]);$this->viewingProfile['profile_photo']=$p;
            }else{
                $o=Organizer::findOrFail($this->viewingProfileId);
                if($o->profile_photo&&strpos($o->profile_photo,'default.png')===false) Storage::disk('public')->delete($o->profile_photo);
                $p=$this->storeOrganizerPhoto($this->updatingProfilePhoto);$o->update(['profile_photo'=>$p]);$this->viewingProfile['profile_photo']=$p;
            }
            $this->updatingProfilePhoto=null;$this->flash('success','Photo updated!');
        }catch(\Exception $e){Log::error('Photo update:'.$e->getMessage());$this->flash('error','Failed to update photo');}
        finally{$this->updatingProfile=false;}
    }

    // =====================================================
    // IMPORT
    // =====================================================

    public function processImportFile(): void
    {
        $this->importingFile=true; $this->importStatus='Preparing...';
        $this->importProgress=0; $this->importSuccessCount=0; $this->importFailCount=0; $this->importErrors=[];
        try {
            if (!$this->importFile) throw new \Exception('No file selected');
            $ext = strtolower($this->importFile->getClientOriginalExtension());
            if ($ext==='xlsx'||$ext==='xls') $csv = $this->parseExcelFile($this->importFile->getRealPath());
            elseif ($ext==='csv') $csv = array_map('str_getcsv', file($this->importFile->getRealPath()));
            else throw new \Exception('File must be .csv or .xlsx/.xls');
            if (count($csv)<2) throw new \Exception('File is empty or has no data rows.');
            $header = array_map('trim', array_map('strtolower', $csv[0]));
            foreach (['name','student_id','course_code','year','email'] as $f)
                if (!in_array($f,$header)) throw new \Exception("Missing required column: \"{$f}\"");
            $this->importTotal = count($csv)-1;
            for ($i=1; $i<count($csv); $i++) {
                if (count(array_filter($csv[$i], fn($v)=>trim($v)!==''))===0) continue; // skip blank rows
                if (count($csv[$i])<count($header)) continue;
                $this->importProgress=$i;
                $row = array_combine($header, array_slice($csv[$i],0,count($header)));
                $name  = trim($row['name'] ?? '');
                $email = strtolower(trim($row['email'] ?? ''));
                $rawId = trim($row['student_id'] ?? '');
                $code  = strtoupper(trim($row['course_code'] ?? ''));
                $year  = trim($row['year'] ?? '');
                try {
                    // Validate name
                    if (!$name) { $this->importFailCount++; $this->importErrors[]="Row {$i} ({$name}): Name is empty."; continue; }
                    if (!preg_match('/^[a-zA-Z\s\-\.\']+$/',$name)) { $this->importFailCount++; $this->importErrors[]="Row {$i}: Name \"{$name}\" contains invalid characters."; continue; }
                    // Validate email format first
                    if (!filter_var($email,FILTER_VALIDATE_EMAIL)) { $this->importFailCount++; $this->importErrors[]="Row {$i} ({$name}): Email \"{$email}\" is not a valid email address."; continue; }
                    // Check duplicate email
                    if (Alumni::where('email',$email)->exists()) { $this->importFailCount++; $this->importErrors[]="Row {$i} ({$name}): Email \"{$email}\" is already registered as an alumni."; continue; }
                    if (User::where('email',$email)->exists())   { $this->importFailCount++; $this->importErrors[]="Row {$i} ({$name}): Email \"{$email}\" is already used by an existing account."; continue; }
                    // Validate student ID
                    if (!$rawId || !preg_match('/^\d{1,8}$/',$rawId)) { $this->importFailCount++; $this->importErrors[]="Row {$i} ({$name}): Student ID \"{$rawId}\" must be 1–8 digits."; continue; }
                    $sid = str_pad($rawId,8,'0',STR_PAD_LEFT);
                    if (Alumni::where('student_id',$sid)->exists()) { $this->importFailCount++; $this->importErrors[]="Row {$i} ({$name}): Student ID \"{$sid}\" is already registered."; continue; }
                    // Validate course
                    $course = Course::where('code',$code)->first();
                    if (!$course) { $this->importFailCount++; $this->importErrors[]="Row {$i} ({$name}): Course code \"{$code}\" does not exist. Add it via Manage Courses first."; continue; }
                    // Validate year
                    $batchYear = (int)$year;
                    if ($batchYear<2000||$batchYear>date('Y')) { $this->importFailCount++; $this->importErrors[]="Row {$i} ({$name}): Year \"{$year}\" is invalid (must be 2000–".date('Y').")."; continue; }
                    // Create records
                    $alumni = Alumni::create(['name'=>$name,'student_id'=>$sid,'email'=>$email,'course_code'=>$code,'course_name'=>$course->name,'batch'=>$batchYear,'status'=>'VERIFIED']);
                    $tmp = Str::random(10);
                    User::create(['name'=>$name,'email'=>$email,'password'=>Hash::make($tmp),'role'=>'alumni']);
                    try { Mail::to($email)->send(new \App\Mail\AlumniRegistered($alumni,$tmp)); } catch (\Exception $me) { Log::warning('Import email: '.$me->getMessage()); }
                    $this->importSuccessCount++;
                } catch (\Exception $e) {
                    $this->importFailCount++;
                    $this->importErrors[] = "Row {$i} ({$name}): ".$e->getMessage();
                }
            }
            $this->importStatus = 'Done!';
            $this->coursesList  = Course::all()->toArray();
            $this->importFile   = null;
            $this->activeModal  = '';
            $this->resetAlumniFilters();
            if ($this->importSuccessCount>0) {
                $msg = "Successfully imported {$this->importSuccessCount} alumni.";
                if ($this->importFailCount>0) $msg .= " {$this->importFailCount} row(s) were skipped — see details below.";
                $this->flash('success', $msg);
            } else {
                $this->flash('error', "No alumni were imported. All {$this->importFailCount} row(s) had errors — check the list below.");
            }
        } catch (\Exception $e) {
            Log::error('Import error: '.$e->getMessage());
            $this->importStatus  = 'Error: '.$e->getMessage();
            $this->importingFile = false;
        }
    }

    private function parseExcelFile($path): array
    {
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            $sheet = $reader->load($path)->getActiveSheet();
            $rows  = [];
            $highestRow = $sheet->getHighestDataRow();
            $highestCol = $sheet->getHighestDataColumn();
            foreach ($sheet->getRowIterator(1, $highestRow) as $row) {
                $rd = [];
                $ci = $row->getCellIterator('A', $highestCol);
                $ci->setIterateOnlyExistingCells(false);
                foreach ($ci as $cell) $rd[] = $cell->getValue();
                $rows[] = $rd;
            }
            return $rows;
        } catch (\Exception $e) {
            throw new \Exception('Excel parse failed: '.$e->getMessage());
        }
    }

    private function flash(string $type, string $msg): void
    {
        $this->dispatch('flash-message', type: $type, message: $msg);
    }

    public function getPhotoUrl(?string $path): string
    {
        if(!$path||strpos($path,'default.png')!==false) return asset('storage/alumni-photos/default.png');
        if(str_starts_with($path,'alumni-photos/')||str_starts_with($path,'organizers/'))
            return Storage::disk('public')->exists($path)?asset('storage/'.$path):asset('storage/alumni-photos/default.png');
        return asset('storage/alumni-photos/default.png');
    }
};
?>

<div class="flex flex-col bg-gradient-to-br from-slate-50 to-slate-100 overflow-hidden" style="height:90vh;">

<style>
    :root{--primary-color:#7a3f91;}
    *{scroll-behavior:smooth;}
    .scrollbar-custom::-webkit-scrollbar{width:6px;height:6px;}
    .scrollbar-custom::-webkit-scrollbar-track{background:transparent;}
    .scrollbar-custom::-webkit-scrollbar-thumb{background:rgba(122,63,145,.3);border-radius:10px;}
    .scrollbar-custom::-webkit-scrollbar-thumb:hover{background:rgba(122,63,145,.6);}
    @keyframes slideInDown{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:translateY(0)}}
    @keyframes modalSlideIn{from{opacity:0;transform:scale(.95) translateY(10px)}to{opacity:1;transform:scale(1) translateY(0)}}
    @keyframes progressPulse{0%,100%{box-shadow:0 0 0 0 rgba(122,63,145,.4)}50%{box-shadow:0 0 0 8px rgba(122,63,145,0)}}
    @keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}
    @keyframes slideInRight{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:translateX(0)}}
    .modal-animate{animation:modalSlideIn .3s cubic-bezier(.16,1,.3,1);}
    .flash-animate{animation:slideInRight .4s ease-out;}
    .progress-animate{animation:progressPulse 2s infinite;}
    .spin-icon{animation:spin 1s linear infinite;}
    .btn-primary{background:linear-gradient(135deg,#7a3f91,#6a3580);color:white;border:none;}
    .btn-primary:disabled{background:linear-gradient(135deg,#cbd5e1,#94a3b8);cursor:not-allowed;}
    .input-focus:focus{border-color:#7a3f91!important;box-shadow:0 0 0 3px rgba(122,63,145,.1)!important;outline:none!important;}
    .table-row-hover{transition:background-color .15s ease;}
    .table-row-hover:hover{background-color:rgba(122,63,145,.05);}
    .college-card{border-left:4px solid #7a3f91;}
    .course-check-row{transition:all .15s;}
    .course-check-row:hover{background:rgba(122,63,145,.07);}
    .course-check-row.is-selected{background:rgba(122,63,145,.12);border-color:#9b59c4!important;}
    .tbl-container{transition:opacity .2s ease;}
    .tbl-loading{opacity:.45;pointer-events:none;}
</style>

<!-- FLASH — pure Alpine, no Livewire re-render flicker -->
<div x-data="{
        show: false, type: 'success', msg: '', timer: null,
        display(t, m) {
            this.type = t; this.msg = m; this.show = true;
            clearTimeout(this.timer);
            this.timer = setTimeout(() => this.show = false, 5000);
        }
     }"
     @flash-message.window="display($event.detail.type, $event.detail.message)"
     x-show="show"
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 translate-x-6"
     x-transition:enter-end="opacity-100 translate-x-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="opacity-100 translate-x-0"
     x-transition:leave-end="opacity-0 translate-x-6"
     class="fixed top-5 right-6 z-50 flex items-start gap-3 px-6 py-4 rounded-lg shadow-xl max-w-sm border backdrop-blur-sm"
     :class="type==='success'?'bg-emerald-50 border-emerald-200 text-emerald-800':type==='info'?'bg-blue-50 border-blue-200 text-blue-800':'bg-red-50 border-red-200 text-red-800'"
     style="display:none">
    <i class="fas mt-0.5 text-lg flex-shrink-0"
       :class="type==='success'?'fa-check-circle text-emerald-500':type==='info'?'fa-info-circle text-blue-500':'fa-exclamation-circle text-red-500'"></i>
    <div class="flex-1 min-w-0">
        <div class="font-semibold text-sm" x-text="type==='success'?'Success':type==='info'?'Info':'Error'"></div>
        <div class="text-sm mt-0.5 leading-snug opacity-90" x-text="msg"></div>
    </div>
    <button @click="show=false" class="opacity-40 hover:opacity-100 shrink-0 transition"><i class="fas fa-times text-sm"></i></button>
</div>

<div class="flex flex-col flex-1 min-h-0 px-8 pt-7 pb-6">

    <!-- HEADER -->
    <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4 mb-5 shrink-0" style="animation:slideInDown .5s ease-out;">
        <div>
            <h1 class="text-4xl font-bold text-slate-800 flex items-center gap-3">
                <div class="w-14 h-14 btn-primary rounded-lg flex items-center justify-center shadow-md"><i class="fas fa-users text-xl"></i></div>
                Alumni & Organizers
            </h1>
            <p class="text-slate-600 text-sm mt-2 ml-0.5">Manage alumni and organizer records efficiently</p>
        </div>
        <div class="flex flex-wrap gap-2 shrink-0">
            @if($this->activeTab==='alumni')
                <button wire:click="openModal('registerAlumni')" class="inline-flex items-center gap-2 px-5 py-3 btn-primary rounded-lg font-semibold text-sm hover:shadow-lg transition-all"><i class="fas fa-user-plus"></i> Register Alumni</button>
                <button wire:click="openModal('importModal')" class="inline-flex items-center gap-2 px-5 py-3 bg-white text-slate-700 rounded-lg font-semibold hover:shadow-md transition text-sm border border-slate-200"><i class="fas fa-file-import"></i> Import</button>
                <button wire:click="openModal('manageCourses')" class="inline-flex items-center gap-2 px-5 py-3 bg-white text-slate-700 rounded-lg font-semibold hover:shadow-md transition text-sm border border-slate-200"><i class="fas fa-sliders"></i> Manage Courses</button>
            @elseif($this->activeTab==='organizers')
                <button wire:click="openModal('registerOrganizer')" class="inline-flex items-center gap-2 px-5 py-3 btn-primary rounded-lg font-semibold text-sm hover:shadow-lg transition-all"><i class="fas fa-users-gear"></i> Register Organizer</button>
                <button wire:click="openModal('manageOrgCourses')" class="inline-flex items-center gap-2 px-5 py-3 bg-white text-slate-700 rounded-lg font-semibold hover:shadow-md transition text-sm border border-slate-200"><i class="fas fa-building-columns"></i> Manage Colleges</button>
            @endif
        </div>
    </div>

    <!-- TABS -->
    <div class="flex gap-2 mb-4 shrink-0">
        <button wire:click="switchTab('alumni')" class="px-6 py-3 rounded-lg font-semibold transition flex items-center gap-2 text-sm {{ $this->activeTab==='alumni'?'bg-white text-slate-800 shadow-sm':'bg-white/50 text-slate-600 hover:bg-white/70' }}"><i class="fas fa-graduation-cap"></i> Alumni</button>
        <button wire:click="switchTab('organizers')" class="px-6 py-3 rounded-lg font-semibold transition flex items-center gap-2 text-sm {{ $this->activeTab==='organizers'?'bg-white text-slate-800 shadow-sm':'bg-white/50 text-slate-600 hover:bg-white/70' }}"><i class="fas fa-users-gear"></i> Organizers</button>
    </div>

    <!-- TABLE PANEL -->
    <div class="flex-1 min-h-0 bg-white rounded-lg shadow-sm flex flex-col overflow-hidden">

        {{-- ALUMNI TAB --}}
        @if($this->activeTab==='alumni')
        <div class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex flex-wrap gap-3 items-center shrink-0">
            <div class="relative flex-1 min-w-[200px] max-w-sm"
     wire:ignore
     x-data="searchBox('alumniSearch')"
     x-init="init()"
     @reset-alumni-search.window="val=''; $wire.set('alumniSearch','')">
    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm pointer-events-none"></i>
    <input type="text"
           x-model="val"
           @input="onInput()"
           placeholder="Search name, ID, email…"
           class="w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus"
           autocomplete="off" spellcheck="false">
</div>
            <select wire:model.live="alumniBatch" class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus"><option value="">All Years</option>@foreach($this->batches as $b)<option value="{{ $b }}">{{ $b }}</option>@endforeach</select>
            <select wire:model.live="alumniCourse" class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus"><option value="">All Courses</option>@foreach($this->courses as $c)<option value="{{ $c->code }}">{{ $c->code }}</option>@endforeach</select>
            <select wire:model.live="alumniSort" class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus"><option value="recent">Recent First</option><option value="oldest">Oldest First</option></select>
            <button @click="$dispatch('reset-alumni-search'); $wire.resetAlumniFilters()" class="px-4 py-2.5 text-slate-700 hover:bg-slate-100 rounded-lg border border-slate-200 transition text-sm font-medium"><i class="fas fa-rotate-left mr-2"></i>Reset</button>
        </div>
        <div class="flex-1 overflow-auto scrollbar-custom tbl-container"
     wire:loading.class="tbl-loading"
     wire:target="alumniSearch,alumniBatch,alumniCourse,alumniSort,resetAlumniFilters">
            <table class="w-full"><thead class="btn-primary text-white sticky top-0 z-10"><tr>
                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Name</th>
                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Student ID</th>
                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Course</th>
                <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wide">Year</th>
                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Email</th>
                <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wide">Status</th>
                <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wide">Action</th>
            </tr></thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($this->alumniRecords as $item)
                <tr class="table-row-hover">
                    <td class="px-6 py-4"><div class="flex items-center gap-3"><img src="{{ $this->getPhotoUrl($item->profile_photo) }}" alt="{{ $item->name }}" class="w-10 h-10 rounded-lg object-cover shrink-0"><span class="font-semibold text-slate-900 text-sm">{{ $item->name }}</span></div></td>
                    <td class="px-6 py-4"><span class="font-mono text-slate-800 text-sm font-semibold">{{ $item->student_id }}</span></td>
                    <td class="px-6 py-4"><span class="inline-block px-3 py-1.5 bg-purple-50 text-purple-700 rounded-full text-xs font-semibold">{{ $item->course_code }}</span></td>
                    <td class="px-6 py-4 text-center"><span class="font-mono text-slate-800 text-sm font-semibold">{{ $item->batch }}</span></td>
                    <td class="px-6 py-4"><span class="text-slate-700 text-sm">{{ $item->email }}</span></td>
                    <td class="px-6 py-4 text-center">
                        @php $sc=match($item->status){'VERIFIED'=>'bg-emerald-100 text-emerald-700','PENDING'=>'bg-amber-100 text-amber-700','REJECTED'=>'bg-red-100 text-red-700',default=>'bg-slate-100 text-slate-600'}; @endphp
                        <span class="inline-block px-3 py-1.5 rounded-full text-xs font-semibold {{ $sc }}">{{ $item->status }}</span>
                    </td>
                    <td class="px-6 py-4 text-center"><button wire:click="viewProfile({{ $item->id }},'alumni')" class="inline-flex items-center gap-2 px-3 py-2 text-xs font-semibold text-purple-700 hover:bg-purple-50 rounded-lg transition border border-purple-200"><i class="fas fa-eye"></i> View</button></td>
                </tr>
                @empty
                <tr><td colspan="7" class="py-16 text-center"><i class="fas fa-users text-5xl text-slate-200 block mb-4"></i><p class="font-semibold text-slate-400">No alumni found</p><p class="text-sm text-slate-400 mt-1">Try adjusting filters</p></td></tr>
                @endforelse
            </tbody></table>
        </div>
        <div class="px-6 py-4 border-t border-slate-200 bg-slate-50 shrink-0">
            <div class="flex items-center justify-between">
                @php $total=$this->alumniRecords->total();$pp=$this->alumniRecords->perPage();$cp=$this->alumniRecords->currentPage();$from=$total>0?($cp-1)*$pp+1:0;$to=min($cp*$pp,$total); @endphp
                <p class="text-slate-600 text-sm">Showing <span class="font-semibold text-slate-800">{{ $from }}–{{ $to }}</span> of <span class="font-semibold text-slate-800">{{ $total }}</span></p>
                <div class="flex gap-2 items-center">
                    @if($this->alumniRecords->onFirstPage())<button disabled class="px-4 py-2 bg-slate-200 text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">← Prev</button>
                    @else<button wire:click="previousPage('alumniPage')" class="px-4 py-2 btn-primary rounded-lg text-sm font-medium">← Prev</button>@endif
                    <span class="px-4 py-2 text-slate-700 text-sm font-medium">{{ $this->alumniRecords->currentPage() }} / {{ $this->alumniRecords->lastPage() }}</span>
                    @if($this->alumniRecords->hasMorePages())<button wire:click="nextPage('alumniPage')" class="px-4 py-2 btn-primary rounded-lg text-sm font-medium">Next →</button>
                    @else<button disabled class="px-4 py-2 bg-slate-200 text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">Next →</button>@endif
                </div>
            </div>
        </div>
        @endif

        {{-- ORGANIZERS TAB --}}
        @if($this->activeTab==='organizers')
        <div class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex flex-wrap gap-3 items-center shrink-0">
            <div class="relative flex-1 min-w-[200px] max-w-sm"
     wire:ignore
     x-data="searchBox('orgSearch')"
     x-init="init()"
     @reset-org-search.window="val=''; $wire.set('orgSearch','')">
    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm pointer-events-none"></i>
    <input type="text"
           x-model="val"
           @input="onInput()"
           placeholder="Search name, ID, email…"
           class="w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus"
           autocomplete="off" spellcheck="false">
</div>
            <select wire:model.live="orgCollege" class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
                <option value="">All Colleges</option>
                @foreach($this->orgColleges as $col)<option value="{{ $col }}">{{ $col }}</option>@endforeach
            </select>
            <select wire:model.live="orgSort" class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus"><option value="recent">Recent First</option><option value="oldest">Oldest First</option></select>
            <button @click="$dispatch('reset-org-search'); $wire.resetOrgFilters()" class="px-4 py-2.5 text-slate-700 hover:bg-slate-100 rounded-lg border border-slate-200 transition text-sm font-medium"><i class="fas fa-rotate-left mr-2"></i>Reset</button>
        </div>
        <div class="flex-1 overflow-auto scrollbar-custom tbl-container"
     wire:loading.class="tbl-loading"
     wire:target="orgSearch,orgCollege,orgSort,resetOrgFilters,toggleOrganizerStatus">
            <table class="w-full"><thead class="btn-primary text-white sticky top-0 z-10"><tr>
                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Name</th>
                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Teacher ID</th>
                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Email</th>
                <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">College</th>
                <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wide">Status</th>
                <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wide">Action</th>
            </tr></thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($this->organizerRecords as $item)
                @php $collegeName=$this->getCollegeForCourse($item->department); @endphp
                <tr class="table-row-hover">
                    <td class="px-6 py-4"><div class="flex items-center gap-3"><img src="{{ $this->getPhotoUrl($item->profile_photo) }}" alt="{{ $item->name }}" class="w-10 h-10 rounded-lg object-cover shrink-0"><span class="font-semibold text-slate-900 text-sm">{{ $item->name }}</span></div></td>
                    <td class="px-6 py-4"><span class="font-mono text-slate-800 text-sm font-semibold">{{ $item->id_number }}</span></td>
                    <td class="px-6 py-4"><span class="text-slate-700 text-sm">{{ $item->email }}</span></td>
                    <td class="px-6 py-4">
                        <span class="block font-semibold text-slate-800 text-sm leading-snug">{{ $collegeName }}</span>
                        <div class="flex flex-wrap gap-1 mt-1">
                            @foreach($this->getCollegeDepts($item->department) as $deptCode)
                                <span class="px-2 py-0.5 rounded text-xs font-mono {{ $deptCode===$item->department ? 'bg-purple-600 text-white' : 'bg-purple-50 text-purple-600' }}">{{ $deptCode }}</span>
                            @endforeach
                        </div>
                    </td>
                    <td class="px-6 py-4 text-center">
                        @php $sc=match($item->status){'ACTIVE'=>'bg-emerald-100 text-emerald-700','INACTIVE'=>'bg-amber-100 text-amber-700','SUSPENDED'=>'bg-red-100 text-red-700',default=>'bg-slate-100 text-slate-600'}; @endphp
                        <span class="inline-block px-3 py-1.5 rounded-full text-xs font-semibold {{ $sc }}">{{ $item->status }}</span>
                    </td>
                    <td class="px-6 py-4 text-center">
                        <div class="flex items-center justify-center gap-2"
                             x-data="{ confirmId: null }"
                             @keydown.escape.window="confirmId=null">
                            <button wire:click="viewProfile({{ $item->id }},'organizer')"
                                    class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-purple-700 hover:bg-purple-50 rounded-lg transition border border-purple-200">
                                <i class="fas fa-eye"></i> View
                            </button>
                            @if($item->status==='ACTIVE')
                                <div x-data="{ open: false }">
                                    <button @click="open=true"
                                            class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-red-600 hover:bg-red-50 rounded-lg transition border border-red-200">
                                        <i class="fas fa-ban"></i> Deactivate
                                    </button>
                                    <div x-show="open" x-transition
                                         class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4"
                                         style="display:none" @click.self="open=false">
                                        <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm p-6" @click.stop>
                                            <div class="flex items-center gap-3 mb-4">
                                                <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center shrink-0">
                                                    <i class="fas fa-ban text-red-600"></i>
                                                </div>
                                                <div>
                                                    <p class="font-bold text-slate-800">Deactivate Organizer?</p>
                                                    <p class="text-xs text-slate-500 mt-0.5">{{ $item->name }}</p>
                                                </div>
                                            </div>
                                            <p class="text-sm text-slate-600 mb-5">This organizer will no longer be able to log in. You can reactivate them at any time.</p>
                                            <div class="flex gap-3">
                                                <button @click="open=false" class="flex-1 px-4 py-2 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">Cancel</button>
                                                <button @click="open=false; $wire.toggleOrganizerStatus({{ $item->id }})"
                                                        class="flex-1 px-4 py-2 bg-red-600 text-white rounded-lg text-sm font-semibold hover:bg-red-700 transition">
                                                    Yes, Deactivate
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @else
                                <div x-data="{ open: false }">
                                    <button @click="open=true"
                                            class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-emerald-600 hover:bg-emerald-50 rounded-lg transition border border-emerald-200">
                                        <i class="fas fa-circle-check"></i> Activate
                                    </button>
                                    <div x-show="open" x-transition
                                         class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4"
                                         style="display:none" @click.self="open=false">
                                        <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm p-6" @click.stop>
                                            <div class="flex items-center gap-3 mb-4">
                                                <div class="w-10 h-10 bg-emerald-100 rounded-full flex items-center justify-center shrink-0">
                                                    <i class="fas fa-circle-check text-emerald-600"></i>
                                                </div>
                                                <div>
                                                    <p class="font-bold text-slate-800">Activate Organizer?</p>
                                                    <p class="text-xs text-slate-500 mt-0.5">{{ $item->name }}</p>
                                                </div>
                                            </div>
                                            <p class="text-sm text-slate-600 mb-5">This organizer will be able to log in again.</p>
                                            <div class="flex gap-3">
                                                <button @click="open=false" class="flex-1 px-4 py-2 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">Cancel</button>
                                                <button @click="open=false; $wire.toggleOrganizerStatus({{ $item->id }})"
                                                        class="flex-1 px-4 py-2 bg-emerald-600 text-white rounded-lg text-sm font-semibold hover:bg-emerald-700 transition">
                                                    Yes, Activate
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="6" class="py-16 text-center"><i class="fas fa-users-gear text-5xl text-slate-200 block mb-4"></i><p class="font-semibold text-slate-400">No organizers found</p><p class="text-sm text-slate-400 mt-1">Register an organizer to get started</p></td></tr>
                @endforelse
            </tbody></table>
        </div>
        <div class="px-6 py-4 border-t border-slate-200 bg-slate-50 shrink-0">
            <div class="flex items-center justify-between">
                @php $total=$this->organizerRecords->total();$pp=$this->organizerRecords->perPage();$cp=$this->organizerRecords->currentPage();$from=$total>0?($cp-1)*$pp+1:0;$to=min($cp*$pp,$total); @endphp
                <p class="text-slate-600 text-sm">Showing <span class="font-semibold text-slate-800">{{ $from }}–{{ $to }}</span> of <span class="font-semibold text-slate-800">{{ $total }}</span></p>
                <div class="flex gap-2 items-center">
                    @if($this->organizerRecords->onFirstPage())<button disabled class="px-4 py-2 bg-slate-200 text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">← Prev</button>
                    @else<button wire:click="previousPage('orgPage')" class="px-4 py-2 btn-primary rounded-lg text-sm font-medium">← Prev</button>@endif
                    <span class="px-4 py-2 text-slate-700 text-sm font-medium">{{ $this->organizerRecords->currentPage() }} / {{ $this->organizerRecords->lastPage() }}</span>
                    @if($this->organizerRecords->hasMorePages())<button wire:click="nextPage('orgPage')" class="px-4 py-2 btn-primary rounded-lg text-sm font-medium">Next →</button>
                    @else<button disabled class="px-4 py-2 bg-slate-200 text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">Next →</button>@endif
                </div>
            </div>
        </div>
        @endif

    </div><!-- end TABLE PANEL -->

    <!-- ===== MODALS ===== -->

    <!-- REGISTER ALUMNI -->
    @if($activeModal==='registerAlumni')
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.closeModal()">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[92vh] overflow-y-auto scrollbar-custom modal-animate">
            <div class="flex items-center justify-between px-8 py-6 btn-primary text-white rounded-t-lg sticky top-0 z-10">
                <h2 class="text-2xl font-bold flex items-center gap-3"><i class="fas fa-user-plus text-2xl"></i> Register Alumni</h2>
                <button wire:click="closeModal" class="text-3xl leading-none hover:opacity-70 transition">×</button>
            </div>
            @if(count($alumniErrors)>0)
            <div class="bg-red-50 border-b border-red-200 px-8 py-5">
                <p class="font-semibold text-red-800 text-sm mb-3">⚠️ Please fix the following errors:</p>
                <ul class="text-red-700 text-sm space-y-2">@foreach($alumniErrors as $ms)@foreach($ms as $m)<li class="flex items-start gap-2"><span class="text-red-500 mt-0.5">•</span><span>{{ $m }}</span></li>@endforeach@endforeach</ul>
            </div>
            @endif
            <form wire:submit="registerAlumni" class="p-8 space-y-6">
                <div>
                    <label class="block text-sm font-bold text-slate-800 mb-3">Profile Photo <span class="font-normal text-slate-500">(Optional)</span></label>
                    <div class="border-2 border-dashed border-slate-300 rounded-lg p-8 text-center cursor-pointer hover:border-purple-400 hover:bg-purple-50 transition" onclick="document.getElementById('regPhotoInput').click()">
                        @if($regPhoto)<img src="{{ $regPhoto->temporaryUrl() }}" alt="Preview" class="w-32 h-32 rounded-lg mx-auto mb-4 object-cover shadow-md"><p class="text-sm text-emerald-600 font-semibold">✓ Photo Selected</p>
                        @else<i class="fas fa-cloud-arrow-up text-4xl text-slate-400 block mb-3"></i><p class="text-sm text-slate-700 font-semibold">Click to Upload Photo</p><p class="text-xs text-slate-600 mt-2">JPG, PNG, WebP · max 5 MB</p>@endif
                        <input type="file" id="regPhotoInput" wire:model="regPhoto" accept="image/*" class="hidden">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-800 mb-3">Full Name <span class="text-red-500">*</span></label>
                    <div class="grid grid-cols-2 gap-4">
                        <div><input wire:model="regFirstName" type="text" placeholder="First Name" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800"><p class="text-xs text-slate-500 mt-1.5 pl-1">First Name <span class="text-red-400">*</span></p></div>
                        <div><input wire:model="regLastName" type="text" placeholder="Last Name" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800"><p class="text-xs text-slate-500 mt-1.5 pl-1">Last Name <span class="text-red-400">*</span></p></div>
                    </div>
                    <div class="grid grid-cols-2 gap-4 mt-3">
                        <div><input wire:model="regMiddleInitial" type="text" placeholder="Middle Initial" maxlength="5" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800"><p class="text-xs text-slate-500 mt-1.5 pl-1">Middle Initial</p></div>
                        <div><input wire:model="regSuffix" type="text" placeholder="Suffix (Jr., Sr.)" maxlength="10" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800"><p class="text-xs text-slate-500 mt-1.5 pl-1">Suffix</p></div>
                    </div>
                    @if($regFirstName||$regLastName)<p class="text-sm text-purple-700 font-semibold mt-3 pl-1">Preview: {{ trim("{$regFirstName} {$regMiddleInitial} {$regLastName}".($regSuffix?' '.$regSuffix:'')) }}</p>@endif
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-sm font-bold text-slate-800 mb-3">Student ID <span class="text-red-500">*</span></label><div class="relative"><input wire:model="regStudentId" type="text" placeholder="e.g. 12345" maxlength="8" inputmode="numeric" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm font-mono input-focus text-slate-800">@if($regStudentId&&strlen($regStudentId)<8)<span class="absolute right-4 top-1/2 -translate-y-1/2 text-xs text-slate-500 font-mono">→ {{ str_pad($regStudentId,8,'0',STR_PAD_LEFT) }}</span>@endif</div></div>
                    <div><label class="block text-sm font-bold text-slate-800 mb-3">Email <span class="text-red-500">*</span></label><input wire:model="regEmail" type="email" placeholder="student@example.com" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800"></div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-sm font-bold text-slate-800 mb-3">Course <span class="text-red-500">*</span></label><select wire:model="regCourseCode" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800"><option value="">Select Course</option>@foreach($this->courses as $c)<option value="{{ $c->code }}">{{ $c->code }}</option>@endforeach</select></div>
                    <div><label class="block text-sm font-bold text-slate-800 mb-3">Year <span class="text-red-500">*</span></label><input wire:model="regYear" type="number" placeholder="{{ date('Y') }}" min="2000" max="{{ date('Y') }}" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800"></div>
                </div>
                <div class="flex gap-4 pt-3">
                    <button type="button" wire:click="closeModal" class="flex-1 px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">Cancel</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="registerAlumni" class="flex-1 px-6 py-2.5 btn-primary rounded-lg text-sm font-semibold flex items-center justify-center gap-2">
                        <span wire:loading wire:target="registerAlumni"><i class="fas fa-spinner spin-icon"></i> Registering...</span>
                        <span wire:loading.remove wire:target="registerAlumni"><i class="fas fa-user-check"></i> Register Alumni</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    @endif

    <!-- REGISTER ORGANIZER -->
    @if($activeModal==='registerOrganizer')
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.closeModal()">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[92vh] overflow-y-auto scrollbar-custom modal-animate">
            <div class="flex items-center justify-between px-8 py-6 btn-primary text-white rounded-t-lg sticky top-0 z-10">
                <h2 class="text-2xl font-bold flex items-center gap-3"><i class="fas fa-users-gear text-2xl"></i> Register Organizer</h2>
                <button wire:click="closeModal" class="text-3xl leading-none hover:opacity-70 transition">×</button>
            </div>
            @if(count($organizerErrors)>0)
            <div class="bg-red-50 border-b border-red-200 px-8 py-5">
                <p class="font-semibold text-red-800 text-sm mb-3">⚠️ Please fix the following errors:</p>
                <ul class="text-red-700 text-sm space-y-2">@foreach($organizerErrors as $ms)@foreach($ms as $m)<li class="flex items-start gap-2"><span class="text-red-500 mt-0.5">•</span><span>{{ $m }}</span></li>@endforeach@endforeach</ul>
            </div>
            @endif
            <form wire:submit="registerOrganizer" class="p-8 space-y-6">
                <div>
                    <label class="block text-sm font-bold text-slate-800 mb-3">Profile Photo <span class="font-normal text-slate-500">(Optional)</span></label>
                    <div class="border-2 border-dashed border-slate-300 rounded-lg p-8 text-center cursor-pointer hover:border-purple-400 hover:bg-purple-50 transition" onclick="document.getElementById('orgPhotoInput').click()">
                        @if($orgPhoto)<img src="{{ $orgPhoto->temporaryUrl() }}" alt="Preview" class="w-32 h-32 rounded-lg mx-auto mb-4 object-cover shadow-md"><p class="text-sm text-emerald-600 font-semibold">✓ Photo Selected</p>
                        @else<i class="fas fa-cloud-arrow-up text-4xl text-slate-400 block mb-3"></i><p class="text-sm text-slate-700 font-semibold">Click to Upload Photo</p><p class="text-xs text-slate-600 mt-2">JPG, PNG, WebP · max 5 MB</p>@endif
                        <input type="file" id="orgPhotoInput" wire:model="orgPhoto" accept="image/*" class="hidden">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-800 mb-3">Full Name <span class="text-red-500">*</span></label>
                    <div class="grid grid-cols-2 gap-4">
                        <div><input wire:model="orgFirstName" type="text" placeholder="First Name" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800"><p class="text-xs text-slate-500 mt-1.5 pl-1">First Name <span class="text-red-400">*</span></p></div>
                        <div><input wire:model="orgLastName" type="text" placeholder="Last Name" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800"><p class="text-xs text-slate-500 mt-1.5 pl-1">Last Name <span class="text-red-400">*</span></p></div>
                    </div>
                    <div class="grid grid-cols-2 gap-4 mt-3">
                        <div><input wire:model="orgMiddleInitial" type="text" placeholder="Middle Initial" maxlength="5" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800"><p class="text-xs text-slate-500 mt-1.5 pl-1">Middle Initial</p></div>
                        <div><input wire:model="orgSuffix" type="text" placeholder="Suffix (Jr., Sr.)" maxlength="10" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800"><p class="text-xs text-slate-500 mt-1.5 pl-1">Suffix</p></div>
                    </div>
                    @if($orgFirstName||$orgLastName)<p class="text-sm text-purple-700 font-semibold mt-3 pl-1">Preview: {{ trim("{$orgFirstName} {$orgMiddleInitial} {$orgLastName}".($orgSuffix?' '.$orgSuffix:'')) }}</p>@endif
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-sm font-bold text-slate-800 mb-3">Teacher ID <span class="text-red-500">*</span></label><div class="relative"><input wire:model="orgTeacherId" type="text" placeholder="e.g. 12345" maxlength="8" inputmode="numeric" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm font-mono input-focus text-slate-800">@if($orgTeacherId&&strlen($orgTeacherId)<8)<span class="absolute right-4 top-1/2 -translate-y-1/2 text-xs text-slate-500 font-mono">→ {{ str_pad($orgTeacherId,8,'0',STR_PAD_LEFT) }}</span>@endif</div></div>
                    <div><label class="block text-sm font-bold text-slate-800 mb-3">Email <span class="text-red-500">*</span></label><input wire:model="orgEmail" type="email" placeholder="teacher@example.com" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800"></div>
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-800 mb-3">Department <span class="text-red-500">*</span></label>
                    <select wire:model="orgDept" class="w-full px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800">
                        <option value="">Select Department</option>
                        @foreach($this->orgDepartmentsGrouped as $college=>$depts)
                            <optgroup label="🏫 {{ $college }}">@foreach($depts as $d)<option value="{{ $d->code }}">{{ $d->code }} — {{ $d->name }}</option>@endforeach</optgroup>
                        @endforeach
                        @if($this->unassignedCourses->count()>0)
                            <optgroup label="— Other Courses —">@foreach($this->unassignedCourses as $d)<option value="{{ $d->code }}">{{ $d->code }} — {{ $d->name }}</option>@endforeach</optgroup>
                        @endif
                    </select>
                </div>
                <div class="flex gap-4 pt-3">
                    <button type="button" wire:click="closeModal" class="flex-1 px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">Cancel</button>
                    <button type="submit" wire:loading.attr="disabled" wire:target="registerOrganizer" class="flex-1 px-6 py-2.5 btn-primary rounded-lg text-sm font-semibold flex items-center justify-center gap-2">
                        <span wire:loading wire:target="registerOrganizer"><i class="fas fa-spinner spin-icon"></i> Registering...</span>
                        <span wire:loading.remove wire:target="registerOrganizer"><i class="fas fa-users-gear"></i> Register Organizer</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
    @endif

    <!-- IMPORT -->
    @if($activeModal==='importModal')
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.cancelImport()">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl modal-animate">
            <div class="flex items-center justify-between px-8 py-6 btn-primary text-white rounded-t-lg">
                <h2 class="text-2xl font-bold flex items-center gap-3"><i class="fas fa-file-import text-2xl"></i> Import Alumni</h2>
                <button wire:click="cancelImport" class="text-3xl leading-none hover:opacity-70 transition">×</button>
            </div>
            <div class="p-8 space-y-5">
                @if(!$importingFile)
                    {{-- Upload step --}}
                    <div class="bg-blue-50 border-l-4 border-blue-400 p-4 rounded-lg text-sm">
                        <p class="text-blue-800 font-semibold mb-1">📋 Supported formats: CSV · Excel (.xlsx, .xls)</p>
                        <p class="text-blue-700 text-xs">Required columns: <code class="bg-blue-100 px-1 rounded">name</code> <code class="bg-blue-100 px-1 rounded">student_id</code> <code class="bg-blue-100 px-1 rounded">course_code</code> <code class="bg-blue-100 px-1 rounded">year</code> <code class="bg-blue-100 px-1 rounded">email</code></p>
                    </div>
                    <div class="border-2 border-dashed border-slate-300 rounded-xl p-8 text-center cursor-pointer hover:border-purple-400 hover:bg-purple-50 transition-all"
                         @click="document.getElementById('importFile').click()">
                        @if($importFile)
                            <i class="fas fa-file-circle-check text-5xl text-emerald-500 block mb-3"></i>
                            <p class="text-sm text-emerald-700 font-semibold">{{ $importFile->getClientOriginalName() }}</p>
                            <p class="text-xs text-slate-500 mt-1">Click to change file</p>
                        @else
                            <i class="fas fa-file-arrow-up text-5xl text-slate-300 block mb-3"></i>
                            <p class="text-slate-700 font-semibold text-sm">Click to choose file</p>
                            <p class="text-xs text-slate-400 mt-1">CSV or Excel format</p>
                        @endif
                        <input type="file" id="importFile" wire:model="importFile" accept=".csv,.xlsx,.xls" class="hidden">
                    </div>
                    <div class="flex gap-3">
                        <button type="button" wire:click="cancelImport"
                                class="flex-1 px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">
                            Cancel
                        </button>
                        <button type="button" wire:click="processImportFile" @if(!$importFile) disabled @endif
                                wire:loading.attr="disabled" wire:target="processImportFile"
                                class="flex-1 px-6 py-2.5 btn-primary rounded-lg text-sm font-semibold disabled:opacity-40 disabled:cursor-not-allowed flex items-center justify-center gap-2">
                            <span wire:loading wire:target="processImportFile"><i class="fas fa-spinner spin-icon"></i> Processing…</span>
                            <span wire:loading.remove wire:target="processImportFile"><i class="fas fa-upload"></i> Import Now</span>
                        </button>
                    </div>
                @else
                    {{-- Progress / results step --}}
                    @php $isDone = $importStatus === 'Done!'; @endphp
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            @if($isDone)
                                <p class="text-slate-800 font-semibold text-sm flex items-center gap-2">
                                    <i class="fas fa-circle-check text-emerald-500"></i> Import complete
                                </p>
                            @else
                                <p class="text-slate-800 font-semibold text-sm flex items-center gap-2">
                                    <i class="fas fa-spinner spin-icon text-purple-600"></i> Importing… {{ $importProgress }}/{{ $importTotal }}
                                </p>
                            @endif
                            <span class="text-xs text-slate-500 font-mono">{{ $importTotal>0?round(($importProgress/$importTotal)*100):0 }}%</span>
                        </div>
                        <div class="w-full bg-slate-200 rounded-full h-2.5 overflow-hidden">
                            <div class="h-full rounded-full transition-all duration-500 {{ $isDone&&$importFailCount===0 ? 'bg-emerald-500' : ($isDone ? 'bg-amber-500' : 'bg-purple-600') }}"
                                 style="width:{{ $importTotal>0?round(($importProgress/$importTotal)*100):0 }}%"></div>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="bg-emerald-50 rounded-xl p-4 border border-emerald-200 text-center">
                            <p class="text-emerald-600 text-2xl font-bold">{{ $importSuccessCount }}</p>
                            <p class="text-emerald-700 text-xs font-semibold mt-1 uppercase tracking-wide">Imported</p>
                        </div>
                        <div class="bg-red-50 rounded-xl p-4 border border-red-200 text-center">
                            <p class="text-red-600 text-2xl font-bold">{{ $importFailCount }}</p>
                            <p class="text-red-700 text-xs font-semibold mt-1 uppercase tracking-wide">Skipped</p>
                        </div>
                    </div>

                    @if(count($importErrors)>0)
                    <div class="bg-red-50 rounded-xl border border-red-200 overflow-hidden">
                        <div class="px-4 py-3 bg-red-100 border-b border-red-200 flex items-center gap-2">
                            <i class="fas fa-triangle-exclamation text-red-600 text-sm"></i>
                            <p class="text-red-800 font-semibold text-sm">{{ count($importErrors) }} row(s) were skipped</p>
                        </div>
                        <ul class="divide-y divide-red-100 max-h-56 overflow-y-auto scrollbar-custom">
                            @foreach($importErrors as $err)
                            <li class="px-4 py-2.5 text-xs text-red-700 flex items-start gap-2">
                                <i class="fas fa-circle-xmark text-red-400 mt-0.5 shrink-0"></i>
                                <span>{{ $err }}</span>
                            </li>
                            @endforeach
                        </ul>
                    </div>
                    @endif

                    <button type="button" wire:click="cancelImport"
                            class="w-full px-6 py-2.5 {{ $isDone ? 'btn-primary' : 'border border-slate-300 text-slate-700' }} rounded-lg text-sm font-semibold transition">
                        {{ $isDone ? 'Done' : 'Close' }}
                    </button>
                @endif
            </div>
        </div>
    </div>
    @endif

    <!-- MANAGE ALUMNI COURSES -->
    @if($activeModal==='manageCourses')
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.closeModal()">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[92vh] overflow-hidden flex flex-col modal-animate">
            <div class="flex items-center justify-between px-8 py-6 btn-primary text-white rounded-t-lg"><h2 class="text-2xl font-bold flex items-center gap-3"><i class="fas fa-sliders text-2xl"></i> Manage Courses</h2><button wire:click="closeModal" class="text-3xl leading-none hover:opacity-70 transition">×</button></div>
            @if($courseAlert)<div x-data="{s:true}" x-effect="if(s){setTimeout(()=>{s=false},4000)}" x-show="s" :class="'{{ $courseAlertType }}'==='success'?'bg-emerald-50 border-l-4 border-emerald-400':'bg-red-50 border-l-4 border-red-400'" class="p-4 mx-8 mt-6 rounded-lg"><p :class="'{{ $courseAlertType }}'==='success'?'text-emerald-800':'text-red-800'" class="text-sm font-semibold"><i :class="'{{ $courseAlertType }}'==='success'?'fas fa-check-circle':'fas fa-exclamation-circle'" class="mr-2"></i>{{ $courseAlert }}</p></div>@endif
            <div class="flex-1 overflow-y-auto scrollbar-custom px-8 py-6 space-y-6">
                <div class="border border-slate-200 rounded-lg p-6 bg-slate-50">
                    <h3 class="text-base font-bold text-slate-800 mb-4">{{ $editingCourseId?'✏️ Edit Course':'➕ Add New Course' }}</h3>
                    <div class="space-y-4">
                        <div><label class="block text-xs font-bold text-slate-700 mb-2">Course Code</label><input wire:model="courseCode" type="text" placeholder="e.g. CS101" maxlength="20" class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm input-focus text-slate-800"></div>
                        <div><label class="block text-xs font-bold text-slate-700 mb-2">Course Name</label><input wire:model="courseName" type="text" placeholder="e.g. Computer Science" maxlength="100" class="w-full px-4 py-2 border border-slate-300 rounded-lg text-sm input-focus text-slate-800"></div>
                        <div class="flex gap-3 pt-2">
                            @if($editingCourseId)<button type="button" wire:click="resetCourseForm" class="flex-1 px-4 py-2 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-100 transition">Cancel</button>@endif
                            <button type="button" wire:click="saveCourse" wire:loading.attr="disabled" wire:target="saveCourse" class="flex-1 px-4 py-2 btn-primary rounded-lg text-sm font-semibold flex items-center justify-center gap-2"><span wire:loading wire:target="saveCourse"><i class="fas fa-spinner spin-icon"></i></span><span wire:loading.remove wire:target="saveCourse">{{ $editingCourseId?'Update':'Add Course' }}</span></button>
                        </div>
                    </div>
                </div>
                <div>
                    <h3 class="text-base font-bold text-slate-800 mb-4">📚 Courses ({{ count($coursesList) }})</h3>
                    <div class="space-y-2 max-h-64 overflow-y-auto scrollbar-custom pr-2">
                        @forelse($coursesList as $c)
                        <div class="flex items-center justify-between p-4 border border-slate-200 rounded-lg bg-white">
                            <div class="flex-1"><p class="font-semibold text-slate-800 text-sm">{{ $c['code'] }}</p><p class="text-slate-600 text-xs mt-1">{{ $c['name'] }}</p></div>
                            <div class="flex gap-2 ml-4"><button wire:click="openEditCourse({{ $c['id'] }})" class="px-3 py-1.5 bg-blue-50 text-blue-600 rounded-lg hover:bg-blue-100 transition font-semibold text-xs border border-blue-200"><i class="fas fa-pencil"></i> Edit</button><button wire:click="confirmDeleteCourse({{ $c['id'] }})" class="px-3 py-1.5 bg-red-50 text-red-600 rounded-lg hover:bg-red-100 transition font-semibold text-xs border border-red-200"><i class="fas fa-trash"></i></button></div>
                        </div>
                        @empty<p class="text-center text-slate-500 py-8 text-sm">No courses yet.</p>@endforelse
                    </div>
                </div>
            </div>
            <div class="px-8 py-4 border-t border-slate-200 bg-slate-50"><button wire:click="closeModal" class="w-full px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-100 transition">Close</button></div>
        </div>
    </div>
    @endif

    <!-- DELETE ALUMNI COURSE CONFIRM -->
    @if($activeModal==='deleteCourseConfirm')
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.closeModal()">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-sm modal-animate">
            <div class="px-8 py-6 bg-red-50 border-b border-red-200 rounded-t-lg"><h2 class="text-xl font-bold text-red-800 flex items-center gap-3"><i class="fas fa-triangle-exclamation"></i> Delete Course</h2></div>
            <div class="p-8">
                <p class="text-slate-800 text-sm mb-4">Delete <strong class="text-red-600">{{ $deleteCourseName }}</strong>?</p>
                <p class="text-slate-600 text-xs mb-6">This action cannot be undone.</p>
                <div class="flex gap-3">
                    <button type="button" wire:click="closeModal" class="flex-1 px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">Cancel</button>
                    <button type="button" wire:click="deleteCourse" wire:loading.attr="disabled" wire:target="deleteCourse" class="flex-1 px-6 py-2.5 bg-red-600 text-white rounded-lg text-sm font-semibold hover:bg-red-700 transition flex items-center justify-center gap-2"><span wire:loading wire:target="deleteCourse"><i class="fas fa-spinner spin-icon"></i></span><span wire:loading.remove wire:target="deleteCourse">Delete</span></button>
                </div>
            </div>
        </div>
    </div>
    @endif

    <!-- =====================================================
         MANAGE ORGANIZER COLLEGES MODAL
         ===================================================== -->
    @if($activeModal==='manageOrgCourses')
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.closeModal()">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-2xl max-h-[92vh] overflow-hidden flex flex-col modal-animate">
            <div class="flex items-center justify-between px-8 py-6 btn-primary text-white rounded-t-lg">
                <h2 class="text-2xl font-bold flex items-center gap-3"><i class="fas fa-building-columns text-2xl"></i> Manage Colleges & Departments</h2>
                <button wire:click="closeModal" class="text-3xl leading-none hover:opacity-70 transition">×</button>
            </div>

            @if($orgCourseAlert)
            <div x-data="{s:true}" x-effect="if(s){setTimeout(()=>{s=false},4000)}" x-show="s"
                 :class="'{{ $orgCourseAlertType }}'==='success'?'bg-emerald-50 border-l-4 border-emerald-400':'bg-red-50 border-l-4 border-red-400'"
                 class="p-4 mx-8 mt-5 rounded-lg shrink-0">
                <p :class="'{{ $orgCourseAlertType }}'==='success'?'text-emerald-800':'text-red-800'" class="text-sm font-semibold">
                    <i :class="'{{ $orgCourseAlertType }}'==='success'?'fas fa-check-circle':'fas fa-exclamation-circle'" class="mr-2"></i>{{ $orgCourseAlert }}
                </p>
            </div>
            @endif

            <div class="flex-1 overflow-y-auto scrollbar-custom px-8 py-6 space-y-5">

                <!-- ADD COLLEGE (only visible when not currently assigning) -->
                @if(!$orgAddingToCollege)
                <div class="border border-slate-200 rounded-lg p-5 bg-slate-50">
                    <h3 class="text-sm font-bold text-slate-800 mb-3">🏫 Add New College</h3>
                    <div class="flex gap-3">
                        <input wire:model="orgNewCollegeName" type="text" placeholder="e.g. College of Computer Studies"
                               class="flex-1 px-4 py-2.5 border border-slate-300 rounded-lg text-sm input-focus text-slate-800"
                               @keydown.enter.prevent="$wire.addCollege()">
                        <button type="button" wire:click="addCollege" class="px-5 py-2.5 btn-primary rounded-lg text-sm font-semibold flex items-center gap-2 whitespace-nowrap">
                            <i class="fas fa-plus"></i> Add College
                        </button>
                    </div>
                    <p class="text-xs text-slate-500 mt-2">After adding, select which courses/departments belong to it.</p>
                </div>
                @endif

                <!-- ASSIGN COURSES (multi-select checkboxes) -->
                @if($orgAddingToCollege)
                <div class="border-2 border-purple-300 rounded-lg p-5 bg-purple-50">
                    <div class="flex items-start justify-between mb-4">
                        <div>
                            <h3 class="text-sm font-bold text-purple-800">
                                {{ isset($orgCoursesList[$orgAddingToCollege]) ? '✏️ Edit Departments' : '➕ Assign Departments' }}
                            </h3>
                            <p class="text-xs text-purple-600 mt-0.5">College: <strong>{{ $orgAddingToCollege }}</strong></p>
                        </div>
                        <span class="text-xs bg-purple-200 text-purple-800 px-2.5 py-1 rounded-full font-semibold">{{ count($orgSelectedCourseCodes) }} selected</span>
                    </div>

                    @if($this->allCoursesForAssign->count()>0)
                    <p class="text-xs text-slate-600 mb-3">Check all courses that belong to this college:</p>
                    <div class="space-y-2 max-h-56 overflow-y-auto scrollbar-custom pr-1 mb-4">
                        @foreach($this->allCoursesForAssign as $c)
                        @php
                            $isSelected = in_array($c->code, $orgSelectedCourseCodes);
                            $otherCollege = ($c->college && $c->college !== $orgAddingToCollege) ? $c->college : null;
                        @endphp
                        <label class="course-check-row flex items-center gap-3 p-3 border rounded-lg cursor-pointer {{ $isSelected ? 'is-selected border-purple-400' : 'border-slate-200 bg-white' }}">
                            <input type="checkbox" wire:model="orgSelectedCourseCodes" value="{{ $c->code }}" class="w-4 h-4 shrink-0" style="accent-color:#7a3f91;">
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="font-bold text-slate-800 text-sm font-mono">{{ $c->code }}</span>
                                    <span class="text-slate-600 text-xs">{{ $c->name }}</span>
                                </div>
                                @if($otherCollege)<p class="text-xs text-amber-600 mt-0.5"><i class="fas fa-triangle-exclamation mr-1"></i>Currently under: <em>{{ $otherCollege }}</em></p>@endif
                            </div>
                            @if($isSelected)<i class="fas fa-check-circle text-purple-600 shrink-0 text-lg"></i>@endif
                        </label>
                        @endforeach
                    </div>
                    @else
                    <div class="text-center py-6"><i class="fas fa-book text-3xl text-slate-300 block mb-2"></i><p class="text-slate-500 text-sm">No courses available. Add courses first via <strong>Manage Courses</strong> (Alumni tab).</p></div>
                    @endif

                    <div class="flex gap-3">
                        <button type="button" wire:click="cancelAddingCourses" class="flex-1 px-4 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">Cancel</button>
                        <button type="button" wire:click="saveCollegeCourses" wire:loading.attr="disabled" wire:target="saveCollegeCourses" class="flex-1 px-4 py-2.5 btn-primary rounded-lg text-sm font-semibold flex items-center justify-center gap-2">
                            <span wire:loading wire:target="saveCollegeCourses"><i class="fas fa-spinner spin-icon"></i> Saving...</span>
                            <span wire:loading.remove wire:target="saveCollegeCourses"><i class="fas fa-floppy-disk"></i> Save Departments</span>
                        </button>
                    </div>
                </div>
                @endif

                <!-- COLLEGES LIST -->
                <div>
                    <h3 class="text-base font-bold text-slate-800 mb-3">📋 Colleges & Departments</h3>
                    @if(count($orgCoursesList)===0)
                    <div class="text-center py-10 border border-dashed border-slate-300 rounded-lg">
                        <i class="fas fa-building-columns text-5xl text-slate-200 block mb-3"></i>
                        <p class="text-slate-500 font-semibold text-sm">No colleges yet</p>
                        <p class="text-slate-400 text-xs mt-1">Add a college above to get started</p>
                    </div>
                    @else
                    <div class="space-y-3">
                        @foreach($orgCoursesList as $college=>$departments)
                        <div class="border border-slate-200 rounded-lg overflow-hidden college-card">
                            <div class="flex items-center justify-between px-5 py-3 bg-purple-50">
                                <div class="flex items-center gap-3">
                                    <div class="w-8 h-8 bg-purple-200 rounded-lg flex items-center justify-center"><i class="fas fa-building-columns text-purple-700 text-sm"></i></div>
                                    <div>
                                        <p class="font-bold text-purple-900 text-sm">{{ $college }}</p>
                                        <p class="text-purple-600 text-xs">{{ count($departments) }} department{{ count($departments)!==1?'s':'' }}</p>
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    @if(!$orgAddingToCollege)
                                    <button wire:click="startEditingCollege('{{ addslashes($college) }}')"
                                            class="px-3 py-1.5 bg-white text-purple-700 rounded-lg hover:bg-purple-100 transition font-semibold text-xs border border-purple-300 flex items-center gap-1.5">
                                        <i class="fas fa-pencil"></i> Edit
                                    </button>
                                    @endif
                                    <button wire:click="confirmDeleteCollege('{{ addslashes($college) }}')"
                                            class="px-3 py-1.5 bg-red-50 text-red-600 rounded-lg hover:bg-red-100 transition font-semibold text-xs border border-red-200">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="divide-y divide-slate-100">
                                @foreach($departments as $dept)
                                <div class="flex items-center px-5 py-3 bg-white">
                                    <span class="w-8 h-8 bg-purple-50 text-purple-600 rounded-lg flex items-center justify-center text-xs font-bold shrink-0">{{ strtoupper(substr($dept['code'],0,2)) }}</span>
                                    <div class="ml-3">
                                        <p class="font-semibold text-slate-800 text-sm">{{ $dept['code'] }}</p>
                                        <p class="text-slate-500 text-xs">{{ $dept['name'] }}</p>
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

            <div class="px-8 py-4 border-t border-slate-200 bg-slate-50 shrink-0">
                <button wire:click="closeModal" class="w-full px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-100 transition">Close</button>
            </div>
        </div>
    </div>
    @endif

    <!-- DELETE COLLEGE CONFIRM -->
    @if($activeModal==='deleteOrgCollegeConfirm')
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-sm modal-animate">
            <div class="px-8 py-6 bg-red-50 border-b border-red-200 rounded-t-lg"><h2 class="text-xl font-bold text-red-800 flex items-center gap-3"><i class="fas fa-triangle-exclamation"></i> Delete College</h2></div>
            <div class="p-8">
                <p class="text-slate-800 text-sm mb-2">Remove college <strong class="text-red-600">{{ $deleteOrgCourseName }}</strong>?</p>
                <p class="text-slate-600 text-xs mb-6">⚠️ All courses will be unassigned from this college. The courses themselves will <strong>not</strong> be deleted.</p>
                <div class="flex gap-3">
                    <button type="button" wire:click="openModal('manageOrgCourses')" class="flex-1 px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">Cancel</button>
                    <button type="button" wire:click="deleteOrgCollege" wire:loading.attr="disabled" wire:target="deleteOrgCollege" class="flex-1 px-6 py-2.5 bg-red-600 text-white rounded-lg text-sm font-semibold hover:bg-red-700 transition flex items-center justify-center gap-2">
                        <span wire:loading wire:target="deleteOrgCollege"><i class="fas fa-spinner spin-icon"></i></span>
                        <span wire:loading.remove wire:target="deleteOrgCollege">Delete College</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
    @endif

    <!-- VIEW PROFILE -->
    @if($activeModal==='viewProfile'&&$viewingProfile)
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40 backdrop-blur-sm" @keydown.escape.window="$wire.closeModal()">
        <div class="bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[92vh] overflow-y-auto scrollbar-custom modal-animate">
            <div class="flex items-center justify-between px-8 py-5 btn-primary text-white rounded-t-lg sticky top-0 z-10">
                <h2 class="text-xl font-bold flex items-center gap-2">
                    <i class="fas {{ $viewingProfileType==='alumni'?'fa-graduation-cap':'fa-users-gear' }}"></i>
                    {{ $viewingProfileType==='alumni'?'Alumni':'Organizer' }} Profile
                </h2>
                <button wire:click="closeModal" class="text-3xl leading-none hover:opacity-70 transition">×</button>
            </div>
            <div class="p-8 space-y-6">

                {{-- Photo + Name --}}
                <div class="flex items-center gap-5">
                    @if($updatingProfilePhoto)
                        <img src="{{ $updatingProfilePhoto->temporaryUrl() }}" alt="Preview" class="w-40 h-40 rounded-xl object-cover shadow-md shrink-0">
                    @else
                        <img src="{{ $this->getPhotoUrl($viewingProfile['profile_photo']??null) }}" alt="{{ $viewingProfile['name'] }}" class="w-40 h-40 rounded-xl object-cover shadow-md shrink-0">
                    @endif
                    <div>
                        <p class="text-xl font-bold text-slate-800 leading-tight">{{ $viewingProfile['name'] }}</p>
                        <p class="text-slate-500 text-sm mt-1">{{ $viewingProfile['email'] }}</p>
                        @if($viewingProfileType==='alumni')
                            @php $sc=match($viewingProfile['status']??''){'VERIFIED'=>'bg-emerald-100 text-emerald-700','PENDING'=>'bg-amber-100 text-amber-700','REJECTED'=>'bg-red-100 text-red-700',default=>'bg-slate-100 text-slate-600'}; @endphp
                        @else
                            @php $sc=match($viewingProfile['status']??''){'ACTIVE'=>'bg-emerald-100 text-emerald-700','INACTIVE'=>'bg-red-100 text-red-700','SUSPENDED'=>'bg-amber-100 text-amber-700',default=>'bg-slate-100 text-slate-600'}; @endphp
                        @endif
                        <span class="inline-block mt-2 px-3 py-1 rounded-full text-xs font-semibold {{ $sc }}">{{ $viewingProfile['status'] ?? 'N/A' }}</span>
                    </div>
                </div>

                {{-- Info Cards --}}
                <div class="grid grid-cols-2 gap-3">
                    @if($viewingProfileType==='alumni')
                        <div class="bg-slate-50 rounded-lg p-4 border border-slate-200">
                            <p class="text-xs text-slate-500 font-semibold uppercase tracking-wide mb-1">Student ID</p>
                            <p class="font-bold text-slate-800 font-mono">{{ $viewingProfile['student_id'] ?? '—' }}</p>
                        </div>
                        <div class="bg-slate-50 rounded-lg p-4 border border-slate-200">
                            <p class="text-xs text-slate-500 font-semibold uppercase tracking-wide mb-1">Batch Year</p>
                            <p class="font-bold text-slate-800">{{ $viewingProfile['batch'] ?? '—' }}</p>
                        </div>
                        <div class="bg-purple-50 rounded-lg p-4 border border-purple-200 col-span-2">
                            <p class="text-xs text-purple-600 font-semibold uppercase tracking-wide mb-1">Course</p>
                            <p class="font-bold text-purple-900 text-sm">{{ $viewingProfile['course_code'] ?? '—' }}</p>
                            <p class="text-purple-700 text-xs mt-0.5">{{ $viewingProfile['course_name'] ?? '' }}</p>
                        </div>
                    @else
                        <div class="bg-slate-50 rounded-lg p-4 border border-slate-200">
                            <p class="text-xs text-slate-500 font-semibold uppercase tracking-wide mb-1">Teacher ID</p>
                            <p class="font-bold text-slate-800 font-mono">{{ $viewingProfile['id_number'] ?? '—' }}</p>
                        </div>
                        <div class="bg-slate-50 rounded-lg p-4 border border-slate-200">
                            <p class="text-xs text-slate-500 font-semibold uppercase tracking-wide mb-1">Department</p>
                            <p class="font-bold text-slate-800 font-mono">{{ $viewingProfile['department'] ?? '—' }}</p>
                        </div>
                        <div class="bg-purple-50 rounded-lg p-4 border border-purple-200 col-span-2">
                            <p class="text-xs text-purple-600 font-semibold uppercase tracking-wide mb-1">College</p>
                            <p class="font-bold text-purple-900 text-sm">{{ $this->getCollegeForCourse($viewingProfile['department'] ?? '') }}</p>
                        </div>
                    @endif
                </div>

                {{-- Update Photo --}}
                <div>
                    <p class="text-xs font-bold text-slate-700 uppercase tracking-wide mb-2">Update Profile Photo</p>
                    <div class="border-2 border-dashed border-slate-300 rounded-lg p-5 text-center cursor-pointer hover:border-purple-400 hover:bg-purple-50 transition" @click="document.getElementById('profilePhotoInput').click()">
                        <i class="fas fa-camera text-2xl text-slate-400 block mb-2"></i>
                        <p class="text-slate-700 font-semibold text-sm">{{ $updatingProfilePhoto?'Change Photo':'Click to Upload New Photo' }}</p>
                        <p class="text-xs text-slate-500 mt-1">JPG, PNG, WebP · max 5 MB</p>
                        <input type="file" id="profilePhotoInput" wire:model="updatingProfilePhoto" accept="image/*" class="hidden">
                    </div>
                    @if($updatingProfilePhoto)
                    <button wire:click="updateProfilePhoto" wire:loading.attr="disabled" wire:target="updateProfilePhoto" class="w-full mt-3 px-6 py-2.5 btn-primary rounded-lg text-sm font-semibold flex items-center justify-center gap-2">
                        <span wire:loading wire:target="updateProfilePhoto"><i class="fas fa-spinner spin-icon"></i> Saving...</span>
                        <span wire:loading.remove wire:target="updateProfilePhoto"><i class="fas fa-save"></i> Save Photo</span>
                    </button>
                    @endif
                </div>

                <button wire:click="closeModal" class="w-full px-6 py-2.5 border border-slate-300 text-slate-700 rounded-lg text-sm font-semibold hover:bg-slate-50 transition">Close</button>
            </div>
        </div>
    </div>
    @endif

</div>
</div>

<script>
function searchBox(wireProp) {
    return {
        val: '',
        timer: null,
        init() {
            this.val = this.$wire[wireProp] || '';
            // Sync when Livewire resets the value (e.g. reset filters button)
            Livewire.hook('morph.updated', ({ el }) => {
                // noop — we manage val ourselves
            });
            this.$el.addEventListener('livewire:update', () => {
                const fresh = this.$wire[wireProp] || '';
                if (fresh !== this.val) this.val = fresh;
            });
        },
        onInput() {
            clearTimeout(this.timer);
            this.timer = setTimeout(() => {
                this.$wire.set(wireProp, this.val);
            }, 380);
        },
        onReset(v) {
            this.val = v || '';
        }
    }
}
</script>