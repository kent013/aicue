Round 1 の指摘に対する対応マトリクスと、修正後の概念設計を提示する。

## 対応マトリクス (Round 1)
# 対応マトリクス: conceptual-review Round 1

## [Critical] 型専用 interface を ESLint `globals` に足すのは value/type space の混同 (観点 3 / 5 / 7、3 件は同一指摘)
- 判断: **対応する**（全面的に受け入れ）
- 根拠: 指摘のとおり。`globals` に `MediaTrackConstraints` を `readonly` で載せると、
  同名を**実行時の値**として誤用した場合も `no-undef` が黙る。
  gate を入れる同じ PR で gate に穴を開ける設計であり、baseline gate の趣旨に反する。
  また「型専用 interface は globals へ追記する」を**運用ルール化**すると、
  穴が今後も増え続ける構造になる（AGENTS.md 禁止事項 2「PHPStan エラーの widen」と同種の悪手）。
- 対応内容:
  1. `globals` に載せるのは **実行時グローバルのみ** (`...globals.browser`) とし、
     型専用名は 1 件も追加しない。config には
     「型専用名を globals に足さないこと」を**禁止として**コメントに明記する。
  2. 唯一の実測違反 `CameraRecorder.svelte:168` の `MediaTrackConstraints` は、
     **`videoConstraints()` を `resources/js/lib/capture/camera.ts` へ移す**ことで解消する。
     同ファイルは既に `FacingMode` / `classifyGetUserMediaError` 等を export しており
     `CameraRecorder.svelte` が import 済み。`.ts` なので **tsc の型検査対象**になり、
     型名は `.svelte` から消える（`no-undef` の鋭さを一切削らない）。
     副次効果として、これまで型検査の外にあった constraints 構築が tsc 配下に入る。
  3. 将来 `.svelte` の型注釈に WebIDL dictionary 等の型専用名が必要になった場合の
     運用ルールを **globals 追記ではなく**「`.ts` 側で `export type X = MediaTrackConstraints;`
     と別名 export し、`.svelte` からは `import type` で参照する」に定める。
     `import type` は module 参照なので `no-undef` の対象外であり、
     かつ実行時誤用は引き続き検出される。

## [Warning] 「`.svelte` の型検査の空白地帯を閉じる」は過大表現 (観点 1 / 4)
- 判断: 対応する
- 根拠: 妥当。本バッチが閉じるのは「未定義識別子 (runtime identifier) の検出機構がゼロ」という
  一点であり、props/event の型不整合やテンプレート式の型崩れは埋まらない。
- 対応内容: 背景・期待効果の記述を「未定義識別子事故の予防」に限定。
  `.svelte` の型検査経路 (svelte-check 等) の導入は**別 backlog**として申し送りへ切り出す。

## [Warning] `noInlineConfig` 下の例外 (config override) の許可基準を先に固定せよ (観点 2 / 5)
- 判断: 対応する
- 根拠: 妥当。基準がないと「config override なら何でも良い」に流れる。
- 対応内容: 「inline disable 禁止 / 例外は config の file-scoped override に集約」という
  運用契約と、override を認める 3 条件（(a) 抑制対象が 1 ファイルに閉じている
  (b) なぜ安全かがコード側コメントで説明されている (c) config 側に理由と再検討条件を書く）を
  設計に明記し、`svelte-no-undef-gate` の記述と `docs/template-divergence.md` に固定する。

## [Warning] `svelte-no-undef-gate` の ESLint API 静的検査は brittle (観点 3)
- 判断: 対応する
- 根拠: 妥当。config オブジェクトの形状マッチも、内部 API 依存もどちらも脆い。
- 対応内容: **実ファイル fixture に対する実効設定の解決結果**を検査する方式に固定し
  (`ESLint#calculateConfigForFile` = 公開 API)、
  さらに `pages-path-case-invariant.test.ts` の作法に倣って
  **負のコントロール**（no-undef を落とした config を解決させると検出が点灯する）と
  **正のコントロール**（実 config なら通る）を置く。

## [Warning] `contrast-invariant` の名前が「コントラスト全般」と誤読される (観点 5)
- 判断: 対応する
- 根拠: 妥当。非テキスト (1.4.11) と alpha 合成をスコープ外にしている以上、
  名前と説明で境界を明示しないと「検査済み」の誤ったシグナルになる。
- 対応内容: ファイル名は台帳の正典パス (`contrast-invariant.test.ts`) を維持しつつ、
  describe 名を「不透明ペアのテキストコントラスト (WCAG 2.2 SC 1.4.3 AA)」とし、
  inventory に **PENDING_CONTRAST_PAIRS**（非テキスト / alpha 合成）を
  理由付きで宣言して「未検査であることが見える」形にする。

## [Warning] lint baseline と contrast baseline は受け入れ条件を分けよ (観点 6)
- 判断: 対応する
- 根拠: 妥当。失敗時の切り分けが違う。
- 対応内容: 詳細設計で受け入れ条件を 2 系統に分離して記述する。

