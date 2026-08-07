# T134 実装レビュー (Round 1)

以下はアプリの使命・禁止事項・思考原則である。レビューはこれらに照らして行うこと。

## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
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

## system: あなたの役割

あなたは Laravel 12 + Svelte 5 (runes) + Inertia.js + TypeScript のコードレビュアーである。
TODO **T134「アプリ内招待受諾の追加と役割付き招待の撤去」** の実装差分を、詳細設計書と突き合わせてレビューせよ。

### レビュー観点

1. **設計との一致性** — 詳細設計書に書かれた施策 1〜9 が実装されているか。設計から逸脱している箇所があれば、その逸脱が正当か (より良い判断か / 単なる実装漏れか) を判定せよ。
2. **正確性** — ロジックの誤り、境界条件、存在秘匿 (一律 404) の穴、TOCTOU / 競合、トランザクションとロック順序。
3. **PHPStan level 10 適合性** — 型の widen / baseline / `@phpstan-ignore` は禁止。null 安全性。
4. **DTO / Inertia パターン** — `response()->json()` 直書き禁止。Inertia props は DTO の `toArray()` shape。
5. **テスト網羅性** — 各施策に対応するテストがあるか。deny-by-default の目録型 gate が空振りしないか。テストが実装の写像になっていないか (トートロジー)。
6. **セキュリティ** — 存在オラクル、cross-org、PII (CipherSweet / whereBlind)、認可 gate、throttle レーン、mass-assignment。
7. **DESIGN.md 準拠** — color / radius / typography は design token 経由で参照し hex 直書き (`#RRGGBB`) を増やさない。token 値を変更する diff は `resources/css/tokens.css` と同一 diff 内で同期しているか。
8. **Atomic Design 準拠** — `atoms → molecules → organisms → features/{domain} → templates → pages` の単方向 import のみ。atom は単機能・状態を持たない。アイコンは `@lucide/svelte` のみ (SVG 直書きを増やさない)。

### 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
  - [Critical] = 本番で壊れる / セキュリティ不変条件を破る / 設計の必須要素が欠けている
  - [Warning] = 保守性・一貫性の問題、テストの穴
  - [Suggestion] = 好みの範囲
- 最後に **全体判定: APPROVED または CHANGES_REQUESTED** を明記する
- **既に APPROVED 済みの詳細設計を蒸し返さない**。設計判断そのものへの異論は、実装が設計どおりである限り [Suggestion] 止まりにすること。実装が設計に反している場合のみ [Critical]/[Warning] にせよ。

---

## user: レビュー対象

### 前提となるリポジトリ規約 (抜粋)

- Pest + `RefreshDatabase` グローバル適用 + `--parallel`。個別 `DatabaseTransactions` 禁止。テストデータは Factory。
- 変更系 route は `Gate::authorize` を通すか、`ControllerAuthorizationGateTest` の exemption inventory へ 30 文字以上の理由付きで登録する (deny-by-default)。
- 1 個以上の route parameter を持つ named route は `NestedRouteDefenseInventory` へ登録必須。手動解決は `RouteBindingTypes::MANUALLY_RESOLVED` にも登録する。
- named limiter のキーは `{レーン}:{種別}:{値}`。自前 route の inline throttle は T125 で全廃済み (`InlineThrottleInventoryTest` の enum に自前 route 向け case が無いため登録不能)。actor/IP レーンのキー組み立ては `App\Support\Http\RateLimiterKeys::actorOrIp()` が唯一の入口。
- PII (email/name) は CipherSweet。検索は `whereBlind()` (平文 where は hit しない)。
- component 階層は `atoms → molecules → organisms → features/{domain} → templates → pages` の単方向 import のみ。
- 層 2 (テナント境界 = 404) は層 3 (認可 = 403) より前。逆にすると存在が漏れる。

### 詳細設計書 (APPROVED 済み)

````markdown
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
- **email の正規化契約**: `App\Support\EmailNormalizer` は inquiry / billing contact 専用で、
  **User の email は登録時に正規化していない**（`CreateNewUser` は validated 値をそのまま保存する）。
  招待側 `inviteMember` も入力 email をそのまま保存する。したがって
  「招待は `Foo@example.com` 宛 / ログインは `foo@example.com`」が実運用で起こりうる。
  **これは本件が持ち込む新しい非対称ではない** — 既存の `emailBelongsToMember` /
  `hasPendingInvitation` / `acceptInvitationIfValid` の email 一致判定もすべて同じ完全一致である。
  本件では**挙動を変えず契約を明記する**（施策 9 で `docs/architecture.md` へ）:
  大小差は 0 件 = 404 に倒れる（fail-secure）/ メール token 経路は `token_hash` 照合なので
  影響を受けず従来どおり受諾できる / 正規化するなら既存全レコードの blind index 再計算と
  全 `whereBlind` 呼び出し元の同時変更を伴う別作業になる。

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

決定的な作り方は **SQL の形で当てる**（取得回数で当てない — `acceptPendingInvitation` は
「下見 → ロック下再解決 → `joinOrganization` 内のロック取得」で回数が経路ごとに変わり、
回数依存は実装変更に脆いため）:

```php
// tests/Feature/Organization/InvitationAcceptRaceTest.php の helper
function revokeOnLockedRead(int $invitationId): void
{
    $fired = false; // one-shot。自分の UPDATE による再入を止める
    DB::beforeExecuting(function (string $query, array $bindings) use ($invitationId, &$fired): void {
        if ($fired) {
            return;
        }
        // joinOrganization がロック下再検証のために発行する SELECT ... FOR UPDATE を検出する。
        // id は必ず placeholder になるため **bindings 側で対象 id を確認する**
        // (SQL 文字列だけでは対象 id を判定できない)
        $lower = strtolower($query);
        if (! str_contains($lower, 'organization_invitations') || ! str_contains($lower, 'for update')) {
            return;
        }
        $stringBindings = array_map(static fn (mixed $b): string => is_scalar($b) ? (string) $b : '', $bindings);
        if (! in_array((string) $invitationId, $stringBindings, true)) {
            return; // 別の招待行のロック読取には干渉しない
        }
        $fired = true;
        // 同一接続・同一トランザクション内なので自分のロックと競合しない。
        // 取り消しが割り込んだのと同じ状態を作る
        DB::table('organization_invitations')->where('id', $invitationId)->update(['revoked_at' => now()]);
    });
}
```

`joinOrganization` の `lockForUpdate()->firstOrFail()` が更新後の行を読み、
`isRevoked() === true` で `false` を返す。

**`DB::beforeExecuting()` の callback は解除できない**（Laravel に unregister API が無い）。
したがって「後始末する」とは書かない。代わりに **callback 自身が one-shot で恒久的に inert になる**
設計にする:

- `$fired` を立てた後は即 `return` するだけになり、以降のクエリに一切干渉しない
  （自分が発行する UPDATE による再入もこれで止まる）
- 発火条件は **(a) `organization_invitations` を対象とし (b) `for update` を含み
  (c) bindings に対象 invitation id を含む SELECT** の 3 条件すべて。
  他テーブル・他 id のクエリでは何もしない
  （id は placeholder になるため SQL 文字列ではなく **bindings** で判定する）。
  条件に一致するクエリが 1 度も来なければ helper は何もせず、
  結果としてテストは「`false` 分岐に入らなかった」ことで**明示的に fail する**（黙って通らない）
- **テスト間の漏れ**: Pest は各テストでアプリケーション（＝ `DatabaseManager` と
  `Connection` インスタンス）を再構築するため callback は次のテストへ持ち越されない。
  ただしこれは前提であって保証ではないので、**同一テスト内で 2 回目以降のクエリに
  干渉しないこと**を明示的にアサートするテストを 1 本置く
  （helper 適用後に別の招待を普通に受諾できること = inert 化の behavioral proof）

- [ ] `acceptInvitation はロック下再検証の敗北を ValidationException へ畳む` —
      メッセージが事前検証と同一（`この招待は無効です。`）であること
- [ ] `acceptInvitationIfValid はロック下再検証の敗北で null を返し、現在組織を書き換えない` —
      `$user->fresh()->current_organization_id` が呼び出し前と不変
- [ ] `acceptPendingInvitation はロック下再検証の敗北で null を返す`
- [ ] いずれのケースでも `organization_user` に行が増えていないこと
- [ ] `helper は one-shot で、同一テスト内の後続クエリに干渉しない` — helper 適用後に
      別の有効な招待を普通に受諾できること（inert 化の behavioral proof。
      `DB::beforeExecuting` が解除できない API であることの埋め合わせ）

`tests/Architecture/MembershipWriteLockInventoryTest.php`（既存に検査を 1 本追加。
**`false` を捨てた呼び出しの静的検出**）

`token_get_all()` でサービスファイルをトークン化し、**呼び出し式の開始位置まで遡ってから**
判定する（`joinOrganization` の直前は必ず `->` なので、直前 1 トークンでは判定できない）。

手順:

1. `T_STRING` で値が `joinOrganization`、かつ次の有意トークンが `(` のものを拾う
2. **メソッド宣言を除外する** — 直前の有意トークンが `T_FUNCTION` なら
   `private function joinOrganization(` の宣言そのものなので skip する
   （除外しないと宣言が「未知の呼び出し形」として必ず fail する）
3. 残ったものから**後方へ**有意トークン（空白 `T_WHITESPACE` / コメント `T_COMMENT` `T_DOC_COMMENT` を飛ばす）を辿り、
   `T_OBJECT_OPERATOR`（`->`）→ `T_VARIABLE`（`$this`）の 2 つを越える。
   この形でなければ**未知の呼び出し形として fail**（deny-by-default。人のレビューを通す）
4. さらにその 1 つ前の有意トークンを見る。それが `;` / `{` / `}` のいずれか
   （= **式文として戻り値を破棄している**形）なら fail

```php
$this->joinOrganization(...);                 // ← 直前が ; または { → fail
if (! $this->joinOrganization(...)) { ... }   // ← 直前が ! → pass
$joined = $this->joinOrganization(...);       // ← 直前が = → pass
return $this->joinOrganization(...);          // ← 直前が T_RETURN → pass
```

- 許可リストではなく**破棄形の拒否**にするのは、`&&` / `||` / `(` / `,` など
  値が使われる文脈が無数にあり、許可側を列挙すると正しい実装を落とすため。
- 文字列一致（`if (! $this->joinOrganization(`）にしないのは、
  `$joined = $this->joinOrganization(...); if (! $joined)` という正常な実装で壊れ、
  かつコメント中の同一文字列で誤って通るため。トークン種別で見ればどちらも起きない。
- **空振り防止は exact-fit**: 拾えた呼び出しが **ちょうど 3 件**であること
  （`expect($callCount)->toBe(3)`）。現在の呼び出し元は
  `acceptInvitation` / `acceptInvitationIfValid` / `acceptPendingInvitation` の 3 つ。
  「3 件未満なら fail」だとセレクタ崩壊は検出できるが**呼び出し元の増加が素通り**する。
  exact-fit なら次の 1 本が必ず「この数値を変える差分」として現れ、
  「その経路でも `false` を正しく消費しているか」の再レビューを強制できる
  （`ThrottleCoverageInventoryTest` の exemption cap と同じ流儀）。
- この静的検査は「**消費している形か**」だけを見る。
  「消費した結果の契約が正しいか」は `InvitationAcceptRaceTest`（behavioral）が見る。
  2 本は役割が違うので**併存させる**。
- [ ] 負のコントロール: 実装時に `acceptPendingInvitation` 内の呼び出しを
      `$this->joinOrganization(...);` （戻り値破棄）へ一時的に書き換えて fail することを確認する

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
        // abort_if ではなく if + abort にする (abort() は never を返すため PHPStan が
        // 以降で $organization を非 null に narrow できる)。
        if ($organization === null) {
            abort(404);
        }

        // 現在組織は切り替えない契約のため、参加先の画面ではなく dashboard へ着地させる
        // (既存 invitations.accept.store の成功応答と同形。intended は使わない = 禁止事項 7)。
        return redirect()->route('dashboard')
            ->with('success', "「{$organization->name}」に参加しました");
    }
}
```

**gate 登録**（対応が要る gate は 6 本。うち inventory への明示登録は 5 本）:

> **なぜ `NestedRouteDefenseInventory` への登録が必須か**（nested route ではないから不要、
> という誤解を防ぐ）:
> 同 inventory の class docblock が「**母集団は 1 個以上の parameter を持つ named route**
> （旧実装は 2 個以上だった）」と明記し、`candidates()` も
> `if ($route->parameterNames() === []) { continue; }` だけで絞っている。
> **nested かどうかは母集団の条件ではない**（2+param に絞ったせいで単独 param の route が
> 丸ごと母集団から外れ、実際に穴が残ったのが audit-cycle-2 High-1）。
> したがって 1 param の `invitations.accept-in-app` は登録しないと
> `NestedRouteIdorDefenseTest` が「分類漏れ」で fail する = 登録は選択肢ではない。
> 先例として `'notifications.open' => ['notification' => $manual]` が**完全に同形**
> （個人スコープ・単一 param・controller が `$user->notifications()` から手動解決）。
> さらに `TenantBoundaryOrderingTest` 検査 3a は
> **inventory で `ManualOwnerScopedResolution` を宣言した param に対してのみ**
> 「action 引数が Model 型でない」「`MANUALLY_RESOLVED` に route identity ごと登録済み」
> 「explicit binder が無い」を機械検証する。inventory に入れないとこの 3 検査が走らず、
> 存在オラクル防御が無検証になる。

| # | gate | 登録内容 |
|---|------|---------|
| 1 | `tests/Support/Routing/NestedRouteDefenseInventory.php` | `'invitations.accept-in-app' => ['invitation' => $manual],` を「個人スコープ」ブロックへ追加（`$manual = ManualOwnerScopedResolution`。これは**解決方式の宣言**でありモデル種別ではない） |
| 2 | `app/Http/Routing/RouteBindingTypes.php` | `MANUALLY_RESOLVED` に `'invitation' => ['routes' => ['invitations.accept-in-app'], 'reason' => '...']` を追加（`{invitation}` は既に `BIGINT` 登録済みなので pattern は継続適用される） |
| 3 | `tests/Architecture/ControllerAuthorizationGateTest.php` | `'invitations.accept-in-app' => [$selfScoped, '...']`（理由は下記） |
| 4 | `tests/Architecture/MembershipWriteLockInventoryTest.php` | `delegatedToLocked` に `acceptPendingInvitation`、`exempt` に `pendingInvitationsFor` / `pendingInvitationCountFor`。加えて**戻り値消費のトークナイザ検査を 1 本追加**（施策 3） |
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
- 404 は `if ($organization === null) { abort(404); }` の形で書く。
  `abort_if()` は `never` を返さないため PHPStan が以降で非 null に narrow できない

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
     *
     * ★禁止事項 8 との関係: 「必須条件未充足を理由に disabled にする」ことはしない
     *   (前提条件で押せないボタンを作らない)。in-flight 中は既存 Button atom の
     *   `loading` (= disabled + aria-busy) を使う — これは二重送信防止であって
     *   必須条件未充足による無効化ではなく、同画面の招待送信ボタン
     *   (`loading={inviteForm.processing}`) と同じ既存流儀である。
     *   加えてハンドラ側でも in-flight 中の再入を無視する (二重の送信ガード)。
     * ★DS: 色 / radius / typography は token 経由のみ (hex 直書き・独自 radius を増やさない)。
     *   アイコンは @lucide/svelte の Mail のみ (SVG 直書きを新設しない)。
     *   Card の入れ子を作らない (この component 自身が 1 枚の Card)。
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
        <p class="mt-1 text-caption text-text-secondary">
            あなた宛の招待です。参加すると、その組織のメンバーになります。
        </p>
        <ul class="mt-4 divide-y divide-border">
            {#each invitations as invitation (invitation.id)}
            <li
                class="flex flex-wrap items-center gap-3 py-3"
                data-testid={`pending-invitation-${invitation.id}`}
            >
                <span class="inline-flex size-8 shrink-0 items-center justify-center rounded-md bg-primary-soft text-primary" aria-hidden="true">
                    <Mail class="size-4" />
                </span>
                <p class="min-w-0 grow truncate text-body text-text">{invitation.organizationName}</p>
                <Badge tone="neutral" size="sm">{invitation.roleLabel}</Badge>
                <span class="text-caption text-text-secondary">期限 {invitation.expiresAt}</span>
                <Button
                    onclick={() => accept(invitation)}
                    loading={acceptingId === invitation.id}
                    testId={`accept-invitation-${invitation.id}`}
                >
                    参加する
                </Button>
            </li>
            {/each}
        </ul>
    </Card>
{/if}
```

> 使用クラスはすべて既存の DS token / utility（`text-h3` `text-body` `text-caption`
> `text-text` `text-text-secondary` `bg-primary-soft` `text-primary` `divide-border`
> `rounded-md`）で、`NotificationListItem.svelte` / `Admin/Users.svelte` が既に使っている語彙に揃える。
> hex 直書き・独自 radius・新規 SVG は導入しない。`resources/css/tokens.css` の変更も無い。

> **Atomic Design 上の位置**: `features/invitations/` に置く（domain 固有の操作を持つため
> molecule ではない）。使う側は `pages/Notifications/Index.svelte`（pages → features は順方向）。
> import するのは `atoms/`（Card / Button / Badge）と `@inertiajs/svelte` / `@lucide/svelte` のみで、
> 他 domain の `features/` を横参照しない（`atomic-import-graph.test.ts` の単方向規約）。

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
- [ ] `初期描画では参加ボタンが disabled 属性を持たない`（禁止事項 8 の回帰封じ。
      **in-flight 中の disabled は対象外** — 二重送信防止であって必須条件未充足による
      無効化ではなく、既存の `loading={inviteForm.processing}` と同じ流儀）
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
     *
     * molecule に置くのは、atom (Link + Lucide icon) の組合せだけで状態も domain 操作も
     * 持たないため (NotificationBell と同じ位置づけ)。
     * ★DS: 色 / radius / typography は token 経由のみ。SVG 直書きを新設しない。
     */
    interface Props {
        pendingCount: number;
        testId?: string;
    }

    let { pendingCount, testId = "pending-invitations-notice" }: Props = $props();
</script>

