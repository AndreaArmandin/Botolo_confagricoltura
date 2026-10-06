<?php

use App\Enums\TemplateStatus;
use App\Models\Template;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('messages:send-due')->everyMinute();

Schedule::call(function () {

    $templates = Template::all();

    if ($templates->isEmpty()) {
        return;
    }

    $baseUrl = rtrim((string) config('kapso.base_url'), '/');

    $endPoint = config('kapso.business_id') . '/message_templates';

    foreach ($templates as $template) {

        // Se il template non ha un external_id, saltalo.
        // Se non ha external_id vuol dire che non è stato ancora creato sul server esterno di Kapso.
        if ($template->external_id === null) {
            continue;
        }

        try {
            $response = Http::withHeaders([
                'X-API-Key' => (string) config('kapso.api_key'),
            ])
                ->acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout((int) config('kapso.timeout', 15))
                ->get($baseUrl . '/' . $endPoint . '/' . $template->external_id);

            if (! $response->successful()) {
                report(new Exception('Failed to fetch template with external_id: ' . $template->external_id));

                continue;
            }

            $data = $response->json();

            if (! isset($data['status'])) {
                continue;
            }

            $newStatus = TemplateStatus::fromMeta($data['status']);

            if ($newStatus !== $template->status) {
                $template->status = $newStatus;
                $template->save();
                Log::info('Template con external_id: ' . $template->external_id . ' aggiornato con nuovo status: ' . $template->status->value);
            }
        } catch (Throwable $e) {
            report($e);
            Log::error('Errore durante il fetch del template con external_id: ' . $template->external_id, ['exception' => $e]);
        }
    }
})->everyMinute();
