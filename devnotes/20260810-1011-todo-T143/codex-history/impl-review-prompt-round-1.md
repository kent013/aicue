## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
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

## あなたの役割

Laravel 12 + Svelte 5 (Inertia) アプリ **AI-CUE** のコードレビュアーとして、詳細設計に基づく実装差分をレビューする。

### レビュー観点
1. **設計との一致性** — 詳細設計 PR-C1 の記述どおりか。逸脱があるなら根拠が妥当か
2. **正確性** — 境界条件 (閾値の <= / null 起算 / 親子順序)、fail-closed の意味、件数報告の整合
3. **PHPStan level 10 適合** — 型の widen / ignore / baseline を使っていないか
4. **DTO / JsonResource パターン** — response()->json() 直書きなし、任意メタデータ領域なし
5. **テスト網羅性** — 「壊すと赤くなる」ことが実測されているか、空振り (vacuous green) しないか
6. **セキュリティ** — PII をログ/出力に載せていないか、テナント境界を跨ぐクエリを増やしていないか
7. **不変条件の機械化** — gate が「保証すること / 保証しないこと」を誇張なく書けているか

### 本 PR の特殊事情 (絶対に踏まえること)
- これは **5 PR に分割された設計の 3 番目 (PR-C1)** である。**PR-A / PR-B / PR-C2 / PR-C3 の内容は本 PR の範囲外**であり、
  「規約文面 (privacy blade) が無い」「日次スケジュールに登録されていない」「--apply が無い」「台帳 (ticket_ledger_entries) の
  purger が無い」のは**すべて設計どおりの意図的な未実装**である。これらを指摘として挙げないこと。
- **C1 は dry-run 専用**。規約が宣言していない削除を先に運用へ効かせないための工程分割である。

### 出力形式
- ファイルごとに判定 (OK / 要修正) を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
  - Critical: 本 PR の範囲で修正しないとマージすべきでない欠陥 (誤削除・誤検出・vacuous green・PII 漏れ等)
  - Warning: 修正が望ましい
  - Suggestion: 任意
- 最後に **全体判定: APPROVED または CHANGES_REQUESTED** を 1 行で書く

---

## 詳細設計 (オーナー決定)

### オーナー決定 (逸脱不可)

| 項目 | 値 |
|

## 詳細設計 (PR-C1 = 本 PR の範囲)

# PR-C1: 保持期間の基盤整備 (非公開)

## C1a. 保持年数の単一出典

```php
// config/legal.php へ追加
/*
| 課金取引記録の保持年数。**env を使わない** — 環境ごとに変えてよい運用値ではなく、
| 法務文書 (/privacy) が宣言する値そのものである (config/idempotency.php の
| retention_hours と同じ理由)。値の変更は規約文面の変更と同義であり、
| App\Support\Legal\BillingRetention が唯一の解決点として読む。
*/
'billing_retention_years' => 7,
```

```php
final class BillingRetention
{
    /** 保持年数 (唯一の解決点)。0 以下は fail-fast。 */
    public static function years(): int { /* config()->integer + Assert::greaterThan(0) */ }

    /** 保持期限の閾値 (これより古い起算日時は期限超過)。*NoOverflow を使う。 */
    public static function threshold(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->subYearsNoOverflow(self::years());
    }
}
```

- `subYearsNoOverflow` を使う (`CarbonOverflowArithmeticGateTest` が暗黙 overflow を禁止)。

## C1b. purge の対象目録と起算点

```php
enum BillingRetentionTarget: string
{
    case StripeWebhookEvent = 'stripe_webhook_event';
    case BillingCheckoutSession = 'billing_checkout_session';
    case TicketCheckoutSession = 'ticket_checkout_session';
    case TicketAutoRechargeAttempt = 'ticket_auto_recharge_attempt';
    case SubscriptionItem = 'subscription_item';
    case Subscription = 'subscription';
    case TicketLedgerEntry = 'ticket_ledger_entry'; // C2 で purger を実装する

    /**
     * 正規の保持起算点 (clock start)。
     * **自テーブルの列名、または `{table}.{column}` の修飾名**を返す
     * (子 target は親テーブルの列を起算点にするため修飾名が要る)。
     */
    public function clockStartColumn(): string { /* … */ }

    /**
     * **起算点が null の状態を「異常」として検出し始めるための補助時計**。
     *
     * 起算列が null だと、その列だけでは「古い」を判定できない。補助時計を使って
     * `{clockStart} IS NULL AND {anomalyClock} <= threshold` を **failClosed** に計上する
     * (例: 未処理 webhook = `processed_at IS NULL AND created_at <= threshold`)。
     *
     * **null を返す target は異常検出をしない**。`Subscription` / `SubscriptionItem` は
     * `ends_at IS NULL` が**正常な起算未到来** (継続中の契約) であって異常ではないため、
     * 明示的に対象から外す。
     */
    public function anomalyClockColumn(): ?string { /* … */ }

    /** 30 文字以上の根拠。 */
    public function rationale(): string { /* … */ }

    /** C1 時点で purger 未実装 (C2 で解消する)。 */
    public function isPendingCarryForward(): bool
    {
        return $this === self::TicketLedgerEntry;
    }
}
```

| case | 正規の起算点 (実在列で確認済み) | 補助時計 `anomalyClockColumn()` | `failClosed` の条件 | 方式 |
|---|---|---|---|---|
| `StripeWebhookEvent` | `processed_at` | `created_at` | `processed_at IS NULL AND created_at <= threshold` | 物理削除 |
| `BillingCheckoutSession` | `completed_at` | `created_at` | `completed_at IS NULL AND created_at <= threshold` | 物理削除 |
| `TicketCheckoutSession` | `completed_at` | `created_at` | `completed_at IS NULL AND created_at <= threshold` | 物理削除 |
| `TicketAutoRechargeAttempt` | `resolved_at` | `created_at` | `resolved_at IS NULL AND created_at <= threshold` | 物理削除 |
| `SubscriptionItem` | **親 Subscription の `ends_at`** (`'subscriptions.ends_at'` = 修飾名) | **null (異常検出しない)** | — (`ends_at IS NULL` は正常な起算未到来) | 物理削除 (子。親より先) |
| `Subscription` | **自身の `ends_at`**。null = 継続中 = 起算未到来 (対象外) | **null (異常検出しない)** | — (同上) | 物理削除 (親。子の後) |
| `TicketLedgerEntry` | `created_at` (取引成立日。起算済み) | **null** (正規起算点が null にならない) | — | **C2 の畳み込み** |

```php
enum BillingRetentionExclusion: string
{
    case BillingNotification = 'billing_notification';
    case TicketReservation = 'ticket_reservation';
    case Plan = 'plan';
    case PlanPrice = 'plan_price';
    case TicketVolumePrice = 'ticket_volume_price';
    case OrganizationQuota = 'organization_quota';
    case TicketAutoRecharge = 'ticket_auto_recharge';

    public function rationale(): string { /* 30 文字以上 */ }
}
```

除外の根拠 (要約。実装では 30 文字以上を各 case に書く):
- `BillingNotification` — メール送達の重複防止台帳。`(type, invoice_id)` / `(type, dedup_key)` の
  UNIQUE が冪等の調停者で、行を消すと同じ請求書の通知が再送される。取引そのものの記録ではなく、
  保持ポリシーの所有者は課金リマインダ feature。
- `TicketReservation` — TTL で解放される一時状態。所有者は既存 `billing:release-stale-reservations`。
- `Plan` / `PlanPrice` / `TicketVolumePrice` — 価格カタログであって取引記録ではない。
- `OrganizationQuota` / `TicketAutoRecharge` — 現在の設定値であって取引記録ではない。

```php
interface BillingRetentionPurger
{
    public function target(): BillingRetentionTarget;

    public function countExpired(CarbonImmutable $threshold): int;

    public function purgeExpired(CarbonImmutable $threshold): BillingRetentionPurgeResultDto;
}
```

```php
final readonly class BillingRetentionPurgeResultDto
{
    public function __construct(
        public BillingRetentionTarget $target,       // enum 型で保持 (string 化は表示境界だけ)
        public int $candidates,                      // 対象件数
        public int $processed,                       // 削除または畳み込み件数
        public int $failClosed,                      // 安全のため残した件数
        public int $unexpectedFailures,              // 想定外失敗件数
        public int $expiredRemaining,                // purge 後に残った期限超過件数
    ) {}

    public function hasFailClosedRecords(): bool { return $this->failClosed > 0; }

    public function hasUnexpectedFailures(): bool { return $this->unexpectedFailures > 0; }

    /** C3 (規約公開) に進んでよいか。**分類を問わず期限超過 0 件**が条件。 */
    public function isPublicationReady(): bool
    {
        return $this->failClosed === 0
            && $this->unexpectedFailures === 0
            && $this->expiredRemaining === 0;
    }
}
```

**`array<string, mixed>` や任意メタデータ領域は持たせない**。

## C1c. purge コマンド (dry-run のみ)

```php
protected $signature = 'billing:purge-retention-expired
    {--target= : 対象を 1 つに絞る (BillingRetentionTarget の value)}';
```

- **C1 の signature に `--apply` は存在しない** (dry-run 専用であることを signature そのもので表現する。
  「規約が宣言していない年数を先に運用へ効かせない」の機械化)。`--apply` は **C2 で追加する**。
- **C1 では `routes/console.php` に登録しない**。
- 出力は **target 別の件数のみ** (organization id / メール / 金額を出さない)。
- 終了コードは B5 と同じ 2 分類 (`hasUnexpectedFailures()` で判定。DTO に閉じる)。

## C1d. 目録 gate + horizon

`BillingRetentionTargetInventoryTest` (Architecture):
1. **母集団 exact-fit**: `app/Models/Billing/` の全モデル + `SubscriptionItem` が
   `BillingRetentionTarget` ∪ `BillingRetentionExclusion` のどちらかに**ちょうど 1 回**現れる
2. 各 case の `rationale()` が **30 文字以上**
3. 各 target の `clockStartColumn()` **および `anomalyClockColumn()` (非 null のもの)** が
   **実在する列**である (schema 照合)。**修飾名 (`{table}.{column}`) も解決して照合する**
4. **target と purger 実装クラスの exact-fit 対応** (`isPendingCarryForward()` を除く)。
   C1 時点の purger は **6 本** (`StripeWebhookEvent` / `BillingCheckoutSession` /
   `TicketCheckoutSession` / `TicketAutoRechargeAttempt` / `SubscriptionItem` / `Subscription`)
4b. **実行順が子 → 親** (`SubscriptionItem` → `Subscription`) であることを固定する
5. 空振り検知 (母集団件数 floor / 0 件で fail)
6. 負のコントロール (fixture のダミーモデルを分類しないと fail することを確認)

`BillingRetentionHorizonTest` (Feature):
- **postcondition**: purger を実行した後に、起算済み・期限超過の行が全 target で **0 件**
  (**`failClosed` を除外しない**)
- **負のコントロール**: わざと古い行を作ると赤くなる
- **保証しないもの**: 本番で日次処理が止まっていないことは保証しない
  (責務は Command の件数報告 + `FAILURE` 終了コード + scheduler 運用)
- C1 では `TicketLedgerEntry` を対象から外す (`isPendingCarryForward()` を見る)。
  **C1 は規約に何も宣言せず日次も回さない**ので、この未了が利用者に見える不整合にならない。

### テスト計画 (C1 全体)
- [ ] 新規: 各 target の**境界テスト** (起算日時が閾値の 1 秒前 / 1 秒後 → 片方だけ消える)
- [ ] 新規: 起算列が null かつ**補助時計が閾値より古い**行は fail-closed
      (削除されず `failClosed` に計上)。**境界テスト**: 補助時計が閾値の 1 秒前 / 1 秒後
- [ ] 新規: 起算列が null で**補助時計が新しい**行は failClosed にも計上されない (正常な未確定)
- [ ] 新規: `Subscription` の `ends_at IS NULL` は**何年前に作られていても failClosed にならない**
      (継続中は正常な起算未到来。異常検出の対象外)
- [ ] 新規: `Subscription` の `ends_at` が null (継続中) は**何年経っても対象外**
- [ ] 新規: `SubscriptionItem` は親の `ends_at` で判定し、**子 → 親**の順で消える
- [ ] 新規: 参照中の `Subscription` は fail-closed で残り件数が report される
- [ ] 新規: dry-run は 1 行も消さない
- [ ] 新規: 出力に PII が無い
- [ ] 新規: `BillingRetention::years()` が 0 以下なら fail-fast

---



## 詳細設計 (共通: gate の書式 / mutation 手順)

# 共通: 検査が空振りしないことの保証

新設する全 gate に以下を必ず同梱する (本リポジトリの gate 書式)。

| 手段 | 内容 |
|---|---|
| **母集団 floor** | 走査ファイル数 / route 数 / 目録件数が 0 でないことを下限で pin。0 件なら fail |
| **exact-fit cap** | 免除・allowlist の件数を**現在値ちょうど**で pin (余裕を 1 でも持たせない。
`ThrottleCoverageInventoryTest` の cap コメントと同じ理由 — 余裕枠は「根拠なしに免除できる枠」になる) |
| **負のコントロール** | fixture ソース (nowdoc 内。code token にならない) を検出器に当てて**点灯すること**を確認 |
| **自己参照コントロール** | gate ファイル自身を走査して hit 0 件 (説明コメントで偽赤にならない) |
| **正の自己検証** | 実ファイルで検出器が実際に点灯すること (検出器が死んでいないこと) |

# 共通: mutation で赤化を確認する手順

**実装完了の条件**は「テストが緑」ではなく「**壊すと赤くなることを実測した**」である。
各 gate について以下を**実行し、結果を実装ノートに記録する**。

