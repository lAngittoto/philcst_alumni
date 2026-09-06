<?php

use Livewire\Volt\Component;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Renderless;
use Livewire\WithPagination;
use Livewire\WithoutUrlPagination;
use Livewire\WithFileUploads;
use App\Models\Alumni;
use App\Models\Course;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

new class extends Component {
    // WithoutUrlPagination keeps previousPage()/nextPage()/gotoPage()
    // working exactly the same, but stops Livewire from writing ?page=N
    // into the browser's address bar — the URL stays clean on every
    // page, not just page 1. Trade-off: the current page no longer
    // survives a manual browser refresh (it resets to page 1), since
    // nothing in the URL remembers it anymore.
    use WithPagination, WithoutUrlPagination, WithFileUploads;

    public string $search = '';
    public string $course = '';

    // Logged-in alumni details
    public string $myBatch       = '';
    public string $myCourseCode  = '';
    public string $myCourseName  = '';
    public string $myCollege     = '';
    public int    $myAlumniId    = 0;

    // ── Own-photo upload (yearbook profile modal) ─────────────────
    // Deliberately keyed off $myAlumniId only — never a ($id) passed
    // in from the client — so there is no way for this to be pointed
    // at anyone else's record, no matter what the Alpine layer sends.
    public $newYearbookPhoto = null;

    protected string $paginationTheme = 'tailwind';

    public function mount(): void
    {
        $user = Auth::user();
        if ($user && $user->role === 'alumni') {
            $alumni = \App\Models\Alumni::where('user_id', $user->id)->first();
            if ($alumni) {
                $this->myBatch      = (string) ($alumni->batch       ?? '');
                $this->myCourseCode = (string) ($alumni->course_code ?? '');
                $this->myCourseName = (string) ($alumni->course_name ?? '');
                $this->myAlumniId   = (int)    ($alumni->id          ?? 0);

                // Get the REAL college this course belongs to (from Course.college column)
                if ($this->myCourseCode !== '') {
                    $this->myCollege = (string) (Course::where('code', $this->myCourseCode)->value('college') ?? '');
                }
            }
        }
    }

    public function updatingCourse() { $this->resetPage(); }
    public function updatingSearch() { $this->resetPage(); }

    /**
     * Course selection is handled by explicit server-side methods
     * (not by setting the property directly from Alpine/JS), so every
     * click is a clean, deterministic round-trip to the server.
     */
    public function setCourse(string $code): void
    {
        $this->course = $code;
        $this->resetPage();
    }

    public function clearCourse(): void
    {
        $this->course = '';
        $this->resetPage();
    }

    /**
     * Course dropdown options — ONLY courses that actually have alumni
     * in the SAME BATCH as the logged-in user. No caching here on
     * purpose: some environments run CACHE_STORE=array, which silently
     * drops cached data between requests and would serve stale options.
     * This is a small, batch-scoped query so recomputing every time is cheap.
     */
    #[Computed]
    public function courses()
    {
        $q = Alumni::query();
        if ($this->myBatch !== '') {
            $q->where('batch', $this->myBatch);
        }

        $codes = $q->pluck('course_code')->unique()->filter()->values();

        if ($codes->isEmpty()) {
            return collect();
        }

        return Course::whereIn('code', $codes)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }

    /**
     * Privacy rule: only alumni from the SAME BATCH as the logged-in user.
     * Within that batch, ALL courses are visible (no college restriction).
     * Optional: filter by specific course or search term.
     *
     * Sort priority (uses the REAL Course.college column via Eloquent):
     *   1) Your own course shows FIRST
     *   2) Then other courses in your SAME COLLEGE (grouped together)
     *   3) Then the rest, grouped by their own college, then course name, then name
     */
    #[Computed]
    public function alumniRecords()
    {
        $q = Alumni::query()
            ->select(['id', 'name', 'student_id', 'email', 'course_code', 'course_name', 'batch', 'profile_photo', 'status', 'created_at']);

        // ── PRIVACY: locked to same batch only ──
        if ($this->myBatch !== '') {
            $q->where('batch', $this->myBatch);
        }

        // ── Optional search (name / student ID / email) ──
        if (trim($this->search) !== '') {
            $s = trim($this->search);
            $q->where(function ($sub) use ($s) {
                $sub->where('name',        'like', "%{$s}%")
                    ->orWhere('student_id', 'like', "%{$s}%")
                    ->orWhere('email',      'like', "%{$s}%");
            });
        }

        // ── Optional course filter ──
        if ($this->course !== '') {
            $q->where('course_code', $this->course);
        }

        // Pull a real course_code -> college map (Eloquent, no raw table names)
        $collegeMap = Course::pluck('college', 'code')->toArray();

        $myCourseCode = $this->myCourseCode;
        $myCollege    = $this->myCollege;

        // Sort entirely in PHP using the real college map — safest & always correct
        $all = $q->get()->sortBy(function ($alumni) use ($collegeMap, $myCourseCode, $myCollege) {
            $college = $collegeMap[$alumni->course_code] ?? '';

            $ownCourseRank  = ($myCourseCode !== '' && $alumni->course_code === $myCourseCode) ? 0 : 1;
            $ownCollegeRank = ($myCollege !== '' && $college === $myCollege) ? 0 : 1;

            return sprintf(
                '%d|%d|%s|%s|%s',
                $ownCourseRank,
                $ownCollegeRank,
                $college,
                $alumni->course_name,
                $alumni->name
            );
        })->values();

        // Manual pagination over the sorted collection.
        //
        // BUG THAT WAS HERE: this used to read $this->page directly, but
        // WithPagination doesn't expose a plain public $page property —
        // that silently evaluated to null every time, so this computed
        // always rebuilt page 1 no matter what page Livewire's internal
        // state said you were on. Next/Prev/page-number clicks DID
        // update Livewire's pagination state correctly; this query just
        // never looked at it.
        //
        // A later attempt used $this->getPage('page') instead — but
        // Livewire's WithPagination trait has no public getPage() method
        // at all, so that call throws "Call to undefined method", which
        // is exactly the "clicked > and got 'No alumni found'" symptom:
        // the request errors out instead of rendering page 2.
        //
        // The trait's real, documented mechanism (used internally by
        // Model::paginate() itself) is Paginator::currentPageResolver(),
        // which WithPagination::initializeWithPagination() already wires
        // up automatically before mount() ever runs. Reading through
        // LengthAwarePaginator::resolveCurrentPage() taps into that same
        // resolver, so it always matches whatever page previousPage(),
        // nextPage(), or gotoPage() last set — no separate getPage() call
        // needed.
        $perPage = 100;
        $page    = (int) \Illuminate\Pagination\LengthAwarePaginator::resolveCurrentPage('page');
        $page    = $page > 0 ? $page : 1;
        $sliced  = $all->forPage($page, $perPage);

        return new \Illuminate\Pagination\LengthAwarePaginator(
            $sliced->values(),
            $all->count(),
            $perPage,
            $page,
            ['path' => request()->url(), 'pageName' => 'page']
        );
    }

    /**
     * Groups the CURRENT page of alumniRecords by course name, preserving
     * the sort order already applied above (own course first, etc).
     */
    #[Computed]
    public function groupedAlumni()
    {
        return $this->alumniRecords->getCollection()->groupBy('course_name');
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'course']);
        $this->resetPage();
    }

    public function getPhotoUrl(?string $path): string
    {
        if (empty($path) || $path === 'null' || is_null($path)) {
            return asset('storage/alumni-photos/default.png');
        }
        if (strpos($path, 'default.png') !== false) {
            return asset('storage/alumni-photos/default.png');
        }
        if (str_starts_with($path, 'alumni-photos/')) {
            return asset('storage/' . $path);
        }
        if (!str_contains($path, '/')) {
            return asset('storage/alumni-photos/' . $path);
        }
        return asset('storage/' . $path);
    }

    /**
     * Replace the logged-in alumni's own yearbook photo. Scoped strictly
     * to $this->myAlumniId (set once in mount() from the authenticated
     * user) — there is no id parameter here on purpose, so this can
     * never be used to touch anyone else's photo.
     */
    #[Renderless]
    public function uploadYearbookPhoto(): void
    {
        if (! $this->myAlumniId || ! $this->newYearbookPhoto) return;

        try {
            $alumni = Alumni::findOrFail($this->myAlumniId);

            if ($alumni->profile_photo && !str_contains($alumni->profile_photo, 'default.png')) {
                Storage::disk('public')->delete($alumni->profile_photo);
            }

            $path = $this->newYearbookPhoto->store('alumni-photos', 'public');
            $alumni->update(['profile_photo' => $path]);

            $this->newYearbookPhoto = null;

            $this->dispatch('flash-message', type: 'success', message: 'Yearbook photo updated successfully.');
            $this->dispatch('yb-photo-saved', url: $this->getPhotoUrl($path));
        } catch (\Exception $e) {
            $this->dispatch('flash-message', type: 'error', message: 'Failed to upload photo.');
        }
    }

    public function formatAlumniName(string $fullName): string
    {
        $parts = array_values(array_filter(explode(' ', trim($fullName)), fn ($p) => $p !== ''));

        if (count($parts) === 0) return '';
        if (count($parts) === 1) return $parts[0];

        $suffixes = ['jr', 'sr', 'ii', 'iii', 'iv', 'v'];

        $suffix = '';
        $lastToken = strtolower(rtrim(end($parts), '.'));
        if (in_array($lastToken, $suffixes, true)) {
            $suffix = array_pop($parts);
        }

        $count = count($parts);

        if ($count === 1) {
            return trim($parts[0] . ($suffix !== '' ? ' ' . $suffix : ''));
        }

        if ($count === 2) {
            return trim($parts[0] . ' ' . $parts[1] . ($suffix !== '' ? ' ' . $suffix : ''));
        }

        $firstName      = $parts[0];
        $lastName       = $parts[$count - 1];
        $middleInitials = '';

        for ($i = 1; $i < $count - 1; $i++) {
            $middleInitials .= strtoupper($parts[$i][0]) . '. ';
        }

        return trim($firstName . ' ' . $middleInitials . $lastName . ($suffix !== '' ? ' ' . $suffix : ''));
    }
};
?>

