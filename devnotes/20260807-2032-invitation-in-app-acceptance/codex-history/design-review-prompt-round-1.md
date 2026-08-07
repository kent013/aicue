# 詳細設計レビュー依頼 (aicue / invitation-in-app-acceptance)

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


## 思考原則

1. **フレームワークのレンジ内でやる**。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. **後方互換の並走を残さない**。書き換えると決めたら同じ PR で旧実装を消す
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**。各ステップで他要素との結合観点を確認する

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)


【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠（UI/frontend 変更を含む場合）: `/DESIGN.md` が design token の canonical source。color / radius / typography を token 経由で参照する設計か、hex 直書きを増やさないか。token 変更時は `resources/css/tokens.css` との同期を設計に織り込んでいるか（運用契約は `docs/design-system.md`）
11. Atomic Design準拠（UI/frontend 変更を含む場合）: `resources/js/components/` の `atoms/molecules/organisms/templates` の責務分離に沿った配置か。atom は単機能・無状態、molecule は atom の組合せという階層を逆流していないか。アイコンは Lucide 前提で、SVG 直書きを新設していないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

【背景 (前提としてよい事実)】
- 概念設計は同じセッションで 4 ラウンドかけて APPROVED 済み。必須 3 点 (存在秘匿の一律 404 /
  受諾解決と件数・一覧で同一絞り込みを再利用 / 未ログイン・未 verified・email 空は DB を引かない) は
  複数リポジトリ共有台帳の裁定 AG-113 由来でリポジトリ側の裁量ではない。
- 役割付き招待 (organization_invitations.project_role) の撤去は裁定 AG-079 のオーナー判断。

---

## 詳細設計書

# 詳細設計: invitation-in-app-acceptance

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### 思考原則

1. フレームワークのレンジ内でやる / 2. 今必要なものだけ作る / 3. 後方互換の並走を残さない /
4. 別物の概念を「似ているから」で統合しない / 5. テストファースト / 6. タコツボ実装を避ける

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`）。**RefreshDatabase** は `tests/Pest.php` でグローバル適用済み、
  `--parallel` 実行。**個別 `DatabaseTransactions` 使用禁止**
- **テストデータは必ず Factory** で生成（`Model::create()` 手組み禁止）
- **DTO + JsonResource / Inertia** パターン。`declare(strict_types=1)` + 日本語コメント
- Controller は薄く（Service 委譲）、transaction は Service 内。保護キーは forceFill / relation で明示代入
- アーリーリターン推奨 / `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- component 階層は `atoms → molecules → organisms → features/{domain} → templates → pages` の単方向 import のみ

### 本件が特に従うセキュリティ不変条件

- **子は親に属する**: nested route の不整合は**認可より前に 404**（`NestedRouteIdorDefenseTest` の inventory に登録必須）
- **cross-org 不可**: 組織を跨ぐ read/write をしない（relation / org-scoped 解決経由のみ）
- **PII(email/name)は CipherSweet**。検索は `whereBlind()`
- **変更系 route は認可を通る**: `Gate::authorize` を通すか exemption inventory へ理由付きで登録（deny-by-default）
- **層 2 は binding の直後・FormRequest より前で閉じる**（`TenantBoundaryOrderingTest`）
- **流量制限**: `invitations.` 始まりの route は `ThrottleCoverageInventoryTest` の S3

## 概念設計リファレンス

- `devnotes/20260807-2032-invitation-in-app-acceptance/conceptual-design.md`（Codex Round 4 で APPROVED）
- 実査ブリーフ: `devnotes/20260807-2032-invitation-in-app-acceptance/recon-brief.md`
- lctl 台帳: `auth-invitation-in-app-discovery`（裁定 AG-113）/ `auth-invitation-flow`（裁定 AG-079）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 受信者視点の単一解決口 (`scopeActivePendingForEmail`) | `app/Models/OrganizationInvitation.php` | 高 |
| 2 | 受信者視点 DTO | `app/DataTransferObjects/Invitations/PendingInvitationForUserDto.php` (新規) | 高 |
| 3 | 受諾サービス + 共有コアの戻り値強化 | `app/Services/Organization/OrganizationMembershipService.php` | 高 |
| 4 | 受諾 route / Controller / named limiter / gate 6 本 | `routes/web.php` / `app/Http/Controllers/Organizations/AcceptInvitationInAppController.php` (新規) / `app/Providers/AppServiceProvider.php` / `app/Http/Routing/RouteBindingTypes.php` / `tests/Support/Routing/NestedRouteDefenseInventory.php` / `tests/Architecture/ControllerAuthorizationGateTest.php` / `tests/Architecture/MembershipWriteLockInventoryTest.php` / `tests/Architecture/RateLimiterKeyConventionTest.php` | 高 |
| 5 | 発見面 → 受諾の導線 | `app/Http/Controllers/NotificationController.php` / `resources/js/pages/Notifications/Index.svelte` / `resources/js/components/features/notifications/NotificationListItem.svelte` / `resources/js/components/features/invitations/PendingInvitationList.svelte` (新規) / `resources/js/types/invitation.ts` (新規) | 高 |
| 6 | 横断の気づき (共有 prop + notice) | `app/Http/Middleware/HandleInertiaRequests.php` / `resources/js/lib/shared-props.ts` / `resources/js/components/molecules/PendingInvitationsNotice.svelte` (新規) / `resources/js/components/templates/AppLayout.svelte` | 中 |
| 7 | 役割付き招待 (`project_role`) の撤去 | migration (新規) / `app/Models/OrganizationInvitation.php` / `app/Services/Organization/OrganizationMembershipService.php` / `app/Http/Requests/Organizations/StoreOrganizationInvitationRequest.php` / `app/DataTransferObjects/Admin/InvitationRowData.php` / `database/factories/OrganizationInvitationFactory.php` / `resources/js/pages/Admin/Users.svelte` / `resources/js/types/admin.ts` | 高 |
| 8 | 目録型 gate (受信者視点解決の deny-by-default) | `app/Enums/Security/InvitationResolutionScope.php` (新規) / `tests/Architecture/InvitationResolutionInventoryTest.php` (新規) | 高 |
| 9 | ドキュメント更新 | `docs/architecture.md` / `docs/template-divergence.md` / `docs/factories.md` | 中 |

実装順序は **8 → 1 → 2 → 3 → 4 → 7 → 5 → 6 → 9**。
gate (施策 8) を先に置き、mutation で赤化を確認してから本体に入る（思考原則 5 テストファースト）。

---

## 施策 1: 受信者視点の単一解決口 (`scopeActivePendingForEmail`)

### 変更箇所

- `app/Models/OrganizationInvitation.php`（`scopeActive` の直後に追加。L120-130 付近）

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし（施策 2 が消費する）
- テストファイル: `tests/Feature/Invitations/PendingInvitationScopeTest.php`（新規）

### 現行コード

```php
    /**
     * Active (受諾可能: 未受諾・未失効・期限内) な招待の query scope。
     *
     * @param  Builder<OrganizationInvitation>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }
```

### 変更後コード

```php
    /**
     * Active (受諾可能: 未受諾・未失効・期限内) な招待の query scope。
     *
     * @param  Builder<OrganizationInvitation>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    /**
     * **受信者視点の単一解決口** — 「この email 宛の、いま受諾できる招待」の集合。
     *
     * アプリ内受諾 (invitations.accept-in-app) の解決・一覧・件数はすべてこの scope を
     * 再利用する (裁定 AG-113 の必須要素 (b)。2 つがずれると「件数は出るのに受諾できない」が起きる)。
     * 再利用の強制は InvitationResolutionInventoryTest が deny-by-default で行う。
     *
     * 3 条件は**すべて存在秘匿のためにある**:
     *  - active(): 期限切れ・取消済・受諾済を落とす
     *  - whereBlind: 宛先不一致を落とす (CipherSweet の blind index。平文 where は hit しない)
     *  - whereHas('organization'): 削除済み組織宛を落とす
     *    (Organization は SoftDeletes。default scope が効くため deleted_at 判定を手書きしない)
     * これらが**すべて同じ「0 件」に collapse する**ことが、呼び出し側で理由を出し分けずに
     * 一律 404 へ畳める根拠である (403 を返さない = 招待の存在を教えない)。
     *
     * ★email は**大文字小文字を区別する完全一致**である (email の blind index に
     *   Lowercase transformer を付けていない。name 側とは非対称)。大小差のある宛先は
     *   0 件 = 404 に倒れる (fail-secure)。従来のメール token 経路は token_hash 照合なので
     *   影響を受けず、そちらで受諾できる。
     * ★空文字 email での呼び出しは**呼び出し側が事前に弾く**契約
     *   (OrganizationMembershipService::pendingInvitationsQuery)。ここでは防御しない
     *   (guard を 2 箇所に置くと「どちらが正か」が曖昧になる)。
     *
     * @param  Builder<OrganizationInvitation>  $query
     */
    public function scopeActivePendingForEmail(Builder $query, string $email): void
    {
        $query->active()
            ->whereBlind('email', 'email_index', $email)
            ->whereHas('organization');
    }
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`void`。Eloquent scope 規約）
- [x] null 安全（`string $email` は非 null。空文字契約は呼び出し側）
- [x] DTO を返している（scope なので該当なし）
- [x] Generics の型パラメータが正しい（`Builder<OrganizationInvitation>`）
- `whereBlind` は `Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet` が提供する
  `scopeWhereBlind` 由来。既存 `User::whereBlind('email', 'email_index', ...)` と同じ形で
  PHPStan は解決済み（`app/Services/Organization/OrganizationMembershipService::emailBelongsToMember` に前例）

### テスト計画

`tests/Feature/Invitations/PendingInvitationScopeTest.php`（新規）

- [ ] `自分宛の active な招待だけを返す` — 同一 email 宛 active 1 件 + 他人宛 active 1 件 → 1 件
- [ ] `期限切れ・取消済・受諾済は返さない` — `expired()` / `revoked()` / `accepted()` state を各 1 件作り 0 件
- [ ] `削除済み (soft-deleted) 組織宛は返さない` — `$organization->delete()` 後に 0 件
- [ ] `email の大小差は一致しない (完全一致契約)` — `Foo@example.com` 宛の招待は `foo@example.com` で 0 件
- [ ] テストデータは `OrganizationInvitation::factory()->forOrganization($organization)->create([...])`
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- `whereHas('organization')` はサブクエリを 1 本足す。招待テーブルは 1 組織あたり数件規模で、
  `organization_id` に index がある（`create_organization_invitations_table` の複合 index）ため
  実害はない。共有 prop で毎リクエスト評価される点は施策 6 のリスク欄で扱う。

---

## 施策 2: 受信者視点 DTO (`PendingInvitationForUserDto`)

### 変更箇所

- `app/DataTransferObjects/Invitations/PendingInvitationForUserDto.php`（新規）

### 波及変更

- TypeScript 型定義: `resources/js/types/invitation.ts`（新規。施策 5 で作る `PendingInvitation`）
- API Resource/DTO: 管理者視点の `app/DataTransferObjects/Admin/InvitationRowData.php` とは**別クラスのまま**
- テストファイル: `tests/Feature/Invitations/PendingInvitationForUserDtoTest.php`（新規）

### 現行コード

（新規ファイル。参考にする既存形は `app/DataTransferObjects/Admin/InvitationRowData.php`）

```php
final readonly class InvitationRowData
{
    public function __construct(
        public int $id,
        public string $email,
        public string $roleState, // MemberRoleState value
        public string $roleLabel,
        public string $expiresAt, // Y-m-d
    ) {}
    // ...
}
```

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Invitations;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use Illuminate\Support\Carbon;
use Webmozart\Assert\Assert;

/**
 * **受信者視点**の保留中招待 1 件（アプリ内受諾 UI 用）。
 *
 * 管理者視点の InvitationRowData とは契約を分離したままにする（裁定 AG-113）:
 * 管理者は「誰を招待したか (email)」を見る面、受信者は「どこへ参加できるか」を見る面であり、
 * 開示すべき項目が違う。似ているからと統合しない（思考原則 4）。
 *
 * **開示するのはこの 4 つだけ**。email（自分の値だが載せる必要がない）/ token_hash /
 * accepted_at・revoked_at・expires_at の生値 / invited_by_user_id / organization_id は出さない。
 * 受諾 URL も持たせない（署名も token も無い経路のため、サーバが URL を配る意味が無く
 * 開示面だけ増える。front が route から組む）。
 */
final readonly class PendingInvitationForUserDto
{
    public function __construct(
        public int $id,
        public string $organizationName,
        public string $roleLabel,
        public string $expiresAt, // Y-m-d
    ) {}

    /**
     * scopeActivePendingForEmail で解決済みの招待から組み立てる。
     *
     * 呼び出し側で `->format()` を書かない（日時の文字列化責務をここへ集約する）。
     * organization は scope の whereHas で存在が保証されているが、
     * relation の遅延解決が null を返す可能性を型で潰すため Assert で narrow する。
     */
    public static function fromInvitation(OrganizationInvitation $invitation): self
    {
        $organization = $invitation->organization;
        Assert::isInstanceOf($organization, Organization::class);

        $expiresAt = $invitation->getAttribute('expires_at');
        Assert::isInstanceOf($expiresAt, Carbon::class);

        return new self(
            id: $invitation->id,
            organizationName: $organization->name,
            roleLabel: OrganizationRole::from($invitation->role)->label(),
            expiresAt: $expiresAt->toDateString(),
        );
    }

    /**
     * @return array{id: int, organizationName: string, roleLabel: string, expiresAt: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organizationName' => $this->organizationName,
            'roleLabel' => $this->roleLabel,
            'expiresAt' => $this->expiresAt,
        ];
    }
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`self` / `array{...}` shape）
- [x] null 安全（`Assert::isInstanceOf` で `?Organization` と `mixed` を narrow）
- [x] DTO を返している（Inertia props へは `toArray()` の shape で渡す）
- [x] Generics の型パラメータが正しい（該当なし）

### テスト計画

`tests/Feature/Invitations/PendingInvitationForUserDtoTest.php`（新規）

- [ ] `開示項目は 4 つだけ` — `toArray()` の `array_keys()` が
      `['id', 'organizationName', 'roleLabel', 'expiresAt']` と**完全一致**すること
      （キー追加を機械検出する = 将来 email 等が混入したら赤くなる）
- [ ] `email / token_hash を含まない` — `json_encode($dto->toArray())` に招待 email 文字列と
      `token_hash` 値が含まれないこと（値ベースの negative control）
- [ ] `roleLabel は org ロールのラベル` — admin 招待 → `管理者` / member 招待 → `メンバー`
- [ ] `expiresAt は Y-m-d` — `expires_at` に固定日時を入れて文字列一致
- [ ] Factory 経由で招待を生成する

### リスク

- なし（新規 read-only DTO）。既存 `InvitationRowData` は触らない（施策 7 で別途変更）。

---

## 施策 3: 受諾サービス + 共有コアの戻り値強化

### 変更箇所

- `app/Services/Organization/OrganizationMembershipService.php`
  - `acceptInvitation()`（L110-138 付近）: `joinOrganization()` の `false` を消費
  - `acceptInvitationIfValid()`（L160-195 付近）: 同上 + 現在組織確定の抑止
  - `joinOrganization()`（L273-315 付近）: `void` → `bool`
  - **新規**: `pendingInvitationsQuery()`（private）/ `pendingInvitationsFor()` /
    `pendingInvitationCountFor()` / `acceptPendingInvitation()`

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: `PendingInvitationForUserDto`（施策 2）を返す
- テストファイル:
  - `tests/Architecture/MembershipWriteLockInventoryTest.php`（新 public メソッドの分類登録。施策 4）
  - `tests/Feature/Organization/InvitationAcceptRaceTest.php`（新規。`false` 消費の契約）
  - `tests/Feature/Organization/InvitationTest.php`（既存。register 経路の現在組織確定の契約を追加）

### 現行コード

```php
    private function joinOrganization(OrganizationInvitation $invitation, Organization $organization, User $user, OrganizationRole $role): void
    {
        DB::transaction(function () use ($organization, $user, $role, $invitation): void {
            $this->lockForMembershipWrite([$this->keyOf($user)], [$this->keyOf($organization)]);

            /** @var OrganizationInvitation $locked */
            $locked = OrganizationInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            if ($locked->isAccepted() || $locked->isRevoked() || $locked->isExpired()) {
                return; // 期限境界の TOCTOU も含めロック下で完全再検証 (敗者は冪等 no-op)
            }

            $joined = DB::table('organization_user')->insertOrIgnore([...]);

            if ($joined === 1) {
                $user->addRole($role->value, $organization->laratrust_team_id);

                $projectRole = $locked->project_role;
                if ($projectRole instanceof ProjectRole) {
                    $project = $this->defaultProjects->resolveForUpdate($organization);
                    $project?->members()->syncWithoutDetaching([...]);
                }
            }

            $locked->forceFill(['accepted_at' => now()])->save();
        });
    }
