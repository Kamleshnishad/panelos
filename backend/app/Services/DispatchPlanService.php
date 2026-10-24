<?php

namespace App\Services;

use App\Models\DispatchItem;
use App\Models\OrderItem;
use App\Models\ProductionBatch;

/**
 * What is left to send for a batch, and which pieces a new dispatch takes (FT-03).
 *
 * Counting is by pieces (nos) of each size row, because that is what goes on a truck. What is "already sent" is the nos on
 * LIVE dispatches (pending, in transit or delivered); cancelling a dispatch gives its pieces back. Money follows the
 * pieces from the order's own stored size amounts, and the dispatch that takes the last pieces of an order line is
 * given the remainder, so the dispatches of a line always add up to the order line to the paisa (rates are weighted
 * averages, recomputing quantity x rate would drift).
 *
 * A dispatch made before this feature has items without an order_item_id and covered the whole batch: it counts as
 * everything sent. A batch whose order has no lines at all keeps the old rule of one dispatch.
 */
class DispatchPlanService
{
    /** @return array{batch_id:int,batch_no:?string,order_no:?string,legacy:bool,fully_dispatched:bool,ordered_sqm:float,dispatched_sqm:float,remaining_sqm:float,items:list<array>} */
    public function plan(ProductionBatch $batch): array
    {
        $orderItems = OrderItem::where('order_id', $batch->order_id)->with(['sizes', 'panelType'])->orderBy('sort_order')->orderBy('id')->get();

        $live = DispatchItem::whereHas('dispatch', fn ($q) => $q->where('batch_id', $batch->id)->where('status', '!=', 'cancelled'))
            ->with('sizes')->get();
        $wholeBatchSent = $live->contains(fn ($i) => $i->order_item_id === null);
        $noLines = $orderItems->isEmpty();

        $nosSent = $live->flatMap->sizes->groupBy('order_item_size_id')->map(fn ($g) => (int) $g->sum('nos'));

        $items = [];
        $orderedSqm = $dispatchedSqm = 0.0;
        foreach ($orderItems as $oi) {
            $sizes = [];
            foreach ($oi->sizes as $s) {
                $sent = $wholeBatchSent ? (int) $s->nos : min((int) $s->nos, (int) ($nosSent[$s->id] ?? 0));
                $remaining = (int) $s->nos - $sent;
                $sizes[] = [
                    'order_item_size_id' => $s->id,
                    'length_mm'      => (int) $s->length_mm,
                    'width_mm'       => (int) $s->width_mm,
                    'ordered_nos'    => (int) $s->nos,
                    'dispatched_nos' => $sent,
                    'remaining_nos'  => $remaining,
                    'sqm_each'       => $s->nos ? round((float) $s->sqm / $s->nos, 4) : 0.0,
                    'remaining_sqm'  => $remaining === (int) $s->nos ? (float) $s->sqm : ($s->nos ? round((float) $s->sqm * $remaining / $s->nos, 4) : 0.0),
                ];
            }

            $ordered = (float) ($oi->total_sqm ?: collect($sizes)->sum(fn ($x) => $x['ordered_nos'] * $x['sqm_each']));
            $remainingSqm = $sizes
                ? round((float) collect($sizes)->sum('remaining_sqm'), 4)
                : (($wholeBatchSent || $live->contains('order_item_id', $oi->id)) ? 0.0 : $ordered);

            $items[] = [
                'order_item_id' => $oi->id,
                'panel_type'    => $oi->panelType?->name,
                'application'   => $oi->application,
                'thickness'     => $oi->thickness,
                'ordered_sqm'   => round($ordered, 4),
                'dispatched_sqm' => round($ordered - $remainingSqm, 4),
                'remaining_sqm' => $remainingSqm,
                'whole_line'    => !$sizes,       // an order line with no size rows can only be sent in one go
                'sizes'         => $sizes,
            ];
            $orderedSqm += $ordered;
            $dispatchedSqm += $ordered - $remainingSqm;
        }

        $remainingNothing = $noLines ? $this->anyLiveDispatch($batch) : collect($items)->every(fn ($i) => $i['remaining_sqm'] <= 0 && collect($i['sizes'])->every(fn ($s) => $s['remaining_nos'] === 0));

        return [
            'batch_id'         => $batch->id,
            'batch_no'         => $batch->batch_no,
            'order_no'         => $batch->order?->order_no,
            'legacy'           => $wholeBatchSent,
            'fully_dispatched' => $remainingNothing,
            'ordered_sqm'      => round($orderedSqm, 4),
            'dispatched_sqm'   => round($dispatchedSqm, 4),
            'remaining_sqm'    => round($orderedSqm - $dispatchedSqm, 4),
            'items'            => $items,
        ];
    }

    private function anyLiveDispatch(ProductionBatch $batch): bool
    {
        return $batch->dispatches()->where('status', '!=', 'cancelled')->exists();
    }

