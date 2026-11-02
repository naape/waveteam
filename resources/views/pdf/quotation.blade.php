<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $quotation->reference }}</title>
<style>
    @page { margin: 28mm 18mm 22mm; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10pt; color: #1e293b; }
    header { position: fixed; top: -20mm; left: 0; right: 0; border-bottom: 2px solid #0f58a2; padding-bottom: 4mm; }
    footer { position: fixed; bottom: -14mm; left: 0; right: 0; font-size: 8pt; color: #64748b; text-align: center; border-top: 1px solid #e2e8f0; padding-top: 2mm; }
    .brand { font-size: 16pt; font-weight: bold; color: #0f58a2; }
    .brand span { color: #6fb83f; }
    .muted { color: #64748b; }
    h1 { font-size: 18pt; color: #0a2c50; margin: 0 0 2mm; }
    table { width: 100%; border-collapse: collapse; }
    .meta td { padding: 1mm 0; vertical-align: top; }
    .items th { background: #0f58a2; color: #fff; font-size: 9pt; text-align: left; padding: 2.5mm; }
    .items td { padding: 2.5mm; border-bottom: 1px solid #e2e8f0; }
    .num { text-align: right; white-space: nowrap; }
    .totals td { padding: 1.5mm 2.5mm; }
    .grand td { font-size: 12pt; font-weight: bold; color: #0a2c50; border-top: 2px solid #0f58a2; }
    .box { border: 1px solid #e2e8f0; padding: 4mm; margin-top: 6mm; }
</style>
</head>
<body>
<header>
    <table><tr>
        <td><img src="data:image/png;base64,{{ base64_encode(file_get_contents(public_path('images/waveteam-lockup.png'))) }}" alt="WaveTeam Engineering" style="height:11mm"><div class="muted">{{ $company['address'] }}</div></td>
        <td class="num muted">{{ $company['phone'] }}<br>{{ $company['email'] }}<br>{{ str_replace('https://', '', $company['website']) }}</td>
    </tr></table>
</header>
<footer>{{ $company['name'] }} · {{ $quotation->reference }} · {{ __('This quotation is valid until the date shown and becomes binding only when accepted and confirmed by WaveTeam.') }}</footer>

<h1>{{ __('Quotation') }}</h1>
<table class="meta">
    <tr>
        <td style="width:55%">
            <strong>{{ $request->organization->name }}</strong><br>
            <span class="muted">{!! nl2br(e($request->organization->billing_address ?: $request->organization->address)) !!}</span>
        </td>
        <td>
            <table>
                <tr><td class="muted">{{ __('Quotation no.') }}</td><td class="num"><strong>{{ $quotation->reference }}</strong> (v{{ $quotation->version }})</td></tr>
                <tr><td class="muted">{{ __('Date') }}</td><td class="num">{{ now()->format('j M Y') }}</td></tr>
                @if ($quotation->valid_until)<tr><td class="muted">{{ __('Valid until') }}</td><td class="num">{{ $quotation->valid_until->format('j M Y') }}</td></tr>@endif
                <tr><td class="muted">{{ __('Your request') }}</td><td class="num">{{ $request->reference }}</td></tr>
            </table>
        </td>
    </tr>
</table>

<p style="margin-top:6mm"><strong>{{ $request->title }}</strong><br><span class="muted">{{ $request->location_text }}</span></p>

<table class="items">
    <thead><tr><th style="width:6%">#</th><th>{{ __('Description') }}</th><th class="num">{{ __('Qty') }}</th><th class="num">{{ __('Unit price') }}</th><th class="num">{{ __('Amount') }} ({{ $quotation->currency }})</th></tr></thead>
    <tbody>
        @foreach ($quotation->items as $i => $item)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $item->description }}</td>
                <td class="num">{{ rtrim(rtrim(number_format($item->quantity, 2), '0'), '.') }} {{ $item->unit }}</td>
                <td class="num">{{ number_format($item->unit_price, 2) }}</td>
                <td class="num">{{ number_format($item->line_total, 2) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
<table class="totals" style="width:50%; margin-left:50%; margin-top:3mm">
    <tr><td class="muted">{{ __('Subtotal') }}</td><td class="num">{{ number_format($quotation->subtotal, 2) }}</td></tr>
    <tr><td class="muted">{{ __('Tax') }}</td><td class="num">{{ number_format($quotation->tax_amount, 2) }}</td></tr>
    <tr class="grand"><td>{{ __('Total') }} ({{ $quotation->currency }})</td><td class="num">{{ number_format($quotation->total, 2) }}</td></tr>
</table>

@if ($quotation->terms)
    <div class="box"><strong>{{ __('Terms & conditions') }}</strong><br>{!! nl2br(e($quotation->terms)) !!}</div>
@endif

<p class="muted" style="margin-top:8mm">{{ __('To accept this quotation, sign in to the WaveTeam portal and open request :ref.', ['ref' => $request->reference]) }}</p>
</body>
</html>
