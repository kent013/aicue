# Round 3: Round 2 の指摘への対応

# 対応マトリクス: design-review Round 2

## [Critical] 「ブロック外ではない」を判定する状態が定義されていない

- 判断: **対応する**
- 根拠: 指摘のとおり。`$namespaceName === ''` かつ深さ 0 という条件は、
  「宣言がまったく無いファイルのグローバル領域」と「波括弧ブロックを閉じた後の領域」の
  両方で真になってしまい、2 変数だけでは区別できない。
- 対応内容: 提案の最小構成 (`$blockOpenDepth: ?int`) を採用した。
  - 状態表に `$blockOpenDepth` を独立した行として追加し、**`null` が「いまブロックの中にいない」**を意味すると明記
  - なぜ独立した状態が要るのか (上記 2 領域を区別するため) を設計に書いた
  - 遷移を 3 本から 4 本へ書き直し、ブロックを閉じたときに `$blockOpenDepth = null` へ戻すこと、
    **ブロック外で次の `T_NAMESPACE` が現れたら波括弧形の開始へ戻る**ことを明記した
  - 判定式を提案どおりの形でそのまま設計に載せた

## [Warning] `PhpLintOracle` の公開契約が検査 4 と診断メッセージの要求を満たさない

- 判断: **対応する**
- 根拠: 指摘のとおり。警告の list だけを返す契約では、終了コード・構文エラーの有無・生の標準出力が取れず、
  検査ごとに `php -l` を実行し直すことになる (実行回数が増え、同じ結果を照合している保証も弱くなる)。
- 対応内容: `nonCompoundWarnings()` を廃し、提案どおり
  `inspect(): array{warnings, syntaxValid, exitCode, stdout, stderr}` の 1 メソッドにした。
  「4 本の検査は同じ 1 回の結果を共有する」ことを契約として書いた。

## [Warning] 「集合として完全一致」では重複警告を隠す

- 判断: **対応する**
- 根拠: 同じ名前・同じ行の警告が 2 回出る場合 (例: 同一行のカンマ区切りに同じ名前を 2 度書いた形)、
  集合化すると走査器側の重複・欠落が消える。
- 対応内容: 「(名前, 行) で整列した **list として**完全一致」へ改め、集合にしない理由も書いた。

## [Suggestion] 「見本が構文として正しい」は終了コードを主判定にする

- 判断: **対応する**
- 根拠: 文言は版で変わりうるが終了コードの意味は変わらない。実測でも
  構文が正しければ (警告が出ていても) `0`、構文エラーなら `255` である。
- 対応内容: `syntaxValid` の主判定を終了コードにし、文言は診断用にだけ使うと明記した。

---

## 修正後の該当箇所

### 施策 2 — 追跡する状態と遷移 (書き直し後)

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

### 施策 2 — 真値の取得と照合 (書き直し後)

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
`PHP_VERSION` / `PHP_BINARY` / `php -l` の標準出力の生の内容を載せる。
「真値の取り出し規則 (警告の文面) が壊れた」のか「見本が壊れた」のか「検出器が壊れた」のかを、
失敗メッセージだけで判断できるようにするためである。

### 見本 12 本
