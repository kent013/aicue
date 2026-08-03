全体判定: **APPROVED**

**1. 使命との整合性**
- [Suggestion] 本件は North Star への直接機能追加ではありませんが、「課金・登録・設定の入力が嘘をつかない」ことは継続利用の前提条件です。特に `billing.index` と認証導線の不信感は、現場管理者の導入継続率を落とすので、土台改善として筋が通っています。
- [Suggestion] 設計書では「課金 UI 改善」よりも「運用信頼を毀損する入力 UX の是正」と位置付けた方が、使命との接続がより明確です。

**2. 禁止事項違反**
- [Suggestion] 提案内容の範囲では明確な違反は見当たりません。`disabled` で塞がず押下時エラーに寄せる方針は、禁止事項 8 と整合しています。
- [Suggestion] PHP 変更なしなので `response()->json()`・DTO/JsonResource・Prism 直呼び系の禁止事項にも直接は触れません。

**3. 実現可能性**
- [Warning] `novalidate` の全 form 一律付与は技術的には妥当ですが、前提条件は「対象フォームがすべてサーバーエラーを該当フィールドに表示できること」です。ここが 1 つでも欠けると、native validation を外した瞬間に UX が後退します。  
  修正提案: affected 8 フォームそれぞれについて「不正 email 提出時に submit が通り、日本語の field error が表示される」受入条件を明記し、少なくとも主要導線 (`Auth/Register`, `Auth/Login`, `BillingContactForm`) はテストで固定してください。
- [Suggestion] `type="email"` を維持し `novalidate` で検証責務を切る判断は妥当です。iOS Safari 主戦場なら、`type="text" + inputmode="email"` に落とすより一貫しています。
- [Suggestion] `read-only:` バリアントを共通 `INPUT_BASE_CLASSES` で使わない判断も妥当です。共有ベースに混ぜると `Select` を巻き込む懸念は合理的です。

**4. 期待効果の妥当性**
- [Suggestion] 3 件を個別バグでなく species と捉え、DS/規約/テストで閉じる発想は妥当です。特に `AutoRechargeCard.svelte` と `Register.svelte` の readonly 問題を同時に閉じる説明は強いです。
- [Suggestion] `stale invalid` を DESIGN.md に昇格させるのも妥当です。既存の T041/T044 を「暗黙知」から「規約」へ上げる整理になっています。

**5. リスク**
- [Warning] readonly を disabled とほぼ同じ見た目に寄せすぎると、「送信される値」「コピー可能」「フォーカス可能」という readonly 本来の性質が伝わらず、別種の誤解を生みます。  
  修正提案: `cursor-default` だけで差をつけるのではなく、`disabled` と `readonly` の意味差を DESIGN.md に明記し、少なくとも 1 つは視覚差分を残してください。例えば「readonly は muted 背景だが text color は通常寄り」「focus ring は維持する」などです。
- [Suggestion] `novalidate` 一律化は今は正しいですが、将来 native validation を意図的に使うフォームを導入したくなった時の逃げ道を設計メモに残しておくと運用が安定します。現時点では allowlist 不要です。

**6. スコープの適切さ**
- [Suggestion] スコープは適切です。`Form` molecule 新設や `<dl>` 全面置換に広げない判断は、現段階では過不足がありません。
- [Suggestion] `type="number"` の別 species を切り離しているのも妥当です。同一タスクに混ぜると論点が濁ります。

**7. 型安全性**
- [Suggestion] PHP 側変更がないため DTO/JsonResource パターンや PHPStan level 10 への直接影響はありません。ここは問題ありません。
- [Suggestion] フロント側では `Input.svelte` / `Textarea.svelte` の `readonly` を明示 prop として型定義し、`type` union は widen しない方針を維持してください。`Select` には `readonly` を追加しない、という境界も型で表現すると安全です。

**特に判断してほしい論点への回答**
- 1. `novalidate` 全 `<form>` 一律付与: **妥当**です。今回の前提では条件付き運用の方がレビュー不能で、将来確実に抜けます。条件付きにする合理性は薄いです。
- 2. `type="email"` 維持: **妥当**です。iOS Safari/PWA では入力補助と意味付けの価値が高く、検証だけ `novalidate` で切れば十分です。
- 3. readonly 視覚状態を DS に持たせる: **妥当**です。`<dl>` 置換は局所解で、species を閉じません。ただし readonly と disabled を視覚的に完全同一にはしない方がよいです。
- 4. `read-only:` を避けて明示 prop: **理解・判断ともに正しい**です。少なくとも現行の共有 `INPUT_BASE_CLASSES` 前提では prop ベースの方が安全です。

設計としては通してよい水準です。修正が必要なのは、`novalidate` 全面適用時の受入条件明記と、readonly/disabled の意味差を DS で潰しすぎないことの 2 点です。