# 詳細設計レビュー Round 2（Round 1 指摘への対応）

Round 1 の Critical / Warning に対応しました。**全体判定の再評価**をお願いします。

## [Critical] 6-3 不変条件テストが `grantMonthly` を直接呼ぶ（実装詳細依存）
→ **対応**。6-3 を実装詳細非依存へ書き換え:
- 1 回目 = 公開ユースケース `grantSignupGrant($org)`（org スコープキー `signup_grant:org:{id}` を挿入）。
- 2 回目 = 旧キー形式を `DB::table('ticket_ledger_entries')->insertOrIgnore([... 'idempotency_key' =>
  'signup_grant:sub_legacy' ...])` で直接投入し、**別キーが部分 UNIQUE index に弾かれる**ことを検証。
- 検証: `signup_grant:%` 行が 1 件 / 残高が `config('billing.signup_grant_tickets')`。
- `grantMonthly` の可視性に依存しない（DB 制約を直接検証）。

## [Warning] 施策3 の呼び出し表記が二重（誤写リスク）
→ **対応**。擬似コード（`$this->grantSignupGrant`）を削除し、最終形
`$this->tickets->grantSignupGrant($organization)` の**一意な形のみ**を残した。

## [Warning] 施策3 付与失敗時の可観測性
→ **対応**。grant を try/catch で包まない（fail-loud）。付与失敗（config 誤設定 = InvalidArgumentException 等）は
登録 tx をロールバックしつつ既存の `UniqueConstraintViolationException` 捕捉に該当せず**そのまま上位へ伝播**し、
Laravel 標準例外ハンドラ（`report`）が記録する（握りつぶさない）旨を明記。

## [Warning] Architecture テストの indexdef 部分一致が弱い
→ **対応**。`indexname` 完全一致に加え、`indexdef` に `UNIQUE` / `ticket_ledger_entries` /
`organization_id` / `WHERE`（部分 index 述語の存在）/ `signup_grant` を**全て要求**する形へ強化。

## [Warning] Registration テストの「残高10」固定値
→ **対応**。期待値を `config('billing.signup_grant_tickets')` とし、**固定値 10 直書き禁止**を明記。

## [Suggestion] テスト名に「増幅防止」
→ **対応**。招待非付与テスト名に「増幅防止（招待 N 人 = N×10 を作らない）」の意図を反映。

---

## 更新後の詳細設計書（全文）

# 詳細設計: registration-ticket-grant

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を
生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする（「思考ゼロ・編集ゼロ」）。本改善は、LP が約束する
「新規登録で無償チケット 10 枚」を実際に付与することで、課金前に AI 解析〜動画完成を試せる
「まず触れる」導線を回復し、使命の入口を機能させる。

### 禁止事項（AGENTS.md 正本より）

1. テストなしの実装完了報告（不変条件は Architecture/Feature テスト登録まで含めて「実装済み」）
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作（`migrate:fresh` 等）のエージェント判断実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う）
5. LLM 呼び出しの Prism 直呼び / 6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

**セキュリティ不変条件 §7（課金の冪等性）**: チケット付与は `idempotency_key` UNIQUE の冪等 insert。
本設計はこれを DB 部分 UNIQUE index で「1 組織 1 signup grant」まで強化する。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）/ **Pest**（`composer test`）
- **RefreshDatabase** + `--parallel`（`tests/Pest.php` グローバル適用、個別 `DatabaseTransactions` 禁止）
- テストデータは **Factory** 生成 / **DTO + JsonResource** / アーリーリターン
- `composer fix`（Pint）/ `pnpm lint:fix` / PHP 8.4 + Laravel 12 + Svelte 5 + Inertia + TS
- テスト DB は **pgsql**（`.env.testing` `DB_CONNECTION=pgsql` / `phpunit.xml`）

## 概念設計リファレンス

- `devnotes/20260713-1637-registration-ticket-grant/conceptual-design.md`（APPROVED / conceptual Round 4）
- レビュー履歴: `conceptual-review-round-1..4.md`, `codex-history/conceptual-review-decisions-round-1..3.md`

### 修正対象（bug-hunt F-H1 / High）

