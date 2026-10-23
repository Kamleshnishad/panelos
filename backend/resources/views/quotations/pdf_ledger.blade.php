@php
    // All wording, grouping and rounding comes from QuotationDocumentService — this file only lays it out.
    $doc = app(\App\Services\QuotationDocumentService::class)->build($quotation);
    $a   = $doc['accent'];
    $co  = $doc['company'];
    $m   = $doc['meta'];
    $by  = $doc['buyer'];
    $pr  = $doc['project'];
    $t   = $doc['totals'];
    $n   = fn ($v) => number_format((float) $v, 2);
    $dash = fn ($v) => ($v !== null && trim((string) $v) !== '') ? $v : '—';
    $half = (int) ceil(count($doc['terms']) / 2);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $m['no'] }}</title>
<style>
@@page { margin: 34px 40px 62px 40px; }
body { margin: 0; font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; line-height: 1.45; color: #1c1f26; }
table { border-collapse: collapse; width: 100%; }
td, th { vertical-align: top; padding: 0; }
.r { text-align: right; }
.b { font-weight: bold; }
.lab { font-size: 8.5px; letter-spacing: 0.08em; text-transform: uppercase; color: #59616f; font-weight: bold; }
.val { font-size: 11px; font-weight: bold; }
.sub { font-size: 9.5px; color: #454c59; line-height: 1.5; }
.co { font-size: 15px; font-weight: bold; letter-spacing: 0.02em; margin-bottom: 2px; }
.title { font-size: 19px; font-weight: bold; letter-spacing: 0.1em; color: {{ $a }}; }
.no { font-size: 13px; font-weight: bold; margin-top: 3px; }
.rule { height: 2px; background: {{ $a }}; margin: 10px 0 0 0; }
.meta td { border-bottom: 1px solid #c9ced6; padding: 7px 8px 7px 0; }
.box { border: 1px solid #c9ced6; padding: 9px 11px; }
.box .nm { font-size: 12px; font-weight: bold; margin: 3px 0 2px 0; }
.it th { background: #1c1f26; color: #ffffff; font-size: 8.5px; letter-spacing: 0.06em; text-transform: uppercase; text-align: left; padding: 7px 6px; }
.it td.c { padding: 8px 6px; border-bottom: 1px solid #c9ced6; }
.it tr { page-break-inside: avoid; }
.dt { font-size: 11px; font-weight: bold; line-height: 1.4; }
.pt { font-size: 9.5px; font-weight: bold; color: {{ $a }}; margin: 3px 0 5px 0; letter-spacing: 0.04em; }
.ds { font-size: 9.5px; color: #454c59; line-height: 1.5; }
.sp td { padding: 1px 0; font-size: 9.5px; }
.sp td.k { width: 70px; color: #59616f; }
.sz { margin-top: 6px; }
.sz th.r, .sz td.r { text-align: right; }
.sz th { background: #eef0f4; color: #454c59; font-size: 7.5px; letter-spacing: 0.03em; text-transform: uppercase; padding: 3px 5px; text-align: left; white-space: nowrap; }
.sz td { padding: 3px 5px; font-size: 9.5px; border-bottom: 1px solid #e3e6ea; white-space: nowrap; }
.sz tr.tot td { font-weight: bold; border-bottom: 0; background: #f6f7f9; }
.dl { font-size: 7.5px; font-weight: bold; color: #ffffff; background: #b5740a; padding: 0 3px; }
.hd { font-size: 8.5px; letter-spacing: 0.08em; text-transform: uppercase; color: #59616f; font-weight: bold; margin: 0 0 5px 0; }
.words { border: 1px solid #c9ced6; padding: 8px 10px; font-size: 10px; }
.gs th { background: #eef0f4; color: #454c59; font-size: 8px; letter-spacing: 0.05em; text-transform: uppercase; padding: 4px 6px; text-align: left; }
.gs td { padding: 4px 6px; font-size: 9.5px; border-bottom: 1px solid #e3e6ea; }
.gs tr.tot td { font-weight: bold; background: #f6f7f9; border-bottom: 0; }
.tt td { padding: 5px 8px; font-size: 10.5px; border-bottom: 1px solid #e3e6ea; }
.tt td.v { text-align: right; font-weight: bold; }
.tt tr.gt td { background: {{ $a }}; color: #ffffff; font-size: 12.5px; font-weight: bold; padding: 9px 8px; border-bottom: 0; }
.tt tr.bal td { font-weight: bold; color: {{ $a }}; border-bottom: 0; }
.pay { border-left: 3px solid {{ $a }}; background: #f6f7f9; padding: 8px 10px; font-size: 9.5px; line-height: 1.55; }
.bank { border: 1px solid #c9ced6; padding: 8px 10px; }
.bank td { padding: 1.5px 0; font-size: 9.5px; }
.bank td.k { width: 74px; color: #59616f; }
.h1 { font-size: 15px; font-weight: bold; letter-spacing: 0.1em; color: {{ $a }}; margin: 0 0 10px 0; }
.tc td { padding: 7px 0; border-bottom: 1px solid #e3e6ea; font-size: 10px; }
.tc td.n { width: 22px; color: {{ $a }}; font-weight: bold; }
.tc .t { font-size: 8.5px; letter-spacing: 0.07em; text-transform: uppercase; color: #59616f; font-weight: bold; display: block; margin-bottom: 1px; }
.ex { border: 1px solid #c9ced6; padding: 10px 12px; margin-top: 14px; }
.ex td { padding: 2px 0; font-size: 10px; }
.ex td.n { width: 20px; color: {{ $a }}; font-weight: bold; }
.acc td.box2 { border: 1px solid #c9ced6; height: 132px; padding: 9px 11px; }
.sig { border-top: 1px solid #1c1f26; margin-top: 50px; padding-top: 4px; font-size: 9px; color: #454c59; }
.avoid { page-break-inside: avoid; }
#ftr { position: fixed; left: 0; right: 0; bottom: -44px; border-top: 1px solid #c9ced6; padding-top: 6px; font-size: 8.5px; color: #59616f; }
</style>
</head>
<body>

<div id="ftr">
    <table><tr>
        <td style="width: 40%">{{ $m['no'] }} &nbsp;·&nbsp; {{ $by['name'] }}</td>
        <td style="width: 20%"></td>
        <td class="r">Generated {{ now()->format('d M Y, H:i') }} &nbsp;·&nbsp; {{ config('app.name', 'PanelOS') }}</td>
    </tr></table>
</div>

{{-- ── Letterhead ───────────────────────────────────────────── --}}
<table>
<tr>
    <td style="width: 62%">
        @if($co['logo'])<img src="{{ $co['logo'] }}" alt="" style="height: 46px; margin-bottom: 6px">@endif
        <div class="co">{{ strtoupper($co['name']) }}</div>
        @if($co['address'])<div class="sub">{{ $co['address'] }}</div>@endif
        <div class="sub">{{ $co['phone'] }}@if($co['phone'] && $co['email']) &nbsp;|&nbsp; @endif{{ $co['email'] }}</div>
        @if($co['gstin'] || $co['pan'])
            <div class="sub">
                @if($co['gstin'])GSTIN <b>{{ $co['gstin'] }}</b>@endif
                @if($co['gstin'] && $co['pan']) &nbsp;|&nbsp; @endif
                @if($co['pan'])PAN <b>{{ $co['pan'] }}</b>@endif
            </div>
        @endif
    </td>
    <td class="r">
        <div class="title">{{ $doc['title'] }}</div>
        <div class="no">{{ $m['no'] }}</div>
        @if($m['revision'] > 1)<div class="sub b" style="color: #b5740a">REVISION {{ $m['revision'] }}</div>@endif
    </td>
</tr>
</table>
<div class="rule"></div>

<table class="meta">
<tr>
    <td><div class="lab">PFI No.</div><div class="val">{{ $m['no'] }}</div></td>
    <td><div class="lab">Date</div><div class="val">{{ $dash($m['date']) }}</div></td>
    <td><div class="lab">Valid until</div><div class="val">{{ $dash($m['valid_until']) }}</div></td>
    <td><div class="lab">Status</div><div class="val">{{ $m['status'] }}</div></td>
    <td><div class="lab">Place of supply</div><div class="val">{{ $dash($m['supply_to']) }}</div></td>
    <td style="padding-right: 0"><div class="lab">GST type</div><div class="val">{{ $m['gst_type'] }}</div></td>
</tr>
</table>

{{-- ── Parties ──────────────────────────────────────────────── --}}
<table style="margin-top: 12px">
<tr>
    <td style="width: 50%; padding-right: 6px">
        <div class="box">
            <div class="lab">Buyer · bill to</div>
            <div class="nm">{{ $by['name'] }}</div>
            @if($by['address'])<div class="sub">{{ $by['address'] }}</div>@endif
            @if($by['locality'])<div class="sub">{{ $by['locality'] }}</div>@endif
            <div class="sub">GSTIN: <b>{{ $dash($by['gstin']) }}</b> &nbsp;|&nbsp; PAN: <b>{{ $dash($by['pan']) }}</b></div>
            <div class="sub">Contact: <b>{{ $dash($by['contact']) }}</b> &nbsp;|&nbsp; Tel {{ $dash($by['phone']) }}@if($by['whatsapp']) &nbsp;|&nbsp; WhatsApp {{ $by['whatsapp'] }}@endif</div>
            @if($by['email'])<div class="sub">{{ $by['email'] }}</div>@endif
        </div>
    </td>
    <td style="width: 50%; padding-left: 6px">
        <div class="box">
            <div class="lab">Project · deliver to</div>
            <div class="nm">{{ $dash($pr['name']) }}</div>
            @if($pr['location'])<div class="sub">{{ $pr['location'] }}</div>@endif
            @if($pr['quality'])<div class="sub">Quality grade: <b>{{ $pr['quality'] }}</b></div>@endif
            @if($pr['prepared_by'])<div class="sub">Prepared by: <b>{{ $pr['prepared_by'] }}</b></div>@endif
        </div>
    </td>
</tr>
</table>

{{-- ── Items ────────────────────────────────────────────────── --}}
<table class="it" style="margin-top: 14px">
<thead>
<tr>
    <th style="width: 26px">Sr</th>
    <th>Description</th>
    <th style="width: 62px">HSN / SAC</th>
    <th class="r" style="width: 54px">Qty</th>
    <th style="width: 34px">UoM</th>
    <th class="r" style="width: 62px">Rate (₹)</th>
    <th class="r" style="width: 76px">Amount (₹)</th>
</tr>
</thead>
<tbody>
@foreach($doc['lines'] as $l)
<tr>
    <td class="c">{{ $l['sr'] }}</td>
    <td class="c">
        <div class="dt">{{ $l['title'] }}</div>
        @if($l['subtitle'])<div class="pt">{{ $l['subtitle'] }}</div>@endif
        @if($l['specs'])
            <table class="sp">
            @foreach($l['specs'] as $k => $v)
                <tr><td class="k">{{ $k }}</td><td>{{ $v }}</td></tr>
            @endforeach
            </table>
        @endif
        @if(!empty($l['description']))<div class="ds">{{ $l['description'] }}</div>@endif
        @if($l['sizes'])
            <table class="sz">
                <tr><th>Size (mm)</th><th class="r">Nos</th><th class="r">SQM</th><th class="r">Rate / SQM</th><th class="r">Amount</th></tr>
                @foreach($l['sizes'] as $s)
                <tr>
                    <td>{{ $s['length'] }} × {{ $s['width'] }}@if($s['dl']) <span class="dl">DL</span>@endif</td>
                    <td class="r">{{ $s['nos'] }}</td>
                    <td class="r">{{ $n($s['sqm']) }}</td><td class="r">{{ $n($s['rate']) }}</td><td class="r">{{ $n($s['amount']) }}</td>
                </tr>
                @endforeach
                <tr class="tot">
                    <td>TOTAL</td><td class="r">{{ collect($l['sizes'])->sum('nos') }}</td>
                    <td class="r">{{ $n($l['qty']) }}</td><td></td><td class="r">{{ $n($l['amount']) }}</td>
                </tr>
            </table>
        @endif
    </td>
    <td class="c">{{ $l['hsn'] }}</td>
    <td class="c r b">{{ $n($l['qty']) }}</td>
    <td class="c">{{ $l['uom'] }}</td>
    <td class="c r">{{ $n($l['rate']) }}</td>
    <td class="c r b">{{ $n($l['amount']) }}</td>
</tr>
@endforeach
</tbody>
</table>
@if(collect($doc['lines'])->contains(fn ($l) => collect($l['sizes'])->contains('dl', true)))
    <div class="sub" style="margin-top: 5px"><span class="dl">DL</span> Panels under 2000 mm are produced at double length and cut to size.</div>
@endif

{{-- ── Totals, tax, payment, bank (kept together) ───────────── --}}
<table class="avoid" style="margin-top: 16px">
<tr>
<td style="width: 55%; padding-right: 14px">
    <div class="hd">Amount in words</div>
    <div class="words"><b>{{ $doc['words'] }}</b></div>

    <div class="hd" style="margin-top: 14px">GST summary</div>
    <table class="gs">
        <tr>
            <th>HSN / SAC</th><th class="r">Taxable value</th>
            @if($m['inter_state'])<th class="r">IGST 18%</th>@else<th class="r">CGST 9%</th><th class="r">SGST 9%</th>@endif
            <th class="r">Total tax</th>
        </tr>
        @foreach($doc['hsn']['rows'] as $h)
        <tr>
            <td>{{ $h['hsn'] }}</td><td class="r">{{ $n($h['taxable']) }}</td>
            @if($m['inter_state'])<td class="r">{{ $n($h['igst']) }}</td>@else<td class="r">{{ $n($h['cgst']) }}</td><td class="r">{{ $n($h['sgst']) }}</td>@endif
            <td class="r">{{ $n($h['tax']) }}</td>
        </tr>
        @endforeach
        <tr class="tot">
            <td>Total</td><td class="r">{{ $n($doc['hsn']['total']['taxable']) }}</td>
            @if($m['inter_state'])<td class="r">{{ $n($doc['hsn']['total']['igst']) }}</td>@else<td class="r">{{ $n($doc['hsn']['total']['cgst']) }}</td><td class="r">{{ $n($doc['hsn']['total']['sgst']) }}</td>@endif
            <td class="r">{{ $n($doc['hsn']['total']['tax']) }}</td>
        </tr>
    </table>

    <div class="hd" style="margin-top: 14px">Payment schedule</div>
    <div class="pay">
        <b>{{ rtrim(rtrim($n($t['advance_pct']), '0'), '.') }}% advance — ₹ {{ $n($t['advance']) }}</b> at order confirmation.<br>
        <b>{{ rtrim(rtrim($n(100 - $t['advance_pct']), '0'), '.') }}% balance — ₹ {{ $n($t['balance']) }}</b> at least 2 working days before dispatch.
    </div>

    <div class="hd" style="margin-top: 14px">Remit to</div>
    <div class="bank">
        <table>
            <tr><td class="k">Account name</td><td><b>{{ $doc['bank']['account_name'] }}</b></td></tr>
            <tr><td class="k">Bank</td><td>{{ $dash(trim(($doc['bank']['bank'] ?? '') . ($doc['bank']['branch'] ? ' · ' . $doc['bank']['branch'] : ''))) }}</td></tr>
            <tr><td class="k">Account no.</td><td><b>{{ $dash($doc['bank']['account_no']) }}</b> &nbsp;·&nbsp; IFSC <b>{{ $dash($doc['bank']['ifsc']) }}</b></td></tr>
        </table>
    </div>
</td>

<td style="width: 45%">
    <table class="tt">
        <tr><td>Panel subtotal</td><td class="v">₹ {{ $n($t['panel']) }}</td></tr>
        @if($t['accessories'] > 0)<tr><td>Accessories &amp; doors</td><td class="v">₹ {{ $n($t['accessories']) }}</td></tr>@endif
        @if($t['installation'] > 0)<tr><td>Installation</td><td class="v">₹ {{ $n($t['installation']) }}</td></tr>@endif
        <tr><td><b>Subtotal</b></td><td class="v">₹ {{ $n($t['subtotal']) }}</td></tr>
        @if($t['discount_pct'] > 0)<tr><td>Discount ({{ $t['discount_pct'] + 0 }}%)</td><td class="v" style="color: #d6322a">- ₹ {{ $n($t['discount']) }}</td></tr>@endif
        <tr><td>Taxable amount</td><td class="v">₹ {{ $n($t['taxable']) }}</td></tr>
        @if($m['inter_state'])
            <tr><td>IGST @ 18%</td><td class="v">₹ {{ $n($t['igst']) }}</td></tr>
        @else
            <tr><td>CGST @ 9%</td><td class="v">₹ {{ $n($t['cgst']) }}</td></tr>
            <tr><td>SGST @ 9%</td><td class="v">₹ {{ $n($t['sgst']) }}</td></tr>
        @endif
        <tr><td>Transportation</td><td class="v">{{ $t['transport'] !== null ? '₹ ' . $n($t['transport']) : 'EXTRA AS ACTUAL' }}</td></tr>
        @if($t['round_off'] != 0)<tr><td>Round off</td><td class="v">₹ {{ $n($t['round_off']) }}</td></tr>@endif
        <tr class="gt"><td>GRAND TOTAL</td><td class="r">₹ {{ $n($t['total']) }}</td></tr>
        <tr><td>Advance ({{ rtrim(rtrim($n($t['advance_pct']), '0'), '.') }}%)</td><td class="v">₹ {{ $n($t['advance']) }}</td></tr>
        <tr class="bal"><td>Balance due</td><td class="r">₹ {{ $n($t['balance']) }}</td></tr>
    </table>
    <div class="sub r" style="margin-top: 8px">Total area: <b>{{ $n($t['total_sqm']) }} SQM</b> &nbsp;·&nbsp; This is a Proforma Invoice and not a valid tax document.</div>
</td>
</tr>
</table>

{{-- ── Terms, exclusions, acceptance ────────────────────────── --}}
<div style="page-break-before: always"></div>
<div class="h1">TERMS &amp; CONDITIONS</div>
<table>
<tr>
    @foreach([array_slice($doc['terms'], 0, $half, true), array_slice($doc['terms'], $half, null, true)] as $ci => $col)
    <td style="width: 50%; {{ $ci === 0 ? 'padding-right: 16px' : 'padding-left: 16px' }}">
        <table class="tc">
        @foreach($col as $i => $term)
            <tr><td class="n">{{ $i + 1 }}</td><td><span class="t">{{ $term['title'] }}</span>{!! $term['text'] !!}</td></tr>
        @endforeach
        </table>
    </td>
    @endforeach
</tr>
</table>

@if($doc['exclusions'])
<div class="ex avoid">
    <div class="lab" style="margin-bottom: 5px">Exclusions — not included in this offer</div>
    <table>
    @foreach($doc['exclusions'] as $i => $line)
        <tr><td class="n">{{ $i + 1 }}</td><td>{!! $line !!}</td></tr>
    @endforeach
    </table>
</div>
@endif

@if($doc['notes'])
<div class="ex avoid">
    <div class="lab" style="margin-bottom: 5px">Special notes</div>
    <div style="font-size: 10px">{{ $doc['notes'] }}</div>
</div>
@endif

<table class="acc avoid" style="margin-top: 22px">
<tr>
    <td class="box2" style="width: 50%">
        <div class="lab">Accepted by — {{ $by['name'] }}</div>
        <div class="sub" style="margin-top: 6px">Name: ______________________________</div>
        <div class="sub" style="margin-top: 6px">Date: _______________</div>
        <div class="sig">Customer stamp &amp; signature</div>
    </td>
    <td style="width: 14px"></td>
    <td class="box2" style="width: 50%">
        <div class="lab">For {{ strtoupper($co['name']) }}</div>
        @if($doc['signatory']['name'])
            <div style="font-size: 11px; font-weight: bold; margin-top: 6px">{{ $doc['signatory']['name'] }}</div>
            @if($doc['signatory']['phone'])<div class="sub">{{ $doc['signatory']['phone'] }}</div>@endif
        @endif
        <div class="sig">Authorised signatory</div>
    </td>
</tr>
</table>

</body>
</html>
