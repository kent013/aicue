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

# 役割・タスク

あなたは aicue (Laravel 12 + Svelte 5) の設計レビュアである。

本ラウンドは **確認ラウンド (Round 6)** である。Round 5 で出した [Critical] / [Warning] に対する対応を詳細設計へ反映済みなので、**その対応で指摘が解消しているかだけ**を判定せよ。

判定のルール:

1. Round 5 の指摘それぞれについて **解消 / 未解消** を明示せよ。未解消のものは何が足りないかを具体的に書け。
2. **全体判定を `APPROVED` または `CHANGES_REQUESTED` のいずれかで返せ**。
3. 解消していない指摘があるときだけ、その指摘を挙げよ。**新しい観点の粗探しを目的にしない** — ただし、今回の対応そのものが持ち込んだ **新規の [Critical]** (対応によって壊れたもの) があれば挙げてよい。
4. 設計を不必要に複雑にする提案は避けよ (思考原則 2「今必要なものだけ作る」)。承認条件でない改善案は `[Suggestion]` と明記せよ。

出力形式:

```
## Round 5 指摘の解消判定
### S4 [Critical] Stripe preflight の配置を赤化できるテストシームが無い → 解消 / 未解消
（理由）
### S4 [Warning] 新設メソッドの列挙 → 解消 / 未解消
### S7 [Warning] Stripe の「配置を保証する」主張 → 解消 / 未解消
### S6 [Suggestion] 期待集合の重複検査 → 解消 / 未解消

## 新規 [Critical] (対応が持ち込んだもの。無ければ「なし」)

## 全体判定
APPROVED / CHANGES_REQUESTED
```

---

# Round 5 の指摘に対する対応マトリクス (Claude 側の判断)

# 対応マトリクス: design-review Round 5

> ⚠ 本ラウンドは app-design SKILL.md の**上限 5 ラウンド目**である。
> 以下の対応は設計へ反映済みだが、**Codex による再レビューは行っていない**
> (final_verdict は Round 5 の実判定どおり CHANGES_REQUESTED を報告する)。

## S4 [Critical] Stripe の preflight 1 / 2 の「配置」を赤化できるテストシームが無い

- 判断: **対応する (設計変更)**
- 根拠: 完全に妥当。検証した結果:
  - `duringCreateInvoice` は `createAutoRechargeInvoice()` の**内側**で発火するため、
    **preflight 1 より後**である。preflight 1 を削除しても結果 (invoice 作成 → attach 0 行 → 終端)
    は変わらず、M16 相当が赤にならない。
  - preflight 2 を通すには attach 成功後に terminal 化する必要があるが、
    `duringCreateInvoice` は attach より前に発火するため attach が 0 行になり preflight 2 へ到達しない。
    既存 invoice 経路 (`stripe_invoice_id !== null`) では冒頭の Pending guard と preflight 2 の間に
    注入点が 1 つも無い。
  - Manual 側は事情が違い、**behavioral に赤化できる**:
    解析は `onAttempt` (LLM 呼び出し n 回目で terminal 化 → n+1 回目が抑止される)、
    レンダは `duringCompose` (compose 中に terminal 化 → upload が抑止される)。
    したがって不足しているのは **Billing だけ**である。