<div class="flex flex-col gap-2 sm:gap-4 px-4 sm:px-7 lg:px-10 pt-3 sm:pt-6 pb-2 sm:pb-6 max-w-screen-2xl mx-auto w-full yb-root-height"
     x-data="{
        setAvailHeight() {
            const rect = this.$el.getBoundingClientRect();
            const bottomSafe = 8;
            const avail = window.innerHeight - rect.top - bottomSafe;
            this.$el.style.setProperty('--yb-avail-h', avail + 'px');
        },
        profileOpen: false,
        profileData: null,
        closing: false,
        photoPreview: null,
        pendingPhotoFile: null,
        hasPendingPhoto: false,
        savingPhoto: false,
        checkingFace: false,
        faceError: '',
        faceModelsReady: false,
        faceModelsLoading: null,
        openProfile(data) {
            this.profileData = data;
            this.photoPreview = data.photo;
            this.pendingPhotoFile = null;
            this.hasPendingPhoto = false;
            this.savingPhoto = false;
            this.faceError = '';
            this.profileOpen = true;
            this.closing = false;
            if (data.isMe) this.ensureFaceModels();
        },
        closeProfile() {
            if (this.closing) return;
            this.closing = true;
            setTimeout(() => {
                this.profileOpen = false;
                this.closing = false;
                setTimeout(() => {
                    this.profileData = null;
                    this.photoPreview = null;
                    this.pendingPhotoFile = null;
                    this.hasPendingPhoto = false;
                    this.faceError = '';
                }, 200);
            }, 350);
        },
        // ── Loads face-api.js + the Tiny Face Detector model once, lazily,
        //    only when someone actually opens their own card (not on page
        //    load). Guarded by faceModelsLoading so a fast double-open
        //    can't kick off two parallel loads. ~190KB model, cached by
        //    the browser after the first load. ──
        ensureFaceModels() {
            if (this.faceModelsReady) return Promise.resolve();
            if (this.faceModelsLoading) return this.faceModelsLoading;
            this.faceModelsLoading = (async () => {
                if (typeof faceapi === 'undefined') {
                    await new Promise((resolve, reject) => {
                        const s = document.createElement('script');
                        s.src = 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api/dist/face-api.js';
                        s.onload = resolve;
                        s.onerror = () => reject(new Error('Failed to load face detection library'));
                        document.head.appendChild(s);
                    });
                }
                const MODEL_URL = 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api/model';
                await faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL);
                this.faceModelsReady = true;
            })();
            return this.faceModelsLoading;
        },
        // ── Verifies the uploaded image contains a detectable human
        //    face before it's allowed as a yearbook photo. Uses the
        //    lightweight Tiny Face Detector (client-side, no server
        //    round-trip). Not a perfect real-photo-vs-drawing check —
        //    some photorealistic art can still pass,
        //    and some genuine photos (heavy shadow, extreme angle,
        //    covered face) can still fail — but it reliably blocks
        //    clearly non-human images like anime/cartoon avatars,
        //    logos, landscapes, etc. ──
        async detectFace(imgEl) {
            await this.ensureFaceModels();
            const result = await faceapi.detectSingleFace(
                imgEl,
                new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.4 })
            );
            return !!result;
        },
        async onYbPhotoChange(event) {
            if (!this.profileData || !this.profileData.isMe) return;
            const file = event.target.files[0];
            if (!file) return;

            this.faceError = '';
            this.checkingFace = true;

            try {
                // Load the picked file into a real <img> element first —
                // face-api.js needs a rendered image/canvas/video element
                // to run detection on, not a raw File/Blob.
                const dataUrl = await new Promise((resolve, reject) => {
                    const r = new FileReader();
                    r.onload = (e) => resolve(e.target.result);
                    r.onerror = reject;
                    r.readAsDataURL(file);
                });
                const probeImg = await new Promise((resolve, reject) => {
                    const im = new Image();
                    im.onload = () => resolve(im);
                    im.onerror = () => reject(new Error('Could not read that image file.'));
                    im.src = dataUrl;
                });

                let hasFace = false;
                try {
                    hasFace = await this.detectFace(probeImg);
                } catch (detErr) {
                    // If the detector itself fails to load/run (offline,
                    // CDN blocked, etc.), fail OPEN rather than trap the
                    // user unable to ever change their photo — but still
                    // log it so it's visible during troubleshooting.
                    console.warn('Face detection unavailable, allowing photo through:', detErr);
                    hasFace = true;
                }

                this.checkingFace = false;

                if (!hasFace) {
                    this.faceError = 'We couldn\'t detect a clear face in that photo. Please use a real photo of yourself, not an avatar, drawing, or anime/cartoon image.';
                    if (this.$refs.ybPhotoInput) this.$refs.ybPhotoInput.value = '';
                    return;
                }
            } catch (err) {
                this.checkingFace = false;
                this.faceError = 'Couldn\'t read that image. Please try a different file.';
                if (this.$refs.ybPhotoInput) this.$refs.ybPhotoInput.value = '';
                return;
            }

            try {
                const compressed = await this.compressYbImage(file, 500, 500, 0.75);
                this.pendingPhotoFile = compressed;
                this.hasPendingPhoto = true;
                const reader = new FileReader();
                reader.onload = (e) => { this.photoPreview = e.target.result; };
                reader.readAsDataURL(compressed);
            } catch (err) {
                this.pendingPhotoFile = file;
                this.hasPendingPhoto = true;
                const reader = new FileReader();
                reader.onload = (e) => { this.photoPreview = e.target.result; };
                reader.readAsDataURL(file);
            }
        },
        compressYbImage(file, maxW, maxH, quality) {
            return new Promise((resolve, reject) => {
                const img = new Image();
                const reader = new FileReader();
                reader.onload = (e) => {
                    img.onload = () => {
                        let w = img.width, h = img.height;
                        if (w > maxW || h > maxH) {
                            const ratio = Math.min(maxW / w, maxH / h);
                            w = Math.round(w * ratio);
                            h = Math.round(h * ratio);
                        }
                        const canvas = document.createElement('canvas');
                        canvas.width = w; canvas.height = h;
                        canvas.getContext('2d').drawImage(img, 0, 0, w, h);
                        canvas.toBlob((blob) => {
                            if (!blob) return reject(new Error('compress failed'));
                            resolve(new File([blob], file.name.replace(/\.\w+$/, '.jpg'), { type: 'image/jpeg' }));
                        }, 'image/jpeg', quality);
                    };
                    img.onerror = reject;
                    img.src = e.target.result;
                };
                reader.onerror = reject;
                reader.readAsDataURL(file);
            });
        },
        saveYbPhoto() {
            if (this.savingPhoto || !this.pendingPhotoFile || !this.profileData?.isMe) return;
            this.savingPhoto = true;
            $wire.upload('newYearbookPhoto', this.pendingPhotoFile,
                () => { $wire.uploadYearbookPhoto(); },
                () => {
                    this.savingPhoto = false;
                    this.hasPendingPhoto = false;
                    this.pendingPhotoFile = null;
                    this.photoPreview = this.profileData.photo;
                    if (this.$refs.ybPhotoInput) this.$refs.ybPhotoInput.value = '';
                },
                () => {}
            );
        },
        cancelYbPhoto() {
            this.pendingPhotoFile = null;
            this.hasPendingPhoto = false;
            this.photoPreview = this.profileData ? this.profileData.photo : null;
            this.faceError = '';
            if (this.$refs.ybPhotoInput) this.$refs.ybPhotoInput.value = '';
        }
     }"
     x-init="
        setAvailHeight();
        window.addEventListener('resize', () => setAvailHeight());
        window.addEventListener('orientationchange', () => setTimeout(() => setAvailHeight(), 150));
        $wire.on('yb-photo-saved', (e) => {
            const url = Array.isArray(e) ? e[0]?.url : e?.url;
            savingPhoto = false;
            hasPendingPhoto = false;
            pendingPhotoFile = null;
            if (url) {
                photoPreview = url;
                if (profileData) profileData.photo = url;
                document.querySelectorAll('[data-yb-my-photo]').forEach(img => { img.src = url; });
            }
            if ($refs.ybPhotoInput) $refs.ybPhotoInput.value = '';
        });
     ">


