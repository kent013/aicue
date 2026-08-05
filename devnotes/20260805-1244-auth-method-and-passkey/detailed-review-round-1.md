**全体判定: CHANGES_REQUESTED**

方向性は概ね妥当です。特に「phantom password 是正」「passkey を 2FA 代替にしない」「ログイン手段保持を投影後状態で見る」という設計判断は North Star とセキュリティ不変条件に合っています。

ただし、passkey 周辺に boot 順序・middleware transaction・HTTP 応答契約・PHPStan 型の未解決リスクがあり、このまま実装に入るのは危険です。

**施策 1: SSO email trust policy seam**
判定: APPROVE

[Suggestion] `EmailTrustPolicyResolver` が毎回 `new` する設計は問題ありませんが、将来 provider 固有検証を入れるなら container 解決へ寄せてもよいです。現時点では YAGNI の範囲です。

[Suggestion] `email_trust` 未宣言を runtime では `unconfirmed`、CI では宣言漏れ fail にする二段構えは妥当です。Google の confirmed pin も回帰テストとして適切です。

**施策 2: ログイン手段 inventory と phantom password 是正**
判定: APPROVE

[Warning] `PasswordUpdated::class` は設計内でも確認事項になっていますが、ここは実装前に確定してください。Fortify 1.37 の実イベント名・payload が違う場合、listener ではなく `App\Actions\Fortify\UpdateUserPassword` から `SecurityEventRecorder` を直接呼ぶ設計に固定するのが安全です。

修正案: 詳細設計上も「イベントが存在すれば listener、なければ Action 直記録」ではなく、どちらを採用するかを確定させてから実装してください。

[Warning] `LoginMethodInventory` は「ログイン可能な手段」と `canSatisfy` を分離できており良いです。ただし将来 password/social 除去 route を追加する場合も、同じ User 行 lock 規約を必須にしてください。passkey だけ守って social unlink が別経路になると TOCTOU が戻ります。

**施策 3: EnsureLoginMethodRemains**
判定: REQUEST_CHANGES

[Critical] middleware 内 `DB::transaction()` で `$next($request)` を実行する設計は、対象 route を極小に固定しないと危険です。controller だけでなく、同期 event listener、Responsable 変換、redirect/session flash まで transaction 内に入ります。現設計は passkey.destroy だけなら成立し得ますが、将来この middleware が他 route に付くと副作用範囲が急に広がります。

修正案: Architecture test に「`ensure-login-method` を付けてよい route は `passkey.destroy` など allowlist のみ」を追加し、未知 route に middleware が付いたら fail させてください。加えて docblock に「streamed response / 外部 I/O / afterCommit でない queue dispatch を含む route には付けない」と明記してください。

[Warning] `recent-auth` → `ensure-login-method` の実行順が設計上重要です。順序が逆になると stale recent-auth のリクエストでも User row lock を取りに行きます。

修正案: `PasskeyRouteProtectionTest` で middleware の存在だけでなく、`passkey.destroy` の gathered middleware 上で `recent-auth` が `ensure-login-method` より前に来ることを pin してください。

[Warning] SQL listener で `FOR UPDATE` と delete 順序を確認する方針は妥当ですが、`RefreshDatabase` の外側 transaction により `transactionLevel()` は 1 以上から始まります。

修正案: 「middleware 内で level が増えていること」または「select/delete が同じ非 0 level で観測されること」を見る設計にしてください。単純に level === 1 を期待すると parallel/RefreshDatabase 下で壊れます。

**施策 4: passkey feature 有効化と vendor アダプタ**
判定: REQUEST_CHANGES

[Critical] `Route::bind('passkey', ...)` を `boot()` で即時実行するだけでは、vendor provider より確実に後勝ちになる保証が弱いです。`bootstrap/providers.php` の順序は app provider 間の順序であり、auto-discovered package provider との最終 boot 順序を設計根拠にするのは危険です。

修正案: binder 差し替えも `$this->app->booted(...)` の中で実行してください。route middleware 後付けと同じ「全 provider boot 後に最終上書き」の形に寄せるべきです。Feature test は「他人 passkey DELETE が 404」を必須にし、可能なら router の binding 挙動を直接叩く小テストも追加してください。

