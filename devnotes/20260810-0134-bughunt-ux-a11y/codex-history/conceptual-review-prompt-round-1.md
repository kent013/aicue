# アプリの使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

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
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: bughunt-ux-a11y (bug-hunt run 20260809-152048 のアプリ側 finding 修正)

## 背景・課題

2026-08-09 のフルサイズ bug-hunt (run `20260809-152048`, 4 shard 並列) で、アプリ側に 3 件の finding が出た。
Critical/High は 0 件で、いずれも「機能は動くがユーザーが目的に到達しにくい」種類の欠落である。

### F-1-03 (Medium / H13 レスポンシブ) — 撮影 PWA モバイル幅でカット選択後に撮影パネルへ到達できない

`resources/js/pages/Capture/Show.svelte` L178 のレイアウトは
`grid grid-cols-1 gap-4 lg:grid-cols-2` で、**lg 未満では左ペイン (CutNavigator = シナリオ一覧) の下に
右ペイン (ナレーション + CameraRecorder + TakeStrip) が縦積みされる**。
カット行をタップしても `selectedCutId` が変わるだけでスクロール位置は動かないため
(`window.scrollY` は 0 のまま)、ユーザーは毎回 14 件以上のカット一覧を手動でスクロールして
撮影パネルまで降りる必要がある。

これは AI-CUE の North Star そのもの ——「スマホ(PWA)でナビゲーション撮影する」「思考ゼロ・編集ゼロ」——
の中核体験で、**カットを切り替えるたびに毎回発生する**。現場で片手にスマホを持ち、
カット 1 → 撮影 → カット 2 → 撮影 … と進む主要ワークフローの摩擦であり、
「AI が撮影を指示する」はずのナビが、実際にはユーザーに「撮影 UI を探させて」いる。

### F-1-02 (Low / H14 a11y) — 完成動画・プレビューの `<video>` にアクセシブルネームが無い

`RenderPanel.svelte` L370 (`data-testid="preview-video"`) と
`TakePreviewDialog.svelte` L78 (`data-testid="take-preview-video"`) の `<video>` は
`aria-label` を持たず、アクセシビリティツリーに名前付きで現れない
(実測: `document.querySelector('video').getAttribute('aria-label')` → `null`)。
視覚的には見出し「完成動画」の直下にあるので文脈から分かるが、支援技術利用者には
「動画が生成された / 再生できる」ことが伝わらない。

### F-2-01 (Low / H14 a11y) — オンボーディングのプラン事前選択が視覚のみで ARIA に出ない

`/onboarding/checkout?plan=starter` で該当プランカードが青枠 (`border-primary`) で
ハイライトされるが、DOM には `aria-current` / `aria-selected` / `aria-pressed` のいずれも無い。
`PricingPlanCard.svelte` の `isHighlighted` prop は `borderClass` (視覚) にしか効かない。
`/pricing` の「このプランで始める」から `?plan=` 付きで誘導されたスクリーンリーダー利用者は、
**意図したプランが事前選択されているか確認できないまま契約に進む**ことになる。

## 改善アイデア

### 施策 A: カット選択時に撮影パネルへ視点を運ぶ (F-1-03)

「ナビが撮影を指示する」という機能の名前に立ち返る。カットを選んだ瞬間に
**ユーザーが次にやること (撮る) が画面に入っている**のが正しい状態である。

- **1 カラム表示のときだけ**、カット選択時に右ペイン (撮影パネル) を `scrollIntoView` する。
- 2 カラム (lg 以上) では左右が同時に見えているのでスクロールしない
  (デスクトップで勝手に画面が動くのは退行になる)。
- `prefers-reduced-motion` を尊重し、`behavior` を切り替える。
- **戻る導線を必ず用意する**: 撮影パネル先頭に「カット一覧へ戻る」を置く。
  スクロールで運んだ以上、帰り道が無ければ別の詰みを作る (H2 を自分で作らない)。

**採らなかった案**: モバイルをタブ/ドロワー切替 (一覧⇔撮影) にする。
表示モデルを 2 つに分岐させると CameraRecorder のライフサイクル (getUserMedia / 録画中の
アンマウント) に条件分岐が波及し、「今必要なものだけ作る」を超える。
まず摩擦の主因である「視点が運ばれない」を解消し、効果を見てから検討する。

### 施策 B: 動画要素にアクセシブルネームを与える (F-1-02)

`<video>` に `aria-label` を付ける。文言は「何の動画か」が分かるものにする
(完成動画/プレビュー、テイクは手順名を含める)。既存の
`<!-- svelte-ignore a11y_media_has_caption -->` (字幕焼き込み済みのため) は維持する
—— これは caption trackの話で、アクセシブルネームとは別軸である。

