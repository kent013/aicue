<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\App;
use Illuminate\Validation\Rules\Password;

/**
 * パスワード強度ポリシーの単一情報源 (SSOT)。
 *
 * 強度ルール (min12 + mixedCase + numbers) と、フォーム表示用の日本語要件文を一元集約する。
 * `AppServiceProvider` の `Password::defaults()` と、登録 / リセット画面の表示要件の双方が
 * 本 helper を参照することで、要件変更を 1 箇所に閉じ込める (文言/ルールの二重管理を排除)。
 */
final class PasswordPolicy
{
    /** 最小文字数 (強度ルールと要件文で共有する単一定数)。 */
    public const int MIN_LENGTH = 12;

    /**
     * HIBP 漏洩照合 (uncompromised) を無効化する APP_ENV 値の denylist (SSOT)。
     *
     * fail-secure: 有効化する env の allowlist ではなく無効化する env の denylist を採り、
     * 未知 env (staging/preprod/qa/review 等の実運用・準本番ミラー) を既定 ON に倒す。
     * 値は APP_ENV (App::environment()) と照合する env 名であり host 名ではない
     * (設計根拠の詳細は conceptual-design.md を参照)。
     *
     * @var list<string>
     */
    private const array PWNED_CHECK_DISABLED_APP_ENVS = ['local', 'testing', 'bughunt.local'];

    /**
     * 現在の実行環境で HIBP 漏洩照合 (uncompromised) を付与すべきかを判定する単一述語。
     *
     * - production は不変条件として無条件で有効 (denylist 編集ミスでも外れない fail-secure guard)。
     * - それ以外は APP_ENV が denylist (既知の開発/テスト env) に無い場合のみ有効 = 未知 env は既定 ON。
     * fake_externals は判定に用いない: fake が有効化され得る env (local/testing/bughunt.local) は
     * すべて denylist に含まれ推移的に無効になるため、責務は APP_ENV のみに閉じる。
     */
    public static function shouldCheckPwned(): bool
    {
        // 本番は不変条件: 無条件で有効。万一 denylist に production を誤って加えても外れない guard。
        if (App::environment('production')) {
            return true;
        }

        // 既知の開発 / テスト env のみ無効。未知 env は既定 ON (fail-secure)。
        return ! App::environment(self::PWNED_CHECK_DISABLED_APP_ENVS);
    }

    /**
     * 強度ルールを構築する。min12 + mixedCase + numbers は全環境共通。
     * HIBP 漏洩照合は shouldCheckPwned() が true の環境でのみ付与する (判定根拠は同メソッド参照)。
     */
    public static function rule(): Password
    {
        $rule = Password::min(self::MIN_LENGTH)->mixedCase()->numbers();

        return self::shouldCheckPwned() ? $rule->uncompromised() : $rule;
    }

    /**
     * バリデーション用のルール配列。`Password::defaults()` / FormRequest から参照する。
     *
     * @return array<int, Password>
     */
    public static function rules(): array
    {
        return [self::rule()];
    }

    /**
     * フォーム表示用の日本語要件文。UI へ配布する SSOT。
     *
     * @return array<int, string>
     */
    public static function describe(): array
    {
        return [
            self::MIN_LENGTH.'文字以上',
            '大文字・小文字を含む',
            '数字を含む',
        ];
    }
}
