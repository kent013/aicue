# バックエンド・データ層の規約調査(視点⑥⑦)

> 対象: 両アプリの app/(レイヤ構造・Controller・FormRequest・Policy・例外・Enum・PHPStan・Event)
> と database/(migration・Model・Factory・Seeder・監査テーブル)。
> テンプレートのバックエンド規約のドナー判断と、保留事項「監査ログ正規形」の推奨案を含む。

## 1. レイヤ構造(ほぼ同型、テンプレ規約化可能)

両アプリ共通: `Actions/`(単一ユースケース実行器) / `Services/`(複雑な業務ロジック+transaction 内包) /
`Dtos/`(readonly) / `Values/`(軽量 VO: Stringable/readonly) / `Support/`(ユーティリティ・Concern) /
`Enums/` / `Policies/` / `Exceptions/`。

aigenba のみ: `Queries/`(CLI/API 共有の読み取り最適化) / `Domain/`(検証 VO) / `ValueObjects/` /
`Models/Builders/`(custom Eloquent Builder)。

**テンプレ方針**: 共通 7 種を必須レイヤとして同梱。`Queries/`・`Domain/`・`Builders/` は
「必要になったら導入する条件」を docs に書く(空ディレクトリは置かない)。

## 2. 確定させたい実装規約(両アプリで実証済みの共通形)

1. **Controller は薄く**: constructor DI で Service 集約 → `Assert::isInstanceOf($user, User::class)` →
   `$this->authorize('action', $model)`(Policy メソッド名で意図明示) → validate → Service/Action 委譲 →
   redirect+flash。**transaction は Service 側に内包、Controller では張らない**(aigenba T682 の知見)
2. **FormRequest は validation 単独責務**: authorize() は `return true`(Policy は Controller 側)。
   `ProhibitsProtectedKeys` を merge し protected key は **missing で後勝ち**。正規化は prepareForValidation()、
   TOCTOU 系の再検証は withValidator()
3. **Policy**: before() は使わない(明示 if 分岐)。組織スコープは `getOrganizationRole($org)` 型 helper +
   人名メソッド(manageMembers 等)。引数順は `User $user, Resource $resource` 固定
4. **例外**: spirux の **統一 ApiExceptionRenderer**(shouldHandle() で API 経路判定 → ApiErrorDto envelope、
   web は null return で Laravel 既定へ)+ **ApiErrorCode enum**(canonical code の SSOT、
   defaultStatus()/defaultMessage())をドナーに。MCP 用 JSON-RPC handler は MCP モジュール側(aigenba から)
5. **Enum**: backed enum + `label()`(UI 表示名)+ inverse map static method + 状態遷移等の
   business logic を enum 内包(aigenba ScenarioScope::fromScenario が見本)
6. **Event**: EventServiceProvider で明示登録(`shouldDiscoverEvents()=false` + listenOnce パターン=
   aigenba)。監査記録は Listener、Observer は使わない。非同期は Queueable+afterCommit
7. **PHPStan**: level 10、baseline なし。**カスタムルール機構**(aigenba `app/PHPStan/Rules/` の
   AST ルール=immutability invariant の compile-time 強制)を雛形 1 本付きで同梱。
   Passport published migration は excludePaths、Prism/Socialite は scanDirectories

アンチパターン集(docs 化): controller transaction / FormRequest authorize で重い Policy /
Observer audit / catch(Throwable) 黙殺 / API と web の error format 不一致 / プラン名でのコード分岐。

## 3. データ層規約

1. **PK**: `id()`(bigint)標準。時系列 trace が要るものだけ ULID(aigenba Encounter が例)
2. **FK**: 所有権キー(laratrust_team_id 等)は `restrictOnDelete()`、配下リソースは `cascadeOnDelete()`
3. **DB CHECK**: cross-org invariant(ownership XOR 等)は DB 層でも強制。MySQL/PostgreSQL は
   ALTER TABLE、SQLite は no-op+理由コメント。down() は違反行があれば RuntimeException で停止
4. **$fillable**: UI 入力属性のみ。ownership/actor/state キーは除外し**明示代入 or named method**
5. **casts()**: enum は全 cast、課金系日時は `immutable_datetime`、secret は $hidden 併用
6. **CipherSweet**: PII(email/name)を暗号化+blind index。検索は `whereBlind()`。
   トークン類は平文を即 sha256 → `*_hash` 列のみ保存
7. **SoftDeletes**: テナント(Organization)は soft delete+phantom 防御(active 確認+lockForUpdate)。
   配下リソースは運用判断
8. **複雑な ownership スコープ**: 2 経路以上の WHERE は custom Builder(`newEloquentBuilder()`)に集約し
   read/write 両方が同一スコープを通る(aigenba ScenarioBuilder::forOrganization が見本)
9. **Factory**: `state()`=属性上書き / `afterCreating()`=リレーション生成。秘密値は
   `createWithPlainKey(): [Model, string]` のペア返し。ownership が複雑な model は
   `projectScoped()/organizationScoped()` 型の自己説明的 state 名
10. **Seeder 順序**: Role → Permission → RolePermission → Plan → ドメイン → ManualTestSeeder(dev用)。
    全 seeder は firstOrCreate/updateOrCreate で再実行安全

## 4. 監査ログ正規形(保留事項 1 の推奨案)

実装比較の結果、両者は**重複ではなく責務の異なる 3 層**だった:

| 層 | 出自 | 責務 | 特性 |
|---|---|---|---|
| **AuditLog**(aigenba) | API v1/MCP/重要 Web 操作の操作ログ | actor 多態(ApiKey/PlatformApiKey/User/System)、organization_id スコープ、dotted action code、metadata JSON | **append-only 不変**(updated_at 無し、update/delete を Eloquent event+Builder で例外化) |
| **SecurityAuditEvent**(spirux) | 認証・セキュリティイベント(2FA 有効化、login_failed 等) | user_id 単位、SecurityEventType enum 固定 | 軽量、Listener が自動記録 |
| **ModelAudit**(両方) | 管理画面の critical action による属性 diff | owen-it/laravel-auditing + AppliesCriticalActionContextToAudit trait(source/action/reason tags) | old/new values JSON |

**推奨**: テンプレートは 3 層すべてを同梱し、名称はこのまま正規化
(`AuditLog` / `SecurityAuditEvent` / `ModelAudit`)。「操作ログ=AuditLog、認証イベント=
SecurityAuditEvent、管理属性 diff=ModelAudit」という使い分け規則を docs に明記。
AuditLog の不変性機構(append-only 強制)もそのまま移植する。
→ 05 の保留事項 1 はこの案で Phase 1 設計時に最終確定。

## 5. スキーマの土台(テンプレ migration の元)

- users(CipherSweet email/name + blind index、current_organization_id)
- organizations(slug unique、laratrust_team_id unique+restrictOnDelete、billing_contact_*、SoftDeletes)
- teams(Laratrust、Organization と 1:1)+ role/permission pivot 群
- custom_teams(organization_id cascade、is_default ← 06 の Default Team 列を追加)
- projects(custom_team_id cascade ← Q1 決定により aigenba 形)+ project_members pivot(spirux から)
- 課金系(Models/Billing 名前空間に対応する billing_* テーブル群)+ organization_quotas/usages(spirux)
- api_keys / idempotency_keys / mcp_idempotency_keys / audit 3 層 / email_suppressions / inquiries
