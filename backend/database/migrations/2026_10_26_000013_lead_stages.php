<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LEADS_PLAN 5.8 (audit LP-11): the pipeline stages are the company's own. `lead_stages` holds the OPEN stages a lead moves through (their name, order and win
 * probability); a lead's `status` stores the stage's `key`, which never changes after the stage is made, so renaming a stage never touches a lead. On hold, won, lost and
 * invalid are fixed and are not rows here. Each company gets the starter stages (new, contacted, qualified, quoted) the first time they are needed, which is exactly the
 * set every lead already uses. `new` (where a lead starts) and `quoted` (where a quotation puts it) are built in: they can be renamed and re-ordered, never removed.
 *
 * `lead_activities.from_status` / `to_status` record a status change as stage keys, so what a change meant survives a stage being renamed; filled in here for every old row.
 */
return new class extends Migration {
    private const KNOWN = ['new', 'contacted', 'qualified', 'quoted', 'on_hold', 'won', 'lost', 'invalid'];

    public function up(): void
    {
        if (!Schema::hasTable('lead_stages')) {
            Schema::create('lead_stages', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $t->string('key', 40);                              // what leads.status holds; never changes
                $t->string('name', 40);                             // what people see; editable
                $t->unsignedTinyInteger('probability')->default(0); // chance of winning, 0 to 100
                $t->unsignedSmallInteger('sort_order')->default(0);
                $t->string('role', 10)->nullable();                 // entry | quoted: built in, cannot be removed
                $t->timestamps();
                $t->softDeletes();
                $t->unique(['company_id', 'key']);
            });
        }

        Schema::table('lead_activities', function (Blueprint $t) {
            if (!Schema::hasColumn('lead_activities', 'from_status')) {
                $t->string('from_status', 40)->nullable()->after('type');
                $t->string('to_status', 40)->nullable()->after('from_status');
            }
        });

        // Old status changes read "Status: new → contacted (reopened: ...)": turn them into keys.
        DB::table('lead_activities')->where('type', 'status_change')->where('description', 'like', 'Status: % → %')->whereNull('from_status')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $r) {
                if (preg_match('/^Status: ([a-z ]+) → ([a-z ]+?)(?: \(|$)/u', $r->description, $m)) {
                    $from = str_replace(' ', '_', trim($m[1]));
                    $to = str_replace(' ', '_', trim($m[2]));
                    if (in_array($from, self::KNOWN, true) && in_array($to, self::KNOWN, true)) {
                        DB::table('lead_activities')->where('id', $r->id)->update(['from_status' => $from, 'to_status' => $to]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('lead_activities', fn (Blueprint $t) => $t->dropColumn(['from_status', 'to_status']));
        Schema::dropIfExists('lead_stages');
    }
};
