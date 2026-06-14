{{-- Shared BOQ cutting-list body (WORKER COPY — no rates/amounts).
     Expects $quotation, $items, $customer, $company in scope. --}}
<div class="boq-header">
  <div class="boq-header-cell">
    <span style="font-size:16px;font-weight:bold">BOQ SHEET — CUTTING LIST</span>
  </div>
  <div class="boq-header-cell" style="text-align:right;font-size:11px">
    {{ $quotation->quotation_no }} &nbsp;|&nbsp; DATE: {{ $quotation->quoted_on?->format('d/m/Y') }}
  </div>
</div>

<div class="boq-spec-bar">
  <strong>CUSTOMER:</strong> {{ $customer->name ?? '—' }} &nbsp;&nbsp;|&nbsp;&nbsp;
  <strong>PROJECT:</strong> {{ $quotation->project_name ?: '—' }} &nbsp;&nbsp;|&nbsp;&nbsp;
  <strong>LOCATION:</strong> {{ $quotation->project_location ?: '—' }}
  &nbsp;&nbsp;|&nbsp;&nbsp; <strong>GUARD FILM:</strong> {{ $items->contains(fn($it) => $it->guard_film) ? 'YES' : 'NO' }}
</div>

@foreach($items as $pi => $item)
<div style="margin-bottom:16px">
  <div class="boq-spec-bar">
    <strong>Panel {{ $pi + 1 }}:</strong>
    &nbsp; {{ $item->panelType->name ?? '—' }}
    &nbsp; | {{ $item->thickness }}mm {{ $item->density_type }} {{ $item->density_kgm3 }}kg/m³
    &nbsp; | TOP: {{ $item->top_skin_thickness }}mm {{ $item->top_skin_material }} {{ $item->top_color }} ({{ $item->top_surface }})
    &nbsp; | BTM: {{ $item->bottom_skin_thickness }}mm {{ $item->bottom_skin_material }} {{ $item->bottom_color }} (PLAIN)
    @if($item->guard_film) &nbsp; | GUARD FILM: YES @endif
    @if($item->cello_tap) &nbsp; | CELLO TAP: YES @endif
  </div>

  <table>
    <thead>
      <tr style="background:#EEF1FE">
        <th style="width:8%;text-align:center">Sr.</th>
        <th style="width:31%;text-align:center">LENGTH (mm)</th>
        <th style="width:30%;text-align:center">WIDTH (mm)</th>
        <th style="width:15%;text-align:center">NOS</th>
        <th style="width:16%;text-align:center">SQM</th>
      </tr>
    </thead>
    <tbody>
      @foreach($item->sizes as $si => $sz)
      <tr>
        <td class="text-center">{{ $si + 1 }}</td>
        <td class="text-center bold">{{ $sz->length_mm }}
          @if($sz->length_mm < 2000)<span style="color:#B5740A;font-size:8px"> &#9888;DL</span>@endif
        </td>
        <td class="text-center">{{ $sz->width_mm }}</td>
        <td class="text-center bold">{{ $sz->nos }}</td>
        <td class="text-center bold">{{ number_format($sz->sqm, 2) }}</td>
      </tr>
      @endforeach
      <tr style="background:#EEF1FE;font-weight:bold">
        <td colspan="2" class="text-right">TOTAL</td>
        <td class="text-center">{{ $item->sizes->sum('nos') }}</td>
        <td class="text-center" colspan="2">{{ number_format($item->total_sqm, 2) }} SQM</td>
      </tr>
    </tbody>
  </table>
</div>
@endforeach

{{-- Accessories — quantities only, NO rates --}}
@if($quotation->accessories->count() > 0)
<div class="boq-spec-bar"><strong>ACCESSORIES</strong></div>
<table>
  <thead>
    <tr style="background:#EEF1FE">
      <th>Accessory</th><th>Specification</th><th class="text-center">Qty</th><th class="text-center">Unit</th>
    </tr>
  </thead>
  <tbody>
    @foreach($quotation->accessories as $acc)
    <tr>
      <td class="bold">{{ $acc->name }}</td>
      <td>
        @if(($acc->pivot->type ?? '') === 'door' && $acc->pivot->door_width)
          {{ ucwords(str_replace('_',' ', $acc->pivot->door_type ?? 'door')) }} · {{ $acc->pivot->door_width }}×{{ $acc->pivot->door_height }}mm
        @endif
        {{ $acc->pivot->description ?? $acc->description ?? '' }}
      </td>
      <td class="text-center">{{ $acc->pivot->quantity }}</td>
      <td class="text-center">{{ $acc->pivot->unit ?? $acc->unit ?? 'NOS' }}</td>
    </tr>
    @endforeach
  </tbody>
</table>
@endif

{{-- Grand total SQM only --}}
<table style="margin-top:12px">
  <tbody>
    <tr style="background:#2B50E0;color:white;font-weight:bold">
      <td class="text-right" style="padding:8px">GRAND TOTAL — TOTAL SQM:</td>
      <td class="text-right bold" style="padding:8px;width:160px">{{ number_format($quotation->total_sqm, 2) }} SQM</td>
    </tr>
  </tbody>
</table>

<div class="dispatch-block" style="margin-top:20px">
  <div style="font-weight:bold;margin-bottom:8px;color:#2B50E0">PRODUCTION / DISPATCH (to be filled at production)</div>
  <div class="dispatch-row">
    <div class="dispatch-field">PRODUCED BY: ________________________</div>
    <div class="dispatch-field">CHECKED BY: ________________________</div>
    <div class="dispatch-field">DATE: ________________________</div>
  </div>
  <div class="dispatch-row" style="margin-top:8px">
    <div class="dispatch-field">TRANSPORTER: ________________________</div>
    <div class="dispatch-field">VEHICLE NO: ________________________</div>
    <div class="dispatch-field">DISPATCH DATE: ________________________</div>
  </div>
</div>

<div style="font-size:8px;color:#aaa;margin-top:10px;text-align:center">
  &#9888; DL = Panel produced at doubled length and cut to size &nbsp;|&nbsp;
  Worker copy — for production use only &nbsp;|&nbsp; {{ config('app.name', 'PanelOS') }} {{ now()->format('d M Y H:i') }}
</div>
