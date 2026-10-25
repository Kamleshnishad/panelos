<?php

namespace App\Services;

use App\Mail\LeadMessageMailable;
use App\Models\Company;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadMessageTemplate;
use App\Models\User;
use App\Support\CompanyMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Contacting a lead from its own screen (LEADS_PLAN 5.3).
 *
 *  - Templates: the company's ready-made WhatsApp texts and e-mails with placeholders ({name}, {my_name} ...), made for a company the first time it needs them.
 *  - WhatsApp: opens WhatsApp's own click-to-chat (wa.me) with the text filled in; no WhatsApp account or API is involved. We can know the chat was opened, not
 *    that the message was sent, so the timeline says "opened".
 *  - E-mail: sent now through the company's own mailbox (Customer Messaging), from the team's address with the sender as reply-to, to the lead's saved address only.
 *    A company with no mailbox set up is told so; mail from a lead never goes out through the platform's server address, where replies would go nowhere.
 *  - A call is a tel: link on the screen; the person logs how it went with the ordinary note (type call).
 * Every use is written to the lead's timeline.
 */
class LeadMessageService
{
    /** placeholder => what it becomes */
    public const PLACEHOLDERS = [
        'name' => "The lead's name", 'company' => "The lead's company", 'lead_no' => 'The lead number', 'requirement' => 'What they asked for (short)',
        'city' => "The lead's city", 'my_name' => 'Your name', 'my_company' => 'Your company name',
    ];

    private const DEFAULTS = [
        ['whatsapp', 'First reply', null, "Hello {name}, thank you for your enquiry about {requirement}. This is {my_name} from {my_company}. May I call you to understand your requirement?"],
        ['whatsapp', 'No answer, call me back', null, "Hello {name}, I tried calling you about your enquiry ({lead_no}). Please let me know a good time to talk. {my_name}, {my_company}"],
        ['whatsapp', 'Site visit', null, "Hello {name}, I would like to visit your site to take measurements for {requirement}. When would be convenient for you? {my_name}, {my_company}"],
        ['whatsapp', 'Quotation follow-up', null, "Hello {name}, this is {my_name} from {my_company}. I wanted to check whether you had a chance to look at our quotation. I am happy to answer any questions."],
        ['email', 'Thank you for your enquiry', 'Your enquiry with {my_company}', "Dear {name},\n\nThank you for your enquiry about {requirement}. We would like to understand your requirement so that we can give you the right quotation.\n\nPlease reply to this e-mail, or tell us a good time to call you.\n\nRegards,\n{my_name}\n{my_company}"],
        ['email', 'Quotation follow-up', 'Following up on our quotation, {lead_no}', "Dear {name},\n\nI am following up on the quotation we sent you. Please let me know if you would like any changes or have any questions.\n\nRegards,\n{my_name}\n{my_company}"],
    ];

    // ── templates ────────────────────────────────────────────────────────────

