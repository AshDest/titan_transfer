@props(['title' => 'Titan Transfer'])
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#212139">
    <title>{{ $title }} · Titan Express</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('titan-mark.svg').'?v=2' }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    {{ $slot }}
</body>
</html>
