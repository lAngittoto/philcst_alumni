{{-- resources/views/livewire/director/manage-users.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

new class extends Component {
    use WithPagination, WithFileUploads;

    protected function queryString(): array { return []; }

    // ── Tab / Filter ──────────────────────────────────────────────────────────
    public string $activeRole   = 'all';
    public string $search       = '';
    public string $statusFilter = '';
    public string $sortBy       = 'recent';
    public string $activeModal  = '';

    // ── Create Director ───────────────────────────────────────────────────────
    public string $dFn='', $dMn='', $dLn='', $dSfx='';
    public string $dUsername='', $dEmail='';
    public array  $dErrs = [];
    public string $dOk   = '';
    public bool   $dSave = false;

    // ── View ──────────────────────────────────────────────────────────────────
    public ?array  $vData = null;

    // ── Photo Upload ──────────────────────────────────────────────────────────
    public $vPhoto       = null;
    public bool $vPhotoSave = false;

    // ── Alumni Email Update Modal ─────────────────────────────────────────────
    public ?int   $ueId     = null;
    public string $ueName   = '';
    public string $ueEmail  = '';
    public array  $ueErrors = [];
    public bool   $ueSave   = false;

    // ── Change Password ───────────────────────────────────────────────────────
    public ?int   $cpId     = null;
    public string $cpName   = '';
    public string $cpNew    = '', $cpConfirm = '';
    public array  $cpErrs   = [];
    public bool   $cpSave   = false;

    // ── Toggle confirm ────────────────────────────────────────────────────────
    public ?int   $tId      = null;
    public string $tName='', $tAction='', $tRole='';

    // ─────────────────────────────────────────────────────────────────────────
    public function mount(): void {}

    private function perPage(): int { return 20; }

    public function updatingSearch()       { $this->resetPage(); }
    public function updatingStatusFilter() { $this->resetPage(); }

    public function switchTab(string $r): void {
        $this->activeRole = $r;
        $this->search = $this->statusFilter = '';
        $this->resetPage();
    }

    // ── Stats ─────────────────────────────────────────────────────────────────
    #[Computed]
    public function stats(): array
    {
        $rows = DB::table('users')
            ->selectRaw("role, COUNT(*) as cnt")
            ->groupBy('role')
            ->pluck('cnt', 'role');
        return [
            'total'       => $rows->sum(),
            'alumni'      => $rows->get('alumni',    0),
            'director'    => $rows->get('director',  0),
            'coordinator' => $rows->get('organizer', 0),
            'registrar'   => $rows->get('registrar', 0),
            'admin'       => $rows->get('admin',     0),
        ];
    }

    // ── Main query ────────────────────────────────────────────────────────────
    #[Computed]
    public function users()
    {
        $st = "(CASE
            WHEN users.role='alumni'    THEN IF(al.password_changed_at IS NOT NULL,'VERIFIED','PENDING')
            WHEN users.role='organizer' THEN COALESCE(org.status,'ACTIVE')
            WHEN users.role='director'  THEN COALESCE(dir.status,'ACTIVE')
            WHEN users.role='registrar' THEN COALESCE(users.user_status,'ACTIVE')
            ELSE 'ACTIVE'
        END)";

        $q = DB::table('users')
            ->select([
                'users.id','users.name','users.email','users.role','users.created_at',
                'users.user_status',
                DB::raw("{$st} as computed_status"),
                DB::raw("COALESCE(al.student_id,'')          as student_id"),
                DB::raw("COALESCE(al.email,'')               as record_email"),
                DB::raw("COALESCE(org.id_number,'')          as id_number"),
                DB::raw("COALESCE(org.department,'')         as department"),
                DB::raw("COALESCE(NULLIF(al.profile_photo,''), NULLIF(org.profile_photo,''), NULLIF(dir.profile_photo,'')) as photo"),
            ])
            ->leftJoin('alumni as al', 'al.user_id', '=', 'users.id')
            ->leftJoin('organizer as org', fn($j) => $j->on('org.user_id','=','users.id')->whereNull('org.deleted_at'))
            ->leftJoin('director as dir',  fn($j) => $j->on('dir.user_id','=','users.id')->whereNull('dir.deleted_at'));

        $map = ['alumni'=>'alumni','director'=>'director','coordinator'=>'organizer','registrar'=>'registrar','admin'=>'admin'];
        if ($this->activeRole !== 'all' && isset($map[$this->activeRole]))
            $q->where('users.role', $map[$this->activeRole]);

        if ($this->search) {
            $t = '%'.$this->search.'%';
            $q->where(fn($s) => $s->where('users.name','like',$t)->orWhere('users.email','like',$t));
        }
        if ($this->statusFilter)
            $q->whereRaw("{$st} = ?", [$this->statusFilter]);

        $q->orderBy('users.created_at', $this->sortBy === 'oldest' ? 'asc' : 'desc');
        return $q->paginate($this->perPage());
    }

    // ── Modal helpers ─────────────────────────────────────────────────────────
    public function openModal(string $m): void {
        $this->activeModal = $m;
        $this->dFn=$this->dMn=$this->dLn=$this->dSfx=$this->dUsername=$this->dEmail='';
        $this->dErrs=[]; $this->dOk='';
    }

    public function closeModal(): void {
        $this->activeModal=''; $this->vData=null;
        $this->tId=null;
        $this->cpId=null; $this->cpNew=$this->cpConfirm=''; $this->cpErrs=[];
        $this->ueId=null; $this->ueName=$this->ueEmail=''; $this->ueErrors=[];
        $this->vPhoto=null; $this->vPhotoSave=false;
    }

    // ── Create Director ───────────────────────────────────────────────────────
    public function createDirector(): void {
        $this->dErrs=[]; $this->dOk=''; $this->dSave=true;
        try {
            $errors = [];

            if (!trim($this->dFn))       $errors[] = 'First name is required.';
            if (!trim($this->dMn))       $errors[] = 'Middle name is required.';
            if (!trim($this->dLn))       $errors[] = 'Last name is required.';
            if (!trim($this->dUsername)) $errors[] = 'Username is required.';
            if (!trim($this->dEmail))    $errors[] = 'Email address is required.';
            elseif (!filter_var(trim($this->dEmail), FILTER_VALIDATE_EMAIL))
                                         $errors[] = 'Please enter a valid email address.';

            if (!empty($errors)) {
                $this->dErrs = ['general' => $errors];
                return;
            }

            // ── ONE ACTIVE DIRECTOR ONLY ──────────────────────────────────────
            if (DB::table('director')->where('status', 'ACTIVE')->exists()) {
                $this->dErrs = ['general' => [
                    'There is already an active Director in the system. Please deactivate the current Director first before creating a new one.'
                ]];
                return;
            }

            $loginEmail = trim($this->dUsername).'@director.internal';
            if (DB::table('users')->where('email', $loginEmail)->exists()) {
                $this->dErrs = ['general' => ['That username is already taken. Please choose a different one.']];
                return;
            }

            $autoPassword = Str::upper(Str::random(3)) . rand(100,999) . Str::random(4) . '!';
            $full  = implode(' ', array_filter(array_map('trim', [$this->dFn, $this->dMn, $this->dLn, $this->dSfx])));
            $uname = trim($this->dUsername);

            $uid = DB::table('users')->insertGetId([
                'name'       => $full,
                'email'      => $loginEmail,
                'role'       => 'director',
                'password'   => Hash::make($autoPassword),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('director')->insert([
                'user_id'     => $uid,
                'first_name'  => trim($this->dFn),
                'middle_name' => trim($this->dMn),
                'last_name'   => trim($this->dLn),
                'suffix'      => trim($this->dSfx) ?: null,
                'email'       => trim($this->dEmail),
                'status'      => 'ACTIVE',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);

            \Mail::send('emails.director-registered', [
                'fullName'     => $full,
                'username'     => $uname,
                'tempPassword' => $autoPassword,
                'email'        => trim($this->dEmail),
            ], function ($m) {
                $m->to(trim($this->dEmail))->subject("Your Director Account – Philcst Alumni Connect");
            });

            $this->dOk = "Director <strong>{$full}</strong> created successfully!"
                . "|Login username: <code class='font-mono bg-green-100 px-1.5 py-0.5 rounded text-green-800'>{$uname}</code>"
                . "|Auto-generated password: <code class='font-mono bg-yellow-100 px-1.5 py-0.5 rounded text-yellow-800'>{$autoPassword}</code>"
                . "|<span class='text-amber-700 font-medium'>⚠ Please share the password with the director and advise them to change it upon first login.</span>";

            $this->flash('success', "Director '{$full}' registered successfully!");
        } catch (\Exception $e) {
            $this->dErrs = ['general' => [$e->getMessage()]];
        } finally { $this->dSave = false; }
    }

    // ── View Profile ──────────────────────────────────────────────────────────
    public function showProfile(int $id): void {
        $r = DB::table('users')
            ->select([
                'users.id','users.name','users.email','users.role','users.created_at',
                DB::raw("(CASE
                    WHEN users.role='alumni'    THEN IF(al.password_changed_at IS NOT NULL,'VERIFIED','PENDING')
                    WHEN users.role='organizer' THEN COALESCE(org.status,'ACTIVE')
                    WHEN users.role='director'  THEN COALESCE(dir.status,'ACTIVE')
                    WHEN users.role='registrar' THEN COALESCE(users.user_status,'ACTIVE')
                    ELSE 'ACTIVE'
                END) as user_status"),
                // Alumni fields
                DB::raw("COALESCE(al.student_id,'')                          as student_id"),
                DB::raw("COALESCE(al.course_code,'')                         as course_code"),
                DB::raw("COALESCE(al.course_name,'')                         as course_name"),
                DB::raw("COALESCE(CAST(al.batch AS CHAR),'')                 as batch"),
                DB::raw("COALESCE(al.first_name,'')                          as alumni_first_name"),
                DB::raw("COALESCE(al.middle_initial,'')                      as alumni_middle_name"),
                DB::raw("COALESCE(al.last_name,'')                           as alumni_last_name"),
                DB::raw("COALESCE(al.suffix,'')                              as alumni_suffix"),
                DB::raw("COALESCE(al.email,'')                               as record_email"),
                // Organizer fields
                DB::raw("COALESCE(org.first_name,'')                         as org_first_name"),
                DB::raw("COALESCE(org.last_name,'')                          as org_last_name"),
                DB::raw("COALESCE(org.id_number,'')                          as id_number"),
                DB::raw("COALESCE(org.department,'')                         as department"),
                // Director fields
                DB::raw("COALESCE(dir.first_name,'')                         as first_name"),
                DB::raw("COALESCE(dir.middle_name,'')                        as middle_name"),
                DB::raw("COALESCE(dir.last_name,'')                          as last_name"),
                DB::raw("COALESCE(dir.suffix,'')                             as suffix"),
                DB::raw("COALESCE(dir.email,'')                              as director_email"),
                // Photo
                DB::raw("COALESCE(NULLIF(al.profile_photo,''), NULLIF(org.profile_photo,''), NULLIF(dir.profile_photo,'')) as photo"),
            ])
            ->leftJoin('alumni as al','al.user_id','=','users.id')
            ->leftJoin('organizer as org', fn($j)=>$j->on('org.user_id','=','users.id')->whereNull('org.deleted_at'))
            ->leftJoin('director as dir',  fn($j)=>$j->on('dir.user_id','=','users.id')->whereNull('dir.deleted_at'))
            ->where('users.id',$id)->first();
        if (!$r) { $this->flash('error','User not found.'); return; }
        $this->vData = (array) $r;
        $this->vPhoto = null;
        $this->activeModal = 'viewProfile';
    }

    // ── Upload Profile Photo ──────────────────────────────────────────────────
    public function savePhoto(): void {
        $this->vPhotoSave = true;
        try {
            if (!$this->vPhoto) {
                $this->flash('error', 'Please select a photo to upload.');
                return;
            }

            $this->validate(['vPhoto' => 'image|mimes:jpg,jpeg,png,gif,webp|max:2048']);

            $role   = $this->vData['role'];
            $userId = $this->vData['id'];

            $folder = match($role) {
                'alumni'    => 'alumni-photos',
                'organizer' => 'organizers',
                'director'  => 'directors',
                'registrar' => 'registrars',
                default     => 'profile-photos',
            };

            // Delete old photo if it exists
            $oldPhoto = $this->vData['photo'] ?? null;
            if ($oldPhoto && Storage::disk('public')->exists($oldPhoto)) {
                Storage::disk('public')->delete($oldPhoto);
            }

            $path = $this->vPhoto->store($folder, 'public');

            match($role) {
                'alumni'    => DB::table('alumni')->where('user_id', $userId)->update(['profile_photo' => $path, 'updated_at' => now()]),
                'organizer' => DB::table('organizer')->where('user_id', $userId)->update(['profile_photo' => $path, 'updated_at' => now()]),
                'director'  => DB::table('director')->where('user_id', $userId)->update(['profile_photo' => $path, 'updated_at' => now()]),
                'registrar' => DB::table('users')->where('id', $userId)->update(['profile_photo' => $path, 'updated_at' => now()]),
                default     => null,
            };

            $this->vData['photo'] = $path;
            $this->vPhoto = null;
            $this->flash('success', 'Profile photo updated successfully!');
        } catch (\Exception $e) {
            $this->flash('error', 'Failed to upload photo: ' . $e->getMessage());
        } finally {
            $this->vPhotoSave = false;
        }
    }

    // ── Alumni Email Update ───────────────────────────────────────────────────
    public function openUpdateEmail(int $id): void {
        $u  = DB::table('users')->find($id);
        $al = DB::table('alumni')->where('user_id', $id)->first();
        if (!$u || $u->role !== 'alumni') { $this->flash('error','User not found.'); return; }
        $this->ueId     = $id;
        $this->ueName   = $u->name;
        $this->ueEmail  = ($al && $al->email && !str_contains($al->email, '@pending.local')) ? $al->email : '';
        $this->ueErrors = [];
        $this->activeModal = 'updateAlumniEmail';
    }

    public function saveUpdateEmail(): void {
        $this->ueErrors = []; $this->ueSave = true;
        try {
            $email = trim($this->ueEmail);

            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->ueErrors = ['general' => ['Please enter a valid email address.']];
                return;
            }

            // Friendly duplicate check before hitting the DB constraint
            $duplicate = DB::table('alumni')
                ->where('email', $email)
                ->where('user_id', '!=', $this->ueId)
                ->exists();

            if ($duplicate) {
                $this->ueErrors = ['general' => [
                    "The email address \"{$email}\" is already registered to another alumni account. Please use a different email address."
                ]];
                return;
            }

            // Update email AND reset password_changed_at so wizard runs on next login
            DB::table('alumni')->where('user_id', $this->ueId)
                ->update([
                    'email'               => $email,
                    'password_changed_at' => null,
                    'updated_at'          => now(),
                ]);

            $this->flash('success', "Email updated for {$this->ueName}. They will be required to reset their password on next login.");
            $this->closeModal();

        } catch (\Illuminate\Database\QueryException $e) {
            // Safety net: catch DB-level duplicate key error
            if (($e->errorInfo[1] ?? null) === 1062) {
                $this->ueErrors = ['general' => [
                    'That email address is already in use by another alumni account. Please choose a different one.'
                ]];
            } else {
                $this->ueErrors = ['general' => ['A database error occurred. Please try again.']];
            }
        } catch (\Exception $e) {
            $this->ueErrors = ['general' => [$e->getMessage()]];
        } finally { $this->ueSave = false; }
    }

    // ── Change Password ───────────────────────────────────────────────────────
    public function openChangePassword(int $id): void {
        $u = DB::table('users')->find($id);
        if (!$u) { $this->flash('error','User not found.'); return; }
        $this->cpId      = $id;
        $this->cpName    = $u->name;
        $this->cpNew     = $this->cpConfirm = '';
        $this->cpErrs    = [];
        $this->activeModal = 'changePassword';
    }

    public function saveChangePassword(): void {
        $this->cpErrs = []; $this->cpSave = true;
        try {
            if (strlen(trim($this->cpNew)) < 8)
                throw new \Exception('Password must be at least 8 characters long.');
            if ($this->cpNew !== $this->cpConfirm)
                throw new \Exception('Passwords do not match. Please re-enter and try again.');

            $user = DB::table('users')->find($this->cpId);

            // Update the password
            DB::table('users')->where('id', $this->cpId)
                ->update(['password' => Hash::make($this->cpNew), 'updated_at' => now()]);

            // Reset password_changed_at in the role table so the user is forced
            // through the change-password wizard on their very next login
            match($user->role ?? '') {
                'alumni'    => DB::table('alumni')
                                    ->where('user_id', $this->cpId)
                                    ->update(['password_changed_at' => null, 'updated_at' => now()]),
                'organizer' => DB::table('organizer')
                                    ->where('user_id', $this->cpId)
                                    ->update(['password_changed_at' => null, 'updated_at' => now()]),
                'director'  => DB::table('director')
                                    ->where('user_id', $this->cpId)
                                    ->update(['password_changed_at' => null, 'updated_at' => now()]),
                'registrar' => DB::table('users')
                                    ->where('id', $this->cpId)
                                    ->update(['password_changed_at' => null, 'updated_at' => now()]),
                default     => null,
            };

            $this->flash('success', "Password updated for {$this->cpName}. They will be required to change it on next login.");
            $this->closeModal();
        } catch (\Exception $e) {
            $this->cpErrs = ['general' => [$e->getMessage()]];
        } finally { $this->cpSave = false; }
    }

    // ── Toggle ────────────────────────────────────────────────────────────────
    public function confirmToggle(int $id, string $a): void {
        $u = DB::table('users')->find($id);
        if (!$u) { $this->flash('error','User not found.'); return; }
        $this->tId=$id; $this->tName=$u->name; $this->tAction=$a; $this->tRole=$u->role;
        $this->activeModal='toggleConfirm';
    }

    public function executeToggle(): void {
        try {
            $s = $this->tAction==='activate' ? 'ACTIVE' : 'INACTIVE';
            if ($this->tRole==='director')  DB::table('director') ->where('user_id',$this->tId)->update(['status'=>$s,'updated_at'=>now()]);
            if ($this->tRole==='registrar') DB::table('users')    ->where('id',$this->tId)->update(['user_status'=>$s,'updated_at'=>now()]);
            $this->flash('success', $this->tName.' has been '.($s==='ACTIVE'?'activated':'deactivated').'.');
        } catch (\Exception $e) { $this->flash('error','Failed: '.$e->getMessage()); }
        finally { $this->closeModal(); }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    private function flash(string $t, string $m): void { $this->dispatch('flash-message', type:$t, message:$m); }

    public function roleBadge(string $r): string {
        return match($r) {
            'admin'     => 'bg-red-50 text-red-700 border-red-200',
            'director'  => 'bg-indigo-50 text-indigo-700 border-indigo-200',
            'organizer' => 'bg-purple-50 text-purple-700 border-purple-200',
            'registrar' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'alumni'    => 'bg-blue-50 text-blue-700 border-blue-200',
            default     => 'bg-gray-100 text-gray-700 border-gray-200',
        };
    }
    public function roleLabel(string $r): string {
        return match($r) {
            'organizer'=>'Coordinator','director'=>'Director','registrar'=>'Registrar',
            'alumni'=>'Alumni','admin'=>'Admin',default=>ucfirst($r),
        };
    }
    public function statusBadge(string $s): string {
        return match($s) {
            'ACTIVE','VERIFIED' => 'bg-emerald-50 text-emerald-700 border-emerald-200',
            'PENDING','INACTIVE'=> 'bg-amber-50 text-amber-700 border-amber-200',
            'SUSPENDED'         => 'bg-red-50 text-red-700 border-red-200',
            default             => 'bg-gray-50 text-gray-600 border-gray-200',
        };
    }
    public function photoUrl(?string $p): string {
        if (!$p) return asset('storage/alumni-photos/default.png');
        if (str_starts_with($p,'alumni-photos/')||str_starts_with($p,'organizers/')||str_starts_with($p,'directors/')||str_starts_with($p,'registrars/'))
            return Storage::disk('public')->exists($p) ? asset('storage/'.$p) : asset('storage/alumni-photos/default.png');
        return asset('storage/alumni-photos/default.png');
    }
    public function displayEmail(string $role, string $email, string $recordEmail): string {
        if ($role === 'alumni')
            return ($recordEmail && !str_contains($recordEmail,'@pending.local')) ? $recordEmail : '—';
        return str_ends_with($email, '.internal') ? '—' : $email;
    }
    public function adminUsername(string $email, string $name): string {
        if (str_ends_with($email, '.internal')) return explode('@', $email)[0];
        return $name ?: explode('@', $email)[0];
    }
};
?>

<div>

{{-- ══════════════════ FLASH TOAST ══════════════════ --}}
<div x-data="{
        show:false,type:'success',msg:'',timer:null,
        display(t,m){this.type=t;this.msg=m;this.show=true;clearTimeout(this.timer);this.timer=setTimeout(()=>this.show=false,7000);}
     }"
     @flash-message.window="display($event.detail.type,$event.detail.message)"
     x-show="show"
     x-transition:enter="transition ease-out duration-250"
     x-transition:enter-start="opacity-0 translate-x-6 scale-95"
     x-transition:enter-end="opacity-100 translate-x-0 scale-100"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0 translate-x-6"
     class="fixed top-4 right-4 z-[200] flex items-start gap-3 px-4 py-3.5 rounded-xl shadow-xl max-w-sm border bg-white"
     :class="{'border-emerald-200':type==='success','border-blue-200':type==='info','border-red-200':type==='error'}"
     style="display:none">
    <i class="fas mt-0.5 text-sm shrink-0"
       :class="{'fa-circle-check text-emerald-500':type==='success','fa-circle-info text-blue-500':type==='info','fa-circle-exclamation text-red-500':type==='error'}"></i>
    <div class="flex-1 min-w-0">
        <p class="font-bold text-sm text-gray-900" x-text="type==='success'?'Success':type==='info'?'Info':'Error'"></p>
        <p class="text-sm mt-0.5 text-gray-600 leading-snug break-words" x-text="msg"></p>
    </div>
    <button @click="show=false" class="text-gray-400 hover:text-gray-600 transition shrink-0"><i class="fas fa-xmark"></i></button>
</div>

{{-- ══════════════════ PAGE WRAPPER ══════════════════ --}}
<div class="flex flex-col px-4 sm:px-6 lg:px-8 pt-5 pb-4 mx-auto max-w-screen-2xl" style="height:90vh;">

    {{-- ── HEADER ── --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4 shrink-0">
        <div class="flex items-center gap-3">
            <div class="w-11 h-11 rounded-xl flex items-center justify-center shadow-sm shrink-0" style="background:#7A3F91;">
                <i class="fas fa-users-cog text-white text-base"></i>
            </div>
            <div>
                <h1 class="text-2xl font-bold leading-tight" style="color:#1a1a1a;">User Management</h1>
                <p class="text-sm mt-0.5 font-medium" style="color:#666;">Manage all system users across every role</p>
            </div>
        </div>
        <div class="flex flex-wrap gap-2 shrink-0">
            <button wire:click="openModal('createDirector')"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-semibold text-white shadow-sm transition hover:opacity-90 active:scale-95"
                    style="background:#7A3F91;">
                <i class="fas fa-user-tie"></i> New Director
            </button>
        </div>
    </div>

    {{-- ── STAT CARDS ── --}}
    <div class="grid grid-cols-3 sm:grid-cols-6 gap-2.5 mb-4 shrink-0">
        @php
            $statCards = [
                ['fa-users',          '#EDE9F6','#7A3F91', $this->stats['total'],       'Total Users' ],
                ['fa-graduation-cap', '#EFF6FF','#3B82F6', $this->stats['alumni'],      'Alumni'      ],
                ['fa-user-tie',       '#EEF2FF','#4F46E5', $this->stats['director'],    'Directors'   ],
                ['fa-users-gear',     '#F5EEF9','#7A3F91', $this->stats['coordinator'], 'Coordinators'],
                ['fa-user-clock',     '#ECFDF5','#059669', $this->stats['registrar'],   'Registrars'  ],
                ['fa-shield-halved',  '#FEF2F2','#DC2626', $this->stats['admin'],       'Admins'      ],
            ];
        @endphp
        @foreach($statCards as [$icon,$bg,$color,$count,$label])
        <div class="bg-white rounded-xl border p-3 sm:p-4" style="border-color:#e5e7eb;">
            <div class="w-9 h-9 rounded-xl flex items-center justify-center mb-2" style="background:{{ $bg }};">
                <i class="fas {{ $icon }} text-sm" style="color:{{ $color }};"></i>
            </div>
            <p class="text-2xl font-bold leading-none" style="color:#1a1a1a;">{{ $count }}</p>
            <p class="text-xs font-semibold mt-1 truncate" style="color:#6b7280;">{{ $label }}</p>
        </div>
        @endforeach
    </div>

    {{-- ── TABLE CARD ── --}}
    <div class="bg-white rounded-xl border flex flex-col overflow-hidden flex-1 min-h-0" style="border-color:#e5e7eb;">

        {{-- Tab Bar + Filters --}}
        <div class="px-4 py-3 border-b flex flex-wrap gap-2 items-center shrink-0" style="border-color:#e5e7eb;background:#f9fafb;">
            <div class="flex gap-1 bg-gray-100 p-0.5 rounded-lg">
                @foreach([
                    ['all','All','fa-globe'],
                    ['alumni','Alumni','fa-graduation-cap'],
                    ['director','Directors','fa-user-tie'],
                    ['coordinator','Coordinators','fa-users-gear'],
                    ['registrar','Registrars','fa-user-clock'],
                    ['admin','Admins','fa-shield-halved'],
                ] as [$tab,$lbl,$ico])
                <button wire:click="switchTab('{{ $tab }}')"
                        class="flex items-center gap-1.5 px-3 py-1.5 rounded-md text-sm font-semibold transition-all duration-150
                               {{ $activeRole===$tab
                                    ? 'bg-white shadow-sm border text-[#7A3F91]'
                                    : 'text-gray-500 hover:text-gray-700' }}"
                        style="{{ $activeRole===$tab ? 'border-color:#e9d5f3;' : '' }}">
                    <i class="fas {{ $ico }} text-xs"></i>
                    <span class="hidden sm:inline">{{ $lbl }}</span>
                </button>
                @endforeach
            </div>

            <div class="flex flex-wrap gap-2 ml-auto items-center">
                <div class="relative" wire:ignore
                     x-data="{ timer:null }"
                     x-on:input="clearTimeout(timer); timer=setTimeout(()=>{ $wire.set('search',$el.querySelector('input').value); },350)">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                    <input type="text" placeholder="Search name or email…"
                           class="pl-9 pr-3 py-2 border rounded-lg text-sm bg-white focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/10 w-44 sm:w-60"
                           style="border-color:#d1d5db;color:#333;" autocomplete="off">
                </div>

                <select wire:model.live="statusFilter"
                        class="px-3 py-2 border rounded-lg text-sm bg-white focus:outline-none focus:border-[#7A3F91]"
                        style="border-color:#d1d5db;color:#333;">
                    <option value="">All Status</option>
                    <option value="ACTIVE">Active</option>
                    <option value="INACTIVE">Inactive</option>
                </select>

                <select wire:model.live="sortBy"
                        class="px-3 py-2 border rounded-lg text-sm bg-white focus:outline-none focus:border-[#7A3F91]"
                        style="border-color:#d1d5db;color:#333;">
                    <option value="recent">Newest First</option>
                    <option value="oldest">Oldest First</option>
                </select>
            </div>
        </div>

        @php $pagedUsers = $this->users; @endphp

        {{-- Table --}}
        <div class="flex-1 min-h-0 overflow-y-auto overflow-x-auto"
             wire:loading.class="opacity-40 pointer-events-none"
             wire:target="switchTab,search,statusFilter,sortBy,previousPage,nextPage">
            <table class="w-full border-collapse" style="min-width:600px;">
                <thead>
                    <tr class="sticky top-0 z-10" style="background:#f9fafb;border-bottom:2px solid #e5e7eb;">
                        <th class="px-5 py-3.5 text-left text-sm font-bold uppercase tracking-wider" style="color:#374151;">User</th>

                        @if($activeRole === 'alumni')
                            <th class="px-5 py-3.5 text-left text-sm font-bold uppercase tracking-wider" style="color:#374151;">Student ID</th>
                            <th class="px-5 py-3.5 text-left text-sm font-bold uppercase tracking-wider" style="color:#374151;">Email</th>
                        @elseif($activeRole === 'coordinator')
                            <th class="px-5 py-3.5 text-left text-sm font-bold uppercase tracking-wider" style="color:#374151;">Teacher ID</th>
                            <th class="px-5 py-3.5 text-left text-sm font-bold uppercase tracking-wider hidden md:table-cell" style="color:#374151;">College</th>
                            <th class="px-5 py-3.5 text-center text-sm font-bold uppercase tracking-wider" style="color:#374151;">Status</th>
                        @elseif($activeRole === 'registrar')
                            <th class="px-5 py-3.5 text-left text-sm font-bold uppercase tracking-wider hidden sm:table-cell" style="color:#374151;">Email</th>
                            <th class="px-5 py-3.5 text-center text-sm font-bold uppercase tracking-wider" style="color:#374151;">Status</th>
                        @elseif($activeRole === 'admin')
                            <th class="px-5 py-3.5 text-left text-sm font-bold uppercase tracking-wider hidden sm:table-cell" style="color:#374151;">Username</th>
                        @elseif($activeRole === 'director')
                            <th class="px-5 py-3.5 text-left text-sm font-bold uppercase tracking-wider hidden sm:table-cell" style="color:#374151;">Email</th>
                            <th class="px-5 py-3.5 text-center text-sm font-bold uppercase tracking-wider" style="color:#374151;">Status</th>
                        @else
                            <th class="px-5 py-3.5 text-left text-sm font-bold uppercase tracking-wider hidden sm:table-cell" style="color:#374151;">Email</th>
                            <th class="px-5 py-3.5 text-left text-sm font-bold uppercase tracking-wider" style="color:#374151;">Role</th>
                        @endif

                        <th class="px-5 py-3.5 text-center text-sm font-bold uppercase tracking-wider hidden lg:table-cell" style="color:#374151;">Joined</th>
                        <th class="px-5 py-3.5 text-center text-sm font-bold uppercase tracking-wider" style="color:#374151;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($pagedUsers as $u)
                    @php $rowStatus = $u->computed_status ?? $u->user_status ?? 'ACTIVE'; @endphp
                    <tr class="bg-white hover:bg-gray-50 transition-colors" style="border-bottom:1px solid #f3f4f6;">
                        <td class="px-5 py-3.5">
                            <div class="flex items-center gap-3">
                                <img src="{{ $this->photoUrl($u->photo ?? '') }}"
                                     alt="{{ $u->name }}"
                                     class="w-9 h-9 rounded-lg object-cover shrink-0 shadow-sm"
                                     style="border:1px solid #e5e7eb;">
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold truncate leading-tight" style="color:#111827;">
                                        @if($u->role === 'admin')
                                            {{ $this->adminUsername($u->email, $u->name) }}
                                        @else
                                            {{ $u->name }}
                                        @endif
                                    </p>
                                    @if($activeRole !== 'alumni' && $activeRole !== 'admin' && $activeRole !== 'director' && $activeRole !== 'coordinator' && $activeRole !== 'registrar')
                                    <p class="text-xs truncate sm:hidden mt-0.5" style="color:#9ca3af;">
                                        {{ $this->displayEmail($u->role, $u->email, $u->record_email ?? '') }}
                                    </p>
                                    @endif
                                </div>
                            </div>
                        </td>

                        @if($activeRole === 'alumni')
                            <td class="px-5 py-3.5">
                                <span class="font-mono text-sm font-bold" style="color:#111827;">
                                    {{ $u->student_id ?: '—' }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="text-sm" style="color:#374151;">
                                    {{ $this->displayEmail($u->role, $u->email, $u->record_email ?? '') }}
                                </span>
                            </td>
                        @elseif($activeRole === 'coordinator')
                            <td class="px-5 py-3.5">
                                <span class="font-mono text-sm font-bold" style="color:#111827;">{{ $u->id_number ?: '—' }}</span>
                            </td>
                            <td class="px-5 py-3.5 hidden md:table-cell">
                                <span class="text-sm" style="color:#374151;">{{ $u->department ?: '—' }}</span>
                            </td>
                            <td class="px-5 py-3.5 text-center">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold border {{ $this->statusBadge($rowStatus) }}">
                                    {{ $rowStatus }}
                                </span>
                            </td>
                        @elseif($activeRole === 'registrar')
                            <td class="px-5 py-3.5 hidden sm:table-cell">
                                <span class="text-sm" style="color:#4b5563;">
                                    {{ $this->displayEmail($u->role, $u->email, $u->record_email ?? '') }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-center">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold border {{ $this->statusBadge($rowStatus) }}">
                                    {{ $rowStatus }}
                                </span>
                            </td>
                        @elseif($activeRole === 'admin')
                            <td class="px-5 py-3.5 hidden sm:table-cell">
                                <span class="text-sm font-mono font-semibold" style="color:#4b5563;">
                                    {{ $this->adminUsername($u->email, $u->name) }}
                                </span>
                            </td>
                        @elseif($activeRole === 'director')
                            <td class="px-5 py-3.5 hidden sm:table-cell">
                                <span class="text-sm" style="color:#4b5563;">
                                    {{ $this->displayEmail($u->role, $u->email, $u->record_email ?? '') }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-center">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold border {{ $this->statusBadge($rowStatus) }}">
                                    {{ $rowStatus }}
                                </span>
                            </td>
                        @else
                            <td class="px-5 py-3.5 hidden sm:table-cell">
                                <span class="text-sm" style="color:#4b5563;">
                                    {{ $this->displayEmail($u->role, $u->email, $u->record_email ?? '') }}
                                </span>
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold border {{ $this->roleBadge($u->role) }}">
                                    {{ $this->roleLabel($u->role) }}
                                </span>
                            </td>
                        @endif

                        <td class="px-5 py-3.5 text-center hidden lg:table-cell">
                            <span class="text-sm font-medium" style="color:#6b7280;">
                                {{ \Carbon\Carbon::parse($u->created_at)->timezone('Asia/Manila')->format('M d, Y') }}
                            </span>
                        </td>

                        <td class="px-5 py-3.5">
                            <div class="flex items-center justify-center gap-1.5 flex-wrap">

                                {{-- VIEW --}}
                                <button wire:click="showProfile({{ $u->id }})"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-semibold border transition hover:opacity-80"
                                        style="background:#F5EEF9;color:#7A3F91;border-color:#d4aaeb;">
                                    <i class="fas fa-eye text-xs"></i>
                                    <span class="hidden sm:inline">View</span>
                                </button>

                                {{-- UPDATE EMAIL — alumni only --}}
                                @if($u->role === 'alumni')
                                <button wire:click="openUpdateEmail({{ $u->id }})"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-semibold border transition hover:opacity-80"
                                        style="background:#fff7ed;color:#c2410c;border-color:#fed7aa;">
                                    <i class="fas fa-envelope text-xs"></i>
                                    <span class="hidden lg:inline">Update Email</span>
                                </button>
                                @endif

                                {{-- CHANGE PASSWORD — registrar and admin only --}}
                                @if(in_array($u->role, ['registrar','admin']))
                                <button wire:click="openChangePassword({{ $u->id }})"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-semibold border transition hover:opacity-80"
                                        style="background:#fff7ed;color:#c2410c;border-color:#fed7aa;">
                                    <i class="fas fa-key text-xs"></i>
                                    <span class="hidden lg:inline">Password</span>
                                </button>
                                @endif

                                {{-- ACTIVATE / DEACTIVATE — director and registrar ONLY --}}
                                @if(in_array($u->role, ['director','registrar']))
                                @if($rowStatus === 'ACTIVE')
                                <button wire:click="confirmToggle({{ $u->id }},'deactivate')"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-semibold border transition hover:opacity-80"
                                        style="background:#fef2f2;color:#b91c1c;border-color:#fecaca;">
                                    <i class="fas fa-ban text-xs"></i>
                                    <span class="hidden lg:inline">Deactivate</span>
                                </button>
                                @else
                                <button wire:click="confirmToggle({{ $u->id }},'activate')"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-semibold border transition hover:opacity-80"
                                        style="background:#f0fdf4;color:#15803d;border-color:#bbf7d0;">
                                    <i class="fas fa-circle-check text-xs"></i>
                                    <span class="hidden lg:inline">Activate</span>
                                </button>
                                @endif
                                @endif

                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="py-20 text-center">
                            <div class="flex flex-col items-center gap-3">
                                <div class="w-16 h-16 rounded-full flex items-center justify-center" style="background:#f3f4f6;">
                                    <i class="fas fa-users text-2xl" style="color:#d1d5db;"></i>
                                </div>
                                <p class="font-semibold text-base" style="color:#9ca3af;">No users found</p>
                                <p class="text-sm" style="color:#d1d5db;">Try adjusting your search or filters</p>
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- ── PAGINATION ── --}}
        <div class="px-5 py-3 border-t shrink-0 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2"
             style="background:#7A3F91;border-color:#6A3080;">
            @php
                $tot  = $pagedUsers->total();
                $pp   = $pagedUsers->perPage();
                $cp   = $pagedUsers->currentPage();
                $from = $tot > 0 ? ($cp-1)*$pp+1 : 0;
                $to   = min($cp*$pp, $tot);
            @endphp
            <p class="text-white text-sm font-medium">
                Showing <strong>{{ $from }}–{{ $to }}</strong> of <strong>{{ $tot }}</strong> users
            </p>
            <div class="flex items-center gap-2">
                @if($pagedUsers->onFirstPage())
                    <button disabled class="px-4 py-1.5 rounded-lg text-sm font-semibold cursor-not-allowed"
                            style="background:rgba(255,255,255,.1);color:rgba(255,255,255,.35);">← Prev</button>
                @else
                    <button wire:click="previousPage"
                            class="px-4 py-1.5 rounded-lg text-sm font-semibold text-white transition hover:opacity-80"
                            style="background:rgba(255,255,255,.2);">← Prev</button>
                @endif
                <span class="px-4 py-1.5 text-sm font-bold rounded-lg" style="background:white;color:#7A3F91;">
                    {{ $cp }} / {{ $pagedUsers->lastPage() }}
                </span>
                @if($pagedUsers->hasMorePages())
                    <button wire:click="nextPage"
                            class="px-4 py-1.5 rounded-lg text-sm font-semibold text-white transition hover:opacity-80"
                            style="background:rgba(255,255,255,.2);">Next →</button>
                @else
                    <button disabled class="px-4 py-1.5 rounded-lg text-sm font-semibold cursor-not-allowed"
                            style="background:rgba(255,255,255,.1);color:rgba(255,255,255,.35);">Next →</button>
                @endif
            </div>
        </div>

    </div>
</div>

{{-- ═══════════════════════════════════════════
     MODALS
     ═══════════════════════════════════════════ --}}

{{-- ══════════════════════════════════════════════════════════
     CREATE DIRECTOR MODAL
     ══════════════════════════════════════════════════════════ --}}
@if($activeModal === 'createDirector')
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5 bg-black/50 backdrop-blur-sm overflow-y-auto"
     @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-xl my-4 overflow-hidden">

        {{-- Header --}}
        <div class="flex items-center justify-between px-6 py-5" style="background:#7A3F91;">
            <h2 class="text-white font-bold text-xl flex items-center gap-2.5">
                <i class="fas fa-user-tie text-lg"></i> Create New Director
            </h2>
            <button wire:click="closeModal" class="text-white/60 hover:text-white text-2xl transition">
                <i class="fas fa-xmark"></i>
            </button>
        </div>

        {{-- Success state --}}
        @if($dOk)
        @php $parts = explode('|', $dOk); @endphp
        <div class="p-6">
            <div class="p-5 rounded-xl border bg-green-50 border-green-200 mb-5">
                <div class="flex items-start gap-3">
                    <i class="fas fa-circle-check text-green-500 mt-0.5 shrink-0 text-xl"></i>
                    <div class="space-y-2">
                        @foreach($parts as $part)
                        <p class="text-base text-green-800">{!! $part !!}</p>
                        @endforeach
                    </div>
                </div>
            </div>
            <button wire:click="closeModal"
                    class="w-full py-3 rounded-xl text-base font-bold text-white transition hover:opacity-90"
                    style="background:#7A3F91;">Done</button>
        </div>
        @endif

        {{-- Validation errors --}}
        @if(count($dErrs))
        <div class="mx-6 mt-5 p-4 rounded-xl bg-red-50 border border-red-200 space-y-1.5">
            @foreach($dErrs as $msgs)
                @foreach($msgs as $msg)
                <p class="text-base text-red-700 flex items-start gap-2">
                    <i class="fas fa-circle-exclamation shrink-0 mt-0.5"></i>
                    <span>{{ $msg }}</span>
                </p>
                @endforeach
            @endforeach
        </div>
        @endif

        {{-- Form --}}
        @if(!$dOk)
        <form wire:submit="createDirector" class="p-6 space-y-6">

            {{-- Full Name --}}
            <div>
                <label class="block text-base font-bold mb-3" style="color:#1a1a1a;">
                    Full Name <span class="text-red-500">*</span>
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <input wire:model.defer="dFn" type="text" placeholder="First Name"
                               class="w-full px-4 py-3 border rounded-xl text-base bg-white focus:outline-none focus:ring-2 focus:ring-[#7A3F91]/15 focus:border-[#7A3F91] transition"
                               style="border-color:#d1d5db;color:#111;">
                        <p class="text-sm mt-1.5 font-semibold" style="color:#9ca3af;">First Name <span class="text-red-400">*</span></p>
                    </div>
                    <div>
                        <input wire:model.defer="dLn" type="text" placeholder="Last Name"
                               class="w-full px-4 py-3 border rounded-xl text-base bg-white focus:outline-none focus:ring-2 focus:ring-[#7A3F91]/15 focus:border-[#7A3F91] transition"
                               style="border-color:#d1d5db;color:#111;">
                        <p class="text-sm mt-1.5 font-semibold" style="color:#9ca3af;">Last Name <span class="text-red-400">*</span></p>
                    </div>
                    <div>
                        <input wire:model.defer="dMn" type="text" placeholder="Middle Name"
                               class="w-full px-4 py-3 border rounded-xl text-base bg-white focus:outline-none focus:ring-2 focus:ring-[#7A3F91]/15 focus:border-[#7A3F91] transition"
                               style="border-color:#d1d5db;color:#111;">
                        <p class="text-sm mt-1.5 font-semibold" style="color:#9ca3af;">Middle Name <span class="text-red-400">*</span></p>
                    </div>
                    <div>
                        <input wire:model.defer="dSfx" type="text" placeholder="e.g. Jr., Sr., III"
                               class="w-full px-4 py-3 border rounded-xl text-base bg-white focus:outline-none focus:ring-2 focus:ring-[#7A3F91]/15 focus:border-[#7A3F91] transition"
                               style="border-color:#d1d5db;color:#111;">
                        <p class="text-sm mt-1.5 font-semibold" style="color:#9ca3af;">Suffix <span class="text-gray-300 text-xs font-normal">(optional)</span></p>
                    </div>
                </div>
            </div>

            {{-- Username --}}
            <div>
                <label class="block text-base font-bold mb-2" style="color:#1a1a1a;">
                    Username <span class="text-red-500">*</span>
                    <span class="ml-1.5 text-sm font-normal" style="color:#9ca3af;">(used to log in)</span>
                </label>
                <input wire:model.defer="dUsername" type="text" placeholder="e.g. jdelacruz2024"
                       class="w-full px-4 py-3 border rounded-xl text-base bg-white focus:outline-none focus:ring-2 focus:ring-[#7A3F91]/15 focus:border-[#7A3F91] transition"
                       style="border-color:#d1d5db;color:#111;" autocomplete="off">
            </div>

            {{-- Email --}}
            <div>
                <label class="block text-base font-bold mb-2" style="color:#1a1a1a;">
                    Email Address <span class="text-red-500">*</span>
                    <span class="ml-1.5 text-sm font-normal" style="color:#9ca3af;">(for records &amp; credentials)</span>
                </label>
                <div class="relative">
                    <i class="fas fa-envelope absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none"></i>
                    <input wire:model.defer="dEmail" type="email" placeholder="e.g. jdelacruz@email.com"
                           class="w-full pl-10 pr-4 py-3 border rounded-xl text-base bg-white focus:outline-none focus:ring-2 focus:ring-[#7A3F91]/15 focus:border-[#7A3F91] transition"
                           style="border-color:#d1d5db;color:#111;" autocomplete="off">
                </div>
                <div class="mt-3 p-3.5 rounded-xl flex items-start gap-2.5" style="background:#fffbeb;border:1px solid #fde68a;">
                    <i class="fas fa-circle-info text-amber-500 mt-0.5 shrink-0"></i>
                    <p class="text-sm font-medium leading-snug" style="color:#92400e;">
                        A secure password will be <strong>auto-generated</strong> and sent to this email.
                        The director logs in using their <strong>username</strong>.
                    </p>
                </div>
            </div>

            {{-- Actions --}}
            <div class="flex gap-3 pt-1">
                <button type="button" wire:click="closeModal"
                        class="flex-1 px-5 py-3 rounded-xl text-base font-bold border transition hover:bg-gray-50"
                        style="color:#374151;border-color:#d1d5db;">Cancel</button>
                <button type="submit" wire:loading.attr="disabled" wire:target="createDirector"
                        class="flex-1 px-5 py-3 rounded-xl text-base font-bold text-white transition hover:opacity-90 flex items-center justify-center gap-2"
                        style="background:#7A3F91;">
                    <span wire:loading wire:target="createDirector">
                        <i class="fas fa-spinner animate-spin"></i> Creating…
                    </span>
                    <span wire:loading.remove wire:target="createDirector">
                        <i class="fas fa-user-tie"></i> Create Director
                    </span>
                </button>
            </div>
        </form>
        @endif
    </div>
</div>
@endif

{{-- ══════════════════════════════════════════════════════════
     VIEW PROFILE MODAL
     ══════════════════════════════════════════════════════════ --}}
@if($activeModal === 'viewProfile' && $vData)
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5 bg-black/50 backdrop-blur-sm overflow-y-auto"
     @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-5xl my-4 overflow-hidden">

        {{-- Header --}}
        <div class="flex items-center justify-between px-6 py-4" style="background:#7A3F91;">
            <h2 class="text-white font-bold text-lg flex items-center gap-2">
                <i class="fas fa-id-card"></i> User Profile
            </h2>
            <button wire:click="closeModal" class="text-white/60 hover:text-white text-xl transition">
                <i class="fas fa-xmark"></i>
            </button>
        </div>

        <div class="p-5 space-y-4 overflow-y-auto" style="max-height:85vh;">

            {{-- ── Avatar + quick info ── --}}
            <div class="flex items-center gap-4 p-4 rounded-xl" style="background:#f9fafb;border:1px solid #e5e7eb;">
                @if($vPhoto)
                    <img src="{{ $vPhoto->temporaryUrl() }}"
                         alt="Preview"
                         class="w-16 h-16 rounded-xl object-cover shadow shrink-0"
                         style="border:2px solid #7A3F91;">
                @else
                    <img src="{{ $this->photoUrl($vData['photo'] ?? '') }}"
                         alt="{{ $vData['name'] }}"
                         class="w-16 h-16 rounded-xl object-cover shadow shrink-0"
                         style="border:2px solid #e5e7eb;">
                @endif
                <div>
                    @if($vData['role'] === 'director')
                        <p class="font-bold text-xl leading-tight" style="color:#111827;">
                            {{ implode(' ', array_filter([
                                $vData['first_name'] ?? '',
                                $vData['middle_name'] ?? '',
                                $vData['last_name'] ?? '',
                                $vData['suffix'] ?? '',
                            ])) ?: $vData['name'] }}
                        </p>
                    @elseif($vData['role'] === 'alumni')
                        @php
                            $alumniFullName = implode(' ', array_filter([
                                $vData['alumni_first_name'] ?? '',
                                $vData['alumni_middle_name'] ?? '',
                                $vData['alumni_last_name'] ?? '',
                                $vData['alumni_suffix'] ?? '',
                            ]));
                        @endphp
                        <p class="font-bold text-xl leading-tight" style="color:#111827;">
                            {{ $alumniFullName ?: $vData['name'] }}
                        </p>
                    @elseif($vData['role'] === 'admin')
                        <p class="font-bold text-xl leading-tight" style="color:#111827;">
                            {{ $this->adminUsername($vData['email'], $vData['name']) }}
                        </p>
                    @else
                        <p class="font-bold text-xl leading-tight" style="color:#111827;">{{ $vData['name'] }}</p>
                    @endif

                    <p class="text-sm mt-1" style="color:#6b7280;">
                        @if($vData['role'] === 'alumni' && !empty($vData['record_email']) && !str_contains($vData['record_email'],'@pending.local'))
                            {{ $vData['record_email'] }}
                        @elseif($vData['role'] === 'director' && !empty($vData['director_email']))
                            {{ $vData['director_email'] }}
                        @elseif(!str_ends_with($vData['email'] ?? '','@director.internal') && !str_ends_with($vData['email'] ?? '','@registrar.internal'))
                            {{ $vData['email'] }}
                        @endif
                    </p>

                    <div class="flex flex-wrap gap-1.5 mt-2">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold border {{ $this->roleBadge($vData['role']) }}">
                            {{ $this->roleLabel($vData['role']) }}
                        </span>
                        @if($vData['role'] !== 'alumni')
                        <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold border {{ $this->statusBadge($vData['user_status']) }}">
                            {{ $vData['user_status'] }}
                        </span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- ── UPLOAD PROFILE PHOTO ── --}}
            @if(in_array($vData['role'], ['alumni','organizer','director','registrar']))
            <div class="rounded-xl overflow-hidden" style="border:1px solid #e9d5f3;">
                <div class="px-4 py-3 border-b flex items-center gap-2" style="background:#f5eef9;border-color:#e9d5f3;">
                    <i class="fas fa-camera text-sm" style="color:#7A3F91;"></i>
                    <p class="text-sm font-bold uppercase tracking-wide" style="color:#374151;">Update Profile Photo</p>
                </div>
                <div class="p-4">
                    <div class="flex flex-col sm:flex-row items-start sm:items-center gap-4">
                        <div class="flex-1 w-full"
                             x-data="{ dragging: false }"
                             @dragover.prevent="dragging=true"
                             @dragleave.prevent="dragging=false"
                             @drop.prevent="dragging=false; $wire.upload('vPhoto', $event.dataTransfer.files[0])">
                            <label for="vPhotoInput"
                                   :class="dragging ? 'border-[#7A3F91] bg-purple-50' : 'border-gray-200 bg-gray-50 hover:border-[#7A3F91] hover:bg-purple-50'"
                                   class="flex flex-col items-center justify-center gap-2 px-4 py-5 rounded-xl border-2 border-dashed cursor-pointer transition-all duration-150 w-full">
                                <div class="w-10 h-10 rounded-full flex items-center justify-center" style="background:#ede9f6;">
                                    <i class="fas fa-cloud-arrow-up text-lg" style="color:#7A3F91;"></i>
                                </div>
                                <div class="text-center">
                                    <p class="text-sm font-bold" style="color:#374151;">
                                        Click to browse or drag &amp; drop
                                    </p>
                                    <p class="text-xs mt-0.5" style="color:#9ca3af;">JPG, PNG, GIF, WEBP — max 2 MB</p>
                                </div>
                                <input id="vPhotoInput" type="file" wire:model="vPhoto" accept="image/*" class="hidden">
                            </label>

                            <div wire:loading wire:target="vPhoto" class="mt-2 flex items-center gap-2 text-sm" style="color:#7A3F91;">
                                <i class="fas fa-spinner animate-spin text-xs"></i>
                                <span class="font-medium">Uploading…</span>
                            </div>

                            @error('vPhoto')
                            <p class="mt-2 text-sm text-red-600 flex items-center gap-1.5">
                                <i class="fas fa-circle-exclamation text-xs"></i>{{ $message }}
                            </p>
                            @enderror
                        </div>

                        @if($vPhoto)
                        <div class="flex flex-col items-center gap-2 shrink-0">
                            <div class="w-20 h-20 rounded-xl overflow-hidden shadow-md" style="border:2px solid #7A3F91;">
                                <img src="{{ $vPhoto->temporaryUrl() }}" class="w-full h-full object-cover" alt="Preview">
                            </div>
                            <button wire:click="savePhoto"
                                    wire:loading.attr="disabled"
                                    wire:target="savePhoto"
                                    class="w-full px-4 py-2 rounded-lg text-sm font-bold text-white transition hover:opacity-90 flex items-center justify-center gap-1.5"
                                    style="background:#7A3F91;">
                                <span wire:loading wire:target="savePhoto">
                                    <i class="fas fa-spinner animate-spin text-xs"></i> Saving…
                                </span>
                                <span wire:loading.remove wire:target="savePhoto">
                                    <i class="fas fa-check text-xs"></i> Save Photo
                                </span>
                            </button>
                            <button wire:click="$set('vPhoto', null)"
                                    class="w-full px-4 py-1.5 rounded-lg text-xs font-semibold border transition hover:bg-gray-50"
                                    style="color:#6b7280;border-color:#e5e7eb;">
                                <i class="fas fa-xmark mr-1 text-xs"></i>Cancel
                            </button>
                        </div>
                        @endif
                    </div>
                </div>
            </div>
            @endif

            {{-- ── ALUMNI: Two-column layout ── --}}
            @if($vData['role'] === 'alumni')
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

                {{-- Personal Information --}}
                <div class="rounded-xl overflow-hidden" style="border:1px solid #bfdbfe;">
                    <div class="px-4 py-3 border-b flex items-center gap-2" style="background:#eff6ff;border-color:#bfdbfe;">
                        <i class="fas fa-user text-sm text-blue-600"></i>
                        <p class="text-sm font-bold uppercase tracking-wide" style="color:#374151;">Personal Information</p>
                    </div>
                    <div class="p-4 grid grid-cols-2 gap-3">
                        <div class="rounded-lg p-3" style="background:#f9fafb;border:1px solid #e5e7eb;">
                            <p class="text-xs font-bold uppercase tracking-wide mb-1" style="color:#9ca3af;">First Name</p>
                            <p class="text-sm font-semibold" style="color:#111827;">
                                {{ !empty($vData['alumni_first_name']) ? $vData['alumni_first_name'] : '—' }}
                            </p>
                        </div>
                        <div class="rounded-lg p-3" style="background:#f9fafb;border:1px solid #e5e7eb;">
                            <p class="text-xs font-bold uppercase tracking-wide mb-1" style="color:#9ca3af;">Middle Initial</p>
                            <p class="text-sm font-semibold" style="color:#111827;">
                                {{ !empty($vData['alumni_middle_name']) ? $vData['alumni_middle_name'] : '—' }}
                            </p>
                        </div>
                        <div class="rounded-lg p-3" style="background:#f9fafb;border:1px solid #e5e7eb;">
                            <p class="text-xs font-bold uppercase tracking-wide mb-1" style="color:#9ca3af;">Last Name</p>
                            <p class="text-sm font-semibold" style="color:#111827;">
                                {{ !empty($vData['alumni_last_name']) ? $vData['alumni_last_name'] : '—' }}
                            </p>
                        </div>
                        <div class="rounded-lg p-3" style="background:#f9fafb;border:1px solid #e5e7eb;">
                            <p class="text-xs font-bold uppercase tracking-wide mb-1" style="color:#9ca3af;">Suffix</p>
                            <p class="text-sm font-semibold" style="color:#111827;">
                                {{ !empty($vData['alumni_suffix']) ? $vData['alumni_suffix'] : '—' }}
                            </p>
                        </div>
                        <div class="col-span-2 rounded-lg p-3" style="background:#f9fafb;border:1px solid #e5e7eb;">
                            <p class="text-xs font-bold uppercase tracking-wide mb-1" style="color:#9ca3af;">Email Address</p>
                            <p class="text-sm font-semibold break-all" style="color:#111827;">
                                @if(!empty($vData['record_email']) && !str_contains($vData['record_email'],'@pending.local'))
                                    {{ $vData['record_email'] }}
                                @else
                                    <span style="color:#9ca3af;">—</span>
                                @endif
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Student Record --}}
                <div class="rounded-xl overflow-hidden" style="border:1px solid #bfdbfe;">
                    <div class="px-4 py-3 border-b flex items-center gap-2" style="background:#eff6ff;border-color:#bfdbfe;">
                        <i class="fas fa-graduation-cap text-sm text-blue-600"></i>
                        <p class="text-sm font-bold uppercase tracking-wide" style="color:#374151;">Student Record</p>
                    </div>
                    <div class="p-4 grid grid-cols-2 gap-3">
                        <div class="rounded-lg p-3" style="background:#f9fafb;border:1px solid #e5e7eb;">
                            <p class="text-xs font-bold uppercase tracking-wide mb-1" style="color:#9ca3af;">Student ID</p>
                            <p class="text-sm font-bold font-mono" style="color:#111827;">
                                {{ !empty($vData['student_id']) ? $vData['student_id'] : '—' }}
                            </p>
                        </div>
                        <div class="rounded-lg p-3" style="background:#f9fafb;border:1px solid #e5e7eb;">
                            <p class="text-xs font-bold uppercase tracking-wide mb-1" style="color:#9ca3af;">Batch</p>
                            <p class="text-sm font-semibold" style="color:#111827;">{{ $vData['batch'] ?: '—' }}</p>
                        </div>
                        <div class="rounded-lg p-3" style="background:#f9fafb;border:1px solid #e5e7eb;">
                            <p class="text-xs font-bold uppercase tracking-wide mb-1" style="color:#9ca3af;">Course Code</p>
                            <p class="text-sm font-semibold" style="color:#111827;">
                                {{ !empty($vData['course_code']) ? $vData['course_code'] : '—' }}
                            </p>
                        </div>
                        <div class="rounded-lg p-3" style="background:#f9fafb;border:1px solid #e5e7eb;">
                            <p class="text-xs font-bold uppercase tracking-wide mb-1" style="color:#9ca3af;">Role</p>
                            <p class="text-sm font-semibold" style="color:#111827;">{{ $this->roleLabel($vData['role']) }}</p>
                        </div>
                        @if(!empty($vData['course_name']))
                        <div class="col-span-2 rounded-lg p-3" style="background:#f9fafb;border:1px solid #e5e7eb;">
                            <p class="text-xs font-bold uppercase tracking-wide mb-1" style="color:#9ca3af;">Course</p>
                            <p class="text-sm font-semibold" style="color:#111827;">{{ $vData['course_name'] }}</p>
                        </div>
                        @endif
                        <div class="col-span-2 rounded-lg p-3" style="background:#f9fafb;border:1px solid #e5e7eb;">
                            <p class="text-xs font-bold uppercase tracking-wide mb-1" style="color:#9ca3af;">Joined</p>
                            <p class="text-sm font-semibold" style="color:#111827;">
                                {{ \Carbon\Carbon::parse($vData['created_at'])->timezone('Asia/Manila')->format('M d, Y') }}
                            </p>
                        </div>
                    </div>
                </div>

            </div>
            @endif

            {{-- ── DIRECTOR NAME DETAILS ── --}}
            @if($vData['role'] === 'director')
            <div class="rounded-xl overflow-hidden" style="border:1px solid #e9d5f3;">
                <div class="px-4 py-3 border-b flex items-center gap-2" style="background:#f5eef9;border-color:#e9d5f3;">
                    <i class="fas fa-shield-halved text-sm" style="color:#7A3F91;"></i>
                    <p class="text-sm font-bold uppercase tracking-wide" style="color:#374151;">Director Name Details</p>
                </div>
                <div class="p-4 grid grid-cols-3 gap-3">
                    <div class="rounded-lg p-3" style="background:#f9fafb;border:1px solid #e5e7eb;">
                        <p class="text-xs font-bold uppercase tracking-wide mb-1" style="color:#9ca3af;">First Name</p>
                        <p class="text-sm font-semibold" style="color:#111827;">
                            {{ !empty($vData['first_name']) ? $vData['first_name'] : '—' }}
                        </p>
                    </div>
                    <div class="rounded-lg p-3" style="background:#f9fafb;border:1px solid #e5e7eb;">
                        <p class="text-xs font-bold uppercase tracking-wide mb-1" style="color:#9ca3af;">Middle Name</p>
                        <p class="text-sm font-semibold" style="color:#111827;">
                            {{ !empty($vData['middle_name']) ? $vData['middle_name'] : '—' }}
                        </p>
                    </div>
                    <div class="rounded-lg p-3" style="background:#f9fafb;border:1px solid #e5e7eb;">
                        <p class="text-xs font-bold uppercase tracking-wide mb-1" style="color:#9ca3af;">Last Name</p>
                        <p class="text-sm font-semibold" style="color:#111827;">
                            {{ !empty($vData['last_name']) ? $vData['last_name'] : '—' }}
                        </p>
                    </div>
                    <div class="rounded-lg p-3" style="background:#f9fafb;border:1px solid #e5e7eb;">
                        <p class="text-xs font-bold uppercase tracking-wide mb-1" style="color:#9ca3af;">Suffix</p>
                        <p class="text-sm font-semibold" style="color:#111827;">
                            {{ !empty($vData['suffix']) ? $vData['suffix'] : '—' }}
                        </p>
                    </div>
                    <div class="col-span-2 rounded-lg p-3" style="background:#f9fafb;border:1px solid #e5e7eb;">
                        <p class="text-xs font-bold uppercase tracking-wide mb-1" style="color:#9ca3af;">Email Address</p>
                        <p class="text-sm font-semibold break-all" style="color:#111827;">
                            {{ !empty($vData['director_email']) ? $vData['director_email'] : '—' }}
                        </p>
                    </div>
                </div>
            </div>
            @endif

            {{-- ── ACCOUNT DETAILS (non-alumni) ── --}}
            @if($vData['role'] !== 'alumni')
            <div class="rounded-xl overflow-hidden" style="border:1px solid #e5e7eb;">
                <div class="px-4 py-3 border-b flex items-center gap-2" style="background:#f9fafb;border-color:#e5e7eb;">
                    <i class="fas fa-circle-info text-sm" style="color:#7A3F91;"></i>
                    <p class="text-sm font-bold uppercase tracking-wide" style="color:#374151;">Account Details</p>
                </div>
                <div class="p-4 grid grid-cols-3 gap-3">
                    <div class="rounded-lg p-3" style="background:#f9fafb;border:1px solid #e5e7eb;">
                        <p class="text-xs font-bold uppercase tracking-wide mb-1" style="color:#9ca3af;">Role</p>
                        <p class="text-sm font-semibold" style="color:#111827;">{{ $this->roleLabel($vData['role']) }}</p>
                    </div>
                    <div class="rounded-lg p-3" style="background:#f9fafb;border:1px solid #e5e7eb;">
                        <p class="text-xs font-bold uppercase tracking-wide mb-1" style="color:#9ca3af;">Joined</p>
                        <p class="text-sm font-semibold" style="color:#111827;">
                            {{ \Carbon\Carbon::parse($vData['created_at'])->timezone('Asia/Manila')->format('M d, Y') }}
                        </p>
                    </div>
                    <div class="rounded-lg p-3" style="background:#f9fafb;border:1px solid #e5e7eb;">
                        <p class="text-xs font-bold uppercase tracking-wide mb-1" style="color:#9ca3af;">Status</p>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold border {{ $this->statusBadge($vData['user_status']) }}">
                            {{ $vData['user_status'] }}
                        </span>
                    </div>
                    @if(in_array($vData['role'], ['director','registrar']) && str_ends_with($vData['email'] ?? '', '.internal'))
                    <div class="rounded-lg p-3" style="background:#f9fafb;border:1px solid #e5e7eb;">
                        <p class="text-xs font-bold uppercase tracking-wide mb-1" style="color:#9ca3af;">Username</p>
                        <p class="text-sm font-semibold font-mono" style="color:#111827;">
                            {{ str_replace(['@director.internal','@registrar.internal'], '', $vData['email']) }}
                        </p>
                    </div>
                    @endif
                    @if($vData['role'] === 'admin')
                    <div class="rounded-lg p-3" style="background:#f9fafb;border:1px solid #e5e7eb;">
                        <p class="text-xs font-bold uppercase tracking-wide mb-1" style="color:#9ca3af;">Username</p>
                        <p class="text-sm font-semibold font-mono" style="color:#111827;">
                            {{ $this->adminUsername($vData['email'], $vData['name']) }}
                        </p>
                    </div>
                    @endif
                </div>
            </div>
            @endif

            {{-- ── COORDINATOR RECORD ── --}}
            @if($vData['role'] === 'organizer' && !empty($vData['id_number']))
            <div class="rounded-xl overflow-hidden" style="border:1px solid #e9d5f3;">
                <div class="px-4 py-3 border-b flex items-center gap-2" style="background:#f5eef9;border-color:#e9d5f3;">
                    <i class="fas fa-users-gear text-sm" style="color:#7A3F91;"></i>
                    <p class="text-sm font-bold uppercase tracking-wide" style="color:#374151;">Coordinator Details</p>
                </div>
                <div class="p-4 grid grid-cols-2 gap-3">
                    <div class="rounded-lg p-3" style="background:#f9fafb;border:1px solid #e5e7eb;">
                        <p class="text-xs font-bold uppercase tracking-wide mb-1" style="color:#9ca3af;">Teacher ID</p>
                        <p class="text-sm font-bold font-mono" style="color:#111827;">{{ $vData['id_number'] }}</p>
                    </div>
                    <div class="rounded-lg p-3" style="background:#f5eef9;border:1px solid #e9d5f3;">
                        <p class="text-xs font-bold uppercase tracking-wide mb-1" style="color:#7A3F91;">College / Dept</p>
                        <p class="text-sm font-semibold" style="color:#5e2f72;">{{ $vData['department'] ?: '—' }}</p>
                    </div>
                </div>
            </div>
            @endif

            <button wire:click="closeModal"
                    class="w-full py-2.5 rounded-xl text-sm font-bold border transition hover:bg-gray-50"
                    style="color:#374151;border-color:#d1d5db;">Close</button>
        </div>
    </div>
</div>
@endif

{{-- ══════════════════════════════════════════════════════════
     UPDATE ALUMNI EMAIL MODAL
     ══════════════════════════════════════════════════════════ --}}
@if($activeModal === 'updateAlumniEmail' && $ueId)
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5 bg-black/50 backdrop-blur-sm"
     @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg overflow-hidden">

        {{-- Header --}}
        <div class="flex items-center justify-between px-6 py-5" style="background:#c2410c;">
            <h2 class="text-white font-bold text-xl flex items-center gap-2.5">
                <i class="fas fa-envelope-open-text text-lg"></i> Update Email Address
            </h2>
            <button wire:click="closeModal" class="text-white/60 hover:text-white text-2xl transition">
                <i class="fas fa-xmark"></i>
            </button>
        </div>

        {{-- Alumni chip --}}
        <div class="px-6 pt-5">
            <div class="flex items-center gap-3 p-4 rounded-xl" style="background:#fff7ed;border:1px solid #fed7aa;">
                <i class="fas fa-user-graduate text-orange-600 text-lg shrink-0"></i>
                <div>
                    <p class="text-base font-bold leading-tight" style="color:#9a3412;">{{ $ueName }}</p>
                    <p class="text-sm font-semibold mt-0.5" style="color:#b45309;">Alumni Account</p>
                </div>
            </div>
        </div>

        {{-- Validation Errors --}}
        @if(count($ueErrors))
        <div class="mx-6 mt-4 p-4 rounded-xl bg-red-50 border border-red-200 space-y-2">
            @foreach($ueErrors as $msgs)
                @foreach($msgs as $msg)
                <p class="text-base font-semibold text-red-700 flex items-start gap-2.5 leading-snug">
                    <i class="fas fa-circle-exclamation shrink-0 mt-0.5 text-base"></i>
                    <span>{{ $msg }}</span>
                </p>
                @endforeach
            @endforeach
        </div>
        @endif

        <div class="p-6 space-y-5">

            {{-- Input --}}
            <div>
                <label class="block text-base font-bold mb-2.5" style="color:#1a1a1a;">
                    New Email Address <span class="text-red-500">*</span>
                </label>
                <div class="relative">
                    <i class="fas fa-envelope absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none"></i>
                    <input wire:model.defer="ueEmail" type="email"
                           placeholder="e.g. alumni@email.com"
                           class="w-full pl-11 pr-4 py-3.5 border rounded-xl text-base bg-white focus:outline-none focus:ring-2 focus:ring-orange-500/15 focus:border-orange-500 transition"
                           style="border-color:#d1d5db;color:#111;" autocomplete="off">
                </div>
            </div>

            {{-- Warning boxes --}}
            <div class="space-y-3">
                <div class="flex items-start gap-3 p-4 rounded-xl" style="background:#fef2f2;border:1px solid #fecaca;">
                    <i class="fas fa-triangle-exclamation text-red-500 text-base mt-0.5 shrink-0"></i>
                    <p class="text-sm font-semibold leading-snug" style="color:#991b1b;">
                        This will overwrite the current email address on file for this alumni.
                    </p>
                </div>
                <div class="flex items-start gap-3 p-4 rounded-xl" style="background:#fffbeb;border:1px solid #fde68a;">
                    <i class="fas fa-key text-amber-500 text-base mt-0.5 shrink-0"></i>
                    <p class="text-sm font-semibold leading-snug" style="color:#92400e;">
                        The alumni's password will be <strong>reset</strong> and they will be required to set a new one on their next login.
                    </p>
                </div>
            </div>

            {{-- Actions --}}
            <div class="flex gap-3 pt-1">
                <button type="button" wire:click="closeModal"
                        class="flex-1 px-5 py-3 rounded-xl text-base font-bold border transition hover:bg-gray-50"
                        style="color:#374151;border-color:#d1d5db;">
                    <i class="fas fa-xmark mr-1.5"></i>Cancel
                </button>
                <button wire:click="saveUpdateEmail"
                        wire:loading.attr="disabled"
                        wire:target="saveUpdateEmail"
                        class="flex-1 px-5 py-3 rounded-xl text-base font-bold text-white transition hover:opacity-90 flex items-center justify-center gap-2"
                        style="background:#c2410c;">
                    <span wire:loading wire:target="saveUpdateEmail">
                        <i class="fas fa-spinner animate-spin"></i> Updating…
                    </span>
                    <span wire:loading.remove wire:target="saveUpdateEmail">
                        <i class="fas fa-check mr-1"></i>Confirm Update
                    </span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ══════════════════════════════════════════════════════════
     CHANGE PASSWORD MODAL
     ══════════════════════════════════════════════════════════ --}}
