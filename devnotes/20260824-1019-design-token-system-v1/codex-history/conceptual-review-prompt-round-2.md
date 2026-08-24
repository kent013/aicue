Round 1 の指摘への対応を報告する。Critical 2 件・Warning 6 件すべてに対応した (1 件は理由を添えて一部対応)。
以下に (1) 対応マトリクス、(2) 修正後の概念設計の全文を示す。改めて全体判定を出してほしい。

未解消の Critical / Warning が残っていれば指摘し、無ければ APPROVED としてほしい。

---

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 1

## [Critical] i15 の状態単位解析は Svelte の実際の適用 class を網羅できない (走査対象構文が未定義 / fail-open)

- 判断: **対応する** (指摘のとおり)
- 根拠: 実コードを実読して、指摘された形の実在と不在を切り分けた。
  - `clsx` / `twMerge` / `classnames`: **リポジトリに 0 件** (依存も無い)。
  - Svelte の `class:foo={expr}` ディレクティブ: **0 件** (`class: extraClass` は props の分割代入で別物)。
  - 配列 + `.filter(Boolean).join(" ")` による合成: **11 箇所**で実在する
    (`atoms/Button.svelte:68` / `Badge.svelte:25` / `Card.svelte:36` / `Input.svelte:41` /
    `Textarea.svelte:39` / `Spinner.svelte:31` / `DragHandle.svelte:29` /
    `molecules/CodeSnippet.svelte:187` ほか)。
  - **前景と背景が別の文字列リテラルに割れる実例が在る**: `atoms/input-state.ts` は
    `text-text` を `INPUT_BASE_CLASSES` に、`bg-surface` / `bg-neutral` を
    `inputStateClass()` の戻り値に持つ。
  - 親から渡る class (`extraClass`): 全 atom に在る。**部品の境界をまたぐ**ので
    正典 i22 (2) の保証外そのものである。
  - テンプレート補間で色 class を組み立てる形 (`` `bg-${tone}` ``): **0 件**
    (補間は `${border}` のように**完成した class 文字列**を差し込む形だけ)。
- 対応内容: 概念設計に「走査対象構文の全数分類」節を新設し、次を明記した。
  1. **走査単位を宣言する**: 文字列リテラル (単引用 / 二重引用 / バッククォート) を
     class 記述の単位とし、単位の中だけで状態と組を作る。
  2. **範囲内で解決できない語は必ず落とす**: テーマの名前空間の接頭辞
     (`bg-` / `text-` / `border-` / `ring-` / `divide-` / `outline-` / `decoration-` /
     `accent-` / `fill-` / `stroke-` / `rounded-`) を持つ語が写像の宣言集合へ解決せず、
     契約表にも無ければ**不合格**にする (抽出 0 件遮断だけに頼らない)。
  3. **補間を含む単位は素通りさせない**: `${` を含む単位で、かつテーマ名前空間の語を
     含むものは判定不能として分類する (現状 1 件 = `input-state.ts`)。
  4. **不完全な単位 (前景か背景の片方しか無い) の扱いを 2 分類に分ける** —
     半透明が関わるものは**実体集合の一致**で固定し、不透明のみのものは下限で受ける
     (理由は下の Warning 5-2 と同じ)。
  5. **保証しない範囲を gate 本体に書く**: 宣言の単位をまたいで成立する組
     (`input-state.ts` の形)、親から渡る class、親要素から継承する背景。
     ただしこの穴は i14 の役割直積が**全色 token を既定拒否で分類する**ことで
     大部分が塞がれている (両方の token に役割が在れば組は既に母集団の内側にある)。
     母集団の外へ出るのは「役割の組み合わせとして直積に現れない 2 token が
     同じ要素に載る」場合だけで、それが宣言の単位に揃っていないときに限り見えない —
     この限定を docblock に書く。

## [Critical] i16 の下地を neutral / surface の 2 種に固定する根拠が実測表と矛盾する (danger / primary 等も不透明背景として実在する)

- 判断: **対応する** (固定をやめる。ただし「すべての不透明 token を下地にする」は採らない)
- 根拠: 「不透明な背景」と「下地 (ground)」は別概念である。
  `bg-primary` / `bg-danger` / `bg-border` は**塗り面** (それ自体が 1 つのコントロール) で、
  他の部品を上に置く面ではない。実際、`bg-primary-soft` (primary 12%) を
  `bg-primary` の上に置いた場合の合成は primary そのもの = 比 1.0 になり、
  **どんな値を選んでも成立しない**。したがって「すべての不透明 token を下地に要求する」は
  検査として実行不能である。
  正典 i16 の「そのアプリに実在する不透明な下地のすべて」は、
  aicue の役割分類でいう**面 (`SURFACE_ROLE_TOKENS`)** に対応する。