{#if pendingCount > 0}
    <Link
        href="/notifications"
        class="flex items-center gap-2 rounded-md border border-border bg-primary-soft/40
            px-4 py-2 text-body text-text hover:bg-primary-soft"
        data-testid={testId}
    >
        <Mail class="size-4 text-primary" aria-hidden="true" />
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

### デプロイ手順（expand/contract の contract 側。**順序が安全境界**）

列 drop は破壊的変更のため、ローリングデプロイ中の**旧プロセスと新 schema の同居**を避ける。

| 段階 | やること | なぜ |
|---|---|---|
| 1 | **コードを先にデプロイ**（`project_role` を読み書きしないコード） | 旧コードの `inviteMember` は `forceFill(['project_role' => ...])` を実行するため、列が先に消えると**存在しない列への INSERT で 500** になる（read 側は属性が欠落するだけで null 相当に落ちるので 500 にはならない = 壊れるのは書き込み側） |
| 2 | **旧プロセスが残っていないことを確認**（`php artisan queue:restart` 済み / web worker の入れ替え完了） | queue worker も `OrganizationMembershipService` を持つ |
| 3 | **migration を流す**（列 + check 制約の drop） | ここで初めて schema を縮める |

**新旧 HTTP 契約が混在する時間帯の扱い**（段階 1 のローリング更新中に必ず生じる）:

| 混在の向き | 起きること | 収束先 |
|---|---|---|
| 新 UI（`organization_member` を送る） → 旧 backend（`Rule::enum(AdminConsoleRole)`） | validation 422 | `StoreOrganizationInvitationRequest::messages()` の既存文言「ロールの指定が不正です。画面を再読み込みしてやり直してください。」 |
| 旧 UI（`editor` / `shooter` を送る） → 新 backend（`Rule::enum(OrganizationRole)->except([Owner])`） | validation 422 | 同上（**同一文言**） |

**両方向とも 422 + 同一の再読込導線に収束する**（500 にもデータ破損にもならない）。
対象は管理者だけが使う低頻度の招待送信フォームで、回復は画面再読込 1 手。
したがって本設計は次を採る:

- **既定は原子的切替**（単一インスタンスの再起動でコードと assets を同時に入れ替える）。
  この場合、契約混在の窓は再起動の瞬間だけで実質存在しない。
- **複数インスタンスのローリング更新を行う場合は、上記の一時的な 422 を明示的に受容する**
  （後方互換の並走コードを入れない = 思考原則 3。互換のために `AdminConsoleRole` を
  受け続ける分岐を残すと、それを消す差分がまた必要になる）。
  運用手順に「切替中は招待送信の 422 が一時的に増えうる。回復は画面再読込」と記載し、
  切替後に 422 が収束することを確認する。
- **どちらの場合も段階 2（旧プロセスの排除）→ 段階 3（migration）の順序は崩さない**。
  422 は回復可能だが、列を先に消したときの「存在しない列への INSERT」は 500 で回復導線が無い。

- **rollback**: `down()` で列と check 制約は復元できるが、**値は復元できない**。
  値を失った pending 招待は「参加後に管理画面でロールを割り当てる」運用に倒れるだけで、
  **参加自体は成功する**（`joinOrganization` は org 参加とロール付与だけを行う）。
- `php artisan route:cache` は毎デプロイ再生成する（AGENTS.md ドメイン規約 5。
  新 route の throttle 後付けが cached 起動で skip されるのを防ぐ）。

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

DB 側（**実行先は worktree のテスト DB / CI DB に限定する**。dev DB への適用は
通常のデプロイ手順、またはユーザーの明示承認による = 禁止事項 3）:

- [ ] テスト DB で migration が通ること（`composer test` が `RefreshDatabase` で毎回流す）
- [ ] `down()` で列と check 制約が復元できること（rollback 手順の確認。テスト DB で
      `migrate:rollback --step=1` → `migrate` を 1 往復させる）
- [ ] **dev DB に対して `migrate` / `migrate:fresh` をエージェント判断で実行しない**

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
- [ ] **実測値を devnotes に固定する**: `devnotes/{dir}/gate-mutation-log.md` に
      **(a) 初回の抽出結果（クエリ起点の全リスト。パス#メソッド名）**、
      **(b) 確定した `invitationResolutionSiteFloor()` の値**、
      **(c) `RecipientScopedPendingSet` の exact-fit cap の値** を記録し、
      M1〜M7 の赤化結果と併せてレビュー対象に含める
      （目録の中身そのものがレビューの対象であり、mutation ログだけでは不十分）

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
- **email を正規化保存していない**こと（`EmailNormalizer` は inquiry / billing contact 専用）と、
  その帰結（アプリ内受諾は大小差で 404 に倒れる / メール token 経路は影響を受けない /
  正規化するなら blind index 再計算を伴う別作業）
- 共有 prop `invitations.pendingCount` は closure のため
  **`only:` 指定の partial reload では評価されない**（件数はフルページ遷移時に更新される。
  受諾直後は dashboard へフル遷移するため実害はない）
- **デプロイ順序**（施策 7 の expand/contract）を `docs/architecture.md` からも参照できるようにする

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
| 完了条件 | AGENTS.md の `VERIFICATION_COMMANDS` **全部**が green: `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`。加えて `devnotes/{dir}/gate-mutation-log.md` に **(a) 施策 8 の抽出結果全リスト / (b) floor 実測値 / (c) exact-fit cap 実測値 / (d) mutation M1〜M7 の赤化結果 / (e) 戻り値消費トークナイザ検査の負のコントロール結果** が残っていること |

---

## 最終確認（使命・禁止事項チェック）

| 観点 | 確認 |
|---|---|
| 使命への寄与 | 招待受諾は現場ユーザーがアプリに入る最初の関門。現状はそこがアプリの外（メール探し）に落ちており、スマホ (PWA) 中心の利用では特に高い障壁になる。本件はその障壁を除き、標準作業→シナリオ→撮影の本線へ到達させる |
| 禁止事項 1（テストなし完了なし） | 全 9 施策にテスト計画あり。新しい不変条件は `InvitationResolutionInventoryTest`（目録型 deny-by-default）+ 既存 5 gate への登録で機械強制 |
| 禁止事項 2（PHPStan widen / baseline） | 各施策に PHPStan 適合チェック。`abort_if` を `if + abort` に変えたのも narrowing を型で通すため（widen ではない） |
| 禁止事項 3（dev DB 破壊操作） | migration の実行先を worktree テスト DB / CI DB に限定。dev DB はデプロイ手順またはユーザー承認のみ |
| 禁止事項 4（`response()->json()` 直書き） | 新 route は redirect のみ。Inertia props は DTO の `toArray()` shape |
| 禁止事項 7（`redirect()->intended()`） | 成功は `redirect()->route('dashboard')->with('success', ...)` 固定。intended は使わない |
| 禁止事項 8（必須条件未充足の disabled） | 受諾ボタンは初期描画で disabled を出さない。in-flight の `loading` は二重送信防止であり既存 `Button` atom の sanctioned な流儀 |
| 思考原則 2（今必要なものだけ） | 通知 payload への招待 id 追加を不採用。新 GET 画面を作らず既存 `notifications.index` に載せる。席満杯分岐を作らない（aicue に席上限が無い） |
| 思考原則 3（後方互換の並走なし） | `project_role` は列ごと撤去。`AdminConsoleRole` を招待側で受け続ける互換分岐を残さない |
| 思考原則 5（テストファースト） | 実装順序を「gate → 本体」にし、各段で対象テストを赤くしてから実装する。gate mutation M1〜M7 の赤化記録を完了条件に含める |
| セキュリティ不変条件 2（認可より前に 404） | `{invitation}` は手動解決 + 一律 404。`NestedRouteDefenseInventory` / `TenantBoundaryOrderingTest` 検査 3a で機械検証 |
| セキュリティ不変条件 6（PII は CipherSweet） | 宛先照合は `whereBlind`。受信者視点 DTO に email を載せない |
| セキュリティ不変条件 9（変更系は認可を通る） | `ControllerAuthorizationGateTest` に `SelfScopedResource` + 理由で登録（403 を返すと存在が漏れるため Gate を置かない、を目録に残す） |
| ドメイン規約 5（throttle） | named limiter `invitation-accept-in-app` を新設（10/min・`{レーン}:{種別}:{値}`）。inline を使わず既存 bucket を巻き添えにしない |

## レビュー履歴

| フェーズ | ラウンド | 結果 |
|---|---|---|
| 概念設計 (gpt-5.5 / medium) | 1 | CHANGES_REQUESTED（Critical 1: 戻り値型の記述矛盾） |
| 概念設計 | 2 | CHANGES_REQUESTED（Critical 1: `false` を既存呼び出し元が無視する整理の不成立） |
| 概念設計 | 3 | CHANGES_REQUESTED（Warning 4: gate 数の誤記 / token 経路のスコープ表現 / 戻り値消費テストの脆さ / throttle テスト表現） |
| 概念設計 | 4 | **APPROVED** |
| 詳細設計 (gpt-5.5 / high) | 1 | CHANGES_REQUESTED（Critical 2。うち 1 件は反論しレビュアーが撤回） |
| 詳細設計 | 2 | CHANGES_REQUESTED（Critical 1: トークナイザ判定が成立しない） |
| 詳細設計 | 3 | CHANGES_REQUESTED（Critical 1: メソッド宣言の誤抽出） |
| 詳細設計 | 4 | **APPROVED** |
````

### 実装差分 (git diff HEAD -- app/ resources/ tests/ routes/ config/ bootstrap/ docs/)

````diff
diff --git a/app/DataTransferObjects/Admin/InvitationRowData.php b/app/DataTransferObjects/Admin/InvitationRowData.php
index 124d9f7..27cceb9 100644
--- a/app/DataTransferObjects/Admin/InvitationRowData.php
+++ b/app/DataTransferObjects/Admin/InvitationRowData.php
@@ -4,7 +4,6 @@
 
 namespace App\DataTransferObjects\Admin;
 
-use App\Enums\MemberRoleState;
 use App\Enums\OrganizationRole;
 use App\Models\OrganizationInvitation;
 use Illuminate\Support\Carbon;
@@ -12,24 +11,23 @@
 
 /**
  * ユーザー管理画面 (Admin/Users) の招待中 1 行分。TS 側 types/admin.ts の InvitationRow と対で保守。
- * 表示状態は招待の org role + project_role から導出する (受諾後のメンバー行と同じ 5 値語彙)。
+ * 招待は org ロールだけを持つ (役割付き招待は裁定 AG-079 で撤去)。
+ * MemberRoleState (受諾後の 5 値表示状態) は使わない — 招待中の行を「未割当」と表示するのは
+ * 意味的に誤り (割当漏れではなく、まだ参加していないだけ)。
  */
 final readonly class InvitationRowData
 {
     public function __construct(
         public int $id,
         public string $email,
-        public string $roleState, // MemberRoleState value
-        public string $roleLabel,
+        public string $role,      // OrganizationRole value
+        public string $roleLabel, // OrganizationRole label
         public string $expiresAt, // Y-m-d
     ) {}
 
     public static function fromInvitation(OrganizationInvitation $invitation): self
     {
-        $state = MemberRoleState::derive(
-            OrganizationRole::from($invitation->role),
-            $invitation->project_role,
-        );
+        $role = OrganizationRole::from($invitation->role);
 
         $expiresAt = $invitation->getAttribute('expires_at');
         Assert::isInstanceOf($expiresAt, Carbon::class);
@@ -37,8 +35,8 @@ public static function fromInvitation(OrganizationInvitation $invitation): self
         return new self(
             id: $invitation->id,
             email: $invitation->email,
-            roleState: $state->value,
-            roleLabel: $state->label(),
+            role: $role->value,
+            roleLabel: $role->label(),
             expiresAt: $expiresAt->toDateString(),
         );
     }
diff --git a/app/DataTransferObjects/Invitations/PendingInvitationForUserDto.php b/app/DataTransferObjects/Invitations/PendingInvitationForUserDto.php
new file mode 100644
index 0000000..f24e8f8
--- /dev/null
+++ b/app/DataTransferObjects/Invitations/PendingInvitationForUserDto.php
@@ -0,0 +1,71 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Invitations;
+
+use App\Enums\OrganizationRole;
+use App\Models\Organization;
+use App\Models\OrganizationInvitation;
+use Illuminate\Support\Carbon;
+use Webmozart\Assert\Assert;
+
+/**
+ * **受信者視点**の保留中招待 1 件 (アプリ内受諾 UI 用)。
+ *
+ * 管理者視点の InvitationRowData とは契約を分離したままにする (裁定 AG-113):
+ * 管理者は「誰を招待したか (email)」を見る面、受信者は「どこへ参加できるか」を見る面であり、
+ * 開示すべき項目が違う。似ているからと統合しない (思考原則 4)。
+ *
+ * **開示するのはこの 4 つだけ**。email (自分の値だが載せる必要がない) / token_hash /
+ * accepted_at・revoked_at・expires_at の生値 / invited_by_user_id / organization_id は出さない。
+ * 受諾 URL も持たせない (署名も token も無い経路のため、サーバが URL を配る意味が無く
+ * 開示面だけ増える。front が route から組む)。
+ *
+ * TS 側 resources/js/types/invitation.ts の PendingInvitation と対で保守する。
+ */
+final readonly class PendingInvitationForUserDto
+{
+    public function __construct(
+        public int $id,
+        public string $organizationName,
+        public string $roleLabel,
+        public string $expiresAt, // Y-m-d
+    ) {}
+
+    /**
+     * scopeActivePendingForEmail で解決済みの招待から組み立てる。
+     *
+     * 呼び出し側で `->format()` を書かない (日時の文字列化責務をここへ集約する)。
+     * organization は scope の whereHas で存在が保証されているが、
+     * relation の遅延解決が null を返す可能性を型で潰すため Assert で narrow する。
+     */
+    public static function fromInvitation(OrganizationInvitation $invitation): self
+    {
+        $organization = $invitation->organization;
+        Assert::isInstanceOf($organization, Organization::class);
+
+        $expiresAt = $invitation->getAttribute('expires_at');
+        Assert::isInstanceOf($expiresAt, Carbon::class);
+
+        return new self(
+            id: $invitation->id,
+            organizationName: $organization->name,
+            roleLabel: OrganizationRole::from($invitation->role)->label(),
+            expiresAt: $expiresAt->toDateString(),
+        );
+    }
+
+    /**
+     * @return array{id: int, organizationName: string, roleLabel: string, expiresAt: string}
+     */
+    public function toArray(): array
+    {
+        return [
+            'id' => $this->id,
+            'organizationName' => $this->organizationName,
+            'roleLabel' => $this->roleLabel,
+            'expiresAt' => $this->expiresAt,
+        ];
+    }
+}
diff --git a/app/Enums/Security/InvitationResolutionScope.php b/app/Enums/Security/InvitationResolutionScope.php
new file mode 100644
index 0000000..0a8eafe
--- /dev/null
+++ b/app/Enums/Security/InvitationResolutionScope.php
@@ -0,0 +1,42 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Security;
+
+/**
+ * OrganizationInvitation を解決する経路の分類 (存在秘匿の視点別)。
+ *
+ * 招待は「誰の視点から引くか」で開示していい情報が変わる。視点を混ぜると
+ * 受信者向けの経路が管理者向けの絞り込みを使ってしまい、他人宛の招待に到達できる
+ * = 存在オラクルになる。**例外機構は設けない** (分類外は fail)。
+ */
+enum InvitationResolutionScope: string
+{
+    /**
+     * 受信者視点。認証ユーザー宛の有効 pending 集合
+     * (OrganizationInvitation::scopeActivePendingForEmail) からのみ解決する。
+     * 不成立はすべて 0 件 = 呼び出し側は一律 404 に畳める。
+     */
+    case RecipientScopedPendingSet = 'recipient_scoped_pending_set';
+
+    /** 平文 token の sha256 照合で解決する (署名なし token URL 経路)。 */
+    case TokenHashLookup = 'token_hash_lookup';
+
+    /** 管理者視点。$organization->invitations() の relation 経由でのみ解決する。 */
+    case OrganizationScoped = 'organization_scoped';
+
+    /** モデル自身が持つ解決口 / scope の定義そのもの。 */
+    case ModelInternal = 'model_internal';
+
+    /**
+     * **既に他の経路で解決済み**の招待を、同一トランザクション内で主キー指定して
+     * 行ロック付きで読み直すだけの経路 (`whereKey($invitation->id)->lockForUpdate()`)。
+     *
+     * 新しい到達経路を増やさない (id の出所が上位 4 分類のいずれかで既に絞り込み済み)
+     * ため存在秘匿の視点を持たないが、**クエリ起点であることに変わりはない**ので
+     * 目録には現れる。この分類を「未解決の外部入力 id で引く」用途に使ってはならない
+     * (その場合は受信者視点 / 管理者視点のどちらかへ寄せる)。
+     */
+    case LockedRowReload = 'locked_row_reload';
+}
diff --git a/app/Http/Controllers/NotificationController.php b/app/Http/Controllers/NotificationController.php
index beb963f..3ccdf82 100644
--- a/app/Http/Controllers/NotificationController.php
+++ b/app/Http/Controllers/NotificationController.php
@@ -4,10 +4,12 @@
 
 namespace App\Http\Controllers;
 
+use App\DataTransferObjects\Invitations\PendingInvitationForUserDto;
 use App\DataTransferObjects\Notification\NotificationListItemData;
 use App\Enums\Notification\NotificationType;
 use App\Models\User;
 use App\Services\Notification\NotificationCenterService;
+use App\Services\Organization\OrganizationMembershipService;
 use Illuminate\Database\Eloquent\Builder;
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
@@ -31,7 +33,10 @@
  */
 class NotificationController extends Controller
 {
-    public function __construct(private readonly NotificationCenterService $notifications) {}
+    public function __construct(
+        private readonly NotificationCenterService $notifications,
+        private readonly OrganizationMembershipService $membership,
+    ) {}
 
     /** 通知一覧 (全 org 横断 = 自分宛のみで構造的に閉じる) */
     public function index(Request $request): Response
@@ -51,6 +56,12 @@ public function index(Request $request): Response
             // shared prop notifications.unreadCount がページ prop `notifications` (配列) と
             // キー衝突するため (詳細は Index.svelte JSDoc)。
             'unreadCount' => $this->notifications->unreadCountFor($user),
+            // 自分宛の受諾可能な招待 (受信者視点 DTO)。共有 prop の件数・受諾の解決と
+            // **同一 scope** から算出する (裁定 AG-113 必須要素 (b))
+            'pendingInvitations' => array_map(
+                fn (PendingInvitationForUserDto $dto): array => $dto->toArray(),
+                $this->membership->pendingInvitationsFor($user),
+            ),
             // 既存 ManualListItem のページャ shape (ProjectController::manualRows) と同形
             'meta' => [
                 'current_page' => $paginator->currentPage(),
@@ -84,9 +95,14 @@ public function open(Request $request, string $notification): RedirectResponse
                 ->with('info', '対象の動画マニュアルは削除されています。'),
             $item->type === NotificationType::TicketBalanceLow => redirect()
                 ->route('billing.tickets.show', [], 303),
-            $item->type === NotificationType::InvitationReceived => redirect()
-                ->route('notifications.index', [], 303)
-                ->with('info', '招待はメールの受諾リンクから参加してください。'),
+            // 招待通知: 受諾可能な一覧が出る通知センターへ戻す。
+            // ★通知 payload は招待 id を持たないため「この招待」を特定できない。
+            //   したがって flash は**集合表現**にする (件数 0 のときだけ説明を出す)。
+            //   件数は受諾の解決と同一 scope から算出する。
+            $item->type === NotificationType::InvitationReceived => $this->membership->pendingInvitationCountFor($user) > 0
+                ? redirect()->route('notifications.index', [], 303)
+                : redirect()->route('notifications.index', [], 303)
+                    ->with('info', '現在有効な招待はありません (取り消し・期限切れ・参加済みの可能性があります)。'),
             // 未知 type (enum⇔DB ドリフト時の防御): 既読化のみ・汎用文言
             default => redirect()
                 ->route('notifications.index', [], 303)
diff --git a/app/Http/Controllers/Organizations/AcceptInvitationInAppController.php b/app/Http/Controllers/Organizations/AcceptInvitationInAppController.php
new file mode 100644
index 0000000..f428ba8
--- /dev/null
+++ b/app/Http/Controllers/Organizations/AcceptInvitationInAppController.php
@@ -0,0 +1,53 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Controllers\Organizations;
+
+use App\Http\Controllers\Controller;
+use App\Models\User;
+use App\Services\Organization\OrganizationMembershipService;
+use Illuminate\Http\RedirectResponse;
+use Illuminate\Http\Request;
+use Webmozart\Assert\Assert;
+
+/**
+ * アプリ内からの招待受諾 (POST invitations.accept-in-app)。裁定 AG-113 標準形 v1。
+ *
+ * 受諾の根拠は URL の所持ではなく「auth 済み (middleware) ∧ email 確認済み (middleware) ∧
+ * ログイン者 email = 招待の宛先 (Service の scope)」。
+ *
+ * **認可 (Gate) を持たない**のは意図的である:
+ * 受諾前の user は対象組織の非メンバーで組織 Policy は構造的に必ず拒否になるうえ、
+ * 403 を返すこと自体が「その招待は実在する」を教える口になる。代わりに
+ * OrganizationMembershipService::acceptPendingInvitation() が
+ * $user 宛の有効 pending 集合からしか解決せず、**すべての不成立を 404 に畳む**
+ * (ControllerAuthorizationGateTest に SelfScopedResource として理由付きで登録済み)。
+ *
+ * **{invitation} は string で受ける**。型を Model にすると implicit binding が復活し、
+ * 不在 id だけが binding 段で 404 になって存在オラクルが生まれる
+ * (TenantBoundaryOrderingTest 検査 3a が action 引数型を機械検証する)。
+ */
+class AcceptInvitationInAppController extends Controller
+{
+    public function __invoke(Request $request, string $invitation, OrganizationMembershipService $membership): RedirectResponse
+    {
+        $user = $request->user();
+        Assert::isInstanceOf($user, User::class);
+
+        $organization = $membership->acceptPendingInvitation($user, $invitation);
+
+        // 業務上の受諾不能はすべて 404 (403 を返さない = 存在を教えない)。
+        // back() も flash も出さない (文脈依存の戻り先そのものが手掛かりになるため)。
+        // abort_if ではなく if + abort にする (abort() は never を返すため PHPStan が
+        // 以降で $organization を非 null に narrow できる)。
+        if ($organization === null) {
+            abort(404);
+        }
+
+        // 現在組織は切り替えない契約のため、参加先の画面ではなく dashboard へ着地させる
+        // (既存 invitations.accept.store の成功応答と同形。intended は使わない = 禁止事項 7)。
+        return redirect()->route('dashboard')
+            ->with('success', "「{$organization->name}」に参加しました");
+    }
+}
diff --git a/app/Http/Middleware/HandleInertiaRequests.php b/app/Http/Middleware/HandleInertiaRequests.php
index 4731ee8..5170289 100644
--- a/app/Http/Middleware/HandleInertiaRequests.php
+++ b/app/Http/Middleware/HandleInertiaRequests.php
@@ -7,6 +7,7 @@
 use App\Models\Organization;
 use App\Models\User;
 use App\Services\Marketing\ContactUrl;
+use App\Services\Organization\OrganizationMembershipService;
 use App\Support\Seo\SeoManager;
 use Illuminate\Http\Request;
 use Illuminate\Support\Str;
@@ -64,6 +65,19 @@ public function share(Request $request): array
             'notifications' => [
                 'unreadCount' => fn (): int => $user === null ? 0 : $user->unreadNotifications()->count(),
             ],
+            // 自分宛の受諾可能な招待の件数 (全画面横断の気づき。裁定 AG-113 必須要素 (b)(c))。
+            // ★件数は受諾の解決・一覧と**同一 scope** から算出する
+            //   (ずれると「件数は出るのに受諾できない」が起きる)。
+            // ★未ログイン・未 verified・email 空は pendingInvitationCountFor が
+            //   DB を一切引かずに 0 を返す (全リクエストで評価されるため実効的な負荷契約)。
+            // app() 解決にするのはコンストラクタ注入を増やさないため (contact prop と同じ流儀)。
+            // ★キー名を 'invitations' にしない: ページ prop 'invitations' (Admin/Users の
+            //   招待一覧) と衝突し、その画面だけ共有 prop が配列で上書きされて
+            //   横断の気づきが黙って消える (通知の unreadCount と同じ衝突クラス)。
+            'invitationInbox' => [
+                'pendingCount' => fn (): int => app(OrganizationMembershipService::class)
+                    ->pendingInvitationCountFor($user),
+            ],
             'flash' => [
                 'success' => $request->session()->get('success'),
                 'error' => $request->session()->get('error'),
diff --git a/app/Http/Requests/Organizations/StoreOrganizationInvitationRequest.php b/app/Http/Requests/Organizations/StoreOrganizationInvitationRequest.php
index dafa681..b231037 100644
--- a/app/Http/Requests/Organizations/StoreOrganizationInvitationRequest.php
+++ b/app/Http/Requests/Organizations/StoreOrganizationInvitationRequest.php
@@ -4,7 +4,7 @@
 
 namespace App\Http\Requests\Organizations;
 
-use App\Enums\AdminConsoleRole;
+use App\Enums\OrganizationRole;
 use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
 use Illuminate\Foundation\Http\FormRequest;
 use Illuminate\Validation\Rule;
@@ -12,10 +12,11 @@
 use Webmozart\Assert\Assert;
 
 /**
- * メンバー招待 (3 値遷移コマンド)。
+ * メンバー招待 (組織ロール 2 値)。
  * 認可は Controller の Gate::authorize('manageMembers') が唯一の責務。
- * 重複招待の中立検査・Default Project 存在確認は Service 側 (TOCTOU になる DB 依存検証を
- * FormRequest に置かない)。project_role はクライアントから受けず role コマンドから導出する。
+ * 重複招待の中立検査は Service 側 (TOCTOU になる DB 依存検証を FormRequest に置かない)。
+ * 招待は組織ロールだけを運ぶ (役割付き招待は裁定 AG-079 で撤去。編集者 / 撮影者は
+ * 参加後に管理画面のロール割当コマンドで付与する)。
  */
 class StoreOrganizationInvitationRequest extends FormRequest
 {
@@ -31,7 +32,8 @@ public function rules(): array
     {
         return array_merge([
             'email' => ['required', 'string', 'email', 'max:255'],
-            'role' => ['required', 'string', Rule::enum(AdminConsoleRole::class)],
+            // Owner は招待で付与できない (Owner 昇格は transferOwnership のみ)
+            'role' => ['required', 'string', Rule::enum(OrganizationRole::class)->except([OrganizationRole::Owner])],
         ], $this->protectedKeyMissingRules());
     }
 
@@ -39,7 +41,7 @@ public function rules(): array
     public function messages(): array
     {
         return [
-            // 旧契約値 (organization_admin 等) を送るデプロイ跨ぎタブの回復導線を明示する
+            // 旧契約値 (editor / shooter 等) を送るデプロイ跨ぎタブの回復導線を明示する
             'role.'.Enum::class => 'ロールの指定が不正です。画面を再読み込みしてやり直してください。',
         ];
     }
@@ -54,10 +56,10 @@ public function email(): string
     }
 
     /** 型付きアクセサ (validated 後の値を enum へ narrow して Service に渡す) */
-    public function role(): AdminConsoleRole
+    public function role(): OrganizationRole
     {
-        $role = $this->enum('role', AdminConsoleRole::class);
-        Assert::isInstanceOf($role, AdminConsoleRole::class);
+        $role = $this->enum('role', OrganizationRole::class);
+        Assert::isInstanceOf($role, OrganizationRole::class);
 
         return $role;
     }
diff --git a/app/Http/Routing/RouteBindingTypes.php b/app/Http/Routing/RouteBindingTypes.php
index 04878b4..f1ab8c6 100644
--- a/app/Http/Routing/RouteBindingTypes.php
+++ b/app/Http/Routing/RouteBindingTypes.php
@@ -109,6 +109,14 @@ final class RouteBindingTypes
             'reason' => '存在オラクル封じのため controller が $organization->users() 経由で解決する'
                 .' (binding 段で解決しないことが不在 id と実在の非メンバーを同一応答にする根拠)',
         ],
+        // AcceptInvitationInAppController は $user 宛の有効 pending 集合 (scopeActivePendingForEmail)
+        // から解決する。implicit binding のままだと「不在 id = binding 404 / 実在の他人宛 =
+        // 後段短絡」に分岐し 1 bit の存在オラクルになる。
+        'invitation' => [
+            'routes' => ['invitations.accept-in-app'],
+            'reason' => '存在秘匿のため controller が認証ユーザー宛の有効 pending 集合から解決する'
+                .' (binding 段で解決しないことが不在 id と実在の他人宛招待を同一の 404 にする根拠)',
+        ],
     ];
 
     /**
diff --git a/app/Models/OrganizationInvitation.php b/app/Models/OrganizationInvitation.php
index 7394bb0..0ca443c 100644
--- a/app/Models/OrganizationInvitation.php
+++ b/app/Models/OrganizationInvitation.php
@@ -4,7 +4,6 @@
 
 namespace App\Models;
 
-use App\Enums\ProjectRole;
 use Database\Factories\OrganizationInvitationFactory;
 use Illuminate\Database\Eloquent\Builder;
 use Illuminate\Database\Eloquent\Factories\HasFactory;
@@ -22,8 +21,8 @@
  * email は CipherSweet 暗号化 + blind index。
  * token_hash / organization_id / invited_by_user_id は $fillable 外。
  * 取り消しは行削除ではなく revoked_at による論理失効 (spirux 方式)。
- *
- * @property-read ProjectRole|null $project_role 受諾時に Default Project へ付与する pivot ロール
+ * 招待が持つロールは**組織ロールのみ** (役割付き招待は裁定 AG-079 で撤去。
+ * 編集者 / 撮影者は参加後に管理画面のロール割当コマンドで付与する)。
  */
 class OrganizationInvitation extends Model implements CipherSweetEncrypted
 {
@@ -133,15 +132,44 @@ public function scopeActive(Builder $query): void
             ->where('expires_at', '>', now());
     }
 
+    /**
+     * **受信者視点の単一解決口** — 「この email 宛の、いま受諾できる招待」の集合。
+     *
+     * アプリ内受諾 (invitations.accept-in-app) の解決・一覧・件数はすべてこの scope を
+     * 再利用する (裁定 AG-113 の必須要素 (b)。2 つがずれると「件数は出るのに受諾できない」が起きる)。
+     * 再利用の強制は InvitationResolutionInventoryTest が deny-by-default で行う。
+     *
+     * 3 条件は**すべて存在秘匿のためにある**:
+     *  - active(): 期限切れ・取消済・受諾済を落とす
+     *  - whereBlind: 宛先不一致を落とす (CipherSweet の blind index。平文 where は hit しない)
+     *  - whereHas('organization'): 削除済み組織宛を落とす
+     *    (Organization は SoftDeletes。default scope が効くため deleted_at 判定を手書きしない)
+     * これらが**すべて同じ「0 件」に collapse する**ことが、呼び出し側で理由を出し分けずに
+     * 一律 404 へ畳める根拠である (403 を返さない = 招待の存在を教えない)。
+     *
+     * ★email は**大文字小文字を区別する完全一致**である (email の blind index に
+     *   Lowercase transformer を付けていない)。大小差のある宛先は 0 件 = 404 に倒れる
+     *   (fail-secure)。従来のメール token 経路は token_hash 照合なので影響を受けず、
+     *   そちらで受諾できる。
+     * ★空文字 email での呼び出しは**呼び出し側が事前に弾く**契約
+     *   (OrganizationMembershipService::pendingInvitationsQuery)。ここでは防御しない
+     *   (guard を 2 箇所に置くと「どちらが正か」が曖昧になる)。
+     *
+     * @param  Builder<OrganizationInvitation>  $query
+     */
+    public function scopeActivePendingForEmail(Builder $query, string $email): void
+    {
+        $query->active()
+            ->whereBlind('email', 'email_index', $email)
+            ->whereHas('organization');
+    }
+
     /**
      * @return array<string, string>
      */
     protected function casts(): array
     {
         return [
-            // 受諾時に Default Project へ付与する pivot ロール (null = org 参加のみ)。
-            // サーバ導出値のため $fillable 外 (forceFill 専用 = tenant キー不信の流儀)
-            'project_role' => ProjectRole::class,
             'expires_at' => 'datetime',
             'accepted_at' => 'datetime',
             'revoked_at' => 'datetime',
diff --git a/app/Providers/AppServiceProvider.php b/app/Providers/AppServiceProvider.php
index 65f8fc9..f3890b1 100644
--- a/app/Providers/AppServiceProvider.php
+++ b/app/Providers/AppServiceProvider.php
@@ -261,6 +261,17 @@ private function configureActorScopedRateLimiters(): void
         RateLimiter::for('invitation-accept-submit', fn (Request $request): Limit => Limit::perMinute(10)
             ->by(RateLimiterKeys::actorOrIp($request, 'invitation-accept-submit')));
 
+        // アプリ内受諾の確定 (POST /invitations/{invitation}/accept-in-app)。
+        // ★閾値は姉妹の `invitation-accept-submit` / `invitation-accept` と同値 10/min
+        //   (既存値を変えない = AG-096)。
+        // ★`invitation-accept-submit` と**別レーン**にする。あちらは token URL 経路の確定で、
+        //   こちらは受諾可能な招待一覧からの確定。同一 bucket にすると片方の連打が
+        //   もう片方を 429 にして「別の入口なら受諾できる」という救済を潰す。
+        // ★route parameter ({invitation}) をキーに混ぜない。混ぜると bucket が id ごとに分かれ、
+        //   「429 になるまでの回数」が招待の実在オラクルになる。
+        RateLimiter::for('invitation-accept-in-app', fn (Request $request): Limit => Limit::perMinute(10)
+            ->by(RateLimiterKeys::actorOrIp($request, 'invitation-accept-in-app')));
+
         // パーソナルプランの有効化 (POST /onboarding/activate-personal)。
         // 一回性の操作であり、連打の実効は事前条件 (既契約なら常に失敗) が抑えるが、
         // throttle は「試行の受理数」の上限として 10/min を維持する。
diff --git a/app/Services/Organization/OrganizationMembershipService.php b/app/Services/Organization/OrganizationMembershipService.php
index 87eb27a..796d7f0 100644
--- a/app/Services/Organization/OrganizationMembershipService.php
+++ b/app/Services/Organization/OrganizationMembershipService.php
@@ -4,11 +4,11 @@
 
 namespace App\Services\Organization;
 
+use App\DataTransferObjects\Invitations\PendingInvitationForUserDto;
 use App\DataTransferObjects\Organizations\AccountDeletionBlockerDto;
 use App\Enums\AccountDeletionBlockReason;
 use App\Enums\AdminConsoleRole;
 use App\Enums\OrganizationRole;
-use App\Enums\ProjectRole;
 use App\Enums\SecurityEventType;
 use App\Models\Organization;
 use App\Models\OrganizationInvitation;
@@ -54,13 +54,20 @@ public function __construct(
     ) {}
 
     /**
-     * メンバー招待 (3 値ロールコマンド)。招待レコード生成 + 受諾 URL 付きメール送信。
-     * 編集者/撮影者は Default Project 存在が必須 (不在は ValidationException = Inertia error bag)。
+     * メンバー招待。招待レコード生成 + 受諾 URL 付きメール送信。
+     * ロールは**組織ロール 2 値 (管理者 / メンバー)**。Owner は招待で付与できない
+     * (Owner 昇格は transferOwnership のみという不変条件の型表現)。
+     * 編集者 / 撮影者 (Default Project の pivot ロール) は参加後に applyConsoleRole で割り当てる
+     * (裁定 AG-079 で役割付き招待を撤去したため)。
      *
-     * @throws ValidationException 既存メンバー / 有効な既存招待 (中立メッセージ) / project 不在
+     * @throws ValidationException 既存メンバー / 有効な既存招待 (中立メッセージ)
      */
-    public function inviteMember(Organization $organization, User $invitedBy, string $email, AdminConsoleRole $role): OrganizationInvitation
+    public function inviteMember(Organization $organization, User $invitedBy, string $email, OrganizationRole $role): OrganizationInvitation
     {
+        // Owner は FormRequest の Rule::enum(...)->except() で構造的に弾かれるが、
+        // Service を直接呼ぶ経路 (テスト・将来のバッチ) でも不変条件を守る
+        Assert::notSame($role, OrganizationRole::Owner, 'Owner は招待で付与できない');
+
         if ($this->emailBelongsToMember($organization, $email) || $this->hasPendingInvitation($organization, $email)) {
             // 既存メンバーか既存招待かを開示しない中立メッセージ (アカウント列挙対策)
             throw ValidationException::withMessages([
@@ -68,23 +75,14 @@ public function inviteMember(Organization $organization, User $invitedBy, string
             ]);
         }
 
-        // 編集者/撮影者は Default Project が前提 (送信時点の静的確認。受諾時の最終確認は
-        // joinOrganization が resolveForUpdate で行い、不在なら未割当に落とす)
-        if ($role->projectRole() !== null && $this->defaultProjects->resolve($organization) === null) {
-            throw ValidationException::withMessages([
-                'role' => ['編集者・撮影者を招待するには、先にプロジェクトを作成してください。'],
-            ]);
-        }
-
         $plainToken = OrganizationInvitation::generateToken();
 
         $invitation = new OrganizationInvitation(['email' => $email]);
         $invitation->organization()->associate($organization);
         $invitation->invitedBy()->associate($invitedBy);
-        // role / project_role / token_hash / expires_at は明示代入 (mass-assignment させない)
+        // role / token_hash / expires_at は明示代入 (mass-assignment させない)
         $invitation->forceFill([
-            'role' => $role->organizationRole()->value,
-            'project_role' => $role->projectRole()?->value,
+            'role' => $role->value,
             'token_hash' => OrganizationInvitation::hashToken($plainToken),
             'expires_at' => now()->addDays(self::EXPIRES_DAYS),
         ]);
@@ -133,7 +131,11 @@ public function acceptInvitation(string $plainToken, User $user): Organization
 
         $role = OrganizationRole::from($invitation->role);
 
-        $this->joinOrganization($invitation, $organization, $user, $role);
+        if (! $this->joinOrganization($invitation, $organization, $user, $role)) {
+            // ロック下再検証で受諾不能になった (並行受諾 / 取り消し / 期限到来)。
+            // 事前検証と同じ中立メッセージへ畳む (取り消された事実を token 保持者に開示しない)
+            throw ValidationException::withMessages(['token' => ['この招待は無効です。']]);
+        }
 
         return $organization;
     }
@@ -178,7 +180,11 @@ public function acceptInvitationIfValid(string $plainToken, User $user): ?Organi
             return null;
         }
 
-        $this->joinOrganization($invitation, $organization, $user, OrganizationRole::from($invitation->role));
+        if (! $this->joinOrganization($invitation, $organization, $user, OrganizationRole::from($invitation->role))) {
+            // 受諾不能なら現在組織も確定しない (join 失敗でも current_organization_id を
+            // 招待組織へ書くと、非所属 org が current という非正規状態を作る)
+            return null;
+        }
 
         // [register 経路限定] 参加した招待組織をこの新規ユーザーの「現在組織」として確定する。
         // - 本メソッドは register 経路専用 (呼び出し元は CreateNewUser のみ。POST 受諾は acceptInvitation)。
@@ -254,7 +260,122 @@ public function revokeInvitation(OrganizationInvitation $invitation): void
     }
 
     /**
-     * 招待受諾の確定処理 (attach + ロール付与 + pivot attach + accepted_at)。両受諾経路の共通コア。
+     * **受信者視点の pending 集合クエリの唯一の起点**。
+     *
+     * 裁定 AG-113 の必須要素 (b)(c) をここ 1 箇所で満たす:
+     *  (b) 受諾の解決・一覧・件数がすべてこのメソッドを通る (絞り込みが 1 本 = drift しない)
+     *  (c) 未ログイン / 未 verified / email 空は **null を返し DB を一切引かない**
+     *      (共有 prop は全リクエストで評価されるため、この early return が実効的な負荷契約になる)
+     *
+     * @return Builder<OrganizationInvitation>|null null = 引くべきでない (クエリを組み立てない)
+     */
+    private function pendingInvitationsQuery(?User $user): ?Builder
+    {
+        if ($user === null || ! $user->hasVerifiedEmail()) {
+            return null;
+        }
+
+        $email = $user->email; // CipherSweet 復号後
+        if ($email === '') {
+            return null;
+        }
+
+        return OrganizationInvitation::query()->activePendingForEmail($email);
+    }
+
+    /**
+     * 自分宛の受諾可能な招待の一覧 (受信者視点 DTO)。表示専用でロックしない。
+     *
+     * @return list<PendingInvitationForUserDto>
+     */
+    public function pendingInvitationsFor(?User $user): array
+    {
+        $query = $this->pendingInvitationsQuery($user);
+        if ($query === null) {
+            return [];
+        }
+
+        // N+1 回避に with('organization') を付ける (DTO が organization->name を読む)
+        $invitations = $query->with('organization')->orderBy('expires_at')->get();
+
+        $rows = [];
+        foreach ($invitations as $invitation) {
+            $rows[] = PendingInvitationForUserDto::fromInvitation($invitation);
+        }
+
+        return $rows;
+    }
+
+    /** 自分宛の受諾可能な招待の件数 (共有 prop 用。一覧と同一 scope を再利用する)。 */
+    public function pendingInvitationCountFor(?User $user): int
+    {
+        return $this->pendingInvitationsQuery($user)?->count() ?? 0;
+    }
+
+    /**
+     * **アプリ内受諾** (メールの URL を根拠にしない受諾。裁定 AG-113 標準形 v1)。
+     *
+     * 受諾の根拠は「auth 済み ∧ email 確認済み ∧ ログイン者 email = 招待宛先」であり、
+     * その全部が pendingInvitationsQuery() の 1 本に畳まれている。
+     *
+     * **戻り値契約**: 業務上の受諾不能 (宛先不一致 / 不在 / 期限切れ / 取消済 / 受諾済 /
+     * 組織削除済 / ロック下再検証での敗北) は例外にせず null を返す (呼び出し側が一律 404)。
+     * DB 障害・インフラ障害・プログラム不整合の例外は**捕捉せず伝播させる** (500 のまま。
+     * 404 に化けさせない)。この分離により、将来この分岐へ理由を足しても情報が漏れない。
+     *
+     * **ロックと最終権威**:
+     *  1. 下見 (ロック無し) で organization_id を得る
+     *  2. canonical 順序 (users 昇順 → organizations) で lockForMembershipWrite
+     *     — 組織の soft-delete は同じ organizations 行の UPDATE なのでここで直列化される
+     *  3. **ロック下で同一 scope を再解決** — ここが組織 soft-delete / 取消 / 期限に対する権威
+     *  4. joinOrganization() が招待行を lockForUpdate して最終再検証 (取消の割り込みはここが閉じる。
+     *     revokeInvitation は membership ロックを取らないため 3 と 4 の間に窓があるが、
+     *     取り消し側の UPDATE も同じ招待行を取るため直列化される)
+     * joinOrganization() は同一 tx 内で同じ行の lockForMembershipWrite を再取得するが、
+     * 取得済み行の再取得は no-op でロック順序も変わらない (新しい順序を作らない
+     * = デッドロックを導入しない)。
+     *
+     * @param  string  $invitationId  route parameter (未検証の文字列。pattern で 1-18 桁数値に制約済み)
+     */
+    public function acceptPendingInvitation(?User $user, string $invitationId): ?Organization
+    {
+        if ($user === null) {
+            return null;
+        }
+
+        return DB::transaction(function () use ($user, $invitationId): ?Organization {
+            // 1. 下見 (ロック前)。ここで null なら DB もロックも最小で終わる
+            $preliminary = $this->pendingInvitationsQuery($user)?->whereKey($invitationId)->first();
+            if ($preliminary === null) {
+                return null;
+            }
+
+            // 2. canonical 順序でロック (users 昇順 → organizations)
+            $organizationId = $preliminary->getAttribute('organization_id');
+            Assert::integer($organizationId);
+            $this->lockForMembershipWrite([$this->keyOf($user)], [$organizationId]);
+
+            // 3. ロック下で同一 scope を再解決 (下見の結果は信用しない)
+            $invitation = $this->pendingInvitationsQuery($user)?->whereKey($invitationId)->first();
+            if ($invitation === null) {
+                return null;
+            }
+
+            $organization = $invitation->organization;
+            Assert::isInstanceOf($organization, Organization::class);
+
+            // 4. 変換本体 (token 経路と共有)。false = 招待行ロック下の再検証で受諾不能
+            if (! $this->joinOrganization($invitation, $organization, $user, OrganizationRole::from($invitation->role))) {
+                return null;
+            }
+
+            // 現在組織は切り替えない (POST 受諾の既存契約と揃える。驚き最小)
+            return $organization;
+        });
+    }
+
+    /**
+     * 招待受諾の確定処理 (attach + ロール付与 + accepted_at)。全受諾経路の共通コア。
      * accepted_at は $fillable 外のため forceFill で明示代入する。
      *
      * 並行受諾への防御は 2 層:
@@ -267,12 +388,16 @@ public function revokeInvitation(OrganizationInvitation $invitation): void
      *    (organization/user は relation 解決済み) で、payload 不信の保護キー規約に反しない。
      *    organization_user は (organization_id, user_id) UNIQUE + timestamps のみの pivot。
      *
-     * project_role 付き招待は Default Project (resolveForUpdate = 行ロック) へ pivot attach。
-     * 受諾時に project が消えていた場合は org 参加のみ = 「未割当」表示状態に落ちる (可視 degrade)。
+     * 招待は「組織に入れる」ことだけを意味する (役割付き招待は裁定 AG-079 で撤去)。
+     * 編集者 / 撮影者の割当は参加後に applyConsoleRole で行う。
+     *
+     * @return bool true = ロック下再検証を通り変換が完了した (既 join の冪等 no-op を含む) /
+     *              false = ロック下で受諾不能 (受諾済 / 取消済 / 期限切れ) だった。
+     *              **false は全呼び出し元が必ず消費する** (成功扱いで返さない)。
      */
-    private function joinOrganization(OrganizationInvitation $invitation, Organization $organization, User $user, OrganizationRole $role): void
+    private function joinOrganization(OrganizationInvitation $invitation, Organization $organization, User $user, OrganizationRole $role): bool
     {
-        DB::transaction(function () use ($organization, $user, $role, $invitation): void {
+        return DB::transaction(function () use ($organization, $user, $role, $invitation): bool {
             // canonical 共通ロック境界 (users 昇順 → organizations)。並行メンバー追加を
             // deleteAccount 等と直列化する (招待行ロックの手前で org/user 行ロックを取る)。
             $this->lockForMembershipWrite([$this->keyOf($user)], [$this->keyOf($organization)]);
@@ -281,10 +406,10 @@ private function joinOrganization(OrganizationInvitation $invitation, Organizati
             /** @var OrganizationInvitation $locked */
             $locked = OrganizationInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
             if ($locked->isAccepted() || $locked->isRevoked() || $locked->isExpired()) {
-                return; // 期限境界の TOCTOU も含めロック下で完全再検証 (敗者は冪等 no-op)
+                return false; // 期限境界の TOCTOU も含めロック下で完全再検証 (敗者は受諾不能)
             }
 
-            // 2. org 参加の原子的 INSERT。0 行 = 別経路で join 済み (role/pivot は変更しない。
+            // 2. org 参加の原子的 INSERT。0 行 = 別経路で join 済み (role は変更しない。
             //    非正規状態が残る場合も「未割当」として可視化され管理画面から修復できる)
             $joined = DB::table('organization_user')->insertOrIgnore([
                 'organization_id' => $organization->id,
@@ -295,17 +420,11 @@ private function joinOrganization(OrganizationInvitation $invitation, Organizati
 
             if ($joined === 1) {
                 $user->addRole($role->value, $organization->laratrust_team_id);
-
-                $projectRole = $locked->project_role;
-                if ($projectRole instanceof ProjectRole) {
-                    $project = $this->defaultProjects->resolveForUpdate($organization);
-                    $project?->members()->syncWithoutDetaching([
-                        $user->id => ['role' => $projectRole->value],
-                    ]);
-                }
             }
 
             $locked->forceFill(['accepted_at' => now()])->save();
+
+            return true;
         });
     }
 
diff --git a/docs/architecture.md b/docs/architecture.md
index 3336b46..e1642e5 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -857,12 +857,65 @@ ### ロールの 2 層モデル (保存しない = 導出)
 ロール未付与の異常行) は非表示にせず可視化し、ロール割当コマンドで修復できる
 (`OrganizationMembershipService::applyConsoleRole` の修復経路)。
 
-- `organizations.members.update` (PATCH) / `organizations.invitations.store` (POST) の role
-  payload は 3 値コマンド (旧 org ロール値は enum 検証で拒否)。Owner は enum 外 = 構造的に
-  指定不可 (Owner 昇格は transferOwnership のみ)
-- 招待は `organization_invitations.project_role` (nullable・サーバ導出・forceFill 専有) を持ち、
-  受諾 (`joinOrganization` = 招待行 lockForUpdate + organization_user の insertOrIgnore) で
-  Default Project へ pivot attach。受諾時 project 不在は org 参加のみ = 未割当へ可視 degrade
+- `organizations.members.update` (PATCH) の role payload は 3 値コマンド
+  (旧 org ロール値は enum 検証で拒否)。Owner は enum 外 = 構造的に指定不可
+  (Owner 昇格は transferOwnership のみ)
+- **`organizations.invitations.store` (POST) の role payload は org ロール 2 値**
+  (`organization_admin` / `organization_member`)。`Rule::enum(OrganizationRole)->except([Owner])`
+  で Owner を構造的に拒否する。**招待は「組織に入れる」ことだけを意味し**、
+  編集者 / 撮影者は参加後にロール割当コマンドで付与する
+  (役割付き招待 `organization_invitations.project_role` は裁定 AG-079 で列ごと撤去。
+  受諾 `joinOrganization` は org 参加 + org ロール付与 + accepted_at のみを行う)
+
+### 招待受諾の 2 経路 (token URL / アプリ内)
+
+受諾経路は 2 本あり **受諾の根拠が違う**。片方だけを見て「不整合」と直さないこと。
+
+| 経路 | route | 受諾の根拠 | 解決方法 |
+|---|---|---|---|
+| メール token URL | `invitations.accept` (GET) / `invitations.accept.store` (POST) | **有効な招待 token の保持** | `token_hash` (sha256) 照合 |
+| アプリ内 | `invitations.accept-in-app` (POST) | **auth 済み ∧ email 確認済み ∧ ログイン者 email = 招待宛先** | `OrganizationInvitation::scopeActivePendingForEmail($user->email)` |
+
+- **`verified` の非対称は仕様**: token 経路は招待直後の未検証ユーザーも受諾できる
+  (メールを受け取れたこと自体が根拠の一部)。アプリ内経路は根拠そのものが
+  「email 確認済みのログイン者 = 招待宛先」なので `verified` が必須。
+- **email 照合の非対称**: アプリ内経路は blind index の**大文字小文字を区別する完全一致**
+  (email の blind index に Lowercase transformer を付けていない)。
+  **email は正規化保存していない** (`App\Support\EmailNormalizer` は inquiry / billing contact 専用で、
+  `CreateNewUser` は validated 値をそのまま保存する) ため、「招待は `Foo@example.com` 宛 /
+  ログインは `foo@example.com`」は実運用で起こりうる。この場合アプリ内受諾は 0 件 = **404 に倒れる**
+  (fail-secure) が、**メール token 経路は `token_hash` 照合なので影響を受けず従来どおり受諾できる**。
+  正規化するなら既存全レコードの blind index 再計算と全 `whereBlind` 呼び出し元の同時変更を伴う別作業になる。
+- **存在秘匿の畳み方**: 受信者視点の解決・一覧・件数は `scopeActivePendingForEmail` の 1 本だけを
+  再利用する (`InvitationResolutionInventoryTest` が deny-by-default で強制)。
+  宛先不一致 / 不在 id / 期限切れ / 取消済 / 受諾済 / 削除済み組織宛は**すべて同じ 0 件**へ collapse し、
+  controller は**一律 404** を返す (403 を返さない = 招待の存在を教えない)。
+  `{invitation}` は implicit binding させない (binding 段で解決すると不在 id だけが binding 404 になり
+  1 bit の存在オラクルになる。`NestedRouteDefenseInventory` / `RouteBindingTypes::MANUALLY_RESOLVED` に登録)。
+- **最終権威の表** (どの競合をどのロックが閉じるか):
+
+| 競合 | 最終権威 |
+|---|---|
+| 組織の soft-delete | `lockForMembershipWrite` が取る organizations 行ロック (削除も同じ行の UPDATE) |
+| 取消 / 期限到来 / 並行受諾 | `joinOrganization` の招待行 `lockForUpdate` (取消の UPDATE も同じ行を取る) |
+| 別経路での並行 join | `organization_user` の `insertOrIgnore` (0 行 = 既 join として role を変更しない) |
+
+- `joinOrganization()` は **bool を返す** (false = ロック下再検証で受諾不能)。
+  全呼び出し元が false を消費する (token 経路は中立メッセージへ / register 経路は
+  現在組織を確定せず null / アプリ内経路は 404)。戻り値を捨てる実装は
+  `MembershipWriteLockInventoryTest` のトークナイザ検査が fail させる。
+- 共有 prop `invitationInbox.pendingCount` は **closure** のため `only:` 指定の partial reload では
+  評価されない (件数はフルページ遷移時に更新される。受諾直後は dashboard へフル遷移するため実害はない)。
+  キー名を `invitations` にしないのは、ページ prop `invitations` (Admin/Users の招待一覧) と
+  衝突して共有 prop が上書きされるため。
+- **未ログイン / 未 verified / email 空は DB を一切引かない**
+  (`OrganizationMembershipService::pendingInvitationsQuery` の early return。
+  共有 prop は全リクエストで評価されるため、これが実効的な負荷契約になる)。
+- **役割付き招待の撤去 (AG-079) のデプロイ順序**: (1) `project_role` を読み書きしないコードを先にデプロイ →
+  (2) 旧プロセスの排除 (`queue:restart` / web worker 入替完了) → (3) 列 drop の migration。
+  逆順にすると旧コードが存在しない列へ INSERT して 500 になる (回復導線が無い)。
+  ローリング更新中は新旧 HTTP 契約の混在で招待送信が一時的に 422 になりうるが、
+  `StoreOrganizationInvitationRequest::messages()` の「画面を再読み込みしてやり直してください。」が回復導線になる。
 
 ### DefaultProjectResolver の read/write 契約
 
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 265ae6f..afaa4d0 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -224,7 +224,7 @@ ## D8 ✅ 管理メニューのユーザー管理 = 招待一本化 + 遷移コ
 |---|---|---|
 | メンバー管理 UI | `Organizations/Settings.svelte` に組織設定と同居 | 管理メニュー専用画面 `Admin/Users` (GET `/manage/users`) へ移設。Settings は組織設定 (名称 / 2FA 方針 / API キー導線 / オーナー移譲) のみ |
 | ロールの語彙 | org ロール直接指定 (`organization_admin` / `organization_member`) | **3 値遷移コマンド** (`AdminConsoleRole`: admin/editor/shooter)。org ロール + Default Project pivot への「正規状態への遷移」を 1 tx で適用 (`applyConsoleRole`)。表示は導出 5 値 (`MemberRoleState`: owner/admin/editor/shooter/unassigned) |
-| 招待 | org ロールのみ | `organization_invitations.project_role` (nullable・forceFill 専有) を追加し、受諾時に Default Project へ pivot attach。旧行 (null) は従来どおり org 参加のみ |
+| 招待 | org ロールのみ | **org ロールのみ (テンプレートと同じ)**。一度 `organization_invitations.project_role` を追加して受諾時に Default Project へ pivot attach する差分を持っていたが、裁定 AG-079 (Default Project という概念自体が不要) で**列ごと撤去**し逸脱を戻した。編集者 / 撮影者は参加後にロール割当コマンドで付与する |
 | settings() props | members に email / role / twoFactorStatus | members は `{id, name}` に縮小 (オーナー移譲 select 用途のみ = PII 最小化)。invitations prop は撤去 |
 | ユーザー作成 | (doc/04 レガシーモック: 管理者がパスワード直接発行・平文一覧表示) | **招待一本化** (ユーザー ID → email へマッピング)。パスワードは本人設定のみ |
 
@@ -242,9 +242,10 @@ ### 揃えている不変条件 (これは保証し続ける)
 > 「招待 token は hash-only 保存 / 重複は中立メッセージ / 権限判定は laratrust_team_id 明示 /
 > Owner 昇格は transferOwnership のみ / PII 可視性は manageMembers 到達境界 (403)」
 
-- Owner は `AdminConsoleRole` の enum 外 = 型で構造的に指定不可
-- project_role はクライアント payload から受けない (role コマンドからサーバ導出 + forceFill。
-  `ProhibitsProtectedKeys` は入口で保護キーを missing 強制)
+- Owner はメンバーのロール変更では `AdminConsoleRole` の enum 外 = 型で構造的に指定不可。
+  招待では `Rule::enum(OrganizationRole)->except([Owner])` が構造的に拒否する
+  (**ロール語彙の非対称**: 招待は org ロール 2 値 / メンバーのロール変更は 3 値コマンド。
+  招待は「組織に入れる」だけを意味し、編集者 / 撮影者の割当は参加後の別操作である)
 - pivot 書き込み経路は `OrganizationMembershipService` / `ProjectMemberController` に閉じる
   (**`ProjectMemberPivotWritePathTest`** が deny-by-default で強制)
 - `/manage/` 配下 route の auth+verified は **`ManageRouteAuthGuardTest`** が deny-by-default で強制
@@ -257,7 +258,8 @@ ### 関連
   `app/Services/Organization/OrganizationMembershipService.php` /
   `app/Services/Project/DefaultProjectResolver.php` /
   `app/Http/Controllers/Admin/UserManagementController.php`
-- 設計: `devnotes/20260711-1009-admin-console/` (概念設計 D1/D2/D6・詳細設計 施策 1〜7)
+- 設計: `devnotes/20260711-1009-admin-console/` (概念設計 D1/D2/D6・詳細設計 施策 1〜7) /
+  `devnotes/20260807-2032-invitation-in-app-acceptance/` (役割付き招待の撤去 = 裁定 AG-079)
 
 ## D9 ✅→解消 BillingAccess の entitlement 判定への書き換え (free tier は課金ゲートを通す)
 
diff --git a/resources/js/components/features/invitations/PendingInvitationList.svelte b/resources/js/components/features/invitations/PendingInvitationList.svelte
new file mode 100644
index 0000000..d624001
--- /dev/null
+++ b/resources/js/components/features/invitations/PendingInvitationList.svelte
@@ -0,0 +1,80 @@
+<script lang="ts">
+    import { router } from "@inertiajs/svelte";
+    import { Mail } from "@lucide/svelte";
+    import Badge from "@/components/atoms/Badge.svelte";
+    import Button from "@/components/atoms/Button.svelte";
+    import Card from "@/components/atoms/Card.svelte";
+    import type { PendingInvitation } from "@/types/invitation";
+
+    /**
+     * 自分宛の受諾可能な招待の一覧 (受諾ボタン付き)。
+     * 受諾 = POST /invitations/{id}/accept-in-app (サーバが 302 で dashboard へ着地させる)。
+     *
+     * ★禁止事項 8 との関係: 「必須条件未充足を理由に disabled にする」ことはしない
+     *   (前提条件で押せないボタンを作らない)。in-flight 中は既存 Button atom の
+     *   `loading` (= disabled + aria-busy) を使う — これは二重送信防止であって
+     *   必須条件未充足による無効化ではなく、同画面の招待送信ボタン
+     *   (`loading={inviteForm.processing}`) と同じ既存流儀である。
+     *   加えてハンドラ側でも in-flight 中の再入を無視する (二重の送信ガード)。
+     * ★DS: 色 / radius / typography は token 経由のみ (hex 直書き・独自 radius を増やさない)。
+     *   アイコンは @lucide/svelte の Mail のみ (SVG 直書きを新設しない)。
+     *   Card の入れ子を作らない (この component 自身が 1 枚の Card)。
+     */
+    interface Props {
+        invitations: PendingInvitation[];
+    }
+
+    let { invitations }: Props = $props();
+
+    let acceptingId = $state<number | null>(null);
+
+    function accept(invitation: PendingInvitation): void {
+        if (acceptingId !== null) return; // in-flight 中の再入を無視 (disabled ではない)
+        acceptingId = invitation.id;
+        router.post(
+            `/invitations/${invitation.id}/accept-in-app`,
+            {},
+            {
+                onFinish: () => {
+                    acceptingId = null;
+                },
+            },
+        );
+    }
+</script>
+
+{#if invitations.length > 0}
+    <Card padding="lg" testId="pending-invitation-list">
+        <h2 class="text-h3">届いている招待</h2>
+        <p class="mt-1 text-caption text-text-secondary">
+            あなた宛の招待です。参加すると、その組織のメンバーになります。
+        </p>
+        <ul class="mt-4 divide-y divide-border">
+            {#each invitations as invitation (invitation.id)}
+                <li
+                    class="flex flex-wrap items-center gap-3 py-3"
+                    data-testid={`pending-invitation-${invitation.id}`}
+                >
+                    <span
+                        class="inline-flex size-8 shrink-0 items-center justify-center rounded-md bg-primary-soft text-primary"
+                        aria-hidden="true"
+                    >
+                        <Mail class="size-4" />
+                    </span>
+                    <p class="min-w-0 grow truncate text-body text-text">
+                        {invitation.organizationName}
+                    </p>
+                    <Badge tone="neutral" size="sm">{invitation.roleLabel}</Badge>
+                    <span class="text-caption text-text-secondary">期限 {invitation.expiresAt}</span>
+                    <Button
+                        onclick={() => accept(invitation)}
+                        loading={acceptingId === invitation.id}
+                        testId={`accept-invitation-${invitation.id}`}
+                    >
+                        参加する
+                    </Button>
+                </li>
+            {/each}
+        </ul>
+    </Card>
+{/if}
diff --git a/resources/js/components/features/notifications/NotificationListItem.svelte b/resources/js/components/features/notifications/NotificationListItem.svelte
index 440add5..24efcb6 100644
--- a/resources/js/components/features/notifications/NotificationListItem.svelte
+++ b/resources/js/components/features/notifications/NotificationListItem.svelte
@@ -94,7 +94,7 @@
                 : `${manualPayload.manual_title}: ${manualPayload.error ?? "エラーが発生しました"}`;
         }
         if (invitationPayload) {
-            return "メールの受諾リンクから参加してください";
+            return "クリックすると、届いている招待から参加できます";
         }
         if (balancePayload) {
             return `通知の目安 (${balancePayload.threshold} 枚) を下回りました。チケットを追加購入できます`;
diff --git a/resources/js/components/molecules/PendingInvitationsNotice.svelte b/resources/js/components/molecules/PendingInvitationsNotice.svelte
new file mode 100644
index 0000000..fdb803c
--- /dev/null
+++ b/resources/js/components/molecules/PendingInvitationsNotice.svelte
@@ -0,0 +1,31 @@
+<script lang="ts">
+    import { Link } from "@inertiajs/svelte";
+    import { Mail } from "@lucide/svelte";
+
+    /**
+     * 自分宛の保留中招待の件数だけを出す誘導専用 notice (受諾 UI は持たない)。
+     * 受諾は /notifications の「届いている招待」から行う。
+     * 件数は shared props invitationInbox.pendingCount (親が渡す)。
+     *
+     * molecule に置くのは、atom (Link + Lucide icon) の組合せだけで状態も domain 操作も
+     * 持たないため (NotificationBell と同じ位置づけ)。
+     * ★DS: 色 / radius / typography は token 経由のみ。SVG 直書きを新設しない。
+     */
+    interface Props {
+        pendingCount: number;
+        testId?: string;
+    }
+
+    let { pendingCount, testId = "pending-invitations-notice" }: Props = $props();
+</script>
+
+{#if pendingCount > 0}
+    <Link
+        href="/notifications"
+        class="flex items-center gap-2 rounded-md border border-border bg-primary-soft/40 px-4 py-2 text-body text-text hover:bg-primary-soft"
+        data-testid={testId}
+    >
+        <Mail class="size-4 text-primary" aria-hidden="true" />
+        あなた宛の招待が {pendingCount} 件あります
+    </Link>
+{/if}
diff --git a/resources/js/components/templates/AppLayout.svelte b/resources/js/components/templates/AppLayout.svelte
index 76e6f10..58e065d 100644
--- a/resources/js/components/templates/AppLayout.svelte
+++ b/resources/js/components/templates/AppLayout.svelte
@@ -19,6 +19,7 @@
     } from "@lucide/svelte";
     import EmailVerificationBanner from "@/components/features/auth/EmailVerificationBanner.svelte";
     import NotificationBell from "@/components/molecules/NotificationBell.svelte";
+    import PendingInvitationsNotice from "@/components/molecules/PendingInvitationsNotice.svelte";
     import ToastContainer from "@/components/organisms/ToastContainer.svelte";
     import SidebarNavItems, {
         type SidebarNavItem,
@@ -60,6 +61,8 @@
     const userName = $derived(shared.auth?.user?.name ?? "ユーザー");
     const orgName = $derived(currentOrganization?.name ?? "組織未選択");
     const unreadCount = $derived(shared.notifications?.unreadCount ?? 0);
+    // 自分宛の保留中招待の件数 (全画面横断の気づき。未ログイン / 未 verified は 0)
+    const pendingInvitationCount = $derived(shared.invitationInbox?.pendingCount ?? 0);
 
     // メール未認証のソフトゲート案内
     const showEmailBanner = $derived(
@@ -474,6 +477,11 @@
                     <EmailVerificationBanner />
                 </div>
             {/if}
+            {#if showAccountNav && pendingInvitationCount > 0}
+                <div class="px-4 pt-4 sm:px-6 lg:px-8">
+                    <PendingInvitationsNotice pendingCount={pendingInvitationCount} />
+                </div>
+            {/if}
             <!-- padding は各ページの PageContainer が担う (aigenba parity, T071)。ここでは付けない。 -->
             <div data-testid="app-main">
                 {@render children()}
diff --git a/resources/js/lib/shared-props.ts b/resources/js/lib/shared-props.ts
index 583b00f..42c1a16 100644
--- a/resources/js/lib/shared-props.ts
+++ b/resources/js/lib/shared-props.ts
@@ -1,4 +1,5 @@
 import type { FlashPayload } from "@/lib/stores/flash-to-toast";
+import type { InvitationSharedProps } from "@/types/invitation";
 import type { NotificationSharedProps } from "@/types/notification";
 
 /**
@@ -61,6 +62,12 @@ export interface SharedProps {
     flash: FlashPayload;
     /** 通知センターの未読数 (全 org 横断・自分宛のみ。未ログイン時は 0) */
     notifications: NotificationSharedProps;
+    /**
+     * 自分宛の受諾可能な招待の件数 (未ログイン / 未 verified / email 空は 0)。
+     * キー名が `invitations` でないのは、ページ prop `invitations` (Admin/Users の招待一覧) と
+     * 衝突して共有 prop が上書きされるのを避けるため。
+     */
+    invitationInbox: InvitationSharedProps;
     /** サーバ描画 <title> と同一の完成タイトル (document-title.ts が SPA 遷移時に同期する) */
     title: string;
 }
diff --git a/resources/js/pages/Admin/Users.svelte b/resources/js/pages/Admin/Users.svelte
index ccaca83..dc638a2 100644
--- a/resources/js/pages/Admin/Users.svelte
+++ b/resources/js/pages/Admin/Users.svelte
@@ -45,6 +45,15 @@
         members.find((member) => member.isSelf)?.roleState === "owner",
     );
 
+    /**
+     * 招待のロール選択肢 = org ロール 2 値 (編集者 / 撮影者は参加後に割り当てる)。
+     * メンバーのロール変更コマンド (ROLE_OPTIONS) とは別集合 (統合しない)。
+     */
+    const INVITE_ROLE_OPTIONS: { value: string; label: string }[] = [
+        { value: "organization_admin", label: "管理者" },
+        { value: "organization_member", label: "メンバー" },
+    ];
+
     /** ロール select の選択肢 (遷移コマンド 3 値。owner は enum 外 = 構造的に指定不可) */
     const ROLE_OPTIONS: { value: ConsoleRole; label: string }[] = [
         { value: "admin", label: "管理者" },
@@ -212,7 +221,7 @@
     }
 
     /* ---- ユーザー追加 (招待。モック 03/04/06) ---- */
-    const inviteForm = useForm({ email: "", role: "shooter" });
+    const inviteForm = useForm({ email: "", role: "organization_member" });
 
     function submitInvite(event: SubmitEvent): void {
         event.preventDefault();
@@ -254,7 +263,7 @@
     <PageContainer>
         <PageHeader
             title="ユーザー管理"
-            description="組織のメンバーと招待を管理します。ロールは「管理者・編集者・撮影者」から選択します。"
+            description="組織のメンバーと招待を管理します。メンバーのロールは「管理者・編集者・撮影者」から選択します (招待は「管理者・メンバー」の 2 値で、編集者・撮影者は参加後に割り当てます)。"
             icon={Users}
             testId="users-heading"
         />
@@ -372,7 +381,7 @@
                 <Card padding="lg">
                     <h2 class="text-h3">ユーザーを追加</h2>
                     <p class="mt-1 text-caption text-text-secondary">
-                        招待メールを送信します。招待の有効期限は 7 日間です。
+                        招待メールを送信します。招待の有効期限は 7 日間です。編集者・撮影者は参加後に割り当てます。
                     </p>
                     <form novalidate onsubmit={submitInvite} class="mt-4 flex flex-col gap-4">
                         <FormField
@@ -399,7 +408,7 @@
                                     error={invalid}
                                     aria-describedby={describedBy}
                                 >
-                                    {#each ROLE_OPTIONS as option (option.value)}
+                                    {#each INVITE_ROLE_OPTIONS as option (option.value)}
                                         <option value={option.value}>{option.label}</option>
                                     {/each}
                                 </Select>
diff --git a/resources/js/pages/Notifications/Index.svelte b/resources/js/pages/Notifications/Index.svelte
index ae12e5f..71bfad1 100644
--- a/resources/js/pages/Notifications/Index.svelte
+++ b/resources/js/pages/Notifications/Index.svelte
@@ -6,12 +6,14 @@
     import EmptyState from "@/components/molecules/EmptyState.svelte";
     import Pagination from "@/components/molecules/Pagination.svelte";
     import NotificationListItem from "@/components/features/notifications/NotificationListItem.svelte";
+    import PendingInvitationList from "@/components/features/invitations/PendingInvitationList.svelte";
     import AppLayout from "@/components/templates/AppLayout.svelte";
     import PageContainer from "@/components/templates/PageContainer.svelte";
     import PageContent from "@/components/templates/PageContent.svelte";
     import PageHeaderSection from "@/components/molecules/PageHeaderSection.svelte";
     import type { SharedProps } from "@/lib/shared-props";
     import type { PaginationMeta } from "@/types/manual";
+    import type { PendingInvitation } from "@/types/invitation";
     import type { NotificationItem } from "@/types/notification";
 
     /**
@@ -29,9 +31,11 @@
         notifications: NotificationItem[];
         meta: PaginationMeta;
         unreadCount: number;
+        /** 自分宛の受諾可能な招待 (受諾の解決・共有 prop の件数と同一 scope から算出) */
+        pendingInvitations: PendingInvitation[];
     }
 
-    let { notifications, meta, unreadCount }: Props = $props();
+    let { notifications, meta, unreadCount, pendingInvitations }: Props = $props();
 
     const shared = $derived(page.props as unknown as SharedProps);
     const appName = $derived(shared.appName ?? "");
@@ -74,6 +78,11 @@
             {/if}
         </PageHeaderSection>
         <PageContent>
+            {#if pendingInvitations.length > 0}
+                <div class="mt-6">
+                    <PendingInvitationList invitations={pendingInvitations} />
+                </div>
+            {/if}
             {#if notifications.length === 0}
                 <div class="mt-6">
                     <EmptyState
diff --git a/resources/js/types/admin.ts b/resources/js/types/admin.ts
index 37237cc..3309c69 100644
--- a/resources/js/types/admin.ts
+++ b/resources/js/types/admin.ts
@@ -19,10 +19,16 @@ export interface MemberRow {
     isSelf: boolean;
 }
 
+/**
+ * 招待中の 1 行。招待は org ロールだけを持つ (役割付き招待は裁定 AG-079 で撤去)。
+ * メンバー行の 5 値表示状態 (MemberRoleState) とは語彙が違う
+ * (招待中の行は「未割当」ではなく、まだ参加していないだけ)。
+ */
 export interface InvitationRow {
     id: number;
     email: string;
-    roleState: MemberRoleState;
+    /** App\Enums\OrganizationRole の value (organization_admin / organization_member) */
+    role: string;
     roleLabel: string;
     expiresAt: string;
 }
diff --git a/resources/js/types/invitation.ts b/resources/js/types/invitation.ts
new file mode 100644
index 0000000..da19810
--- /dev/null
+++ b/resources/js/types/invitation.ts
@@ -0,0 +1,17 @@
+/**
+ * 受信者視点の保留中招待。
+ * PHP 側 App\DataTransferObjects\Invitations\PendingInvitationForUserDto::toArray() と対で保守する。
+ * 管理者視点の InvitationRow (types/admin.ts) とは別契約 (統合しない)。
+ */
+export interface PendingInvitation {
+    id: number;
+    organizationName: string;
+    roleLabel: string;
+    /** Y-m-d */
+    expiresAt: string;
+}
+
+/** HandleInertiaRequests が共有する invitations props */
+export interface InvitationSharedProps {
+    pendingCount: number;
+}
diff --git a/routes/web.php b/routes/web.php
index d7bdaef..590535f 100644
--- a/routes/web.php
+++ b/routes/web.php
@@ -20,6 +20,7 @@
 use App\Http\Controllers\Onboarding\ActivatePersonalController;
 use App\Http\Controllers\Onboarding\BillingRequiredController;
 use App\Http\Controllers\Onboarding\OnboardingController;
+use App\Http\Controllers\Organizations\AcceptInvitationInAppController;
 use App\Http\Controllers\Organizations\InvitationAcceptanceController;
 use App\Http\Controllers\Organizations\OrganizationApiKeyController;
 use App\Http\Controllers\Organizations\OrganizationController;
@@ -620,6 +621,27 @@
     ->middleware(['auth', 'throttle:invitation-accept-submit'])
     ->name('invitations.accept.store');
 
+/*
+| アプリ内受諾 (メールの URL を根拠にしない第 2 の受諾経路。裁定 AG-113 標準形 v1)。
+| 根拠は「auth 済み ∧ email 確認済み ∧ ログイン者 email = 招待宛先」。
+| 既存 token 経路を**置き換えず並べて持つ** (未登録の人にはメールが唯一の入口)。
+|
+| ★{invitation} は implicit binding させない。binding 段で解決すると
+|   「不在 id = binding 404 / 実在の他人宛 = 後段の応答」に分岐し 1 bit の存在オラクルになる。
+|   controller が $user 宛の有効 pending 集合から手動解決し、全ての不成立を 404 へ畳む。
+| ★verified 必須。姉妹の invitations.accept.store は意図的に verified を要求しない
+|   (招待直後の未検証ユーザーも token で受諾できる仕様) — この非対称は仕様であり、
+|   受諾根拠が違うことに由来する (docs/architecture.md に理由を明記)。
+| ★throttle は named limiter `invitation-accept-in-app` (10/min・actor レーン)。
+|   自前 route の inline throttle は T125 で全廃済みで、InlineThrottleInventoryTest の
+|   enum に自前 route 向けの case が無いため**登録できない** = 構造的に使えない。
+|   姉妹の invitations.accept.store (invitation-accept-submit) とも別レーンにする
+|   (片方の連打がもう片方の入口を 429 で塞がないため)。
+*/
+Route::post('/invitations/{invitation}/accept-in-app', AcceptInvitationInAppController::class)
+    ->middleware(['auth', 'verified', 'throttle:invitation-accept-in-app'])
+    ->name('invitations.accept-in-app');
+
 /*
 |--------------------------------------------------------------------------
 | local 専用デバッグログイン
diff --git a/tests/Architecture/ControllerAuthorizationGateTest.php b/tests/Architecture/ControllerAuthorizationGateTest.php
index b4a72df..19b483e 100644
--- a/tests/Architecture/ControllerAuthorizationGateTest.php
+++ b/tests/Architecture/ControllerAuthorizationGateTest.php
@@ -90,6 +90,13 @@ function controllerAuthorizationExemptions(): array
             .'token hash 照合と失効/期限/受諾済み判定を行う。受諾前の user は対象組織の非メンバーであり、'
             .'組織 Policy を通すと構造的に必ず拒否になる (機能が成立しない)。'],
 
+        'invitations.accept-in-app' => [$selfScoped,
+            '対象は認証ユーザー自身宛の招待のみ。acceptPendingInvitation が '
+            .'scopeActivePendingForEmail($user->email) の集合からしか解決せず、宛先不一致・不在・'
+            .'期限切れ・取消済・削除済み組織宛はすべて 404 に畳まれる。受諾前の user は対象組織の'
+            .'非メンバーであり組織 Policy は構造的に必ず拒否になるうえ、403 を返すこと自体が'
+            .'招待の存在を教える口になるため Gate を置かない (層 2 の 404 が層 3 より前という不変条件)。'],
+
         'settings.account.destroy' => [$selfScoped,
             '対象は $request->user() 自身のみ。route に他者を指せる parameter が 1 つも無く、'
             .'他人のアカウントへ到達する経路がコード上存在しない。'
diff --git a/tests/Architecture/InvitationResolutionInventoryTest.php b/tests/Architecture/InvitationResolutionInventoryTest.php
new file mode 100644
index 0000000..0c466db
--- /dev/null
+++ b/tests/Architecture/InvitationResolutionInventoryTest.php
@@ -0,0 +1,311 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Security\InvitationResolutionScope;
+
+/*
+ * OrganizationInvitation のクエリ起点の deny-by-default 目録。
+ *
+ * 守る不変条件 (裁定 AG-113 の必須要素 (b)):
+ *   「**受信者視点の解決・一覧・件数は、必ず scopeActivePendingForEmail の 1 本を再利用する**」
+ * これがずれると「件数は出るのに受諾できない」が起き、さらに悪いことに、
+ * 受信者向け経路が別の絞り込みで招待に到達すると**他人宛の招待に届く存在オラクル**になる。
+ *
+ * 本テストは app/ 配下で OrganizationInvitation のクエリを起点する箇所を機械抽出し、
+ * 5 分類のいずれかへの登録を要求する (未登録は fail / 実在しない登録も fail)。
+ * さらに RecipientScopedPendingSet に分類した箇所は、**本文に activePendingForEmail(
+ * が現れること**を要求する (分類したのに別の絞り込みを書く退行の検出)。
+ *
+ * ★空振り対策を 3 つ持つ:
+ *   (1) 母集団 floor — 抽出が 0 件 / 縮小したら fail (セレクタの空振り検出)
+ *   (2) RecipientScopedPendingSet の exact-fit cap — 現在値ちょうど。
+ *       受信者視点の解決口を増やす差分は必ずこの数値を変える形で現れ、再レビューを強制する
+ *   (3) 負のコントロール — 分類済み各 case が 1 件以上存在すること (分類の死に枝を作らない)
+ *
+ * ★**保証範囲を誇張しない**: 抽出は字句 (文字列一致) ベースであり、
+ *   `$model = OrganizationInvitation::class; $model::query()` のように変数経由で
+ *   モデルを扱う書き方は検出できない。これは既存の ModelDirectFetchInvariantTest /
+ *   MembershipWriteLockInventoryTest と同じ限界である。
+ */
+
+/** 目録キー = "{app/ からの相対パス}#{メソッド名}"。 */
+function invitationResolutionInventory(): array
+{
+    $recipient = InvitationResolutionScope::RecipientScopedPendingSet;
+    $token = InvitationResolutionScope::TokenHashLookup;
+    $orgScoped = InvitationResolutionScope::OrganizationScoped;
+    $model = InvitationResolutionScope::ModelInternal;
+    $lockedReload = InvitationResolutionScope::LockedRowReload;
+
+    return [
+        'Models/OrganizationInvitation.php#findActiveByPlainToken' => [$model,
+            '平文 token の sha256 照合による active 解決の単一口。email での絞り込みを持たない'
+            .' (列挙面を広げない)。MatchesInvitationEmail / acceptInvitationIfValid /'
+            .' register prefill が共有する。'],
+        'Models/OrganizationInvitation.php#scopeActive' => [$model,
+            'active (未受諾・未失効・期限内) の定義そのもの。受諾可能性の判定条件を'
+            .' 1 箇所に集約し、個別判定 (isExpired / isAccepted / isRevoked) とのドリフトを防ぐ。'],
+        'Models/OrganizationInvitation.php#scopeActivePendingForEmail' => [$model,
+            '受信者視点の絞り込みの定義そのもの。active + blind index 完全一致 + 組織実在の'
+            .' 3 条件がすべて 0 件へ collapse することが、一律 404 に畳める根拠。'],
+
+        'Http/Controllers/Organizations/InvitationAcceptanceController.php#show' => [$token,
+            '署名なし token URL の確認画面。token_hash 照合で 1 件引き、無効理由 (不在/取消/受諾済/'
+            .'期限切れ) を出し分けずに同一の Invitations/Invalid ページへ畳む (token オラクル封じ)。'],
+        'Http/Controllers/Admin/UserManagementController.php#index' => [$orgScoped,
+            '管理者視点の招待一覧。$organization->invitations() 経由でのみ引き、現在組織の'
+            .'招待だけを列挙する (cross-org 不可。organization は membership スコープの binder 解決済み)。'],
+        'Rules/MatchesInvitationEmail.php#validate' => [$token,
+            'register フォームの email 一致検証。session の平文 token を findActiveByPlainToken へ'
+            .'渡すだけで、email から招待を引くことはしない (列挙面を広げない)。'],
+
+        'Services/Organization/OrganizationMembershipService.php#pendingInvitationsQuery' => [$recipient,
+            '受信者視点の唯一のクエリ起点。未ログイン・未 verified・email 空は null を返し'
+            .' DB を引かない。一覧・件数・受諾解決がすべてここを通る。'],
+        'Services/Organization/OrganizationMembershipService.php#acceptInvitation' => [$token,
+            'POST token 受諾。token_hash 照合で解決し、失効/期限/受諾済みを個別メッセージに'
+            .'出し分ける (token 保持者向けの既存契約)。'],
+        'Services/Organization/OrganizationMembershipService.php#acceptInvitationIfValid' => [$token,
+            'register 経路の受諾。findActiveByPlainToken で解決し、招待 email と登録 email の'
+            .'一致を要求する (MatchesInvitationEmail と対で二重防御)。'],
+        'Services/Organization/OrganizationMembershipService.php#resolveRegisterPrefillEmail' => [$token,
+            'register 画面の email prefill。session の平文 token を token_hash 照合で解決するだけで、'
+            .'email 側から招待を引かない (stale token は forget して null)。'],
+        'Services/Organization/OrganizationMembershipService.php#joinOrganization' => [$lockedReload,
+            '受諾確定の共通コア。id は呼び出し元が既に解決済みの招待の主キーで、ここでは'
+            .'ロック下の再検証 (受諾済/取消/期限) のために同じ行を読み直すだけ (新しい到達経路を作らない)。'],
+        'Services/Organization/OrganizationMembershipService.php#hasPendingInvitation' => [$orgScoped,
+            '管理者視点。$organization->invitations() 経由で同一組織内の重複招待だけを見る'
+            .' (中立メッセージのための存在判定であり受信者視点ではない)。'],
+    ];
+}
+
+/** 理由の最低文字数 (「短い」で通さない)。 */
+function invitationResolutionReasonMinLength(): int
+{
+    return 30;
+}
+
+/**
+ * 抽出されるクエリ起点の下限 (空振り drift ガード)。**実測値ちょうど**。
+ * セレクタが壊れて母集団が縮んだら fail する。
+ */
+function invitationResolutionSiteFloor(): int
+{
+    return 12;
+}
+
+/**
+ * RecipientScopedPendingSet の exact-fit cap (**現在値ちょうど**)。
+ *
+ * 受信者視点の解決口を増やす差分は必ずこの数値を変える形で現れ、
+ * 「その経路も scopeActivePendingForEmail を再利用しているか」の再レビューを強制する。
+ */
+function invitationResolutionRecipientCap(): int
+{
+    return 1;
+}
+
+/**
+ * OrganizationInvitation のクエリ起点とみなす字句セレクタ。
+ *
+ * @return list<string>
+ */
+function invitationResolutionSelectors(): array
+{
+    return [
+        'OrganizationInvitation::query(',
+        'OrganizationInvitation::where',
+        'OrganizationInvitation::find',
+        '->invitations()',
+        'activePendingForEmail(',
+    ];
+}
+
+/**
+ * モデル自身のファイル (app/Models/OrganizationInvitation.php) にだけ適用する追加セレクタ。
+ * scope 定義 / 静的解決口はモデル内では `$query->` / `self::query(` の形で書かれるため。
+ *
+ * @return list<string>
+ */
+function invitationResolutionModelSelectors(): array
+{
+    return ['self::query(', '$query->'];
+}
+
+/** app/ 配下の PHP ファイル (相対パス => 絶対パス)。 */
+function invitationResolutionAppFiles(): array
+{
+    $appDir = dirname(__DIR__, 2).'/app';
+    $iterator = new RecursiveIteratorIterator(
+        new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS),
+    );
+
+    $files = [];
+    /** @var SplFileInfo $file */
+    foreach ($iterator as $file) {
+        if ($file->getExtension() !== 'php') {
+            continue;
+        }
+        $files[str_replace($appDir.'/', '', $file->getPathname())] = $file->getPathname();
+    }
+    ksort($files);
+
+    return $files;
+}
+
+/**
+ * クエリ起点を持つメソッドを抽出する。
+ *
+ * ReflectionClass の getStartLine()/getEndLine() でメソッド本文へ切り分け、
+ * 本文にセレクタが現れるものだけを返す (MembershipWriteLockInventoryTest の bodyOf() と同じ手法)。
+ *
+ * @return array<string, string> 目録キー => メソッド本文
+ */
+function invitationResolutionSites(): array
+{
+    $modelRelativePath = 'Models/OrganizationInvitation.php';
+    $sites = [];
+
+    foreach (invitationResolutionAppFiles() as $relative => $absolute) {
+        $class = 'App\\'.str_replace('/', '\\', substr($relative, 0, -4));
+        if (! class_exists($class) && ! trait_exists($class) && ! interface_exists($class) && ! enum_exists($class)) {
+            continue;
+        }
+
+        $selectors = invitationResolutionSelectors();
+        if ($relative === $modelRelativePath) {
+            $selectors = [...$selectors, ...invitationResolutionModelSelectors()];
+        }
+
+        $lines = file($absolute);
+        if ($lines === false) {
+            continue;
+        }
+
+        $reflection = new ReflectionClass($class);
+        foreach ($reflection->getMethods() as $method) {
+            if ($method->getDeclaringClass()->getName() !== $class) {
+                continue;
+            }
+            // trait 由来のメソッドは declaringClass が使用側クラスになるが、行番号は
+            // trait のファイルを指す。別ファイルの行を切り出さないよう定義ファイルで弾く。
+            if ($method->getFileName() !== $absolute) {
+                continue;
+            }
+            $start = $method->getStartLine();
+            $end = $method->getEndLine();
+            if ($start === false || $end === false) {
+                continue;
+            }
+            $body = implode('', array_slice($lines, $start - 1, $end - $start + 1));
+
+            foreach ($selectors as $selector) {
+                if (str_contains($body, $selector)) {
+                    $sites[$relative.'#'.$method->getName()] = $body;
+
+                    break;
+                }
+            }
+        }
+    }
+
+    ksort($sites);
+
+    return $sites;
+}
+
+test('OrganizationInvitation のクエリ起点はすべて目録へ分類登録されている (未登録は fail)', function (): void {
+    $sites = invitationResolutionSites();
+    $inventory = invitationResolutionInventory();
+
+    $unclassified = array_values(array_diff(array_keys($sites), array_keys($inventory)));
+
+    expect($unclassified)->toBe([],
+        'OrganizationInvitation の新しいクエリ起点は InvitationResolutionScope の'
+        .'いずれかへ理由付きで登録してください (deny-by-default)。'
+        .PHP_EOL.implode(PHP_EOL, $unclassified));
+});
+
+test('目録に実在しないクエリ起点が残っていない (stale 検出)', function (): void {
+    $sites = invitationResolutionSites();
+    $inventory = invitationResolutionInventory();
+
+    $stale = array_values(array_diff(array_keys($inventory), array_keys($sites)));
+
+    expect($stale)->toBe([],
+        '目録に実在しないクエリ起点が残っています (メソッド名変更 / 削除に追随してください)。'
+        .PHP_EOL.implode(PHP_EOL, $stale));
+});
+
+test('目録の理由は 30 文字以上で分類は enum である', function (): void {
+    $violations = [];
+
+    foreach (invitationResolutionInventory() as $key => $entry) {
+        [$scope, $reason] = $entry;
+        expect($scope)->toBeInstanceOf(InvitationResolutionScope::class);
+        if (mb_strlen($reason) < invitationResolutionReasonMinLength()) {
+            $violations[] = $key.' (理由 '.mb_strlen($reason).' 文字)';
+        }
+    }
+
+    expect($violations)->toBe([],
+        '目録の理由は '.invitationResolutionReasonMinLength().' 文字以上で書いてください。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('受信者視点に分類した箇所は scopeActivePendingForEmail を再利用している', function (): void {
+    $sites = invitationResolutionSites();
+    $violations = [];
+
+    foreach (invitationResolutionInventory() as $key => [$scope, $reason]) {
+        if ($scope !== InvitationResolutionScope::RecipientScopedPendingSet) {
+            continue;
+        }
+        $body = $sites[$key] ?? '';
+        if (! str_contains($body, 'activePendingForEmail(')) {
+            $violations[] = $key;
+        }
+    }
+
+    expect($violations)->toBe([],
+        '受信者視点の解決は scopeActivePendingForEmail の再利用のみで書いてください '
+        .'(絞り込みを手書きすると件数と受諾がずれ、他人宛の招待へ届く経路になりうる)。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('抽出件数が floor を下回らない (セレクタ空振りの検出)', function (): void {
+    $sites = invitationResolutionSites();
+
+    expect(count($sites))->toBeGreaterThanOrEqual(invitationResolutionSiteFloor(),
+        'OrganizationInvitation のクエリ起点の抽出件数が floor を下回りました。'
+        .'セレクタが空振りしている可能性があります (削除が意図的なら floor を下げてください)。');
+});
+
+test('受信者視点の分類件数が exact-fit cap ちょうどである', function (): void {
+    $recipients = array_keys(array_filter(
+        invitationResolutionInventory(),
+        static fn (array $entry): bool => $entry[0] === InvitationResolutionScope::RecipientScopedPendingSet,
+    ));
+
+    expect(count($recipients))->toBe(invitationResolutionRecipientCap(),
+        '受信者視点の解決口の数が変わりました。増やす場合は「その経路も'
+        .' scopeActivePendingForEmail を再利用しているか」を再レビューし、'
+        .'invitationResolutionRecipientCap() を書き換えてください。');
+});
+
+test('各分類 case に 1 件以上の実体がある (死に枝を作らない)', function (): void {
+    $used = array_map(static fn (array $entry): string => $entry[0]->value, invitationResolutionInventory());
+    $missing = [];
+
+    foreach (InvitationResolutionScope::cases() as $case) {
+        if (! in_array($case->value, $used, true)) {
+            $missing[] = $case->value;
+        }
+    }
+
+    expect($missing)->toBe([],
+        '実体の無い分類 case があります (使われない分類は形骸化するため、'
+        .'case を消すか実体を分類してください)。'.PHP_EOL.implode(PHP_EOL, $missing));
+});
diff --git a/tests/Architecture/MembershipWriteLockInventoryTest.php b/tests/Architecture/MembershipWriteLockInventoryTest.php
index 8ff1ae1..cfaa4fc 100644
--- a/tests/Architecture/MembershipWriteLockInventoryTest.php
+++ b/tests/Architecture/MembershipWriteLockInventoryTest.php
@@ -19,11 +19,17 @@
     // 自身の tx 冒頭で直接ロックする mutating メソッド
     $directLock = ['applyConsoleRole', 'changeRole', 'removeMember', 'transferOwnership', 'deleteAccount'];
     // ロック済み内部メソッド (joinOrganization) 経由で間接的にロックされる受諾経路
-    $delegatedToLocked = ['acceptInvitation', 'acceptInvitationIfValid'];
+    $delegatedToLocked = ['acceptInvitation', 'acceptInvitationIfValid', 'acceptPendingInvitation'];
     // ロック不要 (membership/role を変えない) と判断した書き込みメソッド (根拠付き exempt)
     $exempt = [
         'inviteMember',     // 招待レコード生成のみ (membership/role 不変)
-        'revokeInvitation', // 招待の論理失効のみ (membership/role 不変)
+        // 招待の論理失効のみ (membership/role 不変)。**受諾との競合の最終権威は
+        // joinOrganization が取る招待行の lockForUpdate 側にあり**、取り消しの UPDATE も
+        // 同じ行を取るため直列化される (ここで membership ロックを取る必要はない)
+        'revokeInvitation',
+        // 受信者視点の read-only (表示・件数)。membership/role を変えない
+        'pendingInvitationsFor',
+        'pendingInvitationCountFor',
         // 読み取り専用判定 (ロック不要・表示スナップショット)。deleteAccount がロック下で権威判定する
         'organizationsBlockingDeletion',
         // 課金孤児の検知バッチ用の読み取り専用列挙 (Owner 不在の組織)。membership/role を変えない
@@ -76,6 +82,91 @@
     expect($firstLock)->toBeLessThan($orgEnumeration, 'deleteAccount は組織列挙の前に user 行をロックすること');
 });
 
+/*
+ * joinOrganization() の戻り値 (bool) 消費 drift-guard。
+ *
+ * joinOrganization は「ロック下再検証で受諾不能だった」を false で返す。false を捨てると
+ * 呼び出し元は受諾できていないのに成功扱いで応答してしまう (register 経路では非所属 org を
+ * current_organization_id に据える非正規状態まで作る)。
+ *
+ * 本検査は token_get_all() で**呼び出し式の形**だけを見る (契約の正しさは
+ * InvitationAcceptRaceTest が behavioral に見る。2 本は役割が違うので併存させる)。
+ * 判定は「破棄形の拒否」で、許可形の列挙はしない (&& / || / ( / , など値が使われる文脈は
+ * 無数にあり、許可側を列挙すると正しい実装を落とすため)。
+ */
+test('joinOrganization() の戻り値を破棄している呼び出しが無い (受諾不能を成功扱いにしない)', function (): void {
+    $reflection = new ReflectionClass(OrganizationMembershipService::class);
+    $path = $reflection->getFileName();
+    expect($path)->not->toBeFalse();
+    $source = file_get_contents((string) $path);
+    expect($source)->not->toBeFalse();
+
+    $tokens = token_get_all((string) $source);
+    /** 空白 / コメントを飛ばして有意トークンの index 列を作る */
+    $significant = [];
+    foreach ($tokens as $index => $token) {
+        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
+            continue;
+        }
+        $significant[] = $index;
+    }
+
+    $callCount = 0;
+    $violations = [];
+    $unknownForms = [];
+
+    foreach ($significant as $position => $index) {
+        $token = $tokens[$index];
+        if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'joinOrganization') {
+            continue;
+        }
+        // 呼び出しであること (次の有意トークンが `(`)
+        $next = $tokens[$significant[$position + 1] ?? $index] ?? null;
+        if ($next !== '(') {
+            continue;
+        }
+        // メソッド宣言 (`private function joinOrganization(`) は呼び出しではない
+        $prev = $tokens[$significant[$position - 1] ?? $index] ?? null;
+        if (is_array($prev) && $prev[0] === T_FUNCTION) {
+            continue;
+        }
+
+        $callCount++;
+        $line = $token[2];
+
+        // `$this->joinOrganization(` の形であること (未知の呼び出し形は deny-by-default)
+        $prevPrev = $tokens[$significant[$position - 2] ?? $index] ?? null;
+        $isThisCall = is_array($prev) && $prev[0] === T_OBJECT_OPERATOR
+            && is_array($prevPrev) && $prevPrev[0] === T_VARIABLE && $prevPrev[1] === '$this';
+        if (! $isThisCall) {
+            $unknownForms[] = "line {$line}";
+
+            continue;
+        }
+
+        // さらに 1 つ前が `;` / `{` / `}` なら式文 = 戻り値の破棄
+        $beforeCall = $tokens[$significant[$position - 3] ?? $index] ?? null;
+        if (in_array($beforeCall, [';', '{', '}'], true)) {
+            $violations[] = "line {$line}";
+        }
+    }
+
+    expect($unknownForms)->toBe([],
+        'joinOrganization() の未知の呼び出し形を検出しました (人のレビューを通すため fail させています)。'
+        .PHP_EOL.implode(PHP_EOL, $unknownForms));
+
+    expect($violations)->toBe([],
+        'joinOrganization() の戻り値 (false = ロック下再検証で受諾不能) を破棄している呼び出しがあります。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+
+    // exact-fit: 現在の呼び出し元は acceptInvitation / acceptInvitationIfValid /
+    // acceptPendingInvitation の 3 つ。増減は必ずこの数値を変える差分として現れ、
+    // 「その経路でも false を正しく消費しているか」の再レビューを強制する。
+    expect($callCount)->toBe(3,
+        'joinOrganization() の呼び出し元の数が変わりました。新しい経路が false を'
+        .'正しく消費しているかを確認してからこの数値を更新してください。');
+});
+
 /*
  * role-grant sole-gateway drift-guard。
  *
diff --git a/tests/Architecture/RateLimiterKeyConventionTest.php b/tests/Architecture/RateLimiterKeyConventionTest.php
index 33d5c0e..cc5f5c9 100644
--- a/tests/Architecture/RateLimiterKeyConventionTest.php
+++ b/tests/Architecture/RateLimiterKeyConventionTest.php
@@ -205,6 +205,17 @@ function rateLimiterKeyInventory(): array
             'expectedKeyPrefixes' => ['invitation-accept:ip'],
             'emailScenarios' => [],
         ],
+        // アプリ内受諾 (T134)。RateLimiterKeys::actorOrIp の actor/IP 2 分岐。
+        // route parameter ({invitation}) はキーに混ぜない (bucket が id ごとに分かれると
+        // 「429 になるまでの回数」が招待の実在オラクルになる)。
+        'invitation-accept-in-app' => [
+            'scenarios' => [
+                'authenticated' => static fn (): Request => rateLimiterAuthenticatedRequest(rateLimiterProbeUser()),
+                'guest' => $noEmail,
+            ],
+            'expectedKeyPrefixes' => ['invitation-accept-in-app:user', 'invitation-accept-in-app:ip'],
+            'emailScenarios' => [],
+        ],
         // 認証済み / 未認証の 2 分岐 (passkeys と同じ形)。
         // throttle は auth middleware より先に走るため guest 分岐も実在する。
         'two-factor-secret-read' => [
@@ -460,6 +471,8 @@ function rateLimiterActorOrIpFullKeys(): array
         'two-factor-manage',
         'invitation-accept-submit',
         'plan-activate',
+        // T134 で新設。helper 経由なので同じ full key 契約に載る
+        'invitation-accept-in-app',
     ];
 
     $expected = [];
diff --git a/tests/Feature/Admin/UserManagementPageTest.php b/tests/Feature/Admin/UserManagementPageTest.php
index 4a07e39..a905e83 100644
--- a/tests/Feature/Admin/UserManagementPageTest.php
+++ b/tests/Feature/Admin/UserManagementPageTest.php
@@ -16,8 +16,8 @@
 
 test('org Owner は 200 + Admin/Users component で members/invitations shape を受け取る', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
-    OrganizationInvitation::factory()->forOrganization($organization)->editorInvitation()
-        ->create(['email' => 'pending-editor@example.com']);
+    OrganizationInvitation::factory()->forOrganization($organization)
+        ->create(['email' => 'pending-member@example.com', 'role' => OrganizationRole::Member->value]);
 
     $response = $this->actingAs($owner)->get('/manage/users');
 
@@ -27,8 +27,9 @@
         ->where('organizationSlug', $organization->slug)
         ->where('members.0.roleState', 'owner')
         ->where('members.0.isSelf', true)
-        ->where('invitations.0.email', 'pending-editor@example.com')
-        ->where('invitations.0.roleState', 'editor')
+        ->where('invitations.0.email', 'pending-member@example.com')
+        ->where('invitations.0.role', OrganizationRole::Member->value)
+        ->where('invitations.0.roleLabel', 'メンバー')
         ->where('hasDefaultProject', false)
         // T071: 独自二次左メニュー(AdminMenuNav)撤去に伴い categoriesUrl prop は廃止 → 存在しない
         ->missing('categoriesUrl'));
@@ -137,8 +138,9 @@
     $response->assertInertia(fn ($page) => $page
         ->count('invitations', 1)
         ->where('invitations.0.email', 'active@example.com')
-        // 旧招待 (project_role なし) は未割当語彙で表示される
-        ->where('invitations.0.roleState', 'unassigned'));
+        // 招待は org ロールで表示される (役割付き招待は AG-079 で撤去)
+        ->where('invitations.0.role', OrganizationRole::Member->value)
+        ->where('invitations.0.roleLabel', 'メンバー'));
 });
 
 test('current org 未設定 (組織未所属状態) は 404', function (): void {
diff --git a/tests/Feature/Auth/EmailVerificationGateTest.php b/tests/Feature/Auth/EmailVerificationGateTest.php
index 87e543f..ae52c1a 100644
--- a/tests/Feature/Auth/EmailVerificationGateTest.php
+++ b/tests/Feature/Auth/EmailVerificationGateTest.php
@@ -2,8 +2,8 @@
 
 declare(strict_types=1);
 
-use App\Enums\AdminConsoleRole;
 use App\Enums\Auth\EmailVerificationGateContext;
+use App\Enums\OrganizationRole;
 use App\Models\User;
 use Illuminate\Support\Facades\Notification;
 use Illuminate\Support\Facades\Route;
@@ -109,7 +109,7 @@
         ->from("/organizations/{$organization->slug}/settings")
         ->post("/organizations/{$organization->slug}/invitations", [
             'email' => 'invitee@example.com',
-            'role' => AdminConsoleRole::Admin->value,
+            'role' => OrganizationRole::Admin->value,
         ]);
 
     // referer (from) が同一オリジンなのでそこへ戻す + invite 文言の error flash。
@@ -126,7 +126,7 @@
         ->from("/organizations/{$organization->slug}/settings")
         ->post("/organizations/{$organization->slug}/invitations", [
             'email' => 'invitee@example.com',
-            'role' => AdminConsoleRole::Admin->value,
+            'role' => OrganizationRole::Admin->value,
         ]);
 
     // gate は通過し招待が送信される (back + success flash)。
diff --git a/tests/Feature/Invitations/AcceptInvitationInAppTest.php b/tests/Feature/Invitations/AcceptInvitationInAppTest.php
new file mode 100644
index 0000000..1a61413
--- /dev/null
+++ b/tests/Feature/Invitations/AcceptInvitationInAppTest.php
@@ -0,0 +1,159 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\OrganizationRole;
+use App\Models\OrganizationInvitation;
+use App\Models\User;
+
+/*
+ * アプリ内受諾 (POST invitations.accept-in-app) の**存在秘匿の網羅**。
+ *
+ * 業務上の受諾不能 (宛先不一致 / 不在 / 期限切れ / 取消済 / 受諾済 / 削除済み組織宛) は
+ * **すべて 404** に畳む (403 を返さない = 招待の存在を教えない)。
+ */
+
+/** 受諾 URL。 */
+function acceptInAppUrl(int|string $invitationId): string
+{
+    return "/invitations/{$invitationId}/accept-in-app";
+}
+
+test('自分宛の有効な招待を受諾できる', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
+    $invitation = OrganizationInvitation::factory()->forOrganization($organization)->asAdmin()
+        ->create(['email' => 'invitee@example.com']);
+
+    $response = $this->actingAs($invitee)->post(acceptInAppUrl($invitation->id));
+
+    $response->assertRedirect(route('dashboard'));
+    $response->assertSessionHas('success', "「{$organization->name}」に参加しました");
+    expect($organization->users()->whereKey($invitee->id)->exists())->toBeTrue();
+    expect($invitation->refresh()->isAccepted())->toBeTrue();
+    expect($invitee->fresh()?->organizationRole($organization))->toBe(OrganizationRole::Admin);
+});
+
+test('受諾しても現在組織は切り替わらない', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    [$ownOrganization, $invitee] = createOrganizationWithOwner('自分の組織');
+    $invitation = OrganizationInvitation::factory()->forOrganization($organization)
+        ->create(['email' => $invitee->email]);
+
+    $this->actingAs($invitee)->post(acceptInAppUrl($invitation->id))->assertRedirect(route('dashboard'));
+
+    expect($invitee->fresh()?->current_organization_id)->toBe($ownOrganization->id);
+});
+
+test('他人宛の実在する招待は 404 (403 ではない)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
+    $invitation = OrganizationInvitation::factory()->forOrganization($organization)
+        ->create(['email' => 'someone-else@example.com']);
+
+    $response = $this->actingAs($invitee)->post(acceptInAppUrl($invitation->id));
+
+    $response->assertNotFound();
+    expect($response->getStatusCode())->not->toBe(403); // 403 は存在を教えるため使わない
+    expect($organization->users()->whereKey($invitee->id)->exists())->toBeFalse();
+});
+
+test('不在 id は 404', function (): void {
+    $invitee = User::factory()->create();
+
+    $this->actingAs($invitee)->post(acceptInAppUrl(999999))->assertNotFound();
+});
+
+test('期限切れ・取消済・受諾済は 404', function (string $state): void {
+    [$organization] = createOrganizationWithOwner();
+    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
+    $invitation = OrganizationInvitation::factory()->forOrganization($organization)->{$state}()
+        ->create(['email' => 'invitee@example.com']);
+
+    $this->actingAs($invitee)->post(acceptInAppUrl($invitation->id))->assertNotFound();
+    expect($organization->users()->whereKey($invitee->id)->exists())->toBeFalse();
+})->with(['expired', 'revoked', 'accepted']);
+
+test('削除済み (soft-deleted) 組織宛は 404', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
+    $invitation = OrganizationInvitation::factory()->forOrganization($organization)
+        ->create(['email' => 'invitee@example.com']);
+    $organization->delete();
+
+    $this->actingAs($invitee)->post(acceptInAppUrl($invitation->id))->assertNotFound();
+});
+
+test('受諾直後の再 POST は 404 (冪等 200 にしない = 秘匿)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
+    $invitation = OrganizationInvitation::factory()->forOrganization($organization)
+        ->create(['email' => 'invitee@example.com']);
+
+    $this->actingAs($invitee)->post(acceptInAppUrl($invitation->id))->assertRedirect(route('dashboard'));
+    $this->actingAs($invitee)->post(acceptInAppUrl($invitation->id))->assertNotFound();
+});
+
+test('既にメンバーの user 宛の招待は冪等に成功する (insertOrIgnore の 0 行分岐)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
+    $organization->users()->attach($invitee);
+    $invitee->addRole(OrganizationRole::Member->value, $organization->laratrust_team_id);
+    $invitation = OrganizationInvitation::factory()->forOrganization($organization)
+        ->create(['email' => 'invitee@example.com']);
+
+    $response = $this->actingAs($invitee)->post(acceptInAppUrl($invitation->id));
+
+    $response->assertRedirect(route('dashboard'));
+    $response->assertSessionHas('success');
+    expect($organization->users()->whereKey($invitee->id)->count())->toBe(1);
+    expect($invitation->refresh()->isAccepted())->toBeTrue();
+});
+
+test('未 verified は verified middleware で遮断され、実在 id と不在 id で応答が同一', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $invitee = User::factory()->unverified()->create(['email' => 'invitee@example.com']);
+    $invitation = OrganizationInvitation::factory()->forOrganization($organization)
+        ->create(['email' => 'invitee@example.com']);
+
+    $existing = $this->actingAs($invitee)->post(acceptInAppUrl($invitation->id));
+    $missing = $this->actingAs($invitee)->post(acceptInAppUrl(999999));
+
+    // 存在オラクルが無いこと: status も location も同一
+    expect($existing->getStatusCode())->toBe($missing->getStatusCode());
+    expect($existing->headers->get('Location'))->toBe($missing->headers->get('Location'));
+    expect($organization->users()->whereKey($invitee->id)->exists())->toBeFalse();
+});
+
+test('guest は login へ 302', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $invitation = OrganizationInvitation::factory()->forOrganization($organization)
+        ->create(['email' => 'invitee@example.com']);
+
+    $this->post(acceptInAppUrl($invitation->id))->assertRedirect(route('login'));
+});
+
+test('非数値 id / 19 桁 id は 404 (500 にならない)', function (string $id): void {
+    $invitee = User::factory()->create();
+
+    $this->actingAs($invitee)->post(acceptInAppUrl($id))->assertNotFound();
+})->with(['abc', '1234567890123456789']);
+
+test('throttle: 不在 id へ 10 回 POST はすべて 404、11 回目が 429', function (): void {
+    $invitee = User::factory()->create();
+
+    for ($i = 0; $i < 10; $i++) {
+        $this->actingAs($invitee)->post(acceptInAppUrl(999999))->assertNotFound();
+    }
+
+    $this->actingAs($invitee)->post(acceptInAppUrl(999999))->assertStatus(429);
+});
+
+test('throttle: 有効な招待への正常受諾 1 回は 429 にならない', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
+    $invitation = OrganizationInvitation::factory()->forOrganization($organization)
+        ->create(['email' => 'invitee@example.com']);
+
+    $this->actingAs($invitee)->post(acceptInAppUrl($invitation->id))->assertRedirect(route('dashboard'));
+});
diff --git a/tests/Feature/Invitations/PendingInvitationForUserDtoTest.php b/tests/Feature/Invitations/PendingInvitationForUserDtoTest.php
new file mode 100644
index 0000000..45aff13
--- /dev/null
+++ b/tests/Feature/Invitations/PendingInvitationForUserDtoTest.php
@@ -0,0 +1,58 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Invitations\PendingInvitationForUserDto;
+use App\Enums\OrganizationRole;
+use App\Models\OrganizationInvitation;
+
+/*
+ * 受信者視点 DTO の開示面。管理者視点 (InvitationRowData) とは別契約であり、
+ * email / token_hash / 生の日時 / 招待者 id / 組織 id を出さない。
+ */
+
+test('開示項目は 4 つだけ (キー追加を機械検出する)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $invitation = OrganizationInvitation::factory()->forOrganization($organization)
+        ->create(['email' => 'me@example.com']);
+
+    $dto = PendingInvitationForUserDto::fromInvitation($invitation);
+
+    expect(array_keys($dto->toArray()))->toBe(['id', 'organizationName', 'roleLabel', 'expiresAt']);
+});
+
+test('email / token_hash を含まない (値ベースの negative control)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $invitation = OrganizationInvitation::factory()->forOrganization($organization)
+        ->create(['email' => 'secret-invitee@example.com']);
+
+    $json = json_encode(PendingInvitationForUserDto::fromInvitation($invitation)->toArray());
+    $tokenHash = $invitation->getAttribute('token_hash');
+
+    expect($json)->not->toContain('secret-invitee@example.com');
+    expect(is_string($tokenHash) && $tokenHash !== '')->toBeTrue();
+    expect($json)->not->toContain((string) $tokenHash);
+});
+
+test('roleLabel は org ロールのラベル', function (string $role, string $label): void {
+    [$organization] = createOrganizationWithOwner();
+    $invitation = OrganizationInvitation::factory()->forOrganization($organization)
+        ->create(['email' => 'me@example.com', 'role' => $role]);
+
+    expect(PendingInvitationForUserDto::fromInvitation($invitation)->roleLabel)->toBe($label);
+})->with([
+    [OrganizationRole::Admin->value, '管理者'],
+    [OrganizationRole::Member->value, 'メンバー'],
+]);
+
+test('expiresAt は Y-m-d の文字列', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $invitation = OrganizationInvitation::factory()->forOrganization($organization)
+        ->create(['email' => 'me@example.com', 'expires_at' => '2026-09-30 13:45:00']);
+
+    $dto = PendingInvitationForUserDto::fromInvitation($invitation);
+
+    expect($dto->expiresAt)->toBe('2026-09-30');
+    expect($dto->organizationName)->toBe($organization->name);
+    expect($dto->id)->toBe($invitation->id);
+});
diff --git a/tests/Feature/Invitations/PendingInvitationQueryGuardTest.php b/tests/Feature/Invitations/PendingInvitationQueryGuardTest.php
new file mode 100644
index 0000000..0a9c3f5
--- /dev/null
+++ b/tests/Feature/Invitations/PendingInvitationQueryGuardTest.php
@@ -0,0 +1,86 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\OrganizationInvitation;
+use App\Models\User;
+use App\Services\Organization\OrganizationMembershipService;
+use Illuminate\Database\Events\QueryExecuted;
+use Illuminate\Support\Facades\DB;
+
+/*
+ * 裁定 AG-113 必須要素 (c) の behavioral proof:
+ * 未ログイン / 未 verified / email 空は **DB を一切引かない**。
+ *
+ * 共有 prop は全リクエストで評価されるため、この early return が実効的な負荷契約になる。
+ * 最後の 1 本は**負のコントロール** (guard が常に null を返す実装退行の検出)。
+ */
+
+/**
+ * organization_invitations に触れた SQL の本数を数えながら callback を実行する。
+ *
+ * @param  Closure(): void  $callback
+ */
+function countInvitationQueries(Closure $callback): int
+{
+    $count = 0;
+    DB::listen(function (QueryExecuted $query) use (&$count): void {
+        if (str_contains($query->sql, 'organization_invitations')) {
+            $count++;
+        }
+    });
+
+    $callback();
+
+    return $count;
+}
+
+test('未ログインは organization_invitations を引かない', function (): void {
+    $membership = app(OrganizationMembershipService::class);
+
+    $queries = countInvitationQueries(function () use ($membership): void {
+        expect($membership->pendingInvitationCountFor(null))->toBe(0);
+        expect($membership->pendingInvitationsFor(null))->toBe([]);
+    });
+
+    expect($queries)->toBe(0);
+});
+
+test('未 verified は organization_invitations を引かない', function (): void {
+    $membership = app(OrganizationMembershipService::class);
+    $user = User::factory()->unverified()->create(['email' => 'unverified@example.com']);
+
+    $queries = countInvitationQueries(function () use ($membership, $user): void {
+        expect($membership->pendingInvitationCountFor($user))->toBe(0);
+        expect($membership->pendingInvitationsFor($user))->toBe([]);
+    });
+
+    expect($queries)->toBe(0);
+});
+
+test('email 空は organization_invitations を引かない', function (): void {
+    $membership = app(OrganizationMembershipService::class);
+    $user = User::factory()->create();
+    $user->forceFill(['email' => ''])->save();
+
+    $queries = countInvitationQueries(function () use ($membership, $user): void {
+        expect($membership->pendingInvitationCountFor($user))->toBe(0);
+        expect($membership->pendingInvitationsFor($user))->toBe([]);
+    });
+
+    expect($queries)->toBe(0);
+});
+
+test('verified かつ email 非空のときだけ引く (負のコントロール)', function (): void {
+    $membership = app(OrganizationMembershipService::class);
+    [$organization] = createOrganizationWithOwner();
+    $user = User::factory()->create(['email' => 'invitee@example.com']);
+    OrganizationInvitation::factory()->forOrganization($organization)->create(['email' => 'invitee@example.com']);
+
+    $queries = countInvitationQueries(function () use ($membership, $user): void {
+        expect($membership->pendingInvitationCountFor($user))->toBe(1);
+        expect($membership->pendingInvitationsFor($user))->toHaveCount(1);
+    });
+
+    expect($queries)->toBeGreaterThan(0);
+});
diff --git a/tests/Feature/Invitations/PendingInvitationScopeTest.php b/tests/Feature/Invitations/PendingInvitationScopeTest.php
new file mode 100644
index 0000000..b05f484
--- /dev/null
+++ b/tests/Feature/Invitations/PendingInvitationScopeTest.php
@@ -0,0 +1,51 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\OrganizationInvitation;
+
+/*
+ * 受信者視点の単一解決口 scopeActivePendingForEmail の契約。
+ *
+ * 3 条件 (active / blind index 完全一致 / 組織実在) が**すべて同じ 0 件へ collapse する**
+ * ことが、呼び出し側で理由を出し分けずに一律 404 へ畳める根拠である。
+ */
+
+test('自分宛の active な招待だけを返す', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    OrganizationInvitation::factory()->forOrganization($organization)->create(['email' => 'me@example.com']);
+    OrganizationInvitation::factory()->forOrganization($organization)->create(['email' => 'other@example.com']);
+
+    $found = OrganizationInvitation::query()->activePendingForEmail('me@example.com')->get();
+
+    expect($found)->toHaveCount(1);
+    expect($found->sole()->email)->toBe('me@example.com');
+});
+
+test('期限切れ・取消済・受諾済は返さない', function (string $state): void {
+    [$organization] = createOrganizationWithOwner();
+    OrganizationInvitation::factory()->forOrganization($organization)->{$state}()
+        ->create(['email' => 'me@example.com']);
+
+    expect(OrganizationInvitation::query()->activePendingForEmail('me@example.com')->count())->toBe(0);
+})->with(['expired', 'revoked', 'accepted']);
+
+test('削除済み (soft-deleted) 組織宛は返さない', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    OrganizationInvitation::factory()->forOrganization($organization)->create(['email' => 'me@example.com']);
+
+    expect(OrganizationInvitation::query()->activePendingForEmail('me@example.com')->count())->toBe(1);
+
+    $organization->delete();
+
+    expect(OrganizationInvitation::query()->activePendingForEmail('me@example.com')->count())->toBe(0);
+});
+
+test('email の大小差は一致しない (blind index の完全一致契約)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    OrganizationInvitation::factory()->forOrganization($organization)->create(['email' => 'Foo@example.com']);
+
+    // fail-secure: 大小差は 0 件 = 呼び出し側では 404 に倒れる
+    expect(OrganizationInvitation::query()->activePendingForEmail('foo@example.com')->count())->toBe(0);
+    expect(OrganizationInvitation::query()->activePendingForEmail('Foo@example.com')->count())->toBe(1);
+});
diff --git a/tests/Feature/Invitations/PendingInvitationSharedPropTest.php b/tests/Feature/Invitations/PendingInvitationSharedPropTest.php
new file mode 100644
index 0000000..2cfc8ce
--- /dev/null
+++ b/tests/Feature/Invitations/PendingInvitationSharedPropTest.php
@@ -0,0 +1,99 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\OrganizationInvitation;
+use App\Models\User;
+use Illuminate\Database\Events\QueryExecuted;
+use Illuminate\Support\Facades\DB;
+use Inertia\Testing\AssertableInertia;
+
+/*
+ * 共有 prop invitationInbox.pendingCount の契約。
+ *
+ * 件数は受諾の解決・一覧と**同一 scope** から算出する (ずれると
+ * 「件数は出るのに受諾できない」が起きる)。未ログイン / 未 verified は DB を引かない。
+ */
+
+/**
+ * organization_invitations に触れた SQL の本数を数えながら callback を実行する。
+ *
+ * @param  Closure(): void  $callback
+ */
+function countInvitationQueriesDuringRequest(Closure $callback): int
+{
+    $count = 0;
+    DB::listen(function (QueryExecuted $query) use (&$count): void {
+        if (str_contains($query->sql, 'organization_invitations')) {
+            $count++;
+        }
+    });
+
+    $callback();
+
+    return $count;
+}
+
+test('未ログインのページでは pendingCount が 0 で DB を引かない', function (): void {
+    $queries = countInvitationQueriesDuringRequest(function (): void {
+        $this->get('/login')->assertInertia(
+            fn (AssertableInertia $page) => $page->where('invitationInbox.pendingCount', 0),
+        );
+    });
+
+    expect($queries)->toBe(0);
+});
+
+test('未 verified では 0 で DB を引かない', function (): void {
+    $user = User::factory()->unverified()->create(['email' => 'unverified@example.com']);
+    [$organization] = createOrganizationWithOwner();
+    OrganizationInvitation::factory()->forOrganization($organization)->create(['email' => 'unverified@example.com']);
+
+    // 未 verified が到達できる Inertia 画面 (メール確認案内) で共有 prop を検証する
+    $queries = countInvitationQueriesDuringRequest(function () use ($user): void {
+        $this->actingAs($user)->get(route('verification.notice'))->assertInertia(
+            fn (AssertableInertia $page) => $page->where('invitationInbox.pendingCount', 0),
+        );
+    });
+
+    expect($queries)->toBe(0);
+});
+
+test('verified かつ自分宛 active 招待 2 件で pendingCount = 2 (負のコントロール)', function (): void {
+    [$firstOrganization] = createOrganizationWithOwner('組織 A');
+    [$secondOrganization] = createOrganizationWithOwner('組織 B');
+    $user = User::factory()->create(['email' => 'invitee@example.com']);
+    OrganizationInvitation::factory()->forOrganization($firstOrganization)->create(['email' => 'invitee@example.com']);
+    OrganizationInvitation::factory()->forOrganization($secondOrganization)->create(['email' => 'invitee@example.com']);
+
+    $this->actingAs($user)->get('/notifications')->assertInertia(
+        fn (AssertableInertia $page) => $page->where('invitationInbox.pendingCount', 2),
+    );
+});
+
+test('他人宛の招待は数えない', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $user = User::factory()->create(['email' => 'invitee@example.com']);
+    OrganizationInvitation::factory()->forOrganization($organization)->create(['email' => 'someone-else@example.com']);
+
+    $this->actingAs($user)->get('/notifications')->assertInertia(
+        fn (AssertableInertia $page) => $page->where('invitationInbox.pendingCount', 0),
+    );
+});
+
+test('件数と一覧が一致する (scope 再利用の behavioral proof)', function (): void {
+    [$firstOrganization] = createOrganizationWithOwner('組織 A');
+    [$secondOrganization] = createOrganizationWithOwner('組織 B');
+    $user = User::factory()->create(['email' => 'invitee@example.com']);
+    OrganizationInvitation::factory()->forOrganization($firstOrganization)->create(['email' => 'invitee@example.com']);
+    OrganizationInvitation::factory()->forOrganization($secondOrganization)->create(['email' => 'invitee@example.com']);
+    // 数えてはいけないもの (取消済 / 他人宛) を混ぜる
+    OrganizationInvitation::factory()->forOrganization($firstOrganization)->revoked()->create(['email' => 'invitee@example.com']);
+    OrganizationInvitation::factory()->forOrganization($firstOrganization)->create(['email' => 'other@example.com']);
+
+    $this->actingAs($user)->get('/notifications')->assertInertia(
+        fn (AssertableInertia $page) => $page
+            ->where('invitationInbox.pendingCount', 2)
+            ->has('pendingInvitations', 2),
+    );
+});
diff --git a/tests/Feature/Notifications/InvitationNotificationTest.php b/tests/Feature/Notifications/InvitationNotificationTest.php
index 3534655..61904b6 100644
--- a/tests/Feature/Notifications/InvitationNotificationTest.php
+++ b/tests/Feature/Notifications/InvitationNotificationTest.php
@@ -2,7 +2,7 @@
 
 declare(strict_types=1);
 
