【アプリの使命 (North Star)】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
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

# 役割

あなたは aicue (Laravel 12 + Svelte 5) の設計レビュアである。

# 確認ラウンド Round 7 (最終)

前ラウンド (Round 6) であなたは、Round 5 の [Critical] 1 件 / [Warning] 2 件 / [Suggestion] 1 件を
**すべて解消**と判定し、そのうえで対応が持ち込んだ**新規 [Critical] 1 件**を挙げた:

> [Critical] `preflight 2: attempt が canceled のとき既作成 invoice を終端する` suppression テストに、
> 決定論的な注入点がありません。attach 成功後、pay preflight 直前に attempt を `canceled` へ変える
> シームが無いため、`terminateInvoiceAfterOwnershipLost()` の canceled 分岐を Feature test で
> 固定できません。最小対応は、test fake preflight 側で `StripeInvoicePay` チェック直前に
> DB status を `canceled` へ変えてから本物の `stillPending()` 相当を通す、などです。

これに対応した。**本ラウンドで判定するのはこの 1 件だけ**である。

判定のルール:

1. 上記 [Critical] が **解消 / 未解消** かを明示せよ。未解消なら何が足りないかを具体的に書け。
2. 全体判定を **`APPROVED` または `CHANGES_REQUESTED`** で返せ。
3. **新しい観点の粗探しをするな**。今回の対応そのものが壊したもの (新規 [Critical]) があれば挙げてよいが、
   承認条件でない改善案は `[Suggestion]` と明記せよ。設計を不必要に複雑にする提案はするな
   (思考原則 2「今必要なものだけ作る」)。

出力形式:

```
## 新規 [Critical] (Round 6) の解消判定
解消 / 未解消
（理由）

## 全体判定
APPROVED / CHANGES_REQUESTED
```

---

# 対応マトリクス (Claude 側の判断)

# 対応マトリクス: design-review Round 6

Round 6 は**確認ラウンド**。Round 5 の [Critical] 1 件 / [Warning] 2 件 / [Suggestion] 1 件は
**すべて解消**と判定された。ただし対応が持ち込んだ新規 [Critical] が 1 件出たため、
これに対応して Round 7 (上限) を回す。

## Round 5 指摘の判定結果 (Codex)

| 指摘 | 判定 |
|---|---|
| S4 [Critical] Stripe preflight の配置を赤化できるテストシームが無い | 解消 |
| S4 [Warning] 新設メソッドの列挙 | 解消 |
| S7 [Warning] Stripe の「配置を保証する」主張 | 解消 |
| S6 [Suggestion] 期待集合の重複検査 | 解消 |

## [Critical] (新規) `preflight 2 の canceled 分岐 (既作成 invoice の終端)` に決定論的な注入点が無い

- 判断: **対応する (設計変更)**
- 根拠: 妥当。自分で追っても同じ結論になる:
  - `duringCreateInvoice` は `createAutoRechargeInvoice()` の内側 = **attach より前**に発火するため、
    再現できるのは `attach 0 行` の競合だけ。
  - 一方 `FakeAttemptOwnershipPreflight` が「DB を触らず false を返すだけ」の fake だと、
    行は Pending のままなので `terminateInvoiceAfterOwnershipLost()` は `Canceled` 限定の
    early return に落ち、**canceled 分岐 (invoice を終端する) が 1 度も実行されない**。
  - 実際 Round 6 直前の設計はこの矛盾を「placement テストでは `terminated === []` を期待する」
    と書いて回避しており、canceled 分岐の behavioral 固定が抜けていた。
