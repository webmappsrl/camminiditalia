@props(['label'])
<div style="margin:0 0 14px;padding:0 0 14px;border-bottom:1px solid #e6e9ea;">
    <p style="margin:0 0 2px;font-size:14px;line-height:1.4;color:#bb4613;">{{ $label }}</p>
    <p style="margin:0;font-size:16px;line-height:1.5;color:#1d282b;">{{ $slot }}</p>
</div>
