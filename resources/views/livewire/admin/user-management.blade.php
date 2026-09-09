{{-- resources/views/livewire/ADMIN/manage-users.blade.php --}}

<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

new class extends Component {
    use WithPagination, WithFileUploads;

    protected function queryString(): array { return []; }

    public string $activeRole   = 'all';
    public string $search       = '';
    public string $activeModal  = '';
    // Alumni-only status filter: 'all' | 'complete' | 'pending'. Ignored
    // when activeRole isn't 'alumni'. Driven by the clickable "X complete /
    // Y pending" text on the Total Alumni stat card, and by the incoming
    // admin_alumni_filter session value set from the dashboard's
    // goToAlumni('complete'/'pending') links.
    public string $statusFilter = 'all';

    public string $dFn='', $dMn='', $dLn='', $dSfx='';
    public string $dUsername='', $dEmail='';
    public array  $dErrs = [];
    public string $dOk   = '';
    public bool   $dSave = false;

    public ?array  $vData = null;

    public $vPhoto       = null;
    public bool $vPhotoSave = false;

    public ?int   $ueId      = null;
    public string $ueName    = '';
    public string $ueEmail   = '';
    public array  $ueErrors  = [];
    public bool   $ueSave    = false;
    // Inline confirmation shown right inside the modal the moment a
    // save succeeds — the global toast (7s auto-dismiss, top of the
    // page) was easy to miss while looking at the modal itself, which
    // made a successful update feel indistinguishable from "nothing
    // happened yet" until the admin looked elsewhere. This sits right
    // next to the input so success is unmistakable immediately.
    public string $ueSuccess = '';

    public ?int   $cpId     = null;
    public string $cpName   = '';
    public string $cpNew    = '', $cpConfirm = '';
    public array  $cpErrs   = [];
    public bool   $cpSave   = false;

    public ?int   $tId      = null;
    public string $tName='', $tAction='', $tRole='';

    public int $currentPage = 1;

    public function mount(): void {
        $filter = session()->pull('admin_alumni_filter', '');
        if ($filter) {
            $this->activeRole = 'alumni';
            if (in_array($filter, ['complete', 'pending', 'this_month'], true)) {
                $this->statusFilter = $filter === 'this_month' ? 'new_this_month' : $filter;
            }
        }
        $tab = session()->pull('admin_users_tab', '');
        if ($tab) {
            $this->activeRole = $tab;
        }
        session()->forget('admin_users_status');

        // ── Auto-open View Details when arriving from an "Email Updated"
        // notification (sidebar notif panel routes here with
        // ?highlight_user={id}) — same "click a notif -> land with the
        // record already open" pattern as Job Postings' highlight_job
        // and Events' highlight_event handling. Only opens if the user
        // still exists; a deleted account's notif link just lands on
        // the plain table instead of erroring.
        $highlightUserId = request()->query('highlight_user');
        if ($highlightUserId && DB::table('users')->where('id', $highlightUserId)->exists()) {
            $this->showProfile((int) $highlightUserId);

            // Strip ?highlight_user=... from the address bar once the
            // modal is open — same reasoning as the job/event cases:
            // the query param has done its job, and leaving it there
            // means a manual refresh or reshare of the URL keeps
            // popping the same modal back open, plus it's just noise
            // in the URL bar. history.replaceState swaps it out in
            // place with no reload and no extra navigation entry.
            $this->js(<<<'JS'
                window.history.replaceState({}, '', window.location.pathname);
            JS);
        }
    }

    // Same-page notif click: the sidebar dispatches this directly (instead
    // of a full navigate) when the admin is already on User Management, so
    // the View Details modal opens immediately with no page flash.
    #[On('open-view-user')]
    public function openViewUserFromNotif(int $id): void
    {
        $this->showProfile($id);
    }

    private function perPage(): int
    {
        return 100;
    }

    public function updatingSearch(): void { $this->currentPage = 1; }

    public function switchTab(string $r): void {
        $this->activeRole    = $r;
        $this->search        = '';
        // Only reset the alumni status filter when explicitly leaving alumni
        // via the "Reset"/"Clear Filters" all-tab action. Switching away to
        // another role tab and back (via the Alumni dropdown) should keep
        // whatever Complete/Pending/All was previously selected.
        if ($r === 'all') {
            $this->statusFilter = 'all';
        }
        $this->currentPage   = 1;
    }

    public function setStatusFilter(string $status): void {
        $this->activeRole    = 'alumni';
        $this->statusFilter  = in_array($status, ['all', 'complete', 'pending', 'new_this_month'], true) ? $status : 'all';
        $this->search        = '';
        $this->currentPage   = 1;
    }

    public function goToPage(int $p): void  { $this->currentPage = $p; }
    public function nextPage(): void        { $this->currentPage++; }
    public function previousPage(): void    { if ($this->currentPage > 1) $this->currentPage--; }

    #[Computed]
    public function stats(): array
    {
        // Livewire re-renders this WHOLE component after every action in
        // the page — including things that have nothing to do with the
        // stat cards, like saving an email in the View Profile modal.
        // Before this cache, that meant clicking "Update" in the email
        // modal also re-ran every COUNT() query below on every single
        // click, which is most of why that save felt slow. A short
        // cache means those unrelated actions reuse the last computed
        // numbers instead of re-querying; any action that actually
        // changes what these numbers count (toggle active/inactive,
        // email/username update affecting verified/pending counts, a
        // new photo doesn't affect this one) busts it immediately via
        // bustUserListCache() so nothing goes stale.
        return \Illuminate\Support\Facades\Cache::remember('admin_users_stats', 15, function () {
            $rows = DB::table('users')
                ->selectRaw("role, COUNT(*) as cnt")
                ->groupBy('role')
                ->pluck('cnt', 'role');

            $dirActive   = DB::table('director')->where('status', 'ACTIVE')->whereNull('deleted_at')->count();
            $dirInactive = DB::table('director')->where('status', 'INACTIVE')->whereNull('deleted_at')->count();

            $coordActive   = DB::table('organizer')->where('status', 'ACTIVE')->whereNull('deleted_at')->count();
            $coordInactive = DB::table('organizer')->where('status', 'INACTIVE')->whereNull('deleted_at')->count();

            $regActive   = DB::table('users')->where('role','registrar')->where('user_status','ACTIVE')->count();
            $regInactive = DB::table('users')->where('role','registrar')->where('user_status','INACTIVE')->count();

            $alumniTotal    = $rows->get('alumni', 0);
            $alumniVerified = DB::table('alumni')->whereNotNull('password_changed_at')->count();
            $alumniPending  = $alumniTotal - $alumniVerified;

            // Newly registered alumni — created_at falls within the current
            // calendar month (Asia/Manila), read straight off the users table
            // since that's where the registration timestamp lives.
            $alumniNewThisMonth = DB::table('users')
                ->where('role', 'alumni')
                ->whereMonth('created_at', now('Asia/Manila')->month)
                ->whereYear('created_at', now('Asia/Manila')->year)
                ->count();

            return [
                'total'              => $rows->sum(),
                'alumni'             => $alumniTotal,
                'alumniVerified'     => $alumniVerified,
                'alumniPending'      => $alumniPending,
                'alumniNewThisMonth' => $alumniNewThisMonth,
                'director'           => $rows->get('director',  0),
                'dirActive'          => $dirActive,
                'dirInactive'        => $dirInactive,
                'coordinator'        => $rows->get('organizer', 0),
                'coordActive'        => $coordActive,
                'coordInactive'      => $coordInactive,
                'registrar'          => $rows->get('registrar', 0),
                'regActive'          => $regActive,
                'regInactive'        => $regInactive,
            ];
        });
    }

    /**
     * Cache key for users() — varies by every input that changes its
     * result (role tab, status filter, search text, page) so cached
     * results never leak across different views of the table.
     */
    private function usersCacheKey(): string
    {
        // admin_users_list_version must be folded in here — it's what
        // bustUserListCache() bumps. Without it, incrementing the
        // version does nothing because every filter/page combo still
        // resolves to the same key it always did, so updates (email,
        // username, activate/deactivate, photo) kept serving stale
        // cached rows until the 15s TTL expired on its own.
        $version = \Illuminate\Support\Facades\Cache::get('admin_users_list_version', 1);
        return 'admin_users_list:' . $version . ':' . md5(implode('|', [
            $this->activeRole, $this->statusFilter, $this->search, $this->currentPage,
        ]));
    }

    /**
     * Clears both cached blocks — called by every action that actually
     * changes data these two queries read (email/username update,
     * activate/deactivate toggle, new profile photo). Password changes
     * don't touch anything either of these counts or lists, so
     * saveChangePassword() doesn't need to call this.
     */
    private function bustUserListCache(): void
    {
        \Illuminate\Support\Facades\Cache::forget('admin_users_stats');
        // users() is keyed per filter/page combination, so a single
        // forget() can't reach every possible key — instead this bumps
        // a version tag that's folded into usersCacheKey(), which
        // invalidates every previous key at once without needing to
        // enumerate them.
        \Illuminate\Support\Facades\Cache::increment('admin_users_list_version');
    }

    #[Computed]
    public function users()
    {
        // Same reasoning as stats() above: this runs on every Livewire
        // request regardless of which action fired, including saving an
        // email in a completely separate modal. Cached briefly, keyed by
        // every input that changes its result (see usersCacheKey()) so
        // different tabs/filters/pages never collide, and busted
        // immediately by bustUserListCache() whenever an action actually
        // changes a row this query reads.
        $result = \Illuminate\Support\Facades\Cache::remember($this->usersCacheKey(), 15, function () {
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
                    DB::raw("COALESCE(dir.email,'')               as director_email"),
                    DB::raw("COALESCE(org.id_number,'')          as id_number"),
                    DB::raw("COALESCE(org.department,'')         as department"),
                    DB::raw("COALESCE(NULLIF(al.profile_photo,''), NULLIF(org.profile_photo,''), NULLIF(dir.profile_photo,'')) as photo"),
                    DB::raw("COALESCE(al.first_name,'')          as alumni_first_name"),
                    DB::raw("COALESCE(al.middle_initial,'')      as alumni_middle_name"),
                    DB::raw("COALESCE(al.last_name,'')           as alumni_last_name"),
                    DB::raw("COALESCE(org.first_name,'')         as org_first_name"),
                    DB::raw("COALESCE(org.last_name,'')          as org_last_name"),
                    DB::raw("COALESCE(dir.first_name,'')         as dir_first_name"),
                    DB::raw("COALESCE(dir.middle_name,'')        as dir_middle_name"),
                    DB::raw("COALESCE(dir.last_name,'')          as dir_last_name"),
                    DB::raw("COALESCE(dir.suffix,'')              as dir_suffix"),
                ])
                ->leftJoin('alumni as al', 'al.user_id', '=', 'users.id')
                ->leftJoin('organizer as org', fn($j) => $j->on('org.user_id','=','users.id')->whereNull('org.deleted_at'))
                ->leftJoin('director as dir',  fn($j) => $j->on('dir.user_id','=','users.id')->whereNull('dir.deleted_at'));

            $q->where('users.role', '!=', 'admin');

            $map = ['alumni'=>'alumni','director'=>'director','coordinator'=>'organizer','registrar'=>'registrar'];
            if ($this->activeRole !== 'all' && isset($map[$this->activeRole]))
                $q->where('users.role', $map[$this->activeRole]);

            if ($this->activeRole === 'alumni' && in_array($this->statusFilter, ['complete', 'pending', 'new_this_month'], true)) {
                if ($this->statusFilter === 'complete') {
                    $q->whereNotNull('al.password_changed_at');
                } elseif ($this->statusFilter === 'pending') {
                    $q->whereNull('al.password_changed_at');
                } else { // new_this_month
                    $q->whereMonth('users.created_at', now('Asia/Manila')->month)
                      ->whereYear('users.created_at', now('Asia/Manila')->year);
                }
            }

            if ($this->search) {
                $t = '%'.$this->search.'%';
                $q->where(fn($s) => $s->where('users.name','like',$t)->orWhere('users.email','like',$t));
            }
            $q->orderBy('users.created_at', 'desc');

            $pp    = $this->perPage();
            $total = $q->count();
            $lp    = (int) ceil($total / $pp);
            $cp    = max(1, min($this->currentPage, max($lp, 1)));

            $items = $q->offset(($cp - 1) * $pp)->limit($pp)->get();

            return (object)[
                'items'       => $items,
                'total'       => $total,
                'perPage'     => $pp,
                'currentPage' => $cp,
                'lastPage'    => $lp,
                'from'        => $total > 0 ? ($cp - 1) * $pp + 1 : 0,
                'to'          => min($cp * $pp, $total),
                'hasPrev'     => $cp > 1,
                'hasNext'     => $cp < $lp,
            ];
        });

        // Cache::remember() can return a plain stdClass after
        // (de)serialization depending on cache driver — currentPage still
        // needs to land back on the component the same way the original
        // uncached version did, so pagination controls stay in sync.
        $this->currentPage = $result->currentPage;

        return $result;
    }

    public function openModal(string $m): void {
        $this->activeModal = $m;
        $this->dFn=$this->dMn=$this->dLn=$this->dSfx=$this->dUsername=$this->dEmail='';
        $this->dErrs=[]; $this->dOk='';
        $this->vPhoto = null;
    }

    public function closeModal(): void {
        $this->activeModal=''; $this->vData=null;
        $this->tId=null;
        $this->cpId=null; $this->cpNew=$this->cpConfirm=''; $this->cpErrs=[];
        $this->ueId=null; $this->ueName=$this->ueEmail=''; $this->ueErrors=[]; $this->ueSuccess='';
        $this->vPhoto=null; $this->vPhotoSave=false;
    }

    public function createDirector(): void {
        $this->dErrs=[]; $this->dOk=''; $this->dSave=true;
        try {
            $errors = [];
            if (!trim($this->dFn))       $errors[] = 'First name is required.';
            if (!trim($this->dMn))       $errors[] = 'Middle name is required.';
            if (!trim($this->dLn))       $errors[] = 'Last name is required.';
            if (!trim($this->dUsername)) $errors[] = 'Teacher ID is required.';
            elseif (!preg_match('/^\d{8}$/', trim($this->dUsername)))
                                         $errors[] = 'Teacher ID must be exactly 8 digits.';
            if (!trim($this->dEmail))    $errors[] = 'Email address is required.';
            elseif (!filter_var(trim($this->dEmail), FILTER_VALIDATE_EMAIL))
                                         $errors[] = 'Please enter a valid email address.';
            if (!empty($errors)) { $this->dErrs = ['general' => $errors]; return; }

            if (DB::table('director')->where('status', 'ACTIVE')->exists()) {
                $this->dErrs = ['general' => ['There is already an active Director. Please deactivate the current Director first.']];
                return;
            }

            $loginEmail = trim($this->dUsername).'@director.internal';
            if (DB::table('users')->where('email', $loginEmail)->exists()) {
                $this->dErrs = ['general' => ['That Teacher ID is already taken. Please choose a different one.']];
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
            ], function ($m) { $m->to(trim($this->dEmail))->subject("Your Director Account – Philcst Alumni Connect"); });

            $this->dOk = "Director <strong>{$full}</strong> created successfully!"
                . "|Login Teacher ID: <code class='font-mono bg-green-100 px-1.5 py-0.5 rounded text-green-800'>{$uname}</code>"
                . "|Auto-generated password: <code class='font-mono bg-yellow-100 px-1.5 py-0.5 rounded text-yellow-800'>{$autoPassword}</code>"
                . "|<span class='text-amber-700 font-medium'>⚠ Please share the password with the director and advise them to change it upon first login.</span>";

            // ── DISPATCH: new director created notification ──────────────────
            $this->dispatch('__admin-user-created-rich', [
                'uid'      => $uid,
                'name'     => $full,
                'username' => $uname,
            ]);

            $this->flash('success', "Director '{$full}' registered successfully!");
        } catch (\Exception $e) {
            $this->dErrs = ['general' => [$e->getMessage()]];
        } finally { $this->dSave = false; }
    }

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
                DB::raw("COALESCE(al.student_id,'')         as student_id"),
                DB::raw("COALESCE(al.course_code,'')        as course_code"),
                DB::raw("COALESCE(al.course_name,'')        as course_name"),
                DB::raw("COALESCE(CAST(al.batch AS CHAR),'') as batch"),
                DB::raw("COALESCE(al.first_name,'')         as alumni_first_name"),
                DB::raw("COALESCE(al.middle_initial,'')     as alumni_middle_name"),
                DB::raw("COALESCE(al.last_name,'')          as alumni_last_name"),
                DB::raw("COALESCE(al.suffix,'')             as alumni_suffix"),
                DB::raw("COALESCE(al.email,'')              as record_email"),
                DB::raw("COALESCE(org.first_name,'')        as org_first_name"),
                DB::raw("COALESCE(org.last_name,'')         as org_last_name"),
                DB::raw("COALESCE(org.id_number,'')         as id_number"),
                DB::raw("COALESCE(org.department,'')        as department"),
                DB::raw("COALESCE(dir.first_name,'')        as first_name"),
                DB::raw("COALESCE(dir.middle_name,'')       as middle_name"),
                DB::raw("COALESCE(dir.last_name,'')         as last_name"),
                DB::raw("COALESCE(dir.suffix,'')            as suffix"),
                DB::raw("COALESCE(dir.email,'')             as director_email"),
                DB::raw("(CASE
                    WHEN users.role='alumni'    THEN al.email_updated_at
                    WHEN users.role='director'  THEN dir.email_updated_at
                    WHEN users.role='registrar' THEN users.email_updated_at
                    ELSE NULL
                END) as email_updated_at"),
                DB::raw("COALESCE(NULLIF(al.profile_photo,''), NULLIF(org.profile_photo,''), NULLIF(dir.profile_photo,'')) as photo"),
            ])
            ->leftJoin('alumni as al','al.user_id','=','users.id')
            ->leftJoin('organizer as org', fn($j)=>$j->on('org.user_id','=','users.id')->whereNull('org.deleted_at'))
            ->leftJoin('director as dir',  fn($j)=>$j->on('dir.user_id','=','users.id')->whereNull('dir.deleted_at'))
            ->where('users.id',$id)->first();
        if (!$r) { $this->flash('error','User not found.'); return; }
        $this->vData = (array) $r;
        $this->vPhoto = null;
        $this->ueEmail  = '';
        $this->ueErrors = [];
        $this->ueSuccess = '';
        $this->cpNew = $this->cpConfirm = '';
        $this->cpErrs = [];
        if ($r->role === 'alumni' && !empty($r->record_email) && !str_contains($r->record_email,'@pending.local')) {
            $this->ueEmail = $r->record_email;
            $this->ueId    = $id;
            $this->ueName  = $r->name;
        } elseif ($r->role === 'alumni') {
            $this->ueId   = $id;
            $this->ueName = $r->name;
        } elseif ($r->role === 'director') {
            $this->ueEmail = $r->director_email ?? '';
            $this->ueId    = $id;
            $this->ueName  = $r->name;
        } elseif ($r->role === 'registrar') {
            $this->ueEmail = $r->name ?? '';
            $this->ueId    = $id;
            $this->ueName  = $r->name;
        }
        if (in_array($r->role, ['registrar','admin'])) {
            $this->cpId   = $id;
            $this->cpName = $r->name;
        }
        $this->activeModal = 'viewProfile';
    }

    public function ueCooldownDaysLeft(): int {
        $last = $this->vData['email_updated_at'] ?? null;
        if (!$last) return 0;
        // Was off by one: diffInDays() on the exact same moment returns 0,
        // so "30 - 0" made the cooldown effectively last a full 30 days
        // PLUS however much of "day 0" was left — 31 days end to end
        // before a change was allowed again. Anchoring both sides to
        // start-of-day before diffing removes that partial-day carry, so
        // the window is exactly 30 calendar days from the update.
        $elapsedDays = \Carbon\Carbon::parse($last)->startOfDay()
            ->diffInDays(now()->startOfDay());
        $remaining   = 30 - $elapsedDays;
        return $remaining > 0 ? (int) $remaining : 0;
    }

    public function savePhoto(): void {
        $this->vPhotoSave = true;
        try {
            if (!$this->vPhoto) { $this->flash('error', 'Please select a photo to upload.'); return; }
            $role   = $this->vData['role'] ?? '';
            if (!in_array($role, ['director', 'registrar'])) {
                $this->flash('error', 'Profile photo can only be uploaded for Directors and Registrars.');
                return;
            }
            $this->validate(['vPhoto' => 'image|mimes:jpg,jpeg,png,gif,webp|max:2048']);
            $userId = $this->vData['id'];
            $folder = match($role) {
                'alumni'    => 'alumni-photos',
                'organizer' => 'organizers',
                'director'  => 'directors',
                'registrar' => 'registrars',
                default     => 'profile-photos',
            };
            $oldPhoto = $this->vData['photo'] ?? null;
            if ($oldPhoto && Storage::disk('public')->exists($oldPhoto))
                Storage::disk('public')->delete($oldPhoto);
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
            // Photo is also shown in the users table row, so bust the
            // cached list so it doesn't keep showing the old photo.
            $this->bustUserListCache();
            $this->flash('success', 'Profile photo updated successfully!');
        } catch (\Exception $e) {
            $this->flash('error', 'Failed to upload photo: ' . $e->getMessage());
        } finally { $this->vPhotoSave = false; }
    }

    public function saveUpdateEmail(): void {
        $this->ueErrors = []; $this->ueSuccess = ''; $this->ueSave = true;
        try {
            $role = $this->vData['role'] ?? '';

            $cooldownDays = $this->ueCooldownDaysLeft();
            if ($cooldownDays > 0) {
                $label = $role === 'registrar' ? 'username' : 'email';
                $this->ueErrors = ['general' => ["This account's {$label} was updated recently. Please wait {$cooldownDays} more day" . ($cooldownDays === 1 ? '' : 's') . " before changing it again."]];
                return;
            }

            if ($role === 'registrar') {
                $uname = trim($this->ueEmail);
                if ($uname === '') {
                    $this->ueErrors = ['general' => ['Please enter a username.']]; return;
                }
                if (!preg_match('/^[a-zA-Z0-9._-]+$/', $uname)) {
                    $this->ueErrors = ['general' => ['Username can only contain letters, numbers, dots, dashes, and underscores.']]; return;
                }
                $loginEmail = $uname . '@registrar.internal';
                $duplicate = DB::table('users')->where('email', $loginEmail)->where('id', '!=', $this->ueId)->exists();
                if ($duplicate) {
                    $this->ueErrors = ['general' => ["The username \"{$uname}\" is already taken. Please choose a different one."]]; return;
                }
                DB::table('users')->where('id', $this->ueId)
                    ->update(['email' => $loginEmail, 'password_changed_at' => null, 'email_updated_at' => now(), 'updated_at' => now()]);
                if ($this->vData) { $this->vData['email'] = $loginEmail; $this->vData['email_updated_at'] = now(); }

                $this->ueErrors = [];
                // Keep the field showing the username that was actually
                // saved (not just whatever was typed) — same as the
                // alumni/director branch below — so the input itself
                // visibly reflects the new state, not just the toast.
                $this->ueEmail = $uname;

                // Bust the cached stats/list immediately — otherwise the
                // table and stat cards keep serving the pre-update
                // snapshot for up to 15s, which is what made this feel
                // slow/broken even though the DB write already succeeded.
                $this->bustUserListCache();

                // ── DISPATCH: username updated notification ─────────────────────
                $this->dispatch('__admin-user-username-rich', [
                    'uid'      => $this->ueId,
                    'name'     => $this->ueName,
                    'username' => $uname,
                ]);

                $msg = "Username updated for {$this->ueName}. They will be required to reset their password on next login.";
                $this->ueSuccess = $msg;
                $this->flash('success', $msg);
                return;
            }

            $email = trim($this->ueEmail);
            if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->ueErrors = ['general' => ['Please enter a valid email address.']]; return;
            }

            if ($role === 'director') {
                $duplicate = DB::table('director')->where('email', $email)->where('user_id', '!=', $this->ueId)->exists();
                if ($duplicate) {
                    $this->ueErrors = ['general' => ["The email \"{$email}\" is already registered to another director account."]]; return;
                }
                DB::table('director')->where('user_id', $this->ueId)
                    ->update(['email' => $email, 'email_updated_at' => now(), 'updated_at' => now()]);
                if ($this->vData) { $this->vData['director_email'] = $email; $this->vData['email_updated_at'] = now(); }
            } else {
                $duplicate = DB::table('alumni')->where('email', $email)->where('user_id', '!=', $this->ueId)->exists();
                if ($duplicate) {
                    $this->ueErrors = ['general' => ["The email \"{$email}\" is already registered to another alumni account."]]; return;
                }
                DB::table('alumni')->where('user_id', $this->ueId)
                    ->update(['email' => $email, 'password_changed_at' => null, 'email_updated_at' => now(), 'updated_at' => now()]);
                if ($this->vData) { $this->vData['record_email'] = $email; $this->vData['email_updated_at'] = now(); }
            }
            $this->ueErrors = [];
            // Same reasoning as the registrar branch above: keep the
            // field showing the email that actually got saved.
            $this->ueEmail = $email;

            // Bust the cached stats/list immediately — otherwise the
            // table and stat cards keep serving the pre-update snapshot
            // for up to 15s, which is what made this feel slow/broken
            // even though the DB write already succeeded.
            $this->bustUserListCache();

            // ── DISPATCH: email updated notification ─────────────────────────
            $this->dispatch('__admin-user-email-rich', [
                'uid'   => $this->ueId,
                'name'  => $this->ueName,
                'email' => $email,
                'role'  => $role,
            ]);

            $msg = $role === 'director'
                ? "Email updated for {$this->ueName}."
                : "Email updated for {$this->ueName}. They will be required to reset their password on next login.";
            $this->ueSuccess = $msg;
            $this->flash('success', $msg);
        } catch (\Illuminate\Database\QueryException $e) {
            $this->ueErrors = ($e->errorInfo[1] ?? null) === 1062
                ? ['general' => ['That email is already in use by another alumni account.']]
                : ['general' => ['A database error occurred. Please try again.']];
        } catch (\Exception $e) {
            $this->ueErrors = ['general' => [$e->getMessage()]];
        } finally { $this->ueSave = false; }
    }

    public function saveChangePassword(): void {
        $this->cpErrs = []; $this->cpSave = true;
        try {
            if (strlen(trim($this->cpNew)) < 8) throw new \Exception('Password must be at least 8 characters long.');
            if ($this->cpNew !== $this->cpConfirm) throw new \Exception('Passwords do not match. Please re-enter and try again.');
            $user = DB::table('users')->find($this->cpId);
            DB::table('users')->where('id', $this->cpId)->update(['password' => Hash::make($this->cpNew), 'updated_at' => now()]);
            match($user->role ?? '') {
                'alumni'    => DB::table('alumni')->where('user_id', $this->cpId)->update(['password_changed_at' => null, 'updated_at' => now()]),
                'organizer' => DB::table('organizer')->where('user_id', $this->cpId)->update(['password_changed_at' => null, 'updated_at' => now()]),
                'director'  => DB::table('director')->where('user_id', $this->cpId)->update(['password_changed_at' => null, 'updated_at' => now()]),
                'registrar' => DB::table('users')->where('id', $this->cpId)->update(['password_changed_at' => null, 'updated_at' => now()]),
                default     => null,
            };
            $this->cpNew = $this->cpConfirm = '';
            $this->flash('success', "Password updated for {$this->cpName}. They will be required to change it on next login.");
        } catch (\Exception $e) {
            $this->cpErrs = ['general' => [$e->getMessage()]];
        } finally { $this->cpSave = false; }
    }

    public function confirmToggle(int $id, string $a): void {
        $u = DB::table('users')->find($id);
        if (!$u) { $this->flash('error','User not found.'); return; }
        $this->tId=$id; $this->tName=$u->name; $this->tAction=$a; $this->tRole=$u->role;
        $this->activeModal='toggleConfirm';
    }

    public function executeToggle(): void {
        try {
            $s = $this->tAction==='activate' ? 'ACTIVE' : 'INACTIVE';
            if ($this->tRole==='director')  DB::table('director')->where('user_id',$this->tId)->update(['status'=>$s,'updated_at'=>now()]);
            if ($this->tRole==='registrar') DB::table('users')->where('id',$this->tId)->update(['user_status'=>$s,'updated_at'=>now()]);

            // Status shown in the table/stats cards, so bust the cache
            // right away — otherwise the row keeps showing the old
            // Active/Inactive state for up to 15s after this succeeds.
            $this->bustUserListCache();

            // ── DISPATCH: activate / deactivate notification ─────────────────
            $this->dispatch('__admin-user-toggled-rich', [
                'uid'    => $this->tId,
                'name'   => $this->tName,
                'action' => $this->tAction,   // 'activate' | 'deactivate'
                'role'   => $this->tRole,
            ]);

            $this->flash('success', $this->tName.' has been '.($s==='ACTIVE'?'activated':'deactivated').'.');
        } catch (\Exception $e) { $this->flash('error','Failed: '.$e->getMessage()); }
        finally { $this->closeModal(); }
    }

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
            'organizer' => 'Coordinator',
            'director'  => 'Director',
            'registrar' => 'Registrar',
            'alumni'    => 'Alumni',
            'admin'     => 'Admin',
            default     => ucfirst($r),
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

    public function displayEmail(string $role, string $email, string $recordEmail, string $directorEmail = ''): string {
        if ($role === 'alumni')
            return ($recordEmail && !str_contains($recordEmail,'@pending.local')) ? $recordEmail : '—';
        if ($role === 'director')
            return $directorEmail ?: '—';
        if ($role === 'registrar')
            return '';
        return str_ends_with($email, '.internal') ? '—' : $email;
    }

    public function adminUsername(string $email, string $name): string {
        if (str_ends_with($email, '.internal')) return explode('@', $email)[0];
        return $name ?: explode('@', $email)[0];
    }

    /**
     * Wraps every case-insensitive occurrence of the current search term
     * inside $text with a highlighted <mark> span. Safe against XSS:
     * $text is HTML-escaped first, then the highlight markup is injected —
     * so this must always be output with {!! !!} in the view, never {{ }}.
     */
    public function highlightText(?string $text): string {
        $safe = e($text ?? '');
        $term = trim($this->search);
        if ($term === '') return $safe;

        $escapedTerm = preg_quote(e($term), '/');
        return preg_replace(
            '/(' . $escapedTerm . ')/i',
            '<mark class="mu-search-highlight">$1</mark>',
            $safe
        );
    }
};
?>

