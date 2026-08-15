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
まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4.24 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript / PHPStan level 10 / Pest / vitest

【この設計に固有の文脈】
- 変更対象はテストコード (Architecture テスト / vitest gate) のみ。アプリ実行時コードは 1 行も変えない。
- 「小さな掃除の束」であり、4 件の候補のうち 2 件を採用・2 件を不採用にする判断そのものが成果物である。
- 実測済みの事実 (PHP 8.4.24):
  * 別名つき import (use Foo as Bar; / use function strlen as sl; / use const PHP_VERSION as PV; / use \Baz as Qux;) は php -l で警告が出ない
  * 別名なしの 4 形は警告が出る / namespace { use Foo; } も警告が出る / namespace App; use Foo; は出ない
  * use\n Foo,\n Bar; は Foo と Bar の両方が「Foo の行」で報告される
  * php -l の警告は標準出力に出る。-n (php.ini を読まない) でも出る。拡張子 .php.txt のファイルも直接検査できる

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null 安全性）
2. 既存コードとの整合性（命名規約、パターン）
3. PHPStan level 10 適合性
4. テスト計画の網羅性（fail-first の順序、負の対照、空振り検知）
5. 副作用・後退リスク（既存テストの意味を変える箇所の妥当性）
6. 走査器の設計（class トークンの文字集合の定義は妥当か。名前空間の文脈追跡に穴は無いか）
7. 不採用判断（施策 3・施策 4）の根拠は十分か。落とすべきでないものを落としていないか
8. 保証範囲の記述が誇張になっていないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: p3-gate-fixes (既存 gate の是正)

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、
そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  (撮影者・教える人のスキルに品質を依存させない)。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

### 禁止事項 (AGENTS.md より)

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用

> 本設計はテストコード (Architecture テスト / vitest の gate) だけを変更する。
> `app/` `resources/js` の実行時コード・route・DB・設定には一切触れない。よって 3〜8 は構造的に無関係である。

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)
- **Pest** (`composer test`) / **vitest** (`pnpm test`)
- **RefreshDatabase** はグローバル適用。個別 `DatabaseTransactions` 禁止
- `declare(strict_types=1)` + 日本語コメント (git 追跡下の PHP 全数が対象)
- コードフォーマット: `composer fix` (Pint) / `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + TypeScript

## 概念設計リファレンス

`devnotes/20260816-0410-p3-gate-fixes/conceptual-design.md`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 許可語の除去を class トークンの完全一致にする | `tests/js/support/ds-purity.ts` / `tests/js/architecture/ds-purity.test.ts` | 中 |
| 2 | 非複合 global use gate の偽陽性是正と正典 t1 追従 | `tests/Architecture/NoNonCompoundGlobalUseTest.php` / `tests/Support/GlobalUse/*` (新設) / `tests/Architecture/fixtures/global-use/*.php.txt` (新設) | 中 |
| — | (不採用) enum ⇔ TS 同期の抽出器の置換 | なし | — |
| — | (不採用) 撤去物の不在 gate の字句走査の層 | なし | — |

---

## 施策 1: 許可語の除去を class トークンの完全一致にする

### 変更箇所

- `tests/js/support/ds-purity.ts` (L82-99 の型の説明 / L122-136 の `allowlistPatternsFor` と `stripAllowlisted`)
- `tests/js/architecture/ds-purity.test.ts` (自己検査と走査件数の床を追加)

### 波及変更

- TypeScript 型定義: `FileScopedAllowlistEntry.patterns` の**意味の説明**だけを直す (型そのものは `readonly string[]` のまま)
- API Resource/DTO: なし
- テストファイル: `tests/js/architecture/ds-purity.test.ts` (自己検査の追加)
- 実行時コード (`resources/js` 配下): **なし**。現行の許可語 `rounded-full` は
  `Avatar.svelte` (32 行 / 説明 4 行) と `Toggle.svelte` (36・49 行 / 説明 33 行) のいずれでも
  前後が空白か引用符の**素のトークン**なので、除去の意味を変えても検査結果は変わらない (実測で確認済み)

### 現行コード

```ts
/** 指定ファイルの allowlist patterns を返す */
export function allowlistPatternsFor(relPath: string): readonly string[] {
    return FILE_SCOPED_ALLOWLIST.find((e) => e.file === relPath)?.patterns ?? [];
}

/**
 * content から allowlist で許可された文字列を除去する (除去後に禁止パターンを適用する)。
 */
export function stripAllowlisted(relPath: string, content: string): string {
    let result = content;
    for (const pattern of allowlistPatternsFor(relPath)) {
        result = result.split(pattern).join("");
    }
    return result;
}
```

### 何が欠陥か

`split(pattern).join("")` は**素の部分文字列**の除去である。許可語を部分として含む別の語まで丸ごと消えるので、
その語が禁止パターンに掛かるはずでも掛からなくなる = **検出漏れ**になる。

| 入力 | 現行の除去後 | 禁止パターン | 現行の判定 | あるべき判定 |
|---|---|---|---|---|
| `!rounded-full` | `!` | `\brounded-(?:xs\|xl\|2xl\|3xl\|4xl\|full)\b` に掛かる | 素通り (緑) | **違反** |
| `sm:rounded-full` | `sm:` | 同上 | 素通り (緑) | **違反** |
| `rounded-full/50` | `/50` | 同上 | 素通り (緑) | **違反** |

許可したのは「アバターとトグルが真に円形であること」だけであり、
変種の修飾や重要度の修飾が付いた別の書き方まで許した覚えはない。現行はそこまで一緒に免罪している。

### 変更後コード

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
 * **保証しないもの (誇張しない)**: 丸括弧・`@`・カンマを含む書き方 (`bg-(--var)` / `@md:flex`) は
 * ここでトークンが割れるため、その形は許可一覧に**登録できない**。
 * 登録が要るようになったらこの文字集合を広げる (広げたら下の自己検査が必ず巻き添えで赤くなる)。
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
 * 素の部分文字列で除去すると、許可語を部分に含む別の語 (`!rounded-full` / `sm:rounded-full`) まで
 * 一緒に消えて**検出漏れ**になるためである (家系の裁定 AG-063)。
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

`FileScopedAllowlistEntry.patterns` の説明も直す:

```ts
    /**
     * 許可する class トークン (区切り文字で分割したトークンとの**完全一致**)。
     * 変種の修飾や重要度の修飾が付いた形 (`sm:rounded-full` / `!rounded-full`) は
     * **別のトークン**なので、必要ならそれ自体を 1 行足して登録する (自動では免罪しない)。
     */
    patterns: readonly string[];
