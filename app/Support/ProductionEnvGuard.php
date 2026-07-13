<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use Throwable;

/**
 * production env に必要な必須項目を検査し、違反があれば fail-fast する SSOT。
 *
 * AppServiceProvider::boot() (production 起動時) と production:preflight コマンドの
 * 双方から参照される。検査項目:
 * - APP_KEY / CIPHERSWEET_KEY 非空 (暗号化キー未設定の起動防止)
 * - STRIPE_WEBHOOK_SECRET 非空 (Cashier の署名検証 silent skip 防止)
 * - SESSION_SECURE_COOKIE=true (HTTPS Cookie 必須)
 * - APP_DEBUG=false (stack trace / 設定露出防止)
 * - SECURITY_HSTS_ENABLED / SECURITY_CSP_ENABLED=true (セキュリティヘッダ必須)
 * - DEBUG_LOGIN_USER / DEBUG_LOGIN_PASSWORD が空 (local 専用機構の誤投入防止)
 * - TESTING_FAKE_EXTERNALS=false (Stripe 外部 fake の本番混入防止)
 * - TESTING_FAKE_LLM=false (LLM fake の本番混入防止)
 * - TESTING_FAKE_STORAGE=false (storage fake の本番混入防止)
 * - TrustHosts allowlist (Host header injection 防御の allowlist 非空・書式)
 */
class ProductionEnvGuard
{
    /**
     * production env に必要な必須項目を検査し、違反メッセージのリストを返す。
     *
     * @return list<string>
     */
    public function violations(): array
    {
        $errors = [];

        $appKeyValue = config('app.key');
        $appKey = is_string($appKeyValue) ? $appKeyValue : '';
        if ($appKey === '') {
            $errors[] = 'APP_KEY is required in production.';
        }

        $cipherKeyValue = config('ciphersweet.providers.string.key');
        $cipherKey = is_string($cipherKeyValue) ? $cipherKeyValue : '';
        if ($cipherKey === '') {
            $errors[] = 'CIPHERSWEET_KEY is required in production (PII encryption key).';
        }

        $stripeSecretValue = config('cashier.webhook.secret');
        $stripeSecret = is_string($stripeSecretValue) ? $stripeSecretValue : '';
        if ($stripeSecret === '') {
            $errors[] = 'STRIPE_WEBHOOK_SECRET is required in production '
                .'(Cashier silently skips signature verification when missing).';
        }

        if (config('session.secure') !== true) {
            $errors[] = 'SESSION_SECURE_COOKIE must be true in production '
                .'(current: '.var_export(config('session.secure'), true).').';
        }

        // APP_DEBUG=true は本番で stack trace / env 露出を招くため禁止。
        if (config('app.debug') === true) {
            $errors[] = 'APP_DEBUG must be false in production '
                .'(true leaks stack traces and configuration via error pages).';
        }

        if (config('security.hsts.enabled') !== true) {
            $errors[] = 'SECURITY_HSTS_ENABLED must be true in production.';
        }

        if (config('security.csp.enabled') !== true) {
            $errors[] = 'SECURITY_CSP_ENABLED must be true in production.';
        }

        $debugUserValue = config('debug.login.user');
        $debugPasswordValue = config('debug.login.password');
        $debugUser = is_string($debugUserValue) ? $debugUserValue : '';
        $debugPassword = is_string($debugPasswordValue) ? $debugPasswordValue : '';
        if ($debugUser !== '' || $debugPassword !== '') {
            $errors[] = 'DEBUG_LOGIN_USER and DEBUG_LOGIN_PASSWORD must be empty in production '
                .'(both are local-dev only; presence indicates dangerous misconfiguration).';
        }

        // 外部 fake flag は非本番専用。production で true なら課金 (Stripe) が fake に
        // 差し替わり得る危険設定のため fail-fast する (FakeExternalsServiceProvider の
        // allowlist で bind 自体は起きないが、設定として存在すること自体を拒否する)
        if (config('testing.fake_externals') === true) {
            $errors[] = 'TESTING_FAKE_EXTERNALS must be false in production '
                .'(external fakes must never be enabled in production).';
        }

        // LLM fake は production で real LLM を潰すため禁止 (fake_externals と同じ fail-secure)。
        if (config('testing.fake_llm') === true) {
            $errors[] = 'TESTING_FAKE_LLM must be false in production '
                .'(LLM fake must never be enabled in production).';
        }

        // storage fake は production で実ストレージを潰し得るため禁止。
        if (config('testing.fake_storage') === true) {
            $errors[] = 'TESTING_FAKE_STORAGE must be false in production '
                .'(storage fake must never be enabled in production).';
        }

        // Host header injection 防御の TrustHosts allowlist を起動時検証。
        // 純粋クラス TrustedHostsConfigValidator に委譲し、throw を violation メッセージへ写像する。
        $exact = $this->stringList(config('trusted_hosts.exact_hosts', []));
        $wildcard = $this->stringList(config('trusted_hosts.wildcard_suffixes', []));
        $rawWildcards = $this->stringList(config('trusted_hosts.raw_wildcard_suffixes', []), keepEmpty: true);
        try {
            (new TrustedHostsConfigValidator)->validateForProduction($exact, $wildcard, $rawWildcards);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        return $errors;
    }

    /**
     * production 起動時に違反があれば例外で fail-fast。
     */
    public function enforce(): void
    {
        $errors = $this->violations();
        if ($errors !== []) {
            throw new RuntimeException(
                "Production env baseline violations:\n- ".implode("\n- ", $errors)
            );
        }
    }

    /**
     * config 値を string list へ正規化する (非 string 要素を除外)。
     *
     * @return list<string>
     */
    private function stringList(mixed $value, bool $keepEmpty = false): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }
            if (! $keepEmpty && $item === '') {
                continue;
            }
            $result[] = $item;
        }

        return $result;
    }
}
