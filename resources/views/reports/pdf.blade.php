<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $reportName }}</title>
    <style>
        @page { margin: 100px 40px 60px 40px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        header { position: fixed; top: -80px; left: 0; right: 0; height: 70px;
                 border-bottom: 2px solid {{ $branding['primary_color'] }};
                 padding-bottom: 8px; }
        header .brand { font-size: 16px; font-weight: bold; color: {{ $branding['primary_color'] }}; }
        header .meta { font-size: 9px; color: #6b7280; margin-top: 2px; }
        footer { position: fixed; bottom: -40px; left: 0; right: 0; height: 30px;
                 border-top: 1px solid #e5e7eb; padding-top: 6px;
                 font-size: 9px; color: #6b7280; }
        footer .right { float: right; }
        footer .page::after { content: counter(page) " / " counter(pages); }
        h1 { font-size: 18px; margin: 0 0 4px 0; color: #111827; }
        .subtitle { color: #6b7280; font-size: 11px; margin-bottom: 18px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        thead th { background: {{ $branding['primary_color'] }}; color: #ffffff;
                   text-align: left; padding: 6px 8px; font-size: 10px; }
        tbody td { padding: 5px 8px; border-bottom: 1px solid #e5e7eb; }
        tbody tr:nth-child(even) td { background: #f9fafb; }
        .empty { padding: 16px; text-align: center; color: #9ca3af; font-style: italic; }
    </style>
</head>
<body>
    <header>
        <div class="brand">{{ $branding['display_name'] }}</div>
        <div class="meta">{{ $reportName }} &middot; {{ $reportType }} &middot; generated {{ $generatedAt }}</div>
    </header>

    <footer>
        <span>{{ $branding['display_name'] }} &middot; {{ $teamName }}</span>
        <span class="right">Page <span class="page"></span></span>
    </footer>

    <main>
        <h1>{{ $reportName }}</h1>
        <p class="subtitle">Period snapshot &middot; {{ count($metrics) }} metric{{ count($metrics) === 1 ? '' : 's' }}</p>

        @if (count($metrics) === 0)
            <div class="empty">No KPI records available for this report.</div>
        @else
            <table>
                <thead>
                    <tr>
                        <th>Metric</th>
                        <th>Period start</th>
                        <th>Period end</th>
                        <th>Value</th>
                        <th>Unit</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($metrics as $row)
                        <tr>
                            <td>{{ $row['code'] ?? '' }}</td>
                            <td>{{ $row['period_start'] ?? '' }}</td>
                            <td>{{ $row['period_end'] ?? '' }}</td>
                            <td>{{ $row['value'] ?? '' }}</td>
                            <td>{{ $row['unit'] ?? '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </main>
</body>
</html>
