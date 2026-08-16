# 対応マトリクス: design-review Round 5 (最終)

全体判定 **APPROVED**。Critical 0 件 / Warning 0 件 / Suggestion 0 件。
施策 1-6 すべて APPROVE。**対応すべき指摘は無い**。

Codex が最終確認した成立条件 (実装時の受け入れ確認に使う):

- `function runSettled(...)` の宣言行を呼び出しとして数えない
- `runSettled(() => …)` は `ARROW_DEFINITION` に一致せず、通常どおり呼び出しとして数える
- 1 行形式・複数行形式のどちらの文レベル arrow function からの直接呼び出しも
  `fromNamedFunction: false` になる
- 現行コードでは 8 件を収集し、すべて `fromNamedFunction: true` になる
- 字句走査の保証外が明示されており、検査能力を誇張していない

## 合議の経緯 (ラウンド別)

| ラウンド | 判定 | 主な指摘 | 対応 |
|---|---|---|---|
| 概念 R1 | APPROVED | Warning 4 (ダイアログ表示側 / throw 経路の主張 / 解決不能時の UI / 引数の型で key 前提を明示) | 全件反映 |
| 詳細 R1 | CHANGES_REQUESTED | Warning 2 (`removePoint` の no-op が未検証 / 棚卸しが散文のまま) | テスト 6→8 件、施策 6 (目録テスト) 新設 |
| 詳細 R2 | CHANGES_REQUESTED | Warning 4 (`steps[-1]` の挙動誤認 / 成立しない負の実装 (c) / 変種記号の不整合 / 目録テストの帰属と保証範囲) | 全件こちらの誤りとして訂正 |
| 詳細 R3 | CHANGES_REQUESTED | Warning 2 (`runSettled` 宣言行を 9 件目に数える / assertion コメントが保証を超える) + Suggestion 1 | 全件訂正 |
| 詳細 R4 | CHANGES_REQUESTED | Warning 1 (arrow 判定が呼び出し判定より後で 1 行形式を弾けない) | 判定順を訂正 |
| 詳細 R5 | **APPROVED** | なし | — |

## 最終確認 (app-design Phase 2-5: 使命・禁止事項チェック)

- **使命への寄与**: 施策 1-6 はすべて「シナリオ編集面で日本語入力中に別の手順が消える /
  別の手順に急所が生えるデータ喪失」を塞ぐためのものである。編集面が信用できることは
  「専門知識ゼロの現場作業者が標準化されたマニュアル動画を作れる」ための前提であり、
  使命に直接寄与する。装飾的な機能追加は 1 つも含まない。
- **禁止事項**: 1 (テストなしの完了報告) は施策 4・5・6 で満たす。
  8 (disabled にしない) は `disabled` を 1 つも増やさないことで満たす。
  2-7 は PHP / LLM / HTTP 応答の規約で、サーバ側を一切変更しない本設計には該当しない。
  9 (Artifact 禁止) — 成果物はすべて `devnotes/` 配下のファイルとして出力しており違反なし。
- **コーディングルール**: テスト必須は施策 4・6 で、早期 return は施策 1-3 の実装で、
  型安全は `pnpm typecheck` での検出条件として設計に反映済み。
  新しい component / icon / DS token / 型定義は 1 つも増やさない。
