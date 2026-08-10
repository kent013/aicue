# アプリの使命 (North Star)

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

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

---

あなたは Laravel 12 + Svelte 5 + Inertia のコードレビュアーである。以下の実装差分を **PR-C2 の詳細設計に対して** レビューせよ。

## 前提 (この PR のスコープ)
- 本 PR は 5 分割された設計の **PR-C2 (保持期間の実処理の有効化) のみ**を実装している。
  PR-A / PR-B / PR-C1 は既に main にマージ済み、**PR-C3 (規約文面の公開) は本 PR の範囲外**である。
  `resources/views/legal/privacy.blade.php` を触っていないのは意図的であり、指摘対象ではない。
- リポジトリのルートは `/workspace/.claude/worktrees/tasks/T144` である (ファイル読み込みは許可)。

## レビュー観点
1. **設計との一致性** — PR-C2 (C2a/C2b/C2c/C2d) の要求を満たしているか。逸脱があるなら根拠が記録されているか
2. **正確性 (最重要)** — 畳み込みで **チケット残高が 1 枚でも増減しないか**。group key・境界条件・トランザクション境界・
   並行実行 (reserve / commit / grant との競合)・append-only 不変条件の扱い
3. **PHPStan level 10 適合性** (`@phpstan-ignore` / baseline / 型の widen は禁止)
4. **テスト網羅性** — 「壊すと赤くなる」ことが実測されているか。空振り (degenerate PASS) が無いか
5. **セキュリティ** — 出力への PII 混入、cross-org 越境、ログへの可変文字列混入
6. **DTO / JsonResource パターン**、**アーリーリターン**、Controller/Command の薄さ
7. **DESIGN.md 準拠 / Atomic Design 準拠** — 本 PR に `resources/js` / `resources/css` の差分は無いため該当なし
8. **誇張していないか** — docblock / runbook の「保証するもの / 保証しないもの」が実装と一致しているか

## 出力形式
- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** で分類する
- 最後に **全体判定: APPROVED または CHANGES_REQUESTED** を明記する
- 「実装していないこと」を指摘するときは、それが PR-C2 のスコープ内かを必ず確認すること

---

## 詳細設計 (使命・制約 + PR-C1/PR-C2 の全文)

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star) — AGENTS.md より転記

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、
そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  (撮影者・教える人のスキルに品質を依存させない)。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) /
> 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項 — AGENTS.md より転記

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`) / **Pest** (`composer test`)
- **RefreshDatabase** は `tests/Pest.php` でグローバル適用 (`--parallel` 実行)。
  個別 `DatabaseTransactions` 禁止
- **テストデータは必ず Factory で生成** (`Model::create()` 手組み禁止)
- **DTO + JsonResource** パターン / **アーリーリターン** 推奨
- `declare(strict_types=1)` + 日本語コメント。Controller は薄く (Service 委譲)、transaction は Service 内
- 月/年の加減算は `*NoOverflow` 明示 (`CarbonOverflowArithmeticGateTest` が検出)
- `composer fix` (Pint) / `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

### オーナー決定 (逸脱不可)

| 項目 | 値 |
|---|---|
| 猶予期間 | **30 日** |
| 課金取引記録の保持 | **7 年** |
| 猶予中の扱い | **凍結方式** (users 行の生死を変えない = SoftDeletes を使わない) |
| 規約文面 | **spirux の /privacy「取引関係書類等につき最長 7 年」に揃える。独自の法的主張を書かない** |
| `config/legal.php` の `consent_version` | **`draft-1` から動かさない** |
| 追記文面の位置づけ | **法務レビュー前の草案**。設計・実装・runbook・PR 説明の 4 箇所に明記する |

---

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

# PR-C2: 保持期間の実処理の有効化

## C2a. ledger reader 目録

`TicketLedgerReaderInventoryTest` (Architecture)。走査入口は **4 つ**:
モデル参照 (`TicketLedgerEntry`) / table 名 (`'ticket_ledger_entries'`) / relation 名 /
主要列名 (`delta` / `source` / `expires_at`)。正負 fixture と空振り検知を同梱する。

**保証範囲 (誇張しない)**: 目録が保証するのは「読んでいる場所を宣言なしに増やせない」ことだけで、
動的 relation・変数 table 名・DB facade の動的組み立ては取りこぼしうる。
**最終保証は C2b の挙動テスト側**である。

## C2b. ledger 畳み込み

### 設計

保持期限より古い行を **`(organization_id, source, expires_at)` の組ごと**に合算し、
合計 `delta` を持つ 1 行の**繰越行**へ置換する。

> **`organization_id` を group key に必ず含める**。含め忘れると**組織を跨いで残高を合算する**
> 重大バグになる (Codex 詳細レビュー Round 1 の [Critical])。
> 実コードで確認した残高の粒度はこの 3 つで閉じる — `sumBalance()` は
> `where organization_id` + `source` (Purchased は `source IS NULL` も含む) +
> `expires_at IS NULL or > now` で合算しており、team / project 粒度は持たない。
> **`source IS NULL` (legacy 行) は独立した group として扱う** (Purchased へ寄せると
> `sumActiveHolds` の legacy 除外規則と意味がズレる)。

- **繰越行は「取引記録」ではなく「現在残高のスナップショット」と定義する**。
  新しい `created_at` を持つ取引記録のままだと、7 年後にまた畳み込まれて保持時計が
  永久に更新され続け、規約との対応が崩れる。性質を変えることで境界を明示する。
- **取引追跡情報を引き継がない**: 原取引 ID / 説明 / 個別日時 / `stripe_invoice_id` は残さない。
- **引き継ぐのは残高の粒度を決める 3 つだけ**: `organization_id` と `source` と `expires_at`。
- **`kind` は新 case `TicketLedgerKind::CarryForward`** にする (既存 kind へ相乗りしない。
  思考原則 4「別物の概念を似ているからで統合しない」)。
- **取引追跡列はすべて null**: `description` / `reservation_id` / `stripe_checkout_session_id` /
  `payment_intent_id` / `purchase_amount` / `granted_at` (実カラムを migration 実読で確認済み)。
- **`idempotency_key` の形は固定する**:
  `carry_forward:{orgId}:{source ?? 'null'}:{expiresAt(UTC) ?? 'null'}:{through(UTC)}`。
  **null は明示トークン `'null'`** で表し (空文字との衝突を避ける)、**日時は UTC 正規化**
  (`Y-m-d\TH:i:s\Z`)。「同一 group の再実行で同じキーになる」ことをテストで固定する。
  既存の signup unique index は **`idempotency_key LIKE 'signup_grant:%'` の部分 unique** なので
  衝突しない (`2026_07_13_180622_add_signup_grant_unique_index_to_ticket_ledger_entries.php` を実読)。
- **`carried_forward_through`** (この繰越が集約した期間の終端) を型付きで持つ (新列)。
- **繰越行の再畳み込みを許す** (スナップショット同士の合算は情報を増やさない)。
- **合計 0 の繰越行を作らない** (残高に寄与しない行を増やさない)。
- **再畳み込み後も `carried_forward_through` が単調に進む**。
- 組織の行ロック下で行い、`reserve` / `commit` と同じロック順序に乗せる。

### 波及変更 (C2b)
- TypeScript 型定義: **`TicketLedgerKind` に case を足すため TS 側の対応型と表示分岐を確認する**
  (`resources/js/types/` の該当型 + 台帳表示 UI。enum TS 同期テストがあれば同時更新)
- API Resource/DTO: `TicketLedgerEntry` を載せる Resource/DTO があれば新 kind の表示を確認
- テストファイル: `TicketLedgerReaderInventoryTest` (C2a) / 既存 `TicketLedgerService` テスト群

### 検証 (7 種を畳み込み前後で比較)
1. 総残高 2. 利用可能残高 (`availableTrueBalance`) 3. 有効期限別残高
4. `source` 別残高 5. `debit`/`reserve`/`commit`/`release` の選択順序
6. 外部キー・重複防止キー (`signup_grant` unique 等)・監査表示
7. **組織ごとの残高が畳み込み前後で一致する (複数組織 fixture)**

加えて **個別取引情報が復元不能であることをテストで固定する**
(畳み込み後に原取引の識別子が 1 つも残っていないこと)。

## C2c. 日次登録 + `--apply`

```php
Schedule::command('billing:purge-retention-expired --apply')->daily()->onOneServer();
```

**schedule は C2 のデプロイ時点から有効**である。runbook の初回 `--apply` は
「初回を能動的に完走させて結果を確認する」ためのもので、schedule を抑止する意味ではない
(抑止機構を新設しない = 思考原則 2)。

## C2d. 有効化 runbook (`docs/billing-retention-runbook.md`)

**公開順序 (C2 の完了条件)**:
1. C1 の dry-run で target 別件数と想定外失敗を確認する
2. C2 (ledger 畳み込み込み) をデプロイする
3. `--apply` を実行する
4. apply 後の horizon 検査で **「期限超過件数 0 (`failClosed` を含む。分類を問わない)」** を確認する
5. **4 が満たされて初めて C3 (文面公開) を出す**
6. 日次 scheduler を**継続監視へ移す**

- **C3 チェックリスト**: 初回 apply の出力 (target 別件数 / `failClosed` = 0 / 想定外失敗 = 0) の
  **証跡を C3 の PR 説明へ貼る**ことを必須項目にする。
- `failClosed` が長期継続したときの解消手順 (参照元の特定 → 参照の解消 → 再実行、
  件数が単調増加しているときの初動)。
- **`failClosed` は「安全に残した」であって「規約を満たした」ではない**ことを明記する。
- **監視対象**: `docs/architecture.md` の監視対象リストへ本コマンドを追加する
  (`failClosed` の継続・増加を正常成功として扱わない)。


## 実装差分 (git diff)

