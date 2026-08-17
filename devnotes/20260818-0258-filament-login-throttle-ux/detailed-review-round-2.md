## 再レビュー結論

Round 1の主要指摘は適切に解消されています。特に、実使用クラスによる赤の再現、通常/MFA両方の制限経路、cache分離の根拠、overrideの作用範囲が明確になりました。

仮説と実装方向は妥当ですが、MFAテストに2点だけ契約精度の修正が必要です。

## 施策1: 上限到達の表示検査

判定: **REQUEST_CHANGES**

### [Warning] MFA limiterのキーには `getKey()` ではなく `getAuthIdentifier()` を使うべき

vendorの正本は次です。

```php
$rateLimitingKey = "filament-multi-factor-challenge:{$user->getAuthIdentifier()}";
```

設計では次のように異なる概念を使っています。

```php
$challengeKey = "filament-multi-factor-challenge:{$admin->getKey()}";
```

現在の `AdminUser` では同じ値であっても、認証識別子は主キーと同一とは限りません。将来 `getAuthIdentifierName()` などが変更されると、テストがvendorの実使用キーをseedせず、MFA制限経路へ到達しなくなります。

修正案:

```php
$authIdentifier = $admin->getAuthIdentifier();

expect($authIdentifier)->toBeString();

$challengeKey = "filament-multi-factor-challenge:{$authIdentifier}";
```

実際の戻り値が整数を許すモデル契約なら、文字列アサーションは付けず、vendorと同様に文字列展開してください。

```php
$challengeKey = "filament-multi-factor-challenge:{$admin->getAuthIdentifier()}";
```

確認点の表も `{id}` ではなく `{auth identifier}` と記載するのが正確です。

### [Warning] `assertHasErrors()` ではrequiredエラーを踏んだことを固定できない

現在のテストは次のように任意のエラーが1件あれば通ります。

```php
$component->call('authenticate')->assertHasErrors();
```

設計上の根拠は「確認コード未入力によるrequiredエラー」なので、そのフィールドとruleまで固定しないと、別のエラーが発生した場合にも誤って緑になります。

修正案として、実際のLivewire error bagのキーを実測し、具体的に検査してください。想定される形は次です。

```php
$component
    ->call('authenticate')
    ->assertHasErrors([
        'data.multiFactor.app.code' => 'required',
    ]);
```

Filamentのform assertionがMFA form名を受け付ける構成なら、対応する `assertHasFormErrors()` を使っても構いません。推測ではなく、赤の実測時にerror bagの実キーを確認して確定してください。

### Round 1のRateLimiter指摘への反論

反論を受け入れます。`phpunit.xml` が `CACHE_STORE=array` を `force="true"` で固定し、各テスト・workerでアプリケーションコンテナが分離されるなら、通常Login limiterのキーを自前で複写してclearする必要はありません。

vendor内部キーを不要に複写しない現在の判断が適切です。MFAキーの直接操作は、その経路だけを到達可能にするwhite-box testとして目的があり、リスク欄にも依存関係が明記されています。

そのほか、以下は適切に是正されています。

- 実装前はvendor Login、実装後は独自Loginを同じ振る舞いテストで検査
- 通常側を上限未満に保ったままMFA専用limiterを到達させる
- TOTP偶然一致を未入力requiredによって排除
- page route検査を今回の誤配置に限定
- 正当な管理ページ追加との不要な結合を解消

## 施策2: 独自ログインページとpanel配線

判定: **APPROVE**

通常Login limiterとMFA専用limiterの両方から呼ばれること、`resetValidation()` がerror bag全体を消すこと、各経路で新しいvalidation errorがまだ生成されていないことが正確に記述されています。

実装も以下の点で妥当です。

- vendorの `authenticate()` を複写しない
- 閾値、判定順序、認証処理を変更しない
- 親メソッドのシグネチャとnullableな戻り値を維持
- 認証状態、MFA状態、フォーム値をresetしない
- 新規route、JSON応答、Inertia Props、DTO/JsonResourceへの影響なし
- 認可・tenant境界・セッション再生成処理への影響なし

## 施策3: 流量制限免除の前提検査

判定: **APPROVE**

実使用クラスの解決、継承先本文のtoken走査、宣言元アサーション、negative controlの組み合わせで、免除前提を適切に固定しています。

`authenticate()` を一時overrideして赤を実測する完了条件も、検査の感度確認として妥当です。

## 全体判定

**CHANGES_REQUESTED**

Criticalはありません。施策1の次の2点を局所修正すれば承認可能です。

- MFAキーを `getAuthIdentifier()` に合わせる
- 未入力時のエラーを具体的なフィールドと`required` ruleで検査する