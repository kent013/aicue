# Round 3: Round 2 指摘への対応

## 対応マトリクス

# 対応マトリクス: impl-review Round 2

## [Warning] `implements` 逆向き辺は「container binding の解決」ではなく「保守的な引き込み」

- 判断: **対応する (表現の訂正)**
- 根拠: 指摘のとおり。service provider の bind 宣言は 1 行も読んでいないので、
  「container binding 越しの到達を解決する」と書くのは**実装より強い保証を謳う**ことになる。
  「保証しないもの」に穴を書いていても、名前と説明が誤っていれば読み手は誤解する。
- 対応内容:
  - `deletionPathImplementedInterfaces()` の docblock に
    「**これは container binding を解決しているのではない**。bind 宣言は一切読まず、
    閉包に入った interface の app/ 内の全実装クラスを保守的に引き込むだけである
    (未登録・未使用・別用途の実装も入る)」を明記した。
  - fixture 8 形目のテスト名を
    「interface 経由の container binding 越しの到達を閉包へ引き込む」→
    **「閉包に入った interface の実装クラスを保守的に引き込む」**へ変更し、コメントも合わせた。
  - 複数実装 interface で信号が弱まる危険については [Suggestion] の対応で可視化した。

## [Warning] `implements` 解析が「1 ファイル = パス由来の型 1 つ」を暗黙に前提している

- 判断: **対応する (文章での断りではなく機械検査にする)**
- 根拠: 妥当。前提が崩れると implementor が別クラスへ帰属して**静かに落ちる** (fail-open)。
  「保証しない条件へ追記」でも要求は満たせるが、**実測できる前提を散文に落とすのは弱い**
  (本リポジトリの gate 書式は前提そのものを pin する)。
- 対応内容: 走査結果に `declared` (ファイルが宣言する名前付き型) を追加し、
  **app/ の全ファイルで「宣言された型 == パス由来の FQCN」を実測して 0 件に pin** した
  (検査 4 の `misdeclared`)。匿名クラスは `class=null` なので母集団から自然に外れる。
  これで前提は文章ではなく機械が守る。

## [Warning] mutation 被覆表の M1 記述が実測と一致していない / 新設検出点の mutation が未提示

- 判断: **対応する**
- 根拠: そのとおり。M1 は実測で緑だったのに「赤くなる」と書いたままで、
  これは「誇張しない記述」に反する。また新設した検出点 5 つは
  「壊すと赤くなること」を実測していなかった (禁止事項 1 に抵触)。
- 対応内容:
  - `DELETION_PATH_MUTATION_COVERAGE` の M1 を実測に合わせて書き直し、
    「設計どおりの deleteAccount を外す形は閉包が変わらず緑」であることをコメントで明示した。
  - **M4〜M8 を追加し、5 本すべて実測した** (実測値は `mutation-evidence.md`):

    | ID | 変異 | 赤くなったテスト |
    |---|---|---|
    | M4 | allowlist へ `charge` を追加 | 検査 7 + 負のコントロール 7 形目 (**2 本**) |
    | M5 | 起点を 1 つ削除 | 検査 8 |
    | M6 | 記録コマンドへ `Cashier::stripe()` | 検査 9 |
    | M7 | literal 動的メソッド名の検出を殺す | 負のコントロール 7 形目 |
    | M8 | implementors の辺を外す | 負のコントロール 8 形目 |

  - M6 では Feature テストの `StripeGatewayInterface` mock は**緑のまま**であることも確認した
    (静的検査が要る理由の実測)。
  - 全 mutation は 1 本ずつ適用 → 実測 → 復元し、`git status --short` で残留 0 を確認した。

## [Suggestion] 閉包に入った interface ごとの implementor 数を失敗出力に含める

- 判断: **対応する**
- 根拠: 安価で、逆向き辺が信号を弱め始めたことをレビュー時点で気づける。
- 対応内容: 検査 1 の失敗メッセージに
  「`{interface}` の実装 N 件が逆向きの辺で閉包に入っています」を付ける実装を入れた
  (現時点では閉包に App の interface が無いため出力は空)。

## [Suggestion] architecture.md は競合解消後に 1 つの記述へ統合するのが望ましい

- 判断: **見送る (本 PR では現状維持)**
- 根拠: Codex 自身が「並列作業との衝突回避が現実の制約なら本 PR では許容範囲」「ブロッカーにしない」と
  述べている。本タスクは並列 2 件と同時進行で、共有ファイルの既存行書き換えは衝突源になる。
  統合は競合が解消した後の作業として妥当であり、本 PR のスコープ (PR-A) ではない。

## [Suggestion] `--customer=` の見送りは妥当 (Round 2 で追認)

- 判断: **対応不要** (Round 1 の判断を Codex が追認)


## mutation 実測記録 (更新後の全文)

# T141 (PR-A) mutation evidence — 「壊すと赤くなること」の実測

> 詳細設計 `devnotes/20260809-0908-account-deletion-grace/detailed-design.md` §共通: mutation で赤化を確認する手順。
> **実装完了の条件は「テストが緑」ではなく「壊すと赤くなることを実測した」**である。
> PR-A に該当する mutation は **M1 / M2 / M3 / M24** の 4 本。
> 各 mutation は 1 つずつ適用 → 実測 → 戻す、を行い、最後に `git status --short` で残留 0 を確認した。

実行コマンド: `composer test -- <対象テストファイル>` (グローバルテストロック配下)。

---

## M1 (設計どおりでは**赤くならなかった**。設計の予測と実測がずれた例)

| 項目 | 内容 |
|---|---|
| 変異 | `AccountDeletionPathGateTest` の `DELETION_PATH_ROOTS` から `OrganizationMembershipService::deleteAccount` を外す |
| 設計の予測 | 空振り検知 (閉包サイズ floor) が赤くなる |
| **実測** | **16 tests / 16 passed = 緑のまま** |

**なぜずれたか (辻褄を合わせずに記録する)**:
`AccountController::destroy` は `OrganizationMembershipService` を**引数の型宣言で受け取る**。
閉包はクラス粒度で辿るので、`deleteAccount` を起点から外しても
`AccountController` 経由で `OrganizationMembershipService` に到達し、**閉包が 1 件も変わらない**。
すなわち設計が想定した「起点を 1 つ外せば閉包が縮む」という前提が、この 2 起点の関係
(片方がもう片方を型で参照している) では成立しない。

これは gate の欠陥ではなく **mutation の設計ミス**である。閉包の到達判定が生きていることは
M1' が示す。なお PR-B で 3 つ目の起点 (`PurgeDeletionRequestsCommand::handle`) を足すときは、
その起点が他の 2 起点から到達不能なら M1 相当が成立する (足した側で再確認すること)。

## M1' (M1 の代替。実際に赤くなる形へ置き換えて実測)

| 項目 | 内容 |
|---|---|
| 変異 | `DELETION_PATH_ROOTS` から `AccountController::destroy` を外す (他方から到達不能な起点を外す) |
| 赤くなったテスト | **検査 1: 退会経路の依存閉包は目録と exact-fit で一致する** |
| 実測 | 16 tests / 15 passed / **1 failed** |

失敗メッセージ (要点):

```
残骸 => [
  'App\Http\Controllers\Controller',
  'App\Http\Controllers\Settings\AccountController',
]
```

---

## M2

| 項目 | 内容 |
|---|---|
| 変異 | `OrganizationMembershipService` に `private ?\Stripe\StripeClient $mutationProbe = null;` を追加 (**型宣言だけ。呼び出しは 1 つも書かない**) |
| 赤くなったテスト | **検査 2: 閉包内のどのクラスも決済事業者記号を参照しない** |
| 実測 | 16 tests / 15 passed / **1 failed** |

```
App\Services\Organization\OrganizationMembershipService :
  app/Services/Organization/OrganizationMembershipService.php:39 name Stripe\StripeClient
```

これが本 gate の存在理由そのものである。behavioral 2 本
(`AccountDeletionTest`) はこの変異では**緑のまま**である
(型注入しただけで呼ばれていないため、実行時には観測されない)。

## M3

| 項目 | 内容 |
|---|---|
| 変異 | `deleteAccount` の中に `$probe = app('cashier.stripe');` を追加 (container の literal 解決) |
| 赤くなったテスト | **検査 2: 閉包内のどのクラスも決済事業者記号を参照しない** |
| 実測 | 16 tests / 15 passed / **1 failed** |

```
App\Services\Organization\OrganizationMembershipService :
  app/Services/Organization/OrganizationMembershipService.php container literal cashier.stripe
```

## M24

| 項目 | 内容 |
|---|---|
| 変異 | redaction 記録 migration から CHECK 制約 (`organizations_stripe_customer_redaction_pair_check`) の `DB::statement` を削除 |
| 赤くなったテスト | **片列だけの UPDATE は DB の CHECK 制約が拒否する (アプリ層を迂回しても守られる)** |
| 実測 | 8 tests / 7 passed / **1 failed** — `Exception "Illuminate\Database\QueryException" not thrown.` |

---

# impl-review Round 1 の修正で**新設した検出点**の mutation (Round 2 [Warning] 対応)

