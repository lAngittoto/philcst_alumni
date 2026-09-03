<?php

use App\Http\Controllers\AdminEmploymentTrackingExportController;
use App\Http\Controllers\AdminNotificationController;
use App\Http\Controllers\AlumniController;
use App\Http\Controllers\AlumniInformationController;
use App\Http\Controllers\AlumniNotificationController;
use App\Http\Controllers\AlumniPasswordChangeController;
use App\Http\Controllers\CoordinatorNotificationController;
use App\Http\Controllers\CourseController;
use App\Http\Controllers\DirectorNotificationController;
use App\Http\Controllers\EmploymentTrackingExportController;
use App\Http\Controllers\MessengerController;
use App\Http\Controllers\OrganizerAlumniExportController;
use App\Http\Controllers\OrganizerController;
use App\Http\Controllers\RegistrarAlumniExportController;
use App\Http\Controllers\RegistrarNotificationController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

// ===================================
// Public Routes
// ===================================
Route::get('/', fn() => view('home'));
Route::get('/about', fn() => view('about'));
Route::get('/latest-events', fn() => view('showevents'));

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
// Alumni — Forgot Password (PUBLIC — no auth required)
// ===================================
Volt::route('/alumni/forgot-password', 'alumni/forgot-password')
    ->name('alumni.forgot-password');

// ===================================
// Organizer — Password Change Wizard
// ===================================
Route::middleware(['auth', 'organizer.password.ensure'])->group(function () {
    Volt::route('/coordinator/change-password', 'organizer/change-password')
        ->name('organizer.change-password');
});

// ===================================
// Organizer — Main Portal
// ===================================
Route::middleware(['auth', 'organizer.password.ensure'])->group(function () {
    Route::view('/coordinator/dashboard',        'organizer.dashboard-wrapper')        ->name('organizer.dashboard');
    Route::view('/coordinator/event/management', 'organizer.event-organizer-wrapper')  ->name('organizer.event/organizer');
    Route::view('/coordinator/job/management',   'organizer.job-management-wrapper')   ->name('organizer.job/management');
    Route::view('/coordinator/alumni/employment','organizer.alumni-employment-wrapper') ->name('organizer.alumni/employment');
    Route::view('/coordinator/reports',          'organizer.reports')                   ->name('organizer.reports');
    Route::view('/coordinator/message/hub',      'organizer.chat-alumni-wrapper')       ->name('organizer.chat/alumni');
    Route::view('/coordinator/yearbook',         'organizer.yearbook-wrapper')          ->name('organizer.yearbook');

    // ── Alumni Employment: Generate Reports (PDF / Excel / Print export) ───
    Route::get('/coordinator/alumni/employment/export', [OrganizerAlumniExportController::class, 'export'])
        ->name('organizer.alumni-employment.export');

    // ── Coordinator Notification API ──────────────────────────────────────
    Route::get('/coordinator/notifications',                [CoordinatorNotificationController::class, 'index']);
    Route::post('/coordinator/notifications',               [CoordinatorNotificationController::class, 'store']);
    Route::patch('/coordinator/notifications/read-all',     [CoordinatorNotificationController::class, 'markAllRead']);
    Route::patch('/coordinator/notifications/{n}/read',     [CoordinatorNotificationController::class, 'markRead']);
    Route::delete('/coordinator/notifications/{n}',         [CoordinatorNotificationController::class, 'destroy']);
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

    Route::get('/alumni/notifications',              [AlumniNotificationController::class, 'index']);
    Route::post('/alumni/notifications',             [AlumniNotificationController::class, 'store']);
    Route::patch('/alumni/notifications/read-all',   [AlumniNotificationController::class, 'markAllRead']);
    Route::patch('/alumni/notifications/{n}/read',   [AlumniNotificationController::class, 'markRead']);
    Route::delete('/alumni/notifications/{n}',       [AlumniNotificationController::class, 'destroy']);
});

