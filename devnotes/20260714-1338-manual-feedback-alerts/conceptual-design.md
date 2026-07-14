# 概念設計: manual-feedback-alerts

対象: bug-hunt (real-llm run 20260714-093524) F-1-1 (Medium) / F-1-2 (Medium)。
いずれも S3 manual 画面のフィードバック一貫性。frontend 主体・incremental。

## 背景・課題

### 事実確認 (コード実地調査の結果 — brief の前提を一部修正)

brief は「scenario 保存に成功トーストが**無い**ので追加せよ」「render/preview の複数 alert に
帰属が無い」とするが、実コードを読むと状況はより具体的だった。設計はこの実態に忠実に組む。

**F-1-1 (scenario 保存の成功フィードバック)**
- `ScenarioEditor.svelte` の `applySaved()` は **既に** `addToast("success", "シナリオを保存しました")`
  を呼んでいる (T002 = 826a8a7, 2026-07-10 から存在)。`AppLayout` は `ToastContainer` を mount 済みで、
  unit test `tests/js/components/features/manual/ScenarioEditor.test.ts:249` が「保存成功で success toast が
  積まれる」ことを **緑で保証している**。→ 「トーストが無い」わけではない。
- `scenario.update` は 409 楽観ロック契約のため **同一オリジン XHR (JsonResource 応答)** であり、
  Inertia ナビゲーションを伴わない (`ManualScenarioController::update` は `ScenarioResource` を返す)。
  一方 `manuals.store` は `redirect()->with('success', ...)` で **別ページへ遷移し、着地した新ページで
  flash→toast が新鮮に出る**。
- 真の課題: success toast は `toast.ts` の `AUTO_DISMISS_MS.success = 4000ms` で**自動消去**される。
  scenario 保存は画面遷移が無くその場更新なので、保存後にユーザー (や low-and-slow な実 LLM エージェント) が
  結果を確認するまでの間に 4 秒が経過し、トーストが消えていることがある。残るのは「未保存の変更があります」
  インジケータが**黙って消えるだけ**で、`claimed_success_no_change` (H7) と知覚される。
  bug-hunt の証跡 (S3-scenario-save-flash-top.png = 保存直後にメッセージ無し) はこの
  「消えるのが速い + その場に残る確認が無い」非対称そのもの。

**F-1-2 (完成動画/プレビューの失敗 alert 帰属)**
- `RenderPanel.svelte` には danger `Alert` が **3 か所**ある:
  - `render-start-error` … `errorMessage` state (起動 POST の 402/409/422)
  - `render-error` … `failedRenderJob.error` (render **ジョブ**失敗)
  - `preview-error` … `failedPreviewJob.error` (preview **ジョブ**失敗)
- 帰属バグ (a): `errorMessage` は `start("render")` と `start("preview")` の**両方で共有**される単一 state。
  レンダリング位置は「完成動画」見出し直下の `render-start-error` 1 か所のみ。したがって
  **preview 起動失敗 (例: 402 チケット不足) が「完成動画」欄に誤帰属して表示**される。
- 帰属バグ (b): どの Alert にも発生源ラベル (見出し) が無い。brief のシナリオ
  「preview ジョブ失敗 (`preview-error`, 下部) + render 起動 422『採用テイク未設定』(`render-start-error`, 上部)」
  では、赤い Alert が 2 つ、本文サーバメッセージだけで並び、どちらがどの操作かが判別不能。
- T032 (stale-alert followup, devnotes/20260714-0159) は **backend で stale な失敗 job を props から null 化**
  (`displayRenderJob`/`displayPreviewJob`) して「解析やり直し後に古い失敗 alert が残る」を解決済み。
  F-1-2 は「**同時提示時の発生源帰属**」という未整理の別変種であり、render 層 (frontend) の責務。

## 改善アイデア

### F-1-1: その場に残る保存確認インジケータを追加 (toast は維持)
- 既存の success toast は残す (瞬間的な能動通知として有効)。加えて、操作点である「シナリオを更新」
  ボタン横に、**dirty インジケータの鏡像**として**永続的な**「保存しました」インジケータを出す。
  - `dirty` のとき → 「未保存の変更があります」(既存)
  - `!dirty && justSaved` のとき → 「保存しました」(新規。次の編集で dirty に転じるまで残る)
