<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Alumni Records Report</title>
<style>
    @page { size: A4 portrait; margin: 14mm 12mm; }
    * { box-sizing: border-box; }
    /* SPEED FIX: use dompdf's built-in sans-serif (maps to DejaVu Sans
       internally) instead of "Arial, Helvetica" — dompdf has to locate,
       load, and metric-match a system font for those names, which adds
       overhead on every text node. sans-serif uses dompdf's native font
       directly, no substitution step needed. */
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

    /* FIX (borders kept vanishing / landing randomly): dompdf's
       border-collapse:collapse has known bugs where shared borders
       between adjacent table rows get dropped or merged inconsistently
       — that's why some rows had a line, some didn't, no matter how the
       cell heights were adjusted. Switching to border-collapse:separate
       with border-spacing:0 makes every <td> paint its OWN border
       independently instead of trying to share/merge one with its
       neighbor, which is what dompdf handles reliably. Visually
       identical spacing (border-spacing:0 removes the gaps separate
       mode normally adds between cells). */
    table {
        width: 100%;
        table-layout: fixed;
        border-collapse: separate;
        border-spacing: 0;
        margin-bottom: 6px;
    }
    thead th {
        background: #F5F0FA;
        color: #333333;
        font-size: 8.5px;
        text-transform: uppercase;
        letter-spacing: .01em;
        text-align: left;
        padding: 6px 5px;
        border-bottom: 1.5px solid #E0D3EC;
        white-space: normal;
        word-break: break-word;
        line-height: 1.25;
        vertical-align: bottom;
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

    /* FIX (biggest win): removed border-radius entirely.
       Rounded-corner rendering is one of the slowest operations in
       dompdf's rendering engine — at 1500 rows that was 1500 rounded
       shapes computed one by one. Plain colored, bold text conveys
       the same Complete/Pending status without the cost. */
    .rp-badge {
        display: inline;
        padding: 0;
        font-size: 9px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .02em;
    }
    .rp-complete { color: #059669; }
    .rp-pending  { color: #D97706; }

    .rp-emp-employed      { color: #7A3F91; }
    .rp-emp-self_employed { color: #1D4ED8; }
    .rp-emp-unemployed    { color: #B45309; }
    .rp-emp-no_record     { color: #6B7280; }

    .rp-page-block { page-break-after: always; }
    .rp-page-block:last-child { page-break-after: auto; }

    .rp-empty { text-align: center; padding: 60px 0; color: #999999; font-size: 12px; }
</style>
</head>
<body>

@php
    $chunks = $records->chunk(200);

    /*
     * FIX (all-rows-showed-Complete bug): the previous version of this
     * view used an $isComplete closure that OR'd in a "derived from
     * basic fields" check (first_name, last_name, student_id,
     * course_code, batch, email, middle_initial). Those are exactly the
     * fields that are ALWAYS filled in at registration/bulk-import time
     * — long before an alumnus ever finishes their actual profile
     * (address, parents' info, disability, etc. via the alumni portal).
     * So that derived check evaluated true for basically every row,
     * which is why every row showed "Complete" regardless of the real
     * profile_completed value in the database.
     *
     * The `profile_completed` column IS the authoritative flag — it's
     * set by the alumni portal's own completion logic, which checks far
     * more than these 7 basic columns. So Status here now reads straight
     * from the DB column, no derived fallback. If an $isComplete closure
     * isn't passed in (e.g. view rendered directly without it), fall
     * back to the raw column too, never to a derived guess.
     */
    $isComplete = $isComplete ?? fn($row) => (bool) ($row->profile_completed ?? false);

    // Employment Status — mirrors employmentStatusBadge() in the
    // registrar's alumni-records Volt component (label only, no icon;
    // plain colored text same as the Complete/Pending status cell).
    $empStatusLabel = fn($row) => match ($row->employment_status ?? null) {
        'employed'      => ['Employed',      'rp-emp-employed'],
        'self_employed' => ['Self-Employed', 'rp-emp-self_employed'],
        'unemployed'    => ['Unemployed',    'rp-emp-unemployed'],
        default         => ['No Record',     'rp-emp-no_record'],
    };
@endphp

@forelse($chunks as $pageIndex => $chunk)
<div class="rp-page-block">
    <div class="rp-header">
        <div class="rp-header-left">
            <h1>PHILCST Alumni Records Report</h1>
            <p>Registrar's Office &middot; {{ number_format($records->count()) }} total record(s)</p>
        </div>
        <div class="rp-header-right">
            <div class="rp-meta">
                Generated {{ $generatedAt->format('F j, Y g:i A') }}<br>
                Page {{ $pageIndex + 1 }} of {{ $chunks->count() }}
            </div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:17%;">Name</th>
                <th style="width:11%;">Student ID</th>
                <th style="width:14%;">Standard Abbreviation</th>
                <th style="width:7%;">Batch</th>
                <th style="width:19%;">Email</th>
                <th style="width:16%;">Employment Status</th>
                <th style="width:16%;">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach($chunk as $item)
            <tr>
                <td>{{ strtoupper($formatName($item)) }}</td>
                <td>{{ $item->student_id }}</td>
                <td>{{ $item->course_code }}</td>
                <td>{{ $item->batch }}</td>
                <td>{{ $item->email }}</td>
                <td>
                    @php [$empLabel, $empClass] = $empStatusLabel($item); @endphp
                    <span class="rp-badge {{ $empClass }}">{{ $empLabel }}</span>
                </td>
                <td>
                    @if($isComplete($item))
                        <span class="rp-badge rp-complete">Complete</span>
                    @else
                        <span class="rp-badge rp-pending">Pending</span>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
@empty
<div class="rp-empty">No alumni records found for the applied filters.</div>
@endforelse

</body>
</html>