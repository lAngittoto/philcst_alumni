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

// --- Auth ---
Volt::route('/login', 'auth/login')->name('login');

// --- Protected Routes (auth + admin) ---
Route::middleware(['auth', 'admin'])->group(function () {
    // Dashboard
    Route::view('/admin/dashboard', 'admin.admin-dashboard')->name('admin.dashboard');
    Route::view('/yearbook', 'admin.yearbook')->name('admin.yearbook');

    // Alumni Management
    Route::get('/alumni/management',              [AlumniController::class, 'index'])->name('alumni.management');
    Route::post('/alumni',                        [AlumniController::class, 'store'])->name('alumni.store');
    Route::get('/alumni/{alumni}/edit',           [AlumniController::class, 'edit'])->name('alumni.edit');
    Route::put('/alumni/{alumni}',                [AlumniController::class, 'update'])->name('alumni.update');
    Route::delete('/alumni/{alumni}',             [AlumniController::class, 'destroy'])->name('alumni.destroy');
    Route::post('/alumni/import',                 [AlumniController::class, 'import'])->name('alumni.import');
    Route::post('/alumni/check-duplicate',        [AlumniController::class, 'checkDuplicate'])->name('alumni.checkDuplicate');
    
    // Organizer Management
    Route::post('/organizers',                    [OrganizerController::class, 'store'])->name('organizers.store');
    Route::get('/organizers/{organizer}/edit',    [OrganizerController::class, 'edit'])->name('organizers.edit');
    Route::put('/organizers/{organizer}',         [OrganizerController::class, 'update'])->name('organizers.update');
    Route::delete('/organizers/{organizer}',      [OrganizerController::class, 'destroy'])->name('organizers.destroy');
    Route::post('/organizers/import',             [OrganizerController::class, 'import'])->name('organizers.import');
    Route::get('/organizers/export',              [OrganizerController::class, 'export'])->name('organizers.export');
    Route::get('/organizers/data',                [OrganizerController::class, 'getData'])->name('organizers.getData');
    Route::post('/organizers/check-duplicate',    [OrganizerController::class, 'checkDuplicate'])->name('organizers.checkDuplicate');
    
    // Courses Routes
    Route::post('/courses',                       [AlumniController::class, 'storeCourse'])->name('courses.store');
    Route::put('/courses/{course}',               [AlumniController::class, 'updateCourse'])->name('courses.update');
    Route::delete('/courses/{course}',            [AlumniController::class, 'destroyCourse'])->name('courses.destroy');
    
});

// --- Logout ---
Route::post('/logout', function () {
    Auth::logout();
    session()->invalidate();
    session()->regenerateToken();
    return redirect()->route('login');
})->name('logout')->middleware('auth');