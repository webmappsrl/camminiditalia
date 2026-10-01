@php($layer = $request->layer)
@php($routeName = $layer?->getStringName() ?? 'Cammino non determinato')
@extends('emails.layouts.cammini', [
    'title' => 'Nuova richiesta di certificazione',
    'preheader' => $walkerDisplay.' chiede la certificazione di '.$routeName,
    'status' => ['text' => 'Nuova richiesta', 'bg' => '#fdedd7', 'fg' => '#1d282b'],
    'appIconUrl' => $appIconUrl,
    'button' => ['label' => 'Esamina la richiesta', 'url' => $novaUrl, 'fallback' => 'Se il pulsante non funziona, apri questo indirizzo:'],
    'footer' => "Messaggio automatico di Cammini d'Italia. Le risposte a questo indirizzo non vengono lette.",
])

@section('content')
    @if($noOwner)
    <p style="margin:0 0 16px;padding:12px 16px;background:#fdedd7;border-radius:4px;font-size:15px;color:#1d282b;">
        Questa richiesta è stata inviata a info@camminiditalia.org perché {{ $layer ? 'il cammino '.$routeName.' non ha un gestore assegnato' : 'non è stato possibile determinare il cammino di appartenenza' }}.
    </p>
    @endif

    <p style="margin:0 0 20px;">Un camminatore ha inviato le foto della credenziale cartacea e chiede di riconoscere le tappe percorse.</p>

    <x-mail.route :name="$routeName" :logo-url="$routeLogoUrl" :logo-alt="'Logo di '.$routeName" />

    <x-mail.field label="Camminatore">{{ $walkerDisplay }}</x-mail.field>
    <x-mail.field label="Inviata il">{{ $request->created_at?->format('d/m/Y, H:i') ?? '—' }}</x-mail.field>
    @if(!empty($request->serial_number))
    <x-mail.field label="Numero seriale">{{ $request->serial_number }}</x-mail.field>
    @endif
    <x-mail.photos label="Foto della credenziale" :urls="$photoUrls" alt-pattern="Foto :n della credenziale" />
@endsection
