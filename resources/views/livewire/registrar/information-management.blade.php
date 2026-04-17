{{--
    resources/views/livewire/admin/information-management.blade.php

    ══════════════════════════════════════════════════════════════════════
    REQUIRED MIGRATION — run before using this component:
    ══════════════════════════════════════════════════════════════════════

    Schema::create('information_fields', function (Blueprint $table) {
        $table->id();
        $table->string('field_key', 100)->unique();
        $table->string('field_label');
        $table->string('field_type', 50)->default('text');
        $table->string('section', 50)->default('other');
        $table->json('field_options')->nullable();
        $table->boolean('is_required')->default(false);
        $table->boolean('is_active')->default(true);
        $table->boolean('is_system')->default(false);
        $table->unsignedSmallInteger('sort_order')->default(0);
        $table->timestamps();
    });

    Schema::create('alumni_field_values', function (Blueprint $table) {
        $table->id();
        $table->foreignId('alumni_id')->constrained('alumni')->cascadeOnDelete();
        $table->string('field_key', 100);
        $table->text('field_value')->nullable();
        $table->timestamps();
        $table->unique(['alumni_id','field_key']);
    });

    ══════════════════════════════════════════════════════════════════════
    HOW CUSTOM FIELDS SHOW IN ALUMNI-INFORMATION:
    ══════════════════════════════════════════════════════════════════════
    In your alumni-information component mount(), after loading system fields add:

        $customFields = DB::table('information_fields')
            ->where('is_system', false)->where('is_active', true)
            ->orderBy('section')->orderBy('sort_order')->get();

        $this->customFieldValues = DB::table('alumni_field_values')
            ->where('alumni_id', $alumni->id)
            ->pluck('field_value','field_key')->toArray();

    Then in the blade, loop $customFields grouped by section to render them.
    ══════════════════════════════════════════════════════════════════════
--}}

<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

