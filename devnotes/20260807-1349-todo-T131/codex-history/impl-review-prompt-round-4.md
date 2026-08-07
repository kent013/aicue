# impl-review Round 4 (確認ラウンド) — T131 ジョブ二重実行の所有権再検証

## アプリの使命 (North Star)

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
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## このラウンドの位置づけ

Round 3 で貴殿は **[Critical] 0 件 / [Warning] 3 件**、全体判定 **CHANGES_REQUESTED** を返した。
本ラウンドは**その 3 件への対応が実際にコードへ入っているかの確認**である。
新規の設計変更は行っていない。以下の対応マトリクスと実コードを見て、**Round 3 の [Warning] が解消しているか**を判定せよ。

対象ブランチ: `todo/T131` / 実装コミット `a5e0433`。
参照可能なファイル (read-only): `app/Services/Billing/AutoRechargeService.php`,
`tests/Feature/Billing/AutoRechargeServiceTest.php`, `tests/Support/FakeAutoRechargeGateway.php`,
`docs/architecture.md`。

---

# 対応マトリクス

| # | Round 3 の指摘 | 判断 | 対応 |
|---|---|---|---|
| W1 | `report($exception)` により外部生成メッセージが通常の例外ログへ流れる (保存場所を移しただけ) | **対応する** | 原例外を報告せず、invoice id と例外クラス名だけを持つ **サニタイズ済み `RuntimeException`** を報告する。`previous` にも繋がない |
| W2 | 新設テストが「report 経由で原メッセージが流れないこと」を固定できていない | **対応する** | `Exceptions::fake()` + `assertReported()` / `assertReportedCount(1)` でテストを 1 本追加 |
| W3 | `docs/architecture.md` (b) の「放置してよい」は無条件には正しくない | **対応する** | (b) の収束手順を **「原則すべて手動終端の対象」** に改め、idempotency key の保持期間という理由と、例外的に一時保留してよい条件を引用ブロックで明記 |

## W1 で採らなかった案とその根拠 (反論)

貴殿は Round 3 で 3 案を挙げた。採ったのは 1 案目 (呼び出し側でのサニタイズ) のみである。

- **「gateway 境界で Stripe 例外をドメイン例外へ変換する」は本 PR では採らない。**
  これは `App\Services\Billing\Contracts\AutoRechargeGatewayInterface` の契約変更を伴う。
  本タスクの詳細設計は同 interface を**「変更しない」と明記**しており、変更するなら設計の再合議が要る。
  スコープ外として**独立 TODO 起票が妥当**と判断した。
- **「例外報告基盤で redact する」も本 PR では採らない。** 横断基盤 (exception handler) の変更であり、
  T131 (ジョブ二重実行の所有権再検証) の責務ではない。
- 同じ理由で、**T131 が新設していない**既存経路 `tryTerminateInvoice()` が `$e->getMessage()` を
  構造化ログへ入れている点は本 PR では触っていない (観測語彙を揃えるなら独立 TODO)。

この線引きが妥当かどうかも判定に含めてよい。ただし「本 PR で直せ」と言うなら、
**なぜ設計の再合議を待たずに interface 契約を変えてよいのか**の根拠を示すこと。

---

# 実差分 (対応部分のみ抜粋)

## W1: `app/Services/Billing/AutoRechargeService.php`

`terminateInvoiceBestEffort()` — 所有権喪失後 / attach 0 行の invoice 後始末の共通部。

