# 実査ブリーフ: http-idempotency-keys

> lctl 台帳 (feature id: `http-idempotency-keys`) の正典設計と aicue の実コードを突き合わせた調査結果。
> 2026-08-08 の実査 (T124〜T135 マージ後の main = c71061e 時点)。
> 設計フェーズの入力であり、設計そのものではない。

## 序列 (候補 7 件中)

- 順位: #4 / 想定タイトル: 冪等キーの並行409と配線漏れ検査
- テーマ / 優先度 / モード: backend / Medium / standalone
- value=6 effort=7 self_contained=True
- 選定理由: 並行 409 の不在は同一キー 2 本が両方 controller を実行する二重実行の穴で、判断基準 1 に該当する。ただし影響面は REST 書込 3 route と MCP write tool 0 本に閉じており実害の窓は小さい。逆に、現行の『失敗応答は保存しない = 同一キーで再実行できる』を docblock とテストで明示している公開契約を家系標準へ寄せると API v1 の破壊的変更になり、外部利用者への周知可否というオーナー判断が挟まる。保持期間の公開値も方針判断。裁定 (AG-032 / AG-122) は確定しているので、方針が返ってきたら次サイクルの筆頭候補。

## 設計で最初に決めるべき論点

4xx 後の同一キー再送を 409 にする破壊的変更を採るか (= 決着は completed と indeterminate のみで release 経路を持たない標準形をそのまま採るか) をオーナーに確認してから、状態列の移行 (既存行は削除せず indeterminate へ) と claim の条件付き UPDATE を pgsql / sqlite 双方で成立させる形を決める。

## 台帳が確定させた標準形

裁定 AG-032 の標準形 t1 は外部標準の必須 5 点。(1) 同一キーの処理中要求を 409 で拒む (実行前 claim / 状態機械。決着は completed と indeterminate のみで release 経路を持たない)、(2) 保持期間を SoT へ一元化し利用者向け文書へ公開 (drift は deny-by-default の parity gate)、(3) 期限切れ鍵の物理削除コマンド + schedule (REST と MCP の両テーブル)、(4) 認証付き書込 route への配線漏れ検査 (deny-by-default 目録)、(5) MCP 書込ツールへのキー必須化の中央強制を gate 化。AG-122 で再生応答ヘッダ名は Idempotent-Replayed に家系統一 (replay 応答にのみ付与・定数一元化・「外部標準に無い拡張」と文書明記)。状態機械の実装形 (cache ロック補助か indeterminate 固定か) と保持期間の値以外の細部は各リポジトリに委ねられている。

## aicue の現状 (実在確認済み)

t0 一式が実在し、台帳の「並行要求の扱いと配線漏れが未是正」は現 HEAD (c71061e) でも成立する。REST: app/Http/Middleware/IdempotentRequest.php (TTL_HOURS=24、alias 'idempotent' は bootstrap/app.php:186) が handle() で既存行 lookup → 無ければ $next() 実行 → 2xx JsonResponse のみ storeResponse()。状態列は無く (database/migrations/2026_06_11_100100_create_idempotency_keys_table.php は api_key_id/user_id×route_name×key の unique 2 本のみ)、並行の unique 衝突は catch (QueryException) → report() で握り潰すため同一キー 2 本が両方 controller を実行する。MCP: app/Services/Mcp/McpIdempotencyService.php の replay()/store() も同形 (store の unique 違反を swallow)。app/Mcp/Tools/AppMcpTool.php:70 が ToolName::isWriteTool() を見る中央強制を持つが、app/Enums/Mcp/ToolName.php の 4 case (Whoami/ListProjects/ShowProject/ListItems) は全て false = write tool 0 本。配線は routes/api.php:97 の write group (api.v1.projects.items.store/update/destroy) のみで、DELETE api/v1/me/session (api.v1.me.session.revoke、routes/api.php:69-74) は変更系だが 'idempotent' を持たない。tests/Architecture に冪等関連 gate は 0 本 (idempotent への言及は ProjectRouteCurrentOrgGuardTest:119-140 と TenantBoundaryOrderingTest:103,464 の順序固定のみ)。テストは tests/Feature/Api/IdempotencyTest.php (178 行) と tests/Feature/Mcp/McpIdempotencyServiceTest.php (120 行) の 2 本で、いずれも並行を扱わない。config/idempotency.php は無く 24h は IdempotentRequest::TTL_HOURS と McpIdempotencyService::TTL_HOURS の 2 定数に重複。期限切れ鍵の物理削除コマンドは app/Console/Commands/ に無く (Operations 配下は CheckMailConfig.php のみ)、routes/console.php にも冪等 schedule は無い (削除は再送時の lazy delete のみ)。利用者向け文書も無い (docs/ 配下で idempot に触れるのは TODO.md / TODO-closed.md / app-integration-guide.md / architecture.md / factories.md だけで保持期間の公開なし)。Idempotent-Replayed / Idempotency-Replayed は app/ resources/ tests/ に 0 件。

