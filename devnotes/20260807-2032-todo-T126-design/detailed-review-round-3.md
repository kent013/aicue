## 全体判定

**CHANGES_REQUESTED**

Round 2 の3件は適切に解消されています。ただし、施策5の setter gate に1件、施策6のサンプルコードに1件、設計段階で直すべき残件があります。Critical はありません。

## 施策別判定

| 施策 | 判定 |
|---|---|
| 1. pin 値の単一出典 | APPROVE |
| 2. Stripe 専用 provider | APPROVE |
| 3. AWS 3構築点への配線 | APPROVE |
| 4. `headObject` 上書き | APPROVE |
| 5. enum / 目録 gate | REQUEST_CHANGES |
| 6. Stripe 呼び出し予算 | REQUEST_CHANGES |
| 7. web 経路の Bulk 禁止 | APPROVE |
| 8. timeout 例外分類 | APPROVE |
| 9. 帯の張り替え | APPROVE |

## 設計で解くべき残件

### 施策5: tests側setter gateが「ファイル許可」に留まっている

[Warning] `tests/` 側の期待値が「明示2ファイルのみ」になっているため、許可済みファイル内へ setter を追加しても検出できません。「exact-fit」という説明と検査強度が一致していません。

実際の設計例では、同じファイル内に複数のsiteがあります。

- Providerテストの `setHttpClient`: 初期状態設定と`finally`復元の2 site
- Providerテストの `setMaxNetworkRetries`: 初期状態設定と`finally`復元の2 site
- CallBudgetテストの `setHttpClient`: fake設定と`finally`復元の2 site

修正案: 期待値をファイル集合ではなく、少なくとも「相対パス × シンボル × site件数」で固定してください。

```text
ExternalClientTimeoutServiceProviderTest.php:
  setHttpClient            = 2
  setMaxNetworkRetries     = 2

AutoRechargeStripeCallBudgetTest.php:
  setHttpClient            = 2
  setMaxNetworkRetries     = 0

上記以外:
  setter                   = 0
```

可能なら関数・メソッド名もsite情報に含めます。行番号は整形で変わるため期待値の識別子にはせず、失敗時の診断情報としてのみ出すのが妥当です。

### 施策6: `Assert` のimportが欠落している

[Warning] `CountingStripeHttpClient` は `Assert::isArray()` を呼んでいますが、提示されたコードには次のimportがありません。

```php
use Webmozart\Assert\Assert;
```

このまま実装すると`Tests\Support\Billing\Assert`として解決され、実行時に失敗します。変更後コードへimportを追加してください。

併せて、`$expectedException` のPHPDocまたはdataset shapeは、単なる`string|null`ではなく次に狭めるとPHPStan上の意図が明確です。

```php
/** @param class-string<Throwable>|null $expectedException */
```

後者はSuggestion相当ですが、import欠落は設計書のコードを修正する必要があります。

## Round 2 指摘の確認

adapter/fake分類は十分です。検査5bにより、境界目録の`adapter`集合と面分類目録が機械的に結ばれています。

setterのapp側期待集合も正しくなりました。`CurlClient::instance()`を0件とする設計はprovider実装と整合しています。残るのはtests側をファイル単位からsite件数まで狭める点だけです。

SES fallbackの削除も十分です。`MailManager`経由で構築できなければ前提破綻としてfailさせる方針で、vendor契約のgateとして成立しています。

## 実装時確認へ落としてよい事項

以下は現在の整理のまま実装時確認で問題ありません。

- `config/filesystems.php` / `config/services.php`の直接`require`有無
- SES mailerを局所的に解決するための具体的なconfig値
- 新enumが既存TS同期gateの母集団に入らないこと
- AWS SDK/Laravelの実オブジェクトで`@http` / `@retries`が期待どおり見えること
- Stripe fixtureの必要フィールドと厳密な呼び出し回数
- `docs/TODO.md`のT127更新を別責務で行うこと

DTO / JsonResource / Inertia、frontend、DESIGN.md、Atomic Designは引き続き対象外です。