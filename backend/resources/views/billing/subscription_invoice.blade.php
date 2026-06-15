<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $p->invoice_no }}</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #222; }
.page { padding: 24px 28px; }
.doc-header { display: table; width: 100%; border-bottom: 3px solid #2B50E0; padding-bottom: 10px; margin-bottom: 14px; }
.seller { display: table-cell; width: 60%; vertical-align: top; }
.seller .name { font-size: 17px; font-weight: bold; color: #2B50E0; }
.seller .sub { font-size: 10px; color: #555; margin-top: 2px; line-height: 1.5; }
.title-cell { display: table-cell; width: 40%; text-align: right; vertical-align: top; }
.doc-title { font-size: 20px; font-weight: bold; color: #2B50E0; letter-spacing: 1px; }
.doc-sub { font-size: 11px; color: #777; margin-top: 3px; }
.meta-bar { display: table; width: 100%; background: #f5f5f5; padding: 8px 10px; margin-bottom: 12px; border: 1px solid #e0e0e0; }
.meta-cell { display: table-cell; width: 33%; }
.meta-label { font-size: 8px; color: #888; text-transform: uppercase; letter-spacing: .5px; }
.meta-value { font-weight: bold; font-size: 11px; color: #333; margin-top: 2px; }
.party { border: 1px solid #ddd; border-radius: 3px; padding: 8px 10px; margin-bottom: 12px; }
.party-label { font-size: 8px; text-transform: uppercase; letter-spacing: 1px; color: #888; margin-bottom: 4px; }
.party-name { font-size: 13px; font-weight: bold; color: #2B50E0; }
.party-detail { font-size: 10px; color: #555; margin-top: 2px; }
table.items { width: 100%; border-collapse: collapse; margin-bottom: 12px; font-size: 10px; }
.items th { background: #2B50E0; color: #fff; padding: 8px; text-align: left; font-size: 9px; text-transform: uppercase; }
.items td { padding: 8px; border-bottom: 1px solid #E4E7EC; }
.r { text-align: right; }
.totals { width: 55%; margin-left: auto; border: 1px solid #ddd; }
.trow { display: table; width: 100%; }
.tl { display: table-cell; padding: 6px 10px; width: 60%; }
.tv { display: table-cell; padding: 6px 10px; text-align: right; font-weight: bold; border-left: 1px solid #eee; }
.trow.sub { background: #f7f8fa; border-bottom: 1px solid #eee; }
.trow.grand { background: #2B50E0; color: #fff; font-size: 13px; }
.words { border: 1px solid #ddd; border-radius: 3px; padding: 8px 10px; font-size: 10px; margin: 12px 0; }
.foot { border-top: 1px solid #ddd; padding-top: 8px; margin-top: 16px; font-size: 9px; color: #888; text-align: center; }
.sign { text-align: right; margin-top: 28px; font-size: 10px; }
.sign .line { border-top: 1px solid #333; display: inline-block; padding-top: 4px; margin-top: 36px; font-weight: bold; }
</style>
</head>
<body>
<div class="page">
  <div class="doc-header">
    <div class="seller">
      <div class="name">{{ strtoupper($platform['name']) }}</div>
      <div class="sub">{{ $platform['address'] }}@if($platform['state']), {{ $platform['state'] }}@endif</div>
      <div class="sub">@if($platform['email']){{ $platform['email'] }}@endif @if($platform['phone']) | {{ $platform['phone'] }}@endif</div>
      @if($platform['gstin'])<div class="sub">GSTIN: <strong>{{ $platform['gstin'] }}</strong></div>@endif
    </div>
    <div class="title-cell">
      <div class="doc-title">TAX INVOICE</div>
      <div class="doc-sub">{{ $p->invoice_no }}</div>
    </div>
  </div>

  <div class="meta-bar">
    <div class="meta-cell"><div class="meta-label">Invoice No</div><div class="meta-value">{{ $p->invoice_no }}</div></div>
    <div class="meta-cell"><div class="meta-label">Date</div><div class="meta-value">{{ $p->created_at->format('d/m/Y') }}</div></div>
    <div class="meta-cell"><div class="meta-label">Payment</div><div class="meta-value">{{ ucfirst($p->method) }}</div></div>
  </div>

  <div class="party">
    <div class="party-label">Billed To</div>
    <div class="party-name">{{ $company->name ?? '—' }}</div>
    <div class="party-detail">{{ $company->address_line1 ?? '' }}@if($company && $company->city), {{ $company->city }}@endif @if($company && $company->state){{ $company->state }}@endif</div>
    @if($company && $company->gstin)<div class="party-detail">GSTIN: <strong>{{ $company->gstin }}</strong></div>@endif
  </div>

  <table class="items">
    <thead>
      <tr><th style="width:8%">#</th><th>Description</th><th style="width:14%">SAC</th><th class="r" style="width:14%">Period</th><th class="r" style="width:18%">Amount</th></tr>
    </thead>
    <tbody>
      <tr>
        <td>1</td>
        <td>PanelOS Subscription — <strong>{{ ucfirst($p->plan) }}</strong> plan ({{ $p->months }} month{{ $p->months > 1 ? 's' : '' }})</td>
        <td>{{ $platform['hsn_sac'] }}</td>
        <td class="r">{{ $p->period_start?->format('d/m/y') }}–{{ $p->period_end?->format('d/m/y') }}</td>
        <td class="r"><strong>₹ {{ number_format($p->taxable_amount, 2) }}</strong></td>
      </tr>
    </tbody>
  </table>

  <div class="words">Amount in words: <strong>Indian Rupees {{ $words }} Only</strong></div>

  <div class="totals">
    <div class="trow sub"><div class="tl">Taxable Value</div><div class="tv">₹ {{ number_format($p->taxable_amount, 2) }}</div></div>
    @if($intra)
      <div class="trow sub"><div class="tl">CGST @ {{ rtrim(rtrim(number_format($p->gst_rate/2,2),'0'),'.') }}%</div><div class="tv">₹ {{ number_format($p->gst_amount/2, 2) }}</div></div>
      <div class="trow sub"><div class="tl">SGST @ {{ rtrim(rtrim(number_format($p->gst_rate/2,2),'0'),'.') }}%</div><div class="tv">₹ {{ number_format($p->gst_amount/2, 2) }}</div></div>
    @else
      <div class="trow sub"><div class="tl">IGST @ {{ rtrim(rtrim(number_format($p->gst_rate,2),'0'),'.') }}%</div><div class="tv">₹ {{ number_format($p->gst_amount, 2) }}</div></div>
    @endif
    <div class="trow grand"><div class="tl">TOTAL</div><div class="tv">₹ {{ number_format($p->total_amount, 2) }}</div></div>
  </div>

  <div class="sign"><div class="line">For {{ $platform['name'] }}<br><span style="font-size:9px;color:#888;font-weight:normal">Authorised Signatory</span></div></div>

  <div class="foot">This is a computer-generated tax invoice for SaaS subscription services. @if(!$platform['gstin'])(Set PLATFORM_GSTIN in .env once registered.)@endif</div>
</div>
</body>
</html>
