<?php

namespace App\Integrations\Kapso;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;

class KapsoException extends RuntimeException
{
    public static function fromResponse(Response $response): self
    {
        $message = $response->json('error')
            ?? $response->json('error.message')
            ?? $response->body();

        if (is_array($message)) {
            $message = json_encode($message);
        }

        return new self(sprintf('Kapso %d: %s', $response->status(), Str::of((string) $message)->limit(300)), $response->status());
    }
}
