# c2c 追従 第 2 周 (T119 / T120 / T118) 事後監査レポート

- 監査日時: 2026-08-06 16:19 (JST)
- 監査対象コミット: `b01b90b` (main。`Merge branch 'todo/T118'`)
- 監査範囲: `dedb71f..b01b90b` (T119 `4a8224f` / T120 `0e877ae` / T118 `4e8a44b` の 3 実装コミットと 3 マージコミット)
- 立場: 独立監査。実装担当・マージ担当の自己申告は根拠として採らず、実コードと mutation 実走で裏を取った。
- ブリーフ (正本): `brief2-external-fakes-wiring-gate.md` / `brief2-path-based-throttle.md` /
  `brief2-t118-payload-id-org-scoping.md` (セッション作業ディレクトリ)

## 総合判定

**PASS_WITH_FOLLOWUP**。

3 件とも「ブリーフの必須スコープを実装し、スコープ外にしたものは詳細設計に理由付きで残す」
という要求を満たしている。黙って落とされた項目は後述の 1 件 (社会ログイン GET) を除いて無い。
既存テストの削除・skip・アサーション緩和は **0 件** (振る舞い変更に伴う期待値更新 3 件はいずれも正当)。

ただし **T118 の主力テスト `PayloadIdExistenceOracleTest` に偽グリーンが 1 件ある**。
本監査の mutation 実走で「ブリーフが本タスクの核心と名指しした劣化 (`exists:users,id` の再導入)」が
**1 件も検出されない**ことを実証した。現行の本番挙動は正しいので稼働中の脆弱性ではないが、
回帰ガードとしては成立していない。

---

## 1. T119 / `external-fakes-wiring-gate`

### 要求 → 実態 → 判定

| # | ブリーフの要求 | 実装の実態 | 判定 | 根拠 |
|---|---|---|---|---|
| 1 | 柱 1 (実証ベースの配線検査) を**必須スコープ**とする | `ExternalFakeWiringInvariantTest` 3-1〜3-12。`app($binding->abstract)::class` の**厳密一致**で解決結果を確認 (instanceof を使わない) | ✅ | `tests/Architecture/ExternalFakeWiringInvariantTest.php:111-138` |
| 2 | fake の導入と復元がテスト間へ漏れないこと (spirux 形の要点) | LLM static (`Prompt::$fake`) は同一 test case 内で往復を assert し、`afterEach` はフェイルセーフとして併置 | ✅ | 同 `:247-287`, `:80-85` |
| 3 | 柱 3(c) 本番コードの fake クラス名**全走査** (クラス名はディレクトリから動的導出) | `FakeClassCatalog` がディレクトリ + 命名から導出。走査根は `app/` `routes/` `config/` `bootstrap/` の 4 根。allowlist 4 件・配置例外 2 件は件数まで固定 | ✅ | `tests/Architecture/FakeClassReferenceInvariantTest.php:29-105` |
| 4 | 柱 2 (別プロセス観測) は実査のうえ判断し、入れないなら**やらない理由を詳細設計に明記** | 「後続 TODO 候補」表に理由 (agenda 未裁定 / aicue は外部ログイン driver を fake 化していない / bug-hunt の env 注入は `bug-hunt-shard.sh self-test` が検証済み / 高コスト) と**発火条件**まで記載 | ✅ | `devnotes/20260806-1355-external-fakes-wiring-gate/detailed-design.md:848` |
| 5 | 柱 3(b) 起動時二重判定は `ProductionEnvGuard` との責務重複を実査してから決める | 実査の結果「`ProductionEnvGuard` は配備前 (`production:preflight`) と起動時 (`AppServiceProvider::boot`) の**両方**から呼ばれる」ことを確認し、残差は config キャッシュ非信頼のみ → 入れない | ✅ | 同 `:849`, `:883` / `app/Providers/AppServiceProvider.php:136-138` / `app/Console/Commands/ProductionPreflightCommand.php:23` |
| 6 | 既存資産 (`ProductionEnvGuard` の fake フラグ 3 本) を壊さない / 独自の凝った機構を新設しない | アプリコード (`app/` `config/` `bootstrap/` `routes/`) は **1 行も変更なし** (差分実測) | ✅ | `git show --stat 4a8224f` |
| 7 | aicue 固有の形 (`FakeExternalsServiceProvider` 単一化) を尊重する | inventory は provider の bind 5 本と集合一致。`ExternalFakes.php` の移植はしていない | ✅ | `tests/Support/ExternalFakes/ExternalFakeWiringInventory.php:86-131` |
| 8 | 台帳の記述を鵜呑みにしない | 台帳の誤り 2 件 (「実証も無い」→ Feature に 1 本ある / 「`ProductionEnvGuard` は配備前の層」→ 起動時にも走る) を設計で明示訂正 | ✅ | 同 `:882-883` |

