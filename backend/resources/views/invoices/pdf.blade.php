<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #333;
            line-height: 1.6;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            padding: 40px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 40px;
            border-bottom: 3px solid #2B50E0;
            padding-bottom: 20px;
        }
        .company-info h1 {
            color: #2B50E0;
            font-size: 28px;
            margin-bottom: 5px;
        }
        .company-info p {
            font-size: 12px;
            color: #666;
        }
        .invoice-info {
            text-align: right;
        }
        .invoice-info div {
            margin-bottom: 5px;
            font-size: 12px;
        }
        .invoice-no {
            font-size: 18px;
            font-weight: bold;
            color: #2B50E0;
        }
        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
            margin-top: 5px;
            text-transform: uppercase;
        }
        .status-draft { background: #e0e0e0; color: #333; }
        .status-sent { background: #EEF1FE; color: #2B50E0; }
        .status-accepted { background: #EFEAFB; color: #6B3FC9; }
        .status-paid { background: #E4F5EC; color: #14894E; }
        .status-cancelled { background: #ffebee; color: #D6322A; }

        .bill-to {
            margin-bottom: 30px;
        }
        .bill-to h3 {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 8px;
        }
        .bill-to p {
            font-size: 13px;
            margin-bottom: 3px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        th {
            background: #f5f5f5;
            padding: 12px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            border-bottom: 2px solid #ddd;
        }
        td {
            padding: 12px;
            border-bottom: 1px solid #e0e0e0;
            font-size: 12px;
        }
        tr:last-child td {
            border-bottom: none;
        }
        .text-right {
            text-align: right;
        }
        .text-center {
            text-align: center;
        }

        .totals {
            width: 100%;
            margin-bottom: 30px;
        }
        .totals-row {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 8px;
            font-size: 12px;
        }
        .totals-label {
            width: 150px;
            text-align: right;
            padding-right: 20px;
        }
        .totals-value {
            width: 100px;
            text-align: right;
        }
        .subtotal-row {
            border-bottom: 1px solid #ddd;
            padding-bottom: 8px;
        }
        .total-row {
            font-weight: bold;
            font-size: 14px;
            color: #2B50E0;
            padding-top: 8px;
        }

        .notes {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            font-size: 11px;
        }
        .notes h4 {
            font-size: 12px;
            margin-bottom: 5px;
        }

        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            font-size: 10px;
            color: #999;
            text-align: center;
        }

        .payment-info {
            background: #EEF1FE;
            padding: 15px;
            border-radius: 4px;
            margin-bottom: 20px;
            font-size: 12px;
        }
        .payment-info h4 {
            color: #2B50E0;
            margin-bottom: 5px;
        }

        .page-break {
            page-break-after: always;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="company-info">
                @if($invoice->company && $invoice->company->image_data_uri)
                    <img src="{{ $invoice->company->image_data_uri }}" style="max-height:48px;max-width:200px;margin-bottom:6px;" alt="logo">
                @endif
                <h1>{{ $invoice->company->name ?? 'INVOICE' }}</h1>
                <p>
                    {{ $invoice->company->address_line1 ?? 'PUF/PIR Panel Manufacturing' }}@if($invoice->company && $invoice->company->city), {{ $invoice->company->city }}@endif<br>
                    @if($invoice->company && $invoice->company->gstin)GSTIN: {{ $invoice->company->gstin }}@endif
                </p>
            </div>
            <div class="invoice-info">
                <div class="invoice-no">{{ $invoice->invoice_no }}</div>
                <div><strong>Date:</strong> {{ $invoice->invoice_date->format('M d, Y') }}</div>
                <div><strong>Due:</strong> {{ $invoice->due_date->format('M d, Y') }}</div>
                <span class="status-badge status-{{ $invoice->status }}">{{ $invoice->status }}</span>
            </div>
        </div>

        <!-- Bill To -->
        @php
            $billCustomer = optional(optional(optional($invoice->dispatch)->batch)->order)->customer
                ?? optional($invoice->order)->customer;
        @endphp
        <div class="bill-to">
            <h3>Bill To:</h3>
            @if($billCustomer)
                <p><strong>{{ $billCustomer->name }}</strong></p>
                <p>{{ $billCustomer->address_line1 ?? '' }}</p>
                <p>{{ $billCustomer->city ?? '' }}@if($billCustomer->state), {{ $billCustomer->state }}@endif {{ $billCustomer->pincode ?? '' }}</p>
                <p>{{ $billCustomer->country ?? 'India' }}</p>
                @if($billCustomer->gstin)<p>GSTIN: {{ $billCustomer->gstin }}</p>@endif
            @else
                <p>Customer information not available</p>
            @endif
        </div>

        <!-- Line Items -->
        <table>
            <thead>
                <tr>
                    <th>Item Description</th>
                    <th class="text-center">Quantity</th>
                    <th class="text-right">Unit Price</th>
                    <th class="text-right">Amount</th>
                    <th class="text-right">Tax</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice->items as $item)
                <tr>
                    <td>{{ $item->panelType->name ?? 'Item' }}@if($item->panelType && $item->panelType->thickness) ({{ $item->panelType->thickness }}mm)@endif</td>
                    <td class="text-center">{{ $item->quantity }}</td>
                    <td class="text-right">₹ {{ number_format($item->unit_price, 2) }}</td>
                    <td class="text-right">₹ {{ number_format($item->amount, 2) }}</td>
                    <td class="text-right">₹ {{ number_format($item->tax_amount, 2) }}</td>
                    <td class="text-right"><strong>₹ {{ number_format($item->total_with_tax, 2) }}</strong></td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <!-- Totals -->
        <div class="totals">
            <div class="totals-row subtotal-row">
                <div class="totals-label">Subtotal:</div>
                <div class="totals-value">₹ {{ number_format($invoice->subtotal, 2) }}</div>
            </div>
            @if($invoice->taxCalculation)
            <div class="totals-row">
                <div class="totals-label">Tax ({{ $invoice->taxCalculation->tax_rate }}%):</div>
                <div class="totals-value">₹ {{ number_format($invoice->taxCalculation->tax_amount, 2) }}</div>
            </div>
            @if($invoice->taxCalculation->sgst_amount > 0)
            <div class="totals-row" style="font-size: 11px; padding-right: 40px;">
                <div class="totals-label">SGST:</div>
                <div class="totals-value">₹ {{ number_format($invoice->taxCalculation->sgst_amount, 2) }}</div>
            </div>
            <div class="totals-row" style="font-size: 11px; padding-right: 40px;">
                <div class="totals-label">CGST:</div>
                <div class="totals-value">₹ {{ number_format($invoice->taxCalculation->cgst_amount, 2) }}</div>
            </div>
            @endif
            @endif
            <div class="totals-row total-row">
                <div class="totals-label">Total Due:</div>
                <div class="totals-value">₹ {{ number_format($total, 2) }}</div>
            </div>
        </div>

        <!-- Payment Info -->
        @if($invoice->status !== 'draft' && $invoice->status !== 'cancelled')
        <div class="payment-info">
            <h4>Payment Information</h4>
            <p>Please remit payment by {{ $invoice->due_date->format('M d, Y') }}</p>
            <p>Bank Transfer or Check accepted</p>
        </div>
        @endif

        <!-- Notes -->
        @if($invoice->notes)
        <div class="notes">
            <h4>Additional Notes:</h4>
            <p>{{ $invoice->notes }}</p>
        </div>
        @endif

        <!-- Terms -->
        @if($invoice->terms)
        <div class="notes">
            <h4>Terms & Conditions:</h4>
            <p>{{ $invoice->terms }}</p>
        </div>
        @endif

        <!-- Footer -->
        <div class="footer">
            <p>Generated on {{ now()->format('F d, Y \a\t H:i A') }}</p>
            <p>This is an electronically generated document.</p>
        </div>
    </div>
</body>
</html>