| # | 変異 (実施後は必ず戻す) | 赤くなるべきテスト |
|---|---|---|
| M1 | `AccountDeletionPathGateTest` の起点から `deleteAccount` を外す | 空振り検知 (閉包サイズ floor) |
| M2 | `OrganizationMembershipService` に `Stripe\StripeClient` を型注入するだけの private property を足す | 依存閉包 gate 検査 2 |
| M3 | 同じ注入を `app('cashier.stripe')` の literal 呼び出しで書く | 同上 (fixture 4 形目) |
| M4 | `AccountDeletionFreezeAllowance` から `settings` を削る | 到達性テスト (取消に到達できない) |
| M5 | 同 enum に `dashboard` を足す | exact-fit 検査 3 |
| M6 | 凍結 middleware を priority list で `EnsureProjectBelongsToRouteOrganization` より**前**へ動かす | `TenantBoundaryOrderingTest` + 他組織 `{project}` が 302 になる behavioral |
| M7 | `PurgeDeletionRequestsCommand` の終了コードを常に `SUCCESS` にする | 「想定外例外で FAILURE」テスト |
| M8 | `deleteAccount` の precondition 差し込み位置をブロッカー判定の**後**へ動かす | 「抽出後に取消 → 削除しない」テスト |
| M9 | 通知の `via()` から予約生存の再確認を外す | 「予約 → 即取消 → メール 0 通」テスト |
| M10 | `BillingRetentionTarget` から `Subscription` を削る | 目録 exact-fit (母集団の分類漏れ) |
| M11 | `Subscription` の起算列を `ends_at` → `created_at` に変える | 「継続中は何年経っても対象外」テスト |
| M12 | `TicketLedgerEntry` を C1 の horizon 対象に入れる | horizon (期限超過が残る) |
| M13 | 畳み込みで `source` を捨てて 1 行に合算する | 6 種比較の「source 別残高」 |
| M13b | 畳み込みの group key から `organization_id` を外す | 7 種比較の「組織ごとの残高一致」(複数組織 fixture) |
| M14 | privacy blade の年数を literal `7` に書き換える | 三者一致 gate 検査 3 |
| M15 | privacy の保持期間の節ごと削除する | `PrivacyRetentionDeclarationTest` (a)(b)(c)(d) |
| M16 | `BillingRetentionPurgeResultDto::isPublicationReady()` から `failClosed === 0` を外す | 公開条件テスト |
| M17 | `AccountDeletionFreezeAllowance` に `settings.account.destroy` を足す | 「予約中は即時削除できない」テスト |
| M18 | `logout` を `auth`+`verified` group の中へ移す | 凍結 gate 検査 7 (`U` に含まれないこと) |
| M19 | `requestAccountDeletion` の冪等 no-op を外し予約中でも通知を発火させる | 「予約 POST 2 回でメール 1 通」テスト |
| M20 | 執行バッチの抽出条件から `whereNotNull('deletion_requested_at')` を外す | 「片列だけの非正規行を due に数えない」テスト |
| M21 | `config/account.php` の `deletion_grace_days` を 0 にする | `AccountDeletionGraceConfigTest` の fail-fast |
| M22 | `purgeAfter()` を `addDaysNoOverflow` に戻す | 「2026-01-31 の 30 日後 = 2026-03-02」behavioral |
| M23 | 通知 `via()` を `fresh() ?? $notifiable` へ戻す | 「執行済み user へ送らない」テスト |
| M24 | redaction 記録の CHECK 制約を外し片列だけ UPDATE する | migration の DB 制約テスト |
| M25 | `recent-auth.confirm` を allowlist から外す | 到達性 (d) 移譲画面へ到達できない |
| M26 | `StripeWebhookEvent` の `anomalyClockColumn()` を null にする | 「未処理の古い webhook が failClosed に計上される」テスト |
| M27 | `AccountDeletionFreezeAllowance` に `billing.auto-recharge.update` を足す | 「予約中に auto-recharge 更新が遮断される」テスト |
| M28 | users の CHECK 制約を外し片列だけ UPDATE する | migration の DB 制約テスト |
| M29 | `PortalConfigurationSpec` の `subscription_update` を `true` にする | `AccountDeletionFreezeRouteGateTest` の**前提検査 3 点** (`--verify` は spec との一致しか見ないため、前提 pin が無いと赤化しない可能性がある。**どのテストが赤くなったかを実装ノートに記録する**) |

**手順**: 1 変異ずつ適用 → 対象テストが**赤いこと**を実測 → 変異を戻す →
全体が緑に戻ることを確認 (`git diff` が空であることも確認する)。



## 実装者への申し送り (設計者から)

- この PR では規約文面に触らない (PR-C3 の担当)。`resources/views/legal/privacy.blade.php` を編集しない。
- この PR では実処理を有効化しない (PR-C2 の担当)。コマンドは `--apply` を持たない dry-run 専用にする。
- 数値は単一出典: `config/legal.php` の `billing_retention_years => 7` を `App\Support\Legal\BillingRetention` だけが読む。
  「config を読んでよいのは BillingRetention 1 箇所」という不変条件を gate で固定する。
- 目録は人間の申告である。purge 対象テーブルの網羅性は機械では保証できないので「保証しないもの」に明記する。

## 実装差分 (git diff)