## [Suggestion] 共有ヘルパの返却型と frontmatter schema を明示せよ (観点 7)
- 判断: 対応する
- 対応内容: `tests/js/styles/design-md.ts` の公開 API と返却型を詳細設計で明示する。

## [Suggestion] その他 (使命整合・スコープ・danger 是正の妥当性)
- 判断: 対応不要（肯定的評価）


---

## 修正後の概念設計（全文）
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

1. **`.svelte` の未定義識別子は誰も見ていない**。`tsc --listFiles` に `.svelte` は 1 件も現れず、
   eslint 側にも `no-undef` が無い。つまり **aicue には .svelte 内の未定義識別子を
   捕まえる機構が現状ゼロ**。spirux では同じ穴が実障害 (SSO 接続追加画面のクラッシュ,
   spirux:T1054) として顕在化しており、仮説ではなく既知の事故パターンである。

   > **本バッチが閉じるのはここだけ**である。`.svelte` の型検査 (props / event の型不整合、
   > テンプレート式の型崩れ) は依然として空白のまま残る。
   > 「`.svelte` の型安全性が回復する」とは主張しない (svelte-check 導入は §5-4 の申し送り)。

2. **`no-undef` を仮に有効化した実測**: `eslint resources/js` に
   `svelte` ブロック限定で `no-undef: error` を足すと 40 識別子 / 約 160 件が点灯する。
   ただしその大半 (`window` `document` `fetch` `setTimeout` `HTMLButtonElement`
   `SubmitEvent` `MediaRecorder` `Response` `URL` …) は `globals.browser` で解決される
   **実行時グローバル**であり、既存コードの修正は不要。
   `globals.browser` で解決されない真の残件は
   `resources/js/components/features/capture/CameraRecorder.svelte:168` の
   **`MediaTrackConstraints` 1 件のみ**。これは WebIDL の dictionary =
   TypeScript の型専用 interface で、実行時グローバルではないため `globals` に無い。
   **これを `globals` に足して黙らせることはしない** (理由は §施策 1-b)。

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

#### 1-a. config 変更

- devDependency に `globals` を追加
- `eslint.config.js` の svelte ブロックに
  `languageOptions.globals = { ...globals.browser }` と `rules["no-undef"] = "error"` を追加
- トップレベルに `linterOptions.noInlineConfig = true` を追加

#### 1-b. `globals` に載せてよいのは実行時グローバルだけ (禁止事項として config に明記)

**型専用名 (WebIDL dictionary 等) を `globals` に足すことを禁止する**。
足せば lint は green になるが、同じ名前を**実行時の値**として誤用したときにも
`no-undef` が黙る = gate を入れる同じ変更で gate に穴を開けることになる。
これは PHPStan エラーを widen して黙らせる (AGENTS.md 禁止事項 2) のフロント版である。

代わりに、型専用名が `.svelte` の型注釈に必要になったら **`.ts` 側へ逃がす**:

1. ロジックごと `.ts` に移せるなら移す (第一選択。`.ts` は tsc の検査対象になるので純増)
2. 移せない (component props の型等) なら `.ts` で
   `export type CaptureVideoConstraints = MediaTrackConstraints;` のように別名 export し、
   `.svelte` からは `import type` で参照する。
   `import type` は module 参照なので `no-undef` の対象外であり、
   グローバル名としての実行時誤用は引き続き検出される

この方針を `eslint.config.js` のコメントに固定する。

#### 1-c. 唯一の実測違反の解消 (1 ファイル)

`CameraRecorder.svelte` の
`function videoConstraints(): MediaTrackConstraints { return { facingMode }; }` を
**`resources/js/lib/capture/camera.ts` へ純関数として移す**
(`videoConstraints(mode: FacingMode): MediaTrackConstraints`)。
同ファイルは既に `FacingMode` / `classifyGetUserMediaError` / `nextFacingMode` 等を export し、
`CameraRecorder.svelte` が import 済みなので、置き場所として自然
(AGENTS.md「Controller は薄く」に対応するフロント側の作法 = `.svelte` は薄く保つ)。

結果、`globals` に型専用名は 1 件も入らず、既存コードの修正は**この 1 関数の移動のみ**。

### 施策 2: `svelte/no-at-html-tags` の死んだ directive を撤去する

`noInlineConfig` の存在意義は「ルールをファイル内コメントで黙らせられないこと」。
その体制下に **何も抑制していない `eslint-disable` コメント**を残すのは、
後続の読み手に「ここは抑制済み」という誤ったシグナルを与える罠になる。

#### 例外の許可基準 (`noInlineConfig` 体制の運用契約)

inline disable は**一律禁止**。例外が要るときは `eslint.config.js` の
**file-scoped override** に集約する。override を認めるのは次の 3 条件を**すべて**満たすときだけ:

- (a) 抑制対象が具体的な 1 ファイル (または明示列挙されたファイル群) に閉じている
- (b) なぜ安全かがコード側の日本語コメントで説明されている
- (c) config 側にも理由と**再検討条件** (いつ外せるか) が書かれている

