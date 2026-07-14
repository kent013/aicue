# 対応マトリクス: design-review Round 1

全体判定: **CHANGES_REQUESTED**。施策1・2 は APPROVE、施策3・4（テスト計画）が
REQUEST_CHANGES。実装方針自体は妥当で、テスト厳密性の補強のみが条件。

## [Warning] 施策3: serverErrors 非退行テストが抽象的（操作後も残ることを直接検証していない）
- 判断: 対応する
- 根拠: 「client precheck 未発火描画で担保」では回帰防止として弱い。Codex 指摘は正当。
- 対応内容: 既存の確定フロー機構（`stubRecentAuthStatus(true)` + `router.post` spy、既存
  L142-201 に存在）を再利用し、`router.post` mock を `opts.onError({ user_id: "サーバ由来…" })`
  実装にする方式へ具体化。`useForm.submit` 内部 onError が `form.clearErrors().setError(errors)`
  を呼ぶ（`node_modules/@inertiajs/svelte/dist/useForm.svelte.js` L64-66）ため実
  `transferForm.errors.user_id` にサーバエラーが載る。確定 → サーバエラー表示 → 別の有効候補へ
  change（$effect 発火）→ サーバエラー残存を明示アサート、と test plan を書き換えた。

## [Warning] 施策3: 過剰クリア防止テストの2案目が候補0人ケースと原因混在
- 判断: 対応する
- 根拠: 「候補0人」と「候補あり・未選択」は分岐原因が異なり、狙いがぶれる指摘は妥当。
- 対応内容: 過剰クリア防止テストを「候補あり props・空選択を維持（`isValidTransferTarget=false`）
  でエラー残留」の**単一条件**に絞った。候補0人ケースは既存 it（L112-116）が別途カバー。

## [Warning] 施策4: serverErrors 非退行が「未発火描画」中心で回帰防止として弱い
- 判断: 対応する
- 根拠: 施策3と同旨。
- 対応内容: `router.post` mock の `opts.onError({ user_id: … })` で実 `memberForm.errors.user_id`
  を設定 → 追加ボタンでサーバエラー表示 → 別の有効候補へ change → 残存を明示アサート、へ具体化。
  onError 経路では `onSuccess`（reset + clientError=null）が発火せず選択値が有効のまま保たれる
  設計整合も明記。

## [Suggestion] 施策4: stale 解消で aria-invalid と文言の両方を確認する方針は適切
- 判断: 対応する（既に反映）
- 根拠: 妥当。両施策の stale 解消テストで `aria-invalid` 属性の脱落と文言消失を both 確認と明記。

## [Suggestion] 施策1・2 の各種肯定的指摘（$effect 停止性・T041 整合・disabled 不使用）
- 判断: 見送り（現状維持）
- 根拠: 設計を肯定する指摘。追加変更不要。
