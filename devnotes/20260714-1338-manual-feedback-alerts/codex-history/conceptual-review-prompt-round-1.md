# アプリの使命・禁止事項・思考原則（AGENTS.md 正本より）

## 使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## 思考原則 — 全議論に適用
まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

## ツール使用制限
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: 概念設計レビュアー

あなたは Web アプリケーション（Laravel 12 + Svelte 5 + Inertia.js）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResource パターンに沿っているか。PHPStan level 10 を通せるか

【本件の特殊事情 — 必ず考慮すること】
- 本設計は bug-hunt の 2 findings (F-1-1 / F-1-2) が入力だが、Claude が実コードを読んだ結果、
  brief の前提を一部修正している (F-1-1 の success toast は既に存在し test で緑。真因は 4s 自動消去 +
  その場に残る確認の欠如)。この「データに真摯に向き合った」再定義が妥当かを重点評価してほしい。
- `scenario.update` は 409 楽観ロック契約のため XHR (JsonResource) であり、backend flash 追加は
  契約破壊になる、という判断の妥当性。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

# user: 概念設計

（以下、`devnotes/20260714-1338-manual-feedback-alerts/conceptual-design.md` の内容。
関連コードは同リポジトリの以下を必要に応じて読んでよい:
`resources/js/components/features/manual/ScenarioEditor.svelte`,
`resources/js/components/features/manual/RenderPanel.svelte`,
`resources/js/components/atoms/Alert.svelte`,
`resources/js/lib/stores/toast.ts`,
`resources/js/components/templates/AppLayout.svelte`,
`app/Http/Controllers/Projects/ManualScenarioController.php`,
`tests/js/components/features/manual/ScenarioEditor.test.ts`,
`tests/js/components/features/manual/RenderPanel.test.ts`）

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
- `justSaved` は `applySaved()` で true、以後 dirty へ転じた瞬間に false (level-triggered)。
  これで「消えるのが速いトースト」に依存せず、ユーザーが見ている**まさにその場所**に帰属の明確な
  確認が残る。
- backend flash は**追加しない**。`scenario.update` は 409 JSON 契約の XHR endpoint であり、
  `redirect()->with()` に変えると Inertia/XHR の契約が壊れる。フロントの toast + インライン確認が正道。

### F-1-2: 各 alert を発生源に帰属させる
- 共有 `errorMessage` を発生源タグ付き state に置換: `startError: { source: "render" | "preview";
  message: string; showPurchaseLink: boolean } | null`。
  - source=render の起動エラーは「完成動画」欄に、source=preview の起動エラーは preview 小節に表示。
    → preview 起動失敗の誤帰属 (a) を解消。
- 全 danger `Alert` に発生源見出し (`title` prop) を付与:
  - 「完成動画」系 (`render-error` / render 起動エラー) → `title="完成動画"`
  - 「プレビュー」系 (`preview-error` / preview 起動エラー) → `title="プレビュー"`
  - Alert atom は既に `title` を状態色見出しとして描画する (追加実装不要)。
- 結果: 1 小節あたり赤 alert は最大 1、各 alert に発生源ラベルが付き、preview 失敗 + render 失敗が
  同時に出ても帰属が一義になる (b 解消)。

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
  `errorMessage`/`showPurchaseLink` を `startError` (source タグ付き) へ再構成、preview 起動エラーを
  preview 小節へ移動、全 danger Alert に `title` 付与。
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

