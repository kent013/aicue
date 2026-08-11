# 詳細設計レビュー Round 2 (deferred-robustness)

Round 1 の指摘への対応マトリクスと、修正後の詳細設計の該当部分を送ります。
**指摘は推測で受け入れず、すべて実 DB / vendor / 実コードで裏を取りました。**

---

## 対応マトリクス (Round 1)

# 対応マトリクス: design-review Round 1

Round 1 判定: **CHANGES_REQUESTED**。Critical 3 件・Warning 2 件・Suggestion 2 件。
**Critical 3 件はすべて実測で裏を取り、すべて対応した**(うち 1 件は設計者の誤読の訂正)。

---

## [Critical] 施策 1a: 同一 `attempt_token` replay は 1 本の unique 制約だけに当たらない

- 判断: **対応する(指摘は正しい。設計を作り直した)**
- 検証: 推測で受け入れず、`billing_checkout_sessions` と**同じ index 構成・同じ作成順**の
  TEMP 表を作って実測した(`scratchpad/probe3.php`)。

  ```
  --- 正規 replay (3 本すべて同時に違反) ---
    試行0: 報告された制約 = bcs_stripe_session_id_unique
    試行1: 報告された制約 = bcs_stripe_session_id_unique
    試行2: 報告された制約 = bcs_stripe_session_id_unique
  --- 逆順で作った場合 (複合 unique を先に作る) ---
    報告された制約 = bcs2_org_intent_attempt_unique
  ```

  → **報告される 1 本は index の作成順(OID 昇順)で決まる**。アプリの意味論ではない。

  さらに実 DB(migrate 済み)の OID 順も照会した:

  | index | OID |
  |---|---|
  | `billing_checkout_sessions_org_intent_attempt_unique` | **91838**(unique の中で最若) |
  | `billing_checkout_sessions_stripe_session_id_unique` | 91840 |
  | `billing_checkout_sessions_idempotency_key_unique` | 91842 |

  → **今日は偶然、旧案でも動く**(Laravel の Blueprint が明示 unique を fluent unique より
  先に発行するため)。しかし migration に unique を 1 本足す・`pg_dump`/restore で
  再作成順が変わるだけで壊れる。**依存してよい契約ではない**。

- 対応内容:
  - 施策 1a を **自然キーの同一性判定**へ作り直した。
    `(organization_id, intent, attempt_token)` で既存行を読み直し、
    `stripe_session_id` / `idempotency_key` が今回の値と一致するときだけ replay として握る。
    一致しない・行が無いなら再送出(fail-closed)。const `CHECKOUT_ATTEMPT_TOKEN_UNIQUE` は廃止。
  - 施策一覧の直後に **判定方式の選択規則**を明文化した:
    「制約名で判定してよいのは『期待制約以外が同時に違反しえない』ことが構造的に言える場合だけ」。
  - 実測を **E-7** として詳細設計に追加した。
- **施策 1b は制約名判定のままとする**(反論ではなく、規則の適用結果):
  `attempt_ulid` は毎回新規 ULID、`stripe_invoice_id` は insert 時 NULL(NULL は unique に
  抵触しない)、pkey は serial。**期待制約以外が同時に違反しえない**。
  Codex も施策 1b は APPROVE している。

## [Critical] 施策 1c: 「修正前に赤くなる」前提が現行コードと矛盾する

- 判断: **対応する(指摘は正しい。設計者の誤読であり、施策ごと撤回する)**
- 検証: `app/Services/Billing/SubscriptionService.php:550-562` を実読した。

  ```php
  return str_contains($message, 'billing_checkout_sessions_org_intent_attempt_unique')
      || (str_contains($message, 'billing_checkout_sessions.organization_id')
          && str_contains($message, 'attempt_token'));
  ```

  **制約名を照合している**。設計者は L546-556 の docblock 冒頭だけを読み、本体を読まずに
  「SQLSTATE しか見ていない / docblock が実装していない保証を宣言している」と断定した。
  **これは設計者の誤読である**。
- 対応内容:
  - **施策 1c を撤回**した。現状は既に fail-closed であり、`$e->index` へ置き換えても
    振る舞いは変わらない。「今必要なものだけ作る」(思考原則 2) に照らしてやらない。
  - 詳細設計に **E-6(誤読の訂正)** を明記した。概念設計の survey 表も訂正した
    (「見ない」→「見る(`str_contains` で index 名照合)」)。
  - R-1c / M-5 は「撤回」と明記して残した(消さずに経緯を残す)。

## [Critical] 施策 1c: 1a と同じく正規 replay が複数 unique に当たり得る

