<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * TrustProxies allowlist (config/trustedproxy.php) の production 起動時検証。
 *
 * `TrustedHostsConfigValidator` と同形 (final / 純粋クラス / RuntimeException)。
 * 検証ロジックを純粋クラスに切り出して unit test で直接検証可能にする。
 *
 * 背景: かつて `trustProxies(at: '*')` だった。全アドレスを trusted proxy 扱いにすると
 * `$request->ip()` が X-Forwarded-For の最左 = **クライアントが自由に書ける値**になり、
 * IP ベースの rate limit / reCAPTCHA / 監査ログがすべて無効化される (audit-cycle-2 High-2)。
 * production では「hop を明示宣言する」ことを起動条件にする。
 *
 * ⚠ 本 validator は **意図的にデプロイ時の破壊的変更**である。`TRUSTED_PROXIES` を
 * 宣言せずに production を起動すると fail-fast する。rollback は `at: '*'` へ戻すことでは
 * なく、正しい CIDR を設定すること。運用契約は docs/trusted-proxies-runbook.md。
 */
final class TrustedProxiesConfigValidator
{
    /**
     * @param  list<string>  $proxies  検証通過後の proxy 列 (config 通過後)
     * @param  list<string>  $rawProxies  生 token (空白 trim のみ、format validation 前)
     *
     * @throws RuntimeException
     */
    public function validateForProduction(array $proxies, array $rawProxies): void
    {
        $tokens = array_values(array_filter(
            array_map('trim', $rawProxies),
            static fn (string $v): bool => $v !== '',
        ));

        // 1. 全アドレス信頼は無条件で拒否する (これが High-2 の元の状態)。
        //    `*` / `**` だけでなく prefix 長 0 の CIDR (`0.0.0.0/0` / `::/0`) も同値。
        //    後者は書式として正当な CIDR なので、書式検査だけでは通り抜ける。
        foreach ($tokens as $token) {
            if (TrustedProxyToken::isAllAddresses($token)) {
                throw new RuntimeException(sprintf(
                    'TRUSTED_PROXIES contains "%s". Trusting every address lets clients forge '
                    .'X-Forwarded-For (client IP, rate limits and audit logs become attacker-controlled). '
                    .'Enumerate the actual proxy hops as IP/CIDR instead.',
                    $token,
                ));
            }
        }

        // 2. `none` sentinel (プロキシ無し構成の明示宣言) を **書式検査より先に**処理する。
        //    順序が逆だと `none` 自身が「config 段で落ちた不正値」として reject される。
        if (in_array(TrustedProxyToken::NONE, $tokens, true)) {
            if (count($tokens) !== 1) {
                throw new RuntimeException(
                    'TRUSTED_PROXIES declares "none" together with other values. '
                    .'"none" means "there is no proxy in front of this app" and must be declared alone.'
                );
            }
            if ($proxies !== []) {
                throw new RuntimeException(
                    'TRUSTED_PROXIES declares "none" but the resolved proxy list is not empty. '
                    .'This indicates a configuration inconsistency (check config/trustedproxy.php).'
                );
            }

            return; // プロキシ無し構成の明示宣言 = 正常
        }

        // 3. production で REMOTE_ADDR (直接接続元の一括信頼) は許さない。
        if (in_array(TrustedProxyToken::REMOTE_ADDR, $tokens, true)) {
            throw new RuntimeException(
                'TRUSTED_PROXIES contains "REMOTE_ADDR". Trusting the immediate peer unconditionally '
                .'is a local-development convenience and must not be used in production. '
                .'Enumerate the actual proxy hops as IP/CIDR instead.'
            );
        }

        // 4. 書式不正 (config 段の silent drop を起動時に表面化させる)。
        foreach ($tokens as $token) {
            if (! TrustedProxyToken::isTrustableAddress($token)) {
                throw new RuntimeException(sprintf(
                    'TRUSTED_PROXIES contains an invalid value "%s". '
                    .'Each entry must be a single IP address or a CIDR block (e.g. 10.0.0.0/8).',
                    $token,
                ));
            }
        }

        // 5. 未設定 (空) は production では宣言漏れとして扱う。
        if ($proxies === []) {
            throw new RuntimeException(
                'TRUSTED_PROXIES is not set in production. Enumerate every proxy hop as IP/CIDR, '
                .'or declare "none" explicitly when the app is not behind a proxy. '
                .'See docs/trusted-proxies-runbook.md.'
            );
        }
    }
}
