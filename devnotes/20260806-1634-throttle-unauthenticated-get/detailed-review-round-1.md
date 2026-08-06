制約どおりコマンド実行・書き込みは行っていません。以下は提示された詳細設計テキストに対するレビューです。

**全体判定: CHANGES_REQUESTED**

deny-by-default gate の母集団拡張という方向性は妥当です。ただし、施策 6 / 8 / 9 に「機械で検出できない状態」や PHPStan/Pest 上の危険が残っています。このまま実装に入ると、exemption 台帳が正しそうに見えるだけで、前提崩れを検出できない箇所が出ます。

**施策 1: APPROVE**

[Sugerestion] `floor=60` は妥当ですが、詳細設計内で「実測 70」と「機能フラグ無効時の目減り」を根拠にするなら、fail 観測ログに実測母集団数も残す運用まで明記するとよいです。

**施策 2: APPROVE**

[Warning] `social-callback` / `invitation-accept` は IP レーンなので、無効リクエストでも正当ユーザーの枠を消費します。設計では巻き添えを認識できていますが、実装後のテスト名か docs に「無効 request も同じ bucket を消費する」ことを明記してください。

修正案: behavioral test に「missing intent / invalid token でも `X-RateLimit-Remaining` が減る」観点を含める。

**施策 3: APPROVE**

[Warning] `social.callback` に throttle を貼る判断は妥当ですが、`social.redirect` 無制限との組み合わせで、同一 IP から callback 枠を意図的に枯らす一時 DoS は残ります。これは許容リスクとして docs に残すべきです。

修正案: `docs/app-integration-guide.md` の監視項目に、429 発生率だけでなく「invalid callback 比率」も入れる。

**施策 4: APPROVE**

[Warning] Fortify の 2FA GET 3 本へ `throttle:10,1` を貼ると、同じ actor の inline bucket を既存の 2FA 操作と共有する可能性があります。設計は「初期表示で 2 消費」と書いていますが、続く enable/confirm まで含めた実導線の消費量を固定していません。

修正案: 2FA 設定画面相当の GET 2 本後に、既存の 2FA POST が即 429 にならないことを feature test で固定してください。

**施策 5: APPROVE**

新 case を `AuthViewRenderOnly` と `AuthFlowInitiationWithoutOutboundCall` に分ける判断は妥当です。

**施策 6: REQUEST_CHANGES**

[Critical] `filament.admin.auth.multi-factor-authentication.set-up-required` の exemption が、設計内で未確定のままです。「GET で秘密が生成・保存されるなら throttle」と書かれていますが、deny-by-default gate ではこの状態を残せません。

修正案: 実装前提確認ではなく、`ThrottleExemptionPremiseTest` に Filament MFA GET の behavioral proof を追加してください。難しい場合は、いったん exemption ではなく `throttle:10,1` 側に倒すべきです。

[Warning] 施策タイトルは「検査 2 本追加」ですが、実際の snippet は 3 本追加しています。設計書内の数が揺れるとレビュー・実装時に抜けます。

修正案: 「検査 3 本追加」に統一してください。

[Warning] case 別 cap のテストは、使用中 case の cap 未登録は検出しますが、説明文の「新 case を足したら上限も同時に決めさせる」と完全には一致しません。未使用 case は検出されません。

修正案: 意図が「使用時に必須」なら説明を修正。全 enum case に cap を要求するなら enum 全件を走査してください。

**施策 7: APPROVE**

`RateLimiterKeyConventionTest` への登録は必須で、設計の shape も既存方針に合っています。

**施策 8: REQUEST_CHANGES**

[Warning] 8-5 は「behavioral proof」と言いつつ、middleware entry の存在確認に寄っています。これだけでは実際に 429 が発生すること、また bucket 共有で既存 2FA 操作を壊さないことを示せません。

修正案: 少なくとも authenticated user で対象 GET の rate limit header 変化、または 11 回目 429 を 1 本は確認してください。加えて、初期表示相当の 2 リクエスト後に通常操作が通ることを固定してください。

[Warning] `Http::preventStrayRequests()` は Laravel HTTP client には効きますが、Socialite/Guzzle の外向き通信まで保証する証明にはなりません。

修正案: `social.callback` は intent 不在で controller が短絡することを直接 assert するか、Socialite 側を mock/spy して `user()` が呼ばれないことを確認してください。

**施策 9: REQUEST_CHANGES**

[Critical] 9-4 の snippet は PHPStan level 10 で `$callback` の null が絞り込まれません。`expect($callback)->not->toBeNull()` は PHPStan の型 narrowing にならないため、`RouteThrottleBinder::throttleEntries($router, $callback)` が落ちる可能性があります。

修正案:
```php
$callback = $routes->getByName('social.callback');
expect($callback)->not->toBeNull();
assert($callback instanceof \Illuminate\Routing\Route);

$entries = RouteThrottleBinder::throttleEntries($router, $callback);
```

[Warning] 9-2 が `social.redirect` にも「DB 書込 0 件」を要求する設計になっていますが、`social.redirect` は session state を生成する route です。session driver が DB の環境では、許容しているはずの自セッション副作用とテスト条件が衝突します。

修正案: DB 書込 0 件テストは `AuthViewRenderOnly` 代表に限定し、`AuthFlowInitiationWithoutOutboundCall` は「外向き HTTP なし」「callback throttle 実在」「session 内 state 生成のみ」を別条件で検証してください。

[Warning] `throttlePremiseIsWriteStatement()` の説明に `with x as (...) insert ...` が出ていますが、単純な先頭動詞判定では CTE 書込を検出できません。

修正案: CTE を unsupported と明記して false 期待にするか、保守的に `^with\b.*\b(insert|update|delete)\b` を write 扱いにしてください。deny-by-default なら過検出の方が安全です。

**施策 10: REQUEST_CHANGES**

[Warning] 詳細設計の後半に `exemption 25 / cap 26` と読める記述があり、前段の exact fit `cap=25` と矛盾しています。

修正案: cap は 25 exact fit に統一してください。余裕枠を作らない方針と一致します。

**まとめ**

設計の主方向、つまり S3 を method 非依存にして認証面 GET を gate 母集団へ入れる判断は承認できます。差し戻し理由は、exemption の前提が一部「実装時に確認」に残っていること、2FA throttle の実効動作テストが弱いこと、9-4 に PHPStan 上の null narrowing 問題があることです。これらを直せば、deny-by-default の設計としてかなり堅くなります。