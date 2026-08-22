# アプリの使命 (North Star) — AGENTS.md より

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則

# 禁止事項 — AGENTS.md より

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

## セキュリティ不変条件(アプリ都合で緩めない)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# あなたの役割

あなたは Laravel 12 + Svelte 5 (Inertia.js) + TypeScript アプリ「AI-CUE」のコードレビュアーである。
TODO T251「Svelte raw HTML sink の deny-by-default 禁止 (家系正典 t1 追従)」の実装差分をレビューする。

リポジトリは /workspace/.claude/worktrees/tasks/T251 にある (読み取りのみ可)。
必要なら周辺ファイルを読んでよい。

## レビュー観点

1. **詳細設計との一致性**: 設計書の施策 1〜8 が意図どおり実装されているか。
   設計から**意図的に外した点**があるとき、その判断は妥当か (下の「設計からの逸脱」を参照)。
2. **正確性**: gate / 検出器の判定ロジックにバグや見逃しが無いか。
   特に fail-closed 性 (解決できない形を落とす / 母集団が空なら落とす / lint 結果が使えるかを
   rule 件数より前に確かめる) が本当に成立しているか。
3. **AGENTS.md「静的検査 (gate) と走査器の共通規約」への適合**:
   (a) 名前解決の完全修飾 (本件は文字列走査なので適用外の可能性あり) /
   (b) 解決できない形を落とす・保証範囲の docblock 明記・「違反 0 件」と「母集団 0 件」の区別 /
   (c) 検出力を負例で裏取り (両方向) / (d) 集めた走査結果を判定に使わない形を作らない /
   (e) 語彙一致の否定形はトークン完全一致 (許可一覧を持たない本検出器に適用されるか要判断)。
4. **PHPStan level 10 適合性**: 追加した PHP コード (テスト内 helper 含む) の型安全性。
5. **DTO / JsonResource パターン**: 本差分に該当があるか (無ければ「該当なし」でよい)。
6. **テスト網羅性**: テストが実際に検出力を持つか。トートロジーになっていないか。
   「実装前に一度赤を見た」だけで恒久的な裏取りが無い箇所は無いか。
7. **セキュリティ**: XSS sink の閉じ方として本当に十分か。
   data URI + `<img>` への置換で新たな穴が開いていないか (CSP 依存を含む)。
8. **DESIGN.md 準拠**: `/DESIGN.md` が design token の canonical source。
   color / radius / typography は token 経由で参照し hex 直書き (`#RRGGBB`) を増やさない。
   token 値を変更する diff は `resources/css/tokens.css` と同一 diff 内で同期しているか
   (運用契約は `docs/design-system.md`)。
9. **Atomic Design 準拠**: `resources/js/components/` は atoms/molecules/organisms/templates の
   責務分離に従う。atom は単機能・状態を持たない、molecule は atom の組合せ、という階層を
   逆流していないか。アイコンは Lucide を使い、SVG 直書きを増やさない。

## 設計からの逸脱 (実装時に判断した点。妥当性を判定せよ)

**逸脱 1: 無効化コメント 3 形式の選び直し。**
詳細設計は無効化の 3 形式として HTML コメント形式
(`<!-- eslint-disable -->` / `<!-- eslint-disable svelte/no-at-html-tags -->` /
`<!-- eslint-disable-next-line svelte/no-at-html-tags -->`) を挙げていた。
実装前に実測したところ、この lint 構成では **noInlineConfig:false の対照条件でも
HTML コメント形式は 1 つも rule を無効化できなかった** (eslint-plugin-svelte の
`svelte/comment-directive` を有効化していないため、HTML コメントが directive として
解釈されない)。すなわち HTML コメント形式は「元から解釈されていない文字列」であり、
負例として無効である (設計書の施策 4 リスク欄が予告していた分岐)。
そこで DISABLE_FORMS を script ブロック内の JS コメント 3 形式
(`/* eslint-disable */` / `/* eslint-disable svelte/no-at-html-tags */` /
`/* eslint svelte/no-at-html-tags: "off" */`) に置き換え、実測で
「noInlineConfig:true では効かない・false では効く」の両方向を固定した。
併せて検査 B'' を新設し、HTML コメント形式が**対照条件でも効かない**ことを固定した
(将来 comment-directive を有効化したら B'' が赤くなり、その形式を DISABLE_FORMS へ
移す信号になる)。

**逸脱 2: 画面テストの「script が生えない」検査の書き方。**
設計は「DOM に `<script>` 要素が生えないこと」に加えて `<svg>` の非存在も想定していたが、
画面全体には Lucide アイコンの `<svg>` が実在するため、`container.querySelector("svg")` は
置換後も非 null になる。そこで QR 要素の**部分木に子要素が 1 つも無いこと**
(`qr.querySelectorAll("*")` が空) + 画面全体に `<script>` が無いこと、の 2 点に変えた。
部品テスト側 (QrCodeImage.test.ts) では container に Lucide が居ないので、
`<svg>` / `<script>` の非存在を直接固定している。

**逸脱 3: `containsRawHtmlSink()` の実装。**
設計は `source.includes("{" + "@html")` と文字列連結で書いていたが、
本 gate は自分自身を走査根に含めないので連結する理由が無く、
`source.includes("{@html")` と直接書いた。

## 出力形式

ファイルごとに判定を書き、指摘は次の 3 分類で示すこと:

- **[Critical]** — 必ず直すべき欠陥 (バグ / セキュリティ / 不変条件の穴 / 規約違反)
- **[Warning]** — 直すことを強く推奨する問題
- **[Suggestion]** — 任意の改善提案

最後に**全体判定**を `APPROVED` または `CHANGES_REQUESTED` のいずれか 1 語で明示すること。

**過剰な指摘をしないこと**: 本リポジトリは「今必要なものだけ作る」(オーバーエンジニアリング禁止)
を思考原則に掲げている。汎用化・将来の拡張・「あったら便利」の提案は Suggestion 止まりにし、
それを理由に CHANGES_REQUESTED にしないこと。

---

## 詳細設計書 (devnotes/20260823-0017-svelte-raw-html-sink-ban/detailed-design.md)

# 詳細設計: svelte-raw-html-sink-ban (家系正典 t1 への追従)

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

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- フロントは Svelte 5 runes + DS token/ramp のみ（`DESIGN.md` が canonical、ds-purity テストが検出）
- component 階層は `atoms → molecules → organisms → features → templates → pages` の単方向 import

## 概念設計リファレンス

`devnotes/20260823-0017-svelte-raw-html-sink-ban/conceptual-design.md`
（Codex `gpt-5.6-terra` レビュー Round 2 で **APPROVED**）

---

## 正典が求める不変条件（全列挙）

出典: lctl `get_feature svelte-raw-html-sink-ban`
（`canonical_version: t1` / `feature_revision: 3-dc59a9928099` / `ledger_revision: 81f0e624…`）。
boundary が「正典の版 t1 の内訳は上記 (1)〜(4) の 4 点そろいである」と明記している。

| ID | 不変条件（正典の文言に対応） | 本設計での保証機構 |
|---|---|---|
| **I1** | lint 設定で当該規則 (`{@html}` の禁止) を **error** にすること | 施策 1（`eslint.config.js`）+ 施策 4 検査 A |
| **I2** | ファイル内のコメントで規則を**無効化できない**こと。無効化の **3 形式**を負例として**実際に lint を走らせて**確かめる | 施策 4 検査 B / B'（対照条件） |
| **I3** | 対象ディレクトリ配下の**実ファイルに当該構文が 0 件**であることの**直接固定** | 施策 3（唯一の実在サイトの除去）+ 施策 4 検査 C |
| **I4** | 唯一の正当な用途（サーバ生成の QR を描く）に対する**置き換え先の部品** | 施策 2（`QrCodeImage.svelte`）+ 施策 5 |
| **I5** | その部品が依存する**応答ヘッダの指示**（画像取得元に `data:` を許す）を **2 通りの構成の両方**で固定 | 施策 7（`SecurityHeadersTest.php`） |
| **I6** | **許可一覧の口を持たない**（例外を設けるなら別のセキュリティ設計としてレビューを通す） | 施策 1 のコメント宣言 + 施策 4 は exemption inventory を**持たない** |
| **I7** | gate は**参照が別ファイルへ向いている場合は落とす側へ倒す**（fail-closed） | 施策 4 検査 E/F（config 解決失敗・母集団 0 件・lint の fatal・ignored 扱いはすべて fail） |

### 運用契約: `resources/js` 配下の `.svelte` に禁止構文の**字面**を書かない

検査 C は「`resources/js` 配下の `.svelte` 本文に文字列 `{` + `@html` が 0 件」を
**コメント内・文字列リテラル内も含めて**数える（構文解析器を持たない字面走査。
目標値が 0 件なので拾いすぎる方向へ倒すのは AGENTS.md (b) の許す側である）。

したがって **`resources/js` 配下の `.svelte` では、説明のためであっても禁止構文の字面を書けない**。
コメントでは「raw HTML 挿入構文」「生 HTML を DOM へ差し込む構文」と**呼び名で**書く。
字面を書いてよいのは**走査対象の外**、すなわち
`eslint.config.js` / `DESIGN.md` / 本 gate 自身（gate は負例入力として**字面が必要**）である。

この契約は「気を付ける」ではなく**検査 C が機械で強制する**。
実装時は、提案するすべての `.svelte` 本文に対して先に同じ部分文字列検査をかけてから入れる。

**正典が「含まない」と明記しているもの**（本設計もスコープ外にする）:
lint / 型 / 整形設定の基礎そのもの（`eslint-svelte-ts-baseline`）/
応答ヘッダを配る仕組み自体（`security-headers-csp`。本 feature が固定するのは
「置き換え先の部品が依存する画像取得元の指示が緩まないこと」だけ）/
部品の粒度と設計体系の純度（`atomic-design-gates`）/
雛形へ外部由来の文字列を渡すときの防御（`prompt-injection-defense`）。

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | lint 規則 `svelte/no-at-html-tags` を error にする | `eslint.config.js` | 高 |
| 2 | 置き換え部品 `QrCodeImage` atom を新設 | `resources/js/components/atoms/QrCodeImage.svelte`（新規） | 高 |
| 3 | 唯一の実在サイトを置換し `{@html}` を除去 | `resources/js/pages/Settings/Security.svelte` | 高 |
| 4 | raw HTML sink gate を新設 | `tests/js/architecture/svelte-raw-html-gate.test.ts`（新規） | 高 |
| 5 | 部品テスト | `tests/js/components/atoms/QrCodeImage.test.ts`（新規） | 高 |
| 6 | 画面テスト（QR 表示の実挙動）と既存テストの追随 | `tests/js/pages/SettingsSecurityTwoFactorQr.test.ts`（新規）/ `tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts`（既存） | 高 |
| 7 | 応答ヘッダの依存を 2 構成で pin | `tests/Feature/Security/SecurityHeadersTest.php` | 高 |
| 8 | 設計規約への追記 | `DESIGN.md` | 中 |

---

## 施策 1: lint 規則 `svelte/no-at-html-tags` を error にする

### 変更箇所
- ファイル: `eslint.config.js`（`.svelte` 向け rules ブロック。現行 L120 付近）

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 施策 4 の新 gate が本変更を検査する。
  既存の `tests/js/architecture/svelte-no-undef-gate.test.ts` は
  `no-undef` の severity / `globals` の完全一致 / `noInlineConfig` の 3 点だけを見るので、
  **rules に 1 本足しても影響しない**（実読で確認）。

### 現行コード
```js
    {
        files: ["**/*.svelte"],
        plugins: { svelte },
        languageOptions: {
            globals: svelteGlobals,
        },
        rules: {
            // .svelte は tsc の検査対象外 (tsc --listFiles に 1 件も現れない)。
            // 未定義識別子を捕まえる機構がここにしか無いので error 固定
            // (spirux:T1054 = SSO 接続追加画面のクラッシュと同型の事故を止める)。
            "no-undef": "error",
            "svelte/require-each-key": "error",
            "svelte/prefer-svelte-reactivity": "error",
            "svelte/prefer-writable-derived": "error",
            "svelte/no-useless-mustaches": ["error", { ignoreStringEscape: true }],
        },
    },
```

