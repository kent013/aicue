【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

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

## 補足コンテキスト (レビューに必要な現行コードの事実)

対象リポジトリは `/workspace` (aicue)。以下は実際に読んだ現行コードの要約である。

### `app/Services/Billing/Contracts/AutoRechargeGatewayInterface.php`
9 メソッドの狭い gateway。`createSetupCheckout` / `createAutoRechargeInvoice` /
`payOffSessionInvoice` / `terminateInvoice` / `retrieveInvoiceState` /
`getDefaultPaymentMethodState` / `resolveSetupIntentPaymentMethod` /
`setDefaultPaymentMethod` / `resolveSubscriptionPaymentMethod`。
docblock に「カード起因の失敗は例外ではなく typed 結果で返す。Stripe 障害・設定不備は
例外のまま伝播 (fail-closed)」と明記。実装は `CashierAutoRechargeGateway` (Stripe SDK 直)。
`fake_externals` 時は `App\Services\Billing\Fakes\FakeAutoRechargeGateway` を bind。

### gateway を注入される app 側クラス (母集団は現在 4 つ)
- `App\Services\Billing\AutoRechargeService` (constructor 注入。catch あり)
- `App\Jobs\Billing\SetDefaultPaymentMethodJob` (handle 注入。catch なし)
- `App\Jobs\Billing\ReuseSubscriptionPaymentMethodJob` (handle 注入。catch なし)
- `App\Jobs\Billing\HandleAutoRechargeChargeFailureJob` (handle 注入。catch なし)

### `AutoRechargeService` の観測 4 箇所 (現行)
```php
// L694-721 terminateInvoiceBestEffort() — T131 で新設。有界語彙のみ
} catch (Throwable $exception) {
    $terminated = false;
    $error = $exception::class;
    report(new RuntimeException("auto-recharge: invoice {$invoiceId} の終端に失敗しました ({$error})"));
}
Log::warning('auto-recharge: 所有権喪失後の invoice 終端', [
    'event' => ExternalCallKind::CLEANUP_LOG_EVENT, 'job_type' => …, 'job_id' => …,
    'attempt_ulid' => …, 'invoice_id' => $invoiceId, 'terminated' => $terminated, 'error' => $error,
]);

// L819-838 tryTerminateInvoice() — 姉妹経路。原メッセージが残っている
} catch (Throwable $e) {
    Log::warning('auto-recharge: invoice termination failed, keeping attempt pending', [
        'attempt_ulid' => $attempt->attempt_ulid,
        'invoice_id' => $attempt->stripe_invoice_id,
        'error' => $e->getMessage(),
    ]);
    return false;
}

// L990-996 reconcile() の attempt 隔離 catch
} catch (Throwable $e) {
    Log::warning('auto-recharge reconcile: attempt processing failed', [
        'attempt_ulid' => $attempt->attempt_ulid, 'error' => $e->getMessage(),
    ]);
}

// L1011-1016 reconcile() の取りこぼし起票 catch
} catch (Throwable $e) {
    Log::warning('auto-recharge reconcile: trigger failed', [
        'organization_id' => $organization->getKey(), 'error' => $e->getMessage(),
    ]);
}
```

### テスト用 spy (`tests/Support/FakeAutoRechargeGateway.php`) の現行の投げ方
```php
public function terminateInvoice(string $invoiceId): void
{
    if ($this->failOnTerminate) { throw new RuntimeException('fake gateway: invoice 終端失敗'); }
    $status = $this->invoiceStatuses[$invoiceId] ?? 'open';
    if ($status === 'paid') { throw new RuntimeException("fake gateway: paid invoice {$invoiceId} は終端できない"); }
    …
}
```
一方、本物 `CashierAutoRechargeGateway::terminateInvoice()` の paid 判定は
`Webmozart\Assert\Assert::true(...)` であり、投げるのは
`Webmozart\Assert\InvalidArgumentException` である (= spy と本物でクラスが違う)。

### runtime fake (`app/Services/Billing/Fakes/FakeAutoRechargeGateway.php`)
全メソッドが中立帰還で、**例外を 1 つも投げない**。`payOffSessionInvoice` は常に
`OffSessionChargeResultDto::failed($invoiceId, 'card_declined', 'generic_decline')`。

