# AI-CUE 実装レビュー依頼: T076 (P5) チケット残高会計の aigenba verbatim 移植

## 【前提 1】アプリの使命 (North Star) — AGENTS.md より


<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


## 【前提 2】禁止事項 — AGENTS.md より


1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)


## 【前提 3】セキュリティ不変条件 (アプリ都合で緩めない) — AGENTS.md より


詳細と実装手順は `docs/app-integration-guide.md` §7。すべて Architecture テストで強制されている:

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない
   (`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`)
2. **子は親に属する**: nested route の不整合は**認可より前に 404**
   (`NestedRouteIdorDefenseTest` の inventory に登録必須)
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`(平文 where は hit しない)
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**: 外部 URL(特にユーザ入力由来)を取得する機能は
   必ず `Kent013\SsrfPin\UrlSafetyInspector` / `PinnedHttpClient` を通す。
   安全境界は `config/ssrf-pin.php` に pin する(`SsrfPinBoundaryTest` が pin 値を固定)



## 【前提 4】思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## 【前提 5】ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# あなたの役割

あなたはこの実装差分の **実装レビュアー** である。設計書 (Codex 合議 16 ラウンドで APPROVED 済み) と実装差分を突き合わせ、
**設計からの逸脱・不変条件の破れ・金銭事故につながる境界バグ** を検出せよ。

## 出力形式 (厳守)

指摘は次の 3 段階でラベル付けし、各指摘に「該当ファイル:行 / 何が壊れるか (具体的な入力→結果) / 根拠」を必ず添えること。

- `[Critical]` — 金銭事故・セキュリティ不変条件の破れ・設計書との明確な矛盾・テストが空振りしている。**マージ前に必ず直す必要があるもの**
- `[Warning]` — 実害の可能性はあるが条件付き / 可読性・保守性で将来事故を招く
- `[Suggestion]` — 改善提案 (任意対応)

最後に `## Verdict: APPROVED` または `## Verdict: CHANGES_REQUESTED` を 1 行で書け。
**指摘が無い場合は無理に作らないこと**。設計どおりの実装を「一般論として気になる」で Critical にしない。
逆に、設計書自体が誤っている (= 設計どおりに実装すると金銭事故になる) と判断したらそれも Critical として書け。

## レビュー観点

1. **設計書どおりか** — 下に添付する設計書 P5 節の各契約 (バケット定義 / clamp / 消費優先 / commit-wins / legacy 扱い / hold 集計) と実装が一致しているか。設計が「aigenba verbatim」と書く箇所で勝手な発明をしていないか。逆に、AI-CUE 固有の適合 (amount 一般化 / 無期限 monthly / source IS NULL 畳み込み) が設計の意図どおりか
2. **禁止事項・セキュリティ不変条件への抵触**
3. **PHPStan level 10 適合** (widen / baseline / @phpstan-ignore を入れていないか。差分に該当なしは確認済み)
4. **テストが不変条件を実際に固定しているか (空振りしていないか)** — 期待値が実装をなぞっているだけで、実装を壊しても落ちないテストになっていないか。設計のテスト計画で要求された項目に抜けがないか
5. **副作用・後退リスク** — 既存テストの期待値更新が「バグを承認する更新」になっていないか

## P5 固有の重点観点 (ここを最優先で見よ)

- **残高計算の境界**: 負残高 (返金 clawback で purchased が負) / per-source clamp の適用順序 (clamp は hold 控除の前か後か) / `expires_at > now` の境界 (等号の扱い) が aigenba と一致するか
- **`availableTrueBalance()` の契約が P8a (オートリチャージ) の依存に耐えるか** — P8a は「閾値判定」と「数量確定 `quantity = min(max_count − availableTrueBalance, PURCHASE_MAX)`」の双方でこの値を使い、**非負性が `quantity <= max_count` (ユーザ同意上限の不変条件) の根拠**になる。この契約が構造的に保証されているか
- **`nearestMonthlyExpiry` で消費行に載せる `expires_at` の妥当性**: 生きた monthly grant が複数あり期限が異なる場合、最短期限が「実際に残高を供給している grant」とは限らない。amount 一般化 (aigenba は 1 枚固定) でこのズレが増幅しないか。増幅する場合、それは over-grant (タダ配り) か under-grant (過剰請求) のどちらに倒れるか
- **`ReleasedExpired` 経路と「succeeded ∧ 無課金の非共存」**: 失効 monthly hold は決定的 no-charge なので、長時間ジョブ中に monthly が失効すると「成果物を渡して無課金」が成立し得る。設計はこれを許容しているが、実装がその窓を不必要に広げていないか
- **`insertIdempotent()` の戻り値化と `consume:{reservationId}` UNIQUE** が二重課金防止として本当に機能するか (status guard 撤去で失われた防御を肩代わりできているか)
- **`whereNot(closure)` による失効 monthly hold の除外が 3 値論理 (NULL 伝播) で legacy 行を誤って除外しないか** — SQLite / MySQL / PostgreSQL いずれでも同じ結果になるか

---

# データ 1: 実装差分 (`git diff main...HEAD`)

このブランチには 2 コミットある。

- `623e32d feat: T076 決済 parity P5 チケット残高会計を aigenba verbatim へ移植` (本体)
- `a613cee fix(test): P4 の残赤 SeededFreePlanBillingAccessTest を personal へ読み替え + pint 整形`
  (P5 とは独立。P4 = 前タスクで `plans` から `free` 行が撤去された後始末漏れ。`Plan::where('code','free')` が
  `firstOrFail` で落ちていたのを `'personal'` へ読み替えた。テストの意図とアサーションは不変。
  併せて既存の未整形 devnotes スクリプト 2 本を `composer fix` で整形した)

## 検証コマンドの結果 (実装者申告)

| コマンド | 結果 |
|---|---|
| `composer test` | 2164 tests / 2162 passed / 2 skipped / 0 errors / 8660 assertions |
| `composer phpstan` | 691 files → No errors |
| `vendor/bin/pint --test` | passed |
| `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` | すべて exit 0 |