-use App\Enums\AdminConsoleRole;
+use App\Enums\OrganizationRole;
 use App\Models\User;
 use App\Notifications\InApp\InvitationReceivedNotification;
 use App\Notifications\OrganizationInvitationNotification;
@@ -22,7 +22,7 @@
     $existing = User::factory()->create(['email' => 'invited@example.com']);
 
     app(OrganizationMembershipService::class)->inviteMember(
-        $organization, $owner, 'invited@example.com', AdminConsoleRole::Admin,
+        $organization, $owner, 'invited@example.com', OrganizationRole::Admin,
     );
 
     $rows = DB::table('notifications')->where('notifiable_id', $existing->id)->get();
@@ -40,7 +40,7 @@
     [$organization, $owner] = createOrganizationWithOwner();
 
     app(OrganizationMembershipService::class)->inviteMember(
-        $organization, $owner, 'nobody@example.com', AdminConsoleRole::Admin,
+        $organization, $owner, 'nobody@example.com', OrganizationRole::Admin,
     );
 
     expect(DB::table('notifications')->count())->toBe(0);
@@ -52,7 +52,7 @@
     $existing = User::factory()->create(['email' => 'both@example.com']);
 
     app(OrganizationMembershipService::class)->inviteMember(
-        $organization, $owner, 'both@example.com', AdminConsoleRole::Admin,
+        $organization, $owner, 'both@example.com', OrganizationRole::Admin,
     );
 
     // メール (on-demand route) は従来どおり