- `justSaved` の状態遷移 (Codex R2: reseed は 409 リロードでも走るため「reseed で true」は競合後に
  偽の成功表示を出す。true にする契機は保存成功のみに固定する):
  - **true にするのは `applySaved()` (保存成功パス) のみ**。
  - **false にする**: `save()` 開始 / 保存失敗 (`saveFailure` set) / dirty へ転じた瞬間 (編集) /
    初期化 / `reseed()` (理由を問わず = 409 競合後・明示リロード後も含む)。
  - 実装上は `reseed()` が常に `justSaved = false` を行い、`applySaved()` が `reseed()` を呼んだ**後**に
    `justSaved = true` を立てる。これで保存成功のみ true、409/明示リロード reseed は false になる。
  これで「古い成功表示が残って誤認を生む」ことを防ぎつつ、消えるのが速いトーストに依存せず、
  ユーザーが見ている**まさにその場所**に帰属の明確な確認が残る。
- backend flash は**追加しない**。`scenario.update` は 409 JSON 契約の XHR endpoint であり、
  `redirect()->with()` に変えると Inertia/XHR の契約が壊れる。フロントの toast + インライン確認が正道。

### F-1-2: 各 alert を発生源 (source) + 局面 (phase) に帰属させる
- 共有 `errorMessage`/`showPurchaseLink` を **source 別の 2 state** に分離
  (Codex R1: 単一タグ付き state だと render/preview の起動失敗を同時保持できず後発が先発を上書きする):
  - `renderStartError: StartError | null` / `previewStartError: StartError | null`
  - `type StartError = { message: string; showPurchaseLink: boolean }` (component 内定義)
  - render 起動エラーは「完成動画」小節に、preview 起動エラーは preview 小節に表示。
    → preview 起動失敗の render 側誤帰属 (a) と、購入導線の誤表示を同時に解消。
- 全 danger `Alert` に **phase-aware な見出し** (`title` prop) を付与
  (Codex R1: source だけだと同一小節で「起動失敗」と「ジョブ失敗」が同じ見出しの赤 alert 2 つになる):
  - 完成動画 — 起動失敗 → `title="完成動画の生成を開始できませんでした"`
  - 完成動画 — ジョブ失敗 (`render-error`) → `title="完成動画の生成に失敗しました"`
  - プレビュー — 起動失敗 → `title="プレビューの生成を開始できませんでした"`
  - プレビュー — ジョブ失敗 (`preview-error`) → `title="プレビューの生成に失敗しました"`
  - Alert atom は既に `title` を状態色見出しとして描画する (追加実装不要)。本文は従来どおりサーバ
    メッセージ/ジョブ error を出す。
- 結果: 各 alert が **source + phase の見出し**を持つため、preview 失敗と render 失敗が同時に出ても、
  さらに同一 source 内で起動失敗とジョブ失敗が併存しても、どの操作のどの局面かが一義に読める (b 解消)。

## 期待効果
- 使命への貢献: 「思考ゼロ・編集ゼロ」で現場作業者が迷わずマニュアルを完成させる導線の信頼性向上。
  保存の成否/失敗の発生源が常に自明になり、二度手間・不安・操作詰まりを排除する。
- F-1-1: 保存成功が「消えないその場の確認」で常に伝わり、`claimed_success_no_change` を解消。
- F-1-2: 同時多発失敗でも「どの操作が失敗したか」が見出しで一義になり、`ux_dead_end` を解消。
- 既存修正 (T026 トースト機構 / T032 stale 抑制) と整合し、UX 一貫性を面で仕上げる。

## 実装方針（概要）
- F-1-1: `resources/js/components/features/manual/ScenarioEditor.svelte` のみ。
  `justSaved` state 追加、`applySaved` で set、dirty 転換で reset、ボタン横に永続インジケータ 1 つ追加。
- F-1-2: `resources/js/components/features/manual/RenderPanel.svelte` のみ。
  `errorMessage`/`showPurchaseLink` を source 別 2 state (`renderStartError`/`previewStartError`) へ
  再構成、preview 起動エラーを preview 小節へ移動、全 danger Alert に phase-aware `title` 付与。
- backend / DTO / 型定義 (Props) の変更なし (両 state はコンポーネント内部状態)。

## 制約・前提
- Svelte 5 runes / DS token のみ (DESIGN.md)。Alert/Toast は既存 atom/organism を流用 (新規部品なし)。
- 既存 testId (`render-start-error` / `preview-error` / `render-error` / `scenario-dirty-indicator`
  / `scenario-submit`) は維持し、既存 vitest を壊さない。preview 起動エラー用に新 testId を 1 つ足す。
- `scenario.update` の 409/XHR 契約は不変 (backend 非変更)。
- toast の success=4s 自動消去ポリシーは変えない (グローバル影響回避)。インライン確認で補完する。

## スコープ外
- backend flash 追加 / `scenario.update` の Inertia 化 (契約を壊すため不可)。
- toast の TTL/表示位置などグローバル toast 挙動の変更。
- capture 画面や他パネルのフィードバック整理 (今回の 2 finding に限定)。
- ffmpeg 欠如や S3 fake 配線など環境ギャップ (別 TODO で対応済/対象外)。
