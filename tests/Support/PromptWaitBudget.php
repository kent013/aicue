<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * prompt YAML が宣言する「LLM の待ち予算」(`client_options.timeout`) の**唯一の読み取り器**。
 *
 * lctl 機能台帳 feature `llm-prompt-wait-budget` の正典 v1 (単一読み取り器 + 検出器自己テスト形)。
 * 設計: devnotes/20260824-1016-llm-prompt-wait-budget-v1/
 *
 * 【走査対象】呼び出し側が渡した prompt YAML の絶対パス 1 本。
 *   分母の列挙 (再帰全数走査) は `Tests\Support\PromptYaml::paths()` の責務であり、
 *   本クラスは**列挙しない** (判定だけを持つ)。
 *
 * 【既定拒否】次はすべて違反である。
 *   1. `client_options` が無い (未宣言)
 *   2. `client_options` が配列でない
 *   3. `client_options.timeout` キーが無い
 *   4. `timeout` が `is_int()` でない (数値文字列 `"300"` / 小数 / 真偽値 / 空値を含む)
 *   5. `timeout` が `<= 0`
 *
 * 【公開面】用途の違う 2 口だけを公開し、**どちらも検査済みの結果しか返さない**。
 *   未検査の `timeout` を外へ出す口を作らないので、呼び出し側に
 *   `is_int()` / `> 0` の再判定 (= 第 4 の読み取り実装) が生まれない。
 *   - `violations()`: gate 用。違反ラベルの列 (空なら適合)
 *   - `requirePositive()`: 仕様値との突合用。違反が 1 件でもあれば例外、適合なら正の整数
 *   判定の純関数 `evaluate()` は private である (自己テストは公開 2 口を通して行う)。
 *
 * 【解決できない形は落とす (fail-closed)】3 段のどこでも**無言で null を返さない**。
 *   段 1: ファイルが無い → 独立したラベル。`Yaml::parseFile()` は不在も `ParseException`
 *         にするため、段 2 に混ぜると「構文が壊れている」と区別できなくなる。
 *         走査由来のパスでは起きないが `requirePositive()` は名前から組んだパスを受ける
 *         (prompt の改名で現実に起きる)。
 *   段 2: parse 不能 / 最上位が map でない → `PromptYaml::parseOrFail()` が積む既存の 2 ラベル。
 *         ★段 2 の fail-closed は**共有ヘルパの契約に依存する** — 「null を返すときは
 *         必ず理由を 1 件以上積む」ことが前提であり、これが崩れると `violations()` だけが
 *         空 (= 適合) を返して公開 2 口が非対称になる。**前提そのものを自己テストが
 *         明示的に固定する** (`PromptWaitBudgetTest` の「共有ヘルパは解決不能形で必ず理由を積む」)。
 *         読み取り器側に到達不能な guard を積む形は採らない (発火しない防御コードは
 *         裏取りできず、AGENTS.md 共通規約 (c) を満たせないため)。
 *         **本クラスは自前の `catch` を書かない** (分類は既存の共有ヘルパに従う)。
 *         ★ただし同ヘルパは `Yaml::parseFile()` の投げる `Throwable` をまとめて
 *         「parse 失敗」へ分類するため、**構文エラーと vendor 内部のエラーの区別までは
 *         保証しない** (ヘルパは採用時債務として凍結されており本 PR では変えない)。
 *   段 3: 上記 5 類型 → `evaluate()` が積む。
 *
 * 【保証しないもの (誇張しない)】
 *   - **宣言値が実効値であることは主張しない**。見るのは宣言の有無と型と正負だけである。
 *     実効性は 3 つの前提に依存し、**そのどれも本クラスは見ていない** —
 *     (i) `app/Prompts/` の factory が vendor の `$clientOptions` クラスプロパティを
 *     設定しないこと、(ii) `resources/prompts` を読む非 PHP の実装が無いこと、
 *     (iii) vendor の解決順序が「クラスプロパティ > YAML > config」であること。
 *     2026-08-24 に実読で確認した事実であり、機械では見ていない。
 *     崩れたときの手当ては `docs/architecture.md` §AI 解析ジョブの運用契約 に書いてある。
 *   - **待ち予算の値の妥当性は判定しない** (360 秒が適切かは本クラスの範囲外)。
 *   - **4 本目の読み取り実装が生まれることは機械では止めない**。字句走査では
 *     「読み取り実装」と失敗メッセージ中の文字列を区別できず、区別のために目録へ
 *     メッセージ側まで登録すると保護しないのに更新が要る目録になるため作らない。
 *     唯一の読み取り器であることは本 docblock の宣言とレビューが担う。
 *   - 段 2 のラベルは共有ヘルパが**絶対パス**で積む (段 1・段 3 は `$label`)。
 *     ラベル集合での照合は段 3 に対してのみ成立する。
 *   - **parse 段の失敗分類は `PromptYaml::parseOrFail()` に従う**。同ヘルパは
 *     `Yaml::parseFile()` の `Throwable` を parse 失敗へ分類するので、構文エラーと
 *     vendor 内部エラーの区別は保証しない。
 */