### 偽グリーン検査 (mutation 実走。すべて本監査で自ら実行)

| ID | 変異 | 期待 | 実測 |
|---|---|---|---|
| G | `FakeExternalsServiceProvider` から `StripeGatewayInterface` の bind 1 行を削除 | 赤 | **赤 4 件** (3-2 が local/testing/bughunt.local の 3 データセットで real 解決を検出 + 3-8 の集合一致) |
| H | 本番コード (`app/Http/Controllers/Controller.php`) から `FakeStripeGateway` を import | 赤 | **赤 1 件** (4-3 が `app/Http/Controllers/Controller.php: App\Services\Billing\Fakes\FakeStripeGateway` を列挙) |

allowlist の実効性も確認した。`FAKE_REFERENCE_ALLOWED` は 4 件 (配線点 1・fake storage の受け口 2・
`bootstrap/providers.php`) で、`bindings()` は 5 件すべてが本物の差し替え対象。
**「allowlist 全入りで無害化」には該当しない**。

### 判定

**PASS**。指摘なし。

---

## 2. T120 / `throttle-coverage-inventory` (c2c `path-based-throttle`)

### 要求 → 実態 → 判定

| # | ブリーフの要求 | 実装の実態 | 判定 | 根拠 |
|---|---|---|---|---|
| 1 | (5) 付与漏れの目録検査を新設。保護対象群を deny-by-default で分類 | `ThrottleCoverageInventoryTest`。母集団は名前列挙でなく構造セレクタ S1∪S2∪S3。floor 40 / exemption cap 14 | ✅ | `tests/Architecture/ThrottleCoverageInventoryTest.php:172-202`, `:204-286` |
| 2 | **既存 10 本を全部 allowlist に入れて緑にするのは禁止。貼り忘れが見つかったら貼る** | 本監査で母集団を独自に再列挙: **47 route 中 36 route が throttle 1 本を保持、exemption は 11 件**。webhook 2 本 / Fortify 9 本 / 招待受諾 1 本は実際に**新規付与**されている | ✅ | 本監査の実測 (下記「母集団の独自再現」) |
| 3 | (2) キー形式 `{レーン}:{種別}:{値}` への準拠是正・email のハッシュ化 | 15 本すべてが規約に準拠。email は `EmailNormalizer` → `EmailHash` (HMAC-SHA256/app.key)。`Str::transliterate` を廃止 | ✅ | `app/Providers/FortifyServiceProvider.php:238-352` / `tests/Architecture/RateLimiterKeyConventionTest.php` |
| 4 | `Str::transliterate` は使わない (巻き添えロックアウト) | 廃止済み。Unicode の異なる 2 email が同 bucket へ collapse しないことを behavioral に固定 | ✅ | `tests/Feature/Security/AuthThrottleCoverageTest.php:144` |
| 5 | (3) 全体天井は「増幅があり止まっても中核業務が止まらない口」が実在するときだけ | **置いていない**。未認証 webhook に固定キー天井を置くと「無効 body の連打で正当通知を 429 にできる」ため、と理由を明記。後続 TODO B1 として発火条件付きで残置 | ✅ | `app/Providers/AppServiceProvider.php:244-262` / detailed-design.md:1026 の B1 |
| 6 | (1) 3 段優先順への是正 | 設定で貼れるもの (`config/fortify.php` の limiters 4 キー) は設定のまま。設定で貼れない vendor route のみ `RouteThrottleBinder` で後付け。URL パス表方式は不採用 | ✅ | `app/Support/Http/RouteThrottleBinder.php:15-38` |
| 7 | 二重付与の禁止 (実効上限の半減) | 「ちょうど 1 本」を目録検査が強制し、binder 側も冪等 + 想定外 throttle で `RuntimeException` | ✅ | `ThrottleCoverageInventoryTest.php:224-235` / `RouteThrottleBinder.php:132-159` |
| 8 | (4) 429 応答は既定のまま (削らない・書き換えない) | `Retry-After` / `X-RateLimit-*` の存在を behavioral に固定 | ✅ | `AuthThrottleCoverageTest.php:38` |
| 9 | **閾値はプロダクト依存。既存値を変えない** | 既存閾値の変更 0 件。新規値は webhook 2 本の 300/min のみで、根拠 (正常時ピークの 1〜2 桁上・SNS/Stripe とも再送対象) をコードに残置 | ✅ | `AppServiceProvider.php:244-262` |
| 10 | 範囲外 (`trusted-proxy-hardening` / `error-response-contract` / `api-error-envelope`) に手を出さない | いずれも触れていない。B5 として明示的に射程外と記載 | ✅ | detailed-design.md:1026 |