```

### 自己検査 (負の対照。空振りを緑にしない)

`tests/js/architecture/ds-purity.test.ts` に `describe("allowlist の除去", ...)` を足す。
**旧実装なら緑になる入力を先に赤くしてから直す**順序で入れる (家系規約)。

| # | 種別 | 入力 | 期待 |
|---|---|---|---|
| 1 | 正 | `Avatar.svelte` + `class="rounded-full"` | 除去後に `rounded-full` を含まない |
| 2 | **負** | `Avatar.svelte` + `!rounded-full` | 除去後も `!rounded-full` が残り、`THEME_PATTERNS` の rounded 段規則に掛かる |
| 3 | **負** | `Avatar.svelte` + `sm:rounded-full` | 同上 |
| 4 | **負** | `Avatar.svelte` + `rounded-full/50` | 同上 |
| 5 | 正 | `Button.svelte` (許可一覧に無いファイル) + `rounded-full` | 除去されない (ファイル単位の免罪であることの固定) |
| 6 | 正 | `Avatar.svelte` + `"rounded-lg rounded-full shadow-lg"` | 除去後も `rounded-lg` と `shadow-lg` が**別のトークンとして**残る (連結しない) |
| 7 | 正 | `Avatar.svelte` + 引用符・改行・角括弧に隣接した `rounded-full` 3 形 | すべて除去される |
| 8 | 正 | `FILE_SCOPED_ALLOWLIST` の全エントリの全 `patterns` | `isSingleClassToken` が真 (死んだ登録を作らせない) |

### 走査の空振り検知

現行の `listFiles` は対象ディレクトリが読めないと空配列を返し、2 本の検査が**両方とも素通りで緑**になる。
床を固定する:

```ts
it("走査が空振りしていない (対象ファイルの床と代表ファイル)", () => {
    expect(allFiles.length).toBeGreaterThan(100); // 現在 154 本
    const rels = allFiles.map(relPath);
    expect(rels).toContain("components/atoms/Avatar.svelte");
    expect(rels).toContain("components/atoms/Toggle.svelte");
});
```

代表ファイルに許可一覧の 2 本を選ぶのは、**免罪の対象が母集団から落ちたら赤くする**ためである
(落ちると免罪が意味を失ったことに誰も気づかない)。

### テスト方針 (施策 1)

- **fail-first**: 先に上の表の負の対照 3 本 (#2/#3/#4) を書き、現行実装で**赤くなること**を確認してから
  `stripAllowlisted` を直す。赤くならなかったら「欠陥の理解が間違っている」ので設計へ戻る。
- 実ツリーが緑のままであることを `pnpm test` で確認する
  (現行の `rounded-full` は 5 箇所すべて素のトークンなので、意味を変えても判定は変わらない見込み。
  もし赤くなったら、その箇所は**本当に免罪すべきでなかった**書き方なので、許可一覧へ足すのではなく書き方を直す)。
- 実行コマンド: `pnpm test` / `pnpm lint` / `pnpm typecheck`

### リスク

- **免罪が狭くなる方向の変更**なので、想定外の赤が出るとすれば「これまで黙って許されていた書き方」である。
  そのときは書き方を直すのが原則で、許可一覧を広げるのは最後の手段とする (理由・撤去条件の記入が要る既存の枠に従う)。
- 追従元 (テンプレート) が確定させた標準形と同じ形になるので、逸脱の登録は不要。
  motivation が採った別形 (重要度の修飾を排他 3 択でマッチに取り込む) は採らない。

---

## 施策 2: 非複合 global use gate の偽陽性是正と正典 t1 追従

### 背景 (実測)

手元の PHP 8.4.24 で `php -l` を取り直した結果 (本設計の判断の土台):

| 書き方 | 警告 | 現行 gate の判定 | 齟齬 |
|---|---|---|---|
| `use Foo;` / `use function strlen;` / `use const PHP_VERSION;` / `use \Baz;` | **出る** | 違反 | 一致 |
| `use Foo as Bar;` / `use function strlen as sl;` / `use const PHP_VERSION as PV;` / `use \Baz as Qux;` | **出ない** | 違反 | **偽陽性** |
| `namespace { use Foo; }` (明示的なグローバル名前空間) | **出る** | 対象外 (見逃す) | **検出漏れ** |
| `namespace App; use Foo;` / `namespace App { use Foo; }` | 出ない | 対象外 | 一致 |
| `use Foo, Bar as B, Baz;` | Foo と Baz にだけ出る | 3 件すべて違反 | **偽陽性 1 件** |
| 複数行の `use\n Foo,\n Bar;` | 両方とも **Foo の行**で報告 | 要素ごとの実際の行 | 行番号の仕様差 |

### 変更箇所

| ファイル | 変更 |
|---|---|
| `tests/Support/GlobalUse/NonCompoundGlobalUseScanner.php` | **新設**。走査器 (純関数) を test ファイルから切り出す |
| `tests/Support/GlobalUse/PhpLintOracle.php` | **新設**。`php -l` を真値として非複合名と行番号を取り出す |
| `tests/Architecture/fixtures/global-use/*.php.txt` | **新設**。見本 11 本 (検出 6 / 無違反 5) |
| `tests/Architecture/NoNonCompoundGlobalUseTest.php` | 走査器の呼び出し・見本の照合・母集団の pin に専念する形へ書き換え |

母集団の列挙は本日 `tests/Support/TrackedPhpSourceFiles.php` へ集約済みなので**触らない**
(`TrackedPhpSourceFiles::all(base_path())` をそのまま使い続ける)。

### 変更 1 — 別名つきの import を対象外にする (偽陽性の是正)

現行は `as` を見た時点で「そこまでに集めた名前」を violations へ積んでいる (172-175 行)。

```php
            if ($current->is(T_AS)) {
                $flush();
                $collecting = false; // `as` 以降の別名は判定対象ではない
                continue;
            }
```

別名が付いた要素は PHP が警告を出さない = 正典 t1 の真値では違反ではないので、
**要素ごとに「別名が付いたか」を持ち、付いていたら報告しない**形へ改める。

```php
            if ($current->is(T_AS)) {
                $aliased = true;   // この要素は import として実際に効く = 違反ではない
                $collecting = false;
                continue;
            }
```

`$flush()` は `,` と `;` でだけ呼び、`$aliased` が真なら報告せずに捨てる。

### 変更 2 — 名前空間の文脈を追う (検出漏れの解消)

現行はファイル先頭から `T_NAMESPACE` を 1 個でも見つけた時点で `scanned=false` を返す (89-94 行)。
この形では `namespace { ... }` の内側を**原理的に**検出できない。

追う状態は 3 つだけにする (それ以上は要らない)。

| 状態 | 意味 |
|---|---|
| `$namespaceName` | 現在有効な名前空間。`''` がグローバル |
| `$bodyDepth` | 現在の名前空間の直下にあたる波括弧の深さ (波括弧なしの宣言なら 0、`namespace … { }` なら 1) |
| `$blockOpenDepth` | 波括弧つき宣言を開いたときの深さ (`null` なら波括弧なし)。閉じ括弧でこの深さに戻ったらグローバルへ復帰 |

判定は「**`$namespaceName === ''` かつ 現在の深さ === `$bodyDepth`**」の `use` だけを見る、の 1 本になる。
これでクラス本体の trait 取り込み (深さが深い) とクロージャの `use ($x)` (次のトークンが `(`) は
現行と同じ理由でそのまま除外され、`namespace { use Foo; }` は拾えるようになる。

`namespace` の宣言の形が読めなかった場合 (トークンが尽きた等) は**黙って対象外にせず**、
`unresolved` として返して gate を赤くする (fail-closed)。

戻り値の shape:

```php
/**
 * @return array{
 *   violations: list<array{name: string, line: int}>,
 *   hasGlobalRegion: bool,
 *   unresolved: list<string>,
 * }
 */
