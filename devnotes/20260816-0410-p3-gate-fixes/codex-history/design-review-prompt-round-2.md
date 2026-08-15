# Round 2: Round 1 の指摘への対応

Round 1 の指摘に対する対応マトリクスと、修正後の該当箇所を送る。全体判定を出し直してほしい。

# 対応マトリクス: design-review Round 1

## [Critical] 名前空間追跡の状態遷移が曖昧 (セミコロン形 / 波括弧形の切替と終了条件)

- 判断: **対応する**
- 根拠: 指摘のとおり。`$bodyDepth` / `$blockOpenDepth` の 2 変数だけでは、
  「セミコロン形はファイル末尾まで有効でグローバルへ戻らない」という性質が読み取れない。
- 対応内容: 状態を `$kind` = `none` / `semicolon` / `bracketed` の 3 値で明示し、遷移を 3 本に書き下した。
  - `semicolon` は次の宣言まで有効で、**次の宣言も必ず名前つき**なのでグローバルへは戻らない
    (名前なしのセミコロン形 `namespace;` は構文として存在しない)
  - `bracketed` は開始した深さを抜けたときだけブロック外へ戻る
  無違反の見本 `clean-named-namespace` に `namespace App; use Foo; namespace Bar; use Baz;` を入れ、
  「セミコロン形はグローバルへ戻らない」ことを見本で固定する。

## [Critical] `namespace App {}` の後の global 領域を検出対象にするか不明

- 判断: **半分は対応する / 半分は根拠を添えて反論する**
- 根拠 (反論部分): **その形は PHP として存在しない**。実測 (PHP 8.4.24 / `php -l`):
  - `namespace App { } use Foo;` → `Fatal error: No code may exist outside of namespace {}`
  - `namespace App; namespace { }` → `Fatal error: Cannot mix bracketed namespace declarations with unbracketed namespace declarations`
  波括弧形を使ったら、コードは必ずどれかのブロックの中にある。したがって
  「ブロックを閉じた後の素のトップレベルにある `use`」を追跡する必要は無い。
  むしろ見本に置くと parse error になり、真値の取得そのものが失敗する。
- 根拠 (対応部分): ただし**波括弧形の名前つきの後にグローバル領域を置く正当な形**は実在する —
  `namespace Bar { use Qux; } namespace { use Foo; }` は正常に通り、`Foo` にだけ警告が出る (実測)。
  これは指摘が突いていた穴そのもの (ブロックを抜けた後に判定を誤ると `Foo` を取りこぼすか `Qux` を誤検出する) である。
- 対応内容:
  - 実測 6 形の表を詳細設計へ入れ、「グローバル領域は (A) 名前空間宣言がまったく無いファイル全体、
    (B) `namespace { … }` の中、の 2 通りだけ」と仕様化した
  - 検出の見本に `detects-bracketed-after-named.php.txt`
    (`namespace Bar { use Qux; } namespace { use Foo; }` → `Foo` だけ 1 件) を追加した (見本は 11 本 → 12 本)
  - 「言語が許さない 2 形は見本にしない」ことと、その根拠を設計に残した

## [Warning] `PhpLintOracle` の正規表現が警告文の英語文面に依存する / 切り分けが難しい

- 判断: **対応する**
- 対応内容: 照合の検査を 3 本 → 4 本にした。
  - 4 本目「見本が構文として正しい」を追加し、parse error (見本が壊れた) を空振り (検出力が落ちた) と切り分ける
  - 3 と 4 の失敗メッセージに `PHP_VERSION` / `PHP_BINARY` / `php -l` の標準出力の生の内容を載せる

## [Warning] 母集団 pin の `totalFiles > 1000` は本質でない赤を生む

- 判断: **対応する**
- 対応内容: 件数の床をやめ、`> 0` に加えて目的に直結する 2 区画
  (`database/migrations/` 配下と `tests/Architecture/` 配下がそれぞれ 1 本以上) を接頭辞で pin する形にした。
  ファイル名は日付や機能名で変わるので接頭辞で見る。

## [Warning] 施策 1 の走査床 `> 100` が現在値 154 に近い

- 判断: **対応する**
- 対応内容: 件数の床をやめ、`> 0` + 代表ファイル 2 本 (許可一覧の対象そのもの) +
  走査根の 3 区画 (`components/` `pages/` `lib/`) がそれぞれ 1 本以上、という形にした。

## [Suggestion] `unresolved` の失敗メッセージに対象ファイル・行・近傍 token を含める

- 判断: **対応する**
- 対応内容: 「ファイル名・行番号・その位置の前後 3 トークンの字面を載せる」と設計に明記した。

## [Suggestion] 施策 1 の文字集合は妥当 / 施策 3・4 の不採用は妥当

- 判断: **見送る** (変更不要)
- 根拠: 追加の作業を伴わない承認の所見である。

---

## 修正後の詳細設計 (施策 2 の名前空間追跡 / 見本 / 真値照合 / 母集団 pin、施策 1 の空振り検知)

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

| 状態 | 意味 |
|---|---|
| `$kind` | `none` (宣言なし) / `semicolon` (セミコロン形) / `bracketed` (波括弧形) の 3 値 |
| `$namespaceName` | 現在有効な名前空間。`''` がグローバル |
| `$bodyDepth` | 現在の名前空間の直下にあたる波括弧の深さ (`none` と `semicolon` は 0、`bracketed` は 1) |

遷移は 3 本だけ:

1. `none` の初期状態は「グローバル・`$bodyDepth = 0`」
2. `namespace <名前>;` を見たら `$kind = semicolon` / `$namespaceName = <名前>` / `$bodyDepth = 0`。
   **以降このファイルでグローバルへ戻ることは無い** (次のセミコロン形宣言も必ず名前つきである。
   名前なしのセミコロン形 `namespace;` は構文として存在しない)
3. `namespace <名前>? {` を見たら `$kind = bracketed` / `$namespaceName = <名前 or ''>` / `$bodyDepth = 1`。
   このブロックの `}` で深さが 0 に戻ったら `$namespaceName = ''` かつ `$bodyDepth = 0` の
   **ブロック外**へ戻す (ここに `use` は書けない = 上表のとおり言語が禁じている)

判定は「**`$namespaceName === ''` かつ 現在の深さ === `$bodyDepth` かつ ブロック外ではない**」の
`use` だけを見る、の 1 本になる。
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

照合する検査を 4 本置く:

1. **一致**: 検出 7 本の見本について、走査器の `violations` と真値が **(名前, 行) の集合として完全一致**する
2. **無違反**: 無違反 5 本の見本について、真値が 0 件であり、走査器も 0 件である
3. **真値の空振り検知**: 検出 7 本の見本から得た真値の総数が 0 件なら赤くする
   (`php -l` の警告文面が将来変わったら、照合が「両方 0 件で一致」して静かに無力化するため)
4. **見本が構文として正しい**: 全 12 本について `php -l` が「構文エラーなし」を返すこと。
   見本が parse error になると警告が 1 件も出ず、3 の空振り検知に頼るまで気づけない
   (parse error は「見本の書き方が壊れた」であって「検出力が落ちた」ではないので、切り分けて赤くする)

**赤くなったときに切り分けられるようにする**: 3 と 4 の失敗メッセージには
`PHP_VERSION` / `PHP_BINARY` / `php -l` の標準出力の生の内容を載せる。
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
