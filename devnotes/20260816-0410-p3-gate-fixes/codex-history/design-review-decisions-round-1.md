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
