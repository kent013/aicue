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

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）
- 検査は vitest (tests/js/) と Pest (tests/) の 2 系統。本設計の主な変更は vitest 側

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

【補足コンテキスト】
本件は家系 (6 リポジトリ) 共通の設計台帳 lctl が確定させた正典 v1 (不変条件 i1〜i22) への追従作業である。概念設計は別モデル (gpt-5.6-terra) のレビューを 3 ラウンド経て APPROVED になっている。
正典の要点:
- i2: @theme はリポジトリに 1 ブロックだけ / 宣言はトップレベルの @theme 直下
- i4: 検査の母集団を固定配列に書かず正本と写像から導出して集合一致させる
- i9: 参照の閉包 (token 名が写像 1 か所へ解決する。token を指さない語は理由つき契約表へ全数登録)
- i10: 文書の部品の節 ⇔ 実装の部品ファイルの双方向集合一致
- i12: 描画されない Markdown を検査の前に落とす (HTML コメント / 囲みコード / 4 空白字下げコード。行数は保つ。跡に空白でない目印)
- i13: 線形化しきい値 0.04045 / 一律 4.5:1 (大きな文字の 3:1 緩和は採らない)
- i14: ペア母集団は役割の全数分類 (既定拒否) の直積 + 個別宣言ペア
- i15: 実装 class からの逆向き被覆。走査分母はディレクトリ単位。行番号は固定しない
- i16: 半透明背景 × 不透明文字の合成検査。下地は宣言させず実在する不透明な下地すべてで AA。合成モデルは gate 本体に前提として書く。静的に決められない形は素通りさせない。半透明の組は全件台帳に載る
- i17: 非テキストの境界 (1.4.11 = 3:1) は対象外と宣言し、その token は免除へ理由つき登録
- i18: 壊れたら赤くなることを固定検体で示す / i19: 空振り遮断 / i20: 解析の失敗を pass に変えない
- i21: 正本と写像の読み出しは 1 実装へ集約 / i22: 保証しない範囲を検査自身が明記

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

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
| S1 | 写像 (tokens.css) の読み出しを 1 実装へ集約し、`@theme` ブロックの一意性を機械で固定する (i21 / i2 前半) | `tests/js/styles/theme-map.ts` (新) / `canonical-source-parity.test.ts` / `tokens.test.ts` | 高 (他施策の土台) |
| S2 | class 走査器を新設する (i15 / i16 / i9 の共通入力 + 未対応入口の deny) | `tests/js/styles/class-usage.ts` (新) / `tests/js/styles/class-usage.test.ts` (新) | 高 (土台) |
| S3 | 参照の閉包 gate を新設し、写像の外の色語を落とす (i9) | `token-reference-closure.test.ts` (新) / `inventory.ts` / `AppLayout.svelte` / `SidebarNavItems.svelte` | 高 |
| S4 | 線形化しきい値を 0.04045 へ揃える (i13) | `contrast-invariant.test.ts` | 高 (S5 の前提) |
| S5 | 半透明背景 × 不透明文字の合成検査を新設する (i16) | `contrast-invariant.test.ts` / `inventory.ts` | 高 |
| S6 | トークン値を是正する (i16 の帰結) | `DESIGN.md` / `resources/css/tokens.css` | 高 |
| S7 | 実装からの逆向き被覆と役割分類の是正 (i15 / i14) | `contrast-invariant.test.ts` / `inventory.ts` | 高 |
| S8 | 文書 ⇔ 実装の双方向一致 gate を新設する (i10) | `component-doc-parity.test.ts` (新) / `design-md.ts` / `inventory.ts` / `DESIGN.md` | 中 |
| S9 | 描画されない領域の除去に 4 空白字下げコードを足す (i12 の残余) | `design-system-docs.test.ts` / `docs/design-system.md` | 中 |
| S10 | 不透明度修飾の生成形を契約として固定する (i6 の補強 / S5 の前提の裏取り) | `tokens.test.ts` | 中 |
| S11 | 責務境界表へ新設 gate を登録する (i11 の帰結) | `docs/design-system.md` | 中 (必須。書かないと S3/S8 で既存 gate が落ちる) |
| S12 | 共有パスの採用時債務を決着させる (乖離台帳) | `docs/template-divergence.md` / `LedgerPins.php` / `adoption-debt.tsv` | 中 (必須) |

**実施順**: S1 → S2 → S4 → S10 → S5 → S6 → S7 → S3 → S8 → S9 → S11 → S12。
S4 を S5 より先に置くのは、しきい値を直してから合成の期待値を書くため
(逆順だと 0.03928 基準の期待値を書いて後で全部直すことになる)。
S6 (値の是正) は S5 の赤を確認した**後**に行う (テストファースト。思考原則 5)。
S11 は S3 / S8 が新設する `tests/js/styles/*.test.ts` を既存 `design-system-docs.test.ts` の
双方向集合一致が要求するので、**同じコミットの中**で行う。

---

## S1 写像の読み出しを 1 実装へ集約し、`@theme` ブロックの一意性を固定する (i21 / i2 前半)

### 変更箇所

- 新規: `tests/js/styles/theme-map.ts`
- `tests/js/styles/canonical-source-parity.test.ts` (L29-35 の `cssColorTokens()` を削除して移設、
  L66-69 の radius 抽出と L122 の `@utility` 抽出も移設)
- `tests/js/styles/tokens.test.ts` (`REPO_ROOT` の import 元は `design-md.ts` のままでよい。
  写像のテキストを読む必要が生じた箇所だけ `theme-map.ts` を使う)

### 波及変更

- TypeScript 型定義: `theme-map.ts` の公開型を新設 (下記)。他ファイルへの型の波及は無い
- API Resource/DTO: なし
- テストファイル: `canonical-source-parity.test.ts` の import 追加 / ローカル関数の削除

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
 * 【走査対象】resources/css/tokens.css のテキスト 1 本だけ。
 * 【保証しないもの】
 *   - Tailwind の解釈 (宣言が生成 CSS に出るか) は見ない。それは tokens.test.ts の担当
 *   - CSS の完全な構文解析ではない。`@theme` の**ブロックの深さは 1 段だけ**を想定し、
 *     入れ子の `{}` を含む宣言 (現状 0 件) は解析できないので**例外にする** (fail-closed)
 *   - 値の意味 (色空間・単位) は見ない。文字列として扱う
 */
export interface ThemeBlock {
    /** ブロック本文 (最初の `{` の次から対応する `}` の直前まで) */
    readonly body: string;
    /** ファイル先頭からのブロック開始位置 (診断用。期待値には使わない) */
    readonly offset: number;
}

/** tokens.css の `@theme` ブロックを全件返す (0 件・2 件以上も呼び出し側が判定できるよう返す)。 */
export function themeBlocks(): readonly ThemeBlock[];

/** `@theme` 直下の CSS 変数宣言 `{ 変数名 → 値 }`。同名の再宣言は例外 (i20)。 */
export function themeDeclarations(): ReadonlyMap<string, string>;

/** `--color-<suffix>` だけを suffix で引ける形にしたもの (コメント除去・小文字化)。 */
export function cssColorTokens(): ReadonlyMap<string, string>;

/** `--radius-<suffix>` だけを suffix で引ける形にしたもの。 */
export function cssRadiusTokens(): ReadonlyMap<string, string>;

/** `@utility text-<name>` の宣言 `{ name → { プロパティ → 値 } }`。 */
export function cssRampUtilities(): ReadonlyMap<string, ReadonlyMap<string, string>>;
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
        const withTheme = cssFiles.filter((rel) => hasThemeAtRule(rel));
        expect(withTheme).toEqual(["resources/css/tokens.css"]);
        expect(themeBlocks().length, "tokens.css の @theme が 1 ブロックでない").toBe(1);
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
      リポジトリに 1 つだけある」。実装前は `themeBlocks()` が存在しないので**コンパイルエラーで赤**。
      次に `theme-map.ts` を空実装 (`throw`) で置いて**実行時エラーで赤**を確認してから実装する
- [x] 既存テスト `canonical-source-parity.test.ts` の 8 it は**移設後も同じ期待値で緑**であること
      (リファクタの等価性の確認)
- [x] 新規: `tests/js/styles/theme-map.test.ts` — 固定検体でパーサの仕様を固定する (i18)
  - 負例 1: `@theme` を 2 ブロック持つ検体 → `themeBlocks().length === 2` (呼び出し側が落とせる)
  - 負例 2: `@media` の中の `@theme` → **ブロックとして数える**が、
    `themeDeclarations()` は**トップレベルの `@theme` だけ**を見る (i2 後半と同じ絞り込み)
  - 負例 3: 同名変数の再宣言 → 例外 (i20)
  - 負例 4: 入れ子の `{}` を含む宣言 → 例外 (解析できない形を落とす = 共通規約 (b))
  - 正例: 現行 tokens.css と同形の検体で色 / radius / ramp が期待どおり取れる
- [x] 母集団の非空: `themeDeclarations().size > 0` / `cssColorTokens().size > 0` /
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

/** 解決した色の参照 (前景 / 背景のどちらか)。 */
export interface ColorUse {
    readonly channel: "background" | "foreground";
    /** tokens.css の `--color-<suffix>` の suffix (DESIGN.md のキーではない) */
    readonly suffix: string;
    /** 不透明度修飾。`null` は修飾なし */
    readonly alpha: number | null;
    /** 元の class トークン (診断用。期待値には使わない) */
    readonly token: string;
}

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

/** 静的に組を決められない理由 (正典 i16 が「例外にして素通りさせない」と定めた形)。 */
export type UndecidableReason =
    | "foreground-alpha"          // 前景にも不透明度修飾がある
    | "double-alpha"              // alpha を持つ token にさらに修飾 (bg-primary-soft/40)
    | "keyword-color"             // bg-transparent 等、token を指さない色キーワード
    | "alpha-background-no-text"  // 同じ宣言に前景が無い alpha 背景
    | "opaque-and-alpha-background" // 同じ状態に塗り面の背景と alpha 背景が同居
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
    /** ディレクトリごとの抽出件数 (どれかが丸ごと読めていない状態を捕まえる) */
    readonly perDirectory: ReadonlyMap<string, number>;
    readonly pairs: readonly ScannedPair[];
    readonly incompleteOpaque: IncompleteOpaqueCounts;
    /** テーマ名前空間の接頭辞を持つが解決できなかった語 (契約表に無いもの) */
    readonly unresolvedTokens: readonly string[];
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