### 変更後コード
```js
    {
        files: ["**/*.svelte"],
        plugins: { svelte },
        languageOptions: {
            globals: svelteGlobals,
        },
        rules: {
            // .svelte は tsc の検査対象外 (tsc --listFiles に 1 件も現れない)。
            // 未定義識別子を捕まえる機構がここにしか無いので error 固定
            // (spirux:T1054 = SSO 接続追加画面のクラッシュと同型の事故を止める)。
            "no-undef": "error",
            /*
             * 生の HTML を DOM へ差し込む構文 ({@html}) の全面禁止。
             *
             * 値の出どころが 1 か所でも汚れていれば script がそのまま実行される。
             * 撮影 PWA は同一オリジン・セッション認証なので、XSS の成立は
             * 撮影導線の資格情報にそのまま届く。
             *
             * **許可一覧 (allowlist / exemption inventory) の口は持たない**。
             * 例外を設けるなら、その口を排除できない理由・安全境界・専用テストを含む
             * **別のセキュリティ設計**としてレビューを通すこと
             * (file-scoped override をここに書き足して済ませない)。
             *
             * サーバ生成の SVG (2 要素認証の QR) を描く用途には
             * components/atoms/QrCodeImage.svelte を使う (data URI の <img>)。
             *
             * 実効性の裏取りは tests/js/architecture/svelte-raw-html-gate.test.ts
             * (実際に lint を走らせ、無効化コメント 3 形式が効かないことまで固定する)。
             */
            "svelte/no-at-html-tags": "error",
            "svelte/require-each-key": "error",
            "svelte/prefer-svelte-reactivity": "error",
            "svelte/prefer-writable-derived": "error",
            "svelte/no-useless-mustaches": ["error", { ignoreStringEscape: true }],
        },
    },
```

### PHPStan適合チェック
- 対象外（JS 設定ファイル）

### テスト計画
- [ ] 施策 4 の gate 検査 A（`calculateConfigForFile()` で `.svelte` の実効 severity が error）
- [ ] 施策 4 の gate 検査 B（実際に lint を走らせて違反入力が error になる）
- [ ] `pnpm lint` が全体で green（施策 3 で唯一の違反を除去済みであること）

### リスク
- **順序依存**: 施策 3 より先に本施策だけを入れると `pnpm lint` が赤くなる。
  → 本施策は**実装順の最後（段 7）**に置き、施策 1〜3 は同一 PR / 同一ブランチで入れる
  （下の「実装順」表）。
- `svelte/no-at-html-tags` は `eslint-plugin-svelte` v3.22 に実在する（`node_modules` 実読で確認）。
  依存追加は不要。

---

## 施策 2: 置き換え部品 `QrCodeImage` atom を新設

### 変更箇所
- ファイル: `resources/js/components/atoms/QrCodeImage.svelte`（新規）

### 波及変更
- TypeScript 型定義: `Props` interface を component 内に持つ（`Avatar.svelte` と同形）。
  別ファイルの `.types.ts` は作らない（`Badge` / `Button` / `Toggle` のように
  「仕様の真実を型ファイルに置く」ほどの選択肢を持たないため。思考原則 2）。
- API Resource/DTO: なし
- テストファイル: 施策 5（新規）

### 現行コード
（新規ファイルのため無し）

### 変更後コード
```svelte
<script lang="ts">
    /**
     * QrCodeImage atom。**サーバが生成した SVG 文字列を data URI の <img> として描く**。
     *
     * 存在理由: raw HTML 挿入構文 (生 HTML を DOM へ差し込む構文) を使わずに
     * サーバ生成の QR を表示するための唯一の手段を配る。
     * 禁止構文は文字列を DOM 木として解釈させるが、本部品は画像リソースとして読ませる。
     * lint 規則 svelte/no-at-html-tags と対で 1 組である
     * (禁止だけを配ると現場は使い続けるため、代わりの手段を同時に配る)。
     *
     * ※ 本ファイルは resources/js 配下の .svelte なので、
     *   禁止構文の**字面**は書けない (svelte-raw-html-gate の検査 C が字面で数えるため)。
     *   正本の説明は eslint.config.js のコメントと DESIGN.md にある。
     *
     * **保証範囲 (誇張しない)**: 本部品が保証するのは
     * 「SVG 文字列を DOM へ HTML として挿さないこと」までである。
     * browser が画像文脈の SVG をどう扱うかの細部は本部品の保証範囲ではない。
     *
     * data URI は **percent encoding** で作る (base64 を採らない):
     *   - btoa() は非 ASCII を含む SVG で例外を投げる
     *   - TextEncoder 経由の base64 化は安全性が同じで手数だけ増える
     *   - 素朴な文字列連結は `#` (fragment 開始) で切れ、`%` が不正な escape になり、
     *     非 ASCII で壊れる
     */

    interface Props {
        /** サーバが生成した SVG 文字列。**null 許容にしない** (呼び出し側が分岐を持つ) */
        svg: string;
        /** 画像の代替テキスト。必須 (アクセシブルネームの正本) */
        alt: string;
        testId?: string;
    }

    let { svg, alt, testId }: Props = $props();

    const src = $derived(`data:image/svg+xml,${encodeURIComponent(svg)}`);
</script>

<img {src} {alt} data-testid={testId} />
```

**`class` prop は持たない**（初版から削除）。現在の唯一の呼び出し箇所は
wrapper 側で寸法・装飾を管理しており `class` を渡さない。
「あったら便利」は作らない（AGENTS.md 思考原則 2）。
将来ほんとうに寸法差が要るようになったら、任意 class ではなく
DS 制約付きの `size` prop を検討する（申し送り）。

### PHPStan適合チェック
- 対象外（Svelte component）
- TypeScript: `svg` / `alt` を必須にすることで、呼び出し側の `string | null` を
  そのまま渡すと `pnpm typecheck` が落ちる（nullable の吸収を atom 側に作らない）

### テスト計画
- [ ] 新規テスト `tests/js/components/atoms/QrCodeImage.test.ts`（施策 5 で詳述）
- [ ] `ds-purity` テスト: 本部品は class を 1 つも書かないため
      `FILE_SCOPED_ALLOWLIST` への登録は不要（ramp 外 utility を自前で書かない）
- [ ] `svg-inline-allowlist.test.ts`: 本部品は `<svg` 要素を**書かない**ため抵触しない
- [ ] `atomic-import-graph.test.ts`: atom は他層を import しない（本部品は import 0 件）

### リスク
- **自己違反**: 本ファイルは検査 C の走査対象なので、docblock に禁止構文の**字面**を
  書くと gate が赤くなる。→ 上の運用契約どおり呼び名で書く（コード例に反映済み）。
- **percent encoding の副作用**: `encodeURIComponent` は `'` を encode しないが、
  `src` 属性は Svelte が属性値としてエスケープするため属性境界は壊れない。
- **画像サイズ**: `<img>` は intrinsic size を SVG から取る。
  Fortify の QR SVG は `width`/`height` を持つ（現行の raw HTML 挿入でも同じ寸法で
  描けている）ため、レイアウト崩れは起きない。
  万一寸法調整が要るなら wrapper 側（`Security.svelte`）で行い、atom に口を開けない。

---

## 施策 3: 唯一の実在サイトを置換し `{@html}` を除去

### 変更箇所
- ファイル: `resources/js/pages/Settings/Security.svelte`（L631-643 付近 = 唯一の `{@html}`）
- import 追加（L3-8 の atom import 群）

### 波及変更
- TypeScript 型定義: なし（`qrSvg` の型 `string | null` は変えない）
- API Resource/DTO: なし（`/user/two-factor-qr-code` の応答形は変えない）
- テストファイル: 施策 6（新規 + 既存追随）

### 現行コード
```svelte
                            {#if qrSvg}
                                <!-- QR はサーバ提供の SVG をそのまま描画する。svg 文字列に属性を注入せず、
                                     wrapper を role="img" にしてアクセシブルネームを与える (H14) -->
                                <div
                                    role="img"
                                    aria-label="2 要素認証の設定用 QR コード"
                                    class="self-start rounded-md border border-border bg-surface p-4"
                                    data-testid="two-factor-qr"
                                >
                                    {@html qrSvg}
                                </div>
                            {:else}
```

### 変更後コード
```svelte
                            {#if qrSvg}
                                <!-- QR はサーバ生成の SVG を **data URI の <img>** として描く。
                                     生の HTML を DOM へ差し込む構文は使わない
                                     (禁止の正本は eslint.config.js の svelte/no-at-html-tags)。
                                     アクセシブルネームは img の alt が正本なので、
                                     wrapper の role="img" / aria-label は持たせない (二重命名を避ける)。 -->
                                <div class="self-start rounded-md border border-border bg-surface p-4">
                                    <QrCodeImage
                                        svg={qrSvg}
                                        alt="2 要素認証の設定用 QR コード"
                                        testId="two-factor-qr"
                                    />
                                </div>
                            {:else}
```

import 追加（既存の atom import 群のアルファベット順に沿って `Input` の後）:
```svelte
    import QrCodeImage from "@/components/atoms/QrCodeImage.svelte";
```

### PHPStan適合チェック
- 対象外（Svelte component）

### テスト計画
- [ ] 新規テスト `tests/js/pages/SettingsSecurityTwoFactorQr.test.ts`（施策 6）
- [ ] 既存 `tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts` が green のまま
      （`getByTestId("two-factor-qr")` は img 側に移るが**存在検査だけ**なので通る。実読で確認）
- [ ] 既存 `tests/js/pages/SettingsSecurity.test.ts` が green のまま（QR の DOM 形に依存していない）

### リスク
- **状態機械への波及なし**。取得失敗 Alert / 再認証 step-up / 世代管理 / `resetEnrollmentAssets()` は
  いずれも `qrSvg` の**値**しか見ておらず、描画形の変更は届かない（実読で確認）。
- **アクセシビリティの後退**: wrapper の `role="img"` を外すので、
  アクセシブルネームの正本が `alt` 1 か所になる。
  → 施策 6 の新規テストで `getByAltText("2 要素認証の設定用 QR コード")` を固定する。
- **`data-testid` の移動**: wrapper → img。既存テストは存在検査のみなので影響しない。

---

## 施策 4: raw HTML sink gate を新設

### 変更箇所
- ファイル: `tests/js/architecture/svelte-raw-html-gate.test.ts`（新規）

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 本施策そのもの

### 現行コード
（新規ファイルのため無し）

### 変更後コード（構造と検査項目。実装時に肉付けする）

