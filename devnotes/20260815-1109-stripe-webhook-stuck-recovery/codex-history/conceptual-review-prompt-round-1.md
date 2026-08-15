【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

【関連するセキュリティ不変条件 (AGENTS.md §セキュリティ不変条件)】

- **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
- **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない
- **cross-org 不可**: クラス起点の主キー同一性クエリは deny-by-default で目録分類が要る
  (`ModelDirectFetchInvariantTest` + `DirectFetchInventory`)

【関連するドメイン規約 (AGENTS.md §ドメイン固有規約 6)】

**ジョブの重複実行と結果の一回性**: 入口の排他は best-effort であり保証を担わない。
結果の一回性は永続状態遷移 (条件付き UPDATE / 悲観ロック + status guard / 予約 CAS) と
外部側の冪等キーが担う。取り消せない外部副作用の直前には所有権の再検証を置く。

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 補足: 現行コードの要点 (レビューの前提)

対象リポジトリは `/workspace` (aicue)。以下は実読で確認済みの事実である。

### `app/Services/Billing/StripeWebhookProcessor.php` の現行 `handle()` / `claim()`

```php
public function handle(WebhookReceived $event): void
{
    $payload = $event->payload;
    $eventId = $this->stringAt($payload, 'id');
    $type = $this->stringAt($payload, 'type');
    if ($eventId === null || $type === null) {
        return;
    }

    $record = $this->claim($eventId, $type, $payload);
    if ($record === null) {
        return; // 同一 event_id を処理済み (冪等 skip)
    }

    try {
        $this->process($type, $payload);
    } catch (Throwable $exception) {
        $record->status = WebhookEventStatus::Failed;
        $record->failure_reason = $exception->getMessage();
        $record->save();
        report($exception);

        throw $exception;
    }

    $record->status = WebhookEventStatus::Processed;
    $record->failure_reason = null;
    $record->processed_at = CarbonImmutable::now();
    $record->save();
}

private function claim(string $eventId, string $type, array $payload): ?StripeWebhookEvent
{
    return DB::transaction(function () use ($eventId, $type, $payload): ?StripeWebhookEvent {
        $existing = StripeWebhookEvent::query()
            ->where('event_id', $eventId)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            if ($existing->status !== WebhookEventStatus::Failed) {
                return null;
            }
            if ($existing->attempts >= self::MAX_PROCESSING_ATTEMPTS) {
                Log::warning('stripe webhook: terminal failure, acking to stop Stripe retries', [...]);
                if (in_array($type, [CheckoutSessionCompleted, InvoicePaid], true)) {
                    report(new RuntimeException("stripe webhook terminal failure (grant イベント): ..."));
                }
                return null;
            }
            $existing->status = WebhookEventStatus::Received;
            $existing->attempts += 1;
            $existing->save();

            return $existing;
        }

        $record = new StripeWebhookEvent;
        $record->event_id = $eventId;
        $record->type = $type;
        $record->status = WebhookEventStatus::Received;
        $record->payload = $payload;
        $record->save();

        return $record;
    });
}
```

### 誤りが残っているコメント (`MAX_PROCESSING_ATTEMPTS` の docblock)

```
 * webhook 処理失敗の再送上限。attempts (failed→received 復帰回数) がこれに到達したら
 * terminal とみなし処理せず 200 ack して Stripe の自動再送を打ち切る。
 * claim() が transaction + lockForUpdate で状態遷移を直列化するため
 * "processing 残留 stale" は生じず、復帰 sweep は不要。
 * Stripe の自動再送窓 (~3 日) に対し 8 回で十分。
```

### 既存の滞留回収の作法 (`RenderJobService::recoverStale`)

```php
public function recoverStale(): int
{
    $staleIds = RenderJob::query()->where(/* status と経過時間 */)->pluck('id');

    $recovered = 0;
    foreach ($staleIds as $id) {
        $job = RenderJob::query()->whereKey($id)->first();
        if ($job === null) { continue; }
        // failJob 内で行ロック + terminal guard 再検証するため、競合したジョブはそこで no-op
        if ($this->failJob($job, RenderErrorCode::Timeout, '...')) { $recovered++; }
    }

    return $recovered;
}
```
`routes/console.php` で `Artisan::command(...)` + `Schedule::command(...)->everyFiveMinutes()` として配線されている。
同型の実装が `TicketLedgerService::releaseStale` / `StaleUploadReservationSweeper::sweep` にもある。

