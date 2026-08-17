<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * パスキー (WebAuthn) 設定 (config/fortify.php の passkeys ブロック → Fortify が passkeys.* へ写す)
 * の production 起動時検証。
 *
 * `TrustedProxiesConfigValidator` / `TrustedHostsConfigValidator` と同形
 * (final / 純粋クラス / RuntimeException / ProductionEnvGuard から try-catch で写像)。
 *
 * 背景: パスキーは**単独でログインできる強い資格**であり、正しさが 3 つの設定値に依存する。
 * これらは既定で APP_URL / APP_KEY から導出されるため、設定事故が
 * **利用者が認証しようとした瞬間まで表面化しない** (登録はできるのに検証が全件失敗する、
 * あるいは APP_KEY ローテートで登録済みパスキーが全件無効になる)。
 * production では起動時に落として、デプロイ前に気づけるようにする。
 *
 * ⚠ **受理と検証の役割分担**: 「正規形へ寄せる」のは宣言側 (config/fortify.php) の責務であり、
 * 正規形の定義は `PasskeyOriginCanonicalizer` ただ 1 か所にある。本 validator は
 * **正規形からの逸脱を落とす**側に徹する (判定基準を 2 か所に持たない)。
 * したがって運用者が env に書いた末尾スラッシュや既定 port は**宣言側で吸収され**、
 * ここへ非正規形が届くのは「宣言側を通らない経路が設定した」場合だけである。
 * その値は webauthn-lib の厳密比較に一致せず**全手続きを無言で失敗させる**ので、
 * 黙って受理せず起動時に落とす (裁定 2026-08-04 / aicue:T216)。
 *
 * ⚠ **例外文に設定の生値を載せない**。載せると配備ログへ設定値が焼き付くため、
 * **何番目の値か**と**環境変数名**だけを示す (運用者は自分の .env を見れば特定できる)。
 *
 * ⚠ 本 validator は **意図的にデプロイ時の破壊的変更**である
 * (TRUSTED_PROXIES と同性質)。`PASSKEYS_USER_HANDLE_SECRET` を宣言せずに
 * production を起動すると fail-fast する。既にパスキーが登録済みの環境では
 * **現行 APP_KEY の値をそのまま宣言すれば既存パスキーは維持される**
 * (宣言の有無で判定し、値の一致では判定しないため)。
 * 運用契約は docs/auth-security-mechanisms.md §5。
 *
 * 限界 (誇張しない): 本 validator は **書式と相互整合**しか見ない。
 * 「その host を本当に運用しているか」「TLS 証明書があるか」は検査できない。
 * **Public Suffix List を持たないため、`co.uk` のような public suffix を身元の識別子に
 * 置いた設定は通ってしまう** (ブラウザ側は PSL を見るので実際の手続きは失敗する)。
 * したがって本 validator は **WebAuthn の完全な妥当性検査ではない**。
 * PSL 判定のために依存を足すことはしない (TrustedHostsConfigValidator と同じ判断。
 * 誤設定の結果は「パスキーが使えない」であって権限昇格ではなく、
 * 設定するのは攻撃者ではなく運用者であるため)。この限界は
 * PasskeyConfigValidatorTest に**既知の限界として明示的なテストで記録する**。
 * production の身元の識別子・接続元は **DNS 名のみ**を対象とする
 * (IPv4 / IPv6 リテラルと単一ラベルは reject する = WebAuthn の relying party id にできない)。
 * **非 ASCII ホストは受理しない** — 国際化ドメインの punycode 変換は行わず運用者に
 * punycode で書かせる (変換を実装すると、変換結果が正しいことを誰も検査できない層が増える)。
 */
final class PasskeyConfigValidator
{
    /** DNS 名の最大長 (末尾ドットを含まない) */
    private const MAX_DNS_NAME_LENGTH = 253;

    /** DNS ラベルの最大長 */
    private const MAX_DNS_LABEL_LENGTH = 63;

    /** 導出鍵の最小長 (短い値は typo / placeholder の可能性が高い) */
    private const MIN_USER_HANDLE_SECRET_LENGTH = 32;

