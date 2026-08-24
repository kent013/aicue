## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


## 禁止事項

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

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【補足コンテキスト】
本件は「lctl 機能台帳」という家系 (6 リポジトリ) 共通の設計台帳が確定させた正典 v1 (不変条件 i1〜i22) への追従作業である。正典は「問いの集合」で定義され、検査ファイルの分割・命名・テーマ値そのものは正典に含まれない。aicue は正典 v1 に 7 条件を提供した側で、欠けているのは i9 / i10 / i13 / i15 / i16 と i12 の残余・i2 の前半である。変更は TypeScript の検査 (vitest) / CSS トークン / Markdown 文書 / 乖離台帳に限られ、PHP は 1 行も変えない。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

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
4. **i15**: 実装 class からの逆向き被覆検査を新設する。走査分母は `resources/js` の
   **ディレクトリ単位の実ファイル走査**で導き、抽出 0 件を遮断する。導出した前景 × 背景の組が
   すべて母集団 (役割の直積 + 個別宣言) の内側にあることを固定する。
   解析できなかった経路は**理由別に集約**し、**出現位置の行番号は固定しない** (s14)。
5. **i9**: 参照の閉包検査を新設する。`resources/css` と `resources/js` が参照する token 名が
   すべて `tokens.css` の `@theme` 宣言集合へ解決すること。解決の根拠を写像 1 か所に限り、
   token を指さない語は理由つきの契約表へ全数登録し、未登録語を不合格にする。
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

- **使命への貢献**: 撮影 PWA は現場作業者が屋外のスマホで使う面であり、状態色と本文が読めることが
  業務の前提である。soft 背景の Badge は「撮影中 / 完了 / 警告」という**工程の状態表示そのもの**で、
  実測 4.00〜4.34 は屋外の環境光で読めない。i16 は「思考ゼロ」の前提である
  「見れば分かる」を機械で守る。
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

- `bg-<token>/<N>` は `color-mix(in oklab, var(--color-<token>) N%, transparent)` へ展開され、
  透明との混色は**同じ色の alpha `N/100`** になる (仕様)。
- ブラウザの合成はチャンネルごとの `a*FG + (1-a)*BG` で、実際に描かれるのは **8bit へ丸めた値**。
  丸めまで再現しないと記録値と 0.01 ずれるため、丸めを含めて gate 本体に前提として書く。
- **下地は宣言させない**。実在する不透明な下地 (`neutral` / `surface`) の**両方**で AA を
  要求するので、部品がどちらに置かれても成立する。
- `--color-primary-soft` は `rgba(..., 0.12)` を**値として持つ派生 token** であり、
  `bg-primary-soft` は「alpha 0.12 の背景」として扱う。
- **判定不能の 5 分類**(素通りさせない): 前景にも alpha /
  alpha の二重 (`bg-primary-soft/40`) / `bg-transparent` 等のキーワード背景 /
  同じ宣言に前景を持たない alpha 背景 / 要素全体の不透明度指定 (`opacity-*`)。
  分類ごとに件数を台帳へ持つ。

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

## 参考: 実測記録

# 実測記録: 合成コントラスト (i16) と是正後の値

計算モデル (正典 i16 / q3 の前提):

- 半透明の背景は `color-mix(in oklab, var(--color-X) N%, transparent)` へ展開され、
  透明との混色は**同じ色の alpha `N/100`** になる。
