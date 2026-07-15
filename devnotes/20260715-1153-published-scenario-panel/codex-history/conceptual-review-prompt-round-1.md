# アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか（本件は純フロント）

【特に判断してほしい論点】
- シナリオ有無の判定を「status ベース（ready/rendering/published）」に留める設計判断は妥当か。
  それとも cuts 実在を server prop で渡す（Controller/Inertia props/型の波及変更を伴う）案にすべきか。
- 複製直後の draft+cuts をスコープ外とする判断は妥当か。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（以下、devnotes/20260715-1153-published-scenario-panel/conceptual-design.md の内容）

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

シナリオ有り表示 (b) の判定を `status === "ready"` から
**「シナリオ確定フェーズ (`ready` / `rendering` / `published`)」** へ広げ、
cuts が存在するのに「未生成」案内 (c) を出さないようにする。

判定は既存の型安全パターン（`CAPTURE_NAVIGABLE_BY_STATUS` + `isCaptureNavigable`,
`resources/js/types/manual.ts`）に倣い、
`SCENARIO_PRESENT_BY_STATUS` (`satisfies Record<VideoManualStatus, boolean>`) と
`hasScenario(status)` を追加して単一ソース化する。`satisfies Record` により
将来 status を追加した際の分岐漏れをコンパイル時に検出できる
（AGENTS.md / types/manual.ts の「literal union で UI 分岐漏れを検出」方針と一致）。

文言は既存挙動を壊さない範囲で最小変更:
- `ready`: 現行文言（再解析による置換の注記を含む）を**そのまま維持**。`ready` は解析可能状態
  （`AnalysisJobService` L63 が許可するのは `Draft`/`Ready` のみ）で、再解析注記は正確。
- `rendering` / `published`: 再解析は不可（`status_not_analyzable` = 409）なので、
  再解析注記を含まない「生成済みシナリオを編集画面で確認できます」系の文言にする
  （誤って「再解析で置換できる」と示唆しない）。

## 期待効果

- **使命への貢献**: 「シナリオを起点にナビ撮影」する製品で、シナリオ確定〜公開後の
  最終状態においてパネルが状態と矛盾しないことは、ユーザーの状態把握の信頼性に直結する。
  公開済みマニュアルで「未作成に戻った」誤認を解消する。
- 状態バッジ（公開済み/書き出し中）と説明文の整合。
- 型安全な単一ソース化により、将来の status 追加時の UI 分岐漏れをコンパイル時検出。

## 実装方針（概要）

1. `resources/js/types/manual.ts`
   - `SCENARIO_PRESENT_BY_STATUS = { draft:false, analyzing:false, ready:true, rendering:true, published:true } as const satisfies Record<VideoManualStatus, boolean>` を追加。
   - `hasScenario(status: VideoManualStatus): boolean` を追加（`CAPTURE_NAVIGABLE_BY_STATUS` と同型）。
   - 注: `CAPTURE_NAVIGABLE_BY_STATUS` とは別概念（撮影ナビ導線は `rendering=false`、
     本 map はシナリオ存在なので `rendering=true`）。統合しない（別物の概念を統合しない）。
2. `resources/js/components/features/manual/AnalysisPanel.svelte`
   - 説明文分岐 (L313-321) を `hasScenario(status)` 優先で組み替える:
     ```
     {#if hasScenario(status)}
         {#if status === "ready"} 現行の ready 文言（維持） {:else} 生成済みシナリオ案内（再解析注記なし） {/if}
     {:else if !hasDocument} 現行 (a) {:else} 現行 (c) {/if}
     ```
   - `import { hasScenario }` を追加。
   - ポーリング/stale alert/解析起動ロジック（T032/T046）は不変。

サーバ (Inertia props / DTO / Controller) 変更は不要（`status` は既に props で渡っている）。

## 制約・前提

- v1 スコープ (doc/10) を尊重。字幕のみ / PWA 撮影 / 自前 ffmpeg。
- AGENTS.md セキュリティ不変条件に触れない純フロント表示変更。
- DESIGN.md 準拠: 文言のみ変更、DS token / Atomic Design 階層に変更なし。
- 既存 analyze/stale alert ロジック（`$effect` ポーリング, missing_document 破棄）と整合。
- `ManualEnumTsSyncInvariantTest` は enum の**値集合**同期を検査するもので、UI 専用 map
  （`CAPTURE_NAVIGABLE_BY_STATUS` 等）は対象外。新 map 追加は同期テストに影響しない。

## スコープ外

- **複製直後の `draft`+cuts 表示**: `VideoManualService::copyCuts` は `ScenarioService` を
  経由せず cuts を複製するため `draft`+cuts が生成され、複製直後の Show 画面で
  「手順書をアップロードすると…生成します」(a)（`hasDocument=false` のため）が出る。
  これは status ベース判定では拾えない別症状。ただし:
  (1) 複製時は「手順書は引き継がれません」と別途通知済みで下書き表示として内的整合、
  (2) F-1-03 の症状（`published` で公開済みバッジと矛盾）とは重大度・文脈が異なる、
  (3) cuts 実在を server prop で渡す案は候補ファイル（AnalysisPanel + status 型）を超え
  v1 の最小修正を逸脱する、
  ため本 PR では扱わず別 finding 候補とする（詳細設計のリスク欄に明記）。
- AI 解析ボタン自体の表示制御（`published` で押下すると 409 になる既存挙動）は変更しない。
- サーバ側の状態遷移・解析可否ロジックの変更。

