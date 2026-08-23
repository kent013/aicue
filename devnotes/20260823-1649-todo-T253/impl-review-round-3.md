## 全体判定

`CHANGES_REQUESTED`

実装本体では、第2段の再ロックによってメール上書き競合は閉じています。JWKS lock の release を best-effort にする判断も妥当です。

残件は主に検査側です。

- メール昇格の割り込みテストが「commit 後」を再現していない
- 同テストの `flushEventListeners()` が後続テストを汚染する
- G2-4 はネストした名前付き引数を外側の `fetch()` の引数と誤認できる

加えて、メール更新と security audit が別 transaction になっている原子性の問題があります。

## ファイル別判定

### [EmailPromotionService.php](/workspace/.claude/worktrees/tasks/T253/app/Services/Auth/EmailPromotionService.php)

第2段の再ロックについては `APPROVE` です。

第1段の commit 後、第2段で利用者行を改めて `lockForUpdate()` し、保存済みの `email` を読み直してから書いています。したがって次の順序でも上書きしません。

```text
A: token を消費して commit
B: email=other@example.com を commit
A: 第2段でロック取得
A: email が non-null なので false
```

token を消費済みのままにする帰結も妥当です。確認 token の一回使用を優先し、利用者は必要なら改めて発行できます。

[Warning] メール更新と security audit が原子的ではありません。

現在は以下の順序です。

```php
$applied = DB::transaction(/* email を保存 */);

// transaction は既に commit 済み
$this->recorder->record(...);
```

具体的には、監査テーブルの障害、DB 接続障害、`SecurityEventRecorder` の例外が発生すると、

1. メールと `email_verified_at` は更新済み
2. 監査イベントは存在しない
3. HTTP 応答は500
4. token は消費済み

という状態になります。

設計が「変更を既存監査基盤へ記録する」と要求するなら、メール保存と監査記録は同じ第2段 transaction に入れるべきです。blind index の競合時は transaction 全体を savepoint まで戻せるため、現在の二段構成とも両立します。

監査に副作用がDB以外にもある場合は、少なくとも「DB上の監査行とメール変更」の原子性をどこが担うか明示してください。

---

### [EmailPromotionTest.php](/workspace/.claude/worktrees/tasks/T253/tests/Feature/Auth/EmailPromotionTest.php)

validation の空振り修正は `APPROVE` です。

上限から生成した長さ、配列、欠落を使っており、さらに rule 自体の境界を直接検査しています。Round 1 の Critical に対する回帰として成立しました。

[Warning] 「第1段の commit 後・第2段の前」という割り込みを再現していません。

`EmailPromotion::deleted()` の listener は、`$promotion->delete()` の最中、つまり第1段 transaction の中で同期実行されます。コメントにある「第1段の commit の後」ではありません。

実際の順序は次です。

```text
第1段 transaction 開始
users 行をロック
promotion を delete
deleted listener 発火
  同じPHPプロセス・同じDB接続で users.email を更新
第1段 transaction commit
第2段開始
```

同じ接続なので自分が保持している user row lock へ再入でき、テストは通ります。しかし、これは Round 2 で問題にした「commit 後に別 transaction が割り込む窓」とは異なります。

実装の再ロック自体は正しいものの、テストの主張が実測内容を超えています。次のいずれかが必要です。

- 第1段の transaction が戻った直後に呼べる、同期的なテスト seam を service に置く
- 消費と適用を内部メソッド／DTO outcome に分離し、テストから第1段を完了してから別更新を commit、その後に第2段を呼ぶ
- 二接続ハーネスで、第1段 commit 後の更新を明示的に作る

[Warning] `EmailPromotion::flushEventListeners()` が後続テストを汚染します。

これは今回登録した closure だけでなく、`EmailPromotion` に登録された全 listener を静的に削除します。同じPHPプロセス内の後続テストでは listener が戻らないため、実行順によって挙動が変わります。

```php
finally {
    EmailPromotion::flushEventListeners();
}
```

は使用しないでください。特にモデル trait や observer が event に依存している場合、後続テストが本番と違う状態になります。

一時 listener の全削除を避けられないことも、テスト専用の明示 seam を選ぶ理由になります。

[Warning] 監査失敗時の原子性テストがありません。

`SecurityEventRecorder` が失敗した場合に、メール更新も rollback されることを固定してください。期待結果は少なくとも次です。

- メールは null のまま
- `email_verified_at` も昇格時刻へ変わらない
- 監査行はない
- token の扱いは設計どおり消費済み

token は第1段で既に消費済みなので、その点は戻さなくて構いません。

---

### [EnterpriseSsoSourceScanner.php](/workspace/.claude/worktrees/tasks/T253/tests/Support/EnterpriseSso/EnterpriseSsoSourceScanner.php)

