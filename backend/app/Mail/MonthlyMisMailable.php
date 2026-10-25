<?php

namespace App\Mail;

use App\Support\Inr;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The owner's monthly MIS (FR-010): one small table of the month's figures, sent through the company's own mailbox, never queued. */
class MonthlyMisMailable extends Mailable
{
    /** @param array{company:string, month_label:string, mis:array, cash_received:float, receivable:array} $data */
    public function __construct(public array $data)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Monthly MIS: {$this->data['month_label']} - {$this->data['company']}");
    }

    public function content(): Content
    {
        $m = $this->data['mis'];
        $row = fn (string $label, string $value, bool $bold = false) => '<tr><td style="padding:6px 12px;border-bottom:1px solid #eee;color:#555">' . e($label) . '</td><td style="padding:6px 12px;border-bottom:1px solid #eee;text-align:right;'
            . ($bold ? 'font-weight:bold;' : '') . '">' . $value . '</td></tr>';
        $rs = fn ($v) => '₹ ' . Inr::fmt($v, 2);
        $ar = $this->data['receivable'];

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#222;max-width:560px">'
            . '<h2 style="color:#2B50E0;margin:0 0 4px">' . e($this->data['company']) . '</h2>'
            . '<div style="color:#777;margin-bottom:14px">Monthly report for ' . e($this->data['month_label']) . '</div>'
            . '<table style="border-collapse:collapse;width:100%">'
            . $row('Invoiced this month', $rs($m['revenue']['invoiced']), true)
            . $row('Money received this month', $rs($this->data['cash_received']), true)
            . $row('Of this month\'s invoices: paid so far', $rs($m['revenue']['collected']))
            . $row('Of this month\'s invoices: still unpaid', $rs($m['revenue']['outstanding']))
            . $row('GST on this month\'s invoices', $rs($m['gst']['total']))
            . $row('Orders placed', (string) $m['orders']['count'])
            . $row('Production runs completed', $m['production']['runs'] . ' (' . Inr::fmt($m['production']['sqm_produced'], 2) . ' sqm planned)')
            . $row('Total receivable at month end', $rs($ar['total_ar']), true)
            . $row('Of which overdue', $rs($ar['30_days'] + $ar['60_days'] + $ar['90_days'] + $ar['over_90_days']))
            . '</table>'
            . '<p style="color:#888;font-size:12px;margin-top:16px">Sent automatically on the 1st of each month. Switch it off in Settings &rarr; Notifications.</p></div>';

        return new Content(htmlString: $html);
    }
}
