<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Delivery Challan {{ $dispatch->dispatch_no }}</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #222; }
  .page { padding: 22px 26px; }

  /* Header */
  .doc-header { display: table; width: 100%; border-bottom: 3px solid #2B50E0; padding-bottom: 10px; margin-bottom: 6px; }
  .hdr-left  { display: table-cell; width: 60%; vertical-align: top; }
  .hdr-right { display: table-cell; width: 40%; vertical-align: top; text-align: right; }
  .company-name { font-size: 17px; font-weight: bold; color: #2B50E0; }
  .company-sub  { font-size: 9px; color: #555; margin-top: 2px; }
  .doc-title    { font-size: 19px; font-weight: bold; color: #2B50E0; letter-spacing: 1px; }
  .doc-sub      { font-size: 10px; color: #777; margin-top: 2px; }

  .copy-tag { font-size: 8px; color: #999; text-align: center; margin-bottom: 8px; letter-spacing: 1px; text-transform: uppercase; }

  /* Meta bar */
  .meta-bar  { display: table; width: 100%; background: #f5f5f5; border: 1px solid #e0e0e0; padding: 7px 10px; margin-bottom: 10px; }
  .meta-cell { display: table-cell; width: 25%; }
  .meta-label { font-size: 8px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; }
  .meta-value { font-weight: bold; font-size: 11px; color: #333; margin-top: 2px; }

  /* Parties */
  .parties { display: table; width: 100%; margin-bottom: 12px; }
  .party { display: table-cell; width: 50%; padding: 9px; vertical-align: top; border: 1px solid #ddd; }
  .party.left { border-right: none; }
  .party-label { font-size: 8px; text-transform: uppercase; letter-spacing: 1px; color: #888; margin-bottom: 4px; }
  .party-name { font-size: 13px; font-weight: bold; color: #2B50E0; }
  .party-detail { font-size: 10px; color: #555; margin-top: 2px; line-height: 1.5; }

  /* Items */
  table.items { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
  table.items th { background: #2B50E0; color: white; padding: 7px 8px; text-align: left; font-size: 9px; text-transform: uppercase; }
  table.items td { padding: 7px 8px; border-bottom: 1px solid #eee; vertical-align: top; font-size: 10px; }
  table.items tr:nth-child(even) td { background: #fafafa; }
  .desc-main { font-weight: bold; }
  .desc-sub  { font-size: 9px; color: #666; margin-top: 2px; line-height: 1.5; }
  .text-right { text-align: right; }
  .text-center { text-align: center; }
  .totals-row td { background: #EEF1FE !important; font-weight: bold; border-top: 2px solid #2B50E0; }

  /* Notes + declaration */
  .note-box { border: 1px solid #e0e0e0; border-radius: 4px; padding: 8px 10px; margin-bottom: 12px; font-size: 10px; color: #555; }
  .declaration { font-size: 9px; color: #777; line-height: 1.6; margin-bottom: 18px; }

  /* Signatures */
  .sign-block { display: table; width: 100%; margin-top: 26px; }
  .sign-cell { display: table-cell; width: 33%; text-align: center; vertical-align: bottom; padding: 0 8px; }
  .sign-line { border-top: 1px solid #333; margin-top: 42px; padding-top: 4px; font-size: 9px; color: #555; }

  .footer { border-top: 1px solid #ddd; padding-top: 7px; margin-top: 16px; font-size: 8px; color: #aaa; text-align: center; }
  .bold { font-weight: bold; }
</style>
</head>
<body>

@php
  $company  = $dispatch->company;
  $order    = $dispatch->batch?->order;
  $customer = $order?->customer;
  $items    = $dispatch->items;
  $totalSqm = $items->sum('quantity');
@endphp

<div class="page">

  <!-- Header -->
  <div class="doc-header">
    <div class="hdr-left">
      @if($company && $company->image_data_uri)
        <img src="{{ $company->image_data_uri }}" style="max-height:46px;max-width:200px;margin-bottom:5px;" alt="logo">
      @endif
      <div class="company-name">{{ strtoupper($company->name ?? 'Company') }}</div>
      <div class="company-sub">{{ $company->address_line1 ?? '' }}{{ $company->city ? ', '.$company->city : '' }}{{ $company->state ? ', '.$company->state : '' }} {{ $company->pincode ?? '' }}</div>
      <div class="company-sub">
        @if($company->phone) Ph: {{ $company->phone }} @endif
        @if($company->email) &nbsp;|&nbsp; {{ $company->email }} @endif
      </div>
      @if($company->gstin)<div class="company-sub">GSTIN: <span class="bold">{{ $company->gstin }}</span></div>@endif
    </div>
    <div class="hdr-right">
      <div class="doc-title">DELIVERY CHALLAN</div>
      <div class="doc-sub">{{ $dispatch->dispatch_no }}</div>
      <div class="doc-sub">Status: {{ strtoupper($dispatch->status) }}</div>
    </div>
  </div>
  <div class="copy-tag">Original for Recipient / Duplicate for Transporter / Triplicate for Consignor</div>

  <!-- Meta -->
  <div class="meta-bar">
    <div class="meta-cell"><div class="meta-label">Challan No</div><div class="meta-value">{{ $dispatch->dispatch_no }}</div></div>
    <div class="meta-cell"><div class="meta-label">Dispatch Date</div><div class="meta-value">{{ $dispatch->dispatch_date?->format('d/m/Y') }}</div></div>
    <div class="meta-cell"><div class="meta-label">Batch No</div><div class="meta-value">{{ $dispatch->batch?->batch_no ?? '—' }}</div></div>
    <div class="meta-cell"><div class="meta-label">Order No</div><div class="meta-value">{{ $order?->order_no ?? '—' }}</div></div>
  </div>

  <!-- Parties -->
  <div class="parties">
    <div class="party left">
      <div class="party-label">Consignee (Ship To)</div>
      <div class="party-name">{{ $customer->name ?? '—' }}</div>
      @if($dispatch->customer_address)
        <div class="party-detail">{{ $dispatch->customer_address }}</div>
      @else
        <div class="party-detail">{{ $customer->address_line1 ?? '' }}</div>
        <div class="party-detail">{{ $customer->city ?? '' }}{{ $customer->state ? ', '.$customer->state : '' }} {{ $customer->pincode ?? '' }}</div>
      @endif
      @if($customer && $customer->gstin)<div class="party-detail">GSTIN: <span class="bold">{{ $customer->gstin }}</span></div>@endif
      @if($customer && $customer->phone)<div class="party-detail">Ph: {{ $customer->phone }}</div>@endif
    </div>
    <div class="party right">
      <div class="party-label">Dispatch Details</div>
      @if($order && $order->project_name)
        <div class="party-detail"><span class="bold">Project:</span> {{ $order->project_name }}</div>
        <div class="party-detail">{{ $order->project_location }}</div>
      @endif
      <div class="party-detail"><span class="bold">Expected Delivery:</span> {{ $dispatch->expected_delivery_date?->format('d/m/Y') ?? '—' }}</div>
      <div class="party-detail"><span class="bold">Tracking / LR No:</span> {{ $dispatch->tracking_number ?? '—' }}</div>
      @if($dispatch->actual_delivery_date)
        <div class="party-detail"><span class="bold">Delivered On:</span> {{ $dispatch->actual_delivery_date->format('d/m/Y') }}</div>
      @endif
    </div>
  </div>

  <!-- Items -->
  <table class="items">
    <thead>
      <tr>
        <th style="width:5%">Sr.</th>
        <th style="width:47%">Description of Goods</th>
        <th style="width:14%">HSN</th>
        <th style="width:17%" class="text-right">Qty (SQM)</th>
        <th style="width:17%">UoM</th>
      </tr>
    </thead>
    <tbody>
      @forelse($items as $i => $item)
        @php $spec = $item->spec ?? null; @endphp
        <tr>
          <td class="text-center">{{ $i + 1 }}</td>
          <td>
            <div class="desc-main">{{ strtoupper($item->panelType->name ?? 'PUF Panel') }}</div>
            @if($spec)
              <div class="desc-sub">
                {{ $spec->thickness }}mm {{ $spec->density_type }} ({{ $spec->density_kgm3 }} kg/m³)
                &nbsp;|&nbsp; Top: {{ $spec->top_skin_thickness }}mm {{ $spec->top_skin_material }} {{ $spec->top_color }} ({{ $spec->top_surface }})
                &nbsp;|&nbsp; Bottom: {{ $spec->bottom_skin_thickness }}mm {{ $spec->bottom_skin_material }} {{ $spec->bottom_color }}
                @if($spec->guard_film) &nbsp;|&nbsp; Guard Film @endif
                @if($spec->cello_tap) &nbsp;|&nbsp; Cello Tap @endif
              </div>
            @endif
          </td>
          <td>{{ $spec->hsn_code ?? '39259010' }}</td>
          <td class="text-right bold">{{ number_format($item->quantity, 2) }}</td>
          <td>SQM</td>
        </tr>
      @empty
        <tr><td colspan="5" class="text-center" style="padding:18px;color:#999">No items recorded on this dispatch.</td></tr>
      @endforelse

      @if($items->count() > 0)
      <tr class="totals-row">
        <td colspan="3" class="text-right">TOTAL</td>
        <td class="text-right">{{ number_format($totalSqm, 2) }}</td>
        <td>SQM</td>
      </tr>
      @endif
    </tbody>
  </table>

  @if($dispatch->notes)
  <div class="note-box"><span class="bold">Notes:</span> {{ $dispatch->notes }}</div>
  @endif

  <div class="declaration">
    <span class="bold">Declaration:</span> This is a delivery challan and not a tax invoice. Goods are dispatched against the above order.
    Certified that the particulars given above are true and correct. Goods once dispatched will not be taken back without prior approval.
    Please check the goods on receipt; claims for shortage/damage must be reported within 24 hours of delivery.
  </div>

  <!-- Signatures -->
  <div class="sign-block">
    <div class="sign-cell"><div class="sign-line">Prepared By</div></div>
    <div class="sign-cell"><div class="sign-line">Driver / Transporter Signature</div></div>
    <div class="sign-cell"><div class="sign-line">For {{ $company->name ?? 'Company' }}<br>Authorised Signatory</div></div>
  </div>

  <div class="footer">
    Generated on {{ now()->format('d M Y H:i') }} &nbsp;|&nbsp; {{ config('app.name', 'PanelOS') }} &nbsp;|&nbsp; Receiver's Signature &amp; Stamp: ____________________________
  </div>
</div>

</body>
</html>