LP（`Welcome.svelte` / `Pricing.svelte`）が「新規登録でチケット 10 枚無料」と明記するが、初回
signup grant を付与する経路が **Stripe `invoice.paid`（`subscription_create`）1 箇所のみ**で、Free 登録者は
残高 0 のまま。**登録完了時に個人組織へ 10 枚を冪等付与**し、二重付与を DB 制約で原子的に防ぐ。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 部分 UNIQUE index マイグレーション（重複監査 fail-closed 付き） | `database/migrations/{ts}_add_signup_grant_unique_index_to_ticket_ledger_entries.php`（新規） | High |
| 2 | `grantSignupGrant` を org スコープ冪等へ改修 | `app/Services/Billing/TicketLedgerService.php` | High |
| 3 | 登録完了時に signup grant を付与 | `app/Actions/Fortify/CreateNewUser.php` | High |
| 4 | Stripe webhook 呼び出しを新シグネチャへ更新（dead code 除去） | `app/Services/Billing/StripeWebhookProcessor.php` | High |
| 5 | LP 文言の挙動整合（「新規契約」→「新規登録」） | `resources/js/pages/Pricing.svelte` | Medium |
| 6 | テスト（Feature / Architecture / 既存更新 / JS） | 下記テスト計画参照 | High |

---

## 施策 1: 部分 UNIQUE index マイグレーション（重複監査 fail-closed 付き）

### 変更箇所
- 新規: `database/migrations/{timestamp}_add_signup_grant_unique_index_to_ticket_ledger_entries.php`

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 施策 6 の Architecture テスト・不変条件テストで検証

### 設計内容
`ticket_ledger_entries` に「1 組織あたり `signup_grant:%` 行は高々 1」を強制する部分 UNIQUE index を
追加する。作成前に**非破壊の重複監査**を行い、既存重複があれば `RuntimeException` で fail-closed 停止
（台帳行の削除・書換えはしない = append-only 厳守。重複補正は別承認手順へ分離）。pgsql / sqlite の
partial index（LIKE 述語）を使用（テスト・本番は pgsql）。

### 変更後コード
```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ticket_ledger_entries に「1 組織 1 signup grant」を強制する部分 UNIQUE index を追加する。
 *
 * - 述語 `idempotency_key LIKE 'signup_grant:%'` は旧キー (signup_grant:{subId}) と
 *   新 org スコープキー (signup_grant:org:{id}) の双方をカバーし、ローリングデプロイ中の
 *   別キー同時 insert でも二重付与を DB レベルで原子的に防ぐ。
 * - 作成前に既存重複を非破壊監査し、重複があれば fail-closed で停止する
 *   (台帳は append-only。重複補正は別途承認された手順へ分離し、本 migration では触れない)。
 */
return new class extends Migration
{
    private const string INDEX_NAME = 'ticket_ledger_entries_signup_grant_unique';

    public function up(): void
    {
        // 非破壊監査: 同一 organization_id に signup_grant:% 行が 2 件以上あると UNIQUE index は作れない
        $duplicates = DB::table('ticket_ledger_entries')
            ->where('idempotency_key', 'like', 'signup_grant:%')
            ->groupBy('organization_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('organization_id');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                'signup_grant 重複あり (organization_id: '.$duplicates->implode(', ').
                ')。台帳は append-only のため本 migration は補正しない。別途承認された補正手順で解消後に再実行すること。',
            );
        }

        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException("部分 UNIQUE index 未対応の driver: {$driver} (pgsql/sqlite のみ対応)");
        }

        // pgsql / sqlite はいずれも partial index (WHERE 述語) を支持する
        DB::statement(
            'CREATE UNIQUE INDEX '.self::INDEX_NAME.
            " ON ticket_ledger_entries (organization_id) WHERE idempotency_key LIKE 'signup_grant:%'",
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEX_NAME);
    }
};
```

### PHPStan 適合チェック
- [x] 戻り値の型が明示（`up(): void` / `down(): void`）
- [x] `pluck` の結果は `Illuminate\Support\Collection`。`implode` は string を返す
- [x] DTO 返却なし（マイグレーション）

### テスト計画
- [x] 施策 6 の Architecture テストで index の存在・述語・対象列を検証
- [x] 施策 6 の不変条件 Feature テストで実競合抑止を検証

### リスク
- 既存重複がある環境では up() が停止する（意図通りの fail-closed）。早期アプリでは signup grant 行は
  ほぼ存在せず重複も想定薄。停止時は別承認の補正手順で解消してから再実行する。
