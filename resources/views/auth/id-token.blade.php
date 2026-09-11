<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="shopify-api-key" content="{{ config('shopify.client_id') }}">
        <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>

        <title>{{ config('app.name', 'Laravel') }}</title>
    </head>
    <body>
        {{-- App Bridge reloads the page named by the "shopify-reload" parameter with a fresh ID token. --}}
        <p>Open {{ config('app.name', 'Laravel') }} from your Shopify admin.</p>
    </body>
</html>