```ts
import { describe, it, expect } from "vitest";
import fs from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { ESLint } from "eslint";

/*
 * svelte-raw-html-gate — 生の HTML を DOM へ差し込む構文 ({@html}) の
 * 全面禁止が **実効である**ことを、振る舞いから固定する。
 *
 * 家系の機能台帳 lctl feature `svelte-raw-html-sink-ban` (canonical_version t1) の
 * 4 点そろいのうち (1)(2)(3) を担う (残る (4) は QrCodeImage.svelte と
 * tests/Feature/Security/SecurityHeadersTest.php)。
 *
 * 検査する不変条件:
 *   A. [config・全数] C が収集した resources/js 配下の .svelte **全件**について
 *      calculateConfigForFile() の実効 severity が error である。
 *      代表 1 件では、特定ファイル向け override で規則を off にされたときに見逃す。
 *   B. [振る舞い] 禁止構文を含む合成入力を実際に lint すると error になる。
 *      **無効化コメント 3 形式を付けても error のまま**である:
 *        (i)   先頭 <!-- eslint-disable -->
 *        (ii)  先頭 <!-- eslint-disable svelte/no-at-html-tags -->
 *        (iii) 違反行の直前 <!-- eslint-disable-next-line svelte/no-at-html-tags -->
 *   B'. [負例の裏取り] 同じ 3 形式が noInlineConfig:false の**対照条件**では
 *      実際に error を消せる。これが無いと「元から解釈されていない文字列」を
 *      負例と称して緑になる (検出力の空振り)。
 *   C. [実ファイル] resources/js 配下の .svelte 全数に禁止構文が 0 件。
 *      判定は純関数 containsRawHtmlSink() が行う。
 *   C'. [C の検出力] containsRawHtmlSink() を合成入力で恒久的に裏取りする。
 *      実ファイルが 0 件になった後も検出器が生きていることを保証する
 *      (実ファイル 0 件の状態では C だけでは検出器の生存を確かめられない)。
 *   D. [正例・lint] 禁止構文を含まない規定どおりの入力を ESLint が誤検出しない。
 *   E. [fail-closed] 走査根 resources/js が解決できない / 母集団が 0 件 /
 *      config 解決に失敗した場合は**落とす**。
 *   F. [fail-closed・lint] すべての lintText 結果について
 *      「lint が実際に走って結果が使える」ことを、**対象 rule の件数を見る前に**確認する
 *      (fatalErrorCount === 0 / fatal な message が無い / その filePath が ignored でない)。
 *      ESLint は構文解析エラーを throw せず fatal message として返すため、
 *      「対象 rule が 0 件」だけを見ると解析失敗も ignored も正常扱いしてしまう (fail-open)。
 *   F'. [F の検出力] 判定は純関数 assertLintExecutionUsable() が行い、
 *      合成入力で正負を恒久的に裏取りする (B/B'/D はすべて正常に parse される入力なので、
 *      F の検査を壊しても実入力では気付けない)。
 *
 * **許可一覧の口は持たない** (正典が明記する方針)。
 * 例外を設けるなら、その口を排除できない理由・安全境界・専用テストを含む
 * 別のセキュリティ設計としてレビューを通すこと。
 *
 * 走査対象: resources/js 配下の `.svelte` 全数 (git 追跡かどうかは見ない。実測 123 件)。
 * 検出の区切り: 文字列 `{` + `@html` の出現。
 *   **コメント内・文字列リテラル内も違反として数える** — 構文解析器を持たない字面走査であり、
 *   目標値が 0 件なので拾いすぎる方向へ倒すのは AGENTS.md (b) の許す側である。
 *   帰結として **resources/js 配下の .svelte では説明のためであっても禁止構文の字面を書けない**
 *   (コメントでは「raw HTML 挿入構文」と呼び名で書く)。
 *   字面を書いてよいのは走査対象の外 — eslint.config.js / DESIGN.md / 本 gate 自身である
 *   (本 gate は負例入力として字面が**必要**なので、自分自身を走査根に含めない)。
 * 保証しないもの:
 *   - 禁止構文以外の raw HTML sink (innerHTML 直代入 / svelte:element の動的タグ /
 *     document.write 等) には**無言で効かない**。
 *   - resources/js の外の .svelte は走査しない (lint 対象と一致させている)。
 *   - browser が画像文脈の SVG をどう扱うかは本 gate の対象ではない。
 */

/** 本文に raw HTML 挿入構文の字面が含まれるか (検査 C の判定の正本。C' が裏取りする)。 */
export function containsRawHtmlSink(source: string): boolean {
    return source.includes("{" + "@html");
}

/**
 * lint 結果が「判定に使える」か (検査 F の判定の正本。F' が裏取りする)。
 * 対象 rule の件数を数える**前に**通す。違反理由を返す (空配列 = 使える)。
 */
export function assertLintExecutionUsable(
    result: { fatalErrorCount: number; messages: readonly { fatal?: boolean }[] },
    isIgnored: boolean,
): string[] { /* … */ }
```

検査の実装方針:

| 検査 | 実装 |
|---|---|
| **C** | `resources/js` を再帰走査して `.svelte` を集め（= **母集団**）、各ファイルの本文を `containsRawHtmlSink()` に渡す。`true` を返したパスを一覧で報告して fail |
| **C'** | `containsRawHtmlSink()` を合成入力で直接テストする（期待値は下の 2 表で確定）。実ファイルが 0 件になった後も検出器の生存を保証する |
| **A** | **C が収集した母集団の全件**について `new ESLint({ cwd: REPO_ROOT }).calculateConfigForFile(path)` を解決し、`rules["svelte/no-at-html-tags"]` の実効 severity が `2`（または `"error"`）であることを確認。違反パスを一覧で報告。解決が throw したらそのまま fail |
| **B** | `eslint.lintText(source, { filePath: <resources/js 配下の仮想 .svelte パス> })` を 4 本（素の違反 + 無効化 3 形式）走らせ、いずれも `svelte/no-at-html-tags` の error が **1 件以上**残ることを確認 |
| **B'** | `new ESLint({ cwd: REPO_ROOT, overrideConfig: { linterOptions: { noInlineConfig: false } } })` で同じ 3 形式を lint し、いずれも当該 rule の error が **0 件になる**ことを確認（負例が負例として効いている裏取り） |
| **D** | 禁止構文を含まない正常な `.svelte`（`{expr}` / `{@const}` / `{#if}`）を lint し、当該 rule の報告が 0 件であることを確認 |
| **E** | 走査根 `resources/js` が存在しなければ fail。`.svelte` の母集団が 0 件なら fail |
| **F** | B / B' / D で得たすべての `LintResult` を、**対象 rule の件数を数える前に** `assertLintExecutionUsable(result, await eslint.isPathIgnored(filePath))` へ通す。`lintText` が throw した場合も握り潰さずそのまま fail |
| **F'** | `assertLintExecutionUsable()` を合成入力で直接テストする（期待値は下の表で確定）|

#### C' の期待値（`containsRawHtmlSink()`）

検出契約は「部分文字列 `{`+`@html` を含めば違反」である。したがって
**禁止文字列を内包する形はすべて `true` 側**であり、正例ではない。

| 期待 | 入力の形 |
|---|---|
| `true` | 実構文（`{@html value}`）/ HTML コメント内に字面を置いた本文 / `<script>` 内の文字列リテラルに字面を置いた本文 / 禁止文字列を**内包する**接頭辞・接尾辞つきの綴り |
| `false` | 通常の補間 `{name}` / `{@const x = 1}` / `{@render children()}` / `{#if cond}` / **禁止文字列を内包しない近い綴り**（例: `{@htm value}`） |

> AGENTS.md (e) の「接頭辞つき・打ち消しつき・接尾辞つきの 3 形」は
> **許可語を否定的に除去する照合**に対する規約であり、
> **許可一覧を持たない**本検出器には適用されない。ここでは上表の期待値が正本である。

#### F' の期待値（`assertLintExecutionUsable()`）

| 期待 | 入力 |
|---|---|
| 使える（空配列） | `fatalErrorCount: 0` / fatal な message 無し / `isIgnored: false` |
| 使えない | `fatalErrorCount > 0` |
| 使えない | `messages` に `fatal: true` を含む |
| 使えない | `isIgnored: true` |

**合成入力はファイルに書き出さない**（`lintText` の `filePath` は仮想パスでよい）。
`resources/js` 配下に fixture ファイルを置くと検査 C の母集団に混ざり、
「実ファイル 0 件」の意味が壊れるため。

**同じ母集団を A と C の両方が判定に使う**（AGENTS.md (d)「集めた走査結果を判定に使わない形を作らない」）。

### PHPStan適合チェック
- 対象外（vitest テスト）

### テスト計画
- [ ] 本 gate 自身が A / B / B' / C / C' / D / E / F / F' を持つ
- [ ] **恒久的な**検出力の裏取りは **C'（走査器の純関数）/ F'（fail-closed 判定の純関数）/
      B'（lint 対照条件）** の 3 つが担う。
      「実装前に一度赤を見る」だけでは、違反を消した後に検出器を壊しても緑になるため不十分
- [ ] テストファースト: 施策 1 を入れる**前に**本 gate を書いて **A と B が赤くなること**を確認する
      （思考原則 5 / AGENTS.md「走査器・gate を新設するときに揃える 4 点」の 1）
- [ ] 施策 3 を入れる**前に** C が赤くなること（`Security.svelte` の 1 件を検出する）を確認する
- [ ] `pnpm test` に含まれる（`tests/js/architecture/` は既存 vitest レーン）

### リスク
- **ESLint API の版差**: `eslint` は `^10.8.0`。`ESLint` クラスの
  `calculateConfigForFile` / `lintText` / `overrideConfig` はいずれも v10 の公開 API。
  既存 `svelte-no-undef-gate.test.ts` が `calculateConfigForFile` を使って動いているため、
  少なくとも A の経路は実績がある。
- **実行時間**: `lintText` を 8 本（B: 4 本 / B': 3 本 / D: 1 本）走らせ、
  `calculateConfigForFile()` を母集団 123 件に対して回す。
  `resources/js` 全数を **lint** するわけではない（config 解決だけ）ので、
  既存 `svelte-no-undef-gate.test.ts`（同じ 123 件に同じ API を回している）と同オーダーに収まる。
- **inline configuration の解釈位置**: Svelte テンプレートの HTML コメントが
  ESLint の directive として解釈されることは B' の対照条件が**実測で**裏取りする。
  もし B' が「3 形式とも対照条件でも error が消えない」となった場合、
  その形式は**この lint 構成では負例として無効**なので、
  **B' が赤くなる = 実装時に形式を選び直せ**という信号になる（fail-closed 側に倒れている）。

---

## 施策 5: 部品テスト `QrCodeImage.test.ts`

### 変更箇所
- ファイル: `tests/js/components/atoms/QrCodeImage.test.ts`（新規）

### 波及変更
- なし

### 変更後コード（検査項目）

```ts
import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import QrCodeImage from "@/components/atoms/QrCodeImage.svelte";

describe("QrCodeImage", () => {
    // 検査は **性質** で書く。実装と同じ式 (`encodeURIComponent`) による
    // 完全一致テストはトートロジーなので**書かない**。
    //
    // 1. getAttribute("src") が "data:image/svg+xml," で始まる
    // 2. 接頭辞を除いた payload を decodeURIComponent() すると入力 SVG と一致する (往復)
    // 3. URI を壊しやすい文字 (#, %, 非 ASCII) を含む入力でも 2 の往復が成立する
    // 4. render 結果の container 内に <svg> 要素も <script> 要素も生成されない
    //    (= HTML として解釈されていないことの直接の裏取り。本部品の存在理由そのもの)
    // 5. alt と testId が渡る
});
```

### PHPStan適合チェック
- 対象外

### テスト計画
- [ ] 上記 5 項目
- [ ] 検査 4 は「本部品の存在理由」そのものの裏取りなので**必須**
- [ ] `class` prop は持たないので、その検査は書かない（施策 2 の修正に対応）
- [ ] **テストファースト**: 本テストを先に書き、`QrCodeImage.svelte` が無い状態で
      赤（import 解決失敗）になることを確認してから部品を実装する

### リスク
- 性質検査に寄せたので、`encodeURIComponent` を別の等価な符号化へ差し替えても
  検査 2・3 は通る（意図どおり — 固定したいのは符号化方式ではなく**往復すること**である）。
  符号化方式そのものの選択理由は部品の docblock が持つ。

---

## 施策 6: 画面テスト（QR 表示の実挙動）と既存テストの追随

### 変更箇所
- ファイル: `tests/js/pages/SettingsSecurityTwoFactorQr.test.ts`（新規。正典の tests に対応）
- ファイル: `tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts`（既存。必要なら追随）

### 波及変更
- なし（`Security.svelte` の props / API は変えない）

### 新規テストの検査項目
1. QR 取得成功時、`two-factor-qr` が **`IMG` 要素**であること
2. その `src` が `data:image/svg+xml,` で始まること
3. `getByAltText("2 要素認証の設定用 QR コード")` で引けること（アクセシブルネームの維持）
4. サーバが返した SVG に `<script>` が含まれていても、**DOM に `<script>` 要素が生えない**こと
   （= 画面の層でも sink が閉じていることの裏取り）
5. QR 取得失敗時は従来どおり `qr-unavailable` Alert が出ること（後退が無いこと）

### 既存テストの扱い
- `SettingsSecurityTwoFactorConfirm.test.ts` は `getByTestId("two-factor-qr")` の
  **存在検査**のみで、stub は `{ svg: "<svg></svg>" }` を返す（実読で確認）。
  置換後も `testId` は img に付くので**修正不要**の見込み。
  実装時に赤くなったら、DOM 形の変更に合わせて最小限だけ直す（削除・上書きはしない）。
- `SettingsSecurity.test.ts` は QR の DOM 形に依存していない（実読で確認）。

### テスト計画
- [ ] 新規 5 項目
- [ ] 既存 2 ファイルが green
- [ ] **テストファースト**: 新規画面テストを**先に**書き、現行の `Security.svelte`
      （raw HTML 挿入のまま）に対して
      「`two-factor-qr` が `IMG` である」「`src` が `data:image/svg+xml,` で始まる」
      「`<script>` が生えない」の 3 assertion が**赤くなること**を確認してから置換に入る
      （施策 3 の実装より前）

### リスク
- jsdom は `data:` URI の画像を実際には読み込まないが、
  本テストが見るのは **DOM の形（要素種別・属性値）**なので影響しない。
- 検査 4（悪意ある SVG 文字列で `<script>` が生えない）は、置換前は
  raw HTML 挿入によって実際に `<script>` 要素が DOM に生えるため**赤になる**。
  これが本施策の後退防止テストとして最も価値のある assertion である。

---

## 施策 7: 応答ヘッダの依存を 2 構成で pin

### 変更箇所
- ファイル: `tests/Feature/Security/SecurityHeadersTest.php`（既存。テスト追加）

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- **`config/security.php` は変更しない**（`img-src` は既定・GTM overlay の
  両方に既に `data:` を含む。実読で確認）

### 現行コード
`config/security.php`（変更しない。pin の対象）:
```php
        'directives' => [
            // …
            'img-src' => "'self' data:",
            // …
        ],
        'gtm_directives' => [
            // …
            'img-src' => "'self' data: https://www.googletagmanager.com https://*.google-analytics.com https://*.googletagmanager.com",
            // …
        ],
```

### 変更後コード（`SecurityHeadersTest.php` に追加する helper + 1 テスト）

**判定は部分文字列一致で書かない**。`/img-src[^;]*\bdata:/` のような正規表現は
`https://data:443` のような**別の source の部分列**にも一致してしまい、
`data:` scheme-source が許可されていることを厳密に固定できない
（`img-src` が `data:` を失って `https://data:443` だけになった状態でも緑になる）。
CSP は構造が定義された文字列なので、`;` で directive、ASCII 空白で token へ割ってから
**完全一致**で見る。これは CSP 判定の正確性の問題である。

> AGENTS.md「静的検査 (gate) と走査器の共通規約」の形式的な対象は
> `tests/Support/` 配下の検出器と Architecture gate であり、本 Feature テストは対象外である。
> ここで完全一致を採る理由を同規約の (e) に求めない（規約の適用範囲を誇張しない）。

```php
/**
 * CSP ヘッダから 1 directive の source token 列を取り出す。
 *
 * 区切りの宣言:
 *   directive の区切り … `;`
 *   token の区切り     … ASCII 空白 (半角空白 / タブ)
 * 部分文字列一致に頼らない (`https://data:443` のような別 source の部分列を拾わないため)。
 *
 * @return list<string> source token 列 (directive 名は含まない)。directive が無ければ空配列
 */
function cspDirectiveSources(string $csp, string $directive): array
{
    foreach (explode(';', $csp) as $segment) {
        $tokens = preg_split('/[ \t]+/', trim($segment), -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === false || $tokens === []) {
            continue;
        }

        if ($tokens[0] === $directive) {
            return array_values(array_slice($tokens, 1));
        }
    }

    return [];
}

/*
 * QrCodeImage (components/atoms/QrCodeImage.svelte) は
 * サーバ生成の SVG を data URI の <img> として描く。
 * これは raw HTML 挿入構文を使わずに QR を表示するための唯一の手段であり、
 * **img-src が data: を失うと 2 要素認証の設定画面が壊れる**。
 * よって既定構成と GTM 有効構成の **両方**で data: の存在を固定する。
 * (CSP を配る仕組み自体の検査ではない。依存している 1 点だけを pin する)
 */
test('CSP の img-src は data: を許す (QrCodeImage の前提。既定 / GTM 有効の 2 構成)', function (): void {
    // 既定構成
    $csp = (string) $this->get('/')->headers->get('Content-Security-Policy');
    $sources = cspDirectiveSources($csp, 'img-src');

    // 母集団が空 = directive ごと消えた場合も落とす (fail-closed)
    expect($sources)->not->toBe([])
        ->and($sources)->toContain('data:');

    // GTM 有効構成 (production + container id の二重ゲート)
    config([
        'app.env' => 'production',
        'services.google_tag_manager.container_id' => 'GTM-TEST',
    ]);
    $gtmSources = cspDirectiveSources(
        (string) $this->get('/')->headers->get('Content-Security-Policy'),
        'img-src',
    );

    expect($gtmSources)->not->toBe([])
        ->and($gtmSources)->toContain('data:');
});
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（`function (): void` / `@return list<string>`）
- [x] null 安全（`(string)` cast で `?string` を潰す。`preg_split` の `false` も分岐で処理）
- [x] DTO を返している（該当なし。テスト内 helper）
- [x] Generics の型パラメータが正しい（`list<string>` を phpdoc で明示）

### テスト計画
- [ ] 上記 1 テスト（2 構成を 1 テストで見る。正典の「2 通りの構成の両方で固定する」に対応）
- [ ] **helper の検出力を合成入力で裏取りする 1 テスト**（本体テストとは別に置く）。
      「`img-src 'self'` に `data:` が無い」だけを見る負のコントロールでは
      **素朴な部分文字列実装でも同じく落ちる**ので、防ぎたい誤検出を区別できない。
      よって次の 4 本を helper へ直接与える:

      | 入力 | 期待 |
      |---|---|
      | `img-src 'self' data:` | source 列に `data:` を**含む** |
      | `img-src 'self' https://data:443` | source 列に `data:` を**含まない**（部分列を拾わない裏取り） |
      | `script-src 'self'; img-src 'self' data:` | 正しい directive を選び `data:` を含む |
      | `img-src<TAB>'self'<TAB>data:` | タブ区切りでも token 化できる（区切りの宣言どおり） |
- [ ] `RefreshDatabase` はグローバル適用済み。個別 `DatabaseTransactions` は使わない
- [ ] Factory 不使用（`/` への GET のみ。DB データ不要）

### リスク
- **GTM 有効構成の作り方**が `GtmCspTest` と重複する。
  → 重複ではなく**関心が違う**（`GtmCspTest` は GTM ホストの追加、本テストは
    `data:` が緩まないこと）。正典が `SecurityHeadersTest.php` を置き場所に定めており、
    「QrCodeImage が依存する 1 点」としてここに置くのが台帳との対応も取れる。
- **`config()` の変更**はテスト単位でロールバックされる（Laravel の標準挙動）。
- **helper の名前衝突**: Pest のテストファイルに定義した関数はグローバル関数になる。
  `SecurityHeadersTest.php` には既に `captureShowContext()` が同じ形で定義されており
  （実読で確認）、既存の作法に沿う。名前は `cspDirectiveSources` で衝突が無いことを
  実装時に `rg` で確認する。

---

## 施策 8: 設計規約への追記

### 変更箇所
- ファイル: `DESIGN.md`
  - `## Components` 配下に `### QrCodeImage` を追加（`Card` と `Spinner` の間 = 概ね ABC 順の位置に合わせる）
  - `## Do's and Don'ts` の **Don't** に 1 項目追加

### 波及変更
- なし（`DESIGN.md` は指紋台帳のキーに**無い**。実測で確認）

### 変更後コード
`### QrCodeImage`（新規節）:
```markdown
### QrCodeImage

実装: `components/atoms/QrCodeImage.svelte`。**サーバが生成した SVG 文字列を
data URI の `<img>` として描く**。生の HTML を DOM へ差し込む構文 (`{@html}`) を
使わずに QR を表示するための**唯一の手段**であり、lint 規則
`svelte/no-at-html-tags` (eslint.config.js) と対で 1 組である。
props は `svg: string`(必須) / `alt: string`(必須) / `testId`。
**`class` は受けない** — 寸法・装飾は呼び出し側の wrapper が持つ。
`svg` は **null 許容にしない** — 取得中・取得失敗の分岐は呼び出し側が持つ。
アクセシブルネームの正本は `alt` なので、wrapper 側に `role="img"` を重ねない。
data URI は percent encoding で作る (`btoa()` は非 ASCII の SVG で例外を投げる)。
CSP の `img-src` が `data:` を含むことに依存しており、
`tests/Feature/Security/SecurityHeadersTest.php` が 2 構成で pin している。
```

`## Do's and Don'ts` の **Don't** への追加:
```markdown
- **生の HTML を DOM へ差し込む構文 (`{@html}`) を書かない**。値の出どころが 1 か所でも
  汚れていれば script がそのまま実行される。`eslint.config.js` の
  `svelte/no-at-html-tags` が error で落とし、inline コメントでの無効化も効かない
  (`noInlineConfig`)。**許可一覧の口は無い** — 例外を設けるなら、その口を排除できない
  理由・安全境界・専用テストを含む別のセキュリティ設計としてレビューを通すこと。
  サーバ生成の SVG (2 要素認証の QR) には `QrCodeImage` atom を使う。
  実効性の裏取りは `tests/js/architecture/svelte-raw-html-gate.test.ts`。
  なお同 gate は**字面**で数えるため、`resources/js` 配下の `.svelte` では
  コメントであってもこの構文の字面を書けない (「raw HTML 挿入構文」と呼び名で書く)。
```

### PHPStan適合チェック
- 対象外（ドキュメント）

### テスト計画
- [ ] `DESIGN.md` の記述に対応する機械検査は施策 1・4 が持つ（文書側に検査は足さない）
- [ ] `pnpm test` の既存 DS 系テストに影響なし

### リスク
- **二重管理**: `DESIGN.md` に禁止の詳細を書きすぎると `eslint.config.js` のコメントと
  食い違う。→ **正本は `eslint.config.js` と gate の docblock**とし、
  `DESIGN.md` は「使う部品」と「禁止の事実 + 参照先」に留める（上記の分量で確定）。

---

## 乖離台帳の確認（Phase 3 で確定させる材料）

`docs/template-fingerprints.json` の `entries`（281 件）に**在るか**で共有ファイルかが決まる。
本設計の変更対象を全数照合した結果（実測）:

| 変更対象 | 指紋台帳のキーに在るか |
|---|---|
| `eslint.config.js` | **在る** |
| `resources/js/components/atoms/QrCodeImage.svelte` | 無い（新規） |
| `resources/js/pages/Settings/Security.svelte` | 無い |
| `tests/js/architecture/svelte-raw-html-gate.test.ts` | 無い（新規） |
| `tests/js/components/atoms/QrCodeImage.test.ts` | 無い（新規） |
| `tests/js/pages/SettingsSecurityTwoFactorQr.test.ts` | 無い（新規） |
| `tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts` | 無い |
| `tests/Feature/Security/SecurityHeadersTest.php` | 無い |
| `DESIGN.md` | 無い |
| `config/security.php` | 無い（かつ**変更しない**） |

`eslint.config.js` は既に**内容がテンプレートと不一致**（ローカル sha256
`613c74ef…` ≠ 台帳値 `6479fb2d…`）で、**D11 の対象パスとして登録済み**である。
また `tests/Support/TemplateDivergence/adoption-debt.tsv` には**含まれない**（実測）。

したがって:

- `LedgerPins::DIVERGENCE_ENTRY_COUNT` (36) / `FINGERPRINT_POPULATION_COUNT` (281) /
  `ADOPTION_DEBT_COUNT` (171) は **いずれも動かさない**。
- 本変更は正典への**接近**（追従）であり、新たな逸脱ではないため
  **新規の D エントリは起こさない**。
- ただし D11 の「揃え続ける不変条件」は `no-undef` 系の 3 点しか書いていない。
  `eslint.config.js` に別の関心事（raw HTML sink の禁止）が同居することになるので、
  **D11 の記述へ 1 行の申し送りを足すかどうか**を実装 PR のレビューで判定する
  （足す場合も対象パス・件数は変わらないため `LedgerPins` は不変）。
  本設計の既定は「**足さない**」— D11 は「同一不変条件・別実装」の登録であり、
  同じファイルに正典由来の規則が 1 本増えることは D11 の主張を変えないため。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 施策 1（lint 規則を error）と施策 3（唯一の違反の除去）は**分割すると `pnpm lint` が赤くなる**ため、同一ブランチで一体に入れる必要がある。施策 4 の gate も施策 1・3 の両方に依存する。また `eslint.config.js` は lint 対象全体に効く共有設定であり、他の実装が同時に走ると `pnpm lint` の赤で相互に足を引っ張る。1 本の worktree で通しで入れて main へマージするのが安全である。 |
| 競合リスク | `eslint.config.js` を触る他 TODO があれば競合する（現状 `docs/TODO.md` に該当なしを実装前に確認する）。`resources/js/pages/Settings/Security.svelte` は 2FA / passkey 系の TODO と競合しうるが、変更は QR 描画の 1 ブロックに閉じている。 |

### 実装順（テストファースト。**すべてのテストは対象の実装より先に書き、赤を確認する**）

| 段 | やること | このとき赤くなるべきもの |
|---|---|---|
| 1 | 施策 4 の gate を書く（A / B / B' / C / C' / D / E / F / F' すべて） | 下の「段 1 の詳細」を参照 |
| 2 | 施策 5 の部品テストを書く | import 解決に失敗して赤（部品が無い） |
| 3 | 施策 2（`QrCodeImage.svelte`）を実装 | → 施策 5 が green |
| 4 | 施策 6 の新規画面テストを**現行の `Security.svelte` に対して**書く | `two-factor-qr` が `IMG` でない / `src` が `data:` でない / 悪意ある SVG で `<script>` が生える、の 3 点が赤 |
| 5 | 施策 3（`Security.svelte` の置換）を実装 | → 施策 6 が green、gate の **C** が green |
| 6 | 施策 7 の CSP テスト（本体 + helper の合成入力 4 本）を書く | helper 未実装で赤 → helper 実装で green |
| 7 | 施策 1（`eslint.config.js`）を実装 | → gate の **A・B** が green、`pnpm lint` が green |
| 8 | 施策 8（`DESIGN.md`） | — |
| 9 | 全検証コマンドを通す | — |

#### 段 1 の詳細（走査器のテストファースト）

`containsRawHtmlSink()` を **stub（`return false`）のまま** gate 一式を書き、
次の順に赤を確認してから実装する:

1. **C' の違反入力側が赤くなる**ことを確認する（stub は何も検出しないため）。
   ここが段 1 の「先に赤くする」の本体である。
2. `containsRawHtmlSink()` を実装 → **C' が green** になる。
3. その状態で統合検査 **C が現行 `Security.svelte` の 1 件を検出して赤**になることを確認する
   （施策 3 はまだ未実施）。
4. **注意**: stub（`return false`）に戻すと **C は green になってしまう**
   （違反を見逃すため）。**だから C' が恒久的な検出力を担う** —
   実ファイルが 0 件になった後は、C だけでは検出器の生存を確かめられない。

同様に **F' も stub 先行**で書く（`assertLintExecutionUsable()` が常に空配列を返す状態で
負例 3 本が赤くなることを確認してから実装する）。

**A と B** は施策 1（`eslint.config.js`）が未実施なので**最初から赤**である（段 7 で green）。
**D** は正例なので最初から green でよい。

段 7 を最後に置くのは、施策 1 を先に入れると段 2〜6 の間ずっと
`pnpm lint` が赤いままになり、他の赤と区別が付かなくなるためである
（施策 1 と施策 3 は**同一コミット**でなくてもよいが、**同一 PR / 同一ブランチ**で入れる）。

全検証コマンド: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
`pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
`pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`

---

## スコープ外（明記）

- **`{@html}` 以外の raw HTML sink**（`innerHTML` 直代入 / `svelte:element` の動的タグ /
  `document.write` 等）。正典 t1 の対象語彙を勝手に増やさない。
- **lint / 型 / 整形設定の基礎そのもの**（`eslint-svelte-ts-baseline` の範囲）。
- **CSP を配る仕組み**（`SecurityHeaders` middleware / directive の組み立て）。
  本設計は `img-src` に `data:` が居ることを pin するだけで、`config/security.php` は変更しない。
- **サーバ側の QR 生成**（Fortify の `/user/two-factor-qr-code`）。応答形も変えない。
- **許可一覧 / exemption inventory の新設**。正典が「口を持たない」と明記しており、
  口を作ること自体が逸脱になる。
- **`resources/js` の外の `.svelte`**（現状 0 件。走査根は lint 対象と一致させる）。
- **`{@html}` 禁止の他リポジトリへの展開**（家系の台帳側の話）。
- **アクセシビリティの全面見直し**。変えるのは QR 1 箇所のアクセシブルネームの正本だけ。

## 実装差分 (git diff HEAD)

```diff
diff --git a/DESIGN.md b/DESIGN.md
index 12b6cd85..65c0ec8a 100644
--- a/DESIGN.md
+++ b/DESIGN.md
@@ -253,6 +253,20 @@ ### Card
 表現する — §Elevation & Depth)。padding: `none`(table/list 等を内包し内側で個別に
 padding を制御する箱用)/ `sm` / `md`(既定)/ `lg`。
 
