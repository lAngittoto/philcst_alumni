<?php

use App\Http\Controllers\AlumniController;
use App\Http\Controllers\CourseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// --- Public Routes ---
Route::get('/', fn() => view('home'));
Route::get('/about', fn() => view('about'));
Route::get('/events', fn() => view('events'));

// --- Auth ---
Volt::route('/login', 'auth/login')->name('login');

// --- Protected Routes (auth + admin) ---
Route::middleware(['auth', 'admin'])->group(function () {

    // Dashboard
    Route::view('/admin/dashboard', 'admin.admin-dashboard')->name('admin.dashboard');

    // Alumni Management
    Route::get('/alumni/management',          [AlumniController::class, 'index'])->name('alumni.management');
    Route::post('/alumni/management',         [AlumniController::class, 'store'])->name('alumni.store');
    Route::delete('/alumni/management/{alumni}', [AlumniController::class, 'destroy'])->name('alumni.destroy');
    Route::post('/alumni/management/import',  [AlumniController::class, 'import'])->name('alumni.import');

    // Courses API (JSON) — inside auth middleware
    Route::prefix('courses')->name('courses.')->group(function () {
        Route::get('/',            [CourseController::class, 'index'])->name('index');
        Route::post('/',           [CourseController::class, 'store'])->name('store');
        Route::put('/{course}',    [CourseController::class, 'update'])->name('update');
        Route::delete('/{course}', [CourseController::class, 'destroy'])->name('destroy');
    });

});

// --- Logout ---
Route::post('/logout', function () {
    Auth::logout();
    session()->invalidate();
    session()->regenerateToken();
    return redirect()->route('login');
})->name('logout')->middleware('auth');