# 対応マトリクス: design-review Round 2 (判定 CHANGES_REQUESTED)

## [Critical] G: 走査根を `app/` だけにすると `routes/` 等からの迂回を検出できない
- 判断: 対応する
- 根拠: 指摘のとおり。`routes/` のクロージャや seeder からの
  `PromptDefense::load()` / `new GuardedPrompt()` / `Prompt::load()` は Prism 直呼びではないため
  施策 I-1 でも捕まらず、「1 本道」の保証が成立していなかった。
- 対応内容: 検査ごとに母集団を分ける表を施策 G に新設した。
  呼び出し site の検査 (#2 / #3 / #5 / #5b) は **5 根** (`app/` `routes/` `database/` `config/`
  `bootstrap/`)、所有権 (#4) と reflection (#6 / #9) は `app/`、`tests/` は常に母集団外。
  「走査の母集団」節も同じ内容へ書き換えた (一括表現をやめた)。

## [Critical] G: 検査 #4 が `GuardedPrompt` 自身の `PromptCanary` 参照と両立しない
- 判断: 対応する
- 根拠: 実装不能な検査だった (`GuardedPrompt` は constructor / property 型として正当に参照する)。
- 対応内容: 許可集合を責務別にした。
  `UserInput` / `UntrustedTextSanitizer` は `PromptDefense.php` のみ、
  `PromptCanary` は `PromptDefense.php` と `GuardedPrompt.php` のみ。

## [Warning] G: `template:` がリテラルであることの明記が無い
- 判断: 対応する
- 対応内容: 検査 #7b を新設。`template:` は文字列リテラルで、その値が対応 YAML の
  **ファイル名 (拡張子なし)** と **`name` キー**の両方に一致することを pin する。

## [Warning] A: `max_untrusted_bytes` の根拠 (token → バイトの上界) が成立していない
- 判断: 対応する
- 根拠: 指摘のとおり、1 token が復号後に何バイトになるかは tokenizer 依存で、
  「4 バイト/token」は上界の証明にならない。
- 対応内容: 断定を削除し、「正常系の実測より十分大きい防御上限であり、
  当たること自体が異常事態の合図」という位置づけに書き換えた。値の妥当性は
  実装後に `dev:pipeline-smoke` と `llm_call_logs` の実測で**追認**する (リスク節に明記)。
  値そのものは変えない (仕組みが機能していない段階で値を弄らない)。

## [Warning] C: 空白除去の `/u` が不正 UTF-8 で fail-open する
- 判断: 対応する
- 根拠: 合言葉は ASCII hex なので Unicode モードは不要で、`preg_replace` の false を
  「漏洩なし」と読むのは安全側ではなかった。
- 対応内容: `/[[:space:]]+/` (バイト列) に変え、正規化に失敗したら**漏洩ありとみなす**
  (fail-closed)。テスト計画に「不正 UTF-8 を含む応答で fail-open しない」を追加。

## [Warning] F: `unsafeResponse()` が原因を手順書と断定している
- 判断: 対応する
- 根拠: 合言葉が保証するのは検知事実だけで、モデル / provider 側の異常もありうる。
  原因を断定すると正当な SOP の記述を削らせる誘導にもなる。
- 対応内容: 「安全検査により、AI の応答を受け取れませんでした。もう一度実行しても
  解消しない場合は、管理者へ連絡してください。」へ書き換え、docblock に断定しない理由を書いた。

## [Warning] F / J: `InvalidEncoding` のテストが無い
- 判断: 対応する
- 対応内容: 施策 F のテスト計画を「3 拒否 × 4 点 (再試行回数 / `failed` / 文言 /
  チケット release)」の表にした。施策 J にも `InvalidEncoding` と
  「合言葉検査が fail-open しない」「`routes/` 相当の合成負例」を追加した。

## [Warning] K: 文書の記述が gate の実保証を上回る
- 判断: 対応する
- 対応内容: `docs/architecture.md` の節に「gate の母集団を検査ごとに書く」を追加し、
  「保証しないもの」に文言の非断定・`max_untrusted_bytes` が上界の証明ではないことを足した。

## [Suggestion] I: 節タイトルが「3 本」のまま
- 判断: 対応する
- 対応内容: 「既存 gate 4 本の射程更新」へ修正 (施策一覧も同様)。
