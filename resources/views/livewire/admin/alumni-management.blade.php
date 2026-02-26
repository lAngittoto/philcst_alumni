@extends('layouts.sidebar-admin')
@section('content')

<input type="hidden" id="global-csrf-token" value="{{ csrf_token() }}">

<style>
/* ============================================================
   ALUMNI MANAGEMENT — MODERN CAPSTONE UI
   ============================================================ */
:root {
    --brand:      #7a3f91;
    --brand-dark: #622f75;
    --brand-lite: #f3eaf7;
    --brand-ring: rgba(122,63,145,.18);
    --success:    #059669;
    --warning:    #d97706;
    --danger:     #dc2626;
    --gray-50:    #f9fafb;
    --gray-100:   #f3f4f6;
    --gray-200:   #e5e7eb;
    --gray-300:   #d1d5db;
    --gray-500:   #6b7280;
    --gray-700:   #374151;
    --gray-900:   #111827;
    --radius:     10px;
    --shadow-sm:  0 1px 3px rgba(0,0,0,.08),0 1px 2px rgba(0,0,0,.05);
    --shadow-md:  0 4px 12px rgba(0,0,0,.10);
    --shadow-lg:  0 10px 30px rgba(0,0,0,.12);
    --shadow-xl:  0 20px 50px rgba(0,0,0,.16);
}

/* ── LAYOUT ── */
.am-root {
    display: flex;
    flex-direction: column;
    height: calc(100vh - 0px);
    overflow: hidden;
    background: var(--gray-50);
    font-family: 'Inter', system-ui, sans-serif;
    padding: 20px 24px 16px;
    gap: 14px;
    box-sizing: border-box;
}

/* ── HEADER ── */
.am-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
}
.am-header-left { display: flex; align-items: center; gap: 12px; }
.am-header-icon {
    width: 42px; height: 42px;
    background: var(--brand);
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 18px;
    box-shadow: 0 4px 14px var(--brand-ring);
    flex-shrink: 0;
}
.am-title { font-size: 1.5rem; font-weight: 800; color: var(--gray-900); line-height: 1.2; }
.am-subtitle { font-size: 0.88rem; color: var(--gray-500); margin-top: 2px; }
.am-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }

/* ── BUTTONS ── */
.btn {
    display: inline-flex; align-items: center; gap: 7px;
    font-size: 0.9rem; font-weight: 700;
    border-radius: var(--radius); cursor: pointer;
    border: none; transition: all .15s ease;
    white-space: nowrap; padding: 10px 18px;
    text-decoration: none; line-height: 1;
}
.btn:focus-visible { outline: 3px solid var(--brand-ring); }
.btn-primary {
    background: var(--brand); color: #fff;
    box-shadow: 0 2px 8px var(--brand-ring);
}
.btn-primary:hover { background: var(--brand-dark); transform: translateY(-1px); box-shadow: 0 4px 14px var(--brand-ring); }
.btn-outline {
    background: #fff; color: var(--brand);
    border: 1.5px solid var(--brand);
}
.btn-outline:hover { background: var(--brand-lite); }
.btn-ghost {
    background: #fff; color: var(--gray-700);
    border: 1.5px solid var(--gray-200);
    box-shadow: var(--shadow-sm);
}
.btn-ghost:hover { background: var(--gray-100); border-color: var(--brand); color: var(--brand); }

/* ── TABS ── */
.am-tabs {
    display: flex; gap: 4px;
    background: #fff;
    border: 1.5px solid var(--gray-200);
    border-radius: var(--radius);
    padding: 5px;
    flex-shrink: 0;
    align-self: flex-start;
}
.am-tab {
    display: flex; align-items: center; gap: 7px;
    padding: 9px 20px;
    font-size: 0.9rem; font-weight: 700;
    color: var(--gray-500);
    border-radius: 7px;
    cursor: pointer;
    border: none;
    background: transparent;
    transition: all .15s ease;
}
.am-tab:hover { color: var(--brand); background: var(--brand-lite); }
.am-tab.active {
    background: var(--brand); color: #fff;
    box-shadow: 0 2px 8px var(--brand-ring);
}
.am-tab-count {
    padding: 2px 9px; border-radius: 20px; font-size: 0.78rem; font-weight: 800;
    background: rgba(255,255,255,.22); color: inherit;
}
.am-tab.active .am-tab-count { background: rgba(255,255,255,.25); }
.am-tab:not(.active) .am-tab-count { background: var(--gray-100); color: var(--gray-600); }

