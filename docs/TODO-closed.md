# TODO — Closed / Obsoleted

<!--
運用規約:
- Open / Conditional の TODO は TODO.md を参照
- このファイルへの行追加は /app-todo-close スキル経由で行う
  - close:    TODO.md Open → Closed (完了日を記入。タイトル列に実装サマリーを追記してよい)
  - obsolete: TODO.md Open/Conditional → Obsoleted (廃止日と理由を記入)
- ID は再利用しない (採番は全テーブルを通した最大 ID + 1)
- テーブルが空になってもヘッダー行は残す
-->

Open リストは [TODO.md](TODO.md) を参照。

## Closed

| ID | タイトル | テーマ | 完了日 |
|---|---|---|---|
| T001 | AI-CUE ドメイン基盤(Category/VideoManual)。Category/VideoManual CRUD + Tier B スキーマ先取り (Enum/migration/Model/Factory/Service/Policy/route/UI/テスト一式)。cross-org {project} の FormRequest DB ルール存在オラクルを project.in-current-org middleware で封じる修正込み | backend | 2026-07-10 23:46 |
| T002 | シナリオ編集(document一括保存・楽観ロック)。scenario document 一括 PUT (expected_version 楽観ロック 409 + 行別 422 + protected キー拒否)、ScenarioService reconcile (id 保全・並べ替え・削除 cascade)、ScenarioEditor (Svelte 5 作業コピー編集・409 復帰は onSuccess で reseed・419 自動リトライ)。Codex impl-review Critical なし | backend | 2026-07-11 01:32 |
| T003 | AI解析(SOP→作業分解→シナリオ生成→Cut materialize)。SOP アップロード (内容 sniff + 追記型 immutable)・解析トリガー (analyze 冪等 409 / 残高 402)・RunManualAnalysis + AnalysisPipeline (extract→decompose→generate→materialize、チケット reserve→terminal tx で materialize+commit+succeeded 原子化)・LLM 3 プロンプト YAML + 有界リトライ・stale 回復 cron・AnalysisPanel ポーリング UI。Codex impl-review Critical なし (C1 は前提誤認で反論、W1-W3 修正済み) | backend | 2026-07-11 03:37 |
| T004 | 撮影PWA(presignedアップロード+テイク+容量Quota)。presigned PUT + 署名チケット (reserve→verifying→completed の 2 フェーズ、Organization 行ロックで TOCTOU 防止)・テイク登録 (client_take_id 冪等 + CAS + 重複解決)・容量 Quota (used+pending 集計、pending→used 読み取り順で競合を安全側=拒否側に固定)・stale 予約 sweeper cron・撮影 PWA UI。Codex impl-review Critical なし (occupiedBytes 読み取り順 Warning 修正済み) | backend | 2026-07-11 05:41 |
| T005 | レンダ(採用テイク合成→完成mp4・ffmpeg+チケット)。RenderJob + trigger/preview (in-flight 冪等 409・尺上限 422・残高 402・org preview 上限直列化)・RunManualRender + RenderPipeline (startJob reserve→buildManifest version 固定→ffmpeg 合成→terminal tx で complete+commit+succeeded 原子化)・FfmpegVideoComposer (TakeVideo/TakeStill/Placeholder、字幕は ASS ファイル経由で filtergraph 非注入)・stale 回復/出力 reconcile cron・世代交代削除・RenderPanel ポーリング UI。Codex impl-review Critical なし (Still 経路テスト網羅 Warning 修正済み) | backend | 2026-07-11 07:42 |
| T006 | 管理メニュー(管理者ユーザー管理+カテゴリ管理画面)。/manage 配下 (Users/Categories) + AdminMenuNav・AdminConsoleRole 3 値遷移コマンド (admin/editor/shooter) + MemberRoleState 遷移規約 (最後の owner 保護含む)・招待への project_role 追加 (Default Project 自動割当・受諾時 project 消失は未割当へ縮退)・ManageRouteAuthGuardTest / ProjectMemberPivotWritePathTest の deny-by-default Architecture テスト・Organizations/Settings と Projects/Show からの機能移設。Codex impl-review Critical なし (Enum メッセージキー Warning は反証+回帰テスト固定) | frontend | 2026-07-11 11:46 |
| T007 | LP(トップ)+料金表+チケットリチャージ(aigenba移植)。ゲスト向け LP (Welcome) + 公開料金表 (/pricing、Plan/PlanPrice 真実源 + quota 能力値) + チケットスポット購入 (Stripe Checkout、attempt_token 冪等 / live pending dedup / webhook 冪等付与 / 金額・通貨・customer・org_ref fail-closed 照合) + Standard 価格改定 ¥4,980。Codex impl-review Critical なし (success_url 帰還は session_id 照合の fail-closed バナーに修正、dedup (org,user) 粒度は設計意図として反証) | frontend | 2026-07-11 21:44 |

## Obsoleted

| ID | タイトル | テーマ | 廃止日 | 理由 |
|---|---|---|---|---|
