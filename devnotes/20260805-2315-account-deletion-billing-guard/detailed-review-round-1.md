レビュー結果: **CHANGES_REQUESTED**

## 施策 1: 課金責務 guard 新設

判定: **REQUEST_CHANGES**

[Critical] `Collection` の import が抜けています。  
`orphanBillingOrganizationIds(Collection $ownerlessOrganizations)` を使うため、実装ファイルに以下が必要です。

```php
use Illuminate\Support\Collection;
```

[Warning] `orphanBillingOrganizationIds()` は `hasLiveBillingObligation()` を組織ごとに呼ぶため、検知バッチで N+1 になります。設計では daily なので許容としていますが、「全組織を走査する」前提なら将来の組織数増加で効きます。  
修正案: v1 では許容するなら、`docs/architecture.md` に「現時点では許容、件数増加時は subscription eager load か exists subquery 化」と明記してください。

## 施策 2: blocker enum / DTO

判定: **REQUEST_CHANGES**

[Warning] `AccountDeletionBlockerDto::build()` の action 導出仕様が不足しています。  
両理由がある場合の action 順、重複排除、current org 判定時の billing action が実装者依存になります。

修正案: DTO 設計に以下を固定してください。

- `OwnerlessMembers` → `transfer_ownership`
- `ActiveBilling` かつ current org → `open_billing`
- `ActiveBilling` かつ non-current org → `switch_organization_then_open_billing`
- 順序は `transfer_ownership` → billing action
- action は重複なし

[Warning] `build()` の `$reasons` に phpdoc が必要です。PHPStan level 10 では enum list として狭めるべきです。

```php
/**
 * @param list<AccountDeletionBlockReason> $reasons
 */
public static function build(
    Organization $organization,
    array $reasons,
    bool $isCurrentOrganization,
): self;
```

## 施策 3: OrganizationMembershipService 拡張

判定: **REQUEST_CHANGES**

[Critical] `deleteAccount()` の「ロック下再評価」が課金 webhook との競合に対して十分でない前提になっています。  
設計本文では「課金状態の読み取りは組織行ロック取得後」とありますが、Cashier webhook が `subscriptions` 行を作る経路が `organizations` 行ロックを共有しないなら、組織行ロック後に subscription が追加される race は残ります。設計は daily 検知で second layer としていますが、`deleteAccount()` のコメントに「権威判定」と書くと過剰主張です。

修正案: コメントと docs を修正してください。

- `deleteAccount()` は通常アプリ経路の権威判定
- vendor Cashier webhook との完全排他はしない
- 漏れは daily 検知で捕捉する
- 「課金状態の読み取りは組織行ロック取得後」は membership race 対策であり、subscription 作成 race の完全排他ではない

[Critical] `organizationsWithoutOwner()` の Laratrust pivot カラム名が不確かです。本文では不変条件が `laratrust_team_id` 明示ですが、コードは `role_user.team_id` を参照しています。既存が本当に `team_id` ならよいですが、設計だけでは `laratrust_team_id` と混在しており危険です。

修正案: 既存先例と実スキーマに合わせ、設計に正確な pivot カラム名を明記してください。`role_user.team_id` を使うなら「Laratrust pivot は `team_id`、organizations 側は `laratrust_team_id`」と明記するのが必要です。

[Warning] `->filter()` だけでは PHPStan が `Collection<int, ?AccountDeletionBlockerDto>` から `Collection<int, AccountDeletionBlockerDto>` へ十分に narrow しない可能性があります。

修正案:

```php
->filter(fn (?AccountDeletionBlockerDto $blocker): bool => $blocker !== null)
->values();
```

または `map` ではなく `flatMap` / 明示的な collection 組み立てにしてください。

[Warning] `hasAnotherOwner()` が組織ごとに呼ばれるため、`/settings` で組織数分の role query が走る可能性があります。既存踏襲なら許容ですが、今回さらに billing query も足されます。  
修正案: v1 では許容する根拠を「通常 1〜数件」だけでなく、既存実装踏襲として明記してください。

## 施策 4: 表示 props 差し替え

判定: **APPROVE**

[Suggestion] `ProfileController` から `use App\Models\Organization;` が不要になります。実装時に消してください。Pint/PHPStan の対象です。

## 施策 5: Svelte 警告 UI

