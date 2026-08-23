### `tests/Architecture/ArchBaselineTest.php`

PHPStanの指摘は解消しています。

- 設計固有コマンドが0エラー
- baseline・抑止コメント・型のwidenなし
- `test($description, fn)`でもdescriptionがテスト名になる
- `expect(...)->not->toBeUsed()->ignoring(...)` の検出力を実測済み

`arch()`を使わない逸脱も、PHPStan level 10と同じ実効性を両立するための合理的な変更です。

[Critical] S4-3cは、先行する `return` による無力化を検出できません。

ファイルの禁止表明より前に次の1行を置いた場合、

```php
return;
```

PHPファイルの読み込みは正常終了しますが、以下はすべて登録されません。

- 禁止表明7本
- S1〜S5
- S4-3c自身

S4-3cは実行されて初めてPestの登録簿を検査するため、自分自身が登録されないケースを検出できません。docblockでは先行する `return` をS4-3bの保証外とし、「登録はS4-3cが保証する」と説明していますが、このケースではその説明が成立しません。

Round 2で指摘したbrace-less `if` は解消していますが、同時に挙げた先行 `return` の経路は残っています。別のテストファイルから `ArchBaselineTest.php` の登録状況を確認する外部自己検査が必要です。少なくとも、外部テストが以下を確認しなければなりません。

- `ArchBaselineTest.php` のfactoryが登録されている
- 7規則のdescriptionが登録されている
- S4-3c自身も登録されている

現在のS4-3cは内部自己検査としては有用ですが、外部検査の代わりにはなりません。

### `tests/Support/Architecture/ArchBaseline.php`

新しい定数構成は整合しています。

- `arch`を0件に固定
- `toBeUsed`を一意な錨に使用
- 錨のoffsetを期待トークン列から導出
- `foreach + test + closure` のヘッダーを完全一致で固定
- 期待形の写しをgate側に作っていない

問題ありません。

### `tests/Support/Architecture/ArchSurfaceScanner.php`

`braceDepthAt()` の保証範囲は正確に狭められています。brace-less制御構文や先行 `return` を検出できないことも明記され、共通規約(b)に適合しています。

走査器自体に新たな誤検出・見逃しは見当たりません。

### `tests/Unit/Architecture/ArchBaselineScannerTest.php`

13cはRound 2の指摘どおり修正されています。

- 波括弧付き制御構文では深さが増える
- brace-less制御構文では深さが増えない
- 走査器の限界を正例として固定
- 到達可能性を `braceDepthAt()` に誤って帰属させていない

問題ありません。ただし先行 `return` は同一ファイル内のS4-3cでは検出できないため、上記の外部自己検査が別途必要です。

### `docs/template-divergence.md`

`arch()`から `test($description, fn)` への変更理由と、実行時登録確認への記述更新は妥当です。

[Warning] 「7本が実際にPestへ登録されたことまで実行時に確かめる」という記述は、現状では先行 `return` に対して成立しません。外部自己検査を追加するか、保証を「S4-3cが登録・実行された場合」に狭める必要があります。ただし後者では無力化防止の目的を満たさないため、外部検査が適切です。

### `tests/Support/Architecture/ArchTokenStream.php`

変更なし。問題ありません。

### `tests/Support/Architecture/GlobalFunctionCallScanner.php`

変更なし。問題ありません。

### `tests/Support/Architecture/VendorArchPresetReader.php`

変更なし。問題ありません。

### `tests/Support/Concurrency/ProcessBarrier.php`

変更なし。保証範囲を狭めたコメントも適切です。

### `tests/Support/TemplateDivergence/LedgerPins.php`

変更なし。問題ありません。

### PHPStan・その他の検証

PHPStan level 10のRound 2指摘は完全に解消しています。`composer test`、Pint、通常のPHPStanも本件について緑です。

`pnpm test`の既存失敗は、clean mainで同一内容を再現しているならT252の回帰とは判断しません。

CHANGES_REQUESTED