```diff
diff --git a/app/DataTransferObjects/Billing/TicketBalanceDto.php b/app/DataTransferObjects/Billing/TicketBalanceDto.php
new file mode 100644
index 0000000..c7e5318
--- /dev/null
+++ b/app/DataTransferObjects/Billing/TicketBalanceDto.php
@@ -0,0 +1,54 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+/**
+ * 表示用の per-source チケット残高 (aigenba TicketBalanceDto verbatim)。
+ *
+ * monthlyRemaining / purchasedRemaining は出所ごとの生残高を max(…, 0) で clamp した
+ * **表示値**。判定 (与信・閾値) には使わないこと — clamp が負残高 (返金逆仕訳による債務) を
+ * 隠すため、判定に使うと誤判定する。判定は TicketLedgerService::availableTrueBalance() を使う。
+ *
+ * @phpstan-type TicketBalanceShape array{
+ *   monthlyRemaining: int,
+ *   purchasedRemaining: int,
+ *   totalAvailable: int,
+ *   activeReservations: int,
+ *   nextExpireAt: string|null
+ * }
+ */
+final readonly class TicketBalanceDto
+{
+    public function __construct(
+        /** monthly バケットの生残高を clamp した表示値 (hold は控除しない) */
+        public int $monthlyRemaining,
+        /** purchased バケット (source=purchased ∪ source IS NULL) の生残高を clamp した表示値 */
+        public int $purchasedRemaining,
+        /** Reserved 予約が拘束している「枚数」(SUM(amount)。legacy 行も計上する保守側) */
+        public int $activeReservations,
+        /** 未失効・正 delta の最短失効時刻 (ISO8601)。無ければ null */
+        public ?string $nextExpireAt,
+    ) {}
+
+    /** 表示用の利用可能枚数 (clamp 済み残高 − 拘束枚数。常に 0 以上) */
+    public function totalAvailable(): int
+    {
+        return max($this->monthlyRemaining + $this->purchasedRemaining - $this->activeReservations, 0);
+    }
+
+    /**
+     * @return TicketBalanceShape
+     */
+    public function toArray(): array
+    {
+        return [
+            'monthlyRemaining' => $this->monthlyRemaining,
+            'purchasedRemaining' => $this->purchasedRemaining,
+            'totalAvailable' => $this->totalAvailable(),
+            'activeReservations' => $this->activeReservations,
+            'nextExpireAt' => $this->nextExpireAt,
+        ];
+    }
+}
diff --git a/app/Enums/Billing/TicketCommitResult.php b/app/Enums/Billing/TicketCommitResult.php
new file mode 100644
index 0000000..85bee7f
--- /dev/null
+++ b/app/Enums/Billing/TicketCommitResult.php
@@ -0,0 +1,27 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Billing;
+
+/**
+ * TicketLedgerService::commit の結果 (aigenba TicketCommitResult verbatim)。
+ *
+ * commit は pipeline の terminal transaction (成果物確定後) から呼ばれる。
+ * no-charge パス (ReleasedExpired) を void で隠さず明示・可観測にするための戻り値。
+ * 呼び出し側は分岐に使わない (課金の真実源は台帳)。
+ */
+enum TicketCommitResult
+{
+    /** 消費行 (負 delta) を計上して確定した。 */
+    case Committed;
+
+    /** 既に committed 済 (冪等 no-op)。 */
+    case AlreadyCommitted;
+
+    /**
+     * monthly hold が commit 時点で失効していたため課金せず Released にした。
+     * 成果物は既に確定済のため完了自体はブロックしない (入口与信は reserve が権威)。
+     */
+    case ReleasedExpired;
+}
diff --git a/app/Http/Controllers/Billing/BillingController.php b/app/Http/Controllers/Billing/BillingController.php
index d50008e..042f7bf 100644
--- a/app/Http/Controllers/Billing/BillingController.php
+++ b/app/Http/Controllers/Billing/BillingController.php
@@ -61,7 +61,7 @@ public function index(Request $request, TicketLedgerService $tickets): Response
         return Inertia::render('Billing/Index', [
             'plans' => $plans,
             'currentPlanCode' => $organization->plan_code,
-            'ticketBalance' => $tickets->balance($organization),
+            'ticketBalance' => $tickets->balance($organization)->totalAvailable(),
             'canManageBilling' => $user->can('manageBilling', $organization),
         ]);
     }
diff --git a/app/Http/Controllers/Billing/TicketPurchaseController.php b/app/Http/Controllers/Billing/TicketPurchaseController.php
index aef423d..8453669 100644
--- a/app/Http/Controllers/Billing/TicketPurchaseController.php
+++ b/app/Http/Controllers/Billing/TicketPurchaseController.php
@@ -63,7 +63,7 @@ public function show(
             minCount: TicketVolumePrice::PURCHASE_MIN_COUNT,
             maxCount: TicketVolumePrice::PURCHASE_MAX_COUNT,
             defaultCount: self::DEFAULT_COUNT,
-            balance: $tickets->balance($organization),
+            balance: $tickets->balance($organization)->totalAvailable(),
             canManage: $user->can('manageBilling', $organization),
             attemptToken: (string) Str::ulid(),
             purchased: $purchased,
diff --git a/app/Models/Billing/TicketReservation.php b/app/Models/Billing/TicketReservation.php
index 19785b5..c92a573 100644
--- a/app/Models/Billing/TicketReservation.php
+++ b/app/Models/Billing/TicketReservation.php
@@ -5,26 +5,37 @@
 namespace App\Models\Billing;
 
 use App\Enums\Billing\TicketReservationStatus;
+use App\Enums\Billing\TicketSource;
 use App\Models\Organization;
 use Carbon\CarbonImmutable;
+use Database\Factories\Billing\TicketReservationFactory;
+use Illuminate\Database\Eloquent\Factories\HasFactory;
 use Illuminate\Database\Eloquent\Model;
 use Illuminate\Database\Eloquent\Relations\BelongsTo;
 
 /**
  * チケット予約 (reserve → commit / release の 2 フェーズ消費の前半)。
  *
- * organization_id / status / amount / expires_at はすべて TicketLedgerService が
+ * organization_id / status / amount / expires_at / consume_* はすべて TicketLedgerService が
  * 管理する状態のため $fillable は持たない (明示代入のみ)。
  * 状態遷移も同 Service 経由のみ (直接 update を書かない)。
  *
+ * consume_source / consume_expires_at は「消費する期間 = 予約した期間」を予約時に固定する
+ * (commit は再探索しない)。両者 null は P5 デプロイ前の in-flight 予約 (legacy)。
+ *
  * @property int $id
  * @property int $organization_id
  * @property int $amount
  * @property TicketReservationStatus $status
  * @property CarbonImmutable $expires_at
+ * @property ?TicketSource $consume_source
+ * @property ?CarbonImmutable $consume_expires_at
  */
 class TicketReservation extends Model
 {
+    /** @use HasFactory<TicketReservationFactory> */
+    use HasFactory;
+
     /**
      * @return BelongsTo<Organization, $this>
      */
@@ -42,6 +53,8 @@ protected function casts(): array
             'amount' => 'integer',
             'status' => TicketReservationStatus::class,
             'expires_at' => 'immutable_datetime',
+            'consume_source' => TicketSource::class,
+            'consume_expires_at' => 'immutable_datetime',
         ];
     }
 }
diff --git a/app/Services/Billing/TicketLedgerService.php b/app/Services/Billing/TicketLedgerService.php
index f1fd583..a293e9d 100644
--- a/app/Services/Billing/TicketLedgerService.php
+++ b/app/Services/Billing/TicketLedgerService.php
@@ -4,6 +4,8 @@
 
 namespace App\Services\Billing;
 
+use App\DataTransferObjects\Billing\TicketBalanceDto;
+use App\Enums\Billing\TicketCommitResult;
 use App\Enums\Billing\TicketLedgerKind;
 use App\Enums\Billing\TicketReservationStatus;
 use App\Enums\Billing\TicketSource;
@@ -13,7 +15,10 @@
 use App\Models\Organization;
 use App\Services\Notification\NotificationCenterService;
 use Carbon\CarbonImmutable;
+use Carbon\CarbonInterface;
+use Illuminate\Database\Eloquent\Builder;
 use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Log;
 use LogicException;
 use RuntimeException;
 use Webmozart\Assert\Assert;
@@ -21,13 +26,23 @@
 /**
  * チケット台帳 (2 フェーズ消費プリミティブ) の唯一の窓口。
  *
- * - 残高 = SUM(未失効 ledger.delta) − SUM(active 予約.amount)。直接デクリメントは書かない
- * - 消費を伴う処理は必ず reserve → (成功) commit / (失敗) release
+ * - 残高は **出所 (source) ごとのバケット会計**。バケットは
+ *   monthly (`source = 'monthly'`) と purchased (`source = 'purchased' OR source IS NULL`) の 2 つ。
+ *   `source IS NULL` 行 (P5 以前の消費行 / 手動 grant / adjustment / release) は
+ *   purchased へ畳む (いずれも無期限で寿命特性が一致。両バケットから落とすと過去消費が
+ *   帳消しになり over-grant する)
+ * - 各バケットは `expires_at IS NULL OR expires_at > now` の行のみ合算する。消費行 (reserve_commit)
+ *   は**消費した grant と同じ expires_at を載せる**ため、失効時に +grant と −consume が同時に
+ *   合算から落ちる (「全額失効」近似が無い)
+ * - 直接デクリメントは書かない。消費を伴う処理は必ず reserve → (成功) commit / (失敗) release
  * - 全操作 transaction + organizations 行ロック (lockForUpdate) で残高判定の
  *   TOCTOU を防止する (並行 reserve のオーバーセル防止)
- * - reserve TTL 超過は billing:release-stale-reservations cron (releaseStale) が解放する
+ * - reserve TTL 超過と失効 monthly hold は billing:release-stale-reservations cron
+ *   (releaseStale) が解放する
  * - webhook 由来の付与 (grantMonthly / grantSignupGrant / grantPurchased) と
  *   返金逆仕訳 (clawback) は idempotency_key UNIQUE の冪等 insert で二重計上を防ぐ
+ * - commit は **commit-wins**: reserve TTL 超過や stale releaser 先着でも生存 hold は課金する
+ *   (二重課金は `consume:{reservationId}` の UNIQUE が防ぐ。課金の真実源は台帳)
  */
 class TicketLedgerService
 {
@@ -217,35 +232,75 @@ public function clawbackPurchasedByPaymentIntent(string $paymentIntentId, int $a
     }
 
     /**
-     * 利用可能残高 (= 未失効の台帳合計 − reserved 予約合計)。
+     * 表示用の per-source 残高。
      *
-     * 期限付き付与は expires_at 到達で合算から外れる。消費 (reserve_commit / clawback) 行は
-     * 期限を持たず残るため、失効は「未消費分も含めた全額失効」として保守的に働く
-     * (失効前に消費した分だけ残高が下振れし得るが、over-grant にはならない)。
-     * バケット (出所×期限) 単位の厳密な失効会計が必要な派生アプリは
-     * source / expires_at 列を使って balance を差し替えること。
+     * monthlyRemaining / purchasedRemaining は出所ごとの生残高を max(…, 0) で clamp した
+     * **表示値** (hold は控除しない)。activeReservations は Reserved 予約の拘束枚数
+     * (SUM(amount)。legacy 行も計上する保守側)。
+     *
+     * **判定 (与信・閾値) には使わないこと** — clamp が返金逆仕訳による負残高を隠すため、
+     * 判定に使うと誤判定する。判定は availableTrueBalance() を使う。
      */
-    public function balance(Organization $organization): int
+    public function balance(Organization $organization): TicketBalanceDto
     {
-        $ledgerTotal = (int) TicketLedgerEntry::query()
-            ->where('organization_id', $organization->getKey())
-            ->where(function ($query): void {
-                $query->whereNull('expires_at')
-                    ->orWhere('expires_at', '>', CarbonImmutable::now());
-            })
-            ->sum('delta');
+        $now = CarbonImmutable::now();
+
+        $monthly = $this->sumBalance($organization, TicketSource::Monthly, $now);
+        $purchased = $this->sumBalance($organization, TicketSource::Purchased, $now);
 
-        $reserved = (int) TicketReservation::query()
+        // 拘束「枚数」。sumActiveHolds と完全に同一条件で集計する (与信の単一真実源)。
+        // reserve TTL 切れでも Reserved は枠を保持し (commit-wins と対称)、失効 monthly hold のみ
+        // 除外する。expires_at>now ガードは付けない (30 分超ジョブ中の同枠二重予約 = オーバーセル防止)
+        $activeReservations = (int) TicketReservation::query()
             ->where('organization_id', $organization->getKey())
             ->where('status', TicketReservationStatus::Reserved)
+            ->whereNot(fn (Builder $query) => $this->expiredMonthlyHoldCondition($query, $now))
             ->sum('amount');
 
-        return $ledgerTotal - $reserved;
+        $nextExpire = TicketLedgerEntry::query()
+            ->where('organization_id', $organization->getKey())
+            ->where('delta', '>', 0)
+            ->whereNotNull('expires_at')
+            ->where('expires_at', '>', $now)
+            ->orderBy('expires_at')
+            ->value('expires_at');
+
+        return new TicketBalanceDto(
+            monthlyRemaining: max($monthly, 0),
+            purchasedRemaining: max($purchased, 0),
+            activeReservations: $activeReservations,
+            nextExpireAt: $nextExpire instanceof CarbonInterface
+                ? CarbonImmutable::instance($nextExpire)->toIso8601String()
+                : null,
+        );
+    }
+
+    /**
+     * 与信・判定用の真値残高。出所ごとに「生残高 (負許容) − active 予約」を max(…, 0) して
+     * から合算するため **戻り値は常に 0 以上**。monthly の余剰が purchased の負 (返金債務) を
+     * 埋めない / その逆もしない真値判定で、reserve() の availableMonthly + availablePurchased と
+     * 同一意味論。
+     *
+     * **この契約 (非負性 + per-source clamp 後の合算) には P8a のオートリチャージが依存する** —
+     * 閾値判定と数量確定 (quantity = max_count − balance) の双方がこの真値を使い、非負性が
+     * quantity <= max_count (同意上限の不変条件) の根拠になる。変更時は P8a 側の契約も見直すこと。
+     *
+     * UI 表示には balance() を使うこと (表示 DTO は clamp 済みで、判定に使うと負残高で誤判定する)。
+     */
+    public function availableTrueBalance(Organization $organization): int
+    {
+        $now = CarbonImmutable::now();
+        [$availableMonthly, $availablePurchased] = $this->availableBySource($organization, $now);
+
+        return $availableMonthly + $availablePurchased;
     }
 
     /**
      * チケットを予約する (2 フェーズ消費の前半)。
-     * 残高不足は InsufficientTicketsException。
+     *
+     * 消費優先順位は monthly (期限付き = 先に失効する) → purchased (無期限)。予約時に
+     * 「どの出所をどの期限で消費するか」を consume_source / consume_expires_at へ固定し、
+     * commit は再探索しない。残高不足は InsufficientTicketsException。
      */
     public function reserve(Organization $organization, int $amount): TicketReservation
     {
@@ -255,24 +310,41 @@ public function reserve(Organization $organization, int $amount): TicketReservat
             // 残高判定の直列化点: organizations 行ロックで並行 reserve の TOCTOU を防ぐ
             $this->lockOrganizationRow($organization);
 
-            $balance = $this->balance($organization);
-            if ($balance < $amount) {
-                throw InsufficientTicketsException::forReserve($amount, $balance);
+            $now = CarbonImmutable::now();
+            [$availableMonthly, $availablePurchased] = $this->availableBySource($organization, $now);
+
+            // 予約行は単一 consume_source を持つ (source ごとの分割配賦をしない) ため、実際に
+            // 賄える容量は **max 側**。sum 形にすると「どちらの source も単独では amount を
+            // 賄えない」ケースで選んだ source を超過消費し、clamp がそれを隠して最大 amount−1 枚の
+            // タダ配りになる (aigenba は amount=1 固定のため sum 形と max 形が同値)
+            $capacity = max($availableMonthly, $availablePurchased);
+            if ($capacity < $amount) {
+                throw InsufficientTicketsException::forReserve($amount, $capacity);
             }
 
+            $consumeSource = $availableMonthly >= $amount ? TicketSource::Monthly : TicketSource::Purchased;
+            // monthly は最短の生きた月次期限を境界にする。AI-CUE には無期限 monthly grant
+            // (BughuntBillingSeeder / monthly_ticket_grant を戻した場合の invoice.paid) が実在するため
+            // null を許容する (null = 無期限 monthly からの消費 = 失効しない hold)
+            $consumeExpiresAt = $consumeSource === TicketSource::Monthly
+                ? $this->nearestMonthlyExpiry($organization, $now)
+                : null;
+
             $reservation = new TicketReservation;
             // 所有権・状態キーは明示代入 (mass assignment しない)
             $reservation->organization()->associate($organization);
             $reservation->amount = $amount;
             $reservation->status = TicketReservationStatus::Reserved;
-            $reservation->expires_at = CarbonImmutable::now()->addMinutes(self::RESERVATION_TTL_MINUTES);
+            $reservation->expires_at = $now->addMinutes(self::RESERVATION_TTL_MINUTES);
+            $reservation->consume_source = $consumeSource;
+            $reservation->consume_expires_at = $consumeExpiresAt;
             $reservation->save();
 
-            // 残高低下の閾値クロス検知。クロス判定を reserve に置く理由: balance() は
-            // 「有効台帳合計 − Reserved 拘束」であり、実効残高が減る唯一の消費イベントは reserve
-            // (Reserved→Committed の commit は拘束 -amount と台帳 -amount が相殺し balance() 不変)。
-            // reserve は org 行ロック下で直列化済みのため、並行 reserve でもクロスを観測するのは
-            // ちょうど 1 回 (release/grant で回復して再度跨げば再通知される = 仕様)
+            // 残高低下の閾値クロス検知。クロス判定を reserve に置く理由: 実効残高が減る唯一の
+            // 消費イベントは reserve (Reserved→Committed の commit は拘束 -amount と台帳 -amount が
+            // 相殺し実効残高は不変)。reserve は org 行ロック下で直列化済みのため、並行 reserve でも
+            // クロスを観測するのはちょうど 1 回 (release/grant で回復して再度跨げば再通知 = 仕様)
+            $balance = $availableMonthly + $availablePurchased; // = availableTrueBalance と同一意味論
             $threshold = config()->integer('billing.ticket_low_balance_threshold');
             $after = $balance - $amount;
             if ($balance >= $threshold && $after < $threshold) {
@@ -285,28 +357,97 @@ public function reserve(Organization $organization, int $amount): TicketReservat
         });
     }
 
-    /** 予約を確定する (台帳に負 delta を記録し、予約を committed にする) */
-    public function commit(TicketReservation $reservation): void
+    /**
+     * 予約を確定する (台帳に負 delta を記録し、予約を committed にする)。
+     *
+     * **commit-wins**: 完了時は必ず課金する。reserve TTL 超過 (30 分超ジョブ) でも、stale releaser
+     * が先着で Released 化していても、生存 hold は消費行を計上して確定する (status は一方向遷移を
+     * 壊さないため Released のまま据え置き、課金は台帳が真実源)。reserve TTL は「reserve 入口の
+     * 二重起動防止」専用と再定義し、二重課金は `consume:{reservationId}` の UNIQUE が防ぐ。
+     *
+     * 例外は失効 monthly hold (consume_expires_at 経過) のみで、これは課金せず Released に倒して
+     * ReleasedExpired を返す (stale job の実行タイミングに依らず決定的 no-charge)。
+     * **戻り値は可観測性のためのもので、呼び出し側は分岐に使わない**。
+     */
+    public function commit(TicketReservation $reservation): TicketCommitResult
     {
-        DB::transaction(function () use ($reservation): void {
-            $locked = $this->lockReservationRow($reservation);
+        $result = DB::transaction(function () use ($reservation): TicketCommitResult {
+            // status guard を撤去 (commit-wins)。行ロックは維持する
+            $locked = $this->lockReservationRow($reservation, requireReserved: false);
+
+            if ($locked->status === TicketReservationStatus::Committed) {
+                return TicketCommitResult::AlreadyCommitted; // 冪等 no-op
+            }
+
             $organization = $locked->organization;
             Assert::isInstanceOf($organization, Organization::class);
             $this->lockOrganizationRow($organization);
 
-            $this->appendEntry(
-                $organization,
-                -$locked->amount,
-                TicketLedgerKind::ReserveCommit,
-                $locked,
-                "予約 {$locked->id} の消費確定",
-            );
+            $now = CarbonImmutable::now();
+
+            if ($this->isExpiredMonthlyHold($locked, $now)) {
+                if ($locked->status === TicketReservationStatus::Reserved) {
+                    $locked->status = TicketReservationStatus::Released;
+                    $locked->save();
+                    Log::warning('ticket commit: monthly hold expired at commit, released without charge', [
+                        'reservation_id' => $locked->id,
+                        'organization_id' => $locked->organization_id,
+                        'consume_expires_at' => $locked->consume_expires_at?->toIso8601String(),
+                        'committed_at' => $now->toIso8601String(),
+                    ]);
+                } else {
+                    // stale releaser が先に Released 化済 (= 消費行は元々無い)。可観測性のため記録
+                    Log::info('ticket commit: monthly hold already released as expired, no charge', [
+                        'reservation_id' => $locked->id,
+                        'organization_id' => $locked->organization_id,
+                    ]);
+                }
+
+                return TicketCommitResult::ReleasedExpired; // 台帳行を書かない (決定的 no-charge)
+            }
 
-            $locked->status = TicketReservationStatus::Committed;
-            $locked->save();
+            $source = $locked->consume_source ?? TicketSource::Monthly; // legacy 既定
+            $expiresAt = $this->consumeExpiresAtFor($locked, $source);
+
+            // 消費行に「消費した grant と同じ expires_at」を載せる。バケット失効時に
+            // +grant と −consume が同時に合算から落ちる (「全額失効」近似の解消)
+            $inserted = $this->insertIdempotent($organization, "consume:{$locked->id}", [
+                'delta' => -$locked->amount,
+                'kind' => TicketLedgerKind::ReserveCommit->value,
+                'source' => $source->value,
+                'reservation_id' => $locked->getKey(),
+                'description' => "予約 {$locked->id} の消費確定",
+                'granted_at' => null,
+                'expires_at' => $expiresAt,
+            ]);
+
+            if ($inserted === 0) {
+                // Committed を返すのに消費行が書かれなかった = 既存 consume 行が存在。冪等としては
+                // 正しい (二重課金しない) が、不整合検知のため可観測化する
+                Log::warning('ticket commit: consume ledger already existed, no consume entry written', [
+                    'reservation_id' => $locked->id,
+                    'organization_id' => $locked->organization_id,
+                ]);
+            }
+
+            if ($locked->status === TicketReservationStatus::Reserved) {
+                $locked->status = TicketReservationStatus::Committed;
+                $locked->save();
+            } else {
+                // stale releaser に先着 Released された生存予約。commit-wins で課金済。
+                // 一方向遷移 (Released→Committed) を壊さず status は据え置き、課金は台帳で確定
+                Log::info('ticket commit: released-then-charged (stale release before completion)', [
+                    'reservation_id' => $locked->id,
+                    'organization_id' => $locked->organization_id,
+                ]);
+            }
+
+            return TicketCommitResult::Committed;
         });
 
         $reservation->refresh();
+
+        return $result;
     }
 
     /** 予約を解放する (残高拘束を解く。台帳には監査用の 0 行を残す) */
@@ -334,16 +475,24 @@ public function release(TicketReservation $reservation): void
     }
 
     /**
-     * TTL (expires_at) 超過の reserved 予約を解放する
-     * (routes/console.php の billing:release-stale-reservations が 5 分毎に実行)。
+     * TTL (expires_at) 超過、または失効 monthly hold (consume_expires_at 経過) の reserved 予約を
+     * 解放する (routes/console.php の billing:release-stale-reservations が 5 分毎に実行)。
+     *
+     * 失効 monthly hold を含めるのは、消費元の grant が既に失効している hold を拘束として
+     * 残すと翌期間の残高を侵食するため (commit-wins も当該 hold は no-charge にする)。
      *
      * @return int 解放した予約数
      */
     public function releaseStale(): int
     {
+        $now = CarbonImmutable::now();
+
         $staleIds = TicketReservation::query()
             ->where('status', TicketReservationStatus::Reserved)
-            ->where('expires_at', '<=', CarbonImmutable::now())
+            ->where(function (Builder $query) use ($now): void {
+                $query->where('expires_at', '<=', $now)
+                    ->orWhere(fn (Builder $expired) => $this->expiredMonthlyHoldCondition($expired, $now));
+            })
             ->pluck('id');
 
         $released = 0;
@@ -373,15 +522,21 @@ private function lockOrganizationRow(Organization $organization): void
             ->firstOrFail();
     }
 
-    /** 予約行をロックして reserved 状態であることを検証する (一方向遷移の強制) */
-    private function lockReservationRow(TicketReservation $reservation): TicketReservation
+    /**
+     * 予約行をロックする。
+     *
+     * $requireReserved = true (既定) は reserved 状態を検証する (release の一方向遷移の強制)。
+     * commit は commit-wins のため false で呼び、status 検査を行わない
+     * (二重課金は consume:{id} の UNIQUE が防ぐ)。
+     */
+    private function lockReservationRow(TicketReservation $reservation, bool $requireReserved = true): TicketReservation
     {
         $locked = TicketReservation::query()
             ->whereKey($reservation->getKey())
             ->lockForUpdate()
             ->firstOrFail();
 
-        if ($locked->status !== TicketReservationStatus::Reserved) {
+        if ($requireReserved && $locked->status !== TicketReservationStatus::Reserved) {
             throw new LogicException(
                 "予約 {$locked->id} は reserved ではありません (status: {$locked->status->value})",
             );
@@ -390,6 +545,146 @@ private function lockReservationRow(TicketReservation $reservation): TicketReser
         return $locked;
     }
 
+    /**
+     * 出所ごとの利用可能枚数 (生残高 − active hold を出所ごとに clamp)。
+     *
+     * monthly の余剰が purchased の負 (返金債務) を埋めない / その逆もしない。
+     * reserve() / availableTrueBalance() の単一定義点。
+     *
+     * @return array{int, int} [availableMonthly, availablePurchased]
+     */
+    private function availableBySource(Organization $organization, CarbonImmutable $now): array
+    {
+        $monthly = $this->sumBalance($organization, TicketSource::Monthly, $now);
+        $purchased = $this->sumBalance($organization, TicketSource::Purchased, $now);
+
+        return [
+            max($monthly - $this->sumActiveHolds($organization, TicketSource::Monthly, $now), 0),
+            max($purchased - $this->sumActiveHolds($organization, TicketSource::Purchased, $now), 0),
+        ];
+    }
+
+    /**
+     * 出所バケットの生残高 (未失効行の delta 合計。負を許容)。
+     *
+     * purchased バケットは `source IS NULL` 行を畳み込む。AI-CUE の台帳には出所を持たない行
+     * (P5 以前の消費行 / 手動 grant / adjustment / release) が既存し、台帳は append-only で
+     * backfill できないため。両バケットから落とすと過去消費が帳消しになり over-grant する
+     * (null 行はいずれも無期限で purchased と寿命特性が一致する)。
+     */
+    private function sumBalance(Organization $organization, TicketSource $source, CarbonImmutable $now): int
+    {
+        return (int) TicketLedgerEntry::query()
+            ->where('organization_id', $organization->getKey())
+            ->where(function (Builder $query) use ($source): void {
+                $query->where('source', $source);
+                if ($source === TicketSource::Purchased) {
+                    $query->orWhereNull('source');
+                }
+            })
+            ->where(function (Builder $query) use ($now): void {
+                $query->whereNull('expires_at')->orWhere('expires_at', '>', $now);
+            })
+            ->sum('delta');
+    }
+
+    /**
+     * 当該出所を消費する active hold の拘束枚数。
+     *
+     * reserve TTL 切れ (expires_at <= now) でも Reserved である限り枠を保持する: commit-wins は
+     * TTL 超過でも課金するため、与信側で枠を再開放すると 30 分超ジョブ中に同じ枠が二重予約され
+     * 両方 commit でオーバーセルになる。枠の解放は releaseStale の Released 化に委ねる。
+     * 失効 monthly hold のみ除外する (grant 自体が消えており commit-wins も no-charge のため)。
+     *
+     * legacy 行 (consume_source = null) はどちらの出所にも計上されない (aigenba verbatim)。
+     * その結果 legacy 行が reserve を拘束しない窓が TTL 30 分だけ開くが、balance() の
+     * activeReservations は legacy も計上するため表示は保守側になる。
+     */
+    private function sumActiveHolds(Organization $organization, TicketSource $source, CarbonImmutable $now): int
+    {
+        return (int) TicketReservation::query()
+            ->where('organization_id', $organization->getKey())
+            ->where('status', TicketReservationStatus::Reserved)
+            ->where('consume_source', $source)
+            ->whereNot(fn (Builder $query) => $this->expiredMonthlyHoldCondition($query, $now))
+            ->sum('amount');
+    }
+
+    /**
+     * 「失効 monthly hold」の PHP 述語。query 版 expiredMonthlyHoldCondition と同一定義を共有し、
+     * commit / hold 集計 / releaseStale の判定を揃える。
+     *
+     * legacy 行 (consume_source = null) は先頭で false になる。
+     * consume_source = monthly かつ consume_expires_at = null は「無期限 monthly からの消費」で、
+     * 失効しない (AI-CUE には無期限 monthly grant が実在するため空き枝をここに割り当てる)。
+     */
+    private function isExpiredMonthlyHold(TicketReservation $reservation, CarbonImmutable $now): bool
+    {
+        if ($reservation->consume_source !== TicketSource::Monthly) {
+            return false;
+        }
+        if ($reservation->consume_expires_at === null) {
+            return false;
+        }
+
+        return $reservation->consume_expires_at->lessThanOrEqualTo($now);
+    }
+
+    /**
+     * query 版「失効 monthly hold」条件。isExpiredMonthlyHold と同一定義。
+     *
+     * whereNotNull で確定 boolean にする (NULL 伝播で whereNot が 3 値論理 NULL になり
+     * legacy 行が誤って除外される事故を防ぐ)。
+     *
+     * @param  Builder<TicketReservation>  $query
+     */
+    private function expiredMonthlyHoldCondition(Builder $query, CarbonImmutable $now): void
+    {
+        $query->where('consume_source', TicketSource::Monthly->value)
+            ->whereNotNull('consume_expires_at')
+            ->where('consume_expires_at', '<=', $now);
+    }
+
+    /** 生きている (未失効の) monthly 付与のうち最短の失効時刻。無期限のみなら null */
+    private function nearestMonthlyExpiry(Organization $organization, CarbonImmutable $now): ?CarbonImmutable
+    {
+        $value = TicketLedgerEntry::query()
+            ->where('organization_id', $organization->getKey())
+            ->where('source', TicketSource::Monthly)
+            ->where('delta', '>', 0)
+            ->whereNotNull('expires_at')
+            ->where('expires_at', '>', $now)
+            ->orderBy('expires_at')
+            ->value('expires_at');
+
+        return $value instanceof CarbonInterface ? CarbonImmutable::instance($value) : null;
+    }
+
+    /**
+     * 消費行に載せる失効境界。
+     *
+     * monthly は予約時に固定した consume_expires_at をそのまま使う (再探索しない。
+     * null = 無期限 monthly)。legacy 行 (consume_source = null → monthly 既定) は予約 TTL を
+     * 境界として一回限り採用し、null-expiry の不滅ゴーストを作らない。purchased は無期限 (null)。
+     */
+    private function consumeExpiresAtFor(TicketReservation $reservation, TicketSource $source): ?CarbonImmutable
+    {
+        if ($source !== TicketSource::Monthly) {
+            return null;
+        }
+
+        if ($reservation->consume_source === null) {
+            Log::warning('ticket commit: legacy reservation without consume_source', [
+                'reservation_id' => $reservation->id,
+                'organization_id' => $reservation->organization_id,
+            ]);
+
+            return $reservation->expires_at;
+        }
+
+        return $reservation->consume_expires_at;
+    }
+
     /**
      * idempotency_key UNIQUE による冪等 insert (webhook 由来の付与・逆仕訳専用)。
      *
@@ -400,8 +695,9 @@ private function lockReservationRow(TicketReservation $reservation): TicketReser
      * insert のみ (update/delete なし) なので append-only 不変条件は保たれる。
      *
      * @param  array<string, mixed>  $attributes  DB 期待型へ正規化済みの列値 (enum は ->value、日時は Carbon 可)
+     * @return int 実際に挿入された行数 (0 = 冪等 skip)
      */
-    private function insertIdempotent(Organization $organization, string $idempotencyKey, array $attributes): void
+    private function insertIdempotent(Organization $organization, string $idempotencyKey, array $attributes): int
     {
         $now = CarbonImmutable::now();
         $row = [
@@ -418,7 +714,7 @@ private function insertIdempotent(Organization $organization, string $idempotenc
             $row,
         );
 
-        DB::table('ticket_ledger_entries')->insertOrIgnore($row);
+        return DB::table('ticket_ledger_entries')->insertOrIgnore($row);
     }
 
     /**
diff --git a/app/Services/Dashboard/DashboardService.php b/app/Services/Dashboard/DashboardService.php
index 23e5a93..a9056be 100644
--- a/app/Services/Dashboard/DashboardService.php
+++ b/app/Services/Dashboard/DashboardService.php
@@ -218,7 +218,7 @@ private function shootingTargets(Project $project): array
 
     private function billingSummary(Organization $organization): BillingSummaryData
     {
-        $balance = $this->tickets->balance($organization);
+        $balance = $this->tickets->balance($organization)->totalAvailable();
         $used = $this->storage->occupiedBytes($organization);
         $limit = $this->quota->limits($organization)[QuotaKey::MaxStorageBytes->value] ?? null;
         $percent = ($limit === null || $limit <= 0)
diff --git a/app/Services/Manual/AnalysisJobService.php b/app/Services/Manual/AnalysisJobService.php
index ab38cbd..84593c9 100644
--- a/app/Services/Manual/AnalysisJobService.php
+++ b/app/Services/Manual/AnalysisJobService.php
@@ -75,10 +75,12 @@ public function trigger(Project $project, VideoManual $manual, ?User $actor = nu
             if ($document === null) {
                 throw ValidationException::withMessages(['document' => ['手順書をアップロードしてください。']]);
             }
-            // 残高事前チェック (reserve はジョブ開始時 = §10.5。ここは fail-fast の入口ゲート)
+            // 残高事前チェック (reserve はジョブ開始時 = §10.5。ここは fail-fast の入口ゲート)。
+            // 判定は表示 clamp 済みの balance() ではなく真値 availableTrueBalance() を使う
+            // (返金債務で負に振れた出所を clamp が隠すと誤判定になる)
             $organization = $this->resolveOrganization($project);
             $cost = config()->integer('manual.analysis_ticket_cost');
-            $balance = $this->tickets->balance($organization);
+            $balance = $this->tickets->availableTrueBalance($organization);
             if ($balance < $cost) {
                 throw InsufficientTicketsException::forReserve($cost, $balance);
             }
diff --git a/app/Services/Manual/AnalysisPipeline.php b/app/Services/Manual/AnalysisPipeline.php
index 824c92c..60e76b3 100644
--- a/app/Services/Manual/AnalysisPipeline.php
+++ b/app/Services/Manual/AnalysisPipeline.php
@@ -219,7 +219,9 @@ private function finalize(AnalysisJob $job, GeneratedScenarioData $generated): b
             // ロック 3: reservation/org 行 (TicketLedgerService::commit 内部。savepoint)
             $reservation = $locked->ticketReservation;
             Assert::notNull($reservation, 'startJob が必ず予約を付けている');
-            // 非 Reserved は LogicException → terminal tx 全体 rollback (materialize も巻き戻る) → failJob
+            // commit-wins: TTL 超過や stale releaser 先着 (Released) でも生存 hold は課金する
+            // (二重課金は consume:{id} の UNIQUE が防ぐ)。失効 monthly hold のみ no-charge。
+            // 戻り値 (TicketCommitResult) は可観測性のためのもので分岐には使わない
             $this->tickets->commit($reservation);
 
             $locked->status = JobStatus::Succeeded;
diff --git a/app/Services/Manual/RenderJobService.php b/app/Services/Manual/RenderJobService.php
index 5c0bd1f..5852777 100644
--- a/app/Services/Manual/RenderJobService.php
+++ b/app/Services/Manual/RenderJobService.php
@@ -85,9 +85,11 @@ public function trigger(Project $project, VideoManual $manual, ?User $actor = nu
             $this->assertAllCutsHaveAdoptedReadyTakes($ordered);
             $this->assertTotalSourceDurationWithinLimit($ordered);
 
-            // 残高事前チェック (reserve はジョブ開始時 = §10.5。ここは fail-fast の入口ゲート)
+            // 残高事前チェック (reserve はジョブ開始時 = §10.5。ここは fail-fast の入口ゲート)。
+            // 判定は表示 clamp 済みの balance() ではなく真値 availableTrueBalance() を使う
+            // (返金債務で負に振れた出所を clamp が隠すと誤判定になる)
             $cost = config()->integer('manual.render_ticket_cost');
-            $balance = $this->tickets->balance($this->resolveOrganization($project));
+            $balance = $this->tickets->availableTrueBalance($this->resolveOrganization($project));
             if ($balance < $cost) {
                 throw InsufficientTicketsException::forReserve($cost, $balance);
             }
diff --git a/app/Services/Manual/RenderPipeline.php b/app/Services/Manual/RenderPipeline.php
index 606c648..cefbaad 100644
--- a/app/Services/Manual/RenderPipeline.php
+++ b/app/Services/Manual/RenderPipeline.php
@@ -291,7 +291,9 @@ private function finalize(RenderJob $job, RenderResult $result): bool
                 $this->jobs->completeRenderIntoLockedManual($lockedManual, $result);
 
                 // ロック 3: reservation/org 行 (TicketLedgerService::commit 内部)。
-                // 非 Reserved は LogicException → terminal tx 全体 rollback → failJob
+                // commit-wins: TTL 超過や stale releaser 先着 (Released) でも生存 hold は課金する
+                // (二重課金は consume:{id} の UNIQUE が防ぐ)。失効 monthly hold のみ no-charge。
+                // 戻り値 (TicketCommitResult) は可観測性のためのもので分岐には使わない
                 $reservation = $locked->ticketReservation;
                 Assert::notNull($reservation, 'startJob が必ず予約を付けている');
                 $this->tickets->commit($reservation);
diff --git a/database/factories/Billing/TicketReservationFactory.php b/database/factories/Billing/TicketReservationFactory.php
new file mode 100644
index 0000000..df59949
--- /dev/null
+++ b/database/factories/Billing/TicketReservationFactory.php
@@ -0,0 +1,80 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Database\Factories\Billing;
+
+use App\Enums\Billing\TicketReservationStatus;
+use App\Enums\Billing\TicketSource;
+use App\Models\Billing\TicketReservation;
+use App\Models\Organization;
+use Carbon\CarbonImmutable;
+use Illuminate\Database\Eloquent\Factories\Factory;
+
+/**
+ * 既定は purchased 消費の live な Reserved 予約 (TTL 未来)。
+ * legacy() で P5 デプロイ前の in-flight 予約 (consume_* = null) を、
+ * monthlyHold() / purchasedHold() で消費出所を、stale() で TTL 切れを作る。
+ *
+ * @extends Factory<TicketReservation>
+ */
+class TicketReservationFactory extends Factory
+{
+    protected $model = TicketReservation::class;
+
+    /**
+     * @return array<string, mixed>
+     */
+    public function definition(): array
+    {
+        return [
+            'organization_id' => Organization::factory(),
+            'amount' => 1,
+            'status' => TicketReservationStatus::Reserved,
+            'expires_at' => CarbonImmutable::now()->addMinutes(30),
+            'consume_source' => TicketSource::Purchased,
+            'consume_expires_at' => null,
+        ];
+    }
+
+    public function forOrganization(Organization $organization): static
+    {
+        return $this->state(fn (): array => ['organization_id' => $organization->getKey()]);
+    }
+
+    /** P5 デプロイ前の in-flight 予約 (consume_source / consume_expires_at とも null)。 */
+    public function legacy(): static
+    {
+        return $this->state(fn (): array => [
+            'consume_source' => null,
+            'consume_expires_at' => null,
+        ]);
+    }
+
+    /** monthly バケットからの消費予約。$consumeExpiresAt = null は無期限 monthly。 */
+    public function monthlyHold(?CarbonImmutable $consumeExpiresAt = null): static
+    {
+        return $this->state(fn (): array => [
+            'consume_source' => TicketSource::Monthly,
+            'consume_expires_at' => $consumeExpiresAt,
+        ]);
+    }
+
+    /** purchased バケット (無期限) からの消費予約。 */
+    public function purchasedHold(): static
+    {
+        return $this->state(fn (): array => [
+            'consume_source' => TicketSource::Purchased,
+            'consume_expires_at' => null,
+        ]);
+    }
+
+    /** TTL 切れ (status は reserved のまま expires_at が過去) の予約。 */
+    public function stale(): static
+    {
+        return $this->state(fn (): array => [
+            'status' => TicketReservationStatus::Reserved,
+            'expires_at' => CarbonImmutable::now()->subMinutes(31),
+        ]);
+    }
+}
diff --git a/database/migrations/2026_07_17_000500_add_consume_columns_to_ticket_reservations.php b/database/migrations/2026_07_17_000500_add_consume_columns_to_ticket_reservations.php
new file mode 100644
index 0000000..ea4761c
--- /dev/null
+++ b/database/migrations/2026_07_17_000500_add_consume_columns_to_ticket_reservations.php
@@ -0,0 +1,40 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\Schema;
+
+return new class extends Migration
+{
+    /**
+     * P5: チケット残高の per-source 会計 (aigenba TicketService verbatim) のための additive 2 列。
+     *
+     * 「消費する期間 = 予約した期間」を予約行へ固定し、commit が再探索しないようにする。
+     * - consume_source: 予約が消費する出所 (monthly | purchased。App\Enums\Billing\TicketSource)
+     * - consume_expires_at: monthly 消費の失効境界 (null = 無期限 monthly または legacy)
+     *
+     * **backfill しない**。デプロイ時に in-flight だった既存 Reserved 行は 2 列 null (= legacy)
+     * のまま残し、誤配賦を固定しない / 並行 reserve と競合させない。legacy 行の扱いは
+     * TicketLedgerService::commit (consume_source ?? Monthly + 予約 TTL 境界) が担い、
+     * 5 分 cron の releaseStale が TTL 30 分で window を終息させる。
+     *
+     * **新規 index を追加しない**: hold 集計は既存 ['organization_id','status']、
+     * releaseStale は既存 ['status','expires_at'] で覆われる (予約行は org あたり TTL 30 分の少数)。
+     */
+    public function up(): void
+    {
+        Schema::table('ticket_reservations', function (Blueprint $table) {
+            $table->string('consume_source')->nullable()->after('amount');
+            $table->timestamp('consume_expires_at')->nullable()->after('consume_source');
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::table('ticket_reservations', function (Blueprint $table) {
+            $table->dropColumn(['consume_source', 'consume_expires_at']);
+        });
+    }
+};
diff --git a/docs/factories.md b/docs/factories.md
index 0115ded..83d979e 100644
--- a/docs/factories.md
+++ b/docs/factories.md
@@ -39,6 +39,7 @@ ## Factory 一覧 (テンプレート同梱)
 | `ModelAuditFactory` | ModelAudit | — (auditable は Item 既定。派生アプリは state で上書き) |
 | `Billing\BillingNotificationFactory` | Billing/BillingNotification | `forOrganization($org)`, `reminder(?string $dedupKey = null)` (dedup_key 経路), `sent()`, `failed()` |
 | `Billing\TicketCheckoutSessionFactory` | Billing/TicketCheckoutSession | `forOrganization($org)`, `initiatedBy($user)`, `completed()`, `expired()`, `stale()` (pending のまま expires_at 過去) |
+| `Billing\TicketReservationFactory` | Billing/TicketReservation | `forOrganization($org)`, `legacy()` (P5 前の in-flight 予約 = `consume_*` null), `monthlyHold(?CarbonImmutable $consumeExpiresAt = null)`, `purchasedHold()`, `stale()` (reserved のまま TTL 超過) |
 | `Billing\BillingCheckoutSessionFactory` | Billing/BillingCheckoutSession | `withAttemptToken($token, ?$checkoutUrl)`, `initiatedBy(int $userId)`, `completed()`, `setupPaymentMethod()`, `expired()`, `failed()`, `stale()` (pending のまま created_at が stale 境界より過去) |
 
 Factory を持たないモデル (Role / Permission / Team 等) は seed 固定値
```