/* ── PANEL (card wrapping filter+table+pagination) ── */
.am-panel {
    flex: 1;
    min-height: 0;
    background: #fff;
    border: 1.5px solid var(--gray-200);
    border-radius: 14px;
    box-shadow: var(--shadow-sm);
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

/* ── FILTER BAR ── */
.am-filters {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 16px;
    border-bottom: 1.5px solid var(--gray-100);
    background: var(--gray-50);
    flex-wrap: nowrap;
    flex-shrink: 0;
}
.filter-search-wrap {
    position: relative; flex: 1; min-width: 160px; max-width: 240px;
}
.filter-search-wrap svg {
    position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
    color: var(--gray-400); pointer-events: none;
}
.fi {
    height: 38px; font-size: 0.88rem; font-weight: 500;
    border: 1.5px solid var(--gray-200); border-radius: 8px;
    background: #fff; color: var(--gray-700); outline: none;
    transition: border-color .15s, box-shadow .15s;
    padding: 0 11px;
}
.fi:focus { border-color: var(--brand); box-shadow: 0 0 0 3px var(--brand-ring); }
.fi-search { padding-left: 32px; width: 100%; }
.fi-select { padding: 0 28px 0 10px; min-width: 100px; appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 8px center;
}
.fi-wide { min-width: 180px; max-width: 220px; }
.filters-right { margin-left: auto; flex-shrink: 0; }

/* ── TABLE ── */
.am-table-wrap {
    flex: 1; overflow-y: auto; overflow-x: auto; min-height: 0;
}
.am-table-wrap::-webkit-scrollbar { width: 5px; height: 5px; }
.am-table-wrap::-webkit-scrollbar-track { background: transparent; }
.am-table-wrap::-webkit-scrollbar-thumb { background: var(--gray-200); border-radius: 10px; }
.am-table {
    width: 100%; border-collapse: collapse; font-size: 0.92rem;
    table-layout: auto;
}
.am-table thead { position: sticky; top: 0; z-index: 10; }
.am-table thead th {
    background: #7a3f91;
    color: rgba(255,255,255,.95);
    padding: 12px 20px;
    font-size: 0.72rem; font-weight: 800; text-transform: uppercase; letter-spacing: .06em;
    white-space: nowrap;
    text-align: left;
    vertical-align: middle;
}
.am-table thead th.tc { text-align: center; }
.am-table thead th.tr { text-align: right; }
.am-table tbody tr {
    border-bottom: 1px solid var(--gray-100);
    transition: background .1s;
}
.am-table tbody tr:hover { background: #faf6fb; }
.am-table tbody td { padding: 8px 20px; vertical-align: middle; line-height: 1.3; text-align: left; }
.am-table .tc { text-align: center; }
.am-table .tr { text-align: right; }

/* user cell */
.user-cell { display: flex; align-items: center; gap: 11px; min-width: 0; }
.avatar {
    width: 36px; height: 36px; border-radius: 8px;
    object-fit: cover; border: 2px solid var(--brand-lite);
    flex-shrink: 0; display: block;
    background: var(--brand-lite);
}
.avatar-letter {
    width: 36px; height: 36px; min-width: 36px; border-radius: 8px;
    background: var(--brand-lite); color: var(--brand);
    display: flex; align-items: center; justify-content: center;
    font-weight: 800; font-size: 0.85rem; flex-shrink: 0;
    border: 2px solid var(--brand-lite); line-height: 1;
}
.user-name { font-weight: 600; font-size: 0.92rem; color: var(--gray-900); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 200px; }
.mono { font-family: 'SF Mono', 'Fira Code', monospace; font-size: 0.88rem; font-weight: 600; color: var(--gray-700); }
.muted { color: var(--gray-600); font-size: 0.88rem; }

/* badges */
.badge {
    display: inline-flex; align-items: center;
    padding: 4px 12px; border-radius: 6px;
    font-size: 0.75rem; font-weight: 800; letter-spacing: .04em;
    text-transform: uppercase; white-space: nowrap;
}
.badge-brand  { background: var(--brand-lite); color: var(--brand); }
.badge-ok     { background: #d1fae5; color: #065f46; }
.badge-warn   { background: #fef3c7; color: #92400e; }
.badge-danger { background: #fee2e2; color: #991b1b; }
.badge-gray   { background: var(--gray-100); color: var(--gray-600); }

/* action buttons */
.act-btns { display: flex; gap: 6px; justify-content: center; }
.act-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 34px; height: 34px; border-radius: 8px;
    border: 1.5px solid transparent;
    cursor: pointer; font-size: 0.82rem; font-weight: 700;
    transition: all .15s; background: transparent;
}
.act-btn-edit {
    background: #eff6ff; color: #2563eb; border-color: #bfdbfe;
}
.act-btn-edit:hover { background: #dbeafe; }
.act-btn-del {
    background: #fff5f5; color: var(--danger); border-color: #fecaca;
}
.act-btn-del:hover { background: #fee2e2; }

/* empty state */
.empty-cell { padding: 48px 0 !important; text-align: center !important; color: var(--gray-400); }
.empty-cell i { font-size: 2.5rem; display: block; margin-bottom: 10px; opacity: .4; }
.empty-cell p { font-weight: 700; font-size: 0.9rem; }
.empty-cell span { font-size: 0.77rem; }

/* ── PAGINATION ── */
.am-pagination {
    display: flex; align-items: center; justify-content: space-between;
    padding: 10px 16px;
    border-top: 1.5px solid var(--gray-100);
    background: var(--gray-50);
    flex-shrink: 0;
}
.pag-info { font-size: 0.85rem; color: var(--gray-500); font-weight: 500; }
.pag-controls { display: flex; gap: 3px; align-items: center; }
.pag-btn {
    padding: 6px 13px; border-radius: 6px; font-size: 0.82rem; font-weight: 700;
    border: 1.5px solid var(--gray-200); background: #fff; color: var(--gray-700);
    cursor: pointer; transition: all .12s;
}
.pag-btn:hover { border-color: var(--brand); color: var(--brand); background: var(--brand-lite); }
.pag-btn.active { background: var(--brand); color: #fff; border-color: var(--brand); }
.pag-btn.disabled { color: var(--gray-300); cursor: not-allowed; pointer-events: none; }

/* ── FLASH ── */
.flash-container {
    position: fixed; top: 20px; right: 20px; z-index: 9999;
    display: flex; flex-direction: column; gap: 8px; width: 360px; pointer-events: none;
}
.flash-msg {
    pointer-events: auto;
    display: flex; align-items: flex-start; gap: 12px;
    padding: 14px 16px;
    border-radius: 12px;
    box-shadow: 0 8px 24px rgba(0,0,0,.12), 0 2px 6px rgba(0,0,0,.08);
    font-size: 0.82rem; font-weight: 600;
    border-left: 4px solid transparent;
    transform: translateX(20px); opacity: 0;
    transition: all .28s cubic-bezier(.34,1.56,.64,1);
    background: #fff;
}
.flash-msg.show { transform: translateX(0); opacity: 1; }
.flash-msg.flash-success { border-left-color: var(--success); }
.flash-msg.flash-error   { border-left-color: var(--danger); }
.flash-msg.flash-warning { border-left-color: var(--warning); }
.flash-icon { font-size: 1rem; flex-shrink: 0; margin-top: 1px; }
.flash-success .flash-icon { color: var(--success); }
.flash-error   .flash-icon { color: var(--danger); }
.flash-warning .flash-icon { color: var(--warning); }
.flash-text { flex: 1; line-height: 1.5; }
.flash-title { font-weight: 800; font-size: 0.83rem; }
.flash-sub { font-size: 0.76rem; color: var(--gray-500); margin-top: 2px; }
.flash-close {
    background: none; border: none; cursor: pointer;
    color: var(--gray-400); font-size: 1rem; padding: 0; line-height: 1;
    flex-shrink: 0; transition: color .1s;
}
.flash-close:hover { color: var(--gray-700); }

/* ── MODAL ── */
.modal-backdrop {
    position: fixed; inset: 0; z-index: 50;
    background: rgba(0,0,0,.45); backdrop-filter: blur(4px);
    display: flex; align-items: center; justify-content: center;
    padding: 16px;
}
.modal-box {
    background: #fff; border-radius: 16px;
    box-shadow: var(--shadow-xl);
    width: 100%; max-width: 600px;
    max-height: 92vh; overflow: hidden;
    display: flex; flex-direction: column;
    animation: modalIn .22s cubic-bezier(.34,1.56,.64,1);
}
.modal-box-sm { max-width: 420px; }
.modal-box-wide { max-width: 680px; }
@keyframes modalIn {
    from { opacity: 0; transform: scale(.94) translateY(10px); }
    to   { opacity: 1; transform: scale(1) translateY(0); }
}
.modal-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 20px 24px;
    background: var(--brand);
    color: #fff; flex-shrink: 0;
}
.modal-header-info h2 { font-size: 1.1rem; font-weight: 800; display: flex; align-items: center; gap: 8px; }
.modal-header-info p  { font-size: 0.72rem; color: rgba(255,255,255,.72); margin-top: 2px; }
.modal-close {
    background: rgba(255,255,255,.15); border: none;
    width: 30px; height: 30px; border-radius: 7px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; color: #fff; font-size: 1rem;
    transition: background .15s; flex-shrink: 0;
}
.modal-close:hover { background: rgba(255,255,255,.25); }
.modal-body {
    flex: 1; overflow-y: auto; padding: 24px;
    display: flex; flex-direction: column; gap: 16px;
}
.modal-body::-webkit-scrollbar { width: 5px; }
.modal-body::-webkit-scrollbar-thumb { background: var(--gray-200); border-radius: 10px; }
.modal-footer {
    padding: 16px 24px;
    border-top: 1.5px solid var(--gray-100);
    display: flex; gap: 10px; flex-shrink: 0;
}

/* form fields */
.form-row { display: grid; gap: 14px; }
.form-row-2 { grid-template-columns: 1fr 1fr; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-label {
    font-size: 0.75rem; font-weight: 800; text-transform: uppercase;
    letter-spacing: .07em; color: var(--gray-700);
    display: flex; align-items: center; gap: 5px;
}
.form-label i { color: var(--brand); }
.form-input {
    height: 44px; padding: 0 14px;
    border: 1.5px solid var(--gray-200);
    border-radius: 8px; font-size: 0.92rem; font-weight: 500;
    background: var(--gray-50); color: var(--gray-900);
    outline: none; transition: border-color .15s, box-shadow .15s; width: 100%;
    box-sizing: border-box;
}
.form-input:focus { border-color: var(--brand); box-shadow: 0 0 0 3px var(--brand-ring); background: #fff; }
.form-input.err { border-color: var(--danger); }
.form-input:disabled { background: var(--gray-100); color: var(--gray-400); cursor: not-allowed; }
.form-select { appearance: none; padding-right: 32px;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236b7280' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center; background-color: var(--gray-50);
}
.form-select:focus { background-color: #fff; }
.form-hint { font-size: 0.7rem; color: var(--gray-500); display: flex; align-items: center; gap: 4px; }

/* photo upload area */
.photo-upload {
    display: flex; align-items: center; gap: 14px;
    padding: 14px 16px;
    background: var(--brand-lite); border-radius: 10px;
    border: 1.5px dashed #c4b5fd;
}
.photo-icon {
    width: 52px; height: 52px; border-radius: 10px;
    background: var(--brand); color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.3rem; flex-shrink: 0;
}
.photo-info p { font-weight: 700; font-size: 0.82rem; color: var(--gray-900); }
.photo-info span { font-size: 0.72rem; color: var(--gray-500); }
.photo-info input[type=file] {
    margin-top: 6px; font-size: 0.72rem;
}
.photo-info input::file-selector-button {
    padding: 4px 10px; border-radius: 6px; border: none;
    background: var(--brand); color: #fff; font-size: 0.72rem; font-weight: 700;
    cursor: pointer; margin-right: 8px; transition: background .15s;
}
.photo-info input::file-selector-button:hover { background: var(--brand-dark); }

/* error block */
.err-block {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 12px 14px; background: #fff5f5; border-left: 4px solid var(--danger);
    border-radius: 8px; font-size: 0.8rem; color: #991b1b;
}
.err-block i { color: var(--danger); font-size: 0.95rem; flex-shrink: 0; margin-top: 1px; }
.err-block ul { list-style: disc; padding-left: 16px; margin-top: 4px; }
.err-block ul li { margin-bottom: 2px; }

/* btn full & ghost-cancel */
.btn-full { flex: 1; justify-content: center; height: 48px; font-size: 0.95rem; }
.btn-cancel {
    padding: 0 22px; height: 48px; font-size: 0.95rem; font-weight: 700;
    background: var(--gray-100); color: var(--gray-700); border: none;
    border-radius: var(--radius); cursor: pointer; transition: background .15s; white-space: nowrap;
}
.btn-cancel:hover { background: var(--gray-200); }

/* ── MANAGE COURSES MODAL specific ── */
.mc-form-area {
    background: var(--brand-lite); border-radius: 10px;
    border: 1.5px solid #c4b5fd; padding: 16px;
}
.mc-list { display: flex; flex-direction: column; gap: 6px; max-height: 260px; overflow-y: auto; padding-right: 4px; }
.mc-list::-webkit-scrollbar { width: 4px; }
.mc-list::-webkit-scrollbar-thumb { background: var(--gray-200); border-radius: 10px; }
.mc-item {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px; border-radius: 8px;
    border: 1.5px solid var(--gray-200);
    background: #fff; transition: all .12s;
}
.mc-item:hover { border-color: var(--brand); }
.mc-item.editing { border-color: var(--brand); background: #f5f3ff; }
.mc-item-badge {
    padding: 3px 10px; border-radius: 6px;
    background: var(--brand-lite); color: var(--brand);
    font-size: 0.68rem; font-weight: 800; text-transform: uppercase;
    white-space: nowrap; flex-shrink: 0;
}
.mc-item-name { font-size: 0.8rem; font-weight: 600; color: var(--gray-700); flex: 1; min-width: 0; truncate; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mc-item-actions { display: flex; gap: 5px; flex-shrink: 0; }

/* ── DELETE MODAL ── */
.del-icon-wrap {
    width: 60px; height: 60px; border-radius: 50%;
    background: #fee2e2; display: flex; align-items: center; justify-content: center;
    margin: 0 auto 16px;
}
.del-icon-wrap i { font-size: 1.6rem; color: var(--danger); }

/* ── IMPORT MODAL ── */
.import-dropzone {
    border: 2px dashed #c4b5fd; border-radius: 10px;
    background: var(--brand-lite);
    padding: 32px 20px; text-align: center; cursor: pointer;
    transition: border-color .15s;
}
.import-dropzone:hover { border-color: var(--brand); }
.import-dropzone i { font-size: 2.2rem; color: var(--brand); display: block; margin-bottom: 8px; }
.import-dropzone p { font-weight: 700; font-size: 0.85rem; color: var(--gray-800); }
.import-dropzone span { font-size: 0.72rem; color: var(--gray-500); }
.import-note {
    background: #eff6ff; border: 1.5px solid #bfdbfe; border-radius: 8px;
    padding: 10px 14px; font-size: 0.77rem; color: #1e40af;
}

/* ── Alpine cloak ── */
[x-cloak] { display: none !important; }

/* ── Responsive ── */
@media (max-width: 768px) {
    .am-root { padding: 12px; gap: 10px; }
    .form-row-2 { grid-template-columns: 1fr; }
    .am-filters { flex-wrap: wrap; }
    .fi-wide { min-width: 140px; }
    .am-header { flex-direction: column; align-items: flex-start; gap: 10px; }
}
</style>

{{-- ============================================================
     FLASH CONTAINER
     ============================================================ --}}
<div id="flash-container" class="flash-container"></div>

{{-- ============================================================
     MAIN ROOT
     ============================================================ --}}
<div class="am-root" x-data="manageCourses()">

    {{-- ── HEADER ── --}}
    <div class="am-header">
        <div class="am-header-left">
            <div class="am-header-icon">
                <i class="fa-solid fa-users"></i>
            </div>
            <div>
                <div class="am-title">Alumni &amp; Organizers</div>
                <div class="am-subtitle">Manage database records</div>
            </div>
        </div>
        <div class="am-actions">
            <button onclick="openModal('registerAlumniModal')" class="btn btn-primary">
                <i class="fa-solid fa-user-plus"></i> Register Alumni
            </button>
            <button onclick="openModal('registerOrganizerModal')" class="btn btn-primary">
                <i class="fa-solid fa-users-gear"></i> Register Organizer
            </button>
            <button onclick="openModal('importModal')" class="btn btn-outline">
                <i class="fa-solid fa-file-import"></i> Import
            </button>
        </div>
    </div>

    {{-- ── TABS ── --}}
    <div class="am-tabs">
        <button id="alumniTab" onclick="showTab('alumni')" class="am-tab active">
            <i class="fa-solid fa-graduation-cap"></i>
            Alumni
            <span class="am-tab-count">{{ $totalAlumni }}</span>
        </button>
        <button id="organizerTab" onclick="showTab('organizers')" class="am-tab">
            <i class="fa-solid fa-users-gear"></i>
            Organizers
            <span class="am-tab-count" id="orgTabCount">{{ count($organizers) }}</span>
        </button>
    </div>

    {{-- ══════════ ALUMNI PANEL ══════════ --}}
    <div id="alumni" class="am-panel">

        {{-- Filters --}}
        <div class="am-filters">
            <div class="filter-search-wrap">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="search" id="alumniSearch" placeholder="Name, ID, email…"
                       oninput="debounce(applyAlumniFilters, 350)()"
                       class="fi fi-search">
            </div>
            <select id="alumniFilterBatch" onchange="applyAlumniFilters()" class="fi fi-select">
                <option value="">All Years</option>
                @foreach($batches as $year)
                    <option value="{{ $year }}">{{ $year }}</option>
                @endforeach
            </select>
            <select id="alumniFilterCourse" onchange="applyAlumniFilters()" class="fi fi-select fi-wide">
                <option value="">All Courses</option>
                @foreach($courses as $c)
                    <option value="{{ $c->code }}">{{ $c->code }} — {{ $c->name }}</option>
                @endforeach
            </select>
            <select id="alumniFilterSort" onchange="applyAlumniFilters()" class="fi fi-select">
                <option value="recent">Recent First</option>
                <option value="oldest">Oldest First</option>
            </select>
            <div class="filters-right" style="display:flex;gap:6px;align-items:center;">
                <button type="button" onclick="resetAlumniFilters()" class="btn btn-ghost" style="height:38px;padding:0 14px;font-size:0.85rem;" title="Reset filters">
                    <i class="fa-solid fa-rotate-left"></i> Reset
                </button>
                <button type="button" @click="openManageCourses()" class="btn btn-ghost" style="height:38px;padding:0 14px;font-size:0.85rem;">
                    <i class="fa-solid fa-sliders"></i> Manage Courses
                </button>
            </div>
        </div>

        {{-- Table --}}
        <div class="am-table-wrap">
            <table class="am-table ">
                <thead>
                    <tr>
                        <th style="min-width:200px;">Alumnus</th>
                        <th style="min-width:130px;">Student ID</th>
                        <th style="min-width:120px;">Course</th>
                        <th class="tc" style="min-width:90px;">Batch</th>
                        <th style="min-width:200px;">Email</th>
                        <th class="tc" style="min-width:110px;">Status</th>
                        <th class="tc" style="min-width:100px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="alumniTableBody">
                    @forelse($alumni as $item)
                    <tr>
                        <td>
                            <div class="user-cell">
                                @php
                                    $photoSrc = ($item->profile_photo && Storage::disk('public')->exists($item->profile_photo))
                                        ? asset('storage/' . $item->profile_photo)
                                        : asset('storage/alumni-photos/default.png');
                                @endphp
                                <img src="{{ $photoSrc }}" class="avatar" alt="{{ $item->name }}" loading="lazy"
                                     onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';">
                                <div class="avatar-letter" style="display:none;">{{ strtoupper(substr($item->name,0,1)) }}</div>
                                <span class="user-name">{{ $item->name }}</span>
                            </div>
                        </td>
                        <td><span class="mono">{{ $item->student_id }}</span></td>
                        <td><span class="badge badge-brand">{{ $item->course_code }}</span></td>
                        <td class="tc"><span class="mono">{{ $item->batch }}</span></td>
                        <td><span class="muted">{{ $item->email }}</span></td>
                        <td class="tc">
                            @php
                                $sc = ['VERIFIED'=>'badge-ok','PENDING'=>'badge-warn','REJECTED'=>'badge-danger'];
                            @endphp
                            <span class="badge {{ $sc[$item->status] ?? 'badge-gray' }}">{{ $item->status }}</span>
                        </td>
                        <td>
                            <div class="act-btns">
                                <button type="button"
                                    data-id="{{ $item->id }}"
                                    data-name="{{ $item->name }}"
                                    data-student-id="{{ $item->student_id }}"
                                    data-email="{{ $item->email }}"
                                    data-batch="{{ $item->batch }}"
                                    data-course-code="{{ $item->course_code }}"
                                    onclick="openEditAlumni(this)"
                                    class="act-btn act-btn-edit" title="Edit">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </button>
                                <button type="button"
                                    data-delete-url="{{ route('alumni.destroy', $item->id) }}"
                                    data-delete-name="{{ $item->name }}"
                                    data-delete-type="alumni"
                                    onclick="showDeleteConfirm(this.dataset.deleteUrl, this.dataset.deleteName, this.dataset.deleteType)"
                                    class="act-btn act-btn-del" title="Delete">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="empty-cell">
                            <i class="fa-solid fa-users"></i>
                            <p>No alumni records found</p>
                            <span>Try adjusting your filters or register a new alumnus</span>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        <div class="am-pagination">
            <span class="pag-info" id="alumniPaginationInfo">
                Showing {{ $alumni->firstItem() ?? 0 }}–{{ $alumni->lastItem() ?? 0 }} of {{ $alumni->total() }} entries
            </span>
            <div id="alumniPagination" class="pag-controls">
                @if($alumni->onFirstPage())
                    <span class="pag-btn disabled">Prev</span>
                @else
                    <button onclick="fetchAlumniPage({{ $alumni->currentPage()-1 }})" class="pag-btn">Prev</button>
                @endif
                @foreach($alumni->getUrlRange(max(1,$alumni->currentPage()-3),min($alumni->lastPage(),$alumni->currentPage()+3)) as $page=>$url)
                    @if($page==$alumni->currentPage())
                        <span class="pag-btn active">{{ $page }}</span>
                    @else
                        <button onclick="fetchAlumniPage({{ $page }})" class="pag-btn">{{ $page }}</button>
                    @endif
                @endforeach
                @if($alumni->hasMorePages())
                    <button onclick="fetchAlumniPage({{ $alumni->currentPage()+1 }})" class="pag-btn">Next</button>
                @else
                    <span class="pag-btn disabled">Next</span>
                @endif
            </div>
        </div>
    </div>

    {{-- ══════════ ORGANIZERS PANEL ══════════ --}}
    <div id="organizers" class="am-panel" style="display:none;">

        {{-- Filters --}}
        <div class="am-filters">
            <div class="filter-search-wrap">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="search" id="orgSearch" placeholder="Name, ID, email…"
                       oninput="debounce(applyOrganizerFilters, 350)()"
                       class="fi fi-search">
            </div>
            <select id="orgFilterDept" onchange="applyOrganizerFilters()" class="fi fi-select fi-wide">
                <option value="">All Departments</option>
                @foreach($courses as $c)
                    @if($departments->contains($c->code))
                        <option value="{{ $c->code }}">{{ $c->code }} — {{ $c->name }}</option>
                    @endif
                @endforeach
            </select>
            <select id="orgFilterSort" onchange="applyOrganizerFilters()" class="fi fi-select">
                <option value="recent">Recent First</option>
                <option value="oldest">Oldest First</option>
            </select>
            <div class="filters-right" style="display:flex;gap:6px;align-items:center;">
                <button type="button" onclick="resetOrgFilters()" class="btn btn-ghost" style="height:38px;padding:0 14px;font-size:0.85rem;" title="Reset filters">
                    <i class="fa-solid fa-rotate-left"></i> Reset
                </button>
                <button type="button" @click="openManageCourses()" class="btn btn-ghost" style="height:38px;padding:0 14px;font-size:0.85rem;">
                    <i class="fa-solid fa-sliders"></i> Manage Courses
                </button>
            </div>
        </div>

        {{-- Table --}}
        <div class="am-table-wrap">
            <table class="am-table">
                <thead>
                    <tr>
                        <th style="min-width:200px;">Organizer</th>
                        <th style="min-width:140px;">ID Number</th>
                        <th style="min-width:200px;">Email</th>
                        <th style="min-width:140px;">Department</th>
                        <th class="tc" style="min-width:120px;">Status</th>
                        <th class="tc" style="min-width:110px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="orgTableBody">
                    @forelse($organizers as $item)
                    <tr>
                        <td>
                            <div class="user-cell">
                                @php
                                    $photoSrc = ($item->profile_photo && Storage::disk('public')->exists($item->profile_photo))
                                        ? asset('storage/' . $item->profile_photo)
                                        : asset('storage/alumni-photos/default.png');
                                @endphp
                                <img src="{{ $photoSrc }}" class="avatar" alt="{{ $item->name }}" loading="lazy"
                                     onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';">
                                <div class="avatar-letter" style="display:none;">{{ strtoupper(substr($item->name,0,1)) }}</div>
                                <span class="user-name">{{ $item->name }}</span>
                            </div>
                        </td>
                        <td><span class="mono">{{ $item->id_number }}</span></td>
                        <td><span class="muted">{{ $item->email }}</span></td>
                        <td><span class="badge badge-brand">{{ $item->department }}</span></td>
                        <td class="tc">
                            @php $os=['ACTIVE'=>'badge-ok','INACTIVE'=>'badge-warn','SUSPENDED'=>'badge-danger']; @endphp
                            <span class="badge {{ $os[$item->status] ?? 'badge-gray' }}">{{ $item->status }}</span>
                        </td>
                        <td>
                            <div class="act-btns">
                                <button type="button"
                                    data-id="{{ $item->id }}"
                                    data-name="{{ $item->name }}"
                                    data-email="{{ $item->email }}"
                                    data-id-number="{{ $item->id_number }}"
                                    data-department="{{ $item->department }}"
                                    onclick="openEditOrganizer(this)"
                                    class="act-btn act-btn-edit" title="Edit">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </button>
                                <button type="button"
                                    data-delete-url="{{ route('organizers.destroy', $item->id) }}"
                                    data-delete-name="{{ $item->name }}"
                                    data-delete-type="organizer"
                                    onclick="showDeleteConfirm(this.dataset.deleteUrl, this.dataset.deleteName, this.dataset.deleteType)"
                                    class="act-btn act-btn-del" title="Delete">
                                    <i class="fa-solid fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="6" class="empty-cell">
                            <i class="fa-solid fa-users-gear"></i>
                            <p>No organizer records found</p>
                            <span>Register an organizer to get started</span>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        <div class="am-pagination">
            <span class="pag-info" id="orgPaginationInfo">Showing 1–{{ count($organizers) }} of {{ count($organizers) }} entries</span>
            <div id="orgPagination" class="pag-controls"></div>
        </div>
    </div>

    {{-- ══════════════════════════════════════
         REGISTER ALUMNI MODAL
    ══════════════════════════════════════ --}}
    <div id="registerAlumniModal" style="display:none;" class="modal-backdrop">
        <div class="modal-box" onclick="event.stopPropagation()">
            <div class="modal-header">
                <div class="modal-header-info">
                    <h2><i class="fa-solid fa-user-plus"></i> Register New Alumni</h2>
                    <p>Add a student to the alumni database</p>
                </div>
                <button class="modal-close" onclick="closeModal('registerAlumniModal')" type="button"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form id="registerAlumniForm" action="{{ route('alumni.store') }}" method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    @if($errors->registerAlumni->any())
                    <div class="err-block">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <div>
                            <strong>Please fix the following:</strong>
                            <ul>
                                @foreach($errors->registerAlumni->all() as $e)<li>{{ $e }}</li>@endforeach
                            </ul>
                        </div>
                    </div>
                    @endif
                    @csrf
                    {{-- Photo --}}
                    <div class="photo-upload">
                        <div class="photo-icon"><i class="fa-solid fa-camera"></i></div>
                        <div class="photo-info">
                            <p>Profile Photo <span style="font-weight:400;color:var(--gray-500)">(optional)</span></p>
                            <span>JPG, PNG, WebP — max 5MB</span>
                            <input type="file" name="profile_photo" accept="image/*">
                        </div>
                    </div>
                    {{-- Name + Student ID --}}
                    <div class="form-row form-row-2">
                        <div class="form-group">
                            <label class="form-label"><i class="fa-solid fa-user"></i> Full Name *</label>
                            <input type="text" name="name" value="{{ old('name') }}" placeholder="Juan M. Dela Cruz" required
                                   class="form-input {{ $errors->registerAlumni->has('name') ? 'err' : '' }}">
                            @error('name', 'registerAlumni')<span style="font-size:0.78rem;color:var(--danger);margin-top:2px;">{{ $message }}</span>@enderror
                        </div>
                        <div class="form-group">
                            <label class="form-label"><i class="fa-solid fa-id-card"></i> Student ID (8 digits) *</label>
                            <input type="text" name="student_id" value="{{ old('student_id') }}" placeholder="20210001"
                                   required maxlength="8" pattern="\d{8}" inputmode="numeric"
                                   class="form-input {{ $errors->registerAlumni->has('student_id') ? 'err' : '' }}" style="font-family:monospace">
                            @error('student_id', 'registerAlumni')<span style="font-size:0.78rem;color:var(--danger);margin-top:2px;">{{ $message }}</span>@enderror
                        </div>
                    </div>
                    {{-- Email --}}
                    <div class="form-group">
                        <label class="form-label"><i class="fa-solid fa-envelope"></i> Email Address *</label>
                        <input type="email" name="email" value="{{ old('email') }}" placeholder="student@philcst.edu.ph" required
                               class="form-input {{ $errors->registerAlumni->has('email') ? 'err' : '' }}">
                        @error('email', 'registerAlumni')<span style="font-size:0.78rem;color:var(--danger);margin-top:2px;">{{ $message }}</span>@enderror
                    </div>
                    {{-- Batch + Course --}}
                    <div class="form-row form-row-2">
                        <div class="form-group">
                            <label class="form-label"><i class="fa-solid fa-graduation-cap"></i> Batch / Year *</label>
                            <input type="number" name="batch" value="{{ old('batch', date('Y')) }}" min="2000" max="{{ date('Y') }}" required
                                   class="form-input {{ $errors->registerAlumni->has('batch') ? 'err' : '' }}">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><i class="fa-solid fa-book"></i> Course *</label>
                            <select name="course_code" required class="form-input form-select {{ $errors->registerAlumni->has('course_code') ? 'err' : '' }}">
                                <option value="">— Select course —</option>
                                @foreach($courses as $c)
                                    <option value="{{ $c->code }}" {{ old('course_code')===$c->code?'selected':'' }}>{{ $c->code }} — {{ $c->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary btn-full">
                        <i class="fa-solid fa-user-check"></i> Register Alumni
                    </button>
                    <button type="button" class="btn-cancel" onclick="closeModal('registerAlumniModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    {{-- ══════════════════════════════════════
         EDIT ALUMNI MODAL
    ══════════════════════════════════════ --}}
    <div id="editAlumniModal" style="display:none;" class="modal-backdrop">
        <div class="modal-box" onclick="event.stopPropagation()">
            <div class="modal-header">
                <div class="modal-header-info">
                    <h2><i class="fa-solid fa-pen-to-square"></i> Edit Alumni Record</h2>
                    <p>Update alumnus information</p>
                </div>
                <button class="modal-close" onclick="closeModal('editAlumniModal')" type="button"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form id="editAlumniForm" method="POST" enctype="multipart/form-data">
                @csrf @method('PUT')
                <input type="hidden" id="editAlumniEmailHidden" name="email">
                <input type="hidden" id="editAlumniIdHidden" name="_alumni_id">
                <div class="modal-body">
                    @if($errors->editAlumni->any())
                    <div class="err-block">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <div>
                            <strong>Please fix the following:</strong>
                            <ul>
                                @foreach($errors->editAlumni->all() as $e)<li>{{ $e }}</li>@endforeach
                            </ul>
                        </div>
                    </div>
                    @endif
                    <div class="photo-upload">
                        <div class="photo-icon"><i class="fa-solid fa-camera"></i></div>
                        <div class="photo-info">
                            <p>Profile Photo <span style="font-weight:400;color:var(--gray-500)">(optional)</span></p>
                            <span>JPG, PNG, WebP — max 5MB</span>
                            <input type="file" name="profile_photo" accept="image/*">
                        </div>
                    </div>
                    <div class="form-row form-row-2">
                        <div class="form-group">
                            <label class="form-label"><i class="fa-solid fa-user"></i> Full Name *</label>
                            <input type="text" id="editAlumniName" name="name" required class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><i class="fa-solid fa-id-card"></i> Student ID (8 digits) *</label>
                            <input type="text" id="editAlumniStudentId" name="student_id" required maxlength="8" pattern="\d{8}" inputmode="numeric"
                                   class="form-input" style="font-family:monospace">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fa-solid fa-envelope"></i> Email Address</label>
                        <input type="email" id="editAlumniEmailDisplay" disabled class="form-input">
                        <span class="form-hint"><i class="fa-solid fa-lock"></i> Email cannot be changed</span>
                    </div>
                    <div class="form-row form-row-2">
                        <div class="form-group">
                            <label class="form-label"><i class="fa-solid fa-graduation-cap"></i> Batch / Year *</label>
                            <input type="number" id="editAlumniBatch" name="batch" min="2000" max="{{ date('Y') }}" required class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><i class="fa-solid fa-book"></i> Course *</label>
                            <select id="editAlumniCourse" name="course_code" required class="form-input form-select">
                                <option value="">— Select course —</option>
                                @foreach($courses as $c)
                                    <option value="{{ $c->code }}">{{ $c->code }} — {{ $c->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary btn-full"><i class="fa-solid fa-save"></i> Save Changes</button>
                    <button type="button" class="btn-cancel" onclick="closeModal('editAlumniModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    {{-- ══════════════════════════════════════
         REGISTER ORGANIZER MODAL
    ══════════════════════════════════════ --}}
    <div id="registerOrganizerModal" style="display:none;" class="modal-backdrop">
        <div class="modal-box" onclick="event.stopPropagation()">
            <div class="modal-header">
                <div class="modal-header-info">
                    <h2><i class="fa-solid fa-users-gear"></i> Register New Organizer</h2>
                    <p>Create an organizer account</p>
                </div>
                <button class="modal-close" onclick="closeModal('registerOrganizerModal')" type="button"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form id="registerOrganizerForm" action="{{ route('organizers.store') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <div class="modal-body">
                    @if($errors->registerOrganizer->any())
                    <div class="err-block">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <div>
                            <strong>Please fix the following:</strong>
                            <ul>
                                @foreach($errors->registerOrganizer->all() as $e)<li>{{ $e }}</li>@endforeach
                            </ul>
                        </div>
                    </div>
                    @endif
                    <div class="photo-upload">
                        <div class="photo-icon"><i class="fa-solid fa-camera"></i></div>
                        <div class="photo-info">
                            <p>Profile Photo <span style="font-weight:400;color:var(--gray-500)">(optional)</span></p>
                            <span>JPG, PNG, WebP — max 5MB</span>
                            <input type="file" name="org_profile_photo" accept="image/*">
                        </div>
                    </div>
                    <div class="form-row form-row-2">
                        <div class="form-group">
                            <label class="form-label"><i class="fa-solid fa-user"></i> Full Name *</label>
                            <input type="text" name="org_name" value="{{ old('org_name') }}" placeholder="Juan M. Dela Cruz" required
                                   class="form-input {{ $errors->registerOrganizer->has('org_name')?'err':'' }}">
                            @error('org_name', 'registerOrganizer')<span style="font-size:0.78rem;color:var(--danger);margin-top:2px;">{{ $message }}</span>@enderror
                        </div>
                        <div class="form-group">
                            <label class="form-label"><i class="fa-solid fa-id-card"></i> ID Number *</label>
                            <input type="text" name="org_id_number" value="{{ old('org_id_number') }}" required
                                   class="form-input {{ $errors->registerOrganizer->has('org_id_number')?'err':'' }}" style="font-family:monospace">
                            @error('org_id_number', 'registerOrganizer')<span style="font-size:0.78rem;color:var(--danger);margin-top:2px;">{{ $message }}</span>@enderror
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fa-solid fa-envelope"></i> Email Address *</label>
                        <input type="email" name="org_email" value="{{ old('org_email') }}" placeholder="organizer@philcst.edu.ph" required
                               class="form-input {{ $errors->registerOrganizer->has('org_email')?'err':'' }}">
                        @error('org_email', 'registerOrganizer')<span style="font-size:0.78rem;color:var(--danger);margin-top:2px;">{{ $message }}</span>@enderror
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fa-solid fa-building"></i> Department *</label>
                        <select name="org_department" required class="form-input form-select {{ $errors->registerOrganizer->has('org_department')?'err':'' }}">
                            <option value="">— Select department —</option>
                            @foreach($courses as $c)
                                <option value="{{ $c->code }}" {{ old('org_department')===$c->code?'selected':'' }}>{{ $c->code }} — {{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary btn-full"><i class="fa-solid fa-users-gear"></i> Register Organizer</button>
                    <button type="button" class="btn-cancel" onclick="closeModal('registerOrganizerModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    {{-- ══════════════════════════════════════
         EDIT ORGANIZER MODAL
    ══════════════════════════════════════ --}}
    <div id="editOrganizerModal" style="display:none;" class="modal-backdrop">
        <div class="modal-box" onclick="event.stopPropagation()">
            <div class="modal-header">
                <div class="modal-header-info">
                    <h2><i class="fa-solid fa-pen-to-square"></i> Edit Organizer Record</h2>
                    <p>Update organizer information</p>
                </div>
                <button class="modal-close" onclick="closeModal('editOrganizerModal')" type="button"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form id="editOrganizerForm" method="POST" enctype="multipart/form-data">
                @csrf @method('PUT')
                <input type="hidden" id="editOrgEmailHidden" name="org_email">
                <input type="hidden" id="editOrgIdHidden" name="_organizer_id">
                <div class="modal-body">
                    @if($errors->editOrganizer->any())
                    <div class="err-block">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <div>
                            <strong>Please fix the following:</strong>
                            <ul>
                                @foreach($errors->editOrganizer->all() as $e)<li>{{ $e }}</li>@endforeach
                            </ul>
                        </div>
                    </div>
                    @endif
                    <div class="photo-upload">
                        <div class="photo-icon"><i class="fa-solid fa-camera"></i></div>
                        <div class="photo-info">
                            <p>Profile Photo <span style="font-weight:400;color:var(--gray-500)">(optional)</span></p>
                            <span>JPG, PNG, WebP — max 5MB</span>
                            <input type="file" name="org_profile_photo" accept="image/*">
                        </div>
                    </div>
                    <div class="form-row form-row-2">
                        <div class="form-group">
                            <label class="form-label"><i class="fa-solid fa-user"></i> Full Name *</label>
                            <input type="text" id="editOrgName" name="org_name" required class="form-input">
                        </div>
                        <div class="form-group">
                            <label class="form-label"><i class="fa-solid fa-id-card"></i> ID Number *</label>
                            <input type="text" id="editOrgIdNumber" name="org_id_number" required class="form-input" style="font-family:monospace">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fa-solid fa-envelope"></i> Email Address</label>
                        <input type="email" id="editOrgEmailDisplay" disabled class="form-input">
                        <span class="form-hint"><i class="fa-solid fa-lock"></i> Email cannot be changed</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fa-solid fa-building"></i> Department *</label>
                        <select id="editOrgDepartment" name="org_department" required class="form-input form-select">
                            <option value="">— Select —</option>
                            @foreach($courses as $c)
                                <option value="{{ $c->code }}">{{ $c->code }} — {{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary btn-full"><i class="fa-solid fa-save"></i> Save Changes</button>
                    <button type="button" class="btn-cancel" onclick="closeModal('editOrganizerModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    {{-- ══════════════════════════════════════
         IMPORT MODAL
    ══════════════════════════════════════ --}}
    <div id="importModal" style="display:none;" class="modal-backdrop">
        <div class="modal-box modal-box-sm" onclick="event.stopPropagation()">
            <div class="modal-header">
                <div class="modal-header-info">
                    <h2><i class="fa-solid fa-file-import"></i> Import Records</h2>
                    <p>CSV or Excel format</p>
                </div>
                <button class="modal-close" onclick="closeModal('importModal')" type="button"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <form id="importForm" method="POST" enctype="multipart/form-data">
                @csrf
                <div class="modal-body">
                    <div class="import-dropzone" onclick="document.getElementById('fileInput').click()">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        <p>Click to upload</p>
                        <span>CSV, XLS, or XLSX — max 10MB</span>
                        <p id="fileName" style="font-size:0.78rem;font-weight:700;color:var(--brand);margin-top:6px;"></p>
                    </div>
                    <input type="file" id="fileInput" name="file" accept=".csv,.xlsx,.xls" required class="hidden"
                           onchange="document.getElementById('fileName').textContent=this.files[0]?'✓ '+this.files[0].name:''">
                    <div class="import-note">
                        <strong style="display:block;margin-bottom:4px;"><i class="fa-solid fa-circle-info"></i> Required columns:</strong>
                        <code style="font-family:monospace;color:var(--brand);">student_id, name, email, course_code, batch</code>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary btn-full"><i class="fa-solid fa-file-import"></i> Import</button>
                    <button type="button" class="btn-cancel" onclick="closeModal('importModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    {{-- ══════════════════════════════════════
         MANAGE COURSES MODAL (Alpine)
    ══════════════════════════════════════ --}}
    <div x-show="openManageCourseModal" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="modal-backdrop"
         @click.self.prevent>
        <div class="modal-box modal-box-wide" onclick="event.stopPropagation()"
             x-transition:enter="transition ease-out duration-220"
             x-transition:enter-start="opacity-0 scale-95"
             x-transition:enter-end="opacity-100 scale-100">
            <div class="modal-header">
                <div class="modal-header-info">
                    <h2><i class="fa-solid fa-book"></i> Manage Courses</h2>
                    <p>Add, edit, or remove courses from the system</p>
                </div>
                <button @click="openManageCourseModal=false;resetCourseForm()" class="modal-close" type="button"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body">
                {{-- Alert --}}
                <div x-show="courseAlert.show" x-transition
                     :class="courseAlert.type==='success'?'bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800':'bg-red-50 border-l-4 border-red-500 text-red-800'"
                     class="flex items-center justify-between p-3 rounded-lg text-sm font-semibold">
                    <span x-text="courseAlert.message"></span>
                    <button @click="courseAlert.show=false" class="ml-4 opacity-60 hover:opacity-100 text-lg leading-none border-none bg-transparent cursor-pointer">✕</button>
                </div>

                {{-- Form --}}
                <div class="mc-form-area">
                    <div style="font-size:0.77rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:var(--brand);margin-bottom:12px;display:flex;align-items:center;gap:6px;">
                        <i x-show="!editingCourseId" class="fa-solid fa-plus"></i>
                        <i x-show="editingCourseId" class="fa-solid fa-pen-to-square"></i>
                        <span x-text="editingCourseId ? 'Edit Course' : 'Add New Course'"></span>
                    </div>
                    <div class="form-row form-row-2" style="margin-bottom:10px;">
                        <div class="form-group">
                            <label class="form-label">Code *</label>
                            <input type="text" x-model="courseForm.code" placeholder="e.g. BSCS"
                                   @keydown.enter.prevent="saveCourse()"
                                   class="form-input" style="text-transform:uppercase">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Course Name *</label>
                            <input type="text" x-model="courseForm.name" placeholder="Bachelor of Science in..."
                                   @keydown.enter.prevent="saveCourse()"
                                   class="form-input">
                        </div>
                    </div>
                    <div style="display:flex;gap:8px;">
                        <button @click="saveCourse()" :disabled="savingCourse"
                                class="btn btn-primary" style="height:36px;font-size:0.78rem;">
                            <span x-show="!savingCourse && !editingCourseId"><i class="fa-solid fa-plus"></i> Add Course</span>
                            <span x-show="!savingCourse && editingCourseId"><i class="fa-solid fa-save"></i> Update</span>
                            <span x-show="savingCourse"><i class="fa-solid fa-spinner fa-spin"></i> Saving…</span>
                        </button>
                        <button x-show="editingCourseId" @click="resetCourseForm()"
                                class="btn-cancel" style="height:36px;">Cancel</button>
                    </div>
                </div>

                {{-- Course list --}}
                <div>
                    <div style="font-size:0.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--gray-500);margin-bottom:10px;display:flex;align-items:center;gap:6px;">
                        <i class="fa-solid fa-list" style="color:var(--brand)"></i>
                        All Courses (<span x-text="courses.length"></span>)
                    </div>
                    <div class="mc-list">
                        <template x-for="c in courses" :key="c.id">
                            <div class="mc-item" :class="editingCourseId===c.id?'editing':''">
                                <span class="mc-item-badge" x-text="c.code"></span>
                                <span class="mc-item-name" x-text="c.name"></span>
                                <div class="mc-item-actions">
                                    <button @click="openEditCourse(c)" class="act-btn act-btn-edit" title="Edit"><i class="fa-solid fa-pen-to-square"></i></button>
                                    <button @click="deleteCourse(c.id)" class="act-btn act-btn-del" title="Delete"><i class="fa-solid fa-trash"></i></button>
                                </div>
                            </div>
                        </template>
                        <template x-if="courses.length===0">
                            <div style="padding:32px;text-align:center;color:var(--gray-400);font-size:0.82rem;">
                                <i class="fa-solid fa-inbox" style="font-size:2rem;opacity:.3;display:block;margin-bottom:8px;"></i>
                                No courses yet. Add one above!
                            </div>
                        </template>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="justify-content:flex-end;">
                <button @click="openManageCourseModal=false;resetCourseForm()" class="btn-cancel">Done</button>
            </div>
        </div>
    </div>

    {{-- ══════════════════════════════════════
         DELETE CONFIRM MODAL
    ══════════════════════════════════════ --}}
    <div id="deleteConfirmModal" style="display:none;" class="modal-backdrop">
        <div class="modal-box modal-box-sm" onclick="event.stopPropagation()" style="max-width:380px;">
            <div class="modal-body" style="text-align:center;padding:32px 28px;">
                <div class="del-icon-wrap">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <h3 style="font-size:1.05rem;font-weight:800;color:var(--gray-900);margin-bottom:8px;">Delete Record?</h3>
                <p style="font-size:0.82rem;color:var(--gray-500);line-height:1.6;margin-bottom:4px;">
                    You are about to permanently delete<br>
                    <strong id="deleteRecordName" style="color:var(--gray-900);"></strong>
                </p>
                <p id="deleteRecordType" style="font-size:0.76rem;color:var(--gray-400);margin-bottom:0;"></p>
                <p style="font-size:0.76rem;color:var(--danger);font-weight:700;margin-top:6px;">This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeDeleteConfirm()" class="btn-cancel" style="flex:1;">Cancel</button>
                <button type="button" id="deleteConfirmBtn" onclick="executeDelete()"
                        class="btn btn-full" style="flex:1;background:var(--danger);color:#fff;">
                    <i class="fa-solid fa-trash"></i> Delete
                </button>
            </div>
        </div>
    </div>

</div>{{-- end x-data --}}

{{-- ══════════════════════════════════════════════════
     SCRIPTS
══════════════════════════════════════════════════ --}}
<script>
/* ─── UTILS ─── */
let deleteUrl='';

function debounce(fn,d){let t;return function(...a){clearTimeout(t);t=setTimeout(()=>fn.apply(this,a),d);};}

function openModal(id){
    const el=document.getElementById(id);
    if(el){el.style.display='flex';}
}
function closeModal(id){
    const el=document.getElementById(id);
    if(el){el.style.display='none';}
}

/* ─── TABS ─── */
let orgTabLoaded = false;

function showTab(name){
    ['alumni','organizers'].forEach(t=>{
        const el=document.getElementById(t);
        if(el) el.style.display = t===name ? 'flex' : 'none';
    });
    document.querySelectorAll('.am-tab').forEach(b=>{
        b.classList.remove('active');
    });
    const activeId = name==='alumni' ? 'alumniTab' : 'organizerTab';
    const activeBtn = document.getElementById(activeId);
    if(activeBtn) activeBtn.classList.add('active');
    localStorage.setItem('activeTab', name);

    // Fetch organizer AJAX data on first switch to ensure photos + sort are correct
    if(name === 'organizers' && !orgTabLoaded){
        orgTabLoaded = true;
        fetchOrgPage(1);
    }
}

/* ─── ALUMNI FILTERS ─── */
let alumniState={page:1,search:'',batch:'',course:'',sort:'recent'};

function applyAlumniFilters(){
    alumniState.page   = 1;
    alumniState.search = document.getElementById('alumniSearch').value;
    alumniState.batch  = document.getElementById('alumniFilterBatch').value;
    alumniState.course = document.getElementById('alumniFilterCourse').value;
    alumniState.sort   = document.getElementById('alumniFilterSort').value||'recent';
    fetchAlumniPage(1);
}

function fetchAlumniPage(page){
    const p=new URLSearchParams({section:'alumni',page,
        search:alumniState.search,batch:alumniState.batch,
        course:alumniState.course,sort:alumniState.sort});
    fetch('?'+p,{headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(r=>r.json())
        .then(d=>{
            document.getElementById('alumniTableBody').innerHTML=d.tbody;
            document.getElementById('alumniPagination').innerHTML=d.pagination;
            document.getElementById('alumniPaginationInfo').innerHTML=d.info;
            alumniState.page=page;
        }).catch(e=>console.error(e));
}

/* ─── ORGANIZER FILTERS ─── */
let orgState={page:1,search:'',department:'',sort:'recent'};

function applyOrganizerFilters(){
    orgState.page       = 1;
    orgState.search     = document.getElementById('orgSearch').value;
    orgState.department = document.getElementById('orgFilterDept').value;
    orgState.sort       = document.getElementById('orgFilterSort').value||'recent';
    fetchOrgPage(1);
}

function fetchOrgPage(page){
    const p=new URLSearchParams({section:'organizers',page,
        search:orgState.search,department:orgState.department,sort:orgState.sort});
    fetch('?'+p,{headers:{'X-Requested-With':'XMLHttpRequest'}})
        .then(r=>r.json())
        .then(d=>{
            document.getElementById('orgTableBody').innerHTML=d.tbody;
            document.getElementById('orgPagination').innerHTML=d.pagination;
            document.getElementById('orgPaginationInfo').innerHTML=d.info;
            orgState.page=page;
        }).catch(e=>console.error(e));
}

/* ─── RESET FILTERS ─── */
function resetAlumniFilters(){
    document.getElementById('alumniSearch').value='';
    document.getElementById('alumniFilterBatch').value='';
    document.getElementById('alumniFilterCourse').value='';
    document.getElementById('alumniFilterSort').value='recent';
    alumniState={page:1,search:'',batch:'',course:'',sort:'recent'};
    fetchAlumniPage(1);
}

function resetOrgFilters(){
    document.getElementById('orgSearch').value='';
    document.getElementById('orgFilterDept').value='';
    document.getElementById('orgFilterSort').value='recent';
    orgState={page:1,search:'',department:'',sort:'recent'};
    fetchOrgPage(1);
}

/* ─── EDIT ALUMNI ─── */
function openEditAlumni(btn){
    const id=btn.dataset.id;
    document.getElementById('editAlumniForm').action='/alumni/'+id;
    document.getElementById('editAlumniIdHidden').value=id;
    document.getElementById('editAlumniName').value=btn.dataset.name;
    document.getElementById('editAlumniStudentId').value=btn.dataset.studentId;
    document.getElementById('editAlumniEmailHidden').value=btn.dataset.email;
    document.getElementById('editAlumniEmailDisplay').value=btn.dataset.email;
    document.getElementById('editAlumniBatch').value=btn.dataset.batch;
    document.getElementById('editAlumniCourse').value=btn.dataset.courseCode;
    openModal('editAlumniModal');
}

/* ─── EDIT ORGANIZER ─── */
function openEditOrganizer(btn){
    const id=btn.dataset.id;
    document.getElementById('editOrganizerForm').action='/organizers/'+id;
    document.getElementById('editOrgIdHidden').value=id;
    document.getElementById('editOrgName').value=btn.dataset.name;
    document.getElementById('editOrgIdNumber').value=btn.dataset.idNumber;
    document.getElementById('editOrgEmailHidden').value=btn.dataset.email;
    document.getElementById('editOrgEmailDisplay').value=btn.dataset.email;
    document.getElementById('editOrgDepartment').value=btn.dataset.department;
    openModal('editOrganizerModal');
}

/* ─── DELETE ─── */
function showDeleteConfirm(url,name,type){
    deleteUrl=url;
    document.getElementById('deleteRecordName').textContent=name;
    const typeEl=document.getElementById('deleteRecordType');
    if(typeEl) typeEl.textContent = type==='alumni' ? '(Alumni record)' : '(Organizer record)';
    openModal('deleteConfirmModal');
}
function closeDeleteConfirm(){closeModal('deleteConfirmModal');deleteUrl='';}

function executeDelete(){
    if(!deleteUrl) return;
    const token=document.getElementById('global-csrf-token')?.value;
    if(!token){showFlash('error','CSRF token missing. Refresh the page.');return;}
    const btn=document.getElementById('deleteConfirmBtn');
    if(btn){btn.disabled=true;btn.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Deleting…';}

    const recordName = document.getElementById('deleteRecordName')?.textContent || 'Record';
    const recordType = document.getElementById('deleteRecordType')?.textContent || '';

    fetch(deleteUrl,{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
        body:'_token='+encodeURIComponent(token)+'&_method=DELETE'
    }).then(r=>{
        if(r.ok || r.redirected){
            localStorage.setItem('pendingFlash', JSON.stringify({
                type: 'success',
                title: recordName + ' has been deleted.',
                sub: recordType
            }));
            closeModal('deleteConfirmModal');
            window.location.reload();
        } else {
            throw new Error('Status '+r.status);
        }
    }).catch(e=>{
        console.error(e);
        closeModal('deleteConfirmModal');
        showFlash('error','Delete failed. Please try again.');
        if(btn){btn.disabled=false;btn.innerHTML='<i class="fa-solid fa-trash"></i> Delete';}
    });
}

/* ─── IMPORT ─── */
document.getElementById('importForm')?.addEventListener('submit',function(){
    const isOrg=document.getElementById('organizers')?.style.display!=='none';
    this.action=isOrg?'{{ route('organizers.import') }}':'{{ route('alumni.import') }}';
});

/* ─── FLASH NOTIFICATIONS ─── */
function showFlash(type,title,sub=''){
    const c=document.getElementById('flash-container');
    const icons={success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation'};
    const el=document.createElement('div');
    el.className='flash-msg flash-'+type;
    el.innerHTML=`
        <i class="fa-solid ${icons[type]||icons.error} flash-icon"></i>
        <div class="flash-text">
            <div class="flash-title">${title}</div>
            ${sub?`<div class="flash-sub">${sub}</div>`:''}
        </div>
        <button class="flash-close" onclick="this.parentElement.remove()"><i class="fa-solid fa-xmark"></i></button>`;
    c.appendChild(el);
    requestAnimationFrame(()=>el.classList.add('show'));
    setTimeout(()=>{
        el.classList.remove('show');
        setTimeout(()=>el.remove(),300);
    },5000);
}

/* ─── DOM READY ─── */
document.addEventListener('DOMContentLoaded',function(){

    // Show pending flash from delete (stored before reload)
    const pendingFlash = localStorage.getItem('pendingFlash');
    if(pendingFlash){
        try{
            const f = JSON.parse(pendingFlash);
            setTimeout(()=>showFlash(f.type, f.title, f.sub||''), 200);
        }catch(e){}
        localStorage.removeItem('pendingFlash');
    }

    // ── Determine which modal/tab to open based on validation errors ──
    // Priority: check each error bag independently so they don't bleed into each other
    @if($errors->registerAlumni->any())
        showTab('alumni');
        openModal('registerAlumniModal');
    @elseif($errors->editAlumni->any())
        showTab('alumni');
        openModal('editAlumniModal');
        @if(old('_alumni_id'))
            document.getElementById('editAlumniForm').action='/alumni/'+@json(old('_alumni_id'));
            document.getElementById('editAlumniName').value=@json(old('name',''));
            document.getElementById('editAlumniStudentId').value=@json(old('student_id',''));
            document.getElementById('editAlumniEmailHidden').value=@json(old('email',''));
            document.getElementById('editAlumniEmailDisplay').value=@json(old('email',''));
            document.getElementById('editAlumniBatch').value=@json(old('batch',''));
            document.getElementById('editAlumniCourse').value=@json(old('course_code',''));
        @endif
    @elseif($errors->registerOrganizer->any())
        showTab('organizers');
        openModal('registerOrganizerModal');
    @elseif($errors->editOrganizer->any())
        showTab('organizers');
        openModal('editOrganizerModal');
        @if(old('_organizer_id'))
            document.getElementById('editOrganizerForm').action='/organizers/'+@json(old('_organizer_id'));
            document.getElementById('editOrgName').value=@json(old('org_name',''));
            document.getElementById('editOrgIdNumber').value=@json(old('org_id_number',''));
            document.getElementById('editOrgEmailHidden').value=@json(old('org_email',''));
            document.getElementById('editOrgEmailDisplay').value=@json(old('org_email',''));
            document.getElementById('editOrgDepartment').value=@json(old('org_department',''));
        @endif
    @else
        showTab(localStorage.getItem('activeTab')||'alumni');
    @endif

    @if(session('success'))
        showFlash('success', @json(session('success')));
    @endif
    @if(session('warning'))
        showFlash('warning', @json(session('warning')));
    @endif
    @if(session('error'))
        showFlash('error', @json(session('error')));
    @endif

    // Only fetch organizer AJAX data when actually switching to that tab
    // Server already rendered the initial rows with correct photo URLs
});
</script>

<script>
/* ─── ALPINE — MANAGE COURSES ─── */
function manageCourses(){
    return {
        openManageCourseModal: false,
        editingCourseId: null,
        savingCourse: false,
        courses: @json($courses),
        courseAlert: {show:false,type:'success',message:''},
        courseForm: {code:'',name:''},

        openManageCourses(){
            this.openManageCourseModal=true;
        },
        async refreshCourses(){
            try{
                const r=await fetch('/courses',{headers:{
                    'Accept':'application/json',
                    'X-CSRF-TOKEN':document.getElementById('global-csrf-token').value
                }});
                const d=await r.json();
                if(d.success) this.courses=d.courses;
            }catch(e){console.error(e);}
        },
        showAlert(type,msg){
            this.courseAlert={show:true,type,message:msg};
            setTimeout(()=>this.courseAlert.show=false,4000);
        },
        openEditCourse(c){
            this.editingCourseId=c.id;
            this.courseForm={code:c.code,name:c.name};
        },
        resetCourseForm(){
            this.editingCourseId=null;
            this.courseForm={code:'',name:''};
        },
        async saveCourse(){
            const code=this.courseForm.code.trim().toUpperCase();
            const name=this.courseForm.name.trim();
            if(!code||!name){this.showAlert('error','Both Code and Name are required.');return;}
            this.savingCourse=true;
            try{
                const url=this.editingCourseId?`/courses/${this.editingCourseId}`:'/courses';
                const method=this.editingCourseId?'PUT':'POST';
                const r=await fetch(url,{method,headers:{
                    'Content-Type':'application/json',
                    'X-CSRF-TOKEN':document.getElementById('global-csrf-token').value,
                    'Accept':'application/json'
                },body:JSON.stringify({code,name})});
                const d=await r.json();
                if(d.success){
                    if(this.editingCourseId){
                        const idx=this.courses.findIndex(c=>c.id===this.editingCourseId);
                        if(idx!==-1) this.courses[idx]=d.course;
                    }else{
                        this.courses.push(d.course);
                    }
                    this.showAlert('success',d.message);
                    this.resetCourseForm();
                }else{
                    this.showAlert('error',d.message||'An error occurred.');
                }
            }catch(e){
                this.showAlert('error','Network error. Please try again.');
            }finally{
                this.savingCourse=false;
            }
        },
        async deleteCourse(id){
            if(!confirm('Delete this course? This cannot be undone.')) return;
            try{
                const r=await fetch(`/courses/${id}`,{method:'DELETE',headers:{
                    'X-CSRF-TOKEN':document.getElementById('global-csrf-token').value,
                    'Accept':'application/json'
                }});
                const d=await r.json();
                if(d.success){
                    this.courses=this.courses.filter(c=>c.id!==id);
                    this.showAlert('success',d.message);
                }else{
                    this.showAlert('error',d.message);
                }
            }catch(e){
                this.showAlert('error','Network error. Please try again.');
            }
        }
    };
}
</script>

@endsection