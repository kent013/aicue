## アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(招待送信等は `back()->with(...)` で完結)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)

### セキュリティ不変条件（アプリ都合で緩めない・Architecture テストで強制）
1. tenant キー不信: ownership/actor/tenant キーを payload から受け取らない
2. 子は親に属する: nested route の不整合は認可より前に 404（NestedRouteIdorDefenseTest 登録必須）
3. cross-org 不可: 組織を跨ぐ read/write をしない
4. untrusted 文字列は UserInput 型経由でのみ prompt に入れる
5. 権限判定は常に laratrust_team_id を明示（strict_check=true）
6. PII(email/name)は CipherSweet。検索は whereBlind()
7. 課金の冪等性: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. 外部 URL 取得は SSRF 検査経由

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。想定外のパターンも判断材料になる。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。
仕組みが機能していない段階で値を弄るな。設計の方向性が正しいと確認できてから細部を詰めろ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system（役割）

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【補足コンテキスト】
- 本設計はマルチフェーズの AI-CUE ドメイン実装の「フェーズ1（データ基盤 + 最初の CRUD リソース）」に限定されている。AI 解析・シナリオ編集・レンダ・撮影 PWA・課金統合は明示的にスコープ外（後続フェーズ）。
- 確定仕様は `doc/10_実装仕様.md`（特に §10.6 フェーズ1着手チェックリスト、§10.8 着手前レビュー反映が §10.1〜§10.7 に優先）。
- テンプレート基盤（認証・組織/Team/Project・セキュリティ層・課金プリミティブ・LLM コア）は実装済みで、`Item` が「Project 配下リソースの正しい追加」の見本として存在する。

---

## user（概念設計）

# 概念設計: aicue-domain-foundation（AI-CUE ドメイン実装フェーズ1・データ基盤 + Category/VideoManual リソース）

## 背景・課題

テンプレート抽出 Phase 0〜10 は完了し、認証 / 組織・Team・Project / セキュリティ層 / 課金 / API・MCP / LLM コア / 管理画面などの**汎用基盤は green**（`doc`・`devnotes/20260611-template-extraction`）。一方、**AI-CUE のドメイン本体（`categories` / `video_manuals` / `source_documents` / `cuts` / `takes` / ジョブ群、撮影 PWA、SOP→シナリオ生成）は未実装**で、存在するドメインらしきモデルは汎用サンプルの `Item` / `Project` のみ。

`doc/10 実装仕様` §10.6 は、フェーズ1を「初期化後、**Category → VideoManual → Cut → Take** の順で各リソースを `Item` 見本と同じ 15 点セットでトレースする」と定義している。§10.8（2026-07-10 の着手前レビュー反映）は §10.1〜§10.7 に優先する確定事項。まずこのフェーズ1の**データ基盤とユーザーが直接 CRUD する最初のリソース**を立ち上げ、以降のフェーズ（AI 解析・シナリオ編集・レンダ・撮影 PWA・課金統合）が乗る土台を green で確定させる。

**仮説**: `Item` 見本のトレースで確立済みの「org-scoped 解決 → 認可前 404 → 親委譲 Policy → protected FK → NestedRouteIdorDefenseTest 登録」パターンを、AI-CUE の中核集約（Project ─< Category / VideoManual ─< Cut ─< Take）へ機械的に横展開できる。ドメイン固有の難所（チケット2フェーズ消費・楽観ロック・PWA・ffmpeg）を**振る舞いとして持ち込まず、スキーマとして先取り**すれば、フェーズ1は既存テンプレのレンジ内（新規機構ゼロ）で完了できる。

**成功判定**: `composer test` / `phpstan`(lv10) / `pint --test` / `pnpm lint,typecheck,test,build` が全 green。Category の管理 CRUD と VideoManual の一覧/作成/表示/削除が動作し、保護キー 422・cross-org/cross-project 404・権限（編集者/撮影者）が Feature テストで固定される。

## 改善アイデア

`doc/10` §10.1〜§10.8 のうち**フェーズ1の土台に必要な部分だけ**を、テンプレの `Item` 規約に沿って実装する。ドメインの中核集約 5 テーブルのスキーマ・Model・Factory・保護キー・IDOR 防御を先に確定し、そのうち**ユーザーが直接操作する Category と VideoManual にだけ CRUD UI とルートを与える**。Cut / Take / SourceDocument は「振る舞いは後続フェーズ、スキーマと親子・IDOR 連鎖は今」確立する。

### スコープ（フェーズ1）

1. **Enum 定義（string backed）**: `VideoManualStatus`(draft/analyzing/ready/rendering/published) / `CutType`(step/point) / `ShotType`(hiki/yori) / `TakeStatus`(uploading/processing/ready/failed) / `JobStatus`(queued/running/succeeded/failed) / `MaterialType`(video/still)。状態遷移の**振る舞い（遷移メソッド）は後続**、定義と cast のみ。
2. **マイグレーション（§10.1 の確定スキーマ、§10.8 のカラム追加を織り込む）**:
   - `categories`（project_id protected・cascade、name project 内ユニーク、sort_order）
   - `video_manuals`（project_id/created_by protected、category_id NULL・削除時 set null、status、`scenario_version` int default 0〔§10.8-2 楽観ロック用カラム。振る舞いは後続〕、total_length_ms NULL）
   - `source_documents`（video_manual_id protected cascade、file_path/original_name/mime/size_bytes、extracted_json NULL）
   - `cuts`（video_manual_id/parent_cut_id/adopted_take_id protected、type/shot_type/material_type enum、sort_order、本文フィールド群）
   - `takes`（cut_id protected cascade、`client_take_id` で `(cut_id, client_take_id)` UNIQUE〔同期冪等キー〕、size_bytes〔§10.8-4 Quota 計上用カラム〕、status、sort_order）
