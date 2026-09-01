<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Employment Tracking Report</title>
<style>
    @page { size: A4 portrait; margin: 14mm 12mm; }
    * { box-sizing: border-box; }
    /* Same dompdf font-speed fix as alumni-records-print: "sans-serif"
       maps straight to dompdf's built-in DejaVu Sans, no font
       lookup/metric-matching overhead per text node like "Arial,
       Helvetica" would need. */
    body {
        font-family: sans-serif;
        color: #111111;
        margin: 0;
        padding: 0;
        font-size: 11px;
    }

    .rp-header {
        display: table;
        width: 100%;
        border-bottom: 2px solid #7A3F91;
        padding-bottom: 8px;
        margin-bottom: 10px;
    }
    .rp-header-left, .rp-header-right {
        display: table-cell;
        vertical-align: bottom;
    }
    .rp-header-right { text-align: right; }
    .rp-header h1 { font-size: 16px; margin: 0; color: #7A3F91; }
    .rp-header p  { margin: 2px 0 0; font-size: 10px; color: #555555; }
    .rp-meta      { font-size: 10px; color: #555555; white-space: nowrap; line-height: 1.5; }

    /* ── Stat summary cards, mirrors the on-screen sidebar cards ──
       dompdf has no flexbox/grid support, so this uses a <table>
       layout (display:table/table-row/table-cell) instead — same
       technique as .rp-header above, just with more columns. Only
       renders on page 1 so it doesn't repeat on every page-break. */
    .rp-stats {
        display: table;
        width: 100%;
        table-layout: fixed;
        border-spacing: 4px 0;
        margin: 0 0 12px;
    }
    .rp-stats-row { display: table-row; }
    .rp-stat {
        display: table-cell;
        background: #F9F7FB;
        border: 1px solid #E8E0F0;
        padding: 6px 4px;
        text-align: center;
        vertical-align: middle;
    }
    .rp-stat .rp-stat-label {
        font-size: 7.5px;
        text-transform: uppercase;
        letter-spacing: .02em;
        color: #7A3F91;
        font-weight: 700;
        margin: 0 0 2px;
        white-space: nowrap;
    }
    .rp-stat .rp-stat-value {
        font-size: 15px;
        font-weight: 700;
        color: #333333;
        margin: 0;
    }

    /* Same border-collapse:separate fix as alumni-records-print — dompdf
       drops/merges shared borders unpredictably under collapse mode. */
    table.rp-table {
        width: 100%;
        table-layout: fixed;
        border-collapse: separate;
        border-spacing: 0;
        margin-bottom: 6px;
    }
    thead th {
        background: #F5F0FA;
        color: #333333;
        font-size: 9.5px;
        text-transform: uppercase;
        letter-spacing: .03em;
        text-align: left;
        padding: 6px 7px;
        border-bottom: 1.5px solid #E0D3EC;
    }

    tbody td {
        padding: 5px 7px;
        border-bottom: 1px solid #E5E5E5;
        font-size: 10.5px;
        vertical-align: top;
        background: #ffffff;
        overflow: hidden;
    }

    tbody tr:first-child td { border-top: none; }

    /* No border-radius — same rendering-cost fix as alumni-records-print. */
    .rp-badge {
        display: inline;
        padding: 0;
        font-size: 9px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .02em;
    }
    .rp-emp-employed      { color: #059669; }
    .rp-emp-self_employed { color: #1D4ED8; }
    .rp-emp-unemployed    { color: #B45309; }
    .rp-emp-not_filled    { color: #6B7280; }

    .rp-page-block { page-break-after: always; }
    .rp-page-block:last-child { page-break-after: auto; }

    .rp-empty { text-align: center; padding: 60px 0; color: #999999; font-size: 12px; }
</style>
</head>
<body>

@php
    $chunks = $records->chunk(200);

    $formatName  = $formatName  ?? fn($row) => trim(($row->first_name ?? '') . ' ' . ($row->last_name ?? ''));
    $statusLabel = $statusLabel ?? fn($row) => match ($row->employment_status ?? null) {
        'employed'      => 'Employed',
        'self_employed' => 'Self-Employed',
        'unemployed'    => 'Unemployed',
        default         => 'Not Filled',
    };
    $statusClass = fn($row) => match ($row->employment_status ?? null) {
        'employed'      => 'rp-emp-employed',
        'self_employed' => 'rp-emp-self_employed',
        'unemployed'    => 'rp-emp-unemployed',
        default         => 'rp-emp-not_filled',
    };

    // Falls back to zeros if the controller didn't pass stats, so this
    // view never breaks even if called from somewhere that hasn't been
    // updated to compute them yet.
    $stats = $stats ?? [];
    $s = fn($key) => number_format($stats[$key] ?? 0);
@endphp

@forelse($chunks as $pageIndex => $chunk)
<div class="rp-page-block">
    <div class="rp-header">
        <div class="rp-header-left">
            <h1>PHILCST Employment Tracking Report</h1>
            <p>Admin Office &middot; {{ number_format($records->count()) }} total record(s)</p>
        </div>
        <div class="rp-header-right">
            <div class="rp-meta">
                Generated {{ $generatedAt->format('F j, Y g:i A') }}<br>
                Page {{ $pageIndex + 1 }} of {{ $chunks->count() }}
            </div>
        </div>
    </div>

    @if($pageIndex === 0)
    <div class="rp-stats">
        <div class="rp-stats-row">
            <div class="rp-stat">
                <p class="rp-stat-label">Total Alumni</p>
                <p class="rp-stat-value">{{ $s('totalAlumni') }}</p>
            </div>
            <div class="rp-stat">
                <p class="rp-stat-label">Employed</p>
                <p class="rp-stat-value">{{ $s('totalEmployed') }}</p>
            </div>
            <div class="rp-stat">
                <p class="rp-stat-label">Self-Employed</p>
                <p class="rp-stat-value">{{ $s('totalSelf') }}</p>
            </div>
            <div class="rp-stat">
                <p class="rp-stat-label">Unemployed</p>
                <p class="rp-stat-value">{{ $s('totalUnemployed') }}</p>
            </div>
            <div class="rp-stat">
                <p class="rp-stat-label">Not Filled</p>
                <p class="rp-stat-value">{{ $s('totalNotFilled') }}</p>
            </div>
        </div>
    </div>
    <div class="rp-stats">
        <div class="rp-stats-row">
            <div class="rp-stat">
                <p class="rp-stat-label">Local</p>
                <p class="rp-stat-value">{{ $s('totalLocal') }}</p>
            </div>
            <div class="rp-stat">
                <p class="rp-stat-label">OFW</p>
                <p class="rp-stat-value">{{ $s('totalOFW') }}</p>
            </div>
            <div class="rp-stat">
                <p class="rp-stat-label">Course-Related</p>
                <p class="rp-stat-value">{{ $s('totalRelated') }}</p>
            </div>
            <div class="rp-stat">
                <p class="rp-stat-label">Partially Related</p>
                <p class="rp-stat-value">{{ $s('totalPartial') }}</p>
            </div>
            <div class="rp-stat">
                <p class="rp-stat-label">Not Related</p>
                <p class="rp-stat-value">{{ $s('totalNotRelated') }}</p>
            </div>
        </div>
    </div>
    @endif

    <table class="rp-table">
        <thead>
            <tr>
                <th style="width:18%;">Name</th>
                <th style="width:12%;">Student ID</th>
                <th style="width:10%;">Program Code</th>
                <th style="width:7%;">Batch</th>
                <th style="width:14%;">Employment Status</th>
                <th style="width:17%;">Company</th>
                <th style="width:15%;">Job Title</th>
                <th style="width:7%;">Location</th>
            </tr>
        </thead>
        <tbody>
            @foreach($chunk as $item)
            <tr>
                <td>{{ strtoupper($formatName($item)) }}</td>
                <td>{{ $item->student_id }}</td>
                <td>{{ $item->course_code }}</td>
                <td>{{ $item->batch }}</td>
                <td>
                    <span class="rp-badge {{ $statusClass($item) }}">{{ $statusLabel($item) }}</span>
                </td>
                <td>{{ $item->company_name ?? '—' }}</td>
                <td>{{ $item->job_title ?? '—' }}</td>
                <td>{{ $item->work_location ? ucfirst($item->work_location) : '—' }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@empty
<div class="rp-empty">No alumni employment records found for the applied filters.</div>
@endforelse

</body>
</html>