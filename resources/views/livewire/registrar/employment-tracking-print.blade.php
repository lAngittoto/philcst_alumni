<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Employment Tracking Report</title>
<style>
    /* ────────────────────────────────────────────────────────────────────
       IMPORTANT — dompdf-safe styling only for the PDF/print branch.
       dompdf's SVG support is very limited: <circle> with stroke-dasharray
       and <g transform="rotate(...)"> (donut charts) silently fail to
       render. Every PDF visual below uses plain <div>/<table> block
       layout with inline width percentages instead.

       The Excel branch below is a completely separate, table-only layout
       (no divs/bars at all — Excel doesn't render CSS visuals), built as
       several small "Metric | Count | Rate" style tables mirroring the
       PDF's numbers section by section, followed by the full per-alumni
       raw data table at the very end.
    ──────────────────────────────────────────────────────────────────── */
    * { box-sizing: border-box; }
    body {
        font-family: {{ !empty($excelMode) ? 'Arial, sans-serif' : "'DejaVu Sans', Arial, sans-serif" }};
        color: #111111;
        margin: 0;
        padding: 20px;
        font-size: 12px;
    }
    .header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        border-bottom: 2px solid #7A3F91;
        padding-bottom: 10px;
        margin-bottom: 14px;
    }
    .header h1 { font-size: 20px; margin: 0 0 2px; color: #7A3F91; }
    .header p { margin: 0; font-size: 12px; color: #333333; }
    .meta { text-align: right; font-size: 11px; color: #333333; }
    .filters-box {
        background: #F9F7FC; border: 1px solid #E8E0F0; border-radius: 6px;
        padding: 8px 12px; margin-bottom: 14px; font-size: 11.5px; color: #333333;
    }
    .filters-box strong { color: #7A3F91; }

    table { width: 100%; border-collapse: collapse; }
    thead th {
        background: #7A3F91; color: #ffffff; text-align: left; padding: 6px 8px;
        font-size: 11px; text-transform: uppercase; letter-spacing: .04em;
    }
    tbody td { padding: 5px 8px; border-bottom: 1px solid #eeeeee; font-size: 11.5px; vertical-align: top; color: #111111; }
    tbody tr:nth-child(even) { background: #FAFAFA; }

    .badge { display: inline-block; padding: 2px 6px; border-radius: 10px; font-size: 10.5px; font-weight: bold; }
    .badge-employed      { background: #ECFDF5; color: #059669; }
    .badge-self_employed { background: #EFF6FF; color: #2563EB; }
    .badge-unemployed    { background: #FFFBEB; color: #D97706; }
    .badge-none          { background: #F3F4F6; color: #333333; }

    .footer {
        margin-top: 16px; font-size: 10.5px; color: #333333; text-align: center;
        border-top: 1px solid #eeeeee; padding-top: 8px;
    }

    /* ── PDF: stat summary (4 boxes) — plain table, no flex ─────────────── */
    .stat-table td {
        border: 1px solid #E8E0F0; border-radius: 6px; padding: 10px 12px;
        width: 25%; vertical-align: top; background: #FFFFFF;
    }
    .stat-num { font-size: 20px; font-weight: bold; color: #111111; line-height: 1.1; }
    .stat-lbl { font-size: 10.5px; font-weight: bold; color: #333333; margin-top: 3px; }
    .stat-rate { font-size: 10px; font-weight: bold; margin-top: 2px; }
    .c-submitted  .stat-rate { color: #7A3F91; }
    .c-working    .stat-rate { color: #059669; }
    .c-unemployed .stat-rate { color: #D97706; }
    .c-norecord   .stat-rate { color: #6B7280; }

    /* ── PDF: section block wrapper ───────────────────────────────────── */
    .section-block {
        border: 1px solid #E8E0F0; border-radius: 8px; background: #FFFFFF;
        margin-bottom: 14px; overflow: hidden;
    }
    .section-block-title {
        background: #F9F7FC; border-bottom: 1px solid #E8E0F0;
        padding: 8px 12px; font-size: 12px; font-weight: bold; color: #111111;
        text-transform: uppercase; letter-spacing: .03em;
    }
    .section-block-sub { font-size: 10.5px; font-weight: normal; color: #333333; text-transform: none; letter-spacing: 0; }
    .section-block-body { padding: 12px 14px; }
    .section-total { font-size: 12px; font-weight: bold; color: #111111; margin-bottom: 8px; }

    /* ── PDF: segmented horizontal bar (donut replacement) ───────────────── */
    .seg-bar { height: 14px; border-radius: 7px; overflow: hidden; background: #E5E7EB; white-space: nowrap; font-size: 0; }
    .seg-bar-fill { display: inline-block; height: 14px; }

    .legend-row { font-size: 11px; color: #111111; padding: 3px 0; }
    .legend-dot {
        display: inline-block; width: 8px; height: 8px; border-radius: 50%;
        margin-right: 6px; vertical-align: middle;
    }
    .legend-label { color: #333333; font-weight: 600; }
    .legend-value { font-weight: bold; color: #111111; float: right; }

    .loc-split-nums { font-size: 11.5px; margin-bottom: 6px; }
    .loc-split-nums .n-local  { color: #7A3F91; font-weight: bold; }
    .loc-split-nums .n-abroad { color: #C084FC; font-weight: bold; float: right; }

    .rank-row { margin-bottom: 9px; }
    .rank-row:last-child { margin-bottom: 0; }
    .rank-row-label { font-size: 11.5px; font-weight: bold; color: #111111; margin-bottom: 3px; }
    .rank-row-label .rank-count { float: right; color: #7A3F91; font-weight: bold; }
    .rank-bar-track { height: 12px; border-radius: 6px; background: #F3F4F6; overflow: hidden; }
    .rank-bar-fill { height: 12px; border-radius: 6px; }

    .mini-bar-track { height: 10px; border-radius: 5px; background: #F3F4F6; overflow: hidden; white-space: nowrap; font-size: 0; }
    .mini-bar-fill { display: inline-block; height: 10px; }
    .rate-cell-num { font-size: 11.5px; font-weight: bold; color: #111111; }

    .section-title-standalone {
        font-size: 12.5px; font-weight: bold; color: #7A3F91; margin: 0 0 6px;
        text-transform: uppercase; letter-spacing: .03em;
    }

    /* ── EXCEL: plain report-style tables (numbers only, no CSS visuals) ──
       NOTE: full grid borders (vertical + horizontal) added on every th/td
       so the workbook shows visible cell lines, same as a normal Excel grid. */
    .xl-section-title {
        background: #7A3F91; color: #ffffff; font-weight: bold; font-size: 12px;
        padding: 6px 8px; text-transform: uppercase; letter-spacing: .04em;
        border: 1px solid #7A3F91;
    }
    .xl-table { margin-bottom: 4px; border: 1px solid #D9D9D9; }
    .xl-table th {
        background: #F0E6F8; color: #7A3F91; text-align: left; padding: 5px 8px;
        font-size: 11px; text-transform: uppercase; border: 1px solid #D9D9D9;
    }
    .xl-table td { padding: 5px 8px; font-size: 11.5px; border: 1px solid #D9D9D9; }
    .xl-spacer td { padding: 4px 0; border: none; }

    @media print {
        body { padding: 6px; }
        .no-print { display: none !important; }
    }
</style>
</head>
<body>

    @php
        // ── Scope-wide stats — computed directly from $records, which the
        //    controller already scopes to Program/Batch AND includes every
        //    alumnus in that scope (even those with no employment_tracking
        //    row at all, counted here as "No Record"). ─────────────────────
        $total         = $records->count();
        $employedCnt   = $records->where('employment_status', 'employed')->count();
        $selfCnt       = $records->where('employment_status', 'self_employed')->count();
        $unemployedCnt = $records->where('employment_status', 'unemployed')->count();
        $submittedCnt  = $employedCnt + $selfCnt + $unemployedCnt;
        $noRecordCnt   = max(0, $total - $submittedCnt);
        $workingCnt    = $employedCnt + $selfCnt;

        $empRate      = $total > 0 ? round($workingCnt / $total * 100, 1) : 0;
        $responseRate = $total > 0 ? round($submittedCnt / $total * 100, 1) : 0;
        $unempRate    = $submittedCnt > 0 ? round($unemployedCnt / $submittedCnt * 100, 1) : 0;
        $noRecordRate = $total > 0 ? round($noRecordCnt / $total * 100, 1) : 0;

        $workingRecords = $records->whereIn('employment_status', ['employed', 'self_employed']);
        $localCnt   = $workingRecords->where('work_location', 'local')->count();
        $abroadCnt  = $workingRecords->where('work_location', 'abroad')->count();
        $locTotal   = $localCnt + $abroadCnt;
        $localPct   = $locTotal > 0 ? round($localCnt  / $locTotal * 100, 1) : 0;
        $abroadPct  = $locTotal > 0 ? round($abroadCnt / $locTotal * 100, 1) : 0;

        $relYesCnt     = $workingRecords->whereIn('course_relevance', ['yes', 'relevant'])->count();
        $relPartialCnt = $workingRecords->whereIn('course_relevance', ['partially', 'partially_relevant'])->count();
        $relNoCnt      = $workingRecords->whereIn('course_relevance', ['no', 'not_relevant'])->count();
        $relTotal      = $relYesCnt + $relPartialCnt + $relNoCnt;

        // ── Program breakdown — carries a 'working' field so Top Programs
        //    can rank by it directly. ─────────────────────────────────────
        $byProgram = $records->groupBy(fn($r) => $r->course_code ?? '—')->map(function ($grp, $code) {
            $t = $grp->count();
            $e = $grp->where('employment_status', 'employed')->count();
            $s = $grp->where('employment_status', 'self_employed')->count();
            $u = $grp->where('employment_status', 'unemployed')->count();
            $w = $e + $s;
            return (object) [
                'course_code'   => $code,
                'total'         => $t,
                'employed'      => $e,
                'self_employed' => $s,
                'unemployed'    => $u,
                'not_filled'    => max(0, $t - ($e + $s + $u)),
                'working'       => $w,
                'rate'          => $t > 0 ? round($w / $t * 100, 1) : 0,
            ];
        })->sortByDesc('rate')->values();

        // ── Top Programs — Top 3 by working (employed + self-employed)
        //    alumni count, mirroring the dashboard's "Top Programs" card. ──
        $topPrograms    = $byProgram->sortByDesc('working')->take(3)->values();
        $topProgramsMax = $topPrograms->max('working') ?: 1;

        // ── Batch breakdown — grouped by batch year. ────────────────────────
        $byBatch = $records->groupBy(fn($r) => $r->batch ?? '—')->map(function ($grp, $batchLabel) {
            $t = $grp->count();
            $e = $grp->where('employment_status', 'employed')->count();
            $s = $grp->where('employment_status', 'self_employed')->count();
            $u = $grp->where('employment_status', 'unemployed')->count();
            $w = $e + $s;
            return (object) [
                'batch'         => $batchLabel,
                'total'         => $t,
                'employed'      => $e,
                'self_employed' => $s,
                'unemployed'    => $u,
                'not_filled'    => max(0, $t - ($e + $s + $u)),
                'working'       => $w,
                'rate'          => $t > 0 ? round($w / $t * 100, 1) : 0,
            ];
        })->sortBy('batch')->values();
    @endphp

    <div class="header">
        <div>
            <h1>Employment Tracking Report</h1>
            <p>System-wide alumni employment analytics</p>
        </div>
        <div class="meta">
            <div>Generated: {{ $generatedAt->format('F j, Y g:i A') }}</div>
            <div>By: {{ $generatedBy ?? 'Registrar' }}</div>
            <div>Total Alumni in Scope: {{ number_format($total) }}</div>
        </div>
    </div>

    <div class="filters-box">
        <strong>Report scope:</strong> {{ $filters }}
    </div>

    @if(!empty($excelMode))
        {{-- ═══════════════════════════════════════════════════════════
             EXCEL — structured report tables (numbers/percentages in
             rows & columns), mirroring the PDF section-for-section,
             followed by the full per-alumni raw data table at the end.
        ═══════════════════════════════════════════════════════════ --}}

        {{-- Summary --}}
        <table class="xl-table">
            <tr><td class="xl-section-title" colspan="3">Summary</td></tr>
            <tr><th>Metric</th><th>Count</th><th>Rate</th></tr>
            <tr><td>Submitted</td><td>{{ number_format($submittedCnt) }}</td><td>{{ $responseRate }}% response rate</td></tr>
            <tr><td>Working</td><td>{{ number_format($workingCnt) }}</td><td>{{ $empRate }}% of total alumni</td></tr>
            <tr><td>Unemployed</td><td>{{ number_format($unemployedCnt) }}</td><td>{{ $unempRate }}% of submitted</td></tr>
            <tr><td>No Record</td><td>{{ number_format($noRecordCnt) }}</td><td>{{ $noRecordRate }}% of total alumni</td></tr>
            <tr><td><strong>Total Alumni</strong></td><td><strong>{{ number_format($total) }}</strong></td><td>100%</td></tr>
        </table>
        <table class="xl-spacer"><tr><td>&nbsp;</td></tr></table>

        {{-- Employment Status --}}
        <table class="xl-table">
            <tr><td class="xl-section-title" colspan="3">Employment Status</td></tr>
            <tr><th>Status</th><th>Count</th><th>Percentage</th></tr>
            <tr><td>Employed</td><td>{{ number_format($employedCnt) }}</td><td>{{ $total>0?round($employedCnt/$total*100,1):0 }}%</td></tr>
            <tr><td>Self-Employed</td><td>{{ number_format($selfCnt) }}</td><td>{{ $total>0?round($selfCnt/$total*100,1):0 }}%</td></tr>
            <tr><td>Unemployed</td><td>{{ number_format($unemployedCnt) }}</td><td>{{ $total>0?round($unemployedCnt/$total*100,1):0 }}%</td></tr>
            <tr><td>No Record</td><td>{{ number_format($noRecordCnt) }}</td><td>{{ $total>0?round($noRecordCnt/$total*100,1):0 }}%</td></tr>
        </table>
        <table class="xl-spacer"><tr><td>&nbsp;</td></tr></table>

        {{-- Work Location --}}
        <table class="xl-table">
            <tr><td class="xl-section-title" colspan="3">Work Location ({{ number_format($locTotal) }} working)</td></tr>
            <tr><th>Location</th><th>Count</th><th>Percentage</th></tr>
            <tr><td>Local / PH</td><td>{{ number_format($localCnt) }}</td><td>{{ $localPct }}%</td></tr>
            <tr><td>Abroad / OFW</td><td>{{ number_format($abroadCnt) }}</td><td>{{ $abroadPct }}%</td></tr>
        </table>
        <table class="xl-spacer"><tr><td>&nbsp;</td></tr></table>

        {{-- Job Relevance --}}
        <table class="xl-table">
            <tr><td class="xl-section-title" colspan="3">Job Relevance ({{ number_format($relTotal) }} working)</td></tr>
            <tr><th>Relevance</th><th>Count</th><th>Percentage</th></tr>
            <tr><td>Relevant</td><td>{{ number_format($relYesCnt) }}</td><td>{{ $relTotal>0?round($relYesCnt/$relTotal*100,1):0 }}%</td></tr>
            <tr><td>Partially Relevant</td><td>{{ number_format($relPartialCnt) }}</td><td>{{ $relTotal>0?round($relPartialCnt/$relTotal*100,1):0 }}%</td></tr>
            <tr><td>Not Relevant</td><td>{{ number_format($relNoCnt) }}</td><td>{{ $relTotal>0?round($relNoCnt/$relTotal*100,1):0 }}%</td></tr>
        </table>
        <table class="xl-spacer"><tr><td>&nbsp;</td></tr></table>

        {{-- Top Programs --}}
        <table class="xl-table">
            <tr><td class="xl-section-title" colspan="3">Top Programs (Top 3 by Employed Alumni)</td></tr>
            <tr><th>Rank</th><th>Program</th><th>Working Alumni</th></tr>
            @forelse($topPrograms as $i => $tp)
            <tr><td>#{{ $i + 1 }}</td><td>{{ $tp->course_code }}</td><td>{{ number_format($tp->working) }}</td></tr>
            @empty
            <tr><td colspan="3">No employment data yet for this scope.</td></tr>
            @endforelse
        </table>
        <table class="xl-spacer"><tr><td>&nbsp;</td></tr></table>

        {{-- Employment Breakdown by Batch Year --}}
        <table class="xl-table">
            <tr><td class="xl-section-title" colspan="6">Employment Breakdown by Batch Year</td></tr>
            <tr><th>Batch</th><th>Total</th><th>Employed</th><th>Self-Employed</th><th>Unemployed</th><th>No Record</th></tr>
            @forelse($byBatch as $b)
            <tr>
                <td>{{ $b->batch }}</td>
                <td>{{ number_format($b->total) }}</td>
                <td>{{ number_format($b->employed) }}</td>
                <td>{{ number_format($b->self_employed) }}</td>
                <td>{{ number_format($b->unemployed) }}</td>
                <td>{{ number_format($b->not_filled) }}</td>
            </tr>
            @empty
            <tr><td colspan="6">No batch data available for the selected scope.</td></tr>
            @endforelse
        </table>
        <table class="xl-spacer"><tr><td>&nbsp;</td></tr></table>

        {{-- Employment Rate Trend per Batch Year --}}
        <table class="xl-table">
            <tr><td class="xl-section-title" colspan="4">Employment Rate Trend per Batch Year</td></tr>
            <tr><th>Batch</th><th>Working</th><th>Total</th><th>Rate</th></tr>
            @forelse($byBatch as $b)
            <tr>
                <td>{{ $b->batch }}</td>
                <td>{{ number_format($b->working) }}</td>
                <td>{{ number_format($b->total) }}</td>
                <td>{{ $b->rate }}%</td>
            </tr>
            @empty
            <tr><td colspan="4">No batch data available for the selected scope.</td></tr>
            @endforelse
        </table>
        <table class="xl-spacer"><tr><td>&nbsp;</td></tr></table>

        {{-- Program Breakdown --}}
        <table class="xl-table">
            <tr><td class="xl-section-title" colspan="7">Program Breakdown</td></tr>
            <tr><th>Program</th><th>Total</th><th>Employed</th><th>Self-Employed</th><th>Unemployed</th><th>No Record</th><th>Employment Rate</th></tr>
            @forelse($byProgram as $pr)
            <tr>
                <td>{{ $pr->course_code }}</td>
                <td>{{ number_format($pr->total) }}</td>
                <td>{{ number_format($pr->employed) }}</td>
                <td>{{ number_format($pr->self_employed) }}</td>
                <td>{{ number_format($pr->unemployed) }}</td>
                <td>{{ number_format($pr->not_filled) }}</td>
                <td>{{ $pr->rate }}%</td>
            </tr>
            @empty
            <tr><td colspan="7">No program data available for the selected scope.</td></tr>
            @endforelse
        </table>
        <table class="xl-spacer"><tr><td>&nbsp;</td></tr></table>
        <table class="xl-spacer"><tr><td>&nbsp;</td></tr></table>

        {{-- Per-Alumni Raw Data (kept so no detail is lost) --}}
        <table class="xl-table">
            <tr><td class="xl-section-title" colspan="10">Per-Alumni Raw Data</td></tr>
            <tr>
                <th>#</th><th>Name</th><th>Student ID</th><th>Program</th><th>Batch</th>
                <th>Status</th><th>Relevance</th><th>Location</th><th>Email</th><th>Contact No.</th>
            </tr>
            @forelse($records as $i => $row)
            @php
                $name = trim(implode(' ', array_filter([
                    $row->first_name ?? '',
                    !empty($row->middle_initial) ? strtoupper(substr($row->middle_initial,0,1)).'.' : '',
                    $row->last_name ?? '',
                    $row->suffix ?? '',
                ])));

                $statusLabels = [
                    'employed'      => 'Employed',
                    'self_employed' => 'Self-Employed',
                    'unemployed'    => 'Unemployed',
                ];
                $statusKey   = $row->employment_status ?? null;
                $statusLabel = $statusKey ? ($statusLabels[$statusKey] ?? $statusKey) : 'No Record';

                $relevanceLabels = [
                    'yes' => 'Relevant', 'relevant' => 'Relevant',
                    'partially' => 'Partially Relevant', 'partially_relevant' => 'Partially Relevant',
                    'no' => 'Not Relevant', 'not_relevant' => 'Not Relevant',
                ];
                $relevanceLabel = isset($row->course_relevance) && $row->course_relevance
                    ? ($relevanceLabels[$row->course_relevance] ?? '—') : '—';

                $locationLabel = !empty($row->work_location) ? ucfirst($row->work_location) : '—';
            @endphp
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ strtoupper($name) ?: '—' }}</td>
                <td>{{ $row->student_id ?? '—' }}</td>
                <td>{{ $row->course_code ?? '—' }}</td>
                <td>{{ $row->batch ?? '—' }}</td>
                <td>{{ $statusLabel }}</td>
                <td>{{ $relevanceLabel }}</td>
                <td>{{ $locationLabel }}</td>
                <td>{{ $row->email ?? '—' }}</td>
                <td>{{ $row->contact_number ?? '—' }}</td>
            </tr>
            @empty
            <tr><td colspan="10">No records found for the selected scope.</td></tr>
            @endforelse
        </table>
    @else
        {{-- ═══════════════════════════════════════════════════════════
             PDF / PRINT — mirrors the live dashboard section-for-section:
             stat cards, Employment Status, Work Location, Job Relevance,
             Top Programs, Employment Breakdown by Batch Year, Employment
             Rate Trend per Batch Year, and the Program Breakdown table.
        ═══════════════════════════════════════════════════════════ --}}

        {{-- Stat summary --}}
        <table class="stat-table" style="margin-bottom:14px;">
            <tr>
                <td class="c-submitted">
                    <div class="stat-num">{{ number_format($submittedCnt) }}</div>
                    <div class="stat-lbl">Submitted</div>
                    <div class="stat-rate">{{ $responseRate }}% response rate</div>
                </td>
                <td class="c-working">
                    <div class="stat-num">{{ number_format($workingCnt) }}</div>
                    <div class="stat-lbl">Working</div>
                    <div class="stat-rate">{{ $empRate }}% of total alumni</div>
                </td>
                <td class="c-unemployed">
                    <div class="stat-num">{{ number_format($unemployedCnt) }}</div>
                    <div class="stat-lbl">Unemployed</div>
                    <div class="stat-rate">{{ $unempRate }}% of submitted</div>
                </td>
                <td class="c-norecord">
                    <div class="stat-num">{{ number_format($noRecordCnt) }}</div>
                    <div class="stat-lbl">No Record</div>
                    <div class="stat-rate">{{ $noRecordRate }}% of total alumni</div>
                </td>
            </tr>
        </table>

        {{-- Employment Status --}}
        <div class="section-block">
            <div class="section-block-title">Employment Status
                <div class="section-block-sub">Overall breakdown of alumni job status</div>
            </div>
            <div class="section-block-body">
                <div class="section-total">{{ number_format($total) }} Total</div>
                <div class="seg-bar">
                    @if($total > 0)
                        @if($employedCnt   > 0)<div class="seg-bar-fill" style="width:{{ round($employedCnt/$total*100,2) }}%;background:#10b981;"></div>@endif
                        @if($selfCnt       > 0)<div class="seg-bar-fill" style="width:{{ round($selfCnt/$total*100,2) }}%;background:#3b82f6;"></div>@endif
                        @if($unemployedCnt > 0)<div class="seg-bar-fill" style="width:{{ round($unemployedCnt/$total*100,2) }}%;background:#f59e0b;"></div>@endif
                        @if($noRecordCnt   > 0)<div class="seg-bar-fill" style="width:{{ round($noRecordCnt/$total*100,2) }}%;background:#d1d5db;"></div>@endif
                    @endif
                </div>
                <div style="margin-top:9px;">
                    <div class="legend-row"><span class="legend-dot" style="background:#10b981;"></span><span class="legend-label">Employed</span><span class="legend-value">{{ number_format($employedCnt) }} ({{ $total>0?round($employedCnt/$total*100,1):0 }}%)</span></div>
                    <div class="legend-row"><span class="legend-dot" style="background:#3b82f6;"></span><span class="legend-label">Self-Employed</span><span class="legend-value">{{ number_format($selfCnt) }} ({{ $total>0?round($selfCnt/$total*100,1):0 }}%)</span></div>
                    <div class="legend-row"><span class="legend-dot" style="background:#f59e0b;"></span><span class="legend-label">Unemployed</span><span class="legend-value">{{ number_format($unemployedCnt) }} ({{ $total>0?round($unemployedCnt/$total*100,1):0 }}%)</span></div>
                    <div class="legend-row"><span class="legend-dot" style="background:#d1d5db;"></span><span class="legend-label">No Record</span><span class="legend-value">{{ number_format($noRecordCnt) }} ({{ $total>0?round($noRecordCnt/$total*100,1):0 }}%)</span></div>
                </div>
            </div>
        </div>

        {{-- Work Location --}}
        <div class="section-block">
            <div class="section-block-title">Work Location
                <div class="section-block-sub">Where working alumni are based &middot; {{ number_format($locTotal) }} working</div>
            </div>
            <div class="section-block-body">
                <div class="loc-split-nums">
                    <span class="n-local">Local / PH: {{ number_format($localCnt) }} ({{ $localPct }}%)</span>
                    <span class="n-abroad">Abroad / OFW: {{ number_format($abroadCnt) }} ({{ $abroadPct }}%)</span>
                </div>
                <div class="seg-bar">
                    @if($locTotal > 0)
                        @if($localCnt  > 0)<div class="seg-bar-fill" style="width:{{ $localPct }}%;background:#7A3F91;"></div>@endif
                        @if($abroadCnt > 0)<div class="seg-bar-fill" style="width:{{ $abroadPct }}%;background:#C084FC;"></div>@endif
                    @else
                        <div class="seg-bar-fill" style="width:100%;background:#E5E7EB;"></div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Job Relevance --}}
        <div class="section-block">
            <div class="section-block-title">Job Relevance
                <div class="section-block-sub">Alumni whose jobs match their program &middot; {{ number_format($relTotal) }} working</div>
            </div>
            <div class="section-block-body">
                <div class="seg-bar">
                    @if($relTotal > 0)
                        @if($relYesCnt     > 0)<div class="seg-bar-fill" style="width:{{ round($relYesCnt/$relTotal*100,2) }}%;background:#10b981;"></div>@endif
                        @if($relPartialCnt > 0)<div class="seg-bar-fill" style="width:{{ round($relPartialCnt/$relTotal*100,2) }}%;background:#f59e0b;"></div>@endif
                        @if($relNoCnt      > 0)<div class="seg-bar-fill" style="width:{{ round($relNoCnt/$relTotal*100,2) }}%;background:#ef4444;"></div>@endif
                    @else
                        <div class="seg-bar-fill" style="width:100%;background:#E5E7EB;"></div>
                    @endif
                </div>
                <div style="margin-top:9px;">
                    <div class="legend-row"><span class="legend-dot" style="background:#10b981;"></span><span class="legend-label">Relevant</span><span class="legend-value">{{ number_format($relYesCnt) }} ({{ $relTotal>0?round($relYesCnt/$relTotal*100,1):0 }}%)</span></div>
                    <div class="legend-row"><span class="legend-dot" style="background:#f59e0b;"></span><span class="legend-label">Partially Relevant</span><span class="legend-value">{{ number_format($relPartialCnt) }} ({{ $relTotal>0?round($relPartialCnt/$relTotal*100,1):0 }}%)</span></div>
                    <div class="legend-row"><span class="legend-dot" style="background:#ef4444;"></span><span class="legend-label">Not Relevant</span><span class="legend-value">{{ number_format($relNoCnt) }} ({{ $relTotal>0?round($relNoCnt/$relTotal*100,1):0 }}%)</span></div>
                </div>
            </div>
        </div>

        {{-- Top Programs — Top 3 by working alumni --}}
        <div class="section-block">
            <div class="section-block-title">Top Programs
                <div class="section-block-sub">Top 3 programs by employed alumni</div>
            </div>
            <div class="section-block-body">
                @forelse($topPrograms as $tp)
                    @php $tpWidth = $topProgramsMax > 0 ? round($tp->working / $topProgramsMax * 100, 2) : 0; @endphp
                    <div class="rank-row">
                        <div class="rank-row-label">{{ $tp->course_code }} <span class="rank-count">{{ number_format($tp->working) }}</span></div>
                        <div class="rank-bar-track">
                            <div class="rank-bar-fill" style="width:{{ $tpWidth }}%;background:#7A3F91;"></div>
                        </div>
                    </div>
                @empty
                    <p style="font-size:11.5px;color:#333333;margin:0;">No employment data yet for this scope.</p>
                @endforelse
            </div>
        </div>

        {{-- Employment Breakdown by Batch Year --}}
        <div class="section-block">
            <div class="section-block-title">Employment Breakdown by Batch Year
                <div class="section-block-sub">Number of employed, self-employed &amp; unemployed per batch</div>
            </div>
            <div class="section-block-body">
                <table>
                    <thead>
                        <tr>
                            <th style="width:14%;">Batch</th>
                            <th style="width:46%;">Breakdown</th>
                            <th style="width:10%;">Employed</th>
                            <th style="width:12%;">Self-Emp.</th>
                            <th style="width:10%;">Unemployed</th>
                            <th style="width:8%;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($byBatch as $b)
                        <tr>
                            <td>{{ $b->batch }}</td>
                            <td>
                                <div class="mini-bar-track">
                                    @if($b->total > 0)
                                        @if($b->employed      > 0)<div class="mini-bar-fill" style="width:{{ round($b->employed/$b->total*100,2) }}%;background:#10b981;"></div>@endif
                                        @if($b->self_employed > 0)<div class="mini-bar-fill" style="width:{{ round($b->self_employed/$b->total*100,2) }}%;background:#3b82f6;"></div>@endif
                                        @if($b->unemployed    > 0)<div class="mini-bar-fill" style="width:{{ round($b->unemployed/$b->total*100,2) }}%;background:#f59e0b;"></div>@endif
                                        @if($b->not_filled    > 0)<div class="mini-bar-fill" style="width:{{ round($b->not_filled/$b->total*100,2) }}%;background:#d1d5db;"></div>@endif
                                    @endif
                                </div>
                            </td>
                            <td>{{ number_format($b->employed) }}</td>
                            <td>{{ number_format($b->self_employed) }}</td>
                            <td>{{ number_format($b->unemployed) }}</td>
                            <td>{{ number_format($b->total) }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="6" style="text-align:center;padding:16px;color:#333333;">No batch data available for the selected scope.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Employment Rate Trend per Batch Year --}}
        <div class="section-block">
            <div class="section-block-title">Employment Rate Trend per Batch Year
                <div class="section-block-sub">% of alumni (employed + self-employed) out of total per batch</div>
            </div>
            <div class="section-block-body">
                <table>
                    <thead>
                        <tr>
                            <th style="width:14%;">Batch</th>
                            <th style="width:56%;">Employment Rate</th>
                            <th style="width:10%;">Working</th>
                            <th style="width:10%;">Total</th>
                            <th style="width:10%;">Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($byBatch as $b)
                        <tr>
                            <td>{{ $b->batch }}</td>
                            <td>
                                <div class="mini-bar-track">
                                    <div class="mini-bar-fill" style="width:{{ $b->rate }}%;background:#7A3F91;"></div>
                                </div>
                            </td>
                            <td>{{ number_format($b->working) }}</td>
                            <td>{{ number_format($b->total) }}</td>
                            <td class="rate-cell-num">{{ $b->rate }}%</td>
                        </tr>
                        @empty
                        <tr><td colspan="5" style="text-align:center;padding:16px;color:#333333;">No batch data available for the selected scope.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Program Breakdown --}}
        <p class="section-title-standalone">Program Breakdown</p>
        <table>
            <thead>
                <tr>
                    <th style="width:16%;">Program</th>
                    <th style="width:9%;">Total</th>
                    <th style="width:12%;">Employed</th>
                    <th style="width:13%;">Self-Employed</th>
                    <th style="width:12%;">Unemployed</th>
                    <th style="width:10%;">No Record</th>
                    <th style="width:28%;">Employment Rate</th>
                </tr>
            </thead>
            <tbody>
                @forelse($byProgram as $pr)
                <tr>
                    <td>{{ $pr->course_code }}</td>
                    <td>{{ number_format($pr->total) }}</td>
                    <td>{{ number_format($pr->employed) }}</td>
                    <td>{{ number_format($pr->self_employed) }}</td>
                    <td>{{ number_format($pr->unemployed) }}</td>
                    <td>{{ number_format($pr->not_filled) }}</td>
                    <td>
                        <div class="mini-bar-track" style="margin-bottom:2px;">
                            <div class="mini-bar-fill" style="width:{{ $pr->rate }}%;background:#10b981;"></div>
                        </div>
                        <span class="rate-cell-num">{{ $pr->rate }}%</span>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" style="text-align:center;padding:20px;color:#333333;">No program data available for the selected scope.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    @endif

    <div class="footer">
        Employment Tracking Report &mdash; Generated on {{ $generatedAt->format('F j, Y g:i A') }} &mdash; {{ number_format($total) }} alumni in scope
    </div>

</body>
</html>