## 再レビュー結果

### S1: APPROVE

401 を対象外にする理由が、`AuthenticationException`、`Inertia::clearHistory()`、302 着地の既存契約まで含めて明確になっています。追加指摘はありません。

### S2: APPROVE

`Retry-After` の本文・API 応答ヘッダ・Inertia props・Inertia 応答ヘッダが同じ parser に収束しており、Round 1 の不整合は解消されています。

[Suggestion] `RetryAfterSeconds` の docblock は「利用点は3つ」とありますが、修正後は次の4つです。

- API `details.retry_after`
- API `Retry-After` ヘッダ
- Inertia prop
- Inertia `Retry-After` ヘッダ

利用点の列挙を4つへ更新すると、SoT の監査対象が正確になります。

### S3: APPROVE

named route による期待値比較、非空保証、href の同一オリジン性と重複禁止まで揃っています。TS 側との契約も整合しています。

### S4: REQUEST_CHANGES

[Warning] 419でも `$request->user()` が先に評価されます。

```php
destinations: ErrorScreenDestinations::for(
    $status,
    $request->user() !== null,
),
```

PHPでは引数がメソッド呼び出し前に評価されるため、`forcesGuestDestinations()` が真でも認証状態を取得します。したがってリスク欄の「419 は D1 で認証状態を見ない」は現在のコードでは成立しません。セッション不整合時には `report()` してBladeへ落ち、意図した419復旧画面が出ません。

修正案:

```php
$authenticated = $status->forcesGuestDestinations()
    ? false
    : $request->user() !== null;

$data = new ErrorScreenData(
    status: $status,
    retryAfterSeconds: $retryAfterSeconds,
    destinations: ErrorScreenDestinations::for($status, $authenticated),
);
```

加えて、419で user resolver が例外を投げる設定でも認証参照が起きず、Error画面になるテストを追加してください。

[Warning] `report($e)` の追加がテスト契約になっていません。現在の fail-safe テストは原応答の一致だけなので、将来 `report()` が削除されてもgreenです。

修正案: version resolverを例外化するテストで、原応答の一致に加えて、その例外がreportedされたことも検証してください。Laravelの例外fake/assertion機構を既存作法に合わせて利用します。

### S5: APPROVE

Errorのlazy map除外と非空tuple化により、Round 1の指摘は解消されています。実行時fallbackを追加しない判断も、サーバ固定許可一覧を正本とする設計に整合します。

### S6: REQUEST_CHANGES

[Warning] 設計本文に旧説明が残っています。

冒頭では「4と5は文字列走査」、リスク欄でも「文字列走査の限界」としていますが、変更後は`PhpToken`によるtoken走査です。保証範囲の説明が実装方式と食い違っています。

修正案: 「token走査であり、コメントは除外する。ただし動的呼び出し、別名ラッパー、同名の無関係メソッド、将来追加されるAPIは検出範囲外」と更新してください。

[Warning] 恒久テストの件数が不一致です。変更後コードのfixtureには3呼び出しがありますが、後段のチェックリストでは「2件検出」となっています。

修正案: 期待件数を3件に統一してください。

[Suggestion] mutation表のヘッダが二重に記載されています。片方を削除してください。

## 全体判定

**CHANGES_REQUESTED**

Round 1の指摘自体はすべて妥当に反映されています。残る実質的な変更点は、S4で419時の認証解決を本当に回避することと、`report()`をテスト契約に含めることです。S6の残件は設計書内の整合修正ですが、gateの保証範囲を誤読させるため実装前に直すべきです。