- 対応内容: 3 点を概念設計へ足した。
  1. 下地集合を**固定配列にしない**。i14 の役割分類の「面」から**機械導出**する
     (面を足したら自動で下地が増える。i4 の趣旨)。
  2. 塗り面の上に半透明背景が載る形を**静的に検出できる範囲で落とす**:
     同じ状態の中に**塗り面の背景と半透明の背景が同居**する単位を判定不能として分類する
     (正典 i16 が名指しする「不透明背景と alpha 背景の同居」がこれである)。
  3. 静的に追えない親要素の塗り面については、**DESIGN.md に規約行を置く**
     (「ソフト背景の部品は面 (`neutral` / `surface`) の上にのみ置く」) 。
     機械で保証しないことを gate 本体の「保証しないもの」に書く (i22 (2))。

## [Warning] 「屋外では読めない」は AA 4.5:1 の計算では立証できない

- 判断: **対応する**
- 対応内容: 期待効果の表現を「WCAG AA の最低基準を機械で満たし続ける」へ改めた。
  屋外の実利用評価は本設計の主張から外し、別の検証対象として明記した。

## [Warning] `color-mix(…, transparent)` を alpha 合成へ還元する仮定を契約テストで固定すべき

- 判断: **対応する** (指摘が正しく、しかも安価である)
- 根拠: 実際に Tailwind 4.3 でコンパイルして生成形を実測した。
  - `bg-primary/10` → 直接の宣言は `color-mix(in srgb, #2563eb 10%, transparent)`、
    `@supports (color: color-mix(in lab, red, red))` の中に
    `color-mix(in oklab, var(--color-primary) 10%, transparent)`。
  - `bg-primary-soft/40` → `color-mix(in srgb, rgba(37, 99, 235, 0.12) 40%, transparent)`
    (= **alpha の二重**であることが生成形からも読める)。
  - fallback 側は**リテラルの hex を埋め込む**ので、正本の値との突き合わせにも使える。
- 対応内容: 密閉の層 (`tokens.test.ts`) に「不透明度修飾の生成形の契約」を追加する施策を足した。
  代表の `/10`・`/12` (派生 token) と二重修飾の 3 形を固定する。

## [Warning] i9 は class suffix だけでは閉包を保証できない (`var()` / 任意値 / Tailwind 既定色)

- 判断: **対応する**
- 対応内容: 契約表の対象範囲を概念設計に明記した。
  (a) `resources/css` と `resources/js` の `var(--…)` 参照、
  (b) テーマ名前空間の任意値 class (`text-[13px]` / `bg-[#fff]` 等)、
  (c) Tailwind 既定テーマの色語 (`white` / `black` / raw palette) は
  **写像の外なので不合格**、を分類として持つ。
  実在する 1 件 (`--app-sidebar-w` = 同一要素の `style` 属性で宣言する局所変数) は
  理由つきで契約表へ登録する。

## [Warning] 一段暗色化はブランド印象・hover・disabled・選択状態を変える。本文コントラストだけでは後退を検知できない

- 判断: **対応する**
- 対応内容: 詳細設計に「是正対象 6 token の逆引き表」(利用箇所 × 状態 × 前景/背景の組) を
  付録として持つ施策を足した。走査器の試作で機械的に導出できることは確認済みである。

## [Warning] 判定不能分類の「件数だけ」の固定は、件数更新で通す誘惑を残す

- 判断: **一部対応する** (半透明が関わる分類は実体集合の一致へ。不透明のみは下限で受ける)
- 根拠: 正典 i16 は「走査で見つかった半透明の組は全件が台帳に載ること」を要求しており、
  ここは実体集合の一致が正しい。一方、不透明のみの不完全な単位は数十ファイルに及び
  (`bg-surface` 単独が 39 単位、`bg-neutral` 単独が 20 単位)、これを実体集合で pin すると
  **期待値の機械的な更新が常態化して統制が形骸化する** — 正典が s14 で
  「出現位置の行番号は固定しない」と決めたのと同じ理由である。
  加えて不透明のみの組は i14 の役割直積が既に母集団として覆っている。
