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
