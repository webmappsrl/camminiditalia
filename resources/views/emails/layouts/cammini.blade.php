{{--
    Scheletro comune delle mail di Cammini d'Italia (oc:8671).
    Tabelle con role="presentation" e stili in linea perché Outlook e diverse
    webmail ignorano flex e <style>. Colori dal sito camminiditalia.org
    (tema cammini-v2): quasi nero #1d282b, arancione #f07821 / #e15d15,
    crema #fdedd7, fondo #f4f6f8, etichette #bb4613. Contrasti WCAG AA tranne il pulsante (testo
    bianco 16px su #e15d15, 3,6:1): eccezione decisa dal dev per il brand.

    Variabili: $title, $preheader, $status ['text','bg','fg'], $appIconUrl,
    $button (opzionale: ['label','url']), $footer. Corpo in @section('content').
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f8;">
<div style="display:none;max-height:0;overflow:hidden;">{{ $preheader }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:8px;">
                <tr>
                    <td style="padding:20px 32px;background:#1d282b;border-radius:8px 8px 0 0;border-bottom:4px solid #f07821;">
                        <table role="presentation" cellpadding="0" cellspacing="0">
                            <tr>
                                @if($appIconUrl)
                                <td style="padding-right:12px;"><img src="{{ $appIconUrl }}" width="44" height="44" alt="" style="display:block;border-radius:50%;"></td>
                                @endif
                                <td style="font-family:Montserrat,Arial,Helvetica,sans-serif;font-size:20px;font-weight:bold;color:#ffffff;">Cammini d'Italia</td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:28px 32px 8px;font-family:Montserrat,Arial,Helvetica,sans-serif;">
                        <p style="margin:0 0 12px;"><span style="display:inline-block;padding:4px 12px;border-radius:999px;font-size:14px;font-weight:bold;background:{{ $status['bg'] }};color:{{ $status['fg'] }};">{{ $status['text'] }}</span></p>
                        <h1 style="margin:0 0 12px;font-size:24px;line-height:1.3;color:#1d282b;">{{ $title }}</h1>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 32px 8px;font-family:Montserrat,Arial,Helvetica,sans-serif;font-size:16px;line-height:1.5;color:#1d282b;">
                        @yield('content')
                    </td>
                </tr>
                @if(!empty($button))
                <tr>
                    <td style="padding:8px 32px 28px;font-family:Montserrat,Arial,Helvetica,sans-serif;">
                        <table role="presentation" cellpadding="0" cellspacing="0">
                            <tr>
                                <td style="border-radius:6px;background:#e15d15;">
                                    <a href="{{ $button['url'] }}" style="display:inline-block;padding:13px 24px;font-size:16px;line-height:20px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:6px;">{{ $button['label'] }}</a>
                                </td>
                            </tr>
                        </table>
                        <p style="margin:12px 0 0;font-size:14px;line-height:1.5;color:#4c585b;">{{ $button['fallback'] }} <a href="{{ $button['url'] }}" style="color:#bb4613;word-break:break-all;">{{ $button['url'] }}</a></p>
                    </td>
                </tr>
                @else
                <tr><td style="padding:0 0 20px;"></td></tr>
                @endif
                <tr>
                    <td style="padding:16px 32px;background:#f4f6f8;border-radius:0 0 8px 8px;font-family:Montserrat,Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#4c585b;">
                        {{ $footer }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
