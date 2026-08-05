【アプリの使命 (North Star)】
<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】
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
# 概念設計: frontend-baseline-gates

c2c 台帳 3 件 (`eslint-svelte-ts-baseline` / `atomic-design-gates` の svelte-no-undef gate 部分 /
`design-token-system` の contrast-invariant 部分) を 1 バッチに統合し、aicue の
フロントエンド baseline gate の欠落を補完する。

## 背景・課題

### 台帳側の位置づけ (2026-08-05 オーナー裁定)

| feature | aicue の状態 | 裁定 |
|---|---|---|
| `eslint-svelte-ts-baseline` | `update_pending` / `pre-t0` | eslint.config.js が t0 群と乖離 (no-undef gate 不在を mirror で確認) |
| `atomic-design-gates` (AG-023) | `update_pending` / `pre-t0` | **欠落補完: svelte-no-undef-gate**。「不在は方式差ではなく欠落」 |
| `design-token-system` (AG-022) | `update_pending` / `pre-t0` | **欠落補完: contrast-invariant**。「コントラスト不足は読めない利用者が出る実害であり、欠落は採否を選ぶ余地がない」 |

3 件とも「aicue にだけ無い」= 方式差ではなく欠落、という裁定が既に出ている。
本バッチは裁定の実行であって、採否を再検討するものではない。

### 実測した実害 (この devcontainer 上で確認)

1. **`.svelte` は型検査の空白地帯**。`tsc --listFiles` に `.svelte` は 1 件も現れず、
   eslint 側にも `no-undef` が無い。つまり **aicue には .svelte 内の未定義識別子を
   捕まえる機構が現状ゼロ**。spirux では同じ穴が実障害 (SSO 接続追加画面のクラッシュ,
   spirux:T1054) として顕在化しており、仮説ではなく既知の事故パターンである。

2. **`no-undef` を仮に有効化した実測**: `eslint resources/js` に
   `svelte` ブロック限定で `no-undef: error` を足すと 40 識別子 / 約 160 件が点灯する。
   ただしその大半 (`window` `document` `fetch` `setTimeout` `HTMLButtonElement`
   `SubmitEvent` `MediaRecorder` `Response` `URL` …) は `globals.browser` で解決される
   **実行時グローバル**であり、既存コードの修正は不要。
   `globals.browser` で解決されない真の残件は
   `resources/js/components/features/capture/CameraRecorder.svelte:168` の
   **`MediaTrackConstraints` 1 件のみ**。これは WebIDL の dictionary =
   TypeScript の型専用 interface で、実行時グローバルではないため `globals` に無い。

3. **`noInlineConfig` の影響は 1 箇所**。`resources/js` 配下の inline directive は
   `resources/js/pages/Settings/Security.svelte:465` の
   `<!-- eslint-disable-next-line svelte/no-at-html-tags -->` **のみ**。
   そして **`svelte/no-at-html-tags` は現在の eslint.config.js で有効化されていない**
   (svelte ブロックは `require-each-key` / `prefer-svelte-reactivity` /
   `prefer-writable-derived` / `no-useless-mustaches` の 4 本のみ)。
   実験的に `noInlineConfig: true` を入れて `eslint resources/js` を回したところ
   **exit 0 / 出力ゼロ** = この directive は**既に何も抑制していない死んだコメント**だった。
   「放置すると lint が赤くなる」という当初想定は実測で否定された。

