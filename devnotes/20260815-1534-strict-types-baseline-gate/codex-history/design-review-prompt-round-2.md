# Round 2: Round 1 の指摘への対応

Round 1 の指摘に対する Claude 側の対応マトリクスと、修正後の詳細設計 (施策 2・施策 3 の全文) を送ります。
Critical 2 件・Warning 3 件・Suggestion 4 件すべてに対応済みです。再レビューをお願いします。

## 対応マトリクス

# 対応マトリクス: design-review Round 1

Codex 判定: **CHANGES_REQUESTED** (Critical 2 / Warning 3 / Suggestion 4)。

## [Critical] 施策 2: 判定器が先頭 8 token だけで真を返すため、後続の `strict_types` 再宣言を見落とす
- 判断: **対応する**
- 根拠: 指摘のとおり。なお PHP 8.4 で実測すると
  `declare(strict_types=1); declare(strict_types=0);` の**実効は strict のまま**であり
  (1 が 1 度でもあれば実効。逆順・同一 declare 内の重複でも同じ)、現行版では
  「判定器 true / 実効 false」の fail-open は成立していない。
  しかし (a)「表記を正準形 1 つに揃える」という本 gate の規約に反すること、
  (b) 将来「後に書いた方が勝つ」へ仕様が変われば fail-open になること、の 2 点で拒否が正しい。
- 対応内容: 判定器を `hasCanonicalHead()` + `hasLaterStrictTypesDeclare()` の 2 段に分けた。
  後者は index 8 以降の `T_DECLARE` の引数部に `strict_types` (T_STRING、大小無視) が
  あれば真を返し、判定器全体は偽に倒す。`declare(ticks=1)` 等 `strict_types` を含まない
  後続 declare は拒否しない。負の対照 2 件 (`…=1); …=0);` / `…=1); …=1);`) と
  正の対照 1 件 (`…=1); declare(ticks=1);`) をテスト計画へ追加した。
  実測が strict であることは自己検査で「安全側の乖離」として明示的に固定する。

## [Critical] 施策 3: 判定器が偽陽性を持つ限り gate も fail-open
- 判断: **対応する**
- 根拠: 上と同じ。
- 対応内容: 判定器の修正に加え、gate 内の自己検査へ
  `declaresStrictTypes("<?php declare(strict_types=1); declare(strict_types=0);\n") === false` を追加した
  (自己検査ファイルを消されても gate 単独で壊れ方に気付ける)。

## [Warning] 施策 2: `tempnam()` の戻り値へ `.php` を連結しているため元ファイルが残る / false 未処理
- 判断: **対応する**
- 根拠: `tempnam()` は実ファイルを作る。指摘のとおり別パスへ書けば元が残り続ける。
  php は拡張子に関係なくファイルを実行できるので拡張子は不要。
- 対応内容: 戻り値のパスへそのまま書く形に変更し、`false` を例外にした。

## [Warning] 施策 2: 実測照合が header 断片中心で、判定器が受け取る完全ソースとの対応が弱い
- 判断: **対応する**
- 根拠: 判定器と実測器へ**同一の文字列**を渡さないと、突き合わせの主張が弱くなる。
- 対応内容: 実測器の引数を「完全な PHP ソース」に変え、検体表も完全ソースで持つことにした。
  文字列リテラル・配列リテラルだけの検体も実行可能な形にする。

## [Warning] 施策 4: `config:clear` だけでは変更後の config 評価確認として弱い
- 判断: **対応する**
- 対応内容: 検証順を明記した (`config:clear` → `route:list` → `config:cache` → `config:clear`)。

## [Suggestion] `NoNonCompoundGlobalUseTest` の `use` 文の入れ替えを明記
- 判断: 対応する。`Process` の参照は同ファイル L48 の 1 箇所だけである (実測) ことを添えて diff を書いた。

## [Suggestion] 一時ディレクトリの再帰削除に prefix guard を置く
- 判断: 対応する。テスト計画へ「確保した一時ディレクトリ直下であることを確認してから消す」を追加した。

