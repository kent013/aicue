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
     *
     * Undecided を true 側に置くのは「期限が要ると決まった」からではなく、
     * **期限の連鎖に入りうるので保守的に horizon 側へ寄せる**という判断である
     * (未確定の表を親に持つ基準データは、期限が決まった瞬間に壊れる)。
     *
     * ★**削除期限が実在することを保証する述語ではない**。RC-7 が「基準データ / 基盤の表が
     *   親に持ってはいけない側」を選ぶためだけに使う分類上の述語である。
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
    /**
     * 宣言の並び (**表名をキーにした連想配列にしない**)。
     *
     * 連想配列にすると同じ表を 2 回書いても後の 1 件で上書きされ、**二重宣言が消えてしまう**。
     * 並びのまま返し、キー化と二重宣言の検出は gate 側の純関数が行う。
     *
     * @return list<RetentionTableEntry>
     */
    public static function entries(): array
    {
        return [
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
    }
}
```

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
| `users` | 定期実行が消す | `App\Support\Account\AccountDeletionGrace` / `account:purge-deletion-requests`。根拠に「**退会予約が入った行だけ**が猶予後に物理削除される。予約の無い行に期限は無い = **表の中で行ごとに寿命が違う**」と書く |
| `custom_teams` `projects` `organization_user` `project_members` `organization_invitations` `role_user` `permission_user` `social_accounts` `passkeys` `api_keys` `notifications` `items` `categories` `video_manuals` `source_documents` `cuts` `takes` `analysis_jobs` `render_jobs` `ticket_reservations` `ticket_auto_recharges` `organization_quotas` `billing_notifications` | 親と一緒に消える | 親 (organizations / projects / users / video_manuals …) の cascade |
| `blind_indexes` | 親と一緒に消える | 外部キーを持たないが、`Spatie\LaravelCipherSweet\Observers\ModelObserver` が親モデルの Eloquent 削除に合わせて索引行を消す (OR 条件の (b) = 削除責務クラスの宣言) |
| `plans` `plan_prices` `ticket_volume_prices` `roles` `permissions` `permission_role` | 基準データ | 運用者が入れ替えるまで残る。個人に紐づかない |
| `migrations` `cache` `cache_locks` `jobs` `job_batches` `failed_jobs` `sessions` `password_reset_tokens` | 基盤が寿命を持つ | フレームワーク / キュー / セッションの実装が寿命を決める |
| `organizations` `teams` `llm_call_logs` `security_audit_events` `model_audits` `email_suppressions` `admin_users` `oauth_access_tokens` `oauth_refresh_tokens` `oauth_auth_codes` `oauth_device_codes` `oauth_clients` `oauth_sessions` | **未確定** | 下記 |

**未確定に置く理由 (根拠欄に書く内容)**:

- `llm_call_logs` / `security_audit_events`: 組織・利用者への外部キーが `null on delete` なので、
  退会・組織削除の後も**行は残る**。費用分析・監査の必要期間が決まっていない。
- `model_audits`: 監査証跡。外部キーを持たない (`morphs`) ので親の削除に連動しない。保持期間が未決。
- `email_suppressions`: メールアドレスそのものを保持する。送達抑制の必要期間が未決。
- `admin_users`: 運用者アカウント。退任時の扱いが手順として決まっていない。
- `organizations` / `teams`: **実装時の実測で分類を変えた** (下記「実装時に確定した差分」)。
- `oauth_*`: **本リポジトリの保持期限の責任者が決まっていない**。失効・期限切れのトークンを
  誰がいつ消すかの決着 (掃除の配線を含む) が未決である。
  **gate は Schedule への登録有無を見ない**ので、ここを「定期実行が消す」と分類すると
  根拠と検査がずれる (コマンドが実在しさえすれば RC-5 は通ってしまう)。よって未確定に置く。

> **未確定は「まだ何もしていない」の可視化であって、放置の許可ではない**。
> 件数と表名を RC-8 が現在値ちょうどで pin するため、増えるときも減るときも
> テストの変更として必ずレビューに出る。

### 実装時に確定した差分 (実スキーマと app/ の実測で設計案を直した箇所)

実スキーマは **63 表**で、初期分類の想定と一致した。ただし次の 3 点は実測の結果が
設計案と食い違ったため、**実測に合わせて設計を直した**。

1. **`organizations` を「親と一緒に消える」→「未確定」へ**。`organizations` の外部キーは
   `teams` への `restrict` と `users` への `set null` だけで **cascade を 1 本も持たず**、
   `app/` 配下に organization の行を削除する経路も 1 つも無い (実測)。つまり
   「親が消えれば一緒に消える」も「定期実行が消す」も成り立たない。RC-6 を通すために
   実在しない削除責務クラスを宣言するのは**嘘の根拠**になるため、未確定に載せる。
2. **`teams` を「親と一緒に消える」→「未確定」へ**。`teams` は外部キーを 1 本も持たず、
   行を削除する経路も `app/` に無い。`organizations` の決着が付くまでこの表の期限も決まらない。
3. **`blind_indexes` を「未確定」→「親と一緒に消える (削除責務クラスの宣言)」へ**。
   設計時は「親行の削除に連動するかを確認していない」としていたが、
   `vendor/spatie/laravel-ciphersweet` の `Observers\ModelObserver::deleting()` が
   `deleteBlindIndexes()` を呼ぶことを実読で確認した (連動は DB ではなくアプリ側にある)。
   よって RC-6 の通り道 (b) で通す。
   **注意**: 連動が効くのは Eloquent の削除経路だけで、DB の連鎖削除で親モデルの行が
   消えた場合は観測者を通らない。現時点で暗号化列を持つモデル (`users` /
   `organizations` / `organization_invitations` / `inquiries` / `admin_users`) を
   連鎖削除で消す経路は稼働していないため実害は無いが、**この非対称は根拠に書く**。

**区分ごとの件数 (実装後の確定値)**: 課金取引の記録 7 / 定期実行が消す 5 /
親と一緒に消える 24 / 基準データ 6 / 基盤が寿命を持つ 8 / 未確定 13 = **63**。
(件数の正本は台帳と gate の pin であり、本書の数字は実装時点の記録である。)

### 検査の追加 (設計の骨格に対する上積み)

- **RC-7 は「参照先の表が台帳に無い」場合も違反へ倒す** (fail-closed)。区分が決まらない
  参照を黙って通さないため。負のコントロール NC-6 が点灯を固定する。
- **RC-3 の二重宣言に負のコントロールを付けた** (NC-2b)。純関数
  `retentionClassify()` が同じ表の 2 回目の宣言を検出することを合成入力で確かめる。

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
| RC-5 | 宣言された保持者が実在する (`class_exists()` / `Artisan::all()` のキーに存在) | クラス改名・コマンド廃止に追随していない |
| RC-6 | 「親と一緒に消える」の裏取り: **`on delete cascade` の FK を 1 本以上持つ**、**または**削除責務クラスを宣言している | 孤立した表を「親に連動する」と言い張った |
| RC-7 | 「基準データ」「基盤が寿命を持つ」が、期限が要る区分の表を**矛盾する削除動作で**参照していない | 期限の連鎖の中にある表を「期限を持たない」と分類した |
| RC-8 | 空振り検知: 総件数と**未確定の表名一覧**を現在値ちょうどで pin (**区分ごとの件数は pin しない**。下の理由を参照) | 台帳が空になった / 未確定が無音で増えた |
| NC-1〜4 | 負のコントロール (合成入力で RC-1 / RC-2 / RC-6 / RC-7 を 1 つずつ点灯させる) | 検査が形だけになった |

> RC-6 の条件は **OR** である。DB の cascade で連動する表は保持者を書かなくてよく、
> 連動がアプリ側にある表 (`teams` / vendor 由来の表など) は削除責務クラスを宣言して通す。
> **どちらも無い表は「親と一緒に消える」と宣言できない** (この 2 つが唯一の通り道である)。

### 実装の骨格

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
 * ★この gate が保証するもの:
 *   - RC-1 / RC-2: 実スキーマの表一覧と台帳が**両方向で集合等価**である
 *   - RC-4: 課金 7 年の表集合が App\Enums\Billing\BillingRetentionTarget と一致する
 *   - RC-5: 宣言された保持者 (クラス / コマンド) が**識別先として実在する**
 *   - RC-6 / RC-7: 区分と実スキーマの外部キーの構造が矛盾していない
 *   - RC-8: 台帳の総件数と未確定の表名が**現在値ちょうど**である (無音で増えない)
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

/**
 * スキーマ照会の入口。
 *
 * **ファサードではなく具体の Builder を取る** — `Schema::` の docblock は
 * `array getTables(...)` としか書いておらず、level 10 では要素が mixed になる。
 * `Connection::getSchemaBuilder()` は `Illuminate\Database\Schema\Builder` を返し、
 * 実体側の `@return list<array{name: string, schema: string|null, …}>` がそのまま効く
 * (**型を緩めて黙らせない** — 禁止事項 2)。
 */
function retentionSchemaBuilder(): Builder
{
    return DB::connection()->getSchemaBuilder();
}

/** 現在のスキーマの表名 (非修飾・sort 済み)。pgsql は引数なしだと全スキーマを返すため必ず絞る。 */
function retentionSchemaTableNames(): array
{
    $builder = retentionSchemaBuilder();
    $names = array_map(
        static fn (array $table): string => $table['name'],
        $builder->getTables($builder->getCurrentSchemaName()),
    );
    sort($names);

    return $names;
}

/**
 * 全表の外部キーを 1 度だけ読み、表名 => 参照先と on delete の一覧にする。
 *
 * **スキーマ修飾名で問い合わせる** (`getForeignKeys()` は `schema.table` を受け取り
 * `parseSchemaAndTable()` で分解する)。表一覧を現在のスキーマに絞っておきながら
 * 外部キーの照会だけ `search_path` 任せにすると、同名表があるときに食い違う。
 *
 * @return array<string, list<array{foreign_table: string, columns: list<string>, on_delete: string|null}>>
 */
function retentionForeignKeyMap(): array
{
    $builder = retentionSchemaBuilder();
    $schema = $builder->getCurrentSchemaName();

    $map = [];
    foreach (retentionSchemaTableNames() as $table) {
        $map[$table] = array_map(
            static fn (array $fk): array => [
                'foreign_table' => $fk['foreign_table'],
                'columns' => $fk['columns'],
                'on_delete' => $fk['on_delete'],
            ],
            $builder->getForeignKeys($schema.'.'.$table),
        );
    }

    return $map;
}

/**
 * 指定した表の列が nullable かどうか。
 *
 * RC-7 が `on delete set null` を非違反にしてよいかの判定にだけ使う
 * (`NOT NULL` の列が混ざっていると親の削除が制約違反で失敗するため)。
 * **対象は「基準データ」「基盤が寿命を持つ」に分類した表だけ**に絞って引く
 * (全表を引く必要が無い)。
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
    // …
}

/**
 * RC-6 の判定 (**純関数**。外部キーの一覧を引数で受け取るので合成入力で点灯させられる)。
 *
 * @param  list<RetentionTableEntry>  $entries
 * @param  array<string, list<array{foreign_table: string, on_delete: string|null}>>  $foreignKeys
 * @return list<string> 違反した表名
 */
function retentionDeletedWithParentViolations(array $entries, array $foreignKeys): array
{
    // …
}

/**
 * RC-7 の判定 (**純関数**)。期限を持たない区分の表が、期限が要る区分の表を
 * **矛盾する `on delete` で**参照していないか。
 *
 * ★**外部キーの存在だけでは違反にしない**。親が消えたときに子がどうなるかで意味が変わる:
 *   - `cascade` = 子も消える → 「期限を持たない」と矛盾する (違反)
 *   - `restrict` / `no action` = 削除対象の親行が子から参照されていれば**親の削除を拒否する**
 *     → 親の期限の執行を止めうる (違反)
 *   - `set null` = 子の外部キー列を空にして子は残る → 子自身は期限の連鎖の外にある。
 *     **ただし外部キーの列がすべて nullable なときに限る** — `NOT NULL` の列が混ざっていると
 *     親の削除は制約違反で失敗するので、実際には `restrict` と同じ結果になる (違反)
 *   - `set default` = 既定値への置換を試みるが、その値が外部キー制約を満たさなければ
 *     親の削除は失敗する。本リポジトリに 1 本も無いため、**現れたら分類の見直しが要る**ものとして
 *     保守的に違反へ倒す
 *   - `null` (取得できない) = 未知 → 保守的に違反へ倒す
 *
 * ★**足りない情報はすべて違反へ倒す (fail-closed)**:
 *   - 外部キーの列が空 (`columns === []`) → 違反。空集合に対して「全部 nullable」と
 *     答えてしまう空虚な真を作らない
 *   - 列の nullable が一覧に無い → 違反 (`?? false` で倒す)
 *   見るのは **Laravel の Schema API が返す外部キーの列すべて**である。
 *   複合外部キーの一部だけを対象にする書き方があっても区別しないので、判定は
 *   必要条件より厳しくなりうる (見落としではなく、余分に赤くなる側へ倒れる)。
 *
 * @param  list<RetentionTableEntry>  $entries
 * @param  array<string, list<array{foreign_table: string, columns: list<string>, on_delete: string|null}>>  $foreignKeys
 * @param  array<string, array<string, bool>>  $nullableColumns  表名 => 列名 => nullable か
 * @return list<string> `{表名} -> {親表名} (on delete …)` の形の違反一覧
 */
function retentionHorizonParentViolations(array $entries, array $foreignKeys, array $nullableColumns): array
{
    // 子が残らない / 親を消せなくする / 意味が確定しない、を矛盾とみなす。
    // `set null` だけは列の nullable を見てから決める。
    $conflicting = ['cascade', 'restrict', 'no action', 'set default', null];
    // `set null` を非違反にできるのは次のすべてが成り立つときだけ:
    //   $fk['columns'] !== []
    //   かつ すべての列が $nullableColumns[$table] に存在する
    //   かつ すべての列が true
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
/**
 * RC-6 の中身 (上の純関数の本体)。
 *
 * 通り道は **2 つだけ**である:
 *   (a) `on delete cascade` の外部キーを 1 本以上持つ (DB が連動を保証する)
 *   (b) 削除責務を持つクラスを宣言している (連動がアプリ側にある。vendor 由来の表など)
 * どちらも無ければ、その表は親が消えても残るので「親と一緒に消える」とは言えない。
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
            static fn (array $fk): bool => $fk['on_delete'] === 'cascade',
        );
        if ($cascades === []) {
            $violations[] = $entry->table;
        }
    }

    return $violations;
}

test('RC-6: 「親と一緒に消える」表は cascade の外部キーを持つか削除責務クラスを宣言している', function (): void {
    $violations = retentionDeletedWithParentViolations(
        RetentionTableRegistry::entries(),
        retentionForeignKeyMap(),
    );

    expect($violations)->toBe([],
        'cascade の外部キーも削除責務クラスの宣言も無い表を「親と一緒に消える」と分類しています '
        .'(親が消えてもこの行は残ります): '.implode(', ', $violations));
});

test('RC-7: 期限を持たない区分の表が、期限が要る区分の表を矛盾する削除動作で参照していない', function (): void {
    $entries = RetentionTableRegistry::entries();
    // nullable の照会は「基準データ」「基盤が寿命を持つ」の表だけに絞る
    $noHorizonTables = array_values(array_map(
        static fn (RetentionTableEntry $entry): string => $entry->table,
        array_filter($entries, static fn (RetentionTableEntry $entry): bool => ! $entry->class->hasHorizon()),
    ));

    $violations = retentionHorizonParentViolations(
        $entries,
        retentionForeignKeyMap(),
        retentionNullableColumnMap($noHorizonTables),
    );

    expect($violations)->toBe([],
        '期限が要る表を「期限を持たない」区分の表が参照しており、親が消えたときの挙動と '
        .'分類が矛盾しています: '.implode(', ', $violations));
});
```

