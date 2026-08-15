<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\ExternalFakes\ExternalFakeDeclaration;
use Laravel\Fortify\Features;
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
 * - 偽の外部サービスのフラグが false (本番混入防止)。対象と環境変数名の正本は
 *   App\Support\ExternalFakes\ExternalFakeDeclaration で、**設定値とプロセスの実環境変数の
 *   両方**を見る (設定キャッシュが失われた起動で環境変数が読み直されるため)
 * - TrustHosts allowlist (Host header injection 防御の allowlist 非空・書式)
 * - TrustProxies allowlist (client IP / X-Forwarded-Proto の信頼境界。未宣言・`*`・
 *   REMOTE_ADDR・書式不正を拒否。プロキシ無し構成は `none` の明示宣言を要求する)
 * - パスキー設定 (身元の識別子 / 許可する接続元 / 利用者ハンドルの導出鍵。
 *   書式・相互整合・導出鍵の独立宣言。Features::passkeys() 有効時のみ)
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

        // 偽の外部サービスのフラグは非本番専用。production で true なら課金 (Stripe) が
        // 偽物へ差し替わり得る危険設定のため fail-fast する (配線 provider の許可環境で
        // bind 自体は起きないが、設定として存在すること自体を拒否する)。
        //
        // ★**設定値とプロセスの実環境変数の両方**を見る。設定キャッシュを作った環境と
        //   出荷先が食い違うと、キャッシュ上は false でも、キャッシュが失われた起動で
        //   環境変数が読み直されて本番で偽物が立ちうる (経路キャッシュが古いと保護が
        //   無音で外れるのと同じ形)。対象と環境変数名の正本は ExternalFakeDeclaration。
        foreach (ExternalFakeDeclaration::FLAG_ENVIRONMENT_VARIABLES as $flag => $variable) {
            if (config($flag) === true) {
                $errors[] = "{$variable} must be false in production (configuration value).";
            }

            // $_SERVER / $_ENV / getenv() を独立に見る (どれか 1 つでも危険側なら違反)。
            foreach ($this->rawEnvironmentValues($variable) as $source => $raw) {
                if (! $this->isUnambiguouslyDisabled($raw)) {
                    $errors[] = "{$variable} must be false in production "
                        ."(process environment via {$source}: ".var_export($raw, true).').';
                }
            }
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

        // client IP の信頼境界 (TrustProxies allowlist) を起動時検証。
        // 未宣言だと XFF 偽装 or hop 取りこぼしによる自己 DoS のどちらかに倒れるため、
        // production では「hop を明示宣言する」ことを起動条件にする (audit-cycle-2 High-2)。
        $proxies = $this->stringList(config('trustedproxy.proxies', []));
        $rawProxies = $this->stringList(config('trustedproxy.raw_proxies', []), keepEmpty: true);
        try {
            (new TrustedProxiesConfigValidator)->validateForProduction($proxies, $rawProxies);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        // パスキー (WebAuthn) の身元 / 接続元 / 利用者ハンドル導出鍵を起動時検証。
        // **キルスイッチが有効なときだけ**検査する (機能を止めている環境に設定を要求しない)。
        // 有効化点は config/fortify.php の Features::passkeys([...]) ただ 1 箇所。
        if (Features::enabled(Features::passkeys())) {
            $relyingPartyIdValue = config('passkeys.relying_party_id');
            $relyingPartyId = is_string($relyingPartyIdValue) ? $relyingPartyIdValue : '';
            // ★読み出し元が 2 つに分かれる理由:
            //   - 実効値 (passkeys.*) = **実際に手続きで使われる値**。Fortify の上書き後の姿を検査する。
            //   - 宣言の事実 (fortify.passkeys.raw_allowed_origins / user_handle_secret_declared) は
            //     検査専用キーで Fortify は passkeys.* へ写さない。宣言元から読むしかない。
            $originsValue = config('passkeys.allowed_origins', []);
            $rawOriginsValue = config('fortify.passkeys.raw_allowed_origins', []);
            $userHandleSecretValue = config('passkeys.user_handle_secret');
            $userHandleSecret = is_string($userHandleSecretValue) ? $userHandleSecretValue : '';
            $userHandleSecretDeclared = config('fortify.passkeys.user_handle_secret_declared') === true;

            // 文字列以外が混ざった config を **黙って除去しない** (除去すると設定破損を見逃す)。
            // trusted hosts / proxies 側の stringList() は「silent drop を raw で表面化させる」形だが、
            // passkeys は config が必ず string 列を返す設計なので、破損はそのまま violation にする。
            if (! $this->isStringList($originsValue) || ! $this->isStringList($rawOriginsValue)) {
                $errors[] = 'passkeys.allowed_origins and fortify.passkeys.raw_allowed_origins must be lists of strings.';
            } else {
                try {
                    (new PasskeyConfigValidator)->validateForProduction(
                        $relyingPartyId,
                        $originsValue,
                        $rawOriginsValue,
                        $userHandleSecretDeclared,
                        $userHandleSecret,
                    );
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            }
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
     * プロセスの実環境変数を 3 経路で読み、**値が存在するものだけ**を返す。
     *
     * 未設定は判定対象にしない。`$_SERVER` / `$_ENV` は mixed を持ちうるので
     * **型を絞らずそのまま返す** (絞って捨てると設定破損を見逃す)。
     *
     * @return array<string, mixed> 経路名 => 生の値
     */
    private function rawEnvironmentValues(string $variable): array
    {
        $values = [];

        if (array_key_exists($variable, $_SERVER)) {
            $values['$_SERVER'] = $_SERVER[$variable];
        }

        if (array_key_exists($variable, $_ENV)) {
            $values['$_ENV'] = $_ENV[$variable];
        }

        // getenv() の false は「未設定」であり、空文字とは区別する。
        $raw = getenv($variable);
        if ($raw !== false) {
            $values['getenv()'] = $raw;
        }

        return $values;
    }

    /**
     * 生の値が「間違いなく無効」と読めるか。
     *
     * 文字列でない値を含め、解釈できない値はすべて違反 (安全側) にする。
     */
    private function isUnambiguouslyDisabled(mixed $raw): bool
    {
        if (! is_string($raw)) {
            return false;
        }

        return in_array(
            strtolower($raw),
            ['', 'false', '(false)', '0', 'off', 'no', 'null', '(null)'],
            true
        );
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

    /**
     * 値が string だけの list か (非 string を黙って除去せず、破損として扱うための判定)。
     *
     * @phpstan-assert-if-true list<string> $value
     */
    private function isStringList(mixed $value): bool
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                return false;
            }
        }

        return true;
    }
}