```

### 変更 3 — 行番号を `php -l` の規則へ合わせる

実測のとおり、カンマ区切りの要素は**その `use` 文で最初に現れた名前トークンの行**で報告される。
走査器も 1 つの `use` 文の中では `$statementLine` を共有し、要素ごとの行を使わない。
この規則そのものを見本 (`detects-comma-list`) が固定する。

### 変更 4 — `php -l` を真値とする自己検査

```php
final class PhpLintOracle
{
    /**
     * 見本ファイルに対して php -l を実行し、非複合名の警告を (名前, 行) で返す。
     *
     * 実行系は **いまテストを走らせている PHP そのもの** (PHP_BINARY) を使う。
     * 別の php を探しに行かないので「手元と CI で版が違うと結果が変わる」問題は起きない
     * (その実行系が警告を出す形を、その実行系で検出できているかを見る検査になる)。
     * `-n` で php.ini を読ませない (opcache 等の状態に左右されない)。
     * 警告は **標準出力**へ出る (実測)。
     *
     * @return list<array{name: string, line: int}>
     */
    public static function nonCompoundWarnings(string $absolutePath): array
}
```

- 実行: `PHP_BINARY -n -d error_reporting=E_ALL -d display_errors=1 -d log_errors=0 -l <path>`
- 取り出し: `/non-compound name '([^']+)' has no effect in .+ on line (\d+)/`
- 見本の拡張子は **`.php.txt`** にする。`.php` にすると
  この gate 自身 (`git ls-files -- '*.php'`) と `StrictTypesDeclarationGateTest` /
  `ForbiddenStatementTokenInvariantTest` の母集団に入り、**わざと違反させた見本で本番の gate が赤くなる**。
  `php -l` は拡張子を見ないので `.php.txt` のまま直接検査できる (実測確認済み)。

照合する検査を 3 本置く:

1. **一致**: 検出 6 本の見本について、走査器の `violations` と真値が **(名前, 行) の集合として完全一致**する
2. **無違反**: 無違反 5 本の見本について、真値が 0 件であり、走査器も 0 件である
3. **真値の空振り検知**: 検出 6 本の見本から得た真値の総数が 0 件なら赤くする
   (`php -l` の警告文面が将来変わったら、照合が「両方 0 件で一致」して静かに無力化するため)

### 見本 11 本