### 施策 C: プランカードの選択状態を ARIA に出す (F-2-01)

**`isHighlighted` にそのまま aria を生やさない。** このカードは 3 箇所で使われ、
`isHighlighted` の意味が異なる:

| 呼び出し元 | isHighlighted の意味 | 適切な ARIA |
|---|---|---|
| `Onboarding/Checkout.svelte` | これから選ぼうとしている**事前選択** | `aria-current="true"` |
| `Billing/_helpers/PlanCard.svelte` | **現在契約中**のプラン | `aria-current="true"` |
| `Guest/Pricing.svelte` | (推し枠などの強調) | ARIA を出すべきでない |

「別物の概念を似ているから統合しない」に従い、**視覚強調 (`isHighlighted`) と
状態表明 (`currentLabel`) を別 prop に分ける**。新 prop は既定値を持ち、
指定しない既存呼び出し元の出力は不変とする (後方互換の並走ではなく、既定値の設計)。

## 期待効果

- **使命への貢献 (施策 A)**: 「思考ゼロ・編集ゼロ」で現場作業者が撮影できる、という North Star の
  中核導線から、カットごとに発生していた手動スクロールを取り除く。撮影カット数に比例して効く。
- **a11y (施策 B/C)**: 支援技術利用者が「動画ができた」「このプランが選ばれている」を
  認識できるようになる。契約という不可逆操作の前段で状態が伝わらない F-2-01 は特に重要。
- **回帰の固定**: 3 件とも Browser テストで固定でき、次回以降の bug-hunt で再燃しない。

## 実装方針（概要）

| # | 施策 | 変更コンポーネント |
|---|---|---|
| A | カット選択時の撮影パネルへのスクロール + 戻る導線 | `resources/js/pages/Capture/Show.svelte` |
| B | video のアクセシブルネーム | `resources/js/components/features/manual/RenderPanel.svelte`, `resources/js/components/features/capture/TakePreviewDialog.svelte` |
| C | プラン選択状態の ARIA | `resources/js/components/molecules/PricingPlanCard.svelte` (新 prop) + `resources/js/pages/Onboarding/Checkout.svelte`, `resources/js/pages/Billing/_helpers/PlanCard.svelte` (呼び出し側) |

すべて **frontend (Svelte 5 runes + TypeScript) のみ**。PHP 側 (Controller / DTO / route /
migration) の変更は無く、Inertia Props の形も変えない。

テストは Browser レーン (pest-plugin-browser、Chromium + WebKit の 2 レーン契約) と
vitest のどちらで固定するかを詳細設計で確定する。**a11y 属性の存在は DOM 検査で足りるので
軽い方を選ぶ**が、施策 A は viewport 依存 (1 カラム/2 カラム) なので Browser レーンが要る。

## 制約・前提

- **DESIGN.md 準拠**: 新しい色・角丸・タイポグラフィは足さない。施策 A の「戻る」は既存の
  `TextLink` / `Button` atom を使う。hex 直書きを増やさない。
- **Atomic Design の単方向 import**: `PricingPlanCard` は molecule。pages から molecule への
  import は現状どおりで階層を逆流しない。新規 component は作らない見込み。
- **禁止事項 8 (必須条件未充足を理由に disabled)**: 本設計は disabled を増やさない。
- **`svelte-ignore a11y_media_has_caption` は維持**する (字幕は焼き込み済みという既存の判断を覆さない)。
- 施策 C は `PricingPlanCard` の**既存 3 呼び出し元すべて**を確認済み。新 prop 未指定時の
  レンダリング結果は現状と同一でなければならない。

## スコープ外

- **bug-hunt の要確認 Q1〜Q4** (プレビューの採用テイク 0 件許容 / オーナー移譲の
  「パスワード再確認」文言 / `config/fortify.php` の doc drift / T042 の検証条件不足)。
  いずれも仕様確定が先で、本設計では触らない。
- **bug-hunt 基盤の不具合 4 件** (teardown の zombie 誤判定 / `bug_hunt_5` ループ /
  `optimize:clear` が dev DB を触る / `setup-worktree` の env コピー欠落)。
  別テーマとして独立に設計する (対象レイヤも検証手段も違うため混ぜない)。
- モバイルの表示モデルそのものの再設計 (タブ/ドロワー化)。施策 A の効果を見てから。
- `/pricing` (`Guest/Pricing.svelte`) の強調枠に ARIA を足すこと。
  そこは「選択状態」ではないので `aria-current` は誤りになる。

