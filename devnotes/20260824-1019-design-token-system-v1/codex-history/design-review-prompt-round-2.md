# アプリの使命（North Star）

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。
# 禁止事項

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

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠（UI/frontend 変更を含む場合）: `/DESIGN.md` が design token の canonical source。color / radius / typography を token 経由で参照する設計か、hex 直書きを増やさないか。token 変更時は `resources/css/tokens.css` との同期を設計に織り込んでいるか（運用契約は `docs/design-system.md`）
11. Atomic Design準拠（UI/frontend 変更を含む場合）: `resources/js/components/` の `atoms/molecules/organisms/templates` の責務分離に沿った配置か。atom は単機能・無状態、molecule は atom の組合せという階層を逆流していないか。アイコンは Lucide 前提で、SVG 直書きを新設していないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

# これは Round 2 である（重要）

Round 1 のレビューセッションは復元できなかったため、**新しいセッションで Round 2 を行う**。
Round 1 の指摘全文と、Claude 側の対応マトリクス（全件対応・反論 0 件）を下に添付する。
本文の詳細設計書は **Round 1 の指摘をすべて反映した改訂版**である。

レビューでは次の 2 点を重点的に見てほしい:
1. Round 1 の Critical 9 件・Warning 11 件が**実際に解消されているか**（対応が字面だけで、
   同じ穴が別の形で残っていないか）
2. 改訂で**新たに入り込んだ**矛盾・実装不能・検出力の穴

---

# 添付 A: Round 1 のレビュー全文

## レビュー前提

仮説は「各 gate が、解析不能・母集団追加・正本外参照を必ず赤にし、負のコントロールが実装本体を直接検証しているなら、正典 i1〜i22 に追従できる」です。

提供テキストのみで検証しました。コマンド実行・ファイル読み込み・書き込みは行っていません。設計書で言及された `app-design` スキルはこのセッションでは提供されていないため、記載された規約を直接レビュー基準として扱いました。

## 施策別判定

### S1 — REQUEST_CHANGES

[Warning] 固定検体を解析する入口が公開 API にありません。提示 API は実ファイルを直接読む `themeBlocks()` 等だけなので、`theme-map.test.ts` の任意入力を検査できません。

修正案: 純粋関数 `parseThemeMap(source, file)` を唯一の解析実装とし、実ファイル用関数はその薄いラッパーにしてください。固定検体も同じ純粋関数へ渡します。

[Warning] `@theme` の検出について、コメント・文字列・トップレベル判定の仕様が不足しています。単純な正規表現ではコメント中の `@theme` を拾う可能性があります。

修正案: コメント除去を含む字句走査を定義し、コメント内の `@theme`、トップレベル、ネスト、閉じないブロックを固定検体へ追加してください。

---

### S2 — REQUEST_CHANGES

[Critical] `ClassUsageScan` に全 class token の出力がありません。S3 は `border-*`、`ring-*`、radius、ramp、非 token 語を含む全参照を検査する必要がありますが、公開結果は pair・不完全数・未解決語だけです。このままではS3が2本目の走査器を書くことになります。

修正案: `ClassTokenOccurrence { file, literalId, raw, variants, important, utility, alpha }` のような共通出力を追加し、S3/S5/S7が同じ抽出結果から導出する構造にしてください。

[Critical] 走査根が `components/pages/lib` に固定され、docblockの「resources/js の走査分母」と一致しません。`resources/js/app.ts` や将来追加されるディレクトリから迂回できます。

修正案: `resources/js` 全体を再帰走査し、対象拡張子と除外対象を分類表から導出してください。新しい直下ディレクトリ・拡張子は未分類として失敗させます。

[Critical] 「同じ channel の不透明宣言が複数あれば判定不能」とありますが、`UndecidableReason` に対応する値がありません。設計どおり実装できない型定義です。

修正案: `multiple-foreground` / `multiple-background` を追加するか、勝敗を確定できる正式なモデルを設けてください。

[Warning] 状態の継承を固定する負例が不足しています。提示例は hover で前景・背景の両方を上書きするため、「片方だけ上書きした状態が基底のもう片方を継承する」実装不良を検出できません。

修正案: `text-text hover:bg-danger` と `bg-surface hover:text-danger` の期待ペアを追加してください。

[Warning] `incompleteOpaque.backgroundOnly > 0` などは安全不変条件ではなく現状件数です。コード改善で0件になった正常状態を赤にします。

修正案: 抽出器の固定検体で各分類分岐を点灯させ、実リポジトリに「不完全単位が必ず存在する」とは要求しないでください。

---

### S3 — REQUEST_CHANGES

[Critical] 契約表の完全一致方針と、`sm:text-center` / `!text-center` / `text-center/50` を同じ語として扱う計画が矛盾しています。特に `text-center/50` は正当な opacity 修飾ではなく、これを `text-center` として通すと未知 utility が静かに通ります。

修正案:

- variant、important、opacityを別々に解析する
- opacityは色 utilityにだけ許可する
- `NON_TOKEN_WORD_CONTRACT` は正規化前の有効な完全 token を対象にする
- `text-center/50` は負例として不合格にする

[Warning] `--app-sidebar-w` を class語と `var()` 参照で共通の無型契約表に入れると、別チャネルでの出現によって登録が生きているように見える恐れがあります。

修正案: 契約を `kind: "class-word" | "css-variable"` で判別可能にし、出現・冗長性をチャネル別に突き合わせてください。

---

### S4 — REQUEST_CHANGES

[Critical] 追加テストは `linearize()` を呼んでいないため、実装が0.03928のままでも緑です。正典 i13 の0.04045を固定できません。

修正案: 正規化済みの小数チャンネルを受ける純粋関数を切り出し、両しきい値の間にある `0.04` などで0.04045側の既知値を検査してください。8bit全値で結果が同じというテストは補助検査として残せます。

---

### S10 — REQUEST_CHANGES

[Warning] `designColors().get("primary")` と `cssColorTokens().get(...)` は `undefined` を返し得ます。文字列補間によって `"undefined"` になり、意図した解析失敗になりません。

修正案: `requiredMapValue(map, key)` のような例外を投げる共通ヘルパを使ってください。

[Critical] `primary-soft` が「primaryのRGBを12%」であることを固定していません。現状の値免除と提示テストでは、別のrgba値へ変わっても生成CSSとコントラストが偶然通れば検出できません。

修正案: rgbaを厳密に解析し、RGBが正本のprimaryと一致し、alphaが0.12であることを検査してください。二重修飾はその解析結果から実効alphaも検証します。

---

### S5 — REQUEST_CHANGES

[Critical] `bg-primary-soft` はCSS値自体にalphaを持ちますが、`ColorUse.alpha` は「修飾のalpha」としか定義されていません。さらに合成関数は `alphaHex` を要求する一方、実値は `rgba(...)` です。派生tokenをどのようにRGB＋alphaへ解決するかが欠けています。

修正案: 色を次の判別可能型へ正規化してください。

```ts
type ParsedColor =
    | { kind: "opaque"; rgb: Rgb }
    | { kind: "alpha"; rgb: Rgb; alpha: number };
```

class修飾のalphaとは別に保持し、合成時に統合してください。

[Critical] `bg-primary-soft/40` は静的に決定可能です。実効alphaは原則 `0.12 × 0.40` であり、これを `double-alpha` として判定不能台帳へ逃がすのは「静的に決められない形だけを例外にする」という i16 に反します。

修正案: S10で生成形を固定したうえで、二重alphaを合成対象として計算してください。本当に解析不能なCSS色表現だけを例外にします。

[Critical] `UNDECIDABLE_PAIR_LEDGER` の識別子が `(file, reason)` だけです。同じファイルに同じ理由の未解析箇所が増えても集合が変わらず、追加を検出できません。

修正案: 行番号を使わず、`file + reason + normalized literal/state/token` を安定識別子にするか、`file + reason + count` を完全一致で固定してください。

[Warning] 「実際に描かれるのは8bitへ丸めた値」という前提はブラウザ描画一般の保証としては強すぎます。

修正案: 「本gateが採用する近似モデル」と表現し、浮動小数合成との差で4.5境界を跨ぐペアがないことを別検査してください。

---

### S6 — REQUEST_CHANGES

値の選択と実測余裕自体は妥当です。

ただし [Warning] `--color-primary-soft` のprimary追随が機械保証されないため、「両方を直さないと赤」という説明が派生tokenについて成立しません。

修正案: S10にprimary-softのRGB・alpha同一性検査を追加したうえで実施してください。

[Suggestion] ブランド色変更は主要PWA画面の視覚確認対象を明記すると、逆引き表の目視確認が再現可能になります。

---

### S7 — REQUEST_CHANGES

[Critical] `DECLARED_CONTRAST_PAIRS` に現れたtokenを「役割分類済み」と数す設計は、役割分類の既定拒否を迂回できます。任意の新tokenを1組だけ登録すれば全色被覆を通せます。

また、`border` は「非テキスト境界」と「テキストを載せるhover背景」の複数用途を持ちます。免除と検査対象をtoken単位で排他にするのは、別物の用途を統合しています。

修正案: tokenごとの用途を複数分類できる台帳にしてください。例:

```ts
border: ["non-text-boundary", "declared-text-background"]
```

全tokenの役割分類と、個別ペアの妥当性は別の集合一致で検査します。

[Warning] 実施順はS7→S3ですが、S7のテスト計画はS3後に現れる `(surface, primary)` の赤を前提にしています。

修正案: S3→S7へ入れ替えるか、S7をborder分類とsurface分類に分割してください。

---

### S8 — REQUEST_CHANGES

[Warning] サブディレクトリ分類の再帰境界が不明です。`features` を除外した後にその子を走査しないのか、`atoms/icons` のような例外だけ再帰するのかが実装者依存になります。

修正案: 「直下を分類し、excludedでは再帰停止、documentedでは直下ファイルのみ」のように探索規則を明記し、未分類のネストを固定検体で落としてください。

[Warning] DragHandleの「disabledにしない」を禁止事項8の帰結とするのは過剰です。禁止事項8は「必須条件未充足を理由にボタンをdisabledにする」ことを禁じており、全コントロールのdisabledを禁止していません。

修正案: 「並べ替え不可時の表現は別途定義し、禁止事項8を一般的disabled禁止として扱わない」に訂正してください。

---

### S9 — REQUEST_CHANGES

[Critical] 状態遷移とテスト計画が矛盾しています。

- 実装説明: 直前の描画行が空行のときだけ字下げコード開始
- テスト説明: 空行なしの4空白行も落とす

両方を同時には満たせません。

修正案: CommonMark上で描画されないものを落とすのが目的なら、可能ならCommonMark parserのASTとsource rangeを利用してください。独自状態機械を維持する場合は、段落・リスト・空行の扱いを一意に定め、少なくとも次を別々に固定します。

- 空行後のindented code
- 段落継続行
- リスト配下のindented content
- code区間内のfence文字列
- EOFまで閉じない区間
- 行数保存

---

### S11 — REQUEST_CHANGES

[Critical] 本数が算術的に誤っています。既存4本に新規3本を追加するため、責務境界表は6本ではなく7本です。

修正案: 「4本→7本」に訂正してください。可能なら説明中の固定数自体をやめ、「下表の全検査」と表現し、表の双方向集合一致だけを正本にしてください。

---

### S12 — REQUEST_CHANGES

[Warning] D50の説明はコントラスト・逆向き被覆だけですが、`docs/design-system.md` には責務表、Markdown非描画領域、component parity、運用契約という別の変更理由も入ります。パス単位で採用時債務を解除するのに、登録理由が変更全体を説明していません。

修正案: D50の説明・再判定条件を共有文書の全変更へ広げるか、論理的に別の逸脱なら複数エントリへ分けてください。S4/S5/S7/S9/S11の修正後に保証文も同期させます。

## 横断評価

- PHPStan・DTO/JsonResource・Inertia Props・DB・tenant認可への直接変更はなく、この範囲の問題は見当たりません。
- Atomic Design上、新規アプリcomponentはありません。ただしS8のディレクトリ分類規則は明確化が必要です。
- 最大の後退リスクは、正規表現ベースのclass/CSS解析が解析不能を検出できず「候補なし」として落とすことです。
- テストファーストの意図は明確ですが、S4のように本体を呼ばない負例や、S9のように仕様と矛盾する負例は i18 の裏取りになりません。

## 全体判定

**CHANGES_REQUESTED**

特にブロッキングなのは、S2の共通走査出力と母集団、S4のしきい値を実装へ結び付ける検査、S5の派生alpha・二重alpha・台帳識別子、S7の用途分類、S9のMarkdown状態遷移、S11の検査本数です。これらを直せば、正典v1へ追従する方向性そのものは妥当です。
---

# 添付 B: Claude 側の対応マトリクス（Round 1）

# 対応マトリクス: design-review Round 1

Critical 9 件・Warning 11 件・Suggestion 1 件。**すべて対応する** (反論は 0 件)。
指摘はいずれも実装可能性か検出力の穴を正確に突いていた。

## S1

### [Warning] 固定検体を解析する入口が公開 API に無い

- 判断: **対応する**
- 対応内容: **純粋関数 `parseThemeMap(source, file)` を唯一の解析実装**にし、
  実ファイル用の関数はその薄いラッパーにした。固定検体も同じ純粋関数へ渡す。
  `design-md.ts` が実ファイルを module 初期化で読む形になっているのは既存の負債だが、
  本 PR で新設する側は純粋関数を正本にする。

### [Warning] `@theme` の検出でコメント・文字列・トップレベル判定の仕様が不足

- 判断: **対応する**
- 対応内容: **コメント除去を含む字句走査**を仕様として明記し、固定検体に
  (a) コメント中の `@theme`、(b) トップレベルの `@theme`、(c) 条件つき at-rule の中の `@theme`、
  (d) 閉じないブロック、(e) 入れ子の `{}` を含む宣言 の 5 形を置く。
  (d)(e) は**例外** (i20 = 解析の失敗を pass に変えない)。

## S2

### [Critical] `ClassUsageScan` に全 class token の出力が無い → S3 が 2 本目の走査器を書くことになる

- 判断: **対応する** (i21 に直接反する指摘で、最も重い)
- 対応内容: 共通出力 `ClassTokenOccurrence`
  (`file` / `unit` / `raw` / `variants` / `important` / `utility` / `alpha` / `resolution`) を新設し、
  S3 (閉包) / S5 (合成) / S7 (逆向き被覆) が**同じ抽出結果から導出する**形にした。
  `resolution` は判別可能 union (`color` / `ramp` / `radius` / `contract` / `unresolved`)。
  `var(--…)` 参照も同じ走査器 (`scanCssVarReferences()`) が返す。

### [Critical] 走査根が `components` / `pages` / `lib` 固定で docblock の「resources/js」と一致しない

- 判断: **対応する**
- 根拠: 実測すると `resources/js` 直下は `app.ts` / `inertia.ts` / `vite-env.d.ts` /
  `components/` / `lib/` / `pages/` / `types/` で、**固定 3 根は 4 つを取り落としている**。
- 対応内容: `resources/js` を**全体再帰走査**し、
  **拡張子の全数分類** (`.svelte` / `.ts` を走査 / `.gitkeep` は理由つきで無視 / 未分類は不合格) と
  **直下の子の全数分類** (新しい直下ディレクトリが現れたら不合格) を置いた。
  さらに実測に基づき、抽出があることを要求する子を明示した —
  `components` / `pages` は要求、`lib` / `types` / 直下ファイルは**要求しない**
  (実測でテーマ名前空間の class トークンが 0 件。要求すると正常な状態を赤にする)。

### [Critical] 「同じ channel の不透明宣言が複数あれば判定不能」に対応する `UndecidableReason` が無い

- 判断: **対応する**
- 対応内容: `multiple-foreground` / `multiple-background` を値域へ追加した。

### [Warning] 状態の継承を固定する負例が不足 (片方だけ上書きした場合)

- 判断: **対応する**
- 対応内容: 負例へ `"text-text hover:bg-danger"` (背景だけ上書き) と
  `"bg-surface hover:text-danger"` (前景だけ上書き) の期待ペアを追加した。

### [Warning] `incompleteOpaque > 0` は安全不変条件ではなく現状件数

- 判断: **対応する** (正しい指摘。コードが良くなって 0 件になった正常状態を赤にする形だった)
- 対応内容: 分類分岐の点灯は**固定検体**で確かめる形に移し、
  実リポジトリに対しては「不完全な単位が存在すること」を要求しない。
  実リポジトリに対して要求するのは
  「走査単位が 0 でない」「`components` / `pages` から抽出がある」だけにした。

## S3

### [Critical] 契約表の完全一致方針と `text-center/50` を同じ語として扱う計画が矛盾

- 判断: **対応する** (概念設計の共通規約 (e) の適用を誤っていた)
- 対応内容: 解析を 3 段に分ける —
  **変種 (`sm:`) / 重要度 (`!`) / 不透明度 (`/NN`) をそれぞれ独立に解析**する。
  **不透明度修飾は色 utility にだけ許す** (`text-center/50` は
  `unresolved: "alpha-on-non-color"` で**不合格**)。
  契約表は**正規化後の有効な完全 token** を対象にする。
  負例は「`sm:text-center` と `!text-center` は誤検出しない / `text-center/50` は不合格」の
  3 形に直した (元の「3 形すべて解決する」は誤り)。

### [Warning] `--app-sidebar-w` を class 語と `var()` 参照で共通の無型契約表に入れると登録が生きて見える

- 判断: **対応する**
- 対応内容: 契約表を `kind: "class-word" | "css-variable"` の判別可能な形にし、
  出現の突き合わせと冗長判定を**チャネル別**に行う。

## S4

### [Critical] 追加テストが `linearize()` を呼んでいないので 0.03928 のままでも緑

- 判断: **対応する** (i18 の裏取りになっていないという指摘が正しい)
- 根拠: 実測すると、2 つのしきい値の**間**にある `c = 0.04` で
  0.03928 実装は `pow` 枝 = `0.0030954995810608932`、
  0.04045 実装は線形枝 = `0.0030959752321981426` になり、**値が違う**。
- 対応内容: **正規化済みチャンネル (0..1) を受ける純粋関数 `linearizeChannel()` を切り出し**、
  `expect(linearizeChannel(0.04)).toBe(0.04 / 12.92)` で既知値を固定する
  (0.03928 実装ならこの `toBe` が落ちる)。
  8bit 全値で結果が同じという検査は**補助**として残す。

## S10

### [Warning] `Map#get` の `undefined` が文字列補間で `"undefined"` になる

- 判断: **対応する**
- 対応内容: `requiredMapValue(map, key, label)` (不在で例外) を共通ヘルパにし、
  期待値の組み立てはすべてこれを通す。

### [Critical] `primary-soft` が「primary の RGB を 12%」であることを固定していない

- 判断: **対応する** (`COMPILED_VALUE_EXEMPT_TOKENS` の値免除の穴を突いた指摘)
- 対応内容: `rgba(...)` を厳密に解析し、
  **RGB が正本の `primary` と一致すること**と **alpha が 0.12 であること**を検査する。
  二重修飾の実効 alpha もこの解析結果から検証する。
  これで「値免除」は「DESIGN.md に期待値が無い」ことの表明にとどまり、
  **派生の導出関係は機械で固定される** (S6 の Warning も同時に解消する)。

## S5

### [Critical] 派生 token の色を RGB + alpha へ正規化する経路が無い

- 判断: **対応する**
- 対応内容: 色を判別可能型へ正規化する層を入れた。

  ```ts
  type ParsedColor =
      | { kind: "opaque"; rgb: Rgb }
      | { kind: "alpha"; rgb: Rgb; alpha: number };
  ```

  class 修飾の alpha は**別に保持**し、合成時に `alpha_effective = colorAlpha * modifierAlpha` で統合する。

### [Critical] `bg-primary-soft/40` は静的に決定可能なので `double-alpha` へ逃がすのは i16 に反する

- 判断: **対応する** (正しい。i16 は「静的に決められない形」だけを例外にすると定めている)
- 根拠: 実測した生成形は `color-mix(in srgb, rgba(29, 78, 216, 0.12) 40%, transparent)` で、
  透明との混色は乗算済み alpha なので実効 alpha は `0.12 × 0.40 = 0.048` に確定する。
  実測: `text-text` on `bg-primary-soft/40` = neutral 上 15.02 / surface 上 16.51 で AA を満たす。
- 対応内容: `double-alpha` を判定不能の値域から**削除**し、
  実効 alpha を計算して `ALPHA_CONTRAST_PAIRS` に載せる
  (`{ fg: "text", bg: "primary-soft", alpha: 0.048 }` を追加)。
  判定不能に残すのは**本当に解析できない色表現**だけにする。

### [Critical] `UNDECIDABLE_PAIR_LEDGER` の識別子が `(file, reason)` だけで追加を検出できない

- 判断: **対応する**
- 対応内容: 識別子を **`(file, reason, count)` の完全一致**にした
  (行番号は持たない = 正典 s14)。同じファイルに同じ理由の箇所が増えたら件数で赤くなる。

### [Warning] 「実際に描かれるのは 8bit へ丸めた値」はブラウザ描画一般の保証としては強すぎる

- 判断: **対応する**
- 対応内容: 表現を「**本 gate が採用する近似モデル**」へ改め、
  **浮動小数のまま合成した比と 8bit 丸めの比が 4.5 の境界を跨がないこと**を
  別の it で検査する (近似の影響が判定を変えていないことの裏取り)。

## S6

### [Warning] `--color-primary-soft` の primary 追随が機械保証されない

- 判断: **対応する** → S10 の Critical で解消 (RGB・alpha の同一性検査)

### [Suggestion] ブランド色変更の視覚確認対象を明記する

- 判断: **対応する**
- 対応内容: 目視確認する画面を 5 面に特定して施策に書いた
  (撮影画面のガイド帯・字幕帯 / 通知一覧の未読 / Badge の 6 tone を出す画面 /
  サイドバーの選択中 / 料金ページの強調カード)。

## S7

### [Critical] 個別宣言ペアに現れた token を「役割分類済み」と数えると既定拒否を迂回できる

- 判断: **対応する** (指摘のとおり。1 組登録すれば全色被覆を通せる穴だった)
- 対応内容: **役割分類を token ごとの複数役割の宣言へ作り直す**。
  `COLOR_TOKEN_ROLES: Record<DESIGN.md の色キー, readonly ColorRole[]>` を**唯一の宣言**とし、
  既存の 5 つの配列は**そこから導出する** (i4 = 母集団を導出して集合一致させる形に揃う)。
  `border` は `["non-text-boundary", "declared-text-background"]` の 2 役割を持つ
  (別物の用途を統合しない = AGENTS.md 思考原則 4)。
  個別宣言ペアの妥当性は**別の集合一致**で検査する —
  背景側が `declared-text-background` の役割を持つこと、前景側が
  `text-on-surface` か `fill-label` を持つこと。
  役割分類の全数性 (既定拒否) は `COLOR_TOKEN_ROLES` のキーと DESIGN.md の色キーの
  集合一致で見るので、**個別宣言ペアでは迂回できない**。

### [Warning] 実施順が S7 → S3 なのに S7 のテスト計画が S3 後の赤を前提にしている

- 判断: **対応する**
- 対応内容: 実施順を **S3 → S7** に入れ替えた
  (S1 → S2 → S4 → S10 → S5 → S6 → S3 → S7 → S8 → S9 → S11 → S12)。

