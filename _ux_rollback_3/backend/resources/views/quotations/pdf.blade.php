<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $quotation->quotation_no }}</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #222; }
.page { padding: 18px 22px; page-break-after: always; }
.page:last-child { page-break-after: auto; }

/* Header */
.doc-header { display: table; width: 100%; border-bottom: 3px solid #2B50E0; padding-bottom: 10px; margin-bottom: 12px; }
.logo-cell { display: table-cell; width: 55%; vertical-align: top; }
.company-logo { max-height: 52px; max-width: 220px; margin-bottom: 6px; }
.prod-img { max-width: 64px; max-height: 48px; object-fit: contain; border: 1px solid #E4E7EC; border-radius: 3px; }
.no-img { color: #c9cdd6; font-size: 12px; }
.title-cell { display: table-cell; width: 45%; vertical-align: top; text-align: right; }
.company-name { font-size: 16px; font-weight: bold; color: #2B50E0; }
.company-sub { font-size: 9px; color: #555; margin-top: 2px; }
.doc-title { font-size: 20px; font-weight: bold; color: #2B50E0; letter-spacing: 2px; }
.doc-subtitle { font-size: 10px; color: #777; margin-top: 2px; }

/* Meta bar */
.meta-bar { display: table; width: 100%; background: #f5f5f5; padding: 8px 10px; margin-bottom: 10px; border: 1px solid #e0e0e0; }
.meta-cell { display: table-cell; width: 25%; }
.meta-label { font-size: 8px; color: #888; text-transform: uppercase; letter-spacing: 0.5px; }
.meta-value { font-weight: bold; font-size: 11px; color: #333; margin-top: 2px; }

/* Buyer/Seller boxes */
.parties { display: table; width: 100%; margin-bottom: 10px; }
.party-cell { display: table-cell; width: 50%; padding: 8px; vertical-align: top; }
.party-cell.left { border: 1px solid #ddd; border-radius: 3px; }
.party-cell.right { padding-left: 20px; }
.party-label { font-size: 8px; text-transform: uppercase; letter-spacing: 1px; color: #888; margin-bottom: 4px; }
.party-name { font-size: 13px; font-weight: bold; color: #2B50E0; }
.party-detail { font-size: 10px; color: #555; margin-top: 2px; }

/* Items table */
table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 10px; }
.items-table th { background: #2B50E0; color: white; padding: 7px 8px; text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: 0.3px; }
.items-table td { padding: 8px 8px; vertical-align: top; }
.items-table tr.item-row td { border-top: 1px solid #E4E7EC; }
.items-table tr.item-row:first-child td { border-top: none; }
.desc-text { font-weight: bold; color: #15181E; line-height: 1.35; }
.desc-detail { font-size: 8.5px; color: #667085; margin-top: 3px; line-height: 1.5; }

/* Size sub-table — full width under each item */
.size-row td { padding-top: 0 !important; padding-bottom: 10px; }
.size-table { width: 100%; border-collapse: collapse; margin: 2px 0 0; background: #F8F9FC; border: 1px solid #E4E7EC; border-radius: 4px; }
.size-table th { background: #EEF1FE; color: #2B50E0; padding: 4px 8px; font-size: 8px; text-align: left; border-bottom: 1px solid #D6DEFB; letter-spacing: 0.3px; }
.size-table td { padding: 4px 8px; font-size: 9px; text-align: left; border-bottom: 1px solid #EDEFF4; }
.size-table tr:last-child td { border-bottom: none; }
.size-table .size-total td { background: #EEF1FE; }
.size-table .text-right { text-align: right; }
.dl { display: inline-block; background: #FBF0DA; color: #B5740A; font-size: 7px; font-weight: bold; padding: 0 3px; border-radius: 3px; margin-left: 3px; }

/* Totals */
.totals-wrap { width: 55%; margin-left: auto; margin-bottom: 10px; border: 1px solid #ddd; }
.total-row { display: table; width: 100%; }
.total-label { display: table-cell; padding: 6px 10px; width: 65%; }
.total-value { display: table-cell; padding: 6px 10px; text-align: right; font-weight: bold; border-left: 1px solid #eee; }
.total-row.grand { background: #2B50E0; color: white; font-size: 13px; font-weight: bold; }
.total-row.grand .total-value { border-left: 1px solid #2140C0; }
.total-row.sub-line { background: #f5f5f5; border-bottom: 1px solid #eee; }
.total-row.section-line { border-top: 2px solid #2B50E0; border-bottom: 2px solid #2B50E0; }

/* Footer */
.page-footer { border-top: 1px solid #ddd; padding-top: 8px; margin-top: 10px; font-size: 9px; color: #888; }
.sign-block { display: table; width: 100%; margin-top: 20px; }
.sign-cell { display: table-cell; width: 50%; }
.sign-line { border-top: 1px solid #333; margin-top: 40px; padding-top: 4px; font-size: 10px; font-weight: bold; }

/* Page 2 - T&C */
.tc-section { margin-bottom: 14px; }
.tc-title { font-weight: bold; font-size: 11px; color: #2B50E0; border-bottom: 1px solid #e0e0e0; padding-bottom: 3px; margin-bottom: 6px; }
.tc-item { padding: 3px 0; font-size: 10px; color: #444; }

/* Page 3 - BOQ Sheet */
.boq-header { background: #2B50E0; color: white; padding: 8px 12px; margin-bottom: 10px; display: table; width: 100%; }
.boq-header-cell { display: table-cell; vertical-align: middle; }
.boq-spec-bar { background: #EEF1FE; padding: 6px 10px; margin-bottom: 10px; border: 1px solid #D6DEFB; font-size: 10px; }
.dispatch-block { border: 1px solid #ccc; padding: 10px; margin-top: 15px; font-size: 10px; }
.dispatch-field { display: table-cell; width: 33%; padding: 4px; border-bottom: 1px solid #ccc; }
.dispatch-row { display: table; width: 100%; }

/* Utility */
.text-right { text-align: right; }
.text-center { text-align: center; }
.bold { font-weight: bold; }
.blue { color: #2B50E0; }
.mt-4 { margin-top: 4px; }
.mt-8 { margin-top: 8px; }
</style>
</head>
<body>

@php
  $company  = $quotation->company;
  $customer = $quotation->customer;
  $items    = $quotation->items;
  $isInter  = $quotation->is_inter_state;
@endphp

<!-- ═══════════════════════════════════ PAGE 1 — PROFORMA INVOICE ════ -->
<div class="page">

  <!-- Header -->
  <div class="doc-header">
    <div class="logo-cell">
      @if($company->image_data_uri)
        <img src="{{ $company->image_data_uri }}" class="company-logo" alt="logo">
      @endif
      <div class="company-name">{{ strtoupper($company->name) }}</div>
      <div class="company-sub">{{ $company->address_line1 ?? $company->address ?? '' }}{{ $company->city ? ', '.$company->city : '' }}{{ $company->state ? ', '.$company->state : '' }} {{ $company->pincode ?? '' }}</div>
      <div class="company-sub">{{ $company->phone ?? '' }}  |  {{ $company->email ?? '' }}</div>
      @if($company->gstin)
        <div class="company-sub">GSTIN: <strong>{{ $company->gstin }}</strong> &nbsp; AN ISO 9001:2015 COMPANY</div>
      @endif
    </div>
    <div class="title-cell">
      <div class="doc-title">PROFORMA INVOICE</div>
      <div class="doc-subtitle">{{ $quotation->quotation_no }}</div>
      @if($quotation->revision_number > 1)
        <div class="doc-subtitle" style="color:orange">REVISION {{ $quotation->revision_number }}</div>
      @endif
    </div>
  </div>

  <!-- Meta bar -->
  <div class="meta-bar">
    <div class="meta-cell"><div class="meta-label">PFI No.</div><div class="meta-value">{{ $quotation->quotation_no }}</div></div>
    <div class="meta-cell"><div class="meta-label">Date</div><div class="meta-value">{{ $quotation->quoted_on?->format('d/m/Y') }}</div></div>
    <div class="meta-cell"><div class="meta-label">Valid Until</div><div class="meta-value">{{ $quotation->valid_until?->format('d/m/Y') }}</div></div>
    <div class="meta-cell"><div class="meta-label">Status</div><div class="meta-value">{{ strtoupper($quotation->status) }}</div></div>
  </div>

  <!-- Parties -->
  <div class="parties">
    <div class="party-cell left">
      <div class="party-label">Buyer's Details</div>
      <div class="party-name">{{ $customer->name }}</div>
      <div class="party-detail">{{ $customer->address_line1 }}</div>
      <div class="party-detail">{{ $customer->city }}, {{ $customer->state }} {{ $customer->pincode }}</div>
      @if($customer->gstin)<div class="party-detail">GSTIN: <strong>{{ $customer->gstin }}</strong></div>@endif
      <div class="party-detail">📞 {{ $customer->phone }}  |  ✉ {{ $customer->email }}</div>
    </div>
    <div class="party-cell right">
      @if($quotation->project_name)
        <div class="party-label">Project</div>
        <div class="party-name" style="font-size:12px">{{ $quotation->project_name }}</div>
        <div class="party-detail">{{ $quotation->project_location }}</div>
      @endif
      @if($quotation->quality_grade)
        <div class="party-detail mt-8">Quality Grade: <strong>{{ $quotation->quality_grade }}</strong></div>
      @endif
      <div class="party-detail">GST Type: <strong>{{ $isInter ? 'Inter-state (IGST)' : 'Intra-state (CGST+SGST)' }}</strong></div>
    </div>
  </div>

  <!-- Items -->
  <table class="items-table">
    <thead>
      <tr>
        <th style="width:4%">Sr.</th>
        <th style="width:30%">Description</th>
        <th style="width:11%" class="text-center">Image</th>
        <th style="width:10%" class="text-right">Qty (SQM)</th>
        <th style="width:7%">UoM</th>
        <th style="width:12%" class="text-right">Rate</th>
        <th style="width:14%" class="text-right">Amount</th>
        <th style="width:12%">HSN Code</th>
      </tr>
    </thead>
    <tbody>
      @foreach($items as $i => $item)
        @php $pt = $item->panelType; @endphp
        <tr class="item-row">
          <td>{{ $i + 1 }}</td>
          <td>
            <div class="desc-text">SUPPLY OF {{ strtoupper($company->name) }} PUF PANEL
              @if($item->top_surface === 'RIBBED') ROOF @else FLAT @endif
              {{ $item->thickness }}MM THICK.</div>
            <div class="desc-detail">
              Density: {{ $item->density_type }} {{ $item->density_kgm3 }}(±2) kg/m³
              &nbsp;&bull;&nbsp; Top: {{ $item->top_skin_thickness }}mm {{ $item->top_skin_material }} {{ strtoupper($item->top_color) }} ({{ $item->top_surface }})
              &nbsp;&bull;&nbsp; Bottom: {{ $item->bottom_skin_thickness }}mm {{ $item->bottom_skin_material }} {{ strtoupper($item->bottom_color) }} (PLAIN)
              @if($item->guard_film) &nbsp;&bull;&nbsp; Guard Film @endif
              @if($item->cello_tap) &nbsp;&bull;&nbsp; Cello Tap @endif
            </div>
          </td>
          <td class="text-center">
            @if($pt && $pt->image_data_uri)<img src="{{ $pt->image_data_uri }}" class="prod-img" alt="">@else<span class="no-img">—</span>@endif
          </td>
          <td class="text-right bold">{{ number_format($item->total_sqm, 2) }}</td>
          <td>SQM</td>
          <td class="text-right">₹ {{ number_format($item->rate_per_sqm, 2) }}</td>
          <td class="text-right bold">₹ {{ number_format($item->amount, 2) }}</td>
          <td>{{ $item->hsn_code }}</td>
        </tr>
        @if($item->sizes->count() > 0)
        <tr class="size-row">
          <td></td>
          <td colspan="7" style="padding-top:0">
            <table class="size-table">
              <thead>
                <tr>
                  <th style="width:18%">LENGTH (mm)</th>
                  <th style="width:14%">WIDTH (mm)</th>
                  <th style="width:10%">NOS</th>
                  <th style="width:16%" class="text-right">SQM</th>
                  <th style="width:20%" class="text-right">RATE (₹/SQM)</th>
                  <th style="width:22%" class="text-right">AMOUNT (₹)</th>
                </tr>
              </thead>
              <tbody>
                @foreach($item->sizes as $sz)
                <tr>
                  <td>{{ $sz->length_mm }} @if($sz->length_mm < 2000)<span class="dl">DL</span>@endif</td>
                  <td>{{ $sz->width_mm }}</td>
                  <td>{{ $sz->nos }}</td>
                  <td class="text-right">{{ number_format($sz->sqm, 2) }}</td>
                  <td class="text-right">{{ number_format($sz->rate_per_sqm, 2) }}</td>
                  <td class="text-right">{{ number_format($sz->amount, 2) }}</td>
                </tr>
                @endforeach
                <tr class="size-total">
                  <td colspan="2" class="bold">TOTAL</td>
                  <td class="bold">{{ $item->sizes->sum('nos') }}</td>
                  <td class="text-right bold">{{ number_format($item->total_sqm, 2) }}</td>
                  <td></td>
                  <td class="text-right bold">₹ {{ number_format($item->amount, 2) }}</td>
                </tr>
              </tbody>
            </table>
          </td>
        </tr>
        @endif
      @endforeach

      <!-- Accessories -->
      @foreach($quotation->accessories as $ai => $acc)
        @php $isDoor = ($acc->pivot->type ?? '') === 'door'; @endphp
        <tr class="item-row">
          <td>{{ $items->count() + $ai + 1 }}</td>
          <td>
            <div class="desc-text">{{ strtoupper($acc->name) }}</div>
            @if($isDoor)
              <div class="desc-detail">
                @if($acc->pivot->door_type) Type: {{ ucwords(str_replace('_',' ', $acc->pivot->door_type)) }} @endif
                @if($acc->pivot->door_width && $acc->pivot->door_height) &nbsp;&bull;&nbsp; Size: {{ $acc->pivot->door_width }} × {{ $acc->pivot->door_height }} mm @endif
              </div>
            @endif
            @if($acc->pivot->description ?? $acc->description)<div class="desc-detail">{{ $acc->pivot->description ?? $acc->description }}</div>@endif
          </td>
          <td class="text-center">
            @if($acc->image_data_uri)<img src="{{ $acc->image_data_uri }}" class="prod-img" alt="">@else<span class="no-img">—</span>@endif
          </td>
          <td class="text-right">{{ number_format($acc->pivot->quantity, 2) }}</td>
          <td>{{ $acc->pivot->unit ?? $acc->unit ?? 'NOS' }}</td>
          <td class="text-right">₹ {{ number_format($acc->pivot->unit_price, 2) }}</td>
          <td class="text-right bold">₹ {{ number_format($acc->pivot->amount, 2) }}</td>
          <td>{{ $acc->hsn_code ?? '73089090' }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <!-- Totals -->
  <div class="totals-wrap">
    <div class="total-row sub-line">
      <div class="total-label">Panel Subtotal:</div>
      <div class="total-value">₹ {{ number_format($quotation->panel_subtotal, 2) }}</div>
    </div>
    @if($quotation->accessory_subtotal > 0)
    <div class="total-row sub-line">
      <div class="total-label">Accessories:</div>
      <div class="total-value">₹ {{ number_format($quotation->accessory_subtotal, 2) }}</div>
    </div>
    @endif
    @if($quotation->installation_amount > 0)
    <div class="total-row sub-line">
      <div class="total-label">Installation:</div>
      <div class="total-value">₹ {{ number_format($quotation->installation_amount, 2) }}</div>
    </div>
    @endif
    <div class="total-row sub-line">
      <div class="total-label">Subtotal:</div>
      <div class="total-value">₹ {{ number_format($quotation->subtotal, 2) }}</div>
    </div>
    @if($quotation->discount_pct > 0)
    <div class="total-row sub-line">
      <div class="total-label">Discount ({{ $quotation->discount_pct }}%):</div>
      <div class="total-value" style="color:#D6322A">- ₹ {{ number_format($quotation->discount_amount, 2) }}</div>
    </div>
    @endif
    <div class="total-row section-line">
      <div class="total-label">Taxable Amount:</div>
      <div class="total-value">₹ {{ number_format($quotation->taxable_amount, 2) }}</div>
    </div>
    @if($isInter)
    <div class="total-row sub-line">
      <div class="total-label">IGST @ 18%:</div>
      <div class="total-value">₹ {{ number_format($quotation->igst_amount, 2) }}</div>
    </div>
    @else
    <div class="total-row sub-line">
      <div class="total-label">CGST @ 9%:</div>
      <div class="total-value">₹ {{ number_format($quotation->cgst_amount, 2) }}</div>
    </div>
    <div class="total-row sub-line">
      <div class="total-label">SGST @ 9%:</div>
      <div class="total-value">₹ {{ number_format($quotation->sgst_amount, 2) }}</div>
    </div>
    @endif
    @if($quotation->transport_fixed && $quotation->transport_amount > 0)
    <div class="total-row sub-line">
      <div class="total-label">Transportation:</div>
      <div class="total-value">₹ {{ number_format($quotation->transport_amount, 2) }}</div>
    </div>
    @else
    <div class="total-row sub-line">
      <div class="total-label">Transportation:</div>
      <div class="total-value">EXTRA AS ACTUAL</div>
    </div>
    @endif
    @if($quotation->round_off != 0)
    <div class="total-row sub-line">
      <div class="total-label">Round Off:</div>
      <div class="total-value">₹ {{ number_format($quotation->round_off, 2) }}</div>
    </div>
    @endif
    <div class="total-row grand">
      <div class="total-label">GRAND TOTAL:</div>
      <div class="total-value">₹ {{ number_format($quotation->total_amount, 2) }}</div>
    </div>
    <div class="total-row sub-line">
      <div class="total-label">Advance ({{ $quotation->advance_pct }}%):</div>
      <div class="total-value">₹ {{ number_format($quotation->advance_amount, 2) }}</div>
    </div>
    <div class="total-row">
      <div class="total-label bold blue">Balance Due:</div>
      <div class="total-value bold blue">₹ {{ number_format($quotation->balance_amount, 2) }}</div>
    </div>
  </div>

  <div style="font-size:10px;color:#888;margin-top:6px">
    Total SQM: <strong>{{ number_format($quotation->total_sqm, 2) }}</strong> &nbsp;&nbsp;
    This is a Proforma Invoice and not a valid tax document. &nbsp;&nbsp;
    PTO →
  </div>

  <!-- Signatory -->
  <div class="sign-block">
    <div class="sign-cell"></div>
    <div class="sign-cell" style="text-align:right">
      <div class="sign-line">For {{ $company->name }}<br><span style="font-size:9px;color:#888">Authorised Signatory</span></div>
    </div>
  </div>

  <div class="page-footer">
    Generated on {{ now()->format('d M Y H:i') }} &nbsp;|&nbsp; {{ config('app.name', 'PanelOS') }}
  </div>
</div>


<!-- ═══════════════════════════════════ PAGE 2 — TERMS & CONDITIONS ══ -->
<div class="page">
  <div class="doc-header">
    <div class="logo-cell"><div class="company-name">{{ strtoupper($company->name) }}</div></div>
    <div class="title-cell"><div class="doc-title">TERMS &amp; CONDITIONS</div><div class="doc-subtitle">{{ $quotation->quotation_no }}</div></div>
  </div>

  <div class="tc-section">
    <div class="tc-title">1. Bank Details</div>
    <div class="tc-item">Bank Name: {{ $company->bank_name ?? 'As provided separately' }}</div>
    <div class="tc-item">Account No: {{ $company->bank_account ?? '—' }}</div>
    <div class="tc-item">IFSC Code: {{ $company->ifsc_code ?? '—' }}</div>
  </div>

  <div class="tc-section">
    <div class="tc-title">2. Scope of Work</div>
    <div class="tc-item">Supply of PUF/PIR insulated panels as per specifications above. Erection/installation is excluded unless specifically mentioned.</div>
  </div>

  <div class="tc-section">
    <div class="tc-title">3. Price Basis</div>
    <div class="tc-item">All prices are Ex-Works from our factory, Survey No 158/1, Dhanora, Karjan, Vadodara.</div>
    <div class="tc-item">GST, transportation, loading, unloading and other charges extra as applicable.</div>
  </div>

  <div class="tc-section">
    <div class="tc-title">4. Payment Terms</div>
    <div class="tc-item">Advance: {{ $quotation->advance_pct }}% at order confirmation — ₹ {{ number_format($quotation->advance_amount, 2) }}</div>
    <div class="tc-item">Balance: {{ 100 - $quotation->advance_pct }}% minimum 2 working days before dispatch.</div>
    <div class="tc-item">All payments by NEFT/RTGS/Cheque in favour of {{ $company->name }}.</div>
  </div>

  <div class="tc-section">
    <div class="tc-title">5. Delivery Terms</div>
    <div class="tc-item">Standard panels: 14 working days from receipt of advance and confirmed order.</div>
    <div class="tc-item">Non-standard/large orders: 21 working days.</div>
    <div class="tc-item">Delivery subject to availability of raw materials. Any delays in raw material supply will be communicated.</div>
  </div>

  <div class="tc-section">
    <div class="tc-title">6. Offer Validity</div>
    <div class="tc-item">This quotation is valid for <strong>{{ $quotation->validity_days }} days</strong> from the date of issue ({{ $quotation->quoted_on?->format('d/m/Y') }}).</div>
    <div class="tc-item">Prices may change due to fluctuations in raw material costs after validity period.</div>
  </div>

  <div class="tc-section">
    <div class="tc-title">7. Wastage & Tolerances</div>
    <div class="tc-item">Actual dispatched quantity may vary ±5% from ordered quantity due to production process.</div>
    <div class="tc-item">Billing will be done on actual dispatched quantity.</div>
    <div class="tc-item">Panels shorter than 2000mm will be produced at double length and cut to order.</div>
  </div>

  <div class="tc-section">
    <div class="tc-title">8. Exclusions</div>
    <div class="tc-item">1. Erection / installation charges</div>
    <div class="tc-item">2. Civil work, foundations, structural steel</div>
    <div class="tc-item">3. Electrical, plumbing, HVAC works</div>
    <div class="tc-item">4. Transportation (unless specifically included)</div>
    <div class="tc-item">5. E-way bill charges</div>
    <div class="tc-item">6. Unloading at site</div>
    <div class="tc-item">7. Any work not specifically mentioned in scope above</div>
  </div>

  @if($quotation->notes)
  <div class="tc-section">
    <div class="tc-title">9. Special Notes</div>
    <div class="tc-item">{{ $quotation->notes }}</div>
  </div>
  @endif

  <div class="sign-block">
    <div class="sign-cell">
      <div class="sign-line">Customer Acceptance<br><span style="font-size:9px;color:#888">Stamp & Signature</span></div>
    </div>
    <div class="sign-cell" style="text-align:right">
      <div class="sign-line">For {{ $company->name }}<br><span style="font-size:9px;color:#888">Authorised Signatory</span></div>
    </div>
  </div>
</div>


<!-- PAGE 3 - BOQ CUTTING SHEET (worker copy, no rates) -->
<div class="page">
@include('quotations._boq_sheet_body')
</div>

</body>
</html>
