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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
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

# 概念設計: drag-and-drop-reordering (シナリオ行とテイクのドラッグ&ドロップ並べ替え)

## 背景・課題

要件側の記述と実装が乖離している。

- `doc/04_PCサイト機能仕様.md` L40: 「**手順操作**: 「＋」「－」で行の追加・削除、**ドラッグ＆ドロップで並べ替え**」
- `doc/05_スマホアプリ機能仕様.md` L55: 「**並べ替え（D&D）**: ドラッグで順序変更。**一番左のテイクが全体プレビュー・採用候補**として扱われる」

現状の実装はどちらも「1 段だけ動かす矢印ボタン」である。

- `resources/js/components/features/manual/ScenarioEditor.svelte` L224-244:
  `moveStep(index, delta: -1|1)` / `movePoint(stepIndex, index, delta)` と ▲▼ ボタン。
- `resources/js/components/features/capture/TakeStrip.svelte` L108-109, L199-220:
  `move(take, position)` (PATCH) と 上へ/下へ ボタン。

AI が設計したシナリオは 10〜30 行規模になる。「手順 9 を手順 2 の前へ」を 1 段ずつ 7 回押させる
現状は、使命が掲げる「思考ゼロ・編集ゼロ」の編集体験を明確に損なっている。テイク側も同様で、
現場で 5 本撮った中から「3 本目を採用候補(先頭)に」を押し込む操作が繰り返しタップになる。

## 改善アイデア

**1 つの並べ替え機構**を `resources/js/lib/dnd/` に用意し、シナリオ編集 (PC) と撮影 PWA (スマホ) の
両方から使う。既存の矢印ボタンは**残す**(キーボード経路とスクリーンリーダ経路を消さない)。

### 中核の判断 D1: HTML5 Drag and Drop API は採らない。Pointer Events で実装する

- HTML5 DnD (`draggable="true"` + `dragstart`) は **iOS Safari のタッチ操作で発火しない**
  (mobile Safari は `draggable` を無視する)。撮影 PWA の主戦場は iOS Safari
  (`docs/supported-browsers.md` L77-80「**iOS Safari が最重要**」) なので、
  この時点で撮影 PWA 側の要件を満たせない。
- 2 画面で別機構 (PC=HTML5 DnD / PWA=Pointer Events) を採ると、同じ「並べ替え」という
  1 つの概念に 2 つの実装と 2 組のバグが生まれる (思考原則 4・6 に反する)。
- Pointer Events (`pointerdown` / `pointermove` / `pointerup` + `setPointerCapture`) は
  マウス・タッチ・ペンを 1 つのイベント系で扱え、iOS Safari / Android Chrome /
  デスクトップ主要ブラウザすべてで利用できる。
- **結論**: Pointer Events 1 本。HTML5 DnD は使わない。

### 中核の判断 D2: 新しい依存パッケージを追加しない

- 候補として `svelte-dnd-action` 等があるが、(a) 上記のとおり必要なのは
  「単一リスト・階層をまたがない・1 軸」の最小機能であり、(b) 追加は
  `docs/supply-chain/review-checklist.md` の審査対象を 1 つ増やし、
  (c) Svelte 5 runes + 既存 DS token 前提との整合を継続的に見る責務が増える。
- 必要な計算は「配列の要素を from から to へ動かす」「ポインタ Y 座標から挿入位置を決める」の
  2 つだけで、いずれも **20 行程度の純関数**である。ライブラリを引く動機に足りない。
- **結論**: 依存追加なし。もし将来どうしても必要になったら、`review-checklist.md` の
  審査観点 (メンテ状況・依存の深さ・advisory 履歴・accept-risk 運用) に沿って別途審議する。
  本設計ではその状況にならない。

### 中核の判断 D3: 「並べ替えの計算」は純関数に切り出し、そこにテストの重心を置く

D&D は DOM イベントの連鎖であり、テストで忠実に再現しづらい。そこで

- `resources/js/lib/dnd/list-reorder.ts` — **DOM に触れない純関数**
  (`moveItem` / `insertionIndexFromRects` / `toFinalIndex`)。ここを Vitest で網羅する。
- `resources/js/lib/dnd/pointer-drag.ts` — **Svelte に依存しない素の TS** の
  ポインタ制御 (capture / 閾値 / 端でのスクロール / Esc 取消)。コールバックで外へ通知する。
- 各画面のコンポーネントは「コールバックを受けて既存の並べ替え関数を呼ぶ」だけにする。

これにより、D&D の**意味的な正しさ**(どこに落ちたら何番目になるか) は純関数テストで、
**配線の正しさ**(落としたら既存の保存経路が動くか) はコンポーネントテストで検証できる。

### 中核の判断 D4: 既存の書き込み経路を 1 mm も変えない

- シナリオ側: D&D の確定は既存の `runSettled(() => commitStructural(...))` を通す。
  よって **undo/redo 履歴・dirty 判定・IME ゲート・離脱警告**に自動的に整合する。
  PUT payload (`payloadSteps()`) は `sort_order` を含まず**配列順がそのまま順序**なので、
  サーバ採番 (`parent_cut_id` / `sort_order` / `type` はサーバ導出) の契約も変わらない。
