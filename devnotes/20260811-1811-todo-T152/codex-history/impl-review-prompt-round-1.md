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
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは Laravel + Svelte アプリのコードレビュアーである。以下の改善実装をレビューせよ。

## レビュー観点
- 設計との一致性 (詳細設計書どおりに実装されているか。逸脱があるなら妥当か)
- 正確性 (ロジックの誤り・境界条件・並行性・トランザクション/savepoint の扱い)
- PHPStan level 10 適合性 (型の widen / ignore を使っていないか)
- DTO / JsonResource パターン (`response()->json()` 直書きをしていないか)
- テスト網羅性 (fail-first で赤を確認したか、mutation が kill されているか、偽グリーンが無いか)
- セキュリティ (テナント境界・認可・mass assignment・保護キー)
- DESIGN.md 準拠 / Atomic Design 準拠 → **本 diff は resources/js・resources/css を 1 行も含まないため対象外**

## 出力形式
- ファイルごとに判定を書く
- 指摘は [Critical] / [Warning] / [Suggestion] に分類する
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** の一語で書く

---

## 背景

本タスクは aicue の TODO **T152** (既存 TODO T140 を吸収) の実装である。
「今は顕在化していないが条件が揃うと黙って壊れる」2 件を fail-closed 化する。
詳細設計は Codex 合議で Round 6 APPROVED 済み。

**やらないこと (タスク指示による制約)**:
- fail-closed が既定。識別できない unique 違反は再送出する
- migration の default は消さない
- 予約の状態遷移の CAS 規約を変えない (件 2 は初期値の話)
- 課金の成功時の挙動を変えない

## 詳細設計書 (全文)