> **RC-7 が `FrameworkManaged` も対象に含める理由**: 基盤の表がアプリの寿命を持つ表を親に
> 持っているなら、それは「フレームワークが寿命を決めている」とは言えない (アプリ側の削除で
> 一緒に消える表であり、`DeletedWithParent` である)。区分の定義そのものの検査になるので、
> `ReferenceData` と同じ 1 本で扱う。
>
> **`on delete set null` を (列がすべて nullable なら) 違反にしない理由**:
> 親が消えても子の行は残る (外部キー列が空になるだけ) ので、子自身は期限の連鎖の外にある。
> 実際 `llm_call_logs` / `security_audit_events` は組織・利用者への外部キーを
> `nullOnDelete()` で持っており、**退会・組織削除の後も行が残る**。
> ここを一律違反にすると、残る表を「親と一緒に消える」と偽って分類させることになり、
> 検査が事実と逆の方向へ働く。
>
> **ただし nullable であることは前提ではなく検査する**。`ON DELETE SET NULL` が宣言されていても
> 外部キーの列に `NOT NULL` が付いていれば、親の削除は制約違反で失敗する
> (= 結果は `restrict` と同じで、親の期限の執行を止める)。複合外部キーもあるので
> **列を 1 つずつ**見て、1 つでも `NOT NULL` があれば違反にする。

