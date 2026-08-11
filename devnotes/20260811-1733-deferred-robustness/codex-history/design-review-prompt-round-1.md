# 詳細設計レビュー依頼 (deferred-robustness)

## アプリの使命 (North Star) — AGENTS.md より

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


## 禁止事項 — AGENTS.md より

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `->withMetadata($context->toMetadata())` で帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) は
   `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
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

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 13.18 + Svelte 5 + Inertia.js + TypeScript
- PostgreSQL 18.4 単一 (phpunit.xml が DB_CONNECTION=pgsql を force。sqlite/mysql の二重運用なし)
- PHPStan level 10
- Pest (RefreshDatabase をグローバル適用 + --parallel)
- DTO + JsonResource パターン
- Laratrust RBAC (Organization -> Team -> Project 階層)

【レビュー観点】
1. コードの正確性 (ロジックエラー、エッジケース、null 安全性)
2. 既存コードとの整合性 (命名規約、パターン、API)
3. PHPStan level 10 適合性
4. テスト計画の網羅性 (各施策に Pest テスト。個別 DatabaseTransactions 禁止)
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性
9. セキュリティ (認可、入力バリデーション、AGENTS.md のセキュリティ不変条件)
10. DESIGN.md 準拠 / 11. Atomic Design 準拠 (本設計に UI 変更は無いので該当しなければ「該当なし」でよい)

【本件固有の重要な前提 — 設計者からの申し送り】
- 本設計フェーズではアプリコードを1行も変更していない。実装は別TODO。
- 「横断的な既定値依存を禁止する gate」の新設は過去TODO(aicue:T151)の設計が
  「判定式が静的に書けず偽陽性で gate の信用を落とす」として却下済み。蒸し返さないこと。
- migration の default は消さない。
- fail-closed が既定。
- 2件を無理に共通化しない (層も直し方も違う)。
- 「今必要なものだけ作る」(AGENTS.md 思考原則2) を厳守。新しい抽象・新しい gate・新しい inventory の
  提案は、それが無いと壊れる具体的シナリオを添えること。添えられないなら Suggestion 止まりにすること。
- **特に厳しく見てほしい点**: (a) 再現テストが本当に「意図した1本の制約だけ」に当たるか、
  (b) 修正前に本当に赤くなるか、(c) mutation 手順が本当に非対称を観測できるか、
  (d) 「保証しないもの」に嘘や漏れが無いか。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: deferred-robustness (先送り堅牢性 2 件)

## 使命・制約(絶対遵守)

### アプリの使命(North Star) — AGENTS.md より転記

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須(`composer phpstan`)
- **Pest**(`composer test`)
- **RefreshDatabase** + `--parallel`(`tests/Pest.php` でグローバル適用。個別 `DatabaseTransactions` 禁止)
- **テストデータは必ず Factory で生成**(`Model::create()` 手組み禁止)。
  ただし「DB 制約に直接当てる」目的の行は `DB::table()->insert()` を使う
  (Model event を発火させず、検査を迂回して制約だけを試すため。既存
  `tests/Feature/Billing/AutoRechargeAttemptUniquenessTest.php` の先例に揃える)
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨
- `composer fix`(Pint)/ `pnpm lint:fix`
- PHP 8.4 + Laravel 13.18 + Svelte 5 + Inertia.js + TypeScript + **PostgreSQL 単一**

## 概念設計リファレンス

- `devnotes/20260811-1733-deferred-robustness/conceptual-design.md`(Round 1 APPROVED)
- 一次入力: `devnotes/20260811-1733-deferred-robustness/recon-brief.md`
- 合議履歴: `devnotes/20260811-1733-deferred-robustness/conceptual-review-round-1.md` /
  `codex-history/conceptual-review-decisions-round-1.md`

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1a | `startSetupCheckout()` の unique 握りを制約名で絞る | `app/Services/Billing/AutoRechargeService.php` | High |
| 1b | `maybeCreateAttempt()` の unique 握りを制約名で絞る + `isUniqueViolation()` 削除 | `app/Services/Billing/AutoRechargeService.php` | High |
| 1c | `SubscriptionService::startCheckout()` を宣言済み契約に合わせる | `app/Services/Billing/SubscriptionService.php` | Medium |
| 2 | `TakeUploadService::issue()` の初期 `status` を明示代入 | `app/Services/Capture/TakeUploadService.php` | Medium |
| 3 | 契約の文書化(AGENTS.md ドメイン規約 2 / docs/architecture.md) | `AGENTS.md` / `docs/architecture.md` | Low |

**新規モデルなし** = Factory 追加は不要。**インターフェース変更なし** = TypeScript / Inertia Props /
JsonResource への波及なし(各施策の「波及変更」欄で個別に宣言する)。

---

## 前提の実測(設計の土台。推測を含まない)

### E-1. PostgreSQL の例外から制約名は取れる。ただし構造化フィールドではない

実 DB(PostgreSQL **18.4**、`lc_messages=en_US.utf8`、`server_encoding=UTF8`)に対し
PDO で unique 違反を起こして実測した(TEMP 表のみ使用。永続表に触れていない):

```
getCode          = '23505'   (string)
count(errorInfo) = 3
errorInfo        = [
  0 => '23505',
  1 => 7,
  2 => 'ERROR:  duplicate key value violates unique constraint "probe_a_expected_unique"
        DETAIL:  Key (id)=(1) already exists.',
]
```

- **`errorInfo` は 3 要素しかない**。libpq の `PG_DIAG_CONSTRAINT_NAME` は PDO_pgsql から
  構造化フィールドとして露出していない。**制約名は `errorInfo[2]` の本文にしか無い**
- **部分 UNIQUE index**(`CREATE UNIQUE INDEX ... WHERE status='pending'` =
  `tar_attempts_org_pending_unique` と同型)でも同じ形で名前が出る:
  `... violates unique constraint "probe_p_org_pending_unique"`
- **PRIMARY KEY 違反も 23505** で `"probe_c_pkey"` が出る
- NOT NULL は **23502** で別コード(unique とは混ざらない)

→ **結論: 取れる。ただし自前パーサは書かない。**

### E-2. Laravel 13 が既にパースしている(先人の知恵)

`vendor/laravel/framework/src/Illuminate/Database/Connection.php` L849-873:

```php
$exceptionType = ($isUniqueConstraintError = $this->isUniqueConstraintError($e))
    ? UniqueConstraintViolationException::class
    : QueryException::class;
$exception = new $exceptionType(...);
if ($isUniqueConstraintError) {
    ['index' => $index, 'columns' => $columns] = $this->parseUniqueConstraintViolation($e);
    $exception->setIndex($index)->setColumns($columns);
}
throw $exception;
```

`vendor/.../PostgresConnection.php` L78-103:

```php
protected function isUniqueConstraintError(Exception $exception)
{
    return '23505' === $exception->getCode();
}