## [Suggestion] gate の代表 prefix チェックは prefix ごとに失敗メッセージを出す
- 判断: 対応する。骨子コードを具体化した。

## [Suggestion] `docs/template-divergence.md` に再宣言拒否の方針を明記
- 判断: 対応する。D15 の「保証しないもの」へ追記した。


---

## 修正後の詳細設計 (施策 2 / 施策 3 の全文)

## 施策 2: 宣言判定器と実測照合器

### 変更箇所

- 新規: `tests/Support/StrictTypesDeclarationScanner.php`
- 新規: `tests/Support/StrictTypesRuntimeProbe.php`
- 新規: `tests/Unit/Architecture/StrictTypesDeclarationScannerTest.php`

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: 新規のみ

### 変更後コード (判定器)

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * PHP ソースが冒頭で `declare(strict_types=1);` を宣言しているかを判定する純関数。
 *
 * ★正規表現・部分文字列判定にしない。コメントや文字列リテラル中の
 *   `declare(strict_types=1)` という**記述**を宣言と誤認するため
 *   (負の対照で固定する)。走査は `PhpTokenScan::normalize()` (空白・コメント除去済み) に対して行う。
 *
 * ★**受理するのは正準形だけ**である:
 *     <?php  declare ( strict_types = 1 ) ;
 *   (キーワード・指令名の大小は無視。空白とコメントは透過)
 *   PHP 8.4 の実測では `01` / `0x1` / `0b1` / `declare(ticks=1, strict_types=1)` /
 *   同一 declare 内の重複指定 / 2 文目の declare も**実際には厳密化が効く**が、
 *   本判定器はこれらを**未宣言側に倒す** (安全側の乖離)。
 *   本 gate は PHP の意味論の再現ではなく、リポジトリ内の表記を 1 つに揃える規約検査だからである。
 *
 * ★**先頭の正準形だけでは終わらない — 後続の `strict_types` 再宣言があれば未宣言に倒す**。
 *   PHP 8.4 の実測では `declare(strict_types=1); declare(strict_types=0);` の実効は
 *   **strict のまま**だが (1 が 1 度でもあれば実効)、
 *   (a) 表記を 1 つに揃えるという本 gate の規約に反すること、
 *   (b) 「後に書いた方が勝つ」へ言語仕様が変わった場合に
 *       判定器 true / 実効 false という**逆向きの乖離 = fail-open** になること、
 *   の 2 つの理由で拒否する。`declare(ticks=1)` のように `strict_types` を含まない
 *   後続の declare は拒否しない (厳密化に関係しないため)。
 *
 * ★**逆向きの乖離は 1 件も許さない** — 「判定器は宣言済みと言うのに実際は厳密化されない」形が
 *   あると gate が嘘をつく。`StrictTypesDeclarationScannerTest` が
 *   `StrictTypesRuntimeProbe` (別プロセスで実際に型不一致が起きるかを測る) と
 *   突き合わせ、乖離の向きを機械的に固定する。
 */
final class StrictTypesDeclarationScanner
{
    /** 正準形の宣言 (失敗メッセージで提示する)。 */
    public const string CANONICAL_DECLARATION = 'declare(strict_types=1);';

    public static function declaresStrictTypes(string $phpSource): bool
    {
        $tokens = PhpTokenScan::normalize($phpSource);

        return self::hasCanonicalHead($tokens) && ! self::hasLaterStrictTypesDeclare($tokens);
    }