```diff
diff --git a/app/Console/Commands/Billing/PurgeBillingRetentionCommand.php b/app/Console/Commands/Billing/PurgeBillingRetentionCommand.php
index c1005d0..2658c70 100644
--- a/app/Console/Commands/Billing/PurgeBillingRetentionCommand.php
+++ b/app/Console/Commands/Billing/PurgeBillingRetentionCommand.php
@@ -14,26 +14,29 @@
 use Throwable;
 
 /**
- * 保持期限を超えた課金記録の**集計 (dry-run 専用)**。
+ * 保持期限を超えた課金記録の決着 (**既定は dry-run。実処理は `--apply`**)。
  *
- * ★**`--apply` を持たない**。これは「規約が宣言していない削除を先に運用へ効かせない」の
- *   機械化である。/privacy には保持年数の宣言がまだ無く (PR-C3 の担当)、宣言より先に
- *   実削除を回すと、利用者が読める根拠のないままデータが消える。実処理の有効化は
- *   **PR-C2 で `--apply` を追加してから**行う (signature そのものが工程の順序を表している)。
+ * ★決着の方式は target で違う — 削除で決着する 6 target と、**畳み込み**で決着する
+ *   `ticket_ledger_entry` (台帳は残高の真実源なので消すと残高が変わる) がある。
+ *   どちらも「保持期限を超えた個別取引の情報が残らない」という同じ結果に着地する。
  *
  * ★出力は **target 別の件数のみ**。organization id / メールアドレス / 金額を出さない
  *   (運用ログとチケットに課金の個別情報を写さない)。
  *
- * ★`ticket_ledger_entry` は C1 時点で purger 未実装 (append-only の畳み込みは PR-C2)。
- *   「対象だが未了」であることを出力に必ず出す — 黙って集計から外すと、対象を網羅したように
- *   見える出力になる。
+ * ★**horizon (期限超過が残っているか) を出力で観測できる**こと。規約文面の公開 (PR-C3) の
+ *   前提条件は「分類を問わず期限超過 0 件」であり、その確認はこのコマンドの出力で行う。
+ *
+ * ★終了コードは 2 分類 — 想定外失敗があれば FAILURE。**`failClosed` が残っていても
+ *   SUCCESS である** (安全に残した = 異常ではない)。ただしそれは「規約を満たした」ではない
+ *   ので、件数を必ず出力する (docs/billing-retention-runbook.md)。
  */
 final class PurgeBillingRetentionCommand extends Command
 {
     protected $signature = 'billing:purge-retention-expired
-        {--target= : 対象を 1 つに絞る (BillingRetentionTarget の value)}';
+        {--target= : 対象を 1 つに絞る (BillingRetentionTarget の value)}
+        {--apply : 実際に決着させる (既定は dry-run で 1 行も変更しない)}';
 
-    protected $description = '保持期限を超えた課金記録を target 別に集計する (dry-run 専用。実削除はしない)';
+    protected $description = '保持期限を超えた課金記録を決着させる (既定は dry-run。--apply で実処理)';
 
     public function handle(BillingRetentionPurgerRegistry $registry): int
     {
@@ -48,10 +51,13 @@ public function handle(BillingRetentionPurgerRegistry $registry): int
             return self::FAILURE;
         }
 
+        $apply = $this->option('apply') === true;
+
         // 閾値は 1 回だけ解決して全 target へ渡す (実行中に日付が変わっても判定を揃える)。
         $threshold = BillingRetention::threshold();
         $this->info(sprintf(
-            '[dry-run] 保持期間 %d 年 / 閾値 %s 以前の起算日時が期限超過 (実削除はしない)',
+            '%s 保持期間 %d 年 / 閾値 %s 以前の起算日時が期限超過',
+            $apply ? '[apply]' : '[dry-run]',
             BillingRetention::years(),
             $threshold->toDateTimeString(),
         ));
@@ -61,22 +67,37 @@ public function handle(BillingRetentionPurgerRegistry $registry): int
             if ($filter !== null && $purger->target()->value !== $filter) {
                 continue;
             }
-            $results[] = $this->inspect($purger, $threshold);
+            $results[] = $apply
+                ? $this->settle($purger, $threshold)
+                : $this->inspect($purger, $threshold);
         }
 
         foreach ($results as $result) {
             $this->line(sprintf(
-                '  %s: expired=%d fail_closed=%d unexpected_failures=%d',
+                '  %s: expired=%d processed=%d fail_closed=%d unexpected_failures=%d remaining=%d',
                 $result->target->value,
                 $result->candidates,
+                $result->processed,
                 $result->failClosed,
                 $result->unexpectedFailures,
+                $result->expiredRemaining,
             ));
         }
 
-        $this->reportPendingTargets($filter);
+        return $this->report($results, $apply);
+    }
 
-        $expired = array_sum(array_map(
+    /**
+     * 合計の報告と終了コードの決定。
+     *
+     * `remaining` は **PR-C3 (規約文面の公開) の前提条件そのもの**なので、
+     * 0 かどうかを人が読める形で必ず出す。
+     *
+     * @param  list<BillingRetentionPurgeResultDto>  $results
+     */
+    private function report(array $results, bool $apply): int
+    {
+        $remaining = array_sum(array_map(
             static fn (BillingRetentionPurgeResultDto $result): int => $result->expiredRemaining,
             $results,
         ));
@@ -84,14 +105,33 @@ public function handle(BillingRetentionPurgerRegistry $registry): int
             static fn (BillingRetentionPurgeResultDto $result): int => $result->failClosed,
             $results,
         ));
-        $this->info("合計: 期限超過 {$expired} 件 / fail-closed {$failClosed} 件");
+        $processed = array_sum(array_map(
+            static fn (BillingRetentionPurgeResultDto $result): int => $result->processed,
+            $results,
+        ));
+
+        $this->info(sprintf(
+            '合計: 決着 %d 件 / 残存 (期限超過) %d 件 / fail-closed %d 件',
+            $processed,
+            $remaining,
+            $failClosed,
+        ));
+
+        // horizon の観測点。C3 の前提条件は「分類を問わず 0 件」である。
+        $this->line($remaining === 0
+            ? 'horizon: OK (期限超過 0 件)'
+            : "horizon: NG (期限超過 {$remaining} 件が残存。fail-closed も残存に数える)");
+
+        if (! $apply) {
+            $this->comment('dry-run のため 1 行も変更していません (--apply で実処理)。');
+        }
 
         $failed = array_filter(
             $results,
             static fn (BillingRetentionPurgeResultDto $result): bool => $result->hasUnexpectedFailures(),
         );
         if ($failed !== []) {
-            $this->error('集計に失敗した target があります (件数は不明として 0 で表示しています)。');
+            $this->error('想定外の失敗がある target があります (件数は不明として扱ってください)。');
 
             return self::FAILURE;
         }
@@ -99,7 +139,7 @@ public function handle(BillingRetentionPurgerRegistry $registry): int
         return self::SUCCESS;
     }
 
-    /** 1 target を集計する (実削除は行わない)。 */
+    /** 1 target を集計する (実処理は行わない)。 */
     private function inspect(BillingRetentionPurger $purger, CarbonImmutable $threshold): BillingRetentionPurgeResultDto
     {
         try {
@@ -109,33 +149,35 @@ private function inspect(BillingRetentionPurger $purger, CarbonImmutable $thresh
                 failClosed: $purger->countFailClosed($threshold),
             );
         } catch (Throwable $e) {
-            // 例外 message は載せない (外部生成の可変文字列)。target と例外クラスだけ。
-            $this->warn(sprintf('集計失敗 target=%s (%s)', $purger->target()->value, $e::class));
-
-            // 数えられなかったので件数は不明。0 と報告するが、unexpectedFailures が
-            // 「この 0 は信用できない」ことを示す (終了コードもここから決まる)。
-            return new BillingRetentionPurgeResultDto(
-                target: $purger->target(),
-                candidates: 0,
-                processed: 0,
-                failClosed: 0,
-                unexpectedFailures: 1,
-                expiredRemaining: 0,
-            );
+            return $this->unknown($purger, $e);
         }
     }
 
-    /** purger 未実装 (C2 待ち) の target を明示する。 */
-    private function reportPendingTargets(?string $filter): void
+    /** 1 target を実際に決着させる。 */
+    private function settle(BillingRetentionPurger $purger, CarbonImmutable $threshold): BillingRetentionPurgeResultDto
     {
-        foreach (BillingRetentionTarget::cases() as $case) {
-            if (! $case->isPendingCarryForward()) {
-                continue;
-            }
-            if ($filter !== null && $case->value !== $filter) {
-                continue;
-            }
-            $this->warn("  {$case->value}: purger 未実装 (append-only の畳み込みは PR-C2)");
+        try {
+            return $purger->purgeExpired($threshold);
+        } catch (Throwable $e) {
+            return $this->unknown($purger, $e);
         }
     }
+
+    /** 集計・決着に失敗した target の結果 (件数は不明として 0 で報告する)。 */
+    private function unknown(BillingRetentionPurger $purger, Throwable $e): BillingRetentionPurgeResultDto
+    {
+        // 例外 message は載せない (外部生成の可変文字列)。target と例外クラスだけ。
+        $this->warn(sprintf('失敗 target=%s (%s)', $purger->target()->value, $e::class));
+
+        // 数えられなかったので件数は不明。0 と報告するが、unexpectedFailures が
+        // 「この 0 は信用できない」ことを示す (終了コードもここから決まる)。
+        return new BillingRetentionPurgeResultDto(
+            target: $purger->target(),
+            candidates: 0,
+            processed: 0,
+            failClosed: 0,
+            unexpectedFailures: 1,
+            expiredRemaining: 0,
+        );
+    }
 }
diff --git a/app/Enums/Billing/BillingRetentionTarget.php b/app/Enums/Billing/BillingRetentionTarget.php
index 6c90363..d988ce5 100644
--- a/app/Enums/Billing/BillingRetentionTarget.php
+++ b/app/Enums/Billing/BillingRetentionTarget.php
@@ -75,7 +75,8 @@ public function clockStartColumn(): string
             // 子は親 (subscriptions) の契約終了日で判定する
             self::SubscriptionItem => 'subscriptions.ends_at',
             self::Subscription => 'ends_at',
-            // 台帳は取引成立の時点で起算済み (null にならない)
+            // 台帳は取引成立の時点で起算済み (null にならない)。
+            // 決着は物理削除ではなく畳み込み (App\Services\Billing\TicketLedgerCarryForwardService)
             self::TicketLedgerEntry => 'created_at',
         };
     }
@@ -116,16 +117,4 @@ public function rationale(): string
             self::TicketLedgerEntry => 'チケット残高の取引台帳。append-only のため物理削除ではなく繰越 (畳み込み) で決着させる',
         };
     }
-
-    /**
-     * C1 時点で purger 未実装 (C2 の畳み込みで解消する)。
-     *
-     * `ticket_ledger_entries` は append-only (残高の真実源) であり、物理削除すると
-     * 残高が変わる。保持期間の決着は「古い行を残高スナップショットへ畳み込む」形になり、
-     * その設計と検証は PR-C2 の担当である。
-     */
-    public function isPendingCarryForward(): bool
-    {
-        return $this === self::TicketLedgerEntry;
-    }
 }
diff --git a/app/Enums/Billing/TicketLedgerKind.php b/app/Enums/Billing/TicketLedgerKind.php
index c69a29e..e61f80d 100644
--- a/app/Enums/Billing/TicketLedgerKind.php
+++ b/app/Enums/Billing/TicketLedgerKind.php
@@ -6,7 +6,8 @@
 
 /**
  * チケット台帳エントリの種別。
- * 残高に影響するのは grant (正) / reserve_commit (負) / adjustment (正負) / clawback (負)。
+ * 残高に影響するのは grant (正) / reserve_commit (負) / adjustment (正負) / clawback (負) /
+ * carry_forward (正負)。
  * release は予約解放の監査痕跡 (delta=0、残高には影響しない)。
  */
 enum TicketLedgerKind: string
@@ -17,6 +18,16 @@ enum TicketLedgerKind: string
     case Adjustment = 'adjustment';
     /** 返金 (charge.refunded) による買い切り付与の逆仕訳 */
     case Clawback = 'clawback';
+    /**
+     * 保持期間 (7 年) の畳み込みが作る**繰越行**。
+     *
+     * ★他の case と性質が違う: これは**取引記録ではなく現在残高のスナップショット**である。
+     *   `(organization_id, source, expires_at)` ごとに期限超過の取引行を合算した 1 行で、
+     *   原取引の識別子 (説明 / stripe id / payment intent / 予約 id / 冪等キー) を
+     *   1 つも引き継がない。既存 kind へ相乗りさせないのは、この性質の違いを
+     *   型で見えるようにするためである (別物の概念を「似ているから」で統合しない)。
+     */
+    case CarryForward = 'carry_forward';
 
     public function label(): string
     {
@@ -26,6 +37,7 @@ public function label(): string
             self::Release => '予約解放',
             self::Adjustment => '調整',
             self::Clawback => '返金逆仕訳',
+            self::CarryForward => '繰越 (残高スナップショット)',
         };
     }
 }
diff --git a/app/Models/Billing/TicketLedgerEntry.php b/app/Models/Billing/TicketLedgerEntry.php
index 55ef2c7..bd83a27 100644
--- a/app/Models/Billing/TicketLedgerEntry.php
+++ b/app/Models/Billing/TicketLedgerEntry.php
@@ -8,6 +8,8 @@
 use App\Enums\Billing\TicketSource;
 use App\Models\Organization;
 use Carbon\CarbonImmutable;
+use Database\Factories\Billing\TicketLedgerEntryFactory;
+use Illuminate\Database\Eloquent\Factories\HasFactory;
 use Illuminate\Database\Eloquent\Model;
 use Illuminate\Database\Eloquent\Relations\BelongsTo;
 use LogicException;
@@ -23,6 +25,12 @@
  * idempotency_key (UNIQUE) で二重付与を防ぐ。買い切り購入行は payment_intent_id /
  * purchase_amount を持ち、返金 (charge.refunded) の逆仕訳 (clawback) の正本になる。
  *
+ * 保持期間 (7 年) の決着は**物理削除ではなく畳み込み**である
+ * (`TicketLedgerCarryForwardService`)。期限超過の取引行は
+ * `(organization_id, source, expires_at)` ごとに合算され、`kind = carry_forward` の
+ * **残高スナップショット 1 行**へ置換される。置換後の行は `carried_forward_through` に
+ * 集約期間の終端を持ち、原取引の識別子を 1 つも持たない。
+ *
  * 全カラムが TicketLedgerService の内部状態のため $fillable は持たない (明示代入のみ)。
  *
  * @property int $id
@@ -34,7 +42,9 @@
  * @property string $description
  * @property CarbonImmutable|null $granted_at
  * @property CarbonImmutable|null $expires_at
+ * @property CarbonImmutable|null $carried_forward_through
  * @property string|null $stripe_checkout_session_id
+ * @property string|null $stripe_invoice_id
  * @property string|null $payment_intent_id
  * @property int|null $purchase_amount
  * @property string|null $idempotency_key
@@ -42,6 +52,9 @@
  */
 class TicketLedgerEntry extends Model
 {
+    /** @use HasFactory<TicketLedgerEntryFactory> */
+    use HasFactory;
+
     /** append-only のため updated_at を持たない */
     public const UPDATED_AT = null;
 
@@ -83,6 +96,7 @@ protected function casts(): array
             'purchase_amount' => 'integer',
             'granted_at' => 'immutable_datetime',
             'expires_at' => 'immutable_datetime',
+            'carried_forward_through' => 'immutable_datetime',
             'created_at' => 'immutable_datetime',
         ];
     }
diff --git a/app/Services/Billing/Retention/BillingRetentionPurgerRegistry.php b/app/Services/Billing/Retention/BillingRetentionPurgerRegistry.php
index 9ee7a2d..254becb 100644
--- a/app/Services/Billing/Retention/BillingRetentionPurgerRegistry.php
+++ b/app/Services/Billing/Retention/BillingRetentionPurgerRegistry.php
@@ -35,6 +35,10 @@ public static function purgerClasses(): array
             // 子 → 親 の順 (入れ替えない)
             SubscriptionItemPurger::class,
             SubscriptionPurger::class,
+            // 台帳は物理削除ではなく畳み込みで決着する (残高を保存する操作)。
+            // 他 target と親子関係を持たないため順序制約は無いが、最後に置いて
+            // 「削除で決着する群」と「畳み込みで決着する群」を出力上も分ける。
+            TicketLedgerEntryPurger::class,
         ];
     }
 
diff --git a/app/Services/Billing/Retention/TicketLedgerEntryPurger.php b/app/Services/Billing/Retention/TicketLedgerEntryPurger.php
new file mode 100644
index 0000000..1dc23c3
--- /dev/null
+++ b/app/Services/Billing/Retention/TicketLedgerEntryPurger.php
@@ -0,0 +1,50 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing\Retention;
+
+use App\DataTransferObjects\Billing\BillingRetentionPurgeResultDto;
+use App\Enums\Billing\BillingRetentionTarget;
+use App\Services\Billing\Contracts\BillingRetentionPurger;
+use App\Services\Billing\TicketLedgerCarryForwardService;
+use Carbon\CarbonImmutable;
+
+/**
+ * チケット台帳の purger (**物理削除ではなく畳み込み**)。
+ *
+ * 他の target は行を消して決着させるが、台帳は残高の真実源であり、消すと残高が変わる。
+ * よってここは {@see AbstractBillingRetentionPurger} を継承せず、畳み込み
+ * ({@see TicketLedgerCarryForwardService}) への薄い adapter に徹する。
+ *
+ * ★`countFailClosed()` は常に 0 である。台帳は補助時計 (起算不能の異常検出) を持たず
+ *   (`created_at` は必ず入る)、参照されて消せない行も無い。決着できなかった組織は
+ *   `unexpectedFailures` として報告され、その行は `expiredRemaining` に残る
+ *   — 「安全のため残した」と「決着できなかった」を混同しない。
+ */
+final class TicketLedgerEntryPurger implements BillingRetentionPurger
+{
+    public function __construct(
+        private readonly TicketLedgerCarryForwardService $carryForward,
+    ) {}
+
+    public function target(): BillingRetentionTarget
+    {
+        return BillingRetentionTarget::TicketLedgerEntry;
+    }
+
+    public function countExpired(CarbonImmutable $threshold): int
+    {
+        return $this->carryForward->countExpired($threshold);
+    }
+
+    public function countFailClosed(CarbonImmutable $threshold): int
+    {
+        return 0;
+    }
+
+    public function purgeExpired(CarbonImmutable $threshold): BillingRetentionPurgeResultDto
+    {
+        return $this->carryForward->carryForward($threshold);
+    }
+}
diff --git a/app/Services/Billing/TicketLedgerCarryForwardService.php b/app/Services/Billing/TicketLedgerCarryForwardService.php
new file mode 100644
index 0000000..af8e02d
--- /dev/null
+++ b/app/Services/Billing/TicketLedgerCarryForwardService.php
@@ -0,0 +1,332 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing;
+
+use App\DataTransferObjects\Billing\BillingRetentionPurgeResultDto;
+use App\Enums\Billing\BillingRetentionTarget;
+use App\Enums\Billing\TicketLedgerKind;
+use App\Enums\Billing\TicketSource;
+use App\Models\Billing\TicketLedgerEntry;
+use App\Models\Organization;
+use Carbon\CarbonImmutable;
+use Illuminate\Database\Eloquent\Builder;
+use Illuminate\Database\Eloquent\Collection;
+use Illuminate\Database\Query\Builder as QueryBuilder;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Log;
+use RuntimeException;
+use Throwable;
+
+/**
+ * チケット台帳 (`ticket_ledger_entries`) の**保持期間 (7 年) の畳み込み**。
+ *
+ * 台帳は append-only の残高の真実源であり、古い行を物理削除すると**残高が変わる**。
+ * よって保持期間の決着は削除ではなく畳み込み — 保持期限より古い行を
+ * `(organization_id, source, expires_at)` の組ごとに合算し、合計 `delta` を持つ
+ * **繰越行 1 行**へ置換する。
+ *
+ * ★**`organization_id` を group key に必ず含める**。含め忘れると組織を跨いで残高を
+ *   合算する重大バグになる。残高の粒度が実際にこの 3 つで閉じることは
+ *   {@see TicketLedgerService::balance()} の集計条件 (organization_id + source
+ *   (purchased は `source IS NULL` も含む) + `expires_at IS NULL or > now`) と対応する。
+ *   **`source IS NULL` (legacy 行) は独立した group** として扱う (purchased へ寄せると
+ *   `sumActiveHolds` の legacy 除外規則と意味がズレる)。
+ *
+ * ★**繰越行は「取引記録」ではなく「現在残高のスナップショット」である**。
+ *   原取引の識別子 (説明 / stripe id / payment intent / 予約 id / 冪等キー / 個別日時) を
+ *   1 つも引き継がない — 引き継いだら「7 年より古い取引の情報が残る」ことになり、
+ *   保持期間の意味が消える。引き継ぐのは残高の粒度を決める 3 つ
+ *   (`organization_id` / `source` / `expires_at`) だけである。
+ *   性質の違いは `kind = carry_forward` として型に出す (既存 kind へ相乗りしない)。
+ *
+ * ★**append-only 不変条件との関係**: 本サービスは Eloquent の delete guard を迂回する
+ *   Query Builder 直書きで行を消す**唯一の**経路である ({@see TicketLedgerService} の
+ *   `backfillPaymentIntentId` と同じ閉じ込め方)。「計上の事後改竄をしない」という
+ *   append-only の意図は保たれる — 個別行の値を書き換えるのではなく、
+ *   **保持期限を超えた区間ごと残高スナップショットへ置換する**操作だからである。
+ *
+ * ★**保証しないもの (誇張しない)**:
+ *   - 畳み込み後は**原取引が復元できない**。返金逆仕訳 (`clawbackPurchasedByPaymentIntent`) /
+ *     消費の冪等キー (`consume:{reservationId}`) / signup grant の部分 UNIQUE index は
+ *     いずれも**畳み込まれた行に対しては効かなくなる**。7 年より古い決済への遅延返金や
+ *     7 年前の予約の commit は現実には起きないが、「index が守っている」と言えるのは
+ *     畳み込み前の行までである (signup grant の**正本**は
+ *     `organizations.signup_tickets_granted_at` の条件付き UPDATE で、これは畳み込まれない)
+ *   - **合計 0 の group は繰越行を作らない**ため、その group の `expires_at` は
+ *     台帳から消える。未失効の monthly が完全に消費済みという組み合わせでのみ
+ *     `nearestMonthlyExpiry` の探索結果が変わる (残高は不変。既知窓としてテストで固定)
+ */
+final class TicketLedgerCarryForwardService
+{
+    /** 繰越行の冪等キーの接頭辞。 */
+    public const string IDEMPOTENCY_KEY_PREFIX = 'carry_forward:';
+
+    /**
+     * 繰越行の説明。
+     *
+     * ★詳細設計は `description` も null にすると書いているが、実列は **NOT NULL** である
+     *   (`2026_06_11_091400_create_ticket_tables.php` を実読で確認)。列を nullable へ変える
+     *   代わりに**取引追跡情報を一切含まない固定文言**を入れる。原取引の説明は残らないため
+     *   「個別取引が復元不能」という要件は満たす。
+     */
+    public const string CARRY_FORWARD_DESCRIPTION = '保持期間の繰越 (残高スナップショット)';
+
+    /** 冪等キー / 集約終端の日時表現 (UTC 正規化)。 */
+    private const string KEY_TIME_FORMAT = 'Y-m-d\TH:i:s\Z';
+
+    /** 冪等キーで null を表す明示トークン (空文字との衝突を避ける)。 */
+    private const string NULL_TOKEN = 'null';
+
+    /** 起算済み (台帳は `created_at` が起算点) かつ期限超過の行数。 */
+    public function countExpired(CarbonImmutable $threshold): int
+    {
+        return TicketLedgerEntry::query()
+            ->where('created_at', '<=', $threshold)
+            ->count();
+    }
+
+    /**
+     * 繰越行の冪等キー。
+     *
+     * 形は `carry_forward:{orgId}:{source}:{expiresAt}:{threshold}` で固定する。
+     * **null は明示トークン `'null'`**、日時は **UTC 正規化**。
+     * **同一 group を同じ閾値で再実行すれば同じキーになる** (= UNIQUE が二重の繰越行を弾く)。
+     *
+     * ★キーの第 4 要素は**その実行の閾値**であって `carried_forward_through` (集約終端) では
+     *   ない。両者は普段一致するが、保持年数を延ばして閾値が過去へ動いた場合だけ食い違う
+     *   (終端は単調に進むので前回値を保つ)。**冪等の単位は「同じ入力で同じ実行をしたか」**
+     *   なので、キーは入力である閾値で決める。
+     *
+     * 既存の signup grant 部分 UNIQUE index の述語 (`idempotency_key LIKE 'signup_grant:%'`) とは
+     * 接頭辞が異なるため衝突しない。
+     */
+    public static function idempotencyKeyFor(
+        int $organizationId,
+        ?TicketSource $source,
+        ?CarbonImmutable $expiresAt,
+        CarbonImmutable $threshold,
+    ): string {
+        return implode(':', [
+            rtrim(self::IDEMPOTENCY_KEY_PREFIX, ':'),
+            (string) $organizationId,
+            $source === null ? self::NULL_TOKEN : $source->value,
+            $expiresAt === null ? self::NULL_TOKEN : $expiresAt->utc()->format(self::KEY_TIME_FORMAT),
+            $threshold->utc()->format(self::KEY_TIME_FORMAT),
+        ]);
+    }
+
+    /**
+     * 保持期限より古い台帳行を組織ごとに畳み込む。
+     *
+     * 組織 1 件 = 1 トランザクション。途中で失敗した組織は**まるごと巻き戻る**ので
+     * 「繰越行だけ入って原取引が残る (= 二重計上)」も「原取引だけ消えて繰越行が無い
+     * (= 残高消失)」も起きない。失敗は件数として報告し、残りの組織は進む。
+     */
+    public function carryForward(CarbonImmutable $threshold): BillingRetentionPurgeResultDto
+    {
+        $candidates = $this->countExpired($threshold);
+        $processed = 0;
+        $unexpectedFailures = 0;
+
+        foreach ($this->organizationsWithExpiredEntries($threshold) as $organization) {
+            try {
+                $processed += DB::transaction(
+                    fn (): int => $this->carryForwardOrganization($organization, $threshold),
+                );
+            } catch (Throwable $e) {
+                $unexpectedFailures++;
+                // 例外 message は載せない (外部生成の可変文字列)。target と例外クラスだけ。
+                Log::warning('ticket ledger carry forward failed', [
+                    'target' => BillingRetentionTarget::TicketLedgerEntry->value,
+                    'organization_id' => $organization->getKey(),
+                    'error_class' => $e::class,
+                ]);
+            }
+        }
+
+        return new BillingRetentionPurgeResultDto(
+            target: BillingRetentionTarget::TicketLedgerEntry,
+            candidates: $candidates,
+            processed: $processed,
+            // 台帳は補助時計 (起算不能の異常) を持たず、参照されて消せない行も無い。
+            // 失敗した組織は fail-closed ではなく unexpectedFailures として報告する
+            // (「安全のため残した」ではなく「決着できなかった」である)。
+            failClosed: 0,
+            unexpectedFailures: $unexpectedFailures,
+            expiredRemaining: $this->countExpired($threshold),
+        );
+    }
+
+    /**
+     * 期限超過の台帳行を持つ組織 (id 昇順 = ロック順序の固定)。
+     *
+     * @return Collection<int, Organization>
+     */
+    private function organizationsWithExpiredEntries(CarbonImmutable $threshold): Collection
+    {
+        return Organization::query()
+            ->whereHas(
+                'ticketLedgerEntries',
+                fn (Builder $query): Builder => $query->where('created_at', '<=', $threshold),
+            )
+            ->orderBy('id')
+            ->get();
+    }
+
+    /**
+     * 1 組織ぶんの畳み込み (organizations 行ロック下)。
+     *
+     * @return int 畳み込んだ (置換で消えた) 行数
+     */
+    private function carryForwardOrganization(Organization $organization, CarbonImmutable $threshold): int
+    {
+        // 残高判定・台帳追記の直列化点。reserve / commit と同じロックを取る
+        // (畳み込みの最中に同じ組織の残高が動かないようにする)
+        Organization::query()
+            ->whereKey($organization->getKey())
+            ->lockForUpdate()
+            ->firstOrFail();
+
+        $organizationId = $organization->getKey();
+        if (! is_int($organizationId)) {
+            throw new RuntimeException('組織 id が解決できません (畳み込みは中止する)');
+        }
+
+        $processed = 0;
+        foreach ($this->expiredGroups($organizationId, $threshold) as $group) {
+            $processed += $this->carryForwardGroup(
+                $organizationId,
+                $group->source,
+                $group->expires_at,
+                $threshold,
+            );
+        }
+
+        return $processed;
+    }
+
+    /**
+     * 期限超過行の group key 一覧 (`source` / `expires_at` の相異なる組)。
+     *
+     * @return Collection<int, TicketLedgerEntry>
+     */
+    private function expiredGroups(int $organizationId, CarbonImmutable $threshold): Collection
+    {
+        return TicketLedgerEntry::query()
+            ->where('organization_id', $organizationId)
+            ->where('created_at', '<=', $threshold)
+            ->select(['source', 'expires_at'])
+            ->distinct()
+            ->get();
+    }
+
+    /**
+     * 1 group を繰越行へ置換する。
+     *
+     * @return int 置換で消えた行数
+     */
+    private function carryForwardGroup(
+        int $organizationId,
+        ?TicketSource $source,
+        ?CarbonImmutable $expiresAt,
+        CarbonImmutable $threshold,
+    ): int {
+        $total = (int) $this->groupQuery($organizationId, $source, $expiresAt, $threshold)->sum('delta');
+        $through = $this->resolveThrough($organizationId, $source, $expiresAt, $threshold);
+
+        // 合計 0 の繰越行は作らない (残高に寄与しない行を増やさない)
+        if ($total !== 0) {
+            $inserted = DB::table('ticket_ledger_entries')->insertOrIgnore([
+                'organization_id' => $organizationId,
+                'delta' => $total,
+                'kind' => TicketLedgerKind::CarryForward->value,
+                'source' => $source?->value,
+                // --- ここから下は取引追跡情報。繰越行は 1 つも引き継がない ---
+                'reservation_id' => null,
+                'description' => self::CARRY_FORWARD_DESCRIPTION,
+                'granted_at' => null,
+                'stripe_checkout_session_id' => null,
+                'stripe_invoice_id' => null,
+                'payment_intent_id' => null,
+                'purchase_amount' => null,
+                // --- 残高の粒度と集約終端 ---
+                'expires_at' => $expiresAt?->toDateTimeString(),
+                'carried_forward_through' => $through->toDateTimeString(),
+                'idempotency_key' => self::idempotencyKeyFor($organizationId, $source, $expiresAt, $threshold),
+                'created_at' => CarbonImmutable::now()->toDateTimeString(),
+            ]);
+
+            // 冪等キーの衝突 = 同一 group を同一閾値で二重に畳み込もうとしている
+            // (通常は起きない。同じ閾値の再実行では対象行が既に消えているため)。
+            // 起きうるのは「畳み込み済みの group へ、閾値より古い created_at の行が
+            // 後から入った」ときで、既存の繰越行へ足し込むには UPDATE が要る。
+            // ここで原取引を消すと繰越行 1 行ぶんの残高が失われるため fail-closed で中止する
+            // (トランザクションごと巻き戻り、この組織は unexpectedFailures として報告される)。
+            if ($inserted !== 1) {
+                throw new RuntimeException('繰越行の冪等キーが衝突しました (畳み込みを中止して巻き戻す)');
+            }
+        }
+
+        // 繰越行の created_at は now (= 閾値より後) なので、この削除の対象にならない
+        return $this->groupQuery($organizationId, $source, $expiresAt, $threshold)->delete();
+    }
+
+    /**
+     * この繰越が集約した期間の終端。
+     *
+     * 既に繰越行を含む group (再畳み込み) では**前回の終端と今回の閾値の大きい方**を採り、
+     * 単調に進むことを保証する。
+     */
+    private function resolveThrough(
+        int $organizationId,
+        ?TicketSource $source,
+        ?CarbonImmutable $expiresAt,
+        CarbonImmutable $threshold,
+    ): CarbonImmutable {
+        /** @var mixed $previous */
+        $previous = $this->groupQuery($organizationId, $source, $expiresAt, $threshold)
+            ->max('carried_forward_through');
+
+        if (! is_string($previous) || $previous === '') {
+            return $threshold;
+        }
+
+        $parsed = CarbonImmutable::parse($previous);
+
+        return $parsed->greaterThan($threshold) ? $parsed : $threshold;
+    }
+
+    /**
+     * group を指す Query Builder (呼ぶたびに作り直す = 集計で汚れない)。
+     *
+     * ★Eloquent ではなく Query Builder を使う。台帳モデルは delete を例外化しており
+     *   (append-only guard)、畳み込みはその唯一の例外だからである。迂回を 1 箇所に閉じ込め、
+     *   「どこで消しているか」をコードで見えるようにする。
+     */
+    private function groupQuery(
+        int $organizationId,
+        ?TicketSource $source,
+        ?CarbonImmutable $expiresAt,
+        CarbonImmutable $threshold,
+    ): QueryBuilder {
+        $query = DB::table('ticket_ledger_entries')
+            ->where('organization_id', $organizationId)
+            ->where('created_at', '<=', $threshold);
+
+        if ($source === null) {
+            $query->whereNull('source');
+        } else {
+            $query->where('source', $source->value);
+        }
+
+        if ($expiresAt === null) {
+            $query->whereNull('expires_at');
+        } else {
+            $query->where('expires_at', $expiresAt);
+        }
+
+        return $query;
+    }
+}
diff --git a/database/factories/Billing/TicketLedgerEntryFactory.php b/database/factories/Billing/TicketLedgerEntryFactory.php
new file mode 100644
index 0000000..8570280
--- /dev/null
+++ b/database/factories/Billing/TicketLedgerEntryFactory.php
@@ -0,0 +1,103 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Database\Factories\Billing;
+
+use App\Enums\Billing\TicketLedgerKind;
+use App\Enums\Billing\TicketSource;
+use App\Models\Billing\TicketLedgerEntry;
+use App\Models\Organization;
+use Carbon\CarbonImmutable;
+use Illuminate\Database\Eloquent\Factories\Factory;
+
+/**
+ * 台帳エントリ (残高の真実源) の fixture。
+ *
+ * 既定は purchased バケットの無期限付与 (+1)。保持期間の畳み込み (PR-C2) の検証で
+ * 「7 年より古い取引行」を任意の出所・失効時刻で並べるために使う。
+ *
+ * ★台帳は append-only (update / delete が Model イベントで例外化されている)。
+ *   factory は insert しか行わないため不変条件に触れない。
+ *
+ * @extends Factory<TicketLedgerEntry>
+ */
+class TicketLedgerEntryFactory extends Factory
+{
+    protected $model = TicketLedgerEntry::class;
+
+    /**
+     * @return array<string, mixed>
+     */
+    public function definition(): array
+    {
+        return [
+            'organization_id' => Organization::factory(),
+            'delta' => 1,
+            'kind' => TicketLedgerKind::Grant,
+            'source' => TicketSource::Purchased,
+            'description' => 'テスト付与',
+            'granted_at' => CarbonImmutable::now(),
+            'expires_at' => null,
+            'created_at' => CarbonImmutable::now(),
+        ];
+    }
+
+    public function forOrganization(Organization $organization): static
+    {
+        return $this->state(fn (): array => ['organization_id' => $organization->getKey()]);
+    }
+
+    /** 取引成立日時 (保持期間の起算点)。 */
+    public function createdAt(CarbonImmutable $createdAt): static
+    {
+        return $this->state(fn (): array => ['created_at' => $createdAt]);
+    }
+
+    /** monthly バケットの期限付き付与。 */
+    public function monthly(?CarbonImmutable $expiresAt): static
+    {
+        return $this->state(fn (): array => [
+            'source' => TicketSource::Monthly,
+            'expires_at' => $expiresAt,
+        ]);
+    }
+
+    /** purchased バケット (無期限)。 */
+    public function purchased(): static
+    {
+        return $this->state(fn (): array => [
+            'source' => TicketSource::Purchased,
+            'expires_at' => null,
+        ]);
+    }
+
+    /** P5 以前の出所を持たない行 (purchased バケットへ畳まれる legacy 行)。 */
+    public function legacy(): static
+    {
+        return $this->state(fn (): array => ['source' => null]);
+    }
+
+    /** 消費行 (負 delta)。消費した grant と同じ失効時刻を載せる。 */
+    public function consumed(int $amount, ?CarbonImmutable $expiresAt = null): static
+    {
+        return $this->state(fn (): array => [
+            'delta' => -$amount,
+            'kind' => TicketLedgerKind::ReserveCommit,
+            'granted_at' => null,
+            'expires_at' => $expiresAt,
+        ]);
+    }
+
+    /** 枚数 (正: 付与 / 負: 消費)。 */
+    public function delta(int $delta): static
+    {
+        return $this->state(fn (): array => ['delta' => $delta]);
+    }
+
+    /** 冪等キー (二重付与防止キー) を持つ行。 */
+    public function idempotencyKey(string $key): static
+    {
+        return $this->state(fn (): array => ['idempotency_key' => $key]);
+    }
+}
diff --git a/database/migrations/2026_08_10_114500_add_carried_forward_through_to_ticket_ledger_entries.php b/database/migrations/2026_08_10_114500_add_carried_forward_through_to_ticket_ledger_entries.php
new file mode 100644
index 0000000..2b85db2
--- /dev/null
+++ b/database/migrations/2026_08_10_114500_add_carried_forward_through_to_ticket_ledger_entries.php
@@ -0,0 +1,36 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\Schema;
+
+/**
+ * 保持期間 (7 年) の畳み込み (PR-C2) が作る**繰越行**の終端を記録する列を足す。
+ *
+ * `carried_forward_through` は「この繰越行が集約した期間の終端」= 畳み込み時の保持期限の閾値。
+ * 繰越行は**取引記録ではなく現在残高のスナップショット**であり、原取引の識別子を 1 つも
+ * 引き継がない。よって「いつまでの取引が畳み込まれたか」を表す唯一の情報がこの列になる。
+ *
+ * - null = 通常の取引行 (畳み込みで作られた行ではない)。既存行は全て null のままでよい
+ * - 再畳み込み (繰越行同士の合算) でも値は**単調に進む** (前回値と今回の閾値の大きい方)
+ *
+ * 索引は張らない — 本列で検索する経路は無く、畳み込みの抽出条件は `created_at` である。
+ */
+return new class extends Migration
+{
+    public function up(): void
+    {
+        Schema::table('ticket_ledger_entries', function (Blueprint $table): void {
+            $table->timestamp('carried_forward_through')->nullable()->after('expires_at');
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::table('ticket_ledger_entries', function (Blueprint $table): void {
+            $table->dropColumn('carried_forward_through');
+        });
+    }
+};
diff --git a/docs/architecture.md b/docs/architecture.md
index 5baa34e..cbc2ed8 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -1418,3 +1418,41 @@ ## 冪等キーの claim と保持期間 (REST API v1 / MCP)
   使われていることが前提 (既存の `billing:send-billing-reminders` /
   `render:reconcile-outputs` と同じ。本節で新しく持ち込む前提ではない)。
   満たさないと多重実行しうるが DELETE は冪等で、害は `report()` の重複に留まる。
+
+## 課金記録の保持期間 (7 年) の決着 (T143 / T144)
+
+保持年数の正本は `config/legal.php` の `billing_retention_years`、唯一の解決点は
+`App\Support\Legal\BillingRetention` (`BillingRetentionConfigSingleSourceTest` が機械固定)。
+運用手順・障害対応は **`docs/billing-retention-runbook.md` が正本**。
+
+- **コマンド**: `billing:purge-retention-expired` (既定 dry-run / `--apply` で実処理)。
+  日次登録は `routes/console.php` の `Schedule::command('… --apply')->daily()->onOneServer()`。
+- **決着の方式は target で 2 種類ある**。削除で決着する 6 target
+  (`stripe_webhook_event` / `billing_checkout_session` / `ticket_checkout_session` /
+  `ticket_auto_recharge_attempt` / `subscription_item` / `subscription`) と、
+  **畳み込み**で決着する `ticket_ledger_entry` である。実行順は registry
+  (`BillingRetentionPurgerRegistry`) が持ち、**子 → 親** (`subscription_item` →
+  `subscription`) は入れ替えない (親を先に消すと FK cascade で子が件数報告を経由せず消える)。
+- **台帳 (`ticket_ledger_entries`) だけ方式が違う理由**: そこが**残高の真実源**だからである。
+  期限超過の行をそのまま消すと利用者のチケット残高が変わる。畳み込み
+  (`App\Services\Billing\TicketLedgerCarryForwardService`) は
+  `(organization_id, source, expires_at)` ごとに合算し、`kind = carry_forward` の
+  **残高スナップショット 1 行**へ置換する。**group key に `organization_id` を必ず含める**
+  (欠くと組織を跨いで残高を合算する)。`source IS NULL` (legacy 行) は独立した group。
+  繰越行は**取引記録ではなく残高のスナップショット**であり、原取引の識別子を 1 つも
+  引き継がない (`carried_forward_through` に集約期間の終端だけを持つ)。
+  残高が 1 枚も変わらないことは `tests/Feature/Billing/TicketLedgerCarryForwardTest.php` が
+  組織 / source / 失効時刻の粒度で機械固定する。
+- **台帳を読む場所は目録制** (`TicketLedgerReaderInventoryTest`)。畳み込みの帰結として
+  「7 年より古い個別取引は復元できない」ため、個別行に依存する読み手が宣言なしに増えると
+  ある日その経路だけが静かに壊れる。目録は読み方 (`aggregate` / `row_detail` / `other_table`)
+  の宣言を強制する。
+- **監視対象**: 本コマンドの終了コード (`unexpected_failures > 0` で `FAILURE`) と、
+  出力の `horizon:` 行。**`fail_closed` は「安全に残した」であって「規約を満たした」ではない**
+  ので、`horizon: NG` の継続と `fail_closed` の増加を正常成功として扱わない。
+- **保証しないもの (誇張しない)**: 目録 (`BillingRetentionTarget` /
+  `BillingRetentionExclusion`) は**人間の申告**であり、課金取引の記録が
+  `app/Models/Billing/` の外や Eloquent を経由しない表に置かれれば gate は沈黙する。
+  本番で日次処理が止まっていないことも保証しない (責務は終了コードと scheduler 運用)。
+  畳み込みで失われるもの (返金逆仕訳の逆引き / 消費の冪等キー / signup grant の部分 UNIQUE
+  index の保護範囲) は `docs/billing-retention-runbook.md` §7 が一覧を持つ。
diff --git a/docs/billing-retention-runbook.md b/docs/billing-retention-runbook.md
new file mode 100644
index 0000000..9fc3283
--- /dev/null
+++ b/docs/billing-retention-runbook.md
@@ -0,0 +1,153 @@
+# 課金記録の保持期間 (7 年) 運用 runbook
+
+> 対象コマンド: `php artisan billing:purge-retention-expired [--apply] [--target=...]`
+> 設計: `devnotes/20260809-0908-account-deletion-grace/detailed-design.md` (PR-C1 / PR-C2)
+> 保持年数の正本: `config/legal.php` の `billing_retention_years` (唯一の解決点は
+> `App\Support\Legal\BillingRetention`)
+
+## 1. これは何をするコマンドか
+
+保持期限 (既定 **7 年**) を超えた課金記録を **target ごとに決着**させる。
+
+| 決着の方式 | target | 何が起きるか |
+|---|---|---|
+| 物理削除 | `stripe_webhook_event` / `billing_checkout_session` / `ticket_checkout_session` / `ticket_auto_recharge_attempt` / `subscription_item` / `subscription` | 行が消える |
+| **畳み込み** | `ticket_ledger_entry` | 行が消え、`(organization_id, source, expires_at)` ごとの **残高スナップショット 1 行** (`kind = carry_forward`) に置き換わる |
+
+台帳 (`ticket_ledger_entries`) だけ方式が違うのは、**そこが残高の真実源**だからである。
+古い行をそのまま消すと残高が変わる (= 利用者のチケットが増減する)。畳み込みは
+**残高を 1 枚も変えずに個別取引の情報だけを落とす**操作で、
+`tests/Feature/Billing/TicketLedgerCarryForwardTest.php` が残高保存を機械固定している。
+
+**既定は dry-run**。`--apply` を付けたときだけ実処理が走る。
+
+## 2. 出力の読み方
+
+```
+[apply] 保持期間 7 年 / 閾値 2019-08-10 11:45:00 以前の起算日時が期限超過
+  stripe_webhook_event: expired=12 processed=12 fail_closed=0 unexpected_failures=0 remaining=0
+  ...
+  ticket_ledger_entry: expired=340 processed=340 fail_closed=0 unexpected_failures=0 remaining=0
+合計: 決着 352 件 / 残存 (期限超過) 0 件 / fail-closed 0 件
+horizon: OK (期限超過 0 件)
+```
+
+| 項目 | 意味 |
+|---|---|
+| `expired` | 起算済み (起算列が非 null) かつ保持期限を超えた件数 |
+| `processed` | 実際に決着した件数 (削除 または 畳み込みで消えた行数) |
+| `fail_closed` | **安全のため残した**件数。(a) 起算列が null で補助時計が古い異常、(b) 参照中で消せないもの |
+| `unexpected_failures` | 想定外の失敗。**件数の 0 は信用できない**という印 |
+| `remaining` | 決着後に残った期限超過の件数 |
+| `horizon:` | **規約 (最長 7 年) を満たしているか**の観測点。`remaining` の合計が 0 なら OK |
+
+- **出力に PII は出さない** (organization id / メールアドレス / 金額 / Stripe 識別子を載せない)。
+  調査で個別の行に降りる必要が出たら、コマンドの出力ではなく DB を直接見ること。
+- **終了コードは 2 分類**。`unexpected_failures > 0` なら `FAILURE`、それ以外は `SUCCESS`。
+  **`fail_closed` が残っていても SUCCESS である** (安全に残したのは異常ではない)。
+
+> ⚠ **`fail_closed` は「安全に残した」であって「規約を満たした」ではない**。
+> 規約が宣言した年数を満たしたと言えるのは `horizon: OK` (= `remaining` 合計 0) のときだけである。
+> `fail_closed` を「対処済み」として扱わないこと。
+
+## 3. 日次スケジュール
+
+`routes/console.php` に登録済み:
+
+```php
+Schedule::command('billing:purge-retention-expired --apply')->daily()->onOneServer();
+```
+
+`onOneServer()` は **scheduler が動いていること + ロックを提供する cache driver** を前提にする
+(既存の `billing:send-billing-reminders` / `idempotency:prune` と同じ前提)。
+
+## 4. 有効化の手順 (PR-C2 デプロイ時に 1 回)
+
+1. **PR-C1 の dry-run で棚卸しする** — `php artisan billing:purge-retention-expired`
+   (target 別件数と `unexpected_failures` を確認する)
+2. **PR-C2 をデプロイする** (台帳の畳み込みと `--apply` が入る)
+3. **初回 apply を能動的に実行する** — `php artisan billing:purge-retention-expired --apply`
+   - schedule は既に有効なので、これは「初回を見届ける」ための能動実行である
+     (schedule を抑止する意味ではない。抑止機構は持たない)
+4. **apply 後の horizon を確認する** — 出力の `horizon: OK (期限超過 0 件)`
+   - **`fail_closed` を含めて 0 件**であることを確認する (分類を問わない)
+5. **4 が満たされて初めて PR-C3 (規約文面の公開) を出す**
+6. 日次 scheduler を**継続監視へ移す** (§6)
+
+### PR-C3 のチェックリスト (必須)
+
+C3 の PR 説明に **初回 apply の出力の証跡**を貼ること:
+
+- [ ] target 別件数 (`expired` / `processed`)
+- [ ] `fail_closed` = 0
+- [ ] `unexpected_failures` = 0
+- [ ] `horizon: OK (期限超過 0 件)`
+
+証跡が無いまま C3 を出すと「規約が宣言した年数を実処理が満たしていない状態で文面を公開する」
+ことになる。これは利用者から見て検証不能な形の規約違反である。
+
+## 5. `fail_closed` が続くときの解消手順
+
+`fail_closed` は 2 種類ある。**まずどちらかを切り分ける**。
+
+### (a) 起算列が null で補助時計が古い (起算不能の異常)
+
+例: `processed_at IS NULL` のまま 7 年経った webhook 記録。
+「取引が決着していない記録を、決着したことにして捨てない」ため消していない。
+
+1. 当該 target の行を DB で確認する (`processed_at IS NULL AND created_at <= 閾値`)
+2. **なぜ起算されなかったか**を特定する (処理の取りこぼし / 例外で終わった / 手動投入)
+3. 起算列を正しい値で埋めるか、業務上「決着済み」と判断できるなら記録として決着させる
+4. 再実行して `fail_closed` が減ることを確認する
+
+### (b) 参照中で消せない (子が残っている)
+
+例: 明細 (`subscription_items`) が残っている `subscriptions`。
+FK は cascade なので DELETE 自体は成功するが、それは**子 purger が決着させられなかった行を
+件数報告を経由せず道連れにする**ことを意味するので、残して報告する側を採っている。
+
+1. 子 target の `fail_closed` / `unexpected_failures` を先に見る (原因は子側にあることが多い)
+2. 子を決着させてから親を再実行する (registry の実行順は **子 → 親**。入れ替えないこと)
+
+### 件数が単調増加しているときの初動
+
+`fail_closed` が日々増えている = **新しい異常が継続的に生まれている**ということである
+(過去の残骸ではない)。
+
+1. 増加している target を 1 つに絞る (`--target=` の dry-run)
+2. 直近で追加された行の生成経路を特定する (webhook の失敗 / 決済フローの中断)
+3. **保持期間の処理ではなく生成側の不具合**として扱う。purge 側の閾値や分類を緩めて
+   件数を減らさないこと (fail-open になる)
+
+## 6. 監視対象
+
+**本コマンドの終了コードと出力の `horizon:` 行**を監視対象に登録する。
+
+- `FAILURE` (= `unexpected_failures > 0`) … 件数報告そのものが信用できない状態。即調査
+- `horizon: NG` が**継続** … 規約 (/privacy が宣言する最長 7 年) を満たせていない状態
+- `fail_closed` の**継続・増加** … 正常成功として扱わない (§5)
+
+## 7. 台帳の畳み込みで**失われるもの** (誇張しない)
+
+畳み込み後、7 年より古い台帳行については以下が**復元できない**。これは不具合ではなく
+保持期間の意味そのものだが、依存している機構があるので明記する。
+
+- **原取引の識別子** — 説明 / `stripe_checkout_session_id` / `stripe_invoice_id` /
+  `payment_intent_id` / `purchase_amount` / `reservation_id` / 個別の `created_at`
+- **返金逆仕訳** (`clawbackPurchasedByPaymentIntent`) は畳み込まれた購入行を引けない
+  (7 年より古い決済への遅延返金は現実には起きないが、「引ける」とは言えない)
+- **消費の冪等キー** (`consume:{reservationId}`) が消えるため、7 年前の予約を今 commit すると
+  二重計上を防げない (予約 TTL は 30 分であり到達しない)
+- **signup grant の部分 UNIQUE index** (`idempotency_key LIKE 'signup_grant:%'`) は
+  畳み込まれた行を守らない。ただし「org 生涯 1 回」の**正本は
+  `organizations.signup_tickets_granted_at` の条件付き UPDATE** であり、これは畳み込みの
+  対象外なので不変条件そのものは維持される (index は保険であって正本ではない)
+- **合計 0 の group は繰越行を作らない**ため、その group の `expires_at` が台帳から消える。
+  「未失効の monthly が完全に消費済み」という組み合わせでのみ `nearestMonthlyExpiry` の
+  探索結果が変わる (**残高は不変**。既知窓としてテストで固定してある)
+
+## 8. 関連
+
+- `docs/account-deletion-runbook.md` — 退会 (アカウント削除) の運用
+- `docs/inquiry-deletion-runbook.md` — 問い合わせの保持期間 (別概念・別所有者)
+- `docs/architecture.md` §課金記録の保持期間 (7 年) の決着
diff --git a/docs/factories.md b/docs/factories.md
index 3461b16..774994b 100644
--- a/docs/factories.md
+++ b/docs/factories.md
@@ -46,6 +46,7 @@ ## Factory 一覧 (テンプレート同梱)
 | `Billing\TicketAutoRechargeFactory` | Billing/TicketAutoRecharge | `enabled()` (PM + 同意記録済み), `preConsented()` (事前同意のみ = pendingAutoEnable), `consentedMaxAmount(int $amount)` (価格改定 → 再同意シナリオ), `disabledByFailures()` |
 | `Billing\StripeWebhookEventFactory` | Billing/StripeWebhookEvent | `processed(?CarbonImmutable $processedAt = null)` (保持期間の起算済み), `failed()` (処理失敗のまま滞留 = 起算されない)。既定は受信済み・未処理 |
 | `Billing\TicketAutoRechargeAttemptFactory` | Billing/TicketAutoRechargeAttempt | `withInvoice(?string $invoiceId = null)`, `paid()`, `failed()`, `canceled()` (既定は invoice 未作成の pending。**org あたり pending は DB partial unique で 1 件まで**) |
+| `Billing\TicketLedgerEntryFactory` | Billing/TicketLedgerEntry | `forOrganization($org)`, `createdAt(CarbonImmutable $at)` (保持期間の起算点), `monthly(?CarbonImmutable $expiresAt)`, `purchased()`, `legacy()` (source null の P5 以前行), `consumed(int $amount, ?CarbonImmutable $expiresAt = null)`, `delta(int $delta)`, `idempotencyKey(string $key)`。既定は purchased の無期限付与 (+1)。**台帳は append-only** で factory は insert のみ |
 
 Factory を持たないモデル (Role / Permission / Team 等) は seed 固定値
 または Service (`OrganizationProvisioningService` 等) 経由で作る。
diff --git a/routes/console.php b/routes/console.php
index 3d37884..f0a4347 100644
--- a/routes/console.php
+++ b/routes/console.php
@@ -172,3 +172,22 @@
 |   前提にする (既存の billing:send-billing-reminders / render:reconcile-outputs と同じ前提)。
 */
 Schedule::command('idempotency:prune')->daily()->onOneServer();
+
+/*
+|--------------------------------------------------------------------------
+| 課金記録の保持期間 (7 年) の決着 (T144 / PR-C2)
+|--------------------------------------------------------------------------
+| 保持期限 (config legal.billing_retention_years) を超えた課金記録を日次で決着させる。
+| 削除で決着する 6 target と、**畳み込み**で決着する台帳 (ticket_ledger_entries) がある
+| (台帳は残高の真実源なので消すと残高が変わる。古い行は残高スナップショットへ置換する)。
+|
+| **監視対象**: 本コマンドの終了コードと出力の `horizon:` 行。
+|   - `horizon: NG` が続く = 規約 (/privacy が宣言する最長 7 年) を満たせていない状態である。
+|     **`fail_closed` は「安全に残した」であって「規約を満たした」ではない**ため残存に数える。
+|   - `fail_closed` の**継続・増加**を正常成功として扱わないこと (解消手順は
+|     docs/billing-retention-runbook.md)。
+|
+| 本コマンドは PR-C2 のデプロイ時点から --apply で有効である (runbook の初回 apply は
+| 「初回を能動的に完走させて結果を確認する」ためのもので、schedule の抑止ではない)。
+*/
+Schedule::command('billing:purge-retention-expired --apply')->daily()->onOneServer();
diff --git a/tests/Architecture/BillingRetentionTargetInventoryTest.php b/tests/Architecture/BillingRetentionTargetInventoryTest.php
index dd168ec..81637dc 100644
--- a/tests/Architecture/BillingRetentionTargetInventoryTest.php
+++ b/tests/Architecture/BillingRetentionTargetInventoryTest.php
@@ -63,8 +63,8 @@
 /** 母集団の**現在件数** (cap ではなく exact-fit。増減したら必ずこの数字を書き換える)。 */
 const BILLING_RETENTION_POPULATION_COUNT = 14;
 
-/** C1 時点の purger 実装数 (TicketLedgerEntry は C2 の畳み込み待ちで含まない)。 */
-const BILLING_RETENTION_PURGER_COUNT = 6;
+/** purger 実装数 (全 target が purger を持つ。削除 6 本 + 畳み込み 1 本)。 */
+const BILLING_RETENTION_PURGER_COUNT = 7;
 
 /**
  * 母集団: app/Models/Billing 配下の全 Model + 外部列挙。
@@ -191,6 +191,22 @@ function billingRetentionPurgerClassesOnDisk(): array
     return $classes;
 }
 
+/**
+ * purger 実装クラスが宣言する target の value。
+ *
+ * **コンテナ経由で解決する** (`new $class` にしない) — purger は依存を注入されうるため、
+ * gate 側で生成方法を固定すると「依存を持つ purger を書けない」制約になる。
+ *
+ * @param  class-string<BillingRetentionPurger>  $class
+ */
+function billingRetentionTargetOf(string $class): string
+{
+    $purger = app($class);
+    expect($purger)->toBeInstanceOf(BillingRetentionPurger::class);
+
+    return $purger->target()->value;
+}
+
 test('検査 1: 課金モデルの母集団が target / exclusion にちょうど 1 回分類されている', function (): void {
     $result = billingRetentionClassify(billingRetentionPopulation(), billingRetentionDeclarations());
 
@@ -264,33 +280,24 @@ function billingRetentionPurgerClassesOnDisk(): array
         'app/Services/Billing/Retention/ の purger 実装と registry の登録が一致しません '
         .'(登録漏れの purger は実行されず、期限超過が黙って残ります)。');
 
-    $registeredTargets = array_map(
-        static fn (string $class): string => (new $class)->target()->value,
-        $registry,
-    );
+    $registeredTargets = array_map(billingRetentionTargetOf(...), $registry);
     sort($registeredTargets);
 
     $expected = array_map(
         static fn (BillingRetentionTarget $case): string => $case->value,
-        array_filter(
-            BillingRetentionTarget::cases(),
-            static fn (BillingRetentionTarget $case): bool => ! $case->isPendingCarryForward(),
-        ),
+        BillingRetentionTarget::cases(),
     );
     sort($expected);
 
     expect($registeredTargets)->toBe($expected,
-        'purger を持つべき target と実装が一致しません (isPendingCarryForward() の target を除く)。');
+        'purger を持つべき target と実装が一致しません (全 target が purger を持つ)。');
 
     // 1 target につき purger は 1 本 (重複登録で二重実行しない)
     expect(count(array_unique($registeredTargets)))->toBe(count($registeredTargets));
 });
 
 test('検査 4b: 実行順が子 → 親である (SubscriptionItem → Subscription)', function (): void {
-    $order = array_map(
-        static fn (string $class): string => (new $class)->target()->value,
-        BillingRetentionPurgerRegistry::purgerClasses(),
-    );
+    $order = array_map(billingRetentionTargetOf(...), BillingRetentionPurgerRegistry::purgerClasses());
 
     $child = array_search(BillingRetentionTarget::SubscriptionItem->value, $order, true);
     $parent = array_search(BillingRetentionTarget::Subscription->value, $order, true);
diff --git a/tests/Architecture/TicketLedgerReaderInventoryTest.php b/tests/Architecture/TicketLedgerReaderInventoryTest.php
new file mode 100644
index 0000000..e758d3e
--- /dev/null
+++ b/tests/Architecture/TicketLedgerReaderInventoryTest.php
@@ -0,0 +1,343 @@
+<?php
+
+declare(strict_types=1);
+
+/*
+ * Architecture invariant: **チケット台帳 (`ticket_ledger_entries`) を読む場所は deny-by-default の目録制**。
+ *
+ * SoT = devnotes/20260809-0908-account-deletion-grace/detailed-design.md の PR-C2 (C2a)。
+ *
+ * ★なぜ要るか:
+ *   保持期間 (7 年) の決着は**畳み込み** — 期限超過の個別取引行を消し、
+ *   `(organization_id, source, expires_at)` ごとの**残高スナップショット 1 行**へ置き換える。
+ *   帰結として、7 年より古い**個別取引の情報は復元できなくなる** (それが保持期間の意味である)。
+ *   よって「台帳の個別行を読む場所」が宣言なしに増えると、ある日その画面 / 集計だけが
+ *   静かに壊れる (行が消えているのに例外は起きない = 気付けない壊れ方)。
+ *   目録は「増やすときに必ず読み方 (集計 / 個別行) を宣言させる」ための摩擦である。
+ *
+ * ★走査入口は 4 つ (詳細設計 C2a):
+ *   1. モデル参照 (`TicketLedgerEntry` の識別子)
+ *   2. table 名リテラル (`'ticket_ledger_entries'`)
+ *   3. relation 名 (`ticketLedgerEntries`)
+ *   4. 主要列名リテラル (`'delta'` / `'source'` / `'expires_at'`)
+ *
+ * ★この gate が保証するもの:
+ *   - 検査 1: 走査で検出したファイルが目録と **exact-fit** (未登録 = fail / 幽霊登録 = fail)
+ *   - 検査 2: 全 entry が読み方 (`aggregate` / `row_detail` / `other_table`) を宣言し、
+ *     根拠が 30 文字以上
+ *   - 検査 3: 空振り検知 (走査ファイル数 / 検出件数を**現在値ちょうど**で pin)
+ *   - 検査 4: 自己参照コントロール (コメント・docblock 内の言及は 0 件 = 説明文で偽赤にならない)
+ *   - 検査 5: 正のコントロール (4 入口それぞれが実際に点灯する = 検出器が死んでいない)
+ *   - 検査 6: 負のコントロール (未登録ファイルを混ぜると検査 1 が点灯する)
+ *
+ * ★この gate が保証しないもの (誇張しない):
+ *   - **目録が保証するのは「読んでいる場所を宣言なしに増やせない」ことだけ**である。
+ *     動的 relation (`$org->{$name}`) / 変数 table 名 (`DB::table($t)`) /
+ *     文字列を組み立てる raw SQL は**取りこぼす**。
+ *     **最終保証は畳み込みの挙動テスト側** (tests/Feature/Billing/TicketLedgerCarryForwardTest.php) である
+ *   - 宣言した読み方 (`aggregate` / `row_detail`) が**実際のコードと一致するか**は機械では見ない
+ *     (人間の申告である)。gate が強制できるのは「宣言があること」まで
+ *   - **列名リテラル (入口 4) の走査範囲は課金ディレクトリに限る**
+ *     (`app/Models/Billing` / `app/Services/Billing` / `app/Console/Commands/Billing` /
+ *      `app/Enums/Billing`)。`source` / `expires_at` は `ticket_reservations` /
+ *      `ticket_checkout_sessions` / `api_keys` / `organization_invitations` 等が**同名の列**を
+ *      持ち、app/ 全体を走査すると台帳と無関係な hit が大量に出て信号が死ぬ。
+ *      **課金ディレクトリの外**で台帳の列名だけを使う経路には**沈黙する**
+ *   - vendor / database/migrations / tests は母集団外 (migration は列定義そのものであり、
+ *     tests は台帳を読むのが仕事である)
+ */
+
+/** 台帳モデルの短縮名 (識別子として現れる形)。 */
+const TICKET_LEDGER_MODEL_IDENTIFIER = 'TicketLedgerEntry';
+
+/** 台帳の table 名。 */
+const TICKET_LEDGER_TABLE = 'ticket_ledger_entries';
+
+/** 台帳への relation 名。 */
+const TICKET_LEDGER_RELATION = 'ticketLedgerEntries';
+
+/** 台帳の主要列名 (入口 4)。 */
+const TICKET_LEDGER_COLUMNS = ['delta', 'source', 'expires_at'];
+
+/**
+ * 入口 4 (列名リテラル) の走査範囲。
+ *
+ * `source` / `expires_at` は他テーブルにも実在する一般名のため、app/ 全体では信号が死ぬ。
+ * 課金ディレクトリに限ることで「台帳の近所で列名だけ使う新規経路」を捕まえる。
+ */
+const TICKET_LEDGER_COLUMN_SCAN_DIRS = [
+    'Models/Billing',
+    'Services/Billing',
+    'Console/Commands/Billing',
+    'Enums/Billing',
+];
+
+/**
+ * 台帳を読む / 触る場所の目録 (app_path からの相対パス => [読み方, 根拠])。
+ *
+ * 読み方の語彙:
+ * - `aggregate`   … 集計 (SUM / COUNT / MAX) でしか読まない。畳み込みに影響されない
+ * - `row_detail`  … 個別取引行の属性に依存する。畳み込みで**情報が失われる**側
+ * - `other_table` … 台帳ではない同名列を持つ別テーブルの経路 (入口 4 の巻き添え)
+ *
+ * @var array<string, array{string, string}>
+ */
+const TICKET_LEDGER_READER_INVENTORY = [
+    'Models/Billing/TicketLedgerEntry.php' => [
+        'row_detail',
+        '台帳モデルそのもの。列定義と append-only guard (update/delete の例外化) を持つ',
+    ],
+    'Models/Organization.php' => [
+        'aggregate',
+        'relation 定義 (ticketLedgerEntries) のみ。行の中身は読まず件数・合算の入口を提供する',
+    ],
+    'Enums/Billing/BillingRetentionTarget.php' => [
+        'aggregate',
+        '保持期間の目録で台帳を target として宣言する。モデルクラスと起算列名の参照のみ',
+    ],
+    'Services/Billing/TicketLedgerService.php' => [
+        'aggregate',
+        '台帳の唯一の書き込み窓口。残高は source / expires_at 別の SUM で読み、個別取引行の識別子には依存しない',
+    ],
+    'Services/Billing/TicketLedgerCarryForwardService.php' => [
+        'row_detail',
+        '保持期間の畳み込み本体。期限超過の個別取引行を残高スナップショット 1 行へ置換する唯一の経路',
+    ],
+    'Services/Billing/Retention/TicketLedgerEntryPurger.php' => [
+        'aggregate',
+        '保持期間 purger の adapter。件数の集計と畳み込みサービスへの委譲だけを行う',
+    ],
+    'Models/Billing/TicketReservation.php' => [
+        'other_table',
+        'ticket_reservations の expires_at (予約 TTL) であり台帳の失効時刻ではない。入口 4 の巻き添え',
+    ],
+    'Models/Billing/TicketCheckoutSession.php' => [
+        'other_table',
+        'ticket_checkout_sessions の expires_at (Checkout Session の失効) であり台帳ではない',
+    ],
+    'Services/Billing/TicketCheckoutService.php' => [
+        'other_table',
+        'ticket_checkout_sessions の expires_at を扱う購入手続きの経路であり台帳は読まない',
+    ],
+];
+
+/** 読み方の語彙 (exact-fit)。 */
+const TICKET_LEDGER_READ_MODES = ['aggregate', 'row_detail', 'other_table'];
+
+/** 走査ファイル数の下限 (degenerate PASS 防止)。 */
+const TICKET_LEDGER_SCAN_FLOOR = 200;
+
+/**
+ * PHP ソースから台帳への参照入口を検出する。
+ *
+ * コメント / docblock は code token ではないので拾わない (説明文で偽赤にならない)。
+ * 文字列リテラルは table 名・relation 名・列名の照合に要るので**値だけ**見る
+ * (中身を PHP として解釈はしない)。
+ *
+ * @param  bool  $scanColumns  入口 4 (列名リテラル) を有効にするか
+ * @return list<string> 検出した入口のラベル (重複排除済み)
+ */
+function ticketLedgerReferenceEntries(string $source, bool $scanColumns): array
+{
+    /** @var list<PhpToken> $tokens */
+    $tokens = PhpToken::tokenize($source);
+
+    $found = [];
+    foreach ($tokens as $token) {
+        if ($token->is([T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML])) {
+            continue;
+        }
+
+        if ($token->is(T_STRING)) {
+            if ($token->text === TICKET_LEDGER_MODEL_IDENTIFIER) {
+                $found['model'] = 'model';
+            }
+            if ($token->text === TICKET_LEDGER_RELATION) {
+                $found['relation'] = 'relation';
+            }
+
+            continue;
+        }
+
+        if (! $token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE])) {
+            continue;
+        }
+
+        $value = trim($token->text, "'\"");
+        if ($value === TICKET_LEDGER_TABLE) {
+            $found['table'] = 'table';
+        }
+        if ($value === TICKET_LEDGER_RELATION) {
+            $found['relation'] = 'relation';
+        }
+        if ($scanColumns && in_array($value, TICKET_LEDGER_COLUMNS, true)) {
+            $found['column'] = 'column';
+        }
+    }
+
+    return array_values($found);
+}
+
+/**
+ * app/ 配下の PHP ファイル (app_path からの相対パス)。
+ *
+ * @return list<string>
+ */
+function ticketLedgerScanFiles(): array
+{
+    $base = app_path();
+    $files = [];
+    $iterator = new RecursiveIteratorIterator(
+        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
+    );
+    /** @var SplFileInfo $file */
+    foreach ($iterator as $file) {
+        if (! $file->isFile() || $file->getExtension() !== 'php') {
+            continue;
+        }
+        $files[] = str_replace($base.'/', '', $file->getPathname());
+    }
+
+    sort($files);
+
+    return $files;
+}
+
+/**
+ * 走査結果 (相対パス => 検出した入口ラベル)。
+ *
+ * @return array<string, list<string>>
+ */
+function ticketLedgerDetected(): array
+{
+    $detected = [];
+    foreach (ticketLedgerScanFiles() as $relative) {
+        $source = file_get_contents(app_path($relative));
+        if ($source === false) {
+            continue;
+        }
+
+        $scanColumns = false;
+        foreach (TICKET_LEDGER_COLUMN_SCAN_DIRS as $dir) {
+            if (str_starts_with($relative, $dir.'/')) {
+                $scanColumns = true;
+
+                break;
+            }
+        }
+
+        $entries = ticketLedgerReferenceEntries($source, $scanColumns);
+        if ($entries !== []) {
+            $detected[$relative] = $entries;
+        }
+    }
+
+    ksort($detected);
+
+    return $detected;
+}
+
+test('検査 1: 台帳を読む場所が目録と exact-fit である', function (): void {
+    $detected = array_keys(ticketLedgerDetected());
+    $declared = array_keys(TICKET_LEDGER_READER_INVENTORY);
+    sort($declared);
+
+    $missing = array_values(array_diff($detected, $declared));
+    $phantom = array_values(array_diff($declared, $detected));
+
+    expect($missing)->toBe([],
+        '台帳を読む場所が目録に登録されていません。読み方 (aggregate / row_detail / other_table) と '
+        .'30 文字以上の根拠を TICKET_LEDGER_READER_INVENTORY へ登録してください。'
+        .PHP_EOL.implode(PHP_EOL, $missing));
+
+    expect($phantom)->toBe([],
+        '目録にあるが実在しない / 台帳を参照しなくなったファイルです (残置を消してください): '
+        .implode(', ', $phantom));
+});
+
+test('検査 2: 全 entry が読み方を宣言し根拠が 30 文字以上である', function (): void {
+    $violations = [];
+    foreach (TICKET_LEDGER_READER_INVENTORY as $path => [$mode, $rationale]) {
+        if (! in_array($mode, TICKET_LEDGER_READ_MODES, true)) {
+            $violations[] = $path.': 未知の読み方 '.$mode;
+        }
+        if (mb_strlen($rationale) < 30) {
+            $violations[] = $path.': 根拠が 30 文字未満';
+        }
+    }
+
+    expect($violations)->toBe([], implode(PHP_EOL, $violations));
+});
+
+test('検査 3: 空振り検知 (走査ファイル数と検出件数を pin する)', function (): void {
+    expect(count(ticketLedgerScanFiles()))->toBeGreaterThan(TICKET_LEDGER_SCAN_FLOOR);
+    expect(ticketLedgerDetected())->toHaveCount(count(TICKET_LEDGER_READER_INVENTORY));
+    expect(TICKET_LEDGER_READER_INVENTORY)->not->toBeEmpty();
+
+    // 正の自己検証: 実ファイルで検出器が実際に点灯する (検出器が死んでいない)
+    $service = file_get_contents(app_path('Services/Billing/TicketLedgerService.php'));
+    expect($service)->toBeString();
+    expect(ticketLedgerReferenceEntries((string) $service, true))->toContain('model');
+});
+
+test('検査 4: 自己参照コントロール (コメント・docblock 内の言及は検出しない)', function (): void {
+    $fixture = <<<'PHP'
+        <?php
+        /**
+         * 残高の真実源は ledger (TicketLedgerEntry) である。
+         * table 名は ticket_ledger_entries、relation は ticketLedgerEntries。
+         */
+        final class Documented
+        {
+            // delta / source / expires_at の意味はここに書く
+            public function noop(): void {}
+        }
+        PHP;
+
+    expect(ticketLedgerReferenceEntries($fixture, true))->toBe([]);
+
+    // 実在の証拠: コメントでしか台帳に触れないファイルは目録に載らない
+    expect(TICKET_LEDGER_READER_INVENTORY)
+        ->not->toHaveKey('Services/Billing/AutoRechargeService.php');
+    expect(TICKET_LEDGER_READER_INVENTORY)
+        ->not->toHaveKey('Models/Billing/TicketAutoRecharge.php');
+});
+
+test('検査 5: 正のコントロール (4 入口それぞれが点灯する)', function (): void {
+    $model = <<<'PHP'
+        <?php
+        use App\Models\Billing\TicketLedgerEntry;
+        final class R { public function f(): void { TicketLedgerEntry::query()->get(); } }
+        PHP;
+    expect(ticketLedgerReferenceEntries($model, false))->toBe(['model']);
+
+    $table = <<<'PHP'
+        <?php
+        final class R { public function f(): void { DB::table('ticket_ledger_entries')->get(); } }
+        PHP;
+    expect(ticketLedgerReferenceEntries($table, false))->toBe(['table']);
+
+    $relation = <<<'PHP'
+        <?php
+        final class R { public function f($org): void { $org->ticketLedgerEntries()->count(); } }
+        PHP;
+    expect(ticketLedgerReferenceEntries($relation, false))->toBe(['relation']);
+
+    $column = <<<'PHP'
+        <?php
+        final class R { public function f($q): void { $q->sum('delta'); } }
+        PHP;
+    expect(ticketLedgerReferenceEntries($column, true))->toBe(['column']);
+
+    // 入口 4 は走査範囲を絞っている (無効時は点灯しない)
+    expect(ticketLedgerReferenceEntries($column, false))->toBe([]);
+});
+
+test('検査 6: 負のコントロール (未登録ファイルを混ぜると検査 1 が点灯する)', function (): void {
+    $detected = array_keys(ticketLedgerDetected());
+    $detected[] = 'Services/Billing/UndeclaredLedgerReader.php';
+    $declared = array_keys(TICKET_LEDGER_READER_INVENTORY);
+
+    expect(array_values(array_diff($detected, $declared)))
+        ->toBe(['Services/Billing/UndeclaredLedgerReader.php']);
+});
diff --git a/tests/Feature/Billing/BillingRetentionHorizonTest.php b/tests/Feature/Billing/BillingRetentionHorizonTest.php
index 478a19d..9b40700 100644
--- a/tests/Feature/Billing/BillingRetentionHorizonTest.php
+++ b/tests/Feature/Billing/BillingRetentionHorizonTest.php
@@ -25,19 +25,15 @@
  *   - **本番で日次処理が止まっていないこと**は保証しない (責務は Command の件数報告 +
  *     FAILURE 終了コード + scheduler 運用。C1 では日次登録すらしていない)
  *   - **目録の網羅性**は保証しない (BillingRetentionTarget は人間の申告である)
- *   - C1 時点では `ticket_ledger_entry` (append-only の畳み込み待ち) を対象から外している。
- *     C1 は規約に何も宣言せず日次も回さないため、この未了は利用者に見える不整合にならない。
- *     C2 で畳み込みを実装したら `isPendingCarryForward()` が false になり、本検査の
- *     母集団へ自動的に入る (除外を書き足す必要がない = 外し忘れが起きない)
+ *   - **決着の方式は target で違う** (削除 6 本 / 畳み込み 1 本) が、horizon は方式を問わず
+ *     「起算済み・期限超過が残っていないか」だけを見る。台帳の畳み込みが**残高を保存するか**は
+ *     本検査の担当ではない (tests/Feature/Billing/TicketLedgerCarryForwardTest.php が担う)
  */
 
-/** C1 時点で horizon の母集団に入る target。 */
+/** horizon の母集団に入る target (= 全 target。除外は無い)。 */
 function billingRetentionHorizonTargets(): array
 {
-    return array_values(array_filter(
-        BillingRetentionTarget::cases(),
-        static fn (BillingRetentionTarget $case): bool => ! $case->isPendingCarryForward(),
-    ));
+    return BillingRetentionTarget::cases();
 }
 
 /** @return list<BillingRetentionPurger> */
@@ -128,15 +124,20 @@ function billingRetentionPurgersInOrder(): array
     expect(Subscription::query()->count())->toBe(1);
 });
 
-test('C1 の母集団は畳み込み待ちの台帳を含まない (C2 で自動的に加わる)', function (): void {
+test('母集団は全 target を含む (畳み込みで決着する台帳も horizon の対象である)', function (): void {
     $targets = array_map(
         static fn (BillingRetentionPurger $purger): string => $purger->target()->value,
         billingRetentionPurgersInOrder(),
     );
 
-    expect($targets)->not->toContain(BillingRetentionTarget::TicketLedgerEntry->value);
-    expect(array_map(
+    expect($targets)->toContain(BillingRetentionTarget::TicketLedgerEntry->value);
+
+    $expected = array_map(
         static fn (BillingRetentionTarget $case): string => $case->value,
         billingRetentionHorizonTargets(),
-    ))->toBe($targets);
+    );
+    sort($expected);
+    sort($targets);
+
+    expect($targets)->toBe($expected);
 });
diff --git a/tests/Feature/Billing/BillingRetentionPurgeTest.php b/tests/Feature/Billing/BillingRetentionPurgeTest.php
index 26f2b4f..a9fc165 100644
--- a/tests/Feature/Billing/BillingRetentionPurgeTest.php
+++ b/tests/Feature/Billing/BillingRetentionPurgeTest.php
@@ -6,6 +6,7 @@
 use App\Models\Billing\StripeWebhookEvent;
 use App\Models\Billing\Subscription;
 use App\Models\Billing\TicketCheckoutSession;
+use App\Models\Billing\TicketLedgerEntry;
 use App\Services\Billing\Contracts\BillingRetentionPurger;
 use App\Services\Billing\Retention\BillingCheckoutSessionPurger;
 use App\Services\Billing\Retention\BillingRetentionPurgerRegistry;
@@ -183,9 +184,10 @@
 
     $this->artisan('billing:purge-retention-expired')
         ->expectsOutputToContain('[dry-run]')
-        ->expectsOutputToContain('stripe_webhook_event: expired=1 fail_closed=0')
-        ->expectsOutputToContain('ticket_checkout_session: expired=0 fail_closed=1')
-        ->expectsOutputToContain('subscription: expired=1 fail_closed=1')
+        ->expectsOutputToContain('stripe_webhook_event: expired=1 processed=0 fail_closed=0')
+        ->expectsOutputToContain('ticket_checkout_session: expired=0 processed=0 fail_closed=1')
+        ->expectsOutputToContain('subscription: expired=1 processed=0 fail_closed=1')
+        ->expectsOutputToContain('dry-run のため 1 行も変更していません')
         ->assertExitCode(0);
 
     expect(StripeWebhookEvent::query()->count())->toBe(1);
@@ -194,9 +196,9 @@
     expect(SubscriptionItem::query()->count())->toBe(1);
 });
 
-test('コマンドは purger 未実装の target (台帳の畳み込み) を出力で明示する', function (): void {
+test('コマンドは台帳 (畳み込みで決着する target) も集計対象に含める', function (): void {
     $this->artisan('billing:purge-retention-expired')
-        ->expectsOutputToContain('ticket_ledger_entry: purger 未実装')
+        ->expectsOutputToContain('ticket_ledger_entry: expired=0')
         ->assertExitCode(0);
 });
 
@@ -237,10 +239,36 @@
         ->assertExitCode(0);
 });
 
-test('コマンドは --apply オプションを持たない (規約が宣言していない削除を先に効かせない)', function (): void {
-    $definition = Artisan::all()['billing:purge-retention-expired']->getDefinition();
+test('コマンドは --apply で実際に決着させ、horizon の観測点を出力する', function (): void {
+    $threshold = BillingRetention::threshold();
+    BillingRetentionFixtures::createStarted(BillingRetentionTarget::StripeWebhookEvent, $threshold->subSecond());
+    BillingRetentionFixtures::expiredLedgerEntries($threshold->subSecond());
+
+    $this->artisan('billing:purge-retention-expired', ['--apply' => true])
+        ->expectsOutputToContain('[apply]')
+        ->expectsOutputToContain('stripe_webhook_event: expired=1 processed=1')
+        ->expectsOutputToContain('ticket_ledger_entry: expired=2 processed=2')
+        ->expectsOutputToContain('horizon: OK (期限超過 0 件)')
+        ->assertExitCode(0);
+
+    expect(StripeWebhookEvent::query()->count())->toBe(0);
+    // 台帳は消えるのではなく繰越行 1 行へ畳み込まれる (残高 10 - 4 = 6 が保存される)
+    expect(TicketLedgerEntry::query()->count())->toBe(1);
+    expect(TicketLedgerEntry::query()->sole()->delta)->toBe(6);
+});
 
-    expect($definition->hasOption('apply'))->toBeFalse();
+test('--apply でも決着できない記録が残れば horizon は NG と報告する (終了コードは成功)', function (): void {
+    $threshold = BillingRetention::threshold();
+    // 明細が残っている契約は消せない (fail-closed)。「安全に残した」も規約から見れば残存である。
+    // --target で親だけを回し、子が残ったまま = fail-closed の状態を作る
+    BillingRetentionFixtures::attachItem(BillingRetentionFixtures::endedSubscription($threshold->subSecond()));
+
+    $this->artisan('billing:purge-retention-expired', ['--apply' => true, '--target' => 'subscription'])
+        ->expectsOutputToContain('subscription: expired=1 processed=0 fail_closed=1')
+        ->expectsOutputToContain('horizon: NG (期限超過 1 件が残存')
+        ->assertExitCode(0);
+
+    expect(Subscription::query()->count())->toBe(1);
 });
 
 test('保持年数が 0 以下なら fail-fast する', function (): void {
diff --git a/tests/Feature/Billing/TicketLedgerCarryForwardTest.php b/tests/Feature/Billing/TicketLedgerCarryForwardTest.php
new file mode 100644
index 0000000..3dc2841
--- /dev/null
+++ b/tests/Feature/Billing/TicketLedgerCarryForwardTest.php
@@ -0,0 +1,440 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\TicketLedgerKind;
+use App\Enums\Billing\TicketSource;
+use App\Models\Billing\TicketLedgerEntry;
+use App\Models\Organization;
+use App\Services\Billing\TicketLedgerCarryForwardService;
+use App\Services\Billing\TicketLedgerService;
+use App\Support\Legal\BillingRetention;
+use Carbon\CarbonImmutable;
+
+/*
+ * 保持期間 (7 年) の台帳畳み込み (PR-C2 / C2b) の挙動。
+ *
+ * ★畳み込みは**会計上の残高を保存する操作**である。1 枚でも増減したら重大な不具合なので、
+ *   「畳み込み前後で 7 種の観測値が一致する」ことを本ファイルが機械固定する
+ *   (詳細設計 C2b の検証 1〜7)。
+ *
+ * ★繰越行は「取引記録」ではなく**現在残高のスナップショット**である。原取引の識別子
+ *   (説明 / stripe id / payment intent / 予約 id / 冪等キー) は 1 つも引き継がない
+ *   — 引き継ぐと「7 年より古い取引の情報が残る」ことになり保持期間の意味が消える。
+ */
+
+/**
+ * 台帳の残高粒度ごとの合計 (organization_id / source / expires_at)。
+ *
+ * **合計 0 の group は落とす**。畳み込みは残高に寄与しない行を作らないため、
+ * 「0 の group が消えること」は残高の変化ではない。
+ *
+ * @return array<string, int>
+ */
+function ledgerBalanceByGroup(): array
+{
+    $totals = [];
+    foreach (TicketLedgerEntry::query()->get() as $entry) {
+        $key = implode('|', [
+            $entry->organization_id,
+            $entry->source?->value ?? 'null',
+            $entry->expires_at?->toIso8601String() ?? 'null',
+        ]);
+        $totals[$key] = ($totals[$key] ?? 0) + $entry->delta;
+    }
+
+    ksort($totals);
+
+    return array_filter($totals, static fn (int $total): bool => $total !== 0);
+}
+
+/**
+ * 組織ごとの表示残高 + 与信残高。
+ *
+ * @return array<int, array{monthly: int, purchased: int, holds: int, available: int}>
+ */
+function ledgerBalancesByOrganization(): array
+{
+    $service = app(TicketLedgerService::class);
+    $out = [];
+    foreach (Organization::query()->orderBy('id')->get() as $organization) {
+        $balance = $service->balance($organization);
+        $id = $organization->getKey();
+        expect($id)->toBeInt();
+        $out[$id] = [
+            'monthly' => $balance->monthlyRemaining,
+            'purchased' => $balance->purchasedRemaining,
+            'holds' => $balance->activeReservations,
+            'available' => $service->availableTrueBalance($organization),
+        ];
+    }
+
+    return $out;
+}
+
+/**
+ * 3 組織ぶんの「7 年より古い取引 + 新しい取引」を並べる。
+ *
+ * @return array{Organization, Organization, Organization}
+ */
+function seedCarryForwardLedger(CarbonImmutable $threshold): array
+{
+    $old = $threshold->subYearNoOverflow();
+
+    // --- 組織 A: 失効済み monthly の付与 / 消費 + 無期限 purchased + legacy (source null)
+    [$a] = createOrganizationWithOwner('組織A');
+    $expiredMonthly = $threshold->subMonthsNoOverflow(6);
+    TicketLedgerEntry::factory()->forOrganization($a)->createdAt($old)
+        ->monthly($expiredMonthly)->delta(100)->create();
+    TicketLedgerEntry::factory()->forOrganization($a)->createdAt($old)
+        ->monthly($expiredMonthly)->consumed(40, $expiredMonthly)->create();
+    // **同じ source で失効時刻だけが違う group** を必ず 2 つ置く。
+    // これが無いと「group key から expires_at を落とす」変異が検出できない (実測済み)。
+    $otherExpiry = $threshold->subMonthsNoOverflow(3);
+    TicketLedgerEntry::factory()->forOrganization($a)->createdAt($old)
+        ->monthly($otherExpiry)->delta(70)->create();
+    TicketLedgerEntry::factory()->forOrganization($a)->createdAt($old)
+        ->purchased()->delta(50)->create();
+    TicketLedgerEntry::factory()->forOrganization($a)->createdAt($old)
+        ->legacy()->delta(10)->create();
+    // 新しい取引 (畳み込みの対象外)
+    TicketLedgerEntry::factory()->forOrganization($a)->createdAt(CarbonImmutable::now())
+        ->purchased()->delta(5)->create();
+
+    // --- 組織 B: 7 年より古いが**まだ失効していない** monthly (残高に効いている)
+    [$b] = createOrganizationWithOwner('組織B');
+    $liveExpiry = CarbonImmutable::now()->addYearNoOverflow();
+    TicketLedgerEntry::factory()->forOrganization($b)->createdAt($old)
+        ->monthly($liveExpiry)->delta(30)->create();
+    TicketLedgerEntry::factory()->forOrganization($b)->createdAt($old)
+        ->purchased()->delta(80)->create();
+    TicketLedgerEntry::factory()->forOrganization($b)->createdAt($old)
+        ->purchased()->consumed(20)->create();
+
+    // --- 組織 C: 新しい取引しか無い (畳み込みが 1 行も触らない対照)
+    [$c] = createOrganizationWithOwner('組織C');
+    TicketLedgerEntry::factory()->forOrganization($c)->createdAt(CarbonImmutable::now())
+        ->purchased()->delta(7)->create();
+
+    return [$a, $b, $c];
+}
+
+test('検証 1〜4・7: 畳み込み前後で残高が 1 枚も変わらない (組織 / source / 失効時刻の粒度)', function (): void {
+    $threshold = BillingRetention::threshold();
+    seedCarryForwardLedger($threshold);
+
+    $groupsBefore = ledgerBalanceByGroup();
+    $balancesBefore = ledgerBalancesByOrganization();
+    $rowsBefore = TicketLedgerEntry::query()->count();
+
+    $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    // 空振り検知: 実際に畳み込まれた (0 件で green になっていない)
+    expect($result->candidates)->toBeGreaterThan(0);
+    expect($result->processed)->toBe($result->candidates);
+    expect($result->unexpectedFailures)->toBe(0);
+    expect($result->expiredRemaining)->toBe(0);
+    expect($result->failClosed)->toBe(0);
+
+    expect(ledgerBalanceByGroup())->toBe($groupsBefore);
+    expect(ledgerBalancesByOrganization())->toBe($balancesBefore);
+
+    // 行数は必ず減る (畳み込みが実際に起きた証拠)
+    expect(TicketLedgerEntry::query()->count())->toBeLessThan($rowsBefore);
+});
+
+test('検証 5: 畳み込み後も消費の出所と失効境界の選択が変わらない', function (): void {
+    $threshold = BillingRetention::threshold();
+    [, $b] = seedCarryForwardLedger($threshold);
+
+    $service = app(TicketLedgerService::class);
+
+    // 畳み込み前の選択を観測する (monthly が生きているので monthly から消費する)
+    $before = $service->reserve($b, 1);
+    $beforeSource = $before->consume_source;
+    $beforeExpiry = $before->consume_expires_at?->toIso8601String();
+    $service->release($before);
+
+    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    $after = $service->reserve($b, 1);
+
+    expect($after->consume_source)->toBe($beforeSource);
+    expect($after->consume_expires_at?->toIso8601String())->toBe($beforeExpiry);
+    expect($beforeSource)->toBe(TicketSource::Monthly); // 空振り検知
+});
+
+test('繰越行は残高の粒度 3 つだけを引き継ぎ、取引追跡情報を 1 つも残さない', function (): void {
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    [$organization] = createOrganizationWithOwner();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->purchased()->delta(40)->idempotencyKey('purchase:cs_test_secret')
+        ->create(['description' => 'チケット購入 (checkout session: cs_test_secret)']);
+
+    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    $entries = TicketLedgerEntry::query()->get();
+    expect($entries)->toHaveCount(1);
+
+    $carry = $entries->firstOrFail();
+    expect($carry->kind)->toBe(TicketLedgerKind::CarryForward);
+    expect($carry->delta)->toBe(40);
+    expect($carry->source)->toBe(TicketSource::Purchased);
+    expect($carry->expires_at)->toBeNull();
+    expect($carry->carried_forward_through?->toDateTimeString())
+        ->toBe($threshold->toDateTimeString());
+
+    // 取引追跡情報は 1 つも残っていない (原取引が復元不能である)
+    expect($carry->reservation_id)->toBeNull();
+    expect($carry->granted_at)->toBeNull();
+    expect($carry->stripe_checkout_session_id)->toBeNull();
+    expect($carry->payment_intent_id)->toBeNull();
+    expect($carry->purchase_amount)->toBeNull();
+    expect($carry->stripe_invoice_id)->toBeNull();
+    expect($carry->description)->not->toContain('cs_test_secret');
+    expect($carry->idempotency_key)->not->toContain('cs_test_secret');
+    expect($carry->created_at->greaterThan($threshold))->toBeTrue();
+});
+
+test('group key は (organization_id, source, expires_at) の 3 つで、組織を跨いで合算しない', function (): void {
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    [$first] = createOrganizationWithOwner('第一組織');
+    [$second] = createOrganizationWithOwner('第二組織');
+
+    TicketLedgerEntry::factory()->forOrganization($first)->createdAt($old)->purchased()->delta(11)->create();
+    TicketLedgerEntry::factory()->forOrganization($second)->createdAt($old)->purchased()->delta(22)->create();
+
+    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    expect(TicketLedgerEntry::query()->count())->toBe(2);
+    expect((int) TicketLedgerEntry::query()->where('organization_id', $first->getKey())->sum('delta'))->toBe(11);
+    expect((int) TicketLedgerEntry::query()->where('organization_id', $second->getKey())->sum('delta'))->toBe(22);
+});
+
+test('source が null の legacy 行は独立した group として畳み込まれる (purchased へ寄せない)', function (): void {
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    [$organization] = createOrganizationWithOwner();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)->purchased()->delta(9)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)->legacy()->delta(4)->create();
+
+    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    $entries = TicketLedgerEntry::query()->orderBy('id')->get();
+    expect($entries)->toHaveCount(2);
+    expect($entries->firstWhere('source', TicketSource::Purchased)?->delta)->toBe(9);
+    expect($entries->first(fn (TicketLedgerEntry $e): bool => $e->source === null)?->delta)->toBe(4);
+});
+
+test('合計 0 の group は繰越行を作らない (残高に寄与しない行を増やさない)', function (): void {
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    [$organization] = createOrganizationWithOwner();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)->purchased()->delta(12)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)->purchased()->consumed(12)->create();
+
+    $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    expect($result->processed)->toBe(2);
+    expect(TicketLedgerEntry::query()->count())->toBe(0);
+});
+
+test('冪等キーは group と閾値で決まり、再実行で同じ値になる (null は明示トークン / 日時は UTC)', function (): void {
+    $through = CarbonImmutable::parse('2019-03-04 05:06:07', 'Asia/Tokyo');
+    $expiresAt = CarbonImmutable::parse('2018-12-31 15:00:00', 'UTC');
+
+    $withValues = TicketLedgerCarryForwardService::idempotencyKeyFor(42, TicketSource::Monthly, $expiresAt, $through);
+    $withNulls = TicketLedgerCarryForwardService::idempotencyKeyFor(42, null, null, $through);
+
+    expect($withValues)->toBe('carry_forward:42:monthly:2018-12-31T15:00:00Z:2019-03-03T20:06:07Z');
+    expect($withNulls)->toBe('carry_forward:42:null:null:2019-03-03T20:06:07Z');
+
+    // 再実行で同じ値になる (同一入力 → 同一キー)
+    expect(TicketLedgerCarryForwardService::idempotencyKeyFor(42, TicketSource::Monthly, $expiresAt, $through))
+        ->toBe($withValues);
+
+    // 既存の signup_grant 部分 UNIQUE index の述語 (LIKE 'signup_grant:%') と衝突しない
+    expect($withValues)->not->toStartWith('signup_grant:');
+});
+
+test('繰越行はさらに畳み込める (carried_forward_through が単調に進む)', function (): void {
+    $threshold = BillingRetention::threshold();
+    [$organization] = createOrganizationWithOwner();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)
+        ->createdAt($threshold->subYearsNoOverflow(2))->purchased()->delta(15)->create();
+
+    // 1 回目: 2 年前の閾値で畳み込む (繰越行の created_at はその時点)
+    $firstThreshold = $threshold->subYearNoOverflow();
+    app(TicketLedgerCarryForwardService::class)->carryForward($firstThreshold);
+
+    $first = TicketLedgerEntry::query()->sole();
+    expect($first->kind)->toBe(TicketLedgerKind::CarryForward);
+    $firstThrough = $first->carried_forward_through;
+    expect($firstThrough)->not->toBeNull();
+
+    // 繰越行を「古い行」に見せるため created_at だけを過去へずらす (append-only guard を迂回する
+    // Query Builder 直書き。fixture の都合であり本番経路には無い操作である)
+    DB::table('ticket_ledger_entries')
+        ->where('organization_id', $organization->getKey())
+        ->update(['created_at' => $threshold->subMonthNoOverflow()]);
+
+    TicketLedgerEntry::factory()->forOrganization($organization)
+        ->createdAt($threshold->subMonthsNoOverflow(2))->purchased()->delta(5)->create();
+
+    // 2 回目: 現在の閾値で再畳み込み
+    $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    expect($result->processed)->toBe(2);
+
+    $second = TicketLedgerEntry::query()->sole();
+    expect($second->delta)->toBe(20);
+    expect($second->carried_forward_through?->greaterThan($firstThrough))->toBeTrue();
+});
+
+test('畳み込み済み group に古い行が後から入ったら fail-closed (残高を失わない)', function (): void {
+    // 冪等キーは (group, 閾値) で決まるので、同じ閾値で 2 度目の繰越行は insert されない。
+    // そこで原取引だけ消すと**繰越行 1 行ぶんの残高が消える**ため、丸ごと巻き戻して報告する。
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    [$organization] = createOrganizationWithOwner();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->purchased()->delta(30)->create();
+
+    $service = app(TicketLedgerCarryForwardService::class);
+    $service->carryForward($threshold);
+    expect(TicketLedgerEntry::query()->sole()->delta)->toBe(30);
+
+    // 同じ group へ「閾値より古い」行が後から入る (取り込み遅延 / 手動投入)
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->purchased()->delta(7)->create();
+
+    $result = $service->carryForward($threshold);
+
+    expect($result->unexpectedFailures)->toBe(1);
+    expect($result->processed)->toBe(0);
+    expect($result->expiredRemaining)->toBe(1);
+    // 残高は 1 枚も失われていない (30 + 7)
+    expect((int) TicketLedgerEntry::query()->sum('delta'))->toBe(37);
+});
+
+test('閾値が過去へ戻っても carried_forward_through は後退しない (単調性)', function (): void {
+    // 保持年数を延ばす (7 年 → もっと長く) と閾値は過去へ動く。既に「ここまで畳み込んだ」と
+    // 記録した終端を、後から短い値で上書きすると**集約済みの範囲を過小申告する**ことになる。
+    [$organization] = createOrganizationWithOwner();
+    $now = CarbonImmutable::now();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)
+        ->createdAt($now->subYearsNoOverflow(12))->purchased()->delta(15)->create();
+
+    // 1 回目: 新しい方の閾値 (now - 5 年) で畳み込む
+    $laterThreshold = $now->subYearsNoOverflow(5);
+    app(TicketLedgerCarryForwardService::class)->carryForward($laterThreshold);
+    expect(TicketLedgerEntry::query()->sole()->carried_forward_through?->toDateTimeString())
+        ->toBe($laterThreshold->toDateTimeString());
+
+    // 繰越行を「古い行」に見せる (fixture の都合。append-only guard を迂回する直書き)
+    DB::table('ticket_ledger_entries')
+        ->where('organization_id', $organization->getKey())
+        ->update(['created_at' => $now->subYearsNoOverflow(10)]);
+
+    TicketLedgerEntry::factory()->forOrganization($organization)
+        ->createdAt($now->subYearsNoOverflow(11))->purchased()->delta(5)->create();
+
+    // 2 回目: **過去へ戻った**閾値 (now - 9 年) で再畳み込み
+    $earlierThreshold = $now->subYearsNoOverflow(9);
+    app(TicketLedgerCarryForwardService::class)->carryForward($earlierThreshold);
+
+    $carry = TicketLedgerEntry::query()->sole();
+    expect($carry->delta)->toBe(20);
+    expect($carry->carried_forward_through?->toDateTimeString())
+        ->toBe($laterThreshold->toDateTimeString()); // 後退していない
+});
+
+test('新しい取引 (閾値より後) は 1 行も畳み込まれない', function (): void {
+    $threshold = BillingRetention::threshold();
+    [$organization] = createOrganizationWithOwner();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)
+        ->createdAt($threshold->addSecond())->purchased()->delta(3)->create();
+
+    $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    expect($result->candidates)->toBe(0);
+    expect($result->processed)->toBe(0);
+    expect(TicketLedgerEntry::query()->count())->toBe(1);
+    expect(TicketLedgerEntry::query()->sole()->kind)->toBe(TicketLedgerKind::Grant);
+});
+
+test('境界: created_at が閾値ちょうどの行は畳み込まれる (<= で判定する)', function (): void {
+    $threshold = BillingRetention::threshold();
+    [$organization] = createOrganizationWithOwner();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)
+        ->createdAt($threshold)->purchased()->delta(3)->create();
+
+    $service = app(TicketLedgerCarryForwardService::class);
+    expect($service->countExpired($threshold))->toBe(1);
+
+    $service->carryForward($threshold);
+
+    expect(TicketLedgerEntry::query()->sole()->kind)->toBe(TicketLedgerKind::CarryForward);
+});
+
+test('検証 6: 畳み込み後も signup grant の org 生涯 1 回は marker が守る', function (): void {
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    [$organization] = createOrganizationWithOwner();
+    $organization->forceFill(['signup_tickets_granted_at' => $old])->save();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->monthly($threshold->subMonthsNoOverflow(3))->delta(20)
+        ->idempotencyKey('signup_grant:org:'.$organization->getKey())->create();
+
+    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    // 畳み込みで signup_grant 行 (= 部分 UNIQUE index が守っていた行) は消える。
+    // 「org 生涯 1 回」の**正本は organizations.signup_tickets_granted_at の条件付き UPDATE** であり、
+    // それは畳み込みの対象ではないので残る (index は保険であって正本ではない)。
+    expect(TicketLedgerEntry::query()->where('idempotency_key', 'like', 'signup_grant:%')->count())->toBe(0);
+    expect($organization->fresh()?->signup_tickets_granted_at)->not->toBeNull();
+});
+
+test('[既知窓] 合計 0 の未失効 monthly group は畳み込みで失効境界の情報を失う', function (): void {
+    // 7 年より古い付与と消費が相殺し、かつ失効時刻が**まだ未来**という組み合わせでのみ起きる。
+    // 残高は変わらない (0 のまま) が、消費境界の探索 (nearestMonthlyExpiry) が見る
+    // 「delta>0 の未失効 monthly 行」が消えるため、次の予約の consume_expires_at が変わる。
+    // 残高保存を優先し、この窓は受容する (詳細設計 C2b「合計 0 の繰越行を作らない」)。
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    $liveExpiry = CarbonImmutable::now()->addYearNoOverflow();
+    [$organization] = createOrganizationWithOwner();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->monthly($liveExpiry)->delta(25)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->monthly($liveExpiry)->consumed(25, $liveExpiry)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->purchased()->delta(10)->create();
+
+    $service = app(TicketLedgerService::class);
+    $balanceBefore = $service->availableTrueBalance($organization);
+
+    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    // 残高は保存される (これが最優先の不変条件)
+    expect($service->availableTrueBalance($organization))->toBe($balanceBefore);
+
+    // 一方で「未失効 monthly の失効境界」は消えている (既知窓)
+    expect(TicketLedgerEntry::query()
+        ->where('source', TicketSource::Monthly)
+        ->where('delta', '>', 0)
+        ->whereNotNull('expires_at')
+        ->count())->toBe(0);
+});
diff --git a/tests/Support/Billing/BillingRetentionFixtures.php b/tests/Support/Billing/BillingRetentionFixtures.php
index 33016fe..d197227 100644
--- a/tests/Support/Billing/BillingRetentionFixtures.php
+++ b/tests/Support/Billing/BillingRetentionFixtures.php
@@ -10,6 +10,8 @@
 use App\Models\Billing\Subscription;
 use App\Models\Billing\TicketAutoRechargeAttempt;
 use App\Models\Billing\TicketCheckoutSession;
+use App\Models\Billing\TicketLedgerEntry;
+use App\Models\Organization;
 use Carbon\CarbonImmutable;
 use Illuminate\Support\Str;
 use InvalidArgumentException;
@@ -87,7 +89,25 @@ public static function attachItem(Subscription $subscription): SubscriptionItem
         return $item;
     }
 
-    /** 全 target ぶんの「期限超過だが消せる」記録を作る (horizon 検査の母集団)。 */
+    /**
+     * 期限超過の台帳行 (畳み込みで決着する target) を 1 組織ぶん作る。
+     *
+     * 付与と消費を 1 組ずつ置き、畳み込みが**合算して残高を保存する**ことを
+     * horizon 側でも通す (残高保存そのものの検証は TicketLedgerCarryForwardTest)。
+     */
+    public static function expiredLedgerEntries(CarbonImmutable $clock): Organization
+    {
+        [$organization] = \createOrganizationWithOwner('台帳保持期間テスト組織');
+
+        TicketLedgerEntry::factory()->forOrganization($organization)
+            ->createdAt($clock)->purchased()->delta(10)->create();
+        TicketLedgerEntry::factory()->forOrganization($organization)
+            ->createdAt($clock)->purchased()->consumed(4)->create();
+
+        return $organization;
+    }
+
+    /** 全 target ぶんの「期限超過だが決着できる」記録を作る (horizon 検査の母集団)。 */
     public static function seedExpiredRows(CarbonImmutable $threshold): void
     {
         self::createStarted(BillingRetentionTarget::StripeWebhookEvent, $threshold->subDay());
@@ -96,5 +116,7 @@ public static function seedExpiredRows(CarbonImmutable $threshold): void
         self::createStarted(BillingRetentionTarget::TicketAutoRechargeAttempt, $threshold->subDay());
 
         self::attachItem(self::endedSubscription($threshold->subDay()));
+
+        self::expiredLedgerEntries($threshold->subDay());
     }
 }

```