protected function parseUniqueConstraintViolation(Exception $exception): array
{
    [$index, $columns] = [null, []];
    if (preg_match('#unique constraint "([^"]+)"#i', $message = $exception->getMessage(), $matches)) {
        $index = $matches[1];
    }
    if (preg_match('#Key \(([^)]+)\)=#i', $message, $matches)) {
        $columns = array_map(trim(...), explode(',', $matches[1]));
    }
    return ['columns' => $columns, 'index' => $index];
}
```

`vendor/.../UniqueConstraintViolationException.php`:

```php
class UniqueConstraintViolationException extends QueryException
{
    public ?string $index = null;      // ← string|null
    public array $columns = [];        // ← list<string>
}
```

- `UniqueConstraintViolationException` は **`QueryException` の子**なので、
  現在の `catch (QueryException $e)` はこれも捕まえている(= 既存の握り潰しの正体)
- `Model::save()` の INSERT は `Connection::insert` → `statement` → `run` →
  `runQueryCallback` を通るので、この経路に**必ず乗る**

### E-3. 期待する制約名は実 DB に実在する(実測)

`pg_indexes` を実 DB(migrate 済みテスト DB)で照会した結果:

| テーブル | index 名 |
|---|---|
| `billing_checkout_sessions` | `billing_checkout_sessions_pkey` / `..._idempotency_key_unique` / **`billing_checkout_sessions_org_intent_attempt_unique`** / `..._organization_id_intent_status_index` / `..._stripe_session_id_unique` |
| `ticket_auto_recharge_attempts` | `ticket_auto_recharge_attempts_pkey` / `..._attempt_ulid_unique` / `..._stripe_invoice_id_unique` / `..._organization_id_status_index` / `..._status_created_at_index` / **`tar_attempts_org_pending_unique`** |
| `take_upload_reservations` | `take_upload_reservations_pkey` / `..._cut_id_client_take_id_index` / `take_upload_reservations_organization_id_status_expires_at_inde` |

**最後の 1 本に注目**: PostgreSQL は識別子を **63 バイトで黙って切る**(`..._index` の `x` が落ちている)。
今回期待する 2 本は 51 / 31 文字で無事だが、**この事実は設計の前提として明記する**。

### E-4. `take_upload_reservations.status` の DB 既定値(実測)

```
column_default = '\'pending\'::character varying'   nullable = NO
```

`TakeUploadReservation::casts()` は `'status' => TakeUploadReservationStatus::class` を宣言済み。
`$fillable` に `status` は**無い**(保護状態列)。

### E-5. `$index` が取れない条件(fail-closed で扱う)

| 条件 | `$index` | 扱い |
|---|---|---|
| pgsql + unique / PK 違反 | 制約名 | 期待名と一致すれば握る |
| pgsql + exclusion 制約 (23505) | `null`(本文が `exclusion constraint`) | **再送出** |
| pgsql + 翻訳カタログのあるロケール | `null` になりうる | **再送出** |
| sqlite | **常に `null`**(`SQLiteConnection::parseUniqueConstraintViolation` が `'index' => null` 固定。vendor L69-83) | **再送出** |

本リポジトリは `phpunit.xml` が `<server name="DB_CONNECTION" value="pgsql" force="true"/>` で
pgsql を強制し、`ticket_auto_recharge_attempts` の migration 自身が非 pgsql/sqlite driver で
`RuntimeException` を投げる。**テストも本番も pgsql 単一**。

---

## 施策 1a: `startSetupCheckout()` の unique 握りを制約名で絞る

### 変更箇所
- ファイル: `app/Services/Billing/AutoRechargeService.php` (L286-311 / import 部 / const 追加)

### 波及変更
- TypeScript 型定義: **なし**(戻り値 `array{id: string, url: string|null}` は不変)
- API Resource/DTO: **なし**
- テストファイル: `tests/Feature/Billing/AutoRechargeSetupCheckoutUniquenessTest.php`(**新規**)

### 現行コード

```php
        try {
            DB::transaction(function () use ($organization, $user, $result, $attemptToken, $idempotencyKey): void {
                $session = new BillingCheckoutSession;
                $session->organization()->associate($organization);
                $session->initiated_by_user_id = $user->id;
                $session->fill([...]);
                $session->save();
            });
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }
            // 同一 attempt_token の replay — Stripe 側も同一冪等キーで同一 session を返している。
        }

        return $result;
```

### 変更後コード

```php
    /**
     * 期待する unique 制約 (この 1 本だけを replay として握る)。
     * unique(organization_id, intent, attempt_token) の index 名。
     * 実 DB の識別子と literal 一致していることは AutoRechargeSetupCheckoutUniquenessTest が固定する
     * (名前が drift すると「握るはずの違反が再送出される」形で赤くなる)。
     */
    private const string CHECKOUT_ATTEMPT_TOKEN_UNIQUE = 'billing_checkout_sessions_org_intent_attempt_unique';
```

```php
        // 台帳記録 (webhook の intent 照合 / setupPending 判定の出典)。attempt_token unique で
        // 二重 submit は冪等 (この 1 本の unique 違反だけを既存行の再利用として握る)。
        // insert は DB::transaction (= 外側 TX 下では savepoint) で包む — unique violation が
        // 呼び出し元 TX を abort させない (pgsql の 25P02 連鎖を避ける)。
        try {
            DB::transaction(function () use ($organization, $user, $result, $attemptToken, $idempotencyKey): void {
                $session = new BillingCheckoutSession;
                // tenant / actor キーは relation 経由で明示代入する (mass assignment しない)
                $session->organization()->associate($organization);
                $session->initiated_by_user_id = $user->id;
                $session->fill([
                    'intent' => CheckoutIntent::SetupPaymentMethod->value,
                    'plan_code' => null,
                    'stripe_session_id' => $result['id'],
                    'status' => CheckoutSessionStatus::Pending->value,
                    'idempotency_key' => $idempotencyKey,
                    'attempt_token' => $attemptToken,
                    'checkout_url' => $result['url'],
                ]);
                $session->save();
            });
        } catch (UniqueConstraintViolationException $e) {
            // fail-closed: 期待した 1 本以外の unique 違反 (stripe_session_id / idempotency_key /
            // pkey) と、制約名を特定できなかった場合 ($e->index === null) は握らず再送出する。
            // 握ると「Stripe session はあるのに台帳行が無い」状態が正常終了として通ってしまう。
            if ($e->index !== self::CHECKOUT_ATTEMPT_TOKEN_UNIQUE) {
                throw $e;
            }
            // 同一 attempt_token の replay — Stripe 側も同一冪等キーで同一 session を返している。
        }

        return $result;
```

import 追加: `use Illuminate\Database\UniqueConstraintViolationException;`

### なぜ `catch (QueryException)` をやめるのか
`UniqueConstraintViolationException` は `QueryException` の子なので、
`catch (UniqueConstraintViolationException)` に狭めると**非 unique の `QueryException` は
そもそも捕まらず素通りする**。旧コードの `if (! isUniqueViolation) throw` と等価であり、
SQLSTATE 判定を書く必要がなくなる(思考原則 3: 後方互換の並走を残さない)。

### PHPStan 適合チェック
- [x] 戻り値の型が明示されている(`array{id: string, url: string|null}`、変更なし)
- [x] null 安全: `$e->index` は `?string`。`!==` による string 比較で **null は自動的に再送出側へ落ちる**
      (`null !== 'billing_...'` は true)。`??` や `(string)` で潰さない
- [x] DTO を返している(本メソッドは既存の shape 配列。変更なし)
- [x] Generics の型パラメータ: 対象なし
- [x] `private const string` で PHP 8.3+ の型付き定数を使う(既存 migration の
      `private const string PENDING_INDEX_NAME` と同じ書き方)

### テスト計画
- [x] バグ修正のため**再現テストを先に書く**(下記「赤化手順 R-1a」)
- [ ] 新規 `tests/Feature/Billing/AutoRechargeSetupCheckoutUniquenessTest.php`
  1. `別の unique 制約 (stripe_session_id) の違反は握り潰さず再送出する`
     — **修正前は赤**
  2. `同一 attempt_token の replay は従来どおり例外を漏らさず $result を返す`
     — **修正前も後も緑**(成功時の振る舞いを変えていないことの固定)
- [ ] 既存 `tests/Feature/Billing/SubscriptionCheckoutIdempotencyTest.php` は変更不要
      (施策 1c で 1 テスト追加)
- [x] 個別の `DatabaseTransactions` を使わない

### リスク
- **これまで黙って通っていた障害が例外として表面化する**。これは意図した効果である
  (fail-closed)。ユーザー向けエラー文言は追加も変更もしない = **観測可能性の改善であって
  UX 改善ではない**
- 制約名が将来 rename された場合、replay が握られず 500 になる。
  → テスト 2(replay 経路)が赤くなるので検出できる

---

## 施策 1b: `maybeCreateAttempt()` の unique 握りを制約名で絞る

### 変更箇所
- ファイル: `app/Services/Billing/AutoRechargeService.php` (L508-516 / L1501-1507 削除 / const 追加)

### 波及変更
- TypeScript 型定義: **なし**
- API Resource/DTO: **なし**(戻り値 `?TicketAutoRechargeAttempt` は不変)
- テストファイル: `tests/Feature/Billing/AutoRechargeAttemptUniquenessTest.php`(**既存に追記**)

### 現行コード

```php
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                // DB partial unique (tar_attempts_org_pending_unique) が最終防衛。並行起票は no-op。
                return null;
            }

            throw $e;
        }
```

```php
    private function isUniqueViolation(QueryException $e): bool
    {
        // driver 差吸収 (23505 = pgsql / 23000 = sqlite・mysql)。
        $sqlState = $e->errorInfo[0] ?? null;

        return $sqlState === '23505' || $sqlState === '23000';
    }
