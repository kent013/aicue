Round 3で設計上の主要課題は解消されています。ただし、P-10bが例外型の不一致で失敗するため、現状では承認できません。

## S1

判定: APPROVE

fail-first、バイト一致、非破壊整形確認は妥当です。

## S2

判定: REQUEST_CHANGES

- [Critical] `Assert::writable($base)` とP-10bの期待例外型が一致しません。

  Webmozart Assertの失敗は通常 `Webmozart\Assert\InvalidArgumentException` であり、PHPの `RuntimeException` ではありません。0500の親を渡したP-10bは、`mkdir()` より前の `Assert::writable()` で落ちるため、次の表明が失敗します。

  ```php
  ->toThrow(RuntimeException::class);
  ```

  修正案: この境界ではAssertではなく明示検査で `RuntimeException` へ統一してください。

  ```php
  if (! str_starts_with($base, DIRECTORY_SEPARATOR)) {
      throw new RuntimeException('観測用の置き場所は絶対パスであること');
  }

  if (! is_dir($base) || ! is_writable($base)) {
      throw new RuntimeException('観測用の置き場所を使用できない');
  }
  ```

  またはP-10b側でAssert由来の例外を期待できますが、呼び出し側に一貫した例外契約を提供する前者が適切です。

外側ディレクトリのrealpath、リポジトリ内拒否、0700検査、finally削除は妥当です。

## S3

判定: APPROVE

実働証明と責務記述に問題はありません。

## S4

判定: REQUEST_CHANGES

- [Warning] P-10dは `storage/framework/testing` が存在しない場合に作成しますが、テスト終了時にその基底ディレクトリを戻しません。また生成物検査の対象にも `storage/framework/testing` が含まれていません。

  修正案: テスト開始時の存在を記録し、このテストが作った場合だけfinallyで削除してください。あるいは既存のリポジトリ内ディレクトリを使い、新しい基底を作らない形にしてください。

P-10cはファイルと下位ディレクトリを含む再帰削除まで検査できており、Round 2の問題は解消しています。

## S5

判定: APPROVE

載せ替えない判断と記録方法は妥当です。

## S6

判定: REQUEST_CHANGES

- [Warning] 軸Aの名称がまだ「起動能力」のままです。診断表示だけの参照も母集団に含むため、実体は起動能力ではありません。

  修正案: 表・コード・テスト名を「`PHP_BINARY` 参照」へ統一してください。

- [Warning] 「完全修飾名は追えない」という保証外の説明と、次の正例が整合していません。

  ```php
  Tests\Support\Process\BootProbeRunner::run([])
  ```

  `token_get_all()` ではこの名前は通常単一の `T_NAME_QUALIFIED` になります。G-6が検出するなら「qualified nameの末尾要素は字句的に扱う」と保証範囲へ明記し、先頭`\`付きの `T_NAME_FULLY_QUALIFIED` を扱うか否かも固定してください。扱わないなら、この正例を削除してください。

恒久的な正負例の追加と、gate名を参照inventoryへ狭めた対応自体は妥当です。

## 受入条件

判定: REQUEST_CHANGES

- [Warning] `--exclude-filter` はテストファイルを除外するオプションではなく、テスト名のパターンを除外します。Pestのテスト名に `BootProbeRunnerTest` というファイル名が含まれる保証はなく、新規2ファイルを正しく除外できない可能性があります。

  修正案: 実装前に既存テストのファイルパス一覧を保存し、実装後もその同じファイル集合を明示して実行してください。または新規ファイルを除いたファイル一覧を機械生成し、その一覧をテストランナーへ渡す方式に確定してください。

## 全体判定

CHANGES_REQUESTED

必須修正は `Assert::writable()` とP-10bの例外型不一致です。併せて、P-10dの基底ディレクトリ後始末、S6の名称・qualified nameの保証範囲、実行時間比較コマンドを修正すれば承認可能です。