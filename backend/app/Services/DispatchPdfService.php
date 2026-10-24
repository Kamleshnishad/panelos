<?php

namespace App\Services;

use App\Models\Dispatch;
use App\Models\OrderItem;
use Barryvdh\DomPDF\Facade\Pdf;

class DispatchPdfService
{
    /**
     * Load a dispatch with everything the challan needs, and enrich each
     * dispatch item with the panel specification snapshot from the order.
     */
    private function loadForChallan(Dispatch $dispatch): Dispatch
    {
        $dispatch->load([
            'company',
            'batch.order.customer',
            'items.panelType',
            'items.sizes',
        ]);

        // Build a panel_type_id => OrderItem spec map from the linked order
        $order = $dispatch->batch?->order;
        $specMap = collect();
        if ($order) {
            $specMap = OrderItem::where('order_id', $order->id)
                ->get()
                ->keyBy('panel_type_id');
        }

        // A dispatch item points at its order line (FT-03); older ones only know the panel type.
        $byLine = $order ? OrderItem::where('order_id', $order->id)->get()->keyBy('id') : collect();

        // Attach spec to each dispatch item (non-persistent)
        $dispatch->items->each(function ($item) use ($specMap, $byLine) {
            $item->spec = ($item->order_item_id ? $byLine->get($item->order_item_id) : null) ?? $specMap->get($item->panel_type_id);
        });

        // What is still to come after this challan, so the receiver sees it is a part load.
        $dispatch->balance_sqm = null;
        if ($dispatch->batch && $dispatch->items->contains(fn ($i) => $i->sizes->isNotEmpty())) {
            $plan = app(DispatchPlanService::class)->plan($dispatch->batch);
            $dispatch->balance_sqm = $plan['remaining_sqm'] > 0 ? $plan['remaining_sqm'] : null;
        }

        return $dispatch;
    }

    public function stream(Dispatch $dispatch): \Illuminate\Http\Response
    {
        $dispatch = $this->loadForChallan($dispatch);

        $pdf = Pdf::loadView('dispatches.challan', ['dispatch' => $dispatch])
            ->setPaper('a4', 'portrait');

        return $pdf->stream("challan-{$dispatch->dispatch_no}.pdf");
    }

    public function download(Dispatch $dispatch): \Illuminate\Http\Response
    {
        $dispatch = $this->loadForChallan($dispatch);

        $pdf = Pdf::loadView('dispatches.challan', ['dispatch' => $dispatch])
            ->setPaper('a4', 'portrait');

        return $pdf->download("challan-{$dispatch->dispatch_no}.pdf");
    }
}
