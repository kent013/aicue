# 概念設計: deferred-robustness (先送り堅牢性 2 件)

対象: `devnotes/20260811-1733-deferred-robustness/recon-brief.md` の件 1 / 件 2。
**2 件を 1 設計で扱うが、層も直し方も違うため共通化はしない**(思考原則 4「別物の概念を
似ているからで統合しない」)。共通するのは「今は顕在化していないが条件が揃うと黙って壊れる」
という**性質**だけで、機構は無関係である。

---

## 背景・課題

### 件 1 (aicue:T140): unique violation の識別が SQLSTATE 止まり

`app/Services/Billing/AutoRechargeService.php:1501`

```php
private function isUniqueViolation(QueryException $e): bool
{
    // driver 差吸収 (23505 = pgsql / 23000 = sqlite・mysql)。
    $sqlState = $e->errorInfo[0] ?? null;

    return $sqlState === '23505' || $sqlState === '23000';
}
```

呼び出しは 2 箇所で、**どちらも「期待している 1 本の制約」以外の違反まで同じ扱いへ収束させる**。

| 位置 | 期待している制約 | 現在の握り方 | 別制約に当たったときの実害 |
|---|---|---|---|
| L306 `startSetupCheckout()` | `billing_checkout_sessions_org_intent_attempt_unique` | 例外を捨てて `$result` を返す | Stripe session は作られたのに**台帳行が無い**状態が正常終了する。`setupPending` 判定・webhook の intent 照合の出典が欠落する |
| L510 `maybeCreateAttempt()` | `tar_attempts_org_pending_unique` | `null` を返す | 「並行起票の敗者」と区別が付かず、`AutoRechargeTriggerJob` は structured no-op として黙る。**リチャージが永久に起票されない**組織が出ても誰も気づかない |

`billing_checkout_sessions` には他に 3 本の unique がある
(`_stripe_session_id_unique` / `_idempotency_key_unique` / `_pkey`)。
`ticket_auto_recharge_attempts` にも 3 本ある
(`_attempt_ulid_unique` / `_stripe_invoice_id_unique` / `_pkey`)。
**いずれも現状は「並行競合」として飲み込まれる。**

aicue:T137 で `AutoRechargeTriggerJob` から `ShouldBeUnique` を撤去し一回性を DB 制約へ寄せた。
DB 制約への依存が強まったぶん、識別しないことの危険も増している。

### 件 2 (aicue:T151 副次発見): `take_upload_reservations.status` の DB 既定値依存

`app/Services/Capture/TakeUploadService.php:76-84`

```php
$reservation = $lockedCut->uploadReservations()->make([
    'client_take_id' => ..., 'video_path' => ..., 'size_bytes' => ...,
    'content_type' => ..., 'checksum_sha256' => ..., 'expires_at' => ...,
]);
$reservation->forceFill(['organization_id' => $lockedOrg->id])->save();
```

`status` は `$fillable` に無く `forceFill` にも無い。行が `pending` になるのは
migration の `$table->string('status', 20)->default('pending')` のおかげである
(実 DB 実測: `column_default = 'pending'::character varying`, `is_nullable = NO`)。

結果として **`save()` 直後の in-memory インスタンスの `status` は `null`** になる。
aicue:T151 で直した `VideoManualService::create()` とまったく同じ形である。

---

## 改善アイデア

### 件 1: 「呼び出し側が期待を宣言し、それ以外は再送出する」

> **Round 2 での改訂**: 当初は「期待する**制約名**を宣言する」形だけを考えていたが、
> 詳細設計 E-7 の実測で **正規 replay では複数の unique が同時に違反し、PostgreSQL が
> 報告する 1 本は index の OID 順で決まる**ことが分かった。したがって
> **「期待を宣言する」の中身は 2 種類**になる:
> - 期待制約以外が同時に違反しえない場合 → **制約名**で宣言する(施策 1b)
> - 同時に違反しうる場合 → **自然キーで既存行を読み直し同一性を確認**して宣言する(施策 1a)
>
> どちらも「不明な unique 違反は飲まない」= fail-closed である点は同じ。