Round 1 の指摘対応で入れた検出点 (検査 7 / 8 / 9 / literal 動的メソッド名 / implements 逆向き辺) は
設計の mutation 表に無い。「不変条件は壊すと赤くなることまで確認して初めて実装済み」に従い、
**5 本を追加で実測した**。

## M4

| 項目 | 内容 |
|---|---|
| 変異 | `DELETION_PATH_CASHIER_LOCAL_METHODS` へ `'charge' => '…'` を追加 (検出面を狭める改変) |
| 赤くなったテスト | **検査 7 (allowlist の exact-fit pin)** + **負のコントロール 7 形目** (`->{'charge'}()` が検出されなくなる) |
| 実測 | 21 tests / 19 passed / **2 failed** — `Failed asserting that an array has the key 'charge'` |

2 本落ちたことに意味がある: exact-fit pin だけでなく、**検出面が実際に狭まったこと**も
fixture が独立に捕まえている。

## M5

| 項目 | 内容 |
|---|---|
| 変異 | `DELETION_PATH_ROOTS` から `OrganizationMembershipService::deleteAccount` を外す (= 設計の M1 と同じ変異) |
| 赤くなったテスト | **検査 8 (起点集合の exact-fit pin)** |
| 実測 | 21 tests / 20 passed / **1 failed** |

**設計の M1 が緑だった穴は、これで塞がった**。閉包が変わらない起点削除でも起点 pin が赤くなる。

## M6

| 項目 | 内容 |
|---|---|
| 変異 | `MarkStripeCustomerRedactedCommand::handle` に `\Laravel\Cashier\Cashier::stripe();` を追加 |
| 赤くなったテスト | **検査 9 (redaction 記録コマンドは決済事業者記号を参照しない)** |
| 実測 | 21 tests / 20 passed / **1 failed** (symbol `Laravel\Cashier\Cashier` と `Laravel\Cashier\Cashier::stripe()` を検出) |

Feature テストの `StripeGatewayInterface` mock は**この変異では緑のまま**である
(Cashier facade は mock を経由しない)。静的検査が要る理由そのもの。

## M7

| 項目 | 内容 |
|---|---|
| 変異 | `deletionPathLiteralDynamicCalls()` の収集直前に `continue;` を挿入し検出を殺す |
| 赤くなったテスト | **負のコントロール 7 形目 (literal の動的メソッド名)** |
| 実測 | 21 tests / 20 passed / **1 failed** (`['->stripe()', '->charge()']` → `[]`) |

## M8

| 項目 | 内容 |
|---|---|
| 変異 | `deletionPathTraverse()` から implementors の逆向き辺を外す |
| 赤くなったテスト | **負のコントロール 8 形目 (interface の実装クラスを保守的に引き込む)** |
| 実測 | 21 tests / 20 passed / **1 failed** |

---

## 実装中に mutation とは別に発見した fail-open (修正済み)

`tests/Support/PhpReferenceScanner` の alias マップ (`ReferenceScanResult::$imports`) は、
**クラス本体の `use SomeTrait;` を先頭の `use App\...\SomeTrait;` と同じ短縮キーで上書きする**。
結果として alias マップの値が短縮名 (`'SomeTrait'`) に潰れ、**FQCN が失われる**。

閉包の到達辺を alias マップから取ると **trait 経由の到達が丸ごと消える (fail-open)**。
本 gate は辺を**正規化トークン列の修飾名トークンから直接**取ることでこれを回避しており、
`負のコントロール 5 形目 (b)` が「alias マップは潰れている / それでも辺は残る」を両方 pin している。

**走査器そのものは変更していない** (他 gate の振る舞い保存のため)。この非対称は
`ExternalSeamInventoryTest` / `ExternalClientTimeoutInventoryTest` にも同じ形で存在しうるが、
両目録は「決済 / facade / client 構築」の **site** を見ており trait 到達を辺に使っていないため、
本件で挙動が変わる箇所は無い (実測でも app/ の閉包メンバーは 1 件も増減しなかった)。

---

## 後始末

全 mutation を戻した後、`git diff` に mutation の残留が無いことを確認済み
(`git status --short` の差分は本 PR の実装ファイルのみ)。


## 修正後の gate 全文差分 (Round 2 以降に変更したのはこのファイルのみ)

