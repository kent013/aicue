**全体判定: CHANGES_REQUESTED**

大枠の方向性、特に「binding 直後にテナント境界 404 を置く」という整理は妥当です。Laravel の priority list を使う方針も正しいです。ただし、S3-b と S5 は設計どおりに動かない箇所があり、このまま実装に進むとテスト不能または不変条件未達になります。

**施策別判定**

| 施策 | 判定 |
|---|---|
| S1 API ability / テナント guard 順序反転 | APPROVE |
| S2 テナント guard priority pin | APPROVE |
| S3 メンバー route の `{user}` 解決 | REQUEST_CHANGES |
| S4 順序不変条件 Architecture テスト | REQUEST_CHANGES |
| S5 trustProxies env allowlist | REQUEST_CHANGES |
| S6 RedirectToHttps を TrustProxies 後へ | APPROVE |
| S7 passkey 増減監査記録 | REQUEST_CHANGES |
| S8 passkey.destroy throttle | REQUEST_CHANGES |

**主要指摘**

[Critical] **S3-b: `projects.members.destroy` の手動解決は controller では遅いです。**  
未契約組織・メール未確認・2FA 強制・recent-auth stale などの middleware は controller 到達前に短絡します。したがって設計書の「未契約組織で非メンバー user / 不在 user が同一 404」は成立せず、実際は subscription gate 等の 302/402 が先に返る可能性が高いです。  
修正案: `{user}` を current organization relation から解決する専用 middleware を作り、`SubstituteBindings` と各種短絡 middleware の間に priority pin してください。controller 内手動解決は二重防御として残す位置づけにするのが安全です。

[Critical] **S5: `TRUSTED_PROXIES=none` が validator で不正扱いになる設計です。**  
「raw の非空 token が `proxies` に残っていないなら reject」という検査により、`none` は config 段で落ちた値として reject されます。  
修正案: silent-drop 検査では `none` を明示的に許容対象にし、`none` 単独の場合のみ `proxies === []` を正常扱いしてください。

[Critical] **S5: CIDR validation が緩すぎます。**  
`/^[0-9a-fA-F:.]+\/\d{1,3}$/` では `999.999.999.999/999` のような値を通し得ます。ProductionEnvGuard の fail-fast 目的と矛盾します。  
修正案: slash で分割し、IP 部分を `FILTER_VALIDATE_IP`、prefix を IPv4 は `0..32`、IPv6 は `0..128` で検証してください。config 段と validator 段で同じ判定関数を使うのがよいです。

[Warning] **S4: middleware 分類 inventory は「解決済み文字列の正規化」を仕様化してください。**  
`Router::gatherRouteMiddleware()` は `Class:param` 形式を返すことがあります。また `HandleInertiaRequests` は `Inertia\Middleware::class` ではなくアプリの具象 class として現れるはずです。  
修正案: `explode(':', $middleware, 2)[0]` 相当の正規化、alias 解決後 class 名、具象 class 名で分類する、と明記してください。

[Warning] **S4: pre-binding 短絡の静的検査は named rate limiter closure まで見ないと偽陰性が残ります。**  
`ThrottleRequests` 自体が route param を読まなくても、`throttle:api-read` / `throttle:passkeys` の limiter closure が route / segment / attribute を読む可能性があります。  
修正案: middleware class だけでなく RateLimiter 定義も inventory 化するか、最低限 Feature テストで「実在 id / 不在 id の完全同一性」を各 limiter ごとに固定してください。

[Warning] **S1 の “ヘッダ差分なし” テストはそのままだと不安定です。**  
`X-RateLimit-Remaining`、`Date`、`Set-Cookie`、request id 系ヘッダは連続リクエストで差分が出ます。  
修正案: 存在オラクルに関係するヘッダだけを比較する normalize helper を作り、volatile header は除外してください。

[Warning] **S7: vendor event の property 名が未確定のままです。**  
詳細設計としては `$event->user` / `$event->passkey` が本当に public かを前提にしていますが、「実装時確認」止まりです。  
修正案: vendor event の実体に合わせた property / accessor を設計書に確定し、Feature テストに加えて PHPStan が通る型注釈を明記してください。TypeScript 側の union 型有無も「要確認」ではなく調査対象ファイルを列挙してください。

[Warning] **S8: “ThrottleRequests が Authenticate より後なので user 単位” は保証になっていません。**  
実際の key は `throttle:passkeys` の named limiter 定義次第です。  
修正案: limiter が `user()->id` ベースであることをテストし、別ユーザー同士で bucket が共有されない Feature テストを追加してください。あわせて limiter closure が route param を読まないことを S4 inventory に含めるべきです。

**確認結果**

- S2 の priority pin 方針自体は妥当です。`ResolveApiActor → SubstituteBindings → api.project-in-org → project.in-route-org` の鎖は、解決済み middleware 列で固定すれば検証可能です。
- S3-a の `scopeBindings()` + `Organization::users()` は、Laravel の `BelongsToMany` 子 binding が related table の column を修飾するため、pivot の `id` 曖昧参照リスクは低いです。Feature テストで固定すれば十分です。
- S5 の `config('trustedproxy.proxies')` fallback は、`at:` を渡さず static `TrustProxies::at()` も使わない前提なら Laravel 12 の提供抜粋上は成立します。問題は fallback ではなく config/validator の値検証です。
- Frontend 変更は実質なしのため、DESIGN.md / Atomic Design は該当なしです。DTO/JsonResource パターンも本件の本線では破っていません。