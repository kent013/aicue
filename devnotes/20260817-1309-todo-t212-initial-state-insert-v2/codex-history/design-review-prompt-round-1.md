【アプリの使命 (North Star)】
## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】
## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)


【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。

データに真摯に向き合え。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考えてから手を動かせ。

先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。

仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript / PHPStan level 10 / Pest / DTO + JsonResource / Laratrust RBAC
- 本設計は複数リポジトリで共有される機能台帳 (lctl) の正典 v2 への追従である。裁定 AG-191 が「NULL が初期状態を表す状態列を検査の分類に 1 つ足して既定拒否の全数申告に載せ、その列への DB 既定値の後付けを赤にするスキーマ pin を置く。CHECK 制約は使わない」と確定している。実装の形は自由 (不変条件を満たせばよい)。
- 正典の関連不変条件: i5 (検査の母集団は実スキーマから取る。0 件なら落とす) / i6 (保証しない範囲は検査自身が明文で持ち、それが正本) / i7 (CHECK・排他制約・部分一意索引・外部キーを義務化しない)。
- 既存の v1 実装 (規約明文 + ファイル粒度の許可一覧) は 1 文字も変更しない方針である。

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性
4. テスト計画の網羅性（テストファーストの赤の置き方が妥当か。負のコントロールが本当に点灯するか）
5. 副作用・後退リスク
6. 波及変更の網羅性
7. セキュリティ（AGENTS.md のセキュリティ不変条件）
8. **裁定 AG-191 の要求を実際に満たしているか**（既定値の後付けが本当に赤くなるか。抜け道は無いか）
9. **保証しない範囲の記述が誇張・過小になっていないか**

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: NULL が初期状態を表す列の分類とスキーマ pin (正典 v2 追従)

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  (撮影者・教える人のスキルに品質を依存させない)。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) /
> 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項 (AGENTS.md より)

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

本施策はいずれにも触れない (UI・API・LLM 経路に一切変更を入れない)。

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)。走査根は `app` / `config` / `database` / `routes`
  であり `tests` は含まれない。**含まれないからといって型を緩めない** (禁止事項 2 の趣旨)
- **Pest** (`composer test`)。`RefreshDatabase` は `tests/Pest.php` で Feature/Unit へ
  グローバル適用済み。個別 `DatabaseTransactions` は使わない。`--parallel` 実行
- `declare(strict_types=1)` + 日本語コメント (git 追跡下の PHP 全数が対象。免除簿なし)
- `echo` / `goto` / `global` / 開始タグ付きの出力記法は書かない
- コードフォーマット: `composer fix` (Pint) / `pnpm lint:fix`
- PHP 8.4 + Laravel 12

## 目的と台帳の根拠

家系の機能台帳 lctl の機能 `explicit-initial-state-on-insert` が **裁定 AG-191 (2026-08-16)** で
正典 v2 へ上がった。本リポジトリ (aicue) のセルは `update_pending` / `version: v1` /
`target_version: v2 (NULL が初期状態を表す状態列の分類 + 既定値後付けを赤にするスキーマ pin)`。

本施策が追加で満たす不変条件は **i10** (確定設計 s10。旧 未決論点 q1 が AG-191 で解消):

> NULL 自体が初期状態を表す状態列 (受諾日時・処理日時・失効日時など、値が入ったら処理済みと
> 読む列) も本 feature の守備範囲に含める。守り方はスキーマ pin 形 — この一族の列を検査の
> 分類に 1 つ足して既定拒否の全数申告に載せ、この列へ後から DB 既定値を足したら赤くなることを
> スキーマ側で固定する。CHECK 制約は使わない (i7 と衝突しない)。

同時に効く既存の不変条件: **i5** (母集団は実スキーマから取る。0 件なら落とす) /
**i6** (保証しない範囲は検査自身が明文で持ち、それが正本) /
**i7** (CHECK・排他制約・部分一意索引・外部キーを義務化しない)。

台帳 ID は家系の規律に従い `<repo>:ID` の形で書く (aicue:T151 / aicue:T152 /
laravel-claude-template:T131)。本設計は aicue:T212 として TODO へ登録される予定である。

## 概念設計リファレンス

`devnotes/20260817-1309-todo-t212-initial-state-insert-v2/conceptual-design.md`
(Codex 概念設計レビュー Round 1 で APPROVED。Warning 4 件は反映済み。
対応は `codex-history/conceptual-review-decisions-round-1.md`)

## 現状 (実読で確認した事実)

| 対象 | 実読して分かったこと |
|---|---|
| `AGENTS.md` ドメイン固有規約 1 (ii) / 2 | v1 の規約明文。「初期状態を INSERT 時に明示代入する (DB カラム default に依存しない)」。規約 2 は「migration の `default('pending')` は既存行と Factory 以外の INSERT 経路のために残す」と書く |
| `tests/Architecture/ScenarioWritePathInventoryTest.php` | v1 の機械検査。token 走査 + **ファイル粒度**の許可一覧。「同一ファイル内のメソッド追加は検出しない」と自認済み |
| `app/Services/Manual/VideoManualService.php` / `app/Services/Capture/TakeUploadService.php` | v1 の準拠実装 (aicue:T151 / aicue:T152) |
| `tests/Feature/Retention/RetentionTableClassificationTest.php` + `tests/Support/Retention/*` | **本施策の直接の先例**。実スキーマの表一覧を母集団に取り、区分 + 30 文字以上の根拠で全数分類し、両方向の集合等価・件数の exact-fit pin・負のコントロールを持つ。除外一覧を持たない |
| `phpstan.neon` | `paths: app / config / database / routes`。`tests` は走査根に入らない |
| `tests/Pest.php` | Feature/Unit レーンに `RefreshDatabase` をグローバル適用。Architecture レーンは DB を使わない |
| 実スキーマ (現在の移行を適用した状態) | base table 63。nullable かつ既定値なし・生成列/identity 除外の列は 298。うち時刻型は 140 (作成・更新時刻 90 を除くと 50)。BackedEnum へ cast された nullable かつ既定値なしの列は 9 |

実スキーマの数値は Laravel の Schema API と同じ情報 (information_schema) を読んで数えた
現在値であり、**実装時に再測して pin する** (設計が固定するのは規則であって数値ではない)。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 分類の型 (区分 3 種) | `tests/Support/InitialState/NullInitialStateClass.php` (新規) | 高 |
| 2 | 台帳の 1 行 (readonly + 名前付き生成子) | `tests/Support/InitialState/NullableStateColumnEntry.php` (新規) | 高 |
| 3 | 全数台帳 | `tests/Support/InitialState/NullableStateColumnRegistry.php` (新規) | 高 |
| 4 | 検査 (規則 NI-1..NI-7 + 負のコントロール NC-1..NC-7) | `tests/Feature/InitialState/NullInitialStateColumnClassificationTest.php` (新規) | 高 |
| 5 | 規約の明文 | `AGENTS.md` (変更) | 高 |
| 6 | 運用の説明 | `docs/architecture.md` (変更) | 中 |

