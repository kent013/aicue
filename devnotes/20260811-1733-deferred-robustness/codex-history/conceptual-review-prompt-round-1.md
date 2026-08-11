# 概念設計レビュー依頼 (deferred-robustness)

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

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 13 + Svelte 5 + Inertia.js + PostgreSQL 単一）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10 を通せるか

【本件固有の重要な前提 — 設計者からの申し送り】
- 本設計は「アプリコードを1行も変更しない」設計フェーズである。実装は別TODO。
- 「横断的な既定値依存を禁止する gate の新設」は過去TODO(aicue:T151)の設計が
  「判定式が静的に書けず偽陽性で gate の信用を落とす」として却下済み。蒸し返さないこと。
- migration の default は消さない方針である。
- fail-closed が既定である。
- 2件を無理に共通化しない方針である（層も直し方も違う）。
- 「過剰に作らない」(AGENTS.md 思考原則2) を厳守する。新しい抽象・新しい gate の提案は
  それが無いと壊れる具体的シナリオを添えること。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

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

### 件 1: 「期待する制約名を呼び出し側が宣言し、それ以外は再送出する」

**先人の知恵 (思考原則 1)**: 自前の正規表現は書かない。Laravel 13 の
`Illuminate\Database\UniqueConstraintViolationException` が**既に制約名を持っている**。

- `Connection::runQueryCallback()` (vendor L849-873) が unique 違反を検出すると
  `QueryException` ではなく `UniqueConstraintViolationException` を投げ、
  `parseUniqueConstraintViolation()` の結果を `setIndex()` / `setColumns()` する
- `PostgresConnection::parseUniqueConstraintViolation()` (vendor L88-103) が
  `#unique constraint "([^"]+)"#i` で**制約名を `$e->index` に入れる**

したがって 2 箇所を次の形に変える。

```php
} catch (UniqueConstraintViolationException $e) {
    if ($e->index !== self::CHECKOUT_ATTEMPT_TOKEN_UNIQUE) {
        throw $e;          // fail-closed: 期待外の unique は握らない
    }
    // 同一 attempt_token の replay
}
```

- **`isUniqueViolation()` は削除する**(思考原則 3「後方互換の並走を残さない」)。
  非 unique の `QueryException` はそもそも catch されず素通りするので、SQLSTATE 判定は不要になる
- 期待する制約名は**呼び出し側の `private const`** として宣言する。
  2 箇所で期待は異なるので共有の enum / 表は作らない(思考原則 2)
- `$e->index === null` (パース失敗 / exclusion 制約 / sqlite) も**再送出**。fail-closed が既定

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

- **件 1**: 「Stripe session だけ作られて台帳行が無い」「リチャージが永久に起票されない」が
  黙って起きなくなる。障害は例外として上がり、既存の失敗観測経路に乗る。
  → 使命「思考ゼロ・編集ゼロ」を支える課金基盤が、壊れたときに**壊れたと言う**ようになる
- **件 2**: `issue()` が保持する予約インスタンスが DB と同じ状態を持つ。
  migration default を将来変えても**この経路の意味は変わらない**
- どちらも**成功時の振る舞いは変わらない**(課金の振る舞いを変えない / CAS 規約を変えない)

---

## 実装方針(概要)

| # | 施策 | 変更ファイル | 層 |
|---|---|---|---|
| 1a | `startSetupCheckout()` の catch を制約名で絞る | `app/Services/Billing/AutoRechargeService.php` | 例外ハンドリング |
| 1b | `maybeCreateAttempt()` の catch を制約名で絞る + `isUniqueViolation()` 削除 | 同上 | 例外ハンドリング |
| 2 | `TakeUploadService::issue()` の初期 status 明示代入 | `app/Services/Capture/TakeUploadService.php` | 永続化の初期値 |

施策 1c (`SubscriptionService`) の扱いは下記「調査した同型」で決める。

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
| pgsql + exclusion 制約 (23505) | `null` (メッセージが `exclusion constraint`) | 再送出 |
| pgsql + `lc_messages` が翻訳カタログのあるロケール | `null` になりうる | 再送出。実測ではコンテナに ja カタログが無く英語のまま |
| sqlite | **常に `null`** (`SQLiteConnection::parseUniqueConstraintViolation` は `index => null` 固定) | 再送出 |

