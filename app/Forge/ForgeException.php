<?php

declare(strict_types=1);

namespace App\Forge;

use Illuminate\Http\Client\Response;
use RuntimeException;

class ForgeException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0)
    {
        parent::__construct($message, $status);
    }

    public static function fromResponse(Response $response, string $action, ?string $token = null): self
    {
        $status = $response->status();

        $message = match (true) {
            $status === 401 => 'Forge rejected the API token (401). Reconnect in the Forge MCP dashboard.',
            $status === 403 => "Forge denied {$action} (403). The Forge API token may be missing a required scope.",
            $status === 404 => "Forge could not find the requested resource (404) while trying to {$action}.",
            $status === 422 => "Forge rejected the request to {$action} (422): ".self::validationMessage($response),
            $status === 429 => self::rateLimitMessage($response),
            default => "Forge returned an unexpected error ({$status}) while trying to {$action}.",
        };

        if ($token !== null && $token !== '') {
            $message = str_replace($token, '[redacted]', $message);
        }

        return new self($message, $status);
    }

    public static function unreachable(string $action): self
    {
        return new self("Could not reach Forge while trying to {$action}. Try again shortly.");
    }

    public function agentMessage(): string
    {
        return $this->getMessage();
    }

    private static function validationMessage(Response $response): string
    {
        $errors = $response->json('errors');

        if (is_array($errors)) {
            foreach ($errors as $messages) {
                $first = is_array($messages) ? ($messages[0] ?? null) : $messages;
                if (is_string($first) && $first !== '') {
                    return $first;
                }
            }
        }

        $message = $response->json('message');

        return is_string($message) && $message !== '' ? $message : 'The request was invalid.';
    }

    private static function rateLimitMessage(Response $response): string
    {
        $seconds = null;

        if (is_numeric($retryAfter = $response->header('Retry-After'))) {
            $seconds = (int) $retryAfter;
        } elseif (is_numeric($reset = $response->header('X-RateLimit-Reset'))) {
            // Either a unix timestamp or a number of seconds.
            $seconds = (int) $reset > 1_000_000_000 ? (int) $reset - time() : (int) $reset;
        }

        $message = 'Forge rate limit reached (429).';

        return $seconds !== null
            ? $message.' Retry in '.max(1, $seconds).' seconds.'
            : $message.' Retry shortly.';
    }
}
