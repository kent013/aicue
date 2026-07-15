# アプリの使命（AGENTS.md North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項（AGENTS.md）

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う）
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示する。DESIGN.md）

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。

先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ（Laravel/Svelte エコシステムの既存解）。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。

仕組みが機能していない段階で値を弄るな。方向性が間違っているなら設計そのものを見直せ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは Web アプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js、Tailwind）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか（特に tablet 768 非退行）
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResource パターンに沿っているか（本件はフロント表示のみだが波及の見落としが無いか）

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（以下は devnotes/20260715-1158-capture-take-row-mobile/conceptual-design.md の内容）

# 概念設計: capture-take-row-mobile（撮影テイク行の mobile 375px レイアウト崩れ修正）

## 背景・課題

bug-hunt run 20260715-084108 の F-1-05 (Medium, H11/H13)。

`resources/js/components/features/capture/TakeStrip.svelte` のテイク行が、mobile 375px 幅で
レイアウト崩れを起こす。

- 症状: テイク行のラベル（「テイク N」＋「採用中」「DL 済み」バッジ）が横方向に収まらず、
  再生ボタン（T050 で追加したインラインプレビュー Play アイコン）などの操作ボタン群と重なる。
  ラベルが縦に折り返して見える。
- tablet 768 では正常。
- 原因候補: T050（インラインプレビュー再生ボタン追加）/ T056（撮影 UX 拡充）で
  テイク行内の要素数が増えた影響。テイク行は 1 行 flex（chevron 列 / ラベル・メタ列 /
  操作ボタン列）で、操作ボタン列に Play・採用（テキスト付）・DL・コメント・削除の 5 ボタンが
  shrink-0 で並ぶ。375px では操作ボタン列が幅を占有し、min-w-0 flex-1 のラベル列が
  極端に狭くなる。ラベル内のバッジ行 `<p class="flex items-center gap-2">` は
  wrap も min-w-0 も無いため、狭い親の幅を無視して右方向へはみ出し、操作ボタンと重なる。

### 現行構造（TakeStrip.svelte L190-302 の要点）

行コンテナ `flex items-center gap-2`（nowrap）/ chevron 列 / ラベル列 `min-w-0 flex-1`
（内にバッジ行 `<p class="flex items-center gap-2 text-body">`）/ 操作ボタン列
`flex shrink-0 items-center gap-1`（Play/採用/DL/コメント/削除）。
バッジ行 `<p>` が flex nowrap かつ min-w-0 無し → 親幅を無視してはみ出し、操作ボタンと重なる（直接原因）。

## 改善アイデア

テイク行の flex レイアウトを 375px でも要素が重ならないよう見直す。方向性 2 点:
1. 行を mobile で 2 段に分ける（レスポンシブ wrap）。操作ボタン列を mobile ではラベル行の下へ
   folリ返して full-width 右寄せ、tablet 以上（sm: = 640px 以上、768 含む）では従来どおり 1 行。
2. ラベル内バッジ行を wrap・縮小可能にする（flex-wrap + min-w-0）。

字幕トグル（T050）は TakePreviewDialog 側でテイク行内に無い。録画/撮影 UX ボタン（T056）は
CameraRecorder 側で別コンポーネント。TakeStrip 内の操作ボタン共存のみ扱う。

## 期待効果

- 使命への貢献: 撮影 PWA はスマホ（375px 級）が主戦場。最小幅で崩れると採用操作が誤タップ・詰みに繋がる。
- 具体: 375px でラベル/バッジと操作ボタンが重ならず、タップ領域確保。tablet 以上は現状維持。

## 実装方針（概要）

TakeStrip.svelte の各テイク行のみ変更（CSS/クラス調整が主）:
1. 行: `flex flex-wrap items-center gap-x-2 gap-y-2 sm:flex-nowrap`
2. 操作ボタン列: `flex w-full shrink-0 items-center justify-end gap-1 sm:w-auto sm:justify-start`
3. ラベル列: `min-w-0 flex-1`（現状維持）
4. バッジ行 `<p>`: `flex flex-wrap items-center gap-x-2 gap-y-1 min-w-0`
5. 操作ボタン列/バッジ行に data-testid（`take-actions-${id}` / `take-label-${id}`）付与（挙動不変の hook）。

DS token / Atomic Design 準拠: レイアウトユーティリティのみ。hex 直書き・新規 SVG・新規 atom 無し。

## 制約・前提

- DESIGN.md 準拠（disabled 禁止原則維持、token 参照方針維持）。
- Atomic Design 準拠（atom 責務不変、features 層のレイアウト調整に閉じる）。
- 非退行: tablet 768（sm: 以上）は従来 1 行維持。
- Svelte props・XHR ロジックは変更しない（クラスと testid のみ）。

## スコープ外

TakePreviewDialog / CameraRecorder のレイアウト、ボタン数・機能変更、F-1-04 等他指摘、サーバ側/API/DTO。
</content>