```
resources/js/components   (深さ無制限)
resources/js/pages        (深さ無制限)
resources/js/lib          (深さ無制限)
```

- 3 根とも**存在しなければ fail-fast** (`PrismDirectDispatchScanner::roots()` に倣う)。
- `perDirectory` は上の 3 根ごとの抽出件数で、**それぞれ 0 でないこと**を gate が固定する
  (motivation の「ディレクトリごとに 1 件以上抽出できる」形)。
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
  - class トークンの分解: 接頭辞つき `sm:bg-primary` / 打ち消しつき `!bg-primary` /
    接尾辞つき `bg-primary/10` の**3 形**をそれぞれ正しく解決する
    (素の部分文字列一致だと 3 形が一緒に消える。共通規約 (e))
  - 非 ASCII の混入 (`bg-primaryあ`) は**解決できない語**として `unresolvedTokens` に出る
  - `bg-(--var)` はトークンが割れるので `unresolvedTokens` に出る
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
- [x] 空振り検知: `files.length > 0` / `perDirectory` の 3 根がそれぞれ > 0 /
      `pairs.length > 0` (共通規約 (b) の 3 点目)
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- 状態単位の作り方が Tailwind の実際の勝敗 (生成 CSS の順序) と一致しない場合がある。
  → `atoms/input-state.ts` のコメントが既に「Tailwind は同一プロパティの utility が並んだ場合、
  勝敗が class 属性の順ではなく生成 CSS の順で決まる」と記録している。本走査器は
  **同じ状態に同じ channel の宣言が 2 つ以上ある単位**を `opaque-and-alpha-background` か
  (両方不透明なら) `unresolvedTokens` ではなく**判定不能**として扱い、素通りさせない。
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
function linearize(channel: number): number {
    const c = channel / 255;
    return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
}
```

負のコントロールへ追加する検査:

```ts
it("負のコントロール: errata のしきい値の差が 8bit では判定を変えない", () => {
    // 「揃えたら結果が変わった」= どちらかの実装が間違っていたことになるので、
    // 変わらないことを 8bit の全チャンネル値で固定する (i18 の既知値)。
    for (let channel = 0; channel <= 255; channel += 1) {
        const c = channel / 255;
        expect(c <= 0.03928, `channel=${channel}`).toBe(c <= 0.04045);
    }
});
```

### 型適合チェック

- [x] 戻り値の型が明示されている / [x] `null` 安全 (数値のみ) / [x] 配列返却なし / [x] Generics なし

### テスト計画

- [x] **先に赤くするテスト**: 上の「errata のしきい値の差が 8bit では判定を変えない」を
      **先に書く**。この it は現行実装でも緑になる (性質の検査なので)。
      **緑になる場合は負例が押さえる分岐を一時的に壊して赤を確認する**
      (共通規約「既存の抽出器を流用して最初から緑になる場合は、負例が押さえる分岐を
      一時的に壊して赤を確認する」)。具体的には片方を `0.5` にして赤を見る
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
        const expected = designColors().get("primary");
        expect(decls.get("background-color")).toBe(
            `color-mix(in srgb, ${expected} 10%, transparent)`,
        );
    });

    it("@supports の中は var() 参照の oklab 混色になる", () => {
        // 条件つき at-rule の中は soleRule が拾わないので、条件つきの側を明示的に見る。
        // 条件の綴りは allowlist と突き合わせる (D の ALLOWED_HOVER_CONDITIONS と同じ方針)。
        …
    });

    it("alpha を値に持つ派生 token への修飾は alpha の二重になる (S5 が判定不能に分類する根拠)", () => {
        const decls = soleRule(sealed, ".bg-primary-soft\\/40");
        expect(decls.get("background-color")).toBe(
            `color-mix(in srgb, ${cssColorTokens().get("primary-soft")} 40%, transparent)`,
        );
    });
});
```

### 型適合チェック

- [x] 戻り値の型が明示されている / [x] `null` 安全 (`soleRule` は 0 件も重複も落とす) /
      [x] 配列返却なし / [x] Generics なし

### テスト計画

- [x] **先に赤くするテスト**: 上の 3 it。`UTILITY_CANDIDATES.alpha` を足す前は
      `.bg-primary\/10` の規則が生成されないので `soleRule` が「1 件でない」で**赤**になる
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
 * ★**下地は宣言しない**。実在する不透明な下地 = 役割分類の「面」(SURFACE_ROLE_TOKENS) の
 *   **すべて**の上で 4.5:1 を要求するので、部品がどちらに置かれても成立する。
 * ★行番号は持たない (正典 s14)。ファイル単位までである。
 */
export const ALPHA_CONTRAST_PAIRS = [
    // ★キーは **tokens.css の `--color-<suffix>` 空間** である (下の「2 つのキー空間」を参照)。
    { fg: "danger", bg: "danger", alpha: 0.1 },
    { fg: "primary", bg: "primary", alpha: 0.1 },
    { fg: "primary", bg: "primary-soft", alpha: 0.12 },
    { fg: "success", bg: "success", alpha: 0.1 },
    { fg: "surface", bg: "text", alpha: 0.7 },
    { fg: "tertiary", bg: "tertiary", alpha: 0.1 },
    { fg: "text", bg: "danger", alpha: 0.1 },
    { fg: "text", bg: "primary-soft", alpha: 0.12 },
    { fg: "text", bg: "surface", alpha: 0.8 },
    { fg: "text", bg: "warning", alpha: 0.1 },
    { fg: "text-secondary", bg: "surface", alpha: 0.8 },
    { fg: "warning", bg: "warning", alpha: 0.1 },
] as const satisfies readonly AlphaPair[];

/**
 * 静的に組を決められなかった単位の台帳 (正典 i16「例外にして静かに素通りさせない」)。
 *
 * ★(ファイル, 理由) の**実体集合**を固定する。件数だけを固定すると、
 *   新しい未解析の使用を件数更新で通す誘惑が残る。
 * ★不透明のみの不完全な単位 (前景か背景の片方しか無い) は**ここに載せない** —
 *   `bg-surface` 単独が 39 単位・`bg-neutral` 単独が 20 単位あり、実体集合で pin すると
 *   期待値の機械的な更新が常態化して統制が形骸化する (正典 s14 と同じ理由)。
 *   そちらは「0 でないこと」と「分類の全数性」で受け、組そのものは i14 の役割直積が覆う。
 */
export const UNDECIDABLE_PAIR_LEDGER = [
    { file: "components/atoms/Button.types.ts", reason: "keyword-color",
      note: "ghost / danger-ghost の bg-transparent。背景は親から来る" },
    { file: "components/atoms/input-state.ts", reason: "interpolated",
      note: "完成した class 文字列を補間で差し込む (border の状態)" },
    { file: "components/atoms/input-state.ts", reason: "foreground-alpha",
      note: "placeholder:text-text-secondary/70 (前景に不透明度修飾)" },
    { file: "components/atoms/Button.types.ts", reason: "element-opacity",
      note: "success / danger の hover:opacity-90 (要素全体の不透明度)" },
    { file: "components/molecules/PendingInvitationsNotice.svelte", reason: "double-alpha",
      note: "bg-primary-soft/40 (alpha を値に持つ token への修飾)" },
    { file: "components/features/notifications/NotificationListItem.svelte", reason: "double-alpha",
      note: "unread 時の bg-primary-soft/40" },
    /* … alpha-background-no-text の 13 ファイル … */
] as const satisfies readonly UndecidableEntry[];

/** 未検査であることを明示する pending 集合。**i16 の完了後も空にならない**。 */
export const PENDING_CONTRAST_PAIRS = [
    "WCAG 1.4.11 非テキストコントラスト (3:1): border / border-strong / focus ring (正典 i17 により本 gate の対象外)",
    "UNDECIDABLE_PAIR_LEDGER に載せた 7 分類 (前景の alpha / alpha の二重 / 色キーワード / " +
        "前景を持たない alpha 背景 / 塗り面と alpha 背景の同居 / 要素全体の不透明度 / 補間)",
] as const;
```

```ts
// tests/js/architecture/contrast-invariant.test.ts (追加)

/**
 * 半透明の背景を不透明な下地の上へ合成する。
 *
 * 【前提 (版や環境で変わりうるので gate 本体に書く)】
 *   1. 不透明度修飾は `color-mix(…, transparent)` へ展開され、**透明との混色は
 *      同じ色の alpha になる** (透明側の乗算済み色が寄与しないため色相・明度は変わらない)。
 *      生成形そのものは tokens.test.ts の「H. 不透明度修飾の生成形」が固定する
 *   2. ブラウザの合成は**チャンネルごとの `a*FG + (1-a)*BG`** である
 *   3. 実際に描かれるのは **8bit へ丸めた値**である。丸めまで再現しないと
 *      docs/design-system.md の記録値と 0.01 ずれる
 *   ★広い色域 (Display P3 等) の実描画との厳密一致は**測っていない** (正典の未決論点 q3)。
 */
function compositeOverOpaque(alphaHex: string, alpha: number, baseHex: string): string { … }

