<?php

use App\Http\Controllers\AuditLogsController;
use App\Http\Controllers\AlumniController;
use App\Http\Controllers\AlumniPasswordChangeController;
use App\Http\Controllers\AlumniInformationController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\MessengerController;
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
// Public Job Detail Route (used for sharing)
// ===================================
Route::get('/jobs/{id}', function ($id) {
    return view('alumni.job-opportunities-wrapper', ['highlightJobId' => (int) $id]);
})->name('jobs.show')->where('id', '[0-9]+');

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
    Route::view('/organizer/dashboard',        'organizer.dashboard-wrapper')        ->name('organizer.dashboard');
    Route::view('/organizer/event/organizer',  'organizer.event-organizer-wrapper')  ->name('organizer.event/organizer');
    Route::view('/organizer/job/management',   'organizer.job-management-wrapper')   ->name('organizer.job/management');
    Route::view('/organizer/alumni/employment','organizer.alumni-employment-wrapper') ->name('organizer.alumni/employment');
    Route::view('/organizer/reports',          'organizer.reports')                   ->name('organizer.reports');
    Route::view('/organizer/chat/alumni',      'organizer.chat-alumni-wrapper')       ->name('organizer.chat/alumni');
});

// ===================================
// Alumni — All Routes
// ===================================
Route::middleware(['auth', 'alumni.onboarded'])->group(function () {

    Volt::route('/alumni/change-password', 'alumni/change-password')
        ->name('alumni.change-password');

    Route::post('/alumni/send-otp',         [AlumniPasswordChangeController::class, 'sendOtp'])        ->name('alumni.send-otp');
    Route::post('/alumni/resend-otp',       [AlumniPasswordChangeController::class, 'resendOtp'])      ->name('alumni.resend-otp');
    Route::post('/alumni/verify-otp',       [AlumniPasswordChangeController::class, 'verifyOtp'])      ->name('alumni.verify-otp');
    Route::post('/alumni/confirm-password', [AlumniPasswordChangeController::class, 'confirmPassword'])->name('alumni.confirm-password');

    Route::get('/alumni/information', [AlumniInformationController::class, 'show'])  ->name('alumni.information');
    Route::put('/alumni/information', [AlumniInformationController::class, 'update'])->name('alumni.information.update');

    Route::view('/alumni/yearbook',  'alumni.yearbook-wrapper')         ->name('alumni.yearbook');

    Route::view('/alumni/dashboard',  'alumni.dashboard-wrapper')         ->name('alumni.dashboard');
    Route::view('/alumni/employment', 'alumni.employment-wrapper')        ->name('alumni.employment');
    Route::view('/alumni/messenger',  'alumni.messenger-wrapper')         ->name('alumni.messenger');
    Route::view('/job/opportunities', 'alumni.job-opportunities-wrapper') ->name('job.opportunities');
    Route::view('/upcoming/events',   'alumni.upcoming-events-wrapper')   ->name('upcoming.events');

    Route::post('/messenger/ping',           [MessengerController::class, 'ping'])        ->name('messenger.ping');
    Route::get('/messenger/{roomId}/online', [MessengerController::class, 'onlineCount']) ->name('messenger.online');
});

// ===================================
// Registrar Routes
// ===================================
Route::middleware(['auth', 'registrar'])->prefix('registrar')->name('registrar.')->group(function () {

    Route::view('/dashboard',              'registrar.dashboard-wrapper')              ->name('dashboard');
    Route::view('/alumni',                 'registrar.alumni-wrapper')                 ->name('alumni');
    Route::view('/alumni/register',        'registrar.register-wrapper')               ->name('alumni.register');
    Route::view('/alumni/import',          'registrar.import-wrapper')                 ->name('alumni.import');
    Route::view('/information-management', 'registrar.information-management-wrapper') ->name('information-management');
    Route::view('/courses',                'registrar.courses-wrapper')                ->name('courses');
});

// ===================================
// Director Routes
// ===================================
Route::middleware(['auth', 'director'])->prefix('director')->name('director.')->group(function () {

    // ── Dashboard ─────────────────────────────────────────────────────────────
    Route::view('/dashboard',              'director.dashboard-wrapper')          ->name('dashboard');

    // ── Coordinator Management ────────────────────────────────────────────────
    Route::view('/coordinator/management', 'director.manage-coordinator-wrapper') ->name('coordinator/management');

    // ── Event Management ──────────────────────────────────────────────────────
    Route::view('/event/management',       'director.manage-event-wrapper')       ->name('event/management');

    // ── Job Management ────────────────────────────────────────────────────────
    Route::view('/job/management',         'director.manage-job-wrapper')         ->name('job/management');

    // ── Messenger ─────────────────────────────────────────────────────────────
    Route::view('/messenger', 'director.director-messenger-wrapper') ->name('director/messenger');

    // ── Employment Management ─────────────────────────────────────────────────
    Route::view('/manage/employment',      'director.manage-employment-wrapper')  ->name('manage/employment');
});

// ===================================
// Admin Routes
// ===================================
Route::middleware(['auth', 'admin'])->group(function () {

    Route::view('/admin/dashboard', 'admin.admin-dashboard-wrapper')->name('admin.dashboard');

    Route::get('/audit/logs',              fn() => view('admin.audit-logs-wrapper'))->name('audit.logs');
    Route::get('/admin/audit-logs/export', [AuditLogsController::class, 'export'])->name('admin.audit-logs.export');
    Route::get('/admin/audit-logs/stats',  [AuditLogsController::class, 'stats']) ->name('admin.audit-logs.stats');
    Route::get('/admin/audit-logs/{log}',  [AuditLogsController::class, 'show'])  ->name('admin.audit-logs.show');

    Route::get('/user/management',     fn() => view('admin.alumni-management-wrapper'))   ->name('user.management');
    Route::get('/employment/tracking', fn() => view('admin.employment-tracking-wrapper')) ->name('employment.tracking');
    Route::get('/yearbook',            fn() => view('admin.yearbook-wrapper'))             ->name('admin.yearbook');
    Route::get('/job/posts',           fn() => view('admin.job-posts-wrapper'))            ->name('job.posts');
    Route::get('/events',              fn() => view('admin.events-wrapper'))               ->name('events');

    Route::post('/alumni/import', [AlumniController::class, 'import'])->name('alumni.import');

    Route::get('/courses',             [CourseController::class, 'index'])  ->name('courses.index');
    Route::post('/courses',            [CourseController::class, 'store'])  ->name('courses.store');
    Route::put('/courses/{course}',    [CourseController::class, 'update']) ->name('courses.update');
    Route::delete('/courses/{course}', [CourseController::class, 'destroy'])->name('courses.destroy');

    Route::post('/organizer',                     [OrganizerController::class, 'store'])        ->name('organizer.store');
    Route::get('/organizer/{organizer}',          [OrganizerController::class, 'show'])         ->name('organizer.show');
    Route::put('/organizer/{organizer}',          [OrganizerController::class, 'update'])       ->name('organizer.update');
    Route::delete('/organizer/{organizer}',       [OrganizerController::class, 'destroy'])      ->name('organizer.destroy');
    Route::patch('/organizer/{organizer}/status', [OrganizerController::class, 'updateStatus']) ->name('organizer.status');
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