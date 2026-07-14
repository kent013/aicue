# 対応マトリクス: conceptual-review Round 1

全体判定は **APPROVED**。Warning は詳細設計 (Phase 2) に織り込む。

## [Warning] テスト追加を完了条件に明示
- 判断: 対応する
- 根拠: AGENTS.md 禁止事項 #1 (テストなし完了禁止)。
- 対応内容: 詳細設計のテスト計画に「押下では即 DELETE しない」「confirm 後に DELETE」「cancel/ESC/overlay では未発火」を明記。既存 `TakeStrip.test.ts` の DL 済み 422 テストは confirm 経由に更新する旨も波及変更に記載。

## [Warning] ConfirmDialog の processing/ESC/overlay/danger 契約への依存
- 判断: 対応する (確認済み)
- 根拠: `ConfirmDialog.svelte` / `.types.ts` を確認。`processing` prop で loading 表示 + ESC/overlay/cancel close 抑止、`confirmVariant: "danger"`、`onConfirm`/`onCancel`/`bind:open` を提供。既存 API で充足し拡張不要。
- 対応内容: 詳細設計で契約充足を明記。organism の API 拡張はしない。

## [Warning] 期待効果の表現 (過大表現回避)
- 判断: 対応する
- 根拠: 確認ダイアログは誤削除を完全には防げない。
- 対応内容: 詳細設計では「誤削除リスクを低減する」に表現を統一。

## [Warning] 失敗時のダイアログ close 条件が曖昧
- 判断: 対応する
- 根拠: 422 (DL 済み削除不可) 表示の文脈を失わない必要がある。
- 対応内容: DELETE 解決後 (成功/失敗いずれも) にダイアログを閉じ、失敗は既存 `take-strip-error` (role="alert") に表示する方針を明示する。これは動画マニュアル削除の onFinish 挙動と一致し、既存 422 テストの期待 (エラー表示) を confirm 経由でも満たす。ダイアログ内エラー表示は organism 拡張になるため採らない。

## [Warning] deleteTarget を object 参照で保持する認知ズレ
- 判断: 対応する
- 根拠: 親再取得・並び替えで参照内容がずれる余地。
- 対応内容: 保持は `deleteTargetId: number | null` + 表示ラベル `deleteLabel: string` を requestDelete 時点でスナップショット。onConfirm では id から現行 take を解決し null ガード。

## [Warning] TypeScript nullable state の null 安全性
- 判断: 対応する
- 根拠: PHPStan 相当の型安全を TS でも担保。
- 対応内容: `onConfirm` に明示 null ガード、optional 連鎖任せにせず分岐を明記。

## [Suggestion] 主目的を「現場素材の喪失防止」に据える / 観測指標の将来検討
- 判断: 一部反映・一部見送り
- 根拠: 主目的の位置づけは概念設計で既に喪失防止を筆頭に記載済み。観測指標 (confirm/cancel 比率) は本施策スコープ外。
- 対応内容: 詳細設計でも喪失防止を主目的として維持。指標収集は今回スコープ外と明記。