    public function ensureDefaults(int $companyId): void
    {
        if (LeadMessageTemplate::withoutGlobalScope('tenant')->withTrashed()->where('company_id', $companyId)->exists()) {
            return;                                                   // a company that deleted them all does not get them back
        }
        $now = now();
        foreach (self::DEFAULTS as $i => [$channel, $name, $subject, $body]) {
            DB::table('lead_message_templates')->insert(['company_id' => $companyId, 'channel' => $channel, 'name' => $name, 'subject' => $subject, 'body' => $body,
                'is_active' => true, 'sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    /** @return \Illuminate\Support\Collection<int, LeadMessageTemplate> */
    public function templates(int $companyId, ?string $channel = null, bool $activeOnly = false)
    {
        $this->ensureDefaults($companyId);

        return LeadMessageTemplate::where('company_id', $companyId)->when($channel, fn ($q) => $q->where('channel', $channel))
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))->orderBy('channel')->orderBy('sort_order')->orderBy('id')->get();
    }

    /** Fill the placeholders in $text. An unknown {word} is left as typed; a missing value reads naturally ("there" for a name). */
    public function render(string $text, Lead $lead, User $user): string
    {
        $req = trim((string) $lead->requirement);
        $values = [
            'name'        => trim((string) $lead->contact_name) ?: 'there',
            'company'     => trim((string) $lead->company_name),
            'lead_no'     => $lead->lead_no,
            'requirement' => $req !== '' ? rtrim(mb_substr(preg_replace('/\s+/', ' ', $req), 0, 80), " .,;") : 'your enquiry',
            'city'        => trim((string) $lead->city),
            'my_name'     => $user->name,
            'my_company'  => (string) Company::whereKey($user->company_id)->value('name'),
        ];

        return preg_replace_callback('/\{(' . implode('|', array_keys(self::PLACEHOLDERS)) . ')\}/', fn ($m) => $values[$m[1]], $text);
    }

    // ── WhatsApp ─────────────────────────────────────────────────────────────

    /** Digits only with the country code (Indian numbers get 91), or null when it is not a number WhatsApp could reach. */
    public static function waNumber(?string $phone): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $phone);
        if (strlen($d) === 10) {
            return '91' . $d;
        }
        if (strlen($d) === 11 && $d[0] === '0') {
            return '91' . substr($d, 1);
        }
        if (strlen($d) === 12 && str_starts_with($d, '91')) {
            return $d;
        }

        return strlen($d) >= 11 && strlen($d) <= 15 ? $d : null;           // another country's number, with its own code
    }

    /** The WhatsApp link for this message, and a line on the timeline saying the chat was opened. @return array{url:string, number:string} */
    public function whatsapp(Lead $lead, User $user, string $message): array
    {
        $number = self::waNumber($lead->phone);
        if (!$number) {
            $this->refuse('phone', 'This lead has no phone number WhatsApp can use. Add the number with its 10 digits.');
        }
        $message = trim($message);
        if ($message === '') {
            $this->refuse('message', 'Write the message first.');
        }
        $this->log($lead, $user, 'whatsapp', 'WhatsApp opened with: ' . mb_substr($message, 0, 300) . (mb_strlen($message) > 300 ? '…' : ''));

        return ['url' => 'https://wa.me/' . $number . '?text=' . rawurlencode($message), 'number' => $number];
    }

    // ── e-mail ───────────────────────────────────────────────────────────────

    /** Send now, from the company mailbox, to the lead's own address. @throws ValidationException when it cannot be sent, with words the person can act on */
    public function email(Lead $lead, User $user, string $subject, string $body): void
    {
        $to = trim((string) $lead->email);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->refuse('email', 'This lead has no valid e-mail address. Add one to the lead first.');
        }
        $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject));
        $body = trim($body);
        if ($subject === '' || $body === '') {
            $this->refuse('body', 'Give the e-mail a subject and some text.');
        }
        if (!CompanyMail::activeFor($lead->company_id, CompanyMail::kindFor($lead->company_id, 'lead_message'))) {
            $this->refuse('email', 'E-mail is not set up for your company yet. Add your mailbox in Customer Messaging, then try again.');
        }

        try {
            CompanyMail::deliver($lead->company_id, $to, new LeadMessageMailable($subject, $body, $user->email, $user->name), 'lead_message');
        } catch (\Throwable $e) {
            Log::warning('lead e-mail not sent', ['lead' => $lead->id, 'error' => get_class($e)]);          // never the message: it can name the mail server and the login
            $this->refuse('email', 'Your mailbox did not accept the message. Check the mailbox settings in Customer Messaging and try again.');
        }
        $this->log($lead, $user, 'email', "E-mail sent to {$to}: {$subject}\n" . mb_substr($body, 0, 600) . (mb_strlen($body) > 600 ? '…' : ''));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function log(Lead $lead, User $user, string $type, string $text): void
    {
        LeadActivity::create(['company_id' => $lead->company_id, 'lead_id' => $lead->id, 'user_id' => $user->id, 'type' => $type, 'description' => $text, 'activity_date' => now()]);
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
