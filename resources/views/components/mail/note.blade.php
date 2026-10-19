@props(['label'])
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;">
    <tr>
        <td style="padding:14px 18px;background:#fdedd7;border-left:4px solid #f07821;border-radius:4px;">
            <p style="margin:0 0 4px;font-size:14px;font-weight:bold;color:#1d282b;">{{ $label }}</p>
            <p style="margin:0;font-size:16px;line-height:1.5;color:#1d282b;white-space:pre-line;">{{ $slot }}</p>
        </td>
    </tr>
</table>