### 母集団の独自再現 (実装担当の報告に依存しない実測)

`ThrottleCoverageInventoryTest` と同じセレクタを再実装して `APP_ENV=testing` で列挙した結果:

- 保護対象 **47 route** (floor 40 を上回る)
- throttle 1 本を持つ **36 route**
- throttle 0 本 = exemption 登録済み **11 route**
  (`.well-known/oauth-*` 4 / `GET|DELETE /api/v1/mcp` 2 / `logout` 2 / `debug.login-as` /
   `default-livewire.update` / `storage.local.upload`)

exemption 11 件はいずれも `ThrottleCoverageExemption` の case と 30 文字以上の具体的根拠を持ち、
その**前提そのもの**が `ThrottleExemptionPremiseTest` (署名短絡 / 定数 405 / DB クエリ 0 件 /
Filament component の `rateLimit` 実在 / `debug.login-as` の登録条件) で behavioral に固定されている。
形骸化した allowlist ではない。

### route:cache 焼き込みの検証 (binder の skip が穴でないことの実証)

実装は「cached 起動では後付けを skip する」設計で、その正当性は
「`route:cache` は `route:clear` 後に再 bootstrap するので付与が焼き込まれる」に依存する。
これは実装担当の主張なので**自分で焼き込み後のファイルを検査**した:

```
php artisan route:cache
→ bootstrap/cache/routes-v7.php 内の出現数
   throttle:webhook-stripe            1
   throttle:webhook-ses               1
   throttle:account-register          1
   throttle:password-reset-request    1
   throttle:10,1                      6
   throttle:6,1                       6
php artisan route:clear  (実行済み。bootstrap/cache/ に残るのは packages.php / services.php のみ)
```

**主張は成立している**。残るリスク (stale cache のまま起動) は運用要件として
AGENTS.md ドメイン固有規約 5 / `docs/app-integration-guide.md §7b` / binder docblock の 3 箇所に明記済み。

### 偽グリーン検査 (mutation 実走)

| ID | 変異 | 期待 | 実測 |
|---|---|---|---|
| D | `routes/web.php` の `webhooks.ses` から `throttle:webhook-ses` を除去 | 赤 | **赤** (`webhooks.ses: throttle が 1 本も無く exemption inventory にも未登録`) |
| E | `login` limiter のキー接頭辞を `login:email-ip:` → `loginEmailIp/` へ改変 | 赤 | **赤 2 件** (キー規約不一致 + `expectedKeyPrefixes` 不一致) |
| F | `throttledFortifyRoutes()` から `two-factor.enable` を削除 | 赤 | **赤** (`two-factor.enable: throttle が 1 本も無く exemption inventory にも未登録`) |
| I | `AppServiceProvider` に未登録の `RateLimiter::for('audit-probe', …)` を追加 | 赤 | **赤** (scan 検出集合と inventory の不一致。deny-by-default が機能) |

### 所見 (Warning 1 件)

**W-1: 認証面の GET 経路が母集団セレクタから構造的に外れており、その判断が設計に書かれていない。**

セレクタ S1・S3 はいずれも `method ∈ {POST, PUT, PATCH, DELETE}` を前提にしているため、
**未認証 GET の認証面は 1 本も母集団に入らない**。実害が具体的に見えるのは `social.callback`:

- `routes/web.php:165` `GET /auth/{provider}/callback` — 未認証・throttle なし
- `app/Http/Controllers/Auth/SocialAuthController.php:88` `Socialite::driver($provider)->user()`
  = **1 リクエストにつき IdP への外向き HTTP が 1 回**発生する (増幅がある未認証経路)

さらに S3 の認証面パターンには `social\.` が列挙されている
(`tests/Architecture/ThrottleCoverageInventoryTest.php:41-42`) が、social route は
2 本とも GET なので**この列挙は 1 件も一致しない死んだ条件**であり、
「social も見ている」という誤った安心を与える。

詳細設計は「秘密を返す GET の保護」(`two-factor.qr-code` 等) を後続 TODO B2 として明示的に外しているが、
**GET の認証面一般 / SSO callback については記述が無い** (= 黙って落ちている)。
ブリーフの裁定 AG-096「認証経路の流量制限を全リポジトリで必須とする」に照らすと、
少なくとも「なぜ今回入れないか」を設計に残すべき項目である。

なお、これは今回の実装が**壊した**ものではなく元から throttle が無かった経路であり、
稼働中の回帰ではない。

---

## 3. T118 / `payload-id-org-scoping`

### 要求 → 実態 → 判定

| # | ブリーフの要求 | 実装の実態 | 判定 | 根拠 |
|---|---|---|---|---|
| A | `OrganizationOwnershipController::store` を `$organization->users()->whereKey()` へ寄せる | 実装済み。`exists:users,id` も撤去し、不在 id / 実在の非メンバー id を同一文言へ | ✅ | `app/Http/Controllers/Organizations/OrganizationOwnershipController.php:35,42-47` |
| B | `ProjectMemberController::store` を同上。403 → 404/422 の挙動変更を判断する | validation failure (field error) に倒した。pivot 在籍とロール付与が同値でないため `organizationRole()` 判定は残し、両者を同一文言へ統一 | ✅ | `app/Http/Controllers/Projects/ProjectMemberController.php:38,51,63-67` |
| C | `McpConsentOrganizationBinder::handle` の `Organization::find()` を撤去 | 撤去済み。整数として受理した id は全て membership 判定へ流し 403 に統一。`bool` guard と `filter_var` 条件は据え置き | ✅ | `app/Http/Middleware/McpConsentOrganizationBinder.php:65-68` |
| D | inventory の債務 3 件を削除し `modelDirectFetchDebtCap()` を 3 → 0 | 両方実施。分類 case と `globalExistenceRuleDebt()` は裁定語彙として残置 | ✅ | `tests/Support/Security/DirectFetchInventory.php:316-319` / `tests/Architecture/ModelDirectFetchInvariantTest.php:61` |
| E | 既存テストが 403/422 を仕様固定していれば**期待値を変える** (削除ではない) | 3 件の期待値更新のみ。テスト削除・skip・緩和は 0 件。うち 1 件は緩和ではなく文言アサーションの**追加** | ✅ | `git diff dedb71f..b01b90b -- tests/` |
| F | UI が 403 を期待している導線がないか確認し、壊れるなら同じタスクで直す | `resources/js/pages/Projects/Show.svelte:578` が `memberForm.errors.user_id` を表示しており、403 → field error への変更と整合。`Settings.svelte` の陳腐化コメントも同期 | ✅ | 同左 |
| G | 層 2 (404) は層 3 (403) より前 | `ProjectMemberController::store` は `resolveOrganizationProject()` (404) → `Gate::authorize()` (403) → payload 検証、の順を維持 | ✅ | `ProjectMemberController.php:41-47` |
| H | 範囲外: gate 本体 (`ModelDirectFetchInvariantTest` / `PrimaryKeyStaticQueryScanner`) を触らない | 触れているのは debtCap の値とコメントのみ | ✅ | `git show 4e8a44b --stat` |

### 偽グリーン検査 (mutation 実走) — **1 件エスケープ**

