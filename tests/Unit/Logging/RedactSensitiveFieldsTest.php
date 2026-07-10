<?php

declare(strict_types=1);

use App\Logging\RedactSensitiveFields;
use Monolog\Level;
use Monolog\LogRecord;

/*
 * log context / extra の秘匿 field が [REDACTED] に置換されることを固定する。
 * 対象キー: password / token / access_token / refresh_token / api_key / secret / private_key。
 */

function makeLogRecord(array $context, array $extra = []): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'test',
        level: Level::Info,
        message: 'test',
        context: $context,
        extra: $extra,
    );
}

beforeEach(function (): void {
    $this->processor = new RedactSensitiveFields;
});

it('redacts password / token / access_token / refresh_token / api_key / secret / private_key', function (): void {
    $record = makeLogRecord([
        'user_id' => 42,
        'password' => 'plaintext',
        'token' => 'raw_secret',
        'access_token' => 'at',
        'refresh_token' => 'rt',
        'api_key' => 'ak',
        'secret' => 's',
        'private_key' => 'pk',
    ]);

    $processed = ($this->processor)($record);

    expect($processed->context)->toBe([
        'user_id' => 42,
        'password' => '[REDACTED]',
        'token' => '[REDACTED]',
        'access_token' => '[REDACTED]',
        'refresh_token' => '[REDACTED]',
        'api_key' => '[REDACTED]',
        'secret' => '[REDACTED]',
        'private_key' => '[REDACTED]',
    ]);
});

it('leaves non-sensitive fields untouched', function (): void {
    $record = makeLogRecord([
        'user_id' => 7,
        'organization_id' => 99,
        'duration_ms' => 123,
        'success' => true,
    ]);

    $processed = ($this->processor)($record);

    expect($processed->context)->toBe([
        'user_id' => 7,
        'organization_id' => 99,
        'duration_ms' => 123,
        'success' => true,
    ]);
});

it('redacts nested sensitive fields (recursive)', function (): void {
    $record = makeLogRecord([
        'request' => [
            'headers' => [
                'user-agent' => 'Claude/1.0',
            ],
            'body' => [
                'password' => 'hidden',
                'api_key' => 'hidden-key',
            ],
        ],
    ]);

    $processed = ($this->processor)($record);

    expect($processed->context['request']['headers']['user-agent'])->toBe('Claude/1.0');
    expect($processed->context['request']['body']['password'])->toBe('[REDACTED]');
    expect($processed->context['request']['body']['api_key'])->toBe('[REDACTED]');
});

it('is case-insensitive on key names', function (): void {
    $record = makeLogRecord([
        'Password' => 'x',
        'ACCESS_TOKEN' => 'abc',
        'Secret' => 'mixed',
    ]);

    $processed = ($this->processor)($record);

    expect($processed->context['Password'])->toBe('[REDACTED]');
    expect($processed->context['ACCESS_TOKEN'])->toBe('[REDACTED]');
    expect($processed->context['Secret'])->toBe('[REDACTED]');
});

it('normalizes key separators (_ / - / .) for matching', function (): void {
    $record = makeLogRecord([
        'access-token' => 'A',
        'access.token' => 'B',
        'Access_Token' => 'C',
        'AccessToken' => 'D',
    ]);

    $processed = ($this->processor)($record);

    expect($processed->context['access-token'])->toBe('[REDACTED]');
    expect($processed->context['access.token'])->toBe('[REDACTED]');
    expect($processed->context['Access_Token'])->toBe('[REDACTED]');
    expect($processed->context['AccessToken'])->toBe('[REDACTED]');
});

// ========== Value pattern redaction ==========

it('redacts Bearer tokens in string values even when key is not flagged', function (): void {
    $record = makeLogRecord([
        'message' => 'received Authorization: Bearer abc.def-ghi_123 from client',
    ]);

    $processed = ($this->processor)($record);

    expect($processed->context['message'])->toContain('[REDACTED]');
    expect($processed->context['message'])->not->toContain('abc.def-ghi_123');
});

it('redacts JWT-like strings in value', function (): void {
    $record = makeLogRecord([
        'raw' => 'token is eyJhbGciOiJIUzI1NiJ9.eyJ1c2VyIjoxfQ.signaturepart and valid',
    ]);

    $processed = ($this->processor)($record);

    expect($processed->context['raw'])->toContain('[REDACTED]');
    expect($processed->context['raw'])->not->toContain('eyJhbGciOiJIUzI1NiJ9.eyJ1c2VyIjoxfQ.signaturepart');
});

it('redacts Basic auth in value', function (): void {
    $record = makeLogRecord([
        'headers_raw' => 'Authorization: Basic dXNlcjpwYXNz',
    ]);

    $processed = ($this->processor)($record);

    expect($processed->context['headers_raw'])->toContain('[REDACTED]');
    expect($processed->context['headers_raw'])->not->toContain('dXNlcjpwYXNz');
});

// ========== extra array / stdClass ==========

it('redacts sensitive fields in the extra array (not only context)', function (): void {
    $record = makeLogRecord(
        ['user_id' => 1],
        [
            'password' => 'plaintext-secret',
            'request_id' => 'safe-123',
        ],
    );

    $processed = ($this->processor)($record);

    expect($processed->extra['password'])->toBe('[REDACTED]');
    expect($processed->extra['request_id'])->toBe('safe-123');
    expect($processed->context['user_id'])->toBe(1);
});

it('redacts sensitive properties on stdClass context values', function (): void {
    $payload = new stdClass;
    $payload->password = 'plaintext-secret';
    $payload->token = 'raw-token';
    $payload->method = 'tools/call';

    $record = makeLogRecord([
        'payload' => $payload,
    ]);

    $processed = ($this->processor)($record);

    $redacted = $processed->context['payload'];
    expect($redacted)->toBeInstanceOf(stdClass::class);
    expect($redacted->password)->toBe('[REDACTED]');
    expect($redacted->token)->toBe('[REDACTED]');
    expect($redacted->method)->toBe('tools/call');
});

// ========== channel wiring ==========

it('wires RedactSensitiveFields into the main log channels', function (): void {
    foreach (['single', 'daily', 'slack', 'stderr', 'syslog', 'errorlog'] as $channel) {
        // toContain は可変長 needle なので診断メッセージを引数に渡せない (渡すと needle 扱いされる)。
        // 未配線時にどの channel かは $channel 変数付きの withMessage で明示する。
        expect(config("logging.channels.{$channel}.processors"))
            ->toContain(RedactSensitiveFields::class);
    }
});
