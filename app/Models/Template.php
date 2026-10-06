<?php

namespace App\Models;

use App\Enums\TemplateCategory;
use App\Enums\TemplateStatus;
use App\Integrations\Kapso\KapsoException;
use Database\Factories\TemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * @property TemplateStatus $status
 * @property TemplateCategory $category
 * @property array<int, string>|null $body_examples
 */
class Template extends Model
{
    /** @use HasFactory<TemplateFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'language',
        'category',
        'body',
        'body_examples',
        'status',
        'external_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TemplateStatus::class,
            'category' => TemplateCategory::class,
            'body_examples' => 'array',
        ];
    }

    // RELAZIONI

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    // SCOPES

    /**
     * Solo i template approvati da Meta si possono inviare.
     *
     * @param  Builder<Template>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', TemplateStatus::Approved);
    }

    protected static function booted(): void
    {
        // Gestisce evento di salvataggio del template
        static::saving(function (Template $template): void {
            $isNewTemplate = ! $template->exists;
            $contentHasChanged = $template->isDirty(['name', 'language', 'category', 'body', 'body_examples']);
            $isUnsubmittedDraft = $template->status === TemplateStatus::Draft && blank($template->external_id);

            if ($isNewTemplate || $contentHasChanged || $isUnsubmittedDraft) {
                // Invia il template a Kapso per l'approvazione
                $template->sendTemplateToKapsoForApproval();
            }
        });


        // Gestisce evento di eliminazione del template
        static::deleting(function (Template $template): bool {
            if (! filled($template->external_id) || ! filled($template->name)) {
                return true;
            }

            // Invia la richiesta di eliminazione del template a Kapso
            return $template->deleteTemplateFromKapso($template->external_id, $template->name);
        });
    }





    // UTILITY

    // Funzione per inviare il template a Kapso per l'approvazione
    protected function sendTemplateToKapsoForApproval(): void
    {
        // Controlla se possibile modificare il template
        $this->checkTemplateChangesAllowed();

        // Invia la richiesta di approvazione del template a Kapso
        $response = $this->sendTemplateApprovalRequestToKapso($this->bodyComponent());

        // Aggiorna il modello con la risposta ricevuta da Kapso
        $this->updateFromKapsoResponse($response);
    }


    // Elimina il template da Kapso
    protected function deleteTemplateFromKapso(string $hsmId, string $name): bool
    {
        try {
            $url = rtrim((string) config('kapso.base_url'), '/') . '/' . config('kapso.business_id') . '/message_templates?' . http_build_query([
                'hsm_id' => $hsmId,
                'name' => $name,
            ]);

            $response = Http::withHeaders([
                'X-API-Key' => (string) config('kapso.api_key'),
            ])
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout((int) config('kapso.timeout', 15))
                ->delete($url);

            if (! $response->successful() || $response->json('success') !== true) {
                Log::error('Kapso non ha confermato l’eliminazione del template.', [
                    'hsm_id' => $hsmId,
                    'name' => $name,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);

                return false;
            }

            Log::info('Template eliminato su Kapso.', ['hsm_id' => $hsmId, 'name' => $name]);

            return true;
        } catch (Throwable $e) {
            report($e);
            Log::error('Errore durante l’eliminazione del template su Kapso.', [
                'hsm_id' => $hsmId,
                'name' => $name,
                'exception' => $e,
            ]);

            return false;
        }
    }





    protected function checkTemplateChangesAllowed(): void
    {
        if ($this->exists && filled($this->getOriginal('external_id')) && $this->isDirty('category')) {
            throw ValidationException::withMessages([
                'category' => 'Meta non permette di cambiare la categoria di un template già inviato.',
            ]);
        }

        if (
            $this->exists
            && filled($this->getOriginal('external_id'))
            && $this->getRawOriginal('status') !== TemplateStatus::Rejected->value
            && $this->isDirty(['body', 'body_examples'])
        ) {
            throw ValidationException::withMessages([
                'body' => 'Meta permette di modificare solo i template rifiutati. Crea un nuovo template per inviare un’altra versione.',
            ]);
        }

        if ($this->exists && filled($this->getOriginal('external_id')) && $this->isDirty(['name', 'language'])) {
            throw ValidationException::withMessages([
                'name' => 'Nome e lingua di un template già inviato non possono essere modificati. Crea un nuovo template.',
            ]);
        }
    }

    protected function sendTemplateApprovalRequestToKapso(array $component): Response
    {
        if (blank(config('kapso.business_id')) || blank(config('kapso.api_key'))) {
            throw new KapsoException('Configura KAPSO_BUSINESS_ID e KAPSO_API_KEY per inviare il template in approvazione.');
        }

        $payload = [];
        $endpoint = config('kapso.business_id') . '/message_templates';

        if (filled($this->external_id)) {
            $endpoint .= '?' . http_build_query(['hsm_id' => $this->external_id]);
        } else {
            $payload['name'] = $this->name;
            $payload['language'] = $this->language;
            $payload['parameter_format'] = 'POSITIONAL';
            $payload['category'] = $this->category->forMeta();
        }

        $payload['components'] = [$component];

        try {
            $response = Http::baseUrl(rtrim((string) config('kapso.base_url'), '/') . '/')
                ->withHeader('X-API-Key', (string) config('kapso.api_key'))
                ->acceptJson()
                ->asJson()
                ->connectTimeout(5)
                ->timeout((int) config('kapso.timeout', 15))
                ->post($endpoint, $payload);
        } catch (ConnectionException $exception) {
            throw new KapsoException('Connessione a Kapso non riuscita. Verifica lo stato su Kapso prima di riprovare.', previous: $exception);
        }

        if ($response->failed()) {
            throw KapsoException::fromResponse($response);
        }

        return $response;
    }

    protected function updateFromKapsoResponse(Response $response): void
    {
        $externalId = $response->json('id');

        if (blank($this->external_id) && blank($externalId)) {
            throw new KapsoException('Kapso non ha restituito l’ID del template. Verifica lo stato su Kapso prima di riprovare.');
        }

        if (filled($this->external_id) && blank($externalId) && $response->json('success') !== true) {
            throw new KapsoException('Kapso non ha confermato l’aggiornamento del template.');
        }

        $this->external_id = $externalId ?? $this->external_id;
        $this->status = TemplateStatus::fromMeta($response->json('status'));
        $categoryFromKapso = strtolower((string) $response->json('category'));
        $category = TemplateCategory::tryFrom($categoryFromKapso);

        if ($category !== null) {
            $this->category = $category;
        }
    }





    /**
     * Restituisce un array con i numeri dei buchi presenti nel testo.
     * "Ciao {{1}}, scade il {{2}}." restituisce [1, 2]
     */
    public function placeholders(): array
    {
        // cerca tutti i numeri scritti fra due graffe
        preg_match_all('/\{\{(\d+)\}\}/', (string) $this->body, $matches);

        $numbers = [];

        foreach ($matches[1] as $match) {
            $numbers[] = (int) $match;
        }

        // toglie i doppioni (se {{1}} compare due volte) e ordina
        $numbers = array_unique($numbers);
        sort($numbers);

        return $numbers;
    }

