<?php

declare(strict_types=1);

return [

    'placeholder' => 'default',
    'kapso' => [
        'base_url' => env('KAPSO_BASE_URL'),
        'phone_id' => env('KAPSO_PHONE_ID'),
        'api_key' => env('KAPSO_API_KEY'),
        'business_id' => env('KAPSO_BUSINESS_ID'),
    ],
    'webhook' => [
        'handler' => null,
        'secret' => env('KAPSO_WEBHOOK_SECRET'),

        /*
         * Kapso ritenta la consegna a 10 e 40 secondi se non riceve un 200.
         * Ogni payload viene processato una volta sola: la chiave e' per
         * singolo evento, cosi' il retry di un batch parzialmente riuscito
         * ripassa solo gli eventi rimasti indietro.
         */
        'idempotency' => [
            'enabled' => (bool) env('KAPSO_WEBHOOK_IDEMPOTENCY', true),
            'store' => env('KAPSO_WEBHOOK_CACHE_STORE'),
            'ttl' => (int) env('KAPSO_WEBHOOK_IDEMPOTENCY_TTL', 3600),
        ],

        /*
         * L'endpoint deve rispondere entro 10 secondi. Con la coda attiva
         * l'handler viene eseguito fuori dalla richiesta, che si chiude
         * subito dopo la validazione della firma.
         */
        'queue' => [
            'enabled' => (bool) env('KAPSO_WEBHOOK_QUEUE', true),
            'connection' => env('KAPSO_WEBHOOK_QUEUE_CONNECTION'),
            'queue' => env('KAPSO_WEBHOOK_QUEUE_NAME'),
        ],
    ],

];