+### QrCodeImage
+
+実装: `components/atoms/QrCodeImage.svelte`。**サーバが生成した SVG 文字列を
+data URI の `<img>` として描く**。生の HTML を DOM へ差し込む構文 (`{@html}`) を
+使わずに QR を表示するための**唯一の手段**であり、lint 規則
+`svelte/no-at-html-tags` (`eslint.config.js`) と対で 1 組である。
+props は `svg: string`(必須)/ `alt: string`(必須)/ `testId`。
+**`class` は受けない** — 寸法・装飾は呼び出し側の wrapper が持つ。
+`svg` は **null 許容にしない** — 取得中・取得失敗の分岐は呼び出し側が持つ。
+アクセシブルネームの正本は `alt` なので、wrapper 側に `role="img"` を重ねない。
+data URI は percent encoding で作る(`btoa()` は非 ASCII の SVG で例外を投げる)。
+CSP の `img-src` が `data:` を含むことに依存しており、
+`tests/Feature/Security/SecurityHeadersTest.php` が 2 構成で pin している。
+
 ### Spinner
 
 実装: `components/atoms/Spinner.svelte`。LoaderCircle(@lucide/svelte)+ `animate-spin`。
@@ -520,6 +534,15 @@ ## Do's and Don'ts
   (認証・権限・ゲートで確実に弾かれる先を指すもの)は**出さずに、なぜ今は進めないかを
   文章で説明する**。disabled 化でも代替しない(上の Don't と同根。例: メール未認証画面から
   `verified` ゲート内の checkout へ進む CTA)
