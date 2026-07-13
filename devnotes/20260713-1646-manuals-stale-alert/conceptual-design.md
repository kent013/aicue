# 概念設計: manuals-stale-alert

## 背景・課題

bug-hunt finding **F-H2 (High, H10)**。

manuals show 画面 (`resources/js/pages/Manuals/Show.svelte`) で、直前の AI 解析起動失敗に由来する
赤字 alert「手順書をアップロードしてください。」が、その後に **SOP アップロード成功 / シナリオ保存成功 /
解析成功** といった別操作を行っても **消えずに残留する**。手動リロードで消えるため、
クライアント側の **stale local state** が原因。

### 根本原因 (コードレベル)

エラー文言の実体は Show ページ内の `AnalysisPanel.svelte`
(`resources/js/components/features/manual/AnalysisPanel.svelte`) が持つローカル `$state`:

- `errorMessage` … 解析起動 (`POST .../analyze`) の 402/409/422 応答メッセージを表示する。
  「手順書をアップロードしてください。」は **422 (手順書なし)** の応答文言。
- 付随する一過性 state: `showPurchaseLink` (402 併記の購入導線) / `sessionExpiredMessage` (401/419)。
- `currentJob` / `status` … `job` / `manualStatus` props から **一度だけ seed** し
  (`// svelte-ignore state_referenced_locally`)、以後は XHR 応答でのみ更新する設計。

一方、SOP アップロードは兄弟コンポーネント `SourceDocumentUpload.svelte` が Inertia の
`form.post(...)` で送信し、サーバは `back()->with('success', ...)` を返す
(`app/Http/Controllers/Projects/SourceDocumentController.php`)。これにより Show ページは
**同一ページコンポーネントのまま新しい props で再描画**される (`analysis.hasDocument` が
`false → true` になる)。

しかし Inertia は同一ページコンポーネントを **再マウントしない**ため、`AnalysisPanel` の
ローカル `errorMessage` は初期 seed のまま残り、`startAnalyze()` を再度呼ぶまでクリアされない。
結果として「サーバの新しい真実 (手順書あり)」と「クライアントの古いエラー overlay」が矛盾し、
残留エラーとして表示される。

> 補足: 「シナリオ保存成功」は別ページ (`Manuals/Edit.svelte`) の操作であり、Show への遷移で
> `AnalysisPanel` は再マウントされるため元来 stale は起きない。本 finding の残留は
> **Show ページ内の兄弟操作 (SOP アップロード) 後に props だけ更新され、ローカルエラーが
> 再同期されない**ケースが本質。

## 修正方針: 「手順書なし 422」overlay を、手順書が現れた瞬間に破棄する

Round 1→2 の合議で、「新しいサーバスナップショットが来たら overlay を無条件破棄」という
一般化は **署名比較では実装と乖離する** (署名値が全て同じなら Inertia の再訪でも no-op になり
overlay が残る) ことが判明した。そこで **本 finding の根本原因に忠実な narrow fix** に統一する。

本 finding の残留エラーは 422「手順書をアップロードしてください。」= `errorMessage` (start error) であり、
その **precondition (手順書が存在しない) が解消される契機は `hasDocument` prop の `false → true` 遷移**
(= SOP アップロード成功で Inertia が Show を再描画したとき) に一意に対応する。したがって:

> **`hasDocument` が `false → true` に遷移したとき、start error が「手順書なし (422)」種別であれば
> その overlay を破棄する。**

### 型安全なエラー種別の保持 (観点7 対応)

「手順書なし 422 だけ」を server-side の検証順序に依存せず型安全に判定するため、start error に
**種別 (kind)** のローカル state を持たせる:

```ts
type StartErrorKind = "missing_document" | "insufficient_tickets" | "conflict" | "generic";
let startErrorKind = $state<StartErrorKind | null>(null);
```

- `handleStartResponse` で res.status / code から `startErrorKind` を設定
  (422→`missing_document` / 402(insufficient_tickets)→`insufficient_tickets` /
  409→`conflict` / それ以外→`generic`)。
- `startAnalyze()` 冒頭の既存リセット (`errorMessage = null` 等) に `startErrorKind = null` を追加。
- `errorMessage` の**文字列一致で判定しない** (国際化・文言変更に脆いため)。

### 破棄トリガー (`$effect`)

```
前回の hasDocument を非リアクティブなローカル変数 prevHasDocument で保持する
$effect:
  const now = hasDocument
  const was = prevHasDocument
  prevHasDocument = now
  if (!was && now && startErrorKind === "missing_document"):
    errorMessage = null
    showPurchaseLink = false
    startErrorKind = null
```

- 反応的依存は `hasDocument` (と `startErrorKind`)。**ポーリングは props を変えない**ので、
  この effect は解析進行中には発火せず、進捗表示・2.5 秒間隔を壊さない。
