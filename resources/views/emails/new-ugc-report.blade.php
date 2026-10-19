@php($routeName = $layer?->getStringName() ?? 'Cammino non determinato')
@extends('emails.layouts.cammini', [
    'title' => 'Nuova segnalazione sul tuo cammino',
    'preheader' => 'Nuova segnalazione su '.$routeName,
    'status' => ['text' => 'Nuova segnalazione', 'bg' => '#fdedd7', 'fg' => '#1d282b'],
    'appIconUrl' => $appIconUrl,
    'button' => ['label' => 'Apri la segnalazione', 'url' => $novaUrl, 'fallback' => 'Se il pulsante non funziona, apri questo indirizzo:'],
    'footer' => "Messaggio automatico di Cammini d'Italia. Le risposte a questo indirizzo non vengono lette.",
])

@section('content')
    @if($noOwner)
    <p style="margin:0 0 16px;padding:12px 16px;background:#fdedd7;border-radius:4px;font-size:15px;color:#1d282b;">
        Questa segnalazione è stata inviata a info@camminiditalia.org perché {{ $layer ? 'il cammino '.$routeName.' non ha un gestore assegnato' : 'non è stato possibile determinare il cammino di appartenenza' }}.
    </p>
    @endif

    <p style="margin:0 0 20px;">Un camminatore ha inviato una segnalazione dall'app.</p>

    <x-mail.route :name="$routeName" :logo-url="$routeLogoUrl" :logo-alt="'Logo di '.$routeName" />

    @foreach($formFields as $field)
    <x-mail.field :label="$field['label']">{{ is_array($field['value']) ? implode(', ', $field['value']) : $field['value'] }}</x-mail.field>
    @endforeach

    @if($coordinates)
    <x-mail.field label="Posizione">{{ $coordinates }}</x-mail.field>
    @endif

    <x-mail.field label="Data e ora">{{ $ugcPoi->created_at?->format('d/m/Y, H:i') ?? '—' }}</x-mail.field>

    <x-mail.photos label="Foto" :urls="$mediaUrls" alt-pattern="Foto :n della segnalazione" />
@endsection