### 変更ファイル一覧

**新規 (4)**

- `tests/Support/InitialState/NullInitialStateClass.php`
- `tests/Support/InitialState/NullableStateColumnEntry.php`
- `tests/Support/InitialState/NullableStateColumnRegistry.php`
- `tests/Feature/InitialState/NullInitialStateColumnClassificationTest.php`

**変更 (2)**

- `AGENTS.md` — ドメイン固有規約に 1 項 (17 番) を追加し、既存の 規約 1 (ii) / 規約 2 から参照させる
- `docs/architecture.md` — 「NULL が初期状態を表す列の分類」節を新設 (保証しないものは複写せず検査を参照)

**削除**: なし。**v1 の資産 (`ScenarioWritePathInventoryTest.php` /
`VideoManualService.php` / `TakeUploadService.php`) は 1 文字も変更しない。**

### 波及変更 (全施策共通)

- TypeScript 型定義: **なし** (画面・props・API に変更が無い)
- API Resource / DTO: **なし**
- Inertia Props: **なし**
- migration: **なし** (既定値の追加も削除もしない。CHECK 制約も足さない = i7 / AG-191)
- 既存テストファイル: **なし** (新規 1 本のみ。既存の検査と母集団が交わらない)

---

## 施策 1: 分類の型 (`NullInitialStateClass`)

### 区分は 3 つだけ

判定は 1 つの問いで決まる — **「その行が生まれた時点で、この列は必ず NULL か」**。

| 区分 | 意味 | 既定値の後付け |
|---|---|---|
| `InitialStateMarker` (初期状態の目印) | 行が生まれた時点で**必ず NULL** であり、NULL であること自体が「まだその段階に達していない」を意味する。業務が進むと値が入る | **置いてはならない**。置くと新しい行が生まれた瞬間に「済んだ」ことになる |
| `SetAtCreation` (生成時に決まりうる値) | 行を作る時点で値が入りうる列。NULL は「該当なし / 無期限 / 未指定」であって進行段階ではない (期限・外部が決めた値の写し・任意の属性) | 置く理由が無い (アプリが決めている)。置けば母集団から抜けて検査が赤くなる |
| `Undecided` (未確定) | どちらとも決められていない列。**隠さずここへ載せる** (件数と列名を pin する) | 同上 |

`Undecided` を持つのは `RetentionTableRegistry` と同じ理由である — 判断がつかない列を
「とりあえず片側」へ寄せると、その嘘が二度と見直されない。

### 変更後コード (骨子)

```php
<?php

declare(strict_types=1);

namespace Tests\Support\InitialState;

/**
 * NULL が意味を持つ列の区分。
 *
 * 判定は 1 つの問いで決まる: **その行が生まれた時点で、この列は必ず NULL か**。
 */
enum NullInitialStateClass: string
{
    /** 生成時は必ず NULL。NULL であること自体が「まだその段階に達していない」を意味する。 */
    case InitialStateMarker = 'initial_state_marker';

    /** 生成時に値が入りうる列。NULL は該当なし / 無期限 / 未指定であって進行段階ではない。 */
    case SetAtCreation = 'set_at_creation';

    /** どちらとも決めていない列。隠さずここへ載せる (件数と列名を gate が pin する)。 */
    case Undecided = 'undecided';

    /** 人が読む区分名 (失敗メッセージ用)。 */
    public function label(): string
    {
        return match ($this) {
            self::InitialStateMarker => '初期状態の目印',
            self::SetAtCreation => '生成時に決まりうる値',
            self::Undecided => '未確定',
        };
    }
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている
- [x] `match` は全 case を網羅し、到達不能な `default` を作らない
- [x] backed enum の値は文字列リテラルで固定

---

## 施策 2: 台帳の 1 行 (`NullableStateColumnEntry`)

### 設計判断

- `final readonly` + **private constructor** + 区分ごとの名前付き生成子
  (`RetentionTableEntry` と同じ形。不正な組み合わせを型で作らせない)
- **根拠 30 文字以上をコンストラクタでも検査**する。gate の規則 (NI-2) とは別に、台帳を
  作った時点で落とす (Codex 概念レビューの Warning への対応)
- **集合比較のキーは `key()` の 1 メソッドに寄せる** (`表名.列名`)。gate 側で文字列連結を書かない

### 変更後コード (骨子)

```php
<?php

declare(strict_types=1);

namespace Tests\Support\InitialState;

use InvalidArgumentException;

/**
 * 「nullable かつ DB 既定値を持たない列」1 本分の分類の宣言。
 *
 * **コンストラクタは private** で、区分ごとの名前付き生成子からしか作れない。
 */