RC-8 (空振り検知):

```php
/** 台帳の総件数 (cap ではなく exact-fit。増減したら必ずこの数字を書き換える)。 */
const RETENTION_TABLE_COUNT = 63; // 実装時に実スキーマから確定する

/**
 * 保持期限が**まだ決まっていない**表 (現在値ちょうど。増えるときも減るときもここを書き換える)。
 *
 * ここに 1 行足す / 消す操作は必ずテストの差分として現れる = レビューで見える。
 */
const RETENTION_UNDECIDED_TABLES = [ /* 実装時に確定 */ ];
```

> **区分ごとの件数は pin しない**。全体件数と未確定の表名一覧があれば、
> 「台帳が空になった」「未確定が無音で増えた」の 2 つは捕まる。
> 区分ごとの件数まで pin すると、分類を 1 つ直すたびに数字の書き換えが要るだけで、
> 新しく捕まえられるものが無い (思考原則 2)。

RC-5 (保持者の実在):

```php
test('RC-5: 宣言された期限の保持者が実在する', function (): void {
    // コマンドは **一覧のキーに存在するか**だけを見る (実行しない)。
    // Artisan::all() は command discovery を 1 度走らせるが、Feature lane では
    // 他のテストと同じ boot 済みアプリを使うため追加の初期化は起きない。
    $registered = Artisan::all();

    $missing = [];
    foreach (RetentionTableRegistry::entries() as $entry) {
        if ($entry->ownerClass !== null && ! class_exists($entry->ownerClass)) {
            $missing[] = $entry->table.' => class '.$entry->ownerClass;
        }
        if ($entry->ownerCommand !== null && ! array_key_exists($entry->ownerCommand, $registered)) {
            $missing[] = $entry->table.' => command '.$entry->ownerCommand;
        }
    }

    expect($missing)->toBe([],
        '台帳が名指しした期限の保持者が実在しません (改名・廃止に追随していません): '
        .implode(', ', $missing));
});
```

