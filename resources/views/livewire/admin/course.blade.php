<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use App\Models\Alumni;
use App\Models\Course;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

new class extends Component {

    public array  $coursesList = [];
    public string $courseCode  = '';
    public string $courseName  = '';
    public ?int   $editingId   = null;
    public bool   $saving      = false;
    public string $alertMsg    = '';
    public string $alertType   = '';

    public function mount(): void
    {
        $this->loadCourses();
    }

    #[Computed]
    public function filteredCourses(): array
    {
        return $this->coursesList;
    }

    private function loadCourses(): void
    {
        $this->coursesList = Course::orderBy('code')->get()->toArray();
    }

    private function setAlert(string $type, string $msg): void
    {
        $this->alertType = $type;
        $this->alertMsg  = $msg;
    }

    private function clearAlert(): void
    {
        $this->alertMsg  = '';
        $this->alertType = '';
    }

    private function writeAuditLog(
        string  $action,
        string  $description,
        string  $severity    = 'info',
        ?array  $oldValues   = null,
        ?array  $newValues   = null,
        ?string $subjectLabel = null,
    ): void {
        try {
            $user = Auth::user();
            AuditLog::create([
                'action'        => $action,
                'module'        => 'system',
                'user_name'     => $user?->name          ?? 'Admin',
                'user_email'    => $user?->email         ?? null,
                'user_role'     => $user?->role          ?? 'admin',
                'subject_label' => $subjectLabel,
                'description'   => $description,
                'old_values'    => $oldValues,
                'new_values'    => $newValues,
                'ip_address'    => Request::ip(),
                'user_agent'    => Request::userAgent(),
                'severity'      => $severity,
                'is_flagged'    => false,
            ]);
        } catch (\Throwable $e) {
            Log::warning('AuditLog write failed: ' . $e->getMessage());
        }
    }

    public function openEdit(int $id): void
    {
        try {
            $c = Course::findOrFail($id);
            $this->editingId  = $c->id;
            $this->courseCode = $c->code;
            $this->courseName = $c->name;
            $this->clearAlert();
        } catch (\Exception) {
            $this->setAlert('error', 'Failed to load course.');
        }
    }

    public function cancelEdit(): void
    {
        $this->editingId  = null;
        $this->courseCode = '';
        $this->courseName = '';
        $this->clearAlert();
        $this->saving     = false;
    }

    public function saveCourse(): void
    {
        $this->saving = true;
        $code = strtoupper(trim($this->courseCode));
        $name = trim($this->courseName);

        if (!$code || !$name) {
            $this->setAlert('error', 'Course Code and Course Name are both required.');
            $this->saving = false;
            return;
        }

        if (!preg_match('/^[A-Z0-9\-\/\s]+$/', $code)) {
            $this->setAlert('error', 'Course code may only contain letters, numbers, hyphens, or slashes.');
            $this->saving = false;
            return;
        }

        try {
            if ($this->editingId) {
                // ── EDIT ──────────────────────────────────────────────
                $course  = Course::findOrFail($this->editingId);
                $oldCode = $course->code;
                $oldName = $course->name;
                $changed = $oldCode !== $code || $oldName !== $name;

                $course->update(['code' => $code, 'name' => $name]);

                if ($changed) {
                    Alumni::where('course_code', $oldCode)
                          ->update(['course_code' => $code, 'course_name' => $name]);
                }

                // Always write audit log on edit (even if unchanged, log the intent)
                $this->writeAuditLog(
                    action:       'updated',
                    description:  $changed
                        ? "Updated course '{$oldCode}' → '{$code}'. Linked alumni records were also updated."
                        : "Viewed/re-saved course '{$code}' with no changes.",
                    severity:     'info',
                    oldValues:    ['code' => $oldCode, 'name' => $oldName],
                    newValues:    ['code' => $code,    'name' => $name],
                    subjectLabel: "Course: {$code}",
                );

                $this->setAlert('success', "Course '{$code}' updated successfully.");

            } else {
                // ── CREATE ────────────────────────────────────────────
                Course::create(['code' => $code, 'name' => $name]);

                $this->writeAuditLog(
                    action:       'created',
                    description:  "Added new course '{$code}' — {$name}.",
                    severity:     'info',
                    newValues:    ['code' => $code, 'name' => $name],
                    subjectLabel: "Course: {$code}",
                );

                $this->setAlert('success', "Course '{$code}' added successfully.");
            }

            $this->loadCourses();
            $this->cancelEdit();

        } catch (\Exception $e) {
            $errMsg = str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'unique')
                ? "Course code '{$code}' already exists."
                : 'Failed to save course.';
            $this->setAlert('error', $errMsg);
            Log::error('Course save: ' . $e->getMessage());
        } finally {
            $this->saving = false;
        }
    }
};
?>

