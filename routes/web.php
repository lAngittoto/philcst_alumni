<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('home');
});

Route::get('/about', function () {
    return view('about');
});

Route::get('/events', function () {
    return view('events');
});

// ✅ TANGGALIN ang Route::get('/login') — Volt na ang bahala
// routes/web.php

Volt::route('/login', 'auth/login')->name('login');

// ✅ Admin routes — protektado ng auth + admin
Route::middleware(['auth', 'admin'])->group(function () {
    Route::view('/admin/dashboard', 'admin.admin-dashboard')->name('admin.dashboard');
});

Route::middleware(['auth', 'admin'])->group(function () {
    Route::view('/alumni/management', 'admin.alumni-management')->name('alumni.management');
});

// ✅ Logout
Route::post('/logout', function () {
    Auth::logout();
    session()->invalidate();
    session()->regenerateToken();
    return redirect()->route('login');
})->name('logout')->middleware('auth');