- 対応内容: Codex 提案の 1 番目 (「ownership verifier を小さな注入可能 collaborator にする」) を採る。
  **`app/Support/JobExecution/AttemptOwnershipPreflight`** を新設し
  (`stillPending(TicketAutoRechargeAttempt $attempt, ExternalCallKind $call): bool`)、
  `AutoRechargeService` のコンストラクタで受け取る。
  - **非 final クラス**にする。これは `App\Services\Render\RenderObjectStorage` と同じ作法で、
    「fake が override して差し替える前提」であることを docblock に書く
    (interface を新設しない = AGENTS.md 思考原則 2)。
  - テスト側は `Tests\Support\FakeAttemptOwnershipPreflight` (`$denyKinds` / `$calls` を持つ) を
    `app()->instance()` で差し込む。
  - これで **本番コードにテスト専用 closure を足さずに** 次が成立する:
    - `denyKinds = [StripeInvoiceCreate]` → `createdInvoices === []` を期待。
      **create 直前の `stillPending()` を削除すると invoice が作られてしまい赤化する** (M16)。
    - `denyKinds = [StripeInvoicePay]` → `payCalls === []` かつ invoice が終端される、を期待。
      **pay 直前の `stillPending()` を削除すると pay が走り赤化する** (M17)。
  - `AutoRechargeService::stillPending()` は廃止し (後方互換の並走を残さない。AGENTS.md 思考原則 3)、
    所有権喪失ログもこの collaborator が出す (Billing 側ログ schema の所在が 1 箇所になる)。
  - S6 の目録の `PreflightCheckpoint` は
    `AttemptOwnershipPreflight::stillPending` / `ReturnsBoolean` を指す
    (`verifierClass` を持つ型モデルなので変更なしで表現できる)。
  - Manual 側は**変更しない** — 既に behavioral に赤化できるため、
    collaborator を足すのは利益のない churn になる (AGENTS.md 思考原則 2)。
    この非対称性の理由を設計に明記する。

## S4 [Warning] 新設メソッドが変更箇所に列挙されていない

- 判断: **対応する**
- 根拠: 事実。実装漏れ防止。
- 対応内容: 変更箇所に `terminateUnattachedInvoice()` / `terminateInvoiceBestEffort()` /
  コンストラクタへの `AttemptOwnershipPreflight` 追加を列挙する。

## S7 [Warning] Stripe については「Feature テストが配置を保証する」と言えない

- 判断: **対応する**
- 根拠: 上記のとおり。collaborator 導入後は成立する。
- 対応内容: mutation 表に **M16 / M17** を追加し、対応表の分担記述を
  「Billing は注入可能な preflight collaborator の fake が配置を赤化する」まで具体化する。

## S6 [Suggestion] 期待集合側にも同一 `ExternalCallKind` の重複検査を入れる

- 判断: **対応する (安価)**
- 根拠: 妥当。期待値と checkpoint の両方を重複登録した場合に読みやすく失敗する。
- 対応内容: `jobDedupRequiredExternalCalls()` の各リストに重複が無いことを検査するケースを追加。

---

# 対応後の詳細設計 (変更された節と、判断に必要な周辺の節)

## 【1】S4 全文 (対応の本体。preflight collaborator の新設・変更箇所の列挙・テスト計画)

## S4. auto-recharge の preflight (Stripe 呼び出し直前) + 中断時の invoice 終端

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
  `AttemptOwnershipPreflight` を継承し、`public array $denyKinds = []` (この種別のとき false を返す) と
  `public array $calls = []` (呼ばれた種別の記録) を持つ。`app()->instance()` で差し込む。
  **preflight の配置 (呼び出しの有無) を behavioral に赤化するためのシーム**である
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
  競合した場合に「**Stripe で課金済みなのにチケット未付与**」という**より悪い**不整合が生じる。
  現行順序は「取られた金は必ず台帳に載せる」という意図的な設計であり、**変更しない**。
  この 2 本立てを S6 の目録の根拠文へ記録し、Feature テストで固定する。

### 現行コード

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
            $invoiceId = $this->gateway->createAutoRechargeInvoice(
                $organization,
                $attempt->stripe_price_id,
                $attempt->quantity,
                $this->metadataFor($organization, $attempt),
                $keyBase,
            );
            // invoice_id の永続化は pay より必ず前 (プロセス死でも迷子 invoice を作らない)。
            $attempt->forceFill(['stripe_invoice_id' => $invoiceId])->save();
        }

        $result = $this->gateway->payOffSessionInvoice($invoiceId, $keyBase);

        if ($result->paid) { /* … */ }

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
 * ★ **非 final** にしてあるのは fake が override して差し替えるためである
 *   (`App\Services\Render\RenderObjectStorage` と同じ作法。interface は新設しない)。
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
        ]);
    }
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`bool` / `void`)
- [x] null 安全 — `$attempt->status` は `@property AutoRechargeAttemptStatus $status` で非 null。
      `$attempt->stripe_invoice_id` は `?string` だがログへ渡すだけ
