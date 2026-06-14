@component('mail::message')
# Payment Received - Thank You!

Dear {{ $customerName }},

We have received your payment. Thank you for your prompt payment!

## Payment Confirmation

- **Invoice Number:** {{ $invoiceNo }}
- **Amount Received:** ${{ number_format($paymentAmount, 2) }}
- **Payment Method:** {{ $paymentMethod }}
- **Date Received:** {{ $paymentDate }}
- **Payment Status:** {{ $paymentStatus }}

@if($remainingDue > 0)
## Outstanding Balance

- **Total Invoice Amount:** ${{ number_format($totalAmount, 2) }}
- **Remaining Due:** ${{ number_format($remainingDue, 2) }}

Please arrange payment for the outstanding balance by the original due date.
@else
## Status

This invoice is now **fully paid**. Thank you for your business!
@endif

If you have any questions about this payment or need additional documentation, please don't hesitate to contact us.

@component('mail::button', ['url' => config('app.url')])
View Invoice Details
@endcomponent

We appreciate your business!

Best regards,
{{ config('app.name') }} Team

---
*This is an automated confirmation. Please do not reply directly to this message.*
@endcomponent