## テスト差分

```diff
diff --git a/tests/Feature/Auth/RegistrationInvitationPrefillTest.php b/tests/Feature/Auth/RegistrationInvitationPrefillTest.php
index d6e48b4..83471b0 100644
--- a/tests/Feature/Auth/RegistrationInvitationPrefillTest.php
+++ b/tests/Feature/Auth/RegistrationInvitationPrefillTest.php
@@ -175,7 +175,7 @@ function makeInvitationWithToken(string $email = 'invitee@example.com'): array
 
     // 個人組織が生成され signup grant 済み
     $personalOrg = $user->organizations()->where('is_personal', true)->firstOrFail();
-    expect(app(TicketLedgerService::class)->balance($personalOrg))
+    expect(app(TicketLedgerService::class)->balance($personalOrg)->totalAvailable())
         ->toBe(config()->integer('billing.signup_grant_tickets'));
 
     // current_organization_id は個人組織側 (招待組織側でない)
diff --git a/tests/Feature/Auth/RegistrationTest.php b/tests/Feature/Auth/RegistrationTest.php
index 2ad8013..197dbb0 100644
--- a/tests/Feature/Auth/RegistrationTest.php
+++ b/tests/Feature/Auth/RegistrationTest.php
@@ -26,7 +26,7 @@
     // LP が約束する「新規登録で無償チケット」を個人組織へ付与する。
     // 固定値ではなく config 由来値を期待に使う (設定変更後も意味が一貫する)。
     $personalOrg = $user->organizations()->where('is_personal', true)->firstOrFail();
-    expect(app(TicketLedgerService::class)->balance($personalOrg))
+    expect(app(TicketLedgerService::class)->balance($personalOrg)->totalAvailable())
         ->toBe(config()->integer('billing.signup_grant_tickets'));
 
     // [分岐 B 固定] 通常登録では現在組織が個人組織に確定する (招待成立分岐と排他)
diff --git a/tests/Feature/Billing/PersonalPlanServiceTest.php b/tests/Feature/Billing/PersonalPlanServiceTest.php
index c43f647..fe498b7 100644
--- a/tests/Feature/Billing/PersonalPlanServiceTest.php
+++ b/tests/Feature/Billing/PersonalPlanServiceTest.php
@@ -29,7 +29,7 @@ function personalPlanService(): PersonalPlanService
 
 function personalPlanBalance(Organization $organization): int
 {
-    return app(TicketLedgerService::class)->balance($organization);
+    return app(TicketLedgerService::class)->balance($organization)->totalAvailable();
 }
 
 function signupGrantEntryCount(Organization $organization): int
diff --git a/tests/Feature/Billing/SeededFreePlanBillingAccessTest.php b/tests/Feature/Billing/SeededFreePlanBillingAccessTest.php
index f8a106a..25155b3 100644
--- a/tests/Feature/Billing/SeededFreePlanBillingAccessTest.php
+++ b/tests/Feature/Billing/SeededFreePlanBillingAccessTest.php
@@ -11,29 +11,32 @@
 use Illuminate\Support\Str;
 
 /*
- * F-C3 回帰: ManualTestSeeder が生成する Free (Stripe Price 無し) プラン組織の全ロールが、
+ * F-C3 回帰: ManualTestSeeder が生成する無料 (Stripe Price 無し) プラン組織の全ロールが、
  * 課金ゲート (require-active-subscription) を素通りして中核業務 route に到達できることを固定する。
- * 根本原因は seeder が Free にも plan_code='free' を載せ、BillingAccess が active subscription を
+ * 根本原因は seeder が無料プランにも plan_code を載せ、BillingAccess が active subscription を
  * 要求して締め出していたこと (devnotes/20260713-1633-seeder-free-plan-billing)。
+ *
+ * P4 (T075) で plans から 'free' 行が撤去され、無料枠は organizations.free_plan_code='personal'
+ * の明示申告になった。よって本テストの対象プランは 'free' → 'personal' へ読み替える
+ * (関心は「Price 無しプラン組織がゲートを素通りできるか」であり、プラン名ではない)。
  */
 
 /**
- * Free プラン (current base Price を持たない) を取得する。
+ * 無料プラン (current base Price を持たない) を取得する。
  *
- * personal も Price を持たないため「Price 無しの最初の Plan」では対象が非決定になる。
- * 本テストの関心は Free プラン組織のゲート素通りなので code で固定する。
+ * 「Price 無しの最初の Plan」では対象が非決定になりうるため code で固定する。
  */
 function seededFreePlan(): Plan
 {
-    $plan = Plan::query()->where('code', 'free')->firstOrFail();
+    $plan = Plan::query()->where('code', 'personal')->firstOrFail();
     if ($plan->currentPrice(PlanPriceKind::Base) !== null) {
-        throw new RuntimeException('Free プランに Price が付いている (seed 不変条件の破れ)');
+        throw new RuntimeException('無料プランに Price が付いている (seed 不変条件の破れ)');
     }
 
     return $plan;
 }
 
-test('seeded Free 組織の全ロールが /projects に到達できる (F-C3 回帰)', function (OrganizationRole $role): void {
+test('seeded 無料プラン組織の全ロールが /projects に到達できる (F-C3 回帰)', function (OrganizationRole $role): void {
     $this->seed(ManualTestSeeder::class);
 
     $plan = seededFreePlan();
diff --git a/tests/Feature/Billing/SignupGrantOncePerOrgTest.php b/tests/Feature/Billing/SignupGrantOncePerOrgTest.php
index 787a6ee..b820a66 100644
--- a/tests/Feature/Billing/SignupGrantOncePerOrgTest.php
+++ b/tests/Feature/Billing/SignupGrantOncePerOrgTest.php
@@ -74,7 +74,7 @@ function grantOnceSignupEntryCount(Organization $organization): int
     $organization = $user->organizations()->where('is_personal', true)->firstOrFail();
 
     // 付与契機・枚数は不変 (現行挙動)
-    expect(app(TicketLedgerService::class)->balance($organization))
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())
         ->toBe(config()->integer('billing.signup_grant_tickets'));
     expect($organization->ticketLedgerEntries()->firstOrFail()->idempotency_key)
         ->toBe("signup_grant:org:{$organization->id}");
@@ -93,12 +93,12 @@ function grantOnceSignupEntryCount(Organization $organization): int
 
     $user = User::whereBlind('email', 'email_index', 'grant-once-2@example.com')->firstOrFail();
     $organization = $user->organizations()->where('is_personal', true)->firstOrFail();
-    $balanceBefore = app(TicketLedgerService::class)->balance($organization);
+    $balanceBefore = app(TicketLedgerService::class)->balance($organization)->totalAvailable();
 
     $result = app(PersonalPlanService::class)->activate($organization, $user);
 
     expect($result->granted)->toBeFalse();
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe($balanceBefore);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe($balanceBefore);
     expect(grantOnceSignupEntryCount($organization))->toBe(1);
 });
 
@@ -117,13 +117,13 @@ function grantOnceSignupEntryCount(Organization $organization): int
 
     app(PersonalPlanService::class)->activate($organization, $owner);
     expect(grantOnceSignupEntryCount($organization))->toBe(1);
-    $balanceBefore = app(TicketLedgerService::class)->balance($organization);
+    $balanceBefore = app(TicketLedgerService::class)->balance($organization)->totalAvailable();
 
     event(new WebhookReceived(grantOnceInvoicePaidPayload()));
 
     // 部分 UNIQUE index が経路 (signup_grant:personal:% ↔ signup_grant:org:%) を跨いで弾く
     expect(grantOnceSignupEntryCount($organization))->toBe(1);
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe($balanceBefore);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe($balanceBefore);
 });
 
 test('paid webhook で付与済みの組織を free 有効化しても二重付与しない (逆順)', function (): void {
@@ -132,7 +132,7 @@ function grantOnceSignupEntryCount(Organization $organization): int
 
     event(new WebhookReceived(grantOnceInvoicePaidPayload()));
     expect(grantOnceSignupEntryCount($organization))->toBe(1);
-    $balanceBefore = app(TicketLedgerService::class)->balance($organization);
+    $balanceBefore = app(TicketLedgerService::class)->balance($organization)->totalAvailable();
 
     // paid webhook 経路も移行期規約 (marker 先取できたときのみ付与) に従うため、webhook 時点で
     // マーカーが立つ。よって後続の activate はマーカーを先取できず granted=false になる
@@ -143,7 +143,7 @@ function grantOnceSignupEntryCount(Organization $organization): int
 
     expect($result->granted)->toBeFalse();
     expect(grantOnceSignupEntryCount($organization))->toBe(1);
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe($balanceBefore);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe($balanceBefore);
 });
 
 test('登録経由でない組織の初回契約 (paid webhook) でもマーカーが立つ (付与実績と真実源が一致する)', function (): void {
diff --git a/tests/Feature/Billing/TicketAmountBasedReserveTest.php b/tests/Feature/Billing/TicketAmountBasedReserveTest.php
new file mode 100644
index 0000000..6c71d57
--- /dev/null
+++ b/tests/Feature/Billing/TicketAmountBasedReserveTest.php
@@ -0,0 +1,72 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\TicketLedgerKind;
+use App\Enums\Billing\TicketReservationStatus;
+use App\Models\Billing\TicketLedgerEntry;
+use App\Services\Billing\TicketLedgerService;
+
+/*
+ * P5 で維持する AI-CUE 固有の逸脱 (AGENTS.md 不変条件 #7) の回帰網。
+ * - amount ベース reserve (aigenba の 1 枚固定に退化していない)
+ * - reserve → commit / release の 2 フェーズ (直接デクリメントを書かない)
+ */
+
+function amountBasedService(): TicketLedgerService
+{
+    return app(TicketLedgerService::class);
+}
+
+test('reserve は amount 枚をまとめて 1 行の予約にする (1 枚固定に退化しない)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    amountBasedService()->grant($organization, 10, '初期付与');
+
+    $reservation = amountBasedService()->reserve($organization, 5);
+
+    expect($organization->ticketReservations()->count())->toBe(1);
+    expect($reservation->amount)->toBe(5);
+    expect($reservation->status)->toBe(TicketReservationStatus::Reserved);
+    expect(amountBasedService()->balance($organization)->activeReservations)->toBe(5);
+});
+
+test('解析 / レンダの可変コストがそれぞれの枚数で reserve → commit される', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    amountBasedService()->grant($organization, 10, '初期付与');
+
+    $analysisCost = config()->integer('manual.analysis_ticket_cost');
+    $renderCost = config()->integer('manual.render_ticket_cost');
+    expect($analysisCost)->not->toBe($renderCost); // 可変コスト前提が失われていない
+
+    amountBasedService()->commit(amountBasedService()->reserve($organization, $analysisCost));
+    amountBasedService()->commit(amountBasedService()->reserve($organization, $renderCost));
+
+    $deltas = TicketLedgerEntry::query()
+        ->where('organization_id', $organization->getKey())
+        ->where('kind', TicketLedgerKind::ReserveCommit)
+        ->orderBy('id')
+        ->pluck('delta')
+        ->all();
+    expect($deltas)->toBe([-$analysisCost, -$renderCost]);
+    expect(amountBasedService()->balance($organization)->totalAvailable())
+        ->toBe(10 - $analysisCost - $renderCost);
+});
+
+test('reserve → release は台帳を減らさない (直接デクリメントが無い)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    amountBasedService()->grant($organization, 10, '初期付与');
+
+    $reservation = amountBasedService()->reserve($organization, 4);
+    expect(amountBasedService()->balance($organization)->totalAvailable())->toBe(6);
+
+    amountBasedService()->release($reservation);
+
+    expect($reservation->refresh()->status)->toBe(TicketReservationStatus::Released);
+    expect(amountBasedService()->balance($organization)->totalAvailable())->toBe(10);
+    // 監査痕跡は delta=0 の release 行のみ (負 delta を書かない)
+    $release = TicketLedgerEntry::query()
+        ->where('organization_id', $organization->getKey())
+        ->where('kind', TicketLedgerKind::Release)
+        ->firstOrFail();
+    expect($release->delta)->toBe(0);
+});
diff --git a/tests/Feature/Billing/TicketBalanceAccountingTest.php b/tests/Feature/Billing/TicketBalanceAccountingTest.php
new file mode 100644
index 0000000..d8b897a
--- /dev/null
+++ b/tests/Feature/Billing/TicketBalanceAccountingTest.php
@@ -0,0 +1,147 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Billing\TicketBalanceDto;
+use App\Enums\Billing\TicketLedgerKind;
+use App\Enums\Billing\TicketSource;
+use App\Models\Billing\TicketLedgerEntry;
+use App\Services\Billing\TicketLedgerService;
+use Carbon\CarbonImmutable;
+
+/*
+ * P5: per-source 会計 (aigenba TicketService::balance verbatim 移植)。
+ * バケット = monthly (source=monthly) / purchased (source=purchased ∪ source IS NULL)。
+ * 消費行に grant と同じ expires_at を載せることで「+grant と −consume が同時に落ちる」。
+ */
+
+function accountingService(): TicketLedgerService
+{
+    return app(TicketLedgerService::class);
+}
+
+test('期限付き monthly の grant と消費が同時に失効する (全額失効近似の解消)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $expiresAt = CarbonImmutable::now()->addDays(30);
+    accountingService()->grantMonthly($organization, 10, $expiresAt, 'monthly:1', '月次付与');
+
+    $reservation = accountingService()->reserve($organization, 3);
+    accountingService()->commit($reservation);
+
+    // 消費行は grant と同じ失効境界を持つ
+    $consume = TicketLedgerEntry::query()
+        ->where('organization_id', $organization->getKey())
+        ->where('kind', TicketLedgerKind::ReserveCommit)
+        ->firstOrFail();
+    expect($consume->source)->toBe(TicketSource::Monthly);
+    expect($consume->expires_at?->toIso8601String())->toBe($expiresAt->toIso8601String());
+
+    expect(accountingService()->balance($organization)->monthlyRemaining)->toBe(7);
+
+    // 期限到達で +10 と -3 が同時に合算から落ちる (現行実装なら -3 が残り -3 になる)
+    $this->travelTo($expiresAt->addMinute());
+    $balance = accountingService()->balance($organization);
+    expect($balance->monthlyRemaining)->toBe(0);
+    expect($balance->totalAvailable())->toBe(0);
+});
+
+test('balance は per-source DTO を返し debt フィールドを持たない', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    accountingService()->grant($organization, 5, '手動付与');
+
+    $balance = accountingService()->balance($organization);
+
+    expect($balance)->toBeInstanceOf(TicketBalanceDto::class);
+    expect(array_keys($balance->toArray()))->toBe([
+        'monthlyRemaining',
+        'purchasedRemaining',
+        'totalAvailable',
+        'activeReservations',
+        'nextExpireAt',
+    ]);
+});
+
+test('per-source clamp: purchased の債務を monthly が肩代わりも打ち消しもしない', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    // purchased を -2 にする (返金逆仕訳相当の負計上)
+    accountingService()->grantPurchased($organization, 3, 'cs_clamp', 'pi_clamp', 3000);
+    $reservation = accountingService()->reserve($organization, 3);
+    accountingService()->commit($reservation);
+    accountingService()->clawbackPurchasedByPaymentIntent('pi_clamp', 3000);
+
+    accountingService()->grantMonthly($organization, 10, CarbonImmutable::now()->addDays(30), 'monthly:clamp', '月次付与');
+
+    $balance = accountingService()->balance($organization);
+    expect($balance->purchasedRemaining)->toBe(0); // max(-3, 0)
+    expect($balance->monthlyRemaining)->toBe(10);
+    expect($balance->totalAvailable())->toBe(10);
+
+    // 台帳側では債務が保全されている (clamp は表示・与信のみ)
+    $purchasedNetRaw = (int) TicketLedgerEntry::query()
+        ->where('organization_id', $organization->getKey())
+        ->where('source', TicketSource::Purchased)
+        ->sum('delta');
+    expect($purchasedNetRaw)->toBe(-3);
+});
+
+test('source IS NULL の台帳行は purchased バケットへ畳まれる (過去消費が帳消しにならない)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    accountingService()->grantPurchased($organization, 10, 'cs_null', 'pi_null', 10000);
+
+    // P5 以前の消費行相当 (source = null) を append-only で 1 行足す
+    $legacyConsume = new TicketLedgerEntry;
+    $legacyConsume->organization()->associate($organization);
+    $legacyConsume->delta = -4;
+    $legacyConsume->kind = TicketLedgerKind::ReserveCommit;
+    $legacyConsume->description = 'P5 以前の消費行 (source なし)';
+    $legacyConsume->save();
+
+    $balance = accountingService()->balance($organization);
+    expect($balance->purchasedRemaining)->toBe(6); // 帳消しにせず purchased へ畳む
+    expect($balance->totalAvailable())->toBe(6);
+});
+
+test('nextExpireAt は未失効・正 delta の最短 expires_at (ISO8601)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $near = CarbonImmutable::now()->addDays(10);
+    $far = CarbonImmutable::now()->addDays(30);
+    accountingService()->grantMonthly($organization, 5, $far, 'monthly:far', '遠い期限');
+    accountingService()->grantMonthly($organization, 5, $near, 'monthly:near', '近い期限');
+    accountingService()->grantPurchased($organization, 5, 'cs_inf', 'pi_inf', 5000); // 無期限は対象外
+
+    expect(accountingService()->balance($organization)->nextExpireAt)
+        ->toBe($near->toIso8601String());
+});
+
+test('activeReservations は拘束枚数 (SUM(amount))', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    accountingService()->grant($organization, 10, '初期付与');
+
+    accountingService()->reserve($organization, 3);
+    accountingService()->reserve($organization, 2);
+
+    $balance = accountingService()->balance($organization);
+    expect($balance->activeReservations)->toBe(5); // count(2) ではなく枚数
+    expect($balance->totalAvailable())->toBe(5);
+});
+
+test('無期限 monthly grant のみの組織でも reserve が例外にならず consume_expires_at が null で固定される', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    accountingService()->grantMonthly($organization, 100, null, 'monthly:infinite', '無期限月次付与');
+
+    $reservation = accountingService()->reserve($organization, 3);
+
+    expect($reservation->consume_source)->toBe(TicketSource::Monthly);
+    expect($reservation->consume_expires_at)->toBeNull();
+});
+
+test('availableTrueBalance は per-source clamp 後の合算で常に 0 以上', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    accountingService()->grantPurchased($organization, 3, 'cs_true', 'pi_true', 3000);
+    $reservation = accountingService()->reserve($organization, 3);
+    accountingService()->commit($reservation);
+    accountingService()->clawbackPurchasedByPaymentIntent('pi_true', 3000);
+    accountingService()->grantMonthly($organization, 10, CarbonImmutable::now()->addDays(30), 'monthly:true', '月次付与');
+
+    expect(accountingService()->availableTrueBalance($organization))->toBe(10);
+});
diff --git a/tests/Feature/Billing/TicketCommitWinsTest.php b/tests/Feature/Billing/TicketCommitWinsTest.php
new file mode 100644
index 0000000..9160bed
--- /dev/null
+++ b/tests/Feature/Billing/TicketCommitWinsTest.php
@@ -0,0 +1,98 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\TicketCommitResult;
+use App\Enums\Billing\TicketLedgerKind;
+use App\Enums\Billing\TicketReservationStatus;
+use App\Models\Billing\TicketLedgerEntry;
+use App\Services\Billing\TicketLedgerService;
+use Carbon\CarbonImmutable;
+
+/*
+ * P5: commit-wins (aigenba TicketService::commit verbatim)。
+ * TTL 切れ / stale releaser 先着でも生存 hold は課金する。二重課金は
+ * idempotency_key `consume:{reservationId}` UNIQUE が防ぐ。
+ * 失効 monthly hold のみ決定的 no-charge (ReleasedExpired)。
+ */
+
+function commitWinsService(): TicketLedgerService
+{
+    return app(TicketLedgerService::class);
+}
+
+test('TTL 切れで Released 化された生存予約でも commit は課金する (commit-wins)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    commitWinsService()->grantPurchased($organization, 10, 'cs_wins', 'pi_wins', 10000);
+
+    $reservation = commitWinsService()->reserve($organization, 3);
+    $this->travel(31)->minutes();
+    commitWinsService()->releaseStale();
+    expect($reservation->refresh()->status)->toBe(TicketReservationStatus::Released);
+
+    $result = commitWinsService()->commit($reservation);
+
+    expect($result)->toBe(TicketCommitResult::Committed);
+    expect($reservation->refresh()->status)->toBe(TicketReservationStatus::Released); // 一方向遷移は壊さない
+    expect(commitWinsService()->balance($organization)->totalAvailable())->toBe(7); // 課金は台帳が真実源
+});
+
+test('再 commit は AlreadyCommitted で消費行は 1 行のみ', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    commitWinsService()->grantPurchased($organization, 10, 'cs_again', 'pi_again', 10000);
+
+    $reservation = commitWinsService()->reserve($organization, 3);
+    expect(commitWinsService()->commit($reservation))->toBe(TicketCommitResult::Committed);
+    expect(commitWinsService()->commit($reservation))->toBe(TicketCommitResult::AlreadyCommitted);
+
+    $consumes = TicketLedgerEntry::query()
+        ->where('organization_id', $organization->getKey())
+        ->where('kind', TicketLedgerKind::ReserveCommit)
+        ->get();
+    expect($consumes)->toHaveCount(1);
+    expect(commitWinsService()->balance($organization)->totalAvailable())->toBe(7);
+});
+
+test('失効した monthly hold の commit は課金せず ReleasedExpired', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $expiresAt = CarbonImmutable::now()->addDays(30);
+    commitWinsService()->grantMonthly($organization, 10, $expiresAt, 'monthly:expired', '月次付与');
+
+    $reservation = commitWinsService()->reserve($organization, 3);
+    $this->travelTo($expiresAt->addMinute());
+
+    $result = commitWinsService()->commit($reservation);
+
+    expect($result)->toBe(TicketCommitResult::ReleasedExpired);
+    expect($reservation->refresh()->status)->toBe(TicketReservationStatus::Released);
+    expect(TicketLedgerEntry::query()
+        ->where('organization_id', $organization->getKey())
+        ->where('kind', TicketLedgerKind::ReserveCommit)
+        ->count())->toBe(0);
+});
+
+test('無期限 monthly 予約は TTL 経過後も ReleasedExpired にならず課金される', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    commitWinsService()->grantMonthly($organization, 10, null, 'monthly:inf-commit', '無期限月次付与');
+
+    $reservation = commitWinsService()->reserve($organization, 3);
+    $this->travel(31)->minutes();
+
+    expect(commitWinsService()->commit($reservation))->toBe(TicketCommitResult::Committed);
+    expect(commitWinsService()->balance($organization)->totalAvailable())->toBe(7);
+});
+
+test('releaseStale は TTL 未超過でも失効 monthly hold を解放する', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    // monthly 期限 (10 分後) < reserve TTL (30 分) にして「TTL 切れ」枝と切り分ける
+    $expiresAt = CarbonImmutable::now()->addMinutes(10);
+    commitWinsService()->grantMonthly($organization, 10, $expiresAt, 'monthly:stale', '月次付与');
+
+    $reservation = commitWinsService()->reserve($organization, 3);
+    expect($reservation->consume_expires_at?->toIso8601String())->toBe($expiresAt->toIso8601String());
+
+    $this->travel(11)->minutes(); // TTL (30 分) は未超過だが monthly hold は失効
+
+    expect(commitWinsService()->releaseStale())->toBe(1);
+    expect($reservation->refresh()->status)->toBe(TicketReservationStatus::Released);
+});
diff --git a/tests/Feature/Billing/TicketConsumeOrderTest.php b/tests/Feature/Billing/TicketConsumeOrderTest.php
new file mode 100644
index 0000000..e5d020e
--- /dev/null
+++ b/tests/Feature/Billing/TicketConsumeOrderTest.php
@@ -0,0 +1,100 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\TicketLedgerKind;
+use App\Enums\Billing\TicketSource;
+use App\Exceptions\Billing\InsufficientTicketsException;
+use App\Models\Billing\TicketLedgerEntry;
+use App\Models\Organization;
+use App\Services\Billing\TicketLedgerService;
+use Carbon\CarbonImmutable;
+
+/*
+ * P5: 消費優先 (monthly → purchased) と単一 consume_source の容量ガード。
+ * aigenba TicketService::reserve verbatim + amount 一般化。
+ */
+
+function consumeOrderService(): TicketLedgerService
+{
+    return app(TicketLedgerService::class);
+}
+
+function sourceNet(Organization $organization, ?TicketSource $source): int
+{
+    $query = TicketLedgerEntry::query()->where('organization_id', $organization->getKey());
+    $query = $source === null ? $query->whereNull('source') : $query->where('source', $source);
+
+    return (int) $query->sum('delta');
+}
+
+test('monthly が賄えるうちは monthly から消費し最短 monthly 期限を固定する', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $monthlyExpiry = CarbonImmutable::now()->addDays(30);
+    consumeOrderService()->grantMonthly($organization, 10, $monthlyExpiry, 'monthly:order', '月次付与');
+    consumeOrderService()->grantPurchased($organization, 10, 'cs_order', 'pi_order', 10000);
+
+    $reservation = consumeOrderService()->reserve($organization, 3);
+
+    expect($reservation->consume_source)->toBe(TicketSource::Monthly);
+    expect($reservation->consume_expires_at?->toIso8601String())->toBe($monthlyExpiry->toIso8601String());
+});
+
+test('monthly を使い切ると purchased から消費し consume_expires_at は null', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    consumeOrderService()->grantMonthly($organization, 3, CarbonImmutable::now()->addDays(30), 'monthly:used', '月次付与');
+    consumeOrderService()->grantPurchased($organization, 10, 'cs_used', 'pi_used', 10000);
+
+    $first = consumeOrderService()->reserve($organization, 3);
+    consumeOrderService()->commit($first);
+
+    $second = consumeOrderService()->reserve($organization, 3);
+
+    expect($second->consume_source)->toBe(TicketSource::Purchased);
+    expect($second->consume_expires_at)->toBeNull();
+});
+
+test('commit は単一 source の消費行を 1 行だけ書く (source ごとの分割をしない)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    consumeOrderService()->grantMonthly($organization, 10, CarbonImmutable::now()->addDays(30), 'monthly:single', '月次付与');
+    consumeOrderService()->grantPurchased($organization, 10, 'cs_single', 'pi_single', 10000);
+
+    $reservation = consumeOrderService()->reserve($organization, 3);
+    consumeOrderService()->commit($reservation);
+
+    $consumes = TicketLedgerEntry::query()
+        ->where('organization_id', $organization->getKey())
+        ->where('kind', TicketLedgerKind::ReserveCommit)
+        ->get();
+
+    expect($consumes)->toHaveCount(1);
+    expect($consumes->firstOrFail()->delta)->toBe(-3);
+    expect($consumes->firstOrFail()->source)->toBe(TicketSource::Monthly);
+});
+
+test('単一 source 容量ガード: どちらの source も単独で賄えない reserve は不足 (タダ配りを作らない)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    consumeOrderService()->grantMonthly($organization, 2, CarbonImmutable::now()->addDays(30), 'monthly:cap', '月次付与');
+    consumeOrderService()->grantPurchased($organization, 2, 'cs_cap', 'pi_cap', 2000);
+
+    expect(fn () => consumeOrderService()->reserve($organization, 3))
+        ->toThrow(InsufficientTicketsException::class, '残高: 2'); // max(2, 2)
+
+    expect($organization->ticketReservations()->count())->toBe(0);
+    // 台帳は無傷 (超過消費が clamp に隠れていない)
+    expect(sourceNet($organization, TicketSource::Purchased))->toBe(2);
+    expect(sourceNet($organization, TicketSource::Monthly))->toBe(2);
+});
+
+test('availableTrueBalance は monthly が purchased の債務を埋めない真値', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    consumeOrderService()->grantPurchased($organization, 5, 'cs_debt', 'pi_debt', 5000);
+    $reservation = consumeOrderService()->reserve($organization, 2);
+    consumeOrderService()->commit($reservation);
+    consumeOrderService()->clawbackPurchasedByPaymentIntent('pi_debt', 5000); // purchased = -2
+
+    consumeOrderService()->grantMonthly($organization, 10, CarbonImmutable::now()->addDays(30), 'monthly:debt', '月次付与');
+
+    expect(consumeOrderService()->availableTrueBalance($organization))->toBe(10);
+    expect(sourceNet($organization, TicketSource::Purchased))->toBe(-2); // 債務は台帳で保全
+});
diff --git a/tests/Feature/Billing/TicketGrantTest.php b/tests/Feature/Billing/TicketGrantTest.php
index d1b3536..746bf5f 100644
--- a/tests/Feature/Billing/TicketGrantTest.php
+++ b/tests/Feature/Billing/TicketGrantTest.php
@@ -25,7 +25,7 @@ function grantService(): TicketLedgerService
     grantService()->grantMonthly($organization, 100, null, 'monthly:in_1', '月次付与');
     grantService()->grantMonthly($organization, 100, null, 'monthly:in_1', '月次付与');
 
-    expect(grantService()->balance($organization))->toBe(100);
+    expect(grantService()->balance($organization)->totalAvailable())->toBe(100);
     expect($organization->ticketLedgerEntries()->count())->toBe(1);
     $entry = $organization->ticketLedgerEntries()->firstOrFail();
     expect($entry->kind)->toBe(TicketLedgerKind::Grant);
@@ -40,7 +40,7 @@ function grantService(): TicketLedgerService
     grantService()->grantMonthly($organization, 100, null, 'monthly:in_1', '月次付与');
     grantService()->grantMonthly($organization, 100, null, 'monthly:in_2', '月次付与');
 
-    expect(grantService()->balance($organization))->toBe(200);
+    expect(grantService()->balance($organization)->totalAvailable())->toBe(200);
 });
 
 test('期限付き付与は expires_at 到達で残高から外れる', function (): void {
@@ -55,12 +55,12 @@ function grantService(): TicketLedgerService
     );
     grantService()->grantMonthly($organization, 5, null, 'monthly:in_perm', '無期限付与');
 
-    expect(grantService()->balance($organization))->toBe(15);
+    expect(grantService()->balance($organization)->totalAvailable())->toBe(15);
 
     $this->travel(31)->days();
 
     // 期限付き 10 枚だけが失効し、無期限 5 枚が残る
-    expect(grantService()->balance($organization))->toBe(5);
+    expect(grantService()->balance($organization)->totalAvailable())->toBe(5);
 });
 
 test('期限内の付与は reserve → commit で消費できる', function (): void {
@@ -76,7 +76,7 @@ function grantService(): TicketLedgerService
     $reservation = grantService()->reserve($organization, 3);
     grantService()->commit($reservation);
 
-    expect(grantService()->balance($organization))->toBe(7);
+    expect(grantService()->balance($organization)->totalAvailable())->toBe(7);
 });
 
 test('grantSignupGrant は config の枚数・期限で org スコープキーで冪等付与する', function (): void {
@@ -88,7 +88,7 @@ function grantService(): TicketLedgerService
     grantService()->grantSignupGrant($organization, "signup_grant:org:{$organization->id}");
     grantService()->grantSignupGrant($organization, "signup_grant:org:{$organization->id}");
 
-    expect(grantService()->balance($organization))->toBe(10);
+    expect(grantService()->balance($organization)->totalAvailable())->toBe(10);
     expect($organization->ticketLedgerEntries()->count())->toBe(1);
     $entry = $organization->ticketLedgerEntries()->firstOrFail();
     expect($entry->source)->toBe(TicketSource::Monthly);
@@ -98,7 +98,7 @@ function grantService(): TicketLedgerService
 
     // 期限到達で失効する
     $this->travel(31)->days();
-    expect(grantService()->balance($organization))->toBe(0);
+    expect(grantService()->balance($organization)->totalAvailable())->toBe(0);
 });
 
 test('grantSignupGrant は config が不正 (0 以下) なら停止する', function (): void {
@@ -133,7 +133,7 @@ function grantService(): TicketLedgerService
 
     expect($organization->ticketLedgerEntries()
         ->where('idempotency_key', 'like', 'signup_grant:%')->count())->toBe(1);
-    expect($svc->balance($organization))->toBe(config('billing.signup_grant_tickets'));
+    expect($svc->balance($organization)->totalAvailable())->toBe(config('billing.signup_grant_tickets'));
 });
 
 test('grantPurchased は checkout session id で冪等付与し、返金正本キーを記録する', function (): void {
@@ -142,7 +142,7 @@ function grantService(): TicketLedgerService
     grantService()->grantPurchased($organization, 10, 'cs_1', 'pi_1', 5000);
     grantService()->grantPurchased($organization, 10, 'cs_1', 'pi_1', 5000); // 再送
 
-    expect(grantService()->balance($organization))->toBe(10);
+    expect(grantService()->balance($organization)->totalAvailable())->toBe(10);
     expect($organization->ticketLedgerEntries()->count())->toBe(1);
     $entry = $organization->ticketLedgerEntries()->firstOrFail();
     expect($entry->source)->toBe(TicketSource::Purchased);
diff --git a/tests/Feature/Billing/TicketLedgerTest.php b/tests/Feature/Billing/TicketLedgerTest.php
index 0687522..26a339b 100644
--- a/tests/Feature/Billing/TicketLedgerTest.php
+++ b/tests/Feature/Billing/TicketLedgerTest.php
@@ -2,6 +2,7 @@
 
 declare(strict_types=1);
 
+use App\Enums\Billing\TicketCommitResult;
 use App\Enums\Billing\TicketLedgerKind;
 use App\Enums\Billing\TicketReservationStatus;
 use App\Exceptions\Billing\InsufficientTicketsException;
@@ -23,7 +24,7 @@ function ticketService(): TicketLedgerService
 
     ticketService()->grant($organization, 10, '初期付与');
 
-    expect(ticketService()->balance($organization))->toBe(10);
+    expect(ticketService()->balance($organization)->totalAvailable())->toBe(10);
     $entry = $organization->ticketLedgerEntries()->firstOrFail();
     expect($entry->kind)->toBe(TicketLedgerKind::Grant);
     expect($entry->delta)->toBe(10);
@@ -35,12 +36,12 @@ function ticketService(): TicketLedgerService
 
     $reservation = ticketService()->reserve($organization, 3);
     // 予約中は残高から拘束される
-    expect(ticketService()->balance($organization))->toBe(7);
+    expect(ticketService()->balance($organization)->totalAvailable())->toBe(7);
 
     ticketService()->commit($reservation);
 
     expect($reservation->status)->toBe(TicketReservationStatus::Committed);
-    expect(ticketService()->balance($organization))->toBe(7);
+    expect(ticketService()->balance($organization)->totalAvailable())->toBe(7);
     $commitEntry = $organization->ticketLedgerEntries()
         ->where('kind', TicketLedgerKind::ReserveCommit)
         ->firstOrFail();
@@ -53,12 +54,12 @@ function ticketService(): TicketLedgerService
     ticketService()->grant($organization, 10, '初期付与');
 
     $reservation = ticketService()->reserve($organization, 3);
-    expect(ticketService()->balance($organization))->toBe(7);
+    expect(ticketService()->balance($organization)->totalAvailable())->toBe(7);
 
     ticketService()->release($reservation);
 
     expect($reservation->status)->toBe(TicketReservationStatus::Released);
-    expect(ticketService()->balance($organization))->toBe(10);
+    expect(ticketService()->balance($organization)->totalAvailable())->toBe(10);
 });
 
 test('残高不足の reserve は InsufficientTicketsException', function (): void {
@@ -82,17 +83,20 @@ function ticketService(): TicketLedgerService
     expect($organization->ticketReservations()->count())->toBe(1);
 });
 
-test('committed / released の予約は再 commit / 再 release できない', function (): void {
+test('committed の再 commit は冪等 no-op / 再 release は例外 (commit-wins)', function (): void {
     [$organization] = createOrganizationWithOwner();
     ticketService()->grant($organization, 10, '初期付与');
 
     $reservation = ticketService()->reserve($organization, 3);
-    ticketService()->commit($reservation);
+    expect(ticketService()->commit($reservation))->toBe(TicketCommitResult::Committed);
 
-    expect(fn () => ticketService()->commit($reservation))->toThrow(LogicException::class);
+    // P5 commit-wins: status guard を撤去したため再 commit は例外ではなく冪等 no-op
+    expect(ticketService()->commit($reservation))->toBe(TicketCommitResult::AlreadyCommitted);
+    // release の意味論は不変 (非 Reserved は LogicException)
     expect(fn () => ticketService()->release($reservation))->toThrow(LogicException::class);
     // 二重 commit が無いこと (負 delta は 1 行のみ)
-    expect(ticketService()->balance($organization))->toBe(7);
+    expect($organization->ticketLedgerEntries()->where('kind', TicketLedgerKind::ReserveCommit)->count())->toBe(1);
+    expect(ticketService()->balance($organization)->totalAvailable())->toBe(7);
 });
 
 test('releaseStale は expires_at 超過の reserved だけを解放する', function (): void {
@@ -100,7 +104,7 @@ function ticketService(): TicketLedgerService
     ticketService()->grant($organization, 10, '初期付与');
 
     $stale = ticketService()->reserve($organization, 4);
-    expect(ticketService()->balance($organization))->toBe(6);
+    expect(ticketService()->balance($organization)->totalAvailable())->toBe(6);
 
     // TTL (30 分) を超過させる
     $this->travel(31)->minutes();
@@ -112,7 +116,7 @@ function ticketService(): TicketLedgerService
     expect($stale->refresh()->status)->toBe(TicketReservationStatus::Released);
     expect($fresh->refresh()->status)->toBe(TicketReservationStatus::Reserved);
     // stale 分の拘束は解け、fresh 分だけが残る
-    expect(ticketService()->balance($organization))->toBe(8);
+    expect(ticketService()->balance($organization)->totalAvailable())->toBe(8);
 });
 
 test('台帳エントリは append-only (update / delete は例外)', function (): void {
diff --git a/tests/Feature/Billing/TicketLegacyReservationTest.php b/tests/Feature/Billing/TicketLegacyReservationTest.php
new file mode 100644
index 0000000..e62bd25
--- /dev/null
+++ b/tests/Feature/Billing/TicketLegacyReservationTest.php
@@ -0,0 +1,72 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\TicketCommitResult;
+use App\Enums\Billing\TicketLedgerKind;
+use App\Enums\Billing\TicketSource;
+use App\Models\Billing\TicketLedgerEntry;
+use App\Models\Billing\TicketReservation;
+use App\Services\Billing\TicketLedgerService;
+use Illuminate\Support\Facades\Log;
+
+/*
+ * P5: デプロイ時に in-flight だった legacy 予約 (consume_source / consume_expires_at = null) の
+ * 移行期挙動 (aigenba verbatim)。backfill しないため 2 列 null のまま到達する。
+ */
+
+function legacyReservationService(): TicketLedgerService
+{
+    return app(TicketLedgerService::class);
+}
+
+test('legacy 予約は per-source hold に計上されないが activeReservations には計上される', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    legacyReservationService()->grantMonthly($organization, 10, null, 'monthly:legacy-hold', '無期限月次付与');
+
+    TicketReservation::factory()->forOrganization($organization)->legacy()->create(['amount' => 4]);
+
+    $balance = legacyReservationService()->balance($organization);
+    // 表示は保守側 (legacy も拘束として見せる)
+    expect($balance->activeReservations)->toBe(4);
+    expect($balance->totalAvailable())->toBe(6);
+    // 一方 per-source hold には計上されないため与信は拘束されない (aigenba と同一の既知窓)
+    expect(legacyReservationService()->availableTrueBalance($organization))->toBe(10);
+});
+
+test('legacy 予約の commit は monthly / 予約 TTL 境界で 1 行計上し警告を残す', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    legacyReservationService()->grantMonthly($organization, 10, null, 'monthly:legacy-commit', '無期限月次付与');
+    $reservation = TicketReservation::factory()->forOrganization($organization)->legacy()->create(['amount' => 3]);
+
+    Log::spy();
+
+    expect(legacyReservationService()->commit($reservation))->toBe(TicketCommitResult::Committed);
+
+    $consume = TicketLedgerEntry::query()
+        ->where('organization_id', $organization->getKey())
+        ->where('kind', TicketLedgerKind::ReserveCommit)
+        ->firstOrFail();
+    expect($consume->delta)->toBe(-3);
+    expect($consume->source)->toBe(TicketSource::Monthly);
+    expect($consume->expires_at?->toIso8601String())
+        ->toBe($reservation->refresh()->expires_at->toIso8601String());
+
+    Log::shouldHaveReceived('warning')
+        ->withArgs(fn (string $message): bool => str_contains($message, 'legacy reservation without consume_source'))
+        ->once();
+});
+
+test('legacy 予約の再 commit は AlreadyCommitted', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    legacyReservationService()->grantMonthly($organization, 10, null, 'monthly:legacy-again', '無期限月次付与');
+    $reservation = TicketReservation::factory()->forOrganization($organization)->legacy()->create(['amount' => 3]);
+
+    legacyReservationService()->commit($reservation);
+
+    expect(legacyReservationService()->commit($reservation))->toBe(TicketCommitResult::AlreadyCommitted);
+    expect(TicketLedgerEntry::query()
+        ->where('organization_id', $organization->getKey())
+        ->where('kind', TicketLedgerKind::ReserveCommit)
+        ->count())->toBe(1);
+});
diff --git a/tests/Feature/Billing/TicketPurchaseWebhookTest.php b/tests/Feature/Billing/TicketPurchaseWebhookTest.php
index d670b59..b1ca817 100644
--- a/tests/Feature/Billing/TicketPurchaseWebhookTest.php
+++ b/tests/Feature/Billing/TicketPurchaseWebhookTest.php
@@ -84,7 +84,7 @@ function paidTicketPayload(Organization $organization, string $eventId = 'evt_ti
 
     event(new WebhookReceived(paidTicketPayload($organization)));
 
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(30);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(30);
     expect($session->refresh()->status)->toBe(TicketCheckoutSessionStatus::Completed);
     expect($session->completed_at)->not->toBeNull();
 
@@ -103,7 +103,7 @@ function paidTicketPayload(Organization $organization, string $eventId = 'evt_ti
     event(new WebhookReceived(paidTicketPayload($organization)));
     event(new WebhookReceived(paidTicketPayload($organization)));
 
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(30);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(30);
     expect($organization->ticketLedgerEntries()->count())->toBe(1);
 });
 
@@ -113,7 +113,7 @@ function paidTicketPayload(Organization $organization, string $eventId = 'evt_ti
     event(new WebhookReceived(paidTicketPayload($organization, 'evt_a')));
     event(new WebhookReceived(paidTicketPayload($organization, 'evt_b')));
 
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(30);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(30);
     expect($organization->ticketLedgerEntries()->count())->toBe(1);
 });
 
@@ -128,7 +128,7 @@ function paidTicketPayload(Organization $organization, string $eventId = 'evt_ti
 
     $record = StripeWebhookEvent::query()->firstOrFail();
     expect($record->status)->toBe(WebhookEventStatus::Failed);
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(0);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
 
     // (2) 同一 attempt の再試行が DB 行を記録 (Stripe idempotency key で同一 session に収束)
     TicketCheckoutSession::factory()
@@ -144,7 +144,7 @@ function paidTicketPayload(Organization $organization, string $eventId = 'evt_ti
     // (3) Stripe の event 再送 (failed→received 復帰) で一度だけ付与
     event(new WebhookReceived(paidTicketPayload($organization)));
 
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(30);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(30);
     expect($organization->ticketLedgerEntries()->count())->toBe(1);
     expect($record->refresh()->status)->toBe(WebhookEventStatus::Processed);
 });
@@ -160,7 +160,7 @@ function paidTicketPayload(Organization $organization, string $eventId = 'evt_ti
     expect(fn () => event(new WebhookReceived($payload)))
         ->toThrow(RuntimeException::class, $messagePart);
 
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(0);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
     expect($session->refresh()->status)->toBe(TicketCheckoutSessionStatus::Pending);
     expect(StripeWebhookEvent::query()->firstOrFail()->status)->toBe(WebhookEventStatus::Failed);
 })->with([
@@ -181,7 +181,7 @@ function paidTicketPayload(Organization $organization, string $eventId = 'evt_ti
 
     event(new WebhookReceived($payload));
 
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(0);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
     expect($session->refresh()->status)->toBe(TicketCheckoutSessionStatus::Pending);
     expect(StripeWebhookEvent::query()->firstOrFail()->status)->toBe(WebhookEventStatus::Processed);
 })->with([
@@ -199,7 +199,7 @@ function paidTicketPayload(Organization $organization, string $eventId = 'evt_ti
 
     event(new WebhookReceived(paidTicketPayload($organization)));
 
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(30);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(30);
     expect($session->refresh()->status)->toBe(TicketCheckoutSessionStatus::Completed);
     expect($session->completed_at)->not->toBeNull();
 });
@@ -223,7 +223,7 @@ function paidTicketPayload(Organization $organization, string $eventId = 'evt_ti
     // 例外は投げられない (terminal-ack = 200) + 付与されない
     event(new WebhookReceived(paidTicketPayload($organization, 'evt_terminal_1')));
 
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(0);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
     expect($record->refresh()->status)->toBe(WebhookEventStatus::Failed);
     expect($record->attempts)->toBe(StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS);
     $handler->shouldHaveReceived('report')->once();
@@ -253,7 +253,7 @@ function paidTicketPayload(Organization $organization, string $eventId = 'evt_ti
     [$organization] = ticketPurchaseFixture();
 
     event(new WebhookReceived(paidTicketPayload($organization)));
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(30);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(30);
 
     // 全額返金 → 全枚数逆仕訳 (既存 charge.refunded → clawbackPurchasedByPaymentIntent 経路)
     event(new WebhookReceived([
@@ -268,5 +268,5 @@ function paidTicketPayload(Organization $organization, string $eventId = 'evt_ti
         ],
     ]));
 
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(0);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
 });
diff --git a/tests/Feature/Billing/TicketRefundClawbackTest.php b/tests/Feature/Billing/TicketRefundClawbackTest.php
index 23307cb..8ae453f 100644
--- a/tests/Feature/Billing/TicketRefundClawbackTest.php
+++ b/tests/Feature/Billing/TicketRefundClawbackTest.php
@@ -139,12 +139,17 @@ function purchasedNet(Organization $organization): int
     // 2 枚消費 (reserve → commit)
     $reservation = clawbackService()->reserve($organization, 2);
     clawbackService()->commit($reservation);
-    expect(clawbackService()->balance($organization))->toBe(3);
+    expect(clawbackService()->balance($organization)->totalAvailable())->toBe(3);
 
     // 全額返金 → target 5 逆仕訳 → 5 - 2 - 5 = -2 (既消費分は取り戻せない)
     event(new WebhookReceived(chargeRefundedPayload('evt_neg', 'pi_neg', 2500)));
 
-    expect(clawbackService()->balance($organization))->toBe(-2);
+    // P5 per-source clamp: 表示・与信からは債務を遮蔽する (purchasedRemaining = max(-2, 0))
+    expect(clawbackService()->balance($organization)->purchasedRemaining)->toBe(0);
+    expect(clawbackService()->balance($organization)->totalAvailable())->toBe(0);
+    expect(clawbackService()->availableTrueBalance($organization))->toBe(0);
+    // 台帳では債務が保全され、次回購入で一度だけ自然回収される (clamp は表示・与信のみに効く)
+    expect(purchasedNet($organization))->toBe(-2);
     expect(fn () => clawbackService()->reserve($organization, 1))
         ->toThrow(InsufficientTicketsException::class);
 });
diff --git a/tests/Feature/Billing/WebhookIdempotencyTest.php b/tests/Feature/Billing/WebhookIdempotencyTest.php
index 6962c08..b42b2a4 100644
--- a/tests/Feature/Billing/WebhookIdempotencyTest.php
+++ b/tests/Feature/Billing/WebhookIdempotencyTest.php
@@ -104,7 +104,7 @@ function subscriptionPayload(string $type, string $status, string $eventId): arr
     event(new WebhookReceived(invoicePaidPayload()));
 
     // standard プランの monthly_ticket_grant (100) が 1 回だけ付与される
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(100);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(100);
     expect($organization->ticketLedgerEntries()->count())->toBe(1);
     expect(StripeWebhookEvent::query()->count())->toBe(1);
     $record = StripeWebhookEvent::query()->firstOrFail();
@@ -123,7 +123,7 @@ function subscriptionPayload(string $type, string $status, string $eventId): arr
     event(new WebhookReceived($first));
     event(new WebhookReceived($second));
 
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(200);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(200);
     expect(StripeWebhookEvent::query()->count())->toBe(2);
 });
 
@@ -135,7 +135,7 @@ function subscriptionPayload(string $type, string $status, string $eventId): arr
     event(new WebhookReceived(invoicePaidPayload('evt_dup_a')));
     event(new WebhookReceived(invoicePaidPayload('evt_dup_b')));
 
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(100);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(100);
     expect($organization->ticketLedgerEntries()->count())->toBe(1);
     expect(StripeWebhookEvent::query()->count())->toBe(2);
 });
@@ -150,7 +150,7 @@ function subscriptionPayload(string $type, string $status, string $eventId): arr
     event(new WebhookReceived($payload));
 
     // 月次 100 + signup grant (config billing.signup_grant_tickets = 10)
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(110);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(110);
     // 冪等キーは org スコープ (呼び出し側が渡す)。subscription id には依存しない。
     $signup = $organization->ticketLedgerEntries()
         ->where('idempotency_key', "signup_grant:org:{$organization->id}")
@@ -163,7 +163,7 @@ function subscriptionPayload(string $type, string $status, string $eventId): arr
     $retry['id'] = 'evt_signup_2';
     event(new WebhookReceived($retry));
 
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(110);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(110);
 });
 
 test('subscription id が無くても org スコープキーで signup grant を付与する', function (): void {
@@ -178,7 +178,7 @@ function subscriptionPayload(string $type, string $status, string $eventId): arr
     event(new WebhookReceived($payload));
 
     // 月次 100 + signup grant 10
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(110);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(110);
     expect(
         $organization->ticketLedgerEntries()
             ->where('idempotency_key', 'like', 'signup_grant:%')
@@ -198,7 +198,7 @@ function subscriptionPayload(string $type, string $status, string $eventId): arr
     expect($organization->ticketLedgerEntries()
         ->where('idempotency_key', 'like', 'monthly:%')->count())->toBe(0);
     // signup grant のみが計上される (残高は config の付与枚数と一致)
-    expect(app(TicketLedgerService::class)->balance($organization))
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())
         ->toBe(config('billing.signup_grant_tickets'));
     expect($organization->ticketLedgerEntries()->count())->toBe(1);
 });
@@ -246,7 +246,7 @@ function subscriptionPayload(string $type, string $status, string $eventId): arr
 
     event(new WebhookReceived($payload));
 
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(0);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
     // 受理自体は冪等記録される (processed)
     $record = StripeWebhookEvent::query()->firstOrFail();
     expect($record->status)->toBe(WebhookEventStatus::Processed);
@@ -260,7 +260,7 @@ function subscriptionPayload(string $type, string $status, string $eventId): arr
 
     event(new WebhookReceived($payload));
 
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(0);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
     expect(StripeWebhookEvent::query()->count())->toBe(1);
 });
 
@@ -315,7 +315,7 @@ function failedWebhookRecord(string $eventId, int $attempts): StripeWebhookEvent
     expect($record->status)->toBe(WebhookEventStatus::Processed);
     expect($record->attempts)->toBe(3);
     expect($record->failure_reason)->toBeNull();
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(100);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(100);
 });
 
 test('attempts が上限到達済みの failed は terminal-ack (処理せず例外も投げない)', function (): void {
@@ -329,5 +329,5 @@ function failedWebhookRecord(string $eventId, int $attempts): StripeWebhookEvent
     $record = StripeWebhookEvent::query()->where('event_id', 'evt_terminal')->firstOrFail();
     expect($record->status)->toBe(WebhookEventStatus::Failed); // failed のまま (運用調査用に保持)
     expect($record->attempts)->toBe(StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS);
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(0); // 付与されない
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0); // 付与されない
 });
diff --git a/tests/Feature/Database/BughuntBillingSeederTest.php b/tests/Feature/Database/BughuntBillingSeederTest.php
index d8ea911..67e3c66 100644
--- a/tests/Feature/Database/BughuntBillingSeederTest.php
+++ b/tests/Feature/Database/BughuntBillingSeederTest.php
@@ -47,7 +47,7 @@ function runWithBughuntGuardSatisfied(Closure $callback): void
     $this->seed(BughuntBillingSeeder::class);
 
     expect(Subscription::query()->count())->toBe(0);
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(0);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
 });
 
 test('fake_externals=true でも env=testing のままなら no-op (flag 単独では点火しない)', function (): void {
@@ -58,7 +58,7 @@ function runWithBughuntGuardSatisfied(Closure $callback): void
     $this->seed(BughuntBillingSeeder::class);
 
     expect(Subscription::query()->count())->toBe(0);
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(0);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
 });
 
 test('guard 成立時: standard 組織のみ active sub + チケット 100 を付与し、再実行しても増えない (冪等)', function (): void {
@@ -80,11 +80,11 @@ function runWithBughuntGuardSatisfied(Closure $callback): void
     // standard 組織: active subscription (課金ゲート通過) + チケット 100
     expect(app(BillingAccess::class)->hasActiveAccess($standardOrg))->toBeTrue();
     expect($standardOrg->subscriptions()->count())->toBe(1);
-    expect($tickets->balance($standardOrg))->toBe(100);
+    expect($tickets->balance($standardOrg)->totalAvailable())->toBe(100);
 
     // free 組織: subscription もチケットも付与されない (課金なし経路の温存)
     expect($freeOrg->subscriptions()->count())->toBe(0);
-    expect($tickets->balance($freeOrg))->toBe(0);
+    expect($tickets->balance($freeOrg)->totalAvailable())->toBe(0);
 });
 
 test('既存 subscription が past_due でも再実行で active に回復する (行は増えない)', function (): void {
diff --git a/tests/Feature/Manual/RenderPipelineTest.php b/tests/Feature/Manual/RenderPipelineTest.php
index 2085cfe..9ffc24b 100644
--- a/tests/Feature/Manual/RenderPipelineTest.php
+++ b/tests/Feature/Manual/RenderPipelineTest.php
@@ -5,6 +5,7 @@
 use App\DataTransferObjects\Manual\Render\ComposedLocalVideo;
 use App\DataTransferObjects\Manual\Render\RenderClipSource;
 use App\DataTransferObjects\Manual\Render\RenderManifest;
+use App\Enums\Billing\TicketLedgerKind;
 use App\Enums\Billing\TicketReservationStatus;
 use App\Enums\Manual\JobStatus;
 use App\Enums\Manual\MaterialType;
@@ -13,6 +14,7 @@
 use App\Enums\Manual\VideoManualStatus;
 use App\Exceptions\Manual\RenderCompositionException;
 use App\Jobs\Manual\DeleteRenderOutputsJob;
+use App\Models\Billing\TicketLedgerEntry;
 use App\Models\Billing\TicketReservation;
 use App\Models\Cut;
 use App\Models\Organization;
@@ -33,7 +35,7 @@
  * レンダパイプライン (RenderPipeline::run の直接呼び出し。§10.8-1/-6/-8 / 概念設計 §5):
  * - 成功パス: complete + commit + succeeded の原子化 (terminal tx)
  * - version 固定 (preview トリガー後の編集は scenario_version_changed で fail)
- * - チケット 2 フェーズ (再利用 / TTL 付け替え / 失敗 release / commit は Reserved のみ /
+ * - チケット 2 フェーズ (再利用 / TTL 付け替え / 失敗 release / commit-wins /
  *   preview は台帳・予約が一切動かない)
  * - stale 先勝ち・失敗後始末 (S3 に出力を残さない)・世代交代の削除 job dispatch
  */
@@ -140,7 +142,7 @@ function renderTriggeredJob(?RenderJob $job): RenderJob
     $reservation = $job->ticketReservation;
     expect($reservation)->not->toBeNull();
     expect($reservation?->status)->toBe(TicketReservationStatus::Committed);
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(0);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
 
     // マニフェスト: 採用テイクの S3 素材がローカルへ供給されている
     expect($fake->lastManifest?->kind)->toBe(RenderKind::Render);
@@ -161,7 +163,7 @@ function renderTriggeredJob(?RenderJob $job): RenderJob
     expect(Storage::disk('s3')->exists((string) $previewJob->output_path))->toBeTrue();
     expect($manual->refresh()->status)->toBe(VideoManualStatus::Ready);
     expect(TicketReservation::query()->count())->toBe(0);
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(0);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
     expect($fake->lastManifest?->kind)->toBe(RenderKind::Preview);
 });
 
@@ -329,7 +331,10 @@ function renderTriggeredJob(?RenderJob $job): RenderJob
     expect(Storage::disk('s3')->exists($expectedKey))->toBeFalse();
 });
 
-test('commit は Reserved のみ: finalize 直前に released なら rollback + failed (完成扱いにしない)', function (): void {
+test('finalize 直前に released でも commit-wins で完走し課金される (無課金 succeeded を作らない)', function (): void {
+    // P5 commit-wins: 守る不変条件は「succeeded ∧ released の非共存」ではなく
+    // 「succeeded ∧ 無課金 (= 成果物を渡してタダ乗り) の非共存」。予約 status が Released でも
+    // 台帳に消費行が立てば課金は成立する (課金の真実源は台帳。status は一方向遷移を壊さない)
     [, , , $manual, $cut, $job, $fake] = renderPipelineContext();
     $fake->duringCompose = function () use ($job): void {
         // finalize 前に予約が releaseStale cron で解放される競合を細工
@@ -342,17 +347,19 @@ function renderTriggeredJob(?RenderJob $job): RenderJob
     app(RenderPipeline::class)->run($job->id);
 
     $job->refresh();
-    expect($job->status)->toBe(JobStatus::Failed);
-    // terminal tx 全体 rollback: manual は published にならず ready へ復帰 (failJob 経由)
+    expect($job->status)->toBe(JobStatus::Succeeded);
     $manual->refresh();
-    expect($manual->status)->toBe(VideoManualStatus::Ready);
-    expect($manual->total_length_ms)->toBeNull();
-    expect($cut->refresh()->cut_length_ms)->toBeNull();
-    // 非共存: 課金 (committed) は発生していない
-    expect(TicketReservation::query()->where('status', TicketReservationStatus::Committed)->count())->toBe(0);
-    // アップロード済み出力は後始末で削除される
-    $expectedKey = "projects/{$manual->project_id}/manuals/{$manual->id}/renders/v2-{$job->id}.mp4";
-    expect(Storage::disk('s3')->exists($expectedKey))->toBeFalse();
+    expect($manual->status)->toBe(VideoManualStatus::Published);
+    expect($cut->refresh()->cut_length_ms)->not->toBeNull();
+    // 非共存: succeeded なのに無課金、にはならない (消費行が立っている)
+    $reservation = $job->ticketReservation;
+    expect($reservation)->not->toBeNull();
+    expect(TicketLedgerEntry::query()
+        ->where('reservation_id', $reservation?->getKey())
+        ->where('kind', TicketLedgerKind::ReserveCommit)
+        ->count())->toBe(1);
+    // 一方向遷移は壊さない (Released → Committed へは戻さない)
+    expect($reservation?->refresh()->status)->toBe(TicketReservationStatus::Released);
 });
 
 test('世代交代: 再レンダ成功で旧 job id の DeleteRenderOutputsJob が dispatch される', function (): void {
diff --git a/tests/Feature/Manual/RenderStaleRecoveryTest.php b/tests/Feature/Manual/RenderStaleRecoveryTest.php
index a1c8ede..b73ed6e 100644
--- a/tests/Feature/Manual/RenderStaleRecoveryTest.php
+++ b/tests/Feature/Manual/RenderStaleRecoveryTest.php
@@ -89,7 +89,7 @@ function staleRecoveryContext(): array
     expect($job->refresh()->status)->toBe(JobStatus::Failed);
     expect($manual->refresh()->status)->toBe(VideoManualStatus::Ready);
     expect($reservation->refresh()->status)->toBe(TicketReservationStatus::Released);
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(3); // 拘束解放
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(3); // 拘束解放
 });
 
 test('kind=preview の回収は manual status を触らない', function (): void {
diff --git a/tests/Feature/Manual/RenderTriggerTest.php b/tests/Feature/Manual/RenderTriggerTest.php
index 3a1ebb8..d4cea84 100644
--- a/tests/Feature/Manual/RenderTriggerTest.php
+++ b/tests/Feature/Manual/RenderTriggerTest.php
@@ -251,7 +251,7 @@ function adoptReadyTake(Cut $cut, int $durationMs = 5_000): Take
     expect($manual->refresh()->status)->toBe(VideoManualStatus::Ready);
     // チケット非消費: 台帳・予約とも無変化 (残高 0 でも通る)
     expect(TicketReservation::query()->count())->toBe(0);
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(0);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
     Queue::assertPushed(RunManualRender::class, fn (RunManualRender $pushed): bool => $pushed->renderJobId === $job->id);
 });
 
diff --git a/tests/Feature/Notifications/TicketBalanceLowNotificationTest.php b/tests/Feature/Notifications/TicketBalanceLowNotificationTest.php
index 9bc2016..feae86e 100644
--- a/tests/Feature/Notifications/TicketBalanceLowNotificationTest.php
+++ b/tests/Feature/Notifications/TicketBalanceLowNotificationTest.php
@@ -85,7 +85,7 @@ function lowBalanceNotificationCountFor(User $user): int
     $ledger->commit($second);
     $ledger->commit($first);
     expect(lowBalanceNotificationCountFor($owner))->toBe(1);
-    expect($ledger->balance($organization))->toBe(2);
+    expect($ledger->balance($organization)->totalAvailable())->toBe(2);
 });
 
 test('release で閾値以上へ回復 → 再度跨ぐ reserve で再通知される', function (): void {
@@ -114,7 +114,7 @@ function lowBalanceNotificationCountFor(User $user): int
     }
 
     expect(lowBalanceNotificationCountFor($owner))->toBe(0);
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(10); // reserve ごと巻き戻る
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(10); // reserve ごと巻き戻る
 });
 
 test('grant で回復して再度跨ぐ場合も再通知される', function (): void {
diff --git a/tests/Feature/Organization/InvitationTest.php b/tests/Feature/Organization/InvitationTest.php
index ea40023..c753ee5 100644
--- a/tests/Feature/Organization/InvitationTest.php
+++ b/tests/Feature/Organization/InvitationTest.php
@@ -384,7 +384,7 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
     // 個人組織は生成されない
     expect($user->organizations()->where('is_personal', true)->exists())->toBeFalse();
     // 招待組織の残高に signup grant は乗らない (owner の付与ぶんも招待組織には走っていない)
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(0);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
     expect(
         $organization->ticketLedgerEntries()
             ->where('idempotency_key', 'like', 'signup_grant:%')
diff --git a/tests/Feature/Projects/AnalysisPipelineTest.php b/tests/Feature/Projects/AnalysisPipelineTest.php
index 85497cf..74a4f24 100644
--- a/tests/Feature/Projects/AnalysisPipelineTest.php
+++ b/tests/Feature/Projects/AnalysisPipelineTest.php
@@ -3,12 +3,14 @@
 declare(strict_types=1);
 
 use App\DataTransferObjects\Manual\Analysis\GeneratedScenarioData;
+use App\Enums\Billing\TicketLedgerKind;
 use App\Enums\Billing\TicketReservationStatus;
 use App\Enums\Manual\CutType;
 use App\Enums\Manual\JobStatus;
 use App\Enums\Manual\ShotType;
 use App\Enums\Manual\VideoManualStatus;
 use App\Models\AnalysisJob;
+use App\Models\Billing\TicketLedgerEntry;
 use App\Models\Billing\TicketReservation;
 use App\Models\Cut;
 use App\Models\Organization;
@@ -163,7 +165,7 @@ function fakeSuccessfulLlm(): void
     $reservation = $job->ticketReservation;
     expect($reservation)->not->toBeNull();
     expect($reservation->status)->toBe(TicketReservationStatus::Committed);
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(0);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
     expect(TicketReservation::query()->count())->toBe(1);
 
     // 監査スナップショット
@@ -322,7 +324,10 @@ function fakeSuccessfulLlm(): void
     expect($job->ticketReservation?->status)->toBe(TicketReservationStatus::Committed);
 });
 
-test('インターリーブ (d): commit は Reserved のみ → terminal tx 全体 rollback (cuts 不変)', function (): void {
+test('インターリーブ (d): stale releaser 先着でも finalize は完走し課金される (commit-wins)', function (): void {
+    // P5 commit-wins: 守るべき不変条件は「succeeded ∧ released の非共存」ではなく
+    // 「succeeded ∧ 無課金 (= 成果物を渡してタダ乗り) の非共存」。予約 status が Released でも
+    // 台帳に消費行が立てば課金は成立する (課金の真実源は台帳。status は一方向遷移を壊さない)
     [$organization, , , $manual, , $job] = pipelineContext();
     $reservation = app(TicketLedgerService::class)->reserve($organization, 1);
     $job->ticketReservation()->associate($reservation);
@@ -333,16 +338,18 @@ function fakeSuccessfulLlm(): void
 
     $generated = GeneratedScenarioData::fromLlmText(scenarioFixture());
     $finalize = new ReflectionMethod(AnalysisPipeline::class, 'finalize');
-    expect(fn () => $finalize->invoke(app(AnalysisPipeline::class), $job, $generated))
-        ->toThrow(LogicException::class);
+    $finalize->invoke(app(AnalysisPipeline::class), $job, $generated);
 
-    // terminal tx 全体が rollback: materialize も succeeded も残らない
     $job->refresh();
-    expect($job->status)->toBe(JobStatus::Running);
-    expect($manual->refresh()->cuts()->count())->toBe(0);
-    expect($manual->status)->toBe(VideoManualStatus::Analyzing);
-    // 非共存: 課金 (committed) は発生していない
-    expect(TicketReservation::query()->where('status', TicketReservationStatus::Committed)->count())->toBe(0);
+    expect($job->status)->toBe(JobStatus::Succeeded);
+    expect($manual->refresh()->cuts()->count())->toBeGreaterThan(0);
+    // 非共存: succeeded なのに無課金、にはならない (消費行が立っている)
+    expect(TicketLedgerEntry::query()
+        ->where('reservation_id', $reservation->getKey())
+        ->where('kind', TicketLedgerKind::ReserveCommit)
+        ->count())->toBe(1);
+    // 一方向遷移は壊さない (Released → Committed へは戻さない)
+    expect($reservation->refresh()->status)->toBe(TicketReservationStatus::Released);
 });
 
 test('materialize は analyzing 以外で呼ぶと LogicException (defensive 二層目)', function (): void {
```

