@component('mail::message')
@if($isOverdue)
# Payment Reminder - Invoice Overdue

Dear {{ $customerName }},

We notice that payment for the following invoice is **{{ $daysOverdue }} days overdue**.
@else
# Payment Reminder

Dear {{ $customerName }},

This is a friendly reminder that the following invoice payment is due soon.
@endif

## Invoice Details

- **Invoice Number:** {{ $invoiceNo }}
- **Amount Due:** ${{ number_format($remainingDue, 2) }}
- **Original Due Date:** {{ $dueDate }}

@if($isOverdue)
## Immediate Action Required

Please arrange payment at your earliest convenience. If payment has already been sent, please disregard this notice.
@else
## Please Pay By

{{ $dueDate }}

We appreciate your prompt attention to this matter.
@endif

## Payment Methods Accepted

- Bank Transfer
- Check
- Online Payment
- Cash

If you have questions or need to discuss payment terms, please contact us immediately.

@component('mail::button', ['url' => config('app.url')])
View Invoice Details
@endcomponent

Thank you,
{{ config('app.name') }} Team

---
*This is an automated reminder. Please do not reply directly to this message.*
@endcomponent