- 判断: **指摘は正しい。ただし施策 1c 自体を撤回したため、修正ではなく記録に留める**
- 根拠: `SubscriptionService` にも E-7 の脆さは残る。ただし**失敗方向は安全側**である
  (黙って飲まず 500 になる)。今日の OID 順(複合 unique が最若)では発現しない。
  T140 のスコープ外であり、「今必要なものだけ作る」に照らして本設計では直さない。
- 対応内容: 詳細設計 E-6 / E-7 と「保証しないもの」§5 に**記録のみ**として残した
  (**対処の約束ではない**と明記)。既存テスト
  `並行 race: INSERT 直前に同 token 行が割り込んでも…` が緑なのは、勝者行が
  **別 session id・別 idempotency_key** なので複合 unique 1 本しか違反しないためであり、
  この脆さの反証にならないことも明記した。

## [Warning] M-1 の mutation は「const が load-bearing」を十分に証明しない

- 判断: **対応する**
- 根拠: 指摘のとおり。旧案では replay が複数制約に当たるため、const を壊して赤くなっても
  「報告制約名にたまたま依存している」ことの証明にしかならない。
- 対応内容: 施策 1a が制約名を使わなくなったので mutation を作り直した。
  - **M-1**: 同一性検査の `stripe_session_id` 比較 1 条件だけを削除 →
    テスト 3 のみ赤 / テスト 1・2 は緑(意味論側の非対称)
  - **M-2**: catch 節の `throw` を削除(= 常に握る)→ テスト 1・3 が赤 / テスト 2 は緑
  - **M-2b**: **撤回した旧案(制約名判定)に差し替える** → 3 本とも緑。
    これは旧案が正しいことの証明ではないので、E-7 の実測と併せて
    「**テストでは差が出ないが依存してよい契約ではない**」ことを mutation ログに明記する。
    テストで守れない差を正直に記録するために実施する。

## [Warning] exclusion 制約は 23505 ではなく 23P01

- 判断: **対応する(指摘は正しい。実測で確認した)**
- 検証:
  ```
  errorInfo = [ 0 => '23P01', 1 => 7,
                2 => 'ERROR:  conflicting key value violates exclusion constraint "ex1_a_excl" ...' ]
  ```
- 対応内容: E-5 の表を書き直した。exclusion 制約は
  `PostgresConnection::isUniqueConstraintError()`(`'23505' === getCode()`)を通らないので
  **素の `QueryException` として catch 節の外へ出る**。`$index === null` の fail-closed は
  「unique 違反だが Laravel が index を抽出できなかった場合」の話であると書き分けた。
  「保証しないもの」§1 も同じ趣旨に直した。概念設計の確認 2 の表も訂正した。

## [Suggestion] 施策 1b のテストに「pending 検査後の race を model event で作る」旨を明記

- 判断: **対応する**(コスト 0)
- 対応内容: R-1b のコメントに既に
  「pending 検査の**後**・INSERT の**直前**に、**別 org**で**同じ attempt_ulid** の行を作る」
  と書いてある。加えて「別 org なので部分 unique には触れず attempt_ulid unique だけが違反する」
  という**単一制約性の根拠**を明記済み。

## [Suggestion] R-2 の `created` フックの意図をコメントで補う

- 判断: **対応する**(コスト 0)
- 対応内容: R-2 のコード内コメントに
  「service が save() した当のインスタンス」と既に書いてある。加えて
  「既存テストは `uploadReservations()->sole()` = **DB 再読込**なので DB default で緑になり
  この欠落を検出できなかった」という**なぜ既存テストで漏れたか**の説明を残した。

---

## 施策 1a を作り直した結果、テスト計画も変わった点(Round 2 で見てほしい)

- テスト 2(正規 replay が緑)が**旧案の Critical を捕まえるテスト**である。
  ただし今日の OID 順では旧案でも緑なので、**このテストだけでは旧案と新案を区別できない**。
  区別は E-7 の実測と M-2b の mutation ログで担保する。**これを正直に書いた**。
- テスト 3(既存行の session id 食い違い)を新設した。
  「行は在るが内容が違う = 台帳が壊れている」を飲まないことの固定。
- `grep -rn 'startSetupCheckout' tests/` は **0 件**であり、この経路には
  **既存テストが一切ない**ことを確認した。新規ファイルが唯一の回帰防御である旨を明記した。


---

## 追加で実読した現行コード (Round 1 の Critical 検証のため)

### app/Services/Billing/SubscriptionService.php L544-562 (isUniqueViolation 本体)
```php
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
    }

```

