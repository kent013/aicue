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
it("走査が空振りしていない (母集団が空でなく、代表ファイルを含む)", () => {
    expect(allFiles.length).toBeGreaterThan(0);
    const rels = allFiles.map(relPath);
    // 免罪の対象が母集団から落ちたら赤くする (落ちると免罪が意味を失ったことに誰も気づかない)
    expect(rels).toContain("components/atoms/Avatar.svelte");
    expect(rels).toContain("components/atoms/Toggle.svelte");
    // 走査根の 3 区画がそれぞれ 1 本以上ある (どれかが丸ごと読めていない状態を捕まえる)
    expect(rels.some((r) => r.startsWith("components/"))).toBe(true);
    expect(rels.some((r) => r.startsWith("pages/"))).toBe(true);
    expect(rels.some((r) => r.startsWith("lib/"))).toBe(true);
});
```

**件数の床は置かない**。現在 154 本だが、画面の整理で自然に減ることは正常であり、
本質でない赤を生む。空振りで壊れるのは「0 件」か「ある区画が丸ごと読めていない」場合なので、
その 2 つを直接固定する方が目的に合う。

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
| `tests/Architecture/fixtures/global-use/*.php.txt` | **新設**。見本 12 本 (検出 7 / 無違反 5) |
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

#### PHP の名前空間宣言の形 (実測で確定させた前提)

追跡の仕様を決める前に、`php -l` で 6 形を実測した。**言語が許さない形を追跡する必要は無い**。

| 書き方 | 結果 | 本 gate にとっての意味 |
|---|---|---|
| `namespace App;` (セミコロン形) | 正常 | 以降**ファイル末尾まで**名前つき。波括弧が閉じてもグローバルへは戻らない |
| `namespace App; … namespace Bar;` | 正常 | セミコロン形は次の宣言まで有効。**どちらも名前つき**でグローバルは現れない |
| `namespace App { }` (波括弧形) | 正常 | ブロックの中だけ名前つき |
| `namespace App { } use Foo;` | **Fatal (No code may exist outside of namespace {})** | **この形は存在しない**。波括弧形を使ったら、コードは必ずどれかのブロックの中にある |
| `namespace App { } namespace { use Foo; }` | 正常 (警告が出る) | 波括弧形の名前つきの後にグローバル領域を置くには**もう 1 つの波括弧ブロック**が要る |
| `namespace App; namespace { }` | **Fatal (Cannot mix …)** | セミコロン形と波括弧形は混ぜられない |

つまりグローバル領域は次の 2 通りだけである — **(A) 名前空間宣言がまったく無いファイルの全体**、
**(B) `namespace { … }` と書いた波括弧ブロックの中**。
「波括弧ブロックを閉じた後の素のトップレベル」は言語が許さないので、追跡の対象に入れない。

#### 追跡する状態

| 状態 | 型 | 意味 |
|---|---|---|
| `$kind` | `'none'｜'semicolon'｜'bracketed'` | 宣言なし / セミコロン形 / 波括弧形 |
| `$namespaceName` | `string` | 現在有効な名前空間。`''` がグローバル |
| `$bodyDepth` | `int` | 現在の名前空間の直下にあたる波括弧の深さ (`none` と `semicolon` は 0、`bracketed` は 1) |
| `$blockOpenDepth` | `?int` | 波括弧ブロックを開いた深さ。**`null` は「いまブロックの中にいない」**を意味する |

`$blockOpenDepth` を独立した状態として持つのは、**「宣言がまったく無いファイルのグローバル領域」**と
**「波括弧ブロックを閉じた後の、言語がコードを許さない領域」**を区別するためである。
どちらも `$namespaceName === ''` かつ深さ 0 になるので、この 1 変数が無いと区別できない。

遷移は 4 本:

1. 初期状態は `$kind = 'none'` / `$namespaceName = ''` / `$bodyDepth = 0` / `$blockOpenDepth = null`
2. `namespace <名前>;` を見たら `$kind = 'semicolon'` / `$namespaceName = <名前>` / `$bodyDepth = 0` /
   `$blockOpenDepth = null`。
   **以降このファイルでグローバルへ戻ることは無い** (次のセミコロン形宣言も必ず名前つきである。
   名前なしのセミコロン形 `namespace;` は構文として存在しない)
3. `namespace <名前>? {` を見たら `$kind = 'bracketed'` / `$namespaceName = <名前 or ''>` /
   `$blockOpenDepth = <開いたときの深さ = 0>` / `$bodyDepth = 1`
4. 3 で開いたブロックの `}` で深さが `$blockOpenDepth` に戻ったら
   `$namespaceName = ''` / `$bodyDepth = 0` / **`$blockOpenDepth = null`** (ブロック外)。
   `$kind` は `'bracketed'` のまま据え置く (このファイルはもう波括弧形だと確定しているため)。
   **ブロック外で次の `T_NAMESPACE` が現れたら 3 へ戻る** (`namespace Bar { } namespace { }` の形)

判定式:

```php
$isGlobalImportRegion =
    $namespaceName === ''
    && $depth === $bodyDepth
    && ($kind !== 'bracketed' || $blockOpenDepth !== null);
```
これでクラス本体の trait 取り込み (深さが深い) とクロージャの `use ($x)` (次のトークンが `(`) は
現行と同じ理由でそのまま除外され、`namespace { use Foo; }` は拾えるようになる。

`namespace` の宣言の形が読めなかった場合 (トークンが尽きた / 名前の後が `;` でも `{` でもない) は
**黙って対象外にせず**、`unresolved` として返して gate を赤くする (fail-closed)。
失敗メッセージには**ファイル名・行番号・その位置の前後 3 トークンの字面**を載せる
(赤くなったときに何が読めなかったのかが分かるようにする)。

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
     * 見本ファイルに対して php -l を **1 回だけ**実行し、結果を丸ごと返す。
     *
     * 実行系は **いまテストを走らせている PHP そのもの** (PHP_BINARY) を使う。
     * 別の php を探しに行かないので「手元と CI で版が違うと結果が変わる」問題は起きない
     * (その実行系が警告を出す形を、その実行系で検出できているかを見る検査になる)。
     * `-n` で php.ini を読ませない (opcache 等の状態に左右されない)。
     * 警告は **標準出力**へ出る (実測)。
     *
     * 4 本の検査は**同じ 1 回の結果を共有する**。検査ごとに実行し直すと、
     * 実行回数が増えるうえ「同じ実行結果を照合している」ことが保証されなくなる。
     *
     * @return array{
     *   warnings: list<array{name: string, line: int}>,
     *   syntaxValid: bool,
     *   exitCode: int,
     *   stdout: string,
     *   stderr: string,
     * }
     */
    public static function inspect(string $absolutePath): array
}
```

- 実行: `PHP_BINARY -n -d error_reporting=E_ALL -d display_errors=1 -d log_errors=0 -l <path>`
- 取り出し: `/non-compound name '([^']+)' has no effect in .+ on line (\d+)/`
- `syntaxValid` の主判定は**終了コード**である (実測: 構文が正しければ警告が出ていても `0`、
  構文エラーなら `255`)。「構文エラーなし」の文言は診断用にだけ使い、判定には使わない
  (文言は版で変わりうるが終了コードの意味は変わらない)
