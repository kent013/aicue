# 対応マトリクス: design-review Round 3

Round 3 の判定: **Critical 0 件**。全施策 APPROVE、テスト計画に文面矛盾 2 件の [Warning] のみ。
レビューは最大 3 ラウンドの上限に達したため、以下 2 件を**その場で文面修正して確定**した
(新たな設計変更ではなく、Round 2 の設計変更に追随できていなかった記述の是正)。

## [Warning] (施策 7) vitest #30 が旧設計 (配列全行表示) のまま残っている
- 判断: **対応する**
- 対応内容: #30 を「`errors.account` の**単一要約文字列**を danger Alert に表示する
  (現行の `string | string[]` 正規化のまま・配列表示にしない)。複数 blocker の詳細は
  `accountDeletionBlockers` の warning Alert に全件表示される」に修正。

## [Warning] (施策 7) transport テストの検証地点が曖昧
- 判断: **対応する**
- 対応内容: #16b を「**redirect back 後の `GET /settings` の Inertia props まで通して**、
  同一 `assertInertia` 内で (a) `errors.account` が単一文字列 / (b) 両組織の必要対応を含む /
  (c) `accountDeletionBlockers` が 2 件 / (d) 各 blocker の `actions` が期待どおり、を固定する。
  session の MessageBag だけを見ない」に具体化。

## 最終状態
- Critical: 0
- 未解消の Warning: 0 (上記 2 件は文面修正で解消)
- 施策 1〜6・8 は Round 3 で APPROVE 済み。施策 7 も上記修正で Round 3 の指摘を満たす