### 実 DB の index OID 順 (pg_index を照会)
```
billing_checkout_sessions_pkey                               oid=91825 unique=1 pk=1
billing_checkout_sessions_organization_id_intent_status_index oid=91837 unique= pk=
billing_checkout_sessions_org_intent_attempt_unique          oid=91838 unique=1 pk=
billing_checkout_sessions_stripe_session_id_unique           oid=91840 unique=1 pk=
billing_checkout_sessions_idempotency_key_unique             oid=91842 unique=1 pk=
--- ticket_auto_recharge_attempts ---
ticket_auto_recharge_attempts_pkey                           oid=91888 unique=1 pk=1
ticket_auto_recharge_attempts_organization_id_status_index   oid=91895 unique= pk=
ticket_auto_recharge_attempts_status_created_at_index        oid=91896 unique= pk=
ticket_auto_recharge_attempts_attempt_ulid_unique            oid=91897 unique=1 pk=
ticket_auto_recharge_attempts_stripe_invoice_id_unique       oid=91899 unique=1 pk=
tar_attempts_org_pending_unique                              oid=91901 unique=1 pk=
```

---

## 修正後の詳細設計 (全文)

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
| 1a | `startSetupCheckout()` の unique 握りを**自然キーの同一性**で絞る | `app/Services/Billing/AutoRechargeService.php` | High |
| 1b | `maybeCreateAttempt()` の unique 握りを**制約名**で絞る + `isUniqueViolation()` 削除 | `app/Services/Billing/AutoRechargeService.php` | High |
| ~~1c~~ | ~~`SubscriptionService`~~ — **撤回**(下記 E-6。設計者の誤読だった) | — | — |
| 2 | `TakeUploadService::issue()` の初期 `status` を明示代入 | `app/Services/Capture/TakeUploadService.php` | Medium |
| 3 | 契約の文書化(AGENTS.md ドメイン規約 2 / docs/architecture.md) | `AGENTS.md` / `docs/architecture.md` | Low |

**新規モデルなし** = Factory 追加は不要。**インターフェース変更なし** = TypeScript / Inertia Props /
JsonResource への波及なし(各施策の「波及変更」欄で個別に宣言する)。

### 施策 1a と 1b で直し方が違う理由 —— 判定方式の選択規則

Codex 詳細レビュー Round 1 の [Critical] を受け、**制約名で判定してよい条件を実測で確定した**
(E-7)。導かれた規則:

> **制約名(`$e->index`)で判定してよいのは、「期待する制約以外が同時に違反しえない」ことが
> 構造的に言える場合だけである。言えないなら、自然キーで既存行を読み直して同一性を確認する。**

| 施策 | 期待制約以外が同時に違反しうるか | 採る判定 |
|---|---|---|
| 1a `startSetupCheckout` | **する**。正規 replay では `org_intent_attempt` / `idempotency_key` / `stripe_session_id` の **3 本が同時に**違反する | **自然キーの同一性**(制約名を使わない) |
| 1b `maybeCreateAttempt` | **しない**。`attempt_ulid` は毎回新規 ULID、`stripe_invoice_id` は insert 時 NULL(NULL は unique に抵触しない)、pkey は serial | **制約名** |

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
施策 1b が名指しする `tar_attempts_org_pending_unique` は 31 文字で無事だが、
**この事実は設計の前提として明記する**(施策 1a は E-7 の結論により制約名を使わない)。

### E-4. `take_upload_reservations.status` の DB 既定値(実測)

```
column_default = '\'pending\'::character varying'   nullable = NO
```

`TakeUploadReservation::casts()` は `'status' => TakeUploadReservationStatus::class` を宣言済み。
`$fillable` に `status` は**無い**(保護状態列)。

### E-5. `$index` が取れない条件(fail-closed で扱う)

| 条件 | 例外の型 / `$index` | 扱い |
|---|---|---|
| pgsql + unique / PK 違反 (23505) | `UniqueConstraintViolationException` / 制約名 | 期待どおりなら握る |
| pgsql + unique 違反だが本文をパースできなかった | `UniqueConstraintViolationException` / `null` | **再送出** |
| pgsql + 翻訳カタログのあるロケール | 同上 / `null` になりうる | **再送出** |
| pgsql + **exclusion 制約** | **`23P01`(実測。下記)** → `isUniqueConstraintError()` が false → **素の `QueryException`** | **catch されず素通り**(= 実質再送出) |
| sqlite | `UniqueConstraintViolationException` / **常に `null`**(`SQLiteConnection::parseUniqueConstraintViolation` は `'index' => null` 固定。vendor L69-83) | **再送出** |

**exclusion 制約の SQLSTATE を実測した**(Codex Round 1 [Warning] の指摘どおり 23505 ではなかった):

```
errorInfo = [ 0 => '23P01', 1 => 7,
              2 => 'ERROR:  conflicting key value violates exclusion constraint "ex1_a_excl"
                    DETAIL:  Key (a)=(1) conflicts with existing key (a)=(1).' ]
```