- `Process::getExitCode()` は型の上では `?int` なので、`run()` の後に `null` なら**例外にする**
  (fail-closed。`null` を 0 と読むと構文エラーを合格へ倒しかねない)。
  `syntaxValid` は取得済みの終了コードとの厳密比較で作る

```php
$exitCode = $process->getExitCode();
if ($exitCode === null) {
    throw new RuntimeException('php -l の終了コードを取得できませんでした');
}
$syntaxValid = $exitCode === 0;
```

**呼び出し方も決めておく**: 見本 12 本の `inspect()` は**テストファイルの先頭で 1 度だけ**回して
結果の一覧を作り、4 本の検査はその一覧を読む。各検査の中から `inspect()` を呼ぶ形にすると、
「同じ 1 回の結果を共有する」という契約が書いてあるだけになって、同じ見本を何度も実行しやすくなる。
- 見本の拡張子は **`.php.txt`** にする。`.php` にすると
  この gate 自身 (`git ls-files -- '*.php'`) と `StrictTypesDeclarationGateTest` /
  `ForbiddenStatementTokenInvariantTest` の母集団に入り、**わざと違反させた見本で本番の gate が赤くなる**。
  `php -l` は拡張子を見ないので `.php.txt` のまま直接検査できる (実測確認済み)。

照合する検査を 4 本置く:

1. **一致**: 検出 7 本の見本について、走査器の `violations` と真値が
   **(名前, 行) で整列した list として完全一致**する。
   **集合にしない** — 同じ名前・同じ行の警告が 2 回出る場合に、集合化すると
   走査器側の重複や欠落を隠してしまう。重複を保ったまま両側を同じ規則で整列して比べる
2. **無違反**: 無違反 5 本の見本について、真値が 0 件であり、走査器も 0 件である
3. **真値の空振り検知**: 検出 7 本の見本から得た真値の総数が 0 件なら赤くする
   (`php -l` の警告文面が将来変わったら、照合が「両方 0 件で一致」して静かに無力化するため)
