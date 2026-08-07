# 対応マトリクス: design-review Round 4

## [Critical] 施策 5: file-scope の Pest closure が `null` 帰属になり、許可 site と両立しない

- 判断: **対応する**（提案された用途別の帰属規則と `ScanScopeKind` をそのまま採用）
- 根拠: 指摘が正しい。scanner 仕様は「`null` 帰属の site は違反」と書いていたが、
  R6 (Stripe setter) の**許可対象**である 2 つのテストの setter は
  ファイルスコープの Pest closure 内にあり、クラス帰属を持たない。
  同じ規則を全規則へ当てると許可 site が必ず違反になる自己矛盾だった。
- 対応内容:
  1. scope を `null` に潰さず **`ScanScopeKind`（`NamedClass` / `AnonymousClass` / `FileScope`）**
     の 3 値で保持する設計に変更した。
  2. 帰属規則を**用途別**に分けた:
     - R1〜R5（到達境界）: `app/` の `NamedClass` 帰属を要求し、
       `AnonymousClass` / `FileScope` は違反（匿名クラスで境界を跨ぐ抜け道を塞ぐ）
     - R6（Stripe setter）: **クラス帰属を要求しない**。正本は
       `相対パス × シンボル × site 件数`
  3. 診断用の callable 名を「名前付きメソッド名 / `{closure}`」と定義し、
     匿名クラス内の setter も**ファイルと件数には含める**（件数増加で検出できる）ことを明記した。
  4. mutation #20（`app/` の匿名クラス内に `Storage::disk('s3')` を足すと赤）を追加した。

## [Warning] 施策 6: PHPDoc の配置では closure 引数が narrowing されない

- 判断: **対応する**
- 根拠: 指摘が正しい。`test()` 呼び出しの直前に置いた PHPDoc が匿名関数の PHPDoc として
  PHPStan に認識される保証は無く、native 型 `array` の `$responses` が
  `list<array{status: int, body: string}>` へ狭まらない。
  PHPStan level 10 適合を設計書で主張する以上、付与先を明確にする必要がある。
- 対応内容:
  - サンプルコードを **PHPDoc を closure に直接付ける形**へ書き換えた
    （`test('...', /** @param ... */ function (...) {...})->with(...)`）。
  - 施策 6 の「PHPStan 適合チェック」に
    「dataset を受けるテストの PHPDoc は closure に直接付ける。実装時にリポジトリ内の
    既存 Pest dataset パターンを確認して合わせる」を追加した。