| ID | 変異 | 期待 | 実測 |
|---|---|---|---|
| A2 | `$organization->users()->whereKey()` を `User::query()->find()` へ戻す (クラス起点の直 fetch 再発) | 赤 | **赤** (`ModelDirectFetchInvariantTest` が `…#User.find:$userId#1` を列挙。debtCap 0 が機能) |
| C | MCP binder に「不在 org は 422」を再導入 | 赤 | **赤 2 件** (`ConsentOrganizationBinderTest` が 422≠403 と (status, message) 不一致を検出) |
| **A1** | **`OrganizationOwnershipController::store` の validation へ `exists:users,id` を再追加** | **赤** | **緑 (エスケープ)** |

A1 は `composer test -- --filter='PayloadIdExistenceOracleTest'` で 4/4 passed、
母集団を広げた `--filter='OwnershipTransfer|PayloadIdExistenceOracle|ProjectMember|ModelDirectFetch|MassAssignment'`
でも **40/40 passed**。**リポジトリ全体で 1 本も検出しない**。

### 根本原因 (実測で特定)

`PayloadIdExistenceOracleTest` の観測ヘルパが field error を**常に空**で返している。

```php
// tests/Feature/Security/PayloadIdExistenceOracleTest.php:41-48
$errors = session('errors');
return [
    'signature' => ResponseSignature::of($response),
    'user_id_errors' => $errors instanceof ViewErrorBag
        ? array_values($errors->getBag('default')->get('user_id'))
        : [],   // ← 実行時は常にこちら
];
```

本監査で一時 probe テストを流して `session('errors')` の実体を確認したところ、
**`ViewErrorBag` ではなく plain `array`** だった (`gettype()` === `'array'`)。中身自体は存在する:

```
{"errors":{"default":{"format":":message","messages":{"user_id":["移譲先は組織のメンバーである必要があります。"]}}},
 "sessionErrorsClass":"array"}
```

つまり `instanceof ViewErrorBag` の narrowing が成立せず、`user_id_errors` は
**どの入力に対しても `[]`** になる。結果として `pieoAssertNoOracle()`
(同ファイル `:78-83`) の比較は実質「302 の `ResponseSignature` 同士の比較」に退化する。
302 redirect の status / Location / body は validation メッセージが違っても同一なので、
**「不在 id はルール既定文言、実在の非メンバー id は org 相対解決の文言」という 1 bit の分岐を検出できない**。

これはブリーフが本タスクの核心と名指しした点そのものである:

> **fetch 側だけ直しても `exists:users,id` が同じ情報を漏らす。**
> validation rule の見直しとセットでなければ存在オラクルは閉じない。

実装は正しく rule を撤去しているので**現行 main に稼働中の存在オラクルは無い**が、
**再導入を止める機械ガードは存在しない**。同ファイルの
「文言まで固定する (rule 既定文言に分岐して戻らないことの回帰点)」というコメント (`:99`) が
主張する保証も、実際には成立していない (`assertSessionHasErrors` は実在の非メンバー側
1 パターンのみを固定しており、不在 id 側の文言は一度も比較されない)。

`projects.members.store` 側の 2 test も同じヘルパを使うため同じ弱点を持つ
(ただし実装前の 403 vs 302 の**ステータス差**は `ResponseSignature` が検出できていた =
TODO クローズ時の「実装前 failed 1 / errors 1」の記録と整合する)。

### 所見 (Critical 1 件)

**C-1**: 上記のとおり、T118 の受入証拠である `PayloadIdExistenceOracleTest` の
field-error 比較が実行時に無効化されており、`exists:` rule の再導入という
**本タスクが閉じたはずの劣化パターンを検出できない**。

修正方針 (監査は発見までが責務なので実装はしていない):
`session('errors')` を `ViewErrorBag` 前提で narrowing せず、
`TestResponse::assertSessionHasErrors` 相当のアクセス経路
(例: `session('errors')` を配列としても読める正規化、または
`$response->getSession()->get('errors')` の型に依存しない取り出し) に変え、
`array` 形状でも `user_id` の messages を取得できるようにする。
併せて「A1 変異を入れると赤くなる」ことを実走で確認すること。

---

## 4. AGENTS.md 不変条件・禁止事項の確認

