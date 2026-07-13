# 対応マトリクス: design-review Round 2

全体判定: **CHANGES_REQUESTED**。残 1 点 (有償 base Price の独立不変条件テスト) を追加。

## [Warning] 施策2 の drift 検知が同一判定式依存で成立しない (施策2/4)
- 判断: 対応する
- 根拠: 施策2 は分岐選択 (`$isPaid`) と期待値の両方を同じ `currentPrice(Base)` から導出するため、
  standard の base Price が欠落すると free 扱いで silently pass し drift を検知できない。Codex 指摘は正当。
- 対応内容: **施策4 を新設** (`tests/Feature/Billing/PlanSeederPriceInvariantTest.php`)。
  判定式に依存せず、`Plan::where('code','standard')` の `currentPrice(Base)` が non-null であること、
  および free が Price を持たないことを独立検証する。プラン名参照は fixture 仕様検証であり本番ロジックの
  能力分岐ではない (Codex が明示容認)。施策1 リスク節の記述も施策4 参照へ更新。

## [確認] 施策1・施策3 は APPROVE
- 施策1: attachFakeActiveSubscription のメソッド単体冪等化・currentPrice 値ベース判定・sub_seed_ 命名すべて容認。
- 施策3: 修正前 fail / 修正後 pass の回帰として機能。ロール dataset + assertOk + Inertia component 検証で十分。
