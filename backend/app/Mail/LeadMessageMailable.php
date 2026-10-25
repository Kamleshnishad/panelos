<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A plain e-mail a person on the team writes to a lead (LEADS_PLAN 5.3). Sent now through the company's own mailbox, never queued; replies go to the sender. */
class LeadMessageMailable extends Mailable
{
    public function __construct(public string $mailSubject, public string $messageBody, public ?string $replyToAddress = null, public ?string $replyToName = null)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->mailSubject,
            replyTo: $this->replyToAddress ? [new Address($this->replyToAddress, $this->replyToName ?? '')] : [],
        );
    }

    public function content(): Content
    {
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#222">' . nl2br(e($this->messageBody)) . '</div>';

        return new Content(htmlString: $html);
    }
}