## S8

### [Warning] サブディレクトリ分類の再帰境界が不明

- 判断: **対応する**
- 対応内容: 探索規則を明記した —
  **直下のサブディレクトリを分類し、`excluded` は再帰を止め、`documented` は直下のファイルだけを見る**。
  `documented` の下にさらにディレクトリがある場合 (`atoms/icons`) は
  **そのパスも分類表に無ければ不合格**。未分類のネストを固定検体で落とす。

### [Warning] DragHandle の「disabled にしない」を禁止事項 8 の帰結とするのは過剰

- 判断: **対応する** (禁止事項 8 は「必須条件未充足を理由に」disabled にすることの禁止であり、
  すべての disabled を禁じてはいない)
- 対応内容: 節に書く意味論を「並べ替え不可時の表現は別途定義する。
  禁止事項 8 を一般的な disabled 禁止として扱わない」へ訂正した。

## S9

### [Critical] 状態遷移とテスト計画が矛盾している

- 判断: **対応する** (指摘のとおり両立しない。**CommonMark に忠実な側へ寄せる**)
- 根拠: CommonMark では字下げコードブロックは**段落を中断できない**。
  したがって「段落の継続行 (直前が空行でない 4 空白行)」は**描画される本文**であり、
  落とさないのが正しい。Round 1 の設計は「落とす側に倒す」と書いていたが、
  それは仕様の誤りであって fail-closed の適用ではなかった。
- 対応内容: 開始条件を「**直前の描画行が空行または文書先頭**」の 1 本に統一し、
  段落継続行は**落とさない**ことをテストで固定した。
  過剰に落としうるのは**リスト項目の配下に空行を挟んで 4 空白で続けた内容**だけで、
  これは docblock の保証範囲に明記する (実測で `docs/design-system.md` に該当行は 0 件)。
  固定検体を Codex の指摘どおり 6 種に分けた —
  空行後の字下げコード / 段落継続行 / リスト配下の字下げ内容 /
  字下げコード区間の中の fence 文字列 / EOF まで閉じない区間 / 行数保存。
- CommonMark パーサの導入は**しない**: 依存が無く (`marked` / `commonmark` /
  `markdown-it` はいずれも未導入)、この 1 検査のために依存を増やすのは
  「今必要なものだけ作る」に反する。代わりに保証範囲を狭く宣言する。

## S11

### [Critical] 本数が算術的に誤っている (4 + 3 = 7)

- 判断: **対応する**
- 対応内容: **固定数の記述そのものをやめる**。「4 本ある」→「下表に挙げた検査がすべてである」、
  「4 本のどれも見ていない」→「下表のどれも見ていない」へ書き換え、
  表の双方向集合一致だけを正本にする (数字は機械検査の対象外なので陳腐化する)。

## S12

### [Warning] D50 の説明がコントラストだけで、共有文書の変更全体を説明していない

- 判断: **対応する** (「論理的に別の逸脱なら複数エントリへ分ける」を採る)
- 対応内容: **2 エントリに分ける** —
  - `D50` = `tests/js/architecture/contrast-invariant.test.ts`
    (半透明の合成 / 逆向き被覆 / errata のしきい値)
  - `D51` = `docs/design-system.md`
    (検査目録の正本化 / 描画されない領域の除去範囲を CommonMark へ寄せる /
    値変更時の運用契約に合成検査を含める)
  `LedgerPins::DIVERGENCE_ENTRY_COUNT` は 46 → **48**、
  `ADOPTION_DEBT_COUNT` は 148 → **146**。

## 横断評価への対応

- 「最大の後退リスクは、正規表現ベースの解析が解析不能を検出できず『候補なし』として落とすこと」
  → `TokenResolution` の `unresolved` を**結果に必ず残し、gate が落とす**形にした
  (無言で候補から外さない = 共通規約 (b) の 1 点目)。
  加えて `unparsable-token` (区切りで割れた形) を値域へ明示した。

---

# 詳細設計書（Round 1 の指摘を反映した改訂版）

# 詳細設計: design-token-system 正典 v1 追従

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の**1 本道のみ**)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

加えて `app-design` スキルが設計判断に直結するものとして挙げる核: 既存テストの削除・上書き禁止 /
`DatabaseTransactions` の個別使用禁止 / やたらに複雑な案を提案しない。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）。**本作業は PHP を 1 行も変えないため、
  PHP 側の母集団に変化は無い**(唯一の PHP 変更は `tests/Support/TemplateDivergence/LedgerPins.php`
  の `int` 定数 2 本の値変更で、型は変わらない)
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ずFactoryで生成**（`Model::create()` 手組み禁止）。**本作業は DB を使わない**
- **DTO + JsonResource** パターン（本作業には該当なし）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- **TS 側の型の閉じ方** (概念設計「型の方針」):
  discriminated union / `as const satisfies` / 分類の網羅を `never` へ収束
- **AGENTS.md「静的検査 (gate) と走査器の共通規約」の 5 条**を新設・変更する走査器すべてに適用し、
  **同じ PR で 4 点** (負例と正例 / 解決できない形を落とす分岐 / 空振り検知 / docblock) を揃える

## 概念設計リファレンス

- [devnotes/20260824-1019-design-token-system-v1/conceptual-design.md](./conceptual-design.md) (Codex `gpt-5.6-terra` Round 3 で APPROVED)
- 実測記録: [contrast-measurements.md](./contrast-measurements.md)
- 逆引き表: [token-change-impact.md](./token-change-impact.md)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | 写像 (tokens.css) の読み出しを 1 実装へ集約し、`@theme` ブロックの一意性を機械で固定する (i21 / i2 前半) | `tests/js/styles/theme-map.ts` (新) / `tests/js/styles/theme-map.test.ts` (新) / `canonical-source-parity.test.ts` / `tokens.test.ts` | 高 (他施策の土台) |
| S2 | class 走査器を新設する (i15 / i16 / i9 の共通入力 + 未対応入口の deny) | `tests/js/styles/class-usage.ts` (新) / `tests/js/styles/class-usage.test.ts` (新) | 高 (土台) |
| S3 | 参照の閉包 gate を新設し、写像の外の色語を落とす (i9) | `token-reference-closure.test.ts` (新) / `inventory.ts` / `AppLayout.svelte` / `SidebarNavItems.svelte` | 高 |
| S4 | 線形化しきい値を 0.04045 へ揃える (i13) | `contrast-invariant.test.ts` | 高 (S5 の前提) |
| S5 | 半透明背景 × 不透明文字の合成検査を新設する (i16) | `contrast-invariant.test.ts` / `inventory.ts` | 高 |
| S6 | トークン値を是正する (i16 の帰結) | `DESIGN.md` / `resources/css/tokens.css` | 高 |
| S7 | 実装からの逆向き被覆と役割分類の是正 (i15 / i14) | `contrast-invariant.test.ts` / `inventory.ts` | 高 |
| S8 | 文書 ⇔ 実装の双方向一致 gate を新設する (i10) | `component-doc-parity.test.ts` (新) / `design-md.ts` / `inventory.ts` / `DESIGN.md` | 中 |
| S9 | 描画されない領域の除去に 4 空白字下げコードを足す (i12 の残余) | `design-system-docs.test.ts` / `docs/design-system.md` | 中 |
| S10 | 不透明度修飾の生成形を契約として固定する (i6 の補強 / S5 の前提の裏取り) | `tokens.test.ts` | 中 |
| S11 | 責務境界表へ新設 gate を登録する (i11 の帰結) | `docs/design-system.md` | 中 (必須。書かないと S1/S2/S3/S8 で既存 gate が落ちる) |
| S12 | 共有パスの採用時債務を決着させる (乖離台帳 D50 / D51 の新設と D28 の本文訂正) | `docs/template-divergence.md` / `LedgerPins.php` / `adoption-debt.tsv` | 中 (必須) |

**実施順**: S1 → S2 → S4 → S10 → S5 → S6 → **S3 → S7** → S8 → S9 → S11 → S12。
S4 を S5 より先に置くのは、しきい値を直してから合成の期待値を書くため
(逆順だと 0.03928 基準の期待値を書いて後で全部直すことになる)。
S6 (値の是正) は S5 の赤を確認した**後**に行う (テストファースト。思考原則 5)。
**S3 を S7 より先に置く** (Round 1 レビューの指摘で入れ替えた): S7 の逆向き被覆は
S3 が `text-white` を `text-surface` へ直した**後**に現れる `(surface, primary)` の赤を
前提にしているため、逆順だと S7 の「先に赤くするテスト」の記述が実際の実行順と食い違う。
S11 は S2 / S3 / S8 が新設する `tests/js/styles/*.test.ts` を既存 `design-system-docs.test.ts` の
双方向集合一致が要求するので、**同じコミットの中**で行う。

> **Round 1 レビューの反映**: 本書は Codex (`gpt-5.6-sol`) の詳細設計レビュー Round 1 で
> Critical 9 件・Warning 11 件・Suggestion 1 件を受け、**全件を対応して改訂した版**である。
> 対応の判断と根拠は [codex-history/design-review-decisions-round-1.md](./codex-history/design-review-decisions-round-1.md)。

---

## S1 写像の読み出しを 1 実装へ集約し、`@theme` ブロックの一意性を固定する (i21 / i2 前半)

### 変更箇所

- 新規: `tests/js/styles/theme-map.ts` (パーサ。gate ではない)
- 新規: `tests/js/styles/theme-map.test.ts` (パーサの自己検査 = 固定検体の負例・正例)
- `tests/js/styles/canonical-source-parity.test.ts` (L29-35 の `cssColorTokens()` を削除して移設、
  L66-69 の radius 抽出と L122 の `@utility` 抽出も移設)
- `tests/js/styles/tokens.test.ts` (`REPO_ROOT` の import 元は `design-md.ts` のままでよい。
  写像のテキストを読む必要が生じた箇所だけ `theme-map.ts` を使う)

### 波及変更

- TypeScript 型定義: `theme-map.ts` の公開型を新設 (下記)。`ParsedColor` / `Rgb` は
  S5 (合成) と S10 (派生の導出検査) が import する
- API Resource/DTO: なし
- テストファイル: `canonical-source-parity.test.ts` の import 追加 / ローカル関数の削除

> ⚠ `theme-map.ts` は `*.test.ts` ではないので `design-system-docs.test.ts` の
> `gateFiles()` の母集団には入らない。一方 `theme-map.test.ts` は**入る**ので
> S11 で責務境界表へ行を足す (足さないと既存 gate が赤くなる)。

### 現行コード

```ts
// tests/js/styles/canonical-source-parity.test.ts (L27-35)
const tokensCss = fs.readFileSync(path.join(REPO_ROOT, "resources/css/tokens.css"), "utf-8");

function cssColorTokens(): Map<string, string> {
    const map = new Map<string, string>();
    for (const m of tokensCss.matchAll(/--color-([a-z-]+):\s*([^;]+);/g)) {
        map.set(m[1], m[2].replace(/\/\*.*?\*\//g, "").trim().toLowerCase());
    }
    return map;
}
```

`--radius-*` の抽出 (L66-69) と `@utility text-*` の抽出 (L122) も**同ファイルの中に直書き**されている。
`tokens.test.ts` は生成 CSS 側を読むので写像のテキストは読んでいないが、
S3 (参照の閉包) が `@theme` の宣言集合を必要とするため、**このまま新 gate に 2 本目の
パーサを書くと i21 に反する**。

### 変更後コード

```ts
// tests/js/styles/theme-map.ts (新設)
/**
 * 実装写像 (resources/css/tokens.css) の読み出し — 検査テスト共有。
 *
 * ★正典 i21: 正本と写像の読み出しは**それぞれ 1 実装へ集約する**。
 *   同じ関心の解析が 2 本あると弱い方が緑を作る (「片方だけが読める写像」が成立する)。
 *   正本 (DESIGN.md) 側は design-md.ts が担当する。本ファイルは写像側だけを担当する。
 *
 * 【走査対象】呼び出し側が渡した CSS ソース文字列。実ファイルを読むのは薄いラッパーだけである。
 * 【解析の方式】**コメントを先に除去する字句走査**である。`/* … *​/` を空白へ潰してから
 *   `@theme` を探すので、**コメントの中の `@theme` は数えない**。
 *   ブロックは `{` `}` の対応を数えて閉じる (閉じないまま EOF に達したら例外 = i20)。
 * 【保証しないもの】
 *   - Tailwind の解釈 (宣言が生成 CSS に出るか) は見ない。それは tokens.test.ts の担当
 *   - CSS の完全な構文解析ではない。文字列リテラルの中の `/*` や `{` は見分けない
 *     (tokens.css に文字列リテラルは 1 件も無い。現れたら解析結果が変わり
 *     集合一致が赤くなるので、無言では通らない)
 *   - `@theme` の**ブロックの深さは 1 段だけ**を想定し、入れ子の `{}` を含む宣言
 *     (現状 0 件) は解析できないので**例外にする** (fail-closed)
 *   - 値の意味 (色空間・単位) は見ない。文字列として扱う
 */
export interface ThemeBlock {
    /** ブロック本文 (最初の `{` の次から対応する `}` の直前まで) */
    readonly body: string;
    /** ソース先頭からのブロック開始位置 (診断用。期待値には使わない) */
    readonly offset: number;
    /** ルート直下の `@theme` か (条件つき at-rule の内側なら false) */
    readonly topLevel: boolean;
}

/** 1 本のソースを解析した結果。 */
export interface ThemeMap {
    /** 見つかった `@theme` ブロック全件 (0 件・2 件以上も呼び出し側が判定できるよう返す) */
    readonly blocks: readonly ThemeBlock[];
    /** ルート直下の `@theme` 直下の CSS 変数宣言 `{ 変数名 → 値 }` */
    readonly declarations: ReadonlyMap<string, string>;
    /** `@utility text-<name>` の宣言 `{ name → { プロパティ → 値 } }` */
    readonly rampUtilities: ReadonlyMap<string, ReadonlyMap<string, string>>;
}

/**
 * ★**唯一の解析実装**。実ファイル用の関数はすべてこの薄いラッパーである
 *   (Round 1 レビューの指摘: 固定検体を解析する入口が公開 API に無いと、
 *   `theme-map.test.ts` が任意入力を検査できず i18 の裏取りにならない)。
 * `file` は例外メッセージに載せる識別子であって、ファイルを読むためのものではない。
 */
export function parseThemeMap(source: string, file: string): ThemeMap;

/** `resources/css/tokens.css` を読んで `parseThemeMap` に渡す薄いラッパー。 */
export function tokensCssThemeMap(): ThemeMap;

/** `--color-<suffix>` だけを suffix で引ける形にしたもの (コメント除去・小文字化)。 */
export function cssColorTokens(): ReadonlyMap<string, string>;

/** `--radius-<suffix>` だけを suffix で引ける形にしたもの。 */
export function cssRadiusTokens(): ReadonlyMap<string, string>;

/** `@utility text-<name>` の宣言 (`tokensCssThemeMap().rampUtilities` の別名)。 */
export function cssRampUtilities(): ReadonlyMap<string, ReadonlyMap<string, string>>;

/**
 * `rgba(r, g, b, a)` / `rgb(r g b / a)` を厳密に解析する (派生 token の値の検査に使う)。
 * 解析できない色表現は**例外**にする (i20)。
 */
export function parseCssColor(value: string): ParsedColor;

/** 色の正規化形 (S5 の合成と S10 の派生検査が共有する)。 */
export type ParsedColor =
    | { readonly kind: "opaque"; readonly rgb: Rgb }
    | { readonly kind: "alpha"; readonly rgb: Rgb; readonly alpha: number };

export interface Rgb {
    readonly r: number;
    readonly g: number;
    readonly b: number;
}
```

- `canonical-source-parity.test.ts` は**ローカル関数を削除**して `theme-map.ts` を使う
  (後方互換の並走を残さない = AGENTS.md 思考原則 3)。
- `@theme` の一意性は `canonical-source-parity.test.ts` に describe を 1 つ足して固定する
  (写像の形の検査なので 正本 ⇔ 写像 の gate が持つのが自然)。

```ts
describe("canonical source parity: 写像の形", () => {
    it("@theme ブロックがリポジトリに 1 つだけある (2 つ目の宣言が検査を素通りする経路を塞ぐ)", () => {
        // 走査は git 追跡下の *.css 全数。tokens.css の外に @theme を置くと
        // canonical-source-parity / tokens の両方が見ない token 空間が育つ。
        const cssFiles = trackedCssFiles();
        expect(cssFiles.length, "*.css が 1 件も取れない (走査の空振り)").toBeGreaterThan(0);
        // ★判定は parseThemeMap の結果で行う (コメントの中の @theme を数えない)。
        const withTheme = cssFiles.filter(
            (rel) => parseThemeMap(readCss(rel), rel).blocks.length > 0,
        );
        expect(withTheme).toEqual(["resources/css/tokens.css"]);
        expect(tokensCssThemeMap().blocks.length, "tokens.css の @theme が 1 ブロックでない").toBe(1);
        expect(tokensCssThemeMap().blocks[0].topLevel, "@theme がルート直下でない").toBe(true);
    });
});
```

写像のキー空間と正本のキー空間の橋渡しが一意であることも同じ describe で固定する。

```ts
    it("COLOR_TOKEN_MAP の逆写像が一意である (suffix → DESIGN キーが後勝ちにならない)", () => {
        // 走査器は suffix 空間を返し、gate は逆写像で DESIGN キー空間へ写す。
        // 値に重複があると逆引きが後勝ちになり、別のトークンの値で検査してしまう。
        const suffixes = Object.values(COLOR_TOKEN_MAP);
        expect(suffixes.length, "COLOR_TOKEN_MAP が空 (走査の空振り)").toBeGreaterThan(0);
        expect(new Set(suffixes).size).toBe(suffixes.length);
    });
```

- `trackedCssFiles()` は `git ls-files -- '*.css'` を使わず、**`resources/` の再帰走査**で
  取る (テスト実行で子プロセスを起こさない。`vitest-inventory-gate` が
  「収集フェーズで spawn しない」規約を持つのと同じ配慮)。
  走査根は `resources/` 1 本で、**存在しなければ fail-fast** にする。
  `node_modules` / `vendor` / `public/build` は走査根の外なので自然に落ちる。
  **保証範囲**: `resources/` の外に置いた CSS は見ない — これを docblock に明記する
  (アプリの CSS はすべて `resources/css` にあり、`vite.config` の入口も同ディレクトリである)。

### 型適合チェック

- [x] 戻り値の型が明示されている (`readonly` / `ReadonlyMap` で外から書き換えられない)
- [x] `null` 安全: 解析失敗は `undefined` を返さず**例外**にする (i20 = 解析の失敗を pass に変えない)
- [x] 配列返却ではなく `ReadonlyMap` / `interface` を返す
- [x] Generics の型パラメータが正しい (`ReadonlyMap<string, ReadonlyMap<string, string>>`)

### テスト計画

- [x] **先に赤くするテスト**: `canonical-source-parity.test.ts` の新 describe「@theme ブロックが
      リポジトリに 1 つだけある」。実装前は `parseThemeMap()` が存在しないので**コンパイルエラーで赤**。
      次に `theme-map.ts` を空実装 (`throw`) で置いて**実行時エラーで赤**を確認してから実装する
- [x] 既存テスト `canonical-source-parity.test.ts` の 8 it は**移設後も同じ期待値で緑**であること
      (リファクタの等価性の確認)
- [x] 新規: `tests/js/styles/theme-map.test.ts` — **固定検体を `parseThemeMap(source, file)` へ
      直接渡して**パーサの仕様を固定する (i18。実ファイルを読む経路は検体を差し込めない)
  - 負例 1: `@theme` を 2 ブロック持つ検体 → `blocks.length === 2` (呼び出し側が落とせる)
  - 負例 2: `@media` の中の `@theme` → **ブロックとして数える**が `topLevel === false` で、
    `declarations` は**トップレベルの `@theme` だけ**を見る (i2 後半と同じ絞り込み)
  - 負例 3: **コメントの中の `@theme`** (`/* @theme { --color-x: red; } *​/`) →
    `blocks.length === 0` (コメント除去を先に行う仕様の裏取り)
  - 負例 4: 同名変数の再宣言 → 例外 (i20)
  - 負例 5: 入れ子の `{}` を含む宣言 → 例外 (解析できない形を落とす = 共通規約 (b))
  - 負例 6: **閉じないブロック** (`@theme {` のまま EOF) → 例外 (i20)
  - 負例 7: `parseCssColor("color-mix(in oklab, red 10%, transparent)")` → 例外
    (扱えない色表現を「読めた」ことにしない)
  - 正例 1: 現行 tokens.css と同形の検体で色 / radius / ramp が期待どおり取れる
  - 正例 2: `parseCssColor("rgba(29, 78, 216, 0.12)")` →
    `{ kind: "alpha", rgb: { r: 29, g: 78, b: 216 }, alpha: 0.12 }`
- [x] 母集団の非空: `tokensCssThemeMap().declarations.size > 0` / `cssColorTokens().size > 0` /
      `cssRampUtilities().size > 0` (共通規約 (b) の 3 点目)
- [x] 個別の `DatabaseTransactions` を使っていない (DB を使わない)

### リスク

- リファクタで既存 8 it の期待値が変わると、**値の drift を見逃す穴**が開く。
  → 移設は「関数の本体をそのまま移す」に限り、正規表現を書き換えない。
  書き換えるのは「例外にする / `ReadonlyMap` にする」の 2 点だけ。
- `resources/` 再帰走査は将来 CSS を別の場所へ置いたときに見落とす。
  → docblock に保証範囲として明記し、`vite.config.ts` の入口が
  `resources/css/app.css` であることを根拠として書く。

---

## S2 class 走査器を新設する (i15 / i16 / i9 の共通入力)

### 変更箇所

- 新規: `tests/js/styles/class-usage.ts` (走査器。gate ではない)
- 新規: `tests/js/styles/class-usage.test.ts` (走査器の自己検査 = 固定検体の負例・正例)

> ⚠ `class-usage.ts` は `*.test.ts` ではないので `design-system-docs.test.ts` の
> `gateFiles()` の母集団には入らない (母集団は `tests/js/styles/*.test.ts`)。
> 一方 `class-usage.test.ts` は**入る**ので S11 で責務境界表へ行を足す。

### 波及変更

- TypeScript 型定義: 下記の公開型 (走査結果) を新設。`inventory.ts` が理由の union を参照する
- API Resource/DTO: なし
- テストファイル: S3 / S5 / S7 の gate が本走査器を import する

### 変更後コード (公開する型と関数)

```ts
// tests/js/styles/class-usage.ts (新設)
/**
 * resources/js の class 記述から「前景 × 背景の組」と「解決できなかった形」を導出する走査器。
 *
 * 【走査分母】resources/js のディレクトリ単位の再帰走査 (`*.svelte` / `*.ts`)。
 *   ファイルを足したら自動で分母に入る (正典 i15 / s14: 固定のファイル列挙は足し忘れが静かに起きる)。
 *
 * 【走査単位 (これが保証する構文集合)】**文字列リテラルの中の class トークン**
 *   (単引用 / 二重引用 / バッククォート)。単位の中だけで状態と組を作る。
 *   ★**それ以外の形については検出力を主張しない**。代わりに、扱えない**既知の入口**を
 *     語彙の deny (unsupportedEntryPoints()) で 0 件に固定する。
 *
 * 【class トークンの区切り】ds-purity.ts の CLASS_TOKEN_PATTERN と同じ文字集合を使う
 *   (英数字 / `_` / `-` / `:` / `/` / `.` / `%` / `[` / `]` / `!` / `#`)。
 *   これ以外の文字はすべて区切りとして扱う。丸括弧・`@`・カンマを含む書き方
 *   (`bg-(--var)` / `@md:flex`) はここでトークンが割れるため**解析できない形として落ちる**。
 *
 * 【状態の作り方】素の宣言を基底の状態とし、同じ修飾の連なり (`hover:` / `disabled:` …) を
 *   持つ宣言は基底をその修飾で上書きした状態とする。組は状態の内側だけで作る。
 *   これをしないと `"bg-surface text-danger hover:bg-danger hover:text-neutral"` から
 *   `text-danger on bg-danger` (比 1.0) という**実在しない組**が生まれる。
 *
 * 【保証しないもの (誇張しない)】
 *   - **宣言の単位をまたいで成立する組**。実例: atoms/input-state.ts は `text-text` を
 *     INPUT_BASE_CLASSES に、`bg-surface` / `bg-neutral` を inputStateClass() の戻り値に持つ。
 *     ただしこの穴の大部分は役割の直積 (i14) が覆っている — 両方の token に役割が在れば、
 *     その組は宣言が割れていても既に母集団の内側にある。見えないのは
 *     「直積に現れない役割の組み合わせの 2 token が同じ要素に載り、かつ宣言の単位が割れている」
 *     場合だけである
 *   - **親から渡る class** (`extraClass`) と**親要素から継承する背景** (正典 i22 (2))
 *   - **実行時に組み立てられる class** (正典 i22 (1))
 *   - **DOM の実際の入れ子**。同じ単位に載っていることは「同じ要素にある」ことの近似である
 *   - **変種の修飾の綴りが正しいこと**。`hoverr:bg-primary` は token としては解決する
 *     (変種の名前空間は Tailwind のもので、本アプリの写像ではない)
 */

