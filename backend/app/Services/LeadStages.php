<?php

namespace App\Services;

use App\Models\LeadStage;
use Illuminate\Support\Facades\DB;

/**
 * A company's lead pipeline as the rest of the code asks about it (LEADS_PLAN 5.8): which statuses are OPEN stages and in what order, what each is called, the chance
 * of winning at each, and which moves between statuses are allowed.
 *
 *  - The stages are the company's own rows (`lead_stages`; edit them in Master Data -> Lead stages). A lead's `status` holds a stage's `key`, which never changes, so a
 *    rename touches no lead and no history.
 *  - On hold, won, lost and invalid are FIXED: they are not stages, they mean the same in every company.
 *  - Two stages are built in and keep their keys for ever: `new` (where a lead starts) and `quoted` (where a quotation puts it). Code may name those two; it must never
 *    name any other stage, and must ask here for the list of open stages.
 *  - A company is given the starter stages (new, contacted, qualified, quoted) the first time they are asked for; that is the set leads used before stages could be edited.
 * Read once per request and company; changing a stage calls forget().
 */
final class LeadStages
{
    public const ENTRY = 'new';
    public const QUOTED = 'quoted';
    public const FIXED = ['on_hold' => 'On hold', 'won' => 'Won', 'lost' => 'Lost', 'invalid' => 'Invalid'];
    public const CLOSED = ['won', 'lost', 'invalid'];
    public const MAX = 12;
    public const STARTERS = [
        ['key' => 'new', 'name' => 'New', 'probability' => 10, 'role' => 'entry'],
        ['key' => 'contacted', 'name' => 'Contacted', 'probability' => 25, 'role' => null],
        ['key' => 'qualified', 'name' => 'Qualified', 'probability' => 50, 'role' => null],
        ['key' => 'quoted', 'name' => 'Quoted', 'probability' => 75, 'role' => 'quoted'],
    ];

    /** @var array<int, self> */
    private static array $cache = [];

    /** @param list<array{id:int,key:string,name:string,probability:int,sort_order:int,role:?string}> $rows in order */
    private function __construct(public readonly array $rows)
    {
    }

    public static function for(int $companyId): self
    {
        return self::$cache[$companyId] ??= new self(self::load($companyId));
    }

    public static function forget(?int $companyId = null): void
    {
        if ($companyId === null) {
            self::$cache = [];
        } else {
            unset(self::$cache[$companyId]);
        }
    }

    /** @return list<array<string,mixed>> */
    private static function load(int $companyId): array
    {
        $read = fn () => LeadStage::withoutGlobalScope('tenant')->where('company_id', $companyId)->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'key', 'name', 'probability', 'sort_order', 'role'])->map(fn ($s) => $s->only(['id', 'key', 'name', 'probability', 'sort_order', 'role']))->all();
        $rows = $read();
        if (!$rows && !LeadStage::withoutGlobalScope('tenant')->withTrashed()->where('company_id', $companyId)->exists()) {
            $now = now();
            foreach (self::STARTERS as $i => $s) {
                DB::table('lead_stages')->insertOrIgnore($s + ['company_id' => $companyId, 'sort_order' => $i + 1, 'created_at' => $now, 'updated_at' => $now]);
            }
            $rows = $read();
        }

        return $rows;
    }

    /** @return list<string> the keys of the open stages, in pipeline order */
    public function open(): array
    {
        return array_column($this->rows, 'key');
    }

    /** @return list<string> every status a lead may have: the open stages, then on hold, won, lost, invalid */
    public function statuses(): array
    {
        return [...$this->open(), ...array_keys(self::FIXED)];
    }

    public function isOpen(?string $status): bool
    {
        return $status !== null && in_array($status, $this->open(), true);
    }

    public function has(?string $status): bool
    {
        return $status !== null && in_array($status, $this->statuses(), true);
    }

    /** What people are shown. */
    public function name(string $key): string
    {
        foreach ($this->rows as $r) {
            if ($r['key'] === $key) {
                return $r['name'];
            }
        }

        return self::FIXED[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    /** The same in lower case, for a sentence on the timeline ("Status: new → contacted"). */
    public function label(string $key): string
    {
        return mb_strtolower($this->name($key));
    }

    /** Chance of winning, 0 to 100, for an open stage; won is 100, lost and invalid 0; on hold has none. */
    public function probability(string $key): ?int
    {
        foreach ($this->rows as $r) {
            if ($r['key'] === $key) {
                return (int) $r['probability'];
            }
        }

        return match ($key) { 'won' => 100, 'lost', 'invalid' => 0, default => null };
    }

    /** Position among the open stages (0 first), or -1 for any other status. */
    public function rank(string $key): int
    {
        $i = array_search($key, $this->open(), true);

        return $i === false ? -1 : (int) $i;
    }

    /**
     * The one table of allowed moves (LEADS_PLAN 1.2), now over this company's stages:
     *  - an open lead can move to any other open stage, or be put on hold, won, lost or invalid;
     *  - a lead on hold can go back to any open stage, or be won, lost or invalid;
     *  - a closed lead can only be reopened to an open stage. Won to lost (or the other way) goes through a reopen.
     * @return list<string>
     */
    public function allowedNext(string $from): array
    {
        $open = $this->open();
        if (in_array($from, $open, true)) {
            return array_values(array_diff([...$open, 'on_hold', 'won', 'lost', 'invalid'], [$from]));
        }
        if ($from === 'on_hold') {
            return [...$open, 'won', 'lost', 'invalid'];
        }

        return $open;
    }

    /** @return array<string,list<string>> every status with the statuses it may move to, for the screen */
    public function transitions(): array
    {
        $map = [];
        foreach ($this->statuses() as $s) {
            $map[$s] = $this->allowedNext($s);
        }

        return $map;
    }
}
