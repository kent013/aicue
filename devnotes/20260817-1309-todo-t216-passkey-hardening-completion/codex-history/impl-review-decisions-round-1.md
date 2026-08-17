# 対応マトリクス: impl-review Round 1

Codex の全体判定は **APPROVED**。Critical は 0 件。Warning 3 件・Suggestion 多数の扱いを記録する。

## [Warning] vendor のトランザクション検出は字句ベースで、helper 経由には沈黙する

- 判断: 見送る (現状のままとする)
- 根拠: 設計が最初からこの限界を受け入れており、関数名 (`declaresCommonTransactionBoundary`) と
  docblock と失敗メッセージの 3 か所に限界を書いてある。網羅を主張する検出器へ広げると、
  「何を保証しているか」が読めなくなる (思考原則 2)。
- 対応内容: コード側の変更なし。`docs/auth-security-mechanisms.md` §5 の追記でも
  「前提の固定は `PasskeyPackageContractTest`」と書き、網羅とは書いていない。

## [Warning] 生値非露出テストの母集団に「接続元 0 件」と導出鍵系が入っていない

- 判断: 対応しない (設計どおり)
- 根拠: この 2 つの例外文は**そもそも設定値を引数に取らない**
  (`allowed origins are empty` は値を持たず、導出鍵系は環境変数名と最小長しか出さない)。
  露出しうる値が存在しない経路をテストの母集団へ入れると、
  「何を守っているか」が薄まる方向に効く。
- 対応内容: 変更なし。露出しうる 3 種 (接続元の文字列全体 / そのホスト部 /
  身元の識別子) を持つ違反経路はすべて母集団に入れてある。

## [Warning] 提示 diff に `docs/` と `.env.example` が含まれず、件数一致を確認できない

- 判断: 反論する (事実誤認ではなく、送った diff の範囲の問題)
- 根拠: `app-implement` スキルの規定どおり diff は `app/ resources/ tests/ routes/`
  (+ 本タスクでは `config/`) に絞って送っている。`docs/template-divergence.md` の
  D25 追加と冒頭件数 24 件への更新、`docs/auth-security-mechanisms.md` §5 の書き換え、
  `.env.example` の注記はいずれも同じコミットに含まれる。
- 対応内容: 変更なし。3 点一致 (冒頭件数 / 定数 / 実エントリ数) は
  `TemplateDivergenceLedgerFormatTest` が機械判定し、実装前に件数不一致の赤を実測済み。

## [Suggestion] 群

- いずれも「設計どおりであることの確認」であり、変更を要求していない。対応なし。