/**
 * ★**すべての利用側 (S3 / S5 / S7) が同じ抽出結果から導出する**ための共通出力
 *   (Round 1 レビューの Critical: これが無いと S3 が 2 本目の走査器を書くことになり i21 に反する)。
 *
 * 解析は **3 段を独立に**行う — 変種の修飾 (`sm:` `hover:`) / 重要度の修飾 (`!`) /
 * 不透明度の修飾 (`/NN`)。**不透明度の修飾は色 utility にだけ許す**ので、
 * `text-center/50` は `unresolved: "alpha-on-non-color"` になり**素通りしない**。
 */
export interface ClassTokenOccurrence {
    /** リポジトリ相対のファイルパス */
    readonly file: string;
    /** 走査単位 (文字列リテラル) の識別子。行番号は持たない (正典 s14) */
    readonly unit: string;
    /** 区切りで分割したままの生のトークン (診断用。期待値には使わない) */
    readonly raw: string;
    /** 変種の修飾を出現順に並べたもの (`["sm", "hover"]`)。素の宣言は空配列 */
    readonly variants: readonly string[];
    /** 重要度の修飾が付いているか */
    readonly important: boolean;
    /** 変種・重要度・不透明度を取り除いた utility 名 (`bg-primary` / `text-center`) */
    readonly utility: string;
    /** 不透明度修飾。`null` は修飾なし */
    readonly alpha: number | null;
    /** utility 名が何へ解決したか */
    readonly resolution: TokenResolution;
}

/** utility 名の解決結果 (判別可能 union。未解決を無言で候補から外さない = 共通規約 (b))。 */
export type TokenResolution =
    | { readonly kind: "color"; readonly channel: ColorChannel; readonly suffix: string }
    | { readonly kind: "ramp"; readonly name: string }
    | { readonly kind: "radius"; readonly name: string }
    | { readonly kind: "contract"; readonly word: string }
    | { readonly kind: "unresolved"; readonly reason: UnresolvedReason };

/** 色 utility の channel。**前景 / 背景以外も分類する** (i17 の非テキスト境界を混ぜないため)。 */
export type ColorChannel = "background" | "foreground" | "border" | "ring" | "other";

/** 解決できなかった理由。 */
export type UnresolvedReason =
    | "unknown-token"        // テーマ名前空間の接頭辞を持つが写像にも契約表にも無い
    | "alpha-on-non-color"   // 色でない utility に不透明度修飾が付いている
    | "unparsable-token";    // 区切りで割れた形 (`bg-(--var)` / 非 ASCII の混入)

/** `var(--…)` 参照 (class ではない別チャネル)。 */
export interface CssVarReference {
    readonly file: string;
    readonly name: string;
    readonly resolution: TokenResolution;
}

export function scanCssVarReferences(): readonly CssVarReference[];

/** 走査で得た 1 つの組。 */
export type ScannedPair =
    | { readonly kind: "opaque"; readonly file: string; readonly fg: string; readonly bg: string }
    | {
          readonly kind: "alpha-background";
          readonly file: string;
          readonly fg: string;
          readonly bg: string;
          /** 0 < alpha < 1 */
          readonly alpha: number;
      }
    | { readonly kind: "undecidable"; readonly file: string; readonly reason: UndecidableReason };

/**
 * 静的に組を決められない理由 (正典 i16 が「例外にして素通りさせない」と定めた形)。
 *
 * ★`double-alpha` は**値域から外した** (Round 1 レビューの Critical)。
 *   alpha を値に持つ token への修飾は実効 alpha が `token の alpha × 修飾の alpha` に
 *   確定する (S10 が生成形を固定する) ので、**静的に決められる形**であり
 *   例外へ逃がすのは i16 に反する。合成対象として計算する。
 */
export type UndecidableReason =
    | "foreground-alpha"          // 前景にも不透明度修飾がある
    | "keyword-color"             // bg-transparent 等、token を指さない色キーワード
    | "alpha-background-no-text"  // 同じ宣言に前景が無い alpha 背景
    | "opaque-and-alpha-background" // 同じ状態に塗り面の背景と alpha 背景が同居
    | "multiple-background"       // 同じ状態に不透明な背景の宣言が 2 つ以上 (勝敗を静的に決められない)
    | "multiple-foreground"       // 同じ状態に前景の宣言が 2 つ以上
    | "element-opacity"           // 要素全体の不透明度指定 (opacity-*) が同居
    | "interpolated";             // 補間で完成した class 文字列を差し込む単位

/** 不透明のみの不完全な単位 (前景か背景の片方しか無い) の集計。 */
export interface IncompleteOpaqueCounts {
    readonly backgroundOnly: number;
    readonly foregroundOnly: number;
}

/** 走査結果。 */
export interface ClassUsageScan {
    /** 走査したファイル (リポジトリ相対、ソート済み)。空なら呼び出し側が落とす */
    readonly files: readonly string[];
    /** `resources/js` の直下の子ごとの抽出件数 (どれかが丸ごと読めていない状態を捕まえる) */
    readonly perDirectory: ReadonlyMap<string, number>;
    /** ★全 class トークンの共通出力。S3 / S5 / S7 はここから導出する (2 本目の走査器を書かない) */
    readonly occurrences: readonly ClassTokenOccurrence[];
    readonly pairs: readonly ScannedPair[];
    readonly incompleteOpaque: IncompleteOpaqueCounts;
}

export function scanClassUsage(): ClassUsageScan;

/** 走査器が扱えない**既知の入口**の出現 (0 件であることを gate が固定する)。 */
export interface UnsupportedEntryPoint {
    readonly file: string;
    readonly kind: "class-directive" | "class-helper-library" | "interpolated-prefix";
}

export function unsupportedEntryPoints(): readonly UnsupportedEntryPoint[];
```

### 走査分母と走査根

**走査根は `resources/js` の 1 本**で、**全体を再帰走査**する
(Round 1 レビューの Critical: 固定 3 根 (`components` / `pages` / `lib`) は
実測で `app.ts` / `inertia.ts` / `vite-env.d.ts` / `types/` の 4 つを取り落としており、
docblock の「resources/js の走査分母」と食い違っていた。新しい直下ディレクトリからも迂回できる)。
走査根が存在しなければ **fail-fast** (`PrismDirectDispatchScanner::roots()` に倣う)。

**拡張子の全数分類** (未分類が現れたら不合格):

| 拡張子 | 扱い | 理由 |
|---|---|---|
| `.svelte` | 走査する | 画面のマークアップ |
| `.ts` | 走査する | variant 表・helper の class 文字列 |
| `.d.ts` | 走査しない | 型宣言のみ。class 文字列を持たない |
| `.gitkeep` | 走査しない | 空ディレクトリの目印 |

**`resources/js` 直下の子の全数分類** (新しい直下の子が現れたら不合格):

| 直下の子 | 抽出を要求するか | 実測 |
|---|---|---|
| `components/` | **要求する** | 多数 |
| `pages/` | **要求する** | 多数 |
| `lib/` | 要求しない | テーマ名前空間の class トークンが 0 件 |
| `types/` | 要求しない | 型定義のみ (0 件) |
| 直下のファイル (`app.ts` / `inertia.ts` / `vite-env.d.ts`) | 要求しない | 0 件 |

- `perDirectory` は上の分類ごとの抽出件数で、**「要求する」の 2 つがそれぞれ 0 でないこと**を
  gate が固定する (motivation の「ディレクトリごとに 1 件以上抽出できる」形)。
  **要求しない子に 0 件を強いない** — 0 件が正常なので、要求すると正常な状態を赤にする。
- `resources/views/vendor/mail/html/themes/template.css` は**走査根の外**である。
  Laravel 同梱メールテーマの独立パレットで DS token の写像ではない
  (既に `contrast-invariant.test.ts` の docblock が同じ線引きを持つ)。

### deny する既知の入口 (実測で現状すべて 0 件)

| kind | 判定 | 現状 |
|---|---|---|
| `class-directive` | Svelte の `class:` に**識別子が直接続く**形 (`class:foo=` / `class:foo`)。`class: extraClass` (props の分割代入。コロンの後に空白) は**別物**なので当たらない | 0 件 |
| `class-helper-library` | `clsx` / `twMerge` / `tailwind-merge` / `classnames` / `cva` が区切りで分割したトークンとして現れる (import・呼び出しとも) | 0 件 |
| `interpolated-prefix` | テーマ名前空間の接頭辞の**直後**に補間が来る形 | 0 件 |

### 型適合チェック

- [x] 戻り値の型が明示されている
- [x] `null` 安全: `alpha` は `number | null` を明示し、`ScannedPair` の判別で分岐を強制する
- [x] 配列返却ではなく判別可能 union を返している (`ScannedPair`)
- [x] `UndecidableReason` の網羅を `switch` の default で `never` へ収束させる

### テスト計画

- [x] **先に赤くするテスト**: `class-usage.test.ts` に固定検体を置き、
      「状態単位の組の作り方」を先に書く。実装前は import が解決せず**赤**
- [x] 負例 (共通規約 (c) / i18):
  - `"bg-surface text-danger hover:bg-danger hover:text-neutral"` から
    `(danger, surface)` と `(neutral, danger)` の**2 組だけ**が出る
    (`(danger, danger)` / `(neutral, surface)` が出たら赤)
  - **状態の継承の片側だけ上書き** (Round 1 レビューの Warning。上の検体は両方を上書きするので
    「継承していない実装」を検出できない):
    - `"text-text hover:bg-danger"` → `(text, danger)` が出る (前景を基底から継承する)
    - `"bg-surface hover:text-danger"` → `(danger, surface)` が出る (背景を基底から継承する)
  - 同じ状態に不透明な背景が 2 つ (`"bg-surface bg-neutral text-text"`) →
    `multiple-background` の判定不能になる (どちらが勝つかは生成 CSS の順で決まり静的に決められない)
  - 同じ状態に前景が 2 つ (`"bg-surface text-text text-danger"`) → `multiple-foreground`
  - **二重 alpha は判定不能にしない**: `"bg-primary-soft/40 text-text"` →
    `kind: "alpha-background"` / `alpha === 0.048` (= 0.12 × 0.40) の組が出る
  - class トークンの分解: 接頭辞つき `sm:bg-primary` / 打ち消しつき `!bg-primary` /
    接尾辞つき `bg-primary/10` の**3 形**をそれぞれ正しく解決する
    (素の部分文字列一致だと 3 形が一緒に消える。共通規約 (e))
  - 非 ASCII の混入 (`bg-primaryあ`) は `resolution.kind === "unresolved"` /
    `reason === "unparsable-token"` として**結果に残る** (無言で候補から外さない = 共通規約 (b))
  - `bg-(--var)` はトークンが割れるので同じく `unparsable-token` として残る
  - **色でない utility への不透明度修飾**: `text-center/50` は
    `unresolved: "alpha-on-non-color"` になる (`text-center` として通さない)。
    一方 `sm:text-center` と `!text-center` は `utility === "text-center"` /
    `resolution.kind === "contract"` として**正しく解決する** (3 形を別々に固定する = 共通規約 (e))
  - deny 語彙 3 群それぞれについて、合成入力で `unsupportedEntryPoints()` が**検出する**
    (`class:foo={x}` / `clsx(...)` / 接頭辞の直後に補間) ことと、
    紛らわしい形 (`class: extraClass` / `flash-to-toast` / 補間が完成した class を差し込む形) を
    **誤検出しない**ことの両方向
  - `ramp` と整列語の取り違え: `text-body` / `text-center` を前景色として拾わない
  - **DESIGN.md のキーとの衝突**: `text-primary` は前景色 `primary`、
    `text-text` は前景色 `text` として解決する (`COLOR_TOKEN_MAP` の `text-primary` キーは
    本文色 = `--color-text` であって別物)
- [x] 正例: 実在する `atoms/Badge.types.ts` の 5 tone / `atoms/Button.types.ts` の 8 variant を
      期待どおりの組へ分解する (**既知の要求組が抽出結果から実際に生成されること** = 正典 i15)
- [x] **分類分岐の点灯は固定検体で確かめる** (Round 1 レビューの Warning。
      実リポジトリに「不完全な単位が必ず存在する」ことを要求すると、コードが良くなって
      0 件になった正常状態を赤にしてしまう)。`incompleteOpaque.backgroundOnly` /
      `foregroundOnly` / `UndecidableReason` の 8 分類は、それぞれ**合成入力で 1 件出る**ことを固定する
- [x] 空振り検知 (実リポジトリに対して要求するのはここまで):
      `files.length > 0` / `occurrences.length > 0` / `pairs.length > 0` /
      `perDirectory` の**「要求する」2 つ** (`components` / `pages`) がそれぞれ > 0
      (共通規約 (b) の 3 点目)
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- 状態単位の作り方が Tailwind の実際の勝敗 (生成 CSS の順序) と一致しない場合がある。
  → `atoms/input-state.ts` のコメントが既に「Tailwind は同一プロパティの utility が並んだ場合、
  勝敗が class 属性の順ではなく生成 CSS の順で決まる」と記録している。本走査器は
  **同じ状態に同じ channel の宣言が 2 つ以上ある単位**を
  `multiple-background` / `multiple-foreground` / `opaque-and-alpha-background` の
  **判定不能**として扱い、勝敗を勝手に決めずに素通りもさせない。
- 走査単位が「同じ要素」の近似であることは誇張しない (docblock に明記)。

---

## S4 線形化しきい値を 0.04045 へ揃える (i13)

### 変更箇所

- `tests/js/architecture/contrast-invariant.test.ts` (L45-49)

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 同ファイルの負のコントロール (L146-153) に errata の裏取りを 1 行足す
- **共有パス**: このファイルは `docs/template-fingerprints.json` のキーに在り、
  `adoption-debt.tsv` にも在る → **S12 で決着させる**

### 現行コード

```ts
/** sRGB チャンネルの線形化 (WCAG 2.x 相対輝度の定義) */
function linearize(channel: number): number {
    const c = channel / 255;
    return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
}
```

### 変更後コード

```ts
/**
 * sRGB チャンネルの線形化 (WCAG 2.x 相対輝度の定義)。
 *
 * しきい値は **0.04045** を使う。WCAG 2.0 / 2.1 本文の 0.03928 は
 * **2022-02-22 の errata で訂正済み**で、IEC 61966-2-1 (sRGB) の正しい値が 0.04045 である。
 * ★**8bit の色値では判定結果は変わらない** (境界は 0.03928*255 = 10.02 と
 *   0.04045*255 = 10.31 の間にあり、整数のチャンネル値 10 と 11 のどちらも
 *   両しきい値の同じ側に落ちる)。正しい方へ揃えるだけの変更である。
 */
export function linearizeChannel(c: number): number {
    return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
}

function linearize(channel: number): number {
    return linearizeChannel(channel / 255);
}
```

★**正規化済みチャンネル (0..1) を受ける純粋関数 `linearizeChannel()` を切り出す**のが本施策の要点である
(Round 1 レビューの Critical: 8bit の全値で「両しきい値の判定が一致する」ことを確かめるだけの検査は
**実装本体を 1 度も呼ばない**ので、実装が 0.03928 のままでも緑になり、i13 を固定できない)。

負のコントロールへ追加する検査:

```ts
it("負のコントロール: 線形化のしきい値が errata 後の 0.04045 である", () => {
    // 2 つのしきい値の**間**の値でだけ実装の差が出る。
    //   c = 0.04 → 0.04045 実装は線形枝 = 0.04 / 12.92 = 0.0030959752321981426
    //              0.03928 実装は pow 枝  =              0.0030954995810608932
    // ★実装本体 (linearizeChannel) を呼ぶので、0.03928 のままならこの toBe が落ちる。
    expect(linearizeChannel(0.04)).toBe(0.04 / 12.92);
    // 両しきい値の外側では当然一致する (この it が「何でも通る」形でないことの裏取り)。
    expect(linearizeChannel(0.03)).toBe(0.03 / 12.92);
    expect(linearizeChannel(0.5)).toBeCloseTo(Math.pow((0.5 + 0.055) / 1.055, 2.4), 12);
});

it("補助: errata のしきい値の差が 8bit では判定を変えない", () => {
    // 「揃えたら結果が変わった」= どちらかの実装が間違っていたことになるので、
    // 変わらないことを 8bit の全チャンネル値で固定する (i18 の既知値)。
    // ★これは**性質の検査**であって実装のしきい値は固定しない (上の it が固定する)。
    for (let channel = 0; channel <= 255; channel += 1) {
        const c = channel / 255;
        expect(c <= 0.03928, `channel=${channel}`).toBe(c <= 0.04045);
    }
});
```

### 型適合チェック

- [x] 戻り値の型が明示されている / [x] `null` 安全 (数値のみ) / [x] 配列返却なし / [x] Generics なし

### テスト計画

- [x] **先に赤くするテスト**: 上の「線形化のしきい値が errata 後の 0.04045 である」を
      **先に書く**。現行実装 (0.03928 + `linearizeChannel` の切り出しなし) では
      **コンパイルエラー → 切り出し後は `toBe` の不一致**で赤になる。
      赤を確認してからしきい値を直す (テストファースト。思考原則 5)
- [x] 既存の 12 ペア + 負のコントロール 4 件が**同じ値で緑**であること (差が出ないことの実証)

### リスク

- 実質的な後退リスクは無い (8bit では判定不変)。値だけを直す変更なので、
  **仕組みが機能していない段階で値を弄るな**の原則には触れない (仕組みは既にある)。

---

## S10 不透明度修飾の生成形を契約として固定する (i6 の補強)

### 変更箇所

- `tests/js/styles/tokens.test.ts` (`UTILITY_CANDIDATES` に `alpha` 区分を追加 / describe を 1 つ追加)

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし / テストファイル: 同ファイルのみ

### 変更後コード

```ts
const UTILITY_CANDIDATES = {
    color: /* 既存 */,
    radius: /* 既存 */,
    ramp: /* 既存 */,
    hover: /* 既存 */,
    /**
     * 不透明度修飾。**S5 (合成の検査) が置く前提「修飾は同じ色の alpha になる」の裏取り**。
     * 代表として不透明 token の /10、alpha を値に持つ派生 token の /40 (= 二重) を取る。
     */
    alpha: ["bg-primary/10", "bg-primary-soft/40"],
} as const;
```

```ts
/* ===== H. 不透明度修飾の生成形 (密閉の層) ===== */

describe("tokens/H: 不透明度修飾は同じ色の alpha として生成される", () => {
    /**
     * ★S5 の合成モデルはこの生成形を前提にしている。前提が版で変わったら
     *   ここが赤くなって「見直す契機」になる (正典 i16 が要求する形)。
     *
     * 実測 (Tailwind 4.3):
     *   .bg-primary\/10 {
     *       background-color: color-mix(in srgb, #1d4ed8 10%, transparent);
     *       @supports (color: color-mix(in lab, red, red)) {
     *           background-color: color-mix(in oklab, var(--color-primary) 10%, transparent);
     *       }
     *   }
     * fallback 側は**正本の hex をリテラルで埋め込む**ので、値の突き合わせも兼ねる。
     */
    it("不透明 token の /10 は正本の hex を 10% で透明と混ぜた形になる", () => {
        const decls = soleRule(sealed, ".bg-primary\\/10");
        // ★`Map#get` の undefined が文字列補間で "undefined" になり、
        //   「意図した解析失敗」ではなく「文字列が一致しないだけ」の赤に化けるのを防ぐ
        //   (Round 1 レビューの Warning)。不在は例外にする。
        const expected = requiredMapValue(designColors(), "primary", "DESIGN.md colors.primary");
        expect(requiredMapValue(decls, "background-color", ".bg-primary/10")).toBe(
            `color-mix(in srgb, ${expected} 10%, transparent)`,
        );
    });

    it("@supports の中は var() 参照の oklab 混色になる", () => {
        // 条件つき at-rule の中は soleRule が拾わないので、条件つきの側を明示的に見る。
        // 条件の綴りは allowlist と突き合わせる (D の ALLOWED_HOVER_CONDITIONS と同じ方針)。
        …
    });

    it("alpha を値に持つ派生 token への修飾は実効 alpha が積になる (S5 が合成対象にする根拠)", () => {
        const decls = soleRule(sealed, ".bg-primary-soft\\/40");
        const soft = requiredMapValue(cssColorTokens(), "primary-soft", "--color-primary-soft");
        expect(requiredMapValue(decls, "background-color", ".bg-primary-soft/40")).toBe(
            `color-mix(in srgb, ${soft} 40%, transparent)`,
        );
        // 透明との混色は乗算済み alpha なので、実効 alpha は token の alpha × 修飾の alpha に確定する。
        const parsed = parseCssColor(soft);
        expect(parsed.kind).toBe("alpha");
        if (parsed.kind !== "alpha") return;
        expect(parsed.alpha * 0.4).toBeCloseTo(0.048, 6);
    });

    /**
     * ★派生 token の**導出関係**を機械で固定する (Round 1 レビューの Critical)。
     *   `COMPILED_VALUE_EXEMPT_TOKENS` が免除しているのは「DESIGN.md に期待値が無い」
     *   ことの表明にとどまり、**別の rgba へ静かに差し替わる**ことまで許してはいない。
     *   これが無いと、S6 で primary を直したのに primary-soft を直し忘れた状態が
     *   (生成 CSS の出現とコントラストが偶然通れば) 検出できない。
     */
    it("--color-primary-soft は正本の primary の RGB を alpha 0.12 にしたものである", () => {
        const soft = parseCssColor(
            requiredMapValue(cssColorTokens(), "primary-soft", "--color-primary-soft"),
        );
        const primary = parseCssColor(
            requiredMapValue(designColors(), "primary", "DESIGN.md colors.primary"),
        );
        expect(soft.kind).toBe("alpha");
        expect(primary.kind).toBe("opaque");
        if (soft.kind !== "alpha" || primary.kind !== "opaque") return;
        expect(soft.rgb).toEqual(primary.rgb);
        expect(soft.alpha).toBe(0.12);
    });
});
```

`requiredMapValue()` は共有ヘルパとして `tests/js/styles/theme-map.ts` に置く
(`Map#get` の `undefined` を文字列補間で `"undefined"` に化けさせないため。
不在は**例外**にする = i20)。