### 付与の冪等キー (再実行安全性の根拠)

- 月次付与: `monthly:{invoiceId}` UNIQUE
- スポット購入付与: `purchase:{sessionId}` UNIQUE
- 自動購入付与: `recharge:{invoiceId}` UNIQUE
- 返金逆仕訳: `refund:{PI}:{target}` UNIQUE (「複数回の部分返金・順序逆転・再送に対して冪等」と実装コメントにある)
- 初回無償付与: `signup_grant:{subId}` UNIQUE + `organizations.signup_tickets_granted_at` marker

### 順序に依存する側の根拠 (`SubscriptionService::applySubscriptionSnapshot`)

```php
$sub->forceFill([
    'stripe_status' => $snap->status,
    'stripe_price' => $snap->basePriceId,
    'quantity' => $snap->baseQuantity,
    'trial_ends_at' => $snap->trialEndsAt,
    'ends_at' => $snap->endsAt,
    // current_period_end は payload に値があるときだけ上書き
])->save();
// active 系 status かつ既知 Price なら $org->plan_code を上書き
```
イベントの新旧を判定する列も条件も持たない (後勝ちで上書きする)。

### 参考: 同一機能の他リポジトリ実装 (機能台帳 lctl `stripe-webhook-idempotency`)

- **laravel-claude-template**: 状態は 受信 / 処理済み / 失敗 / **回収待ち** の 4 値。失敗と回収待ちからだけ再受理する。
  クラッシュ残留は滞留回収の 1 経路が回収待ちへ落として再発火させる。回収してよい種類は
  「再実行の安全性」の 2 値分類 (`WebhookReplaySafety`: SafeToReplay / OrderSensitive) が決める。
  台帳には「SafeToReplay の意味は『再実行しても追加の被害を生まない』に限定され『再実行すれば復旧する』ではない」と明記されている
- **aigenba**: 受理は衝突時無視の INSERT + 「試行回数 < 8 かつ (受信 / 5 分超の処理中 / 失敗)」に対する 1 本の原子的 UPDATE (行ロックなし)。
  取り残された処理中の行は 5 分ごとの掃除コマンドが受信へ戻す
- **aicue (本リポジトリ)**: 3 値のまま。台帳の観測に「回収待ちの状態も回収経路も持たない。クラッシュ残留の穴が残っている」と記録されている

---

## 概念設計

（以下、devnotes/20260815-1109-stripe-webhook-stuck-recovery/conceptual-design.md の全文）
# 概念設計: stripe-webhook-stuck-recovery

## 背景・課題

### 現状の webhook 冪等マシン (aicue)

`StripeWebhookProcessor::handle()` は次の順で動く。

1. `claim()` — `DB::transaction` + `lockForUpdate` で `stripe_webhook_events` 行を取得し、
   状態を `received` にして返す (新規なら INSERT、`failed` なら `attempts+1` して `received` へ戻す)
2. **トランザクションの外**で `process()` を呼ぶ (本処理)
3. 成功なら `processed`、例外なら `failed` + `failure_reason` を記録して再 throw

状態は `received` / `processed` / `failed` の 3 値しかない。

### 穴: 本処理中にプロセスが落ちた行が二度と処理されない

手順 2 の最中に PHP プロセスが落ちる (OOM kill / デプロイ時の worker 停止 / fatal error) と、
行は `received` のまま残る。このとき:

- HTTP 応答が返らないので **Stripe は再送する** (ここまでは正常)
- しかし再送を受けた `claim()` は `$existing->status !== Failed` で **`null` を返す**
  (`received` = 「他プロセスが処理中」とみなす設計)
- `handle()` は何もせず return し、Cashier が **200 を返す**
- Stripe は「配信成功」と判断して**再送を打ち切る**

結果、そのイベントは**永久に未処理**のまま残る。しかも 200 を返しているので Stripe 側にも
失敗として残らず、`stripe_webhook_events.failure_reason` も NULL なので運用調査の手掛かりも無い。
**完全に無音で失われる**。

