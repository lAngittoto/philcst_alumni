\<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Employment Analytics Report</title>
<style>
    @page { size: A4 portrait; margin: 14mm 12mm; }
    * { box-sizing: border-box; }
    body {
        font-family: "Times New Roman", Times, serif;
        color: #111111;
        margin: 0;
        padding: 0;
        font-size: 12px;
    }

    .rp-header {
        display: table;
        width: 100%;
        border-bottom: 2px solid #7A3F91;
        padding-bottom: 8px;
        margin-bottom: 12px;
    }
    .rp-header-left, .rp-header-right {
        display: table-cell;
        vertical-align: bottom;
    }
    .rp-header-right { text-align: right; }
    .rp-header h1 { font-size: 18px; margin: 0; color: #7A3F91; }
    .rp-header p  { margin: 2px 0 0; font-size: 12px; color: #555555; }
    .rp-meta      { font-size: 12px; color: #555555; white-space: nowrap; line-height: 1.5; }

    /* ── Stat summary cards ── dompdf has no flexbox/grid, so this uses
       a table layout (display:table/table-row/table-cell) instead. */
    .rp-stats {
        display: table;
        width: 100%;
        table-layout: fixed;
        border-spacing: 4px 0;
        margin: 0 0 14px;
    }
    .rp-stats-row { display: table-row; }
    .rp-stat {
        display: table-cell;
        background: #F9F7FB;
        border: 1px solid #E8E0F0;
        padding: 7px 4px;
        text-align: center;
        vertical-align: middle;
    }
    .rp-stat .rp-stat-label {
        font-size: 9px;
        text-transform: uppercase;
        letter-spacing: .02em;
        color: #7A3F91;
        font-weight: 700;
        margin: 0 0 3px;
        white-space: nowrap;
    }
    .rp-stat .rp-stat-value {
        font-size: 16px;
        font-weight: 700;
        color: #333333;
        margin: 0;
    }

    /* ── Chart sections ── */
    .rp-section {
        border: 1px solid #E8E0F0;
        border-radius: 4px;
        margin-bottom: 10px;
        page-break-inside: avoid;
    }
    .rp-section-head {
        background: #F5F0FA;
        padding: 6px 10px;
        border-bottom: 1px solid #E0D3EC;
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .04em;
        color: #333333;
    }
    .rp-section-body { padding: 8px 10px; }

    /* One bar row: label | track | value, laid out as a table since
       dompdf can't reliably flex three inline pieces of differing
       width otherwise. */
    .rp-bar-row {
        display: table;
        width: 100%;
        table-layout: fixed;
        margin-bottom: 6px;
    }
    .rp-bar-row:last-child { margin-bottom: 0; }
    .rp-bar-label {
        display: table-cell;
        width: 30%;
        font-size: 12px;
        color: #333333;
        vertical-align: middle;
        padding-right: 6px;
        white-space: nowrap;
        overflow: hidden;
    }
    .rp-bar-track {
        display: table-cell;
        vertical-align: middle;
        background: #F0EBF5;
        border-radius: 3px;
        height: 14px;
    }
    .rp-bar-fill {
        height: 14px;
        border-radius: 3px;
        background: #7A3F91;
    }
    .rp-bar-value {
        display: table-cell;
        width: 44px;
        text-align: right;
        font-size: 12px;
        font-weight: 700;
        color: #333333;
        vertical-align: middle;
        padding-left: 6px;
        white-space: nowrap;
    }

    /* Grouped bars (Batch Year / College): three colored segments per
       row instead of one solid fill. */
    .rp-gbar-track {
        display: table-cell;
        vertical-align: middle;
        white-space: nowrap;
        height: 14px;
        border-radius: 3px;
        overflow: hidden;
        background: #F0EBF5;
    }
    .rp-gseg { display: inline-block; height: 14px; }
    .rp-gseg-employed   { background: #10b981; }
    .rp-gseg-self       { background: #3b82f6; }
    .rp-gseg-unemployed { background: #f59e0b; }

    .rp-legend { font-size: 11px; color: #666666; margin-top: 6px; }
    .rp-legend .sw {
        display: inline-block; width: 9px; height: 9px; border-radius: 2px;
        margin-right: 3px; vertical-align: middle;
    }

    .rp-two-col { display: table; width: 100%; table-layout: fixed; border-spacing: 8px 0; }
    .rp-two-col-row { display: table-row; }
    .rp-two-col-cell { display: table-cell; width: 50%; vertical-align: top; }

    .rp-empty-note { font-size: 11px; color: #999999; font-style: italic; padding: 4px 0; }
</style>
</head>
<body>

@php
    $generatedAt = $generatedAt ?? now();
    $s = fn($key) => number_format($data['stats'][$key] ?? 0);

    // Renders one "label — bar — value" row. $max lets every row in a
    // section share one common scale so bar lengths are comparable.
    $bar = function (string $label, $value, $max, string $color = '#7A3F91') {
        $pct = $max > 0 ? round(($value / $max) * 100) : 0;
        return '<div class="rp-bar-row">'
            . '<div class="rp-bar-label">' . e($label) . '</div>'
            . '<div class="rp-bar-track"><div class="rp-bar-fill" style="width:' . $pct . '%;background:' . $color . ';"></div></div>'
            . '<div class="rp-bar-value">' . number_format($value) . '</div>'
            . '</div>';
    };

    // Renders a single-metric chart section (labels[] + data[]).
    $simpleSection = function (string $title, array $chart, array $colors = []) use ($bar) {
        $labels = $chart['labels'] ?? [];
        $values = $chart['data']   ?? [];
        $max    = !empty($values) ? max($values) : 0;

        $html = '<div class="rp-section"><div class="rp-section-head">' . e($title) . '</div><div class="rp-section-body">';
        if (empty($labels)) {
            $html .= '<div class="rp-empty-note">No data for this scope.</div>';
        } else {
            foreach ($labels as $i => $label) {
                $color = $colors[$i] ?? '#7A3F91';
                $html .= $bar($label, $values[$i] ?? 0, $max, $color);
            }
        }
        $html .= '</div></div>';
        return $html;
    };

    // Renders a grouped chart section (Batch Year / College) — one row
    // per label, three stacked colored segments (employed/self/unemployed)
    // sized relative to that row's own total.
    $groupedSection = function (string $title, array $chart) {
        $labels     = $chart['labels']     ?? [];
        $employed   = $chart['employed']   ?? [];
        $selfEmp    = $chart['self_emp']   ?? [];
        $unemployed = $chart['unemployed'] ?? [];
        $totals     = $chart['total']      ?? [];
        $maxTotal   = !empty($totals) ? max($totals) : 0;

        $html = '<div class="rp-section"><div class="rp-section-head">' . e($title) . '</div><div class="rp-section-body">';
        if (empty($labels)) {
            $html .= '<div class="rp-empty-note">No data for this scope.</div>';
        } else {
            foreach ($labels as $i => $label) {
                $emp  = (int) ($employed[$i]   ?? 0);
                $self = (int) ($selfEmp[$i]    ?? 0);
                $unem = (int) ($unemployed[$i] ?? 0);
                $tot  = (int) ($totals[$i]     ?? 0);
                $rowMax = $maxTotal > 0 ? $maxTotal : 1;

                $wEmp  = $tot > 0 ? round(($emp  / $rowMax) * 100) : 0;
                $wSelf = $tot > 0 ? round(($self / $rowMax) * 100) : 0;
                $wUn   = $tot > 0 ? round(($unem / $rowMax) * 100) : 0;

                $html .= '<div class="rp-bar-row">'
                    . '<div class="rp-bar-label">' . e($label) . '</div>'
                    . '<div class="rp-gbar-track">'
                        . '<span class="rp-gseg rp-gseg-employed" style="width:' . $wEmp . '%;"></span>'
                        . '<span class="rp-gseg rp-gseg-self" style="width:' . $wSelf . '%;"></span>'
                        . '<span class="rp-gseg rp-gseg-unemployed" style="width:' . $wUn . '%;"></span>'
                    . '</div>'
                    . '<div class="rp-bar-value">' . number_format($tot) . '</div>'
                    . '</div>';
            }
            $html .= '<div class="rp-legend">'
                . '<span class="sw" style="background:#10b981;"></span>Employed &nbsp;'
                . '<span class="sw" style="background:#3b82f6;"></span>Self-Employed &nbsp;'
                . '<span class="sw" style="background:#f59e0b;"></span>Unemployed'
                . '</div>';
        }
        $html .= '</div></div>';
        return $html;
    };
@endphp

<div class="rp-header">
    <div class="rp-header-left">
        <h1>PHILCST Employment Analytics Report</h1>
        <p>Admin Office &middot; System-wide employment intelligence</p>
    </div>
    <div class="rp-header-right">
        <div class="rp-meta">
            Generated {{ $generatedAt->format('F j, Y g:i A') }}
        </div>
    </div>
</div>

@if(!empty($filterSummary))
<p style="font-size:12px;color:#555555;margin:-6px 0 12px;">
    <strong style="color:#7A3F91;">Report scope:</strong> {{ $filterSummary }}
</p>
@endif

{{-- ── Summary stat cards (row 1) ── --}}
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

{{-- ── Summary stat cards (row 2) ── --}}
<div class="rp-stats">
    <div class="rp-stats-row">
        <div class="rp-stat">
            <p class="rp-stat-label">Local</p>
            <p class="rp-stat-value">{{ $s('totalLocal') }}</p>
        </div>
        <div class="rp-stat">
            <p class="rp-stat-label">Abroad (OFW)</p>
            <p class="rp-stat-value">{{ $s('totalAbroad') }}</p>
        </div>
    </div>
</div>

{{-- ── Employment Status / Work Location ── --}}
<div class="rp-two-col">
    <div class="rp-two-col-row">
        <div class="rp-two-col-cell">
            {!! $simpleSection('Employment Status', $data['status'], ['#10b981', '#3b82f6', '#f59e0b', '#d1d5db']) !!}
        </div>
        <div class="rp-two-col-cell">
            {!! $simpleSection('Work Location', $data['location'], ['#7a3f91', '#e879f9']) !!}
        </div>
    </div>
</div>

{{-- ── Job-Course Relevance / Unemployed Breakdown ── --}}
<div class="rp-two-col">
    <div class="rp-two-col-row">
        <div class="rp-two-col-cell">
            {!! $simpleSection('Job-Course Relevance', $data['relevance'], ['#10b981', '#f59e0b', '#ef4444']) !!}
        </div>
        <div class="rp-two-col-cell">
            {!! $simpleSection('Unemployed Breakdown', $data['unemployed'], ['#f59e0b', '#9ca3af']) !!}
        </div>
    </div>
</div>

{{-- ── Employment Type / Further Education ── --}}
<div class="rp-two-col">
    <div class="rp-two-col-row">
        <div class="rp-two-col-cell">
            {!! $simpleSection('Employment Type', $data['empType'], ['#7a3f91', '#a855f7', '#c084fc', '#ddd6fe', '#ede9fe']) !!}
        </div>
        <div class="rp-two-col-cell">
            {!! $simpleSection('Further Education', $data['edu'], ['#9ca3af', '#3b82f6', '#7a3f91']) !!}
        </div>
    </div>
</div>

{{-- ── Career Path Labels ── --}}
{!! $simpleSection('Career Path Labels', $data['careerPath'], ['#f59e0b', '#10b981', '#3b82f6', '#ef4444', '#7a3f91']) !!}

{{-- ── Top Courses (Employed) ── --}}
{!! $simpleSection('Top Courses (Employed)', $data['course']) !!}

{{-- ── Employment by Batch Year ── --}}
{!! $groupedSection('Employment by Batch Year', $data['batch']) !!}

{{-- ── Employment by College ── --}}
{!! $groupedSection('Employment by College', $data['college']) !!}

</body>
</html>