## ギャップ

1. 必須 (1) 並行 409 が無い — IdempotentRequest も McpIdempotencyService も実行後 store 方式で、状態 (processing/completed/indeterminate) も claim も持たず同一キー 2 本が両方本処理を実行する。
2. 必須 (2) 保持期間が公開されていない — 24h が 2 つのクラス定数に重複し、config の SoT も利用者向け文書も docs/ に無く、文書 ⇔ 実装の parity gate も無い。
3. 必須 (3) 期限切れ鍵の物理削除が無い — prune コマンドも routes/console.php の schedule も無く、idempotency_keys / mcp_idempotency_keys が単調増加する。
4. 必須 (4) 配線漏れ検査が無い — tests/Architecture に冪等 gate が 0 本で、実際に DELETE api/v1/me/session が 'idempotent' 無しのまま検出されずに存在する。
5. 必須 (5) MCP 書込ツールのキー必須化は AppMcpTool に実装があるだけで Architecture gate による強制が無く、write/read 分類は ToolName::isWriteTool() の網羅 match だけに依存する。
6. AG-122 の Idempotent-Replayed ヘッダが未実装 — replay 応答が通常応答と区別できず、定数一元化も「外部標準に無い拡張」の文書明記も無い。

## 想定スコープ

新規: config/idempotency.php (保持期間 SoT。env 不使用) / app/Support/Idempotency/ に Retention・Replay ヘッダ定数 / app/Enums/Idempotency/IdempotencyState.php / database/migrations/*_add_state_to_idempotency_key_tables.php (既存行は削除せず indeterminate へ移行。idempotency_keys の unique 2 本と mcp_idempotency_unique を壊さない) / app/Console/Commands/Operations/PruneIdempotencyKeysCommand.php (REST・MCP 両テーブル) / tests/Architecture/IdempotentRouteCoverageTest.php + tests/Support/ 配下の route 目録 + app/Enums/Security/ の免除 enum / tests/Architecture/IdempotencyRetentionParityTest.php + docs/api-idempotency.md (新設) / tests/Architecture/McpWriteToolIdempotencyEnforcementTest.php / 並行実行の Feature テスト (tests/Feature/Api/IdempotencyConcurrentClaimTest.php)。変更: app/Http/Middleware/IdempotentRequest.php (実行前 reserve + terminable での確定 + 409 + Idempotent-Replayed) / app/Models/IdempotencyKey.php・app/Models/McpIdempotencyKey.php (state cast) / app/Services/Mcp/McpIdempotencyService.php (replay → reserve/complete) / app/Mcp/Tools/AppMcpTool.php / app/Enums/ApiErrorCode.php (処理中・結果不明の 409 コード追加。fromStatus の 409 → IdempotencyConflict 写像に注意) / routes/api.php (api.v1.me.session.revoke を配線か目録免除) / routes/console.php (prune の schedule) / tests/Feature/Api/IdempotencyTest.php・tests/Feature/Mcp/McpIdempotencyServiceTest.php / docs/architecture.md・docs/app-integration-guide.md・AGENTS.md ドメイン規約への追記。既存 gate の追随: tests/Architecture/TenantBoundaryOrderingTest.php (103,464 行の IdempotentRequest 期待列) と ProjectRouteCurrentOrgGuardTest (119-140 行) は middleware 順序を変えなければ無改修で通るはず。gate の書き味は ThrottleCoverageInventoryTest (母集団下限 / ちょうど 1 本 or 型付き免除 + 30 文字根拠 / stale 検出 / 死んだ免除の検出 / 件数 cap) をそのまま踏襲できる。

## リスク

最大のリスクは公開契約の変更。現行 IdempotentRequest は「失敗応答は保存しない = 同一キーで再実行できる」を docblock とテスト (tests/Feature/Api/IdempotencyTest.php) で明示しており、家系標準の「決着は completed と indeterminate のみ・release 経路なし」へ移すと 4xx 後の同一キー再送が 409 に変わる = REST API v1 の破壊的変更。既存 3 テストの書き換えが必須で、外部利用者向けの周知判断が要る。第 2 に DB。idempotency_keys は api_key_id / user_id の NULL distinct 前提の unique 2 本に依存しており、state 列追加と条件付き UPDATE による claim は pgsql (本番) と sqlite (テスト) の双方で検証が要る。既存行を削除する移行はデプロイをまたいだ再送で二重実行を招くため不可。第 3 に順序。'idempotent' は api.project-in-org / api-key.ability より後という不可侵契約 (ProjectRouteCurrentOrgGuardTest / TenantBoundaryOrderingTest) があり、reserve を早めても middleware 位置は動かせない。terminable 化する場合 bootstrap/app.php の priority list の相対順序を壊さないこと。第 4 に MCP 側は T109 (replay 判定が runTool より前 = リソース解決前) と同じコードを触るため、reserve/complete への再構成は T109 のハザードを同時に閉じるか、閉じないなら明示的に据え置く判断が要る。波及は限定的 (REST 書込 3 route + MCP write tool 0 本) だが、cache ロックを併用する設計を採るなら AGENTS.md のキャッシュ素データ規約 (配列/文字列/数値/真偽値のみ) と CachePayloadPlainDataGateTest の目録に触れる。

## 実装者への申し送り (台帳と実コードの食い違いを含む)

台帳と実コードの食い違い / 台帳より新しい事実: (1) inbox 要約の「agenda に楽観ロック競合が残っている」は既に古い。当該議題 (conflict acc7fac336af) は AG-121 として 2026-08-08T21:09 にオーナー裁定でクローズ済みで、aicue の作業には影響しない。(2) 逆に inbox に無い新しい制約として AG-122 (2026-08-08T21:10) がある — 再生応答ヘッダ名は Idempotent-Replayed に家系統一する裁定なので、aicue が replay ヘッダを新設するときはこの名前で定数一元化し「外部標準に無い拡張」と文書に明記すること (aicue には現在ヘッダが 1 つも無いので改名ではなく新規実装になる)。(3) 台帳の aicue セルの観測点は aicue@ad8c6a3 (2026-08-06) だが、T124〜T135 を含む現 HEAD c71061e で再実査しても状況は変わっていない — 冪等まわりのファイルは 1 つも増減していない。(4) 台帳が「配線漏れ」と抽象的に書いている中身を具体化すると、実在する穴は DELETE api/v1/me/session (api.v1.me.session.revoke) が変更系なのに 'idempotent' を持たないこと。ただし motivation は同種の自セッション失効 route を「配線しても機能しない (成功で自分のセッションが失効し再送は冪等層より前の guard で 401)」として理由付き免除 + 前提テストで裏取りしており、aicue も同じ判断になる公算が高い。gate を作る価値はこの 1 件を機械的に固定する点にある。(5) app/Enums/Mcp/ToolName.php の docblock が言及する ToolNameInvariantTest は実在するが置き場所は tests/Feature/Mcp/ToolNameInvariantTest.php で Architecture ではない。必須 (5) の gate を新設するときは、この Feature テストと役割が重複しないよう (登録 1:1 の検査 vs キー必須化の中央強制の検査) 切り分けること。(6) 参考にすべき既存 gate は tests/Architecture/ThrottleCoverageInventoryTest.php (494 行)。母集団の下限 pin・「ちょうど 1 本 or 型付き免除 + 30 文字根拠」・stale 検出・死んだ免除の検出・件数 cap (exact fit) という 6 test 構成がそのまま冪等配線目録に流用できる。実効 middleware 列は Router::gatherRouteMiddleware() で取る (route:list --json は group 名が展開されず誤判定する) という既存の作法も踏襲すること。(7) 'idempotent' は routes/api.php にインラインで宣言されており RouteThrottleBinder / RouteMiddlewareBinder の後付け経路には乗っていないため、T135 / route:cache 起動の落とし穴 (docs/app-integration-guide.md §7c) の影響を受けない。