失われるものは金銭に直結する:

| イベント | 失われるもの |
|---------|------------|
| `checkout.session.completed` (ticket_purchase) | 決済済みチケットの付与 (顧客は払ったのに残高が増えない) |
| `invoice.paid` (subscription_cycle) | 月次チケット付与 |
| `invoice.paid` (auto_recharge) | 自動購入分の付与 + attempt の paid 確定 |
| `customer.subscription.created` | 初回無償チケット付与 + `plan_code` 同期 |

### 実装内の説明が事実と食い違っている

`StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS` の docblock はこう書いている。

> claim() が transaction + lockForUpdate で状態遷移を直列化するため
> "processing 残留 stale" は生じず、復帰 sweep は不要。

これは誤りである。`claim()` が直列化するのは**状態遷移そのもの**であって、
**遷移のあとに走る本処理**ではない。本処理はトランザクションの外にあるため、
そこで落ちれば `received` が残る。

同一機能の台帳 (lctl feature `stripe-webhook-idempotency`) では、2026-08-04 の精査がこの主張を
明確に否定しており、テンプレート (laravel-claude-template) は同じ位置に
「回収しないと月次付与が無音で失われる」と真逆の説明を置いている。
テンプレートは既に回収経路 (`WebhookEventStatus::RecoveryPending` +
`StaleWebhookClaimStream` + 再実行安全性の 2 値分類) を実装済みで、aicue にだけ穴が残っている。

## 改善アイデア

**「本処理中に落ちて `received` のまま滞留した行」を検出して再処理へ戻す経路を足す。**
既に aicue にある滞留回収 (`RenderJobService::recoverStale` /
`TicketLedgerService::releaseStale` / `StaleUploadReservationSweeper::sweep`) と**同じ作法**で作る。

3 点で構成する。

### (1) 「回収待ち」の状態を足す

`WebhookEventStatus` に `RecoveryPending`(`recovery_pending`、表示名「回収待ち」) を足す。
これは「滞留と判定して回収対象に落とした」ことを表す中間状態で、
`claim()` は `failed` と同じく **`recovery_pending` からの再 claim を受理する**。

`received` からの再 claim は**受理しない** (現行どおり `null`)。
= `claim()` の直列化契約は一切変えない。滞留判定は claim の外 (回収 cron) が持つ。

### (2) 滞留回収の cron を足す

`billing:recover-stale-webhook-events` (5 分ごと)。

- `status=received` かつ `updated_at` が閾値 (既定 15 分) より古い行を列挙する
- 1 行ずつ `lockForUpdate` で取り直して状態と滞留を**再検証**してから `recovery_pending` へ落とす
  (競合したら no-op)
- 落とした行を、保存済み `payload` から**再処理**する
  (`claim()` が `recovery_pending` を受理 → `attempts+1` → `received` → `process()`)

`attempts` を消費するので、回収は既存の上限 8 に**そのまま乗る** (無限回収ループにならない)。
上限到達時は現行どおり処理せず終局し、付与系イベントなら `report()` が飛ぶ。

### (3) 再実行してよい種類を型で分類する

保存済み payload の**再実行**は、イベントの種類によって安全性が違う。

- **再実行しても追加の被害を生まない** (`SafeToReplay`):
  `invoice.paid` / `checkout.session.completed` / `charge.refunded` / `invoice.payment_failed`。
  付与はすべて台帳の `idempotency_key` UNIQUE (`monthly:` / `purchase:` / `recharge:` /
  `refund:`) で冪等、checkout 行の遷移は `Completed` が終局で no-op になる
- **順序に依存する** (`OrderSensitive`):
  `customer.subscription.created` / `updated` / `deleted`。
  `SubscriptionService::applySubscriptionSnapshot` は**後勝ちで上書きする** (順序判定を持たない)
  ため、古い payload を後から流すと `plan_code` / `current_period_end` /
  `stripe_status` が**巻き戻る**

`HandledStripeWebhookEvent::replaySafety()` (網羅 `match`) を単一出典にし、
`OrderSensitive` は**自動再実行しない** (`recovery_pending` へ落として `report()` で運用へ渡すだけ)。