- [x] DTO を返している (配列返却なし。ログ配列は Monolog context)
- [x] Generics — 該当なし
- [x] `Assert` — 追加不要 (既存の `Assert::isInstanceOf($organization, ...)` を維持)

### テスト計画

`tests/Feature/Billing/AutoRechargeServiceTest.php` へ **追記** (既存 test は改変しない)。
テストデータは Factory (`TicketAutoRechargeAttempt::factory()` /
`TicketAutoRecharge::factory()` / `createOrganizationWithOwner()`)。

**配置 (placement) の固定** — `FakeAttemptOwnershipPreflight` を差し込んで行う
(Round 5 レビュー反映。これらが M16 / M17 を赤化する):

- [ ] `配置: create の直前に preflight がある (denyKinds=[StripeInvoiceCreate] で invoice を作らない)`
  - 期待: `$gateway->createdInvoices === []` かつ `$gateway->payCalls === []`
  - **create 直前の `stillPending()` 呼び出しを削除すると invoice が作られて赤化する**
- [ ] `配置: pay の直前に preflight がある (denyKinds=[StripeInvoicePay] で pay しない)`
  - 前提: attempt 行は **Pending のまま**にする (冒頭の Pending guard と attach の
    条件付き UPDATE を通す必要があるため。fake は DB 状態を変えず「所有権喪失」だけを演じる)
  - 期待: `$gateway->createdInvoices` は 1 件・`$gateway->payCalls === []`
  - 期待: `$gateway->terminated === []` — 行が Pending である以上
    `terminateInvoiceAfterOwnershipLost()` は `Canceled` 限定の early return で何もしない。
    **「canceled のときに invoice を終端する」ほうは実 preflight を使う下の抑止テストの担当**であり、
    ここで両方を見ようとすると 1 つの fake で相反する状態を要求することになる
    (denyKinds で pay を止めつつ行を canceled にすると attach が 0 行になり preflight 2 へ到達しない)
  - **pay 直前の `stillPending()` 呼び出しを削除すると pay が走って赤化する**
- [ ] `配置: preflight が両方 true を返す正常系では create → pay が従来どおり進む` (回帰)

**抑止 (suppression) の固定** — 実 `AttemptOwnershipPreflight` を使い、状態遷移で再現する:

- [ ] `preflight 1: invoice 作成前に attempt が canceled → createAutoRechargeInvoice を呼ばない`
  - gateway fake の呼び出し記録が空であること
- [ ] `preflight 2: invoice 作成後・pay 前に attempt が canceled → payOffSessionInvoice を呼ばない`
  - fake の `createAutoRechargeInvoice` は 1 回・`payOffSessionInvoice` は 0 回
  - **`terminateInvoice` が作成された invoice id で 1 回呼ばれる** (要件 ii)
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
- [ ] `preflight 2: attempt が paid のときは terminateInvoice を呼ばない` (void 不可の分類)
- [ ] `preflight 2: attempt が failed のときは terminateInvoice を呼ばない` (二重終端の抑止)
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

## 【2】S6 の該当部分 — 目録の PreflightCheckpoint 登録 / 期待集合 / 重複検査 (S4 の collaborator を目録がどう表現するか)

>   checkpoint 登録の**集合一致** / 再検証点の実在と制御方式に一致する戻り型 /
>   根拠 30 文字以上 / 免除 cap / `PreflightRequirement` 実装が 2 種に閉じている /
>   固定 event literal が 1 箇所に閉じている。
> - **保証しない**: preflight が**外部呼び出しの直前に置かれている**こと (Feature テストの担当)。
>   期待値 map と目録を**同時に**消す変更 (宣言的 gate の性質。1 箇所の削除では通らない
>   = レビューで必ず 2 箇所の差分が見えることが目的)。