final readonly class NullableStateColumnEntry
{
    /** 根拠の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
    public const int RATIONALE_MIN_LENGTH = 30;

    private function __construct(
        public string $table,
        public string $column,
        public NullInitialStateClass $class,
        public string $rationale,
    ) {
        if (mb_strlen($rationale) < self::RATIONALE_MIN_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('%s.%s の根拠が %d 文字未満です', $table, $column, self::RATIONALE_MIN_LENGTH),
            );
        }
    }

    /** 生成時は必ず NULL で、NULL 自体が「まだその段階に達していない」を意味する列。 */
    public static function initialStateMarker(string $table, string $column, string $rationale): self
    {
        return new self($table, $column, NullInitialStateClass::InitialStateMarker, $rationale);
    }

    /** 行を作る時点で値が入りうる列 (期限 / 外部の値の写し / 任意の属性)。 */
    public static function setAtCreation(string $table, string $column, string $rationale): self
    {
        return new self($table, $column, NullInitialStateClass::SetAtCreation, $rationale);
    }

    /** どちらとも決められていない列。$rationale には**何が決まっていないか**を書く。 */
    public static function undecided(string $table, string $column, string $rationale): self
    {
        return new self($table, $column, NullInitialStateClass::Undecided, $rationale);
    }

    /** 集合比較の正規化キー (gate 側で文字列連結を書かないための唯一の入口)。 */
    public function key(): string
    {
        return $this->table.'.'.$this->column;
    }
}
```

### PHPStan 適合チェック

- [x] すべての public プロパティに型がある
- [x] 生成子の戻り値型が `self`
- [x] null を返す経路が無い (不正な入力は例外)

---

## 施策 3: 全数台帳 (`NullableStateColumnRegistry`)

### 設計判断

- **連想配列にしない**。並び (`list<NullableStateColumnEntry>`) のまま返し、
  二重宣言の検出は gate の純関数が行う (`RetentionTableRegistry` と同じ理由 =
  キー化すると同じ列を 2 回書いても後の 1 件で上書きされ、二重宣言が消える)
- **除外一覧を持たない**。母集団に入った列は必ずここに現れる

### 区分の初期案 (実装時に各列の生成点を実読して確定する)

下表は実スキーマと生成点の抜き取り実読 (`CreateInquiryAction` / `CreateNewUser` /
`PersonalPlanService` / `AutoRechargeService` / `StoreCaptureTakeRequest` /
`ApiKeyGuard` / `PlanPrice` / `TicketVolumePrice`) にもとづく**初期案**である。
**実装では 59 列すべての生成点を読んで確定し、読んでも決められない列は `undecided` へ載せる。**

**(a) 時刻型 50 列**

| 区分 | 列 |
|---|---|
| 初期状態の目印 (30) | `api_keys.last_used_at` / `api_keys.revoked_at` / `billing_checkout_sessions.completed_at` / `billing_checkout_sessions.pm_reuse_dispatched_at` / `billing_notifications.sent_at` / `billing_notifications.failed_at` / `inquiries.closed_at` / `notifications.read_at` / `oauth_device_codes.user_approved_at` / `oauth_device_codes.last_polled_at` / `oauth_sessions.last_used_at` / `oauth_sessions.revoked_at` / `organization_invitations.accepted_at` / `organization_invitations.revoked_at` / `organizations.deleted_at` / `organizations.free_plan_activated_at` / `organizations.personal_declared_at` / `organizations.signup_tickets_granted_at` / `organizations.stripe_customer_redacted_at` / `passkeys.last_used_at` / `stripe_webhook_events.processed_at` / `subscriptions.ends_at` / `subscriptions.past_due_since` / `takes.downloaded_at` / `ticket_auto_recharge_attempts.resolved_at` / `ticket_checkout_sessions.completed_at` / `ticket_ledger_entries.carried_forward_through` / `ticket_reservations.consume_expires_at` / `users.email_verified_at` / `users.two_factor_confirmed_at` |
| 生成時に決まりうる値 (20) | `api_keys.expires_at` / `inquiries.terms_accepted_at` / `oauth_access_tokens.expires_at` / `oauth_auth_codes.expires_at` / `oauth_device_codes.expires_at` / `oauth_refresh_tokens.expires_at` / `organizations.trial_ends_at` / `plan_prices.synced_at` / `plan_prices.active_to` / `subscriptions.current_period_end` / `subscriptions.trial_ends_at` / `takes.captured_at` / `ticket_auto_recharges.consented_at` / `ticket_ledger_entries.granted_at` / `ticket_ledger_entries.expires_at` / `ticket_volume_prices.synced_at` / `ticket_volume_prices.active_to` / `users.terms_accepted_at` / `users.deletion_requested_at` / `users.deletion_purge_after` |

> `users.deletion_requested_at` / `users.deletion_purge_after` は「退会予約が入った瞬間に
> 2 つ同時に書かれる」形なので、生成時点では必ず NULL である = 本来は初期状態の目印側である。
> 一方で `AccountDeletionGrace` が 2 列の同時性を前提にしているため、**実装時に
> `app/Support/Account/AccountDeletionGrace.php` と退会予約の書き込み経路を実読して確定する**
> (この 2 列は初期案の中でもっとも移る可能性が高い)。

**(b) BackedEnum へ cast された 9 列**

| 区分 | 列 |
|---|---|
| 初期状態の目印 (6) | `analysis_jobs.step` / `render_jobs.step` / `render_jobs.error_code` / `stripe_webhook_events.recovery_reason` / `ticket_auto_recharges.disabled_reason` / `ticket_reservations.consume_source` |
| 生成時に決まりうる値 (3) | `cuts.material_type` / `inquiries.source` / `ticket_ledger_entries.source` |

### 根拠の書き方 (30 文字以上)

- `InitialStateMarker`: **NULL が何を意味し、値が入ると何が変わるか**を書く
  (例: 「招待が受諾されるまで NULL で、受諾の瞬間に一度だけ時刻が入る。NULL のまま = 未受諾」)
- `SetAtCreation`: **なぜ生成時に値が決まってよいのか**を書く
  (例: 「発行時に有効期限を決めて書き込む列で、NULL は無期限を意味する。進行段階を表さない」)
- `Undecided`: **何が決まっていないか**を書く

---

## 施策 4: 検査 (`NullInitialStateColumnClassificationTest`)

置き場所は **Feature レーン** (`tests/Feature/InitialState/`)。実スキーマを引くため。
先例は `tests/Feature/Retention/RetentionTableClassificationTest.php` (同じ理由で Feature)。

### 母集団の作り方 (実スキーマ起点 — i5)

読み口は `DB::connection()->getSchemaBuilder()` (ファサードではなく具体の `Builder` を取る =
戻り値の shape 宣言がそのまま効き、型を緩めずに済む。`RetentionTableClassificationTest` と同じ)。

1. `getTables($builder->getCurrentSchemaName())` で現在のスキーマの base table 名を取る
2. 各表について `getColumns($schema.'.'.$table)` を引き、次をすべて満たす列を残す
   - `nullable === true`
   - `default === null`
   - `generation === null` (生成列を除く)
   - `auto_increment === false` (identity / serial を除く)
3. 残った列から母集団を 2 系統で作る
   - **(a) 時刻型**: `type_name` が `timestamp` / `timestamptz` / `date` のいずれか。
     ただし後述の「作成・更新時刻の除外」に該当する列は外す
   - **(b) 列挙 cast**: `app/Models` 配下の具象 Eloquent クラスが `getCasts()` で
     **`enum_exists()` かつ `BackedEnum` 実装**へ cast すると宣言している列
4. (a) ∪ (b) を `表名.列名` へ正規化し sort して母集団とする

**モデルと表の対応**: `app/Models` 配下の `*.php` から FQCN を組み、
`Illuminate\Database\Eloquent\Model` の具象サブクラスだけをインスタンス化して `getTable()` を引く
(クラス名からの推測をしない)。同じ表を指すモデルが複数あるときは **cast 宣言の和集合**を取る
(母集団が広がる側 = 見落としの出ない側へ倒す)。

**作成・更新時刻の除外** (Codex 概念レビュー Warning への対応。列名の一律一致で外さない):

- その表を持つモデルがあるとき: そのモデルが `usesTimestamps()` を満たし、かつ列名が
  そのモデルの `getCreatedAtColumn()` / `getUpdatedAtColumn()` と一致する場合だけ外す
- その表を持つモデルが無いとき (枠組み・外部パッケージ・中間表): 列名が
  `Model::CREATED_AT` / `Model::UPDATED_AT` の既定値と一致する場合だけ外す。
  **この経路で外れた件数を NI-7 が完全一致で pin する** (除外が無音で広がらない)
- `deleted_at` は**除外しない** (論理削除は初期状態の目印そのもの)

### 純関数と副作用の分離

合成入力で点灯させられるよう、判定はすべて純関数に切る (`RetentionTableClassificationTest` の流儀)。

```php
/**
 * 母集団の算出 (**純関数**)。
 *
 * @param  array<string, list<array{name: string, type_name: string, nullable: bool,
 *          default: string|null, auto_increment: bool, generation: array<string, mixed>|null}>>  $columnsByTable
 * @param  array<string, list<string>>  $enumCastColumns  表名 => BackedEnum へ cast された列名
 * @param  array<string, list<string>>  $lifecycleColumns 表名 => 除外する作成 / 更新時刻の列名
 * @return array{population: list<string>, temporal: list<string>, enumCast: list<string>,
 *          excludedLifecycle: list<string>}
 */