### 型適合チェック

- [x] 戻り値の型が明示されている / [x] `null` 安全 (`soleRule` は 0 件も重複も落とす) /
      [x] 配列返却なし / [x] Generics なし

### テスト計画

- [x] **先に赤くするテスト**: 上の 4 it。`UTILITY_CANDIDATES.alpha` を足す前は
      `.bg-primary\/10` の規則が生成されないので `soleRule` が「1 件でない」で**赤**になる。
      派生の導出関係の it は、`--color-primary-soft` の RGB を 1 文字変えた検体で
      赤になることを確認してから実値へ戻す
- [x] 空振り防止: 既存の `it.each(Object.entries(UTILITY_CANDIDATES))` が
      新区分 `alpha` も 0 件でないことを自動で見る (区分を足すだけで検査が増える形)
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- Tailwind の版が上がって生成形が変わると赤くなる。**それは緩める理由ではなく、
  合成モデルを見直す契機である** (同ファイル冒頭のリスク欄と同じ方針を明記する)。

---

## S5 半透明背景 × 不透明文字の合成検査を新設する (i16)

### 変更箇所

- `tests/js/architecture/contrast-invariant.test.ts` (合成関数と describe を追加 / docblock の
  「検査しないもの」を書き換え)
- `tests/js/styles/inventory.ts` (`ALPHA_CONTRAST_PAIRS` / `UNDECIDABLE_PAIR_LEDGER` を新設、
  `PENDING_CONTRAST_PAIRS` を書き換え)

### 波及変更

- TypeScript 型定義: `inventory.ts` に台帳の型を新設 (`UndecidableReason` を `class-usage.ts` から import)
- API Resource/DTO: なし
- テストファイル: `contrast-invariant.test.ts` のみ
- **共有パス**: `contrast-invariant.test.ts` → S12

### 現行コード

```ts
// tests/js/styles/inventory.ts (L97-101)
export const PENDING_CONTRAST_PAIRS = [
    "WCAG 1.4.11 非テキストコントラスト (3:1): border / border-strong / focus ring",
    "alpha 合成ペア: Badge の bg-<tone>/10 + text-<tone>、bg-primary-soft、ring-primary/35、" +
        "bg-text/70 + text-surface (合成後の実効色が親背景に依存しトークン単体では定まらない)",
] as const;
```

### 変更後コード

```ts
// tests/js/styles/inventory.ts
/**
 * 半透明の背景 × 不透明な文字の組の台帳 (正典 i16)。
 *
 * ★**走査で見つかった半透明の組は全件がここに載る**ことを contrast-invariant が
 *   集合一致で固定する (件数だけの pin にしない = 新しい使用を件数更新で通せない)。
 * ★**下地は宣言しない**。実在する不透明な下地 = 役割分類の「面」(`surface` 役割を持つ token =
 *   `SURFACE_ROLE_TOKENS`) の**すべて**の上で 4.5:1 を要求するので、部品がどちらに置かれても成立する。
 * ★**「面」と「テキストを載せる塗り」は別物である** (思考原則 4)。
 *   `border` は Button の hover 塗りとしてテキストを載せるので
 *   `declared-text-background` の役割を持つが、**容器の背景として宣言された用途は無い**ので
 *   「面」ではなく、半透明の合成の**下地には数えない**。
 *   下地に数えると、実際には起きない重ね方 (ソフト背景のバッジを Button の hover 塗りの上へ置く)
 *   を根拠にテーマ値の是正を要求することになる。この線引きは**宣言であって導出ではない**ことを
 *   gate 本体に書く (静的走査は親要素を辿れない = 正典 i22 (2))。
 * ★行番号は持たない (正典 s14)。ファイル単位までである。
 */
export const ALPHA_CONTRAST_PAIRS = [
    // ★キーは **tokens.css の `--color-<suffix>` 空間** である (下の「2 つのキー空間」を参照)。
    // ★`alpha` は**実効 alpha** である。alpha を値に持つ派生 token (`primary-soft` = 0.12) に
    //   さらに修飾が付く形は積で確定する (`bg-primary-soft/40` → 0.12 × 0.40 = 0.048)。
    //   生成形は tokens.test.ts の「H. 不透明度修飾の生成形」が固定する。
    { fg: "danger", bg: "danger", alpha: 0.1 },
    { fg: "primary", bg: "primary", alpha: 0.1 },
    { fg: "primary", bg: "primary-soft", alpha: 0.12 },
    { fg: "success", bg: "success", alpha: 0.1 },
    { fg: "surface", bg: "text", alpha: 0.7 },
    { fg: "tertiary", bg: "tertiary", alpha: 0.1 },
    { fg: "text", bg: "danger", alpha: 0.1 },
    { fg: "text", bg: "primary-soft", alpha: 0.12 },
    { fg: "text", bg: "primary-soft", alpha: 0.048 }, // bg-primary-soft/40 (二重 alpha を計算する)
    { fg: "text", bg: "surface", alpha: 0.8 },
    { fg: "text", bg: "warning", alpha: 0.1 },
    { fg: "text-secondary", bg: "surface", alpha: 0.8 },
    { fg: "warning", bg: "warning", alpha: 0.1 },
] as const satisfies readonly AlphaPair[];

/**
 * 静的に組を決められなかった単位の台帳 (正典 i16「例外にして静かに素通りさせない」)。
 *
 * ★識別子は **(ファイル, 理由, 件数) の完全一致**である (Round 1 レビューの Critical:
 *   (ファイル, 理由) だけだと、同じファイルに同じ理由の未解析箇所が**増えても集合が変わらず**
 *   追加を検出できない)。**行番号は持たない** (正典 s14: 無関係な 1 行の追加でずれ、
 *   期待値の機械的な更新が常態化して統制が形骸化する)。
 * ★不透明のみの不完全な単位 (前景か背景の片方しか無い) は**ここに載せない** —
 *   `bg-surface` 単独が 39 単位・`bg-neutral` 単独が 20 単位あり、実体集合で pin すると
 *   期待値の機械的な更新が常態化して統制が形骸化する (正典 s14 と同じ理由)。
 *   そちらは「分類の全数性」を固定検体で受け、組そのものは i14 の役割直積が覆う。
 * ★`double-alpha` は**もう理由の値域に無い**。実効 alpha が積で確定するので
 *   `ALPHA_CONTRAST_PAIRS` 側で計算する (i16 は「静的に決められない形」だけを例外にする)。
 */
export const UNDECIDABLE_PAIR_LEDGER = [
    { file: "components/atoms/Button.types.ts", reason: "keyword-color", count: 2,
      note: "ghost / danger-ghost の bg-transparent。背景は親から来る" },
    { file: "components/atoms/Button.types.ts", reason: "element-opacity", count: 2,
      note: "success / danger の hover:opacity-90 (要素全体の不透明度)" },
    { file: "components/atoms/input-state.ts", reason: "interpolated", count: 1,
      note: "完成した class 文字列を補間で差し込む (border の状態)" },
    { file: "components/atoms/input-state.ts", reason: "foreground-alpha", count: 1,
      note: "placeholder:text-text-secondary/70 (前景に不透明度修飾)" },
    { file: "components/features/notifications/NotificationListItem.svelte",
      reason: "alpha-background-no-text", count: 1,
      note: "unread 時の bg-primary-soft/40 だけを持つリテラル (前景は別のリテラル)" },
    /* … alpha-background-no-text の残り 12 ファイル (実装時に走査結果で確定させる) … */
] as const satisfies readonly UndecidableEntry[];

/** 未検査であることを明示する pending 集合。**i16 の完了後も空にならない**。 */
export const PENDING_CONTRAST_PAIRS = [
    "WCAG 1.4.11 非テキストコントラスト (3:1): border / border-strong / focus ring (正典 i17 により本 gate の対象外)",
    "UNDECIDABLE_PAIR_LEDGER に載せた分類 (前景の alpha / 色キーワード / " +
        "前景を持たない alpha 背景 / 塗り面と alpha 背景の同居 / 背景の多重宣言 / " +
        "前景の多重宣言 / 要素全体の不透明度 / 補間)。値域の正本は UndecidableReason で、" +
        "分類の全数性は contrast-invariant の it が never への収束で固定する",
] as const;
```

```ts
// tests/js/architecture/contrast-invariant.test.ts (追加)

/**
 * 半透明の背景を不透明な下地の上へ合成する。
 *
 * 【本 gate が採用する**近似モデル** (版や環境で変わりうるので gate 本体に書く)】
 *   1. 不透明度修飾は `color-mix(…, transparent)` へ展開され、**透明との混色は
 *      同じ色の alpha になる** (透明側の乗算済み色が寄与しないため色相・明度は変わらない)。
 *      alpha を値に持つ token にさらに修飾が付く形は**実効 alpha が積**になる。
 *      生成形そのものは tokens.test.ts の「H. 不透明度修飾の生成形」が固定する
 *   2. 合成は**チャンネルごとの `a*FG + (1-a)*BG`** で、ガンマ符号化された sRGB 値を
 *      直接ブレンドする (web の既定)
 *   3. 比の計算に使うのは **8bit へ丸めた値**である。丸めまで再現しないと
 *      docs/design-system.md の記録値と 0.01 ずれる
 *   ★これは「ブラウザが必ずこう描く」という主張ではない (Round 1 レビューの Warning)。
 *     **本 gate が判定に使う近似**であり、近似が判定を変えていないことは
 *     「丸めない合成との比が 4.5 の境界を跨がない」検査が別に固定する。
 *   ★広い色域 (Display P3 等) の実描画との厳密一致は**測っていない** (正典の未決論点 q3)。
 */
function compositeOverOpaque(color: ParsedColor, alpha: number, base: Rgb): Rgb { … }

describe("architecture/contrast-invariant: 半透明背景 × 不透明文字 (面のすべての上で 4.5:1)", () => {
    it("走査で見つかった半透明の組と台帳が集合一致する (件数だけの pin にしない)", () => { … });
    it("判定不能の単位と台帳が (ファイル, 理由, 件数) の完全一致で揃う", () => { … });
    it("台帳の理由が UndecidableReason の値域に収まり、分類が全数である (never で収束)", () => { … });
    it.each(ALPHA_CONTRAST_PAIRS)("%o が面のすべての上で 4.5:1 以上", ({ fg, bg, alpha }) => {
        for (const base of SURFACE_ROLE_TOKENS) { … }
    });
    it("負のコントロール: 是正前の値では 5 組が AA を割る", () => {
        // 家系で実在した違反値を固定する (正典 i18 (d))。
        // primary #2563EB の 12% を neutral #F4F4F5 の上へ合成 → 4.01 で 4.5 を割る。
        expect(ratioOfComposite("#2563eb", "#2563eb", 0.12, "#f4f4f5")).toBeLessThan(4.5);
        // 是正後の値では通る。
        expect(ratioOfComposite("#1d4ed8", "#1d4ed8", 0.12, "#f4f4f5")).toBeGreaterThanOrEqual(4.5);
    });
    it("負のコントロール: 8bit の丸めを省くと記録値とずれる", () => { … });
    it("近似の裏取り: 丸めない合成との比が 4.5 の境界を跨ぐ組が無い", () => {
        // 8bit へ丸める近似が**判定そのものを変えていない**ことを固定する。
        // 跨ぐ組が現れたら、その組は近似の当否に判定が依存しているので、
        // 近似モデルの側を見直す契機になる (緩める理由にはしない)。
        for (const pair of ALPHA_CONTRAST_PAIRS) {
            for (const base of SURFACE_ROLE_TOKENS) {
                const rounded = ratioRounded(pair, base);
                const exact = ratioUnrounded(pair, base);
                expect(rounded >= 4.5, `${pair.fg} on ${pair.bg} over ${base}`).toBe(exact >= 4.5);
            }
        }
    });
});
```

### 2 つのキー空間 (取り違えの防止)

`inventory.ts` は**2 つのキー空間**を扱う。取り違えると別のトークンを検査してしまうので、
どちらの空間かを宣言ごとに docblock へ書き、境界は `COLOR_TOKEN_MAP` の 1 本だけにする。

| 空間 | 使う宣言 | 例 |
|---|---|---|
| **DESIGN.md の色キー** (13 件) | 役割分類 (`COLOR_TOKEN_ROLES` と、そこから導出する `SURFACE_ROLE_TOKENS` / `TEXT_ON_SURFACE_TOKENS` / `FILL_TOKENS` / `FILL_LABEL_TOKENS` / `NON_TEXT_BOUNDARY_REASONS` / `DECLARED_CONTRAST_PAIRS`) | `text-primary` = **本文色** |
| **tokens.css の `--color-<suffix>`** (14 件) | 半透明の台帳 (`ALPHA_CONTRAST_PAIRS`)、走査器の出力、生成 CSS 検査 | `text` = 本文色 / `text-primary` は**存在しない** |

- 派生トークン `primary-soft` は**DESIGN.md に無い**ので、半透明の台帳は
  suffix 空間で書かなければ表現できない (これが空間を分ける実質的な理由である)。
- **走査器 (`class-usage.ts`) は suffix 空間だけを返す**。役割の母集団と突き合わせるときに
  gate が `COLOR_TOKEN_MAP` の逆写像で DESIGN キー空間へ写す。
- 逆写像が一意であること (`COLOR_TOKEN_MAP` の値に重複が無いこと) は
  **S1 で `canonical-source-parity.test.ts` に it を 1 本足して固定する**
  (重複があると逆写像が後勝ちになり、別のトークンの値で検査してしまう)。

### 型適合チェック

- [x] 戻り値の型が明示されている
- [x] `null` 安全: `hex()` は不在で例外 (既存の形を踏襲)
- [x] 配列返却ではなく `as const satisfies` の台帳 (キーの取り違えが型で落ちる)
- [x] `UndecidableReason` の網羅を `never` へ収束させる

### テスト計画

- [x] **先に赤くするテスト**: `it.each(ALPHA_CONTRAST_PAIRS)` の AA 検査。
      **S6 (値の是正) の前なので 5 組が実際に赤くなる** — これが本設計の
      「実測が設計の見込みを覆した」記録そのものである
- [x] 集合一致の 2 it も、台帳を空で置いた状態で**先に赤**を確認する
- [x] 負のコントロール: 是正前の値で落ちること / 是正後の値で通ること / 丸めを省くとずれること
- [x] 既存テストの削除・上書きをしない:
      「未検査宣言 (PENDING_CONTRAST_PAIRS) が空でない」は**据え置く**
      (i17 の 1 行と判定不能の 1 行が残るので空にならない)
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- 台帳が 20 行前後になり、初見では冗長に見える。
  → 「不透明のみの不完全な単位は載せない」線引きを docblock に書き、
  台帳が肥大しないことを構造で担保する。
- 走査結果と台帳の集合一致は、**新しいソフト背景を足すたびに台帳の更新を要求する**。
  これは意図した摩擦である (正典 i16 の「全件が台帳に載る」)。

---

## S6 トークン値を是正する (i16 の帰結)

### 変更箇所

- `DESIGN.md` frontmatter L6-9 / L16-17 (色値 6 件)
- `DESIGN.md` L71-72 (§Overview の色記述) / L79 / L82 / L100 / L102 (§Colors・§状態色の本文)
- `DESIGN.md` L107-110 (**§状態色の規約文の改定**)
- `DESIGN.md` L112-114 付近 (**ソフト背景の置き場の規約行を追加**)
- `resources/css/tokens.css` L13-17 / L28-29 (色値 6 件 + `--color-primary-soft`)

### 波及変更

- TypeScript 型定義: なし (値だけの変更)
- API Resource/DTO: なし
- テストファイル: `canonical-source-parity` の値一致 / `tokens` の値検査 /
  `contrast-invariant` の不透明ペアと半透明ペアが**自動で追随する**
  (どれも DESIGN.md から導出しているので期待値の手書きは 1 か所も無い)
- `docs/design-system.md`: 値は書かれていないので更新不要 (grep で確認済み)。
  ただし §テーマの差し替え方の手順に「合成の検査も通ること」を 1 行足す (S11 に含める)
- **メールテンプレート** `resources/views/vendor/mail/html/themes/template.css` は
  独立パレットなので**追随させない** (DS token の写像ではない。既存の線引きどおり)

### 現行コード / 変更後コード

| 位置 | 現行 | 変更後 |
|---|---|---|
| `DESIGN.md:6` / `tokens.css:13` | `#2563EB` | `#1D4ED8` (blue-700) |
| `DESIGN.md:7` / `tokens.css:14` | `#1D4ED8` | `#1E40AF` (blue-800) |
| `DESIGN.md:8` / `tokens.css:16` | `#0F766E` | `#115E59` (teal-800) |
| `DESIGN.md:9` / `tokens.css:17` | `#115E59` | `#134E4A` (teal-900) |
| `DESIGN.md:16` / `tokens.css:28` | `#15803D` | `#166534` (green-800) |
| `DESIGN.md:17` / `tokens.css:29` | `#B45309` | `#92400E` (amber-800) |
| `tokens.css:15` | `rgba(37, 99, 235, 0.12)` | `rgba(29, 78, 216, 0.12)` |
| `DESIGN.md:18` / `tokens.css:30` | `#B91C1C` | **据え置き** (soft でも 4.98 で足りる) |

§状態色の規約文 (現行 L107-110):

```markdown
状態色・アクセントは Tailwind の **-700 段**で揃える(`tertiary` teal-700 / `success` green-700 /
`warning` amber-700 / `danger` red-700)。`neutral`(#F4F4F5)や `surface`(#FFFFFF)の上で
**本文コントラスト 4.5:1** を確保するための下限であり、これより明るい段は使わない
(`tests/js/architecture/contrast-invariant.test.ts` が機械検証する)。
```

変更後:

```markdown
状態色・アクセントの段は**段の名前ではなくコントラストの実測で決める**。満たすべき条件は 2 つで、
**面として分類した token の上で本文コントラスト 4.5:1** と、
**同じ色のソフト背景(不透明度 10〜12%)の上でも 4.5:1** である。後者が効くため、
実際に選べるのは概ね **-800 段**になる(既定テーマは `tertiary` teal-800 / `success` green-800 /
`warning` amber-800 / `danger` red-700 で、`danger` だけは -700 でも両条件を満たす)。
**段を機械的に揃えるのではなく、`tests/js/architecture/contrast-invariant.test.ts` の
実測で決めること**(不透明ペアと半透明ペアの両方を機械検証する)。

ソフト背景は状態色の opacity 修飾で表現する(`bg-success/10`, `bg-danger/10`,
`bg-primary-soft` 等)。**ソフト背景の部品は面として分類した token の上にのみ置く**
(既定テーマでは `neutral` / `surface`)。塗り面(`bg-primary` 等)の上へ重ねると
合成後の実効色が前景と同色になり、どの値を選んでも 4.5:1 を満たせない
(静的走査は親要素を辿れないため、この規約は機械では部分的にしか保証されない —
保証範囲は contrast gate の docblock が持つ)。
```

### 型適合チェック

- [x] 該当なし (値のみ。TypeScript の変更なし)

### テスト計画

- [x] **順序が本質**: S5 で `it.each(ALPHA_CONTRAST_PAIRS)` の 5 組が**赤**であることを
      確認した後に本施策で値を変える (テストファースト。思考原則 5)
- [x] 是正後に緑になる範囲を実測で確認済み ([contrast-measurements.md](./contrast-measurements.md))
- [x] `canonical-source-parity` の値一致 / `tokens/A` の値検査が
      **DESIGN.md と tokens.css の両方を直さないと赤**であること (片側だけの変更を落とす既存機構)
- [x] **派生 token の追随は機械で保証する**: S10 が足す
      「`--color-primary-soft` は正本の primary の RGB を alpha 0.12 にしたもの」の it が、
      `primary` を直して `primary-soft` を直し忘れた状態を**赤**にする
      (Round 1 レビューの Warning。値免除の穴を塞ぐ)
- [x] 逆引き表 ([token-change-impact.md](./token-change-impact.md)) の 131 行で、
      非テキスト用途 (`border-*` / `ring-*` / `decoration-*` / `accent-*`) と
      テキストを載せない塗り面 (Toggle トラック / アイコン帯) を目視レビューする
- [x] **目視確認する画面 (5 面)** — ブランド色を動かすので、逆引き表の机上確認だけで終わらせない:
  1. 撮影画面のガイド帯・字幕帯 (`features/capture/ShootingGuideOverlay` /
     `molecules/SubtitleOverlay`。`bg-text/70` + `text-surface`)
  2. 通知一覧の未読行 (`features/notifications/NotificationListItem`。
     `bg-primary-soft/40` と `bg-primary-soft` + `text-primary`)
  3. Badge の 6 tone を並べて出す画面 (`pages/Welcome.svelte` の状態表示)
  4. サイドバーの選択中 (`templates/AppLayout` / `templates/_helpers/SidebarNavItems`。
     `bg-primary` + `text-surface`)
  5. 料金ページの強調カード (`pages/Guest/Pricing.svelte`。`border-primary/30` + `bg-primary-soft`)

### リスク

- **ブランド印象が変わる** (primary が blue-600 → blue-700)。
  → i1 によりテーマ値はプロジェクト裁量であり、正典が値を定めているわけではない。
  変更理由は「i16 を満たすための帰結」であり、規約文の改定として DESIGN.md に記録する。
  家系の先行事例 (motivation:T194) は同じ方向・同じ段へ動いている。
- **hover の視認性**: `primary` と `primary-hover` の差が blue-700 → blue-800 になり、
  明度差は現行 (blue-600 → blue-700) と同程度に保たれる (逆引き表で確認)。
- **disabled の見え方**: disabled は `opacity-40` / `text-text-secondary` で表現しており、
  是正対象 token に依存しない (逆引き表に disabled 状態の行が出ない = 実測)。

---

## S3 参照の閉包 gate を新設する (i9)

### 変更箇所

- 新規: `tests/js/styles/token-reference-closure.test.ts`
- `tests/js/styles/inventory.ts` (`NON_TOKEN_WORD_CONTRACT` を新設)
- `resources/js/components/templates/AppLayout.svelte` (L299 / L427: `text-white` → `text-surface`)
- `resources/js/components/templates/_helpers/SidebarNavItems.svelte` (L38: 同上)

### 波及変更

- TypeScript 型定義: 契約表の型を新設
- API Resource/DTO: なし
- テストファイル: `contrast-invariant.test.ts` の逆向き被覆に `(surface, primary)` が現れる
  (S7 で `surface` を `FILL_LABEL_TOKENS` へ足すことで直積の内側に入る)
- **見た目の変化は無い**: `--color-surface` は `#FFFFFF` で `text-white` と同色