- 対応内容: 分類の粒度を 2 段に分け、それぞれの理由を概念設計に書いた。
  実体集合の側は**行番号を持たず (ファイル + 分類) の集合**で固定する。

## [Suggestion] `border` を塗り面へ、`surface` を塗り面のラベルへ分類し直す判断は妥当

- 判断: そのまま採用 (変更なし)

## [Warning] 台帳・契約表・パーサを文字列配列や `Record<string, string>` に寄せると取り違えを型で防げない

- 判断: **対応する**
- 対応内容: 概念設計の「型の方針」節を新設した。
  解決結果を discriminated union (`{ kind: "token" | "ramp" | "radius" | "contract" | "unresolved" }`)
  で表し、判定不能の理由も union にする。台帳は `as const satisfies` で宣言し、
  分類の網羅を `never` へ収束させる。既存の `FrontmatterSectionOwner` が同じ形の先例である。

## [Suggestion] 使命・禁止事項・スコープの切り分けは妥当

- 判断: そのまま採用 (変更なし)

---

## 修正後の概念設計 (全文)

# 概念設計: design-token-system 正典 v1 追従

対象 feature: `design-token-system` (lctl 機能台帳 / feature_revision `33-8dfb1da7bd25` /
canonical_version `v1` / aicue セル `version: pre-v1` → `target_version: v1`)

## 背景・課題

家系の機能台帳は 2026-08-22 の settle で本 feature の正典 **v1** (不変条件 i1〜i22) を確定させ、
6 リポジトリすべてを `update_pending (pre-v1 → v1)` へ戻した。aicue は正典 v1 へ最も多くの条件を
提供したセル (i5 / i7 / i6 の走査の絞り込み / i11 の検査目録 / i12 の CommonMark 忠実な fence 判定 /
i21 / i8 の訂正) だが、**5 条件を満たしていない**と名指しされている。

実コードを実読して 5 条件すべてが事実であることを確認し、さらに台帳が挙げていない欠落を 2 件見つけた。

| 条件 | 正典が要求すること | aicue の現状 (実読で確認) |
|---|---|---|
| i13 | 線形化しきい値 `0.04045` | `tests/js/architecture/contrast-invariant.test.ts:48` が `0.03928` |
| i16 | 半透明背景 × 不透明文字の合成検査 | `tests/js/styles/inventory.ts:99` の `PENDING_CONTRAST_PAIRS` で「alpha 合成ペアは未検査」と明示宣言したまま |
| i15 | 実装 class からの逆向き被覆 | contrast gate の入力は `inventory.ts` の宣言のみ。`resources/js` を 1 行も走査していない |
| i9 | 参照の閉包 (token 名が写像 1 か所へ解決する) | 該当 gate が `tests/js` 配下に 1 本も無い (ds-purity / typography-invariant / shape-ramp-purity はいずれも禁止パターンの deny 走査) |
| i10 | 文書の部品の節 ⇔ 部品ファイルの双方向一致 | 該当 gate が無い |
| i12 の残余 | 描画されない Markdown の除去に **4 空白以上の字下げコード**を含める | `design-system-docs.test.ts` の `renderedLines()` は HTML コメントと囲みコードだけ。`docs/design-system.md` 自身も「落とすのは HTML コメント / fenced code の 2 つ」と書いている |
| i2 前半 | `@theme` ブロックがリポジトリに **1 つだけ**であることの機械検査 | 実体は `resources/css/tokens.css:12` の 1 本のみだが、**ブロック数を見る検査が存在しない** (`themeVariables()` が見ているのは「トップレベル直下」= i2 後半だけ) |

### 実測で確定した重い事実 — トークン値の是正が避けられない

