<?php
use Livewire\Volt\Component;

new class extends Component {
    public $organizer;

    public function mount()
    {
        $this->organizer = auth()->user()->organizer;
    }
}; ?>

<div>
    @if (!$organizer)
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
            <i class="fa-solid fa-circle-exclamation mr-2"></i>
            Organizer record not found. Please contact the administrator.
        </div>
    @else

        {{-- Page heading --}}
        <div class="mb-8">
            <h1 class="text-3xl font-black text-[#2b0d3e]">Dashboard</h1>
            <p class="text-gray-500 mt-1">Welcome back, <span class="font-semibold text-[#7a3f91]">{{ $organizer->name }}</span>!</p>
        </div>



    @endif
</div>