以下、提示された詳細設計に対するレビュー結果です（実装コード変更は未実施、提示テキストのみ分析）。

**S1 条件付き middleware 新設**: **APPROVE**
- [Suggestion] `changesEmail()` 内の `! $user instanceof User` 分岐は実運用で到達不能に近く、コメントどおり fail-safe。設計意図として妥当。
- [Suggestion] `Webmozart\Assert\Assert::isInstanceOf($response, Response::class)` は PHPStan 補助として有効だが、`RequireRecentAuth` 側が `LogicException` を投げる流儀に揃える選択肢もある（統一性向上）。

**S2 middleware alias 登録**: **APPROVE**
- [Suggestion] alias 命名 `recent-auth.on-email-change` は可読性が高く、`RecentAuthRouteTest` の `str_starts_with('recent-auth')` と整合している。

**S3 Fortify route への後付け配線**: **APPROVE**
- [Warning] `attachRecentAuthToSensitiveRoutes()` が肥大化しやすい。  
  修正案: `appendMiddlewareIfMissing(RouteCollection $routes, string $routeName, string $alias): void` の小ヘルパを切り出し、無条件群/条件付き群で再利用。
- [Suggestion] `CONDITIONAL_RECENT_AUTH_ROUTES` の docblock は良い。将来の機微ルート追加時の誤配線を防ぐため、理由コメント（なぜ条件付きか）を配列要素近傍にも1行残すと保守性が上がる。

**S4 Architecture allowlist 追加**: **APPROVE**
- [Suggestion] テストの意図は十分。`user-profile-information.update` を追加することで「配線漏れ検出」が機能するため、今回の不変条件に合致。

**S5 client precheck 追加**: **APPROVE**
- [Warning] `initialUser.email` 基準の比較は「同一画面滞在中にサーバ側メールが別経路で変わった」場合に UI 判定がズレうる。安全性はサーバ最終ゲートで担保されるが UX 差分は発生しうる。  
  修正案: 受理可能なら、成功時に `initialUser` 相当 state を更新（または page props 再同期）し、連続操作時のズレを抑制。
- [Suggestion] `putProfile()` 抽出はテスト容易性・再利用性の観点で良い分割。

**S6 Feature テスト新設**: **REQUEST_CHANGES**
- [Critical] case 5 の設計が「Validator 422」を期待していますが、`submitted email` が **string かつ変更あり** の入力を混ぜると middleware 側で先に recent-auth に入り 409/302 となり得ます。現設計文では dataset の境界が曖昧。  
  修正案: case 5 は厳密に「`email` 欠落」と「`email` 非 string（配列）」のみに限定し、`string` パターンを含めないことをテスト名/データセット名で明示。
- [Warning] case 2 の 302/303 揺れに言及済みだが、最終 assertion を曖昧にしない方が良い。  
  修正案: `assertRedirect(...)`（遷移先検証）を主にし、必要なら status は `assertStatus(302|303)` ではなく Fortify Response 実装に合わせて固定。
- [Suggestion] 1a の `Notification::assertNothingSent()` は良い。加えて `email_verified_at` 不変も明示すると回帰耐性が上がる。

**S7 client 再開テスト + listener テスト**: **APPROVE**
- [Suggestion] listener テストは「viaRemember=true で stamp しない」に加え、「viaRemember=false で stamp する」対照ケースを1本置くと契約がより明確。
- [Suggestion] JS テストで `put` 呼び出し回数（stale時は確認後に1回）を検証すると二重送信回帰を捕捉しやすい。

**横断レビュー（観点 1〜11）**
- [APPROVE] ロジック整合: サーバ側 raw `!==` を action と合わせる方針は非常に良い（ドリフト耐性あり）。
- [APPROVE] DTO/JsonResource: 独自 `response()->json()` を避け、既存 `RecentAuthRequiredResource` 委譲は規約準拠。
- [APPROVE] Inertia vs API: mutation 409 を recent-auth code で扱う既存設計と整合。
- [APPROVE] セキュリティ: bug-hunt F-4-01 の攻撃線（stale→email差替え）を直接遮断できる。
- [Warning] PII/CipherSweet 観点は action 側で `whereBlind()` 準拠済みだが、今回追加テスト群でも「重複 email 不可」既存回帰に依存しているため、関連テストの実行セットに明示的に含める運用が望ましい。  
  修正案: CI 実行単位で `EmailChangeTest` を今回タスクの必須回帰として明記。

**全体判定**: **CHANGES_REQUESTED**
- 主理由は **S6 のケース境界を明確化しないと期待値衝突の余地が残る点（Critical）**。  
- それを解消すれば、設計全体は高品質で、セキュリティ要件・既存規約・テスト戦略に概ね適合しています。