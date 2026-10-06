<?php

namespace App\Jobs;

use App\Enums\MessageStatus;
use App\Integrations\Kapso\Kapso;
use App\Integrations\Kapso\KapsoException;
use App\Models\Message;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public Message $message) {}

    public function handle(Kapso $kapso): void
    {
        // Se non e piu in coda qualcuno l'ha gia mandato.
        // Serve perche dopo un errore di rete il job viene ritentato,
        // e senza questo il socio riceverebbe il messaggio due volte.
        if ($this->message->status !== MessageStatus::Queued) {
            return;
        }

        if ($this->message->template_id === null && $this->message->parameters !== null) {
            $this->message->update([
                'status' => MessageStatus::Failed,
                'error' => 'Il template collegato a questo messaggio non è più disponibile.',
            ]);

            return;
        }

        try {
            if ($this->message->template_id === null) {
                if ($this->message->media_id === null) {
                    $externalId = $kapso->sendText(to: $this->message->to, body: $this->message->body);
                } elseif (($this->message->media_type ?? 'image') === 'document') {
                    $externalId = $kapso->sendDocument(
                        to: $this->message->to,
                        mediaId: $this->message->media_id,
                        caption: $this->message->body,
                        filename: $this->message->media_filename ?? '',
                    );
                } elseif (($this->message->media_type ?? 'image') === 'image') {
                    $externalId = $kapso->sendImage(
                        to: $this->message->to,
                        mediaId: $this->message->media_id,
                        caption: $this->message->body,
                    );
                } else {
                    throw new KapsoException('Tipo di allegato non supportato.');
                }
            } else {
                $externalId = $kapso->sendTemplate(
                    to: $this->message->to,
                    templateName: $this->message->template->name,
                    language: $this->message->template->language,
                    parameters: $this->message->parameters ?? [],
                );
            }
        } catch (KapsoException $e) {
            // Errore dell'API: e definitivo, non ha senso ritentare
            $this->message->update([
                'status' => MessageStatus::Failed,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $this->message->update([
            'status' => MessageStatus::Sent,
            'external_id' => $externalId,
            'sent_at' => now(),
        ]);
    }

    /**
     * Finiti i tentativi. Senza questo il messaggio resterebbe
     * "in coda" per sempre, sembrando in attesa invece che morto.
     */
    public function failed(Throwable $e): void
    {
        $this->message->update([
            'status' => MessageStatus::Failed,
            'error' => $e->getMessage(),
        ]);
    }
}
