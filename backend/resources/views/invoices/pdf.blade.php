<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $invoice->invoice_no }}</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #222; }
.page { padding: 20px 24px; }

/* Header */
.doc-header { display: table; width: 100%; border-bottom: 3px solid #2B50E0; padding-bottom: 10px; margin-bottom: 12px; }
.logo-cell { display: table-cell; width: 58%; vertical-align: top; }
.company-logo { max-height: 52px; max-width: 220px; margin-bottom: 6px; }
.company-name { font-size: 17px; font-weight: bold; color: #2B50E0; }
.company-sub { font-size: 9px; color: #555; margin-top: 2px; line-height: 1.45; }
.title-cell { display: table-cell; width: 42%; vertical-align: top; text-align: right; }
.doc-title { font-size: 22px; font-weight: bold; color: #2B50E0; letter-spacing: 2px; }
.doc-subtitle { font-size: 11px; color: #777; margin-top: 3px; }
.status-badge { display: inline-block; padding: 3px 12px; border-radius: 3px; font-size: 9px; font-weight: bold; margin-top: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
.status-draft { background: #e0e0e0; color: #333; }
.status-sent { background: #EEF1FE; color: #2B50E0; }
.status-accepted { background: #EFEAFB; color: #6B3FC9; }
.status-paid { background: #E4F5EC; color: #14894E; }
.status-cancelled { background: #ffebee; color: #D6322A; }
.status-overdue { background: #FDECEA; color: #D6322A; }

/* Meta bar */
.meta-bar { display: table; width: 100%; background: #f5f5f5; padding: 8px 10px; margin-bottom: 10px; border: 1px solid #e0e0e0; }
.meta-cell { display: table-cell; width: 25%; }
.meta-label { font-size: 8px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; }
.meta-value { font-weight: bold; font-size: 11px; color: #333; margin-top: 2px; }

/* Parties */
.parties { display: table; width: 100%; margin-bottom: 12px; }
.party-cell { display: table-cell; width: 50%; padding: 8px 10px; vertical-align: top; }
.party-cell.left { border: 1px solid #ddd; border-radius: 3px; }
.party-cell.right { padding-left: 18px; }
.party-label { font-size: 8px; text-transform: uppercase; letter-spacing: 1px; color: #888; margin-bottom: 4px; }
.party-name { font-size: 13px; font-weight: bold; color: #2B50E0; }
.party-detail { font-size: 10px; color: #555; margin-top: 2px; line-height: 1.4; }

/* Items table */
table { width: 100%; border-collapse: collapse; }
.items-table { margin-bottom: 12px; font-size: 10px; }
.items-table th { background: #2B50E0; color: white; padding: 8px; text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: 0.3px; }
.items-table td { padding: 8px; vertical-align: top; border-bottom: 1px solid #E4E7EC; }
.items-table tr:last-child td { border-bottom: 1px solid #c9cdd6; }
.desc-text { font-weight: bold; color: #15181E; line-height: 1.35; }
.desc-detail { font-size: 8.5px; color: #667085; margin-top: 2px; }

/* Bottom split: words + totals */
.bottom { display: table; width: 100%; margin-bottom: 12px; }
.bottom-left { display: table-cell; width: 45%; vertical-align: top; padding-right: 14px; }
.bottom-right { display: table-cell; width: 55%; vertical-align: top; }
.words-box { border: 1px solid #ddd; border-radius: 3px; padding: 8px 10px; font-size: 9.5px; color: #444; }
.words-label { font-size: 8px; text-transform: uppercase; letter-spacing: 0.5px; color: #888; margin-bottom: 3px; }

/* Totals */
.totals-wrap { width: 100%; border: 1px solid #ddd; }
.total-row { display: table; width: 100%; }
.total-label { display: table-cell; padding: 6px 10px; width: 60%; }
.total-value { display: table-cell; padding: 6px 10px; text-align: right; font-weight: bold; border-left: 1px solid #eee; }
.total-row.sub-line { background: #f7f8fa; border-bottom: 1px solid #eee; }
.total-row.section-line { border-top: 2px solid #2B50E0; border-bottom: 2px solid #2B50E0; }
.total-row.grand { background: #2B50E0; color: white; font-size: 13px; font-weight: bold; }
.total-row.grand .total-value { border-left: 1px solid #2140C0; }
.total-row.due { background: #FDECEA; color: #D6322A; font-weight: bold; }
.total-row.due .total-value { border-left: 1px solid #f3c7c1; }
.total-row.paid .total-value { color: #14894E; }

/* Payment / bank info */
.info-box { background: #EEF1FE; border: 1px solid #D6DEFB; border-radius: 3px; padding: 10px 12px; margin-bottom: 12px; font-size: 10px; color: #333; }
.info-box h4 { color: #2B50E0; font-size: 11px; margin-bottom: 4px; }
.notes-box { background: #f9f9f9; border: 1px solid #eee; border-radius: 3px; padding: 10px 12px; margin-bottom: 12px; font-size: 10px; }
.notes-box h4 { font-size: 10px; color: #555; margin-bottom: 3px; text-transform: uppercase; letter-spacing: 0.4px; }

/* Signature + footer */
.sign-block { display: table; width: 100%; margin-top: 24px; }
.sign-cell { display: table-cell; width: 50%; vertical-align: bottom; }
.sign-line { border-top: 1px solid #333; margin-top: 44px; padding-top: 4px; font-size: 10px; font-weight: bold; }
.page-footer { border-top: 1px solid #ddd; padding-top: 8px; margin-top: 14px; font-size: 9px; color: #888; text-align: center; }

.text-right { text-align: right; }
.text-center { text-align: center; }
.bold { font-weight: bold; }
</style>
</head>
<body>

@php
  $company = $invoice->company;
  $billCustomer = optional(optional(optional($invoice->dispatch)->batch)->order)->customer
      ?? optional($invoice->order)->customer;

  $tc        = $invoice->taxCalculation;
  $taxable   = $tc->taxable_amount ?? $invoice->subtotal;
  $taxAmount = $tc->tax_amount ?? $invoice->tax_amount ?? 0;
  $igst      = $tc->igst_amount ?? 0;
  $cgst      = $tc->cgst_amount ?? 0;
  $sgst      = $tc->sgst_amount ?? 0;
  $isInter   = $igst > 0;
  $taxRate   = $tc->tax_rate ?? 18;

  $paid    = $invoice->payments ? $invoice->payments->sum('amount') : 0;
  $balance = max(0, $total - $paid);

  $overdue    = $invoice->due_date && $invoice->due_date->isPast() && $balance > 0.01 && $invoice->status !== 'cancelled';
  $statusText = $overdue ? 'overdue' : $invoice->status;
  $words      = \App\Services\InvoiceService::amountInWords((float)$total);
@endphp

<div class="page">

  <!-- Header -->
  <div class="doc-header">
    <div class="logo-cell">
      @if($company && $company->image_data_uri)
        <img src="{{ $company->image_data_uri }}" class="company-logo" alt="logo">
      @endif
      <div class="company-name">{{ strtoupper($company->name ?? 'COMPANY') }}</div>
      <div class="company-sub">{{ $company->address_line1 ?? 'PUF/PIR Panel Manufacturing' }}{{ ($company && $company->city) ? ', '.$company->city : '' }}{{ ($company && $company->state) ? ', '.$company->state : '' }} {{ $company->pincode ?? '' }}</div>
      <div class="company-sub">@if($company && $company->phone){{ $company->phone }}@endif @if($company && $company->email) | {{ $company->email }}@endif</div>
      @if($company && $company->gstin)<div class="company-sub">GSTIN: <strong>{{ $company->gstin }}</strong></div>@endif
    </div>
    <div class="title-cell">
      <div class="doc-title">TAX INVOICE</div>
      <div class="doc-subtitle">{{ $invoice->invoice_no }}</div>
      <span class="status-badge status-{{ $statusText }}">{{ $statusText }}</span>
    </div>
  </div>

  <!-- Meta bar -->
  <div class="meta-bar">
    <div class="meta-cell"><div class="meta-label">Invoice No.</div><div class="meta-value">{{ $invoice->invoice_no }}</div></div>
    <div class="meta-cell"><div class="meta-label">Invoice Date</div><div class="meta-value">{{ $invoice->invoice_date?->format('d/m/Y') }}</div></div>
    <div class="meta-cell"><div class="meta-label">Due Date</div><div class="meta-value">{{ $invoice->due_date?->format('d/m/Y') }}</div></div>
    <div class="meta-cell"><div class="meta-label">GST Type</div><div class="meta-value">{{ $isInter ? 'IGST' : 'CGST + SGST' }}</div></div>
  </div>

  <!-- Parties -->
  <div class="parties">
    <div class="party-cell left">
      <div class="party-label">Bill To</div>
      @if($billCustomer)
        <div class="party-name">{{ $billCustomer->name }}</div>
        <div class="party-detail">{{ $billCustomer->address_line1 ?? '' }}</div>
        <div class="party-detail">{{ $billCustomer->city ?? '' }}@if($billCustomer->state), {{ $billCustomer->state }}@endif {{ $billCustomer->pincode ?? '' }}</div>
        <div class="party-detail">{{ $billCustomer->country ?? 'India' }}</div>
        @if($billCustomer->gstin)<div class="party-detail">GSTIN: <strong>{{ $billCustomer->gstin }}</strong></div>@endif
        @if($billCustomer->phone)<div class="party-detail">Phone: {{ $billCustomer->phone }}</div>@endif
      @else
        <div class="party-detail">Customer information not available</div>
      @endif
    </div>
    <div class="party-cell right">
      <div class="party-label">Invoice Summary</div>
      <div class="party-detail">Taxable Value: <strong>₹ {{ number_format($taxable, 2) }}</strong></div>
      <div class="party-detail">Total GST ({{ rtrim(rtrim(number_format($taxRate,2),'0'),'.') }}%): <strong>₹ {{ number_format($taxAmount, 2) }}</strong></div>
      <div class="party-detail" style="margin-top:4px;font-size:13px;color:#2B50E0">Grand Total: <strong>₹ {{ number_format($total, 2) }}</strong></div>
      @if($balance > 0.01)
        <div class="party-detail" style="color:#D6322A">Balance Due: <strong>₹ {{ number_format($balance, 2) }}</strong></div>
      @else
        <div class="party-detail" style="color:#14894E"><strong>PAID IN FULL</strong></div>
      @endif
    </div>
  </div>

  <!-- Line items -->
  <table class="items-table">
    <thead>
      <tr>
        <th style="width:5%">#</th>
        <th style="width:37%">Item Description</th>
        <th style="width:9%" class="text-center">HSN</th>
        <th style="width:9%" class="text-center">Qty</th>
        <th style="width:13%" class="text-right">Rate</th>
        <th style="width:13%" class="text-right">Taxable</th>
        <th style="width:14%" class="text-right">Total</th>
      </tr>
    </thead>
    <tbody>
      @foreach($invoice->items as $i => $item)
      <tr>
        <td class="text-center">{{ $i + 1 }}</td>
        <td>
          <div class="desc-text">{{ $item->panelType->name ?? 'Item' }}@if($item->panelType && $item->panelType->thickness) — {{ rtrim(rtrim(number_format($item->panelType->thickness,2),'0'),'.') }}mm @endif</div>
          @if($item->panelType && $item->panelType->category)<div class="desc-detail">{{ ucfirst(str_replace('_',' ',$item->panelType->category)) }} panel</div>@endif
        </td>
        <td class="text-center">{{ $item->panelType->hsn_code ?? '—' }}</td>
        <td class="text-center">{{ rtrim(rtrim(number_format($item->quantity,2),'0'),'.') }}</td>
        <td class="text-right">₹ {{ number_format($item->unit_price, 2) }}</td>
        <td class="text-right">₹ {{ number_format($item->amount, 2) }}</td>
        <td class="text-right bold">₹ {{ number_format($item->total_with_tax, 2) }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>

  <!-- Words + totals -->
  <div class="bottom">
    <div class="bottom-left">
      <div class="words-box">
        <div class="words-label">Amount in Words</div>
        Indian Rupees {{ $words }} Only
      </div>
    </div>
    <div class="bottom-right">
      <div class="totals-wrap">
        <div class="total-row sub-line">
          <div class="total-label">Taxable Amount</div>
          <div class="total-value">₹ {{ number_format($taxable, 2) }}</div>
        </div>
        @if($isInter)
        <div class="total-row sub-line">
          <div class="total-label">IGST @ {{ rtrim(rtrim(number_format($taxRate,2),'0'),'.') }}%</div>
          <div class="total-value">₹ {{ number_format($igst, 2) }}</div>
        </div>
        @else
        <div class="total-row sub-line">
          <div class="total-label">CGST @ {{ rtrim(rtrim(number_format($taxRate/2,2),'0'),'.') }}%</div>
          <div class="total-value">₹ {{ number_format($cgst, 2) }}</div>
        </div>
        <div class="total-row sub-line">
          <div class="total-label">SGST @ {{ rtrim(rtrim(number_format($taxRate/2,2),'0'),'.') }}%</div>
          <div class="total-value">₹ {{ number_format($sgst, 2) }}</div>
        </div>
        @endif
        <div class="total-row grand">
          <div class="total-label">GRAND TOTAL</div>
          <div class="total-value">₹ {{ number_format($total, 2) }}</div>
        </div>
        @if($paid > 0.01)
        <div class="total-row sub-line paid">
          <div class="total-label">Amount Paid</div>
          <div class="total-value">₹ {{ number_format($paid, 2) }}</div>
        </div>
        @endif
        @if($balance > 0.01)
        <div class="total-row due">
          <div class="total-label">BALANCE DUE</div>
          <div class="total-value">₹ {{ number_format($balance, 2) }}</div>
        </div>
        @endif
      </div>
    </div>
  </div>

  <!-- Payment info -->
  @if($invoice->status !== 'draft' && $invoice->status !== 'cancelled' && $balance > 0.01)
  <div class="info-box">
    <h4>Payment Information</h4>
    <div>Please remit payment by <strong>{{ $invoice->due_date?->format('d M Y') }}</strong>.</div>
    @if($company && ($company->bank_name ?? false))
      <div style="margin-top:3px">Bank: {{ $company->bank_name }} @if($company->bank_account) | A/c: {{ $company->bank_account }}@endif @if($company->bank_ifsc) | IFSC: {{ $company->bank_ifsc }}@endif</div>
    @endif
  </div>
  @endif

  @if($invoice->notes)
  <div class="notes-box">
    <h4>Notes</h4>
    <div>{{ $invoice->notes }}</div>
  </div>
  @endif

  @if($invoice->terms)
  <div class="notes-box">
    <h4>Terms &amp; Conditions</h4>
    <div>{{ $invoice->terms }}</div>
  </div>
  @endif

  <!-- Signatory -->
  <div class="sign-block">
    <div class="sign-cell">
      <div style="font-size:9px;color:#888">Goods once sold will not be taken back. Subject to {{ $company->city ?? 'local' }} jurisdiction.</div>
    </div>
    <div class="sign-cell" style="text-align:right">
      <div class="sign-line">For {{ $company->name ?? 'Company' }}<br><span style="font-size:9px;color:#888;font-weight:normal">Authorised Signatory</span></div>
    </div>
  </div>

  <div class="page-footer">
    This is a computer-generated tax invoice. &nbsp;|&nbsp; Generated on {{ now()->format('d M Y H:i') }} &nbsp;|&nbsp; {{ config('app.name', 'PanelOS') }}
  </div>
</div>
</body>
</html>
