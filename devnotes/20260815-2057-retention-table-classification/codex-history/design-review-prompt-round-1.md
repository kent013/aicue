# 使命 (North Star)

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


# 禁止事項

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
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 13 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pest テストフレームワーク。`tests/Pest.php` が Feature/Unit に RefreshDatabase をグローバル適用。`--parallel` 実行
- テスト DB は pgsql (phpunit.xml が DB_CONNECTION=pgsql を force)。worktree ごとに DB 名が変わる
- Architecture lane は DB を持たない (ファイル走査中心)
- Laratrust RBAC（Organization → Team → Project階層）

【本設計の性質 — 誤解を避けるため先に伝える】
- 本設計は **UI も HTTP 応答も LLM 呼び出しも足さない**。テスト側の宣言 (目録) と検査だけを足す
- アプリ実行時コード (`app/`) は 1 行も変更しない
- 既存の保持期限の「値」は 1 つも動かさない (委譲するだけ)

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null 安全性）
2. 既存コードとの整合性（命名規約、パターン、目録 gate の作法）
3. PHPStan level 10 適合性（型安全性、generics、配列 shape）
4. テスト計画の網羅性（負のコントロール、空振り検知、RefreshDatabase グローバル適用に従う）
5. 副作用・後退リスク（並列実行、実行時間、他タスクとの競合）
6. 二重検査・二重管理を作っていないか（既存 gate との責務境界）
7. 保証範囲の記述が実態と一致しているか（誇張していないか / 過小でないか）
8. Laravel 13 の Schema API の使い方が正しいか（pgsql のスキーマ解決、getTables / getForeignKeys の戻り値 shape）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: retention-table-classification (表ごとの保持期限の分類と実スキーマ整合)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用

> 本設計は 4〜9 に触れない (UI も LLM も HTTP 応答も足さない)。1 と 2 は下記テスト方針・PHPStan 節で満たす。
> 3 について: 本 gate は**読み取り専用の照会だけ**を行う (`getTables` / `getForeignKeys`)。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ずFactoryで生成**（本設計はデータを作らない — 読み取り専用の照会のみ）
- `declare(strict_types=1)` + 日本語コメント（git 追跡下の PHP 全数が対象）
- **コードフォーマット**: `composer fix`（Pint）/ `vendor/bin/pint --test`
- PHP 8.4 + Laravel 13

## 概念設計リファレンス

`devnotes/20260815-2057-retention-table-classification/conceptual-design.md`
（Codex 合議 Round 2 で APPROVED）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 区分 enum と宣言の値オブジェクト | `tests/Support/Retention/RetentionClass.php` / `tests/Support/Retention/RetentionTableEntry.php` (新規) | 高 |
| 2 | 全表の分類台帳 | `tests/Support/Retention/RetentionTableRegistry.php` (新規) | 高 |
| 3 | 実スキーマとの照合 gate | `tests/Feature/Retention/RetentionTableClassificationTest.php` (新規) | 高 |
| 4 | 既存 gate との責務境界の明記 | `tests/Architecture/BillingRetentionTargetInventoryTest.php` (docblock 追記のみ) | 中 |
| 5 | 運用文書 | `docs/architecture.md` (§追加) | 中 |
| 6 | 規約への登録 | `AGENTS.md` (ドメイン固有規約に 1 項) | 中 |

---

## 施策 1: 区分 enum と宣言の値オブジェクト

### 変更箇所

- 新規: `tests/Support/Retention/RetentionClass.php`
- 新規: `tests/Support/Retention/RetentionTableEntry.php`

**アプリの実行時コード (`app/`) は 1 行も足さない**。本台帳を実行時に読む消費者がいないためで、
本リポジトリの先例 (`tests/Support/ExternalSeam/` / `tests/Support/Recovery/` /
`tests/Support/ForbiddenStatement/`) と同じ置き場所にする。

### 波及変更

- TypeScript型定義: なし
- API Resource/DTO: なし
- テストファイル: 施策 3 で新規追加。既存テストの改変は施策 4 の docblock 追記だけ

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Retention;

/**
 * 表ごとの「保持期限を誰が持つか」の区分。
 *
 * 分類の母集団は**実スキーマの表一覧**であり、人間が申告したディレクトリやモデル一覧ではない
 * (母集団を申告に置くと、申告の外に置かれた表は何をしても検出できない)。
 */
enum RetentionClass: string
{
    /** 課金取引の記録。期限 (7 年) と実処理の正本は App\Enums\Billing\BillingRetentionTarget 側にある。 */
    case BillingRecord = 'billing_record';

    /** 定期実行の掃除が期限を執行する表。期限の保持者 (解決点クラスとコマンド) の宣言が要る。 */
    case ScheduledDeletion = 'scheduled_deletion';

    /** 独自の期限を持たず、親行の削除に連動して消える表。 */
    case DeletedWithParent = 'deleted_with_parent';

    /** 期限を持たない基準データ。運用者が入れ替えるまで残る (プラン / 権限 / 分類)。 */
    case ReferenceData = 'reference_data';