- マウント初回 run は `was === now` で no-op。
- `false→true` 以外の遷移 (true→false 等) では破棄しない。
- `currentJob` / `status` / `sessionExpiredMessage` は触らない (server-truth 由来 / poll 系は非対象)。

## 期待効果

- **使命への貢献**: 「思考ゼロ・編集ゼロ」で現場作業者が SOP → シナリオ → 撮影へ迷わず進める
  導線において、SOP アップロード成功後も「手順書をアップロードしてください。」が残ると、
  作業者は「まだ何か失敗している」と誤認し次アクション (AI 解析) に進めない。この誤認と
  操作の詰まりを解消し、中核導線の信頼性を回復する。
- **具体的改善 (本 finding の直接効果)**: Show 画面で 422「手順書をアップロードしてください。」を
  出した後、**同一画面で SOP アップロードが成功すると (`hasDocument: false→true`) 赤字 alert が
  即時に消える**。手動リロード不要。
- 効果範囲は「Show 内 SOP アップロード後に残る 422」に限定する (過大主張しない)。

## 受け入れ基準 (acceptance criteria)

1. Show で AI 解析 → 422「手順書をアップロードしてください。」表示 → `hasDocument` が
   `false→true` に更新される → **start-error alert (`data-testid="analysis-start-error"`) が消える**。
2. ポーリング中 (analyzing) の進捗表示・step ラベル・2.5 秒間隔は **壊れない** (props 不変のため
   破棄 effect は発火しない)。
3. `failedJob` alert (`data-testid="analysis-error"`、サーバ真実由来) は本変更で消えない (非退行)。
4. `insufficient_tickets` (402) 等、`missing_document` 以外の start error は
   `hasDocument` 遷移で消えない (種別ゲートの検証)。
5. `pnpm typecheck` / `pnpm lint` / `pnpm build` green、`pnpm test` (vitest) の
   `AnalysisPanel.test.ts` 既存ケース全 green + 下記追加ケース green:
   - 「422 表示後に `hasDocument: false→true` の rerender で start-error alert が消える」
   - 「`hasDocument: false→true` でも `missing_document` 以外 (例: 402 相当を模した種別) の
     start error は消えない」(種別ゲート)
   - 「ポーリング state / failedJob 表示が rerender で維持される」(非退行)

## 実装方針（概要）

- 変更対象は原則 **`AnalysisPanel.svelte` の 1 ファイル**。
  - `StartErrorKind` union と `startErrorKind` state を追加し、`handleStartResponse` /
    `startAnalyze` リセットで設定・クリアする。
  - `hasDocument` の `false→true` 遷移を検知する `$effect` を追加。遷移かつ
    `startErrorKind === "missing_document"` のとき `errorMessage`/`showPurchaseLink`/`startErrorKind`
    をクリアする。前回 `hasDocument` は非リアクティブなローカル変数で保持。
  - **`currentJob` / `status` / `sessionExpiredMessage` は触らない**。
  - 実装コメントで「transient start-error overlay (破棄対象)」と「server-truth 由来
    (`currentJob`/`failedJob`)・poll 系 (`sessionExpiredMessage`) (非破棄)」の区別を明記する。
- `SourceDocumentUpload.svelte` は既に Inertia `back()` で props を更新させているため **変更不要**。
- サーバ (`show()` / `SourceDocumentController`) は既に正しい fresh props を返すため **変更不要**
  (backend 変更なし)。

## 制約・前提

- **frontend のみ**の修正 (Svelte 5 runes + Inertia)。PHP/DTO/JsonResource の変更なし。
- 既存の意図的設計 (`state_referenced_locally` による seed-once + XHR 駆動ポーリング) を壊さない。
  ポーリングは props を変えないので、prop 変化契機の overlay 破棄はポーリングと干渉しない。
- **エラー時にボタンを disabled にしない原則 (DESIGN.md / 禁止事項#8) を維持**する
  (本変更は overlay の破棄のみで、ボタンの disabled 制御には触れない)。
- `errorMessage`/`showPurchaseLink`/`sessionExpiredMessage` の型は現状維持
  (`string | null` / `boolean`)。`currentJob`/`status` を props 型から逸脱させない。
- `failedJob` alert (`data-testid="analysis-error"`) は `currentJob` = `job` prop 由来の
  **サーバ真実**を表示するものであり「stale client state」ではない。再同期後も
  サーバが最新 job として failed を返す限り表示され続けるのが正しい (本 finding の対象外)。
- DESIGN.md / Atomic Design: 既存 atom (`Alert`) の利用のみ。新規 hex/SVG/コンポーネント追加なし。

## スコープ外

- `failedJob` (実際に走って失敗した解析ジョブ) の alert 表示ロジック自体の変更。
  これはサーバ props 由来で正当。
- Edit ページ (シナリオ保存) 側の挙動 (ページ遷移で再マウントされ stale は起きない)。
- flash/toast (`back()->with('success')` → toast) の挙動変更。本 finding とは独立。
- backend (Controller/Service/DTO/JsonResource) の変更。
