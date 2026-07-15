# 概念設計: published-scenario-panel

bug-hunt run 20260715-084108 F-1-03 (Medium, H10)

## 背景・課題

動画マニュアル詳細 (`resources/js/pages/Manuals/Show.svelte`) の AI 解析パネル
`resources/js/components/features/manual/AnalysisPanel.svelte` は、解析中でないときの
説明文を次の 3 分岐で出し分けている (L313-321):

```
{#if !hasDocument}        手順書 (SOP) をアップロードすると…シナリオを生成します。   … (a) 未アップロード
{:else if status === "ready"}  手順書から生成したシナリオを編集画面で確認できます。再解析すると…  … (b) シナリオ有り
{:else}                   アップロード済みの手順書から AI がシナリオを生成できます。       … (c) 未生成
```

**バグ**: シナリオ有り表示 (b) を `status === "ready"` **だけ**で判定しているため、
マニュアルが `rendering` / `published` に進むと分岐 (c)（未生成扱いの案内文）へ落ちる。
既に 16 カットのシナリオが確定しているのに「アップロード済みの手順書から AI が
シナリオを生成できます」と表示され、状態バッジ「公開済み」と矛盾する
（ユーザーには「シナリオが未作成に戻った」ように見える）。

### 状態ライフサイクル（コード確認済み）

`App\Enums\Manual\VideoManualStatus` = `draft / analyzing / ready / rendering / published`。
実コードで確認した「シナリオ (cuts) が存在する」状態は次のとおり:

- `draft` → `ready`: cuts が 1 件以上になった時に自動遷移
  （`ScenarioService::transitionStatusAfterEdit` L272 / 解析完了 `AnalysisJobService` L136 は
  `cuts()->exists() ? Ready : Draft`）。
- `ready` → `rendering` → `published`: レンダ経路。`rendering` / `published` は必ずシナリオ確定後。
- `published` → `ready`: シナリオ編集で再合成待ちへ戻す（`ScenarioService` L267）。

したがって **`ready` / `rendering` / `published` は常にシナリオ (cuts) が存在する**。
`draft` / `analyzing` は通常シナリオなし（`analyzing` はパネルの進捗分岐が優先される）。

（補足: 複製経路 `VideoManualService::copyCuts` は `ScenarioService` を経由せず cuts を
コピーするため `draft`+cuts が生成され得るが、これは別導線・別症状。→「スコープ外」参照）

## 改善アイデア

**注意（Round 1 反映）**: 本 map は「cuts 実在判定」ではなく **`status` に基づく表示相の判定** である。
draft+cuts（複製直後）は status ベースでは拾えない別症状であり、命名・コメントで
「シナリオ確定**相** (ready 以降) の表示判定」であることを固定する（cuts 実在は表さない）。

### 施策1: シナリオ有り表示の判定を確定相へ拡張

シナリオ有り表示 (b) の判定を `status === "ready"` から
**「シナリオ確定相 (`ready` / `rendering` / `published`)」** へ広げ、
確定相なのに「未生成」案内 (c) を出さないようにする。

判定は既存の型安全パターン（`CAPTURE_NAVIGABLE_BY_STATUS` + `isCaptureNavigable`,
`resources/js/types/manual.ts`）に倣い、
`SCENARIO_ESTABLISHED_BY_STATUS` (`satisfies Record<VideoManualStatus, boolean>`) と
`isScenarioEstablished(status)` を追加して単一ソース化する。`satisfies Record` により
将来 status を追加した際の分岐漏れをコンパイル時に検出できる
（AGENTS.md / types/manual.ts の「literal union で UI 分岐漏れを検出」方針と一致）。

文言は既存挙動を壊さない範囲で最小変更:
- `ready`: 現行文言（再解析による置換の注記を含む）を**そのまま維持**。`ready` は解析可能状態
  （`AnalysisJobService` L63 が許可するのは `Draft`/`Ready` のみ）で、再解析注記は正確。
- `rendering` / `published`: 再解析は不可（`status_not_analyzable` = 409）なので、
  再解析注記を含まない「生成済みシナリオを編集画面で確認できます」系の文言にする
  （誤って「再解析で置換できる」と示唆しない）。

### 施策2: AI 解析 CTA の表示を解析可能 status に限定（Round 1 Warning 反映）

現状 AI 解析ボタンは `canManage && !analyzing` で表示されるため、`rendering` / `published`
でもボタンが出て、押すと `status_not_analyzable` (409) になる。施策1で「シナリオ有り」表示に
した確定相にこの CTA が残ると新たな不整合になる。そこで、解析可能 status
（`AnalysisJobService` L63 の許可集合 = `draft` / `ready`）に表示を限定する:

- `SCENARIO_ANALYZABLE_BY_STATUS` (`satisfies Record<VideoManualStatus, boolean>`) と
  `isAnalyzable(status)` を追加し、ボタン表示条件を
  `canManage && isAnalyzable(status) && !analyzing` にする。
