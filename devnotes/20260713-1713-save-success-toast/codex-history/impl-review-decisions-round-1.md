# 対応マトリクス: impl-review Round 1

## [Critical] PasswordResetResponse: JSON `message` の `(string) __($this->status)` が型安全性が弱い
- 判断: 対応する (ただし Codex 提案の `trans()` 置換とは異なる方法で解決)
- 根拠: `trans()` も宣言型は `array|string` であり `__()` と等価のため、`trans()` に置換しても PHPStan Lv10 の型不整合は解消しない。真の narrowing は「配列でないこと」を明示することであり、AGENTS.md も `Webmozart\Assert\Assert` の活用を推奨している。キャスト (`(string)`) は array が来た場合 "Array" 文字列 + warning を生む silencing になるため、Assert で不変条件 (status は単一言語キー=string) を実行時にも保証する形へ変更した。
- 対応内容: `$message = __($this->status); Assert::string($message);` に変更し `use Webmozart\Assert\Assert;` を追加。PHPStan は Assert 拡張により string へ narrow。composer phpstan OK / Feature 11 passed で確認。

## [Warning] コメントの「既定準拠」表現が広く web 文言固定との差分が読み取りづらい
- 判断: 対応する
- 根拠: 誤読低減は低コストで有益。
- 対応内容: docblock を「web の redirect flash は汎用 success 文言へ寄せる／JSON message のみ既定の localize status を維持 (差分は web redirect の flash キー・文言のみ)」と明示化。

## [Suggestion] toResponse の引数を `Request $request` で明示
- 判断: 見送る
- 根拠: Fortify の `PasswordResetResponse` interface は `toResponse($request)` を型なしで宣言している。実装側でパラメータ型を追加すると型の narrowing になり PHP の LSP 制約 (パラメータは反変) に反して fatal error になる。既存の Response family (`ProfileUpdatedResponse` / `PasswordUpdatedResponse` / `TwoFactorDisabledResponse` 等) も一貫して型なし `$request` + `@param Request $request` docblock で統一しており、docblock で静的解析上の型は既に付与済み。family の一貫性と互換性維持のため現状を維持する。
