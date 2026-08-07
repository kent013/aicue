## 再レビュー結果

### S1: APPROVE

追加指摘はありません。

### S2: APPROVE

追加指摘はありません。

### S3: APPROVE

追加指摘はありません。

### S4: REQUEST_CHANGES

[Warning] `Cache-Control`の実装が、記載された「既存directiveを縮めず、常に`no-store, private`」という契約を満たしていません。

現在案は次の問題を持ちます。

```php
if (! $rendered->headers->hasCacheControlDirective('no-store')) {
    $rendered->headers->set('Cache-Control', 'no-store, private');
}
```

- 既存に`no-store, public`があれば`private`になりません。
- `no-store`がなく、既存に`must-revalidate`などがあれば`set()`で失われます。
- テストは`no-store`しか確認せず、設計が約束する`private`とdirective保持を固定していません。

`no-store`だけで保存禁止としては十分ですが、設計上`private`も不変条件にするなら、既存directiveへ加算し、矛盾する`public`を除去する実装にしてください。

修正案:

```php
$rendered->headers->addCacheControlDirective('no-store');
$rendered->setPrivate();
```

利用するSymfony APIの実挙動を確認し、少なくとも以下をテストへ追加します。

- guest応答に`no-store`と`private`がある
- `public`が残らない
- 既存の無関係なdirectiveを保持する方針なら、それが失われない

逆に`private`を必須にしないなら、設計・リスク表・コメントを「必須は`no-store`。`private`は新規設定時のみ」に狭め、テストと一致させる必要があります。

[Suggestion] 「セッション由来の分岐はVaryでは宣言できない」は厳密には言い過ぎです。`Vary: Cookie`は可能ですが、キャッシュキーの爆発やcookie全体への依存を招くため不適切、という判断です。その表現にすると技術的に正確です。

素通しBladeを変更しない判断、`X-Inertia-Version`を含む3種の`Vary`、guestを主戦場にしたテスト方針は妥当です。

### S5: APPROVE

追加指摘はありません。

### S6: APPROVE

M16まで記述が同期され、追加指摘はありません。

## 全体判定

**CHANGES_REQUESTED**

残件はS4の`Cache-Control`実装と契約の一致だけです。既存directiveを保持しながら`no-store`と`private`を確実に付与する形へ直せば、全体承認できる状態です。