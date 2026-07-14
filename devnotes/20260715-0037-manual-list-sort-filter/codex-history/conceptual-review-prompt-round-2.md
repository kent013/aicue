## 概念設計レビュー Round 2

Round 1 の指摘への対応を反映しました。対応マトリクスと修正後の概念設計を提示します。判定を更新してください (APPROVED / CHANGES_REQUESTED)。

### Round 1 指摘への対応

| 指摘 | 分類 | 対応 |
|------|------|------|
| SOP 検索が監査 JSON ダンプ検索で意味論ずれ | Critical | **受入。SOP/原稿キーワード検索を v1 out-of-scope に落とした**。`q` は現行 title LIKE を維持(挙動不変) |
| extracted_json::text ILIKE は false pos/neg 多く効果保証できない | Critical | 受入。out-of-scope 化で解消 |
| JSON 全文 LIKE は件数増で一覧性能を崩す | Critical | 受入。検索専用投影(tsvector/text 列)無しに導入しない = out-of-scope |
| 「ギャップ #9 を閉じる」は過剰主張 | Warning | 「発見性ギャップを部分的に縮小」に修正。残課題(原稿検索/作成者名検索/サムネイル)を明示分離 |
| テスト計画への言及がない | Warning | 「テスト方針(概念段階)」節を追加(sort/mine/props/cross-org 非漏洩/paginate 整合/Vitest) |
| JSON 全文検索を query object へ隔離を | Warning | out-of-scope 化で隔離設計自体が不要に。将来実装は検索専用投影で行う旨を残課題に記載 |
| SOP 検索の PC/PWA 同時投入が過大 | Warning | SOP 検索を外し、mine(PC/PWA)+作成者/更新日(PC/PWA)+sort(PC)に限定 |
| 型安全: sort は enum allowlist、mine は bool、creator は nullable shape | Warning | sort=allowlist enum、mine=bool、行 props に creator:{id,name}\|null を明記(詳細設計で shape 固定) |
| 検索の根を $project->manuals() に固定し sourceDocuments 逆引きしない | Warning | SOP 検索除外で sourceDocuments 逆引き自体が消滅。一覧は $project->manuals() 起点維持を明記 |

### 修正後の v1 施策 (確定)
1. 並べ替え (PC のみ): `sort` allowlist enum (updated_desc/updated_asc/title_asc/title_desc)。既定は現行 `created_at desc, id desc` 不変。allowlist 外はフォールバック。
2. 「自分の作成分のみ」フィルタ (PC/PWA): `mine=1` (bool) で `where('created_by', $user->id)`。created_by は payload 非受領。
3. メタ表示: 作成者 (creator relation eager load, creator.name は復号 read, nullable shape) + 更新日 (updated_at) を PC/PWA の一覧行に追加。

out-of-scope: 原稿(SOP)検索 / 作成者名検索 / サムネイル / PWA sort / 再生時間列 / 一覧内 DL・削除。

---

## 修正後の概念設計 (全文)

（以下 conceptual-design.md 全文）

# 概念設計: manual-list-sort-filter (動画一覧の並べ替え・自作フィルタ・メタ表示・原稿検索)

出典: ユースケース・カバレッジ監査ギャップ #9 (Low〜Med)。doc/04 §4.2「動画一覧ページ」/ doc/05 §5.2「シナリオ選択画面」。

## 背景・課題

doc/04・doc/05 が要求する動画/シナリオ一覧の仕様と現状実装に乖離がある。

**doc/04 (PC 動画一覧ページ)**:
- 絞り込み: カテゴリ / **「自分が作成したタイトルのみ」** / 状態
- 検索: タイトル・作成者名などのキーワード
- **並べ替え: 更新日・タイトルで昇順/降順**
- 一覧列: No / 状態 / タイトル / カテゴリ / 再生時間 / **更新日** / DL / 削除

**doc/05 (PWA シナリオ選択画面)**:
- カード表示: サムネイル / タイトル / カテゴリ / **作成者** / **更新日** / 撮影進捗
- 絞り込み: 「すべてのシナリオ」「**自分が作ったシナリオ**」/ カテゴリ別
- 検索: タイトルや**原稿のキーワード**

**現状**:
- `ProjectController::show` (PC): 一覧は `created_at desc, id desc` 固定。フィルタは `category / status / q(title like)` のみ。作成者・更新日の表示なし。並べ替え UI なし。「自作のみ」フィルタなし。
- `CaptureManualController::index` (PWA): `updated_at desc` 固定。フィルタは `category / q(title like)` のみ。作成者表示・更新日表示なし。「自作のみ」なし。原稿検索なし。