```

### 変更後コード

```php
    /**
     * 期待する unique 制約 (この 1 本だけを並行起票の敗者として握る)。
     * 部分 UNIQUE index (WHERE status = 'pending') = 「org に pending は同時に 1 つまで」。
     * 名前の正本は create_ticket_auto_recharge_attempts_table migration の PENDING_INDEX_NAME。
     */
    private const string ATTEMPT_ORG_PENDING_UNIQUE = 'tar_attempts_org_pending_unique';
```

```php
        } catch (UniqueConstraintViolationException $e) {
            if ($e->index === self::ATTEMPT_ORG_PENDING_UNIQUE) {
                // DB partial unique が最終防衛。並行起票は no-op。
                return null;
            }

            // fail-closed: attempt_ulid / stripe_invoice_id / pkey の違反と、制約名を
            // 特定できなかった場合 ($e->index === null) は握らない。握ると
            // AutoRechargeTriggerJob が structured no-op として黙り、その組織のリチャージが
            // 起票されないまま誰も気づかない。
            throw $e;
        }
```

`private function isUniqueViolation(QueryException $e): bool` は **メソッドごと削除**する
(他に呼び出し元が無いことは施策実装時に `grep -n 'isUniqueViolation' app/Services/Billing/AutoRechargeService.php`
で確認する)。`use Illuminate\Database\QueryException;` が他所で未使用になれば import も落とす
(Pint / PHPStan が検出する)。

### PHPStan 適合チェック
- [x] 戻り値 `?TicketAutoRechargeAttempt` は不変
- [x] `$e->index` は `?string`。`===` 比較で null は else 側(再送出)へ落ちる
- [x] 未使用 private メソッドを残さない(削除する)
- [x] 未使用 import を残さない

### テスト計画
- [x] 再現テストを先に書く(下記「赤化手順 R-1b」)
- [ ] `tests/Feature/Billing/AutoRechargeAttemptUniquenessTest.php` に追記:
  - `別の unique 制約 (attempt_ulid) の違反は no-op へ収束させず再送出する` — **修正前は赤**
- [x] 既存 3 テスト(pending 検査 / DB 制約が最終防衛 / unique violation の no-op 収束)は
      **すべて緑のまま**であること = 期待制約側の振る舞いを変えていない証拠
- [x] 個別の `DatabaseTransactions` を使わない

### リスク
- 既存テスト `unique violation は no-op へ収束し呼び出し側へ例外が漏れない` が
  **期待制約に当たっていること**が前提になる。`creating` フックが同 org の pending 行を
  入れているので `tar_attempts_org_pending_unique` に当たる。**修正後も緑であることを
  実測で確認する**(緑のままなら前提は正しい。赤くなったら前提が誤りなので設計を見直す)

---

## 施策 1c: `SubscriptionService::startCheckout()` を宣言済み契約に合わせる

### 変更箇所
- ファイル: `app/Services/Billing/SubscriptionService.php` (L469-473 / L546-556 / const 追加)

### 波及変更
- TypeScript 型定義: **なし**
- API Resource/DTO: **なし**(`CheckoutSessionDto` / `StaleCheckoutAttemptException` は不変)
- テストファイル: `tests/Feature/Billing/SubscriptionCheckoutIdempotencyTest.php`(**既存に追記**)

### 現行コード

```php
        } catch (UniqueConstraintViolationException $e) {
            // 段 6: 並行 race。unique(org, intent, attempt_token) 違反 → 既存を再読込して収束する
            // (attempt_token 以外の unique 違反は rethrow = 500 に落として調査対象にする)。
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }
            $row = $this->subscriptionAttemptQuery($org)->where('attempt_token', $attemptToken)->latest('id')->first();
            if ($row instanceof BillingCheckoutSession && $this->isReplayableCheckout($row, CarbonImmutable::now())) {
                return $this->replayCheckout($row);
            }
            throw new StaleCheckoutAttemptException('契約手続きの有効期限が切れました。画面を再読み込みして再試行してください。');
        }
```

```php
    private function isUniqueViolation(QueryException $e): bool
    {
        if (! in_array($e->getCode(), ['23000', '23505'], true)) {
            return false;
        }
        ...
    }
```

**問題**: `catch` が既に `UniqueConstraintViolationException` なので `isUniqueViolation()` は
常に true を返す(pgsql の code は必ず `'23505'`)。**docblock が宣言している
「attempt_token 以外の unique 違反は rethrow」は実装されていない**。
実際には `stripe_session_id` 衝突が `StaleCheckoutAttemptException`
(「契約手続きの有効期限が切れました」)へ化け、ユーザーには無関係な文言が出て
DB 整合性異常が隠れる。

### 変更後コード

```php
    /** 段 6 で握ってよい唯一の unique 制約 (unique(organization_id, intent, attempt_token) の index 名)。 */
    private const string CHECKOUT_ATTEMPT_TOKEN_UNIQUE = 'billing_checkout_sessions_org_intent_attempt_unique';
```

```php
        } catch (UniqueConstraintViolationException $e) {
            // 段 6: 並行 race。unique(org, intent, attempt_token) 違反 → 既存を再読込して収束する。
            // それ以外の unique 違反 (stripe_session_id / idempotency_key / pkey) と、制約名を
            // 特定できなかった場合 ($e->index === null) は rethrow = 500 に落として調査対象にする
            // (StaleCheckoutAttemptException に化けさせない)。
            if ($e->index !== self::CHECKOUT_ATTEMPT_TOKEN_UNIQUE) {
                throw $e;
            }
            ...
        }
```

`private function isUniqueViolation(QueryException $e): bool` は **メソッドごと削除**する
(他に呼び出し元が無いことを実装時に grep で確認)。

**同じ定数名 `CHECKOUT_ATTEMPT_TOKEN_UNIQUE` が 1a と 1c の 2 クラスに出る**。
**共通化しない**(思考原則 2 / 4)。理由:
- 「同じ表の同じ index を指す」だけで、**握る理由も握った後の処理も違う**
  (1a は無言 replay / 1c は既存行を読み直して `replayCheckout` か `StaleCheckoutAttemptException`)
- 共有 const を置く器(enum / value object)を新設すると、
  「制約名の台帳」という**新しい概念**が生まれる。今必要ない
- 実 DB の名前とずれたら**双方の behavioral テストが独立に赤くなる**

### PHPStan 適合チェック
- [x] `$e->index` は `?string`。null は再送出側へ落ちる
- [x] 未使用 private メソッド / import を残さない
- [x] `CheckoutSessionDto` を返す既存の型は不変

### テスト計画
- [x] 再現テストを先に書く(下記「赤化手順 R-1c」)
- [ ] `tests/Feature/Billing/SubscriptionCheckoutIdempotencyTest.php` に追記:
  - `attempt_token 以外の unique 違反は StaleCheckoutAttemptException に化けず再送出する`
    — **修正前は赤**(`StaleCheckoutAttemptException` が飛ぶ)
- [x] 既存の replay / stale 系テストは緑のまま

### リスク
- 既存テストのうち「stale 判定」を通すものが、実は別制約違反経由で通っていた場合に赤くなる。
  その場合は**テストの前提が誤っていた**ことの発見なので、テスト側を直す
  (既存テストの削除・上書きはしない。前提コメントを直して原因側に当たるよう修正する)

---

## 施策 2: `TakeUploadService::issue()` の初期 `status` を明示代入

### 変更箇所
- ファイル: `app/Services/Capture/TakeUploadService.php` (L76-84)

### 波及変更
- TypeScript 型定義: **なし**(`TakeUploadTicketData` の shape は不変。API 応答も不変)
- API Resource/DTO: **なし**(`UploadTicketClaims::fromReservation()` は `status` を読まない)
- テストファイル: `tests/Feature/Capture/TakeUploadUrlTest.php`(**既存に追記**)
- Factory: **なし**(`TakeUploadReservationFactory` は既に `'status' => Pending->value` を明示済み)
- migration: **触らない**(`default('pending')` は残す)

### 現行コード

```php
            $reservation = $lockedCut->uploadReservations()->make([
                'client_take_id' => $input->clientTakeId,
                'video_path' => $path,
                'size_bytes' => $input->sizeBytes,
                'content_type' => $input->contentType,
                'checksum_sha256' => $input->checksum->base64,
                'expires_at' => $expiresAt,
            ]);
            $reservation->forceFill(['organization_id' => $lockedOrg->id])->save();
