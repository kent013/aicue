**全体判定: CHANGES_REQUESTED**

設計方向は妥当です。5値の制作状態と3値の一覧表示語彙を分け、写像の正本を `ManualProgress` に閉じる判断は North Star と既存の DTO/Inertia 方針に合っています。ただし、破壊的変更の波及とテスト配置にいくつか修正必須があります。

**A. ManualProgress enum: APPROVE**

[Suggestion] `statuses()` は毎回 `VideoManualStatus::cases()` を走査する実装で問題ありません。ただ、件数が小さいとはいえ「逆写像は導出」と明記するなら現設計のままでよいです。固定配列にすると二重表になりやすいので避ける判断は妥当です。

**B. ManualListQuery status→progress: REQUEST_CHANGES**

[Warning] `toQueryParams()` の返却値に `progress` を載せる設計ですが、削除後 redirect の URL 維持だけでなく、`paginate()->withQueryString()` 側には旧 `status` が残る可能性があります。`ProjectController::manualRows()` は `$request` の生クエリを保持した paginator に `withQueryString()` を呼んでいるため、`?status=published` でアクセスした場合、ページネーションリンクに旧キーが残る懸念があります。  
修正案: `withQueryString()` を使い続けるなら、旧キー混入を許容するかを明記し、Feature テストで paginator link/meta の query まで確認してください。より整合的には、paginator URL 生成に `ManualListQuery::toQueryParams()` 由来の allowlist 済みクエリだけを使う設計に寄せるべきです。

[Warning] `ManualFilters.progress: ManualProgress | null` にする一方、`Show.svelte` の state は `$state<string>` です。実装上は動きますが、型の境界が緩みます。  
修正案: `let filterProgress = $state<ManualProgress | "">(manualFilters.progress ?? "");` とし、`manualQuery()` で空文字以外を `progress` に載せる形にしてください。

**C. 一覧 WHERE を写像経由へ: APPROVE**

[Suggestion] `whereIn('status', $listQuery->progress->statusValues())` は正しいです。既存の enum cast に頼らず DB 値へ落としている点も PHPStan/実行時の両面で安定しています。

**D. ManualListItemData status→progress: APPROVE**

[Suggestion] `status` を一覧 payload から消す判断は、一覧が5値を使わない前提なら妥当です。`durationMs` / `currentFinishedRenderJobId` の published 判定は表示語彙ではなく完成物の可用性判定なので残す整理も正しいです。

**E. TS 型・ラベル・トーン: REQUEST_CHANGES**

[Warning] `VideoManualStatus` の用途を「詳細画面 / ダッシュボードだけ」と書くと、同じファイル内の `CAPTURE_NAVIGABLE_BY_STATUS` / `isCaptureNavigable()` と衝突します。PC編集/詳細から撮影ナビへの導線判定も5値を使う正当な用途です。  
修正案: docblock を「一覧の行バッジ・絞り込みでは使わない」に狭めてください。5値そのものの利用面を詳細/ダッシュボードに限定する説明は過剰です。

[Warning] `ManualFilters.progress` を union にするなら、Svelte 側の query 組み立ても union を維持する必要があります。上記 B と同じ修正が必要です。

**F. 一覧 UI 3値化: REQUEST_CHANGES**

[Warning] `testId` を `manual-status-*` から `manual-progress-*` に変える方針は読みやすい一方、E2E/Browser/外部テストが参照していないことを設計書内の「実読で確認済み」だけに依存しています。提供された範囲では確認不能です。  
修正案: 実装時の検証項目に `rg 'manual-status-|manual-filter-status'` の結果を反映し、該当があれば全更新対象に含めてください。テスト計画にも「旧 testId 参照ゼロ確認」を明記してください。

[Suggestion] UI は DS token / Badge tone のみで、hex 直書きや Lucide 追加もないため DESIGN.md / Atomic Design 面のリスクは低いです。

**G. 撮影 PWA 語彙明示化と dead payload 撤去: REQUEST_CHANGES**

[Warning] `CaptureManualSummaryData` から `status` を削除するのは dead payload として妥当ですが、`CaptureManualController` の検索クエリには入力正規化の既存弱点が残ります。今回の主目的外ですが、`category` は `(int)` キャストで `abc` が `0` になり、PC 側 `ManualListQuery` の allowlist 方針と不一致です。  
修正案: 本施策で触らないなら「既存仕様として据え置き」と明記してください。整合を取るなら Capture 側にも VO を置くか、少なくとも `ctype_digit` 相当の allowlist を入れる設計にしてください。

[Warning] `captureProgressOf()` は `cuts_total=0, cuts_with_takes>0` の不整合データを「撮影中」にせず「未撮影」にします。現行三項式と同じですが、関数化で仕様として固定されます。  
修正案: テスト境界に `cuts_total=0 && cuts_with_takes>0` を入れ、この不整合時は未撮影でよい理由をコメントに残してください。

**H. enum ⇔ TS union 同期テスト: APPROVE**

[Suggestion] `ManualProgress` と `VideoManualStatus` の両方を pin するのは良いです。`types/manual.ts` の「手動確認」コメント削除も必須です。

**I. 写像テスト + 既存更新: REQUEST_CHANGES**

[Warning] `ManualProgressMappingTest` は DB を使わない純粋 enum テストなので、`tests/Feature/Manual` より `tests/Unit` または `tests/Architecture` の方が意図に合います。Feature に置くと RefreshDatabase のコストだけが増えます。  
修正案: enum の写像表・排他性・件数は `tests/Unit/Manual/ManualProgressMappingTest.php` へ置き、Inertia payload / filter 挙動だけ Feature に残してください。

[Warning] `ProjectShowManualsTest` の `has('manuals.data', 5)` は pagination や既存 fixture の変更に弱く、並び順も固定しないとタイトル確認が曖昧になります。  
修正案: 5件すべての title set を `where` ではなく collection 的に検証する、または status ごとの対象 title を明示してください。`in_progress` は analyzing/ready/rendering の3件が返ることを title で固定するのが望ましいです。

**J. doc 更新: APPROVE**

[Suggestion] doc に写像表そのものを書かず、正本の所在だけを書く方針は正しいです。二重管理を避けられます。

**追加で直すべき点**

[Critical] 旧 `?status=` を「互換なし」とするなら、アプリ内生成 URL、pagination link、削除 redirect、Vitest の router query、Feature の Inertia props の全経路で `status` が出ないことを固定する必要があります。現設計は redirect と UI query は押さえていますが、pagination の query string が漏れる可能性が未処理です。  
修正案: paginator の query source を allowlist 済み `ManualListQuery::toQueryParams()` に寄せるか、少なくとも Feature テストで旧 `status` が pagination/links に残らないことを確認してください。

[Warning] fail-first の記述は良いですが、検証コマンドが不足しています。PHP/TS/Svelte/API payload を同時に変えるため、最低でも `composer test`、`composer phpstan`、`vendor/bin/pint --test`、`pnpm lint`、`pnpm typecheck`、`pnpm test` は設計の完了条件に明記してください。

この設計は大筋 APPROVE 可能ですが、旧 query 混入、TS 型の緩み、Capture 側の既存入力正規化との扱い、テスト配置を直してから実装に進むべきです。