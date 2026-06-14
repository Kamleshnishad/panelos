<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Carbon;

/**
 * Production Planning (Phase 1 — advisory / read-only).
 *
 * Continuous PUF/PIR line: switching between dissimilar panels costs setup time
 * + material waste (coil splice scrap, foam purge, profile/tooling change).
 * This service looks at every order that still needs production, computes a
 * "production signature" per panel row (the attributes that drive changeover
 * cost — length is excluded because it is free to change), groups identical
 * rows together, and recommends a run sequence that minimises changeovers.
 *
 * It also raises "take this job first" alerts when a pending order matches a
 * job that is already running/queued — so the planner doesn't re-set the line
 * for the same spec twice and waste material.
 */
class ProductionPlanningService
{
    /** Orders that are actively on the line right now. */
    private const RUNNING_STATUSES = ['in_production'];
    /** Orders accepted but not yet sent to the line — these are what we schedule. */
    private const PENDING_STATUSES = ['pending'];

    public function getPlan(int $companyId): array
    {
        // 1. Signatures of whatever is already running on the line.
        $runningSignatures = $this->collectRunningSignatures($companyId);

        // 2. All panel rows from orders waiting to be produced.
        $pendingOrders = Order::where('company_id', $companyId)
            ->whereIn('status', self::PENDING_STATUSES)
            ->with(['items.sizes', 'items.panelType', 'customer'])
            ->orderBy('expected_delivery_date')
            ->get();

        // 3. Group pending rows by production signature.
        $groups = [];
        foreach ($pendingOrders as $order) {
            foreach ($order->items as $item) {
                $sig = $this->signatureFor($item);

                if (!isset($groups[$sig])) {
                    $groups[$sig] = [
                        'signature'       => $sig,
                        'label'           => $this->labelFor($item),
                        'application'     => $item->application ?: $this->guessApplication($item),
                        'core_type'       => $item->density_type,
                        'thickness'       => (int) $item->thickness,
                        'total_sqm'       => 0.0,
                        'total_nos'       => 0,
                        'dl_count'        => 0,
                        'orders'          => [],
                        '_order_ids'      => [],
                        'earliest_due'    => null,
                        'matches_running' => in_array($sig, $runningSignatures, true),
                    ];
                }

                $sqm = (float) $item->total_sqm;
                $nos = (int) $item->sizes->sum('nos');
                $dl  = (int) $item->sizes->where('length_mm', '<', 2000)->count();

                $groups[$sig]['total_sqm'] += $sqm;
                $groups[$sig]['total_nos'] += $nos;
                $groups[$sig]['dl_count']  += $dl;

                if (!in_array($order->id, $groups[$sig]['_order_ids'], true)) {
                    $groups[$sig]['_order_ids'][] = $order->id;
                    $groups[$sig]['orders'][] = [
                        'order_id'      => $order->id,
                        'order_no'      => $order->order_no,
                        'customer_name' => $order->customer->name ?? '—',
                        'due_date'      => optional($order->expected_delivery_date)->toDateString(),
                        'sqm'           => round($sqm, 2),
                        'nos'           => $nos,
                    ];
                } else {
                    // same order contributes another row of the same spec
                    foreach ($groups[$sig]['orders'] as &$o) {
                        if ($o['order_id'] === $order->id) {
                            $o['sqm'] = round($o['sqm'] + $sqm, 2);
                            $o['nos'] += $nos;
                            break;
                        }
                    }
                    unset($o);
                }

                $due = $order->expected_delivery_date;
                if ($due && (!$groups[$sig]['earliest_due'] || $due->lt(Carbon::parse($groups[$sig]['earliest_due'])))) {
                    $groups[$sig]['earliest_due'] = $due->toDateString();
                }
            }
        }

        $today = Carbon::today();

        // 4. Finalise each group (urgency flags, round totals).
        $groups = array_values(array_map(function ($g) use ($today) {
            unset($g['_order_ids']);
            $g['total_sqm']   = round($g['total_sqm'], 2);
            $g['order_count'] = count($g['orders']);

            $g['is_overdue'] = false;
            $g['due_soon']   = false;
            $g['days_to_due'] = null;
            if ($g['earliest_due']) {
                $days = $today->diffInDays(Carbon::parse($g['earliest_due']), false);
                $g['days_to_due'] = $days;
                $g['is_overdue']  = $days < 0;
                $g['due_soon']    = $days >= 0 && $days <= 3;
            }
            return $g;
        }, $groups));

        // 5. Recommended sequence — minimise changeovers while honouring urgency:
        //    a) groups matching a job already on the line first (add to current run),
        //    b) then overdue, c) then soonest due, d) then biggest run.
        usort($groups, function ($a, $b) {
            if ($a['matches_running'] !== $b['matches_running']) {
                return $a['matches_running'] ? -1 : 1;
            }
            if ($a['is_overdue'] !== $b['is_overdue']) {
                return $a['is_overdue'] ? -1 : 1;
            }
            $ad = $a['earliest_due'] ?? '9999-12-31';
            $bd = $b['earliest_due'] ?? '9999-12-31';
            if ($ad !== $bd) return strcmp($ad, $bd);
            return $b['total_sqm'] <=> $a['total_sqm'];
        });

        foreach ($groups as $i => &$g) {
            $g['run_order'] = $i + 1;
        }
        unset($g);

        // 6. Build "take this first" alerts.
        $alerts = $this->buildAlerts($groups);

        return [
            'generated_at' => now()->toDateTimeString(),
            'alerts'       => $alerts,
            'groups'       => $groups,
            'summary'      => [
                'pending_orders'  => $pendingOrders->count(),
                'run_groups'      => count($groups),
                'total_sqm'       => round(array_sum(array_column($groups, 'total_sqm')), 2),
                'alert_count'     => count($alerts),
                'changeovers'     => max(0, count($groups) - 1),
            ],
        ];
    }

