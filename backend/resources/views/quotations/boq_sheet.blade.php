<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>BOQ Cutting Sheet {{ $quotation->quotation_no }}</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11px; color: #222; }
.page { padding: 20px 24px; }

/* Company strip */
.co-strip { display: table; width: 100%; border-bottom: 3px solid #2B50E0; padding-bottom: 8px; margin-bottom: 10px; }
.co-left { display: table-cell; vertical-align: middle; }
.co-right { display: table-cell; text-align: right; vertical-align: middle; font-size: 10px; color: #667085; }
.co-logo { max-height: 42px; max-width: 180px; margin-bottom: 3px; }
.co-name { font-size: 15px; font-weight: bold; color: #2B50E0; }

/* BOQ blocks */
.boq-header { background: #2B50E0; color: white; padding: 8px 12px; margin-bottom: 10px; display: table; width: 100%; }
.boq-header-cell { display: table-cell; vertical-align: middle; color: white; }
.boq-spec-bar { background: #EEF1FE; padding: 6px 10px; margin-bottom: 8px; border: 1px solid #D6DEFB; font-size: 10px; }

table { width: 100%; border-collapse: collapse; margin-bottom: 8px; font-size: 10px; }
th { background: #EEF1FE; color: #15181E; padding: 5px 8px; border: 1px solid #D6DEFB; font-size: 9px; text-transform: uppercase; }
td { padding: 5px 8px; border: 1px solid #E4E7EC; }
.text-center { text-align: center; }
.text-right { text-align: right; }
.bold { font-weight: bold; }

.dispatch-block { border: 1px solid #ccc; padding: 10px; margin-top: 15px; font-size: 10px; border-radius: 4px; }
.dispatch-row { display: table; width: 100%; }
.dispatch-field { display: table-cell; width: 33%; padding: 4px; }
</style>
</head>
<body>
@php $customer = $quotation->customer; $items = $quotation->items; @endphp
<div class="page">

  <!-- Compact company strip -->
  <div class="co-strip">
    <div class="co-left">
      @if($company && $company->image_data_uri)<img src="{{ $company->image_data_uri }}" class="co-logo" alt="logo"><br>@endif
      <span class="co-name">{{ strtoupper($company->name ?? 'Company') }}</span>
    </div>
    <div class="co-right">
      {{ $company->city ?? '' }}{{ $company->state ? ', '.$company->state : '' }}<br>
      Production Cutting Sheet
    </div>
  </div>

  @include('quotations._boq_sheet_body')
</div>
</body>
</html>