- 対応内容: Codex 提案どおり、fake の責務を「**判定の差し替え**」から
  「**競合の注入**」へ変える (本番コードは 1 行も変えない):
  - `FakeAttemptOwnershipPreflight` を
    `$terminalizeAt: list<ExternalCallKind>` / `$terminalStatus: AutoRechargeAttemptStatus` /
    `$calls: list<string>` を持つシームにし、
    **該当 checkpoint に到達したら attempt 行を条件付き UPDATE で terminal 化してから
    `parent::stillPending()` へ委譲する**。
  - これで判定・`refresh()`・所有権喪失ログは**常に本番実装が実行**される
    (fake が verdict を騙らないので、テストが実装から乖離しない)。
  - 得られる決定論的インターリーブ:
    - `terminalizeAt=[StripeInvoiceCreate]` → 冒頭 guard 通過 → preflight 1 直前に canceled →
      **invoice を作らない**。preflight 1 を削除すると行が Pending のままなので invoice が
      作成され赤化する (**M16**)。
    - `terminalizeAt=[StripeInvoicePay]` → create → **attach 1 行** → preflight 2 直前に canceled →
      pay を抑止し、**`terminateInvoice` が 1 回呼ばれる**。preflight 2 を削除すると
      terminal 化自体が起きず pay が走って赤化する (**M17**)。
      = Round 5 が要求した 2 つのインターリーブと、新規 Critical の canceled 分岐が
      **同じ 1 本のシーム**で成立する。
    - `terminalStatus` を `Failed` / `Paid` に切り替えれば、後始末の非終端分岐
      (二重終端の抑止 / void 不可) も同じシームで固定できる。
  - 抑止セクションにあった `preflight 2: paid のとき` / `failed のとき` の 2 ケースは
    配置セクションへ**移動**した (同じことを 2 通りの再現手段で書かない。
    AGENTS.md 思考原則 2 / 3)。
  - `FakeAutoRechargeGateway::$duringCreateInvoice` は**残す** — こちらは
    「create 成功と attach の間」という別の競合点 (attach 0 行) の担当で、
    preflight シームでは再現できない。

## 反論・見送り

- なし (今ラウンドで反論した指摘は無い)。

---

# 対応後の詳細設計 (S4 の該当箇所)

## 【1】S4 変更箇所 / 波及変更 (テスト支援クラスの定義が変わった)

### 変更箇所

- `app/Services/Billing/AutoRechargeService.php`
  - **コンストラクタ**: `AttemptOwnershipPreflight $preflight` を追加 (Round 5 レビュー反映)
  - `executeAttemptLocked()` (L528-570): Stripe 2 呼び出しの直前へ preflight を挿入し、
    `stripe_invoice_id` の永続化を**条件付き UPDATE** 化する
  - `terminateInvoiceAfterOwnershipLost()` / `terminateUnattachedInvoice()` /
    `terminateInvoiceBestEffort()` を private メソッドとして新設
- **新規** `app/Support/JobExecution/AttemptOwnershipPreflight.php` —
  Billing の preflight を**注入可能な collaborator**として切り出す (Round 5 レビュー反映)
  - `tryTerminateInvoice()` (L669) / `terminateAndCancel()` / `terminateAndFail()` /
    `recordSuccessfulCharge()` / `handleChargeFailure()`: **いずれも変更しない**

### 波及変更

- TypeScript 型定義: **なし**
- API Resource/DTO: **なし**
- 外部ゲートウェイ interface (`AutoRechargeGatewayInterface`): **変更しない**
  - Codex Round 3 要件 (i)「終端操作にも attempt に固定した idempotency key を使う」は
    **反論する**: `terminateInvoice()` は Stripe から invoice を `retrieve` して
    `void`/`deleted` → 成功扱い、`404` → 成功扱い、`paid` → `Assert` で明示的な非成功、
    `draft` → delete、`open`/`uncollectible` → void と**状態検査で冪等化**されている
    (`CashierAutoRechargeGateway::terminateInvoice()` L145-180)。
    idempotency key は「24 時間以内の再送」しか重複排除しないのに対し、状態検査は
    期限がなく**より強い**。既存の冪等化を捨てて key へ寄せる理由がない。
  - 要件 (ii)「void 対象の invoice ID が当該 attempt に保存された値と一致する」は
    `tryTerminateInvoice($attempt)` が `$attempt->stripe_invoice_id` のみを読むため
    **構造的に満たされる** (`stillPending()` が直前に `refresh()` 済み)。
  - 要件 (iii)「already void / paid の分類」は上記のとおり既存実装が満たす。
- テストファイル: `tests/Feature/Billing/AutoRechargeServiceTest.php` へ**追記**
  (`Tests\Support\FakeAutoRechargeGateway` を `app()->instance()` で注入する既存作法)
