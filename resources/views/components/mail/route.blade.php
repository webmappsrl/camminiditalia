@props(['name', 'logoUrl' => null, 'logoAlt' => ''])
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px;">
    <tr>
        @if($logoUrl)
        <td style="padding-right:14px;vertical-align:middle;"><img src="{{ $logoUrl }}" width="56" height="56" alt="{{ $logoAlt }}" style="display:block;border-radius:6px;"></td>
        @endif
        <td style="vertical-align:middle;font-size:18px;font-weight:bold;color:#1d282b;">{{ $name }}</td>
    </tr>
</table>
