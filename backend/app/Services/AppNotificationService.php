<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Writes in-app notifications (the header bell).
 *
 * Callers sit inside business flows (stage approval, scheduled scans), so a
 * failure here must never surface to them: every public method swallows and
 * logs its own errors.
 */
class AppNotificationService
{
    /**
     * @param  string|int[]  $to  a permission key (everyone in the company who holds it)
     *                            or an explicit list of user ids
     */
    public function notify(
        int $companyId,
        string|array $to,
        string $type,
        string $title,
        ?string $body = null,
        ?string $section = null,
        ?int $linkId = null,
        string $severity = 'info',
        ?string $dedupeKey = null,
    ): void {
        try {
            foreach ($this->recipientIds($companyId, $to) as $userId) {
                $this->write($companyId, $userId, $type, $title, $body, $section, $linkId, $severity, $dedupeKey);
            }
        } catch (\Throwable $e) {
            Log::warning('app notification failed', ['type' => $type, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Make the set of standing notifications of one type match `$items`
     * (dedupe key => [title, body, section, link_id, severity]): add or refresh
     * the ones present, remove the ones whose condition has cleared.
     */
    public function syncStanding(int $companyId, string|array $to, string $type, array $items): void
    {
        try {
            foreach ($items as $key => $i) {
                $this->notify($companyId, $to, $type, $i['title'], $i['body'] ?? null,
                    $i['section'] ?? null, $i['link_id'] ?? null, $i['severity'] ?? 'warning', (string) $key);
            }
            AppNotification::withoutGlobalScope('tenant')
                ->where('company_id', $companyId)->where('type', $type)
                ->whereNotIn('dedupe_key', array_map('strval', array_keys($items)) ?: [''])
                ->delete();
        } catch (\Throwable $e) {
            Log::warning('app notification sync failed', ['type' => $type, 'error' => $e->getMessage()]);
        }
    }

    /** @return int[] */
    private function recipientIds(int $companyId, string|array $to): array
    {
        if (is_array($to)) {
            return User::withoutGlobalScope('tenant')
                ->where('company_id', $companyId)->where('is_active', true)
                ->whereIn('id', array_filter($to))->pluck('id')->all();
        }

        return User::withoutGlobalScope('tenant')->with('role')
            ->where('company_id', $companyId)->where('is_active', true)
            ->get()->filter(fn (User $u) => $u->hasPermission($to))->pluck('id')->all();
    }

    private function write(int $companyId, int $userId, string $type, string $title, ?string $body,
                           ?string $section, ?int $linkId, string $severity, ?string $dedupeKey): void
    {
        $attrs = ['company_id' => $companyId, 'type' => $type, 'severity' => $severity, 'title' => $title,
                  'body' => $body, 'link_section' => $section, 'link_id' => $linkId];

        if ($dedupeKey === null) {
            AppNotification::withoutGlobalScope('tenant')->create($attrs + ['user_id' => $userId]);
            return;
        }

        $n = AppNotification::withoutGlobalScope('tenant')
            ->firstOrNew(['user_id' => $userId, 'dedupe_key' => $dedupeKey]);
        // A changed message is news again; an identical one stays as the user left it.
        $changed = !$n->exists || $n->title !== $title || $n->body !== $body || $n->severity !== $severity;
        $n->fill($attrs);
        if ($changed) {
            $n->read_at = null;
            $n->created_at = now();
        }
        $n->save();
    }
}