`PostgresConnection::isUniqueConstraintError()` は `'23505' === $exception->getCode()` だけを見るので、
**exclusion 違反は `UniqueConstraintViolationException` にならず、素の `QueryException` として
catch 節の外へ出る**。`$index === null` の fail-closed は「unique 違反だが Laravel が
index 名を抽出できなかった場合」の話であり、exclusion 制約とは別件である。
(本リポジトリに exclusion 制約は現存しない。将来足したときの挙動として記録する)

本リポジトリは `phpunit.xml` が `<server name="DB_CONNECTION" value="pgsql" force="true"/>` で
pgsql を強制し、`ticket_auto_recharge_attempts` の migration 自身が非 pgsql/sqlite driver で
`RuntimeException` を投げる。**テストも本番も pgsql 単一**。

### E-6. `SubscriptionService` は既に制約名で判定していた(設計者の誤読の訂正)

概念設計と詳細設計 Round 1 で「`SubscriptionService::isUniqueViolation()` は SQLSTATE しか見ておらず、
docblock が実装していない保証を宣言している」と書いた。**これは誤りである**。実コード L550-562:

```php
    private function isUniqueViolation(QueryException $e): bool
    {
        if (! in_array($e->getCode(), ['23000', '23505'], true)) {
            return false;
        }

        $message = $e->getMessage();

        return str_contains($message, 'billing_checkout_sessions_org_intent_attempt_unique')
            || (str_contains($message, 'billing_checkout_sessions.organization_id')
                && str_contains($message, 'attempt_token'));
    }
```

**制約名を `str_contains` で見ている**。docblock の宣言(「他制約を replay 分岐へ誤って流さない」)は
**実装されている**。設計者は L546-556 の docblock 冒頭だけを読んで本体を読まずに断定した。

→ **施策 1c は撤回する**。現状は既に fail-closed であり、`$e->index` へ置き換えても
振る舞いは変わらない。**「今必要なものだけ作る」(思考原則 2) に照らして、やらない**。

**ただし E-7 の脆さは `SubscriptionService` にも残る**(下記)。これは
**記録に留め、本設計では直さない**(対処の約束ではない)。

### E-7. 複数の unique が同時に違反したとき、PostgreSQL は 1 本しか報告しない(実測)

Codex 詳細レビュー Round 1 の [Critical] を検証するため、`billing_checkout_sessions` と
**同じ index 構成・同じ作成順**の TEMP 表を作り、全列が重複する行を INSERT して実測した:

```
--- 正規 replay (3 本すべて同時に違反) ---
  試行0: 報告された制約 = bcs_stripe_session_id_unique
  試行1: 報告された制約 = bcs_stripe_session_id_unique
  試行2: 報告された制約 = bcs_stripe_session_id_unique
--- 逆順で作った場合 (複合 unique を先に作る) ---
  報告された制約 = bcs2_org_intent_attempt_unique
```

**報告される 1 本は index の作成順(OID 昇順)で決まる**。アプリの意味論ではない。

実 DB(migrate 済み)の OID 順を照会した結果:

| index | OID | unique |
|---|---|---|
| `billing_checkout_sessions_pkey` | 91825 | yes |
| `billing_checkout_sessions_organization_id_intent_status_index` | 91837 | no |
| **`billing_checkout_sessions_org_intent_attempt_unique`** | **91838** | yes |
| `billing_checkout_sessions_stripe_session_id_unique` | 91840 | yes |
| `billing_checkout_sessions_idempotency_key_unique` | 91842 | yes |

**今の migration では偶然、複合 unique が最若の OID を持つ**
(Laravel の `Blueprint` は明示的な `$table->unique([...], 'name')` を先に、
カラム fluent の `->unique()` を後に発行するため)。つまり**今日は**制約名判定が動く。

しかしこれは**依存してよい契約ではない**:
- migration に unique を 1 本足す/並べ替えるだけで順序が変わる
- `pg_dump` / restore・`pg_repack` 等で index の再作成順が変わりうる
- 「どの index が先に検査されるか」は PostgreSQL の内部順序であって仕様ではない

**正規 replay(同一 attempt_token の二重 submit)では 3 本が同時に違反する**:
`idempotency_key = 'auto-recharge-setup:'.$attemptToken` は attempt_token と 1:1、
Stripe は同一冪等キーに対し**同一 session を返す**ため `stripe_session_id` も一致する。

→ 施策 1a は**制約名を使わない**。自然キー `(organization_id, intent, attempt_token)` で
既存行を読み直し、`stripe_session_id` / `idempotency_key` が今回の値と一致するときだけ
replay として握る。**一致しない・行が無いなら再送出**(fail-closed)。