    /** フレームワーク・キュー・セッションの実装が寿命を決める表。 */
    case FrameworkManaged = 'framework_managed';

    /** 保持期限がまだ決まっていない表。隠さずここへ載せる (件数と表名を gate が pin する)。 */
    case Undecided = 'undecided';

    /**
     * その表がいずれ消えることを前提にしている区分か。
     *
     * ReferenceData / FrameworkManaged がこの側の表を**親に持っていたら**、
     * その表自身も期限の連鎖の中にあることになる (= 分類が間違っている)。
     */
    public function hasHorizon(): bool
    {
        return match ($this) {
            self::BillingRecord,
            self::ScheduledDeletion,
            self::DeletedWithParent,
            self::Undecided => true,
            self::ReferenceData,
            self::FrameworkManaged => false,
        };
    }

    /** 人が読む区分名 (失敗メッセージ用)。 */
    public function label(): string
    {
        return match ($this) {
            self::BillingRecord => '課金取引の記録 (7 年)',
            self::ScheduledDeletion => '定期実行が消す',
            self::DeletedWithParent => '親と一緒に消える',
            self::ReferenceData => '基準データ',
            self::FrameworkManaged => '基盤が寿命を持つ',
            self::Undecided => '未確定',
        };
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Retention;

/**
 * 1 表分の保持期限の宣言。
 *
 * **コンストラクタは private で、区分ごとの名前付き生成子からしか作れない**。
 * 「定期実行が消すのに保持者が無い」宣言は PHPStan の段階で書けない
 * (実行時の検査に頼らず、型で不正な状態を作らせない)。
 */
final readonly class RetentionTableEntry
{
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

### PHPStan適合チェック

- [x] 戻り値の型が明示されている
- [x] null 安全 (nullable は `?string` で明示。`match` は全 case 網羅で `else` を持たない)
- [x] `class-string` の phpdoc を付ける (`class_exists()` 検査の入力になる)
- [x] 配列返却なし (値オブジェクト)

### テスト計画

- [x] 施策 3 の gate が全 case を実際に評価するため、単独の Unit テストは作らない
      (**評価されない述語を作らない**。`hasHorizon()` は RC-7、`label()` は失敗メッセージで使われる)
- [ ] 負のコントロール: 名前付き生成子を経由しない生成が**型として不可能**であることは
      private コンストラクタで担保する (テストではなく設計で閉じる)

### リスク

- 区分が 6 種あるため「どれに入れるか迷う表」が出る。迷ったら `undecided()` に入れて
  何が決まっていないかを書く運用にする (無理に分類して嘘の根拠を書かせない)。

---

## 施策 2: 全表の分類台帳

### 変更箇所

- 新規: `tests/Support/Retention/RetentionTableRegistry.php`

### 変更後コード（骨格）

```php
<?php

declare(strict_types=1);

namespace Tests\Support\Retention;

/**
 * 実スキーマの全表を保持期限の区分へ分類した台帳。
 *
 * ★**除外一覧を持たない**。基盤の表 (migrations / cache / sessions / jobs) も
 *   区分の 1 つとして必ずここに載る。除外の口を作ると、そこへ名前を足すだけで
 *   検査から逃げられる。
 *
 * ★**年数・起算点・purger の配線は書かない**。課金 7 年の正本は
 *   App\Enums\Billing\BillingRetentionTarget、各バッチの期限は各 config の解決点クラスであり、
 *   ここに写すと二重管理になる。ここが持つのは「区分」「根拠」「保持者の名前」だけである。
 *
 * 実スキーマとの両方向の集合等価は
 * tests/Feature/Retention/RetentionTableClassificationTest.php が deny-by-default で強制する。
 */
final class RetentionTableRegistry
{
    /** @return array<string, RetentionTableEntry> 表名 => 宣言 */
    public static function entries(): array
    {
        $entries = [
            // --- 課金取引の記録 (7 年。正本は BillingRetentionTarget) ---
            RetentionTableEntry::billingRecord('subscriptions', '契約の取引記録。…'),
            // …

            // --- 定期実行が消す ---
            RetentionTableEntry::scheduledDeletion(
                'inquiries',
                '公開問い合わせの本文と連絡先を保持する。閉じた / spam と判定した行を保持日数の超過で削除する',
                \App\Console\Commands\PurgeInquiriesCommand::class,
                'inquiry:purge',
            ),
            // …
        ];

        $keyed = [];
        foreach ($entries as $entry) {
            $keyed[$entry->table] = $entry;
        }

        return $keyed;
    }
}
```

> `entries()` を「表名をキーにした配列」で返すのは、**同じ表を 2 回宣言しても最後の 1 件しか
> 残らない**形を避けるためではなく (それだと二重宣言が消える)、gate 側で件数を突き合わせるためである。
> **二重宣言の検出は gate の RC-3 が `list` の段階で行う** (下記)。よって `entries()` は
> `list<RetentionTableEntry>` を返し、キー化は gate 側で行う。実装時はこちらを採る。

### 初期分類（案）

**この表は実装の出発点であり、正本ではない**。実装時はまず gate を 1 度回して
実スキーマの表一覧を出させ (RC-1 が未分類の表名を全部並べる)、その一覧と突き合わせること。
Laravel の `migrations` 表のように migration 定義に現れない表があるためである。

| 表 | 区分 | 根拠の要点 / 保持者 |
|---|---|---|
| `subscriptions` `subscription_items` `stripe_webhook_events` `billing_checkout_sessions` `ticket_checkout_sessions` `ticket_auto_recharge_attempts` `ticket_ledger_entries` | 課金取引の記録 | 正本は `BillingRetentionTarget` (7 表。RC-4 が両方向で結線) |
| `inquiries` | 定期実行が消す | `PurgeInquiriesCommand` / `inquiry:purge` |
| `idempotency_keys` `mcp_idempotency_keys` | 定期実行が消す | `App\Support\Idempotency\IdempotencyRetention` / `idempotency:prune` |
| `take_upload_reservations` | 定期実行が消す | `PurgeUploadReservationsCommand` / `capture:purge-upload-reservations` |
| `users` | 定期実行が消す | `App\Support\Account\AccountDeletionGrace` / `account:purge-deletion-requests`。根拠に「**退会予約が入った行だけ**が猶予後に物理削除される。予約の無い行に期限は無い」と書く |
| `organizations` `custom_teams` `teams` `projects` `organization_user` `project_members` `organization_invitations` `role_user` `permission_user` `social_accounts` `passkeys` `api_keys` `notifications` `items` `categories` `video_manuals` `source_documents` `cuts` `takes` `analysis_jobs` `render_jobs` `ticket_reservations` `ticket_auto_recharges` `organization_quotas` `billing_notifications` | 親と一緒に消える | 親 (organizations / projects / users / video_manuals …) の cascade。**`teams` は cascade FK を持たない可能性が高い**ので、その場合は削除責務クラスを宣言する (OR 条件) |
| `plans` `plan_prices` `ticket_volume_prices` `roles` `permissions` `permission_role` | 基準データ | 運用者が入れ替えるまで残る。個人に紐づかない |
| `migrations` `cache` `cache_locks` `jobs` `job_batches` `failed_jobs` `sessions` `password_reset_tokens` | 基盤が寿命を持つ | フレームワーク / キュー / セッションの実装が寿命を決める |
| `llm_call_logs` `security_audit_events` `model_audits` `email_suppressions` `blind_indexes` `admin_users` `oauth_access_tokens` `oauth_refresh_tokens` `oauth_auth_codes` `oauth_device_codes` `oauth_clients` `oauth_sessions` | **未確定** | 下記 |

**未確定に置く理由 (根拠欄に書く内容)**:

- `llm_call_logs` / `security_audit_events`: 組織・利用者への外部キーが `null on delete` なので、
  退会・組織削除の後も**行は残る**。費用分析・監査の必要期間が決まっていない。
- `model_audits`: 監査証跡。外部キーを持たない (`morphs`) ので親の削除に連動しない。保持期間が未決。
- `email_suppressions`: メールアドレスそのものを保持する。送達抑制の必要期間が未決。
- `blind_indexes`: 暗号化列の検索用索引。親行の削除に連動するかを実装で確認していない。
- `admin_users`: 運用者アカウント。退任時の扱いが手順として決まっていない。
- `oauth_*`: 失効・期限切れのトークンを掃除する定期実行が登録されていない
  (Passport の掃除コマンドは存在するが `routes/console.php` の Schedule に無い)。

> **未確定は「まだ何もしていない」の可視化であって、放置の許可ではない**。
> 件数と表名を RC-8 が現在値ちょうどで pin するため、増えるときも減るときも
> テストの変更として必ずレビューに出る。

### 波及変更

- TypeScript型定義 / API Resource / DTO: なし

### テスト計画

- [x] 台帳そのもののテストは作らない (**台帳は宣言であり、検査は施策 3 の gate が全数で行う**)
- [x] 二重宣言・幽霊登録・根拠不足はすべて gate の RC-1 / RC-2 / RC-3 が拾う

### リスク

- 初期分類 63 行の記述量が多い。ただし**これは 1 度きり**で、以後は表を足した人が 1 行足す。
- 分類の意味が間違っていても機械は気付かない (保証しないものに明記済み)。
  緩和策として、区分ごとの構造検査 (RC-6 / RC-7) が「明らかに矛盾する分類」だけは落とす。

---

## 施策 3: 実スキーマとの照合 gate

### 変更箇所

- 新規: `tests/Feature/Retention/RetentionTableClassificationTest.php`

**Feature lane に置く** — 実スキーマを引くため DB が要る。Architecture lane は DB を持たないので
migration 前の空スキーマしか見えず**常に空振り**する
(既存 `tests/Architecture/BillingRetentionTargetInventoryTest.php` の docblock 36〜41 行が、
同じ理由で schema 照合を Feature lane へ移した実例を残している)。

### 検査一覧

| ID | 検査 | 落ちるとき |
|---|---|---|
| RC-1 | 実スキーマの表がすべて台帳に載っている | 表を足して分類を書き忘れた |
| RC-2 | 台帳の表がすべて実スキーマに実在する | 表を消したのに台帳から消し忘れた (幽霊登録) |
| RC-3 | 表名の二重宣言が無い / 根拠が 30 文字以上 | 同じ表を 2 回書いた / 根拠が形だけ |
| RC-4 | 課金 7 年の表集合が `BillingRetentionTarget` と**両方向で一致** | 片側だけ増減した |
| RC-5 | 宣言された保持者が実在する (`class_exists` / 登録済み artisan コマンド) | クラス改名・コマンド廃止に追随していない |
| RC-6 | 「親と一緒に消える」の裏取り: **`on delete cascade` の FK を 1 本以上持つ**、**または**削除責務クラスを宣言している | 孤立した表を「親に連動する」と言い張った |
| RC-7 | 「基準データ」「基盤が寿命を持つ」が、期限が要る区分の表への FK を 1 本も持たない | 期限の連鎖の中にある表を「期限を持たない」と分類した |
| RC-8 | 空振り検知: 総件数 / 区分ごとの件数 / **未確定の表名一覧**を現在値ちょうどで pin | 台帳が空になった / 未確定が無音で増えた |
| NC-1〜4 | 負のコントロール (合成入力で RC-1 / RC-2 / RC-6 / RC-7 を 1 つずつ点灯させる) | 検査が形だけになった |

> RC-6 の条件は **OR** である。DB の cascade で連動する表は保持者を書かなくてよく、
> 連動がアプリ側にある表 (`teams` / vendor 由来の表など) は削除責務クラスを宣言して通す。
> **どちらも無い表は「親と一緒に消える」と宣言できない** (この 2 つが唯一の通り道である)。

### 実装の骨格

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Retention\RetentionClass;
use Tests\Support\Retention\RetentionTableEntry;
use Tests\Support\Retention\RetentionTableRegistry;

/*
 * Feature invariant: **実スキーマの全表が保持期限の区分へ分類されている** (deny-by-default)。
 *
 * ★この gate が保証するもの:
 *   - RC-1 / RC-2: 実スキーマの表一覧と台帳が**両方向で集合等価**である
 *   - RC-4: 課金 7 年の表集合が App\Enums\Billing\BillingRetentionTarget と一致する
 *   - RC-5: 宣言された保持者 (クラス / コマンド) が**識別先として実在する**
 *   - RC-6 / RC-7: 区分と実スキーマの外部キーの構造が矛盾していない
 *   - RC-8: 未確定の表が**無音で増えない** (件数と表名を現在値ちょうどで pin)
 *
 * ★この gate が保証しないもの (誇張しない):
 *   - **列の内容が個人情報かどうかは見ない**。単位は表であり、列は見ない
 *   - **実データが実際に消えることは保証しない**。それは各掃除バッチの behavioral テスト
 *     (inquiry:purge / idempotency:prune / billing:purge-retention-expired /
 *      capture:purge-upload-reservations / account:purge-deletion-requests) の担当である
 *   - **`on delete cascade` の存在は「親が実際に消される」ことを意味しない**。
 *     親を消す経路が存在するかは見ていない
 *   - **保持者の実在は「そのクラス / コマンドがその表を処理すること」を意味しない**。
 *     見ているのは識別先が実在することだけである
 *   - **区分の意味が正しいかは人間のレビュー対象**である
 *   - S3 上の実体 (レンダ出力・撮影テイク) / ビュー / 他スキーマの表は対象外である
 *
 * ★責務境界 (二重検査を作らない):
 *   tests/Architecture/BillingRetentionTargetInventoryTest.php は
 *   「app/Models/Billing/ の課金モデルを 7 年で消すか消さないか」を扱い、年数・起算点列・
 *   purger の配線・実行順を持つ。本 gate は**それらを 1 つも持たず**、表集合の一致 (RC-4) だけで
 *   結線する。同じ事実を 2 か所に書かない。
 */

/** 現在のスキーマの表名 (非修飾)。pgsql は引数なしだと全スキーマを返すため必ず絞る。 */
function retentionSchemaTableNames(): array
{
    $builder = Schema::getFacadeRoot();

    return array_column($builder->getTables($builder->getCurrentSchemaName()), 'name');
}

/** 表の外部キー (参照先表名と on delete の挙動だけを取り出す)。 */
function retentionForeignKeys(string $table): array
{
    return array_map(
        static fn (array $fk): array => [
            'foreign_table' => $fk['foreign_table'],
            'on_delete' => $fk['on_delete'],
        ],
        Schema::getForeignKeys($table),
    );
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
    // …
}

test('RC-1 / RC-2: 実スキーマの表一覧と台帳が両方向で集合等価である', function (): void {
    $result = retentionClassify(retentionSchemaTableNames(), RetentionTableRegistry::entries());

    expect($result['unclassified'])->toBe([],
        '保持期限の区分が無い表を検出しました。tests/Support/Retention/RetentionTableRegistry.php へ '
        .'区分と 30 文字以上の根拠付きで 1 行足してください (決まっていないなら undecided で構いません)。'
        .PHP_EOL.implode(PHP_EOL, $result['unclassified']));

    expect($result['phantom'])->toBe([],
        '実スキーマに存在しない表が台帳に残っています (表を消したときの消し忘れ): '
        .implode(', ', $result['phantom']));
});
```

RC-4 の結線:

```php
test('RC-4: 課金 7 年の表集合が BillingRetentionTarget と両方向で一致する', function (): void {
    $declared = /* 台帳の BillingRecord 区分の表名 (sort 済み) */;
    $canonical = array_map(
        static fn (BillingRetentionTarget $case): string => $case->table(),
        BillingRetentionTarget::cases(),
    );
    sort($canonical);

    expect($declared)->toBe($canonical,
        '課金 7 年の対象表が 2 つの目録で食い違っています。年数と実処理の正本は '
        .'App\Enums\Billing\BillingRetentionTarget であり、本台帳は区分の宣言だけを持ちます。');
});
```

RC-6 / RC-7:

```php
test('RC-6: 「親と一緒に消える」表は cascade の外部キーを持つか削除責務クラスを宣言している', function (): void {
    $violations = [];
    foreach (RetentionTableRegistry::entries() as $entry) {
        if ($entry->class !== RetentionClass::DeletedWithParent) {
            continue;
        }
        if ($entry->ownerClass !== null) {
            continue; // 連動がアプリ側にある表 (OR 条件のもう一方)
        }
        $cascades = array_filter(
            retentionForeignKeys($entry->table),
            static fn (array $fk): bool => $fk['on_delete'] === 'cascade',
        );
        if ($cascades === []) {
            $violations[] = $entry->table;
        }
    }

    expect($violations)->toBe([],
        'cascade の外部キーも削除責務クラスの宣言も無い表を「親と一緒に消える」と分類しています '
        .'(親が消えてもこの行は残ります): '.implode(', ', $violations));
});

test('RC-7: 期限を持たない区分の表が、期限が要る区分の表を親に持っていない', function (): void {
    // ReferenceData / FrameworkManaged の表の FK 参照先が hasHorizon() の区分なら赤
});
```

RC-8 (空振り検知):

```php
/** 台帳の総件数 (cap ではなく exact-fit。増減したら必ずこの数字を書き換える)。 */
const RETENTION_TABLE_COUNT = 63; // 実装時に実スキーマから確定する

/** 保持期限が**まだ決まっていない**表 (現在値ちょうど。増えるときも減るときもここを書き換える)。 */
const RETENTION_UNDECIDED_TABLES = [ /* 実装時に確定 */ ];
```

### 波及変更

- TypeScript型定義 / API Resource / DTO: なし
- テストファイル: 施策 4 の docblock 追記のみ

### PHPStan適合チェック

- [x] 純関数の引数・戻り値に配列 shape の phpdoc を付ける (`array{unclassified: list<string>, …}`)
- [x] `Schema::getForeignKeys()` の戻り値は framework の配列 shape に従い、必要なキーだけ取り出す
- [x] `class-string` を `class_exists()` へ渡す (RC-5)
- [x] `match` は全 case 網羅

### テスト計画

- [x] 新規テストそのものが本施策の成果物である
- [ ] **負のコントロール 4 本**: 合成入力で RC-1 / RC-2 / RC-6 / RC-7 を 1 つずつ点灯させる
      (実スキーマを触らずに純関数へ配列を渡す。DB を汚さない)
- [ ] 正の自己検証: RC-6 は「cascade を持つ表が 1 件以上実在する」ことも確認する
      (全件が `ownerClass` 宣言で素通りしていたら検査が形だけになる)
- [ ] 個別の `DatabaseTransactions` を使わない (グローバル `RefreshDatabase` に従う)
- [ ] 実行時間: 外部キーの照会は**必要な区分の表だけ**に絞り、同一表を 2 度引かないよう
      静的配列で覚える (63 表 × 1 クエリが上限)

### リスク

- **`--parallel` で並列 DB を使うため、全レーンで同じスキーマが見えることが前提**である。
  worktree ごとに DB 名が変わるが、migration は同一なので表一覧は一致する。
- 将来 vendor package が migration を publish して表が増えると、その PR が赤くなる。
  これは意図した挙動である (分類を書く場所へ誘導する失敗メッセージを出す)。
- `Schema::getFacadeRoot()` の使用は型が緩い。実装時は
  `Schema::connection(config('database.default'))` 等、PHPStan level 10 が
  `Illuminate\Database\Schema\Builder` として解決できる呼び方を選ぶ
  (**型を緩めて黙らせない** — 禁止事項 2)。

---

## 施策 4: 既存 gate との責務境界の明記

### 変更箇所

- `tests/Architecture/BillingRetentionTargetInventoryTest.php` の冒頭 docblock
  (32〜46 行の「保証しないもの」節) へ 1 項追記

### 変更後（追記する文言）

```
 *   - **purge 対象テーブルの網羅性は保証しない**。(既存の記述はそのまま)
 *     **実スキーマの表一覧全体との集合等価は
 *     tests/Feature/Retention/RetentionTableClassificationTest.php が持つ** (母集団が違う —
 *     あちらは実スキーマの実測、こちらは app/Models/Billing/ という人間の申告)。
 *     本 gate は年数・起算点列・purger の配線・実行順を持ち、あちらは**それらを 1 つも持たない**。
 *     重なるのは対象表の集合だけで、そこはあちらの RC-4 が本 enum と両方向で結線する。
 *     **同じ事実を 2 か所に書かないこと** (どちらかに検査を足す前に、この境界を読み直す)。
```

### 波及変更

- なし。**検査は 1 本も足さない / 変えない** (docblock だけ)

### テスト計画

- [x] 既存 8 テストが緑のままであること (`composer test -- --filter=BillingRetentionTargetInventory`)
- [x] 検査の増減が無いことをレビューで確認する (差分が docblock だけ)

### リスク

- なし (コメントのみ)。

---

## 施策 5: 運用文書

### 変更箇所

- `docs/architecture.md` に §**表ごとの保持期限の分類** を新設
  (§課金記録の保持期間 (7 年) の決着 の直後に置く)

### 記載内容

- 何を分類するか (実スキーマの全表)、区分 6 種とその意味
- **保証するもの 4 つ / 保証しないもの 6 つ** (gate の docblock と同じ内容)
- 既存の保持期限 6 系統との関係 (値の正本は動かしていないこと)
- `BillingRetentionTargetInventoryTest` との責務境界
- **件数と表名は書かない** — 正本は台帳だけである。
  (AGENTS.md §禁止する文 が「登録の正本は目録だけで、本書には件数を写さない
  (2 か所に書くと必ず食い違う)」という同じ方針を既に採っている)

### テスト計画

- [x] 文書の機械照合は行わない (件数を写さない方針なので照合対象が無い)

### リスク

- 文書と台帳がずれる余地は「区分の説明文」だけに限定される (数値を持たないため)。

---

## 施策 6: 規約への登録

### 変更箇所

- `AGENTS.md` の「ドメイン固有規約」に 15 項目めを追加

### 記載内容（骨子）

```
15. **表ごとの保持期限の分類**: migration で表を足したら、
    `tests/Support/Retention/RetentionTableRegistry.php` へ区分と 30 文字以上の根拠を
    1 行足す (deny-by-default。`RetentionTableClassificationTest` が実スキーマの表一覧と
    両方向で突き合わせる)。区分は 6 種で、期限が決まっていないなら「未確定」に載せる
    (隠さない。件数と表名は gate が現在値ちょうどで pin する)。
    - **年数・起算点・purger の配線は台帳に書かない**。課金 7 年の正本は
      `BillingRetentionTarget`、各バッチの期限は各 config の解決点クラスであり、
      台帳が持つのは区分・根拠・保持者の名前だけである
    - **保証範囲を誇張しない**: 見るのは表単位であり列は見ない。
      分類の意味が正しいかは人間のレビュー対象で、実データが消えることは
      各掃除バッチの behavioral テストが担う。正本は
      `docs/architecture.md` §表ごとの保持期限の分類
```

### テスト計画

- [x] AGENTS.md の記述と gate の docblock が同じ保証範囲を言っていることをレビューで確認する
      (機械照合はしない — 件数を持たないため)

### リスク

- ドメイン固有規約が 15 項に増える。ただし本項は「新しい表を足す人が必ず踏む」規約であり、
  規約に載せないと deny-by-default の失敗メッセージだけが手掛かりになる。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 新規ファイル 4 本 (tests/Support/Retention/ 3 + tests/Feature/Retention/ 1) が中心で、既存ファイルへの変更は docblock 追記 1 か所と文書 2 本だけである。ただし**実スキーマの表一覧全体に触れる**ため、同時期に migration を足す他タスクと突き合わせが必要になる (表が増えれば RC-1 と RC-8 の件数が動く)。単独ブランチで完結させ、main へ入れる直前に `composer test` を回して件数を確定させるのが安全である |
| 競合リスク | **migration を追加する他タスクと衝突する**。衝突時の解消は簡単 (台帳に 1 行足して RC-8 の件数を +1) だが、**マージ順序によって必ず 1 回赤くなる**ことを実装者に伝える。逆に言えば「表を足した PR は本 gate を必ず踏む」ので、これは設計の意図どおりの摩擦である |

## 検証コマンド

```
composer test -- --filter=RetentionTableClassification
composer test -- --filter=BillingRetentionTargetInventory
composer test
composer phpstan
vendor/bin/pint --test
```


---

## 関連する現行コード

### tests/Architecture/BillingRetentionTargetInventoryTest.php (冒頭 docblock と主要検査)

```php
<?php

declare(strict_types=1);

use App\Enums\Billing\BillingRetentionExclusion;
use App\Enums\Billing\BillingRetentionTarget;
use App\Services\Billing\Contracts\BillingRetentionPurger;
use App\Services\Billing\Retention\BillingRetentionPurgerRegistry;
use Illuminate\Database\Eloquent\Model;

/*
 * Architecture invariant: **課金記録の保持期間 (7 年) の purge 目録は deny-by-default**。
 *
 * SoT = devnotes/20260809-0908-account-deletion-grace/detailed-design.md の PR-C1
 * (lctl 台帳 feature `account-deletion-billing-guard` 標準形 v1 / 裁定 AG-128 の必須 (3)
 * 「保持期間 = 規約が宣言する年数と実処理の対応づけ」)。
 *
 * ★この gate が保証するもの:
 *   - 検査 1: 課金モデルの母集団 (app/Models/Billing/** + Cashier の SubscriptionItem) が
 *     `BillingRetentionTarget` ∪ `BillingRetentionExclusion` に **ちょうど 1 回**現れる
 *     (新しい課金モデルを足したら「消す / 消さない」を必ず宣言させる)
 *   - 検査 2: 全 case の rationale() が 30 文字以上
 *   - 検査 3: 起算点 / 補助時計の**修飾名が構造的に解決できる** (`{table}.{column}` の
 *     table 部が目録内の実在 target のテーブルである)
 *   - 検査 4: target と purger 実装クラスが exact-fit (registry / ディレクトリ / enum の 3 者)
 *   - 検査 4b: 実行順が**子 → 親** (`SubscriptionItem` → `Subscription`)
 *   - 検査 5: 空振り検知 (母集団件数 / 目録件数 / purger 件数を**現在値ちょうど**で pin。
 *     余裕枠は「根拠なしに増やせる枠」になるため持たせない)
 *   - 検査 6: 負のコントロール (未分類のダミーモデルを混ぜると検査 1 が点灯する)
 *
 * ★この gate が保証しないもの (誇張しない):
 *   - **purge 対象テーブルの網羅性は保証しない**。母集団は `app/Models/Billing/` という
 *     **ディレクトリの人間の申告**であり、課金取引の記録が別ディレクトリ (例: app/Models/ 直下 /
 *     別ドメインのモデル) や Eloquent を経由しない表に置かれた場合、この gate は**沈黙する**。
 *     目録は「機械が見つけた全部」ではなく「人間が申告した全部」である
 *   - **列が実在するか**は静的には見ない。詳細設計 C1d は実在列の照合 (schema 照合) も
 *     本 gate の責務としていたが、**Architecture lane は DB を持たない** (tests/Pest.php が
 *     RefreshDatabase を Feature/Unit にしか適用しないため、ここで Schema を引いても
 *     migration 前の空スキーマしか見えず**常に空振り**する)。よって schema 照合だけは
 *     tests/Feature/Billing/BillingRetentionHorizonTest.php へ移した (検査そのものは消していない)。
 *     ここで見るのは修飾名の**構造**(検査 3) までである
 *   - **起算点の意味の正しさ** (その列が本当に「取引の終了時」か) は人間のレビュー対象である
 *   - 保持年数の値そのもの / 規約文面との一致は本 gate の担当ではない
 *     (前者は tests/Architecture/BillingRetentionConfigSingleSourceTest.php、
 *      後者は PR-C3 の三者一致 gate)
 *
 * DB 不使用の静的検査 (Architecture lane の作法)。
 */

/**
 * app/Models/Billing/ の外にある母集団 (理由付きの明示列挙)。
 *
 * `Laravel\Cashier\SubscriptionItem` は vendor のモデルだが `subscription_items` は
 * 自リポジトリの migration が作る課金取引の記録であり、親 (`subscriptions`) を消すなら
 * 一緒に決着させないと孤児になる。vendor 由来を理由に母集団から外さない。
 *
 * @var array<class-string<Model>, string>
 */
const BILLING_RETENTION_EXTERNAL_POPULATION = [
    'Laravel\Cashier\SubscriptionItem' => 'subscription_items は自リポジトリの migration が作る課金記録 (親 subscriptions の子)',

(中略)
test('検査 1: 課金モデルの母集団が target / exclusion にちょうど 1 回分類されている', function (): void {
    $result = billingRetentionClassify(billingRetentionPopulation(), billingRetentionDeclarations());

    expect($result['unclassified'])->toBe([],
        '保持期間の分類が無い課金モデルを検出しました。BillingRetentionTarget (消す) か '
        .'BillingRetentionExclusion (消さない) のどちらかへ根拠付きで登録してください。'
        .PHP_EOL.implode(PHP_EOL, $result['unclassified']));

    expect($result['duplicated'])->toBe([], '同一モデルが 2 か所で分類されています: '
        .implode(', ', $result['duplicated']));

    expect($result['phantom'])->toBe([],
        '母集団に実在しないモデルが目録に登録されています (削除されたモデルの残置): '
        .implode(', ', $result['phantom']));
});

test('検査 2: 全 case の rationale が 30 文字以上である', function (): void {
    $short = [];
    foreach (BillingRetentionTarget::cases() as $case) {
        if (mb_strlen($case->rationale()) < 30) {
            $short[] = 'target:'.$case->value;
        }
    }
    foreach (BillingRetentionExclusion::cases() as $case) {
        if (mb_strlen($case->rationale()) < 30) {
            $short[] = 'exclusion:'.$case->value;
        }
    }

    expect($short)->toBe([], '根拠が 30 文字未満の case: '.implode(', ', $short));
});
```

### app/Enums/Billing/BillingRetentionTarget.php (抜粋)

```php

/**
 * 保持期間 (7 年) の purge 対象となる課金記録の目録。
 *
 * 「消さない」と決めたものは {@see BillingRetentionExclusion} へ根拠付きで登録する。
 * 母集団 (app/Models/Billing 配下 + Cashier の SubscriptionItem) がどちらかに
 * ちょうど 1 回現れることは `BillingRetentionTargetInventoryTest` が deny-by-default で
 * 機械強制する (テストクラスへの参照は app → tests の import を生むため書かない)。
 *
 * ★**目録は人間の申告である**。網羅性は機械では保証できない — 課金取引の記録が
 *   app/Models/Billing の外や Eloquent を経由しない表に置かれれば、目録も gate も沈黙する。
 */
enum BillingRetentionTarget: string
{
    case StripeWebhookEvent = 'stripe_webhook_event';
    case BillingCheckoutSession = 'billing_checkout_session';
    case TicketCheckoutSession = 'ticket_checkout_session';
    case TicketAutoRechargeAttempt = 'ticket_auto_recharge_attempt';
    case SubscriptionItem = 'subscription_item';
    case Subscription = 'subscription';
    case TicketLedgerEntry = 'ticket_ledger_entry';

    /**
     * 対象モデル (母集団との突合キー)。
     *
     * @return class-string<Model>
     */
    public function modelClass(): string
    {
        return match ($this) {
            self::StripeWebhookEvent => StripeWebhookEvent::class,
            self::BillingCheckoutSession => BillingCheckoutSession::class,
            self::TicketCheckoutSession => TicketCheckoutSession::class,
            self::TicketAutoRechargeAttempt => TicketAutoRechargeAttempt::class,
            self::SubscriptionItem => SubscriptionItem::class,
            self::Subscription => Subscription::class,
            self::TicketLedgerEntry => TicketLedgerEntry::class,
        };
    }

    /** 対象テーブル (起算点の修飾名を解決する基準)。 */
    public function table(): string
    {
        $model = $this->modelClass();

        return (new $model)->getTable();
    }

    /**
     * 正規の保持起算点 (clock start)。
     *
```

### Laravel 13 の Schema API (vendor 実物)

```php
    /**
     * Get the tables that belong to the connection.
     *
     * @param  string|string[]|null  $schema
     * @return list<array{name: string, schema: string|null, schema_qualified_name: string, size: int|null, comment: string|null, collation: string|null, engine: string|null}>
     */
    public function getTables($schema = null)
    {
        return $this->connection->getPostProcessor()->processTables(
            $this->connection->selectFromWriteConnection($this->grammar->compileTables($schema))
        );
    }

    /**
     * Get the names of the tables that belong to the connection.
     *
     * @param  string|string[]|null  $schema
     * @param  bool  $schemaQualified
     * @return list<string>
     */
    public function getTableListing($schema = null, $schemaQualified = true)
    {
        return array_column(
            $this->getTables($schema),
            $schemaQualified ? 'schema_qualified_name' : 'name'
        );
    }
```

`Schema::getForeignKeys()` の pgsql 側の戻り値 shape (PostgresProcessor::processForeignKeys):

```php
    public function processForeignKeys($results)
    {
        return array_map(function ($result) {
            $result = (object) $result;

            return [
                'name' => $result->name,
                'columns' => explode(',', $result->columns),
                'foreign_schema' => $result->foreign_schema,
                'foreign_table' => $result->foreign_table,
                'foreign_columns' => explode(',', $result->foreign_columns),
                'on_update' => match (strtolower($result->on_update)) {
                    'a' => 'no action',
                    'r' => 'restrict',
                    'c' => 'cascade',
                    'n' => 'set null',
                    'd' => 'set default',
                    default => null,
                },
                'on_delete' => match (strtolower($result->on_delete)) {
                    'a' => 'no action',
                    'r' => 'restrict',
                    'c' => 'cascade',
                    'n' => 'set null',
                    'd' => 'set default',
                    default => null,
                },
            ];
```

### 実スキーマの表一覧 (migration が作るもの。62 表 + Laravel の migrations 表)

```
admin_users analysis_jobs api_keys billing_checkout_sessions billing_notifications blind_indexes cache cache_locks categories custom_teams cuts email_suppressions failed_jobs idempotency_keys inquiries items job_batches jobs llm_call_logs mcp_idempotency_keys model_audits notifications oauth_access_tokens oauth_auth_codes oauth_clients oauth_device_codes oauth_refresh_tokens oauth_sessions organization_invitations organization_quotas organization_user organizations passkeys password_reset_tokens permission_role permission_user permissions plan_prices plans project_members projects render_jobs role_user roles security_audit_events sessions social_accounts source_documents stripe_webhook_events subscription_items subscriptions take_upload_reservations takes teams ticket_auto_recharge_attempts ticket_auto_recharges ticket_checkout_sessions ticket_ledger_entries ticket_reservations ticket_volume_prices users video_manuals 
```