```diff
diff --git a/tests/Architecture/AccountDeletionPathGateTest.php b/tests/Architecture/AccountDeletionPathGateTest.php
new file mode 100644
index 0000000..bd1bafc
--- /dev/null
+++ b/tests/Architecture/AccountDeletionPathGateTest.php
@@ -0,0 +1,1274 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Security\DeletionPathSeamExemption;
+use Laravel\Cashier\Billable;
+use Laravel\Cashier\Subscription;
+use Tests\Support\PhpReferenceScanner;
+use Tests\Support\ReferenceKind;
+use Tests\Support\ReferenceScanResult;
+use Tests\Support\ReferenceSite;
+use Tests\Support\ScanScopeKind;
+use Webmozart\Assert\Assert;
+
+/*
+ * Architecture invariant: **退会 (アカウント削除) 経路の依存閉包から決済事業者 SDK へ到達しない**。
+ *
+ * SoT = lctl 台帳 feature `account-deletion-billing-guard` の標準形 v1 (裁定 AG-128) と
+ * docs/architecture.md §退会 (アカウント削除) の課金ガード (T115)。
+ *
+ * ★なぜ静的検査か (behavioral では捕まらない):
+ *   既存の tests/Feature/Auth/AccountDeletionTest.php の 2 本
+ *   (「退会成功経路では決済事業者 API を呼ばない」「課金中でブロックされる経路でも呼ばない」) は
+ *   **その経路で今日呼ばれなかった**ことしか言えない。新しい依存を注入した瞬間に沈黙する
+ *   (注入しただけで呼ばれない依存は behavioral では観測できず、次の変更で呼ばれた時に初めて壊れる)。
+ *   laravel-claude-template では実際に「依存閉包の抽出が**型宣言だけの注入**を素通りさせていた」
+ *   fail-open が実装レビューで見つかっている。よって静的 gate と behavioral 2 本は**並存**させる
+ *   (behavioral 側は 1 行も変更しない)。
+ *
+ * ★この gate が保証するもの:
+ *   - 検査 1: 起点から辿れる app/ 内クラスの閉包が目録 (DELETION_PATH_CLOSURE) と exact-fit
+ *   - 検査 2: 閉包内のどのクラスも決済事業者記号を参照しない
+ *     (Stripe\* / Laravel\Cashier\Cashier / Cashier Billable・Subscription の API メソッド名 /
+ *      名前に stripe を含む呼び出し / 決済 binding の container literal)
+ *   - 検査 3: 免除は DeletionPathSeamExemption (型付き enum) + 30 文字以上の根拠のみ。現在 0 本ちょうど
+ *   - 検査 4: 空振り検知 (走査ファイル数 / 解決できた到達辺 / 閉包サイズが 0 でない・閉包が実在クラス)
+ *   - 検査 5: 自己参照コントロール (本ファイル自身を走査して記号 hit 0 件・辺は exact-fit)
+ *   - 検査 6: 閉包内に**動的メソッド名の呼び出しが 0 件** (`->{$m}()` / `::$m()` は名前が字句的に
+ *     確定せず記号照合を迂回できるため、閉包内では deny-by-default で 0 件に pin する)
+ *   - 検査 7: Cashier API 名の導出が生きている (ローカル判定 allowlist は **exact-fit の二重宣言**
+ *     + 30 文字以上の根拠つき。allowlist へ足すことは検出面を狭めることと同義なので摩擦を置く)
+ *   - 検査 8: 起点集合の exact-fit pin (起点を静かに減らせない)
+ *   - 検査 9: redaction 記録コマンドが決済事業者記号を持たない (記録専用であることの静的固定)
+ *   - 正負 fixture: 型注入のみ / facade / static call / app()・resolve()・make() の literal 引数 /
+ *     trait 経由 (+ alias 上書き) / 動的メソッド名 / literal 動的メソッド名 /
+ *     interface 経由の container binding / 基底クラス継承を辿らないこと / コメント・文字列の非誤検出
+ *
+ * ★この gate が保証しないもの (誇張しない):
+ *   - **文字列キーが変数の container 解決** (`$c->make($name)`)。受け手を解決できない
+ *   - **vendor 内部から出る通信**。Cashier の WebhookController / Billable の内部実装は閉包の外
+ *   - **完全修飾 docblock だけで型宣言も import も無い受け手** (docblock 解析はしない)
+ *   - **実行時 config による bind 差し替え** (静的走査は bind 先を知らない)
+ *   - **container binding のうち `implements` 関係で表せないもの**。閉包は interface の
+ *     実装クラスを逆向きに引き込むが、**abstract 基底クラスへの bind / closure binding /
+ *     contextual binding / 別名文字列 bind** は辿らない (`extends` を逆向きに辿ると
+ *     `AccountController extends Controller` から app/ の全 Controller が入り信号が死ぬため、
+ *     意図的に `implements` だけに限定している)
+ *   - **`use Billable;` のような trait 取り込み**そのもの。PhpReferenceScanner はクラス本体の
+ *     `use` を import として扱い site を出さないため、trait 名は記号照合に載らない
+ *     (帰結として Cashier の**構造的な取り込み**は検出せず、**呼び出し**だけを見る)
+ *   - **`Laravel\Cashier\` 名前空間の型参照そのもの** (`Subscription extends CashierSubscription` /
+ *     `use Billable;`) は記号にしない。接頭辞走査は値オブジェクト・例外・モデル継承を巻き込んで
+ *     信号を殺すため (ExternalSeamScanner が同じ理由で接頭辞走査を禁じている)
+ *   - **メソッド呼び出しの受け手は解決しない**。決済 API 名の照合は名前だけで行うため、
+ *     同名の無関係な呼び出しは偽陽性になりうる (fail-closed 側に倒している)
+ *   - **これは検知であって遮断ではない**。実行時の外部通信を止める機構ではない
+ *
+ * ★閉包の粒度は**クラス**である (起点は method 名で指すが、閉包はクラス単位で辿る)。
+ *   同一クラス内の private メソッド経由の到達 (`deleteAccount` → `$this->organizationsBlockingDeletion()`)
+ *   を落とさないための意図的な過大近似 = fail-closed。method 粒度にすると
+ *   「private メソッドへ移せば gate を迂回できる」抜け道ができる。
+ *
+ * 解析は Tests\Support\PhpReferenceScanner に乗せる (namespace 解決 / alias / scope 追跡を
+ * ExternalSeamInventoryTest / ExternalClientTimeoutInventoryTest と共有する。自前の走査器を作らない)。
+ * 走査は正規化済みトークン列に対して行うため、**この説明コメント自身では偽赤にならない** (検査 5)。
+ * DB 不使用 (Architecture lane は TestCase のみ)。
+ */
+
+/**
+ * 退会経路の起点 (`FQCN::method`)。
+ *
+ * ★**PR-A の時点では 2 つ**である。PR-B (猶予期間つき削除) で日次執行バッチ
+ *   `App\Console\Commands\Account\PurgeDeletionRequestsCommand::handle` を 3 つ目として足す。
+ *
+ * @var list<string>
+ */
+const DELETION_PATH_ROOTS = [
+    'App\Http\Controllers\Settings\AccountController::destroy',
+    'App\Services\Organization\OrganizationMembershipService::deleteAccount',
+];
+
+/**
+ * 起点から辿れる app/ 内クラスの閉包 (exact-fit の目録)。
+ *
+ * ★増減はどちらも赤くする。増えたら「退会経路の依存が広がった」ことのレビューを、
+ *   減ったら「走査が壊れた / 起点が外れた」ことの検出を意図している。
+ *
+ * @var list<string>
+ */
+const DELETION_PATH_CLOSURE = [
+    'App\DataTransferObjects\Invitations\PendingInvitationForUserDto',
+    'App\DataTransferObjects\Notification\InvitationReceivedPayload',
+    'App\DataTransferObjects\Notification\ManualJobPayload',
+    'App\DataTransferObjects\Notification\TicketBalanceLowPayload',
+    'App\DataTransferObjects\Organizations\AccountDeletionBlockerDto',
+    'App\Enums\AccountDeletionBlockReason',
+    'App\Enums\AccountDeletionBlockerAction',
+    'App\Enums\AdminConsoleRole',
+    'App\Enums\Billing\PlanPriceKind',
+    'App\Enums\Billing\ScheduleSetupStatus',
+    'App\Enums\Billing\SubscriptionState',
+    'App\Enums\Billing\TicketLedgerKind',
+    'App\Enums\Billing\TicketReservationStatus',
+    'App\Enums\Billing\TicketSource',
+    'App\Enums\CheckoutIntent',
+    'App\Enums\CheckoutSessionStatus',
+    'App\Enums\Manual\AnalysisStep',
+    'App\Enums\Manual\JobStatus',
+    'App\Enums\Manual\RenderErrorCode',
+    'App\Enums\Manual\RenderKind',
+    'App\Enums\Manual\RenderStep',
+    'App\Enums\Manual\VideoManualStatus',
+    'App\Enums\Notification\NotificationType',
+    'App\Enums\OrganizationRole',
+    'App\Enums\ProjectRole',
+    'App\Enums\SecurityEventType',
+    'App\Enums\TwoFactorStatus',
+    'App\Http\Controllers\Controller',
+    'App\Http\Controllers\Settings\AccountController',
+    'App\Models\AnalysisJob',
+    'App\Models\Billing\BillingCheckoutSession',
+    'App\Models\Billing\OrganizationQuota',
+    'App\Models\Billing\Plan',
+    'App\Models\Billing\Subscription',
+    'App\Models\Billing\TicketLedgerEntry',
+    'App\Models\Billing\TicketReservation',
+    'App\Models\Organization',
+    'App\Models\OrganizationInvitation',
+    'App\Models\Project',
+    'App\Models\RenderJob',
+    'App\Models\SecurityAuditEvent',
+    'App\Models\User',
+    'App\Models\VideoManual',
+    'App\Notifications\InApp\InvitationReceivedNotification',
+    'App\Notifications\InApp\ManualAnalyzedNotification',
+    'App\Notifications\InApp\ManualRenderedNotification',
+    'App\Notifications\InApp\TicketBalanceLowNotification',
+    'App\Notifications\OrganizationInvitationNotification',
+    'App\Services\Billing\AccountDeletionBillingGuard',
+    'App\Services\Notification\NotificationCenterService',
+    'App\Services\Organization\OrganizationMembershipService',
+    'App\Services\Project\DefaultProjectResolver',
+    'App\Services\Security\SecurityEventRecorder',
+];
+
+/**
+ * Cashier の API 表面から**除外**するメソッド名 (小文字) => ローカル処理である根拠。
+ *
+ * ★決済 API 名の集合は `Laravel\Cashier\Billable` / `Laravel\Cashier\Subscription` の
+ *   public メソッドから**リフレクションで導出**する (Cashier が API を増やしたら自動で
+ *   母集団に入る = fail-closed)。ここに載せた名前だけがその母集団から外れる。
+ * ★走査は受け手を解決しない (`PhpReferenceScanner` の MethodCall は名前だけ)。よって
+ *   ここに載るのは「Cashier と同名だが実際には決済到達でない呼び出し」の allowlist である。
+ * ★`stripe` を含む名前は載せられない (検査 7 が拒否する)。
+ *
+ * @var array<string, string>
+ */
+const DELETION_PATH_CASHIER_LOCAL_METHODS = [
+    'subscriptions' => 'Billable が生やす subscriptions リレーションの取得。AccountDeletionBillingGuard は '
+        .'ローカル subscriptions 行を読むだけで決済事業者 API を呼ばない (T115 の設計そのもの)',
+    'active' => 'OrganizationInvitation の「有効な招待か」を判定するローカル述語。Cashier Subscription の '
+        .'同名メソッドとは無関係で、招待テーブルの列 (accepted_at / expires_at) だけを見る',
+    'user' => 'Request::user() / SecurityEventRecorder の actor 取得。Cashier Subscription の owner 取得と '
+        .'同名なだけで、認証済み actor をローカルに読むだけの呼び出しである',
+];
+
+/**
+ * 決済 binding とみなす container literal (小文字で部分一致)。
+ *
+ * `app('cashier.stripe')` のように **文字列キーで client を取り出す**形を捕まえる。
+ *
+ * @var list<string>
+ */
+const DELETION_PATH_CONTAINER_LITERAL_MARKERS = ['stripe', 'cashier'];
+
+/**
+ * 免除 (case value => 30 文字以上の根拠)。**現在 0 件ちょうど**。
+ *
+ * @var array<string, string>
+ */
+const DELETION_PATH_SEAM_EXEMPTION_RATIONALES = [];
+
+/**
+ * mutation 被覆表 (設計 §共通/mutation の本 gate 該当分)。
+ *
+ * @var array<string, string>
+ */
+const DELETION_PATH_MUTATION_COVERAGE = [
+    // ★M1 は**設計の予測が外れた**。設計は「deleteAccount を起点から外すと閉包が縮む」と
+    //   書いていたが、AccountController::destroy が OrganizationMembershipService を
+    //   型宣言で受けるため閉包は 1 件も変わらず**緑のまま**だった。実測は
+    //   devnotes/20260810-1004-todo-T141/mutation-evidence.md に記録してある。
+    //   起点を減らす改変は検査 8 (起点の exact-fit pin) が捕まえる。
+    'M1' => '起点から AccountController::destroy を外すと閉包が縮み検査 1 と検査 8 が赤くなる'
+        .' (設計どおりの deleteAccount を外す形は閉包が変わらず緑。実測を記録済み)',
+    'M2' => 'OrganizationMembershipService へ Stripe\StripeClient を型注入するだけの property を足すと検査 2 が赤くなる',
+    'M3' => '同じ注入を app(\'cashier.stripe\') の literal 呼び出しで書くと検査 2 が赤くなる',
+    'M4' => 'DELETION_PATH_CASHIER_LOCAL_METHODS へ charge を足すと検査 7 の allowlist exact-fit pin が赤くなる',
+    'M5' => '起点を 1 つ削ると検査 8 の exact-fit pin が赤くなる',
+    'M6' => 'redaction 記録コマンドへ Cashier::stripe() を書くと検査 9 が赤くなる',
+    'M7' => 'literal 動的メソッド名の検出を殺すと fixture 7 形目が赤くなる',
+    'M8' => 'deletionPathTraverse() から implementors の辺を外すと fixture 8 形目が赤くなる',
+];
+
+/** @var list<string> */
+const DELETION_PATH_MUTATION_IDS = ['M1', 'M2', 'M3', 'M4', 'M5', 'M6', 'M7', 'M8'];
+
+/**
+ * app/ 配下 1 ファイルぶんの走査結果。
+ *
+ * @return array{
+ *     class: string,
+ *     declared: list<string>,
+ *     edges: list<string>,
+ *     implements: list<string>,
+ *     payment: list<array{symbol: string, descriptor: string}>,
+ *     dynamic: list<string>,
+ * }
+ */
+function deletionPathScanSource(string $relativePath, string $source): array
+{
+    $result = PhpReferenceScanner::references($relativePath, $source);
+    $tokens = PhpReferenceScanner::tokens($source);
+
+    $class = deletionPathClassFromPath($relativePath);
+    $edges = deletionPathEdges($result, $tokens);
+    $payment = deletionPathPaymentHits($relativePath, $result, $tokens);
+    $dynamic = deletionPathDynamicCallSites($relativePath, $tokens);
+
+    // container literal は到達辺にもなる (`app(App\Foo::class)` は NameReference で拾えるが、
+    // `app('App\Foo')` は文字列なので site を出さない)。
+    foreach (deletionPathContainerLiterals($tokens) as $literal) {
+        if (str_starts_with($literal, 'App\\')) {
+            $edges[] = $literal;
+        }
+    }
+
+    // ファイルが宣言している名前付き型 (匿名クラスは class=null なので自然に外れる)。
+    // PSR-4 導出 (`$class`) が実態と一致していることを検査 4 が機械的に確かめるために使う。
+    $declared = [];
+    foreach ($result->sites as $site) {
+        if ($site->scopeKind === ScanScopeKind::NamedClass && $site->class !== null) {
+            $declared[$site->class] = true;
+        }
+    }
+
+    return [
+        'class' => $class,
+        'declared' => array_keys($declared),
+        'edges' => array_values(array_unique($edges)),
+        'implements' => deletionPathImplementedInterfaces($result, $tokens),
+        'payment' => $payment,
+        'dynamic' => $dynamic,
+    ];
+}
+
+/**
+ * このファイルが `implements` している interface の FQCN。
+ *
+ * ★**なぜ必要か**: 退会経路が `App\Contracts\Foo` を型注入し、service provider が
+ *   concrete 実装へ bind している場合、型宣言の辺だけでは interface で止まり
+ *   **実装クラス側の決済事業者記号に到達しない** (impl-review Round 1 [Critical])。
+ * ★**これは container binding を解決しているのではない**。service provider の bind 宣言は
+ *   一切読まず、「閉包に入った interface の **app/ 内の全実装クラスを保守的に引き込む**」
+ *   だけである (過大近似 = fail-closed。未登録・未使用・別用途の実装も入る)。
+ *   複数実装を持つ interface が退会経路へ入ると閉包が膨らんで信号が弱くなりうるので、
+ *   検査 1 の失敗出力に interface ごとの実装数を出してレビューで気づけるようにしてある。
+ * ★**`extends` は辺にしない**。`AccountController extends Controller` の基底クラスを
+ *   逆向きに辿ると **app/ の全 Controller** が閉包に入り信号が死ぬ。基底クラスの継承は
+ *   container binding の代替ではないため対象外とする (残る穴は冒頭の「保証しないもの」に明記)。
+ *
+ * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+ * @return list<string>
+ */
+function deletionPathImplementedInterfaces(ReferenceScanResult $result, array $tokens): array
+{
+    /** @var array<int, ReferenceSite> $byTokenIndex */
+    $byTokenIndex = [];
+    foreach ($result->sites as $site) {
+        $byTokenIndex[$site->tokenIndex] = $site;
+    }
+
+    $names = [];
+    $count = count($tokens);
+
+    for ($i = 0; $i < $count; $i++) {
+        if ($tokens[$i]['id'] !== T_IMPLEMENTS) {
+            continue;
+        }
+        for ($j = $i + 1; $j < $count; $j++) {
+            $token = $tokens[$j];
+            if ($token['id'] === null && ($token['text'] === '{' || $token['text'] === ';')) {
+                break;
+            }
+            if ($token['id'] === T_NAME_QUALIFIED || $token['id'] === T_NAME_FULLY_QUALIFIED) {
+                $names[] = ltrim($token['text'], '\\');
+
+                continue;
+            }
+            // 短縮名は alias 解決済みの site から引く (`use App\Contracts\Foo;` + `implements Foo`)。
+            $site = $byTokenIndex[$j] ?? null;
+            if ($site !== null && $site->kind === ReferenceKind::NameReference) {
+                $names[] = $site->name;
+            }
+        }
+    }
+
+    return array_values(array_unique(array_filter(
+        $names,
+        static fn (string $name): bool => str_starts_with($name, 'App\\'),
+    )));
+}
+
+/**
+ * PSR-4 (`app/` => `App\`) でファイルパスからクラス FQCN を導く。
+ */
+function deletionPathClassFromPath(string $relativePath): string
+{
+    $withoutExtension = preg_replace('/\.php$/', '', $relativePath);
+    Assert::string($withoutExtension);
+
+    return str_replace('/', '\\', 'App'.substr($withoutExtension, strlen('app')));
+}
+
+/**
+ * 到達辺 (`App\` で始まる参照先 FQCN)。
+ *
+ * 型宣言 / `new` / `::class` / instanceof は NameReference・Construction として、
+ * 静的呼び出しの受け手は StaticCall の receiver として拾う。
+ * import を辺に数えるのは意図的な過大近似 = fail-closed (使われていない import も辺にする)。
+ *
+ * ★**import は alias マップ (`ReferenceScanResult::$imports`) から取らず、正規化トークン列の
+ *   修飾名トークンから直接取る**。`PhpReferenceScanner` はクラス本体の `use SomeTrait;` も
+ *   `use` 文として処理するため、**同名の短縮キーで先頭の import を上書きし FQCN を失う**
+ *   (`use App\Models\Concerns\Foo;` + `use Foo;` → alias マップは `foo => 'Foo'`)。
+ *   alias マップだけを見ると **trait 経由の到達辺が丸ごと消える** = fail-open になる
+ *   (実測: 本 gate の fixture 5 形目で発覚)。トークンを直接見れば上書きの影響を受けない。
+ *
+ * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+ * @return list<string>
+ */
+function deletionPathEdges(ReferenceScanResult $result, array $tokens): array
+{
+    $names = [];
+    foreach ($result->sites as $site) {
+        if ($site->kind === ReferenceKind::NameReference || $site->kind === ReferenceKind::Construction) {
+            $names[] = $site->name;
+        }
+        if ($site->kind === ReferenceKind::StaticCall && $site->receiver !== null) {
+            $names[] = $site->receiver;
+        }
+    }
+    foreach ($tokens as $token) {
+        if ($token['id'] === T_NAME_QUALIFIED || $token['id'] === T_NAME_FULLY_QUALIFIED) {
+            $names[] = ltrim($token['text'], '\\');
+        }
+    }
+
+    return array_values(array_unique(array_filter(
+        $names,
+        static fn (string $name): bool => str_starts_with($name, 'App\\'),
+    )));
+}
+
+/**
+ * 決済事業者記号の hit。
+ *
+ * ★`symbol` は**行番号を含まない安定キー**である (免除 `DeletionPathSeamExemption` の value は
+ *   `{クラス FQCN}#{symbol}` で書くため。行番号を含めると免除が行移動で壊れる)。
+ *   `descriptor` は失敗メッセージ用に path:line を含む。
+ *
+ * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+ * @return list<array{symbol: string, descriptor: string}>
+ */
+function deletionPathPaymentHits(string $relativePath, ReferenceScanResult $result, array $tokens): array
+{
+    $apiMethods = deletionPathPaymentApiMethods();
+    /** @var array<string, array{symbol: string, descriptor: string}> $hits 重複排除キー => hit */
+    $hits = [];
+
+    foreach ($result->sites as $site) {
+        $symbol = deletionPathClassifySite($site, $apiMethods);
+        if ($symbol !== null) {
+            $hits[$symbol.'@'.$site->line] = [
+                'symbol' => $symbol,
+                'descriptor' => $relativePath.':'.$site->line.' '.$symbol,
+            ];
+        }
+    }
+
+    // import だけを持ち site を出さないファイル (`use Stripe\StripeClient;` のみ) も拾う。
+    // alias マップではなくトークンを見る (辺の収集と同じ理由。上書きの影響を受けない)。
+    foreach ($tokens as $token) {
+        if ($token['id'] !== T_NAME_QUALIFIED && $token['id'] !== T_NAME_FULLY_QUALIFIED) {
+            continue;
+        }
+        $name = ltrim($token['text'], '\\');
+        if (deletionPathIsPaymentNamespace($name)) {
+            $hits[$name.'@name'] ??= [
+                'symbol' => $name,
+                'descriptor' => $relativePath.':'.$token['line'].' name '.$name,
+            ];
+        }
+    }
+
+    // literal の動的メソッド名 (`->{'stripe'}()`)。名前が字句的に確定するので
+    // **動的扱いにせず通常の呼び出しと同じ規則で分類する** (impl-review Round 1 [Warning])。
+    foreach (deletionPathLiteralDynamicCalls($tokens) as $call) {
+        $symbol = deletionPathClassifyMethodName($call['name'], $apiMethods);
+        if ($symbol !== null) {
+            $hits[$symbol.'@'.$call['line']] = [
+                'symbol' => $symbol,
+                'descriptor' => $relativePath.':'.$call['line'].' '.$symbol.' (literal 動的メソッド名)',
+            ];
+        }
+    }
+
+    foreach (deletionPathContainerLiterals($tokens) as $literal) {
+        $lower = mb_strtolower($literal);
+        foreach (DELETION_PATH_CONTAINER_LITERAL_MARKERS as $marker) {
+            if (str_contains($lower, $marker)) {
+                $symbol = 'container:'.$literal;
+                $hits[$symbol] = [
+                    'symbol' => $symbol,
+                    'descriptor' => $relativePath.' container literal '.$literal,
+                ];
+
+                break;
+            }
+        }
+    }
+
+    return array_values($hits);
+}
+
+/**
+ * site 1 件が決済事業者記号かを判定し**安定 symbol** を返す (該当しなければ null)。
+ *
+ * @param  array<string, string>  $apiMethods  小文字メソッド名 => 正規表記
+ */
+function deletionPathClassifySite(ReferenceSite $site, array $apiMethods): ?string
+{
+    if (($site->kind === ReferenceKind::NameReference || $site->kind === ReferenceKind::Construction)
+        && deletionPathIsPaymentNamespace($site->name)
+    ) {
+        return $site->name;
+    }
+
+    if ($site->kind === ReferenceKind::StaticCall && $site->receiver !== null
+        && deletionPathIsPaymentNamespace($site->receiver)
+    ) {
+        return $site->receiver.'::'.$site->name.'()';
+    }
+
+    if ($site->kind !== ReferenceKind::MethodCall && $site->kind !== ReferenceKind::StaticCall) {
+        return null;
+    }
+
+    return deletionPathClassifyMethodName($site->name, $apiMethods);
+}
+
+/**
+ * メソッド名 1 つが決済事業者 API かを判定し安定 symbol を返す。
+ *
+ * @param  array<string, string>  $apiMethods  小文字メソッド名 => 正規表記
+ */
+function deletionPathClassifyMethodName(string $name, array $apiMethods): ?string
+{
+    $lower = mb_strtolower($name);
+    if (str_contains($lower, 'stripe') || array_key_exists($lower, $apiMethods)) {
+        return '->'.$name.'()';
+    }
+
+    return null;
+}
+
+/**
+ * 決済事業者の名前空間か (**接頭辞走査は Stripe SDK だけ**。Cashier は facade 1 本に限定する)。
+ */
+function deletionPathIsPaymentNamespace(string $fqcn): bool
+{
+    return str_starts_with($fqcn, 'Stripe\\') || $fqcn === 'Laravel\Cashier\Cashier';
+}
+
+/**
+ * Cashier の API 表面とみなすメソッド名 (小文字 => 正規表記)。
+ *
+ * @return array<string, string>
+ */
+function deletionPathPaymentApiMethods(): array
+{
+    /** @var array<string, string>|null $cache */
+    static $cache = null;
+    if ($cache !== null) {
+        return $cache;
+    }
+
+    $methods = [];
+    foreach ([Billable::class, Subscription::class] as $target) {
+        $reflection = new ReflectionClass($target);
+        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
+            if (! str_starts_with($method->getDeclaringClass()->getName(), 'Laravel\Cashier')) {
+                continue; // Eloquent 由来の継承メソッドは Cashier の API 表面ではない
+            }
+            $methods[mb_strtolower($method->getName())] = $method->getName();
+        }
+    }
+
+    foreach (array_keys(DELETION_PATH_CASHIER_LOCAL_METHODS) as $local) {
+        unset($methods[$local]);
+    }
+
+    $cache = $methods;
+
+    return $methods;
+}
+
+/**
+ * `app('...')` / `resolve('...')` / `->make('...')` の literal 第 1 引数。
+ *
+ * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+ * @return list<string>
+ */
+function deletionPathContainerLiterals(array $tokens): array
+{
+    $literals = [];
+    $count = count($tokens);
+
+    for ($i = 0; $i < $count; $i++) {
+        if ($tokens[$i]['id'] !== T_STRING) {
+            continue;
+        }
+        if (! in_array(mb_strtolower($tokens[$i]['text']), ['app', 'resolve', 'make'], true)) {
+            continue;
+        }
+        $open = $tokens[$i + 1] ?? null;
+        $argument = $tokens[$i + 2] ?? null;
+        if ($open === null || $argument === null) {
+            continue;
+        }
+        if ($open['id'] !== null || $open['text'] !== '(') {
+            continue;
+        }
+        if ($argument['id'] !== T_CONSTANT_ENCAPSED_STRING) {
+            continue;
+        }
+
+        $literals[] = deletionPathUnquote($argument['text']);
+    }
+
+    return array_values(array_unique($literals));
+}
+
+/**
+ * 文字列リテラルトークンから値を取り出す。
+ *
+ * ★`stripcslashes()` を通さない。単引用符の `'App\Foo'` に掛けると `\F` が escape として
+ *   消費され `AppFoo` になり、**クラス名の literal が丸ごと辺から落ちる**。
+ */
+function deletionPathUnquote(string $token): string
+{
+    $quote = $token[0] ?? "'";
+    $inner = substr($token, 1, -1);
+
+    return $quote === "'"
+        ? str_replace(['\\\\', "\\'"], ['\\', "'"], $inner)
+        : stripcslashes($inner);
+}
+
+/**
+ * literal の動的メソッド呼び出し (`->{'stripe'}()` / `::{'charge'}()`)。
+ *
+ * 名前が字句的に確定するので**動的扱いにせず記号照合へ載せる** (載せないと
+ * `->{'stripe'}()` で記号照合を素通りできる = fail-open。impl-review Round 1 [Warning])。
+ *
+ * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+ * @return list<array{name: string, line: int}>
+ */
+function deletionPathLiteralDynamicCalls(array $tokens): array
+{
+    $calls = [];
+    $count = count($tokens);
+
+    for ($i = 0; $i < $count; $i++) {
+        $id = $tokens[$i]['id'];
+        if ($id !== T_OBJECT_OPERATOR && $id !== T_NULLSAFE_OBJECT_OPERATOR && $id !== T_DOUBLE_COLON) {
+            continue;
+        }
+        $open = $tokens[$i + 1] ?? null;
+        $inner = $tokens[$i + 2] ?? null;
+        $close = $tokens[$i + 3] ?? null;
+        $paren = $tokens[$i + 4] ?? null;
+        if ($open === null || $inner === null || $close === null || $paren === null) {
+            continue;
+        }
+        if ($open['id'] !== null || $open['text'] !== '{') {
+            continue;
+        }
+        if ($inner['id'] !== T_CONSTANT_ENCAPSED_STRING) {
+            continue;
+        }
+        if ($close['id'] !== null || $close['text'] !== '}') {
+            continue;
+        }
+        if ($paren['id'] !== null || $paren['text'] !== '(') {
+            continue;
+        }
+
+        $calls[] = ['name' => deletionPathUnquote($inner['text']), 'line' => $tokens[$i]['line']];
+    }
+
+    return $calls;
+}
+
+/**
+ * 動的メソッド名の呼び出し (`->{$m}()` / `->$m()` / `::{$m}()` / `::$m()`)。
+ *
+ * ★literal の `->{'stripe'}()` はここには含めない。名前が字句的に確定するので
+ *   `deletionPathLiteralDynamicCalls()` が拾い、通常の呼び出しと同じ規則で記号照合する。
+ *
+ * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+ * @return list<string>
+ */
+function deletionPathDynamicCallSites(string $relativePath, array $tokens): array
+{
+    $sites = [];
+    $count = count($tokens);
+
+    for ($i = 0; $i < $count; $i++) {
+        $id = $tokens[$i]['id'];
+        if ($id !== T_OBJECT_OPERATOR && $id !== T_NULLSAFE_OBJECT_OPERATOR && $id !== T_DOUBLE_COLON) {
+            continue;
+        }
+
+        $next = $tokens[$i + 1] ?? null;
+        if ($next === null) {
+            continue;
+        }
+
+        // `->$m(` 形
+        if ($next['id'] === T_VARIABLE) {
+            $after = $tokens[$i + 2] ?? null;
+            if ($after !== null && $after['id'] === null && $after['text'] === '(') {
+                $sites[] = $relativePath.':'.$tokens[$i]['line'].' '.$tokens[$i]['text'].$next['text'].'()';
+            }
+
+            continue;
+        }
+
+        // `->{expr}(` 形 (literal は除く)
+        if ($next['id'] === null && $next['text'] === '{') {
+            $inner = $tokens[$i + 2] ?? null;
+            $closing = $tokens[$i + 3] ?? null;
+            $isLiteral = $inner !== null && $inner['id'] === T_CONSTANT_ENCAPSED_STRING
+                && $closing !== null && $closing['id'] === null && $closing['text'] === '}';
+            if (! $isLiteral) {
+                $sites[] = $relativePath.':'.$tokens[$i]['line'].' '.$tokens[$i]['text'].'{...}()';
+            }
+        }
+    }
+
+    return array_values(array_unique($sites));
+}
+
+/**
+ * app/ 全体の走査結果 (1 回だけ実行してテスト間で使い回す)。
+ *
+ * @return array{
+ *     files: int,
+ *     edges: array<string, list<string>>,
+ *     implementors: array<string, list<string>>,
+ *     payment: array<string, list<array{symbol: string, descriptor: string}>>,
+ *     dynamic: array<string, list<string>>,
+ *     misdeclared: list<string>,
+ *     edgeCount: int,
+ * }
+ */
+function deletionPathScanApp(): array
+{
+    /**
+     * @var array{
+     *     files: int,
+     *     edges: array<string, list<string>>,
+     *     implementors: array<string, list<string>>,
+     *     payment: array<string, list<array{symbol: string, descriptor: string}>>,
+     *     dynamic: array<string, list<string>>,
+     *     misdeclared: list<string>,
+     *     edgeCount: int,
+     * }|null $cache
+     */
+    static $cache = null;
+    if ($cache !== null) {
+        return $cache;
+    }
+
+    $files = PhpReferenceScanner::phpFiles(base_path('app'), 'app');
+
+    $edges = [];
+    $implementors = [];
+    $payment = [];
+    $dynamic = [];
+    $misdeclared = [];
+    $edgeCount = 0;
+
+    foreach ($files as $relativePath => $source) {
+        $scan = deletionPathScanSource($relativePath, $source);
+        $edges[$scan['class']] = $scan['edges'];
+        $payment[$scan['class']] = $scan['payment'];
+        $dynamic[$scan['class']] = $scan['dynamic'];
+        $edgeCount += count($scan['edges']);
+        foreach ($scan['implements'] as $interface) {
+            $implementors[$interface][] = $scan['class'];
+        }
+        // PSR-4 導出の前提: 1 ファイルが宣言する名前付き型はパス由来の FQCN ちょうど 1 つ。
+        foreach ($scan['declared'] as $declared) {
+            if ($declared !== $scan['class']) {
+                $misdeclared[] = $relativePath.' が宣言する型 '.$declared.' はパス由来の '.$scan['class'].' と一致しません';
+            }
+        }
+    }
+
+    $cache = [
+        'files' => count($files),
+        'edges' => $edges,
+        'implementors' => $implementors,
+        'payment' => $payment,
+        'dynamic' => $dynamic,
+        'misdeclared' => $misdeclared,
+        'edgeCount' => $edgeCount,
+    ];
+
+    return $cache;
+}
+
+/**
+ * 起点から辿れる app/ 内クラスの閉包 (ソート済み)。
+ *
+ * @return list<string>
+ */
+function deletionPathClosure(): array
+{
+    $scan = deletionPathScanApp();
+
+    $roots = array_map(deletionPathRootClass(...), DELETION_PATH_ROOTS);
+
+    return deletionPathTraverse($roots, $scan['edges'], $scan['implementors']);
+}
+
+/**
+ * 閉包の到達計算 (純関数。fixture から合成データで検証できるよう切り出してある)。
+ *
+ * @param  list<string>  $roots
+ * @param  array<string, list<string>>  $edges  クラス => 参照先クラス
+ * @param  array<string, list<string>>  $implementors  interface => 実装クラス (逆向きの辺)
+ * @return list<string>
+ */
+function deletionPathTraverse(array $roots, array $edges, array $implementors): array
+{
+    $queue = $roots;
+    $seen = [];
+
+    while ($queue !== []) {
+        $class = array_shift($queue);
+        if (array_key_exists($class, $seen) || ! array_key_exists($class, $edges)) {
+            continue;
+        }
+        $seen[$class] = true;
+        foreach ($edges[$class] as $next) {
+            $queue[] = $next;
+        }
+        // interface を経由した container binding 越しの到達 (逆向きの辺)。
+        foreach ($implementors[$class] ?? [] as $implementor) {
+            $queue[] = $implementor;
+        }
+    }
+
+    $closure = array_keys($seen);
+    sort($closure);
+
+    return $closure;
+}
+
+/** `FQCN::method` からクラス部分を取り出す。 */
+function deletionPathRootClass(string $root): string
+{
+    $position = strpos($root, '::');
+
+    return $position === false ? $root : substr($root, 0, $position);
+}
+
+/** `FQCN::method` からメソッド部分を取り出す。 */
+function deletionPathRootMethod(string $root): string
+{
+    $position = strpos($root, '::');
+
+    return $position === false ? '' : substr($root, $position + 2);
+}
+
+// ---------------------------------------------------------------------------
+// 検査
+// ---------------------------------------------------------------------------
+
+test('検査 1: 退会経路の依存閉包は目録と exact-fit で一致する', function (): void {
+    $closure = deletionPathClosure();
+    $inventory = DELETION_PATH_CLOSURE;
+    sort($inventory);
+
+    $missing = array_values(array_diff($closure, $inventory));
+    $stale = array_values(array_diff($inventory, $closure));
+
+    // 閉包に入った interface とその実装数を出す (逆向きの辺で閉包が膨らんだときに
+    // 「信号が弱まり始めたこと」をレビューで判断できるようにする。impl-review Round 2 [Suggestion])。
+    $implementors = deletionPathScanApp()['implementors'];
+    $interfaceNotes = [];
+    foreach ($closure as $class) {
+        $count = count($implementors[$class] ?? []);
+        if ($count > 0) {
+            $interfaceNotes[] = "{$class} の実装 {$count} 件が逆向きの辺で閉包に入っています";
+        }
+    }
+
+    expect(['未登録' => $missing, '残骸' => $stale])->toBe(['未登録' => [], '残骸' => []],
+        '退会経路の依存閉包が変わりました。DELETION_PATH_CLOSURE を更新する前に'
+        .'「この依存は本当に退会経路に必要か」「決済事業者へ到達しないか」をレビューしてください。'
+        .($interfaceNotes === [] ? '' : PHP_EOL.implode(PHP_EOL, $interfaceNotes)));
+});
+
+test('検査 2: 閉包内のどのクラスも決済事業者記号を参照しない', function (): void {
+    $payment = deletionPathScanApp()['payment'];
+    $exemptions = array_map(
+        static fn (DeletionPathSeamExemption $case): string => $case->value,
+        DeletionPathSeamExemption::cases(),
+    );
+
+    $violations = [];
+    foreach (deletionPathClosure() as $class) {
+        foreach ($payment[$class] ?? [] as $hit) {
+            // 免除キーは `{クラス FQCN}#{symbol}`。symbol は行番号を含まない安定キーで、
+            // enum の docblock が宣言している書式と同一である (行移動で免除が壊れない)。
+            if (in_array($class.'#'.$hit['symbol'], $exemptions, true)) {
+                continue;
+            }
+            $violations[] = $class.' : '.$hit['descriptor'];
+        }
+    }
+
+    expect($violations)->toBe([],
+        '退会経路の依存閉包から決済事業者記号へ到達しています。退会経路は決済事業者 API を呼びません '
+        .'(T115: 自 DB と外部サービスの二重書き込みを避ける / 解約を代行しない)。'
+        .'やむを得ない場合のみ DeletionPathSeamExemption へ 30 文字以上の根拠つきで登録してください。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('検査 3: 免除は型付き enum + 30 文字以上の根拠で、現在 0 件ちょうど', function (): void {
+    $cases = array_map(
+        static fn (DeletionPathSeamExemption $case): string => $case->value,
+        DeletionPathSeamExemption::cases(),
+    );
+    $keys = array_keys(DELETION_PATH_SEAM_EXEMPTION_RATIONALES);
+    sort($cases);
+    sort($keys);
+
+    expect($keys)->toBe($cases,
+        'DeletionPathSeamExemption に case を足したら DELETION_PATH_SEAM_EXEMPTION_RATIONALES へも'
+        .'同じ value をキーに根拠を登録してください (免除を型と根拠の両方で縛るための二重宣言です)。');
+
+    $short = [];
+    foreach (DELETION_PATH_SEAM_EXEMPTION_RATIONALES as $value => $rationale) {
+        if (mb_strlen($rationale) < 30) {
+            $short[] = $value.': 根拠が '.mb_strlen($rationale).' 文字';
+        }
+    }
+    expect($short)->toBe([], implode(PHP_EOL, $short));
+
+    // exact-fit cap: 余裕枠は「根拠なしに免除できる枠」になるため 1 でも持たせない。
+    expect($cases)->toHaveCount(0,
+        '免除は現在 0 件です。増やす場合はこの cap も同時に更新し、増やした理由をレビューで残してください。');
+});
+
+test('検査 4: 走査が空振りしていない', function (): void {
+    $scan = deletionPathScanApp();
+
+    expect($scan['files'])->toBeGreaterThan(300, '走査対象ファイルが想定より少ない (ディレクトリ構成の変更を疑う)');
+    expect($scan['edgeCount'])->toBeGreaterThan(0, '到達辺を 1 件も解決できていない (走査が死んでいる)');
+
+    $closure = deletionPathClosure();
+    expect(count($closure))->toBeGreaterThan(1, '閉包が起点だけになっている (辺の解決が死んでいる)');
+
+    // 起点が実在すること (クラス名 / メソッド名のタイポで空振りしない)。
+    foreach (DELETION_PATH_ROOTS as $root) {
+        $class = deletionPathRootClass($root);
+        $method = deletionPathRootMethod($root);
+        expect(class_exists($class))->toBeTrue("起点クラスが実在しません: {$class}");
+        expect(method_exists($class, $method))->toBeTrue("起点メソッドが実在しません: {$root}");
+        expect($closure)->toContain($class);
+    }
+
+    // 閉包の要素がすべて実在の型であること (PSR-4 導出の健全性)。
+    $unresolved = array_values(array_filter(
+        $closure,
+        static fn (string $class): bool => ! class_exists($class) && ! interface_exists($class)
+            && ! trait_exists($class) && ! enum_exists($class),
+    ));
+    expect($unresolved)->toBe([], '閉包に実在しない型が含まれます (PSR-4 導出の破綻): '.implode(', ', $unresolved));
+
+    // ★**前提の機械検査**: 走査は「1 ファイル = パス由来の FQCN 1 型」を前提に、
+    //   辺と implementor をファイル単位で帰属させている。ファイル名とクラス名がずれた実装や
+    //   1 ファイル複数型があると **implementor が別クラスへ帰属して静かに落ちる** (fail-open)。
+    //   前提を文章で断らず、app/ 全ファイルで実測して 0 件に pin する
+    //   (impl-review Round 2 [Warning])。匿名クラスは class=null なので母集団から自然に外れる。
+    expect($scan['misdeclared'])->toBe([],
+        'PSR-4 導出の前提 (1 ファイル = パス由来の型 1 つ) が崩れています。'
+        .'この前提が崩れると implements の逆向きの辺が誤ったクラスへ帰属します。'
+        .PHP_EOL.implode(PHP_EOL, $scan['misdeclared']));
+});
+
+test('検査 5: 自己参照コントロール (本 gate 自身は記号 hit なし・辺も exact-fit)', function (): void {
+    $self = 'tests/Architecture/AccountDeletionPathGateTest.php';
+    $source = file_get_contents(base_path($self));
+    Assert::string($source, '本 gate 自身を読み込めません');
+
+    $scan = deletionPathScanSource($self, $source);
+
+    // 説明コメント・nowdoc fixture は正規化で落ちるため記号 hit にならない。
+    expect($scan['payment'])->toBe([],
+        '本 gate 自身が決済事業者記号を持っています (コメントで偽赤になっていないか確認してください)。');
+    expect($scan['dynamic'])->toBe([]);
+
+    // ★「到達 0 件」ではなく **exact-fit** で pin する。本ファイルは免除 enum を import するので
+    //   辺は 1 本ある。0 件を主張すると実装より強い保証を謳うことになる (impl-review Round 1)。
+    //   nowdoc fixture 内の `App\...` は code token にならないため辺に現れない = 自己汚染しない。
+    expect($scan['edges'])->toBe(['App\Enums\Security\DeletionPathSeamExemption'],
+        '本 gate が app/ の型を追加参照しています。nowdoc fixture が code として走査されていないか確認してください。');
+});
+
+test('検査 6: 閉包内に動的メソッド名の呼び出しは 0 件', function (): void {
+    $dynamic = deletionPathScanApp()['dynamic'];
+
+    $violations = [];
+    foreach (deletionPathClosure() as $class) {
+        foreach ($dynamic[$class] ?? [] as $site) {
+            $violations[] = $class.' : '.$site;
+        }
+    }
+
+    expect($violations)->toBe([],
+        '退会経路の閉包に動的メソッド名の呼び出しがあります。名前が字句的に確定しないため'
+        .'決済事業者記号の照合を迂回できます (deny-by-default で 0 件に pin しています)。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('検査 7: Cashier API 名の導出が生きており、ローカル allowlist は exact-fit で根拠つき', function (): void {
+    $api = deletionPathPaymentApiMethods();
+
+    // 導出が死んでいないこと (Cashier の API 表面は数十件ある)。
+    expect(count($api))->toBeGreaterThan(50, 'Cashier API 名の導出が壊れています (リフレクションの前提を確認)');
+    expect($api)->toHaveKey('newsubscription')
+        ->and($api)->toHaveKey('charge')
+        ->and($api)->toHaveKey('cancelnow');
+
+    $violations = [];
+    foreach (DELETION_PATH_CASHIER_LOCAL_METHODS as $name => $rationale) {
+        if (str_contains($name, 'stripe')) {
+            $violations[] = "{$name}: 名前に stripe を含むメソッドは allowlist へ載せられません";
+        }
+        if (mb_strlen($rationale) < 30) {
+            $violations[] = "{$name}: 根拠が ".mb_strlen($rationale).' 文字';
+        }
+        if (! method_exists(Billable::class, $name)
+            && ! method_exists(Subscription::class, $name)
+        ) {
+            $violations[] = "{$name}: Cashier に実在しないメソッド名です (残骸)";
+        }
+        if (array_key_exists($name, $api)) {
+            $violations[] = "{$name}: allowlist が効いていません";
+        }
+    }
+
+    expect($violations)->toBe([], implode(PHP_EOL, $violations));
+
+    // ★**exact-fit cap**。根拠文さえ書けば `charge` / `cancelNow` を allowlist へ足して
+    //   検出面を静かに狭められる (fail-open) ため、キー集合そのものを別宣言で pin する
+    //   = 検出面を狭めるには 2 箇所を触らせる (impl-review Round 1 [Critical])。
+    $allowlist = array_keys(DELETION_PATH_CASHIER_LOCAL_METHODS);
+    sort($allowlist);
+    expect($allowlist)->toBe(['active', 'subscriptions', 'user'],
+        'Cashier API のローカル判定 allowlist を変えるときは、この pin も同時に更新してください'
+        .' (allowlist に足すことは決済到達の検出面を狭めることと同義です)。');
+});
+
+test('検査 8: 起点集合は exact-fit で pin される', function (): void {
+    // ★起点を静かに減らすと閉包が縮み、以後の到達検出が丸ごと消える。
+    //   PR-B (猶予期間つき削除) で PurgeDeletionRequestsCommand::handle を 3 本目として足すときは
+    //   この pin も同時に更新する (意図的な摩擦)。
+    expect(DELETION_PATH_ROOTS)->toBe([
+        'App\Http\Controllers\Settings\AccountController::destroy',
+        'App\Services\Organization\OrganizationMembershipService::deleteAccount',
+    ], '退会経路の起点を変えるときは、なぜ変えるのかをレビューで残してください。');
+});
+
+test('検査 9: redaction 記録コマンドは決済事業者記号を参照しない (記録専用の静的固定)', function (): void {
+    // ★A2 の「決済事業者 API を呼ばない」は Feature テストの StripeGatewayInterface mock だけでは
+    //   足りない (Cashier / Stripe SDK を直接使えば mock を経由しない)。記録コマンドは退会経路の
+    //   閉包には入らないので、ここで名指しの静的検査を置く (impl-review Round 1 [Warning])。
+    $relativePath = 'app/Console/Commands/Billing/MarkStripeCustomerRedactedCommand.php';
+    $source = file_get_contents(base_path($relativePath));
+    Assert::string($source, 'redaction 記録コマンドを読み込めません');
+
+    $scan = deletionPathScanSource($relativePath, $source);
+
+    expect($scan['payment'])->toBe([],
+        'redaction の記録コマンドが決済事業者記号を参照しています。このコマンドは**記録専用**で'
+        .'決済事業者 API を呼びません (実施は人手。docs/account-deletion-runbook.md)。');
+    expect($scan['dynamic'])->toBe([]);
+});
+
+// ---------------------------------------------------------------------------
+// 正負コントロール fixture
+// fixture は nowdoc (文字列トークン) なので本ファイルの走査では code にならない (検査 5)。
+// ---------------------------------------------------------------------------
+
+/**
+ * hit の symbol 一覧 (fixture の assert 用)。
+ *
+ * @param  list<array{symbol: string, descriptor: string}>  $hits
+ * @return list<string>
+ */
+function deletionPathSymbols(array $hits): array
+{
+    return array_values(array_map(static fn (array $hit): string => $hit['symbol'], $hits));
+}
+
+test('負のコントロール 1 形目: 型宣言だけの注入を検出する', function (): void {
+    // laravel-claude-template で実際に fail-open していた形。呼び出しが 1 つも無くても赤くする。
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Services\Organization;
+    use Stripe\StripeClient;
+    class Fixture {
+        public function __construct(private readonly StripeClient $stripeClient) {}
+        public function run(\Stripe\Customer $customer): void {}
+    }
+    PHP;
+
+    $scan = deletionPathScanSource('app/Services/Organization/Fixture.php', $fixture);
+
+    expect(deletionPathSymbols($scan['payment']))
+        ->toContain('Stripe\StripeClient')
+        ->toContain('Stripe\Customer');
+});
+
+test('負のコントロール 2 形目: facade 経由の client 取得を検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Services\Organization;
+    use Laravel\Cashier\Cashier;
+    class Fixture {
+        public function run(): void { Cashier::stripe()->customers->delete('cus_1'); }
+    }
+    PHP;
+
+    $scan = deletionPathScanSource('app/Services/Organization/Fixture.php', $fixture);
+
+    expect(deletionPathSymbols($scan['payment']))
+        ->toContain('Laravel\Cashier\Cashier')
+        ->toContain('Laravel\Cashier\Cashier::stripe()');
+});
+
+test('負のコントロール 3 形目: 完全修飾の static 呼び出しを検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Services\Organization;
+    class Fixture {
+        public function run(): void { \Stripe\Customer::retrieve('cus_1'); }
+    }
+    PHP;
+
+    $scan = deletionPathScanSource('app/Services/Organization/Fixture.php', $fixture);
+
+    expect(deletionPathSymbols($scan['payment']))
+        ->toContain('Stripe\Customer')
+        ->toContain('Stripe\Customer::retrieve()');
+});
+
+test('負のコントロール 4 形目: app() / resolve() / make() の literal 引数を検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Services\Organization;
+    class Fixture {
+        public function run(\Illuminate\Contracts\Container\Container $container): void {
+            app('cashier.stripe');
+            resolve('stripe.client');
+            $container->make('Stripe\StripeClient');
+        }
+    }
+    PHP;
+
+    $scan = deletionPathScanSource('app/Services/Organization/Fixture.php', $fixture);
+
+    expect(deletionPathSymbols($scan['payment']))->toBe([
+        'container:cashier.stripe',
+        'container:stripe.client',
+        'container:Stripe\StripeClient',
+    ]);
+});
+
+test('負のコントロール 5 形目: trait / import 経由の到達を辺として拾う', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Services\Organization;
+    use App\Support\Billing\SomeBillingTrait;
+    class Fixture {
+        use SomeBillingTrait;
+    }
+    PHP;
+
+    $scan = deletionPathScanSource('app/Services/Organization/Fixture.php', $fixture);
+
+    expect($scan['edges'])->toContain('App\Support\Billing\SomeBillingTrait');
+});
+
+test('負のコントロール 5 形目 (b): クラス本体の use が先頭 import を上書きしても辺を失わない', function (): void {
+    // ★`PhpReferenceScanner` の alias マップは `use App\...\Foo;` と クラス本体の `use Foo;` を
+    //   同じ短縮キーで扱うため、後者が前者を上書きして FQCN を失う。alias マップを辺に使うと
+    //   **trait 経由の到達が丸ごと消える** (fail-open)。トークン直読みでこれを防いでいることを固定する。
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Models;
+    use App\Models\Concerns\ShadowedTrait;
+    class Fixture {
+        use ShadowedTrait;
+    }
+    PHP;
+
+    $result = PhpReferenceScanner::references('app/Models/Fixture.php', $fixture);
+    // 前提の実測: alias マップ側は上書きで短縮名に潰れている (この前提が崩れたら本テストは不要になる)。
+    expect($result->imports['shadowedtrait'] ?? null)->toBe('ShadowedTrait');
+
+    $scan = deletionPathScanSource('app/Models/Fixture.php', $fixture);
+    expect($scan['edges'])->toContain('App\Models\Concerns\ShadowedTrait');
+});
+
+test('負のコントロール 6 形目: 動的メソッド名を検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Services\Organization;
+    class Fixture {
+        public function run(object $billable, string $method): void {
+            $billable->{$method}();
+            $billable->$method();
+        }
+    }
+    PHP;
+
+    $scan = deletionPathScanSource('app/Services/Organization/Fixture.php', $fixture);
+
+    expect($scan['dynamic'])->toHaveCount(2);
+});
+
+test('負のコントロール 7 形目: literal の動的メソッド名も記号照合に載せる', function (): void {
+    // ★`->{'stripe'}()` は名前が字句的に確定するので**動的扱いにしない**。
+    //   動的にも記号にも載せないと、この書き方 1 つで検出を素通りできる (fail-open)。
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Services\Organization;
+    class Fixture {
+        public function run(object $billable): void {
+            $billable->{'stripe'}();
+            $billable->{'charge'}(100);
+        }
+    }
+    PHP;
+
+    $scan = deletionPathScanSource('app/Services/Organization/Fixture.php', $fixture);
+
+    expect(deletionPathSymbols($scan['payment']))->toBe(['->stripe()', '->charge()']);
+    // literal なので「動的」には数えない (二重計上しない)。
+    expect($scan['dynamic'])->toBe([]);
+});
+
+test('負のコントロール 8 形目: 閉包に入った interface の実装クラスを保守的に引き込む', function (): void {
+    // ★退会経路が interface を型注入し、service provider が concrete へ bind している形を想定する。
+    //   ただし **bind 宣言は読まない** — 「その interface の app/ 内実装を全部引き込む」保守的な
+    //   逆向きの辺である (impl-review Round 1 [Critical] / Round 2 [Warning] の表現訂正)。
+    $consumer = <<<'PHP'
+    <?php
+    namespace App\Services\Organization;
+    use App\Contracts\BillingRedactor;
+    class Consumer {
+        public function __construct(private readonly BillingRedactor $redactor) {}
+    }
+    PHP;
+    $implementation = <<<'PHP'
+    <?php
+    namespace App\Services\Billing;
+    use App\Contracts\BillingRedactor;
+    use Stripe\StripeClient;
+    class StripeBillingRedactor implements BillingRedactor {
+        public function __construct(private readonly StripeClient $client) {}
+    }
+    PHP;
+    $interface = <<<'PHP'
+    <?php
+    namespace App\Contracts;
+    interface BillingRedactor {}
+    PHP;
+
+    $consumerScan = deletionPathScanSource('app/Services/Organization/Consumer.php', $consumer);
+    $implementationScan = deletionPathScanSource('app/Services/Billing/StripeBillingRedactor.php', $implementation);
+    $interfaceScan = deletionPathScanSource('app/Contracts/BillingRedactor.php', $interface);
+
+    expect($implementationScan['implements'])->toBe(['App\Contracts\BillingRedactor']);
+
+    $edges = [
+        $consumerScan['class'] => $consumerScan['edges'],
+        $implementationScan['class'] => $implementationScan['edges'],
+        $interfaceScan['class'] => $interfaceScan['edges'],
+    ];
+    $implementors = ['App\Contracts\BillingRedactor' => [$implementationScan['class']]];
+
+    $closure = deletionPathTraverse([$consumerScan['class']], $edges, $implementors);
+
+    expect($closure)->toContain('App\Services\Billing\StripeBillingRedactor');
+    expect(deletionPathSymbols($implementationScan['payment']))->toContain('Stripe\StripeClient');
+});
+
+test('正のコントロール: 基底クラスの継承は逆向きに辿らない (信号を殺さない)', function (): void {
+    // ★`implements` だけを逆向きの辺にする。`extends` を辿ると
+    //   `AccountController extends Controller` から app/ の全 Controller が閉包に入り信号が死ぬ。
+    $source = <<<'PHP'
+    <?php
+    namespace App\Http\Controllers\Other;
+    use App\Http\Controllers\Controller;
+    class OtherController extends Controller {}
+    PHP;
+
+    $scan = deletionPathScanSource('app/Http/Controllers/Other/OtherController.php', $source);
+
+    expect($scan['implements'])->toBe([]);
+    // 実アプリの閉包にも兄弟 Controller は入っていない (検査 1 の目録が 53 件に収まっている根拠)。
+    expect(deletionPathClosure())->not->toContain('App\Http\Controllers\Settings\ProfileController');
+});
+
+test('正のコントロール: コメント・文字列中の決済事業者記号を誤検出しない', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Services\Organization;
+    /** Stripe\StripeClient を呼ばないことがこのクラスの契約である (Cashier::stripe も同様)。 */
+    class Fixture {
+        public function run(): void {
+            $note = 'Stripe\StripeClient';
+            // Cashier::stripe()->customers->delete($id);
+        }
+    }
+    PHP;
+
+    $scan = deletionPathScanSource('app/Services/Organization/Fixture.php', $fixture);
+
+    expect($scan['payment'])->toBe([]);
+});
+
+test('検査: mutation 被覆表のキー集合が想定 mutation ID と一致する', function (): void {
+    expect(array_keys(DELETION_PATH_MUTATION_COVERAGE))->toBe(DELETION_PATH_MUTATION_IDS);
+});

```

## 再検証結果

- `composer phpstan` : OK (841 files, No errors)
- `vendor/bin/pint --test` : passed
- `composer test` : 4145 tests / 4143 passed / 2 skipped / 0 failed (17796 assertions)
- `AccountDeletionPathGateTest` 単体: 21 tests / 21 passed
- 全 mutation は復元済み (`git status --short` に残留なし)

## 質問

1. Round 2 の [Warning] 3 件・[Suggestion] 1 件への対応は十分か。
2. 「1 ファイル = パス由来の型 1 つ」を**散文ではなく app/ 全ファイルの実測 pin** にした対応は妥当か。
3. 残っている [Critical] はあるか。無ければ APPROVED としてよいか。

全体判定 (APPROVED / CHANGES_REQUESTED) を最後に 1 行で書け。