### 波及変更

- TypeScript型定義 / API Resource / DTO: なし
- テストファイル: 施策 4 の docblock 追記のみ

### PHPStan適合チェック

- [x] 純関数の引数・戻り値に配列 shape の phpdoc を付ける
      (`array{unclassified: list<string>, …}` / `array<string, list<array{foreign_table: string, on_delete: string|null}>>`)
- [x] スキーマ照会は `DB::connection()->getSchemaBuilder()` で**具体の Builder** を取り、
      実体側の `@return list<array{...}>` を効かせる (ファサードの `array` では要素が mixed になる)
- [x] `on_delete` は `string|null` である (framework の shape どおり。`=== 'cascade'` で比較する)
- [x] `getColumns()` の `nullable` は `bool` である (framework の shape どおり)
- [x] `class-string` を `class_exists()` へ渡す (RC-5)
- [x] `match` は全 case 網羅

### テスト計画

- [x] 新規テストそのものが本施策の成果物である
- [ ] **負のコントロール 4 本**: 合成入力で RC-1 / RC-2 / RC-6 / RC-7 を 1 つずつ点灯させる。
      RC-6 / RC-7 の判定は**外部キーの一覧を引数で受け取る純関数**に分けてあるので、
      DB を触らずに合成した外部キーの一覧を渡して点灯させられる:
      - NC-1: 台帳に無い表名を表一覧へ足すと RC-1 が点灯する
      - NC-2: 実在しない表名を台帳へ足すと RC-2 が点灯する
      - NC-3: `on delete` が cascade でない外部キーしか持たない表を「親と一緒に消える」と宣言し、
              削除責務クラスを書かないと RC-6 が点灯する
      - NC-4: 「基準データ」の表が「定期実行が消す」表を **`cascade`** で参照すると RC-7 が点灯する
              (`restrict` でも点灯することを同じテストで確かめる)