```php
<?php

declare(strict_types=1);

// ★以下は「要点のみ」の掲載だが、**import はこの一覧を満たすこと** (Round 3 レビュー反映)。
use App\Enums\Security\ExternalCallKind;
use App\Enums\Security\JobDedupExemption;
use App\Enums\Security\JobDedupGuarantee;
use App\Jobs\Billing\ExecuteAutoRechargeAttemptJob;
use App\Jobs\Manual\RunManualAnalysis;
use App\Jobs\Manual\RunManualRender;
use App\Services\Manual\AnalysisPipeline;
use App\Services\Manual\RenderPipeline;
use App\Support\JobExecution\AttemptOwnershipPreflight;
use Tests\Support\JobDedup\ExemptionEntry;
use Tests\Support\JobDedup\GuaranteeEntry;
use Tests\Support\JobDedup\NoExternalCall;
use Tests\Support\JobDedup\PreflightCheckpoint;
use Tests\Support\JobDedup\PreflightControlFlow;
use Tests\Support\JobDedup\PreflightRequirement;
use Tests\Support\QueuedJobPopulation;
use Webmozart\Assert\Assert;

/*
 * 裁定 AG-082「入口の排他 / 結果の一回性」の aicue 実装を deny-by-default で固定する。
 *
 * キューに載る全クラス (ShouldQueue 実装) は、次のいずれかに**必ず**分類される:
 *   - 保証側: JobDedupGuarantee (永続状態遷移の機構) + PreflightRequirement + 30 文字以上の根拠
 *   - 免除:   JobDedupExemption + 30 文字以上の根拠
 * 未分類は fail (新しいジョブを足したら必ずここへ登録する)。
 *
 * ★母集団は QueuedJobLeaseInventoryTest と**同一の実装** (Tests\Support\QueuedJobPopulation)
 *   を使う。2 実装に分けると片方だけ更新される drift が起きるため。
 *
 * 運用契約: docs/architecture.md §ジョブの重複実行と結果の一回性
 */

/** @return array<class-string, GuaranteeEntry> */
function jobDedupGuarantees(): array
{
    return [
        RunManualAnalysis::class => new GuaranteeEntry(
            mechanisms: [JobDedupGuarantee::PessimisticLockWithStatusGuard],
            preflights: [new PreflightCheckpoint(
                AnalysisPipeline::class, 'assertStillOwned',
                ExternalCallKind::LlmCompletion, PreflightControlFlow::ThrowsOnLoss,
            )],
            rationale: 'startJob が lockForUpdate + status===queued で running へ遷移させ、'
                .'finalize が同一 tx で materialize + tickets->commit + succeeded を原子化する。'
                .'LLM は冪等キーを持たないため、各呼び出しの直前に preflight を置く。',
        ),
        RunManualRender::class => new GuaranteeEntry(
            mechanisms: [JobDedupGuarantee::PessimisticLockWithStatusGuard],
            preflights: [new PreflightCheckpoint(
                RenderPipeline::class, 'assertStillOwned',
                ExternalCallKind::ObjectStoragePut, PreflightControlFlow::ThrowsOnLoss,
            )],
            rationale: 'startJob / finalize が AnalysisPipeline と同型。S3 PUT は取り消せない'
                .'外部副作用なので、updateProgress の後・upload の直前に preflight を置く。',
        ),
        ExecuteAutoRechargeAttemptJob::class => new GuaranteeEntry(
            // ★軸の違う 2 本の保証を**両方**登録する (Round 2 レビュー反映)
            mechanisms: [
                // ★台帳の冪等キー UNIQUE は「起票の拒否」ではなく「効果確定の拒否」なので
                //   DatabaseUniqueConstraint とは別 case (Round 3 レビュー反映)
                JobDedupGuarantee::IdempotentLedgerKeyUniqueConstraint, // 付与の一回性 (invoice 単位)
                JobDedupGuarantee::ConditionalStatusUpdate,             // attempt 遷移の一回性 (attempt 単位)
            ],
            // ★外部呼び出しは 2 つある。両方を登録する (Round 3 レビュー反映)
            preflights: [
                new PreflightCheckpoint(
                    AttemptOwnershipPreflight::class, 'stillPending',
                    ExternalCallKind::StripeInvoiceCreate, PreflightControlFlow::ReturnsBoolean,
                ),
                new PreflightCheckpoint(
                    AttemptOwnershipPreflight::class, 'stillPending',
                    ExternalCallKind::StripeInvoicePay, PreflightControlFlow::ReturnsBoolean,
                ),
            ],
            rationale: '冪等キーが 2 本ある: 付与の一回性は台帳の recharge:{invoiceId} UNIQUE '
                .'(invoice 単位。付与は attempt 遷移より先に走るが、この UNIQUE が二重付与を拒否する)、'
                .'attempt 遷移の一回性は where status=pending の条件付き UPDATE (attempt 単位)。'
                .'org lock (TTL 180s) は best-effort で保証を担わない。',
        ),
        // AutoRechargeTriggerJob / DeleteTakeObjectsJob / DeleteRenderOutputsJob /
        // SyncBillingCustomerDetails / … 全 18 件をここか jobDedupExemptions() のどちらかへ登録する
    ];
}

/**
 * ジョブごとに **preflight を要求する外部呼び出しの種別** (期待値の正本)。
 *
 * ★ 目録 (`jobDedupGuarantees()`) とは**独立した宣言**である (Round 4 レビュー反映)。
 *   目録の中だけで閉じた検査 (非空 / 重複なし / 実在) は「登録漏れ」という**不在**を
 *   検出できない — checkpoint を 1 件削っても残りが要件を満たしてしまう。
 *   期待集合との一致を要求すれば、削除は必ず赤になる。
 *
 * ★ **この検査が保証しないこと**: 本 map と目録の**両方**を同時に消せば green のままになる。
 *   これは宣言的 gate の性質であり、目的は「1 箇所の削除では通らない = レビューで必ず
 *   2 箇所の差分が見える」ことである。
 *   より強い形 (サービスのソースを走査し、外部呼び出しの実在から期待集合を導出する) は、
 *   **preflight を意図的に持たない外部呼び出し** (所有権喪失**後**の後始末である
 *   `terminateInvoice`) を別分類する必要があり複雑さが跳ねるため今回は採らない
 *   (AGENTS.md 思考原則 2)。
 *
 * ★ 空リスト = 外部呼び出しを持たない (`preflights` は `NoExternalCall` ちょうど 1 件)。
 *
 * @return array<class-string, list<ExternalCallKind>>
 */
function jobDedupRequiredExternalCalls(): array
{
    return [
        RunManualAnalysis::class => [ExternalCallKind::LlmCompletion],
        RunManualRender::class => [ExternalCallKind::ObjectStoragePut],
        ExecuteAutoRechargeAttemptJob::class => [
            ExternalCallKind::StripeInvoiceCreate,
            ExternalCallKind::StripeInvoicePay,
        ],
        // jobDedupGuarantees() の全キーを漏れなく列挙する (キー集合一致を gate が検査する)
    ];
}

/** @return array<class-string, ExemptionEntry> */
function jobDedupExemptions(): array { /* 配信系 8 件ほか */ }

/** 免除件数の上限 (形骸化ガード)。**現在値ちょうど** (exact fit)。 */
function jobDedupExemptionCap(): int { /* 実装時に確定 */ }


(中略: 目録 gate の検査本体のうち、重複検査と集合一致検査の部分)

        expect($reflection->hasMethod($preflight->verifierMethod))->toBeTrue(
            "{$class}: preflight 再検証点 {$preflight->verifierClass}::{$preflight->verifierMethod} が実在しません",
        );

        // ★ Manual (例外で中断 = void) と Billing (structured return = bool) を統合しないため、
        //   目録が宣言した PreflightControlFlow と実際の戻り型の一致を検査する。
        $expected = $preflight->expectedReturnType();
        $returnType = $reflection->getMethod($preflight->verifierMethod)->getReturnType();
        expect($returnType instanceof ReflectionNamedType && $returnType->getName() === $expected)->toBeTrue(
            "{$class}: preflight 再検証点の戻り型が目録の制御方式 ({$expected}) と一致しません",
        );
        }
    }
});

test('保証機構は 1 つ以上・重複なしで登録されている', function (): void {
    foreach (jobDedupGuarantees() as $class => $entry) {
        expect($entry->mechanisms)->not->toBeEmpty("{$class}: 保証機構が空です");

        // ★重複「値そのもの」を出す (順序依存の比較より失敗メッセージが読みやすい)
        $values = array_map(static fn (JobDedupGuarantee $m): string => $m->value, $entry->mechanisms);
        $duplicates = array_values(array_diff_assoc($values, array_unique($values)));
        expect($duplicates)->toBe([], "{$class}: 保証機構が重複しています: ".implode(', ', $duplicates));
    }
});

test('期待する外部呼び出し種別が全ジョブ分宣言されている (期待値の書き忘れ検出)', function (): void {
    $guaranteed = array_keys(jobDedupGuarantees());
    $required = array_keys(jobDedupRequiredExternalCalls());
    sort($guaranteed);
    sort($required);

    expect($required)->toBe($guaranteed,
        'jobDedupRequiredExternalCalls() は jobDedupGuarantees() の全クラスを列挙すること');
});

test('期待する外部呼び出し種別に重複がない', function (): void {
    foreach (jobDedupRequiredExternalCalls() as $class => $kinds) {
        $values = array_map(static fn (ExternalCallKind $k): string => $k->value, $kinds);
        $duplicates = array_values(array_diff_assoc($values, array_unique($values)));
        expect($duplicates)->toBe([], "{$class}: 期待種別が重複しています: ".implode(', ', $duplicates));
    }
});

test('登録済み checkpoint の種別集合が期待集合と一致する (登録漏れ / 余剰の検出)', function (): void {
    // ★ ここが completeness の要 (Round 4 レビュー反映)。目録の中だけで閉じた検査では
    //   「checkpoint を 1 件削った」ことを検出できない (残りが要件を満たしてしまう)。
    foreach (jobDedupGuarantees() as $class => $entry) {
        expect($entry->preflights)->not->toBeEmpty("{$class}: preflight が空です");

        $checkpoints = array_values(array_filter(
            $entry->preflights,
            static fn (PreflightRequirement $p): bool => $p instanceof PreflightCheckpoint,
        ));
        $none = array_values(array_filter(
            $entry->preflights,
            static fn (PreflightRequirement $p): bool => $p instanceof NoExternalCall,
        ));

        $expectedKinds = array_map(
            static fn (ExternalCallKind $k): string => $k->value,
            jobDedupRequiredExternalCalls()[$class] ?? [],
        );
        $registeredKinds = array_map(
            static fn (PreflightCheckpoint $c): string => $c->externalCall->value,
            $checkpoints,

---

## 【3】S6 の mutation 表 (M16 / M17 を追加した箇所)

### テスト計画 (gate 自身の受け入れ = mutation 手順)

> **問題**: この gate は「素の main では常に green」であり、そのままでは
> 「本当に検出できるのか」が確認できない。以下の mutation を **1 つずつ手で入れて赤化を確認し、
> 必ず元へ戻す**。結果 (mutation → 失敗したテスト名) を実装 PR の説明に記録する。

| # | mutation | 期待する赤 |
|---|---|---|
| M1 | `jobDedupGuarantees()` から `RunManualAnalysis` の entry を 1 行削除 | 「未分類の ShouldQueue 実装がある」 |
| M2 | `AnalysisPipeline::assertStillOwned` を `assertStillOwnedX` にリネーム | 「preflight 再検証点が実在しません」 |
| M3 | `assertStillOwned` の戻り型を `void` → `bool` に変更 | 「戻り型が目録の制御方式 (void) と一致しません」 |
| M3b | `stillPending` の戻り型を `bool` → `void` に変更 | 「戻り型が目録の制御方式 (bool) と一致しません」 |
| M3c | `ExecuteAutoRechargeAttemptJob` の `mechanisms` を空配列にする | `GuaranteeEntry` の `Assert::notEmpty` + 「保証機構は 1 つ以上・重複なし」 |
| M4 | `NoExternalCall` の根拠を 10 文字にする | constructor の `Assert` + gate の 30 文字検査 |
| M5 | `AutoRechargeService::LOCK_TTL_SECONDS` を 700 にする | S5 の「org lock TTL は retry_after を下回る」 |
| M6 | `AutoRechargeTriggerJob::$uniqueFor` を 0 にする | S5 の「uniqueFor は正の値である」 |
| M7 | `tests/Support/JobDedup/` に 3 つ目の `PreflightRequirement` 実装を足す | 「実装は 2 種類に閉じている」 |
| M8 | `app/Services/Manual/AnalysisPipeline.php` に `'job_ownership_lost'` を直書き | 「固定 event 名の literal は ExternalCallKind 以外に直書きされていない」 |
| M8b | 同じく **double quote** で `"job_ownership_lost"` を直書き | 同上 (quote 種別の取りこぼしが無いこと) |
| M8c | `ExecuteAutoRechargeAttemptJob` の `preflights` から `StripeInvoiceCreate` を削除 | 「登録済み checkpoint の種別集合が期待集合と一致する」 |
| M8d | `jobDedupRequiredExternalCalls()` から 1 クラス丸ごと削除 | 「期待する外部呼び出し種別が全ジョブ分宣言されている」 |
| M9 | 目録の免除を 1 件増やす (cap 到達) | 「免除件数が上限を超えない」 |
| M10 | `QUEUED_JOB_LEASE_INVENTORY` から 1 件削除 | 既存の「接続経路: キューに載る全クラスが目録に登録されている」(走査の委譲後も従来どおり検出できることの確認) |
| M11 | `AnalysisPipeline::writeProgress()` の `where('status', running)` を外す | S2 の「cron failed 後に step / progress が書き戻されない」 |
| M12 | `RenderPipeline::updateProgress()` の `where('status', running)` を外す | S3 の「cron failed 後に step / progress が書き戻されない」 |
| M13 | `stripe_invoice_id` の永続化を素の `save()` に戻す | S4 の「attach 0 行: invoice_id を書かず invoice を終端する」 |
| M14 | `terminateUnattachedInvoice()` を `Canceled` 限定にする | S4 の「attach 0 行: failed へ遷移していた場合も終端する」 |
| M15 | cleanup ログの event 名を `LOG_EVENT` に戻す | S4 の「event ごとのキー集合 schema」 |
| M16 | `createAutoRechargeInvoice()` 直前の `stillPending()` 呼び出しを削除 | S4 の「配置: create の直前に preflight がある」 |
| M17 | `payOffSessionInvoice()` 直前の `stillPending()` 呼び出しを削除 | S4 の「配置: pay の直前に preflight がある」 |

その他:

---

## 【4】S7 の規約 ↔ テスト対応表 (分担記述を具体化した箇所)

     (b) invoice 作成成功 → stripe_invoice_id 保存前のワーカー死亡で残ったもの。
     どちらも Stripe metadata の `recharge_attempt_ulid` から attempt を逆引きできる。
     `reconcile()` は DB の pending attempt を走査するため**母集団外**である。
8. **規約 ↔ テスト対応表** (下記)。
```

