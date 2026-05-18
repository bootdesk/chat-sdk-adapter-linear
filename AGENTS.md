# adapter-linear

Linear adapter for bootdesk/chat-sdk-core. Namespace: `BootDesk\ChatSDK\Linear`

## files
- `LinearAdapter` — implements `Adapter` using Linear GraphQL API
- `LinearFormatConverter` — Linear markdown ↔ CommonMark AST
- `LinearCards` — Card model → Linear rich text (JSON content)
- `LinearWebhookVerifier` — HMAC-SHA256 signature verification

## registration
`src/register.php` registers `'linear' => LinearAdapter::class` via `AdapterRegistry`

## constructor
```php
new LinearAdapter(
    string $apiKey,
    ClientInterface $httpClient,
    string $webhookSecret,
    ?Psr17Factory $psrFactory = null,
);
```

## thread ID format
`linear:{issueId}` — e.g. `linear:ENG-123` (uses issue identifier or ID)

## webhook flow
1. `verifyWebhook` — verifies `linear-signature` header HMAC
2. `parseWebhook` — handles `Issue` create/update with comment; extracts `Comment` payload

## features
- Post comments on issues (via GraphQL `commentCreate` mutation)
- Fetch issue info, comments
- No DM support (returns null)
- No reaction support
- No typing indicators
- Streaming: accumulates and posts as single comment
- GraphQL API: single endpoint `https://api.linear.app/graphql`

## config (laravel)
```php
'linear' => [
    'api_key' => env('LINEAR_API_KEY'),
    'webhook_secret' => env('LINEAR_WEBHOOK_SECRET'),
],
```