diff --git a/tests/Feature/Notifications/NotificationCenterTest.php b/tests/Feature/Notifications/NotificationCenterTest.php
index 5f40644..33f86f9 100644
--- a/tests/Feature/Notifications/NotificationCenterTest.php
+++ b/tests/Feature/Notifications/NotificationCenterTest.php
@@ -6,6 +6,7 @@
 use App\DataTransferObjects\Notification\ManualJobPayload;
 use App\DataTransferObjects\Notification\TicketBalanceLowPayload;
 use App\Models\Organization;
+use App\Models\OrganizationInvitation;
 use App\Models\Project;
 use App\Models\User;
 use App\Models\VideoManual;
@@ -258,17 +259,35 @@ function notifyManualAnalyzed(Organization $organization, User $user, Project $p
         ->assertRedirect('/purchase-tickets');
 });
 
-test('open: invitation_received → 一覧へ 303 + 招待案内 info', function (): void {
+test('open: invitation_received → 受諾可能な招待があるときは info を出さず一覧へ 303', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
     $owner->notify(new InvitationReceivedNotification(
         $organization->id, new InvitationReceivedPayload($organization->name),
     ));
     $id = $owner->notifications()->firstOrFail()->getKey();
+    // 一覧に「届いている招待」が出る状態 (受諾の解決と同一 scope で件数を算出する)
+    OrganizationInvitation::factory()->forOrganization($organization)->create(['email' => $owner->email]);
 
     $this->actingAs($owner)->post("/notifications/{$id}/open")
         ->assertStatus(303)
         ->assertRedirect('/notifications')
-        ->assertSessionHas('info', '招待はメールの受諾リンクから参加してください。');
+        ->assertSessionMissing('info');
+});
+
+test('open: invitation_received → 受諾可能な招待が無いときは説明 info を出す', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $owner->notify(new InvitationReceivedNotification(
+        $organization->id, new InvitationReceivedPayload($organization->name),
+    ));
+    $id = $owner->notifications()->firstOrFail()->getKey();
+    // 取り消し済み = 受諾できない (件数 0 に collapse する)
+    OrganizationInvitation::factory()->forOrganization($organization)->revoked()
+        ->create(['email' => $owner->email]);
+
+    $this->actingAs($owner)->post("/notifications/{$id}/open")
+        ->assertStatus(303)
+        ->assertRedirect('/notifications')
+        ->assertSessionHas('info', '現在有効な招待はありません (取り消し・期限切れ・参加済みの可能性があります)。');
 });
 
 test('open: 未知 type → 一覧へ 303 + 汎用 info (招待文言と混同しない)・既読化のみ', function (): void {
diff --git a/tests/Feature/Organization/InvitationAcceptRaceTest.php b/tests/Feature/Organization/InvitationAcceptRaceTest.php
new file mode 100644
index 0000000..9e45eec
--- /dev/null
+++ b/tests/Feature/Organization/InvitationAcceptRaceTest.php
@@ -0,0 +1,124 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Organization;
+use App\Models\OrganizationInvitation;
+use App\Models\User;
+use App\Services\Organization\OrganizationMembershipService;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Validation\ValidationException;
+
+/*
+ * joinOrganization() の戻り値 (false = ロック下再検証で受諾不能) の**消費契約**。
+ *
+ * ★目的は競合の完全再現ではなく、`joinOrganization() === false` を各呼び出し元が
+ *   正しく消費することを**決定的に**検証することである (スレッド競合は再現しない)。
+ *
+ * 決定的な作り方は **SQL の形で当てる**。取得回数では当てない —
+ * acceptPendingInvitation は「下見 → ロック下再解決 → joinOrganization 内のロック取得」で
+ * 回数が経路ごとに変わり、回数依存の細工は実装変更に脆いため。
+ */
+
+/**
+ * joinOrganization がロック下再検証のために発行する SELECT ... FOR UPDATE を検出し、
+ * その直前に「取り消しが割り込んだ」のと同じ状態を作る (one-shot)。
+ *
+ * **DB::beforeExecuting() の callback は解除できない** (Laravel に unregister API が無い)。
+ * したがって「後始末する」とは書かない。代わりに callback 自身が one-shot で恒久的に
+ * inert になる設計にしてある ($fired を立てた後は即 return するだけ)。
+ */
+function revokeOnLockedRead(int $invitationId): void
+{
+    $fired = false; // one-shot。自分の UPDATE による再入も止める
+    DB::beforeExecuting(function (string $query, array $bindings) use ($invitationId, &$fired): void {
+        if ($fired) {
+            return;
+        }
+        $lower = strtolower($query);
+        if (! str_contains($lower, 'organization_invitations') || ! str_contains($lower, 'for update')) {
+            return;
+        }
+        // id は必ず placeholder になるため **bindings 側で対象 id を確認する**
+        // (SQL 文字列だけでは対象 id を判定できない)。別の招待行のロック読取には干渉しない
+        $stringBindings = array_map(static fn (mixed $b): string => is_scalar($b) ? (string) $b : '', $bindings);
+        if (! in_array((string) $invitationId, $stringBindings, true)) {
+            return;
+        }
+        $fired = true;
+        // 同一接続・同一トランザクション内なので自分のロックと競合しない
+        DB::table('organization_invitations')->where('id', $invitationId)->update(['revoked_at' => now()]);
+    });
+}
+
+test('acceptInvitation はロック下再検証の敗北を事前検証と同一の中立メッセージへ畳む', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    [$invitation, $plainToken] = OrganizationInvitation::factory()->forOrganization($organization)
+        ->createWithPlainToken(['email' => 'invitee@example.com']);
+    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
+
+    revokeOnLockedRead($invitation->id);
+
+    $thrown = null;
+    try {
+        app(OrganizationMembershipService::class)->acceptInvitation($plainToken, $invitee);
+    } catch (ValidationException $exception) {
+        $thrown = $exception;
+    }
+
+    expect($thrown)->not->toBeNull();
+    expect($thrown?->errors()['token'][0] ?? null)->toBe('この招待は無効です。');
+    expect($organization->users()->whereKey($invitee->id)->exists())->toBeFalse();
+    expect($invitation->refresh()->isAccepted())->toBeFalse();
+});
+
+test('acceptInvitationIfValid はロック下再検証の敗北で null を返し現在組織を書き換えない', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    [$ownOrganization, $invitee] = createOrganizationWithOwner('自分の組織');
+    [$invitation, $plainToken] = OrganizationInvitation::factory()->forOrganization($organization)
+        ->createWithPlainToken(['email' => $invitee->email]);
+
+    revokeOnLockedRead($invitation->id);
+
+    $result = app(OrganizationMembershipService::class)->acceptInvitationIfValid($plainToken, $invitee);
+
+    expect($result)->toBeNull();
+    expect($invitee->fresh()?->current_organization_id)->toBe($ownOrganization->id);
+    expect($organization->users()->whereKey($invitee->id)->exists())->toBeFalse();
+});
+
+test('acceptPendingInvitation はロック下再検証の敗北で null を返す', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
+    $invitation = OrganizationInvitation::factory()->forOrganization($organization)
+        ->create(['email' => 'invitee@example.com']);
+
+    revokeOnLockedRead($invitation->id);
+
+    $result = app(OrganizationMembershipService::class)->acceptPendingInvitation($invitee, (string) $invitation->id);
+
+    expect($result)->toBeNull();
+    expect($organization->users()->whereKey($invitee->id)->exists())->toBeFalse();
+    expect($invitation->refresh()->isAccepted())->toBeFalse();
+});
+
+test('helper は one-shot で、同一テスト内の後続受諾に干渉しない (inert 化の behavioral proof)', function (): void {
+    [$firstOrganization] = createOrganizationWithOwner('組織 A');
+    [$secondOrganization] = createOrganizationWithOwner('組織 B');
+    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
+    $blocked = OrganizationInvitation::factory()->forOrganization($firstOrganization)
+        ->create(['email' => 'invitee@example.com']);
+    $normal = OrganizationInvitation::factory()->forOrganization($secondOrganization)
+        ->create(['email' => 'invitee@example.com']);
+
+    revokeOnLockedRead($blocked->id);
+
+    $membership = app(OrganizationMembershipService::class);
+    expect($membership->acceptPendingInvitation($invitee, (string) $blocked->id))->toBeNull();
+
+    // helper 発火後は inert。別の有効な招待は普通に受諾できる
+    $joined = $membership->acceptPendingInvitation($invitee, (string) $normal->id);
+
+    expect($joined)->toBeInstanceOf(Organization::class);
+    expect($secondOrganization->users()->whereKey($invitee->id)->exists())->toBeTrue();
+});
diff --git a/tests/Feature/Organization/InvitationTest.php b/tests/Feature/Organization/InvitationTest.php
index f1d5676..97aa81d 100644
--- a/tests/Feature/Organization/InvitationTest.php
+++ b/tests/Feature/Organization/InvitationTest.php
@@ -2,9 +2,7 @@
 
 declare(strict_types=1);
 