    /** Signatures of order rows currently on the line (status in_production). */
    private function collectRunningSignatures(int $companyId): array
    {
        $orders = Order::where('company_id', $companyId)
            ->whereIn('status', self::RUNNING_STATUSES)
            ->with('items')
            ->get();

        $sigs = [];
        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $sigs[$this->signatureFor($item)] = true;
            }
        }
        return array_keys($sigs);
    }

    private function buildAlerts(array $groups): array
    {
        $alerts = [];
        foreach ($groups as $g) {
            $orderNos = implode(', ', array_map(fn($o) => $o['order_no'], $g['orders']));

            if ($g['matches_running']) {
                $alerts[] = [
                    'type'     => 'running_match',
                    'severity' => 'high',
                    'title'    => 'Yeh job abhi line par chal rahe spec se match karta hai',
                    'message'  => "{$g['label']} — Order(s) {$orderNos}. Ise abhi line mein add karo; alag se dobara setup + material waste bachega.",
                    'signature'=> $g['signature'],
                    'order_count' => $g['order_count'],
                    'total_sqm'   => $g['total_sqm'],
                ];
            } elseif ($g['order_count'] > 1) {
                $alerts[] = [
                    'type'     => 'merge_pending',
                    'severity' => 'medium',
                    'title'    => 'Same panel — ek saath produce karo',
                    'message'  => "{$g['order_count']} orders ({$orderNos}) ka panel bilkul same hai ({$g['label']}). Ek hi run mein chalao — ek changeover bachega.",
                    'signature'=> $g['signature'],
                    'order_count' => $g['order_count'],
                    'total_sqm'   => $g['total_sqm'],
                ];
            } elseif ($g['is_overdue']) {
                $alerts[] = [
                    'type'     => 'overdue',
                    'severity' => 'high',
                    'title'    => 'Delivery date nikal gayi',
                    'message'  => "Order {$orderNos} ({$g['label']}) ki delivery date past ho chuki hai. Ise priority pe lo.",
                    'signature'=> $g['signature'],
                    'order_count' => $g['order_count'],
                    'total_sqm'   => $g['total_sqm'],
                ];
            }
        }
        return $alerts;
    }

    /**
     * Production signature = everything that forces a line changeover.
     * Length/Nos are intentionally excluded (free to change, no waste).
     */
    private function signatureFor($item): string
    {
        return implode('|', [
            strtoupper($item->density_type ?? 'PUF'),
            strtoupper($item->application ?: $this->guessApplication($item)),
            (int) $item->thickness,
            (string) $item->density_kgm3,
            strtoupper($item->top_skin_material ?? ''),
            (string) $item->top_skin_thickness,
            strtoupper($item->top_color ?? ''),
            strtoupper($item->top_color_ral ?? ''),
            strtoupper($item->top_surface ?? ''),
            strtoupper($item->bottom_skin_material ?? ''),
            (string) $item->bottom_skin_thickness,
            strtoupper($item->bottom_color ?? ''),
            strtoupper($item->bottom_color_ral ?? ''),
            strtoupper($item->bottom_surface ?? ''),
        ]);
    }

    private function labelFor($item): string
    {
        $app  = $item->application ?: $this->guessApplication($item);
        $core = $item->density_type ?? 'PUF';
        $topRal = $item->top_color_ral ? " (RAL {$item->top_color_ral})" : '';

        $sameBottom = ($item->bottom_skin_material === $item->top_skin_material)
            && ((string) $item->bottom_skin_thickness === (string) $item->top_skin_thickness)
            && ($item->bottom_color === $item->top_color)
            && (($item->bottom_color_ral ?? '') === ($item->top_color_ral ?? ''))
            && (($item->bottom_surface ?? 'PLAIN') === ($item->top_surface ?? 'PLAIN'));

        $bottom = $sameBottom
            ? 'Bottom: same'
            : "Bottom {$item->bottom_skin_thickness}mm {$item->bottom_skin_material} {$item->bottom_color} ({$item->bottom_surface})";

        return strtoupper($app) . " · {$item->thickness}mm · {$core} {$item->density_kgm3}kg"
            . " · Top {$item->top_skin_thickness}mm {$item->top_skin_material} {$item->top_color}{$topRal} ({$item->top_surface})"
            . " · {$bottom}";
    }

    /** Fallback when older rows have no explicit application. */
    private function guessApplication($item): string
    {
        if (($item->top_surface ?? 'PLAIN') === 'RIBBED') return 'Roof';
        return 'Wall';
    }
}
