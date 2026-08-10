## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

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


# あなたの役割

Laravel 12 + Svelte 5 + Inertia.js のコードレビュアーとして、**TODO T142 (PR-B: 退会の猶予期間つき削除 / 凍結方式・30 日)** の実装差分をレビューする。

## レビュー観点

1. **設計との一致性** — 下に添付した詳細設計の **PR-B の節だけ**が実装対象である (PR-A / PR-C1 は既に main にマージ済み、PR-C2 / C3 は未着手)。PR-B の各施策 (B0〜B8) が実装されているか、設計から逸脱していないか。
2. **正確性** — 状態機械 (予約中 / 未予約) の破れ、TOCTOU、ロック順序 (users 昇順 → organizations 昇順)、冪等性、境界条件。
3. **セキュリティ** — 存在オラクル (テナント境界 404 が凍結 302 より前か)、deny-by-default の破れ、猶予の迂回口、救済経路の詰み (行き先のない遮断)、PII のログ出力。
4. **PHPStan level 10 適合性** — 型の widen / `@phpstan-ignore` / baseline は禁止。
5. **DTO / JsonResource パターン** — `response()->json()` 直書き禁止 (仕様固定 endpoint のみ例外)。
6. **テスト網羅性** — 各施策にテストがあるか。gate が **vacuous green** になっていないか (空振り検知・負のコントロール・正の自己検証の同梱)。
7. **DESIGN.md 準拠** — `/DESIGN.md` が design token の canonical source。color / radius / typography は token 経由で参照し hex 直書き (`#RRGGBB`) を増やさない。token 値を変更する diff は `resources/css/tokens.css` と同一 diff 内で同期しているか。
8. **Atomic Design 準拠** — `resources/js/components/` は `atoms/molecules/organisms/features/templates` の責務分離に従う。階層を逆流していないか。アイコンは Lucide を使い SVG 直書きを増やさない。

## 特に厳しく見てほしい点 (実装者からの申し送り)

- **凍結 (`EnsureAccountNotPendingDeletion`) の allowlist に穴が無いか**。通してはいけない route が通っていないか / 通すべき route が漏れて**詰み**を作っていないか。母集団は `routes/web.php` の `auth` + `verified` group 全体である。
- **実行位置**: `bootstrap/app.php` の priority list で、凍結 (302 短絡) がテナント境界 404 より後にあるか。
- **`AccountDeletionStateDto` の pending 判定**と DB の CHECK 制約の定義が一致しているか。
- **日次バッチの終了コード 2 分類** (業務上の保留 = SUCCESS / 想定外 = FAILURE) が、障害を静かに握り潰す形になっていないか。
- **通知の保証範囲の主張が誇張されていないか** (「予約操作からの job 生成は最大 1 件」までしか主張していないか)。
- **gate の主張と実装の乖離**。mutation 実測記録 (添付) に「設計の予測と実測がずれた」ものを 3 件記録している。この記録が**辻褄合わせになっていないか**、記録した限界の書き方が誇張・過小になっていないかを見てほしい。

## 出力形式

- **ファイルごとに判定**を書く。
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する。
  - [Critical]: 不変条件の破れ・セキュリティ欠陥・データ破壊・詰みを生む欠陥
  - [Warning]: 設計逸脱・テストの空振り・誇張された主張
  - [Suggestion]: 改善提案
- 最後に **全体判定: APPROVED / CHANGES_REQUESTED** を明記する。

---

## 詳細設計書 (PR-B の節 + 共通規約。**これ以外の節は実装対象外**)

# PR-B: 猶予期間つき削除 (凍結方式)

## B0. 猶予日数の単一出典

### 変更箇所
- 新規: `config/account.php`
- 新規: `app/Support/Account/AccountDeletionGrace.php`
- 新規: `tests/Architecture/AccountDeletionGraceConfigTest.php`

### 変更後コード

```php
// config/account.php
/*
| 退会 (アカウント削除) の猶予日数。**env を使わない** — 環境ごとに変えてよい運用値ではなく、
| オーナーが確定したプロダクト判断である (config/idempotency.php の retention_hours /
| config/legal.php の billing_retention_years と同じ理由)。
| 唯一の解決点は App\Support\Account\AccountDeletionGrace。Service は config を直読しない。
*/
return [
    'deletion_grace_days' => 30,
];
```

```php
final class AccountDeletionGrace
{
    /** 猶予日数 (唯一の解決点)。0 以下は fail-fast。 */
    public static function days(): int
    {
        $days = config()->integer('account.deletion_grace_days');
        Assert::greaterThan($days, 0, '猶予日数は 1 以上でなければならない');

        return $days;
    }

    /**
     * 予約時刻から執行期限を導く。要件は「**暦日 30 日**」。
     *
     * **`addDaysNoOverflow` は使わない**。`NoOverflow` の意味は「上位単位 (月) を越えない」であり、
     * 日加算に適用すると月末で丸められ **30 日未満**になりうる (猶予期間の意味が壊れる)。
     * `CarbonOverflowArithmeticGateTest` の禁止語彙は月・年・四半期だけで
     * (`addmonth(s)` / `addyear(s)` / `addquarter(s)` 等)、**日は母集団に入っていない**
     * (gate の定数を実読して確認)。AGENTS.md の実装規約も対象を月/年/四半期と書いている。
     */
    public static function purgeAfter(CarbonImmutable $requestedAt): CarbonImmutable
    {
        return $requestedAt->addDays(self::days());
    }
}
```

### 波及変更
- TypeScript 型定義: なし (猶予日数は `AccountDeletionStateDto::graceDays()` の導出値として渡る)
- API Resource/DTO: なし
- テストファイル: `AccountDeletionGraceConfigTest`

### PHPStan 適合チェック
- [x] 戻り値の型が明示 (`int` / `CarbonImmutable`)
- [x] `config()->integer()` を使い `mixed` を持ち込まない
- [x] `Assert::greaterThan` で fail-fast

### テスト計画
- [ ] 新規: 値が **30** であること
- [ ] 新規: 0 以下なら例外 (fail-fast)
- [ ] 新規: `config('account.deletion_grace_days')` を読んでよいのは
      `AccountDeletionGrace` **1 箇所だけ** (token 走査 + exact-fit caller inventory。
      `LegalConsentVersionSingleSourceTest` と同じ書式)
- [ ] 新規 (**behavioral**): `2026-01-31` の 30 日後が `2026-03-02` (月末で丸められない)
- [ ] 新規 (**behavioral**): うるう年の 2 月をまたぐ 30 日後が暦日どおりになる
- [ ] 新規: アプリのタイムゾーン設定下で期待するローカル時刻になる (要件は暦日 30 日)

### リスク
- なし (新規 config + 純関数)。

## B1. 予約列

### 変更箇所
- 新規 migration: `users.deletion_requested_at` / `users.deletion_purge_after` (ともに nullable timestamp)
- 変更: `app/Models/User.php` の `casts()`

### 波及変更
- TypeScript 型定義: B7 で `resources/js/types/account.ts` に `AccountDeletionState` を追加
- API Resource/DTO: B2 で `AccountDeletionStateDto` を新設
- テストファイル: B2 以降で使用。`UserFactory` に **state を 1 つ追加**
  (`pendingDeletion()`。テストデータは必ず Factory で作る規約)

### 変更後コード

```php
// database/migrations/2026_08_09_000100_add_deletion_request_columns_to_users_table.php
/**
 * 猶予期間つき退会 (凍結方式) の予約列。
 *
 * **SoftDeletes は使わない**。users 行の生死を変えないのが凍結方式の定義で、
 * FK cascade / nullOnDelete / CipherSweet の blind index (email_index) の一意照合 /
 * passkey / OAuth セッション / 招待の email 照合がすべて users 行の実在を前提にしている。
 *
 * `deletion_purge_after` は **絶対時刻**で持つ (猶予日数のスナップショットにしない)。
 * 不可逆な物理削除のため config 変更を既予約へ遡及させてはならず、絶対時刻なら
 * 1 列でそれが表現でき、バッチのクエリも `where deletion_purge_after <= now()` の 1 条件で済む。
 * 猶予日数は `purge_after - requested_at` で導出する (2 つの表現を持たない)。
 */
public function up(): void
{
    Schema::table('users', function (Blueprint $table): void {
        $table->timestamp('deletion_requested_at')->nullable()->after('remember_token');
        $table->timestamp('deletion_purge_after')->nullable()->after('deletion_requested_at');
        // 日次バッチの走査条件。部分 index (NULL を含めない) で通常ユーザーの行を index に載せない
        $table->index('deletion_purge_after');
    });

    // **状態機械を DB で閉じる**。片列だけの非正規状態になると isPending() が false になり、
    // 凍結を通過し、cancelAccountDeletion() も no-op で解消できず、日次バッチが毎日 FAILURE を
    // 出し続ける (検出はできても解消できない)。アプリ層だけでなく DB 制約で防ぐ。
    DB::statement(<<<'SQL'
        ALTER TABLE users ADD CONSTRAINT users_deletion_request_pair_check CHECK (
            (deletion_requested_at IS NULL AND deletion_purge_after IS NULL)
            OR (deletion_requested_at IS NOT NULL AND deletion_purge_after IS NOT NULL)
        )
    SQL);
    // 両列 non-null だが期限が予約時刻より前、という別の非正規状態も防ぐ
    DB::statement(<<<'SQL'
        ALTER TABLE users ADD CONSTRAINT users_deletion_purge_after_order_check CHECK (
            deletion_purge_after IS NULL OR deletion_purge_after >= deletion_requested_at
        )
    SQL);
}
```

```php
// app/Models/User.php casts() へ追加
// **immutable_datetime** を使う (DTO が CarbonImmutable 前提のため。'datetime' だと
// mutable Carbon が返り、DTO の型と食い違う)。
'deletion_requested_at' => 'immutable_datetime',
'deletion_purge_after' => 'immutable_datetime',
```

- **`$fillable` には入れない** (保護キー。`forceFill` でのみ書く)。
  `MassAssignmentSafetyTest` / `ProhibitsProtectedKeys` の対象に入るため、
  **`MassAssignmentProtectedKeys` への登録が必要かを実装時に確認する**
  (`current_organization_id` / `terms_accepted_at` と同じ扱い)。

### PHPStan 適合チェック
- [x] `casts()` の戻り値型 `array<string, string>` に適合
- [x] `$user->deletion_purge_after` は `?CarbonImmutable` (cast が `immutable_datetime`)。
      `AccountDeletionStateDto::fromUser()` 側でも `CarbonImmutable::instance()` で明示変換し、
      cast 設定の変更に対して二重に守る

### テスト計画
- [ ] 新規: migration の up/down が通る (既存の migration テスト方式に従う)
- [ ] 新規: `UserFactory::pendingDeletion()` が両列を埋める
- [ ] 新規: **mass-assignment で両列を書けない** (`MassAssignmentSafetyTest` の母集団に入る)
- [ ] 新規: **片列だけの INSERT/UPDATE を DB が拒否する** (CHECK 制約。アプリ層を迂回しても守られる)
- [ ] 新規: **`deletion_purge_after < deletion_requested_at` の行を DB が拒否する**
- [ ] migration の **precondition 検査**: 制約を張る前に非正規データが 0 件であることを
      **非破壊 (SELECT のみ)** で確認する (新規列なので理論上 0 件だが、
      「制約追加 migration は既存データを壊しうる」という一般則に従い明示する)

### リスク
- 部分 index の書き方が pgsql 固有になる → まず素の index で入れ、性能問題が出てから絞る
  (思考原則 2。予約中ユーザーは常に極少数)。

---

## B2. 予約 / 取消 (Service)

### 変更箇所
- `app/Services/Organization/OrganizationMembershipService.php` に public メソッド 3 本を追加
- 新規: `app/DataTransferObjects/Account/AccountDeletionStateDto.php`

### なぜ `OrganizationMembershipService` か
責務ではなく**ロック順序**が理由である。予約列の書き込みは `lockForMembershipWrite`
(users 昇順 → organizations 昇順) と同じ順序に乗せる必要があり、順序の SoT を 2 クラスに分けると
デッドロックの余地が生まれる。`deleteAccount()` と同じクラスにあれば順序の交差が構造的に起きない。

### 変更後コード

```php
    /**
     * 退会の予約 (猶予期間つき削除)。**凍結方式**なので users 行の生死は変えない。
     *
     * 冪等: 既に予約中なら **`purge_after` を延長しない**で既存の予約をそのまま返す
     * (二重送信で猶予が伸び続けるのを防ぐ。取消 → 再予約は明示操作)。
     *
     * **予約時にブロッカーを評価しない**。予約は退会の意思表示であって削除ではなく、
     * ブロックされている人が予約すらできないと「解約待ちの間は退会予約もできない」詰みになる。
     * 権威判定は執行時 (deleteAccount のロック下再評価) が担う。
     *
     * @return AccountDeletionStateDto 予約後の状態 (通知とレスポンスが同じ値を見る)
     */
    public function requestAccountDeletion(User $user): AccountDeletionStateDto
    {
        return DB::transaction(function () use ($user): AccountDeletionStateDto {
            // canonical 共通ロック境界 (users 昇順 → organizations 昇順)。organizations は不要だが
            // 順序の起点を deleteAccount と揃える (新しいロック順序を作らない)。
            $this->lockForMembershipWrite([$this->keyOf($user)], []);

            $fresh = $user->fresh();
            Assert::isInstanceOf($fresh, User::class);

            $state = AccountDeletionStateDto::fromUser($fresh);
            if ($state->isPending()) {
                return $state; // 冪等 no-op (延長しない)
            }

            $requestedAt = CarbonImmutable::now();
            // 猶予日数の解決は AccountDeletionGrace 1 箇所だけ (B0)。Service は config を直読しない。
            $purgeAfter = AccountDeletionGrace::purgeAfter($requestedAt);
            $fresh->forceFill([
                'deletion_requested_at' => $requestedAt,
                'deletion_purge_after' => $purgeAfter,
            ])->save();

            $this->recorder->record(SecurityEventType::AccountDeletionRequested, $fresh);

            // ドメイン規約 11: 業務状態の保存とキュー投入は**同一トランザクション内**で行う
            // (afterCommit に依存しない)。通知側が送信直前に予約の生存を再確認する (B6)。
            $fresh->notify(new AccountDeletionRequestedNotification($requestedAt, $purgeAfter));
            $this->notifications->notifyAccountDeletionRequested($fresh, $purgeAfter);

            return AccountDeletionStateDto::fromUser($fresh);
        });
    }

    /**
     * 退会予約の取消。**誤操作救済の本体**であり、ブロッカーの有無に関わらず必ず成功する。
     * 冪等: 予約が無ければ no-op。
     */
    public function cancelAccountDeletion(User $user): AccountDeletionStateDto
    {
        return DB::transaction(function () use ($user): AccountDeletionStateDto {
            $this->lockForMembershipWrite([$this->keyOf($user)], []);

            $fresh = $user->fresh();
            Assert::isInstanceOf($fresh, User::class);

            if (! AccountDeletionStateDto::fromUser($fresh)->isPending()) {
                return AccountDeletionStateDto::fromUser($fresh); // 冪等 no-op
            }

            $fresh->forceFill([
                'deletion_requested_at' => null,
                'deletion_purge_after' => null,
            ])->save();

            $this->recorder->record(SecurityEventType::AccountDeletionCancelled, $fresh);

            return AccountDeletionStateDto::fromUser($fresh);
        });
    }

    /**
     * 予約の執行 (日次バッチ専用)。**期限到来をロック下で再確認してから**
     * 既存の deleteAccount() をそのまま呼ぶ。判定コードを分岐させない。
     *
     * @return bool true = 削除した / false = 期限未到来 or 予約が消えていた (抽出後の取消)
     *
     * @throws ValidationException 退会ブロッカーが立っている (呼び出し側が「業務上の保留」として捌く)
     */
    public function executeAccountDeletionRequest(User $user): bool
    {
        $executed = false;

        $this->deleteAccount($user, null, function (User $locked) use (&$executed): bool {
            // deleteAccount のロック取得後・ガード評価前に呼ばれる前提条件フック。
            $state = AccountDeletionStateDto::fromUser($locked);
            $executed = $state->isDue(CarbonImmutable::now());

            return $executed;
        });

        return $executed;
    }
```

**`deleteAccount()` の変更 (最小)**: 第 3 引数 `?\Closure $precondition = null` を足す。

```php
    /**
     * @param  (\Closure(): void)|null  $beforeDelete  例外を投げないこと (投げると削除全体が rollback)
     * @param  (\Closure(User): bool)|null  $precondition  ロック取得直後・ガード評価**前**に
     *        呼ばれる前提条件。false を返すと**ガードを評価せず**削除せずに正常終了する
     *        (バッチが「抽出後に取消された」を検出する口。null なら常に true)
     */
    public function deleteAccount(User $user, ?\Closure $beforeDelete = null, ?\Closure $precondition = null): void
```

差し込み位置は「step 3 の fresh 取得直後・`organizationsBlockingDeletion()` 呼び出しの**前**」。
false のときは**ブロッカー判定に入らず** return する (取消済みユーザーに対して
ブロッカー例外を出さない = バッチが「保留」と誤分類しない)。

### `AccountDeletionStateDto`

```php
final readonly class AccountDeletionStateDto
{
    public function __construct(
        public ?CarbonImmutable $requestedAt,
        public ?CarbonImmutable $purgeAfter,
    ) {}

    public static function fromUser(User $user): self { /* … */ }

    /** 予約中か (両列が揃っているときだけ true = 片方だけの非正規状態を pending と認めない) */
    public function isPending(): bool
    {
        return $this->requestedAt !== null && $this->purgeAfter !== null;
    }

    /** 執行期限が到来しているか (比較演算子ではなく Carbon API を使う。意図と型が明確) */
    public function isDue(CarbonImmutable $now): bool
    {
        return $this->requestedAt !== null
            && $this->purgeAfter !== null
            && $this->purgeAfter->lessThanOrEqualTo($now);
    }

    /** 猶予日数 (表示用。導出値であり列を持たない) */
    public function graceDays(): ?int { /* diffInDays */ }

    /**
     * Inertia props 形。日時は **ISO 8601 文字列** (`toIso8601String()`)。
     *
     * @return array{requestedAt: string|null, purgeAfter: string|null, graceDays: int|null}
     */
    public function toArray(): array { /* … */ }
}
```

### 波及変更
- TypeScript 型定義: `resources/js/types/account.ts` に `AccountDeletionState` を追加 (B7)
- API Resource/DTO: 本 DTO が新設分
- テストファイル: `tests/Feature/Auth/AccountDeletionGraceTest.php` (新)、
  `tests/Architecture/MembershipWriteLockInventoryTest.php` (B8)

### PHPStan 適合チェック
- [x] 戻り値の型が明示されている (`AccountDeletionStateDto` / `bool` / `void`)
- [x] null 安全 (`Assert::isInstanceOf($fresh, User::class)` で `fresh()` の `?User` を narrowing)
- [x] DTO を返している (配列返却なし)
- [x] Closure の型は `(\Closure(User): bool)|null` で phpdoc に明示

### テスト計画
- [ ] 新規: 予約 → 両列が入る / SecurityEvent `account_deletion_requested` が 1 件
- [ ] 新規: **二重予約で `purge_after` が延びない** (冪等)
- [ ] 新規: 取消 → 両列が null / SecurityEvent `account_deletion_cancelled`
- [ ] 新規: **ブロッカーがあっても予約できる** (予約時に評価しない契約)
- [ ] 新規: **ブロッカーがあっても取消できる**
- [ ] 新規: 執行 → 期限到来なら削除 / 未到来なら false で無変更
- [ ] 新規: **抽出後に取消 → `executeAccountDeletionRequest` が false を返し削除しない**
- [ ] 新規: TOCTOU — 予約と `deleteAccount` の並行実行でロック順序が交差しない
      (既存 `MembershipWriteLockInventoryTest` の drift-guard + 実行順テスト)

### リスク
- `deleteAccount()` のシグネチャ変更が既存呼び出し (`AccountController::destroy`) に波及する
  → 第 3 引数は**省略可能**にするので既存呼び出しは無変更。
  既存 16 本のアサーションは崩れない (禁止事項 3)。

---

## B3. 予約 / 取消 (HTTP)

### 変更箇所
- 新規: `app/Http/Controllers/Settings/AccountDeletionRequestController.php`
- 変更: `routes/web.php` (`settings.account.destroy` の直下に 2 本)

### 変更後コード

```php
// routes/web.php (auth + verified group 内、settings.account.destroy の直後)

// 退会の予約 (猶予 30 日)。**UI の主導線**。即時削除と同水準の機微操作のため step-up 必須。
Route::post('/settings/account/deletion-request', [AccountDeletionRequestController::class, 'store'])
    ->middleware('recent-auth')
    ->name('settings.account.deletion-request.store');
// 退会予約の取消。**誤操作救済の本体**なので step-up を課さない
// (救済経路に関門を足すと「取り消せない」詰みの再生産になる。取消は権限を増やす操作ではない)。
Route::delete('/settings/account/deletion-request', [AccountDeletionRequestController::class, 'destroy'])
    ->name('settings.account.deletion-request.destroy');
```

```php
final class AccountDeletionRequestController extends Controller
{
    public function store(Request $request, OrganizationMembershipService $membership): RedirectResponse
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $state = $membership->requestAccountDeletion($user);

        // 操作系 POST は back() で完結させる (禁止事項 7: intended() を使わない)
        return back()->with('success', "退会を予約しました。{$state->purgeAfterLabel()}までは取り消せます。");
    }

    public function destroy(Request $request, OrganizationMembershipService $membership): RedirectResponse
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $membership->cancelAccountDeletion($user);

        return back()->with('success', '退会の予約を取り消しました。');
    }
}
```

### 波及変更
- TypeScript 型定義: B7
- API Resource/DTO: なし (Inertia の flash と props)
- テストファイル: `tests/Feature/Auth/AccountDeletionGraceTest.php`

### 既存 gate への登録 (B8 と重複するがここで根拠を書く)
- **`RecentAuthRouteTest`**: `settings.account.deletion-request.store` を allowlist に追加。
  `destroy` は**追加しない** (救済経路に step-up を課さない)。
- **`ControllerAuthorizationGateTest`**: 2 本とも `$selfScoped` で登録。根拠:
  「対象は `$request->user()` 自身のみ。route に他者を指せる parameter が 1 つも無く、
  他人のアカウントへ到達する経路がコード上存在しない。予約は step-up (recent-auth) を必須にし、
  取消は権限を増やさない操作のため関門を置かない」。
- **`ThrottleCoverageInventoryTest`**: **登録不要**。実コードで確認した根拠 —
  母集団は S1 (未認証で到達しうる変更系。本 route は `Authenticate` 配下なので該当しない) ∪
  S2 (`api/`・`oauth/`・`.well-known/oauth-` prefix。該当しない) ∪
  S3 (`throttleCoverageAuthSurfacePattern()` に一致する route 名。パターンは
  `settings\.password\.` は含むが **`settings\.account\.` は含まない**) であり、
  `settings.account.deletion-request.*` はどれにも入らない。
  既存の `settings.account.destroy` も同じ理由で登録されていない。
  **recon-brief の「ThrottleCoverageInventoryTest の更新が要る」は誤りである** (実読で訂正)。
- **`LoginMethodRemovalRouteTest`**: 予約は認証手段を減らさないので**登録不要**
  (実装時に母集団定義を再確認する)。

### PHPStan 適合チェック
- [x] `$request->user()` の `?Authenticatable` を `Assert::isInstanceOf` で narrowing
- [x] 戻り値 `RedirectResponse` 明示
- [x] `response()->json()` を使わない (Inertia の back + flash)

### テスト計画
- [ ] 新規: step-up 無しでは予約できない (302 → recent-auth.confirm)
- [ ] 新規: step-up 済みなら予約でき flash が出る
- [ ] 新規: **step-up 無しでも取消できる** (救済経路)
- [ ] 新規: 未認証は 302 login
- [ ] 新規: 他人のアカウントを指す口が無い (route parameter 不在の構造的検証)

### リスク
- 取消に step-up を課さないことで、セッション奪取者が予約を取り消せる。
  → **これは受け入れる**。奪取者が取り消しても失われるのは「退会の意思」だけで、
  本人は再度予約できる。逆に取消に関門を付けると、**本人が救済できない**方が重い被害になる。
  設計判断として docblock に明記する。

---

## B4. 凍結 middleware (deny-by-default)

### 変更箇所
- 新規: `app/Http/Middleware/EnsureAccountNotPendingDeletion.php`
- 新規: `app/Enums/Account/AccountDeletionFreezeAllowance.php`
- 変更: `bootstrap/app.php` (alias 登録 + priority list の web 鎖へ append)
- 変更: `routes/web.php` (`auth` + `verified` group へ付与)
- 新規: `tests/Architecture/AccountDeletionFreezeRouteGateTest.php`

### 現行コード (priority list の web 鎖。`bootstrap/app.php` L233-254)

```php
foreach ([
    [EnsureProjectBelongsToCurrentOrganization::class, HandleInertiaRequests::class],
    // …
    [EnsureEmailIsVerified::class, RequireActiveSubscription::class],
] as [$after, $append]) {
    $middleware->appendToPriorityList($after, $append);
}
```

### 変更後コード

```php
    [EnsureEmailIsVerified::class, RequireActiveSubscription::class],
    // 退会予約中の凍結。**302 で短絡する**ため、テナント境界 404
    // (EnsureProjectBelongsToCurrentOrganization) より必ず後に置く。
    // 前に置くと「他組織に実在 = 302 / 不在 = 404」の 1 bit 存在オラクルになる
    // (AGENTS.md 不変条件 10)。課金ゲートの直後に置き、未契約組織のユーザーは
    // 課金ゲート → onboarding → 凍結 → /settings の 2 hop で取消 UI に着く。
    [RequireActiveSubscription::class, EnsureAccountNotPendingDeletion::class],
```

```php
// routes/web.php
Route::middleware(['auth', 'verified', 'not-pending-deletion'])->group(function (): void {
```

**route:cache 前提**: group への直付けで配線する。`RouteMiddlewareBinder` の後付けは使わない
(cached 起動では 1 本も効かず無音で保護が外れる = T135 / AGENTS.md 運用要件)。

```php
/**
 * 退会予約中 (凍結) の route allowlist。**deny-by-default**。
 *
 * ここに無い route は予約中に遮断され `/settings` (取消ボタンのある画面) へ 302 する。
 * **wildcard を書かない** (route 名の exact case のみ)。`billing.*` のような namespace 指定を
 * 許すと購入・新規契約・自動チャージ有効化まで一緒に通り、凍結の意味が消える。
 */
enum AccountDeletionFreezeAllowance: string
{
    // --- 取消に到達するための step-up ---
    case RecentAuthConfirm = 'recent-auth.confirm';
    case RecentAuthStatus = 'recent-auth.status';
    case RecentAuthPassword = 'recent-auth.password';
    // --- 取消 UI と取消そのもの ---
    case Settings = 'settings';
    case DeletionRequestDestroy = 'settings.account.deletion-request.destroy';
    // --- 退会ブロッカー (生きた課金責務) の解消 ---
    case BillingIndex = 'billing.index';
    case BillingPortal = 'billing.portal';
    // --- 退会ブロッカー (孤児メンバー) の解消 ---
    case OrganizationSwitch = 'organizations.switch';
    case OrganizationSettings = 'organizations.settings';
    case TransferOwnership = 'organizations.transfer-ownership';
    case MemberUpdate = 'organizations.members.update';
    case MemberDestroy = 'organizations.members.destroy';
    case InvitationRevoke = 'organizations.invitations.revoke';
    // --- 予約・執行不能を知る手段 (読むだけ) ---
    case NotificationsIndex = 'notifications.index';
    case NotificationsReadAll = 'notifications.read-all';
    case NotificationsRead = 'notifications.read';

    /** 30 文字以上の根拠 (gate が長さを検査する)。 */
    public function rationale(): string { /* case ごとに 30 文字以上 */ }
}
```

**`billing.auto-recharge.update` を入れない**: 同じ更新 endpoint が有効化・閾値変更・数量変更を
受けるため、allowlist に入れると**新しい課金責務を作る入口**になり deny-by-default と矛盾する。
方向制約つきの専用 route を新設する案もあるが、**実コードを読むと不要**だった —
`AutoRechargeTriggerJob` を dispatch するのは **`TicketLedgerService::reserve()` の 1 箇所だけ**
(L453 を実読)、`reserve()` を呼ぶのは解析・レンダ等の**業務フロー**であり、
**業務 route は凍結で全部止まる**。つまり**凍結中に自動チャージが発火する経路が構造的に存在しない**。
必要ないものを作らない (思考原則 2)。behavioral で
「**予約中は `AutoRechargeTriggerJob` が 1 件も dispatch されない**」を固定する
(`Queue::fake()` ではなく**実 `jobs` 表**で観測する。ドメイン規約 11 の作法)。

**`billing.portal` を通してよい根拠 (方向制約は既に構造で担保されている)**:
`app/Services/Billing/PortalConfigurationSpec.php` が
**`subscription_update => ['enabled' => false]`** /
**`subscription_cancel => ['enabled' => true, 'mode' => 'at_period_end']`** を宣言し、
`CashierStripeGateway` はこの spec 準拠 configuration で portal session を作る
(運用検証は `billing:ensure-portal-configuration --verify`)。
したがって Portal からは**プラン変更・新規契約ができず、解約と支払い方法更新だけ**ができる =
**責務を減らす方向のみ**。`BillingPortal` case の `rationale()` にこの spec への参照を含め、
**spec が変われば凍結の前提が崩れる**ことを依存関係として明記する。
新しい configuration 検証機構は作らない (既存の `--verify` がある)。
**ただし前提 pin は置く**: `billing:ensure-portal-configuration --verify` が保証するのは
「Stripe 側設定と `PortalConfigurationSpec` の**一致**」だけなので、**spec 自体を
`subscription_update = true` に書き換えると正しい設定として受け入れられうる**
(= M29 が赤化しない可能性がある)。そこで `AccountDeletionFreezeRouteGateTest` に
**allowlist 登録の前提検査**を 3 点置く:
`subscription_update.enabled === false` / `subscription_cancel.enabled === true` /
`subscription_cancel.mode === 'at_period_end'`。
これは新しい検証機構ではなく、**免除・allowlist の前提を behavioral に固定する**
本リポジトリの既存作法である (`ThrottleExemptionPremiseTest` /
`IdempotencyExemptionPremiseTest` が先例)。**M29 の実測**で赤化するテストが
この前提検査であることを実装ノートに記録する。

**`settings.account.destroy` (即時削除) を入れない**: 予約中のユーザーが表明した意思は
「30 日後に削除」であり、その状態で即時削除の口を開けておくと**猶予が守ろうとしているもの
(誤操作) をそのまま通してしまう** (30 日猶予の迂回口になる)。「今すぐ消したい」なら
**取消 → 即時削除**の 2 手を踏む。一貫した状態機械でありユーザーに説明できる。
UI 側も予約中は削除ボタン群を出さず、バナー (取消 + 次の一手) だけを出す (B7)。

