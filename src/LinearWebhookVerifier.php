<?php

declare(strict_types=1);

namespace BootDesk\ChatSDK\Linear;

class LinearWebhookVerifier
{
    public function __construct(
        private readonly string $webhookSecret,
    ) {}

    public function verify(string $body, string $signature): bool
    {
        if ($signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $body, $this->webhookSecret);

        return hash_equals($expected, $signature);
    }
}
