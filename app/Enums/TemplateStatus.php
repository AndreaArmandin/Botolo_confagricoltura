<?php

namespace App\Enums;

enum TemplateStatus: string
{
    /** Solo nostro: scritto qui, mai mandato a Meta. */
    case Draft = 'draft';

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /** Meta puo disabilitare un template gia approvato, per qualita o segnalazioni. */
    case Disabled = 'disabled';

    /**
     * Solo un template approvato puo iniziare una conversazione.
     */
    public function isSendable(): bool
    {
        return $this === self::Approved;
    }

    /**
     * Traduce lo stato che arriva da Meta nel nostro.
     */
    public static function fromMeta(?string $status): self
    {
        return match (strtoupper((string) $status)) {
            'APPROVED' => self::Approved,
            'REJECTED' => self::Rejected,
            'DISABLED', 'PAUSED' => self::Disabled,
            'PENDING', 'IN_APPEAL', 'PENDING_DELETION', 'SUBMITTED' => self::Pending,
            default => self::Pending,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Pending => 'warning',
            self::Disabled => 'danger',
            self::Draft => 'gray',
        };
    }
}
