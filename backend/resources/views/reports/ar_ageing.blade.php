<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Receivables ageing</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 9.5px; color: #222; }
.page { padding: 18px 22px; }
.head { border-bottom: 3px solid #2B50E0; padding-bottom: 8px; margin-bottom: 10px; }
.company { font-size: 15px; font-weight: bold; color: #2B50E0; }
.title { font-size: 17px; font-weight: bold; color: #2B50E0; letter-spacing: 1px; margin: 8px 0 2px; }
.sub { font-size: 9px; color: #555; }
.cards { display: table; width: 100%; margin: 8px 0 12px; }
.card { display: table-cell; width: 16.6%; padding: 7px 8px; background: #f5f5f5; border: 1px solid #e0e0e0; text-align: center; }
.card .v { font-size: 12px; font-weight: bold; }
.card .l { font-size: 8px; color: #777; text-transform: uppercase; margin-top: 2px; }
table.t { width: 100%; border-collapse: collapse; }
th { background: #EEF1FE; color: #2B50E0; font-size: 8px; text-transform: uppercase; text-align: left; padding: 5px 6px; border: 1px solid #d5dcf5; }
td { padding: 4px 6px; border: 1px solid #e3e3e3; }
.r { text-align: right; }
.red { color: #D6322A; }
tr.total td { font-weight: bold; background: #fafafa; }
</style>
</head>
<body>
<div class="page">
  <div class="head"><div class="company">{{ $company }}</div></div>
  <div class="title">RECEIVABLES AGEING</div>
  <div class="sub">As of {{ \Carbon\Carbon::parse($as_of)->format('d M Y') }} &middot; {{ count($rows) }} invoice(s) with a balance</div>

  <div class="cards">
    <div class="card"><div class="v">₹ {{ \App\Support\Inr::fmt($summary['total_ar'], 2) }}</div><div class="l">Total receivable</div></div>
    <div class="card"><div class="v">₹ {{ \App\Support\Inr::fmt($summary['current'], 2) }}</div><div class="l">Not yet due</div></div>
    <div class="card"><div class="v">₹ {{ \App\Support\Inr::fmt($summary['30_days'], 2) }}</div><div class="l">1-30 days</div></div>
    <div class="card"><div class="v">₹ {{ \App\Support\Inr::fmt($summary['60_days'], 2) }}</div><div class="l">31-60 days</div></div>
    <div class="card"><div class="v">₹ {{ \App\Support\Inr::fmt($summary['90_days'], 2) }}</div><div class="l">61-90 days</div></div>
    <div class="card"><div class="v">₹ {{ \App\Support\Inr::fmt($summary['over_90_days'], 2) }}</div><div class="l">Over 90 days</div></div>
  </div>

  <table class="t">
    <thead><tr><th>Invoice</th><th>Customer</th><th>Date</th><th>Due</th><th class="r">Total</th><th class="r">Paid</th><th class="r">Balance</th><th class="r">Days overdue</th></tr></thead>
    <tbody>
      @forelse($rows as $r)
      <tr>
        <td>{{ $r['invoice_no'] }}</td>
        <td>{{ $r['customer_name'] }}</td>
        <td>{{ \Carbon\Carbon::parse($r['invoice_date'])->format('d M Y') }}</td>
        <td>{{ $r['due_date'] ? \Carbon\Carbon::parse($r['due_date'])->format('d M Y') : '' }}</td>
        <td class="r">₹ {{ \App\Support\Inr::fmt($r['total_amount'], 2) }}</td>
        <td class="r">₹ {{ \App\Support\Inr::fmt($r['paid_amount'], 2) }}</td>
        <td class="r">₹ {{ \App\Support\Inr::fmt($r['remaining_due'], 2) }}</td>
        <td class="r {{ $r['days_overdue'] > 0 ? 'red' : '' }}">{{ $r['days_overdue'] > 0 ? $r['days_overdue'] : 'not due' }}</td>
      </tr>
      @empty
      <tr><td colspan="8" style="text-align:center;color:#888">Nothing is outstanding.</td></tr>
      @endforelse
      <tr class="total"><td colspan="6">Total</td><td class="r">₹ {{ \App\Support\Inr::fmt($summary['total_ar'], 2) }}</td><td></td></tr>
    </tbody>
  </table>
</div>
</body>
</html>