```

### 変更後コード

```php
            $reservation = $lockedCut->uploadReservations()->make([
                'client_take_id' => $input->clientTakeId,
                'video_path' => $path,
                'size_bytes' => $input->sizeBytes,
                'content_type' => $input->contentType,
                'checksum_sha256' => $input->checksum->base64,
                'expires_at' => $expiresAt,
            ]);
            // organization_id は保護キー、status は保護状態列のため $fillable 外 (forceFill で代入)。
            // status は**初期状態の明示代入**であり状態遷移ではない (AGENTS.md ドメイン規約 2 の
            // 「直接 UPDATE を書かない」は pending→verifying 以降の CAS の話。ドメイン規約 1 (ii) と
            // 同じ理由で、DB カラム default に依存すると (a) migration default 変更でこの経路の
            // 意味だけが黙って変わり (b) save() 直後の in-memory instance の status が null になる)。
            $reservation->forceFill([
                'organization_id' => $lockedOrg->id,
                'status' => TakeUploadReservationStatus::Pending,
            ])->save();
```

import 追加: `use App\Enums\Capture\TakeUploadReservationStatus;`

### cast との整合(Codex Round 1 [Warning] への対応)

`app/Models/TakeUploadReservation.php`:

```php
protected function casts(): array
{
    return [
        'status' => TakeUploadReservationStatus::class,
        'expires_at' => 'datetime',
    ];
}
```

enum インスタンスを渡してよい。DB へは backing value(`'pending'`)が書かれ、
読み戻すと enum になる。テストは **in-memory instance が enum であること**と
**DB 実値が `'pending'` であること**の両方を固定する(往復が効いていることの確認)。

### `$fillable` に入れない理由
`status` は保護状態列である。Model docblock が
「status 遷移は `TakeUploadService`(insert)/ `TakeRegistrationService`(claim/CAS)/
`StaleUploadReservationSweeper`(released 化)のみが行う」と宣言しており、
`$fillable` へ入れると `make([...])` 経由で外部入力から到達しうる形になる。
aicue:T151 の `VideoManualService` も同じ理由で `forceFill` を使っている。

### PHPStan 適合チェック
- [x] 戻り値 `TakeUploadTicketData` は不変
- [x] `forceFill(array<string, mixed>)` に enum を渡すのは cast 定義と一致
- [x] `Assert` 追加不要(null になり得る値を新たに導入しない)
- [x] DTO を返している(変更なし)

### テスト計画
- [x] 再現テストを先に書く(下記「赤化手順 R-2」)
- [ ] `tests/Feature/Capture/TakeUploadUrlTest.php` に追記:
  - `issue() が保持する予約インスタンスは refresh なしで status=pending を持つ`
    — **修正前は赤**
- [x] 既存 `発行成功: pending 予約が作成され bytes_pending に計上、...` は
      `$cut->uploadReservations()->sole()`(**DB からの再読込**)なので修正前も後も緑。
      **この既存テストがあるのに defect が生き残っていた事実**を、新テストのコメントに書く
- [x] `TakeUploadReservationModelTest` / `StorageUsageServiceTest` /
      `StaleReservationSweepTest` / `TakeRegistrationTest` は変更不要(挙動不変)
- [x] 個別の `DatabaseTransactions` を使わない

### リスク
- **挙動不変**。DB に入る値は同じ、API 応答も同じ、`bytes_pending` 集計も同じ。
  唯一変わるのは in-memory instance が属性を持つこと
- `forceFill` の第 2 キー追加は `organization_id` の代入順序に影響しない(同一配列)

---

## 施策 3: 契約の文書化

### 変更箇所
- `AGENTS.md` ドメイン規約 2(容量 Quota の予約規約)に 1 文追加
- `docs/architecture.md` §撮影 PWA (L968-) の presigned 直アップロード項に 1 節追加

### 変更後(AGENTS.md ドメイン規約 2)

現行末尾:
> 予約 (`take_upload_reservations`) の状態遷移は pending→verifying (claim)→completed/released の
> CAS で行い、直接 UPDATE を書かない。

追加:
> **初期状態 `pending` は INSERT 時に明示代入する**(`TakeUploadService::issue()`。
> DB カラム default に依存しない = migration default 変更による silent break と、
> `save()` 直後の in-memory instance の属性欠落の両方を防ぐ。ドメイン規約 1 (ii) と同じ理由)。
> **これは状態遷移ではないので上の CAS 規約とは独立である**。
> migration の `default('pending')` は既存行と Factory 以外の INSERT 経路のために残す。

### 変更後(docs/architecture.md §撮影 PWA)

`take_upload_reservations` (pending) を予約 …… の箇所に括弧書きで追加:
> (**初期 status は INSERT 時に明示代入**。DB カラム default は保険として残すが、
> この経路の意味は default に依存しない)

### 波及変更
- TypeScript 型定義 / API Resource / テスト: **なし**(文書のみ)

### PHPStan 適合チェック
- 対象外(Markdown)

### テスト計画
- **新規 Architecture テストは作らない**。理由は「保証しないもの」§3 のとおり。
  文書は施策 2 の behavioral テストが守る契約の**説明**であって、それ自体は gate ではない

### リスク
- 文書と実装が将来ずれる可能性。→ 施策 2 のテストが実装側を固定するので、
  ずれたときはテストが赤くなる方が先に来る

---

## 再現テストを先に赤にする手順(fail-first)

**必ずこの順で行う**(AGENTS.md 思考原則 5: テストファースト)。
実測ログは `devnotes/20260811-1733-deferred-robustness/red-before-fix.txt` に残す
(aicue:T151 の同名ファイルと同じ書式: 実行コマンド・時刻・HEAD・生出力・判定)。

### R-1a: `startSetupCheckout` が**別の unique 制約**を握り潰すことの再現

**狙い**: 期待制約(`..._org_intent_attempt_unique`)ではなく
`billing_checkout_sessions_stripe_session_id_unique` **だけ**に当てる。

`Tests\Support\FakeAutoRechargeGateway::createSetupCheckout()` は
`'id' => 'cs_setup_'.substr(hash('sha256', $idempotencyKey), 0, 24)` と
**idempotency key から決定的に**session id を導出する。この性質を使う:

```php
test('別の unique 制約 (stripe_session_id) の違反は握り潰さず再送出する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $gateway = new FakeAutoRechargeGateway;
    app()->instance(AutoRechargeGatewayInterface::class, $gateway);
    $service = app(AutoRechargeService::class);
    $token = (string) Str::ulid();

    // 1 回目: 正規に台帳行を作る (session id S は token から決定的に導出される)
    $service->startSetupCheckout($organization, $owner, 'https://ok.test', 'https://ng.test', $token);
    $row = BillingCheckoutSession::query()->sole();

    // 2 回目が「stripe_session_id **だけ**衝突する」状況を作る:
    //   既存行の attempt_token / idempotency_key を別値へ退避し、stripe_session_id は S のまま残す。
    //   → 同じ $token で再実行すると (org, intent, attempt_token) と idempotency_key は衝突せず、
    //      stripe_session_id だけが衝突する。
    DB::table('billing_checkout_sessions')->where('id', $row->id)->update([
        'attempt_token' => (string) Str::ulid(),
        'idempotency_key' => 'unrelated:'.Str::ulid(),
    ]);

    expect(fn () => $service->startSetupCheckout($organization, $owner, 'https://ok.test', 'https://ng.test', $token))
        ->toThrow(UniqueConstraintViolationException::class);
});
```

- **修正前 (現状)**: `isUniqueViolation()` が true を返して例外を捨てるため
  `startSetupCheckout` は正常終了する →
  `Failed asserting that exception of type "Illuminate\Database\UniqueConstraintViolationException" is thrown.`
  = **赤**
- **修正後**: `$e->index === 'billing_checkout_sessions_stripe_session_id_unique'` は
  期待名と一致しないので再送出 = **緑**

**なぜハッシュを自前計算しないか**: fake の導出式をテストに写すと、fake を変えたとき
テストが「別の理由で」壊れる。1 回目を実行して S を DB に作らせ、衝突しない列だけを
退避する方式なら、**導出式に依存しない**。

**ペアの緑テスト**(同ファイル):

```php
test('同一 attempt_token の replay は例外を漏らさず結果を返す (成功時の振る舞いは不変)', function (): void {
    // ... 同じ $token で 2 回呼ぶ。例外は飛ばず、行は 1 件のまま
});
```
これは**修正前後とも緑**。「成功時の挙動を変えていない」ことの固定である。

### R-1b: `maybeCreateAttempt` が**別の unique 制約**を no-op へ収束させることの再現

**狙い**: `tar_attempts_org_pending_unique` ではなく
`ticket_auto_recharge_attempts_attempt_ulid_unique` **だけ**に当てる。

```php
test('別の unique 制約 (attempt_ulid) の違反は no-op へ収束させず再送出する', function (): void {
    [$organization] = attemptUniquenessContext();
    [$otherOrganization] = createOrganizationWithOwner();

    // pending 検査の**後**・INSERT の**直前**に、**別 org**で**同じ attempt_ulid** の行を作る。
    // 別 org なので部分 unique (org 単位) には触れず、attempt_ulid unique **だけ**が違反する。
    // DB::table は model event を発火しないため再入しない。
    TicketAutoRechargeAttempt::creating(function (TicketAutoRechargeAttempt $attempt) use ($otherOrganization): void {
        DB::table('ticket_auto_recharge_attempts')->insert([
            'organization_id' => $otherOrganization->getKey(),
            'attempt_ulid' => $attempt->attempt_ulid,   // ← 衝突させたい 1 本
            'status' => AutoRechargeAttemptStatus::Pending->value,
            'quantity' => 10, 'unit_amount' => 70, 'stripe_price_id' => 'price_other',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    expect(fn () => app(AutoRechargeService::class)->maybeCreateAttempt($organization))
        ->toThrow(UniqueConstraintViolationException::class);
});
```

- **修正前**: `isUniqueViolation()` が true → `return null` で握り潰し →
  `Failed asserting that exception ... is thrown.` = **赤**
- **修正後**: `$e->index` が `ticket_auto_recharge_attempts_attempt_ulid_unique` で
  期待名と不一致 → 再送出 = **緑**

**model event listener の後始末**: 登録した `creating` クロージャは
`Illuminate\Database\DatabaseServiceProvider::boot()` が
`Model::setEventDispatcher($this->app['events'])` を毎テストの app 再生成時に張り直すため、
**次テストへ漏れない**(既存 `AutoRechargeAttemptUniquenessTest` の 3 テスト目が
同じ方式で既に成立していることが実証になっている)。

### R-1c: `SubscriptionService` が別制約違反を `StaleCheckoutAttemptException` へ化けさせる再現

`SubscriptionCheckoutIdempotencyTest` の既存 helper を使い、R-1a と同型
(`stripe_session_id` だけを衝突させる)で:

```php
expect(fn () => $service->startCheckout(...))
    ->toThrow(UniqueConstraintViolationException::class);
```

- **修正前**: `StaleCheckoutAttemptException`(「契約手続きの有効期限が切れました」)が飛ぶ →
  期待した型と違うので **赤**
- **修正後**: `UniqueConstraintViolationException` が再送出される = **緑**

**注意**: 既存テスト L340 のコメントが
「実 DB の UNIQUE で再現する(例外の自作注入ではない = isUniqueViolation() …)」と
`isUniqueViolation` に言及している。削除後に**このコメントも更新する**
(テスト本体は削除・上書きしない)。

### R-2: 予約行の初期 `status` が in-memory で `null` であることの再現

`issue()` は `TakeUploadTicketData` を返し予約行を返さないため、aicue:T151 の
「戻り値の status を読む」をそのまま写せない。**service が保持している当のインスタンスを
`created` フックで捕まえて**読む。

```php
test('issue() が保持する予約インスタンスは refresh なしで status=pending を持つ', function (): void {
    [$organization, $owner, $project, $manual, $cut] = uploadUrlContext();
    mockPresign();

    /** @var TakeUploadReservation|null $captured */
    $captured = null;
    TakeUploadReservation::created(function (TakeUploadReservation $reservation) use (&$captured): void {
        $captured = $reservation;   // service が save() した当のインスタンス
    });

    $this->actingAs($owner)->postJson(uploadUrlPath($project, $manual, $cut), uploadUrlPayload())->assertOk();

    // in-memory: 明示代入していないと属性ごと存在せず null になる (DB default では埋まらない)。
    // 既存テスト「発行成功: pending 予約が作成され…」は uploadReservations()->sole() = **DB 再読込**
    // なので DB default で緑になり、この欠落を検出できなかった。
    expect($captured)->not->toBeNull();
    expect($captured->status)->toBe(TakeUploadReservationStatus::Pending);
    // enum→backing value の往復が効いていること (DB 実値も pending)
    expect(DB::table('take_upload_reservations')->where('id', $captured->id)->value('status'))->toBe('pending');
});
```

- **修正前**: `Failed asserting that null is identical to an object of class
  "App\Enums\Capture\TakeUploadReservationStatus".` = **赤**
  (aicue:T151 の `red-before-fix.txt` と**同一の形**)
- **修正後**: 緑。DB 実値の assertion も緑(修正前も緑 = default が効いている)

---

## mutation で赤化を確認する手順

実測ログは `devnotes/20260811-1733-deferred-robustness/mutation.txt` に残す
(aicue:T151 の `gate-mutation.txt` と同じ書式。**実測値のみ**を書き、推測を書かない)。

**同時に 2 箇所を壊さない**。1 箇所ずつ壊して「どの assertion がどの実装行を守っているか」の
1:1 対応を確認する。

### M-1: 施策 1a の const を別名に差し替える
`self::CHECKOUT_ATTEMPT_TOKEN_UNIQUE` の値を `'nonexistent_unique'` に変える。
```
composer test -- --filter=AutoRechargeSetupCheckoutUniquenessTest
```
期待: **replay テストが赤**(期待制約が一致せず再送出される)/
**別制約テストは緑のまま**(元々一致しないため)。
→ **const の値が load-bearing** であることの実証。

### M-2: 施策 1a の `if ($e->index !== self::…) throw $e;` を削除する
期待: **別制約テストが赤**(握り潰しが復活)/ **replay テストは緑のまま**。
→ 非対称を実測で観測する。

### M-3: 施策 1b の `if ($e->index === self::…)` を `if (true)` に変える
```
composer test -- --filter=AutoRechargeAttemptUniquenessTest
```
期待: **`attempt_ulid` テストのみ赤** / 既存 3 テストは緑のまま。

### M-4: 施策 1b の catch 節を丸ごと削除(= 例外が素通り)
期待: **既存の「unique violation は no-op へ収束し呼び出し側へ例外が漏れない」が赤** /
`attempt_ulid` テストは緑のまま。
→ **期待制約側の握りが load-bearing** であることの実証(M-3 と逆向きの非対称)。

### M-5: 施策 1c の `if ($e->index !== self::…) throw $e;` を削除
```
composer test -- --filter=SubscriptionCheckoutIdempotencyTest
```
期待: **新規追加テストのみ赤** / 既存 replay・stale テストは緑のまま。

### M-6: 施策 2 の `'status' => TakeUploadReservationStatus::Pending` の**1 行だけ**削除
```
composer test -- --filter=TakeUploadUrlTest
```
期待: **新規テストのみ赤**(`null is identical to ...`)/
既存「発行成功: pending 予約が作成され…」は**緑のまま**
(DB 再読込なので default が埋める)。
→ **DB default が silent に肩代わりしている**構造そのものを実測で見せる。

### M-7: 復帰確認
全 mutation を戻し、`git diff --stat app/` が空であることと
`composer test -- --filter='AutoRecharge|SubscriptionCheckout|TakeUploadUrl'` が緑であることを確認する。

### 全体の最終確認
```
composer fix && composer phpstan && composer test
```

---

## 保証しないもの(実装後も成立しない事柄。先に宣言する)

1. **`$e->index` が常に取れることは保証しない**。sqlite では常に `null`、
   pgsql でも exclusion 制約と翻訳ロケールでは `null` になりうる。
   その場合の挙動は**握らず再送出**(fail-closed)だけで、**識別はできない**。
   「どの制約に当たったか」がログに残るのは例外メッセージ本文としてであって、
   構造化フィールドとしてではない
2. **制約名の drift を静的に検出しない**。PostgreSQL は識別子を 63 バイトで黙って切る
   (実 DB に `take_upload_reservations_organization_id_status_expires_at_inde` が実在する)。
   本設計が持つのは「名前がずれたら behavioral テストが赤くなる」という**事後検出だけ**である。
   `pg_indexes` を照合する専用 gate は作らない(思考原則 2)
3. **「app 全体で unique 違反の握り潰しが無い」ことは保証しない**。
   概念設計の survey は本設計時点の手動走査であり、**静的 gate は作らない**。
   新しい握り潰しはレビューで見るしかない
4. **横断的な「既定値依存を禁止する gate」は新設しない**。aicue:T151 の設計が
   「判定式が静的に書けず偽陽性で gate の信用を落とす」として却下済み。蒸し返さない。
   したがって**施策 2 と同型の既定値依存が他に無いことは保証しない**
5. **孤児 Stripe session そのものは防げない**。Stripe session 作成は DB insert より前の
   外部 I/O なので、insert が落ちれば孤児 session は残る。本設計が変えるのは
   **「その状態が正常終了として扱われること」だけ**である
6. **これは観測可能性の改善であって UX 改善ではない**。ユーザー向けエラー文言は
   追加も変更もしない。これまで黙って通っていた障害が 500 相当で表面化する
7. **施策 2 は現在の外部挙動を何も変えない**。DB に入る値も API 応答も `bytes_pending` 集計も同じ。
   守るのは「この経路の意味が migration default に依存しない」ことだけ
8. **`AutoRechargeTriggerJob` の structured no-op の語彙は変えない**。
   変えるのは「no-op に収束させる条件」だけ
9. **並行起票そのものを減らさない**。DB 制約が最終防衛である構造 (aicue:T137/T148) は不変
10. **`migration` の `default` は消さない**。既存行と Factory 以外の INSERT 経路のために残す

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 変更は 3 ファイル・計 5 箇所の小さな置換で、新モデル・新 migration・新 gate を伴わない。DB スキーマも API 形も変えないので main への追従コストが低い。施策 1a/1b/1c と施策 2 は互いに独立しており、片方だけ先に入っても他方は壊れない |
| 競合リスク | `AutoRechargeService.php` は auto-recharge 系 TODO と競合しうる(直近では aicue:T137/T140 が触っている)。ただし本変更は catch 節と private メソッド 1 個に閉じるため衝突しても解決は容易。`TakeUploadService.php` は撮影 PWA 系の TODO と競合しうるが、変更は `forceFill` の 1 キー追加のみ |
| 実装順序 | (1) 全再現テストを書いて**赤を実測しログに残す** → (2) 施策 1a/1b/1c → (3) 施策 2 → (4) 施策 3(文書) → (5) mutation M-1〜M-7 → (6) `composer fix && composer phpstan && composer test` |


---

## 関連する現行コード

### app/Services/Billing/AutoRechargeService.php (L286-315)
```php
        // insert は DB::transaction (= 外側 TX 下では savepoint) で包む — unique violation が
        // 呼び出し元 TX を abort させない (pgsql の 25P02 連鎖を避ける)。
        try {
            DB::transaction(function () use ($organization, $user, $result, $attemptToken, $idempotencyKey): void {
                $session = new BillingCheckoutSession;
                // tenant / actor キーは relation 経由で明示代入する (mass assignment しない)
                $session->organization()->associate($organization);
                $session->initiated_by_user_id = $user->id;
                $session->fill([
                    'intent' => CheckoutIntent::SetupPaymentMethod->value,
                    'plan_code' => null,
                    'stripe_session_id' => $result['id'],
                    'status' => CheckoutSessionStatus::Pending->value,
                    'idempotency_key' => $idempotencyKey,
                    'attempt_token' => $attemptToken,
                    'checkout_url' => $result['url'],
                ]);
                $session->save();
            });
        } catch (QueryException $e) {
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }
            // 同一 attempt_token の replay — Stripe 側も同一冪等キーで同一 session を返している。
        }

        return $result;
    }

    /**
```

### app/Services/Billing/AutoRechargeService.php (L486-520)
```php
                // UI (AutoRechargeCard の「1 枚あたり」表示) も同じ Max 枚 tier 単価を提示しており、
                // 表示・同意・実請求の 3 者がこれで一致する。
                $tier = TicketVolumePrice::currentTierFor($config->max_count);

                $attempt = new TicketAutoRechargeAttempt;
                $attempt->organization()->associate($locked);
                $attempt->fill([
                    'attempt_ulid' => strtolower((string) Str::ulid()),
                    'status' => AutoRechargeAttemptStatus::Pending->value,
                    'quantity' => $quantity,
                    'unit_amount' => $tier->unitAmount,
                    'stripe_price_id' => $tier->stripePriceId,
                ]);
                $attempt->save();

                // 実行 job の投入を**起票と同一 tx**で行う (AG-114 確定 1)。
                // 旧: 呼び出し側 (AutoRechargeTriggerJob::handle / reconcile (v)) が tx 成功後に
                // dispatch していたため「attempt=pending・実行未投入」の窓があり、
                // reconcile (v) の 15 分周期に依存していた。
                ExecuteAutoRechargeAttemptJob::dispatch($attempt->id);

                return $attempt;
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                // DB partial unique (tar_attempts_org_pending_unique) が最終防衛。並行起票は no-op。
                return null;
            }

            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // attempt 実行 (課金)
```

### app/Services/Billing/AutoRechargeService.php (L1490-1508)
```php
        return config()->integer('billing.auto_recharge.pending_expiry_hours');
    }

    private function currentConsentVersion(): string
    {
        $version = config()->string('billing.auto_recharge.consent_version');
        Assert::stringNotEmpty($version, 'config billing.auto_recharge.consent_version は非空で設定してください');

        return $version;
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // driver 差吸収 (23505 = pgsql / 23000 = sqlite・mysql)。
        $sqlState = $e->errorInfo[0] ?? null;

        return $sqlState === '23505' || $sqlState === '23000';
    }
}
```

### app/Services/Billing/SubscriptionService.php (L448-560)
```php
        );

        try {
            // 失敗 INSERT が PostgreSQL で外側 transaction を abort させないよう savepoint で囲む。
            DB::transaction(function () use ($org, $user, $plan, $created, $attemptToken, $funding): void {
                $session = new BillingCheckoutSession;
                // tenant / actor キーは relation / 明示代入 (mass assignment しない)
                $session->organization()->associate($org);
                $session->initiated_by_user_id = $user->id;
                $session->fill([
                    'intent' => CheckoutIntent::SubscriptionStart->value,
                    'plan_code' => $plan->code,
                    'funding_choice' => $funding?->value,
                    'stripe_session_id' => $created->sessionId,
                    'idempotency_key' => 'sub_start:'.$attemptToken,
                    'attempt_token' => $attemptToken,
                    'checkout_url' => $created->url,
                    'status' => CheckoutSessionStatus::Pending->value,
                ]);
                $session->save();
            });
        } catch (UniqueConstraintViolationException $e) {
            // 段 6: 並行 race。unique(org, intent, attempt_token) 違反 → 既存を再読込して収束する
            // (attempt_token 以外の unique 違反は rethrow = 500 に落として調査対象にする)。
            if (! $this->isUniqueViolation($e)) {
                throw $e;
            }

            $row = $this->subscriptionAttemptQuery($org)
                ->where('attempt_token', $attemptToken)
                ->latest('id')
                ->first();

            if ($row instanceof BillingCheckoutSession && $this->isReplayableCheckout($row, CarbonImmutable::now())) {
                return $this->replayCheckout($row);
            }

            throw new StaleCheckoutAttemptException(
                '契約手続きの有効期限が切れました。画面を再読み込みして再試行してください。',
            );
        }

        return new CheckoutSessionDto(
            stripeSessionId: $created->sessionId,
            url: $created->url,
            intent: CheckoutIntent::SubscriptionStart->value,
            planCode: $plan->code,
        );
    }

    /**
     * `intent=subscription_start` に pin した org スコープのクエリ
     * (P8a の `setup_payment_method` 行を段 2/3/4 に混入させない唯一の出典)。
     *
     * @return Builder<BillingCheckoutSession>
     */
    private function subscriptionAttemptQuery(Organization $org): Builder
    {
        return BillingCheckoutSession::query()
            ->where('organization_id', $org->getKey())
            ->where('intent', CheckoutIntent::SubscriptionStart->value);
    }

    /**
     * 同 attempt_token の既存 session が冪等再生可能か。
     * **stale pending は replay しない** (死んだ checkout_url へ収束させない = C-1)。
     */
    private function isReplayableCheckout(BillingCheckoutSession $session, CarbonImmutable $now): bool
    {
        if ($session->status === CheckoutSessionStatus::Completed->value) {
            return true;
        }

        return $session->isReplayablePending($now);
    }

    /**
     * replayable な既存 session を冪等再生する。
     *  - Pending → 同じ checkout_url に戻す
     *  - Completed → url=null (Controller が「受付済み」フィードバックを出す)
     */
    private function replayCheckout(BillingCheckoutSession $session): CheckoutSessionDto
    {
        $url = $session->status === CheckoutSessionStatus::Pending->value
            ? $session->checkout_url
            : null;

        return new CheckoutSessionDto(
            stripeSessionId: $session->stripe_session_id,
            url: $url,
            intent: CheckoutIntent::SubscriptionStart->value,
            planCode: $session->plan_code,
        );
    }

    /**
     * QueryException が attempt_token unique 制約違反か判定する (driver 差を吸収)。
     *
     * SQLSTATE は driver で異なる (MySQL/SQLite=23000, PostgreSQL=23505) ため両方許容し、
     * 識別子で attempt_token unique 違反だけを拾う (他制約を replay 分岐へ誤って流さない)。
     * MySQL/PostgreSQL は index 名、SQLite は構成列名で一致を見る。
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        if (! in_array($e->getCode(), ['23000', '23505'], true)) {
            return false;
        }

        $message = $e->getMessage();

        return str_contains($message, 'billing_checkout_sessions_org_intent_attempt_unique')
            || (str_contains($message, 'billing_checkout_sessions.organization_id')
                && str_contains($message, 'attempt_token'));
```

### app/Services/Capture/TakeUploadService.php (L37-100)
```php
    public function issue(Organization $organization, Project $project, VideoManual $manual, Cut $cut, TakeUploadInput $input): TakeUploadTicketData
    {
        $expiresAt = CarbonImmutable::now()->addMinutes(config()->integer('capture.upload_ticket_ttl_minutes'));

        $reservation = DB::transaction(function () use ($organization, $project, $manual, $cut, $input, $expiresAt): TakeUploadReservation {
            /** @var Organization $lockedOrg */
            $lockedOrg = Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();
            // 子は親に属する: ロック済み経路で再解決 (cross は 404)。manual 状態 guard も同時に行う
            /** @var VideoManual $lockedManual */
            $lockedManual = $project->manuals()->whereKey($manual->id)->firstOrFail();
            if (! in_array($lockedManual->status, [VideoManualStatus::Ready, VideoManualStatus::Published], true)) {
                throw ValidationException::withMessages([
                    'manual' => ['このマニュアルは現在撮影できません（解析中・書き出し中）。'],
                ]);
            }
            /** @var Cut $lockedCut */
            $lockedCut = $lockedManual->cuts()->whereKey($cut->id)->firstOrFail();

            // Quota: bytes_used + bytes_pending + size が上限を超えるなら 422 (QuotaExceededException)。
            // 加算合成は occupiedBytes() (overflow 安全) に委譲し、呼び出し側で生加算しない。
            // occupiedBytes() は pending→used の読み取り順が並行制御上の不変条件
            // (finalize は org ロックを取らないため。StorageUsageService の docblock 参照)
            $this->quota->checkAddition(
                $lockedOrg,
                QuotaKey::MaxStorageBytes,
                current: $this->usage->occupiedBytes($lockedOrg),
                addition: $input->sizeBytes,
            );

            // S3 キーはサーバ生成 (SourceDocumentService と同じ規約)
            $path = sprintf(
                'projects/%d/manuals/%d/cuts/%d/takes/%s.%s',
                $lockedManual->project_id,
                $lockedManual->id,
                $lockedCut->id,
                (string) Str::ulid(),
                self::extensionFor($input->contentType),
            );

            $reservation = $lockedCut->uploadReservations()->make([
                'client_take_id' => $input->clientTakeId,
                'video_path' => $path,
                'size_bytes' => $input->sizeBytes,
                'content_type' => $input->contentType,
                'checksum_sha256' => $input->checksum->base64,
                'expires_at' => $expiresAt,
            ]);
            $reservation->forceFill(['organization_id' => $lockedOrg->id])->save();

            return $reservation;
        });

        // presign は外部 I/O のため tx 外 (ロック保持時間を最小化)。checksum を署名条件に含める (D2b)
        $presigned = $this->storage->presignUpload(
            $reservation->video_path,
            $input->contentType,
            $input->sizeBytes,
            $input->checksum->base64,
            $expiresAt,
        );
        $ticket = $this->codec->seal(UploadTicketClaims::fromReservation($reservation));

        return new TakeUploadTicketData($presigned, $ticket, $reservation->client_take_id);
    }
