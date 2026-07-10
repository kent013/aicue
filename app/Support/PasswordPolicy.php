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
     * 強度ルールを構築する。
     *
     * min12 + mixedCase + numbers は全環境共通。HIBP 漏洩照合 (uncompromised) は
     * テスト実行時のみ除外する (外部依存 / flaky 回避。local/staging/prod では有効)。
     */
    public static function rule(): Password
    {
        $rule = Password::min(self::MIN_LENGTH)->mixedCase()->numbers();

        return App::runningUnitTests() ? $rule : $rule->uncompromised();
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
