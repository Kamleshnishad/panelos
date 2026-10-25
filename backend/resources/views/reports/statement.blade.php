<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Statement of account - {{ $customer['name'] }}</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 10px; color: #222; }
.page { padding: 20px 24px; }
.head { border-bottom: 3px solid #2B50E0; padding-bottom: 8px; margin-bottom: 12px; }
.company { font-size: 16px; font-weight: bold; color: #2B50E0; }
.sub { font-size: 9px; color: #555; margin-top: 2px; line-height: 1.45; }
.title { font-size: 18px; font-weight: bold; color: #2B50E0; letter-spacing: 1px; margin: 10px 0 2px; }
.box { background: #f5f5f5; border: 1px solid #e0e0e0; padding: 8px 10px; margin-bottom: 10px; }
.label { font-size: 8px; color: #888; text-transform: uppercase; letter-spacing: .5px; }
.val { font-weight: bold; font-size: 11px; margin-top: 2px; }
table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
th { background: #EEF1FE; color: #2B50E0; font-size: 8px; text-transform: uppercase; text-align: left; padding: 5px 6px; border: 1px solid #d5dcf5; }
td { padding: 5px 6px; border: 1px solid #e3e3e3; vertical-align: top; }
.r { text-align: right; }
.total td { font-weight: bold; background: #fafafa; }
.red { color: #D6322A; }
h3 { font-size: 11px; color: #2B50E0; margin: 12px 0 6px; }
.note { font-size: 8.5px; color: #777; margin-top: 8px; }
</style>
</head>
<body>
<div class="page">
  <div class="head">
    <div class="company">{{ $company['name'] }}</div>
    <div class="sub">{{ $company['address'] }}@if($company['phone']) &middot; {{ $company['phone'] }}@endif @if($company['gstin'])<br>GSTIN {{ $company['gstin'] }}@endif</div>
  </div>
  <div class="title">STATEMENT OF ACCOUNT</div>
  <div class="sub" style="margin-bottom:8px">{{ \Carbon\Carbon::parse($period['from'])->format('d M Y') }} to {{ \Carbon\Carbon::parse($period['to'])->format('d M Y') }}</div>

  <div class="box">
    <div class="label">Customer</div>
    <div class="val">{{ $customer['name'] }}</div>
    <div class="sub">{{ $customer['address'] }}@if($customer['gstin']) &middot; GSTIN {{ $customer['gstin'] }}@endif</div>
  </div>

  <table>
    <thead><tr><th style="width:11%">Date</th><th>Details</th><th style="width:14%">Reference</th><th class="r" style="width:14%">Billed (Dr)</th><th class="r" style="width:14%">Received (Cr)</th><th class="r" style="width:15%">Balance</th></tr></thead>
    <tbody>
      <tr class="total"><td colspan="5">Opening balance</td><td class="r">₹ {{ \App\Support\Inr::fmt($opening_balance, 2) }}</td></tr>
      @forelse($lines as $l)
      <tr>
        <td>{{ \Carbon\Carbon::parse($l['date'])->format('d M Y') }}</td>
        <td>{{ $l['description'] }}</td>
        <td>{{ $l['reference'] }}</td>
        <td class="r">@if($l['debit'] > 0)₹ {{ \App\Support\Inr::fmt($l['debit'], 2) }}@endif</td>
        <td class="r">@if($l['credit'] > 0)₹ {{ \App\Support\Inr::fmt($l['credit'], 2) }}@endif</td>
        <td class="r">₹ {{ \App\Support\Inr::fmt($l['balance'], 2) }}</td>
      </tr>
      @empty
      <tr><td colspan="6" style="text-align:center;color:#888">No invoices or payments in this period.</td></tr>
      @endforelse
      <tr class="total"><td colspan="3">Totals for the period</td><td class="r">₹ {{ \App\Support\Inr::fmt($total_debit, 2) }}</td><td class="r">₹ {{ \App\Support\Inr::fmt($total_credit, 2) }}</td><td></td></tr>
      <tr class="total"><td colspan="5">Closing balance (amount due from the customer)</td><td class="r {{ $closing_balance > 0 ? 'red' : '' }}">₹ {{ \App\Support\Inr::fmt($closing_balance, 2) }}</td></tr>
    </tbody>
  </table>

  @if(count($outstanding))
  <h3>Invoices still unpaid on {{ \Carbon\Carbon::parse($period['to'])->format('d M Y') }}</h3>
  <table>
    <thead><tr><th>Invoice</th><th>Date</th><th>Due</th><th class="r">Total</th><th class="r">Paid</th><th class="r">Balance</th><th class="r">Days overdue</th></tr></thead>
    <tbody>
      @foreach($outstanding as $o)
      <tr>
        <td>{{ $o['invoice_no'] }}</td>
        <td>{{ \Carbon\Carbon::parse($o['invoice_date'])->format('d M Y') }}</td>
        <td>{{ $o['due_date'] ? \Carbon\Carbon::parse($o['due_date'])->format('d M Y') : '' }}</td>
        <td class="r">₹ {{ \App\Support\Inr::fmt($o['total'], 2) }}</td>
        <td class="r">₹ {{ \App\Support\Inr::fmt($o['paid'], 2) }}</td>
        <td class="r">₹ {{ \App\Support\Inr::fmt($o['balance'], 2) }}</td>
        <td class="r {{ $o['days_overdue'] > 0 ? 'red' : '' }}">{{ $o['days_overdue'] > 0 ? $o['days_overdue'] : 'not due' }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>
  @endif
  <div class="note">This statement lists invoices (including GST) and the payments received against them. Write-offs are shown as received entries marked "Written off". Please tell us of any difference.</div>
</div>
</body>
</html>
