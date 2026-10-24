<?php

namespace App\Services;

use App\Models\LeadConnector;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Settings and the "test connection" for an outside lead channel (LEADS_PLAN 3.1). The key is stored encrypted, shown as "set" with its last
 * four characters, and never returned. A test looks at the last 6 hours, only counts what is there, and creates nothing.
 */
class LeadConnectorService
{
    public const TEST_WINDOW_HOURS = 6;

    public function __construct(private IndiaMartClient $indiaMart)
    {
    }

    /** A new key (blank keeps the saved one), or $clear to remove it. Saving a different key forgets the last test result. */
    public function saveIndiaMart(int $companyId, ?string $key, bool $clear = false): LeadConnector
    {
        $c = LeadConnector::forCompany($companyId);
        $key = $key === null ? null : trim($key);

        if ($clear) {
            $c->setKey(null);
            $c->forceFill(['is_enabled' => false, 'last_test_at' => null, 'last_test_ok' => null, 'last_test_message' => null, 'cursor_at' => null])->save();

            return $c;
        }
        if ($key !== null && $key !== '') {
            if (!preg_match('/^[A-Za-z0-9._\-=+\/]{8,200}$/', $key)) {
                throw ValidationException::withMessages(['crm_key' => 'That does not look like an IndiaMART CRM key. Copy it again from Lead Manager → CRM Integration (no spaces).']);
            }
            if ($key !== $c->key()) {
                $c->setKey($key);
                $c->forceFill(['last_test_at' => null, 'last_test_ok' => null, 'last_test_message' => null, 'last_error' => null, 'last_error_at' => null])->save();
            }
        }

        return $c;
    }

    /**
     * @return array{ok:bool, message:string, count:?int, tested_at:string}
     * @throws ValidationException when there is no key to test, or the last request to IndiaMART was under 5 minutes ago
     */
    public function testIndiaMart(int $companyId): array
    {
        $c = LeadConnector::forCompany($companyId);
        $key = $c->key();
        if (!$key) {
            throw ValidationException::withMessages(['crm_key' => 'Save the CRM key first, then test it.']);
        }
        if ($c->last_request_at && $c->last_request_at->gt(now()->subMinutes(IndiaMartClient::MIN_GAP_MINUTES))) {
            $wait = max(1, (int) ceil(now()->diffInSeconds($c->last_request_at->copy()->addMinutes(IndiaMartClient::MIN_GAP_MINUTES)) / 60));
            throw ValidationException::withMessages(['test' => "IndiaMART allows one request every 5 minutes, and one was made a moment ago. Try again in about {$wait} minute" . ($wait === 1 ? '' : 's') . '.']);
        }

        $c->forceFill(['last_request_at' => now()])->save();      // counts even if it fails: IndiaMART counts it too
        try {
            $rows = $this->indiaMart->fetch($key, now()->subHours(self::TEST_WINDOW_HOURS), now());
            $count = count($rows);
            $ok = true;
            $message = "Connected. IndiaMART holds {$count} " . ($count === 1 ? 'enquiry' : 'enquiries') . ' from the last ' . self::TEST_WINDOW_HOURS . ' hours. Nothing was imported.';
        } catch (IndiaMartException $e) {
            $count = null;
            $ok = false;
            $message = $e->getMessage();
        }

        $c->forceFill(['last_test_at' => now(), 'last_test_ok' => $ok, 'last_test_message' => mb_substr($message, 0, 255)])->save();

        return ['ok' => $ok, 'message' => $message, 'count' => $count, 'tested_at' => now()->toIso8601String()];
    }
}
