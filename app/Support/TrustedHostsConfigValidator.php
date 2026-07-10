<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * TrustHosts allowlist (config/trusted_hosts.php) の production 起動時検証。
 *
 * 検証ロジックを純粋クラスに切り出して unit test で直接検証可能にする
 * (Service Provider / Guard の boot 内 inline では Feature test で再現が困難なため)。
 *
 * production で以下のいずれかに該当するときに `RuntimeException` を投げ、起動時 fail-fast させる:
 *   - allowlist が完全に空 (exact host / wildcard suffix の双方が空)
 *   - `TRUSTED_HOSTS_WILDCARD_SUFFIXES` の生 env 値が `.suffix` 形式に違反
 *     (config 側で silent drop されるが、起動時には fail-fast で表面化)
 *   - `PRIMARY_HOST` / `TRUSTED_HOSTS_ADDITIONAL` が host 形式に違反
 *     (scheme / path / port を含む値を弾く)
 *
 * 限界: 本 validator は format (label 数等) のみ検査する。`.co.jp` のような
 * public suffix 単体も「2 ラベル以上」を満たすため通過する (label 数では正当な
 * `.example.com` と区別不能)。public suffix の wildcard を弾くには Public Suffix List
 * が必要だが、運用者が意図的に設定する config に対しては過剰なため scope 外とする。
 * 運用者は所有ドメイン配下の suffix (例: `.preview.example.com`) を設定すること。
 */
final class TrustedHostsConfigValidator
{
    /**
     * @param  list<string>  $exactHosts  validate 済 exact host 列 (config 通過後)
     * @param  list<string>  $wildcardSuffixes  validate 済 wildcard suffix 列 (config 通過後)
     * @param  list<string>  $rawWildcards  生 env 値 (空白 trim のみ、format validation 前)
     *
     * @throws RuntimeException
     */
    public function validateForProduction(
        array $exactHosts,
        array $wildcardSuffixes,
        array $rawWildcards,
    ): void {
        // empty check: production で allowlist が完全空だと SUSPICIOUS_OPERATION で
        // 全 request が 400 になる事故 → 起動時点で fail-fast。
        if ($exactHosts === [] && $wildcardSuffixes === []) {
            throw new RuntimeException(
                'TrustHosts allowlist is empty in production. '
                .'Set PRIMARY_HOST or TRUSTED_HOSTS_ADDITIONAL or TRUSTED_HOSTS_WILDCARD_SUFFIXES.'
            );
        }

        // wildcard 不正値 (raw 値で検証、config 側で silent drop された値も起動時 fail で表面化)。
        foreach ($rawWildcards as $raw) {
            $trimmed = trim($raw);
            if ($trimmed === '') {
                continue;
            }
            // `.com` のような単一ラベル suffix は過大許可 (全 .com host を信頼) になるため、
            // 先頭 dot の後に **2 ラベル以上** を必須化する。
            if (preg_match('/^\.[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/', $trimmed) !== 1) {
                throw new RuntimeException(sprintf(
                    'TRUSTED_HOSTS_WILDCARD_SUFFIXES contains invalid value "%s". '
                    .'Format must be ".sub.domain.tld" (leading dot, >=2 labels, alphanumeric/hyphen only). '
                    .'Single-label suffixes like ".com" are rejected (too broad).',
                    $trimmed,
                ));
            }
        }

        // exact host 不正値 (scheme / path / port / wildcard / カンマ等を弾く)。
        foreach ($exactHosts as $host) {
            if (preg_match('/^[A-Za-z0-9.-]+$/', $host) !== 1) {
                throw new RuntimeException(sprintf(
                    'PRIMARY_HOST or TRUSTED_HOSTS_ADDITIONAL contains invalid value "%s". '
                    .'Format must be host only (alphanumeric/dot/hyphen, no scheme/port/path).',
                    $host,
                ));
            }
        }
    }
}
