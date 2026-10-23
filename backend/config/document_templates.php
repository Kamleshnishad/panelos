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
 * no flex/grid, no CSS variables or calc) and add one line here. It becomes selectable
 * automatically. Optional `page_numbers => true` stamps "Page x of y" after rendering.
 */
return [
    'quotation' => [
        'classic' => [
            'name'        => 'Classic',
            'view'        => 'quotations.pdf',
            'description' => 'Standard 3-page: Proforma Invoice + Terms & Conditions + BOQ cutting sheet.',
        ],
        'ledger' => [
            'name'         => 'Ledger',
            'view'         => 'quotations.pdf_ledger',
            'description'  => 'Dense, tabular GST-style proforma: size schedules, GST summary by HSN/SAC, payment schedule, bank details, terms and acceptance. "Page x of y" on every page.',
            'page_numbers' => true,
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
            'name'        => 'Professional',
            'view'        => 'invoices.pdf',
            'description' => 'GST tax invoice — DejaVu font (proper ₹), CGST/SGST/IGST split, amount in words, balance due, signatory block.',
        ],
    ],
];
