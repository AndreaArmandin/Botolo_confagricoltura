<?php

namespace App\Enums;

enum MessageStatus: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';
    case Scheduled = 'scheduled';

    /**
     * Il messaggio e uscito da qui ed e in mano a WhatsApp.
     */
    public function isHandedOver(): bool
    {
        return in_array($this, [self::Sent, self::Delivered, self::Read], strict: true);
    }
}
