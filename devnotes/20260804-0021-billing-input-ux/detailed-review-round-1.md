**全体判定**  
- **CHANGES_REQUESTED**

**施策別判定**
- 施策1（readonly 視覚状態）: **APPROVE**
- 施策2（client エラー追随）: **APPROVE**
- 施策3（全 form `novalidate`）: **REQUEST_CHANGES**
- 施策4（DESIGN.md 昇格）: **APPROVE**
- 施策5（不変条件テスト）: **REQUEST_CHANGES**

**[Critical]**
- なし

**[Warning]**
- 施策3/5: `form-novalidate` の architecture テストを生テキスト正規表現で走査すると、`<script>` 内文字列やコメント中の `<form` を誤検出する偽陽性リスクがあります（`tests/js/architecture/form-novalidate.test.ts` 新規案）。  
  **修正案**: `svelte/compiler` で AST 解析し、`Element(name==="form")` の属性配列先頭が `novalidate` かを判定してください（行番号は `node.start` から算出）。これで「`<form` 直後」規約を維持したまま偽陽性/偽陰性を大幅に減らせます。

**[Suggestion]**
- 施策1: `bg-surface` を `inputStateClass()` 側へ寄せる判断は妥当です（Tailwind v4 の同一プロパティ競合回避）。将来の再発防止として、`Input`/`Textarea` 呼び出し側で `class` に `bg-*` を渡さない lint/architecture ルール追加を検討してください（`resources/js/components/atoms/input-state.ts:1`）。
- 施策2: `boolean + $derived` は本件（3種エラー）に適合しており、T041/T044 の `$effect` 連動クリアを無理に統一しない判断も妥当です。規約乖離回避のため、DESIGN.md に「不変条件が同じなら既存実装は許容、新規は `$derived` 推奨」を明文化すると運用が安定します（`resources/js/components/features/billing/AutoRechargeCard.svelte:45`, `resources/js/pages/Billing/PurchaseTickets.svelte:59`, `resources/js/pages/Organizations/Settings.svelte:112`）。
- 施策5: jsdom で native validation ブロックを再現できない前提は妥当です。補強するなら 1 本だけブラウザ E2E（WebKit か Chromium）で「invalid email でも submit ハンドラ到達」を smoke 化すると、将来のテスト実行漏れに強くなります。

**論点への回答**
- 1) 妥当です。`$derived` への切替は stale 文言防止の根治で、先行2実装を非改変にする判断も churn 回避として合理的です。  
- 2) 妥当です。`bg-surface` の移動は正しい対処です（より良い手は「bg 上書き禁止ルール」の追加）。  
- 3) 方針自体は妥当ですが、正規表現実装は改善必須です（AST 化推奨）。  
- 4) テスト計画は概ね禁止事項1に耐えます。`novalidate` を属性不変条件で固定する割り切りは適切です。追加するとしたら E2E 1 本の最小補強です。