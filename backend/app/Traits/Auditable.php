<?php

namespace App\Traits;

use App\Models\AuditLog;

/**
 * Add `use Auditable;` to a model to record create/update/delete/restore into
 * audit_logs. Writes never break the main operation (wrapped in try/catch).
 * Sensitive fields are stripped from the snapshot.
 */
trait Auditable
{
    /** Fields never stored in the audit snapshot. */
    protected array $auditExclude = ['updated_at', 'created_at', 'password', 'remember_token'];

    public static function bootAuditable(): void
    {
        static::created(function ($m) {
            $m->writeAudit('created', null, $m->cleanAudit($m->getAttributes()));
        });

        static::updated(function ($m) {
            $changes = $m->cleanAudit($m->getChanges());
            if (empty($changes)) return;
            $before = array_intersect_key($m->getOriginal(), $changes);
            $m->writeAudit('updated', $m->cleanAudit($before), $changes);
        });

        static::deleted(function ($m) {
            $soft = method_exists($m, 'isForceDeleting') && !$m->isForceDeleting();
            $m->writeAudit($soft ? 'deleted' : 'force_deleted', $m->cleanAudit($m->getOriginal()), null);
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function ($m) {
                $m->writeAudit('restored', null, $m->cleanAudit($m->getAttributes()));
            });
        }
    }

    public function writeAudit(string $action, ?array $before, ?array $after): void
    {
        try {
            $user = auth()->user();
            AuditLog::create([
                'company_id'     => $this->company_id ?? ($user->company_id ?? null),
                'user_id'        => $user?->id,
                'user_name'      => $user?->name ?? 'system',
                'action'         => $action,
                'auditable_type' => class_basename($this),
                'auditable_id'   => $this->getKey(),
                'label'          => $this->auditLabel(),
                'before'         => $before ?: null,
                'after'          => $after ?: null,
                'ip'             => request()?->ip(),
                'created_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            // never let auditing break the actual business operation
        }
    }

    protected function cleanAudit(array $attrs): array
    {
        foreach ($this->auditExclude as $f) unset($attrs[$f]);
        return $attrs;
    }

    protected function auditLabel(): string
    {
        foreach (['quotation_no', 'order_no', 'invoice_no', 'lead_no', 'po_no', 'run_no', 'batch_no', 'dispatch_no', 'name'] as $f) {
            if (!empty($this->{$f})) return (string) $this->{$f};
        }
        return '#' . $this->getKey();
    }
}