new class extends Component {

    // ── List / Filter ─────────────────────────────────────────────────────────
    public array  $fields         = [];
    public string $search         = '';
    public string $filterSection  = '';

    // ── Panel state ───────────────────────────────────────────────────────────
    public bool   $showForm       = false;
    public bool   $isEditing      = false;
    public ?int   $editingId      = null;

    // ── Form fields ───────────────────────────────────────────────────────────
    public string $field_label    = '';
    public string $field_key      = '';
    public string $field_type     = 'text';
    public string $section        = 'personal';
    public bool   $is_required    = false;
    public array  $field_options  = [];
    public string $new_opt_label  = '';
    public string $new_opt_value  = '';

    // ── Delete confirm ────────────────────────────────────────────────────────
    public bool   $showDelete     = false;
    public ?int   $deleteId       = null;
    public string $deleteLabel    = '';

    // ── Messages ──────────────────────────────────────────────────────────────
    public string $successMessage = '';
    public string $errorMessage   = '';

    // ── Type / Section maps ───────────────────────────────────────────────────
    public array $fieldTypes = [
        'text'     => 'Short Text',
        'tel'      => 'Phone / Tel',
        'email'    => 'Email',
        'number'   => 'Number',
        'date'     => 'Date',
        'select'   => 'Dropdown (Select)',
        'radio'    => 'Radio Buttons',
        'textarea' => 'Long Text',
    ];

    public array $sections = [
        'student_record' => 'Student Record',
        'personal'       => 'Personal Details',
        'family'         => 'Family Background',
        'address'        => 'Home Address',
        'employment'     => 'Employment',
        'other'          => 'Other / Custom',
    ];

    public array $sectionColors = [
        'student_record' => ['bg'=>'bg-violet-100','text'=>'text-violet-800','dot'=>'bg-violet-500'],
        'personal'       => ['bg'=>'bg-blue-100',   'text'=>'text-blue-800',  'dot'=>'bg-blue-500'],
        'family'         => ['bg'=>'bg-rose-100',   'text'=>'text-rose-800',  'dot'=>'bg-rose-500'],
        'address'        => ['bg'=>'bg-emerald-100','text'=>'text-emerald-800','dot'=>'bg-emerald-500'],
        'employment'     => ['bg'=>'bg-amber-100',  'text'=>'text-amber-800', 'dot'=>'bg-amber-500'],
        'other'          => ['bg'=>'bg-gray-100',   'text'=>'text-gray-700',  'dot'=>'bg-gray-400'],
    ];

    public array $typeIcons = [
        'text'     => 'fa-font',
        'tel'      => 'fa-phone',
        'email'    => 'fa-envelope',
        'number'   => 'fa-hashtag',
        'date'     => 'fa-calendar',
        'select'   => 'fa-caret-down',
        'radio'    => 'fa-circle-dot',
        'textarea' => 'fa-align-left',
    ];

    // ─────────────────────────────────────────────────────────────────────────
    public function mount(): void
    {
        $this->seedSystemFieldsIfEmpty();
        $this->loadFields();
    }

    // ── Seed system fields on first run ───────────────────────────────────────
    private function seedSystemFieldsIfEmpty(): void
    {
        if (DB::table('information_fields')->count() > 0) return;

        $now  = now();
        $rows = [
            // ── Student Record ────────────────────────────────────────────────
            ['field_key'=>'first_name',       'field_label'=>'First Name',            'field_type'=>'text',   'section'=>'student_record','is_required'=>1,'sort_order'=>1, 'field_options'=>null],
            ['field_key'=>'middle_initial',   'field_label'=>'Middle Name',           'field_type'=>'text',   'section'=>'student_record','is_required'=>0,'sort_order'=>2, 'field_options'=>null],
            ['field_key'=>'last_name',        'field_label'=>'Last Name',             'field_type'=>'text',   'section'=>'student_record','is_required'=>1,'sort_order'=>3, 'field_options'=>null],
            ['field_key'=>'suffix',           'field_label'=>'Suffix',                'field_type'=>'text',   'section'=>'student_record','is_required'=>0,'sort_order'=>4, 'field_options'=>null],
            ['field_key'=>'student_id',       'field_label'=>'Student ID',            'field_type'=>'text',   'section'=>'student_record','is_required'=>1,'sort_order'=>5, 'field_options'=>null],
            ['field_key'=>'course_code',      'field_label'=>'Course Code',           'field_type'=>'text',   'section'=>'student_record','is_required'=>1,'sort_order'=>6, 'field_options'=>null],
            ['field_key'=>'course_name',      'field_label'=>'Course Name',           'field_type'=>'text',   'section'=>'student_record','is_required'=>1,'sort_order'=>7, 'field_options'=>null],
            ['field_key'=>'batch',            'field_label'=>'Batch Year',            'field_type'=>'number', 'section'=>'student_record','is_required'=>1,'sort_order'=>8, 'field_options'=>null],
            ['field_key'=>'email',            'field_label'=>'Email Address',         'field_type'=>'email',  'section'=>'student_record','is_required'=>1,'sort_order'=>9, 'field_options'=>null],
            // ── Personal ──────────────────────────────────────────────────────
            ['field_key'=>'gender',           'field_label'=>'Gender',                'field_type'=>'radio',  'section'=>'personal','is_required'=>1,'sort_order'=>10,'field_options'=>json_encode([['value'=>'Male','label'=>'Male'],['value'=>'Female','label'=>'Female']])],
            ['field_key'=>'date_of_birth',    'field_label'=>'Date of Birth',         'field_type'=>'date',   'section'=>'personal','is_required'=>1,'sort_order'=>11,'field_options'=>null],
            ['field_key'=>'place_of_birth',   'field_label'=>'Place of Birth',        'field_type'=>'text',   'section'=>'personal','is_required'=>1,'sort_order'=>12,'field_options'=>null],
            ['field_key'=>'citizenship',      'field_label'=>'Citizenship',           'field_type'=>'text',   'section'=>'personal','is_required'=>1,'sort_order'=>13,'field_options'=>null],
            ['field_key'=>'civil_status',     'field_label'=>'Civil Status',          'field_type'=>'select', 'section'=>'personal','is_required'=>1,'sort_order'=>14,'field_options'=>json_encode([['value'=>'Single','label'=>'Single'],['value'=>'Married','label'=>'Married'],['value'=>'Widowed','label'=>'Widowed'],['value'=>'Separated','label'=>'Separated'],['value'=>'Annulled','label'=>'Annulled']])],
            ['field_key'=>'blood_type',       'field_label'=>'Blood Type',            'field_type'=>'select', 'section'=>'personal','is_required'=>0,'sort_order'=>15,'field_options'=>json_encode([['value'=>'A+','label'=>'A+'],['value'=>'A-','label'=>'A-'],['value'=>'B+','label'=>'B+'],['value'=>'B-','label'=>'B-'],['value'=>'AB+','label'=>'AB+'],['value'=>'AB-','label'=>'AB-'],['value'=>'O+','label'=>'O+'],['value'=>'O-','label'=>'O-'],['value'=>'Unknown','label'=>'Unknown']])],
            ['field_key'=>'contact_number',   'field_label'=>'Contact Number',        'field_type'=>'tel',    'section'=>'personal','is_required'=>1,'sort_order'=>16,'field_options'=>null],
            // ── Family ────────────────────────────────────────────────────────
            ['field_key'=>'father_name',      'field_label'=>"Father's Full Name",    'field_type'=>'text',   'section'=>'family','is_required'=>1,'sort_order'=>17,'field_options'=>null],
            ['field_key'=>'mother_name',      'field_label'=>"Mother's Full Name",    'field_type'=>'text',   'section'=>'family','is_required'=>1,'sort_order'=>18,'field_options'=>null],
            ['field_key'=>'spouse_name',      'field_label'=>'Spouse Name',           'field_type'=>'text',   'section'=>'family','is_required'=>0,'sort_order'=>19,'field_options'=>null],
            // ── Address ───────────────────────────────────────────────────────
            ['field_key'=>'address_no',           'field_label'=>'House / Unit No.',  'field_type'=>'text','section'=>'address','is_required'=>0,'sort_order'=>20,'field_options'=>null],
            ['field_key'=>'address_street',       'field_label'=>'Street',            'field_type'=>'text','section'=>'address','is_required'=>1,'sort_order'=>21,'field_options'=>null],
            ['field_key'=>'address_barangay',     'field_label'=>'Barangay',          'field_type'=>'text','section'=>'address','is_required'=>1,'sort_order'=>22,'field_options'=>null],
            ['field_key'=>'address_municipality', 'field_label'=>'Municipality/City', 'field_type'=>'text','section'=>'address','is_required'=>1,'sort_order'=>23,'field_options'=>null],
            ['field_key'=>'address_province',     'field_label'=>'Province',          'field_type'=>'text','section'=>'address','is_required'=>1,'sort_order'=>24,'field_options'=>null],
            ['field_key'=>'address_zip_code',     'field_label'=>'Zip Code',          'field_type'=>'text','section'=>'address','is_required'=>1,'sort_order'=>25,'field_options'=>null],
            // ── Employment ────────────────────────────────────────────────────
            ['field_key'=>'employment_status',   'field_label'=>'Employment Status',        'field_type'=>'radio', 'section'=>'employment','is_required'=>0,'sort_order'=>26,'field_options'=>json_encode([['value'=>'employed','label'=>'Employed'],['value'=>'self_employed','label'=>'Self-Employed'],['value'=>'unemployed','label'=>'Unemployed']])],
            ['field_key'=>'company_name',        'field_label'=>'Company / Business Name',  'field_type'=>'text',  'section'=>'employment','is_required'=>0,'sort_order'=>27,'field_options'=>null],
            ['field_key'=>'job_title',           'field_label'=>'Job Title',                'field_type'=>'text',  'section'=>'employment','is_required'=>0,'sort_order'=>28,'field_options'=>null],
            ['field_key'=>'employment_type',     'field_label'=>'Employment Type',          'field_type'=>'select','section'=>'employment','is_required'=>0,'sort_order'=>29,'field_options'=>json_encode([['value'=>'full_time','label'=>'Full-Time'],['value'=>'part_time','label'=>'Part-Time'],['value'=>'contractual','label'=>'Contractual'],['value'=>'project_based','label'=>'Project-Based'],['value'=>'internship','label'=>'Internship']])],
            ['field_key'=>'work_location',       'field_label'=>'Work Location',            'field_type'=>'radio', 'section'=>'employment','is_required'=>0,'sort_order'=>30,'field_options'=>json_encode([['value'=>'local','label'=>'Local'],['value'=>'abroad','label'=>'Abroad (OFW)']])],
            ['field_key'=>'date_hired',          'field_label'=>'Date Hired',               'field_type'=>'date',  'section'=>'employment','is_required'=>0,'sort_order'=>31,'field_options'=>null],
            ['field_key'=>'career_path',         'field_label'=>'Career Path',              'field_type'=>'text',  'section'=>'employment','is_required'=>0,'sort_order'=>32,'field_options'=>null],
            ['field_key'=>'education_status',    'field_label'=>'Education Status',         'field_type'=>'select','section'=>'employment','is_required'=>0,'sort_order'=>33,'field_options'=>json_encode([['value'=>'none','label'=>'None'],['value'=>'pursuing_masteral','label'=>'Pursuing Masteral'],['value'=>'pursuing_doctorate','label'=>'Pursuing Doctorate']])],
            ['field_key'=>'course_relevance',    'field_label'=>'Job Related to Course?',   'field_type'=>'radio', 'section'=>'employment','is_required'=>0,'sort_order'=>34,'field_options'=>json_encode([['value'=>'yes','label'=>'Yes'],['value'=>'no','label'=>'No'],['value'=>'partially','label'=>'Partially']])],
            ['field_key'=>'unemployment_status', 'field_label'=>'Unemployment Status',      'field_type'=>'radio', 'section'=>'employment','is_required'=>0,'sort_order'=>35,'field_options'=>json_encode([['value'=>'seeking_employment','label'=>'Seeking Employment'],['value'=>'not_looking','label'=>'Not Looking']])],
        ];

        foreach ($rows as &$row) {
            $row['is_system']    = true;
            $row['is_active']    = true;
            $row['created_at']   = $now;
            $row['updated_at']   = $now;
        }
        unset($row);

        DB::table('information_fields')->insert($rows);
    }

    // ── Load fields ───────────────────────────────────────────────────────────
    public function loadFields(): void
    {
        $sectionOrder = implode("','", array_keys($this->sections));

        $this->fields = DB::table('information_fields')
            ->when($this->search, fn($q) =>
                $q->where(fn($q2) =>
                    $q2->where('field_label', 'like', "%{$this->search}%")
                       ->orWhere('field_key', 'like', "%{$this->search}%")))
            ->when($this->filterSection, fn($q) => $q->where('section', $this->filterSection))
            ->orderByRaw("FIELD(section,'{$sectionOrder}')")
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();
    }

    public function updatedSearch(): void        { $this->loadFields(); }
    public function updatedFilterSection(): void { $this->loadFields(); }

    // Auto-generate field_key from label (create mode only)
    public function updatedFieldLabel(): void
    {
        if (!$this->isEditing) {
            $this->field_key = Str::snake(Str::lower(trim($this->field_label)));
        }
    }

    // ── Open create panel ─────────────────────────────────────────────────────
    public function openCreate(): void
    {
        $this->resetForm();
        $this->showForm  = true;
        $this->isEditing = false;
        $this->successMessage = $this->errorMessage = '';
    }

    // ── Open edit panel ───────────────────────────────────────────────────────
    public function openEdit(int $id): void
    {
        $row = DB::table('information_fields')->find($id);
        if (!$row) return;

        $this->editingId      = $id;
        $this->field_label    = $row->field_label;
        $this->field_key      = $row->field_key;
        $this->field_type     = $row->field_type;
        $this->section        = $row->section;
        $this->is_required    = (bool)$row->is_required;
        $this->field_options  = $row->field_options
            ? (json_decode($row->field_options, true) ?? [])
            : [];
        $this->showForm       = true;
        $this->isEditing      = true;
        $this->successMessage = $this->errorMessage = '';
        $this->resetValidation();
    }

    // ── Save (create or update) ───────────────────────────────────────────────
    public function saveField(): void
    {
        $this->errorMessage = $this->successMessage = '';

        $this->validate([
            'field_label' => 'required|string|max:255',
            'field_key'   => ['required','string','max:100','regex:/^[a-z0-9_]+$/'],
            'field_type'  => 'required|string|in:text,tel,email,number,date,select,radio,textarea',
            'section'     => 'required|string|in:student_record,personal,family,address,employment,other',
        ], [
            'field_label.required' => 'Field label is required.',
            'field_key.required'   => 'Field key is required.',
            'field_key.regex'      => 'Only lowercase letters, numbers, and underscores allowed.',
            'field_type.required'  => 'Please select a field type.',
            'section.required'     => 'Please select a section.',
        ]);

        try {
            $hasOptions = in_array($this->field_type, ['select','radio']) && count($this->field_options) > 0;
            $optJson    = $hasOptions ? json_encode(array_values($this->field_options)) : null;

            if ($this->isEditing && $this->editingId) {
                // Check duplicate key (excluding self)
                if (DB::table('information_fields')
                    ->where('field_key', $this->field_key)
                    ->where('id', '!=', $this->editingId)
                    ->exists()) {
                    $this->errorMessage = "Field key \"{$this->field_key}\" already exists.";
                    return;
                }

                DB::table('information_fields')->where('id', $this->editingId)->update([
                    'field_label'   => $this->field_label,
                    'field_key'     => $this->field_key,
                    'field_type'    => $this->field_type,
                    'section'       => $this->section,
                    'is_required'   => $this->is_required,
                    'field_options' => $optJson,
                    'updated_at'    => now(),
                ]);

                $this->successMessage = ""{$this->field_label}" updated successfully.";
                Log::info("info_field updated | id:{$this->editingId} | key:{$this->field_key}");

            } else {
                // Check duplicate key
                if (DB::table('information_fields')->where('field_key', $this->field_key)->exists()) {
                    $this->errorMessage = "Field key \"{$this->field_key}\" already exists. Choose a different key.";
                    return;
                }

                $maxOrder = (int)(DB::table('information_fields')
                    ->where('section', $this->section)
                    ->max('sort_order') ?? 0);

                DB::table('information_fields')->insert([
                    'field_label'   => $this->field_label,
                    'field_key'     => $this->field_key,
                    'field_type'    => $this->field_type,
                    'section'       => $this->section,
                    'is_required'   => $this->is_required,
                    'is_system'     => false,
                    'is_active'     => true,
                    'field_options' => $optJson,
                    'sort_order'    => $maxOrder + 1,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);

                $this->successMessage = ""{$this->field_label}" added successfully!";
                Log::info("info_field created | key:{$this->field_key} | section:{$this->section}");
            }

            $this->showForm = false;
            $this->resetForm();
            $this->loadFields();

        } catch (\Throwable $e) {
            Log::error('saveField error: ' . $e->getMessage());
            $this->errorMessage = 'Failed to save. Please try again.';
        }
    }

    // ── Toggle active / inactive ──────────────────────────────────────────────
    public function toggleActive(int $id): void
    {
        $row = DB::table('information_fields')->find($id);
        if (!$row || $row->is_system) return;

        DB::table('information_fields')->where('id', $id)->update([
            'is_active'  => !$row->is_active,
            'updated_at' => now(),
        ]);
        $this->loadFields();
        $this->successMessage = $row->is_active
            ? ""{$row->field_label}" has been deactivated."
            : ""{$row->field_label}" is now active.";
        $this->errorMessage = '';
    }

    // ── Confirm delete ────────────────────────────────────────────────────────
    public function confirmDelete(int $id): void
    {
        $row = DB::table('information_fields')->find($id);
        if (!$row || $row->is_system) return;
        $this->deleteId    = $id;
        $this->deleteLabel = $row->field_label;
        $this->showDelete  = true;
    }

    public function cancelDelete(): void
    {
        $this->deleteId    = null;
        $this->deleteLabel = '';
        $this->showDelete  = false;
    }

    public function deleteField(): void
    {
        if (!$this->deleteId) return;
        $row = DB::table('information_fields')->find($this->deleteId);

        if (!$row) { $this->cancelDelete(); return; }
        if ($row->is_system) {
            $this->errorMessage = 'System fields cannot be deleted.';
            $this->cancelDelete();
            return;
        }

        try {
            DB::table('alumni_field_values')->where('field_key', $row->field_key)->delete();
            DB::table('information_fields')->where('id', $this->deleteId)->delete();
            $this->successMessage = ""{$row->field_label}" has been deleted.";
            Log::info("info_field deleted | id:{$this->deleteId} | key:{$row->field_key}");
        } catch (\Throwable $e) {
            Log::error('deleteField error: ' . $e->getMessage());
            $this->errorMessage = 'Failed to delete. Please try again.';
        }

        $this->cancelDelete();
        $this->loadFields();
    }

    // ── Options management ────────────────────────────────────────────────────
    public function addOption(): void
    {
        $label = trim($this->new_opt_label);
        if (!$label) return;
        $value = trim($this->new_opt_value) ?: Str::snake(Str::lower($label));
        $this->field_options[] = ['value' => $value, 'label' => $label];
        $this->new_opt_label = $this->new_opt_value = '';
    }

    public function removeOption(int $index): void
    {
        array_splice($this->field_options, $index, 1);
        $this->field_options = array_values($this->field_options);
    }

    // ── Cancel / reset form ───────────────────────────────────────────────────
    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->editingId     = null;
        $this->isEditing     = false;
        $this->field_label   = '';
        $this->field_key     = '';
        $this->field_type    = 'text';
        $this->section       = 'personal';
        $this->is_required   = false;
        $this->field_options = [];
        $this->new_opt_label = '';
        $this->new_opt_value = '';
        $this->resetValidation();
    }

    // ── Stats helpers ─────────────────────────────────────────────────────────
    public function getTotalCount(): int  { return DB::table('information_fields')->count(); }
    public function getCustomCount(): int { return DB::table('information_fields')->where('is_system', false)->count(); }
    public function getActiveCount(): int { return DB::table('information_fields')->where('is_active', true)->count(); }

}; ?>

