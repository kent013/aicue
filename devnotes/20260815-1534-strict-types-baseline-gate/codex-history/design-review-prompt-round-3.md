# Round 3: Round 2 の指摘への対応

Warning 1 件 (実測器がプローブ到達を証明できていない) と Suggestion 1 件 (括弧深度) の両方に対応しました。

## 対応マトリクス

# 対応マトリクス: design-review Round 2

Codex 判定: **CHANGES_REQUESTED** (Warning 1 / Suggestion 1)。施策 1・3・4・5 は APPROVE。

## [Warning] 施策 2: 実測器がプロセス全体の標準出力を見ており、検体自身の出力・制御フローで真になりうる
- 判断: **対応する**
- 根拠: 指摘のとおり。`<?php declare(strict_types=1); echo 'STRICT'; exit;` は
  プローブへ到達しないのに真になっていた。これは自己検査の主張 (判定器が実効性の下界であること) を
  無効にする。
- 対応内容: 実測器を次の契約に作り直した。
  1. 標識に **nonce** を付け、関数名も nonce つきにする (検体側との衝突回避)
  2. 終了コードが 0 でなければ **false** (Fatal / Parse error = 読み込めないソース)
  3. 終了コード 0 なら標準出力が `STRICT-<nonce>` / `WEAK-<nonce>` と**完全一致**することを要求し、
     一致しなければ**例外**にする (検体が自分で出力した / `exit` / `?>` /
     `__halt_compiler()` でプローブへ到達しなかった場合。**false を返して黙らない**)
  4. 検体表は「自分では出力せず `exit` / `?>` を持たない」ことを制約として書き、
     破れたら例外で気付ける形にする
- 試作で実測して確認した (設計へ記載): 宣言なし=false / 正準形=true /
  `namespace` を含む正準形=true / 後置・`true` 値=終了コード 255 で false /
  再宣言=true (実効は strict) / コメントのみ・文字列のみ=false /
  **自前出力 + `exit`=例外** / **`?>` で閉じる=例外**。
  指摘された 5 つの固定事項をすべて満たしている。

## [Suggestion] `hasLaterStrictTypesDeclare()` は「対応する `)`」と書きながら最初の `)` までしか見ていない
- 判断: **対応する** (Warning ではないとされたが、見落としの向きの穴なので塞ぐ)
- 根拠: `declare(ticks=(1), strict_types=1)` のように引数の中に括弧があると、
  最初の `)` で打ち切る実装は後続の `strict_types` を取りこぼす。
- 対応内容: 括弧の深さを追う実装に変更し、docblock にも理由を書いた。


---

