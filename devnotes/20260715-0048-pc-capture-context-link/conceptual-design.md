# 概念設計: pc-capture-context-link

## 背景・課題

ユースケース・カバレッジ監査ギャップ #10 (Low-Med)。

PC 管理 UI のマニュアル詳細 (`Manuals/Show.svelte`) / 編集 (`Manuals/Edit.svelte`) から、
当該マニュアルの**撮影ナビ画面** (`capture.manuals.show` = `/app/projects/{project}/manuals/{manual}`)
への文脈リンクが存在しない。

現状、撮影面へ到達するには Dashboard の `/app` ボタン → プロジェクト選択 → マニュアル一覧 →
再度マニュアル選択、というリセレクトが必要。編集者が「シナリオを整えた → そのまま撮影に行きたい」
という自然な導線が途切れており、使命 (SOP→シナリオ→ナビ撮影の一気通貫) の体験に摩擦がある。

## 改善アイデア

`Manuals/Show.svelte` と `Manuals/Edit.svelte` に「**この手順書を撮影する**」リンク/ボタンを追加し、
当該マニュアルの撮影ナビ画面 (`capture.manuals.show`) へ 1 クリックで遷移できるようにする。

- 遷移先 URL: `/app/projects/${project.id}/manuals/${manual.id}`
  (既存の `Dashboard.svelte:65` が同一パスを既に使用しており、パターン確立済み)。
- 表示条件は「撮影対象になり得る状態」= `status ∈ {ready, published}` に限定する
  (撮影ナビ一覧 `CaptureManualController::index` が ready/published のみを列挙する既存セマンティクスと一致。
  draft / analyzing / rendering はシナリオ未確定でナビ画面が空になり導線が dead-end になるため出さない)。
- 判定は文字列リテラルを画面に散在させず、`resources/js/types/manual.ts` に追加する**型付き predicate**
  `isCaptureNavigable(status: VideoManualStatus): boolean` 経由で行う。判定表は
  `CAPTURE_NAVIGABLE_BY_STATUS satisfies Record<VideoManualStatus, boolean>` の**網羅マップ**とし、
  `VideoManualStatus` に case が増えたら型エラーで検知できるようにする (単なる配列では追加時に
  コンパイルエラーにならないため。Codex R1/R2 指摘)。