```markdown
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

> **制約名(`$e->index`)で判定してよいのは、「通常のアプリ生成経路では期待制約以外が同時に
> 違反を構成しない」と構造的に言える場合だけである。言えないなら、自然キーで既存行を
> 読み直して同一性を確認する。**
>
> (「同時に違反しえない」という絶対表現は使わない。ULID 衝突のような確率的事象までは
> 排除できないため。**そして同時違反が起きた場合に安全側へ倒れる保証もない** ——
> 報告される 1 本は index 順で決まるので、`tar_attempts_org_pending_unique` が報告されれば
> 別異常を並行 race として握ってしまう。残留リスクとして「保証しないもの」§3 に明記する。)

| 施策 | 期待制約以外が同時に違反しうるか | 採る判定 |
|---|---|---|
| 1a `startSetupCheckout` | **する**。正規 replay では `org_intent_attempt` / `idempotency_key` / `stripe_session_id` の **3 本が同時に**違反する | **自然キーの同一性**(制約名を使わない) |
| 1b `maybeCreateAttempt` | **通常のアプリ生成経路では構成しない**。`attempt_ulid` は毎回新規 ULID、`stripe_invoice_id` は insert 時 NULL(NULL は unique に抵触しない)、pkey は serial。**ただし ULID 衝突等で同時違反が起きた場合の挙動は保証しない**(「保証しないもの」§3) | **制約名** |

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

### 同一性判定に `initiated_by_user_id` を**入れない**(契約として宣言する)

Codex Round 2 の [Warning]。`attempt_token` は client 供給値
(`StartAutoRechargeSetupRequest` の validated 値。`BillingController::startAutoRechargeSetup` L449)
なので、**同一 org の別ユーザーが同じ token を送る**ことは理屈上ありうる。
その場合、既存行の `initiated_by_user_id` は先行ユーザー A のままで、
後続ユーザー B の呼び出しは replay として握られる。

**これは意図した契約である**。理由:

1. **握ってよい条件は「書きたかった attempt が既に在る」であり、attempt の同一性を決めるのは
   `(organization_id, intent, attempt_token)` である**。`initiated_by_user_id` は
   「その attempt を**誰が起こしたか**」の記録であって、attempt の同一性を構成しない。
   既存行が A を指しているのは**正しい**(A がその Stripe session を作った)
2. **認可上の含意がない**。両者とも `Gate::authorize('manageBilling', $organization)` を
   通過した同一 org の billing 管理者であり、setup checkout は org 単位で
   支払い方法を org の Stripe customer に紐づける操作である。cross-org でも権限昇格でもない
3. **入れると成功時の振る舞いが変わる**。現状は actor を問わず握っている。
   actor 一致を条件に加えると、**今まで正常終了していた B の呼び出しが 500 になる**。
   本設計は「成功時の振る舞いを変えない」と宣言しており(「保証しないもの」§9)、
   これを破る変更は本設計のスコープ外である

**保証しないもの**として明記する: 本判定は「誰が起こした attempt か」を検証しない
(「保証しないもの」§14)。これを検証したい要求が将来出たら、それは
**同一性判定の話ではなく attribution の要件**として別途設計する。

この契約は**テスト 4** で固定する(下記 R-1a)。

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
  4. `同一 org の別 billing 管理者が同じ attempt_token を送っても replay として握る (actor は問わない)`
     — **修正前も後も緑**。actor を同一性判定に入れない契約の固定
     (入れてしまうと赤くなるので、契約が load-bearing であることも同時に示す)。
     **2 人目には対象 org の `manageBilling` を実際に付与し、Controller 経由
     (`POST /billing/auto-recharge/setup`)で叩く**。Service 直呼びでも同一性契約は検査できるが、
     「両者とも認可済み」という**設計根拠そのもの**は Controller 経由でないと固定できない
     (Codex Round 3 [Suggestion])
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
- **残留リスク(保証しない)**: 複数の unique が同時に違反した場合、報告される 1 本は
  index 順で決まるため、`tar_attempts_org_pending_unique` が報告されれば別異常を
  no-op として握りうる。通常のアプリ生成経路では同時違反を構成しない(ULID は毎回新規、
  `stripe_invoice_id` は NULL、pkey は serial)が、**「常に安全側」とは言えない**。
  「保証しないもの」§3 に明記する。確率的に極めて小さいため自然キー再照合は導入しない

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
- **新規 Architecture テストは作らない**。理由は「保証しないもの」§6・§7 のとおり。
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
テスト 1(stripe_session_id-only 衝突)・テスト 2(正規 replay)・テスト 4(別 actor)は緑のまま。
→ 同一性検査の**この 1 条件**が load-bearing であることの実証。

### M-2: 施策 1a の catch 節から `if (...) { throw $e; }` を丸ごと削除する(= 常に握る)
期待: **テスト 1 とテスト 3 が赤** / **テスト 2・4 は緑のまま**。
→ 「握り潰しの復活」と「正規 replay の不変」の非対称を実測で観測する。

### M-2c: 同一性判定に `|| $existing->initiated_by_user_id !== $user->getKey()` を**足す**
期待: **テスト 4(別 actor の replay)のみ赤** / テスト 1・2・3 は緑のまま。
→ 「actor を同一性判定に入れない」という契約が load-bearing であることの実証
(入れると benign な replay が 500 になる)。

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

### M-7: 復帰確認(基準は「全 green を通した後の基準コミット」)

**`git diff --stat app/` が空になることを基準にできるのは、実装順序 3〜4
(全 green → 基準コミット)を先に済ませている場合だけである**(Codex Round 2/3 [Warning])。

手順:

1. 実装順序 3 で `composer fix && composer phpstan && composer test` を通す
2. 実装順序 4 で基準コミットを打つ(`composer fix` の差分もここに含める)
3. 各 mutation / probe のあと、対象ファイルを戻す
4. 全部戻した後 `git diff --stat app/` が**空**であることを確認する
5. `composer phpstan && composer test` が緑であることを確認する

**`composer fix` を基準コミットより後に走らせない**(差分が出て基準がずれる)。

### 全体の最終確認
```
composer fix && composer phpstan && composer test
```

---

## 代替実装 probe(mutation ではない)

**mutation ではないので mutation.txt とは別に `alternative-probe.txt` に残す。**
mutation は「実装を壊すとテストが赤くなる」ことの確認であり、これは
「**テストでは識別できない設計上の差**」を正直に記録するための比較実験である
(Codex Round 2 [Suggestion])。両者を同じ節に置くと
「全 mutation が kill された」という読み方と衝突する。

### P-1: 施策 1a を**撤回した旧案**(制約名 `..._org_intent_attempt_unique` で判定)に差し替える

```
composer test -- --filter=AutoRechargeSetupCheckoutUniquenessTest
```

**予測**: 4 本とも緑になる。今日の index OID 順では
`billing_checkout_sessions_org_intent_attempt_unique` が unique の中で最若(91838)であり、
正規 replay で pg が報告するのはこの 1 本だからである(E-7)。

**この結果が意味すること**: 「旧案が正しい」ではない。
**新規テスト 4 本では旧案と新案を区別できない**ということである。
区別を担保しているのは E-7 の実測(index を 1 本足す/並べ替えると報告制約が変わる)であって、
テストではない。**この限界を `alternative-probe.txt` に明記する**。

**予測が外れた場合**(旧案で赤が出た場合): OID 順の理解が誤っているということなので、
`pg_index` を再照会して E-7 を訂正し、設計判断を見直す。


---

## 保証しないもの(実装後も成立しない事柄。先に宣言する)

1. **`$e->index` が常に取れることは保証しない**。sqlite では常に `null`、
   pgsql でも翻訳ロケールでは `null` になりうる。その場合の挙動は**握らず再送出**
   (fail-closed)だけで、**識別はできない**。「どの制約に当たったか」がログに残るのは
   例外メッセージ本文としてであって、構造化フィールドとしてではない
   (exclusion 制約は `23P01` なのでそもそも `UniqueConstraintViolationException` にならない。E-5)
2. **`$e->index` が「違反した全制約」を表すことは保証しない**。**複数の unique が同時に
   違反したとき PostgreSQL が報告するのは 1 本だけ**であり、どれになるかは index の
   OID 順(= 作成順)で決まる(E-7 の実測)。**施策 1b が制約名判定を採るのは、
   通常のアプリ生成経路では期待制約以外との同時違反を構成しないためであって、一般則ではない**
   (同時違反が起きたときの挙動は §3 のとおり保証しない)
3. **施策 1b は「通常のアプリ生成経路では別制約との同時違反を構成しない」ことを前提とする。
   前提が崩れたときに安全側へ倒れる保証はない**。
   ULID 衝突・sequence drift 等で複数制約が同時に違反した場合、報告される 1 本は
   index 順で決まる(§2)。**報告制約が `tar_attempts_org_pending_unique` になれば、
   別異常を並行 race として no-op で握る可能性を排除しない**。
   確率的に極めて小さい残留リスクであり、自然キー再照合や新 gate は追加しない
   (思考原則 2)。また `ticket_auto_recharge_attempts` に「insert 時点で値が確定していて
   衝突しうる unique」を将来足すとこの前提は崩れるが、**その検出は静的にはできない**
   (足した人が本設計を読む保証はない)
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
14. **施策 1a の同一性判定は「誰が起こした attempt か」を検証しない**。
    同一 org の別 billing 管理者が同じ `attempt_token`(client 供給値)を送った場合、
    先行ユーザーの行を replay として握り、**後続ユーザーは先行ユーザーが作った
    Stripe session へ送られる**。cross-org でも権限昇格でもなく、現状の振る舞いと同じである。
    attribution を検証したい要求が出たら、同一性判定ではなく**別の要件**として設計する
15. **`alternative-probe.txt` の P-1 が緑でも、旧案が正しいことは意味しない**。
    テスト 4 本では旧案と新案を区別できないという**限界の記録**である

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | **アプリコードの変更は 2 ファイル・計 3 箇所**(ほかに文書 2 ファイルとテスト 3 ファイル)の小さな置換で、新モデル・新 migration・新 gate・**新しい共有抽象や制約名台帳**を伴わない(施策 1b が `private const string ATTEMPT_ORG_PENDING_UNIQUE` を 1 本追加するのみ)。DB スキーマも API 形も変えないので main への追従コストが低い。施策 1a / 1b / 2 は互いに独立しており、片方だけ先に入っても他方は壊れない |
| 競合リスク | `AutoRechargeService.php` は auto-recharge 系 TODO と競合しうる(直近では aicue:T137/T140 が触っている)。ただし本変更は catch 節 2 個と private メソッド 1 個の削除に閉じるため衝突しても解決は容易。`TakeUploadService.php` は撮影 PWA 系の TODO と競合しうるが、変更は `forceFill` の 1 キー追加のみ。**`SubscriptionService.php` には触らない**(施策 1c 撤回) |
| 実装順序 | 下表のとおり(Codex Round 3 [Warning] を反映。**検証を済ませてから基準コミットを打つ**) |

### 実装順序(確定)

1. 全テストを先に追加し、**修正前の期待マトリクスと一致することを実測して
   `red-before-fix.txt` に残す**。「全部赤にする」のではない —— 期待は次のとおり:

   | テスト | 修正前 | 意味 |
   |---|---|---|
   | R-1a テスト 1(`stripe_session_id`-only 衝突) | **赤** | 別制約の握り潰しの再現 |
   | R-1a テスト 2(正規 replay) | **緑** | 成功時の振る舞いを変えないことの基準 |
   | R-1a テスト 3(既存行の session id 食い違い) | **赤** | 壊れた台帳を飲まないことの再現 |
   | R-1a テスト 4(別 actor の同 token) | **緑** | actor 非検証の契約の基準 |
   | R-1b(`attempt_ulid` 衝突) | **赤** | 別制約の no-op 収束の再現 |
   | R-2(in-memory `status`) | **赤** | 既定値依存の再現 |

   **赤 4 本(R-1a テスト 1・3 / R-1b / R-2)・緑 2 本(R-1a テスト 2・4)が
   期待マトリクスどおりに揃ってから**実装に入る
   (緑であるべきものが赤なら前提が誤っているので設計を見直す)
2. 施策 1a → 1b → 2 → 3(文書)を実装する
3. **`composer fix && composer phpstan && composer test` を通す**(全 green)
4. **基準コミットを打つ**(mutation の復帰基準。`composer fix` の差分もここに含める)
5. mutation **M-1 / M-2 / M-2c / M-3 / M-4 / M-6** を**1 箇所ずつ**実施し `mutation.txt` に残す
6. 代替実装 probe **P-1** を実施し `alternative-probe.txt` に残す
7. **M-7**: すべて戻し `git diff --stat app/` が**空**(= 基準コミットと同一)であることを確認
8. 最終確認: `composer phpstan && composer test`

**`composer fix` をコミット後に走らせない**(差分が出て基準がずれる)。

---

## 最終確認(使命・禁止事項チェック / app-design Phase 2-5)

### 使命への寄与
本設計は撮影・教材生成の機能そのものを増やさない。寄与は間接的で、
**「思考ゼロ・編集ゼロ」を支える基盤(課金の自動リチャージ / 撮影 PWA のアップロード予約)が
壊れたときに黙らないようにする**ことである。誇張しない。

### 禁止事項チェック

| # | 禁止事項 | 本設計での状態 |
|---|---|---|
| 1 | テストなしの実装完了報告 | 全施策に Pest テスト。fail-first の期待マトリクスと mutation 手順まで設計済み。**施策 3(文書)は behavioral テストが守る契約の説明**であり単独の gate は作らない |
| 2 | PHPStan エラーの widen / baseline 化 | なし。`$existing` は `instanceof` で narrowing、`$e->index` は `?string` のまま比較 |
| 3 | dev DB への破壊操作 | なし。実測は **TEMP 表と読み取り専用照会のみ**(`pg_indexes` / `pg_index` / `information_schema`) |
| 4 | `response()->json()` 直書き | なし(戻り値の型を一切変えない) |
| 5 | Prism 直呼び | 該当なし |
| 6 | prompt 文字列のコード直書き | 該当なし |
| 7 | `redirect()->intended()` | 該当なし |
| 8 | 未充足でボタン disabled | 該当なし(UI 変更なし) |
| 9 | Artifact の使用 | 使っていない。成果物はすべて `devnotes/` 配下のファイル |

### 思考原則チェック

| # | 原則 | 本設計での状態 |
|---|---|---|
| 1 | フレームワークのレンジ内でやる | 自前の正規表現を書かず Laravel 13 の `UniqueConstraintViolationException` を使う。使えない場面(E-7)は**自然キー再照合**という既存パターン(`SubscriptionService`)に揃える |
| 2 | 今必要なものだけ作る | 施策 1c を**撤回**。新 gate / 新 inventory / 制約名台帳 / 共有抽象を作らない。追加する const は 1 本のみ |
| 3 | 後方互換の並走を残さない | `isUniqueViolation()` はメソッドごと削除する |
| 4 | 別物の概念を統合しない | 件 1 と件 2 を共通化しない。施策 1a と 1b も判定方式を分ける(規則は明文化した) |
| 5 | テストファースト | fail-first の期待マトリクス(赤 4 / 緑 2)を実測してから実装に入る |
| 6 | タコツボ実装を避ける | `SubscriptionService` / `PersonalPlanService` / `CreateNewUser` / `TakeRegistrationService` / `LlmCallLog` / `McpIdempotencyService` を survey 済み。直さないものは**理由付きで**記録した |

### ドメイン規約チェック
- **規約 2(容量 Quota の予約規約)**: 施策 2 は **INSERT の初期値**であり状態遷移ではない。
  CAS 規約(`直接 UPDATE を書かない`)には触れない。規約側に 1 文追記して契約を明示する(施策 3)
- **規約 1 (ii)**: `video_manuals` 系が対象で `take_upload_reservations` は対象外だが、
  **同じ理由が同じ形で当てはまる**ことを施策 2 の根拠として引用した
- **規約 6(ジョブの重複実行と結果の一回性)**: 施策 1b は「no-op に収束させる条件」を
  絞るだけで、DB 制約が最終防衛である構造(aicue:T137 / T148)は変えない

### Codex 合議の結果
- 概念設計: **Round 1 で APPROVED**(Critical 0)
- 詳細設計: **Round 6 で APPROVED**。Round 1〜5 で Critical 3 件 / Warning 9 件 / Suggestion 4 件。
  **Critical 3 件はすべて設計者側の誤りであり、実 DB・vendor・実コードで裏を取ってから全面的に受け入れた**
  (制約名判定の破綻 / 施策 1c の前提となった誤読 / exclusion 制約の SQLSTATE)。
  反論して現状維持にしたのは「見出しの重複」1 件のみで、これも Round 5 で Codex が追認した。
```

