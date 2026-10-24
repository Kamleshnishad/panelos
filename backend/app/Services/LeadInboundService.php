<?php

namespace App\Services;

use App\Models\Company;
use App\Models\LeadImportLog;
use App\Models\LeadInboundToken;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The inbound lead address (LEADS_PLAN 2.3): POST /api/inbound/leads/{token}. Anything outside (a website form, a partner, Zapier) can create
 * a lead without a login. The token is the whole credential: long, random, only its hash stored, shown once, revocable. The company comes
 * from the token and nothing else in the request, so a token for one company can never touch another's data.
 */
class LeadInboundService
{
    public const MAX_BYTES = 65536;
    public const BAD_TOKENS_PER_MINUTE = 30;
    public const PREFIX = 'lt_';
    /** The hidden "leave this empty" field of the website form. A person never fills it; a bot does. */
    public const HONEYPOT = '_hp';

    /** Friendly names some forms use, mapped to the intake keys. The intake keys always win when both are sent. */
    private const ALIASES = ['name' => 'contact_name', 'mobile' => 'phone', 'message' => 'requirement', 'company' => 'company_name', 'id' => 'external_id'];

    public function __construct(private LeadIntakeService $intake, private LeadListService $lists)
    {
    }

    // ── tokens ───────────────────────────────────────────────────────────────

    /** @return array{0:LeadInboundToken,1:string} the token row and the plain token (this is the only time it exists in the clear) */
    public function createToken(int $companyId, string $name, string $source, ?int $userId = null): array
    {
        $source = $this->lists->resolveSource($companyId, $source);
        if (!$source) {
            throw ValidationException::withMessages(['source' => 'Choose one of your lead sources.']);
        }
        $plain = $this->newToken();
        $row = LeadInboundToken::create([
            'company_id' => $companyId, 'name' => trim($name), 'source' => $source, 'token_hash' => $this->hash($plain),
            'token_hint' => substr($plain, -4), 'is_active' => true, 'created_by_user_id' => $userId,
        ]);

        return [$row, $plain];
    }

    /** A new token replaces the old one at once: the old address stops working. @return array{0:LeadInboundToken,1:string} */
    public function regenerate(LeadInboundToken $token): array
    {
        $plain = $this->newToken();
        $token->update(['token_hash' => $this->hash($plain), 'token_hint' => substr($plain, -4), 'is_active' => true]);

        return [$token->fresh(), $plain];
    }

    private function newToken(): string
    {
        return self::PREFIX . Str::random(48);
    }

    private function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function findActive(string $plain): ?LeadInboundToken
    {
        if (!preg_match('/^' . self::PREFIX . '[A-Za-z0-9]{48}$/', $plain)) {
            return null;
        }
        $token = LeadInboundToken::withoutGlobalScope('tenant')->where('token_hash', $this->hash($plain))->where('is_active', true)->first();
        if (!$token || !Company::where('id', $token->company_id)->where('is_active', true)->exists()) {
            return null;
        }

        return $token;
    }

    // ── the request ──────────────────────────────────────────────────────────

    /**
     * @param array $input the decoded body (JSON or form)
     * @param int   $bytes the size of the body as received
     * @return array{status:int, body:array}
     */
    public function receive(string $plain, array $input, int $bytes, ?string $ip): array
    {
        $token = $this->findActive($plain);
        if (!$token) {
            // Guessing: after 30 wrong tokens a minute from one address the answer becomes 429. Real senders (valid tokens) never pass through here.
            $key = 'inbound-bad-token:' . $ip;
            if (RateLimiter::tooManyAttempts($key, self::BAD_TOKENS_PER_MINUTE)) {
                return $this->answer(429, false, 'RATE_LIMITED', 'Too many attempts. Wait a minute and try again.');
            }
            RateLimiter::hit($key, 60);

            return $this->answer(401, false, 'INVALID_TOKEN', 'This address is not valid. Ask for a new one.');
        }

        $log = fn (string $outcome, ?int $leadId = null, ?string $reason = null, ?string $ext = null) => $this->log($token, $outcome, $leadId, $reason, $ext, $ip);

        if ($bytes > self::MAX_BYTES) {
            $log('too_large', null, 'Message larger than ' . (self::MAX_BYTES / 1024) . ' KB.');

            return $this->answer(413, false, 'PAYLOAD_TOO_LARGE', 'The message is too large (limit ' . (self::MAX_BYTES / 1024) . ' KB).');
        }

        $ext = isset($input['external_id']) && is_scalar($input['external_id']) ? mb_substr((string) $input['external_id'], 0, 120) : null;

        if (trim((string) ($input[self::HONEYPOT] ?? '')) !== '') {
            $log('spam', null, 'Hidden field was filled in.', $ext);

            return $this->answer(200, true, null, 'Received.', ['outcome' => 'received']);   // looks like success to a bot
        }

        try {
            $result = $this->intake->ingest($token->company_id, $token->source, $this->mapped($input), $input);
        } catch (\Throwable $e) {
            report($e);
            $log('error', null, 'Could not be saved.', $ext);

            return $this->answer(500, false, 'SERVER_ERROR', 'We could not save this message. Please try again.');
        }

        $log($result->outcome, $result->leadId, $result->reason, $ext);
        if (!$token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->save();
        }

        return match ($result->outcome) {
            LeadIntakeResult::CREATED  => $this->answer(201, true, null, 'Lead created.', ['outcome' => 'created', 'lead_no' => $result->leadNo]),
            LeadIntakeResult::MERGED   => $this->answer(200, true, null, 'Added to the existing lead.', ['outcome' => 'merged', 'lead_no' => $result->leadNo]),
            LeadIntakeResult::ALREADY_RECEIVED => $this->answer(200, true, null, 'Already received.', ['outcome' => 'already_received']),
            default => $this->answer(422, false, 'REJECTED', $result->reason ?? 'The message could not be used.', ['outcome' => 'rejected']),
        };
    }

    private function mapped(array $in): array
    {
        $out = $in;
        foreach (self::ALIASES as $alias => $key) {
            if (!isset($out[$key]) && isset($in[$alias])) {
                $out[$key] = $in[$alias];
            }
        }

        return $out;
    }

    private function log(LeadInboundToken $t, string $outcome, ?int $leadId, ?string $reason, ?string $ext, ?string $ip): void
    {
        LeadImportLog::withoutGlobalScope('tenant')->create([
            'company_id' => $t->company_id, 'token_id' => $t->id, 'source' => $t->source, 'outcome' => $outcome, 'lead_id' => $leadId,
            'reason' => $reason ? mb_substr($reason, 0, 255) : null, 'external_id' => $ext, 'ip' => $ip, 'created_at' => now(),
        ]);
    }

    private function answer(int $status, bool $ok, ?string $code, string $message, array $data = []): array
    {
        return ['status' => $status, 'body' => array_filter([
            'success' => $ok, 'message' => $message, 'error_code' => $code, 'data' => $data ?: null,
        ], fn ($v) => $v !== null)];
    }

    /** Old log rows are of no use; the hourly scan calls this. */
    public function pruneLogs(int $days = 90): int
    {
        return LeadImportLog::withoutGlobalScope('tenant')->where('created_at', '<', now()->subDays($days))->delete();
    }
}
