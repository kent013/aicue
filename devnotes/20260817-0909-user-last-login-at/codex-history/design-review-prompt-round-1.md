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

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠: design token 経由で color / radius / typography を参照する設計か、hex 直書きを増やさないか
11. Atomic Design準拠: atoms/molecules/organisms/templates の責務分離に沿った配置か。アイコンは Lucide 前提で SVG 直書きを新設していないか

【この設計に特有の、重点的に検証してほしい点】
- **施策 A のクエリと型**: `selectRaw('user_id, max(occurred_at) as last_login_at')` + `groupBy` +
  `withCasts(['last_login_at' => 'immutable_datetime'])` の組み合わせは、Laravel 12 / pgsql で
  期待どおり CarbonImmutable を返すか。`Assert::numeric` / `Assert::isInstanceOf` で
  PHPStan level 10 を通せるか。より素直な書き方があるなら示せ。
- **施策 D の索引置き換え**: 新索引が旧索引を包含するという主張は正しいか。
  `dropIndex(['user_id','event_type'])` の配列指定が既定命名を正しく再構成するか。
  up/down の順序に問題はないか。
- **数え方の網羅性**: 「Login イベントの発火集合 = 数える集合」という設計は、
  数え落とし・数え過ぎを生まないか。とくに 2FA 途中離脱・招待受諾・Filament の admin guard の扱い。
- **施策 F**: 「トリップワイヤは RC-8 として既に存在するので新しい gate を足さない」という判断は妥当か。
- **テスト計画**: G-1 の 8 件と G-2 で、この設計の不変条件を実際に守れるか。抜けている検査はないか。
  とくにテスト 7 (remember me) の代替案への落とし方は妥当か。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: user-last-login-at (最終ログイン日時の記録と表示)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- 新モデルを追加する設計では **対応する Factory の作成も施策に含める**（本設計では施策 E）
- **DTO + JsonResource** パターン（AGENTS.md 参照）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- `declare(strict_types=1)` + 日本語コメント

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md)（Codex conceptual-review Round 1 で **APPROVED**）
- 対応マトリクス: [codex-history/conceptual-review-decisions-round-1.md](./codex-history/conceptual-review-decisions-round-1.md)

**概念設計の中心判断（本詳細設計はこれを前提にする）**:
`users.last_login_at` **カラムを新設しない**。`security_audit_events` の `event_type='login'` 行
（`RecordSecurityEvent` が既に `Illuminate\Auth\Events\Login` を購読して書いている）から導出する。
**記録経路を 1 本も新設しない / 列を 1 本も足さない / 変更系 route を 1 本も足さない。**

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| A | 最終ログインの一括取得サービス | `app/Services/Security/LastLoginLookup.php`（新規） | High |
| B | Inertia props への追加 | `app/DataTransferObjects/Admin/MemberRowData.php` / `app/Http/Controllers/Admin/UserManagementController.php` | High |
| C | TS 型と画面表示 | `resources/js/types/admin.ts` / `resources/js/pages/Admin/Users.svelte` | High |
| D | 監査表の索引の置き換え | `database/migrations/2026_08_17_000100_replace_security_audit_events_user_event_index.php`（新規。**列は足さない**） | Medium |
| E | `SecurityAuditEventFactory` の新設 | `database/factories/SecurityAuditEventFactory.php`（新規） / `app/Models/SecurityAuditEvent.php` / `docs/factories.md` | High |
| F | 保持期間台帳への依存の明記 | `tests/Support/Retention/RetentionTableRegistry.php`（**区分は変えない**） | Medium |
| G | テスト | `tests/Feature/Admin/UserManagementPageTest.php` / `tests/Feature/Admin/UserManagementLastLoginQueryCountTest.php`（新規） / `tests/js/pages/AdminUsers.test.ts` | High |

### 波及変更の全体像（インターフェース変更の影響）

`MemberRowData` は Inertia props の構造体であり、TS 側 `MemberRow` と**対で保守する**契約が
両ファイルの doc comment に明記されている。フィールドを 1 つ足すと以下が連動する。

| 層 | 影響 | 施策 |
|---|---|---|
| TypeScript 型定義 | `resources/js/types/admin.ts` の `MemberRow` に `lastLoginAt` | C |
| Inertia Props | `Admin/Users.svelte` の `members` 経由（Props interface 自体は `MemberRow[]` のままで変更不要） | C |
| API Resource / DTO | `MemberRowData`（Inertia 専用 DTO。JsonResource は経由しない = API 露出なし） | B |
| テストファイル (PHP) | `UserManagementPageTest`（既存の shape assertion に列が増える） | G |
| テストファイル (TS) | `AdminUsers.test.ts` の `membersFixture` **4 行すべて**に `lastLoginAt` を足す（型必須なので足さないと `pnpm typecheck` が落ちる） | G |
| Browser lane | **影響なし**（`/manage/users` の Browser テストは存在しない。DOM 契約を新設しない） | — |
| Filament | **影響なし**（`SecurityAuditEventResource` の表示は変えない） | — |

---

## 施策 A: 最終ログインの一括取得サービス

### 変更箇所

- 新規ファイル: `app/Services/Security/LastLoginLookup.php`
- 置き場所の根拠: `app/Services/Security/` は既に `SecurityEventRecorder`（**書き込みの唯一の窓口**）を持つ。
  本クラスはその表の**読み取り**であり、同じ名前空間に置くのが自然。
  `SecurityEventRecorder` に読み取りメソッドを生やさない（窓口の責務は「記録」であり、
  読み取りを混ぜると窓口の意味が薄まる。思考原則 4）。

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし（本クラスは DTO を返さず `array<int, CarbonImmutable>` の写像を返す。§型の根拠を参照）
- テストファイル: `tests/Feature/Admin/UserManagementLastLoginQueryCountTest.php`（新規。施策 G）

### 現行コード

存在しない（新規）。参考にする既存の「1 クエリで写像を作る」流儀は
`UserManagementController::index` の `$pivotRoles` 構築（L42-53）。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\SecurityEventType;
use App\Models\SecurityAuditEvent;
use Carbon\CarbonImmutable;
use Webmozart\Assert\Assert;

/**
 * 「この利用者は最後にいつこのシステムに入ったか」の読み取り。
 *
 * **記録点を増やさない**: 出所は security_audit_events の `login` 行だけである
 * (書き込みの窓口は SecurityEventRecorder。本クラスは読み取り専用で 1 行も書かない)。
 * users に last_login_at 列を持たない理由は
 * devnotes/20260817-0909-user-last-login-at/conceptual-design.md §2 が正本。
 *
 * **数える対象**: `Illuminate\Auth\Events\Login` が発火したセッション確立すべて
 * (パスワード / 2FA 完了 / パスキー / SSO / remember me による自動復元 / 登録直後)。
 * remember me を**除外しない**ことが App\Listeners\Auth\StampRecentAuthOnLogin との
 * 意図的な差である (あちらの問いは「たった今資格情報を提示したか」で、本クラスの問いは
 * 「最後に入ったのはいつか」。同じイベントを別条件で読む 2 概念であり統合しない)。
 * 機械アクセス (API キー / OAuth トークン) は Login を発火しないため構造的に入らない。
 *
 * ⚠ **前提**: users プロバイダを持つセッション系 guard は現在 `web` だけである。
 * 新しいセッション guard / loginUsingId / impersonation / magic-link を足すときは
 * 数え方を読み直すこと (StampRecentAuthOnLogin の ⚠ 注記と同じ性質の前提に立っている)。
 *
 * ⚠ **保証しないもの**: 値は「最終**ログイン**」であって「最終**活動**」ではない。
 * remember me の cookie が生きている間は再ログインが起きないため、値は
 * 実際の利用より古くなりうる (仕様。doc/02 §2.4 の項目名に従う)。
 * また security_audit_events の保持期間は未確定であり、将来 purger が入れば
 * 古い値から失われる (この依存は RetentionTableRegistry の根拠文に記録してある)。
 */
