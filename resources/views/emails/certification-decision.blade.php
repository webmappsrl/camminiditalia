<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $approved ? __('Certification request approved') : __('Certification request rejected') }}</title>
    <style>
        body { font-family: Arial, sans-serif; color: #333; margin: 0; padding: 0; background: #f0ede8; }
        .container { max-width: 600px; margin: 30px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 12px rgba(0,0,0,0.12); }
        .header { background: #111111; padding: 20px 32px; }
        .header h1 { margin: 0; font-size: 18px; color: #fff; font-weight: bold; }
        .status-bar { padding: 10px 32px; }
        .status-bar.approved { background: #2e7d32; }
        .status-bar.rejected { background: #c62828; }
        .status-bar p { margin: 0; color: #fff; font-size: 13px; font-weight: bold; letter-spacing: 0.05em; text-transform: uppercase; }
        .body { padding: 32px; font-size: 15px; color: #444; }
        .stages { margin: 12px 0 24px; padding-left: 20px; }
        .stages li { margin-bottom: 4px; color: #222; }
        .note { background: #faf9f7; border-left: 4px solid #e8621a; padding: 12px 20px; border-radius: 4px; }
        .note label { display: block; font-size: 11px; text-transform: uppercase; color: #e8621a; font-weight: bold; letter-spacing: 0.08em; margin-bottom: 4px; }
        .note p { margin: 0; white-space: pre-line; color: #222; }
        .footer { padding: 16px 32px; font-size: 12px; color: #aaa; border-top: 1px solid #f0ede8; text-align: center; background: #faf9f7; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Cammini d'Italia</h1>
    </div>

    <div class="status-bar {{ $approved ? 'approved' : 'rejected' }}">
        <p>{{ $approved ? __('Certification request approved') : __('Certification request rejected') }}</p>
    </div>

    <div class="body">
        @if($walkerName !== '')
        <p>{{ __('Hello :name,', ['name' => $walkerName]) }}</p>
        @endif

        @if($approved)
        <p>{{ __('the manager of :route has approved your paper passport. These stages have been recognised:', ['route' => $route]) }}</p>
        <ul class="stages">
            @foreach($stages as $stage)
            <li>{{ $stage }}</li>
            @endforeach
        </ul>
        @else
        <p>{{ __('the manager of :route has rejected your certification request.', ['route' => $route]) }}</p>
        @endif

        @if(!empty($note))
        <div class="note">
            <label>{{ __('Note from the route manager') }}</label>
            <p>{{ $note }}</p>
        </div>
        @endif
    </div>

    <div class="footer">
        {{ __("Automatic notification — Cammini d'Italia — Please do not reply to this email.") }}
    </div>
</div>
</body>
</html>