---

# データ 2: 設計書 P5 節 (正本。この設計は Codex 合議 16 ラウンドで APPROVED 済み)

### P5: チケット残高会計を aigenba verbatim へ移植（per-source clamp / 消費優先 / commit-wins）

現行 `TicketLedgerService::balance()` は docblock（`app/Services/Billing/TicketLedgerService.php:217-225`）自身が「失効は未消費分も含めた全額失効として保守的に働く」と近似を認める単一 int。これを **aigenba `TicketService` の per-source 会計へ verbatim 移植**する。**debt は発明しない**（v1 の `debt` フィールド・債務保全数式・`consume_monthly_amount` の分割配賦はすべて撤回）。台帳（`ticket_ledger_entries`）は**列追加ゼロ・既存行の書き換えゼロ**、変更は `ticket_reservations` への additive 2 列と読み取り計算のみ。維持する逸脱は **amount ベース reserve**（`AnalysisPipeline.php:121` / `RenderPipeline.php:177` の `reserve($organization, $cost)`。`manual.analysis_ticket_cost`=1 / `render_ticket_cost`=3）と **reserve→commit/release の 2 フェーズ**（AGENTS.md 不変条件 #7。aigenba も同形）の 2 点のみ。

#### 変更箇所（ファイルパス + 何をするか。移植元 aigenba のパスを併記）

