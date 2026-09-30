<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>{{ $artifact->kind === 'statement' ? 'Customer statement' : $snapshot['title'] }}</title>
<style>
@page { margin: 40px 32px 48px; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #182230; }
h1 { font-size: 20px; margin: 0 0 8px; } h2 { font-size: 12px; margin-top: 20px; }
p { line-height: 1.5; } .muted { color: #475467; } table { border-collapse: collapse; width: 100%; margin-top: 12px; table-layout: fixed; }
th, td { text-align: left; padding: 6px 5px; border-bottom: 1px solid #e4e7ec; word-wrap: break-word; vertical-align: top; }
th { background: #f2f4f7; } thead { display: table-header-group; } tr { page-break-inside: avoid; }
footer { position: fixed; bottom: -30px; font-size: 8px; color: #475467; } .page:after { content: counter(page); }
</style></head><body>
<footer>Confidential · {{ $artifact->artifact_reference }} · Page <span class="page"></span></footer>
<h1>{{ $manifest['business_name'] }}</h1>
@if ($manifest['supersedes_reference'] ?? null)<p>This issued statement supersedes {{ $manifest['supersedes_reference'] }}. The earlier statement remains preserved.</p>@endif
@if ($artifact->kind === 'statement')
<h2>Issued Customer statement · {{ $snapshot['customer_name'] }} · {{ $snapshot['customer_id'] }}</h2>
<p>{{ $snapshot['from'] }} through {{ $snapshot['to'] }} · {{ $snapshot['timezone'] }}<br>Cutoff {{ $snapshot['cutoff_at'] }} · Ledger watermark {{ $snapshot['ledger_watermark'] }}</p>
<p>Opening NGN {{ \App\Support\MoneyFormatter::decimal($snapshot['opening_kobo']) }} · Activity NGN {{ \App\Support\MoneyFormatter::decimal($snapshot['activity_kobo']) }} · Closing NGN {{ \App\Support\MoneyFormatter::decimal($snapshot['closing_kobo']) }}</p>
<table><thead><tr><th>Date</th><th>Reference</th><th>Type</th><th>Savings effect (NGN)</th><th>Fee (NGN)</th></tr></thead><tbody>
@foreach ($snapshot['lines'] as $line)
<tr><td>{{ $line['occurred_on'] }}</td><td>{{ $line['reference'] }}</td><td>{{ str_replace('_', ' ', $line['type']) }}</td><td>{{ \App\Support\MoneyFormatter::decimal($line['savings_effect_kobo']) }}</td><td>{{ \App\Support\MoneyFormatter::decimal($line['fee_amount_kobo']) }}</td></tr>
@endforeach
</tbody></table>
@else
<h2>{{ $snapshot['title'] }}</h2><p>Report—not an issued Customer statement.</p>
<p>Cutoff {{ $snapshot['manifest']['cutoff'] }} · {{ $snapshot['manifest']['timezone'] }} · Schema {{ $snapshot['manifest']['schema_version'] }}</p>
@foreach ($snapshot['sections'] as $name => $section)
<h2>{{ str_replace('_', ' ', $name) }} · {{ $section['status'] }}</h2>
<p class="muted">{{ $section['reason'] ?? '' }}</p>
@foreach ($section['metrics'] as $metric)<p>{{ $metric['title'] ?? $metric['label'] ?? $metric['code'] ?? 'Metric' }}: {{ $metric['display'] ?? $metric['formatted'] ?? $metric['value'] ?? 'Unavailable' }}</p>@endforeach
@foreach ($section['groups'] ?? [] as $group)
<h2>{{ $group['label'] }} · {{ $group['count'] }} records</h2>
@foreach ($group['metrics'] as $metric)<p>{{ $metric['label'] ?? $metric['code'] }}: {{ $metric['formatted'] ?? 'Unavailable' }}</p>@endforeach
@endforeach
@if (count($section['columns']) > 0)
<table><thead><tr>@foreach ($section['columns'] as $label)<th>{{ $label }}</th>@endforeach</tr></thead><tbody>
@foreach ($section['rows'] as $row)<tr>@foreach ($section['columns'] as $key => $label)<td>{{ $row[$key] ?? '' }}</td>@endforeach</tr>@endforeach
</tbody></table>
@endif
@endforeach
@endif
<p class="muted">Document {{ $artifact->artifact_reference }} · NGN · Captured {{ $manifest['captured_at'] }} · Snapshot {{ $manifest['snapshot_hash'] }}</p>
</body></html>
