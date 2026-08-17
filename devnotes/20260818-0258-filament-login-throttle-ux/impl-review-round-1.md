検証仮説は「Filament の共通拡張点が通常・MFA の両経路で動的ディスパッチされ、追加した error key が現在表示中の Filament field と一致するなら、2 つの台帳保証が成立する」です。実装本体はこの仮説を満たしていますが、テストに修正必須の問題があります。

### `app/Filament/Auth/Login.php`

判定: 妥当

- `authenticate()` を複写せず、vendor の `getRateLimitedNotification()` だけを上書きしているため、上限値 5、減衰 60 秒、判定順序は維持されています。
- Filament の通常上限と MFA 専用上限はいずれも同メソッドを呼ぶため、動的ディスパッチにより両経路へ是正が適用されます。
- `resetErrorBag()` 後に案内を1件だけ追加するため、前要求の理由を残さないという台帳保証に一致します。
- 通常画面の `data.email`、MFA 画面の `data.multiFactor.app.code` は、それぞれ既存の検証エラーと同じ key です。単なるトーストではなく表示中の入力欄へ届く構造になっています。
- `userUndertakingMultiFactorAuthentication` による分岐も、vendor が表示フォームを切り替える状態と一致しています。
- `auth.throttle` に実際の残り秒数を渡す変更は、詳細設計からの意図的な拡張として妥当です。
- PHPStan level 10 上も、親メソッドとのシグネチャ、翻訳結果の型絞り込み、例外プロパティの防御的処理に問題は見当たりません。
- エラー表示によって新たに分かるのは、既存トーストでも通知されていた流量制限状態と残り時間だけです。MFA の存在も既にチャレンジ画面上で明らかなため、追加の情報漏えいはありません。

注意点として、通常ログインの実キーはコンポーネントクラス名が変わるため、デプロイ時に一度変化します。これは差分内でも明示されており、書式・閾値・IP 単位という規約は維持されています。MFA 専用キーは変わりません。詳細設計で受容済みの一時的な計数リセットとして妥当です。

### `app/Providers/Filament/AdminPanelProvider.php`

判定: 妥当

- `->login(Login::class)` は Filament v4 の想定された差し替え方法です。
- route、認証 guard、MFA provider、流量制限値には変更がありません。
- `app/Filament/Auth` 配下なので `discoverPages()` の対象にならず、通常ページとして余分な route が生える問題も避けています。
- フロントエンド、DTO、JSON 応答、LLM 経路などの禁止事項とは無関係です。

### `tests/Feature/Filament/AdminLoginThrottleDisplayTest.php`

判定: 修正が必要

[Warning] `assertHasErrors()` の連想配列の値を、エラーメッセージの完全一致検査として使用しています。

```php
->assertHasErrors([
    'data.email' => adminLoginThrottleMessage(),
]);
```

Livewire v3 の標準的な契約では、連想配列の値はエラーメッセージではなく、`required` などの失敗したバリデーション規則を指定するものです。詳細設計自身も `TestsValidation::failedRules()` への依存としてこの点を認識しています。

したがって、提示された「全テスト green」という実測と、このコードの意味には整合しない部分があります。仮に現在の組み合わせで通っていても、「残り秒数入りの案内そのもの」を固定していると明確には判断できません。

両ケースとも、key の存在とメッセージを分けて検査してください。

```php
$component->call('authenticate')
    ->assertHasErrors(['data.email']);

expect($component->errors()->get('data.email'))
    ->toBe([adminLoginThrottleMessage()]);
```

MFA 側も同様です。

```php
$component->call('authenticate')
    ->assertHasErrors(['data.multiFactor.app.code']);

expect($component->errors()->get('data.multiFactor.app.code'))
    ->toBe([adminLoginThrottleMessage()]);
```

これにより、次のすべてを一つの明確な期待値で保証できます。

- 古い認証失敗・必須エラーが残らない
- 案内が1本だけになる
- `60` 秒を含む正しい翻訳文になる
- 想定した入力欄の key に載る

それ以外は良好です。通常経路、上限前、MFA 経路、状態保持、panel 配線、自動発見による余分な route の不在までカバーしています。MFA で以前の error bag が現在の vendor 挙動では途中で消える点も、案内が入力欄へ追加される保証の検査として意味があります。

### `tests/Feature/Security/ThrottleExemptionPremiseTest.php`

判定: 妥当

- 固定した vendor クラスではなく、panel が実際に使用するログインクラスを起点にしているため、免除前提と実行経路が一致しています。
- `ReflectionMethod` が継承元の vendor 実装を走査するため、`rateLimit(5)` の存在確認は引き続き成立します。
- `authenticate()` の宣言元を vendor に固定しているため、閾値・判定順序の複写や独自認証処理への置換を検出できます。
- negative control を維持しており、走査器が常に true を返す偽緑にもなっていません。

[Suggestion] 宣言元検査が固定するのは `authenticate()` だけです。将来、子クラスが `rateLimit()` や流量制限キー生成メソッドを上書きした場合、本文走査と宣言元検査だけでは免除前提が緑のままになる可能性があります。今回の差分にはそのような上書きがなく、振る舞いテストも上限到達を確認するため現時点の欠陥ではありませんが、deny-by-default の保証範囲を説明するときは「独自クラス側のあらゆる上限解除を検出する」とまでは主張しない方が正確です。

## 全体判定

実装本体、Filament/Livewire の両経路、セキュリティ境界、PHPStan 対応は妥当です。しかし、台帳保証 (2) の中心である残り秒数入りメッセージのテストが、Livewire のアサーション契約と整合していません。直接の error bag 完全一致検査へ直す必要があります。

**CHANGES_REQUESTED**