```diff
diff --git a/app/Console/Commands/Billing/PurgeBillingRetentionCommand.php b/app/Console/Commands/Billing/PurgeBillingRetentionCommand.php
new file mode 100644
index 0000000..c1005d0
--- /dev/null
+++ b/app/Console/Commands/Billing/PurgeBillingRetentionCommand.php
@@ -0,0 +1,141 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Console\Commands\Billing;
+
+use App\DataTransferObjects\Billing\BillingRetentionPurgeResultDto;
+use App\Enums\Billing\BillingRetentionTarget;
+use App\Services\Billing\Contracts\BillingRetentionPurger;
+use App\Services\Billing\Retention\BillingRetentionPurgerRegistry;
+use App\Support\Legal\BillingRetention;
+use Carbon\CarbonImmutable;
+use Illuminate\Console\Command;
+use Throwable;
+
+/**
+ * 保持期限を超えた課金記録の**集計 (dry-run 専用)**。
+ *
+ * ★**`--apply` を持たない**。これは「規約が宣言していない削除を先に運用へ効かせない」の
+ *   機械化である。/privacy には保持年数の宣言がまだ無く (PR-C3 の担当)、宣言より先に
+ *   実削除を回すと、利用者が読める根拠のないままデータが消える。実処理の有効化は
+ *   **PR-C2 で `--apply` を追加してから**行う (signature そのものが工程の順序を表している)。
+ *
+ * ★出力は **target 別の件数のみ**。organization id / メールアドレス / 金額を出さない
+ *   (運用ログとチケットに課金の個別情報を写さない)。
+ *
+ * ★`ticket_ledger_entry` は C1 時点で purger 未実装 (append-only の畳み込みは PR-C2)。
+ *   「対象だが未了」であることを出力に必ず出す — 黙って集計から外すと、対象を網羅したように
+ *   見える出力になる。
+ */
+final class PurgeBillingRetentionCommand extends Command
+{
+    protected $signature = 'billing:purge-retention-expired
+        {--target= : 対象を 1 つに絞る (BillingRetentionTarget の value)}';
+
+    protected $description = '保持期限を超えた課金記録を target 別に集計する (dry-run 専用。実削除はしない)';
+
+    public function handle(BillingRetentionPurgerRegistry $registry): int
+    {
+        $filter = $this->option('target');
+        if ($filter !== null && BillingRetentionTarget::tryFrom($filter) === null) {
+            $this->error("未知の target です: {$filter}");
+            $this->line('指定できる値: '.implode(', ', array_map(
+                static fn (BillingRetentionTarget $case): string => $case->value,
+                BillingRetentionTarget::cases(),
+            )));
+
+            return self::FAILURE;
+        }
+
+        // 閾値は 1 回だけ解決して全 target へ渡す (実行中に日付が変わっても判定を揃える)。
+        $threshold = BillingRetention::threshold();
+        $this->info(sprintf(
+            '[dry-run] 保持期間 %d 年 / 閾値 %s 以前の起算日時が期限超過 (実削除はしない)',
+            BillingRetention::years(),
+            $threshold->toDateTimeString(),
+        ));
+
+        $results = [];
+        foreach ($registry->purgers() as $purger) {
+            if ($filter !== null && $purger->target()->value !== $filter) {
+                continue;
+            }
+            $results[] = $this->inspect($purger, $threshold);
+        }
+
+        foreach ($results as $result) {
+            $this->line(sprintf(
+                '  %s: expired=%d fail_closed=%d unexpected_failures=%d',
+                $result->target->value,
+                $result->candidates,
+                $result->failClosed,
+                $result->unexpectedFailures,
+            ));
+        }
+
+        $this->reportPendingTargets($filter);
+
+        $expired = array_sum(array_map(
+            static fn (BillingRetentionPurgeResultDto $result): int => $result->expiredRemaining,
+            $results,
+        ));
+        $failClosed = array_sum(array_map(
+            static fn (BillingRetentionPurgeResultDto $result): int => $result->failClosed,
+            $results,
+        ));
+        $this->info("合計: 期限超過 {$expired} 件 / fail-closed {$failClosed} 件");
+
+        $failed = array_filter(
+            $results,
+            static fn (BillingRetentionPurgeResultDto $result): bool => $result->hasUnexpectedFailures(),
+        );
+        if ($failed !== []) {
+            $this->error('集計に失敗した target があります (件数は不明として 0 で表示しています)。');
+
+            return self::FAILURE;
+        }
+
+        return self::SUCCESS;
+    }
+
+    /** 1 target を集計する (実削除は行わない)。 */
+    private function inspect(BillingRetentionPurger $purger, CarbonImmutable $threshold): BillingRetentionPurgeResultDto
+    {
+        try {
+            return BillingRetentionPurgeResultDto::dryRun(
+                target: $purger->target(),
+                candidates: $purger->countExpired($threshold),
+                failClosed: $purger->countFailClosed($threshold),
+            );
+        } catch (Throwable $e) {
+            // 例外 message は載せない (外部生成の可変文字列)。target と例外クラスだけ。
+            $this->warn(sprintf('集計失敗 target=%s (%s)', $purger->target()->value, $e::class));
+
+            // 数えられなかったので件数は不明。0 と報告するが、unexpectedFailures が
+            // 「この 0 は信用できない」ことを示す (終了コードもここから決まる)。
+            return new BillingRetentionPurgeResultDto(
+                target: $purger->target(),
+                candidates: 0,
+                processed: 0,
+                failClosed: 0,
+                unexpectedFailures: 1,
+                expiredRemaining: 0,
+            );
+        }
+    }
+
+    /** purger 未実装 (C2 待ち) の target を明示する。 */
+    private function reportPendingTargets(?string $filter): void
+    {
+        foreach (BillingRetentionTarget::cases() as $case) {
+            if (! $case->isPendingCarryForward()) {
+                continue;
+            }
+            if ($filter !== null && $case->value !== $filter) {
+                continue;
+            }
+            $this->warn("  {$case->value}: purger 未実装 (append-only の畳み込みは PR-C2)");
+        }
+    }
+}
diff --git a/app/DataTransferObjects/Billing/BillingRetentionPurgeResultDto.php b/app/DataTransferObjects/Billing/BillingRetentionPurgeResultDto.php
new file mode 100644
index 0000000..9da936a
--- /dev/null
+++ b/app/DataTransferObjects/Billing/BillingRetentionPurgeResultDto.php
@@ -0,0 +1,77 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+use App\Enums\Billing\BillingRetentionTarget;
+
+/**
+ * 保持期間 purge の 1 target 分の結果。
+ *
+ * **任意メタデータ領域 (`array<string, mixed>`) は持たせない** — 何が入るか型で分からない
+ * 領域を作ると、そこに organization id やメールアドレスが載って運用ログへ漏れる。
+ *
+ * 件数の関係:
+ *   candidates      = 起算済み・期限超過の件数 (purge 前)
+ *   processed       = 実際に削除 (C2 では畳み込み) した件数
+ *   failClosed      = 安全のため残した件数 (起算不能の異常 + 参照中で消せないもの)
+ *   expiredRemaining = purge 後に残った起算済み・期限超過の件数
+ *
+ * **`failClosed` は「安全に残した」であって「規約を満たした」ではない**。
+ * 規約 (最長 N 年) を満たしたと言えるのは `expiredRemaining === 0` のときだけである。
+ */
+final readonly class BillingRetentionPurgeResultDto
+{
+    public function __construct(
+        public BillingRetentionTarget $target,
+        public int $candidates,
+        public int $processed,
+        public int $failClosed,
+        public int $unexpectedFailures,
+        public int $expiredRemaining,
+    ) {}
+
+    /**
+     * dry-run (1 行も消さない) の結果。
+     *
+     * 何も消していないのだから残存 = 候補である (楽観的に 0 と報告しない)。
+     */
+    public static function dryRun(
+        BillingRetentionTarget $target,
+        int $candidates,
+        int $failClosed,
+    ): self {
+        return new self(
+            target: $target,
+            candidates: $candidates,
+            processed: 0,
+            failClosed: $failClosed,
+            unexpectedFailures: 0,
+            expiredRemaining: $candidates,
+        );
+    }
+
+    public function hasFailClosedRecords(): bool
+    {
+        return $this->failClosed > 0;
+    }
+
+    public function hasUnexpectedFailures(): bool
+    {
+        return $this->unexpectedFailures > 0;
+    }
+
+    /**
+     * 規約文面の公開 (PR-C3) に進んでよいか。
+     *
+     * **分類を問わず期限超過 0 件**が条件である。`failClosed` を除外して「安全に残したものは
+     * 数えない」とすると、規約が宣言した年数を超えた記録が残ったまま「準拠した」と言えてしまう。
+     */
+    public function isPublicationReady(): bool
+    {
+        return $this->failClosed === 0
+            && $this->unexpectedFailures === 0
+            && $this->expiredRemaining === 0;
+    }
+}
diff --git a/app/Enums/Billing/BillingRetentionExclusion.php b/app/Enums/Billing/BillingRetentionExclusion.php
new file mode 100644
index 0000000..4fe79fa
--- /dev/null
+++ b/app/Enums/Billing/BillingRetentionExclusion.php
@@ -0,0 +1,70 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Billing;
+
+use App\Models\Billing\BillingNotification;
+use App\Models\Billing\OrganizationQuota;
+use App\Models\Billing\Plan;
+use App\Models\Billing\PlanPrice;
+use App\Models\Billing\TicketAutoRecharge;
+use App\Models\Billing\TicketReservation;
+use App\Models\Billing\TicketVolumePrice;
+use Illuminate\Database\Eloquent\Model;
+
+/**
+ * 保持期間 (7 年) の purge 対象**外**と裁定した課金モデルの目録。
+ *
+ * 「取引記録ではない」か「保持ポリシーの所有者が別にいる」かのどちらかであること。
+ * {@see BillingRetentionTarget} との合計が課金モデルの母集団と exact-fit であることは
+ * `BillingRetentionTargetInventoryTest` が deny-by-default で機械強制する。
+ *
+ * ★除外は「消さない」の宣言であって「消せない」ではない。所有者が別にいるものは
+ *   **その所有者の側で保持期間を持つ**こと (ここで二重に持つと決着が分岐する)。
+ */
+enum BillingRetentionExclusion: string
+{
+    case BillingNotification = 'billing_notification';
+    case TicketReservation = 'ticket_reservation';
+    case Plan = 'plan';
+    case PlanPrice = 'plan_price';
+    case TicketVolumePrice = 'ticket_volume_price';
+    case OrganizationQuota = 'organization_quota';
+    case TicketAutoRecharge = 'ticket_auto_recharge';
+
+    /**
+     * 対象モデル (母集団との突合キー)。
+     *
+     * @return class-string<Model>
+     */
+    public function modelClass(): string
+    {
+        return match ($this) {
+            self::BillingNotification => BillingNotification::class,
+            self::TicketReservation => TicketReservation::class,
+            self::Plan => Plan::class,
+            self::PlanPrice => PlanPrice::class,
+            self::TicketVolumePrice => TicketVolumePrice::class,
+            self::OrganizationQuota => OrganizationQuota::class,
+            self::TicketAutoRecharge => TicketAutoRecharge::class,
+        };
+    }
+
+    /** なぜ保持期間の対象にしないのか (30 文字以上)。 */
+    public function rationale(): string
+    {
+        return match ($this) {
+            self::BillingNotification => 'メール送達の重複防止台帳。UNIQUE が冪等の調停者であり、消すと同じ請求書の通知が再送される。'
+                .'保持ポリシーの所有者は課金リマインダ機能である',
+            self::TicketReservation => 'TTL で解放される一時状態であって取引記録ではない。'
+                .'所有者は既存の billing:release-stale-reservations である',
+            self::Plan => '価格カタログ (現在提供している商品の定義) であって取引の記録ではない',
+            self::PlanPrice => 'Stripe Price のカタログ snapshot であって取引の記録ではない。過去行は価格改定の履歴として残す',
+            self::TicketVolumePrice => 'チケット単価のカタログ snapshot であって取引の記録ではない。過去行は価格改定の履歴として残す',
+            self::OrganizationQuota => '組織ごとの現在の上限設定値 (容量・人数) であって取引の記録ではない。契約中は常に参照される',
+            self::TicketAutoRecharge => 'オートリチャージの現在の設定値と同意記録であって取引の記録ではない。'
+                .'実際の課金試行は ticket_auto_recharge_attempts が持つ',
+        };
+    }
+}
diff --git a/app/Enums/Billing/BillingRetentionTarget.php b/app/Enums/Billing/BillingRetentionTarget.php
new file mode 100644
index 0000000..6c90363
--- /dev/null
+++ b/app/Enums/Billing/BillingRetentionTarget.php
@@ -0,0 +1,131 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Billing;
+
+use App\Models\Billing\BillingCheckoutSession;
+use App\Models\Billing\StripeWebhookEvent;
+use App\Models\Billing\Subscription;
+use App\Models\Billing\TicketAutoRechargeAttempt;
+use App\Models\Billing\TicketCheckoutSession;
+use App\Models\Billing\TicketLedgerEntry;
+use Illuminate\Database\Eloquent\Model;
+use Laravel\Cashier\SubscriptionItem;
+
+/**
+ * 保持期間 (7 年) の purge 対象となる課金記録の目録。
+ *
+ * 「消さない」と決めたものは {@see BillingRetentionExclusion} へ根拠付きで登録する。
+ * 母集団 (app/Models/Billing 配下 + Cashier の SubscriptionItem) がどちらかに
+ * ちょうど 1 回現れることは `BillingRetentionTargetInventoryTest` が deny-by-default で
+ * 機械強制する (テストクラスへの参照は app → tests の import を生むため書かない)。
+ *
+ * ★**目録は人間の申告である**。網羅性は機械では保証できない — 課金取引の記録が
+ *   app/Models/Billing の外や Eloquent を経由しない表に置かれれば、目録も gate も沈黙する。
+ */
+enum BillingRetentionTarget: string
+{
+    case StripeWebhookEvent = 'stripe_webhook_event';
+    case BillingCheckoutSession = 'billing_checkout_session';
+    case TicketCheckoutSession = 'ticket_checkout_session';
+    case TicketAutoRechargeAttempt = 'ticket_auto_recharge_attempt';
+    case SubscriptionItem = 'subscription_item';
+    case Subscription = 'subscription';
+    case TicketLedgerEntry = 'ticket_ledger_entry';
+
+    /**
+     * 対象モデル (母集団との突合キー)。
+     *
+     * @return class-string<Model>
+     */
+    public function modelClass(): string
+    {
+        return match ($this) {
+            self::StripeWebhookEvent => StripeWebhookEvent::class,
+            self::BillingCheckoutSession => BillingCheckoutSession::class,
+            self::TicketCheckoutSession => TicketCheckoutSession::class,
+            self::TicketAutoRechargeAttempt => TicketAutoRechargeAttempt::class,
+            self::SubscriptionItem => SubscriptionItem::class,
+            self::Subscription => Subscription::class,
+            self::TicketLedgerEntry => TicketLedgerEntry::class,
+        };
+    }
+
+    /** 対象テーブル (起算点の修飾名を解決する基準)。 */
+    public function table(): string
+    {
+        $model = $this->modelClass();
+
+        return (new $model)->getTable();
+    }
+
+    /**
+     * 正規の保持起算点 (clock start)。
+     *
+     * **自テーブルの列名、または `{table}.{column}` の修飾名**を返す
+     * (子 target は親テーブルの列を起算点にするため修飾名が要る)。
+     */
+    public function clockStartColumn(): string
+    {
+        return match ($this) {
+            self::StripeWebhookEvent => 'processed_at',
+            self::BillingCheckoutSession, self::TicketCheckoutSession => 'completed_at',
+            self::TicketAutoRechargeAttempt => 'resolved_at',
+            // 子は親 (subscriptions) の契約終了日で判定する
+            self::SubscriptionItem => 'subscriptions.ends_at',
+            self::Subscription => 'ends_at',
+            // 台帳は取引成立の時点で起算済み (null にならない)
+            self::TicketLedgerEntry => 'created_at',
+        };
+    }
+
+    /**
+     * **起算点が null の状態を「異常」として検出し始めるための補助時計**。
+     *
+     * 起算列が null だと、その列だけでは「古い」を判定できない。補助時計を使って
+     * `{clockStart} IS NULL AND {anomalyClock} <= threshold` を **fail-closed** に計上する
+     * (例: 未処理 webhook = `processed_at IS NULL AND created_at <= threshold`)。
+     * 計上するだけで**消さない** — 判定できないものを消すのは fail-open である。
+     *
+     * **null を返す target は異常検出をしない**。`Subscription` / `SubscriptionItem` は
+     * `ends_at IS NULL` が**正常な起算未到来** (継続中の契約) であって異常ではないため、
+     * 明示的に対象から外す。`TicketLedgerEntry` は正規の起算点が null にならない。
+     */
+    public function anomalyClockColumn(): ?string
+    {
+        return match ($this) {
+            self::StripeWebhookEvent,
+            self::BillingCheckoutSession,
+            self::TicketCheckoutSession,
+            self::TicketAutoRechargeAttempt => 'created_at',
+            self::SubscriptionItem, self::Subscription, self::TicketLedgerEntry => null,
+        };
+    }
+
+    /** なぜこれが保持期間の対象なのか (30 文字以上)。 */
+    public function rationale(): string
+    {
+        return match ($this) {
+            self::StripeWebhookEvent => '決済事業者からの通知そのものの記録。処理完了 (processed_at) をもって取引の決着とみなす',
+            self::BillingCheckoutSession => 'サブスク契約の決済手続きの記録。完了時刻 (completed_at) が取引の成立日時である',
+            self::TicketCheckoutSession => 'チケット買い切り購入の決済手続きの記録。完了時刻 (completed_at) が取引の成立日時である',
+            self::TicketAutoRechargeAttempt => 'オートリチャージの課金試行の記録。決着時刻 (resolved_at) をもって取引の終了とみなす',
+            self::SubscriptionItem => '継続課金の明細 (価格・数量) の記録。親契約の終了日 (subscriptions.ends_at) で起算する',
+            self::Subscription => '継続課金契約そのものの記録。契約終了日 (ends_at) が保持期間の起算点である',
+            self::TicketLedgerEntry => 'チケット残高の取引台帳。append-only のため物理削除ではなく繰越 (畳み込み) で決着させる',
+        };
+    }
+
+    /**
+     * C1 時点で purger 未実装 (C2 の畳み込みで解消する)。
+     *
+     * `ticket_ledger_entries` は append-only (残高の真実源) であり、物理削除すると
+     * 残高が変わる。保持期間の決着は「古い行を残高スナップショットへ畳み込む」形になり、
+     * その設計と検証は PR-C2 の担当である。
+     */
+    public function isPendingCarryForward(): bool
+    {
+        return $this === self::TicketLedgerEntry;
+    }
+}
diff --git a/app/Models/Billing/StripeWebhookEvent.php b/app/Models/Billing/StripeWebhookEvent.php
index 0af9797..31a3e3f 100644
--- a/app/Models/Billing/StripeWebhookEvent.php
+++ b/app/Models/Billing/StripeWebhookEvent.php
@@ -6,6 +6,8 @@
 
 use App\Enums\Billing\WebhookEventStatus;
 use Carbon\CarbonImmutable;
+use Database\Factories\Billing\StripeWebhookEventFactory;
+use Illuminate\Database\Eloquent\Factories\HasFactory;
 use Illuminate\Database\Eloquent\Model;
 
 /**
@@ -25,6 +27,9 @@
  */
 class StripeWebhookEvent extends Model
 {
+    /** @use HasFactory<StripeWebhookEventFactory> */
+    use HasFactory;
+
     /**
      * @return array<string, string>
      */
diff --git a/app/Services/Billing/Contracts/BillingRetentionPurger.php b/app/Services/Billing/Contracts/BillingRetentionPurger.php
new file mode 100644
index 0000000..172a36e
--- /dev/null
+++ b/app/Services/Billing/Contracts/BillingRetentionPurger.php
@@ -0,0 +1,41 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing\Contracts;
+
+use App\DataTransferObjects\Billing\BillingRetentionPurgeResultDto;
+use App\Enums\Billing\BillingRetentionTarget;
+use Carbon\CarbonImmutable;
+
+/**
+ * 保持期間 (7 年) を超えた課金記録を target ごとに決着させる purger。
+ *
+ * 実装は `app/Services/Billing/Retention/` に置き、
+ * `App\Services\Billing\Retention\BillingRetentionPurgerRegistry` へ**実行順で**登録する
+ * (子 target は親より先。親を先に消すと FK cascade で子が件数報告を経由せず消える)。
+ * target と実装の exact-fit は `BillingRetentionTargetInventoryTest` が機械強制する。
+ *
+ * **閾値 (`$threshold`) は呼び出し側が `BillingRetention::threshold()` で 1 回だけ解決して
+ * 全 purger へ渡す**。purger が各自で now を読むと、実行中に日付が変わったときに
+ * target ごとに違う閾値で判定されうる。
+ */
+interface BillingRetentionPurger
+{
+    public function target(): BillingRetentionTarget;
+
+    /** 起算済み (起算列が非 null) かつ期限超過の件数。**horizon 検査の観測点**。 */
+    public function countExpired(CarbonImmutable $threshold): int;
+
+    /**
+     * 安全のため残す件数。
+     *
+     * 内訳は 2 つ — (a) 起算列が null で補助時計が閾値より古い**異常** (判定できないものを
+     * 消さない) と、(b) 期限超過だが他から参照されていて消せないもの。
+     * どちらも「消さなかった」事実の報告であり、**規約を満たした証明ではない**。
+     */
+    public function countFailClosed(CarbonImmutable $threshold): int;
+
+    /** 期限超過の記録を決着させる (dry-run では**呼ばない**)。 */
+    public function purgeExpired(CarbonImmutable $threshold): BillingRetentionPurgeResultDto;
+}
diff --git a/app/Services/Billing/Retention/AbstractBillingRetentionPurger.php b/app/Services/Billing/Retention/AbstractBillingRetentionPurger.php
new file mode 100644
index 0000000..f6d2e59
--- /dev/null
+++ b/app/Services/Billing/Retention/AbstractBillingRetentionPurger.php
@@ -0,0 +1,140 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing\Retention;
+
+use App\DataTransferObjects\Billing\BillingRetentionPurgeResultDto;
+use App\Services\Billing\Contracts\BillingRetentionPurger;
+use Carbon\CarbonImmutable;
+use Illuminate\Database\Eloquent\Builder;
+use Illuminate\Database\Eloquent\Collection;
+use Illuminate\Database\Eloquent\Model;
+use Illuminate\Support\Facades\Log;
+use Throwable;
+
+/**
+ * 物理削除で決着する purger の共通実装。
+ *
+ * 起算点 (`clockStartColumn`) と補助時計 (`anomalyClockColumn`) は目録 (enum) が正本で、
+ * ここでは**自テーブルの列**であることを前提に機械的にクエリへ落とす。親テーブルの列を
+ * 起算点にする子 target は {@see expiredQuery()} を override する。
+ *
+ * ★削除は**行単位**で行う (`$model->delete()`)。1 本の DELETE で消すと、1 行の失敗で
+ *   バッチ全体が落ちて「1 件も消えない日」が続く。行単位なら失敗を件数として報告でき、
+ *   残りは進む。行の取り出しは `chunkById` (削除しながらでも取りこぼさない)。
+ *
+ * @template TModel of Model
+ */
+abstract class AbstractBillingRetentionPurger implements BillingRetentionPurger
+{
+    /** 1 回の取り出し件数。 */
+    private const int CHUNK_SIZE = 500;
+
+    /** @return Builder<TModel> */
+    abstract protected function baseQuery(): Builder;
+
+    public function countExpired(CarbonImmutable $threshold): int
+    {
+        return $this->expiredQuery($threshold)->count();
+    }
+
+    public function countFailClosed(CarbonImmutable $threshold): int
+    {
+        $anomaly = $this->anomalyQuery($threshold);
+        $blocked = $this->blockedQuery($threshold);
+
+        return ($anomaly === null ? 0 : $anomaly->count())
+            + ($blocked === null ? 0 : $blocked->count());
+    }
+
+    public function purgeExpired(CarbonImmutable $threshold): BillingRetentionPurgeResultDto
+    {
+        $candidates = $this->countExpired($threshold);
+        $processed = 0;
+        $unexpectedFailures = 0;
+
+        $this->deletableQuery($threshold)->chunkById(
+            self::CHUNK_SIZE,
+            function (Collection $rows) use (&$processed, &$unexpectedFailures): void {
+                foreach ($rows as $row) {
+                    try {
+                        $row->delete();
+                        $processed++;
+                    } catch (Throwable $e) {
+                        $unexpectedFailures++;
+                        // 例外 message は載せない (外部生成の可変文字列)。target と例外クラスだけ。
+                        Log::warning('billing retention purge failed', [
+                            'target' => $this->target()->value,
+                            'error_class' => $e::class,
+                        ]);
+                    }
+                }
+            },
+        );
+
+        return new BillingRetentionPurgeResultDto(
+            target: $this->target(),
+            candidates: $candidates,
+            processed: $processed,
+            failClosed: $this->countFailClosed($threshold),
+            unexpectedFailures: $unexpectedFailures,
+            expiredRemaining: $this->countExpired($threshold),
+        );
+    }
+
+    /**
+     * 起算済み (起算列が非 null) かつ期限超過の行。
+     *
+     * @return Builder<TModel>
+     */
+    protected function expiredQuery(CarbonImmutable $threshold): Builder
+    {
+        $column = $this->target()->clockStartColumn();
+
+        return $this->baseQuery()
+            ->whereNotNull($column)
+            ->where($column, '<=', $threshold);
+    }
+
+    /**
+     * 起算列が null のまま補助時計が閾値より古い**異常**の行 (消さずに計上する)。
+     *
+     * 補助時計を持たない target は null を返す (= 異常検出をしない)。
+     *
+     * @return Builder<TModel>|null
+     */
+    protected function anomalyQuery(CarbonImmutable $threshold): ?Builder
+    {
+        $anomalyClock = $this->target()->anomalyClockColumn();
+        if ($anomalyClock === null) {
+            return null;
+        }
+
+        return $this->baseQuery()
+            ->whereNull($this->target()->clockStartColumn())
+            ->where($anomalyClock, '<=', $threshold);
+    }
+
+    /**
+     * 期限超過だが他から参照されていて消せない行 (消さずに計上する)。
+     *
+     * 既定は「参照制約なし」。参照を持つ target は override する。
+     *
+     * @return Builder<TModel>|null
+     */
+    protected function blockedQuery(CarbonImmutable $threshold): ?Builder
+    {
+        return null;
+    }
+
+    /**
+     * 実際に削除する行 (期限超過のうち参照中でないもの)。
+     *
+     * @return Builder<TModel>
+     */
+    protected function deletableQuery(CarbonImmutable $threshold): Builder
+    {
+        return $this->expiredQuery($threshold);
+    }
+}
diff --git a/app/Services/Billing/Retention/BillingCheckoutSessionPurger.php b/app/Services/Billing/Retention/BillingCheckoutSessionPurger.php
new file mode 100644
index 0000000..312bc08
--- /dev/null
+++ b/app/Services/Billing/Retention/BillingCheckoutSessionPurger.php
@@ -0,0 +1,31 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing\Retention;
+
+use App\Enums\Billing\BillingRetentionTarget;
+use App\Models\Billing\BillingCheckoutSession;
+use Illuminate\Database\Eloquent\Builder;
+
+/**
+ * サブスク契約 Checkout の追跡行の purge (起算点 = completed_at)。
+ *
+ * 未完了 (pending / expired / failed) のまま保持期限を超えた行は**異常**として計上する
+ * (完了しなかった手続きは「取引の終了時」を持たないため、起算できない)。
+ *
+ * @extends AbstractBillingRetentionPurger<BillingCheckoutSession>
+ */
+final class BillingCheckoutSessionPurger extends AbstractBillingRetentionPurger
+{
+    public function target(): BillingRetentionTarget
+    {
+        return BillingRetentionTarget::BillingCheckoutSession;
+    }
+
+    /** @return Builder<BillingCheckoutSession> */
+    protected function baseQuery(): Builder
+    {
+        return BillingCheckoutSession::query();
+    }
+}
diff --git a/app/Services/Billing/Retention/BillingRetentionPurgerRegistry.php b/app/Services/Billing/Retention/BillingRetentionPurgerRegistry.php
new file mode 100644
index 0000000..9ee7a2d
--- /dev/null
+++ b/app/Services/Billing/Retention/BillingRetentionPurgerRegistry.php
@@ -0,0 +1,57 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing\Retention;
+
+use App\Services\Billing\Contracts\BillingRetentionPurger;
+use Webmozart\Assert\Assert;
+
+/**
+ * 保持期間 purger の**実行順つき目録**。
+ *
+ * ★順序は契約である: **子 target を親より先に置く**。親 (`subscriptions`) を先に消すと
+ *   FK cascade で子 (`subscription_items`) が件数報告を経由せず道連れになり、
+ *   「何件消したか」の報告が嘘になる。
+ *
+ * 登録漏れの purger は実行されず期限超過が黙って残るため、
+ * `BillingRetentionTargetInventoryTest` が「ディレクトリ上の実装 ⇔ 本目録 ⇔ enum の target」の
+ * 3 者 exact-fit を deny-by-default で機械強制する。
+ */
+final class BillingRetentionPurgerRegistry
+{
+    /**
+     * 実行順の purger 実装クラス。
+     *
+     * @return list<class-string<BillingRetentionPurger>>
+     */
+    public static function purgerClasses(): array
+    {
+        return [
+            StripeWebhookEventPurger::class,
+            BillingCheckoutSessionPurger::class,
+            TicketCheckoutSessionPurger::class,
+            TicketAutoRechargeAttemptPurger::class,
+            // 子 → 親 の順 (入れ替えない)
+            SubscriptionItemPurger::class,
+            SubscriptionPurger::class,
+        ];
+    }
+
+    /**
+     * 実行順の purger インスタンス。
+     *
+     * @return list<BillingRetentionPurger>
+     */
+    public function purgers(): array
+    {
+        $purgers = [];
+        foreach (self::purgerClasses() as $class) {
+            $purger = app($class);
+            Assert::isInstanceOf($purger, BillingRetentionPurger::class);
+            $purgers[] = $purger;
+        }
+
+        return $purgers;
+    }
+}
diff --git a/app/Services/Billing/Retention/StripeWebhookEventPurger.php b/app/Services/Billing/Retention/StripeWebhookEventPurger.php
new file mode 100644
index 0000000..4c537ab
--- /dev/null
+++ b/app/Services/Billing/Retention/StripeWebhookEventPurger.php
@@ -0,0 +1,31 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing\Retention;
+
+use App\Enums\Billing\BillingRetentionTarget;
+use App\Models\Billing\StripeWebhookEvent;
+use Illuminate\Database\Eloquent\Builder;
+
+/**
+ * 決済事業者の webhook 記録の purge (起算点 = processed_at)。
+ *
+ * 未処理 (processed_at IS NULL) のまま保持期限を超えた行は**異常**として計上するだけで
+ * 消さない (取引が決着していない記録を、決着したことにして捨てない)。
+ *
+ * @extends AbstractBillingRetentionPurger<StripeWebhookEvent>
+ */
+final class StripeWebhookEventPurger extends AbstractBillingRetentionPurger
+{
+    public function target(): BillingRetentionTarget
+    {
+        return BillingRetentionTarget::StripeWebhookEvent;
+    }
+
+    /** @return Builder<StripeWebhookEvent> */
+    protected function baseQuery(): Builder
+    {
+        return StripeWebhookEvent::query();
+    }
+}
diff --git a/app/Services/Billing/Retention/SubscriptionItemPurger.php b/app/Services/Billing/Retention/SubscriptionItemPurger.php
new file mode 100644
index 0000000..1482c60
--- /dev/null
+++ b/app/Services/Billing/Retention/SubscriptionItemPurger.php
@@ -0,0 +1,57 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing\Retention;
+
+use App\Enums\Billing\BillingRetentionTarget;
+use App\Models\Billing\Subscription;
+use Carbon\CarbonImmutable;
+use Illuminate\Database\Eloquent\Builder;
+use Laravel\Cashier\SubscriptionItem;
+
+/**
+ * 継続課金の明細 (subscription_items) の purge。
+ *
+ * **起算点は自テーブルに無い** — 明細は「いつ終わったか」を持たず、終了時刻は親契約の
+ * `subscriptions.ends_at` である。よって目録の起算点は修飾名 `subscriptions.ends_at` で、
+ * ここでは親への `whereHas` に落とす。親が継続中 (`ends_at IS NULL`) の明細は
+ * **起算未到来**であって異常ではない (補助時計を持たない = 異常検出をしない)。
+ *
+ * 親を先に消せば FK cascade で明細も消えるが、**それでは何件消えたかを報告できない**
+ * (規約準拠の証明は件数で行う)。子 → 親の順で明示的に消す。
+ *
+ * @extends AbstractBillingRetentionPurger<SubscriptionItem>
+ */
+final class SubscriptionItemPurger extends AbstractBillingRetentionPurger
+{
+    public function target(): BillingRetentionTarget
+    {
+        return BillingRetentionTarget::SubscriptionItem;
+    }
+
+    /** @return Builder<SubscriptionItem> */
+    protected function baseQuery(): Builder
+    {
+        return SubscriptionItem::query();
+    }
+
+    /**
+     * 親契約が終了済み (ends_at 非 null) かつ期限超過の明細。
+     *
+     * 親の絞り込みは**副問合せ**で書く (`whereHas` のクロージャは親の型が
+     * 静的に決まらず、型検査の効かない場所を作るため)。
+     *
+     * @return Builder<SubscriptionItem>
+     */
+    protected function expiredQuery(CarbonImmutable $threshold): Builder
+    {
+        return $this->baseQuery()->whereIn(
+            'subscription_id',
+            Subscription::query()
+                ->whereNotNull('ends_at')
+                ->where('ends_at', '<=', $threshold)
+                ->select('id'),
+        );
+    }
+}
diff --git a/app/Services/Billing/Retention/SubscriptionPurger.php b/app/Services/Billing/Retention/SubscriptionPurger.php
new file mode 100644
index 0000000..edc6e87
--- /dev/null
+++ b/app/Services/Billing/Retention/SubscriptionPurger.php
@@ -0,0 +1,57 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing\Retention;
+
+use App\Enums\Billing\BillingRetentionTarget;
+use App\Models\Billing\Subscription;
+use Carbon\CarbonImmutable;
+use Illuminate\Database\Eloquent\Builder;
+
+/**
+ * 継続課金契約 (subscriptions) の purge (起算点 = ends_at)。
+ *
+ * ★`ends_at IS NULL` は**継続中の契約 = 正常な起算未到来**であり、何年前に作られていても
+ *   期限超過にも異常にもならない (補助時計を持たない)。ここを `created_at` で起算すると
+ *   **生きている契約の記録を消す**。
+ *
+ * ★明細 (`subscription_items`) が残っている契約は**消さない** (fail-closed)。FK は
+ *   cascade なので DELETE 自体は成功してしまうが、それは子 purger が決着させられなかった
+ *   行を件数報告を経由せず道連れにすることを意味する。残して報告する方を採る。
+ *
+ * @extends AbstractBillingRetentionPurger<Subscription>
+ */
+final class SubscriptionPurger extends AbstractBillingRetentionPurger
+{
+    public function target(): BillingRetentionTarget
+    {
+        return BillingRetentionTarget::Subscription;
+    }
+
+    /** @return Builder<Subscription> */
+    protected function baseQuery(): Builder
+    {
+        return Subscription::query();
+    }
+
+    /**
+     * 期限超過だが明細が残っている契約 (消さずに計上する)。
+     *
+     * @return Builder<Subscription>
+     */
+    protected function blockedQuery(CarbonImmutable $threshold): Builder
+    {
+        return $this->expiredQuery($threshold)->has('items');
+    }
+
+    /**
+     * 実際に削除する契約 = 期限超過かつ明細が残っていないもの。
+     *
+     * @return Builder<Subscription>
+     */
+    protected function deletableQuery(CarbonImmutable $threshold): Builder
+    {
+        return $this->expiredQuery($threshold)->doesntHave('items');
+    }
+}
diff --git a/app/Services/Billing/Retention/TicketAutoRechargeAttemptPurger.php b/app/Services/Billing/Retention/TicketAutoRechargeAttemptPurger.php
new file mode 100644
index 0000000..9ec0588
--- /dev/null
+++ b/app/Services/Billing/Retention/TicketAutoRechargeAttemptPurger.php
@@ -0,0 +1,31 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing\Retention;
+
+use App\Enums\Billing\BillingRetentionTarget;
+use App\Models\Billing\TicketAutoRechargeAttempt;
+use Illuminate\Database\Eloquent\Builder;
+
+/**
+ * オートリチャージの課金試行の purge (起算点 = resolved_at)。
+ *
+ * pending のまま保持期限を超えた行は**異常**として計上する (資金回収済み・チケット未付与の
+ * 滞留が何年も残っている状態であり、消してよい記録ではない)。
+ *
+ * @extends AbstractBillingRetentionPurger<TicketAutoRechargeAttempt>
+ */
+final class TicketAutoRechargeAttemptPurger extends AbstractBillingRetentionPurger
+{
+    public function target(): BillingRetentionTarget
+    {
+        return BillingRetentionTarget::TicketAutoRechargeAttempt;
+    }
+
+    /** @return Builder<TicketAutoRechargeAttempt> */
+    protected function baseQuery(): Builder
+    {
+        return TicketAutoRechargeAttempt::query();
+    }
+}
diff --git a/app/Services/Billing/Retention/TicketCheckoutSessionPurger.php b/app/Services/Billing/Retention/TicketCheckoutSessionPurger.php
new file mode 100644
index 0000000..1b456f5
--- /dev/null
+++ b/app/Services/Billing/Retention/TicketCheckoutSessionPurger.php
@@ -0,0 +1,28 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing\Retention;
+
+use App\Enums\Billing\BillingRetentionTarget;
+use App\Models\Billing\TicketCheckoutSession;
+use Illuminate\Database\Eloquent\Builder;
+
+/**
+ * チケット買い切り購入 Checkout の追跡行の purge (起算点 = completed_at)。
+ *
+ * @extends AbstractBillingRetentionPurger<TicketCheckoutSession>
+ */
+final class TicketCheckoutSessionPurger extends AbstractBillingRetentionPurger
+{
+    public function target(): BillingRetentionTarget
+    {
+        return BillingRetentionTarget::TicketCheckoutSession;
+    }
+
+    /** @return Builder<TicketCheckoutSession> */
+    protected function baseQuery(): Builder
+    {
+        return TicketCheckoutSession::query();
+    }
+}
diff --git a/app/Support/Legal/BillingRetention.php b/app/Support/Legal/BillingRetention.php
new file mode 100644
index 0000000..b19fef3
--- /dev/null
+++ b/app/Support/Legal/BillingRetention.php
@@ -0,0 +1,56 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Legal;
+
+use Carbon\CarbonImmutable;
+use Webmozart\Assert\Assert;
+
+/**
+ * 課金取引記録の保持年数 (config/legal.php) への**唯一の解決点 (SSOT)**。
+ *
+ * この数値は「環境ごとに変えてよい運用値」ではなく、**法務文書 (/privacy) が宣言する
+ * 値そのもの**である。読む場所が分岐すると「規約が宣言した年数」と「実際に消える年数」が
+ * 静かにズレる — 利用者から見て検証不能な形で規約違反が起きる。よって
+ * `config('legal.billing_retention_years')` を読んでよいのは本クラス 1 箇所だけとし、
+ * それを `tests/Architecture/BillingRetentionConfigSingleSourceTest.php` が
+ * deny-by-default で機械固定する (テストクラスへの参照は app → tests の import を
+ * 生むため {@see} では書かない)。
+ *
+ * - 状態も DB 参照も持たない (設定アクセサ + fail-fast のみ)。
+ * - 0 以下は設定漏れであり、そのまま threshold を計算すると**未来の時刻**が閾値になり
+ *   「まだ保持すべき記録を消す」向きに壊れる。よって **fail-fast** する
+ *   (fail-open にしない)。
+ *
+ * 対象外: 問い合わせ (Inquiry) の保持日数 `legal.inquiry_retention_days` は別概念であり
+ * 本クラスは一切関与しない (所有者は inquiry:purge)。
+ */
+final class BillingRetention
+{
+    /**
+     * 保持年数。
+     *
+     * @throws \InvalidArgumentException 未設定 / 非整数 / 0 以下のとき
+     */
+    public static function years(): int
+    {
+        /** @var mixed $years */
+        $years = config('legal.billing_retention_years');
+        Assert::integer($years, 'config(legal.billing_retention_years) must be an int.');
+        Assert::greaterThan($years, 0, 'config(legal.billing_retention_years) must be positive.');
+
+        return $years;
+    }
+
+    /**
+     * 保持期限の閾値。**これ以前 (境界を含む) の起算日時を持つ記録は期限超過**である。
+     *
+     * 年の加減算は暗黙 overflow を禁止する規約に従い `subYearsNoOverflow` を使う
+     * (CarbonOverflowArithmeticGateTest が検出する)。
+     */
+    public static function threshold(?CarbonImmutable $now = null): CarbonImmutable
+    {
+        return ($now ?? CarbonImmutable::now())->subYearsNoOverflow(self::years());
+    }
+}
diff --git a/config/legal.php b/config/legal.php
index 95b9c4e..9e9adc9 100644
--- a/config/legal.php
+++ b/config/legal.php
@@ -27,6 +27,15 @@
     */
     'inquiry_retention_days' => (int) env('LEGAL_INQUIRY_RETENTION_DAYS', 365),
 
+    /*
+    | 課金取引記録の保持年数。**env を使わない** — 環境ごとに変えてよい運用値ではなく、
+    | 法務文書 (/privacy) が宣言する値そのものである (config/idempotency.php の
+    | retention_hours と同じ理由)。値の変更は規約文面の変更と同義であり、
+    | App\Support\Legal\BillingRetention が唯一の解決点として読む
+    | (直読は BillingRetentionConfigSingleSourceTest が deny-by-default で禁止する)。
+    */
+    'billing_retention_years' => 7,
+
     /*
     | 問い合わせ受付通知 (運営宛) の宛先。INQUIRY_RECIPIENT 優先、未設定時は
     | MAIL_FROM_ADDRESS、それも未設定なら mail.php と同一の default に fallback
diff --git a/database/factories/Billing/StripeWebhookEventFactory.php b/database/factories/Billing/StripeWebhookEventFactory.php
new file mode 100644
index 0000000..55e3add
--- /dev/null
+++ b/database/factories/Billing/StripeWebhookEventFactory.php
@@ -0,0 +1,59 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Database\Factories\Billing;
+
+use App\Enums\Billing\WebhookEventStatus;
+use App\Models\Billing\StripeWebhookEvent;
+use Carbon\CarbonImmutable;
+use Illuminate\Database\Eloquent\Factories\Factory;
+use Illuminate\Support\Str;
+
+/**
+ * 既定は受信済み・未処理 (processed_at が null) の webhook 記録。
+ *
+ * `processed()` で処理完了 (= 保持期間の起算済み) の行を作る。
+ *
+ * @extends Factory<StripeWebhookEvent>
+ */
+class StripeWebhookEventFactory extends Factory
+{
+    protected $model = StripeWebhookEvent::class;
+
+    /**
+     * @return array<string, mixed>
+     */
+    public function definition(): array
+    {
+        return [
+            'event_id' => 'evt_test_'.Str::random(24),
+            'type' => 'customer.subscription.updated',
+            'status' => WebhookEventStatus::Received,
+            'payload' => ['id' => 'evt_test', 'type' => 'customer.subscription.updated'],
+            'attempts' => 0,
+            'failure_reason' => null,
+            'processed_at' => null,
+        ];
+    }
+
+    /** 処理完了 (保持期間の起算済み) の行。 */
+    public function processed(?CarbonImmutable $processedAt = null): static
+    {
+        return $this->state(fn (): array => [
+            'status' => WebhookEventStatus::Processed,
+            'processed_at' => $processedAt ?? CarbonImmutable::now(),
+        ]);
+    }
+
+    /** 処理失敗のまま滞留している行 (起算されない)。 */
+    public function failed(): static
+    {
+        return $this->state(fn (): array => [
+            'status' => WebhookEventStatus::Failed,
+            'attempts' => 1,
+            'failure_reason' => 'test failure',
+            'processed_at' => null,
+        ]);
+    }
+}
diff --git a/docs/factories.md b/docs/factories.md
index 1f04d00..3461b16 100644
--- a/docs/factories.md
+++ b/docs/factories.md
@@ -44,6 +44,7 @@ ## Factory 一覧 (テンプレート同梱)
 | `Billing\TicketReservationFactory` | Billing/TicketReservation | `forOrganization($org)`, `legacy()` (P5 前の in-flight 予約 = `consume_*` null), `monthlyHold(?CarbonImmutable $consumeExpiresAt = null)`, `purchasedHold()`, `stale()` (reserved のまま TTL 超過) |
 | `Billing\BillingCheckoutSessionFactory` | Billing/BillingCheckoutSession | `withAttemptToken($token, ?$checkoutUrl)`, `initiatedBy(int $userId)`, `completed()`, `setupPaymentMethod()`, `expired()`, `failed()`, `stale()` (pending のまま created_at が stale 境界より過去), `withAttempt($token, $planCode)` (契約 attempt の token + plan を同時固定), `fundingAutoRecharge()` (T1004 の PM 流用対象), `pmReuseDispatched(?$at)` (PM 流用 Job dispatch marker) |
 | `Billing\TicketAutoRechargeFactory` | Billing/TicketAutoRecharge | `enabled()` (PM + 同意記録済み), `preConsented()` (事前同意のみ = pendingAutoEnable), `consentedMaxAmount(int $amount)` (価格改定 → 再同意シナリオ), `disabledByFailures()` |
+| `Billing\StripeWebhookEventFactory` | Billing/StripeWebhookEvent | `processed(?CarbonImmutable $processedAt = null)` (保持期間の起算済み), `failed()` (処理失敗のまま滞留 = 起算されない)。既定は受信済み・未処理 |
 | `Billing\TicketAutoRechargeAttemptFactory` | Billing/TicketAutoRechargeAttempt | `withInvoice(?string $invoiceId = null)`, `paid()`, `failed()`, `canceled()` (既定は invoice 未作成の pending。**org あたり pending は DB partial unique で 1 件まで**) |
 
 Factory を持たないモデル (Role / Permission / Team 等) は seed 固定値
diff --git a/tests/Architecture/BillingRetentionConfigSingleSourceTest.php b/tests/Architecture/BillingRetentionConfigSingleSourceTest.php
new file mode 100644
index 0000000..2eb4377
--- /dev/null
+++ b/tests/Architecture/BillingRetentionConfigSingleSourceTest.php
@@ -0,0 +1,223 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Support\Legal\BillingRetention;
+
+/*
+ * Architecture invariant: 課金取引記録の保持年数 (legal.billing_retention_years) の
+ * **解決点は App\Support\Legal\BillingRetention の 1 箇所だけ**である。
+ *
+ * SoT = devnotes/20260809-0908-account-deletion-grace/detailed-design.md の PR-C1 (C1a)
+ * とオーナー決定 (課金取引記録の保持 = 7 年)。
+ *
+ * 背景: この数値は「環境ごとに変えてよい運用値」ではなく、**法務文書 (/privacy) が
+ * 宣言する値そのもの**である。読む場所が分岐すると「規約が宣言した年数」と
+ * 「実際に消える年数」が静かにズレる — 利用者から見て検証不能な形で規約違反が起きる。
+ * よって (a) env を使わない (b) config を読むのは SSOT クラス 1 箇所だけ、を機械固定する。
+ *
+ * ★この gate が保証するもの:
+ *   - 検査 1: `'legal.billing_retention_years'` を読むのは BillingRetention だけ (app/ 走査)
+ *   - 検査 2: config/legal.php の値が **整数リテラル**である (env() 経由で環境依存にしない)
+ *     かつ**オーナー決定の 7** である
+ *   - 検査 3: 実行時の `BillingRetention::years()` が config リテラルと一致する
+ *   - 検査 4: 空振り検知 (走査ファイル数 / token 数が 0 でない) と
+ *     正の自己検証 (SSOT ファイルで検出器が実際に点灯する)
+ *   - 検査 5: 負のコントロール (fixture ソースで点灯 / コメント中の表記は点灯しない)
+ *
+ * ★この gate が保証しないもの (誇張しない):
+ *   - **tests/ は走査しない**。保持年数の fail-fast (0 以下) を検証するテストは
+ *     config を書き換える必要があり、そこを禁止すると検査そのものが書けなくなる
+ *   - **規約文面 (privacy blade) との一致は見ない**。文面はまだ存在せず (PR-C3 の担当)、
+ *     三者一致 (config / SSOT / 文面) の gate は PR-C3 で本 gate の上に積む
+ *   - 動的キー組み立て (`config('legal.'.$key)`) には沈黙する (実測 0 件)
+ *
+ * 検出方式は LegalConsentVersionSingleSourceTest と同じ token 走査
+ * (regex にすると本ファイルの説明コメント自身で偽赤になる)。DB 不使用。
+ */
+
+/** 設定キー: SSOT だけが読んでよい。 */
+const BILLING_RETENTION_CONFIG_KEY = 'legal.billing_retention_years';
+
+/** config/legal.php 内での素のキー名。 */
+const BILLING_RETENTION_CONFIG_BARE_KEY = 'billing_retention_years';
+
+/** 単一出典クラス (repo ルート相対)。 */
+const BILLING_RETENTION_SOURCE_FILE = 'app/Support/Legal/BillingRetention.php';
+
+/** オーナー決定の保持年数 (逸脱不可。変更は規約文面の変更と同義)。 */
+const BILLING_RETENTION_OWNER_DECIDED_YEARS = 7;
+
+/**
+ * 1 ソースを走査して出現数を返す (純関数 = 負のコントロールから直接呼べる)。
+ *
+ * @return array{configKey: int, tokens: int}
+ */
+function billingRetentionScanSource(string $source): array
+{
+    $result = ['configKey' => 0, 'tokens' => 0];
+
+    foreach (token_get_all($source) as $token) {
+        if (! is_array($token)) {
+            continue;
+        }
+        $result['tokens']++;
+        if ($token[0] !== T_CONSTANT_ENCAPSED_STRING) {
+            continue;
+        }
+        if (trim($token[1], "'\"") === BILLING_RETENTION_CONFIG_KEY) {
+            $result['configKey']++;
+        }
+    }
+
+    return $result;
+}
+
+/**
+ * repo ルート相対パス => 走査結果。
+ *
+ * @param  list<string>  $dirs
+ * @return array<string, array{configKey: int, tokens: int}>
+ */
+function billingRetentionScanTree(array $dirs): array
+{
+    $root = base_path();
+    $scanned = [];
+
+    foreach ($dirs as $dir) {
+        $iterator = new RecursiveIteratorIterator(
+            new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS),
+        );
+        foreach ($iterator as $file) {
+            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
+                continue;
+            }
+            $absolute = $file->getRealPath();
+            if (! is_string($absolute)) {
+                continue;
+            }
+            $source = file_get_contents($absolute);
+            if (! is_string($source)) {
+                continue;
+            }
+            $scanned[substr($absolute, strlen($root) + 1)] = billingRetentionScanSource($source);
+        }
+    }
+
+    ksort($scanned);
+
+    return $scanned;
+}
+
+/**
+ * config/legal.php の `billing_retention_years => <値>` の値トークンを返す。
+ *
+ * 値が単一の整数リテラルでなければ null (= env() やクラス定数を挟んだ形は不合格)。
+ */
+function billingRetentionConfigLiteral(): ?int
+{
+    $source = file_get_contents(base_path('config/legal.php'));
+    if (! is_string($source)) {
+        return null;
+    }
+
+    $tokens = array_values(array_filter(
+        token_get_all($source),
+        static fn (array|string $token): bool => ! is_array($token)
+            || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
+    ));
+
+    $count = count($tokens);
+    for ($i = 0; $i < $count - 3; $i++) {
+        $token = $tokens[$i];
+        if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
+            continue;
+        }
+        if (trim($token[1], "'\"") !== BILLING_RETENTION_CONFIG_BARE_KEY) {
+            continue;
+        }
+        $arrow = $tokens[$i + 1];
+        $value = $tokens[$i + 2];
+        $terminator = $tokens[$i + 3];
+        if (! is_array($arrow) || $arrow[0] !== T_DOUBLE_ARROW) {
+            return null;
+        }
+        if (! is_array($value) || $value[0] !== T_LNUMBER) {
+            return null; // env(...) / 定数 / 式は不合格
+        }
+        if ($terminator !== ',' && $terminator !== ')' && $terminator !== ']') {
+            return null;
+        }
+
+        return (int) $value[1];
+    }
+
+    return null;
+}
+
+test('検査 1: 保持年数の config キーを読むのは BillingRetention だけである', function (): void {
+    $violations = [];
+    foreach (billingRetentionScanTree(['app', 'config', 'database', 'routes']) as $relative => $scan) {
+        if ($scan['configKey'] > 0 && $relative !== BILLING_RETENTION_SOURCE_FILE) {
+            $violations[] = $relative;
+        }
+    }
+
+    expect($violations)->toBe([],
+        'config キー legal.billing_retention_years の直読を検出しました。保持年数は '
+        .'App\Support\Legal\BillingRetention::years() 経由で取得してください '
+        .'(規約が宣言する年数と実処理を 1 箇所で対応づけるため)。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('検査 2: config/legal.php の保持年数が env を挟まない整数リテラル 7 である', function (): void {
+    $literal = billingRetentionConfigLiteral();
+
+    expect($literal)->not->toBeNull(
+        'config/legal.php の billing_retention_years が整数リテラルではありません。'
+        .'env() を挟むと環境ごとに保持年数が変わり、規約の宣言が環境依存の嘘になります。');
+    expect($literal)->toBe(BILLING_RETENTION_OWNER_DECIDED_YEARS,
+        '保持年数はオーナー決定 (7 年) です。変更は規約文面の変更と同義であり、'
+        .'このテストと /privacy の文面を同じ PR で更新すること。');
+});
+
+test('検査 3: 実行時の BillingRetention::years() が config リテラルと一致する', function (): void {
+    expect(BillingRetention::years())->toBe(billingRetentionConfigLiteral());
+});
+
+test('検査 4: 空振り検知と正の自己検証', function (): void {
+    $scanned = billingRetentionScanTree(['app', 'config', 'database', 'routes']);
+
+    expect(count($scanned))->toBeGreaterThan(0);
+    expect(array_sum(array_column($scanned, 'tokens')))->toBeGreaterThan(0);
+
+    // 検出器が死んでいたら検査 1 は vacuous green になる。SSOT では必ず 1 件点灯する。
+    expect($scanned[BILLING_RETENTION_SOURCE_FILE]['configKey'])->toBe(1);
+});
+
+test('検査 5: 負のコントロール (リテラルは検出し、コメント中の表記は検出しない)', function (): void {
+    $code = <<<'PHP'
+    <?php
+    class Fixture {
+        public function run(): void {
+            $a = config('legal.billing_retention_years');
+            $b = config("legal.billing_retention_years");
+        }
+    }
+    PHP;
+
+    $comment = <<<'PHP'
+    <?php
+    /**
+     * config('legal.billing_retention_years') を直読してはならない。
+     */
+    class Fixture {
+        // config('legal.billing_retention_years')
+        public function run(): void {}
+    }
+    PHP;
+
+    expect(billingRetentionScanSource($code)['configKey'])->toBe(2);
+    expect(billingRetentionScanSource($comment)['configKey'])->toBe(0);
+    expect(billingRetentionScanSource($comment)['tokens'])->toBeGreaterThan(0);
+});
diff --git a/tests/Architecture/BillingRetentionTargetInventoryTest.php b/tests/Architecture/BillingRetentionTargetInventoryTest.php
new file mode 100644
index 0000000..315aa6c
--- /dev/null
+++ b/tests/Architecture/BillingRetentionTargetInventoryTest.php
@@ -0,0 +1,333 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\BillingRetentionExclusion;
+use App\Enums\Billing\BillingRetentionTarget;
+use App\Services\Billing\Contracts\BillingRetentionPurger;
+use App\Services\Billing\Retention\BillingRetentionPurgerRegistry;
+use Illuminate\Database\Eloquent\Model;
+
+/*
+ * Architecture invariant: **課金記録の保持期間 (7 年) の purge 目録は deny-by-default**。
+ *
+ * SoT = devnotes/20260809-0908-account-deletion-grace/detailed-design.md の PR-C1
+ * (lctl 台帳 feature `account-deletion-billing-guard` 標準形 v1 / 裁定 AG-128 の必須 (3)
+ * 「保持期間 = 規約が宣言する年数と実処理の対応づけ」)。
+ *
+ * ★この gate が保証するもの:
+ *   - 検査 1: 課金モデルの母集団 (app/Models/Billing/** + Cashier の SubscriptionItem) が
+ *     `BillingRetentionTarget` ∪ `BillingRetentionExclusion` に **ちょうど 1 回**現れる
+ *     (新しい課金モデルを足したら「消す / 消さない」を必ず宣言させる)
+ *   - 検査 2: 全 case の rationale() が 30 文字以上
+ *   - 検査 3: 起算点 / 補助時計の**修飾名が構造的に解決できる** (`{table}.{column}` の
+ *     table 部が目録内の実在 target のテーブルである)
+ *   - 検査 4: target と purger 実装クラスが exact-fit (registry / ディレクトリ / enum の 3 者)
+ *   - 検査 4b: 実行順が**子 → 親** (`SubscriptionItem` → `Subscription`)
+ *   - 検査 5: 空振り検知 (母集団件数 / 目録件数 / purger 件数を**現在値ちょうど**で pin。
+ *     余裕枠は「根拠なしに増やせる枠」になるため持たせない)
+ *   - 検査 6: 負のコントロール (未分類のダミーモデルを混ぜると検査 1 が点灯する)
+ *
+ * ★この gate が保証しないもの (誇張しない):
+ *   - **purge 対象テーブルの網羅性は保証しない**。母集団は `app/Models/Billing/` という
+ *     **ディレクトリの人間の申告**であり、課金取引の記録が別ディレクトリ (例: app/Models/ 直下 /
+ *     別ドメインのモデル) や Eloquent を経由しない表に置かれた場合、この gate は**沈黙する**。
+ *     目録は「機械が見つけた全部」ではなく「人間が申告した全部」である
+ *   - **列が実在するか**は静的には見ない (Architecture lane は DB を持たない)。
+ *     実在照合は tests/Feature/Billing/BillingRetentionHorizonTest.php が担う
+ *   - **起算点の意味の正しさ** (その列が本当に「取引の終了時」か) は人間のレビュー対象である
+ *   - 保持年数の値そのもの / 規約文面との一致は本 gate の担当ではない
+ *     (前者は tests/Architecture/BillingRetentionConfigSingleSourceTest.php、
+ *      後者は PR-C3 の三者一致 gate)
+ *
+ * DB 不使用の静的検査 (Architecture lane の作法)。
+ */
+
+/**
+ * app/Models/Billing/ の外にある母集団 (理由付きの明示列挙)。
+ *
+ * `Laravel\Cashier\SubscriptionItem` は vendor のモデルだが `subscription_items` は
+ * 自リポジトリの migration が作る課金取引の記録であり、親 (`subscriptions`) を消すなら
+ * 一緒に決着させないと孤児になる。vendor 由来を理由に母集団から外さない。
+ *
+ * @var array<class-string<Model>, string>
+ */
+const BILLING_RETENTION_EXTERNAL_POPULATION = [
+    'Laravel\Cashier\SubscriptionItem' => 'subscription_items は自リポジトリの migration が作る課金記録 (親 subscriptions の子)',
+];
+
+/** 母集団の**現在件数** (cap ではなく exact-fit。増減したら必ずこの数字を書き換える)。 */
+const BILLING_RETENTION_POPULATION_COUNT = 14;
+
+/** C1 時点の purger 実装数 (TicketLedgerEntry は C2 の畳み込み待ちで含まない)。 */
+const BILLING_RETENTION_PURGER_COUNT = 6;
+
+/**
+ * 母集団: app/Models/Billing 配下の全 Model + 外部列挙。
+ *
+ * @return list<class-string<Model>>
+ */
+function billingRetentionPopulation(): array
+{
+    $classes = [];
+    $base = app_path('Models/Billing');
+    $iterator = new RecursiveIteratorIterator(
+        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
+    );
+    /** @var SplFileInfo $file */
+    foreach ($iterator as $file) {
+        if (! $file->isFile() || $file->getExtension() !== 'php') {
+            continue;
+        }
+        $relative = str_replace([$base.'/', '.php', '/'], ['', '', '\\'], $file->getPathname());
+        $class = 'App\\Models\\Billing\\'.$relative;
+        if (class_exists($class) && is_subclass_of($class, Model::class)) {
+            $classes[] = $class;
+        }
+    }
+
+    foreach (array_keys(BILLING_RETENTION_EXTERNAL_POPULATION) as $class) {
+        if (class_exists($class) && is_subclass_of($class, Model::class)) {
+            $classes[] = $class;
+        }
+    }
+
+    sort($classes);
+
+    return $classes;
+}
+
+/**
+ * 目録が宣言しているモデルクラス => 宣言箇所ラベル (重複検出のため list で持つ)。
+ *
+ * @return array<class-string<Model>, list<string>>
+ */
+function billingRetentionDeclarations(): array
+{
+    $declared = [];
+    foreach (BillingRetentionTarget::cases() as $case) {
+        $declared[$case->modelClass()][] = 'target:'.$case->value;
+    }
+    foreach (BillingRetentionExclusion::cases() as $case) {
+        $declared[$case->modelClass()][] = 'exclusion:'.$case->value;
+    }
+
+    return $declared;
+}
+
+/**
+ * 母集団と目録の突合 (純関数 = 負のコントロールから直接呼べる)。
+ *
+ * @param  list<class-string<Model>>  $population
+ * @param  array<class-string<Model>, list<string>>  $declared
+ * @return array{unclassified: list<string>, duplicated: list<string>, phantom: list<string>}
+ */
+function billingRetentionClassify(array $population, array $declared): array
+{
+    $unclassified = [];
+    $duplicated = [];
+    foreach ($population as $class) {
+        $labels = $declared[$class] ?? [];
+        if ($labels === []) {
+            $unclassified[] = $class;
+
+            continue;
+        }
+        if (count($labels) > 1) {
+            $duplicated[] = $class.' ('.implode(' / ', $labels).')';
+        }
+    }
+
+    $phantom = [];
+    foreach (array_keys($declared) as $class) {
+        if (! in_array($class, $population, true)) {
+            $phantom[] = $class;
+        }
+    }
+
+    return ['unclassified' => $unclassified, 'duplicated' => $duplicated, 'phantom' => $phantom];
+}
+
+/** 目録内の全 target のテーブル名。 */
+function billingRetentionKnownTables(): array
+{
+    return array_map(
+        static fn (BillingRetentionTarget $case): string => $case->table(),
+        BillingRetentionTarget::cases(),
+    );
+}
+
+/**
+ * `app/Services/Billing/Retention/` 配下で BillingRetentionPurger を実装するクラス。
+ *
+ * @return list<class-string<BillingRetentionPurger>>
+ */
+function billingRetentionPurgerClassesOnDisk(): array
+{
+    $classes = [];
+    $base = app_path('Services/Billing/Retention');
+    /** @var SplFileInfo $file */
+    foreach (new DirectoryIterator($base) as $file) {
+        if (! $file->isFile() || $file->getExtension() !== 'php') {
+            continue;
+        }
+        $class = 'App\\Services\\Billing\\Retention\\'.$file->getBasename('.php');
+        if (! class_exists($class)) {
+            continue;
+        }
+        $reflection = new ReflectionClass($class);
+        if ($reflection->isAbstract() || ! $reflection->implementsInterface(BillingRetentionPurger::class)) {
+            continue;
+        }
+        $classes[] = $class;
+    }
+
+    sort($classes);
+
+    return $classes;
+}
+
+test('検査 1: 課金モデルの母集団が target / exclusion にちょうど 1 回分類されている', function (): void {
+    $result = billingRetentionClassify(billingRetentionPopulation(), billingRetentionDeclarations());
+
+    expect($result['unclassified'])->toBe([],
+        '保持期間の分類が無い課金モデルを検出しました。BillingRetentionTarget (消す) か '
+        .'BillingRetentionExclusion (消さない) のどちらかへ根拠付きで登録してください。'
+        .PHP_EOL.implode(PHP_EOL, $result['unclassified']));
+
+    expect($result['duplicated'])->toBe([], '同一モデルが 2 か所で分類されています: '
+        .implode(', ', $result['duplicated']));
+
+    expect($result['phantom'])->toBe([],
+        '母集団に実在しないモデルが目録に登録されています (削除されたモデルの残置): '
+        .implode(', ', $result['phantom']));
+});
+
+test('検査 2: 全 case の rationale が 30 文字以上である', function (): void {
+    $short = [];
+    foreach (BillingRetentionTarget::cases() as $case) {
+        if (mb_strlen($case->rationale()) < 30) {
+            $short[] = 'target:'.$case->value;
+        }
+    }
+    foreach (BillingRetentionExclusion::cases() as $case) {
+        if (mb_strlen($case->rationale()) < 30) {
+            $short[] = 'exclusion:'.$case->value;
+        }
+    }
+
+    expect($short)->toBe([], '根拠が 30 文字未満の case: '.implode(', ', $short));
+});
+
+test('検査 3: 起算点 / 補助時計の修飾名が目録内のテーブルへ解決できる', function (): void {
+    $known = billingRetentionKnownTables();
+    $violations = [];
+
+    foreach (BillingRetentionTarget::cases() as $case) {
+        foreach ([$case->clockStartColumn(), $case->anomalyClockColumn()] as $column) {
+            if ($column === null) {
+                continue;
+            }
+            if (! str_contains($column, '.')) {
+                continue; // 自テーブルの列 (実在照合は Feature lane)
+            }
+            [$table] = explode('.', $column, 2);
+            if (! in_array($table, $known, true)) {
+                $violations[] = $case->value.' => '.$column;
+            }
+        }
+    }
+
+    expect($violations)->toBe([],
+        '修飾名の table 部が目録内の target テーブルに存在しません (親テーブルの綴り間違い?): '
+        .implode(', ', $violations));
+
+    // 正の自己検証: 修飾名の形が 1 件以上実在する (検査が形だけになっていない)
+    $qualified = array_filter(
+        BillingRetentionTarget::cases(),
+        static fn (BillingRetentionTarget $case): bool => str_contains($case->clockStartColumn(), '.'),
+    );
+    expect($qualified)->not->toBeEmpty();
+});
+
+test('検査 4: target と purger 実装クラスが exact-fit である', function (): void {
+    $registry = BillingRetentionPurgerRegistry::purgerClasses();
+    $onDisk = billingRetentionPurgerClassesOnDisk();
+
+    $sortedRegistry = $registry;
+    sort($sortedRegistry);
+    expect($sortedRegistry)->toBe($onDisk,
+        'app/Services/Billing/Retention/ の purger 実装と registry の登録が一致しません '
+        .'(登録漏れの purger は実行されず、期限超過が黙って残ります)。');
+
+    $registeredTargets = array_map(
+        static fn (string $class): string => (new $class)->target()->value,
+        $registry,
+    );
+    sort($registeredTargets);
+
+    $expected = array_map(
+        static fn (BillingRetentionTarget $case): string => $case->value,
+        array_filter(
+            BillingRetentionTarget::cases(),
+            static fn (BillingRetentionTarget $case): bool => ! $case->isPendingCarryForward(),
+        ),
+    );
+    sort($expected);
+
+    expect($registeredTargets)->toBe($expected,
+        'purger を持つべき target と実装が一致しません (isPendingCarryForward() の target を除く)。');
+
+    // 1 target につき purger は 1 本 (重複登録で二重実行しない)
+    expect(count(array_unique($registeredTargets)))->toBe(count($registeredTargets));
+});
+
+test('検査 4b: 実行順が子 → 親である (SubscriptionItem → Subscription)', function (): void {
+    $order = array_map(
+        static fn (string $class): string => (new $class)->target()->value,
+        BillingRetentionPurgerRegistry::purgerClasses(),
+    );
+
+    $child = array_search(BillingRetentionTarget::SubscriptionItem->value, $order, true);
+    $parent = array_search(BillingRetentionTarget::Subscription->value, $order, true);
+
+    expect($child)->toBeInt();
+    expect($parent)->toBeInt();
+    expect($child)->toBeLessThan($parent,
+        '親 (subscriptions) を先に消すと FK cascade で子 (subscription_items) が'
+        .'件数報告を経由せず消える。子 → 親の順を維持すること。');
+});
+
+test('検査 5: 空振り検知 (母集団 / 目録 / purger の件数を現在値ちょうどで pin)', function (): void {
+    expect(billingRetentionPopulation())->toHaveCount(BILLING_RETENTION_POPULATION_COUNT);
+    expect(BillingRetentionTarget::cases())->toHaveCount(7);
+    expect(BillingRetentionExclusion::cases())->toHaveCount(7);
+    expect(BillingRetentionPurgerRegistry::purgerClasses())->toHaveCount(BILLING_RETENTION_PURGER_COUNT);
+    expect(billingRetentionPurgerClassesOnDisk())->toHaveCount(BILLING_RETENTION_PURGER_COUNT);
+    expect(BILLING_RETENTION_EXTERNAL_POPULATION)->not->toBeEmpty();
+});
+
+test('負のコントロール: 未分類のモデルを母集団へ混ぜると検査 1 が点灯する', function (): void {
+    $population = billingRetentionPopulation();
+    $population[] = 'App\Models\Billing\DummyUnclassifiedModel';
+
+    $result = billingRetentionClassify($population, billingRetentionDeclarations());
+
+    expect($result['unclassified'])->toBe(['App\Models\Billing\DummyUnclassifiedModel']);
+    expect($result['duplicated'])->toBe([]);
+    expect($result['phantom'])->toBe([]);
+});
+
+test('負のコントロール: 二重分類と幽霊登録を検出する', function (): void {
+    $population = ['App\Models\Billing\Alpha', 'App\Models\Billing\Beta'];
+    $declared = [
+        'App\Models\Billing\Alpha' => ['target:alpha', 'exclusion:alpha'],
+        'App\Models\Billing\Gamma' => ['target:gamma'],
+    ];
+
+    $result = billingRetentionClassify($population, $declared);
+
+    expect($result['unclassified'])->toBe(['App\Models\Billing\Beta']);
+    expect($result['duplicated'])->toBe(['App\Models\Billing\Alpha (target:alpha / exclusion:alpha)']);
+    expect($result['phantom'])->toBe(['App\Models\Billing\Gamma']);
+});
diff --git a/tests/Feature/Billing/BillingRetentionHorizonTest.php b/tests/Feature/Billing/BillingRetentionHorizonTest.php
new file mode 100644
index 0000000..94186fc
--- /dev/null
+++ b/tests/Feature/Billing/BillingRetentionHorizonTest.php
@@ -0,0 +1,135 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\BillingRetentionTarget;
+use App\Models\Billing\Subscription;
+use App\Services\Billing\Contracts\BillingRetentionPurger;
+use App\Services\Billing\Retention\BillingRetentionPurgerRegistry;
+use App\Support\Legal\BillingRetention;
+use Illuminate\Support\Facades\Schema;
+use Tests\Support\Billing\BillingRetentionFixtures;
+
+/*
+ * 保持期間の **horizon (地平線) 検査**: purger を一通り実行したあと、
+ * 起算済み・期限超過の記録が **1 件も残らない**ことを固定する。
+ *
+ * ★なぜ「target ごとに消えた」ではなく horizon で見るか:
+ *   target 単位のテストは「その target が消えた」ことしか言わない。規約 (最長 N 年) が
+ *   要求するのは**全体として超過が残らない**ことであり、target を 1 つ足して purger を
+ *   書き忘れたときに赤くなるのは horizon 側だけである。
+ *
+ * ★`failClosed` を除外しない。「安全のため残した」も**規約から見れば残存**である。
+ *
+ * ★この検査が保証しないもの (誇張しない):
+ *   - **本番で日次処理が止まっていないこと**は保証しない (責務は Command の件数報告 +
+ *     FAILURE 終了コード + scheduler 運用。C1 では日次登録すらしていない)
+ *   - **目録の網羅性**は保証しない (BillingRetentionTarget は人間の申告である)
+ *   - C1 時点では `ticket_ledger_entry` (append-only の畳み込み待ち) を対象から外している。
+ *     C1 は規約に何も宣言せず日次も回さないため、この未了は利用者に見える不整合にならない。
+ *     C2 で畳み込みを実装したら `isPendingCarryForward()` が false になり、本検査の
+ *     母集団へ自動的に入る (除外を書き足す必要がない = 外し忘れが起きない)
+ */
+
+/** C1 時点で horizon の母集団に入る target。 */
+function billingRetentionHorizonTargets(): array
+{
+    return array_values(array_filter(
+        BillingRetentionTarget::cases(),
+        static fn (BillingRetentionTarget $case): bool => ! $case->isPendingCarryForward(),
+    ));
+}
+
+/** @return list<BillingRetentionPurger> */
+function billingRetentionPurgersInOrder(): array
+{
+    return app(BillingRetentionPurgerRegistry::class)->purgers();
+}
+
+test('起算点と補助時計の列が実在する (修飾名も解決する)', function (): void {
+    $missing = [];
+
+    foreach (BillingRetentionTarget::cases() as $case) {
+        foreach ([$case->clockStartColumn(), $case->anomalyClockColumn()] as $column) {
+            if ($column === null) {
+                continue;
+            }
+            $table = $case->table();
+            if (str_contains($column, '.')) {
+                [$table, $column] = explode('.', $column, 2);
+            }
+            if (! Schema::hasColumn($table, $column)) {
+                $missing[] = $case->value.' => '.$table.'.'.$column;
+            }
+        }
+    }
+
+    expect($missing)->toBe([], '目録が宣言した列が実在しません: '.implode(', ', $missing));
+
+    // 空振り検知: 実在しない列は確かに false になる (検査器が常に true を返していない)
+    expect(Schema::hasColumn('subscriptions', 'ends_at'))->toBeTrue();
+    expect(Schema::hasColumn('subscriptions', 'no_such_column'))->toBeFalse();
+});
+
+test('postcondition: purger を一通り実行すると期限超過が全 target で 0 件になる', function (): void {
+    $threshold = BillingRetention::threshold();
+    BillingRetentionFixtures::seedExpiredRows($threshold);
+
+    // 前提: 実行前は確かに超過が存在する (空振りで green にならない)
+    $before = 0;
+    foreach (billingRetentionPurgersInOrder() as $purger) {
+        $before += $purger->countExpired($threshold);
+    }
+    expect($before)->toBeGreaterThan(0);
+
+    $remaining = [];
+    foreach (billingRetentionPurgersInOrder() as $purger) {
+        $result = $purger->purgeExpired($threshold);
+        expect($result->unexpectedFailures)->toBe(0, $purger->target()->value);
+        if (! $result->isPublicationReady()) {
+            $remaining[] = sprintf(
+                '%s: expiredRemaining=%d failClosed=%d',
+                $result->target->value,
+                $result->expiredRemaining,
+                $result->failClosed,
+            );
+        }
+    }
+
+    expect($remaining)->toBe([], '保持期限を超えた記録が残っています: '.implode(' / ', $remaining));
+
+    foreach (billingRetentionPurgersInOrder() as $purger) {
+        expect($purger->countExpired($threshold))->toBe(0, $purger->target()->value);
+    }
+});
+
+test('負のコントロール: purge 後に古い記録を作ると horizon が満たされなくなる', function (): void {
+    $threshold = BillingRetention::threshold();
+
+    foreach (billingRetentionPurgersInOrder() as $purger) {
+        $purger->purgeExpired($threshold);
+    }
+
+    BillingRetentionFixtures::endedSubscription($threshold->subDay());
+
+    $expired = 0;
+    foreach (billingRetentionPurgersInOrder() as $purger) {
+        $expired += $purger->countExpired($threshold);
+    }
+
+    expect($expired)->toBe(1);
+    expect(Subscription::query()->count())->toBe(1);
+});
+
+test('C1 の母集団は畳み込み待ちの台帳を含まない (C2 で自動的に加わる)', function (): void {
+    $targets = array_map(
+        static fn (BillingRetentionPurger $purger): string => $purger->target()->value,
+        billingRetentionPurgersInOrder(),
+    );
+
+    expect($targets)->not->toContain(BillingRetentionTarget::TicketLedgerEntry->value);
+    expect(array_map(
+        static fn (BillingRetentionTarget $case): string => $case->value,
+        billingRetentionHorizonTargets(),
+    ))->toBe($targets);
+});
diff --git a/tests/Feature/Billing/BillingRetentionPurgeTest.php b/tests/Feature/Billing/BillingRetentionPurgeTest.php
new file mode 100644
index 0000000..26f2b4f
--- /dev/null
+++ b/tests/Feature/Billing/BillingRetentionPurgeTest.php
@@ -0,0 +1,263 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\BillingRetentionTarget;
+use App\Models\Billing\StripeWebhookEvent;
+use App\Models\Billing\Subscription;
+use App\Models\Billing\TicketCheckoutSession;
+use App\Services\Billing\Contracts\BillingRetentionPurger;
+use App\Services\Billing\Retention\BillingCheckoutSessionPurger;
+use App\Services\Billing\Retention\BillingRetentionPurgerRegistry;
+use App\Services\Billing\Retention\StripeWebhookEventPurger;
+use App\Services\Billing\Retention\SubscriptionItemPurger;
+use App\Services\Billing\Retention\SubscriptionPurger;
+use App\Services\Billing\Retention\TicketAutoRechargeAttemptPurger;
+use App\Services\Billing\Retention\TicketCheckoutSessionPurger;
+use App\Support\Legal\BillingRetention;
+use Carbon\CarbonImmutable;
+use Illuminate\Support\Facades\Artisan;
+use Laravel\Cashier\SubscriptionItem;
+use Tests\Support\Billing\BillingRetentionFixtures;
+
+/*
+ * 保持期間 (7 年) purge の挙動 (PR-C1)。
+ *
+ * ★C1 は **dry-run 専用** (コマンドに --apply は無い)。purger 本体の削除挙動はここで
+ *   直接呼んで固定し、コマンド経由では「1 行も消えない」ことを固定する。
+ *
+ * ★境界の定義: 起算日時が閾値**ちょうど以前**なら期限超過 (`<=`)。
+ */
+
+/** 自テーブルに起算列を持つ 4 target (purger クラス => target)。 */
+dataset('自テーブル起算の target', [
+    'stripe_webhook_event' => [StripeWebhookEventPurger::class, BillingRetentionTarget::StripeWebhookEvent],
+    'billing_checkout_session' => [BillingCheckoutSessionPurger::class, BillingRetentionTarget::BillingCheckoutSession],
+    'ticket_checkout_session' => [TicketCheckoutSessionPurger::class, BillingRetentionTarget::TicketCheckoutSession],
+    'ticket_auto_recharge_attempt' => [TicketAutoRechargeAttemptPurger::class, BillingRetentionTarget::TicketAutoRechargeAttempt],
+]);
+
+test('境界: 起算日時が閾値の 1 秒前なら消え、1 秒後なら残る', function (string $purgerClass, BillingRetentionTarget $target): void {
+    $threshold = BillingRetention::threshold();
+    BillingRetentionFixtures::createStarted($target, $threshold->subSecond());
+    BillingRetentionFixtures::createStarted($target, $threshold->addSecond());
+
+    /** @var BillingRetentionPurger $purger */
+    $purger = app($purgerClass);
+    expect($purger->countExpired($threshold))->toBe(1);
+
+    $result = $purger->purgeExpired($threshold);
+
+    expect($result->target)->toBe($target);
+    expect($result->candidates)->toBe(1);
+    expect($result->processed)->toBe(1);
+    expect($result->failClosed)->toBe(0);
+    expect($result->unexpectedFailures)->toBe(0);
+    expect($result->expiredRemaining)->toBe(0);
+    expect($target->modelClass()::query()->count())->toBe(1); // 新しい方は残っている
+})->with('自テーブル起算の target');
+
+test('境界: 起算日時が閾値ちょうどなら期限超過 (<= で判定する)', function (string $purgerClass, BillingRetentionTarget $target): void {
+    $threshold = BillingRetention::threshold();
+    BillingRetentionFixtures::createStarted($target, $threshold);
+
+    /** @var BillingRetentionPurger $purger */
+    $purger = app($purgerClass);
+
+    expect($purger->countExpired($threshold))->toBe(1);
+})->with('自テーブル起算の target');
+
+test('起算列が null で補助時計が閾値より古い行は fail-closed (消さずに計上する)', function (string $purgerClass, BillingRetentionTarget $target): void {
+    $threshold = BillingRetention::threshold();
+    BillingRetentionFixtures::createUnstarted($target, $threshold->subSecond());
+
+    /** @var BillingRetentionPurger $purger */
+    $purger = app($purgerClass);
+
+    expect($purger->countExpired($threshold))->toBe(0);
+    expect($purger->countFailClosed($threshold))->toBe(1);
+
+    $result = $purger->purgeExpired($threshold);
+
+    expect($result->processed)->toBe(0);
+    expect($result->failClosed)->toBe(1);
+    expect($result->hasFailClosedRecords())->toBeTrue();
+    expect($target->modelClass()::query()->count())->toBe(1); // 消していない
+})->with('自テーブル起算の target');
+
+test('境界: 起算列 null かつ補助時計が閾値の 1 秒後なら fail-closed に計上しない', function (string $purgerClass, BillingRetentionTarget $target): void {
+    $threshold = BillingRetention::threshold();
+    BillingRetentionFixtures::createUnstarted($target, $threshold->addSecond());
+
+    /** @var BillingRetentionPurger $purger */
+    $purger = app($purgerClass);
+
+    expect($purger->countExpired($threshold))->toBe(0);
+    expect($purger->countFailClosed($threshold))->toBe(0); // 正常な未確定 (まだ新しい)
+})->with('自テーブル起算の target');
+
+test('継続中の契約 (ends_at が null) は何年前に作られていても対象外かつ異常でもない', function (): void {
+    $threshold = BillingRetention::threshold();
+    [$organization] = createOrganizationWithOwner();
+    $subscription = createFakeSubscription($organization);
+    $subscription->forceFill([
+        'ends_at' => null,
+        'created_at' => $threshold->subYearsNoOverflow(3),
+    ])->save();
+
+    $purger = app(SubscriptionPurger::class);
+
+    expect($purger->countExpired($threshold))->toBe(0);
+    expect($purger->countFailClosed($threshold))->toBe(0);
+
+    $result = $purger->purgeExpired($threshold);
+
+    expect($result->processed)->toBe(0);
+    expect(Subscription::query()->count())->toBe(1);
+});
+
+test('終了済み契約は ends_at で判定され、明細が無ければ消える', function (): void {
+    $threshold = BillingRetention::threshold();
+    BillingRetentionFixtures::endedSubscription($threshold->subSecond());
+    BillingRetentionFixtures::endedSubscription($threshold->addSecond());
+
+    $result = app(SubscriptionPurger::class)->purgeExpired($threshold);
+
+    expect($result->candidates)->toBe(1);
+    expect($result->processed)->toBe(1);
+    expect($result->failClosed)->toBe(0);
+    expect($result->expiredRemaining)->toBe(0);
+    expect(Subscription::query()->count())->toBe(1);
+});
+
+test('明細が残っている期限超過の契約は fail-closed で残り、件数が報告される', function (): void {
+    $threshold = BillingRetention::threshold();
+    $subscription = BillingRetentionFixtures::endedSubscription($threshold->subSecond());
+    BillingRetentionFixtures::attachItem($subscription);
+
+    $purger = app(SubscriptionPurger::class);
+
+    expect($purger->countFailClosed($threshold))->toBe(1);
+
+    $result = $purger->purgeExpired($threshold);
+
+    expect($result->candidates)->toBe(1);
+    expect($result->processed)->toBe(0);
+    expect($result->failClosed)->toBe(1);
+    expect($result->expiredRemaining)->toBe(1);
+    expect($result->isPublicationReady())->toBeFalse();
+    expect(Subscription::query()->count())->toBe(1);
+    expect(SubscriptionItem::query()->count())->toBe(1); // cascade で道連れにしない
+});
+
+test('明細は親の ends_at で判定され、子 → 親の順に消える', function (): void {
+    $threshold = BillingRetention::threshold();
+    $expired = BillingRetentionFixtures::endedSubscription($threshold->subSecond());
+    BillingRetentionFixtures::attachItem($expired);
+
+    $live = BillingRetentionFixtures::endedSubscription($threshold->addSecond());
+    BillingRetentionFixtures::attachItem($live);
+
+    $itemPurger = app(SubscriptionItemPurger::class);
+    expect($itemPurger->countExpired($threshold))->toBe(1);
+    expect($itemPurger->countFailClosed($threshold))->toBe(0); // 継続中の親は異常ではない
+
+    // registry の順 (子 → 親) で回す
+    $results = [];
+    foreach (app(BillingRetentionPurgerRegistry::class)->purgers() as $purger) {
+        $results[$purger->target()->value] = $purger->purgeExpired($threshold);
+    }
+
+    expect($results[BillingRetentionTarget::SubscriptionItem->value]->processed)->toBe(1);
+    expect($results[BillingRetentionTarget::Subscription->value]->processed)->toBe(1);
+    expect(SubscriptionItem::query()->count())->toBe(1);
+    expect(Subscription::query()->count())->toBe(1);
+});
+
+test('dry-run コマンドは 1 行も消さず target 別の件数を報告する', function (): void {
+    $threshold = BillingRetention::threshold();
+    BillingRetentionFixtures::createStarted(BillingRetentionTarget::StripeWebhookEvent, $threshold->subSecond());
+    BillingRetentionFixtures::createUnstarted(BillingRetentionTarget::TicketCheckoutSession, $threshold->subSecond());
+    $subscription = BillingRetentionFixtures::endedSubscription($threshold->subSecond());
+    BillingRetentionFixtures::attachItem($subscription);
+
+    $this->artisan('billing:purge-retention-expired')
+        ->expectsOutputToContain('[dry-run]')
+        ->expectsOutputToContain('stripe_webhook_event: expired=1 fail_closed=0')
+        ->expectsOutputToContain('ticket_checkout_session: expired=0 fail_closed=1')
+        ->expectsOutputToContain('subscription: expired=1 fail_closed=1')
+        ->assertExitCode(0);
+
+    expect(StripeWebhookEvent::query()->count())->toBe(1);
+    expect(TicketCheckoutSession::query()->count())->toBe(1);
+    expect(Subscription::query()->count())->toBe(1);
+    expect(SubscriptionItem::query()->count())->toBe(1);
+});
+
+test('コマンドは purger 未実装の target (台帳の畳み込み) を出力で明示する', function (): void {
+    $this->artisan('billing:purge-retention-expired')
+        ->expectsOutputToContain('ticket_ledger_entry: purger 未実装')
+        ->assertExitCode(0);
+});
+
+test('コマンドの出力に PII (組織名 / メール / Stripe 識別子) が現れない', function (): void {
+    $threshold = BillingRetention::threshold();
+    [$organization, $owner] = createOrganizationWithOwner('秘密の組織名');
+    $session = TicketCheckoutSession::factory()
+        ->forOrganization($organization)
+        ->initiatedBy($owner)
+        ->completed()
+        ->create(['completed_at' => $threshold->subSecond()]);
+
+    $exitCode = Artisan::call('billing:purge-retention-expired');
+    $output = Artisan::output();
+
+    expect($exitCode)->toBe(0);
+
+    expect($output)->not->toContain('秘密の組織名');
+    expect($output)->not->toContain($owner->email);
+    expect($output)->not->toContain($session->stripe_session_id);
+    expect($output)->toContain('ticket_checkout_session: expired=1');
+});
+
+test('コマンドは未知の --target を拒否する', function (): void {
+    $this->artisan('billing:purge-retention-expired', ['--target' => 'unknown_table'])
+        ->expectsOutputToContain('未知の target です: unknown_table')
+        ->assertExitCode(1);
+});
+
+test('コマンドは --target で 1 つに絞れる', function (): void {
+    $threshold = BillingRetention::threshold();
+    BillingRetentionFixtures::createStarted(BillingRetentionTarget::StripeWebhookEvent, $threshold->subSecond());
+    BillingRetentionFixtures::createStarted(BillingRetentionTarget::BillingCheckoutSession, $threshold->subSecond());
+
+    $this->artisan('billing:purge-retention-expired', ['--target' => 'stripe_webhook_event'])
+        ->expectsOutputToContain('stripe_webhook_event: expired=1')
+        ->doesntExpectOutputToContain('billing_checkout_session:')
+        ->assertExitCode(0);
+});
+
+test('コマンドは --apply オプションを持たない (規約が宣言していない削除を先に効かせない)', function (): void {
+    $definition = Artisan::all()['billing:purge-retention-expired']->getDefinition();
+
+    expect($definition->hasOption('apply'))->toBeFalse();
+});
+
+test('保持年数が 0 以下なら fail-fast する', function (): void {
+    config()->set('legal.billing_retention_years', 0);
+
+    expect(fn (): int => BillingRetention::years())->toThrow(InvalidArgumentException::class);
+});
+
+test('保持年数が整数でなければ fail-fast する', function (): void {
+    config()->set('legal.billing_retention_years', '7');
+
+    expect(fn (): int => BillingRetention::years())->toThrow(InvalidArgumentException::class);
+});
+
+test('閾値は保持年数ぶん過去であり、年の加減算は overflow しない', function (): void {
+    $now = CarbonImmutable::parse('2032-02-29 12:00:00'); // 閏日
+
+    expect(BillingRetention::threshold($now)->toDateTimeString())
+        ->toBe('2025-02-28 12:00:00'); // 2025-03-01 へ溢れない
+});
diff --git a/tests/Support/Billing/BillingRetentionFixtures.php b/tests/Support/Billing/BillingRetentionFixtures.php
new file mode 100644
index 0000000..33016fe
--- /dev/null
+++ b/tests/Support/Billing/BillingRetentionFixtures.php
@@ -0,0 +1,100 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Billing;
+
+use App\Enums\Billing\BillingRetentionTarget;
+use App\Models\Billing\BillingCheckoutSession;
+use App\Models\Billing\StripeWebhookEvent;
+use App\Models\Billing\Subscription;
+use App\Models\Billing\TicketAutoRechargeAttempt;
+use App\Models\Billing\TicketCheckoutSession;
+use Carbon\CarbonImmutable;
+use Illuminate\Support\Str;
+use InvalidArgumentException;
+use Laravel\Cashier\SubscriptionItem;
+
+/**
+ * 保持期間 (7 年) purge のテスト fixture。
+ *
+ * 目録 (`BillingRetentionTarget`) と 1:1 で対応する生成器を 1 箇所に置く。
+ * 挙動テスト (`BillingRetentionPurgeTest`) と horizon テスト
+ * (`BillingRetentionHorizonTest`) の両方から使うため、テストファイル内の
+ * グローバル関数ではなくクラスに置いている (どちらか一方だけ実行しても壊れない)。
+ *
+ * 契約と Subscription 行の生成は tests/Pest.php の共有ヘルパへ委譲する
+ * (Cashier の契約行の作り方を 2 箇所に増やさない)。
+ */
+final class BillingRetentionFixtures
+{
+    /** 起算済み (起算列が非 null = 取引が終了している) の行を作る。 */
+    public static function createStarted(BillingRetentionTarget $target, CarbonImmutable $clock): void
+    {
+        match ($target) {
+            BillingRetentionTarget::StripeWebhookEvent => StripeWebhookEvent::factory()
+                ->processed($clock)->create(),
+            BillingRetentionTarget::BillingCheckoutSession => BillingCheckoutSession::factory()
+                ->completed()->create(['completed_at' => $clock]),
+            BillingRetentionTarget::TicketCheckoutSession => TicketCheckoutSession::factory()
+                ->completed()->create(['completed_at' => $clock]),
+            BillingRetentionTarget::TicketAutoRechargeAttempt => TicketAutoRechargeAttempt::factory()
+                ->paid()->create(['resolved_at' => $clock]),
+            default => throw new InvalidArgumentException('自テーブル起算の target ではありません: '.$target->value),
+        };
+    }
+
+    /** 起算されていない (起算列 null) 行を、補助時計 (created_at) を指定して作る。 */
+    public static function createUnstarted(BillingRetentionTarget $target, CarbonImmutable $anomalyClock): void
+    {
+        match ($target) {
+            BillingRetentionTarget::StripeWebhookEvent => StripeWebhookEvent::factory()
+                ->failed()->create(['created_at' => $anomalyClock]),
+            BillingRetentionTarget::BillingCheckoutSession => BillingCheckoutSession::factory()
+                ->create(['completed_at' => null, 'created_at' => $anomalyClock]),
+            BillingRetentionTarget::TicketCheckoutSession => TicketCheckoutSession::factory()
+                ->create(['completed_at' => null, 'created_at' => $anomalyClock]),
+            BillingRetentionTarget::TicketAutoRechargeAttempt => TicketAutoRechargeAttempt::factory()
+                ->create(['resolved_at' => null, 'created_at' => $anomalyClock]),
+            default => throw new InvalidArgumentException('補助時計を持つ target ではありません: '.$target->value),
+        };
+    }
+
+    /** 終了済み (ends_at 指定) の契約を 1 件作る。 */
+    public static function endedSubscription(CarbonImmutable $endsAt): Subscription
+    {
+        [$organization] = \createOrganizationWithOwner();
+        $subscription = \createFakeSubscription($organization, status: 'canceled');
+        $subscription->forceFill(['ends_at' => $endsAt])->save();
+
+        /** @var Subscription $fresh */
+        $fresh = $subscription->fresh();
+
+        return $fresh;
+    }
+
+    /** 契約に明細を 1 件ぶら下げる。 */
+    public static function attachItem(Subscription $subscription): SubscriptionItem
+    {
+        /** @var SubscriptionItem $item */
+        $item = $subscription->items()->create([
+            'stripe_id' => 'si_test_'.Str::random(20),
+            'stripe_product' => 'prod_test',
+            'stripe_price' => 'price_test',
+            'quantity' => 1,
+        ]);
+
+        return $item;
+    }
+
+    /** 全 target ぶんの「期限超過だが消せる」記録を作る (horizon 検査の母集団)。 */
+    public static function seedExpiredRows(CarbonImmutable $threshold): void
+    {
+        self::createStarted(BillingRetentionTarget::StripeWebhookEvent, $threshold->subDay());
+        self::createStarted(BillingRetentionTarget::BillingCheckoutSession, $threshold->subDay());
+        self::createStarted(BillingRetentionTarget::TicketCheckoutSession, $threshold->subDay());
+        self::createStarted(BillingRetentionTarget::TicketAutoRechargeAttempt, $threshold->subDay());
+
+        self::attachItem(self::endedSubscription($threshold->subDay()));
+    }
+}
diff --git a/tests/Unit/DataTransferObjects/Billing/BillingRetentionPurgeResultDtoTest.php b/tests/Unit/DataTransferObjects/Billing/BillingRetentionPurgeResultDtoTest.php
new file mode 100644
index 0000000..058025b
--- /dev/null
+++ b/tests/Unit/DataTransferObjects/Billing/BillingRetentionPurgeResultDtoTest.php
@@ -0,0 +1,68 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Billing\BillingRetentionPurgeResultDto;
+use App\Enums\Billing\BillingRetentionTarget;
+
+/*
+ * 保持期間 purge の結果 DTO の判定規則。
+ *
+ * ★`isPublicationReady()` は**規約文面を公開してよいか**の判定であり、
+ *   「安全のため残した (failClosed)」を免除しない。免除すると、規約が宣言した年数を
+ *   超えた記録が残ったまま「準拠した」と言えてしまう。
+ */
+
+/** 指定の件数だけを差し替えた結果を作る。 */
+function billingRetentionResult(
+    int $failClosed = 0,
+    int $unexpectedFailures = 0,
+    int $expiredRemaining = 0,
+): BillingRetentionPurgeResultDto {
+    return new BillingRetentionPurgeResultDto(
+        target: BillingRetentionTarget::Subscription,
+        candidates: 10,
+        processed: 10,
+        failClosed: $failClosed,
+        unexpectedFailures: $unexpectedFailures,
+        expiredRemaining: $expiredRemaining,
+    );
+}
+
+test('すべて 0 なら公開してよい', function (): void {
+    expect(billingRetentionResult()->isPublicationReady())->toBeTrue();
+});
+
+test('fail-closed が残っていれば公開できない (安全に残した = 規約準拠ではない)', function (): void {
+    $result = billingRetentionResult(failClosed: 1);
+
+    expect($result->hasFailClosedRecords())->toBeTrue();
+    expect($result->isPublicationReady())->toBeFalse();
+});
+
+test('想定外失敗があれば公開できない', function (): void {
+    $result = billingRetentionResult(unexpectedFailures: 1);
+
+    expect($result->hasUnexpectedFailures())->toBeTrue();
+    expect($result->isPublicationReady())->toBeFalse();
+});
+
+test('期限超過が残っていれば公開できない', function (): void {
+    expect(billingRetentionResult(expiredRemaining: 1)->isPublicationReady())->toBeFalse();
+});
+
+test('dry-run の結果は「何も処理せず候補がそのまま残っている」形になる', function (): void {
+    $result = BillingRetentionPurgeResultDto::dryRun(
+        target: BillingRetentionTarget::StripeWebhookEvent,
+        candidates: 3,
+        failClosed: 2,
+    );
+
+    expect($result->target)->toBe(BillingRetentionTarget::StripeWebhookEvent);
+    expect($result->candidates)->toBe(3);
+    expect($result->processed)->toBe(0);
+    expect($result->failClosed)->toBe(2);
+    expect($result->unexpectedFailures)->toBe(0);
+    expect($result->expiredRemaining)->toBe(3); // 消していないのだから残っている
+    expect($result->isPublicationReady())->toBeFalse();
+});

```

