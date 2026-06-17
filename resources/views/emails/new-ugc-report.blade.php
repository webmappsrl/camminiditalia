<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nuova segnalazione</title>
    <style>
        body { font-family: Arial, sans-serif; color: #333; margin: 0; padding: 0; background: #f0ede8; }
        .container { max-width: 600px; margin: 30px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,0.12); }

        .header { background: #111111; padding: 20px 32px; display: flex; align-items: center; }
        .header img { height: 56px; width: 56px; border-radius: 50%; margin-right: 16px; }
        .header-text { }
        .header-text h1 { margin: 0; font-size: 18px; color: #fff; font-weight: bold; }
        .header-text p { margin: 2px 0 0; font-size: 13px; color: #e8621a; letter-spacing: 0.05em; text-transform: uppercase; }

        .alert-bar { background: #e8621a; padding: 10px 32px; }
        .alert-bar p { margin: 0; color: #fff; font-size: 13px; font-weight: bold; letter-spacing: 0.05em; text-transform: uppercase; }

        .body { padding: 32px; }
        .intro { font-size: 15px; color: #444; margin-bottom: 24px; }
        .intro strong { color: #1e2235; }

        .field { margin-bottom: 14px; border-bottom: 1px solid #f0ede8; padding-bottom: 14px; }
        .field:last-of-type { border-bottom: none; }
        .field label { display: block; font-size: 11px; text-transform: uppercase; color: #e8621a; font-weight: bold; letter-spacing: 0.08em; margin-bottom: 3px; }
        .field p { margin: 0; font-size: 15px; color: #222; }

        .photos { margin-bottom: 20px; }
        .photos label { display: block; font-size: 11px; text-transform: uppercase; color: #e8621a; font-weight: bold; letter-spacing: 0.08em; margin-bottom: 8px; }
        .photos-grid { display: flex; flex-wrap: wrap; gap: 8px; }
        .photos-grid img { width: 180px; height: 130px; object-fit: cover; border-radius: 4px; border: 1px solid #eee; }
        .cta-wrap { text-align: center; margin-top: 28px; }
        .cta { display: inline-block; background: #e8621a; color: #fff; padding: 13px 32px; border-radius: 6px; text-decoration: none; font-weight: bold; font-size: 15px; letter-spacing: 0.03em; }

        .no-owner-notice { background: #fff8e1; border-left: 4px solid #f0a500; padding: 12px 20px; margin-bottom: 20px; border-radius: 4px; font-size: 13px; color: #7a5c00; }
        .footer { padding: 16px 32px; font-size: 12px; color: #aaa; border-top: 1px solid #f0ede8; text-align: center; background: #faf9f7; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        @php
            $appModel = \Wm\WmPackage\Models\App::find($ugcPoi->app_id);
            $logoUrl = $appModel?->getFirstMediaUrl('icon');
        @endphp
        @if($logoUrl)
        <img src="{{ $logoUrl }}" alt="Logo">
        @endif
        <div class="header-text">
            <h1>Cammini d'Italia</h1>
            <p>Pannello gestione segnalazioni</p>
        </div>
    </div>

    <div class="alert-bar">
        <p>⚠ Nuova segnalazione ricevuta</p>
    </div>

    <div class="body">
        @if($noOwner)
        <div class="no-owner-notice">
            ⚠️ Questa segnalazione è stata inviata a info@camminiditalia.org perché {!! $layer ? 'il cammino <strong>'.e($layer->getStringName()).'</strong> non ha un gestore assegnato' : 'non è stato possibile determinare il cammino di appartenenza' !!}.
        </div>
        @endif
        <p class="intro">È stata ricevuta una nuova segnalazione{!! $layer ? ' sul cammino <strong>'.e($layer->getStringName()).'</strong>' : ' (cammino non determinato)' !!}.</p>

        <div class="field">
            <label>{{ __('Layer') }}</label>
            <p>{{ $layer?->getStringName() ?? 'Non determinato' }}</p>
        </div>

        @foreach($formFields as $field)
        <div class="field">
            <label>{{ $field['label'] }}</label>
            <p>{{ is_array($field['value']) ? implode(', ', $field['value']) : $field['value'] }}</p>
        </div>
        @endforeach

        @if($coordinates)
        <div class="field">
            <label>Posizione geografica</label>
            <p>{{ $coordinates }}</p>
        </div>
        @endif

        <div class="field">
            <label>Data e ora</label>
            <p>{{ $ugcPoi->created_at?->format('d/m/Y H:i') ?? '—' }}</p>
        </div>

        @if(!empty($mediaUrls))
        <div class="photos">
            <label>{{ __('Photos') }}</label>
            <div class="photos-grid">
                @foreach($mediaUrls as $url)
                <img src="{{ $url }}" alt="foto segnalazione">
                @endforeach
            </div>
        </div>
        @endif

        <div class="cta-wrap">
            <a href="{{ $novaUrl }}" class="cta">Apri nel pannello admin →</a>
        </div>
    </div>

    <div class="footer">
        Notifica automatica &mdash; Cammini d'Italia &mdash; Non rispondere a questa email.
    </div>
</div>
</body>
</html>
