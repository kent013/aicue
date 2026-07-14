# 使命・禁止事項・思考原則（AGENTS.md 正本）

## アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。成果だけでなく、構造の揺らぎ・想定外のパターンも判断材料になる。
先人の知恵を探せ。Laravel/Svelte のエコシステムに既存解があるなら乗れ。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。
仕組みが機能していない段階で値を弄るな。方向性が正しいと確認できてから微調整せよ。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# System: 役割

あなたは Web アプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResource パターンに沿っているか。PHPStan level 10 を通せるか
8. フロントエンド規約: DESIGN.md (token canonical) / Atomic Design 階層 / Lucide アイコン前提に沿っているか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 参考: 現行コードの要点（レビュー補助）

### resources/js/components/templates/GuestLayout.svelte（現行ヘッダー）
```svelte
<header class="border-b border-border bg-surface">
  <div class="mx-auto flex max-w-5xl items-center justify-between px-8 py-4">
    <a href="/" class="text-h3 text-primary">{appName}</a>
    {#if nav}
      <nav class="flex items-center gap-4 text-body">
        {@render nav()}
      </nav>
    {/if}
  </div>
</header>
```
- Props: `appName: string; children: Snippet; nav?: Snippet; footerLinks?: Snippet;`
- 利用ページ: Welcome.svelte / Pricing.svelte（nav snippet を渡す）/ Contact/*（nav 無し）

### Welcome.svelte が渡す nav snippet（内容は不変の想定）
```svelte
{#snippet nav()}
  <a href="/pricing" class="text-text-secondary hover:text-primary">料金プラン</a>
  {#if page.isAuthenticated}
    <a href="/dashboard" ...>ダッシュボード</a>
  {:else}
    <a href="/login" ...>ログイン</a>
    <a href="/register" class="text-primary hover:text-primary-hover">無料で始める</a>
  {/if}
{/snippet}
```

### 既存テスト: tests/js/pages/Welcome.test.ts（vitest + @testing-library/svelte）
`getByRole("link", { name: /..../ })` でナビ・CTA を単一ヒット前提で検証。

---

# User: 概念設計

（この下に conceptual-design.md 全文を貼付）

---
# 概念設計: home-responsive-header

## 背景・課題

bug-hunt finding **F-M2 (Medium, H13)**。

- home (Welcome / LP) のヘッダーナビが mobile 375px 幅でハンバーガーメニュー化されず、
  ロゴ + 横並びナビ (`料金プラン` / `ログイン` / `無料で始める`) が水平方向に収まりきらず
  **単語途中で折返す**。LP は第一印象を決める面であり、可読性の低下は North Star
  (現場作業者が迷わず使える) の入口体験を損なう。
- 現行のヘッダーは共通レイアウト `resources/js/components/templates/GuestLayout.svelte`
  にあり、ナビ内容はページ側 (`Welcome.svelte` / `Pricing.svelte`) から `nav` snippet で
  差し込まれる。`GuestLayout` のヘッダーは `flex items-center justify-between` +
  `nav class="flex items-center gap-4"` の**単一の横並び**で、狭幅の折返し対策が無い。
- 同じ症状は `GuestLayout` を使う **Pricing.svelte** でも起きる (共通の根)。
  認証済み画面の `AppLayout.svelte` は `flex-wrap` で 2 段化する別方針を既に持つが、
  ゲスト面には未適用。

## 改善アイデア

**根 (`GuestLayout.svelte`) を 1 箇所直す**ことで、`GuestLayout` を使う全ゲストページ
(Welcome / Pricing / Contact) のヘッダーを狭幅対応にする。ページ側 (`Welcome.svelte`) は
既存の `nav` snippet をそのまま渡すだけで変更不要 (snippet の中身は据え置き)。

- **広幅 (`sm` = 640px 以上)**: 現行どおりロゴ右に横並びナビを表示。
- **狭幅 (`sm` 未満, 375px を含む)**: 横並びナビを隠し、**ハンバーガーのトグルボタン**
  (Lucide `Menu` アイコン、開いている間は `X`) を表示。押下でヘッダー直下に
  ナビ項目を**縦積みのパネル**として展開する。
- ナビ項目そのもの (`nav` snippet が描く `<a>` 群) は不変。展開方向 (横 flex / 縦 flex-col)
  は `GuestLayout` 側のコンテナが制御するため、**同一 snippet を 2 箇所で `@render`** して
  広幅=横・狭幅パネル=縦を出し分ける。

### なぜ `sm` (640px) を境界にするか
- ゲストナビは最大 3 項目の短いリンクで、640〜768px では横並びで問題なく収まる
  (`768px で崩れない` を満たす)。375px では収まらないためハンバーガー化する
  (`375px で崩れない` を満たす)。
- アプリ既存のレスポンシブは `sm:` / `lg:` の Tailwind ブレークポイントを多用しており
  (Welcome の grid: `sm:grid-cols-3` / `lg:grid-cols-2` 等)、`sm` 境界は既存方針に整合。

## 期待効果

- **使命への貢献**: LP/料金の第一印象で文字折返しが消え、初見の現場作業者・導入担当が
  迷わず CTA (`無料で始める` / `ログイン`) に到達できる。入口体験の破綻を除去する。
- **具体的な改善見込み**:
  - 375px でヘッダーが 1 行に収まり、ナビはハンバーガー内に整列 (単語途中折返しの解消)。
  - `GuestLayout` 単一修正で Welcome / Pricing / Contact のヘッダーが同時に狭幅対応。
  - 768px 以上は現行の見た目を維持 (回帰なし)。

## 実装方針（概要）

`resources/js/components/templates/GuestLayout.svelte` のヘッダーのみを変更する。

1. `GuestLayout` にローカル `$state` (`menuOpen`) と `toggle()` / `close()` を追加。
2. ヘッダー右側を 2 系統に分割:
   - **広幅ナビ**: 現行の `<nav>` に `hidden sm:flex` を付与 (`sm` 未満で非表示)。
   - **ハンバーガーボタン**: `sm:hidden` を付与し `sm` 以上で非表示。
     既存の **Button atom (`iconOnly`)** を再利用 (塗りつぶしでない `ghost` variant、
     型で必須の `ariaLabel` を付与)。アイコンは `@lucide/svelte` の `Menu` / `X` を状態で切替。
     `aria-expanded` / `aria-controls` を配線。
3. **展開パネル**: `{#if menuOpen}` のときだけヘッダー直下に縦積みの `<nav>` を描画
   (`id` をボタンの `aria-controls` に対応)。中身は同じ `{@render nav()}`。
   閉時は DOM に出さない (テストの重複要素回避・SSR/初期描画の軽量化)。
4. a11y/UX: パネル内リンク押下で `close()`、`Escape` で `close()`。DESIGN.md 準拠で
   影・グラデーション不使用、`border`/`bg-surface` と token で階層表現。

**新規 component は作らない** (レイアウト構造の関心は template 層が担う。トグルは
Button atom の再利用で足りる)。Atomic Design の階層 (atoms→...→templates→pages) と
単方向 import を逸脱しない。

## 制約・前提

- **純フロントエンド変更**。バックエンド (Controller / Service / DTO / JsonResource /
  Inertia Props / marketing 型) に一切変更なし。`response()->json` 直書き等の禁止事項に無関係。
  PHPStan (PHP 側) には影響しない。
- DESIGN.md が canonical。色は既存 token (`text-text-secondary` / `text-primary` /
  `bg-surface` / `border-border` 等) のみ使用し、hex 直書き・新規 token を増やさない。
  角丸は `sm/md/lg` ramp のみ。アイコンは Lucide のみ (`Menu` / `X`)。
- component 階層 (`atomic-import-graph.test.ts`)・Lucide scoped import
  (`lucide-scoped-import.test.ts`)・SVG 直書き禁止 (`svg-inline-allowlist.test.ts`)・
  ds-purity / shape-ramp-purity / typography-invariant の各アーキテクチャテストに適合させる。
- `nav` snippet を 2 箇所で `@render` する。閉時にパネルを描画しないことで、既存
  `tests/js/pages/Welcome.test.ts` の `getByRole("link", ...)` が**重複ヒットしない**
  ことを保証する (jsdom は `hidden sm:*` を実際には隠さないため、閉時レンダリング抑止が要)。
- ブレークポイント境界は `sm` (640px)。375px=ハンバーガー / 768px=横並び。

## スコープ外

- 認証済み画面 `AppLayout.svelte` のヘッダー方針変更 (別 finding。今回は触らない)。
- ナビ項目そのものの追加・文言変更・IA 変更。
- フッターリンク (`footerLinks` snippet) の狭幅対応 (現状 `料金プラン` 等の短リンクで
  折返し報告なし。今回の finding 対象外)。
- ダークモード/テーマ切替の新設、アニメーション演出の作り込み。
