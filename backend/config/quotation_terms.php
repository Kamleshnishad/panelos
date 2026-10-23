<?php

/**
 * Default terms printed by the quotation templates that use QuotationDocumentService.
 * Placeholders ({advance_amount}, {valid_until}, {company}, {city} …) are filled per quotation
 * and shown in bold. Per-company editable terms are a later step (QUOTATION_TEMPLATES_PLAN.md,
 * step 0.3); until then every tenant gets this wording.
 */
return [
    'terms' => [
        ['title' => 'Scope of work', 'text' => 'Scope is limited to supply of material as per the specifications above.'],
        ['title' => 'Price basis', 'text' => 'Prices are ex works.'],
        ['title' => 'Payment', 'text' => '{advance_pct} of order value including taxes at the time of placing the order ({advance_amount}); balance {balance_pct} ({balance_amount}) against Proforma Invoice at least 2 days before delivery. Payment by NEFT / RTGS / cheque in favour of {company}.'],
        ['title' => 'Taxes', 'text' => 'GST and TCS extra as applicable.'],
        ['title' => 'Delivery', 'text' => 'Within 14 days for standard material and 21 days for non-standard material, after techno-commercial clearance.'],
        ['title' => 'Freight and transit insurance', 'text' => 'In customer scope.'],
        ['title' => 'Offer validity', 'text' => '{validity_days} from {quoted_on} — valid until {valid_until}.'],
        ['title' => 'Wastage and overlap', 'text' => 'Panel standard cover width is 1 m and length as per requirement. Any wastage and overlap to be in customer scope.'],
        ['title' => 'Measurement of quantity', 'text' => 'Above quantities are tentative. Material will be supplied and billed as per actual supplied material.'],
        ['title' => 'Jurisdiction', 'text' => 'Subject to {city} jurisdiction.'],
        ['title' => 'Warranty', 'text' => '6 months against manufacturing defects from date of invoicing, subject to standard terms and conditions.'],
        ['title' => 'Note', 'text' => 'Rates are subject to change without prior notice as per the raw material market.'],
    ],

    'exclusions' => [
        'Unloading and safe storage of the material at site.',
        'Any replacement required due to damage in handling is chargeable.',
        'Electrical works.',
        'Project detailing and BOQ.',
        'Any special packaging cost.',
        'Any other items not specifically included above.',
    ],
];