**`SubscriptionService` にも同じ脆さが残る**(`str_contains` で複合 unique 名だけを見ているため、
index 順が変われば正規 replay が 500 になる)。ただし**失敗方向は安全側**(黙って飲まず 500)であり、
今日の OID 順では発現しない。**本設計では直さず、別 TODO 候補として記録のみとする**
(対処の約束ではない)。既存テスト
`並行 race: INSERT 直前に同 token 行が割り込んでも 500 にならず replay へ収束する` は
**別 session id・別 idempotency_key の勝者行**を作るので複合 unique 1 本しか違反せず、
この脆さを踏まない(= 既存テストが緑なことは反証にならない)。

---

## 施策 1a: `startSetupCheckout()` の unique 握りを自然キーの同一性で絞る

### 変更箇所
- ファイル: `app/Services/Billing/AutoRechargeService.php` (L286-311 / import 部)

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
        // 台帳記録 (webhook の intent 照合 / setupPending 判定の出典)。attempt_token unique で
        // 二重 submit は冪等。insert は DB::transaction (= 外側 TX 下では savepoint) で包む —
        // unique violation が呼び出し元 TX を abort させない (pgsql の 25P02 連鎖を避ける)。
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
            // **制約名では判定しない**。正規 replay では 3 本の unique
            // (org+intent+attempt_token / idempotency_key / stripe_session_id) が**同時に**違反し、
            // PostgreSQL が報告する 1 本は index の作成順 (OID 昇順) で決まるため、
            // アプリの意味論として依存できない (詳細設計 E-7 の実測)。
            //
            // 代わりに**自然キーで既存行を読み直し、同一内容であることを確認**する。
            // 一致しない / 行が無い場合は fail-closed で再送出する — 握ると
            // 「Stripe session はあるのに台帳行が無い」状態が正常終了として通ってしまう。
            // (失敗した insert は上の tx / savepoint で巻き戻っているので、この SELECT は
            //  pgsql の 25P02 に当たらない。SubscriptionService::startCheckout と同じ形。)
            $existing = BillingCheckoutSession::query()
                ->where('organization_id', $this->orgId($organization))
                ->where('intent', CheckoutIntent::SetupPaymentMethod->value)
                ->where('attempt_token', $attemptToken)
                ->first();

            if (! $existing instanceof BillingCheckoutSession
                || $existing->stripe_session_id !== $result['id']
                || $existing->idempotency_key !== $idempotencyKey) {
                throw $e;
            }
            // 同一 attempt_token の replay — Stripe 側も同一冪等キーで同一 session を返しており、
            // 既存行の session id / 冪等キーが今回の値と一致することを確認済み。
        }

        return $result;
