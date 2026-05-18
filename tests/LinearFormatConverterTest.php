<?php

namespace BootDesk\ChatSDK\Linear\Tests;

use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Linear\LinearFormatConverter;
use PHPUnit\Framework\TestCase;

class LinearFormatConverterTest extends TestCase
{
    private LinearFormatConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new LinearFormatConverter;
    }

    public function test_to_ast(): void
    {
        $ast = $this->converter->toAst('Hello **world**');
        $this->assertNotNull($ast);
    }

    public function test_from_ast(): void
    {
        $ast = $this->converter->toAst('Hello');
        $result = $this->converter->fromAst($ast);
        $this->assertStringContainsString('Hello', $result);
    }

    public function test_render_postable_card(): void
    {
        $card = Card::make()->header('Test');
        $message = PostableMessage::card($card);
        $result = $this->converter->renderPostable($message);
        $this->assertStringContainsString('Test', $result);
    }

    public function test_render_postable_text(): void
    {
        $message = PostableMessage::text('Plain');
        $result = $this->converter->renderPostable($message);
        $this->assertSame('Plain', $result);
    }
}