| ファイル | 内容 | 移植元（aigenba） |
|---|---|---|
| `database/migrations/2026_07_17_000500_add_consume_columns_to_ticket_reservations.php`（新規） | `ticket_reservations` へ additive 2 列: `consume_source`(string nullable) / `consume_expires_at`(timestamp nullable)。**backfill しない**（既存 Reserved 行は 2 列 null = legacy）。新規 index なし | `TicketService.php:426-436` の `ticket_reservations` insert 列 |
| `app/Enums/Billing/TicketCommitResult.php`（新規） | `Committed` / `AlreadyCommitted` / `ReleasedExpired` | `app/Enums/Billing/TicketCommitResult.php` **verbatim**（case を足さない） |
| `app/DataTransferObjects/Billing/TicketBalanceDto.php`（新規） | `monthlyRemaining` / `purchasedRemaining` / `activeReservations` / `nextExpireAt` + `totalAvailable()` + `toArray()` | `app/DataTransferObjects/Billing/TicketBalanceDto.php` **verbatim**（フィールド追加なし） |
| `app/Models/Billing/TicketReservation.php` | `consume_source` / `consume_expires_at` の `@property` + `casts()`。`$fillable` は持たない（明示代入のみ）。`HasFactory` 追加 | `app/Models/Billing/TicketReservation.php` |
| `app/Services/Billing/TicketLedgerService.php` | 中核。`balance()` を DTO 化 / `availableTrueBalance()` 追加 / `reserve()` を per-source clamp + 消費優先 + `consume_*` 固定へ / `commit()` を commit-wins + `TicketCommitResult` 化 / `releaseStale()` に失効 monthly hold を追加 / private `sumBalance()` `sumActiveHolds()` `nearestMonthlyExpiry()` `isExpiredMonthlyHold()` `expiredMonthlyHoldCondition()` 追加 / `insertIdempotent()` を `int`（挿入行数）返しへ / `lockReservationRow()` に `bool $requireReserved = true` | `TicketService.php:312-342`(balance) / `:349-453`(reserve) / `:465-588`(commit) / `:595-623`(失効述語) / `:992-1005`(releaseStale) / `:1029-1083`(availableTrueBalance / sumBalance / countActiveReservations / nearestMonthlyExpiry) |
| `app/Http/Controllers/Billing/BillingController.php:63` | `'ticketBalance' => $tickets->balance($organization)->totalAvailable()`（props は int のまま） | — |
| `app/Http/Controllers/Billing/TicketPurchaseController.php:66` | `balance:` へ `->totalAvailable()` | — |
| `app/Services/Dashboard/DashboardService.php:221` | `$balance = $this->tickets->balance($organization)->totalAvailable()`（`isLowBalance` も同値） | — |
| `app/Services/Manual/AnalysisJobService.php:81` / `RenderJobService.php:90` | 入口 fail-fast の残高を `availableTrueBalance()` へ（表示 clamp を判定に使わない） | `TicketService.php:1019-1027`「UI 表示には balance() を使うこと — 判定に使うと負残高で誤判定する」 |
| `app/Services/Manual/AnalysisPipeline.php:219-223` / `RenderPipeline.php:293-297` | commit の docblock/コメントを commit-wins へ更新（「非 Reserved は LogicException → rollback」を撤回）。**戻り値は分岐に使わない** | `TicketService.php:455-464` |
| `database/factories/Billing/TicketReservationFactory.php`（新規） | 新規テスト用（手組み禁止）。state: `forOrganization($org)` / `legacy()`(`consume_*`=null) / `monthlyHold(?CarbonImmutable $consumeExpiresAt)` / `purchasedHold()` / `stale()`。`docs/factories.md` の表へ追記 | — |