### 変更後コード

```ts
// tests/js/styles/inventory.ts
/**
 * **token を指さない語**の契約表 (正典 i9)。
 *
 * ★これは許可一覧ではなく**検査対象の定義**である。テーマの名前空間の接頭辞を持つ語のうち、
 *   写像の宣言集合へ解決しないものは**全数がここに登録されていなければ不合格**になる。
 * ★Tailwind 既定テーマの色語 (`white` / `black` / raw palette) は**登録しない** —
 *   写像の外の token 空間を参照する形なので落とすのが正しい
 *   (実在した `text-white` 3 箇所は本施策で `text-surface` へ直す)。
 * ★**チャネルを型で分ける** (Round 1 レビューの Warning)。class の語と `var()` 参照を
 *   同じ無型の表へ入れると、**別のチャネルでの出現によって登録が生きているように見える**
 *   (`--app-sidebar-w` が class 語として出現しなくなっても、`var()` 側の出現で
 *   冗長判定をすり抜ける)。出現の突き合わせと冗長判定は**チャネル別**に行う。
 * ★登録するのは**正規化後の有効な完全 token** である。`text-center/50` のような
 *   「色でない utility に不透明度修飾が付いた形」は走査器が
 *   `unresolved: "alpha-on-non-color"` にするので、**契約表に登録しても救われない**。
 */
export type NonTokenWord =
    | { readonly kind: "class-word"; readonly word: string; readonly reason: string }
    | { readonly kind: "css-variable"; readonly name: string; readonly reason: string };

export const NON_TOKEN_WORD_CONTRACT = [
    { kind: "class-word", word: "bg-transparent", reason: "CSS の全域キーワード。色 token を指さない" },
    { kind: "class-word", word: "border-transparent",
      reason: "同上。全 variant で外形高さを揃えるための透明枠 (DESIGN.md §Components)" },
    { kind: "class-word", word: "border-2", reason: "境界の太さ。色ではない" },
    { kind: "class-word", word: "border-b", reason: "境界の辺の指定。色ではない" },
    { kind: "class-word", word: "border-b-0", reason: "同上 (打ち消し)" },
    { kind: "class-word", word: "border-l-2", reason: "同上" },
    { kind: "class-word", word: "border-r", reason: "同上" },
    { kind: "class-word", word: "border-t", reason: "同上" },
    { kind: "class-word", word: "border-dashed", reason: "境界の線種。色ではない" },
    { kind: "class-word", word: "divide-y", reason: "区切り線の軸。色ではない (色は divide-border が持つ)" },
    { kind: "class-word", word: "outline-none", reason: "outline の打ち消し。色ではない" },
    { kind: "class-word", word: "ring-2", reason: "focus ring の太さ。色ではない" },
    { kind: "class-word", word: "ring-3", reason: "同上" },
    { kind: "class-word", word: "rounded-full",
      reason: "角丸 ramp の外の真円 UI。radius token を指さず ds-purity の file-scoped allowlist が管轄する" },
    { kind: "class-word", word: "text-center", reason: "テキストの整列。色でも ramp でもない" },
    { kind: "class-word", word: "text-left", reason: "同上" },
    { kind: "class-word", word: "text-right", reason: "同上" },
    { kind: "css-variable", name: "--app-sidebar-w",
      reason: "同一要素の style 属性で宣言する局所変数。@theme の token ではない " +
              "(他ファイルのローカル宣言を解決の根拠に数えない)" },
] as const satisfies readonly NonTokenWord[];
```

```ts
// tests/js/styles/token-reference-closure.test.ts (新設)
/**
 * 参照の閉包 (正典 i9) — 自リポジトリのスタイルと画面のコードが参照する token 名が、
 * すべて写像 (resources/css/tokens.css の @theme) の宣言集合へ解決することを検査する。
 *
 * 【なぜ要るか】綴り誤りは「無スタイル」として静かに消える。Tailwind は未知の utility を
 *   エラーにせず、単に生成しない。
 * 【解決の根拠は写像 1 か所だけ】他ファイルのローカル宣言 (style 属性 / 別 CSS の :root) を
 *   根拠に数えると、正本の外に token 空間が静かに育つ形が通ってしまう。
 * 【走査対象】
 *   - resources/js: 文字列リテラルの中の class トークン (class-usage.ts と同じ走査単位)
 *   - resources/js / resources/css: `var(--…)` 参照
 * 【保証しないもの】
 *   - resources/views 配下 (Laravel 同梱メールテーマの独立パレット) は対象外
 *   - 変種の修飾の綴り (`hoverr:`) は見ない (Tailwind の名前空間で写像ではない)
 *   - 走査単位の外 (動的に組み立てた class) は見ない。既知の入口は class-usage.ts が deny する
 */
```

検査項目:

1. `scanClassUsage().occurrences` のうち `resolution.kind === "unresolved"` が **0 件**であること。
   すなわち、テーマ名前空間の接頭辞を持つ class トークンはすべて
   **写像の宣言集合 / ramp 集合 / radius 集合 / 契約表 (`class-word`)** のいずれかへ解決する。
   ★走査器は S2 の 1 本だけを使う (2 本目のパーサを書かない = i21)
2. `scanCssVarReferences()` のうち `unresolved` が **0 件**であること
   (**写像の宣言集合か契約表 (`css-variable`)** へ解決する)
3. **契約表に冗長な登録が無い**。判定は**チャネル別**に行う —
   `class-word` の登録は class トークンとして 1 回以上出現し、かつ写像へは解決しないこと。
   `css-variable` の登録は `var()` 参照として 1 回以上出現し、かつ写像へは解決しないこと
4. **母集団が空でない** (class トークン数 > 0 / `var()` 参照数 > 0 / 契約表の 2 チャネルとも > 0)
5. 負のコントロール (固定検体):
   - `text-white` を含む検体 → 不合格になる (**Tailwind 既定テーマの色語を通さない**)
   - `bg-primaryy` (綴り誤り) → 不合格になる
   - `var(--color-does-not-exist)` → 不合格になる
   - 別ファイルの `:root` に `--color-foo` を宣言した検体 → **解決の根拠に数えない**
     (写像 1 か所だけという境界そのものを pin する)
   - 契約表の語 (`text-center` 等) は誤検出しない
   - **変種 / 重要度 / 不透明度の 3 形を別々に固定する** (共通規約 (e)) —
     接頭辞つき `sm:text-center` は解決する / 打ち消しつき `!text-center` は解決する /
     **接尾辞つき `text-center/50` は不合格**になる
     (色でない utility への不透明度修飾を「同じ語」として通すと、未知の utility が静かに通る)
   - `css-variable` の登録語を class トークンとして書いた検体 (`--app-sidebar-w` を class に置く) →
     **チャネルが違うので解決の根拠にならず不合格**になる

### 型適合チェック

- [x] 戻り値の型が明示されている / [x] `null` 安全 (解決失敗は結果に残して gate が落とす) /
      [x] 配列返却ではなく union / [x] Generics 正しい

### テスト計画

- [x] **先に赤くするテスト**: 検査 1。`text-white` が 3 箇所あるので**実装した時点で赤**になる。
      赤を確認してからアプリ側 3 箇所を直す (テストファースト。バグ修正の再現テストと同じ形)
- [x] 検査 3 (冗長な登録) を先に書き、契約表を空で置いて赤 → 埋めて緑
- [x] 負のコントロール 6 種を固定検体で置く (一時的に壊す形では代替しない = 正典 i18)
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- 契約表が Tailwind の構造 utility を足すたびに増える。
  → 増えるのは**テーマ名前空間の接頭辞を持つ語だけ**である (実測 17 件)。
  `flex` / `px-3` / `gap-2` のような語は接頭辞を持たないので母集団に入らない。
  この限定を docblock に書く。
- `text-white` → `text-surface` の置き換えでサイドバー選択中の見た目が変わらないこと
  (`--color-surface` = `#FFFFFF`) を実装時に目視で確認する。

---

## S7 実装からの逆向き被覆と役割分類の是正 (i15 / i14)

### 変更箇所

- `tests/js/styles/inventory.ts`
  (**`COLOR_TOKEN_ROLES` を新設して 5 つの役割配列をそこから導出する** /
  `CONTRAST_EXEMPT_TOKENS` を `NON_TEXT_BOUNDARY_REASONS` へ作り替える /
  `DECLARED_CONTRAST_PAIRS` を新設)
- `tests/js/architecture/contrast-invariant.test.ts` (逆向き被覆の describe を追加 /
  役割分類の全数性の it を個別宣言ペアまで含む形へ拡張)

### 波及変更

- TypeScript 型定義: `DeclaredPair` を新設
- API Resource/DTO: なし
- テストファイル: `contrast-invariant.test.ts` のみ。既存の 4 it と `it.each(PAIRS)` は据え置く
- **共有パス**: `contrast-invariant.test.ts` → S12

### 現行コード

```ts
// tests/js/styles/inventory.ts (L59-85)
export const FILL_TOKENS = ["primary","primary-hover","tertiary","tertiary-hover","success","warning","danger"] as const;
export const FILL_LABEL_TOKENS = ["neutral"] as const;
export const CONTRAST_EXEMPT_TOKENS = {
    "border": "1px の区切り線・入力欄の枠。テキストではなく WCAG 1.4.11 (非テキスト 3:1) の領域。…",
    "border-strong": "区切りの強調・ghost ボタンの枠。…",
} as const;
```

### 走査で判明した役割分類の食い違い 2 件と、その決着

| 食い違い | 実測 | 決着 |
|---|---|---|
| `bg-border` が**テキストを載せた塗り面**として使われている (`atoms/Button.types.ts` の neutral variant の hover) のに、`border` は 1.4.11 の免除に入っている | `text-text` on `bg-border` = 13.96 で AA を満たす | **`border` を免除から外し、個別宣言ペアで受ける**。`FILL_TOKENS` には**入れない** |
| `text-surface` が塗り面のラベルとして使われている (字幕帯 / 撮影中バッジ / サイドバーの選択中) のに、`surface` は `FILL_LABEL_TOKENS` に無い | `surface` × 全塗り面 = 6.70〜9.48 で全組が AA を満たす (是正後の値) | **`FILL_LABEL_TOKENS` へ `surface` を追加する** (直積が全組成立するので直積で受けられる) |

**`border` を `FILL_TOKENS` へ入れない理由 (設計判断)**: 入れると直積に
`neutral on border` (**1.15**) と `surface on border` (**1.27**) が生まれるが、
**この 2 組は実装に 1 件も存在しない** (`bg-border` の上に載るのは `text-text` だけである)。
実在しない組を検査すると誤検知になる。正典 i14 は
「役割の直積で表現できない正当な 1 対 1 の組は**個別宣言ペア**として理由つきで足し、
直積と同じ閾値を課す」と定めており、これはまさにその用途である。
**逆に `surface` は直積が全組成立するので直積側で受ける** — 個別宣言ペアは
「直積で表現できないもの」に限る (安易に個別宣言へ逃がすと母集団が痩せる)。

### 変更後コード

```ts
// tests/js/styles/inventory.ts

/**
 * 色 token の役割。**1 つの token が複数の役割を持ちうる** (思考原則 4: 別物の用途を統合しない)。
 *
 * ★Round 1 レビューの Critical を受けた作り直しである。旧設計は
 *   「個別宣言ペアに現れた token を役割分類済みと数える」形だったため、
 *   **任意の新 token を 1 組だけ登録すれば全色被覆の既定拒否を通せる**穴があった。
 *   役割の全数性は本表のキーと DESIGN.md の色キーの集合一致だけで見る。
 */
export type ColorRole =
    /** 面 = 容器の背景。**半透明の合成の下地でもある** (i16) */
    | "surface"
    /** 面の上に載るテキスト色 */
    | "text-on-surface"
    /** 塗り面 (solid fill) */
    | "fill"
    /** 塗り面の上に載るラベル色 */
    | "fill-label"
    /** 直積で表現できない、テキストを載せる塗り (個別宣言ペアの背景側にだけ現れる) */
    | "declared-text-background"
    /** 1px 境界・focus ring 等。WCAG 1.4.11 の別の閾値体系なので本 gate の対象外 (i17。理由必須) */
    | "non-text-boundary";

/**
 * ★**役割分類の唯一の宣言**。既存の 5 つの配列は**ここから導出する** (i4)。
 * ★キーは **DESIGN.md の色キー空間**である (`text-primary` は本文色 = `--color-text`)。
 */
export const COLOR_TOKEN_ROLES = {
    "primary": ["text-on-surface", "fill"],
    "primary-hover": ["fill"],
    "tertiary": ["text-on-surface", "fill"],
    "tertiary-hover": ["fill"],
    "neutral": ["surface", "fill-label"],
    "surface": ["surface", "fill-label"],
    // ★2 役割を持つ: 1px 枠 (対象外) と、Button の neutral variant の hover 塗り (検査する)
    "border": ["non-text-boundary", "declared-text-background"],
    "border-strong": ["non-text-boundary"],
    "text-primary": ["text-on-surface"],
    "text-secondary": ["text-on-surface"],
    "success": ["text-on-surface", "fill"],
    "warning": ["text-on-surface", "fill"],
    "danger": ["text-on-surface", "fill"],
} as const satisfies Readonly<Record<string, readonly ColorRole[]>>;

/** 導出 (固定配列を持たない = i4)。 */
export const SURFACE_ROLE_TOKENS = tokensWithRole("surface");
export const TEXT_ON_SURFACE_TOKENS = tokensWithRole("text-on-surface");
export const FILL_TOKENS = tokensWithRole("fill");
export const FILL_LABEL_TOKENS = tokensWithRole("fill-label");

/**
 * `non-text-boundary` の役割を持つ token の理由 (理由必須。正典 i17)。
 *
 * ★**キー集合が `tokensWithRole("non-text-boundary")` と一致する**ことを機械で見る
 *   (理由だけ残る / 役割だけ足す のどちらも落とす)。
 * ★**「この token は一切検査しない」という意味ではない**。`border` は
 *   `declared-text-background` の役割も持つので、その用途は個別宣言ペアで検査される。
 */
export const NON_TEXT_BOUNDARY_REASONS = {
    "border":
        "1px の区切り線・入力欄の枠としての用途。WCAG 1.4.11 (非テキスト 3:1) の別の閾値体系で、" +
        "装飾的な境界線は 1.4.11 の適用除外にあたるため、使用箇所ごとの役割分類が要る " +
        "(家系の未決論点 q2 の担当)。**テキストを載せる塗りとしての用途は別の役割で検査する**",
    "border-strong":
        "3 つの用途がいずれも本 gate の対象外である — (1) 1px の区切り線・入力欄の枠 " +
        "(WCAG 1.4.11 の非テキスト 3:1 で別の閾値体系。役割モデルが未定のため家系の未決論点 q2 の担当)、" +
        "(2) Toggle のトラック (テキストを載せない塗り)、" +
        "(3) 無効化したタブのラベル (SC 1.4.3 は無効化された UI 部品を適用除外にしている)。" +
        "実測 2.56 で 3:1 に届かないので、値の是正は 1.4.11 の役割モデルを DESIGN.md に" +
        "定めてから別バッチで行う",
} as const;

/**
 * 役割の直積で表現できない正当な 1 対 1 の組 (理由必須。正典 i14)。
 *
 * ★直積と**同じ閾値** (4.5:1) を課す。
 * ★**キーは DESIGN.md の色キー空間**である。走査器が返す CSS suffix 空間とは別なので、
 *   突き合わせは COLOR_TOKEN_MAP の逆写像で行う。
 * ★**役割分類の既定拒否をここで迂回できない** — 本表に現れた token を
 *   「分類済み」と数えるのはやめ、分類の全数性は `COLOR_TOKEN_ROLES` だけで見る。
 *   本表に対しては別の集合一致を課す (下の 3 条)。
 */
export const DECLARED_CONTRAST_PAIRS = [
    {
        fg: "text-primary",
        bg: "border",
        reason:
            "Button の neutral variant の hover (hover:bg-border + text-text)。" +
            "border を塗り面の役割へ入れると直積に neutral on border (1.15) と " +
            "surface on border (1.27) が生まれるが、この 2 組は実装に 1 件も無い。" +
            "border の 1px 枠としての用途は WCAG 1.4.11 (別の閾値体系) で本 gate の対象外である",
    },
] as const satisfies readonly DeclaredPair[];
```

**個別宣言ペアに課す 3 条** (これが無いと「1 組登録して全色被覆を通す」経路が残る):

1. 背景側は `declared-text-background` の役割を持つこと
2. 前景側は `text-on-surface` か `fill-label` の役割を持つこと
3. `declared-text-background` の役割を持つ token は、**本表の背景側に 1 回以上現れる**こと
   (役割だけ宣言して組を書かない = 死んだ宣言を作らせない)。
   加えて背景側は `surface` / `fill` の役割を**持たない**こと
   (持つなら直積で受けられるので個別宣言は冗長である)

```ts
// tests/js/architecture/contrast-invariant.test.ts (追加)

/** 個別宣言ペアも直積と同じ閾値を課す (正典 i14)。 */
const PAIRS = [
    ...TEXT_ON_SURFACE_TOKENS.flatMap(/* 既存 */),
    ...FILL_LABEL_TOKENS.flatMap(/* 既存 */),
    ...DECLARED_CONTRAST_PAIRS.map((p) => [p.fg, p.bg, "個別宣言ペア"] as const),
];

describe("architecture/contrast-invariant: 実装からの逆向き被覆 (i15)", () => {
    it("走査の分母が空でない (ディレクトリ単位の走査が生きている)", () => {
        const scan = scanClassUsage();
        expect(scan.files.length).toBeGreaterThan(0);
        for (const [dir, count] of scan.perDirectory) {
            expect(count, `${dir} から 1 件も抽出できていない`).toBeGreaterThan(0);
        }
    });

    it("走査で得た不透明ペアがすべて母集団 (役割の直積 + 個別宣言) の内側にある", () => {
        // 役割の宣言を書かずに新しい組を足す経路を塞ぐ。
        // 走査は CSS suffix 空間なので COLOR_TOKEN_MAP の逆写像で母集団へ写す。
        …
    });

    it("既知の要求組が抽出結果から実際に生成される (抽出の空振り防止)", () => {
        // Badge の 5 tone と Button の 8 variant が期待どおり出ること (正典 i15)。
        …
    });

    it("面の役割とテキストの役割が素である (自己ペア = 比 1.0 が混入しない)", () => {
        // 既存 it の等価な置き換え (導出後の配列で見る)。
        const surfaces = new Set<string>(SURFACE_ROLE_TOKENS);
        expect(TEXT_ON_SURFACE_TOKENS.filter((t) => surfaces.has(t))).toEqual([]);
    });

    it("走査器が扱えない既知の入口が 0 件である", () => {
        expect(unsupportedEntryPoints()).toEqual([]);
    });

});
```

> **解決できなかった class トークン (`resolution.kind === "unresolved"`) を 0 件に固定するのは
> S3 (参照の閉包) の担当**である。同じ主張を 2 つの gate へ書くと、片方を緩めたときに
> もう片方が残っていることが分かりにくくなる (責務境界は `docs/design-system.md` の表が正本)。
> 走査器は `unresolved` を**結果に必ず残す** (無言で候補から外さない = 共通規約 (b) の 1 点目)。

既存 it の書き換え (**個別宣言ペアを分類の根拠に数えない**形へ):

```ts
it("役割宣言が DESIGN.md の全色トークンを覆う (deny-by-default)", () => {
    // ★分類の全数性は COLOR_TOKEN_ROLES **だけ**で見る。
    //   個別宣言ペアに現れることを「分類済み」と数えると、任意の新 token を
    //   1 組登録するだけで既定拒否を通せてしまう (Round 1 レビューの Critical)。
    expect(Object.keys(COLOR_TOKEN_ROLES).sort()).toEqual(Object.keys(COLOR_TOKEN_MAP).sort());
    for (const [token, roles] of Object.entries(COLOR_TOKEN_ROLES)) {
        expect(roles.length, `${token}: 役割が 0 件`).toBeGreaterThan(0);
    }
});

it("non-text-boundary の役割と理由の集合が一致する (理由だけ残る / 役割だけ足す を落とす)", () => {
    expect(Object.keys(NON_TEXT_BOUNDARY_REASONS).sort()).toEqual(
        [...tokensWithRole("non-text-boundary")].sort(),
    );
    for (const [token, reason] of Object.entries(NON_TEXT_BOUNDARY_REASONS)) {
        expect(reason.length, `${token}: 理由`).toBeGreaterThan(30);
    }
});

it("個別宣言ペアが 3 条を満たす (直積の既定拒否を迂回できない)", () => {
    const declaredBackgrounds = new Set(DECLARED_CONTRAST_PAIRS.map((p) => p.bg));
    for (const p of DECLARED_CONTRAST_PAIRS) {
        expect(rolesOf(p.bg), `${p.bg}: 背景側の役割`).toContain("declared-text-background");
        expect(rolesOf(p.bg), `${p.bg}: 直積で受けられる背景は個別宣言にしない`)
            .not.toContain("surface");
        expect(rolesOf(p.bg)).not.toContain("fill");
        expect(
            rolesOf(p.fg).some((r) => r === "text-on-surface" || r === "fill-label"),
            `${p.fg}: 前景側の役割`,
        ).toBe(true);
        expect(p.reason.length, `${p.fg} on ${p.bg}: 理由`).toBeGreaterThan(30);
    }
    // 役割だけ宣言して組を書かない = 死んだ宣言を作らせない
    for (const token of tokensWithRole("declared-text-background")) {
        expect(declaredBackgrounds.has(token), `${token}: 役割はあるが個別宣言ペアが無い`).toBe(true);
    }
});
```

### 型適合チェック

- [x] 戻り値の型が明示されている
- [x] `null` 安全 (`hex()` は不在で例外。`COLOR_TOKEN_MAP` の逆写像が引けない suffix は例外)
- [x] 配列返却ではなく `as const satisfies readonly DeclaredPair[]` の宣言
- [x] Generics の型パラメータが正しい

### テスト計画

- [x] **先に赤くするテスト**: 「走査で得た不透明ペアがすべて母集団の内側にある」。
      役割分類を直す**前**に実行すると `(text, border)` が母集団の外なので**赤**になる。
      **S3 が先に済んでいる**ので `text-white` は `text-surface` へ直っており、
      `(surface, primary)` も同時に赤で現れる — `surface` に `fill-label` の役割を足し、
      `border` に `declared-text-background` の役割と個別宣言ペアを足すまで赤が続く。
      これが役割分類と実装の食い違いの実証である
- [x] 「個別宣言ペアが 3 条を満たす」は、`border` に `surface` の役割を足した検体
      (= 直積で受けられるのに個別宣言している状態) で**赤**になることを先に確認する
- [x] 「役割宣言が DESIGN.md の全色トークンを覆う」は、`COLOR_TOKEN_ROLES` から 1 キーを
      抜いた検体で**赤**になることを確認する (個別宣言ペアで迂回できないことの裏取り)
- [x] `it.each(PAIRS)` に個別宣言ペアが加わることで**組の総数が増える**ことを確認する
      (母集団を痩せさせていないことの確認)