## 実装差分 (git show HEAD — app/ tests/ docs/ AGENTS.md)

```diff
diff --git a/AGENTS.md b/AGENTS.md
index 7b1b77c..4d890df 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -344,6 +344,11 @@ ## ドメイン固有規約
    `Billing/QuotaService::checkAddition` + `Capture/StorageUsageService::occupiedBytes`
    (bytes_used + bytes_pending) 経由のみ。予約 (`take_upload_reservations`) の状態遷移は
    pending→verifying (claim)→completed/released の CAS で行い、直接 UPDATE を書かない。
+   **初期状態 `pending` は INSERT 時に明示代入する** (`TakeUploadService::issue()`。
+   DB カラム default に依存しない = migration default 変更による silent break と、
+   `save()` 直後の in-memory instance の属性欠落の両方を防ぐ。ドメイン規約 1 (ii) と同じ理由)。
+   **これは状態遷移ではないので上の CAS 規約とは独立である**。
+   migration の `default('pending')` は既存行と Factory 以外の INSERT 経路のために残す。
    運用契約 (media queue worker / 孤児掃除 cron) は `docs/architecture.md` §撮影 PWA
 3. **サポート対象ブラウザと履歴復元の扱い**: 「どのブラウザで何をどこまで保証しているか」の
    正本は **`docs/supported-browsers.md`**。**Inertia が描画する認証済み画面**が
diff --git a/app/Services/Billing/AutoRechargeService.php b/app/Services/Billing/AutoRechargeService.php
index 648e1a6..9c449df 100644
--- a/app/Services/Billing/AutoRechargeService.php
+++ b/app/Services/Billing/AutoRechargeService.php
@@ -31,7 +31,7 @@
 use App\Support\JobExecution\AttemptOwnershipPreflight;
 use Carbon\CarbonImmutable;
 use Illuminate\Contracts\Cache\LockTimeoutException;
-use Illuminate\Database\QueryException;
+use Illuminate\Database\UniqueConstraintViolationException;
 use Illuminate\Support\Facades\Cache;
 use Illuminate\Support\Facades\DB;
 use Illuminate\Support\Facades\Log;
@@ -79,6 +79,13 @@ final class AutoRechargeService
      */
     public const int LOCK_TTL_SECONDS = 180;
 
+    /**
+     * 期待する unique 制約 (この 1 本だけを並行起票の敗者として握る)。
+     * 部分 UNIQUE index (WHERE status = 'pending') = 「org に pending は同時に 1 つまで」。
+     * 名前の正本は create_ticket_auto_recharge_attempts_table migration の PENDING_INDEX_NAME。
+     */
+    private const string ATTEMPT_ORG_PENDING_UNIQUE = 'tar_attempts_org_pending_unique';
+
     public function __construct(
         private readonly TicketLedgerService $tickets,
         private readonly TicketPricingService $pricing,
@@ -302,11 +309,35 @@ public function startSetupCheckout(
                 ]);
                 $session->save();
             });
-        } catch (QueryException $e) {
-            if (! $this->isUniqueViolation($e)) {
+        } catch (UniqueConstraintViolationException $e) {
+            // **制約名では判定しない**。正規 replay では 3 本の unique
+            // (org+intent+attempt_token / idempotency_key / stripe_session_id) が**同時に**違反し、
+            // PostgreSQL が報告する 1 本は index の作成順 (OID 昇順) で決まるため、
+            // アプリの意味論として依存できない (詳細設計 E-7 の実測)。
+            //
+            // 代わりに**自然キーで既存行を読み直し、同一内容であることを確認**する。
+            // 一致しない / 行が無い場合は fail-closed で再送出する — 握ると
+            // 「Stripe session はあるのに台帳行が無い」状態が正常終了として通ってしまう。
+            // (失敗した insert は上の tx / savepoint で巻き戻っているので、この SELECT は
+            //  pgsql の 25P02 に当たらない。SubscriptionService::startCheckout と同じ形。)
+            //
+            // 同一性判定に initiated_by_user_id は**入れない**。attempt の同一性を決めるのは
+            // (organization_id, intent, attempt_token) であり、actor は「誰が起こしたか」の
+            // 記録に過ぎない (両者とも manageBilling 済みの同一 org 管理者)。入れると
+            // これまで正常終了していた別 actor の replay が 500 になる。
+            $existing = BillingCheckoutSession::query()
+                ->where('organization_id', $this->orgId($organization))
+                ->where('intent', CheckoutIntent::SetupPaymentMethod->value)
+                ->where('attempt_token', $attemptToken)
+                ->first();
+
+            if (! $existing instanceof BillingCheckoutSession
+                || $existing->stripe_session_id !== $result['id']
+                || $existing->idempotency_key !== $idempotencyKey) {
                 throw $e;
             }
-            // 同一 attempt_token の replay — Stripe 側も同一冪等キーで同一 session を返している。
+            // 同一 attempt_token の replay — Stripe 側も同一冪等キーで同一 session を返しており、
+            // 既存行の session id / 冪等キーが今回の値と一致することを確認済み。
         }
 
         return $result;
@@ -506,12 +537,16 @@ private function createAttemptLocked(Organization $organization): ?TicketAutoRec
 
                 return $attempt;
             });
-        } catch (QueryException $e) {
-            if ($this->isUniqueViolation($e)) {
-                // DB partial unique (tar_attempts_org_pending_unique) が最終防衛。並行起票は no-op。
+        } catch (UniqueConstraintViolationException $e) {
+            if ($e->index === self::ATTEMPT_ORG_PENDING_UNIQUE) {
+                // DB partial unique が最終防衛。並行起票は no-op。
                 return null;
             }
 
+            // fail-closed: attempt_ulid / stripe_invoice_id / pkey の違反と、制約名を
+            // 特定できなかった場合 ($e->index === null) は握らない。握ると
+            // AutoRechargeTriggerJob が structured no-op として黙り、その組織のリチャージが
+            // 起票されないまま誰も気づかない。
             throw $e;
         }
     }
@@ -1497,12 +1532,4 @@ private function currentConsentVersion(): string
 
         return $version;
     }
-
-    private function isUniqueViolation(QueryException $e): bool
-    {
-        // driver 差吸収 (23505 = pgsql / 23000 = sqlite・mysql)。
-        $sqlState = $e->errorInfo[0] ?? null;
-
-        return $sqlState === '23505' || $sqlState === '23000';
-    }
 }
diff --git a/app/Services/Capture/TakeUploadService.php b/app/Services/Capture/TakeUploadService.php
index f7913c8..ff1ff70 100644
--- a/app/Services/Capture/TakeUploadService.php
+++ b/app/Services/Capture/TakeUploadService.php
@@ -7,6 +7,7 @@
 use App\DataTransferObjects\Capture\TakeUploadInput;
 use App\DataTransferObjects\Capture\TakeUploadTicketData;
 use App\DataTransferObjects\Capture\UploadTicketClaims;
+use App\Enums\Capture\TakeUploadReservationStatus;
 use App\Enums\Manual\VideoManualStatus;
 use App\Enums\QuotaKey;
 use App\Models\Cut;
@@ -81,7 +82,15 @@ public function issue(Organization $organization, Project $project, VideoManual
                 'checksum_sha256' => $input->checksum->base64,
                 'expires_at' => $expiresAt,
             ]);
-            $reservation->forceFill(['organization_id' => $lockedOrg->id])->save();
+            // organization_id は保護キー、status は保護状態列のため $fillable 外 (forceFill で代入)。
+            // status は**初期状態の明示代入**であり状態遷移ではない (AGENTS.md ドメイン規約 2 の
+            // 「直接 UPDATE を書かない」は pending→verifying 以降の CAS の話。ドメイン規約 1 (ii) と
+            // 同じ理由で、DB カラム default に依存すると (a) migration default 変更でこの経路の
+            // 意味だけが黙って変わり (b) save() 直後の in-memory instance の status が null になる)。
+            $reservation->forceFill([
+                'organization_id' => $lockedOrg->id,
+                'status' => TakeUploadReservationStatus::Pending,
+            ])->save();
 
             return $reservation;
         });
diff --git a/docs/architecture.md b/docs/architecture.md
index 6bb1d22..a45a0a7 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -972,7 +972,9 @@ ## 撮影 PWA (presigned アップロード + 容量 Quota) の運用契約
 
 - **presigned 直アップロード**: `Capture/TakeUploadService` が Organization 行ロック tx 内で
   容量 Quota (`max_storage_bytes`。bytes_used + bytes_pending + 加算) を判定し
-  `take_upload_reservations` (pending) を予約 → `Capture/TakeObjectStorage` が
+  `take_upload_reservations` (pending) を予約
+  (**初期 status は INSERT 時に明示代入**する。DB カラム default は既存行と他の INSERT 経路の
+  ために残すが、この経路の意味は default に依存しない) → `Capture/TakeObjectStorage` が
   **ChecksumSHA256 を署名条件に含む** presigned PUT URL + Crypt 封緘の検証専用チケットを発行
   (封緘/開封は `Capture/UploadTicketCodec` に集約。AEAD で改竄検出し、復号失敗・shape 不正・
   期限切れは null → 呼び出し側が 422 に変換。payload 種別キーで upload チケットと
diff --git a/tests/Feature/Billing/AutoRechargeAttemptUniquenessTest.php b/tests/Feature/Billing/AutoRechargeAttemptUniquenessTest.php
index 0e0fec0..b60bb25 100644
--- a/tests/Feature/Billing/AutoRechargeAttemptUniquenessTest.php
+++ b/tests/Feature/Billing/AutoRechargeAttemptUniquenessTest.php
@@ -10,6 +10,7 @@
 use App\Services\Billing\AutoRechargeService;
 use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
 use Illuminate\Database\QueryException;
+use Illuminate\Database\UniqueConstraintViolationException;
 use Illuminate\Support\Facades\DB;
 use Illuminate\Support\Str;
 use Tests\Support\FakeAutoRechargeGateway;
@@ -24,8 +25,9 @@
 |  (2) tar_attempts_org_pending_unique (partial unique) — DB の最終防衛
 |  (3) unique violation の no-op 収束 (呼び出し側へ例外を漏らさない)
 |
-| ★ (3) の判定 (isUniqueViolation) は SQLSTATE だけを見て制約名を識別しない。
-|   これは本 PR で作った問題ではなく、docs/TODO.md へ Low で追跡起票済みである。
+| ★ (3) の判定は当初 SQLSTATE だけを見て制約名を識別しなかった (T140)。
+|   現在は期待制約 tar_attempts_org_pending_unique **1 本だけ**を握り、それ以外は再送出する
+|   (fail-closed)。下の attempt_ulid テストがその境界を固定する。
 */
 
 beforeEach(function (): void {
@@ -123,3 +125,29 @@ function attemptUniquenessContext(): array
     expect($result)->toBeNull();
     expect(TicketAutoRechargeAttempt::query()->count())->toBe(0);
 });
+
+test('別の unique 制約 (attempt_ulid) の違反は no-op へ収束させず再送出する', function (): void {
+    [$organization] = attemptUniquenessContext();
+    [$otherOrganization] = createOrganizationWithOwner();
+
+    // pending 検査の**後**・INSERT の**直前**に、**別 org**で**同じ attempt_ulid** の行を作る。
+    // 別 org なので部分 unique (org 単位) には触れず、attempt_ulid unique **だけ**が違反する。
+    // DB::table は model event を発火しないため再入しない。
+    TicketAutoRechargeAttempt::creating(function (TicketAutoRechargeAttempt $attempt) use ($otherOrganization): void {
+        DB::table('ticket_auto_recharge_attempts')->insert([
+            'organization_id' => $otherOrganization->getKey(),
+            'attempt_ulid' => $attempt->attempt_ulid,   // ← 衝突させたい 1 本
+            'status' => AutoRechargeAttemptStatus::Pending->value,
+            'quantity' => 10,
+            'unit_amount' => 70,
+            'stripe_price_id' => 'price_other',
+            'created_at' => now(),
+            'updated_at' => now(),
+        ]);
+    });
+
+    // 握ると AutoRechargeTriggerJob が structured no-op として黙り、その組織のリチャージが
+    // 起票されないまま誰も気づかない。期待制約以外は fail-closed で再送出する。
+    expect(fn () => app(AutoRechargeService::class)->maybeCreateAttempt($organization))
+        ->toThrow(UniqueConstraintViolationException::class);
+});
diff --git a/tests/Feature/Billing/AutoRechargeSetupCheckoutUniquenessTest.php b/tests/Feature/Billing/AutoRechargeSetupCheckoutUniquenessTest.php
new file mode 100644
index 0000000..bd9ee8a
--- /dev/null
+++ b/tests/Feature/Billing/AutoRechargeSetupCheckoutUniquenessTest.php
@@ -0,0 +1,115 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\CheckoutIntent;
+use App\Enums\OrganizationRole;
+use App\Models\Billing\BillingCheckoutSession;
+use App\Services\Billing\AutoRechargeService;
+use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
+use Illuminate\Database\UniqueConstraintViolationException;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Str;
+use Tests\Support\FakeAutoRechargeGateway;
+
+/*
+|--------------------------------------------------------------------------
+| startSetupCheckout の unique 握りは「書きたかった行が既に在る」ときだけ
+|--------------------------------------------------------------------------
+|
+| 旧実装は SQLSTATE (23505 / 23000) だけを見て握っていたため、**別の unique 制約**の
+| 違反も「同一 attempt_token の replay」として黙って正常終了していた
+| (= Stripe session はあるのに台帳行が無い状態が成功として通る)。
+|
+| 制約名 ($e->index) では判定しない。正規 replay では 3 本の unique
+| (org+intent+attempt_token / idempotency_key / stripe_session_id) が**同時に**違反し、
+| PostgreSQL が報告する 1 本は index の作成順 (OID 昇順) で決まるためである
+| (詳細設計 E-7 の実測)。代わりに**自然キーで既存行を読み直して同一性を確認**する。
+*/
+
+beforeEach(function (): void {
+    $this->gateway = new FakeAutoRechargeGateway;
+    app()->instance(AutoRechargeGatewayInterface::class, $this->gateway);
+});
+
+test('別の unique 制約 (stripe_session_id) の違反は握り潰さず再送出する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $service = app(AutoRechargeService::class);
+    $token = strtolower((string) Str::ulid());
+
+    // 1 回目: 正規に台帳行を作る (session id S は idempotency key から決定的に導出される)
+    $service->startSetupCheckout($organization, $owner, 'https://ok.test', 'https://ng.test', $token);
+    $row = BillingCheckoutSession::query()->sole();
+
+    // 2 回目が「stripe_session_id **だけ**衝突する」状況を作る:
+    //   既存行の attempt_token / idempotency_key を別値へ退避し、stripe_session_id は S のまま残す。
+    //   → 同じ $token で再実行すると (org, intent, attempt_token) と idempotency_key は衝突せず、
+    //      stripe_session_id **1 本だけ**が違反する (E-7 の同時違反問題を踏まない)。
+    // fake の導出式をテストへ写さないため、S は 1 回目の実行に作らせている。
+    DB::table('billing_checkout_sessions')->where('id', $row->id)->update([
+        'attempt_token' => strtolower((string) Str::ulid()),
+        'idempotency_key' => 'unrelated:'.strtolower((string) Str::ulid()),
+    ]);
+
+    expect(fn () => $service->startSetupCheckout($organization, $owner, 'https://ok.test', 'https://ng.test', $token))
+        ->toThrow(UniqueConstraintViolationException::class);
+});
+
+test('同一 attempt_token の replay は例外を漏らさず結果を返し行も増えない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $service = app(AutoRechargeService::class);
+    $token = strtolower((string) Str::ulid());
+
+    $first = $service->startSetupCheckout($organization, $owner, 'https://ok.test', 'https://ng.test', $token);
+    $second = $service->startSetupCheckout($organization, $owner, 'https://ok.test', 'https://ng.test', $token);
+
+    // 成功時の振る舞いを変えていないことの基準 (fail-closed 化で replay を壊していない)
+    expect($second['id'])->toBe($first['id']);
+    expect(BillingCheckoutSession::query()->count())->toBe(1);
+});
+
+test('既存行の stripe_session_id が今回の値と食い違うなら replay として握らない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $service = app(AutoRechargeService::class);
+    $token = strtolower((string) Str::ulid());
+
+    $service->startSetupCheckout($organization, $owner, 'https://ok.test', 'https://ng.test', $token);
+    $row = BillingCheckoutSession::query()->sole();
+
+    // 自然キー (org, intent, attempt_token) では**見つかる**が、内容が食い違う状態を作る。
+    // = 台帳が壊れている状態。これを replay として飲むと障害が正常終了として通る。
+    DB::table('billing_checkout_sessions')->where('id', $row->id)->update([
+        'stripe_session_id' => 'cs_setup_tampered_'.strtolower((string) Str::ulid()),
+    ]);
+
+    expect(fn () => $service->startSetupCheckout($organization, $owner, 'https://ok.test', 'https://ng.test', $token))
+        ->toThrow(UniqueConstraintViolationException::class);
+});
+
+test('同一 org の別 billing 管理者が同じ attempt_token を送っても replay として握る (actor は問わない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    // 2 人目にも実際に manageBilling を持たせる (= 両者とも認可済みという設計根拠を固定するため
+    // Service 直呼びではなく Controller 経由で叩く)
+    $secondManager = attachOrganizationMember($organization, OrganizationRole::Admin);
+    $secondManager->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $token = strtolower((string) Str::ulid());
+
+    $this->actingAs($owner)
+        ->post('/billing/auto-recharge/setup', ['attempt_token' => $token]);
+
+    // 同一性判定に initiated_by_user_id を**入れない**契約 (詳細設計「保証しないもの」§14)。
+    // 入れると benign なこの replay が 500 になる = 契約が load-bearing であることの固定。
+    $this->actingAs($secondManager)
+        ->post('/billing/auto-recharge/setup', ['attempt_token' => $token])
+        ->assertRedirect($this->gateway->setupUrl); // 500 にならず checkout へ送られる
+
+    $sessions = BillingCheckoutSession::query()
+        ->where('organization_id', $organization->id)
+        ->where('intent', CheckoutIntent::SetupPaymentMethod->value)
+        ->get();
+
+    // 行は増えず、initiated_by_user_id は先行ユーザーのまま (attempt を起こした記録として正しい)
+    expect($sessions)->toHaveCount(1)
+        ->and($sessions->firstOrFail()->initiated_by_user_id)->toBe($owner->id);
+});
diff --git a/tests/Feature/Capture/TakeUploadUrlTest.php b/tests/Feature/Capture/TakeUploadUrlTest.php
index c14c22e..1fd6b62 100644
--- a/tests/Feature/Capture/TakeUploadUrlTest.php
+++ b/tests/Feature/Capture/TakeUploadUrlTest.php
@@ -16,6 +16,7 @@
 use App\Services\Capture\StorageUsageService;
 use App\Services\Capture\TakeObjectStorage;
 use Carbon\CarbonImmutable;
+use Illuminate\Support\Facades\DB;
 use Illuminate\Support\Str;
 use Mockery\MockInterface;
 
@@ -255,3 +256,26 @@ function uploadUrlPath(Project $project, VideoManual $manual, Cut $cut): string
         uploadUrlPayload(['checksum_sha256' => $tooLong]),
     )->assertStatus(422);
 });
+
+test('issue() が保持する予約インスタンスは refresh なしで status=pending を持つ', function (): void {
+    [, $owner, $project, $manual, $cut] = uploadUrlContext();
+    mockPresign();
+
+    /** @var TakeUploadReservation|null $captured */
+    $captured = null;
+    TakeUploadReservation::created(function (TakeUploadReservation $reservation) use (&$captured): void {
+        $captured = $reservation;   // service が save() した当のインスタンス
+    });
+
+    $this->actingAs($owner)
+        ->postJson(uploadUrlPath($project, $manual, $cut), uploadUrlPayload())
+        ->assertOk();
+
+    // in-memory: 明示代入していないと属性ごと存在せず null になる (DB default では埋まらない)。
+    // 既存テスト「発行成功: pending 予約が作成され…」は uploadReservations()->sole() = **DB 再読込**
+    // なので DB default で緑になり、この欠落を検出できなかった。
+    expect($captured)->not->toBeNull();
+    expect($captured->status)->toBe(TakeUploadReservationStatus::Pending);
+    // enum → backing value の往復が効いていること (DB 実値も pending)
+    expect(DB::table('take_upload_reservations')->where('id', $captured->id)->value('status'))->toBe('pending');
+});
```