- [ ] **正のコントロール (RC-7 の境界を固定する)**: 同じ参照を **`set null` + nullable 列**に
      すると RC-7 が点灯しない。これが無いと「外部キーがあれば全部赤」に退化しても気付けない
- [ ] **NC-5**: `set null` でも外部キーの列に `NOT NULL` が混ざっていると RC-7 が点灯する
      (nullable の判定が効いていることの固定。複合外部キーの合成入力で確かめる)
- [ ] 正の自己検証: RC-6 は「cascade を持つ表が 1 件以上実在する」ことも確認する
      (全件が `ownerClass` 宣言で素通りしていたら検査が形だけになる)
- [ ] 個別の `DatabaseTransactions` を使わない (グローバル `RefreshDatabase` に従う)
- [ ] 実行時間: 外部キーの一覧は `retentionForeignKeyMap()` が**1 度だけ**組み立て、
      RC-6 / RC-7 で使い回す (63 表 × 1 クエリが上限)。
      列の nullable は「基準データ」「基盤が寿命を持つ」の表だけに絞って引く (十数表)

### リスク

- **`--parallel` で並列 DB を使うため、全レーンで同じスキーマが見えることが前提**である。
  worktree ごとに DB 名が変わるが、migration は同一なので表一覧は一致する。
- 将来 vendor package が migration を publish して表が増えると、その PR が赤くなる。
  これは意図した挙動である (分類を書く場所へ誘導する失敗メッセージを出す)。