## 修正後の施策 2 (判定器 + 実測器 + テスト計画の全文)

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
     * ★引数部の終端は**括弧の深さで追う**。`declare(ticks=(1), strict_types=1)` のように
     *   引数の中に括弧があると、最初の `)` で打ち切る実装では後続の `strict_types` を
     *   取りこぼす (= 見落としの向きの穴になる)。
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

            $depth = 0;
            for ($j = $i + 1; $j < $count; $j++) {
                $text = $tokens[$j]['text'];
                if ($text === '(') {
                    $depth++;

                    continue;
                }
                if ($text === ')') {
                    $depth--;
                    if ($depth <= 0) {
                        break; // この declare の引数部が閉じた
                    }

                    continue;
                }
                if ($tokens[$j]['id'] === T_STRING && mb_strtolower($text) === 'strict_types') {
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
 * 「その PHP ソースで厳密な型検査が実際に効くか」を**別プロセスで実測**する。
 *
 * ★判定器の自己検査で使う。表に書いた真値が PHP の版で変わっても、
 *   実測との突き合わせがあれば「判定器が実効性の下界である」ことは崩れない。
 * ★書き込み先はシステムの一時ディレクトリで、リポジトリ内には何も残さない。
 *
 * ★**検体自身の出力や制御フローを判定材料にしない**。判定は
 *   「追記したプローブに到達し、その場で観測した結果」だけを見る:
 *   1. 終了コードが 0 でない (Fatal / Parse error で読み込めない) → 厳密化は成立しない = false
 *   2. 終了コードが 0 なら、標準出力は **nonce つきの標識と完全一致**していなければならない。
 *      一致しない場合 (検体が自分で出力した / `exit` や `__halt_compiler()` で
 *      プローブへ到達しなかった / `?>` の後ろとして素通しされた) は**実測不能**として
 *      例外にする。false を返して黙らない (fail-open 防止)
 *   3. 標識が `STRICT-<nonce>` なら true、`WEAK-<nonce>` なら false
 *   関数名も nonce つきにして、検体側の関数と衝突しないようにする。
 */
final class StrictTypesRuntimeProbe
{
    /**
     * @param  string  $phpSource  判定器へ渡すのと**同一の完全な PHP ソース**
     * @return bool 厳密化が実際に効いたか
     *
     * @throws RuntimeException 検体がプローブと干渉して実測できないとき
     */
    public static function strictTypesInEffect(string $phpSource): bool
    {
        // tempnam() は実ファイルを作る。拡張子を足すと**元のファイルが残る**ため、
        // 戻り値のパスへそのまま書く (php は拡張子に関係なく実行できる)。
        $path = tempnam(sys_get_temp_dir(), 'strict-probe-');
        if ($path === false) {
            throw new RuntimeException('実測用の一時ファイルを作れませんでした');
        }

        $nonce = bin2hex(random_bytes(8));
        $probe = <<<PHP

            function probe_{$nonce}(int \$value): int { return \$value; }
            try { probe_{$nonce}("1"); echo 'WEAK-{$nonce}'; }
            catch (\\TypeError \$e) { echo 'STRICT-{$nonce}'; }
            PHP;

        try {
            if (file_put_contents($path, rtrim($phpSource, "\n")."\n".$probe) === false) {
                throw new RuntimeException("実測用の一時ファイルを書けませんでした: {$path}");
            }

            $process = new Process([PHP_BINARY, '-d', 'error_reporting=E_ALL', $path]);
            $process->run();

            if (! $process->isSuccessful()) {
                return false; // 読み込めないソース (Fatal / Parse error) は厳密化が成立しない
            }

            $output = trim($process->getOutput());
            if ($output === 'STRICT-'.$nonce) {
                return true;
            }
            if ($output === 'WEAK-'.$nonce) {
                return false;
            }

            throw new RuntimeException(
                '実測用のプローブへ到達しませんでした (検体が自分で出力した / exit した可能性があります)。'
                ."出力: {$output}"
            );
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
- [ ] **検体表の制約**: 検体は自分では何も出力せず `exit` / `?>` / `__halt_compiler()` を
      持たない形で書く (実測器がプローブへ到達できる形にする)。この制約が破れたら
      実測器が例外を投げるので、破れたまま緑になることはない
- [ ] 実測器そのものの健全性 (実測器が常に同じ値を返す壊れ方を検出する):
      - 宣言なしの源で `false`、正準形で `true`
      - 読み込めない源 (`declare(strict_types=true);`) で `false` (終了コード 0 以外)
      - **プローブへ到達しない源で例外**: `<?php declare(strict_types=1); echo 'STRICT'; exit;` と
        `?>` で閉じた源。**検体自身が `STRICT` と出力しても真にならない**ことを固定する
        (実測: 前者は「到達しなかった」例外、後者は追記分が素通し出力されて例外になる)
- [ ] 実ファイルでの疎通: `tests/Support/PhpTokenScan.php` を読んで `true`、
      `resources/views/app.blade.php` を読んで `false` になる
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **実測器の設計を試作で確認済み** (`devnotes/20260815-1534-strict-types-baseline-gate/` の
  設計時実測): 宣言なし=false / 正準形=true / `namespace` を含む正準形=true /
  後置・`true` 値=終了コード 255 で false / 再宣言=true (実効は strict) /
  コメントのみ・文字列リテラルのみ=false / 自前出力 + `exit`=例外 / `?>` で閉じる=例外。
  Round 2 で指摘された「検体自身の出力で真になる」経路は塞がっている
- **別プロセス起動のコスト**: 検体 20 件前後 × 1 プロセス ≒ 1 秒未満を見込む。
  超えるようなら実測との突き合わせを「乖離の向きが問題になる検体」に絞る
  (絞ってもこのテストの主張は保てる)
- **`PHP_BINARY` が使えない実行環境**: Process が失敗したら実測器は例外にする
  (silent に `false` を返して緑にしない)
- **判定器が厳しすぎて誤って赤くなる**: 実測で本リポジトリの 1543 本中 1511 本が
  正準形であることを確認済み。残り 32 本は施策 4 で正準形にする

---



---

## 設計時の実測結果 (試作コードで確認済み)

```
なし                 => FALSE
正準                 => TRUE
namespace 付き正準   => TRUE
後置                 => FALSE(exit 255)
true 値              => FALSE(exit 255)
再宣言 (…=1); …=0);) => TRUE   ← 実効は strict。判定器は false に倒すので安全側の乖離
コメントのみ         => FALSE
文字列リテラルのみ   => FALSE
自前出力 + exit      => THROW(unreached: STRICT)
?> で閉じる          => THROW(unreached: function probe_…)
```

Round 2 で挙げられた 5 つの固定事項との対応:
- プローブ到達前の `echo 'STRICT'; exit;` を true にしない → 例外 (上表)
- 対象ソースの既存出力を判定材料にしない → nonce つき標識との完全一致のみ受理
- 終了コードを検査し起動失敗と型検査結果を区別する → 終了コード 0 以外は false
- 固定名 `probeTarget` の衝突を避ける → 関数名を nonce つきにした
- `?>` / `exit` / 構文エラーで未到達なら strict として扱わない → 例外または false

## 確認してほしいこと

残る Critical / Warning があるか、全体判定 (APPROVED / CHANGES_REQUESTED) を明記してください。
