<?php

namespace BootDesk\ChatSDK\Linear\Tests;

use BootDesk\ChatSDK\Core\Cards\Button;
use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Linear\LinearCards;
use PHPUnit\Framework\TestCase;

class LinearCardsTest extends TestCase
{
    public function test_card_to_markdown_with_header(): void
    {
        $card = Card::make()->header('Deploy Ready')->section(fn ($s) => $s->text('Build passed'));

        $md = LinearCards::toLinearMarkdown($card);

        $this->assertStringContainsString('**Deploy Ready**', $md);
        $this->assertStringContainsString('Build passed', $md);
    }

    public function test_card_to_markdown_with_fields(): void
    {
        $card = Card::make()->section(fn ($s) => $s->fields(['Status' => 'passing', 'Branch' => 'main']));

        $md = LinearCards::toLinearMarkdown($card);

        $this->assertStringContainsString('**Status:** passing', $md);
        $this->assertStringContainsString('**Branch:** main', $md);
    }

    public function test_card_to_markdown_with_buttons(): void
    {
        $card = Card::make()
            ->header('Actions')
            ->actions([Button::primary('Deploy', 'deploy'), Button::secondary('Cancel', 'cancel')]);

        $md = LinearCards::toLinearMarkdown($card);

        $this->assertStringContainsString('Deploy', $md);
        $this->assertStringContainsString(' • ', $md);
    }

    public function test_card_to_plain_text(): void
    {
        $card = Card::make()->header('Title')->section(fn ($s) => $s->text('Body'));

        $text = LinearCards::toPlainText($card);

        $this->assertStringContainsString('Title', $text);
        $this->assertStringContainsString('Body', $text);
    }

    public function test_escape_markdown(): void
    {
        $this->assertSame('hello\\*world', LinearCards::escapeMarkdown('hello*world'));
        $this->assertSame('\\[link\\]', LinearCards::escapeMarkdown('[link]'));
    }

    public function test_empty_card(): void
    {
        $this->assertSame('', LinearCards::toLinearMarkdown(Card::make()));
    }
}