- テイク側: D&D の確定は既存の `move(take, position)` = 既存の `PATCH .../takes/{id}`
  (`position`) をそのまま呼ぶ。サーバ (`CaptureTakeService::reorderWithinCut`) は無変更。
- **サーバ側の変更は 0 件**。API 契約・DTO・JsonResource・migration すべて無変更。

### 中核の判断 D5: 掴む場所は専用のハンドルに限る

行全体をドラッグ対象にすると、シナリオ編集の行は入力欄の塊なので**テキスト選択と衝突**し、
撮影 PWA では**縦スクロールと衝突**する。よって左端に専用ハンドル (`GripVertical`) を置き、
そこにだけ `touch-action: none` を当てる。行の他の場所のタッチは従来どおりスクロールする。

### 中核の判断 D6: キーボード経路は消さず、むしろ増やす

- 既存の ▲▼ / 上へ・下へ ボタンは**そのまま残す** (要件どおり)。
- 追加するハンドルは素の `<button>` (新規 atom `DragHandle`) とし、
  focus 中の `ArrowUp` / `ArrowDown` で**既存の 1 段移動関数をそのまま呼ぶ**。
  「focus できるのに何も起きないコントロール」を作らないため。
- 並べ替えの結果は `aria-live="polite"` の領域で読み上げる
  (「手順 2 を 4 番目に移動しました」)。

## 期待効果

- **使命への貢献**: 「編集ゼロ」に近づく。AI が出したシナリオの構成順を直す作業は動画マニュアルの
  品質を左右する主要な編集操作であり、ここが 1 段ずつのボタン連打だと「編集の手間」が残る。
- **要件の充足**: doc/04 L40 と doc/05 L55 の未実装ギャップを閉じる。
- **現場での実効性**: 撮影 PWA (iOS Safari) で採用候補 (先頭) の入れ替えが 1 ジェスチャで済む。
- **回帰リスクの低さ**: サーバ変更 0、既存の保存/履歴経路の変更 0。追加は
  「新しい入力手段が既存の関数を呼ぶ」層だけ。

## 実装方針（概要）

| # | 施策 | 主な変更ファイル |
|---|------|-----------------|
| 1 | 並べ替え計算の純関数モジュール | `resources/js/lib/dnd/list-reorder.ts` (新規) |
| 2 | Pointer Events のドラッグ制御 (素の TS) | `resources/js/lib/dnd/pointer-drag.ts` (新規) |
| 3 | ドラッグハンドル atom | `resources/js/components/atoms/DragHandle.svelte` (新規) |
| 4 | シナリオ編集への配線 (手順行・急所行) | `ScenarioEditor.svelte` |
| 5 | 撮影 PWA テイク列への配線 | `TakeStrip.svelte` |
| 6 | jsdom に無い pointer capture のスタブ | `tests/js/setup.ts` |
| 7 | テスト (純関数 + 2 コンポーネント) | `tests/js/lib/dnd/*.test.ts` ほか |

- **視覚表現**は DESIGN.md に従う。影・gradient・scale は使わない
  (`ds-purity` が機械検出する)。ドラッグ中の行は `border-primary` + 不透明度を落とすだけ、
  挿入位置は行間に 2px の `bg-primary` の線を出すだけ。静的 `style=""` は書かない。
- **アイコンは `@lucide/svelte` の `GripVertical`** のみ (SVG 直書きはしない)。
- **階層規約**: `DragHandle` は atom なので import してよいのは token / util / external だけ。
  `lib/dnd/*` は util なので atom からも feature からも import できる (単方向規約に適合)。

## 制約・前提

- **タッチ端末で動くこと**が要件 (撮影 PWA の主戦場 = iOS Safari)。ハンドルに
  `touch-action: none` を当ててブラウザのスクロールジェスチャと競合させない。
  ドラッグ開始には**移動閾値** (数 px) を設け、単なるタップでドラッグを始めない。
  リストの端に近づいたら少しずつスクロールさせる (画面外の行へ落とせない詰みを作らない)。
- **iOS Safari 実機での確認は「テスト済み」と表現しない**
  (`docs/supported-browsers.md` の規約)。実施したら日時・端末・OS を devnotes に記録する。
- **jsdom は pointer capture を実装しない**。制御側で機能検出し、無ければ capture 無しで
  動く経路を持つ (テスト都合ではなく、古い環境での劣化動作としても正しい)。
- 既存の Browser lane (Chromium + WebKit) の契約は変えない。本設計では Browser テストを
  必須にしない (D&D の実挙動は実機確認の領分。Vitest で意味論を固定する)。
- PHP 側の変更が無いため PHPStan / Pest の新規追加は無い
  (既存の Feature テストは無変更で緑のままであることを確認する)。

## スコープ外

- **階層をまたぐ移動**(急所を別の手順の下へ、手順を急所へ) は提供しない (現状どおり)。
- **複数選択のドラッグ**、ドラッグ中のサムネイル生成、アニメーション付きの並べ替え。
- テイク列の**横並び化** (doc/05 は「一番左」と書くが、現行 UI は縦 1 列で「先頭 = 採用候補」を
  維持している。レイアウト変更は本タスクの目的ではなく、別途 UI 設計の議題とする)。
- カテゴリ一覧の並べ替え (doc/04 L60 は明示的に「▲▼」なので対象外)。
- サーバ API の変更、`sort_order` の意味変更、楽観ロックの仕様変更。