function nullInitialStatePopulation(
    array $columnsByTable,
    array $enumCastColumns,
    array $lifecycleColumns,
): array { /* ... */ }

/**
 * 母集団と台帳の突合 (**純関数**)。
 *
 * @param  list<string>  $population  '表名.列名'
 * @param  list<NullableStateColumnEntry>  $entries
 * @return array{unclassified: list<string>, phantom: list<string>, duplicated: list<string>}
 */
function nullInitialStateClassify(array $population, array $entries): array { /* ... */ }
```

### 規則

| # | 規則 | 落ちる条件 |
|---|---|---|
| NI-1 | 母集団と台帳が**両方向で集合一致**する | 未分類の列がある / 実在しない列が台帳に残っている |
| NI-2 | 同じ列の二重宣言が無く、根拠が 30 文字以上ある | 二重宣言 / 根拠が短い |
| NI-3 | **空振り検知**: 母集団が 0 件でない。かつ (a) 時刻型・(b) 列挙 cast の**各系統がそれぞれ 1 件以上**寄与している | 抽出が壊れて静かに縮んだとき |
| NI-4 | 台帳の総件数が現在値ちょうど | 列が増減したのに pin を直していない |
| NI-5 | 「初期状態の目印」区分の列一覧が現在値ちょうど | 一族の列が無音で減った / 増えた |
| NI-6 | 「未確定」区分の列一覧が現在値ちょうど | 未確定が無音で増えた |
| NI-7 | モデルを持たない表で外した作成・更新時刻の件数が現在値ちょうど | 除外が無音で広がった |

**NI-1 が AG-191 の「既定値の後付けを赤にするスキーマ pin」の本体である。**
登録済みの列に DB 既定値を足すと、その列は母集団の条件 (`default === null`) から外れて
母集団から抜け、台帳側の登録が「実在しない登録」として残る → NI-1 が赤くなる。
**除外規則も CHECK 制約も足さずに、母集団の定義そのものから pin が出る。**
失敗メッセージは、この経路で赤くなったときに
「**この列に migration で DB 既定値を足していませんか。足すと新しい行は生まれた瞬間に
『済んだ』ことになります**」を名指しで出す。

### 負のコントロール (合成入力)

| # | 内容 |
|---|---|
| NC-1 | 台帳に無い列を母集団へ足すと NI-1 の「未分類」が点灯する |
| NC-2 | 実在しない列を台帳へ足すと NI-1 の「実在しない登録」が点灯する |
| NC-3 | **登録済みの列に DB 既定値が付いた状況**を合成すると母集団から抜け、NI-1 の「実在しない登録」が点灯する (AG-191 の pin の本体) |
| NC-4 | `usesTimestamps()` が false のモデル / 作成時刻列名を差し替えたモデルでは、`created_at` という名の列が母集団に**残る** (列名だけで外していないことの確認) |
| NC-5 | 同じ列を 2 回宣言すると NI-2 の二重宣言が点灯する |
| NC-6 | 母集団が空のとき NI-3 が落ちる (0 件を合格にしない) |
| NC-7 | BackedEnum ではない cast (組み込みの `array` / `datetime` / Castable クラス) は (b) に入らず、BackedEnum の cast だけが入る |

### 保証しないもの (検査の docblock が正本 — i6)

- **列の意味が区分どおりかは見ない**。機械が見るのは集合の一致と根拠の長さだけであり、
  区分が正しいかは人間のレビュー対象である
- **母集団は時刻型と BackedEnum cast 列に限る**。nullable な文字列・数値・json・外部キーで
  「NULL = まだ」を表す列 (実例: `billing_checkout_sessions.funding_choice` /
  `render_jobs.output_path` / `cuts.adopted_take_id`) は母集団外であり、
  そこへ既定値を足しても**沈黙する**
- **(b) はモデルの宣言に依存する**。`app/Models` にモデルを持たない表 (枠組み・外部パッケージ・
  中間表) の状態語彙の列は見えない。ただし cast を外す変更は台帳側が「実在しない登録」になって
  赤くなるので、**片方向は閉じている**
- **最初から既定値を持って生まれた列は母集団外**である (v1 の担当。同じ事実を 2 か所で検査しない)。
  新しい列を最初から既定値付きで足す変更には沈黙する
- **既定値の中身は見ない**。`default === null` かどうかだけを見る
- **CHECK 制約・部分一意索引・排他制約は使わない** (i7 / AG-191)。列の組の整合や値域は保証しない
- **Factory / Seeder は走査域外**である (家系の未決論点 q3。本裁定の範囲外)
- 見るのは**現在のスキーマ**であり、`search_path` の健全性は前提であって保証ではない。
  S3 上の実体・ビュー・他スキーマの表は対象外である
- **アプリが実際にその列の NULL を読んで分岐していることは確かめない**。
  区分 `InitialStateMarker` は人の宣言である

---

## 施策 5: 規約の明文 (`AGENTS.md`)

ドメイン固有規約に **17 番**として 1 項を足す (v1 の 規約 1 (ii) / 規約 2 と同じ節に置き、
初期状態の話が 1 か所に集まるようにする)。文面の骨子:

> 17. **NULL が初期状態を表す列の分類 (aicue:T212 / 家系の正典 v2、裁定 AG-191)**:
>     nullable かつ **DB 既定値を持たない**列のうち、時刻型の列と BackedEnum へ cast された列は、
>     `tests/Support/InitialState/NullableStateColumnRegistry.php` へ区分と 30 文字以上の根拠を
>     1 行足す (deny-by-default。`NullInitialStateColumnClassificationTest` が実スキーマと
>     両方向で突き合わせる)。区分は 3 つで、決められないなら「未確定」に載せる (隠さない)。
>     - **登録済みの列に migration で DB 既定値を後付けすると赤くなる**。母集団の条件から
>       外れて「実在しない登録」になるためで、CHECK 制約は使わない
>     - **DB 既定値を持つ状態列は 規約 1 (ii) / 規約 2 の担当**であり本目録の母集団外である
>       (同じ事実を 2 か所で検査しない)
>     - **保証しないものの正本は検査の docblock** であり、本書と `docs/architecture.md` には
>       写さない (2 か所に書くと必ず食い違う)

併せて 規約 1 (ii) と 規約 2 の末尾に「NULL が初期状態を表す列は 規約 17」の 1 行参照を足す
(既存の文面は変えない)。

## 施策 6: 運用の説明 (`docs/architecture.md`)

「NULL が初期状態を表す列の分類」節を新設する。書くのは
**なぜこの形なのか (母集団の定義から pin が出る仕組み)** と **区分の判断基準 (1 つの問い)** と
**列を足したときの手順**だけで、保証しないものは検査を参照する (複写しない)。

---

## テストファースト計画 (どのテストを先に赤にするか)

1. **赤 1 (母集団の実測)**: 施策 4 の検査だけを置き、施策 3 の台帳を**空**にして
   `composer test --filter=NullInitialStateColumnClassification` を走らせる。
   NI-1 が「未分類 59 件」で赤くなることを実測し、列の全一覧を
   `devnotes/20260817-1309-todo-t212-initial-state-insert-v2/red-first.md` に貼る
   (**この一覧が台帳の入力そのものになる** = 母集団が実スキーマ起点であることの証跡)
2. **赤 2 (空振り検知)**: 母集団の抽出を意図的に空にした合成入力 (NC-6) で NI-3 が
   落ちることを確認する。0 件を合格にしないこと (i5) を先に固定する
3. **赤 3 (pin の本体)**: NC-3 を先に書く。登録済みの列に既定値が付いた合成スキーマで
   「実在しない登録」が点灯し、失敗メッセージが「migration で DB 既定値を足していませんか」を
   名指しすることを確認する。**ここが赤くならない実装は AG-191 を満たしていない**
4. **赤 4 (除外の限定)**: NC-4 を書く。`usesTimestamps()` が false のモデル /
   作成時刻列名を差し替えたモデルで `created_at` という名の列が母集団に残ることを確認する
5. **緑化**: 1 の一覧を読み、59 列すべての生成点を実読して区分を決め、台帳を埋める。
   NI-4 / NI-5 / NI-6 / NI-7 の pin を実測値で確定する
6. **回帰**: 全検証コマンドを走らせる (下記)

**赤を実測せずに台帳から書き始めないこと** (思考原則 5)。

## 受け入れ条件 (機械検証可能な形で)

| # | 条件 | 確認方法 |
|---|---|---|
| A1 | 新規検査が単体で緑 | `composer test -- --filter=NullInitialStateColumnClassification` が 0 失敗 |
| A2 | 規則が 7 本・負のコントロールが 7 本そろっている | 同上の実行結果に NI-1..NI-7 / NC-1..NC-7 の 14 件が現れる |
| A3 | 台帳が実スキーマと両方向で集合一致する | NI-1 が緑 |
| A4 | 台帳の全 entry の根拠が 30 文字以上 | NI-2 が緑 (加えて `NullableStateColumnEntry` の例外で台帳作成時に落ちる) |
| A5 | 母集団が 0 件でなく、(a)(b) の両系統が寄与している | NI-3 が緑 |
| A6 | 件数と一覧が現在値ちょうどで pin されている | NI-4 / NI-5 / NI-6 / NI-7 が緑 |
| A7 | **登録済みの列に DB 既定値を足すと赤くなる** | NC-3 が緑 (合成入力で点灯を確認) |
| A8 | v1 の資産が 1 文字も変わっていない | `git diff --stat` に `ScenarioWritePathInventoryTest.php` / `VideoManualService.php` / `TakeUploadService.php` が現れない |
| A9 | migration の追加・変更が無い | `git diff --stat database/migrations` が空 |
| A10 | PHPStan level 10 でエラー 0 | `composer phpstan` |
| A11 | 整形が通る | `vendor/bin/pint --test` |
| A12 | 既存の全テストが緑 | `composer test` |
| A13 | フロントの検証が緑 | 下記の pnpm 系すべて |
| A14 | 赤の実測が記録に残っている | `devnotes/20260817-1309-todo-t212-initial-state-insert-v2/red-first.md` が存在し、未分類 59 件の出力を含む |

### 全検証コマンド (すべて green であること)

```
composer test
composer phpstan
vendor/bin/pint --test
pnpm lint
pnpm typecheck
pnpm test
pnpm build
pnpm typecheck:packages
pnpm build:packages
pnpm test:packages
```

(`AGENTS.md` の `VERIFICATION_COMMANDS` ブロックが正本。
`tests/js/architecture/verification-commands-doc-sync.test.ts` が package.json と同期を強制する)

## 保証しないもの / やらないと決めたこと (理由付き)

| やらないこと | 理由 |
|---|---|
| 生成点のコード走査 (構文解析) を足す | この一族には**書き忘れが存在しない**。列が nullable で既定値を持たないとき、生成点が何も代入しないことがそのまま正しい意味になる。走査しても守るものが無い (AG-191 の s10 が同じ理由で「スキーマ pin 形」を選んだ) |
| CHECK 制約・部分一意索引・排他制約を足す | AG-191 が明示的に排除。i7 (制約を義務化しない) と衝突させない。制約を厚くした実費は家系で実測済み (別リポジトリで通し試験レーンが 18 件全滅) |
| `default('pending')` を落として NOT NULL 化する (家系標準 i2 への移行) | v2 の要求ではない。規約 2 は既定値を残す理由 (既存行と Factory 以外の INSERT 経路) を明文で持ち、正典 i3 の申告で表現できる形になっている。**後方互換の並走を作らないため、やるなら別施策で一度に**やる |
| Factory / Seeder を走査域に入れる | 家系の未決論点 q3 (`evidence` 待ち)。本裁定の範囲外 |
| 母集団を nullable かつ既定値なしの列**全数** (298) へ広げる | 250 件近くが「任意の記述・付随情報」で、根拠が定型文になり読まれなくなる。守りたい事故 (NULL の意味の静かな反転) が起きる形は時刻型と状態語彙に集中している。**広げなかったことは保証しない範囲として検査の docblock に実名で書く** |
| v1 の検査を作り直す / 統合する | 母集団が交わらない (既定値を持つ列 / 持たない列)。同じ事実を 2 か所で検査しない |
| 区分が正しいことの機械判定 | 「その列が状態列か」は機械化できない (正典 s5 が同じ結論)。人間のレビュー対象として残す |

## リスク

| リスク | 中身と受け止め方 |
|---|---|
| 台帳 59 行の初期投入コスト | 1 度きり。以後は列を足したときの 1 行だけである。区分の判断は「生成時に必ず NULL か」の 1 問で決まる形にして迷いを減らした |
| 区分の初期案が実読で覆る | 覆ってよい (初期案と明記した)。`users.deletion_requested_at` / `deletion_purge_after` はとくに移る可能性が高いと明記済み。判断がつかない列は `undecided` へ載せる |
| モデルのインスタンス化に副作用があるモデルが将来現れる | 現在の `app/Models` は素の Eloquent サブクラスのみ。副作用のあるモデルが現れたらそのモデルが原因で検査が落ちるので、**沈黙にはならない** |
| `pnpm` 側の検証に影響が出る | 変更が PHP とドキュメントだけなので影響しない。ただし `AGENTS.md` を触るため、`verification-commands-doc-sync` のマーカー区間 (`VERIFICATION_COMMANDS:BEGIN/END`) に触れないこと |
| `docs/architecture.md` の節追加が既存 doc 検査に触れる | 節を足すだけで既存の見出しを消さない。`DocumentTitleCoverageTest` は Inertia ページの題名の検査であり本変更と無関係 |
| 検査が実 DB を引くため Feature レーンの実行時間が伸びる | 引くのは表一覧と列定義のみ (1 回)。`RetentionTableClassificationTest` と同規模 |

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 新規 4 ファイル + `AGENTS.md` / `docs/architecture.md` への追記だけで、既存のコード経路に一切触れない。他施策と競合する面が無い |
| 競合リスク | `AGENTS.md` のドメイン固有規約に番号を足すため、同じ節を触る他タスクと衝突しうる。マージ順で解決する (番号は最後に採番する) |


## 関連する現行コード (抜粋)

### tests/Feature/Retention/RetentionTableClassificationTest.php (本施策の直接の先例。冒頭抜粋)

```php
<?php