<style>
/* ── Base ──────────────────────────────────────────────── */
.yb-card { transition: border-color .15s ease, box-shadow .15s ease; position: relative; }
.yb-card:hover { border-color: #c49ed8 !important; box-shadow: 0 4px 14px rgba(122,63,145,.14); }

.yb-section-badge {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 14px; border-radius: 9999px;
    font-size: 12px; font-weight: 700; letter-spacing: .02em;
    background: #F3E8FF; color: #7A3F91; border: 1.5px solid #D8B4FE;
}
.yb-chip {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 12px; border-radius: 9999px;
    font-size: 11px; font-weight: 700; letter-spacing: .04em;
    background: rgba(122,63,145,.10); color: #7A3F91;
    border: 1px solid rgba(122,63,145,.22); white-space: nowrap;
}

/* ── Scrollbar ──────────────────────────────────────────── */
.yb-scroll::-webkit-scrollbar       { width: 5px; }
.yb-scroll::-webkit-scrollbar-track { background: #f3f4f6; border-radius: 99px; }
.yb-scroll::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }
.yb-scroll::-webkit-scrollbar-thumb:hover { background: #7a3f91; }

/* ── Entry animation ────────────────────────────────────── */
@keyframes ybFadeUp {
    from { opacity: 0; transform: translateY(8px); }
    to   { opacity: 1; transform: translateY(0); }
}
.yb-grid-wrap { animation: ybFadeUp .22s cubic-bezier(.4,0,.2,1) both; }

/* ── Search input ───────────────────────────────────────── */
.yb-search-input {
    padding: 0.5rem 0.75rem 0.5rem 2.25rem;
    border: 1px solid #E8E0F0; border-radius: 0.5rem;
    font-size: 0.875rem; font-weight: 500;
    background: #fff; color: #333333;
    transition: border-color .15s, box-shadow .15s;
    outline: none; width: 100%;
}
.yb-search-input::placeholder { color: #999999; font-weight: 400; }
.yb-search-input:hover  { border-color: #c4b5d4; }
.yb-search-input:focus  { border-color: #7a3f91; box-shadow: 0 0 0 2px rgba(122,63,145,.10); }

/* ── Dropdown button ────────────────────────────────────── */
.yb-dd-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 0.5rem 2.25rem 0.5rem 0.75rem;
    border: 1px solid #E8E0F0; border-radius: 0.5rem;
    font-size: 0.875rem; font-weight: 500;
    background: #fff; color: #333333;
    cursor: pointer; white-space: nowrap;
    transition: border-color .15s, box-shadow .15s;
    outline: none; user-select: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23333333' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
    background-position: right 0.6rem center;
    background-repeat: no-repeat; background-size: 1.25em 1.25em;
}
.yb-dd-btn:hover  { border-color: #c4b5d4; }
.yb-dd-btn.active { border-color: #7a3f91; box-shadow: 0 0 0 2px rgba(122,63,145,.10); color: #7a3f91; }

/* ── Dropdown panel ─────────────────────────────────────── */
.yb-dd-panel {
    position: absolute; top: calc(100% + 4px); left: 0;
    min-width: 100%; max-height: 224px; overflow-y: auto;
    background: #fff; border: 1.5px solid #E8E0F0;
    border-radius: 10px; box-shadow: 0 8px 24px rgba(122,63,145,.13);
    z-index: 600; padding: 4px;
    scrollbar-width: thin; scrollbar-color: #d4b8e8 transparent;
}
.yb-dd-panel::-webkit-scrollbar       { width: 4px; }
.yb-dd-panel::-webkit-scrollbar-thumb { background: #d4b8e8; border-radius: 9999px; }
.yb-dd-item {
    display: block; width: 100%; padding: 6px 12px;
    border-radius: 7px; text-align: left;
    font-size: 12px; font-weight: 600; color: #333333;
    background: transparent; border: none; cursor: pointer;
    white-space: nowrap; transition: background .12s, color .12s;
}
.yb-dd-item:hover { background: #F5F0FA; color: #7A3F91; }
.yb-dd-item.sel   { background: #F0E6F8; color: #7A3F91; }

/* ── Main block ─────────────────────────────────────────── */
.yb-table-block {
    display: flex; flex-direction: column;
    border-radius: 1rem; overflow: hidden;
    border: 1px solid #E8E0F0;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
    flex: 1; min-height: 0;
}
.yb-filter-bar {
    background: #F5F5F5; border-bottom: 1px solid #E8E0F0;
    padding: 0.6rem 0.875rem; flex-shrink: 0;
    position: relative; z-index: 50; overflow: visible;
}
.yb-pagination-bar {
    flex-shrink: 0;
    background: #7A3F91;
    padding: 0 1rem; min-height: 48px;
    display: flex; align-items: center;
    justify-content: space-between; gap: 0.5rem;
    flex-wrap: wrap; border-top: 1px solid rgba(255,255,255,.15);
    /* Safety net: always pinned to the bottom of the table block,
       so it can never end up scrolled out of view, no matter how
       tall the card area ends up being. */
    position: sticky;
    bottom: 0;
    z-index: 30;
}
.yb-pg-btn {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 32px; height: 32px; padding: 0 10px;
    border-radius: 8px; font-size: 12px; font-weight: 700; transition: all .15s;
}
.yb-pg-active { background: #fff; color: #7a3f91; }
.yb-pg-nav    { background: rgba(255,255,255,.15); color: #fff; border: 1px solid rgba(255,255,255,.25); }
.yb-pg-nav:hover:not(:disabled) { background: rgba(255,255,255,.28); border-color: rgba(255,255,255,.5); }
.yb-pg-nav:disabled { opacity: .35; cursor: not-allowed; }

/* ── Big BATCH banner in header ─────────────────────────── */
.yb-batch-banner {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: 0;
    overflow: hidden;
    border-radius: 14px;
    border: 2px solid #7A3F91;
    box-shadow: 0 2px 16px rgba(122,63,145,.18), 0 0 0 4px rgba(122,63,145,.07);
    background: #fff;
    padding: 0;
    animation: ybFadeUp .3s cubic-bezier(.4,0,.2,1) both;
}
.yb-batch-banner-label {
    background: #7A3F91;
    color: #fff;
    font-size: 10px;
    font-weight: 800;
    letter-spacing: .12em;
    text-transform: uppercase;
    padding: 0 10px;
    height: 38px;
    display: flex;
    align-items: center;
    white-space: nowrap;
    flex-shrink: 0;
}
.yb-batch-banner-year {
    color: #7A3F91;
    font-size: clamp(1.15rem, 2.2vw, 1.55rem);
    font-weight: 900;
    letter-spacing: .06em;
    text-transform: uppercase;
    padding: 0 18px 0 14px;
    height: 38px;
    display: flex;
    align-items: center;
    white-space: nowrap;
    background: #fff;
    line-height: 1;
}

/* ── "My card" highlight ─────────────────────────────────── */
.yb-card-me {
    border-color: #7A3F91 !important;
    border-width: 2px !important;
    animation: ybMeGlowPulse 2.2s ease-in-out infinite;
}
.yb-card-me:hover {
    animation: none;
    box-shadow: 0 0 0 6px rgba(122,63,145,.35), 0 10px 28px rgba(122,63,145,.32) !important;
}
@keyframes ybMeGlowPulse {
    0%, 100% {
        box-shadow: 0 0 0 3px rgba(122,63,145,.18), 0 6px 16px rgba(122,63,145,.14);
    }
    50% {
        box-shadow: 0 0 0 7px rgba(122,63,145,.32), 0 10px 26px rgba(122,63,145,.30);
    }
}

/* ── Root height ─────────────────────────────────────────
   Desktop: reserve 180px for surrounding layout chrome.
   Mobile: instead of guessing a fixed px offset for the
   topbar (hamburger/bell), --yb-avail-h is measured live via
   Alpine (window.innerHeight - element's actual top offset),
   so the block always fits exactly under whatever topbar
   height the layout actually has, on any device. Falls back
   to the 100dvh calc if JS hasn't run yet.
──────────────────────────────────────────────────────── */
.yb-root-height {
    height: calc(100vh - 180px);
    max-height: calc(100vh - 180px);
    overflow: hidden;
}

/* Privacy notice pill */
.yb-privacy-pill {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 3px 11px; border-radius: 9999px;
    font-size: 11px; font-weight: 700; letter-spacing: .03em;
    background: #FFF8E7; color: #92660A;
    border: 1.5px solid #F6D860;
    white-space: nowrap;
}

/* ── Mobile responsiveness ──────────────────────────────── */
@media (max-width: 640px) {
    .yb-batch-banner-year { font-size: 1.1rem; padding: 0 12px 0 10px; }
    .yb-batch-banner-label { font-size: 9px; padding: 0 8px; }
    .yb-filter-bar { gap: 8px; }
}

/*
   Mobile pagination visibility fix:
   Use the JS-measured --yb-avail-h custom property (falls back
   to 100dvh if not yet set) so the block height always matches
   the REAL visible space under the app's topbar. Combined with
   the sticky pagination bar above, the page/pagination is now
   guaranteed visible without needing to scroll the outer page.
*/
@media (max-width: 767px) {
    html, body { overflow: hidden !important; }

    .yb-root-height {
        height: var(--yb-avail-h, 100dvh) !important;
        max-height: var(--yb-avail-h, 100dvh) !important;
        overflow: hidden !important;
    }

    /* Compact header on mobile to free up vertical space */
    .yb-mobile-subtitle { display: none; }
    .yb-mobile-header-icon { width: 2.25rem !important; height: 2.25rem !important; }
    .yb-mobile-title { font-size: 1rem !important; }

    .yb-filter-bar { padding: 0.45rem 0.65rem; }
    .yb-dd-btn, .yb-search-input { padding-top: 0.4rem; padding-bottom: 0.4rem; }

    .yb-pagination-bar { min-height: 40px; padding: 6px 0.75rem; }
    .yb-pagination-bar p { font-size: 11px; }

    .yb-pagination-bar {
        padding-bottom: calc(0.4rem + env(safe-area-inset-bottom, 0px));
    }
}

/* ── Floating background bubbles (whole scroll area) ─────────
   Layered, gently drifting white/lavender circles behind the
   alumni cards — NOT on the card headers themselves, so every
   card stays a clean, readable solid purple. This lives on the
   scroll container background only. ──────────────────────────── */
.yb-bubble-bg {
    position: relative;
    background-color: #FFFFFF;
}
.yb-bubble-bg::before {
    content: '';
    position: absolute;
    inset: 0;
    z-index: 0;
    pointer-events: none;
    background-image:
        radial-gradient(circle, rgba(243,232,255,0.6) 0, rgba(243,232,255,0.6) 6px, transparent 7px),
        radial-gradient(circle, rgba(243,232,255,0.45) 0, rgba(243,232,255,0.45) 4px, transparent 5px),
        radial-gradient(circle, rgba(216,180,254,0.25) 0, rgba(216,180,254,0.25) 5px, transparent 6px),
        radial-gradient(circle, rgba(243,232,255,0.5) 0, rgba(243,232,255,0.5) 3px, transparent 4px);
    background-repeat: repeat;
    background-size: 340px 340px, 260px 260px, 300px 300px, 220px 220px;
    background-position: 20px 40px, 180px 120px, 90px 220px, 250px 60px;
    animation: ybBubbleDrift 22s linear infinite;
}
@keyframes ybBubbleDrift {
    from { background-position: 20px 40px, 180px 120px, 90px 220px, 250px 60px; }
    to   { background-position: 20px -300px, 180px -140px, 90px -80px, 250px -260px; }
}
@media (prefers-reduced-motion: reduce) {
    .yb-bubble-bg::before { animation: none; }
}

/* ── Card click affordance ─────────────────────────────────
   Only the LOGGED-IN alumni's own card is clickable and opens
   the profile modal — other alumni cards in the yearbook have
   no click handler at all and use the default cursor. ─────── */
.yb-card-clickable { cursor: pointer; }
.yb-card-clickable:hover { transform: translateY(-2px); }
.yb-card-clickable:active { transform: translateY(0); }

/* ── "View Profile" tooltip — own card only, hover-triggered,
   matches the dark-pill .tip pattern used elsewhere in the app
   (event cards' Share/RSVP tooltips) ────────────────────────── */
.yb-card-tip {
    position: absolute; top: -8px; left: 50%;
    transform: translate(-50%, -100%);
    background: #111827; color: #fff;
    font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
    padding: 4px 10px; border-radius: 6px; white-space: nowrap;
    pointer-events: none; opacity: 0; transition: opacity .15s;
    z-index: 40;
}
.yb-card-tip::after {
    content: ''; position: absolute; top: 100%; left: 50%;
    transform: translateX(-50%);
    border: 4px solid transparent; border-top-color: #111827;
}
.yb-card-clickable:hover .yb-card-tip { opacity: 1; }

/* ── Profile / yearbook-page modal ──────────────────────── */
.yb-modal-overlay {
    position: fixed; inset: 0; z-index: 1000;
    background: rgba(40, 20, 55, .55);
    backdrop-filter: blur(3px);
    display: flex; align-items: center; justify-content: center;
    padding: 16px;
}
.yb-modal-card {
    position: relative;
    width: 100%; max-width: 420px;
    background: #fff;
    border-radius: 22px;
    overflow: hidden;
    box-shadow: 0 24px 60px rgba(60,20,80,.35);
    max-height: 90vh;
    display: flex; flex-direction: column;
}

/* ── Mobile: profile view goes full screen, not a floating modal ── */
@media (max-width: 640px) {
    .yb-modal-overlay {
        padding: 0;
        align-items: stretch;
        justify-content: stretch;
    }
    .yb-modal-card {
        max-width: 100%;
        width: 100%;
        height: 100dvh;
        max-height: 100dvh;
        border-radius: 0;
    }
    .yb-modal-body { flex: 1; }
}
.yb-modal-close {
    position: absolute; top: 12px; right: 12px; z-index: 5;
    width: 32px; height: 32px; border-radius: 9999px;
    background: rgba(255,255,255,.25);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    backdrop-filter: blur(4px);
    transition: background .15s;
}
.yb-modal-close:hover { background: rgba(255,255,255,.4); }
.yb-modal-header {
    position: relative;
    background: linear-gradient(135deg, #7A3F91 0%, #9C5FB8 100%);
    padding: 40px 20px 66px;
    text-align: center;
    flex-shrink: 0;
}
.yb-modal-header-deco {
    position: absolute; inset: 0;
    overflow: hidden;
    border-radius: 22px 22px 0 0;
    pointer-events: none;
}
.yb-modal-header-deco::before,
.yb-modal-header-deco::after {
    content: '';
    position: absolute;
    border-radius: 9999px;
    background: rgba(255,255,255,.12);
}
.yb-modal-header-deco::before { width: 140px; height: 140px; top: -60px; left: -40px; }
.yb-modal-header-deco::after  { width: 90px; height: 90px; bottom: -30px; right: -20px; background: rgba(255,255,255,.1); }
.yb-modal-photo-ring {
    position: relative;
    margin: 0 auto;
    width: 148px; height: 148px; border-radius: 9999px;
    background: #fff; padding: 5px;
    box-shadow: 0 8px 22px rgba(60,20,80,.3);
    z-index: 2;
}
/* ── Hover-to-change overlay — enabled ONLY on the viewer's own
     card (gated server-side via profileData.isMe in the template),
     mirrors the registrar's "hover photo to change" pattern. ── */
.yb-modal-photo-outer {
    position: relative;
    width: 100%; height: 100%;
}
.yb-modal-photo-wrap {
    position: relative;
    width: 100%; height: 100%;
    border-radius: 9999px;
    overflow: hidden;
}
.yb-modal-photo-overlay {
    position: absolute; inset: 0;
    border-radius: 9999px;
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px;
    background: rgba(0,0,0,.55);
    color: #fff;
    opacity: 0;
    transition: opacity .15s;
    cursor: pointer;
}
.yb-modal-photo-wrap:hover .yb-modal-photo-overlay { opacity: 1; }
.yb-modal-photo-overlay span {
    font-size: 10px; font-weight: 700; letter-spacing: .03em; text-transform: uppercase;
}
.yb-modal-photo-uploading {
    position: absolute; inset: 0;
    border-radius: 9999px;
    background: linear-gradient(90deg,rgba(122,63,145,.25) 25%,rgba(122,63,145,.45) 50%,rgba(122,63,145,.25) 75%);
    background-size: 200% 100%;
    animation: ybShimmer 1.2s infinite linear;
    display: flex; align-items: center; justify-content: center;
}
@keyframes ybShimmer {
    0%   { background-position: -200% 0; }
    100% { background-position:  200% 0; }
}
.yb-modal-photo-actions {
    position: absolute;
    left: 50%; transform: translateX(-50%);
    bottom: -32px;
    display: flex; gap: 6px;
    z-index: 3;
}
.yb-modal-photo-action-btn {
    width: 30px; height: 30px; border-radius: 9999px;
    border: 1.5px solid #E0E0E0;
    background: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px;
    cursor: pointer;
    box-shadow: 0 3px 10px rgba(0,0,0,.18);
    transition: all .15s;
}
.yb-modal-photo-save-btn { background: #7A3F91; border-color: #7A3F91; color: #fff; }
.yb-modal-photo-save-btn:hover { background: #6a3280; border-color: #6a3280; }
.yb-modal-photo-save-btn:disabled { opacity: .55; cursor: not-allowed; }
.yb-modal-photo-cancel-btn { color: #555555; }
.yb-modal-photo-cancel-btn:hover { background: #FEF2F2; border-color: #FECACA; color: #DC2626; }
.yb-modal-face-error {
    margin-top: 16px;
    display: flex; align-items: flex-start; gap: 7px;
    padding: 10px 13px; border-radius: 12px;
    background: #FEF2F2; border: 1.5px solid #FECACA;
    color: #B91C1C;
    font-size: 12px; font-weight: 600; line-height: 1.45;
    text-align: left;
}
.yb-modal-face-error i { margin-top: 1px; flex-shrink: 0; }
.yb-modal-body {
    padding: 20px 24px 24px;
    text-align: center;
    overflow-y: auto;
}
.yb-modal-info-stack {
    margin-top: 18px;
    display: flex; flex-direction: column; align-items: center; gap: 8px;
}
.yb-modal-program {
    font-size: 14px; font-weight: 800; color: #333333;
    line-height: 1.3;
}
.yb-modal-batch-pill {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 16px; border-radius: 9999px;
    font-size: 12.5px; font-weight: 700;
    background: #F3E8FF; color: #7A3F91;
    border: 1.5px solid #D8B4FE;
}
.yb-modal-note {
    margin-top: 14px;
    border-radius: 14px;
    padding: 16px 18px;
    background: linear-gradient(135deg, #F8F0FF 0%, #F3E8FF 100%);
    border: 1.5px solid #E4CBFA;
}
.yb-modal-note-title {
    font-size: 13px; font-weight: 800; color: #7A3F91;
    letter-spacing: .02em; margin-bottom: 4px;
}
.yb-modal-note-text {
    font-size: 13.5px; line-height: 1.55; color: #4A2E58;
}
.yb-modal-private-tag {
    margin-top: 12px;
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 12px; border-radius: 9999px;
    font-size: 10.5px; font-weight: 700; letter-spacing: .03em;
    background: #FFF8E7; color: #92660A;
    border: 1.5px solid #F6D860;
}
@keyframes ybModalIn {
    from { opacity: 0; transform: scale(.94) translateY(8px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
}
.yb-modal-anim { animation: ybModalIn .22s cubic-bezier(.4,0,.2,1) both; }
</style>

    {{-- PAGE HEADER --}}
    <div class="flex flex-col gap-3 flex-shrink-0">

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-4">
                <div class="yb-mobile-header-icon w-11 h-11 rounded-2xl flex items-center justify-center flex-shrink-0 shadow-md bg-[#7A3F91]">
                    <i class="fas fa-book-open text-white text-lg"></i>
                </div>
                <div>
                    <h1 class="yb-mobile-title text-xl font-semibold tracking-tight" style="color:#333333;">Alumni Yearbook</h1>
                    <p class="yb-mobile-subtitle text-xs leading-relaxed mt-0.5" style="color:#555555;">
                        A digital collection of PhilCST graduates
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-2 flex-wrap">
                {{-- Big readable BATCH XXXX banner --}}
                @if($myBatch !== '')
                <div class="yb-batch-banner" aria-label="Batch {{ $myBatch }}">
                    <span class="yb-batch-banner-label">
                        <i class="fas fa-graduation-cap mr-1.5" style="font-size:9px;"></i>
                        Batch
                    </span>
                    <span class="yb-batch-banner-year">{{ $myBatch }}</span>
                </div>
                @endif
            </div>
        </div>

    </div>

    {{-- UNIFIED BLOCK --}}
    <div class="yb-table-block">

        {{-- FILTER BAR --}}
        <div class="yb-filter-bar flex flex-wrap gap-2 items-center">

            <div class="flex items-center gap-2 px-1 h-[38px] rounded-xl shrink-0 font-semibold text-sm uppercase tracking-wide"
                 style="color:#7a3f91;">
                Filters
            </div>

            {{--
                FIX (real bug, matched to registrar/alumni-records.blade.php):
                Previously this input was driven by a locally-scoped Alpine
                `liveSearch` variable that was seeded ONCE from `@js($search)`
                and never synced again. That caused two problems:

                1) The old manual setTimeout debounce could still race with
                   a Livewire response repainting the wrapper (no wire:ignore),
                   letting the server's older $search snapshot occasionally
                   stomp on what the user was mid-typing.

                2) If $search was ever changed from OUTSIDE the input itself
                   — e.g. the "Reset" button calling resetFilters(), which
                   resets $search server-side — the textbox never found out,
                   so it kept showing stale/typed text even though the
                   underlying filter had already been cleared.

                Fix: wrap with wire:ignore so Livewire's morph never touches
                this subtree at all (Alpine has full, uncontested ownership
                of the input's value). Use Alpine's built-in
                `@input.debounce.300ms` instead of a manual timer, and add
                a `$wire.$watch('search', ...)` so any external change to
                $search (Reset button, programmatic resets, etc.) syncs
                back into the visible input automatically.
            --}}
            <div class="relative flex-1 min-w-[150px] max-w-xs" wire:ignore
                 x-data="{
                    q: @js($search),
                    init() {
                        this.q = $wire.search ?? '';
                        $wire.$watch('search', v => { if (v !== this.q) this.q = v; });
                    }
                 }">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none text-xs"
                   style="color:#555555; z-index:1;"></i>
                <input type="text"
                       x-model="q"
                       @input.debounce.300ms="$wire.set('search', q)"
                       placeholder="Search name, ID, email…"
                       class="yb-search-input"
                       autocomplete="off" spellcheck="false">
            </div>

            <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                <button type="button"
                        @click="open = !open"
                        :class="{ 'active': $wire.course !== '' }"
                        class="yb-dd-btn">
                    @if($course !== '')
                        <span>{{ $this->courses->firstWhere('code', $course)?->name ?? $course }}</span>
                    @else
                        <span>All Programs</span>
                    @endif
                </button>
                <div x-show="open"
                     x-transition:enter="transition ease-out duration-100"
                     x-transition:enter-start="opacity-0 scale-95"
                     x-transition:enter-end="opacity-100 scale-100"
                     x-transition:leave="transition ease-in duration-75"
                     x-transition:leave-start="opacity-100 scale-100"
                     x-transition:leave-end="opacity-0 scale-95"
                     class="yb-dd-panel"
                     style="display:none; min-width:280px;">
                    <button type="button"
                            wire:click="clearCourse"
                            @click="open = false"
                            :class="{ 'sel': $wire.course === '' }"
                            class="yb-dd-item">All Programs</button>
                    @forelse($this->courses as $c)
                    <button type="button"
                            wire:click="setCourse('{{ $c->code }}')"
                            @click="open = false"
                            :class="{ 'sel': $wire.course === '{{ $c->code }}' }"
                            class="yb-dd-item">{{ $c->name }}</button>
                    @empty
                    <p class="px-3 py-2 text-xs" style="color:#999;">No other courses in your batch yet.</p>
                    @endforelse
                </div>
            </div>

            {{-- Reset --}}
            <button wire:click="resetFilters"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-60 cursor-wait"
                    wire:target="resetFilters"
                    class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm font-semibold
                           bg-white border border-[#E8E0F0] transition active:scale-95 disabled:pointer-events-none cursor-pointer"
                    style="color:#333333;">
                <span wire:loading.remove wire:target="resetFilters">
                    <i class="fas fa-rotate-left text-sm"></i>
                </span>
                <span wire:loading wire:target="resetFilters">
                    <i class="fas fa-spinner fa-spin text-sm" style="color:#7A3F91;"></i>
                </span>
                <span class="hidden sm:inline">Reset</span>
            </button>

        </div>

        {{-- SCROLLABLE CARDS AREA --}}
        <div class="flex-1 min-h-0 relative yb-bubble-bg"
             x-data="{ showTop: false }">

            <div id="yb-scroll"
                 @scroll.passive="showTop = $event.target.scrollTop > 200"
                 class="yb-scroll absolute inset-0 overflow-y-auto overflow-x-hidden p-3 sm:p-4 transition-opacity duration-200"
                 style="z-index: 1;"
                 wire:loading.class="opacity-40 pointer-events-none"
                 wire:target="search,course,setCourse,clearCourse,resetFilters,previousPage,nextPage,gotoPage">

                <div class="hidden absolute inset-0 z-[9999] items-center justify-center pointer-events-none"
                     wire:loading.flex wire:target="search,course,setCourse,clearCourse,resetFilters,previousPage,nextPage,gotoPage">
                    <i class="fas fa-spinner fa-spin" style="font-size:38px; color:#7a3f91;"></i>
                </div>

                @if($this->alumniRecords->count() > 0)
                    <div class="yb-grid-wrap space-y-2"
                         wire:key="results-{{ md5($search . '|' . $course . '|' . $this->alumniRecords->currentPage()) }}">
                        @foreach($this->groupedAlumni as $courseName => $group)
                            <div wire:key="group-{{ Str::slug($courseName) }}">
                                <div class="flex items-center gap-2 pt-2 pb-2 px-1">
                                    <span class="yb-section-badge">
                                        {{ $courseName }}
                                    </span>
                                    <div class="flex-1 h-px" style="background:#D8B4FE;"></div>
                                </div>

                                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6 gap-3">
                                    @foreach($group as $alumni)
                                        @php $isMe = ($myAlumniId > 0 && $alumni->id === $myAlumniId); @endphp
                                        <div wire:key="alumni-{{ $alumni->id }}"
                                             class="yb-card {{ $isMe ? 'yb-card-me yb-card-clickable' : '' }} bg-white rounded-2xl overflow-hidden border flex flex-col items-center shadow-sm"
                                             style="border-color:#E8E0F0;"
                                             @if($isMe)
                                             @click="openProfile({
                                                name: @js($this->formatAlumniName($alumni->name)),
                                                course: @js($alumni->course_name),
                                                batch: @js((string) $alumni->batch),
                                                photo: @js($this->getPhotoUrl($alumni->profile_photo)),
                                                isMe: true
                                             })"
                                             @endif>

                                            {{-- Purple header strip --}}
                                            <div class="w-full h-[88px] shrink-0 relative bg-[#7A3F91]">
                                                <div class="absolute left-1/2 -translate-x-1/2 -bottom-[39px] z-10 w-[78px] h-[78px]">
                                                    <img src="{{ $this->getPhotoUrl($alumni->profile_photo) }}"
                                                         alt="{{ $alumni->name }}"
                                                         @if($isMe) data-yb-my-photo @endif
                                                         class="w-full h-full rounded-full object-cover block"
                                                         style="border:{{ $isMe ? '3px solid #7A3F91' : '3px solid #fff' }}; box-shadow:{{ $isMe ? '0 0 0 3px #fff, 0 0 0 5px #7A3F91, 0 3px 12px rgba(122,63,145,.3)' : '0 2px 10px rgba(0,0,0,.12)' }}; background:#f0e6f8;"
                                                         loading="lazy" decoding="async"
                                                         onerror="this.src='{{ asset('storage/alumni-photos/default.png') }}'">
                                                </div>
                                            </div>

                                            {{-- Card body --}}
                                            <div class="w-full pt-[52px] pb-5 px-3.5 flex flex-col items-center text-center flex-1">
                                                <p class="text-sm font-semibold leading-snug mb-2 break-words w-full uppercase"
                                                   style="color:#111111;">
                                                    {{ $this->formatAlumniName($alumni->name) }}
                                                </p>
                                                <p class="text-sm font-bold uppercase leading-snug mb-2.5"
                                                   style="color:#333333; letter-spacing:0.02em;">
                                                    {{ $alumni->course_name }}
                                                </p>
                                                <span class="inline-flex items-center gap-1 px-2.5 py-[3px] rounded-full text-xs font-bold"
                                                      style="background:#F3E8FF; color:#7A3F91; border:1.5px solid #D8B4FE;">
                                                    Class of {{ $alumni->batch }}
                                                </span>
                                            </div>
                                            @if($isMe)
                                            <span class="yb-card-tip">View Profile</span>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="flex flex-col items-center justify-center py-24 text-center">
                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center bg-gray-100 mb-4">
                            <i class="fas fa-book text-xl text-gray-400"></i>
                        </div>
                        <p class="font-semibold text-base" style="color:#333333;">No alumni found.</p>
                        <p class="text-sm mt-1" style="color:#555555;">Try adjusting your filters.</p>
                        @if($search || $course)
                        <button wire:click="resetFilters"
                                class="mt-4 px-4 py-2 rounded-xl text-sm font-semibold text-white transition uppercase tracking-widest cursor-pointer"
                                style="background-color:#7a3f91;">
                            <i class="fas fa-rotate-left mr-1.5 text-xs"></i> Clear Filters
                        </button>
                        @endif
                    </div>
                @endif

            </div>

            {{-- Scroll-to-top --}}
            <button x-show="showTop" x-cloak
                    @click="document.getElementById('yb-scroll').scrollTo({top:0,behavior:'smooth'})"
                    class="absolute bottom-4 right-4 z-20 w-9 h-9 rounded-xl flex items-center justify-center shadow-lg transition-all text-white"
                    style="background:#7A3F91;">
                <i class="fas fa-arrow-up text-xs"></i>
            </button>

        </div>{{-- /scroll wrapper --}}

        {{-- PAGINATION (sticky — always visible, no extra scroll needed) --}}
        @php
            $total   = $this->alumniRecords->total();
            $pp      = $this->alumniRecords->perPage();
            $cp      = $this->alumniRecords->currentPage();
            $lp      = $this->alumniRecords->lastPage();
            $from    = $total > 0 ? ($cp - 1) * $pp + 1 : 0;
            $to      = min($cp * $pp, $total);
            $pgStart = max(1, $cp - 2);
            $pgEnd   = min($lp, $cp + 2);
        @endphp
        <div class="yb-pagination-bar">
            <p class="text-white/80 text-xs font-normal whitespace-nowrap">
                Showing <strong class="text-white font-bold">{{ number_format($from) }}–{{ number_format($to) }}</strong>
                of <strong class="text-white font-bold">{{ number_format($total) }}</strong> alumni
                @if($search || $course)
                    <span class="text-white/50 text-xs ml-1">(filtered)</span>
                @endif
            </p>

            @if($lp > 1)
            <div class="flex items-center gap-1 flex-wrap py-2">
                <button wire:click="previousPage"
                        class="yb-pg-btn yb-pg-nav"
                        @if($this->alumniRecords->onFirstPage()) disabled @endif>
                    <i class="fas fa-chevron-left text-[9px]"></i>
                </button>

                @if($pgStart > 1)
                    <button wire:click="gotoPage(1)" class="yb-pg-btn yb-pg-nav">1</button>
                    @if($pgStart > 2)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
                @endif

                @for($p = $pgStart; $p <= $pgEnd; $p++)
                    @if($p === $cp)
                        <span class="yb-pg-btn yb-pg-active">{{ $p }}</span>
                    @else
                        <button wire:click="gotoPage({{ $p }})" class="yb-pg-btn yb-pg-nav">{{ $p }}</button>
                    @endif
                @endfor

                @if($pgEnd < $lp)
                    @if($pgEnd < $lp - 1)<span class="text-white/55 text-sm font-semibold px-0.5">…</span>@endif
                    <button wire:click="gotoPage({{ $lp }})" class="yb-pg-btn yb-pg-nav">{{ $lp }}</button>
                @endif

                <button wire:click="nextPage"
                        class="yb-pg-btn yb-pg-nav"
                        @if(!$this->alumniRecords->hasMorePages()) disabled @endif>
                    <i class="fas fa-chevron-right text-[9px]"></i>
                </button>

                <span class="hidden sm:inline text-white/60 text-xs font-normal whitespace-nowrap ml-1">
                    Page {{ $cp }}/{{ $lp }}
                </span>
            </div>
            @endif
        </div>

    </div>{{-- /yb-table-block --}}

    {{-- DIGITAL YEARBOOK PROFILE MODAL --}}
    <template x-if="profileOpen && profileData">
        <div class="yb-modal-overlay"
             x-show="profileOpen"
             x-transition:enter="transition ease-out duration-150"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0">

            <div class="yb-modal-card yb-modal-anim">

                <button type="button" class="yb-modal-close" @click="closeProfile()" :disabled="closing" aria-label="Close">
                    <i class="fas fa-xmark text-sm" x-show="!closing"></i>
                    <i class="fas fa-spinner fa-spin text-sm" x-show="closing" x-cloak></i>
                </button>

                <div class="yb-modal-header">
                    <div class="yb-modal-header-deco"></div>
                    <div class="yb-modal-photo-ring">
                        {{-- Hover-to-change is enabled ONLY when this is the
                             viewer's own card (profileData.isMe, set server-side
                             when the card was opened) — everyone else's photo
                             here is view-only, same rule as the registrar tool. --}}
                        <template x-if="profileData.isMe">
                            <div class="yb-modal-photo-outer">
                                <div class="yb-modal-photo-wrap">
                                    <img :src="photoPreview" :alt="profileData.name"
                                         class="w-full h-full rounded-full object-cover block"
                                         style="background:#f0e6f8;"
                                         onerror="this.src='{{ asset('storage/alumni-photos/default.png') }}'">

                                    <div x-show="savingPhoto" class="yb-modal-photo-uploading">
                                        <i class="fas fa-spinner fa-spin text-white"></i>
                                    </div>

                                    <div x-show="checkingFace" class="yb-modal-photo-uploading">
                                        <div class="flex flex-col items-center gap-1">
                                            <i class="fas fa-spinner fa-spin text-white"></i>
                                            <span style="font-size:9px;font-weight:700;color:#fff;letter-spacing:.03em;">CHECKING…</span>
                                        </div>
                                    </div>

                                    <label x-show="!savingPhoto && !checkingFace" class="yb-modal-photo-overlay">
                                        <i class="fas fa-camera" style="font-size:18px;"></i>
                                        <span>Change photo</span>
                                        <input type="file" x-ref="ybPhotoInput" class="hidden"
                                               accept="image/jpeg,image/png,image/webp" @change="onYbPhotoChange($event)">
                                    </label>
                                </div>

                                {{-- Moved OUTSIDE .yb-modal-photo-wrap on purpose:
                                     that wrap needs overflow:hidden to mask the photo
                                     into a circle, which was also clipping these
                                     buttons (they render half-visible but can't
                                     receive clicks there). This outer div has no
                                     clipping, so the buttons sit fully outside the
                                     circle and are actually clickable. --}}
                                <div class="yb-modal-photo-actions" x-show="hasPendingPhoto && !savingPhoto && !checkingFace">
                                    <button type="button" class="yb-modal-photo-action-btn yb-modal-photo-save-btn"
                                            @click="saveYbPhoto()" title="Save photo">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button type="button" class="yb-modal-photo-action-btn yb-modal-photo-cancel-btn"
                                            @click="cancelYbPhoto()" title="Cancel">
                                        <i class="fas fa-xmark"></i>
                                    </button>
                                </div>
                            </div>
                        </template>
                        <template x-if="!profileData.isMe">
                            <img :src="profileData.photo" :alt="profileData.name"
                                 class="w-full h-full rounded-full object-cover block"
                                 style="background:#f0e6f8;"
                                 onerror="this.src='{{ asset('storage/alumni-photos/default.png') }}'">
                        </template>
                    </div>
                </div>

                <div class="yb-modal-body">
                    <p class="text-lg font-semibold uppercase leading-snug" style="color:#333333;" x-text="profileData.name"></p>

                    {{-- Face-check rejection message — only ever relevant on
                         the viewer's own card, since that's the only place
                         the file input exists. --}}
                    <template x-if="profileData.isMe && faceError">
                        <p class="yb-modal-face-error">
                            <i class="fas fa-triangle-exclamation"></i>
                            <span x-text="faceError"></span>
                        </p>
                    </template>

                    {{-- Digital yearbook info: program on top, batch centered below —
                         no field labels, so this reads like a yearbook caption
                         rather than a form. --}}
                    <div class="yb-modal-info-stack">
                        <p class="yb-modal-program" x-text="profileData.course"></p>
                        <span class="yb-modal-batch-pill">
                            <i class="fas fa-calendar-check" style="font-size:10px;"></i>
                            <span x-text="'Batch ' + profileData.batch"></span>
                        </span>
                    </div>

                    {{-- "We / Our" congratulatory note --}}
                    <div class="yb-modal-note">
                        <p class="yb-modal-note-title">
                            <i class="fas fa-heart mr-1"></i> A Note From PhilCST
                        </p>
                        <p class="yb-modal-note-text">
                            We are so proud of you. From your first day on campus to walking the stage,
                            <span x-text="profileData.name"></span> — this achievement is <em>ours</em> to celebrate too.
                            Congratulations, Alumni! 🎓
                        </p>
                    </div>

                    {{-- Privacy notice: only shows on the viewer's own card --}}
                    <template x-if="profileData.isMe">
                        <span class="yb-modal-private-tag">
                            <i class="fas fa-lock"></i> Only you can see this note
                        </span>
                    </template>
                </div>
            </div>
        </div>
    </template>

</div>{{-- /main layout --}}