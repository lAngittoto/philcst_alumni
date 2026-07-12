<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Employment Tracking Report</title>
<style>
    * { box-sizing: border-box; }
    body {
        font-family: {{ !empty($excelMode) ? 'Arial, sans-serif' : "'DejaVu Sans', Arial, sans-serif" }};
        color: #111111;
        margin: 0;
        padding: 20px;
        font-size: 11px;
    }
    .header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        border-bottom: 2px solid #7A3F91;
        padding-bottom: 10px;
        margin-bottom: 14px;
    }
    .header h1 {
        font-size: 18px;
        margin: 0 0 2px;
        color: #7A3F91;
    }
    .header p {
        margin: 0;
        font-size: 11px;
        color: #333333;
    }
    .meta {
        text-align: right;
        font-size: 10px;
        color: #333333;
    }
    .filters-box {
        background: #F9F7FC;
        border: 1px solid #E8E0F0;
        border-radius: 6px;
        padding: 8px 12px;
        margin-bottom: 14px;
        font-size: 10.5px;
        color: #333333;
    }
    .filters-box strong { color: #7A3F91; }
    table {
        width: 100%;
        border-collapse: collapse;
    }
    thead th {
        background: #7A3F91;
        color: #ffffff;
        text-align: left;
        padding: 6px 8px;
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: .04em;
    }
    tbody td {
        padding: 5px 8px;
        border-bottom: 1px solid #eeeeee;
        font-size: 10.5px;
        vertical-align: top;
        color: #111111;
    }
    tbody tr:nth-child(even) { background: #FAFAFA; }
    .badge {
        display: inline-block;
        padding: 2px 6px;
        border-radius: 10px;
        font-size: 9.5px;
        font-weight: bold;
    }
    .badge-employed      { background: #ECFDF5; color: #059669; }
    .badge-self_employed { background: #EFF6FF; color: #2563EB; }
    .badge-unemployed    { background: #FFFBEB; color: #D97706; }
    .badge-none          { background: #F3F4F6; color: #333333; }
    .footer {
        margin-top: 16px;
        font-size: 9.5px;
        color: #333333;
        text-align: center;
        border-top: 1px solid #eeeeee;
        padding-top: 8px;
    }

    /* ── Summary stat boxes ──────────────────────────────────────────────── */
    .stat-summary-row {
        display: flex;
        gap: 8px;
        margin-bottom: 16px;
    }
    .stat-box {
        flex: 1;
        border: 1px solid #E8E0F0;
        border-radius: 8px;
        padding: 8px 10px;
        background: #FFFFFF;
    }
    .stat-box .stat-num {
        font-size: 18px;
        font-weight: bold;
        color: #111111;
        line-height: 1.1;
    }
    .stat-box .stat-lbl {
        font-size: 9.5px;
        font-weight: bold;
        color: #333333;
        margin-top: 3px;
    }
    .stat-box .stat-rate {
        font-size: 9px;
        font-weight: bold;
        margin-top: 2px;
    }
    .stat-box.c-submitted .stat-rate { color: #7A3F91; }
    .stat-box.c-working   .stat-rate { color: #059669; }
    .stat-box.c-unemployed .stat-rate { color: #D97706; }
    .stat-box.c-norecord   .stat-rate { color: #6B7280; }

    /* ── Charts row (2 donut blocks side by side) ────────────────────────── */
    .charts-row {
        display: flex;
        gap: 10px;
        margin-bottom: 16px;
    }
    .donut-block {
        flex: 1;
        border: 1px solid #E8E0F0;
        border-radius: 8px;
        overflow: hidden;
        background: #FFFFFF;
    }
    .donut-block-title {
        background: #F9F7FC;
        border-bottom: 1px solid #E8E0F0;
        padding: 7px 10px;
        font-size: 10.5px;
        font-weight: bold;
        color: #111111;
        text-transform: uppercase;
        letter-spacing: .03em;
    }
    .donut-block-body {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 12px;
    }
    .donut-legend { flex: 1; }
    .legend-row {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 10px;
        color: #111111;
        padding: 2px 0;
    }
    .legend-dot {
        display: inline-block;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        flex-shrink: 0;
    }
    .legend-label { flex: 1; color: #333333; font-weight: 600; }
    .legend-value { font-weight: bold; color: #111111; white-space: nowrap; }

    /* ── Work location split bar ─────────────────────────────────────────── */
    .loc-split-block {
        border: 1px solid #E8E0F0;
        border-radius: 8px;
        padding: 10px 12px;
        margin-bottom: 16px;
        background: #FFFFFF;
    }
    .loc-split-title {
        font-size: 10.5px;
        font-weight: bold;
        color: #111111;
        text-transform: uppercase;
        letter-spacing: .03em;
        margin-bottom: 8px;
    }
    .loc-split-nums {
        display: flex;
        justify-content: space-between;
        margin-bottom: 6px;
        font-size: 10.5px;
    }
    .loc-split-nums .n-local  { color: #7A3F91; font-weight: bold; }
    .loc-split-nums .n-abroad { color: #C084FC; font-weight: bold; }
    .loc-split-bar {
        height: 10px;
        border-radius: 6px;
        overflow: hidden;
        background: #E5E7EB;
        display: flex;
    }
    .loc-split-fill-local  { height: 100%; background: #7A3F91; }
    .loc-split-fill-abroad { height: 100%; background: #C084FC; }

    /* ── Program breakdown mini table ────────────────────────────────────── */
    .section-title {
        font-size: 11.5px;
        font-weight: bold;
        color: #7A3F91;
        margin: 0 0 6px;
        text-transform: uppercase;
        letter-spacing: .03em;
    }

    @media print {
        body { padding: 6px; }
        .no-print { display: none !important; }
    }
</style>
</head>
<body>

    @php
        // ── Scope-wide stats, computed directly from $records (already
        //    filtered by Program / Batch Year on the server side, and
        //    already includes alumni with no employment_tracking row —
        //    same as the dashboard's own computeStats()). ──────────────────
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

        // Program breakdown, aggregated from the same $records set.
        $byProgram = $records->groupBy(fn($r) => $r->course_code ?? '—')->map(function ($grp, $code) {
            $t = $grp->count();
            $e = $grp->where('employment_status', 'employed')->count();
            $s = $grp->where('employment_status', 'self_employed')->count();
            $u = $grp->where('employment_status', 'unemployed')->count();
            $w = $e + $s;
            return (object) [
                'course_code' => $code,
                'total'       => $t,
                'employed'    => $e,
                'self_employed' => $s,
                'unemployed'  => $u,
                'not_filled'  => max(0, $t - ($e + $s + $u)),
                'rate'        => $t > 0 ? round($w / $t * 100, 1) : 0,
            ];
        })->sortByDesc('rate')->values();

        // ── SVG donut geometry ──────────────────────────────────────────────
        $donutRadius = 42;
        $donutCirc   = 2 * M_PI * $donutRadius;

        $buildDonut = function (array $items) use ($donutCirc) {
            $sum    = array_sum(array_column($items, 'value'));
            $cursor = 0;
            $segs   = [];
            foreach ($items as $it) {
                $pct    = $sum > 0 ? $it['value'] / $sum : 0;
                $dash   = $pct * $donutCirc;
                $segs[] = [
                    'color'  => $it['color'],
                    'label'  => $it['label'],
                    'value'  => $it['value'],
                    'pct'    => $sum > 0 ? round($it['value'] / $sum * 100, 1) : 0,
                    'dash'   => $dash,
                    'gap'    => $donutCirc - $dash,
                    'offset' => -$cursor,
                ];
                $cursor += $dash;
            }
            return ['segments' => $segs, 'total' => $sum];
        };

        $statusDonut = $buildDonut([
            ['label' => 'Employed',      'value' => $employedCnt,   'color' => '#10b981'],
            ['label' => 'Self-Employed', 'value' => $selfCnt,       'color' => '#3b82f6'],
            ['label' => 'Unemployed',    'value' => $unemployedCnt, 'color' => '#f59e0b'],
            ['label' => 'No Record',     'value' => $noRecordCnt,   'color' => '#d1d5db'],
        ]);

        $relevanceDonut = $buildDonut([
            ['label' => 'Relevant',           'value' => $relYesCnt,     'color' => '#10b981'],
            ['label' => 'Partially Relevant',  'value' => $relPartialCnt, 'color' => '#f59e0b'],
            ['label' => 'Not Relevant',        'value' => $relNoCnt,      'color' => '#ef4444'],
        ]);
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
             EXCEL — kept as a flat per-alumni table (spreadsheet users
             need raw rows to sort/filter themselves, not charts).
        ═══════════════════════════════════════════════════════════ --}}
        <table>
            <thead>
                <tr>
                    <th style="width:4%;">#</th>
                    <th style="width:20%;">Name</th>
                    <th style="width:12%;">Student ID</th>
                    <th style="width:10%;">Course</th>
                    <th style="width:7%;">Batch</th>
                    <th style="width:12%;">Status</th>
                    <th style="width:10%;">Relevance</th>
                    <th style="width:9%;">Location</th>
                    <th style="width:16%;">Email</th>
                </tr>
            </thead>
            <tbody>
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
                    $badgeClass  = $statusKey ? ('badge-' . $statusKey) : 'badge-none';

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
                    <td><span class="badge {{ $badgeClass }}">{{ $statusLabel }}</span></td>
                    <td>{{ $relevanceLabel }}</td>
                    <td>{{ $locationLabel }}</td>
                    <td>{{ $row->email ?? '—' }}</td>
                </tr>
                @empty
                <tr>
                    <td colspan="9" style="text-align:center;padding:20px;color:#333333;">No records found for the selected scope.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    @else
        {{-- ═══════════════════════════════════════════════════════════
             PDF / PRINT — same visual summary as the live dashboard:
             stat cards, Employment Status + Job Relevance pie charts,
             work location split, and the Program Breakdown table.
        ═══════════════════════════════════════════════════════════ --}}

        {{-- Stat summary --}}
        <div class="stat-summary-row">
            <div class="stat-box c-submitted">
                <div class="stat-num">{{ number_format($submittedCnt) }}</div>
                <div class="stat-lbl">Submitted</div>
                <div class="stat-rate">{{ $responseRate }}% response rate</div>
            </div>
            <div class="stat-box c-working">
                <div class="stat-num">{{ number_format($workingCnt) }}</div>
                <div class="stat-lbl">Working</div>
                <div class="stat-rate">{{ $empRate }}% of total alumni</div>
            </div>
            <div class="stat-box c-unemployed">
                <div class="stat-num">{{ number_format($unemployedCnt) }}</div>
                <div class="stat-lbl">Unemployed</div>
                <div class="stat-rate">{{ $unempRate }}% of submitted</div>
            </div>
            <div class="stat-box c-norecord">
                <div class="stat-num">{{ number_format($noRecordCnt) }}</div>
                <div class="stat-lbl">No Record</div>
                <div class="stat-rate">{{ $noRecordRate }}% of total alumni</div>
            </div>
        </div>

        {{-- Pie charts: Employment Status + Job Relevance --}}
        <div class="charts-row">
            <div class="donut-block">
                <div class="donut-block-title">Employment Status</div>
                <div class="donut-block-body">
                    <svg width="110" height="110" viewBox="0 0 140 140">
                        <g transform="rotate(-90 70 70)">
                            @foreach($statusDonut['segments'] as $seg)
                                @if($seg['value'] > 0)
                                <circle cx="70" cy="70" r="{{ $donutRadius }}" fill="none"
                                        stroke="{{ $seg['color'] }}" stroke-width="16"
                                        stroke-dasharray="{{ $seg['dash'] }} {{ $seg['gap'] }}"
                                        stroke-dashoffset="{{ $seg['offset'] }}" />
                                @endif
                            @endforeach
                        </g>
                        <circle cx="70" cy="70" r="26" fill="#ffffff" />
                        <text x="70" y="66" text-anchor="middle" font-size="20" font-weight="bold" fill="#111111">{{ number_format($statusDonut['total']) }}</text>
                        <text x="70" y="82" text-anchor="middle" font-size="9" fill="#333333">Total</text>
                    </svg>
                    <div class="donut-legend">
                        @foreach($statusDonut['segments'] as $seg)
                        <div class="legend-row">
                            <span class="legend-dot" style="background:{{ $seg['color'] }}"></span>
                            <span class="legend-label">{{ $seg['label'] }}</span>
                            <span class="legend-value">{{ number_format($seg['value']) }} ({{ $seg['pct'] }}%)</span>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="donut-block">
                <div class="donut-block-title">Job Relevance</div>
                <div class="donut-block-body">
                    <svg width="110" height="110" viewBox="0 0 140 140">
                        <g transform="rotate(-90 70 70)">
                            @foreach($relevanceDonut['segments'] as $seg)
                                @if($seg['value'] > 0)
                                <circle cx="70" cy="70" r="{{ $donutRadius }}" fill="none"
                                        stroke="{{ $seg['color'] }}" stroke-width="16"
                                        stroke-dasharray="{{ $seg['dash'] }} {{ $seg['gap'] }}"
                                        stroke-dashoffset="{{ $seg['offset'] }}" />
                                @endif
                            @endforeach
                        </g>
                        <circle cx="70" cy="70" r="26" fill="#ffffff" />
                        <text x="70" y="66" text-anchor="middle" font-size="20" font-weight="bold" fill="#111111">{{ number_format($relevanceDonut['total']) }}</text>
                        <text x="70" y="82" text-anchor="middle" font-size="9" fill="#333333">Working</text>
                    </svg>
                    <div class="donut-legend">
                        @foreach($relevanceDonut['segments'] as $seg)
                        <div class="legend-row">
                            <span class="legend-dot" style="background:{{ $seg['color'] }}"></span>
                            <span class="legend-label">{{ $seg['label'] }}</span>
                            <span class="legend-value">{{ number_format($seg['value']) }} ({{ $seg['pct'] }}%)</span>
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- Work location split --}}
        <div class="loc-split-block">
            <div class="loc-split-title">Work Location</div>
            <div class="loc-split-nums">
                <span class="n-local">Local: {{ number_format($localCnt) }} ({{ $localPct }}%)</span>
                <span class="n-abroad">OFW / Abroad: {{ number_format($abroadCnt) }} ({{ $abroadPct }}%)</span>
            </div>
            <div class="loc-split-bar">
                @if($locTotal > 0)
                    <div class="loc-split-fill-local"  style="width:{{ $localPct }}%;"></div>
                    <div class="loc-split-fill-abroad" style="width:{{ $abroadPct }}%;"></div>
                @endif
            </div>
        </div>

        {{-- Program breakdown --}}
        <p class="section-title">Program Breakdown</p>
        <table>
            <thead>
                <tr>
                    <th style="width:20%;">Program</th>
                    <th style="width:10%;">Total</th>
                    <th style="width:14%;">Employed</th>
                    <th style="width:14%;">Self-Employed</th>
                    <th style="width:14%;">Unemployed</th>
                    <th style="width:12%;">No Record</th>
                    <th style="width:16%;">Employment Rate</th>
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
                    <td>{{ $pr->rate }}%</td>
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