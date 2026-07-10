# Stripe Price Catalog

`stripe/fixtures/*.json` は **現在あるべき価格 catalog の宣言 (current desired state)**。
価格改定は fixture を PR で更新してから Stripe Catalog へ適用する。

アプリ runtime は `plan_prices` (DB snapshot) 経由でのみ Price を参照する。
`plan_prices` を書き込むのは bootstrap seeder (`PlanSeeder`) と
`php artisan billing:sync-stripe-prices` のみで、fixture / Stripe を runtime で
直接参照しない。

## lookup_key 規約

lookup_key は `{app_slug}_{plan_code}_{kind}` (slug は `.env` の `TEMPLATE_APP_SLUG`
= `config('template.slug')`)。コード側の宣言は
`App\Support\Billing\StripePriceLookupKeys` が単一出典で、fixture との集合一致は
`tests/Architecture/StripePriceCatalogFixtureInvariantTest` が CI で固定する。

**アプリ初期化で slug を変更したら、fixture 内のリテラル lookup_key
(`app_standard_base` 等) と `metadata.managed_by` も必ず書き換えること**
(書き換え漏れは上記 invariant テストが検出する)。

## 構成 (テンプレート初期状態)

| fixture | 投入内容 | lookup_key |
|---|---|---|
| `plan_standard.json` | Standard プラン基本料 (月額 1,980 円) | `app_standard_base` |

Free (無料・サブスク対象外) は fixture から除外し、plan_prices 行を持たない。
追加席課金 (kind=seat) を導入する場合は `StripePriceLookupKeys` の宣言と fixture に
seat price を追加する (schema は plan×kind 多軸に対応済み)。

## 初期構築 (新規 Stripe Account)

```bash
# Stripe CLI のログイン (初回のみ。test mode)
stripe login

# fixture を Stripe Catalog に投入
stripe fixtures stripe/fixtures/plan_standard.json

# DB snapshot (plan_prices) を Stripe Catalog から同期
php artisan billing:sync-stripe-prices

# fixture / Stripe / plan_prices の整合確認
php artisan billing:verify-stripe-prices
```

live mode に投入する場合は `--api-key <live key>` を各 `stripe` コマンドに渡す。

## 価格改定運用 (transfer_lookup_key)

Stripe の Price は immutable なため、価格改定は新 Price を作って `lookup_key` を移管する。

```bash
# 例: <slug>_standard_base を 1,980 → 2,980 円に改定
# 1. 新 Price を作成 (lookup_key 未設定)
stripe prices create --currency=jpy --unit-amount=2980 \
  --product=<standard product id> -d "recurring[interval]=month"

# 2. 旧 Price の lookup_key を新 Price に移管
stripe prices update price_NEW --lookup-key=<slug>_standard_base --transfer-lookup-key=true

# 3. 旧 Price を archive
stripe prices update price_OLD --active=false

# 4. fixture の unit_amount と PlanSeeder の bootstrap 金額を新価格に更新 (PR)

# 5. DB snapshot を同期 → 整合確認
php artisan billing:sync-stripe-prices
php artisan billing:verify-stripe-prices
```

- **`lookup_key` には金額を含めない** (改定で名前が嘘になる)。
- **既存 subscription が存在する状態での価格改定は本 runbook の対象外**。
  `transfer_lookup_key` だけでは既存契約の price は変わらず、subscription item の
  swap / schedule update といった別設計が必要。本 runbook は「新規 checkout 経路 +
  DB snapshot の整合」のみを扱う。

## go-live チェックリスト

- [ ] CI secrets に Stripe RAK (restricted API key) を投入後、`billing:verify-stripe-prices`
      を (1) release workflow の必須 gate、(2) 週次 schedule (失敗時通知) として
      CI workflow に組み込む。
