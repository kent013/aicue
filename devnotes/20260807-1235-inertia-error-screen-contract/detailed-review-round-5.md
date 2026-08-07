## 再レビュー結果

### S1: APPROVE

追加指摘はありません。

### S2: APPROVE

追加指摘はありません。

### S3: APPROVE

追加指摘はありません。

### S4: REQUEST_CHANGES

[Warning] 「既存のCache-Control directiveを落とさない」テストの準備方法が、実装構造と一致していません。

`render()`は原応答を変更せず、新しいInertia応答を生成しています。

```php
$rendered = Inertia::render(...)
    ->toResponse($request);
```

そのため、テスト用routeや`render()`へ渡す原応答に`must-revalidate`を設定しても、そのdirectiveは`$rendered`へ移植されません。現在案のテストは`set()`への退行ではなく、「原応答のCache-Controlをallowlist移植していない」という別契約で失敗します。

修正案は次のいずれかです。

- キャッシュポリシー適用をテスト可能な小さなメソッドへ切り出し、`must-revalidate`を持つ対象レスポンスへ直接適用して保持を検証する。
- Inertiaが生成した`$rendered`に既存directiveが確実に存在することを確認できるなら、その実directiveの保持をFeatureテストで固定する。
- 原応答のCache-Controlも移植する要件なら、allowlistへ明示的に追加する。ただし現在のdeny-by-default方針から契約変更になるため、単にテストを通す目的では追加しない。

最も素直なのは、例えば以下をprivateメソッドへ切り出し、その動作を製品経路のFeatureテストとmutationで固定する設計です。

```php
private static function applyCachePolicy(Response $response): void
{
    $response->setVary([Header::INERTIA, Header::VERSION, 'Accept'], replace: false);
    $response->headers->addCacheControlDirective('no-store');
    $response->setPrivate();
}
```

privateメソッドを直接テストするためだけにreflectionを使うのは避け、FeatureテストではInertia生成応答が持つ既存directiveを事前に特定するか、キャッシュポリシー自体を独立した小さなSupportクラスとして扱うかを選んでください。後者は既存directive保持を明確な契約にする場合に限り妥当です。

`addCacheControlDirective('no-store')`と`setPrivate()`の実装自体、guest/authenticatedの最終応答テスト、`Vary: Cookie`を採らない理由は妥当です。

### S5: APPROVE

追加指摘はありません。

### S6: APPROVE

M17まで同期され、追加指摘はありません。

## 全体判定

**CHANGES_REQUESTED**

残件はテストセットアップの成立性だけです。原応答と新規Inertia応答を混同せず、実際に変更対象となる`$rendered`へ既存directiveを持たせて保持を検証できる設計へ直せば承認可能です。