```

### app/Models/TakeUploadReservation.php (L38-65)
```php
    /** @use HasFactory<TakeUploadReservationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'client_take_id',
        'video_path',
        'size_bytes',
        'content_type',
        'checksum_sha256',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TakeUploadReservationStatus::class,
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Cut, $this>
     */
    public function cut(): BelongsTo
```

### tests/Feature/Billing/AutoRechargeAttemptUniquenessTest.php (全文)
```php
<?php

declare(strict_types=1);

use App\DataTransferObjects\Billing\AutoRechargeConsentDto;
use App\Enums\Billing\AutoRechargeAttemptStatus;
use App\Models\Billing\TicketAutoRechargeAttempt;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\AutoRechargeService;
use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeAutoRechargeGateway;

/*
|--------------------------------------------------------------------------
| ShouldBeUnique 撤去後の「結果の一回性」 (AG-114 確定 1 / AGENTS.md ドメイン規約 6)
|--------------------------------------------------------------------------
|
| 入口排他 (ShouldBeUnique) は撤去された。一回性を担うのは 3 点:
|  (1) maybeCreateAttempt の organizations 行ロック + pending 存在検査
|  (2) tar_attempts_org_pending_unique (partial unique) — DB の最終防衛
|  (3) unique violation の no-op 収束 (呼び出し側へ例外を漏らさない)
|
| ★ (3) の判定 (isUniqueViolation) は SQLSTATE だけを見て制約名を識別しない。
|   これは本 PR で作った問題ではなく、docs/TODO.md へ Low で追跡起票済みである。
*/