    /**
     * 冒頭が正準形か。
     *
     * [0] T_OPEN_TAG / [1] T_DECLARE / [2] '(' / [3] T_STRING(strict_types)
     * [4] '=' / [5] T_LNUMBER('1') / [6] ')' / [7] ';'
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function hasCanonicalHead(array $tokens): bool
    {
        if (count($tokens) < 8) {
            return false;
        }
        if ($tokens[0]['id'] !== T_OPEN_TAG || $tokens[1]['id'] !== T_DECLARE) {
            return false; // 先頭に inline HTML / shebang / 他の文があれば未宣言
        }
        if ($tokens[2]['text'] !== '(' || $tokens[3]['id'] !== T_STRING) {
            return false;
        }
        if (mb_strtolower($tokens[3]['text']) !== 'strict_types') {
            return false;
        }
        if ($tokens[4]['text'] !== '=' || $tokens[5]['id'] !== T_LNUMBER || $tokens[5]['text'] !== '1') {
            return false; // 値 0 / 01 / true / 式 はすべて未宣言側
        }

        return $tokens[6]['text'] === ')' && $tokens[7]['text'] === ';'; // ブロック形 `{` は未宣言側
    }

    /**
     * 冒頭の正準形より後ろに、`strict_types` を含む declare が現れるか。
     *
     * ★`'strict_types'` という**文字列リテラル**は T_CONSTANT_ENCAPSED_STRING であって
     *   T_STRING ではないため、配列リテラル (`['strict_types' => 1]`) は誤検出しない。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function hasLaterStrictTypesDeclare(array $tokens): bool
    {
        $count = count($tokens);
        for ($i = 8; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_DECLARE) {
                continue;
            }
            // この declare の引数部 (対応する ')' まで) に strict_types があるか見る
            for ($j = $i + 1; $j < $count && $tokens[$j]['text'] !== ')'; $j++) {
                if ($tokens[$j]['id'] === T_STRING && mb_strtolower($tokens[$j]['text']) === 'strict_types') {
                    return true;
                }
            }
        }

        return false;
    }
}
```

### 変更後コード (実測照合器)

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * 「ある冒頭の書き方で PHP の厳密な型検査が実際に効くか」を**別プロセスで実測**する。
 *
 * ★判定器の自己検査で使う。表に書いた真値が PHP の版で変わっても、
 *   実測との突き合わせがあれば「判定器が実効性の下界である」ことは崩れない。
 * ★書き込み先はシステムの一時ディレクトリで、リポジトリ内には何も残さない。
 */
final class StrictTypesRuntimeProbe
{
    /**
     * @param  string  $phpSource  判定器へ渡すのと**同一の完全な PHP ソース**
     * @return bool 厳密化が実際に効いたか (Fatal / Parse error は false)
     */
    public static function strictTypesInEffect(string $phpSource): bool
    {
        // tempnam() は実ファイルを作る。拡張子を足すと**元のファイルが残る**ため、
        // 戻り値のパスへそのまま書く (php は拡張子に関係なく実行できる)。
        $path = tempnam(sys_get_temp_dir(), 'strict-probe-');
        if ($path === false) {
            throw new RuntimeException('実測用の一時ファイルを作れませんでした');
        }

        $probe = <<<'PHP'

            function probeTarget(int $value): int { return $value; }
            try { probeTarget("1"); echo 'WEAK'; } catch (\TypeError $e) { echo 'STRICT'; }
            PHP;

        try {
            if (file_put_contents($path, rtrim($phpSource, "\n")."\n".$probe) === false) {
                throw new RuntimeException("実測用の一時ファイルを書けませんでした: {$path}");
            }

            $process = new Process([PHP_BINARY, '-d', 'error_reporting=E_ALL', $path]);
            $process->run();

            return trim($process->getOutput()) === 'STRICT';
        } finally {
            @unlink($path);
        }
    }
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`bool`)
- [x] null 安全 (`tempnam()` の false、`file_put_contents()` の false を扱う。
      `tempnam()` の戻り値へ拡張子を連結しない = 元ファイルを残さない)
- [x] DTO 返却は不要 (真偽値 1 つ)
- [x] `PhpTokenScan::normalize()` の戻り型 (`list<array{id: int|null, text: string, line: int}>`) に
      沿って添字アクセスする

### テスト計画

`tests/Unit/Architecture/StrictTypesDeclarationScannerTest.php` (新規)。
**検体表を 1 つ持ち、正の対照・負の対照・実測との突き合わせを同じ表から回す**:

- [ ] 正の対照: `<?php declare(strict_types=1);` / 空行入り / 直前にコメント /
      大文字 (`DECLARE(STRICT_TYPES=1);`) / 空白揺れ (`declare ( strict_types = 1 ) ;`) が
      すべて `true` になる
- [ ] 負の対照 1 (**この scanner の存在理由**): コメント内の
      `// declare(strict_types=1);` だけがある源が `false` になる
- [ ] 負の対照 2: 文字列リテラル `$x = 'declare(strict_types=1);';` だけがある源が `false`
- [ ] 負の対照 3: 値 0 (`declare(strict_types=0);`) が `false`
- [ ] 負の対照 4: ブロック形 (`declare(strict_types=1) { }`) が `false`
- [ ] 負の対照 5: 後置 (`namespace A; declare(strict_types=1);`) が `false`
- [ ] 負の対照 6: 別指令のみ (`declare(ticks=1);`) が `false`
- [ ] 負の対照 7: 宣言なし (`<?php` のみ) が `false`
- [ ] 負の対照 8: `<?php` の前に文字がある (`X<?php declare(strict_types=1);`) が `false`
- [ ] 負の対照 9: 配列リテラル (`['strict_types' => 1]`) が `false`
- [ ] 負の対照 10 (**後続の再宣言**): 次の 2 つが `false` になる
      (実効は現行 PHP では strict のままだが、表記を揃える規約として拒否し、
      かつ言語仕様が変わったときの fail-open を先に塞ぐ)
      - `<?php declare(strict_types=1); declare(strict_types=0);`
      - `<?php declare(strict_types=1); declare(strict_types=1);`
- [ ] 正の対照 (再宣言の境界): `<?php declare(strict_types=1); declare(ticks=1);` は
      `true` のまま (`strict_types` を含まない後続 declare は拒否しない)
- [ ] 安全側の乖離 (実効だが未宣言と判定する) を**明示的に固定**: `01` / `0x1` / `0b1` /
      `declare(ticks=1, strict_types=1)` / `declare(strict_types=(1))` /
      同一 declare 内の重複指定 / 2 文目の declare (`declare(ticks=1); declare(strict_types=1);`) が
      `false` になる
- [ ] **実測との突き合わせ**: 検体は**完全な PHP ソース**として持ち (判定器へ渡すものと同一の文字列)、
      同じ文字列を `StrictTypesRuntimeProbe::strictTypesInEffect()` にも渡して、
      「判定器が `true` なら実測も必ず `true`」を要求する
      (= 判定器は実効性の下界。**逆向きの乖離 0 件**を機械的に固定する)。
      文字列リテラル・配列リテラルだけの検体も実行可能な完全ソースにしておく
- [ ] 実測器そのものの健全性: 宣言なしの源で実測が `false`、正準形で `true` になる
      (実測器が常に同じ値を返す壊れ方を検出する)
- [ ] 実ファイルでの疎通: `tests/Support/PhpTokenScan.php` を読んで `true`、
      `resources/views/app.blade.php` を読んで `false` になる
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **別プロセス起動のコスト**: 検体 20 件前後 × 1 プロセス ≒ 1 秒未満を見込む。
  超えるようなら実測との突き合わせを「乖離の向きが問題になる検体」に絞る
  (絞ってもこのテストの主張は保てる)
- **`PHP_BINARY` が使えない実行環境**: Process が失敗したら実測器は例外にする
  (silent に `false` を返して緑にしない)
- **判定器が厳しすぎて誤って赤くなる**: 実測で本リポジトリの 1543 本中 1511 本が
  正準形であることを確認済み。残り 32 本は施策 4 で正準形にする

---

## 施策 3: gate 本体

### 変更箇所

- 新規: `tests/Architecture/StrictTypesDeclarationGateTest.php`

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし / 既存テストの変更: なし

### 変更後コード (骨子)

```php
<?php

declare(strict_types=1);

use Tests\Support\StrictTypesDeclarationScanner;
use Tests\Support\TrackedPhpSourceFiles;

/*
 * Architecture invariant: **git 追跡下の PHP ソース全数**が冒頭で
 * `declare(strict_types=1);` を宣言している。
 *
 * なぜ全数か: PHP は既定で "1" と 1 を黙って行き来させる。宣言を欠くファイルが 1 枚あると
 * そこだけ暗黙変換が復活し、取り違えが実行時まで表に出ない。容量予約 (bytes) や
 * チケット枚数のように数値と文字列の取り違えが金額・容量の誤りになる領域を持つため、
 * 「どこか 1 枚だけ緩い」状態を構造的に作らない。
 *
 * **免除の登録簿 (baseline / allow-list) を持たない**。導入時点の未宣言 32 本を同一変更で
 * 是正して 0 件から始めるので、登録簿は 1 件も守らないまま複雑さだけを足すことになる
 * (`QueueDispatchAtomicityInventoryTest` と同じ形 = 免除機構そのものが無い)。
 * **どうしても宣言できないファイルが将来出た場合も、なし崩しに allow-list を足さない。
 * 設計レビュー (app-design) を通してから機構を新設すること。**
 *
 * 走査域 (追跡下 `*.php` − `*.blade.php`) の定義と限界は
 * `Tests\Support\TrackedPhpSourceFiles` の docblock が正本。
 * 判定の正準形と「実効だが受理しない形」は `Tests\Support\StrictTypesDeclarationScanner` が正本。
 *
 * 家系との関係: laravel-claude-template は `StrictTypesBaselineInvariantTest` で
 * **app のみ**を走査し空の baseline を持つ。本 gate は走査域が広く baseline を持たない
 * (`docs/template-divergence.md` D15)。
 */

test('git 追跡下の PHP は全数 declare(strict_types=1) を宣言している', function (): void {
    $targets = TrackedPhpSourceFiles::all(base_path());

    // 空振り防止 1: 走査対象が 0 件なら赤 (走査域が消えても緑にならないようにする)
    expect($targets)->not->toBeEmpty();

    // 空振り防止 2: 母集団の床値 (実測 1543)。走査域が黙って狭まると赤くなる
    expect(count($targets))->toBeGreaterThanOrEqual(1400);

    // 空振り防止 3: 代表ディレクトリが母集団に含まれること
    //   (prefix ごとに個別の失敗メッセージを出す = どの走査域が消えたか分かるようにする)
    $prefixes = ['app/', 'tests/', 'config/', 'database/', 'routes/', 'bootstrap/', 'public/'];
    foreach ($prefixes as $prefix) {
        $found = array_filter($targets, fn (array $t): bool => str_starts_with($t['relative'], $prefix));
        expect($found)->not->toBeEmpty("走査域から {$prefix} が消えています");
    }

    // 空振り防止 4: 判定器が壊れていない (自己検査ファイルを消されても gate 単独で気付く)
    expect(StrictTypesDeclarationScanner::declaresStrictTypes("<?php\n"))->toBeFalse();
    expect(StrictTypesDeclarationScanner::declaresStrictTypes("<?php\n\ndeclare(strict_types=1);\n"))->toBeTrue();
    expect(StrictTypesDeclarationScanner::declaresStrictTypes(
        "<?php declare(strict_types=1); declare(strict_types=0);\n"
    ))->toBeFalse();

    $undeclared = [];
    foreach ($targets as $target) {
        $source = file_get_contents($target['absolute']);
        if ($source === false) {
            throw new RuntimeException("読み取れないファイルがあります: {$target['relative']}");
        }
        if (! StrictTypesDeclarationScanner::declaresStrictTypes($source)) {
            $undeclared[] = $target['relative'];
        }
    }

    expect($undeclared)->toBe([], /* 下記の失敗メッセージ */);
});
```

### 失敗メッセージの仕様

```
declare(strict_types=1) を欠く PHP ファイルがあります (N 件):
  - <相対パス>
  …
直し方: 各ファイルの <?php の直後に次の 1 行を置く (前に他の文・出力を置かない):
  declare(strict_types=1);
補足 1: 01 / 0x1 / declare(ticks=1, strict_types=1) / 冒頭より後ろでの strict_types の
        再宣言などは PHP としては有効だが、本リポジトリは表記を上の正準形 1 つに揃えるため
        受理しない。
補足 2: `php artisan vendor:publish` の直後は骨組み由来ファイルが宣言を失う。
        publish した内容を確認したうえで宣言を足してから commit すること。
補足 3: 免除の登録簿は意図的に持たない。宣言できない事情ができたときは
        allow-list を足す前に設計レビューを通すこと。
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (test closure は `void`)
- [x] null 安全 (`file_get_contents()` の false を例外にする = 無音 skip しない)
- [x] DTO 返却なし (テスト)
- [x] Generics: `TrackedPhpSourceFiles::all()` の戻り型注釈をそのまま使う
- 注: `tests/` は PHPStan 解析対象外 (`app` / `config` / `database` / `routes` のみ) だが、
  型注釈は既存テストと同水準で書く

### テスト計画

gate 自体がテストである。**テストファーストの手順を実測で残す**:

- [ ] 段 1: 施策 4 の前に gate を走らせ、**未宣言 32 本を列挙して赤くなる**ことを確認する
      (件数と一覧が上表と一致すること)
- [ ] 段 2: 施策 4 で宣言を足し、gate が緑になることを確認する
- [ ] 段 3: 任意の 1 ファイルから宣言を一時的に外すと再び赤くなることを確認する
      (実施記録を `devnotes/.../notes-implementation.md` に残す。ファイルは元に戻す)
- [ ] 段 4: 床値の pin が効くことを確認する (走査域を一時的に `app/` へ狭めると赤になる)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **1543 ファイルの tokenize による実行時間**: 実測して 5 秒を超えるようなら、
  判定を打ち切り可能な形 (先頭 8 トークンで決着) にしてあるので tokenize 自体を
  `PhpToken::tokenize()` に変えるなどの調整を行う。**先読みバイト数で切る最適化は採らない**
  (長い冒頭コメントがあるファイルで偽陽性になるため)
- **git が無い環境で走らせると例外**: 意図どおり (fail-open 防止)。
  既存 `NoNonCompoundGlobalUseTest` も同じ前提に立っている

---



---

## その他の修正 (差分の要点)

- 「PHP 8.4 での実測」節に、判定器が後続の `strict_types` 再宣言も拒否する理由 (規約 + 将来の fail-open 予防) を追記
- gate の失敗メッセージ 補足 1 に「冒頭より後ろでの strict_types の再宣言」を追加
- 施策 1: `NoNonCompoundGlobalUseTest` の import 入れ替え diff を明記 (`Process` の参照はそのファイルの L48 のみ)、
  一時ディレクトリ再帰削除の prefix guard をテスト計画へ追加
- 施策 4: 起動確認の順序を `config:clear` → `route:list` → `config:cache` → `config:clear` に確定
- 施策 5: `docs/template-divergence.md` D15 の「保証しないもの」へ再宣言拒否を追記
- 末尾「この設計が保証しないもの」へ再宣言拒否を追記

## 確認してほしいこと

1. 判定器の `hasLaterStrictTypesDeclare()` に見落とし・偽陽性が無いか
   (特に文字列リテラル・配列リテラル・クラス定数名などとの取り違え)
2. 実測照合器の一時ファイル処理と、完全ソースを渡す形に変えたことで残る穴が無いか
3. 他に Critical / Warning が残っていないか

全体判定 (APPROVED / CHANGES_REQUESTED) を明記してください。