いずれも `video_manuals.created_by` (protected, サーバ導出) / `updated_at` は既に存在し、`creator()` relation も定義済み。SOP は `source_documents.extracted_json` に AI 解析の抽出結果 (統一 JSON) が write-only 監査スナップショットとして保存される。

## 改善アイデア (v1 スコープ内)

現行のフィルタ機構 (GET クエリ + typed array props + paginate) を素直に拡張する。新機構は作らない。

### 1. 並べ替え (PC のみ)
`sort` パラメータを追加。許容値は allowlist で固定:
- `updated_desc` / `updated_asc` (更新日)
- `title_asc` / `title_desc` (タイトル)
- 既定は現行踏襲 (`created_at desc, id desc`)。allowlist 外の値は既定へフォールバック (不正値無視 = 既存 status フィルタと同じ流儀)。

doc/05 (PWA) は並べ替えを要求していないため PWA には sort を**追加しない** (過剰実装回避)。PWA は現行 `updated_at desc` を維持。

### 2. 「自分の作成分のみ」フィルタ (PC / PWA 両方)
`mine=1` (bool) パラメータ。true のとき `where('created_by', $user->id)` を追加。`created_by` は既存の protected カラムを read するのみ (payload からは受け取らない = tenant/actor キー不信を維持)。

### 3. メタ表示: 作成者・更新日 (PC / PWA 両方)
- **更新日**: `updated_at` を一覧行 props に追加し表示。
- **作成者**: `creator` relation を eager load し `creator.name` を行 props に追加。`User.name` は CipherSweet 暗号化 PII だがモデル属性の read で自動復号され、表示は既存のメンバー一覧 (member.name を常時表示) と同じ流儀。行の N+1 は `with('creator')` で回避。
- **サムネイル**: **out-of-scope** (後述)。

### 4. 原稿 (SOP) キーワード検索 — v1 out-of-scope (Round 1 レビュー反映)

当初案は `q` を「`title` OR `source_documents.extracted_json` 抽出テキスト」へ拡張することだったが、Codex (gpt-5.4) レビューの Critical を受け **v1 では out-of-scope とする**。

- `extracted_json` は **write-only の監査スナップショット** (AI 抽出結果の統一 JSON) であり、「利用者が見ている原稿」ではない。これを検索実体にすると意味論がずれ、JSON のキー名・構造語に false positive、抽出で落ちた原文語に false negative が生じる。
- 一覧クエリ (PC/PWA 双方) に毎回 JSON 全文部分一致サブクエリを載せると、件数増で一覧性能を崩す共通劣化要因になる。
- 正しく行うには検索専用の平文投影 (検索用 text 列 / pgsql `tsvector`) が必要で、それ自体が独立施策の規模になる。本監査ギャップ #9 (一覧の発見性) の範囲を超える。

したがって `q` は **現行の `title LIKE` を維持** (挙動不変) し、原稿/SOP キーワード検索は残課題 (将来施策) とする。

## 期待効果

- **使命への貢献**: 「思考ゼロ」で現場作業者が目的のマニュアルへ最短到達できる。作成者/更新日メタと自作フィルタは、多数のシナリオから自分の担当・最新版を素早く選び撮影に入るための導線 (doc/05 の撮影ツールとしての要件)。並べ替えは PC 管理者が最新更新・タイトル順で棚卸しする導線。
- doc/04・doc/05 が要求する一覧の**発見性ギャップを部分的に縮小**する (sort / 自作フィルタ / メタ表示)。原稿検索・作成者名検索・サムネイルは残課題として分離。
- 既存の GET クエリ + paginate 機構の素直な拡張で、後方互換の並走を残さない。

## テスト方針 (概念段階)

各施策は Feature テスト (Pest, RefreshDatabase グローバル + `--parallel`) + Vitest で担保する。詳細は詳細設計で確定:

- **sort (PC)**: allowlist 各値で `manuals.data` の順序を検証。allowlist 外値は既定順へフォールバック。既定 (sort 無し) は現行 `created_at desc, id desc` 不変。
- **mine (PC/PWA)**: `mine=1` で自ユーザー作成分のみに絞られること。他ユーザー作成分が除外されること。
- **作成者/更新日 props**: `manuals.data.*` に `creator` (nullable shape) と `updated_at` が供給されること。
- **組織スコープ非漏洩**: cross-org / cross-project の manual が sort/mine/props 経由で漏れないこと (現行 `$project->manuals()` 起点の維持を回帰確認)。
- **paginate 整合**: sort/mine が `withQueryString()` でページ跨ぎに保持されること。
- **Vitest**: sort セレクト・自作チェックの GET クエリ組み立て、行の作成者/更新日表示。

