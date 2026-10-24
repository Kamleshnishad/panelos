{{-- Running footer: reference on the left, generated-on on the right. "Page x of y" is stamped in the middle
     afterwards by QuotationPdfService (registry flag page_numbers), because the total is only known once laid out. --}}
<div id="ftr">
    <table><tr>
        <td style="width: 40%">{{ $reference }} &nbsp;·&nbsp; {{ $buyer }}</td>
        <td style="width: 20%"></td>
        <td class="r">Generated {{ now()->format('d M Y, H:i') }} &nbsp;·&nbsp; {{ config('app.name', 'PanelOS') }}</td>
    </tr></table>
</div>