-use App\Enums\AdminConsoleRole;
 use App\Enums\OrganizationRole;
-use App\Enums\ProjectRole;
 use App\Models\Organization;
 use App\Models\OrganizationInvitation;
 use App\Models\Project;
@@ -20,13 +18,14 @@
  * 組織招待 (送信 / 受諾 / 拒否系)。
  * 招待送信は back + success flash で完結すること (画面遷移しない。
  * devnotes/20260611-template-extraction/14 §4)。
- * ロールは 3 値遷移コマンド (admin/editor/shooter = AdminConsoleRole)。
+ * 招待のロールは**組織ロール 2 値** (管理者 / メンバー)。役割付き招待 (project_role) は
+ * 裁定 AG-079 で撤去済みで、編集者 / 撮影者は参加後に管理画面のロール割当コマンドで付与する。
  */
 
 /**
  * service 経由で招待を送り、メールに載った平文 token を取り出す。
  */
-function inviteAndCaptureToken(Organization $organization, User $invitedBy, string $email, AdminConsoleRole $role): string
+function inviteAndCaptureToken(Organization $organization, User $invitedBy, string $email, OrganizationRole $role): string
 {
     Notification::fake();
     app(OrganizationMembershipService::class)->inviteMember($organization, $invitedBy, $email, $role);
@@ -54,7 +53,7 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
         ->from("/organizations/{$organization->slug}/settings")
         ->post("/organizations/{$organization->slug}/invitations", [
             'email' => 'invitee@example.com',
-            'role' => AdminConsoleRole::Admin->value,
+            'role' => OrganizationRole::Admin->value,
         ]);
 
     // back (= 元画面の組織設定) へ戻ること。intended は使わない
@@ -74,7 +73,7 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
 
 test('token 受諾でメンバーシップ + 招待ロールが付与される', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
-    $token = inviteAndCaptureToken($organization, $owner, 'invitee@example.com', AdminConsoleRole::Admin);
+    $token = inviteAndCaptureToken($organization, $owner, 'invitee@example.com', OrganizationRole::Admin);
 
     // 受諾するユーザーは別組織を現在組織に持つ (POST 受諾が現在組織を切り替えないことを固定するため)
     [$otherOrg, $invitee] = createOrganizationWithOwner('受諾者の既存組織');
@@ -96,7 +95,7 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
 
 test('受諾画面 (GET) は組織名と token を表示する', function (): void {
     [$organization, $owner] = createOrganizationWithOwner('受諾テスト組織');
-    $token = inviteAndCaptureToken($organization, $owner, 'invitee@example.com', AdminConsoleRole::Admin);
+    $token = inviteAndCaptureToken($organization, $owner, 'invitee@example.com', OrganizationRole::Admin);
 
     $invitee = User::factory()->create();
     $response = $this->actingAs($invitee)->get('/invitations/accept?token='.$token);
@@ -157,7 +156,7 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
         ->from("/organizations/{$organization->slug}/settings")
         ->post("/organizations/{$organization->slug}/invitations", [
             'email' => $member->email,
-            'role' => AdminConsoleRole::Admin->value,
+            'role' => OrganizationRole::Admin->value,
         ]);
 
     // 既存メンバーであることを開示しない中立メッセージ
@@ -167,11 +166,11 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
 
 test('有効な既存招待がある email への再招待も中立メッセージで拒否される', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
-    inviteAndCaptureToken($organization, $owner, 'pending@example.com', AdminConsoleRole::Admin);
+    inviteAndCaptureToken($organization, $owner, 'pending@example.com', OrganizationRole::Admin);
 
     $response = $this->actingAs($owner)->post("/organizations/{$organization->slug}/invitations", [
         'email' => 'pending@example.com',
-        'role' => AdminConsoleRole::Admin->value,
+        'role' => OrganizationRole::Admin->value,
     ]);
 
     $response->assertSessionHasErrors(['email' => 'このメールアドレスには招待を送信できません。']);
@@ -186,7 +185,7 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
 
     $response = $this->actingAs($member)->post("/organizations/{$organization->slug}/invitations", [
         'email' => 'someone@example.com',
-        'role' => AdminConsoleRole::Admin->value,
+        'role' => OrganizationRole::Admin->value,
     ]);
 
     $response->assertForbidden();
@@ -278,7 +277,7 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
 
 test('未ログインの有効招待リンクは token を session 保存し register へ誘導する', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
-    $token = inviteAndCaptureToken($organization, $owner, 'guest@example.com', AdminConsoleRole::Admin);
+    $token = inviteAndCaptureToken($organization, $owner, 'guest@example.com', OrganizationRole::Admin);
 
     $response = $this->get('/invitations/accept?token='.$token);
 
@@ -347,7 +346,7 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
 
 test('有効な招待リンクの受諾確認画面は route 既定タイトルのまま', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
-    $token = inviteAndCaptureToken($organization, $owner, 'valid-title@example.com', AdminConsoleRole::Admin);
+    $token = inviteAndCaptureToken($organization, $owner, 'valid-title@example.com', OrganizationRole::Admin);
     $invitee = User::factory()->create();
 
     $this->actingAs($invitee)
@@ -369,7 +368,7 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
 
 test('招待 email で register すると個人組織を作らず招待組織へ参加する', function (): void {
     [$organization, $owner] = createOrganizationWithOwner('招待組織');
-    $token = inviteAndCaptureToken($organization, $owner, 'newbie@example.com', AdminConsoleRole::Admin);
+    $token = inviteAndCaptureToken($organization, $owner, 'newbie@example.com', OrganizationRole::Admin);
 
     $response = $this->withSession(['invitation_token' => $token])->post('/register', [
         'name' => '新人 花子',
@@ -396,7 +395,7 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
 
 test('招待経由登録の直後、dashboard 自己修復を経ずに共有プロップ currentOrganization が招待先を指す', function (): void {
     [$organization, $owner] = createOrganizationWithOwner('招待組織');
-    $token = inviteAndCaptureToken($organization, $owner, 'header@example.com', AdminConsoleRole::Admin);
+    $token = inviteAndCaptureToken($organization, $owner, 'header@example.com', OrganizationRole::Admin);
 
     $this->withSession(['invitation_token' => $token])->post('/register', [
         'name' => 'ヘッダー 確認',
@@ -422,7 +421,7 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
     // 招待経由は個人組織を作らず所属組織の残高を共有する。ここで付与すると招待 N 人 = N×10 の
     // 増幅になるため、signup grant は「個人組織を作る新規登録」時のみに限定する (LP CTA も同じ意図)。
     [$organization, $owner] = createOrganizationWithOwner('招待組織');
-    $token = inviteAndCaptureToken($organization, $owner, 'nofree@example.com', AdminConsoleRole::Admin);
+    $token = inviteAndCaptureToken($organization, $owner, 'nofree@example.com', OrganizationRole::Admin);
 
     $this->withSession(['invitation_token' => $token])->post('/register', [
         'name' => '無償なし 花子',
@@ -446,7 +445,7 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
 
 test('招待 email と異なる email で register すると email エラーになる', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
-    $token = inviteAndCaptureToken($organization, $owner, 'invited@example.com', AdminConsoleRole::Admin);
+    $token = inviteAndCaptureToken($organization, $owner, 'invited@example.com', OrganizationRole::Admin);
 
     $response = $this->withSession(['invitation_token' => $token])
         ->from('/register')
@@ -491,35 +490,9 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
 });
 
 /*
- * 3 値ロールコマンド招待 (editor/shooter = project_role 付き) の送信・受諾。
+ * 役割付き招待の撤去 (裁定 AG-079)。招待は「組織に入れる」ことだけを意味する。
  */
 
-test('editor 招待の受諾で Default Project へ project_admin pivot が attach される', function (): void {
-    [$organization, $owner] = createOrganizationWithOwner();
-    $project = Project::factory()->forOrganization($organization)->create();
-    $token = inviteAndCaptureToken($organization, $owner, 'editor@example.com', AdminConsoleRole::Editor);
-
-    $invitee = User::factory()->create();
-    $this->actingAs($invitee)->post('/invitations/accept', ['token' => $token])
-        ->assertRedirect('/dashboard');
-
-    expect($invitee->organizationRole($organization))->toBe(OrganizationRole::Member);
-    expect($project->memberRole($invitee))->toBe(ProjectRole::Admin);
-});
-
-test('shooter 招待の受諾で Default Project へ project_member pivot が attach される', function (): void {
-    [$organization, $owner] = createOrganizationWithOwner();
-    $project = Project::factory()->forOrganization($organization)->create();
-    $token = inviteAndCaptureToken($organization, $owner, 'shooter@example.com', AdminConsoleRole::Shooter);
-
-    $invitee = User::factory()->create();
-    $this->actingAs($invitee)->post('/invitations/accept', ['token' => $token])
-        ->assertRedirect('/dashboard');
-
-    expect($invitee->organizationRole($organization))->toBe(OrganizationRole::Member);
-    expect($project->memberRole($invitee))->toBe(ProjectRole::Member);
-});
-
 test('招待送信の role が不正値ならカスタムメッセージ付き error bag になる (Enum ルールキー解決の回帰防止)', function (): void {
     Notification::fake();
     [$organization, $owner] = createOrganizationWithOwner();
@@ -537,71 +510,33 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
     expect(OrganizationInvitation::query()->count())->toBe(0);
 });
 
-test('editor/shooter 招待の送信は Default Project 不在なら error bag (role)', function (string $role): void {
-    Notification::fake();
-    [$organization, $owner] = createOrganizationWithOwner();
-
-    $response = $this->actingAs($owner)->post("/organizations/{$organization->slug}/invitations", [
-        'email' => 'someone@example.com',
-        'role' => $role,
-    ]);
-
-    $response->assertSessionHasErrors('role');
-    Notification::assertNothingSent();
-    expect(OrganizationInvitation::query()->count())->toBe(0);
-})->with(['editor', 'shooter']);
-
-test('受諾時に project が消えていた場合は org 参加のみ = 未割当に落ちる (例外にならない)', function (): void {
+test('招待の受諾は org 参加のみで Default Project の pivot を作らない', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
     $project = Project::factory()->forOrganization($organization)->create();
-    $token = inviteAndCaptureToken($organization, $owner, 'degrade@example.com', AdminConsoleRole::Shooter);
-
-    // 招待後に project を削除 (受諾時 race の逐次再現)
-    $project->delete();
-
-    $invitee = User::factory()->create();
-    $this->actingAs($invitee)->post('/invitations/accept', ['token' => $token])
-        ->assertRedirect('/dashboard');
-
-    // org 参加 + org ロールは付与される (未割当として可視化され、管理画面から再割当できる)
-    expect($organization->users()->whereKey($invitee->id)->exists())->toBeTrue();
-    expect($invitee->organizationRole($organization))->toBe(OrganizationRole::Member);
-    expect(OrganizationInvitation::query()->sole()->isAccepted())->toBeTrue();
-});
-
-test('旧招待互換: project_role = null の既存行の受諾は従来どおり org 参加のみ', function (): void {
-    [$organization] = createOrganizationWithOwner();
-    Project::factory()->forOrganization($organization)->create();
-    [, $token] = OrganizationInvitation::factory()
-        ->forOrganization($organization)
-        ->createWithPlainToken(['email' => 'legacy@example.com']);
+    $token = inviteAndCaptureToken($organization, $owner, 'member@example.com', OrganizationRole::Member);
 
     $invitee = User::factory()->create();
     $this->actingAs($invitee)->post('/invitations/accept', ['token' => $token])
         ->assertRedirect('/dashboard');
 
     expect($invitee->organizationRole($organization))->toBe(OrganizationRole::Member);
-    // pivot は付与されない (未割当)
-    expect($organization->projects()->sole()->members()->whereKey($invitee->id)->exists())->toBeFalse();
+    // 編集者 / 撮影者は参加後にロール割当コマンドで付与する (招待では付かない)
+    expect($project->memberRole($invitee))->toBeNull();
 });
 
-test('register 経路でも project_role 付き招待の受諾で pivot が attach される', function (): void {
+test('招待は Default Project が無くても送信できる (撤去で消えた前提検査の回帰封じ)', function (): void {
+    Notification::fake();
     [$organization, $owner] = createOrganizationWithOwner();
-    $project = Project::factory()->forOrganization($organization)->create();
-    $token = inviteAndCaptureToken($organization, $owner, 'register-shooter@example.com', AdminConsoleRole::Shooter);
 
-    $response = $this->withSession(['invitation_token' => $token])->post('/register', [
-        'name' => '撮影 次郎',
-        'email' => 'register-shooter@example.com',
-        'password' => 'SecurePass1234',
-        'terms_accepted' => '1',
+    $response = $this->actingAs($owner)->post("/organizations/{$organization->slug}/invitations", [
+        'email' => 'no-project@example.com',
+        'role' => OrganizationRole::Member->value,
     ]);
 
-    $response->assertRedirect(route('verification.notice'));
-
-    $user = User::whereBlind('email', 'email_index', 'register-shooter@example.com')->firstOrFail();
-    expect($user->organizationRole($organization))->toBe(OrganizationRole::Member);
-    expect($project->memberRole($user))->toBe(ProjectRole::Member);
+    $response->assertSessionHasNoErrors();
+    $response->assertSessionHas('success');
+    expect($organization->projects()->count())->toBe(0);
+    expect(OrganizationInvitation::query()->count())->toBe(1);
 });
 
 /*
@@ -610,7 +545,7 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
 
 test('受諾済み招待で joinOrganization 相当に到達しても no-op (ロック下再検証の契約)', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
-    $token = inviteAndCaptureToken($organization, $owner, 'idempotent@example.com', AdminConsoleRole::Admin);
+    $token = inviteAndCaptureToken($organization, $owner, 'idempotent@example.com', OrganizationRole::Admin);
 
     $first = User::factory()->create();
     $this->actingAs($first)->post('/invitations/accept', ['token' => $token]);
@@ -624,10 +559,10 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
     expect($organization->users()->whereKey($first->id)->exists())->toBeTrue();
 });
 
-test('既 attach 状態での受諾は unique 違反にならず role/pivot を変更しない (insertOrIgnore 契約)', function (): void {
+test('既 attach 状態での受諾は unique 違反にならず role を変更しない (insertOrIgnore 契約)', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
     $project = Project::factory()->forOrganization($organization)->create();
-    $token = inviteAndCaptureToken($organization, $owner, 'already@example.com', AdminConsoleRole::Editor);
+    $token = inviteAndCaptureToken($organization, $owner, 'already@example.com', OrganizationRole::Member);
 
     // 招待送信後に別経路で org へ参加済み (organization_user 行あり + Admin ロール)
     $invitee = User::factory()->create();
@@ -637,7 +572,7 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
     // Controller 経路は「既にメンバー」で弾かれるため、Service の joinOrganization 契約を直接検証する
     $invitation = OrganizationInvitation::query()->sole();
     $method = new ReflectionMethod(OrganizationMembershipService::class, 'joinOrganization');
-    $method->invoke(
+    $joined = $method->invoke(
         app(OrganizationMembershipService::class),
         $invitation,
         $organization,
@@ -645,6 +580,8 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
         OrganizationRole::Member,
     );
 
+    // ロック下再検証を通ったので true (既 join の冪等 no-op も「変換完了」に含む)
+    expect($joined)->toBeTrue();
     // 500 (unique 違反) にならず、既存 role は温存・pivot も付与されない
     expect($invitee->fresh()->organizationRole($organization))->toBe(OrganizationRole::Admin);
     expect($project->memberRole($invitee))->toBeNull();
diff --git a/tests/Feature/Organization/OrganizationBoundaryNotFoundTest.php b/tests/Feature/Organization/OrganizationBoundaryNotFoundTest.php
index 429bdf2..ed4053e 100644
--- a/tests/Feature/Organization/OrganizationBoundaryNotFoundTest.php
+++ b/tests/Feature/Organization/OrganizationBoundaryNotFoundTest.php
@@ -2,7 +2,6 @@
 
 declare(strict_types=1);
 
-use App\Enums\AdminConsoleRole;
 use App\Enums\OrganizationRole;
 use App\Models\User;
 
@@ -104,7 +103,7 @@
     $this->actingAs($member)
         ->post(route('organizations.invitations.store', $organization), [
             'email' => 'someone@example.com',
-            'role' => AdminConsoleRole::Admin->value,
+            'role' => OrganizationRole::Admin->value,
         ])
         ->assertForbidden();
 });
diff --git a/tests/Support/Routing/NestedRouteDefenseInventory.php b/tests/Support/Routing/NestedRouteDefenseInventory.php
index 1095be6..b65bc46 100644
--- a/tests/Support/Routing/NestedRouteDefenseInventory.php
+++ b/tests/Support/Routing/NestedRouteDefenseInventory.php
@@ -130,6 +130,10 @@ public static function inventory(): array
             'notifications.read' => ['notification' => $manual],
             // {passkey} は SelfScopedPasskeyBinder が認証ユーザーの passkeys() スコープで解決する
             'passkey.destroy' => ['passkey' => $binder],
+            // {invitation} は AcceptInvitationInAppController が認証ユーザー宛の有効 pending 集合
+            // (scopeActivePendingForEmail) から手動解決する。不在 id / 他人宛 / 期限切れ /
+            // 取消済 / 削除済み組織宛はすべて同一の 404 に畳まれる (存在オラクル封じ)
+            'invitations.accept-in-app' => ['invitation' => $manual],
 
             // --- テナント親子でない param (理由は nonTenantReasons に必須登録) ---
             'social.callback' => ['provider' => $nonRes],
diff --git a/tests/js/components/features/NotificationListItem.test.ts b/tests/js/components/features/NotificationListItem.test.ts
index 2e289f3..ed2d5df 100644
--- a/tests/js/components/features/NotificationListItem.test.ts
+++ b/tests/js/components/features/NotificationListItem.test.ts
@@ -152,7 +152,7 @@ describe("NotificationListItem", () => {
         expect(screen.getByText(/5 枚/)).toBeInTheDocument();
     });
 
-    it("invitation_received: 招待文言とメール案内", () => {
+    it("invitation_received: 招待文言とアプリ内受諾への案内", () => {
         render(NotificationListItem, {
             props: {
                 notification: manualAnalyzedItem({
@@ -163,7 +163,9 @@ describe("NotificationListItem", () => {
         });
 
         expect(screen.getByText("招待元組織 に招待されています")).toBeInTheDocument();
-        expect(screen.getByText("メールの受諾リンクから参加してください")).toBeInTheDocument();
+        expect(
+            screen.getByText("クリックすると、届いている招待から参加できます"),
+        ).toBeInTheDocument();
     });
 
     it("未読行には個別既読ボタンを表示する", () => {
diff --git a/tests/js/components/features/invitations/PendingInvitationList.test.ts b/tests/js/components/features/invitations/PendingInvitationList.test.ts
new file mode 100644
index 0000000..90cc89a
--- /dev/null
+++ b/tests/js/components/features/invitations/PendingInvitationList.test.ts
@@ -0,0 +1,73 @@
+import { beforeEach, describe, expect, it, vi } from "vitest";
+import { fireEvent, render, screen } from "@testing-library/svelte";
+import PendingInvitationList from "@/components/features/invitations/PendingInvitationList.svelte";
+import type { PendingInvitation } from "@/types/invitation";
+
+// 受諾は router.post をモックして検証する (サーバは 302 で dashboard へ着地させる)
+const { routerPostMock } = vi.hoisted(() => ({
+    routerPostMock: vi.fn(),
+}));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    router: {
+        post: routerPostMock,
+    },
+}));
+
+function invitation(overrides: Partial<PendingInvitation> = {}): PendingInvitation {
+    return {
+        id: 12,
+        organizationName: "テスト組織",
+        roleLabel: "メンバー",
+        expiresAt: "2026-09-30",
+        ...overrides,
+    };
+}
+
+describe("PendingInvitationList", () => {
+    beforeEach(() => {
+        routerPostMock.mockReset();
+    });
+
+    it("招待 0 件では何も描画しない", () => {
+        render(PendingInvitationList, { props: { invitations: [] } });
+
+        expect(screen.queryByTestId("pending-invitation-list")).toBeNull();
+    });
+
+    it("組織名・ロール・期限・参加ボタンを描画する", () => {
+        render(PendingInvitationList, { props: { invitations: [invitation()] } });
+
+        expect(screen.getByTestId("pending-invitation-list")).toBeInTheDocument();
+        expect(screen.getByText("テスト組織")).toBeInTheDocument();
+        expect(screen.getByText("メンバー")).toBeInTheDocument();
+        expect(screen.getByText("期限 2026-09-30")).toBeInTheDocument();
+        expect(screen.getByTestId("accept-invitation-12")).toHaveTextContent("参加する");
+    });
+
+    it("初期描画では参加ボタンが disabled 属性を持たない (禁止事項 8)", () => {
+        render(PendingInvitationList, { props: { invitations: [invitation()] } });
+
+        expect(screen.getByTestId("accept-invitation-12")).not.toHaveAttribute("disabled");
+    });
+
+    it("参加ボタン押下で POST /invitations/{id}/accept-in-app を送る", async () => {
+        render(PendingInvitationList, { props: { invitations: [invitation()] } });
+
+        await fireEvent.click(screen.getByTestId("accept-invitation-12"));
+
+        expect(routerPostMock).toHaveBeenCalledTimes(1);
+        expect(routerPostMock.mock.calls[0]?.[0]).toBe("/invitations/12/accept-in-app");
+    });
+
+    it("in-flight 中の 2 回目の押下は送信しない (二重送信ガード)", async () => {
+        render(PendingInvitationList, { props: { invitations: [invitation()] } });
+
+        const button = screen.getByTestId("accept-invitation-12");
+        await fireEvent.click(button);
+        await fireEvent.click(button);
+
+        expect(routerPostMock).toHaveBeenCalledTimes(1);
+    });
+});
diff --git a/tests/js/components/molecules/PendingInvitationsNotice.test.ts b/tests/js/components/molecules/PendingInvitationsNotice.test.ts
new file mode 100644
index 0000000..70dd969
--- /dev/null
+++ b/tests/js/components/molecules/PendingInvitationsNotice.test.ts
@@ -0,0 +1,28 @@
+import { describe, expect, it } from "vitest";
+import { render, screen } from "@testing-library/svelte";
+import PendingInvitationsNotice from "@/components/molecules/PendingInvitationsNotice.svelte";
+
+describe("PendingInvitationsNotice", () => {
+    it("pendingCount=0 では描画しない", () => {
+        render(PendingInvitationsNotice, { props: { pendingCount: 0 } });
+
+        expect(screen.queryByTestId("pending-invitations-notice")).toBeNull();
+    });
+
+    it("pendingCount=3 で件数と /notifications への link を描画する", () => {
+        render(PendingInvitationsNotice, { props: { pendingCount: 3 } });
+
+        const link = screen.getByTestId("pending-invitations-notice");
+        expect(link.tagName).toBe("A");
+        expect(new URL(link.getAttribute("href") ?? "", "http://localhost").pathname).toBe(
+            "/notifications",
+        );
+        expect(link).toHaveTextContent("あなた宛の招待が 3 件あります");
+    });
+
+    it("disabled 属性を持たない (必須未充足 disabled UI 禁止)", () => {
+        render(PendingInvitationsNotice, { props: { pendingCount: 1 } });
+
+        expect(screen.getByTestId("pending-invitations-notice")).not.toHaveAttribute("disabled");
+    });
+});
diff --git a/tests/js/pages/AdminUsers.test.ts b/tests/js/pages/AdminUsers.test.ts
index f8ba86a..3ce1442 100644
--- a/tests/js/pages/AdminUsers.test.ts
+++ b/tests/js/pages/AdminUsers.test.ts
@@ -92,8 +92,9 @@ const invitationsFixture: InvitationRow[] = [
     {
         id: 10,
         email: "invited@example.com",
-        roleState: "shooter",
-        roleLabel: "撮影者",
+        // 招待は org ロールのみ (役割付き招待は AG-079 で撤去)
+        role: "organization_member",
+        roleLabel: "メンバー",
         expiresAt: "2026-07-18",
     },
 ];
diff --git a/tests/js/pages/NotificationsIndex.test.ts b/tests/js/pages/NotificationsIndex.test.ts
index 56a9e6b..0fadda7 100644
--- a/tests/js/pages/NotificationsIndex.test.ts
+++ b/tests/js/pages/NotificationsIndex.test.ts
@@ -1,14 +1,16 @@
 import { afterEach, describe, expect, it, vi } from "vitest";
 import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
 import NotificationsIndex from "@/pages/Notifications/Index.svelte";
+import type { PendingInvitation } from "@/types/invitation";
 import type { NotificationItem } from "@/types/notification";
 import type { PaginationMeta } from "@/types/manual";
 
-/** Index.svelte の Props (unreadCount 必須)。全 render はこの型で統一する */
+/** Index.svelte の Props (unreadCount / pendingInvitations 必須)。全 render はこの型で統一する */
 interface IndexProps {
     notifications: NotificationItem[];
     meta: PaginationMeta;
     unreadCount: number;
+    pendingInvitations: PendingInvitation[];
 }
 
 // router をモックし page state は実物を使う (props 未設定の空オブジェクト)
@@ -23,9 +25,9 @@ vi.mock("@inertiajs/svelte", async (importOriginal) => ({
 
 const meta: PaginationMeta = { current_page: 1, last_page: 1, per_page: 20, total: 0 };
 
-/** 全 render の共通 props。unreadCount 必須化に伴う追従漏れを防ぐ (デフォルト 0) */
+/** 全 render の共通 props。必須 prop 追加時の追従漏れを防ぐ (デフォルト空) */
 function baseProps(overrides: Partial<IndexProps> = {}): IndexProps {
-    return { notifications: [], meta, unreadCount: 0, ...overrides };
+    return { notifications: [], meta, unreadCount: 0, pendingInvitations: [], ...overrides };
 }
 
 function item(id: string): NotificationItem {
@@ -97,3 +99,31 @@ describe("Notifications/Index", () => {
         expect(screen.getAllByTestId("notification-item")).toHaveLength(2);
     });
 });
+
+describe("Notifications/Index 届いている招待", () => {
+    afterEach(() => cleanup());
+
+    it("pendingInvitations が空なら招待セクションを描画しない", () => {
+        render(NotificationsIndex, { props: baseProps() });
+
+        expect(screen.queryByTestId("pending-invitation-list")).toBeNull();
+    });
+
+    it("pendingInvitations があれば招待セクションを描画する", () => {
+        render(NotificationsIndex, {
+            props: baseProps({
+                pendingInvitations: [
+                    {
+                        id: 7,
+                        organizationName: "招待元組織",
+                        roleLabel: "メンバー",
+                        expiresAt: "2026-09-30",
+                    },
+                ],
+            }),
+        });
+
+        expect(screen.getByTestId("pending-invitation-list")).toBeInTheDocument();
+        expect(screen.getByTestId("accept-invitation-7")).toBeInTheDocument();
+    });
+});
````

### gate mutation ログ (施策 8 の deny-by-default gate が空振りしないことの証拠)

````markdown
# T134 gate mutation ログ (InvitationResolutionInventoryTest + 戻り値消費トークナイザ検査)

対象: `tests/Architecture/InvitationResolutionInventoryTest.php` (施策 8) /
`tests/Architecture/MembershipWriteLockInventoryTest.php` の
「joinOrganization() の戻り値を破棄している呼び出しが無い」検査 (施策 3)。

**素の実装では常に green の gate であるため、mutation を当てて赤化を確認した**。
入れた mutation はすべて元に戻し、`git diff` で残っていないことを確認済み。

---

## (a) 初回の抽出結果 (クエリ起点の全リスト。パス#メソッド名)

セレクタ (`invitationResolutionSelectors()` / モデルファイル限定の
`invitationResolutionModelSelectors()`) で抽出した **12 件**。

| # | 目録キー | 分類 |
|---|---|---|
| 1 | `Http/Controllers/Admin/UserManagementController.php#index` | OrganizationScoped |
| 2 | `Http/Controllers/Organizations/InvitationAcceptanceController.php#show` | TokenHashLookup |
| 3 | `Models/OrganizationInvitation.php#findActiveByPlainToken` | ModelInternal |
| 4 | `Models/OrganizationInvitation.php#scopeActive` | ModelInternal |
| 5 | `Models/OrganizationInvitation.php#scopeActivePendingForEmail` | ModelInternal |
| 6 | `Rules/MatchesInvitationEmail.php#validate` | TokenHashLookup |
| 7 | `Services/Organization/OrganizationMembershipService.php#acceptInvitation` | TokenHashLookup |
| 8 | `Services/Organization/OrganizationMembershipService.php#acceptInvitationIfValid` | TokenHashLookup |
| 9 | `Services/Organization/OrganizationMembershipService.php#hasPendingInvitation` | OrganizationScoped |
| 10 | `Services/Organization/OrganizationMembershipService.php#joinOrganization` | LockedRowReload |
| 11 | `Services/Organization/OrganizationMembershipService.php#pendingInvitationsQuery` | **RecipientScopedPendingSet** |
| 12 | `Services/Organization/OrganizationMembershipService.php#resolveRegisterPrefillEmail` | TokenHashLookup |

> **実装前 (施策 1・3 の本体が無い状態) の実測は 10 件**で、その時点では
> floor (12) 下回り + stale 2 件で **fail** することを確認している (テストファースト)。

## (b) `invitationResolutionSiteFloor()` の確定値

**12** (実測ちょうど)。

## (c) `RecipientScopedPendingSet` の exact-fit cap

**1** (`pendingInvitationsQuery` のみ)。

> **設計との差分**: 設計の enum は 4 case だったが、抽出結果に
> `joinOrganization` の「既に解決済みの招待を主キーでロック下再取得する」経路が現れ、
> 4 分類のどれにも意味的に収まらなかったため 5 番目の case
> `LockedRowReload` を追加した (詳細は実装報告の deviations)。

---

## (d) mutation M1〜M7 の赤化結果

いずれも `composer test -- tests/Architecture/InvitationResolutionInventoryTest.php` で確認。

| # | mutation | 結果 | 赤化したテスト |
|---|---|---|---|
| M1 | `AcceptInvitationInAppController::__invoke` に `OrganizationInvitation::query()->whereKey($invitation)->first();` を一時的に足す | **FAILED (期待どおり)** | 「クエリ起点はすべて目録へ分類登録されている (未登録は fail)」 |
| M2 | `pendingInvitationsQuery()` の本文を `activePendingForEmail(...)` から `->active()->whereBlind(...)` の手書きへ置換 | **FAILED (期待どおり)** | 「受信者視点に分類した箇所は scopeActivePendingForEmail を再利用している」 |
| M3 | 目録から `pendingInvitationsQuery` の行を削除 | **FAILED (期待どおり)** | 未登録 fail + exact-fit cap + 各 case の実体 (3 本) |
| M4 | 目録に実在しないキー (`Services/Foo.php#bar`) を足す | **FAILED (期待どおり)** | 「目録に実在しないクエリ起点が残っていない (stale 検出)」 |
| M5 | 理由文を `'短い'` に置換 | **FAILED (期待どおり)** | 「目録の理由は 30 文字以上で分類は enum である」 |
| M6 | `invitationResolutionSiteFloor()` を実測 +1 (13) に上げる | **FAILED (期待どおり)** | 「抽出件数が floor を下回らない (セレクタ空振りの検出)」 |
| M7 | `RecipientScopedPendingSet` 分類の 2 件目を目録に足す (実在サイト `joinOrganization` を再分類) | **FAILED (期待どおり)** | exact-fit cap + 受信者視点の本文検査 + 各 case の実体 |

## (e) 戻り値消費トークナイザ検査の負のコントロール

`acceptPendingInvitation` 内の呼び出しを
`$this->joinOrganization(...);` (戻り値破棄) へ一時的に書き換えて実行:

```
composer test -- tests/Architecture/MembershipWriteLockInventoryTest.php
→ FAILED: joinOrganization() の戻り値 (false = ロック下再検証で受諾不能) を
   破棄している呼び出しがあります。line 368
```

期待どおり赤化した。書き換えは元に戻し済み。

---

## 後始末の確認

- mutation 適用対象ファイル (`AcceptInvitationInAppController.php` /
  `OrganizationMembershipService.php` / `InvitationResolutionInventoryTest.php`) は
  ドライバが必ず元の内容へ復元する構造 (try/finally) にしてある
- 実行後に `git diff` / `git status` で mutation の残留が無いことを確認した
````

### テスト結果 (全 green)

```
composer phpstan  → No errors (815 files)
composer fix / vendor/bin/pint --test → passed
composer test     → passed: tests=3806 passed=3804 skipped=2 assertions=15336
composer test:browser → chromium passed=11 skipped=3 / webkit passed=11 skipped=3
pnpm lint         → passed
pnpm typecheck    → passed
pnpm test         → 128 files / 1246 tests passed
pnpm build        → built
pnpm typecheck:packages / build:packages → passed
pnpm test:packages → 10 files / 106 tests passed
```

### 実装者からの補足 (設計からの意図的な逸脱)

1. **`InvitationResolutionScope` に 5 番目の case `LockedRowReload` を追加した**。設計の 4 分類では
   `joinOrganization()` の「既に解決済みの招待を主キー + `lockForUpdate()` で読み直す」経路が
   どれにも意味的に収まらなかったため。存在秘匿の視点を持たない経路であることを docblock に明記している。
2. **共有 prop のキーを `invitations` ではなく `invitationInbox` にした**。`Admin/Users` のページ prop
   `invitations` (管理者視点の招待一覧) と衝突し、その画面だけ共有 prop が配列で上書きされて
   横断の気づきが黙って消えるため (既存の `notifications.unreadCount` と同じ衝突クラス)。
3. **named limiter `invitation-accept-in-app` のキー組み立てを `RateLimiterKeys::actorOrIp()` に寄せた**。
   設計時点では未認証を `'guest'` リテラルへ落とす案だったが、中断中に main へマージされた T125 が
   actor/IP レーンのキー組み立ての単一入口として同 helper を導入したため、その規約に合わせた
   (guest は `:ip:` 分岐になり、guest 同士が同一 bucket を共有しなくなる = 厳密に改善)。
   登録先も `configureAuthSurfaceRateLimiters()` (未認証 IP レーン) ではなく
   `configureActorScopedRateLimiters()` (認証済み actor レーン) にした。
4. `docs/factories.md` は `editorInvitation()` / `shooterInvitation()` を元々記載していなかったため
   更新差分が無い (施策 9 の当該項目は no-op)。

以上を踏まえてレビューし、最後に全体判定 (APPROVED / CHANGES_REQUESTED) を明記せよ。
