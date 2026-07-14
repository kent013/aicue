# 概念設計レビュー Round 2

Round 1 の指摘に対する対応です。全指摘への対応マトリクスと、それを反映した概念設計の該当箇所を示します。再判定（APPROVED / CHANGES_REQUESTED）をお願いします。

## 対応マトリクス（要約）

- **[Critical] レイアウト契約未定義**: 対応。概念設計に「表示レイアウト契約」節を新設 → 位置（primary 上部 inset / secondary 下部 inset）・`max-w-[90%] mx-auto text-center`・`whitespace-pre-line` 折返し（truncate しない）・帯単位スクリム `bg-text/70`（空行に帯を出さない）・行 inset（`px-3`/`py-2`）を明文化。ASS との許容差（ライブは端末アスペクト差があるため「占有領域の目安」でありピクセル一致は保証しない）も明記。
- **[Critical] 長文/空白のみ/小画面/safe-area リスク**: 対応。空判定は `trim()` 後で行い空白のみも空扱い。折返しは `whitespace-pre-line`。safe-area は「プレビューはカード内 `aspect-video`（非フルスクリーン）ゆえ端末 safe-area は当たらない。フルスクリーン横持ち UI（スコープ外）に safe-area 対応は帰属」と整理。長文 JP・改行・空白のみを vitest ケースに追加。被写体を覆わない（上下帯のみ半透過）。
- **[Warning] 空でトグル disabled にしない（禁止8）**: 対応。「空でも disabled にしない。ON でも中身無ければ描画しないだけ」を明記。
- **[Warning] DS token / Lucide アイコン存在確認**: 対応（確認済み）。`@lucide/svelte` に `captions`/`captions-off` 実在（node_modules 確認）。`bg-text`(`--color-text`)/`text-surface`(`--color-surface`) は tokens.css 実在、`bg-text/50` は DESIGN.md Modal overlay で使用実績あり（`/70` も opacity modifier で有効）。
- **[Warning] 効果の言い切り緩和**: 対応。「再撮影の手戻りが減る」→「字幕とかぶらない構図判断を支援する（期待効果）」。定量効果は v1 計測基盤スコープ外と明記。
- **[Warning] icon-only に状態連動 aria-label**: 対応。ON「字幕を非表示」/ OFF「字幕を表示」を必須化（`aria-pressed` 併用）。
- **[Warning] doc/05 §5.2 satisfy 表現が広い**: 対応。「§5.2 のうち字幕重畳要件を満たす」に狭めた。
- **[Warning] 「3 ファイル」の粒度**: 対応。「実装 3 ファイル + テスト新規/追記」に修正。
- **[Warning] props 型ドリフト**: 対応。字幕 props 型を `CaptureCut["subtitle_primary"]` / `CaptureCut["subtitle_secondary"]` の indexed access で束ね、trim 正規化を `SubtitleOverlay` 1 箇所に集約。
- **[Suggestion] 受け入れ条件 / test-first / 観測指標**: 一部採用。成功判定に「撮影者が字幕占有領域を事前認識できる」を追加。test-first をテスト計画に明記。観測指標は v1 スコープ外として言及に留める。
- **既定 ON**: 維持。v1 中核価値=字幕・本機能の目的（撮影者が字幕位置を構図に織り込む）から既定 ON。doc/05 の「再タップで非表示」はトグルの往復挙動でありトグルで OFF にできるため spec 操作要件は満たす、と整理。

## 反映後の概念設計（該当節）

### 改善アイデア（抜粋）
- ON/OFF はトグル（`Captions`/`CaptionsOff`、実在確認済み）、`aria-pressed` + 状態連動 `aria-label` 必須。既定 ON。空でも disabled にしない（禁止8）。
- 両方空（trim 後）なら overlay 非描画（空白のみも空扱い）。

### 表示レイアウト契約（新設）
- 位置: primary→上部中央 inset、secondary→下部中央 inset（ASS Alignment 8/2 対応）。
- 配置: overlay は `absolute inset-0 pointer-events-none`。帯は端 inset（`px-3`+`py-2`）、`text-center`。
- 最大幅・折返し: 各帯 `max-w-[90%] mx-auto`、本文 `whitespace-pre-line`（truncate しない）。
- スクリム: 帯単位に `bg-text/70`（空行に帯を出さない）、text `text-surface`、ramp `text-body`。
- 被写体非隠蔽: 上下帯のみ・映像中央を覆わない。
- safe-area: カード内 `aspect-video` は端末 safe-area 非該当。フルスクリーン UI はスコープ外。
- ASS 許容差: 占有領域の目安でありピクセル一致は非保証。

### 実装方針（抜粋）
- 実装 3 ファイル（`SubtitleOverlay.svelte` 新規 / `CameraRecorder.svelte` 改修 / `Capture/Show.svelte` 配線）+ テスト新規/追記。
- props 型は `CaptureCut` の indexed access。trim 空判定は `SubtitleOverlay` 1 箇所。
- テスト: visible/両空/空白のみ/primary のみ/secondary のみ/長文 JP・改行折返し/上下位置、CameraRecorder のトグルで表示切替。test-first。

以上を踏まえ、再判定をお願いします。残 Critical/Warning があれば具体的な修正案付きで指摘してください。