@if($activeModal === 'changePassword' && $cpId)
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5 bg-black/50 backdrop-blur-sm"
     @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">

        {{-- Header --}}
        <div class="flex items-center justify-between px-6 py-5" style="background:#c2410c;">
            <h2 class="text-white font-bold text-xl flex items-center gap-2.5">
                <i class="fas fa-key text-lg"></i> Change Password
            </h2>
            <button wire:click="closeModal" class="text-white/60 hover:text-white text-2xl transition">
                <i class="fas fa-xmark"></i>
            </button>
        </div>

        {{-- User chip --}}
        <div class="px-6 pt-5 pb-2">
            <div class="flex items-center gap-3 p-4 rounded-xl" style="background:#fff7ed;border:1px solid #fed7aa;">
                <i class="fas fa-user text-orange-600 text-lg shrink-0"></i>
                <p class="text-base font-bold" style="color:#9a3412;">{{ $cpName }}</p>
            </div>
        </div>

        {{-- Errors --}}
        @if(count($cpErrs))
        <div class="mx-6 mt-3 p-4 rounded-xl bg-red-50 border border-red-200 space-y-2">
            @foreach($cpErrs as $msgs)
                @foreach($msgs as $msg)
                <p class="text-base font-semibold text-red-700 flex items-start gap-2.5 leading-snug">
                    <i class="fas fa-circle-exclamation shrink-0 mt-0.5 text-base"></i>
                    <span>{{ $msg }}</span>
                </p>
                @endforeach
            @endforeach
        </div>
        @endif

        <div class="p-6 space-y-5">

            {{-- Warning box --}}
            <div class="flex items-start gap-3 p-4 rounded-xl" style="background:#fffbeb;border:1px solid #fde68a;">
                <i class="fas fa-rotate-left text-amber-500 text-base mt-0.5 shrink-0"></i>
                <p class="text-sm font-semibold leading-snug" style="color:#92400e;">
                    After saving, this user will be required to <strong>change their password</strong> on their next login.
                </p>
            </div>

            <div>
                <label class="block text-base font-bold mb-2.5" style="color:#1a1a1a;">
                    New Password <span class="text-red-500">*</span>
                </label>
                <input wire:model.defer="cpNew" type="password" placeholder="Min. 8 characters"
                       class="w-full px-4 py-3.5 border rounded-xl text-base bg-white focus:outline-none focus:ring-2 focus:ring-orange-500/15 focus:border-orange-500 transition"
                       style="border-color:#d1d5db;color:#111;" autocomplete="new-password">
            </div>

            <div>
                <label class="block text-base font-bold mb-2.5" style="color:#1a1a1a;">
                    Confirm New Password <span class="text-red-500">*</span>
                </label>
                <input wire:model.defer="cpConfirm" type="password" placeholder="Repeat new password"
                       class="w-full px-4 py-3.5 border rounded-xl text-base bg-white focus:outline-none focus:ring-2 focus:ring-orange-500/15 focus:border-orange-500 transition"
                       style="border-color:#d1d5db;color:#111;" autocomplete="new-password">
            </div>

            <div class="flex gap-3 pt-1">
                <button type="button" wire:click="closeModal"
                        class="flex-1 px-5 py-3 rounded-xl text-base font-bold border transition hover:bg-gray-50"
                        style="color:#374151;border-color:#d1d5db;">Cancel</button>
                <button wire:click="saveChangePassword"
                        wire:loading.attr="disabled"
                        wire:target="saveChangePassword"
                        class="flex-1 px-5 py-3 rounded-xl text-base font-bold text-white transition hover:opacity-90 flex items-center justify-center gap-2"
                        style="background:#c2410c;">
                    <span wire:loading wire:target="saveChangePassword">
                        <i class="fas fa-spinner animate-spin"></i> Saving…
                    </span>
                    <span wire:loading.remove wire:target="saveChangePassword">
                        <i class="fas fa-key"></i> Update Password
                    </span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ══════════════════════════════════════════════════════════
     TOGGLE CONFIRM MODAL
     ══════════════════════════════════════════════════════════ --}}