**規約 ↔ テストの対応表** (AGENTS.md 禁止事項 1 = 不変条件はテスト登録まで含めて実装済み):

| 規約の文 | 保証するテスト |
|---|---|
| キューに載る全クラスが保証側 or 免除に分類される | `JobExecutionDedupInventoryTest` |
| 登録された**すべての** preflight checkpoint が実在し、制御方式 (`PreflightControlFlow`) に一致する戻り型を持つ (**存在まで**) | `JobExecutionDedupInventoryTest` |
| **期待する外部呼び出し種別 (`jobDedupRequiredExternalCalls()` が正本) と checkpoint 登録の集合一致** / `NoExternalCall` と混在しない | `JobExecutionDedupInventoryTest` |
| preflight が**外部呼び出しの直前に置かれている** (配置) | `AnalysisPipelineTest` / `RenderPipelineTest` / `AutoRechargeServiceTest` (追記分。fake が呼ばれないことで固定)<br>★**分担**: Architecture gate = 集合一致 + 実在 + 戻り型 / Feature テスト = 配置。<br>Manual は既存 fake のフック (`onAttempt` / `duringCompose`) で、**Billing は注入可能な `FakeAttemptOwnershipPreflight`** で配置を赤化する |
| 終端後にジョブ行の進捗を書き戻さない (条件付き UPDATE) | `AnalysisPipelineTest` / `RenderPipelineTest` (追記分) |
| 終端後に `stripe_invoice_id` を書き込まない (条件付き UPDATE) | `AutoRechargeServiceTest` (追記分) |
| 同一 invoice への付与は台帳に 1 件しか入らない | `AutoRechargeServiceTest` (追記分) |
| 免除は型付き enum + 30 文字以上の根拠 | `JobExecutionDedupInventoryTest` + value object の `Assert` |
| 入口の排他 TTL / uniqueFor < retry_after | `JobExclusionOrderingInvariantTest` |
| `$timeout < retry_after < 予約 TTL ≤ stale 閾値` | `AnalysisTimeBudgetInvariantTest` / `RenderTimeBudgetInvariantTest` (既存) |
| worker `--timeout` < `retry_after` | `QueueWorkerLeaseInvariantTest` (既存) |
| 所有権喪失時に LLM を呼ばない | `AnalysisPipelineTest` (追記分) |
| 所有権喪失時に S3 PUT しない | `RenderPipelineTest` (追記分) |
| 所有権喪失時に **invoice 作成・支払いを抑止し、必要な既作成 invoice を終端する** | `AutoRechargeServiceTest` (追記分) |

