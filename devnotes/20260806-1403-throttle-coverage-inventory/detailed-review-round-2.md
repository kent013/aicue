## Round 2 判定

| 施策 | 判定 |
|---|---|
| 1 | REQUEST_CHANGES |
| 2 | APPROVE |
| 3 | APPROVE |
| 4 | APPROVE |
| 5 | APPROVE |
| 6 | APPROVE |
| 7 | REQUEST_CHANGES |
| 8 | APPROVE |
| 9 | REQUEST_CHANGES |
| 10 | APPROVE |

### [Warning] 施策1: 期待値検証が実装骨子に反映されていない

本文では「`$limiter` が named/inline のどちらにも一致しなければ例外」とありますが、提示された `attachByName()` は、既存 throttle がない場合に検証せずそのまま追加します。

```php
if ($entries === []) {
    $route->middleware('throttle:'.$limiter);
}
```

これでは初回呼び出しで `6,1,9` などを登録できます。

修正案: route 解決前または middleware 追加前に、期待値を必ず検証する専用メソッドを呼んでください。

```php
self::assertValidLimiter($limiter);
```

また、既存 entry が不正形式の場合のテストとして、params なしだけでなく `6,1,9` と `Foo Bar` も追加してください。

### [Warning] 施策7: `T_NAME_QUALIFIED` の無条件受理は別クラスを誤合格させる

名前空間内の次の記述は、グローバルな Laravel Facade を意味しません。

```php
namespace App\Example;

Illuminate\Support\Facades\RateLimiter::for(...);
```

これは原則として `App\Example\Illuminate\Support\Facades\RateLimiter` に解決されます。したがって、`T_NAME_QUALIFIED('Illuminate\Support\Facades\RateLimiter')` を無条件で正規 Facade として受理すると deny-by-default が偽グリーンになります。

修正案:

- `T_NAME_FULLY_QUALIFIED('\Illuminate\Support\Facades\RateLimiter')` は受理
- 正規の `use Illuminate\Support\Facades\RateLimiter;` を伴う `RateLimiter` は受理
- `T_NAME_QUALIFIED` は、ファイルがグローバル名前空間の場合だけ受理するか、単純に `unresolved` とする

後者が実装も規約も単純です。単体テストも「名前空間内の非完全修飾 qualified name は unresolved」に変更してください。

### [Warning] 施策9: 異常入力の「同一 IP bucket」要件が実装と矛盾する

`login` では、極端に長い文字列も有効な `string` なので `EmailHash` が計算されます。したがって、配列・空文字の `anon` bucket と同じ bucketにはなりません。

認証フォームの2段 limiterも、異常な文字列ではIPレーンは共有しますが、IP-emailレーンは別になります。

修正案:

- 配列・空文字: `anon` fallbackとして同じ bucketを消費する
- 極端に長い文字列: 500にならず、同一値の反復で同じ bucketを消費する
- auth form: 異なる異常文字列でもIPレーンが共有される

この3契約に分けて記述してください。

## 全体判定

**CHANGES_REQUESTED**

Round 1のWarningは実質的に解消されています。残件は局所的ですが、施策1と7はいずれもdeny-by-default検査の偽グリーンに関わるため、実装開始前の修正が必要です。