## mutation 実測 (壊すと赤くなることの確認)

# T143 (PR-C1) mutation evidence

詳細設計 §「共通: mutation で赤化を確認する手順」のうち **C1 に関係する 5 件** (M10 / M11 / M12 / M16 / M26) を実施した。
1 変異ずつ適用 → 対象テストが赤いことを実測 → 変異を戻す → 全体が緑に戻ることを確認 (`git diff` に残っていないことも確認済み)。

> **設計の予測と実測がずれた点は辻褄を合わせずそのまま記録する** (M10 / M12)。

## M10: 目録から `Subscription` を外す (母集団の分類漏れ)

**設計の変異**: `BillingRetentionTarget` から `Subscription` case を削る。

**実施した変異 (代替形)**: `app/Models/Billing/MutationProbe.php` (未分類の課金モデル) を新設する。

**代替した理由**: enum の case を削ると `SubscriptionPurger::target()` / 各 `match` 腕が
未定義定数を参照して **PHP の fatal error** になる。fatal は「gate が検出した」ことの証拠にならない
(gate を消しても同じ赤になる)。検出したい性質は「**母集団に分類漏れがあると赤くなる**」であり、
未分類モデルの追加はその性質を直接突く同値の変異である。

**実測 (赤)**: `tests/Architecture/BillingRetentionTargetInventoryTest.php` が 3 本失敗。