- index 作成はテーブルロックを取るが対象は小規模につき許容（大規模化時は
  `CREATE INDEX CONCURRENTLY` を別 migration で検討。CONCURRENTLY は tx 外実行が必要）。

---

## 施策 2: `grantSignupGrant` を org スコープ冪等へ改修

### 変更箇所
- `app/Services/Billing/TicketLedgerService.php` L86-105（`grantSignupGrant`）

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/Feature/Billing/TicketGrantTest.php`（施策 6）/
  `tests/Feature/Billing/WebhookIdempotencyTest.php`（施策 6）
- 呼び出し元: `StripeWebhookProcessor`（施策 4）

### 現行コード
```php
public function grantSignupGrant(Organization $organization, string $idempotencyKey): void
{
    Assert::stringNotEmpty($idempotencyKey);

    $count = config('billing.signup_grant_tickets');
    Assert::integer($count, 'config billing.signup_grant_tickets は整数で設定してください');
    Assert::greaterThan($count, 0, 'signup_grant_tickets は 1 以上で設定してください');

    $expiryDays = config('billing.signup_grant_expiry_days');
    Assert::integer($expiryDays, 'config billing.signup_grant_expiry_days は整数で設定してください');
    Assert::greaterThan($expiryDays, 0, 'signup_grant_expiry_days は 1 以上で設定してください');

    $this->grantMonthly(
        $organization,
        $count,
        CarbonImmutable::now()->addDays($expiryDays),
        $idempotencyKey,
        '初回 signup grant',
    );
}
```

### 変更後コード
```php
/**
 * 初回 signup grant (「まず触れる」導線の無償チケット)。
 *
 * 通常登録の完了時 (個人組織生成直後) と、Stripe サブスク作成の支払い確定時
 * (invoice.paid, billing_reason=subscription_create) の双方から呼ばれる。
 * 枚数は config('billing.signup_grant_tickets')、期限は now + config('billing.signup_grant_expiry_days') 日。
 *
 * **1 組織につき高々 1 回**の不変条件は、冪等キー `signup_grant:org:{orgId}` の UNIQUE と、
 * ticket_ledger_entries の部分 UNIQUE index (organization_id WHERE idempotency_key LIKE 'signup_grant:%')
 * が DB レベルで原子的に保証する。旧キー (signup_grant:{subId}) 行が既にある組織でも、部分 index が
 * 同一述語でカバーするため insertOrIgnore が二重付与を弾く (アプリ層の存在チェックは不要)。
 */
public function grantSignupGrant(Organization $organization): void
{
    $count = config('billing.signup_grant_tickets');
    Assert::integer($count, 'config billing.signup_grant_tickets は整数で設定してください');
    Assert::greaterThan($count, 0, 'signup_grant_tickets は 1 以上で設定してください');

    $expiryDays = config('billing.signup_grant_expiry_days');
    Assert::integer($expiryDays, 'config billing.signup_grant_expiry_days は整数で設定してください');
    Assert::greaterThan($expiryDays, 0, 'signup_grant_expiry_days は 1 以上で設定してください');

    $organizationId = (int) $organization->getKey();

    $this->grantMonthly(
        $organization,
        $count,
        CarbonImmutable::now()->addDays($expiryDays),
        "signup_grant:org:{$organizationId}",
        '初回 signup grant',
    );
}
```

### 補足（source を Monthly のまま維持する理由）
`grantMonthly` 経由のため source は `TicketSource::Monthly` のまま（signup grant は「サブスク由来の
期限付き付与」の下位実装という既存意味論を踏襲。既存テストの `source === Monthly` 期待も不変）。
部分 index の述語は `idempotency_key`（`source` ではない）を軸にするため source 変更は不要。

### PHPStan 適合チェック
- [x] `grantSignupGrant(Organization $organization): void` 明示
- [x] `config(...)` の mixed は `Assert::integer` で int へ絞り込み（既存踏襲）
- [x] `(int) $organization->getKey()` で mixed → int を明示し、文字列補間の mixed 混入を回避
- [x] DTO 返却なし（void の内部付与）

### テスト計画
- [x] `TicketGrantTest`「grantSignupGrant は config の枚数・期限で冪等付与する」を新シグネチャ・
  `signup_grant:org:{id}` キー期待へ更新（施策 6）
- [x] `TicketGrantTest`「config が不正 (0 以下) なら停止する」を新シグネチャへ更新（施策 6）

### リスク
- 呼び出し元 2 箇所（施策 3・4）を同 PR で更新しないとコンパイル/型エラー。施策一括で対応する。

---

## 施策 3: 登録完了時に signup grant を付与

### 変更箇所
- `app/Actions/Fortify/CreateNewUser.php`（コンストラクタ DI + `create()` の個人組織生成分岐）

### 波及変更
- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: `tests/Feature/Auth/RegistrationTest.php`（施策 6 で残高 10 検証を追加）

### 現行コード
```php
public function __construct(
    private readonly OrganizationProvisioningService $provisioning,
    private readonly OrganizationMembershipService $membership,
) {}
// ...
$joined = $invitationToken !== null
    ? $this->membership->acceptInvitationIfValid($invitationToken, $user)
    : null;

