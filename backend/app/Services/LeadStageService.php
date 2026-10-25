<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\LeadStage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Editing a company's lead stages (LEADS_PLAN 5.8, Master Data -> Lead stages). See LeadStages for what a stage is.
 *
 *  - Add: a name and a chance of winning; it goes to the end of the pipeline. Its `key` is made from the name once and never changes (a rename never touches a lead).
 *  - Change: the name and the probability of any stage, including the two built-in ones (new, quoted). Re-order: any order.
 *  - Remove: only a stage nobody is in (move those leads first), and never one of the two built-in ones (the system puts a new lead in `new` and a quotation puts it in `quoted`).
 *  - Names are unique within the company and cannot be the fixed ones (On hold, Won, Lost, Invalid). At most 12 stages.
 */
class LeadStageService
{
    /** @return list<array<string,mixed>> */
    public function list(int $companyId): array
    {
        LeadStages::for($companyId);                                   // makes the starters when the company has none yet
        $counts = Lead::withoutGlobalScope('tenant')->where('company_id', $companyId)->groupBy('status')->selectRaw('status, count(*) as n')->pluck('n', 'status');

        return LeadStage::where('company_id', $companyId)->orderBy('sort_order')->orderBy('id')->get()->map(fn (LeadStage $s) => [
            'id' => $s->id, 'key' => $s->key, 'name' => $s->name, 'probability' => $s->probability, 'built_in' => $s->role !== null, 'role' => $s->role, 'leads' => (int) ($counts[$s->key] ?? 0),
        ])->all();
    }

    public function create(int $companyId, string $name, $probability): LeadStage
    {
        LeadStages::for($companyId);
        $name = $this->cleanName($companyId, $name);
        $probability = $this->cleanProbability($probability);
        if (LeadStage::where('company_id', $companyId)->count() >= LeadStages::MAX) {
            $this->refuse('name', 'A pipeline can have up to ' . LeadStages::MAX . ' stages.');
        }
        $stage = LeadStage::create([
            'company_id' => $companyId, 'key' => $this->newKey($companyId, $name), 'name' => $name, 'probability' => $probability,
            'sort_order' => (int) LeadStage::where('company_id', $companyId)->max('sort_order') + 1, 'role' => null,
        ]);
        LeadStages::forget($companyId);

        return $stage;
    }

    public function update(LeadStage $stage, array $d): LeadStage
    {
        if (array_key_exists('name', $d)) {
            $stage->name = $this->cleanName((int) $stage->company_id, (string) $d['name'], $stage->id);
        }
        if (array_key_exists('probability', $d)) {
            $stage->probability = $this->cleanProbability($d['probability']);
        }
        $stage->save();
        LeadStages::forget((int) $stage->company_id);

        return $stage;
    }

    /** @param list<int> $ids every stage of the company, in the order wanted */
    public function reorder(int $companyId, array $ids): void
    {
        $have = LeadStage::where('company_id', $companyId)->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();
        $want = collect($ids)->map(fn ($i) => (int) $i)->values();
        if ($want->count() !== count($have) || $want->sort()->values()->all() !== $have) {
            $this->refuse('ids', 'The order must list every stage once.');
        }
        DB::transaction(function () use ($want, $companyId) {
            foreach ($want as $i => $id) {
                LeadStage::where('company_id', $companyId)->where('id', $id)->update(['sort_order' => $i + 1]);
            }
        });
        LeadStages::forget($companyId);
    }

    public function delete(LeadStage $stage): void
    {
        if ($stage->role !== null) {
            $why = $stage->role === 'entry' ? 'is where every new lead starts' : 'is where a quotation puts a lead';
            $this->refuse('stage', "\"{$stage->name}\" is built in and cannot be removed: it {$why}. You can rename it.");
        }
        $n = Lead::withoutGlobalScope('tenant')->where('company_id', $stage->company_id)->where('status', $stage->key)->count();
        if ($n > 0) {
            $this->refuse('stage', "{$n} lead" . ($n === 1 ? ' is' : 's are') . " in \"{$stage->name}\". Move " . ($n === 1 ? 'it' : 'them') . ' to another stage first (select them on the Leads screen and use Move to).');
        }
        $stage->delete();
        LeadStages::forget((int) $stage->company_id);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function cleanName(int $companyId, string $name, ?int $exceptId = null): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 40) {
            $this->refuse('name', 'Give the stage a name of up to 40 characters.');
        }
        $taken = LeadStage::where('company_id', $companyId)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->pluck('name')->merge(array_values(LeadStages::FIXED));
        if ($taken->contains(fn ($n) => mb_strtolower($n) === mb_strtolower($name))) {
            $this->refuse('name', "There is already a stage called \"{$name}\".");
        }

        return $name;
    }

    private function cleanProbability($p): int
    {
        if (!is_numeric($p) || $p < 0 || $p > 100 || (int) $p != $p) {
            $this->refuse('probability', 'The chance of winning is a whole number from 0 to 100.');
        }

        return (int) $p;
    }

    /** A key made from the name, unique in the company even among removed stages, and never one of the fixed statuses. */
    private function newKey(int $companyId, string $name): string
    {
        $base = substr(Str::slug($name, '_'), 0, 30) ?: 'stage';
        $used = LeadStage::withoutGlobalScope('tenant')->withTrashed()->where('company_id', $companyId)->pluck('key')->merge(array_keys(LeadStages::FIXED))->all();
        $key = $base;
        for ($i = 2; in_array($key, $used, true); $i++) {
            $key = $base . '_' . $i;
        }

        return $key;
    }

    private function refuse(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