| 項目 | 確認結果 |
|---|---|
| 層 2 (404) は層 3 (403) より前 | 維持。T118 は `ProjectMemberController` で 404 → 403 → payload の順を保っている。`OrganizationOwnershipController` の順序は変更していない |
| tenant キー不信 / cross-org 不可 | T118 はむしろ強化 (payload id を relation 起点へ)。`modelDirectFetchDebtCap()` = 0 |
| PHPStan の widen / baseline 化 | **なし**。`phpstan.neon` に差分なし、`@phpstan-ignore` の追加 0 件、level 10 / 793 files で `[OK] No errors` (本監査で再実行) |
| テストなしの実装完了報告 | なし。3 件とも Architecture / Feature テストへの登録まで含む |
| `response()->json()` 直書き / Prism 直呼び / prompt 直書き | 該当なし (差分に無し) |
| Artifact の使用 | 本監査でも未使用。成果物は本ファイル |
| dev DB への破壊操作 | 実行していない。`drop-test-db.php --apply` も未実行。`route:cache` → `route:clear` は DB 非接触で、実行後 `bootstrap/cache/` は `packages.php` / `services.php` のみに復帰済み |
| 検証コマンドの同期マーカー | `AGENTS.md` の `VERIFICATION_COMMANDS` マーカーは維持 |

## 5. 監査で自ら再実行した検証

| コマンド | 結果 |
|---|---|
| `composer phpstan` | level 10 / 793 files / `[OK] No errors` |
| `vendor/bin/pint --test` | `{"tool":"pint","result":"passed"}` |
| `composer test -- --filter='ThrottleCoverageInventoryTest\|RateLimiterKeyConventionTest\|ExternalFakeWiringInvariantTest\|FakeClassReferenceInvariantTest\|PayloadIdExistenceOracleTest\|ModelDirectFetchInvariantTest'` | 74 tests / 74 passed (baseline) |
| mutation 8 種 (A1 / A2 / C / D / E / F / G / H / I) | A1 のみエスケープ。他 8 種はすべて期待どおり赤化 |
| `php artisan route:cache` → 焼き込み検査 → `route:clear` | throttle の焼き込みを実測確認。cache は撤去済み |

`composer test` 全体はマージ担当が `b01b90b` で実走済み (3392 tests / 3390 passed / 2 skipped) のため
本監査では再実行せず、疑わしい箇所のみ名指しで流した (指示どおり)。
mutation 実施中に一時的に作成した probe テストと変異はすべて `git checkout` で復帰済みで、
監査終了時の `git status` はクリーン。

## 6. 残タスク (今回入らなかったもの)

### 本監査が新規に起票を推奨するもの

| # | 内容 | 優先度 |
|---|---|---|
| R-1 | `PayloadIdExistenceOracleTest` の field-error 観測を修復し、A1 変異 (`exists:users,id` 再導入) で赤化することを実走確認する | **高** (受入証拠の回復) |
| R-2 | 認証面の **GET 経路**を throttle 目録の母集団に含めるかの判断。少なくとも `social.callback` (未認証 + IdP への外向き HTTP) の扱いを決め、入れないなら理由を設計に残す。あわせて S3 パターンの死んだ `social\.` 条件を整理する | 中 |

### 実装側が既に後続 TODO 候補として明記済みのもの (黙って落ちてはいない)

- T119: 柱 2 (別プロセス観測による fake 配線の実測) / 柱 3(b) 起動時の実 env 二重判定
  — いずれも発火条件付きで `detailed-design.md:844-849`
- T120: B1 固定キー全体天井 + 署名済み identity による bucket 再設計 /
  B2 秘密を返す GET の recent-auth 化 / B3 Filament・Livewire 面の rate limit 契約 /
  B4 DCR・Passkey の後付けを `RouteThrottleBinder` へ統合 / B5 429 応答の経路別契約 (別 feature) /
  B6 家系 (`laravel-claude-template`) への還流 / B7 `RouteSecurityServiceProvider` への分離
  — `detailed-design.md:1026` の表
- T118: 直 fetch の債務以外の分類 31 件の見直し (ブリーフが明示的に範囲外)

## 7. 台帳 (c2c) と実コードの食い違い

本監査で新たに見つけた食い違いは無い。実装担当が設計に記録した訂正
(T119: 「実証も無い」→ Feature に 1 本ある / 「`ProductionEnvGuard` は配備前の層」→ 起動時にも走る、
T120: 「リミッタ不在」→ 10 本保有 (裁定 2026-08-06 で訂正済み)) は
本監査でも実コードで裏を取り、いずれも実装担当の記述が正しいことを確認した。