```

```php
    public function acceptInvitation(string $plainToken, User $user): Organization
    {
        // ... 事前検証 (invalid / accepted / expired / 既メンバー) ...
        $role = OrganizationRole::from($invitation->role);

        $this->joinOrganization($invitation, $organization, $user, $role);

        return $organization;
    }
```

```php
    public function acceptInvitationIfValid(string $plainToken, User $user): ?Organization
    {
        // ... findActiveByPlainToken / email 一致 / 既メンバー ...
        $this->joinOrganization($invitation, $organization, $user, OrganizationRole::from($invitation->role));

        $user->forceFill(['current_organization_id' => $organization->id])->save();

        return $organization;
    }
```

### 変更後コード

```php
    /**
     * **受信者視点の pending 集合クエリの唯一の起点**。
     *
     * 裁定 AG-113 の必須要素 (b)(c) をここ 1 箇所で満たす:
     *  (b) 受諾の解決・一覧・件数がすべてこのメソッドを通る (絞り込みが 1 本 = drift しない)
     *  (c) 未ログイン / 未 verified / email 空は **null を返し DB を一切引かない**
     *      (共有 prop は全リクエストで評価されるため、この early return が実効的な負荷契約になる)
     *
     * @return Builder<OrganizationInvitation>|null null = 引くべきでない (クエリを組み立てない)
     */
    private function pendingInvitationsQuery(?User $user): ?Builder
    {
        if ($user === null || ! $user->hasVerifiedEmail()) {
            return null;
        }

        $email = $user->email; // CipherSweet 復号後
        if ($email === '') {
            return null;
        }

        return OrganizationInvitation::query()->activePendingForEmail($email);
    }

    /**
     * 自分宛の受諾可能な招待の一覧 (受信者視点 DTO)。表示専用でロックしない。
     *
     * @return list<PendingInvitationForUserDto>
     */
    public function pendingInvitationsFor(?User $user): array
    {
        $query = $this->pendingInvitationsQuery($user);
        if ($query === null) {
            return [];
        }

        return $query->with('organization')
            ->orderBy('expires_at')
            ->get()
            ->map(fn (OrganizationInvitation $invitation): PendingInvitationForUserDto => PendingInvitationForUserDto::fromInvitation($invitation))
            ->values()
            ->all();
    }

    /** 自分宛の受諾可能な招待の件数 (共有 prop 用。一覧と同一 scope を再利用する)。 */
    public function pendingInvitationCountFor(?User $user): int
    {
        return $this->pendingInvitationsQuery($user)?->count() ?? 0;
    }

    /**
     * **アプリ内受諾** (メールの URL を根拠にしない受諾。裁定 AG-113 標準形 v1)。
     *
     * 受諾の根拠は「auth 済み ∧ email 確認済み ∧ ログイン者 email = 招待宛先」であり、
     * その全部が pendingInvitationsQuery() の 1 本に畳まれている。
     *
     * **戻り値契約**: 業務上の受諾不能 (宛先不一致 / 不在 / 期限切れ / 取消済 / 受諾済 /
     * 組織削除済 / ロック下再検証での敗北) は例外にせず null を返す (呼び出し側が一律 404)。
     * DB 障害・インフラ障害・プログラム不整合の例外は**捕捉せず伝播させる** (500 のまま。
     * 404 に化けさせない)。この分離により、将来この分岐へ理由を足しても情報が漏れない。
     *
     * **ロックと最終権威**:
     *  1. 下見 (ロック無し) で organization_id を得る
     *  2. canonical 順序 (users 昇順 → organizations) で lockForMembershipWrite
     *     — 組織の soft-delete は同じ organizations 行の UPDATE なのでここで直列化される
     *  3. **ロック下で同一 scope を再解決** — ここが組織 soft-delete / 取消 / 期限に対する権威
     *  4. joinOrganization() が招待行を lockForUpdate して最終再検証 (取消の割り込みはここが閉じる。
     *     revokeInvitation は membership ロックを取らないため 3 と 4 の間に窓があるが、
     *     取り消し側の UPDATE も同じ招待行を取るため直列化される)
     * joinOrganization() は同一 tx 内で同じ行の lockForMembershipWrite を再取得するが、
     * 取得済み行の再取得は no-op でロック順序も変わらない (新しい順序を作らない
     * = デッドロックを導入しない)。
     *
     * @param  string  $invitationId  route parameter (未検証の文字列。pattern で 1-18 桁数値に制約済み)
     */
    public function acceptPendingInvitation(?User $user, string $invitationId): ?Organization
    {
        if ($user === null) {
            return null;
        }

        return DB::transaction(function () use ($user, $invitationId): ?Organization {
            // 1. 下見 (ロック前)。ここで null なら DB もロックも最小で終わる
            $preliminary = $this->pendingInvitationsQuery($user)?->whereKey($invitationId)->first();
            if ($preliminary === null) {
                return null;
            }

            // 2. canonical 順序でロック (users 昇順 → organizations)
            $organizationId = $preliminary->getAttribute('organization_id');
            Assert::integer($organizationId);
            $this->lockForMembershipWrite([$this->keyOf($user)], [$organizationId]);

            // 3. ロック下で同一 scope を再解決 (下見の結果は信用しない)
            $invitation = $this->pendingInvitationsQuery($user)?->whereKey($invitationId)->first();
            if ($invitation === null) {
                return null;
            }

            $organization = $invitation->organization;
            Assert::isInstanceOf($organization, Organization::class);

            // 4. 変換本体 (署名経路と共有)。false = 招待行ロック下の再検証で受諾不能
            if (! $this->joinOrganization($invitation, $organization, $user, OrganizationRole::from($invitation->role))) {
                return null;
            }

            // 現在組織は切り替えない (POST 受諾の既存契約と揃える。驚き最小)
            return $organization;
        });
    }