## fail-first の実測 (修正前)

```
=====================================================================
T152 fail-first 実測ログ (修正前の期待マトリクス確認)
=====================================================================

時刻   : 2026-08-11 18:14 JST 〜 18:20 JST
HEAD   : 5f1b2cff76a2236e73093973e76afe76977deb6e (todo/T152 = main 起点、実装前)
worktree: /workspace/.claude/worktrees/tasks/T152
DB     : pgsql (phpunit.xml が DB_CONNECTION=pgsql を force)

この時点で **テストのみ追加済み・app/ は 1 行も変更していない**
(git diff --stat app/ は空)。

---------------------------------------------------------------------
[1] 3 ファイル一括
---------------------------------------------------------------------
$ composer test -- --filter='AutoRechargeSetupCheckoutUniquenessTest|AutoRechargeAttemptUniquenessTest|TakeUploadUrlTest'

 INFO Configuration cache cleared successfully.

ensure-test-db: base DB already exists: app_test_922486d1
{"tool":"pest","result":"failed","tests":28,"passed":24,"assertions":81,"duration_ms":15132,"failed":4,"failures":[
  {"test":"AutoRechargeAttemptUniquenessTest::別の unique 制約 (attempt_ulid) の違反は no-op へ収束させず再送出する",
   "file":".../tests/Feature/Billing/AutoRechargeAttemptUniquenessTest.php","line":152,
   "message":"Exception \"Illuminate\\Database\\UniqueConstraintViolationException\" not thrown."},
  {"test":"AutoRechargeSetupCheckoutUniquenessTest::別の unique 制約 (stripe_session_id) の違反は握り潰さず再送出する",
   "file":".../tests/Feature/Billing/AutoRechargeSetupCheckoutUniquenessTest.php","line":55,
   "message":"Exception \"Illuminate\\Database\\UniqueConstraintViolationException\" not thrown."},
  {"test":"AutoRechargeSetupCheckoutUniquenessTest::既存行の stripe_session_id が今回の値と食い違うなら replay として握らない",
   "file":".../tests/Feature/Billing/AutoRechargeSetupCheckoutUniquenessTest.php","line":86,
   "message":"Exception \"Illuminate\\Database\\UniqueConstraintViolationException\" not thrown."},
  {"test":"TakeUploadUrlTest::issue() が保持する予約インスタンスは refresh なしで status=pending を持つ",
   "file":".../tests/Feature/Capture/TakeUploadUrlTest.php","line":278,
   "message":"Failed asserting that null is identical to an object of class \"App\\Enums\\Capture\\TakeUploadReservationStatus\"."}
]}
Script bash scripts/run-test.sh handling the test event returned with error code 1

(注: 上記 JSON の "test" キーは実出力では Unicode エスケープ済み。可読性のため
 このログでは日本語に復元した。値そのものは改変していない)

---------------------------------------------------------------------
[2] 施策 1a の新規ファイル単独 (緑であるべき 2 本の確認)
---------------------------------------------------------------------
$ composer test -- --filter='AutoRechargeSetupCheckoutUniquenessTest'

{"tool":"pest","result":"failed","tests":4,"passed":2,"assertions":8,"duration_ms":6333,"failed":2, ...}

→ 4 本中 2 本 passed。passed の 2 本は failures に現れない
  「同一 attempt_token の replay は例外を漏らさず結果を返し行も増えない」と
  「同一 org の別 billing 管理者が同じ attempt_token を送っても replay として握る」である。

---------------------------------------------------------------------
判定: 詳細設計「実装順序 1」の期待マトリクスと **完全一致**
---------------------------------------------------------------------
| テスト                                          | 期待 | 実測 |
|-------------------------------------------------|------|------|
| R-1a テスト 1 (stripe_session_id-only 衝突)      | 赤   | 赤   |
| R-1a テスト 2 (正規 replay)                      | 緑   | 緑   |
| R-1a テスト 3 (既存行の session id 食い違い)     | 赤   | 赤   |
| R-1a テスト 4 (別 actor の同 token)              | 緑   | 緑   |
| R-1b (attempt_ulid 衝突)                        | 赤   | 赤   |
| R-2 (in-memory status)                          | 赤   | 赤   |

赤 4 本 / 緑 2 本。「緑であるべきものが赤」は 0 件のため、設計の前提は成立している。

赤の意味 (修正前の実挙動):
- 施策 1a/1b: isUniqueViolation() が SQLSTATE だけを見るため、**期待制約以外の
  unique 違反も**「replay」「並行起票の敗者」として握り潰され、正常終了していた。
- 施策 2: TakeUploadService::issue() が status を明示代入しないため、save() 直後の
  in-memory インスタンスには status 属性が存在せず null になる (DB default では埋まらない)。
  aicue:T151 の red-before-fix.txt と同一の失敗メッセージ形である。

これを確認してから実装に入る (AGENTS.md 思考原則 5: テストファースト)。
```