i16 の合成モデル (sRGB の重み付き和・8bit 丸め・しきい値 0.04045) で、**実在する不透明な下地
2 種** (`neutral` #F4F4F5 / `surface` #FFFFFF) の両方に対して現行値を計算すると、
**5 組が AA (4.5:1) 未達**である (本設計で実測。計算スクリプトは同ディレクトリの
`contrast-measurements.md` に記録)。

| 組 | neutral 上 | surface 上 | 使用箇所 (実在) |
|---|---|---|---|
| `bg-primary-soft` + `text-primary` | **4.01** | **4.37** | `atoms/Badge.types.ts:24`, `Welcome.svelte:145/214/250/292/313/332`, `PendingInvitationList.svelte:59`, `NotificationListItem.svelte:216` |
| `bg-primary/10` + `text-primary` | **4.13** | **4.49** | `pages/Onboarding/Checkout.svelte:229` |
| `bg-success/10` + `text-success` | **4.00** | **4.38** | `Badge.types.ts:26`, `Welcome.svelte:172` |
| `bg-warning/10` + `text-warning` | **4.01** | **4.39** | `Badge.types.ts:27` |
| `bg-tertiary/10` + `text-tertiary` | **4.34** | 4.75 | `Badge.types.ts:25` |

`bg-danger/10` + `text-danger` は 4.98 / 5.45 で通る (danger は 2026-08 に red-600 → red-700 へ
是正済みだったため)。

これは家系の先行事例と同じ現象である — motivation:T194 も「トークン値の変更は発生しない見込み」
という設計を実測が覆し、4 値が 1 段暗く是正された。**同じ轍を踏まないため、本設計は最初から
トークン値の是正を施策に含める**。

### 実読で見つかった副産物 (正典が名指ししていない実害)

1. `text-white` が 3 箇所 (`templates/AppLayout.svelte:299/427`,
   `templates/_helpers/SidebarNavItems.svelte:38`) で使われている。これは Tailwind 既定テーマの
   `--color-white` を参照しており、**本アプリの `@theme` の外**である。ds-purity の
   raw palette 禁止リストに `white` / `black` が入っていないため現在は無検出。
   **i9 (参照の閉包) が本来捕まえるべき形**の実物である。
2. `bg-border` (`atoms/Button.types.ts:25` の neutral variant の hover) が**塗り面として
   テキストを載せている**のに、`border` は現在 `CONTRAST_EXEMPT_TOKENS` (非テキスト 1.4.11) に
   分類されている。役割分類が実装と食い違っている。
3. `text-surface` が 4 箇所で塗り面のラベルとして使われているのに、`surface` は
   `FILL_LABEL_TOKENS` に無い。
4. DESIGN.md §Components に節を持たない部品が 4 本ある
   (`atoms/DragHandle` / `molecules/OrganizationChoiceCard` /
   `molecules/PendingInvitationsNotice` / `molecules/SubtitleOverlay`)。
   i10 が防ぐべき「13 部品事件」と同じ形が既に発生している。

## 改善アイデア

正典 v1 の 22 条件を全数満たす。**足すのは 7 項目**で、すべて「検査が緑なのに穴が開いていた」を
塞ぐ側の追加である。既存の 5 gate は消さず、i21 (読み出しの一本化) を守るために
**共有パーサを 2 本に増やす** (DESIGN.md 側の既存 `design-md.ts` に加え、写像 = tokens.css 側の
読み出しを 1 本へ集約する)。

1. **i13**: 線形化しきい値を `0.04045` へ。errata 追従である旨を gate 本体に書き、
   8bit では判定が変わらないことを負のコントロールで固定する。
2. **i16**: 半透明背景 × 不透明文字の合成検査を新設する。下地は書き手に宣言させず、
   **実在する不透明な下地すべて** (`neutral` / `surface`) の上で 4.5:1 を要求する。
   合成モデル (sRGB の重み付き和・8bit 丸め) を gate 本体に前提として明記する。
   静的に組を決められない形は例外化して素通りさせず、**走査で見つかった半透明の組が
   全件台帳に載る**ことを集合一致で要求する。
3. **トークン値の是正**: 上表の 5 組を通すため、`primary` / `primary-hover` / `tertiary` /
   `tertiary-hover` / `success` / `warning` を 1 段暗くする (`danger` は据え置き)。
   DESIGN.md frontmatter + 本文 + `resources/css/tokens.css` + `docs/design-system.md` を同一 PR で同期する。
   DESIGN.md 本文の「状態色・アクセントは **-700 段**で揃える」という**規約文の改定**を含む。
   値の変更は本文コントラストだけでは後退を検知できないので、**是正対象 6 token の逆引き表**
   (利用箇所 × 状態 (通常 / hover / disabled / 選択) × 前景/背景の組) を詳細設計の付録に持ち、
   レビュー可能な形で残す (走査器の試作で機械的に導出できることは確認済み)。
4. **i15**: 実装 class からの逆向き被覆検査を新設する。走査分母は `resources/js` の
   **ディレクトリ単位の実ファイル走査**で導き、抽出 0 件を遮断する。導出した前景 × 背景の組が
   すべて母集団 (役割の直積 + 個別宣言) の内側にあることを固定する。
   解析できなかった経路は**理由別に集約**し、**出現位置の行番号は固定しない** (s14)。
5. **i9**: 参照の閉包検査を新設する。`resources/css` と `resources/js` が参照する token 名が
   すべて `tokens.css` の `@theme` 宣言集合へ解決すること。解決の根拠を写像 1 か所に限り、
   token を指さない語は理由つきの契約表へ全数登録し、未登録語を不合格にする。
   契約表が扱う対象は 4 系統である —
   (a) テーマ名前空間の class トークン (`bg-*` / `text-*` / `rounded-*` …)、
   (b) `resources/css` と `resources/js` の `var(--…)` 参照
   (実在する 1 件 = `--app-sidebar-w` は同一要素の `style` 属性で宣言する局所変数なので
   理由つきで登録する。**他ファイルのローカル宣言を解決の根拠に数えない**)、
   (c) テーマ名前空間の任意値 class (`text-[13px]` / `bg-[#fff]` 等)、
   (d) Tailwind 既定テーマの色語 (`white` / `black` / raw palette) は
   **写像の外なので不合格**にする (実在する `text-white` 3 箇所がこれで落ちる)。
6. **i10**: DESIGN.md §Components の節と `resources/js/components` の部品ファイルの
   双方向集合一致検査を新設する。走査対象サブディレクトリを全数分類し (未分類は不合格)、
   既定の対応 (節名 = ファイル名) に乗らない対応だけを理由つき申告とし、
   申告表の失効・重複・冗長も検査する。**節を持たない 4 部品は DESIGN.md へ節を足して解消する**。
7. **i12 の残余 / i2 前半**: `renderedLines()` に 4 空白以上の字下げコードの除去を足し、
   `docs/design-system.md` の「落とすのは 2 つ」の記述を同一 PR で訂正する。
   `@theme` ブロックがリポジトリに 1 つだけであることの機械検査を足す。

加えて **i11 の帰結**として、新設 gate を `docs/design-system.md` の責務境界表へ登録する
(登録しないと既存の `design-system-docs.test.ts` が双方向集合一致で落ちる)。

## 期待効果

- **使命への貢献**: soft 背景の Badge は「撮影中 / 完了 / 警告」という**工程の状態表示そのもの**で、
  「思考ゼロ」の前提である「見れば分かる」を担っている。i16 はその可読性が
  **WCAG AA の最低基準 (4.5:1) を満たし続ける**ことを機械で守る。
  現状は実測 4.00〜4.34 で最低基準を割っている。
  なお **AA は通常の閲覧条件の最低基準であり、屋外の環境光での可読性を保証するものではない** —
  屋外での実利用評価は本設計の主張に含めず、別の検証対象として切り出す。
- **静かな劣化の遮断**: i9 は綴り誤りが「無スタイル」として静かに消える経路を塞ぐ。
  i10 は文書に載らない部品が増える形 (家系で実測のある 13 部品事件) を塞ぐ。
  i15 は役割宣言を書かずに新しい前景 × 背景の組を足す経路を塞ぐ。
- **家系への還元**: aicue が i9 / i10 / i13 / i15 / i16 を揃えれば、正典 v1 の全 22 条件を
  満たす**家系初の実装**になる。乖離登録 `D28` の解消可否も再評価できる。

## 実装方針（概要）

### 変更する層

| 層 | ファイル | 変更の性質 |
|---|---|---|
| 正本 | `DESIGN.md` | 色値 6 件 + 本文の色記述 + §状態色の規約文改定 + §Components 冒頭の対象範囲明記 + 部品 4 節の追加 |
| 写像 | `resources/css/tokens.css` | 色値 6 件 + `--color-primary-soft` の rgba 更新 |
| 運用ガイド | `docs/design-system.md` | 責務境界表に新 gate の行 + i12 の記述訂正 + テーマ差し替え手順の注記 |
| 共有パーサ | `tests/js/styles/design-md.ts` (拡張) / `tests/js/styles/theme-map.ts` (新設) | i21 = 正本と写像の読み出しを各 1 実装へ集約 |
| 走査器 | `tests/js/styles/class-usage.ts` (新設) | `resources/js` の class 走査 (i9 / i15 / i16 が共有) |
| 台帳 | `tests/js/styles/inventory.ts` | 役割分類の是正 + 半透明の組の台帳 + 契約表 + 部品対応の申告表 |
| gate | `contrast-invariant.test.ts` (拡張) / `token-reference-closure.test.ts` (新設) / `component-doc-parity.test.ts` (新設) / `canonical-source-parity.test.ts` (拡張) / `design-system-docs.test.ts` (拡張) | i9〜i20 |
| アプリ | `AppLayout.svelte` / `SidebarNavItems.svelte` | `text-white` → `text-surface` (3 箇所) |
| 乖離台帳 | `docs/template-divergence.md` / `LedgerPins.php` / `adoption-debt.tsv` | 共有 2 パスの採用時債務の決着 |

### 共通規約 (AGENTS.md「静的検査 (gate) と走査器の共通規約」) の適用

新設・変更する走査器はすべて 5 条を満たす。**発火条件に該当する** (走査ロジック・走査対象・
名前解決・判定条件・目録のすべてを新設する) ため、同じ PR で 4 点を揃える。

- (a) 名前解決: class トークンは**写像の宣言集合に対する最長一致**で解決する。
  `text-primary` (= 前景色 primary) と DESIGN.md の色キー `text-primary` (= 本文色、
  写像は `--color-text`) は**別物**なので、走査は CSS suffix 空間で行い、
  `COLOR_TOKEN_MAP` の逆写像で正本の値へ渡す。`text-body` 等の ramp と
  `text-center` 等の整列語も同じ接頭辞を共有するため、契約表で分類する。
- (b) fail-closed: 解決できない語は**契約表への未登録**として不合格にする。
  静的に組を決められない半透明の形は素通りさせず理由別の台帳へ入れる。
- (c) 負例で裏取り: 固定の検体で「壊れた形を検出する」と「規定どおりの形を誤検出しない」の
  両方向を固定する (i18)。
- (d) 集めて使わない形を作らない: 診断の集約は必ず判定 (集合一致 / 件数) に使う。
- (e) 語彙一致は**区切り文字で分割したトークンの完全一致**で判定する。区切りの文字集合は
  `ds-purity.ts` の `CLASS_TOKEN_PATTERN` の宣言に合わせ、走査器の docblock で宣言する。
  負例には接頭辞つき (`sm:bg-primary`)・打ち消しつき (`!bg-primary`)・
  接尾辞つき (`bg-primary/10`) の 3 形を置く。

### 半透明の合成の扱い (i16 の設計核心)

- `bg-<token>/<N>` は `color-mix(…, transparent)` へ展開され、透明との混色は
  **同じ色の alpha `N/100`** になる (透明側の乗算済み色が寄与しないため色相・明度は変わらない)。
  この仮定は**推測にせず生成 CSS で固定する** — Tailwind 4.3 の実測形は
  直接の宣言が `color-mix(in srgb, <正本の hex> N%, transparent)`、
  `@supports (color: color-mix(in lab, red, red))` の中が
  `color-mix(in oklab, var(--color-<token>) N%, transparent)` である。
  密閉の層に「不透明度修飾の生成形の契約」を置き、`/10`・派生 token の `/12`・
  二重修飾 (`bg-primary-soft/40` → `color-mix(in srgb, rgba(...) 40%, transparent)`) の
  3 形を固定する。fallback 側は正本の hex をリテラルで埋め込むので、値の突き合わせも兼ねる。
- ブラウザの合成はチャンネルごとの `a*FG + (1-a)*BG` で、実際に描かれるのは **8bit へ丸めた値**。
  丸めまで再現しないと記録値と 0.01 ずれるため、丸めを含めて gate 本体に前提として書く。
- **下地は宣言させない**。下地の集合は**固定配列に書かず、役割分類の「面」から機械導出する**
  (現状は `neutral` / `surface` の 2 件。面を足したら自動で下地が増える = i4 の趣旨)。
  面の**すべて**の上で AA を要求するので、部品がどちらに置かれても成立する。
- **「不透明な背景」と「下地」は別概念である**。`bg-primary` / `bg-danger` / `bg-border` は
  **塗り面** (それ自体が 1 つのコントロール) で、他の部品を載せる面ではない。
  塗り面を下地に含めると `bg-primary-soft` on `bg-primary` = 比 1.0 となり
  **どの値を選んでも成立しない**ので、検査として実行不能である。
  塗り面の上に半透明背景が載る形は、**静的に見える範囲では判定不能分類で落とし**
  (同じ状態に塗り面の背景と半透明の背景が同居する単位)、
  静的に追えない親要素については **DESIGN.md の規約行**
  (「ソフト背景の部品は面 = `neutral` / `surface` の上にのみ置く」) で受け、
  機械で保証しないことを gate 本体の「保証しないもの」に書く (i22 (2))。
- `--color-primary-soft` は `rgba(..., 0.12)` を**値として持つ派生 token** であり、
  `bg-primary-soft` は「alpha 0.12 の背景」として扱う。
- **判定不能の 5 分類**(素通りさせない): 前景にも alpha /
  alpha の二重 (`bg-primary-soft/40`) / `bg-transparent` 等のキーワード背景 /
  同じ宣言に前景を持たない alpha 背景 / 要素全体の不透明度指定 (`opacity-*`)。
  **半透明が関わる判定不能は「(ファイル, 分類) の実体集合の一致」で固定する**
  (件数だけを固定すると、新しい未解析の使用を件数更新で通す誘惑が残る)。
  **行番号は持たない** (s14 — 無関係な 1 行の追加で期待値の機械的な更新が常態化する)。

### 走査の単位 (i15 の設計核心)

class の記述は「1 つの状態」を表すとは限らない。`"bg-surface text-danger hover:bg-danger
hover:text-neutral"` を素朴に直積すると `text-danger on bg-danger` (比 1.0) という
**実在しない組**が生まれる。そこで走査は **CSS の段階付けに合わせた「状態」単位**で組を作る:

- 素の (修飾なしの) 前景・背景を**基底の状態**とする
- 同じ修飾の連なり (`hover:` / `focus-visible:` / `disabled:` …) を持つ宣言は、
  基底を**その修飾で上書きした状態**を作る
- 組は**状態の内側だけ**で作る

この単位なら上例は `(danger, surface)` と `(neutral, danger)` の 2 組になり、実在する組だけが出る。
条件分岐の各枝が別の文字列リテラルに分かれている書き方も、リテラル単位で状態を作れば正しく分かれる。

### 走査対象構文の全数分類 (i15 / i16 / i9 共通の fail-closed 規則)

「解析できなかった経路を件数だけ数える」形は fail-open になる。そこで**走査対象の構文を宣言し、
範囲内で解決できない語は必ず落とす**。実コードを実読して、扱うべき形と実在しない形を切り分けた。

| 形 | 実在 | 扱い |
|---|---|---|
| 文字列リテラル (`'…'` / `"…"` / バッククォート囲み) 中の class 記述 | 多数 | **走査単位**。単位の中だけで状態と組を作る |
| 配列 + `.filter(Boolean).join(" ")` の合成 | 11 箇所 | 各要素が別の単位。要素をまたぐ組は作らない (下記の保証外) |
| `clsx` / `twMerge` / `classnames` | **0 件** (依存も無い) | 出現したら未知の構文として落ちる (下記 (2)) |
| Svelte の `class:foo={expr}` ディレクティブ | **0 件** | 同上 |
| テンプレート補間で色 class を組む (`` `bg-${tone}` ``) | **0 件** | 同上 |
| 補間で**完成した class 文字列**を差し込む (`` `${border} bg-neutral` ``) | 1 件 (`atoms/input-state.ts`) | 判定不能分類 (補間を含む単位) |
| 親から渡る class (`extraClass`) | 全 atom | i22 (2) の保証外 (部品の境界をまたぐ) |

規則:

1. **走査単位を宣言する** (上表の 1 行目)。区切り文字の集合は `ds-purity.ts` の
   `CLASS_TOKEN_PATTERN` の宣言に合わせ、走査器の docblock で宣言する (共通規約 (e))。
2. **範囲内で解決できない語は不合格にする**。テーマの名前空間の接頭辞
   (`bg-` / `text-` / `border-` / `ring-` / `divide-` / `outline-` / `decoration-` /
   `accent-` / `fill-` / `stroke-` / `rounded-`) を持つ語が写像の宣言集合へ解決せず、
   契約表にも登録が無ければ**落とす**。抽出 0 件遮断だけに頼らない (共通規約 (b))。
3. **補間を含む単位は素通りさせない**。`${` を含み、かつテーマ名前空間の語を含む単位は
   判定不能として分類する。
4. **不完全な単位 (前景か背景の片方しか無い) の扱いを 2 段に分ける**。
   - 半透明が関わるもの → **(ファイル, 分類) の実体集合の一致**で固定する (i16 の要求)
   - 不透明のみのもの → **下限 (0 でないこと) と分類の全数性**で受ける。
     `bg-surface` 単独が 39 単位・`bg-neutral` 単独が 20 単位あり、実体集合で pin すると
     期待値の機械的な更新が常態化して統制が形骸化する (s14 と同じ理由)。
     加えて不透明のみの組は i14 の役割直積が母集団として覆っている
5. **保証しない範囲を gate 本体に書く** (i22)。宣言の単位をまたいで成立する組
   (実例: `atoms/input-state.ts` は `text-text` と `bg-surface` が別の単位に割れている)、
   親から渡る class、親要素から継承する背景、動的に組み立てた class。
   ただしこの穴の大部分は i14 の役割直積が塞いでいる —
   **両方の token に役割が在れば、その組は宣言が割れていても既に母集団の内側にある**。
   見えないのは「直積に現れない役割の組み合わせの 2 token が同じ要素に載り、
   かつ宣言の単位が割れている」場合だけで、この限定を docblock に明記する。

### 型の方針 (PHPStan ではなく TypeScript 側の閉じ方)

台帳・契約表・解決結果を文字列配列や `Record<string, string>` に寄せると、
token 種別・前景/背景の役割・alpha の有無・例外理由の取り違えがコンパイル時に見えなくなる。

- class トークンの解決結果は **discriminated union** で表す
  (`{ kind: "color"; role: …; suffix: …; alpha: number | null }` /
  `{ kind: "ramp" }` / `{ kind: "radius" }` / `{ kind: "contract"; reason: string }` /
  `{ kind: "unresolved"; token: string }`)。
- 判定不能の理由も union にし、分類の網羅を `never` へ収束させる
  (`switch` の default で `never` を要求する)。
- 台帳・契約表は `as const satisfies` で宣言する
  (既存の `FILE_SCOPED_ALLOWLIST` / `FrontmatterSectionOwner` が同じ形の先例)。
- 走査器の入出力は `unknown` から検証して narrow する形にはしない
  (入力はファイル文字列で、外部 JSON を読まないため。過剰設計を避ける)。

## 制約・前提

- **正典に含まれないもの**: テーマ値そのもの (色・ブランド) は i1 によりプロジェクト裁量である。
  本設計のトークン値の是正は「正典が要求する不変条件 (i16) を満たすための帰結」であって、
  正典が値を定めているわけではない。したがって**規約文の改定という設計判断として記録する**。
- **既存テストは削除・上書きしない** (禁止事項)。`PENDING_CONTRAST_PAIRS` は
  i17 (非テキスト 1.4.11) と判定不能の 5 分類が残るので**空にならない**。
  よって「pending が空でない」テストも据え置く。
- **共有パスの採用時債務**: `docs/design-system.md` と
  `tests/js/architecture/contrast-invariant.test.ts` は `adoption-debt.tsv` に
  採用時 sha256 で凍結されている (現況は採用時の姿のまま)。本設計はどちらも変更するため、
  突合 gate の `mutatedDebtPaths` で赤くなる。**意図的逸脱として登録を書き、債務一覧から削る**。
  `tests/js/support/ds-purity.ts` も債務パスなので**触らない** (`white` / `black` を
  raw palette 禁止に足す案は採らない。i9 の閉包が同じ穴を塞ぐ)。
- **`resources/views/vendor/mail/html/themes/template.css`** は Laravel 同梱メールテーマの
  独立パレットで DS token の写像ではない。i9 / i15 / i16 の走査対象外であることを宣言する
  (既に contrast gate の docblock が同じ線引きを持つ)。
- **PHP 側は 1 行も変えない**。本作業は TS / CSS / Markdown と乖離台帳のみ。
  PHPStan / Pest の母集団は変わらない。

## スコープ外

- **q1 (写像を照合から生成へ移す)**: 正典の未決論点。生成の入力へ切り出す実測が家系に無いので着手しない。
- **q2 (非テキスト 3:1 の 1.4.11)**: i17 により本 feature の対象外。
  `border` / `border-strong` の 1.4.11 判定は入れない
  (`border` は塗り面としての役割だけを是正する)。
- **q3 (広色域の実描画との厳密一致)**: 家系に実測 0 件。合成モデルを gate 本体の前提として
  書き残すことで足りる (正典 v1 の決定どおり)。
- **`DESIGN.md` frontmatter の `spacing:`**: 既に `FRONTMATTER_SECTION_OWNERS` の `pending` として
  理由・解消条件・追跡先つきで宣言されている。本作業では解消しない (追跡先の
  devnotes 参照が生きていることだけを維持する)。
- **`ds-purity.ts` の禁止パターンの拡張**: 債務パスであり、かつ i9 が同じ穴を塞ぐため触らない。
- **テンプレートへの還元 (逆同期)**: 家系の巡回の責務。本リポジトリでは乖離登録までで完了とする。
