{{-- =====================================================
     ORGANIZERS TAB CONTENT
     ===================================================== --}}
<div class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex flex-wrap gap-3 items-center shrink-0">

    {{-- SEARCH - plain wire:model, no wire:ignore, no Alpine searchBox --}}
    <div class="relative flex-1 min-w-[200px] max-w-sm">
        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-sm pointer-events-none"></i>
        <input type="text"
               wire:model.live.debounce.400ms="orgSearch"
               placeholder="Search name, ID, email…"
               class="w-full pl-10 pr-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus"
               autocomplete="off" spellcheck="false">
    </div>

    <select wire:model.live="orgCollege" class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
        <option value="">All Colleges</option>
        @foreach($this->orgColleges as $col)<option value="{{ $col }}">{{ $col }}</option>@endforeach
    </select>
    <select wire:model.live="orgSort" class="px-4 py-2.5 border border-slate-200 rounded-lg text-sm bg-white input-focus">
        <option value="recent">Recent First</option>
        <option value="oldest">Oldest First</option>
    </select>

    <button wire:click="resetOrgFilters"
            class="px-4 py-2.5 text-slate-700 hover:bg-slate-100 rounded-lg border border-slate-200 transition text-sm font-medium">
        <i class="fas fa-rotate-left mr-2"></i>Reset
    </button>
</div>

{{-- Table wrapper --}}
<div class="relative flex-1 min-h-0"
     x-data="{ showScrollTop: false }">
    <div id="org-table-scroll"
         x-ref="scrollArea"
         @scroll.passive="showScrollTop = $event.target.scrollTop > 200"
         class="h-full overflow-auto scrollbar-custom tbl-container"
         wire:loading.class="tbl-loading"
         wire:target="orgSearch,orgCollege,orgSort,resetOrgFilters,toggleOrganizerStatus">
        <table class="w-full">
            <thead class="btn-primary text-white sticky top-0 z-10">
                <tr>
                    <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Name</th>
                    <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Teacher ID</th>
                    <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">Email</th>
                    <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wide">College</th>
                    <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wide">Status</th>
                    <th class="px-6 py-4 text-center text-xs font-semibold uppercase tracking-wide">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($this->organizerRecords as $item)
                @php $collegeName=$this->getCollegeForCourse($item->department); @endphp
                <tr class="table-row-hover">
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            <img src="{{ $this->getPhotoUrl($item->profile_photo) }}" alt="{{ $item->name }}"
                                 class="w-10 h-10 rounded-lg object-cover shrink-0">
                            <span class="font-semibold text-slate-900 text-sm">{{ $item->name }}</span>
                        </div>
                    </td>
                    <td class="px-6 py-4"><span class="font-mono text-slate-800 text-sm font-semibold">{{ $item->id_number }}</span></td>
                    <td class="px-6 py-4"><span class="text-slate-700 text-sm">{{ $item->email }}</span></td>
                    <td class="px-6 py-4">
                        <span class="block font-semibold text-slate-800 text-sm leading-snug">{{ $collegeName }}</span>
                        <div class="flex flex-wrap gap-1 mt-1">
                            @foreach($this->getCollegeDepts($item->department) as $deptCode)
                                <span class="text-xs font-mono font-semibold text-purple-700">{{ $deptCode }}</span>
                                @if(!$loop->last)<span class="text-slate-300 text-xs">·</span>@endif
                            @endforeach
                        </div>
                    </td>
                    <td class="px-6 py-4 text-center">
                        @php $sc=match($item->status){'ACTIVE'=>'bg-emerald-100 text-emerald-700','INACTIVE'=>'bg-amber-100 text-amber-700','SUSPENDED'=>'bg-red-100 text-red-700',default=>'bg-slate-100 text-slate-600'}; @endphp
                        <span class="inline-block px-3 py-1.5 rounded-full text-xs font-semibold {{ $sc }}">{{ $item->status }}</span>
                    </td>
                    <td class="px-6 py-4 text-center">
                        <div class="flex items-center justify-center gap-2">
                            <button wire:click="viewProfile({{ $item->id }},'organizer')"
                                    class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold text-purple-700 hover:bg-purple-50 rounded-lg transition border border-purple-200">
                                <i class="fas fa-eye"></i> View
                            </button>

                            @if($item->status==='ACTIVE')
                                <div x-data="{ open: false }" @keydown.escape.window="open=false">
                                    <button @click="open=true"
                                            class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-50 rounded-lg transition border border-red-200">
                                        <i class="fas fa-ban"></i> Deactivate
                                    </button>
                                    <div x-show="open" x-transition
                                         class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4"
                                         style="display:none">
                                        <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm p-6">
                                            <div class="flex items-center gap-3 mb-5">
                                                <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center shrink-0">
                                                    <i class="fas fa-ban text-red-600 text-lg"></i>
                                                </div>
                                                <div>
                                                    <p class="font-bold text-slate-800 text-lg">Deactivate Organizer?</p>
                                                    <p class="text-sm text-slate-500 mt-0.5">{{ $item->name }}</p>
                                                </div>
                                            </div>
                                            <p class="text-base text-slate-600 mb-6">This organizer will no longer be able to log in. You can reactivate them at any time.</p>
                                            <div class="flex gap-3">
                                                <button @click="open=false"
                                                        class="flex-1 px-4 py-3 border border-slate-300 text-slate-700 rounded-lg text-base font-bold hover:bg-slate-50 transition">
                                                    Cancel
                                                </button>
                                                <button @click="open=false; $wire.toggleOrganizerStatus({{ $item->id }})"
                                                        class="flex-1 px-4 py-3 bg-red-600 text-white rounded-lg text-base font-bold hover:bg-red-700 transition">
                                                    Yes, Deactivate
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @else
                                <div x-data="{ open: false }" @keydown.escape.window="open=false">
                                    <button @click="open=true"
                                            class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-semibold text-emerald-600 hover:bg-emerald-50 rounded-lg transition border border-emerald-200">
                                        <i class="fas fa-circle-check"></i> Activate
                                    </button>
                                    <div x-show="open" x-transition
                                         class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 backdrop-blur-sm p-4"
                                         style="display:none">
                                        <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm p-6">
                                            <div class="flex items-center gap-3 mb-5">
                                                <div class="w-12 h-12 bg-emerald-100 rounded-full flex items-center justify-center shrink-0">
                                                    <i class="fas fa-circle-check text-emerald-600 text-lg"></i>
                                                </div>
                                                <div>
                                                    <p class="font-bold text-slate-800 text-lg">Activate Organizer?</p>
                                                    <p class="text-sm text-slate-500 mt-0.5">{{ $item->name }}</p>
                                                </div>
                                            </div>
                                            <p class="text-base text-slate-600 mb-6">This organizer will be able to log in again.</p>
                                            <div class="flex gap-3">
                                                <button @click="open=false"
                                                        class="flex-1 px-4 py-3 border border-slate-300 text-slate-700 rounded-lg text-base font-bold hover:bg-slate-50 transition">
                                                    Cancel
                                                </button>
                                                <button @click="open=false; $wire.toggleOrganizerStatus({{ $item->id }})"
                                                        class="flex-1 px-4 py-3 bg-emerald-600 text-white rounded-lg text-base font-bold hover:bg-emerald-700 transition">
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
                <tr>
                    <td colspan="6" class="py-16 text-center">
                        <i class="fas fa-users-gear text-5xl text-slate-200 block mb-4"></i>
                        <p class="font-semibold text-slate-400">No organizers found</p>
                        <p class="text-sm text-slate-400 mt-1">Register an organizer to get started</p>
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
            @click="$refs.scrollArea.scrollTo({ top: 0, behavior: 'smooth' })"
            class="absolute bottom-4 right-4 z-20 w-10 h-10 btn-primary rounded-full shadow-lg
                   flex items-center justify-center hover:shadow-xl transition-shadow"
            style="display:none"
            title="Back to top">
        <i class="fas fa-arrow-up text-sm"></i>
    </button>