### 既存の deny-by-default 目録型 gate の作法 (見本)
`tests/Architecture/JobExecutionDedupInventoryTest.php` /
`ThrottleCoverageInventoryTest.php` / `QueuedJobLeaseInventoryTest.php`。
共通の形は「母集団をコードから機械導出 → 目録 (保証側) か免除 (型付き enum + 30 文字以上の根拠)
に全件分類 → 未分類は fail → 免除件数を exact fit の cap で固定」。

### vendor の例外クラス実数
- `Stripe\Exception\` 直下の具象クラス 13 個 (ApiConnectionException / AuthenticationException /
  BadMethodCallException / CardException / IdempotencyException / InvalidArgumentException /
  InvalidRequestException / PermissionException / RateLimitException /
  SignatureVerificationException / TemporarySessionExpiredException / UnexpectedValueException /
  UnknownApiErrorException)。`ApiErrorException` は abstract、`ExceptionInterface` は interface。
  サブ名前空間は `Stripe\Exception\OAuth\` の 1 つだけ (Connect OAuth 専用で本アプリは未使用)。
- `Laravel\Cashier\Exceptions\` 8 個 (CustomerAlreadyCreated / IncompletePayment / InvalidCoupon /
  InvalidCustomer / InvalidCustomerBalanceTransaction / InvalidInvoice / InvalidPaymentMethod /
  SubscriptionUpdateFailure)。

### T131 で確定済み・蒸し返してはいけない判断
- `terminateInvoiceBestEffort()` は原例外を `report()` せず、`previous` にも繋がない。
- `tryTerminateInvoice()` を再利用しない (前者は「attempt 行へ永続化できなかった invoice」を
  引数で受ける必要があり、後者は `$attempt->stripe_invoice_id` を読むため)。
- 境界でのドメイン例外化は「独立 TODO 起票が妥当」と Codex 自身が同意して見送られた。
  本設計はその独立 TODO の設計である。

---

## 概念設計

（以下、`devnotes/20260807-1851-billing-gateway-error-taxonomy/conceptual-design.md` の全文）

# 概念設計: billing-gateway-error-taxonomy

> 一次入力: [`recon-brief.md`](./recon-brief.md)
> 出自: aicue:T131 (job-execution-deduplication 保証側実装) の Codex 合議で
> 「本 PR の範囲外・独立 TODO 起票が妥当」と決着した残課題 2 件。

## 背景・課題

`AutoRechargeService` は決済 gateway (`AutoRechargeGatewayInterface`) の失敗を
`catch (Throwable)` で受けるしかない。interface の docblock が
「Stripe 障害・設定不備は例外のまま伝播 (fail-closed)」と宣言しているためである。

その結果、**同一クラスの中で観測語彙が 2 系統に割れている**:

| 経路 | 行 | 記録している値 | 性質 |
|------|----|---------------|------|
| `terminateInvoiceBestEffort()` (T131 新設) | L705 / L712-720 | `$exception::class` (有界) + サニタイズ済み例外の `report()` | 有界な語彙のみ |
| `tryTerminateInvoice()` (姉妹経路) | L833 | `$e->getMessage()` | **外部生成の可変文字列** |
| `reconcile()` attempt 処理失敗 | L994 | `$e->getMessage()` | 同上 |
| `reconcile()` 取りこぼし起票失敗 | L1014 | `$e->getMessage()` | 同上 |

問題は 2 つある。

1. **次に触る人がどちらに倣うべきか決まらない**。T131 が「クラス名だけ記録する」形を
   選んだのは合議の結論だが、その判断は同じクラスの隣の 3 箇所へ適用されていない。
   規約として書かれていないので、次の実装者は隣の行を真似る。
2. **`$e->getMessage()` は外部サービスが生成する可変文字列**であり、構造化ログに
   何が混ざるかの契約が無い (T131 の Codex 指摘の本体)。3 箇所に残っている。

さらに、クラス名は「有界」ではあるが**分類ではない**。運用担当が知りたいのは
「待てば直るのか / 我々が直すのか / データを調べるのか」であって、
`Stripe\Exception\ApiConnectionException` という文字列そのものではない。

## 仮説

**検証したいこと**: 決済 gateway 由来の失敗を「呼び出し側が取れる行動」で 5 つに分類した
**有界な語彙 1 系統**に統一すれば、(a) 構造化ログから外部生成文字列が消え、
(b) 同一クラス内の観測語彙の分裂が解消し、(c) 次に触る人が倣うべき形が
機械 (Architecture gate) で決まる。

**成功判定**: 次の 4 つがすべて満たされること。
1. `AutoRechargeService` から `$e->getMessage()` が 0 件になる (禁止事項 3 = 旧語彙を並走させない)。
2. gateway を注入される app 側の全クラスが「観測する / 伝播させる」のどちらかに
   deny-by-default で分類され、未分類が fail する。
3. Stripe SDK / Cashier が定義する例外クラスが**すべて明示的に分類**されており、
   ライブラリ更新で未知クラスが増えたら**赤くなる** (無音で `unknown` に落ちない)。
4. **fake が本物と同じ分類を返す**ことが機械で固定される。
5. 課金の**制御フローが 1 バイトも変わらない** (前後の等価性を Feature テストで固定)。

## 改善アイデア

### 中心の判断: interface 契約は変えない。分類器 1 本に寄せる

ブリーフの論点 1 は「9 メソッド全部を変換するか / 取り消せない副作用の 3 つに絞るか」だが、
**そのどちらも採らない**。`AutoRechargeGatewayInterface` の契約は変更しない。

根拠は 3 つある。

- **変換だけでは閉じない**。`reconcile()` の 2 箇所は gateway 以外の例外
  (DB の `QueryException` / `Assert` の不変条件違反 / lock timeout) も受ける。
  境界でドメイン例外へ変換しても、この 2 箇所は依然として `catch (Throwable)` のままで、
  「ドメイン例外の分類」と「それ以外の何か」という**新しい 2 系統**が生まれる。
  本設計の目的 (語彙を 1 系統にする) と正面から衝突する。
- **3 gateway の非対称**。本 interface だけ契約が変わると
  「狭い gateway + gateway 単位の Fake bind」規約 (サブスク系 `StripeGatewayInterface` /
  チケット checkout 系 `TicketCheckoutGateway`) の中で 1 つだけ作法が違う状態になる。
  規約を揃えるなら 3 つ全部を変える必要があり、スコープが跳ねる (思考原則 2 に反する)。
- **必要なのは分類であって型ではない**。呼び出し側は分類で分岐しない (制御フローは変えない)。
  型に載せる価値は「分岐の網羅性を型で守れる」ことだが、分岐しない以上その価値は無い。

代わりに、**`Throwable` → 有界な分類 enum の純関数**を 1 本置き、
gateway 由来かどうかにかかわらず**観測点で分類する**。

### 分類語彙 (`GatewayFailureClass`)

Stripe の error code をそのまま採らない。**呼び出し側 / 運用担当が取れる行動**で切る。

| case | 意味 | 運用の行動 |
|------|------|-----------|
| `provider_unavailable` | 決済事業者側の一時障害・ネットワーク断・レート制限 | 待つ (リコンサイルが再試行する) |
| `provider_rejected` | 要求そのものが拒否された (不正リクエスト / 認証・権限 / 冪等キー衝突) | 設定かコードを直す。待っても直らない |
| `invariant_violation` | アプリの不変条件違反 (`Assert` / SDK の誤用 / 金額不一致) | 該当データとコードを調べる |
| `local_infrastructure` | 自 infra の失敗 (DB / cache lock) | 自インフラを調べる |
| `unknown` | 上記のどれにも当てはまらない | **分類器を直す** (この case が出ること自体が欠陥の通知) |

粒度をこれ以上細かくしない (思考原則 2)。case を足す条件は
「運用担当が取る行動が既存 case と異なる」ことに限る。

### 未知の扱い (ブリーフ論点 2 への結論)

**deny-by-default を「写像表の全域性」で実現し、実行時は `unknown` へ落とすが検出可能にする**
という二段構えにする。

- **設計時 (機械)**: Stripe SDK (`Stripe\Exception\` 直下) と Cashier
  (`Laravel\Cashier\Exceptions\`) が定義する**具象例外クラスをすべて明示的に分類**する。
  Architecture gate が vendor を走査して「写像表の集合 == 実在クラスの集合」を要求するため、
  ライブラリ更新で新しい例外クラスが増えた瞬間に**赤くなる**。
  つまり「外部の語彙が増えたら無音で `unknown` へ落ちる」という失敗モードを構造的に閉じる。
- **実行時 (アプリ)**: それでも未知の `Throwable` は来る (アプリ自身の新しい例外など)。
  ここで例外を投げると**課金の制御フローを変えてしまう**ため、`unknown` へ落とす。
  ただし**必ず `error_class` (例外クラス名 = 有界) を併記**するので、ログ基盤で
  `failure_class=unknown` を検索すれば分類器に足すべきクラスが一意に分かる。

**Stripe の error code / request id は採らない**。error code は外部語彙そのもので
増えたときに追随できず、request id は外部生成の文字列である。既に typed 結果で扱っている
カード拒否 (`OffSessionChargeResultDto::failureCode`) との二重管理にもなる。

### fake と本物の分類一致 (ブリーフ論点 4 への結論)

「fake が本物と違う例外を投げると、分類を使う経路がテストで一度も本物と同じ値を見ない」
という偽グリーンを、**fake が実ライブラリの例外クラスそのものを投げる**ことで閉じる。

- テスト用 spy (`Tests\Support\FakeAutoRechargeGateway`) の失敗注入を
  「分類を指定して投げる」形に変え、投げる実体は
  **共有 fixture (`Tests\Support\Billing\GatewayFailureFixtures`) が返す実ライブラリ例外**
  (`Stripe\Exception\ApiConnectionException` / `Webmozart\Assert\InvalidArgumentException` 等)
  にする。fake が独自の `RuntimeException` を投げる現状を消す。
- gate が (i) 全 case に fixture がある、(ii) `classify(fixture(case)) === case`、
  (iii) fixture が返すクラスが実ライブラリ名前空間に属する、(iv) spy のソースに
  fixture 経由でない `throw` が無い、を deny-by-default で固定する。
- runtime fake (`App\Services\Billing\Fakes\FakeAutoRechargeGateway`) は
  **例外を 1 つも投げない**契約 (bughunt 環境で決済を成立させない) なので、
  「`throw` を持たないこと」をソース走査で固定する = 分岐しようがない。

現状 spy の `terminateInvoice` は `RuntimeException` を投げ、本物は `Assert` 由来の
`Webmozart\Assert\InvalidArgumentException` を投げる。分類は前者が `unknown`、
後者が `invariant_violation` で**実際に食い違っている**。これは実在する偽グリーンである。

### (b) の 3 箇所 (ブリーフ論点 3 への結論)

**同じ PR で 3 箇所すべて直す**。interface を変えないと決めた以上、(a) の完成を待つ理由が無い
(「(a) から落ちてくる」という前提自体が消える)。禁止事項 3 (後方互換の並走を残さない) により、
`$e->getMessage()` を残したまま新語彙を足す形は採らない。
T131 が作った `terminateInvoiceBestEffort()` も同じ 2 キー (`failure_class` / `error_class`) へ揃える
(T131 で確定した「原例外を `previous` に繋がない / `report()` はサニタイズ済み例外だけ」という
性質は**維持する。蒸し返さない**)。

## 期待効果

- **使命への貢献 (間接)**: 使命は SOP からの動画マニュアル生成だが、その前提として
  「オートリチャージが静かに壊れない」ことが要る。分類が付くと、決済失敗の一次切り分けが
  ログ 1 行で終わり、現場の撮影が止まる時間が短くなる。
- **外部生成文字列がログ基盤に載らなくなる** (T131 が自経路で閉じた性質を、
  同じクラスの残り 3 経路へ広げる)。
- **次に触る人の判断が機械で決まる**。gateway を注入されるクラスを増やしたら、
  gate が「観測するのか伝播させるのか」を必ず問う。
- **ライブラリ更新で語彙が壊れたら CI が赤くなる** (今は無音で通る)。

## 実装方針（概要）

| # | 何を | どこに |
|---|------|--------|
| 1 | 分類 enum `GatewayFailureClass` (5 case) | `app/Enums/Billing/` (新規) |
| 2 | 純関数の分類器 + ログ context ヘルパ | `app/Support/Billing/GatewayFailureClassifier.php` (新規) |
| 3 | 免除分類 enum `GatewayFailureObservationExemption` | `app/Enums/Security/` (新規) |
| 4 | `AutoRechargeService` の 4 catch 箇所を分類器へ統一 (`getMessage()` 全廃) | 既存変更 |
| 5 | spy の失敗注入を fixture 経由へ / fixture 新設 | `tests/Support/Billing/` (新規) + 既存変更 |
| 6 | deny-by-default 目録 gate | `tests/Architecture/BillingGatewayFailureTaxonomyInventoryTest.php` (新規) |
| 7 | Feature テスト: 制御フロー等価性 + ログ語彙の固定 | 既存 `tests/Feature/Billing/` 更新 |
| 8 | 運用契約の記述 | `docs/architecture.md` + `AGENTS.md` ドメイン固有規約 |

制御フローは変えない。`catch` の構造・戻り値・状態遷移・Stripe 呼び出し回数はすべて現状のまま、
**ログの context と `report()` の文言だけ**が変わる。

## 制約・前提

- **PHPStan level 10** / Pest / `RefreshDatabase` グローバル適用 / `--parallel`。
- **課金の振る舞いを変えない**。分類は観測のためであり、制御フローを変えない
  (変える箇所は無い。等価性は Feature テストで固定する)。
- **T131 の確定判断を蒸し返さない**: `terminateInvoiceBestEffort()` の
  「原例外を `previous` に繋がない」「`report()` はサニタイズ済み例外のみ」
  「`tryTerminateInvoice()` を再利用しない (L671 の理由)」はそのまま維持する。
- **禁止事項 3**: 旧語彙 (`getMessage()`) は同じ PR で消す。
- `tests/Architecture/ExternalFakeWiringInvariantTest.php` の既存契約
  (runtime fake の厳密クラス一致 / provider の bind 組と inventory の集合一致) を壊さない。
  本設計は runtime fake の**クラスも bind も変えない**ため、既存 gate への影響は無い。
- **新しい gate は vendor ディレクトリを走査する**。`composer update` で
  stripe-php / cashier の例外クラスが増減すると CI が赤くなる。これは意図した副作用であり、
  「増えたことを人間に必ず知らせる」ための費用として受け入れる。

## スコープ外（意図的に外したもの）

| 外したもの | 理由 |
|-----------|------|
| `AutoRechargeGatewayInterface` の 9 メソッドの契約変更 (境界でのドメイン例外化) | 上記「中心の判断」の 3 根拠。特に reconcile が gateway 以外の例外も受けるため**変換だけでは閉じず**、語彙が 2 系統になる |
| 他 2 gateway (`StripeGatewayInterface` / `TicketCheckoutGateway`) への横展開 | 今そこに割れた語彙が無い。必要になってから広げる (思考原則 2)。分類器自体は依存を持たないので後から流用できる |
| 例外報告基盤 (`app/Exceptions/`) での横断 redact | 横断基盤の変更。T131 の合議で既に「スコープ外」と決着済み |
| Stripe の error code / decline code / request id の記録 | 外部語彙。カード拒否は既に `OffSessionChargeResultDto` の typed 結果で扱っており、二重管理になる |
| 分類による制御フローの分岐 (再試行の出し分け等) | 「分類は観測のため」が本設計の前提。分岐が要るなら別 TODO で、そのとき初めて型 (ドメイン例外) の価値が出る |
| `app/` 全体での `getMessage()` 禁止 | 自前ドメイン例外の固定文言を利用者向けに出す正当な用途が多数ある (`BillingController` 等)。走査対象は gateway 消費クラスの目録に限定する |
| `SubscriptionService` / `StripeWebhookProcessor` の `getMessage()` | 本 interface の消費者ではない。母集団の定義 (gateway を注入されるクラス) の外 |