- [x] 既存テストの削除・上書きをしない: 既存の 4 it (役割の被覆 / 0 件でない / 素である /
      pending が空でない) と `it.each(PAIRS)` は**すべて据え置く** (被覆の it は拡張のみ)
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- 個別宣言ペアは「直積で表現できないもの」に限る規律が緩むと母集団が痩せる。
  → 登録の理由に「直積へ入れると実在しない組が生まれる」ことを**具体的な比の値つき**で
  書くことを様式にする (上記 `reason` の形)。レビューで判断できる。
- `surface` に `fill-label` の役割を足すと直積が 7 組増える。是正後の値では全組が
  6.70〜9.48 で成立する (実測)。**是正前の値では `surface on primary` が 5.17 で成立する**ので、
  S6 の前に足しても赤にはならない。

---

## S8 文書 ⇔ 実装の双方向一致 gate を新設する (i10)

### 変更箇所

- 新規: `tests/js/styles/component-doc-parity.test.ts`
- `tests/js/styles/design-md.ts` (`designComponentSections()` を追加 — 正本の解析は 1 実装へ集約)
- `tests/js/styles/inventory.ts` (`COMPONENT_DIR_CLASSIFICATION` / `COMPONENT_FILE_KINDS` /
  `COMPONENT_SECTION_MAPPINGS` を新設)
- `DESIGN.md` (§Components 冒頭の対象範囲を明記 + **4 節を追加**)

### 波及変更

- TypeScript 型定義: 分類表と申告表の型を新設
- API Resource/DTO: なし
- テストファイル: 新設 1 本 (S11 で責務境界表へ行を足す)

### 現行コード

```markdown
## Components

> component 仕様は実装(`resources/js/components/`)と型定義が真実。本節は意味論と
> 使い分けルールのみを定義する。各 component を追加したら本節に追記すること。
```

31 節が並ぶ。実測で**節を持たない部品が 4 本**ある。

### 変更後コード

```markdown
## Components

> component 仕様は実装(`resources/js/components/`)と型定義が真実。本節は意味論と
> 使い分けルールのみを定義する。
> **本節が対象にするのは DS の再利用部品(`atoms` / `molecules` / `organisms`)である。**
> `features/` のドメイン部品と `templates/` のレイアウト骨格は本節の対象外
> (前者は各 feature の設計が使い分けを決め、後者は §Layout と
> `tests/js/architecture/page-shell-structure.test.ts` が担当する)。
> **対象の component を追加したら本節に追記すること**
> (`tests/js/styles/component-doc-parity.test.ts` が双方向の集合一致で強制する)。
```

追加する 4 節 (アルファベット順ではなく既存の並びの流儀 = 概ね atom → molecule に従う):

| 節 | 対応ファイル | 節に書く意味論 |
|---|---|---|
| `### DragHandle` | `atoms/DragHandle.svelte` | 並べ替えのつかみ手。`GripVertical` 固定 / `touch-none` でタッチをスクロールに奪わせない / 小コントロールなので `rounded-sm` / **並べ替えができない状態の表現は別途定義する** (禁止事項 8 は「必須条件未充足を理由に disabled にする」ことの禁止であって、あらゆる disabled の禁止ではない) |
| `### OrganizationChoiceCard` | `molecules/OrganizationChoiceCard.svelte` | 組織を 1 件選ぶ遷移カード。遷移先 URL は親が渡す (組織文脈を molecule が解決しない) |
| `### PendingInvitationsNotice` | `molecules/PendingInvitationsNotice.svelte` | 自分宛の保留中招待の件数だけを出す誘導専用 notice。**受諾 UI は持たない** (受諾は通知一覧) |
| `### SubtitleOverlay` | `molecules/SubtitleOverlay.svelte` | 映像へ重畳する字幕 overlay。焼込ではなく DOM overlay (MediaRecorder の stream に含まれない) / primary=上部帯・secondary=下部メイン / 位置は `AssSubtitleWriter` (ASS) と一致 / 長文は line-clamp で省略 |

**探索規則** (Round 1 レビューの Warning。再帰の境界を実装者依存にしない):

1. `resources/js/components` の**直下**のサブディレクトリを列挙し、分類表と**集合一致**させる
   (未分類が 1 つでもあれば不合格)
2. `kind: "excluded"` の分類は**そこで再帰を止める** (中は一切見ない)
3. `kind: "documented"` の分類は**その直下のファイルだけ**を部品の母集団に入れる
4. `documented` の直下にさらにサブディレクトリがある場合 (`atoms/icons`)、
   **そのパス自体が分類表に無ければ不合格**にする (深さ 2 以降も同じ規則を適用する)
5. 分類表のキーは実在するディレクトリであること (失効した登録を残さない)

```ts
// tests/js/styles/inventory.ts
/** §Components の対象にするサブディレクトリの全数分類 (既定拒否。キーは components からの相対パス)。 */
export const COMPONENT_DIR_CLASSIFICATION = {
    atoms: { kind: "documented" },
    molecules: { kind: "documented" },
    organisms: { kind: "documented" },
    templates: {
        kind: "excluded",
        reason: "レイアウトの骨格。使い分けは DESIGN.md §Layout と page-shell-structure.test.ts が担当する",
    },
    features: {
        kind: "excluded",
        reason: "ドメイン部品。使い分けは各 feature の設計が決め、DS の再利用部品カタログではない",
    },
    "atoms/icons": {
        kind: "excluded",
        reason: "Lucide に無いブランド/SSO ロゴの SVG 内包専用。svg-inline-allowlist.test.ts が担当する",
    },
    "templates/_helpers": {
        kind: "excluded",
        reason: "AppLayout の内部分割。単独で使う部品ではない",
    },
} as const;

/** 対象ディレクトリ直下のファイル種別の全数分類 (既定拒否)。 */
export const COMPONENT_FILE_KINDS = {
    ".svelte": { kind: "component", requiresSection: true },
    ".types.ts": {
        kind: "types",
        requiresSection: false,
        reason: "型と variant 表。同名の *.svelte が対になっていることを検査する",
    },
    ".ts": {
        kind: "helper",
        requiresSection: false,
        reason: "共有 helper。現状 1 件 = atoms/input-state.ts (入力系 atom の共通スタイル定義)",
    },
    ".gitkeep": { kind: "marker", requiresSection: false, reason: "空ディレクトリの目印" },
} as const;

/** 既定の対応 (節名 = ファイル名) に乗らない対応の申告 (理由必須。正典 i10)。 */
export const COMPONENT_SECTION_MAPPINGS = [
    { section: "Input / Textarea / Select(入力系 atom)",
      files: ["atoms/Input.svelte", "atoms/Textarea.svelte", "atoms/Select.svelte"],
      reason: "3 つの入力 atom は同じ枠・同じ状態表現を共有するため 1 節で意味論を定義している" },
    { section: "Toast", files: ["organisms/ToastContainer.svelte"],
      reason: "節名は利用者から見た概念 (Toast)、実装は容器 1 本 (ToastContainer)" },
    { section: "PageHeader / PageHeaderSection",
      files: ["molecules/PageHeader.svelte", "molecules/PageHeaderSection.svelte"],
      reason: "ページ見出しと節見出しは対で使うため 1 節で使い分けを定義している" },
] as const;
```

検査項目:

1. **双方向の集合一致**: §Components の `###` 節と、対象ディレクトリ直下の `*.svelte` が
   (申告表を適用したうえで) 集合一致する
2. **サブディレクトリの全数分類**: 実在するサブディレクトリが分類表と集合一致する (未分類は不合格)
3. **ファイル種別の全数分類**: 対象ディレクトリ直下の拡張子が分類表と集合一致する (未分類は不合格)
4. **`.types.ts` に対の `*.svelte` がある** (孤立した型ファイルを作らせない)
5. **申告表の健全性**: 失効 (存在しない節 / 存在しないファイル) / 重複 (同じファイルが 2 つの節に) /
   **冗長** (既定の対応で足りるのに申告している) をそれぞれ落とす
6. **母集団が空でない** (節数 > 0 / 部品数 > 0)
7. 負のコントロール (固定検体): 節を 1 つ消すと赤 / 部品を 1 つ足すと赤 /
   申告を冗長にすると赤 / 未分類のサブディレクトリを足すと赤 /
   **`documented` の下に未分類の入れ子ディレクトリを足すと赤** (規則 4 の裏取り) /
   **`excluded` の下のファイルは母集団に入らない** (規則 2 の裏取り)

### 型適合チェック

- [x] 戻り値の型が明示されている
- [x] `null` 安全 (節の抽出に失敗したら例外 = i20)
- [x] 配列返却ではなく `as const satisfies` の宣言
- [x] `kind` の網羅を `never` へ収束させる

### テスト計画

- [x] **先に赤くするテスト**: 検査 1 (双方向の集合一致)。DESIGN.md に 4 節を足す**前**は
      「実装にあるのに節が無い」で赤になる (13 部品事件と同じ形が実在することの実証)
- [x] 検査 5 の冗長判定を先に書き、`Input / Textarea / Select` を申告しない状態で赤を確認する
- [x] 負のコントロール 4 種を固定検体で置く
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- DESIGN.md §Components に節を足す作業が今後の部品追加ごとに要る。
  → それが i10 の目的である。`docs/design-system.md` の
  「コンポーネント追加時のチェックリスト」に既に
  「DESIGN.md §Components に意味論・使い分けを追記」が入っており、規約は変わらない。
- `features/` を対象外にする判断は、DESIGN.md 冒頭の「各 component を追加したら本節に追記する」と
  食い違っていた。→ 同じ PR で冒頭の文を対象範囲つきに直す (上記)。

---

## S9 描画されない領域の除去に 4 空白字下げコードを足す (i12 の残余)

### 変更箇所

- `tests/js/styles/design-system-docs.test.ts` (`renderedLines()` / docblock / fixture)
- `docs/design-system.md` (「落とすのは HTML コメント / fenced code の 2 つ」の記述を訂正)

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 同ファイルの fixture に負例を追加
- **共有パス**: `docs/design-system.md` → S12

### 現行コード

```ts
// tests/js/styles/design-system-docs.test.ts (L25-28 の docblock)
 *   - **描画されない領域の全種類**。潰すのは HTML コメントと fenced code の 2 つだけで、
 *     4 空白字下げのコードブロックや HTML 要素による非表示は見ていない
```

### 変更後コード

```ts
/**
 * 4 空白以上の字下げコードブロックの区間か (CommonMark の indented code block)。
 *
 * ★**開始条件は 1 つだけ**である — 「直前の描画行が空行、または文書の先頭」+
 *   「字下げ 4 以上」+「fence の外」。
 *   CommonMark では**字下げコードブロックは段落を中断できない**ので、
 *   直前が空行でない 4 空白行は**段落の継続行 = 描画される本文**であり、落とさないのが正しい。
 *   (Round 1 レビューの Critical: 旧設計は「段落の継続行も落とす側に倒す」と書いており、
 *   状態遷移の説明とテスト計画が両立しなかった。仕様の誤りであって fail-closed ではなかった。)
 * ★終端は「空行でない、字下げ 4 未満の行」である。空行は区間を終わらせない。
 * ★**過剰に落としうるのは 1 形だけ**である — リスト項目の配下に空行を挟んで
 *   4 空白で続けた内容 (CommonMark ではリストの継続段落だが、本実装は字下げコードとして落とす)。
 *   実測: `docs/design-system.md` に 4 空白以上の字下げ行は 1 行も無く、
 *   1〜3 空白の字下げ行も 0 行なので、現時点で誤検出は起きない。
 *   この 1 形を**保証範囲として docblock に明記する** (明記したのでこの構文について検出力を主張しない)。
 * ★CommonMark パーサは導入しない: `marked` / `commonmark` / `markdown-it` はいずれも未導入で、
 *   この 1 検査のために依存を増やすのは「今必要なものだけ作る」に反する。
 *   代わりに保証範囲を狭く宣言する。
 */
```

`renderedLines()` の変更:

- fence / コメントの処理の**後**に、字下げコードの状態機械を 1 段足す。
- 区間の中の行は `""` へ潰す (**行数は保つ**)。
- 状態は「直前の描画行が空行だったか」の真偽値 1 つと「字下げコード区間の中か」の真偽値 1 つ。
- **段落の継続行 (直前が空行でない 4 空白行) は落とさない**。

`docs/design-system.md` の訂正:

```markdown
ただし**完全な Markdown 解析ではない** — 4 空白字下げのコードブロックと
HTML 要素による非表示は見ていない。
```
↓
```markdown
落とすのは HTML コメント・囲みコード・**4 空白以上の字下げコード**の 3 つで、
字下げコードは「直前の描画行が空行(または文書の先頭)」のときだけ始まる
(CommonMark では字下げコードが段落を中断できないため、段落の途中の 4 空白行は
本文として残る)。ただし**完全な Markdown 解析ではない** —
リスト項目の配下に空行を挟んで 4 空白で続けた内容は誤って落とし、
HTML 要素による非表示は見ていない。
```

### 型適合チェック

- [x] 戻り値の型が明示されている (`readonly string[]`)
- [x] `null` 安全 (状態は判別可能な形で持つ)
- [x] 配列返却は行配列という性質上正しい (行数保存が契約)
- [x] Generics なし

### テスト計画

- [x] **先に赤くするテスト**: fixture に「空行 + 4 空白字下げの本文」を足し、
      `body).not.toContain("字下げコードの中の本文")` を**先に書く**。実装前は赤
- [x] 負のコントロール (固定検体。Round 1 レビューの指摘どおり**6 種を別々に**置く):
  1. **空行の後の 4 空白字下げ行**は落ちる (indented code block)
  2. **段落の継続行** (直前が空行でない 4 空白字下げ行) は**落ちない**
     (CommonMark では字下げコードは段落を中断できない。落とすと本文が消える)
  3. **リスト配下の 4 空白字下げ内容**は落ちる (**過剰に落とす 1 形**。
     保証範囲として docblock に明記した性質そのものを固定検体で見えるようにする)
  4. **字下げコード区間の中の `` ``` ``** で fence が開かないこと
  5. **EOF まで閉じない区間** (最後まで字下げが続く) はそこまで落ちること
  6. **行数が保存される**こと (節の切り出しがずれない)
- [x] 既存の 8 it が同じ期待値で緑であること (`docs/design-system.md` に
      4 空白以上の字下げ行が 1 行も無いことを実測済み)
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- 近似がリスト配下の継続段落を誤って落とし、規範の最小断片の検査が偽陽性で赤くなる可能性がある
  (該当は 1 形だけで、実測では現状 0 件)。
  → 落とす側へ倒すのは共通規約 (b)「拾いすぎる方向へ倒すのは可、見逃す方向へ倒すのは不可」に沿う。
  赤くなったら**書き方を直す** (字下げをやめる) のが正しい対応であり、検査を緩めない。
  この方針と過剰に落とす 1 形を docblock に書く (明記したのでその構文について検出力を主張しない)。
- 逆に**段落の継続行を落とす**実装にすると、契約の本文が消えても緑になる形が生まれる。
  そちらは見逃す方向なので採らない (Round 1 レビューで是正した点)。

---

## S11 責務境界表へ新設 gate を登録する (i11 の帰結)

### 変更箇所

- `docs/design-system.md` (§検査の責務境界の表に 4 行追加 / **本数の記述そのものを廃止** /
  §トークン変更時の運用契約に 1 行追加 / §テーマの差し替え方に 1 行追加)

### 波及変更

- テストファイル: 既存 `design-system-docs.test.ts` の
  「責務境界表の 1 列目と実在する検査ファイルが集合一致する (双方向)」が**この行なしでは赤**
- **共有パス**: `docs/design-system.md` → S12

### 変更後コード (表に追加する 4 行)

| 検査 | 見ている写像 | 代表的に検出する壊れ方 |
|------|------------|--------------------|
| `tests/js/styles/token-reference-closure.test.ts` | 参照側 (resources/js / resources/css) ⇒ tokens.css の宣言集合 | token 名の綴り誤りが無スタイルとして静かに消える / 写像の外の色語 (Tailwind 既定の white 等) の混入 |
| `tests/js/styles/component-doc-parity.test.ts` | DESIGN.md §Components ⇔ resources/js/components の部品ファイル | 文書に載らない部品が増える / 節だけ残って実装が消える |
| `tests/js/styles/class-usage.test.ts` | 走査器そのもの (固定検体) | 状態単位の分解の退行 / 未対応入口の deny の空振り |
| `tests/js/styles/theme-map.test.ts` | 写像パーサそのもの (固定検体) | `@theme` の検出・宣言の抽出・色表現の解析の退行 |

**本数の記述そのものを廃止する** (Round 1 レビューの Critical: 既存 4 本 + 新規 4 本 = 8 本で、
「4 本 → 6 本」は算術的に誤っていた。**数字は機械検査の対象外なので必ず陳腐化する**)。

| 現行 | 変更後 |
|---|---|
| 「本節で責務境界を管理するデザイントークン検査は **4 本ある**」 | 「本節で責務境界を管理するデザイントークン検査は**下表に挙げたものがすべてである**」 |
| 「保証しないもの: … **4 本のどれも**見ていない」 | 「保証しないもの: … **下表のどれも**見ていない」 |

表の双方向集合一致 (`design-system-docs.test.ts`) だけを正本にする。

`§トークン変更時の運用契約` へ追加する 1 行:

```markdown
- [ ] トークンの**値**を変える場合は `contrast-invariant.test.ts` の
      不透明ペアと**半透明ペア(合成)**の両方が緑であること
      (ソフト背景の色は面の上での合成後の値で判定される)
```

`§テーマの差し替え方` の 3 手順へ追加:

```markdown
3. parity テスト green を確認(**contrast-invariant の合成検査も含む**。
   状態色を明るい段に戻すとソフト背景側で落ちる)
