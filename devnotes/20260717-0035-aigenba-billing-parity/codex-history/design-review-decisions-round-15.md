# 対応マトリクス: design-review Round 15（CHANGES REQUESTED / Critical 1）

## [Critical] T1004 が通常の Stripe payload で発火しない（`subscriptionIdFrom()` が展開済み array しか扱わない）
- 判断: 対応する（**実害のある指摘**。`checkout.session.completed` の `data.object.subscription` は **expandable field** で、
  **expand 指定の無い通常の payload では string ID**（`"sub_xxx"`）で来る。array 前提だと **本番で Job が一度も dispatch されない**）
- 対応:
  1. **契約を明示**: `subscriptionIdFrom(array $object): ?string` は **string と `array{id: string}` の両方を受理**する。
     実装を明記: `$v = $object['subscription'] ?? null; if (is_array($v)) { $v = $v['id'] ?? null; }
     return is_string($v) && $v !== '' ? $v : null;`（それ以外の型は null = fail-closed で dispatch しない）。
  2. **dispatch 箇所のコメント**に「通常 payload は string ID が主経路。array は expand 済みのみ。array 前提だと本番で
     発火しない」を明記（同じ取り違えの再発防止）。
  3. **テスト 50 を必須で拡張**: **(a) string ID `['subscription' => 'sub_x']` → dispatch される（本番の主経路）** /
     (b) expanded object → id を取り出して dispatch / (c) null・空文字・その他の型 → dispatch されない（fail-closed）。
