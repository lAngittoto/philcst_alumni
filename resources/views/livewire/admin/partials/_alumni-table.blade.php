{{-- =====================================================
     ALUMNI TAB CONTENT
     ===================================================== --}}
<div class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex flex-wrap gap-3 items-center shrink-0">

    {{-- SEARCH — Alpine-controlled to prevent focus loss on re-render --}}
    <div class="relative flex-1 min-w-[200px] max-w-sm"
         x-data="{
             query: @entangle('alumniSearch').live,
             timer: null,
             onInput(e) {
                 clearTimeout(this.timer);
                 this.timer = setTimeout(() => { this.query = e.target.value; }, 300);
             }
         }"
         wire:ignore>
        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm pointer-events-none"></i>
        <input type="text"
               :value="query"
               @input="onInput($event)"
               placeholder="Search name, ID, email…"
               class="w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus"
               autocomplete="off" spellcheck="false">
    </div>

    <select wire:model.live="alumniBatch" class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
        <option value="">All Years</option>
        @foreach($this->batches as $b)<option value="{{ $b }}">{{ $b }}</option>@endforeach
    </select>
    <select wire:model.live="alumniCourse" class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
        <option value="">All Courses</option>
        @foreach($this->courses as $c)<option value="{{ $c->code }}">{{ $c->code }}</option>@endforeach
    </select>
    <select wire:model.live="alumniSort" class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
        <option value="recent">Recent First</option>
        <option value="oldest">Oldest First</option>
    </select>

    <button wire:click="resetAlumniFilters"
            class="px-4 py-2.5 text-slate-700 hover:bg-slate-100 rounded-lg border border-slate-200 transition text-sm font-medium">
        <i class="fas fa-rotate-left mr-2"></i>Reset
    </button>
</div>

{{-- Table wrapper --}}
<div class="relative flex-1 min-h-0"
     x-data="{ showScrollTop: false }">
    <div id="alumni-table-scroll"
         @scroll.passive="showScrollTop = $event.target.scrollTop > 200"
         class="h-full overflow-y-auto overflow-x-auto scrollbar-custom tbl-container"
         wire:loading.class="tbl-loading"
         wire:target="alumniSearch,alumniBatch,alumniCourse,alumniSort,resetAlumniFilters">
        <table class="w-full border-separate border-spacing-0">
            <thead class="btn-primary text-white" style="position:sticky;top:0;z-index:10;">
                <tr>
                    <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Name</th>
                    <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Student ID</th>
                    <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Course</th>
                    <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wide">Year</th>
                    <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Email</th>
                    <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wide">Status</th>
                    <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wide">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($this->alumniRecords as $item)
                <tr class="table-row-hover">
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            <img src="{{ $this->getPhotoUrl($item->profile_photo) }}" alt="{{ $item->name }}"
                                 class="w-10 h-10 rounded-lg object-cover shrink-0">
                            <span class="font-semibold text-slate-900 text-sm">{{ $item->name }}</span>
                        </div>
                    </td>
                    <td class="px-6 py-4"><span class="font-mono text-slate-800 text-sm font-semibold">{{ $item->student_id }}</span></td>
                    <td class="px-6 py-4">
                        <span class="inline-block px-3 py-1.5 bg-purple-50 text-purple-700 rounded-full text-xs font-semibold">{{ $item->course_code }}</span>
                    </td>
                    <td class="px-6 py-4 text-center"><span class="font-mono text-slate-800 text-sm font-semibold">{{ $item->batch }}</span></td>
                    <td class="px-6 py-4"><span class="text-slate-700 text-sm">{{ $item->email }}</span></td>
                    <td class="px-6 py-4 text-center">
                        @php $sc=match($item->status){'VERIFIED'=>'bg-emerald-100 text-emerald-700','PENDING'=>'bg-amber-100 text-amber-700','REJECTED'=>'bg-red-100 text-red-700',default=>'bg-slate-100 text-slate-600'}; @endphp
                        <span class="inline-block px-3 py-1.5 rounded-full text-xs font-semibold {{ $sc }}">{{ $item->status }}</span>
                    </td>
                    <td class="px-6 py-4 text-center">
                        <button wire:click="viewProfile({{ $item->id }},'alumni')"
                                class="inline-flex items-center gap-2 px-3 py-2 text-xs font-semibold text-purple-700 hover:bg-purple-50 rounded-lg transition border border-purple-200">
                            <i class="fas fa-eye"></i> View
                        </button>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="py-16 text-center">
                        <i class="fas fa-users text-5xl text-slate-200 block mb-4"></i>
                        <p class="font-semibold text-slate-400">No alumni found</p>
                        <p class="text-sm text-slate-400 mt-1">Try adjusting filters</p>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <button x-show="showScrollTop"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-75"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-75"
            @click="document.getElementById('alumni-table-scroll').scrollTo({ top: 0, behavior: 'smooth' })"
            class="absolute bottom-4 right-4 z-20 w-10 h-10 btn-primary rounded-full shadow-lg flex items-center justify-center hover:shadow-xl transition-shadow"
            style="display:none"
            title="Back to top">
        <i class="fas fa-arrow-up text-sm"></i>
    </button>
</div>

{{-- Pagination --}}
<div class="px-6 py-4 border-t border-slate-200 bg-slate-50 shrink-0">
    <div class="flex items-center justify-between">
        @php
            $total=$this->alumniRecords->total();$pp=$this->alumniRecords->perPage();
            $cp=$this->alumniRecords->currentPage();$from=$total>0?($cp-1)*$pp+1:0;$to=min($cp*$pp,$total);
        @endphp
        <p class="text-slate-600 text-sm">Showing <span class="font-semibold text-slate-800">{{ $from }}–{{ $to }}</span> of <span class="font-semibold text-slate-800">{{ $total }}</span></p>
        <div class="flex gap-2 items-center">
            @if($this->alumniRecords->onFirstPage())
                <button disabled class="px-4 py-2 bg-slate-200 text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">← Prev</button>
            @else
                <button wire:click="previousPage('alumniPage')" class="px-4 py-2 btn-primary rounded-lg text-sm font-medium">← Prev</button>
            @endif
            <span class="px-4 py-2 text-slate-700 text-sm font-medium">{{ $this->alumniRecords->currentPage() }} / {{ $this->alumniRecords->lastPage() }}</span>
            @if($this->alumniRecords->hasMorePages())
                <button wire:click="nextPage('alumniPage')" class="px-4 py-2 btn-primary rounded-lg text-sm font-medium">Next →</button>
            @else
                <button disabled class="px-4 py-2 bg-slate-200 text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">Next →</button>
            @endif
        </div>
    </div>
</div>