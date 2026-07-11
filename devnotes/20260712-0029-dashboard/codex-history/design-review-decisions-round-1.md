# 対応マトリクス: design-review Round 1

## [Critical] Builder import が不正（Contracts ではなく Illuminate\Database\Eloquent\Builder）
- 判断: 反論する（コードベースの実証事実つき）+ 一部設計変更
- 根拠: `Illuminate\Contracts\Database\Eloquent\Builder` は**このリポジトリの既存規約**。`CaptureManualController`（withCount/when closure）・`StorageUsageService`・`StaleUploadReservationSweeper` が同 import で PHPStan lv10 green（CI 通過済み）。Eloquent Builder はこの contract を実装しており where/whereHas/withCount closure の型として成立する
- 対応内容: ただし前例のない「constrained eager load（`load(['rel' => closure])`）の closure 型付け」は疑義が残るため（closure が受けるのは Relation インスタンス）、**`load()` 自体を廃止**し、`AnalysisJob::query()->whereIn('video_manual_id', $manualIds)` + `keyBy('video_manual_id')` の standalone クエリ 2 本（`VideoManualService::delete` の `Take::query()->whereIn('cut_id', $manual->cuts()->select('id'))` と同じ既存パターン = relation subquery で構造的に project スコープ）に設計変更した

## [Critical] recentManuals の `updated_at ?? ''` が欠損を隠す
- 判断: 対応する
- 対応内容: `Assert::notNull($manual->updated_at)` で欠損を顕在化させ、DTO の updatedAt は非 null string を維持（timestamps は DB 不変条件として非 null）

## [Critical] `page.props as unknown as SharedProps` が型安全性を壊す
- 判断: 反論する
- 根拠: この cast は `resources/js/lib/shared-props.ts` の docblock が「ページ側は `page.props as unknown as SharedProps` で参照する」と規定する**リポジトリ標準パターン**で、現在 28 箇所で使用中。ここだけ別方式にする方が規約逸脱・drift 源になる。パターン自体の変更は本設計のスコープ外（テンプレート由来の横断規約）
- 対応内容: 設計にリポジトリ標準パターンである旨と根拠を明記

## [Warning] Assert::integerish + (int) cast の冗長さ
- 判断: 見送る（現設計維持）
- 根拠: `Assert::nullOrIntegerish` → `(int)` cast は `VideoManualController::store/update` の既存パターンと同型。runtime 検証を残す方が防御的で、リポジトリの Assert 規約とも整合

## [Warning] GET 内自己修復の監査痕跡がない
- 判断: 対応する
- 対応内容: `Log::info('current organization self-heal', [user_id, observed, candidate, updated_rows])` を UPDATE 直後に追加

## [Warning] `config()->integer` が存在しない環境がある
- 判断: 反論する
- 根拠: Laravel 11+ の Config Repository 標準 API であり、本リポジトリで既に多数使用（`TicketLedgerService` L268・`SecurityHeaders`・`StoreTakeUploadUrlRequest` 等）して lv10 green。実装ノートに既存使用の根拠を明記

## [Warning] `latest('id')` は updated_at 基準とズレる
- 判断: 反論する（根拠を設計に明記）
- 根拠: in-flight job は manual×操作種別あたり 1 本の既存不変条件（doc/10 §10.8-8。ScenarioWritePathInventoryTest / 各 trigger の in-flight guard）があり、並び順は意味を持たない。`orderBy('id')` + keyBy 後勝ちは不変条件が万一破れた場合の防御的決定化（最新作成 job に確定）。実装ノートに理由を明記

## [Warning] Gate::authorize と resolver の二重判定の役割分担
- 判断: 対応する
- 対応内容: controller コメントを「resolver = 所属整合（構造的確認）、Policy = 最終認可。Policy が将来厳格化してもここが最終判定」に明確化

## [Warning] no_project + can_create_project=false で閲覧者が詰む
- 判断: 対応する
- 対応内容: 「組織の管理者にプロジェクト作成を依頼してください」の案内文 + 組織名表示（依頼先の明示）を追加

## [Warning] progressbar の job_status=null 時の aria が未定義
- 判断: 対応する
- 対応内容: `progress` 非 null のときのみ progressbar を描画（aria-valuenow/min/max 付き）。`job_status` null / `progress` null は progressbar を描画せず「準備中」テキストのみ

## [Warning] Service 単体テストなし方針が競合再現性を下げる
- 判断: 対応する（設計の明確化）
- 対応内容: 施策 1 の `CurrentOrganizationResolverTest` は元々 **Service を直接駆動するテスト**（HTTP を介さず `resolve($user)` を呼ぶ）であることを明記。DashboardService は Controller 経由の Feature テストで検証（既存 Service 群と同方針）

## [Warning] DashboardTest に dangling cross-org ケースを明示
- 判断: 対応する
- 対応内容: 「他 org の id を current に forceFill した dangling 状態 → 当該 org のデータが response に一切出ない + 所属 org へ自己修復」を DashboardTest のケースとして追加

## [Suggestion] DashboardState の PHP enum 化
- 判断: 対応する
- 対応内容: `App\Enums\Dashboard\DashboardState`（no_organization/no_project/ready）を追加し、DTO の state を enum 型に変更

## [Suggestion] STATUS_TONES に as const + satisfies
- 判断: 対応する
- 対応内容: `as const satisfies Record<VideoManualStatus, BadgeTone>` を移設仕様に明記

## [Suggestion] resolver の null 意味の enum 化
- 判断: 見送る
- 根拠: v1 の呼び出し元は DashboardController のみで、null の分岐は DashboardService が state に写像する。呼び出し元が増えた時点で検討（今必要なものだけ作る）