// ===================================
// Registrar Routes
// ===================================
Route::middleware(['auth', 'registrar'])->prefix('registrar')->name('registrar.')->group(function () {

    Route::view('/dashboard',           'registrar.dashboard-wrapper')           ->name('dashboard');
    Route::view('/alumni',              'registrar.alumni-wrapper')             ->name('alumni');
    Route::view('/alumni/register',     'registrar.register-wrapper')           ->name('alumni.register');
    Route::view('/employment/tracking', 'registrar.employment-tracking-wrapper')->name('employment.tracking');

    // ── Alumni Records: Generate Reports (PDF / Excel export) ──────────────
    Route::get('/alumni-records/export', [RegistrarAlumniExportController::class, 'export'])
        ->name('alumni-records.export');

    // ── Employment Tracking: Generate Reports (PDF / Excel / Print export) ─
    Route::get('/employment-tracking/export', [EmploymentTrackingExportController::class, 'export'])
        ->name('employment-tracking.export');

    Route::get('/notifications',                      [RegistrarNotificationController::class, 'index'])      ->name('notifications.index');
    Route::post('/notifications',                     [RegistrarNotificationController::class, 'store'])      ->name('notifications.store');
    Route::patch('/notifications/read-all',           [RegistrarNotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::patch('/notifications/{notification}/read',[RegistrarNotificationController::class, 'markRead'])   ->name('notifications.read');
    Route::delete('/notifications/{notification}',    [RegistrarNotificationController::class, 'destroy'])    ->name('notifications.destroy');
});

// ===================================
// Director — Password Change Wizard
// ===================================
Route::middleware(['auth', 'director.password.ensure'])->group(function () {
    Volt::route('/director/change-password', 'director/change-password')
        ->name('director.change-password');
});

// ===================================
// Director Routes
// ===================================
Route::middleware(['auth', 'director', 'director.password.ensure'])->prefix('director')->name('director.')->group(function () {
    Route::view('/dashboard',              'director.dashboard-wrapper')          ->name('dashboard');
    Route::view('/coordinator/management', 'director.manage-coordinator-wrapper')->name('coordinator/management');
    Route::view('/event/management',       'director.manage-event-wrapper')       ->name('event/management');
    Route::view('/job/management',         'director.manage-job-wrapper')         ->name('job/management');
    Route::view('/messenger',              'director.director-messenger-wrapper') ->name('director/messenger');
    Route::view('/manage/employment',      'director.manage-employment-wrapper')  ->name('manage/employment');

    // ── Director Notification API ─────────────────────────────────────────
    Route::get('/notifications',                         [DirectorNotificationController::class, 'index'])      ->name('notifications.index');
    Route::post('/notifications',                        [DirectorNotificationController::class, 'store'])      ->name('notifications.store');
    Route::patch('/notifications/read-all',              [DirectorNotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::patch('/notifications/{notification}/read',   [DirectorNotificationController::class, 'markRead'])   ->name('notifications.read');
    Route::delete('/notifications/{notification}',       [DirectorNotificationController::class, 'destroy'])    ->name('notifications.destroy');
});

// ===================================
// Admin Routes
// ===================================
Route::middleware(['auth', 'admin'])->group(function () {

    Route::view('/admin/dashboard', 'admin.admin-dashboard-wrapper')->name('admin.dashboard');

    Route::get('/user/management',     fn() => view('admin.alumni-management-wrapper'))   ->name('user.management');
    Route::get('/employment/tracking', fn() => view('admin.employment-tracking-wrapper')) ->name('employment.tracking');

    // ── Employment Tracking: Generate Reports (PDF / Excel / Print export) ─
    Route::get('/employment/tracking/export', [AdminEmploymentTrackingExportController::class, 'export'])
        ->name('employment.tracking.export');

    Route::get('/yearbook',            fn() => view('admin.yearbook-wrapper'))             ->name('admin.yearbook');
    Route::get('/job/posts',           fn() => view('admin.job-posts-wrapper'))            ->name('job.posts');
    Route::get('/events',              fn() => view('admin.events-wrapper'))               ->name('events');

    Route::post('/alumni/import', [AlumniController::class, 'import'])->name('alumni.import');

    Route::view('/course', 'admin.course-wrapper')->name('course');

    Route::get('/courses',             [CourseController::class, 'index'])  ->name('courses.index');
    Route::post('/courses',            [CourseController::class, 'store'])  ->name('courses.store');
    Route::put('/courses/{course}',    [CourseController::class, 'update']) ->name('courses.update');
    Route::delete('/courses/{course}', [CourseController::class, 'destroy'])->name('courses.destroy');

    Route::post('/organizer',                     [OrganizerController::class, 'store'])        ->name('organizer.store');
    Route::get('/organizer/{organizer}',          [OrganizerController::class, 'show'])         ->name('organizer.show');
    Route::put('/organizer/{organizer}',          [OrganizerController::class, 'update'])       ->name('organizer.update');
    Route::delete('/organizer/{organizer}',       [OrganizerController::class, 'destroy'])      ->name('organizer.destroy');
    Route::patch('/organizer/{organizer}/status', [OrganizerController::class, 'updateStatus']) ->name('organizer.status');

    // ── Admin Notification API ──────────────────────────────────────────────
    Route::get('/admin/notifications',                       [AdminNotificationController::class, 'index'])      ->name('admin.notifications.index');
    Route::post('/admin/notifications',                      [AdminNotificationController::class, 'store'])      ->name('admin.notifications.store');
    Route::patch('/admin/notifications/read-all',            [AdminNotificationController::class, 'markAllRead'])->name('admin.notifications.read-all');
    Route::patch('/admin/notifications/{notification}/read', [AdminNotificationController::class, 'markRead'])   ->name('admin.notifications.read');
    Route::delete('/admin/notifications/{notification}',     [AdminNotificationController::class, 'destroy'])    ->name('admin.notifications.destroy');
});

// ===================================
// Logout
// ===================================
// Accepts both GET and POST. GET is what the admin sidebar's
// wire:navigate link uses (see sidebar-admin_blade.php) — no CSRF
// token involved, so nothing can go stale after SPA hops or session
// expiry, which is what was causing an immediate 419 Page Expired
// there before. POST is kept too because other portal sidebars
// (registrar / organizer / director) still submit the old CSRF form —
// dropping POST entirely broke those with a 405 Method Not Allowed.
// Once every sidebar is migrated to the GET wire:navigate link, POST
// can be removed here.
Route::match(['get', 'post'], '/logout', function () {
    Auth::logout();
    session()->invalidate();
    session()->regenerateToken();

    // 303 See Other tells the browser "fetch the redirect target with GET,
    // don't treat it as a resubmittable POST". This stops the browser from
    // offering to resubmit the /logout request when the user hits Back
    // later, which is what caused the native "This page has expired /
    // Confirm Form Resubmission" prompt right after logging out.
    return redirect()->route('login')
        ->setStatusCode(303)
        ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
        ->header('Pragma', 'no-cache')
        ->header('Expires', '0');
})->name('logout')->middleware('auth');