```

### 型適合チェック

- [x] 該当なし (Markdown)

### テスト計画

- [x] **先に赤くするテスト**: S3 / S8 / S2 の新 `*.test.ts` を置いた時点で
      既存の「責務境界表の 1 列目と実在する検査ファイルが集合一致する」が**赤**になる。
      その赤を確認してから本施策で行を足す
- [x] 既存の「Canonical source 表の 2 列目のパスがすべて実在する」が緑のままであること
- [x] 規範の最小断片 (`SECTION_CONTRACT_PHRASES`) を**変えない**
      (契約の文言は変えず、行と本数だけを足す)

### リスク

- **本数の記述は廃止する**ので陳腐化しない。表そのものが機械で突き合わされており、
  数字は「表と実体が一致していること」に何も足していなかった。
  数字を最小断片 (`SECTION_CONTRACT_PHRASES`) に入れない (文言固定は増やさない = 既存方針)。

---

## S12 共有パスの採用時債務を決着させる (乖離台帳)

### 変更箇所

- `docs/template-divergence.md` (宣言行 46 → 48 / **D50 と D51 を追加**)
- `tests/Support/TemplateDivergence/LedgerPins.php`
  (`DIVERGENCE_ENTRY_COUNT` 46 → **48** / `ADOPTION_DEBT_COUNT` 148 → 146)
- `tests/Support/TemplateDivergence/adoption-debt.tsv` (2 行削除)

### 乖離台帳の確認段 (app-design スキル 3-0)

`docs/template-fingerprints.json` のキーに在るか (= テンプレートと共有するパスか) を実測した。

| 変更するパス | 指紋台帳のキー | 採用時債務 | 決着 |
|---|---|---|---|
| `docs/design-system.md` | **在る** | **在る** (採用時 sha と現況が一致) | **(3) 意図的逸脱として登録 (D51) を書き、債務から削る** |
| `tests/js/architecture/contrast-invariant.test.ts` | **在る** | **在る** (同上) | **(3) 意図的逸脱として登録 (D50) を書き、債務から削る** |
| `tests/js/support/ds-purity.ts` | 在る | 在る | **変更しない** (i9 が同じ穴を塞ぐので `white`/`black` を禁止リストへ足す案は採らない) |
| `DESIGN.md` | 無い | — | 登録不要 |
| `resources/css/tokens.css` | 無い | — | 登録不要 |
| `resources/css/app.css` | 無い (変更もしない) | — | — |
| `tests/js/styles/*` (既存 5 + 新設 3) | 無い | — | 登録不要 (既存の D28 が同領域の逸脱を説明済み) |
| `postcss.config.js` | 在る (変更しない) | — | — |

**判定の根拠**: `FingerprintReconciler` は債務パスの現況が採用時 sha と違えば
`mutatedDebtPaths` として落とす。かつ債務パスと登録の対象パスの**両方に在る** (`doubleDeclaredPaths`)
のも落とす。したがって「登録を書く」と「債務から削る」は**同じ変更で行う**。

### 追加する登録 (D50 / D51 の 2 件)

**2 エントリに分ける** (Round 1 レビューの Warning: 1 エントリの説明がコントラストだけでは、
`docs/design-system.md` に入る**別の変更理由** (検査目録の正本化 / 描画されない領域の除去範囲 /
運用契約への合成検査の追加) を説明できない。**パス単位で採用時債務を解除するのだから、
登録理由は変更全体を説明していなければならない**)。

```markdown
## D50 デザイントークンのコントラスト検査を、半透明の合成と実装からの逆向き被覆まで広げる

| 行 | 内容 |
|---|---|
| 対象パス | `tests/js/architecture/contrast-invariant.test.ts` |
| 業務要件起因の説明 | 撮影 PWA の状態表示 (撮影中 / 完了 / 警告) はソフト背景のバッジで出しており、作業者はその 1 個の色で工程の状態を読む。テンプレートの検査は不透明な組だけを見るため、実際に画面へ出ているソフト背景の可読性が 1 件も検査されていなかった (実測で 5 組が AA 未達) |
| 揃え続ける不変条件と保証機構 | 半透明の背景 × 不透明な文字の組が、面として分類した token のすべての上で 4.5:1 を満たすこと。走査で見つかった半透明の組が全件台帳に載り、静的に決められない形は理由と件数つきで台帳に載ること。実装の class から導出した前景 × 背景の組が役割の母集団 (役割の全数分類の直積 + 個別宣言ペア) の内側にあること。線形化しきい値が errata 後の 0.04045 であること。`contrast-invariant.test.ts` と `tests/js/styles/class-usage.ts` が保証する |
| 再判定の条件 | 正典が半透明の合成を不変条件から外したとき。または Tailwind の不透明度修飾の展開形が変わって合成モデルの前提が崩れたとき (`tokens.test.ts` の「不透明度修飾の生成形」が赤くなる)。広色域の実描画との差を実測して系統的なずれが出たとき (家系の未決論点 q3) |
| 決めた日 | 2026-08-24 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260824-1019-design-token-system-v1/ |
| 状態 | 恒久 |
| 見直し期限 | — |
```

```markdown
## D51 デザインシステム運用ガイドを検査目録の正本にし、描画されない領域の判定を CommonMark へ寄せる

| 行 | 内容 |
|---|---|
| 対象パス | `docs/design-system.md` |
| 業務要件起因の説明 | 本アプリはデザイントークン検査を独自系統で持つ (D28) ため、検査の本数も置き場もテンプレートと一致しない。責務境界の表を機械照合の入力にしている以上、テンプレートの散文をそのまま維持することはできない。加えて撮影 PWA のテーマ値を動かす運用契約 (合成検査を通すこと) を書き足す必要がある |
| 揃え続ける不変条件と保証機構 | 正本の宣言表が全数宣言であり検査側の宣言と役割とパスの組で集合一致すること。責務境界表と `tests/js/styles/*.test.ts` の実体が双方向に集合一致すること。節ごとの規範の最小断片が読者に描画される本文に在ること。描画されない領域 (HTML コメント / 囲みコード / 4 空白以上の字下げコード) を検査の前に落とすこと。`tests/js/styles/design-system-docs.test.ts` が保証する |
| 再判定の条件 | 検査目録を文書ではなく機械可読な台帳へ移したとき。または正典が運用ガイドの節構成そのものを不変条件として明文化したとき |
| 決めた日 | 2026-08-24 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260824-1019-design-token-system-v1/ |
| 状態 | 恒久 |
| 見直し期限 | — |
```

各エントリには観点表 (テンプレート / 本アプリ)、`### なぜ正当な差分か(logic-driven)`、
`### 揃えている不変条件(これは保証し続ける)`、`### 保証しないもの`、`### 関連` を
エントリ形式どおりに書く。**対象パスは全登録の和集合で重複しない**規約があるので、
既存 D28 の対象パス (`tests/js/styles/tokens.test.ts` /
`tests/js/styles/design-system-docs.test.ts`) とは重ならないことを確認する
(実測: `docs/design-system.md` と `contrast-invariant.test.ts` はどの登録にも現れていない)。

> **D28 の本文も同じ変更で直す**: 「保証しないもの」に書かれた
> 「描画されない領域として除くのは HTML コメントと fenced code の 2 つだけで、
> 4 空白字下げのコードブロックと HTML 要素による非表示は見ていない」は S9 で事実が変わる。
> 台帳の中身を実態に合わせるのは登録の維持であって新規登録ではない (件数は変わらない)。

### 型適合チェック

- [x] `LedgerPins.php` は `int` 定数の値変更のみ。型は変わらない (PHPStan level 10 に影響なし)
- [x] `declare(strict_types=1)` は既に在る

### テスト計画

- [x] **先に赤くする**: S4 で `contrast-invariant.test.ts` を 1 文字変えた時点で
      `TemplateDivergenceFingerprintTest` が `mutatedDebtPaths` で**赤**になる。
      その赤を確認してから本施策で決着させる
- [x] `TemplateDivergenceLedgerFormatTest` (9 行ちょうど / 値域 / 対象パスの実在と重複なし /
      件数の 3 点一致) が緑であること
- [x] `TemplateDivergenceFingerprintTest` の `doubleDeclaredPaths` が空であること
      (債務から削り忘れると赤)
- [x] `composer test` 全体が緑 (PHP 側の唯一の変更が定数 2 本なので他への波及なし)

### リスク

- 債務件数を減らす変更なので、**掃除の方向**である (D34 の期限つき縮小の趣旨に沿う)。
- D 番号は再利用しない規約なので `D50` / `D51` (現在の最大が `D49`) を使う。
- 件数の 3 点一致 (本文の宣言行 46 → **48** / 見出しの実数 / `DIVERGENCE_ENTRY_COUNT`) を
  同じ変更で揃える。**エントリ形式の例 (`## D1 <逸脱の要約>`) は囲みコードの中なので
  見出しの実数に数えない** — 実測で本文の `## D<n> ` 見出しは 47 個検出されるが、
  うち 1 個がその例である (現行の宣言行 46 と整合)。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 12 施策が 1 本の依存鎖でつながっており、途中の状態では**必ず赤いテストが残る**。とくに S5 (合成検査) を入れた時点で 5 組が赤になり、S6 (値の是正) を同じ作業単位で行わなければ main がマージ不能になる。同様に S1 / S2 / S3 / S8 が新 `*.test.ts` を作った時点で既存 `design-system-docs.test.ts` が赤になり、S11 が同じ作業単位に無いと閉じない。S4 が共有パスを触った時点で `TemplateDivergenceFingerprintTest` が赤になり、S12 が同じ作業単位に無いと閉じない。分割すると「赤いまま main に入れる」か「後方互換の並走を残す」のどちらかになり、AGENTS.md 思考原則 3 と禁止事項 1 に触れる |
| 競合リスク | `tests/js/styles/inventory.ts` に 6 つの台帳・分類表を追加するため、同ファイルを触る他タスクと衝突しうる。`docs/TODO.md` の Open は T249 (別 feature「起動 probe の共通 runner 一元化」) のみで、`tests/js/styles/` には触らないため**現時点で衝突なし**。`DESIGN.md` / `resources/css/tokens.css` / `docs/design-system.md` も T249 の対象外 |

### 実装中に「後方互換の並走を残さない」ために同じ作業単位で消すもの (AGENTS.md 思考原則 3)

| 消すもの | 移す先 |
|---|---|
| `canonical-source-parity.test.ts` のローカル `cssColorTokens()` / radius 抽出 / `@utility` 抽出 | `tests/js/styles/theme-map.ts` |
| `PENDING_CONTRAST_PAIRS` の「alpha 合成ペア」の 1 行 | `ALPHA_CONTRAST_PAIRS` + `UNDECIDABLE_PAIR_LEDGER` (pending には判定不能の分類だけが残る) |
| `CONTRAST_EXEMPT_TOKENS` (token 単位の排他な免除) | `COLOR_TOKEN_ROLES` の複数役割 + `NON_TEXT_BOUNDARY_REASONS` + `DECLARED_CONTRAST_PAIRS` |
| `SURFACE_ROLE_TOKENS` / `TEXT_ON_SURFACE_TOKENS` / `FILL_TOKENS` / `FILL_LABEL_TOKENS` の**固定配列** | `COLOR_TOKEN_ROLES` からの導出 (i4: 母集団を固定配列に書かない) |
| `UndecidableReason` の `double-alpha` | `ALPHA_CONTRAST_PAIRS` の実効 alpha (積) として計算する |
| `resources/js` の `text-white` 3 箇所 | `text-surface` |
| `docs/design-system.md` の「落とすのは HTML コメントと fenced code の 2 つ」 | 3 つに訂正 |
| `docs/design-system.md` の「検査は 4 本ある」 | 「下表に挙げたものがすべてである」(数字を持たない形へ) |
| `adoption-debt.tsv` の 2 行 | `docs/template-divergence.md` の D50 / D51 |

### migration の扱い

**DB migration は 1 本も要らない**。本作業はスタイルの正本・写像・検査・文書・乖離台帳のみで、
スキーマ・モデル・Factory・route・DTO に触れない。したがって
`docs/architecture.md` / `docs/factories.md` への追記も不要である
(新規モデルを追加していないため)。

### 検証コマンド (全 green でコミット)

`composer test` / `composer phpstan` / `vendor/bin/pint --test` /
`pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
`pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`

- `pnpm build` は**必須**である (トークン値を変えるので生成 CSS が変わる)。
- `composer test` は `TemplateDivergenceFingerprintTest` /
  `TemplateDivergenceLedgerFormatTest` を含むので S12 の決着を検証する。

---

## Round 1 レビューの横断評価への対応

| 横断の指摘 | 対応 |
|---|---|
| PHPStan・DTO/JsonResource・Inertia Props・DB・tenant 認可への直接変更は無い | 変更なし (PHP の変更は `LedgerPins.php` の `int` 定数 2 本のみ) |
| Atomic Design 上、新規アプリ component は無い。S8 のディレクトリ分類規則は明確化が要る | S8 に**探索規則 5 条**を明記し、未分類の入れ子を固定検体で落とすようにした |
| **最大の後退リスク**: 正規表現ベースの class / CSS 解析が解析不能を検出できず「候補なし」として落とすこと | `TokenResolution` に `unresolved` を持たせ、**結果に必ず残して S3 の gate が落とす**形にした (無言で候補から外さない = 共通規約 (b) の 1 点目)。`unparsable-token` (区切りで割れた形) と `alpha-on-non-color` (色でない utility への不透明度修飾) を値域へ明示した |
| S4 のように本体を呼ばない負例、S9 のように仕様と矛盾する負例は i18 の裏取りにならない | S4 は `linearizeChannel()` を切り出して**実装本体を呼ぶ既知値検査**へ、S9 は開始条件を 1 本に統一して**段落継続行を落とさない**仕様へ直した |

### Round 1 の指摘で設計判断そのものが変わった 4 点 (記録)

1. **`bg-primary-soft/40` は静的に決められる** — 実効 alpha は `0.12 × 0.40 = 0.048` に確定するので、
   判定不能へ逃がすのは i16 に反する。合成対象に加えた
2. **個別宣言ペアを役割分類の根拠に数えない** — 数えると 1 組の登録で既定拒否を通せる。
   役割は `COLOR_TOKEN_ROLES` の**複数役割**で持ち、`border` は
   「非テキスト境界」と「テキストを載せる塗り」の 2 つを持つ (思考原則 4)
3. **字下げコードは段落を中断できない** — CommonMark の規則に従い、段落の継続行は落とさない。
   旧設計の「落とす側に倒す」は fail-closed ではなく**仕様の誤り**だった
4. **走査根は `resources/js` の 1 本** — 固定 3 根は実測で 4 つの入口を取り落としており、
   docblock の主張と実装が食い違っていた

---

# 添付 C: 関連する現行コード（抜粋）


## `tests/js/styles/inventory.ts`

```
/**
 * DS token inventory — canonical-source-parity テストの single source of truth。
 *
 * DESIGN.md frontmatter のキーと tokens.css の CSS 変数名の対応を定義する。
 * トークンを追加・削除する PR は DESIGN.md / tokens.css / 本ファイルを同一 PR で更新する。
 */

/** DESIGN.md colors キー → tokens.css `--color-<suffix>` の対応 */
export const COLOR_TOKEN_MAP = {
    "primary": "primary",
    "primary-hover": "primary-hover",
    "tertiary": "tertiary",
    "tertiary-hover": "tertiary-hover",
    "neutral": "neutral",
    "surface": "surface",
    "border": "border",
    "border-strong": "border-strong",
    "text-primary": "text",
    "text-secondary": "text-secondary",
    "success": "success",
    "warning": "warning",
    "danger": "danger",
} as const;

/**
 * DESIGN.md frontmatter に現れない派生トークン (rgba 等)。
 * tokens.css にのみ存在してよい。追加時は理由をコメントで残すこと。
 */
export const DERIVED_COLOR_TOKENS = [
    "primary-soft", // primary 12% — badge / focus ring 用 (DESIGN.md §Colors 本文で言及)
] as const;

export const RADIUS_TOKENS = ["sm", "md", "lg"] as const;

export const TYPOGRAPHY_RAMPS = ["display", "h1", "h2", "h3", "body", "caption"] as const;

/*
 * ===== コントラスト検査の役割宣言 (contrast-invariant.test.ts の入力) =====
 *
 * DESIGN.md の全色トークンは下の 5 分類の**いずれかに必ず属する** (deny-by-default)。
 * 未分類のトークンがあれば contrast-invariant が fail する = 新トークンが
 * 黙って gate をすり抜けられない。
 */

/** 面 (背景) として塗るトークン。DESIGN.md §Colors: neutral=画面全体 / surface=カード・モーダル */
export const SURFACE_ROLE_TOKENS = ["neutral", "surface"] as const;

/** 面の上に載るテキスト色 (本文・見出し・意味を担う状態テキスト) */
export const TEXT_ON_SURFACE_TOKENS = [
    "text-primary",
    "text-secondary",
    "primary", // リンク / TextLink
    "tertiary",
    "success",
    "warning",
    "danger", // Alert 見出し / Button danger-ghost のラベル
] as const;

/** 塗り面 (solid fill) として使うトークン。DESIGN.md §Components Button の bg-* */
export const FILL_TOKENS = [
    "primary",
    "primary-hover",
    "tertiary",
    "tertiary-hover",
    "success",
    "warning",
    "danger",
] as const;

/** 塗り面の上に載るラベル色。DESIGN.md §Components: `bg-* + text-neutral` */
export const FILL_LABEL_TOKENS = ["neutral"] as const;

/**
 * コントラスト検査の対象外トークン (理由必須)。
 * 「検査していない」ことを見えるようにするための明示宣言であり、免罪符ではない。
 */
export const CONTRAST_EXEMPT_TOKENS = {
    "border":
        "1px の区切り線・入力欄の枠。テキストではなく WCAG 1.4.11 (非テキスト 3:1) の領域。" +
        "装飾的な境界線は 1.4.11 の適用除外のため、使用箇所ごとの役割分類が要る (v1 スコープ外)",
    "border-strong":
        "区切りの強調・ghost ボタンの枠。ghost ボタンの枠は機能的境界の可能性があり、" +
        "実測 2.56 で 3:1 に届かない。値の是正は『どの border が機能的境界か』の" +
        "役割モデルを DESIGN.md に定めてから別バッチで行う (申し送り 5-3)",
} as const;

/**
 * 未検査であることを明示する pending 集合 (v1 スコープ外)。
 * contrast-invariant はこれらを検査しない — 「gate があるからコントラストは守られている」
 * という誤読を作らないための宣言。
 *
 * **出口**: pending 項目に対応したらその行を削る。全部消えたら
 * 本 export と contrast-invariant.test.ts の
 * 「未検査宣言 (PENDING_CONTRAST_PAIRS) が空でない」テストを**同時に削除**すること
 * (空の宣言と、空でないことを確かめるテストを残すと形骸化する)。
 */
export const PENDING_CONTRAST_PAIRS = [
    "WCAG 1.4.11 非テキストコントラスト (3:1): border / border-strong / focus ring",
    "alpha 合成ペア: Badge の bg-<tone>/10 + text-<tone>、bg-primary-soft、ring-primary/35、" +
        "bg-text/70 + text-surface (合成後の実効色が親背景に依存しトークン単体では定まらない)",
] as const;

/*
 * ===== 生成 CSS 検査の入力 (tokens.test.ts) =====
 */

/**
 * tokens.css が持つ `--color-<suffix>` の全件。
 *
 * COLOR_TOKEN_MAP (DESIGN.md 由来) と DERIVED_COLOR_TOKENS (tokens.css 固有の派生) の和。
 * これが tokens.css の `--color-*` 全件と一致することは canonical-source-parity の
 * 集合一致テストが固定しているので、この配列は「定義上の全件」である。
 */
export const CSS_COLOR_SUFFIXES: readonly string[] = [
    ...Object.values(COLOR_TOKEN_MAP),
    ...DERIVED_COLOR_TOKENS,
];

/**
 * 生成 CSS で**値**の一致を検査しないトークン (理由必須)。
 *
 * 契約: **派生トークンは全件が値免除である** (DESIGN.md に期待値が無いため)。
 * キー集合が DERIVED_COLOR_TOKENS と一致することを canonical-source-parity が固定する
 * = 派生トークンを足したのに「値も見ていない・免除にも入っていない」状態を作れない。
 * 免除しているのは**値だけ**で、生成 CSS への出現は検査する。
 */
export const COMPILED_VALUE_EXEMPT_TOKENS = {
    "primary-soft":
        "DESIGN.md frontmatter に現れない派生トークン (rgba)。期待値を正本から導出できないため" +
        "値の突き合わせは行わず、生成 CSS への出現までを検査する。値の正本は tokens.css で、" +
        "集合としての存在は canonical-source-parity が固定している",
} as const;

/**
 * 経路の層 (実 app.css のコンパイル) で**必ず現れることを求める**トークン。
 *
 * これは**アンカー集合であって全件ではない**。経路の層の生成物はアプリ側の class 使用状況に
 * 依存するため、全件の網羅は密閉の層が担う。ここに並べるのは画面の土台
 * (面・本文・主 CTA) が使う 4 件に限る
 * (実測の使用回数: bg-primary 17 / text-text 106 / bg-surface 47 / bg-neutral 35)。
 *
 * **アンカーが使われなくなったときの直し方**: テストを緩めるのではなく、
 * 土台に相当する別のトークンへ差し替える (集合を縮めて緑にしない)。
 */
export const ROUTE_LAYER_ANCHOR_TOKENS = ["primary", "text", "surface", "neutral"] as const;

/*
 * ===== DESIGN.md frontmatter の節ごとの担当宣言 (既定拒否) =====
 *
 * frontmatter の最上位の節は下の 3 分類の**いずれかに必ず属する**。
 * 未分類の節があれば canonical-source-parity が fail する
 * = 正本に節を足したのに誰も見ていない状態を作れない。
 *
 * **`checked` は「担当がいる」ことを表すのであって、節の中身を全項目網羅しているという
 * 主張ではない**。母集団の網羅は節ごとの集合一致テストが別に固定する。
 */

/** 節を検査している gate の識別子 (ファイル名の語幹に合わせる)。 */
export type DesignGateName = "canonical-source-parity" | "tokens" | "contrast-invariant";

export type FrontmatterSectionOwner =
    /** 担当のいる節。どの gate が見ているかを列挙する */
    | { readonly kind: "checked"; readonly by: readonly DesignGateName[] }
    /** 実装写像を持たないメタ情報 (理由必須) */
    | { readonly kind: "metadata"; readonly reason: string }
    /**
     * 未検査であることの明示宣言 (理由・解消条件・追跡先の 3 つが必須)。
     * 追跡先は `T<3 桁以上>` (TODO の表の ID 列に実在) か
     * `devnotes/<dir>/` (実在するディレクトリ) のどちらか。
     */
    | {
          readonly kind: "pending";
          readonly reason: string;
          readonly exit: string;
          readonly tracking: string;
      };

export const FRONTMATTER_SECTION_OWNERS: Readonly<Record<string, FrontmatterSectionOwner>> = {
    version: { kind: "metadata", reason: "テーマの版。実装写像を持たない" },
    name: { kind: "metadata", reason: "テーマの名前。実装写像を持たない" },
    description: { kind: "metadata", reason: "テーマの説明文。実装写像を持たない" },
    colors: { kind: "checked", by: ["canonical-source-parity", "tokens", "contrast-invariant"] },
    typography: { kind: "checked", by: ["canonical-source-parity", "tokens"] },
    rounded: { kind: "checked", by: ["canonical-source-parity", "tokens"] },
    spacing: {
        kind: "pending",
        reason:
            "tokens.css に --spacing-* の写像が無く、値も写像の有無もどの検査も見ていない。" +
            "Tailwind 既定の spacing で足りているのか写像の作り忘れなのかが未決である",
        exit:
            "tokens.css に写像を作って canonical-source-parity と tokens の担当に移すか、" +
            "frontmatter から spacing を外すかを決めたら、本項目を削る",
        tracking: "devnotes/20260818-0248-design-token-t1-tests/",
    },
};
```

## `tests/js/styles/design-md.ts`

```
/**
 * DESIGN.md (canonical source) の frontmatter パーサ — 検査テスト共有。
 *
 * canonical-source-parity (DESIGN.md ⇔ tokens.css の同期) と
 * contrast-invariant (色の可読性) が **同一のパーサ**を使うためのヘルパ。
 * パーサを二重実装すると「片方だけが読める DESIGN.md」という状態を作れてしまう。
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const HERE = path.dirname(fileURLToPath(import.meta.url));
export const REPO_ROOT = path.resolve(HERE, "../../../");

const designMd = fs.readFileSync(path.join(REPO_ROOT, "DESIGN.md"), "utf-8");

/** DESIGN.md 冒頭の `---` で囲まれた frontmatter 本文 */
export const frontmatter: string = (() => {
    const m = designMd.match(/^---\n([\s\S]*?)\n---/);
    if (!m) throw new Error("DESIGN.md frontmatter not found");
    return m[1];
})();

/** frontmatter `colors:` → `{ トークン名 → "#rrggbb" (小文字) }` */
export function designColors(): Map<string, string> {
    const section = frontmatter.match(/^colors:\n((?: {4}\S[^\n]*\n)+)/m);
    if (!section) throw new Error("DESIGN.md colors section not found");
    const map = new Map<string, string>();
    for (const line of section[1].matchAll(/^ {4}([a-z-]+): "(#[0-9A-Fa-f]{6})"$/gm)) {
        map.set(line[1], line[2].toLowerCase());
    }
    return map;
}

/** frontmatter `rounded:` → `{ 段名 → "Npx" }` */
export function designRounded(): Map<string, string> {
    const section = frontmatter.match(/^rounded:\n((?: {4}\S[^\n]*\n)+)/m);
    if (!section) throw new Error("DESIGN.md rounded section not found");
    const map = new Map<string, string>();
    for (const m of section[1].matchAll(/^ {4}([a-z]+): (\d+px)$/gm)) {
        map.set(m[1], m[2]);
    }
    return map;
}

/** frontmatter `typography.<name>:` → `{ プロパティ名 → 値 }` */
export function designRamp(name: string): Record<string, string> {
    const m = frontmatter.match(new RegExp(`^ {4}${name}:\\n((?: {8}\\S[^\\n]*\\n)+)`, "m"));
    if (!m) throw new Error(`DESIGN.md typography ramp not found: ${name}`);
    const props: Record<string, string> = {};
    for (const line of m[1].matchAll(/^ {8}([a-zA-Z]+): "?([^"\n]+)"?$/gm)) {
        props[line[1]] = line[2];
    }
    return props;
}

/**
 * frontmatter の**最上位の節名**を宣言順で返す。
 *
 * 「どの節がどの検査の担当か」を既定拒否で宣言するための入力
 * (tests/js/styles/inventory.ts の FRONTMATTER_SECTION_OWNERS)。
 * 入れ子の子キー (typography.display 等) は含めない — 担当の宣言は節の粒度で行う。
 *
 * 保証範囲: 行頭から始まるキーだけを最上位として拾う。frontmatter の書式が変わったときは
 * 抽出結果が変わり、担当宣言との集合一致で気付ける**ことが多い**が、
 * 別の最上位らしい文字列を拾う形の誤解析まで防げるわけではない。
 */
export function designFrontmatterSections(): readonly string[] {
    const sections: string[] = [];
    for (const m of frontmatter.matchAll(/^([a-zA-Z][a-zA-Z0-9-]*):/gm)) {
        sections.push(m[1]);
    }
    return sections;
}

/**
 * frontmatter `typography:` の**子キー** (ramp 名) を宣言順で返す。
 *
 * TYPOGRAPHY_RAMPS (検査側の母集団) と集合一致させるための入力。
 * これが無いと、DESIGN.md に ramp を足しても検査側の固定配列に入らず見逃す。
 */
export function designTypographyNames(): readonly string[] {
    const section = frontmatter.match(/^typography:\n((?: {4}\S[^\n]*\n| {8}\S[^\n]*\n)+)/m);
    if (!section) throw new Error("DESIGN.md typography section not found");
    const names: string[] = [];
    for (const m of section[1].matchAll(/^ {4}([a-zA-Z][a-zA-Z0-9-]*):$/gm)) {
        names.push(m[1]);
    }
    return names;
}
```

## `tests/js/architecture/contrast-invariant.test.ts`

```
import { describe, it, expect } from "vitest";
import { designColors } from "../styles/design-md";
import {
    COLOR_TOKEN_MAP,
    CONTRAST_EXEMPT_TOKENS,
    FILL_LABEL_TOKENS,
    FILL_TOKENS,
    PENDING_CONTRAST_PAIRS,
    SURFACE_ROLE_TOKENS,
    TEXT_ON_SURFACE_TOKENS,
} from "../styles/inventory";