[Critical] Response contract とフロント transport の契約が曖昧です。`PasskeyRegistrationResponse` / `DeletedResponse` が常に `back()` を返す一方、`resources/js/lib/passkeys.ts` は fetch 型の薄い wrapper に見えます。fetch が redirect 後 HTML を受け取る設計になると、成功判定・Inertia props 更新・recent-auth 409 処理が崩れます。

修正案: passkey JS が Inertia router で POST/DELETE するのか、fetch で JSON/204 を扱うのかを先に固定してください。fetch なら `expectsJson()` 時は `204 no-store`、Inertia mutation なら `back()->with(...)` のように response を分岐させるべきです。

[Warning] `Features::passkeys(['confirmPassword' => false])` の副作用を `config()->all()` で見るだけでは config cache 下の保証になりません。

修正案: config cache を作った状態で `fortify-options.passkeys.confirmPassword === false` が残る smoke test、または CI の config-cache ジョブで検証してください。通常 runtime の architecture test だけでは不十分です。

[Warning] `User::passkeys()` の PHPStan 型が危ういです。trait 由来の relation が vendor base model 型として見える場合、`Collection<App\Models\Passkey>` 前提の closure や DTO 生成で level 10 が落ちます。

修正案: `User` 側で `passkeys(): HasMany` を明示 override し、`@return HasMany<\App\Models\Passkey, $this>` を付けて `return $this->hasMany(\App\Models\Passkey::class);` にしてください。

**施策 5: recent-auth 配線**
判定: REQUEST_CHANGES

[Warning] `PasskeyVerified` は login / confirm の両方で発火するため、listener で `RecentAuthState::confirm()` する設計自体は理解できます。ただし passkey login が policy deny される経路でも、verify 後に event が発火する可能性があります。最終的に `Login` が後勝ちする想定だけでは不足です。

修正案: TOTP enabled ユーザーの passkey login deny 時に、認証済み session へ fresh state が残らないことを Feature test で固定してください。

[Warning] satisfier inventory の静的走査は false negative があります。`->confirm(` + `RecentAuthState` 文字列一致では、alias import、container 解決、変数名経由、メソッド転送を取り逃がします。

修正案: 最低限 `token_get_all()` で namespace/use/class と method call を解決する走査にしてください。より良いのは既存方針どおり code-review-graph/AST ベースの inventory に寄せることです。

[Warning] `ClearRecentAuthOnPasskeyChange` は HTTP session 前提です。イベントが将来 CLI/queue/admin cleanup から発火すると、意図しない session 操作になります。

修正案: `request()->hasSession()` または session availability を確認してから clear してください。

**施策 6: フロント**
判定: REQUEST_CHANGES

[Critical] passkey 登録・削除の client/server 契約が未確定です。`passkeys.ts` は fetch wrapper、response は redirect/back、UI は `guardWithRecentAuth()` 前提で、3 つの責務が噛み合っていません。

修正案: `registerPasskey` / `deletePasskey` / `confirmWithPasskey` ごとに、送信手段、期待 status、409 recent-auth の扱い、422 login-method rejection の扱いを表で固定してください。その後に Svelte 実装と response contract を合わせるべきです。

[Warning] Inertia prop で `PasskeyListItemResource::collection($collection->map(dto))` を返す設計は成立し得ますが、PHPStan と Inertia resolve の両面で不安定です。

修正案: Controller に抽出し、DTO collection を `->resolve($request)` した plain array として Inertia に渡す方が安全です。Resource を使うなら `$wrap = null` と DTO property access の型をテストで固定してください。

[Warning] `Passkey::$authenticator` は vendor の accessor/appended 属性である可能性が高く、`$p->authenticator` の直接参照は PHPStan level 10 で落ちるリスクがあります。

修正案: `getAttribute('authenticator')` を `is_string() ? ... : null` で narrow するか、app model に typed accessor wrapper を追加してください。

**最重要修正順**

1. `Route::bind('passkey')` を `app->booted()` 後勝ちに変更。
2. passkey JS と Response contract の transport 契約を確定。
3. `ensure-login-method` の適用 route allowlist と middleware 順序 test を追加。
4. `User::passkeys()` の app model 型を PHPStan 向けに明示。
5. recent-auth satisfier inventory を文字列一致から token/AST 走査へ強化。

この 5 点が直れば、設計全体は実装フェーズに進められる水準です。