- テスト支援クラス (1): **新規** `tests/Support/FakeAttemptOwnershipPreflight.php` —
  `AttemptOwnershipPreflight` を継承した**競合注入シーム**。
  `public array $terminalizeAt = []` (この checkpoint に到達したら attempt 行を terminal 化する) /
  `public AutoRechargeAttemptStatus $terminalStatus = Canceled` /
  `public array $calls = []` (到達した checkpoint の記録) を持ち、`app()->instance()` で差し込む。
  **判定そのものは fake しない — 行を terminal 化してから `parent::stillPending()` へ委譲する**
  (Round 6 レビュー反映)。fake が作るのは「checkpoint の直前に停止側が terminal 化した」という
  **窓だけ**であり、`refresh()` / status 判定 / 所有権喪失ログは本番実装が実行する。
  これにより配置 (M16 / M17) と抑止後の後始末 (canceled → invoice 終端) を
  **同じ 1 本のテストで**決定論的に固定できる。詳細は下記テスト計画
- テスト支援クラス (2): `tests/Support/FakeAutoRechargeGateway.php` に
  **`public ?Closure $duringCreateInvoice = null;` フックを追加**する
  (`FakeRenderComposer::$duringCompose` と同じ作法。既存プロパティ
  `$createdInvoices` / `$payCalls` / `$terminated` / `$failOnTerminate` は変更しない)。
  `attach 0 行` の競合点を決定論的に再現するために必要
- **台帳側の保証 (変更しない。明記のみ)**: `TicketLedgerService::grantAutoRecharge()` は
  `insertIdempotent($organization, "recharge:{$stripeInvoiceId}", …)` を使う。
  冪等キーは **invoice 単位で UNIQUE** であり、同じ invoice への付与は何度呼んでも 1 件しか入らない。
  したがって `recordSuccessfulCharge()` が「grant → attempt の条件付き UPDATE」の順であることは
  **矛盾ではなく、冪等キーが 2 本ある**ことを意味する (Round 1 レビューへの反論):
  - 付与の一回性 … 台帳の `recharge:{invoiceId}` UNIQUE (**invoice 単位**)
  - attempt 遷移の一回性 … `where('status','pending')->update()` (**attempt 単位**)

  順序を入れ替える (attempt 遷移が 1 行のときだけ grant する) と、canceled 化と実課金が

## 【2】S4 変更後コード — executeAttemptLocked と preflight collaborator と後始末 3 メソッド (本番コードは Round 6 から 1 行も変えていない。docblock のみ追記)

        $this->handleChargeFailure(/* … */);
    }
