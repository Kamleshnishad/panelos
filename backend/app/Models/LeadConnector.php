<?php

namespace App\Models;

/** A company's link to one outside lead channel (LEADS_PLAN 3.1). The secret is encrypted and never leaves the server. */
class LeadConnector extends BaseModel
{
    public const INDIAMART = 'indiamart';

    protected $fillable = [
        'company_id', 'type', 'is_enabled', 'credentials', 'key_hint', 'last_test_at', 'last_test_ok', 'last_test_message',
        'last_request_at', 'cursor_at', 'last_success_at', 'last_error_at', 'last_error',
    ];

    protected $hidden = ['credentials'];

    protected $casts = [
        'is_enabled'      => 'boolean',
        'credentials'     => 'encrypted:array',
        'last_test_at'    => 'datetime',
        'last_test_ok'    => 'boolean',
        'last_request_at' => 'datetime',
        'cursor_at'       => 'datetime',
        'last_success_at' => 'datetime',
        'last_error_at'   => 'datetime',
    ];

    public static function forCompany(int $companyId, string $type = self::INDIAMART): self
    {
        return static::withoutGlobalScope('tenant')->firstOrCreate(['company_id' => $companyId, 'type' => $type]);
    }

    public function key(): ?string
    {
        $key = $this->credentials['crm_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    public function setKey(?string $key): void
    {
        $this->credentials = $key ? ['crm_key' => $key] : null;
        $this->key_hint = $key ? substr($key, -4) : null;
    }

    /** What the screens may see: whether a key is saved, its last characters, and the test results. Never the key. */
    public function summary(): array
    {
        return [
            'type'              => $this->type,
            'configured'        => $this->key() !== null,
            'key_hint'          => $this->key_hint,
            'is_enabled'        => $this->is_enabled,
            'cursor_at'         => $this->cursor_at,
            'last_test_at'      => $this->last_test_at,
            'last_test_ok'      => $this->last_test_ok,
            'last_test_message' => $this->last_test_message,
            'last_success_at'   => $this->last_success_at,
            'last_error_at'     => $this->last_error_at,
            'last_error'        => $this->last_error,
        ];
    }
}
