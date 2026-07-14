# 対応マトリクス: conceptual-review Round 1

## [Critical] 焼込一貫性のためのレイアウト契約が未定義（観点1）
- 判断: 対応する
- 根拠: 「最終焼込と同じ構図判断」を価値の中心に据える以上、位置だけでなく最大幅・折返し・inset・行間まで定義しないと目的を果たせない。妥当。
- 対応内容: 概念設計に「表示レイアウト契約」節を追加。max-width（`max-w-[90%]` 中央）・`text-center`・複数行は `whitespace-pre-line` で折返し・primary は上部 inset / secondary は下部 inset・primary/secondary の行間・スクリム帯は行ボックス単位で描画（空行に帯を出さない）を明文化。ASS との許容差（ライブは端末アスペクト差があるため「占有領域の目安」であり厳密ピクセル一致は保証しないと明記）。

## [Critical] 長文/空白のみ/小画面/safe-area 未定義のリスク（観点5）
- 判断: 対応する（safe-area は scope 整理で対応）
- 根拠: 空白のみで黒帯だけ出る・被写体を大きく隠す・長文字幕の後退は実害。trim 空判定・折返し・長文 JP テストは必須。
- 対応内容: 空判定は `trim()` 済みで行う（空白のみ→空扱い）。プレビューはカード内 `aspect-video`（フルスクリーンでない）ため端末 safe-area（ホームインジケータ）は当たらない → safe-area 対応はフルスクリーン横持ち UI（スコープ外）に帰属すると明記し、カード内は padding inset で担保。長文 JP・複数行・空白のみを vitest ケースに追加。被写体隠しを避けるため overlay は帯のみ半透過（`bg-text/70`）で映像を完全に覆わない。

## [Warning] 空字幕でトグルを disabled にしない（観点2 / 禁止事項8）
- 判断: 対応する
- 根拠: 禁止事項8（未充足を理由に disabled 禁止）と整合させる。
- 対応内容: 「字幕が空でもトグルは disabled にしない。ON でも中身が無ければ何も描画しないだけ」と明記。

## [Warning] DS token / Lucide アイコン名の存在確認（観点3）
- 判断: 対応する（確認済み）
- 根拠: 実装成立条件。既に確認した。
- 対応内容: `@lucide/svelte` に `captions` / `captions-off` が存在することを確認済み（node_modules 実在）。`bg-text`（`--color-text`）・`text-surface`（`--color-surface`）は tokens.css に実在、`bg-text/50` は DESIGN.md Modal overlay で使用実績あり → `/70` も opacity modifier で有効。概念設計に「確認済み」と追記。

## [Warning] 効果表現の言い切りを弱める（観点4）
- 判断: 対応する
- 根拠: 因果の断定は設計文書として不適切。
- 対応内容: 「再撮影の手戻りが減る」→「字幕とかぶらない構図判断を支援する（期待効果）」に緩和。

## [Warning] icon-only トグルに状態連動 aria-label（観点5）
- 判断: 対応する
- 対応内容: `aria-label="字幕を表示"` / `"字幕を非表示"` を状態連動で必須化。`aria-pressed` と併用。

## [Warning] 「doc/05 §5.2 を satisfy」は広すぎる（観点6）
- 判断: 対応する
- 対応内容: 「doc/05 §5.2 のうち**字幕重畳要件**を満たす」に表現を狭める。

## [Warning] 「3 ファイル変更」の見積もり粒度（観点6）
- 判断: 対応する
- 対応内容: 「実装 3 ファイル + テスト新規/追記」と表記。

## [Warning] props 手再定義による CaptureCut との型ドリフト（観点7）
- 判断: 対応する
- 根拠: 将来の Inertia props 変更で静かにズレるのを防ぐ。
- 対応内容: `CameraRecorder` / `SubtitleOverlay` の字幕 props 型を `CaptureCut["subtitle_primary"]` / `CaptureCut["subtitle_secondary"]` の indexed access で束ね、手書き `string | null` の独立定義を避ける。正規化（trim 空判定）は `SubtitleOverlay` の 1 箇所に寄せる。

## [Suggestion] 受け入れ条件追加 / test-first / 観測指標
- 判断: 一部採用
- 対応内容: 成功判定に「撮影者が字幕占有領域を事前認識できる（overlay が primary=上・secondary=下に表示される）」を追記。test-first は詳細設計のテスト計画で明記。観測指標（再撮影率等）は v1 計測基盤スコープ外として言及に留める。

## 既定 ON の解釈について
- 判断: 既定 ON を維持（論点として明示）
- 根拠: v1 中核価値が「字幕」であり、本機能の目的は撮影者が字幕占有領域を構図に織り込むこと。既定 OFF だと撮影者がタップを失念し価値が届かない。doc/05 の「（再タップで非表示）」はトグルの往復挙動の記述であり初期状態を OFF に限定するものではないと解釈。トグルで OFF にできるため spec の操作要件は満たす。この判断を概念設計に明記した。
