# 対応マトリクス: design-review Round 3

## [Critical] S2: `Assert::writable()` の例外型と P-10b の期待が一致しない

- 判断: **対応する**
- 根拠: 正しい。Webmozart Assert は `Webmozart\Assert\InvalidArgumentException` を投げるので、
  0500 の親を渡す P-10b の `->toThrow(RuntimeException::class)` は落ちる
  (`InvalidArgumentException` は `RuntimeException` を継承しない)。
  設計のまま実装すると**新設テストが最初から赤**になる。
- 対応内容: `withEnvironmentDirectory()` の入口の 3 検査を **`Assert` ではなく明示検査**へ置き換え、
  すべて `RuntimeException` に統一した (提案されたコードのとおり)。
  呼び出し側へ**一貫した例外契約**を出す形にする。`Webmozart\Assert\Assert` の `use` も不要になったので
  変更一覧から外した。

## [Warning] S4: P-10d が作った基底ディレクトリを戻さない / 生成物検査の対象に入っていない

- 判断: 対応する
- 対応内容: (a) テスト開始時の `storage/framework/testing` の存在を記録し、
  **このテストが作った場合だけ `finally` で削除**する形へ直した。
  (b) 受入条件の「ignored な既知の書き出し場所」の一覧へ **`storage/framework/testing/`** を足した。

## [Warning] S6: 軸 A の名称が「起動能力」のままで実体と合わない

- 判断: 対応する
- 根拠: 正しい。診断表示だけの参照 (`NoNonCompoundGlobalUseTest`) や設定値としての参照
  (`PipelineSmokeCommandTest`) も母集団に入るので、「起動能力」は実体より広い。
  **機能の名前に立ち返れ**という思考原則の適用でもある。
- 対応内容: 表・コード・説明の全てで軸 A を **「`PHP_BINARY` 参照」** へ統一した
  (関数名は `phpBinaryReferenceInventory()` のままで整合する)。

## [Warning] S6: 「完全修飾名は追えない」という保証外の説明と G-6 の正例が矛盾する

- 判断: **対応する (保証範囲を書き分ける)**
- 根拠: 正しい。`Tests\Support\Process\BootProbeRunner` は `token_get_all()` では
  単一の `T_NAME_QUALIFIED` になるので、**末尾要素で照合すれば字句的に扱える**。
  一方で「名前解決 (どのクラスを指すかの解決)」は依然としてできない。
  この 2 つを混ぜて「完全修飾名は追えない」と書いたのが誤りだった。
- 対応内容: 保証範囲を次のように書き分けた。
  - **扱う**: 名前トークン (`T_STRING` / `T_NAME_QUALIFIED` / `T_NAME_FULLY_QUALIFIED`) の
    **末尾要素**での照合。したがって `BootProbeRunner::run(` /
    `Tests\Support\Process\BootProbeRunner::run(` / `\Tests\…\BootProbeRunner::run(` は**すべて検出する**
  - **扱わない**: `use … as X;` の**別名**での呼び出し (`X::run(`)。
    これは名前解決を要するので追えない — 保証しないことへ明記し、**負例として恒久テストに固定**する
  - 見本表へ `\Tests\Support\Process\BootProbeRunner::run([]);` (正例) と
    `use … as Runner; Runner::run([]);` (負例 = 射程外) を追加した

## [Warning] 受入条件: `--exclude-filter` はファイルを除外するオプションではない

- 判断: **対応する (測り方そのものを変える)**
- 根拠: 正しい。Pest の `--exclude-filter` はテスト**名**のパターンを除くもので、
  ファイル名が入る保証がない。ファイル一覧を渡す方式も数千パスの受け渡しになり運用に耐えない。
- 対応内容: **除外をやめ、全体走行 3 本の引き算で測る**形へ変えた。
  - (a) 実装**前**の `composer test` 全体の中央値
  - (b) 実装**後**の `composer test` 全体の中央値
  - (c) 実装**後**の新規 2 ファイルだけの中央値 (`composer test -- <2 パス>`)
  - 判定: **(b) − (a) − (c) が (a) の 5% 以内**。超えたら**閾値を動かさず原因を報告する**
  除外オプションに依存せず、いずれも普通の実行で測れる。

## [APPROVE] S1 / S3 / S5

- 判断: 対応不要
