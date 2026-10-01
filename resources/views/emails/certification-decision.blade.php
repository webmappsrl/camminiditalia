@extends('emails.layouts.cammini', [
    'title' => $approved ? __('Your request has been approved') : __('Your request has not been approved'),
    'preheader' => $approved
        ? __('Your certification request for :route has been approved', ['route' => $route])
        : __('Your certification request for :route has been rejected', ['route' => $route]),
    'status' => $approved
        ? ['text' => __('Approved'), 'bg' => '#e6f4ea', 'fg' => '#14532d']
        : ['text' => __('Rejected'), 'bg' => '#fdecea', 'fg' => '#8a1c12'],
    'appIconUrl' => $appIconUrl,
    'button' => null,
    'footer' => __("Automatic message from Cammini d'Italia. Replies to this address are not read."),
])

@section('content')
    @if($walkerName !== '')
    <p style="margin:0 0 16px;">{{ __('Hello :name,', ['name' => $walkerName]) }}</p>
    @endif

    <x-mail.route :name="$route" :logo-url="$routeLogoUrl" :logo-alt="__('Logo of :name', ['name' => $route])" />

    @if($approved)
    <p style="margin:0 0 12px;">{{ __('the manager of :route has approved your paper passport. These stages have been recognised:', ['route' => $route]) }}</p>
    <ul style="margin:0 0 20px;padding-left:22px;font-size:16px;line-height:1.6;color:#1d282b;">
        @foreach($stages as $stage)
        <li>{{ $stage }}</li>
        @endforeach
    </ul>
    @else
    <p style="margin:0 0 16px;">{{ __('the manager of :route has rejected your certification request.', ['route' => $route]) }}</p>
    @endif

    @if(!empty($note))
    <x-mail.note :label="__('Note from the route manager')">{{ $note }}</x-mail.note>
    @endif

    @unless($approved)
    <p style="margin:0 0 20px;">{{ __('You can send a new request from the app, on the route page, with clearer photos.') }}</p>
    @endunless
@endsection