- 検査 1 (分類漏れ): `保持期間の分類が無い課金モデルを検出しました … App\Models\Billing\MutationProbe`
- 検査 5 (空振り検知): `Failed asserting that actual size 15 matches expected size 14.`
- 負のコントロール: 未分類一覧に probe が混ざり期待値と不一致

## M11: `Subscription` の起算列を `ends_at` → `created_at` に変える

**実測 (赤)**: Feature 6 本失敗。設計の予測 (「継続中は何年経っても対象外」テスト) を含む。

- `継続中の契約 (ends_at が null) は何年前に作られていても対象外かつ異常でもない` ← 設計の予測どおり
- `終了済み契約は ends_at で判定され、明細が無ければ消える`
- `明細が残っている期限超過の契約は fail-closed で残り、件数が報告される`
- `明細は親の ends_at で判定され、子 → 親の順に消える`
- `dry-run コマンドは 1 行も消さず target 別の件数を報告する`
- `負のコントロール: purge 後に古い記録を作ると horizon が満たされなくなる`

## M12: `TicketLedgerEntry` を C1 の horizon 対象に入れる

**実施した変異**: `BillingRetentionTarget::isPendingCarryForward()` を `return false;` にする。

**実測 (赤)**: 2 本失敗。

- `tests/Architecture/BillingRetentionTargetInventoryTest.php` 検査 4:
  `purger を持つべき target と実装が一致しません (isPendingCarryForward() の target を除く)。`