- 撮影不可状態では**リンク自体を非表示**にする (禁止事項 #8 に従い disabled ボタンにはしない)。

## 期待効果

- **使命への貢献**: 「SOP → AI シナリオ → スマホでナビ撮影」の一気通貫体験のうち、**アプリ内導線**の
  摩擦を除去。編集者がシナリオ確定後そのまま撮影ナビ画面へ乗れる。
- リセレクト (プロジェクト→マニュアル再選択) の手数を 3〜4 クリックから 1 クリックへ削減。
  (注: 本改善は**アプリ内導線短縮**であり、PC ブラウザ→スマホ実機へのハンドオフ自体を解決するものではない。)
- 監査ギャップ #10 (PC→撮影 文脈リンク欠落) を解消。

## 実装方針（概要）

1. `resources/js/pages/Manuals/Show.svelte`
   - ヘッダ操作領域に「この手順書を撮影する」導線を追加。
   - **`canManage` の内外を問わず表示**する (撮影者=project_member も Show を閲覧でき、
     撮影ナビ (`view` 認可) にも入れるため)。既存の複製/編集/削除ボタン (canManage 限定) とは別枠に置く。
2. `resources/js/pages/Manuals/Edit.svelte`
   - ヘッダ付近に同じ「この手順書を撮影する」導線を追加 (編集者のみが到達する画面)。
   - 遷移は既存の「キャンセル」ghost リンクと**同一の Inertia 通常遷移セマンティクス** (dirty ガードなし)。
     未保存の title/category は破棄されるが、これは既存「キャンセル」と同挙動であり本改善で挙動は変えない。
     撮影リンクは保存ボタン群 (基本情報カード内) とは別のヘッダ側に置き、保存アクションと視覚的に競合させない。
3. 遷移は Inertia の通常遷移 (`Button` atom の `href` + `inertia`、または `TextLink`)。
   URL はテンプレートリテラルで生成 (Svelte 側に Ziggy `route()` は未導入、既存コード全て同方式)。
4. アイコンは Lucide (`@lucide/svelte`。例: `Video` / `Clapperboard` 等) を使用。SVG 直書きはしない。
5. `resources/js/types/manual.ts` に `CAPTURE_NAVIGABLE_STATUSES` と `isCaptureNavigable()` を追加
   (型付き判定の単一ソース。Show/Edit 双方がこれを参照)。

### 認可・組織スコープ (確認結果)

- 遷移先 `capture.manuals.show` は `Gate::authorize('view', $manual)` = **org member なら可**
  (撮影者含む。`CaptureManualController::show` L98)。撮影 (take upload) は別途 `ProjectPolicy::capture`
  だがナビ画面の**閲覧**は view で足りる。
- PC の Show は `view`、Edit は `update` 認可で到達する。**いずれも撮影ナビの view 認可を包含**するため、
  リンクを出した相手が遷移先で 403 になることはない (フロントの表示条件は UX 上の話であり、
  認可はサーバ側の `capture.manuals.show` が最終担保)。
- `capture.manuals.show` は `project.in-current-org` middleware 配下。PC のマニュアルも current org の
  プロジェクト配下なので組織スコープは一致 (cross-org 遷移は発生しない)。

## テスト方針 (テストファースト)

vitest (`tests/js/`) で以下を検証する。詳細ケースは詳細設計「テスト計画」で確定:

- **predicate 単体**: `isCaptureNavigable` が `ready`/`published` で `true`、`draft`/`analyzing`/`rendering`
  で `false` を返す (全 `VideoManualStatus` を網羅)。
- **Show 表示条件**: ready/published のみ撮影リンクを描画、draft 等では非描画。
- **Show 権限非依存**: `canManage=false` (撮影者) でも撮影リンクが描画される。
- **Edit 表示条件**: ready/published のみ撮影リンクを描画。
- **リンク先 URL**: `href` が `/app/projects/{project.id}/manuals/{manual.id}` (対象 project/manual) を指す。

## 制約・前提

- 既存アーキテクチャ (Svelte 5 runes + DS token + Atomic Design + `@lucide/svelte`) に整合。
- サーバ側の変更は不要 (ルート・Controller・DTO・認可はいずれも既存のまま)。純フロント (view) の変更。
- URL 空間: PC 管理 (`/projects/...`) と 撮影 PWA (`/app/...`) は doc/10 §10.8-3 で意図的に分離。
  本改善は「PC → PWA」の一方向リンクを張るのみで、URL 空間の統合はしない。

## スコープ外

- **逆方向 (撮影ナビ → PC 編集) の導線追加は out-of-scope**。
  - 監査ギャップ #10 の症状は「PC→撮影」の欠落であり、本改善で解消する。
  - 撮影 PWA (`Capture/Show.svelte`) は URL 空間ごと分離された撮影者向け画面。逆リンクを張るには
    撮影者 (project_member) が編集不可である以上 `canManage` 相当を Capture の Controller/DTO/Props に
    新規に流し込む必要があり、スコープが非対称に膨らむ。PWA を編集 UI へ引き戻す導線は撮影集中の
    体験を乱すため、本改善では追加しない (必要になれば別 TODO)。
- Ziggy `route()` ヘルパの Svelte 導入 (既存全ページがテンプレートリテラル方式。本件だけ変えない)。
- 撮影可否 (take upload 権限) に基づく表示制御。ナビ画面の閲覧は view で足り、撮影権限の有無で
  リンクを出し分ける必要はない (over-engineering)。
