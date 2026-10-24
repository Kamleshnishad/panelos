<?php

namespace App\Support;

/**
 * Indian digit grouping for anything printed or sent to a customer: 9,11,476.00, not 911,476.00. PHP's number_format()
 * only knows groups of three. The web screens already format this way, so a figure looked different on screen and on paper.
 */
class Inr
{
    public static function fmt(float|int|string|null $value, int $decimals = 2): string
    {
        $n = round((float) $value, $decimals);
        $parts = explode('.', number_format(abs($n), $decimals, '.', ''));
        $int = $parts[0];

        $head = substr($int, 0, -3);
        $grouped = ($head === '' ? '' : preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $head) . ',') . substr($int, -3);

        return ($n < 0 ? '-' : '') . $grouped . (isset($parts[1]) ? '.' . $parts[1] : '');
    }
}
