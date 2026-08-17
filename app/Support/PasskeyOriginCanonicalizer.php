<?php

declare(strict_types=1);

namespace App\Support;

/**
 * パスキーの「許可する接続元」の正規形を決める唯一の場所。
 *
 * ⚠ **本クラスは接続元だけを扱う**。身元の識別子 (relying party id) には適用しない —
 * パスキーは身元の識別子に束縛されるため、この値を書き換える処理を増やすと
 * **登録済みパスキーが全件使えなくなる**方向の事故を作る。
 *
 * ⚠ **妥当性は判断しない**。本クラスが対象にするのは
 * 「`scheme://host[:port]` の形へ**分解できる**値」だけで、分解できない文字列
 * (path / query / fragment / 利用者情報 / 角括弧の IPv6 / 余分なコロン) には
 * **構造的な変形を加えず**、前後空白の除去と小文字化だけを施した値を返す。
 * 分解できた値についても、ホスト名として妥当かどうかは見ない
 * (`-app.example.com` / `app..example.com` / IP リテラルは正規化の対象に入る)。
 * **妥当性の判断は検証器 (PasskeyConfigValidator) 1 か所に置く** —
 * DNS 名の規則を 2 か所に書くと必ず食い違うためである。
 * 正規化しても不正な値が有効化されることは無い (検証器が同じ理由で拒否し続ける。
 * 境界値が拒否され続けることは検証器側のテストで固定する)。
 *
 * ⚠ **純粋な静的関数**である (config/fortify.php の評価時に呼ばれるため)。
 * サービスコンテナ解決・入出力・設定の読み出し・例外送出のいずれも行わない
 * (この性質は PasskeyOriginCanonicalizerTest が字句で固定する)。
 *
 * 正規形へ寄せる変形は 3 つだけ:
 *   1. 前後空白の除去と小文字化 (RFC 3986 上 scheme と host は大小文字を区別しない)
 *   2. 根を表す末尾スラッシュ 1 個の除去 (裁定 2026-08-04「末尾スラッシュは正規化受理で統一」)
 *   3. scheme に対応する既定 port の除去 (https は 443 / http は 80)
 *
 * 3 が要る理由: ブラウザが申告する接続元は既定 port を含まない。
 * 照合は webauthn-lib の `in_array(..., true)` = **厳密な文字列比較**なので、
 * `https://example.com:443` と書いた設定は一致せず**全ての手続きが無言で失敗する**。
 */
final class PasskeyOriginCanonicalizer
{
    /** scheme ごとの既定 port (書かれていても意味を持たない port) */
    private const DEFAULT_PORTS = ['https' => 443, 'http' => 80];

    /** 接続元 1 件を正規形へ寄せる (解釈できない値は小文字化して返すだけ)。 */
    public static function canonicalize(string $origin): string
    {
        $value = strtolower(trim($origin));

        // scheme://host[:port][/] へ**分解できる**値だけを対象にする。
        // ホスト部の字形を `[a-z0-9.-]+` に限るので、利用者情報 (`user@…`) /
        // 角括弧の IPv6 / 余分なコロン / path / query / fragment を持つ値は一致せず、
        // **そのまま返す** (検証器が位置付きで拒否する)。
        // ★ここでホスト名の**妥当性**は見ない (ラベル規則は検証器 1 か所に置く)。
        if (preg_match('#^([a-z][a-z0-9+.\-]*)://([a-z0-9.\-]+)(?::(\d{1,5}))?/?$#', $value, $matches) !== 1) {
            return $value;
        }

        $scheme = $matches[1];
        $host = $matches[2];
        $port = $matches[3] ?? '';

        if ($port !== '' && (self::DEFAULT_PORTS[$scheme] ?? null) === (int) $port) {
            $port = '';
        }

        return $scheme.'://'.$host.($port === '' ? '' : ':'.$port);
    }

    /**
     * 宣言 (CSV) から接続元の列を作る。**空要素は落とさない**
     * (設定の書き損じ = 余分なカンマ を起動時に表面化させるため)。
     *
     * @param  string|null  $declared  PASSKEYS_ALLOWED_ORIGINS の宣言値 (未宣言は null)
     * @param  string  $derivedOrigin  APP_URL から導出した接続元 (宣言が無いときの既定)
     * @return list<string>
     */
    public static function declaredList(?string $declared, string $derivedOrigin): array
    {
        $csv = $declared === null ? '' : trim($declared);

        // 宣言が無い / 空文字なら APP_URL からの導出 1 件に倒す
        // (env ファイルにキーだけ残す運用を壊さないため、空文字は「未宣言」と同じ扱い)。
        if ($csv === '') {
            return [self::canonicalize($derivedOrigin)];
        }

        return array_map(self::canonicalize(...), explode(',', $csv));
    }
}
