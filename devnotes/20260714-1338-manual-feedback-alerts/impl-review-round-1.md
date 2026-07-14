**レビュー結果（T040 frontend incremental）**

- 前提どおり、提示 diff とテスト結果のみを対象にレビューしました（コマンド実行なし）。

**`resources/js/components/features/manual/ScenarioEditor.svelte`**
- 判定: **OK**
- `justSaved` の遷移は設計 S1 と整合しています。`true` 化は `applySaved()` のみ、`reseed()`/save開始/`showFailure()`/dirty転換で `false` 化されており、偽成功抑止（409→reseed）も満たしています。
- 表示優先度も `dirty` → `justSaved` の排他順で実装され、要件「dirty 優先」を満たしています。
- DS/Atomic も問題なし（`text-success` token、`@lucide/svelte` の `Check`、既存 atom 利用、disabled 化なし）。
- a11y も大きな懸念なし（永続インジケータで live region 化していないため toast との二重読み上げ誘発を増やしていない）。

**`resources/js/components/features/manual/RenderPanel.svelte`**
- 判定: **OK**
- 施策 S2 の核である source 別 state 分離（`renderStartError`/`previewStartError`）は適切です。`start(kind)` が該当 source のみクリアするため、後発が先発を消す共有上書きバグを回避できています。
- `handleStartResponse` でも kind 別格納になっており、402購入導線も source ごとに保持され誤帰属を防げています。
- phase-aware title 4種は要件マトリクスどおり反映済み（render/preview × start/job fail）。
- 403内部文言漏えいの新規導線は見当たらず、既存 `extractMessage` の範囲内で不適切な拡張もありません。
- DS/Atomic 準拠も問題なし（Alert/TextLink 継続利用、新規色直書きなし、ボタン disabled なし）。

**`tests/js/components/features/manual/ScenarioEditor.test.ts`**
- 判定: **OK**
- S1 不変条件（保存成功のみ表示、dirty切替で消去、409 reseedで偽成功なし、失敗時非表示）を回帰として十分固定できています。
- 特に「保存直後 dirty=false でも justSaved=true 維持」の砦テストは、将来の derived/effect 変更に強いです。

**`tests/js/components/features/manual/RenderPanel.test.ts`**
- 判定: **OK（軽微な改善余地あり）**
- 新規ケースで「preview 402 の帰属」「render/preview 起動失敗の共存」「preview job fail と render start fail の並存」を押さえており、S2 不変条件を実質カバーしています。
- 既存ケースへの title 期待追加で phase-aware 帰属の固定もできています。
- 改善余地（Suggestion）: 「render start fail 後に render を再startして成功したとき、render-start-error だけ消えて preview-start-error は残る」対称ケースがあると、source別クリア仕様の退行検知がさらに強固です。

**指摘分類**
- **Critical**: なし
- **Warning**: なし
- **Suggestion**:
  - `RenderPanel.test.ts` に source別クリアの対称回帰（片側成功で他側エラー温存）を1件追加すると、将来のリファクタ耐性が上がります。

**最終判定**
- **APPROVED**