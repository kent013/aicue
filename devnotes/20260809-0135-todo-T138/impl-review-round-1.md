手元のコマンド実行は行わず、提示 diff と詳細設計の照合だけでレビューします。

**指摘**

`tests/Architecture/ExternalSeamInventoryTest.php`

[Warning] `EXTERNAL_SEAM_RULE_KINDS` の検査が「キー集合 exact-fit + 非空」までで、各 rule が許す `ExternalSeamKind` の値集合を固定できていません。  
設計は「規則ごとに名乗ってよい種別が固定」「種別が登録者の言い値にならない」を要求していますが、現実には例えば `http_facade_reference` に `ExternalSeamKind::Mail` を追加しても、このテスト自体は即赤になりません。その後 `FxRateService` に `kind: Mail` の entry を足すと、M15 相当の残骸検出もすり抜けます。

修正案: test 4 でキーだけでなく値も enum value 化して完全一致させるべきです。

```php
$expected = [
    'payment_client_call' => ['payment'],
    'payment_client_construction' => ['payment'],
    'socialite_facade_reference' => ['social_login'],
    'http_facade_reference' => ['captcha', 'market_data'],
    'mail_facade_reference' => ['mail'],
];
```

この穴は gate の主目的である「分類を登録者の言い値にしない」に直接関わるため、修正対象です。

`tests/Support/PhpReferenceScanner.php` / `tests/Support/ExternalClientBoundaryScanner.php`

[Suggestion] group use の修正は、既存実装の docblock と期待仕様に照らすと妥当です。`app/` に group use が無く T126 母集団が変わらないなら、今回の抽出内で直す判断も許容できます。  
ただし「ExternalClientBoundaryScanner の振る舞い保存」を強く証明したいなら、`ExternalClientBoundaryScannerTest` 側にも group use の positive control を 1 本追加した方が説明が締まります。現状は `ExternalSeamScannerTest` では固定されていますが、抽出元 API 側の回帰証拠としては少し間接的です。

`tests/Support/ExternalSeam/ExternalSeamScanner.php`

[Suggestion] `->stripe()` の抑制解除条件は `Stripe\` / `Laravel\Cashier\` の import が 1 つでもあれば通るため、Stripe 例外だけを import しているファイル内の無関係な `$client->stripe()` は adopted になります。これは fail-closed なのでセキュリティ上の偽陰性ではありませんが、偽陽性を entry 登録で黙らせる余地はあります。意図した保守上の摩擦なら、合成テストで「Stripe 例外 import + 無関係 `->stripe()` は adopted になる」または「抑制しないのは fail-closed 方針」と明示しておくとよいです。

**ファイル別判定**

`app/Enums/Security/ExternalSeamClassification.php`  
問題なし。`Exempt` を語彙として残しつつ gate で使用禁止にする設計と一致しています。

`app/Enums/Security/ExternalSeamDimension.php`  
問題なし。保証範囲の限界も過大表現になっていません。

`app/Enums/Security/ExternalSeamKind.php`  
問題なし。`ObjectStorage` / `Llm` を委譲専用にする設計と一致しています。

`app/Providers/FakeExternalsServiceProvider.php`  
問題なし。`testing.fake_externals` + allowlist 環境 + ProductionEnvGuard 前提の三重構造で、captcha fake の本番混入は通常経路では閉じています。SSO を fake しない記述も正確です。

`config/testing.php`  
問題なし。fake 対象が Stripe + captcha に広がったこと、SSO は fake しないことが書けています。

`tests/Architecture/ExternalFakeWiringInvariantTest.php`  
問題なし。定数名の置換と test 名是正のみで、設計どおりです。

`tests/Architecture/ExternalSeamInventoryTest.php`  
[Warning] 上記のとおり rule→kind 値集合が固定されていない点は要修正です。その他の 15 本構成、抑制 0 件、委譲先 test 名抽出、排他的被覆は設計に沿っています。

`tests/Architecture/PromptGuardrailTest.php`  
問題なし。scanner 移設後も test 名・本体を維持する設計に合っています。

`tests/Feature/Captcha/RecaptchaVerifierFakeWiringTest.php`  
問題なし。flag on の遮断、flag off の負のコントロール、secret 未設定時の現状追認が揃っており、S7 の fail-secure 証拠として十分です。

`tests/Support/ExternalClientBoundaryScanner.php`  
概ね問題なし。`disk` / `getClient` の receiver 非依存、Stripe global setter、`dropOrphanGetClientSites()` への入力順は設計と一致しています。group use 修正については上記 Suggestion あり。

`tests/Support/ExternalFakes/ExternalFakeWiringInventory.php`  
問題なし。captcha binding 追加と flag 名の是正は S7 と一致しています。

`tests/Support/ExternalSeam/*`  
概ね問題なし。facade canonical を `NameReference` のみにする契約、`->stripe()` suppressed collection、委譲専用 kind の分離はいずれも設計どおりです。`ExternalSeamScanner` には上記 Suggestion あり。

`tests/Support/PestTestNameScanner.php`  
問題なし。コメント・文字列・メソッド呼び出し・関数宣言の負のコントロールがあり、単純 `str_contains()` の穴を閉じています。

`tests/Support/PhpReferenceScanner.php`  
概ね問題なし。抽出後も `ExternalClientBoundaryScanner` の主要挙動は保存されています。group use の挙動変更は妥当なバグ修正ですが、抽出元 API 側にも専用回帰を足すとより明確です。

`tests/Support/Prompts/PrismDirectDispatchScanner.php`  
問題なし。移設による `appDir()` の段数、`scannedFiles()` 追加、既存検出ロジック維持は設計と一致しています。

`tests/Unit/Architecture/ExternalSeamScannerTest.php`  
問題なし。負のコントロールは十分厚く、facade 二重検出、部分修飾名の限界、group use、抑制 collection が固定されています。

`tests/Unit/Architecture/PestTestNameScannerTest.php`  
問題なし。S5 test 12 の意味を支える負のコントロールが入っています。

`docs/architecture.md` / `AGENTS.md` / `docs/app-integration-guide.md` / `.env.bughunt.local.example`  
差分本文が省略されているため、文言単位の精査はできません。提示された要約の範囲では、保証しないものを `docs/architecture.md` 正本に寄せ、他は要約にする方針は設計と一致しています。

**全体判定: CHANGES_REQUESTED**

主な理由は `ExternalSeamInventoryTest` の rule→kind 値集合が固定されておらず、設計が求める「種別が登録者の言い値にならない」保証に穴が残っているためです。その他は概ね設計どおりです。