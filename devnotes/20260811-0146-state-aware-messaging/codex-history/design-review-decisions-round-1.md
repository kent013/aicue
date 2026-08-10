# 対応マトリクス: design-review Round 1

Codex 全体判定: **CHANGES_REQUESTED**（施策 1 / 4 が REQUEST_CHANGES、施策 2 / 3 は APPROVE）。
Critical 0 / Warning 5 / Suggestion 3。

## [Warning] 施策 1: `pending_checkout` / `expired_checkout` の Feature fixture が不明確

- 判断: **対応する**
- 根拠: 指摘のとおり。`tests/Pest.php:173-180` の `createOrganizationWithOwner()` は
  既定 `$grandfatherFreePlan = true` で `free_plan_code` を立てる。
  `BillingAccess::state()` は entitled subscription → `free_plan_code` の順で判定するため、
  既定のまま checkout session を作っても `ActiveFreePlan` が先に返り、
  **テストが意図した state に到達しないまま緑になる**（最悪の空振り）。
- 対応内容: テスト計画 A に fixture の必須注意ブロックを追加し、新規テスト 1 / 4 / 5 に
  `createOrganizationWithOwner(grandfatherFreePlan: false)` を含むコード片を明記した。

## [Warning] 施策 1: `has_billing_access` 非残存がテストで固定されていない

- 判断: **対応する**
- 根拠: 指摘のとおり。`billing_state` を**追加**しただけで旧 prop を残しても
  全テストが緑になる = 思考原則 3（後方互換の並走を残さない）が機械で守られていない。
- 対応内容: 新規 Feature テスト 1 本目に
  `->missing('dashboard.billing.has_billing_access')` を追加。
  mutation 表にも「両方を併記する変異」（#8）を追加し、この assert が実際に赤くなることを確認する手順にした。

## [Suggestion] 施策 1: `billing_state: string` より `value-of<OnboardingBillingState>`

- 判断: **対応する**
- 根拠: リポジトリ内に先例がある（`app/DataTransferObjects/Billing/BillingFeedbackDto.php:14` の
  `@phpstan-type BillingFeedbackShape array{kind: value-of<BillingFeedbackKind>, …}`）。
  型精度が上がり、enum の値集合が PHPStan に伝わる。level 10 で通る書き方である。
- 対応内容: `BillingSummaryData::toArray()` と `DashboardPageData::toArray()` の
  `@return` shape を `value-of<OnboardingBillingState>` に変更した。

## [Warning] 施策 4: 2 本目の closure 戻り型 `array` が PHPStan level 10 で落ちる可能性

- 判断: **反論する（変更しない）**
- 根拠（証拠つき）:
  1. `phpstan.neon` の `paths` は `app` / `config` / `database` / `routes` の 4 つで、
     **`tests/` は解析対象に含まれない**。よって tests 配下の closure 戻り型は
     level 10 の検査を受けない。
  2. 仮に対象だったとしても、**まったく同じ書き方**
     (`expect(fn (): array => TsUnionValues::extract(...))->toThrow(...)`) が
     `tests/Architecture/AccountDeletionBlockerActionTsSyncInvariantTest.php` に
     既に存在し、CI は緑である。
  3. 既存 3 つの sync gate と書式を揃えるほうが、目録として読み比べやすい。
- 対応内容: 設計を変えず、詳細設計の施策 4 に上記の根拠を注記として明記した
  （次に読む人が同じ疑問を持たないように残す）。

## [Warning] 施策 3: 「保証しないもの」の書き漏れ（連打抑止・429 発生防止）

- 判断: **対応する**
- 根拠: 指摘が正しい。本施策は 429 到達**後**の文言だけを変える。
  「429 が出にくくなる」と読まれると誇張になる。
- 対応内容: 「保証しないもの」に項目 8 を追加
  （連打抑止・cooldown 表示・in-flight 抑制・429 発生率の低下はいずれも保証しない。
  limiter と閾値は 1 文字も変えない）。

## [Suggestion] 施策 4: literal union の regex 抽出前提

- 判断: **対応する**
- 根拠: `TsUnionValues::extract()` は `export type X = "a" | "b";` の書式に依存する。
  書式を変えると gate は **fail-closed で赤くなる**（silent PASS ではない）が、
  その前提は明記しておくべきである。
- 対応内容: 「保証しないもの」に項目 9 として追加した。

## [Warning] 検証コマンドが AGENTS.md の canonical list と非同期

- 判断: **対応する**
- 根拠: AGENTS.md の `VERIFICATION_COMMANDS` マーカー内が正本で、
  `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` を含む。
  部分引用は「走らせなくてよい」という誤読を生む。
- 対応内容: §G を canonical list 全 10 本 + `composer test:browser` に置き換え、
  部分引用しない理由（`verification-commands-doc-sync.test.ts` が同期を強制する list である）を注記した。

## [Suggestion] 施策 2: 非 manageBilling が billing-required へ流れることを必ずテストに残す

- 判断: **対応する（既に計画済みのため維持）**
- 根拠: Feature テスト 3 本目が既にそれである。フロントで権限分岐しない判断
  （禁止事項 8 の思想）の behavioral な裏付けとして必須。
- 対応内容: 変更なし（削らないことを明示）。