if ($joined === null) {
    // 個人用組織を同一 transaction 内で原子的に生成する
    $this->provisioning->provisionPersonalOrganization($user);
}

return $user;
```

### 変更後コード
`use App\Services\Billing\TicketLedgerService;` を import し、コンストラクタで DI する。
```php
public function __construct(
    private readonly OrganizationProvisioningService $provisioning,
    private readonly OrganizationMembershipService $membership,
    private readonly TicketLedgerService $tickets,
) {}
```
`create()` 内・登録 `DB::transaction` 内の個人組織生成分岐（唯一の変更箇所。呼び出しは
`$this->tickets->grantSignupGrant($organization)` の**一意な形のみ**）:
```php
$joined = $invitationToken !== null
    ? $this->membership->acceptInvitationIfValid($invitationToken, $user)
    : null;

if ($joined === null) {
    // 個人用組織を同一 transaction 内で原子的に生成する
    // (user だけ存在し組織なしの中間状態を作らない)
    $organization = $this->provisioning->provisionPersonalOrganization($user);

    // 初回 signup grant (無償 10 枚 / 30 日)。LP が約束する「新規登録で 10 枚」を実現する。
    // grantSignupGrant は純粋な ledger insert (通知・イベント・外部 I/O なし) のため登録 tx 内で完結し、
    // 冪等性は idempotency_key + 部分 UNIQUE index が DB レベルで保証する。
    // 招待経由 (join) は個人組織を作らず所属組織の残高を共有するため、ここでは付与しない
    // (招待 N 人 = N×10 の増幅を避ける)。
    $this->tickets->grantSignupGrant($organization);
}