    /**
     * The order lines and size rows a new dispatch takes, validated, with their money.
     *
     * @param  array|null $requested  [{order_item_size_id, nos} | {order_item_id}] — null or empty means everything still to send
     * @return list<array{item:OrderItem,sizes:list<array>,quantity:float,amount:float}>
     */
    public function lines(ProductionBatch $batch, ?array $requested): array
    {
        $plan = $this->plan($batch);

        if ($plan['fully_dispatched']) {
            throw new \Exception('Batch has already been dispatched');
        }
        if (!$plan['items']) {
            return [];     // a batch whose order has no lines: the old single, itemless dispatch
        }

        // Everything still to send, if nothing was chosen.
        if (!$requested) {
            $requested = [];
            foreach ($plan['items'] as $pi) {
                if ($pi['whole_line']) {
                    if ($pi['remaining_sqm'] > 0) $requested[] = ['order_item_id' => $pi['order_item_id']];
                    continue;
                }
                foreach ($pi['sizes'] as $ps) {
                    if ($ps['remaining_nos'] > 0) $requested[] = ['order_item_size_id' => $ps['order_item_size_id'], 'nos' => $ps['remaining_nos']];
                }
            }
        }

        $bySize = [];
        $wholeLines = [];
        foreach ($plan['items'] as $pi) {
            foreach ($pi['sizes'] as $ps) $bySize[$ps['order_item_size_id']] = ['plan' => $ps, 'item_id' => $pi['order_item_id']];
            if ($pi['whole_line']) $wholeLines[$pi['order_item_id']] = $pi;
        }

        $take = [];       // size id => nos
        $takeWhole = [];  // order item id => true
        foreach ($requested as $r) {
            if (!empty($r['order_item_size_id'])) {
                $sid = (int) $r['order_item_size_id'];
                if (!isset($bySize[$sid])) {
                    throw new \Exception('A size row you chose does not belong to this batch\'s order.');
                }
                $nos = (int) ($r['nos'] ?? 0);
                if ($nos < 1) {
                    throw new \Exception('Enter at least 1 piece for each size row you dispatch.');
                }
                $take[$sid] = ($take[$sid] ?? 0) + $nos;
            } elseif (!empty($r['order_item_id']) && isset($wholeLines[(int) $r['order_item_id']])) {
                $takeWhole[(int) $r['order_item_id']] = true;
            } else {
                throw new \Exception('Each dispatch line needs a size row and a number of pieces.');
            }
        }
        if (!$take && !$takeWhole) {
            throw new \Exception('Choose at least one piece to dispatch.');
        }
        foreach ($take as $sid => $nos) {
            $left = $bySize[$sid]['plan']['remaining_nos'];
            if ($nos > $left) {
                $p = $bySize[$sid]['plan'];
                throw new \Exception("Only {$left} of {$p['ordered_nos']} pieces of {$p['length_mm']} x {$p['width_mm']} mm are left to dispatch; you asked for {$nos}.");
            }
        }

        // Previous (live) sizes, for the remainder rule.
        $prevSizes = \App\Models\DispatchItemSize::whereHas('item.dispatch', fn ($q) => $q->where('batch_id', $batch->id)->where('status', '!=', 'cancelled'))
            ->get()->groupBy('order_item_size_id');
        $prevItems = DispatchItem::whereHas('dispatch', fn ($q) => $q->where('batch_id', $batch->id)->where('status', '!=', 'cancelled'))
            ->get()->groupBy('order_item_id');

        $orderItems = OrderItem::where('order_id', $batch->order_id)->with('sizes')->get()->keyBy('id');
        $out = [];
        foreach ($plan['items'] as $pi) {
            $oi = $orderItems[$pi['order_item_id']];

            if ($pi['whole_line']) {
                if (isset($takeWhole[$oi->id])) {
                    $out[] = ['item' => $oi, 'sizes' => [], 'quantity' => (float) ($oi->total_sqm ?: $oi->quantity), 'amount' => (float) $oi->amount];
                }
                continue;
            }

            $sizes = [];
            $lineSqm = $lineAmount = 0.0;
            $takesAll = true;
            foreach ($oi->sizes as $s) {
                $nos = $take[$s->id] ?? 0;
                $left = $bySize[$s->id]['plan']['remaining_nos'];
                if ($nos < $left) $takesAll = false;
                if ($nos === 0) continue;

                $prev = $prevSizes->get($s->id, collect());
                if ($nos === $left) {   // the last pieces of this row take whatever the earlier dispatches did not
                    $sqm = round((float) $s->sqm - (float) $prev->sum('sqm'), 4);
                    $amount = round((float) $s->amount - (float) $prev->sum('amount'), 2);
                } else {
                    $sqm = round((float) $s->sqm * $nos / $s->nos, 4);
                    $amount = round((float) $s->amount * $nos / $s->nos, 2);
                }
                $sizes[] = ['size' => $s, 'nos' => $nos, 'sqm' => $sqm, 'amount' => $amount];
                $lineSqm += $sqm;
                $lineAmount += $amount;
            }
            if (!$sizes) continue;

            if ($takesAll) {            // closes the order line: carry the stored line amount, exactly as a whole-batch dispatch did
                $prevItem = $prevItems->get($oi->id, collect());
                $lineSqm = round((float) ($oi->total_sqm ?: $lineSqm + $prevItem->sum('quantity')) - (float) $prevItem->sum('quantity'), 4);
                $lineAmount = round((float) ($oi->amount ?: $lineAmount + $prevItem->sum('amount')) - (float) $prevItem->sum('amount'), 2);
            }

            $out[] = ['item' => $oi, 'sizes' => $sizes, 'quantity' => round($lineSqm, 4), 'amount' => round($lineAmount, 2)];
        }

        return $out;
    }
}
