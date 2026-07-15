**レビュー方針（仮説）**
- 仮説: T1/T2/T3 は「`capture.manuals.show` のみ `(self)` 緩和」「その他は baseline 維持」「route 未解決は fail-secure（非送出）」を満たせば成功。
- 成功条件: 実装差分と追加 Feature テストがこの挙動を固定し、型安全（PHPStan level 10）と least-privilege を崩していないこと。

**`app/Http/Middleware/SecurityHeaders.php`**
- **判定**: 問題なし
- **Critical**: なし
- **Warning**: なし
- **Suggestion**:
  - `resolvePermissionsPolicy()` の `array_filter(..., is_string(...))` で `list<string>` へ narrow している点は PHPStan 観点で適切。`config($key)` を `is_string` 判定後 `?string` 返却も安全。
  - `routeIs(...$captureRoutes)` を `[]` ガード付きで使っており、allowlist 空時の誤緩和を防止できている（fail-secure）。
  - 404(binding失敗)時に middleware 非到達でヘッダ非送出、という設計意図と整合。

**`config/security.php`**
- **判定**: 問題なし
- **Critical**: なし
- **Warning**: なし
- **Suggestion**:
  - T1 の追加は設計通り。`capture_permissions_policy_routes` が route 名 allowlist で least-privilege を担保。
  - `capture_permissions_policy` が `(self)` のみで cross-origin 開放していない点はセキュア。
  - opt-out（`null`/空文字）方針の説明も middleware 実装と一致。

**`tests/Feature/Security/SecurityHeadersTest.php`**
- **判定**: 問題なし
- **Critical**: なし
- **Warning**: なし
- **Suggestion**:
  - T3 要件の5ケース（capture 緩和 / 非対象維持 / 404 fail-secure / opt-out / allowlist 型安全 fail-safe）を過不足なく固定できている。
  - `captureShowContext()` の戻り型注釈 `array{User, Project, VideoManual}` も明確で、テスト可読性・型意図ともに良い。
  - 404ケースを「緩和漏れなし」として明示している点はセキュリティレビュー上有益。

**設計適合・規約適合サマリ**
- T1/T2/T3: **実装一致**
- allowlist マッチング / opt-out / fail-secure: **実装・テストとも一致**
- PHPStan level 10 観点: **問題なし**
- DTO/JsonResource 逸脱: **なし**（ヘッダ制御のみ、body 不変）
- least-privilege / cross-origin 非開放: **満たす**

**全体判定**
- **APPROVED**