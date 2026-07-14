# 概念設計レビュー依頼 (conceptual-review round 1)

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

## アプリの使命・禁止事項（AGENTS.md より）

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則

1. **フレームワークのレンジ内でやる**。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. **後方互換の並走を残さない**。書き換えると決めたら同じ PR で旧実装を消す
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**。各ステップで他要素との結合観点を確認する

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## セキュリティ不変条件(アプリ都合で緩めない)

詳細と実装手順は `docs/app-integration-guide.md` §7。すべて Architecture テストで強制されている:

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない
   (`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`)
2. **子は親に属する**: nested route の不整合は**認可より前に 404**
   (`NestedRouteIdorDefenseTest` の inventory に登録必須)
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`(平文 where は hit しない)
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**: 外部 URL(特にユーザ入力由来)を取得する機能は
   必ず `Kent013\SsrfPin\UrlSafetyInspector` / `PinnedHttpClient` を通す。
   安全境界は `config/ssrf-pin.php` に pin する(`SsrfPinBoundaryTest` が pin 値を固定)


## 思考原則 — 全議論に適用
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。ユーザー視点で考えろ。先人の知恵（Laravel/Svelte エコシステム）を探せ。仕組みが機能していない段階で値を弄るな。

## レビュー観点
1. 使命との整合性 2. 禁止事項違反 3. 実現可能性(Laravel12+Svelte5+Inertia) 4. 期待効果の妥当性 5. リスク(副作用/後退) 6. スコープの適切さ 7. 型安全性(DTO/JsonResource, PHPStan L10) 8. DESIGN.md準拠(token/hex) 9. Atomic Design準拠

## 出力形式
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: capture-show-responsive

## 背景・課題

bug-hunt (real-llm run 20260714-093524) F-1-3 (High, H11+H13)。

撮影画面 `capture.manuals.show`(ルート `app/projects/{project}/manuals/{manual}`、GET × Inertia、
ページ `resources/js/pages/Capture/Show.svelte`)が **mobile 375px / tablet 768px** で横 overflow し、
シナリオリスト(`CutNavigator`)のカットの **シーン説明 (`scene`) / 撮影ポイント説明 (`shooting_point`)**
が画面外に切れ、ページ全体に横スクロールが発生する。

### 証跡

- `devnotes/20260714-093524-bug-hunt/shard-1/screenshots/H13-mobile-capture-show.png`
  — scene テキスト「コーヒーメーカー全体を映し、作業者が電源ボ…」が右端で切れ、ellipsis なしで欠落。
- `.../H13-mobile-capture-hscroll.png` — 横スクロールすると隠れていた続き
  「ンに手を伸ばして押し、ランプが消灯するまでの一連」が現れる = truncate が全く効いていない。

### 根本原因(コード精査で brief の仮説を更新)

brief の当初仮説は「該当 flex 親コンテナに `min-w-0` が無い」だったが、コードを精査した結果、
`CutNavigator.svelte` の該当 flex 親(`<div class="min-w-0 flex-1">`, L49)には **既に `min-w-0` があり**、
scene 行(L54)も `truncate` を持っている。それでも truncate が効かない真因は **1 階層上のグリッド**にある:

1. **主因(ページ全体の横スクロール)**: `Show.svelte` L153 のレイアウトが
   `<div class="mt-4 grid gap-4 lg:grid-cols-2">` となっており、mobile/tablet(< `lg`)では
   **明示的な `grid-cols-1` が無い**。列テンプレート未指定の CSS Grid は暗黙の `auto` 列を作り、
   `auto` 列は **max-content**(= 折り返さない最長テキスト幅)までトラックが伸びる。
   結果、グリッドトラックが viewport 幅を超え、子の `min-w-0`/`truncate` は
   「トラックが広い」ため発火せず、ページに横スクロールが出る。
   Tailwind の `grid-cols-1` は `grid-template-columns: repeat(1, minmax(0,1fr))` を出力し、
   **列の最小幅を 0 にクランプ**して 1fr で viewport 内に収める。これが正しい封じ手。

2. **副因(撮影ポイント行の ellipsis 欠落)**: `CutNavigator.svelte` L56 の
   `<p class="flex items-center gap-1 truncate …">` は **flex コンテナ自身に `truncate` を付与**しており、
   直下の匿名テキストノード(flex アイテム、`min-width:auto`)が縮まず、ellipsis が正しく描画されない。
   アイコン(`MapPin`)とテキストを分離し、**テキストを `<span class="min-w-0 truncate">` で包む**のが定石。

## 改善アイデア

撮影画面のレイアウト境界を「狭幅でトラック/フレックスアイテムが max-content に膨らまない」ように是正する。
値のチューニングではなく、**overflow を封じる構造(min-width クランプ)を正す**。

1. `Show.svelte` の 2 カラムグリッドに **mobile 既定 `grid-cols-1`** を明示し、暗黙 `auto` 列を廃する。
2. 保険として 2 つの `<section>`(グリッドアイテム)に **`min-w-0`** を付与し、
   `lg:grid-cols-2` 時も列が子の max-content で膨らまないようにする。
3. `CutNavigator.svelte` の `shooting_point` 行を **アイコン + `<span class="min-w-0 truncate">` テキスト**に
   組み替え、ellipsis を機能させる(scene 行 L54 は grid 是正で truncate が復活するため構造変更不要)。

いずれも Tailwind のレイアウトユーティリティ(`grid-cols-1` / `min-w-0` / `truncate`)のみで、
hex 直書き・新規 design token・新規 SVG は増やさない(DESIGN.md / Atomic Design 準拠)。

## 期待効果

- **使命への貢献**: 撮影 PWA(スマホでのナビ撮影)は使命の中核。狭幅端末で手順テキストが
  読めない/横スクロールで詰まる状態を解消し、「思考ゼロ」で撮るべきカットを把握できる体験を守る。
- mobile 375px / tablet 768px でページ横スクロールが解消(overflow なし)。
- scene / shooting_point が枠内で truncate/ellipsis 表示され、行タップで詳細(narration 全文)を確認できる
  既存フローが機能する。
- 回帰防止: コンポーネントテスト(vitest)で該当要素が `min-w-0`/`truncate`/`grid-cols-1` を持つことを固定。

## 実装方針(概要)

| 対象 | 変更概要 |
|------|---------|
| `resources/js/pages/Capture/Show.svelte` | グリッド `div` に `grid-cols-1` 明示、2 つの `section` に `min-w-0` 付与 |
| `resources/js/components/features/capture/CutNavigator.svelte` | `shooting_point` 行をアイコン+`<span class="min-w-0 truncate">` へ組み替え |
| `tests/js/components/features/capture/CutNavigator.test.ts`(新規) | scene が truncate、shooting_point が min-w-0/truncate span を持つことを検証 |
| `tests/js/pages/CaptureShow.test.ts`(既存) | グリッドが `grid-cols-1` を持つ(mobile 単一列)ことのアサートを追加 |

## 制約・前提

- Svelte 5 runes + DS token/ramp のみ(DESIGN.md canonical、ds-purity テスト)。今回は色/タイポの変更なし。
- component 階層(atoms→…→features→templates→pages)の単方向 import を維持。層構成は変えない。
- アイコンは `@lucide/svelte`(`MapPin` 既存利用)。SVG 直書きの新設なし。
- jsdom(vitest)は実レイアウト計算をしないため、テストは **クラス付与の構造アサート**で回帰を固定する
  (「実際に overflow しないこと」のピクセル検証は bug-hunt の Playwright 実走に委ねる)。
- バックエンド(Controller / DTO / ルート)変更なし。TypeScript 型・Inertia Props も変更なし。

## スコープ外

- `Manuals/`(管理側)`projects.manuals.show` 等、撮影画面以外の画面の overflow。
- narration 詳細パネル(`Show.svelte` L171-179)の折り返し挙動(block 折返しで overflow なし、変更不要)。
- カメラ録画/アップロードキュー等の機能ロジック。
- design token / 配色 / タイポグラフィの変更。