**移植しない（二重実装を作らない / 対象が無い）**: `app/Enums/CreditSource.php` は移植せず既存 `App\Enums\Billing\TicketSource`（`monthly` / `purchased`）を使う（値・意味が 1:1。`plan_monthly` へ改名すると `ticket_ledger_entries.source` 全行の書き換え = append-only 違反）。`ensureSufficient()`（`<1` 固定で AI-CUE の可変 cost に合わない。入口 gate は `availableTrueBalance() < $cost` で同義）/ `insertOrIgnore(encounter_id)` の冪等 reserve（AI-CUE は job 行の `ticketReservation()` 関連が冪等化を担う = `AnalysisPipeline.php:105-118`）/ `TicketBalanceResource`（Inertia props のため JsonResource 不使用）/ `AutoRechargeTriggerJob` の dispatch（P8a 所管）。

#### 波及変更

- **TypeScript 型定義**: **なし**。`resources/js/types/billing.ts:14`(`balance: number`) / `types/dashboard.ts:36`(`ticket_balance: number`) / `pages/Billing/Index.svelte:35`(`ticketBalance: number`) は int のまま（per-source の UI 露出は P8b 所管）。この props 形状不変が P5 の revert 安全性の根拠。
- **DTO・JsonResource**: 新規 `TicketBalanceDto`。`PurchaseTicketsPageDto.balance`（`@phpstan-type` の `balance: int`）/ `Dashboard/BillingSummaryData.ticketBalance` は**形状不変**（供給値の算出元のみ変更）。JsonResource は不使用。
- **Inertia props**: `Billing/Index` の `ticketBalance` / `Billing/PurchaseTickets` の `page.balance` / `Dashboard` の `dashboard.billing.ticket_balance` — キー・型とも不変。
- **テストファイル（更新対象・全列挙）**:
  `tests/Feature/Billing/TicketLedgerTest.php`(:26,:38,:43,:56,:61,:92,:95,:103,:115) /
  `tests/Feature/Billing/TicketGrantTest.php`(:28,:43,:58,:63,:79,:91,:101) /
  `tests/Feature/Billing/TicketRefundClawbackTest.php`(:142,:147) /
  `tests/Feature/Billing/TicketPurchaseWebhookTest.php`(:87,:106,:116,:131,:147,:163,:184,:202,:226,:256) /
  `tests/Feature/Billing/WebhookIdempotencyTest.php`(:94,:112,:123,:137,:150,:164,:214,:227,:280,:293) /
  `tests/Feature/Organization/InvitationTest.php:387` / `tests/Feature/Database/BughuntBillingSeederTest.php`(:50,:61,:83,:87) /
  `tests/Feature/Auth/RegistrationTest.php:29` / `tests/Feature/Auth/RegistrationInvitationPrefillTest.php:178` /
  `tests/Feature/Projects/AnalysisPipelineTest.php`(:166 + :294 近傍の不変条件コメント) /
  `tests/Feature/Manual/RenderPipelineTest.php`(:143,:164) / `tests/Feature/Manual/RenderStaleRecoveryTest.php:92` /
  `tests/Feature/Manual/RenderTriggerTest.php:254` / `tests/Feature/Notifications/TicketBalanceLowNotificationTest.php`(:88,:117)。