```php
    /**
     * invoice の best-effort 終端 + 固定 event 名でのログ (上 2 つの共通部)。
     *
     * ★ `$invoiceId` を**引数で受ける**。attempt 行に永続化できなかった invoice も
     *   終端したいため、DB の値に依存しない。
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
     * ★ **cleanup 専用の event 名**を使う。送信抑止の記録 (`LOG_EVENT`) は最小 7 キー schema を
     *   持つ契約であり、キー集合の違うログを同じ event 名に混ぜない。
     * ★ `error` に入れるのは**例外クラス名だけ**である (impl-review Round 2/3 反映)。
     *   Stripe SDK の例外メッセージは**外部サービスが生成する可変文字列**であり、
     *   いま既知の内容が invoice id と status だけでも、将来の SDK / API 応答で
     *   何が混ざるかの契約は無い。構造化ログには**アプリが決めた有界な語彙**だけを載せる。
     * ★ 例外報告も**原例外を渡さない** (impl-review Round 3 反映)。
     *   標準の exception handler は message とスタックトレースを記録するため、
     *   `report($exception)` では「保存場所を移しただけ」で外部生成文字列が残る。
     *   ここでは invoice id と例外クラス名だけを持つ**サニタイズ済み例外**を報告し、
     *   原例外は `previous` にも**繋がない** (reporter が previous chain を出力しうるため)。
     *   トリアージに必要な情報 (どの invoice が / どの種類の失敗か) は保たれる。
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
            // paid 等の「明示的な非成功」もここに落ちる。分類できる有界な値 (クラス名) のみ記録する。
            $error = $exception::class;
            // 原例外は報告しない (外部生成メッセージ / previous chain をログ基盤へ流さない)。
            report(new RuntimeException(
                "auto-recharge: invoice {$invoiceId} の終端に失敗しました ({$error})",
            ));
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

Round 3 との差分は以下の 2 行 (+ docblock の ★ 1 項目):

```diff
-            report($exception);
+            // 原例外は報告しない (外部生成メッセージ / previous chain をログ基盤へ流さない)。
+            report(new RuntimeException(
+                "auto-recharge: invoice {$invoiceId} の終端に失敗しました ({$error})",
+            ));
```

補足: サニタイズ済み例外の message に載せているのは `$invoiceId` (Stripe の invoice id) と
例外クラス名だけである。invoice id は既に同じ warning ログの `invoice_id` キーに載っており、
アプリが追跡子として扱っている**有界な識別子**なので、外部生成の自由文字列とは区別している。

## W2: `tests/Feature/Billing/AutoRechargeServiceTest.php`

Round 2 で追加済みの「構造化ログ側」テスト (再掲・変更なし):

```php
test('後始末ログの error は例外クラス名のみで、外部由来のメッセージを含まない', function (): void {
    // Stripe SDK の例外メッセージは外部サービスが生成する可変文字列であり、構造化ログの
    // 集計語彙へ流さない。
    Log::spy();
    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
    $gateway->withDefaultPaymentMethod();
    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
    $gateway->failOnTerminate = true; // メッセージ「fake gateway: invoice 終端失敗」で throw する

    $service->executeAttempt($attempt);

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context): bool {
            if (($context['event'] ?? null) !== ExternalCallKind::CLEANUP_LOG_EVENT) {
                return false;
            }

            return $context['terminated'] === false
                && $context['error'] === RuntimeException::class
                && ! str_contains((string) $context['error'], 'fake gateway');
        })
        ->once();
});
```

**Round 4 で新規追加したのは以下の 1 本** (例外報告先そのものを fake して検査する):

```php
test('後始末の例外報告にも外部由来のメッセージを渡さない (サニタイズ済み例外のみ)', function (): void {
    // 「構造化ログに載せない」だけでは不十分 — 標準の exception handler は message と
    // スタックトレースを記録するため、原例外をそのまま report() すると保存場所が移るだけになる。
    Exceptions::fake();
    [$organization, $owner, $gateway, $service, $preflight] = autoRechargePreflightSetup();
    $gateway->withDefaultPaymentMethod();
    $attempt = autoRechargePendingAttempt($organization, $owner, $service);
    $preflight->terminalizeAt = [ExternalCallKind::StripeInvoicePay];
    $gateway->failOnTerminate = true;

    $service->executeAttempt($attempt);

    Exceptions::assertReported(function (RuntimeException $reported): bool {
        return str_contains($reported->getMessage(), 'の終端に失敗しました')
            // 外部 (fake gateway = Stripe SDK 相当) が生成した文字列を含まない
            && ! str_contains($reported->getMessage(), 'fake gateway')
            // previous chain も繋がない (reporter が previous を出力しうるため)
            && $reported->getPrevious() === null;
    });
    Exceptions::assertReportedCount(1);
});
```

外部由来メッセージの発生源 (`tests/Support/FakeAutoRechargeGateway.php`、変更なし):

```php
    /** true にすると terminateInvoice が throw する (終端失敗 → pending 維持の再現)。 */
    public bool $failOnTerminate = false;

    public function terminateInvoice(string $invoiceId): void
    {
        if ($this->failOnTerminate) {
            throw new RuntimeException('fake gateway: invoice 終端失敗');
        }

        $status = $this->invoiceStatuses[$invoiceId] ?? 'open';
        if ($status === 'paid') {
            throw new RuntimeException("fake gateway: paid invoice {$invoiceId} は終端できない");
        }
        // ...
    }
