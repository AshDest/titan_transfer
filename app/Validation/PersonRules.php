<?php

namespace App\Validation;

final class PersonRules
{
    /** @return array<string, list<string>> */
    public static function registration(): array
    {
        return [
            'full_name' => ['sometimes', 'required', 'string', 'max:255'],
            'first_name' => ['exclude_with:full_name', 'required', 'string', 'max:100'],
            'last_name' => ['exclude_with:full_name', 'nullable', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9 ()-]{6,32}$/'],
            'country' => ['required', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'id_type' => ['nullable', 'string', 'max:50', 'required_with:id_number'],
            'id_number' => ['nullable', 'string', 'max:100', 'required_with:id_type'],
        ];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function nameAttributes(array $data): array
    {
        if (array_key_exists('full_name', $data)) {
            $data['first_name'] = trim($data['full_name']);
            $data['last_name'] = null;
            unset($data['full_name']);
        }

        return $data;
    }
}