本リポジトリは `phpunit.xml` が `DB_CONNECTION=pgsql` を `force="true"` で固定し、
`ticket_auto_recharge_attempts` の migration 自身が非 pgsql/sqlite driver で
`RuntimeException` を投げる。**テストも本番も pgsql 単一**である。

### 確認 3: index 名は PostgreSQL が 63 バイトで**黙って切る**

実 DB 実測: `take_upload_reservations_organization_id_status_expires_at_inde`
(末尾 `x` が落ちている)。今回期待する 2 本は短く無事:

| 期待名 | 長さ | 実 DB に実在 |
|---|---|---|
| `billing_checkout_sessions_org_intent_attempt_unique` | 51 | Yes |
| `tar_attempts_org_pending_unique` | 31 | Yes |

名前が将来ずれたら**behavioral テストが赤くなる**(握るはずの違反が再送出される)。
そのため**別途の名前照合 gate は作らない**(思考原則 2)。

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
| `SubscriptionService::startCheckout` L469 | **見ない**(が docblock は見ると書いてある) | 下記で判断 |
| `PersonalPlanService::isDeclarerUniqueViolation` L216 | **見る**(`str_contains` で名前照合) | 対処不要 |
| `Actions/Fortify/CreateNewUser` L119 | 見ないが `emailAlreadyRegistered()` で**再確認して不一致は再送出** | fail-closed 済。対処不要 |
| `Capture/TakeRegistrationService` L120 | 見ないが**重複 Take が実在しなければ再送出** | fail-closed 済。対処不要 |
| `Models/LlmCallLog::recordWithOrganization` L140 | 見ない | 対処不要(下記) |
| `Services/Mcp/McpIdempotencyService` L118 | 見ない | 対処不要(下記) |

- `llm_call_logs` の unique は `_execution_id_unique` と `_pkey` の 2 本のみ(実 DB 実測)。
  かつ握った後の fallback は `firstOrNew(execution_id)` の**再試行**なので、
  別制約なら 2 回目も同じ例外で**外へ出る**。構造的に fail-closed
- `mcp_idempotency_keys` も同様に握った後で既存行を読み直す形
- **`SubscriptionService` は本設計に含める(施策 1c)**。理由は 3 つ:
  1. コードが `catch (UniqueConstraintViolationException $e)` を**既に使っている**のに
     `$index` を見ておらず、直後のコメントが
     「attempt_token 以外の unique 違反は rethrow = 500 に落として調査対象にする」と
     **実装していない保証を宣言している**。実際には `stripe_session_id` 衝突が
     `StaleCheckoutAttemptException`(「有効期限が切れました」)へ化ける
  2. 直し方は `$e->index !== self::…` の 1 条件で、**既に書いてある契約にコードを合わせるだけ**。
     新機能ではない
  3. 隣の service で同じことをしながらここを放置するのは AGENTS.md 思考原則 6
     「タコツボ実装を避ける」に反する

  ただし **`AutoRechargeService` と共通の抽象は作らない**。const と 1 条件を各サービスに置く。

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

## 参考: 実査ブリーフ (一次入力)

# 実査ブリーフ: 先送りしていた堅牢性 2 件 (T140 + 予約行の既定値依存)

> このセッション中に「別 TODO」として明示的に先送りした項目のうち、
> **単独で完了でき、かつ実コードで実在を確認できた 2 件**をまとめて閉じる。
> どちらも「今は顕在化していないが、条件が揃うと黙って壊れる」種類である。

---

## 件 1: auto-recharge の unique violation 判定が対象制約を識別しない (既存 TODO aicue:T140)

### 実コードで確認した事実

`app/Services/Billing/AutoRechargeService.php` は `isUniqueViolation($e)` を **2 箇所**で使う。

- L306: `startCheckout` 相当の replay 判定。「同一 attempt_token の replay」として**例外を握り潰す**
- L510: 起票の並行競合。「DB partial unique (`tar_attempts_org_pending_unique`) が最終防衛」として **null を返す**

**`isUniqueViolation()` は SQLSTATE (23505 / 23000) だけを見ており、制約名を識別しない。**
したがって**別の unique 制約の違反も同じ扱いに収束する** = 本当の障害が「並行競合」として黙って握り潰される。

