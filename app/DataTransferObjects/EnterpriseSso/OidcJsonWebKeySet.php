<?php

declare(strict_types=1);

namespace App\DataTransferObjects\EnterpriseSso;

use App\Enums\EnterpriseSso\OidcSigningAlgorithm;
use App\Enums\EnterpriseSso\RejectionReason;
use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
use JsonException;

/**
 * IdP の公開鍵集合 (JWKS)。**必要な要素だけ**を具体型で持つ。
 *
 * ★`use` と `key_ops` は JWK 仕様で **optional** である。
 *   **存在するときだけ**検査する — 欠落を理由に有効な鍵を拒否しない。
 * ★`kid` の**重複は拒否**する。重複したまま「最初に見つかった鍵」で検証すると、
 *   攻撃者が用意した鍵を先頭へ置くだけで検証を通せる形になりうる。
 */
final readonly class OidcJsonWebKeySet
{
    /**
     * `key_ops` をキャッシュ可能な素のスカラーへ畳むときの区切り。
     *
     * ★用途の値そのものには現れない文字を選ぶ (RFC 7517 の用途は `sign` / `verify` 等の
     *   空白を含まない語である)。畳んだあとも**トークンの完全一致**で判定する。
     */
    private const string KEY_OPS_SEPARATOR = ' ';

    /**
     * JWK のうち**文字列であることを要求する**項目。
     *
     * 存在するのに型が違う鍵は拒否する (欠落として捨てると malformed が素通りする)。
     *
     * @var list<string>
     */
    private const array TYPED_STRING_MEMBERS = ['kty', 'kid', 'alg', 'use', 'crv', 'n', 'e', 'x', 'y'];

    /**
     * @param  array<string, array<string, string>>  $keysByKeyId  kid => JWK の素の要素
     */
    private function __construct(public array $keysByKeyId) {}

    /**
     * @throws EnterpriseSsoAttemptRejectedException
     */
    public static function fromResponseBody(string $body): self
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksMalformed);
        }

        if (! is_array($decoded)) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksMalformed);
        }

        $keys = $decoded['keys'] ?? null;
        if (! is_array($keys)) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksMalformed);
        }

        /** @var array<string, array<string, string>> $byKeyId */
        $byKeyId = [];
        foreach ($keys as $key) {
            if (! is_array($key)) {
                throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksMalformed);
            }

            $normalized = self::normalizeKey($key);
            if ($normalized === null) {
                // kid を持たない鍵は本アプリの検証に使えない (kid 必須)。集合から静かに落とす。
                continue;
            }

            [$keyId, $members] = $normalized;

            if (array_key_exists($keyId, $byKeyId)) {
                throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksDuplicateKeyId);
            }

            $byKeyId[$keyId] = $members;
        }

        if ($byKeyId === []) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksMalformed);
        }

        return new self($byKeyId);
    }

    /**
     * キャッシュから読み戻す (**素の配列から明示的に組み立て直して検査する**)。
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function fromCachePayload(array $payload): ?self
    {
        /** @var array<string, array<string, string>> $byKeyId */
        $byKeyId = [];

        foreach ($payload as $keyId => $members) {
            if (! is_string($keyId) || $keyId === '' || ! is_array($members)) {
                return null;
            }

            /** @var array<string, string> $normalized */
            $normalized = [];
            foreach ($members as $name => $value) {
                if (! is_string($name) || ! is_string($value)) {
                    return null;
                }
                $normalized[$name] = $value;
            }

            $byKeyId[$keyId] = $normalized;
        }

        if ($byKeyId === []) {
            return null;
        }

        return new self($byKeyId);
    }

    /**
     * キャッシュへ入れる形 (**素の配列と文字列だけ**)。
     *
     * @return array<string, array<string, string>>
     */
    public function toCachePayload(): array
    {
        return $this->keysByKeyId;
    }

    public function has(string $keyId): bool
    {
        return array_key_exists($keyId, $this->keysByKeyId);
    }

    /**
     * `alg` と整合する鍵を 1 本返す。
     *
     * 拒否条件 (deny-by-default):
     *  - `kid` に一致する鍵が無い
     *  - `kty` が `alg` と不整合 / EC の `crv` が `alg` と不整合
     *  - **`use` が存在するのに** `sig` でない
     *  - **`key_ops` が存在するのに** `verify` を含まない
     *
     * @return array<string, string>
     *
     * @throws EnterpriseSsoAttemptRejectedException
     */
    public function keyFor(string $keyId, OidcSigningAlgorithm $algorithm): array
    {
        $key = $this->keysByKeyId[$keyId] ?? null;
        if ($key === null) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksKeyNotFound);
        }

        if (($key['kty'] ?? null) !== $algorithm->keyType()) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksMalformed);
        }

        $curve = $algorithm->curve();
        if ($curve !== null && ($key['crv'] ?? null) !== $curve) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksMalformed);
        }

        // ★optional。**在るときだけ**見る。
        if (array_key_exists('use', $key) && $key['use'] !== 'sig') {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksMalformed);
        }

        // ★**トークンの完全一致**で判定する (部分文字列一致にしない —
        //   `["notverify"]` が `verify` を含むものとして通ってしまう)。
        //   RFC 7517 §4.3 の `key_ops` は大文字小文字を区別する文字列の配列であり、
        //   検証用途は完全一致の `verify` である。
        if (array_key_exists('key_ops', $key)
            && ! in_array('verify', explode(self::KEY_OPS_SEPARATOR, $key['key_ops']), true)
        ) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksMalformed);
        }

        return $key;
    }

    /**
     * `key_ops` (文字列の配列) を素のスカラーへ畳む。
     *
     * ★配列でない / 要素が文字列でない / 区切り文字を含む / **重複した**用途は**拒否する**
     *   (区切りを含む値を通すと、畳んだ後のトークン一致が偽陽性になりうる)。
     */
    private static function normalizeKeyOperations(mixed $value): string
    {
        if (! is_array($value)) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksMalformed);
        }

        $operations = [];
        foreach ($value as $operation) {
            if (! is_string($operation) || str_contains($operation, self::KEY_OPS_SEPARATOR)) {
                throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksMalformed);
            }
            // ★**重複も拒否する**。同じ用途を 2 回書くことに意味は無く、malformed 寄りである。
            //   deny-by-default を優先し、意味の無い形を通さない。
            if (in_array($operation, $operations, true)) {
                throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksMalformed);
            }
            $operations[] = $operation;
        }

        return implode(self::KEY_OPS_SEPARATOR, $operations);
    }

    /**
     * JWK の要素を「文字列だけの素の配列」へ落とす。
     *
     * @param  array<array-key, mixed>  $key
     * @return array{0: string, 1: array<string, string>}|null
     */
    private static function normalizeKey(array $key): ?array
    {
        $keyId = $key['kid'] ?? null;
        if (! is_string($keyId) || $keyId === '') {
            return null;
        }

        /** @var array<string, string> $members */
        $members = [];
        foreach ($key as $name => $value) {
            if (! is_string($name)) {
                continue;
            }

            if ($name === 'key_ops') {
                $members['key_ops'] = self::normalizeKeyOperations($value);

                continue;
            }

            // ★**存在する既知の項目は具体型が違えば拒否する** (欠落として捨てない)。
            //   捨てると `{"use": ["sig"]}` のような malformed な鍵が
            //   「optional なので欠落してよい」として素通りする。
            if (in_array($name, self::TYPED_STRING_MEMBERS, true) && ! is_string($value)) {
                throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksMalformed);
            }

            if (is_string($value)) {
                $members[$name] = $value;
            }
        }

        return [$keyId, $members];
    }
}
