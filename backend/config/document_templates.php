<?php

/**
 * PDF template library. Each document type lists the available templates the
 * admin can choose from. A template = a Blade view that receives the same data
 * variables as the default (the "contract"):
 *   - quotation : $quotation  (items.sizes, items.panelType, accessories, customer, company loaded)
 *   - boq       : $quotation, $company
 *   - invoice   : $invoice, $total
 *
 * To add a new template: create a DomPDF-compatible blade (tables/inline-block,
 * no flex/grid) and add one line here. It becomes selectable automatically.
 */
return [
    'quotation' => [
        'classic' => [
            'name'        => 'Classic',
            'view'        => 'quotations.pdf',
            'description' => 'Standard 3-page: Proforma Invoice + Terms & Conditions + BOQ cutting sheet.',
        ],
    ],

    'boq' => [
        'classic' => [
            'name'        => 'Classic',
            'view'        => 'quotations.boq_sheet',
            'description' => 'Cutting-list worker copy (sizes & quantities, no rates).',
        ],
    ],

    'invoice' => [
        'classic' => [
            'name'        => 'Classic',
            'view'        => 'invoices.pdf',
            'description' => 'Standard GST tax invoice.',
        ],
    ],
];
