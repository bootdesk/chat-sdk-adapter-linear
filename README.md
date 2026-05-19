# bootdesk/chat-sdk-adapter-linear

Linear adapter for the laravel-bootdesk multi-platform messaging framework.

## Install

```bash
composer require bootdesk/chat-sdk-adapter-linear
```

Requires a PSR-18 HTTP client (`guzzlehttp/guzzle`, `symfony/http-client`, etc.) and a PSR-17 factory (`nyholm/psr7` bundled).

## Configuration

| Variable | Description | Example |
|----------|-------------|---------|
| `api_key` | Linear API Key | `lin_api_abc123...` |
| `http_client` | PSR-18 HTTP client instance | `new GuzzleHttp\Client` |
| `webhook_secret` | Webhook Secret | `my-secret...` |

```php
use BootDesk\ChatSDK\Linear\LinearAdapter;

$adapter = new LinearAdapter(
    apiKey: env('LINEAR_API_KEY'),
    httpClient: new \GuzzleHttp\Client,
    webhookSecret: env('LINEAR_WEBHOOK_SECRET'),
);
```

### Laravel

The `ChatServiceProvider` auto-binds `Psr\Http\Client\ClientInterface` to `GuzzleHttp\Client`. Add to `config/chat.php`:

```php
'linear' => [
    'api_key'        => env('LINEAR_API_KEY'),
    'webhook_secret' => env('LINEAR_WEBHOOK_SECRET'),
],
```

## Quick Example

```php
// Post a comment on an issue
$adapter->postMessage('linear:ABC-123', 'Looking into this.');

// Post in a comment thread
$adapter->postMessage('linear:ABC-123:c:456', 'Reply to comment.');

// Post in an agent session
$adapter->postMessage('linear:ABC-123:s:session-xyz', 'Agent update.');
```

## Thread ID Format

| Format | Description |
|--------|-------------|
| `linear:{issueId}` | Bare issue |
| `linear:{issueId}:c:{commentId}` | Comment thread |
| `linear:{issueId}:s:{agentSessionId}` | Agent session |
| `linear:{issueId}:c:{commentId}:s:{agentSessionId}` | Comment + session |

## Webhook

Linear sends webhook events to your endpoint. Verify requests using HMAC-SHA256 verification via the `linear-signature` header.

**API endpoint:** `https://api.linear.app/graphql`

**Handled events:** `Comment.create`

## Feature Matrix

| Feature | Supported |
|---------|-----------|
| Post messages | ✓ |
| Edit messages | ✓ |
| Delete messages | ✓ |
| Reactions | ✓ |
| Typing indicator | ✗ |
| Fetch messages | ✓ |
| Fetch thread info | ✓ |
| Fetch channel info | ✓ |
| Get user | ✓ |
| Open DM | ✗ |
| Stream | ✓ |

## Notes

Uses the Linear GraphQL API. Bot identity is resolved via the `viewer` query on initialization. Card messages are rendered as Linear-flavored markdown. Edit and delete are not supported for agent sessions.

## Documentationn
Full API documentation: https://bootdesk.github.io/chat-sdk

## License

MIT