</div>

{{-- Pagination --}}
<div class="px-6 py-4 border-t border-slate-200 bg-slate-50 shrink-0">
    <div class="flex items-center justify-between">
        @php
            $total=$this->organizerRecords->total();$pp=$this->organizerRecords->perPage();
            $cp=$this->organizerRecords->currentPage();$from=$total>0?($cp-1)*$pp+1:0;$to=min($cp*$pp,$total);
        @endphp
        <p class="text-slate-600 text-sm">Showing <span class="font-semibold text-slate-800">{{ $from }}–{{ $to }}</span> of <span class="font-semibold text-slate-800">{{ $total }}</span></p>
        <div class="flex gap-2 items-center">
            @if($this->organizerRecords->onFirstPage())
                <button disabled class="px-4 py-2 bg-slate-200 text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">← Prev</button>
            @else
                <button wire:click="previousPage('orgPage')" class="px-4 py-2 btn-primary rounded-lg text-sm font-medium">← Prev</button>
            @endif
            <span class="px-4 py-2 text-slate-700 text-sm font-medium">{{ $this->organizerRecords->currentPage() }} / {{ $this->organizerRecords->lastPage() }}</span>
            @if($this->organizerRecords->hasMorePages())
                <button wire:click="nextPage('orgPage')" class="px-4 py-2 btn-primary rounded-lg text-sm font-medium">Next →</button>
            @else
                <button disabled class="px-4 py-2 bg-slate-200 text-slate-500 rounded-lg text-sm font-medium cursor-not-allowed">Next →</button>
            @endif
        </div>
    </div>
</div>