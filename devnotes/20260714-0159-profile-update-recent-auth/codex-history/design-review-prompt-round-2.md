# 詳細設計レビュー Round 2

Round 1 の指摘への対応を報告します (使命・禁止事項は Round 1 提示済み)。

## Round 1 指摘への対応

### [Critical] S6 case 5 の dataset 境界（string 変更混入で 409/302 と衝突）
→ 対応。case 5 を 2 本に分離し dataset を厳密限定:
- **5a: stale + email 欠落** (`email` 未送信) → Validator 422 (recent-auth 応答でない)、email 不変
- **5b: stale + email 非 string (配列)** (`email=['x']`) → Validator 422、email 不変
string 値は case 5 に含めない (gate 対象 = 1a/1b が担う) 旨をテスト名/データセット名で明示。

### [Warning] S3 attachRecentAuthToSensitiveRoutes の肥大化
→ 対応。`appendMiddlewareIfMissing(RouteCollectionInterface $routes, string $name, string $alias): void`
ヘルパを切り出し、無条件群 (`RECENT_AUTH_ROUTE_NAMES`) / 条件付き群 (`CONDITIONAL_RECENT_AUTH_ROUTES`)
の双方で再利用。実装時に `getRoutes()` の具象型を確認し型を整合させる旨も注記。

### [Warning] S5 initialUser.email 基準比較のズレ
→ 対応。`let baselineEmail = $state(initialUser?.email ?? "")` を導入し、更新成功 (`onSuccess`) 時に
`baselineEmail = profileForm.email` へ同期。連続操作 (email 変更成功→再編集) の precheck 判定ドリフトを抑制。

### [Warning] S6 case 2 の 302/303 揺れ
→ 対応。遮断/成功の判定を `assertRedirect(遷移先)` 主体にし status 断定を回避 (Fortify `ProfileUpdatedResponse`
実装に追従)。case 2 は email 不変・name 更新・`assertNothingSent()` を併せて固定。

### [Warning] S6 1a/1b に email_verified_at 不変 assertion 追加
→ 対応。遮断ケース (1a/1b) に email 不変 + `email_verified_at` 不変 + `assertNothingSent()` を明記。

### [Suggestion] S7 viaRemember=false 対照 + put 呼び出し回数
→ 採用。listener テストに「viaRemember=false → stamp する」対照ケースを追加。client テストで stale 時の
`put` 呼び出し回数が「再認証後に 1 回」であることを検証 (二重送信回帰の捕捉)。

### [Warning/横断] EmailChangeTest を必須回帰に明記
→ 対応。施策一覧の注記に `tests/Feature/Auth/EmailChangeTest.php` を本タスク必須回帰
(旧アドレス通知 / email_verified_at null 化 / 重複 email 不可) として実行セットに含めると明記。

### [Suggestion] S1 Assert vs LogicException の流儀統一
→ 見送り (軽微)。条件付き middleware は $next を直接呼ぶ分岐のみで `Assert::isInstanceOf` で十分
(PHPStan L10 通過)。実装時に既存流儀へ合わせる余地は残す。

---

以上で Critical/Warning は解消できていますか。追加の懸念があれば指摘してください。
各施策の判定 (APPROVE / REQUEST_CHANGES) と全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。
