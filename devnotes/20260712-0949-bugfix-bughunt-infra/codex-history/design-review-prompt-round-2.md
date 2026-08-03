# Codex 詳細設計レビュー Round 2: bugfix-bughunt-infra

Round 1 の指摘に対応した。Critical 2 件は全て設計へ反映、Warning は 2 件の事実ベース反論を除き反映。
差分を確認し、全体判定を更新してほしい。

## 対応マトリクス

### [Critical] A3: FakeTicketCheckoutGateway の sessionId 生成
- **対応済み**: `$token = substr(hash('sha256', $idempotencyKey), 0, 32)` で固定長トークン化し
  `cs_bughuntfake_{token}` を生成 (決定論・文字種/長さ非依存)。コード例を修正。

### [Critical] B1: paidPlanCodes() の list<string> 型保証
- **対応済み**: `->map(fn (Plan $plan): string => $plan->code)->values()->all()` を正式コードに
  昇格 (pluck 廃止)。

### [Warning] A2: PortalConfigurationSpec の use 欠落
- **反論 (事実誤認)**: `PortalConfigurationSpec` は `App\Services\Billing` 名前空間にあり、
  `CashierSubscriptionCheckoutGateway` も同一名前空間のため `use` は不要 (PHP の名前解決は
  同一 namespace 内を無修飾で参照可能)。設計書のクラス docblock に「同一名前空間のため use 不要」と
  注記して誤読を防いだ。

### [Warning] A2: checkout() 戻り型の意図
- **対応済み**: docblock に「戻り型に RedirectResponse を含むのは price 不在時の back() 分岐のため」を追記。

### [Warning] A3: 未使用 import
- **対応済み**: コード例から `use App\Services\Billing\CashierTicketCheckoutGateway;` を削除。

### [Warning] A3: FakeExternalUrl::neutralReturn の空文字防御
- **対応済み**: `Assert::stringNotEmpty($appUrl)` を追加。

### [Warning] B1: run() の method injection
- **反論 (現状維持)**: Laravel 公式の Seeder DI 作法で、レビュー自身も型安全性の優位を認めている。
  docblock に「依存は method injection で受ける (公式作法・型安全)」を明記した。

### [Warning] B1: stripe_id 決定論値の一意性説明
- **対応済み**: 「`sub_bughunt_{org id}` は org 単位一意 (subscriptions.stripe_id UNIQUE と両立)」を
  helper docblock に追記。

### [Warning] C2: filament_version_from_lock 空文字時の可観測性
- **対応済み**: 空文字時に stderr へ warning echo (「marker skip 不可 = 毎回 publish 判定」)。

### [Warning] C2: die メッセージの運用案内
- **対応済み**: 「artisan filament:assets の出力を確認すること」を die メッセージに追記。

### [Suggestion] A1: config:cache 運用 → 対応済み (A1 リスク欄: bughunt provision は
  clear_stale_config 済み / production は ProductionEnvGuard が実効 config を検査)
### [Suggestion] A1: violation 文言の environment 明示 → 対応済み (既に 'must be false in production' 形式で既存トーンと一致)
### [Suggestion] A2: DTO の filter_var URL 検証 → 見送り (内部 DTO。入力は route()/Cashier 由来のみで
  stringNotEmpty で十分。過剰検証は複雑化)
### [Suggestion] A3: provider テストの app refresh → 注記追加 (Pest はテスト毎に app 再構築。
  テスト内の env 変更は try/finally 復元を維持)
### [Suggestion] B1: 「fake_externals=true でも env=testing なら no-op」ケース → 対応済み
  (BughuntBillingSeederTest ケース 4 / BughuntOAuthSeederGuardTest に追加)
### [Suggestion] C1: shouldSeed 意図の class docblock 反映 → 対応済み
### [Suggestion] C2: cmd_assets_check への filament marker 統合 → 見送り (self-test フィクスチャ
  改修が必要でスコープ超過。レビューも「今回必須ではない」)

## 更新後の設計書該当箇所 (抜粋)

```php
// FakeTicketCheckoutGateway::createTicketCheckout (修正後)
        // idempotency key から決定的に導出 (同一 attempt の再送は同一 session に収束)。
        // key の文字種・長さに依存しないよう sha256 の先頭 32 桁で固定長トークン化する
        // (stripe_session_id 列・URL への混入安全性)
        $token = substr(hash('sha256', $idempotencyKey), 0, 32);

        return new CreatedCheckoutSession(
            sessionId: "cs_bughuntfake_{$token}",
            url: FakeExternalUrl::neutralReturn($cancelUrl),
            expiresAt: CarbonImmutable::now()->addDay(),
        );
```

```php
// FakeExternalUrl (修正後)
use Webmozart\Assert\Assert;

final class FakeExternalUrl
{
    public const string MARKER = 'fake_external=stripe';

    public static function neutralReturn(string $appUrl): string
    {
        Assert::stringNotEmpty($appUrl, '中立帰還先のアプリ内 URL が空です');

        return $appUrl.(str_contains($appUrl, '?') ? '&' : '?').self::MARKER;
    }
}
```

```php
// BughuntBillingSeeder::paidPlanCodes (修正後)
    private function paidPlanCodes(): array
    {
        // pluck は list<mixed> になるため map で list<string> を実型でも保証する (PHPStan level 10)
        return Plan::query()->orderBy('sort_order')->get()
            ->filter(fn (Plan $plan): bool => $plan->currentPrice(PlanPriceKind::Base) !== null)
            ->map(fn (Plan $plan): string => $plan->code)
            ->values()
            ->all();
    }
```

```bash
# ensure_filament_assets (修正後)
ensure_filament_assets() {
    local db=$1 url=$2
    is_dryrun && return 0
    local version; version="$(filament_version_from_lock)"
    [[ -z "${version}" ]] \
        && echo "warning: composer.lock から filament/filament version を解決できない (marker skip 不可 = 毎回 publish 判定)" >&2
    if [[ -n "${version}" && -f "${FILAMENT_ASSET_MARKER}" \
        && "$(cat "${FILAMENT_ASSET_MARKER}")" == "${version}" ]] && filament_assets_present; then
        return 0
    fi
    echo ">>> filament assets missing/stale → filament:assets"
    artisan_for_shard "${db}" "${url}" filament:assets
    filament_assets_present \
        || die 1 "filament:assets 実行後も必須アセットが無い (${FILAMENT_REQUIRED_ASSETS[*]})。filament の publish 先変更を疑い、artisan filament:assets の出力を確認すること"
    [[ -n "${version}" ]] && printf '%s' "${version}" > "${FILAMENT_ASSET_MARKER}"
    return 0
}
```

全体判定 (APPROVED / CHANGES_REQUESTED) の更新を。残る Critical/Warning があれば指摘を。