```

`use Illuminate\Support\Facades\Exceptions;` はテストファイル冒頭に追加済み。

## W3: `docs/architecture.md` §運用契約 (所有者 = 課金運用担当)

```markdown
     | # | 発生条件 | 検知元 | 収束手順 |
     |---|---|---|---|
     | (a) | 所有権喪失後の void / delete に失敗した | **アプリログ**: `event = job_ownership_lost_cleanup` かつ `terminated=false` (原因の分類は同ログの `error` = 例外クラス名。`report()` 側にも **invoice id と例外クラス名だけを持つサニタイズ済み例外**しか流れないため、**Stripe が生成した原メッセージはアプリのどこにも残らない**。詳細が要るときは `invoice_id` で Stripe 側を直接確認する) | 同ログの `invoice_id` を Stripe で確認し、`paid` でなければ手動 void |
     | (b) | invoice 作成成功 → `stripe_invoice_id` の永続化前にワーカーが死亡した | **アプリログには何も残らない**。Stripe 側を起点に探す — metadata `purpose=auto_recharge` を持つ `draft` / `open` invoice を列挙し、その `recharge_attempt_ulid` に対応する `ticket_auto_recharge_attempts` 行の `stripe_invoice_id` が **NULL または別 id** のものが孤児 | **原則すべて手動終端の対象**とする。`paid` でないことを確認して void / delete する |

     > **(b) を「次の実行が拾うから放置してよい」と書かない** — Stripe の idempotency key は
     > **保持期間 (数十時間程度) を過ぎると再実行で別の invoice が作られる**。
     > 期限の無い状態検査で冪等化されている `terminateInvoice()` とは性質が違う。
     > 例外的に一時保留してよいのは「保持期間内であることが確認でき、かつ再実行が確実に
     > 予定されている」場合だけで、その場合も**再実行後に DB の `stripe_invoice_id` と
     > 一致しない旧 invoice は終端する**。長期間残った pending attempt に対して
     > 「収束するはず」という偽の安全性を持たせないこと。
```

Round 3 時点の記述は以下であり、これは削除された:

```diff
-     | (b) | ... | ... | attempt が pending なら次の executeAttempt が同一 idempotency key で同じ invoice に収束するため放置してよい。resolved 済みなら手動で void / delete する |
+     | (b) | ... | ... | **原則すべて手動終端の対象**とする。`paid` でないことを確認して void / delete する |
```

**確認ラウンド中に自ら見つけて直した齟齬** — (a) 行の検知元には
「メッセージ本文は `report()` 側の例外報告に残る」と書かれたままだった。
これは W1 の対応 (サニタイズ済み例外) によって**偽になった記述**であり、
運用担当に「原メッセージを追える」という誤った期待を与えるため、次のように改めた:

```diff
-     (原因の分類は同ログの `error` = 例外クラス名。メッセージ本文は `report()` 側の例外報告に残る)
+     (原因の分類は同ログの `error` = 例外クラス名。`report()` 側にも **invoice id と例外クラス名だけを
+      持つサニタイズ済み例外**しか流れないため、**Stripe が生成した原メッセージはアプリのどこにも残らない**。
+      詳細が要るときは `invoice_id` で Stripe 側を直接確認する)
```

これにより「トリアージの追跡先は Stripe 側である」という運用契約が明示される。

---

# 再検証結果

- `composer phpstan` (level 10): No errors
- `vendor/bin/pint --test`: passed
- `composer test`: 3484 passed / 0 failed / 2 skipped
- `pnpm lint` / `typecheck` / `test` / `build` / packages 系: すべて green
- 設計 S6 の mutation 表 M1〜M17 を 1 件ずつ適用して赤化を確認し、すべて元へ戻した
  (証跡: `devnotes/20260807-1349-todo-T131/mutation-evidence.md`)

---

# 問い

1. 上記の対応で **Round 3 の [Warning] 3 件が解消しているか**を 1 件ずつ判定せよ
   (解消 / 未解消 / 部分的に解消)。未解消なら**具体的にどの行が問題か**を示せ。
2. W1 で採らなかった 2 案 (gateway 境界でのドメイン例外化 / 例外報告基盤での redact) を
   **本 PR のスコープ外・独立 TODO 起票**とした線引きは妥当か。
   妥当でないなら、詳細設計が「変更しない」と明記した interface 契約を
   本 PR で変えてよい根拠を示せ。
3. 今回の対応**そのもの**が新たに持ち込んだ欠陥があれば [Critical] / [Warning] / [Suggestion] で指摘せよ。
4. **全体判定を `APPROVED` または `CHANGES_REQUESTED` の 1 語で最後に述べよ。**
   `CHANGES_REQUESTED` とするなら、その根拠となる指摘を必ず [Critical] または [Warning] として明示すること。
