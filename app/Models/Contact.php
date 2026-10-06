<?php

namespace App\Models;

use App\Enums\MessageStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'surname',
        'email',
        'phone',
        'address',
    ];

    // RELAZIONI
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'contact_tag', 'contact_id', 'tag_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    // FILTRI

    /**
     * Sostituisce i riferimenti al contatto dentro un testo.
     *
     * Esempio: "Gentile {nome} {cognome}" diventa "Gentile Mario Rossi".
     * Se non ci sono riferimenti, il testo torna identico.
     */
    public function fillTokens(string $text): string
    {
        $text = str_replace('{nome}', (string) $this->name, $text);
        $text = str_replace('{cognome}', (string) $this->surname, $text);

        return str_replace('{email}', (string) $this->email, $text);
    }

    /**
     * Crea il messaggio per questo contatto, senza inviarlo.
     *
     * Torna null se il contatto non ha un telefono usabile.
     * Con una data resta in attesa, senza e pronto a partire.
     *
     * @param  array<int, string>  $values  I valori scritti nella modale, uno per buco.
     */
    public function prepareMessage(Template $template, array $values, ?CarbonInterface $scheduledAt = null): ?Message
    {
        $to = $this->whatsAppNumber();

        if ($to === null) {
            return null;
        }

        // I valori arrivano uguali per tutti:      ['{nome}', '31/12/2026']
        // qui diventano quelli di questo contatto: ['Mario',  '31/12/2026']
        $parameters = [];

        foreach ($values as $value) {
            $parameters[] = $this->fillTokens($value);
        }

        return $this->messages()->create([
            'template_id' => $template->getKey(),
            'to' => $to,
            'body' => $template->render($parameters),
            'parameters' => $parameters,
            'scheduled_at' => $scheduledAt,
            'status' => $scheduledAt === null
                ? MessageStatus::Queued
                : MessageStatus::Scheduled,
        ]);
    }

    public function prepareCustomMessage(
        string $body,
        ?string $mediaId = null,
        ?CarbonInterface $scheduledAt = null,
        ?string $mediaType = null,
        ?string $mediaFilename = null,
    ): ?Message {
        $to = $this->whatsAppNumber();

        if ($to === null) {
            return null;
        }

        return $this->messages()->create([
            'template_id' => null,
            'media_id' => $mediaId,
            'media_type' => $mediaId === null ? null : ($mediaType ?? 'image'),
            'media_filename' => $mediaFilename,
            'to' => $to,
            'body' => $body,
            'scheduled_at' => $scheduledAt,
            'status' => $scheduledAt === null
                ? MessageStatus::Queued
                : MessageStatus::Scheduled,
        ]);
    }

    // Normalizza il numero di telefono per WhatsApp
    public function whatsAppNumber(string $countryCode = '39'): ?string
    {
        if (blank($this->phone)) {
            return null;
        }

        // via tutto quello che non e una cifra: +, spazi, trattini, parentesi
        $digits = preg_replace('/\D/', '', $this->phone);

        if (blank($digits)) {
            return null;
        }

        // 0039... e come si scrive a mano il prefisso internazionale
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // 10 cifre che iniziano per 3 = cellulare italiano senza prefisso.
        // Va controllato prima, perche 393... e un prefisso TIM vero e
        // altrimenti lo scambieremmo per un numero gia completo.
        if (strlen($digits) === 10 && str_starts_with($digits, '3')) {
            return $countryCode.$digits;
        }

        // ha gia il prefisso internazionale
        if (str_starts_with($digits, $countryCode)) {
            return $digits;
        }

        // lo zero iniziale dei fissi in Italia si tiene
        return $countryCode.$digits;
    }
}
