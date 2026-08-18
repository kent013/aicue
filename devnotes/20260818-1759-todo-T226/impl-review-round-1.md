主経路の部分修飾名解決は改善されていますが、未解決 receiver の伝播と短縮名の保証に fail-open が残っています。

### `tests/Support/PhpReferenceScanner.php`

[Warning] import の種類ごとの名前空間が固定されていません。

PHP は通常の `use`、`use function`、`use const` を別々の import 表で管理します。今回の `T_USE` 分岐は収集結果を単一の `$aliases` に統合しており、提示されたテストにも種類の衝突がありません。例えば次の二つの `S3` は共存でき、クラス参照には前者だけが効く必要があります。

```php
use Aws\S3 as S3;
use function App\Support\s3 as S3;

new S3\S3Client([]);
```

`collectUseStatement()` が後者を返す場合、実際には `Aws\S3\S3Client` である参照を別名へ誤解決し、外部到達点を見逃します。通常 import だけを class/namespace alias 表へ登録することと、単独・group use の両方について衝突テストが必要です。

[Warning] import のない短縮名を除外して安全だとする説明は成立しません。

次は PHP 上、それぞれ外部クラスを指しますが、`T_STRING` の名前参照・構築を emit しないため検出されません。

```php
namespace Stripe;
new StripeClient(); // Stripe\StripeClient

namespace Aws\S3;
new S3Client([]);   // Aws\S3\S3Client
```

「対象 FQCN に `\` が含まれるから一致し得ない」という前提は誤りです。少なくとも `T_NEW` の直後はクラス名であることが構文から確定するため、一般の `T_STRING` を全件採用せず安全に解決できます。別案として名前空間を `App\...` に限定する機械的不変条件が既にあるなら、それを利用側 gate の保証条件として明示・引用する必要があります。

### `tests/Architecture/AccountDeletionPathGateTest.php`

[Warning] `ReceiverResolution::Unresolved` を明示的に捨てており、規約 (b) に反します。

`deletionPathEdges()` は resolved receiver だけを辺へ加え、`deletionPathClassifySite()` も未解決 receiver を決済名前空間判定から外します。例えば `$serviceClass::run()` が後段の削除サービスへの唯一の辺なら、経路が無言で消えます。

未解決 site を走査結果へ別枠で保持して gate を失敗させるか、理由付き目録へ要求する必要があります。少なくともこの利用側について未解決 receiver の負例が必要です。

### `tests/Support/ReceiverName.php`

[Suggestion] 型の説明と API の実際が一致していません。

docblock は「未解決を黙って候補から外す書き方が構造的にできない」としていますが、`is()` と `startsWith()` は未解決を単に `false` へ畳みます。実際、`AccountDeletionPathGateTest` がその見逃し方を実装できています。

規約を型で強制するなら、これらは unresolved/absent で例外にするか削除し、利用側に解決状態の分岐を要求すべきです。現在の挙動を維持するなら、少なくとも「構造的にできない」という主張は削る必要があります。

### `tests/Support/ReferenceSite.php`

[Suggestion] `ReceiverName` と同様に「未解決を黙って候補から外せない」という説明が過大です。状態を判別可能にした、という実際の保証へ狭めるべきです。

### `tests/Unit/Architecture/PhpReferenceScannerTest.php`

[Warning] 基本的な部分修飾名、namespace-relative、trait import 汚染、variable/static/parent receiver の両方向は適切に固定されています。一方、次の重要な分岐がありません。

- 通常 import と `use function` / `use const` の同名衝突
- mixed group use の import 種別
- import のない短縮名による `new` の解決
- docblock が保証する式 receiver、例えば `factory()::make()`
- `AccountDeletionPathGateTest` まで未解決状態が伝播して失敗すること

提示された赤確認6件は、これらの分岐の検出力を証明しません。

また、テスト総件数は走査根の非空保証にはなりません。再利用走査器自身に非空契約は不要ですが、今回判定を変更した各利用側 gate が既存の母集団非空検査を持つことを、対応する検査から辿れる形にする必要があります。

### `docs/architecture.md`

[Warning] 「対象は `\` を含む完全修飾名なので短縮名の制限で見逃さない」という記述は、上記の `namespace Stripe; new StripeClient()` などで反証されます。機械的に固定された名前空間制約を根拠として示すか、保証しないケースとして正直に残す必要があります。

### `devnotes/20260818-0303-scanner-common-conventions/divergence-survey.md`

[Warning] D1 を「是正済み」とするのは早いです。特に import 種別が単一表へ混ざる場合、部分修飾名を誤った FQCN として emit し、D1 と同じ無言の見逃しが残ります。上記ケースを固定してから完了扱いにすべきです。

### `tests/Support/ExternalClientBoundaryScanner.php`

判定自体は適切です。対象メソッド名を持つ unresolved receiver を採用する方向は fail-closed になっています。ただし、基盤走査器の短縮名・import 種別問題の影響を受けます。

### `tests/Support/ExternalSeam/ExternalSeamScanner.php`

判定自体は適切です。`$gateway::stripe()` を採用する変更と、名前空間相対名を外部クラスと取り違えないテストは妥当です。

### `tests/Support/Llm/PromptWindowScanner.php`

未解決 `::load()` を vendor 読み込み候補へ倒す処理は妥当です。resolved vendor、resolved `PromptDefense`、unresolved の三状態が分離されています。

### `tests/Architecture/PromptDefenseWindowGateTest.php`

部分修飾名の負例、未解決 receiver の負例、同名ローカルクラスの正例が揃っており、この利用側については規約 (b)・(c) に適合しています。

### `tests/Support/ReceiverResolution.php`

問題ありません。三状態へ分ける方針は D1 の設計条件および PHPStan level 10 に適合しています。

### `tests/Unit/Architecture/ExternalClientBoundaryScannerTest.php`

追加された負例・正例は妥当です。ただし、import 種別衝突と import なし短縮構築のケースが不足しています。

### `tests/Unit/Architecture/ExternalSeamScannerTest.php`

既存テストの誤った PHP 名前解釈を修正した点を含め、追加ケースは妥当です。未解決 receiver の採用も固定されています。

PHPStan level 10 に関して、提示差分から新たな型エラーや widen は認められません。問題は型適合ではなく、判別可能になった未解決状態を一部利用側が捨てている点です。

CHANGES_REQUESTED