**先人の知恵 (思考原則 1)**: 自前の正規表現は書かない。Laravel 13 の
`Illuminate\Database\UniqueConstraintViolationException` が**既に制約名を持っている**。

- `Connection::runQueryCallback()` (vendor L849-873) が unique 違反を検出すると
  `QueryException` ではなく `UniqueConstraintViolationException` を投げ、
  `parseUniqueConstraintViolation()` の結果を `setIndex()` / `setColumns()` する
- `PostgresConnection::parseUniqueConstraintViolation()` (vendor L88-103) が
  `#unique constraint "([^"]+)"#i` で**制約名を `$e->index` に入れる**

```php
// 施策 1b (期待制約以外が同時に違反しえない): 制約名で宣言する
} catch (UniqueConstraintViolationException $e) {
    if ($e->index === self::ATTEMPT_ORG_PENDING_UNIQUE) {
        return null;       // 並行起票の敗者
    }
    throw $e;              // fail-closed
}

// 施策 1a (同時に違反しうる): 自然キーの同一性で宣言する
} catch (UniqueConstraintViolationException $e) {
    $existing = BillingCheckoutSession::query()
        ->where('organization_id', ...)->where('intent', ...)->where('attempt_token', $attemptToken)->first();
    if (! $existing instanceof BillingCheckoutSession
        || $existing->stripe_session_id !== $result['id']
        || $existing->idempotency_key !== $idempotencyKey) {
        throw $e;          // fail-closed
    }
    // 同一 attempt_token の replay
}
```

- **`isUniqueViolation()` は削除する**(思考原則 3「後方互換の並走を残さない」)。
  非 unique の `QueryException` はそもそも catch されず素通りするので、SQLSTATE 判定は不要になる
- **共有の enum / 制約名台帳は作らない**(思考原則 2)。2 箇所で期待も判定方式も違う
- `$e->index === null` (パース失敗 / sqlite) も**再送出**。fail-closed が既定

### 件 2: aicue:T151 と同じ直し方 —— INSERT 時に初期状態を明示代入する

```php
$reservation->forceFill([
    'organization_id' => $lockedOrg->id,
    'status' => TakeUploadReservationStatus::Pending,
])->save();
```

`status` は保護状態列なので `$fillable` には**入れない**(Model docblock の
「status 遷移は TakeUploadService (insert) / TakeRegistrationService (claim/CAS) /
StaleUploadReservationSweeper のみ」という宣言を維持する)。`forceFill` は T151 と同形。

**migration の `default('pending')` は消さない**(既存行と他の INSERT 経路 —— Factory を含む —— に影響する)。

---

## 期待効果

- **件 1**: 「Stripe session だけ作られて台帳行が無い」「リチャージが起票されない」が
  **正常終了として扱われなくなり**、既存の例外観測経路に乗る。
  → 使命「思考ゼロ・編集ゼロ」を支える課金基盤が、壊れたときに**壊れたと言う**ようになる
  - **状態そのものは防げない**。Stripe session 作成は DB insert より前の外部 I/O なので、
    insert が落ちれば孤児 session は残る。本設計が変えるのは
    **「その状態が黙って成功扱いされること」だけ**である
  - これは**観測可能性の改善であって UX 改善ではない**。ユーザー向け文言は追加も変更もしない
- **件 2**: `issue()` が保持する予約インスタンスが DB と同じ状態を持つ。
  migration default を将来変えても**この経路の意味は変わらない**
- どちらも**成功時の振る舞いは変わらない**(課金の振る舞いを変えない / CAS 規約を変えない)

---

## 実装方針(概要)