- `Artisan::all()` は command discovery を走らせる。Feature lane では他のテストと同じ
  boot 済みアプリを使うため追加の初期化は起きないが、**コマンドは実行しない**ことを
  コメントで固定する (一覧のキーを見るだけである)。

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
 *     本 gate は年数・起算点列・purger の配線・実行順を持ち、あちらは**それらを 1 つも写さない**。
 *     対象表の名前だけは両方に現れるが、それはあちらの RC-4 が本 enum と両方向で結線して
 *     管理する (片側だけ増減したら赤くなる)。
 *     **年数・起算点・purger を 2 か所に書かないこと** (どちらかに検査を足す前に、この境界を読み直す)。
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
- **保証するもの 4 つ / 保証しないもの** (gate の docblock と同じ内容)。とくに次の 3 点は
  分類の見え方と検査の内容がずれやすいので名指しで書く:
  1. **行ごとの寿命の違いは表現しない** (`users` は退会予約が入った行だけが消えるが、
     表としては 1 つの区分に丸められる)
  2. **Schedule への配線は見ない** (コマンドが実在しさえすれば RC-5 は通る。
     `oauth_*` を未確定に置いているのはこのためである)
  3. **保持者として宣言したクラス / コマンドが実際にその表を消すことは検査していない**
     (見ているのは識別先の実在だけである)
- RC-7 は「期限が要る表への外部キーを一律禁止」ではない。
  **親が消えたときに子がどうなるかで判断する**ことを `on delete` の動作別に列挙する
  (`cascade` / `restrict` / `no action` / `set default` / 取得できない = 矛盾、
  **列がすべて nullable な `set null`** = 矛盾ではない)。
  `llm_call_logs` / `security_audit_events` が `set null` で組織・利用者を参照し、
  **退会後も行が残る**実例をそのまま書く。
  併せて「`set null` でも列に `NOT NULL` が混ざれば親の削除は失敗するので違反にする」ことと、
  **足りない情報 (列の一覧が空 / nullable が取れない) はすべて違反へ倒す**ことも書く
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
    - **保証範囲を誇張しない**: 見るのは表単位であり列は見ない。行ごとの寿命の違いも
      表現しない。分類の意味が正しいかは人間のレビュー対象で、実データが消えることは
      各掃除バッチの behavioral テストが担う。**外部キーをどう読むか
      (`on delete` の動作別の扱い) は規約本文に書かず**、正本の
      `docs/architecture.md` §表ごとの保持期限の分類 へ委譲する
      (規約本文に条件を写すと必ず食い違う)
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