---

## 【5】実装モードの補足 (非対称性の裁定)

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 新モデル・新マイグレーション・新 API・新画面が一切なく、既存 3 サービスへの局所的な挿入 (preflight) と新規テスト群のみ。既存の状態機械・時間 budget・キュー接続トポロジ・DTO / Inertia Props に一切触れないため、他の作業と衝突する面が狭い。S1 (語彙) → S2/S3/S4 (挿入) → S5/S6 (gate) → S7 (文書) の順で段階的にコミットでき、各段で `composer test` が green を保てる。 |
| 競合リスク | **T127 (既定キュー接続の分割)** と `config/queue.php` の `database.retry_after` を共有する (S5 の比較先)。T127 が先に入ると S5 の比較先を差し替える必要がある → S5 の docblock に T127 との関係を明記して、どちらが先でも気付ける形にする。<br>**T124/T125/T126** (throttle / 外部 SDK timeout) とはファイルが重ならない。<br>`tests/Architecture/QueuedJobLeaseInventoryTest.php` を触るのは走査 3 関数の委譲のみで、目録定数とテストケースは無変更 — 同ファイルを触る他タスクがある場合のみ調整が要る。 |
| 補足 | S4 で `app/Support/JobExecution/AttemptOwnershipPreflight` を新設する (非 final。fake が override する前提で `RenderObjectStorage` と同じ作法)。**Manual 側には同じ collaborator を作らない** — 既存 fake のフックで配置を behavioral に赤化できるため、churn になるだけである (AGENTS.md 思考原則 2)。 |
| 実装順序 | S1 → S2 → S3 → S4 → S5 → S6 → S7 (S6 の目録は S2/S3/S4 の再検証点が実在してからでないと green にできない) |

---

# 質問

この対応で Round 5 の [Critical] / [Warning] が解消しているかを判定し、全体判定 (APPROVED / CHANGES_REQUESTED) を返せ。解消していない場合は、**残っている指摘だけ**を挙げよ。

特に次を確認せよ:

- `FakeAttemptOwnershipPreflight` (`AttemptOwnershipPreflight` を継承し `denyKinds` で false を返す fake) を `app()->instance()` で差し込む設計で、**M16 / M17 の mutation が決定論的に赤化するか**。
- `denyKinds=[StripeInvoiceCreate]` / `denyKinds=[StripeInvoicePay]` の 2 ケースが、それぞれ preflight 1 / preflight 2 の**削除**を検出できるか (他方の preflight が残っていても赤になるか)。
- placement テスト (fake preflight) と suppression テスト (実 preflight + 状態遷移) の**分担**に穴が無いか。特に「canceled のときに既作成 invoice を終端する」の検証が抜け落ちていないか。
- Manual 側に collaborator を作らない非対称性の裁定 (既存 fake のフックで配置を赤化できるため) が妥当か。