/*
 * contrast-invariant — DESIGN.md のテーマ色が読める組合せであることを機械検証する。
 *
 * 【検査範囲】不透明 (opaque) なテキストペアのみ。
 *   - 面 (neutral / surface) の上のテキスト色
 *   - 塗り面 (primary / danger 等) の上のラベル色 (DESIGN.md §Components: bg-* + text-neutral)
 *
 * 【閾値】一律 4.5:1。
 *   WCAG 2.2 SC 1.4.3 (AA) には「大きな文字は 3:1」の緩和があるが、
 *   **トークン単位の gate は文字サイズを知り得ない**ため緩和は採らず、
 *   厳しい側 (通常文字基準) を一律適用する。これは WCAG の要求そのものではなく
 *   本プロジェクトの設計判断である。
 *
 * 【検査しないもの】inventory.ts の PENDING_CONTRAST_PAIRS を参照
 *   (非テキスト 1.4.11 / alpha 合成)。「gate があるからコントラストは守られている」
 *   という誤読を作らないため、未検査であることを明示宣言してある。
 *
 *   加えて `resources/views/vendor/mail/html/themes/template.css` は**対象外**。
 *   同ファイルは Laravel 同梱メールテーマの独立パレット (`.button-red` = #dc2626、
 *   `.button-green` = #16a34a 等) を直書きしており、DESIGN.md トークンの写像ではない。
 *   メール HTML は CSS 変数を使えないクライアントが多く、DS token 化するなら
 *   ビルド時展開の設計が別途要る (本バッチのスコープ外)。
 *   なお詳細設計 §施策 6 のリスク表は「メールテンプレに danger は含まれない」と
 *   書いているが、これは事実誤認 (実際は #dc2626 を直書きしている)。
 *   対象外という結論は変わらないため据え置いた。
 *
 * 色値そのものを変えるときは DESIGN.md / tokens.css を同一 PR で更新すること
 * (canonical-source-parity が drift を検出する)。
 */

const AA_NORMAL_TEXT = 4.5;

/** sRGB チャンネルの線形化 (WCAG 2.x 相対輝度の定義) */
function linearize(channel: number): number {
    const c = channel / 255;
    return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
}

/** #rrggbb → 相対輝度 (WCAG 2.x) */
function relativeLuminance(hex: string): number {
    const r = linearize(parseInt(hex.slice(1, 3), 16));
    const g = linearize(parseInt(hex.slice(3, 5), 16));
    const b = linearize(parseInt(hex.slice(5, 7), 16));
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** コントラスト比 (WCAG 2.x)。1.0〜21.0 */
export function contrastRatio(a: string, b: string): number {
    const [l1, l2] = [relativeLuminance(a), relativeLuminance(b)];
    return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
}

const colors = designColors();

function hex(token: string): string {
    const value = colors.get(token);
    if (value === undefined) throw new Error(`DESIGN.md colors に ${token} が無い`);
    return value;
}

/** 検査対象ペア: [前景トークン, 背景トークン, 文脈] */
const PAIRS: readonly (readonly [string, string, string])[] = [
    // 面ロールとテキストロールは素 (下の「両集合が素」テストが固定する) なので、
    // 自己ペア (同一トークン同士 = 比 1.0) は構造上生じない。
    // 素であることを型の widen による自己ペア除外 filter で暗黙に扱わず、
    // 独立した不変条件として明示的に検査する。
    ...TEXT_ON_SURFACE_TOKENS.flatMap((fg) =>
        SURFACE_ROLE_TOKENS.map((bg) => [fg, bg, "面上のテキスト"] as const),
    ),
    ...FILL_LABEL_TOKENS.flatMap((fg) =>
        FILL_TOKENS.map((bg) => [fg, bg, "塗り面のラベル"] as const),
    ),
];

describe("architecture/contrast-invariant: 不透明ペアのテキストコントラスト (一律 4.5:1)", () => {
    it("役割宣言が DESIGN.md の全色トークンを覆う (deny-by-default)", () => {
        const classified = new Set<string>([
            ...SURFACE_ROLE_TOKENS,
            ...TEXT_ON_SURFACE_TOKENS,
            ...FILL_TOKENS,
            ...FILL_LABEL_TOKENS,
            ...Object.keys(CONTRAST_EXEMPT_TOKENS),
        ]);
        const unclassified = Object.keys(COLOR_TOKEN_MAP).filter((t) => !classified.has(t));
        expect(
            unclassified.sort(),
            `未分類の色トークンがある。tests/js/styles/inventory.ts で ` +
                `SURFACE_ROLE / TEXT_ON_SURFACE / FILL / FILL_LABEL / CONTRAST_EXEMPT の ` +
                `いずれかに分類すること (免除するなら理由を書くこと): ${unclassified.join(", ")}`,
        ).toEqual([]);

        // 逆向き: 宣言に DESIGN.md に無いトークンが紛れていないか
        const unknown = [...classified].filter((t) => !(t in COLOR_TOKEN_MAP));
        expect(unknown.sort(), `DESIGN.md に存在しないトークンが宣言されている`).toEqual([]);
    });

    it("検査対象ペアが 0 件でない (空振り防止)", () => {
        expect(PAIRS.length).toBeGreaterThan(0);
    });

    it("面ロールとテキストロールが素である (自己ペア = 比 1.0 が混入しない)", () => {
        // PAIRS は両集合の直積を取るので、重複トークンがあると
        // 「自分自身の上の自分」という無意味なペア (常に 1.0 で必ず fail) が生まれる。
        // 将来あるトークンが面とテキストの両方の役割を持つなら、
        // PAIRS の作り方 (直積) の見直しが要る — それをここで検知する。
        const surfaces = new Set<string>(SURFACE_ROLE_TOKENS);
        const overlap = TEXT_ON_SURFACE_TOKENS.filter((t) => surfaces.has(t));
        expect(
            overlap,
            `SURFACE_ROLE_TOKENS と TEXT_ON_SURFACE_TOKENS が重複している: ${overlap.join(", ")}。` +
                `直積で自己ペアが生じるので PAIRS の構築方法を見直すこと`,
        ).toEqual([]);
    });

    it("未検査宣言 (PENDING_CONTRAST_PAIRS) が空でない", () => {
        // 「gate があるからコントラストは守られている」という誤読を防ぐ宣言そのものが
        // 消し飛ばされないよう固定する。
        // 出口: 1.4.11 / alpha 合成に対応して pending が空になったら、
        // inventory.ts の宣言と本 it を **同時に削除**すること
        // (空の宣言と、空でないことを確かめるテストを残すと形骸化する)。
        expect(PENDING_CONTRAST_PAIRS.length).toBeGreaterThan(0);
    });

    it.each(PAIRS)("[opaque text] %s on %s (%s) が 4.5:1 以上", (fg, bg, context) => {
        const ratio = contrastRatio(hex(fg), hex(bg));
        expect(
            ratio,
            `${context}: text-${fg} on bg-${bg} = ${ratio.toFixed(2)}:1。` +
                `DESIGN.md の色値を見直すこと (ペア集合を縮めて green にしないこと)`,
        ).toBeGreaterThanOrEqual(AA_NORMAL_TEXT);
    });

    /* 負のコントロール: 計算器が実際に点灯することを既知値で確認する */
    it("負のコントロール: 既知の低コントラスト対を検出し、既知の高コントラスト対は通す", () => {
        expect(contrastRatio("#ffffff", "#ffffff")).toBeCloseTo(1, 5);
        expect(contrastRatio("#000000", "#ffffff")).toBeCloseTo(21, 5);
        // red-600 (#dc2626) on neutral (#f4f4f5) = 4.39 — 是正前の実測値。4.5 を割る
        expect(contrastRatio("#dc2626", "#f4f4f5")).toBeLessThan(AA_NORMAL_TEXT);
        // red-700 (#b91c1c) on neutral = 5.89 — 是正後
        expect(contrastRatio("#b91c1c", "#f4f4f5")).toBeGreaterThanOrEqual(AA_NORMAL_TEXT);
    });
});
```

## `resources/css/tokens.css`

```
/**
 * DS tokens — DESIGN.md (canonical source) の実装写像。
 *
 * 値の変更は DESIGN.md / docs/design-system.md と同一 PR で必ず行うこと
 * (tests/js/styles/canonical-source-parity.test.ts が drift を検出する)。
 *
 * 取り込み契約:
 *   本ファイルは単独でビルドしない。常に Tailwind 処理コンテキスト
 *   (`@import "tailwindcss"` の直後) から取り込まれることを前提とする。
 */

@theme {
    /* ===== Brand colors (DESIGN.md Slate × Blue) ===== */
    --color-primary:         #2563eb;
    --color-primary-hover:   #1d4ed8;
    --color-primary-soft:    rgba(37, 99, 235, 0.12);  /* primary 12% — badge / focus ring 用 */
    --color-tertiary:        #0f766e;
    --color-tertiary-hover:  #115e59;

    /* ===== Neutrals & surface ===== */
    --color-neutral:         #f4f4f5;  /* page background */
    --color-surface:         #ffffff;  /* card / modal background */
    --color-border:          #e4e4e7;
    --color-border-strong:   #a1a1aa;
    --color-text:            #18181b;
    --color-text-secondary:  #52525b;

    /* ===== Status colors ===== */
    --color-success:         #15803d;
    --color-warning:         #b45309;
    --color-danger:          #b91c1c;

    /* ===== Fonts ===== */
    --font-sans:  'Noto Sans JP', 'Hiragino Sans', 'Yu Gothic UI', 'Segoe UI',
                  ui-sans-serif, system-ui, sans-serif,
                  'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol', 'Noto Color Emoji';

    /* ===== Radius (DESIGN.md rounded) ===== */
    --radius-sm: 4px;
    --radius-md: 6px;
    --radius-lg: 8px;
}

/* ===== Typography ramp =====
   全ランプ Noto Sans JP、ウェイトは 400 / 500 の 2 階層のみ (DESIGN.md 準拠) */

@utility text-display {
    font-family: var(--font-sans);
    font-size: 48px;
    font-weight: 500;
    line-height: 1.2;
    letter-spacing: 0.02em;
}

@utility text-h1 {
    font-family: var(--font-sans);
    font-size: 32px;
    font-weight: 500;
    line-height: 1.3;
    letter-spacing: 0.02em;
}

@utility text-h2 {
    font-family: var(--font-sans);
    font-size: 24px;
    font-weight: 500;
    line-height: 1.4;
}

@utility text-h3 {
    font-family: var(--font-sans);
    font-size: 18px;
    font-weight: 500;
    line-height: 1.5;
}

@utility text-body {
    font-family: var(--font-sans);
    font-size: 16px;
    font-weight: 400;
    line-height: 1.7;
}

@utility text-caption {
    font-family: var(--font-sans);
    font-size: 12px;
    font-weight: 400;
    line-height: 1.5;
}
```

## `docs/design-system.md`

```
# Design System 運用ガイド

## Canonical source の宣言

| 役割 | 真実のファイル |
|------|---------------|
| **設計仕様 (canonical)** | `/DESIGN.md` |
| **トークン実装写像 (mirror)** | `/resources/css/tokens.css` |
| **Tailwind エントリ** | `/resources/css/app.css` (`@import "./tokens.css"`) |
| **禁止パターン定義** | `/tests/js/support/ds-purity.ts` |
| **運用ガイド (本書)** | `/docs/design-system.md` |

DESIGN.md が唯一の真実。tokens.css はその実装写像であり、独自に値を変えてはいけない。
drift は `tests/js/styles/canonical-source-parity.test.ts` が機械検出する。

## 検査の責務境界

本節で責務境界を管理するデザイントークン検査は 4 本ある
(DS purity 系など、トークンの値以外を見る検査は本節の管理対象ではない)。
**どれが何を見ているか**を混同しないこと — 見ている写像の段が違うので、
片方を消すと別の壊れ方が見えなくなる。

| 検査 | 見ている写像 | 代表的に検出する壊れ方 |
|------|------------|--------------------|
| `tests/js/styles/canonical-source-parity.test.ts` | DESIGN.md (正本) ⇔ tokens.css (宣言) のテキスト | 片方だけ更新した PR / トークンの増減 / 検査の母集団の取りこぼし |
| `tests/js/styles/tokens.test.ts` | tokens.css (宣言) ⇒ Tailwind 生成 CSS | `@theme` が解釈されない / utility 名が解決しない / app.css が tokens.css を取り込んでいない |
| `tests/js/styles/design-system-docs.test.ts` | 本書の構造 ⇔ 検査ファイルの実体 | 運用契約の節の消失 / 表と実体の食い違い |
| `tests/js/architecture/contrast-invariant.test.ts` | DESIGN.md の色値 ⇒ コントラスト比 | 読めない色の組合せ |

**この表は機械で実体と突き合わせている**。`tests/js/styles/` に検査を足したら本表にも行を足す
(足さないと `design-system-docs.test.ts` が落ちる)。逆に検査を消したら行も消す。
別の場所へ足す検査は `design-system-docs.test.ts` の `EXTERNAL_GATE_FILES` へ明示登録する。

本書の検査は、読者に描画されない領域 (HTML コメント / fenced code) を落としてから節と表を見る。
落とす判定は Markdown の fence 規則に寄せてあり (字下げした偽の終端や、
情報文字列にバッククォートを含む無効な開始行では区間が閉じない・開かない)、
コメントを取り除いた跡には**規範の最小断片には使わない制御文字**を目印として残すので、
コメントを挟んだ 2 つの断片が検査の上でだけ繋がることはない。
ただし**完全な Markdown 解析ではない** — 4 空白字下げのコードブロックと
HTML 要素による非表示は見ていない。
そのうえで節ごとに**規範の最小断片** (`design-system-docs.test.ts` の
`SECTION_CONTRACT_PHRASES`) が本文に在ることを求めるので、契約の一文を消したり
描画されない領域へ移したりすると赤になる。**文言を直すときは同じ PR で最小断片も直す**
(それが「契約を変えた」ことの可視化になる)。

保証しないもの: Vite のビルド・アセット配信・ブラウザでの適用は 4 本のどれも見ていない。
文書側で見ているのは節の構造・表の実体・最小断片までで、**周りの説明が骨抜きになったことは
検出できない**。
DESIGN.md frontmatter の `spacing:` は**値も tokens.css への実装写像の有無も検査していない**
(未検査であることは `tests/js/styles/inventory.ts` の `FRONTMATTER_SECTION_OWNERS` に
理由・解消条件・追跡先つきで宣言してある)。

## トークン変更時の運用契約

トークン(color / font / radius / typography ramp)を変更する PR は以下を**同一 PR 内で**更新する:

- [ ] `/DESIGN.md` の該当 token の値および `tailwind:` 行
- [ ] `/resources/css/tokens.css` の `@theme` / `@utility` 該当ブロック
- [ ] `/tests/js/styles/inventory.ts`(トークンの追加・削除時。parity と生成 CSS 検査の母集団を兼ねる)
- [ ] テーマ由来の制約を変える場合は `/tests/js/support/ds-purity.ts` の THEME_PATTERNS

片方だけ更新する PR は merge しない(parity テストが落ちる)。

## テーマの差し替え方(テンプレート派生アプリ向け)

既定テーマ(Slate × Blue)は**色値だけ**差し替えれば変えられる:

1. `DESIGN.md` frontmatter の colors と本文の色記述を更新
2. `tokens.css` の `--color-*` を同じ値に更新
3. parity テスト green を確認

制約体系(影なし / rounded 3 段 / weight 400-500 / ramp 必須)を変えるテーマにする場合は、
`ds-purity.ts` の **THEME_PATTERNS** を DESIGN.md と同期して書き換える。
**UNIVERSAL_PATTERNS(raw palette 禁止・hex 直書き禁止・arbitrary z 禁止・静的 inline style 禁止)
はテーマに依存しないため、どのテーマでも変更しない。**

## 新規 domain 色トークン追加の必須条件(4 条件)

以下を**すべて**満たさない限り却下する(aigenba P6 の運用実証より:
3 度の追加提案がすべて「opacity 修飾 + atom 化」で代替できた):

1. 同一 token が **3 component / 3 page 以上**で同じ意味として使われる
2. 既存の最小色構成(brand 2 + neutral 系 + state 3)と意味の重複がない
3. atom の variant 拡張 + opacity 修飾(`/10`, `/12`, `/30` 等)で表現不能である
4. DESIGN.md + tokens.css + inventory.ts + 本書を同一 PR で更新する

単一 component の識別色は file-scoped allowlist(permanent)で運用する。

## file-scoped allowlist の運用

`ds-purity.ts` の `FILE_SCOPED_ALLOWLIST` は出荷時 2 件
(`components/atoms/Avatar.svelte` と `components/atoms/Toggle.svelte`。
いずれも `rounded-full` を真円 UI の恒久例外として `lifecycle: permanent` で登録)。
例外を足すときは 7 フィールド(file / patterns / reason / owner_phase /
remove_condition / reason_classes / lifecycle)を必ず埋める。`transitional` は
撤去条件必須、`permanent` は brand 色・真円 UI(`rounded-full`)等の恒久例外のみ。

`patterns` は**区切り文字で分割した class トークンとの完全一致**で照合する。
変種の修飾や重要度の修飾が付いた形(`sm:rounded-full` / `!rounded-full`)は
**別のトークン**なので自動では免罪されず、要るならそれ自体を 1 行足して登録する。
登録した文字列が単一の class トークンとして成立していること(= 登録した瞬間に
死んでいる例外を作らないこと)は `ds-purity.test.ts` が機械で確かめる。

## コンポーネント追加時のチェックリスト

- [ ] 配置層(atoms / molecules / organisms / features / templates)。迷ったら下の層へ
- [ ] スタイルは DS token と ramp のみ(ds-purity green)
- [ ] variant→class は `Record<Variant, string> satisfies` で網羅保証
- [ ] DESIGN.md §Components に意味論・使い分けを追記
- [ ] vitest(render + 型制約 `@ts-expect-error`)を同 PR で追加
- [ ] アイコンは `@lucide/svelte` のみ(inline SVG 禁止)
```

## `tests/js/styles/design-system-docs.test.ts` (renderedLines 周辺 L90-170)

```ts
 * 目印を**空白にしてはいけない** — 最小断片が元々空白を含む位置
 * (`同一 PR 内で` の空白等) にコメントを置かれると、空白では一致を防げないためである。
 *
 * 閉じないまま EOF に達したら、そこまでを潰す。
 */
const FENCE_OPEN = /^ {0,3}(`{3,}|~{3,})/;
const FENCE_CLOSE = /^ {0,3}(`{3,}|~{3,})[ \t]*$/;

/**
 * コメントを取り除いた跡に残す目印。垂直タブ (U+000B) を使う。
 *
 * 要件は 2 つある。
 *   1. **規範の最小断片には使わない文字**であること。半角空白のように断片へ現れる文字だと、
 *      最小断片が元々空白を含む位置 (`同一 PR 内で` の空白等) を狙って断片を合成できてしまう
 *   2. **`trim()` が空白として落とす文字**であること。落とさない文字 (U+0000 等) だと、
 *      コメントだけの行が「本文のある行」に見えて節の非空検査をすり抜ける
 * 垂直タブはこの 2 つを同時に満たす (最小断片には使わない / `trim()` の対象)。
 * ファイルに格納できないという意味ではない — 使わないと決めているだけである。
 */
const HIDDEN_MARK = "\u000B";

function renderedLines(doc: string): readonly string[] {
    const out: string[] = [];
    let fence: { readonly char: string; readonly length: number } | null = null;
    let inComment = false;

    for (const raw of doc.split(/\r?\n/)) {
        if (fence !== null) {
            const close = raw.match(FENCE_CLOSE);
            if (close !== null && close[1][0] === fence.char && close[1].length >= fence.length) {
                fence = null;
            }
            out.push("");
            continue;
        }

        let line = raw;
        if (inComment) {
            const end = line.indexOf("-->");
            if (end < 0) {
                out.push("");
                continue;
            }
            // コメントの終端より後ろだけを描画される本文として残す (跡に目印を置く)
            line = HIDDEN_MARK + line.slice(end + 3);
            inComment = false;
        }

        // 同一行に閉じる HTML コメントは繰り返し取り除く (跡には目印を 1 つ残す)
        for (;;) {
            const start = line.indexOf("<!--");
            if (start < 0) break;
            const end = line.indexOf("-->", start + 4);
            if (end < 0) {
                line = line.slice(0, start) + HIDDEN_MARK;
                inComment = true;
                break;
            }
            line = line.slice(0, start) + HIDDEN_MARK + line.slice(end + 3);
        }

        const open = line.match(FENCE_OPEN);
        // バッククォート fence の情報文字列にバッククォートがある行は開始 fence ではない
        const infoString = open === null ? "" : line.slice(open[0].length);
        if (open !== null && !(open[1][0] === "`" && infoString.includes("`"))) {
            fence = { char: open[1][0], length: open[1].length };
            out.push("");
            continue;
        }

        out.push(line);
    }

    return out;
}

/**
 * 見出しから、次の同レベル以上の見出しまでの本文を返す。
 * `## X` の中の `### Y` は同じ節の本文として残る。
 */
function extractSection(lines: readonly string[], heading: string): readonly string[] {
```

## `tests/js/support/ds-purity.ts` (class トークンの区切りの宣言 L130-175)

```ts

/**
 * class トークンを構成する文字。これ以外の文字はすべて区切りとして扱う。
 *
 * 含める文字と理由:
 *   英数字 / `_` / `-`  … utility 名の本体 (`rounded-full`)
 *   `:`                 … 変種の修飾 (`sm:` `hover:`)
 *   `/`                 … 不透明度の指定 (`bg-primary/50`)
 *   `.` `%`             … 任意値の中の数値 (`w-[62.5%]`)
 *   `[` `]`             … 任意値 (`text-[13px]`)
 *   `!`                 … 重要度の修飾 (`!rounded-full` / `rounded-full!`)
 *   `#`                 … 色の直値 (`#1DA1F2`。将来ブランド色を登録するときに 1 トークンで扱えるようにする)
 *
 * **保証しないもの (誇張しない)**: 丸括弧・`@`・カンマを含む書き方
 * (`bg-(--var)` / `@md:flex`) はここでトークンが割れるため、その形は
 * 許可一覧に**登録できない**。登録が要るようになったらこの文字集合を広げる
 * (広げたら「許可一覧の全エントリが単一の class トークンとして成立している」検査が
 * 巻き添えで赤くなるので、黙って広がることはない)。
 */
const CLASS_TOKEN_PATTERN = /[A-Za-z0-9_:./[\]!%#-]+/g;

/** 許可一覧の 1 エントリが class トークンとして成立しているか (登録した瞬間に死んでいる例外を防ぐ) */
export function isSingleClassToken(value: string): boolean {
    const matched = value.match(CLASS_TOKEN_PATTERN);

    return matched !== null && matched.length === 1 && matched[0] === value;
}

/**
 * content から allowlist で許可された class トークンを除去する (除去後に禁止パターンを適用する)。
 *
 * 除去は**区切り文字で分割した class トークンの完全一致**でのみ行う。
 * 素の部分文字列で除去すると、許可語を部分に含む別の語 (`!rounded-full` /
 * `sm:rounded-full` / `rounded-full/50`) まで一緒に消えて**検出漏れ**になる。
 * 許可したのは「真に円形な UI であること」だけであり、変種の修飾や重要度の修飾が
 * 付いた別の書き方まで許した覚えはない。
 *
 * トークンの前後は必ず区切り文字なので、除去によって隣り合うトークンが連結することはない。
 */
export function stripAllowlisted(relPath: string, content: string): string {
    const allowed = allowlistPatternsFor(relPath);
    if (allowed.length === 0) {
        return content;
    }
    const allowedTokens = new Set(allowed);

```
