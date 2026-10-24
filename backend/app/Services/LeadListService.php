<?php

namespace App\Services;

use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The lists a company edits for its leads: where they come from (sources) and why they are lost (lost reasons).
 * Leads store the source and the reason by NAME (so every report and old row keeps working); renaming a source rewrites
 * the name on that company's leads in one step. A list entry that leads still use is switched off, not deleted.
 */
class LeadListService
{
    public const DEFAULT_SOURCES = ['Website', 'Phone', 'WhatsApp', 'Referral', 'IndiaMART', 'Justdial', 'Exhibition', 'Walk-in', 'TradeIndia', 'ExportersIndia',
        'Facebook', 'Instagram', 'Google Ads', 'E-mail', 'Other'];
    public const DEFAULT_REASONS = ['Price too high', 'Chose a competitor', 'No budget or project dropped', 'Timing, not now', 'Not a genuine enquiry', 'No response', 'Other'];

    /** Tables by list kind. */
    private const TABLE = ['source' => 'lead_sources', 'reason' => 'lead_lost_reasons'];

    /** Seed the starter lists the first time a company needs them (existing companies were seeded by the migration). */
    public function ensureDefaults(int $companyId): void
    {
        $this->seed('source', $companyId, self::DEFAULT_SOURCES);
        $this->seed('reason', $companyId, self::DEFAULT_REASONS);
    }

    private function seed(string $kind, int $companyId, array $names): void
    {
        $table = self::TABLE[$kind];
        if (DB::table($table)->where('company_id', $companyId)->exists()) {
            return;
        }
        $now = now();
        foreach ($names as $i => $name) {
            DB::table($table)->insertOrIgnore(['company_id' => $companyId, 'name' => $name, 'is_system' => $name === 'Other', 'is_active' => true, 'sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    /** @return list<array{id:int,name:string,is_system:bool,is_active:bool,sort_order:int,used:int}> */
    public function all(string $kind, int $companyId): array
    {
        $this->ensureDefaults($companyId);
        $rows = DB::table(self::TABLE[$kind])->where('company_id', $companyId)->orderBy('sort_order')->orderBy('id')->get();
        $usage = $this->usage($kind, $companyId);

        return $rows->map(fn ($r) => [
            'id' => (int) $r->id, 'name' => $r->name, 'is_system' => (bool) $r->is_system, 'is_active' => (bool) $r->is_active,
            'sort_order' => (int) $r->sort_order, 'used' => (int) ($usage[$r->name] ?? 0),
        ])->all();
    }

    /** @return list<string> */
    public function activeNames(string $kind, int $companyId): array
    {
        $this->ensureDefaults($companyId);

        return DB::table(self::TABLE[$kind])->where('company_id', $companyId)->where('is_active', true)
            ->orderBy('sort_order')->orderBy('id')->pluck('name')->all();
    }

    /** The company's spelling of a source typed in any case, or null when it is not an active source. */
    public function resolveSource(int $companyId, ?string $input): ?string
    {
        if ($input === null || trim($input) === '') {
            return null;
        }
        foreach ($this->activeNames('source', $companyId) as $name) {
            if (strcasecmp($name, trim($input)) === 0) {
                return $name;
            }
        }

        return null;
    }

    /** @return array{id:int,name:string}|null an active lost reason of the company */
    public function activeReason(int $companyId, int $id): ?array
    {
        $this->ensureDefaults($companyId);
        $r = DB::table('lead_lost_reasons')->where('company_id', $companyId)->where('id', $id)->where('is_active', true)->first();

        return $r ? ['id' => (int) $r->id, 'name' => $r->name] : null;
    }

    public function create(string $kind, int $companyId, string $name): int
    {
        $this->ensureDefaults($companyId);
        $name = $this->cleanName($kind, $companyId, $name);
        $next = (int) DB::table(self::TABLE[$kind])->where('company_id', $companyId)->max('sort_order') + 1;

        return DB::table(self::TABLE[$kind])->insertGetId(['company_id' => $companyId, 'name' => $name, 'is_system' => false, 'is_active' => true, 'sort_order' => $next, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function update(string $kind, int $companyId, int $id, array $data): void
    {
        $row = $this->find($kind, $companyId, $id);
        $changes = ['updated_at' => now()];

        if (array_key_exists('name', $data) && trim((string) $data['name']) !== $row->name) {
            if ($row->is_system) {
                $this->fail('name', "\"{$row->name}\" is built in and cannot be renamed.");
            }
            $changes['name'] = $this->cleanName($kind, $companyId, (string) $data['name'], $id);
        }
        if (array_key_exists('is_active', $data) && (bool) $data['is_active'] !== (bool) $row->is_active) {
            if ($row->is_system && !$data['is_active']) {
                $this->fail('is_active', "\"{$row->name}\" is the fallback and cannot be switched off.");
            }
            $changes['is_active'] = (bool) $data['is_active'];
        }
        if (array_key_exists('sort_order', $data)) {
            $changes['sort_order'] = max(0, (int) $data['sort_order']);
        }

        DB::transaction(function () use ($kind, $companyId, $id, $row, $changes) {
            DB::table(self::TABLE[$kind])->where('id', $id)->update($changes);
            if (isset($changes['name'])) {
                // Leads hold the name, so a rename follows them (soft-deleted ones too). A plain update: no audit row per lead.
                if ($kind === 'source') {
                    DB::table('leads')->where('company_id', $companyId)->where('source', $row->name)->update(['source' => $changes['name']]);
                } else {
                    DB::table('leads')->where('company_id', $companyId)->where('lost_reason_id', $id)->update(['lost_reason' => $changes['name']]);
                }
            }
        });
    }

    public function delete(string $kind, int $companyId, int $id): void
    {
        $row = $this->find($kind, $companyId, $id);
        if ($row->is_system) {
            $this->fail('id', "\"{$row->name}\" is built in and cannot be removed.");
        }
        $used = (int) ($this->usage($kind, $companyId)[$row->name] ?? 0);
        if ($used > 0) {
            $this->fail('id', "{$used} lead" . ($used === 1 ? ' uses' : 's use') . " \"{$row->name}\". Switch it off instead: it stays on those leads but cannot be chosen for new ones.");
        }
        DB::table(self::TABLE[$kind])->where('id', $id)->delete();
    }

    /** @return array<string,int> leads (including soft-deleted) per list name */
    private function usage(string $kind, int $companyId): array
    {
        $q = Lead::withTrashed()->withoutGlobalScopes()->where('company_id', $companyId);

        return $kind === 'source'
            ? $q->selectRaw('source as name, count(*) as c')->groupBy('source')->pluck('c', 'name')->map(fn ($c) => (int) $c)->all()
            : $q->whereNotNull('lost_reason')->selectRaw('lost_reason as name, count(*) as c')->groupBy('lost_reason')->pluck('c', 'name')->map(fn ($c) => (int) $c)->all();
    }

    private function find(string $kind, int $companyId, int $id): object
    {
        $row = DB::table(self::TABLE[$kind])->where('company_id', $companyId)->where('id', $id)->first();
        if (!$row) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(self::TABLE[$kind]);
        }

        return $row;
    }

    private function cleanName(string $kind, int $companyId, string $name, ?int $exceptId = null): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name));
        $max = $kind === 'source' ? 60 : 100;
        if ($name === '' || mb_strlen($name) > $max) {
            $this->fail('name', "Enter a name of up to {$max} characters.");
        }
        $clash = DB::table(self::TABLE[$kind])->where('company_id', $companyId)->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists();
        if ($clash) {
            $this->fail('name', "\"{$name}\" is already in the list.");
        }

        return $name;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
