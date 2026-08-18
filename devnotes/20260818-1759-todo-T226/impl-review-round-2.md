Round 1 の主要な実装上の問題は解消されています。残るのは保証記述の過大さと、新しい group use 分岐の負例不足です。

### `tests/Support/PhpReferenceScanner.php`

[Warning] 「保証しないもの」の理由が PHP の構文規則と一致していません。

型宣言、`::class`、`instanceof`、`implements` は、周辺トークンを見ればクラス名の位置だと構文上判定できます。「この位置だけではクラス名だと確定できない」は誤りです。

これらをT226の対象外にする判断自体は妥当ですが、理由は「中立走査器が当該文脈の解析を実装していないため」などへ狭めるべきです。現在の説明は走査器の実装限界をPHPの構文上の限界として記述しています。

部分修飾名、namespace-relative、`new`、静的receiver、group use内の種別分離の実装自体には問題を認めません。

### `tests/Unit/Architecture/PhpReferenceScannerTest.php`

[Warning] `$isClassImport` を要素ごとに `true` へ戻す新分岐が、テストで裏取りされていません。

現在のmixed group useは「class → function → const」の順です。このテストは、typed要素の後も `$isClassImport === false` のままになる欠陥があっても通ります。例えば次の順序を追加すべきです。

```php
use Aws\{function Support\s3 as Helper, S3};
```

これで `S3` がクラスimportとして登録されることを確認すれば、typed要素を飛ばす方向と、その次のclass要素へ復帰する方向の両方が固定されます。共通規約(c)に対する不足です。

それ以外の追加テスト、特に単独typed import、短縮`new`、式receiverの検証は適切です。

### `docs/architecture.md`

[Warning] `PhpReferenceScanner`と同じく、型宣言等について「クラス名だと構文から確定できない」とする説明が不正確です。

検出力を主張しないことは明確になっていますが、保証を狭める根拠を「未実装の文脈解析」に訂正してください。

### `devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md`

[Warning] 「受け手を決められない静的呼び出しのfail-closed化を実施」は、今回明文化した実際の保証より広い表現です。

`ReceiverName`は未解決を判別可能にしただけで、利用側のfail-closedを強制しません。またAccountDeletionの到達辺は意図的に保証外としています。例えば次のように範囲を限定するのが正確です。

> 受け手の解決状態を判別可能化し、外部到達点2系統とprompt窓口ではfail-closed化を実施

### `tests/Support/ReferenceScanResult.php`

[Suggestion] `$imports`の説明にも「クラス／namespace importだけ」を明記すると、`use function`・`use const`を除外する契約が型の利用者から辿れます。

また、複数namespace blockで同じ短縮名を使うと、返却用の集約mapは後勝ちになります。名前解決自体はblock-localな`$aliases`で正しく行われていますが、`$imports`がファイル全体を完全に表すmapではないことは記載した方が安全です。

### `tests/Architecture/AccountDeletionPathGateTest.php`

問題ありません。到達辺の保証範囲を明示的に狭め、決済記号の検出が維持されることを負例で固定しているため、規約(b)が許す選択になっています。

### `tests/Support/ReceiverName.php` / `ReceiverResolution.php` / `ReferenceSite.php`

問題ありません。型が保証する範囲と利用側レビューに委ねる範囲が正確になりました。

### `tests/Support/Llm/PromptWindowScanner.php` / `PromptDefenseWindowGateTest.php`

問題ありません。補完による二重計上を除去しつつ、未解決`load`と`loadUnattributed`の扱いが利用側テストまで固定されています。

### `tests/Support/ExternalClientBoundaryScanner.php` / `ExternalSeam/ExternalSeamScanner.php`

問題ありません。未解決receiverを対象メソッド名に応じて採用する判定はfail-closedです。

### `tests/Unit/Architecture/ExternalClientBoundaryScannerTest.php` / `ExternalSeamScannerTest.php`

問題ありません。部分修飾名の検出と、同一namespace下の別クラスを誤検出しない正例が揃っています。

PHPStan level 10、型のwiden禁止、空振り検査について新たな問題は認めません。

CHANGES_REQUESTED