<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\ChemicalStock;
use App\Models\Company;
use App\Models\CoilStock;
use App\Models\Invoice;
use App\Services\AppNotificationService;
use Illuminate\Console\Command;

/**
 * Raises (and clears) the notifications for standing conditions — low stock,
 * overdue invoices. Event-style notifications (stage awaiting approval …) are
 * written where the event happens; these have no single moment, so they are
 * evaluated on a schedule. Re-running is safe: each condition has a dedupe key.
 */
class ScanNotifications extends Command
{
    protected $signature = 'notifications:scan';

    protected $description = 'Refresh stock / overdue notifications and prune old read ones';

    public function handle(AppNotificationService $notify): int
    {
        foreach (Company::where('is_active', true)->get() as $company) {
            $this->scanCompany($notify, (int) $company->id);
        }

        $pruned = AppNotification::withoutGlobalScope('tenant')
            ->where('read_at', '<', now()->subDays(30))->delete();
        $this->info("Scan complete; pruned {$pruned} old read notification(s).");

        return 0;
    }

    private function scanCompany(AppNotificationService $notify, int $companyId): void
    {
        $items = [];
        $coils = CoilStock::where('company_id', $companyId)->with('panelType')
            ->whereColumn('quantity_in_stock', '<=', 'reorder_level')->get();
        foreach ($coils as $c) {
            $items["coil:{$c->id}"] = [
                'title' => 'Low coil stock',
                'body' => ($c->panelType?->name ?? 'Coil') . ": {$c->quantity_in_stock} kg left (reorder at {$c->reorder_level}).",
                'section' => 'stock',
            ];
        }
        $notify->syncStanding($companyId, 'stock.manage', 'low_coil', $items);

        $items = [];
        $chemicals = ChemicalStock::where('company_id', $companyId)
            ->whereColumn('quantity_in_stock', '<=', 'reorder_level')->get();
        foreach ($chemicals as $c) {
            $items["chem:{$c->id}"] = [
                'title' => 'Low chemical stock',
                'body' => "{$c->name}: {$c->quantity_in_stock} {$c->unit} left (reorder at {$c->reorder_level}).",
                'section' => 'stock',
            ];
        }
        $notify->syncStanding($companyId, 'stock.manage', 'low_chemical', $items);

        // One summary, refreshed in place; it becomes unread again when the count changes.
        $overdue = Invoice::where('company_id', $companyId)
            ->whereNotIn('status', ['draft', 'cancelled', 'paid'])
            ->where('due_date', '<', now()->startOfDay())->count();
        $notify->syncStanding($companyId, 'payments.view', 'overdue_invoices', $overdue > 0 ? [
            'overdue_invoices' => [
                'title' => "{$overdue} invoice" . ($overdue === 1 ? '' : 's') . ' overdue',
                'body' => 'Payment is past its due date. Open Receivables to follow up.',
                'section' => 'receivables',
                'severity' => 'danger',
            ],
        ] : []);

        // Lead follow-ups due or overdue (LEADS_PLAN 1.7). A problem here must not stop the other scans.
        try {
            app(\App\Services\LeadReminderService::class)->sync($companyId);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