+- **生の HTML を DOM へ差し込む構文 (`{@html}`) を書かない**。値の出どころが 1 か所でも
+  汚れていれば script がそのまま実行される。`eslint.config.js` の
+  `svelte/no-at-html-tags` が error で落とし、inline コメントでの無効化も効かない
+  (`noInlineConfig`)。**許可一覧の口は無い** — 例外を設けるなら、その口を排除できない
+  理由・安全境界・専用テストを含む別のセキュリティ設計としてレビューを通すこと。
+  サーバ生成の SVG (2 要素認証の QR) には `QrCodeImage` atom を使う。
+  実効性の裏取りは `tests/js/architecture/svelte-raw-html-gate.test.ts`。
+  なお同 gate は**字面**で数えるため、`resources/js` 配下の `.svelte` では
+  コメントであってもこの構文の字面を書けない(「raw HTML 挿入構文」と呼び名で書く)
 - ページ内で素の `<input>` / `<table>` / リンク風 `<a>` 手書きをしない(対応する atom/molecule を使う)
 - **native の constraint validation に検証を任せない**。`<form>` には `novalidate` を付け、
   検証文言はサーバ(日本語)と押下時の client エラーに一本化する。
diff --git a/eslint.config.js b/eslint.config.js
index 6ca74873..590f83b1 100644
--- a/eslint.config.js
+++ b/eslint.config.js
@@ -108,6 +108,25 @@ export default [
             // 未定義識別子を捕まえる機構がここにしか無いので error 固定
             // (spirux:T1054 = SSO 接続追加画面のクラッシュと同型の事故を止める)。
             "no-undef": "error",
+            /*
+             * 生の HTML を DOM へ差し込む構文 ({@html}) の全面禁止。
+             *
+             * 値の出どころが 1 か所でも汚れていれば script がそのまま実行される。
+             * 撮影 PWA は同一オリジン・セッション認証なので、XSS の成立は
+             * 撮影導線の資格情報にそのまま届く。
+             *
+             * **許可一覧 (allowlist / exemption inventory) の口は持たない**。
+             * 例外を設けるなら、その口を排除できない理由・安全境界・専用テストを含む
+             * **別のセキュリティ設計**としてレビューを通すこと
+             * (file-scoped override をここに書き足して済ませない)。
+             *
+             * サーバ生成の SVG (2 要素認証の QR) を描く用途には
+             * components/atoms/QrCodeImage.svelte を使う (data URI の <img>)。
+             *
+             * 実効性の裏取りは tests/js/architecture/svelte-raw-html-gate.test.ts
+             * (実際に lint を走らせ、無効化コメントが効かないことまで固定する)。
+             */
+            "svelte/no-at-html-tags": "error",
             "svelte/require-each-key": "error",
             "svelte/prefer-svelte-reactivity": "error",
             "svelte/prefer-writable-derived": "error",
diff --git a/resources/js/components/atoms/QrCodeImage.svelte b/resources/js/components/atoms/QrCodeImage.svelte
new file mode 100644
index 00000000..4a73f1c1
--- /dev/null
+++ b/resources/js/components/atoms/QrCodeImage.svelte
@@ -0,0 +1,42 @@
+<script lang="ts">
+    /**
+     * QrCodeImage atom。**サーバが生成した SVG 文字列を data URI の <img> として描く**。
+     *
+     * 存在理由: raw HTML 挿入構文 (生 HTML を DOM へ差し込む構文) を使わずに
+     * サーバ生成の QR を表示するための唯一の手段を配る。
+     * 禁止構文は文字列を DOM 木として解釈させるが、本部品は画像リソースとして読ませる。
+     * lint 規則 svelte/no-at-html-tags と対で 1 組である
+     * (禁止だけを配ると現場は使い続けるため、代わりの手段を同時に配る)。
+     *
+     * ※ 本ファイルは resources/js 配下の .svelte なので、禁止構文の**字面**は書けない
+     *   (tests/js/architecture/svelte-raw-html-gate.test.ts の検査 C が字面で数えるため)。
+     *   正本の説明は eslint.config.js のコメントと DESIGN.md にある。
+     *
+     * **保証範囲 (誇張しない)**: 本部品が保証するのは
+     * 「SVG 文字列を DOM へ HTML として挿さないこと」までである。
+     * browser が画像文脈の SVG をどう扱うかの細部は本部品の保証範囲ではない。
+     *
+     * data URI は **percent encoding** で作る (base64 を採らない):
+     *   - btoa() は非 ASCII を含む SVG で例外を投げる
+     *   - TextEncoder 経由の base64 化は安全性が同じで手数だけ増える
+     *   - 素朴な文字列連結は `#` (fragment 開始) で切れ、`%` が不正な escape になり、
+     *     非 ASCII で壊れる
+     *
+     * CSP の img-src が `data:` を含むことに依存する
+     * (tests/Feature/Security/SecurityHeadersTest.php が 2 構成で pin している)。
+     */
+
+    interface Props {
+        /** サーバが生成した SVG 文字列。**null 許容にしない** (呼び出し側が分岐を持つ) */
+        svg: string;
+        /** 画像の代替テキスト。必須 (アクセシブルネームの正本) */
+        alt: string;
+        testId?: string;
+    }
+
+    let { svg, alt, testId }: Props = $props();
+
+    const src = $derived(`data:image/svg+xml,${encodeURIComponent(svg)}`);
+</script>
+
+<img {src} {alt} data-testid={testId} />
diff --git a/resources/js/pages/Settings/Security.svelte b/resources/js/pages/Settings/Security.svelte
index 48174495..5c1356ff 100644
--- a/resources/js/pages/Settings/Security.svelte
+++ b/resources/js/pages/Settings/Security.svelte
@@ -6,6 +6,7 @@
     import Button from "@/components/atoms/Button.svelte";
     import Card from "@/components/atoms/Card.svelte";
     import Input from "@/components/atoms/Input.svelte";
+    import QrCodeImage from "@/components/atoms/QrCodeImage.svelte";
     import TextLink from "@/components/atoms/TextLink.svelte";
     import CodeSnippet from "@/components/molecules/CodeSnippet.svelte";
     import FormField from "@/components/molecules/FormField.svelte";
@@ -629,15 +630,17 @@
                             </Alert>
                         {:else}
                             {#if qrSvg}
-                                <!-- QR はサーバ提供の SVG をそのまま描画する。svg 文字列に属性を注入せず、
-                                     wrapper を role="img" にしてアクセシブルネームを与える (H14) -->
-                                <div
-                                    role="img"
-                                    aria-label="2 要素認証の設定用 QR コード"
-                                    class="self-start rounded-md border border-border bg-surface p-4"
-                                    data-testid="two-factor-qr"
-                                >
-                                    {@html qrSvg}
+                                <!-- QR はサーバ生成の SVG を **data URI の <img>** として描く。
+                                     生の HTML を DOM へ差し込む構文は使わない
+                                     (禁止の正本は eslint.config.js の svelte/no-at-html-tags)。
+                                     アクセシブルネームは img の alt が正本なので、
+                                     wrapper の role="img" / aria-label は持たせない (二重命名を避ける)。 -->
+                                <div class="self-start rounded-md border border-border bg-surface p-4">
+                                    <QrCodeImage
+                                        svg={qrSvg}
+                                        alt="2 要素認証の設定用 QR コード"
+                                        testId="two-factor-qr"
+                                    />
                                 </div>
                             {:else}
                                 <Alert type="warning" testId="qr-unavailable">
diff --git a/tests/Feature/Security/SecurityHeadersTest.php b/tests/Feature/Security/SecurityHeadersTest.php
index 9eaf9acd..c9ce1946 100644
--- a/tests/Feature/Security/SecurityHeadersTest.php
+++ b/tests/Feature/Security/SecurityHeadersTest.php
@@ -201,3 +201,92 @@ function captureShowContext(): array
             'geolocation=(), microphone=(), camera=(), payment=(self "https://js.stripe.com")',
         );
 });
