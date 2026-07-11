# 対応マトリクス: design-review Round 2

## [Critical] no_project の「組織名表示」が DTO / TS 型に存在しない（施策 4）
- 判断: 対応する
- 対応内容: `DashboardPageData` に `organizationName: string|null` を追加（no_organization は null / no_project・ready は org name）。array shape・`DashboardData` TS 型・DashboardService の 3 分岐すべてに反映

## [Warning] 競合契約テストが実際の競合分岐を通っていない（施策 1）
- 判断: 対応する
- 根拠: 指摘どおり、detach 済みなら候補取得が null になり whereHas に到達せず、current=B 設定済みなら membershipVerified で早期 return するため、resolve() 経由では UPDATE の WHERE/EXISTS 分岐を実証できない
- 対応内容: 条件付き UPDATE を **`heal(User, ?int $observed, int $candidateId): int` として seam 化**（public・内部 API と明記）。テスト計画を「resolve() の統合 4 ケース + heal() の競合分岐 4 ケース（EXISTS 偽 / WHERE 不一致 / observed 一致置換 / UPDATE 0 件後の resolve 帰結）」に書き直し、UPDATE が実際に 0 件になることを戻り値と DB 状態で検証する

## [Warning] keyBy 後の Collection 型が PHPStan lv10 で絞れない（施策 2）
- 判断: 対応する
- 対応内容: `/** @var \Illuminate\Support\Collection<int, AnalysisJob> */`・`<int, RenderJob>` のローカル型注釈を明示

## [Warning] 容量テストが bytes_pending を固定していない（施策 2）
- 判断: 対応する
- 対応内容: DashboardTest に「pending の TakeUploadReservation（未失効）が storage_used_bytes / percent に加算される」ケースを追加（occupiedBytes = bytes_used + bytes_pending の契約固定）

## [Warning] progress の 0-100 clamp 契約がない（施策 4）
- 判断: 対応する（サーバ側 clamp に一元化）
- 対応内容: DashboardService の DTO 生成時に `max(0, min(100, $progress))` で clamp（UI は正規化済み値を表示・aria・幅に共用）。DashboardTest に範囲外値（150 / -10 → 100 / 0）のケースを追加

## [Suggestion] manual_status が TS literal union より広い（施策 2）
- 判断: 対応する（境界テストで値域固定）
- 対応内容: DashboardTest に「in_progress の manual_status は analyzing | rendering のみ」のケースを追加

## [Suggestion] 自己修復ログのレベル（施策 1）
- 判断: 対応する
- 対応内容: 更新 0 件（正常な競合）は debug、更新成功時のみ info（`Log::log($updated > 0 ? 'info' : 'debug', ...)`）

## （撤回）Builder import / page.props cast
- Codex が Round 1 指摘を撤回（既存規約と PHPStan 実績を確認）。設計変更なし