## mutation の実測

```
=====================================================================
T152 mutation 実測ログ (実装を 1 箇所ずつ壊してテストが赤くなることの確認)
=====================================================================

時刻   : 2026-08-11 18:35 JST 〜 18:50 JST
基準   : 210e7e5 "fix: T152 unique 違反の識別と予約行の初期状態を明示代入する"
         (全 green を通した後の基準コミット。composer fix 済み)
復帰   : 各 mutation のあと `git checkout -- app/`

**同時に 2 箇所を壊していない**。1 箇所ずつ壊し、どの assertion がどの実装行を
守っているかの 1:1 対応を確認した。実測値のみを書き、推測は書かない。

---------------------------------------------------------------------
M-1: 施策 1a の `$existing->stripe_session_id !== $result['id']` 条件を削除
---------------------------------------------------------------------
$ composer test -- --filter=AutoRechargeSetupCheckoutUniquenessTest
{"tool":"pest","result":"failed","tests":4,"passed":3,"assertions":8,"duration_ms":6586,"failed":1,
 "failures":[{"test":"…既存行の stripe_session_id が今回の値と食い違うなら replay として握らない",
              "line":86,"message":"Exception \"…UniqueConstraintViolationException\" not thrown."}]}

期待: テスト 3 のみ赤 / テスト 1・2・4 は緑   →   実測: 一致 (4 中 3 passed、赤はテスト 3 のみ)
→ 同一性検査の**この 1 条件**が load-bearing。

---------------------------------------------------------------------
M-2: 施策 1a の catch 節から `if (…) { throw $e; }` を丸ごと削除 (= 常に握る)
---------------------------------------------------------------------
{"tool":"pest","result":"failed","tests":4,"passed":2,"assertions":8,"duration_ms":6493,"failed":2,
 "failures":[{"…別の unique 制約 (stripe_session_id) の違反は握り潰さず再送出する","line":55,
              "message":"Exception \"…UniqueConstraintViolationException\" not thrown."},
             {"…既存行の stripe_session_id が今回の値と食い違うなら replay として握らない","line":86,
              "message":"Exception \"…UniqueConstraintViolationException\" not thrown."}]}

期待: テスト 1・3 が赤 / テスト 2・4 は緑   →   実測: 一致
→ 「握り潰しの復活」と「正規 replay の不変」の非対称を観測できた。

---------------------------------------------------------------------
M-2c: 同一性判定に `|| $existing->initiated_by_user_id !== $user->getKey()` を**足す**
---------------------------------------------------------------------
{"tool":"pest","result":"failed","tests":4,"passed":3,"assertions":5,"duration_ms":6478,"failed":1,
 "failures":[{"…同一 org の別 billing 管理者が同じ attempt_token を送っても replay として握る (actor は問わない)",
   "message":"Expected response status code [201, 301, 302, 303, 307, 308] but received 500.\n…
     PDOException: SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique
     constraint \"billing_checkout_sessions_org_intent_attempt_unique\"
     DETAIL: Key (organization_id, intent, attempt_token)=(4, setup_payment_method, 01kzr2e74vehxapy9m1s1v93tb) already exists."}]}

期待: テスト 4 のみ赤 / テスト 1・2・3 は緑   →   実測: 一致
→ 「actor を同一性判定に入れない」契約が load-bearing (入れると benign な replay が **実測 500**)。

---------------------------------------------------------------------
M-3: 施策 1b の `if ($e->index === self::ATTEMPT_ORG_PENDING_UNIQUE)` を `if (true)` に
---------------------------------------------------------------------
$ composer test -- --filter=AutoRechargeAttemptUniquenessTest
{"tool":"pest","result":"failed","tests":4,"passed":3,"assertions":11,"duration_ms":6390,"failed":1,
 "failures":[{"…別の unique 制約 (attempt_ulid) の違反は no-op へ収束させず再送出する","line":152,
              "message":"Exception \"…UniqueConstraintViolationException\" not thrown."}]}

期待: attempt_ulid テストのみ赤 / 既存 3 テストは緑   →   実測: 一致

---------------------------------------------------------------------
M-4: 施策 1b の catch 節を丸ごと削除 (= 例外が素通り。`finally {}` に置換)
---------------------------------------------------------------------
{"tool":"pest","result":"failed","tests":4,"passed":3,"assertions":9,"duration_ms":6662,"errors":1,
 "error_details":[{"…unique violation は no-op へ収束し呼び出し側へ例外が漏れない",
   "message":"SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique
     constraint \"tar_attempts_org_pending_unique\" DETAIL: Key (organization_id)=(3) already exists."}]}

期待: 既存「unique violation は no-op へ収束し呼び出し側へ例外が漏れない」が赤 /
      attempt_ulid テストは緑   →   実測: 一致
→ M-3 と逆向きの非対称。**期待制約側の握りも load-bearing** であることの実証。
   (このとき壊れた exception が漏れたのは既存テストであり、attempt_ulid テストは緑のまま =
    2 本が別々の実装行を守っている)

---------------------------------------------------------------------
M-6: 施策 2 の `'status' => TakeUploadReservationStatus::Pending` の 1 行だけ削除
---------------------------------------------------------------------
$ composer test -- --filter=TakeUploadUrlTest
{"tool":"pest","result":"failed","tests":20,"passed":19,"assertions":62,"duration_ms":14262,"failed":1,
 "failures":[{"…issue() が保持する予約インスタンスは refresh なしで status=pending を持つ","line":278,
   "message":"Failed asserting that null is identical to an object of class
              \"App\\Enums\\Capture\\TakeUploadReservationStatus\"."}]}

期待: 新規テストのみ赤 / 既存「発行成功: pending 予約が作成され…」は緑のまま
実測: 一致 (20 中 19 passed)。既存テストは `uploadReservations()->sole()` = DB 再読込のため
      DB default が silent に肩代わりして緑のまま。
→ **DB default が silent に肩代わりしている構造そのもの**を実測で観測できた。

---------------------------------------------------------------------
M-7: 復帰確認
---------------------------------------------------------------------
$ git checkout -- app/
$ git status --porcelain        → 出力なし
$ git diff --stat app/          → 出力なし (= 基準コミット 210e7e5 と同一)

最終確認 (復帰後):
$ composer phpstan              → [OK] No errors
$ composer test                 → {"result":"passed","tests":4461,"passed":4459,"skipped":2,
                                   "assertions":19192}

---------------------------------------------------------------------
まとめ
---------------------------------------------------------------------
M-1 / M-2 / M-2c / M-3 / M-4 / M-6 の 6 mutation はすべて**設計の期待どおりに kill された**。
巻き添えで赤くなったテストは 1 件も無い (各 mutation の失敗本数が期待と一致)。
```

