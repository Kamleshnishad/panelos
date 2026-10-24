<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationSetting extends Model
{
    use \App\Models\Concerns\BelongsToTenant;

    protected $fillable = [
        'company_id',
        'twilio_account_sid', 'twilio_auth_token', 'twilio_from_number', 'sms_enabled',
        'whatsapp_from', 'whatsapp_enabled',
        'notify_payment_due', 'payment_due_days_before',
        'notify_payment_overdue', 'notify_low_stock',
        'notify_order_confirmed', 'notify_dispatch_done',
        'admin_phone',
        'email_payment_reminders',
        'smtp_enabled', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password', 'smtp_from_address', 'smtp_from_name',
    ];

    protected $casts = [
        'sms_enabled'            => 'boolean',
        'whatsapp_enabled'       => 'boolean',
        'notify_payment_due'     => 'boolean',
        'notify_payment_overdue' => 'boolean',
        'notify_low_stock'       => 'boolean',
        'notify_order_confirmed' => 'boolean',
        'notify_dispatch_done'   => 'boolean',
        'payment_due_days_before'=> 'integer',
        'email_payment_reminders'=> 'boolean',
        'smtp_enabled'           => 'boolean',
        'smtp_port'              => 'integer',
        'smtp_password'          => 'encrypted',   // never stored in the clear, never returned by the API
    ];

    /** Hidden in API responses so tokens never leak in list endpoints */
    protected $hidden = ['twilio_auth_token', 'smtp_password'];

    public static function forCompany(int $companyId): self
    {
        return static::firstOrCreate(
            ['company_id' => $companyId],
            ['sms_enabled' => false, 'whatsapp_enabled' => false, 'email_payment_reminders' => false]
        );
    }

    /** Sends from the company's own mailbox: switched on and every field needed to log in and to say who it is from. */
    public function hasOwnMailbox(): bool
    {
        return $this->smtp_enabled && $this->smtp_host && $this->smtp_port && $this->smtp_username
            && !empty($this->getAttribute('smtp_password')) && $this->smtp_from_address;
    }

    /** Whether the automatic reminder e-mails are on for a company (off unless an admin turned them on). Safe with no signed-in user. */
    public static function emailRemindersEnabled(int $companyId): bool
    {
        return (bool) static::withoutGlobalScope('tenant')->where('company_id', $companyId)->value('email_payment_reminders');
    }

    /** Resolved credentials: DB values take priority over env vars. */
    public function resolvedSid(): ?string
    {
        return $this->twilio_account_sid ?: config('services.twilio.account_sid');
    }

    public function resolvedToken(): ?string
    {
        return $this->twilio_auth_token ?: config('services.twilio.auth_token');
    }

    public function resolvedFromNumber(): ?string
    {
        return $this->twilio_from_number ?: config('services.twilio.from_number');
    }

    public function resolvedWhatsappFrom(): ?string
    {
        return $this->whatsapp_from ?: config('services.twilio.whatsapp_from');
    }

    public function isSmsReady(): bool
    {
        return $this->sms_enabled && $this->resolvedSid() && $this->resolvedToken() && $this->resolvedFromNumber();
    }

    public function isWhatsappReady(): bool
    {
        return $this->whatsapp_enabled && $this->resolvedSid() && $this->resolvedToken() && $this->resolvedWhatsappFrom();
    }
}
