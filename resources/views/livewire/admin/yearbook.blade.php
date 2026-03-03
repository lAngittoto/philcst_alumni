<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\WithPagination;
use App\Models\Alumni;
use App\Models\Course;

new class extends Component {
    use WithPagination;

    public string $search = '';
    public int|string $batch = '';
    public string $course = '';

    protected string $paginationTheme = 'tailwind';

    public function updatingSearch() { $this->resetPage(); }
    public function updatingBatch() { $this->resetPage(); }
    public function updatingCourse() { $this->resetPage(); }

    #[Computed]
    public function alumniRecords()
    {
        $q = Alumni::query();
        
        if ($this->search) {
            $q->where(function ($sub) {
                $sub->where('name', 'like', "%{$this->search}%")
                    ->orWhere('student_id', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%");
            });
        }
        
        if ($this->batch !== '' && $this->batch !== null) {
            $q->where('batch', (int)$this->batch);
        }
        
        if ($this->course) {
            $q->where('course_code', $this->course);
        }
        
        return $q->orderByDesc('created_at')->paginate(100);
    }

    #[Computed] 
    public function courses() { 
        return Course::orderBy('code')->get(); 
    }

    #[Computed] 
    public function batches() { 
        return Alumni::select('batch')
            ->distinct()
            ->orderByDesc('batch')
            ->pluck('batch')
            ->filter(fn($batch) => !is_null($batch))
            ->values();
    }

    public function resetFilters(): void
    {
        $this->search = '';
        $this->batch = '';
        $this->course = '';
        $this->resetPage();
    }

    public function getPhotoUrl(?string $path): string
    {
        // If NULL or empty or is string "null", return default
        if (empty($path) || $path === 'null' || is_null($path)) {
            return asset('storage/alumni-photos/default.png');
        }

        // If it's already the default, return it
        if (strpos($path, 'default.png') !== false) {
            return asset('storage/alumni-photos/default.png');
        }

        // If starts with alumni-photos/, prepend storage/
        if (str_starts_with($path, 'alumni-photos/')) {
            return asset('storage/' . $path);
        }

        // If no slash, assume it's in alumni-photos/
        if (!str_contains($path, '/')) {
            return asset('storage/alumni-photos/' . $path);
        }

        // Otherwise prepend storage/
        return asset('storage/' . $path);
    }
};

?>

<div class="flex flex-col bg-gradient-to-br from-slate-50 to-slate-100 overflow-hidden" style="height:93vh;">

    <style>
        :root {
            --primary-color: #7a3f91;
            --primary-dark: #6a3580;
            --primary-light: #8a4fa1;
            --text-dark: #2b0d3e;
        }

        * {
            scroll-behavior: smooth;
        }

        .scrollbar-custom::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        .scrollbar-custom::-webkit-scrollbar-track {
            background: transparent;
        }

        .scrollbar-custom::-webkit-scrollbar-thumb {
            background: rgba(122, 63, 145, 0.3);
            border-radius: 10px;
            transition: all 0.2s ease;
        }

        .scrollbar-custom::-webkit-scrollbar-thumb:hover {
            background: rgba(122, 63, 145, 0.6);
        }

        @keyframes slideInDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes spin {
            from {
                transform: rotate(0deg);
            }
            to {
                transform: rotate(360deg);
            }
        }

        .spin-icon {
            animation: spin 1s linear infinite;
        }

        .btn-primary {
            background: linear-gradient(135deg, #7a3f91, #7a3f91);
            color: white;
            border: none;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #6a3580, #6a3580);
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(122, 63, 145, 0.25);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-primary:disabled {
            background: linear-gradient(135deg, #cbd5e1, #94a3b8);
            cursor: not-allowed;
            transform: none;
        }

        .input-focus {
            transition: all 0.2s ease;
        }

        .input-focus:focus {
            border-color: #7a3f91 !important;
            box-shadow: 0 0 0 3px rgba(122, 63, 145, 0.1) !important;
            outline: none !important;
        }

        /* ALUMNI CARD - WHITE BODY + PURE PURPLE HEADER */
        .alumni-card {
            transition: all 0.3s ease;
            background: white;
            border: none;
            border-radius: 16px;
            overflow: visible;
            display: flex;
            flex-direction: column;
            align-items: center;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
            min-height: 420px;
            position: relative;
        }

        .alumni-card:hover {
            box-shadow: 0 12px 32px rgba(122, 63, 145, 0.15);
            transform: translateY(-8px);
        }

        /* Pure purple header section */
        .alumni-card-header {
            width: 100%;
            height: 120px;
            background: #7a3f91;
            border-radius: 16px 16px 0 0;
            position: relative;
        }

        /* Profile photo container - LEFT side, overlaps header */
        .alumni-card-photo-container {
            width: 110px;
            height: 110px;
            position: absolute;
            top: 50px;
            left: 20px;
            z-index: 10;
            flex-shrink: 0;
        }

        .alumni-card-photo {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            border: 5px solid white;
            background: #7a3f91;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            display: block;
        }

        /* Body content - CENTERED */
        .alumni-card-body {
            width: 100%;
            padding: 30px 20px 24px 20px;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            align-items: center;
            text-align: center;
            border-radius: 0 0 16px 16px;
            background: white;
        }

        .alumni-card-name {
            font-size: 18px;
            font-weight: 700;
            color: #2b0d3e;
            margin-bottom: 8px;
            line-height: 1.3;
        }

        .alumni-card-badge {
            display: inline-block;
            padding: 6px 12px;
            background: rgba(122, 63, 145, 0.08);
            color: #2b0d3e;
            border-radius: 16px;
            font-size: 12px;
            font-weight: 600;
            margin: 4px 2px;
        }

        .alumni-card-info {
            font-size: 14px;
            color: #2b0d3e;
            margin-top: 10px;
            line-height: 1.5;
            font-weight: 500;
        }

        .alumni-card-quote {
            font-size: 12px;
            color: #64748b;
            font-style: italic;
            margin: 12px 0;
            line-height: 1.4;
        }

        .alumni-card-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 10px 20px;
            margin-top: auto;
            background: white;
            color: #7a3f91;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .alumni-card-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(122, 63, 145, 0.15);
            border-color: #7a3f91;
        }

        input, select, textarea {
            border-color: rgba(122, 63, 145, 0.2) !important;
        }

        input:hover, select:hover, textarea:hover {
            border-color: rgba(122, 63, 145, 0.4) !important;
        }
    </style>

    <!-- MAIN CONTENT -->
    <div class="flex flex-col flex-1 min-h-0 px-8 pt-7 pb-4">

        <!-- HEADER -->
        <div class="mb-6 shrink-0" style="animation: slideInDown 0.5s ease-out;">
            <h1 class="text-4xl font-bold text-slate-800 flex items-center gap-3 mb-3">
                <div class="w-14 h-14 btn-primary rounded-lg flex items-center justify-center shadow-md">
                    <i class="fas fa-book text-xl"></i>
                </div>
                Alumni Yearbook
            </h1>
            <p class="text-slate-600 text-sm ml-0.5">
                A comprehensive digital collection of verified PhilCST graduates, showcasing their academic background, achievements, and professional journey.
            </p>
        </div>

        <!-- FILTER BAR -->
        <div class="bg-white rounded-lg shadow-sm p-6 mb-6 shrink-0">
            <div class="flex flex-wrap gap-4 items-end">
                <div class="flex-1 min-w-[250px]">
                    <label class="block text-sm font-semibold text-slate-800 mb-2">Search</label>
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
                        <input wire:model.live="search" type="text" placeholder="Search by name, ID, or email…"
                               class="w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
                    </div>
                </div>

                <div class="min-w-[180px]">
                    <label class="block text-sm font-semibold text-slate-800 mb-2">All Batches</label>
                    <select wire:model.live="batch"
                            class="w-full px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
                        <option value="">All Batches</option>
                        @forelse($this->batches as $b)
                            <option value="{{ $b }}">{{ $b }}</option>
                        @empty
                            <option disabled>No batches available</option>
                        @endforelse
                    </select>
                </div>

                <div class="min-w-[180px]">
                    <label class="block text-sm font-semibold text-slate-800 mb-2">All Courses</label>
                    <select wire:model.live="course"
                            class="w-full px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
                        <option value="">All Courses</option>
                        @forelse($this->courses as $c)
                            <option value="{{ $c->code }}">{{ $c->code }}</option>
                        @empty
                            <option disabled>No courses available</option>
                        @endforelse
                    </select>
                </div>

                <button wire:click="resetFilters"
                        class="px-4 py-2.5 text-slate-700 hover:bg-slate-100 rounded-lg border border-slate-200 transition text-sm font-medium">
                    <i class="fas fa-rotate-left mr-2"></i>Reset
                </button>
            </div>
        </div>

        <!-- ALUMNI GRID -->
        <div class="flex-1 min-h-0 overflow-y-auto scrollbar-custom">
            @if($this->alumniRecords->count() > 0)
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6 pb-6">
                    @foreach($this->alumniRecords as $alumni)
                    <div class="alumni-card">
                        <!-- Pure Purple Header -->
                        <div class="alumni-card-header"></div>

                        <!-- Profile Photo - Picture Only (Auto Default.PNG) -->
                        <div class="alumni-card-photo-container">
                            <img src="{{ $this->getPhotoUrl($alumni->profile_photo) }}" 
                                 alt="{{ $alumni->name }}"
                                 class="alumni-card-photo"
                                 onerror="this.src='{{ asset('storage/alumni-photos/default.png') }}'">
                        </div>
                        
                        <!-- Body Content - CENTERED -->
                        <div class="alumni-card-body">
                            <div>
                                <p class="alumni-card-name">{{ $alumni->name }}</p>
                                
                                <div class="flex flex-wrap justify-center gap-1">
                                    <span class="alumni-card-badge"><i class="fas fa-graduation-cap mr-1"></i>Class of {{ $alumni->batch }}</span>
                                </div>

                                <div class="alumni-card-info">
                                    <p>{{ $alumni->course_name }}</p>
                                </div>

                                <div class="alumni-card-quote">
                                    "Coming soon..."
                                </div>
                            </div>

                            <button class="alumni-card-button">
                                <i class="fas fa-eye"></i> View Alumni Profile
                            </button>
                        </div>
                    </div>
                    @endforeach
                </div>
            @else
                <div class="flex flex-col items-center justify-center h-full py-16">
                    <i class="fas fa-book text-6xl text-slate-200 mb-4"></i>
                    <p class="font-semibold text-slate-400 text-lg">No alumni found</p>
                    <p class="text-sm text-slate-400 mt-1">Try adjusting your filters</p>
                </div>
            @endif
        </div>

        <!-- PAGINATION - TALLER SPACING & BETTER VISIBILITY -->
        <div class="bg-white rounded-lg shadow-sm p-8 mt-2 shrink-0">
            <div class="flex items-center justify-between">
                <p class="text-slate-600 text-sm">
                    @php 
                        $total = $this->alumniRecords->total(); 
                        $pp = $this->alumniRecords->perPage(); 
                        $cp = $this->alumniRecords->currentPage(); 
                        $from = $total > 0 ? ($cp - 1) * $pp + 1 : 0; 
                        $to = min($cp * $pp, $total); 
                    @endphp
                    Showing <span class="font-semibold text-slate-800">{{ $from }}–{{ $to }}</span> of <span class="font-semibold text-slate-800">{{ $total }}</span>
                </p>
                <div class="flex gap-2 items-center">
                    @if($this->alumniRecords->onFirstPage())
                        <button disabled class="px-6 py-3 bg-slate-200 text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">← Prev</button>
                    @else
                        <button wire:click="previousPage" class="px-6 py-3 btn-primary rounded-lg text-sm font-medium">← Prev</button>
                    @endif
                    <span class="px-6 py-3 text-slate-700 text-sm font-semibold">{{ $this->alumniRecords->currentPage() }} / {{ $this->alumniRecords->lastPage() }}</span>
                    @if($this->alumniRecords->hasMorePages())
                        <button wire:click="nextPage" class="px-6 py-3 btn-primary rounded-lg text-sm font-medium">Next →</button>
                    @else
                        <button disabled class="px-6 py-3 bg-slate-200 text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">Next →</button>
                    @endif
                </div>
            </div>
        </div>

    </div>
</div>