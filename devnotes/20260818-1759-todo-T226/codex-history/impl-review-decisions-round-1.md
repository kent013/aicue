# 対応マトリクス: impl-review Round 1

## [Warning] import の種類ごとの名前空間が固定されていない (`use function` / `use const` との衝突)
- 判断: 一部対応する / 一部反論する
- 根拠: 単独の `use function App\Support\s3 as S3;` は `collectUseStatement()` の冒頭で
  `;` まで読み飛ばしており、クラスの別名表には入らない (指摘の例はもともと成立しない)。
  一方**グループの内側**に書く形 (`use Aws\{S3, function Support\s3 as S3};`) は
  `function` / `const` トークンを無視して名前だけを拾っていたため、
  クラスの import を上書きする穴が実在した。ここは指摘のとおりである。
- 対応内容: `collectUseStatement()` に要素ごとの種別フラグを持たせ、
  グループ内の `function` / `const` 要素を別名表へ入れないようにした。
  単独形・グループ形の両方を `PhpReferenceScannerTest` の 2 test で固定した。

## [Warning] import の無い短縮名を除外して安全だとする説明が成立しない
- 判断: 対応する
- 根拠: `namespace Stripe; new StripeClient();` は実際に `Stripe\StripeClient` を指す。
  「対象 FQCN に `\` が含まれるから一致し得ない」は誤りである。
  指摘のとおり `new` の直後は構文からクラス名が確定するので、安全に解決できる。
- 対応内容: (1) `new X(` の位置は import が無くても現在の名前空間の下へ解決する
  (`self` / `static` / `parent` は除く)。(2) 残る位置 (型宣言 / `::class` / `instanceof`) は
  **保証しない**と docblock に明記し、`docs/architecture.md` の誤った根拠を書き換えた。
  (3) 中立走査器が `new` を解決するようになったので、
  `PromptWindowScanner::sameNamespaceReferences()` の `new` / StaticCall の補完を外し、
  二重計上を作らないようにした。テストは
  「import の無い短縮名でも new の直後なら解決する」「new self / new static は解決しない」の 2 本。

## [Warning] `AccountDeletionPathGateTest` が `Unresolved` を明示的に捨てている
- 判断: 一部対応する / 一部反論する
- 根拠: 到達辺は**名前が無いので作れない** (未解決 receiver から辿る先を作れない)。
  一方**決済記号の照合は落ちていない** — 同 gate は受け手を見ずメソッド名だけで判定する規則を
  別に持ち、`$gateway::stripe()` / `static::createAsStripeCustomer()` は記号として拾う。
  未解決の静的呼び出しを閉包内で 0 件に pin する案は採らない。`parent::` を含めると
  app/ に 31 件あり、退会経路と無関係な継承呼び出しにまで免除語彙を要求することになるためである
  (規約 (b) の「利用側 gate は検出力の主張を明示的に狭めるか、未解決として失敗させるか」の
  前者を選ぶ)。
- 対応内容: gate の「保証しないもの」へ**受け手を決められない静的呼び出しからの到達辺**を
  明記し、31 件という根拠と「記号照合は落ちない」ことを併記した。
  負のコントロール 11 形目として、未解決 receiver の決済呼び出しが記号に載り、
  辺は 1 本も増えないことを固定した。

## [Suggestion] `ReceiverName` の docblock が API の実際と一致していない
- 判断: 対応する
- 根拠: `is()` / `startsWith()` が未解決を `false` へ畳む以上、
  「構造的にできない」は誇張である。
- 対応内容: docblock を「未解決だと分かることまでを保証する。
  `is()` / `startsWith()` だけで書いた判定は未解決を落とす。拾う側へ倒すかは利用側の判断で、
  型では強制しない」へ書き換えた (`ReferenceSite` 側も同様に狭めた)。

## [Warning] `PhpReferenceScannerTest` に不足している分岐
- 判断: 対応する
- 対応内容: 追加した test は 5 本 —
  単独の `use function` / `use const` 衝突 / グループ内の `function` / `const` /
  import の無い短縮名の `new` / `new self` `new static` の除外 /
  式を受け手にした静的呼び出し (`factory()::make()` / `(new Registry())::make()`)。
  利用側までの伝播は `AccountDeletionPathGateTest` の負のコントロール 11 形目で押さえた。

## [Warning] 母集団の非空検査への言及
- 判断: 見送る (既存で満たしている)
- 根拠: 判定を変えた利用側 gate はいずれも既存の空振り検査を持つ
  (`PromptDefenseWindowGateTest` の「走査根 5 本すべてで PHP ファイルが検出される」、
  `AccountDeletionPathGateTest` の検査 4、`ExternalClientTimeoutInventoryTest` /
  `ExternalSeamInventoryTest` の母集団検査)。中立走査器自身は
  「入力を受け取って候補を返す再利用可能な検出器」であり、規約 (b) の適用対象外である。

## [Warning] `docs/architecture.md` の根拠が反証される
- 判断: 対応する
- 対応内容: 上記のとおり書き換えた。

## [Warning] D1 を「是正済み」とするのは早い
- 判断: 対応する
- 対応内容: グループ use の穴を塞いだうえで、棚卸しの記載を
  「部分修飾名の解決と受け手の fail-closed 化を実施。残る限界は走査器の docblock が正本」へ改めた。
