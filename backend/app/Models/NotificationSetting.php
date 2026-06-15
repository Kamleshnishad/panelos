<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationSetting extends Model
{
    protected $fillable = [
        'company_id',
        'twilio_account_sid', 'twilio_auth_token', 'twilio_from_number', 'sms_enabled',
        'whatsapp_from', 'whatsapp_enabled',
        'notify_payment_due', 'payment_due_days_before',
        'notify_payment_overdue', 'notify_low_stock',
        'notify_order_confirmed', 'notify_dispatch_done',
        'admin_phone',
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
    ];

    /** Hidden in API responses so tokens never leak in list endpoints */
    protected $hidden = ['twilio_auth_token'];

    public static function forCompany(int $companyId): self
    {
        return static::firstOrCreate(
            ['company_id' => $companyId],
            ['sms_enabled' => false, 'whatsapp_enabled' => false]
        );
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