**`notifications.open` を入れない**: POST + 303 で**通知の遷移先へ飛ばす** route であり、
allowlist に入れると「通知経由なら業務 route / `dashboard` / checkout に到達できる」抜け道になる
(deny-by-default を自ら迂回する)。通知は `notifications.index` で読めるので rescue surface の役割は
満たされる。**「遷移先ごとに判定する」分岐は作らない** (凍結の判定点が 2 箇所に増える。思考原則 2)。

**group の中 / 外の切り分け (実読で確認)**:
- **group の外 (= 母集団 `U` に入らない = 凍結の影響を受けない)**:
  Fortify / Passkeys が登録する **ログイン・ログアウト・パスワード再設定・メール確認・
  2FA challenge・passkey ログイン**、および `session.status`。
  「認証回復と離脱の手段は構造的に凍結されない」。
- **group の中 (= `U` に入るので allowlist が要る)**: **`recent-auth.confirm` /
  `recent-auth.status` / `recent-auth.password`** の 3 本。これらは `routes/web.php` の
  `auth` + `verified` group の中に定義されている (実読)。取消自体に step-up は不要だが、
  **ブロッカー解消経路である `organizations.transfer-ownership` が `recent-auth` middleware を持つ**ため、
  step-up 画面へ到達できないと移譲ができず**詰む**。よって allowlist に残す。

### middleware 本体

```php
final class EnsureAccountNotPendingDeletion
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request); // 未認証は auth middleware の責務
        }
        if (! AccountDeletionStateDto::fromUser($user)->isPending()) {
            return $next($request);
        }

        $name = $request->route()?->getName();
        if ($name !== null && AccountDeletionFreezeAllowance::tryFrom($name) !== null) {
            return $next($request);
        }

        // JSON/XHR は 409 Conflict (状態が操作と矛盾している)。402 (課金) とは別事由。
        if ($request->expectsJson()) {
            abort(Response::HTTP_CONFLICT, self::FROZEN_MESSAGE);
        }

        // 403 で突き放さず、取消ボタンのある画面で受ける (ドメイン規約 4 と同じ思想)。
        // 遮断理由の flash は積まない — 理由は着地ページ (/settings の予約バナー) が持つ。
        $request->session()->reflash();

        return redirect()->route('settings');
    }
}
```

### `AccountDeletionFreezeRouteGateTest` (8 検査)

`U` = 凍結 middleware が付いた全 route、`A` = enum の route 名集合。**`A ⊆ U`**。

1. **`A ⊆ U`** — allowlist に `U` 外の route 名を書けない
2. enum の route 名が**実在し、凍結 middleware を実際に持つ**
3. **middleware が実際に bypass する集合と `A` が exact-fit** (実装と宣言の一致)
4. **`U - A` の route は予約中に遮断される**ことを behavioral に**全件**検査
5. **`U` に無名 route があれば fail** (名前で allowlist を書けないため)
6. **enum は wildcard を持たない** (`*` を含む case があれば fail) / 各 case の
   `rationale()` が **30 文字以上**
7. **母集団の内外を両方向で固定する**:
   (a) **`logout` / `session.status` が `U` に含まれない** (group の中へ移されたら fail =
       認証回復・離脱の手段を凍結させない)。
   (b) **`recent-auth.confirm` / `recent-auth.status` / `recent-auth.password` が `U` に含まれる**
       (group の外へ移されたら fail = allowlist が死に登録になるのを防ぐ)
8. **`BillingPortal` を allowlist に置く前提の pin**: `PortalConfigurationSpec` の
   `subscription_update.enabled === false` / `subscription_cancel.enabled === true` /
   `subscription_cancel.mode === 'at_period_end'`。
   spec が変われば「Portal は責務を減らす方向のみ」という前提が崩れるため、
   allowlist 登録の前提を behavioral に固定する
   (`ThrottleExemptionPremiseTest` / `IdempotencyExemptionPremiseTest` と同じ作法)
- 加えて **空振り検知**: `U` の件数 floor / `A` の件数 exact / 母集団 0 件で fail

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `TenantBoundaryOrderingTest` (順序契約に 1 行追加)

### PHPStan 適合チェック
- [x] `$request->route()?->getName()` の `?string` を早期 return で narrowing
- [x] `Closure(Request): Response` の phpdoc
- [x] `enum ... : string` の `tryFrom` は `?self` を返す (null 検査あり)

### テスト計画
- [ ] 新規: 予約中に `/projects` が `/settings` へ 302
- [ ] 新規: 予約中に `/settings` は 200、取消できる
- [ ] 新規: **予約中は `settings.account.destroy` (即時削除) が遮断される。
      取消してからなら削除できる** (30 日猶予の迂回口を作らない)
- [ ] 新規: **予約中でもログアウトできる** (`logout` は母集団 `U` の外)
- [ ] 新規: 予約中に `billing.portal` / `organizations.transfer-ownership` に到達できる
- [ ] 新規: **予約中に `billing.checkout` / `billing.tickets.checkout` /
      `billing.auto-recharge.update` / `billing.auto-recharge.setup` / `organizations.store` /
      `organizations.invitations.store` / `notifications.open` が遮断される**
- [ ] 新規: **予約中は `AutoRechargeTriggerJob` が 1 件も dispatch されない**
      (実 `jobs` 表で観測。`Queue::fake()` は使わない)
- [ ] 新規: **未予約ユーザーには一切影響しない** (全 route が従来どおり)
- [ ] 新規: XHR は 409
- [ ] 新規 (到達性 4 本):
      (a) セッション切れ → 再ログイン → 取消完了、
      (b) recent-auth 期限切れ → 取消完了、
      (c) 2FA 必須組織のユーザー → 取消完了、
      (d) **予約バナー / `/settings` から 解約 / 移譲 / メンバー整理 / 招待取消の各画面へ到達できる**
- [ ] 新規: **テナント境界 404 が凍結 302 より前**であること
      (`TenantBoundaryOrderingTest` に順序を 1 行追加 + 他組織の `{project}` は
      予約中でも **404** であって 302 でないことを behavioral に固定)

### リスク
- 認証回復系まで凍結して詰む → **構造的に起きない**。凍結 middleware は
  `routes/web.php` の `auth` + `verified` group にのみ付き、Fortify / Passkeys が登録する
  ログイン・パスワード再設定・メール確認・2FA challenge は**この group の外**にある。
  この事実自体を gate の検査 (母集団 `U` の列挙) が可視化する。

---

## B5. 日次執行バッチ

### 変更箇所
- 新規: `app/Console/Commands/Account/PurgeDeletionRequestsCommand.php`
- 変更: `routes/console.php`

### 変更後コード

```php
protected $signature = 'account:purge-deletion-requests
    {--apply : 実削除する (未指定は dry-run)}';
```

```php
public function handle(OrganizationMembershipService $membership): int
{
    $apply = (bool) $this->option('apply');
    $due = 0;
    $deleted = 0;
    $blocked = 0;          // 業務上の保留 (ValidationException)
    $unexpected = 0;       // インフラ障害 / 不変条件違反

    // 片列だけの非正規行を **due 走査より前に** 数える (DB の CHECK 制約に対する
    // defense-in-depth。制約が無効化された / DB が壊れた場合に気づく)。
    // 件数だけを report し、user id は出さない。
    $invalidStateCount = User::query()
        ->where(function (Builder $query): void {
            $query->whereNull('deletion_requested_at')->whereNotNull('deletion_purge_after');
        })
        ->orWhere(function (Builder $query): void {
            $query->whereNotNull('deletion_requested_at')->whereNull('deletion_purge_after');
        })
        // CHECK 制約 2 本と対称にする (制約が無効化されたとき、期限が予約時刻より前の行が
        // 早期削除候補に入る異常も検知できる)
        ->orWhereColumn('deletion_purge_after', '<', 'deletion_requested_at')
        ->count();
    if ($invalidStateCount > 0) {
        $unexpected += $invalidStateCount;
        report(new RuntimeException(
            "退会予約列が非正規な行を検出: count={$invalidStateCount}",
        ));
    }

    // 片列だけの非正規行を due に数えないため両列を条件にする
    // (DTO の pending 定義「両列が揃う」と一致させる)。
    User::query()
        ->whereNotNull('deletion_requested_at')
        ->whereNotNull('deletion_purge_after')
        ->where('deletion_purge_after', '<=', CarbonImmutable::now())
        ->orderBy('id')
        ->chunkById(100, function (Collection $users) use (&$due, &$deleted, &$blocked, &$unexpected, $apply, $membership): void {
            foreach ($users as $user) {
                $due++;
                if (! $apply) {
                    continue;
                }
                try {
                    // ロック取得後に「予約が生きているか」「期限到来か」を再確認する
                    // (抽出後に取消されたユーザーを古いスナップショットで消さない)。
                    if ($membership->executeAccountDeletionRequest($user)) {
                        $deleted++;
                    }
                } catch (ValidationException $e) {
                    // 退会ブロッカー = **業務上の保留**。予約は維持し次へ進む。
                    $blocked++;
                    report($e);
                } catch (Throwable $e) {
                    // インフラ障害 / 不変条件違反 = **想定外**。継続はするが終了コードは FAILURE。
                    $unexpected++;
                    report($e);
                }
            }
        });

    $this->info("due={$due} deleted={$deleted} blocked={$blocked} unexpected={$unexpected}");

    // 終了コードは 2 分類。全件 DB 障害でも SUCCESS を返すと scheduler の失敗通知も
    // 終了コード監視も機能しなくなる (report() の成功自体も保証されない)。
    return $unexpected > 0 ? self::FAILURE : self::SUCCESS;
}
```

- **`chunkById`** を使う (走査中の削除で行が飛ばない)。`chunk` は使わない。
- **片列だけが埋まった非正規行を検出する** (`deletion_requested_at` のみ / `deletion_purge_after` のみ)。
  **0 件でなければ件数だけを `report()` し `$unexpected` に計上する** (= 終了コード FAILURE。
  黙って無視しない = fail-closed)。user id はログに出さない。
  **B1 の CHECK 制約に対する defense-in-depth** であり、制約の代替ではない
  (状態機械を閉じているのは DB 制約の側)。
- **`whereNotNull('deletion_purge_after')`** は「クラス起点の主キー同一性クエリ」ではない
  (主キー等値でない) ため `DirectFetchInventory` の対象外。実装時に
  `ModelDirectFetchInvariantTest` の母集団定義で再確認する。
- ログには **件数のみ**。user id・email を出さない (PII 非出力。既存
  `billing:detect-orphan-billing-organizations` の報告契約と同水準)。

```php
// routes/console.php (既存の作法に揃える)
/*
|--------------------------------------------------------------------------
| 退会予約の執行 (猶予期間つき削除)
|--------------------------------------------------------------------------
| deletion_purge_after を過ぎた退会予約を執行する。判定は既存の
| OrganizationMembershipService::deleteAccount() が行う (課金ガードのロック下再評価をそのまま継承)。
| 退会ブロッカーは業務上の保留として次へ進み、想定外例外があれば FAILURE で終わる。
|
| **監視対象**: 本コマンドの終了コードと report()。
*/
Schedule::command('account:purge-deletion-requests --apply')->daily()->onOneServer();
```

### 波及変更
- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: `tests/Feature/Auth/AccountDeletionGraceTest.php` に執行系を追加

### PHPStan 適合チェック
- [x] `$this->option('apply')` は `mixed` → `(bool)` ではなく `Assert::boolean()` か
      `$this->option()` の bool 化ヘルパを使う (実装時に既存 Command の作法へ揃える)
- [x] `chunkById` の callback 引数 `Collection<int, User>` を phpdoc で明示
- [x] 戻り値 `int`

### テスト計画
- [ ] 新規: dry-run は 1 人も削除しない
- [ ] 新規: 期限到来ユーザーが削除される / 未到来は残る (境界: 1 秒前 / 1 秒後)
- [ ] 新規: **抽出後に取消 → 削除されない**
- [ ] 新規: **同日 2 回実行で二重削除・二重通知が起きない**
- [ ] 新規: **1 人目でブロッカー例外が出ても 2 人目は削除される** (失敗分離)
- [ ] 新規: **ブロッカーだけなら終了コード SUCCESS**
- [ ] 新規: **想定外例外が 1 件でもあれば終了コード FAILURE** (走査は最後まで続く)
- [ ] 新規: **片列だけの非正規行があれば report + FAILURE** (削除もしない)
- [ ] 新規: **決済事業者 API を呼ばない** (`Http::preventStrayRequests` + fake gateway)
- [ ] 新規: ログに user id / email が出ない

### リスク
- 大量の期限到来ユーザーで実行時間が伸びる → chunk 100 + 1 人ずつ独立 tx。
  タイムアウトしても次回が続きから拾う (状態は DB 側にある)。

---

## B6. 通知・監査

### 変更箇所
- `app/Enums/SecurityEventType.php` に 2 case
  (`AccountDeletionRequested` / `AccountDeletionCancelled`)
- 新規: `app/Notifications/Account/AccountDeletionRequestedNotification.php`
- `app/Services/Notification/NotificationCenterService.php` にアプリ内通知 1 本
- `app/Enums/NotificationType.php` に 1 case (+ `resources/js/types/notification.ts` 同期)

### 設計 (メール通知)

```php
final class AccountDeletionRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly CarbonImmutable $requestedAt,
        private readonly CarbonImmutable $purgeAfter,
    ) {}

    /**
     * 送信直前に予約の生存を再確認する。**これは誤通知の防止であって dedup ではない**。
     *
     * **dispatch の位置だけでは誤通知を防げない** — 「dispatch がどこか」と
     * 「job が参照する状態・実行可能時点」は別問題である。aicue は QueueDispatchAtomicityGuard が
     * driver=database / キュー DB = 業務 DB / after_commit=false を全環境の起動時に
     * fail-closed 検査するため commit 前実行は構造的に起きないが、**それは前提であって保証ではない**。
     *
     * 取消済み・再予約で値が変わった・user 不在なら **送らない** (via が空配列を返す)。
     *
     * **一回性を担うのはここではない**: 同じ (requestedAt, purgeAfter) を持つ job が 2 つあれば
     * 両方とも 'mail' を返す。一回性は **永続状態遷移**が担う —
     * `requestAccountDeletion()` は既に予約中なら**通知を発火せず**冪等 no-op で返すため、
     * 二重送信では job が 1 つしか作られない (AGENTS.md ドメイン規約 6
     * 「入口の排他は best-effort、結果の一回性は永続状態遷移が担う」)。
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        if (! $notifiable instanceof User) {
            return [];
        }
        // **フォールバックしない**。fresh() が null = 執行済みで user 行が無い、という意味なので、
        // シリアライズ済みの削除前スナップショットへ倒すと「執行済みなのに送る」逆転が起きる。
        $fresh = $notifiable->fresh();
        if (! $fresh instanceof User) {
            return [];
        }

        $state = AccountDeletionStateDto::fromUser($fresh);

        return $state->matches($this->requestedAt, $this->purgeAfter) ? ['mail'] : [];
    }
}
```

- **`ShouldQueue` + 予約 tx 内 dispatch** (AGENTS.md ドメイン規約 11)。
  spirux の申し送り「通知を afterCommit で外へ出せ」は **aicue の規約と逆なので採らない**。
- **`ShouldBeUnique` は使わない**。AGENTS.md ドメイン規約 11 が禁じている
  (unique lock は dispatch 時に取得され rollback で解放されないため業務 tx 内 dispatch と両立しない。
  `AutoRechargeTriggerJob` から撤去済みの先例がある)。**送達台帳も新設しない** (思考原則 2)。
- **`JobExecutionDedupInventoryTest` への登録が必要**。**`JobDedupGuarantee` には登録しない** —
  保証しているのは「二重 POST から二重 dispatch しないこと」であって **job 実行の dedup ではない**。
  実装時に既存 enum を実読し、**免除側 (`JobDedupExemption`) + 30 文字以上の根拠**
  (「通知の重複配送は業務不変条件を壊さない。取り消せない外部副作用ではなく、
  予約状態の妥当性は via() の再確認が守る」) で登録する。既存 case に合うものが無ければ
  根拠つきで新 case を追加する。
- **保証しないもの (誇張しない)**: 保証するのは
  **「予約操作からの job 生成は最大 1 件」**だけである。
  **job の実行と外部配送は重複しうる best-effort** — 外部メールサービスが受理した後に
  worker が完了記録の前で停止すれば retry で再送されうる。
  「at-most-once」の一語で潰さない。「同一 payload の job を 2 つ投入しても 1 通」とも主張しない。

### 波及変更
- TypeScript 型定義: `resources/js/types/notification.ts` (`NotificationTypeTsSyncInvariantTest` が同期を強制)
- API Resource/DTO: なし
- テストファイル: `SecurityEventCoverageTest` (新 case 2 つの記録経路)、
  `InAppNotificationTypeInvariantTest`

### PHPStan 適合チェック
- [x] `via()` の戻り値 `list<string>`
- [x] `$notifiable->fresh()` の `?Model` を **instanceof + early return** で narrowing
      (`??` フォールバックを書かない = fail-closed)
- [x] `CarbonImmutable` の readonly promoted property

### テスト計画
- [ ] 新規: 予約でメールが 1 通送られる
- [ ] 新規: **予約 → 即取消 → worker 実行 → メール 0 通** (via の再確認)
- [ ] 新規: **予約 POST を 2 回叩いてもメールは 1 通** (Service 層の冪等 no-op が一回性を担う)
- [ ] 新規: **再予約時に古い job が送られない** (requestedAt/purgeAfter の一致検査)
- [ ] 新規: **執行済み (user 削除済み) の queued notification は送られない** (`fresh()` が null)
- [ ] 新規: SecurityEvent 2 種が記録される
- [ ] 新規: アプリ内通知が 1 件作られる

### リスク
- `via()` で DB を引くため、キュー実行時に user が既に消えている (執行済み) 場合がある
  → `fresh()` が null なら送らない (fail-closed)。

---

## B7. UI

### 変更箇所
- `app/Http/Controllers/Settings/ProfileController.php` (props に `accountDeletionState` を追加)
- `resources/js/pages/Settings/Index.svelte` (予約バナー + 主導線の入れ替え)
- `resources/js/types/account.ts` (`AccountDeletionState` 型)

### 変更後コード (props)

```php
return Inertia::render('Settings/Index', [
    'accountDeletionBlockers' => /* 既存のまま */,
    // 退会予約の状態 (予約中なら取消バナーを出す)。ISO 8601 文字列 + 導出 graceDays。
    'accountDeletionState' => AccountDeletionStateDto::fromUser($user)->toArray(),
    'hasPassword' => $user->hasPassword(),
]);
```

```ts
/** PHP: App\DataTransferObjects\Account\AccountDeletionStateDto::toArray() と対 */
export interface AccountDeletionState {
    requestedAt: string | null;
    purgeAfter: string | null;
    graceDays: number | null;
}
```

### UI の契約
- **予約中**: DangerZone の先頭に `Alert type="warning"` の予約バナー。内容は
  (a) `purgeAfter` の日付、(b) **「毎日 1 回自動で再試行する」**旨、
  (c) **取消ボタン** (primary)、(d) ブロッカーがあれば既存 `accountDeletionBlockers` の
  「次の一手」リンク群 (解約 / 移譲 / 切替) をそのまま表示。
- **未予約**: **主ボタンは「30 日後に削除 (取り消せます)」**、
  副導線として **「今すぐ完全に削除する (取り消せません)」** を ghost/link で置く。
- **条件未充足で disabled にしない** (禁止事項 8)。押下時にサーバがエラーを返し、
  既存の blocker 表示が「次の一手」を出す。

### 波及変更
- TypeScript 型定義: `resources/js/types/account.ts`
- API Resource/DTO: `AccountDeletionStateDto`
- テストファイル: `tests/js/pages/SettingsIndex.test.ts` (component)、
  `tests/Browser/` (主導線の視覚的優先度)

### テスト計画
- [ ] 新規 (component): 予約中はバナーと取消ボタンが出る
- [ ] 新規 (component): **未予約時、予約が primary ボタンで即時削除が副導線**である
      (「UI 主導線が本当に予約へ移る」ことを口約束にしない)
- [ ] 新規 (component): ブロッカーがあってもボタンは `disabled` にならない (禁止事項 8)
- [ ] 新規 (Browser): 予約 → バナー表示 → 取消 の一巡
- [ ] 既存 `tests/Browser/FlashToastTest.php` (即時削除 → home の GuestLayout 着地) は**変更しない**
- [ ] 既存 atom/molecule (`Alert` / `Button` / `TextLink` / `DangerZone`) を再利用し、
      **hex 直書きを増やさない** (DESIGN.md が canonical。ds-purity テストの対象)。
      アイコンは `@lucide/svelte` のみ (SVG 直書きを新設しない)。
      component 階層の単方向 import (`atoms → molecules → organisms → features → templates → pages`) を守る
- [ ] **予約中は削除ボタン群を出さない** (バナー + 取消 + 次の一手のみ。
      B4 で `settings.account.destroy` を凍結対象にしたことと UI を一致させる)

### リスク
- 既存 Browser テストが「削除ボタン = 即時削除」を前提にしている可能性
  → 即時削除ボタンは残るので `testId` を変えない。主導線の追加は既存 selector を壊さない
  (spirux は既定を予約に変えて BrowserPest が赤くなった。同じ轍を踏まない)。

---

## B8. 既存 gate の更新 (まとめ)

| gate | 変更内容 | 根拠 |
|---|---|---|
| `RecentAuthRouteTest` | `settings.account.deletion-request.store` を allowlist へ | 即時削除と同水準の機微操作 |
| `ControllerAuthorizationGateTest` | 新 route 2 本を `$selfScoped` で登録 | 他者を指す parameter が無い |
| `MembershipWriteLockInventoryTest` | `requestAccountDeletion` / `cancelAccountDeletion` → **`directLock`** / `executeAccountDeletionRequest` → **`delegatedToLocked`**。併せて **`delegatedToLocked` を「メソッド名 => 必須の委譲先呼び出し」の map へ一般化**する (現状は `joinOrganization(` のハードコード。既存 3 本の判定は等価のまま = テストの意味を弱めない) | 前 2 者は自 tx 冒頭で `lockForMembershipWrite(` を呼ぶ。3 番目は `deleteAccount(` へ委譲する |
| `TenantBoundaryOrderingTest` | 凍結 middleware の順序を 1 行追加 | 404 が 302 より前 |
| `JobExecutionDedupInventoryTest` | 通知 job を登録 | `ShouldQueue` 実装の全クラスが対象 |
| `SecurityEventCoverageTest` | 新 case 2 つの記録経路 | case 追加時は同一 PR で配線 |
| `AccountDeletionPathGateTest` (A1) | 起点に `PurgeDeletionRequestsCommand::handle` を追加。**閉包目録の差分に理由コメントを残す** | 執行経路も依存閉包の対象 |
| `NotificationTypeTsSyncInvariantTest` | 新 NotificationType 1 件 | TS 同期 |
| **`ThrottleCoverageInventoryTest`** | **変更不要** | 母集団 S1/S2/S3 のいずれにも入らない (§B3 で実読確認) |

---



# 共通: 検査が空振りしないことの保証

新設する全 gate に以下を必ず同梱する (本リポジトリの gate 書式)。

| 手段 | 内容 |
|---|---|
| **母集団 floor** | 走査ファイル数 / route 数 / 目録件数が 0 でないことを下限で pin。0 件なら fail |
| **exact-fit cap** | 免除・allowlist の件数を**現在値ちょうど**で pin (余裕を 1 でも持たせない。
`ThrottleCoverageInventoryTest` の cap コメントと同じ理由 — 余裕枠は「根拠なしに免除できる枠」になる) |
| **負のコントロール** | fixture ソース (nowdoc 内。code token にならない) を検出器に当てて**点灯すること**を確認 |
| **自己参照コントロール** | gate ファイル自身を走査して hit 0 件 (説明コメントで偽赤にならない) |
| **正の自己検証** | 実ファイルで検出器が実際に点灯すること (検出器が死んでいないこと) |

# 共通: mutation で赤化を確認する手順

**実装完了の条件**は「テストが緑」ではなく「**壊すと赤くなることを実測した**」である。
各 gate について以下を**実行し、結果を実装ノートに記録する**。

| # | 変異 (実施後は必ず戻す) | 赤くなるべきテスト |
|---|---|---|
| M1 | `AccountDeletionPathGateTest` の起点から `deleteAccount` を外す | 空振り検知 (閉包サイズ floor) |
| M2 | `OrganizationMembershipService` に `Stripe\StripeClient` を型注入するだけの private property を足す | 依存閉包 gate 検査 2 |
| M3 | 同じ注入を `app('cashier.stripe')` の literal 呼び出しで書く | 同上 (fixture 4 形目) |
| M4 | `AccountDeletionFreezeAllowance` から `settings` を削る | 到達性テスト (取消に到達できない) |
| M5 | 同 enum に `dashboard` を足す | exact-fit 検査 3 |
| M6 | 凍結 middleware を priority list で `EnsureProjectBelongsToCurrentOrganization` より**前**へ動かす | `TenantBoundaryOrderingTest` + 他組織 `{project}` が 302 になる behavioral |
| M7 | `PurgeDeletionRequestsCommand` の終了コードを常に `SUCCESS` にする | 「想定外例外で FAILURE」テスト |
| M8 | `deleteAccount` の precondition 差し込み位置をブロッカー判定の**後**へ動かす | 「抽出後に取消 → 削除しない」テスト |
| M9 | 通知の `via()` から予約生存の再確認を外す | 「予約 → 即取消 → メール 0 通」テスト |
| M10 | `BillingRetentionTarget` から `Subscription` を削る | 目録 exact-fit (母集団の分類漏れ) |
| M11 | `Subscription` の起算列を `ends_at` → `created_at` に変える | 「継続中は何年経っても対象外」テスト |
| M12 | `TicketLedgerEntry` を C1 の horizon 対象に入れる | horizon (期限超過が残る) |
| M13 | 畳み込みで `source` を捨てて 1 行に合算する | 6 種比較の「source 別残高」 |
| M13b | 畳み込みの group key から `organization_id` を外す | 7 種比較の「組織ごとの残高一致」(複数組織 fixture) |
| M14 | privacy blade の年数を literal `7` に書き換える | 三者一致 gate 検査 3 |
| M15 | privacy の保持期間の節ごと削除する | `PrivacyRetentionDeclarationTest` (a)(b)(c)(d) |
| M16 | `BillingRetentionPurgeResultDto::isPublicationReady()` から `failClosed === 0` を外す | 公開条件テスト |
| M17 | `AccountDeletionFreezeAllowance` に `settings.account.destroy` を足す | 「予約中は即時削除できない」テスト |
| M18 | `logout` を `auth`+`verified` group の中へ移す | 凍結 gate 検査 7 (`U` に含まれないこと) |
| M19 | `requestAccountDeletion` の冪等 no-op を外し予約中でも通知を発火させる | 「予約 POST 2 回でメール 1 通」テスト |
| M20 | 執行バッチの抽出条件から `whereNotNull('deletion_requested_at')` を外す | 「片列だけの非正規行を due に数えない」テスト |
| M21 | `config/account.php` の `deletion_grace_days` を 0 にする | `AccountDeletionGraceConfigTest` の fail-fast |
| M22 | `purgeAfter()` を `addDaysNoOverflow` に戻す | 「2026-01-31 の 30 日後 = 2026-03-02」behavioral |
| M23 | 通知 `via()` を `fresh() ?? $notifiable` へ戻す | 「執行済み user へ送らない」テスト |
| M24 | redaction 記録の CHECK 制約を外し片列だけ UPDATE する | migration の DB 制約テスト |
| M25 | `recent-auth.confirm` を allowlist から外す | 到達性 (d) 移譲画面へ到達できない |
| M26 | `StripeWebhookEvent` の `anomalyClockColumn()` を null にする | 「未処理の古い webhook が failClosed に計上される」テスト |
| M27 | `AccountDeletionFreezeAllowance` に `billing.auto-recharge.update` を足す | 「予約中に auto-recharge 更新が遮断される」テスト |
| M28 | users の CHECK 制約を外し片列だけ UPDATE する | migration の DB 制約テスト |
| M29 | `PortalConfigurationSpec` の `subscription_update` を `true` にする | `AccountDeletionFreezeRouteGateTest` の**前提検査 3 点** (`--verify` は spec との一致しか見ないため、前提 pin が無いと赤化しない可能性がある。**どのテストが赤くなったかを実装ノートに記録する**) |

**手順**: 1 変異ずつ適用 → 対象テストが**赤いこと**を実測 → 変異を戻す →
全体が緑に戻ることを確認 (`git diff` が空であることも確認する)。

---



## 実装差分 (git diff)