```

`joinOrganization()` の変更（`bool` 化 + pivot attach 撤去は施策 7）:

```php
    /**
     * 招待受諾の確定処理 (attach + ロール付与 + accepted_at)。両受諾経路の共通コア。
     *
     * @return bool true = ロック下再検証を通り変換が完了した (既 join の冪等 no-op を含む) /
     *              false = ロック下で受諾不能 (受諾済 / 取消済 / 期限切れ) だった。
     *              **false は全呼び出し元が必ず消費する** (成功扱いで返さない)。
     */
    private function joinOrganization(OrganizationInvitation $invitation, Organization $organization, User $user, OrganizationRole $role): bool
    {
        return DB::transaction(function () use ($organization, $user, $role, $invitation): bool {
            $this->lockForMembershipWrite([$this->keyOf($user)], [$this->keyOf($organization)]);

            /** @var OrganizationInvitation $locked */
            $locked = OrganizationInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            if ($locked->isAccepted() || $locked->isRevoked() || $locked->isExpired()) {
                return false; // 期限境界の TOCTOU も含めロック下で完全再検証 (敗者は受諾不能)
            }

            $joined = DB::table('organization_user')->insertOrIgnore([
                'organization_id' => $organization->id,
                'user_id' => $user->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($joined === 1) {
                $user->addRole($role->value, $organization->laratrust_team_id);
            }

            $locked->forceFill(['accepted_at' => now()])->save();

            return true;
        });
    }
```

既存 2 経路の追随:

```php
    public function acceptInvitation(string $plainToken, User $user): Organization
    {
        // ... 事前検証は現行のまま ...
        if (! $this->joinOrganization($invitation, $organization, $user, $role)) {
            // ロック下再検証で受諾不能になった (並行受諾 / 取り消し / 期限到来)。
            // 事前検証と同じ中立メッセージへ畳む (取り消された事実を token 保持者に開示しない)
            throw ValidationException::withMessages(['token' => ['この招待は無効です。']]);
        }

        return $organization;
    }
```

```php
    public function acceptInvitationIfValid(string $plainToken, User $user): ?Organization
    {
        // ... findActiveByPlainToken / email 一致 / 既メンバー は現行のまま ...
        if (! $this->joinOrganization($invitation, $organization, $user, OrganizationRole::from($invitation->role))) {
            // 受諾不能なら現在組織も確定しない (現行は join 失敗でも current_organization_id を
            // 招待組織へ書いてしまい、非所属 org が current になる非正規状態を作っていた)
            return null;
        }

        $user->forceFill(['current_organization_id' => $organization->id])->save();

        return $organization;
    }
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`?Builder`, `list<PendingInvitationForUserDto>`, `int`, `?Organization`, `bool`）
- [x] null 安全（`Assert::integer` / `Assert::isInstanceOf` / null 合体演算子）
- [x] DTO を返している（一覧は DTO の list。配列生値を返さない）
- [x] Generics の型パラメータが正しい（`Builder<OrganizationInvitation>` を `@return` に明記）
- `DB::transaction()` のクロージャ戻り値は Laravel が透過して返す（`@template` 付き）ため
  `?Organization` / `bool` が推論される。`use` 変数の型も明示済み

### テスト計画

`tests/Feature/Organization/InvitationAcceptRaceTest.php`（新規。**`false` 消費の契約**）

> docblock に「**目的は競合の完全再現ではなく `joinOrganization() === false` の消費契約を
> 決定的に検証すること**」と明記する。

決定的な作り方: テスト内でだけ `OrganizationInvitation::retrieved` にリスナを登録し、
**取得回数をカウント**して「ロック下の再取得（`joinOrganization` 内の `firstOrFail`）」に
当たる回だけ `forceFill(['revoked_at' => now()])` を当てる
（`retrieved` は通常取得でも発火するため、回数を絞らないと事前検証で落ちて別の分岐を測る）。

- [ ] `acceptInvitation はロック下再検証の敗北を ValidationException へ畳む` —
      メッセージが事前検証と同一（`この招待は無効です。`）であること
- [ ] `acceptInvitationIfValid はロック下再検証の敗北で null を返し、現在組織を書き換えない` —
      `$user->fresh()->current_organization_id` が呼び出し前と不変
- [ ] `acceptPendingInvitation はロック下再検証の敗北で null を返す`
- [ ] いずれのケースでも `organization_user` に行が増えていないこと

`tests/Feature/Invitations/PendingInvitationQueryGuardTest.php`（新規。**(c) の DB 非問い合わせ**）

- [ ] `未ログインは organization_invitations を引かない` — `DB::listen` で
      `organization_invitations` を含む SQL が 0 件、戻り値 0 / `[]`
- [ ] `未 verified は引かない` — `User::factory()->unverified()` で同上
- [ ] `email 空は引かない` — `forceFill(['email' => ''])` した user で同上
- [ ] `verified かつ email 非空のときだけ引く` — SQL が 1 件以上発行される（**負のコントロール**。
      guard が常に null を返す実装退行を検出する）

`tests/Feature/Organization/InvitationTest.php`（既存の更新）

- [ ] register 経路の既存テストに「join に成功したときだけ current_organization_id が確定する」
      アサーションを追加（既存の成功系テストは挙動不変であることの確認）

### リスク

- `joinOrganization()` の戻り値変更は**署名 token 経路の競合時挙動を変える**（成功扱い →
  失敗契約）。これは是正であって後退ではないが、既存 `InvitationTest` の
  「二重受諾」系テストが成功を期待していないかを実装時に確認する
  （現行は事前検証で `既に使用されています` に落ちるため影響しない見込み）。
- `acceptPendingInvitation` は下見 + 再解決で**招待の解決クエリを 2 回**発行する。
  受諾は一回性の低頻度操作であり、ロック確立後の再解決を省くと存在秘匿の権威が失われるため
  この 2 回は設計上必要なコスト。
- `pendingInvitationsFor` は `with('organization')` で N+1 を避ける（DTO が organization->name を読む）。

---

## 施策 4: 受諾 route / Controller / named limiter / gate 6 本

### 変更箇所

- `routes/web.php`（招待受諾セクション L598-617 の直後）
- `app/Http/Controllers/Organizations/AcceptInvitationInAppController.php`（新規）
- `app/Providers/AppServiceProvider.php`（named limiter の追加。L282 付近）
- `app/Http/Routing/RouteBindingTypes.php`（`MANUALLY_RESOLVED`）
- `tests/Support/Routing/NestedRouteDefenseInventory.php`（`inventory()`）
- `tests/Architecture/ControllerAuthorizationGateTest.php`（`controllerAuthorizationExemptions()`）
- `tests/Architecture/MembershipWriteLockInventoryTest.php`（`delegatedToLocked` / `exempt`）
- `tests/Architecture/RateLimiterKeyConventionTest.php`（limiter inventory + 評価シナリオ）

### 波及変更

- TypeScript 型定義: なし（施策 5 で front を作る）
- API Resource/DTO: なし
- テストファイル: `tests/Feature/Invitations/AcceptInvitationInAppTest.php`（新規、存在秘匿の網羅）

### 現行コード

```php
// routes/web.php L609-616
Route::get('/invitations/accept', [InvitationAcceptanceController::class, 'show'])
    ->middleware('throttle:invitation-accept')
    ->name('invitations.accept');
Route::post('/invitations/accept', [InvitationAcceptanceController::class, 'store'])
    ->middleware(['auth', 'throttle:10,1'])
    ->name('invitations.accept.store');
```

```php
// app/Providers/AppServiceProvider.php L282-283
        RateLimiter::for('invitation-accept', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('invitation-accept:ip:'.($request->ip() ?? 'unknown')));
```

### 変更後コード

**route**（既存 2 本の直後に追加。`require-active-subscription` group の外＝既存 `invitations.*` と同じ扱い。
未契約組織を current org に持つユーザーが別組織の招待を受諾できないと詰むため）:

```php
/*
| アプリ内受諾 (メールの URL を根拠にしない第 2 の受諾経路。裁定 AG-113 標準形 v1)。
| 根拠は「auth 済み ∧ email 確認済み ∧ ログイン者 email = 招待宛先」。
| 既存 token 経路を**置き換えず並べて持つ** (未登録の人にはメールが唯一の入口)。
|
| ★{invitation} は implicit binding させない。binding 段で解決すると
|   「不在 id = binding 404 / 実在の他人宛 = 後段の応答」に分岐し 1 bit の存在オラクルになる。
|   controller が $user 宛の有効 pending 集合から手動解決し、全ての不成立を 404 へ畳む。
| ★verified 必須。姉妹の invitations.accept.store は意図的に verified を要求しない
|   (招待直後の未検証ユーザーも token で受諾できる仕様) — この非対称は仕様であり、
|   受諾根拠が違うことに由来する (docs/architecture.md に理由を明記)。
| ★throttle は named limiter。inline throttle は同一 actor の全 inline route と bucket を
|   共有し、最小 max (recent-auth.password = 6) を巻き添えにするため使わない。
*/
Route::post('/invitations/{invitation}/accept-in-app', AcceptInvitationInAppController::class)
    ->middleware(['auth', 'verified', 'throttle:invitation-accept-in-app'])
    ->name('invitations.accept-in-app');
```

**named limiter**:

```php
        // アプリ内受諾 (invitations.accept-in-app)。閾値は姉妹 invitations.accept.store
        // (throttle:10,1) / invitation-accept (10/min) と同値 = 既存値を変えない。
        // ★キーは actor 単位。throttle は auth より前に走るため未認証でも評価されうるので
        //   render-trigger と同じ idiom で 'guest' へ落とす (未認証は後段の auth で 302 になり
        //   受諾へ到達できないため、guest 同士が同一 bucket でも実害が無い)。
        // ★route parameter ({invitation}) をキーに混ぜない。混ぜると bucket が分かれ
        //   「429 になるまでの回数」が存在オラクルになる。
        RateLimiter::for('invitation-accept-in-app', function (Request $request): Limit {
            $user = $request->user();
            $actor = $user instanceof User ? (string) $user->id : 'guest';

            return Limit::perMinute(10)->by("invitation-accept-in-app:user:{$actor}");
        });
```

**Controller**:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Webmozart\Assert\Assert;

/**
 * アプリ内からの招待受諾 (POST invitations.accept-in-app)。裁定 AG-113 標準形 v1。
 *
 * 受諾の根拠は URL の所持ではなく「auth 済み (middleware) ∧ email 確認済み (middleware) ∧
 * ログイン者 email = 招待の宛先 (Service の scope)」。
 *
 * **認可 (Gate) を持たない**のは意図的である:
 * 受諾前の user は対象組織の非メンバーで組織 Policy は構造的に必ず拒否になるうえ、
 * 403 を返すこと自体が「その招待は実在する」を教える口になる。代わりに
 * OrganizationMembershipService::acceptPendingInvitation() が
 * $user 宛の有効 pending 集合からしか解決せず、**すべての不成立を 404 に畳む**
 * (ControllerAuthorizationGateTest に SelfScopedResource として理由付きで登録済み)。
 *
 * **{invitation} は string で受ける**。型を Model にすると implicit binding が復活し、
 * 不在 id だけが binding 段で 404 になって存在オラクルが生まれる
 * (TenantBoundaryOrderingTest 検査 3a が action 引数型を機械検証する)。
 */
class AcceptInvitationInAppController extends Controller
{
    public function __invoke(Request $request, string $invitation, OrganizationMembershipService $membership): RedirectResponse
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $organization = $membership->acceptPendingInvitation($user, $invitation);

        // 業務上の受諾不能はすべて 404 (403 を返さない = 存在を教えない)。
        // back() も flash も出さない (文脈依存の戻り先そのものが手掛かりになるため)。
        abort_if($organization === null, 404);

        // 現在組織は切り替えない契約のため、参加先の画面ではなく dashboard へ着地させる
        // (既存 invitations.accept.store の成功応答と同形。intended は使わない = 禁止事項 7)。
        return redirect()->route('dashboard')
            ->with('success', "「{$organization->name}」に参加しました");
    }
}
```

**gate 登録**（対応が要る gate は 6 本。うち inventory への明示登録は 5 本）:

| # | gate | 登録内容 |
|---|------|---------|
| 1 | `tests/Support/Routing/NestedRouteDefenseInventory.php` | `'invitations.accept-in-app' => ['invitation' => $manual],` を「個人スコープ」ブロックへ追加 |
| 2 | `app/Http/Routing/RouteBindingTypes.php` | `MANUALLY_RESOLVED` に `'invitation' => ['routes' => ['invitations.accept-in-app'], 'reason' => '...']` を追加（`{invitation}` は既に `BIGINT` 登録済みなので pattern は継続適用される） |
| 3 | `tests/Architecture/ControllerAuthorizationGateTest.php` | `'invitations.accept-in-app' => [$selfScoped, '...']`（理由は下記） |
| 4 | `tests/Architecture/MembershipWriteLockInventoryTest.php` | `delegatedToLocked` に `acceptPendingInvitation`、`exempt` に `pendingInvitationsFor` / `pendingInvitationCountFor` |
| 5 | `tests/Architecture/RateLimiterKeyConventionTest.php` | limiter inventory に `invitation-accept-in-app` + 認証済み / guest の評価シナリオ |
| 6 | `tests/Architecture/ThrottleCoverageInventoryTest.php` | **登録不要**（throttle を 1 本持つため母集団の要求を満たす。exemption cap=25 は変えない） |

`RouteBindingTypes::MANUALLY_RESOLVED` の理由文:

```php
        // AcceptInvitationInAppController は $user 宛の有効 pending 集合 (scopeActivePendingForEmail)
        // から解決する。implicit binding のままだと「不在 id = binding 404 / 実在の他人宛 =
        // 後段短絡」に分岐し 1 bit の存在オラクルになる。
        'invitation' => [
            'routes' => ['invitations.accept-in-app'],
            'reason' => '存在秘匿のため controller が認証ユーザー宛の有効 pending 集合から解決する'
                .' (binding 段で解決しないことが不在 id と実在の他人宛招待を同一の 404 にする根拠)',
        ],
```

`ControllerAuthorizationGateTest` の exemption 理由文（30 字以上）:

```php
        'invitations.accept-in-app' => [$selfScoped,
            '対象は認証ユーザー自身宛の招待のみ。acceptPendingInvitation が '
            .'scopeActivePendingForEmail($user->email) の集合からしか解決せず、宛先不一致・不在・'
            .'期限切れ・取消済・削除済み組織宛はすべて 404 に畳まれる。受諾前の user は対象組織の'
            .'非メンバーであり組織 Policy は構造的に必ず拒否になるうえ、403 を返すこと自体が'
            .'招待の存在を教える口になるため Gate を置かない (層 2 の 404 が層 3 より前という不変条件)。'],
```

`MembershipWriteLockInventoryTest` の分類（`revokeInvitation` の exempt 理由も更新）:

```php
    $delegatedToLocked = ['acceptInvitation', 'acceptInvitationIfValid', 'acceptPendingInvitation'];
    $exempt = [
        'inviteMember',
        // 招待の論理失効のみ (membership/role 不変)。**受諾との競合の最終権威は
        // joinOrganization が取る招待行の lockForUpdate 側にあり**、取り消しの UPDATE も
        // 同じ行を取るため直列化される (ここで membership ロックを取る必要はない)
        'revokeInvitation',
        // 受信者視点の read-only (表示・件数)。membership/role を変えない
        'pendingInvitationsFor',
        'pendingInvitationCountFor',
        // ...既存...
    ];
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`RedirectResponse` / `Limit`）
- [x] null 安全（`Assert::isInstanceOf($user, User::class)` で union を narrow、`abort_if` の後は非 null）
- [x] DTO を返している（本 route は redirect のみ。`response()->json()` を使わない）
- [x] Generics の型パラメータが正しい（該当なし）
- `abort_if($organization === null, 404)` の後で PHPStan が `$organization` を非 null に narrow できない場合は
  `if ($organization === null) { abort(404); }` の形に書く（`abort()` は `never` 戻り）

### テスト計画

`tests/Feature/Invitations/AcceptInvitationInAppTest.php`（新規。**存在秘匿の網羅**）

- [ ] `自分宛の有効な招待を受諾できる` — 302 → `dashboard`、`success` flash に組織名、
      `organization_user` に行、`accepted_at` が非 null、Laratrust ロールが付与されている
- [ ] `受諾しても現在組織は切り替わらない` — `current_organization_id` が不変
- [ ] `他人宛の実在する招待は 404 (403 ではない)` — `assertNotFound()` かつ
      `assertStatus(403)` にならないこと（403 でないことを明示的に検証する）
- [ ] `不在 id は 404`
- [ ] `期限切れは 404`（`expired()` state）
- [ ] `取消済みは 404`（`revoked()` state）
- [ ] `受諾済みは 404`（`accepted()` state）
- [ ] `削除済み (soft-deleted) 組織宛は 404`
- [ ] `受諾直後の再 POST は 404` — 1 回目 302、2 回目 404（冪等 200 にしない = 秘匿）
- [ ] `既にメンバーの user 宛の招待は冪等に成功する` — `organization_user` が重複せず
      `accepted_at` が立ち success flash（`insertOrIgnore` の 0 行分岐）
- [ ] `未 verified は verified middleware で遮断され、実在 id と不在 id で応答が同一` —
      両方 302 かつ同一 location（**存在オラクルが無いことの検証**）
- [ ] `guest は login へ 302`
- [ ] `非数値 id / 19 桁 id は 404 (500 にならない)` — `BIGINT_PATTERN` の効きを確認
- [ ] `throttle: 不在 id へ 10 回 POST はすべて 404、11 回目が 429`
- [ ] `throttle: 有効な招待への正常受諾 1 回は 429 にならない`
- [ ] テストデータは `OrganizationInvitation::factory()` / `User::factory()` 経由
- [ ] 個別の `DatabaseTransactions` を使っていない

Architecture 側は既存 gate が自動で走る（`NestedRouteIdorDefenseTest` /
`TenantBoundaryOrderingTest` 検査 3a / `ControllerAuthorizationGateTest` /
`ThrottleCoverageInventoryTest` / `RateLimiterKeyConventionTest` /
`RouteBindingTypeConstraintInventoryTest`）。**登録を忘れると赤くなる**のが正しい状態。

### リスク

- `verified` を必須にしたため、**招待された直後にまだメール確認していないユーザーは
  アプリ内受諾を使えない**（メール token 経路は従来どおり使える）。これは受諾根拠の定義から来る
  意図的な制約で、非対称の理由を `docs/architecture.md` に明記する（施策 9）。
- named limiter を 1 本増やす。`RateLimiterKeyConventionTest` は全 limiter を実評価するため、
  キー組み立てに `$request->user()` を使う点（guest シナリオでも例外を投げない）を確認する。
- route 追加により `ThrottleCoverageInventoryTest` の母集団が 1 増える（floor=60 に対し余裕あり）。

---

## 施策 5: 発見面 → 受諾の導線

### 変更箇所

- `app/Http/Controllers/NotificationController.php`（`index()` に prop 追加 / `open()` の
  `InvitationReceived` 分岐 L85-88 付近）
- `resources/js/pages/Notifications/Index.svelte`
- `resources/js/components/features/notifications/NotificationListItem.svelte`（本文の文言）
- `resources/js/components/features/invitations/PendingInvitationList.svelte`（新規）
- `resources/js/types/invitation.ts`（新規）

### 波及変更

- TypeScript 型定義: `resources/js/types/invitation.ts` の `PendingInvitation`
  （PHP の `PendingInvitationForUserDto::toArray()` と対で保守。docblock で相互参照する）
- API Resource/DTO: `PendingInvitationForUserDto`（施策 2）
- テストファイル: `tests/Feature/Notifications/NotificationCenterTest.php`（既存の
  `InvitationReceived` open 分岐アサーションが割れる）/
  `tests/js/components/features/invitations/PendingInvitationList.test.ts`（新規）

### 現行コード

```php
    /** 通知一覧 (全 org 横断 = 自分宛のみで構造的に閉じる) */
    public function index(Request $request): Response
    {
        $user = $this->authedUser($request);
        $paginator = $this->notifications->paginateFor($user);
        // ...
        return Inertia::render('Notifications/Index', [
            'notifications' => $items,
            'unreadCount' => $this->notifications->unreadCountFor($user),
            'meta' => [...],
        ]);
    }
```

```php
            $item->type === NotificationType::InvitationReceived => redirect()
                ->route('notifications.index', [], 303)
                ->with('info', '招待はメールの受諾リンクから参加してください。'),
```

```svelte
        if (invitationPayload) {
            return "メールの受諾リンクから参加してください";
        }
```

### 変更後コード

```php
class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationCenterService $notifications,
        private readonly OrganizationMembershipService $membership,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->authedUser($request);
        // ...
        return Inertia::render('Notifications/Index', [
            'notifications' => $items,
            'unreadCount' => $this->notifications->unreadCountFor($user),
            // 自分宛の受諾可能な招待 (受信者視点 DTO)。共有 prop の件数・受諾の解決と
            // **同一 scope** から算出する (裁定 AG-113 必須要素 (b))
            'pendingInvitations' => array_map(
                fn (PendingInvitationForUserDto $dto): array => $dto->toArray(),
                $this->membership->pendingInvitationsFor($user),
            ),
            'meta' => [...],
        ]);
    }
```

```php
            // 招待通知: 受諾可能な一覧が出る通知センターへ戻す。
            // ★通知 payload は招待 id を持たないため「この招待」を特定できない。
            //   したがって flash は**集合表現**にする (件数 0 のときだけ説明を出す)。
            //   件数は受諾の解決と同一 scope から算出する。
            $item->type === NotificationType::InvitationReceived => $this->membership->pendingInvitationCountFor($user) > 0
                ? redirect()->route('notifications.index', [], 303)
                : redirect()->route('notifications.index', [], 303)
                    ->with('info', '現在有効な招待はありません (取り消し・期限切れ・参加済みの可能性があります)。'),
```

```svelte
        if (invitationPayload) {
            return "クリックすると、届いている招待から参加できます";
        }
```

`resources/js/types/invitation.ts`（新規）:

```ts
/**
 * 受信者視点の保留中招待。
 * PHP 側 App\DataTransferObjects\Invitations\PendingInvitationForUserDto::toArray() と対で保守する。
 * 管理者視点の InvitationRow (types/admin.ts) とは別契約 (統合しない)。
 */
export interface PendingInvitation {
    id: number;
    organizationName: string;
    roleLabel: string;
    /** Y-m-d */
    expiresAt: string;
}

/** HandleInertiaRequests が共有する invitations props */
export interface InvitationSharedProps {
    pendingCount: number;
}
```

`resources/js/components/features/invitations/PendingInvitationList.svelte`（新規、要点のみ）:

```svelte
<script lang="ts">
    import { router } from "@inertiajs/svelte";
    import { Mail } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import type { PendingInvitation } from "@/types/invitation";

    /**
     * 自分宛の受諾可能な招待の一覧 (受諾ボタン付き)。
     * 受諾 = POST /invitations/{id}/accept-in-app (サーバが 302 で dashboard へ着地させる)。
     * ★ボタンを disabled にしない (禁止事項 8)。二重送信は in-flight 中の再入を
     *   ハンドラ側で無視して抑止する (disabled 属性は出さない)。
     */
    interface Props {
        invitations: PendingInvitation[];
    }

    let { invitations }: Props = $props();

    let acceptingId = $state<number | null>(null);

    function accept(invitation: PendingInvitation): void {
        if (acceptingId !== null) return; // in-flight 中の再入を無視 (disabled ではない)
        acceptingId = invitation.id;
        router.post(
            `/invitations/${invitation.id}/accept-in-app`,
            {},
            { onFinish: () => { acceptingId = null; } },
        );
    }
</script>

{#if invitations.length > 0}
    <Card padding="lg" data-testid="pending-invitation-list">
        <h2 class="text-h3">届いている招待</h2>
        {#each invitations as invitation (invitation.id)}
            <div class="..." data-testid={`pending-invitation-${invitation.id}`}>
                <Mail class="size-4" aria-hidden="true" />
                <p class="text-body">{invitation.organizationName}</p>
                <Badge tone="neutral" size="sm">{invitation.roleLabel}</Badge>
                <span class="text-caption text-text-secondary">期限 {invitation.expiresAt}</span>
                <Button
                    onclick={() => accept(invitation)}
                    loading={acceptingId === invitation.id}
                    testId={`accept-invitation-${invitation.id}`}
                >
                    参加する
                </Button>
            </div>
        {/each}
    </Card>
{/if}
```

`Notifications/Index.svelte` は Props に `pendingInvitations: PendingInvitation[]` を足し、
`PageContent` の先頭で `<PendingInvitationList invitations={pendingInvitations} />` を描画する。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`Response` / `RedirectResponse`）
- [x] null 安全（`authedUser()` が `User` を保証。`pendingInvitationCountFor` は `?User` を受ける）
- [x] DTO を返している（`PendingInvitationForUserDto::toArray()` の shape で Inertia props へ）
- [x] Generics の型パラメータが正しい（`array_map` のコールバック引数型を明示）
- `NotificationController` のコンストラクタに Service を 1 つ追加する（既存の DI 流儀のまま）

### テスト計画

`tests/Feature/Notifications/NotificationCenterTest.php`（既存の更新）

- [ ] 既存 `InvitationReceived の open は info flash で一覧へ戻す` を書き換え:
      **有効な招待があるとき** → 303 `notifications.index` かつ `info` flash **なし**
- [ ] 新規: **有効な招待が無いとき** → 303 かつ
      `info`「現在有効な招待はありません...」（取消済み招待を作って検証）
- [ ] 新規: `通知一覧の props に pendingInvitations が載る` — `assertInertia` で
      `pendingInvitations.0.organizationName` / `roleLabel` / `expiresAt` を検証し、
      `pendingInvitations.0` に `email` キーが `missing` であること

`tests/js/components/features/invitations/PendingInvitationList.test.ts`（新規）

- [ ] `招待 0 件では何も描画しない`
- [ ] `組織名・ロール・期限・参加ボタンを描画する`
- [ ] `参加ボタンは disabled 属性を持たない`（禁止事項 8 の回帰封じ）
- [ ] `参加ボタン押下で POST /invitations/{id}/accept-in-app を送る`（`router.post` を vi.mock）
- [ ] `in-flight 中の 2 回目の押下は送信しない`（`router.post` の呼び出し回数が 1）

`tests/js/components/features/NotificationListItem.test.ts`（既存の更新）

- [ ] 招待通知の本文が「メールの受諾リンクから参加してください」でなくなったことを反映

### リスク

- `NotificationController::open()` に `pendingInvitationCountFor` のクエリが 1 本増える。
  open は低頻度の POST なので実害はない。
- 既存 `NotificationCenterTest` の招待分岐アサーションが割れる（想定済み。上で更新する）。
- `Notifications/Index` に招待セクションを置くのは「通知 = 気づき」「一覧 = 行動」を
  1 画面に収める判断。新しい GET route を作らないことで `DocumentTitleCoverageTest` /
  S3 throttle の追加対応を避けている（今必要なものだけ作る）。

---

## 施策 6: 横断の気づき（共有 prop + notice）

### 変更箇所

- `app/Http/Middleware/HandleInertiaRequests.php`（`share()` に `invitations` を追加）
- `resources/js/lib/shared-props.ts`（`SharedProps` に `invitations`）
- `resources/js/components/molecules/PendingInvitationsNotice.svelte`（新規）
- `resources/js/components/templates/AppLayout.svelte`（notice の設置）

### 波及変更

- TypeScript 型定義: `resources/js/types/invitation.ts` の `InvitationSharedProps`（施策 5 で定義）
- API Resource/DTO: なし（scalar 1 つ）
- テストファイル: `tests/Feature/Invitations/PendingInvitationSharedPropTest.php`（新規）/
  `tests/js/components/molecules/PendingInvitationsNotice.test.ts`（新規）

### 現行コード

```php
            // 通知センターの未読数 (全 org 横断・自分宛のみ)。closure = Inertia partial reload で
            // 省略可能 (将来の router.reload({ only: ['notifications'] }) ポーリング拡張にも使える)
            'notifications' => [
                'unreadCount' => fn (): int => $user === null ? 0 : $user->unreadNotifications()->count(),
            ],
```

### 変更後コード

```php
            'notifications' => [
                'unreadCount' => fn (): int => $user === null ? 0 : $user->unreadNotifications()->count(),
            ],
            // 自分宛の受諾可能な招待の件数 (全画面横断の気づき。裁定 AG-113 必須要素 (b)(c))。
            // ★件数は受諾の解決・一覧と**同一 scope** から算出する
            //   (ずれると「件数は出るのに受諾できない」が起きる)。
            // ★未ログイン・未 verified・email 空は pendingInvitationCountFor が
            //   DB を一切引かずに 0 を返す (全リクエストで評価されるため実効的な負荷契約)。
            'invitations' => [
                'pendingCount' => fn (): int => app(OrganizationMembershipService::class)
                    ->pendingInvitationCountFor($user),
            ],
```

> `app()` 解決にするのは、`HandleInertiaRequests` のコンストラクタ注入を増やさないため
> （既存 `contact` prop が `app(ContactUrl::class)` を closure 内で解決している同じ流儀に合わせる）。

`resources/js/components/molecules/PendingInvitationsNotice.svelte`（新規）:

```svelte
<script lang="ts">
    import { Link } from "@inertiajs/svelte";
    import { Mail } from "@lucide/svelte";

    /**
     * 自分宛の保留中招待の件数だけを出す誘導専用 notice (受諾 UI は持たない)。
     * 受諾は /notifications の「届いている招待」から行う。
     * 件数は shared props invitations.pendingCount (親が渡す)。
     */
    interface Props {
        pendingCount: number;
        testId?: string;
    }

    let { pendingCount, testId = "pending-invitations-notice" }: Props = $props();
</script>

{#if pendingCount > 0}
    <Link href="/notifications" class="..." data-testid={testId}>
        <Mail class="size-4" aria-hidden="true" />
        あなた宛の招待が {pendingCount} 件あります
    </Link>
{/if}
```

`AppLayout.svelte` は `const pendingInvitationCount = $derived(shared.invitations?.pendingCount ?? 0);`
を足し、既存 `NotificationBell` と同じ header 領域の直下（main の先頭）に
`<PendingInvitationsNotice pendingCount={pendingInvitationCount} />` を置く。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（closure に `: int`）
- [x] null 安全（`$user` は `?User` に narrow 済み。`pendingInvitationCountFor(?User)` が受ける）
- [x] DTO を返している（scalar 1 つのため該当なし。配列生値の露出を増やさない）
- [x] Generics の型パラメータが正しい（該当なし）

### テスト計画

`tests/Feature/Invitations/PendingInvitationSharedPropTest.php`（新規）

- [ ] `未ログインのページでは pendingCount が 0 で DB を引かない` — guest でアクセス可能な
      Inertia 画面（`login`）で `assertInertia(fn ($page) => $page->where('invitations.pendingCount', 0))`
      かつ `DB::listen` で `organization_invitations` を含む SQL が 0 件
- [ ] `未 verified では 0 で DB を引かない`
- [ ] `verified かつ自分宛 active 招待 2 件で pendingCount = 2`（**負のコントロール**:
      guard が常に 0 を返す退行を検出する）
- [ ] `他人宛の招待は数えない`
- [ ] `件数と一覧が一致する` — 同一ユーザーで `/notifications` の `pendingInvitations` の件数と
      shared prop の `pendingCount` が一致（**scope 再利用の behavioral proof**）

`tests/js/components/molecules/PendingInvitationsNotice.test.ts`（新規）

- [ ] `pendingCount=0 では描画しない`
- [ ] `pendingCount=3 で件数と /notifications への link を描画する`
- [ ] `disabled 属性を持たない`

### リスク

- **全 Inertia レスポンスでクエリが 1 本増える**（verified かつ email 非空のユーザーのみ）。
  `organization_invitations` は組織あたり数件規模、`whereBlind` は
  `blind_indexes` の index を使う完全一致で、`whereHas('organization')` も PK 参照。
  実測が必要なほどのコストではないが、**closure 共有 prop なので Inertia の partial reload
  （`only:` 指定）では評価されない**ため、実効頻度はフルページ遷移時のみ。
- 未ログイン画面にも `invitations` prop が出る（値は常に 0）。情報漏洩ではない。

---

## 施策 7: 役割付き招待（`project_role`）の撤去

> 裁定 AG-079（オーナー判断: Default Project という概念自体が不要）。
> 本作業単位では**招待が `project_role` を持つのをやめる**ところまでを扱う。
> `DefaultProjectResolver` / `applyConsoleRole` の pivot 経路 / `MemberRoleState` の
> Editor・Shooter は dashboard・撮影 PWA・ユーザー管理画面が現に使っており、撤去はスコープ外。

### 変更箇所

- `database/migrations/2026_08_07_210000_drop_project_role_from_organization_invitations_table.php`（新規）
- `app/Models/OrganizationInvitation.php`（`casts()` / class docblock の `@property-read`）
- `app/Services/Organization/OrganizationMembershipService.php`（`inviteMember` / `joinOrganization`）
- `app/Http/Requests/Organizations/StoreOrganizationInvitationRequest.php`
- `app/DataTransferObjects/Admin/InvitationRowData.php`
- `database/factories/OrganizationInvitationFactory.php`
- `resources/js/pages/Admin/Users.svelte` / `resources/js/types/admin.ts`

### 波及変更

- TypeScript 型定義: `types/admin.ts` の `InvitationRow.roleState`（`MemberRoleState`）→
  `role` / `roleLabel`。`Admin/Users.svelte` の招待フォームの選択肢
- API Resource/DTO: `InvitationRowData`（管理者視点）
- テストファイル: `tests/Feature/Organization/InvitationTest.php`（L494-650 の
  「3 値ロールコマンド招待」ブロック）/ `tests/Feature/Admin/UserManagementPageTest.php`（L19, L140）/
  `tests/Feature/Notifications/InvitationNotificationTest.php`（`AdminConsoleRole::Admin` の呼び出し）/
  `tests/js/pages/Admin/Users.test.ts`（存在すれば）

### 現行コード

```php
// Model
    protected function casts(): array
    {
        return [
            // 受諾時に Default Project へ付与する pivot ロール (null = org 参加のみ)。
            'project_role' => ProjectRole::class,
            'expires_at' => 'datetime',
            // ...
        ];
    }
```

```php
// Service::inviteMember
    public function inviteMember(Organization $organization, User $invitedBy, string $email, AdminConsoleRole $role): OrganizationInvitation
    {
        // ...
        if ($role->projectRole() !== null && $this->defaultProjects->resolve($organization) === null) {
            throw ValidationException::withMessages([
                'role' => ['編集者・撮影者を招待するには、先にプロジェクトを作成してください。'],
            ]);
        }
        // ...
        $invitation->forceFill([
            'role' => $role->organizationRole()->value,
            'project_role' => $role->projectRole()?->value,
            // ...
        ]);
```

```php
// FormRequest
            'role' => ['required', 'string', Rule::enum(AdminConsoleRole::class)],
```

```php
// InvitationRowData
        $state = MemberRoleState::derive(
            OrganizationRole::from($invitation->role),
            $invitation->project_role,
        );
```

### 変更後コード

**migration**（`add_project_role_...` の `down()` と同内容を `up()` に置く）:

```php
return new class extends Migration
{
    /**
     * 役割付き招待の撤去 (裁定 AG-079。Default Project という概念自体が不要という
     * オーナー判断の帰結)。招待は「組織に入れる」ことだけを意味するようになり、
     * 編集者 / 撮影者の割当は参加後に管理画面のロール割当コマンドで行う。
     */
    public function up(): void
    {
        DB::statement('alter table organization_invitations drop constraint if exists organization_invitations_project_role_check');
        Schema::table('organization_invitations', function (Blueprint $table): void {
            $table->dropColumn('project_role');
        });
    }

    public function down(): void
    {
        Schema::table('organization_invitations', function (Blueprint $table): void {
            $table->string('project_role')->nullable()->after('role');
        });
        DB::statement(
            'alter table organization_invitations add constraint organization_invitations_project_role_check '
            ."check (project_role is null or project_role in ('project_admin', 'project_member'))",
        );
    }
};
```

**Model**: `casts()` から `'project_role' => ProjectRole::class` を削除、
class docblock の `@property-read ProjectRole|null $project_role` を削除、
`use App\Enums\ProjectRole;` を削除。

**Service**:

```php
    /**
     * メンバー招待。招待レコード生成 + 受諾 URL 付きメール送信。
     * ロールは**組織ロール 2 値 (管理者 / メンバー)**。Owner は招待で付与できない
     * (Owner 昇格は transferOwnership のみという不変条件の型表現)。
     * 編集者 / 撮影者 (Default Project の pivot ロール) は参加後に applyConsoleRole で割り当てる
     * (裁定 AG-079 で役割付き招待を撤去したため)。
     *
     * @throws ValidationException 既存メンバー / 有効な既存招待 (中立メッセージ)
     */
    public function inviteMember(Organization $organization, User $invitedBy, string $email, OrganizationRole $role): OrganizationInvitation
    {
        // Owner は FormRequest の Rule::enum(...)->except() で構造的に弾かれるが、
        // Service を直接呼ぶ経路 (テスト・将来のバッチ) でも不変条件を守る
        Assert::notSame($role, OrganizationRole::Owner, 'Owner は招待で付与できない');

        if ($this->emailBelongsToMember($organization, $email) || $this->hasPendingInvitation($organization, $email)) {
            throw ValidationException::withMessages([
                'email' => ['このメールアドレスには招待を送信できません。'],
            ]);
        }

        $plainToken = OrganizationInvitation::generateToken();

        $invitation = new OrganizationInvitation(['email' => $email]);
        $invitation->organization()->associate($organization);
        $invitation->invitedBy()->associate($invitedBy);
        // role / token_hash / expires_at は明示代入 (mass-assignment させない)
        $invitation->forceFill([
            'role' => $role->value,
            'token_hash' => OrganizationInvitation::hashToken($plainToken),
            'expires_at' => now()->addDays(self::EXPIRES_DAYS),
        ]);
        $invitation->save();

        // ... 以降のメール送信 / 通知発火は現行のまま ...
    }
```

`joinOrganization()` からは `$projectRole` の分岐ごと削除（施策 3 の変更後コードに反映済み）。
`DefaultProjectResolver` の DI は `applyConsoleRole` / `changeRole` が使い続けるため残す。

**FormRequest**:

```php
            // Owner は招待で付与できない (Owner 昇格は transferOwnership のみ)
            'role' => ['required', 'string', Rule::enum(OrganizationRole::class)->except([OrganizationRole::Owner])],
```

型付きアクセサも `role(): OrganizationRole` へ変更（`$this->enum('role', OrganizationRole::class)`）。
`messages()` の旧契約値に関するメッセージはそのまま残す（デプロイ跨ぎタブの回復導線）。

**InvitationRowData**:

```php
final readonly class InvitationRowData
{
    public function __construct(
        public int $id,
        public string $email,
        public string $role,      // OrganizationRole value
        public string $roleLabel, // OrganizationRole label
        public string $expiresAt, // Y-m-d
    ) {}

    public static function fromInvitation(OrganizationInvitation $invitation): self
    {
        // 招待は org ロールだけを持つ (役割付き招待は AG-079 で撤去)。
        // MemberRoleState (受諾後の 5 値表示状態) は使わない — 招待中の行を「未割当」と
        // 表示するのは意味的に誤り (割当漏れではなく、まだ参加していないだけ)。
        $role = OrganizationRole::from($invitation->role);
        // ...
        return new self(
            id: $invitation->id,
            email: $invitation->email,
            role: $role->value,
            roleLabel: $role->label(),
            expiresAt: $expiresAt->toDateString(),
        );
    }
}
```

**Factory**: `editorInvitation()` / `shooterInvitation()` を削除、`use App\Enums\ProjectRole;` を削除。
`asAdmin()` は維持。

**front**: `Admin/Users.svelte` に招待専用の選択肢を持たせる（メンバーのロール変更用
`ROLE_OPTIONS` は 3 値のまま）:

```ts
    /** 招待のロール = org ロール 2 値 (編集者/撮影者は参加後に割り当てる) */
    const INVITE_ROLE_OPTIONS: { value: string; label: string }[] = [
        { value: "organization_admin", label: "管理者" },
        { value: "organization_member", label: "メンバー" },
    ];
    const inviteForm = useForm({ email: "", role: "organization_member" });
```

招待一覧の行は `{invitation.roleLabel} ・ 期限 {invitation.expiresAt}` のまま（型だけ変わる）。
`PageHeader` の description と `hasDefaultProject` の注意書きは
「編集者・撮影者は**参加後に**割り当てます」の趣旨へ更新する（招待時の前提ではなくなるため）。
`types/admin.ts` の `InvitationRow` は `roleState: MemberRoleState` → `role: string; roleLabel: string`。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`OrganizationInvitation` / `self` / `OrganizationRole`）
- [x] null 安全（`Assert::notSame` / `Assert::isInstanceOf`。`project_role` の nullable 分岐が消える）
- [x] DTO を返している（`InvitationRowData`）
- [x] Generics の型パラメータが正しい（該当なし）
- `Rule::enum()->except()` は Laravel 11+ の API。`Illuminate\Validation\Rules\Enum` を返すため
  `array<string, list<mixed>>` の型に収まる

### テスト計画

`tests/Feature/Organization/InvitationTest.php`（既存の**削除と置換**）

- [ ] 削除: `editor 招待の受諾で Default Project へ project_admin pivot が attach される`
- [ ] 削除: `shooter 招待の受諾で Default Project へ project_member pivot が attach される`
- [ ] 削除: `editor/shooter 招待の送信は Default Project 不在なら error bag (role)`
- [ ] 削除: `受諾時に project が消えていた場合は org 参加のみ = 未割当に落ちる`
- [ ] 削除: `旧招待互換: project_role = null の既存行の受諾は従来どおり org 参加のみ`
      （`project_role` 自体が無くなるため「旧招待互換」という概念が消える）
- [ ] 削除: `register 経路でも project_role 付き招待の受諾で pivot が attach される`
- [ ] 追加: `招待の受諾は org 参加のみで Default Project の pivot を作らない` —
      project がある組織へ member 招待 → 受諾 → `$project->memberRole($invitee)` が null
- [ ] 追加: `招待は Default Project が無くても送信できる` — project 0 件の組織で 302 + success
      （撤去で消えた前提検査の回帰封じ）
- [ ] 追加: `role に organization_owner を送ると 422` — Owner を招待で付与できない
- [ ] 更新: `inviteAndCaptureToken()` helper の第 4 引数を `OrganizationRole` へ
- [ ] 既存の admin 招待 / 受諾 / 失効 / 重複中立メッセージ系テストは**挙動不変**であることを確認

`tests/Feature/Admin/UserManagementPageTest.php`（既存の更新）

- [ ] L19 の `editorInvitation()` を `->create(['role' => OrganizationRole::Member->value])` へ
- [ ] `invitations.0.roleState` のアサーションを `invitations.0.role` / `roleLabel` へ
- [ ] L140 の「旧招待 (project_role なし) は未割当語彙で表示される」を
      「招待は org ロールで表示される (メンバー)」へ置換

`tests/Feature/Notifications/InvitationNotificationTest.php`（既存の更新）

- [ ] `AdminConsoleRole::Admin` → `OrganizationRole::Admin`

`tests/Unit/Enums/MemberRoleStateTest.php`

- [ ] **変更しない**（`AdminConsoleRole` はメンバーのロール変更コマンドとして存続する）

DB 側:

- [ ] `php artisan migrate` が通ること（dev DB への破壊操作はしない。migrate のみ）
- [ ] `down()` で列と check 制約が復元できること（rollback 手順の確認）

### リスク

- **仕様変更（受け入れる後退）**: 招待時に「編集者 / 撮影者」を選べなくなる。
  参加後にユーザー管理画面で「未割当」として可視化され、既存のロール割当コマンドで
  編集者 / 撮影者へ遷移させる 2 段の運用になる。3 値のまま「選べるが効かない」UI を残す方が
  有害（思考原則 3）。この変更は `docs/template-divergence.md` D8 に記録する。
- **デプロイ跨ぎタブ**: 旧 UI が `role=editor` を POST してくると 422 になる。
  `StoreOrganizationInvitationRequest::messages()` の既存メッセージ
  「ロールの指定が不正です。画面を再読み込みしてやり直してください。」がそのまま回復導線になる。
- **列 drop は不可逆**（`down()` で列は戻るが値は戻らない）。
  既存の pending 招待の `project_role` 値は失われる。値を持つ pending 招待は
  「参加後に管理画面で割り当てる」運用に倒れるだけで、参加自体は成功する。

---

## 施策 8: 目録型 gate（受信者視点解決の deny-by-default）

### 変更箇所

- `app/Enums/Security/InvitationResolutionScope.php`（新規）
- `tests/Architecture/InvitationResolutionInventoryTest.php`（新規）

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 本施策が新設するもののみ

### 現行コード

（新規。見本は `tests/Architecture/ThrottleCoverageInventoryTest.php` /
`tests/Architecture/QueuedJobLeaseInventoryTest.php` /
`tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php`）

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * OrganizationInvitation を解決する経路の分類（存在秘匿の視点別）。
 *
 * 招待は「誰の視点から引くか」で開示していい情報が変わる。視点を混ぜると
 * 受信者向けの経路が管理者向けの絞り込みを使ってしまい、他人宛の招待に到達できる
 * = 存在オラクルになる。**例外機構は設けない**（分類外は fail）。
 */
enum InvitationResolutionScope: string
{
    /**
     * 受信者視点。認証ユーザー宛の有効 pending 集合
     * (OrganizationInvitation::scopeActivePendingForEmail) からのみ解決する。
     * 不成立はすべて 0 件 = 呼び出し側は一律 404 に畳める。
     */
    case RecipientScopedPendingSet = 'recipient_scoped_pending_set';

    /** 平文 token の sha256 照合で解決する（署名なし token URL 経路）。 */
    case TokenHashLookup = 'token_hash_lookup';

    /** 管理者視点。$organization->invitations() の relation 経由でのみ解決する。 */
    case OrganizationScoped = 'organization_scoped';

    /** モデル自身が持つ解決口 / scope の定義そのもの。 */
    case ModelInternal = 'model_internal';
}
```

```php
<?php

declare(strict_types=1);

use App\Enums\Security\InvitationResolutionScope;

/*
 * OrganizationInvitation のクエリ起点の deny-by-default 目録。
 *
 * 守る不変条件（裁定 AG-113 の必須要素 (b)）:
 *   「**受信者視点の解決・一覧・件数は、必ず scopeActivePendingForEmail の 1 本を再利用する**」
 * これがずれると「件数は出るのに受諾できない」が起き、さらに悪いことに、
 * 受信者向け経路が別の絞り込みで招待に到達すると**他人宛の招待に届く存在オラクル**になる。
 *
 * 本テストは app/ 配下で OrganizationInvitation のクエリを起点する箇所を機械抽出し、
 * 4 分類のいずれかへの登録を要求する（未登録は fail / 実在しない登録も fail）。
 * さらに RecipientScopedPendingSet に分類した箇所は、**本文に activePendingForEmail(
 * が現れること**を要求する（分類したのに別の絞り込みを書く退行の検出）。
 *
 * ★空振り対策を 3 つ持つ:
 *   (1) 母集団 floor — 抽出が 0 件 / 縮小したら fail（セレクタの空振り検出）
 *   (2) RecipientScopedPendingSet の exact-fit cap — 現在値ちょうど。
 *       受信者視点の解決口を増やす差分は必ずこの数値を変える形で現れ、再レビューを強制する
 *   (3) 負のコントロール — 分類済み各 case が 1 件以上存在すること（分類の死に枝を作らない）
 */
```

検査の骨子（`ThrottleCoverageInventoryTest` の構成に倣う）:

```php
/** 目録キー = "{app/ からの相対パス}#{メソッド名}"。 */
function invitationResolutionInventory(): array
{
    $recipient = InvitationResolutionScope::RecipientScopedPendingSet;
    $token = InvitationResolutionScope::TokenHashLookup;
    $orgScoped = InvitationResolutionScope::OrganizationScoped;
    $model = InvitationResolutionScope::ModelInternal;

    return [
        'Models/OrganizationInvitation.php#findActiveByPlainToken' => [$model,
            '平文 token の sha256 照合による active 解決の単一口。email での絞り込みを持たない'
            .'（列挙面を広げない）。MatchesInvitationEmail / acceptInvitationIfValid / '
            .'register prefill が共有する。'],
        'Models/OrganizationInvitation.php#scopeActivePendingForEmail' => [$model,
            '受信者視点の絞り込みの定義そのもの。active + blind index 完全一致 + 組織実在の'
            .'3 条件がすべて 0 件へ collapse することが、一律 404 に畳める根拠。'],
        'Services/Organization/OrganizationMembershipService.php#pendingInvitationsQuery' => [$recipient,
            '受信者視点の唯一のクエリ起点。未ログイン・未 verified・email 空は null を返し'
            .'DB を引かない。一覧・件数・受諾解決がすべてここを通る。'],
        'Services/Organization/OrganizationMembershipService.php#acceptInvitation' => [$token,
            'POST token 受諾。token_hash 照合で解決し、失効/期限/受諾済みを個別メッセージに'
            .'出し分ける（token 保持者向けの既存契約）。'],
        'Services/Organization/OrganizationMembershipService.php#hasPendingInvitation' => [$orgScoped,
            '管理者視点。$organization->invitations() 経由で同一組織内の重複招待だけを見る'
            .'（中立メッセージのための存在判定であり受信者視点ではない）。'],
        // ... 実装時に抽出結果へ合わせて確定する ...
    ];
}
```

- **母集団の抽出**: `app/` 配下の PHP を走査し、
  `OrganizationInvitation::query(` / `OrganizationInvitation::where` /
  `OrganizationInvitation::find` / `->invitations()` / `activePendingForEmail(` の
  いずれかを含む**メソッド**を、`ReflectionClass` の
  `getStartLine()`/`getEndLine()` でメソッド本文へ切り分けて列挙する
  （`MembershipWriteLockInventoryTest` の `bodyOf()` と同じ手法）。
- **stale 検出**: 目録キーのうち抽出結果に現れないものは fail。
- **理由の最低文字数**: 30 文字（`invitationResolutionReasonMinLength()`）。
- **floor**: `invitationResolutionSiteFloor()` = 実測値（実装時に確定。目安 6）。
- **exact-fit cap**: `RecipientScopedPendingSet` の件数は**現在値ちょうど**（= 1）。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（enum のメソッドなし。テストの helper 関数も戻り値型を明示）
- [x] null 安全（`ReflectionMethod::getStartLine()` の `false` を分岐で処理）
- [x] DTO を返している（該当なし）
- [x] Generics の型パラメータが正しい（`array<string, array{InvitationResolutionScope, string}>`）

### テスト計画（gate 自身の検証 = mutation で赤化を確認する手順）

**素の main では常に green の gate であるため、実装時に以下の mutation を順に当てて
赤化を確認し、結果を `devnotes/{dir}/gate-mutation-log.md` に記録してから元へ戻す。**

| # | mutation | 期待する fail |
|---|---|---|
| M1 | `AcceptInvitationInAppController::__invoke` に `OrganizationInvitation::query()->whereKey($invitation)->first();` を一時的に足す | 「未登録のクエリ起点」で fail（deny-by-default が効いている） |
| M2 | `pendingInvitationsQuery()` の本文を `activePendingForEmail(...)` から `->active()->whereBlind(...)` の手書きへ置換 | `RecipientScopedPendingSet` の本文検査で fail（**scope 再利用の強制**が効いている） |
| M3 | 目録から `pendingInvitationsQuery` の行を削除 | 未登録 fail |
| M4 | 目録に実在しないキー（`Services/Foo.php#bar`）を足す | stale fail |
| M5 | 理由文を `'短い'` に置換 | 理由 30 字未満で fail |
| M6 | `invitationResolutionSiteFloor()` を実測 +1 に上げる | floor 下回りで fail（**空振り検出が生きている**ことの確認） |
| M7 | `RecipientScopedPendingSet` 分類の 2 件目を目録に足す（実在サイトを作って） | exact-fit cap 超過で fail |

- [ ] gate 追加時点では**まだ本体が無い**ため、まず M1 相当の状態（= 施策 1・3 の実装前）で
      「目録が空でも floor で fail する」ことを確認する（テストファースト）
- [ ] 個別の `DatabaseTransactions` を使わない（Architecture レーンは DB を触らない）

### リスク

- 抽出セレクタが正規表現ベースのため、`OrganizationInvitation` を変数経由で扱う書き方
  （`$model = OrganizationInvitation::class; $model::query()`）は検出できない。
  これは既存の `ModelDirectFetchInvariantTest` / `MembershipWriteLockInventoryTest` と
  同じ限界であり、**保証範囲を誇張しない**（テスト docblock に明記する）。
- 目録が育ちすぎると形骸化する。`RecipientScopedPendingSet` の exact-fit cap がその歯止め。

---

## 施策 9: ドキュメント更新

### 変更箇所

- `docs/architecture.md`（招待セクション）
- `docs/template-divergence.md`（D8）
- `docs/factories.md`（`OrganizationInvitationFactory` の state 一覧）

### 波及変更

- テストファイル: なし（`verification-commands-doc-sync` 等の同期テストは対象外）

### 変更後コード（要点）

`docs/architecture.md` に「招待受諾の 2 経路」節を追加:

- 受諾経路が 2 本あること（署名なし token URL / アプリ内）と**受諾の根拠が違う**こと
- **`verified` の非対称は仕様**であること
  （token 経路は招待直後の未検証ユーザーも受諾できる / アプリ内経路は根拠そのものが
  email 確認済みのため必須）。片方だけ見て「不整合」と直さない
- **email 照合の非対称**（アプリ内経路は blind index の大小文字完全一致 /
  token 経路は token_hash 照合のため大小差の影響を受けない）
- 存在秘匿の畳み方（受信者視点の解決は `scopeActivePendingForEmail` 1 本。
  `InvitationResolutionInventoryTest` が deny-by-default で強制）
- 最終権威の表（組織 soft-delete = organizations 行ロック /
  取消・期限・並行受諾 = 招待行ロック / 並行 join = `insertOrIgnore`）

`docs/template-divergence.md` D8:

- 「招待」行を「org ロールのみ（テンプレートと同じ）」へ戻し、
  **役割付き招待は裁定 AG-079 で撤去済み**と記録（差分そのものを削除せず、
  「一度逸脱し、裁定で戻した」経緯を残す）
- 「揃えている不変条件」から `project_role` の項目を削除
- ロール語彙の行に「招待は org ロール 2 値 / メンバーのロール変更は 3 値コマンド」の
  非対称を明記

`docs/factories.md`: `editorInvitation()` / `shooterInvitation()` の記述を削除。

### テスト計画

- [ ] `composer test` / `pnpm test` が green（doc 同期テストの対象外だが回帰確認）
- [ ] `docs/architecture.md` の追記が既存の目次構造と矛盾しないこと（目視）

### リスク

- なし（ドキュメントのみ）。ただし**書き忘れると次の実装者が非対称を「バグ」と誤認して直す**ため、
  施策 4・7 と同じ PR に必ず含める。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | (1) `app/Models/OrganizationInvitation.php` と `OrganizationMembershipService.php` を**構造的に**変更する（scope 追加 / 公開メソッド 4 本追加 / 共有コアの戻り値型変更 / `project_role` 撤去）。他 TODO が同ファイルに触ると衝突が確実。(2) DB migration（列 drop）を含むため、他の worktree と DB 状態を共有できない。(3) Architecture gate を 6 本触る（うち 5 本は inventory 登録）ため、他施策の route 追加と同じ inventory ファイルで機械的に衝突する。(4) front も `AppLayout` / `shared-props.ts` という全画面共有の面に触る。 |
| 競合リスク | **高**。`routes/web.php` / `tests/Support/Routing/NestedRouteDefenseInventory.php` / `tests/Architecture/ControllerAuthorizationGateTest.php` / `app/Providers/AppServiceProvider.php` は他の route 追加系 TODO と衝突しやすい。`resources/js/lib/shared-props.ts` と `AppLayout.svelte` は front 系 TODO と衝突しやすい。**並列実装しない**こと。 |
| 実装順序 | 8（gate + mutation 確認）→ 1 → 2 → 3 → 4 → 7 → 5 → 6 → 9。各段で `composer test --filter` の対象テストを先に赤くしてから実装に入る（思考原則 5） |
| 完了条件 | `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` が全 green。加えて **gate mutation ログ**（施策 8 の M1〜M7）が `devnotes/{dir}/gate-mutation-log.md` に残っていること |

---

## 関連する現行コード (抜粋)

### app/Models/OrganizationInvitation.php (全文)

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProjectRole;
use Database\Factories\OrganizationInvitationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use ParagonIE\CipherSweet\BlindIndex;
use ParagonIE\CipherSweet\EncryptedRow;
use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;
use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;

/**
 * 組織招待。token は平文を保存せず sha256 ハッシュ (token_hash) のみ。
 * email は CipherSweet 暗号化 + blind index。
 * token_hash / organization_id / invited_by_user_id は $fillable 外。
 * 取り消しは行削除ではなく revoked_at による論理失効 (spirux 方式)。
 *
 * @property-read ProjectRole|null $project_role 受諾時に Default Project へ付与する pivot ロール
 */
class OrganizationInvitation extends Model implements CipherSweetEncrypted
{
    /** @use HasFactory<OrganizationInvitationFactory> */
    use HasFactory;

    use UsesCipherSweet;

    /** @var list<string> */
    protected $fillable = [
        'email',
        'role',
    ];

    /** @var list<string> */
    protected $hidden = [
        'token_hash',
    ];

    /**
     * 招待 token (平文) を生成する。URL 埋め込み用途のみで DB には保存しない。
     * DB には hashToken() の sha256 を token_hash 列に保存する。
     */
    public static function generateToken(): string
    {
        return Str::random(64);
    }

    /**
     * 平文 token を at-rest 保存用の sha256 hash に変換する。
     */
    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * 平文 token から「受諾可能 (active: 未受諾・未失効・期限内)」な招待を解決する。
     * token_hash 照合 + scopeActive のみ (平文 email 検索は行わない = 列挙面を広げない)。
     * active でない (不在/失効/取消/受諾済) 場合は null。
     *
     * MatchesInvitationEmail / acceptInvitationIfValid / register prefill resolver が共有し、
     * active 判定条件のドリフトを防ぐ単一解決口。
     * (POST 受諾 acceptInvitation() は revoked/accepted/expired を個別メッセージに出し分けるため
     *  本メソッドを使わない)
     */
    public static function findActiveByPlainToken(string $plainToken): ?self
    {
        // active の定義は scopeActive が単一の正 (未受諾・未失効・期限内: expires_at > now)。
        // isExpired()/isAccepted()/isRevoked() の個別判定と概念的に一致させ、ドリフトを防ぐ。
        return self::query()
            ->active()
            ->where('token_hash', self::hashToken($plainToken))
            ->first();
    }

    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        $encryptedRow
            ->addField('email')
            ->addBlindIndex('email', new BlindIndex('email_index'));
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function isExpired(): bool
    {
        /** @var Carbon $expiresAt */
        $expiresAt = $this->getAttribute('expires_at');

        return $expiresAt->isPast();
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Active (受諾可能: 未受諾・未失効・期限内) な招待の query scope。
     *
     * @param  Builder<OrganizationInvitation>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // 受諾時に Default Project へ付与する pivot ロール (null = org 参加のみ)。
            // サーバ導出値のため $fillable 外 (forceFill 専用 = tenant キー不信の流儀)
            'project_role' => ProjectRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
```

### app/Services/Organization/OrganizationMembershipService.php (招待関連の抜粋: L55-320)

```php

    /**
     * メンバー招待 (3 値ロールコマンド)。招待レコード生成 + 受諾 URL 付きメール送信。
     * 編集者/撮影者は Default Project 存在が必須 (不在は ValidationException = Inertia error bag)。
     *
     * @throws ValidationException 既存メンバー / 有効な既存招待 (中立メッセージ) / project 不在
     */
    public function inviteMember(Organization $organization, User $invitedBy, string $email, AdminConsoleRole $role): OrganizationInvitation
    {
        if ($this->emailBelongsToMember($organization, $email) || $this->hasPendingInvitation($organization, $email)) {
            // 既存メンバーか既存招待かを開示しない中立メッセージ (アカウント列挙対策)
            throw ValidationException::withMessages([
                'email' => ['このメールアドレスには招待を送信できません。'],
            ]);
        }

        // 編集者/撮影者は Default Project が前提 (送信時点の静的確認。受諾時の最終確認は
        // joinOrganization が resolveForUpdate で行い、不在なら未割当に落とす)
        if ($role->projectRole() !== null && $this->defaultProjects->resolve($organization) === null) {
            throw ValidationException::withMessages([
                'role' => ['編集者・撮影者を招待するには、先にプロジェクトを作成してください。'],
            ]);
        }

        $plainToken = OrganizationInvitation::generateToken();

        $invitation = new OrganizationInvitation(['email' => $email]);
        $invitation->organization()->associate($organization);
        $invitation->invitedBy()->associate($invitedBy);
        // role / project_role / token_hash / expires_at は明示代入 (mass-assignment させない)
        $invitation->forceFill([
            'role' => $role->organizationRole()->value,
            'project_role' => $role->projectRole()?->value,
            'token_hash' => OrganizationInvitation::hashToken($plainToken),
            'expires_at' => now()->addDays(self::EXPIRES_DAYS),
        ]);
        $invitation->save();

        // 受諾はログイン必須 (auth ミドルウェア) のため署名なし URL でよい。平文 token は保存しない
        Notification::route('mail', $email)->notify(new OrganizationInvitationNotification(
            organizationName: $organization->name,
            acceptUrl: url('/invitations/accept?token='.$plainToken),
        ));

        // 既存ユーザーが宛先ならアプリ内でも気づけるようにする (メールの補完。平文 token は含めない)
        $this->notifications->notifyInvitationReceived($invitation);

        return $invitation;
    }

    /**
     * 招待受諾。ログイン中ユーザーが受諾する (招待 email と user の email の一致は要求しない)。
     *
     * @throws ValidationException token 不正 / 取り消し済み / 失効 / 受諾済み / 既メンバー
     */
    public function acceptInvitation(string $plainToken, User $user): Organization
    {
        $invitation = OrganizationInvitation::query()
            ->where('token_hash', OrganizationInvitation::hashToken($plainToken))
            ->first();

        // 取り消し済みは「無効」と区別しない (取り消された事実を token 保持者に開示しない)
        if ($invitation === null || $invitation->isRevoked()) {
            throw ValidationException::withMessages(['token' => ['この招待は無効です。']]);
        }
        if ($invitation->isAccepted()) {
            throw ValidationException::withMessages(['token' => ['この招待は既に使用されています。']]);
        }
        if ($invitation->isExpired()) {
            throw ValidationException::withMessages(['token' => ['この招待は有効期限が切れています。']]);
        }

        $organization = $invitation->organization;
        Assert::isInstanceOf($organization, Organization::class);

        if ($organization->users()->whereKey($user->getKey())->exists()) {
            throw ValidationException::withMessages(['token' => ['既にこの組織のメンバーです。']]);
        }

        $role = OrganizationRole::from($invitation->role);

        $this->joinOrganization($invitation, $organization, $user, $role);

        return $organization;
    }

    /**
     * 登録 (register) 経路の招待受諾。CreateNewUser から呼ぶ。
     *
     * acceptInvitation (ログイン後経路) と異なり、失敗しても例外を投げず null を返す
     * (登録そのものは成功させ、呼び出し側が個人組織へ fallback するため)。register 経路は
     * 招待 email と登録 email の一致を要求する (MatchesInvitationEmail rule と対で二重防御)。
     *
     * **register 経路専用 (再利用禁止)**: join 成立時、参加した招待組織を
     * current_organization_id へ **無条件で確定する副作用** を持つ (登録直後の user は
     * current 未設定のため「招待成立 ⇒ current = 招待先」を強制できる)。この副作用は
     * 「呼び出し元の user が登録直後で current 未確定」であることに依存するため、
     * **ログイン中経路 (既存 current を持つ user) から再利用してはならない**
     * (既存 current を無条件上書きしてしまう)。POST 受諾は current を切り替えない
     * acceptInvitation を使い、共通コア joinOrganization は current を触らない
     * (InvitationTest が POST 受諾の current 非変更を固定する)。
     *
     * @return Organization|null 参加した組織 / 招待が受諾不能 (不在・失効・受諾済・取消・
     *                           email 不一致・既メンバー) なら null
     */
    public function acceptInvitationIfValid(string $plainToken, User $user): ?Organization
    {
        // active (未受諾・未失効・期限内) 解決は findActiveByPlainToken に集約 (単一解決口)。
        $invitation = OrganizationInvitation::findActiveByPlainToken($plainToken);
        if ($invitation === null) {
            return null;
        }

        // 招待 email と登録 email が一致しない場合は join しない
        if ($invitation->email !== $user->email) {
            return null;
        }

        $organization = $invitation->organization;
        Assert::isInstanceOf($organization, Organization::class);

        // 既メンバー (race 等) は個人組織へ fallback
        if ($organization->users()->whereKey($user->getKey())->exists()) {
            return null;
        }

        $this->joinOrganization($invitation, $organization, $user, OrganizationRole::from($invitation->role));

        // [register 経路限定] 参加した招待組織をこの新規ユーザーの「現在組織」として確定する。
        // - 本メソッドは register 経路専用 (呼び出し元は CreateNewUser のみ。POST 受諾は acceptInvitation)。
        //   よって現在組織の確定は POST 受諾経路 (現在組織を切り替えない契約) に波及しない。
        // - 個人組織パスが provision() 内で現在組織を据えるのと対称に、招待参加も本サービス内で
        //   「join + 現在組織確定」を 1 ユースケースとして閉じる (呼び出し元の登録 tx 内で連続実行され、
        //   「join 済だが現在組織未設定」の中間状態を残さない)。
        // - この user は登録直後で現在組織が未確定のため、招待先組織を無条件に現在組織にする
        //   (register 責務として「招待成立 ⇒ 現在組織 = 招待先」を強制)。current_organization_id は
        //   mass-assignment 保護キーのためサーバ導出値を forceFill で明示代入する (tenant キー不信)。
        $user->forceFill(['current_organization_id' => $organization->id])->save();

        return $organization;
    }

    /**
     * register 画面のメール prefill 用に、session の invitation_token から
     * 「active な招待の招待先 email」を解決する。fail-secure:
     *  - session 値が非文字列/空 → forget して null
     *  - findActiveByPlainToken が null (不在/失効/取消/受諾済) → session から forget して null
     *    (GET 時点で stale/invalid な token を破棄し「UI は通常登録・サーバは招待フロー」の
     *    不整合を除去する)
     *  - active → 招待先 email (CipherSweet 自動復号後は string) を返す
     *
     * 平文 email 検索は行わない (token_hash 照合のみ)。列挙面を広げない。
     * 正常系 (active) では forget しない: 後続 POST の CreateNewUser が受諾に token を使う。
     *
     * **戻り契約**: 非 null を返す場合は必ず非空の email 文字列である (空文字は null に潰す)。
     * 呼び出し側 (Fortify registerView の no-store 判定 / frontend の isInvited) はこの契約に依存する。
     */
    public function resolveRegisterPrefillEmail(Session $session): ?string
    {
        $raw = $session->get('invitation_token');

        if (! is_string($raw) || $raw === '') {
            if ($raw !== null) {
                $session->forget('invitation_token'); // 汚染値を除去
            }

            return null;
        }

        $invitation = OrganizationInvitation::findActiveByPlainToken($raw);
        if ($invitation === null) {
            $session->forget('invitation_token'); // stale/invalid を GET 時点で破棄

            return null;
        }

        // CipherSweet 復号後の email。空文字 (想定外の欠損) は fail-secure に握り、
        // token を破棄して null 返却する (prefill しない)。
        $email = $invitation->email;
        if ($email === '') {
            $session->forget('invitation_token');

            return null;
        }

        return $email;
    }

    /**
     * 招待の取り消し (論理失効)。行削除ではなく revoked_at を立てる (監査痕跡を残す)。
     * 既に失効/受諾済みなら冪等 no-op (二重取り消しを例外にしない)。
     */
    public function revokeInvitation(OrganizationInvitation $invitation): void
    {
        if ($invitation->isRevoked() || $invitation->isAccepted()) {
            return;
        }

        $invitation->forceFill(['revoked_at' => now()])->save();
    }

    /**
     * 招待受諾の確定処理 (attach + ロール付与 + pivot attach + accepted_at)。両受諾経路の共通コア。
     * accepted_at は $fillable 外のため forceFill で明示代入する。
     *
     * 並行受諾への防御は 2 層:
     * 1. **招待行の lockForUpdate**: 同一招待 (同一トークン二重送信) の並行受諾を直列化し、
     *    accepted_at / revoked_at / expires_at の判定をロック下で再実行する (TOCTOU 封じ。
     *    呼び出し元の事前検証は第 1 層として維持)
     * 2. **organization_user の原子的 INSERT (insertOrIgnore)**: 別招待経由の並行 join
     *    (同一 user × 同一 org) でも unique 違反にならず、勝った側だけが role/pivot を付与する
     *    (affected rows = 0 なら join 済みと判断してスキップ)。値はすべてサーバ側モデル由来
     *    (organization/user は relation 解決済み) で、payload 不信の保護キー規約に反しない。
     *    organization_user は (organization_id, user_id) UNIQUE + timestamps のみの pivot。
     *
     * project_role 付き招待は Default Project (resolveForUpdate = 行ロック) へ pivot attach。
     * 受諾時に project が消えていた場合は org 参加のみ = 「未割当」表示状態に落ちる (可視 degrade)。
     */
    private function joinOrganization(OrganizationInvitation $invitation, Organization $organization, User $user, OrganizationRole $role): void
    {
        DB::transaction(function () use ($organization, $user, $role, $invitation): void {
            // canonical 共通ロック境界 (users 昇順 → organizations)。並行メンバー追加を
            // deleteAccount 等と直列化する (招待行ロックの手前で org/user 行ロックを取る)。
            $this->lockForMembershipWrite([$this->keyOf($user)], [$this->keyOf($organization)]);

            // 1. 招待行ロック + 受諾可能状態のロック下再検証 (並行受諾に敗れた側は冪等 no-op)
            /** @var OrganizationInvitation $locked */
            $locked = OrganizationInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            if ($locked->isAccepted() || $locked->isRevoked() || $locked->isExpired()) {
                return; // 期限境界の TOCTOU も含めロック下で完全再検証 (敗者は冪等 no-op)
            }

            // 2. org 参加の原子的 INSERT。0 行 = 別経路で join 済み (role/pivot は変更しない。
            //    非正規状態が残る場合も「未割当」として可視化され管理画面から修復できる)
            $joined = DB::table('organization_user')->insertOrIgnore([
                'organization_id' => $organization->id,
                'user_id' => $user->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($joined === 1) {
                $user->addRole($role->value, $organization->laratrust_team_id);

                $projectRole = $locked->project_role;
                if ($projectRole instanceof ProjectRole) {
                    $project = $this->defaultProjects->resolveForUpdate($organization);
                    $project?->members()->syncWithoutDetaching([
                        $user->id => ['role' => $projectRole->value],
                    ]);
                }
            }

            $locked->forceFill(['accepted_at' => now()])->save();
        });
    }

    /**
     * ロール遷移コマンドの適用 (概念設計 D2(b))。1 トランザクションで最終状態を保証する:
     * - Admin:   org Admin + org 配下 project pivot detach (stale 掃除)
     * - Editor:  org Member + Default Project pivot role=project_admin (sync)
     * - Shooter: org Member + Default Project pivot role=project_member (sync)
     * changeRole 再利用により非メンバー拒否・最終 Owner 保護を継承する
     * (DB::transaction のネストは savepoint 扱いのため、changeRole の ValidationException は
     * そのまま外へ伝播し外側 tx ごと rollback される)。
     *
```

### app/Http/Controllers/Organizations/InvitationAcceptanceController.php (全文)

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;
use App\Support\Seo\SeoManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Webmozart\Assert\Assert;

/**
 * 招待受諾。GET (確認画面) は guest 可 (未ログインは register へ誘導)。POST (受諾) は auth 必須。
 * verified は要求しない (招待された直後の未検証ユーザーも受諾できる)。
 * 招待 email とログインユーザーの email の一致はログイン後経路では要求しない仕様。
 */
class InvitationAcceptanceController extends Controller
{
    /**
     * 受諾確認画面 (GET, guest 可)。
     *
     * - token 欠落 (URL に token param 自体が無い) は 404
     * - 無効招待 (不在/取消済/受諾済/期限切れ) は理由を出し分けず組織名も出さない専用ページ
     *   (Invitations/Invalid) を返す。どの無効理由でも同一画面にすることで token オラクルを防ぐ
     *   (未認証の URL 探索で「組織が実在し招待が取り消された」等を識別させない)
     * - 未ログイン + 有効招待: token を session に fail-secure 保存し register へ誘導する
     *   (登録完了時に CreateNewUser が招待組織へ参加させる)
     * - ログイン済 + 有効招待: 受諾確認画面 (組織名 + 受諾ボタン) を表示する
     *
     * タイトル: route 既定は config('seo.app_titles')['invitations.accept'] =「組織への招待」。
     * 無効分岐だけは同じ route で別ページ (Invitations/Invalid) を返すため、
     * SeoManager::setPrivateTitle() で上書きする (config は route 名でしか引けない)。
     * **理由・組織名は開示しない**既存の秘匿契約を守り、固有名にも組織名を混ぜない。
     */
    public function show(Request $request, SeoManager $seo): Response|RedirectResponse
    {
        $token = $request->query('token');
        abort_unless(is_string($token) && $token !== '', 404);

        $invitation = OrganizationInvitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        // 無効招待は理由非開示の専用ページへ (guest / auth 共通)
        if ($invitation === null || $invitation->isRevoked() || $invitation->isAccepted() || $invitation->isExpired()) {
            // タブ title は h1「この招待リンクは使用できません」から指示語「この」を落とした形。
            // SeoTitle::compose が ` | {サイト名}` を付けるため、タブ幅を圧迫しない範囲で見出しと揃える
            // (config/seo.php の「h1 と一致させる」規約に対する意図的な短縮。
            //  文言を変えるときは Invitations/Invalid.svelte の h1 も追随させる)。
            $seo->setPrivateTitle('招待リンクは使用できません');

            return Inertia::render('Invitations/Invalid');
        }

        // 未ログイン: token を session に保存して register へ誘導 (受諾は登録完了後)
        if (! $request->user() instanceof User) {
            $request->session()->put('invitation_token', $token);

            return redirect()->route('register');
        }

        $organization = $invitation->organization;
        Assert::isInstanceOf($organization, Organization::class);

        return Inertia::render('Invitations/Accept', [
            'organizationName' => $organization->name,
            'token' => $token,
        ]);
    }

    /** 受諾 (POST)。成否いずれも dashboard へ flash 付きで遷移する */
    public function store(Request $request, OrganizationMembershipService $membership): RedirectResponse
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $request->validate([
            'token' => ['required', 'string'],
        ]);
        $token = $request->input('token');
        Assert::string($token);

        try {
            $organization = $membership->acceptInvitation($token, $user);
        } catch (ValidationException $e) {
            // back 先が GET /invitations/accept (404 になり得る) のため dashboard へ逃がす
            return redirect()->route('dashboard')->with('error', $e->getMessage());
        }

        return redirect()->route('dashboard')->with('success', "「{$organization->name}」に参加しました");
    }
}
```

### app/Http/Controllers/NotificationController.php (全文)

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DataTransferObjects\Notification\NotificationListItemData;
use App\Enums\Notification\NotificationType;
use App\Models\User;
use App\Services\Notification\NotificationCenterService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;
use Webmozart\Assert\Assert;

/**
 * 通知センター (一覧 / open 遷移 / 既読化)。薄い Controller + Service 委譲。
 *
 * - {notification} は implicit binding を使わず Service が $user->notifications() 経由で
 *   解決する (cross-user は構造的に 404 = 存在オラクル封じ。403 で存在を漏らさない。
 *   1 param ルートのため NestedRouteIdorDefenseTest の inventory 対象外)
 * - open は POST + 303 (GET にしない = prefetch/リンクプレビューによる意図しない既読化防止)
 * - open は認可判断 (Gate) を一切複製しない。行うのは (a) 自通知の organization_id と
 *   current org の突合 (自分のデータ同士のルーティング判断) と (b) org→project→manual の
 *   relation 連鎖による存在解決のみ (「認可より前の 404」層の再利用。Gate::authorize は
 *   遷移先 projects.manuals.show が唯一の判断点)。(b) と遷移の間の TOCTOU
 *   (redirect 直後の削除) は遷移先の標準 404 が受ける (残余は許容)
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationCenterService $notifications) {}

    /** 通知一覧 (全 org 横断 = 自分宛のみで構造的に閉じる) */
    public function index(Request $request): Response
    {
        $user = $this->authedUser($request);
        $paginator = $this->notifications->paginateFor($user);

        $items = [];
        foreach ($paginator->items() as $notification) {
            Assert::isInstanceOf($notification, DatabaseNotification::class);
            $items[] = NotificationListItemData::fromNotification($notification)->toArray();
        }

        return Inertia::render('Notifications/Index', [
            'notifications' => $items,
            // 未読数をページ表示制御 (read-all ボタン表示可否) 用に渡す。専用 scalar なのは
            // shared prop notifications.unreadCount がページ prop `notifications` (配列) と
            // キー衝突するため (詳細は Index.svelte JSDoc)。
            'unreadCount' => $this->notifications->unreadCountFor($user),
            // 既存 ManualListItem のページャ shape (ProjectController::manualRows) と同形
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /** 既読化 + 遷移先のサーバ解決 (POST + 303。開けない場合は一覧へ明示 redirect) */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $user = $this->authedUser($request);
        $found = $this->notifications->findOwnOrFail($user, $notification); // cross-user 404
        $this->notifications->markRead($found);

        $item = NotificationListItemData::fromNotification($found);

        // 遷移はすべて 303 (POST → GET の意味論を明示。Inertia の POST visit とも整合)
        return match (true) {
            // manual 系: 通知 org ≠ current org → 案内して一覧へ (自動 org 切替はしない = 驚き最小)
            $item->isManualJob() && ! $this->belongsToCurrentOrg($user, $item) => redirect()
                ->route('notifications.index', [], 303)
                ->with('info', 'この通知は別の組織のものです。組織を切り替えてから開いてください。'),
            // manual 系: current org → project → manual の relation 連鎖で現存する → manual 画面へ
            $item->isManualJob() && $this->manualStillExists($user, $item) => redirect()
                ->route('projects.manuals.show', [$item->projectId(), $item->manualId()], 303),
            $item->isManualJob() => redirect()
                ->route('notifications.index', [], 303)
                ->with('info', '対象の動画マニュアルは削除されています。'),
            $item->type === NotificationType::TicketBalanceLow => redirect()
                ->route('billing.tickets.show', [], 303),
            $item->type === NotificationType::InvitationReceived => redirect()
                ->route('notifications.index', [], 303)
                ->with('info', '招待はメールの受諾リンクから参加してください。'),
            // 未知 type (enum⇔DB ドリフト時の防御): 既読化のみ・汎用文言
            default => redirect()
                ->route('notifications.index', [], 303)
                ->with('info', 'この通知には開ける対象がありません。'),
        };
    }

    /** 1 件既読化 (back() 完結) */
    public function read(Request $request, string $notification): RedirectResponse
    {
        $user = $this->authedUser($request);
        $this->notifications->markRead($this->notifications->findOwnOrFail($user, $notification));

        return back();
    }

    /** 一括既読化 (back() 完結) */
    public function readAll(Request $request): RedirectResponse
    {
        $this->notifications->markAllRead($this->authedUser($request));

        return back()->with('success', 'すべての通知を既読にしました');
    }

    /** admin guard 追加で user() は union になるため User へ narrowing する */
    private function authedUser(Request $request): User
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        return $user;
    }

    /** 通知の org 文脈 (organization_id 列) が current org と一致するか (認可判断ではない) */
    private function belongsToCurrentOrg(User $user, NotificationListItemData $item): bool
    {
        return $item->organizationId !== null
            && $item->organizationId === $user->current_organization_id;
    }

    /**
     * current org → projects() → manuals の relation 連鎖による存在解決 (exists() 1 クエリ。
     * 認可判断なし = 「認可より前の 404」層の再利用)。
     */
    private function manualStillExists(User $user, NotificationListItemData $item): bool
    {
        $organization = $user->currentOrganization;
        if ($organization === null) {
            return false;
        }

        return $organization->projects()
            ->whereKey($item->projectId())
            ->whereHas('manuals', fn (Builder $query): Builder => $query->whereKey($item->manualId()))
            ->exists();
    }
}
```

### app/Http/Middleware/HandleInertiaRequests.php (share() 部分)

```php
    /**
     * 全ページ共有 props。
     * flash.visitKey は flash-to-toast の de-dup 用 (同一 flash の二重表示防止)。
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        // admin guard (AdminUser) 追加により user() は union 型になるため、
        // Inertia (web guard) の共有 props は User のみを対象に narrowing する
        $user = $request->user();
        if (! $user instanceof User) {
            $user = null;
        }

        return [
            ...parent::share($request),
            'appName' => config('app.name'),
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'emailVerified' => $user->hasVerifiedEmail(),
                    'twoFactorEnabled' => $user->hasEnabledTwoFactorAuthentication(),
                ],
            ],
            'organizations' => $this->organizationsProp($user),
            'currentOrganization' => $this->currentOrganizationProp($user),
            // 通知センターの未読数 (全 org 横断・自分宛のみ)。closure = Inertia partial reload で
            // 省略可能 (将来の router.reload({ only: ['notifications'] }) ポーリング拡張にも使える)
            'notifications' => [
                'unreadCount' => fn (): int => $user === null ? 0 : $user->unreadNotifications()->count(),
            ],
            'flash' => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'info' => $request->session()->get('info'),
                'warning' => $request->session()->get('warning'),
                'visitKey' => Str::uuid()->toString(),
            ],
            // 問い合わせ CTA の宛先 (内部 /contact / 外部 URL / mailto を config 駆動で切替)。
            'contact' => fn (): array => [
                'url' => app(ContactUrl::class)->resolve(),
                'kind' => app(ContactUrl::class)->kind()->value,
            ],
            // サーバ描画 <title> と同一文字列を共有し、SPA 遷移後の document.title 陳腐化を解消する
            // (resources/js/lib/document-title.ts が同期)。SeoManager は request-scoped で
            // SeoComposer と同じ実体 (二重 SoT を作らない)。controller の set / setPrivateTitle は
            // share 評価時点 (response 構築時) で反映済み。
            'title' => fn (): string => $this->seoManager->resolveDocumentTitle($request->route()?->getName()),
        ];
    }

    /**
     * ユーザー所属組織の一覧 (組織切替 UI 用。Phase 2c で sidebar に配線する)。
     *
     * @return list<array{id: int, name: string, isPersonal: bool}>
```

### app/DataTransferObjects/Admin/InvitationRowData.php (全文)

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Admin;

use App\Enums\MemberRoleState;
use App\Enums\OrganizationRole;
use App\Models\OrganizationInvitation;
use Illuminate\Support\Carbon;
use Webmozart\Assert\Assert;

/**
 * ユーザー管理画面 (Admin/Users) の招待中 1 行分。TS 側 types/admin.ts の InvitationRow と対で保守。
 * 表示状態は招待の org role + project_role から導出する (受諾後のメンバー行と同じ 5 値語彙)。
 */
final readonly class InvitationRowData
{
    public function __construct(
        public int $id,
        public string $email,
        public string $roleState, // MemberRoleState value
        public string $roleLabel,
        public string $expiresAt, // Y-m-d
    ) {}

    public static function fromInvitation(OrganizationInvitation $invitation): self
    {
        $state = MemberRoleState::derive(
            OrganizationRole::from($invitation->role),
            $invitation->project_role,
        );

        $expiresAt = $invitation->getAttribute('expires_at');
        Assert::isInstanceOf($expiresAt, Carbon::class);

        return new self(
            id: $invitation->id,
            email: $invitation->email,
            roleState: $state->value,
            roleLabel: $state->label(),
            expiresAt: $expiresAt->toDateString(),
        );
    }
}
```

### app/Http/Requests/Organizations/StoreOrganizationInvitationRequest.php (全文)

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Enums\AdminConsoleRole;
use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Webmozart\Assert\Assert;

/**
 * メンバー招待 (3 値遷移コマンド)。
 * 認可は Controller の Gate::authorize('manageMembers') が唯一の責務。
 * 重複招待の中立検査・Default Project 存在確認は Service 側 (TOCTOU になる DB 依存検証を
 * FormRequest に置かない)。project_role はクライアントから受けず role コマンドから導出する。
 */
class StoreOrganizationInvitationRequest extends FormRequest
{
    use ProhibitsProtectedKeys;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return array_merge([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::enum(AdminConsoleRole::class)],
        ], $this->protectedKeyMissingRules());
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            // 旧契約値 (organization_admin 等) を送るデプロイ跨ぎタブの回復導線を明示する
            'role.'.Enum::class => 'ロールの指定が不正です。画面を再読み込みしてやり直してください。',
        ];
    }

    /** 型付きアクセサ (validated 後の値を string へ narrow して Service に渡す) */
    public function email(): string
    {
        $email = $this->validated('email');
        Assert::string($email);

        return $email;
    }

    /** 型付きアクセサ (validated 後の値を enum へ narrow して Service に渡す) */
    public function role(): AdminConsoleRole
    {
        $role = $this->enum('role', AdminConsoleRole::class);
        Assert::isInstanceOf($role, AdminConsoleRole::class);

        return $role;
    }
}
```

### app/Enums/OrganizationRole.php / AdminConsoleRole.php / MemberRoleState.php

```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 組織ロール (Laratrust team スコープ)。Q2/Q10 決定の正規名。
 * アプリ固有のロール体系が必要な場合もこの 3 値の構造 (owner/admin/member) は維持し、
 * label() とシーダーで表現を差し替える。
 */
enum OrganizationRole: string
{
    case Owner = 'organization_owner';
    case Admin = 'organization_admin';
    case Member = 'organization_member';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'オーナー',
            self::Admin => '管理者',
            self::Member => 'メンバー',
        };
    }

    /** 管理権限 (メンバー管理・設定変更) を持つか */
    public function canManage(): bool
    {
        return $this !== self::Member;
    }
}
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 管理メニュー (ユーザー管理) のロール遷移コマンド (doc/02 §2.5 + doc/10 §10.5 の合成)。
 * 保存概念ではない: org ロール + Default Project pivot という既存プリミティブへの
 * 「正規状態への遷移」を表す。表示状態は MemberRoleState (導出) が担う。
 * Owner を含まない = Owner 昇格は transferOwnership のみという不変条件の型表現。
 */
enum AdminConsoleRole: string
{
    case Admin = 'admin';     // 管理者 = org Admin (pivot は掃除)
    case Editor = 'editor';   // 編集者 = org Member + project_admin
    case Shooter = 'shooter'; // 撮影者 = org Member + project_member

    public function label(): string
    {
        return match ($this) {
            self::Admin => '管理者',
            self::Editor => '編集者',
            self::Shooter => '撮影者',
        };
    }

    /** コマンド適用後の org ロール */
    public function organizationRole(): OrganizationRole
    {
        return $this === self::Admin ? OrganizationRole::Admin : OrganizationRole::Member;
    }

    /** コマンド適用後の Default Project pivot ロール (Admin コマンドは pivot なし = null) */
    public function projectRole(): ?ProjectRole
    {
        return match ($this) {
            self::Admin => null,
            self::Editor => ProjectRole::Admin,
            self::Shooter => ProjectRole::Member,
        };
    }
}
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ユーザー管理画面の表示状態 (毎リクエスト導出。DB に保存しない = backfill 不要)。
 * org ロール × Default Project pivot の全組合せを漏れなく 5 値に分類する
 * (概念設計 D2 の canonical mapping)。
 */
enum MemberRoleState: string
{
    case Owner = 'owner';           // 管理者 (オーナー)。変更不可 (transferOwnership のみ)
    case Admin = 'admin';           // 管理者。stale pivot があっても org ロール優先で無視
    case Editor = 'editor';         // 編集者 (org Member + project_admin)
    case Shooter = 'shooter';       // 撮影者 (org Member + project_member)
    case Unassigned = 'unassigned'; // 未割当 (org Member + pivot なし)。割当を促す表示

    /**
     * org ロール null (organization_user attach 済みだが Laratrust ロール未付与の異常行) も
     * Unassigned へ丸める: 異常行を非表示にせず「未割当」として可視化し、管理画面から
     * ロール割当コマンドで修復できるようにする (applyConsoleRole の修復経路と対)。
     * null 判定は project pivot 判定より**必ず先**に評価する (org ロールなし + stale pivot が
     * Editor/Shooter と誤表示され修復契約と食い違うのを防ぐ)。
     */
    public static function derive(?OrganizationRole $orgRole, ?ProjectRole $projectRole): self
    {
        return match (true) {
            $orgRole === null => self::Unassigned,
            $orgRole === OrganizationRole::Owner => self::Owner,
            $orgRole === OrganizationRole::Admin => self::Admin,
            $projectRole === ProjectRole::Admin => self::Editor,
            $projectRole === ProjectRole::Member => self::Shooter,
            default => self::Unassigned,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => '管理者（オーナー）',
            self::Admin => '管理者',
            self::Editor => '編集者',
            self::Shooter => '撮影者',
            self::Unassigned => '未割当',
        };
    }
}
```

### database/factories/OrganizationInvitationFactory.php (全文)

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use InvalidArgumentException;
use Webmozart\Assert\Assert;

/**
 * @extends Factory<OrganizationInvitation>
 */
class OrganizationInvitationFactory extends Factory
{
    /**
     * DB には sha256 の token_hash のみ保存する (平文 token 非保存)。
     * 平文 token が必要なテストは createWithPlainToken() を使う (tuple で平文も受け取る)。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'invited_by_user_id' => User::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => OrganizationRole::Member->value,
            'token_hash' => OrganizationInvitation::hashToken(OrganizationInvitation::generateToken()),
            'expires_at' => now()->addDays(7),
        ];
    }

    /**
     * テスト用 helper: invitation を生成し、平文 token も合わせて返す (URL 生成用)。
     *
     * `$overrides['token_hash']` 指定は平文↔hash の不変条件を壊すため fail-fast で拒否する。
     *
     * @param  array<string, mixed>  $overrides
     * @return array{0: OrganizationInvitation, 1: string}
     */
    public function createWithPlainToken(array $overrides = []): array
    {
        if (array_key_exists('token_hash', $overrides)) {
            throw new InvalidArgumentException(
                'createWithPlainToken() does not accept token_hash override (would break plain↔hash invariant)',
            );
        }

        $plainToken = OrganizationInvitation::generateToken();

        $model = $this->state(fn (): array => [
            'token_hash' => OrganizationInvitation::hashToken($plainToken),
        ])->create($overrides);

        Assert::isInstanceOf($model, OrganizationInvitation::class);

        return [$model, $plainToken];
    }

    /** 指定組織への招待として作る */
    public function forOrganization(Organization $organization): static
    {
        return $this->state(fn (): array => ['organization_id' => $organization->id]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => [
            'accepted_at' => now(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'revoked_at' => now(),
        ]);
    }

    public function asAdmin(): static
    {
        return $this->state(fn (): array => [
            'role' => OrganizationRole::Admin->value,
        ]);
    }

    /** 編集者招待 (org Member + 受諾時 Default Project project_admin) */
    public function editorInvitation(): static
    {
        return $this->state(fn (): array => [
            'role' => OrganizationRole::Member->value,
            'project_role' => ProjectRole::Admin->value,
        ]);
    }

    /** 撮影者招待 (org Member + 受諾時 Default Project project_member) */
    public function shooterInvitation(): static
    {
        return $this->state(fn (): array => [
            'role' => OrganizationRole::Member->value,
            'project_role' => ProjectRole::Member->value,
        ]);
    }
}
```

### tests/Architecture/MembershipWriteLockInventoryTest.php (前半)

```php
<?php

declare(strict_types=1);

use App\Services\Organization\OrganizationMembershipService;

/*
 * メンバーシップ書き込みの共通ロック規約 (canonical 順序 users→organizations) の drift-guard。
 *
 * OrganizationMembershipService の mutating な public メソッドを reflection で列挙し、
 * 3 分類 (directLock / delegatedToLocked / exempt) への登録を強制する。加えてメソッドソースを
 * 検査し、実際にロックを呼んでいることを保証する:
 * - directLock 群: メソッドソースに `lockForMembershipWrite(` が現れること。
 * - delegatedToLocked 群: ロック済み内部メソッド (`joinOrganization(`) 呼び出しが現れること。
 * - 未分類メソッドがあれば fail (drift 検出)。
 */

test('OrganizationMembershipService の書き込みメソッドは共通ロック規約に準拠する', function (): void {
    // 自身の tx 冒頭で直接ロックする mutating メソッド
    $directLock = ['applyConsoleRole', 'changeRole', 'removeMember', 'transferOwnership', 'deleteAccount'];
    // ロック済み内部メソッド (joinOrganization) 経由で間接的にロックされる受諾経路
    $delegatedToLocked = ['acceptInvitation', 'acceptInvitationIfValid'];
    // ロック不要 (membership/role を変えない) と判断した書き込みメソッド (根拠付き exempt)
    $exempt = [
        'inviteMember',     // 招待レコード生成のみ (membership/role 不変)
        'revokeInvitation', // 招待の論理失効のみ (membership/role 不変)
        // 読み取り専用判定 (ロック不要・表示スナップショット)。deleteAccount がロック下で権威判定する
        'organizationsBlockingDeletion',
        // 課金孤児の検知バッチ用の読み取り専用列挙 (Owner 不在の組織)。membership/role を変えない
        'organizationsWithoutOwner',
        // register prefill 用の read + session forget のみ (membership/role/DB 書き込みなし)。
        // token_hash 照合で active 招待の email を返すだけで、共通ロック規約の対象外。
        'resolveRegisterPrefillEmail',
    ];

    $reflection = new ReflectionClass(OrganizationMembershipService::class);
    $ownPublicMethods = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
        ->reject(fn (ReflectionMethod $m): bool => $m->isConstructor()
            || $m->getDeclaringClass()->getName() !== OrganizationMembershipService::class)
        ->map(fn (ReflectionMethod $m): string => $m->getName())
        ->all();

    // 1. 分類漏れ検出
    $classified = array_merge($directLock, $delegatedToLocked, $exempt);
    expect(array_values(array_diff($ownPublicMethods, $classified)))
        ->toBe([], '新しい書き込みメソッドは directLock / delegatedToLocked / exempt に分類すること');

    // 2. 実ロック呼び出しの静的検査 (メソッド本文を切り出して文字列一致)
    $source = file($reflection->getFileName() ?: '') ?: [];
    $bodyOf = function (string $method) use ($reflection, $source): string {
        $m = $reflection->getMethod($method);
        $start = $m->getStartLine();
        $end = $m->getEndLine();
        if ($start === false || $end === false) {
            return '';
        }

        return implode('', array_slice($source, $start - 1, $end - $start + 1));
    };
    foreach ($directLock as $method) {
        // {$method} は lockForMembershipWrite を直接呼ぶこと (toContain は message 引数を取らない)
        expect(str_contains($bodyOf($method), 'lockForMembershipWrite('))->toBeTrue();
    }
    foreach ($delegatedToLocked as $method) {
        // {$method} はロック済み joinOrganization を経由すること
        expect(str_contains($bodyOf($method), 'joinOrganization('))->toBeTrue();
    }

    // 3. [ロック順序 guard] deleteAccount 本文で最初の lockForMembershipWrite( が
    //    organizations( 列挙より前に現れること (canonical 順序 users→organizations の退行検出)
    $deleteBody = $bodyOf('deleteAccount');
    $firstLock = strpos($deleteBody, 'lockForMembershipWrite(');
    $orgEnumeration = strpos($deleteBody, "orderBy('organizations.id')");
    expect($firstLock)->not->toBeFalse('deleteAccount は lockForMembershipWrite を呼ぶこと');
    expect($orgEnumeration)->not->toBeFalse('deleteAccount は organizations を列挙すること');
    expect($firstLock)->toBeLessThan($orgEnumeration, 'deleteAccount は組織列挙の前に user 行をロックすること');
});

/*
 * role-grant sole-gateway drift-guard。
```

### routes/web.php (招待受諾セクション L596-620)

```php
});

/*
|--------------------------------------------------------------------------
| 招待受諾 (verified は要求しない)
|--------------------------------------------------------------------------
| GET (確認画面) は guest 可: 未ログインの招待リンクは token を session に fail-secure 保存し
| register へ誘導する (登録完了時に CreateNewUser が招待組織へ参加させる)。
| POST (受諾確定) のみ auth 必須。
*/
// GET も token を sha256 照合して DB を 1 件引き、有効/無効で応答が分岐する
// (未認証で観測できる分、姉妹の POST より攻撃面として広い)。
// POST 側の `10,1` と同値にする。未認証面のため named limiter でキーを明示する。
Route::get('/invitations/accept', [InvitationAcceptanceController::class, 'show'])
    ->middleware('throttle:invitation-accept')
    ->name('invitations.accept');
// 招待トークンは hash 照合されるが、総当り試行そのものを有界にする
// (onboarding.activate-personal と同値 = 認証済みの一回性操作)。
Route::post('/invitations/accept', [InvitationAcceptanceController::class, 'store'])
    ->middleware(['auth', 'throttle:10,1'])
    ->name('invitations.accept.store');

/*
|--------------------------------------------------------------------------
| local 専用デバッグログイン
```

### app/Providers/AppServiceProvider.php (named limiter L245-285)

```php
     * 未認証で到達する認証面 GET の RateLimiter (T120 事後監査の是正)。
     *
     * ★どちらも**未認証**面のため named limiter で数える単位を明示する。
     *   inline throttle (`10,1`) はフレームワーク既定キーに依存するため、
     *   AGENTS.md の規約どおり「認証済みかつ actor 自身に閉じる操作」以外では使わない。
     *
     * ★閾値は発明しない (AG-096 = 閾値はプロダクト依存):
     *   - social-callback  = 10/min。未認証で到達する認証面の IP レーンとして
     *     本番稼働中の `passkeys` limiter の guest 分岐 (10/min) と同値。
     *   - invitation-accept = 10/min。姉妹操作 invitations.accept.store の
     *     `throttle:10,1` と同値 (同じ token 照合を行う 2 本の非対称を解消する)。
     *
     * ★キーに route parameter / query token を混ぜない (NamedRateLimiterKeyTest)。
     *   social.callback の {provider} や invitations.accept の ?token= を key に入れると
     *   bucket が分かれ、「429 になるまでの回数」が実在オラクルになる。
     *
     * ★**無効リクエストも同じ bucket を消費する** (throttle は controller より前に走る)。
     *   intent 不在の callback / 無効 token の招待 open も枠を減らすため、
     *   同一 IP からの無効連打は正当利用者の枠を奪える (一時 DoS)。
     *   これは「未認証面を IP で数える」ことの必然であり、
     *   引き換えに得ているのは「外向き HTTP と token 照合の総量が有界になること」である。
     *
     * ★巻き添えの扱い: IP レーンである以上、同一 NAT 配下の一斉ログイン / 一斉招待受諾は
     *   巻き添え 429 になりうる。limiter は恒久ロックを作らないが到達は保証しない。
     *   運用は 429 発生率と invalid callback 比率を監視し、
     *   **初動は閾値変更ではなく TRUSTED_PROXIES / 実 client IP の解決の確認**とする
     *   (docs/trusted-proxies-runbook.md)。
     */
    private function configureAuthSurfaceRateLimiters(): void
    {
        // SSO callback。1 リクエストで IdP へ token エンドポイント POST が飛びうる
        // (state + intent が揃った場合)。未認証で外部へ HTTP を発射できる唯一の経路。
        RateLimiter::for('social-callback', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('social-callback:ip:'.($request->ip() ?? 'unknown')));

        // 招待受諾の確認画面 (GET)。未認証入力の token を sha256 照合して DB を 1 件引き、
        // 有効/無効で応答が分岐する。姉妹の POST は既に throttle:10,1 で有界化されている。
        RateLimiter::for('invitation-accept', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('invitation-accept:ip:'.($request->ip() ?? 'unknown')));
    }

```

### app/Http/Routing/RouteBindingTypes.php (MANUALLY_RESOLVED 部分)

```php
     *
     * IV-9(a) の「action 引数が宣言モデル型であること」検査から**明示的に除外**する
     * (action 引数は string になるため)。除外しても pattern の型制約 (IV-3 / IV-4) と
     * 手動解決先の PK 型 (IV-9(c)) は引き続き検証されるため、22P02 / 22003 防御は落ちない。
     *
     * **除外は「param 名 + route identity」単位**で登録する (impl-review R1 Warning)。
     * param 名だけで除外すると、将来同名 param を使う**別 route が丸ごと免除**され
     * deny-by-default の穴になる。列挙されていない route で同じ param が現れたら
     * IV-9(a) は通常どおり fail する。
     *
     * route identity の規約は `routeBindingIdentity()` と同じ (name 優先 / 無ければ
     * `method:uri`)。identity の実在は IV-9 補が検証する (陳腐化した登録を残さない)。
     *
     * @var array<string, array{routes: list<string>, reason: string}>
     */
    public const MANUALLY_RESOLVED = [
        // NotificationController は $request->user()->notifications() 経由で解決する
        // (他ユーザーの通知 id は「存在しない」と同じ 404 = 存在オラクル封じ)。
        'notification' => [
            'routes' => ['notifications.open', 'notifications.read'],
            'reason' => 'cross-user 404 のため controller が $user->notifications() 経由で解決する',
        ],
        // ProjectMemberController::destroy は現在組織の users() から解決する。
        // implicit binding のままだと「不在 id = binding 404 / 実在の非メンバー = 後段短絡の
        // 302」と分岐し users.id の存在オラクルになる (audit-cycle-2 High-1 横断)。
        // {user} の意味的な親は {project} ではなく現在組織のため scopeBindings は採れない
        // (Project::users() が存在しない。Project::members() は明示メンバーのみで意味が狭い)。
        'user' => [
            'routes' => ['projects.members.destroy'],
            'reason' => '存在オラクル封じのため controller が $organization->users() 経由で解決する'
                .' (binding 段で解決しないことが不在 id と実在の非メンバーを同一応答にする根拠)',
        ],
    ];

    /**
     * param ごとに**許可する binding field**。`{user:slug}` のように
     * 非 PK field を指定されると Route::pattern の型制約と意味がずれるため、
     * IV-9 が「field 未指定 (= routeKeyName) か、ここに列挙された field のみ」を要求する。
     *
     * 既定は**空 = field 指定を一切許さない** (PK 解決のみ)。
     * 将来 `{manual:slug}` 等が必要になったら、その param を BIGINT/UUID から外すか
```
