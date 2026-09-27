@if ($isEmbed())
<div {{ $attributes->merge(['id' => $playerId]) }} @foreach ($builder->toDataAttributes() as $name => $value) {{ $name }}="{{ $value }}" @endforeach></div>
@once
<script src="{{ $builder->embedBundleUrl() }}"@if ($builder->embedBundleIsModule()) type="module"@endif{!! $nonce ? ' nonce="'.e($nonce).'"' : '' !!}></script>
@endonce
@else
<div {{ $attributes->merge(['id' => $playerId]) }} data-scarlett-host="{{ $playerId }}-config"{{ $manual ? ' data-scarlett-manual' : '' }}></div>
<script type="application/json" id="{{ $playerId }}-config" data-scarlett-config>{!! $configJson() !!}</script>
@unless ($manual)
<script type="module" @if ($nonce) nonce="{{ $nonce }}" @endif>window.ScarlettPlayerHost ? window.ScarlettPlayerHost.initAll(document, window.scarlettPlayerOptions || {}) : (window.scarlettPlayerPending = true);</script>
@endunless
@endif