| ファイル (`tests/Architecture/fixtures/global-use/`) | 中身 | 期待 |
|---|---|---|
| `detects-class.php.txt` | `use Foo;` | 1 件 |
| `detects-function-const.php.txt` | `use function strlen;` / `use const PHP_VERSION;` | 2 件 |
| `detects-leading-backslash.php.txt` | `use \Foo;` / `use function \strlen;` / `use const \PHP_VERSION;` | 3 件 |
| `detects-comma-list.php.txt` | 複数行に散らした `use\n Foo,\n Bar;` | 2 件 (**両方とも Foo の行**) |
| `detects-partial-alias.php.txt` | `use Foo, Bar as B, Baz;` | 2 件 (`Bar` は入らない) |
| `detects-bracketed-global.php.txt` | `namespace { use Foo; use function strlen; class A { use T; } $f = function () use ($x) {}; }` | 2 件 |
| `clean-compound.php.txt` | 複合名 / グループ use / 先頭 `\` つき複合名 | 0 件 |
| `clean-aliased.php.txt` | 別名つきの 4 形 | 0 件 |
| `clean-named-namespace.php.txt` | `namespace App; use Foo;` | 0 件 |
| `clean-bracketed-named.php.txt` | `namespace App { use Foo; }` | 0 件 |
| `clean-trait-and-closure.php.txt` | 名前空間なしのファイルでの trait 取り込みとクロージャの `use ($x)` | 0 件 |

見本の**本数と名前の一覧**を検査で表明する (差し替え・こっそり削除で検出力が落ちるのを止める)。

### 既存テストの扱い (削除しない)

現行の 6 本の対照テストは heredoc を入力にしている。真値と照合するには
**PHP として実行できるファイル**が要るので、入力を見本ファイルへ移し、**テストの意図と件数は引き継ぐ**。
対応は次のとおりで、失われる検査は 1 つも無い。

| 現行のテスト | 移した先 | 期待の変化 |
|---|---|---|
| 負: class / function / const を検出する | `detects-class` + `detects-function-const` | 3 件 → 1 + 2 件 (合計同じ) |
| 負: カンマ区切り / as 別名も検出する | `detects-comma-list` + `detects-partial-alias` | **3 件 → 2 + 2 件。意味が変わる (下記)** |
| 負: 先頭バックスラッシュ付きも検出する | `detects-leading-backslash` | 3 件のまま |
| 正: 複合名 / グループ use / 先頭 `\` 付き複合名 | `clean-compound` (+ 別名つきは `clean-aliased` へ分離) | 0 件のまま |
| 正: namespace 付きファイルは対象外 | `clean-named-namespace` | 0 件のまま |
| 正: trait use / クロージャ use を誤検知しない | `clean-trait-and-closure` | 0 件のまま |
| 走査が空振りしていない | そのまま (母集団の床) | 変更なし |

**意味を変える 1 件の理由 (テストファイルにそのまま残す)**:
現行の「カンマ区切り / as 別名の非複合 use も検出する」は
`use RuntimeException, LogicException;` と `use InvalidArgumentException as Bad;` で 3 件を期待している。
このうち **別名つきの 1 件は誤りである** — PHP 8.4.24 の `php -l` は別名つきの形に警告を出さない
(別名が付いた import は実際に効くので、この gate が防ぐ事故は起きない)。
正典 t1 は `php -l` を真値と定めているので、期待値ごと正す。
別名つきが**違反にならないこと**は `clean-aliased` が 4 形で固定するので、検査の網は減らない。

### 母集団の pin (走査の空振り検知)

現行の「走査が空振りしていない」を引き継ぎ、次を固定する。

- 追跡下 PHP の総数 > 1000 (現在 1638 本)
- グローバル領域を持つファイル数 > 0 (`database/migrations` などが構造的に必ず該当する)
- `unresolved` が空であること

### PHPStan 適合チェック

- [x] 走査器・真値取得の戻り値は `array{...}` の shape で宣言する (level 10)
- [x] `null` 安全: `PhpToken::tokenize()` の戻りは `list<PhpToken>` で宣言、`preg_match_all` の失敗を明示的に扱う
- [x] 外部プロセス実行は `Symfony\Component\Process\Process` を使い、失敗時は例外にする (fail-closed)
- [x] DTO 返却の要件は本設計の対象外 (テスト補助であり HTTP 応答を作らない)

### テスト方針 (施策 2)

- **fail-first の順序**:
  1. 先に `detects-bracketed-global` と `clean-aliased` の見本と照合検査を入れ、
     **現行の走査器で赤くなること**を確認する (前者は見逃し、後者は誤検出で落ちる)。
  2. そのあと走査器を直して緑にする。
  3. 最後に真値の空振り検知を入れ、真値の取り出し規則をわざと壊すと赤くなることを手元で 1 度確かめる。
- 既存の実ツリー走査 (追跡下 PHP 1638 本) は**現存する違反 0 件**なので、
  変更後も 0 件のままであることを確認する。増える方向の変更 (`namespace { }` の検出) が入るため、
  実装前に**新しい走査器で実ツリーを 1 度走らせて件数を確かめる** (0 件でなければ、その場所を直してから gate を締める)。
- 実行コマンド: `composer test -- --filter=NoNonCompoundGlobalUse` → `composer test` / `composer phpstan` / `vendor/bin/pint --test`

### リスク

- **`php -l` を毎回 11 回実行する**ので、この検査だけ実行時間が伸びる (1 回あたり数十ミリ秒程度)。
  見本は 11 本に絞ってあり、実ツリー走査 (1638 本) には `php -l` を使わない (真値は見本にだけ使う) ので影響は限定的である。
- 警告文の文言が PHP の将来版で変わると真値が取れなくなる。**そのときは空振り検知が赤くなる**ので、
  静かに無力化することはない (これが検査 3 本目を置く理由である)。
- 名前空間の文脈追跡は現行より複雑になる。誤りは見本 11 本と真値照合が受け止める。
  想定外の構文で静かに素通りしないよう、読めなかった宣言は `unresolved` にして赤くする。

---

## 本設計から落とした施策と、その行き先

### 施策 3 (不採用): enum ⇔ TypeScript 同期の抽出器を型情報ベースへ置換

- **落とす理由**: 台帳の指摘は事実だが、この束の前提 (既にある検査の精度を上げる小さな是正) が崩れている。
  正典 v2 は抽出器の置換だけでなく、**発見 → 分類 → 検査**の 3 段構成と**逆走査 2 規則**を必須にしており、
  型情報を使う抽出には TypeScript コンパイラが要る = 検査を PHP のレーンから JavaScript のレーンへ作り直す作業になる。
  他 2 施策と規模が 1 桁違い、束ごと大きくしてしまう (思考原則 2)。
- **抽出器だけ広げる案も採らない**: 現行の対象 6 件はすべて二重引用符の型宣言であり、
  単一引用符や定数配列に広げても呼ぶ側が 1 つも無い = 検出力は増えない (「あったら便利」は作らない)。
- **現行に静かに間違える穴は無い**ことは実物で確認した。読めない書き方に当たると値 0 件で例外を投げて落ちる。
  実際の弱点は「対象が手書きの 6 件しか無く、新しい列挙は永久に対象外」という**網羅の欠落**である
  (例: `VideoManualStatus` / `TakeStatus` / `AnalysisStep` / `ScenarioConflictType` / `PlanCode` /
  `DashboardState` などの写しが現に無検査で残っている)。
- **申し送り**: この網羅の欠落は実在するので、**別の TODO として起票する**。本設計には含めない。

### 施策 4 (不採用): 撤去物の不在 gate に字句走査の層を足す

**作らない**。理由は 4 つで、いずれも実物を読んで確かめた。

1. **撤去したのは名前ではなく結線である**。`password.confirm` という名前は現役で残っている —
   route 名 `password.confirm.store` が実在し (`app/Providers/FortifyServiceProvider.php` の流量制限の割当と
   `tests/Architecture/ThrottleLaneAssignmentTest.php` の一覧)、画面題の対応表 (`config/seo.php`) にも項目がある。
   したがって「撤去した名前が 1 件も残っていない」を 0 件で固定することが**そもそもできない**。
   例外一覧を積めば書けるが、正典が求める「許可一覧 0 件固定」と正反対の形になる。
2. **復活の経路が字句では見えない**。復活は依存パッケージの既定値に戻ること =
   設定行 `'confirmPassword' => false` の**削除**で起きる。
   「撤去した語が現れたら落とす」検査は、語が**消えること**では原理的に鳴らない。
3. **その経路は既に機械で固定されている**。`tests/Architecture/PasskeyPackageContractTest.php` が
   `fortify-options.passkeys.confirmPassword` が false であること、`passkeys.management_middleware` が空であること、
   設定の往復後も同じであることを固定している。実行時の層 (`PasswordConfirmMiddlewareAbsenceTest`) と
   設定値の層の 2 枚が既に掛かっている。
4. **同種の機構は既にある**。字句で撤去物の再流入を止める作法は
   `tests/Architecture/RetiredRecoveryReferenceGateTest.php` が持っている。
   当たらない対象のために 2 本目を作るのは二重の機構である。

台帳の「字句走査の層が無い」という観測自体は正しい。その層が効くのは
「撤去した名前が丸ごと消えている対象」であり、本件は該当しないというのが本設計の結論である。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 施策 1 は JavaScript レーン、施策 2 は PHP レーンで、変更ファイルが 1 つも重ならない。どちらも既存の検査 1 本の中で閉じており、段階的に入れて各段で全レーンを緑にできる |
| 競合リスク | 低い。`tests/Support/TrackedPhpSourceFiles.php` は**読むだけで変更しない**ので、同ファイルを触る他作業とも衝突しない。施策 1 が触る 2 ファイルは他の設計の変更対象になっていない |

---

## 関連する現行コード

### tests/js/support/ds-purity.ts (抜粋: 78-136 行)
/**
 * file-scoped allowlist。例外は必ず理由・撤去条件・lifecycle を持つ。
 * lifecycle: permanent = 恒久例外 (brand 色等) / transitional = 撤去予定 (撤去条件必須)。
 */
export interface FileScopedAllowlistEntry {
    /** resources/js からの相対パス */
    file: string;
    /** 許可する class 文字列 (完全一致部分文字列) */
    patterns: readonly string[];
    reason: string;
    owner_phase: string;
    remove_condition: string;
    reason_classes: ReadonlyArray<
        | "domain_semantic_color"
        | "brand_guideline"
        | "legacy_layout_dependency"
        | "animation_keyframe"
        | "a11y_requirement"
        | "truly_circular_ui"
    >;
    lifecycle: "permanent" | "transitional";
}

export const FILE_SCOPED_ALLOWLIST: readonly FileScopedAllowlistEntry[] = [
    {
        file: "components/atoms/Avatar.svelte",
        patterns: ["rounded-full"],
        reason: "アバターは真に円形な UI (DESIGN.md §Shapes の ramp 外例外)",
        owner_phase: "template",
        remove_condition: "なし (アバターが真円である限り恒久)",
        reason_classes: ["truly_circular_ui"],
        lifecycle: "permanent",
    },
    {
        file: "components/atoms/Toggle.svelte",
        patterns: ["rounded-full"],
        reason: "トグルスイッチのトラックとつまみは真に円形な UI (DESIGN.md §Shapes の ramp 外例外)",
        owner_phase: "template",
        remove_condition: "なし (真に円形な UI である限り恒久)",
        reason_classes: ["truly_circular_ui"],
        lifecycle: "permanent",
    },
];

/** 指定ファイルの allowlist patterns を返す */
export function allowlistPatternsFor(relPath: string): readonly string[] {
    return FILE_SCOPED_ALLOWLIST.find((e) => e.file === relPath)?.patterns ?? [];
}

/**
 * content から allowlist で許可された文字列を除去する (除去後に禁止パターンを適用する)。
 */
export function stripAllowlisted(relPath: string, content: string): string {
    let result = content;
    for (const pattern of allowlistPatternsFor(relPath)) {
        result = result.split(pattern).join("");
    }
    return result;
}

### tests/js/architecture/ds-purity.test.ts (全文)
import { describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import {
    THEME_PATTERNS,
    UNIVERSAL_PATTERNS,
    stripAllowlisted,
} from "../support/ds-purity";

/**
 * resources/js 配下 (components / pages / lib) の DS purity を機械検証する。
 *
 * - UNIVERSAL_PATTERNS: token 迂回の禁止 (テーマ非依存、常時適用)
 * - THEME_PATTERNS: 既定テーマ由来の制約 (影/gradient/rounded ramp/typography ramp)
 *
 * 例外は tests/js/support/ds-purity.ts の FILE_SCOPED_ALLOWLIST で管理する (出荷時 0 件)。
 * inline SVG の統制は svg-inline-allowlist.test.ts (atoms/icons/ 例外 + allowlist) が担う。
 */

const JS_ROOT = path.resolve(__dirname, "../../../resources/js");
const SCAN_EXTENSIONS = new Set([".svelte", ".ts"]);

function listFiles(dir: string): string[] {
    if (!fs.existsSync(dir)) return [];
    const files: string[] = [];
    for (const entry of fs.readdirSync(dir, { withFileTypes: true, recursive: true })) {
        if (!entry.isFile()) continue;
        if (!SCAN_EXTENSIONS.has(path.extname(entry.name))) continue;
        files.push(path.join(entry.parentPath, entry.name));
    }
    return files;
}

function relPath(file: string): string {
    return path.relative(JS_ROOT, file);
}

const allFiles = listFiles(JS_ROOT);

describe("DS purity", () => {
    it("UNIVERSAL: token 迂回 (raw palette / hex / arbitrary z / 静的 inline style) が無い", () => {
        const violations: string[] = [];
        for (const file of allFiles) {
            const content = stripAllowlisted(relPath(file), fs.readFileSync(file, "utf-8"));
            for (const [pattern, message] of UNIVERSAL_PATTERNS) {
                const m = content.match(pattern);
                if (m) {
                    violations.push(`${relPath(file)}: "${m[0]}" — ${message}`);
                }
            }
        }
        expect(violations).toEqual([]);
    });

    it("THEME: 既定テーマの制約 (影/gradient/scale/rounded ramp/typography ramp) を満たす", () => {
        const violations: string[] = [];
        for (const file of allFiles) {
            const content = stripAllowlisted(relPath(file), fs.readFileSync(file, "utf-8"));
            for (const [pattern, message] of THEME_PATTERNS) {
                const m = content.match(pattern);
                if (m) {
                    violations.push(`${relPath(file)}: "${m[0]}" — ${message}`);
                }
            }
        }
        expect(violations).toEqual([]);
    });
});

### tests/Architecture/NoNonCompoundGlobalUseTest.php (全文)
<?php

declare(strict_types=1);

use Tests\Support\TrackedPhpSourceFiles;

/*
 * Architecture invariant: **namespace 宣言の無い PHP ファイル**の global スコープに
 * 非複合名の `use` を書かない。
 *
 * SoT = PHP の言語仕様。namespace 無しファイルでの非複合 use は
 *   Warning: The use statement with non-compound name 'X' has no effect
 * を出し、**import として何の効果も持たない** (参照は global にフォールバックする)。
 * `use function` / `use const` でも **まったく同じ warning** が出る (実測):
 *   use RuntimeException;   → Warning ...'RuntimeException'...
 *   use function strlen;    → Warning ...'strlen'...
 *   use const PHP_VERSION;  → Warning ...'PHP_VERSION'...
 *   use function Foo\bar;   → (複合名なので正常)
 *
 * なぜ「出力が汚れるだけ」で済ませないか (実測):
 *   - この warning が set_error_handler に届くかは **環境依存** (opcache 状態 /
 *     ファイルの初回コンパイル時点)。同一 devcontainer で「届く」「届かない」両方を観測した
 *   - 届いた場合、Laravel の HandleExceptions::handleError は
 *     `error_reporting() & $level` (本アプリは -1) で **ErrorException を throw する**
 *   - migration は Migrator が実行時に require する = そこで throw されれば
 *     RefreshDatabase が死に **全テストが全滅する**
 * つまり「今日は raw output 汚染で済んでいるが、いつ全滅へ化けてもおかしくない非決定的な地雷」。
 *
 * 走査対象: git 追跡下の *.php (ただし *.blade.php を除く)。列挙は
 * `Tests\Support\TrackedPhpSourceFiles` に集約してある (同じ列挙を 2 本持たない。
 * 走査域の定義と限界は同クラスの docblock が正本)。
 * git 管理下に限ることで vendor/ node_modules/ .claude/worktrees/ storage/ を
 * **自動的に**除外できる (明示 exclude リストを保守しなくてよい)。
 * **既知の限界**: 未追跡 (git add 前) のファイルは走査されない。gate が守る境界は
 * commit / CI であり、そこでは必ず追跡下にあるため実効性は損なわれない。
 * git 不在は環境不備として silent skip せず fail させる
 * (tests/js/architecture/pages-path-case-invariant.test.ts と同じ作法)。
 *
 * allowlist は設けない: 非複合 global use に正当な用途は存在しない (常に無効な import)。
 */

/**
 * index 以降で最初の significant token の index。
 *
 * @param  list<PhpToken>  $tokens
 */
function nonCompoundUseNextSignificant(array $tokens, int $index): ?int
{
    $count = count($tokens);
    for ($i = $index; $i < $count; $i++) {
        if (! $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            return $i;
        }
    }

    return null;
}

/**
 * 1 ファイル分の PHP ソースから global スコープの非複合 use を収集する (純関数)。
 *
 * 判定手順:
 *   1. T_NAMESPACE が出現するファイルは対象外 (PHP が warning を出さない = 実際に import される)
 *   2. brace depth を追跡し **depth 0 の T_USE** のみを見る (クラス本体の trait use を除外)
 *   3. `use` 直後の `function` / `const` 修飾は読み飛ばす (同じ warning が出るため対象)
 *   4. `(` が続くならクロージャの `use ($x)` なので対象外
 *   5. カンマ区切りの各要素について、`as` の前の import 名を **1 つの文字列に正規化**し、
 *      先頭の `\` を除いた残りに区切り `\` を含まなければ非複合 = 違反
 *
 * **名前の正規化が必須である理由 (実測)**: PHP は `use \RuntimeException;` のような
 * 先頭 `\` 付きの単一名も受理し、**まったく同じ warning を出す**:
 *   use \RuntimeException;    → Warning ...non-compound name 'RuntimeException'...
 *   use function \strlen;     → Warning ...non-compound name 'strlen'...
 *   use const \PHP_VERSION;   → Warning ...non-compound name 'PHP_VERSION'...
 * しかも tokenizer 上は **T_STRING ではなく T_NAME_FULLY_QUALIFIED** になる:
 *   `use \RuntimeException;`  → T_USE, T_NAME_FULLY_QUALIFIED('\RuntimeException'), ';'
 *   `use RuntimeException;`   → T_USE, T_STRING('RuntimeException'), ';'
 * よって「T_STRING かどうか」で判定すると **先頭 `\` 付きを丸ごと取りこぼす** (silent hole)。
 * token 種別ではなく **名前の中身 (セグメント数)** で判定する。
 *
 * @return array{violations: list<string>, scanned: bool}
 */
function nonCompoundUseCollectFromSource(string $source, string $relative): array
{
    /** @var list<PhpToken> $tokens */
    $tokens = PhpToken::tokenize($source);
    $count = count($tokens);

    // 1. namespace 付きファイルは対象外
    foreach ($tokens as $token) {
        if ($token->is(T_NAMESPACE)) {
            return ['violations' => [], 'scanned' => false];
        }
    }

    $violations = [];
    $depth = 0;

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if ($token->text === '{') {
            $depth++;

            continue;
        }
        if ($token->text === '}') {
            $depth--;

            continue;
        }

        // 2. global スコープの use だけを見る
        if (! $token->is(T_USE) || $depth !== 0) {
            continue;
        }

        $cursor = nonCompoundUseNextSignificant($tokens, $i + 1);
        if ($cursor === null) {
            continue;
        }

        // 4. クロージャの `use ($x)` は import ではない
        if ($tokens[$cursor]->text === '(') {
            continue;
        }

        // 3. `use function` / `use const` の修飾を読み飛ばす (対象に含める)
        if ($tokens[$cursor]->is([T_FUNCTION, T_CONST])) {
            $next = nonCompoundUseNextSignificant($tokens, $cursor + 1);
            if ($next === null) {
                continue;
            }
            $cursor = $next;
        }

        // 5. カンマ区切りの各 import 要素を評価する。
        //    名前は「1 要素 = 1 文字列」に正規化してからセグメント数で判定する
        //    (T_STRING / T_NAME_QUALIFIED / T_NAME_FULLY_QUALIFIED / T_NS_SEPARATOR 分割の
        //     いずれの tokenizer 表現でも同じ結論になる)。
        $name = '';
        $nameLine = 0;
        $collecting = true;

        /** 収集済みの名前を判定して violations へ積む。 */
        $flush = function () use (&$name, &$nameLine, &$violations, $relative): void {
            $normalized = ltrim($name, '\\');
            if ($normalized !== '' && ! str_contains($normalized, '\\')) {
                $violations[] = "{$relative}:{$nameLine} → use {$name};";
            }
            $name = '';
        };

        for ($j = $cursor; $j < $count; $j++) {
            $current = $tokens[$j];

            if ($current->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }
            if ($current->text === ';') {
                $flush();
                break;
            }
            if ($current->text === ',') {
                $flush();
                $collecting = true;

                continue;
            }
            if ($current->is(T_AS)) {
                $flush();
                $collecting = false; // `as` 以降の別名は判定対象ではない

                continue;
            }
            // グループ use (`use A\B\{C, D};`) は prefix に必ず `\` を含むため非複合になりえない。
            if ($current->text === '{') {
                $name = '';
                break;
            }
            if (! $collecting) {
                continue;
            }
            if ($current->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR])) {
                if ($name === '') {
                    $nameLine = $current->line;
                }
                $name .= $current->text;
            }
        }
    }

    return ['violations' => $violations, 'scanned' => true];
}

/**
 * git 追跡下全体の収集結果。
 *
 * @return array{violations: list<string>, namespacelessFiles: int, totalFiles: int}
 */
function nonCompoundUseCollectAll(): array
{
    $violations = [];
    $namespaceless = 0;
    $total = 0;

    foreach (TrackedPhpSourceFiles::all(base_path()) as $target) {
        $source = file_get_contents($target['absolute']);
        if (! is_string($source)) {
            continue;
        }
        $total++;
        $collected = nonCompoundUseCollectFromSource($source, $target['relative']);
        if ($collected['scanned']) {
            $namespaceless++;
        }
        $violations = array_merge($violations, $collected['violations']);
    }

    return [
        'violations' => $violations,
        'namespacelessFiles' => $namespaceless,
        'totalFiles' => $total,
    ];
}

test('namespace 無しファイルに非複合 global use が存在しない', function (): void {
    $result = nonCompoundUseCollectAll();

    expect($result['violations'])->toBe([],
        '非複合 global use を検出しました。PHP は「has no effect」warning を出し import は無効です。'
        .'use 文を削除して参照側を \\FQCN (例: \\RuntimeException) にしてください。'
        .PHP_EOL.implode(PHP_EOL, $result['violations']));
});

test('走査が空振りしていない (git 追跡 PHP > 0 かつ namespace 無しファイル > 0)', function (): void {
    $result = nonCompoundUseCollectAll();

    expect($result['totalFiles'])->toBeGreaterThan(0);
    // database/migrations (60 本) や tests/Architecture など namespace 無しファイルは
    // 構造的に必ず存在する。0 なら namespace 判定が壊れている。
    expect($result['namespacelessFiles'])->toBeGreaterThan(0);
});

/*
 * 負のコントロール: 3 形態すべて (class / function / const) が実際に同じ warning を出すため、
 * 3 形態すべてを検出できることを fixture で固定する。
 */
test('負のコントロール: class / function / const の非複合 use を検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    declare(strict_types=1);
    use RuntimeException;
    use function strlen;
    use const PHP_VERSION;
    return new class {};
    PHP;

    $result = nonCompoundUseCollectFromSource($fixture, 'fixture.php');
    expect($result['scanned'])->toBeTrue();
    expect($result['violations'])->toHaveCount(3);
});

test('負のコントロール: カンマ区切り / as 別名の非複合 use も検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    use RuntimeException, LogicException;
    use InvalidArgumentException as Bad;
    PHP;

    expect(nonCompoundUseCollectFromSource($fixture, 'fixture.php')['violations'])->toHaveCount(3);
});

/*
 * 負のコントロール: **先頭 `\` 付きの単一名**も PHP は同じ warning を出す (実測)。
 * tokenizer 上は T_STRING ではなく T_NAME_FULLY_QUALIFIED になるため、
 * token 種別で判定していると丸ごと取りこぼす (silent hole)。
 */
test('負のコントロール: 先頭バックスラッシュ付きの非複合 use も検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    use \RuntimeException;
    use function \strlen;
    use const \PHP_VERSION;
    PHP;

    expect(nonCompoundUseCollectFromSource($fixture, 'fixture.php')['violations'])->toHaveCount(3);
});

test('正のコントロール: 複合名 / グループ use / 先頭 \\ 付き複合名は検出しない', function (): void {
    $fixture = <<<'PHP'
    <?php
    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Support\Facades\{DB, Schema};
    use function Illuminate\Support\enum_value;
    use const Illuminate\Foundation\SOME_CONST;
    use App\Models\User as Account;
    use \Illuminate\Support\Str;
    use Illuminate\Support\Arr, Illuminate\Support\Collection;
    PHP;

    expect(nonCompoundUseCollectFromSource($fixture, 'fixture.php')['violations'])->toBe([]);
});

/*
 * 正のコントロール: namespace 付きファイルの非複合 use は PHP が warning を出さない
 * (実際に import として機能する) ため対象外。scanned=false で走査自体をスキップする。
 */
test('正のコントロール: namespace 付きファイルは対象外', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Services;
    use RuntimeException;
    class Foo {}
    PHP;

    $result = nonCompoundUseCollectFromSource($fixture, 'fixture.php');
    expect($result['scanned'])->toBeFalse();
    expect($result['violations'])->toBe([]);
});

/*
 * 正のコントロール: クラス本体の trait use と、クロージャの use ($x) を誤検知しない。
 * brace depth 追跡が効いていることの証明。
 */
test('正のコントロール: trait use / クロージャ use を誤検知しない', function (): void {
    $fixture = <<<'PHP'
    <?php
    use Illuminate\Database\Migrations\Migration;
    return new class extends Migration {
        use SomeTrait;
        public function up(): void {
            $x = 1;
            $fn = function () use ($x) { return $x; };
            $arrow = fn () => $x;
        }
    };
    PHP;

    expect(nonCompoundUseCollectFromSource($fixture, 'fixture.php')['violations'])->toBe([]);
});

### tests/Support/TrackedPhpSourceFiles.php (全文)
<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * git 追跡下の PHP ソースファイル (blade を除く) を列挙する純関数。
 *
 * ★同じ列挙を 2 本持たない。`NoNonCompoundGlobalUseTest` (既存) と
 *   `StrictTypesDeclarationGateTest` の両方がここを使う。
 * ★git 管理下に限ることで vendor/ node_modules/ .claude/worktrees/ storage/ を
 *   **自動的に**除外できる (明示 exclude リストを保守しなくてよい)。
 * ★`*.blade.php` は**規則の段階で母集団に入れない**。blade はテンプレートであり
 *   先頭が PHP コードではない (PHP としては `<?php` より前に出力が始まる) ため、
 *   PHP ソースファイルに課す規約の対象にならない。免除ではなく対象外である。
 * ★**保証しないもの**: (a) 未追跡 (git add 前) のファイルは列挙されない。
 *   gate が守る境界は commit / CI であり、そこでは必ず追跡下にある。
 *   (b) 拡張子が `.php` でない PHP ファイル (`artisan` など) は列挙されない。
 *   (c) git が無い環境では**沈黙して空を返さず例外にする** (fail-open 防止)。
 * ★利用側は「自分が期待する母集団」を必ず pin すること (床値 + 代表パス)。
 *   共用したことで一方の都合の変更が他方の走査域を黙って変えるのを防ぐ。
 */
final class TrackedPhpSourceFiles
{
    /**
     * @param  string  $root  git worktree の root (絶対パス)
     * @return list<array{absolute: string, relative: string}> relative の昇順
     */
    public static function all(string $root): array
    {
        $process = new Process(['git', 'ls-files', '-z', '--', '*.php'], $root);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'git ls-files の実行に失敗しました (git worktree 前提の architecture invariant): '
                .$process->getErrorOutput()
            );
        }

        $files = [];
        foreach (explode("\0", $process->getOutput()) as $relative) {
            if ($relative === '' || str_ends_with($relative, '.blade.php')) {
                continue;
            }
            $absolute = $root.'/'.$relative;
            if (! is_file($absolute)) {
                continue; // 削除済みだが index に残っている等
            }
            $files[] = ['absolute' => $absolute, 'relative' => $relative];
        }

        usort($files, fn (array $a, array $b): int => strcmp($a['relative'], $b['relative']));

        return $files;
    }
}

### tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php (全文)
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * `password.confirm` middleware の **全 route での不在** を deny-by-default で固定する。
 *
 * 本アプリは Fortify 標準の password.confirm (3h 窓・パスワード限定) を撤去し、
 * generic recent-auth (15 分窓・パスワード or 再SSO or パスキー) へ統一している。
 * password.confirm が復活すると:
 *   1. SSO-only ユーザー (password 未設定) がその route で**詰む** (satisfier が無い)
 *   2. confirmPasswordView は recent-auth.confirm への redirect でしかなく
 *      `auth.password_confirmed_at` を満たせないため無限ループになる (bug-hunt F-11)
 *
 * 特に laravel/passkeys は config 既定が `management_middleware = ['password.confirm']` で、
 * `fortify-options.passkeys.confirmPassword` を落とすと即座に復活する。
 */
test('password.confirm middleware を持つ route が 1 本も無い', function (): void {
    $violations = [];
    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        $checked++;

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }
            if ($middleware === 'password.confirm' || str_starts_with($middleware, 'password.confirm:')) {
                $violations[] = $route->getName() ?? implode('|', $route->methods()).':'.$route->uri();
            }
        }
    }

    expect($violations)->toBe(
        [],
        'password.confirm は generic recent-auth へ置換済み。復活すると SSO-only ユーザーが詰む: '
        .implode(', ', $violations),
    );
    // route 走査自体が空振りしていないこと
    expect($checked)->toBeGreaterThan(0);
});

### tests/Support/TsUnionValues.php (全文)
<?php

declare(strict_types=1);

namespace Tests\Support;

use BackedEnum;
use RuntimeException;

/**
 * PHP enum ⇔ TS literal union の値集合同期 invariant 用の抽出ヘルパ。
 * ManualEnumTsSyncInvariantTest / NotificationTypeTsSyncInvariantTest が共有する
 * (T008 で ManualEnumTsSyncInvariantTest 内のローカル関数から昇格)。
 */
final class TsUnionValues
{
    /**
     * TS ファイルから `export type {Name} = "a" | "b" | ...;` の値集合を抽出する。
     * 抽出不能 (degenerate PASS) は fail させる (RuntimeException)。
     *
     * @param  string  $relativePath  base_path からの相対パス (例: resources/js/types/manual.ts)
     * @return list<string>
     */
    public static function extract(string $relativePath, string $typeName): array
    {
        $path = base_path($relativePath);
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("TS ファイルを読めません: {$path}");
        }

        // `export type X =` から次の `;` までを取り出す (複数行 union 対応)
        $matched = preg_match(
            '/export\s+type\s+'.preg_quote($typeName, '/').'\s*=\s*(.*?);/s',
            $contents,
            $matches,
        );
        if ($matched !== 1) {
            throw new RuntimeException("TS union が抽出できません (degenerate PASS 防止): {$typeName}");
        }

        $literalCount = preg_match_all('/"([^"]+)"/', $matches[1], $literals);
        if ($literalCount === false || $literalCount === 0) {
            throw new RuntimeException("TS union のリテラルが抽出できません: {$typeName}");
        }

        $values = $literals[1];
        sort($values);

        return $values;
    }

    /**
     * @param  list<BackedEnum>  $cases
     * @return list<string>
     */
    public static function enumStringValues(array $cases): array
    {
        $values = array_map(static fn (BackedEnum $case): string => (string) $case->value, $cases);
        sort($values);

        return $values;
    }
}