- 合成はチャンネルごとの `a*FG + (1-a)*BG` で、**8bit へ丸めた値**が実際に描かれる。
- 相対輝度の線形化しきい値は **0.04045** (WCAG 2.0/2.1 本文の 0.03928 は 2022-02-22 errata で訂正済み)。
- 下地は推論しない。**実在する不透明な下地すべて** (`neutral` #F4F4F5 / `surface` #FFFFFF) の
  両方で 4.5:1 を要求する。

再現スクリプト: `measure.py` (設計時の一時スクリプト。恒久化しない — 恒久の判定は gate が持つ)。

## 是正の内容

| token | BEFORE | AFTER | Tailwind の段 |
|---|---|---|---|
| `primary` | `#2563EB` | `#1D4ED8` | blue-600 → blue-700 |
| `primary-hover` | `#1D4ED8` | `#1E40AF` | blue-700 → blue-800 |
| `tertiary` | `#0F766E` | `#115E59` | teal-700 → teal-800 |
| `tertiary-hover` | `#115E59` | `#134E4A` | teal-800 → teal-900 |
| `success` | `#15803D` | `#166534` | green-700 → green-800 |
| `warning` | `#B45309` | `#92400E` | amber-700 → amber-800 |
| `danger` | `#B91C1C` | `#B91C1C` | red-700 (据え置き。soft でも 4.98 で足りる) |
| `--color-primary-soft` | `rgba(37, 99, 235, 0.12)` | `rgba(29, 78, 216, 0.12)` | primary の 12% (追従) |

家系の先行事例 (motivation:T194) は success green-700 → green-800、warning amber-700 → amber-800、
tertiary teal-700 → teal-800、tertiary-hover teal-800 → teal-900 と**同じ方向・同じ段**へ動いている。

## 走査で実在が確認された組に対する実測

    ===== BEFORE =====
    -- 不透明ペア --
      text-danger          on bg-surface         =  6.47 
      text-neutral         on bg-danger          =  5.89 
      text-neutral         on bg-primary         =  4.70 
      text-neutral         on bg-primary-hover   =  6.10 
      text-neutral         on bg-success         =  4.56 
      text-neutral         on bg-tertiary        =  4.98 
      text-neutral         on bg-tertiary-hover  =  6.90 
      text-text            on bg-border          = 13.96 
      text-text            on bg-neutral         = 16.12 
      text-text            on bg-surface         = 17.72 
      text-text-secondary  on bg-neutral         =  7.03 
      text-text-secondary  on bg-surface         =  7.73 
      text-surface         on bg-primary         =  5.17 
    -- 半透明背景 × 不透明前景 (下地 neutral / surface の両方) --
      text-danger          on bg-danger      / 10 =  4.98 /  5.45 
      text-primary         on bg-primary     / 10 =  4.13 /  4.49 NG
      text-primary         on bg-primary     / 12 =  4.01 /  4.37 NG
      text-success         on bg-success     / 10 =  4.00 /  4.38 NG
      text-surface         on bg-text        / 70 =  6.88 /  6.57 
      text-tertiary        on bg-tertiary    / 10 =  4.34 /  4.75 NG
      text-text            on bg-danger      / 10 = 13.62 / 14.93 
      text-text            on bg-primary     / 12 = 13.76 / 14.97 
      text-text            on bg-surface     / 80 = 17.42 / 17.72 
      text-text            on bg-warning     / 10 = 14.15 / 15.49 
      text-text-secondary  on bg-surface     / 80 =  7.60 /  7.73 
      text-warning         on bg-warning     / 10 =  4.01 /  4.39 NG
    ===== AFTER =====
    -- 不透明ペア --
      text-danger          on bg-surface         =  6.47 
      text-neutral         on bg-danger          =  5.89 
      text-neutral         on bg-primary         =  6.10 
      text-neutral         on bg-primary-hover   =  7.94 
      text-neutral         on bg-success         =  6.49 
      text-neutral         on bg-tertiary        =  6.90 
      text-neutral         on bg-tertiary-hover  =  8.62 
      text-text            on bg-border          = 13.96 
      text-text            on bg-neutral         = 16.12 
      text-text            on bg-surface         = 17.72 
      text-text-secondary  on bg-neutral         =  7.03 
      text-text-secondary  on bg-surface         =  7.73 
      text-surface         on bg-primary         =  6.70 
    -- 半透明背景 × 不透明前景 (下地 neutral / surface の両方) --
      text-danger          on bg-danger      / 10 =  4.98 /  5.45 
      text-primary         on bg-primary     / 10 =  5.23 /  5.72 
      text-primary         on bg-primary     / 12 =  5.08 /  5.57 
      text-success         on bg-success     / 10 =  5.61 /  6.14 
      text-surface         on bg-text        / 70 =  6.88 /  6.57 
      text-tertiary        on bg-tertiary    / 10 =  5.93 /  6.49 
      text-text            on bg-danger      / 10 = 13.62 / 14.93 
      text-text            on bg-primary     / 12 = 13.44 / 14.72 
      text-text            on bg-surface     / 80 = 17.42 / 17.72 
      text-text            on bg-warning     / 10 = 13.86 / 15.18 
      text-text-secondary  on bg-surface     / 80 =  7.60 /  7.73 
      text-warning         on bg-warning     / 10 =  5.55 /  6.08 
