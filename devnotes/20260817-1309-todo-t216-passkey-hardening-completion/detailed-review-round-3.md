## 施策 A: APPROVE

追加の Critical / Warning はありません。Fortify 1.37 系の宣言・解決値の固定と文書更新は妥当です。

## 施策 B: APPROVE

Round 2 の指摘は解消されています。

宣言経路を実際に再評価するテストに変更したことで、正規化器の呼び出しだけでなく、その戻り値が `allowed_origins` と `raw_allowed_origins` に採用されるところまで検証できます。`finally` で `$_SERVER`、`$_ENV`、`putenv()` の三者を「未設定を含めて」復元する方針も、並列実行との整合が取れています。

正規化と妥当性検証の責務分離も明確です。DNSラベル規則を検証器だけに置き、正規化後の不正ホストが必ず拒否される結合テストを追加する構成で問題ありません。

[Suggestion] コメントの「そのまま返す」は、厳密には先に `trim()` と `strtolower()` が適用された値を返します。「構造的な変形はせず、前後空白除去・小文字化後の値を返す」とすると実装との対応がさらに明確です。判定を止める問題ではありません。

## 施策 C: REQUEST_CHANGES

[Critical] 完全一致の購読台帳がワイルドカード購読を捕捉できない可能性がある

`getRawListeners()` から `PasskeyDeleted::class` の直接購読を取り出す検査は、直接登録された購読の顔ぶれと `ShouldQueue` を固定できます。しかし、Laravel Dispatcher がワイルドカード購読を別の内部集合で管理している場合、例えば次のような購読はその2件の一覧に現れません。

```php
Event::listen('Laravel\Passkeys\Events\*', QueuedSecurityListener::class);
```

この購読も `PasskeyDeleted` に反応し得るため、「削除イベントの購読は同期で走る2つだけ」という保証が成立しません。

修正案:

- Dispatcherの対象バージョンで、`getRawListeners()` がワイルドカード購読を含むかを確認し、含まない場合はワイルドカード集合も契約検査の対象にする。
- 内部プロパティへの Reflection を避けるなら、Architectureテストで `Event::listen()` 等のワイルドカード登録を目録化し、`PasskeyDeleted` に一致するパターンを禁止する。
- テスト名は、ワイルドカードまで閉じるまでは「直接購読は同期の2つだけ」に限定する。

[Warning] 想定外のリスナー形状を安全に拒否できない

次の処理は、`toBeArray()` の後に要素数やキーの存在を確認せず `$listener[0]` を参照しています。

```php
expect($listener)->toBeArray();
/** @var array{0: string, 1: string} $listener */
expect($listener[0])->toBeString();
```

空配列、連想配列、要素が1件だけの配列が入ると、意図した契約違反ではなく未定義オフセットで失敗します。また、docblockだけで `array{0:string,1:string}` と断定するのはPHPStan level 10上も根拠が弱い形です。

修正案:

```php
expect($listener)->toBeArray()
    ->and(array_is_list($listener))->toBeTrue()
    ->and(count($listener))->toBe(2);

expect(array_key_exists(0, $listener))->toBeTrue()
    ->and(array_key_exists(1, $listener))->toBeTrue();

$class = $listener[0];
$method = $listener[1];

expect($class)->toBeString()
    ->and($method)->toBeString();
```

必要なら `is_array()`、`array_key_exists()`、`is_string()` の通常分岐で絞り込み、失敗時に明示的なテスト失敗を出すヘルパへ切り出すとPHPStanの推論も安定します。

同様に、`$raw[PasskeyDeleted::class]` も `toHaveKey()` だけに型の絞り込みを依存せず、取り出した値がリストであることを確認してください。

## 施策 D: APPROVE

追加の Critical / Warning はありません。逸脱点を検証時点に限定し、`config/fortify.php` を対象パスへ含めない判断も妥当です。

## 全体判定: CHANGES_REQUESTED

施策Bの問題は解消されています。残るのは施策Cの購読台帳です。直接購読だけでなく、`PasskeyDeleted` に反応し得るワイルドカード購読まで閉じたうえで、想定外のリスナー形状を安全に拒否できれば、全体を承認できる状態です。