| # | 施策 | 変更ファイル | 層 |
|---|---|---|---|
| 1a | `startSetupCheckout()` の catch を**自然キーの同一性**で絞る | `app/Services/Billing/AutoRechargeService.php` | 例外ハンドリング |
| 1b | `maybeCreateAttempt()` の catch を**制約名**で絞る + `isUniqueViolation()` 削除 | 同上 | 例外ハンドリング |
| 2 | `TakeUploadService::issue()` の初期 status 明示代入 | `app/Services/Capture/TakeUploadService.php` | 永続化の初期値 |
| 3 | 契約の文書化 (AGENTS.md ドメイン規約 2 / docs/architecture.md) | `AGENTS.md` / `docs/architecture.md` | 文書 |

`SubscriptionService` は**対象外**(下記「調査した同型」の訂正を参照)。

---

## 制約・前提(実コードと vendor で確認済み)

### 確認 1: PostgreSQL の例外から制約名は取れる —— ただし**構造化フィールドではない**

実 DB (PostgreSQL 18.4, `lc_messages=en_US.utf8`) に対して PDO で unique 違反を起こして実測:

```
getCode   = '23505'   (string)
errorInfo = [ '23505', 7, 'ERROR:  duplicate key value violates unique constraint
                           "probe_a_expected_unique"\nDETAIL:  Key (id)=(1) already exists.' ]
count(errorInfo) = 3
```

- **`errorInfo` は 3 要素しかない**。libpq の `PG_DIAG_CONSTRAINT_NAME` は
  PDO_pgsql から構造化フィールドとして露出していない
- 制約名は **`errorInfo[2]` のドライバメッセージ本文にしか無い**。
  取り出しは**文字列パース以外に手段が無い**
- **部分 UNIQUE index** (`CREATE UNIQUE INDEX ... WHERE status='pending'` =
  `tar_attempts_org_pending_unique` と同型) でも同じ形で index 名が出ることを実測確認
- 値に `"` を含んでも `DETAIL` 側は引用符で囲まないため、**先頭の引用符対が制約名**である

→ **結論: 取れる。ただし自前でパースはしない。Laravel が既にパースしている**
(`UniqueConstraintViolationException::$index`)。取れないふりも、取れるふりもしない。

### 確認 2: 取れない条件を正直に列挙する

| 条件 | `$index` | 設計上の扱い |
|---|---|---|
| pgsql + unique / primary key 違反 | 制約名 | 期待名と一致すれば握る |
| pgsql + exclusion 制約 | **`23P01`(実測)** → そもそも `UniqueConstraintViolationException` にならない | catch されず素通り |
| pgsql + `lc_messages` が翻訳カタログのあるロケール | `null` になりうる | 再送出。実測ではコンテナに ja カタログが無く英語のまま |
| sqlite | **常に `null`** (`SQLiteConnection::parseUniqueConstraintViolation` は `index => null` 固定) | 再送出 |

本リポジトリは `phpunit.xml` が `DB_CONNECTION=pgsql` を `force="true"` で固定し、
`ticket_auto_recharge_attempts` の migration 自身が非 pgsql/sqlite driver で
`RuntimeException` を投げる。**テストも本番も pgsql 単一**である。

### 確認 3: index 名は PostgreSQL が 63 バイトで**黙って切る**

実 DB 実測: `take_upload_reservations_organization_id_status_expires_at_inde`
(末尾 `x` が落ちている)。施策 1b が名指しする `tar_attempts_org_pending_unique` は
31 文字で無事であり、実 DB に実在することを確認済み。

名前が将来ずれたら**behavioral テストが赤くなる**(握るはずの違反が再送出される)。
そのため**別途の名前照合 gate は作らない**(思考原則 2)。

### 確認 3b: 複数の unique が同時に違反したとき報告されるのは 1 本だけ(詳細設計 E-7)

実測により、報告される 1 本は **index の作成順(OID 昇順)** で決まることが分かった。
正規 replay(同一 attempt_token の二重 submit)では `billing_checkout_sessions` の
**3 本の unique が同時に違反する**ため、施策 1a は制約名で判定できない。
詳細は詳細設計 E-7。