beforeEach(function (): void {
    // ★ 実 jobs 表へ積むだけの構成に固定する。sync レーン (after_commit=true) のままだと
    //   起票と同一 tx で投入された ExecuteAutoRechargeAttemptJob が commit 直後に
    //   インライン実行され、attempt が pending から動いてしまう
    //   (「pending があるから 2 件目は no-op」を見ているつもりが別要因で緑になる偽グリーン)。
    config()->set('queue.default', 'database');
    $this->gateway = new FakeAutoRechargeGateway;
    app()->instance(AutoRechargeGatewayInterface::class, $this->gateway);
});

/**
 * 閾値割れ + enabled な組織。
 *
 * @return array{Organization, User}
 */
function attemptUniquenessContext(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    /** @var FakeAutoRechargeGateway $gateway */
    $gateway = app(AutoRechargeGatewayInterface::class);
    $gateway->withDefaultPaymentMethod();
    app(AutoRechargeService::class)->updateSettings(
        $organization,
        $owner,
        enabled: true,
        threshold: 5,
        max: 50,
        consent: new AutoRechargeConsentDto(config()->string('billing.auto_recharge.consent_version')),
    );

    return [$organization, $owner];
}

test('pending attempt があるとき maybeCreateAttempt は null を返し attempt が増えない', function (): void {
    [$organization] = attemptUniquenessContext();

    $first = app(AutoRechargeService::class)->maybeCreateAttempt($organization);
    expect($first)->not->toBeNull();
    expect(TicketAutoRechargeAttempt::query()->count())->toBe(1);

    $second = app(AutoRechargeService::class)->maybeCreateAttempt($organization->refresh());

    expect($second)->toBeNull();
    expect(TicketAutoRechargeAttempt::query()->count())->toBe(1);
    // 1 件目が pending のまま残っていること (= no-op の理由が pending 検査であること) まで固定する
    expect(TicketAutoRechargeAttempt::query()->firstOrFail()->status)
        ->toBe(AutoRechargeAttemptStatus::Pending);
});

