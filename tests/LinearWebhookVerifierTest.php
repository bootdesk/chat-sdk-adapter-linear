<?php

namespace BootDesk\ChatSDK\Linear\Tests;

use BootDesk\ChatSDK\Linear\LinearWebhookVerifier;
use PHPUnit\Framework\TestCase;

class LinearWebhookVerifierTest extends TestCase
{
    private LinearWebhookVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new LinearWebhookVerifier('my_linear_secret');
    }

    public function test_valid_signature(): void
    {
        $body = '{"action":"create","type":"Comment"}';
        $hash = hash_hmac('sha256', $body, 'my_linear_secret');

        $this->assertTrue($this->verifier->verify($body, $hash));
    }

    public function test_invalid_signature(): void
    {
        $this->assertFalse($this->verifier->verify('body', 'badhash'));
    }

    public function test_empty_signature(): void
    {
        $this->assertFalse($this->verifier->verify('body', ''));
    }

    public function test_wrong_secret(): void
    {
        $body = '{"action":"create"}';
        $hash = hash_hmac('sha256', $body, 'wrong_secret');

        $this->assertFalse($this->verifier->verify($body, $hash));
    }
}