## mutation 実測記録 (設計の予測と実測のずれを含む)

# T144 (PR-C2) mutation 実測記録

> 手順: 1 変異ずつ適用 → 対象テストが**赤いこと**を実測 → 変異を戻す → 全体が緑に戻ることを確認。
> 詳細設計 §「共通: mutation で赤化を確認する手順」の M12 / M13 / M13b に加え、
> 本 PR 固有の観測点 (`--apply` / horizon / 目録 gate / 冪等キー衝突) を足した。

## 実測サマリ

| # | 変異 (実施後は戻した) | 赤くなったテスト | 結果 |
|---|---|---|---|
| MU1 (設計 M13b) | 畳み込みの group query から `where('organization_id', …)` を外す | `TicketLedgerCarryForwardTest`「検証 1〜4・7 残高が 1 枚も変わらない」/「group key は 3 つで組織を跨いで合算しない」 | **赤 2 本** |
| MU2 (設計 M13) | group query から `source` 条件を外す | 同「検証 1〜4・7」/「source が null の legacy 行は独立した group」 | **赤 2 本** |
| MU3 | group query から `expires_at` 条件を外す | **初回は緑のまま (検出できず)** → fixture 修正後に「検証 1〜4・7」が赤 | **下記参照** |
| MU4 | 繰越行に `stripe_checkout_session_id` を引き継がせる | 「繰越行は残高の粒度 3 つだけを引き継ぎ、取引追跡情報を 1 つも残さない」 | **赤 1 本** |
| MU5 (設計 M12 の裏) | registry から `TicketLedgerEntryPurger` を外す | `BillingRetentionHorizonTest`「母集団は全 target を含む」/ `BillingRetentionTargetInventoryTest`「検査 4 exact-fit」「検査 5 空振り検知」 | **赤 3 本** |
| MU6 | `app/Services/Billing/` に未登録の台帳 reader を 1 ファイル置く | `TicketLedgerReaderInventoryTest`「検査 1 exact-fit」「検査 3 空振り検知」「検査 6 負のコントロール」 | **赤 3 本** |
| MU7 | `--apply` を常に無視して dry-run にする | `BillingRetentionPurgeTest`「コマンドは --apply で実際に決着させ、horizon の観測点を出力する」 | **赤 1 本** |
| MU8 | `carried_forward_through` の単調性 (`max(閾値, 前回値)`) を外し常に閾値を返す | **初回は緑のまま (検出できず)** → テスト追加後に「閾値が過去へ戻っても後退しない」が赤 | **下記参照** |
| MU9 | horizon 行を常に `OK` にする | 「--apply でも決着できない記録が残れば horizon は NG」 | **赤 1 本** |
| MU10 | 繰越行 insert の `$inserted !== 1` fail-closed を外す | 「畳み込み済み group に古い行が後から入ったら fail-closed」 | **赤 1 本** |