@if($activeModal === 'toggleConfirm' && $tId)
<div class="fixed inset-0 z-50 flex items-center justify-center p-3 sm:p-5 bg-black/50 backdrop-blur-sm"
     @keydown.escape.window="$wire.closeModal()">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden">
        <div class="p-6">
            <div class="flex items-center gap-4 mb-4">
                <div class="w-12 h-12 rounded-full flex items-center justify-center shrink-0
                            {{ $tAction==='deactivate' ? 'bg-red-100' : 'bg-green-100' }}">
                    <i class="{{ $tAction==='deactivate' ? 'fas fa-ban text-red-600 text-lg' : 'fas fa-circle-check text-green-600 text-lg' }}"></i>
                </div>
                <div>
                    <p class="font-bold text-base" style="color:#111827;">
                        {{ $tAction==='deactivate' ? 'Deactivate' : 'Activate' }} User?
                    </p>
                    <p class="text-sm mt-0.5 font-medium" style="color:#6b7280;">{{ $tName }}</p>
                </div>
            </div>
            <p class="text-sm mb-5 leading-relaxed" style="color:#4b5563;">
                @if($tAction==='deactivate')
                    This user will be marked as <strong>Inactive</strong>. You can reactivate them anytime.
                @else
                    This user will be marked as <strong>Active</strong> and regain system access.
                @endif
            </p>
            <div class="flex gap-3">
                <button wire:click="closeModal"
                        class="flex-1 px-4 py-2.5 rounded-xl text-sm font-bold border transition hover:bg-gray-50"
                        style="color:#374151;border-color:#d1d5db;">Cancel</button>
                <button wire:click="executeToggle" wire:loading.attr="disabled" wire:target="executeToggle"
                        class="flex-1 px-4 py-2.5 rounded-xl text-sm font-bold text-white transition flex items-center justify-center gap-2
                               {{ $tAction==='deactivate' ? 'bg-red-600 hover:bg-red-700' : 'bg-green-600 hover:bg-green-700' }}">
                    <span wire:loading wire:target="executeToggle"><i class="fas fa-spinner animate-spin"></i></span>
                    <span wire:loading.remove wire:target="executeToggle">
                        {{ $tAction==='deactivate' ? 'Yes, Deactivate' : 'Yes, Activate' }}
                    </span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif

</div>