4. **見本が構文として正しい**: 全 12 本について `inspect()['syntaxValid']` が真であること (判定は終了コード)。
   見本が parse error になると警告が 1 件も出ず、3 の空振り検知に頼るまで気づけない
   (parse error は「見本の書き方が壊れた」であって「検出力が落ちた」ではないので、切り分けて赤くする)

**赤くなったときに切り分けられるようにする**: 3 と 4 の失敗メッセージには
`PHP_VERSION` / `PHP_BINARY` / `php -l` の**標準出力と標準エラーの両方**の生の内容を載せる。
通常の警告は標準出力に出るが、プロセスの起動失敗や実行環境側の異常は標準エラーにしか出ないことがある。
「真値の取り出し規則 (警告の文面) が壊れた」のか「見本が壊れた」のか「検出器が壊れた」のかを、
失敗メッセージだけで判断できるようにするためである。

### 見本 12 本

| ファイル (`tests/Architecture/fixtures/global-use/`) | 中身 | 期待 |
|---|---|---|
| `detects-class.php.txt` | `use Foo;` | 1 件 |
| `detects-function-const.php.txt` | `use function strlen;` / `use const PHP_VERSION;` | 2 件 |
| `detects-leading-backslash.php.txt` | `use \Foo;` / `use function \strlen;` / `use const \PHP_VERSION;` | 3 件 |
| `detects-comma-list.php.txt` | 複数行に散らした `use\n Foo,\n Bar;` | 2 件 (**両方とも Foo の行**) |
| `detects-partial-alias.php.txt` | `use Foo, Bar as B, Baz;` | 2 件 (`Bar` は入らない) |
| `detects-bracketed-global.php.txt` | `namespace { use Foo; use function strlen; class A { use T; } $f = function () use ($x) {}; }` | 2 件 |
| `detects-bracketed-after-named.php.txt` | `namespace Bar { use Qux; } namespace { use Foo; }` | 1 件 (`Qux` は名前つきの中なので入らない) |
| `clean-compound.php.txt` | 複合名 / グループ use / 先頭 `\` つき複合名 | 0 件 |
| `clean-aliased.php.txt` | 別名つきの 4 形 | 0 件 |
| `clean-named-namespace.php.txt` | `namespace App; use Foo;` に加え `namespace Bar;` を続けて `use Baz;` (セミコロン形はグローバルへ戻らないことの固定) | 0 件 |
| `clean-bracketed-named.php.txt` | `namespace App { use Foo; }` | 0 件 |
| `clean-trait-and-closure.php.txt` | 名前空間なしのファイルでの trait 取り込みとクロージャの `use ($x)` | 0 件 |

計 12 本 (検出 7 / 無違反 5)。見本の**本数と名前の一覧**を検査で表明する
(差し替え・こっそり削除で検出力が落ちるのを止める)。

**言語が許さない形は見本にしない**。`namespace App { } use Foo;` (波括弧形の後の素のトップレベル) と
`namespace App; namespace { }` (2 形の混在) はどちらも parse error になるため、見本に置くと
真値の取得そのものが失敗する。この 2 形を追跡対象から外した根拠は上の実測表に残す。

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

現行の「走査が空振りしていない」を引き継ぐ。**件数の床は置かない** (現在 1638 本だが、
リポジトリの整理で減ることは正常であり、本質でない赤を生む)。目的に直結する 4 点を固定する。

- 追跡下 PHP の総数 > 0
- グローバル領域を持つファイル (名前空間宣言なし) が **1 本以上**ある
- 母集団に `database/migrations/` 配下と `tests/Architecture/` 配下が**それぞれ 1 本以上**含まれる
  (どちらも構造的に名前空間を持たない置き場であり、ここが落ちたら走査域が壊れている。
  ファイル名は日付や機能名で変わるので**接頭辞で見る**)
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

- **`php -l` を毎回 12 回実行する**ので、この検査だけ実行時間が伸びる (1 回あたり数十ミリ秒程度)。
  見本 1 本につき実行は 1 回で、4 本の検査はその結果を共有する (検査ごとに実行し直さない)。
  見本は 12 本に絞ってあり、実ツリー走査 (1638 本) には `php -l` を使わない (真値は見本にだけ使う) ので影響は限定的である。
- 警告文の文言が PHP の将来版で変わると真値が取れなくなる。**そのときは空振り検知が赤くなる**ので、
  静かに無力化することはない (これが検査 3 本目を置く理由である)。
- 名前空間の文脈追跡は現行より複雑になる。誤りは見本 12 本と真値照合が受け止める。
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