変異を戻したあと `TicketLedgerCarryForwardTest` 14 本 / `BillingRetentionPurgeTest` 30 本 /
`BillingRetentionHorizonTest` / 両 Architecture gate はすべて緑に戻ることを実測した。

---

## 設計の予測と実測がずれた点 (辻褄を合わせず記録する)

### ずれ 1: MU3 (group key から `expires_at` を落とす) が初回は**検出できなかった**

**予測**: 詳細設計 C2b の「検証 3 有効期限別残高」が赤くなるはず。

**実測**: 緑のまま通った。原因は**テスト fixture の不足**で、設計側の欠陥ではない。
初版の fixture は「同じ `source` の中で `expires_at` が 1 種類しかない」組織しか作っておらず、
group key から `expires_at` を落としても**分割のされ方が変わらなかった**。

**対処**: `seedCarryForwardLedger()` に「同じ `source` (monthly) で失効時刻だけが違う group」を
2 つ置いた (`$expiredMonthly` と `$otherExpiry`)。これで MU3 は「検証 1〜4・7」で赤くなる。

> 教訓: 「group key の要素を落とす」変異は、**その要素が実際に 2 値以上ある fixture** でしか
> 検出できない。group key を持つ検査には値の分散を fixture 要件として書く必要がある。

### ずれ 2: MU8 (`carried_forward_through` の単調性) が初回は**検出できなかった**