- **prohibition #8 との区別**: 禁止事項#8 は「必須条件未充足（例: SOP 未アップロード = ユーザーが
  解消可能）を理由に**ボタンを disabled** にする」ことの禁止。本施策は「その状態では解析という操作
  自体が適用対象外（rendering/published は編集で ready へ戻さない限りカテゴリ的に解析不可）」ゆえに
  **非表示**にするもので、disabled 化でも「未充足を理由にした抑止」でもない。draft/ready では
  SOP 未アップロードでもボタンは出し、押下時にサーバのメッセージを表示する既存挙動を維持する
  （#8 遵守）。
- 失敗ジョブ後の再実行導線は status が draft/ready へ復帰しているため保持される。

## 期待効果

- **使命への貢献**: 「シナリオを起点にナビ撮影」する製品で、確定〜公開後の最終状態において
  パネルが状態と矛盾しないことは、ユーザーの状態把握の信頼性に直結する。
- `ready` / `rendering` / `published` で「未生成に戻った」誤案内を解消し、状態バッジ
  （公開済み/書き出し中）と説明文・CTA を整合させる。
  （注: 汎用的な「シナリオ有無の正しい判定」までは主張しない。複製直後の draft+cuts は本 PR 対象外。）
- 型安全な単一ソース化により、将来の status 追加時の UI 分岐漏れをコンパイル時検出。

## 実装方針（概要）

1. `resources/js/types/manual.ts`
   - `SCENARIO_ESTABLISHED_BY_STATUS = { draft:false, analyzing:false, ready:true, rendering:true, published:true } as const satisfies Record<VideoManualStatus, boolean>` と `isScenarioEstablished(status)` を追加。
   - `SCENARIO_ANALYZABLE_BY_STATUS = { draft:true, analyzing:false, ready:true, rendering:false, published:false } as const satisfies Record<VideoManualStatus, boolean>` と `isAnalyzable(status)` を追加。
   - いずれも「表示相の判定」であることをコメントで固定（cuts 実在判定ではない）。
   - 注: `CAPTURE_NAVIGABLE_BY_STATUS`（撮影ナビ導線, rendering=false）とは別概念。統合しない。
2. `resources/js/components/features/manual/AnalysisPanel.svelte`
   - `import { isScenarioEstablished, isAnalyzable }` を追加。
   - 説明文分岐 (L313-321) を `isScenarioEstablished(status)` 優先で組み替える:
     ```
     {#if isScenarioEstablished(status)}
         {#if status === "ready"} 現行の ready 文言（維持） {:else} 生成済みシナリオ案内（再解析注記なし） {/if}
     {:else if !hasDocument} 現行 (a) {:else} 現行 (c) {/if}
     ```
   - AI 解析ボタン表示条件 (L254) を `canManage && isAnalyzable(status) && !analyzing` に変更。
   - ポーリング/stale alert/解析起動ロジック（T032/T046）は不変。

サーバ (Inertia props / DTO / Controller) 変更は不要（`status` は既に props で渡っている）。

## 制約・前提

- v1 スコープ (doc/10) を尊重。字幕のみ / PWA 撮影 / 自前 ffmpeg。
- AGENTS.md セキュリティ不変条件に触れない純フロント表示変更。
- DESIGN.md 準拠: 文言のみ変更、DS token / Atomic Design 階層に変更なし。
- 既存 analyze/stale alert ロジック（`$effect` ポーリング, missing_document 破棄）と整合。
- `ManualEnumTsSyncInvariantTest` は enum の**値集合**同期を検査するもので、UI 専用 map
  （`CAPTURE_NAVIGABLE_BY_STATUS` 等）は対象外。新 map 追加は同期テストに影響しない。

## スコープ

**本 PR のスコープ**: 「シナリオ確定相（`ready` / `rendering` / `published`）で未生成案内が出る
不整合」の解消に限定する。具体的には (1) 説明文の分岐拡張、(2) 解析可能 status への CTA 限定。

## スコープ外

- **複製直後の `draft`+cuts 表示**: `VideoManualService::copyCuts` は `ScenarioService` を
  経由せず cuts を複製するため `draft`+cuts が生成され、複製直後の Show 画面で
  「手順書をアップロードすると…生成します」(a)（`hasDocument=false` のため）が出る。
  これは status ベース（表示相）判定では拾えない別症状。ただし:
  (1) 複製時は「手順書は引き継がれません」と別途通知済みで下書き表示として内的整合、
  (2) F-1-03 の症状（`published` で公開済みバッジと矛盾）とは重大度・文脈が異なる、
  (3) cuts 実在を server prop で渡す案は候補ファイル（AnalysisPanel + status 型）を超え
  v1 の最小修正を逸脱する、
  ため本 PR では扱わず**別 finding として明示管理**する（詳細設計のリスク欄に記載、TODO 起票前提）。
  なお本 PR の helper は「表示相判定」に限定命名し、cuts 実在を吸収したかのような汎用化はしない。
- サーバ側の状態遷移・解析可否ロジックの変更。