final class LastLoginLookup
{
    /**
     * 利用者 id の集合に対する最終ログイン時刻の写像を **1 クエリ**で作る。
     *
     * 行ごとに問い合わせない (N+1 を作らない)。ログイン記録の無い利用者は
     * **キーごと現れない** (null を詰めない = 呼び出し側が `?? null` で受ける)。
     *
     * @param  list<int>  $userIds
     * @return array<int, CarbonImmutable>
     */
    public function forUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return []; // 空集合に whereIn を投げない (アーリーリターン)
        }

        $rows = SecurityAuditEvent::query()
            ->selectRaw('user_id, max(occurred_at) as last_login_at')
            ->whereIn('user_id', $userIds)
            ->where('event_type', SecurityEventType::Login->value)
            ->groupBy('user_id')
            // 集計列にはモデルの casts が効かない (occurred_at の cast は別名には伝播しない)。
            // driver 差 (string / DateTime) を SQL 層で吸収せず、framework の cast で閉じる。
            ->withCasts(['last_login_at' => 'immutable_datetime'])
            ->get();

        /** @var array<int, CarbonImmutable> $map */
        $map = [];
        foreach ($rows as $row) {
            $userId = $row->getAttribute('user_id');
            // bigint の PHP 表現は driver 設定で int / numeric-string に揺れるため numeric で受ける
            Assert::numeric($userId);

            $lastLoginAt = $row->getAttribute('last_login_at');
            // 集計値が null になるのは group が成立しない場合だけなので、ここは常に日時である。
            // 黙って捨てず instanceof で narrowing する (mixed を外へ出さない = level 10 対応)
            Assert::isInstanceOf($lastLoginAt, CarbonImmutable::class);

            $map[(int) $userId] = $lastLoginAt;
        }

        return $map;
    }
}
```

### 型の根拠（Codex conceptual Round 1 [Warning] への対応）

`max(occurred_at)` の値はモデルの `casts()` が効かない（cast は実列 `occurred_at` に対してのみ定義され、
別名 `last_login_at` には伝播しない）。driver によって `string` / `DateTime` に揺れるため、
**framework 標準の `Builder::withCasts()`**（`vendor/laravel/framework/.../Eloquent/Builder.php` L1976）で
`immutable_datetime` に固定する。自前で `CarbonImmutable::parse()` の分岐を書かない
（思考原則 1: フレームワークのレンジ内でやる）。
`Assert::isInstanceOf` は narrowing と fail-loud を同時に満たす（黙って捨てる `else` を作らない）。

### セキュリティ不変条件との突き合わせ

| 不変条件 | 判定 |
|---|---|
| 3. cross-org 不可 / クラス起点の主キー同一性クエリ | **母集団に入らない**。本クラスのクエリは `whereIn('user_id', …)` であり、`SecurityAuditEvent` の**主キー**に対する同一性述語（`find` / `whereKey` / `where('id', …)`）を 1 つも持たない。`PrimaryKeyStaticQueryScanner` の `OWNER_COLUMNS` は `user_id` を**テナント絞り込み側**として扱う。よって `DirectFetchInventory` への登録は不要（実装時に `composer test` で確認する） |
| cross-org の実質 | `$userIds` の出所は**呼び出し側が org relation から取った集合だけ**である（施策 B）。本クラス自身は org を知らないので、org を跨ぐ集合を渡すと跨いで返す。**この責務境界を doc comment に書かない**（書くと「渡す側が正しければ安全」という弱い保証を強い保証のように読ませる）代わりに、施策 B 側で relation 経由の集合しか渡らないことを固定し、テストで cross-org 混入を検査する（施策 G のテスト 5） |
| 9. 変更系 route は認可を通る | **母集団が増えない**（GET のみ。route を足さない） |
| 6. PII は CipherSweet | `security_audit_events` に PII 列は無い。`user_id` / `event_type` / `occurred_at` のみ触る |
| 11. キャッシュに入れるのは素のデータだけ | **キャッシュを使わない**（毎回クエリする。1 クエリで足りるため） |

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`array<int, CarbonImmutable>`）
- [x] null 安全（`Webmozart\Assert\Assert` で `mixed` を narrowing。`mixed` を外へ出さない）
- [x] DTO を返している（値の写像を返す。`response()->json()` を書かない）
- [x] Generics の型パラメータが正しい（`@param list<int>` / `@return array<int, CarbonImmutable>`）
- [x] `declare(strict_types=1)` + `final`

### リスク

- **行数の増加に対する読み取り性能**。`security_audit_events` は保持期間未確定 = 単調増加が確定している。
  1 利用者あたりの login 行は年単位で数千行になりうる。→ **施策 D で索引を張り替える**。
- **`withCasts` の挙動が将来の Laravel で変わる可能性**。→ 施策 G のテスト 1（値が ISO8601 で props に出る）が
  実 DB 経由で固定するため、変わればテストが落ちる。

---

## 施策 B: Inertia props への追加

### 変更箇所

- `app/DataTransferObjects/Admin/MemberRowData.php`（全体）
- `app/Http/Controllers/Admin/UserManagementController.php` (L32-79)

### 波及変更

- TypeScript 型定義: `resources/js/types/admin.ts` の `MemberRow`（施策 C）
- API Resource/DTO: `MemberRowData` 自身。**JsonResource は経由しない**（Inertia 専用）
- テストファイル: `tests/Feature/Admin/UserManagementPageTest.php`（施策 G）

### 現行コード（MemberRowData）

```php
final readonly class MemberRowData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $roleState,       // MemberRoleState value
        public string $roleLabel,
        public string $twoFactorStatus, // disabled|pending|enabled
        public bool $isSelf,
    ) {}

    public static function fromUser(User $user, ?OrganizationRole $orgRole, ?ProjectRole $projectRole, int $currentUserId): self
    {
        $state = MemberRoleState::derive($orgRole, $projectRole);

        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            roleState: $state->value,
            roleLabel: $state->label(),
            twoFactorStatus: $user->twoFactorStatus()->value,
            isSelf: $user->id === $currentUserId,
        );
    }
}
```

### 変更後コード（MemberRowData）

```php
/**
 * ユーザー管理画面 (Admin/Users) のメンバー 1 行分。TS 側 types/admin.ts の MemberRow と対で保守。
 * 表示状態 (roleState) は org ロール × Default Project pivot から毎回導出する (概念設計 D2(a))。
 * email は CipherSweet 復号値。本画面は manageMembers 権限者しか到達できない (403) ため
 * 行レベルの可視性分岐は持たない (PII 可視性は画面到達境界で担保)。
 *
 * lastLoginAt は「最後にいつ入ったか」であり、users の列ではなく security_audit_events の
 * login 行から導出する (App\Services\Security\LastLoginLookup)。**履歴は持たない**。
 * 記録が無い利用者は null で、UI は「記録なし」と表示する — 「一度も入っていない」と
 * 断定しないのは、導出元の保持期間が未確定で将来 purge されうるためである。
 */
