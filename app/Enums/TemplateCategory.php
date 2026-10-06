<?php

namespace App\Enums;

enum TemplateCategory: string
{
    case Utility = 'utility';
    case Marketing = 'marketing';
    case Authentication = 'authentication';

    /**
     * Il valore che vuole Meta quando mandi il template in approvazione.
     */
    public function forMeta(): string
    {
        return strtoupper($this->value);
    }

    public function label(): string
    {
        return match ($this) {
            self::Utility => 'Utility — avvisi, scadenze, promemoria',
            self::Marketing => 'Marketing — promozioni, inviti, novità',
            self::Authentication => 'Authentication — codici di verifica',
        };
    }
}