<div class="flex flex-col px-3 sm:px-5 lg:px-6 pt-5 pb-6 max-w-screen-2xl mx-auto" style="height:90vh;">

    {{-- ── PAGE HEADER ─────────────────────────────────────────── --}}
    <div class="flex items-center gap-3 mb-6">
        <div class="w-11 h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
             style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
            <i class="fas fa-book-open text-white text-base"></i>
        </div>
        <div>
            <h1 class="text-3xl font-semibold text-[#333333] leading-tight">Manage Courses</h1>
            <p class="text-xl text-[#666666] font-normal">Add and edit course codes</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-5 gap-5 items-start flex-1 min-h-0">

        {{-- ── Add / Edit Form ──────────────────────────────────────── --}}
        <div class="lg:col-span-2">
            <div class="bg-white rounded-2xl shadow-sm border border-[#E8E0F0] overflow-hidden lg:sticky lg:top-5">

                {{-- Card Header --}}
                <div class="px-5 py-3.5 border-b border-[#E8E0F0] flex items-center gap-2"
                     style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                    <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                         style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                        <i class="fas fa-{{ $editingId ? 'pencil' : 'plus-circle' }} text-white" style="font-size:12px;"></i>
                    </div>
                    <div>
                        <p class="text-xl font-semibold text-[#333333] uppercase tracking-wide leading-tight">
                            {{ $editingId ? 'Edit Course' : 'Add New Course' }}
                        </p>
                        @if($editingId)
                            <p class="text-xs text-[#999999] font-normal mt-0.5">
                                Editing: <strong class="text-[#333333]">{{ $courseCode }}</strong>
                            </p>
                        @endif
                    </div>
                </div>

                {{-- Alert --}}
                @if($alertMsg)
                <div class="mx-4 mt-4 flex items-start gap-2.5 p-3 rounded-xl
                            {{ $alertType === 'success'
                                ? 'bg-emerald-50 border border-emerald-200'
                                : 'bg-red-50 border border-red-200' }}">
                    <i class="fas mt-0.5 text-sm
                               {{ $alertType === 'success'
                                   ? 'fa-circle-check text-emerald-500'
                                   : 'fa-circle-xmark text-red-500' }}"></i>
                    <p class="text-sm font-semibold flex-1
                              {{ $alertType === 'success' ? 'text-emerald-800' : 'text-red-800' }}">
                        {{ $alertMsg }}
                    </p>
                    <button wire:click="$set('alertMsg','')"
                            class="text-[#999999] hover:text-[#333333] transition shrink-0">
                        <i class="fas fa-xmark text-sm"></i>
                    </button>
                </div>
                @endif

                <div class="p-5 space-y-4">

                    {{-- Course Code --}}
                    <div>
                        <label class="block text-xs font-semibold text-[#666666] uppercase tracking-[.08em] mb-1.5">
                            Course Code <span class="text-red-400">*</span>
                        </label>
                        <input wire:model.defer="courseCode"
                               type="text"
                               placeholder="e.g. BSIT"
                               class="w-full px-3 py-3 border border-[#E8E0F0] rounded-xl text-base bg-white text-[#333333] font-mono uppercase
                                      focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/10 transition"
                               maxlength="20"
                               autocomplete="off"
                               @keydown.enter.prevent="$wire.saveCourse()">
                        <p class="text-xs text-[#999999] font-normal mt-1">
                            Unique identifier — e.g. BSIT, BSN, BSED
                        </p>
                    </div>

                    {{-- Course Name --}}
                    <div>
                        <label class="block text-xs font-semibold text-[#666666] uppercase tracking-[.08em] mb-1.5">
                            Course Name <span class="text-red-400">*</span>
                        </label>
                        <input wire:model.defer="courseName"
                               type="text"
                               placeholder="e.g. Bachelor of Science in Information Technology"
                               class="w-full px-3 py-3 border border-[#E8E0F0] rounded-xl text-base bg-white text-[#333333]
                                      focus:outline-none focus:border-[#7A3F91] focus:ring-2 focus:ring-[#7A3F91]/10 transition"
                               maxlength="150"
                               autocomplete="off"
                               @keydown.enter.prevent="$wire.saveCourse()">
                    </div>

                    {{-- Action buttons --}}
                    <div class="flex gap-2.5 pt-1">
                        @if($editingId)
                        <button wire:click="cancelEdit"
                                class="flex-1 bg-white border border-[#E8E0F0] text-[#666666] px-4 py-3 rounded-xl
                                       text-sm font-semibold hover:bg-[#F9FAFB] transition active:scale-[.99]">
                            Cancel
                        </button>
                        @endif

                        <button wire:click="saveCourse"
                                wire:loading.attr="disabled" wire:target="saveCourse"
                                class="{{ $editingId ? 'flex-1' : 'w-full' }} text-white px-4 py-3 rounded-xl text-sm font-semibold
                                       transition flex items-center justify-center gap-2 disabled:opacity-60 hover:opacity-90 active:scale-[.99]"
                                style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                            <span wire:loading wire:target="saveCourse">
                                <i class="fas fa-spinner animate-spin text-sm"></i>
                            </span>
                            <span wire:loading.remove wire:target="saveCourse">
                                <i class="fas fa-{{ $editingId ? 'floppy-disk' : 'plus' }} text-sm"></i>
                                {{ $editingId ? 'Update Course' : 'Add Course' }}
                            </span>
                        </button>
                    </div>
                </div>

                {{-- Info note --}}
                <div class="mx-4 mb-4 p-3 rounded-xl bg-amber-50 border border-amber-200">
                    <p class="text-xs text-amber-700 font-normal">
                        <i class="fas fa-circle-info mr-1.5 text-amber-400"></i>
                        Editing a course will automatically update all linked alumni records.
                    </p>
                </div>
            </div>
        </div>

        {{-- ── Course List ─────────────────────────────────────────── --}}
        <div class="lg:col-span-3 h-full min-h-0 flex flex-col">
            <div class="bg-white rounded-2xl shadow-sm border border-[#E8E0F0] overflow-hidden flex flex-col flex-1 min-h-0">

                {{-- Card Header --}}
                <div class="px-5 py-3.5 border-b border-[#E8E0F0] flex items-center justify-between shrink-0"
                     style="background:linear-gradient(135deg,#F9F7FC,#FFFFFF);">
                    <div class="flex items-center gap-2">
                        <div class="w-6 h-6 rounded-lg flex items-center justify-center"
                             style="background:linear-gradient(135deg,#7A3F91,#9b59b6);">
                            <i class="fas fa-list text-white" style="font-size:12px;"></i>
                        </div>
                        <p class="text-xl font-semibold text-[#333333] uppercase tracking-wide">Course List</p>
                    </div>
                    <div class="flex items-center gap-2 px-3 py-1 bg-[#F9F7FC] rounded-xl border border-[#E8E0F0]">
                        <i class="fas fa-book text-[#c0a0d8] text-xs"></i>
                        <span class="text-sm font-semibold text-[#666666]">
                            {{ count($coursesList) }} course{{ count($coursesList) !== 1 ? 's' : '' }}
                        </span>
                    </div>
                </div>

                {{-- List --}}
                <div class="divide-y divide-[#F5F5F5] overflow-y-auto flex-1 min-h-0">
                    @forelse($this->filteredCourses as $c)
                    <div class="flex items-center justify-between px-5 py-4 transition-colors
                                {{ $editingId === $c['id']
                                    ? 'bg-[#F9F7FC] border-l-4 border-[#7A3F91]'
                                    : 'hover:bg-[#FAFAFA]' }}">

                        {{-- Info --}}
                        <div class="flex items-center gap-4 min-w-0">
                            {{-- Avatar --}}
                            <div class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0 font-bold text-sm"
                                 style="{{ $editingId === $c['id']
                                    ? 'background:linear-gradient(135deg,#7A3F91,#9b59b6); color:#fff;'
                                    : 'background:#ede9fe; color:#7A3F91;' }}">
                                {{ strtoupper(substr($c['code'], 0, 2)) }}
                            </div>

                            {{-- Text --}}
                            <div class="min-w-0">
                                <p class="font-semibold text-[#333333] text-xl font-mono leading-tight uppercase">
                                    {{ $c['code'] }}
                                </p>
                                <p class="text-[#999999] text-xl font-normal mt-0.5 leading-snug truncate max-w-xs">
                                    {{ $c['name'] }}
                                </p>
                            </div>
                        </div>

                        {{-- Edit Action --}}
                        <div class="ml-3 shrink-0">
                            <button wire:click="openEdit({{ $c['id'] }})"
                                    class="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold border transition active:scale-[.97]
                                           {{ $editingId === $c['id']
                                               ? 'text-white border-[#5e2f72]'
                                               : 'text-[#7A3F91] border-[#d4aaeb] hover:bg-[#F9F7FC]' }}"
                                    style="{{ $editingId === $c['id']
                                        ? 'background:linear-gradient(135deg,#7A3F91,#9b59b6);'
                                        : 'background:#f9f5ff;' }}">
                                <i class="fas fa-pencil text-xs"></i>
                                Edit
                            </button>
                        </div>
                    </div>
                    @empty
                    <div class="py-20 text-center">
                        <div class="flex flex-col items-center gap-3">
                            <div class="w-14 h-14 rounded-2xl flex items-center justify-center" style="background:#f5eef9;">
                                <i class="fas fa-book text-2xl" style="color:#d4aaeb;"></i>
                            </div>
                            <p class="font-semibold text-[#999999] text-base">No courses yet</p>
                            <p class="text-xs text-[#CCCCCC] font-normal">Add your first course using the form on the left</p>
                        </div>
                    </div>
                    @endforelse
                </div>

                {{-- Footer count --}}
                @if(count($this->filteredCourses) > 0)
                <div class="px-5 py-3 border-t border-[#E8E0F0] bg-[#F9FAFB] shrink-0">
                    <p class="text-xs text-[#999999] font-semibold">
                        Showing <strong class="text-[#333333]">{{ count($this->filteredCourses) }}</strong>
                        course{{ count($this->filteredCourses) !== 1 ? 's' : '' }}
                    </p>
                </div>
                @endif

            </div>
        </div>

    </div>
</div>