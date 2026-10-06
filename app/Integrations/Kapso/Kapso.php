<?php

namespace App\Integrations\Kapso;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class Kapso
{
    public function __construct(
        protected ?string $phoneId = null,
    ) {
        $this->phoneId ??= config('kapso.phone_id');
    }

    /**
     * Manda un testo libero.
     *
     * Funziona solo se la finestra di 24 ore e aperta, cioe se il destinatario
     * ci ha scritto per primo. Fuori da quella, WhatsApp risponde 422 e bisogna
     * usare un template.
     *
     * @return string L'id del messaggio, serve dopo per agganciare i webhook.
     *
     * @throws KapsoException
     */
    public function sendText(string $to, string $body): string
    {
        return $this->send([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $body],
        ]);
    }

    public function uploadMedia(string $path, string $mimeType, string $filename): string
    {
        $disk = Storage::disk('local');

        if (! in_array($mimeType, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
            throw new KapsoException('Allega un’immagine JPG o PNG oppure un PDF.');
        }

        $response = Http::baseUrl(rtrim((string) config('kapso.base_url'), '/').'/')
            ->withHeader('X-API-Key', (string) config('kapso.api_key'))
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout((int) config('kapso.timeout'))
            ->attach('file', $disk->get($path), $filename, ['Content-Type' => $mimeType])
            ->post("{$this->phoneId}/media", [
                'messaging_product' => 'whatsapp',
                'type' => $mimeType,
            ]);

        if ($response->failed()) {
            throw KapsoException::fromResponse($response);
        }

        $id = $response->json('id');

        if (blank($id)) {
            throw new KapsoException('Kapso ha caricato l’allegato ma non ha restituito il suo ID.');
        }

        return $id;
    }

    public function sendDocument(string $to, string $mediaId, string $caption, string $filename): string
    {
        $document = ['id' => $mediaId];

        if (filled($caption)) {
            $document['caption'] = $caption;
        }

        if (filled($filename)) {
            $document['filename'] = $filename;
        }

        return $this->send([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'document',
            'document' => $document,
        ]);
    }

    public function sendImage(string $to, string $mediaId, string $caption): string
    {
        $image = ['id' => $mediaId];

        if (filled($caption)) {
            $image['caption'] = $caption;
        }

        return $this->send([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'image',
            'image' => $image,
        ]);
    }

    /**
     * Manda un template gia approvato. Non serve nessuna finestra aperta.
     *
     * @param  array<int, string>  $parameters  I valori per {{1}}, {{2}}...
     *
     * @throws KapsoException
     */
    public function sendTemplate(string $to, string $templateName, string $language = 'it', array $parameters = []): string
    {
        $template = [
            'name' => $templateName,
            'language' => ['code' => $language],
        ];

        if (filled($parameters)) {
            $template['components'] = [[
                'type' => 'body',
                'parameters' => array_map(
                    fn (string $value): array => ['type' => 'text', 'text' => $value],
                    array_values($parameters),
                ),
            ]];
        }

        return $this->send([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => $template,
        ]);
    }

    /**
     * Chiede a Meta l'elenco dei template del nostro business account.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws KapsoException
     */
    public function templates(?string $businessId = null): array
    {
        $businessId ??= config('kapso.business_id');

        if (blank($businessId)) {
            throw new KapsoException('KAPSO_BUSINESS_ID non configurato: senza non si possono leggere i template.');
        }

        $response = $this->request()->get("{$businessId}/message_templates", ['limit' => 200]);

        if ($response->failed()) {
            throw KapsoException::fromResponse($response);
        }

        return $response->json('data') ?? [];
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws KapsoException
     */
    protected function send(array $payload): string
    {
        $response = $this->request()->post("{$this->phoneId}/messages", $payload);

        if ($response->failed()) {
            throw KapsoException::fromResponse($response);
        }

        $id = $response->json('messages.0.id');

        if (blank($id)) {
            throw new KapsoException('Kapso accepted the message but returned no message id.');
        }

        return $id;
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('kapso.base_url'), '/').'/')
            ->withHeader('X-API-Key', (string) config('kapso.api_key'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('kapso.timeout'));
    }
}
