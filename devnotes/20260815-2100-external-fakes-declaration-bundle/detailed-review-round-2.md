全体判定: **CHANGES_REQUESTED**

Round 1 の指摘への対応は概ね妥当です。特に施策 4 の P-3 / P-4 に対する反論は成立しています。ただし、宣言から entry を削除した退行を検出できない点と、子プロセスの環境隔離に未解決の問題があります。

## 施策 1: REQUEST_CHANGES

[Critical] `swaps()` から entry を削除する変異を、修正後の検査では検出できません。

`swaps()` が provider とテストデータセット双方の正本になるため、entry を削除すると以下も同時に縮みます。

- provider の bind 対象
- 3-1〜3-3 のデータセット
- P-1 / P-3 の観測対象
- 3-13 の重複・部分集合検査

3-14、3-15、P-5 も「1件減った」ことは検出しません。そのため、設計に記載された「宣言から 1 entry を消すと実装後は赤くなる」は、示された検査構造からは成立しません。これは旧 3-8 の削除によって失われる検出力です。

修正案: `ExternalSeamInventory` 側で「bug-hunt で fake が必須となる container seam」の abstract 集合を独立に分類し、次を集合一致で検査してください。

```text
ExternalSeamInventory の mustFakeInBughunt
    === ExternalFakeDeclaration::swaps() の対象 abstract
```

fake クラス、real クラス、flag、allowlist まで複製する必要はありません。安全上必要な到達点の集合だけを独立した要求として持てば、宣言の SSOT を崩さず削除変異を検出できます。

[Warning] 「DTO を返す（配列返却の新設なし）」という PHPStan 適合チェックは設計内容と一致しません。

`bughuntRequiredFlags()`、`bughuntRequiredEnvFlags()`、`neverSwapped()` は配列を返します。API DTO 規約の対象外であること自体は問題ありませんが、チェック項目の記述が不正確です。

修正案: 「外部応答ではなく内部宣言データなので DTO/JsonResource の対象外。swap entry は `ExternalFakeBinding` を使用する」と書き換えてください。

## 施策 1c: REQUEST_CHANGES

[Warning] 記載された `LaneExternalFakeBindingTest` は、説明上の保証範囲と一致しません。

提示された `bindPairs()` は `$this->app->bind(...)` を抽出する実装であり、`app()->bind(...)` を抽出するコードは示されていません。一方、施策 1c は両方を読めるとしています。`app()->bind(Foo::class, FakeFoo::class)` が素通りする可能性があります。

修正案: 次のいずれかを設計に追加してください。

- `bindPairs()` を `$this->app` と `app()/resolve()` の bind 呼び出しに対応させる
- lane 専用の抽出メソッドを追加し、両形式の正例・負例を固定する

少なくとも `$this->app->bind`、`app()->bind`、alias された `app()` の3ケースを自己検査に入れる必要があります。

## 施策 2: REQUEST_CHANGES

[Warning] S-9 はテストファイルの実在しか確認せず、seeder と振る舞いテストの対応を保証しません。

例えば `BughuntBillingSeeder` の premise test を、無関係だが実在する Feature テストへ差し替えても緑になります。「前提テストが消えたら気づく」は満たしますが、「論理を固定するテストが紐づいている」までは満たしません。

修正案: S-9 に最低限、次を追加してください。

- パスが Feature テストの許可された走査根に属する
- テストソースが対象 seeder クラスを参照する
- 無関係な既存テストへパスを変更する負のコントロール

[Warning] 型設計の記述が揃っていません。

`entries()` の戻り値 shape は `role` と `reason` だけですが、前提テストの紐づけを目録に持たせると説明されています。別メソッドの独立 mapping なのか、entry のフィールドなのかが曖昧です。

修正案: どちらかに固定してください。entry に含めるなら、例えば以下です。

```php
array{
    role: BughuntSeedRole,
    reason: string,
    guardPremiseTest: non-empty-string|null
}
```

別 mapping にするなら、キー集合が「ガードを要求する seeder 集合」と完全一致する検査が必要です。

## 施策 3: APPROVE

Round 1 の型矛盾は解消されています。`mixed` のまま保持し、判定関数で非文字列を危険側に倒す方針も PHPStan level 10 と fail-secure の双方に整合します。

未設定・空文字・`false` の分離と復元ヘルパの導入も妥当です。

## 施策 4: REQUEST_CHANGES

[Critical] 子プロセスへ渡す環境変数について、設計内の説明が矛盾しています。

Runner の説明は「明示した分だけを上書きする」としており、これは通常、親プロセスの他の環境変数を継承します。一方で後段は「渡すのは APP_ENV / 3フラグ / APP_KEY / CIPHERSWEET_KEY に限る」としています。

さらに、親環境を隔離しても Laravel bootstrap が `.env` または `.env.bughunt.local` を読み込めば、外部資格情報は子プロセスの設定へ入ります。現在の設計だけでは「実資格情報を渡さない」は保証できません。

修正案:

- 子プロセスを allowlist 環境で起動するのか、親環境を継承するのかを明示する
- 外部資格情報をダミー値または未設定へ固定する対象を列挙する
- `.env.bughunt.local` 読み込み後も実 Stripe / IdP / S3 資格情報が有効にならないことを保証する
- probe の環境に禁止した資格情報を注入する変異テストを追加する

[Critical] `$provider` が提示コード内で未定義です。

リスク欄には `config('template.social_providers')` の先頭から取るとありますが、probe のコードには代入がありません。このままでは実行時エラーになります。

修正案: 空配列・非文字列も fail-closed に扱う取得処理をコード設計に追加してください。

```php
$providers = config('template.social_providers');
Assert::isArray($providers);
$provider = array_key_first($providers);
Assert::stringNotEmpty($provider);
```

実際の config shape が list なら `array_key_first()` ではなく先頭値を使う必要があるため、現行構造に合わせてください。

[Warning] 設定キャッシュ存在時の期待動作が未定義です。

設計は「設定キャッシュの有無」を実測すると説明していますが、P-1〜P-4には cached / uncached の区分がありません。既存の `bootstrap/cache/config.php` がある場合、プロセス環境へ渡した3フラグが `config('testing.*')` に反映されない可能性があります。

修正案: 次のどちらを検査するか明示してください。

- config cache が存在しない隔離条件で boot 配線を観測する
- cached / uncached の両ケースを明示的に作り、双方を観測する

テスト中に共有の `bootstrap/cache/config.php` を生成・削除する方式は並列実行と衝突するため避け、専用一時 bootstrap/cache パスなど既存の Laravel 作法に沿った隔離が必要です。

### P-3 への再判定

P-3 の反論は妥当です。無条件 bind される3 interfaceと自動解決可能な4具象クラスがあり、in-process の3-1も同じ厳密一致を固定しているなら、「not fake」へ弱める必要はありません。

したがって、P-3 自体について追加の変更要求はありません。

### P-4 への再判定

P-4 の二段表明も妥当です。

- 非ゼロ終了で production 起動拒否を固定
- 特定メッセージで期待する guard が拒否したことを固定
- 順序変更時は偽グリーンではなく赤になる

この目的のためだけに別の順序 gate を新設する必要はありません。P-4についても追加の変更要求はありません。

## 施策 5: APPROVE

文書と機械検査の責務分担が明確になりました。文言そのものを pin する専用検査を追加しない判断も、過剰な機構を避ける観点で妥当です。