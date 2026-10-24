<?php

namespace App\Support;

use App\Models\NotificationSetting;

/**
 * One of a company's two mailboxes, read from its settings row.
 *
 *  - main           smtp_*        quotations, invoices, everything the team sends by hand
 *  - notifications  notif_smtp_*  the automatic messages: payment reminders and receipts
 */
final class CompanyMailbox
{
    public const MAIN = 'main';
    public const NOTIFICATIONS = 'notifications';

    public function __construct(
        public readonly string $kind,
        public readonly bool $enabled,
        public readonly ?string $host,
        public readonly ?int $port,
        public readonly ?string $encryption,
        public readonly ?string $username,
        public readonly ?string $password,
        public readonly ?string $fromAddress,
        public readonly ?string $fromName,
    ) {
    }

    /** Column prefix for a mailbox kind. */
    public static function prefix(string $kind): string
    {
        return $kind === self::NOTIFICATIONS ? 'notif_smtp_' : 'smtp_';
    }

    public static function fromSettings(NotificationSetting $s, string $kind): self
    {
        $p = self::prefix($kind);
        $port = $s->getAttribute($p . 'port');

        return new self(
            $kind,
            (bool) $s->getAttribute($p . 'enabled'),
            $s->getAttribute($p . 'host'),
            $port === null ? null : (int) $port,
            $s->getAttribute($p . 'encryption'),
            $s->getAttribute($p . 'username'),
            $s->getAttribute($p . 'password'),
            $s->getAttribute($p . 'from_address'),
            $s->getAttribute($p . 'from_name'),
        );
    }

    /** Switched on and every field needed to log in and to say who the mail is from. */
    public function isComplete(): bool
    {
        return $this->enabled && $this->host && $this->port && $this->username && !empty($this->password) && $this->fromAddress;
    }
}