```diff
diff --git a/app/Console/Commands/Account/PurgeDeletionRequestsCommand.php b/app/Console/Commands/Account/PurgeDeletionRequestsCommand.php
new file mode 100644
index 0000000..389b570
--- /dev/null
+++ b/app/Console/Commands/Account/PurgeDeletionRequestsCommand.php
@@ -0,0 +1,106 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Console\Commands\Account;
+
+use App\Models\User;
+use App\Services\Organization\OrganizationMembershipService;
+use Carbon\CarbonImmutable;
+use Illuminate\Console\Command;
+use Illuminate\Database\Eloquent\Builder;
+use Illuminate\Database\Eloquent\Collection;
+use Illuminate\Validation\ValidationException;
+use RuntimeException;
+use Throwable;
+
+/**
+ * 退会予約 (猶予期間つき削除) の日次執行。
+ *
+ * ★**判定コードを分岐させない**。期限到来の再確認は
+ *   `OrganizationMembershipService::executeAccountDeletionRequest()` が行い、削除そのものは
+ *   既存の `deleteAccount()` をそのまま呼ぶ (課金ガードのロック下再評価をそのまま継承する)。
+ *
+ * ★終了コードは **2 分類**である。退会ブロッカー (ValidationException) は**業務上の保留**で
+ *   SUCCESS のまま次へ進み、インフラ障害や不変条件違反は `unexpected` として FAILURE を返す。
+ *   全件 DB 障害でも SUCCESS を返すと scheduler の失敗通知も終了コード監視も機能しなくなる
+ *   (`report()` の成功自体も保証されない)。
+ *
+ * ★ログには **件数のみ**。user id / email を出さない (PII 非出力。既存
+ *   `billing:detect-orphan-billing-organizations` の報告契約と同水準)。
+ *
+ * ★`chunkById` を使う (走査中に行が消えても飛ばない)。`chunk` は使わない。
+ */
+class PurgeDeletionRequestsCommand extends Command
+{
+    protected $signature = 'account:purge-deletion-requests
+        {--apply : 実削除する (未指定は dry-run)}';
+
+    protected $description = '猶予期間を過ぎた退会予約を執行する (既定 dry-run)';
+
+    public function handle(OrganizationMembershipService $membership): int
+    {
+        $apply = $this->option('apply') === true;
+        $due = 0;
+        $deleted = 0;
+        $blocked = 0;      // 業務上の保留 (ValidationException)
+        $unexpected = 0;   // インフラ障害 / 不変条件違反
+
+        // 片列だけの非正規行を **due 走査より前に** 数える。DB の CHECK 制約に対する
+        // defense-in-depth であり、制約の代替ではない (状態機械を閉じているのは DB 側)。
+        // 件数だけを report し、user id は出さない。
+        $invalidStateCount = User::query()
+            ->where(function (Builder $query): void {
+                $query->whereNull('deletion_requested_at')->whereNotNull('deletion_purge_after');
+            })
+            ->orWhere(function (Builder $query): void {
+                $query->whereNotNull('deletion_requested_at')->whereNull('deletion_purge_after');
+            })
+            // CHECK 制約 2 本と対称にする (制約が無効化されたとき、期限が予約時刻より前の行が
+            // 早期削除候補に入る異常も検知できる)
+            ->orWhereColumn('deletion_purge_after', '<', 'deletion_requested_at')
+            ->count();
+        if ($invalidStateCount > 0) {
+            $unexpected += $invalidStateCount;
+            report(new RuntimeException(
+                "退会予約列が非正規な行を検出: count={$invalidStateCount}",
+            ));
+        }
+
+        // 片列だけの非正規行を due に数えないため両列を条件にする
+        // (DTO の pending 定義「両列が揃う」と一致させる)。
+        User::query()
+            ->whereNotNull('deletion_requested_at')
+            ->whereNotNull('deletion_purge_after')
+            ->where('deletion_purge_after', '<=', CarbonImmutable::now())
+            ->orderBy('id')
+            ->chunkById(100, function (Collection $users) use (&$due, &$deleted, &$blocked, &$unexpected, $apply, $membership): void {
+                /** @var Collection<int, User> $users */
+                foreach ($users as $user) {
+                    $due++;
+                    if (! $apply) {
+                        continue;
+                    }
+                    try {
+                        // ロック取得後に「予約が生きているか」「期限到来か」を再確認する
+                        // (抽出後に取消されたユーザーを古いスナップショットで消さない)。
+                        if ($membership->executeAccountDeletionRequest($user)) {
+                            $deleted++;
+                        }
+                    } catch (ValidationException $e) {
+                        // 退会ブロッカー = **業務上の保留**。予約は維持し次へ進む。
+                        $blocked++;
+                        report($e);
+                    } catch (Throwable $e) {
+                        // インフラ障害 / 不変条件違反 = **想定外**。継続はするが終了コードは FAILURE。
+                        $unexpected++;
+                        report($e);
+                    }
+                }
+            });
+
+        $this->info("due={$due} deleted={$deleted} blocked={$blocked} unexpected={$unexpected}");
+
+        return $unexpected > 0 ? self::FAILURE : self::SUCCESS;
+    }
+}
diff --git a/app/DataTransferObjects/Account/AccountDeletionStateDto.php b/app/DataTransferObjects/Account/AccountDeletionStateDto.php
new file mode 100644
index 0000000..8a4ed41
--- /dev/null
+++ b/app/DataTransferObjects/Account/AccountDeletionStateDto.php
@@ -0,0 +1,102 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Account;
+
+use App\Models\User;
+use Carbon\CarbonImmutable;
+
+/**
+ * 退会予約 (猶予期間つき削除・凍結方式) の状態スナップショット。
+ *
+ * users 行の 2 列 (`deletion_requested_at` / `deletion_purge_after`) をそのまま写した値
+ * オブジェクトで、**予約中かどうかの判定はこの DTO に一本化する** (middleware / Service /
+ * Command / 画面 props が同じ述語を見る)。
+ *
+ * ★`isPending()` は**両列が揃っているときだけ** true を返す = 片列だけの非正規状態を
+ *   「予約中」と認めない。DB 側の CHECK 制約 (users_deletion_request_pair_check) と同じ定義で、
+ *   制約が無効化された場合でもアプリ側の判定がぶれない。
+ * ★猶予日数は列を持たず `purgeAfter - requestedAt` から**導出**する (2 つの表現を持たない)。
+ */
+final readonly class AccountDeletionStateDto
+{
+    public function __construct(
+        public ?CarbonImmutable $requestedAt,
+        public ?CarbonImmutable $purgeAfter,
+    ) {}
+
+    /**
+     * users 行から組み立てる。
+     *
+     * cast は `immutable_datetime` だが、`CarbonImmutable::instance()` で明示変換して
+     * **cast 設定の変更に対して二重に守る** (cast が 'datetime' へ戻されても型が崩れない)。
+     */
+    public static function fromUser(User $user): self
+    {
+        $requestedAt = $user->deletion_requested_at;
+        $purgeAfter = $user->deletion_purge_after;
+
+        return new self(
+            $requestedAt === null ? null : CarbonImmutable::instance($requestedAt),
+            $purgeAfter === null ? null : CarbonImmutable::instance($purgeAfter),
+        );
+    }
+
+    /** 予約中か (両列が揃っているときだけ true = 片方だけの非正規状態を pending と認めない)。 */
+    public function isPending(): bool
+    {
+        return $this->requestedAt !== null && $this->purgeAfter !== null;
+    }
+
+    /** 執行期限が到来しているか (比較演算子ではなく Carbon API を使う。意図と型が明確)。 */
+    public function isDue(CarbonImmutable $now): bool
+    {
+        return $this->requestedAt !== null
+            && $this->purgeAfter !== null
+            && $this->purgeAfter->lessThanOrEqualTo($now);
+    }
+
+    /**
+     * 予約が「この (requestedAt, purgeAfter) の組」と一致するか。
+     *
+     * キュー実行時の再確認に使う (取消済み / 再予約で値が変わった場合に古い通知を送らない)。
+     * 秒未満の丸め差で偽陰性にならないよう、**秒精度**で比較する。
+     */
+    public function matches(CarbonImmutable $requestedAt, CarbonImmutable $purgeAfter): bool
+    {
+        return $this->isPending()
+            && $this->requestedAt?->startOfSecond()->equalTo($requestedAt->startOfSecond()) === true
+            && $this->purgeAfter?->startOfSecond()->equalTo($purgeAfter->startOfSecond()) === true;
+    }
+
+    /** 猶予日数 (表示用。導出値であり列を持たない)。未予約なら null。 */
+    public function graceDays(): ?int
+    {
+        if ($this->requestedAt === null || $this->purgeAfter === null) {
+            return null;
+        }
+
+        return (int) round($this->requestedAt->diffInDays($this->purgeAfter));
+    }
+
+    /** 執行予定日のラベル (flash 文言用)。未予約なら null。 */
+    public function purgeAfterLabel(): ?string
+    {
+        return $this->purgeAfter?->format('Y年n月j日');
+    }
+
+    /**
+     * Inertia props 形。日時は **ISO 8601 文字列** (クライアントで Date に起こす)。
+     *
+     * @return array{requestedAt: string|null, purgeAfter: string|null, graceDays: int|null}
+     */
+    public function toArray(): array
+    {
+        return [
+            'requestedAt' => $this->requestedAt?->toIso8601String(),
+            'purgeAfter' => $this->purgeAfter?->toIso8601String(),
+            'graceDays' => $this->graceDays(),
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/Notification/AccountDeletionRequestedPayload.php b/app/DataTransferObjects/Notification/AccountDeletionRequestedPayload.php
new file mode 100644
index 0000000..0fbf040
--- /dev/null
+++ b/app/DataTransferObjects/Notification/AccountDeletionRequestedPayload.php
@@ -0,0 +1,47 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Notification;
+
+/**
+ * 退会予約 (猶予期間つき削除) のアプリ内通知の表示用 payload。
+ *
+ * `purgeAfter` は ISO 8601 文字列 (発火時点のスナップショット)。取消で予約が消えても
+ * 通知行は履歴として残るため、**この値は「予約した時点の予定日」であって現在の状態ではない**
+ * (現在の状態は /settings の props が持つ)。
+ */
+final readonly class AccountDeletionRequestedPayload
+{
+    public function __construct(
+        public string $purgeAfter,
+        public int $graceDays,
+    ) {}
+
+    /**
+     * @return array{purge_after: string, grace_days: int}
+     */
+    public function toArray(): array
+    {
+        return [
+            'purge_after' => $this->purgeAfter,
+            'grace_days' => $this->graceDays,
+        ];
+    }
+
+    /**
+     * 読み出し側の検証復元。型不整合は null 返し (= フロントは fallback 表示)。
+     *
+     * @param  array<array-key, mixed>  $data
+     */
+    public static function tryFromArray(array $data): ?self
+    {
+        $purgeAfter = $data['purge_after'] ?? null;
+        $graceDays = $data['grace_days'] ?? null;
+        if (! is_string($purgeAfter) || ! is_int($graceDays)) {
+            return null;
+        }
+
+        return new self($purgeAfter, $graceDays);
+    }
+}
diff --git a/app/DataTransferObjects/Notification/NotificationListItemData.php b/app/DataTransferObjects/Notification/NotificationListItemData.php
index da9802e..e5970c6 100644
--- a/app/DataTransferObjects/Notification/NotificationListItemData.php
+++ b/app/DataTransferObjects/Notification/NotificationListItemData.php
@@ -26,7 +26,7 @@ public function __construct(
         public ?int $organizationId,
         public ?string $readAt,
         public string $createdAt,
-        public ManualJobPayload|InvitationReceivedPayload|TicketBalanceLowPayload|null $payload,
+        public ManualJobPayload|InvitationReceivedPayload|TicketBalanceLowPayload|AccountDeletionRequestedPayload|null $payload,
     ) {}
 
     public static function fromNotification(DatabaseNotification $notification): self
@@ -47,6 +47,7 @@ public static function fromNotification(DatabaseNotification $notification): sel
             NotificationType::ManualRendered => ManualJobPayload::tryFromArray($data),
             NotificationType::InvitationReceived => InvitationReceivedPayload::tryFromArray($data),
             NotificationType::TicketBalanceLow => TicketBalanceLowPayload::tryFromArray($data),
+            NotificationType::AccountDeletionRequested => AccountDeletionRequestedPayload::tryFromArray($data),
             null => null,
         };
 
diff --git a/app/Enums/Account/AccountDeletionFreezeAllowance.php b/app/Enums/Account/AccountDeletionFreezeAllowance.php
new file mode 100644
index 0000000..417acc5
--- /dev/null
+++ b/app/Enums/Account/AccountDeletionFreezeAllowance.php
@@ -0,0 +1,113 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Account;
+
+/**
+ * 退会予約中 (凍結) に**通してよい route 名**の目録。**deny-by-default**。
+ *
+ * ここに無い route は予約中に遮断され `/settings` (取消ボタンのある画面) へ 302 する。
+ *
+ * ★**wildcard を書かない** (route 名の exact case のみ)。`billing.*` のような namespace 指定を
+ *   許すと購入・新規契約・自動チャージ有効化まで一緒に通り、凍結の意味が消える。
+ * ★母集団 (`U` = 凍結 middleware が付いた全 route) との関係は **`A ⊆ U`**。
+ *   `U` に無い route 名は書けない (死に登録の防止)。実装と宣言の一致・母集団の内外は
+ *   `tests/Architecture/AccountDeletionFreezeRouteGateTest.php` が機械固定する。
+ *
+ * ★**`settings.account.destroy` (即時削除) は入れない**。予約中のユーザーが表明した意思は
+ *   「30 日後に削除」であり、その状態で即時削除の口を開けておくと**猶予が守ろうとしているもの
+ *   (誤操作) をそのまま通してしまう** (30 日猶予の迂回口になる)。「今すぐ消したい」なら
+ *   **取消 → 即時削除**の 2 手を踏む (一貫した状態機械でありユーザーに説明できる)。
+ * ★**`notifications.open` は入れない**。POST + 303 で通知の遷移先へ飛ばす route であり、
+ *   入れると「通知経由なら業務 route / dashboard / checkout に到達できる」抜け道になる。
+ *   通知は `notifications.index` で読めるので rescue surface の役割は満たされる
+ *   (「遷移先ごとに判定する」分岐は作らない = 凍結の判定点を 2 箇所に増やさない)。
+ * ★**`billing.auto-recharge.update` は入れない**。同じ更新 endpoint が有効化・閾値変更・
+ *   数量変更を受けるため、通すと**新しい課金責務を作る入口**になる。凍結中に自動チャージが
+ *   発火する経路は構造的に存在しない (`AutoRechargeTriggerJob` を dispatch するのは
+ *   `TicketLedgerService::reserve()` だけで、それを呼ぶ業務 route は凍結で全部止まる)。
+ */
+enum AccountDeletionFreezeAllowance: string
+{
+    // --- 取消に到達するための step-up (satisfier) ---
+    case RecentAuthConfirm = 'recent-auth.confirm';
+    case RecentAuthStatus = 'recent-auth.status';
+    case RecentAuthPassword = 'recent-auth.password';
+    // --- 取消 UI と取消そのもの ---
+    case Settings = 'settings';
+    case DeletionRequestDestroy = 'settings.account.deletion-request.destroy';
+    // --- 退会ブロッカー (生きた課金責務) の解消 ---
+    case BillingIndex = 'billing.index';
+    case BillingPortal = 'billing.portal';
+    // --- 退会ブロッカー (孤児メンバー) の解消 ---
+    case OrganizationSwitch = 'organizations.switch';
+    case OrganizationSettings = 'organizations.settings';
+    case TransferOwnership = 'organizations.transfer-ownership';
+    case MemberUpdate = 'organizations.members.update';
+    case MemberDestroy = 'organizations.members.destroy';
+    case InvitationRevoke = 'organizations.invitations.revoke';
+    // --- 予約・執行不能を知る手段 (読むだけ) ---
+    case NotificationsIndex = 'notifications.index';
+    case NotificationsReadAll = 'notifications.read-all';
+    case NotificationsRead = 'notifications.read';
+
+    /**
+     * 通す根拠 (**30 文字以上**。gate が長さを検査する)。
+     *
+     * 「凍結中でもこれが無いと詰む」を 1 case ずつ書く。書けないなら通してはいけない。
+     */
+    public function rationale(): string
+    {
+        return match ($this) {
+            self::RecentAuthConfirm => '取消自体に step-up は不要だが、ブロッカー解消経路である'
+                .'オーナー移譲 (organizations.transfer-ownership) が recent-auth を持つため、'
+                .'この確認画面に到達できないと移譲ができず退会も取消後の再削除もできず詰む。',
+            self::RecentAuthStatus => 'クライアント主導 step-up の precheck (XHR)。これを塞ぐと '
+                .'/settings 上の各操作が鮮度判定に失敗し、再認証モーダルを出せないまま'
+                .'無反応になる (押したのに何も起きない詰み)。読み取りのみで状態を変えない。',
+            self::RecentAuthPassword => 'step-up の password satisfier。確認画面に到達できても'
+                .'ここが塞がると再認証が完了せず、オーナー移譲によるブロッカー解消が不可能になる。'
+                .'認証手段の増減はせず、鮮度を更新するだけの経路である。',
+            self::Settings => '退会予約バナーと**取消ボタン**が置かれた画面そのもの。凍結の着地先で'
+                .'あり、ここを通さないと 302 が自分自身へ無限ループし、誤操作救済が成立しない。',
+            self::DeletionRequestDestroy => '退会予約の取消そのもの。誤操作救済の本体であり、'
+                .'凍結中に必ず実行できなければ猶予期間を設けた意味が消える (取り消せない詰み)。',
+            self::BillingIndex => '退会ブロッカーのひとつ「生きた課金責務」を解消する導線の起点。'
+                .'解約手段に到達できないと、ブロックされたまま 30 日後の執行も失敗し続ける。',
+            self::BillingPortal => 'Customer Portal のセッション生成。PortalConfigurationSpec が '
+                .'subscription_update=false / subscription_cancel=at_period_end を宣言しており、'
+                .'Portal からは**解約と支払い方法更新だけ**ができる = 責務を減らす方向のみ。'
+                .'**この spec が変われば通してよい前提が崩れる** (gate が spec を pin する)。',
+            self::OrganizationSwitch => '課金・組織設定は current org スコープ (route parameter を'
+                .'持たない) のため、別組織のブロッカーを解消するには切替が必須。切替自体は'
+                .'所属組織の間の移動でしかなく、新しい責務を作らない。',
+            self::OrganizationSettings => 'オーナー移譲・メンバー整理の操作 UI が置かれた画面。'
+                .'ブロッカー解消の入口であり、閲覧できないと「次の一手」が押せず詰む。',
+            self::TransferOwnership => '退会ブロッカー「唯一 Owner かつ他メンバーが残る」の唯一の'
+                .'解消手段。凍結中に実行できないとブロッカーが永久に残り、執行が毎日失敗し続ける。',
+            self::MemberUpdate => 'メンバー整理 (ロール変更) によるブロッカー解消経路。'
+                .'組織の owner 集合を正す操作であり、新しい課金責務も新しいデータも作らない。',
+            self::MemberDestroy => '孤児化するメンバーを外すことでブロッカーを解消する経路。'
+                .'退会条件を満たすための除去操作であり、責務を増やす方向には働かない。',
+            self::InvitationRevoke => '送信済み招待の取り消し。予約中に新しいメンバーが増えると'
+                .'ブロッカーが再発するため、**招待の送信は通さず取り消しだけ**を通す非対称にする。',
+            self::NotificationsIndex => '予約内容 (いつ削除されるか) と執行結果を本人が読む手段。'
+                .'メールが届かない環境でも状況を把握できる rescue surface として必要。',
+            self::NotificationsReadAll => '通知一覧の一括既読化。既読フラグを進めるだけで業務状態も'
+                .'課金も動かさず、一覧が読める以上ここを塞ぐ理由がない (未読が永久に残る)。',
+            self::NotificationsRead => '通知 1 件の既読化。read-all と同じく既読フラグのみを'
+                .'更新する読み取り面の操作で、遷移も伴わない (遷移する open は通さない)。',
+        };
+    }
+
+    /**
+     * 通してよい route 名の集合。
+     *
+     * @return list<string>
+     */
+    public static function values(): array
+    {
+        return array_map(static fn (self $case): string => $case->value, self::cases());
+    }
+}
diff --git a/app/Enums/Notification/NotificationType.php b/app/Enums/Notification/NotificationType.php
index b970f41..56d5c0c 100644
--- a/app/Enums/Notification/NotificationType.php
+++ b/app/Enums/Notification/NotificationType.php
@@ -18,4 +18,7 @@ enum NotificationType: string
     case ManualRendered = 'manual_rendered';
     case InvitationReceived = 'invitation_received';
     case TicketBalanceLow = 'ticket_balance_low';
+    // 退会 (猶予期間つき削除) の予約。凍結中でも通知センターは読めるため、
+    // 「いつ消えるか / どこで取り消せるか」を本人が確認できる rescue surface になる
+    case AccountDeletionRequested = 'account_deletion_requested';
 }
diff --git a/app/Enums/SecurityEventType.php b/app/Enums/SecurityEventType.php
index a9d06d6..ce31498 100644
--- a/app/Enums/SecurityEventType.php
+++ b/app/Enums/SecurityEventType.php
@@ -25,6 +25,10 @@ enum SecurityEventType: string
     case TwoFactorDisabled = 'two_factor_disabled';
     case EmailChanged = 'email_changed';
     case AccountDeleted = 'account_deleted';
+    // 猶予期間つき退会 (凍結方式) の予約 / 取消。物理削除 (account_deleted) とは別事象で、
+    // 「意思表示があった / 撤回された」ことを残す (誤操作救済の追跡と、凍結の説明責任のため)
+    case AccountDeletionRequested = 'account_deletion_requested';
+    case AccountDeletionCancelled = 'account_deletion_cancelled';
     case SocialAccountLinked = 'social_account_linked';
     case OwnershipTransferred = 'ownership_transferred';
     case ApiKeyIssued = 'api_key_issued';
@@ -51,6 +55,8 @@ public function label(): string
             self::TwoFactorDisabled => '2要素認証の無効化',
             self::EmailChanged => 'メールアドレス変更',
             self::AccountDeleted => 'アカウント削除',
+            self::AccountDeletionRequested => '退会の予約',
+            self::AccountDeletionCancelled => '退会予約の取消',
             self::SocialAccountLinked => 'ソーシャルアカウント連携',
             self::OwnershipTransferred => '組織オーナー移譲',
             self::ApiKeyIssued => 'API キー発行',
diff --git a/app/Http/Controllers/Settings/AccountDeletionRequestController.php b/app/Http/Controllers/Settings/AccountDeletionRequestController.php
new file mode 100644
index 0000000..d317768
--- /dev/null
+++ b/app/Http/Controllers/Settings/AccountDeletionRequestController.php
@@ -0,0 +1,54 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Controllers\Settings;
+
+use App\Http\Controllers\Controller;
+use App\Models\User;
+use App\Services\Organization\OrganizationMembershipService;
+use Illuminate\Http\RedirectResponse;
+use Illuminate\Http\Request;
+use Webmozart\Assert\Assert;
+
+/**
+ * 退会の予約 (猶予期間つき削除) と、その取消。
+ *
+ * 対象は常に `$request->user()` **自身**である。route に他者を指せる parameter が 1 つも無く、
+ * 他人のアカウントへ到達する経路がコード上存在しない (`ControllerAuthorizationGateTest` の
+ * `SelfScopedResource` で登録済み)。
+ *
+ * ★**予約 (store) には step-up (recent-auth) を課し、取消 (destroy) には課さない**。
+ *   取消は**誤操作救済の本体**であり、救済経路に関門を足すと「取り消せない」詰みの再生産になる
+ *   (取消は権限を増やす操作ではない)。
+ *   受け入れるリスク: セッション奪取者が予約を取り消せる。しかし奪取者が取り消しても
+ *   失われるのは「退会の意思」だけで、本人は再度予約できる。逆に取消に関門を付けると
+ *   **本人が救済できない**方が重い被害になる。これは設計判断である。
+ */
+final class AccountDeletionRequestController extends Controller
+{
+    public function store(Request $request, OrganizationMembershipService $membership): RedirectResponse
+    {
+        $user = $request->user();
+        Assert::isInstanceOf($user, User::class);
+
+        // ブロッカーは評価しない (予約は意思表示であって削除ではない)。権威判定は執行時。
+        $state = $membership->requestAccountDeletion($user);
+
+        // 操作系 POST は back() で完結させる (禁止事項 7: intended() を使わない)
+        return back()->with(
+            'success',
+            "退会を予約しました。{$state->purgeAfterLabel()}までは取り消せます。",
+        );
+    }
+
+    public function destroy(Request $request, OrganizationMembershipService $membership): RedirectResponse
+    {
+        $user = $request->user();
+        Assert::isInstanceOf($user, User::class);
+
+        $membership->cancelAccountDeletion($user);
+
+        return back()->with('success', '退会の予約を取り消しました。');
+    }
+}
diff --git a/app/Http/Controllers/Settings/ProfileController.php b/app/Http/Controllers/Settings/ProfileController.php
index 4118e8b..564f9cc 100644
--- a/app/Http/Controllers/Settings/ProfileController.php
+++ b/app/Http/Controllers/Settings/ProfileController.php
@@ -4,10 +4,12 @@
 
 namespace App\Http\Controllers\Settings;
 
+use App\DataTransferObjects\Account\AccountDeletionStateDto;
 use App\DataTransferObjects\Organizations\AccountDeletionBlockerDto;
 use App\Http\Controllers\Controller;
 use App\Models\User;
 use App\Services\Organization\OrganizationMembershipService;
+use App\Support\Account\AccountDeletionGrace;
 use Illuminate\Http\Request;
 use Inertia\Inertia;
 use Inertia\Response;
@@ -31,6 +33,12 @@ public function index(Request $request, OrganizationMembershipService $membershi
                 ->map(fn (AccountDeletionBlockerDto $blocker): array => $blocker->toArray())
                 ->values()
                 ->all(),
+            // 退会予約 (猶予期間つき削除) の状態。予約中なら取消バナーを出し、削除ボタン群は隠す
+            // (凍結 middleware が settings.account.destroy を遮断していることと UI を一致させる)。
+            'accountDeletionState' => AccountDeletionStateDto::fromUser($user)->toArray(),
+            // 未予約時の主導線ラベル (「N 日後に削除」) に使う。猶予日数の出典はサーバの SSOT
+            // 1 箇所だけで、クライアントに日数を literal で持たせない。
+            'accountDeletionGraceDays' => AccountDeletionGrace::days(),
             // パスワードカードの出し分け。password 未設定ユーザーに current_password 必須の
             // 変更フォームを出すと必ず失敗する (踏破不能 UI) ため、初回設定フォームへ切り替える。
             'hasPassword' => $user->hasPassword(),
diff --git a/app/Http/Middleware/EnsureAccountNotPendingDeletion.php b/app/Http/Middleware/EnsureAccountNotPendingDeletion.php
new file mode 100644
index 0000000..e02d575
--- /dev/null
+++ b/app/Http/Middleware/EnsureAccountNotPendingDeletion.php
@@ -0,0 +1,72 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Middleware;
+
+use App\DataTransferObjects\Account\AccountDeletionStateDto;
+use App\Enums\Account\AccountDeletionFreezeAllowance;
+use App\Models\User;
+use Closure;
+use Illuminate\Http\Request;
+use Symfony\Component\HttpFoundation\Response;
+
+/**
+ * 退会予約中 (猶予期間つき削除・**凍結方式**) のアクセス制限。alias: `not-pending-deletion`。
+ *
+ * users 行の生死は変えず (SoftDeletes を使わない)、予約中は業務面を止めて
+ * **取消と、退会ブロッカーの解消**だけを通す。通す route は
+ * {@see AccountDeletionFreezeAllowance} の **exact case** のみ (deny-by-default・wildcard 禁止)。
+ *
+ * ★**403 で突き放さない**。遮断時は取消ボタンのある `/settings` へ 302 する
+ *   (AGENTS.md ドメイン規約 4 と同じ思想 = 行き先のない詰みを作らない)。
+ *   遮断理由の flash は積まない — 理由は着地ページ (/settings の予約バナー) が持つ
+ *   (課金ゲート `RequireActiveSubscription` と同じ契約)。
+ *
+ * ★**実行位置は `bootstrap/app.php` の priority list が正本**で、テナント境界 404
+ *   (`EnsureProjectBelongsToCurrentOrganization`) より**必ず後**に置く。前に置くと
+ *   「他組織に実在 = 302 / 不在 = 404」の 1 bit 存在オラクルになる
+ *   (AGENTS.md セキュリティ不変条件 10)。
+ *
+ * ★配線は `routes/web.php` の group への**直付け**である。`RouteMiddlewareBinder` の後付けは
+ *   使わない (route cache 済みの起動では 1 本も効かず、無音で保護が外れる = T135)。
+ *
+ * ★母集団の外 (= 凍結されない): Fortify / Passkeys が登録するログイン・ログアウト・
+ *   パスワード再設定・メール確認・2FA challenge・passkey ログインと `session.status`。
+ *   **認証回復と離脱の手段は構造的に凍結されない**。
+ */
+final class EnsureAccountNotPendingDeletion
+{
+    /** 凍結中に遮断されたことを機械可読に伝える文言 (JSON/XHR の 409 本文)。 */
+    public const FROZEN_MESSAGE = '退会予約中のため、この操作はできません。設定画面から退会を取り消してください。';
+
+    /**
+     * @param  Closure(Request): Response  $next
+     */
+    public function handle(Request $request, Closure $next): Response
+    {
+        $user = $request->user();
+        if (! $user instanceof User) {
+            return $next($request); // 未認証は auth middleware の責務 (ここでは判定しない)
+        }
+
+        if (! AccountDeletionStateDto::fromUser($user)->isPending()) {
+            return $next($request);
+        }
+
+        $name = $request->route()?->getName();
+        if ($name !== null && AccountDeletionFreezeAllowance::tryFrom($name) !== null) {
+            return $next($request);
+        }
+
+        // JSON/XHR は 409 Conflict (状態が操作と矛盾している)。課金ゲートの 402 とは別事由。
+        if ($request->expectsJson()) {
+            abort(Response::HTTP_CONFLICT, self::FROZEN_MESSAGE);
+        }
+
+        // 直前の flash (他画面の success/error) を着地先まで保つ。理由の flash は積まない。
+        $request->session()->reflash();
+
+        return redirect()->route('settings');
+    }
+}
diff --git a/app/Models/User.php b/app/Models/User.php
index c52780a..c5feef0 100644
--- a/app/Models/User.php
+++ b/app/Models/User.php
@@ -6,6 +6,7 @@
 
 use App\Enums\OrganizationRole;
 use App\Enums\TwoFactorStatus;
+use Carbon\CarbonImmutable;
 use Database\Factories\UserFactory;
 use Illuminate\Contracts\Auth\MustVerifyEmail;
 use Illuminate\Database\Eloquent\Factories\HasFactory;
@@ -27,6 +28,15 @@
 use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;
 use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;
 
+/**
+ * T142: 退会予約 (猶予期間つき削除・凍結方式) の予約列。**users 行の生死は変えない**ため
+ * SoftDeletes は使わず、両列が揃っているときだけ「予約中」とみなす
+ * (状態機械は DB の CHECK 制約 users_deletion_request_pair_check が閉じている)。
+ * 保護列であり $fillable 外 (forceFill でのみ書く)。
+ *
+ * @property CarbonImmutable|null $deletion_requested_at
+ * @property CarbonImmutable|null $deletion_purge_after
+ */
 class User extends Authenticatable implements CipherSweetEncrypted, LaratrustUser, MustVerifyEmail, OAuthenticatable, PasskeyUser
 {
     // Passport OAuth guard (mcp-oauth / api-oauth) が withAccessToken() / token() を要求する
@@ -200,6 +210,11 @@ protected function casts(): array
             'email_verified_at' => 'datetime',
             'terms_accepted_at' => 'datetime',
             'two_factor_confirmed_at' => 'datetime',
+            // 退会予約 (猶予期間つき削除)。**immutable_datetime** を使う
+            // (AccountDeletionStateDto が CarbonImmutable 前提。'datetime' だと mutable Carbon が
+            // 返り DTO の型と食い違う)。$fillable には入れない = forceFill でのみ書く保護列。
+            'deletion_requested_at' => 'immutable_datetime',
+            'deletion_purge_after' => 'immutable_datetime',
             'password' => 'hashed',
         ];
     }
diff --git a/app/Notifications/Account/AccountDeletionRequestedNotification.php b/app/Notifications/Account/AccountDeletionRequestedNotification.php
new file mode 100644
index 0000000..04ab4bc
--- /dev/null
+++ b/app/Notifications/Account/AccountDeletionRequestedNotification.php
@@ -0,0 +1,89 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Notifications\Account;
+
+use App\DataTransferObjects\Account\AccountDeletionStateDto;
+use App\Models\User;
+use Carbon\CarbonImmutable;
+use Illuminate\Bus\Queueable;
+use Illuminate\Contracts\Queue\ShouldQueue;
+use Illuminate\Notifications\Messages\MailMessage;
+use Illuminate\Notifications\Notification;
+use Illuminate\Support\Facades\Config;
+
+/**
+ * 退会 (猶予期間つき削除) を予約したことのメール通知。
+ *
+ * 本人が意図していない予約 (セッション奪取 / 誤操作) に**気づく**ための経路であり、
+ * 取消の期日と導線を必ず載せる。
+ *
+ * 【`ShouldQueue` + 予約 tx 内 dispatch】
+ * AGENTS.md ドメイン規約 11 に従い、業務状態の保存とキュー投入は同一トランザクション内で行う
+ * (`afterCommit` に依存しない)。`ShouldBeUnique` は使わない — unique lock は dispatch 時に
+ * 取得され rollback で解放されないため業務 tx 内 dispatch と両立しない
+ * (`AutoRechargeTriggerJob` から撤去済みの先例がある)。送達台帳も新設しない。
+ *
+ * 【保証範囲 (誇張しない)】
+ * 保証するのは **「予約操作からの job 生成は最大 1 件」**だけである
+ * (`OrganizationMembershipService::requestAccountDeletion()` が予約中なら冪等 no-op で
+ * 通知を発火しないため、二重 POST でも job は 1 つしか作られない)。
+ * **job の実行と外部配送は重複しうる best-effort** — 外部メールサービスが受理した後に
+ * worker が完了記録の前で停止すれば retry で再送されうる。「at-most-once」ではないし、
+ * 「同一 payload の job を 2 つ投入しても 1 通」でもない。
+ */
+final class AccountDeletionRequestedNotification extends Notification implements ShouldQueue
+{
+    use Queueable;
+
+    public function __construct(
+        private readonly CarbonImmutable $requestedAt,
+        private readonly CarbonImmutable $purgeAfter,
+    ) {}
+
+    /**
+     * 送信直前に予約の生存を再確認する。**これは誤通知の防止であって dedup ではない**。
+     *
+     * dispatch の位置だけでは誤通知を防げない — 「dispatch がどこか」と「job が参照する状態・
+     * 実行可能時点」は別問題である。aicue は `QueueDispatchAtomicityGuard` が
+     * driver=database / キュー DB = 業務 DB / after_commit=false を全環境の起動時に
+     * fail-closed 検査するため commit 前実行は構造的に起きないが、**それは前提であって
+     * 保証ではない**。
+     *
+     * ★**フォールバックしない**。`fresh()` が null = 執行済みで user 行が無い、という意味なので、
+     *   シリアライズ済みの削除前スナップショットへ倒すと「執行済みなのに送る」逆転が起きる。
+     *
+     * @return list<string>
+     */
+    public function via(object $notifiable): array
+    {
+        if (! $notifiable instanceof User) {
+            return [];
+        }
+
+        $fresh = $notifiable->fresh();
+        if (! $fresh instanceof User) {
+            return [];
+        }
+
+        return AccountDeletionStateDto::fromUser($fresh)->matches($this->requestedAt, $this->purgeAfter)
+            ? ['mail']
+            : [];
+    }
+
+    public function toMail(object $notifiable): MailMessage
+    {
+        $appName = Config::string('app.name');
+        $deadline = $this->purgeAfter->format('Y年n月j日 H:i');
+
+        return (new MailMessage)
+            ->subject('【'.$appName.'】退会のお手続きを受け付けました')
+            ->line("{$appName} の退会 (アカウント削除) を受け付けました。")
+            ->line("削除を実行する予定日時: {$deadline}")
+            ->line('それまでは設定画面からいつでも取り消せます。心当たりがない場合は、'
+                .'取り消したうえでパスワードの変更をご検討ください。')
+            ->action('退会を取り消す', route('settings'))
+            ->line('削除後はデータを復元できません。');
+    }
+}
diff --git a/app/Notifications/InApp/AccountDeletionRequestedNotification.php b/app/Notifications/InApp/AccountDeletionRequestedNotification.php
new file mode 100644
index 0000000..b3f9b55
--- /dev/null
+++ b/app/Notifications/InApp/AccountDeletionRequestedNotification.php
@@ -0,0 +1,46 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Notifications\InApp;
+
+use App\DataTransferObjects\Notification\AccountDeletionRequestedPayload;
+use App\Enums\Notification\NotificationType;
+
+/**
+ * 退会予約 (猶予期間つき削除) のアプリ内通知。
+ *
+ * 凍結中でも `notifications.index` は allowlist で通るため、「いつ消えるか」を
+ * 本人が読める経路として機能する (メールが届かない環境の保険)。
+ *
+ * ★`organizationId` は**予約時点の current org** である。退会そのものは組織に属さない事象だが、
+ *   本アプリのアプリ内通知は org 文脈を必須とする設計 (`AppNotification::organizationId()` が
+ *   non-nullable) のため、表示の文脈として current org を写す。current org を持たない
+ *   ユーザーには**アプリ内通知を作らない** (メールだけが届く) —
+ *   `NotificationCenterService` 側で判定する。
+ */
+final class AccountDeletionRequestedNotification extends AppNotification
+{
+    public function __construct(
+        private readonly int $organizationId,
+        private readonly AccountDeletionRequestedPayload $payload,
+    ) {}
+
+    public function type(): NotificationType
+    {
+        return NotificationType::AccountDeletionRequested;
+    }
+
+    public function organizationId(): int
+    {
+        return $this->organizationId;
+    }
+
+    /**
+     * @return array<string, int|string|bool|null>
+     */
+    public function toDatabase(object $notifiable): array
+    {
+        return $this->payload->toArray();
+    }
+}
diff --git a/app/Services/Notification/NotificationCenterService.php b/app/Services/Notification/NotificationCenterService.php
index 5d6935a..203d2bf 100644
--- a/app/Services/Notification/NotificationCenterService.php
+++ b/app/Services/Notification/NotificationCenterService.php
@@ -4,6 +4,8 @@
 
 namespace App\Services\Notification;
 
+use App\DataTransferObjects\Account\AccountDeletionStateDto;
+use App\DataTransferObjects\Notification\AccountDeletionRequestedPayload;
 use App\DataTransferObjects\Notification\InvitationReceivedPayload;
 use App\DataTransferObjects\Notification\ManualJobPayload;
 use App\DataTransferObjects\Notification\TicketBalanceLowPayload;
@@ -16,10 +18,12 @@
 use App\Models\RenderJob;
 use App\Models\User;
 use App\Models\VideoManual;
+use App\Notifications\InApp\AccountDeletionRequestedNotification;
 use App\Notifications\InApp\InvitationReceivedNotification;
 use App\Notifications\InApp\ManualAnalyzedNotification;
 use App\Notifications\InApp\ManualRenderedNotification;
 use App\Notifications\InApp\TicketBalanceLowNotification;
+use Carbon\CarbonImmutable;
 use Illuminate\Database\Eloquent\ModelNotFoundException;
 use Illuminate\Notifications\DatabaseNotification;
 use Illuminate\Pagination\LengthAwarePaginator;
@@ -31,8 +35,12 @@
  * アプリ内通知センターの唯一の窓口 (発火・読み出し・既読化)。概念設計 20260711-2255。
  *
  * 発火の設計上の位置づけ:
- * - すべて既存 exactly-once 遷移 (terminal tx / org 行ロック) の **commit 後** に呼ばれる
+ * - ジョブ / 招待 / 残高系はすべて既存 exactly-once 遷移 (terminal tx / org 行ロック) の
+ *   **commit 後** に呼ばれる
  *   (terminal tx 内に通知 insert を入れない = 通知失敗がジョブ結果を rollback しない)
+ * - **例外は退会予約 (notifyAccountDeletionRequested) の 1 本**で、こちらは予約の書き込みと
+ *   同一 tx 内から呼ばれる (予約が rollback したら通知も残らないのが正しい)。
+ *   失敗の吸収は同じく safely() が行う
  * - 配信保証は at-most-once (重複なし・欠落あり得る)。正はジョブ status + 既存ポーリング UI
  *   であり通知は補助チャネル (outbox 台帳は作らない。詳細設計「配信保証仕様」)
  * - 宛先・内容・organization_id は DB relation からの再解決のみ (payload 不信任)
@@ -147,6 +155,37 @@ public function notifyTicketBalanceLow(Organization $organization, int $balance,
         });
     }
 
+    /**
+     * 退会予約 (猶予期間つき削除) の気づき通知。
+     *
+     * ★呼び出し位置は **予約の書き込みと同一 tx 内** (他の発火とは違う)。予約が rollback したら
+     *   通知も残らないのが正しい状態であるため。
+     * ★アプリ内通知は org 文脈を必須とする (`AppNotification::organizationId()` が
+     *   non-nullable)。退会は組織に属さない事象なので、**予約時点の current org** を表示文脈として
+     *   写す。current org を持たないユーザーには**作らない** (メールだけが届く。
+     *   org 文脈を捏造しない)。
+     */
+    public function notifyAccountDeletionRequested(User $user, CarbonImmutable $purgeAfter): void
+    {
+        $this->safely(function () use ($user, $purgeAfter): void {
+            $organizationId = $user->current_organization_id;
+            if (! is_int($organizationId)) {
+                return;
+            }
+
+            $state = AccountDeletionStateDto::fromUser($user);
+            $graceDays = $state->graceDays();
+            if ($graceDays === null) {
+                return; // 予約が成立していない (呼び出し順の異常) ときは作らない
+            }
+
+            $user->notify(new AccountDeletionRequestedNotification(
+                $organizationId,
+                new AccountDeletionRequestedPayload($purgeAfter->toIso8601String(), $graceDays),
+            ));
+        });
+    }
+
     // ── 読み出し・既読化 (NotificationController から委譲) ────────────────────
 
     /**
diff --git a/app/Services/Organization/OrganizationMembershipService.php b/app/Services/Organization/OrganizationMembershipService.php
index 796d7f0..5576327 100644
--- a/app/Services/Organization/OrganizationMembershipService.php
+++ b/app/Services/Organization/OrganizationMembershipService.php
@@ -4,6 +4,7 @@
 
 namespace App\Services\Organization;
 
+use App\DataTransferObjects\Account\AccountDeletionStateDto;
 use App\DataTransferObjects\Invitations\PendingInvitationForUserDto;
 use App\DataTransferObjects\Organizations\AccountDeletionBlockerDto;
 use App\Enums\AccountDeletionBlockReason;
@@ -13,11 +14,14 @@
 use App\Models\Organization;
 use App\Models\OrganizationInvitation;
 use App\Models\User;
+use App\Notifications\Account\AccountDeletionRequestedNotification;
 use App\Notifications\OrganizationInvitationNotification;
 use App\Services\Billing\AccountDeletionBillingGuard;
 use App\Services\Notification\NotificationCenterService;
 use App\Services\Project\DefaultProjectResolver;
 use App\Services\Security\SecurityEventRecorder;
+use App\Support\Account\AccountDeletionGrace;
+use Carbon\CarbonImmutable;
 use Illuminate\Contracts\Session\Session;
 use Illuminate\Database\Eloquent\Builder;
 use Illuminate\Database\Eloquent\Model;
@@ -650,6 +654,106 @@ public function transferOwnership(Organization $organization, User $from, User $
         ]);
     }
 
+    /**
+     * 退会の予約 (猶予期間つき削除)。**凍結方式**なので users 行の生死は変えない。
+     *
+     * 冪等: 既に予約中なら **`purge_after` を延長せず**既存の予約をそのまま返す
+     * (二重送信で猶予が伸び続けるのを防ぐ。取消 → 再予約は明示操作)。
+     * この冪等 no-op が「予約操作からの通知 job 生成は最大 1 件」の一回性も担う
+     * (AGENTS.md ドメイン規約 6: 結果の一回性は永続状態遷移が担う)。
+     *
+     * **予約時にブロッカーを評価しない**。予約は退会の意思表示であって削除ではなく、
+     * ブロックされている人が予約すらできないと「解約待ちの間は退会予約もできない」詰みになる。
+     * 権威判定は執行時 (deleteAccount のロック下再評価) が担う。
+     *
+     * @return AccountDeletionStateDto 予約後の状態 (通知とレスポンスが同じ値を見る)
+     */
+    public function requestAccountDeletion(User $user): AccountDeletionStateDto
+    {
+        return DB::transaction(function () use ($user): AccountDeletionStateDto {
+            // canonical 共通ロック境界 (users 昇順 → organizations 昇順)。organizations は不要だが
+            // 順序の起点を deleteAccount と揃える (新しいロック順序を作らない)。
+            $this->lockForMembershipWrite([$this->keyOf($user)], []);
+
+            $fresh = $user->fresh();
+            Assert::isInstanceOf($fresh, User::class);
+
+            $state = AccountDeletionStateDto::fromUser($fresh);
+            if ($state->isPending()) {
+                return $state; // 冪等 no-op (延長しない / 通知も発火しない)
+            }
+
+            // 秒精度で確定させる (DB の timestamp(0) と in-memory 値のズレで
+            // 通知側の一致検査 matches() が偽陰性にならないようにする)。
+            $requestedAt = CarbonImmutable::now()->startOfSecond();
+            // 猶予日数の解決は AccountDeletionGrace 1 箇所だけ。Service は config を直読しない。
+            $purgeAfter = AccountDeletionGrace::purgeAfter($requestedAt);
+            $fresh->forceFill([
+                'deletion_requested_at' => $requestedAt,
+                'deletion_purge_after' => $purgeAfter,
+            ])->save();
+
+            $this->recorder->record(SecurityEventType::AccountDeletionRequested, $fresh);
+
+            // AGENTS.md ドメイン規約 11: 業務状態の保存とキュー投入は**同一トランザクション内**で
+            // 行う (afterCommit に依存しない)。通知側は送信直前に予約の生存を再確認する。
+            $fresh->notify(new AccountDeletionRequestedNotification($requestedAt, $purgeAfter));
+            $this->notifications->notifyAccountDeletionRequested($fresh, $purgeAfter);
+
+            return AccountDeletionStateDto::fromUser($fresh);
+        });
+    }
+
+    /**
+     * 退会予約の取消。**誤操作救済の本体**であり、ブロッカーの有無に関わらず必ず成功する。
+     * 冪等: 予約が無ければ no-op。
+     */
+    public function cancelAccountDeletion(User $user): AccountDeletionStateDto
+    {
+        return DB::transaction(function () use ($user): AccountDeletionStateDto {
+            $this->lockForMembershipWrite([$this->keyOf($user)], []);
+
+            $fresh = $user->fresh();
+            Assert::isInstanceOf($fresh, User::class);
+
+            if (! AccountDeletionStateDto::fromUser($fresh)->isPending()) {
+                return AccountDeletionStateDto::fromUser($fresh); // 冪等 no-op
+            }
+
+            $fresh->forceFill([
+                'deletion_requested_at' => null,
+                'deletion_purge_after' => null,
+            ])->save();
+
+            $this->recorder->record(SecurityEventType::AccountDeletionCancelled, $fresh);
+
+            return AccountDeletionStateDto::fromUser($fresh);
+        });
+    }
+
+    /**
+     * 予約の執行 (日次バッチ専用)。**期限到来をロック下で再確認してから**既存の
+     * `deleteAccount()` をそのまま呼ぶ (判定コードを分岐させない = 課金ガードの
+     * ロック下再評価をそのまま継承する)。
+     *
+     * @return bool true = 削除した / false = 期限未到来 or 予約が消えていた (抽出後の取消)
+     *
+     * @throws ValidationException 退会ブロッカーが立っている (呼び出し側が「業務上の保留」として捌く)
+     */
+    public function executeAccountDeletionRequest(User $user): bool
+    {
+        $executed = false;
+
+        $this->deleteAccount($user, null, function (User $locked) use (&$executed): bool {
+            // deleteAccount のロック取得後・ガード評価**前**に呼ばれる前提条件フック。
+            $executed = AccountDeletionStateDto::fromUser($locked)->isDue(CarbonImmutable::now());
+
+            return $executed;
+        });
+
+        return $executed;
+    }
+
     /**
      * 退会をブロックしている組織と理由。
      *
@@ -788,13 +892,19 @@ private function keyOf(Model $model): int
      * (ブロックされたユーザーはログアウトされない)。**フックは例外を投げてはならない**
      * (投げると削除トランザクション全体が rollback する)。
      *
+     * $precondition はロック取得直後・**ガード評価前**に呼ばれる前提条件フックである。
+     * false を返すと**ブロッカー判定に入らず**削除もせずに正常終了する。日次執行バッチが
+     * 「抽出後に取り消された予約」を検出する口で、ここでブロッカー例外を出さないのは
+     * 「取消済みユーザーを業務上の保留と誤分類しない」ためである (null なら常に true)。
+     *
      * @param  (\Closure(): void)|null  $beforeDelete  例外を投げないこと (投げると削除全体が rollback)
+     * @param  (\Closure(User): bool)|null  $precondition  ロック取得直後・ガード評価前の前提条件
      *
      * @throws ValidationException 唯一 Owner かつ (他メンバーが残る ∨ 生きた課金責務がある) 組織がある
      */
-    public function deleteAccount(User $user, ?\Closure $beforeDelete = null): void
+    public function deleteAccount(User $user, ?\Closure $beforeDelete = null, ?\Closure $precondition = null): void
     {
-        DB::transaction(function () use ($user, $beforeDelete): void {
+        DB::transaction(function () use ($user, $beforeDelete, $precondition): void {
             // 1. 対象 User 行を最初にロック (この後の所属列挙を安定させる。列挙前に user を
             //    ロックしないと、列挙〜user ロック取得の間に別 txn が新組織 B の Owner を user へ
             //    移譲し、B を未検査のまま削除する race が残る)。
@@ -818,6 +928,14 @@ public function deleteAccount(User $user, ?\Closure $beforeDelete = null): void
             // 3. ロック下で述語を再評価 (fresh。事前取得値は信用しない。null フォールバック禁止)
             $freshUser = $user->fresh();
             Assert::isInstanceOf($freshUser, User::class);
+
+            // 3a. 前提条件フック (ロック下・**ブロッカー判定より前**)。false = 削除しないで正常終了。
+            //     ★判定の**前**でなければならない: 後ろに置くと、抽出後に予約を取り消した
+            //       ユーザーに対してブロッカー例外が出て、バッチが「保留」と誤分類する。
+            if ($precondition !== null && $precondition($freshUser) !== true) {
+                return;
+            }
+
             $blockers = $this->organizationsBlockingDeletion($freshUser);
             if ($blockers->isNotEmpty()) {
                 // Inertia の resolveValidationErrors() は field ごとに先頭 1 件しかクライアントへ
diff --git a/app/Support/Account/AccountDeletionGrace.php b/app/Support/Account/AccountDeletionGrace.php
new file mode 100644
index 0000000..8fcd614
--- /dev/null
+++ b/app/Support/Account/AccountDeletionGrace.php
@@ -0,0 +1,61 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Account;
+
+use Carbon\CarbonImmutable;
+use Webmozart\Assert\Assert;
+
+/**
+ * 退会 (アカウント削除) の猶予日数 (config/account.php) への**唯一の解決点 (SSOT)**。
+ *
+ * 猶予日数は「環境ごとに変えてよい運用値」ではなく、利用者に対して
+ * 「いつまで取り消せるか」を約束する値である。読む場所が分岐すると
+ * 「画面が案内した期日」と「日次バッチが実際に消す期日」が静かにズレるため、
+ * `config('account.deletion_grace_days')` を読んでよいのは本クラス 1 箇所だけとし、
+ * それを `tests/Architecture/AccountDeletionGraceConfigTest.php` が deny-by-default で
+ * 機械固定する (テストクラスへの参照は app → tests の import を生むため {@see} では書かない)。
+ *
+ * - 状態も DB 参照も持たない (設定アクセサ + 純粋な日付計算のみ)。
+ * - 0 以下は設定漏れであり、そのまま `purgeAfter()` を計算すると**予約時刻以前**が期限になる
+ *   = 予約した瞬間に期限到来 = 猶予ゼロで物理削除される。よって **fail-fast** する。
+ */
+final class AccountDeletionGrace
+{
+    /**
+     * 猶予日数。
+     *
+     * @throws \InvalidArgumentException 未設定 / 非整数 / 0 以下のとき
+     */
+    public static function days(): int
+    {
+        /** @var mixed $days */
+        $days = config('account.deletion_grace_days');
+        Assert::integer($days, 'config(account.deletion_grace_days) must be an int.');
+        Assert::greaterThan($days, 0, 'config(account.deletion_grace_days) must be positive.');
+
+        return $days;
+    }
+
+    /**
+     * 予約時刻から執行期限 (これを過ぎたら日次バッチが物理削除する時刻) を導く。
+     * 要件は「**暦日 30 日**」。
+     *
+     * ★**`addDaysNoOverflow` は使わない**。`NoOverflow` の意味は「上位単位 (月) を越えない」で
+     *   あり、日加算に *NoOverflow の意味論を持ち込むと月末で丸められて 30 日未満になりうる
+     *   (猶予期間の意味そのものが壊れ、「30 日は取り消せます」という案内が嘘になる)。
+     *   **実測 (T142 の mutation M22)**: 本リポジトリの Carbon では `addDaysNoOverflow` は
+     *   そもそも**存在しない** (`BadMethodCallException`) ため、設計が想定した「静かに 28 日になる」
+     *   壊れ方は起きず、即座に例外になる。したがって現実の危険は *NoOverflow ではなく
+     *   **月単位の式へ書き換えること** (`addMonth()` 等) の側にある。
+     * ★AGENTS.md の実装規約と `CarbonOverflowArithmeticGateTest` の禁止語彙は
+     *   **月・年・四半期**が対象で、日は母集団に入っていない (gate の定数を実読して確認)。
+     *   よって「暦日 30 日」であることの保証は `AccountDeletionGraceConfigTest` の
+     *   behavioral 検査 (2026-01-31 + 30 日 = 2026-03-02) が担う。
+     */
+    public static function purgeAfter(CarbonImmutable $requestedAt): CarbonImmutable
+    {
+        return $requestedAt->addDays(self::days());
+    }
+}
diff --git a/bootstrap/app.php b/bootstrap/app.php
index 14955c5..f82bdf6 100644
--- a/bootstrap/app.php
+++ b/bootstrap/app.php
@@ -7,6 +7,7 @@
 use App\Http\Middleware\BlockTwoFactorDisableForEnforcedOrganizations;
 use App\Http\Middleware\BughuntCoverageMiddleware;
 use App\Http\Middleware\EnforceMcpTransport;
+use App\Http\Middleware\EnsureAccountNotPendingDeletion;
 use App\Http\Middleware\EnsureEmailIsVerifiedOrBack;
 use App\Http\Middleware\EnsureLoginMethodRemains;
 use App\Http\Middleware\EnsureProjectBelongsToApiOrganization;
@@ -166,6 +167,10 @@
             // 現在の付与先は passkey.login-options (WebAuthn challenge を載せる guest route)
             'no-store' => NoStoreResponse::class,
             'require-active-subscription' => RequireActiveSubscription::class,
+            // 退会予約中 (猶予期間つき削除・凍結方式) のアクセス制限。
+            // 通す route は AccountDeletionFreezeAllowance の exact case のみ (deny-by-default)。
+            // **実行位置は下の priority list が正本** (テナント境界 404 より後)。
+            'not-pending-deletion' => EnsureAccountNotPendingDeletion::class,
             // `verified` の web POST 向け代替。未認証時に back + error flash で元ページへ戻す
             // (context 別文言は EmailVerificationGateContext)。organizations.store /
             // organizations.invitations.store に withoutMiddleware('verified') とセットで付与。
@@ -249,6 +254,12 @@
             [NoStoreCacheHeadersForAuthenticatedPages::class, EncryptHistory::class],
             [EncryptHistory::class, EnsureEmailIsVerified::class],
             [EnsureEmailIsVerified::class, RequireActiveSubscription::class],
+            // 退会予約中の凍結。**302 で短絡する**ため、テナント境界 404
+            // (EnsureProjectBelongsToCurrentOrganization) より必ず後に置く。前に置くと
+            // 「他組織に実在 = 302 / 不在 = 404」の 1 bit 存在オラクルになる
+            // (AGENTS.md セキュリティ不変条件 10)。課金ゲートの直後に置き、未契約組織の
+            // ユーザーは 課金ゲート → onboarding → 凍結 → /settings の 2 hop で取消 UI に着く。
+            [RequireActiveSubscription::class, EnsureAccountNotPendingDeletion::class],
         ] as [$after, $append]) {
             $middleware->appendToPriorityList($after, $append);
         }
diff --git a/config/account.php b/config/account.php
new file mode 100644
index 0000000..cc30e7c
--- /dev/null
+++ b/config/account.php
@@ -0,0 +1,31 @@
+<?php
+
+declare(strict_types=1);
+
+/*
+|--------------------------------------------------------------------------
+| Account Configuration
+|--------------------------------------------------------------------------
+|
+| 退会 (アカウント削除) の猶予期間つき削除に関する設定。
+|
+*/
+
+return [
+
+    /*
+    | 退会 (アカウント削除) の猶予日数。**env を使わない** — 環境ごとに変えてよい運用値ではなく、
+    | オーナーが確定したプロダクト判断であり、利用者に「いつまで取り消せるか」を約束する値である
+    | (config/legal.php の billing_retention_years / config/idempotency.php の
+    | retention_hours と同じ理由)。
+    |
+    | 唯一の解決点は App\Support\Account\AccountDeletionGrace で、Service / Command / 画面は
+    | config を直読しない (直読は AccountDeletionGraceConfigTest が deny-by-default で禁止する)。
+    |
+    | この値の変更は**既に入っている予約には遡及しない**。予約は users.deletion_purge_after に
+    | **絶対時刻**で確定するため、変更後の値が効くのは以後の新規予約だけである
+    | (不可逆な物理削除の期日を後から動かさないための設計)。
+    */
+    'deletion_grace_days' => 30,
+
+];
diff --git a/database/factories/UserFactory.php b/database/factories/UserFactory.php
index cb232db..69ccdba 100644
--- a/database/factories/UserFactory.php
+++ b/database/factories/UserFactory.php
@@ -5,6 +5,8 @@
 namespace Database\Factories;
 
 use App\Models\User;
+use App\Support\Account\AccountDeletionGrace;
+use Carbon\CarbonImmutable;
 use Illuminate\Database\Eloquent\Factories\Factory;
 use Illuminate\Support\Collection;
 use Illuminate\Support\Facades\Hash;
@@ -62,6 +64,25 @@ public function ssoOnly(): static
         ]);
     }
 
+    /**
+     * 退会予約中 (凍結方式) のユーザー。**users 行の生死は変えない**ので、埋めるのは予約列 2 本だけ。
+     *
+     * 両列は同時に埋まる (DB の CHECK 制約 users_deletion_request_pair_check が片列だけを拒否する)。
+     * `$purgeAfter` 未指定なら猶予日数の SSOT (AccountDeletionGrace) から導出する
+     * = テストが猶予日数を独自に持たない。
+     */
+    public function pendingDeletion(?CarbonImmutable $requestedAt = null, ?CarbonImmutable $purgeAfter = null): static
+    {
+        return $this->state(function (array $attributes) use ($requestedAt, $purgeAfter): array {
+            $requested = $requestedAt ?? CarbonImmutable::now();
+
+            return [
+                'deletion_requested_at' => $requested,
+                'deletion_purge_after' => $purgeAfter ?? AccountDeletionGrace::purgeAfter($requested),
+            ];
+        });
+    }
+
     /**
      * 2FA 有効・confirmed 状態のユーザーを生成する。
      *
diff --git a/database/migrations/2026_08_10_000200_add_deletion_request_columns_to_users_table.php b/database/migrations/2026_08_10_000200_add_deletion_request_columns_to_users_table.php
new file mode 100644
index 0000000..a181b31
--- /dev/null
+++ b/database/migrations/2026_08_10_000200_add_deletion_request_columns_to_users_table.php
@@ -0,0 +1,85 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Query\Builder;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Schema;
+
+/**
+ * T142 (標準形 v1 / 裁定 AG-128 の必須 (2)): 猶予期間つき退会 (**凍結方式**) の予約列。
+ *
+ * ★**SoftDeletes は使わない**。users 行の生死を変えないのが凍結方式の定義で、
+ *   FK cascade / nullOnDelete / CipherSweet の blind index (email_index) の一意照合 /
+ *   passkey / OAuth セッション / 招待の email 照合が、すべて users 行の実在を前提にしている。
+ *
+ * ★`deletion_purge_after` は **絶対時刻**で持つ (猶予日数のスナップショットにしない)。
+ *   不可逆な物理削除のため config 変更を既予約へ遡及させてはならず、絶対時刻なら 1 列で
+ *   それが表現でき、日次バッチのクエリも `deletion_purge_after <= now()` の 1 条件で済む。
+ *   猶予日数は `purge_after - requested_at` から導出する (2 つの表現を持たない)。
+ *
+ * ★**状態機械を DB で閉じる**。片列だけの非正規状態になると `isPending()` が false になり、
+ *   凍結を通過し、`cancelAccountDeletion()` も no-op で解消できず、日次バッチが毎日
+ *   FAILURE を出し続ける (検出はできても解消できない)。アプリ層だけでなく CHECK 制約で防ぐ。
+ */
+return new class extends Migration
+{
+    private const string PAIR_CONSTRAINT = 'users_deletion_request_pair_check';
+
+    private const string ORDER_CONSTRAINT = 'users_deletion_purge_after_order_check';
+
+    public function up(): void
+    {
+        // precondition 検査 (非破壊 = SELECT のみ)。新規列なので理論上 0 件だが、
+        // 「制約追加 migration は既存データを壊しうる」という一般則に従って明示する。
+        // 列がまだ無い時点では検査不能なので、列追加の**後**・制約追加の**前**に置く。
+        Schema::table('users', function (Blueprint $table): void {
+            $table->timestamp('deletion_requested_at')->nullable()->after('remember_token');
+            $table->timestamp('deletion_purge_after')->nullable()->after('deletion_requested_at');
+            // 日次バッチの走査条件 (deletion_purge_after <= now())。
+            // 部分 index (WHERE NOT NULL) は pgsql 固有の書き方になるため、まず素の index で入れる
+            // (予約中ユーザーは常に極少数。性能問題が出てから絞る = 思考原則 2)。
+            $table->index('deletion_purge_after');
+        });
+
+        $nonNormalized = DB::table('users')
+            ->where(function (Builder $query): void {
+                $query->whereNull('deletion_requested_at')->whereNotNull('deletion_purge_after');
+            })
+            ->orWhere(function (Builder $query): void {
+                $query->whereNotNull('deletion_requested_at')->whereNull('deletion_purge_after');
+            })
+            ->orWhereColumn('deletion_purge_after', '<', 'deletion_requested_at')
+            ->count();
+        if ($nonNormalized > 0) {
+            // 件数だけを出す (user id / email は出さない = PII 非出力)。
+            throw new RuntimeException(
+                "退会予約列が非正規な行が既に存在するため CHECK 制約を張れません: count={$nonNormalized}",
+            );
+        }
+
+        DB::statement(
+            'ALTER TABLE users ADD CONSTRAINT '.self::PAIR_CONSTRAINT.' CHECK ('
+            .'(deletion_requested_at IS NULL AND deletion_purge_after IS NULL)'
+            .' OR (deletion_requested_at IS NOT NULL AND deletion_purge_after IS NOT NULL))',
+        );
+        // 両列 non-null だが期限が予約時刻より前、という別の非正規状態も防ぐ
+        DB::statement(
+            'ALTER TABLE users ADD CONSTRAINT '.self::ORDER_CONSTRAINT.' CHECK ('
+            .'deletion_purge_after IS NULL OR deletion_purge_after >= deletion_requested_at)',
+        );
+    }
+
+    public function down(): void
+    {
+        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS '.self::ORDER_CONSTRAINT);
+        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS '.self::PAIR_CONSTRAINT);
+
+        Schema::table('users', function (Blueprint $table): void {
+            $table->dropIndex(['deletion_purge_after']);
+            $table->dropColumn(['deletion_requested_at', 'deletion_purge_after']);
+        });
+    }
+};
diff --git a/resources/js/components/features/notifications/NotificationListItem.svelte b/resources/js/components/features/notifications/NotificationListItem.svelte
index 24efcb6..2503eff 100644
--- a/resources/js/components/features/notifications/NotificationListItem.svelte
+++ b/resources/js/components/features/notifications/NotificationListItem.svelte
@@ -1,10 +1,11 @@
 <script lang="ts">
     import { tick, type Component } from "svelte";
     import { router } from "@inertiajs/svelte";
-    import { Bell, Check, FileSearch, Film, Mail, TicketMinus } from "@lucide/svelte";
+    import { Bell, Check, FileSearch, Film, Mail, TicketMinus, UserRoundX } from "@lucide/svelte";
     import Badge from "@/components/atoms/Badge.svelte";
     import { addToast } from "@/lib/stores/toast";
     import type {
+        AccountDeletionRequestedPayload,
         InvitationReceivedPayload,
         ManualJobPayload,
         NotificationItem,
@@ -55,6 +56,12 @@
             : null,
     );
 
+    const deletionPayload = $derived(
+        notification.type === "account_deletion_requested" && notification.payload !== null
+            ? (notification.payload as AccountDeletionRequestedPayload)
+            : null,
+    );
+
     const icon = $derived.by<Component>(() => {
         switch (notification.type) {
             case "manual_analyzed":
@@ -65,6 +72,8 @@
                 return Mail;
             case "ticket_balance_low":
                 return TicketMinus;
+            case "account_deletion_requested":
+                return UserRoundX;
             default:
                 return Bell;
         }
@@ -83,6 +92,9 @@
         if (balancePayload) {
             return `チケット残高が残り ${balancePayload.balance} 枚になりました`;
         }
+        if (deletionPayload) {
+            return "退会のお手続きを受け付けました";
+        }
         // 未知 type / payload 復元失敗の fallback (rawType をそのまま出す)
         return notification.type;
     });
@@ -99,6 +111,9 @@
         if (balancePayload) {
             return `通知の目安 (${balancePayload.threshold} 枚) を下回りました。チケットを追加購入できます`;
         }
+        if (deletionPayload) {
+            return `${formatDate(deletionPayload.purge_after)} に削除されます。設定画面からいつでも取り消せます`;
+        }
         return null;
     });
 
@@ -109,6 +124,13 @@
         return null;
     });
 
+    /** ISO8601 を「YYYY年M月D日」表記へ。解釈できない値は空文字 (fallback 描画に倒す) */
+    function formatDate(iso: string): string {
+        const date = new Date(iso);
+        if (Number.isNaN(date.getTime())) return "";
+        return date.toLocaleDateString("ja-JP", { year: "numeric", month: "long", day: "numeric" });
+    }
+
     /** 相対時刻 (分/時間/日)。7 日超は日付表示 */
     function relativeTime(iso: string): string {
         const date = new Date(iso);
diff --git a/resources/js/pages/Settings/Index.svelte b/resources/js/pages/Settings/Index.svelte
index 08558e3..45be048 100644
--- a/resources/js/pages/Settings/Index.svelte
+++ b/resources/js/pages/Settings/Index.svelte
@@ -17,13 +17,17 @@
     import { withRecentAuth, type RecentAuthStatus } from "@/lib/recent-auth";
     import { Settings } from "@lucide/svelte";
     import type { SharedProps } from "@/lib/shared-props";
-    import type { AccountDeletionBlocker } from "@/types/account";
+    import type { AccountDeletionBlocker, AccountDeletionState } from "@/types/account";
 
     // ページ専用 props 型 (SharedProps を継承しページ固有 prop を足す。多重キャスト排除)。
     // SharedProps に errors フィールドは無く、Inertia が別途注入するため継承衝突しない。
     interface SettingsPageProps extends SharedProps {
         /** 退会をブロックしている組織と次の一手 (表示時点のスナップショット) */
         accountDeletionBlockers?: AccountDeletionBlocker[];
+        /** 退会予約 (猶予期間つき削除) の状態。欠落 = 未予約として扱う */
+        accountDeletionState?: AccountDeletionState;
+        /** 未予約時の主導線ラベル用の猶予日数 (サーバの SSOT 由来。欠落 = 日数を出さない) */
+        accountDeletionGraceDays?: number;
         /** password が設定済みか。欠落 = 状態不明 (既定値に倒さない。下記 passwordState 参照) */
         hasPassword?: boolean;
         errors?: Record<string, string | string[]>;
@@ -32,6 +36,30 @@
     const props = $derived(page.props as unknown as SettingsPageProps);
     const appName = $derived(props.appName ?? "");
     const accountDeletionBlockers = $derived(props.accountDeletionBlockers ?? []);
+    const accountDeletionState = $derived(props.accountDeletionState ?? null);
+    /** 予約中か。両方揃っているときだけ true (PHP 側 AccountDeletionStateDto::isPending() と同義) */
+    const pendingDeletion = $derived(
+        accountDeletionState !== null &&
+            accountDeletionState.requestedAt !== null &&
+            accountDeletionState.purgeAfter !== null,
+    );
+    const purgeAfterLabel = $derived.by((): string => {
+        const iso = accountDeletionState?.purgeAfter;
+        if (!iso) return "";
+        const date = new Date(iso);
+        if (Number.isNaN(date.getTime())) return "";
+        return date.toLocaleString("ja-JP", {
+            year: "numeric",
+            month: "long",
+            day: "numeric",
+            hour: "2-digit",
+            minute: "2-digit",
+        });
+    });
+    /** 主導線ラベルの日数。予約中は予約時の導出値、未予約は現行 config 由来の日数 */
+    const graceDays = $derived(
+        accountDeletionState?.graceDays ?? props.accountDeletionGraceDays ?? null,
+    );
 
     /** 別組織へ切り替える導線の失敗表示 (押したのに何も起きない = 詰みを作らない) */
     let switchError = $state<string | null>(null);
@@ -153,6 +181,8 @@
 
     let deleteDialogOpen = $state(false);
     let deleting = $state(false);
+    let requestingDeletion = $state(false);
+    let cancellingDeletion = $state(false);
 
     /* ---- recent-auth (step-up) precheck。stale なら再認証モーダルを挟んで再開する ---- */
     let recentAuthOpen = $state(false);
@@ -176,6 +206,45 @@
         action?.();
     }
 
+    /**
+     * 退会の予約 (猶予つき削除)。UI の主導線。
+     * 即時削除と同水準の機微操作なのでサーバ側 recent-auth が最終ゲート。precheck を挟む。
+     * ブロッカーがあっても disabled にしない (禁止事項 8) — 予約自体はサーバが受理する。
+     */
+    function requestAccountDeletion(): void {
+        guardWithRecentAuth(() => {
+            router.post(
+                "/settings/account/deletion-request",
+                {},
+                {
+                    preserveScroll: true,
+                    onStart: () => {
+                        requestingDeletion = true;
+                    },
+                    onFinish: () => {
+                        requestingDeletion = false;
+                    },
+                },
+            );
+        });
+    }
+
+    /**
+     * 退会予約の取消 (誤操作救済の本体)。**step-up を挟まない** —
+     * 救済経路に関門を足すと「取り消せない」詰みの再生産になる (サーバ側も同じ契約)。
+     */
+    function cancelAccountDeletion(): void {
+        router.delete("/settings/account/deletion-request", {
+            preserveScroll: true,
+            onStart: () => {
+                cancellingDeletion = true;
+            },
+            onFinish: () => {
+                cancellingDeletion = false;
+            },
+        });
+    }
+
     // アカウント削除は recent-auth (step-up) 必須。precheck で鮮度を確認してから送る
     function deleteAccount(): void {
         guardWithRecentAuth(() => {
@@ -379,15 +448,58 @@
                 {#if accountError}
                     <Alert type="danger" class="mb-3">{accountError}</Alert>
                 {/if}
-                <Button
-                    variant="danger-outline"
-                    onclick={() => {
-                        deleteDialogOpen = true;
-                    }}
-                    testId="delete-account-button"
-                >
-                    アカウントを削除
-                </Button>
+                {#if pendingDeletion}
+                    <!-- 予約中は削除ボタン群を出さず、バナー (取消 + 次の一手) だけを出す。
+                         サーバ側でも settings.account.destroy は凍結で遮断されており、
+                         「押せるのに必ず失敗するボタン」を残さない。 -->
+                    <Alert
+                        type="warning"
+                        title="退会を予約しています"
+                        testId="deletion-request-banner"
+                        class="mb-3"
+                    >
+                        <p data-testid="deletion-request-purge-after">
+                            {purgeAfterLabel} に、このアカウントとすべてのデータを削除します。
+                        </p>
+                        <p class="mt-1">
+                            それまではいつでも取り消せます。上に「対応が必要」と出ている場合は削除できないため、
+                            毎日 1 回自動で削除を再試行します。
+                        </p>
+                        {#snippet action()}
+                            <Button
+                                onclick={cancelAccountDeletion}
+                                loading={cancellingDeletion}
+                                testId="cancel-deletion-request-button"
+                            >
+                                退会を取り消す
+                            </Button>
+                        {/snippet}
+                    </Alert>
+                {:else}
+                    <div class="flex flex-col items-start gap-3">
+                        <Button
+                            variant="danger"
+                            onclick={requestAccountDeletion}
+                            loading={requestingDeletion}
+                            testId="request-deletion-button"
+                        >
+                            {graceDays === null
+                                ? "退会する (取り消せます)"
+                                : `${graceDays}日後に削除 (取り消せます)`}
+                        </Button>
+                        <!-- 副導線: 即時削除 (取り消せない)。testId は既存のまま変えない -->
+                        <Button
+                            variant="danger-ghost"
+                            size="sm"
+                            onclick={() => {
+                                deleteDialogOpen = true;
+                            }}
+                            testId="delete-account-button"
+                        >
+                            今すぐ完全に削除する (取り消せません)
+                        </Button>
+                    </div>
+                {/if}
             </DangerZone>
         </div>
 
diff --git a/resources/js/types/account.ts b/resources/js/types/account.ts
index f73b587..d1b4f72 100644
--- a/resources/js/types/account.ts
+++ b/resources/js/types/account.ts
@@ -18,3 +18,18 @@ export interface AccountDeletionBlocker {
     slug: string;
     actions: AccountDeletionBlockerAction[];
 }
+
+/**
+ * 退会予約 (猶予期間つき削除・凍結方式) の状態。
+ *
+ * PHP: App\DataTransferObjects\Account\AccountDeletionStateDto::toArray() と対。
+ * 3 値すべてが null なら「予約なし」。graceDays は purgeAfter - requestedAt の導出値で、
+ * サーバ側が唯一の出典 (クライアントで日数を計算し直さない)。
+ */
+export interface AccountDeletionState {
+    /** ISO8601。null = 未予約 */
+    requestedAt: string | null;
+    /** ISO8601。null = 未予約 */
+    purgeAfter: string | null;
+    graceDays: number | null;
+}
diff --git a/resources/js/types/notification.ts b/resources/js/types/notification.ts
index 0c6cf54..78f51b0 100644
--- a/resources/js/types/notification.ts
+++ b/resources/js/types/notification.ts
@@ -10,7 +10,8 @@ export type NotificationType =
     | "manual_analyzed"
     | "manual_rendered"
     | "invitation_received"
-    | "ticket_balance_low";
+    | "ticket_balance_low"
+    | "account_deletion_requested";
 
 /** 解析/レンダ完了通知の payload (manual_analyzed / manual_rendered 共用) */
 export interface ManualJobPayload {
@@ -32,6 +33,13 @@ export interface TicketBalanceLowPayload {
     threshold: number;
 }
 
+/** 退会予約 (猶予期間つき削除) を受け付けたことの通知 payload */
+export interface AccountDeletionRequestedPayload {
+    /** ISO8601。予約した時点の削除予定日時 (取消後も通知履歴には残る) */
+    purge_after: string;
+    grace_days: number;
+}
+
 /**
  * 通知一覧の 1 行。type を discriminant にした union。
  * 未知 type (enum⇔TS の一時的ドリフト) は string として受け、fallback 描画する。
@@ -45,7 +53,12 @@ export interface NotificationItem {
     /** ISO8601 */
     created_at: string;
     /** サーバ側の検証復元に失敗した場合は null (fallback 描画) */
-    payload: ManualJobPayload | InvitationReceivedPayload | TicketBalanceLowPayload | null;
+    payload:
+        | ManualJobPayload
+        | InvitationReceivedPayload
+        | TicketBalanceLowPayload
+        | AccountDeletionRequestedPayload
+        | null;
 }
 
 /** HandleInertiaRequests が共有する notifications props */
diff --git a/routes/console.php b/routes/console.php
index 3d37884..2b034b8 100644
--- a/routes/console.php
+++ b/routes/console.php
@@ -106,6 +106,20 @@
 */
 Schedule::command('inquiry:purge --apply')->daily();
 
+/*
+|--------------------------------------------------------------------------
+| 退会予約の執行 (猶予期間つき削除)
+|--------------------------------------------------------------------------
+| deletion_purge_after を過ぎた退会予約を執行する。判定は既存の
+| OrganizationMembershipService::deleteAccount() が行う (課金ガードのロック下再評価を
+| そのまま継承する)。退会ブロッカーは**業務上の保留**として次へ進み、想定外例外があれば
+| FAILURE で終わる (全件 DB 障害を SUCCESS で隠さない)。
+|
+| **監視対象**: 本コマンドの終了コードと report()。
+| 取消は利用者が /settings からいつでも行える (誤操作救済の本体)。
+*/
+Schedule::command('account:purge-deletion-requests --apply')->daily()->onOneServer();
+
 /*
 |--------------------------------------------------------------------------
 | AI 解析 cron
diff --git a/routes/web.php b/routes/web.php
index 590535f..a267210 100644
--- a/routes/web.php
+++ b/routes/web.php
@@ -45,6 +45,7 @@
 use App\Http\Controllers\Seo\RobotsController;
 use App\Http\Controllers\Seo\SitemapController;
 use App\Http\Controllers\Settings\AccountController;
+use App\Http\Controllers\Settings\AccountDeletionRequestController;
 use App\Http\Controllers\Settings\PasswordSetupController;
 use App\Http\Controllers\Settings\ProfileController;
 use App\Http\Controllers\Settings\SecurityController;
@@ -176,7 +177,16 @@
 | 認証済み
 |--------------------------------------------------------------------------
 */
-Route::middleware(['auth', 'verified'])->group(function (): void {
+/*
+| 退会予約中 (猶予期間つき削除・凍結方式) の凍結対象は **この group 全体**である
+| (`not-pending-deletion` = deny-by-default)。通す route は
+| App\Enums\Account\AccountDeletionFreezeAllowance の exact case のみで、
+| 母集団の内外と allowlist の一致は AccountDeletionFreezeRouteGateTest が固定する。
+| ログイン・ログアウト・パスワード再設定・メール確認・2FA challenge・passkey ログインは
+| **この group の外**にあるため、認証回復と離脱の手段は構造的に凍結されない。
+| 実行位置 (テナント境界 404 より後) の正本は bootstrap/app.php の priority list。
+*/
+Route::middleware(['auth', 'verified', 'not-pending-deletion'])->group(function (): void {
     // ログイン直後の着地点 (課金ゲート外のまま。未契約でも状況把握と復帰導線を提供)
     Route::get('/dashboard', DashboardController::class)->name('dashboard');
 
@@ -210,11 +220,22 @@
     // 2FA / ソーシャル連携 / パスキーの管理面 (passkey 一覧の組み立てに DI が要るため Controller)
     Route::get('/settings/security', SecurityController::class)->name('settings.security');
 
-    // アカウント削除は step-up (recent-auth) 必須
+    // アカウント削除 (即時・取り消せない) は step-up (recent-auth) 必須。
+    // 猶予期間つきの予約 (下記) が UI の主導線で、こちらは**副導線として併存**させる
+    // (標準形 v1 は「猶予つき予約と即時削除の両方」を必須にしている)。
     Route::delete('/settings/account', [AccountController::class, 'destroy'])
         ->middleware('recent-auth')
         ->name('settings.account.destroy');
 
+    // 退会の予約 (猶予 30 日)。**UI の主導線**。即時削除と同水準の機微操作のため step-up 必須。
+    Route::post('/settings/account/deletion-request', [AccountDeletionRequestController::class, 'store'])
+        ->middleware('recent-auth')
+        ->name('settings.account.deletion-request.store');
+    // 退会予約の取消。**誤操作救済の本体**なので step-up を課さない
+    // (救済経路に関門を足すと「取り消せない」詰みの再生産になる。取消は権限を増やす操作ではない)。
+    Route::delete('/settings/account/deletion-request', [AccountDeletionRequestController::class, 'destroy'])
+        ->name('settings.account.deletion-request.destroy');
+
     /*
     | 組織。`{organization}` / `{organization:slug}` は MembershipScopedOrganizationBinder
     | (AppServiceProvider で Route::bind 登録) が「認証済みユーザーが所属する組織」に
diff --git a/tests/Architecture/AccountDeletionFreezeRouteGateTest.php b/tests/Architecture/AccountDeletionFreezeRouteGateTest.php
new file mode 100644
index 0000000..efb4c9b
--- /dev/null
+++ b/tests/Architecture/AccountDeletionFreezeRouteGateTest.php
@@ -0,0 +1,296 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Account\AccountDeletionFreezeAllowance;
+use App\Http\Middleware\EnsureAccountNotPendingDeletion;
+use App\Models\User;
+use App\Services\Billing\PortalConfigurationSpec;
+use App\Support\Account\AccountDeletionGrace;
+use Carbon\CarbonImmutable;
+use Illuminate\Http\Request;
+use Illuminate\Routing\Route as RoutingRoute;
+use Illuminate\Routing\Router;
+use Symfony\Component\HttpFoundation\Response;
+
+/*
+ * Architecture invariant: 退会予約中の**凍結 (deny-by-default)** の母集団と allowlist を固定する。
+ *
+ * SoT = devnotes/20260809-0908-account-deletion-grace/detailed-design.md の PR-B (B4)。
+ *
+ * 記号: `U` = 凍結 middleware (EnsureAccountNotPendingDeletion) が付いた全 route、
+ *       `A` = AccountDeletionFreezeAllowance の route 名集合。**`A ⊆ U`**。
+ *
+ * ★この gate が保証するもの:
+ *   - 検査 1: `A ⊆ U` (allowlist に `U` 外の route 名を書けない = 死に登録を作らない)
+ *   - 検査 2: enum の route 名が**実在し、凍結 middleware を実際に持つ**
+ *   - 検査 3: **middleware が実際に bypass する集合と `A` が exact-fit** (実装と宣言の一致)
+ *   - 検査 4: **`U` に無名 route があれば fail** (名前で allowlist を書けないため)
+ *   - 検査 5: enum は wildcard (`*`) を持たない / 各 case の `rationale()` が 30 文字以上
+ *   - 検査 6: 母集団の内外を両方向で固定する
+ *       (a) `logout` / `session.status` が `U` に**含まれない** (認証回復・離脱を凍結させない)
+ *       (b) `recent-auth.confirm` / `.status` / `.password` が `U` に**含まれる**
+ *           (group の外へ移されたら allowlist が死に登録になる)
+ *   - 検査 7: **`BillingPortal` を allowlist に置く前提の pin** —
+ *       `PortalConfigurationSpec` の `subscription_update.enabled === false` /
+ *       `subscription_cancel.enabled === true` / `subscription_cancel.mode === 'at_period_end'`。
+ *       `billing:ensure-portal-configuration --verify` が保証するのは「Stripe 側設定と spec の
+ *       **一致**」だけなので、**spec 自体を書き換えると正しい設定として受け入れられうる**。
+ *       よって allowlist 登録の前提を behavioral に固定する
+ *       (`ThrottleExemptionPremiseTest` / `IdempotencyExemptionPremiseTest` と同じ作法)
+ *   - 検査 8: **即時削除 / 通知 open / 自動チャージ更新が `A` に無い**ことの名指し pin
+ *   - 空振り検知: `U` の件数 floor / `A` の件数 exact / 母集団 0 件で fail
+ *
+ * ★この gate が保証しないもの (誇張しない):
+ *   - **実 HTTP での遮断挙動**は見ない (route を実際に叩く全件 sweep と、
+ *     取消・ブロッカー解消への到達性は `tests/Feature/Auth/AccountDeletionFreezeTest.php` の担当。
+ *     Architecture lane は DB を使わないため、ここでは middleware を直接駆動して
+ *     bypass 集合だけを測る)
+ *   - **middleware の実行順序**は見ない (テナント境界 404 より後であることの固定は
+ *     `TenantBoundaryOrderingTest` の担当)
+ *   - route cache 済み起動での配線 (group 直付けなので cache に焼き込まれるが、
+ *     「毎デプロイ route:cache を再生成する」運用要件そのものは機械化できない)
+ *
+ * DB 不使用 (Architecture lane は TestCase のみ)。
+ */
+
+/** `U` の件数下限 (空振り防止)。業務 route が丸ごと group から外れたら赤くなる。 */
+const FREEZE_POPULATION_FLOOR = 60;
+
+/** `A` の件数 (exact-fit。増減させたらこの数値も同じ diff で書き換わる)。 */
+const FREEZE_ALLOWANCE_COUNT = 16;
+
+/**
+ * 凍結 middleware が付いた route (`U`)。名前をキーにする。
+ *
+ * @return array<string, RoutingRoute>
+ */
+function freezePopulation(): array
+{
+    /** @var Router $router */
+    $router = app('router');
+    $routes = $router->getRoutes();
+    $routes->refreshNameLookups();
+
+    $population = [];
+    foreach ($routes as $route) {
+        if (! in_array(EnsureAccountNotPendingDeletion::class, $route->gatherMiddleware(), true)
+            && ! in_array('not-pending-deletion', $route->gatherMiddleware(), true)) {
+            continue;
+        }
+        $population[(string) $route->getName()] = $route;
+    }
+
+    return $population;
+}
+
+/** 凍結 middleware が付いた route のうち**名前を持たない**もの (uri で返す)。 */
+function freezeUnnamedRouteUris(): array
+{
+    /** @var Router $router */
+    $router = app('router');
+    $unnamed = [];
+    foreach ($router->getRoutes() as $route) {
+        if (! in_array(EnsureAccountNotPendingDeletion::class, $route->gatherMiddleware(), true)
+            && ! in_array('not-pending-deletion', $route->gatherMiddleware(), true)) {
+            continue;
+        }
+        if ($route->getName() === null || $route->getName() === '') {
+            $unnamed[] = $route->uri();
+        }
+    }
+
+    return $unnamed;
+}
+
+/** 予約中 (pending) の未保存 User を組み立てる (DB を使わない)。 */
+function freezePendingUser(): User
+{
+    $user = new User;
+    $requestedAt = CarbonImmutable::now();
+    $user->forceFill([
+        'id' => 1,
+        'deletion_requested_at' => $requestedAt,
+        'deletion_purge_after' => AccountDeletionGrace::purgeAfter($requestedAt),
+    ]);
+
+    return $user;
+}
+
+/**
+ * 凍結 middleware を route 名 1 つに対して駆動し、$next が呼ばれた (= 通過した) かを返す。
+ *
+ * 実 HTTP を経由しないのは Architecture lane が DB を持たないため。ここで測るのは
+ * 「middleware 単体の分岐」だけで、配線込みの挙動は Feature テストが見る。
+ */
+function freezeMiddlewarePasses(string $routeName, User $user): bool
+{
+    $request = Request::create('/architecture-probe', 'GET');
+    $request->setLaravelSession(app('session.store'));
+    $request->setUserResolver(static fn (): User => $user);
+
+    $route = new RoutingRoute(['GET'], '/architecture-probe', static fn (): string => 'ok');
+    $route->name($routeName);
+    $request->setRouteResolver(static fn (): RoutingRoute => $route);
+
+    $passed = false;
+    (new EnsureAccountNotPendingDeletion)->handle($request, static function () use (&$passed): Response {
+        $passed = true;
+
+        return new Response('ok');
+    });
+
+    return $passed;
+}
+
+test('検査 1: allowlist は凍結母集団 U の部分集合である (A ⊆ U)', function (): void {
+    $population = array_keys(freezePopulation());
+    $outside = array_values(array_diff(AccountDeletionFreezeAllowance::values(), $population));
+
+    expect($outside)->toBe([],
+        'AccountDeletionFreezeAllowance に、凍結 middleware が付いていない route 名が登録されています。'
+        .'凍結されない route を allowlist に書いても意味がなく (死に登録)、'
+        .'「通している」という誤った安心を生みます: '.implode(', ', $outside));
+});
+
+test('検査 2: allowlist の route 名は実在し、実際に凍結 middleware を持つ', function (): void {
+    /** @var Router $router */
+    $router = app('router');
+    $routes = $router->getRoutes();
+    $routes->refreshNameLookups();
+
+    $missing = [];
+    $unprotected = [];
+    foreach (AccountDeletionFreezeAllowance::cases() as $case) {
+        $route = $routes->getByName($case->value);
+        if ($route === null) {
+            $missing[] = $case->value;
+
+            continue;
+        }
+        $middleware = $route->gatherMiddleware();
+        if (! in_array(EnsureAccountNotPendingDeletion::class, $middleware, true)
+            && ! in_array('not-pending-deletion', $middleware, true)) {
+            $unprotected[] = $case->value;
+        }
+    }
+
+    expect($missing)->toBe([], '実在しない route 名が allowlist に残っています: '.implode(', ', $missing));
+    expect($unprotected)->toBe([], '凍結対象でない route が allowlist にあります: '.implode(', ', $unprotected));
+});
+
+test('検査 3: middleware が実際に bypass する集合と allowlist が exact-fit である', function (): void {
+    $user = freezePendingUser();
+
+    $passing = [];
+    foreach (array_keys(freezePopulation()) as $name) {
+        if (freezeMiddlewarePasses($name, $user)) {
+            $passing[] = $name;
+        }
+    }
+    sort($passing);
+
+    $declared = AccountDeletionFreezeAllowance::values();
+    sort($declared);
+
+    expect($passing)->toBe($declared,
+        '宣言 (enum) と実装 (middleware の分岐) がずれています。'
+        .'wildcard や prefix 一致を実装に入れていないか確認してください。');
+});
+
+test('検査 4: 凍結母集団に無名 route が無い (名前で allowlist を書けないため)', function (): void {
+    expect(freezeUnnamedRouteUris())->toBe([],
+        '凍結対象の group に無名 route があります。allowlist は route 名でしか書けないため、'
+        .'無名 route は「絶対に通せない route」になります (救済経路なら詰みを生みます)。');
+});
+
+test('検査 5: allowlist に wildcard が無く、各 case の根拠が 30 文字以上ある', function (): void {
+    $wildcards = [];
+    $short = [];
+    foreach (AccountDeletionFreezeAllowance::cases() as $case) {
+        if (str_contains($case->value, '*')) {
+            $wildcards[] = $case->value;
+        }
+        $rationale = $case->rationale();
+        if (mb_strlen($rationale) < 30) {
+            $short[] = "{$case->value}: ".mb_strlen($rationale).' 文字';
+        }
+    }
+
+    expect($wildcards)->toBe([],
+        'wildcard を許すと namespace 単位で通ってしまい (billing.* が購入・新規契約まで含む)、'
+        .'凍結の意味が消えます: '.implode(', ', $wildcards));
+    expect($short)->toBe([], '通す根拠は 30 文字以上で書くこと: '.implode(' / ', $short));
+});
+
+test('検査 6: 母集団の内外を両方向で固定する (認証回復は凍結しない / step-up は凍結対象)', function (): void {
+    $population = array_keys(freezePopulation());
+
+    // ★`toContain` は message 引数を取らない (第 2 引数が「もう 1 つの needle」として扱われ、
+    //   期待と違う判定になる)。in_array の真偽で判定して message を付ける。
+    // (a) 認証回復と離脱の手段は構造的に凍結されない。group の中へ移されたら fail。
+    $frozenRecovery = array_values(array_intersect(
+        ['logout', 'login', 'session.status', 'password.request', 'verification.notice'],
+        $population,
+    ));
+    expect($frozenRecovery)->toBe([],
+        '認証回復・離脱の手段が凍結対象になっています。凍結すると「ログアウトすらできない」'
+        .'詰みになります: '.implode(', ', $frozenRecovery));
+
+    // (b) step-up の 3 本は group の中にある (外へ移されたら allowlist が死に登録になる)。
+    $stepUp = ['recent-auth.confirm', 'recent-auth.status', 'recent-auth.password'];
+    $escaped = array_values(array_diff($stepUp, $population));
+    expect($escaped)->toBe([],
+        'step-up の route が凍結母集団から外れました。allowlist の該当 case は死に登録になるため、'
+        .'enum 側も同じ差分で見直してください: '.implode(', ', $escaped));
+});
+
+test('検査 7: billing.portal を allowlist に置く前提 (PortalConfigurationSpec) を pin する', function (): void {
+    // ★`billing:ensure-portal-configuration --verify` が見るのは「Stripe 側設定と spec の一致」
+    //   だけなので、**spec 自体**を書き換えると verify は緑のまま前提だけが壊れる。
+    //   「Portal は責務を減らす方向のみ」という allowlist の根拠をここで固定する。
+    $features = PortalConfigurationSpec::features();
+
+    expect($features['subscription_update']['enabled'])->toBeFalse(
+        'Portal からプラン変更ができるようになると、凍結中に**新しい課金責務を作れる**ため '
+        .'billing.portal を allowlist に置いた前提が崩れます。');
+    expect($features['subscription_cancel']['enabled'])->toBeTrue(
+        'Portal で解約できないと、退会ブロッカー (生きた課金責務) を解消する導線が消えます。');
+    expect($features['subscription_cancel']['mode'] ?? null)->toBe('at_period_end');
+});
+
+test('検査 8: 猶予を迂回しうる route が allowlist に無い (名指しの pin)', function (): void {
+    $declared = AccountDeletionFreezeAllowance::values();
+
+    // 即時削除: 予約中に通すと「30 日猶予」を 1 手で迂回できる (誤操作救済が無効化される)
+    expect($declared)->not->toContain('settings.account.destroy');
+    // 通知 open: 遷移先へ 303 で飛ばすため、通すと業務 route / dashboard への抜け道になる
+    expect($declared)->not->toContain('notifications.open');
+    // 自動チャージ設定: 有効化・閾値変更を同じ endpoint が受けるため新しい課金責務の入口になる
+    expect($declared)->not->toContain('billing.auto-recharge.update');
+    expect($declared)->not->toContain('billing.auto-recharge.setup');
+    // 新規契約・スポット購入・組織/招待の新規作成も責務を増やす方向なので通さない
+    expect($declared)->not->toContain('billing.checkout');
+    expect($declared)->not->toContain('billing.tickets.checkout');
+    expect($declared)->not->toContain('organizations.store');
+    expect($declared)->not->toContain('organizations.invitations.store');
+});
+
+test('空振り検知: 母集団と allowlist の件数を pin する', function (): void {
+    $population = freezePopulation();
+
+    expect(count($population))->toBeGreaterThan(FREEZE_POPULATION_FLOOR,
+        '凍結母集団が想定より小さいです。group から middleware が外れていないか確認してください '
+        .'(母集団が 0 件でも検査 1 は緑になるため、下限で pin します)。');
+    expect(AccountDeletionFreezeAllowance::cases())->toHaveCount(FREEZE_ALLOWANCE_COUNT,
+        '通す route を増減させたら FREEZE_ALLOWANCE_COUNT も同じ差分で書き換えてください '
+        .'(「以下」ではなく「一致」で固定するのは、根拠なしに通せる余裕枠を作らないため)。');
+
+    // 検査 3 の駆動器が死んでいたら vacuous green になる。正負を 1 本ずつ実測する。
+    $user = freezePendingUser();
+    expect(freezeMiddlewarePasses('settings', $user))->toBeTrue();
+    expect(freezeMiddlewarePasses('dashboard', $user))->toBeFalse();
+
+    // 未予約ユーザーは何も凍結されない (middleware が常時 deny になっていないことの対照)
+    expect(freezeMiddlewarePasses('dashboard', new User))->toBeTrue();
+});
diff --git a/tests/Architecture/AccountDeletionGraceConfigTest.php b/tests/Architecture/AccountDeletionGraceConfigTest.php
new file mode 100644
index 0000000..0ae9e71
--- /dev/null
+++ b/tests/Architecture/AccountDeletionGraceConfigTest.php
@@ -0,0 +1,277 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Support\Account\AccountDeletionGrace;
+use Carbon\CarbonImmutable;
+
+/*
+ * Architecture invariant: 退会 (アカウント削除) の猶予日数 (account.deletion_grace_days) の
+ * **解決点は App\Support\Account\AccountDeletionGrace の 1 箇所だけ**である。
+ *
+ * SoT = devnotes/20260809-0908-account-deletion-grace/detailed-design.md の PR-B (B0)
+ * とオーナー決定 (猶予期間 = 30 日)。
+ *
+ * 背景: 猶予日数は「環境ごとに変えてよい運用値」ではなく、利用者に対して
+ * 「いつまで取り消せるか」を約束する値である。読む場所が分岐すると
+ * 「画面が案内した期日」と「バッチが実際に消す期日」が静かにズレる。
+ * よって (a) env を使わない (b) config を読むのは SSOT クラス 1 箇所だけ、を機械固定する。
+ * (config/legal.php の billing_retention_years / config/idempotency.php の
+ * retention_hours と同じ理由・同じ形。)
+ *
+ * ★この gate が保証するもの:
+ *   - 検査 1: `'account.deletion_grace_days'` を読むのは AccountDeletionGrace だけ
+ *     (app/ config/ database/ routes/ を走査)
+ *   - 検査 2: config/account.php の値が **整数リテラル** (env() を挟まない) かつ
+ *     オーナー決定の **30** である
+ *   - 検査 3: 実行時の `AccountDeletionGrace::days()` が config リテラルと一致する
+ *   - 検査 4: 0 以下なら **fail-fast** する (fail-open にしない)
+ *   - 検査 5: 空振り検知 (走査ファイル数 / token 数が 0 でない) と
+ *     正の自己検証 (SSOT ファイルで検出器が実際に点灯する)
+ *   - 検査 6: 負のコントロール (fixture ソースで点灯 / コメント中の表記は点灯しない)
+ *   - 検査 7-9 (**behavioral**): `purgeAfter()` が**暦日 30 日**であること
+ *     (月末丸め無し / うるう年跨ぎ / アプリ TZ 下のローカル時刻)
+ *
+ * ★この gate が保証しないもの (誇張しない):
+ *   - **tests/ は走査しない**。fail-fast (0 以下) の検証は config を書き換える必要があり、
+ *     そこを禁止すると検査そのものが書けなくなる
+ *   - 動的キー組み立て (`config('account.'.$key)`) には沈黙する (実測 0 件)
+ *   - 既に予約済みのユーザーへの遡及有無は本 gate の担当ではない
+ *     (users.deletion_purge_after が**絶対時刻**であることが構造的な答えで、
+ *      その挙動は tests/Feature/Auth/AccountDeletionGraceTest が固定する)
+ *
+ * 検出方式は BillingRetentionConfigSingleSourceTest / LegalConsentVersionSingleSourceTest と
+ * 同じ token 走査 (regex にすると本ファイルの説明コメント自身で偽赤になる)。DB 不使用。
+ */
+
+/** 設定キー: SSOT だけが読んでよい。 */
+const ACCOUNT_GRACE_CONFIG_KEY = 'account.deletion_grace_days';
+
+/** config/account.php 内での素のキー名。 */
+const ACCOUNT_GRACE_CONFIG_BARE_KEY = 'deletion_grace_days';
+
+/** 単一出典クラス (repo ルート相対)。 */
+const ACCOUNT_GRACE_SOURCE_FILE = 'app/Support/Account/AccountDeletionGrace.php';
+
+/** オーナー決定の猶予日数 (逸脱不可)。 */
+const ACCOUNT_GRACE_OWNER_DECIDED_DAYS = 30;
+
+/**
+ * 1 ソースを走査して出現数を返す (純関数 = 負のコントロールから直接呼べる)。
+ *
+ * @return array{configKey: int, tokens: int}
+ */
+function accountGraceScanSource(string $source): array
+{
+    $result = ['configKey' => 0, 'tokens' => 0];
+
+    foreach (token_get_all($source) as $token) {
+        if (! is_array($token)) {
+            continue;
+        }
+        $result['tokens']++;
+        if ($token[0] !== T_CONSTANT_ENCAPSED_STRING) {
+            continue;
+        }
+        if (trim($token[1], "'\"") === ACCOUNT_GRACE_CONFIG_KEY) {
+            $result['configKey']++;
+        }
+    }
+
+    return $result;
+}
+
+/**
+ * repo ルート相対パス => 走査結果。
+ *
+ * @param  list<string>  $dirs
+ * @return array<string, array{configKey: int, tokens: int}>
+ */
+function accountGraceScanTree(array $dirs): array
+{
+    $root = base_path();
+    $scanned = [];
+
+    foreach ($dirs as $dir) {
+        $iterator = new RecursiveIteratorIterator(
+            new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS),
+        );
+        foreach ($iterator as $file) {
+            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
+                continue;
+            }
+            $absolute = $file->getRealPath();
+            if (! is_string($absolute)) {
+                continue;
+            }
+            $source = file_get_contents($absolute);
+            if (! is_string($source)) {
+                continue;
+            }
+            $scanned[substr($absolute, strlen($root) + 1)] = accountGraceScanSource($source);
+        }
+    }
+
+    ksort($scanned);
+
+    return $scanned;
+}
+
+/**
+ * config/account.php の `deletion_grace_days => <値>` の値トークンを返す。
+ *
+ * 値が単一の整数リテラルでなければ null (= env() やクラス定数を挟んだ形は不合格)。
+ */
+function accountGraceConfigLiteral(): ?int
+{
+    $source = file_get_contents(base_path('config/account.php'));
+    if (! is_string($source)) {
+        return null;
+    }
+
+    $tokens = array_values(array_filter(
+        token_get_all($source),
+        static fn (array|string $token): bool => ! is_array($token)
+            || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
+    ));
+
+    $count = count($tokens);
+    for ($i = 0; $i < $count - 3; $i++) {
+        $token = $tokens[$i];
+        if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
+            continue;
+        }
+        if (trim($token[1], "'\"") !== ACCOUNT_GRACE_CONFIG_BARE_KEY) {
+            continue;
+        }
+        $arrow = $tokens[$i + 1];
+        $value = $tokens[$i + 2];
+        $terminator = $tokens[$i + 3];
+        if (! is_array($arrow) || $arrow[0] !== T_DOUBLE_ARROW) {
+            return null;
+        }
+        if (! is_array($value) || $value[0] !== T_LNUMBER) {
+            return null; // env(...) / 定数 / 式は不合格
+        }
+        if ($terminator !== ',' && $terminator !== ')' && $terminator !== ']') {
+            return null;
+        }
+
+        return (int) $value[1];
+    }
+
+    return null;
+}
+
+test('検査 1: 猶予日数の config キーを読むのは AccountDeletionGrace だけである', function (): void {
+    $violations = [];
+    foreach (accountGraceScanTree(['app', 'config', 'database', 'routes']) as $relative => $scan) {
+        if ($scan['configKey'] > 0 && $relative !== ACCOUNT_GRACE_SOURCE_FILE) {
+            $violations[] = $relative;
+        }
+    }
+
+    expect($violations)->toBe([],
+        'config キー account.deletion_grace_days の直読を検出しました。猶予日数は '
+        .'App\Support\Account\AccountDeletionGrace::days() 経由で取得してください '
+        .'(画面が案内する期日とバッチが消す期日を 1 箇所で対応づけるため)。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('検査 2: config/account.php の猶予日数が env を挟まない整数リテラル 30 である', function (): void {
+    $literal = accountGraceConfigLiteral();
+
+    expect($literal)->not->toBeNull(
+        'config/account.php の deletion_grace_days が整数リテラルではありません。'
+        .'env() を挟むと環境ごとに猶予が変わり、利用者への約束が環境依存になります。');
+    expect($literal)->toBe(ACCOUNT_GRACE_OWNER_DECIDED_DAYS,
+        '猶予日数はオーナー決定 (30 日) です。変更は利用者への約束の変更と同義であり、'
+        .'UI 文言・runbook と同じ PR で更新すること。');
+});
+
+test('検査 3: 実行時の AccountDeletionGrace::days() が config リテラルと一致する', function (): void {
+    expect(AccountDeletionGrace::days())->toBe(accountGraceConfigLiteral());
+});
+
+test('検査 4: 猶予日数が 0 以下なら fail-fast する (fail-open にしない)', function (): void {
+    // 0 以下をそのまま通すと purgeAfter が予約時刻以前になり、**予約した瞬間に期限到来**
+    // = 猶予ゼロで物理削除される。設定漏れは静かに通してはならない。
+    config()->set('account.deletion_grace_days', 0);
+    expect(fn (): int => AccountDeletionGrace::days())->toThrow(InvalidArgumentException::class);
+
+    config()->set('account.deletion_grace_days', -1);
+    expect(fn (): int => AccountDeletionGrace::days())->toThrow(InvalidArgumentException::class);
+
+    config()->set('account.deletion_grace_days', '30');
+    expect(fn (): int => AccountDeletionGrace::days())->toThrow(InvalidArgumentException::class);
+});
+
+test('検査 5: 空振り検知と正の自己検証', function (): void {
+    $scanned = accountGraceScanTree(['app', 'config', 'database', 'routes']);
+
+    expect(count($scanned))->toBeGreaterThan(0);
+    expect(array_sum(array_column($scanned, 'tokens')))->toBeGreaterThan(0);
+
+    // 検出器が死んでいたら検査 1 は vacuous green になる。SSOT では必ず 1 件点灯する。
+    expect($scanned[ACCOUNT_GRACE_SOURCE_FILE]['configKey'])->toBe(1);
+});
+
+test('検査 6: 負のコントロール (リテラルは検出し、コメント中の表記は検出しない)', function (): void {
+    $code = <<<'PHP'
+    <?php
+    class Fixture {
+        public function run(): void {
+            $a = config('account.deletion_grace_days');
+            $b = config("account.deletion_grace_days");
+        }
+    }
+    PHP;
+
+    $comment = <<<'PHP'
+    <?php
+    /**
+     * config('account.deletion_grace_days') を直読してはならない。
+     */
+    class Fixture {
+        // config('account.deletion_grace_days')
+        public function run(): void {}
+    }
+    PHP;
+
+    expect(accountGraceScanSource($code)['configKey'])->toBe(2);
+    expect(accountGraceScanSource($comment)['configKey'])->toBe(0);
+    expect(accountGraceScanSource($comment)['tokens'])->toBeGreaterThan(0);
+});
+
+test('検査 7: purgeAfter は暦日 30 日で月末に丸められない', function (): void {
+    // ★`addDaysNoOverflow` にすると 2026-01-31 + 30 日が **2026-02-28** に丸められ、
+    //   猶予が 28 日になる (「30 日は取り消せます」という案内が嘘になる)。
+    $requestedAt = CarbonImmutable::parse('2026-01-31 12:00:00');
+
+    $purgeAfter = AccountDeletionGrace::purgeAfter($requestedAt);
+
+    expect($purgeAfter->toDateTimeString())->toBe('2026-03-02 12:00:00');
+    expect($requestedAt->diffInDays($purgeAfter))->toBe(30.0);
+});
+
+test('検査 8: うるう年の 2 月をまたいでも暦日 30 日である', function (): void {
+    // 2028 はうるう年 (2/29 が存在する)。2028-02-10 + 30 日 = 2028-03-11。
+    $requestedAt = CarbonImmutable::parse('2028-02-10 00:00:00');
+
+    expect(AccountDeletionGrace::purgeAfter($requestedAt)->toDateTimeString())
+        ->toBe('2028-03-11 00:00:00');
+
+    // 非うるう年の同月日は 1 日ずれる (暦日加算であることの対照)
+    expect(AccountDeletionGrace::purgeAfter(CarbonImmutable::parse('2027-02-10 00:00:00'))->toDateTimeString())
+        ->toBe('2027-03-12 00:00:00');
+});
+
+test('検査 9: アプリのタイムゾーン設定下で期待するローカル時刻になる', function (): void {
+    // 要件は「暦日 30 日」であり、時刻部分は動かさない (時差計算で日付が前後しない)。
+    $requestedAt = CarbonImmutable::parse('2026-06-01 23:30:00', config('app.timezone'));
+
+    $purgeAfter = AccountDeletionGrace::purgeAfter($requestedAt);
+
+    expect($purgeAfter->getTimezone()->getName())->toBe($requestedAt->getTimezone()->getName());
+    expect($purgeAfter->format('Y-m-d H:i:s'))->toBe('2026-07-01 23:30:00');
+});
diff --git a/tests/Architecture/AccountDeletionPathGateTest.php b/tests/Architecture/AccountDeletionPathGateTest.php
index d3b6037..1928cff 100644
--- a/tests/Architecture/AccountDeletionPathGateTest.php
+++ b/tests/Architecture/AccountDeletionPathGateTest.php
@@ -84,6 +84,7 @@
  * @var list<string>
  */
 const DELETION_PATH_ROOTS = [
+    'App\Console\Commands\Account\PurgeDeletionRequestsCommand::handle',
     'App\Http\Controllers\Settings\AccountController::destroy',
     'App\Services\Organization\OrganizationMembershipService::deleteAccount',
 ];
@@ -97,6 +98,12 @@
  * @var list<string>
  */
 const DELETION_PATH_CLOSURE = [
+    // ↓ T142 (PR-B) の猶予期間つき削除で閉包に入った 6 クラス。いずれも
+    //   「予約列の読み書き」「猶予日数の解決」「予約したことの通知」だけを行い、
+    //   決済事業者 SDK への到達辺を持たない (検査 2 が機械的に固定する)。
+    'App\Console\Commands\Account\PurgeDeletionRequestsCommand',
+    'App\DataTransferObjects\Account\AccountDeletionStateDto',
+    'App\DataTransferObjects\Notification\AccountDeletionRequestedPayload',
     'App\DataTransferObjects\Invitations\PendingInvitationForUserDto',
     'App\DataTransferObjects\Notification\InvitationReceivedPayload',
     'App\DataTransferObjects\Notification\ManualJobPayload',
@@ -140,6 +147,8 @@
     'App\Models\SecurityAuditEvent',
     'App\Models\User',
     'App\Models\VideoManual',
+    'App\Notifications\Account\AccountDeletionRequestedNotification',
+    'App\Notifications\InApp\AccountDeletionRequestedNotification',
     'App\Notifications\InApp\InvitationReceivedNotification',
     'App\Notifications\InApp\ManualAnalyzedNotification',
     'App\Notifications\InApp\ManualRenderedNotification',
@@ -150,6 +159,7 @@
     'App\Services\Organization\OrganizationMembershipService',
     'App\Services\Project\DefaultProjectResolver',
     'App\Services\Security\SecurityEventRecorder',
+    'App\Support\Account\AccountDeletionGrace',
 ];
 
 /**
@@ -1044,6 +1054,9 @@ function deletionPathRootMethod(string $root): string
     //   PR-B (猶予期間つき削除) で PurgeDeletionRequestsCommand::handle を 3 本目として足すときは
     //   この pin も同時に更新する (意図的な摩擦)。
     expect(DELETION_PATH_ROOTS)->toBe([
+        // T142 (PR-B) で日次執行バッチを 3 本目として追加した。執行経路も依存閉包の対象である
+        // (バッチから決済事業者 SDK へ到達したら、退会が「解約を代行する」機能に化ける)。
+        'App\Console\Commands\Account\PurgeDeletionRequestsCommand::handle',
         'App\Http\Controllers\Settings\AccountController::destroy',
         'App\Services\Organization\OrganizationMembershipService::deleteAccount',
     ], '退会経路の起点を変えるときは、なぜ変えるのかをレビューで残してください。');
diff --git a/tests/Architecture/ControllerAuthorizationGateTest.php b/tests/Architecture/ControllerAuthorizationGateTest.php
index 19b483e..0f857c6 100644
--- a/tests/Architecture/ControllerAuthorizationGateTest.php
+++ b/tests/Architecture/ControllerAuthorizationGateTest.php
@@ -102,6 +102,16 @@ function controllerAuthorizationExemptions(): array
             .'他人のアカウントへ到達する経路がコード上存在しない。'
             .'別軸の防御として recent-auth (step-up) middleware を必須にしている。'],
 
+        'settings.account.deletion-request.store' => [$selfScoped,
+            '対象は $request->user() 自身の退会予約のみ。route に他者を指せる parameter が 1 つも無く、'
+            .'他人のアカウントへ到達する経路がコード上存在しない。即時削除と同水準の機微操作のため'
+            .'別軸の防御として recent-auth (step-up) middleware を必須にしている。'],
+
+        'settings.account.deletion-request.destroy' => [$selfScoped,
+            '対象は $request->user() 自身の退会予約の取消のみ。route に他者を指せる parameter が無く、'
+            .'他人の予約へ到達する経路が存在しない。**誤操作救済の本体**であり権限を増やす操作でもないため、'
+            .'step-up も含めて関門を置かない (救済経路に関門を足すと「取り消せない」詰みの再生産になる)。'],
+
         'settings.password.store' => [$selfScoped,
             '対象は $request->user() 自身のパスワード初回設定のみ。route に他者を指せる parameter が'
             .'無く、他人の credential へ到達する経路がコード上存在しない。'
diff --git a/tests/Architecture/JobExecutionDedupInventoryTest.php b/tests/Architecture/JobExecutionDedupInventoryTest.php
index 9d47219..913fbc8 100644
--- a/tests/Architecture/JobExecutionDedupInventoryTest.php
+++ b/tests/Architecture/JobExecutionDedupInventoryTest.php
@@ -17,6 +17,7 @@
 use App\Jobs\Manual\RunManualRender;
 use App\Mail\InquiryAcknowledgementMail;
 use App\Mail\InquiryReceivedMail;
+use App\Notifications\Account\AccountDeletionRequestedNotification;
 use App\Notifications\Billing\AutoRechargeActionRequiredNotification;
 use App\Notifications\Billing\AutoRechargeDisabledNotification;
 use App\Notifications\Billing\AutoRechargeEnabledNotification;
@@ -236,6 +237,14 @@ function jobDedupExemptions(): array
             'サブスク決済失敗のお知らせ。支払い自体は Stripe 側の請求書で一意に管理され、'
             .'重複受信しても同じ請求書へ誘導するだけなので二重支払いにはならない。',
         ),
+        AccountDeletionRequestedNotification::class => new ExemptionEntry(
+            JobDedupExemption::DuplicateDeliveryAccepted,
+            '退会予約を受け付けたことのお知らせ。ドメイン状態を一切書かず (via() は予約の生存を'
+            .'読むだけ)、重複受信しても案内先は /settings の取消ボタンで、支払い等の操作を'
+            .'新たに発生させない。**job 実行の dedup は主張しない** — 保証しているのは'
+            .'「予約操作からの job 生成は最大 1 件」までで、それは requestAccountDeletion() の'
+            .'冪等 no-op (永続状態遷移) が担う。',
+        ),
         RenewalReminderNotification::class => new ExemptionEntry(
             JobDedupExemption::DuplicateDeliveryAccepted,
             '契約更新のリマインダ。ドメイン状態を書かず、重複受信しても案内内容が同一で'
@@ -252,7 +261,7 @@ function jobDedupExemptions(): array
  */
 function jobDedupExemptionCap(): int
 {
-    return 14;
+    return 15;
 }
 
 /**
@@ -264,7 +273,7 @@ function jobDedupExemptionCap(): int
 function jobDedupExemptionCapByCase(): array
 {
     return [
-        JobDedupExemption::DuplicateDeliveryAccepted->value => 8,
+        JobDedupExemption::DuplicateDeliveryAccepted->value => 9,
         JobDedupExemption::IdempotentDeletion->value => 2,
         JobDedupExemption::ConvergentStateSync->value => 3,
         JobDedupExemption::GuardedByDownstreamConstraint->value => 1,
diff --git a/tests/Architecture/LoginMethodRemovalRouteTest.php b/tests/Architecture/LoginMethodRemovalRouteTest.php
index 3f4a5dd..53c2218 100644
--- a/tests/Architecture/LoginMethodRemovalRouteTest.php
+++ b/tests/Architecture/LoginMethodRemovalRouteTest.php
@@ -39,6 +39,13 @@ function loginMethodRemovalExemptRoutes(): array
         // アカウント自体を消す操作。手段が 0 になるのは目的であって事故ではない。
         // 別途 recent-auth (step-up) で保護済み。
         'settings.account.destroy' => 'アカウント除去そのものであり、手段が残らないことが意図',
+        // 母集団は URI 接頭辞 ('settings/account') × 破壊的メソッド (DELETE) で定義されるため、
+        // 退会**予約の取消**もここに入る。実際にはログイン手段を 1 つも触らない
+        // (users の予約列 2 本を null に戻すだけ) ので免除する。
+        // ★設計は「予約は認証手段を減らさないので登録不要」と書いていたが、母集団は
+        //   「認証手段を触るか」ではなく URI 接頭辞で決まるため実測では登録が必要だった。
+        'settings.account.deletion-request.destroy' => '退会予約の取消であり認証手段を一切触らない '
+            .'(users の予約列 2 本を null に戻すだけ。むしろアカウント消失を防ぐ救済経路)',
         // 第二要素の除去であってログイン手段の除去ではない
         // (TOTP を外してもパスワード / SSO / passkey は残る)。
         'two-factor.disable' => '第二要素の除去でありログイン手段ではない',
diff --git a/tests/Architecture/MembershipWriteLockInventoryTest.php b/tests/Architecture/MembershipWriteLockInventoryTest.php
index cfaa4fc..779ed64 100644
--- a/tests/Architecture/MembershipWriteLockInventoryTest.php
+++ b/tests/Architecture/MembershipWriteLockInventoryTest.php
@@ -11,15 +11,34 @@
  * 3 分類 (directLock / delegatedToLocked / exempt) への登録を強制する。加えてメソッドソースを
  * 検査し、実際にロックを呼んでいることを保証する:
  * - directLock 群: メソッドソースに `lockForMembershipWrite(` が現れること。
- * - delegatedToLocked 群: ロック済み内部メソッド (`joinOrganization(`) 呼び出しが現れること。
+ * - delegatedToLocked 群: 宣言した委譲先 (メソッド名 => 必須の呼び出し文字列の map) が
+ *   メソッドソースに現れること。
+ *   ★かつては `joinOrganization(` のハードコードだった。別のロック済みメソッドへ委譲する経路
+ *   (executeAccountDeletionRequest → deleteAccount) を足したときに実ロック検査が
+ *   空振りしないよう map へ一般化してある (既存 3 本の判定は等価)。
  * - 未分類メソッドがあれば fail (drift 検出)。
  */
 
 test('OrganizationMembershipService の書き込みメソッドは共通ロック規約に準拠する', function (): void {
     // 自身の tx 冒頭で直接ロックする mutating メソッド
-    $directLock = ['applyConsoleRole', 'changeRole', 'removeMember', 'transferOwnership', 'deleteAccount'];
-    // ロック済み内部メソッド (joinOrganization) 経由で間接的にロックされる受諾経路
-    $delegatedToLocked = ['acceptInvitation', 'acceptInvitationIfValid', 'acceptPendingInvitation'];
+    $directLock = [
+        'applyConsoleRole', 'changeRole', 'removeMember', 'transferOwnership', 'deleteAccount',
+        // 退会予約 / 取消 (猶予期間つき削除・凍結方式)。users 行だけを書くが、
+        // deleteAccount と同じ canonical 順序 (users 昇順 → organizations 昇順) の起点に乗せ、
+        // 新しいロック順序を作らない (順序の SoT を 2 クラスに分けない)
+        'requestAccountDeletion', 'cancelAccountDeletion',
+    ];
+    // ロック済み内部メソッド経由で間接的にロックされる経路 (メソッド名 => 必須の委譲先呼び出し)。
+    // ★ハードコードの 'joinOrganization(' を map へ一般化した (既存 3 本の判定は等価のまま)。
+    //   委譲先が 1 種類しか無い前提を残すと、別のロック済みメソッドへ委譲する経路を
+    //   足したときに「登録はできるが実ロックの検査は空振り」になる。
+    $delegatedToLocked = [
+        'acceptInvitation' => 'joinOrganization(',
+        'acceptInvitationIfValid' => 'joinOrganization(',
+        'acceptPendingInvitation' => 'joinOrganization(',
+        // 予約の執行 (日次バッチ専用)。ロック・ガード・削除はすべて deleteAccount が持つ
+        'executeAccountDeletionRequest' => 'deleteAccount(',
+    ];
     // ロック不要 (membership/role を変えない) と判断した書き込みメソッド (根拠付き exempt)
     $exempt = [
         'inviteMember',     // 招待レコード生成のみ (membership/role 不変)
@@ -47,7 +66,7 @@
         ->all();
 
     // 1. 分類漏れ検出
-    $classified = array_merge($directLock, $delegatedToLocked, $exempt);
+    $classified = array_merge($directLock, array_keys($delegatedToLocked), $exempt);
     expect(array_values(array_diff($ownPublicMethods, $classified)))
         ->toBe([], '新しい書き込みメソッドは directLock / delegatedToLocked / exempt に分類すること');
 
@@ -67,9 +86,9 @@
         // {$method} は lockForMembershipWrite を直接呼ぶこと (toContain は message 引数を取らない)
         expect(str_contains($bodyOf($method), 'lockForMembershipWrite('))->toBeTrue();
     }
-    foreach ($delegatedToLocked as $method) {
-        // {$method} はロック済み joinOrganization を経由すること
-        expect(str_contains($bodyOf($method), 'joinOrganization('))->toBeTrue();
+    foreach ($delegatedToLocked as $method => $requiredCall) {
+        // {$method} は宣言した委譲先 ({$requiredCall}) を経由すること
+        expect(str_contains($bodyOf($method), $requiredCall))->toBeTrue();
     }
 
     // 3. [ロック順序 guard] deleteAccount 本文で最初の lockForMembershipWrite( が
diff --git a/tests/Architecture/QueuedJobLeaseInventoryTest.php b/tests/Architecture/QueuedJobLeaseInventoryTest.php
index 7312d1b..d197882 100644
--- a/tests/Architecture/QueuedJobLeaseInventoryTest.php
+++ b/tests/Architecture/QueuedJobLeaseInventoryTest.php
@@ -14,6 +14,7 @@
 use App\Jobs\Manual\RunManualRender;
 use App\Mail\InquiryAcknowledgementMail;
 use App\Mail\InquiryReceivedMail;
+use App\Notifications\Account\AccountDeletionRequestedNotification;
 use App\Notifications\Billing\AutoRechargeActionRequiredNotification;
 use App\Notifications\Billing\AutoRechargeDisabledNotification;
 use App\Notifications\Billing\AutoRechargeEnabledNotification;
@@ -75,6 +76,7 @@
     AutoRechargeFailedNotification::class => null,
     PaymentFailedNotification::class => null,
     RenewalReminderNotification::class => null,
+    AccountDeletionRequestedNotification::class => null,
 ];
 
 /**
diff --git a/tests/Architecture/RecentAuthRouteTest.php b/tests/Architecture/RecentAuthRouteTest.php
index 001910e..61cdc14 100644
--- a/tests/Architecture/RecentAuthRouteTest.php
+++ b/tests/Architecture/RecentAuthRouteTest.php
@@ -26,8 +26,12 @@ function recentAuthRequiredRouteNames(): array
         'organizations.api-keys.revoke',
         // OAuth セッション失効 (組織管理経路。API キー失効と同じ機微度)
         'organizations.api-keys.sessions.revoke',
-        // アカウント削除
+        // アカウント削除 (即時)
         'settings.account.destroy',
+        // 退会の予約 (猶予 30 日)。即時削除と同水準の機微操作のため step-up 必須。
+        // **取消 (settings.account.deletion-request.destroy) は追加しない** —
+        // 誤操作救済の本体であり、救済経路に関門を足すと「取り消せない」詰みの再生産になる
+        'settings.account.deletion-request.store',
         // パスワード初回設定 (認証手段を増やす操作。セッション奪取からの永続化を防ぐため step-up 必須)
         'settings.password.store',
         // オーナー移譲
diff --git a/tests/Architecture/SecurityEventCoverageTest.php b/tests/Architecture/SecurityEventCoverageTest.php
index 077270b..ff168fc 100644
--- a/tests/Architecture/SecurityEventCoverageTest.php
+++ b/tests/Architecture/SecurityEventCoverageTest.php
@@ -91,6 +91,14 @@ function securityEventRecordingMap(): array
             'caller' => OrganizationMembershipService::class,
             'covered_by' => 'tests/Feature/Auth/AccountDeletionTest.php',
         ],
+        SecurityEventType::AccountDeletionRequested->value => [
+            'caller' => OrganizationMembershipService::class,
+            'covered_by' => 'tests/Feature/Auth/AccountDeletionGraceTest.php',
+        ],
+        SecurityEventType::AccountDeletionCancelled->value => [
+            'caller' => OrganizationMembershipService::class,
+            'covered_by' => 'tests/Feature/Auth/AccountDeletionGraceTest.php',
+        ],
         SecurityEventType::SocialAccountLinked->value => [
             'caller' => SocialAccountService::class,
             'covered_by' => 'tests/Feature/Security/SecurityAuditTrailCoverageTest.php',
diff --git a/tests/Architecture/TenantBoundaryOrderingTest.php b/tests/Architecture/TenantBoundaryOrderingTest.php
index fab0189..49129d3 100644
--- a/tests/Architecture/TenantBoundaryOrderingTest.php
+++ b/tests/Architecture/TenantBoundaryOrderingTest.php
@@ -6,6 +6,7 @@
 use App\Http\Middleware\BlockTwoFactorDisableForEnforcedOrganizations;
 use App\Http\Middleware\BughuntCoverageMiddleware;
 use App\Http\Middleware\EnforceMcpTransport;
+use App\Http\Middleware\EnsureAccountNotPendingDeletion;
 use App\Http\Middleware\EnsureEmailIsVerifiedOrBack;
 use App\Http\Middleware\EnsureLoginMethodRemains;
 use App\Http\Middleware\EnsureProjectBelongsToApiOrganization;
@@ -94,6 +95,8 @@ function middlewareShortCircuitInventory(): array
         // Inertia の asset version mismatch は 409 で短絡する
         HandleInertiaRequests::class => true,
         RequireActiveSubscription::class => true,
+        // 退会予約中の凍結。302 (web) / 409 (XHR) で短絡する
+        EnsureAccountNotPendingDeletion::class => true,
         RequireTwoFactorForEnforcedOrganizations::class => true,
         BlockTwoFactorDisableForEnforcedOrganizations::class => true,
         RequireRecentAuth::class => true,
@@ -449,6 +452,8 @@ function tenantBoundaryHasMode(string $routeName, NestedRouteDefenseMode $mode):
     ];
     $guard = EnsureProjectBelongsToCurrentOrganization::class;
     $billing = RequireActiveSubscription::class;
+    // 退会予約中の凍結は**課金ゲートの直後**。テナント境界 404 より必ず後 (302 短絡のため)。
+    $freeze = EnsureAccountNotPendingDeletion::class;
 
     $apiHead = [
         Authenticate::class,
@@ -466,10 +471,10 @@ function tenantBoundaryHasMode(string $routeName, NestedRouteDefenseMode $mode):
         // {project} を持たない route でも guard は列に載る (no-op。group 一括付与の許容)
         'api.v1.me' => $apiHead,
         // web: テナント境界 404 が Inertia / 2FA / verified / 課金ゲートより前
-        'projects.update' => [...$webHead, $guard, ...$webAppend, $billing],
-        'capture.manuals.show' => [...$webHead, $guard, ...$webAppend, $billing],
+        'projects.update' => [...$webHead, $guard, ...$webAppend, $billing, $freeze],
+        'capture.manuals.show' => [...$webHead, $guard, ...$webAppend, $billing, $freeze],
         // guard を持たない web route の列は変化しない (priority 追加の副作用が無いことの pin)
-        'organizations.settings' => [...$webHead, ...$webAppend],
+        'organizations.settings' => [...$webHead, ...$webAppend, $freeze],
     ];
 
     $routes = app('router')->getRoutes();
diff --git a/tests/Feature/Auth/AccountDeletionFreezeTest.php b/tests/Feature/Auth/AccountDeletionFreezeTest.php
new file mode 100644
index 0000000..5ab0622
--- /dev/null
+++ b/tests/Feature/Auth/AccountDeletionFreezeTest.php
@@ -0,0 +1,249 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Account\AccountDeletionFreezeAllowance;
+use App\Enums\OrganizationRole;
+use App\Http\Middleware\EnsureAccountNotPendingDeletion;
+use App\Models\Project;
+use App\Models\User;
+use App\Services\Organization\OrganizationMembershipService;
+use Illuminate\Routing\Route as RoutingRoute;
+use Illuminate\Support\Facades\DB;
+
+/*
+ * 退会予約中の**凍結** (deny-by-default) の振る舞い固定 (T142 / PR-B の B4)。
+ *
+ * 構造 (母集団 `U` と allowlist `A` の一致・enum の形式) は
+ * tests/Architecture/AccountDeletionFreezeRouteGateTest.php が固定する。
+ * 本テストは **実 HTTP** で「遮断されること / 到達できること」を測る
+ * (Architecture lane は DB を持てないため 2 本立てにしている)。
+ *
+ * 凍結の契約:
+ *   - 遮断は **302 → /settings** (403 で突き放さない = 行き先のない詰みを作らない)
+ *   - JSON/XHR は **409 Conflict** (課金ゲートの 402 とは別事由)
+ *   - **認証回復と離脱の手段は凍結しない** (ログアウトは group の外)
+ *   - **即時削除 (settings.account.destroy) は遮断する** (30 日猶予の迂回口を作らない)
+ */
+
+/** 予約中のユーザーを作り、認証主体として使える形で返す。 */
+function frozenUser(): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    app(OrganizationMembershipService::class)->requestAccountDeletion($owner);
+    // actingAs は in-memory インスタンスを認証主体にするため DB の予約状態を読み直す
+    $owner->refresh();
+
+    return [$organization, $owner];
+}
+
+/**
+ * 凍結母集団のうち **route parameter を持たない** route を [名前 => [method, uri]] で返す。
+ *
+ * ★parameter を持つ route を sweep から外すのは、ダミー id を与えるとテナント境界 404 が
+ *   先に閉じる (それが正しい順序である) ため。順序そのものは下の「404 が 302 より前」と
+ *   TenantBoundaryOrderingTest が固定する。
+ *
+ * @return array<string, array{string, string}>
+ */
+function freezeSweepTargets(): array
+{
+    $router = app('router');
+    $routes = $router->getRoutes();
+    $routes->refreshNameLookups();
+
+    $targets = [];
+    /** @var RoutingRoute $route */
+    foreach ($routes as $route) {
+        $middleware = $route->gatherMiddleware();
+        if (! in_array(EnsureAccountNotPendingDeletion::class, $middleware, true)
+            && ! in_array('not-pending-deletion', $middleware, true)) {
+            continue;
+        }
+        $name = $route->getName();
+        if ($name === null || $route->parameterNames() !== []) {
+            continue;
+        }
+        if (in_array($name, AccountDeletionFreezeAllowance::values(), true)) {
+            continue;
+        }
+        $method = collect($route->methods())->first(fn (string $m): bool => $m !== 'HEAD');
+        if (! is_string($method)) {
+            continue;
+        }
+        $targets[$name] = [$method, '/'.ltrim($route->uri(), '/')];
+    }
+
+    return $targets;
+}
+
+test('凍結母集団 U − A の parameterless route はすべて /settings へ 302 する', function (): void {
+    [, $owner] = frozenUser();
+    $targets = freezeSweepTargets();
+
+    expect(count($targets))->toBeGreaterThan(20); // 空振り防止 (sweep が 0 件でも緑にならない)
+
+    $violations = [];
+    foreach ($targets as $name => [$method, $uri]) {
+        $response = $this->actingAs($owner)->call($method, $uri);
+        if ($response->getStatusCode() !== 302 || $response->headers->get('Location') !== url('/settings')) {
+            $violations[] = "{$name} ({$method} {$uri}): "
+                .$response->getStatusCode().' '.(string) $response->headers->get('Location');
+        }
+    }
+
+    expect($violations)->toBe([],
+        '凍結対象の route が /settings へ遮断されていません。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+
+    // ★凍結中はキューに 1 件も積まれない (業務経路が全部止まるため、
+    //   AutoRechargeTriggerJob を含めどのジョブも投入されない)。Queue::fake() は使わず実 jobs 表を見る。
+    expect(DB::table('jobs')->count())->toBe(0);
+});
+
+test('予約中でも /settings は 200 で、そこから取消できる', function (): void {
+    [, $owner] = frozenUser();
+
+    $this->actingAs($owner)->get('/settings')->assertOk();
+
+    $this->actingAs($owner)->from('/settings')
+        ->delete('/settings/account/deletion-request')
+        ->assertRedirect('/settings');
+
+    expect($owner->fresh()?->deletion_requested_at)->toBeNull();
+});
+
+test('予約中は即時削除が遮断され、取消してからなら削除できる', function (): void {
+    [, $owner] = frozenUser();
+
+    // ★allowlist に settings.account.destroy を足すとこのテストが赤くなる (M17)
+    $this->actingAs($owner)
+        ->withSession(freshRecentAuthSession())
+        ->delete('/settings/account')
+        ->assertRedirect('/settings');
+    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue();
+
+    $this->actingAs($owner)->delete('/settings/account/deletion-request');
+    $owner->refresh();
+
+    $this->actingAs($owner)
+        ->withSession(freshRecentAuthSession())
+        ->delete('/settings/account')
+        ->assertRedirect('/');
+    expect(User::query()->whereKey($owner->id)->exists())->toBeFalse();
+});
+
+test('予約中でもログアウトできる (認証回復・離脱の手段は母集団の外)', function (): void {
+    [, $owner] = frozenUser();
+
+    $this->actingAs($owner)->post('/logout')->assertRedirect();
+    $this->assertGuest();
+});
+
+test('予約中でも session.status は読める (bfcache 再検証の前提を凍結しない)', function (): void {
+    [, $owner] = frozenUser();
+
+    $this->actingAs($owner)->getJson('/session/status')->assertOk();
+});
+
+test('予約中でも解約導線 (billing.index / billing.portal) に到達できる', function (): void {
+    [, $owner] = frozenUser();
+
+    $this->actingAs($owner)->get('/billing')->assertOk();
+
+    // portal は Stripe セッション生成へ進むため、ここでは「凍結で 302 されない」ことだけを見る
+    $response = $this->actingAs($owner)->post('/billing/portal');
+    expect($response->headers->get('Location'))->not->toBe(url('/settings'));
+});
+
+test('予約中でもオーナー移譲 (ブロッカー解消) の画面と操作に到達できる', function (): void {
+    [$organization, $owner] = frozenUser();
+    $member = attachOrganizationMember($organization);
+
+    $this->actingAs($owner)->get("/organizations/{$organization->slug}/settings")->assertOk();
+
+    $this->actingAs($owner)
+        ->withSession(freshRecentAuthSession())
+        ->from("/organizations/{$organization->slug}/settings")
+        ->post("/organizations/{$organization->slug}/transfer-ownership", ['user_id' => $member->id])
+        ->assertRedirect("/organizations/{$organization->slug}/settings");
+});
+
+test('予約中でも step-up 確認画面に到達できる (移譲に必要な satisfier)', function (): void {
+    [, $owner] = frozenUser();
+
+    // ★recent-auth.confirm を allowlist から外すとここが赤くなる (M25)
+    $this->actingAs($owner)->get('/recent-auth/confirm')->assertOk();
+    $this->actingAs($owner)->getJson('/recent-auth/status')->assertOk();
+});
+
+test('セッションが切れても再ログインしてから取消できる', function (): void {
+    [, $owner] = frozenUser();
+
+    $this->get('/settings')->assertRedirect('/login');
+
+    $this->actingAs($owner)->from('/settings')
+        ->delete('/settings/account/deletion-request')
+        ->assertRedirect('/settings');
+    expect($owner->fresh()?->deletion_requested_at)->toBeNull();
+});
+
+test('recent-auth の鮮度が切れていても取消できる (救済経路に step-up を課さない)', function (): void {
+    [, $owner] = frozenUser();
+
+    // recent_auth_at を一切持たないセッションで取消する
+    $this->actingAs($owner)->from('/settings')
+        ->delete('/settings/account/deletion-request')
+        ->assertRedirect('/settings');
+    expect($owner->fresh()?->deletion_requested_at)->toBeNull();
+});
+
+test('2FA 必須組織のユーザーでも取消できる (satisfier の到達性)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $organization->forceFill(['two_factor_required' => true])->save();
+
+    // 2FA 準拠済みユーザー (未準拠だと 2FA 強制ゲートが先に短絡し、凍結の検証にならない)
+    $user = User::factory()->withTwoFactor()->create();
+    $organization->users()->attach($user);
+    $user->addRole(OrganizationRole::Admin->value, $organization->laratrust_team_id);
+    $user->forceFill(['current_organization_id' => $organization->id])->save();
+    app(OrganizationMembershipService::class)->requestAccountDeletion($user);
+    $user->refresh();
+
+    $this->actingAs($user)->from('/settings')
+        ->delete('/settings/account/deletion-request')
+        ->assertRedirect('/settings');
+    expect($user->fresh()?->deletion_requested_at)->toBeNull();
+});
+
+test('XHR は 409 Conflict で遮断される (302 に倒さない)', function (): void {
+    [, $owner] = frozenUser();
+
+    $this->actingAs($owner)->getJson('/dashboard')->assertStatus(409);
+});
+
+test('未予約ユーザーには一切影響しない (全 parameterless route が従来どおり)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $redirectedToSettings = [];
+    foreach (freezeSweepTargets() as $name => [$method, $uri]) {
+        $response = $this->actingAs($owner)->call($method, $uri);
+        if ($response->getStatusCode() === 302 && $response->headers->get('Location') === url('/settings')) {
+            $redirectedToSettings[] = $name;
+        }
+    }
+
+    expect($redirectedToSettings)->toBe([],
+        '未予約ユーザーが凍結されています (middleware が予約状態を見ていない疑い): '
+        .implode(', ', $redirectedToSettings));
+});
+
+test('テナント境界 404 が凍結 302 より前に閉じる (存在オラクルを作らない)', function (): void {
+    [, $owner] = frozenUser();
+    [$otherOrganization] = createOrganizationWithOwner('他組織');
+    $foreign = Project::factory()->forOrganization($otherOrganization)->create();
+
+    // ★凍結 middleware を priority list でテナント境界より前へ動かすとここが 302 になる (M6)
+    $this->actingAs($owner)->get("/projects/{$foreign->id}")->assertNotFound();
+    $this->actingAs($owner)->get('/projects/999999999')->assertNotFound();
+});
diff --git a/tests/Feature/Auth/AccountDeletionGraceTest.php b/tests/Feature/Auth/AccountDeletionGraceTest.php
new file mode 100644
index 0000000..df7f017
--- /dev/null
+++ b/tests/Feature/Auth/AccountDeletionGraceTest.php
@@ -0,0 +1,405 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Notification\NotificationType;
+use App\Enums\OrganizationRole;
+use App\Models\SecurityAuditEvent;
+use App\Models\User;
+use App\Notifications\Account\AccountDeletionRequestedNotification;
+use App\Services\Organization\OrganizationMembershipService;
+use App\Support\Account\AccountDeletionGrace;
+use Carbon\CarbonImmutable;
+use Illuminate\Database\Eloquent\MassAssignmentException;
+use Illuminate\Database\QueryException;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Notification;
+
+/*
+ * 猶予期間つき退会 (**凍結方式**) の予約 / 取消 / 執行 (T142 / 標準形 v1 の必須 (2))。
+ *
+ * 凍結中のアクセス制限そのものは tests/Feature/Auth/AccountDeletionFreezeTest.php、
+ * 即時削除 (副導線) の既存挙動は tests/Feature/Auth/AccountDeletionTest.php が担当する
+ * (**既存 16 本は 1 行も変更していない** = 予約と即時削除は併存する)。
+ */
+
+/** 予約中の users 行を DB から素で読む (cast を経由せず列の生値を見る)。 */
+function deletionColumns(User $user): object
+{
+    $row = DB::table('users')
+        ->select(['deletion_requested_at', 'deletion_purge_after'])
+        ->where('id', $user->id)
+        ->first();
+    expect($row)->not->toBeNull();
+
+    return (object) $row;
+}
+
+// ── B1: 予約列と DB 制約 ─────────────────────────────────────────────────
+
+test('users の予約列は既定で両方 null (未予約が既定状態)', function (): void {
+    $user = User::factory()->create();
+
+    $columns = deletionColumns($user);
+    expect($columns->deletion_requested_at)->toBeNull();
+    expect($columns->deletion_purge_after)->toBeNull();
+});
+
+test('UserFactory::pendingDeletion() が両列を猶予日数どおりに埋める', function (): void {
+    $requestedAt = CarbonImmutable::parse('2026-06-01 10:00:00');
+    $user = User::factory()->pendingDeletion($requestedAt)->create();
+
+    expect($user->deletion_requested_at?->toDateTimeString())->toBe('2026-06-01 10:00:00');
+    expect($user->deletion_purge_after?->toDateTimeString())
+        ->toBe(AccountDeletionGrace::purgeAfter($requestedAt)->toDateTimeString());
+});
+
+test('予約列は mass-assignment で書けない (保護列)', function (): void {
+    $user = User::factory()->create();
+
+    // $fillable 外のため fill() は例外になる (silently discard も許さない strict モード)。
+    expect(fn () => $user->fill([
+        'deletion_requested_at' => CarbonImmutable::now(),
+        'deletion_purge_after' => CarbonImmutable::now()->addDays(30),
+    ]))->toThrow(MassAssignmentException::class);
+
+    $columns = deletionColumns($user->fresh() ?? $user);
+    expect($columns->deletion_requested_at)->toBeNull();
+    expect($columns->deletion_purge_after)->toBeNull();
+});
+
+test('片列だけの UPDATE を DB が拒否する (アプリ層を迂回しても守られる)', function (): void {
+    $user = User::factory()->create();
+
+    expect(fn () => DB::table('users')->where('id', $user->id)->update([
+        'deletion_requested_at' => CarbonImmutable::now(),
+    ]))->toThrow(QueryException::class);
+
+    expect(fn () => DB::table('users')->where('id', $user->id)->update([
+        'deletion_purge_after' => CarbonImmutable::now(),
+    ]))->toThrow(QueryException::class);
+});
+
+test('deletion_purge_after が deletion_requested_at より前の行を DB が拒否する', function (): void {
+    $user = User::factory()->create();
+
+    expect(fn () => DB::table('users')->where('id', $user->id)->update([
+        'deletion_requested_at' => CarbonImmutable::parse('2026-06-01 10:00:00'),
+        'deletion_purge_after' => CarbonImmutable::parse('2026-05-31 10:00:00'),
+    ]))->toThrow(QueryException::class);
+});
+
+// ── B2: Service (予約 / 取消 / 執行) ────────────────────────────────────
+
+test('予約すると両列が入り SecurityEvent account_deletion_requested が 1 件記録される', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $state = app(OrganizationMembershipService::class)->requestAccountDeletion($owner);
+
+    expect($state->isPending())->toBeTrue();
+    expect($state->graceDays())->toBe(AccountDeletionGrace::days());
+    expect(SecurityAuditEvent::query()->where('event_type', 'account_deletion_requested')->count())->toBe(1);
+    // users 行は生きている (凍結方式 = 生死を変えない)
+    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue();
+});
+
+test('二重予約しても purge_after が延びない (冪等 no-op)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+    $membership = app(OrganizationMembershipService::class);
+
+    $first = $membership->requestAccountDeletion($owner);
+    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(3));
+    $second = $membership->requestAccountDeletion($owner);
+    CarbonImmutable::setTestNow();
+
+    expect($second->purgeAfter?->toDateTimeString())->toBe($first->purgeAfter?->toDateTimeString());
+    expect($second->requestedAt?->toDateTimeString())->toBe($first->requestedAt?->toDateTimeString());
+    // 監査イベントも 1 件のまま (no-op は記録しない)
+    expect(SecurityAuditEvent::query()->where('event_type', 'account_deletion_requested')->count())->toBe(1);
+});
+
+test('取消で両列が null になり SecurityEvent account_deletion_cancelled が記録される', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+    $membership = app(OrganizationMembershipService::class);
+    $membership->requestAccountDeletion($owner);
+
+    $state = $membership->cancelAccountDeletion($owner);
+
+    expect($state->isPending())->toBeFalse();
+    $columns = deletionColumns($owner);
+    expect($columns->deletion_requested_at)->toBeNull();
+    expect($columns->deletion_purge_after)->toBeNull();
+    expect(SecurityAuditEvent::query()->where('event_type', 'account_deletion_cancelled')->count())->toBe(1);
+});
+
+test('取消は冪等 (未予約でも例外にならず no-op)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $state = app(OrganizationMembershipService::class)->cancelAccountDeletion($owner);
+
+    expect($state->isPending())->toBeFalse();
+    expect(SecurityAuditEvent::query()->where('event_type', 'account_deletion_cancelled')->count())->toBe(0);
+});
+
+test('退会ブロッカーがあっても予約できる (予約時にブロッカーを評価しない契約)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    attachOrganizationMember($organization, OrganizationRole::Admin); // 孤児化する残存メンバー
+
+    // ★ここで例外になると「解約待ちの間は退会予約もできない」詰みになる
+    $state = app(OrganizationMembershipService::class)->requestAccountDeletion($owner);
+
+    expect($state->isPending())->toBeTrue();
+});
+
+test('退会ブロッカーがあっても取消できる (救済経路に条件を付けない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $membership = app(OrganizationMembershipService::class);
+    $membership->requestAccountDeletion($owner);
+    attachOrganizationMember($organization, OrganizationRole::Admin);
+
+    expect($membership->cancelAccountDeletion($owner)->isPending())->toBeFalse();
+});
+
+test('執行は期限到来なら削除し、未到来なら false で何も変えない', function (): void {
+    // 1 秒境界を測るので時計を固定する (実行時間が 1 秒を超えると未到来が到来に化けるため)。
+    // Laravel の TestCase::tearDown が setTestNow をリセットする。
+    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-30 12:00:00'));
+    $membership = app(OrganizationMembershipService::class);
+
+    $due = User::factory()->pendingDeletion(
+        CarbonImmutable::now()->subDays(31),
+        CarbonImmutable::now()->subSecond(),
+    )->create();
+    $notDue = User::factory()->pendingDeletion(
+        CarbonImmutable::now()->subDays(29),
+        CarbonImmutable::now()->addSecond(),
+    )->create();
+
+    expect($membership->executeAccountDeletionRequest($due))->toBeTrue();
+    expect(User::query()->whereKey($due->id)->exists())->toBeFalse();
+
+    expect($membership->executeAccountDeletionRequest($notDue))->toBeFalse();
+    expect(User::query()->whereKey($notDue->id)->exists())->toBeTrue();
+    expect(deletionColumns($notDue)->deletion_purge_after)->not->toBeNull();
+});
+
+test('抽出後に取消されたユーザーは執行されない (ロック下の再確認)', function (): void {
+    $membership = app(OrganizationMembershipService::class);
+    $user = User::factory()->pendingDeletion(
+        CarbonImmutable::now()->subDays(31),
+        CarbonImmutable::now()->subSecond(),
+    )->create();
+
+    // バッチが抽出した後に本人が取り消した状況を作る (in-memory の $user は古いまま)
+    DB::table('users')->where('id', $user->id)->update([
+        'deletion_requested_at' => null,
+        'deletion_purge_after' => null,
+    ]);
+
+    expect($membership->executeAccountDeletionRequest($user))->toBeFalse();
+    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
+});
+
+test('抽出後に取消されたユーザーはブロッカーがあっても例外にならない (保留と誤分類しない)', function (): void {
+    // ★precondition をブロッカー判定の**後**へ動かすとこのテストが赤くなる (M8)。
+    [$organization, $owner] = createOrganizationWithOwner();
+    attachOrganizationMember($organization, OrganizationRole::Admin); // ブロッカーを立てる
+    $membership = app(OrganizationMembershipService::class);
+    $membership->requestAccountDeletion($owner);
+    DB::table('users')->where('id', $owner->id)->update([
+        'deletion_requested_at' => null,
+        'deletion_purge_after' => null,
+    ]);
+
+    expect($membership->executeAccountDeletionRequest($owner))->toBeFalse();
+});
+
+// ── B3: HTTP (予約 / 取消) ──────────────────────────────────────────────
+
+test('step-up なしでは予約できない', function (): void {
+    $user = User::factory()->create();
+
+    $response = $this->actingAs($user)->post('/settings/account/deletion-request');
+
+    $response->assertRedirect();
+    expect(deletionColumns($user)->deletion_requested_at)->toBeNull();
+});
+
+test('step-up 済みなら予約でき flash が出る', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $response = $this->actingAs($owner)
+        ->withSession(freshRecentAuthSession())
+        ->from('/settings')
+        ->post('/settings/account/deletion-request');
+
+    $response->assertRedirect('/settings');
+    $response->assertSessionHas('success');
+    expect(deletionColumns($owner)->deletion_purge_after)->not->toBeNull();
+});
+
+test('取消は step-up 無しでもできる (誤操作救済の本体)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+    app(OrganizationMembershipService::class)->requestAccountDeletion($owner);
+
+    $response = $this->actingAs($owner)
+        ->from('/settings')
+        ->delete('/settings/account/deletion-request');
+
+    $response->assertRedirect('/settings');
+    $response->assertSessionHas('success', '退会の予約を取り消しました。');
+    expect(deletionColumns($owner)->deletion_requested_at)->toBeNull();
+});
+
+test('未認証は予約 / 取消のどちらもログインへ倒れる', function (): void {
+    $this->post('/settings/account/deletion-request')->assertRedirect('/login');
+    $this->delete('/settings/account/deletion-request')->assertRedirect('/login');
+});
+
+test('予約 / 取消 route は他者を指せる parameter を持たない (構造的な自己スコープ)', function (): void {
+    $routes = app('router')->getRoutes();
+    $routes->refreshNameLookups();
+
+    foreach ([
+        'settings.account.deletion-request.store',
+        'settings.account.deletion-request.destroy',
+    ] as $name) {
+        $route = $routes->getByName($name);
+        expect($route)->not->toBeNull();
+        expect($route?->parameterNames())->toBe([]);
+    }
+});
+
+// ── B7: 画面 props ─────────────────────────────────────────────────────
+
+test('/settings が退会予約の状態と猶予日数を props で返す', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)->get('/settings')
+        ->assertInertia(fn ($page) => $page
+            ->where('accountDeletionState.requestedAt', null)
+            ->where('accountDeletionState.purgeAfter', null)
+            ->where('accountDeletionGraceDays', AccountDeletionGrace::days()));
+
+    $state = app(OrganizationMembershipService::class)->requestAccountDeletion($owner);
+    // actingAs は in-memory の User インスタンスをそのまま認証主体にするため、
+    // Service 内の $fresh への書き込みを反映させてから叩く (実リクエストは毎回 DB から読む)。
+    $owner->refresh();
+
+    $this->actingAs($owner)->get('/settings')
+        ->assertInertia(fn ($page) => $page
+            ->where('accountDeletionState.purgeAfter', $state->purgeAfter?->toIso8601String())
+            ->where('accountDeletionState.graceDays', AccountDeletionGrace::days()));
+});
+
+// ── B6: 通知・監査 ─────────────────────────────────────────────────────
+
+test('予約でメール通知が 1 通だけキューされる', function (): void {
+    Notification::fake();
+    [, $owner] = createOrganizationWithOwner();
+
+    app(OrganizationMembershipService::class)->requestAccountDeletion($owner);
+
+    Notification::assertSentToTimes($owner, AccountDeletionRequestedNotification::class, 1);
+});
+
+test('予約でアプリ内通知が 1 件作られる (current org を表示文脈に持つ)', function (): void {
+    // ★Notification::fake() は database channel も含めて全経路を差し替えるため、
+    //   DB 行を見るこのテストでは fake しない (メールは array mailer に落ちる)。
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    app(OrganizationMembershipService::class)->requestAccountDeletion($owner);
+
+    $row = DB::table('notifications')
+        ->where('type', NotificationType::AccountDeletionRequested->value)
+        ->first();
+    expect($row)->not->toBeNull();
+    expect((int) ($row->organization_id ?? 0))->toBe($organization->id);
+
+    $data = json_decode((string) ($row->data ?? '{}'), true);
+    expect($data)->toBeArray();
+    expect($data['grace_days'] ?? null)->toBe(AccountDeletionGrace::days());
+});
+
+test('current org を持たないユーザーにはアプリ内通知を作らない (org 文脈を捏造しない)', function (): void {
+    $user = User::factory()->create();
+    expect($user->current_organization_id)->toBeNull();
+
+    app(OrganizationMembershipService::class)->requestAccountDeletion($user);
+
+    expect(DB::table('notifications')
+        ->where('type', NotificationType::AccountDeletionRequested->value)
+        ->count())->toBe(0);
+    // 予約そのものは成立している (通知の有無が予約を左右しない)
+    expect(deletionColumns($user)->deletion_purge_after)->not->toBeNull();
+});
+
+test('予約 POST を 2 回叩いてもメール通知は 1 通 (一回性は永続状態遷移が担う)', function (): void {
+    Notification::fake();
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)->withSession(freshRecentAuthSession())
+        ->post('/settings/account/deletion-request');
+    $this->actingAs($owner)->withSession(freshRecentAuthSession())
+        ->post('/settings/account/deletion-request');
+
+    Notification::assertSentToTimes($owner, AccountDeletionRequestedNotification::class, 1);
+});
+
+test('予約 → 即取消 のあとに通知 job を実行してもメールは送られない (via の再確認)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+    $membership = app(OrganizationMembershipService::class);
+    $state = $membership->requestAccountDeletion($owner);
+    $requestedAt = $state->requestedAt;
+    $purgeAfter = $state->purgeAfter;
+    expect($requestedAt)->not->toBeNull();
+    expect($purgeAfter)->not->toBeNull();
+
+    $membership->cancelAccountDeletion($owner);
+
+    $notification = new AccountDeletionRequestedNotification($requestedAt, $purgeAfter);
+    expect($notification->via($owner->fresh() ?? $owner))->toBe([]);
+});
+
+test('再予約後は古い通知 job が送られない (requestedAt / purgeAfter の一致検査)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+    $membership = app(OrganizationMembershipService::class);
+    $old = $membership->requestAccountDeletion($owner);
+    expect($old->requestedAt)->not->toBeNull();
+    expect($old->purgeAfter)->not->toBeNull();
+
+    $membership->cancelAccountDeletion($owner);
+    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDay());
+    $membership->requestAccountDeletion($owner);
+    CarbonImmutable::setTestNow();
+
+    $stale = new AccountDeletionRequestedNotification($old->requestedAt, $old->purgeAfter);
+    expect($stale->via($owner->fresh() ?? $owner))->toBe([]);
+});
+
+test('執行済み (user 削除済み) の通知 job は送られない (fresh() が null)', function (): void {
+    $user = User::factory()->pendingDeletion()->create();
+    $requestedAt = $user->deletion_requested_at;
+    $purgeAfter = $user->deletion_purge_after;
+    expect($requestedAt)->not->toBeNull();
+    expect($purgeAfter)->not->toBeNull();
+
+    $notification = new AccountDeletionRequestedNotification($requestedAt, $purgeAfter);
+    expect($notification->via($user))->toBe(['mail']);
+
+    $user->delete();
+
+    // ★`fresh() ?? $notifiable` のフォールバックへ戻すとここが赤くなる (M23)
+    expect($notification->via($user))->toBe([]);
+});
+
+test('予約中のユーザーへの通知 job は送られる (正のコントロール)', function (): void {
+    $user = User::factory()->pendingDeletion()->create();
+    $requestedAt = $user->deletion_requested_at;
+    $purgeAfter = $user->deletion_purge_after;
+    expect($requestedAt)->not->toBeNull();
+    expect($purgeAfter)->not->toBeNull();
+
+    $notification = new AccountDeletionRequestedNotification($requestedAt, $purgeAfter);
+    expect($notification->via($user->fresh() ?? $user))->toBe(['mail']);
+});
diff --git a/tests/Feature/Console/PurgeDeletionRequestsCommandTest.php b/tests/Feature/Console/PurgeDeletionRequestsCommandTest.php
new file mode 100644
index 0000000..2992327
--- /dev/null
+++ b/tests/Feature/Console/PurgeDeletionRequestsCommandTest.php
@@ -0,0 +1,182 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\OrganizationRole;
+use App\Models\User;
+use App\Notifications\Account\AccountDeletionRequestedNotification;
+use App\Services\Billing\AccountDeletionBillingGuard;
+use App\Services\Billing\Contracts\StripeGatewayInterface;
+use App\Services\Notification\NotificationCenterService;
+use App\Services\Organization\OrganizationMembershipService;
+use App\Services\Project\DefaultProjectResolver;
+use App\Services\Security\SecurityEventRecorder;
+use Carbon\CarbonImmutable;
+use Illuminate\Console\Scheduling\Schedule;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Notification;
+use Symfony\Component\Console\Command\Command as SymfonyCommand;
+
+/*
+ * 退会予約の日次執行バッチ (`account:purge-deletion-requests`)。
+ *
+ * 終了コードの契約 (2 分類):
+ *   - 退会ブロッカー (ValidationException) = **業務上の保留**。予約は維持し SUCCESS のまま次へ
+ *   - インフラ障害 / 不変条件違反 = **想定外**。走査は続けるが FAILURE で終わる
+ */
+
+/** 期限到来済みの予約ユーザー。 */
+function dueUser(): User
+{
+    return User::factory()->pendingDeletion(
+        CarbonImmutable::now()->subDays(31),
+        CarbonImmutable::now()->subSecond(),
+    )->create();
+}
+
+test('dry-run は 1 人も削除しない', function (): void {
+    $user = dueUser();
+
+    $this->artisan('account:purge-deletion-requests')
+        ->expectsOutputToContain('due=1 deleted=0')
+        ->assertExitCode(SymfonyCommand::SUCCESS);
+
+    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
+});
+
+test('--apply で期限到来ユーザーが削除され、未到来は残る (境界: 1 秒前 / 1 秒後)', function (): void {
+    // 1 秒境界を測るので時計を固定する (実行時間が 1 秒を超えると未到来が到来に化けるため)
+    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-30 12:00:00'));
+    $due = dueUser();
+    $notDue = User::factory()->pendingDeletion(
+        CarbonImmutable::now()->subDays(29),
+        CarbonImmutable::now()->addSecond(),
+    )->create();
+
+    $this->artisan('account:purge-deletion-requests --apply')
+        ->assertExitCode(SymfonyCommand::SUCCESS);
+
+    expect(User::query()->whereKey($due->id)->exists())->toBeFalse();
+    expect(User::query()->whereKey($notDue->id)->exists())->toBeTrue();
+});
+
+test('抽出後に取り消されたユーザーは削除されない', function (): void {
+    $user = dueUser();
+    // 抽出とロック取得の間に取消された状況の代理として、コマンド実行前に列を消す
+    DB::table('users')->where('id', $user->id)->update([
+        'deletion_requested_at' => null,
+        'deletion_purge_after' => null,
+    ]);
+
+    $this->artisan('account:purge-deletion-requests --apply')
+        ->expectsOutputToContain('due=0')
+        ->assertExitCode(SymfonyCommand::SUCCESS);
+
+    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
+});
+
+test('同日 2 回実行しても二重削除・二重通知が起きない', function (): void {
+    Notification::fake();
+    $user = dueUser();
+
+    $this->artisan('account:purge-deletion-requests --apply')->assertExitCode(SymfonyCommand::SUCCESS);
+    $this->artisan('account:purge-deletion-requests --apply')
+        ->expectsOutputToContain('due=0 deleted=0')
+        ->assertExitCode(SymfonyCommand::SUCCESS);
+
+    expect(User::query()->whereKey($user->id)->exists())->toBeFalse();
+    Notification::assertNothingSentTo($user);
+    Notification::assertNotSentTo([$user], AccountDeletionRequestedNotification::class);
+});
+
+test('1 人目でブロッカー例外が出ても 2 人目は削除される (失敗分離・SUCCESS)', function (): void {
+    // ブロッカー付き (唯一 Owner + 他メンバーが残る) の予約ユーザーを先に作る
+    [$organization, $blockedOwner] = createOrganizationWithOwner();
+    attachOrganizationMember($organization, OrganizationRole::Admin);
+    DB::table('users')->where('id', $blockedOwner->id)->update([
+        'deletion_requested_at' => CarbonImmutable::now()->subDays(31),
+        'deletion_purge_after' => CarbonImmutable::now()->subSecond(),
+    ]);
+    $deletable = dueUser();
+
+    $this->artisan('account:purge-deletion-requests --apply')
+        ->expectsOutputToContain('due=2 deleted=1 blocked=1 unexpected=0')
+        // ★ブロッカーだけなら終了コードは SUCCESS (業務上の保留であって障害ではない)
+        ->assertExitCode(SymfonyCommand::SUCCESS);
+
+    expect(User::query()->whereKey($blockedOwner->id)->exists())->toBeTrue();
+    expect(User::query()->whereKey($deletable->id)->exists())->toBeFalse();
+    // ブロックされたユーザーの予約は維持される (翌日また試す)
+    expect(User::query()->whereKey($blockedOwner->id)->first()?->deletion_purge_after)->not->toBeNull();
+});
+
+test('想定外例外が 1 件でもあれば FAILURE になり、走査は最後まで続く', function (): void {
+    dueUser();
+    dueUser();
+
+    $this->instance(OrganizationMembershipService::class, new class(app(SecurityEventRecorder::class), app(DefaultProjectResolver::class), app(NotificationCenterService::class), app(AccountDeletionBillingGuard::class)) extends OrganizationMembershipService
+    {
+        public function executeAccountDeletionRequest(User $user): bool
+        {
+            throw new RuntimeException('インフラ障害の代理');
+        }
+    });
+
+    // ★終了コードを常に SUCCESS にすると赤くなる (M7)
+    $this->artisan('account:purge-deletion-requests --apply')
+        ->expectsOutputToContain('due=2 deleted=0 blocked=0 unexpected=2')
+        ->assertExitCode(SymfonyCommand::FAILURE);
+});
+
+test('片列だけの非正規行があれば report + FAILURE になり、その行は削除もされない', function (): void {
+    $user = User::factory()->create();
+    // CHECK 制約が無効化された / DB が壊れた状況の再現 (defense-in-depth の検証)
+    DB::statement('ALTER TABLE users DROP CONSTRAINT users_deletion_request_pair_check');
+    DB::table('users')->where('id', $user->id)->update([
+        'deletion_purge_after' => CarbonImmutable::now()->subDay(),
+    ]);
+
+    // ★抽出条件から whereNotNull('deletion_requested_at') を外すと due=1 になり赤くなる (M20)
+    $this->artisan('account:purge-deletion-requests --apply')
+        ->expectsOutputToContain('due=0 deleted=0 blocked=0 unexpected=1')
+        ->assertExitCode(SymfonyCommand::FAILURE);
+
+    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
+});
+
+test('決済事業者 API を 1 回も呼ばない (解約を代行しない)', function (): void {
+    // 課金責務のある組織 (ブロッカー) と、素直に消えるユーザーの両方を通す
+    [$organization, $owner] = createOrganizationWithOwner();
+    createFakeSubscription($organization, status: 'active');
+    DB::table('users')->where('id', $owner->id)->update([
+        'deletion_requested_at' => CarbonImmutable::now()->subDays(31),
+        'deletion_purge_after' => CarbonImmutable::now()->subSecond(),
+    ]);
+    dueUser();
+    // mock は「1 度も呼ばれない」ことを期待する (呼ばれたら Mockery が fail させる)。
+    // 外向き HTTP 自体はレーン既定の StrayHttpRequestGuard が拒否する。
+    $this->mock(StripeGatewayInterface::class);
+
+    $this->artisan('account:purge-deletion-requests --apply')
+        ->assertExitCode(SymfonyCommand::SUCCESS);
+});
+
+test('出力に user id / email が出ない (件数のみ)', function (): void {
+    $user = dueUser();
+    $email = $user->email;
+
+    $this->artisan('account:purge-deletion-requests --apply')
+        ->doesntExpectOutputToContain((string) $email)
+        ->doesntExpectOutputToContain('id='.$user->id)
+        ->assertExitCode(SymfonyCommand::SUCCESS);
+});
+
+test('日次スケジュールに --apply つきで登録されている', function (): void {
+    $commands = collect(app(Schedule::class)->events())
+        ->map(fn ($event): string => (string) $event->command)
+        ->filter(fn (string $command): bool => str_contains($command, 'account:purge-deletion-requests'))
+        ->values();
+
+    expect($commands)->toHaveCount(1);
+    expect($commands->first())->toContain('--apply');
+});
diff --git a/tests/Feature/Notifications/NotificationSchemaTest.php b/tests/Feature/Notifications/NotificationSchemaTest.php
index 5cf7fa5..d454af5 100644
--- a/tests/Feature/Notifications/NotificationSchemaTest.php
+++ b/tests/Feature/Notifications/NotificationSchemaTest.php
@@ -2,9 +2,11 @@
 
 declare(strict_types=1);
 
+use App\DataTransferObjects\Notification\AccountDeletionRequestedPayload;
 use App\DataTransferObjects\Notification\InvitationReceivedPayload;
 use App\DataTransferObjects\Notification\ManualJobPayload;
 use App\DataTransferObjects\Notification\TicketBalanceLowPayload;
+use App\Notifications\InApp\AccountDeletionRequestedNotification;
 use App\Notifications\InApp\InvitationReceivedNotification;
 use App\Notifications\InApp\ManualAnalyzedNotification;
 use App\Notifications\InApp\ManualRenderedNotification;
@@ -64,10 +66,16 @@ function manualJobPayloadFixture(bool $succeeded = true): ManualJobPayload
     $owner->notify(new ManualRenderedNotification($organization->id, manualJobPayloadFixture(succeeded: false)));
     $owner->notify(new InvitationReceivedNotification($organization->id, new InvitationReceivedPayload('テスト組織')));
     $owner->notify(new TicketBalanceLowNotification($organization->id, new TicketBalanceLowPayload('テスト組織', 3, 5)));
+    // T142: 退会予約通知は「予約時点の current org」を表示文脈として持つ (org 非依存通知にしない)
+    $owner->notify(new AccountDeletionRequestedNotification(
+        $organization->id,
+        new AccountDeletionRequestedPayload('2026-09-09T09:00:00+09:00', 30),
+    ));
 
-    expect(DB::table('notifications')->count())->toBe(4);
+    expect(DB::table('notifications')->count())->toBe(5);
     expect(DB::table('notifications')->whereNull('organization_id')->count())->toBe(0);
     expect(DB::table('notifications')->pluck('type')->sort()->values()->all())->toBe([
+        'account_deletion_requested',
         'invitation_received',
         'manual_analyzed',
         'manual_rendered',
@@ -117,6 +125,13 @@ function manualJobPayloadFixture(bool $succeeded = true): ManualJobPayload
     expect(InvitationReceivedPayload::tryFromArray(['organization_name' => 1]))->toBeNull();
     expect(InvitationReceivedPayload::tryFromArray([]))->toBeNull();
 
+    expect(AccountDeletionRequestedPayload::tryFromArray(
+        ['purge_after' => '2026-09-09T09:00:00+09:00', 'grace_days' => 30],
+    ))->not->toBeNull();
+    expect(AccountDeletionRequestedPayload::tryFromArray(['purge_after' => 1, 'grace_days' => 30]))->toBeNull();
+    expect(AccountDeletionRequestedPayload::tryFromArray(['purge_after' => 'x', 'grace_days' => '30']))->toBeNull();
+    expect(AccountDeletionRequestedPayload::tryFromArray([]))->toBeNull();
+
     expect(TicketBalanceLowPayload::tryFromArray([
         'organization_name' => 'X', 'balance' => 3, 'threshold' => 5,
     ]))->not->toBeNull();
diff --git a/tests/js/components/features/NotificationListItem.test.ts b/tests/js/components/features/NotificationListItem.test.ts
index ed2d5df..2990ea4 100644
--- a/tests/js/components/features/NotificationListItem.test.ts
+++ b/tests/js/components/features/NotificationListItem.test.ts
@@ -253,6 +253,23 @@ describe("NotificationListItem", () => {
         );
     });
 
+    it("account_deletion_requested: 退会予約の文言と削除予定日を出す (T142)", () => {
+        render(NotificationListItem, {
+            props: {
+                notification: manualAnalyzedItem({
+                    type: "account_deletion_requested",
+                    payload: { purge_after: "2026-09-09T09:00:00+09:00", grace_days: 30 },
+                }),
+            },
+        });
+
+        expect(screen.getByText("退会のお手続きを受け付けました")).toBeInTheDocument();
+        // 未知 type の fallback (rawType 表示) に落ちていないこと
+        expect(screen.queryByText("account_deletion_requested")).toBeNull();
+        expect(screen.getByText(/2026/)).toBeInTheDocument();
+        expect(screen.getByText(/取り消せます/)).toBeInTheDocument();
+    });
+
     it("排他 (逆方向): open (行) クリックで read URL は呼ばれない", async () => {
         render(NotificationListItem, { props: { notification: manualAnalyzedItem() } });
 
diff --git a/tests/js/pages/SettingsIndex.test.ts b/tests/js/pages/SettingsIndex.test.ts
index 145f35f..d8aa416 100644
--- a/tests/js/pages/SettingsIndex.test.ts
+++ b/tests/js/pages/SettingsIndex.test.ts
@@ -674,3 +674,114 @@ describe("Settings/Index パスワードカードの出し分け (施策 7)", ()
         expect(formHolder.passwordSetup?.post as ReturnType<typeof vi.fn>).not.toHaveBeenCalled();
     });
 });
+
+/*
+ * T142: 猶予期間つき退会 (凍結方式) の UI 契約。
+ * - 未予約: 主導線は「N 日後に削除 (取り消せます)」、即時削除は副導線として併存
+ * - 予約中: バナー + 取消ボタンだけを出し、削除ボタン群は出さない
+ *   (サーバ側で settings.account.destroy が凍結遮断されることと UI を一致させる)
+ */
+describe("Settings/Index 退会予約 (猶予期間つき削除)", () => {
+    it("未予約時は予約が主導線で、即時削除が副導線として併存する", () => {
+        setProps({
+            accountDeletionState: { requestedAt: null, purgeAfter: null, graceDays: null },
+            accountDeletionGraceDays: 30,
+        });
+        render(Index, { props: {} });
+
+        const request = screen.getByTestId("request-deletion-button");
+        const immediate = screen.getByTestId("delete-account-button");
+        expect(request).toHaveTextContent("30日後に削除 (取り消せます)");
+        expect(immediate).toHaveTextContent("今すぐ完全に削除する (取り消せません)");
+
+        // 主導線が先に現れる (視覚的優先度を口約束にしない)
+        expect(request.compareDocumentPosition(immediate) & Node.DOCUMENT_POSITION_FOLLOWING)
+            .toBeTruthy();
+        // 予約バナーは出ない
+        expect(screen.queryByTestId("deletion-request-banner")).toBeNull();
+    });
+
+    it("予約中はバナーと取消ボタンが出て、削除ボタン群は出ない", () => {
+        setProps({
+            accountDeletionState: {
+                requestedAt: "2026-08-10T09:00:00+09:00",
+                purgeAfter: "2026-09-09T09:00:00+09:00",
+                graceDays: 30,
+            },
+            accountDeletionGraceDays: 30,
+        });
+        render(Index, { props: {} });
+
+        expect(screen.getByTestId("deletion-request-banner")).toBeInTheDocument();
+        expect(screen.getByTestId("cancel-deletion-request-button")).toBeInTheDocument();
+        expect(screen.getByTestId("deletion-request-purge-after")).toHaveTextContent("2026");
+        expect(screen.queryByTestId("request-deletion-button")).toBeNull();
+        expect(screen.queryByTestId("delete-account-button")).toBeNull();
+    });
+
+    it("取消は step-up precheck を挟まずに DELETE する (救済経路に関門を置かない)", async () => {
+        setProps({
+            accountDeletionState: {
+                requestedAt: "2026-08-10T09:00:00+09:00",
+                purgeAfter: "2026-09-09T09:00:00+09:00",
+                graceDays: 30,
+            },
+        });
+        const fetchSpy = vi.fn();
+        vi.stubGlobal("fetch", fetchSpy);
+        render(Index, { props: {} });
+
+        await fireEvent.click(screen.getByTestId("cancel-deletion-request-button"));
+
+        await waitFor(() => expect(routerDeleteMock).toHaveBeenCalled());
+        expect(routerDeleteMock.mock.calls.at(-1)?.[0]).toBe("/settings/account/deletion-request");
+        // recent-auth の precheck (fetch) を一切踏まない
+        expect(fetchSpy).not.toHaveBeenCalled();
+    });
+
+    it("予約は recent-auth precheck を通ってから POST する", async () => {
+        setProps({
+            accountDeletionState: { requestedAt: null, purgeAfter: null, graceDays: null },
+            accountDeletionGraceDays: 30,
+        });
+        stubRecentAuthFresh();
+        render(Index, { props: {} });
+
+        await fireEvent.click(screen.getByTestId("request-deletion-button"));
+
+        await waitFor(() => expect(routerPostMock).toHaveBeenCalled());
+        expect(routerPostMock.mock.calls.at(-1)?.[0]).toBe("/settings/account/deletion-request");
+    });
+
+    it("ブロッカーがあっても予約ボタンは disabled にならない (禁止事項 8)", () => {
+        setProps({
+            accountDeletionBlockers: [
+                { name: "現場A", slug: "genba-a", actions: ["transfer_ownership"] },
+            ],
+            accountDeletionState: { requestedAt: null, purgeAfter: null, graceDays: null },
+            accountDeletionGraceDays: 30,
+        });
+        render(Index, { props: {} });
+
+        expect(screen.getByTestId("request-deletion-button")).not.toBeDisabled();
+        expect(screen.getByTestId("delete-account-button")).not.toBeDisabled();
+    });
+
+    it("予約中でもブロッカーの次の一手は表示され続ける (解消導線を隠さない)", () => {
+        setProps({
+            accountDeletionBlockers: [
+                { name: "現場A", slug: "genba-a", actions: ["transfer_ownership"] },
+            ],
+            accountDeletionState: {
+                requestedAt: "2026-08-10T09:00:00+09:00",
+                purgeAfter: "2026-09-09T09:00:00+09:00",
+                graceDays: 30,
+            },
+        });
+        render(Index, { props: {} });
+
+        expect(screen.getByText("退会するには先に対応が必要です")).toBeInTheDocument();
+        expect(screen.getByText("オーナーを移譲する")).toBeInTheDocument();
+        expect(screen.getByTestId("cancel-deletion-request-button")).toBeInTheDocument();
+    });
+});

```

## mutation 実測記録 (実装者が作成)

# T142 (PR-B: 猶予期間つき削除 / 凍結方式) mutation 実測記録

> 実装完了の条件は「テストが緑」ではなく「**壊すと赤くなることを実測した**」。
> 詳細設計 `devnotes/20260809-0908-account-deletion-grace/detailed-design.md` §共通/mutation の
> **PR-B 該当分**を 1 つずつ適用 → 対象テストが赤いことを実測 → 変異を戻した。
> 全変異は適用後に `git checkout --` で復元済み (最終 `git status --short` が空であることを確認)。
>
> **設計の予測と実測がずれたものは、辻褄を合わせずそのまま記録する。**

## 実測サマリ

| # | 変異 | 設計の予測 | 実測 | 判定 |
|---|------|-----------|------|------|
| M4 | `AccountDeletionFreezeAllowance` から `Settings` を削る | 到達性テスト (取消に到達できない) | **赤**。`AccountDeletionFreezeTest`「予約中でも /settings は 200」が 302 になり、gate の件数 pin (16→15) も赤 | 予測どおり (+ 件数 pin も点灯) |
| M5 | 同 enum に `dashboard` を足す | exact-fit 検査 3 | **赤 — ただし赤くなったのは件数 pin だけ**。検査 3 は「宣言 (enum) と実装 (middleware の分岐) の一致」を測るので、enum に足すと**両側が同時に動く**ため点灯しない | **予測とずれ (記録)** |
| M6 | 凍結 middleware を priority list でテナント境界より前へ | `TenantBoundaryOrderingTest` + 他組織 `{project}` が 302 | **赤**。検査 2 (binding と guard の間に短絡)・検査 5 (列の完全一致)・behavioral (404 期待が 302) の 3 本 | 予測どおり |
| M7 | 執行バッチの終了コードを常に SUCCESS | 「想定外例外で FAILURE」 | **赤** (2 本: 想定外例外 / 非正規行) | 予測どおり |
| M8 | `deleteAccount` の precondition をブロッカー判定の後へ | 「抽出後に取消 → 削除しない」 | **2 形を実測**。(a) `$blockers = …` の**直後** (throw より前) へ動かす形は**緑のまま** = 検出できない。(b) `throw` ブロックの**後**へ動かす形は**赤** (取消済みユーザーに `ValidationException` が出る) | **予測とずれ (記録)**。設計の「判定の後」は (b) の意味であり、(a) の窓は本テストでは検出できない |
| M9 | 通知 `via()` から予約生存の再確認を外す | 「予約 → 即取消 → メール 0 通」 | **赤** (2 本: 即取消 / 再予約時の古い job) | 予測どおり |
| M17 | 同 enum に `settings.account.destroy` を足す | 「予約中は即時削除できない」 | **赤**。gate 検査 8 (名指し pin) + 件数 pin + behavioral (即時削除が `/` へ 302 = 実際に消えた) | 予測どおり |
| M18 | `logout` を `auth`+`verified` group の中へ移す | 凍結 gate 検査 6 (`U` に含まれないこと) | **赤**。※Fortify 登録 route を物理的に動かす代わりに、group 内へ `->name('logout')` の route を足して同値の状況を作った | 予測どおり (再現手段のみ代替) |
| M19 | `requestAccountDeletion` の冪等 no-op を外す | 「予約 POST 2 回でメール 1 通」 | **赤** (2 本: purge_after が 3 日延びる / メールが 2 通) | 予測どおり |
| M20 | 執行バッチの抽出条件から `whereNotNull('deletion_requested_at')` を外す | 「片列だけの非正規行を due に数えない」 | **赤** (`due=0` 期待が満たされない) | 予測どおり |
| M21 | `config/account.php` の `deletion_grace_days` を 0 に | `AccountDeletionGraceConfigTest` の fail-fast | **赤** (検査 2 の値 pin + 検査 3/7/8/9 が `Assert::greaterThan` で例外) | 予測どおり |
| M22 | `purgeAfter()` を `addDaysNoOverflow` に戻す | 「2026-01-31 の 30 日後 = 2026-03-02」 | **赤 — ただし理由が違う**。本リポジトリの Carbon に `addDaysNoOverflow` は**存在せず** `Method addDaysNoOverflow does not exist.` で落ちる。設計が想定した「静かに 28 日へ丸められる」壊れ方は**起きない** | **予測とずれ (記録)**。所見はコードの docblock にも反映済み |
| M23 | 通知 `via()` を `fresh() ?? $notifiable` へ戻す | 「執行済み user へ送らない」 | **赤** | 予測どおり |
| M25 | `recent-auth.confirm` を allowlist から外す | 到達性 (d) 移譲画面へ到達できない | **赤** (step-up 確認画面が 302 + 件数 pin) | 予測どおり |
| M27 | 同 enum に `billing.auto-recharge.update` を足す | 「予約中に auto-recharge 更新が遮断される」 | **赤** (gate 検査 8 の名指し pin + 件数 pin) | 予測どおり |
| M28 | users の CHECK 制約を外し片列だけ UPDATE | migration の DB 制約テスト | **赤** (`QueryException` が飛ばない) | 予測どおり |
| M29 | `PortalConfigurationSpec` の `subscription_update` を `true` に | 凍結 gate の**前提検査 3 点** | **赤**。赤くなったのは `AccountDeletionFreezeRouteGateTest` 検査 7 (`subscription_update.enabled === false`) | 予測どおり。**`billing:ensure-portal-configuration --verify` は spec との一致しか見ないため、この前提 pin が無ければ気づけなかった** |

## 予測とのずれ (3 件) の詳細

### 1. M5 — 「exact-fit 検査 3」は allowlist の**増加**を捕まえない

検査 3 は `U` の全 route に対して middleware を実際に駆動し、「bypass した集合」と
「enum が宣言する集合」が一致することを見る。enum に case を足すと **middleware の挙動も同時に変わる**
ため、両辺が同じだけ動いて一致は保たれる。増加を捕まえるのは
**件数の exact-fit pin (`FREEZE_ALLOWANCE_COUNT`)** と **名指しの pin (検査 8)** の 2 つである。

検査 3 が本当に守るのは「宣言と実装がずれること」— たとえば middleware に prefix 一致や
wildcard を実装で持ち込む改変であり、そちらは検査 3 でしか落ちない。役割が違うので両方残す。

### 2. M8 — precondition の「判定の後」には 2 つの位置がある

- `$blockers = $this->organizationsBlockingDeletion(...)` の**直後 / throw の前**:
  ブロッカー例外は出ないので**テストは緑のまま**。実害は「取消済みユーザーに対して
  無駄なブロッカー評価クエリが走る」ことだけで、観測可能な契約は壊れない。
- `throw` ブロックの**後**: 取消済みユーザーが `ValidationException` を受け、バッチが
  「業務上の保留 (blocked)」と誤分類する。**テストは赤**。

実装は前者よりさらに前 (fresh 取得の直後) に置いてある。テストが固定しているのは
**「ブロッカー例外より前であること」**であり、「ブロッカー評価クエリより前であること」は
固定していない。誇張しないためここに明記する。

### 3. M22 — `addDaysNoOverflow` はこの Carbon に存在しない

設計は「`addDaysNoOverflow` は月末丸めで 30 日未満になるため禁止」と書いていたが、実測では
`Method addDaysNoOverflow does not exist.` で即座に落ちる (静かに壊れる経路ではない)。
したがって現実の危険は *NoOverflow ではなく **日加算を月単位の式へ書き換えること**の側にあり、
それは `AccountDeletionGraceConfigTest` の behavioral 検査
(2026-01-31 + 30 日 = 2026-03-02 / うるう年跨ぎ) が担う。
`CarbonOverflowArithmeticGateTest` の禁止語彙は月・年・四半期のみで日は母集団外である
(gate の定数を実読して確認済み)。

## この PR で実施していない mutation

M1 / M2 / M3 / M10〜M16 / M24 / M26 は PR-A・PR-C1・PR-C2・PR-C3 の担当 (本 PR の変更対象外)。
M1〜M3 の実測は `devnotes/20260810-1004-todo-T141/mutation-evidence.md` にある。


## テスト結果サマリー

- `composer phpstan` (level 10): **OK / エラー 0 件**
- `vendor/bin/pint --test`: passed
- `composer test` (Pest, 並列): 4269 tests / 全 green (skipped 2 は既存)
  - 新規: `tests/Architecture/AccountDeletionGraceConfigTest.php` (9)
  - 新規: `tests/Architecture/AccountDeletionFreezeRouteGateTest.php` (9)
  - 新規: `tests/Feature/Auth/AccountDeletionGraceTest.php` (28)
  - 新規: `tests/Feature/Auth/AccountDeletionFreezeTest.php` (14)
  - 新規: `tests/Feature/Console/PurgeDeletionRequestsCommandTest.php` (10)
- `pnpm lint` / `pnpm typecheck` / `pnpm test`: passed (JS 1292 tests → 新規 6 + 1 を追加)

## design system 参照 (diff が resources/js を含むため)

触れた atomic ディレクトリ:
- `resources/js/pages/Settings/Index.svelte` (pages 層)
- `resources/js/components/features/notifications/NotificationListItem.svelte` (features 層)
- 再利用した既存 atom / molecule: `Alert` / `Button` / `Card` / `TextLink` / `DangerZone` / `FormField`
- 新規に追加した component は **0 件** (既存 atom の組み合わせのみ)。アイコンは `@lucide/svelte` の `UserRoundX` を追加使用。
- 使用した token 系クラス: `text-body` / `text-caption` / `text-danger` / `text-text-secondary` / `border-border` / `bg-primary-soft` など既存 utility のみ。**hex 直書き (`#RRGGBB`) は 1 件も追加していない**。
- Button variant は既存の `danger` / `danger-ghost` / `primary` を使用 (新 variant 追加なし)。

## 質問

1. 凍結 allowlist (`AccountDeletionFreezeAllowance`) に **穴** または **詰み** があるか。
2. 予約 / 取消 / 執行のロック順序と冪等性に破れがあるか。
3. gate の「保証しないもの」の記述が誇張・過小になっていないか。
4. PR-B の範囲を超えた実装 (PR-C の先取り) が混ざっていないか。