- `tests/Feature/Billing/BillingRetentionHorizonTest.php`
  `C1 の母集団は畳み込み待ちの台帳を含まない (C2 で自動的に加わる)`

**設計の予測とのズレ (記録)**: 設計は「horizon (期限超過が残る)」が赤くなると予測していたが、
実装では **horizon の母集団を registry (実装済み purger) から導出**しているため、
`postcondition` テストは赤くならなかった。代わりに「purger 実装 ⇔ 目録の exact-fit」検査が赤くなる。
帰結は同じ (畳み込み未実装のまま台帳を対象扱いにすると必ず赤くなる) が、**赤くなる場所が違う**。
母集団を registry から導出した理由は、C2 で `isPendingCarryForward()` が false になったときに
**テスト側の除外を書き足す必要がない** (外し忘れが構造的に起きない) ためである。

## M16: `isPublicationReady()` から `failClosed === 0` を外す

**実測 (赤)**: `tests/Unit/DataTransferObjects/Billing/BillingRetentionPurgeResultDtoTest.php`
`fail-closed が残っていれば公開できない (安全に残した = 規約準拠ではない)` が失敗。

**補足 (記録)**: Feature 側の `明細が残っている期限超過の契約は fail-closed で残り…` は
**赤くならなかった** — この fixture では `expiredRemaining` も 1 になるため、
`failClosed` の条件を外しても `isPublicationReady()` は false のままだからである。
公開条件のうち `failClosed` 単独の寄与を固定しているのは DTO の単体テストだけであり、
その 1 本が変異の唯一の検出点である (だから消してはならない)。

## M26: `StripeWebhookEvent` の `anomalyClockColumn()` を null にする

**実測 (赤)**: `tests/Feature/Billing/BillingRetentionPurgeTest.php`
`起算列が null で補助時計が閾値より古い行は fail-closed (消さずに計上する)`
の dataset `stripe_webhook_event` が失敗 (他の 3 target は緑のまま = 変異した target だけが落ちる)。

## 変異の撤収確認

- `app/Models/Billing/MutationProbe.php` を削除済み (`ls app/Models/Billing/` に存在しない)
- `git diff` に変異の残骸なし (`BillingRetentionTarget.php` の差分は PHPStan 対応の docblock のみ、
  `BillingRetentionPurgeResultDto.php` は差分なし)
- 撤収後に対象テスト群が全 green に戻ることを確認


## テスト結果

- `composer test`: 4167 tests / 4165 passed / 0 failed / 2 skipped (既存 skip)
- `composer phpstan` (level 10): No errors
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm test` (1292 passed) / `pnpm build`: すべて green
- `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` (106 passed): すべて green
- 本 PR で新設したテスト: Architecture 2 ファイル (13 tests) / Feature 2 ファイル (33 tests) / Unit 1 ファイル (5 tests)