この契約は `docs/template-divergence.md` の該当エントリと
`svelte-no-undef-gate` の doc コメントに固定する
(config に集約すれば diff に必ず現れ、レビュー可能かつ数えられる)。

#### 撤去

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
- 検査は **実ファイル (fixture) に対する実効設定の解決結果**に対して行う。
  `ESLint#calculateConfigForFile()` (公開 API) で
  `resources/js/**/*.svelte` の実効 config を解決し、
  `rules["no-undef"]` が error / `linterOptions.noInlineConfig === true` /
  `languageOptions.globals` に browser グローバルが載っていることを確認する。
  config オブジェクトを目視で形状マッチするだけの脆い検査にはしない
- `pages-path-case-invariant.test.ts` の作法に倣い、**負のコントロール**
  (no-undef を外した config を解決させると検査が点灯する) と
  **正のコントロール** (実 config なら通る) を置き、空振り gate を green として扱わない
- **型専用名が `globals` に混入していないこと**も併せて固定する
  (§施策 1-b の禁止が守られているかの機械検査。既知の型専用名の denylist で判定する)
- **同一不変条件・別実装**の新規 divergence として `docs/template-divergence.md` に記録する。
  mirror 取得後にテンプレ実装と突き合わせて収束させる旨も同エントリに書く

### 施策 4: `contrast-invariant` gate を新設し、`danger` トークンを AA まで引き上げる

- `tests/js/architecture/contrast-invariant.test.ts` を新設する
  (**台帳 `design-token-system` の `gates:` が宣言している正典パスがこれ**。
  `tests/js/styles/` ではない。無用な divergence を作らないため台帳のパスに合わせる)
- DESIGN.md frontmatter のパーサは **既存 `tests/js/styles/canonical-source-parity.test.ts` と
  共有ヘルパ化**する (二重実装しない)
- **検査範囲を名前と説明で明示する**。describe 名は
  「不透明ペアのテキストコントラスト (WCAG 2.2 SC 1.4.3 AA / 4.5:1)」とし、
  inventory に `PENDING_CONTRAST_PAIRS` (非テキスト 1.4.11 / alpha 合成) を
  **理由付きで宣言**して「未検査であることが見える」形にする
  (「contrast-invariant があるからコントラストは守られている」という誤読を作らない)
- ペア集合は **deny-by-default** で定義する。DESIGN.md の全色トークンは
  inventory 上で「背景面 / 面上テキスト / 塗り面 / 塗り面ラベル / 検査対象外 (理由必須)」の
  いずれかに**必ず分類**され、未分類トークンがあれば fail する。
  新トークンが黙って gate をすり抜けることを防ぐ
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

## 受け入れ条件 (2 系統に分ける)

lint baseline と contrast baseline は性質も失敗時の切り分けも違うので、独立に判定する。

| 系統 | 施策 | green の定義 |
|---|---|---|
| **lint baseline** | 1 / 2 / 3 | `pnpm lint` が exit 0、`pnpm typecheck` が exit 0、`svelte-no-undef-gate` が pass。負のコントロールが点灯する |
| **contrast baseline** | 4 | `contrast-invariant` が pass (全 opaque text ペア ≥ 4.5:1)、`canonical-source-parity` が pass (DESIGN.md ⇔ tokens.css)、未分類トークン 0 件 |

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
| 1 | eslint no-undef + noInlineConfig | `package.json`, `eslint.config.js`, `resources/js/lib/capture/camera.ts`, `resources/js/components/features/capture/CameraRecorder.svelte` |
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

### 5-4. `.svelte` の型検査経路 (svelte-check) の導入検討

本バッチは「未定義識別子」しか閉じない。`.svelte` 内の props / event の型不整合、
テンプレート式の型崩れは依然 tsc の外にある。
`svelte-check` を `pnpm typecheck` に組み込むかは、
既存 .svelte が生む診断量の実測を先にしないと判断できない (大量に出るなら段階導入が要る)。
c2c 台帳の `eslint-svelte-ts-baseline` boundary にも svelte-check は含まれていないため、
家系全体の議題として起票するのが筋。

### 5-5. mirror 取得後の svelte-no-undef-gate 収束

施策 3 は mirror 未取得ゆえの「同一不変条件・別実装」divergence。
laravel-claude-template の mirror が取得できた時点でテンプレ実装と突き合わせ、
実装を寄せられるなら divergence を解消する。


---

再レビューを依頼する。特に以下を確認してほしい:
- [Critical] 型専用名を globals に入れない方針への修正（.ts へロジックを逃がす / `import type` 別名 export）が、指摘した後退を実際に閉じているか
- 残る [Warning] への対応が十分か
- 新たに持ち込まれたリスクがないか（特に `videoConstraints()` の .ts 移動）

出力形式は Round 1 と同じ（全体判定 + [Critical]/[Warning]/[Suggestion] 分類、日本語）。
