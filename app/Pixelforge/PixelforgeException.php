<?php

namespace App\Pixelforge;

use RuntimeException;

class PixelforgeException extends RuntimeException
{
    /** Codes where waiting and sending the same request again can succeed. */
    private const RETRYABLE = [
        'overloaded',
        'rate_limit_exceeded',
        'concurrency_limit_exceeded',
        'connection_error',
        'server_error',
    ];

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?int $status = null,
        public readonly ?int $retryAfter = null,
        public readonly ?string $param = null,
    ) {
        parent::__construct($message);
    }

    public function isRetryable(): bool
    {
        return in_array($this->errorCode, self::RETRYABLE, true);
    }

    /** A message that is safe and useful to show to the user. */
    public function userMessage(): string
    {
        return match ($this->errorCode) {
            'content_policy_violation' => 'Pixelforge rejected this prompt under its content policy. You were not charged.',
            'daily_limit_exceeded' => 'The daily generation limit has been reached. Please try again after midnight UTC. You were not charged.',
            'overloaded' => 'Pixelforge is overloaded right now.',
            'concurrency_limit_exceeded' => 'Too many images are being generated at once.',
            'rate_limit_exceeded' => 'Too many requests to Pixelforge this minute.',
            'connection_error', 'server_error' => 'We could not reach Pixelforge.',
            default => $this->getMessage(),
        };
    }
}