final class PromptWaitBudget
{
    /** インスタンス化しない (判定の置き場)。 */
    private function __construct() {}

    /**
     * 待ち予算の契約違反 (gate 用)。適合なら空配列。
     *
     * @param  string  $absolutePath  読む YAML の絶対パス
     * @param  string  $label  違反メッセージに出す識別子 (走査根からの相対パス等)
     * @return list<string>
     */
    public static function violations(string $absolutePath, string $label): array
    {
        return self::read($absolutePath, $label)['violations'];
    }

    /**
     * 仕様値との突合に使う正の整数。違反が 1 件でもあれば例外にする。
     *
     * ★`violations()` と**同じ private 判定**を通るので、「gate は落とすが突合は通る」
     *   食い違いが構造的に起きない。
     *
     * @throws RuntimeException 契約違反 (未宣言 / 型違い / 非正 / 解決不能)
     */
    public static function requirePositive(string $absolutePath, string $label): int
    {
        $result = self::read($absolutePath, $label);

        if ($result['violations'] !== []) {
            throw new RuntimeException(
                'LLM の待ち予算の宣言が契約を満たしていません: '.implode(' / ', $result['violations']),
            );
        }

        $timeout = $result['timeout'];
        if ($timeout === null) {
            // 到達しない (違反 0 件なら evaluate() は必ず int を返す)。
            // 到達したら読み取り器の不変条件が壊れているので黙って 0 を返さず落とす。
            throw new RuntimeException("待ち予算の読み取りが不整合です: {$label}");
        }

        return $timeout;
    }

    /**
     * 読み取りの 3 段 (ファイル存在 → parse → 判定)。
     *
     * @return array{timeout: int|null, violations: list<string>}
     */
    private static function read(string $absolutePath, string $label): array
    {
        if (! is_file($absolutePath)) {
            return self::rejected("{$label}: prompt YAML が無い ({$absolutePath})");
        }

        /** @var list<string> $parseErrors */
        $parseErrors = [];
        $parsed = PromptYaml::parseOrFail($absolutePath, $parseErrors);
        if ($parsed === null) {
            return ['timeout' => null, 'violations' => $parseErrors];
        }

        return self::evaluate($parsed, $label);
    }

    /**
     * parse 済みの YAML から待ち予算と契約違反を返す (判定の純関数)。
     *
     * @param  array<string, mixed>  $parsed
     * @return array{timeout: int|null, violations: list<string>}
     */
    private static function evaluate(array $parsed, string $label): array
    {
        if (! array_key_exists('client_options', $parsed)) {
            return self::rejected("{$label}: client_options が無い (LLM の待ち予算が未宣言)");
        }

        // 配列 offset 式のままだと narrowing が保たれないためローカル変数へ移す
        $options = $parsed['client_options'];
        if (! is_array($options)) {
            return self::rejected(sprintf(
                '%s: client_options が配列ではない (%s)', $label, get_debug_type($options),
            ));
        }

        if (! array_key_exists('timeout', $options)) {
            return self::rejected("{$label}: client_options.timeout が無い (LLM の待ち予算が未宣言)");
        }

        $timeout = $options['timeout'];
        if (! is_int($timeout)) {
            return self::rejected(sprintf(
                '%s: client_options.timeout が整数ではない (%s)。数値文字列 ("300") も許さない',
                $label, get_debug_type($timeout),
            ));
        }

        if ($timeout <= 0) {
            return self::rejected(sprintf(
                '%s: client_options.timeout が %d (正の整数でなければならない)', $label, $timeout,
            ));
        }

        return ['timeout' => $timeout, 'violations' => []];
    }

    /** @return array{timeout: null, violations: list<string>} */
    private static function rejected(string $message): array
    {
        return ['timeout' => null, 'violations' => [$message]];
    }
}