final readonly class MemberRowData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $roleState,       // MemberRoleState value
        public string $roleLabel,
        public string $twoFactorStatus, // disabled|pending|enabled
        public bool $isSelf,
        public ?string $lastLoginAt,    // ISO8601 (オフセット付き) / 記録が無ければ null
    ) {}

    public static function fromUser(
        User $user,
        ?OrganizationRole $orgRole,
        ?ProjectRole $projectRole,
        int $currentUserId,
        ?CarbonImmutable $lastLoginAt,
    ): self {
        $state = MemberRoleState::derive($orgRole, $projectRole);

        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            roleState: $state->value,
            roleLabel: $state->label(),
            twoFactorStatus: $user->twoFactorStatus()->value,
            isSelf: $user->id === $currentUserId,
            // オフセット付きで出す。toDateTimeString() は使わない —
            // 端末側 Intl が UTC を現地時刻として解釈し 9 時間ずれる
            lastLoginAt: $lastLoginAt?->toIso8601String(),
        );
    }
}
```

**引数を末尾に足す**（既存の位置引数の順序を変えない = 呼び出し側の破壊を最小にする）。
`?CarbonImmutable` を**必須引数**にする（既定値 `null` を与えない）ことで、
将来 `fromUser` の呼び出し元が増えたときに「渡し忘れて全員 null」が静かに起きない。

### 現行コード（UserManagementController::index、抜粋）

```php
$members = [];
foreach ($organization->users()->get() as $member) {
    $members[] = MemberRowData::fromUser(
        $member,
        $member->organizationRole($organization),
        $pivotRoles[$member->id] ?? null,
        $user->id,
    );
}
```

### 変更後コード（UserManagementController::index、抜粋）

```php
public function index(
    Request $request,
    DefaultProjectResolver $defaultProjects,
    LastLoginLookup $lastLogins,   // ← DI で受ける (container 解決。new しない)
): Response {
    $organization = $this->resolveCurrentOrganization($request);
    Gate::authorize('manageMembers', $organization); // 撮影者・一般メンバーは 403

    // …（$pivotRoles の構築は現行のまま）…

    // メンバー集合は org relation 経由でのみ解決する (cross-org 越境不能)
    $organizationMembers = $organization->users()->get();

    // 最終ログインは行ごとに引かず、id 集合に対して 1 クエリで写像を作る (N+1 を作らない)。
    // 渡す id 集合は上の relation の結果そのものなので、他組織の利用者は構造的に入らない。
    /** @var list<int> $memberIds */
    $memberIds = $organizationMembers->pluck('id')->all();
    $lastLoginMap = $lastLogins->forUserIds($memberIds);

    $members = [];
    foreach ($organizationMembers as $member) {
        $members[] = MemberRowData::fromUser(
            $member,
            $member->organizationRole($organization),
            $pivotRoles[$member->id] ?? null,
            $user->id,
            $lastLoginMap[$member->id] ?? null,
        );
    }

    // …（invitations / Inertia::render は現行のまま）…
}
```

**変更点は 3 つだけ**: (1) DI 引数を 1 つ足す、(2) `$organization->users()->get()` を変数に束ねて
2 度引かない、(3) 写像を作って `fromUser` へ渡す。認可・組織解決・招待の取得は**一切触らない**。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`Response`。既存のまま）
- [x] null 安全（`$lastLoginMap[$member->id] ?? null` で不在キーを明示的に null へ）
- [x] DTO を返している（`MemberRowData`。`response()->json()` 無し）
- [x] Generics の型パラメータが正しい（`pluck('id')->all()` は `list<int>` として PHPDoc で宣言。
      必要なら `array_map(static fn (User $m): int => $m->id, $organizationMembers->all())` に置き換える。
      実装時に `composer phpstan` の出力で確定する — **型を緩めて黙らせない**）

### リスク

- `fromUser` のシグネチャ変更は**呼び出し元が 1 か所しかない**（`UserManagementController`。
  実読で確認済み）ため波及は小さい。
- `$organization->users()->get()` を 2 度呼ばない形に変える副次効果としてクエリが 1 本減る
  （現行は relation を 1 回だけ呼んでいるので実際には増減なし。**性能改善を主張しない**）。

---

## 施策 C: TS 型と画面表示

### 変更箇所

- `resources/js/types/admin.ts` (L12-20)
- `resources/js/pages/Admin/Users.svelte` (L296-311 のメンバー情報ブロック / import 節)

### 波及変更

- TypeScript 型定義: 本施策そのもの
- API Resource/DTO: なし
- テストファイル: `tests/js/pages/AdminUsers.test.ts` の `membersFixture` **4 行すべて**（施策 G）。
  `lastLoginAt` は optional にしないので、足さなければ `pnpm typecheck` が落ちる（意図的）

### 現行コード（types/admin.ts）

```ts
export interface MemberRow {
    id: number;
    name: string;
    email: string;
    roleState: MemberRoleState;
    roleLabel: string;
    twoFactorStatus: "disabled" | "pending" | "enabled";
    isSelf: boolean;
}
```

### 変更後コード（types/admin.ts）

```ts
export interface MemberRow {
    id: number;
    name: string;
    email: string;
    roleState: MemberRoleState;
    roleLabel: string;
    twoFactorStatus: "disabled" | "pending" | "enabled";
    isSelf: boolean;
    /**
     * 最終ログイン日時 (ISO8601、オフセット付き)。記録が無ければ null。
     * 出所は security_audit_events の login 行 (users の列ではない)。
     * null は「一度も入っていない」と「記録が残っていない」を区別しない。
     */
    lastLoginAt: string | null;
}
```

### 現行コード（Admin/Users.svelte、メンバー情報ブロック）

```svelte
<div class="min-w-0 sm:min-w-40">
    <div class="flex items-center gap-2">
        <p class="truncate text-body">{member.name}</p>
        {#if member.twoFactorStatus === "enabled"}
            <Badge tone="success">2FA</Badge>
        {/if}
        {#if member.roleState === "unassigned"}
            <Badge tone="warning" testId={`unassigned-${member.id}`}>
                未割当
            </Badge>
        {/if}
    </div>
    <p class="truncate text-caption text-text-secondary">
        {member.email}
    </p>
</div>
```

### 変更後コード（Admin/Users.svelte）

import 節に 1 行足す:

```ts
import { formatDateTime } from "@/lib/date-format";
```

メンバー情報ブロック（メール行の直後に 1 行足す）:

```svelte
    <p class="truncate text-caption text-text-secondary">
        {member.email}
    </p>
    <!-- 最終ログイン。値の無い行は「記録なし」(「未ログイン」と断定しない — 導出元の
         security_audit_events は保持期間が未確定で、将来 purge されうるため)。
         表示は閲覧者の端末タイムゾーンで行う (date-format.ts の Intl 経由。DS token のみ) -->
    <p
        class="truncate text-caption text-text-secondary"
        data-testid={`member-last-login-${member.id}`}
    >
        最終ログイン {formatDateTime(member.lastLoginAt, "記録なし")}
    </p>
```

### 設計判断

- **`formatDateTime` の fallback 引数で null を吸収する**（Codex conceptual Round 1 の
  「`null` 分岐後にのみ `formatDateTime()` を呼ぶ」提案は採らない）。
  `resources/js/lib/date-format.ts` は「各ページに散在しがちな `toLocaleDateString('ja-JP')` 呼び出しと
  **null/不正値ハンドリングの SSoT**」と自ら宣言しており、呼び出し側で null 分岐を書くのは
  その SSoT を迂回することになる。`Billing/Index.svelte` の `formatDate(page.balance.nextExpireAt, "—")` が
  既存の準拠例である。
- **`formatDate` ではなく `formatDateTime`** を使う。休眠判定は日付粒度で足りるが、
  doc/02 §2.4 の項目名が `最終ログイン日時` であり、`PasskeySection` の「最終利用」（日付のみ）とは
  意味の粒度が違う（あちらは資格の使用痕跡、こちらは在籍の指標）。
- **DS token のみ**: `text-caption` / `text-text-secondary` は `DESIGN.md` L132 / L96 に定義済み。
  hex 直書き無し、新規 token 無し、新規 component 無し、アイコン追加無し。
- **atomic import 階層に影響なし**: `pages` から `lib` を import する形は既存
  （`Billing/Index.svelte` / `Capture/Index.svelte` と同一）。component 層を跨がない。
- **操作列には入れない**。読み取り情報は左の情報ブロックへ、操作は右の actions 列へ、という
  現行の分離を保つ。F-14（375px でのモバイル縦積み）のレイアウトは、情報ブロック内の
  `<p>` が 1 本増えるだけなので横幅に影響しない。

### リスク

- 行が 1 本増えることでモバイル（375px）のリスト密度が下がる。→ 情報ブロックは既に
  `flex-col` の縦積みで、追加は縦方向のみ。**横スクロールは発生しない**（F-14 の最悪幅構成は
  actions 列側で決まる）。
- `truncate` を付けるため、極端に長いロケール表現でも溢れない。

---

## 施策 D: 監査表の索引の置き換え

### 変更箇所

- 新規: `database/migrations/2026_08_17_000100_replace_security_audit_events_user_event_index.php`
- **`users` にも `security_audit_events` にも列を 1 本も足さない**

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: なし（索引は挙動を変えない。`RetentionTableClassificationTest` は
  **表**単位の台帳であり列も索引も見ないため、台帳の件数・区分は不変）

### 現行コード

`database/migrations/2026_06_11_071300_create_security_audit_events_table.php`:

```php
$table->index(['user_id', 'event_type']);   // 既定名: security_audit_events_user_id_event_type_index
$table->index('occurred_at');
```

### 変更後コード

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * security_audit_events の複合索引を occurred_at まで伸ばす (**列は足さない**)。
 *
 * 用途: /manage/users の最終ログイン表示が
 *   select user_id, max(occurred_at) … where user_id in (…) and event_type = 'login' group by user_id
 * を撃つ。既存の ['user_id','event_type'] は絞り込みには効くが最大値の取得には効かず、
 * 該当利用者の login 行を全件読むことになる。本表は保持期間が未確定 = 単調増加が確定しているため、
 * 行数が増えるほど遅くなる形を残さない。
 *
 * **追加ではなく置き換え**である。新索引は旧索引の前方一致 (user_id, event_type) を完全に含むため、
 * 既存の利用箇所 (Filament の絞り込み等) はそのまま効く。並走を残さない (AGENTS.md 思考原則 3)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('security_audit_events', function (Blueprint $table): void {
            // 先に新索引を張ってから旧索引を落とす (索引が 1 本も無い瞬間を作らない)
            $table->index(['user_id', 'event_type', 'occurred_at']);
            $table->dropIndex(['user_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::table('security_audit_events', function (Blueprint $table): void {
            $table->index(['user_id', 'event_type']);
            $table->dropIndex(['user_id', 'event_type', 'occurred_at']);
        });
    }
};
```

### 既存行の default と backfill 方針（migration を追加するため明示する）

- **列を足さないので default は存在しない**。既存行に書き込む値も無い。
- **backfill は行わない / 必要ない**。`security_audit_events` の `login` 行は
  2026-06 の表作成以降**すべて記録済み**であり（`RecordSecurityEvent` が最初から購読している）、
  既存ユーザーの最終ログインは**索引を張り替えた瞬間から正しい値が出る**。
  これが実質の backfill にあたる（データ移行の作業は 0 件）。
- **一度もログインしていない既存ユーザー**（seeder 由来など）は写像にキーが現れず、
  props が `null` になり、画面は「記録なし」を表示する（施策 C）。

### 索引名と lock（Codex conceptual Round 1 の Suggestion への対応）

- **索引名は Laravel の既定命名に任せる**（`security_audit_events_user_id_event_type_occurred_at_index`）。
  `dropIndex(['user_id','event_type'])` も配列指定なら既定命名を再構成するため、
  名前をコードに直書きしない（既存 migration が既定命名で張っているため一致する）。
- **lock**: pgsql の `CREATE INDEX`（非 CONCURRENTLY）は対象表に `SHARE` lock を取り、
  索引構築の間 INSERT を止める。`security_audit_events` へ INSERT するのは認証経路
  （ログイン / ログアウト / ログイン失敗）である。
- **`CONCURRENTLY` は使わない**。理由: (a) `CREATE INDEX CONCURRENTLY` は
  トランザクション内で実行できず、Laravel の migration は pgsql で既定でトランザクションに包むため
  `public $withinTransaction = false;` が要り、**失敗時に途中状態が残る**（invalid index）。
  (b) 現時点で本表の行数は小さく、リポジトリにデプロイ定義が存在しない
  （AGENTS.md の route:cache 運用要件の注記）ため、無停止索引構築を要する運用条件が**まだ無い**。
  過剰な機構を先回りして作らない（思考原則 2）。
- **将来の見直し条件**: 本表が実運用で百万行規模に達し、かつ無停止デプロイ基盤ができたときは
  `CONCURRENTLY` + `$withinTransaction = false` + 失敗時の再実行手順をセットで設計し直す。

### PHPStan 適合チェック

- [x] `declare(strict_types=1)` あり
- [x] closure の引数・戻り値に型を明示（`function (Blueprint $table): void`）

### リスク

- **索引の張り替え中に認証経路の INSERT が待つ**。現行のデータ量では体感できない長さ。
- **旧索引に依存する未知のクエリがあると遅くなる**。→ 新索引が旧索引の前方一致を完全に含むため、
  プランナは同じように使える（B-tree の前方一致性）。**性能が上がるとは主張しない**（等価かそれ以上）。

---

## 施策 E: `SecurityAuditEventFactory` の新設

### 変更箇所

- 新規: `database/factories/SecurityAuditEventFactory.php`
- `app/Models/SecurityAuditEvent.php`（`HasFactory` trait の追加。現在は付いていない）
- `docs/factories.md`（Factory 一覧への追記。AGENTS.md 実装規約で必須）

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 施策 G が使う

### 現行コード

`SecurityAuditEvent` は `HasFactory` を使っていない（実読で確認）。
既存テストは本モデルを**読むだけ**で、生成は「実際にログインさせる」形で行っている
（`SecurityAuditTrailCoverageTest` / `OwnershipTransferTest` 等）。

### 変更後コード

`app/Models/SecurityAuditEvent.php`:

```php
use Illuminate\Database\Eloquent\Factories\HasFactory;

class SecurityAuditEvent extends Model
{
    /** @use HasFactory<SecurityAuditEventFactory> */
    use HasFactory;

    // …（既存のまま）…
}
```

`database/factories/SecurityAuditEventFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SecurityEventType;
use App\Models\SecurityAuditEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SecurityAuditEvent>
 *
 * 監査行そのものを作る factory。**アプリの記録経路ではない**
 * (本番の記録は App\Services\Security\SecurityEventRecorder の 1 本道のみ)。
 * 過去時刻の行 (「3 か月前のログイン」等) をテストで用意するために置く。
 */
class SecurityAuditEventFactory extends Factory
{
    protected $model = SecurityAuditEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event_type' => SecurityEventType::Login->value,
            'metadata' => ['guard' => 'web'],
            'ip_address' => $this->faker->ipv4(),
            'occurred_at' => CarbonImmutable::now(),
        ];
    }

    /** 記録対象の利用者を指定する (user_id は所有権キーのため state で明示代入する) */
    public function forUser(User $user): self
    {
        return $this->state(fn (): array => ['user_id' => $user->id]);
    }

    /** 種別を差し替える (login 以外を数えないことの検査に使う) */
    public function ofType(SecurityEventType $type): self
    {
        return $this->state(fn (): array => ['event_type' => $type->value]);
    }

    /** 発生時刻を指定する (最新の 1 件が選ばれることの検査に使う) */
    public function occurredAt(CarbonImmutable $at): self
    {
        return $this->state(fn (): array => ['occurred_at' => $at]);
    }
}
```

`docs/factories.md` の一覧へ 1 行追記:

```
| `SecurityAuditEventFactory` | SecurityAuditEvent | `forUser($user)`, `ofType(SecurityEventType)`, `occurredAt(CarbonImmutable)`。既定は `login` / `now()` / guard=web。**本番の記録経路ではない** (記録の窓口は SecurityEventRecorder) |
```

### 設計判断

- `user_id` は `$fillable` 外の所有権キーだが、**Factory は `$fillable` を経由しない**
  （`Model::newInstance()` → `forceFill` 相当）ため state で直接指定してよい。
  これは `TakeUploadReservationFactory` が `organization_id` を state で入れているのと同じ流儀。
- **`SecurityEventRecorder` を factory から呼ばない**。窓口は本番の記録経路であり、
  テストの都合で過去時刻を注入できるようにすると窓口の契約（`occurred_at = now()`）が緩む。

### PHPStan 適合チェック

- [x] `@extends Factory<SecurityAuditEvent>` を宣言
- [x] `definition()` の戻り値型 `array<string, mixed>`
- [x] state closure の戻り値型を明示

### リスク

- `HasFactory` の追加はモデルの振る舞いを変えない（trait はメソッドを増やすだけ）。

---

## 施策 F: 保持期間台帳への依存の明記

### 変更箇所

- `tests/Support/Retention/RetentionTableRegistry.php` の `security_audit_events` entry の**根拠文のみ**

### 波及変更

- **区分は `undecided` のまま変えない**。よって `RetentionTableClassificationTest` の
  `RETENTION_TABLE_COUNT`（63）も `RETENTION_UNDECIDED_TABLES`（`security_audit_events` を含む）も**変えない**
- テストファイル: **新規テストを作らない**（下記の重要な発見を参照）

### 重要な発見: トリップワイヤは既に存在する

概念設計 §2-4 決着 3(b) は「区分そのものを pin するトリップワイヤを置く」としたが、
**実装済みだった**。`tests/Feature/Retention/RetentionTableClassificationTest.php` の
`RC-8`（L461-474）が `RETENTION_UNDECIDED_TABLES` を**現在値ちょうどで pin** しており、
その一覧に `security_audit_events` が含まれている（L66）。

したがって、誰かが `security_audit_events` に保持期間を決めて区分を動かした瞬間、
RC-8 が「未確定の表の一覧が変わりました」で落ち、その定数を書き換えるレビューが必ず発生する。
**新しい gate を足さない**（同じ事実を 2 か所で検査しない。思考原則 2 / AGENTS.md「二重検査を作らない」）。
本施策がやるのは、その瞬間に読まれる根拠文へ**依存の事実を書き足すこと**だけである。

### 現行コード

```php
RetentionTableEntry::undecided(
    'security_audit_events',
    '認証と権限に関わる操作の証跡。利用者への外部キーが空値化のため退会後も行が残る。'
    .'監査に必要な保持期間が未決である',
),
```

### 変更後コード

```php
RetentionTableEntry::undecided(
    'security_audit_events',
    '認証と権限に関わる操作の証跡。利用者への外部キーが空値化のため退会後も行が残る。'
    .'監査に必要な保持期間が未決である。'
    .'なおこの表の login 行は /manage/users の最終ログイン表示の唯一の出所であり、'
    .'期限を決めて古い行を消すと、休眠の判定に必要な古い値から先に失われる。'
    .'期限を決めるときは devnotes/20260817-0909-user-last-login-at/ を読み直すこと',
),
```

### PHPStan 適合チェック

- [x] 根拠文は 30 文字以上（`RetentionTableEntry::RATIONALE_MIN_LENGTH` = 30。既に大幅に超過）
- [x] 型の変更なし（`undecided()` の呼び出し形は不変）

### リスク

- 根拠文の変更だけなので機械検査は落ちない（RC-3 は長さのみを見る）。
- `devnotes/` へのパス参照は将来ディレクトリが消えると死ぬ。→ devnotes はコミット対象であり
  削除しない運用（AGENTS.md「設計・TODO・devnotes の運用」）。

---

## 施策 G: テスト

### 変更箇所

- `tests/Feature/Admin/UserManagementPageTest.php`（既存 150 行に追記）
- `tests/Feature/Admin/UserManagementLastLoginQueryCountTest.php`（新規）
- `tests/js/pages/AdminUsers.test.ts`（既存 504 行。fixture 更新 + テスト追加）

### テストファースト（AGENTS.md 思考原則 5）

実装前に **G-1 と G-2 を書いて fail を確認**してから施策 A〜C に入る
（`lastLoginAt` が props に無いので `assertInertia` の `where` が落ちる）。

### G-1. Feature: props の値（`UserManagementPageTest.php` に追記）

| # | テスト名 | 検証内容 |
|---|---|---|
| 1 | login 記録のあるメンバーは lastLoginAt に ISO8601 が載る | `SecurityAuditEventFactory` で `login` 行を作り、props の `members.*.lastLoginAt` が `CarbonImmutable` の `toIso8601String()` と一致すること（**オフセット付き**であることも合わせて固定する。`toDateTimeString()` への退行を検出する） |
| 2 | login 記録の無いメンバーは lastLoginAt が null | 招待受諾直後などを想定。`->where('members.0.lastLoginAt', null)` |
| 3 | 複数の login 行があれば**最新**が選ばれる | 3 か月前 / 昨日 / 1 年前 の 3 行を作り、昨日が返ること |
| 4 | `login` 以外の種別は数えない | `logout` / `login_failed` / `password_changed` の行しか無いメンバーは null になること（`ofType()` state を使う） |
| 5 | **他組織のメンバーの login 行が混ざらない** | 別組織のユーザーに login 行を作り、当組織の一覧に影響しないこと。加えて**同一 id 帯の取り違えが無い**こと（cross-org 不変条件の behavioral 検査） |
| 6 | **実際のログインで値が入る**（配線の通し確認） | factory ではなく `POST /login` を実行し、その後 `/manage/users` の props に時刻が載ること。`RecordSecurityEvent` → `SecurityEventRecorder` → 導出 の鎖が繋がっていることを固定する（施策 A が「既存の記録に乗る」という前提の behavioral な担保） |
| 7 | remember me による自動ログイン復元も数える | remember cookie 経由の再訪で `Login` が発火し行が増えること（`viaRemember` を除外していないことの固定）。**`StampRecentAuthOnLogin` とは逆の扱い**であることを明示的に守る |
| 8 | 認可境界は既存のまま | 既存の「org Member は 403」テストが `lastLoginAt` の露出も同時に塞いでいることを確認（**新規テストは足さない**。403 なら props ごと存在しない） |

> テスト 7 の実装が Pest の HTTP テストで難しい場合は、`SessionGuard` の recaller 経路を
> 直接踏む形（cookie を持たせた 2 回目のリクエスト）で書く。それも困難なら
> **「`Login` イベントを `viaRemember` の有無で区別せず記録する」ことを
> `RecordSecurityEvent::handleLogin` の unit 相当で固定する**に落とす（**削除はしない**）。

### G-2. Feature: クエリ数の行数非依存（新規 `UserManagementLastLoginQueryCountTest.php`）

`tests/Feature/Capture/CaptureManualListQueryCountTest.php` の流儀をそのまま踏襲する。

- メンバー 1 人の組織と 10 人の組織で `/manage/users` を叩き、**発行クエリ数が同じ**であること
- 計測前に暖機の GET を 1 回撃つ / fixture 生成は `DB::flushQueryLog()` で計測外にする
- **同一利用者（owner）で行数だけを変えて比較する**（権限差でクエリ数が変わるため）
- これが落ちる = 誰かが `LastLoginLookup` を行ごとに呼ぶ形へ戻した、という意味になる

### G-3. Vitest: 表示（`AdminUsers.test.ts`）

| # | テスト名 | 検証内容 |
|---|---|---|
| 1 | fixture 更新 | `membersFixture` の **4 行すべて**に `lastLoginAt` を足す（1 件は `null` にする）。型必須なので足さないと `pnpm typecheck` が落ちる |
| 2 | 値のある行は日時を表示する | `member-last-login-{id}` に `formatDateTime` の結果（`2026/…` 形式）が含まれること |
| 3 | 値が null の行は「記録なし」を表示する | 「未ログイン」という語が**出ない**ことも合わせて固定する（§4-3 の文言判断の退行検出） |
| 4 | 既存の描画テストが壊れていない | メンバー一覧・招待中・追加フォームの既存アサーションがそのまま緑 |

> **Vitest はブラウザのタイムゾーンに依存する**。`formatDateTime` は `Intl` で
> 実行環境の TZ に変換するため、期待値を固定文字列でハードコードしない
> （`formatDateTime(fixtureValue)` の戻り値と比較するか、`2026/` の部分一致で見る）。

### G-4. 走らせる検証コマンド（AGENTS.md の検証コマンド節）

`composer test` / `composer phpstan` / `vendor/bin/pint --test` /
`pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`

- `RefreshDatabase` はグローバル適用済み。**個別 `DatabaseTransactions` を書かない**
- テストレーンはホスト全体のグローバルロックで直列化される。heartbeat が出ている間は kill しない

### 既存テストへの影響（削除・上書きをしない）

- `UserManagementPageTest` の既存 shape assertion は `where('members.0.roleState', 'owner')` 等の
  **フィールド単位**なので、フィールドが増えても落ちない（`missing('categoriesUrl')` も無関係）
- `AdminUsers.test.ts` は fixture に必須フィールドが増えるため**型エラーで落ちる**。
  これは意図した波及であり、fixture を直すことで解消する（テストの削除・骨抜きはしない）

---

## 使命・禁止事項チェック（最終確認）

| 項目 | 判定 |
|---|---|
| 使命への寄与 | 直接の生産活動ではない**運用支援**。招待した現場作業者が実際に入れているかを組織管理者が確認できるようにする（オンボーディング不全が無音で放置されない）。概念設計 §6 で「撮影体験そのものの改善ではない」と正直に位置づけ済み |
| 禁止事項 1（テストなし完了） | 施策 G で Feature 8 件 + クエリ数 1 件 + Vitest 4 件を計画。テストファーストで G-1/G-2 の fail を先に見る |
| 禁止事項 2（PHPStan widen） | `withCasts` + `Assert` で `mixed` を narrowing。`@phpstan-ignore` / baseline を使わない |
| 禁止事項 3（dev DB 破壊） | migration は索引の張り替えのみ。`migrate:fresh` を使わない |
| 禁止事項 4（`response()->json()`） | Inertia props + DTO のみ。JsonResource も API 露出も無し |
| 禁止事項 5・6（LLM / prompt） | 該当なし（LLM に触れない） |
| 禁止事項 7（`redirect()->intended()`） | 該当なし（GET のみ） |
| 禁止事項 8（disabled UI） | 操作を 1 つも足さない |
| 禁止事項 9（Artifact） | 成果物はすべて `devnotes/` 配下のファイル |
| 思考原則 2（今必要なものだけ） | 列 0 本 / 記録経路 0 本 / 新規 gate 0 本（既存 RC-8 を使う）/ 絞り込み UI 無し |
| 思考原則 3（並走を残さない） | 索引は追加ではなく**置き換え** |
| 思考原則 4（別概念を統合しない） | 最終ログイン ≠ recent-auth（remember me の扱いを意図的に逆にする）/ ≠ ModelAudit / ≠ 最終活動 / ≠ API キーの last_used_at |
| セキュリティ不変条件 3（cross-org） | current-org 解決のみ・org relation 由来の id 集合のみ・テスト 5 で behavioral に固定 |
| セキュリティ不変条件 9（変更系の認可） | 変更系 route を足さないため母集団不変 |
| DESIGN.md 準拠 | 既存 DS token（`text-caption` / `text-text-secondary`）のみ。hex 直書き無し・token 変更無し |
| Atomic Design 準拠 | 新規 component 無し。`pages` から `lib` の import は既存流儀と同一。アイコン追加無し |

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 施策 A〜G が**1 つの意味単位**で動く。`MemberRowData` のシグネチャ変更（B）は TS 型（C）と Vitest fixture（G）を**同時に**直さないと `pnpm typecheck` が落ち、`LastLoginLookup`（A）が無いと B がコンパイルできず、Factory（E）が無いと Feature テスト（G）が書けない。分割すると必ず赤いままの中間状態が生まれる。一方で他ドメイン（撮影 / シナリオ / 課金）へは 1 行も触れないため、独立したブランチで完結する |
| 競合リスク | **低い**。`Admin/Users.svelte` / `MemberRowData` / `UserManagementController` を同時に触る他タスクが無ければ衝突しない。`security_audit_events` の migration を触る他タスクがある場合のみ migration ファイル名（timestamp）の調整が要る。`RetentionTableRegistry` は根拠文 1 か所のみの変更で、区分・件数を動かさないため他タスクの台帳変更と行単位で衝突しにくい |
| 実装順序 | G-1/G-2 のテストを書いて fail を確認 → E（Factory）→ A（Lookup）→ B（DTO/Controller）→ C（TS/Svelte）→ G-3（Vitest）→ D（索引）→ F（台帳根拠文）→ 全検証コマンド |


---

## 関連する現行コード

### `app/Http/Controllers/Admin/UserManagementController.php`

```
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\DataTransferObjects\Admin\InvitationRowData;
use App\DataTransferObjects\Admin\MemberRowData;
use App\Enums\ProjectRole;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\Project\DefaultProjectResolver;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Webmozart\Assert\Assert;

/**
 * 管理メニュー > ユーザー管理 (doc/04 §4.2。GET のみ)。
 * 書き込みは既存 organizations.* endpoint (招待 / ロール変更 / 削除 / 2FA リセット) を使う。
 * URL は /manage/* (Filament panel が /admin/* を占有しているため。詳細設計 §リファレンス)。
 * current org スコープ解決のみで org URL param を持たない = cross-org 越境不能。
 */
class UserManagementController extends Controller
{
    use ResolvesCurrentOrganization;

    public function index(Request $request, DefaultProjectResolver $defaultProjects): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        Gate::authorize('manageMembers', $organization); // 撮影者・一般メンバーは 403

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $project = $defaultProjects->resolve($organization);

        // Default Project の pivot ロールを 1 クエリで引く (user_id => ProjectRole)
        /** @var array<int, ProjectRole> $pivotRoles */
        $pivotRoles = [];
        if ($project !== null) {
            foreach ($project->members()->get() as $member) {
                $pivot = $member->getRelationValue('pivot');
                $role = $pivot instanceof Pivot ? $pivot->getAttribute('role') : null;
                if (is_string($role)) {
                    $pivotRoles[$member->id] = ProjectRole::from($role);
                }
            }
        }

        $members = [];
        foreach ($organization->users()->get() as $member) {
            // organizationRole null (attach 済みだが Laratrust ロール未付与の異常行) も
            // 非表示にせず「未割当」として可視化する (derive が null を Unassigned へ丸める。
            // 管理者はロール割当コマンドでこの行を修復できる = applyConsoleRole の修復経路)
            $members[] = MemberRowData::fromUser(
                $member,
                $member->organizationRole($organization),
                $pivotRoles[$member->id] ?? null,
                $user->id,
            );
        }

        $invitations = $organization->invitations()->active()->get()
            ->map(fn (OrganizationInvitation $invitation): InvitationRowData => InvitationRowData::fromInvitation($invitation))
            ->values()
            ->all();

        return Inertia::render('Admin/Users', [
            'organizationSlug' => $organization->slug,
            'members' => $members,         // list<MemberRowData>
            'invitations' => $invitations, // list<InvitationRowData>
            'hasDefaultProject' => $project !== null,
        ]);
    }
}

```

### `app/DataTransferObjects/Admin/MemberRowData.php`

```
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Admin;

use App\Enums\MemberRoleState;
use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;
use App\Models\User;

/**
 * ユーザー管理画面 (Admin/Users) のメンバー 1 行分。TS 側 types/admin.ts の MemberRow と対で保守。
 * 表示状態 (roleState) は org ロール × Default Project pivot から毎回導出する (概念設計 D2(a))。
 * email は CipherSweet 復号値。本画面は manageMembers 権限者しか到達できない (403) ため
 * 行レベルの可視性分岐は持たない (PII 可視性は画面到達境界で担保)。
 */
final readonly class MemberRowData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $roleState,       // MemberRoleState value
        public string $roleLabel,
        public string $twoFactorStatus, // disabled|pending|enabled
        public bool $isSelf,
    ) {}

    public static function fromUser(User $user, ?OrganizationRole $orgRole, ?ProjectRole $projectRole, int $currentUserId): self
    {
        $state = MemberRoleState::derive($orgRole, $projectRole);

        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            roleState: $state->value,
            roleLabel: $state->label(),
            twoFactorStatus: $user->twoFactorStatus()->value,
            isSelf: $user->id === $currentUserId,
        );
    }
}

```

### `resources/js/types/admin.ts`

```
/**
 * 管理メニュー (ユーザー管理 / カテゴリ管理) の Inertia props 型。
 * PHP 側 DataTransferObjects\Admin\{MemberRowData,InvitationRowData} と対で保守する。
 */

/** ロール遷移コマンド (App\Enums\AdminConsoleRole と対) */
export type ConsoleRole = "admin" | "editor" | "shooter";

/** 表示状態 5 値 (App\Enums\MemberRoleState と対。導出値のためコマンドより広い) */
export type MemberRoleState = ConsoleRole | "owner" | "unassigned";

export interface MemberRow {
    id: number;
    name: string;
    email: string;
    roleState: MemberRoleState;
    roleLabel: string;
    twoFactorStatus: "disabled" | "pending" | "enabled";
    isSelf: boolean;
}

/**
 * 招待中の 1 行。招待は org ロールだけを持つ (役割付き招待は裁定 AG-079 で撤去)。
 * メンバー行の 5 値表示状態 (MemberRoleState) とは語彙が違う
 * (招待中の行は「未割当」ではなく、まだ参加していないだけ)。
 */
export interface InvitationRow {
    id: number;
    email: string;
    /** App\Enums\OrganizationRole の value (organization_admin / organization_member) */
    role: string;
    roleLabel: string;
    expiresAt: string;
}

```

### `app/Listeners/RecordSecurityEvent.php`

```
<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\SecurityEventType;
use App\Models\User;
use App\Services\Security\SecurityEventRecorder;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;

/**
 * 認証系イベント → security_audit_events の記録 (subscriber)。
 * EventServiceProvider ではなく Event::subscribe で明示登録する。
 */
class RecordSecurityEvent
{
    public function __construct(
        private readonly SecurityEventRecorder $recorder,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, [self::class, 'handleLogin']);
        $events->listen(Failed::class, [self::class, 'handleFailed']);
        $events->listen(Logout::class, [self::class, 'handleLogout']);
        $events->listen(PasswordReset::class, [self::class, 'handlePasswordReset']);
        $events->listen(TwoFactorAuthenticationConfirmed::class, [self::class, 'handleTwoFactorConfirmed']);
        $events->listen(TwoFactorAuthenticationDisabled::class, [self::class, 'handleTwoFactorDisabled']);
        $events->listen(RecoveryCodesGenerated::class, [self::class, 'handleRecoveryCodesGenerated']);
        $events->listen(PasskeyRegistered::class, [self::class, 'handlePasskeyRegistered']);
        $events->listen(PasskeyDeleted::class, [self::class, 'handlePasskeyDeleted']);
    }

    public function handleLogin(Login $event): void
    {
        $this->recorder->record(SecurityEventType::Login, $this->asUser($event->user), [
            'guard' => $event->guard,
        ]);
    }

    public function handleFailed(Failed $event): void
    {
        // user が特定できた失敗のみ記録する (email 列挙の助けになる平文 email は残さない)
        $this->recorder->record(SecurityEventType::LoginFailed, $this->asUser($event->user), [
            'guard' => $event->guard,
        ]);
    }

    public function handleLogout(Logout $event): void
    {
        $this->recorder->record(SecurityEventType::Logout, $this->asUser($event->user), [
            'guard' => $event->guard,
        ]);
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $this->recorder->record(SecurityEventType::PasswordReset, $this->asUser($event->user));
    }

    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->recorder->record(SecurityEventType::TwoFactorEnabled, $this->asUser($event->user));
    }

    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->recorder->record(SecurityEventType::TwoFactorDisabled, $this->asUser($event->user));
    }

    public function handleRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        $this->recorder->record(SecurityEventType::TwoFactorEnabled, $this->asUser($event->user), [
            'action' => 'recovery_codes_generated',
        ]);
    }

    /**
     * パスキーは単独でログインできる強い資格のため、増減は監査上最重要事象として記録する
     * (セッション乗っ取り後の永続化を事後追跡できるようにする)。
     * credential 本体 (公開鍵 / signature counter) は metadata に載せない。
     */
    public function handlePasskeyRegistered(PasskeyRegistered $event): void
    {
        $this->recorder->record(SecurityEventType::PasskeyRegistered, $this->asUser($event->user), [
            'passkey_id' => $event->passkey->getKey(),
        ]);
    }

    /**
     * 削除は EnsureLoginMethodRemains の transaction 内で発火するため、
     * rollback 時は監査行も消える (削除自体も消えるので整合。テストで固定済み)。
     *
     * 注記: SecurityEventRecorder は Throwable を catch して report() するが、
     * pgsql では transaction 内の失敗文が transaction 全体を abort させるため
     * 「catch したのに後続 SQL が全部落ちる」経路が理論上ある。これは既存の全 recorder
     * 呼び出しに共通する性質であり、本 handler で新設したものではない。
     */
    public function handlePasskeyDeleted(PasskeyDeleted $event): void
    {
        $this->recorder->record(SecurityEventType::PasskeyDeleted, $this->asUser($event->user), [
            'passkey_id' => $event->passkey->getKey(),
        ]);
    }

    private function asUser(mixed $user): ?User
    {
        return $user instanceof User ? $user : null;
    }
}

```

### `app/Services/Security/SecurityEventRecorder.php`

```
<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\SecurityEventType;
use App\Models\SecurityAuditEvent;
use App\Models\User;
use App\Services\OAuth\OrganizationAccessRevoker;

/**
 * security_audit_events への記録の唯一の窓口。
 *
 * 既定 ({@see record()}) は best-effort で、記録の失敗が主処理を巻き込まない。
 * 失効の監査だけは握り潰さない版 ({@see recordOrFail()}) を使う。
 */
class SecurityEventRecorder
{
    /**
     * 監査記録 (best-effort)。**既存の意味は変えない** — 記録の失敗で主処理を巻き込まない。
     *
     * @param  array<string, mixed>  $metadata
     */
    public function record(SecurityEventType $type, ?User $user, array $metadata = []): void
    {
        try {
            $this->write($type, $user, $metadata);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * 監査記録 (握り潰さない)。**書けなければ呼び出し元のトランザクションごと巻き戻る**。
     *
     * 「資格情報は失効したが、その事実が監査に残っていない」状態を作らないための版である。
     * 組織アクセスの失効 ({@see OrganizationAccessRevoker}) だけがこれを使う。
     * 認証系の記録 (ログイン失敗など) にこれを使ってはならない —
     * 監査の失敗でログインそのものを落とすことになるためである。
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordOrFail(SecurityEventType $type, ?User $user, array $metadata = []): void
    {
        $this->write($type, $user, $metadata);
    }

    /** @param array<string, mixed> $metadata */
    private function write(SecurityEventType $type, ?User $user, array $metadata): void
    {
        $event = new SecurityAuditEvent([
            'event_type' => $type->value,
            'metadata' => $metadata === [] ? null : $metadata,
            'ip_address' => request()->ip(),
            'occurred_at' => now(),
        ]);
        if ($user !== null) {
            $event->user()->associate($user);
        }
        $event->save();
    }
}

```

### `app/Models/SecurityAuditEvent.php`

```
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SecurityEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 認証・セキュリティイベントの監査ログ (監査 3 層の Layer 2)。
 * 記録は App\Services\Security\SecurityEventRecorder 経由のみとする。
 * user_id は所有権キーのため $fillable 外 (relation 経由で設定)。
 */
class SecurityAuditEvent extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'event_type',
        'metadata',
        'ip_address',
        'occurred_at',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => SecurityEventType::class,
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}

```

### `database/migrations/2026_06_11_071300_create_security_audit_events_table.php`

```
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 認証・セキュリティイベントの監査ログ (監査 3 層のうち Layer 2)。
     * 操作ログ (AuditLog) / 管理属性 diff (ModelAudit) とは責務を分ける
     * (devnotes/20260611-template-extraction/11 §4)。
     */
    public function up(): void
    {
        Schema::create('security_audit_events', function (Blueprint $table) {
            $table->id();
            // 退会後もイベント自体は監査証跡として残す
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type');
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['user_id', 'event_type']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_audit_events');
    }
};

```

### `app/Listeners/Auth/StampRecentAuthOnLogin.php`

```
<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Security\RecentAuthState;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Auth\Factory as AuthFactory;

/**
 * fresh credential login (web guard・非 recaller) を recent-auth 成立として stamp する。
 *
 * 目的: ログイン直後 (= たった今 password / TOTP / SSO で認証した) に機微操作へ進んだ際、
 * 「もう 1 回再認証」を要求される二重壁を消す。
 *
 * stamp する条件 (AND、fail-closed = 満たさない Login は skip):
 *   1. $event->guard === 'web' — recent-auth ゲートは web セッション専用。別 guard は除外。
 *   2. web guard の viaRemember() === false — remember-me cookie による自動ログイン復元
 *      (SessionGuard::userFromRecaller → fireLoginEvent($user, true)) を fresh 認証扱いしない。
 *      recaller 経路は発火前に viaRemember=true をセットするため、この条件で確実に除外される。
 *      (Login::$remember は明示 login でも true になり得るため判別子に使えない。)
 *
 * ⚠ 重要: 本 listener は「web guard の Login が全て credential-presentation である」前提に立つ。
 *   現行コードの web guard login は (1) Fortify password (2) Fortify TOTP (3) SSO
 *   Auth::login() (4) passkey (PasskeyLoginController::store の $guard->login()) の 4 種のみ。
 *   (4) は WebAuthn の user verification (生体 / PIN) を伴うため credential-presentation
 *   として fresh 扱いしてよい (passkey login 可否そのものは PasskeyLoginPolicy が
 *   ログイン成立前に判定する)。
 *   **将来 web guard に loginUsingId / impersonation / magic-link 等の非 credential login を
 *   追加する場合は、本 listener がそれらも fresh 扱いしてしまうため必ず見直すこと**。
 */
final class StampRecentAuthOnLogin
{
    public function __construct(
        private readonly RecentAuthState $recentAuthState,
        private readonly AuthFactory $auth,
    ) {}

    public function handle(Login $event): void
    {
        if ($event->guard !== 'web') {
            return;
        }

        $guard = $this->auth->guard('web');
        // web guard は SessionGuard 確定 (config/auth.php)。防御的に instanceof で narrowing。
        if (! $guard instanceof SessionGuard) {
            return; // fail-closed: viaRemember を判定できないなら stamp しない
        }

        if ($guard->viaRemember()) {
            return; // 自動ログイン復元 = fresh 認証ではない
        }

        $this->recentAuthState->confirm(method: 'login');
    }
}

```

### `resources/js/lib/date-format.ts`

```
/**
 * 日付・時刻フォーマット共通ヘルパ。`Intl.DateTimeFormat` ベースで、
 * 依存追加なしで ja-JP 表示を統一する。
 *
 * 各ページに散在しがちな `toLocaleDateString('ja-JP')` 呼び出しと
 * null/不正値ハンドリングの SSoT。
 */

const DEFAULT_LOCALE = "ja-JP";

const dateFormatter = new Intl.DateTimeFormat(DEFAULT_LOCALE, {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
});

const dateTimeFormatter = new Intl.DateTimeFormat(DEFAULT_LOCALE, {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
});

/** 入力を Date に正規化する。null/undefined/空文字/不正値は null を返す */
function toDate(value: Date | string | number | null | undefined): Date | null {
    if (value === null || value === undefined || value === "") return null;
    const date = value instanceof Date ? value : new Date(value);
    return Number.isNaN(date.getTime()) ? null : date;
}

/**
 * 絶対日付フォーマット (例: "2026/05/04")。不正値は fallback (省略時 "-") を返す。
 */
export function formatDate(
    value: Date | string | number | null | undefined,
    fallback: string = "-",
): string {
    const date = toDate(value);
    return date === null ? fallback : dateFormatter.format(date);
}

/**
 * 絶対日時フォーマット (例: "2026/05/04 10:08")。不正値は fallback (省略時 "-") を返す。
 */
export function formatDateTime(
    value: Date | string | number | null | undefined,
    fallback: string = "-",
): string {
    const date = toDate(value);
    return date === null ? fallback : dateTimeFormatter.format(date);
}

```

### `app/Http/Concerns/ResolvesCurrentOrganization.php`

```
<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Webmozart\Assert\Assert;

/**
 * current organization 解決 + URL 整合 guard の helper 集。
 * 「/projects/...」「/billing」等、URL に org セグメントを含めない current org スコープの
 * ルートで使う。ユーザーの current_organization_id を解決し、未設定なら 404
 * (存在しないリソースとして扱い、組織の有無を露出しない)。
 *
 * 組織管理系ルート (/organizations/{organization:slug}/...) は current に依存せず、
 * MembershipScopedOrganizationBinder の route binding で org を解決する (本 trait 不使用)。
 */
trait ResolvesCurrentOrganization
{
    private function resolveCurrentOrganization(Request $request): Organization
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $organization = $user->currentOrganization;
        abort_if($organization === null, 404);

        return $organization;
    }

    /**
     * current org 解決 + 在籍 guard。current org が未設定なら 404、解決できても
     * **ユーザーがその org に非所属なら 404** (`current_organization_id` が退会後も
     * 残存する不整合を、**認可より前に** 存在しないリソースとして落とす = 不変条件 #2。
     * 403 で org の存在を漏らさない)。
     *
     * 組織 route (`/organizations/{organization:slug}/...`) では
     * MembershipScopedOrganizationBinder の route binding がこの層を担う。本メソッドは
     * その責務を current-org スコープ (URL に org セグメントを持たない route) へ写した受け皿。
     */
    private function resolveMemberCurrentOrganization(Request $request): Organization
    {
        $organization = $this->resolveCurrentOrganization($request);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        abort_unless(
            $organization->users()->whereKey($user->getKey())->exists(),
            404,
        );

        return $organization;
    }

    /**
     * URL 整合 guard (D2 不変条件): URL 上の {project} が current org に属さなければ
     * **認可より前に 404** (403 で存在を漏らさない / cross-org は 404)。
     * 所属確認は relation (Organization::projects = CustomTeam 経由) のみで行う (直 fetch 禁止)。
     *
     * web の {project} route では EnsureProjectBelongsToRouteOrganization middleware
     * (project.in-route-org) が本 guard を FormRequest の DB ルールより**前**にも実行する
     * (422/404 差分の存在オラクル防止)。controller 内の呼び出しは二重防御として維持する。
     */
    private function resolveOrganizationProject(Organization $organization, Project $project): Project
    {
        abort_unless(
            $organization->projects()->whereKey($project->getKey())->exists(),
            404,
        );

        return $project;
    }
}

```

### `resources/js/pages/Admin/Users.svelte` (L290-320 抜粋: メンバー行)

```
                    <ul class="mt-4 flex flex-col divide-y divide-border" data-testid="member-list">
                        {#each members as member (member.id)}
                            <!-- 375px 方針: モバイルは縦積み、sm 以上は現行の横並び (F-14)。操作ブロックは要素単位で折り返し可 -->
                            <li
                                class="flex flex-col gap-2 py-3 sm:flex-row sm:flex-wrap sm:items-center sm:gap-4"
                            >
                                <div class="min-w-0 sm:min-w-40">
                                    <div class="flex items-center gap-2">
                                        <p class="truncate text-body">{member.name}</p>
                                        {#if member.twoFactorStatus === "enabled"}
                                            <Badge tone="success">2FA</Badge>
                                        {/if}
                                        {#if member.roleState === "unassigned"}
                                            <Badge tone="warning" testId={`unassigned-${member.id}`}>
                                                未割当
                                            </Badge>
                                        {/if}
                                    </div>
                                    <p class="truncate text-caption text-text-secondary">
                                        {member.email}
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2 sm:ml-auto sm:shrink-0 sm:justify-end">
                                    {#if canResetTwoFactor(member)}
                                        <Button
                                            variant="danger-ghost"
                                            size="sm"
                                            onclick={() => openResetTwoFactorModal(member)}
                                            testId={`reset-two-factor-${member.id}`}
                                        >
                                            2FA 解除
```

### `tests/Support/Retention/RetentionTableRegistry.php` (security_audit_events entry 周辺)

```
            ),
            RetentionTableEntry::undecided(
                'llm_call_logs',
                'AI 呼び出しの費用と件数の記録。組織と利用者への外部キーが空値化のため、'
                .'退会や組織の削除の後も行が残る。費用分析に必要な期間が未決である',
            ),
            RetentionTableEntry::undecided(
                'security_audit_events',
                '認証と権限に関わる操作の証跡。利用者への外部キーが空値化のため退会後も行が残る。'
                .'監査に必要な保持期間が未決である',
            ),
            RetentionTableEntry::undecided(
                'model_audits',
                'モデルの変更履歴の証跡。多態の関連で外部キーを持たないため親の削除に連動しない。'
                .'保持期間が未決である',
            ),
            RetentionTableEntry::undecided(
                'email_suppressions',
```

### `tests/Feature/Retention/RetentionTableClassificationTest.php` (未確定表の pin と RC-8)

```

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

...
test('RC-8: 台帳の総件数と未確定の表名が現在値ちょうどである', function (): void {
    $entries = RetentionTableRegistry::entries();

    expect($entries)->toHaveCount(RETENTION_TABLE_COUNT,
        '台帳の件数が変わりました。表を足した / 消したなら RETENTION_TABLE_COUNT も書き換えてください。');

    $undecided = retentionTablesOfClass($entries, RetentionClass::Undecided);
    $expected = RETENTION_UNDECIDED_TABLES;
    sort($expected);

    expect($undecided)->toBe($expected,
        '保持期限が未確定の表の一覧が変わりました。増えるときも減るときも '
        .'RETENTION_UNDECIDED_TABLES を書き換えてください (未確定を無音で増やさないための pin です)。');
});
```

### `vendor/.../Illuminate/Auth/SessionGuard.php` (recaller 経路が Login を発火する箇所 L190-210)

```
            $this->fireAuthenticatedEvent($this->user);
        }

        // If the user is null, but we decrypt a "recaller" cookie we can attempt to
        // pull the user data on that cookie which serves as a remember cookie on
        // the application. Once we have a user we can return it to the caller.
        if (is_null($this->user) && ! is_null($recaller = $this->recaller())) {
            $this->user = $this->userFromRecaller($recaller);

            if ($this->user) {
                $this->updateSession($this->user->getAuthIdentifier());

                $this->fireLoginEvent($this->user, true);
            }
        }

        return $this->user;
    }

    /**
     * Pull a user from the repository by its "remember me" cookie token.
```

### `tests/Feature/Admin/UserManagementPageTest.php` (L1-55 抜粋)

```
<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;
use App\Models\OrganizationInvitation;
use App\Models\Project;
use App\Models\User;

/*
 * 管理メニュー > ユーザー管理 (GET /manage/users)。
 * 読み取り専用画面 (書き込みは既存 organizations.* endpoint)。
 * PII (email) の可視性契約: manageMembers 権限者しか画面自体に到達できない (403 境界)。
 */

test('org Owner は 200 + Admin/Users component で members/invitations shape を受け取る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    OrganizationInvitation::factory()->forOrganization($organization)
        ->create(['email' => 'pending-member@example.com', 'role' => OrganizationRole::Member->value]);

    $response = $this->actingAs($owner)->get('/manage/users');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/Users')
        ->where('organizationSlug', $organization->slug)
        ->where('members.0.roleState', 'owner')
        ->where('members.0.isSelf', true)
        ->where('invitations.0.email', 'pending-member@example.com')
        ->where('invitations.0.role', OrganizationRole::Member->value)
        ->where('invitations.0.roleLabel', 'メンバー')
        ->where('hasDefaultProject', false)
        // T071: 独自二次左メニュー(AdminMenuNav)撤去に伴い categoriesUrl prop は廃止 → 存在しない
        ->missing('categoriesUrl'));
});

test('org Admin も閲覧できる (200)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);
    $admin->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($admin)->get('/manage/users')->assertOk();
});

test('org Member (編集者 = project_admin でも org は Member) は 403', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $editor = attachOrganizationMember($organization);
    attachProjectMember($project, $editor, ProjectRole::Admin);
    $editor->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($editor)->get('/manage/users')->assertForbidden();
});

```
