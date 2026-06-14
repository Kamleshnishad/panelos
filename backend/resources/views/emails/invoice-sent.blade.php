@component('mail::message')
# Invoice {{ $invoiceNo }}

Dear {{ $customerName }},

We are pleased to send you the attached invoice for your recent order.

## Invoice Details

- **Invoice Number:** {{ $invoiceNo }}
- **Total Amount Due:** ${{ number_format($total, 2) }}
- **Due Date:** {{ $dueDate }}

## What to Do Next

Please review the attached invoice and arrange payment according to the terms specified. You can pay via:
- Bank Transfer
- Check
- Online Payment

If you have any questions about this invoice, please don't hesitate to contact us.

@component('mail::button', ['url' => config('app.url')])
View Invoice
@endcomponent

Thank you for your business!

Best regards,
{{ config('app.name') }} Team

---
*This is an automated email. Please do not reply directly to this message.*
@endcomponent