3. **Model**: 親 BelongsTo / 子 hasMany、FK は `$fillable` 外、relation 経由 create。`Item` と同じ型注釈規約（`@use HasFactory<...>`、generics）。
4. **`MassAssignmentProtectedKeys` 追記**（§10.1）: `video_manual_id` / `cut_id` / `parent_cut_id` / `category_id` / `adopted_take_id` / `created_by` / `source_document_id`（`ticket_reservation_id` はジョブテーブル導入時=後続フェーズ）。
5. **Factory**（親 Factory 連鎖）: 5 モデル分。`docs/architecture.md` / `docs/factories.md` に追記。
6. **ルート + IDOR 防御**: `/projects/{project}/categories`(resource・管理者) と `/projects/{project}/manuals`（index は Projects/Show 内包・create/store/show/destroy）を `scopeBindings()` で。**全 nested route を `NestedRouteIdorDefenseTest` inventory に登録**（manual→cut→take 連鎖も、UI 未提供でもルート土台として登録）。
7. **FormRequest**（`ProhibitsProtectedKeys` + rules）: Category / VideoManual の Store・Update。
8. **Policy（親委譲）**: `CategoryPolicy` / `VideoManualPolicy`（`ProjectPolicy` に委譲、直 fetch 禁止）。ロールは §10.5：project_admin=編集者（manual CRUD 等）、project_member=撮影者（read 中心）。
9. **Svelte 画面（DS token のみ）**: `Projects/Show.svelte` を見本に**動画一覧をカテゴリ/状態/検索で絞り込み内包**、VideoManual の create/show スケルトン、Category 管理（追加/並べ替え/エラー表記は §禁止事項8 に従い disabled 禁止）。
10. **Feature テスト + Vitest**: 保護キー 422 / cross-org・cross-project 404 / 権限（編集者は CRUD 可・撮影者は不可）/ Category 削除で manual が set null（未分類化）。

## 期待効果

- **使命への貢献**: AI-CUE の中核データモデル（SOP=SourceDocument → VideoManual → Cut → Take）を確定し、「SOP 起点で AI がカット設計した動画マニュアル」を格納する器を green で用意する。以降のフェーズ（AI 解析・シナリオ編集・撮影・レンダ）が全てこの土台に乗る。
- **具体的改善**: `Item` 見本で実証済みのセキュリティ不変条件（tenant キー不信・子は親に属する・cross-org 不可）を、ドメイン全リソースへ機械的に横展開。難所（楽観ロック・容量 Quota・冪等同期）を**スキーマとして先取り**することで、後続フェーズはカラム追加なしに振る舞いだけを足せる。

## 実装方針（概要）

- 新規機構は作らない。既存テンプレの `Item`（Model/Controller/Policy/Request/Factory/routes/IDOR test）を型紙に、5 リソースへ横展開する（AGENTS.md 思考原則1「フレームワークのレンジ内」）。
- `doc/10` の**フェーズ1チェックリスト（§10.6）15 点セット**をリソース単位で満たす。難所（§10.8-1〜7）は**カラム/UNIQUE 制約としてのみ**取り込み、振る舞い（reserve/commit・409 楽観ロック・presigned・ffmpeg）は後続フェーズの TODO とする。
- transaction は Service 内（Category 並べ替え等の複数行更新）。Controller は薄く。
- `docs/template-divergence.md` へ、後続で発生する意図的逸脱（シナリオ document 一括保存・メディア・合成）は**発生フェーズで**記録（フェーズ1では逸脱を持ち込まない方針）。

## 制約・前提

- **§10.8 が §10.1〜§10.7 に優先**する確定仕様。フェーズ1ではカラム（`scenario_version` / `size_bytes` / `client_take_id` UNIQUE / `category_id` set null）として反映。
- セキュリティ不変条件（AGENTS.md §セキュリティ不変条件）を全リソースで満たす: protected FK は payload 拒否、nested route 不整合は認可前 404、cross-org read/write 不可、権限は `laratrust_team_id` 明示。
- PII 該当カラムは現状なし（name/title は業務データ、email/氏名ではない）→ CipherSweet 対象外。
- 課金・Quota・LLM・PWA・ffmpeg は**このフェーズでは配線しない**（後続）。`analysis_jobs` / `render_jobs` テーブルも AI/レンダフェーズで導入（`ticket_reservation_id` 依存のため）。

## スコープ外（後続フェーズ）

- AI 解析（`analysis_jobs`、work-decomposition / scenario-generation / sop-extract プロンプト、DTO 検証・有界リトライ）— §10.4 / §10.8-1
- シナリオ document 一括保存（`PUT .../scenario`、楽観ロック 409、protected キーのネスト構造決定）— §10.8-2 / §10.8-5
- レンダ（`render_jobs`、ffmpeg ワーカー、バージョン固定、ダウンロード署名 URL）— §10.8-6
- 撮影 PWA（presigned upload、署名チケット検証 + HeadObject、takes sync/adopt、CSRF 419 リトライ、カメラフォールバック）— §10.3 / §10.8-3 / §10.8-7
- 課金統合（チケット reserve→commit/release の 2 フェーズ、容量 Quota 実計上・孤児掃除 cron）— §10.5 / §10.8-1 / §10.8-4
- 多言語（`feature_multilang`）