    /**
     * @param  string  $relyingPartyId  config 通過後の身元の識別子 (host のみ)
     * @param  list<string>  $allowedOrigins  config 通過後の許可する接続元 (空要素除去済み)
     * @param  list<string>  $rawAllowedOrigins  フィルタ前の接続元列 (正規化済み、空要素を保持)
     * @param  bool  $userHandleSecretDeclared  導出鍵が専用 env で宣言されたか
     * @param  string  $userHandleSecret  解決後の導出鍵
     *
     * @throws RuntimeException
     */
    public function validateForProduction(
        string $relyingPartyId,
        array $allowedOrigins,
        array $rawAllowedOrigins,
        bool $userHandleSecretDeclared,
        string $userHandleSecret,
    ): void {
        // 1. 身元の識別子。空 = APP_URL に host が無い (パスキーの手続きが実行時例外になる)。
        if ($relyingPartyId === '') {
            throw new RuntimeException(
                'Passkey relying party id is empty in production. '
                .'Set PASSKEYS_RELYING_PARTY_ID, or make sure APP_URL contains a host. '
                .'See docs/auth-security-mechanisms.md.'
            );
        }

        // 2. 身元の識別子は production で受け付ける dotted DNS 名でなければならない。
        //    IP リテラル / localhost / 単一ラベル / 非 ASCII は WebAuthn の relying party id にできない。
        //    (public suffix かどうかはここでは見ない = PSL を持たない。docblock の限界を参照)
        if (! $this->isDnsName($relyingPartyId) || ! str_contains($relyingPartyId, '.')) {
            throw new RuntimeException(
                'Passkey relying party id is not an accepted production DNS name. '
                .'Set PASSKEYS_RELYING_PARTY_ID to a dotted DNS name (the host part of APP_URL); '
                .'IP addresses, "localhost", single labels and non-ASCII names (use punycode) are rejected. '
                .'(Public suffixes are not rejected here: this check has no Public Suffix List.) '
                .'The offending value is not printed here on purpose.'
            );
        }

        // 3. 接続元の宣言に空要素がある = 設定の書き損じ (末尾カンマ / 連続カンマ)。
        //    config 段で落ちた事実を黙って正規化せず、起動時に表面化させる。
        foreach ($rawAllowedOrigins as $index => $raw) {
            if (trim($raw) === '') {
                throw new RuntimeException(sprintf(
                    'PASSKEYS_ALLOWED_ORIGINS entry #%d is empty (a stray or trailing comma). '
                    .'List each origin exactly once as "https://host[:port]".',
                    $index + 1,
                ));
            }
        }

        // 4. 接続元が 1 件も無いと vendor が手続き実行時に例外を投げる (起動時には落ちない)。
        if ($allowedOrigins === []) {
            throw new RuntimeException(
                'Passkey allowed origins are empty in production. '
                .'Set PASSKEYS_ALLOWED_ORIGINS, or make sure APP_URL contains a scheme and host.'
            );
        }

        foreach ($allowedOrigins as $index => $origin) {
            // 例外文には**位置だけ**を出す (生の設定値は出さない)。
            $position = $index + 1;

            // 5. 書式。scheme は**小文字 https のみ** (production の WebAuthn は TLS 必須)。
            //    path / query / fragment / userinfo / 末尾スラッシュ / 非 ASCII ホストを弾く。
            //    ★ここに非正規形が届くのは「宣言側 (config/fortify.php) を通らない経路が
            //      設定した」場合だけである (docblock の役割分担を参照)。
            if (preg_match('#^https://([a-z0-9.\-]+)(?::(\d{1,5}))?$#', $origin, $m) !== 1) {
                throw new RuntimeException(sprintf(
                    'Passkey allowed origin #%d (PASSKEYS_ALLOWED_ORIGINS) is invalid. '
                    .'Each origin must be "https://dns-name[:port]" with no path, query, userinfo '
                    .'or trailing slash. Plain http, IPv4/IPv6 literals, bracketed hosts and '
                    .'non-ASCII hosts (use punycode) are not accepted in production. '
                    .'The offending value is not printed here on purpose '
                    .'(it would be baked into deploy logs).',
                    $position,
                ));
            }

            $host = $m[1];   // 正規表現が小文字だけを通すので strtolower しない
            $port = $m[2] ?? '';

            if (! $this->isDnsName($host)) {
                throw new RuntimeException(sprintf(
                    'Passkey allowed origin #%d (PASSKEYS_ALLOWED_ORIGINS) has an invalid host. '
                    .'Each label must be 1-63 alphanumeric/hyphen characters and must not start '
                    .'or end with a hyphen.',
                    $position,
                ));
            }

            if ($port !== '' && ((int) $port < 1 || (int) $port > 65535)) {
                throw new RuntimeException(sprintf(
                    'Passkey allowed origin #%d (PASSKEYS_ALLOWED_ORIGINS) has an out-of-range port.',
                    $position,
                ));
            }

            // 5b. 正規形からの逸脱 (既定 port の明示など)。ブラウザは既定 port を申告しないため、
            //     `:443` と書かれた設定は厳密比較に一致せず**全手続きが無言で失敗する**。
            //     正規形の定義は PasskeyOriginCanonicalizer ただ 1 か所に置き、ここでは
            //     「宣言側と同じ器に掛けて変化しないこと」だけを見る (判定基準を割らない)。
            if (PasskeyOriginCanonicalizer::canonicalize($origin) !== $origin) {
                throw new RuntimeException(sprintf(
                    'Passkey allowed origin #%d (PASSKEYS_ALLOWED_ORIGINS) is not in canonical form. '
                    .'Do not declare the default port (":443"); browsers never send it, so the '
                    .'strict comparison in webauthn-lib fails for every ceremony.',
                    $position,
                ));
            }

            // 6. WebAuthn は「身元の識別子が接続元 host と一致するか、その上位ドメインである」
            //    ことを要求する。ここが食い違うと**全ての手続きが失敗する** (登録も検証も)。
            if ($host !== $relyingPartyId && ! str_ends_with($host, '.'.$relyingPartyId)) {
                throw new RuntimeException(sprintf(
                    'Passkey allowed origin #%d (PASSKEYS_ALLOWED_ORIGINS) does not belong to the '
                    .'configured relying party id. The origin host must equal the relying party id '
                    .'or be a subdomain of it, otherwise every passkey ceremony fails. '
                    .'Neither value is printed here on purpose.',
                    $position,
                ));
            }
        }

        // 7. 導出鍵は **APP_KEY から独立して宣言されている**こと。
        //    未宣言だと APP_KEY に倒れ、鍵ローテートで登録済みパスキーが全件無効になる。
        if (! $userHandleSecretDeclared) {
            throw new RuntimeException(
                'PASSKEYS_USER_HANDLE_SECRET is not set in production. '
                .'Without it the passkey user handle is derived from APP_KEY, so rotating APP_KEY '
                .'silently invalidates every registered passkey. '
                .'When migrating an environment that already has passkeys, declare the current APP_KEY value. '
                .'See docs/auth-security-mechanisms.md.'
            );
        }

        if (strlen($userHandleSecret) < self::MIN_USER_HANDLE_SECRET_LENGTH) {
            throw new RuntimeException(sprintf(
                'PASSKEYS_USER_HANDLE_SECRET is shorter than %d characters. '
                .'Use a long random value (e.g. php -r "echo bin2hex(random_bytes(32));").',
                self::MIN_USER_HANDLE_SECRET_LENGTH,
            ));
        }
    }

