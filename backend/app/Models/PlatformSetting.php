<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton platform settings (Razorpay creds + GST seller identity).
 * DB values take priority over .env/config; falls back to config when blank.
 */
class PlatformSetting extends Model
{
    protected $fillable = [
        'razorpay_enabled', 'razorpay_key_id', 'razorpay_key_secret', 'razorpay_webhook_secret',
        'platform_name', 'platform_gstin', 'platform_pan', 'platform_address',
        'platform_state', 'platform_state_code', 'platform_email', 'platform_phone', 'platform_sac',
        'plan_prices',
    ];

    protected $casts = ['razorpay_enabled' => 'boolean', 'plan_prices' => 'array'];

    protected $hidden = ['razorpay_key_secret', 'razorpay_webhook_secret'];

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }

    /** Plan prices: DB override merged over config defaults. */
    public function planPrices(): array
    {
        return array_merge(config('plans.prices', []), array_filter((array) ($this->plan_prices ?? [])));
    }

    public function priceFor(string $plan): int
    {
        return (int) ($this->planPrices()[$plan] ?? 0);
    }

    // ── Razorpay (DB first, env fallback) ────────────────────────────────
    public function rzpEnabled(): bool { return (bool) ($this->razorpay_enabled || config('services.razorpay.enabled')); }
    public function rzpKeyId(): ?string { return $this->razorpay_key_id ?: config('services.razorpay.key_id'); }
    public function rzpKeySecret(): ?string { return $this->razorpay_key_secret ?: config('services.razorpay.key_secret'); }
    public function rzpWebhookSecret(): ?string { return $this->razorpay_webhook_secret ?: config('services.razorpay.webhook_secret'); }

    /** Razorpay is usable only if enabled AND key+secret present. */
    public function rzpReady(): bool { return $this->rzpEnabled() && $this->rzpKeyId() && $this->rzpKeySecret(); }

    // ── Platform GST seller identity (DB first, config fallback) ──────────
    public function billingIdentity(): array
    {
        $c = config('platform');
        return [
            'name'       => $this->platform_name       ?: $c['name'],
            'gstin'      => $this->platform_gstin       ?: $c['gstin'],
            'pan'        => $this->platform_pan         ?: $c['pan'],
            'address'    => $this->platform_address     ?: $c['address'],
            'state'      => $this->platform_state       ?: $c['state'],
            'state_code' => $this->platform_state_code  ?: $c['state_code'],
            'email'      => $this->platform_email       ?: $c['email'],
            'phone'      => $this->platform_phone       ?: $c['phone'],
            'hsn_sac'    => $this->platform_sac         ?: $c['hsn_sac'],
        ];
    }
}