test('同一 org の 2 件目の pending 行は tar_attempts_org_pending_unique が拒否する', function (): void {
    [$organization] = attemptUniquenessContext();
    $first = app(AutoRechargeService::class)->maybeCreateAttempt($organization);
    expect($first)->not->toBeNull();

    // pending 検査を迂回して直接 INSERT する = DB 制約が最終防衛であることの固定。
    // ★ PostgreSQL は失敗した文でトランザクション全体を abort させるため、
    //   savepoint (ネストした DB::transaction) の中で起こして外側を巻き込まない。
    expect(fn () => DB::transaction(fn () => DB::table('ticket_auto_recharge_attempts')->insert([
        'organization_id' => $organization->getKey(),
        'attempt_ulid' => strtolower((string) Str::ulid()),
        'status' => AutoRechargeAttemptStatus::Pending->value,
        'quantity' => 10,
        'unit_amount' => 70,
        'stripe_price_id' => 'price_test',
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class);

    expect(TicketAutoRechargeAttempt::query()->count())->toBe(1);
});

test('unique violation は no-op へ収束し呼び出し側へ例外が漏れない', function (): void {
    [$organization] = attemptUniquenessContext();

    // pending 検査の**後**・INSERT の**直前**に別経路で pending 行が生まれた状況
    // (= 並行起票の敗者側) を模す。DB::table は model event を発火しないため再入しない。
    TicketAutoRechargeAttempt::creating(function () use ($organization): void {
        DB::table('ticket_auto_recharge_attempts')->insert([
            'organization_id' => $organization->getKey(),
            'attempt_ulid' => strtolower((string) Str::ulid()),
            'status' => AutoRechargeAttemptStatus::Pending->value,
            'quantity' => 10,
            'unit_amount' => 70,
            'stripe_price_id' => 'price_race',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $result = app(AutoRechargeService::class)->maybeCreateAttempt($organization);

    // 例外は漏れず null に収束し、tx ごと巻き戻るため attempt 行も残らない
    expect($result)->toBeNull();
    expect(TicketAutoRechargeAttempt::query()->count())->toBe(0);
});
```

### tests/Support/FakeAutoRechargeGateway.php (createSetupCheckout)
```php
    public function createSetupCheckout(
        Organization $organization,
        string $successUrl,
        string $cancelUrl,
        array $metadata,
        string $idempotencyKey,
    ): array {
        $this->setupCheckouts[] = [
            'organizationId' => (int) $organization->getKey(),
            'successUrl' => $successUrl,
            'cancelUrl' => $cancelUrl,
            'metadata' => $metadata,
            'idempotencyKey' => $idempotencyKey,
        ];

        // idempotency key から決定的に導出 (同一 token の再送は同一 session に収束)
        return [
            'id' => 'cs_setup_'.substr(hash('sha256', $idempotencyKey), 0, 24),
            'url' => $this->setupUrl,
        ];
    }

```

### tests/Feature/Capture/TakeUploadUrlTest.php (L28-107)
```php
 * @return array{Organization, User, Project, VideoManual, Cut}
 */
function uploadUrlContext(string $status = 'ready'): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => $status]);
    $cut = Cut::factory()->forManual($manual)->create();

    return [$organization, $owner, $project, $manual, $cut];
}

/**
 * @return array<string, mixed>
 */
function uploadUrlPayload(array $overrides = []): array
{
    return array_merge([
        'client_take_id' => (string) Str::ulid(),
        'size_bytes' => 1_000,
        'content_type' => 'video/mp4',
        'checksum_sha256' => base64_encode(hash('sha256', 'blob', true)),
    ], $overrides);
}

/** TakeObjectStorage を container mock にする (presign は fake 値を返す) */
function mockPresign(): MockInterface
{
    $mock = Mockery::mock(TakeObjectStorage::class);
    $mock->shouldReceive('presignUpload')
        ->andReturn(new PresignedUploadData(
            url: 'https://s3.fake.test/bucket/key?X-Amz-Signature=sig',
            headers: ['Content-Type' => 'video/mp4', 'x-amz-checksum-sha256' => 'fake='],
            expiresAt: CarbonImmutable::now()->addMinutes(30),
        ))
        ->byDefault();
    app()->instance(TakeObjectStorage::class, $mock);

    return $mock;
}

function uploadUrlPath(Project $project, VideoManual $manual, Cut $cut): string
{
    return "/app/projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes/upload-url";
}

test('発行成功: pending 予約が作成され bytes_pending に計上、presign には予約行の値が渡る', function (): void {
    [$organization, $owner, $project, $manual, $cut] = uploadUrlContext();
    $payload = uploadUrlPayload();
    $mock = Mockery::mock(TakeObjectStorage::class);
    app()->instance(TakeObjectStorage::class, $mock);
    $mock->shouldReceive('presignUpload')
        ->once()
        ->withArgs(function (string $path, string $contentType, int $sizeBytes, string $checksum) use ($payload): bool {
            return str_contains($path, '/takes/')
                && $contentType === 'video/mp4'
                && $sizeBytes === 1_000
                && $checksum === $payload['checksum_sha256'];
        })
        ->andReturn(new PresignedUploadData(
            url: 'https://s3.fake.test/bucket/key?X-Amz-Signature=sig',
            headers: ['Content-Type' => 'video/mp4', 'x-amz-checksum-sha256' => 'fake='],
            expiresAt: CarbonImmutable::now()->addMinutes(30),
        ));

    $response = $this->actingAs($owner)->postJson(uploadUrlPath($project, $manual, $cut), $payload);

    $response->assertOk();
    $response->assertJsonStructure(['upload_url', 'headers', 'ticket', 'client_take_id', 'expires_at']);

    $reservation = $cut->uploadReservations()->sole();
    expect($reservation->status)->toBe(TakeUploadReservationStatus::Pending);
    expect($reservation->organization_id)->toBe($organization->id);
    expect($reservation->size_bytes)->toBe(1_000);
    expect($reservation->checksum_sha256)->toBe($payload['checksum_sha256']);
    // サーバ生成キー (projects/{pid}/manuals/{mid}/cuts/{cid}/takes/{ulid}.mp4)
    expect($reservation->video_path)->toStartWith("projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes/");
    expect($reservation->video_path)->toEndWith('.mp4');
    expect(app(StorageUsageService::class)->bytesPending($organization))->toBe(1_000);
});
```

### AGENTS.md ドメイン規約 2 (現行)
```
2. **容量 Quota (max_storage_bytes) の予約規約**: presigned アップロードの容量判定は
   `Billing/QuotaService::checkAddition` + `Capture/StorageUsageService::occupiedBytes`
   (bytes_used + bytes_pending) 経由のみ。予約 (`take_upload_reservations`) の状態遷移は
   pending→verifying (claim)→completed/released の CAS で行い、直接 UPDATE を書かない。
   運用契約 (media queue worker / 孤児掃除 cron) は `docs/architecture.md` §撮影 PWA
3. **サポート対象ブラウザと履歴復元の扱い**: 「どのブラウザで何をどこまで保証しているか」の
```

### AGENTS.md ドメイン規約 1 (ii) 抜粋 (先例)
```
     `Manual/RenderJobService::completeRenderIntoLockedManual()` /
     `Capture/CaptureTakeService::adopt()`・`delete()` (cuts.adopted_take_id))
   - **(ii) 生成経路** (新規 INSERT): 対象行は未存在のため、**所有元 Project 行を
     `lockForUpdate()` した同一トランザクション内で INSERT** し、初期状態
     (`status` / `scenario_version`) を **INSERT 時に明示代入する**
     (DB カラム default に依存しない = migration default 変更による silent break と、
     戻り値インスタンスの属性欠落の両方を防ぐ)。
     準拠実装: `Manual/VideoManualService::create()` / `::duplicate()`
     - **免除の範囲を広げない**: (ii) が `lockForUpdate()` を免除されるのは
       **その tx が生成した新規行の初期値 (`status` / `scenario_version`) の INSERT のみ**である。
       **生成後の行に対する後続の書き込み (`cuts` 等) は (i) 更新経路として扱い**、
       保存済みの新 manual を `lockForUpdate()` で**再取得した**同一 tx 内で行う
       (準拠実装: `duplicate()` は新 manual を save 後に `lockForUpdate()` で再取得してから
       `copyCuts()` を呼ぶ)
   経路 inventory は **`ScenarioWritePathInventoryTest` (Architecture テスト) へ昇格済み** =
   新しい書き込み経路は inventory 登録が必須。**ただし allowlist はファイル粒度**であり、
   同一ファイル内のメソッド追加は検出しない (メソッド単位の fail-first は behavioral テストが担う)。
   テイク採用 API は検出 4 (`adopted_take_id` の deny-by-default 走査) で inventory 準拠済み。
   詳細は `docs/architecture.md` §シナリオ整合の共有不変条件
```