### 確認 4: 件 2 は AGENTS.md ドメイン規約 2 と衝突しない

規約 2 は「予約の**状態遷移**は pending→verifying→completed/released の CAS で行い、
**直接 UPDATE を書かない**」と定める。今回追加するのは **INSERT の初期値**であり、
UPDATE でも遷移でもない。加えてドメイン規約 1 (ii) が同型の判断を**既に明文化**している:

> **(ii) 生成経路** (新規 INSERT): 初期状態を **INSERT 時に明示代入する**
> (DB カラム default に依存しない = migration default 変更による silent break と、
> 戻り値インスタンスの属性欠落の両方を防ぐ)

規約 1 は `video_manuals` 系の話で `take_upload_reservations` は対象外だが、
**同じ理由が同じ形で当てはまる**。今回は規約 2 の側に 1 文追記して契約を明示する。

### 確認 5: 件 2 の inventory 登録は不要

`tests/Architecture/` 配下 100 ファイルを走査し、`take_upload_reservations` /
`TakeUploadReservation` を参照する Architecture テストは**ゼロ件**。
`ScenarioWritePathInventoryTest` は `video_manuals.status` / `scenario_version` /
`adopted_take_id` 専用で本テーブルを見ない。**登録先が存在しないので登録しない**。

### 調査した同型(件 1 の 4 項目め)

`app/` 全体で unique 違反を握る箇所を洗った結果:

| 箇所 | 制約名を見るか | 判定 |
|---|---|---|
| `AutoRechargeService::startSetupCheckout` L306 | 見ない | **本設計 施策 1a** |
| `AutoRechargeService::maybeCreateAttempt` L510 | 見ない | **本設計 施策 1b** |
| `SubscriptionService::startCheckout` L469 | **見る**(`str_contains` で index 名照合) | **対処不要**(下記の訂正) |
| `PersonalPlanService::isDeclarerUniqueViolation` L216 | **見る**(`str_contains` で名前照合) | 対処不要 |
| `Actions/Fortify/CreateNewUser` L119 | 見ないが `emailAlreadyRegistered()` で**再確認して不一致は再送出** | fail-closed 済。対処不要 |
| `Capture/TakeRegistrationService` L120 | 見ないが**重複 Take が実在しなければ再送出** | fail-closed 済。対処不要 |
| `Models/LlmCallLog::recordWithOrganization` L140 | 見ない | 対処不要(下記) |
| `Services/Mcp/McpIdempotencyService` L118 | 見ない | 対処不要(下記) |

- `llm_call_logs` の unique は `_execution_id_unique` と `_pkey` の 2 本のみ(実 DB 実測)。
  かつ握った後の fallback は `firstOrNew(execution_id)` の**再試行**なので、
  別制約なら 2 回目も同じ例外で**外へ出る**。構造的に fail-closed
- `mcp_idempotency_keys` も同様に握った後で既存行を読み直す形
- **`SubscriptionService` は本設計に含めない(施策 1c は撤回)**。
  Round 1 時点で「`isUniqueViolation()` は SQLSTATE しか見ておらず docblock が
  実装していない保証を宣言している」と書いたが、**これは設計者の誤読だった**。
  実コード `SubscriptionService.php:550-562` は

  ```php
  return str_contains($message, 'billing_checkout_sessions_org_intent_attempt_unique')
      || (str_contains($message, 'billing_checkout_sessions.organization_id')
          && str_contains($message, 'attempt_token'));
  ```

  と**制約名を照合している**。既に fail-closed であり、`$e->index` へ置き換えても
  振る舞いは変わらない。**「今必要なものだけ作る」(思考原則 2) に照らしてやらない**。
  詳細設計 E-6 / E-7 に、残る脆さ(複数 unique 同時違反時の報告順依存)を
  **記録のみ**として残す(対処の約束ではない)。

---

## 再現テストを先に赤にする手順(要旨。詳細は詳細設計)

### 件 1: **別の unique 制約に当てて握り潰されること**を再現する

