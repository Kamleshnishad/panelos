<?php

namespace App\Support;

/**
 * What an invoice comes to, written once for the reports that add it up in SQL (FR-010). The rule is Invoice::getTotal(): the stored `total_amount` when it is
 * above zero, otherwise the subtotal plus the stored tax. Reports that already hold an Invoice call getTotal(); reports that sum in the database use TOTAL_SQL,
 * so a revenue-trend month and a top-customers row can never disagree about the same invoice.
 */
final class InvoiceFigures
{
    public const TOTAL_SQL = 'CASE WHEN total_amount > 0 THEN total_amount ELSE subtotal + COALESCE(tax_amount, 0) END';
}