describe("architecture/contrast-invariant: 半透明背景 × 不透明文字 (面のすべての上で 4.5:1)", () => {
    it("走査で見つかった半透明の組と台帳が集合一致する (件数だけの pin にしない)", () => { … });
    it("判定不能の単位と台帳が (ファイル, 理由) で集合一致する", () => { … });
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
});
```

### 2 つのキー空間 (取り違えの防止)

`inventory.ts` は**2 つのキー空間**を扱う。取り違えると別のトークンを検査してしまうので、
どちらの空間かを宣言ごとに docblock へ書き、境界は `COLOR_TOKEN_MAP` の 1 本だけにする。

| 空間 | 使う宣言 | 例 |
|---|---|---|
| **DESIGN.md の色キー** (13 件) | 役割分類 (`SURFACE_ROLE_TOKENS` / `TEXT_ON_SURFACE_TOKENS` / `FILL_TOKENS` / `FILL_LABEL_TOKENS` / `CONTRAST_EXEMPT_TOKENS` / `DECLARED_CONTRAST_PAIRS`) | `text-primary` = **本文色** |
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
- [x] 逆引き表 ([token-change-impact.md](./token-change-impact.md)) の 131 行で、
      非テキスト用途 (`border-*` / `ring-*` / `decoration-*` / `accent-*`) と
      テキストを載せない塗り面 (Toggle トラック / アイコン帯) を目視レビューする

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

## S7 実装からの逆向き被覆と役割分類の是正 (i15 / i14)

### 変更箇所

- `tests/js/styles/inventory.ts`
  (`FILL_LABEL_TOKENS` へ `surface` を追加 / `CONTRAST_EXEMPT_TOKENS` から `border` を削除し
  `border-strong` の理由を書き換え / `DECLARED_CONTRAST_PAIRS` を新設)
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

/** 塗り面 (solid fill) として使うトークン。**変更しない** (border を入れない理由は詳細設計 S7)。 */
export const FILL_TOKENS = [
    "primary", "primary-hover", "tertiary", "tertiary-hover", "success", "warning", "danger",
] as const;

/**
 * 塗り面の上に載るラベル色。
 * ★`surface` を含むのは `text-surface` が字幕帯 (molecules/SubtitleOverlay)・
 *   撮影中バッジ (features/capture)・サイドバーの選択中 (templates/_helpers) で
 *   実際にラベル色として使われているためである。直積の全組が AA を満たすので直積で受ける。
 */
export const FILL_LABEL_TOKENS = ["neutral", "surface"] as const;

/**
 * 役割の直積で表現できない正当な 1 対 1 の組 (理由必須。正典 i14)。
 *
 * ★直積と**同じ閾値** (4.5:1) を課す。
 * ★**キーは DESIGN.md の色キー空間**である (`text-primary` は本文色 = `--color-text`)。
 *   走査器が返す CSS suffix 空間とは別なので、突き合わせは COLOR_TOKEN_MAP の逆写像で行う。
 * ★ここへ逃がすのは「直積に入れると**実在しない組**まで検査してしまう」場合だけである。
 *   直積が全組成立するなら直積側で受ける (安易な個別宣言は母集団を痩せさせる)。
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

/**
 * コントラスト検査の対象外トークン (理由必須)。
 *
 * ★**免除と検査対象の役割は排他である** (下の it が固定する)。
 *   「免除に入っているのに塗り面としてテキストを載せている」状態は理由が嘘になるので、
 *   `border` は本表から外し `DECLARED_CONTRAST_PAIRS` へ移した。
 */
export const CONTRAST_EXEMPT_TOKENS = {
    "border-strong":
        "3 つの用途がいずれも本 gate の対象外である — (1) 1px の区切り線・入力欄の枠 " +
        "(WCAG 1.4.11 の非テキスト 3:1 で別の閾値体系。役割モデルが未定のため家系の未決論点 q2 の担当)、" +
        "(2) Toggle のトラック (テキストを載せない塗り)、" +
        "(3) 無効化したタブのラベル (SC 1.4.3 は無効化された UI 部品を適用除外にしている)。" +
        "実測 2.56 で 3:1 に届かないので、値の是正は 1.4.11 の役割モデルを DESIGN.md に" +
        "定めてから別バッチで行う",
} as const;
```

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

    it("免除に入れたトークンが検査対象の役割を持たない (免除の理由が嘘にならない)", () => {
        const checked = new Set([
            ...SURFACE_ROLE_TOKENS, ...TEXT_ON_SURFACE_TOKENS,
            ...FILL_TOKENS, ...FILL_LABEL_TOKENS,
            ...DECLARED_CONTRAST_PAIRS.flatMap((p) => [p.fg, p.bg]),
        ]);
        const contradictory = Object.keys(CONTRAST_EXEMPT_TOKENS).filter((t) => checked.has(t));
        expect(contradictory).toEqual([]);
    });

    it("走査器が扱えない既知の入口が 0 件である", () => {
        expect(unsupportedEntryPoints()).toEqual([]);
    });

    it("不透明のみの不完全な単位が 0 でない (分類が生きている)", () => {
        const { incompleteOpaque } = scanClassUsage();
        expect(incompleteOpaque.backgroundOnly).toBeGreaterThan(0);
        expect(incompleteOpaque.foregroundOnly).toBeGreaterThan(0);
    });
});
```

既存 it の拡張 (削除ではなく拡張):

```ts
it("役割宣言が DESIGN.md の全色トークンを覆う (deny-by-default)", () => {
    const classified = new Set<string>([
        ...SURFACE_ROLE_TOKENS, ...TEXT_ON_SURFACE_TOKENS,
        ...FILL_TOKENS, ...FILL_LABEL_TOKENS,
        // 個別宣言ペアに現れるトークンも分類済みとして数える (border がこれで満たされる)
        ...DECLARED_CONTRAST_PAIRS.flatMap((p) => [p.fg, p.bg]),
        ...Object.keys(CONTRAST_EXEMPT_TOKENS),
    ]);
    …  // 以下は既存のまま
});
```

### 型適合チェック

- [x] 戻り値の型が明示されている
- [x] `null` 安全 (`hex()` は不在で例外。`COLOR_TOKEN_MAP` の逆写像が引けない suffix は例外)
- [x] 配列返却ではなく `as const satisfies readonly DeclaredPair[]` の宣言
- [x] Generics の型パラメータが正しい

### テスト計画

- [x] **先に赤くするテスト**: 「走査で得た不透明ペアがすべて母集団の内側にある」。
      役割分類を直す**前**に実行すると `(text, border)` が母集団の外なので**赤**になる
      (S3 で `text-white` を直した後は `(surface, primary)` も現れ、
      `surface` を `FILL_LABEL_TOKENS` へ足すまで赤が続く)。
      これが役割分類と実装の食い違いの実証である
- [x] 「免除に入れたトークンが検査対象の役割を持たない」は、`border` を免除に残したまま
      `DECLARED_CONTRAST_PAIRS` へ足すと**赤**になることを先に確認する
- [x] `it.each(PAIRS)` に個別宣言ペアが加わることで**組の総数が増える**ことを確認する
      (母集団を痩せさせていないことの確認)
- [x] 既存テストの削除・上書きをしない: 既存の 4 it (役割の被覆 / 0 件でない / 素である /
      pending が空でない) と `it.each(PAIRS)` は**すべて据え置く** (被覆の it は拡張のみ)
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- 個別宣言ペアは「直積で表現できないもの」に限る規律が緩むと母集団が痩せる。
  → 登録の理由に「直積へ入れると実在しない組が生まれる」ことを**具体的な比の値つき**で
  書くことを様式にする (上記 `reason` の形)。レビューで判断できる。
- `surface` を `FILL_LABEL_TOKENS` へ足すと直積が 7 組増える。是正後の値では全組が
  6.70〜9.48 で成立する (実測)。**是正前の値では `surface on primary` が 5.17 で成立する**ので、
  S6 の前に足しても赤にはならない。

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
 */
export const NON_TOKEN_WORD_CONTRACT = {
    "bg-transparent": "CSS の全域キーワード。色 token を指さない",
    "border-transparent": "同上。全 variant で外形高さを揃えるための透明枠 (DESIGN.md §Components)",
    "border-2": "境界の太さ。色ではない",
    "border-b": "境界の辺の指定。色ではない",
    "border-b-0": "同上 (打ち消し)",
    "border-l-2": "同上",
    "border-r": "同上",
    "border-t": "同上",
    "border-dashed": "境界の線種。色ではない",
    "divide-y": "区切り線の軸。色ではない (色は divide-border が持つ)",
    "outline-none": "outline の打ち消し。色ではない",
    "ring-2": "focus ring の太さ。色ではない",
    "ring-3": "同上",
    "rounded-full": "角丸 ramp の外の真円 UI。radius token を指さず ds-purity の file-scoped allowlist が管轄する",
    "text-center": "テキストの整列。色でも ramp でもない",
    "text-left": "同上",
    "text-right": "同上",
    "--app-sidebar-w": "同一要素の style 属性で宣言する局所変数。@theme の token ではない (他ファイルのローカル宣言を解決の根拠に数えない)",
} as const;
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

1. `resources/js` の class トークンのうち、テーマ名前空間の接頭辞を持つものが
   **写像の宣言集合 / ramp 集合 / radius 集合 / 契約表**のいずれかへ解決する (未解決は不合格)
2. `var(--…)` 参照が**写像の宣言集合か契約表**へ解決する
3. **契約表に冗長な登録が無い** (実際には解決する語を登録していない / 出現しない語を登録している)
4. **母集団が空でない** (class トークン数 > 0 / `var()` 参照数 > 0)
5. 負のコントロール (固定検体):
   - `text-white` を含む検体 → 不合格になる (**Tailwind 既定テーマの色語を通さない**)
   - `bg-primaryy` (綴り誤り) → 不合格になる
   - `var(--color-does-not-exist)` → 不合格になる
   - 別ファイルの `:root` に `--color-foo` を宣言した検体 → **解決の根拠に数えない**
     (写像 1 か所だけという境界そのものを pin する)
   - 契約表の語 (`text-center` 等) は誤検出しない
   - 接頭辞つき `sm:text-center` / 打ち消しつき `!text-center` /
     接尾辞つき `text-center/50` の 3 形 (共通規約 (e))

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
| `### DragHandle` | `atoms/DragHandle.svelte` | 並べ替えのつかみ手。`GripVertical` 固定 / `touch-none` でタッチをスクロールに奪わせない / **disabled にしない** (禁止事項 8) / 小コントロールなので `rounded-sm` |
| `### OrganizationChoiceCard` | `molecules/OrganizationChoiceCard.svelte` | 組織を 1 件選ぶ遷移カード。遷移先 URL は親が渡す (組織文脈を molecule が解決しない) |
| `### PendingInvitationsNotice` | `molecules/PendingInvitationsNotice.svelte` | 自分宛の保留中招待の件数だけを出す誘導専用 notice。**受諾 UI は持たない** (受諾は通知一覧) |
| `### SubtitleOverlay` | `molecules/SubtitleOverlay.svelte` | 映像へ重畳する字幕 overlay。焼込ではなく DOM overlay (MediaRecorder の stream に含まれない) / primary=上部帯・secondary=下部メイン / 位置は `AssSubtitleWriter` (ASS) と一致 / 長文は line-clamp で省略 |

