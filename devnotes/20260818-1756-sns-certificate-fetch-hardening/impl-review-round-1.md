仮説は「本体の取得・例外写像は設計どおりだが、代替テストと静的 gate が T228 の保証を迂回していないか」です。実装本体は概ね妥当ですが、Architecture 保証に修正必須の穴があります。

### `app/Services/Mail/Sns/AwsSnsSignatureVerifier.php`

判定: 問題なし。

実効 URL の選択、両キー拒否、取得後の署名検証、検証成功後だけの昇格が設計 D と一致しています。取得系例外を `InvalidSnsMessageException` と混ぜない構造も適切です。

### `app/Services/Mail/Sns/SnsCertificate.php`

判定: 問題なし。

名前付き生成口により `fromCache` の誤指定を防いでいます。

### `app/Services/Mail/Sns/SnsCertificateFetcher.php`

判定: 問題なし。

SSRF 検査、非ブロッキングロック、2xx 判定、サイズ・PEM 検査、例外写像、素データキャッシュはいずれも設計 C と一致します。URL・PEM・例外本文をログへ出していません。

### `app/Services/Mail/Sns/SnsCertificateUrl.php`

判定: 問題なし。

credential、port、query、fragment、host、path を fail-closed で検査しています。

### `config/services.php`

判定: 問題なし。

トップレベル配置と固定予算の大小関係は設計 B どおりです。

### `tests/Architecture/SnsCertificateFetchContractTest.php`

[Warning] C3 のロック site 検出が完全修飾名を解決しておらず、共通規約 (a) と「site がちょうど1件」という契約を満たしていません。

`snsCertStaticCallIndexes(..., 'Cache', 'lock')` は受け手の文字列が `Cache` の場合しか数えません。例えば次の変更は、実際のロック取得を2箇所に増やしても C3 を通過できます。

```php
use Illuminate\Support\Facades\Cache as LockCache;

Cache::lock(self::CERT_FETCH_LOCK_KEY, 8); // gate 用の1件
LockCache::lock('another-lock', 8);        // 数えられない
```

C12 は import を読み飛ばし、`LockCache` は `T_STRING` なので未解決にもなりません。`PhpReferenceScanner` と呼び出し位置を組み合わせて facade の完全修飾名を解決し、別名・完全修飾形の負例と正例を追加する必要があります。

[Warning] C8/C9 が、宣言している全判定の両方向を固定していません。

特に C11 の自己検査は「宣言を除外して call site を数える」だけで、`rememberVerified()` が `validate()` より前にある負例を判定していません。C1/C13b も対象参照を抽出できる負例はありますが、正当な非対象参照を誤検出しない正例がありません。AGENTS.md の共通規約 (c) と詳細設計 E の「各判定器に負例・正例」に未達です。

### `tests/Feature/Mail/SnsCertificateFetcherTest.php`

[Warning] `Cache::swap($manager)` が T228 の実行時キャッシュガードを置き換えています。

`Cache::swap()` は facade の解決済みインスタンスとコンテナの `cache` binding を部分モックの `CacheManager` に差し替えるため、`PlainDataCacheGuard` の受け皿を経由しなくなります。現時点の F10/F11/F16 がオブジェクトを書いていなくても、「テスト中のキャッシュ書き込みを受け皿側で捕捉する」という不変条件 11 の保証外経路が通常の Feature テストに増えています。

機能面では代替の評価は次のとおりです。

- F10: 読みだけ失敗し、実ロックが生きる条件を再現できている
- F11: 書き込み失敗を best-effort にする契約を固定できている
- F14: 内部再確認を削除すれば HTTP へ進むため、不変条件を十分固定できている
- F15: 実際の非 `LockProvider` store による fail-fast を固定できている
- F16: `Lock::get()` の障害を503へ写像し、HTTPへ退避しないことを固定できている

したがって無名 `Store` を使わない判断自体は妥当ですが、`Cache::swap()` による実行時ガードの迂回は別問題です。既存の guarded manager を維持したまま障害を注入できるテスト seam が必要です。

### `tests/Architecture/CachePayloadPlainDataGateTest.php`

[Warning] 追加された L2/L3 目録は `put` の payload とファイルの役割を分類するだけで、上記 `Cache::swap()` による受け皿の置換を固定・拒否していません。

Feature テストを `role: write` に登録しても実行時ガードの迂回は解消されません。通常経路の bypass をゼロにする T228 の方針と整合させる必要があります。

### `tests/Feature/Mail/SesSignatureMiddlewareTest.php`

判定: 問題なし。

403/503の写像、実署名成功、昇格後の再利用、抑止の冪等性を end-to-end で固定しています。

### `tests/Unit/Mail/AwsSnsSignatureVerifierTest.php`

判定: 問題なし。

両キー競合、Lambda 形式、vendor の上書き契約、署名成功・失敗時のキャッシュ昇格を適切に検証しています。

### `tests/Pest.php`

判定: 問題なし。

DNS resolver の差し替えと専用 array store の再生成は局所的です。`flush()` を使わない判断も適切です。

### `tests/Support/SnsTestData.php`

判定: 問題なし。

署名仕様を vendor の `getStringToSign()` に委譲しており、暗号形式の独自再実装を避けています。

### `tests/Architecture/ExternalClientTimeoutInventoryTest.php`

判定: 問題なし。

### `tests/Architecture/ValidationAttributeCoverageTest.php`

判定: 問題なし。

### `tests/Support/StrayHttpRequestGuard.php`

判定: 問題なし。

### `docs/architecture.md`

判定: 実装説明は妥当。ただし C3 の別名回避と `Cache::swap()` の問題を解消するまでは、機械固定の説明が実際の検出力より強い状態です。

### `docs/ses-mail-runbook.md`

判定: 問題なし。

APPROVED にするには、少なくとも C3 を完全修飾名ベースへ直し、Feature テストから実行時キャッシュガードを置き換える経路を除去する必要があります。

CHANGES_REQUESTED