    /**
     * Restituisce il componente del corpo del template, con il testo e gli esempi dei placeholder.
     *
     * @return array{type: string, text: string, example?: array{body_text: list<list<string>>}}
     */
    protected function bodyComponent(): array
    {
        $placeholders = $this->placeholders();
        $remaining = preg_replace('/\{\{[1-9]\d*\}\}/', '', (string) $this->body);
        $hasInvalidPlaceholders = str_contains($remaining, '{{') || str_contains($remaining, '}}');
        $hasMissingNumbers = $placeholders !== [] && $placeholders !== range(1, count($placeholders));

        if ($hasInvalidPlaceholders || $hasMissingNumbers) {
            throw ValidationException::withMessages([
                'body' => 'Usa variabili numeriche consecutive: {{1}}, {{2}}, {{3}}…',
            ]);
        }

        $body = trim((string) $this->body);

        if (preg_match('/^\{\{\d+\}\}|\{\{\d+\}\}$/', $body) === 1) {
            throw ValidationException::withMessages([
                'body' => 'Meta non accetta variabili all’inizio o alla fine del testo. Aggiungi del testo prima e dopo la variabile.',
            ]);
        }

        $component = ['type' => 'BODY', 'text' => (string) $this->body];
        $examples = [];

        foreach ($placeholders as $number) {
            $example = $this->body_examples[$number] ?? null;

            if (! is_string($example) || blank($example)) {
                throw ValidationException::withMessages([
                    'body_examples' => 'Inserisci un valore di esempio per la variabile {{' . $number . '}}.',
                ]);
            }

            $examples[] = $example;
        }

        if ($examples !== []) {
            $component['example'] = ['body_text' => [$examples]];
        }

        return $component;
    }

    /**
     * Ritorna il testo del template con i valori dei placeholder sostituiti.
     * I buchi senza valore restano come sono.
     *
     * @param  array<int, string>  $values
     */
    public function render(array $values): string
    {
        $body = (string) $this->body;
        $values = array_values($values);

        foreach ($this->placeholders() as $position => $number) {
            if (isset($values[$position])) {
                $body = str_replace('{{' . $number . '}}', $values[$position], $body);
            }
        }

        return $body;
    }
}