## 代替実装 probe の実測 (設計の予測が外れた件を含む)

```
=====================================================================
T152 代替実装 probe (mutation ではない)
=====================================================================

これは「実装を壊すとテストが赤くなる」ことの確認 (mutation.txt) ではなく、
**テストでは識別できない設計上の差**を正直に記録するための比較実験である。
両者を同じ節に置くと「全 mutation が kill された」という読み方と衝突するため分けている。

時刻 : 2026-08-11 18:52 JST
基準 : 210e7e5 (実装済み・全 green)

---------------------------------------------------------------------
P-1: 施策 1a を**撤回した旧案**(制約名 `..._org_intent_attempt_unique` で判定)に差し替える
---------------------------------------------------------------------
差し替え内容 (catch 節の中身を丸ごと置換):

    -   $existing = BillingCheckoutSession::query()->where(...)->first();
    -   if (! $existing instanceof BillingCheckoutSession
    -       || $existing->stripe_session_id !== $result['id']
    -       || $existing->idempotency_key !== $idempotencyKey) {
    -       throw $e;
    -   }
    +   if ($e->index !== 'billing_checkout_sessions_org_intent_attempt_unique') {
    +       throw $e;
    +   }

$ composer test -- --filter=AutoRechargeSetupCheckoutUniquenessTest
{"tool":"pest","result":"failed","tests":4,"passed":3,"assertions":8,"duration_ms":6225,"failed":1,
 "failures":[{"test":"…既存行の stripe_session_id が今回の値と食い違うなら replay として握らない",
              "file":".../AutoRechargeSetupCheckoutUniquenessTest.php","line":86,
              "message":"Exception \"Illuminate\\Database\\UniqueConstraintViolationException\" not thrown."}]}

---------------------------------------------------------------------
**設計の予測は外れた**(予測: 4 本とも緑 / 実測: テスト 3 が赤)
---------------------------------------------------------------------
詳細設計 P-1 は「4 本とも緑になる = 新規テストでは旧案と新案を区別できない」と予測していた。
実測は **3 passed / 1 failed** で、テスト 3 (既存行の stripe_session_id 食い違い) が旧案では赤い。

**原因は OID 順の理解の誤りではない**。設計が指示した通り `pg_index` を再照会した
(読み取り専用 SELECT。実行 DB は migrate 済みテスト DB `app_test_922486d1_test_1`):

    billing_checkout_sessions_pkey                                oid=1992000 unique=t
    billing_checkout_sessions_organization_id_intent_status_index oid=1992012 unique=f
    billing_checkout_sessions_org_intent_attempt_unique           oid=1992013 unique=t
    billing_checkout_sessions_stripe_session_id_unique            oid=1992015 unique=t
    billing_checkout_sessions_idempotency_key_unique              oid=1992017 unique=t

E-7 の「複合 unique が unique の中で最若」は**この DB でも成立している**
(絶対値は DB ごとに異なるが順序関係は同じ)。よって **E-7 の訂正は不要**である。

外れた理由は、設計の P-1 予測が**テスト 3 のシナリオを勘定に入れていなかった**こと:

- テスト 3 は「自然キーでは行が見つかるが内容 (stripe_session_id) が食い違う」状態を作る。
  このとき違反するのは `org_intent_attempt` と `idempotency_key` の 2 本で、
  報告されるのは OID が若い `org_intent_attempt` = **旧案が期待する当の制約名**である。
- したがって旧案は**制約名が一致してしまい握り潰す**。これは OID 順が何であっても変わらない
  (期待制約そのものが報告されるため)。制約名判定は原理的に
  「行は在るが内容が違う」を識別できない。

---------------------------------------------------------------------
この結果が意味すること (誇張しない)
---------------------------------------------------------------------
1. 新規テスト 4 本は、**テスト 3 の 1 本によって旧案と新案を区別できている**。
   詳細設計「保証しないもの」§15 が想定した「テストでは区別できない」状態ではなかった。
2. ただし **OID 順への非依存性そのものはテストで固定されていない**。
   テスト 1・2・4 は旧案でも緑であり、migration に unique を 1 本足して OID 順が変わったときに
   正規 replay (テスト 2) が壊れることを、このテスト群は**事前には捕まえない**
   (順序が変わった後に赤くなる = 事後検出)。これを担保しているのは E-7 の実測と設計判断で
   あって、テストではない。**この限界は残る**。
3. `pg_indexes` を照合する専用 gate は作らない (詳細設計「保証しないもの」§4 / 思考原則 2)。

probe 後は `git checkout -- app/` で復帰し、`git diff --stat app/` が空であることを確認済み
(mutation.txt の M-7)。
```