> **語の意味を広げないこと**: `SafeToReplay` は「再実行しても追加の被害を生まない」であって
> 「再実行すれば必ず復旧する」ではない。復旧するかどうかは各ハンドラの事情による。

### (4) 誤った説明コメントを実態に合わせる

`MAX_PROCESSING_ATTEMPTS` の docblock から「復帰 sweep は不要」を削り、
回収経路の存在と役割分担 (claim は状態遷移の直列化まで / 滞留判定は回収 cron) を書く。

## 期待効果

- **使命への貢献**: 決済済みなのにチケットが増えない事故が構造的に消える。
  AI-CUE のチケットは撮影・レンダの実行権そのものなので、付与の取りこぼしは
  「現場作業者がマニュアル動画を作れない」に直結する
- **無音の解消**: 失われても気付けなかった状態から、`recovery_pending` の残存件数と
  `report()` という 2 つの観測点を持つ状態になる
- **家系との収束**: テンプレートが既に持つ形 (回収待ち状態 + 再実行安全性の 2 値分類) に寄せる。
  2026-08-04 の裁定が決めた合成先の方向と一致し、将来のテンプレート追従の差分が小さくなる

## 実装方針（概要）

| 変更対象 | 内容 |
|---------|------|
| `app/Enums/Billing/WebhookEventStatus.php` | `RecoveryPending` を追加 |
| `app/Enums/Billing/WebhookReplaySafety.php` (新規) | `SafeToReplay` / `OrderSensitive` の 2 値 |
| `app/Enums/Billing/HandledStripeWebhookEvent.php` | `replaySafety()` (網羅 `match`) を追加 |
| `app/Services/Billing/StripeWebhookProcessor.php` | `claim()` に `recovery_pending` arm / `recoverStale()` 追加 / 本処理を `handle()` と回収で共有 / 誤コメント修正 |
| `config/billing.php` | 滞留判定の閾値 (`webhook_stale_after_minutes`) |
| `routes/console.php` | `billing:recover-stale-webhook-events` + 5 分スケジュール |
| `database/factories/Billing/StripeWebhookEventFactory.php` | `recoveryPending()` state |
| `tests/Support/Security/DirectFetchInventory.php` | 回収 cron の主キー再取得を目録登録 |
| `docs/architecture.md` | 回収経路・監視対象・運用手順の追記 |

DB migration は**不要** (`status` は `string` 列。既存 default `'received'` も変えない)。

## 制約・前提

- **`claim()` の直列化契約を壊さない**: `received` からの再 claim は引き続き受理しない
- **上限 8 (`MAX_PROCESSING_ATTEMPTS`) を壊さない**: 回収も `attempts` を消費する
- **終局で 200 を壊さない**: 上限到達時は現行どおり処理せず例外も投げない
- **既存の滞留回収と同じ作法**: 「id を列挙 → 1 行ずつ行ロックで取り直して再検証 → 件数を返す」
  (`RenderJobService::recoverStale` / `TicketLedgerService::releaseStale` と同型)。
  **別層 (共通の回収基盤) は作らない** — aicue には共通基盤が無く、
  ドメインごとの個別実装が既定の作法だから (`docs/architecture.md` の方針)
- **保持期限 purge との関係**: `StripeWebhookEventPurger` は `processed_at IS NULL` の行を
  「異常として計上するだけで消さない」ため、回収待ちの行が purge で消えることはない

## スコープ外

- **`customer.subscription.*` の自動再実行**。順序判定の列 (イベント生成時刻) を足して
  後勝ちを止める硬化 (テンプレートの T097 段 1 相当) は本 TODO では作らない。
  ここで必要なのは「付与が無音で失われないこと」であり、契約状態は後続の
  `customer.subscription.updated` で追随する。滞留した行は `report()` で運用に渡す
- **`failed` 行の自動リトライ cron**。`failed` は Stripe の再送で再処理される既存経路がある。
  回収 cron の対象は `received` 滞留のみ
- **webhook 受信の非同期化** (受信即 200 + キュー処理への作り替え)
- **他リポジトリ (テンプレート等) への還流・収束作業**