```

import 追加: `use Illuminate\Database\UniqueConstraintViolationException;`

### なぜこの判定が「握ってよい条件」の正確な表現なのか
握ってよいのは**「書きたかった行が既に在る」**ときだけである。
「どの index が先に検査されたか」はその代理指標ですらない(E-7)。
一方 `(organization_id, intent, attempt_token)` は台帳の自然キーであり、
そこに在る行の `stripe_session_id` / `idempotency_key` が今回の値と一致するなら、
**それは同一の attempt による先行書き込みである**と言い切れる。

- `idempotency_key = 'auto-recharge-setup:'.$attemptToken` は attempt_token と 1:1 なので、
  別 token の行がこのキーに一致することはない
- 別 token の行と `stripe_session_id` が衝突した場合、自然キー検索は**行を見つけられない**か、
  見つけても `stripe_session_id` が一致しないので**再送出**される

### なぜ `catch (QueryException)` をやめるのか
`UniqueConstraintViolationException` は `QueryException` の子なので、
`catch (UniqueConstraintViolationException)` に狭めると**非 unique の `QueryException` は
そもそも捕まらず素通りする**。旧コードの `if (! isUniqueViolation) throw` と等価であり、
SQLSTATE 判定を書く必要がなくなる(思考原則 3: 後方互換の並走を残さない)。

### PHPStan 適合チェック
- [x] 戻り値の型が明示されている(`array{id: string, url: string|null}`、変更なし)
- [x] null 安全: `$existing` は `BillingCheckoutSession|null`。`instanceof` で narrowing してから
      プロパティを読む(`?->` や `(string)` で潰さない)。`||` の短絡評価により
      `! $existing instanceof …` が true のときプロパティ参照に到達しない
- [x] `$existing->stripe_session_id` は `string`(model docblock)、`$result['id']` は
      `array{id: string, url: string|null}` の `string` → **`!==` の両辺が `string`**
- [x] `$this->orgId($organization)` は `int` を返す既存 private helper(L1409。`Assert::integer` 済み)
- [x] DTO を返している(本メソッドは既存の shape 配列。変更なし)
- [x] Generics の型パラメータ: `BillingCheckoutSession::query()` の Builder generics は既存パターンどおり
- [x] **新しい const を導入しない**(制約名を持たないため)

### テスト計画
- [x] バグ修正のため**再現テストを先に書く**(下記「赤化手順 R-1a」)
- [ ] 新規 `tests/Feature/Billing/AutoRechargeSetupCheckoutUniquenessTest.php`
  1. `別の unique 制約 (stripe_session_id) の違反は握り潰さず再送出する`
     — **修正前は赤**
  2. `同一 attempt_token の replay は例外を漏らさず結果を返し行も増えない`
     — **修正前も後も緑**(成功時の振る舞いを変えていないことの固定)
  3. `既存行の stripe_session_id が今回の値と食い違うなら replay として握らない`
     — **修正前は赤**。「行は在るが内容が違う」= 台帳が壊れている状態を飲まないことの固定
- [x] **`startSetupCheckout` を叩く既存テストはリポジトリに存在しない**
      (`grep -rn 'startSetupCheckout' tests/` は 0 件)。したがってテスト 2 は
      **replay 経路の初めての固定**であり、施策 1a の回帰防御はこの新規ファイルだけが担う
- [x] 個別の `DatabaseTransactions` を使わない

### リスク
- **これまで黙って通っていた障害が例外として表面化する**。これは意図した効果である
  (fail-closed)。ユーザー向けエラー文言は追加も変更もしない = **観測可能性の改善であって
  UX 改善ではない**
- catch 節で SELECT が 1 本増える。**unique 違反が起きたときだけ**走るので
  正常系のコストは増えない
- 「行は在るが `checkout_url` だけ違う」ケースは**握る**(一致条件に `checkout_url` を入れない)。
  Stripe は同一冪等キーで同一 session を返すので URL も同一のはずだが、
  URL は表示専用で意味論の同一性を決めないため判定に使わない。**これは意図した緩さ**である

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

## 施策 1c(撤回): `SubscriptionService::startCheckout()`

**Round 1 で撤回した。** 詳細は E-6。要点だけ再掲する:

- 設計者は `SubscriptionService::isUniqueViolation()` を「SQLSTATE しか見ていない」と書いたが、
  **実際には `str_contains` で制約名 `billing_checkout_sessions_org_intent_attempt_unique` を
  見ている**。docblock の宣言は実装されている。**設計者の誤読だった**
- したがって現状は既に fail-closed であり、`$e->index` へ置き換えても振る舞いは変わらない。
  「今必要なものだけ作る」(思考原則 2) に照らして**やらない**
- ただし E-7 の脆さ(正規 replay では 3 本の unique が同時に違反し、報告される 1 本は
  index の OID 順で決まる)は `SubscriptionService` にも残る。
  **失敗方向は安全側**(黙って飲まず 500)であり今日の OID 順では発現しないため、
  **記録に留め本設計では直さない**(対処の約束ではない)

**変更ファイルなし。テスト追加なし。**

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
- **修正後**: 自然キー `(org, intent, $token)` の行は**存在しない**(退避したため)
  → `! $existing instanceof BillingCheckoutSession` が true → 再送出 = **緑**

**なぜハッシュを自前計算しないか**: fake の導出式をテストに写すと、fake を変えたとき
テストが「別の理由で」壊れる。1 回目を実行して S を DB に作らせ、衝突しない列だけを
退避する方式なら、**導出式に依存しない**。

**このテストが「意図した 1 本だけ」に当たることの根拠**: 退避後の DB には
`attempt_token`(別 ULID)・`idempotency_key`(`unrelated:` 前置)が今回の値と異なる行が 1 件だけあり、
`stripe_session_id` だけが一致する。したがって違反しうる unique は
`billing_checkout_sessions_stripe_session_id_unique` **1 本のみ**である
(E-7 の「同時に複数違反しうる」問題を踏まない)。

**ペアの緑テスト 2**(同ファイル):

```php
test('同一 attempt_token の replay は例外を漏らさず結果を返し行も増えない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    app()->instance(AutoRechargeGatewayInterface::class, new FakeAutoRechargeGateway);
    $service = app(AutoRechargeService::class);
    $token = (string) Str::ulid();

    $first = $service->startSetupCheckout($organization, $owner, 'https://ok.test', 'https://ng.test', $token);
    $second = $service->startSetupCheckout($organization, $owner, 'https://ok.test', 'https://ng.test', $token);

    expect($second['id'])->toBe($first['id']);
    expect(BillingCheckoutSession::query()->count())->toBe(1);
});
```

**この 2 本目こそが Codex Round 1 [Critical] を捕まえるテストである**。
撤回した旧案(制約名 `..._org_intent_attempt_unique` で判定)では、正規 replay で
**3 本が同時に違反**し、PostgreSQL が報告する 1 本は index の OID 順で決まる(E-7)。
今日の OID 順では偶然 `..._org_intent_attempt_unique` が最若なので旧案でも緑になるが、
migration に unique を 1 本足すだけで赤に転ぶ。自然キー判定はこの順序に依存しない。

**追加の赤テスト 3**(同ファイル):

```php
test('既存行の stripe_session_id が今回の値と食い違うなら replay として握らない', function (): void {
    // 1 回目を正規に実行 → 既存行の stripe_session_id **だけ**を別値へ書き換える
    //   (attempt_token / idempotency_key はそのまま = 自然キーでは見つかる)
    // → 同じ $token で再実行すると (org, intent, attempt_token) と idempotency_key の
    //   2 本が違反し、自然キー検索は行を見つけるが stripe_session_id が一致しない
    // → 再送出を期待する
    expect(fn () => $service->startSetupCheckout(...))
        ->toThrow(UniqueConstraintViolationException::class);
});
```
- **修正前**: 握り潰されて正常終了 = **赤**
- **修正後**: 同一性検査に落ちて再送出 = **緑**

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

### R-1c: (撤回。施策 1c を行わないため再現テストも作らない)

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

### M-1: 施策 1a の `$existing->stripe_session_id !== $result['id']` の条件を削除する
```
composer test -- --filter=AutoRechargeSetupCheckoutUniquenessTest
```
期待: **テスト 3(session id 食い違い)のみ赤** /
テスト 1(stripe_session_id-only 衝突)とテスト 2(正規 replay)は緑のまま。
→ 同一性検査の**この 1 条件**が load-bearing であることの実証。

### M-2: 施策 1a の catch 節から `if (...) { throw $e; }` を丸ごと削除する(= 常に握る)
期待: **テスト 1 とテスト 3 が赤** / **テスト 2 は緑のまま**。
→ 「握り潰しの復活」と「正規 replay の不変」の非対称を実測で観測する。

### M-2b: 施策 1a を**撤回した旧案**(制約名 `..._org_intent_attempt_unique` で判定)に差し替える
期待: **3 本とも緑**(今日の OID 順では旧案でも通るため)。
→ **これは旧案が正しいことの証明ではない**。E-7 の実測(OID 順で報告制約が変わる)と
併せて「テストでは差が出ないが依存してよい契約ではない」ことを mutation ログに明記する。
**この mutation は「テストで守れない差」を正直に記録するために行う**。

### M-3: 施策 1b の `if ($e->index === self::…)` を `if (true)` に変える
```
composer test -- --filter=AutoRechargeAttemptUniquenessTest
```
期待: **`attempt_ulid` テストのみ赤** / 既存 3 テストは緑のまま。

### M-4: 施策 1b の catch 節を丸ごと削除(= 例外が素通り)
期待: **既存の「unique violation は no-op へ収束し呼び出し側へ例外が漏れない」が赤** /
`attempt_ulid` テストは緑のまま。
→ **期待制約側の握りが load-bearing** であることの実証(M-3 と逆向きの非対称)。

### M-5: (撤回。施策 1c を行わないため mutation も無い)

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
   pgsql でも翻訳ロケールでは `null` になりうる。その場合の挙動は**握らず再送出**
   (fail-closed)だけで、**識別はできない**。「どの制約に当たったか」がログに残るのは
   例外メッセージ本文としてであって、構造化フィールドとしてではない
   (exclusion 制約は `23P01` なのでそもそも `UniqueConstraintViolationException` にならない。E-5)
2. **`$e->index` が「違反した全制約」を表すことは保証しない**。**複数の unique が同時に
   違反したとき PostgreSQL が報告するのは 1 本だけ**であり、どれになるかは index の
   OID 順(= 作成順)で決まる(E-7 の実測)。施策 1b が制約名判定を採れるのは
   「期待制約以外が同時に違反しえない」構造だからであって、一般則ではない
3. **施策 1b の制約名判定は index 順に依存しない代わりに、構造仮定に依存する**。
   `ticket_auto_recharge_attempts` に「insert 時点で値が確定していて衝突しうる unique」を
   将来足すと、この仮定は崩れる。**その検出は静的にはできない**
   (足した人が本設計を読む保証はない)。崩れたときの失敗方向は**安全側**
   (期待名と一致せず再送出)である
4. **制約名の drift を静的に検出しない**。PostgreSQL は識別子を 63 バイトで黙って切る
   (実 DB に `take_upload_reservations_organization_id_status_expires_at_inde` が実在する)。
   本設計が持つのは「名前がずれたら behavioral テストが赤くなる」という**事後検出だけ**である。
   `pg_indexes` を照合する専用 gate は作らない(思考原則 2)
5. **`SubscriptionService` に残る同型の脆さは直さない**(E-6 / E-7)。
   正規 replay で報告される制約が index 順の変化でずれると 500 になりうる。
   **失敗方向は安全側**であり今日の OID 順では発現しないため、記録のみとする
   (対処の約束ではない)
6. **「app 全体で unique 違反の握り潰しが無い」ことは保証しない**。
   概念設計の survey は本設計時点の手動走査であり、**静的 gate は作らない**。
   新しい握り潰しはレビューで見るしかない
7. **横断的な「既定値依存を禁止する gate」は新設しない**。aicue:T151 の設計が
   「判定式が静的に書けず偽陽性で gate の信用を落とす」として却下済み。蒸し返さない。
   したがって**施策 2 と同型の既定値依存が他に無いことは保証しない**
8. **孤児 Stripe session そのものは防げない**。Stripe session 作成は DB insert より前の
   外部 I/O なので、insert が落ちれば孤児 session は残る。本設計が変えるのは
   **「その状態が正常終了として扱われること」だけ**である
9. **これは観測可能性の改善であって UX 改善ではない**。ユーザー向けエラー文言は
   追加も変更もしない。これまで黙って通っていた障害が 500 相当で表面化する
10. **施策 2 は現在の外部挙動を何も変えない**。DB に入る値も API 応答も `bytes_pending` 集計も同じ。
   守るのは「この経路の意味が migration default に依存しない」ことだけ
11. **`AutoRechargeTriggerJob` の structured no-op の語彙は変えない**。
   変えるのは「no-op に収束させる条件」だけ
12. **並行起票そのものを減らさない**。DB 制約が最終防衛である構造 (aicue:T137/T148) は不変
13. **`migration` の `default` は消さない**。既存行と Factory 以外の INSERT 経路のために残す

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 変更は **2 ファイル・計 3 箇所**の小さな置換で、新モデル・新 migration・新 gate・新 const を伴わない。DB スキーマも API 形も変えないので main への追従コストが低い。施策 1a / 1b / 2 は互いに独立しており、片方だけ先に入っても他方は壊れない |
| 競合リスク | `AutoRechargeService.php` は auto-recharge 系 TODO と競合しうる(直近では aicue:T137/T140 が触っている)。ただし本変更は catch 節 2 個と private メソッド 1 個の削除に閉じるため衝突しても解決は容易。`TakeUploadService.php` は撮影 PWA 系の TODO と競合しうるが、変更は `forceFill` の 1 キー追加のみ。**`SubscriptionService.php` には触らない**(施策 1c 撤回) |
| 実装順序 | (1) 全再現テストを書いて**赤を実測し `red-before-fix.txt` に残す** → (2) 施策 1a → (3) 施策 1b → (4) 施策 2 → (5) 施策 3(文書) → (6) mutation M-1〜M-2b / M-3〜M-4 / M-6〜M-7 を**1 箇所ずつ**実施し `mutation.txt` に残す → (7) `composer fix && composer phpstan && composer test` |


---

## 修正後の概念設計 (全文。Round 1 の誤読訂正を反映済み)

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
   pgsql でも exclusion 制約と翻訳ロケールでは `null` になりうる。
   その場合は**握らず再送出する**(fail-closed)だけで、識別はできない
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


---

## Round 2 で特に見てほしい点

1. 施策 1a の**自然キー同一性判定**は fail-closed として十分か。飲んではいけないケースを飲まないか。
2. 施策 1b が**制約名判定のままでよい**という根拠 (期待制約以外が同時に違反しえない) に穴はないか。
3. 施策 1c の**撤回**は妥当か (設計者の誤読であり、現状は既に fail-closed)。
4. R-1a のテスト 1 / 2 / 3 は、それぞれ本当に修正前に赤 / 緑になるか。とくにテスト 2 が
   「今日の OID 順では旧案でも緑」であることを正直に書いた点の扱いは妥当か。
5. M-2b (撤回した旧案に差し替えても 3 本とも緑になる) を「テストで守れない差を正直に記録するために行う」
   としたのは妥当か。それとも mutation としては無意味なので削るべきか。
6. 「保証しないもの」13 項に嘘や漏れがないか。

判定を出してください (各施策 APPROVE / REQUEST_CHANGES + 全体判定 APPROVED / CHANGES_REQUESTED)。