declare(strict_types=1);

use App\Enums\Billing\BillingRetentionTarget;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Retention\RetentionClass;
use Tests\Support\Retention\RetentionTableEntry;
use Tests\Support\Retention\RetentionTableRegistry;

/*
 * Feature invariant: **実スキーマの全表が保持期限の区分へ分類されている** (deny-by-default)。
 *
 * SoT = devnotes/20260815-2057-retention-table-classification/detailed-design.md
 * (lctl 台帳 feature `retention-table-classification` 標準形 v1)。
 *
 * ★この gate が保証するもの:
 *   - RC-1 / RC-2: 実スキーマの表一覧と台帳が**両方向で集合等価**である
 *   - RC-3: 表名の二重宣言が無く、根拠が 30 文字以上ある
 *   - RC-4: 課金 7 年の表集合が App\Enums\Billing\BillingRetentionTarget と一致する
 *   - RC-5: 宣言された保持者 (クラス / コマンド) が**識別先として実在する**
 *   - RC-6 / RC-7: 区分と実スキーマの外部キーの構造が矛盾していない
 *   - RC-8: 総件数と未確定の表名を**現在値ちょうど**で pin する (無音で増えない)
 *
 * ★この gate が保証しないもの (誇張しない):
 *   - **列の内容が個人情報かどうかは見ない**。単位は表であり、列は見ない
 *   - **実データが実際に消えることは保証しない**。それは各掃除バッチの behavioral テスト
 *     (inquiry:purge / idempotency:prune / billing:purge-retention-expired /
 *      capture:purge-upload-reservations / account:purge-deletion-requests) の担当である
 *   - **`on delete cascade` の存在は「親が実際に消される」ことを意味しない**。
 *     親を消す経路が存在するかは見ていない
 *   - **保持者の実在は「そのクラス / コマンドがその表を処理すること」を意味しない**。
 *     見ているのは識別先が実在することだけであり、**Schedule に配線されているかも見ない**
 *     (コマンドが実在しさえすれば RC-5 は通る)
 *   - **行ごとの寿命の違いは表現しない**。単位は表なので、users のように
 *     「退会予約が入った行だけが消える」表も 1 つの区分に丸められる
 *   - **区分の意味が正しいかは人間のレビュー対象**である
 *   - S3 上の実体 (レンダ出力・撮影テイク) / ビュー / 他スキーマの表は対象外である
 *   - 表と外部キーの読み取りは**現在のスキーマ**に限る (`search_path` の健全性は前提であって
 *     保証ではない)
 *
 * ★責務境界 (二重検査を作らない):
 *   tests/Architecture/BillingRetentionTargetInventoryTest.php は
 *   「app/Models/Billing/ の課金モデルを 7 年で消すか消さないか」を扱い、年数・起算点列・
 *   purger の配線・実行順を持つ。本 gate は**それらを 1 つも持たず**、表集合の一致 (RC-4) だけで
 *   結線する。同じ事実を 2 か所に書かない。
 */

