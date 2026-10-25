<?php

namespace App\Support;

use App\Models\NotificationSetting;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * A company's own mailboxes for the e-mail it sends to its customers.
 *
 * Two kinds (see CompanyMailbox): the MAIN mailbox for what the team sends by hand (invoices, later quotations) and the
 * NOTIFICATIONS mailbox for the automatic messages (payment reminders, receipts), so the main address is not the one
 * sending every automatic message. Notifications fall back to the main mailbox when no separate one is set up, and
 * both fall back to the server-wide mailer, queued, exactly as before.
 *
 * Mail through a company mailbox is sent now, not queued: a queued job runs in a worker that knows nothing about this
 * company's settings, and a wrong password should fail where the user can see it. Platform mail (login codes, server
 * alerts) deliberately never uses a company mailbox: a tenant with a broken password must not lock its own staff out.
 */
class CompanyMail
{
    /**
     * The kinds of customer e-mail the admin can route, with the mailbox each uses until the admin says otherwise.
     * Adding a kind (say, quotations once they can be e-mailed) is one line here; the screen and the API follow.
     */
    public const TYPES = [
        'invoice'         => ['label' => 'Invoices sent by your team', 'default' => CompanyMailbox::MAIN],
        'payment_reminder' => ['label' => 'Payment reminders (automatic)', 'default' => CompanyMailbox::NOTIFICATIONS],
        'payment_receipt' => ['label' => 'Payment receipts (automatic)', 'default' => CompanyMailbox::NOTIFICATIONS],
        'lead_message'    => ['label' => 'E-mails your team writes to a lead', 'default' => CompanyMailbox::MAIN],
        'monthly_mis'     => ['label' => 'Monthly MIS report to the owners (automatic)', 'default' => CompanyMailbox::NOTIFICATIONS],
    ];

    /** @var callable|null  replaces the real SMTP mailer; tests only */
    private static $factory = null;

    public static function useFactory(?callable $factory): void
    {
        self::$factory = $factory;
    }

    /** Which mailbox (main or notifications) a kind of e-mail is set to use: the admin's choice, else the default. */
    public static function kindFor(?int $companyId, string $type): string
    {
        $default = self::TYPES[$type]['default'] ?? CompanyMailbox::MAIN;
        if (!$companyId) {
            return $default;
        }
        $routing = NotificationSetting::withoutGlobalScope('tenant')->where('company_id', $companyId)->value('mail_routing');
        $routing = is_string($routing) ? json_decode($routing, true) : $routing;
        $chosen = is_array($routing) ? ($routing[$type] ?? null) : null;

        return in_array($chosen, [CompanyMailbox::MAIN, CompanyMailbox::NOTIFICATIONS], true) ? $chosen : $default;
    }

    /**
     * The mailbox this kind of mail goes out through, or null for the server's mailer.
     * NOTIFICATIONS uses its own mailbox when complete, else the main one, else null.
     */
    public static function activeFor(?int $companyId, string $kind = CompanyMailbox::MAIN): ?CompanyMailbox
    {
        if (!$companyId) {
            return null;
        }
        $s = NotificationSetting::withoutGlobalScope('tenant')->where('company_id', $companyId)->first();
        if (!$s) {
            return null;
        }

        $box = CompanyMailbox::fromSettings($s, $kind);
        if ($box->isComplete()) {
            return $box;
        }
        if ($kind === CompanyMailbox::NOTIFICATIONS) {
            $main = CompanyMailbox::fromSettings($s, CompanyMailbox::MAIN);

            return $main->isComplete() ? $main : null;
        }

        return null;
    }

    /** Exactly this mailbox, with no fallback: the one the admin is testing or editing. Null unless it is switched on and complete. */
    public static function exactFor(?int $companyId, string $kind): ?CompanyMailbox
    {
        $s = $companyId ? NotificationSetting::withoutGlobalScope('tenant')->where('company_id', $companyId)->first() : null;
        $box = $s ? CompanyMailbox::fromSettings($s, $kind) : null;

        return $box && $box->isComplete() ? $box : null;
    }

    /** Symfony/Laravel mailer config. 465 = implicit SSL, anything else starts plain and upgrades with STARTTLS. */
    public static function config(CompanyMailbox $b): array
    {
        return [
            'transport' => 'smtp',
            'scheme'    => $b->encryption === 'ssl' ? 'smtps' : 'smtp',
            'host'      => $b->host,
            'port'      => (int) $b->port,
            'username'  => $b->username,
            'password'  => $b->password,
            'timeout'   => 10,
        ];
    }

    /** @return array{0:string,1:?string} address and display name the mail is sent as */
    public static function from(CompanyMailbox $b): array
    {
        return [$b->fromAddress, $b->fromName ?: null];
    }

    public static function mailer(CompanyMailbox $b): Mailer
    {
        if (self::$factory) {
            return (self::$factory)($b);
        }
        SmtpHostGuard::assertSafeToConnect($b->host);

        return Mail::build(self::config($b));
    }

    /** Sends $mailable to $to from the mailbox its type is routed to (now), else through the server's mailer (queued). */
    public static function deliver(?int $companyId, string $to, Mailable $mailable, string $type = 'invoice'): void
    {
        if ($box = self::activeFor($companyId, self::kindFor($companyId, $type))) {
            [$address, $name] = self::from($box);
            $mailable->from($address, $name);
            self::mailer($box)->to($to)->sendNow($mailable);

            return;
        }

        Mail::to($to)->queue($mailable);
    }
}
