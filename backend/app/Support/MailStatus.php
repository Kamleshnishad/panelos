<?php

namespace App\Support;

/**
 * What the server will do with an e-mail, for the admin screen. Never includes credentials.
 * The `log` mailer writes the message to a log file and delivers nothing — the shipped .env.example default —
 * so a server left on it looks like it is sending mail while nobody receives any.
 */
class MailStatus
{
    public static function describe(?int $companyId = null): array
    {
        // A company that sends from its own mailbox does not depend on the server's mail setting at all.
        if ($own = CompanyMail::activeFor($companyId)) {
            return [
                'mailer'   => 'company-smtp',
                'delivers' => true,
                'own'      => true,
                'from'     => $own->smtp_from_address,
                'headline' => "E-mail is set up: customer e-mail is sent from {$own->smtp_from_address} through {$own->smtp_host}.",
            ];
        }

        $mailer = (string) config('mail.default');
        $delivers = $mailer !== 'log';

        return [
            'mailer'   => $mailer,
            'delivers' => $delivers,
            'own'      => false,
            'from'     => config('mail.from.address'),
            'headline' => $delivers
                ? "E-mail is set up (sending through \"{$mailer}\")."
                : 'E-mail is NOT set up on this server: messages are written to a log file and never delivered. Ask whoever hosts PanelOS to set MAIL_MAILER (for example smtp) and the MAIL_* settings.',
        ];
    }
}
