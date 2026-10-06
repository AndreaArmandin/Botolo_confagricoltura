<?php

return [

    'base_url' => env('KAPSO_API_BASE_URL', env('KAPSO_BASE_URL', 'https://api.kapso.ai/meta/whatsapp/v24.0')),

    'api_key' => env('KAPSO_API_KEY'),

    'phone_id' => env('KAPSO_PHONE_ID'),

    /* Id del WhatsApp Business Account: serve per leggere e creare i template. */
    'business_id' => env('KAPSO_BUSINESS_ID'),

    'webhook_secret' => env('KAPSO_WEBHOOK_SECRET'),

    'timeout' => env('KAPSO_TIMEOUT', 15),

];
