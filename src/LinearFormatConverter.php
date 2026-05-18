<?php

namespace BootDesk\ChatSDK\Linear;

use BootDesk\ChatSDK\Core\Markdown\BaseFormatConverter;
use BootDesk\ChatSDK\Core\PostableMessage;
use League\CommonMark\Node\Block\Document;

class LinearFormatConverter extends BaseFormatConverter
{
    public function toAst(string $platformText): Document
    {
        return $this->parseMarkdown($platformText);
    }

    public function fromAst(Document $ast): string
    {
        return $this->renderMarkdown($ast);
    }

    public function renderPostable(PostableMessage $message): string
    {
        if ($message->isCard()) {
            return LinearCards::toLinearMarkdown($message->content);
        }

        return (string) $message->content;
    }
}
