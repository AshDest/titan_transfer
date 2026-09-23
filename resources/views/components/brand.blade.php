@props(['inverse' => false])
<div {{ $attributes->class(['brand']) }}>
    <img class="brand-logo" src="{{ asset($inverse ? 'images/brand/titan-express-light.png' : 'images/brand/titan-express-dark.png') }}" alt="Titan Express" width="8334" height="2313" decoding="async">
</div>