### なぜ今やるか

aicue:T137 で `AutoRechargeTriggerJob` から `ShouldBeUnique` を撤去し、**一回性の担保を DB 制約へ寄せた**。
DB 制約への依存が強まったぶん、「どの制約に当たったか」を識別しないことの危険も増している。
T140 はそのときに起票された追跡先である。

### 設計で決めるべきこと

1. **制約名をどう取るか**。PostgreSQL の例外から制約名を取り出す方法 (`$e->errorInfo` / driver 由来のメッセージ)
   を**実コードと vendor で確認する**こと。**推測で書かない**。取れないなら「取れない」と結論してよい。
2. **期待する制約名をどこに置くか**。呼び出し 2 箇所で期待する制約は異なる可能性がある
   (L306 は attempt_token 系、L510 は `tar_attempts_org_pending_unique`)。**呼び出し側が期待を宣言する形**が素直か。
3. **識別できなかったときの挙動**。**fail-closed** (再送出) が既定であるべき。
   「不明な unique 違反を並行競合として飲み込む」現状こそが問題だから。
4. **他に同型の握り潰しが無いか**。ただし**今必要なものだけ作る** (思考原則 2)。

---

## 件 2: take_upload_reservations.status が DB 既定値に依存する (aicue:T151 の副次発見)

### 実コードで確認した事実

`app/Services/Capture/TakeUploadService.php` L76-84:

```php
$reservation = $lockedCut->uploadReservations()->make([...]);
$reservation->forceFill(['organization_id' => $lockedOrg->id])->save();
```

**`status` を明示代入していない**。`database/migrations/*_take_upload_reservations*` の
`$table->string('status', 20)->default('pending')` に依存している。

**これは aicue:T151 で直した `VideoManualService::create()` とまったく同じ形**である。
T151 の副次発見として記録され、「**戻り値の status を読む呼び出し側が 0 件で顕在化していないため
本件では直さず、別 TODO 候補として記録のみ (対処の約束ではない)**」とされた。

### なぜ今やるか

- **同じ species のバグが 2 箇所目**である。T151 は pipeline-smoke の実走で初めて顕在化した。
  こちらも「呼び出し側が status を読んだ瞬間」に同じ壊れ方をする。
- migration の default を変えると**この経路の挙動だけが黙って変わる**。
- T151 で **`VideoManualService` の docblock が既定値依存の危険を明文化**しており、
  リポジトリの方針としては既に「明示代入する」側に倒れている。

### 設計で決めるべきこと

1. **T151 と同じ直し方でよいか**。`forceFill` に `status` を足すだけで足りるか、
   予約の状態遷移 (pending → verifying → completed / released) の CAS 規約と衝突しないかを確認する。
   **AGENTS.md ドメイン規約 2 (容量 Quota の予約規約)** が「直接 UPDATE を書かない」と定めている点に注意。
   **初期値の INSERT は状態遷移ではない**が、設計者が根拠を示して確認すること。
2. **inventory 登録が要るか**。`take_upload_reservations` の状態を書く経路に
   対応する Architecture テストがあるか実コードで確認する (無ければ不要)。
3. **横断的に潰すか**。同型 (INSERT で状態列を明示代入しない) が他にもあるかを調べてよいが、
   **今必要なものだけ作る**。見つけても本件で直すとは限らず、記録に留めてよい。

---

## 共通の要点

- **どちらも再現テストを先に赤にしてから直す**。T151 では
  「戻り値の status を読むテスト」が `Failed asserting that null is identical to ...` で赤になった。
  件 2 は同じ形で再現できるはず。件 1 は**別の unique 制約に当てて握り潰されることを再現**する。
- **migration の default は消さない** (既存行と他の INSERT 経路に影響する)。
- **保証しないものを明記する**。

## やらないこと

- **課金の振る舞いを変えない** (件 1 は観測と fail-closed の話であって、成功時の挙動は変えない)。
- **予約の状態遷移の CAS 規約を変えない** (件 2 は初期値の話)。
- 横断的な「既定値依存を禁止する gate」の新設は、**判定式が静的に書けず偽陽性で gate の信用を落とす**ため
  aicue:T151 の設計が既に却下している。蒸し返さないこと。