- **更新なし・回帰確認のみ**: `tests/Feature/Billing/TicketVolumeTierTest.php` は `TicketVolumePrice::currentTierFor` のみを検証し `TicketLedgerService` を注入しない（`balance()` / `reserve()` の呼び出しゼロ = grep 確認済み）ため期待更新は発生しない。`tests/Feature/Billing/BillingPageTest.php` / `tests/Feature/DashboardTest.php` も props 形状不変で更新不要。
- **削除するテストは無い**（期待の更新のみ）。**ルート変更なし**。`docs/factories.md` に `Billing\TicketReservationFactory` 行を追加。

#### 主要な契約

```php
/** 表示用の per-source 会計 (aigenba TicketService::balance verbatim) */
public function balance(Organization $organization): TicketBalanceDto;
/** 与信・判定用の真値 (per-source clamp 後に合算。常に 0 以上。aigenba :1029 verbatim) */
public function availableTrueBalance(Organization $organization): int;
public function reserve(Organization $organization, int $amount): TicketReservation; // シグネチャ不変 (amount ベース維持)
public function commit(TicketReservation $reservation): TicketCommitResult;          // void → enum (commit-wins)
public function release(TicketReservation $reservation): void;                       // 不変 (非 Reserved は LogicException)
public function releaseStale(): int;                                                 // 失効 monthly hold を対象に追加
```

**DTO 形状（aigenba verbatim。`debt` を足さない）**

```php
/**
 * @phpstan-type TicketBalanceShape array{monthlyRemaining: int, purchasedRemaining: int,
 *   totalAvailable: int, activeReservations: int, nextExpireAt: string|null}
 */
final readonly class TicketBalanceDto
{
    public function __construct(
        public int $monthlyRemaining,   // = max($monthly, 0)   ※hold は控除しない (raw clamp)
        public int $purchasedRemaining, // = max($purchased, 0)
        public int $activeReservations, // 拘束「枚数」= SUM(amount) (aigenba は 1 枚固定のため count)
        public ?string $nextExpireAt,
    ) {}

    public function totalAvailable(): int   // aigenba verbatim
    {
        return max($this->monthlyRemaining + $this->purchasedRemaining - $this->activeReservations, 0);
    }
}
```

**バケット定義（台帳 backfill を不要にする唯一の適応。発明ではなくスキーマ事実への必然）**
- aigenba の `ticket_ledger_entries.source` は常に非 null。**AI-CUE には `source IS NULL` 行が既存する**（`kind=reserve_commit` の既存消費行 / 手動 `grant()` / `adjustment` / `release` の 0 行）。純粋な per-source SUM にすると当該行が**両バケットから消え、過去消費が帳消しになる over-grant**（金銭事故）になる。台帳は append-only（Model が update を例外化）で `source` の backfill は不可。
- よって **`purchased` バケット = `source = 'purchased' OR source IS NULL`**（いずれも無期限で寿命特性が一致）、`monthly` バケット = `source = 'monthly'`。両バケットとも `expires_at IS NULL OR expires_at > now` のみ合算（aigenba `:1045-1053` の `sumBalance` と同形）。P5 以降の消費行には `source` が載るため、null 行は P5 以前の履歴と手動 `grant()` / `adjustment` に限られる。
- `nextExpireAt` = `delta > 0 AND expires_at IS NOT NULL AND expires_at > now` の最小 `expires_at` の ISO8601（aigenba `:328-334` 同型。`amount` → `delta`）。

**hold（拘束）集計 — aigenba verbatim + amount 一般化**

```php
// aigenba :1056-1069 (countActiveReservations) の amount 版。count() → sum('amount')
private function sumActiveHolds(Organization $org, TicketSource $source, CarbonImmutable $now): int
    → status=reserved AND consume_source=$source AND NOT expiredMonthlyHold の SUM(amount)
// aigenba :322-326 (balance の activeReservations)
$activeReservations = status=reserved AND NOT expiredMonthlyHold の SUM(amount)  // legacy(null) も計上
```
`reserve` TTL 切れ（`expires_at <= now`）でも Reserved である限り枠は保持する（aigenba `:1062-1066`。commit-wins と対称。30 分超ジョブ中の同枠二重予約 = オーバーセルを防ぐ）。枠の解放は `releaseStale` の Released 化に委ねる。

**失効 monthly hold の述語（aigenba `:595-623` verbatim。legacy 枝のみ AI-CUE の事実に合わせる）**

```php
private function isExpiredMonthlyHold(TicketReservation $r, CarbonImmutable $now): bool
{
    if ($r->consume_source !== TicketSource::Monthly) return false;  // legacy(null) / purchased
    if ($r->consume_expires_at === null) return false;               // 無期限 monthly からの消費
    return $r->consume_expires_at->lessThanOrEqualTo($now);
}
// query 版 (3 値論理事故を避けるため whereNotNull で確定 boolean にする。aigenba :613-623 同型)
$q->where('consume_source', TicketSource::Monthly->value)
  ->whereNotNull('consume_expires_at')->where('consume_expires_at', '<=', $now);
```
aigenba の `$boundary = consume_expires_at ?? expires_at` 枝は、legacy 行が先頭の `consume_source` 判定で false になるため**到達不能**（`Assert` により新規行の monthly は必ず非 null 期限）。AI-CUE ではこの空き枝が「無期限 monthly からの消費」に割り当たる。

**`Assert::isInstanceOf($consumeExpiresAt, CarbonImmutable::class)`（aigenba `:417-421`）は移植しない** — これは「monthly grant は必ず期限付き」という aigenba 固有の DB 事実に依存する assertion であり、AI-CUE では前提が成立しない。`grantMonthly(Organization, int, ?CarbonImmutable $expiresAt, string, string)` は null を受け、生存する呼び出しが実際に null を渡す: `BughuntBillingSeeder.php:63-68`（無期限 100 枚）/ `StripeWebhookProcessor.php:286-291`（`invoice.paid`。D28 で seed 値 0 のため既定は不発だが、Filament `PlanResource` で `monthly_ticket_grant` を戻せば復活する）/ `TicketGrantTest.php:26,:43,:57`。移植すると当該環境の monthly reserve が全て例外で落ちる。値・ロジックの変更ではなく、移植先スキーマ事実に対する必然の措置。

**reserve（aigenba `:385-436` verbatim + amount 一般化。既存 `lockOrganizationRow()` = org 行 `lockForUpdate` 下で評価 = 直列化点は不変）**

```text
$monthly   = sumBalance(monthly)      // clamp 前の生値
$purchased = sumBalance(purchased ∪ null)
$availableMonthly   = max($monthly   - sumActiveHolds(monthly),   0)   // aigenba :394 verbatim
$availablePurchased = max($purchased - sumActiveHolds(purchased), 0)   // aigenba :395 verbatim
$consumeSource = $availableMonthly >= $amount ? Monthly : Purchased    // 消費優先 monthly → purchased
$capacity = max($availableMonthly, $availablePurchased)
if ($capacity < $amount) throw InsufficientTicketsException::forReserve($amount, $capacity)
$consumeExpiresAt = $consumeSource === Monthly ? nearestMonthlyExpiry($org, $now) : null  // null 許容
→ TicketReservation を明示代入で作成 (organization / amount / status=Reserved / expires_at=now+30min
   / consume_source / consume_expires_at)
```
`$consumeSource` は aigenba `:406-408` の `$availableMonthly > 0` の amount 一般化（amount=1 で完全一致）。不足判定も aigenba `:396` の `$availableMonthly + $availablePurchased < 1` の amount 一般化で、非負値では amount=1 のとき sum 形と max 形は同値。**単一 `consume_source`（aigenba verbatim の予約行形状）を維持する以上、実際に賄える容量は max 側**であり、sum 形を採ると「どちらの source も単独では amount を賄えない」ケースで選んだ source を超過消費し、clamp がそれを隠して最大 `amount − 1` 枚のタダ配りになる。同値な 2 つの一般化のうち金銭不変条件を壊さない側を採る（分割配賦 `consume_monthly_amount` は v1 の発明として撤回済み）。

低残高クロス検知（`TicketLedgerService.php:269-280`）は `$balance = $availableMonthly + $availablePurchased`（= `availableTrueBalance` と同一意味論）に差し替えるのみ。`$after = $balance - $amount`。閾値・通知回数の意味論は不変（`billing.ticket_low_balance_threshold`=5）。

**commit（aigenba `:465-587` verbatim。commit-wins）**

```text
lockReservationRow($reservation, requireReserved: false)     // 行ロックは維持。status guard は撤去
status === Committed                        → TicketCommitResult::AlreadyCommitted   // 冪等 no-op
lockOrganizationRow($organization)
if (isExpiredMonthlyHold($locked, $now)):                    // aigenba :489-515
    Reserved なら Released 化 + Log::warning / 既に Released なら Log::info
    → TicketCommitResult::ReleasedExpired                    // 台帳行を書かない (決定的 no-charge)
$source = $locked->consume_source ?? TicketSource::Monthly   // aigenba :522 verbatim (legacy 既定)
$expiresAt = match:
    legacy (consume_source === null) → $locked->expires_at + Log::warning   // aigenba :527-536 verbatim
    Monthly                          → $locked->consume_expires_at          // null = 無期限 monthly
    Purchased                        → null
insertIdempotent(delta = -$locked->amount, kind = ReserveCommit, source = $source,
                 expires_at = $expiresAt, reservation_id = $locked->id,
                 key = "consume:{$locked->id}")               // aigenba :539-548 (consume:{encounterId}) 同型
挿入 0 行 → Log::warning (既存 consume 行あり = 冪等だが不整合検知のため可観測化。aigenba :550-557)
status === Reserved のときのみ Committed へ (Released は据え置き + Log::info。課金の真実源は台帳)
→ TicketCommitResult::Committed
```
- **消費行に grant と同じ `expires_at` を載せる**のが精緻化の核心。バケット失効時に `+grant` と `−consume` が同時に合算から落ち、現行 docblock の「全額失効」近似が消える（aigenba `:524-537` 同型）。
- status guard 撤去で失われる二重課金防止は **`idempotency_key` UNIQUE（`consume:{reservationId}`）が肩代わり**する（既存列。列追加なし）。`insertIdempotent()` は `kind` / `reservation_id` / `description` を含む任意属性を受ける既存実装のままで足り、戻り型のみ `void → int`（挿入行数）に変える（既存呼び出し側は戻り値を捨てる）。Query Builder 直書きで Eloquent イベントを通らないが insert のみのため append-only 不変条件は保たれる（既存 `insertIdempotent` の docblock 済み契約）。
- `release()` の意味論は不変（非 Reserved は `LogicException`）。`releaseStale()` は解放条件を `expires_at <= now OR expiredMonthlyHold` へ拡張する（aigenba `:996-1005` verbatim。単一 `consume_source` のため monthly 予約は行全体が monthly = 失効時に行ごと Released にして安全）。