<div class="space-y-5 relative">

<style>
/* ── Field inputs ─────────────────────────────────────────────────── */
.fm-input {
    width:100%; padding:8px 12px; border-radius:.75rem; font-size:.875rem;
    border:1.5px solid #d1d5db; background:#fff; color:#111827;
    transition:border-color .15s,box-shadow .15s;
}
.fm-input:focus { outline:none; border-color:#7a3f91; box-shadow:0 0 0 3px rgba(122,63,145,.12); }
.fm-input.err   { border-color:#ef4444; }

/* ── Toggle switch ────────────────────────────────────────────────── */
.toggle-track {
    width:42px; height:24px; border-radius:9999px;
    position:relative; cursor:pointer;
    transition:background .2s;
}
.toggle-track.on  { background:#7a3f91; }
.toggle-track.off { background:#d1d5db; }
.toggle-thumb {
    position:absolute; top:3px; width:18px; height:18px;
    border-radius:9999px; background:#fff;
    box-shadow:0 1px 3px rgba(0,0,0,.2);
    transition:left .2s;
}
.toggle-track.on  .toggle-thumb { left:21px; }
.toggle-track.off .toggle-thumb { left:3px;  }

/* ── Slide-over panel ─────────────────────────────────────────────── */
.slide-panel {
    position:fixed; top:0; right:0; height:100vh; width:420px; max-width:95vw;
    background:#fff; box-shadow:-4px 0 24px rgba(0,0,0,.12);
    z-index:50; overflow-y:auto;
    transform:translateX(100%);
    transition:transform .3s cubic-bezier(.4,0,.2,1);
}
.slide-panel.open { transform:translateX(0); }

/* ── Overlay ──────────────────────────────────────────────────────── */
.panel-overlay {
    position:fixed; inset:0; background:rgba(0,0,0,.3);
    z-index:49; opacity:0; pointer-events:none;
    transition:opacity .3s;
}
.panel-overlay.open { opacity:1; pointer-events:all; }

/* ── Row hover ────────────────────────────────────────────────────── */
.field-row { transition:background .1s; }
.field-row:hover { background:#faf7ff; }

/* ── Option chip ──────────────────────────────────────────────────── */
.opt-chip {
    display:inline-flex; align-items:center; gap:5px;
    padding:3px 10px; border-radius:9999px;
    background:#ede9fe; color:#5b21b6; font-size:.75rem; font-weight:600;
}
</style>

    {{-- ══ PAGE HEADER ══════════════════════════════════════════════════════ --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h1 class="text-xl font-extrabold text-gray-900 tracking-tight flex items-center gap-2">
                <i class="fa-solid fa-table-columns text-violet-600"></i>
                Information Management
            </h1>
            <p class="text-xs text-gray-500 mt-0.5">
                Manage the fields collected in the Alumni Information profile.
                System fields are locked; custom fields can be fully edited.
            </p>
        </div>
        <button wire:click="openCreate"
                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-bold
                       text-white shadow-sm hover:opacity-90 active:scale-95 transition self-start sm:self-auto"
                style="background:#7a3f91;">
            <i class="fa-solid fa-plus"></i> Add New Field
        </button>
    </div>

    {{-- ══ STATS ROW ══════════════════════════════════════════════════════════ --}}
    <div class="grid grid-cols-3 gap-3">
        @php
            $stats = [
                ['icon'=>'fa-list','label'=>'Total Fields',  'value'=>$this->getTotalCount(),  'bg'=>'bg-violet-50', 'ic'=>'text-violet-600','border'=>'border-violet-100'],
                ['icon'=>'fa-wand-magic-sparkles','label'=>'Custom Fields','value'=>$this->getCustomCount(),'bg'=>'bg-blue-50','ic'=>'text-blue-600','border'=>'border-blue-100'],
                ['icon'=>'fa-circle-check','label'=>'Active Fields','value'=>$this->getActiveCount(),'bg'=>'bg-emerald-50','ic'=>'text-emerald-600','border'=>'border-emerald-100'],
            ];
        @endphp
        @foreach($stats as $s)
            <div class="rounded-2xl border {{ $s['border'] }} {{ $s['bg'] }} p-4 flex items-center gap-3">
                <div class="w-9 h-9 rounded-xl bg-white shadow-sm flex items-center justify-center flex-shrink-0">
                    <i class="fa-solid {{ $s['icon'] }} {{ $s['ic'] }} text-sm"></i>
                </div>
                <div>
                    <p class="text-2xl font-extrabold text-gray-900 leading-none">{{ $s['value'] }}</p>
                    <p class="text-xs text-gray-500 mt-0.5">{{ $s['label'] }}</p>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ══ ALERTS ══════════════════════════════════════════════════════════════ --}}
    @if($errorMessage)
        <div class="p-3 bg-red-50 border border-red-200 rounded-xl text-red-800 flex items-start gap-2">
            <i class="fa-solid fa-circle-exclamation mt-0.5 text-red-500 text-sm flex-shrink-0"></i>
            <p class="text-sm font-medium">{{ $errorMessage }}</p>
        </div>
    @endif
    @if($successMessage)
        <div class="p-3 bg-emerald-50 border border-emerald-200 rounded-xl text-emerald-800 flex items-start gap-2">
            <i class="fa-solid fa-circle-check mt-0.5 text-emerald-500 text-sm flex-shrink-0"></i>
            <p class="text-sm font-medium">{{ $successMessage }}</p>
        </div>
    @endif

    {{-- ══ SEARCH & FILTER BAR ═══════════════════════════════════════════════ --}}
    <div class="flex flex-col sm:flex-row gap-3">
        <div class="relative flex-1">
            <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
            <input wire:model.live.debounce.300ms="search"
                   type="text" placeholder="Search by label or key…"
                   class="fm-input pl-8">
        </div>
        <select wire:model.live="filterSection" class="fm-input sm:w-52">
            <option value="">All Sections</option>
            @foreach($sections as $key => $label)
                <option value="{{ $key }}">{{ $label }}</option>
            @endforeach
        </select>
    </div>

    {{-- ══ FIELDS TABLE ═══════════════════════════════════════════════════════ --}}
    @php
        $grouped = collect($fields)->groupBy('section');
    @endphp

    @forelse($grouped as $sec => $secFields)
        @php
            $sLabel  = $sections[$sec]  ?? ucfirst($sec);
            $sColor  = $sectionColors[$sec] ?? $sectionColors['other'];
        @endphp

        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">

            {{-- Section header --}}
            <div class="px-5 py-3 border-b border-gray-100 flex items-center gap-3 bg-gray-50">
                <span class="w-2.5 h-2.5 rounded-full {{ $sColor['dot'] }} flex-shrink-0"></span>
                <span class="font-bold text-gray-800 text-sm">{{ $sLabel }}</span>
                <span class="ml-auto text-xs font-semibold text-gray-400">{{ count($secFields) }} field{{ count($secFields)!==1?'s':'' }}</span>
            </div>

            {{-- Field rows --}}
            <div class="divide-y divide-gray-50">
                @foreach($secFields as $f)
                    @php
                        $isSystem = (bool)($f['is_system'] ?? false);
                        $isActive = (bool)($f['is_active'] ?? true);
                        $fOptions = $f['field_options'] ? (json_decode($f['field_options'], true) ?? []) : [];
                    @endphp
                    <div class="field-row px-5 py-3 flex flex-wrap items-center gap-y-2">

                        {{-- Left: icon + label + key --}}
                        <div class="flex items-start gap-3 min-w-0 flex-1">
                            <div class="w-8 h-8 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0 mt-0.5">
                                <i class="fa-solid {{ $typeIcons[$f['field_type']] ?? 'fa-font' }} text-gray-500 text-xs"></i>
                            </div>
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <p class="text-sm font-bold text-gray-900 {{ !$isActive ? 'line-through opacity-50' : '' }}">
                                        {{ $f['field_label'] }}
                                    </p>
                                    @if($f['is_required'])
                                        <span class="text-red-500 text-xs font-bold">*</span>
                                    @endif
                                    @if($isSystem)
                                        <span class="inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-0.5 rounded-full bg-gray-100 text-gray-500">
                                            <i class="fa-solid fa-lock text-[9px]"></i> System
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-0.5 rounded-full bg-violet-100 text-violet-700">
                                            <i class="fa-solid fa-wand-magic-sparkles text-[9px]"></i> Custom
                                        </span>
                                    @endif
                                    @if(!$isActive)
                                        <span class="inline-flex items-center gap-1 text-[10px] font-semibold px-2 py-0.5 rounded-full bg-red-100 text-red-600">
                                            Inactive
                                        </span>
                                    @endif
                                </div>
                                <p class="text-xs text-gray-400 font-mono mt-0.5">{{ $f['field_key'] }}</p>
                                {{-- Options preview --}}
                                @if(count($fOptions) > 0)
                                    <div class="flex flex-wrap gap-1 mt-1.5">
                                        @foreach(array_slice($fOptions,0,4) as $opt)
                                            <span class="opt-chip">{{ $opt['label'] }}</span>
                                        @endforeach
                                        @if(count($fOptions)>4)
                                            <span class="opt-chip bg-gray-100 text-gray-500">+{{ count($fOptions)-4 }} more</span>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </div>

                        {{-- Middle: type badge --}}
                        <div class="flex items-center gap-2 px-4">
                            <span class="{{ $sColor['bg'] }} {{ $sColor['text'] }} text-xs font-semibold px-2.5 py-1 rounded-full capitalize">
                                {{ $fieldTypes[$f['field_type']] ?? $f['field_type'] }}
                            </span>
                        </div>

                        {{-- Right: actions --}}
                        <div class="flex items-center gap-2 ml-auto">

                            {{-- Toggle active (custom only) --}}
                            @if(!$isSystem)
                                <button wire:click="toggleActive({{ $f['id'] }})"
                                        title="{{ $isActive ? 'Deactivate' : 'Activate' }}"
                                        class="toggle-track {{ $isActive ? 'on' : 'off' }}">
                                    <span class="toggle-thumb"></span>
                                </button>
                            @endif

                            {{-- Edit --}}
                            <button wire:click="openEdit({{ $f['id'] }})"
                                    title="Edit field"
                                    class="w-8 h-8 rounded-lg flex items-center justify-center
                                           hover:bg-violet-50 text-gray-400 hover:text-violet-600 transition">
                                <i class="fa-solid fa-pen text-xs"></i>
                            </button>

                            {{-- Delete (custom only) --}}
                            @if(!$isSystem)
                                <button wire:click="confirmDelete({{ $f['id'] }})"
                                        title="Delete field"
                                        class="w-8 h-8 rounded-lg flex items-center justify-center
                                               hover:bg-red-50 text-gray-400 hover:text-red-500 transition">
                                    <i class="fa-solid fa-trash text-xs"></i>
                                </button>
                            @endif
                        </div>

                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="bg-white rounded-2xl border border-gray-200 py-16 text-center">
            <div class="w-14 h-14 rounded-2xl bg-gray-100 flex items-center justify-center mx-auto mb-3">
                <i class="fa-solid fa-table-columns text-gray-400 text-xl"></i>
            </div>
            <p class="text-sm font-semibold text-gray-600">No fields found</p>
            <p class="text-xs text-gray-400 mt-1">Try adjusting your search or filter.</p>
        </div>
    @endforelse


    {{-- ══════════════════════════════════════════════════════════════════════
         SLIDE-OVER PANEL — Create / Edit
    ══════════════════════════════════════════════════════════════════════════ --}}

    {{-- Overlay --}}
    <div wire:click="cancelForm"
         class="panel-overlay {{ $showForm ? 'open' : '' }}"></div>

    {{-- Panel --}}
    <div class="slide-panel {{ $showForm ? 'open' : '' }}">

        {{-- Panel Header --}}
        <div class="px-6 py-4 border-b border-gray-100 flex items-center gap-3 sticky top-0 bg-white z-10">
            <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0"
                 style="background:#7a3f91;">
                <i class="fa-solid {{ $isEditing ? 'fa-pen' : 'fa-plus' }} text-white text-xs"></i>
            </div>
            <div class="flex-1">
                <h3 class="font-extrabold text-gray-900 text-sm">
                    {{ $isEditing ? 'Edit Field' : 'Add New Field' }}
                </h3>
                <p class="text-xs text-gray-500">
                    {{ $isEditing ? 'Update field definition' : 'Create a custom information field' }}
                </p>
            </div>
            <button wire:click="cancelForm"
                    class="w-8 h-8 rounded-lg flex items-center justify-center hover:bg-gray-100 text-gray-400 transition">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        {{-- Panel Alerts --}}
        @if($errorMessage && $showForm)
            <div class="mx-6 mt-4 p-3 bg-red-50 border border-red-200 rounded-xl text-red-800 flex items-start gap-2">
                <i class="fa-solid fa-circle-exclamation mt-0.5 text-red-500 text-sm flex-shrink-0"></i>
                <p class="text-sm font-medium">{{ $errorMessage }}</p>
            </div>
        @endif

        {{-- Panel Form --}}
        <div class="p-6 space-y-4">

            {{-- Field Label --}}
            <div>
                <label class="block text-sm font-semibold text-gray-800 mb-1">
                    Field Label <span class="text-red-500">*</span>
                </label>
                <input wire:model.live="field_label" type="text"
                       placeholder="e.g. Hair Color, Second Contact Number"
                       class="fm-input {{ $errors->has('field_label') ? 'err' : '' }}">
                @error('field_label')
                    <p class="text-xs text-red-600 mt-1 flex items-center gap-1">
                        <i class="fa-solid fa-circle-exclamation"></i> {{ $message }}
                    </p>
                @enderror
                <p class="text-xs text-gray-400 mt-1">This is what the alumni will see on their profile.</p>
            </div>

            {{-- Field Key --}}
            <div>
                <label class="block text-sm font-semibold text-gray-800 mb-1">
                    Field Key <span class="text-red-500">*</span>
                </label>
                <input wire:model="field_key" type="text"
                       placeholder="e.g. hair_color"
                       {{ $isEditing && collect($fields)->firstWhere('id', $editingId)['is_system'] ?? false ? 'readonly' : '' }}
                       class="fm-input font-mono {{ $errors->has('field_key') ? 'err' : '' }}">
                @error('field_key')
                    <p class="text-xs text-red-600 mt-1 flex items-center gap-1">
                        <i class="fa-solid fa-circle-exclamation"></i> {{ $message }}
                    </p>
                @enderror
                <p class="text-xs text-gray-400 mt-1">Unique identifier — lowercase, numbers, underscores only.</p>
            </div>

            {{-- Field Type + Section --}}
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-800 mb-1">
                        Field Type <span class="text-red-500">*</span>
                    </label>
                    <select wire:model.live="field_type"
                            class="fm-input {{ $errors->has('field_type') ? 'err' : '' }}">
                        @foreach($fieldTypes as $val => $label)
                            <option value="{{ $val }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('field_type')
                        <p class="text-xs text-red-600 mt-1"><i class="fa-solid fa-circle-exclamation mr-1"></i>{{ $message }}</p>
                    @enderror
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-800 mb-1">
                        Section <span class="text-red-500">*</span>
                    </label>
                    <select wire:model="section"
                            class="fm-input {{ $errors->has('section') ? 'err' : '' }}">
                        @foreach($sections as $val => $label)
                            <option value="{{ $val }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('section')
                        <p class="text-xs text-red-600 mt-1"><i class="fa-solid fa-circle-exclamation mr-1"></i>{{ $message }}</p>
                    @enderror
                </div>
            </div>

            {{-- Required toggle --}}
            <div class="flex items-center justify-between p-3 rounded-xl bg-gray-50 border border-gray-100">
                <div>
                    <p class="text-sm font-semibold text-gray-800">Required Field</p>
                    <p class="text-xs text-gray-400">Alumni must fill this in to complete their profile</p>
                </div>
                <button type="button" wire:click="$set('is_required', {{ $is_required ? 'false' : 'true' }})"
                        class="toggle-track {{ $is_required ? 'on' : 'off' }}">
                    <span class="toggle-thumb"></span>
                </button>
            </div>

            {{-- Options (for select / radio) ──────────────────────────────── --}}
            @if(in_array($field_type, ['select','radio']))
                <div class="border border-gray-200 rounded-xl overflow-hidden">
                    <div class="px-4 py-2.5 bg-gray-50 border-b border-gray-100 flex items-center gap-2">
                        <i class="fa-solid fa-list text-gray-400 text-xs"></i>
                        <span class="text-sm font-bold text-gray-700">Options</span>
                        <span class="ml-auto text-xs text-gray-400">{{ count($field_options) }} added</span>
                    </div>
                    <div class="p-4 space-y-3">

                        {{-- Existing options --}}
                        @if(count($field_options))
                            <div class="space-y-1.5 max-h-40 overflow-y-auto pr-1">
                                @foreach($field_options as $idx => $opt)
                                    <div class="flex items-center gap-2 px-3 py-2 bg-gray-50 rounded-lg border border-gray-100">
                                        <span class="flex-1 text-sm font-semibold text-gray-800 truncate">{{ $opt['label'] }}</span>
                                        <span class="text-xs font-mono text-gray-400 truncate">{{ $opt['value'] }}</span>
                                        <button type="button" wire:click="removeOption({{ $idx }})"
                                                class="flex-shrink-0 w-6 h-6 flex items-center justify-center rounded-md hover:bg-red-100 text-gray-400 hover:text-red-500 transition">
                                            <i class="fa-solid fa-xmark text-xs"></i>
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        {{-- Add option form --}}
                        <div class="flex gap-2">
                            <div class="flex-1">
                                <input wire:model="new_opt_label" type="text"
                                       placeholder="Label (e.g. Brown)"
                                       wire:keydown.enter.prevent="addOption"
                                       class="fm-input text-xs">
                            </div>
                            <div class="flex-1">
                                <input wire:model="new_opt_value" type="text"
                                       placeholder="Value (auto)"
                                       wire:keydown.enter.prevent="addOption"
                                       class="fm-input font-mono text-xs">
                            </div>
                            <button type="button" wire:click="addOption"
                                    class="flex-shrink-0 w-9 h-9 rounded-xl flex items-center justify-center text-white hover:opacity-90 transition"
                                    style="background:#7a3f91;">
                                <i class="fa-solid fa-plus text-xs"></i>
                            </button>
                        </div>
                        <p class="text-xs text-gray-400">Press Enter or click <i class="fa-solid fa-plus text-[10px]"></i> to add an option. Value is auto-generated from label if left blank.</p>
                    </div>
                </div>
            @endif

        </div>

        {{-- Panel Footer Buttons --}}
        <div class="sticky bottom-0 bg-white border-t border-gray-100 px-6 py-4 flex gap-3">
            <button wire:click="saveField"
                    wire:loading.attr="disabled"
                    wire:target="saveField"
                    class="flex-1 text-white py-2.5 rounded-xl font-bold text-sm shadow-md
                           hover:opacity-90 disabled:opacity-70 active:scale-[0.98] transition-all
                           flex items-center justify-center gap-2"
                    style="background:#7a3f91;">
                <span wire:loading.remove wire:target="saveField">
                    <i class="fa-solid fa-floppy-disk mr-1.5"></i>
                    {{ $isEditing ? 'Update Field' : 'Save Field' }}
                </span>
                <span wire:loading wire:target="saveField">
                    <i class="fa-solid fa-circle-notch fa-spin mr-1.5"></i> Saving…
                </span>
            </button>
            <button wire:click="cancelForm"
                    class="flex-1 py-2.5 rounded-xl font-bold text-sm border-2 border-gray-200
                           bg-white text-gray-700 hover:bg-gray-50 active:scale-[0.98] transition-all
                           flex items-center justify-center gap-2">
                <i class="fa-solid fa-xmark mr-1.5"></i> Cancel
            </button>
        </div>
    </div>


    {{-- ══════════════════════════════════════════════════════════════════════
         DELETE CONFIRMATION MODAL
    ══════════════════════════════════════════════════════════════════════════ --}}
    @if($showDelete)
        <div class="fixed inset-0 z-50 flex items-center justify-center px-4">

            {{-- Backdrop --}}
            <div wire:click="cancelDelete" class="absolute inset-0 bg-black/40"></div>

            {{-- Modal --}}
            <div class="relative bg-white rounded-2xl shadow-2xl border border-gray-100 w-full max-w-md p-6 z-10">
                <div class="text-center">
                    <div class="w-14 h-14 rounded-2xl bg-red-100 flex items-center justify-center mx-auto mb-4">
                        <i class="fa-solid fa-triangle-exclamation text-red-500 text-2xl"></i>
                    </div>
                    <h3 class="text-lg font-extrabold text-gray-900">Delete Field?</h3>
                    <p class="text-sm text-gray-500 mt-2">
                        You're about to delete <strong class="text-gray-800">"{{ $deleteLabel }}"</strong>.
                        <br>All alumni values stored for this field will also be removed.
                        <br><span class="text-red-500 font-semibold">This action cannot be undone.</span>
                    </p>
                </div>
                <div class="flex gap-3 mt-6">
                    <button wire:click="deleteField"
                            wire:loading.attr="disabled"
                            wire:target="deleteField"
                            class="flex-1 bg-red-500 hover:bg-red-600 text-white font-bold py-2.5
                                   rounded-xl text-sm transition active:scale-[0.98]
                                   flex items-center justify-center gap-2">
                        <span wire:loading.remove wire:target="deleteField">
                            <i class="fa-solid fa-trash mr-1.5"></i> Delete
                        </span>
                        <span wire:loading wire:target="deleteField">
                            <i class="fa-solid fa-circle-notch fa-spin mr-1.5"></i> Deleting…
                        </span>
                    </button>
                    <button wire:click="cancelDelete"
                            class="flex-1 border-2 border-gray-200 bg-white text-gray-700 font-bold
                                   py-2.5 rounded-xl text-sm hover:bg-gray-50 transition active:scale-[0.98]">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    @endif

</div>