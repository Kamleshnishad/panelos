<?php

namespace App\Support;

use Barryvdh\DomPDF\PDF;

/**
 * The PDF wrapper every document uses, taught to print Gujarati and Hindi (PB-084).
 *
 * DejaVu Sans has no Gujarati or Devanagari glyphs, so a customer or project name typed in either
 * printed as empty boxes. dompdf does not fall back to another font per character, so each run of
 * such text is wrapped in a span set in the matching Noto font, and the fonts are declared only when
 * a document actually contains that script (no cost for the all-English ones).
 */
class IndicPdf extends PDF
{
    private const SCRIPTS = [
        'gujarati'   => ['range' => '\x{0A80}-\x{0AFF}', 'family' => 'Noto Sans Gujarati',   'file' => 'NotoSansGujarati'],
        'devanagari' => ['range' => '\x{0900}-\x{097F}', 'family' => 'Noto Sans Devanagari', 'file' => 'NotoSansDevanagari'],
    ];

    public function loadHTML(string $string, ?string $encoding = null): self
    {
        return parent::loadHTML(self::apply($string), $encoding);
    }

    public static function apply(string $html): string
    {
        $css = '';
        foreach (self::SCRIPTS as $s) {
            $run = '[' . $s['range'] . '\x{200C}\x{200D}]+(?:[ \x{00A0}]+[' . $s['range'] . '\x{200C}\x{200D}]+)*';
            $parts = preg_split('/(<[^>]*>)/u', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
            if ($parts === false) {
                continue;   // not valid UTF-8: leave the document alone rather than lose it
            }
            $found = false;
            foreach ($parts as $i => $part) {
                if ($i % 2 === 0 && preg_match('/' . $run . '/u', $part)) {
                    $parts[$i] = preg_replace('/(' . $run . ')/u', '<span style="font-family: \'' . $s['family'] . '\';">$1</span>', $part);
                    $found = true;
                }
            }
            if ($found) {
                $html = implode('', $parts);
                $dir = str_replace('\\', '/', resource_path('fonts'));
                $css .= "@font-face{font-family:'{$s['family']}';font-weight:normal;font-style:normal;src:url('file://{$dir}/{$s['file']}_400Regular.ttf') format('truetype');}"
                      . "@font-face{font-family:'{$s['family']}';font-weight:bold;font-style:normal;src:url('file://{$dir}/{$s['file']}_700Bold.ttf') format('truetype');}";
            }
        }

        if ($css === '') {
            return $html;
        }
        // dompdf copies a font it meets for the first time into its font folder, and does not create it.
        if (!is_dir(storage_path('fonts'))) {
            @mkdir(storage_path('fonts'), 0775, true);
        }
        $style = '<style>' . $css . '</style>';
        $count = 0;
        $out = preg_replace('/<\/head>/i', $style . '</head>', $html, 1, $count);
        return $count ? $out : $style . $html;
    }
}