**予測**: 「繰越行はさらに畳み込める (単調に進む)」テストが赤くなるはず。

**実測**: 緑のまま通った。既存テストは「1 回目より 2 回目の閾値が新しい」順でしか回しておらず、
`max(閾値, 前回値)` の `前回値` 側の枝を 1 度も踏んでいなかった (閾値が単調増加する限り
`max` は常に閾値を返すため、単調性は自動的に成立してしまう)。

**対処**: 「保持年数を延ばして**閾値が過去へ動いた**」ケースのテストを追加した
(`閾値が過去へ戻っても carried_forward_through は後退しない`)。これで MU8 が赤くなる。

### ずれ 3: 冪等キーの第 4 要素は「集約終端」ではなく「その実行の閾値」にした

**設計の記述**: `carry_forward:{orgId}:{source}:{expiresAt}:{through(UTC)}`。

**実測で判明した不整合**: 上のずれ 2 の対処 (閾値が過去へ戻る再畳み込み) を実装すると、
`through = max(閾値, 前回値)` は**前回と同じ値**になる。キーが `through` 由来だと
**前回の繰越行とキーが衝突**し、insertOrIgnore が 0 を返して fail-closed に落ちる
(= その group は二度と畳み込めない)。

**対処**: キーの第 4 要素を**その実行の閾値**にした。冪等の単位は「同じ入力で同じ実行をしたか」
なので、入力である閾値で決めるのが正しい。`carried_forward_through` (列) は集約終端として
単調性を別に保つ。両者は普段一致し、保持年数を延ばしたときだけ食い違う。
形 (`carry_forward:{orgId}:{source}:{expiresAt}:{時刻}`)・null の明示トークン・UTC 正規化は
設計どおりで、テストが固定している。

