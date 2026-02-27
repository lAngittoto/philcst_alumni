<?php

use App\Http\Controllers\AlumniController;
use App\Http\Controllers\OrganizerController;
use App\Http\Controllers\CourseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// --- Public Routes ---
Route::get('/', fn() => view('home'));
Route::get('/about', fn() => view('about'));
Route::get('/events', fn() => view('events'));

// --- Auth Routes ---
Volt::route('/login', 'auth/login')->name('login');

// --- Protected Routes (auth + admin) ---
Route::middleware(['auth', 'admin'])->group(function () {
    
    // Admin Dashboard
    Route::view('/admin/dashboard', 'admin.admin-dashboard')->name('admin.dashboard');
    Route::view('/yearbook', 'admin.yearbook')->name('admin.yearbook');
    
    // Alumni Management - Using wrapper view (Livewire component inside)
    Route::get('/user/management', fn() => view('livewire.admin.alumni-management-wrapper'))
        ->name('user.management');
    
    // Import Routes (Traditional POST for file upload)
    Route::post('/alumni/import', [AlumniController::class, 'import'])
        ->name('alumni.import');
    Route::post('/organizers/import', [OrganizerController::class, 'import'])
        ->name('organizers.import');
    Route::get('/organizers/export', [OrganizerController::class, 'export'])
        ->name('organizers.export');
    
    // Course API Routes (JSON - for Livewire)
    Route::get('/courses', [CourseController::class, 'index'])
        ->name('courses.index');
    Route::post('/courses', [CourseController::class, 'store'])
        ->name('courses.store');
    Route::put('/courses/{course}', [CourseController::class, 'update'])
        ->name('courses.update');
    Route::delete('/courses/{course}', [CourseController::class, 'destroy'])
        ->name('courses.destroy');
});

// --- Logout ---
Route::post('/logout', function () {
    Auth::logout();
    session()->invalidate();
    session()->regenerateToken();
    return redirect()->route('login');
})->name('logout')->middleware('auth');