```

### 変更後コード

```php
    private function executeAttemptLocked(Organization $organization, TicketAutoRechargeAttempt $attempt): void
    {
        // lock 取得後に fresh 再読込 (停止側のキャンセルが先行していたら no-op)。
        $attempt->refresh();
        if ($attempt->status !== AutoRechargeAttemptStatus::Pending) {
            return;
        }

        // 停止後課金の禁止: lock 内で enabled を確認 (以降 disable は本実行の完了まで割り込めない)。
        if (! $this->isEnabledFor($organization)) {
            $this->terminateAndCancel($attempt);

            return;
        }

        $keyBase = $this->idempotencyKeyBase($attempt);

        $invoiceId = $attempt->stripe_invoice_id;
        if ($invoiceId === null) {
            // ★ preflight 1: invoice 作成の直前。org lock は TTL 180 秒で切れうるため
            //   (lock は best-effort。保証は本再検証と条件付き UPDATE と Stripe 冪等キー)。
            if (! $this->preflight->stillPending($attempt, ExternalCallKind::StripeInvoiceCreate)) {
                return; // invoice 未作成なので収束は自明 (残す open invoice が無い)
            }

            $invoiceId = $this->gateway->createAutoRechargeInvoice(
                $organization,
                $attempt->stripe_price_id,
                $attempt->quantity,
                $this->metadataFor($organization, $attempt),
                $keyBase,
            );

            // invoice_id の永続化は pay より必ず前 (プロセス死でも迷子 invoice を作らない)。
            // ★ **条件付き UPDATE** にする (Round 1 レビュー反映): 素の save() だと
            //   停止側が先に canceled 化した terminal 行へ invoice_id を後から書き込むことになり、
            //   状態機械の例外を作る。0 行なら「attempt へ紐付けられなかった invoice」であり、
            //   DB の値に依存せずローカルの $invoiceId で終端する。
            $attached = TicketAutoRechargeAttempt::query()
                ->whereKey($attempt->id)
                ->where('status', AutoRechargeAttemptStatus::Pending->value)
                ->update([
                    'stripe_invoice_id' => $invoiceId,
                    'updated_at' => CarbonImmutable::now(),
                ]);

            if ($attached !== 1) {
                // ★ attach 失敗は **status を問わず**終端する (Round 2 レビュー反映)。
                //   この invoice ID を知っているのは自分だけであり、
                //   terminal 化させた側は stripe_invoice_id === null を見ているため終端できない。
                $this->terminateUnattachedInvoice($attempt->refresh(), $invoiceId);

                return;
            }
            $attempt->forceFill(['stripe_invoice_id' => $invoiceId])->syncOriginal(); // in-memory 同期 (再 save しない)
        }

        // ★ preflight 2: pay の直前。**直前に自前の書き込み (invoice_id の永続化) を挟んだため
        //   必ずもう一度検証する** (裁定 AG-082: 検証の後に自前の書き込みを挟むと、
        //   接続断で旧担当が送信できる窓が開く)。
        //   既存 invoice を再利用する経路 (上の if を通らない場合) でもここが唯一の関門になる。
        if (! $this->preflight->stillPending($attempt, ExternalCallKind::StripeInvoicePay)) {
            $this->terminateInvoiceAfterOwnershipLost($attempt, $invoiceId);

            return;
        }

        $result = $this->gateway->payOffSessionInvoice($invoiceId, $keyBase);

        // …以降は現行のまま
    }

```

**注入可能な preflight collaborator** — `app/Support/JobExecution/AttemptOwnershipPreflight.php` (新規):

```php
<?php

declare(strict_types=1);

namespace App\Support\JobExecution;

use App\Enums\Billing\AutoRechargeAttemptStatus;
use App\Enums\Security\ExternalCallKind;
use App\Models\Billing\TicketAutoRechargeAttempt;
use Illuminate\Support\Facades\Log;

/**
 * auto-recharge attempt の所有権再検証 (preflight suppression。裁定 AG-082 標準形 (2))。
 *
 * ★ **なぜ独立クラスなのか** (Round 5 レビュー反映):
 *   Manual の 2 パイプラインは「外部呼び出しが複数回連続する」構造なので、
 *   既存 fake のフック (`ThrowingPromptFake::$onAttempt` / `FakeRenderComposer::$duringCompose`)
 *   で **preflight の配置そのものを behavioral に赤化できる**。
 *   一方 Billing は「冒頭の Pending guard → create → attach → pay」という直列で、
 *   guard と各 preflight の**間に注入点が 1 つも無い**ため、
 *   preflight 呼び出しを削除しても既存 fake では赤化しない。
 *   そこで preflight だけを差し替え可能な collaborator として切り出す
 *   (本番コードにテスト専用 closure を足さないため)。
 *   Manual 側は同じ理由が無いので**この形にしない** (利益の無い churn を作らない。
 *   AGENTS.md 思考原則 2)。
 *
 * ★ **非 final** にしてあるのは fake が override するためである
 *   (`App\Services\Render\RenderObjectStorage` と同じ作法。interface は新設しない)。
 *   ただし fake は**判定を差し替えない** — checkpoint 直前に attempt 行を terminal 化して
 *   `parent::stillPending()` へ委譲するだけの「競合注入シーム」である (Round 6 レビュー反映)。
 *   したがって本メソッドの refresh / status 判定 / ログはテストでも常に本実装が走る。
 */