## 実装方針 (概要)

| 対象 | 変更概要 |
|------|---------|
| `ProjectController::show` (`parseManualFilters` / `manualRows`) | フィルタ typed array に `sort` (allowlist enum) / `mine` (bool) を追加。sort allowlist で orderBy を分岐。`mine` で `created_by` where。`q` は現行 title LIKE のまま。`with('creator')` で行に作成者・更新日を追加 |
| `CaptureManualController::index` | `mine` フィルタ・`with('creator')` + creatorName を summary DTO へ (updatedAt は既存)。sort・SOP 検索は追加しない |
| `CaptureManualSummaryData` | `creatorName` を追加 (updatedAt は既存) |
| `resources/js/types/manual.ts` | `ManualFilters` に `sort`/`mine`、`ManualListItem` に `updated_at`/`creator` を追加 |
| `resources/js/types/capture.ts` | `CaptureManualSummary` に `creator_name`、filters に `mine` を追加 |
| `resources/js/pages/Projects/Show.svelte` | sort セレクト・「自分の作成分のみ」チェック・行に作成者/更新日表示 |
| `resources/js/pages/Capture/Index.svelte` | 「自分が作ったシナリオ」トグル・カードに作成者/更新日表示 |

フィルタ条件は PHP 側で typed array (PHPStan L10 の shape 固定) として組み立て、既存 `manualFilters` prop と対称に TS 型へ反映する。

## 制約・前提

- **組織スコープ維持**: 一覧は現行どおり `$project->manuals()` 経由 (org-scoped project を認可前 404 で解決済み)。`created_by` フィルタは自ユーザー id のみで cross-org read を増やさない。
- **tenant キー不信**: `created_by` は payload から受け取らず `auth user` の id を使う。
- **PII**: 作成者 `name` は CipherSweet PII。**表示は復号 read で可**だが、**作成者名での検索は行わない** (whereBlind が必要 = 完全一致のみで部分一致に向かず、doc の主眼は SOP/タイトル検索)。作成者名検索は out-of-scope として明示。
- **DTO / JsonResource**: Inertia props は既存の typed array 流儀を踏襲 (response()->json 直書きなし)。
- **ページネーション整合**: PC は現行 `paginate(10)->withQueryString()` を維持し sort/mine/q もクエリに載せる。
- **DESIGN.md**: UI 追加は既存 atom (Select/Input/Checkbox) と DS token のみ。Checkbox atom 経由 (フォーム規約)。

## スコープ外 (v1 で実装しない = 残課題 / 将来施策)

1. **原稿 (SOP) キーワード検索**: Round 1 レビュー反映。監査 JSON (`extracted_json`) を検索実体にする案は意味論ずれ + 一覧性能劣化のため却下。正式実装には検索専用の平文投影 (検索用 text 列 / pgsql `tsvector`) が要り独立施策規模。将来施策として分離。
2. **サムネイル表示**: v1 では manual 単位のサムネイル成果物が存在しない (`thumbnail_path` は take 単位のみ。レンダ済み動画のポスター画像を manual 単位で保持していない)。doc/05 のカード「サムネイル」は成果物不在のため out-of-scope。撮影進捗バッジ (既存) で代替済み。
3. **作成者名でのキーワード検索** (doc/04 の「作成者名など」): `User.name` は CipherSweet 暗号化で部分一致 (LIKE) 不可 (whereBlind は完全一致)。out-of-scope。
4. **PWA の並べ替え UI**: doc/05 が要求していないため追加しない。
5. **再生時間 (total_length_ms) の一覧列**: doc/04 に列挙はあるがレンダ後派生値で本監査ギャップ #9 の主眼外。既存スコープを広げないため out-of-scope (別途必要なら独立施策)。
6. **DL / 削除の一覧内アクション**: 既存の詳細画面導線を維持。本施策は「並べ替え・自作フィルタ・メタ表示」に限定。

> 本施策は doc/04・doc/05 のギャップ #9 を**完全には閉じない**。sort / 自作フィルタ / 作成者・更新日メタ表示で発見性ギャップを縮小し、残る原稿検索・作成者名検索・サムネイルは上記のとおり将来施策へ分離する。