期待制約(`tar_attempts_org_pending_unique` / `..._org_intent_attempt_unique`)ではなく
**同じ表の別の unique** に当てる。

- 1b: `TicketAutoRechargeAttempt::creating` で、**別 org・同じ `attempt_ulid`** の行を
  素の `DB::table()->insert()` で先回りさせる。別 org なので部分 unique には触れず、
  `ticket_auto_recharge_attempts_attempt_ulid_unique` だけが違反する。
  → 現状は `null` に収束(**握り潰し**)。テストは例外送出を期待するので**赤**
- 1a: `FakeAutoRechargeGateway` に**既存行と同じ `stripe_session_id`** を返させ、
  `attempt_token` は別にする。`billing_checkout_sessions_stripe_session_id_unique` だけが違反する。
  → 現状は正常終了(**握り潰し**)。テストは例外送出を期待するので**赤**
- 既存 `tests/Feature/Billing/AutoRechargeAttemptUniquenessTest.php` の 3 テストは
  **期待制約の側**を固定しており、修正後も緑のまま(= 成功時の振る舞いを変えていない証拠)

### 件 2: in-memory インスタンスの状態を読む

`issue()` は `TakeUploadTicketData` を返し予約行を返さないため、T151 の
「戻り値の status を読む」をそのまま写せない。代わりに **`TakeUploadReservation::created`
フックで service が保持している当のインスタンスを捕まえて** `status` を読む。

→ 現状は `Failed asserting that null is identical to an object of class
"App\Enums\Capture\TakeUploadReservationStatus"` で**赤**(T151 の赤と同一の形)。

---

## 保証しないもの(先に宣言する)

1. **`$index` が常に取れることは保証しない**。sqlite では常に `null`、
   pgsql でも unique 違反メッセージをパースできない場合や翻訳ロケールでは `null` になりうる。
   その場合は**握らず再送出する**(fail-closed)だけで、識別はできない。
   **exclusion 制約は `23P01`** であり `UniqueConstraintViolationException` にならないため、
   そもそもこの catch の対象外である(`$index === null` のケースではない)
1b. **`$index` が「違反した全制約」を表すことも保証しない**。複数の unique が同時に違反したとき
   PostgreSQL が報告するのは 1 本だけで、どれになるかは index の作成順で決まる(詳細設計 E-7)。
   施策 1a が制約名を使わないのはこのためである
1c. **施策 1a の同一性判定は「誰が起こした attempt か」を検証しない**(詳細設計「保証しないもの」§14)
2. **「app 全体で unique 違反の握り潰しが無い」ことは保証しない**。
   上表は本設計時点の手動走査であり、**静的 gate は作らない**。
   新しい握り潰しはレビューで見るしかない
3. **横断的な「既定値依存を禁止する gate」は新設しない**。
   aicue:T151 の設計が「判定式が静的に書けず偽陽性で gate の信用を落とす」として却下済み。蒸し返さない
4. **件 2 の修正は現在の外部挙動を何も変えない**。`issue()` の戻り値も DB の行も同じ。
   守るのは「この経路の意味が migration default に依存しない」ことだけ
5. **制約名の drift を静的に検出しない**。名前がずれたら behavioral テストが赤くなる、
   という事後検出だけを持つ
6. **`AutoRechargeTriggerJob` の structured no-op の語彙は変えない**。
   件 1 が変えるのは「no-op に収束させる条件」だけである

---

## スコープ外

- 課金の成功時の振る舞い(枚数・単価・invoice・webhook)には触れない
- 予約の状態遷移 CAS 規約 (`TakeRegistrationService` / `StaleUploadReservationSweeper`) に触れない
- `migration` の `default` 削除
- `LlmCallLog` / `McpIdempotencyService` / `PersonalPlanService` / `CreateNewUser` /
  `TakeRegistrationService` の変更(上表のとおり不要と判断)
- 既定値依存 / 握り潰しを検出する新 Architecture gate