[Warning] 外側の `fetch()` に属さない、ネストした名前付き引数を誤認します。

`callsWithoutNamedLiteral()` は、外側の `fetch(` から対応する `)` までを走査しますが、括弧の深さを限定せず、最初に見つけた `followRedirects:` を採用しています。

したがって次が green になります。

```php
$this->pinned->fetch(
    $this->buildRequest(followRedirects: false),
    $deadline,
);
```

外側の `fetch()` には `followRedirects` がなく、既定の `true` が使われます。しかし scanner は内側の `buildRequest()` の名前付き引数を見つけ、

```text
followRedirects : false )
```

を literal `false` と判定します。

外側の引数リストの深さ1にある名前付き引数だけを対象にしてください。配列、closure、関数呼び出し、attribute 等の内側にある同名 token は無視する必要があります。

これは AGENTS.md の走査器共通規約における、実際の検出範囲を変える修正です。したがって同じ変更で負例・正例・docblock 更新が必要です。

---

### [EnterpriseSsoOutboundHttpGateTest.php](/workspace/.claude/worktrees/tasks/T253/tests/Architecture/EnterpriseSsoOutboundHttpGateTest.php)

現在の app 実装に対する判定自体は正しく、`followRedirects: false` を使用しています。

ただし上記 scanner のネスト誤認により、将来の回帰 gate としてはまだ不十分です。scanner 修正後はこの gate をそのまま利用できます。

---

### [RedirectFollowingSample.php.txt](/workspace/.claude/worktrees/tasks/T253/tests/Architecture/fixtures/enterprise-sso/RedirectFollowingSample.php.txt)  
### [EnterpriseSsoSourceScannerTest.php](/workspace/.claude/worktrees/tasks/T253/tests/Unit/Architecture/EnterpriseSsoSourceScannerTest.php)

[Warning] Round 2 で求めた3方向は固定されていますが、ネスト誤認の負例がありません。

次のような fixture を追加してください。

```php
$this->pinned->fetch(
    $this->makeRequest(followRedirects: false),
    $deadline,
);
```

これは1件の違反になる必要があります。

併せて、外側が正しく `false` で、内側にも同名引数がある形を正例にすると、depth 判定の両方向を固定できます。

---

### [OidcDiscoveryService.php](/workspace/.claude/worktrees/tasks/T253/app/Services/EnterpriseSso/OidcDiscoveryService.php)

`APPROVE`

`release()` の best-effort 化は妥当です。

- `get()` 失敗：排他を取得できていないため fail-closed
- callback 失敗：取得結果自体がないため拒否
- `release()` 失敗：取得・検証済みの結果は有効であり、lock は lease で自然失効

この三者は意味が異なります。後片付けの失敗で正常な JWKS を捨てる必要はありません。

[Suggestion] 完全に無言で握り潰すと cache backend の障害兆候が見えません。秘密やURLを含めず、固定メッセージ／固定理由コードだけで warning を記録する余地はあります。ただし認証の正確性を壊す問題ではないため、承認阻害とはしません。

ロック寿命と時間予算の関係を設定テストで固定した点も妥当です。

---

### [EnterpriseSsoConfigTest.php](/workspace/.claude/worktrees/tasks/T253/tests/Feature/EnterpriseSso/EnterpriseSsoConfigTest.php)

`APPROVE`

```text
lock lease > connect timeout + request timeout
```

を固定しており、設定変更によって排他の前提が黙って崩れることを防いでいます。

---

### [OidcJsonWebKeySet.php](/workspace/.claude/worktrees/tasks/T253/app/DataTransferObjects/EnterpriseSso/OidcJsonWebKeySet.php)  
### [OidcDiscoveryServiceTest.php](/workspace/.claude/worktrees/tasks/T253/tests/Feature/EnterpriseSso/OidcDiscoveryServiceTest.php)

`APPROVE`

重複した `key_ops` を拒否側へ移した変更は、deny-by-default の方針と整合します。単独の `verify` と他用途との併記を正例に残しているため、過剰拒否の確認もあります。

## 再確認事項への回答

1. 第2段の再ロック  
   **実装は競合窓を閉じています。** token を消費済みのままにする判断も妥当です。ただし提示されたテストは commit 後の割り込みを再現していません。

2. G2-4 の値検査  
   直接値については、引数省略・`true`・変数・式を拒否できています。しかし、括弧の深さを見ないため、ネストした別呼び出しの `followRedirects: false` で回避できます。

3. `release()` の best-effort  
   **妥当です。** 取得時の障害と後片付けの障害を同じ失敗へ畳まない判断を支持します。固定理由だけの監視ログは検討余地があります。

4. 新たな欠陥  
   メール変更と監査記録が別 transaction である点、割り込みテストの時点誤認と全 listener 削除、scanner のネスト誤認が残っています。

`CHANGES_REQUESTED`