```ts
// tests/js/styles/inventory.ts
/** §Components の対象にするサブディレクトリの全数分類 (既定拒否)。 */
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
   申告を冗長にすると赤 / 未分類のサブディレクトリを足すと赤

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
 * ★開始できるのは「段落を継続できない位置」だけである (CommonMark は段落の途中の
 *   字下げ行を継続行として扱う)。完全な段落判定は持たないので、
 *   **直前の描画行が空行である**ことで近似する。
 * ★近似は**落とす側 (fail-closed) に倒す**: リスト項目の直下に 4 空白で続けた行は
 *   誤って落としうる (実測: docs/design-system.md には 4 空白以上の字下げ行が 1 行も無く、
 *   1〜3 空白の字下げ行も 0 行なので、現時点で誤検出は起きない)。
 * ★終端は「空行でない、字下げ 4 未満の行」である。空行は区間を終わらせない。
 */
```

`renderedLines()` の変更:

- fence / コメントの処理の**後**に、字下げコードの状態機械を 1 段足す。
- 区間の中の行は `""` へ潰す (**行数は保つ**)。
- 状態遷移は「直前の描画行が空行 (または文書先頭)」+「字下げ 4 以上」+「fence の外」で開始。

`docs/design-system.md` の訂正:

```markdown
ただし**完全な Markdown 解析ではない** — 4 空白字下げのコードブロックと
HTML 要素による非表示は見ていない。
```
↓
```markdown
落とすのは HTML コメント・囲みコード・**4 空白以上の字下げコード**の 3 つで、
字下げコードの開始位置は「直前の描画行が空行」で近似する(段落の途中の継続行を
誤って落とす側に倒してある)。ただし**完全な Markdown 解析ではない** —
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
- [x] 負のコントロール (追加):
  - 空行を挟まない 4 空白字下げ行 (段落の継続行) は**落とす側に倒す**ことを固定する
    (性質を明記した検査。誤検出を「仕様」として見えるようにする)
  - 字下げコードの中の `` ``` `` で fence が開かないこと
  - 区間の中の空行が区間を終わらせないこと
  - 行数が保存されること (既存の it が自動で見る)
- [x] 既存の 8 it が同じ期待値で緑であること (`docs/design-system.md` に
      4 空白以上の字下げ行が 1 行も無いことを実測済み)
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- 近似が将来 `docs/design-system.md` の本文を誤って落とし、
  規範の最小断片の検査が偽陽性で赤くなる可能性がある。
  → 落とす側に倒すのは共通規約 (b) に沿う。赤くなったら**書き方を直す**
  (字下げをやめる) のが正しい対応であり、検査を緩めない。この方針を docblock に書く。

---

## S11 責務境界表へ新設 gate を登録する (i11 の帰結)

### 変更箇所

- `docs/design-system.md` (§検査の責務境界の表に 3 行追加 / 本数の記述 4 → 6 /
  §トークン変更時の運用契約に 1 行追加 / §テーマの差し替え方に 1 行追加)

### 波及変更

- テストファイル: 既存 `design-system-docs.test.ts` の
  「責務境界表の 1 列目と実在する検査ファイルが集合一致する (双方向)」が**この行なしでは赤**
- **共有パス**: `docs/design-system.md` → S12

### 変更後コード (表に追加する 3 行)

| 検査 | 見ている写像 | 代表的に検出する壊れ方 |
|------|------------|--------------------|
| `tests/js/styles/token-reference-closure.test.ts` | 参照側 (resources/js / resources/css) ⇒ tokens.css の宣言集合 | token 名の綴り誤りが無スタイルとして静かに消える / 写像の外の色語 (Tailwind 既定の white 等) の混入 |
| `tests/js/styles/component-doc-parity.test.ts` | DESIGN.md §Components ⇔ resources/js/components の部品ファイル | 文書に載らない部品が増える / 節だけ残って実装が消える |
| `tests/js/styles/class-usage.test.ts` | 走査器そのもの (固定検体) | 状態単位の分解の退行 / 未対応入口の deny の空振り |

本数の記述: 「4 本ある」→「6 本ある」。
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

- 本数の記述 (「6 本ある」) は機械検査の対象ではないので陳腐化しうる。
  → 表そのものは機械で突き合わせているので、本数の記述は説明にとどまる。
  数字を最小断片に入れない (文言固定は増やさない = 既存方針)。

---

## S12 共有パスの採用時債務を決着させる (乖離台帳)

### 変更箇所

- `docs/template-divergence.md` (宣言行 46 → 47 / **D50 を追加**)
- `tests/Support/TemplateDivergence/LedgerPins.php`
  (`DIVERGENCE_ENTRY_COUNT` 46 → 47 / `ADOPTION_DEBT_COUNT` 148 → 146)
- `tests/Support/TemplateDivergence/adoption-debt.tsv` (2 行削除)

### 乖離台帳の確認段 (app-design スキル 3-0)

`docs/template-fingerprints.json` のキーに在るか (= テンプレートと共有するパスか) を実測した。

| 変更するパス | 指紋台帳のキー | 採用時債務 | 決着 |
|---|---|---|---|
| `docs/design-system.md` | **在る** | **在る** (採用時 sha と現況が一致) | **(3) 意図的逸脱として登録を書き、債務から削る** |
| `tests/js/architecture/contrast-invariant.test.ts` | **在る** | **在る** (同上) | **(3) 同上** |
| `tests/js/support/ds-purity.ts` | 在る | 在る | **変更しない** (i9 が同じ穴を塞ぐので `white`/`black` を禁止リストへ足す案は採らない) |
| `DESIGN.md` | 無い | — | 登録不要 |
| `resources/css/tokens.css` | 無い | — | 登録不要 |
| `resources/css/app.css` | 無い (変更もしない) | — | — |
| `tests/js/styles/*` (既存 5 + 新設 3) | 無い | — | 登録不要 (既存の D28 が同領域の逸脱を説明済み) |
| `postcss.config.js` | 在る (変更しない) | — | — |

**判定の根拠**: `FingerprintReconciler` は債務パスの現況が採用時 sha と違えば
`mutatedDebtPaths` として落とす。かつ債務パスと登録の対象パスの**両方に在る** (`doubleDeclaredPaths`)
のも落とす。したがって「登録を書く」と「債務から削る」は**同じ変更で行う**。

### 追加する登録 (D50)

```markdown
## D50 デザイントークンのコントラスト検査を、半透明の合成と実装からの逆向き被覆まで広げる

| 行 | 内容 |
|---|---|
| 対象パス | `tests/js/architecture/contrast-invariant.test.ts` / `docs/design-system.md` |
| 業務要件起因の説明 | 撮影 PWA の状態表示 (撮影中 / 完了 / 警告) はソフト背景のバッジで出しており、作業者はその 1 個の色で工程の状態を読む。テンプレートの検査は不透明な組だけを見るため、実際に画面へ出ているソフト背景の可読性が 1 件も検査されていなかった (実測で 5 組が AA 未達) |
| 揃え続ける不変条件と保証機構 | 半透明の背景 × 不透明な文字の組が、面として分類した token のすべての上で 4.5:1 を満たすこと。走査で見つかった半透明の組が全件台帳に載ること。実装の class から導出した前景 × 背景の組が役割の母集団の内側にあること。線形化しきい値が errata 後の 0.04045 であること。`contrast-invariant.test.ts` と `tests/js/styles/class-usage.ts` が保証し、運用ガイドの責務境界表が検査目録を双方向に固定する |
| 再判定の条件 | 正典が半透明の合成を不変条件から外したとき。または Tailwind の不透明度修飾の展開形が変わって合成モデルの前提が崩れたとき (`tokens.test.ts` の「不透明度修飾の生成形」が赤くなる)。広色域の実描画との差を実測して系統的なずれが出たとき (家系の未決論点 q3) |
| 決めた日 | 2026-08-24 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260824-1019-design-token-system-v1/ |
| 状態 | 恒久 |
| 見直し期限 | — |
```

+ 観点表 (テンプレート / 本アプリ)、`### なぜ正当な差分か(logic-driven)`、
`### 揃えている不変条件(これは保証し続ける)`、`### 保証しないもの`、`### 関連` を
エントリ形式どおりに書く。

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
- D 番号は再利用しない規約なので `D50` (現在の最大が `D49`) を使う。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 12 施策が 1 本の依存鎖でつながっており、途中の状態では**必ず赤いテストが残る**。とくに S5 (合成検査) を入れた時点で 5 組が赤になり、S6 (値の是正) を同じ作業単位で行わなければ main がマージ不能になる。同様に S3 / S8 / S2 が新 `*.test.ts` を作った時点で既存 `design-system-docs.test.ts` が赤になり、S11 が同じ作業単位に無いと閉じない。S4 が共有パスを触った時点で `TemplateDivergenceFingerprintTest` が赤になり、S12 が同じ作業単位に無いと閉じない。分割すると「赤いまま main に入れる」か「後方互換の並走を残す」のどちらかになり、AGENTS.md 思考原則 3 と禁止事項 1 に触れる |
| 競合リスク | `tests/js/styles/inventory.ts` に 6 つの台帳・分類表を追加するため、同ファイルを触る他タスクと衝突しうる。`docs/TODO.md` の Open は T249 (別 feature「起動 probe の共通 runner 一元化」) のみで、`tests/js/styles/` には触らないため**現時点で衝突なし**。`DESIGN.md` / `resources/css/tokens.css` / `docs/design-system.md` も T249 の対象外 |

### 実装中に「後方互換の並走を残さない」ために同じ作業単位で消すもの (AGENTS.md 思考原則 3)

| 消すもの | 移す先 |
|---|---|
| `canonical-source-parity.test.ts` のローカル `cssColorTokens()` / radius 抽出 / `@utility` 抽出 | `tests/js/styles/theme-map.ts` |
| `PENDING_CONTRAST_PAIRS` の「alpha 合成ペア」の 1 行 | `ALPHA_CONTRAST_PAIRS` + `UNDECIDABLE_PAIR_LEDGER` (pending には判定不能の分類だけが残る) |
| `CONTRAST_EXEMPT_TOKENS` の `border` | `DECLARED_CONTRAST_PAIRS` (塗り面としての 1 対 1 の組) |
| `resources/js` の `text-white` 3 箇所 | `text-surface` |
| `docs/design-system.md` の「落とすのは HTML コメントと fenced code の 2 つ」 | 3 つに訂正 |
| `adoption-debt.tsv` の 2 行 | `docs/template-divergence.md` の D50 |

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

---

## 関連する現行コード

### tests/js/styles/inventory.ts (全文)

```ts
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

### tests/js/architecture/contrast-invariant.test.ts (全文)

```ts
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

### tests/js/styles/design-md.ts (全文)