### ずれ 4: 繰越行の `description` を null にできない

**設計の記述**: 「取引追跡列はすべて null: `description` / `reservation_id` /
`stripe_checkout_session_id` / `payment_intent_id` / `purchase_amount` / `granted_at`」。

**実測**: `ticket_ledger_entries.description` は **NOT NULL** である
(`2026_06_11_091400_create_ticket_tables.php` を実読)。設計の「実カラムを migration 実読で
確認済み」という記述と食い違う。

**対処**: 列を nullable へ変えるのではなく、**取引追跡情報を一切含まない固定文言**
(`保持期間の繰越 (残高スナップショット)`) を入れた。原取引の説明は残らないため
「個別取引が復元不能」という要件は満たす。テストは「繰越行の description / idempotency_key に
原取引の識別子が含まれない」ことを固定している。

### ずれ 5: horizon の「purger 書き忘れ」を捕まえるのは horizon の postcondition ではない

**設計の記述** (C1d): 「target を 1 つ足して purger を書き忘れたときに赤くなるのは horizon 側だけ」。

**実測**: MU5 (registry から purger を外す) で `BillingRetentionHorizonTest` の
**postcondition テストは緑のまま**だった。postcondition は「登録済み purger を回した結果」しか
見ないため、登録から消えた target は母集団からも消える (自己充足してしまう)。
赤くなったのは同ファイルの「母集団は全 target を含む」検査と、
`BillingRetentionTargetInventoryTest` の exact-fit / 空振り検知だった。

