<?php

namespace App\Support;

use App\Models\NotificationSetting;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Mail;

/**
 * A company's own mailbox for the e-mail it sends to its customers (invoices, reminders, payment receipts).
 *
 * With the switch on and every field filled, mail goes out through that mailbox, synchronously and from that address.
 * Otherwise it falls back to the server-wide mailer, queued, exactly as before. It is synchronous because a queued
 * job runs in a worker that knows nothing about this company's settings, and because a wrong password should fail where
 * the user can see it. Platform mail (login codes, server alerts) deliberately never uses a company mailbox: a tenant
 * with a broken password must not lock its own staff out.
 */
class CompanyMail
{
    /** @var callable|null  replaces the real SMTP mailer; tests only */
    private static $factory = null;

    public static function useFactory(?callable $factory): void
    {
        self::$factory = $factory;
    }

    /** The company's settings when it sends from its own mailbox, null when it uses the server's. */
    public static function activeFor(?int $companyId): ?NotificationSetting
    {
        if (!$companyId) {
            return null;
        }
        $s = NotificationSetting::withoutGlobalScope('tenant')->where('company_id', $companyId)->first();

        return $s && $s->hasOwnMailbox() ? $s : null;
    }

    /** Symfony/Laravel mailer config for the company's mailbox. 465 = implicit SSL, anything else starts plain and upgrades with STARTTLS. */
    public static function config(NotificationSetting $s): array
    {
        return [
            'transport' => 'smtp',
            'scheme'    => $s->smtp_encryption === 'ssl' ? 'smtps' : 'smtp',
            'host'      => $s->smtp_host,
            'port'      => (int) $s->smtp_port,
            'username'  => $s->smtp_username,
            'password'  => $s->smtp_password,
            'timeout'   => 10,
        ];
    }

    /** @return array{0:string,1:?string} address and display name the mail is sent as */
    public static function from(NotificationSetting $s): array
    {
        return [$s->smtp_from_address, $s->smtp_from_name ?: null];
    }

    public static function mailer(NotificationSetting $s): Mailer
    {
        if (self::$factory) {
            return (self::$factory)($s);
        }
        SmtpHostGuard::assertSafeToConnect($s->smtp_host);

        return Mail::build(self::config($s));
    }

    /** Sends $mailable to $to from the company's mailbox if it has one (now), else through the server's mailer (queued). */
    public static function deliver(?int $companyId, string $to, Mailable $mailable): void
    {
        if ($s = self::activeFor($companyId)) {
            [$address, $name] = self::from($s);
            $mailable->from($address, $name);
            self::mailer($s)->to($to)->sendNow($mailable);

            return;
        }

        Mail::to($to)->queue($mailable);
    }
}