return $user;
```

### 付与失敗時の可観測性（fail-loud を維持）
`grantSignupGrant` を try/catch で包まない。付与失敗（config 誤設定 = `InvalidArgumentException` 等）は
登録 `DB::transaction` をロールバックさせつつ、既存の `UniqueConstraintViolationException` 捕捉には
該当しないため**そのまま上位へ伝播**し、Laravel 標準の例外ハンドラ（`report`）が記録する（握りつぶさない）。
誤設定はデプロイエラーとして早期に顕在化させるのが望ましい（既定 10/30 は妥当で通常経路では発生しない）。

### PHPStan 適合チェック
- [x] `provisionPersonalOrganization(): Organization` を受け（既存戻り型）、`grantSignupGrant(Organization)` へ渡す
- [x] コンストラクタ DI の型は `TicketLedgerService`（コンテナ解決可能な具象サービス）
- [x] 新たな null 分岐なし（`$joined === null` 内でのみ実行）

### テスト計画
- [x] `RegistrationTest`「登録できる」に「残高が `config('billing.signup_grant_tickets')` になる」検証を追加
  （**固定値 10 直書き禁止**。config 由来値を期待に使う）
- [x] 新規テスト「招待経由登録では個人組織を作らず signup grant を付与しない（**増幅防止**: 招待 N 人 =
  N×10 を作らない）」
- [x] 個別 `DatabaseTransactions` は使わない（`RefreshDatabase` グローバル）

### リスク
- 登録 tx 内で付与するため、config 誤設定（0 以下）時は登録自体が失敗する。既定 10/30 は妥当で、
  誤設定はデプロイエラーとして早期に顕在化するのが望ましい（fail-loud）。
- 招待経由フローは付与されない（設計意図。制約・前提の招待エッジ参照）。

---

## 施策 4: Stripe webhook 呼び出しを新シグネチャへ更新（dead code 除去）

### 変更箇所
- `app/Services/Billing/StripeWebhookProcessor.php` L266-274（signup grant 呼び出し）/
  L483-493（`resolveInvoiceSubscriptionId` — signup grant 専用のため除去）

### 波及変更
- テストファイル: `tests/Feature/Billing/WebhookIdempotencyTest.php`（施策 6 で 2 テスト更新）

### 現行コード
```php
// 初回 signup grant (「まず触れる」導線)。subscription id が取れない場合は
// 安定した冪等キーを作れないため fail-closed で付与しない (report で可観測化)
if ($billingReason === 'subscription_create') {
    $subscriptionId = $this->resolveInvoiceSubscriptionId($payload);
    if ($subscriptionId !== null) {
        $this->tickets->grantSignupGrant($organization, "signup_grant:{$subscriptionId}");
    } else {
        report(new RuntimeException('invoice.paid subscription_create: subscription id 不明で signup grant skip'));
    }
}
```

### 変更後コード
```php
// 初回 signup grant (「まず触れる」導線)。冪等キーは org スコープ (grantSignupGrant 内部で生成) のため
// subscription id は不要。1 組織 1 回の不変条件は idempotency_key + 部分 UNIQUE index が保証する。
// (通常は登録時に付与済のため、ここは非個人組織のサブスク等に対する no-op ないし 1 回付与の安全網)
if ($billingReason === 'subscription_create') {
    $this->tickets->grantSignupGrant($organization);
}
```

### dead code 除去
`resolveInvoiceSubscriptionId()` は signup grant の冪等キー生成専用（唯一の呼び出しが上記 L269）。
org スコープ化で不要になるため**メソッドごと削除**する（「後方互換の並走を残さない」）。関連する
`use RuntimeException;` が他で未使用になる場合は import も整理する（他 report 呼び出しが残るため要確認）。

### 波及挙動の変化（テスト更新が必要な理由）
- 旧: subscription id が取れない `subscription_create` では signup grant を skip（fail-closed）。
- 新: **subscription id に依存せず付与する**（org スコープキー）。よって
  「subscription id が取れない subscription_create では付与しない」テストは**挙動が反転**し、
  「付与する」検証へ書き換える（施策 6）。

### PHPStan 適合チェック
- [x] `grantSignupGrant($organization)` は新シグネチャ（引数 1）に一致
- [x] `resolveInvoiceSubscriptionId` 削除後に未解決参照が残らないこと（唯一の呼び出しを除去）
- [x] 未使用 import（`RuntimeException` 等）が生じないか確認（他 `report(new RuntimeException(...))` の
  有無を確認して残す/外す）

### テスト計画
- [x] `WebhookIdempotencyTest`「subscription_create の invoice.paid は月次付与に加えて signup grant を
  冪等付与する」を `signup_grant:org:{id}` キー期待へ更新（施策 6）
- [x] `WebhookIdempotencyTest`「subscription id が取れない subscription_create では付与しない」を
  「付与する（org スコープキーで冪等）」へ書き換え（施策 6）

### リスク
- webhook 側の付与挙動が「常に付与（冪等）」へ緩む。ただし部分 index が二重付与を弾くため過剰付与なし。
  非個人組織のサブスク作成に対しては従来どおり 1 回付与される（安全網として妥当）。

---

## 施策 5: LP 文言の挙動整合（「新規契約」→「新規登録」）

### 変更箇所
- `resources/js/pages/Pricing.svelte` L54（FAQ 回答）/ L168（signup-grant-note）

### 波及変更
- TypeScript 型定義: なし（`page.signupGrantTickets` / `signupGrantExpiryDays` の数値バインドは不変）
- API Resource/DTO: なし
- テストファイル: `tests/js/pages/Pricing.test.ts` L76-78（assertion 文字列更新。施策 6）

### 現行コード
```svelte
<!-- L54 FAQ -->
a: `はい。Free プランは基本料金なしでご利用いただけます。さらに新規契約でチケット ${page.signupGrantTickets} 枚 (${page.signupGrantExpiryDays} 日間有効) が無料でついてくるので、AI 解析から動画の完成までを実際にお試しいただけます。`,
<!-- L168 signup-grant-note -->
新規契約でチケット {page.signupGrantTickets} 枚が無料でついてきます (付与から {page.signupGrantExpiryDays}
日間有効)
```

### 変更後コード
```svelte
<!-- L54 FAQ -->
a: `はい。Free プランは基本料金なしでご利用いただけます。さらに新規登録でチケット ${page.signupGrantTickets} 枚 (${page.signupGrantExpiryDays} 日間有効) が無料でついてくるので、AI 解析から動画の完成までを実際にお試しいただけます。`,
<!-- L168 signup-grant-note -->
新規登録でチケット {page.signupGrantTickets} 枚が無料でついてきます (付与から {page.signupGrantExpiryDays}
日間有効)
```

### 判断根拠
本修正で付与が「契約時」→「登録時」へ移るため、現行「新規契約で」は事実と食い違う。「新規登録で」へ
整合させる（`Welcome.svelte` は既に「新規登録で」で正確・据え置き）。「新規ワークスペース作成で」は
**追加ワークスペース作成では付与しない**ため過大表現になり採用しない。招待経由は LP CTA の対象外。

### DESIGN.md / Atomic Design 準拠
- 文言テキストの変更のみ。design token（color/radius/typography）・component 階層・アイコンに変更なし。
  hex 直書きの新設なし。

### PHPStan 適合チェック
- N/A（Svelte テキスト。`pnpm typecheck` で TS 影響なしを確認）

### テスト計画
- [x] `Pricing.test.ts` の `signup-grant-note` assertion を「新規登録でチケット 10 枚が無料でついてきます
  (付与から 30 日間有効)」へ更新。招待導線が LP CTA の対象外である意図をテストコメントに残す（施策 6）

### リスク
- 文言変更に伴う js テストの assertion 齟齬（施策 6 で同時更新）。

---

## 施策 6: テスト

### 6-1. Feature: 登録後残高 10（`tests/Feature/Auth/RegistrationTest.php` 追記）
- 「登録できる」テストに、登録後 `TicketLedgerService::balance($personalOrg)` が
  `config('billing.signup_grant_tickets')` (=10) になることを追加。
- 新規: 「招待経由登録では個人組織を作らず signup grant を付与しない」— 招待セッションを張った登録で
  個人組織が生成されず、付与も走らないこと（所属組織の残高を共有する設計）を検証。

### 6-2. Architecture: 部分 UNIQUE index の存在検証（新規 `tests/Feature/Architecture/...` or `tests/Architecture/...`）
- pgsql の `pg_indexes` を照会し、`ticket_ledger_entries` に index `ticket_ledger_entries_signup_grant_unique`
  が存在し、その `indexdef` が **UNIQUE** かつ `organization_id` を含み、述語に `signup_grant` を含むことを assert。
- **注意（Codex Round 4 caveat）**: pgsql は `LIKE` を `~~` 演算子として、リテラルを `'signup_grant:%'::text`
  として `indexdef` に描画する。よって完全一致文字列（"LIKE" 等）に依存せず、`UNIQUE` /
  `organization_id` / `signup_grant`（部分文字列）の**含有**で検証する。
- テストは pgsql driver 前提（テスト DB は pgsql）。既存 Architecture テストの配置規約に合わせる。

```php
// 例 (Pest) — indexname 完全一致 + indexdef の必須要素を全て要求し誤検知を防ぐ
test('ticket_ledger_entries は 1 組織 1 signup grant を部分 UNIQUE index で強制する', function (): void {
    $row = DB::selectOne(
        "SELECT indexdef FROM pg_indexes
         WHERE tablename = 'ticket_ledger_entries'
           AND indexname = 'ticket_ledger_entries_signup_grant_unique'",
    );
    expect($row)->not->toBeNull(); // indexname 完全一致で 1 行
    /** @var string $def */
    $def = $row->indexdef;
    expect($def)->toContain('UNIQUE');              // 一意制約であること
    expect($def)->toContain('ticket_ledger_entries'); // 対象テーブル
    expect($def)->toContain('organization_id');       // 対象列
    expect($def)->toContain('WHERE');                 // 部分 index (述語) であること
    expect($def)->toContain('signup_grant');          // 述語がキー prefix を参照 (LIKE は ~~ に正規化され得る)
});
```

### 6-3. Feature: 部分 index の実競合抑止（不変条件）
- 同一組織へ **異なる idempotency_key** の signup grant 行を 2 回作ろうとしても 1 行のみ・残高が
  `config('billing.signup_grant_tickets')` に留まることを検証（DB 制約の実効性）。
- **実装詳細非依存**: 1 回目は公開ユースケース `grantSignupGrant($org)`、2 回目は旧キー形式を
  `DB::table()->insertOrIgnore()` で直接投入して**別キーが部分 UNIQUE index に弾かれる**ことを検証する
  （`grantMonthly` の可視性に依存しない）。

```php
test('1 組織に signup_grant は異なるキーでも高々 1 回しか計上されない (部分 UNIQUE index)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $svc = app(TicketLedgerService::class);

    // 1 回目: 公開ユースケース経由 (org スコープキー signup_grant:org:{id})
    $svc->grantSignupGrant($organization);

    // 2 回目: 旧キー形式を直接投入 → 部分 UNIQUE index (organization_id WHERE idempotency_key
    // LIKE 'signup_grant:%') が別キーでも弾く (ON CONFLICT DO NOTHING)
    DB::table('ticket_ledger_entries')->insertOrIgnore([
        'organization_id' => $organization->getKey(),
        'delta' => 10,
        'kind' => \App\Enums\Billing\TicketLedgerKind::Grant->value,
        'source' => \App\Enums\Billing\TicketSource::Monthly->value,
        'description' => '初回 signup grant (legacy)',
        'granted_at' => now(),
        'expires_at' => now()->addDays(30),
        'idempotency_key' => 'signup_grant:sub_legacy',
        'created_at' => now(),
    ]);

    expect($organization->ticketLedgerEntries()
        ->where('idempotency_key', 'like', 'signup_grant:%')->count())->toBe(1);
    expect($svc->balance($organization))->toBe(config('billing.signup_grant_tickets'));
});
```

### 6-4. 既存 `TicketGrantTest` の更新（新シグネチャ）
- 「grantSignupGrant は config の枚数・期限で冪等付与する」: 呼び出しを `grantSignupGrant($organization)`
  へ、キー期待を `signup_grant:org:{$organization->id}` へ更新。二重呼び出しでも 1 行/残高 10 を維持。
- 「config が不正 (0 以下) なら停止する」: 引数を除いた `grantSignupGrant($organization)` へ更新。

### 6-5. 既存 `WebhookIdempotencyTest` の更新
- 「subscription_create の invoice.paid は月次付与に加えて signup grant を冪等付与する」:
  idempotency_key の期待を `signup_grant:org:{$organization->id}` へ更新（`sub_signup_1` 依存を除去）。
- 「subscription id が取れない subscription_create では付与しない」→
  「**subscription id が無くても org スコープキーで signup grant を付与する**」へ書き換え
  （balance 110 / `signup_grant:%` 1 行）。

### 6-6. 既存 `Pricing.test.ts` の更新
- `signup-grant-note` assertion を「新規登録でチケット 10 枚が無料でついてきます (付与から 30 日間有効)」へ。

### PHPStan / テスト実行
- `composer phpstan`（L10）/ `composer test`（`--parallel`）/ `vendor/bin/pint --test` /
  `pnpm lint` / `pnpm typecheck` / `pnpm test` を全 green で完了とする。

---

## 使命・禁止事項チェック（最終確認）

- 使命寄与: LP 約束の無償 10 枚を登録時に実付与し「まず触れる」導線を回復（North Star の入口）。
- 禁止事項: PHPStan 無視なし / 全施策にテスト / `response()->json()` 直書きなし / DTO・Props 変更なし /
  append-only 厳守（migration は index 追加のみ、台帳行を触らない）/ `DatabaseTransactions` 個別使用なし。
- 課金冪等性（§7）: idempotency_key + 部分 UNIQUE index の三層（DB 制約 / Architecture テスト /
  Feature テスト）で「1 組織 1 signup grant」を原子保証。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 既存の Billing 台帳・登録アクション・Stripe webhook・LP に対する局所改修で、新規ドメインの独立追加ではない。既存テストの更新を伴い、現行コードとの結合観点（呼び出し元 2 箇所の同時更新）が重要なため、既存ブランチ上で段階的に統合する incremental が適切。 |
| 競合リスク | Billing 系（`TicketLedgerService` / `StripeWebhookProcessor`）と Auth 系（`CreateNewUser`）に跨るが、いずれも本 finding 専用の変更で他タスクとの重複は想定薄。migration のタイムスタンプ採番のみ他 migration 追加タスクと衝突し得るため採番時に最新を確認する。 |

