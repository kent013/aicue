<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Exceptions\Mcp\IdempotencyConflictException;
use App\Models\McpIdempotencyKey;
use App\Values\Mcp\IdempotencyKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * MCP 書き込み系 tool の冪等性ストアサービス。
 *
 * 既存 REST 向け `IdempotentRequest` middleware とは独立。`mcp_idempotency_keys`
 * テーブルを操作し、caller 境界は `(organization_id, user_id, tool_name, key)`。
 *
 * 契約:
 * - 同一 org の別 user が同じ key を使っても衝突しない (caller 境界は user 単位)
 * - 同一 user の純粋 retry は replay される
 * - refresh で access_token が回転しても同一 user の replay は継続する
 *   (user_id を key に使うので refresh の影響を受けない)
 */
final class McpIdempotencyService
{
    public const TTL_HOURS = 24;

    /**
     * 同一 key を探して replay 可能なら response body を返す。
     * payload mismatch は conflict 例外、TTL 超過は row 削除して null を返す。
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public function replay(
        int $organizationId,
        int $userId,
        string $toolName,
        IdempotencyKey $key,
        array $payload,
    ): ?array {
        $existing = McpIdempotencyKey::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('tool_name', $toolName)
            ->where('idempotency_key', $key->value)
            ->first();

        if ($existing === null) {
            return null;
        }

        if ($existing->expires_at->isPast()) {
            $existing->delete();

            return null;
        }

        $payloadHash = self::hashPayload($payload);
        if ($existing->payload_hash !== $payloadHash) {
            throw new IdempotencyConflictException(
                'idempotency_key reused with different parameters. To replay the prior response, retry with identical arguments. To submit new parameters, use a fresh idempotency_key (UUID v4).',
            );
        }

        /** @var array<string, mixed>|null $body */
        $body = $existing->response_body;
        if ($body === null) {
            // store 中に crash した場合の希なケース。再実行を許す。
            $existing->delete();

            return null;
        }

        return $body;
    }

    /**
     * 成功した tool call の response を永続化する。
     * concurrent race では unique 違反を swallow する (caller が replay で再取得する想定)。
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $response
     */
    public function store(
        int $organizationId,
        int $userId,
        string $toolName,
        IdempotencyKey $key,
        array $payload,
        array $response,
    ): void {
        try {
            // ownership キー (organization_id / user_id) は $fillable 外のため
            // named creation method 経由で明示代入する。
            McpIdempotencyKey::recordForOrgAndUser(
                organizationId: $organizationId,
                userId: $userId,
                toolName: $toolName,
                idempotencyKey: $key->value,
                payloadHash: self::hashPayload($payload),
                responseBody: $response,
                createdAt: CarbonImmutable::now(),
                expiresAt: CarbonImmutable::now()->addHours(self::TTL_HOURS),
            );
        } catch (QueryException $e) {
            if (! self::isUniqueViolation($e)) {
                throw $e;
            }
            // race: 勝者が既に書いた。敗者はそのまま抜ける (caller が replay で拾う想定)。
        }
    }

    /**
     * Payload の正規化ハッシュ。
     *
     * 型同一視ポリシー:
     * - int / float / numeric-string は同一視しない (JSON encode の表現差を維持)
     * - caller (AI IDE) には Tool の JsonSchema で型を integer/string に固定し、
     *   型違反は param 検証層で reject することで矛盾を根元で防ぐ。
     *
     * @param  array<string, mixed>  $payload
     */
    public static function hashPayload(array $payload): string
    {
        // idempotency_key 自体は unique キーに含まれるのでハッシュ対象から除外
        unset($payload['idempotency_key']);
        // JSON_PRESERVE_ZERO_FRACTION: int 1 と float 1.0 を JSON 上で区別する
        // (1 → "1" / 1.0 → "1.0" と表現され、hash も異なる値になる)。
        $canonical = json_encode(
            self::canonicalize($payload),
            JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION,
        );

        return hash('sha256', $canonical);
    }

    /**
     * JSON canonical form.
     * - list (int key 連番) は順序維持 (意味論的に重要)
     * - associative は ksort
     */
    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value);
        /** @var array<array-key, mixed> $value */
        foreach ($value as $k => $v) {
            $value[$k] = self::canonicalize($v);
        }

        return $value;
    }

    /**
     * DB driver 差 (SQLite / PostgreSQL / MySQL) を吸収した unique 制約違反判定。
     */
    private static function isUniqueViolation(QueryException $e): bool
    {
        $info = $e->errorInfo ?? null;
        if (! is_array($info) || ! isset($info[0]) || ! is_string($info[0])) {
            return str_contains($e->getMessage(), 'UNIQUE constraint failed');
        }
        $sqlState = $info[0];

        if ($sqlState === '23505') {
            return true;
        }

        if ($sqlState === '23000' && ($info[1] ?? null) === 1062) {
            return true;
        }

        return str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }
}
