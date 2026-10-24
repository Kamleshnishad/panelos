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
                'company_id'     => $this->auditCompanyId(),
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
        // A model can add to the exclusions with `protected array $auditAlsoExclude` (a trait cannot be redeclared).
        $skip = array_merge($this->auditExclude, property_exists($this, 'auditAlsoExclude') ? $this->auditAlsoExclude : []);
        foreach ($skip as $f) unset($attrs[$f]);
        return $attrs;
    }

    /** The tenant a change belongs to. A Company row is its own tenant, so a super admin editing it is logged against it. */
    protected function auditCompanyId(): ?int
    {
        if ($this instanceof \App\Models\Company) {
            return $this->getKey();
        }
        return $this->company_id ?? (auth()->user()->company_id ?? null);
    }

    protected function auditLabel(): string
    {
        foreach (['quotation_no', 'order_no', 'invoice_no', 'lead_no', 'po_no', 'run_no', 'batch_no', 'dispatch_no', 'reference_no', 'name', 'email'] as $f) {
            if (!empty($this->{$f})) return (string) $this->{$f};
        }
        return '#' . $this->getKey();
    }
}
