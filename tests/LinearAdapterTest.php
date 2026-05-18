<?php

namespace BootDesk\ChatSDK\Linear\Tests;

use BootDesk\ChatSDK\Core\Cards\Button;
use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Exceptions\AdapterException;
use BootDesk\ChatSDK\Core\Exceptions\AuthenticationException;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Linear\LinearAdapter;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class LinearAdapterTest extends TestCase
{
    private LinearAdapter $adapter;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory;

        $mockClient = new class implements ClientInterface
        {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $factory = new Psr17Factory;
                $body = (string) $request->getBody();
                $data = json_decode($body, true);
                $query = $data['query'] ?? '';

                // viewer query (initialize)
                if (str_contains($query, 'viewer { id displayName }')) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            'data' => ['viewer' => ['id' => 'bot-001', 'displayName' => 'ChatBot']],
                        ]))
                    );
                }

                // commentCreate
                if (str_contains($query, 'commentCreate')) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            'data' => ['commentCreate' => ['comment' => [
                                'id' => 'comment-42',
                                'createdAt' => '2024-01-01T00:00:00Z',
                            ]]],
                        ]))
                    );
                }

                // commentUpdate
                if (str_contains($query, 'commentUpdate')) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            'data' => ['commentUpdate' => ['comment' => [
                                'id' => 'comment-42',
                                'updatedAt' => '2024-01-02T00:00:00Z',
                            ]]],
                        ]))
                    );
                }

                // commentDelete
                if (str_contains($query, 'commentDelete')) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            'data' => ['commentDelete' => ['success' => true]],
                        ]))
                    );
                }

                // reactionCreate
                if (str_contains($query, 'reactionCreate')) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            'data' => ['reactionCreate' => ['reaction' => ['id' => 'rx-1']]],
                        ]))
                    );
                }

                // comment with reactions (for removeReaction lookup)
                if (str_contains($query, 'comment(id:') && str_contains($query, 'reactions')) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            'data' => ['comment' => ['reactions' => ['nodes' => [
                                ['id' => 'rx-1', 'emoji' => '👍'],
                                ['id' => 'rx-2', 'emoji' => '❤️'],
                            ]]]],
                        ]))
                    );
                }

                // reactionDelete
                if (str_contains($query, 'reactionDelete')) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            'data' => ['reactionDelete' => ['success' => true]],
                        ]))
                    );
                }

                // issue with comments (fetchMessages)
                if (str_contains($query, 'issue(id:') && str_contains($query, 'comments')) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            'data' => ['issue' => ['comments' => ['nodes' => [
                                ['id' => 'c1', 'body' => 'First', 'createdAt' => '2024-01-01', 'user' => ['id' => 'u1', 'name' => 'Dev', 'type' => 'user']],
                                ['id' => 'c2', 'body' => 'Second', 'createdAt' => '2024-01-02', 'user' => ['id' => 'bot-001', 'name' => 'Bot', 'type' => 'bot']],
                            ]]]],
                        ]))
                    );
                }

                // issue with title/commentCount (fetchThread)
                if (str_contains($query, 'issue(id:') && str_contains($query, 'commentCount')) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            'data' => ['issue' => ['id' => 'ISS-1', 'title' => 'Bug report', 'commentCount' => 5]],
                        ]))
                    );
                }

                // issue with team (fetchChannelInfo)
                if (str_contains($query, 'issue(id:') && str_contains($query, 'team')) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            'data' => ['issue' => ['id' => 'ISS-1', 'title' => 'Bug report', 'team' => ['name' => 'Engineering']]],
                        ]))
                    );
                }

                // user query
                if (str_contains($query, 'user(id:')) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            'data' => ['user' => ['id' => 'u-123', 'name' => 'Jane', 'displayName' => 'jane', 'email' => 'jane@example.com']],
                        ]))
                    );
                }

                return $factory->createResponse(200)->withBody(
                    $factory->createStream(json_encode([
                        'data' => ['viewer' => ['id' => 'fallback']],
                    ]))
                );
            }
        };

        $this->adapter = new LinearAdapter(
            apiKey: 'lin_api_test123',
            webhookSecret: 'test_webhook_secret',
            httpClient: $mockClient,
            psrFactory: $this->factory,
        );
    }

    // --- Construction ---

    public function test_get_name(): void
    {
        $this->assertSame('linear', $this->adapter->getName());
    }

    public function test_initialize_sets_bot_user_id(): void
    {
        $this->adapter->initialize($this->createMock(Chat::class));
        $this->assertSame('bot-001', $this->adapter->getBotUserId());
    }

    // --- Thread IDs ---

    public function test_encode_issue_thread(): void
    {
        $id = $this->adapter->encodeThreadId(['issueId' => 'ISS-1']);
        $this->assertSame('linear:ISS-1', $id);
    }

    public function test_encode_comment_thread(): void
    {
        $id = $this->adapter->encodeThreadId(['issueId' => 'ISS-1', 'commentId' => 'C-42']);
        $this->assertSame('linear:ISS-1:c:C-42', $id);
    }

    public function test_encode_session_thread(): void
    {
        $id = $this->adapter->encodeThreadId(['issueId' => 'ISS-1', 'agentSessionId' => 'S-99']);
        $this->assertSame('linear:ISS-1:s:S-99', $id);
    }

    public function test_encode_comment_session_thread(): void
    {
        $id = $this->adapter->encodeThreadId(['issueId' => 'ISS-1', 'commentId' => 'C-42', 'agentSessionId' => 'S-99']);
        $this->assertSame('linear:ISS-1:c:C-42:s:S-99', $id);
    }

    public function test_decode_issue_thread(): void
    {
        $decoded = $this->adapter->decodeThreadId('linear:ISS-1');
        $this->assertSame('ISS-1', $decoded['issueId']);
        $this->assertArrayNotHasKey('commentId', $decoded);
    }

    public function test_decode_comment_thread(): void
    {
        $decoded = $this->adapter->decodeThreadId('linear:ISS-1:c:C-42');
        $this->assertSame('ISS-1', $decoded['issueId']);
        $this->assertSame('C-42', $decoded['commentId']);
    }

    public function test_decode_session_thread(): void
    {
        $decoded = $this->adapter->decodeThreadId('linear:ISS-1:s:S-99');
        $this->assertSame('ISS-1', $decoded['issueId']);
        $this->assertSame('S-99', $decoded['agentSessionId']);
    }

    public function test_decode_comment_session_thread(): void
    {
        $decoded = $this->adapter->decodeThreadId('linear:ISS-1:c:C-42:s:S-99');
        $this->assertSame('ISS-1', $decoded['issueId']);
        $this->assertSame('C-42', $decoded['commentId']);
        $this->assertSame('S-99', $decoded['agentSessionId']);
    }

    public function test_decode_invalid_thread(): void
    {
        $this->expectException(AdapterException::class);
        $this->adapter->decodeThreadId('not-linear');
    }

    public function test_channel_id_from_thread(): void
    {
        $this->assertSame('linear:ISS-1', $this->adapter->channelIdFromThreadId('linear:ISS-1'));
        $this->assertSame('linear:ISS-1', $this->adapter->channelIdFromThreadId('linear:ISS-1:c:C-42'));
        $this->assertSame('linear:ISS-1', $this->adapter->channelIdFromThreadId('linear:ISS-1:s:S-99'));
    }

    // --- Webhook verification ---

    public function test_verify_valid_signature(): void
    {
        $body = '{"action":"create","type":"Comment"}';
        $hash = hash_hmac('sha256', $body, 'test_webhook_secret');

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withHeader('linear-signature', $hash)
            ->withBody($this->factory->createStream($body));

        $response = $this->adapter->verifyWebhook($request);
        $this->assertNull($response);
    }

    public function test_verify_invalid_signature(): void
    {
        $body = '{"action":"create"}';
        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withHeader('linear-signature', 'badhash')
            ->withBody($this->factory->createStream($body));

        $response = $this->adapter->verifyWebhook($request);
        $this->assertSame(403, $response->getStatusCode());
    }

    // --- Parse webhook ---

    public function test_parse_comment_webhook(): void
    {
        $body = json_encode([
            'action' => 'create',
            'type' => 'Comment',
            'data' => [
                'id' => 'comment-99',
                'body' => 'This looks good',
                'issueId' => 'ISS-1',
                'parentId' => null,
                'user' => ['id' => 'user-1', 'name' => 'Dev'],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);

        $this->assertSame('comment-99', $message->id);
        $this->assertSame('linear:ISS-1:c:comment-99', $message->threadId);
        $this->assertSame('user-1', $message->author->id);
        $this->assertSame('This looks good', $message->text);
        $this->assertFalse($message->isDM);
    }

    public function test_parse_reply_comment_uses_parent_as_root(): void
    {
        $body = json_encode([
            'action' => 'create',
            'type' => 'Comment',
            'data' => [
                'id' => 'reply-1',
                'body' => 'Reply text',
                'issueId' => 'ISS-2',
                'parentId' => 'root-comment',
                'user' => ['id' => 'user-1', 'name' => 'Dev'],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);

        // Thread ID uses parentId as the root comment
        $this->assertSame('linear:ISS-2:c:root-comment', $message->threadId);
        $this->assertSame('reply-1', $message->id);
    }

    public function test_parse_invalid_payload(): void
    {
        $this->expectException(AdapterException::class);

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream('not json'));

        $this->adapter->parseWebhook($request);
    }

    public function test_parse_unsupported_event(): void
    {
        $this->expectException(AdapterException::class);

        $body = json_encode(['action' => 'update', 'type' => 'Issue']);
        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream($body));

        $this->adapter->parseWebhook($request);
    }

    public function test_parse_comment_missing_issue(): void
    {
        $this->expectException(AdapterException::class);

        $body = json_encode([
            'action' => 'create',
            'type' => 'Comment',
            'data' => [
                'id' => 'c1',
                'body' => 'test',
                'user' => ['id' => 'u1'],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream($body));

        $this->adapter->parseWebhook($request);
    }

    // --- Message operations ---

    public function test_post_message(): void
    {
        $sent = $this->adapter->postMessage('linear:ISS-1', PostableMessage::text('Hello'));

        $this->assertSame('comment-42', $sent->id);
        $this->assertSame('linear:ISS-1', $sent->threadId);
    }

    public function test_post_message_with_parent(): void
    {
        $sent = $this->adapter->postMessage('linear:ISS-1:c:C-10', PostableMessage::text('Reply'));

        $this->assertSame('comment-42', $sent->id);
    }

    public function test_post_message_with_card(): void
    {
        $card = Card::make()
            ->header('Deploy Ready')
            ->section(fn ($s) => $s->text('Build passed'))
            ->actions([Button::primary('Deploy', 'deploy')]);

        $sent = $this->adapter->postMessage('linear:ISS-1', PostableMessage::card($card));

        $this->assertSame('comment-42', $sent->id);
    }

    public function test_edit_message(): void
    {
        $sent = $this->adapter->editMessage('linear:ISS-1', 'comment-42', PostableMessage::text('Updated'));

        $this->assertSame('comment-42', $sent->id);
    }

    public function test_edit_agent_session_throws(): void
    {
        $this->expectException(AdapterException::class);
        $this->adapter->editMessage('linear:ISS-1:s:S-99', 'activity-1', PostableMessage::text('x'));
    }

    public function test_delete_message(): void
    {
        $this->adapter->deleteMessage('linear:ISS-1', 'comment-42');
        $this->assertTrue(true);
    }

    public function test_delete_agent_session_throws(): void
    {
        $this->expectException(AdapterException::class);
        $this->adapter->deleteMessage('linear:ISS-1:s:S-99', 'activity-1');
    }

    // --- Reactions ---

    public function test_add_reaction(): void
    {
        $this->adapter->addReaction('linear:ISS-1', 'comment-42', '👍');
        $this->assertTrue(true);
    }

    public function test_remove_reaction(): void
    {
        $this->adapter->removeReaction('linear:ISS-1', 'comment-42', '👍');
        $this->assertTrue(true);
    }

    // --- Stream ---

    public function test_stream_collects_and_posts(): void
    {
        $sent = $this->adapter->stream('linear:ISS-1', ['Hello ', 'World']);
        $this->assertNotNull($sent);
        $this->assertSame('comment-42', $sent->id);
    }

    public function test_stream_empty_returns_null(): void
    {
        $this->assertNull($this->adapter->stream('linear:ISS-1', []));
    }

    // --- Fetch operations ---

    public function test_fetch_messages(): void
    {
        $result = $this->adapter->fetchMessages('linear:ISS-1');
        $this->assertCount(2, $result->messages);
        $this->assertSame('c1', $result->messages[0]->id);
        $this->assertSame('First', $result->messages[0]->text);
        $this->assertFalse($result->messages[0]->author->isBot);
        $this->assertTrue($result->messages[1]->author->isBot);
    }

    public function test_fetch_thread(): void
    {
        $info = $this->adapter->fetchThread('linear:ISS-1');
        $this->assertSame('linear:ISS-1', $info->id);
        $this->assertSame(5, $info->messageCount);
    }

    public function test_fetch_channel_info(): void
    {
        $info = $this->adapter->fetchChannelInfo('linear:ISS-1');
        $this->assertSame('linear:ISS-1', $info->id);
        $this->assertSame('Bug report', $info->name);
    }

    public function test_fetch_channel_info_invalid(): void
    {
        $this->assertNull($this->adapter->fetchChannelInfo('invalid'));
    }

    // --- User ---

    public function test_get_user(): void
    {
        $user = $this->adapter->getUser('u-123');
        $this->assertSame('u-123', $user->id);
        $this->assertSame('jane', $user->name);
    }

    // --- Misc ---

    public function test_open_dm_returns_null(): void
    {
        $this->assertNull($this->adapter->openDM('user-1'));
    }

    public function test_start_typing_is_noop(): void
    {
        $this->adapter->startTyping('linear:ISS-1');
        $this->assertTrue(true);
    }

    public function test_get_format_converter(): void
    {
        $this->assertNotNull($this->adapter->getFormatConverter());
    }

    public function test_disconnect_is_noop(): void
    {
        $this->adapter->disconnect();
        $this->assertTrue(true);
    }

    public function test_create_response_returns_null(): void
    {
        $this->assertNull($this->adapter->createResponse());
    }

    public function test_api_call_throws_authentication_exception_on_auth_error(): void
    {
        $factory = new Psr17Factory;
        $mockClient = new class implements ClientInterface
        {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $f = new Psr17Factory;

                return $f->createResponse(401)->withBody(
                    $f->createStream(json_encode(['errors' => [['message' => 'Authentication required', 'extensions' => ['code' => 'AUTHENTICATION_ERROR']]]]))
                );
            }
        };

        $adapter = new LinearAdapter(
            apiKey: 'lin_api_bad',
            webhookSecret: 'secret',
            httpClient: $mockClient,
            psrFactory: $factory,
        );

        $this->expectException(AuthenticationException::class);
        $adapter->postMessage('linear:ISS-1', PostableMessage::text('test'));
    }
}
