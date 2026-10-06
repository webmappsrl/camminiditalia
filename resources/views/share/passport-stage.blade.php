<!DOCTYPE html>
<html lang="{{ $lang }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    {{-- Pagina di un singolo utente: raggiungibile dal link condiviso, non dai motori di ricerca. --}}
    <meta name="robots" content="noindex">

    {{-- Open Graph (oc:8702): anteprima del link su WhatsApp e simili. La pagina è
         un'istantanea statica: i valori non cambiano dopo la condivisione. --}}
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:image" content="{{ $imageUrl }}">
    <meta property="og:url" content="{{ $canonicalUrl }}">

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ $imageUrl }}">

    <style>
        html, body { margin: 0; padding: 0; background: #12181f; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; color: #f5f5f5; }
        .card { max-width: 420px; margin: 0 auto; padding: 24px; box-sizing: border-box; text-align: center; }
        .card img { max-width: 100%; height: auto; border-radius: 16px; display: block; margin: 0 auto 16px; }
        h1 { font-size: 1.1rem; font-weight: 600; margin: 0 0 12px; }
        dl { margin: 0 0 16px; text-align: left; }
        dl div { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #2a323b; }
        dt { opacity: .7; }
        dd { margin: 0; font-weight: 600; }
        .date { opacity: .6; font-size: .85rem; margin: 0 0 16px; }
    </style>
</head>

<body>
    <div class="card">
        <img src="{{ $imageUrl }}" alt="{{ $title }}">
        <h1>{{ $title }}</h1>
        <dl>
            @foreach ($entries as $label => $value)
                <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
            @endforeach
        </dl>
        @if ($sharedAt)
            <p class="date">{{ $sharedAt }}</p>
        @endif
    </div>
</body>

</html>