/** 台帳の総件数 (cap ではなく exact-fit。増減したら必ずこの数字を書き換える)。 */
const RETENTION_TABLE_COUNT = 63;

/**
 * 保持期限が**まだ決まっていない**表 (現在値ちょうど。増えるときも減るときもここを書き換える)。
 *
 * ここに 1 行足す / 消す操作は必ずテストの差分として現れる = レビューで見える。
 */
const RETENTION_UNDECIDED_TABLES = [
    'admin_users',
    'email_suppressions',
    'llm_call_logs',
    'model_audits',
    'oauth_access_tokens',
    'oauth_auth_codes',
    'oauth_clients',
    'oauth_device_codes',
    'oauth_refresh_tokens',
    'oauth_sessions',
    'organizations',
    'security_audit_events',
    'teams',
];

/**
 * スキーマ照会の入口。
 *
 * **ファサードではなく具体の Builder を取る** — `Schema::` の docblock は
 * `array getTables(...)` としか書いておらず、要素が mixed になる。
 * `Connection::getSchemaBuilder()` は `Illuminate\Database\Schema\Builder` を返し、
 * 実体側の shape 宣言がそのまま効く (**型を緩めて黙らせない**)。
 */
function retentionSchemaBuilder(): Builder
{
    return DB::connection()->getSchemaBuilder();
}

/**
 * 現在のスキーマの表名 (非修飾・sort 済み)。
 *
 * pgsql は引数なしだと全スキーマを返すため必ず現在のスキーマへ絞る。
 *
 * @return list<string>
 */
function retentionSchemaTableNames(): array
{
    $builder = retentionSchemaBuilder();
    $names = array_map(
        static fn (array $table): string => $table['name'],
        $builder->getTables($builder->getCurrentSchemaName()),
    );
    sort($names);

    return array_values($names);
}

/**
 * 全表の外部キーを 1 度だけ読み、表名 => 参照先と on delete の一覧にする。
 *
 * **スキーマ修飾名で問い合わせる** (`getForeignKeys()` は `schema.table` を受け取って分解する)。
 * 表一覧を現在のスキーマに絞っておきながら外部キーの照会だけ `search_path` 任せにすると、
 * 同名表があるときに食い違う。
 *
 * @return array<string, list<array{foreign_table: string, columns: list<string>, on_delete: string|null}>>
 */
function retentionForeignKeyMap(): array
{
    $builder = retentionSchemaBuilder();
    $schema = $builder->getCurrentSchemaName();

    $map = [];
    foreach (retentionSchemaTableNames() as $table) {
        $map[$table] = array_values(array_map(
            static fn (array $fk): array => [
                'foreign_table' => $fk['foreign_table'],
                'columns' => array_values($fk['columns']),
                'on_delete' => $fk['on_delete'],
            ],
            $builder->getForeignKeys($schema.'.'.$table),
        ));
    }

    return $map;
}

/**
 * 指定した表の列が nullable かどうか。
 *
 * RC-7 が `on delete set null` を非違反にしてよいかの判定にだけ使う
 * (`NOT NULL` の列が混ざっていると親の削除が制約違反で失敗するため)。
 * **対象は「基準データ」「基盤が寿命を持つ」に分類した表だけ**に絞って引く。
 *
 * @param  list<string>  $tables
 * @return array<string, array<string, bool>> 表名 => 列名 => nullable か
 */
