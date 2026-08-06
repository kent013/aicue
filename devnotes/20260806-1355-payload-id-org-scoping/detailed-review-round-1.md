全体判定: **CHANGES_REQUESTED**

設計方針は概ね妥当です。特に「404 化」ではなく「payload 由来 id の観測分岐を消す」へ目的を置き直している点、`exists:users,id` と直 fetch をセットで撤去する点、層 2 → 層 3 → payload 検証の順序を Feature テストで固定する点は承認できます。

ただし、MCP binder の入力分類と新規テストヘルパの PHPStan 適合に修正が必要です。

**施策 A: APPROVE**

[Suggestion] `ValidationException` は web/Inertia 経路では実 HTTP status 422 ではなく、通常は redirect back + session errors になります。設計文中の「422」は「422 相当の field error」または「validation failure」と表記を統一すると、実装者が `assertStatus(422)` を書く事故を避けられます。

修正必須ではありません。`Gate::authorize()` を payload 検証より前に置く方針、`exists:users,id` の撤去、組織 relation 解決、Service のロック下再検証を残す判断はいずれも妥当です。

**施策 B: APPROVE**

[Suggestion] ここも「403 → 422」と書かれている箇所は、web 経路では「403 → redirect back + `errors.user_id`」の意味だと明記した方が安全です。テストは `assertSessionHasErrors()` を主にすべきです。

`organizationRole($organization) === null` を残す判断は重要で、pivot 在籍と Laratrust role を混同していない点は良いです。層 2 → 層 3 → payload 検証の順序も妥当です。

**施策 C: REQUEST_CHANGES**

[Warning] `filter_var($raw, FILTER_VALIDATE_INT, ...)` だけでは、設計で「bool は 422」としている契約を満たさない可能性が高いです。特に `true` が `1` として受理されると、`organization_id=true` が organization id `1` として membership 判定へ流れます。存在オラクルには直結しなくても、入力分類契約とテスト計画が実装とズレます。

修正案:

```php
if (! is_string($raw) && ! is_int($raw)) {
    throw new HttpException(422, 'Invalid organization_id.');
}

$orgId = filter_var($raw, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
```

必要なら `is_bool($raw)` を先に明示 reject してもよいですが、配列・bool・float をまとめて拒否するなら上記で十分です。`'+1'` を受理する仕様をコメントに残すなら、テストにも入れるか、コメントから外してください。

**施策 D: APPROVE**

債務 3 件を A/B/C 実装後に inventory から削除し、cap を 0 にする順序は正しいです。分類 case と factory method を残す判断も、再発時の裁定語彙として妥当です。

**施策 E: REQUEST_CHANGES**

[Warning] `pieoObserve()` の設計は PHPStan level 10 で危険です。`session('errors')` は静的解析上 mixed になりやすく、そのまま `$errors->getBag()` を呼ぶと PHPStan エラーになり得ます。また `array` 戻り値に iterable value type が無い点も指摘対象になり得ます。

修正案:

```php
use Illuminate\Support\ViewErrorBag;

/**
 * @return array{signature: mixed, user_id_errors: list<string>}
 */
function pieoObserve(TestResponse $response): array
{
    $errors = session('errors');

    return [
        'signature' => ResponseSignature::of($response),
        'user_id_errors' => $errors instanceof ViewErrorBag
            ? $errors->getBag('default')->get('user_id')
            : [],
    ];
}
```

`ResponseSignature::of()` の正確な戻り型が分かるなら、`signature` も `mixed` ではなくその shape にしてください。

[Warning] ValidationException の応答一致テストでは、`ResponseSignature` だけでは session error の文言差を見られない、という理解は正しいです。一方で response status は実際には 302 になり得るため、新規・更新テストの期待値は `assertRedirect()` + `assertSessionHasErrors()` を基本にしてください。設計文の「422」の語が実装者に `assertStatus(422)` を誘導しないよう修正が必要です。

修正案: テスト計画の該当箇所を「422」ではなく「validation failure: redirect back + `errors.user_id`」に置き換える。

[Suggestion] `PIEO_MISSING_USER_ID` のコメントに「18 桁 pattern 内」とありますが、値は `999999999` で 9 桁です。コメントを直すか、意図した桁数に合わせてください。

**施策 F: APPROVE**

コメント同期のみで、Svelte の UI 実装・DS token・Atomic Design への波及はありません。`exists:users,id` の記述を消すのも妥当です。

**見落としやすい追加確認**

MCP binder のテストには、設計に書いた `true` / `[]` / `'001'` / 前後空白のケースを必ず入れてください。現状設計のままだと `true` のケースが赤くなる可能性があります。

また、A/B の存在オラクルテストでは `from()` を両リクエストで固定してください。`Location` ヘッダが `ResponseSignature` の比較対象に残るため、redirect 先の揺れをなくす必要があります。