<div class="flex flex-col mu-page-root" style="height:90vh; overflow:hidden;">

<div id="mu-hover-tip" class="mu-row-tip">
    <i class="fas fa-eye mr-1.5" style="font-size:.65rem;"></i>View Details
</div>

<style>
.mu-filter-input {
    border: 1px solid #E8E0F0;
    transition: border-color .15s, box-shadow .15s;
    color: #000000;
    background: #ffffff;
    font-size: 0.95rem;
    padding: 0.55rem 0.8rem;
    border-radius: 0.5rem;
    font-weight: 500;
}
.mu-filter-input::placeholder { color: #595959; font-weight: 400; }
.mu-filter-input:hover  { border-color: #c4b5d4; }
.mu-filter-input:focus  { outline: none; border-color: #7a3f91; box-shadow: 0 0 0 2px rgba(122,63,145,.10); }
select.mu-filter-input {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23000000' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
    background-position: right 0.6rem center;
    background-repeat: no-repeat;
    background-size: 1.25em 1.25em;
    padding-right: 2.25rem;
    -webkit-appearance: none; -moz-appearance: none; appearance: none; cursor: pointer;
}
select.mu-filter-input.mu-active {
    border-color: #7a3f91; background-color: #f5f0fa; color: #7a3f91; font-weight: 600;
}

/* "Smooth" polish for the Create Director form: slightly springier
   focus transition than the base .mu-filter-input, and a subtle
   press/lift on the submit button so saving feels responsive rather
   than instant/jarring. */
.mu-smooth-input {
    transition: border-color .18s ease, box-shadow .18s ease, background-color .18s ease;
}
.mu-smooth-input:focus {
    box-shadow: 0 0 0 3px rgba(122,63,145,.12);
}
.mu-smooth-btn {
    transition: opacity .15s ease, transform .1s ease, box-shadow .15s ease;
}
.mu-smooth-btn:active:not(:disabled) { transform: scale(0.98); }
.mu-smooth-btn:disabled { opacity: .7; cursor: wait; }

.mu-stat-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 0.6rem;
    flex-shrink: 0;
}
@media (max-width: 1100px) { .mu-stat-grid { grid-template-columns: repeat(3, 1fr); } }
@media (max-width: 768px)  { .mu-stat-grid { grid-template-columns: 1fr 1fr; } }
/* Was 1fr (single column, 5 stacked full-width cards — the tallest
   possible arrangement) below 480px. Now that cards are shrunk on
   mobile, staying at 2 columns keeps the block to 3 short rows
   instead of 5 tall ones, freeing up real space for the table. */

/* ── Mobile: let the whole layout use more of the real viewport height ── */
@media (max-width: 640px) {
    .mu-page-root { height: 96vh !important; max-height: 96vh !important; }
    .mu-main-layout { height: calc(96vh - 90px) !important; max-height: calc(96vh - 90px) !important; }
}

.mu-stat-card {
    background: #ffffff;
    border: 1px solid #E8E0F0;
    border-radius: 12px;
    padding: 12px 14px;
    position: relative; overflow: visible;
    display: flex; flex-direction: row; align-items: center; gap: 12px;
    min-height: 72px;
    transition: border-color .15s, box-shadow .15s;
}
/* Hover border picks up each card's own icon color, set per-card via
   the --stat-accent custom property (see inline style on each card).
   Scoped to .cursor-pointer only — Directors/Coordinators/Registrars
   aren't clickable, so they must NOT show an interactive hover state. */
.mu-stat-card.cursor-pointer:hover {
    border-color: var(--stat-accent, #c4b5d4);
    box-shadow: 0 3px 10px var(--stat-accent-shadow, rgba(122,63,145,.10));
}
.mu-stat-icon-lg {
    width: 46px; height: 46px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.mu-stat-icon-lg i { font-size: 1.3rem; }
.mu-stat-text { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
.mu-stat-num  { font-size: 1.65rem; font-weight: 800; line-height: 1; letter-spacing: -.01em; color: #000000; }
.mu-stat-lbl  { font-size: .8rem; font-weight: 700; margin-top: 2px; color: #000000; }
.mu-stat-sub  { font-size: .75rem; font-weight: 600; margin-top: 1px; color: #000000; }

/* ── Mobile: shrink the stat cards themselves — smaller icon, tighter
   padding, smaller type — so the 5-card block takes noticeably less
   vertical space, leaving more of the screen for the table below.
   Placed after the base rules above so it correctly overrides them
   at this breakpoint instead of losing to source order. ── */
@media (max-width: 640px) {
    .mu-stat-grid { gap: 0.4rem; }
    .mu-stat-card { padding: 7px 9px; gap: 8px; min-height: 0; border-radius: 10px; }
    .mu-stat-icon-lg { width: 30px; height: 30px; border-radius: 8px; }
    .mu-stat-icon-lg i { font-size: 0.85rem; }
    .mu-stat-num  { font-size: 1.05rem; }
    .mu-stat-lbl  { font-size: .62rem; margin-top: 0; }
    .mu-stat-sub  { font-size: .58rem; margin-top: 0; }
}

.mu-table-block {
    display: flex; flex-direction: column;
    border-radius: 1rem; overflow: hidden;
    border: 1px solid #E8E0F0;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
    flex: 1; min-height: 0;
}

/* ── Mobile: the table block already gets all remaining space via
   flex:1 from its parent (.mu-main-layout is a fixed-height flex
   column) — forcing an extra min-height here made the block taller
   than that available space, so it overflowed the parent's
   overflow:hidden and pushed the pagination footer off-screen
   entirely. Letting min-height stay at 0 (the base rule) keeps the
   block correctly sized to its share of the layout instead. ── */
.mu-table-block-filter {
    background: #F5F5F5; border-bottom: 1px solid #E8E0F0;
    padding: 0.6rem 0.875rem; flex-shrink: 0;
}
.mu-table-block-pagination {
    flex-shrink: 0;
    background: linear-gradient(to right, #7a3f91, #9b59b6);
    padding: 0.5rem 1rem; min-height: 48px;
    display: flex; align-items: center; justify-content: space-between;
    gap: 0.5rem; flex-wrap: wrap;
    border-top: 1px solid rgba(122,63,145,.3);
}
/* Narrow screens: stack the "Showing X-Y of Z" line above the page
   buttons instead of squeezing both onto one row, and shorten the
   label itself so it doesn't wrap mid-number. */
@media (max-width: 480px) {
    .mu-table-block-pagination { justify-content: center; text-align: center; }
    .mu-table-block-pagination > p { width: 100%; text-align: center; }
}

.mu-tbl-row {
    background-color: #ffffff; cursor: pointer;
    transition: background-color .15s ease;
}
.mu-tbl-row:hover { background-color: #f5f0fa !important; }

/* Auto layout: each column sizes to its own content (badges, dates,
   names) instead of being forced into a fixed 1% sliver — that fixed
   layout was what caused "Identifier"/"Role" headers to truncate into
   "ID R…"/"…N…" on desktop. Columns that must never wrap mid-word
   (badges, dates) get white-space: nowrap individually instead. */
.mu-users-table { table-layout: auto; }

.scroll-c::-webkit-scrollbar { width: 5px; }
.scroll-c::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
.scroll-c::-webkit-scrollbar-thumb:hover { background: #7a3f91; }

/* ── Search / filter loading progress bar (same pattern as Event Organizer) ── */
.mu-filter-progress-track { height: 2px; width: 100%; overflow: hidden; background: transparent; position: relative; }
.mu-filter-progress-bar {
    position: absolute; top: 0; left: 0; height: 100%; width: 40%;
    border-radius: 99px; background: linear-gradient(135deg,#7a3f91,#9b59b6);
    animation: muFilterProgress 1s ease-in-out infinite;
}
@keyframes muFilterProgress { 0% { left: -40%; } 100% { left: 100%; } }

.mu-tab-pill {
    display: inline-flex; align-items: center; gap: 0.375rem;
    padding: 0.4rem 0.8rem; border-radius: 0.5rem;
    font-size: 0.9rem; font-weight: 600;
    transition: all .15s; cursor: pointer; white-space: nowrap;
    border: 1px solid transparent;
}
.mu-tab-pill:hover { background: #ede9f6; color: #7a3f91; }
.mu-tab-pill.mu-tab-active   { background: #fff; color: #7a3f91; border-color: #d4b8e8; box-shadow: 0 1px 3px rgba(122,63,145,.12); }
.mu-tab-pill.mu-tab-inactive { color: #000000; }

.mu-close-tooltip { position: relative; }
.mu-close-tooltip::after {
    content: 'Close'; position: absolute; top: calc(100% + 6px); left: 50%;
    transform: translateX(-50%); background: #1a1a1a; color: #fff;
    font-size: 10px; font-weight: 600; letter-spacing: .04em;
    padding: 4px 8px; border-radius: 5px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity .15s ease; z-index: 99999;
}
.mu-close-tooltip::before {
    content: ''; position: absolute; top: calc(100% + 1px); left: 50%;
    transform: translateX(-50%); border: 4px solid transparent;
    border-bottom-color: #1a1a1a; pointer-events: none;
    opacity: 0; transition: opacity .15s ease; z-index: 99999;
}
.mu-close-tooltip:hover::after, .mu-close-tooltip:hover::before { opacity: 1; }

/* Row "View Details" hover tooltip — black bg / white text, follows
   the cursor, desktop only (see JS + media query below). Same pattern
   as the Alumni Records page's #ar-hover-tip. Shown for every role
   (Alumni, Director, Coordinator, Registrar) since they all share
   this same .mu-tbl-row markup. */
.mu-row-tip {
    position: fixed;
    background: #1a1a1a;
    color: #fff;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: .05em;
    padding: 5px 11px;
    border-radius: 7px;
    white-space: nowrap;
    pointer-events: none;
    opacity: 0;
    transition: opacity .15s ease;
    z-index: 500;
    box-shadow: 0 4px 14px rgba(0,0,0,.30);
    transform: translate(12px, -110%);
}
.mu-row-tip.visible { opacity: 1; }
.mu-row-tip::after {
    content: '';
    position: absolute;
    top: 100%;
    left: 14px;
    border: 5px solid transparent;
    border-top-color: #1a1a1a;
}
@media (max-width: 768px), (hover: none) {
    .mu-row-tip { display: none !important; }
}

/* ── Profile modal body: scroll still works (wheel/swipe/keys), scrollbar
     track just isn't drawn, so the full-screen view reads as scroll-free ── */
.mu-vp-scroll {
    scrollbar-width: none;      /* Firefox */
    -ms-overflow-style: none;   /* old Edge / IE */
}
.mu-vp-scroll::-webkit-scrollbar { width: 0; height: 0; display: none; } /* Chrome/Safari/new Edge */

/* ── Role badge — status style ── */
.mu-role-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 4px 12px;
    border-radius: 9999px;
    border-width: 1px;
    border-style: solid;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .05em;
    text-transform: uppercase;
    white-space: nowrap;
}

/* ── Search match highlight ── */
.mu-search-highlight {
    background: #dbeafe;
    color: #1d4ed8;
    font-weight: 700;
    border-radius: 3px;
    padding: 0 2px;
}
</style>

{{-- FLASH TOAST --}}
<div
    x-data="{show:false,type:'success',msg:'',timer:null,display(t,m){this.type=t;this.msg=m;this.show=true;clearTimeout(this.timer);this.timer=setTimeout(()=>this.show=false,7000);}}"
    @flash-message.window="display($event.detail.type,$event.detail.message)"
    x-show="show"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 translate-x-8 scale-95"
    x-transition:enter-end="opacity-100 translate-x-0 scale-100"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100"
    x-transition:leave-end="opacity-0 translate-x-8"
    class="fixed top-5 right-4 sm:right-6 z-[100] flex items-start gap-3 px-5 py-4 rounded-2xl shadow-2xl max-w-xs sm:max-w-sm border w-full bg-white"
    :class="{'border-emerald-300 text-emerald-800':type==='success','border-red-300 text-red-800':type==='error','border-blue-300 text-blue-800':type==='info'}"
    style="display:none">
    <div class="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0"
         :class="{'bg-emerald-100':type==='success','bg-red-100':type==='error','bg-blue-100':type==='info'}">
        <i class="fas text-sm" :class="{'fa-check text-emerald-600':type==='success','fa-exclamation text-red-600':type==='error','fa-info text-blue-600':type==='info'}"></i>
    </div>
    <div class="flex-1 min-w-0">
        <p class="font-bold text-sm" x-text="type==='success'?'Success':type==='info'?'Info':'Error'"></p>
        <p class="text-sm mt-0.5 opacity-80 leading-snug break-words" x-text="msg"></p>
    </div>
    <button @click="show=false" class="opacity-40 hover:opacity-80 transition shrink-0"><i class="fas fa-xmark text-sm"></i></button>
</div>

{{-- MAIN LAYOUT --}}
<div class="flex flex-col gap-3 px-5 sm:px-7 lg:px-10 pt-6 pb-6 max-w-screen-2xl mx-auto w-full mu-main-layout" style="height: calc(100vh - 180px); max-height: calc(100vh - 180px); overflow:hidden;">

    {{-- PAGE HEADER --}}
    <div class="flex items-center gap-4 flex-shrink-0">
        <div class="w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md"
             style="background:linear-gradient(135deg,#7a3f91,#5e2f72);">
            <i class="fas fa-users-cog text-white text-lg"></i>
        </div>
        <div>
            <h1 class="text-xl font-semibold tracking-tight" style="color:#000000;">User Management</h1>
            <p class="text-xs leading-relaxed mt-0.5" style="color:#000000;">Manage all system users across every role</p>
        </div>
        <div class="ml-auto relative" x-data="{tip:false}">
            <button wire:click="openModal('createDirector')" wire:loading.attr="disabled" wire:target="openModal('createDirector')"
                    @mouseenter="tip=true" @mouseleave="tip=false"
                    class="w-10 h-10 rounded-2xl flex items-center justify-center shadow-md transition hover:opacity-90 active:scale-95"
                    style="background:linear-gradient(135deg,#7a3f91,#5e2f72);">
                <span wire:loading wire:target="openModal('createDirector')"><i class="fas fa-spinner animate-spin text-white text-base"></i></span>
                <span wire:loading.remove wire:target="openModal('createDirector')"><i class="fas fa-user-tie text-white text-base"></i></span>
            </button>
            <div x-show="tip" x-cloak
                 x-transition:enter="transition ease-out duration-100"
                 x-transition:enter-start="opacity-0 scale-95"
                 x-transition:enter-end="opacity-100 scale-100"
                 class="absolute right-0 top-full mt-2 z-50 pointer-events-none">
                <div class="bg-[#1a1a1a] text-white text-xs font-semibold px-3 py-1.5 rounded-lg whitespace-nowrap shadow-lg">
                    <i class="fas fa-user-tie mr-1.5"></i>New Director
                </div>
                <div class="absolute right-3 bottom-full w-0 h-0" style="border:5px solid transparent;border-bottom-color:#1a1a1a;"></div>
            </div>
        </div>
    </div>

    {{-- KPI STAT CARDS --}}
    @php $s = $this->stats; @endphp
    <div class="mu-stat-grid">
        <div class="mu-stat-card" style="--stat-accent:#7a3f91;--stat-accent-shadow:rgba(122,63,145,.15);">
            <div class="mu-stat-icon-lg" style="background:linear-gradient(135deg,#6d2f84,#9b59b6);">
                <i class="fas fa-graduation-cap text-white"></i>
            </div>
            <div class="mu-stat-text">
                <div class="mu-stat-num">{{ number_format($s['alumni']) }}</div>
                <div class="mu-stat-lbl">Total Alumni</div>
                <div class="mu-stat-sub">{{ number_format($s['alumniVerified']) }} complete · {{ number_format($s['alumniPending']) }} pending</div>
            </div>
        </div>
        <div class="mu-stat-card">
            <div class="mu-stat-icon-lg" style="background:linear-gradient(135deg,#3730a3,#6366f1);">
                <i class="fas fa-user-tie text-white"></i>
            </div>
            <div class="mu-stat-text">
                <div class="mu-stat-num">{{ number_format($s['director']) }}</div>
                <div class="mu-stat-lbl">Directors</div>
                <div class="mu-stat-sub">{{ $s['dirActive'] }} active · {{ $s['dirInactive'] }} inactive</div>
            </div>
        </div>
        <div class="mu-stat-card">
            <div class="mu-stat-icon-lg" style="background:linear-gradient(135deg,#7a3f91,#b07cc6);">
                <i class="fas fa-users-gear text-white"></i>
            </div>
            <div class="mu-stat-text">
                <div class="mu-stat-num">{{ number_format($s['coordinator']) }}</div>
                <div class="mu-stat-lbl">Coordinators</div>
                <div class="mu-stat-sub">{{ $s['coordActive'] }} active · {{ $s['coordInactive'] }} inactive</div>
            </div>
        </div>
        <div class="mu-stat-card">
            <div class="mu-stat-icon-lg" style="background:linear-gradient(135deg,#027a4f,#10b981);">
                <i class="fas fa-user-clock text-white"></i>
            </div>
            <div class="mu-stat-text">
                <div class="mu-stat-num">{{ number_format($s['registrar']) }}</div>
                <div class="mu-stat-lbl">Registrars</div>
                <div class="mu-stat-sub">{{ $s['regActive'] }} active · {{ $s['regInactive'] }} inactive</div>
            </div>
        </div>
        <div class="mu-stat-card" style="--stat-accent:#2563eb;--stat-accent-shadow:rgba(37,99,235,.15);">
            <div class="mu-stat-icon-lg" style="background:linear-gradient(135deg,#1a4db5,#2563eb);">
                <i class="fas fa-user-plus text-white"></i>
            </div>
            <div class="mu-stat-text">
                <div class="mu-stat-num">{{ number_format($s['alumniNewThisMonth']) }}</div>
                <div class="mu-stat-lbl">Newly Registered</div>
                <div class="mu-stat-sub">Alumni this month</div>
            </div>
        </div>
    </div>

    {{-- UNIFIED TABLE BLOCK --}}
    <div class="mu-table-block min-h-0">

        {{-- FILTER BAR --}}
        <div class="mu-table-block-filter flex flex-wrap gap-2 items-center transition-opacity duration-200"
             wire:loading.class="opacity-60" wire:target="switchTab,setStatusFilter,search,goToPage,nextPage,previousPage">
            <div class="flex items-center gap-2 px-3 h-[38px] rounded-xl shrink-0 font-semibold text-sm uppercase tracking-wide"
                 style="color:#7a3f91;">Filters</div>

            <div class="relative flex-1 min-w-[160px] max-w-xs"
                 wire:ignore
                 x-data="{q:'',init(){this.q=$wire.search??'';$wire.$watch('search',v=>{if(v!==this.q)this.q=v;});}}">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-xs pointer-events-none" style="color:#000000;z-index:1;"></i>
                <input type="text" x-model="q" @input.debounce.400ms="$wire.set('search',q)"
                       placeholder="Search name or email…"
                       class="mu-filter-input w-full" style="padding-left:2.25rem;padding-right:1rem;"
                       autocomplete="off" maxlength="100" spellcheck="false">
            </div>

            <div class="flex gap-1 bg-gray-100 p-0.5 rounded-xl flex-shrink-0">
                @foreach([
                    ['director','Directors','fa-user-tie'],
                    ['coordinator','Coordinators','fa-users-gear'],
                    ['registrar','Registrar','fa-user-clock'],
                ] as [$tab,$lbl,$ico])
                @if($loop->first)
                {{-- Alumni tab merged with its All/Complete/Pending status filter:
                     one pill, not a pill plus a separate dropdown beside it. The
                     native <select> sits transparently over the whole pill so
                     clicking anywhere on it opens the picker. A small colored
                     badge shows the active state (purple=All, green=Complete,
                     amber=Pending). --}}
                <div class="relative">
                    @php
                        $aBadge = match($statusFilter) {
                            'complete'       => ['Complete', '#059669', '#ECFDF5'],
                            'pending'        => ['Pending',  '#d97706', '#FFF7ED'],
                            'new_this_month' => ['New',      '#2563eb', '#EFF6FF'],
                            default          => ['All Alumni', '#7a3f91', '#F3E8FF'],
                        };
                    @endphp
                    <div class="mu-tab-pill {{ $activeRole==='alumni' ? 'mu-tab-active' : 'mu-tab-inactive' }} pr-6">
                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-md text-xs font-bold border"
                              style="background:{{ $aBadge[2] }};color:{{ $aBadge[1] }};border-color:{{ $aBadge[1] }};">
                            <i class="fas fa-graduation-cap"></i>{{ $aBadge[0] }}
                        </span>
                        <i class="fas fa-chevron-down text-xs" style="opacity:.7;"></i>
                    </div>
                    <select wire:key="alumni-status-select-{{ $activeRole }}-{{ $statusFilter }}"
                            wire:change="setStatusFilter($event.target.value)"
                            class="absolute inset-0 w-full h-full opacity-0 cursor-pointer">
                        {{-- When we're on another role tab, the browser still
                             needs SOME option to treat as "currently shown"
                             even with none marked selected — and it silently
                             falls back to the first <option> in the list,
                             which was "All Alumni" (value="all"). So picking
                             "All Alumni" again from Director/Coordinator/
                             Registrar looked like no change to the browser
                             (still "all" before and after) and wire:change
                             never fired — the tab never switched back.
                             This hidden, disabled placeholder is what the
                             browser falls back to instead whenever we're off
                             the Alumni tab, so it's never the same option as
                             "All Alumni" — picking "All Alumni" is always a
                             real change and always fires. It's disabled so
                             it can never be picked on purpose, and it isn't
                             rendered at all while already on Alumni. --}}
                        @unless($activeRole === 'alumni')
                        <option value="" selected disabled hidden></option>
                        @endunless
                        <option value="all"            {{ $activeRole==='alumni' && $statusFilter==='all'            ? 'selected' : '' }}>All Alumni</option>
                        <option value="complete"       {{ $activeRole==='alumni' && $statusFilter==='complete'       ? 'selected' : '' }}>Complete</option>
                        <option value="pending"        {{ $activeRole==='alumni' && $statusFilter==='pending'        ? 'selected' : '' }}>Pending</option>
                        <option value="new_this_month" {{ $activeRole==='alumni' && $statusFilter==='new_this_month' ? 'selected' : '' }}>Newly Registered</option>
                    </select>
                </div>
                @endif
                <button wire:click="switchTab('{{ $tab }}')"
                        wire:loading.attr="disabled" wire:target="switchTab('{{ $tab }}')"
                        class="mu-tab-pill {{ $activeRole===$tab ? 'mu-tab-active' : 'mu-tab-inactive' }}">
                    <span wire:loading wire:target="switchTab('{{ $tab }}')"><i class="fas fa-spinner animate-spin text-xs"></i></span>
                    <span wire:loading.remove wire:target="switchTab('{{ $tab }}')"><i class="fas {{ $ico }} text-xs"></i></span>
                    <span class="hidden sm:inline">{{ $lbl }}</span>
                </button>
                @endforeach
            </div>

            <button wire:click="switchTab('all')"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-wait"
                    wire:target="switchTab('all')"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold
                           bg-white border border-[#E8E0F0] transition active:scale-95 cursor-pointer disabled:pointer-events-none ml-auto"
                    style="color:#000000;">
                <span wire:loading.remove wire:target="switchTab('all')">
                    <i class="fas fa-rotate-left text-sm"></i>
                </span>
                <span wire:loading wire:target="switchTab('all')">
                    <i class="fas fa-spinner fa-spin text-sm" style="color:#7a3f91;"></i>
                </span>
                <span class="hidden sm:inline">Reset</span>
            </button>
        </div>

        {{-- TABLE --}}
        @php $pu = $this->users; @endphp
        <div class="relative flex-1 min-h-0 flex flex-col">

            {{-- Centered loading spinner overlay — mirrors Manage Events' table overlay --}}
            <div class="absolute inset-0 z-20 items-center justify-center hidden"
                 wire:loading.flex wire:target="switchTab,setStatusFilter,search,goToPage,nextPage,previousPage">
                <i class="fas fa-spinner fa-spin" style="font-size:38px; color:#7a3f91;"></i>
            </div>

            @if($pu->items->count() > 0)
            <div class="flex-1 min-h-0 overflow-x-hidden overflow-y-auto scroll-c transition-opacity duration-200" style="background:#fff;"
                 wire:loading.class="opacity-50 pointer-events-none"
                 wire:target="switchTab,setStatusFilter,search,goToPage,nextPage,previousPage">
                <table class="w-full bg-white border-collapse mu-users-table">
                    <thead class="sticky top-0 z-10 bg-white" style="box-shadow:0 1px 0 #E8E0F0;">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest whitespace-nowrap" style="color:#000000;">User</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-widest hidden md:table-cell whitespace-nowrap" style="color:#000000;">Identifier</th>
                            <th class="px-2 sm:px-4 py-3 text-center text-xs font-semibold uppercase tracking-widest hidden sm:table-cell whitespace-nowrap" style="color:#000000;">Role</th>
                            <th class="px-2 sm:px-4 py-3 text-center text-xs font-semibold uppercase tracking-widest whitespace-nowrap" style="color:#000000;">Status</th>
                            <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-widest hidden xl:table-cell whitespace-nowrap" style="color:#000000;">Email</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[#F5F5F5]">
                        @foreach($pu->items as $index => $u)
                        @php
                            $rowStatus  = $u->computed_status ?? $u->user_status ?? 'ACTIVE';
                            $identifier = match($u->role) {
                                'alumni'    => $u->student_id ?: '—',
                                'organizer' => $u->id_number  ?: '—',
                                // Teacher ID is the director's login username, stored as the
                                // local part of their @director.internal email. Directors
                                // created before this change won't have a .internal email,
                                // so adminUsername() falls back to their name — no backfill
                                // needed, existing records are left exactly as they are.
                                'director'  => $this->adminUsername($u->email, $u->name),
                                default     => $this->adminUsername($u->email, $u->name),
                            };
                            $rowDisplayName = match($u->role) {
                                'alumni'    => trim(implode(' ', array_filter([$u->alumni_first_name, $u->alumni_middle_name, $u->alumni_last_name]))) ?: $u->name,
                                'organizer' => trim(implode(' ', array_filter([$u->org_first_name, $u->org_last_name]))) ?: $u->name,
                                'director'  => trim(implode(' ', array_filter([$u->dir_first_name, $u->dir_middle_name, $u->dir_last_name, $u->dir_suffix]))) ?: $u->name,
                                'admin'     => $this->adminUsername($u->email, $u->name),
                                default     => $u->name,
                            };
                            $roleDisplay = $this->roleLabel($u->role);
                            $roleCss     = $this->roleBadge($u->role);
                            $rowEmail = match($u->role) {
                                'alumni'   => (!empty($u->record_email) && !str_contains($u->record_email, '@pending.local')) ? $u->record_email : $u->email,
                                'director' => $u->director_email ?: $u->email,
                                default    => $u->email,
                            };
                        @endphp
                        <tr class="mu-tbl-row" wire:click="showProfile({{ $u->id }})"
                            wire:key="mu-row-{{ $u->id }}" data-mu-row>
                            <td class="px-3 sm:px-4 py-3.5 min-w-0">
                                <div class="flex items-center gap-2 sm:gap-3 min-w-0">
                                    @unless($u->role === 'registrar')
                                    <img src="{{ $this->photoUrl($u->photo ?? '') }}" alt="{{ $rowDisplayName }}"
                                         class="w-8 h-8 sm:w-9 sm:h-9 rounded-xl object-cover flex-shrink-0 shadow ring-1 ring-[#E8E0F0]">
                                    @endunless
                                    <div class="min-w-0">
                                        <p class="font-semibold text-xs sm:text-sm leading-snug truncate uppercase" style="color:#000000;">
                                            {!! $this->highlightText($rowDisplayName) !!}
                                        </p>
                                        {{-- Role shows inline here only on the narrowest screens, where
                                             the dedicated Role column is hidden, so the info isn't lost
                                             — just relocated to keep the row from getting cramped. --}}
                                        <p class="sm:hidden text-[10px] font-semibold uppercase tracking-wide mt-0.5" style="color:#7a3f91;">
                                            {{ $roleDisplay }}
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3.5 hidden md:table-cell">
                                <span class="font-mono text-xs font-semibold px-2 py-1 rounded-lg bg-gray-50 border border-gray-200" style="color:#000000;">
                                    {{ $identifier }}
                                </span>
                            </td>
                            <td class="px-2 sm:px-4 py-3.5 text-center hidden sm:table-cell">
                                <span class="mu-role-badge {{ $roleCss }}">
                                    {{ $roleDisplay }}
                                </span>
                            </td>
                            <td class="px-2 sm:px-4 py-3.5 text-center">
                                <span class="inline-flex items-center px-2 sm:px-2.5 py-1 rounded-xl text-[10px] sm:text-xs font-semibold border whitespace-nowrap {{ $this->statusBadge($rowStatus) }}">
                                    {{ $rowStatus === 'VERIFIED' ? 'COMPLETE' : $rowStatus }}
                                </span>
                            </td>
                            <td class="px-4 py-3.5 text-center hidden xl:table-cell">
                                <span class="text-sm font-semibold truncate" style="color:#000000;">
                                    {{ $rowEmail ?: '—' }}
                                </span>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @else
            <div class="flex-1 flex flex-col items-center justify-center gap-4 text-center px-6 py-16 bg-white">
                <div class="w-14 h-14 rounded-2xl flex items-center justify-center bg-gray-100">
                    <i class="fas fa-users-slash text-xl text-gray-400"></i>
                </div>
                <div>
                    <p class="font-semibold text-base" style="color:#000000;">
                        {{ $search ? 'No users match your filters' : 'No users found' }}
                    </p>
                    <p class="text-sm mt-1" style="color:#000000;">
                        {{ $search ? 'Try clearing your filters to see all users.' : 'No users are registered in this category yet.' }}
                    </p>
                </div>
                @if($search)
                <button wire:click="switchTab('all')"
                        wire:loading.attr="disabled" wire:target="switchTab('all')"
                        class="px-4 py-2 rounded-xl text-sm font-bold text-white transition cursor-pointer"
                        style="background-color:#7a3f91;">
                    <span wire:loading wire:target="switchTab('all')"><i class="fas fa-spinner animate-spin mr-1.5 text-xs"></i> Clearing…</span>
                    <span wire:loading.remove wire:target="switchTab('all')"><i class="fas fa-rotate-left mr-1.5 text-xs"></i> Clear Filters</span>
                </button>
                @endif
            </div>
            @endif
        </div>

        {{-- PAGINATION --}}
        @php
            $pgStart = max(1, $pu->currentPage - 2);
            $pgEnd   = min($pu->lastPage, $pu->currentPage + 2);
        @endphp
        <div class="mu-table-block-pagination">
            <p class="text-white/80 text-xs font-normal whitespace-nowrap">
                Showing <strong class="text-white font-bold">{{ $pu->from }}&ndash;{{ $pu->to }}</strong>
                of <strong class="text-white font-bold">{{ $pu->total }}</strong> users
                @if($search)
                    <span class="text-white/60 text-xs ml-1">(filtered)</span>
                @endif
            </p>
            <div class="flex items-center gap-1 flex-wrap py-2">
                <button wire:click="previousPage"
                        wire:loading.attr="disabled" wire:target="previousPage,nextPage,goToPage,switchTab,setStatusFilter,search"
                        class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                               bg-white/15 border border-white/25 text-white
                               hover:bg-white/28 hover:border-white/50 disabled:opacity-35 disabled:cursor-not-allowed transition"
                        title="Previous Page"
                        @if(!$pu->hasPrev) disabled @endif>
                    <i class="fas fa-chevron-left text-xs"></i>
                </button>

                @if($pgStart > 1)
                    <button wire:click="goToPage(1)"
                            wire:loading.attr="disabled" wire:target="goToPage(1)"
                            class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                   bg-white/15 border border-white/25 text-white hover:bg-white/28 transition disabled:opacity-50"
                            title="Go to Page 1">
                        <span wire:loading wire:target="goToPage(1)"><i class="fas fa-spinner animate-spin text-xs"></i></span>
                        <span wire:loading.remove wire:target="goToPage(1)">1</span>
                    </button>
                    @if($pgStart > 2)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
                @endif

                @for($p = $pgStart; $p <= $pgEnd; $p++)
                    @if($p === $pu->currentPage)
                        <span class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                     bg-white text-[#7a3f91] border border-white">{{ $p }}</span>
                    @else
                        <button wire:click="goToPage({{ $p }})"
                                wire:loading.attr="disabled" wire:target="goToPage({{ $p }})"
                                class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                       bg-white/15 border border-white/25 text-white hover:bg-white/28 transition disabled:opacity-50"
                                title="Go to Page {{ $p }}">
                            <span wire:loading wire:target="goToPage({{ $p }})"><i class="fas fa-spinner animate-spin text-xs"></i></span>
                            <span wire:loading.remove wire:target="goToPage({{ $p }})">{{ $p }}</span>
                        </button>
                    @endif
                @endfor

                @if($pgEnd < $pu->lastPage)
                    @if($pgEnd < $pu->lastPage - 1)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
                    <button wire:click="goToPage({{ $pu->lastPage }})"
                            wire:loading.attr="disabled" wire:target="goToPage({{ $pu->lastPage }})"
                            class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                                   bg-white/15 border border-white/25 text-white hover:bg-white/28 transition disabled:opacity-50"
                            title="Go to Page {{ $pu->lastPage }}">
                        <span wire:loading wire:target="goToPage({{ $pu->lastPage }})"><i class="fas fa-spinner animate-spin text-xs"></i></span>
                        <span wire:loading.remove wire:target="goToPage({{ $pu->lastPage }})">{{ $pu->lastPage }}</span>
                    </button>
                @endif

                <button wire:click="nextPage"
                        wire:loading.attr="disabled" wire:target="previousPage,nextPage,goToPage,switchTab,setStatusFilter,search"
                        class="inline-flex items-center justify-center min-w-[32px] h-8 px-2.5 rounded-lg text-xs font-bold
                               bg-white/15 border border-white/25 text-white
                               hover:bg-white/28 hover:border-white/50 disabled:opacity-35 disabled:cursor-not-allowed transition"
                        title="Next Page"
                        @if(!$pu->hasNext) disabled @endif>
                    <i class="fas fa-chevron-right text-xs"></i>
                </button>

                <span class="hidden sm:inline text-white/80 text-xs font-semibold whitespace-nowrap ml-1">
                    Page {{ $pu->currentPage }}/{{ $pu->lastPage }}
                </span>
            </div>
        </div>

    </div>{{-- /mu-table-block --}}

</div>{{-- /main layout --}}


{{-- ═══════════════════════════════════════════════════════════
     UNIFIED VIEW + EDIT PROFILE MODAL
     ═══════════════════════════════════════════════════════════ --}}
@if($activeModal === 'viewProfile' && $vData)
@php
    $vd       = $vData;
    $vRole    = $vd['role'];
    $vStatus  = $vd['user_status'];
    $isAlumni = $vRole === 'alumni';
    $isOrg    = $vRole === 'organizer';
    $isDir    = $vRole === 'director';
    $isReg    = $vRole === 'registrar';
    $isAdmin  = $vRole === 'admin';
    $canPhoto = in_array($vRole, ['director']);
    $canToggle= in_array($vRole, ['director','registrar']);

    if ($isDir)
        $headerName = implode(' ', array_filter([$vd['first_name']??'', $vd['middle_name']??'', $vd['last_name']??'', $vd['suffix']??''])) ?: $vd['name'];
    elseif ($isAlumni)
        $headerName = implode(' ', array_filter([$vd['alumni_first_name']??'', $vd['alumni_middle_name']??'', $vd['alumni_last_name']??'', $vd['alumni_suffix']??''])) ?: $vd['name'];
    elseif ($isAdmin)
        $headerName = $this->adminUsername($vd['email'], $vd['name']);
    else
        $headerName = $vd['name'];

    $headerSub = '';
    if ($isAlumni && !empty($vd['record_email']) && !str_contains($vd['record_email'],'@pending.local'))
        $headerSub = $vd['record_email'];
    elseif ($isDir && !empty($vd['director_email']))
        $headerSub = $vd['director_email'];
    elseif ($isReg)
        $headerSub = !empty($vd['email']) ? explode('@', $vd['email'])[0] : '';
    elseif (!str_ends_with($vd['email']??'','.internal'))
        $headerSub = $vd['email'];
@endphp
<div class="fixed inset-0"
     style="background:rgba(27,6,46,0.55);backdrop-filter:blur(3px);z-index:9995;"
     x-data="{ muClosing: false }"
     x-show="!muClosing"
     x-init="muClosing = false"
     @keydown.escape.window="$wire.closeModal(); setTimeout(() => muClosing = true, 220)">
    <div class="w-full h-full flex flex-col" style="background:#F2F2F2;overflow:hidden;">

        <div class="flex items-center justify-between px-5 sm:px-6 py-3 shrink-0" style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
            <div class="flex items-center gap-3 min-w-0">
                @if($vPhoto)
                    <img src="{{ $vPhoto->temporaryUrl() }}" alt="Preview"
                         class="w-10 h-10 rounded-xl object-cover flex-shrink-0 ring-2 ring-white/30">
                @else
                    <img src="{{ $this->photoUrl($vd['photo'] ?? '') }}" alt="{{ $headerName }}"
                         class="w-10 h-10 rounded-xl object-cover flex-shrink-0 ring-2 ring-white/30">
                @endif
                <div class="min-w-0">
                    <p class="font-semibold text-white text-sm leading-snug uppercase truncate">{{ $headerName }}</p>
                    <p class="text-xs text-white/70 mt-0.5 truncate">{{ $headerSub ?: $this->roleLabel($vRole) }}</p>
                </div>
            </div>
            <button @click="$wire.closeModal(); setTimeout(() => muClosing = true, 220)" wire:loading.attr="disabled" wire:target="closeModal"
                    class="mu-close-tooltip w-8 h-8 rounded-xl bg-white/20 hover:bg-white/30 flex items-center justify-center transition text-white shrink-0">
                <i wire:loading.remove wire:target="closeModal" class="fa-solid fa-xmark text-base"></i>
                <i wire:loading wire:target="closeModal" class="fas fa-spinner animate-spin text-base"></i>
            </button>
        </div>

        <div class="flex-1 min-h-0 overflow-y-auto p-4 sm:p-5 space-y-3 mu-vp-scroll max-w-4xl mx-auto w-full">

            {{-- SUMMARY CARD: photo + name + role/batch line + email --}}
            <div class="bg-white rounded-xl border border-[#E8E0F0] p-3 flex items-start gap-4">
                @unless($isReg)
                <div class="flex flex-col items-center gap-1.5 shrink-0"
                     x-data="{ dragging: false }"
                     @if($canPhoto)
                     @dragover.prevent="dragging=true"
                     @dragleave.prevent="dragging=false"
                     @drop.prevent="dragging=false; $wire.upload('vPhoto', $event.dataTransfer.files[0])"
                     @endif>
                    <label @if($canPhoto) for="vPhotoInput" @endif class="relative group {{ $canPhoto ? 'cursor-pointer' : '' }}">
                        @if($vPhoto)
                            <img src="{{ $vPhoto->temporaryUrl() }}" alt="Preview"
                                 class="w-14 h-14 rounded-xl object-cover ring-2 ring-[#7A3F91]/30" :class="dragging ? 'ring-[#7A3F91]' : ''">
                        @else
                            <img src="{{ $this->photoUrl($vd['photo'] ?? '') }}" alt="{{ $headerName }}"
                                 class="w-14 h-14 rounded-xl object-cover ring-2 ring-[#E8E0F0]" :class="dragging ? 'ring-[#7A3F91]' : ''">
                        @endif
                        @if($canPhoto)
                        <div class="absolute inset-0 rounded-xl bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                            <i class="fas fa-camera text-white text-xs"></i>
                        </div>
                        <input id="vPhotoInput" type="file" wire:model="vPhoto" accept="image/*" class="hidden">
                        @endif
                    </label>
                    @if($canPhoto)
                    <p class="text-xs font-semibold uppercase tracking-wide text-center leading-tight" style="color:#000000;">Hover to<br>change</p>
                    @endif
                    @if($vPhoto)
                    <div class="flex flex-col items-center gap-1 w-14">
                        <button wire:click="savePhoto" wire:loading.attr="disabled" wire:target="savePhoto"
                                class="w-full px-1.5 py-1 rounded-lg text-xs font-bold text-white transition hover:opacity-90 flex items-center justify-center gap-1"
                                style="background:#7A3F91;">
                            <span wire:loading wire:target="savePhoto"><i class="fas fa-spinner animate-spin text-xs"></i></span>
                            <span wire:loading.remove wire:target="savePhoto"><i class="fas fa-check text-xs"></i> Save</span>
                        </button>
                        <button wire:click="$set('vPhoto', null)" wire:loading.attr="disabled" wire:target="savePhoto"
                                class="w-full px-1.5 py-1 rounded-lg text-xs font-semibold border border-[#E8E0F0] hover:bg-gray-50 transition disabled:opacity-50" style="color:#000000;">Cancel</button>
                    </div>
                    @endif
                    <div wire:loading wire:target="vPhoto" class="flex items-center gap-1 text-xs font-medium" style="color:#7A3F91;">
                        <i class="fas fa-spinner animate-spin text-xs"></i> Uploading…
                    </div>
                    @error('vPhoto')<p class="text-xs text-red-600 text-center flex items-center gap-1"><i class="fas fa-circle-exclamation text-xs"></i>{{ $message }}</p>@enderror
                </div>
                @endunless

                <div class="min-w-0 flex-1">
                    <p class="text-lg font-bold uppercase leading-tight" style="color:#000000;">{{ $headerName }}</p>

                    @if($isAlumni)
                        <p class="text-sm font-semibold mt-0.5" style="color:#000000;">{{ $vd['student_id'] ?: '—' }}</p>
                        <div class="flex flex-wrap items-center gap-1.5 mt-1">
                            <span class="text-sm font-bold" style="color:#000000;">{{ $vd['course_code'] ?: '—' }}</span>
                            <span style="color:#000000;">&middot;</span>
                            <span class="text-sm font-bold" style="color:#000000;">Batch {{ $vd['batch'] ?: '—' }}</span>
                            <span style="color:#000000;">&middot;</span>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-bold border {{ $vStatus === 'VERIFIED' ? 'text-emerald-700 border-emerald-300 bg-emerald-50' : 'text-amber-700 border-amber-300 bg-amber-50' }}">
                                {{ $vStatus === 'VERIFIED' ? 'COMPLETE' : 'PENDING' }}
                            </span>
                        </div>
                    @else
                        <div class="flex flex-wrap items-center gap-1.5 mt-1">
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border {{ $this->roleBadge($vRole) }}">
                                {{ $this->roleLabel($vRole) }}
                            </span>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border {{ $this->statusBadge($vStatus) }}">
                                {{ $vStatus }}
                            </span>
                        </div>
                    @endif

                    @unless($isReg)
                    <p class="text-sm mt-1 font-medium" style="color:#000000;">{{ $headerSub ?: '—' }}</p>
                    @endunless

                    <p class="text-xs font-semibold mt-1" style="color:#000000;">
                        <i class="fa-regular fa-calendar mr-1"></i>
                        Joined {{ \Carbon\Carbon::parse($vd['created_at'])->timezone('Asia/Manila')->format('M d, Y') }}
                    </p>
                </div>
            </div>

            {{-- ALUMNI: STUDENT ID + STUDENT'S NAME --}}
            @if($isAlumni)
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div class="bg-white rounded-xl border border-[#E8E0F0] overflow-hidden">
                    <div class="px-3.5 py-2 border-b border-[#E8E0F0]" style="background:#F9F7FC;">
                        <p class="text-xs font-bold uppercase tracking-widest" style="color:#000000;">Student ID</p>
                    </div>
                    <div class="p-3">
                        <div class="bg-gray-50 rounded-xl px-2.5 py-2 border border-[#E8E0F0]">
                            <p class="text-xs font-semibold uppercase tracking-widest mb-0.5" style="color:#000000;">Student ID</p>
                            <p class="text-xs font-semibold" style="color:#000000;">{{ $vd['student_id'] ?: '—' }}</p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl border border-[#E8E0F0] overflow-hidden">
                    <div class="px-3.5 py-2 border-b border-[#E8E0F0]" style="background:#F9F7FC;">
                        <p class="text-xs font-bold uppercase tracking-widest" style="color:#000000;">Student's Name</p>
                    </div>
                    <div class="p-3 grid grid-cols-2 gap-2">
                        @foreach([
                            ['Last Name',    $vd['alumni_last_name']   ?? ''],
                            ['Given Name',   $vd['alumni_first_name']  ?? ''],
                            ['Middle Name',  $vd['alumni_middle_name'] ?? ''],
                            ['Ext.',         $vd['alumni_suffix']      ?? ''],
                        ] as [$lbl,$val])
                        <div class="bg-gray-50 rounded-xl px-2.5 py-2 border border-[#E8E0F0]">
                            <p class="text-xs font-semibold uppercase tracking-widest mb-0.5" style="color:#000000;">{{ $lbl }}</p>
                            <p class="text-xs font-semibold" style="color:#000000;">{{ $val ?: '—' }}</p>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- ALUMNI: PROGRAM --}}
            <div class="bg-white rounded-xl border border-[#E8E0F0] overflow-hidden">
                <div class="px-3.5 py-2 border-b border-[#E8E0F0]" style="background:#F9F7FC;">
                    <p class="text-xs font-bold uppercase tracking-widest" style="color:#000000;">Program</p>
                </div>
                <div class="p-3 grid grid-cols-1 sm:grid-cols-2 gap-2">
                    <div class="bg-gray-50 rounded-xl px-2.5 py-2 border border-[#E8E0F0]">
                        <p class="text-xs font-semibold uppercase tracking-widest mb-0.5" style="color:#000000;">Program Code</p>
                        <p class="text-xs font-semibold" style="color:#000000;">{{ $vd['course_code'] ?: '—' }}</p>
                    </div>
                    <div class="bg-gray-50 rounded-xl px-2.5 py-2 border border-[#E8E0F0]">
                        <p class="text-xs font-semibold uppercase tracking-widest mb-0.5" style="color:#000000;">Program Name</p>
                        <p class="text-xs font-semibold" style="color:#000000;">{{ $vd['course_name'] ?: '—' }}</p>
                    </div>
                </div>
            </div>
            @endif

            {{-- DIRECTOR INFO --}}
            @if($isDir)
            <div class="bg-white rounded-xl border border-[#E8E0F0] overflow-hidden">
                <div class="px-3.5 py-2 border-b border-[#E8E0F0]" style="background:#F9F7FC;">
                    <p class="text-xs font-bold uppercase tracking-widest" style="color:#000000;">Director Information</p>
                </div>
                <div class="p-3 grid grid-cols-2 sm:grid-cols-4 gap-2">
                    @foreach([
                        ['First Name',  $vd['first_name']  ?? '—'],
                        ['Middle Name', $vd['middle_name'] ?? '—'],
                        ['Last Name',   $vd['last_name']   ?? '—'],
                        ['Suffix',      $vd['suffix']      ?? '—'],
                        // Same as Coordinator's Teacher ID — the director's login
                        // username, read from the local part of their
                        // @director.internal email. Directors made before this
                        // change won't have one, so this reads '—' for them;
                        // nothing about their existing record is touched.
                        ['Teacher ID',  $this->adminUsername($vd['email'] ?? '', $vd['name'] ?? '')],
                    ] as [$lbl,$val])
                    <div class="bg-gray-50 rounded-xl px-2.5 py-2 border border-[#E8E0F0]">
                        <p class="text-xs font-semibold uppercase tracking-widest mb-0.5" style="color:#000000;">{{ $lbl }}</p>
                        <p class="text-xs font-semibold" style="color:#000000;">{{ $val ?: '—' }}</p>
                    </div>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- COORDINATOR INFO --}}
            @if($isOrg)
            <div class="bg-white rounded-xl border border-[#E8E0F0] overflow-hidden">
                <div class="px-3.5 py-2 border-b border-[#E8E0F0]" style="background:#F9F7FC;">
                    <p class="text-xs font-bold uppercase tracking-widest" style="color:#000000;">Coordinator Details</p>
                </div>
                <div class="p-3 grid grid-cols-2 gap-2">
                    <div class="bg-gray-50 rounded-xl px-2.5 py-2 border border-[#E8E0F0]">
                        <p class="text-xs font-semibold uppercase tracking-widest mb-0.5" style="color:#000000;">Teacher ID</p>
                        <p class="text-xs font-bold font-mono" style="color:#000000;">{{ $vd['id_number'] ?: '—' }}</p>
                    </div>
                    <div class="bg-gray-50 rounded-xl px-2.5 py-2 border border-[#E8E0F0]">
                        <p class="text-xs font-semibold uppercase tracking-widest mb-0.5" style="color:#000000;">College / Dept</p>
                        <p class="text-xs font-semibold" style="color:#000000;">{{ $vd['department'] ?: '—' }}</p>
                    </div>
                </div>
            </div>
            @endif

            {{-- ADMIN INFO --}}
            @if($isAdmin)
            <div class="bg-white rounded-xl border border-[#E8E0F0] overflow-hidden">
                <div class="px-3.5 py-2 border-b border-[#E8E0F0]" style="background:#F9F7FC;">
                    <p class="text-xs font-bold uppercase tracking-widest" style="color:#000000;">Account Details</p>
                </div>
                <div class="p-3 grid grid-cols-2 gap-2">
                    <div class="bg-gray-50 rounded-xl px-2.5 py-2 border border-[#E8E0F0]">
                        <p class="text-xs font-semibold uppercase tracking-widest mb-0.5" style="color:#000000;">Username</p>
                        <p class="text-xs font-bold font-mono" style="color:#000000;">
                            {{ $this->adminUsername($vd['email'], $vd['name']) }}
                        </p>
                    </div>
                    <div class="bg-gray-50 rounded-xl px-2.5 py-2 border border-[#E8E0F0]">
                        <p class="text-xs font-semibold uppercase tracking-widest mb-0.5" style="color:#000000;">Role</p>
                        <p class="text-xs font-semibold" style="color:#000000;">{{ $this->roleLabel($vRole) }}</p>
                    </div>
                </div>
            </div>
            @endif

            {{-- UPDATE USERNAME — Registrar --}}
            @if($isReg)
            @php
                $currentUsername = $vd['name'] ?? null;
                $ueCooldown = $this->ueCooldownDaysLeft();
            @endphp
            <div class="bg-white rounded-xl border border-[#E8E0F0] overflow-hidden">
                <div class="px-3.5 py-2 border-b border-[#E8E0F0]" style="background:#F9F7FC;">
                    <p class="text-xs font-bold uppercase tracking-widest" style="color:#000000;">Username</p>
                </div>
                <div class="p-3">
                    <div class="mb-2.5 p-2.5 rounded-xl flex items-start gap-2" style="background:#fef2f2;border:1px solid #fecaca;">
                        <i class="fas fa-triangle-exclamation text-red-500 text-xs mt-0.5 shrink-0"></i>
                        <p class="text-xs font-semibold leading-snug" style="color:#991b1b;">
                            This is the registrar's login username — this account has no separate email, only a username. Changing it takes effect immediately: their old username/password stops working right away, and no notification is sent automatically. Give them the new username and a new password yourself.
                        </p>
                    </div>
                    @if($ueCooldown > 0)
                    <div class="mb-2.5 p-2.5 rounded-xl flex items-start gap-2" style="background:#f3f0fa;border:1px solid #E8E0F0;">
                        <i class="fas fa-lock text-xs mt-0.5 shrink-0" style="color:#7A3F91;"></i>
                        <p class="text-xs font-semibold leading-snug" style="color:#7A3F91;">
                            Username was updated recently. You can change it again in {{ $ueCooldown }} day{{ $ueCooldown === 1 ? '' : 's' }}.
                        </p>
                    </div>
                    @endif
                    @if($ueSuccess)
                    <div class="mb-2.5 p-2.5 rounded-xl bg-emerald-50 border border-emerald-200 flex items-start gap-2">
                        <i class="fas fa-circle-check text-emerald-600 text-xs mt-0.5 shrink-0"></i>
                        <p class="text-xs font-semibold text-emerald-800 leading-snug">{{ $ueSuccess }}</p>
                    </div>
                    @endif
                    @if(count($ueErrors))
                    <div class="mb-2.5 p-2.5 rounded-xl bg-red-50 border border-red-200 space-y-1">
                        @foreach($ueErrors as $msgs)
                            @foreach($msgs as $msg)
                            <p class="text-xs text-red-700 flex items-start gap-2"><i class="fas fa-circle-exclamation shrink-0 mt-0.5 text-xs"></i><span>{{ $msg }}</span></p>
                            @endforeach
                        @endforeach
                    </div>
                    @endif
                    <div class="flex gap-2">
                        <div class="relative flex-1">
                            <i class="fas fa-user absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                            <input wire:model.defer="ueEmail" type="text" placeholder="New username…"
                                   @if($ueCooldown > 0) disabled @endif
                                   class="mu-filter-input w-full {{ $ueCooldown > 0 ? 'opacity-50 cursor-not-allowed' : '' }}" style="padding-left:2.25rem;" autocomplete="off">
                        </div>
                        <button wire:click="saveUpdateEmail" wire:loading.attr="disabled" wire:target="saveUpdateEmail"
                                @if($ueCooldown > 0) disabled @endif
                                class="px-3.5 py-1.5 rounded-lg text-xs font-bold text-white transition hover:opacity-90 flex items-center gap-1.5 flex-shrink-0 {{ $ueCooldown > 0 ? 'opacity-50 cursor-not-allowed' : '' }}"
                                style="background:#7A3F91;">
                            <span wire:loading wire:target="saveUpdateEmail"><i class="fas fa-spinner animate-spin text-xs"></i></span>
                            <span wire:loading.remove wire:target="saveUpdateEmail"><i class="fas fa-{{ $ueCooldown > 0 ? 'lock' : 'check' }} text-xs"></i> Update</span>
                        </button>
                    </div>
                    <div class="mt-1.5 p-2.5 rounded-xl flex items-start gap-2" style="background:#fffbeb;border:1px solid #fde68a;">
                        <i class="fas fa-key text-amber-500 text-xs mt-0.5 shrink-0"></i>
                        <p class="text-xs font-semibold leading-snug" style="color:#92400e;">After you update this, log in with the new username uses whatever password is currently set — set a new one below if needed.</p>
                    </div>
                </div>
            </div>
            @endif

            {{-- UPDATE EMAIL — Alumni / Director --}}
            @if($isAlumni || $isDir)
            @php
                $ueCurrent = $isAlumni
                    ? ((!empty($vd['record_email']) && !str_contains($vd['record_email'],'@pending.local')) ? $vd['record_email'] : null)
                    : ($vd['director_email'] ?: null);
                $ueNote = $isDir
                    ? 'This is the director\'s contact email. It is not used to log in.'
                    : 'Updating the email will require the account to reset their password on next login.';
                $ueCooldown = $this->ueCooldownDaysLeft();
            @endphp
            <div class="bg-white rounded-xl border border-[#E8E0F0] overflow-hidden">
                <div class="px-3.5 py-2 border-b border-[#E8E0F0]" style="background:#F9F7FC;">
                    <p class="text-xs font-bold uppercase tracking-widest" style="color:#000000;">Email Address</p>
                </div>
                <div class="p-3">
                    <div class="bg-gray-50 rounded-xl px-2.5 py-2 border border-[#E8E0F0] mb-2.5">
                        <p class="text-xs font-semibold uppercase tracking-widest mb-0.5" style="color:#000000;">Current Email</p>
                        <p class="text-xs font-semibold break-all" style="color:#000000;">
                            @if($ueCurrent)
                                {{ $ueCurrent }}
                            @else
                                <span class="italic" style="color:#000000;">Not set</span>
                            @endif
                        </p>
                    </div>
                    @if($ueCooldown > 0)
                    <div class="mb-2.5 p-2.5 rounded-xl flex items-start gap-2" style="background:#f3f0fa;border:1px solid #E8E0F0;">
                        <i class="fas fa-lock text-xs mt-0.5 shrink-0" style="color:#7A3F91;"></i>
                        <p class="text-xs font-semibold leading-snug" style="color:#7A3F91;">
                            Email was updated recently. You can change it again in {{ $ueCooldown }} day{{ $ueCooldown === 1 ? '' : 's' }}.
                        </p>
                    </div>
                    @endif
                    @if($ueSuccess)
                    <div class="mb-2.5 p-2.5 rounded-xl bg-emerald-50 border border-emerald-200 flex items-start gap-2">
                        <i class="fas fa-circle-check text-emerald-600 text-xs mt-0.5 shrink-0"></i>
                        <p class="text-xs font-semibold text-emerald-800 leading-snug">{{ $ueSuccess }}</p>
                    </div>
                    @endif
                    @if(count($ueErrors))
                    <div class="mb-2.5 p-2.5 rounded-xl bg-red-50 border border-red-200 space-y-1">
                        @foreach($ueErrors as $msgs)
                            @foreach($msgs as $msg)
                            <p class="text-xs text-red-700 flex items-start gap-2"><i class="fas fa-circle-exclamation shrink-0 mt-0.5 text-xs"></i><span>{{ $msg }}</span></p>
                            @endforeach
                        @endforeach
                    </div>
                    @endif
                    <div class="flex gap-2">
                        <div class="relative flex-1">
                            <i class="fas fa-envelope absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs pointer-events-none"></i>
                            <input wire:model.defer="ueEmail" type="email" placeholder="New email address…"
                                   @if($ueCooldown > 0) disabled @endif
                                   class="mu-filter-input w-full {{ $ueCooldown > 0 ? 'opacity-50 cursor-not-allowed' : '' }}" style="padding-left:2.25rem;" autocomplete="off">
                        </div>
                        <button wire:click="saveUpdateEmail" wire:loading.attr="disabled" wire:target="saveUpdateEmail"
                                @if($ueCooldown > 0) disabled @endif
                                class="px-3.5 py-1.5 rounded-lg text-xs font-bold text-white transition hover:opacity-90 flex items-center gap-1.5 flex-shrink-0 {{ $ueCooldown > 0 ? 'opacity-50 cursor-not-allowed' : '' }}"
                                style="background:#7A3F91;">
                            <span wire:loading wire:target="saveUpdateEmail"><i class="fas fa-spinner animate-spin text-xs"></i></span>
                            <span wire:loading.remove wire:target="saveUpdateEmail"><i class="fas fa-{{ $ueCooldown > 0 ? 'lock' : 'check' }} text-xs"></i> Update</span>
                        </button>
                    </div>
                    <div class="mt-1.5 p-2.5 rounded-xl flex items-start gap-2" style="background:#fffbeb;border:1px solid #fde68a;">
                        <i class="fas fa-key text-amber-500 text-xs mt-0.5 shrink-0"></i>
                        <p class="text-xs font-semibold leading-snug" style="color:#92400e;">{{ $ueNote }}</p>
                    </div>
                </div>
            </div>
            @endif

            {{-- CHANGE PASSWORD — Registrar / Admin --}}
            @if($isReg || $isAdmin)
            <div class="bg-white rounded-xl border border-[#E8E0F0] overflow-hidden">
                <div class="px-3.5 py-2 border-b border-[#E8E0F0]" style="background:#F9F7FC;">
                    <p class="text-xs font-bold uppercase tracking-widest" style="color:#000000;">Change Password</p>
                </div>
                <div class="p-3">
                    @if(count($cpErrs))
                    <div class="mb-2.5 p-2.5 rounded-xl bg-red-50 border border-red-200 space-y-1">
                        @foreach($cpErrs as $msgs)
                            @foreach($msgs as $msg)
                            <p class="text-xs text-red-700 flex items-start gap-2"><i class="fas fa-circle-exclamation shrink-0 mt-0.5 text-xs"></i><span>{{ $msg }}</span></p>
                            @endforeach
                        @endforeach
                    </div>
                    @endif
                    <div class="grid grid-cols-2 gap-1.5 mb-1.5">
                        <input wire:model.defer="cpNew" type="password" placeholder="New password (min. 8)"
                               class="mu-filter-input w-full" autocomplete="new-password">
                        <input wire:model.defer="cpConfirm" type="password" placeholder="Confirm new password"
                               class="mu-filter-input w-full" autocomplete="new-password">
                    </div>
                    <button wire:click="saveChangePassword" wire:loading.attr="disabled" wire:target="saveChangePassword"
                            class="px-3.5 py-1.5 rounded-lg text-xs font-bold text-white transition hover:opacity-90 flex items-center gap-1.5"
                            style="background:#7A3F91;">
                        <span wire:loading wire:target="saveChangePassword"><i class="fas fa-spinner animate-spin text-xs"></i> Saving…</span>
                        <span wire:loading.remove wire:target="saveChangePassword"><i class="fas fa-key text-xs"></i> Update Password</span>
                    </button>
                    <div class="mt-1.5 p-2.5 rounded-xl flex items-start gap-2" style="background:#fffbeb;border:1px solid #fde68a;">
                        <i class="fas fa-rotate-left text-amber-500 text-xs mt-0.5 shrink-0"></i>
                        <p class="text-xs font-semibold leading-snug" style="color:#92400e;">
                            @if($isReg)
                                This is set as their actual login password right away — they can log in with it immediately using their current username. No reset link is sent, so give them this password directly.
                            @else
                                After saving, the user will be required to change their password on next login.
                            @endif
                        </p>
                    </div>
                </div>
            </div>
            @endif

            {{-- ACTIVATE / DEACTIVATE --}}
            @if($canToggle)
            <div class="bg-white rounded-xl border border-[#E8E0F0] overflow-hidden">
                <div class="px-3.5 py-2 border-b border-[#E8E0F0]" style="background:#F9F7FC;">
                    <p class="text-xs font-bold uppercase tracking-widest" style="color:#000000;">Account Status</p>
                </div>
                <div class="p-3">
                    @if($vStatus === 'ACTIVE')
                    <button wire:click="confirmToggle({{ $vd['id'] }}, 'deactivate')"
                            wire:loading.attr="disabled" wire:target="confirmToggle({{ $vd['id'] }}, 'deactivate')"
                            class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl text-xs font-bold transition hover:opacity-90 disabled:opacity-50"
                            style="background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;">
                        <span wire:loading wire:target="confirmToggle({{ $vd['id'] }}, 'deactivate')"><i class="fas fa-spinner animate-spin text-xs"></i></span>
                        <span wire:loading.remove wire:target="confirmToggle({{ $vd['id'] }}, 'deactivate')"><i class="fas fa-ban text-xs"></i></span>
                        Deactivate Account
                    </button>
                    @else
                    <button wire:click="confirmToggle({{ $vd['id'] }}, 'activate')"
                            wire:loading.attr="disabled" wire:target="confirmToggle({{ $vd['id'] }}, 'activate')"
                            class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-xl text-xs font-bold transition hover:opacity-90 disabled:opacity-50"
                            style="background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;">
                        <span wire:loading wire:target="confirmToggle({{ $vd['id'] }}, 'activate')"><i class="fas fa-spinner animate-spin text-xs"></i></span>
                        <span wire:loading.remove wire:target="confirmToggle({{ $vd['id'] }}, 'activate')"><i class="fas fa-circle-check text-xs"></i></span>
                        Activate Account
                    </button>
                    @endif
                </div>
            </div>
            @endif

        </div>{{-- /scroll --}}
    </div>{{-- /panel --}}
</div>
@endif


{{-- ═══════════════════════════════════════════════════════════
     CREATE DIRECTOR MODAL
     ═══════════════════════════════════════════════════════════ --}}
@if($activeModal === 'createDirector')
<div class="fixed inset-0"
     style="background:rgba(0,0,0,0.55);backdrop-filter:blur(3px);z-index:9995;"
     x-data="{ muClosing: false, dPhotoFull: false }"
     x-show="!muClosing"
     x-init="muClosing = false"
     @keydown.escape.window="dPhotoFull ? (dPhotoFull = false) : ($wire.closeModal(), setTimeout(() => muClosing = true, 220))">
    <div class="w-full h-full flex flex-col" style="background:#FFFFFF;overflow:hidden;">

        <div class="flex items-center justify-between px-6 sm:px-8 py-4 shrink-0" style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
            <div class="flex items-center gap-3 min-w-0">
                <div class="w-11 h-11 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0 ring-2 ring-white/30">
                    <i class="fas fa-user-tie text-white text-base"></i>
                </div>
                <div class="min-w-0">
                    <p class="font-bold text-lg text-white leading-snug truncate">Create New Director</p>
                    <p class="text-sm text-white/70 mt-0.5 truncate">Fill in the details below</p>
                </div>
            </div>
            <button @click="$wire.closeModal(); setTimeout(() => muClosing = true, 220)" wire:loading.attr="disabled" wire:target="closeModal"
                    class="mu-close-tooltip w-9 h-9 rounded-xl bg-white/20 hover:bg-white/30 flex items-center justify-center transition text-white shrink-0">
                <i wire:loading.remove wire:target="closeModal" class="fa-solid fa-xmark text-lg"></i>
                <i wire:loading wire:target="closeModal" class="fas fa-spinner animate-spin text-lg"></i>
            </button>
        </div>

        {{-- Fullscreen profile photo preview --}}
        <div x-show="dPhotoFull" x-cloak
             class="fixed inset-0 flex items-center justify-center bg-black/90"
             style="z-index:9999;"
             x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             @click="dPhotoFull = false">
            <button @click.stop="dPhotoFull = false"
                    class="absolute top-4 right-4 w-10 h-10 rounded-xl bg-white/10 hover:bg-white/20 flex items-center justify-center transition text-white">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
            @if($vPhoto)
            <img src="{{ $vPhoto->temporaryUrl() }}" alt="Profile photo preview"
                 class="max-w-[92vw] max-h-[88vh] object-contain rounded-lg" @click.stop>
            @endif
        </div>

        <div class="flex-1 min-h-0 overflow-y-auto p-5 sm:p-6 mu-vp-scroll max-w-4xl mx-auto w-full" style="scrollbar-width:thin;scrollbar-color:#cccccc #F5F5F5;">

            @if($dOk)
            @php $parts = explode('|', $dOk); @endphp
            <div class="p-4 rounded-xl border bg-emerald-50 border-emerald-200 mb-5 space-y-2">
                <div class="flex items-start gap-3">
                    <i class="fas fa-circle-check text-emerald-500 mt-0.5 shrink-0"></i>
                    <div class="space-y-1.5">
                        @foreach($parts as $part)
                        <p class="text-base text-emerald-800">{!! $part !!}</p>
                        @endforeach
                    </div>
                </div>
            </div>
            <button @click="$wire.closeModal(); setTimeout(() => muClosing = true, 220)" wire:loading.attr="disabled" wire:target="closeModal"
                    class="w-full py-3 rounded-xl text-base font-bold text-white transition hover:opacity-90 flex items-center justify-center gap-2" style="background:#7A3F91;">
                <i wire:loading wire:target="closeModal" class="fas fa-spinner animate-spin text-sm"></i>
                <span>Done</span>
            </button>
            @endif

            @if(count($dErrs))
            <div class="mb-5 p-4 rounded-xl bg-red-50 border border-red-200 space-y-1.5">
                @foreach($dErrs as $msgs)
                    @foreach($msgs as $msg)
                    <p class="text-base text-red-700 flex items-start gap-2">
                        <i class="fas fa-circle-exclamation shrink-0 mt-0.5 text-sm"></i><span>{{ $msg }}</span>
                    </p>
                    @endforeach
                @endforeach
            </div>
            @endif

            @if(!$dOk)
            <div class="space-y-4" wire:loading.class="opacity-60 pointer-events-none" wire:target="createDirector" style="transition: opacity .15s ease;">

                {{-- PERSONAL INFORMATION card --}}
                <div class="rounded-xl border overflow-hidden" style="border-color:#E5E5E5;">
                    <div class="px-5 py-3 border-b" style="background:#FAFAFA;border-color:#E5E5E5;">
                        <p class="text-sm font-bold uppercase tracking-widest" style="color:#000000;">Personal Information</p>
                    </div>
                    <div class="p-5 flex flex-col sm:flex-row gap-5">
                        <div class="flex flex-col items-center gap-2 shrink-0 mx-auto sm:mx-0"
                             x-data="{ dragging: false }"
                             @dragover.prevent="dragging=true" @dragleave.prevent="dragging=false"
                             @drop.prevent="dragging=false; $wire.upload('vPhoto', $event.dataTransfer.files[0])">
                            <div class="relative w-32 h-32">
                                <label for="dPhotoInput"
                                       :class="dragging ? 'border-black bg-black/5' : 'border-[#E5E5E5] bg-[#FAFAFA] hover:border-black'"
                                       class="w-32 h-32 rounded-xl border-2 border-dashed cursor-pointer transition-all flex flex-col items-center justify-center gap-1 text-center px-2 overflow-hidden">
                                    @if($vPhoto)
                                        <img src="{{ $vPhoto->temporaryUrl() }}" class="w-full h-full object-cover" alt="Preview">
                                    @else
                                        <i class="fas fa-arrow-up-from-bracket text-base" style="color:#8a8a8a;"></i>
                                        <span class="text-sm font-bold" style="color:#000000;">Profile Photo</span>
                                        <span class="text-xs font-medium" style="color:#8a8a8a;">JPG, PNG, WebP · 5 MB</span>
                                    @endif
                                    <input id="dPhotoInput" type="file" wire:model="vPhoto" accept="image/*" class="hidden">
                                </label>
                                @if($vPhoto)
                                <button type="button" @click.prevent="dPhotoFull = true"
                                        class="absolute bottom-1 right-1 w-7 h-7 rounded-lg bg-black/60 hover:bg-black/80 flex items-center justify-center transition text-white">
                                    <i class="fas fa-expand text-xs"></i>
                                </button>
                                @endif
                            </div>
                            <div wire:loading wire:target="vPhoto" class="flex items-center gap-1.5 text-xs font-semibold" style="color:#000000;">
                                <i class="fas fa-spinner animate-spin text-xs"></i> Uploading…
                            </div>
                            <span class="text-xs font-medium" style="color:#6b6b6b;">Optional — leave blank for default</span>
                        </div>

                        <div class="flex-1 grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <p class="text-sm font-bold mb-2" style="color:#000000;">First Name <span class="text-red-500">*</span></p>
                                <input wire:model.defer="dFn" type="text" placeholder="e.g. Juan" class="mu-filter-input w-full mu-smooth-input text-base" autocomplete="off">
                            </div>
                            <div>
                                <p class="text-sm font-bold mb-2" style="color:#000000;">Last Name <span class="text-red-500">*</span></p>
                                <input wire:model.defer="dLn" type="text" placeholder="e.g. dela Cruz" class="mu-filter-input w-full mu-smooth-input text-base" autocomplete="off">
                            </div>
                            <div>
                                <p class="text-sm font-bold mb-2" style="color:#000000;">Middle Name <span class="text-red-400 font-normal">*</span></p>
                                <input wire:model.defer="dMn" type="text" placeholder="e.g. Santos" class="mu-filter-input w-full mu-smooth-input text-base" autocomplete="off">
                            </div>
                            <div>
                                <p class="text-sm font-bold mb-2" style="color:#000000;">Suffix</p>
                                <select wire:model.defer="dSfx" class="mu-filter-input w-full mu-smooth-input text-base">
                                    <option value="">None</option>
                                    <option value="Jr.">Jr.</option>
                                    <option value="Sr.">Sr.</option>
                                    <option value="II">II</option>
                                    <option value="III">III</option>
                                    <option value="IV">IV</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ACCOUNT CREDENTIALS card --}}
                <div class="rounded-xl border overflow-hidden" style="border-color:#E5E5E5;">
                    <div class="px-5 py-3 border-b" style="background:#FAFAFA;border-color:#E5E5E5;">
                        <p class="text-sm font-bold uppercase tracking-widest" style="color:#000000;">Account Credentials</p>
                    </div>
                    <div class="p-5">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <p class="text-sm font-bold mb-2" style="color:#000000;">Teacher ID <span class="text-red-500">*</span></p>
                                <input wire:model.defer="dUsername" type="text" inputmode="numeric" maxlength="8"
                                       placeholder="e.g. 20240001" class="mu-filter-input w-full mu-smooth-input font-mono text-base" autocomplete="off">
                                <p class="text-xs font-medium mt-1.5" style="color:#8a8a8a;">Must be exactly 8 digits</p>
                            </div>
                            <div>
                                <p class="text-sm font-bold mb-2" style="color:#000000;">Email Address <span class="text-red-500">*</span></p>
                                <input wire:model.defer="dEmail" type="email" placeholder="director@example.com"
                                       class="mu-filter-input w-full mu-smooth-input text-base" autocomplete="off">
                                <p class="text-xs font-medium mt-1.5" style="color:#6b6b6b;">Login credentials will be sent here</p>
                            </div>
                        </div>
                        <div class="mt-4 p-4 rounded-xl flex items-start gap-2.5" style="background:#fffbeb;border:1px solid #fde68a;">
                            <i class="fas fa-circle-info text-amber-500 text-sm mt-0.5 shrink-0"></i>
                            <p class="text-sm font-semibold leading-snug" style="color:#92400e;">
                                A secure password will be <strong>auto-generated</strong> and sent to this email.
                                The director logs in using their <strong>Teacher ID</strong>.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="flex gap-3 pt-1">
                    <button type="button" @click="$wire.closeModal(); setTimeout(() => muClosing = true, 220)" wire:loading.attr="disabled" wire:target="closeModal,createDirector"
                            class="flex-1 px-4 py-3 rounded-xl text-base font-bold border transition hover:bg-black/5 flex items-center justify-center gap-2"
                            style="color:#000000;border-color:#E5E5E5;">
                        <i wire:loading wire:target="closeModal" class="fas fa-spinner animate-spin text-sm"></i>
                        <span>Cancel</span>
                    </button>
                    <button wire:click="createDirector" wire:loading.attr="disabled" wire:target="createDirector"
                            class="flex-1 px-4 py-3 rounded-xl text-base font-bold text-white transition hover:opacity-90 flex items-center justify-center gap-2 mu-smooth-btn"
                            style="background:#7A3F91;">
                        <span wire:loading.remove wire:target="createDirector" class="flex items-center gap-2">
                            <i class="fas fa-user-tie text-sm"></i> Create Director
                        </span>
                        <span wire:loading wire:target="createDirector" class="flex items-center gap-2">
                            <i class="fas fa-spinner animate-spin text-sm"></i> Creating…
                        </span>
                    </button>
                </div>
            </div>
            @endif

        </div>
    </div>
</div>
@endif


{{-- ═══════════════════════════════════════════════════════════
     TOGGLE CONFIRM MODAL
     ═══════════════════════════════════════════════════════════ --}}
@if($activeModal === 'toggleConfirm' && $tId)
<div class="fixed inset-0 flex items-center justify-center p-3 sm:p-4 bg-black/50 backdrop-blur-sm"
     style="z-index:9996;"
     x-data="{ muClosing: false }"
     x-show="!muClosing"
     x-init="muClosing = false"
     @keydown.escape.window="$wire.closeModal(); setTimeout(() => muClosing = true, 220)">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm overflow-hidden border border-[#E8E0F0]"
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100">
        <div class="p-6">
            <div class="flex items-center gap-4 mb-4">
                <div class="w-12 h-12 rounded-2xl flex items-center justify-center flex-shrink-0
                            {{ $tAction==='deactivate' ? 'bg-red-100' : 'bg-emerald-100' }}">
                    <i class="{{ $tAction==='deactivate' ? 'fas fa-ban text-red-600 text-lg' : 'fas fa-circle-check text-emerald-600 text-lg' }}"></i>
                </div>
                <div>
                    <p class="font-semibold text-base" style="color:#000000;">
                        {{ $tAction==='deactivate' ? 'Deactivate' : 'Activate' }} User?
                    </p>
                    <p class="text-sm mt-0.5 font-semibold" style="color:#000000;">{{ $tName }}</p>
                </div>
            </div>
            <p class="text-sm mb-5 leading-relaxed font-medium" style="color:#000000;">
                @if($tAction==='deactivate')
                    This user will be marked as <strong>Inactive</strong>. You can reactivate them anytime.
                @else
                    This user will be marked as <strong>Active</strong> and regain system access.
                @endif
            </p>
            <div class="flex gap-2">
                <button @click="$wire.closeModal(); setTimeout(() => muClosing = true, 220)" wire:loading.attr="disabled" wire:target="closeModal,executeToggle"
                        class="flex-1 px-4 py-2.5 rounded-xl text-sm font-bold border transition hover:bg-gray-50 flex items-center justify-center gap-2"
                        style="color:#000000;border-color:#E8E0F0;">
                    <i wire:loading wire:target="closeModal" class="fas fa-spinner animate-spin text-xs"></i>
                    <span>Cancel</span>
                </button>
                <button wire:click="executeToggle" wire:loading.attr="disabled" wire:target="executeToggle"
                        class="flex-1 px-4 py-2.5 rounded-xl text-sm font-bold text-white transition flex items-center justify-center gap-2
                               {{ $tAction==='deactivate' ? 'bg-red-600 hover:bg-red-700' : 'bg-emerald-600 hover:bg-emerald-700' }}">
                    <span wire:loading wire:target="executeToggle"><i class="fas fa-spinner animate-spin text-xs"></i></span>
                    <span wire:loading.remove wire:target="executeToggle">
                        {{ $tAction==='deactivate' ? 'Yes, Deactivate' : 'Yes, Activate' }}
                    </span>
                </button>
            </div>
        </div>
    </div>
</div>
@endif


<script>
(function () {
    var root = document.currentScript.closest('div') || document.querySelector('[wire\\:id]');

    function findScrollableAncestors(el) {
        var found = [];
        var node = el ? el.parentElement : null;
        while (node && node !== document.body) {
            var cs = window.getComputedStyle(node);
            if ((cs.overflowY === 'auto' || cs.overflowY === 'scroll') && node.scrollHeight > node.clientHeight + 1) {
                found.push(node);
            }
            node = node.parentElement;
        }
        return found;
    }

    var lockedNodes = [];
    var prevStyles = [];

    function lockScroll() {
        var scrollEl = document.querySelector('[wire\\:id]') || document.body;
        var ancestors = findScrollableAncestors(scrollEl);

        [document.documentElement, document.body].concat(ancestors).forEach(function (node) {
            if (lockedNodes.indexOf(node) !== -1) return;
            prevStyles.push([node, node.style.overflow, node.style.overflowY]);
            node.style.overflow = 'hidden';
            node.style.overflowY = 'hidden';
            lockedNodes.push(node);
        });
    }

    function restore() {
        prevStyles.forEach(function (entry) {
            entry[0].style.overflow = entry[1];
            entry[0].style.overflowY = entry[2];
        });
        lockedNodes = [];
        prevStyles = [];
        document.removeEventListener('livewire:navigating', restore);
        window.removeEventListener('beforeunload', restore);
    }

    lockScroll();
    // Re-check shortly after mount in case the parent layout renders async
    setTimeout(lockScroll, 150);
    setTimeout(lockScroll, 500);

    document.addEventListener('livewire:navigating', restore);
    window.addEventListener('beforeunload', restore);
})();
</script>

<script>
(function () {
    var tip = document.getElementById('mu-hover-tip');

    function isHoverCapable() {
        return window.matchMedia('(hover: hover) and (pointer: fine)').matches
            && window.innerWidth > 768;
    }

    // ONE delegated 'mousemove' listener on the document, same fix as
    // the Alumni Records page's #ar-hover-tip: binding listeners
    // directly on each row means every Livewire morph (tab switch,
    // filter, search, pagination) either leaves a stale listener on a
    // removed row or needs a fresh listener rebound on a new one —
    // fragile either way. A delegated listener re-checks "what's
    // under the mouse right now" on every movement, so it works
    // immediately on freshly-morphed rows with no rebinding at all —
    // this is what made "View Details" not show up when switching to
    // the Director tab.
    document.addEventListener('mousemove', function (e) {
        if (!tip || !isHoverCapable()) return;
        var row = e.target.closest ? e.target.closest('[data-mu-row]') : null;
        if (row) {
            tip.style.left = e.clientX + 'px';
            tip.style.top  = e.clientY + 'px';
            tip.classList.add('visible');
        } else {
            tip.classList.remove('visible');
        }
    }, { passive: true });

    function hideHoverTip() {
        if (tip) tip.classList.remove('visible');
    }
    hideHoverTip();
    document.addEventListener('livewire:navigating', hideHoverTip);
    document.addEventListener('livewire:navigated', hideHoverTip);
    document.addEventListener('livewire:load', hideHoverTip);

    // Hide the instant a row is clicked (opening the profile modal).
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-mu-row]')) hideHoverTip();
    });
})();
</script>

</div>