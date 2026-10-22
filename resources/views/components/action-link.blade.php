@props(['href', 'icon', 'label', 'danger' => false])
<a href="{{ $href }}" {{ $attributes->class(['action-icon', 'action-icon-danger' => $danger]) }} title="{{ $label }}" aria-label="{{ $label }}">
    <x-icon :name="$icon" />
</a>