    /**
     * DNS 名として妥当か (ラベル単位で検査する)。
     *
     * 包含正規表現 `[A-Za-z0-9.-]+` だけでは `-example.com` / `example..com` /
     * `example.com.` が通ってしまうため、ドットで分割して 1 ラベルずつ見る。
     *
     * ⚠ これは純粋な DNS 構文検証ではなく **production の WebAuthn 用に採用する DNS 名の方針**である
     * (DNS 構文自体は全数字の末尾ラベルも大文字も禁止していない)。
     *
     * **大文字を受理しない**のは、宣言側 (config/fortify.php) が小文字へ正規化するためである。
     * ここに大文字が届くのは別経路が未正規化のまま設定した場合で、その値は
     * webauthn-lib の strict 比較に一致せず**全手続きを無言で失敗させる**。
     *
     * **非 ASCII を受理しない**のは punycode 変換を実装しない方針の帰結である
     * (ラベルの字形を `[a-z0-9-]` に限るので、非 ASCII のバイト列はここで落ちる)。
     *
     * **末尾ラベルに英字を 1 文字以上要求する**のは、`192.168.001.001` のような
     * 「filter_var では IP と認められないが実質 IP アドレスの書き損じ」を弾くため
     * (全数字の TLD は存在しない)。punycode (`xn--p1ai`) は英字を含むので通る。
     */
    private function isDnsName(string $host): bool
    {
        if ($host === '' || strlen($host) > self::MAX_DNS_NAME_LENGTH) {
            return false;
        }

        // IPv4 / IPv6 リテラルは relying party id にも origin host にも使えない。
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        $labels = explode('.', $host);

        foreach ($labels as $label) {
            if ($label === '' || strlen($label) > self::MAX_DNS_LABEL_LENGTH) {
                return false;   // 空ラベル = 連続ドット / 先頭ドット / 末尾ドット
            }
            if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
                return false;   // ハイフン開始 / ハイフン終了 / 大文字 / 非 ASCII / 不正文字
            }
        }

        // 末尾ラベル (TLD 相当) は英字を含むこと。
        return preg_match('/[a-z]/', $labels[count($labels) - 1]) === 1;
    }
}
