@props(['title' => 'Titan Transfer'])
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#102e2b">
    <title>{{ $title }} · Titan Transfer</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('titan-mark.svg') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    {{ $slot }}
</body>
</html>
