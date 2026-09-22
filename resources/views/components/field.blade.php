@props(['name', 'label', 'type' => 'text', 'value' => '', 'required' => false, 'hint' => null])
@php($inputName = preg_replace('/\.([^.]+)/', '[$1]', $name))
<div class="field">
    <label for="{{ $name }}">{{ $label }}@if($required)<span aria-hidden="true"> *</span>@endif</label>
    @if($slot->isNotEmpty())
        <select id="{{ $name }}" name="{{ $inputName }}" {{ $attributes->class(['form-control']) }} @required($required) @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror>{{ $slot }}</select>
    @else
        <input id="{{ $name }}" name="{{ $inputName }}" type="{{ $type }}" value="{{ is_scalar(old($name, $value)) ? old($name, $value) : '' }}" {{ $attributes->class(['form-control']) }} @required($required) @error($name) aria-invalid="true" aria-describedby="{{ $name }}-error" @enderror>
    @endif
    @if($hint)<p class="field-hint">{{ $hint }}</p>@endif
    @error($name)<p class="field-error" id="{{ $name }}-error" role="alert">{{ $message }}</p>@enderror
</div>
