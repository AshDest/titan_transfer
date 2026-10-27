@props(['prefix' => '', 'firstName' => '', 'lastName' => ''])
@php
    $fieldPrefix = $prefix === '' ? '' : $prefix.'.';
    $previousFirst = old($fieldPrefix.'first_name', $firstName);
    $previousLast = old($fieldPrefix.'last_name', $lastName);
    $name = trim((is_string($previousFirst) ? $previousFirst : '').' '.(is_string($previousLast) ? $previousLast : ''));
@endphp
<x-field :name="$fieldPrefix.'full_name'" label="Noms" :value="$name" required maxlength="255" autocomplete="name" />
