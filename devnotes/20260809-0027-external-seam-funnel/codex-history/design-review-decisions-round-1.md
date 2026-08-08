# 対応マトリクス: design-review Round 1

Critical 4 件 / Warning 4 件 / Suggestion 2 件。**Critical は全件対応**した。反論は 0 件（すべて実測で指摘の正しさを確認した）。

## [Critical] S1 `PhpReferenceScanner` の `StaticCall` と `NameReference` の責務が矛盾

- 判断: **対応する**
- 根拠: `php -r` で実測して確認した。`Socialite::driver("g")` の正規化トークン列は `T_STRING(Socialite)` / `T_DOUBLE_COLON` / `T_STRING(driver)` / `(` であり、receiver の `Socialite` は**直前が `::` ではない**ため現行 R3 と同じ経路で `NameReference` として emit される。S2 の注記「receiver 側は NameReference として emit しない」は**誤り**だった。
- 対応内容:
  - `PhpReferenceScanner::references()` の docblock に **emission 契約**を明記した（1 つの静的呼び出しは NameReference と StaticCall の 2 site を生む。利用側はどちらか一方を canonical にすること）。
  - `ExternalSeamScanner` の facade 判定を **`NameReference` のみ canonical** に固定した。完全修飾でも receiver は `T_NAME_FULLY_QUALIFIED` = `NameReference` として現れるので取りこぼしは出ない。`StaticCall->receiver` を使うのは決済規則（`Cashier::stripe()`）だけで、`Laravel\Cashier\Cashier` は `FACADE_RULES` に無いため二重検出にならない。
  - 誤った注記ブロックを差し替えた。

## [Critical] S2 `->stripe()` の抑制解除条件が import を見られない

- 判断: **対応する**
- 根拠: 正しい。S1 が「`use` import は site ではない」と明記している以上、`use Stripe\StripeClient;` だけを持つファイルは site を 1 つも出さず、site だけを見る判定は落ちる。
- 対応内容: `PhpReferenceScanner::references()` の戻りを **`ReferenceScanResult { list<ReferenceSite> $sites; array<string,string> $imports; }`** にした。`ExternalSeamScanner::hasPaymentNamespace()` は **site の名前 ∪ import の FQCN** で判定する。import を adopted site に混ぜない方針は維持。

## [Critical] S5 mutation M3 が「赤くならないのが正解」になっている

- 判断: **対応する**
- 根拠: 正しい。赤化確認リストに「赤くならない」項目を混ぜると手順として破綻する。
- 対応内容: M3 を coverage 表から外し、**`P1`（positive mutation = 緑のままであることを確認する別枠）** へ移した。P2 / P3 も同じ別枠に整理した（P3 は「完全一致を接頭辞に変えると S6 #6 が赤」= 規則の narrow さが効いていることの確認）。

## [Critical] S5 mutation M4（`FACADE_RULES = []`）は空振り防止を赤にしない

- 判断: **対応する**
- 根拠: 正しい。payment 規則が残るため `adopted` は非空のままで、テスト 2 は緑。
- 対応内容: M3（新）= `FACADE_RULES = []` の期待する赤を **テスト 1（stale 側）+ テスト 7 + テスト 10** へ訂正し、「テスト 2 は赤にならない」と明記した。空振り防止を赤にする mutation は **M4（全規則の無効化）** として独立させた。

## [Critical] S6 #3 が S1/S2 の仕様では成立しない

- 判断: **対応する**（S2 の Critical と同一の修正で解消）
- 対応内容: テスト名を `走査器: import だけで決済名前空間を知るファイルの ->stripe() を検出する` に変え、「`use` は site ではないため `ReferenceScanResult::$imports` を見なければ**必ず落ちる**ケース」であることを検証内容に明記した（design-review Round 1 の回帰テストとして位置づけた）。

## [Warning] S1 `T_NAME_QUALIFIED` の `ltrim` は名前解決として不完全

- 判断: **対応する（ただし「直さない」形で）**
- 根拠: 指摘は正しいが、これは**現行 `ExternalClientBoundaryScanner` の既存挙動**であり、S1 は振る舞い保存が目的。ここを直すと T126 の母集団が変わり、S1 の唯一の安全弁（既存テストを 1 行も変えずに緑）を失う。
- 対応内容:
  - `references()` の docblock に**名前解決の限界**を明記（現在の namespace への相対解決も先頭 segment の alias 解決も行わない）。
  - S6 に **#18 `走査器: 部分修飾名は解決しない (既存 gate と同じ限界を固定する)`** を追加し、限界を**テストとして固定**した（将来直すときに必ず差分が出る）。
  - S6 #19（`use ... as Http` で alias 先が facade でないケース）も追加し、alias マップが名前ではなく解決先で判定していることを固定した。
  - 詳細設計「保証しないもの」に 10 項目目として明記した。

## [Warning] S2 facade 判定の二重検出

- 判断: **対応する**（S1 の Critical と同一の修正で解消）
- 対応内容: canonical rule（`NameReference` のみ）を明記し、S6 #9 / #11 / #12 の期待値を「ちょうど 1 件」「各 1 件」「合計 2 件」に具体化した。

## [Warning] S5 テスト 13 の実装手段が未確定

- 判断: **対応する**
- 根拠: 正しい。「走査結果に Aws 由来 site が 0 件」は自明な緑。
- 対応内容: `ExternalSeamScanner::ruleSymbols()` という **test 専用の公開 API** を新設し（private const を Reflection で覗かない）、テスト 13 は「`ruleSymbols()` の全シンボルが `Aws\` / `League\Flysystem\` / `Illuminate\Filesystem\` / `Prism\` の接頭辞を持たない」+「キー集合が `ExternalSeamRule::cases()` と exact-fit」を検査する形にした。M11 で赤化を実測する。

## [Warning] S7 Feature test の環境復元手順

- 判断: **対応する**
- 根拠: 正しい。既存作法を明示しないと実装者が独自 helper を作りかねない。
- 対応内容: repo に共通 helper が無いことを実読で確認したうえで、`ExternalFakeWiringInvariantTest` 3-2 / 3-3 の `$originalFlag` / `$this->app['env']` 退避 + `try/finally` 復元の形を**そのまま使う**と明記（新 helper を作らない = 思考原則 2）。`Http::fake()` の扱いも補足した。

## [Suggestion] S3 語彙 enum を `app/Enums/Security` に置く理由

- 判断: **対応する**
- 対応内容: 「設計判断: 語彙 enum を `app/Enums/Security/` に置く理由」節を新設。既存作法（`GatewayFailureObservationExemption` / `ExternalClientBoundaryExemption` / `TwoFactorStepUpExemption`）に揃え、**語彙は本番の型・目録は検査の宣言**という分担を明記した。

## [Suggestion] S4 / S8

- 判断: **対応不要**（APPROVE。指摘なし）