function retentionNullableColumnMap(array $tables): array
{
    $builder = retentionSchemaBuilder();
    $schema = $builder->getCurrentSchemaName();

    $map = [];
    foreach ($tables as $table) {
        $columns = [];
        foreach ($builder->getColumns($schema.'.'.$table) as $column) {
            $columns[$column['name']] = $column['nullable'];
        }
        $map[$table] = $columns;
    }

    return $map;
}

/**
 * 母集団と台帳の突合 (**純関数** = 負のコントロールから合成入力で直接呼べる)。
 *
 * @param  list<string>  $schemaTables
 * @param  list<RetentionTableEntry>  $entries
 * @return array{unclassified: list<string>, phantom: list<string>, duplicated: list<string>}
 */
function retentionClassify(array $schemaTables, array $entries): array
{
    $declared = [];
    $duplicated = [];
    foreach ($entries as $entry) {
        if (array_key_exists($entry->table, $declared)) {
            $duplicated[] = $entry->table;

            continue;
        }
        $declared[$entry->table] = true;
    }

    $declaredTables = array_keys($declared);
    $unclassified = array_values(array_diff($schemaTables, $declaredTables));
    $phantom = array_values(array_diff($declaredTables, $schemaTables));

    sort($unclassified);
    sort($phantom);
    sort($duplicated);

    return [
        'unclassified' => array_values($unclassified),
        'phantom' => array_values($phantom),
        'duplicated' => array_values(array_unique($duplicated)),
    ];
}

/**
 * RC-6 の判定 (**純関数**。外部キーの一覧を引数で受け取るので合成入力で点灯させられる)。
 *
 * 通り道は **2 つだけ**である:
 *   (a) `on delete cascade` の外部キーを 1 本以上持つ (DB が連動を保証する)
 *   (b) 削除責務を持つクラスを宣言している (連動がアプリ側にある)
 * どちらも無ければ、その表は親が消えても残るので「親と一緒に消える」とは言えない。
 *
 * @param  list<RetentionTableEntry>  $entries
 * @param  array<string, list<array{foreign_table: string, columns: list<string>, on_delete: string|null}>>  $foreignKeys
 * @return list<string> 違反した表名
 */
function retentionDeletedWithParentViolations(array $entries, array $foreignKeys): array
{
    $violations = [];
    foreach ($entries as $entry) {
        if ($entry->class !== RetentionClass::DeletedWithParent || $entry->ownerClass !== null) {
            continue;
        }
        $cascades = array_filter(
            $foreignKeys[$entry->table] ?? [],
            static fn (array $fk): bool => retentionNormalizedOnDelete($fk['on_delete']) === 'cascade',
        );
        if ($cascades === []) {
            $violations[] = $entry->table;
        }
    }

    sort($violations);

    return $violations;
}

/**
 * `on delete` の表記ゆれ (大文字・前後の空白) を畳む。取得できないときは null のまま返す。
 */
function retentionNormalizedOnDelete(?string $onDelete): ?string
{
    if ($onDelete === null) {
        return null;
    }

    return mb_strtolower(trim($onDelete));
}

/**
 * RC-7 の判定 (**純関数**)。期限を持たない区分の表が、期限が要る区分の表を
 * **矛盾する `on delete` で**参照していないか。
 *
 * ★**外部キーの存在だけでは違反にしない**。親が消えたときに子がどうなるかで意味が変わる:
 *   - `cascade` = 子も消える → 「期限を持たない」と矛盾する (違反)
 *   - `restrict` / `no action` = 子から参照されている親行の削除を拒否する
 *     → 親の期限の執行を止めうる (違反)
 *   - `set null` = 子の外部キー列を空にして子は残る → 子自身は期限の連鎖の外にある。
 *     **ただし外部キーの列がすべて nullable なときに限る** — `NOT NULL` が混ざっていると
 *     親の削除は制約違反で失敗するので、実際には `restrict` と同じ結果になる (違反)
 *   - `set default` = 既定値が外部キー制約を満たさなければ親の削除は失敗する。
 *     本リポジトリに 1 本も無いため、現れたら分類の見直しが要るものとして保守的に違反へ倒す
 *   - `null` (取得できない) = 未知 → 保守的に違反へ倒す
 *
 * ★**足りない情報はすべて違反へ倒す (fail-closed)**:
 *   - 参照先の表が台帳に無い → 違反 (区分が決まらないものを黙って通さない)
 *   - 外部キーの列が空 → 違反 (空集合に対して「全部 nullable」と答える空虚な真を作らない)
 *   - 列の nullable が一覧に無い → 違反
 *   見るのは **Laravel の Schema API が返す外部キーの列すべて**である。判定は必要条件より
 *   厳しくなりうる (見落としではなく、余分に赤くなる側へ倒れる)。
 *
 * @param  list<RetentionTableEntry>  $entries
 * @param  array<string, list<array{foreign_table: string, columns: list<string>, on_delete: string|null}>>  $foreignKeys
 * @param  array<string, array<string, bool>>  $nullableColumns  表名 => 列名 => nullable か
 * @return list<string> `{表名} -> {親表名} (on delete …)` の形の違反一覧
 */