**対処**: 実装は変えず、事実を記録する。C1 が置いた「registry の target 集合 == enum の
target 集合」検査 (本 PR で `isPendingCarryForward()` 除外を撤去して全 target 一致にした) が
実質的な検出点であり、機能としては塞がっている。設計の説明文だけが実装と一致していない。

---

## 変異を戻したことの確認

```
git status --short   # 変異の残骸が無いことを確認
vendor/bin/pest tests/Feature/Billing/TicketLedgerCarryForwardTest.php   # 14 passed
```


## テスト結果

- `composer phpstan` (level 10): **OK (No errors)**
- `vendor/bin/pint` (composer fix): passed
- `tests/Feature/Billing/TicketLedgerCarryForwardTest.php`: 14 passed
- `tests/Feature/Billing/` 全体 + 両 Architecture gate: 780 passed / 0 failed
- `composer test` (全レーン): 実行中 (完了後に報告する)

## 特に見てほしい点

1. 畳み込みの group query (`groupQuery()`) が `TicketLedgerService::sumBalance()` の集計条件と
   本当に対応しているか (組織 / source (purchased は `source IS NULL` を含む) / expires_at)。
   **繰越で残高が動く経路が残っていないか**
2. 組織 1 件 = 1 トランザクション + `organizations` 行ロックという境界で、
   `reserve` / `commit` / `grantMonthly` (ロックを取らない insert 経路) と競合しても壊れないか
3. 冪等キーを `carried_forward_through` ではなく **その実行の閾値**にした判断 (mutation 記録のずれ 3)
4. `TicketLedgerReaderInventoryTest` の入口 4 (列名リテラル) を課金ディレクトリに限定した判断が
   「保証しないもの」として正しく書けているか