```ts
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

### tests/js/styles/canonical-source-parity.test.ts (全文)

```ts
import { describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import {
    COLOR_TOKEN_MAP,
    COMPILED_VALUE_EXEMPT_TOKENS,
    DERIVED_COLOR_TOKENS,
    FRONTMATTER_SECTION_OWNERS,
    RADIUS_TOKENS,
    TYPOGRAPHY_RAMPS,
} from "./inventory";
// DESIGN.md 側のパーサは contrast-invariant と共有する (二重実装しない)。
import {
    REPO_ROOT,
    designColors,
    designFrontmatterSections,
    designRamp,
    designRounded,
    designTypographyNames,
} from "./design-md";

/**
 * DESIGN.md (canonical) ⇔ resources/css/tokens.css (実装写像) の双方向同期を機械検証する。
 * 片方だけ更新された PR をここで落とす (docs/design-system.md の同期契約)。
 */

const tokensCss = fs.readFileSync(path.join(REPO_ROOT, "resources/css/tokens.css"), "utf-8");

function cssColorTokens(): Map<string, string> {
    const map = new Map<string, string>();
    for (const m of tokensCss.matchAll(/--color-([a-z-]+):\s*([^;]+);/g)) {
        map.set(m[1], m[2].replace(/\/\*.*?\*\//g, "").trim().toLowerCase());
    }
    return map;
}

describe("canonical source parity: colors", () => {
    it("DESIGN.md の色集合と tokens.css の --color-* が一致する (set equality)", () => {
        const design = designColors();
        const css = cssColorTokens();

        const expected = [
            ...Object.values(COLOR_TOKEN_MAP),
            ...DERIVED_COLOR_TOKENS,
        ].sort();
        expect([...css.keys()].sort()).toEqual(expected);
        expect([...design.keys()].sort()).toEqual(Object.keys(COLOR_TOKEN_MAP).sort());
    });

    it("DESIGN.md と tokens.css の色の値が一致する (value parity)", () => {
        const design = designColors();
        const css = cssColorTokens();

        for (const [designKey, cssSuffix] of Object.entries(COLOR_TOKEN_MAP)) {
            expect(css.get(cssSuffix), `--color-${cssSuffix}`).toBe(design.get(designKey));
        }
    });
});

describe("canonical source parity: radius", () => {
    it("DESIGN.md rounded と tokens.css の --radius-* が一致する", () => {
        // section 不在は designRounded() が例外で落とす (旧 expect(section).not.toBeNull() 相当)
        const design = designRounded();

        const css = new Map<string, string>();
        for (const m of tokensCss.matchAll(/--radius-([a-z]+):\s*([^;]+);/g)) {
            css.set(m[1], m[2].trim());
        }

        expect([...css.keys()].sort()).toEqual([...RADIUS_TOKENS].sort());
        for (const key of RADIUS_TOKENS) {
            expect(css.get(key), `--radius-${key}`).toBe(design.get(key));
        }
    });
});

describe("canonical source parity: typography ramp", () => {
    function cssRamp(name: string): Record<string, string> {
        const m = tokensCss.match(new RegExp(`@utility text-${name} \\{([^}]+)\\}`));
        if (!m) throw new Error(`tokens.css @utility not found: text-${name}`);
        const props: Record<string, string> = {};
        for (const line of m[1].matchAll(/([a-z-]+):\s*([^;]+);/g)) {
            props[line[1]] = line[2].trim();
        }
        return props;
    }

    it.each([...TYPOGRAPHY_RAMPS])("text-%s の size/weight/line-height が DESIGN.md と一致する", (name) => {
        const design = designRamp(name);
        const css = cssRamp(name);

        expect(css["font-size"], "font-size").toBe(design["fontSize"]);
        expect(css["font-weight"], "font-weight").toBe(design["fontWeight"]);
        expect(css["line-height"], "line-height").toBe(design["lineHeight"]);
        if (design["letterSpacing"]) {
            expect(css["letter-spacing"], "letter-spacing").toBe(design["letterSpacing"]);
        }
    });

    it("ramp の font-weight は 400/500 のみ (DESIGN.md §Typography)", () => {
        for (const name of TYPOGRAPHY_RAMPS) {
            const css = cssRamp(name);
            expect(["400", "500"], `text-${name} font-weight`).toContain(css["font-weight"]);
        }
    });
});

/**
 * 検査の**母集団**が DESIGN.md / tokens.css と集合一致していることを固定する。
 *
 * これが無いと「DESIGN.md に ramp や角丸を足したのに検査側の固定配列に入らず、
 * 誰も見ないまま通る」形が起きる (色だけは既存の set equality が守っていた)。
 */
describe("canonical source parity: 検査の母集団", () => {
    it("DESIGN.md typography の子キーと TYPOGRAPHY_RAMPS が集合一致する", () => {
        const names = designTypographyNames();
        expect(names.length, "ramp 名が 0 件 (抽出の空振り)").toBeGreaterThan(0);
        expect([...names].sort()).toEqual([...TYPOGRAPHY_RAMPS].sort());
    });

    it("tokens.css の @utility text-* と TYPOGRAPHY_RAMPS が集合一致する", () => {
        const utilities = [...tokensCss.matchAll(/@utility\s+text-([a-z0-9-]+)\s*\{/g)].map(
            (m) => m[1],
        );
        expect(utilities.length, "@utility が 0 件 (抽出の空振り)").toBeGreaterThan(0);
        expect([...utilities].sort()).toEqual([...TYPOGRAPHY_RAMPS].sort());
    });

    it("DESIGN.md rounded のキーと RADIUS_TOKENS が集合一致する", () => {
        expect([...designRounded().keys()].sort()).toEqual([...RADIUS_TOKENS].sort());
    });

    it("値検査を免除する派生色と DERIVED_COLOR_TOKENS が集合一致する", () => {
        // 契約: 派生色は全件が値免除である (DESIGN.md に期待値が無いため)。
        // 派生色を足したのに「値も見ていない・免除にも入っていない」状態を作れないようにする。
        expect(Object.keys(COMPILED_VALUE_EXEMPT_TOKENS).sort()).toEqual(
            [...DERIVED_COLOR_TOKENS].sort(),
        );
    });

    it("免除の理由が書かれている", () => {
        for (const [token, reason] of Object.entries(COMPILED_VALUE_EXEMPT_TOKENS)) {
            expect(reason.length, `${token}: 理由`).toBeGreaterThan(30);
        }
    });
});

/**
 * DESIGN.md frontmatter の節が、どの検査の担当かを既定拒否で固定する。
 *
 * 正本に節を足したのに誰も見ていない、という状態を作れないようにするための宣言。
 * 未検査の節は kind: "pending" として理由・解消条件・追跡先つきで登録する
 * (「検査があるから守られている」という誤読を防ぐ明示宣言であって免罪符ではない)。
 *
 * **kind: "checked" は「担当がいる」ことだけを表す**。節の中身の網羅は上の
 * 「検査の母集団」describe が別に固定している。
 */
describe("canonical source parity: frontmatter の節の担当宣言", () => {
    const sections = designFrontmatterSections();

    it("節が 0 件でない (抽出の空振り防止)", () => {
        expect(sections.length).toBeGreaterThan(0);
    });

    it("宣言と frontmatter の節が集合一致する (既定拒否)", () => {
        expect([...sections].sort(), "未宣言の節、または実在しない節の宣言がある").toEqual(
            Object.keys(FRONTMATTER_SECTION_OWNERS).sort(),
        );
    });

    it("metadata 宣言は理由を持ち、checked 宣言は担当 gate を 1 つ以上持つ", () => {
        for (const [section, owner] of Object.entries(FRONTMATTER_SECTION_OWNERS)) {
            if (owner.kind === "metadata") {
                expect(owner.reason.length, `${section}: reason`).toBeGreaterThan(0);
            }
            if (owner.kind === "checked") {
                expect(owner.by.length, `${section}: by`).toBeGreaterThan(0);
            }
        }
    });

    it("pending 宣言は理由・解消条件・追跡先をすべて埋めている", () => {
        for (const [section, owner] of Object.entries(FRONTMATTER_SECTION_OWNERS)) {
            if (owner.kind !== "pending") continue;
            expect(owner.reason.length, `${section}: reason`).toBeGreaterThan(30);
            expect(owner.exit.length, `${section}: exit`).toBeGreaterThan(30);
            expect(owner.tracking.length, `${section}: tracking`).toBeGreaterThan(0);
        }
    });

    it("pending の追跡先が実在する (書式だけ整った死んだ参照を作らせない)", () => {
        // TODO の ID は**表の ID 列**から取る。散文に現れた文字列や、
        // T1234 に含まれる T123 のような部分一致で通らないようにする。
        const todoIds = new Set(
            ["docs/TODO.md", "docs/TODO-closed.md"]
                .map((rel) => fs.readFileSync(path.join(REPO_ROOT, rel), "utf-8"))
                .join("\n")
                .split(/\r?\n/)
                .flatMap((line) => line.match(/^\|\s*(T\d{3,})\s*\|/)?.[1] ?? []),
        );
        expect(todoIds.size, "TODO の ID が 1 件も取れない (抽出の空振り)").toBeGreaterThan(0);

        for (const [section, owner] of Object.entries(FRONTMATTER_SECTION_OWNERS)) {
            if (owner.kind !== "pending") continue;
            const { tracking } = owner;

            if (/^T\d{3,}$/.test(tracking)) {
                expect(todoIds.has(tracking), `${section}: ${tracking} が TODO の表に無い`).toBe(
                    true,
                );
                continue;
            }
            expect(tracking, `${section}: 追跡先の書式`).toMatch(/^devnotes\/[\w.-]+\/$/);
            expect(
                fs.existsSync(path.join(REPO_ROOT, tracking)),
                `${section}: ${tracking} が実在しない`,
            ).toBe(true);
        }
    });
});
```

### tests/js/styles/design-system-docs.test.ts (renderedLines と fixture 部分)

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
    const start = lines.indexOf(heading);
    if (start < 0) return [];
    const level = (heading.match(/^#+/) ?? [""])[0].length;
    const rest = lines.slice(start + 1);
    const end = rest.findIndex((line) => new RegExp(`^#{1,${level}}\\s`).test(line));
    return end < 0 ? rest : rest.slice(0, end);
}

/**
 * Markdown 表の指定した列から、最初のバッククォート囲みの文字列を取り出す。
 *
 * 散文に同じ文字列を書いても通ってしまわないよう、**表の行のセル**だけを見る
 * (区切り行とヘッダー行はバッククォートを持たないので自然に落ちる)。
 */
function tableCellLiterals(section: readonly string[], column: number): readonly string[] {
    const literals: string[] = [];
    for (const line of section) {
        const trimmed = line.trim();
        if (!trimmed.startsWith("|")) continue;
        const cells = trimmed.split("|").slice(1, -1);
        const cell = cells[column];
        if (cell === undefined) continue;
        const literal = cell.match(/`([^`]+)`/)?.[1];
        if (literal !== undefined) literals.push(literal);
    }
    return literals;
}

/** 責務境界表に載っていなければならない検査ファイルの母集団。 */
function gateFiles(): readonly string[] {
    const stylesDir = path.join(REPO_ROOT, "tests/js/styles");
    const styles = fs
        .readdirSync(stylesDir)
        .filter((name) => name.endsWith(".test.ts"))
        .map((name) => `tests/js/styles/${name}`);

    for (const external of EXTERNAL_GATE_FILES) {
        // 明示登録したファイルが消えていたらここで落とす (行だけ残る状態を作らせない)。
        expect(
            fs.statSync(path.join(REPO_ROOT, external)).isFile(),
            `${external} が実在しない (EXTERNAL_GATE_FILES の登録が古い)`,
        ).toBe(true);
    }
    return [...styles, ...EXTERNAL_GATE_FILES].sort();
}

let doc: readonly string[];

beforeAll(() => {
    doc = renderedLines(fs.readFileSync(DOC_PATH, "utf-8"));
});

/* ===== ヘルパの仕様固定 (fixture) =====
 *
 * 「描画されない領域を潰す」という性質は本ファイルの検出力そのものなので、
 * 実文書だけを相手にすると「潰しが効いているから緑」なのか
 * 「潰さなくても緑」なのか区別できない。壊れた形を含む小さな文書で仕様を固定する。
 */

const RENDER_FIXTURE = [
    "## 節",
    "描画される本文",
    "<!-- 隠された本文 -->",
    "<!-- 複数行の",
    "隠された本文 -->行末は描画される",
    "```",
    "fenced の中の本文",
    "~~~",
    "```",
    "~~~~",
    "長い記号で開いた区間の中の ```",
    "~~~~",
    "   ```",
    "3 空白までは fence として扱う本文",
    "   ```",
    "```",
    "偽の終端の手前の本文",
    "    ```",
    "偽の終端の後ろの本文",
    "```",
    "```info`string",
    "無効な開始 fence の行は本文として残る",
    "```",
    "本物の fence の中の本文",
    "```",
    "<!-- 閉じないコメント",
    "ここも隠れる",
].join("\n");

describe("design-system-docs: 描画されない領域の除去 (fixture)", () => {
    const rendered = renderedLines(RENDER_FIXTURE);

    it("行数を保存する (節の切り出しがずれない)", () => {
        expect(rendered.length).toBe(RENDER_FIXTURE.split("\n").length);
    });

    it("HTML コメント・fenced code の中身が残らない", () => {
        const body = rendered.join("\n");
        expect(body).toContain("描画される本文");
        expect(body).toContain("行末は描画される");
        expect(body).not.toContain("隠された本文");
        expect(body).not.toContain("fenced の中の本文");
        expect(body).not.toContain("長い記号で開いた区間の中の");
        expect(body).not.toContain("3 空白までは fence として扱う本文");
        expect(body).not.toContain("ここも隠れる");
    });

    it("負のコントロール: 4 空白字下げの偽の終端では閉じない", () => {
        // 緩めると「区間の途中に偽の終端を置いて後続を描画される本文に見せかける」
        // 回避口ができる。ここが本ファイルの検出力そのものなので恒久的に固定する。
        const body = rendered.join("\n");
        expect(body).not.toContain("偽の終端の手前の本文");
        expect(body).not.toContain("偽の終端の後ろの本文");
    });

    it("負のコントロール: 情報文字列にバッククォートを含む行は開始 fence にならない", () => {
        // ここを fence 扱いにすると、次に来る本物の開始 fence を終端と誤認して
        // 区間が 1 つずれ、隠したい本文が描画される本文として通る。
        const body = rendered.join("\n");
        expect(body).toContain("無効な開始 fence の行は本文として残る");
        expect(body).not.toContain("本物の fence の中の本文");
    });

    it("負のコントロール: コメントを取り除いた跡で前後が繋がらない", () => {
        // 行内コードの中にコメントを置くと読者には離れて見えるのに、
        // 詰めて繋ぐと検査の上でだけ最小断片と一致してしまう。
        const spliced = renderedLines("`DESIGN.md が唯一<!-- 見える印 -->の真実`");
        expect(spliced.join("\n")).not.toContain("DESIGN.md が唯一の真実");
    });

    it("負のコントロール: 最小断片が元々空白を含む位置にコメントを置いても繋がらない", () => {
        // 跡に残す目印を空白にすると、この形が最小断片と一致してしまう
        // (`同一 PR 内で` の空白の位置にコメントを置く形)。
        const spliced = renderedLines("`同一<!-- 見える印 -->PR 内で`");
        expect(spliced.join("\n")).not.toContain("同一 PR 内で");

        const head = renderedLines("`DESIGN.md<!-- 見える印 --> が唯一の真実`");
        expect(head.join("\n")).not.toContain("DESIGN.md が唯一の真実");
    });

    it("隠れた行だけの節は本文が空とみなされる", () => {
        const onlyHidden = renderedLines(["## 節", "<!-- 隠された -->", "## 次"].join("\n"));
        const body = extractSection(onlyHidden, "## 節");
        expect(body.some((line) => line.trim() !== "")).toBe(false);
    });

    it("最小断片を描画されない領域へ移すと見つからなくなる", () => {
        const hidden = renderedLines(
            ["## 節", "```", "契約の最小断片", "```", "別の可視行", "## 次"].join("\n"),
        );
        const body = extractSection(hidden, "## 節").join("\n");
        expect(body).toContain("別の可視行");
        expect(body).not.toContain("契約の最小断片");
    });
});

describe("design-system-docs: 運用契約の節", () => {
    it.each([...REQUIRED_SECTIONS])("%s が存在し、本文を持つ", (heading) => {
        const body = extractSection(doc, heading);
        expect(body.length, `${heading} が見つからない`).toBeGreaterThan(0);
```

### resources/css/tokens.css (全文)

```css
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

### docs/design-system.md (全文)

```markdown
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

### DESIGN.md (frontmatter と §Colors / §状態色 / §Components 冒頭)

```markdown
---
version: "1.0"
name: Slate × Blue (Neutral)
description: テンプレート既定のニュートラルテーマ。中立的な青を主役に、無彩のスレートを支配色とする。アプリはこのファイルと tokens.css の値を差し替えてテーマを定義する。
colors:
    primary: "#2563EB"
    primary-hover: "#1D4ED8"
    tertiary: "#0F766E"
    tertiary-hover: "#115E59"
    neutral: "#F4F4F5"
    surface: "#FFFFFF"
    border: "#E4E4E7"
    border-strong: "#A1A1AA"
    text-primary: "#18181B"
    text-secondary: "#52525B"
    success: "#15803D"
    warning: "#B45309"
    danger: "#B91C1C"
typography:
    display:
...(typography/rounded/spacing 略)...
    lg: 24px
    xl: 40px
---

# Design System

本ファイルが**デザインの canonical source**。`resources/css/tokens.css` はその実装写像であり、
独自に値を変えてはいけない(同期契約は `docs/design-system.md`)。

## Overview

テンプレート既定のニュートラルテーマ。中立的な青(#2563EB)を主役、teal(#0F766E)を強アクセント、
無彩のスレート(#F4F4F5)を背景に据える。**アプリ固有のテーマは frontmatter の色値と
tokens.css の値を差し替えて定義する**(制約体系=影なし・最小色・ramp は維持したまま色だけ変える)。

## Colors

色は意味で割り当てる。順序や見た目の好みで使い分けない。

- **Primary(#2563EB)**: ブランドの中核。プライマリボタン、リンク、選択中のナビゲーション。
  1 画面の主要 CTA 以外には濫用しない。
  - tailwind: `bg-primary`, `text-primary`, `border-primary`、hover は `hover:bg-primary-hover`
- **Tertiary(#0F766E)**: 強いアクセント。緊急性・重要性のある前向き CTA、特別なバッジに限定。
  1 画面に 1 箇所が原則。
  - tailwind: `bg-tertiary`, `text-tertiary`, `border-tertiary`、hover は `hover:bg-tertiary-hover`
- **Neutral(#F4F4F5)**: 主要な背景色。画面全体はこの色で塗る。
  - tailwind: `bg-neutral`
- **Surface(#FFFFFF)**: カード・モーダル・浮いた要素の背景。Neutral との明度差で奥行きを出す。
  - tailwind: `bg-surface`
- **Border(#E4E4E7)**: 区切り線、入力欄の枠。常に細く(1px)。
  - tailwind: `border-border`
- **Border Strong(#A1A1AA)**: 区切りの強調、ghost ボタンの枠。
  - tailwind: `border-border-strong`
- **Text Primary(#18181B)**: 本文・見出しの主たる色。純黒は使わない。
  - tailwind: `text-text`(`--color-text` を参照)
- **Text Secondary(#52525B)**: 補足文、キャプション、ラベル。
  - tailwind: `text-text-secondary`

### 状態色

- **Success(#15803D)**: 完了・正常・公開済み。
  - tailwind: `text-success`, `bg-success`, `border-success`
- **Warning(#B45309)**: 注意・確認が必要・保留。
  - tailwind: `text-warning`, `bg-warning`, `border-warning`
- **Danger(#B91C1C)**: 失敗・破壊的操作・エラー。Tertiary とは別物
  (Tertiary は前向きな強調、Danger は否定的なシグナル)。
  - tailwind: `text-danger`, `bg-danger`, `border-danger`

状態色・アクセントは Tailwind の **-700 段**で揃える(`tertiary` teal-700 / `success` green-700 /
`warning` amber-700 / `danger` red-700)。`neutral`(#F4F4F5)や `surface`(#FFFFFF)の上で
**本文コントラスト 4.5:1** を確保するための下限であり、これより明るい段は使わない
(`tests/js/architecture/contrast-invariant.test.ts` が機械検証する)。

ソフト背景は状態色の opacity 修飾で表現する(`bg-success/10`, `bg-danger/10`,
`bg-primary-soft` 等)。**新しい色トークンを足す前に opacity 修飾と atom 化で表現できないか
検討すること**(追加条件は `docs/design-system.md` の 4 条件)。

## Typography

全ランプ Noto Sans JP。フォントウェイトは **400 と 500 の 2 階層のみ**(700 は使わない)。
コード・識別子・数値整列には `font-mono` を許可する(日本語 prose には使わない)。
...
素の `rounded`・`rounded-xl` 以上・任意値・方向別(`rounded-t-*` 等)は使わない。
完全円(`rounded-full`)はアバター/status dot/トグル等の**真に円形な UI に限る** ramp 外の例外で、
file-scoped allowlist で個別管理する。

## Components

> component 仕様は実装(`resources/js/components/`)と型定義が真実。本節は意味論と
> 使い分けルールのみを定義する。各 component を追加したら本節に追記すること。

### Button

実装: `components/atoms/Button.svelte`(仕様の真実は `Button.types.ts`)。

| variant | 用途 | スタイル要旨 |
|---------|------|------------|
| `primary` | 主要 CTA(1 画面 1 つ目安) | bg-primary + text-neutral |
```

### resources/js/components/atoms/Button.types.ts (VARIANT_CLASSES) と Badge.types.ts (TONE_CLASSES)

```ts
/** 全 variant が border (透明 or 色) を持ち外形高さを統一する (DESIGN.md) */
export const VARIANT_CLASSES = {
    "primary": "border-transparent bg-primary text-neutral hover:bg-primary-hover",
    "tertiary": "border-transparent bg-tertiary text-neutral hover:bg-tertiary-hover",
    "ghost": "border-border-strong bg-transparent text-text hover:border-primary hover:text-primary",
    "neutral": "border-border bg-neutral text-text hover:bg-border",
    "success": "border-transparent bg-success text-neutral hover:opacity-90",
    "danger": "border-transparent bg-danger text-neutral hover:opacity-90",
    "danger-outline": "border-danger bg-surface text-danger hover:bg-danger hover:text-neutral",
    "danger-ghost": "border-transparent bg-transparent text-danger hover:bg-danger/10",
} as const satisfies Record<ButtonVariant, string>;

export const SIZE_CLASSES = {
    sm: "px-3 py-1 text-caption",
    md: "px-4 py-2 text-body",
    lg: "px-6 py-3 text-body",
// --- Badge.types.ts ---

/**
 * 既定は soft (tone 色 12% 背景 + tone 色文字)。
 * primary は専用 token bg-primary-soft、他 tone は opacity 修飾 (/10) で soft 背景を表現する。
 * neutral は中立ラベル用 (bg-neutral + text-text-secondary)。
 */
export const TONE_CLASSES = {
    primary: "bg-primary-soft text-primary",
    tertiary: "bg-tertiary/10 text-tertiary",
    success: "bg-success/10 text-success",
    warning: "bg-warning/10 text-warning",
    danger: "bg-danger/10 text-danger",
    neutral: "bg-neutral text-text-secondary",
} as const satisfies Record<BadgeTone, string>;

/**
// --- input-state.ts ---
/**
 * 入力系 atom (Input / Textarea / Select) の共通スタイル定義。
 * 見た目の真実は DESIGN.md §Components。変更時は全入力 atom に波及することに注意。
 */

// 背景色は inputStateClass 側で確定させる (readonly と競合させないため base に置かない。
// Tailwind は同一プロパティの utility が並んだ場合、勝敗が class 属性の順ではなく
// 生成 CSS の順で決まるため、bg は常に 1 つだけ出力する)。
export const INPUT_BASE_CLASSES = [
    "w-full rounded-sm border text-body text-text",
    "px-3 py-1.5",
    "transition-colors duration-150",
    "placeholder:text-text-secondary/70",
    "focus:border-primary focus:ring-3 focus:ring-primary/20 focus:outline-none",
    "disabled:cursor-not-allowed disabled:bg-neutral disabled:text-text-secondary",
].join(" ");

/**
 * error / readonly の状態クラス。
 *
 * - error: border を danger 化する (readonly でも維持する = どのフィールドが不正か分かる)
 * - readonly: **編集できないことを面で示す**。ただし disabled とは意味が違うので同一にしない —
 *   readonly の値は生きている (送信される・選択してコピーできる・フォーカスできる) ため、
 *   文字色は通常のまま (`text-text`)、カーソルは `cursor-default`、focus ring は base のまま維持する。
 *   disabled は `text-text-secondary` + `cursor-not-allowed` + フォーカス不可 (base の disabled: 側)。
 *   `<select>` は HTML 仕様上 readonly を持たないため呼び出さない (既定 false)。
 */
export function inputStateClass(error: boolean, readonly = false): string {
    const border = error ? "border-danger ring-3 ring-danger/15" : "border-border-strong";
    return readonly ? `${border} bg-neutral cursor-default` : `${border} bg-surface`;
}
```

### tests/js/support/ds-purity.ts (CLASS_TOKEN_PATTERN 周辺 — 走査の区切り文字の宣言)

```ts
/** 指定ファイルの allowlist patterns を返す */
export function allowlistPatternsFor(relPath: string): readonly string[] {
    return FILE_SCOPED_ALLOWLIST.find((e) => e.file === relPath)?.patterns ?? [];
}

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

    return content.replace(CLASS_TOKEN_PATTERN, (token) =>
        allowedTokens.has(token) ? "" : token,
    );
}
```

### 逆引き表 (是正対象 token の全利用箇所)

# 付録: 是正対象 token の逆引き表 (Codex Round 1 Warning 5-1 への対応)

対象 token: `primary` / `primary-hover` / `primary-soft` / `tertiary` / `tertiary-hover` /
`success` / `warning` (`primary-soft` は `primary` の派生なので同時に動く)。

**表の作り方**: 走査単位 (文字列リテラル) ごとに、素の宣言を「通常」、修飾の連なりを持つ宣言を
その修飾の状態として、状態の内側で前景 × 背景の組を作った。
非テキストのプロパティ (`border-*` / `ring-*` / `decoration-*` / `accent-*`) は
i17 により本 gate の対象外なので、そう分かる形で並べてある。
**行番号は持たない** (s14 — 無関係な 1 行の追加で期待値の機械的な更新が常態化するため)。

**読み方**:
- `bg-*` が付いた行 = 是正後の値で AA を再計算した対象 (`contrast-measurements.md` 参照)
- `(背景は同じ宣言に無い)` = 前景だけの単位。親から継承する背景なので i22 (2) の保証外
- `(前景は同じ宣言に無い)` = 塗り面だけの単位。テキストを載せていない (アイコン・トラック・帯)
- `(非テキスト = i17 対象外)` = 枠線・focus ring・下線・フォーム部品のアクセント色

| ファイル | 状態 | 前景 | 背景 |
|---|---|---|---|
| `components/atoms/Alert.svelte` | hover | `ring-primary/35` | `(非テキスト = i17 対象外)` |
| `components/atoms/Alert.svelte` | hover | `text-text` | `(背景は同じ宣言に無い)` |
| `components/atoms/Alert.svelte` | 通常 | `border-primary` | `(非テキスト = i17 対象外)` |
| `components/atoms/Alert.svelte` | 通常 | `border-success` | `(非テキスト = i17 対象外)` |
| `components/atoms/Alert.svelte` | 通常 | `border-warning` | `(非テキスト = i17 対象外)` |
| `components/atoms/Alert.svelte` | 通常 | `ring-primary/35` | `(非テキスト = i17 対象外)` |
| `components/atoms/Alert.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/atoms/Alert.svelte` | 通常 | `text-success` | `(背景は同じ宣言に無い)` |
| `components/atoms/Alert.svelte` | 通常 | `text-text-secondary` | `(背景は同じ宣言に無い)` |
| `components/atoms/Alert.svelte` | 通常 | `text-warning` | `(背景は同じ宣言に無い)` |
| `components/atoms/Badge.types.ts` | 通常 | `border-primary` | `(非テキスト = i17 対象外)` |
| `components/atoms/Badge.types.ts` | 通常 | `border-success` | `(非テキスト = i17 対象外)` |
| `components/atoms/Badge.types.ts` | 通常 | `border-tertiary` | `(非テキスト = i17 対象外)` |
| `components/atoms/Badge.types.ts` | 通常 | `border-warning` | `(非テキスト = i17 対象外)` |
| `components/atoms/Badge.types.ts` | 通常 | `text-primary` | `bg-primary-soft` |
| `components/atoms/Badge.types.ts` | 通常 | `text-success` | `bg-success/10` |
| `components/atoms/Badge.types.ts` | 通常 | `text-tertiary` | `bg-tertiary/10` |
| `components/atoms/Badge.types.ts` | 通常 | `text-warning` | `bg-warning/10` |
| `components/atoms/Button.svelte` | 通常 | `ring-primary/35` | `(非テキスト = i17 対象外)` |
| `components/atoms/Button.types.ts` | hover | `border-primary` | `(非テキスト = i17 対象外)` |
| `components/atoms/Button.types.ts` | hover | `text-neutral` | `bg-primary-hover` |
| `components/atoms/Button.types.ts` | hover | `text-neutral` | `bg-tertiary-hover` |
| `components/atoms/Button.types.ts` | hover | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/atoms/Button.types.ts` | 通常 | `border-primary` | `(非テキスト = i17 対象外)` |
| `components/atoms/Button.types.ts` | 通常 | `text-neutral` | `bg-primary` |
| `components/atoms/Button.types.ts` | 通常 | `text-neutral` | `bg-success` |
| `components/atoms/Button.types.ts` | 通常 | `text-neutral` | `bg-tertiary` |
| `components/atoms/Button.types.ts` | 通常 | `text-text` | `(背景は同じ宣言に無い)` |
| `components/atoms/Checkbox.svelte` | 通常 | `accent-primary` | `(非テキスト = i17 対象外)` |
| `components/atoms/DragHandle.svelte` | 通常 | `ring-primary/35` | `(非テキスト = i17 対象外)` |
| `components/atoms/TextLink.svelte` | 通常 | `decoration-primary` | `(非テキスト = i17 対象外)` |
| `components/atoms/TextLink.svelte` | 通常 | `decoration-primary/30` | `(非テキスト = i17 対象外)` |
| `components/atoms/TextLink.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/atoms/Toggle.svelte` | 通常 | `(前景は同じ宣言に無い)` | `bg-primary` |
| `components/atoms/Toggle.svelte` | 通常 | `ring-primary/35` | `(非テキスト = i17 対象外)` |
| `components/atoms/input-state.ts` | 通常 | `border-primary` | `(非テキスト = i17 対象外)` |
| `components/atoms/input-state.ts` | 通常 | `ring-primary/20` | `(非テキスト = i17 対象外)` |
| `components/features/auth/PasskeySection.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/features/capture/CameraRecorder.svelte` | hover | `ring-primary/35` | `(非テキスト = i17 対象外)` |
| `components/features/capture/CameraRecorder.svelte` | hover | `text-text` | `(背景は同じ宣言に無い)` |
| `components/features/capture/CameraRecorder.svelte` | 通常 | `ring-primary/35` | `(非テキスト = i17 対象外)` |
| `components/features/capture/CameraRecorder.svelte` | 通常 | `text-text-secondary` | `(背景は同じ宣言に無い)` |
| `components/features/capture/TakeStrip.svelte` | 通常 | `(前景は同じ宣言に無い)` | `bg-primary` |
| `components/features/invitations/PendingInvitationList.svelte` | 通常 | `text-primary` | `bg-primary-soft` |
| `components/features/manual/AnalysisPanel.svelte` | 通常 | `(前景は同じ宣言に無い)` | `bg-primary` |
| `components/features/manual/RenderPanel.svelte` | 通常 | `(前景は同じ宣言に無い)` | `bg-primary` |
| `components/features/manual/ScenarioEditor.svelte` | 通常 | `(前景は同じ宣言に無い)` | `bg-primary` |
| `components/features/manual/ScenarioEditor.svelte` | 通常 | `text-success` | `(背景は同じ宣言に無い)` |
| `components/features/manual/TakePickerList.svelte` | 通常 | `(前景は同じ宣言に無い)` | `bg-primary-soft` |
| `components/features/manual/TakePickerList.svelte` | 通常 | `border-primary` | `(非テキスト = i17 対象外)` |
| `components/features/notifications/NotificationListItem.svelte` | 通常 | `(前景は同じ宣言に無い)` | `bg-primary` |
| `components/features/notifications/NotificationListItem.svelte` | 通常 | `(前景は同じ宣言に無い)` | `bg-primary-soft/40` |
| `components/features/notifications/NotificationListItem.svelte` | 通常 | `text-primary` | `bg-primary-soft` |
| `components/molecules/ApiKeyTabNav.svelte` | 通常 | `border-primary` | `(非テキスト = i17 対象外)` |
| `components/molecules/ApiKeyTabNav.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/molecules/Breadcrumb.svelte` | hover | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/molecules/Breadcrumb.svelte` | 通常 | `text-text-secondary` | `(背景は同じ宣言に無い)` |
| `components/molecules/CodeSnippet.svelte` | 通常 | `text-success` | `(背景は同じ宣言に無い)` |
| `components/molecules/OrganizationChoiceCard.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/molecules/PageHeaderSection.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/molecules/Pagination.svelte` | 通常 | `ring-primary/35` | `(非テキスト = i17 対象外)` |
| `components/molecules/Pagination.svelte` | 通常 | `text-neutral` | `bg-primary` |
| `components/molecules/PasswordInput.svelte` | hover | `ring-primary/35` | `(非テキスト = i17 対象外)` |
| `components/molecules/PasswordInput.svelte` | hover | `text-text` | `(背景は同じ宣言に無い)` |
| `components/molecules/PasswordInput.svelte` | 通常 | `ring-primary/35` | `(非テキスト = i17 対象外)` |
| `components/molecules/PasswordInput.svelte` | 通常 | `text-text-secondary` | `(背景は同じ宣言に無い)` |
| `components/molecules/PendingInvitationsNotice.svelte` | hover | `text-text` | `bg-primary-soft` |
| `components/molecules/PendingInvitationsNotice.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/molecules/PendingInvitationsNotice.svelte` | 通常 | `text-text` | `bg-primary-soft/40` |
| `components/molecules/PricingPlanCard.svelte` | 通常 | `border-primary` | `(非テキスト = i17 対象外)` |
| `components/molecules/PricingPlanCard.svelte` | 通常 | `border-warning` | `(非テキスト = i17 対象外)` |
| `components/molecules/PricingPlanCard.svelte` | 通常 | `text-success` | `(背景は同じ宣言に無い)` |
| `components/molecules/PricingPlanCard.svelte` | 通常 | `text-text` | `bg-warning/10` |
| `components/molecules/PricingPlanCard.svelte` | 通常 | `text-warning` | `(背景は同じ宣言に無い)` |
| `components/molecules/StatCard.svelte` | 通常 | `(前景は同じ宣言に無い)` | `bg-primary-soft` |
| `components/molecules/StatCard.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/molecules/Tabs.svelte` | 通常 | `border-primary` | `(非テキスト = i17 対象外)` |
| `components/molecules/Tabs.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/organisms/Modal.svelte` | hover | `ring-primary/35` | `(非テキスト = i17 対象外)` |
| `components/organisms/Modal.svelte` | hover | `text-text` | `(背景は同じ宣言に無い)` |
| `components/organisms/Modal.svelte` | 通常 | `ring-primary/35` | `(非テキスト = i17 対象外)` |
| `components/organisms/Modal.svelte` | 通常 | `text-text-secondary` | `(背景は同じ宣言に無い)` |
| `components/organisms/RecentAuthModal.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/organisms/ToastContainer.svelte` | hover | `ring-primary/35` | `(非テキスト = i17 対象外)` |
| `components/organisms/ToastContainer.svelte` | hover | `text-text` | `(背景は同じ宣言に無い)` |
| `components/organisms/ToastContainer.svelte` | 通常 | `border-primary` | `(非テキスト = i17 対象外)` |
| `components/organisms/ToastContainer.svelte` | 通常 | `border-success` | `(非テキスト = i17 対象外)` |
| `components/organisms/ToastContainer.svelte` | 通常 | `border-warning` | `(非テキスト = i17 対象外)` |
| `components/organisms/ToastContainer.svelte` | 通常 | `ring-primary/35` | `(非テキスト = i17 対象外)` |
| `components/organisms/ToastContainer.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/organisms/ToastContainer.svelte` | 通常 | `text-success` | `(背景は同じ宣言に無い)` |
| `components/organisms/ToastContainer.svelte` | 通常 | `text-text-secondary` | `(背景は同じ宣言に無い)` |
| `components/organisms/ToastContainer.svelte` | 通常 | `text-warning` | `(背景は同じ宣言に無い)` |
| `components/templates/AppLayout.svelte` | 通常 | `(前景は同じ宣言に無い)` | `bg-primary` |
| `components/templates/AppLayout.svelte` | 通常 | `ring-primary` | `(非テキスト = i17 対象外)` |
| `components/templates/AppLayout.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/templates/AuthLayout.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/templates/GuestLayout.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `components/templates/_helpers/SidebarNavItems.svelte` | 通常 | `(前景は同じ宣言に無い)` | `bg-primary` |
| `pages/Billing/PurchaseTickets.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `pages/Capture/Show.svelte` | 通常 | `ring-primary/35` | `(非テキスト = i17 対象外)` |
| `pages/Capture/Show.svelte` | 通常 | `text-text-secondary` | `(背景は同じ宣言に無い)` |
| `pages/Contact/Thanks.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `pages/Dashboard.svelte` | 通常 | `(前景は同じ宣言に無い)` | `bg-primary` |
| `pages/Debug/BfcacheTrial.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `pages/Debug/BfcacheTrialAway.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `pages/Debug/Login.svelte` | hover | `(前景は同じ宣言に無い)` | `bg-primary-soft` |
| `pages/Debug/Login.svelte` | 通常 | `(前景は同じ宣言に無い)` | `bg-surface` |
| `pages/Guest/Pricing.svelte` | hover | `text-primary` | `(背景は同じ宣言に無い)` |
| `pages/Guest/Pricing.svelte` | hover | `text-primary-hover` | `(背景は同じ宣言に無い)` |
| `pages/Guest/Pricing.svelte` | 通常 | `(前景は同じ宣言に無い)` | `bg-primary-soft` |
| `pages/Guest/Pricing.svelte` | 通常 | `border-primary/30` | `(非テキスト = i17 対象外)` |
| `pages/Guest/Pricing.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `pages/Guest/Pricing.svelte` | 通常 | `text-text` | `bg-primary-soft` |
| `pages/Guest/Pricing.svelte` | 通常 | `text-text-secondary` | `(背景は同じ宣言に無い)` |
| `pages/Invitations/Invalid.svelte` | 通常 | `text-warning` | `(背景は同じ宣言に無い)` |
| `pages/Onboarding/BillingRequired.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `pages/Onboarding/Checkout.svelte` | 通常 | `accent-primary` | `(非テキスト = i17 対象外)` |
| `pages/Onboarding/Checkout.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `pages/Onboarding/Checkout.svelte` | 通常 | `text-primary` | `bg-primary/10` |
| `pages/Organizations/ApiKeys/Index.svelte` | 通常 | `(前景は同じ宣言に無い)` | `bg-success/10` |
| `pages/Organizations/ApiKeys/Index.svelte` | 通常 | `border-success` | `(非テキスト = i17 対象外)` |
| `pages/Welcome.svelte` | hover | `text-primary` | `(背景は同じ宣言に無い)` |
| `pages/Welcome.svelte` | hover | `text-primary-hover` | `(背景は同じ宣言に無い)` |
| `pages/Welcome.svelte` | 通常 | `border-primary` | `(非テキスト = i17 対象外)` |
| `pages/Welcome.svelte` | 通常 | `text-primary` | `(背景は同じ宣言に無い)` |
| `pages/Welcome.svelte` | 通常 | `text-primary` | `bg-primary-soft` |
| `pages/Welcome.svelte` | 通常 | `text-success` | `(背景は同じ宣言に無い)` |
| `pages/Welcome.svelte` | 通常 | `text-success` | `bg-success/10` |
| `pages/Welcome.svelte` | 通常 | `text-text` | `bg-primary-soft` |
| `pages/Welcome.svelte` | 通常 | `text-text-secondary` | `(背景は同じ宣言に無い)` |