+
+/**
+ * CSP ヘッダから 1 directive の source token 列を取り出す。
+ *
+ * 区切りの宣言:
+ *   directive の区切り … `;`
+ *   token の区切り     … ASCII 空白 (半角空白 / タブ)
+ *
+ * 部分文字列一致に頼らない。`/img-src[^;]*\bdata:/` のような正規表現は
+ * `https://data:443` のような**別の source の部分列**にも一致するため、
+ * `img-src` が `data:` scheme-source を失っても緑になってしまう。
+ *
+ * @return list<string> source token 列 (directive 名は含まない)。directive が無ければ空配列
+ */
+function cspDirectiveSources(string $csp, string $directive): array
+{
+    foreach (explode(';', $csp) as $segment) {
+        $tokens = preg_split('/[ \t]+/', trim($segment), -1, PREG_SPLIT_NO_EMPTY);
+
+        if ($tokens === false || $tokens === []) {
+            continue;
+        }
+
+        if ($tokens[0] === $directive) {
+            return array_values(array_slice($tokens, 1));
+        }
+    }
+
+    return [];
+}
+
+/*
+ * QrCodeImage (components/atoms/QrCodeImage.svelte) は
+ * サーバ生成の SVG を data URI の <img> として描く。
+ * これは raw HTML 挿入構文 ({@html}) を使わずに QR を表示するための唯一の手段であり、
+ * **img-src が data: を失うと 2 要素認証の設定画面が壊れる**。
+ * よって既定構成と GTM 有効構成の **両方**で data: の存在を固定する。
+ * (CSP を配る仕組み自体の検査ではない。依存している 1 点だけを pin する)
+ */
+test('CSP の img-src は data: を許す (QrCodeImage の前提。既定 / GTM 有効の 2 構成)', function (): void {
+    // 既定構成
+    $sources = cspDirectiveSources(
+        (string) $this->get('/')->headers->get('Content-Security-Policy'),
+        'img-src',
+    );
+
+    // 母集団が空 = directive ごと消えた場合も落とす (fail-closed)
+    expect($sources)->not->toBe([])
+        ->and($sources)->toContain('data:');
+
+    // GTM 有効構成 (production + container id の二重ゲート)
+    config([
+        'app.env' => 'production',
+        'services.google_tag_manager.container_id' => 'GTM-TEST',
+    ]);
+    $gtmSources = cspDirectiveSources(
+        (string) $this->get('/')->headers->get('Content-Security-Policy'),
+        'img-src',
+    );
+
+    expect($gtmSources)->not->toBe([])
+        ->and($gtmSources)->toContain('data:');
+});
+
+/*
+ * helper の検出力を合成入力で裏取りする。
+ * 「img-src 'self' に data: が無い」だけを見る負のコントロールでは
+ * **素朴な部分文字列実装でも同じく落ちる**ので、防ぎたい誤検出を区別できない。
+ */
+test('cspDirectiveSources() は directive を選び分け、別 source の部分列を拾わない', function (): void {
+    expect(cspDirectiveSources("img-src 'self' data:", 'img-src'))
+        ->toBe(["'self'", 'data:']);
+
+    // 部分列を拾わない裏取り (素朴な部分文字列一致ならここで data: を「在る」と誤答する)
+    expect(cspDirectiveSources("img-src 'self' https://data:443", 'img-src'))
+        ->toBe(["'self'", 'https://data:443'])
+        ->not->toContain('data:');
+
+    // 正しい directive を選ぶ
+    expect(cspDirectiveSources("script-src 'self'; img-src 'self' data:", 'img-src'))
+        ->toBe(["'self'", 'data:']);
+
+    // 区切りの宣言どおりタブでも token 化できる
+    expect(cspDirectiveSources("img-src\t'self'\tdata:", 'img-src'))
+        ->toBe(["'self'", 'data:']);
+
+    // 存在しない directive は空配列 (呼び出し側の fail-closed 判定に使う)
+    expect(cspDirectiveSources("img-src 'self' data:", 'font-src'))->toBe([]);
+});
diff --git a/tests/js/architecture/svelte-raw-html-gate.test.ts b/tests/js/architecture/svelte-raw-html-gate.test.ts
new file mode 100644
index 00000000..0d131b96
--- /dev/null
+++ b/tests/js/architecture/svelte-raw-html-gate.test.ts
@@ -0,0 +1,395 @@
+import { describe, it, expect } from "vitest";
+import fs from "node:fs/promises";
+import path from "node:path";
+import { fileURLToPath } from "node:url";
+import { ESLint } from "eslint";
+
+/*
+ * svelte-raw-html-gate — 生の HTML を DOM へ差し込む構文 ({@html}) の全面禁止が
+ * **実効である**ことを、config と振る舞いの両方から固定する。
+ *
+ * 背景: この構文は文字列を DOM 木として解釈させるので、値の出どころが 1 か所でも
+ * 汚れていれば script がそのまま実行される。撮影 PWA は同一オリジン・セッション認証なので、
+ * XSS の成立は撮影導線の資格情報にそのまま届く。
+ *
+ * 検査する不変条件:
+ *   A. [config・全数] C が収集した resources/js 配下の .svelte **全件**について
+ *      calculateConfigForFile() の実効 severity が error である。
+ *      代表 1 件では、特定ファイル向け override で規則を off にされたときに見逃す。
+ *   B. [振る舞い] 禁止構文を含む合成入力を実際に lint すると error になる。
+ *      **無効化コメント 3 形式を付けても error のまま**である (下の DISABLE_FORMS)。
+ *   B'. [負例の裏取り] 同じ 3 形式が noInlineConfig:false の**対照条件**では
+ *      実際に error を消せる。これが無いと「元から解釈されていない文字列」を
+ *      負例と称して緑になる (検出力の空振り)。
+ *   B''. [形式選定の根拠] HTML コメント形式の無効化指示は、この lint 構成では
+ *      **対照条件でも効かない** (eslint-plugin-svelte の comment-directive を
+ *      有効化していないため)。よって B/B' の負例には使えない。この事実を固定しておくと、
+ *      将来 comment-directive を有効化したときに B'' が赤くなり、
+ *      「その形式を B/B' の負例へ移せ」という信号になる。
+ *   C. [実ファイル] resources/js 配下の .svelte 全数に禁止構文が 0 件。
+ *      判定は純関数 containsRawHtmlSink() が行う。
+ *   C'. [C の検出力] containsRawHtmlSink() を合成入力で恒久的に裏取りする。
+ *      実ファイルが 0 件になった後も検出器が生きていることを保証する
+ *      (実ファイル 0 件の状態では C だけでは検出器の生存を確かめられない)。
+ *   D. [正例・lint] 禁止構文を含まない規定どおりの入力を ESLint が誤検出しない。
+ *   E. [fail-closed] 走査根 resources/js が解決できない / 母集団が 0 件 /
+ *      config 解決に失敗した場合は**落とす**。
+ *   F. [fail-closed・lint] すべての lintText 結果について
+ *      「lint が実際に走って結果が使える」ことを、**対象 rule の件数を見る前に**確認する
+ *      (fatalErrorCount === 0 / fatal な message が無い / その filePath が ignored でない)。
+ *      ESLint は構文解析エラーを throw せず fatal message として返すため、
+ *      「対象 rule が 0 件」だけを見ると解析失敗も ignored も正常扱いしてしまう (fail-open)。
+ *   F'. [F の検出力] 判定は純関数 assertLintExecutionUsable() が行い、
+ *      合成入力で正負を恒久的に裏取りする (B/B'/D はすべて正常に parse される入力なので、
+ *      F の検査を壊しても実入力では気付けない)。
+ *
+ * **許可一覧 (allowlist / exemption inventory) の口は持たない** (正典が明記する方針)。
+ * 例外を設けるなら、その口を排除できない理由・安全境界・専用テストを含む
+ * 別のセキュリティ設計としてレビューを通すこと。
+ *
+ * 走査対象: resources/js 配下の `.svelte` 全数 (git 追跡かどうかは見ない)。
+ * 検出の区切り: 文字列 `{@html` の出現。
+ *   **コメント内・文字列リテラル内も違反として数える** — 構文解析器を持たない字面走査であり、
+ *   目標値が 0 件なので拾いすぎる方向へ倒すのは AGENTS.md (b) の許す側である。
+ *   帰結として **resources/js 配下の .svelte では説明のためであっても禁止構文の字面を書けない**
+ *   (コメントでは「raw HTML 挿入構文」と呼び名で書く)。
+ *   字面を書いてよいのは走査対象の外 — eslint.config.js / DESIGN.md / 本 gate 自身である
+ *   (本 gate は負例入力として字面が**必要**なので、自分自身を走査根に含めない)。
+ *
+ * 保証しないもの (誇張しない):
+ *   - 禁止構文**以外**の raw HTML sink (innerHTML 直代入 / svelte:element の動的タグ /
+ *     document.write 等) には**無言で効かない**。
+ *   - resources/js の外の .svelte は走査しない (lint 対象と一致させている)。
+ *   - browser が画像文脈の SVG をどう扱うかは本 gate の対象ではない。
+ */
+
+const HERE = path.dirname(fileURLToPath(import.meta.url));
+const REPO_ROOT = path.resolve(HERE, "../../../");
+const RESOURCES_JS = path.join(REPO_ROOT, "resources/js");
+
+/** 検査対象の lint 規則 (禁止の正本は eslint.config.js)。 */
+const RULE = "svelte/no-at-html-tags";
+
+/**
+ * 合成入力に使う仮想パス。**実在させない** —
+ * resources/js 配下に fixture ファイルを置くと検査 C の母集団に混ざり、
+ * 「実ファイル 0 件」の意味が壊れるため (lintText の filePath は仮想でよい)。
+ */
+const VIRTUAL_SVELTE = path.join(RESOURCES_JS, "__svelte-raw-html-gate-virtual__.svelte");
+
+/** 禁止構文を 1 件含む合成 .svelte 本文を組み立てる (script 先頭へ prelude を差し込む)。 */
+function violatingSource(scriptPrelude = ""): string {
+    return [
+        `<script lang="ts">`,
+        scriptPrelude,
+        `    const value = "<b>x</b>";`,
+        `</script>`,
+        ``,
+        `<div>{@html value}</div>`,
+        ``,
+    ].join("\n");
+}
+
+/**
+ * B / B' で使う無効化コメント 3 形式。
+ *
+ * **script ブロック内の JS コメント**を採る。HTML コメント形式
+ * (`<!-- eslint-disable ... -->`) はこの lint 構成では対照条件でも解釈されず
+ * (comment-directive 未有効)、負例として無効だからである (B'' が実測で固定する)。
+ */
+const DISABLE_FORMS: readonly { readonly name: string; readonly source: string }[] = [
+    { name: "全規則の無効化 (/* eslint-disable */)", source: violatingSource(`    /* eslint-disable */`) },
+    {
+        name: `規則名指しの無効化 (/* eslint-disable ${RULE} */)`,
+        source: violatingSource(`    /* eslint-disable ${RULE} */`),
+    },
+    {
+        name: `inline の severity 上書き (/* eslint ${RULE}: "off" */)`,
+        source: violatingSource(`    /* eslint ${RULE}: "off" */`),
+    },
+] as const;
+
+/** B'' — この lint 構成では対照条件でも効かない (= 負例に使えない) 形式。 */
+const INERT_HTML_COMMENT_FORMS: readonly { readonly name: string; readonly source: string }[] = [
+    {
+        name: "HTML コメントの全規則無効化",
+        source: `<!-- eslint-disable -->\n${violatingSource()}`,
+    },
+    {
+        name: "HTML コメントの規則名指し無効化",
+        source: `<!-- eslint-disable ${RULE} -->\n${violatingSource()}`,
+    },
+    {
+        name: "HTML コメントの次行無効化",
+        source: [
+            `<script lang="ts">`,
+            `    const value = "<b>x</b>";`,
+            `</script>`,
+            ``,
+            `<!-- eslint-disable-next-line ${RULE} -->`,
+            `<div>{@html value}</div>`,
+            ``,
+        ].join("\n"),
+    },
+] as const;
+
+/** 本文に raw HTML 挿入構文の字面が含まれるか (検査 C の判定の正本。C' が裏取りする)。 */
+export function containsRawHtmlSink(source: string): boolean {
+    return source.includes("{@html");
+}
+
+/** assertLintExecutionUsable() が受け取る lint 結果の最小 view。 */
+interface LintExecutionView {
+    readonly fatalErrorCount: number;
+    readonly messages: readonly { readonly fatal?: boolean }[];
+}
+
+/**
+ * lint 結果が「判定に使える」か (検査 F の判定の正本。F' が裏取りする)。
+ * 対象 rule の件数を数える**前に**通す。違反理由を返す (空配列 = 使える)。
+ */
+export function assertLintExecutionUsable(
+    result: LintExecutionView,
+    isIgnored: boolean,
+): string[] {
+    const problems: string[] = [];
+
+    if (result.fatalErrorCount !== 0) {
+        problems.push(`fatalErrorCount が ${result.fatalErrorCount} (構文解析に失敗している)`);
+    }
+    if (result.messages.some((message) => message.fatal === true)) {
+        problems.push("fatal な message がある (構文解析に失敗している)");
+    }
+    if (isIgnored) {
+        problems.push("対象パスが ignored (lint されていないので rule 件数 0 は無意味)");
+    }
+
+    return problems;
+}
+
+/** 走査根から .svelte を再帰収集する。根が解決できなければ落とす ([E] fail-closed)。 */
+async function svelteFiles(root: string): Promise<string[]> {
+    const stats = await fs.stat(root).catch((cause: unknown) => {
+        throw new Error(`走査根を解決できない: ${path.relative(REPO_ROOT, root)}`, { cause });
+    });
+    if (!stats.isDirectory()) {
+        throw new Error(`走査根がディレクトリでない: ${path.relative(REPO_ROOT, root)}`);
+    }
+
+    const out: string[] = [];
+    for (const entry of await fs.readdir(root, { recursive: true, withFileTypes: true })) {
+        if (entry.isFile() && entry.name.endsWith(".svelte")) {
+            out.push(path.join(entry.parentPath, entry.name));
+        }
+    }
+
+    return out.sort(); // 失敗メッセージを走査順の環境差で揺らさない
+}
+
+/** 母集団 (= A と C が**同じものを**判定に使う。AGENTS.md (d))。 */
+async function population(): Promise<string[]> {
+    const files = await svelteFiles(RESOURCES_JS);
+    expect(files.length, "resources/js 配下に .svelte が 1 件も無い (走査が空振りしている)").toBeGreaterThan(0);
+
+    return files;
+}
+
+/**
+ * 合成入力を 1 本 lint する。結果は **F を通してから**返す
+ * (rule 件数を数える前に「lint が実際に走った」ことを確かめる)。
+ */
+async function lintVirtual(eslint: ESLint, source: string): Promise<ESLint.LintResult> {
+    const results = await eslint.lintText(source, { filePath: VIRTUAL_SVELTE });
+    const result = results[0];
+    if (result === undefined) {
+        throw new Error("lintText が結果を返さなかった");
+    }
+
+    const problems = assertLintExecutionUsable(result, await eslint.isPathIgnored(VIRTUAL_SVELTE));
+    expect(problems, `[F] lint 結果が判定に使えない: ${problems.join(" / ")}`).toEqual([]);
+
+    return result;
+}
+
+/** lint 結果のうち検査対象 rule の error 件数。 */
+function ruleErrorCount(result: ESLint.LintResult): number {
+    return result.messages.filter((message) => message.ruleId === RULE && message.severity === 2)
+        .length;
+}
+
+describe("architecture/svelte-raw-html-gate", () => {
+    it(`[A][E] resources/js 配下の全 .svelte で ${RULE} が error`, async () => {
+        const files = await population();
+        const eslint = new ESLint({ cwd: REPO_ROOT });
+
+        const offenders: string[] = [];
+        for (const file of files) {
+            const resolved: unknown = await eslint.calculateConfigForFile(file);
+            if (typeof resolved !== "object" || resolved === null) {
+                // [E] 解決できない形は落とす (無言で候補から外さない)
+                throw new Error(`実効設定を解決できなかった: ${path.relative(REPO_ROOT, file)}`);
+            }
+
+            const rules = (resolved as { rules?: Record<string, unknown> }).rules;
+            const entry = rules?.[RULE];
+            const severity = Array.isArray(entry) ? entry[0] : entry;
+            if (severity !== 2 && severity !== "error") {
+                offenders.push(
+                    `${path.relative(REPO_ROOT, file)}: 実効 severity が error でない (${JSON.stringify(entry)})`,
+                );
+            }
+        }
+
+        expect(
+            offenders,
+            `生の HTML を DOM へ差し込む構文の禁止が無効化されている。eslint.config.js を確認すること ` +
+                `(許可一覧の口は持たない方針である):\n${offenders.join("\n")}`,
+        ).toEqual([]);
+    });
+
+    it("[B][F] 禁止構文は無効化コメント 3 形式を付けても error のまま", async () => {
+        const eslint = new ESLint({ cwd: REPO_ROOT });
+
+        const bare = await lintVirtual(eslint, violatingSource());
+        expect(ruleErrorCount(bare), "素の違反入力が error にならない").toBeGreaterThan(0);
+
+        for (const form of DISABLE_FORMS) {
+            const result = await lintVirtual(eslint, form.source);
+            expect(
+                ruleErrorCount(result),
+                `無効化コメントで error が消えた: ${form.name} ` +
+                    `(eslint.config.js の linterOptions.noInlineConfig を確認すること)`,
+            ).toBeGreaterThan(0);
+        }
+    });
+
+    it("[B'][F] 対照条件 (noInlineConfig:false) では同じ 3 形式が実際に error を消す", async () => {
+        const eslint = new ESLint({
+            cwd: REPO_ROOT,
+            overrideConfig: { linterOptions: { noInlineConfig: false } },
+        });
+
+        // 対照条件でも素の違反は error である (対照条件そのものが壊れていないことの確認)
+        const bare = await lintVirtual(eslint, violatingSource());
+        expect(ruleErrorCount(bare), "対照条件で素の違反が error にならない").toBeGreaterThan(0);
+
+        for (const form of DISABLE_FORMS) {
+            const result = await lintVirtual(eslint, form.source);
+            expect(
+                ruleErrorCount(result),
+                `対照条件でも error が消えない = この形式は負例として無効である: ${form.name} ` +
+                    `(B の「無効化できない」が空振りしていないか、形式を選び直すこと)`,
+            ).toBe(0);
+        }
+    });
+
+    it("[B''][F] HTML コメント形式の無効化指示は対照条件でも効かない (負例に使えない根拠)", async () => {
+        const eslint = new ESLint({
+            cwd: REPO_ROOT,
+            overrideConfig: { linterOptions: { noInlineConfig: false } },
+        });
+
+        for (const form of INERT_HTML_COMMENT_FORMS) {
+            const result = await lintVirtual(eslint, form.source);
+            expect(
+                ruleErrorCount(result),
+                `対照条件で HTML コメント形式が効くようになった: ${form.name}。` +
+                    `eslint-plugin-svelte の comment-directive を有効化したなら、` +
+                    `この形式を DISABLE_FORMS へ移して B/B' の負例に加えること`,
+            ).toBeGreaterThan(0);
+        }
+    });
+
+    it("[C][E] resources/js 配下の .svelte に禁止構文が 0 件", async () => {
+        const files = await population();
+
+        const offenders: string[] = [];
+        for (const file of files) {
+            if (containsRawHtmlSink(await fs.readFile(file, "utf8"))) {
+                offenders.push(path.relative(REPO_ROOT, file));
+            }
+        }
+
+        expect(
+            offenders,
+            `生の HTML を DOM へ差し込む構文が書かれている。サーバ生成の SVG を描くなら ` +
+                `components/atoms/QrCodeImage.svelte を使うこと。` +
+                `なお本 gate は**字面**で数えるので、説明のためのコメントにも書けない:\n` +
+                offenders.join("\n"),
+        ).toEqual([]);
+    });
+
+    it("[C'] containsRawHtmlSink() の検出力 (実ファイルが 0 件になった後の生存保証)", () => {
+        // 検出契約は「部分文字列 `{@html` を含めば違反」である。
+        // したがって禁止文字列を**内包する**形はすべて true 側であり、正例ではない。
+        const violating = [
+            "<div>{@html value}</div>",
+            "<!-- 説明のために {@html} と書いた -->",
+            `<script lang="ts">\n    const s = "{@html}";\n</script>`,
+            "<div>{@htmlish value}</div>", // 接尾辞つき (禁止文字列を内包する)
+            "<div>x{@html value}</div>", // 接頭辞つき
+        ];
+        for (const source of violating) {
+            expect(containsRawHtmlSink(source), `違反を見逃した: ${source}`).toBe(true);
+        }
+
+        const clean = [
+            "<div>{name}</div>",
+            "{@const x = 1}",
+            "{@render children()}",
+            "{#if cond}<span>y</span>{/if}",
+            "<div>{@htm value}</div>", // 禁止文字列を内包しない近い綴り
+            "<div>{ @html value}</div>", // 区切りが違う (字面一致しない)
+        ];
+        for (const source of clean) {
+            expect(containsRawHtmlSink(source), `誤検出した: ${source}`).toBe(false);
+        }
+    });
+
+    it("[D][F] 禁止構文を含まない規定どおりの入力を誤検出しない", async () => {
+        const eslint = new ESLint({ cwd: REPO_ROOT });
+        const source = [
+            `<script lang="ts">`,
+            `    const items = [{ id: 1, label: "a" }];`,
+            `    const cond = true;`,
+            `</script>`,
+            ``,
+            `{#if cond}`,
+            `    {#each items as item (item.id)}`,
+            `        {@const label = item.label}`,
+            `        <span>{label}</span>`,
+            `    {/each}`,
+            `{/if}`,
+            ``,
+        ].join("\n");
+
+        const result = await lintVirtual(eslint, source);
+        expect(ruleErrorCount(result), "正例を違反と判定した").toBe(0);
+    });
+
+    it("[E] 走査根が解決できなければ落とす", async () => {
+        await expect(svelteFiles(path.join(RESOURCES_JS, "__does-not-exist__"))).rejects.toThrow(
+            /走査根を解決できない/,
+        );
+    });
+
+    it("[F'] assertLintExecutionUsable() の検出力", () => {
+        expect(
+            assertLintExecutionUsable({ fatalErrorCount: 0, messages: [{}] }, false),
+            "正のコントロール",
+        ).toEqual([]);
+
+        expect(
+            assertLintExecutionUsable({ fatalErrorCount: 1, messages: [] }, false),
+            "fatalErrorCount > 0",
+        ).toHaveLength(1);
+        expect(
+            assertLintExecutionUsable({ fatalErrorCount: 0, messages: [{ fatal: true }] }, false),
+            "fatal な message",
+        ).toHaveLength(1);
+        expect(
+            assertLintExecutionUsable({ fatalErrorCount: 0, messages: [] }, true),
+            "ignored なパス",
+        ).toHaveLength(1);
+    });
+});
diff --git a/tests/js/components/atoms/QrCodeImage.test.ts b/tests/js/components/atoms/QrCodeImage.test.ts
new file mode 100644
index 00000000..a6bfa038
--- /dev/null
+++ b/tests/js/components/atoms/QrCodeImage.test.ts
@@ -0,0 +1,78 @@
+import { describe, expect, it } from "vitest";
+import { render, screen } from "@testing-library/svelte";
+import QrCodeImage from "@/components/atoms/QrCodeImage.svelte";
+
+/*
+ * QrCodeImage atom — サーバ生成の SVG 文字列を data URI の <img> として描く部品。
+ *
+ * 検査は **性質** で書く。実装と同じ式 (encodeURIComponent) による完全一致テストは
+ * トートロジーなので書かない。固定したいのは符号化方式ではなく
+ * 「data URI として往復すること」と「HTML として解釈されないこと」である。
+ */
+
+const DATA_URI_PREFIX = "data:image/svg+xml,";
+
+/** data URI の payload 部を取り出して復号する (往復検査の共通手順)。 */
+function decodedPayload(src: string): string {
+    expect(src.startsWith(DATA_URI_PREFIX), `data URI の接頭辞が違う: ${src}`).toBe(true);
+
+    return decodeURIComponent(src.slice(DATA_URI_PREFIX.length));
+}
+
+/** 対象 <img> を testId で引く (存在しなければ失敗する)。 */
+function renderQr(svg: string): HTMLImageElement {
+    render(QrCodeImage, { props: { svg, alt: "QR コード", testId: "qr" } });
+    const element = screen.getByTestId("qr");
+    expect(element.tagName).toBe("IMG");
+
+    return element as HTMLImageElement;
+}
+
+describe("QrCodeImage", () => {
+    it("src が data:image/svg+xml, で始まる <img> を描く", () => {
+        const image = renderQr("<svg><rect /></svg>");
+
+        expect(image.getAttribute("src")).toMatch(/^data:image\/svg\+xml,/);
+    });
+
+    it("src の payload は入力 SVG へ往復して戻る", () => {
+        const svg = "<svg><rect width='10' height='10' /></svg>";
+        const image = renderQr(svg);
+
+        expect(decodedPayload(image.getAttribute("src") ?? "")).toBe(svg);
+    });
+
+    it("URI を壊しやすい文字 (# / % / 非 ASCII / &) を含んでも往復する", () => {
+        // # は fragment 開始、% は escape 開始、非 ASCII は btoa() が壊す文字種。
+        const svg = '<svg><text fill="#f00">100% 完了 &amp; 保存</text></svg>';
+        const image = renderQr(svg);
+
+        expect(decodedPayload(image.getAttribute("src") ?? "")).toBe(svg);
+    });
+
+    it("SVG 文字列は HTML として解釈されない (本部品の存在理由)", () => {
+        // サーバ応答が汚れていた場合を模す。HTML として差し込まれていれば
+        // <svg> / <script> が DOM に生える = 本部品の前提が崩れている。
+        const { container } = render(QrCodeImage, {
+            props: {
+                svg: '<svg><script>window.pwned = true;</script></svg>',
+                alt: "QR コード",
+                testId: "qr",
+            },
+        });
+
+        expect(container.querySelector("svg")).toBeNull();
+        expect(container.querySelector("script")).toBeNull();
+        expect(container.querySelectorAll("img")).toHaveLength(1);
+    });
+
+    it("alt と testId が渡る (アクセシブルネームの正本は alt)", () => {
+        render(QrCodeImage, {
+            props: { svg: "<svg></svg>", alt: "2 要素認証の設定用 QR コード", testId: "qr" },
+        });
+
+        const image = screen.getByAltText("2 要素認証の設定用 QR コード");
+        expect(image).toBe(screen.getByTestId("qr"));
+        expect(screen.getByRole("img", { name: "2 要素認証の設定用 QR コード" })).toBe(image);
+    });
+});
diff --git a/tests/js/pages/SettingsSecurityTwoFactorQr.test.ts b/tests/js/pages/SettingsSecurityTwoFactorQr.test.ts
new file mode 100644
index 00000000..743277ee
--- /dev/null
+++ b/tests/js/pages/SettingsSecurityTwoFactorQr.test.ts
@@ -0,0 +1,157 @@
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
+
+/*
+ * Settings/Security 2FA の QR 表示 (T251 / 家系正典 svelte-raw-html-sink-ban)。
+ *
+ * サーバ生成の SVG は **data URI の <img>** として描く。raw HTML 挿入構文
+ * (生 HTML を DOM へ差し込む構文) は使わない — 値の出どころが 1 か所でも汚れていれば
+ * script がそのまま実行され、同一オリジン・セッション認証の撮影導線に直結するため。
+ *
+ * 固定する不変条件:
+ *   1. two-factor-qr が IMG 要素である
+ *   2. その src が data:image/svg+xml, で始まる
+ *   3. アクセシブルネーム (alt) が維持されている
+ *   4. サーバ応答の SVG に script が含まれていても DOM に script 要素が生えない
+ *      (= 画面の層でも sink が閉じていることの直接の裏取り)
+ *   5. QR 取得失敗時の代替導線 (qr-unavailable) に後退が無い
+ */
+
+const { routerPostMock, pageState, addToastMock } = vi.hoisted(() => ({
+    routerPostMock: vi.fn(),
+    pageState: {
+        props: {} as Record<string, unknown>,
+        url: "/settings/security",
+    },
+    addToastMock: vi.fn(),
+}));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    router: { post: routerPostMock },
+    page: pageState,
+}));
+
+vi.mock("@/lib/stores/toast", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@/lib/stores/toast")>()),
+    addToast: addToastMock,
+}));
+
+import Security from "@/pages/Settings/Security.svelte";
+
+const fetchMock = vi.fn();
+
+/** JSON レスポンス風オブジェクト (fetch mock 用) */
+function jsonResponse(ok: boolean, status: number, body: unknown): unknown {
+    return { ok, status, json: () => Promise.resolve(body) };
+}
+
+/** enrollment 素材の fetch を stub する (QR だけ差し替え可能にする) */
+function stubFetchRoutes(qr: unknown = jsonResponse(true, 200, { svg: "<svg></svg>" })): void {
+    fetchMock.mockImplementation((input: RequestInfo | URL) => {
+        const url = String(input);
+        if (url.includes("/user/two-factor-qr-code")) {
+            return Promise.resolve(qr);
+        }
+        if (url.includes("/user/two-factor-secret-key")) {
+            return Promise.resolve(jsonResponse(true, 200, { secretKey: "ABCDEFGH12345678" }));
+        }
+        if (url.includes("/recent-auth/status")) {
+            return Promise.resolve(
+                jsonResponse(true, 200, {
+                    recent: true,
+                    passwordSet: true,
+                    availableProviders: [],
+                    passkeyAvailable: false,
+                    canSatisfy: true,
+                    confirmedAt: 1,
+                }),
+            );
+        }
+        return Promise.resolve(jsonResponse(true, 200, ["code-a", "code-b"]));
+    });
+}
+
+/** 有効化ボタン押下 → router.post の onSuccess 発火で enrollment 表示へ進める */
+async function openEnrollment(): Promise<void> {
+    await fireEvent.click(screen.getByTestId("enable-two-factor-button"));
+    await waitFor(() => {
+        expect(routerPostMock).toHaveBeenCalled();
+    });
+    const call = routerPostMock.mock.calls.at(-1);
+    if (!call) throw new Error("router.post が呼ばれていない");
+    (call[2] as { onSuccess?: () => void }).onSuccess?.();
+    await waitFor(() => {
+        expect(screen.getByLabelText("認証コード")).toBeInTheDocument();
+    });
+}
+
+beforeEach(() => {
+    pageState.props = {
+        appName: "AI-CUE",
+        auth: { user: { id: 1, name: "テスト太郎", twoFactorEnabled: false } },
+    };
+    stubFetchRoutes();
+    vi.stubGlobal("fetch", fetchMock);
+});
+
+afterEach(() => {
+    cleanup();
+    vi.unstubAllGlobals();
+    routerPostMock.mockReset();
+    addToastMock.mockReset();
+    fetchMock.mockReset();
+});
+
+describe("Settings/Security 2FA の QR は data URI の <img> で描く", () => {
+    it("two-factor-qr は IMG 要素で src が data:image/svg+xml, で始まる", async () => {
+        render(Security, { props: {} });
+        await openEnrollment();
+
+        const qr = await screen.findByTestId("two-factor-qr");
+        expect(qr.tagName).toBe("IMG");
+        expect(qr.getAttribute("src")).toMatch(/^data:image\/svg\+xml,/);
+    });
+
+    it("アクセシブルネームを alt で維持する", async () => {
+        render(Security, { props: {} });
+        await openEnrollment();
+
+        await waitFor(() => {
+            expect(screen.getByAltText("2 要素認証の設定用 QR コード")).toBeInTheDocument();
+        });
+        expect(screen.getByAltText("2 要素認証の設定用 QR コード")).toBe(
+            screen.getByTestId("two-factor-qr"),
+        );
+    });
+
+    it("サーバ応答の SVG に script が含まれていても DOM に script 要素が生えない", async () => {
+        stubFetchRoutes(
+            jsonResponse(true, 200, { svg: '<svg><script>window.pwned = true;</script></svg>' }),
+        );
+        const { container } = render(Security, { props: {} });
+        await openEnrollment();
+
+        const qr = await screen.findByTestId("two-factor-qr");
+        expect(qr.tagName).toBe("IMG");
+        // QR 要素の下に子要素が 1 つも生えていない (HTML として解釈されていない)。
+        // svg の有無を画面全体で見ることはできない (Lucide のアイコンが svg を描くため)。
+        expect(qr.querySelectorAll("*")).toHaveLength(0);
+        // script は画面のどこにも生えない (アイコンは script を描かない)
+        expect(container.querySelector("script")).toBeNull();
+    });
+
+    it("QR 取得失敗時は従来どおり代替導線を出す (後退が無い)", async () => {
+        stubFetchRoutes(jsonResponse(false, 500, null));
+        render(Security, { props: {} });
+        await openEnrollment();
+
+        await waitFor(() => {
+            expect(screen.getByTestId("qr-unavailable")).toBeInTheDocument();
+        });
+        expect(screen.queryByTestId("two-factor-qr")).toBeNull();
+        expect(screen.getByTestId("two-factor-setup-key-body")).toHaveTextContent(
+            "ABCDEFGH12345678",
+        );
+    });
+});
```

## 検証結果 (VERIFICATION_COMMANDS 全 green)

- `composer phpstan` (level 10): No errors (1010 files)
- `composer fix` / `vendor/bin/pint --test`: passed
- `composer test`: 6424 tests / 6422 passed / 2 skipped / 5 risky / **0 failed** (30788 assertions)
- `pnpm lint`: clean / `pnpm typecheck`: clean
- `pnpm test`: 173 files / 2366 tests passed
- `pnpm build`: built
- `pnpm typecheck:packages` / `pnpm build:packages`: clean / `pnpm test:packages`: 10 files / 106 tests passed

### 実装中に実測した追加の裏取り (差分には残らない一時操作)

1. gate の検出器 2 本 (`containsRawHtmlSink` / `assertLintExecutionUsable`) を stub に戻すと C' と F' が赤くなることを確認済 (6 failed / 3 passed)。
2. 施策 1 (lint 規則) を入れる前は A / B / B' / B'' が赤、施策 3 (置換) を入れる前は C が `resources/js/pages/Settings/Security.svelte` の 1 件を検出して赤になることを確認済。
3. 画面テストは置換前の `Security.svelte` に対して 3 assertion が赤 (`expected 'DIV' to be 'IMG'`) になることを確認済。
4. CSP pin の検出力: `config/security.php` の `img-src` を一時的に `"'self' https://data:443"` にすると本体テストが `Failed asserting that an array contains 'data:'.` で赤くなることを確認済 (素朴な部分文字列一致の実装ならここで緑になってしまう)。config は元に戻してある。

## 乖離台帳の状況

- `docs/template-fingerprints.json` の 281 キーのうち本差分が触るのは `eslint.config.js` の 1 件のみ。同ファイルは既にテンプレートと内容不一致で `docs/template-divergence.md` **D11** の対象パスとして登録済み、`adoption-debt.tsv` には含まれない。
- よって `LedgerPins` の 3 つの件数 pin (36 / 281 / 171) はいずれも不変、新規 D エントリも起こさない、という設計の判断をそのまま採った。この判断の妥当性も併せて見てほしい。
