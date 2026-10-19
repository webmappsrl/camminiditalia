{{-- altPattern: testo alternativo con :n al posto del numero della foto, es. "Foto :n della segnalazione". --}}
@props(['label', 'urls' => [], 'altPattern'])
@if(!empty($urls))
<p style="margin:0 0 8px;font-size:14px;line-height:1.4;color:#bb4613;">{{ $label }} ({{ count($urls) }})</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px;">
    @foreach(array_chunk(array_values($urls), 3) as $chunkIndex => $row)
    <tr>
        @foreach($row as $index => $url)
        <td style="padding:0 8px 8px 0;"><img src="{{ $url }}" width="170" height="125" alt="{{ str_replace(':n', (string) ($chunkIndex * 3 + $index + 1), $altPattern) }}" style="display:block;border-radius:4px;object-fit:cover;"></td>
        @endforeach
    </tr>
    @endforeach
</table>
@endif