**既存 reserved 行（`consume_source` 未設定の旧予約）の扱い — 決定（aigenba verbatim）**

1. **migration で backfill しない**（2 列 null のまま）。デプロイ中の並行 reserve と競合せず、誤配賦を固定しないため。
2. **hold 集計**: `sumActiveHolds` は `where('consume_source', $source)` のため legacy 行は**どちらの source にも計上されない**（aigenba `:1061` verbatim）。一方 `balance()` の `activeReservations` は全 Reserved を計上するので**表示は保守側**。結果、legacy 行が reserve を拘束しない窓が TTL 30 分だけ開く（aigenba と同一の既知窓）。
3. **commit 時**: `consume_source ?? Monthly` で monthly として計上し、`expires_at = $locked->expires_at`（予約 TTL）を境界に一回限り採用する（aigenba `:527-536` verbatim。null-expiry の不滅ゴーストを作らない）。TTL 境界は既に経過しているか 30 分以内に経過するため、当該消費行は速やかに合算から外れる = **移行期の過少課金**になるが、対象はデプロイ時に in-flight だった予約のみ（TTL 30 分で消滅）で、`Log::warning('legacy reservation without consume_source')` により可観測。aigenba の移行期挙動をそのまま採り、先回り修正しない（原則 5）。
4. `releaseStale()` が 5 分 cron で TTL 切れ legacy 行を Released 化し、window は自然終息する。

**D28（月次付与 0）後に per-source clamp が実質どう働くか**

- D28 により `PlanSeeder` の全 tier `monthly_ticket_grant = 0`（seeder 変更は P1 所管）。`StripeWebhookProcessor.php:274` の既存 guard `$plan->monthly_ticket_grant <= 0` で `invoice.paid` の月次付与は抜ける（aigenba の `if ($count < 1) return;` と同形）。
- **`monthly` バケットに残る生きた source は signup grant のみ**（`billing.signup_grant_tickets`=10 / `signup_grant_expiry_days`=30。org 生涯 1 回）。加えて dev 限定で `BughuntBillingSeeder`（無期限 100 枚。`bughunt.local` + `fake_externals` + bug_hunt DB でのみ実行）。定常状態の monthly は「登録後 30 日で必ず 0 に落ちる一過性バケット」で、**`purchased` が唯一の恒常 source**になる。
- `monthlyRaw` が負になる経路は存在しない（monthly への負計上は commit のみで、reserve が `availableMonthly >= $amount` を満たした source にしか予約を立てないため）。したがって **`max($monthly, 0)` は monthly 側では常に恒等**、clamp が実効を持つのは `purchased` 側だけ = **clawback で `purchasedRaw < 0` になった場合の表示・与信からの遮蔽**の 1 点に収束する（`TicketRefundClawbackTest:147` の `-2` がこれ）。
- したがって「clamp は現行モデルでは実質 no-op（債務の逃げ道になる生きた source が無い）」という aigenba 側の判断は、AI-CUE では「**monthly（signup grant 10 枚 / 30 日）が生きている登録後 30 日間だけ、purchased の未回収債務が monthly 残高で相殺されずに見過ごされる**」という有限窓に対応する。窓は org 生涯 1 回・最大 10 枚・30 日で、その間の返金債務は `purchasedRaw` に負値として保全され、次回購入で一度だけ自然回収される（`purchasedRaw` に加算されるため）。**この挙動は aigenba 現行仕様であり先方が verbatim 移植で問題なしと回答済み。AI-CUE 側で先回り修正しない**（原則 5）。

**DB 列 / index**

```text
ticket_reservations:
  consume_source      string     nullable  // monthly|purchased (App\Enums\Billing\TicketSource)。null = legacy
  consume_expires_at  timestamp  nullable  // monthly 消費の失効境界。null = 無期限 monthly または legacy
```
**新規 index を追加しない**: hold 集計は `where(organization_id, status)` = 既存 `['organization_id','status']`、`releaseStale` は既存 `['status','expires_at']` で覆われ、予約行は org あたり TTL 30 分の少数。`ticket_ledger_entries` は**列追加ゼロ**（`source` / `expires_at` / `idempotency_key` は既存）。**ルート変更なし**。

#### PHPStan 適合チェック

- 戻り型を明示: `balance(): TicketBalanceDto` / `availableTrueBalance(): int` / `commit(): TicketCommitResult` / `insertIdempotent(): int` / `sumBalance(): int` / `sumActiveHolds(): int` / `nearestMonthlyExpiry(): ?CarbonImmutable` / `isExpiredMonthlyHold(): bool`。`commit` の呼び出し側（`AnalysisPipeline:223` / `RenderPipeline:297`）は戻り値を捨てる（level 10 は未使用戻り値を咎めない）。
- `TicketBalanceDto` は `final readonly` + `@phpstan-type TicketBalanceShape` + `toArray(): TicketBalanceShape`。`PurchaseTicketsPageDto` の `@phpstan-type` は `balance: int` のまま（形状不変）。
- `->sum('delta')` / `->sum('amount')` は mixed → 既存踏襲で `(int)` キャスト。`->value('expires_at')` は mixed → `$v instanceof CarbonInterface ? CarbonImmutable::instance($v) : null` で null 安全に絞る（AI-CUE は `immutable_datetime` cast のため `Carbon` 決め打ちの aigenba `:1083` をそのままは使えない）。
- `expiredMonthlyHoldCondition(Builder $query, CarbonImmutable $now): void` に `@param Builder<TicketReservation> $query`（aigenba `:611` 同型）。`whereNot(fn ($w) => $this->expiredMonthlyHoldCondition($w, $now))` のクロージャ引数も同型で注釈。
- `TicketReservation` へ `@property ?TicketSource $consume_source` / `@property ?CarbonImmutable $consume_expires_at` を追加し、`casts(): array<string, string>` へ `'consume_source' => TicketSource::class` / `'consume_expires_at' => 'immutable_datetime'`（既存戻り型に適合）。
- `commit()` の `$source = $locked->consume_source ?? TicketSource::Monthly;` で null 合体してから `TicketSource` に確定させ、以降 null を伝播させない。`consume_expires_at` は `?CarbonImmutable` のまま扱い、**`Assert::isInstanceOf` で非 null を強制しない**（前述の接地事実）。
- Factory は `/** @extends Factory<TicketReservation> */` + `definition(): array<string, mixed>`、Model へ `/** @use HasFactory<TicketReservationFactory> */`。
- `TicketCommitResult` は純粋 enum。呼び出し側で分岐しないため `match` の網羅義務は発生しない。
- **型の widen・baseline 化は行わない**（禁止事項 2）。

#### テスト計画（テストファースト）

**先に red を作る新規テスト**

1. `tests/Feature/Billing/TicketBalanceAccountingTest.php`
   - monthly grant +10（30 日期限）→ reserve/commit −3 → 期限到達で **grant と消費行が同時に落ち** `monthlyRemaining = 0`（現行実装なら `-3` が残るため red）。消費行の `expires_at` が grant と同じ日時であること。
   - `balance()` が DTO（`monthlyRemaining` / `purchasedRemaining` / `activeReservations` / `nextExpireAt`）を返し、**`debt` フィールドを持たない**こと（`toArray()` のキー集合を固定）。
   - **per-source clamp**: `purchased` を clawback で `-2` にした org に monthly 10 を付与 → `purchasedRemaining = 0` / `monthlyRemaining = 10` / `totalAvailable() = 10`（clamp verbatim = monthly が purchased 債務を肩代わりしない・かつ債務を打ち消しもしない）。
   - `source IS NULL` の既存消費行が **purchased バケットへ畳まれる**（帳消しにならない = over-grant しない）。
   - `nextExpireAt` が最短の未失効・正 delta の ISO8601。`activeReservations` が拘束**枚数**（`SUM(amount)`）。
   - **無期限 monthly grant のみの org で `reserve()` が例外にならず** `consume_expires_at = null` で固定される（`BughuntBillingSeeder:63` / `TicketGrantTest:26` の経路。aigenba の `Assert` を移植した場合の red）。
2. `tests/Feature/Billing/TicketConsumeOrderTest.php`
   - 消費優先: monthly 10 / purchased 10 で `reserve(3)` → `consume_source = monthly`・`consume_expires_at = 最短 monthly 期限`。monthly 使い切り後の `reserve` は `consume_source = purchased`・`consume_expires_at = null`。
   - commit で `source = monthly` の `-3` が 1 行（**source ごとの 2 行分割をしない** = 単一 consume_source の verbatim 維持）。
   - **単一 source 容量ガード**: monthly 2 / purchased 2 で `reserve(3)` が `InsufficientTicketsException`（メッセージの残高は `max(2,2)=2`）。この状態で `purchasedRaw` が負に振れない（タダ配りが発生しない）ことを台帳で固定。
   - `availableTrueBalance()` が per-source clamp 後の合算で常に 0 以上（purchased `-2` + monthly 10 → 10）。
3. `tests/Feature/Billing/TicketCommitWinsTest.php`
   - TTL 切れで `releaseStale` に Released 化された生存予約の commit → **課金され `Committed`**（status は Released 据え置き）。
   - 再 commit → `AlreadyCommitted` かつ台帳の消費行は 1 行のみ（`consume:{id}` UNIQUE）。
   - monthly 予約の `consume_expires_at` 経過 → `ReleasedExpired`・台帳行ゼロ・status Released。無期限 monthly 予約（`consume_expires_at = null`）は TTL 経過後も `ReleasedExpired` にならず課金されること。
   - `releaseStale()` が「TTL 切れ」に加え「失効 monthly hold」も解放すること。
4. `tests/Feature/Billing/TicketLegacyReservationTest.php`
   - Factory `legacy()`（`consume_*` = null）の Reserved 行が **per-source hold に計上されない**一方、`balance()->activeReservations` には計上される（表示は保守側）。
   - legacy 行の commit が `source = monthly` / `expires_at = 予約 TTL` で 1 行計上し `Committed` を返し、`Log::warning` を出すこと（移行期の verbatim 挙動を固定）。
   - legacy 行の再 commit が `AlreadyCommitted`。
5. `tests/Feature/Billing/TicketAmountBasedReserveTest.php`（**AGENTS.md #7 / ドメイン境界の回帰**）
   - `reserve($org, 5)` が amount=5 の予約 1 行を作る（1 枚固定に退化していない）。
   - `config('manual.analysis_ticket_cost')`(1) ≠ `config('manual.render_ticket_cost')`(3) の可変コストで解析/レンダが完走する。
   - reserve→commit / reserve→release の 2 フェーズが残っている（直接デクリメントが無い = 台帳 append-only）。

**既存テストの更新（削除しない）**

- `tests/Feature/Billing/TicketLedgerTest.php`: `balance()->toBe(int)` を `balance()->totalAvailable()` へ（:26,:38,:43,:56,:61,:95,:103,:115。期待値は不変 = 回帰の網）。**:85-96「committed / released の予約は再 commit / 再 release できない」は commit-wins へ期待更新** — :92 の再 commit は `LogicException` ではなく **`TicketCommitResult::AlreadyCommitted`**（台帳の消費行は 1 行・残高 7 のまま）、:93 の**再 release は引き続き `LogicException`**（release の意味論は変えない）。:98-116 `releaseStale` は期待不変（:103 = 6 / :115 = 8）。
- `tests/Feature/Billing/TicketRefundClawbackTest.php`: :142 は API 差し替え（`balance()->totalAvailable()` = 3）。**:147 の `-2` 期待は `0` へ更新** — per-source clamp 移植の結果 `purchasedRemaining = max(-2, 0) = 0` / `totalAvailable() = 0`。併せて `purchasedNet($organization)`（同ファイル :42 のヘルパ = `source=purchased` の台帳純額）が **`-2` のまま**であること（台帳では債務が保全され、clamp は表示・与信のみに効く）と、直後の `reserve(1)` が `InsufficientTicketsException` であることを検証する。P5 後は消費行にも `source=purchased` が載るため `purchasedNet` は同じく `-2`。
- `tests/Feature/Billing/TicketGrantTest.php`（:28,:43,:58,:63,:79,:91,:101）: `balance()` の戻り値変更に伴う API 差し替え。**期待値は不変**（:63「期限付き 10 が失効し無期限 5 が残る」= 両行とも monthly バケット、:79「reserve(3)→commit で 7」= monthly からの消費、:101「signup grant が期限到達で 0」= monthly バケットの失効。per-source 化後も同値であることを同時に検証する）。
- `tests/Feature/Billing/TicketVolumeTierTest.php`: **更新なし・回帰確認のみ**（`TicketVolumePrice::currentTierFor` のみを検証し `balance()` / `reserve()` を呼ばない）。
- `tests/Feature/Billing/{TicketPurchaseWebhookTest,WebhookIdempotencyTest}.php` / `tests/Feature/Organization/InvitationTest.php:387` / `tests/Feature/Database/BughuntBillingSeederTest.php`（:50,:61,:83,:87）/ `tests/Feature/Auth/{RegistrationTest.php:29,RegistrationInvitationPrefillTest.php:178}` / `tests/Feature/Projects/AnalysisPipelineTest.php:166` / `tests/Feature/Manual/{RenderPipelineTest.php:143,:164, RenderStaleRecoveryTest.php:92, RenderTriggerTest.php:254}` / `tests/Feature/Notifications/TicketBalanceLowNotificationTest.php`（:88,:117）: `->balance($org)` → `->balance($org)->totalAvailable()` の API 差し替え。**期待値が不変であることを同時に検証する**。低残高通知はクロス判定・通知回数とも不変。
- `tests/Feature/Projects/AnalysisPipelineTest.php:294` 近傍の不変条件記述「succeeded ∧ released の非共存」を **「succeeded ∧ 無課金の非共存」へ読み替え更新**（commit-wins は Released 据え置きのまま課金するため、守るべきは「成果物を渡して無課金 = タダ乗り」と「失敗して課金」の排除であり、これは強化される）。
- テストデータは Factory 生成（手組み `new TicketReservation` を書かない）。`RefreshDatabase` グローバル・`--parallel` 前提を維持（個別 `DatabaseTransactions` を足さない）。

#### リスク

| リスク | 緩和 |
|---|---|
| **無期限 monthly grant が AI-CUE には実在する**（`BughuntBillingSeeder:63` / Filament で `monthly_ticket_grant` を戻した場合の `StripeWebhookProcessor:286`）。aigenba の `Assert::isInstanceOf(consumeExpiresAt)` を移植すると当該環境の monthly reserve が全て例外で落ちる | `nearestMonthlyExpiry()` を nullable のまま扱い Assert を移植しない。`consume_source=monthly && consume_expires_at IS NULL` = 無期限 monthly 消費（`isExpiredMonthlyHold` は false）と定義し、legacy は `consume_source IS NULL` で判別する。新規テスト 1 の必須ケースで固定 |
| **単一 consume_source のため「表示残高 4 / 各 source は 2 ずつ / cost 3」で reserve が不足になる** | 発生条件は monthly と purchased が双方非空かつ双方が cost 未満のときのみ。D28 後 monthly は signup grant 10 枚 / 30 日の一過性バケットなので窓は org 生涯 1 回。失敗は既存の `InsufficientTicketsException` 経路（購入導線への誘導）に乗り詰みにならない。sum 形ガードを採ると最大 `amount−1` 枚のタダ配りが clamp に隠れるため、金銭側を優先。新規テスト 2 で固定 |
| **commit-wins により「succeeded ∧ released」が成立し得る**（status 据え置き・課金は台帳が真実源） | 既存 guard（`AnalysisPipeline:202` の job status 検査）が cron 先勝ちケースを先に弾くため、実際に到達するのは「TTL 切れだが Running」= 成果物を渡す正当な課金ケースのみ。不変条件記述を更新し `Log::info` で可観測化（aigenba `:577-583` 同型） |
| **legacy 予約（デプロイ時 in-flight）の移行期過少課金**（monthly 計上 + `expires_at = 予約 TTL` により消費行が即失効） | aigenba `:527-536` verbatim の移行期挙動。対象は TTL 30 分以内の少数で、`Log::warning` で可観測。専用テスト 4 で挙動を固定し、`releaseStale`（5 分 cron）が window を終息させる。先回り修正しない（原則 5） |
| **legacy 予約が per-source hold に計上されず reserve を拘束しない窓**（≤ TTL 30 分） | aigenba `:1056-1069` と同一の既知窓。`balance()` の `activeReservations` は legacy も計上するため表示は保守側。window は `releaseStale` で自然終息 |
| **reserve TTL 30 分 < 長時間レンダ**。`releaseStale` が Running 中の予約を解放 → 解放枠が別 reserve に取られ、後で commit-wins が課金 → 一時的オーバーセル | aigenba と同じ既知窓。hold 側で TTL 切れを除外しない（枠を保持する）ことで窓を cron 実行間隔（5 分）に限定する。TTL 方針は現状維持（P5 のスコープを会計移植に閉じる） |
| commit の status guard 撤去で二重課金 | `idempotency_key` UNIQUE（`consume:{reservationId}`）+ org 行ロックで DB 保証。`insertIdempotent` の挿入 0 行を `Log::warning` で可観測化（aigenba `:550-557` 同型） |
| **`source IS NULL` 行の purchased 畳み込みを誤ると過去消費が帳消し**（over-grant） | 畳み込みを `sumBalance(purchased)` の 1 箇所に閉じ、新規テスト 1 が「legacy 消費行が帳消しにならない」を機械検証。台帳は無変更（append-only 維持） |
| 呼び出し側 5 箇所（`BillingController:63` / `TicketPurchaseController:66` / `DashboardService:221` / `AnalysisJobService:81` / `RenderJobService:90`）の取りこぼし | `int → TicketBalanceDto` のシグネチャ変更で PHPStan level 10 が全箇所を機械検出する |
| revert 可能性 | additive 2 列 + 読み取り計算 + props 形状不変。旧コードは `consume_*` 列と `consume:*` 台帳行を無視するだけ（台帳の置換・二重書き・差分再同期は無い） |

---


---

# 補足: レビューに必要な既存コードの文脈

- `TicketLedgerEntry` は **append-only** (Eloquent の updating / deleting イベントで例外化)。`UPDATED_AT = null`。
  列は `delta` / `kind` / `source` (nullable) / `reservation_id` / `description` / `granted_at` / `expires_at` /
  `stripe_checkout_session_id` / `payment_intent_id` / `purchase_amount` / `idempotency_key` (UNIQUE) / `created_at`。
- `insertIdempotent()` は `DB::table(...)->insertOrIgnore()` の直書き (Eloquent caster を通らないため
  `CarbonImmutable` を `toDateTimeString()` へ正規化する)。P5 で戻り型のみ `void → int` (挿入行数) に変えた。
- `TicketSource` は `monthly` / `purchased` の 2 値 backed enum (既存)。aigenba の `CreditSource::PlanMonthly` に相当。
- `release()` は `delta = 0` / `kind = release` / `source = null` の監査行を書く (P5 でも不変)。
- `grant()` (手動運用調整) は `source = null` / `expires_at = null` の正 delta 行を書く (P5 でも不変)。
- `releaseStale()` は 5 分 cron (`billing:release-stale-reservations`)。id を pluck して 1 件ずつ `release()` する
  (= Reserved でない行に当たると `LogicException`)。
- pipeline の commit 呼び出しは terminal transaction の内側 (`AnalysisPipeline::finalize` / `RenderPipeline::finalize`)。
  戻り値は捨てる。
- テストは `tests/Pest.php` のグローバル `RefreshDatabase` + `--parallel`。テストデータは必ず Factory 生成。
  `createOrganizationWithOwner()` は helper。

以上を踏まえて指摘を出せ。