class AttemptOwnershipPreflight
{
    /**
     * Stripe 呼び出しの直前に attempt の所有権 (= pending) を再検証する。
     *
     * @return bool 送信してよいか (false = 所有権喪失 → 呼び出し側が中断する)
     */
    public function stillPending(TicketAutoRechargeAttempt $attempt, ExternalCallKind $call): bool
    {
        $attempt->refresh();
        if ($attempt->status === AutoRechargeAttemptStatus::Pending) {
            return true; // アーリーリターン (正常系)
        }

        // Manual ドメインと**同じ必須キー集合**で観測する (集計の語彙を 1 本に保つ。
        // JobOwnershipLostException::logContext() と必須 7 キーが一致する。
        // Billing 固有の追加キーは PII-free な attempt_ulid の 1 本だけ)。
        Log::warning('auto-recharge: 所有権を失ったため Stripe 呼び出しを中止しました', [
            'event' => ExternalCallKind::LOG_EVENT,
            'job_type' => TicketAutoRechargeAttempt::class,
            'job_id' => $attempt->id,
            'expected_status' => AutoRechargeAttemptStatus::Pending->value,
            'actual_status' => $attempt->status->value,
            'stage' => 'execute_attempt',
            'external_call' => $call->value,
            'attempt_ulid' => $attempt->attempt_ulid,
        ]);

        return false;
    }
}
```

`AutoRechargeService` に戻る (後始末の 3 メソッドはサービス側に置く):

```php
    /**
     * preflight 2 で中断したときの invoice 後始末。
     *
     * **canceled のときだけ**終端する:
     *  - paid  … void できない (付与経路の管轄)
     *  - failed… `terminateAndFail()` が **`stripe_invoice_id` を DB 経由で見えている状態**で
     *    終端済み (attach 済みだからこの分岐に来ている)
     *  - canceled … 停止側の `tryTerminateInvoice()` は `stripe_invoice_id === null` を
     *    「invoice 未作成」と解釈して素通りするため、こちらの永続化が停止より後だと
     *    **誰も void しない open invoice が残る**。ここで拾う。
     *
     * ★ attach に失敗した invoice は本メソッドではなく `terminateUnattachedInvoice()` の担当
     *   (あちらは status を問わず終端する。Round 2 レビュー反映)。
     */
    private function terminateInvoiceAfterOwnershipLost(
        TicketAutoRechargeAttempt $attempt,
        string $invoiceId,
    ): void {
        if ($attempt->status !== AutoRechargeAttemptStatus::Canceled) {
            return; // アーリーリターン
        }

        $this->terminateInvoiceBestEffort($attempt, $invoiceId);
    }

    /**
     * attempt 行へ紐付けられなかった (条件付き UPDATE が 0 行だった) invoice の後始末。
     *
     * ★ **status を問わず終端を試みる** (Round 2 レビュー反映)。
     *   この invoice ID を知っているのは自分だけであり、terminal 化させた側は
     *   `stripe_invoice_id === null` を見ているため終端できない。
     *   canceled 限定にすると failed 経路で**誰も終端しない open invoice**が残る。
     * ★ `paid` の可能性は `CashierAutoRechargeGateway::terminateInvoice()` の状態検査が
     *   `Assert` で fail-closed に分類する (例外 → `terminated=false` としてログに残る)。
     */
    private function terminateUnattachedInvoice(
        TicketAutoRechargeAttempt $attempt,
        string $invoiceId,
    ): void {
        $this->terminateInvoiceBestEffort($attempt, $invoiceId);
    }

    /**
     * invoice の best-effort 終端 + 固定 event 名でのログ (上 2 つの共通部)。
     *
     * ★ `$invoiceId` を**引数で受ける** (Round 1 レビュー反映)。
     *   attempt 行に永続化できなかった invoice も終端したいため、DB の値に依存しない。
     * ★ `tryTerminateInvoice($attempt)` を再利用しない理由: あちらは
     *   `$attempt->stripe_invoice_id` を読むため「永続化できなかった invoice」を扱えず、
     *   かつ独自の warning を出すのでログが二重になる。ここは固定 event の 1 行に閉じる。
     * ★ `CashierAutoRechargeGateway::terminateInvoice()` は Stripe から retrieve して
     *   void/deleted/404 → 成功扱い、paid → `Assert` で明示的な非成功、draft → delete、
     *   open/uncollectible → void と**状態検査で冪等化**されている
     *   (idempotency key より強い — 期限が無い)。
     * ★ 失敗しても**課金処理へは進まない** (呼び出し側が無条件に return する)。
     *   残った open invoice は reconcile の母集団外なので、運用契約 (docs/architecture.md) の
     *   手動収束に委ねる。
     * ★ **cleanup 専用の event 名**を使う (Round 2 レビュー反映)。
     *   送信抑止の記録 (`LOG_EVENT`) は最小 7 キー schema を持つ契約であり、
     *   キー集合の違うログを同じ event 名に混ぜない。
     */
    private function terminateInvoiceBestEffort(
        TicketAutoRechargeAttempt $attempt,
        string $invoiceId,
    ): void {
        $terminated = true;
        $error = null;
        try {
            $this->gateway->terminateInvoice($invoiceId);
        } catch (Throwable $exception) {
            $terminated = false;
            $error = $exception->getMessage(); // paid 等の「明示的な非成功」もここに落ちる
        }

        Log::warning('auto-recharge: 所有権喪失後の invoice 終端', [
            'event' => ExternalCallKind::CLEANUP_LOG_EVENT,
            'job_type' => TicketAutoRechargeAttempt::class,
            'job_id' => $attempt->id,
            'attempt_ulid' => $attempt->attempt_ulid,
            'invoice_id' => $invoiceId,
            'terminated' => $terminated,
            'error' => $error,

## 【3】S4 テスト計画 (今回の変更の本体)

### テスト計画

`tests/Feature/Billing/AutoRechargeServiceTest.php` へ **追記** (既存 test は改変しない)。
テストデータは Factory (`TicketAutoRechargeAttempt::factory()` /
`TicketAutoRecharge::factory()` / `createOrganizationWithOwner()`)。

**配置 (placement) と抑止 (suppression) の同時固定** — `FakeAttemptOwnershipPreflight` を
差し込んで行う (Round 5 / Round 6 レビュー反映。これらが M16 / M17 を赤化する)。

シームの動作 (`tests/Support/FakeAttemptOwnershipPreflight`):

```php
final class FakeAttemptOwnershipPreflight extends AttemptOwnershipPreflight
{
    /** @var list<ExternalCallKind> この checkpoint に到達したら attempt 行を terminal 化する */
    public array $terminalizeAt = [];

    /** terminal 化させる先 (canceled / failed / paid を切り替えて後始末の分岐を固定する) */
    public AutoRechargeAttemptStatus $terminalStatus = AutoRechargeAttemptStatus::Canceled;

    /** @var list<string> 到達した checkpoint の記録 (= 配置の観測) */
    public array $calls = [];

    public function stillPending(TicketAutoRechargeAttempt $attempt, ExternalCallKind $call): bool
    {
        $this->calls[] = $call->value;

        if (in_array($call, $this->terminalizeAt, true)) {
            // ★ 「checkpoint の**直前**に停止側 / 他ワーカーが terminal 化した」窓を作る。
            //   これが本設計で唯一足りていなかった注入点である (Round 6 レビュー反映)。
            TicketAutoRechargeAttempt::query()
                ->whereKey($attempt->id)
                ->where('status', AutoRechargeAttemptStatus::Pending->value)
                ->update([
                    'status' => $this->terminalStatus->value,
                    'updated_at' => CarbonImmutable::now(),
                ]);
        }

        // ★ **判定は fake しない**。refresh / status 判定 / 所有権喪失ログは本番実装が実行する。
        //   fake の責務は「窓を開けること」だけである。
        return parent::stillPending($attempt, $call);
    }
}
```

- [ ] `配置: create の直前に preflight がある (terminalizeAt=[StripeInvoiceCreate] で invoice を作らない)`
  - 期待: `$gateway->createdInvoices === []` かつ `$gateway->payCalls === []` かつ
    `$gateway->terminated === []` (未作成なので終端対象が無い)
  - 期待: `$fakePreflight->calls === ['stripe_invoice_create']` (pay checkpoint へ到達しない)
  - **M16**: create 直前の `stillPending()` 呼び出しを削除すると、行が Pending のままなので
    invoice が作成され attach も成功し、`createdInvoices` が 1 件になって**赤化する**
- [ ] `配置: pay の直前に preflight がある (terminalizeAt=[StripeInvoicePay] で pay しない)`
  - 順序: `preflight 1 は Pending で通過 → create → attach 1 行 → preflight 2 の直前に canceled 化 → 抑止`
  - 期待: `$gateway->createdInvoices` は 1 件・`$gateway->payCalls === []`
  - 期待: **`terminateInvoice` が作成された invoice id で 1 回呼ばれる**
    (`terminateInvoiceAfterOwnershipLost()` の `Canceled` 分岐。要件 ii)
  - 期待: `attempt->stripe_invoice_id` は attach 済み (DB に残る)
  - **M17**: pay 直前の `stillPending()` 呼び出しを削除すると terminal 化が起きず pay が走って**赤化する**
- [ ] `後始末: terminalStatus=Failed のとき terminateInvoice を呼ばない` (二重終端の抑止)
  - `terminateAndFail()` が既に終端済みという前提に立つ分岐 (`Canceled` 限定) の固定
- [ ] `後始末: terminalStatus=Paid のとき terminateInvoice を呼ばない` (void 不可の分類)
- [ ] `配置: 行が Pending のままなら (terminalizeAt=[]) create → pay が従来どおり進む` (回帰)
  - 期待: `$fakePreflight->calls === ['stripe_invoice_create', 'stripe_invoice_pay']`
    (= 2 つの checkpoint を**両方**通る)

**抑止 (suppression) の固定** — 実 `AttemptOwnershipPreflight` を使い、実行前の状態で再現する:

- [ ] `preflight 1: invoice 作成前に attempt が canceled → createAutoRechargeInvoice を呼ばない`
  - gateway fake の呼び出し記録が空であること
  - ※これは冒頭の Pending guard でも止まるため、**配置の保証は上の M16 が担う**
    (本ケースは「所有権喪失ログが出る」ことの確認が主目的)
- [ ] `attach 0 行: invoice 作成成功と同時に canceled 化 → invoice_id を書かず invoice を終端する`
  - **Round 1/2 レビュー反映**。競合点を正確に再現するため、
    `tests/Support/FakeAutoRechargeGateway` に `public ?Closure $duringCreateInvoice = null;`
    を追加し (`FakeRenderComposer::$duringCompose` と同じ作法)、
    **invoice ID を返す直前**に attempt を terminal 化させる。
    再現する順序: `preflight 1 成功 → Stripe 作成成功 → 並行 terminal 化 → attach 0 行 → 終端`
  - 期待: `stripe_invoice_id` が **DB 上 null のまま**
  - 期待: `terminateInvoice` が作成された invoice id で 1 回呼ばれる
    (= DB に保存済みであることに依存しない終端)
  - 期待: `payOffSessionInvoice` は 0 回
- [ ] `attach 0 行: failed へ遷移していた場合も invoice を終端する`
  - **Round 2 レビュー反映**。`terminateUnattachedInvoice()` は **status を問わない**。
    canceled 限定にすると、invoice ID を知らない failed 経路で
    **誰も終端しない open invoice** が残るため
- ※ `preflight 2` の後始末分岐 (paid / failed で `terminateInvoice` を呼ばない) は
  上の**配置セクション**へ移した (`terminalStatus` を切り替えて同じシームで固定する。
  Round 6 レビュー反映で注入点が 1 つに揃ったため、2 通りの再現手段を持たない)
- [ ] `preflight 2: terminateInvoice が例外を投げても課金処理へ進まない` (要件 v)
  - fake の `terminateInvoice` を throw させ、`payOffSessionInvoice` が 0 回であること
  - 台帳エントリが 1 件も増えていないこと
- [ ] `前提: terminateInvoice が失敗したら attempt は Pending のまま (Failed へ遷移しない)`
  - **Round 3 レビュー反映**。`terminateInvoiceAfterOwnershipLost()` が `Canceled` 限定でよいのは
    「Failed へ遷移した attempt は invoice 終端済み」という前提が成り立つからであり、
    その前提 (`terminateAndFail()` の順序) が変わったら本設計が壊れる。
    fake の `$failOnTerminate = true` で固定する
- [ ] `前提: Failed へ遷移した attempt は invoice が終端済みである`
- [ ] `同一 invoice への grantAutoRecharge は台帳へ 1 件しか入らない`
  - **Round 1 レビュー反映**。`recordSuccessfulCharge()` を同一 invoice id で 2 回呼び、
    `TicketLedgerEntry` が 1 件しか増えないこと (`recharge:{invoiceId}` UNIQUE の behavioral 固定)
- [ ] `所有権喪失ログが固定 event 名 job_ownership_lost を含み、キー集合が Manual 側と一致する`
  - `Log::spy()` + context の `event` キーで判定 (メッセージ文字列に依存しない)
  - 最小 7 キー (`event` / `job_type` / `job_id` / `expected_status` / `actual_status` /
    `stage` / `external_call`) が揃い、Billing 固有の追加は `attempt_ulid` のみであること
  - **PII (email / name) 由来のキーを含まないこと** (Manual 側と同じ検査を Billing にも置く)
- [ ] `後始末ログは別 event 名 job_ownership_lost_cleanup を使い、独自 schema を持つ`
  - **Round 2 レビュー反映**。`event` / `job_type` / `job_id` / `attempt_ulid` /
    `invoice_id` / `terminated` / `error` の 7 キーであること
  - **抑止ログ (`job_ownership_lost`) と後始末ログが同じ event 名に混ざらないこと**
    (同一 event = 同一集計 schema という契約を守る)
- [ ] `Stripe idempotency key が操作ごとに異なり attempt_ulid に pin されている`
  - `CashierAutoRechargeGateway` が組む 4 キー
    (`{base}:invoice` / `{base}:item` / `{base}:finalize` / `{base}:pay`) が
    互いに異なり、いずれも `auto-recharge:{attempt_ulid}` を prefix に持つ
  - **これは Codex Round 2 Suggestion への対応**。既存実装の性質を初めて固定する
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **preflight 1 は既存の冒頭 `refresh()` + Pending 検査と近接**しており、実質的な追加検出は
  `isEnabledFor()` の実行時間分だけである。それでも置くのは
  「すべての外部呼び出しの直前に preflight がある」という不変条件を
  **読み手が目録から辿れる形に揃える**ためで、コストは PK SELECT 1 本。
  (配置そのものを保証するのは Feature テストであり、S6 の gate ではない。)
- **新しい外部呼び出しを 1 つ増やす** (`terminateInvoice`)。これは
  「中断したのに open invoice を残す」ほうが有害という判断。
  ただし**新しい残余窓は作らない** — 終端の成否にかかわらず課金へは進まない。
- `tryTerminateInvoice()` は `Assert` 失敗 (paid) も `Throwable` として握るため、
  誤って paid で呼ぶと無害だが紛らわしい警告が出る。→ `Canceled` 限定で呼ぶ設計にした。

---


---

# 質問

Round 6 で挙げた新規 [Critical] (attach 成功後・pay preflight 直前に canceled 化する注入点が無い) が、この対応で解消しているかを判定し、全体判定 (APPROVED / CHANGES_REQUESTED) を返せ。

確認の観点:

- `FakeAttemptOwnershipPreflight` が「行を terminal 化してから `parent::stillPending()` へ委譲する」形で、`terminalizeAt=[StripeInvoicePay]` のとき **create → attach 1 行 → preflight 2 直前に canceled → pay 抑止 → terminateInvoice 1 回** が決定論的に再現できるか。
- 同じシームで **M16 / M17 の mutation が赤化するか** (preflight 呼び出しを削除すると terminal 化自体が起きず、外部呼び出しが走ってしまうため赤になる、という論法が成立するか)。
- `terminalStatus` を Failed / Paid へ切り替えて後始末の非終端分岐を固定する設計に穴が無いか。
- `duringCreateInvoice` (attach 0 行の再現) と preflight シームの**責務分離**が妥当か。