4. **contrast-invariant は現行テーマのままでは green にならない**。
   DESIGN.md frontmatter の色値から WCAG 相対輝度比を実測した結果、
   **`danger` (#DC2626) だけが AA 4.5:1 を割る**:

   | ペア | 比 | 判定 |
   |---|---|---|
   | `text-neutral` on `bg-danger` (Button `danger` / `danger-outline` hover / NotificationBell バッジ) | **4.39** | ✗ |
   | `text-danger` on `bg-neutral` (Button `danger-ghost` / 状態テキストがページ背景に載る場合) | **4.39** | ✗ |
   | `text-neutral` on `bg-success` | 4.56 | ✓ |
   | `text-neutral` on `bg-warning` | 4.57 | ✓ |
   | `text-neutral` on `bg-primary` | 4.70 | ✓ |
   | `text-neutral` on `bg-tertiary` | 4.98 | ✓ |
   | `text-danger` on `bg-surface` | 4.83 | ✓ |
   | `text-secondary` on `bg-neutral` | 7.03 | ✓ |
   | `text-primary` on `bg-neutral` | 16.12 | ✓ |

   「色値変更なしで green になる見込み」という当初想定も実測で否定された。
   **データに真摯に向き合う**(思考原則) 以上、ペア集合を縮めて green を作るのではなく、
   1 トークンの値を是正する。

## 改善アイデア

### 施策 1: eslint に svelte の `no-undef` と `noInlineConfig` を入れる

- devDependency に `globals` を追加
- `eslint.config.js` の svelte ブロックに
  `languageOptions.globals = { ...globals.browser, MediaTrackConstraints: "readonly" }` と
  `rules["no-undef"] = "error"` を追加
- トップレベルに `linterOptions.noInlineConfig = true` を追加
- **型専用 interface は `globals` へ追記する**という運用ルールを config のコメントで明記する
  (「.svelte の TS 型注釈に現れる WebIDL dictionary 等は実行時グローバルではないため
  `globals.browser` に無い。`readonly` で足す」)
- 既存コード修正は **`MediaTrackConstraints` の globals 追加のみ**で 0 件

### 施策 2: `svelte/no-at-html-tags` の死んだ directive を撤去する

`noInlineConfig` の存在意義は「ルールをファイル内コメントで黙らせられないこと」。
その体制下に **何も抑制していない `eslint-disable` コメント**を残すのは、
後続の読み手に「ここは抑制済み」という誤ったシグナルを与える罠になる。

- `Security.svelte:465` の `<!-- eslint-disable-next-line svelte/no-at-html-tags -->` を削除する
- `{@html qrSvg}` の正当性は直上の日本語コメント (L461-462: 「QR はサーバ提供の SVG を
  そのまま描画する。svg 文字列に属性を注入せず、wrapper を role="img" にして
  アクセシブルネームを与える (H14)」) が既に説明しており、情報は失われない
- **`svelte/no-at-html-tags` をルールとして有効化することは本バッチではしない**。
  台帳 `eslint-svelte-ts-baseline` の boundary が列挙する t0 のルール集合に
  このルールは含まれておらず、aicue が単独で足すと**新しい divergence を作る**。
  家系全体へ提案すべき話なので、申し送り (§施策 5) に還流候補として記録する

### 施策 3: `svelte-no-undef-gate` (config 静的検査型) を新設する

`tests/js/architecture/svelte-no-undef-gate.test.ts` を新設し、
「eslint.config.js が `resources/js/**/*.svelte` に対して `no-undef=error` と
`noInlineConfig` を持つ」ことを固定する。

- laravel-claude-template の実物が本環境に存在しない (mirror 未取得) ため、
  テンプレ実装をそのまま移植できない。**config を静的に検査する型**で実装する
- ESLint の `Linter`/`loadESLint` API で **実効設定を解決**して検査する
  (config オブジェクトを目視形状マッチするだけの脆い検査にしない)
- **同一不変条件・別実装**の新規 divergence として `docs/template-divergence.md` に記録する。
  mirror 取得後にテンプレ実装と突き合わせて収束させる旨も同エントリに書く

### 施策 4: `contrast-invariant` gate を新設し、`danger` トークンを AA まで引き上げる

- `tests/js/architecture/contrast-invariant.test.ts` を新設する
  (**台帳 `design-token-system` の `gates:` が宣言している正典パスがこれ**。
  `tests/js/styles/` ではない。無用な divergence を作らないため台帳のパスに合わせる)
- DESIGN.md frontmatter のパーサは **既存 `tests/js/styles/canonical-source-parity.test.ts` と
  共有ヘルパ化**する (二重実装しない)
- **`danger` を `#DC2626` (Tailwind red-600) → `#B91C1C` (red-700) に是正**する。
  - 既存パレットは Tailwind 由来で、**状態色/アクセントは軒並み -700 段**
    (`success` = green-700, `warning` = amber-700, `tertiary` = teal-700)。
    `danger` だけが -600 段という**内部不整合**であり、AA 割れはその帰結。
    red-700 へ揃えることは「色を好みで弄る」ではなく**体系の整合回復**である
  - 是正後: `text-neutral` on `bg-danger` = **5.89**、`text-danger` on `bg-neutral` = **5.89**、
    `text-danger` on `bg-surface` = **6.47**。**全ペアが AA 4.5:1 を満たす**
  - 変更範囲は `DESIGN.md` frontmatter L18 + 本文 L104 + `resources/css/tokens.css` L31 の
    3 箇所のみ (grep 実測)。`canonical-source-parity` が同一 PR 更新を強制する。
    既存テストの色 assert は class 名 (`bg-danger`) ベースで値に依存していない (実測確認済み)

## 期待効果

- **使命への貢献**: 撮影 PWA (`CameraRecorder` / `CaptureFileFallback` / `TakePreviewDialog`) は
  ブラウザ API を最も濃く使う面で、未定義識別子事故の一次リスクが集中している。
  現場作業者が現場で撮影中に白画面になる = 使命 (「思考ゼロ・編集ゼロ」) の直撃故障であり、
  その検出機構がゼロという状態を閉じる価値は大きい
- **実害の除去**: `danger` の AA 割れは「失敗・破壊的操作・エラー」という
  最も読めなければ困る面で起きている。読めない利用者が出る実害を 1 トークンで解消する
- **機械化**: 3 件とも「レビューで気をつける」ではなく「壊すと CI が落ちる」形に寄せる
  (台帳が共有している思想と一致)

## 実装方針（概要）

| # | 施策 | 主な変更 |
|---|---|---|
| 1 | eslint no-undef + noInlineConfig | `package.json`, `eslint.config.js` |
| 2 | 死んだ disable directive 撤去 | `resources/js/pages/Settings/Security.svelte` |
| 3 | svelte-no-undef-gate | `tests/js/architecture/svelte-no-undef-gate.test.ts` (新規), `docs/template-divergence.md` |
| 4 | contrast-invariant + danger 是正 | `tests/js/architecture/contrast-invariant.test.ts` (新規), `tests/js/styles/design-md.ts` (新規: 共有パーサ), `tests/js/styles/canonical-source-parity.test.ts` (パーサ差し替え), `tests/js/styles/inventory.ts` (ペア役割の宣言追加), `DESIGN.md`, `resources/css/tokens.css` |
| 5 | 申し送り | 設計書のみ (コード変更なし) |

## 制約・前提

- **JavaScript 禁止・TypeScript 必須** (AGENTS.md)。新規テストは `.ts`。
  `eslint.config.js` は既存の `.js` を維持する (ESLint flat config の設定ファイルであり、
  拡張子を変えると解決経路が変わる。既存資産の維持であって新規 JS の追加ではない)
- 本バッチは **DB 非依存**。この devcontainer に PostgreSQL は無いが影響しない
- `pnpm test` / `lint` / `typecheck` は T099 の global test lock 経由で走る (マージ済み)
- 同時進行の別バッチ (architecture-gate-followup) が
  `tests/js/architecture/svelte-head-no-title.test.ts` を追加する。
  本バッチのファイル名 (`svelte-no-undef-gate.test.ts` / `contrast-invariant.test.ts`) とは衝突しない。
  共通ヘルパを作る場合は既存の走査作法 (`pages-path-case-invariant.test.ts` の
  `fs.readdir(recursive:true)` + 負/正のコントロールテスト) に寄せる
- `public/capture-sw.js` は lint 対象外 (`pnpm lint` = `eslint resources/js`) のまま据え置く

## スコープ外

- **aigenba 拡張** (`tokens.test.ts` / `design-system-docs.test.ts`) と
  **spirux 方式** (DESIGN.md 直読 token-sync 内蔵) は取り込まない。
  AG-022 で t1 標準形への採用は裁定されているが、aicue への配布は agenda 未裁定
- **WCAG 1.4.11 非テキストコントラスト (3:1)** は本バッチのスコープ外 (§施策 5 の申し送りへ)。
  実測では `border-strong` (#A1A1AA) on `surface` = 2.56 で 3:1 を割るが、
  1.4.11 は「装飾的な境界線」を適用除外とするため、
  `border-border` (カード区切り = 装飾) と `border-border-strong` (ghost ボタンの枠 = 機能)
  を**使用箇所ごとに分類**しないと正しい判定ができない。
  トークン単位の gate では原理的に判定できないので、値を弄る前に別バッチで
  「どの border が機能的境界か」の役割モデルを決める
- **alpha 合成ペア** (`bg-danger/10` + `text-danger` の Badge、`bg-primary-soft`、
  `ring-primary/35` 等) はスコープ外。合成後の実効色は親背景に依存するため、
  トークン単体では定まらない。v1 は不透明ペアのみを対象とする
- ダークテーマは存在しない (DESIGN.md は単一テーマ) ため対象外
- c2c への `append_event` は行わない (設計フェーズのため。実装完了後に別途)

## 施策 5: 申し送り (本バッチでは実装しない)

### 5-1. aicue 独自 4 gate が c2c 台帳に未記載

調査の結果、以下の 4 gate が **aicue にのみ存在し、c2c 台帳のどの feature にも
記載がない**ことが判明した (`atomic-design-gates` の `gates:` に載っているのは
aicue 独自分としては `deprecated-imports.test.ts` のみ)。

| gate | 何を守っているか | 還流価値の見立て |
|---|---|---|
| `tests/js/architecture/form-novalidate.test.ts` | form の native constraint validation に依存させない (検証の正本はサーバ + 押下時 client エラー)。AGENTS.md 禁止事項 8「必須条件未充足を理由に disabled にしない」と対になる不変条件 | **高**。日本語 UI を持つ全リポジトリ共通の課題 |
| `tests/js/architecture/logout-call-site-inventory.test.ts` | ログアウト導線を非 Inertia 経路 (JSON 204 完結の XHR 等) で新設させない (deny-by-default)。Inertia history 暗号化 + `clearHistory()` の保証条件を守る | **高**。Inertia + Fortify を使う全リポジトリで同じ穴が開く |
| `tests/js/architecture/page-shell-structure.test.ts` | pages 層の外枠構造 (AppLayout / GuestLayout の被せ方) を固定 | 中 |
| `tests/js/architecture/pages-path-case-invariant.test.ts` | `resources/js/Pages/` (大文字) 参照を禁止。case-insensitive な開発 FS では通り case-sensitive な CI/本番で白画面になる事故を止める。実際に他アプリからの移植で混入した実績あり | **高**。移植の起きる家系では確実に再発する |

**本バッチでは c2c への `append_event` を行わない** (指示による)。
還流提案として台帳へ載せるかはオーナー裁定事項。

### 5-2. `svelte/no-at-html-tags` の家系標準化 (§施策 2 より)

aicue の `{@html}` 使用箇所は Security.svelte の QR SVG 1 件のみで、
`noInlineConfig` 下では config の file-scoped override が唯一の例外手段になる。
これは「例外が config に集約されてレビュー可能になる」という良い性質を持つ。
ただし t0 のルール集合外なので aicue 単独では入れず、家系提案とする。

### 5-3. 非テキストコントラスト (WCAG 1.4.11) の役割モデル

`border-strong` (#A1A1AA) on `surface` = 2.56 で 3:1 を割る (実測)。
`#71717A` (zinc-500) なら 4.83 で通るが、値を決める前に
「どの border が機能的境界で、どれが装飾か」をコンポーネント単位で定義する必要がある。
`Button` ghost の枠・入力欄の枠・focus ring は機能、カードの区切り線は装飾、という線引きの正本を
DESIGN.md に置いてから gate 化する。

### 5-4. mirror 取得後の svelte-no-undef-gate 収束

施策 3 は mirror 未取得ゆえの「同一不変条件・別実装」divergence。
laravel-claude-template の mirror が取得できた時点でテンプレ実装と突き合わせ、
実装を寄せられるなら divergence を解消する。

