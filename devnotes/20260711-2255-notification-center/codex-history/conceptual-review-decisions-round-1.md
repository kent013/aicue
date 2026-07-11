# 対応マトリクス: conceptual-review Round 1

## [Critical] organization_id を JSON data に閉じ込めるのは危険（性能・安全性・型安全性）
- 判断: 対応する
- 根拠: 指摘どおり。org 文脈の判定を untyped payload に依存させるのは将来の書き込み漏れ・型崩れの温床。
- 対応内容: `notifications` テーブルに first-class の `organization_id` 列（nullable FK, cascadeOnDelete, index）を追加。標準 `DatabaseChannel` を薄く拡張した `OrganizationScopedDatabaseChannel`（`buildPayload` に organization_id をマージ）を container binding で差し替える。`data` は表示用 payload に限定。

## [Critical] 通知 payload が array<string, mixed> のまま流れ PHPStan level 10 を守れない
- 判断: 対応する
- 根拠: type ごとに payload 形状が違う以上、型付き契約なしでは崩れるのは確実。
- 対応内容: `NotificationType` backed enum を単一の正とし、type ごとの payload DTO（書き込み側）+ `NotificationListItemData` DTO（読み出し側。enum で payload を検証復元）を新設。DatabaseNotification の生配列をページ・shared props に渡さない設計を明記。

## [Warning] current org 絞り込みだと複数 org 所属ユーザーが別 org の完了を見落とす
- 判断: 対応する
- 対応内容: 未読数・一覧を全 org 横断に変更（通知は自分宛のみで構造的に閉じるため cross-org read には当たらない）。一覧に org 名バッジ（作成時スナップショット）を表示。organization_id 列は表示バッジと open の遷移可否判定にのみ使用。

## [Warning] 遷移先が購読失効・削除・cross-org で 402/404 になり UX が途切れる
- 判断: 対応する
- 対応内容: `GET /notifications/{notification}/open` を新設し、既読化 + 遷移先解決をサーバ側で実施。開けない場合（manual 削除済み / 通知 org ≠ current org / 遷移先なし）は一覧へ flash 付きで戻す。org の自動切替はしない（驚き最小）。

## [Warning] commit 後〜通知 insert 間の worker crash で通知が欠落（効果が best effort）
- 判断: 部分対応（wording を明確化。outbox は見送る）
- 根拠: worker がジョブ実行中に落ちるケースは recoverStale → failJob 経由で失敗通知が発火するため、真の欠落窓は「terminal commit 直後〜通知 insert」の数 ms のみ。正は従来どおり job ポーリング + 画面 status。この窓のために outbox 台帳を作るのは「今必要なものだけ作る」原則に反する。
- 対応内容: 設計書に「at-most-once の補助通知（正はポーリング）」と明示。期待効果の主張をそれに合わせた。

## [Warning] サーバ側が DatabaseNotification 生配列を Inertia に流しやすい（DTO 未定義）
- 判断: 対応する
- 対応内容: 上記 Critical 2 と同じ「型付きデータ契約」節を新設（NotificationListItemData 必須、生配列禁止を明文化）。

## [Warning] databaseType() の安定文字列だけでは PHP/Svelte 間で文字列ドリフト
- 判断: 部分対応
- 根拠: PHP 側は backed enum で一元化（対応）。TS への自動生成基盤は本リポジトリに存在せず、この機能のために codegen を導入するのは過大。
- 対応内容: TS 側は literal union を手動ミラーし、**未知 type の汎用フォールバック描画**を必須にしてドリフト時も UI が壊れない構造とした（+ Vitest でフォールバックを固定）。

## [Suggestion] ticket_balance_low を後続に回す
- 判断: 見送る（v1 に含める）
- 根拠: 本フィーチャの要件（設計ブリーフ）に明示されており、クロス検知は TicketLedgerService::commit への数行のフックで実装面積が小さい。402 での作業中断の予防は使命（現場のマニュアル作成サイクル維持）に直結する。

## [Suggestion] ドロップダウン・SSE・通知設定のスコープ外は適切 / invitation_received は合理的
- 判断: 現状維持（同意）
