{{-- resources/views/admin-dashboard.blade.php --}}

<div class="min-h-screen bg-[#f2eaf7] p-8">
    <div class="max-w-4xl mx-auto">
        <div class="bg-white rounded-3xl shadow-xl p-8">
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-black text-[#2b0d3e] uppercase tracking-tight">
                        Admin Dashboard
                    </h1>
                    <p class="text-[#7a3f91] text-sm mt-1">
                        Welcome, <strong>{{ Auth::user()->name }}</strong>!
                    </p>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                            class="bg-[#2b0d3e] text-white px-6 py-2 rounded-xl font-bold text-sm hover:bg-[#3d1358] transition-all">
                        <i class="fa-solid fa-right-from-bracket mr-2"></i> Logout
                    </button>
                </form>
            </div>
            <div class="bg-[#f2eaf7] rounded-2xl p-6 text-center text-[#7a3f91] font-medium">
                🎉 You are now logged in as Admin!
            </div>
        </div>
    </div>
</div>
