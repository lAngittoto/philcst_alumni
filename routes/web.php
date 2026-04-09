<?php

use App\Http\Controllers\AuditLogsController;
use App\Http\Controllers\AlumniController;
use App\Http\Controllers\AlumniPasswordChangeController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\OrganizerController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// ===================================
// Public Routes
// ===================================
Route::get('/', fn() => view('home'));
Route::get('/about', fn() => view('about'));
Route::get('/showevents', fn() => view('showevents'));

// ===================================
// Auth Routes
// ===================================
Volt::route('/login', 'auth/login')->name('login');

// ===================================
// Organizer — Password Change Wizard
// ===================================
Route::middleware(['auth', 'organizer.password.ensure'])->group(function () {

    Volt::route('/organizer/change-password', 'organizer/change-password')
        ->name('organizer.change-password');
});

// ===================================
// Organizer — Main Portal
// ===================================
Route::middleware(['auth', 'organizer.password.ensure'])->group(function () {

    Route::view('/organizer/dashboard', 'organizer.dashboard-wrapper')->name('organizer.dashboard');
    Route::view('/organizer/event/organizer', 'organizer.event-organizer-wrapper')->name('organizer.event/organizer');
    Route::view('/organizer/job/management',  'organizer.job-management-wrapper') ->name('organizer.job/management');
    Route::view('/organizer/employment',      'organizer.employment')             ->name('organizer.employment');
    Route::view('/organizer/reports',         'organizer.reports')                ->name('organizer.reports');
});

// ===================================
// Alumni — Password Change Wizard
// Only accessible right after first login (session flag required)
// Step 1 (GET)  → Volt handles the view
// Steps 2–4 (POST) → AlumniPasswordChangeController handles logic
// ===================================
Route::middleware(['auth', 'alumni.password.ensure'])->group(function () {

    // ── View (rendered by Volt) ───────────────────────────
    Volt::route('/alumni/change-password', 'alumni/change-password')
        ->name('alumni.change-password');

    // ── Wizard POST actions (AlumniPasswordChangeController) ──
   
    Route::post('/alumni/send-otp',         [AlumniPasswordChangeController::class, 'sendOtp'])        ->name('alumni.send-otp');
    Route::post('/alumni/resend-otp',       [AlumniPasswordChangeController::class, 'resendOtp'])      ->name('alumni.resend-otp');
    Route::post('/alumni/verify-otp',       [AlumniPasswordChangeController::class, 'verifyOtp'])      ->name('alumni.verify-otp');
    Route::post('/alumni/confirm-password', [AlumniPasswordChangeController::class, 'confirmPassword'])->name('alumni.confirm-password');
});

// ===================================
// Alumni — Main Portal
// Only reachable after account setup is complete
// ===================================
Route::middleware(['auth', 'alumni.password.ensure'])->group(function () {
  Route::view('/alumni/dashboard', 'alumni.dashboard-wrapper')->name('alumni.dashboard');
});

// ===================================
// Admin Routes
// ===================================
Route::middleware(['auth', 'admin'])->group(function () {

    Route::view('/admin/dashboard', 'admin.admin-dashboard-wrapper')->name('admin.dashboard');

    Route::get('/audit/logs', fn() => view('admin.audit-logs-wrapper'))->name('audit.logs');
    Route::get('/admin/audit-logs/export', [AuditLogsController::class, 'export'])->name('admin.audit-logs.export');
    Route::get('/admin/audit-logs/stats',  [AuditLogsController::class, 'stats']) ->name('admin.audit-logs.stats');
    Route::get('/admin/audit-logs/{log}',  [AuditLogsController::class, 'show'])  ->name('admin.audit-logs.show');

    Route::get('/user/management', fn() => view('admin.alumni-management-wrapper'))->name('user.management');
    Route::get('/yearbook',        fn() => view('admin.yearbook-wrapper'))          ->name('admin.yearbook');
    Route::get('/job/posts',       fn() => view('admin.job-posts-wrapper'))         ->name('job.posts');
    Route::get('/events',          fn() => view('admin.events-wrapper'))            ->name('events');

    Route::post('/alumni/import', [AlumniController::class, 'import'])->name('alumni.import');

    Route::get('/courses',             [CourseController::class, 'index'])  ->name('courses.index');
    Route::post('/courses',            [CourseController::class, 'store'])  ->name('courses.store');
    Route::put('/courses/{course}',    [CourseController::class, 'update']) ->name('courses.update');
    Route::delete('/courses/{course}', [CourseController::class, 'destroy'])->name('courses.destroy');

    Route::post('/organizer',                     [OrganizerController::class, 'store'])       ->name('organizer.store');
    Route::get('/organizer/{organizer}',          [OrganizerController::class, 'show'])        ->name('organizer.show');
    Route::put('/organizer/{organizer}',          [OrganizerController::class, 'update'])      ->name('organizer.update');
    Route::delete('/organizer/{organizer}',       [OrganizerController::class, 'destroy'])     ->name('organizer.destroy');
    Route::patch('/organizer/{organizer}/status', [OrganizerController::class, 'updateStatus'])->name('organizer.status');
});

// ===================================
// Logout
// ===================================
Route::post('/logout', function () {
    Auth::logout();
    session()->invalidate();
    session()->regenerateToken();
    return redirect()->route('login');
})->name('logout')->middleware('auth');