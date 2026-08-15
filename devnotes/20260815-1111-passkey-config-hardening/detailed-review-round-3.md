## Round 3 レビュー

宣言先を `config/fortify.php` に変更した判断は正しいです。Fortify の `register()` で `configurePasskeys()` が実行され、その後の `AppServiceProvider::boot()` では `passkeys.*` は上書き済みです。検査専用の未知キーを `fortify.passkeys.*` に置くことも問題ありません。

ただし、テストが実際の設定伝播を証明できていない点など、まだ3点修正が必要です。

### 施策 1: `config/fortify.php` の passkeys 宣言

**判定: REQUEST_CHANGES**

[Warning] 「Fortify の上書き後も実効値が宣言値と一致する」テストは、現在の形だと設定伝播の破損を見逃します。

通常環境では両方が同じ `APP_URL` / `APP_KEY` から導出されます。そのため、Fortify が `fortify.passkeys.*` を読まなくなって fallback に戻っても、次は成立し得ます。

```php
config('passkeys.relying_party_id')
    === config('fortify.passkeys.relying_party_id')
```

最重要契約テストとしては偽陰性があります。

修正案は、通常の fallback と一致しない sentinel を設定した状態で `configurePasskeys()` の写像を実行し、実効値を確認することです。例えば対象メソッドが非公開なら Reflection を使った vendor contract test として明示的に扱えます。

```php
config([
    'fortify.passkeys.relying_party_id' => 'sentinel.example.com',
    'fortify.passkeys.allowed_origins' => ['https://sentinel.example.com'],
    'fortify.passkeys.user_handle_secret' => str_repeat('s', 32),
]);

// FortifyServiceProvider::configurePasskeys() を再実行

expect(config('passkeys.relying_party_id'))->toBe('sentinel.example.com');
```

Reflection を避けるなら、専用プロセスで env を変えてアプリを bootstrap する方法でも構いません。重要なのは fallback と異なる値で写像を検査することです。

[Warning] allowed origins のコメントと実装が一致していません。

設計は「trim だけ」「大文字 scheme を reject」としていますが、実装は origin 全体を `strtolower()` します。

```php
array_map(
    static fn (string $v): string => strtolower(trim($v)),
    ...
)
```

このため env の `HTTPS://app.example.com` は config 読み込み時に `https://...` へ変換され、production validator を通ります。一方、validator の単体テストでは大文字 scheme を直接渡して reject します。

修正案は、次のどちらかに統一することです。

- 正規化を仕様にする: 大文字 scheme/host を小文字化して受理し、reject テストを削除する
- 厳格拒否を仕様にする: config では `trim()` だけ行い、validator に原値を渡す

URI schemeとDNS hostは大文字小文字を区別しないため、正規化して受理する方が実用的です。ただし origin 全体ではなく、schemeとhostを構造的に正規化するのが正確です。

[Warning] リスク欄の `mergeConfigFrom` に関する説明が旧設計のままです。

今回の重要な実効キーは Fortify の `config([...])` がすべて供給します。「アプリ側ファイルに書いていないキーが上位キー単位マージで消える」という旧 `config/passkeys.php` 前提の説明は、そのままでは成立しません。

修正案:

- `mergeConfigFrom` による消失リスクの記述を削除する
- リスクを「Fortify の `configurePasskeys()` のキー写像・組立規則への依存」に置き換える
- テスト名の「vendor 既定キーが残る」も「Fortify 結線後の実効キーが残る」などへ変更する

なお、`management_middleware` と `throttle` は vendor既定値ではなく、Fortify がアプリ設定から組み立てた実効値です。

### 施策 2: 設定事故ガード

**判定: REQUEST_CHANGES**

[Warning] `ProductionEnvGuardTest` の baseline のキー位置が設計変更に追従していません。

本文では次の5キーとありますが、検査専用キーは新設計では `fortify.passkeys.*` です。

修正案として baseline を明記してください。

```php
config([
    'passkeys.relying_party_id' => 'app.example.com',
    'passkeys.allowed_origins' => ['https://app.example.com'],
    'passkeys.user_handle_secret' => str_repeat('a', 32),
    'fortify.passkeys.raw_allowed_origins' => ['https://app.example.com'],
    'fortify.passkeys.user_handle_secret_declared' => true,
]);
```

非string検査についても、実効列は `passkeys.allowed_origins`、raw列は `fortify.passkeys.raw_allowed_origins` の両方を個別に壊すテストがあると読み出し元の取り違えを防げます。

validator自体のロジック、`isStringList()`、PSLに関する保証範囲は承認できます。

### 施策 3: `.env.example`

**判定: APPROVE**

行頭一致への変更で、コメントアウトされた例示と必須宣言行を正しく区別できます。

### 施策 4: 版 pin

**判定: APPROVE**

完全一致の制約検査で、OR制約やstability flagによる抜け道は解消されています。

[Suggestion] 今回の設定写像は `laravel/fortify` の実装にも依存します。Fortifyは1.xとしてsemver管理されているため、追加のminor pinまでは不要です。ただしドキュメントの「版は直接要求と解決版検査で pin」という表現は、実際には `laravel/passkeys` だけを指すことを明確にしてください。Fortify側は実効契約テストで守る、という整理が正確です。

### 施策 5: 運用契約

**判定: APPROVE**

宣言場所とFortifyによる上書きの説明は、今回判明した実際のライフサイクルに整合しています。上記のとおり、版 pin と実効契約テストの担当範囲だけ表現を調整してください。

## 確認事項への回答

1. `config/fortify.php` への移動は正しいです。`register()` は provider の `boot()` より前に完了するため、`AppServiceProvider::boot()` からは上書き後の `passkeys.*` が見えます。

2. 検査専用キーの同居に問題はありません。Laravel config配列はスキーマ検証されず、Fortifyは参照する既知キーだけを読みます。未知キーは保持されたまま無視されます。

3. `config:cache` コマンドはアプリを bootstrap した後の設定全体を書き出すため、生成されたキャッシュには通常 `configurePasskeys()` 適用後の `passkeys.*` も含まれます。ただしキャッシュ起動時にもFortifyの `register()` が走り、`config([...])` が再適用されます。したがって本設計はキャッシュへの焼き込み有無に依存せず、最終的に `fortify.passkeys.*` から実効値が再構成されます。

## 全体判定

**CHANGES_REQUESTED**

残る修正は次の3点です。

- fallbackと異なる sentinel を使い、Fortifyの写像を本当に検証する
- allowed origin の小文字化と「大文字schemeを拒否」の仕様矛盾を解消する
- テストbaselineの検査専用キーを `fortify.passkeys.*` に修正する

宣言場所の全面変更そのものは正しく、設計の中核方針に追加変更は不要です。