function retentionHorizonParentViolations(array $entries, array $foreignKeys, array $nullableColumns): array
{
    /** @var array<string, RetentionClass> $classByTable */
    $classByTable = [];
    foreach ($entries as $entry) {
        $classByTable[$entry->table] = $entry->class;
    }

    $violations = [];
    foreach ($entries as $entry) {
        if ($entry->class->hasHorizon()) {
            continue;
        }
        foreach ($foreignKeys[$entry->table] ?? [] as $fk) {
            $parentClass = $classByTable[$fk['foreign_table']] ?? null;
            if ($parentClass !== null && ! $parentClass->hasHorizon()) {
                // 参照先も期限を持たない区分なので、期限の連鎖は生まれない
                continue;
         
```

### tests/Support/Retention/RetentionTableEntry.php (台帳 1 行の先例。全文)

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Retention;

/**
 * 1 表分の保持期限の宣言。
 *
 * **コンストラクタは private で、区分ごとの名前付き生成子からしか作れない**。
 * 「定期実行が消すのに保持者が無い」宣言は書けない
 * (実行時の検査に頼らず、型で不正な状態を作らせない)。
 */
final readonly class RetentionTableEntry
{
    /** 根拠の最低文字数 (「同上」「N/A」を機械的に弾く。検査は gate の RC-3)。 */
    public const int RATIONALE_MIN_LENGTH = 30;

    /**
     * @param  class-string|null  $ownerClass  期限 / 削除責務の解決点クラス
     * @param  string|null  $ownerCommand  期限を執行する artisan コマンド名
     */
    private function __construct(
        public string $table,
        public RetentionClass $class,
        public string $rationale,
        public ?string $ownerClass = null,
        public ?string $ownerCommand = null,
    ) {}

    /** 課金取引の記録。年数・起算点・purger は書かない (正本は BillingRetentionTarget)。 */
    public static function billingRecord(string $table, string $rationale): self
    {
        return new self($table, RetentionClass::BillingRecord, $rationale);
    }

    /**
     * 定期実行が消す表。保持者の宣言は**必須**。
     *
     * @param  class-string  $ownerClass
     */
    public static function scheduledDeletion(
        string $table,
        string $rationale,
        string $ownerClass,
        string $ownerCommand,
    ): self {
        return new self($table, RetentionClass::ScheduledDeletion, $rationale, $ownerClass, $ownerCommand);
    }

    /**
     * 親と一緒に消える表。
     *
     * `on delete cascade` の外部キーを 1 本以上持つなら $ownerClass は不要。
     * 連動が DB ではなくアプリ側にある (cascade が無い) 場合は、削除責務を持つクラスを宣言する。
     *
     * @param  class-string|null  $ownerClass
     */
    public static function deletedWithParent(string $table, string $rationale, ?string $ownerClass = null): self
    {
        return new self($table, RetentionClass::DeletedWithParent, $rationale, $ownerClass);
    }

    public static function referenceData(string $table, string $rationale): self
    {
        return new self($table, RetentionClass::ReferenceData, $rationale);
    }

    public static function frameworkManaged(string $table, string $rationale): self
    {
        return new self($table, RetentionClass::FrameworkManaged, $rationale);
    }

    /** 保持期限が未確定の表。$rationale には**何が決まっていないか**を書く。 */
    public static function undecided(string $table, string $rationale): self
    {
        return new self($table, RetentionClass::Undecided, $rationale);
    }
}

```

### tests/Architecture/ScenarioWritePathInventoryTest.php (v1 の検査。冒頭抜粋)

```php
<?php

declare(strict_types=1);

/*
 * シナリオ整合の共有ロック規約 (AGENTS.md ドメイン固有規約 1) の書き込み経路 inventory。
 *
 * 「cuts / video_manuals.scenario_version / video_manuals.status を書き込む経路は次の 2 分類:
 *   (i) 更新経路 = 対象 VideoManual 行を lockForUpdate() で取得した同一トランザクション内で反映する。
 *   (ii) 生成経路 = 対象行が未存在のため所有元 Project 行を lockForUpdate() した同一 tx 内で INSERT し、
 *        初期状態 (status / scenario_version) を INSERT 時に明示代入する (DB default に依存しない)」
 *
 * 下表は**メソッド粒度で記録する**経路 inventory (docs/architecture.md と対)。ただし
 * **本テストの機械検証は下記 allowlist によるファイル粒度**であり表の粒度とは一致しない
 * (同一ファイル内のメソッド追加は検出しない。メソッド単位の fail-first は behavioral テストが担う):
 * | 経路 | 書いてよいもの |
 * |---|---|
 * | ScenarioService::save() | cuts / scenario_version / status (rendering·analyzing guard 付き) |
 * | ScenarioService::materializeIntoLockedManual() | cuts / scenario_version / status (analyzing→ready のみ) |
 * | AnalysisJobService::trigger() | status (draft·ready→analyzing のみ) |
 * | AnalysisJobService::failJob() | status (analyzing→ready·draft のみ。cuts 有無で決定。scenario_version は snapshot 読みのみ) |
 * | VideoManualService::displayXxxJob() | 書き込みなし (stale 判定で scenario_version を読むのみ) |
 * | VideoManualService::create() | status / scenario_version (**(ii) 生成経路**。新規 manual の INSERT 時に
 *   status=Draft / scenario_version=0 を明示代入する。対象 VideoManual 行は未存在のため所有元 Project 行を
 *   lockForUpdate した同一 tx 内で INSERT = 既存行への並行書き込みではない。検出 1 は
 *   SCENARIO_VERSION_ALLOWED、検出 2 は STATUS_WRITE_ALLOWED に登録済み (duplicate() と同一ファイル)。
 *   **allowlist はファイル粒度のため create() 単体の検出保証はなく、fail-first を担うのは
 *   tests/Feature/Projects/ManualServiceBoundaryTest.php の behavioral 契約テストである** (T151) |
 * | VideoManualService::duplicate() | **(ii) 生成経路**。cuts (lockForUpdate 済みの新 manual 経由で作成)。元 manual を
 *   lockForUpdate して一貫読み取り。複製 manual の INSERT 時に status=Draft / scenario_version=0 を
 *   明示代入する (新規行生成 = lockForUpdate 前だが、その tx が生成した排他的新規行・同一 tx 内反映で
 *   既存行への並行書き込みではない)。検出 1 (scenario_version) は SCENARIO_VERSION_ALLOWED、
 *   検出 2 (status) は STATUS_WRITE_ALLOWED に登録済み。検出 4 (adopted_take_id) は複製しないため非対象 |
 * | RenderJobService::trigger() | status (ready→rendering のみ。scenario_version はスナップショット読み) |
 * | RenderJobService::failJob() | status (rendering→ready のみ。kind=render に限る) |
 * | RenderJobService::completeRenderIntoLockedManual() | cuts.cut_length_ms / total_length_ms / status (rendering→published のみ) |
 * (RenderPipeline は VideoManualStatus を直接書かない = 全て RenderJobService メソッド経由。
 *  buildManifest/finalize の scenario_version は guard 読みのみ)
 *
 * deny-by-default の token ベース静的走査 (PrismDirectDispatchScanner と同じ token_get_all 流儀。
 * コメント/docblock/文字列リテラル**内容**中の出現は無視する)。走査対象: app/ 配下の .php。
 *
 * 検出 1: 識別子/配列キー 'scenario_version' の出現 → allowlist 外のファイルなら fail
 * 検出 2: 書き込み形 `'status' => ... VideoManualStatus::...` / `->status = ... VideoManualStatus::...`
 *         (`VideoManualStatus::class` = cast 宣言は書き込みでないため除外) → allowlist 外なら fail
 * 検出 3: materializeIntoLockedManual の宣言は ScenarioService.php のみ、
 *         呼び出しは AnalysisPipeline.php のみ (ScenarioService 自身の中の呼び出
```

### phpstan.neon (走査根に tests が入っていない)

```
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    level: 10
    paths:
        - app
        - config
        - database
        - routes
    excludePaths:
        - vendor
    ignoreErrors:
        # AppliesCriticalActionContextToAudit は派生アプリの Auditable モデル向けに
        # テンプレートが同梱する trait (テンプレート本体は Auditable モデルを同梱しない
        # ため使用箇所ゼロ)。派生アプリで使用された時点で通常解析される。
        # 実挙動は tests/Feature/Audit/ModelAuditGatingTest.php が検証している。
        -
            identifier: trait.unused
            path: app/Models/Concerns/AppliesCriticalActionContextToAudit.php

```