判定: **REQUEST_CHANGES**

[Warning] `blocker.actions` をそのまま並べると、リンクやボタンが区切りなしで連続表示される可能性があります。UI とテストで視認性を固定した方が安全です。

修正案: action 群を `<div class="...">` や `<ul>` で分け、各 action が独立した操作として見える構造にしてください。DS token/classes は既存パターンに合わせること。

[Warning] `switchThenBilling()` の `router.post` は操作系 POST なので、CSRF/redirect は Inertia が扱えますが、失敗時の user feedback が設計されていません。404/403/validation 時に無反応に見える可能性があります。

修正案: `onError` または既存 flash/error 表示に乗ることを明記してください。最低限テストで「成功時のみ visit」を固定するのは良いです。

[Warning] `errors.account` を配列全表示へ変えるなら、派生値の型を `string | null` から `string[]` へ変更する設計が必要です。

修正案:

```ts
const accountErrors = $derived.by((): string[] => {
    const err = props.errors?.account;
    if (err === undefined) return [];
    return Array.isArray(err) ? err : [err];
});
```

## 施策 6: 孤児組織検知バッチ

判定: **REQUEST_CHANGES**

[Critical] `routes/console.php` のクロージャ DI で `OrganizationMembershipService $membership, AccountDeletionBillingGuard $guard` が解決される前提は確認が必要です。Laravel の `Artisan::command()` はコマンド引数との衝突や closure injection の扱いが route/controller とは違うため、既存 console の書き方に合わせるべきです。

修正案: 既存が DI していないなら、明示的に `app()` で解決してください。

```php
Artisan::command('billing:detect-orphan-billing-organizations', function (): void {
    $membership = app(OrganizationMembershipService::class);
    $guard = app(AccountDeletionBillingGuard::class);
    // ...
});
```

[Warning] `RuntimeException` の import が必要です。FQCN にするか `use RuntimeException;` を追加してください。

[Warning] schedule 名が追加されるため、既存の schedule / console architecture test があるなら更新対象に含めるべきです。設計では触れていません。

## 施策 7: テスト計画

判定: **REQUEST_CHANGES**

[Critical] 「退会成功経路で Stripe を呼ばない」テストが成功経路だけです。今回重要なのは「課金中でブロックされる経路でも Stripe を呼ばない」ことです。  
修正案: 課金中個人組織で削除がブロックされるケースにも `StripeGatewayInterface` mock を bind し、呼ばれないことを固定してください。

[Warning] console command のテストがありません。施策 6 は必須施策なので、検知なし / 検知あり / PII を report に載せない、の最低限が必要です。

修正案: `tests/Feature/Billing/DetectOrphanBillingOrganizationsCommandTest.php` を追加し、以下を固定してください。

- orphan なしなら success/info
- orphan ありなら report が 1 回
- report message に organization id と count は含む
- organization name / user email は含まない

[Warning] `organizationsWithoutOwner()` の SQL 条件を守るテストが不足しています。特に cross-team role 誤判定はセキュリティ不変条件に関わります。

修正案: 別 organization/team の owner role を持つ user がいても、対象 organization の owner として数えないケースを追加してください。

## 施策 8: ドキュメント

判定: **REQUEST_CHANGES**

[Warning] 「判定の権威は deleteAccount のロック下再評価」とだけ書くと、Cashier webhook 競合まで完全に防ぐ印象になります。  
修正案: 通常アプリ経路の権威判定と、vendor webhook 競合の非排他を分けて記述してください。

[Suggestion] Stripe redaction の 90 日 / 最大 30 日は外部仕様依存です。docs に書くなら、参照元と確認日を残す運用にしてください。

## 全体判定

**CHANGES_REQUESTED**

設計の方向性は妥当です。退会時に「唯一 Owner + 他メンバー」だけでなく「唯一 Owner + 課金責務」を止める責務分離も、Billing 層に閉じる方針も良いです。

ただし、このまま実装に入るには以下が不足しています。

- Cashier webhook 競合に対する「権威判定」の表現が強すぎる
- console command の実装可能性とテストが弱い
- PHPStan level 10 で詰まりそうな import / generic narrowing / phpdoc が残っている
- PII 非出力と Laratrust team 境界のテストが不足している

上記を直せば、詳細設計として実装可能な水準になります。