## テスト結果 (実装後・復帰後)

```
$ composer phpstan
 [OK] No errors

$ composer test
{"tool":"pest","result":"passed","tests":4461,"passed":4459,"assertions":19192,"duration_ms":407887,"skipped":2}

$ vendor/bin/pint --test
{"tool":"pint","result":"passed"}

$ pnpm lint / pnpm typecheck
(エラーなし)

$ pnpm test
 Test Files  130 passed (130)
      Tests  1316 passed (1316)
```

**未実行**: `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`
(本 diff は resources/js・resources/css・packages/ を 1 行も含まないため省略した)

## 特に見てほしい点

1. `startSetupCheckout` の catch を `QueryException` から `UniqueConstraintViolationException` へ
   狭めたことで、**非 unique の QueryException が握られなくなった**。これは旧 `if (! isUniqueViolation) throw` と
   等価だと考えているが、抜けはないか。
2. catch 節内の SELECT が、失敗した INSERT の savepoint 巻き戻し後に実行されることを前提にしている。
   pgsql の 25P02 (in failed transaction) を踏まないか。
3. 同一性判定に `initiated_by_user_id` を入れない契約 (詳細設計「保証しないもの」§14) の妥当性。
4. 代替実装 probe で**設計の予測が外れた**点の扱い (alternative-probe.txt)。
   E-7 の訂正は不要と結論したが、この結論は妥当か。
5. `AutoRechargeAttemptUniquenessTest` に残っている `use Illuminate\Database\QueryException;` は
   既存テストが今も使っているため残置している。不要な残置ではないか。
