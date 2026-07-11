<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Alumni Records Report</title>
<style>
    @page { size: A4 portrait; margin: 14mm 12mm; }
    * { box-sizing: border-box; }
    body {
        font-family: Arial, Helvetica, sans-serif;
        color: #111111;
        margin: 0;
        padding: 0;
        font-size: 11px;
    }

    .rp-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        border-bottom: 2px solid #7A3F91;
        padding-bottom: 8px;
        margin-bottom: 10px;
    }
    .rp-header h1 { font-size: 16px; margin: 0; color: #7A3F91; }
    .rp-header p  { margin: 2px 0 0; font-size: 10px; color: #555555; }
    .rp-meta      { text-align: right; font-size: 10px; color: #555555; white-space: nowrap; }

    /*
     * FIX: dompdf has a known rendering bug with `border-collapse: collapse`
     * on multi-row tables — adjoining borders between rows can get dropped
     * or "merged" unpredictably, which is why rows looked grouped instead
     * of each having its own clean horizontal line.
     *
     * Switching to `border-collapse: separate` + `border-spacing: 0` makes
     * every <td> paint its OWN border independently, so every single row
     * reliably gets a horizontal line underneath it — no more skipped or
     * merged lines.
     */
    table {
        width: 100%;
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

    /* One record = one line, every line looks the same — no alternating
       shading. Each <td> now paints its own bottom border (separate mode),
       so every row reliably gets a single, consistent horizontal line. */
    tbody td {
        padding: 5px 7px;
        border-bottom: 1px solid #E5E5E5;
        font-size: 10.5px;
        vertical-align: top;
        background: #ffffff;
    }

    /* Belt-and-suspenders: explicitly zero out the top border so dompdf
       never has two adjoining borders to try to "merge" between rows —
       only the bottom border of each row is ever drawn. */
    tbody tr td { border-top: none; }

    .rp-badge {
        display: inline-block;
        padding: 1px 6px;
        border-radius: 8px;
        font-size: 9px;
        font-weight: 600;
    }
    .rp-complete { background: #ECFDF5; color: #059669; }
    .rp-pending  { background: #FFFBEB; color: #D97706; }

    /* One block per printed page — forces correct page count */
    .rp-page-block { page-break-after: always; }
    .rp-page-block:last-child { page-break-after: auto; }

    .rp-empty { text-align: center; padding: 60px 0; color: #999999; font-size: 12px; }
</style>
</head>
<body>

@php $chunks = $records->chunk(200); @endphp

@forelse($chunks as $pageIndex => $chunk)
<div class="rp-page-block">
    <div class="rp-header">
        <div>
            <h1>PHILCST Alumni Records Report</h1>
            <p>Registrar's Office &middot; {{ number_format($records->count()) }} total record(s)</p>
        </div>
        <div class="rp-meta">
            Generated {{ $generatedAt->format('F j, Y g:i A') }}<br>
            Page {{ $pageIndex + 1 }} of {{ $chunks->count() }}
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:26%;">Name</th>
                <th style="width:16%;">Student ID</th>
                <th style="width:14%;">Program Code</th>
                <th style="width:10%;">Batch</th>
                <th style="width:24%;">Email</th>
                <th style="width:10%;">Status</th>
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
                    @if($item->profile_completed)
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