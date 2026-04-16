<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use App\Models\Alumni;
use App\Models\Course;
use Illuminate\Support\Facades\Log;

new class extends Component {

    public array  $coursesList = [];
    public string $courseCode  = '';
    public string $courseName  = '';
    public ?int   $editingId   = null;
    public bool   $saving      = false;
    public ?int   $deleteId    = null;
    public string $deleteName  = '';
    public bool   $deleting    = false;
    public string $alertMsg    = '';
    public string $alertType   = '';
    public string $search      = '';

    public function mount(): void
    {
        $this->loadCourses();
    }

    #[Computed]
    public function filteredCourses(): array
    {
        if (!$this->search) return $this->coursesList;
        $term = strtolower(trim($this->search));
        return array_values(array_filter($this->coursesList, fn($c) =>
            str_contains(strtolower($c['code']), $term)
            || str_contains(strtolower($c['name']), $term)
            || str_contains(strtolower($c['college'] ?? ''), $term)
        ));
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
                $course  = Course::findOrFail($this->editingId);
                $oldCode = $course->code;
                $oldName = $course->name;
                $course->update(['code' => $code, 'name' => $name]);

                if ($oldCode !== $code || $oldName !== $name) {
                    Alumni::where('course_code', $oldCode)
                          ->update(['course_code' => $code, 'course_name' => $name]);
                }
                $this->setAlert('success', "Course '{$code}' updated successfully.");
            } else {
                Course::create(['code' => $code, 'name' => $name]);
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

    public function confirmDelete(int $id): void
    {
        try {
            $c = Course::findOrFail($id);
            $this->deleteId   = $id;
            $this->deleteName = $c->name . ' (' . $c->code . ')';
            $this->clearAlert();
        } catch (\Exception) {
            $this->setAlert('error', 'Failed to find course.');
        }
    }

    public function cancelDelete(): void
    {
        $this->deleteId   = null;
        $this->deleteName = '';
        $this->deleting   = false;
    }

    public function deleteCourse(): void
    {
        if (!$this->deleteId) return;
        $this->deleting = true;

        try {
            $course  = Course::findOrFail($this->deleteId);
            $oldCode = $course->code;
            Alumni::where('course_code', $oldCode)
                  ->update(['course_code' => null, 'course_name' => null]);
            $course->delete();
            $this->loadCourses();
            $this->cancelDelete();
            $this->setAlert('success', "Course '{$oldCode}' deleted. Affected alumni have been unlinked.");
        } catch (\Exception $e) {
            Log::error('Course delete: ' . $e->getMessage());
            $this->setAlert('error', 'Failed to delete course.');
            $this->cancelDelete();
        } finally {
            $this->deleting = false;
        }
    }
};
?>

<div class="flex flex-col px-3 sm:px-5 lg:px-6 pt-5 pb-6 max-w-screen-2xl mx-auto" style="min-height:100vh; background:#f7f3fe;">

    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
        <div class="flex items-center gap-3">
            <a href="{{ route('registrar.alumni') }}" wire:navigate
               class="w-9 h-9 rounded-xl flex items-center justify-center bg-white border border-gray-200 hover:bg-gray-50 text-gray-500 transition shadow-sm">
                <i class="fas fa-arrow-left text-sm"></i>
            </a>
            <div class="w-10 h-10 sm:w-11 sm:h-11 rounded-xl flex items-center justify-center shadow-lg shrink-0"
                 style="background:linear-gradient(135deg,#7a3f91,#9b59b6);">
                <i class="fas fa-book-open text-white text-sm"></i>
            </div>
            <div>
                <h1 class="text-lg sm:text-xl font-extrabold text-gray-900 leading-tight">Manage Courses</h1>
                <p class="text-gray-400 text-xs">Add, edit, and remove course codes</p>
            </div>
        </div>
        <div class="flex items-center gap-2 px-3 py-1.5 bg-white rounded-xl border border-gray-200 shadow-sm self-start sm:self-auto">
            <i class="fas fa-book text-gray-300 text-xs"></i>
            <span class="text-xs font-bold text-gray-600">{{ count($coursesList) }} course{{ count($coursesList) !== 1 ? 's' : '' }}</span>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-5 gap-5 items-start">

        {{-- ── Add / Edit Form ──────────────────────────────────────── --}}
        <div class="lg:col-span-2">
            <div class="bg-white rounded-2xl shadow-sm border border-purple-100 overflow-hidden lg:sticky lg:top-5">

                <div class="px-5 py-3.5 bg-gradient-to-r from-[#f9f5ff] to-white border-b border-gray-100">
                    <h2 class="font-bold text-gray-900 text-sm flex items-center gap-2">
                        <i class="fas fa-{{ $editingId ? 'pencil' : 'plus-circle' }} text-[#7a3f91] text-xs"></i>
                        {{ $editingId ? 'Edit Course' : 'Add New Course' }}
                    </h2>
                    @if($editingId)
                        <p class="text-xs text-gray-400 mt-0.5">Editing: <strong class="text-gray-700">{{ $courseCode }}</strong></p>
                    @endif
                </div>

                {{-- Alert --}}
                @if($alertMsg)
                <div class="mx-4 mt-4 flex items-start gap-2.5 p-3 rounded-xl {{ $alertType === 'success' ? 'bg-emerald-50 border border-emerald-200' : 'bg-red-50 border border-red-200' }}">
                    <i class="fas mt-0.5 text-xs {{ $alertType === 'success' ? 'fa-circle-check text-emerald-500' : 'fa-circle-xmark text-red-500' }}"></i>
                    <p class="text-xs font-semibold {{ $alertType === 'success' ? 'text-emerald-800' : 'text-red-800' }} flex-1">{{ $alertMsg }}</p>
                    <button wire:click="$set('alertMsg','')" class="text-gray-400 hover:text-gray-600 transition shrink-0">
                        <i class="fas fa-xmark text-xs"></i>
                    </button>
                </div>
                @endif

                <div class="p-5 space-y-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-600 uppercase tracking-wide mb-1.5">
                            Course Code <span class="text-red-400">*</span>
                        </label>
                        <input wire:model.defer="courseCode" type="text" placeholder="e.g. BSIT"
                               class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm bg-white text-gray-900 font-mono uppercase focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                               maxlength="20" autocomplete="off"
                               @keydown.enter.prevent="$wire.saveCourse()">
                        <p class="text-[10px] text-gray-400 mt-1">Unique identifier, e.g. BSIT, BSN, BSED</p>
                    </div>

                    <div>
                        <label class="block text-xs font-bold text-gray-600 uppercase tracking-wide mb-1.5">
                            Course Name <span class="text-red-400">*</span>
                        </label>
                        <input wire:model.defer="courseName" type="text" placeholder="e.g. Bachelor of Science in Information Technology"
                               class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                               maxlength="150" autocomplete="off"
                               @keydown.enter.prevent="$wire.saveCourse()">
                    </div>

                    <div class="flex gap-2.5 pt-1">
                        @if($editingId)
                        <button wire:click="cancelEdit"
                                class="flex-1 bg-white border border-gray-200 text-gray-700 px-4 py-2.5 rounded-xl text-sm font-bold hover:bg-gray-50 transition active:scale-[.99]">
                            Cancel
                        </button>
                        @endif

                        <button wire:click="saveCourse"
                                wire:loading.attr="disabled" wire:target="saveCourse"
                                class="{{ $editingId ? 'flex-1' : 'w-full' }} text-white px-4 py-2.5 rounded-xl text-sm font-bold transition flex items-center justify-center gap-2 disabled:opacity-60 hover:opacity-90 active:scale-[.99]"
                                style="background:linear-gradient(135deg,#7a3f91,#9b59b6);">
                            <span wire:loading wire:target="saveCourse">
                                <i class="fas fa-spinner animate-spin text-xs"></i>
                            </span>
                            <span wire:loading.remove wire:target="saveCourse">
                                <i class="fas fa-{{ $editingId ? 'floppy-disk' : 'plus' }} text-xs"></i>
                                {{ $editingId ? 'Update Course' : 'Add Course' }}
                            </span>
                        </button>
                    </div>
                </div>

                <div class="mx-4 mb-4 p-3 rounded-xl bg-amber-50 border border-amber-200">
                    <p class="text-xs text-amber-700">
                        <i class="fas fa-circle-info mr-1.5 text-amber-400"></i>
                        Editing or deleting a course will automatically update all linked alumni records.
                    </p>
                </div>
            </div>
        </div>

        {{-- ── Course List ─────────────────────────────────────────── --}}
        <div class="lg:col-span-3">
            <div class="bg-white rounded-2xl shadow-sm border border-purple-100 overflow-hidden">

                {{-- Search bar --}}
                <div class="px-4 py-3 border-b border-gray-100 bg-gray-50/80 flex items-center gap-3">
                    <div class="relative flex-1">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-300 text-xs pointer-events-none"></i>
                        <input wire:model.live.debounce.150ms="search" type="text"
                               placeholder="Search code or name…"
                               class="w-full pl-8 pr-3 py-2 border border-gray-200 rounded-lg text-xs bg-white text-gray-900 focus:outline-none focus:border-[#7a3f91] focus:ring-2 focus:ring-[#7a3f91]/10 transition"
                               autocomplete="off">
                    </div>
                    @if($search)
                    <button wire:click="$set('search','')"
                            class="text-xs text-gray-400 hover:text-gray-600 font-semibold transition whitespace-nowrap flex items-center gap-1">
                        <i class="fas fa-xmark text-xs"></i> Clear
                    </button>
                    @endif
                </div>

                {{-- Delete confirmation banner --}}
                @if($deleteId)
                <div class="mx-4 mt-4 p-4 rounded-xl bg-red-50 border border-red-200">
                    <div class="flex items-start gap-3">
                        <div class="w-9 h-9 rounded-xl bg-red-100 flex items-center justify-center shrink-0">
                            <i class="fas fa-trash text-red-600 text-sm"></i>
                        </div>
                        <div class="flex-1">
                            <p class="font-bold text-red-900 text-sm">Confirm Delete</p>
                            <p class="text-xs text-red-700 mt-0.5">Delete <strong>{{ $deleteName }}</strong>?</p>
                            <p class="text-xs text-red-500 mt-1">Affected alumni will have their course unlinked — records will not be deleted.</p>
                        </div>
                    </div>
                    <div class="flex gap-2 mt-3">
                        <button wire:click="cancelDelete"
                                class="flex-1 bg-white border border-gray-200 text-gray-700 px-4 py-2 rounded-lg text-xs font-bold hover:bg-gray-50 transition active:scale-[.99]">
                            Cancel
                        </button>
                        <button wire:click="deleteCourse"
                                wire:loading.attr="disabled" wire:target="deleteCourse"
                                class="flex-1 bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-xs font-bold transition flex items-center justify-center gap-2 disabled:opacity-60 active:scale-[.99]">
                            <span wire:loading wire:target="deleteCourse"><i class="fas fa-spinner animate-spin"></i></span>
                            <span wire:loading.remove wire:target="deleteCourse"><i class="fas fa-trash"></i> Delete</span>
                        </button>
                    </div>
                </div>
                @endif

                {{-- Course list --}}
                <div class="divide-y divide-gray-50 overflow-y-auto" style="max-height:calc(100vh - 280px);">
                    @forelse($this->filteredCourses as $c)
                    <div class="flex items-center justify-between px-4 py-3.5 transition-colors hover:bg-[#faf7ff]
                                {{ $editingId === $c['id'] ? 'bg-[#f5eef9] border-l-4 border-[#7a3f91]' : '' }}
                                {{ $deleteId  === $c['id'] ? 'bg-red-50 border-l-4 border-red-400' : '' }}">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0 transition"
                                 style="{{ $editingId === $c['id']
                                    ? 'background:linear-gradient(135deg,#7a3f91,#9b59b6);'
                                    : 'background:#f5eef9;' }}">
                                <span class="text-xs font-extrabold {{ $editingId === $c['id'] ? 'text-white' : 'text-[#7a3f91]' }}">
                                    {{ strtoupper(substr($c['code'], 0, 2)) }}
                                </span>
                            </div>
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <p class="font-bold text-gray-900 text-sm font-mono">{{ $c['code'] }}</p>
                                    @if(!empty($c['college']))
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold" style="background:#f5eef9;color:#7a3f91;border:1px solid #d4aaeb;">
                                            {{ $c['college'] }}
                                        </span>
                                    @endif
                                </div>
                                <p class="text-gray-400 text-xs mt-0.5 truncate max-w-xs">{{ $c['name'] }}</p>
                            </div>
                        </div>

                        <div class="flex gap-1.5 ml-3 shrink-0">
                            <button wire:click="openEdit({{ $c['id'] }})"
                                    class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-bold transition active:scale-[.97]
                                           {{ $editingId === $c['id']
                                              ? 'text-white border border-[#5e2f72]'
                                              : 'border text-[#7a3f91] hover:bg-[#e9d5f3]' }}"
                                    style="{{ $editingId === $c['id']
                                        ? 'background:linear-gradient(135deg,#7a3f91,#9b59b6);border-color:#5e2f72;'
                                        : 'background:#f5eef9;border-color:#d4aaeb;' }}">
                                <i class="fas fa-pencil text-xs"></i>
                                <span class="hidden sm:inline">Edit</span>
                            </button>
                            <button wire:click="confirmDelete({{ $c['id'] }})"
                                    class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-bold bg-white border border-red-100 text-red-500 hover:bg-red-50 transition active:scale-[.97]">
                                <i class="fas fa-trash text-xs"></i>
                                <span class="hidden sm:inline">Delete</span>
                            </button>
                        </div>
                    </div>
                    @empty
                    <div class="py-20 text-center">
                        @if($search)
                        <div class="flex flex-col items-center gap-3">
                            <div class="w-12 h-12 rounded-2xl bg-gray-100 flex items-center justify-center">
                                <i class="fas fa-search text-lg text-gray-300"></i>
                            </div>
                            <p class="font-semibold text-gray-400 text-sm">No courses match "{{ $search }}"</p>
                            <button wire:click="$set('search','')" class="text-xs font-semibold hover:underline" style="color:#7a3f91;">
                                Clear search
                            </button>
                        </div>
                        @else
                        <div class="flex flex-col items-center gap-3">
                            <div class="w-12 h-12 rounded-2xl flex items-center justify-center" style="background:#f5eef9;">
                                <i class="fas fa-book text-lg" style="color:#d4aaeb;"></i>
                            </div>
                            <p class="font-semibold text-gray-400 text-sm">No courses yet</p>
                            <p class="text-xs text-gray-300">Add your first course using the form</p>
                        </div>
                        @endif
                    </div>
                    @endforelse
                </div>

                @if(count($this->filteredCourses) > 0)
                <div class="px-4 py-2.5 border-t border-gray-100 bg-gray-50/80">
                    <p class="text-[10px] text-gray-400 font-semibold">
                        Showing <strong class="text-gray-600">{{ count($this->filteredCourses) }}</strong>
                        @if($search) of <strong>{{ count($coursesList) }}</strong> @endif
                        course{{ count($this->filteredCourses) !== 1 ? 's' : '' }}
                    </p>
                </div>
                @endif
            </div>
        </div>
    </div>
</div>