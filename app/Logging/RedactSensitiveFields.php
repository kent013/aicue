<?php

declare(strict_types=1);

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * log context / extra の PII / 秘匿情報を `[REDACTED]` に置換する monolog processor。
 *
 * `Log::warning('foo', ['password' => $pw])` のような不注意な call で平文 secret が
 * ファイルログに残る事故を構造的に防ぐ。二段構えの redaction:
 *
 * 1. **key 一致 (正規化)**: context array の key を `_` / `-` / `.` 差異を無視した
 *    lowercase 正規形で照合し、一致すれば value 全体を [REDACTED] に置換。
 * 2. **value pattern**: string value に対し Bearer token / JWT / Basic auth を
 *    regex で検出し、該当部分のみ [REDACTED] に置換。key が対象リストに無くても
 *    token らしき文字列は隠せる。
 *
 * nested array / stdClass (JSON decode 由来) も再帰的に処理する。stdClass 以外の
 * object (Eloquent モデル等) は redact 走査の副作用が予測できないため pass-through
 * する (生 object を context に直接埋めるのは log call 側の anti-pattern)。
 * メッセージ本文 (`$record->message`) は触らない (呼び出し側で直すべき)。
 *
 * 注入は `config/logging.php` の各 channel の `processors` で行う。
 */
final class RedactSensitiveFields implements ProcessorInterface
{
    private const REDACTED = '[REDACTED]';

    /**
     * key 正規化 (strtolower + `_` / `-` / `.` 除去) 後のマスク対象リスト。
     * `password` / `token` / `access_token` / `refresh_token` / `api_key` /
     * `secret` / `private_key` を対象とする。
     *
     * @var list<string>
     */
    private const REDACT_KEYS_NORMALIZED = [
        'password',
        'token',
        'accesstoken',
        'refreshtoken',
        'apikey',
        'secret',
        'privatekey',
    ];

    /**
     * Value pattern: token らしき文字列を string value 内で部分置換する。
     * key が対象リストに無くても message context に直書きされたケースを救う。
     *
     * - Bearer / Basic: 連続した token 部分
     * - JWT: 3 segment dot-separated base64url
     *
     * @var list<string>
     */
    private const VALUE_PATTERNS = [
        '/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*\b/i',
        '/\bBasic\s+[A-Za-z0-9+\/]+=*\b/i',
        '/\beyJ[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\b/', // JWT-ish
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            context: $this->redact($record->context),
            extra: $this->redact($record->extra),
        );
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function redact(array $value): array
    {
        foreach ($value as $k => $v) {
            if (is_string($k) && self::isRedactKey($k)) {
                $value[$k] = self::REDACTED;

                continue;
            }
            if (is_array($v)) {
                /** @var array<array-key, mixed> $v */
                $value[$k] = $this->redact($v);

                continue;
            }
            if ($v instanceof \stdClass) {
                // JSON payload を json_decode($json) で stdClass に展開した場合も
                // password / token プロパティを平文ログさせない。
                $value[$k] = (object) $this->redact((array) $v);

                continue;
            }
            if (is_string($v)) {
                $value[$k] = self::redactValuePatterns($v);
            }
        }

        return $value;
    }

    /**
     * key 名の `_` / `-` / `.` 差異を吸収して対象リストと照合する。
     * `Access-Token` / `access_token` / `AccessToken` を同一視。
     */
    private static function isRedactKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['_', '-', '.'], '', $key));

        return in_array($normalized, self::REDACT_KEYS_NORMALIZED, true);
    }

    /**
     * string value に対し token パターンを検出して該当部分を [REDACTED] に置換。
     */
    private static function redactValuePatterns(string $value): string
    {
        foreach (self::VALUE_PATTERNS as $pattern) {
            $replaced = preg_replace($pattern, self::REDACTED, $value);
            if (is_string($replaced)) {
                $value = $replaced;
            }
        }

        return $value;
    }
}
