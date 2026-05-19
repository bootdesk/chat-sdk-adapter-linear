<?php

namespace BootDesk\ChatSDK\Linear;

use BootDesk\ChatSDK\Core\Author;
use BootDesk\ChatSDK\Core\ChannelInfo;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\Contracts\FileUploadConverter;
use BootDesk\ChatSDK\Core\Contracts\FormatConverter;
use BootDesk\ChatSDK\Core\Contracts\SupportsDeleteMessages;
use BootDesk\ChatSDK\Core\Contracts\SupportsEditMessages;
use BootDesk\ChatSDK\Core\Exceptions\AdapterException;
use BootDesk\ChatSDK\Core\Exceptions\AuthenticationException;
use BootDesk\ChatSDK\Core\FetchOptions;
use BootDesk\ChatSDK\Core\FetchResult;
use BootDesk\ChatSDK\Core\Message;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Core\SentMessage;
use BootDesk\ChatSDK\Core\Support\NullFileUploadConverter;
use BootDesk\ChatSDK\Core\ThreadInfo;
use BootDesk\ChatSDK\Core\UserInfo;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class LinearAdapter implements Adapter, SupportsDeleteMessages, SupportsEditMessages
{
    protected ?string $botUserId = null;

    protected LinearFormatConverter $formatConverter;

    protected LinearWebhookVerifier $webhookVerifier;

    protected FileUploadConverter $fileUploadConverter;

    public function __construct(
        protected readonly string $apiKey,
        protected readonly ClientInterface $httpClient,
        string $webhookSecret,
        protected readonly string $apiUrl = 'https://api.linear.app/graphql',
        protected readonly ?Psr17Factory $psrFactory = null,
        ?FileUploadConverter $fileUploadConverter = null,
    ) {
        $this->formatConverter = new LinearFormatConverter;
        $this->webhookVerifier = new LinearWebhookVerifier($webhookSecret);
        $this->fileUploadConverter = $fileUploadConverter ?? new NullFileUploadConverter;
    }

    public function getName(): string
    {
        return 'linear';
    }

    public function getBotUserId(): ?string
    {
        return $this->botUserId;
    }

    public function verifyWebhook(ServerRequestInterface $request): ?ResponseInterface
    {
        $body = (string) $request->getBody();
        $signature = $request->getHeaderLine('linear-signature');

        if (! $this->webhookVerifier->verify($body, $signature)) {
            return $this->jsonResponse(403, 'Invalid signature');
        }

        return null;
    }

    public function parseWebhook(ServerRequestInterface $request): Message
    {
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        if ($payload === null || ! isset($payload['action'], $payload['type'])) {
            throw new AdapterException('Invalid Linear webhook payload');
        }

        $type = $payload['type'];
        $action = $payload['action'];

        if ($type === 'Comment' && $action === 'create') {
            return $this->parseComment($payload, $body);
        }

        throw new AdapterException("Unsupported Linear webhook event: {$type}.{$action}");
    }

    public function encodeThreadId(mixed $platformData): string
    {
        $issueId = $platformData['issueId'] ?? '';
        $commentId = $platformData['commentId'] ?? null;
        $agentSessionId = $platformData['agentSessionId'] ?? null;

        if ($agentSessionId !== null && $commentId !== null) {
            return "linear:{$issueId}:c:{$commentId}:s:{$agentSessionId}";
        }

        if ($agentSessionId !== null) {
            return "linear:{$issueId}:s:{$agentSessionId}";
        }

        if ($commentId !== null) {
            return "linear:{$issueId}:c:{$commentId}";
        }

        return "linear:{$issueId}";
    }

    public function decodeThreadId(string $threadId): mixed
    {
        if (! str_starts_with($threadId, 'linear:')) {
            throw new AdapterException("Invalid Linear thread ID: {$threadId}");
        }

        $withoutPrefix = substr($threadId, 7);

        // issueId:c:commentId:s:agentSessionId
        if (preg_match('/^([^:]+):c:([^:]+):s:([^:]+)$/', $withoutPrefix, $m)) {
            return ['issueId' => $m[1], 'commentId' => $m[2], 'agentSessionId' => $m[3]];
        }

        // issueId:s:agentSessionId
        if (preg_match('/^([^:]+):s:([^:]+)$/', $withoutPrefix, $m)) {
            return ['issueId' => $m[1], 'agentSessionId' => $m[2]];
        }

        // issueId:c:commentId
        if (preg_match('/^([^:]+):c:([^:]+)$/', $withoutPrefix, $m)) {
            return ['issueId' => $m[1], 'commentId' => $m[2]];
        }

        // issueId (bare)
        if ($withoutPrefix !== '' && ! str_contains($withoutPrefix, ':')) {
            return ['issueId' => $withoutPrefix];
        }

        throw new AdapterException("Invalid Linear thread ID: {$threadId}");
    }

    public function channelIdFromThreadId(string $threadId): string
    {
        $decoded = $this->decodeThreadId($threadId);

        return "linear:{$decoded['issueId']}";
    }

    public function postMessage(string $threadId, PostableMessage $message): SentMessage
    {
        // Convert files to attachments via the registered converter
        if ($message->files !== []) {
            $converted = [];
            foreach ($message->files as $file) {
                $converted[] = $this->fileUploadConverter->upload($file, $this);
            }
            $message = new PostableMessage(
                content: $message->content,
                replyToMessageId: $message->replyToMessageId,
                attachments: array_merge($message->attachments, $converted),
            );
        }

        $decoded = $this->decodeThreadId($threadId);
        $body = $this->renderBody($message);
        $body = $this->appendAttachments($body, $message);

        $result = $this->graphqlMutation(
            'commentCreate',
            'commentCreate(input: $input) { comment { id createdAt } }',
            ['input' => [
                'issueId' => $decoded['issueId'],
                'body' => $body,
                'parentId' => $decoded['commentId'] ?? null,
            ]],
        );

        $comment = $result['comment'] ?? [];

        return new SentMessage(
            id: $comment['id'] ?? '',
            threadId: $threadId,
            timestamp: $comment['createdAt'] ?? '',
        );
    }

    public function editMessage(string $threadId, string $messageId, PostableMessage $message): SentMessage
    {
        $decoded = $this->decodeThreadId($threadId);

        if (isset($decoded['agentSessionId'])) {
            throw new AdapterException('Cannot edit agent session activities');
        }

        $body = $this->renderBody($message);

        $this->graphqlMutation(
            'commentUpdate',
            'commentUpdate(id: $id, input: $input) { comment { id updatedAt } }',
            ['id' => $messageId, 'input' => ['body' => $body]],
        );

        return new SentMessage(
            id: $messageId,
            threadId: $threadId,
            timestamp: '',
        );
    }

    public function deleteMessage(string $threadId, string $messageId): void
    {
        $decoded = $this->decodeThreadId($threadId);

        if (isset($decoded['agentSessionId'])) {
            throw new AdapterException('Cannot delete agent session activities');
        }

        $this->graphqlMutation(
            'commentDelete',
            'commentDelete(id: $id) { success }',
            ['id' => $messageId],
        );
    }

    public function addReaction(string $threadId, string $messageId, string $emoji): void
    {
        $this->graphqlMutation(
            'reactionCreate',
            'reactionCreate(input: $input) { reaction { id } }',
            ['input' => ['commentId' => $messageId, 'emoji' => $emoji]],
        );
    }

    public function removeReaction(string $threadId, string $messageId, string $emoji): void
    {
        // Requires finding the reaction ID first
        $result = $this->graphqlQuery(
            'commentReactions',
            'comment(id: $id) { reactions { nodes { id emoji } } }',
            ['id' => $messageId],
        );

        $nodes = $result['reactions']['nodes'] ?? [];
        foreach ($nodes as $reaction) {
            if (($reaction['emoji'] ?? '') === $emoji) {
                $this->graphqlMutation(
                    'reactionDelete',
                    'reactionDelete(id: $id) { success }',
                    ['id' => $reaction['id']],
                );

                return;
            }
        }
    }

    public function startTyping(string $threadId): void
    {
        // Linear has no typing indicator
    }

    public function fetchMessages(string $threadId, ?FetchOptions $options = null): FetchResult
    {
        $decoded = $this->decodeThreadId($threadId);

        $result = $this->graphqlQuery(
            'issueComments',
            'issue(id: $id) { comments { nodes { id body createdAt user { id name type } } } }',
            ['id' => $decoded['issueId']],
        );

        $messages = [];
        foreach ($result['comments']['nodes'] ?? [] as $comment) {
            $messages[] = new Message(
                id: $comment['id'],
                threadId: $threadId,
                author: new Author(
                    id: $comment['user']['id'] ?? '',
                    isBot: ($comment['user']['type'] ?? '') === 'bot',
                ),
                text: $comment['body'] ?? '',
                isDM: false,
                raw: json_encode($comment),
            );
        }

        return new FetchResult(messages: $messages);
    }

    public function fetchThread(string $threadId): ThreadInfo
    {
        $decoded = $this->decodeThreadId($threadId);

        $result = $this->graphqlQuery(
            'issueInfo',
            'issue(id: $id) { id title commentCount }',
            ['id' => $decoded['issueId']],
        );

        return new ThreadInfo(
            id: $threadId,
            channelId: $this->channelIdFromThreadId($threadId),
            messageCount: $result['commentCount'] ?? 0,
        );
    }

    public function fetchChannelInfo(string $channelId): ?ChannelInfo
    {
        if (! str_starts_with($channelId, 'linear:')) {
            return null;
        }

        $issueId = substr($channelId, 7);

        $result = $this->graphqlQuery(
            'issueInfo',
            'issue(id: $id) { id title team { name } }',
            ['id' => $issueId],
        );

        return new ChannelInfo(
            id: $channelId,
            name: $result['title'] ?? $channelId,
            isPrivate: false,
        );
    }

    public function getUser(string $userId): ?UserInfo
    {
        $result = $this->graphqlQuery(
            'userInfo',
            'user(id: $id) { id name displayName email }',
            ['id' => $userId],
        );

        if ($result === null) {
            return null;
        }

        return new UserInfo(
            id: $result['id'] ?? $userId,
            name: $result['displayName'] ?? ($result['name'] ?? $userId),
        );
    }

    public function openDM(string $userId): ?string
    {
        return null;
    }

    public function getFormatConverter(): ?FormatConverter
    {
        return $this->formatConverter;
    }

    public function initialize(Chat $chat): void
    {
        try {
            $result = $this->graphqlQuery(
                'viewer',
                'viewer { id displayName }',
                [],
            );

            $this->botUserId = $result['id'] ?? null;
        } catch (AdapterException) {
            // Bot identity unavailable
        }
    }

    public function disconnect(): void
    {
        // No persistent connection
    }

    public function createResponse(): ?ResponseInterface
    {
        return null;
    }

    public function stream(string $threadId, iterable $textStream, array $options = []): ?SentMessage
    {
        $fullText = '';
        foreach ($textStream as $chunk) {
            $fullText .= $chunk;
        }

        if ($fullText === '') {
            return null;
        }

        return $this->postMessage($threadId, PostableMessage::text($fullText));
    }

    protected function renderBody(PostableMessage $message): string
    {
        if ($message->isCard()) {
            return LinearCards::toLinearMarkdown($message->content);
        }

        return $this->formatConverter->renderPostable($message);
    }

    protected function appendAttachments(string $body, PostableMessage $message): string
    {
        $lines = [];

        foreach ($message->attachments as $att) {
            $name = $att->name ?? 'Attachment';
            $lines[] = $att->url !== null
                ? "[{$name}]({$att->url})"
                : $name;
        }

        if ($lines === []) {
            return $body;
        }

        return $body !== '' ? $body."\n\n".implode("\n", $lines) : implode("\n", $lines);
    }

    protected function graphqlQuery(string $alias, string $queryBody, array $variables): ?array
    {
        $varDefs = [];
        $varUses = [];
        foreach (array_keys($variables) as $key) {
            $type = $key === 'id' ? 'String!' : 'String';
            $varDefs[] = "\${$key}: {$type}";
            $varUses[] = "{$key}: \${$key}";
        }

        $varDefStr = $varDefs === [] ? '' : '('.implode(', ', $varDefs).')';

        $query = "query {$alias}{$varDefStr} { {$queryBody} }";

        $data = $this->graphqlRequest($query, $variables);

        // Extract the first key from data (the query alias/field name)
        if ($data !== null) {
            $keys = array_keys($data);
            if ($keys !== []) {
                return $data[$keys[0]];
            }
        }

        return $data;
    }

    protected function graphqlMutation(string $alias, string $mutationBody, array $input): ?array
    {
        $varDefs = [];
        foreach (array_keys($input) as $key) {
            $varDefs[] = "\${$key}: String";
        }

        $varDefStr = $varDefs === [] ? '' : '('.implode(', ', $varDefs).')';

        $mutation = "mutation {$alias}{$varDefStr} { {$mutationBody} }";

        $data = $this->graphqlRequest($mutation, $input);

        // Extract the first key from data (the mutation field name)
        if ($data !== null) {
            $keys = array_keys($data);
            if ($keys !== []) {
                return $data[$keys[0]];
            }
        }

        return $data;
    }

    protected function graphqlRequest(string $query, array $variables): ?array
    {
        $factory = $this->psrFactory ?? new Psr17Factory;

        $body = json_encode(array_filter([
            'query' => $query,
            'variables' => $variables === [] ? null : $variables,
        ], fn ($v): bool => $v !== null));

        $request = $factory->createRequest('POST', $this->apiUrl)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Authorization', $this->apiKey)
            ->withBody($factory->createStream($body));

        $psrResponse = $this->httpClient->sendRequest($request);
        $responseBody = (string) $psrResponse->getBody();
        $statusCode = $psrResponse->getStatusCode();

        $data = json_decode($responseBody, true);

        if ($data === null) {
            return null;
        }

        if (isset($data['errors'])) {
            $messages = array_column($data['errors'], 'message');
            $extensions = $data['errors'][0]['extensions'] ?? [];

            if (in_array($statusCode, [401, 403], true) || ($extensions['code'] ?? '') === 'AUTHENTICATION_ERROR') {
                throw new AuthenticationException('Linear API authentication error: '.implode('; ', $messages));
            }

            throw new AdapterException('Linear API error: '.implode('; ', $messages));
        }

        return $data['data'] ?? null;
    }

    protected function parseComment(array $payload, string $rawBody): Message
    {
        $data = $payload['data'] ?? [];

        if (empty($data['issueId']) || empty($data['user'])) {
            throw new AdapterException('Incomplete Linear comment webhook data');
        }

        $rootCommentId = $data['parentId'] ?? $data['id'];
        $threadId = $this->encodeThreadId([
            'issueId' => $data['issueId'],
            'commentId' => $rootCommentId,
        ]);

        return new Message(
            id: $data['id'] ?? '',
            threadId: $threadId,
            author: new Author(
                id: $data['user']['id'] ?? '',
                isBot: false,
            ),
            text: $data['body'] ?? '',
            isDM: false,
            raw: $rawBody,
        );
    }

    protected function jsonResponse(int $status, string $message): ResponseInterface
    {
        $factory = $this->psrFactory ?? new Psr17Factory;

        return $factory->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream(json_encode(['error' => $message])));
    }
}
