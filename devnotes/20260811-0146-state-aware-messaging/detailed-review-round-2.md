## 施策 1: REQUEST_CHANGES

[Warning] `DashboardPageData::toArray()` の PHPDoc で `value-of<OnboardingBillingState>` を使う場合、`OnboardingBillingState` の import が必要です。提示された変更説明では `BillingSummaryData` 側の `use` だけが明示されています。

修正案:

```php
use App\Enums\Billing\OnboardingBillingState;
```

または PHPDoc で完全修飾名を使ってください。これがないと名前空間が `App\DataTransferObjects\Dashboard\OnboardingBillingState` と解釈される可能性があります。

fixture 修正は妥当です。`grandfatherFreePlan: false` により `ActiveFreePlan` の早期 return を避け、pending / expired の分岐へ到達できます。ただし、新規 5 のコード例は組織生成部分が省略されているため、最終設計には次の形を明記してください。

```php
[$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);

BillingCheckoutSession::factory()
    ->for($organization)
    ->expired()
    ->create();
```

必須注意だけでなく各テストの具体的 fixture に残すことで、実装時の取り違えを防げます。

[Suggestion] 「dashboard props は外部 API 契約ではないため、外部消費者は存在しえない」は断定が強すぎます。公開 API ではなくリポジトリ内の既知の消費者は全数確認した、という保証に留めるのが正確です。ブラウザ拡張、外部 E2E、運用スクリプトなど、リポジトリ外の消費者の不存在までは機械的に保証できません。

## 施策 2: APPROVE

`BillingStateValue`、`satisfies Record<...>`、状態別コピーの組み合わせは妥当です。課金ゲートの case 集合や判定を変更せず、F-2-01 に必要な表示情報だけを wire に載せています。

非 `manageBilling` ユーザーの着地テストを維持する判断にも賛成です。フロントで認可を再実装しない設計の根拠になっています。

[Suggestion] Browser テスト 2 本は同一 fixture・同一画面を対象としているため、実行時間を抑えるなら1本に統合できます。表示すべき新文言、旧文言の非表示、CTA 着地を同じブラウザセッションで確認すれば検出力は落ちません。必須変更ではありません。

## 施策 3: APPROVE

追加された「429 の発生自体は減らない」という非保証は十分具体的です。連打抑止、in-flight 排他、cooldown、limiter 変更を明示的に範囲外としており、改善内容を誇張していません。

## 施策 4: APPROVE

反論 4 は妥当です。提示された `phpstan.neon` の `paths` に `tests/` がなく、同じ closure 宣言が既存 Architecture テストで使われている以上、Round 1 の PHPStan Warning は撤回します。提供された情報から、`tests/` が別設定や追加コマンドで PHPStan 対象になっているという反証はありません。

`missing('dashboard.billing.has_billing_access')` は nested path にそのキーが存在しないことを検査するため、DTO が新旧両方を出力する mutation #8 を検出できます。値が `null` でも「存在」と扱われるので、単なる値検査より並走防止に適しています。

ただし mutation #8 では、PHPDoc shape だけでなく実際の `toArray()` 出力へ旧キーを追加してください。PHPDocだけを変えても Inertia payload は変わらず、`missing()` は赤化しません。

literal union 抽出形式の非保証も適切です。抽出形式変更時に fail-closed になることまで明記されており、保証範囲を正確に表現しています。

## 全体判定: CHANGES_REQUESTED

設計の方向性とテスト戦略は承認可能な水準です。残る必須修正は、`DashboardPageData` での enum importまたは完全修飾名の明記と、expired fixture に `grandfatherFreePlan: false` を具体コードとして残すことです。

過剰設計に当たる施策はありません。Browser テスト2本の統合は効率化候補ですが、現状のままでも承認を妨げるものではありません。