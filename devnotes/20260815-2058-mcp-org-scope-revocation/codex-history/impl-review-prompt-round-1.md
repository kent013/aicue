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

# あなたの役割

Laravel 12 + Svelte 5 + Inertia のアプリに対する **実装レビュアー** である。
以下の詳細設計と実装差分を読み、設計との一致性・正確性・セキュリティ・テスト網羅性を評価せよ。

## レビュー観点

1. **設計との一致性**: 詳細設計 (下記) の 9 施策が実装されているか。設計から逸脱した箇所は
   設計側が同じ差分で訂正されているか (本差分では detailed-design.md に「実装時の訂正」を
   3 箇所書いている)
2. **正確性**: 失効の SQL 条件・件数の数え方・トランザクション境界・ロック順序
3. **PHPStan level 10 適合性** (widen / baseline / ignore は禁止。既に green)
4. **DTO / JsonResource パターン** (`response()->json()` 直書き禁止)
5. **テスト網羅性**: 静的検査 (Architecture) と振る舞い (Feature) の役割分担が妥当か。
   検出器の負のコントロールが「検出器が実際に働いていること」を証明しているか。
   **空疎に緑になるテスト** (assert が実質何も見ていない) が無いか
6. **セキュリティ**: cross-org 越境、失効漏れ (取り逃す資格情報)、存在オラクル、
   fail-open な検出器 (回避が容易な字句検査)
7. **保証範囲の記述が誇張になっていないか** (このリポジトリは「保証しないもの」を
   明記する規約を持つ)

本差分は frontend (resources/js) を 1 行も変更しないため、DESIGN.md / Atomic Design 観点は対象外である。

## 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
  - Critical = 正しさ・セキュリティ・設計契約が壊れている (必ず直すべき)
  - Warning = 直すべきだが致命的ではない
  - Suggestion = 好みまたは将来の改善
- 最後に **全体判定: APPROVED または CHANGES_REQUESTED** を 1 行で書く

---

## 詳細設計書

# 詳細設計: mcp-org-scope-revocation (組織の役割変更に同期したトークン失効)

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
5. LLM 呼び出しの Prism 直呼び(窓口 `PromptDefense` → 実行単位 `GuardedPrompt` の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### 関係するセキュリティ不変条件 (AGENTS.md)

- 3 **cross-org 不可**: 失効の対象は必ず (組織, 利用者) の 2 つで絞る。クラス起点の主キー同一性クエリを増やさない (`ModelDirectFetchInvariantTest` + `DirectFetchInventory`)。
- 5 **権限判定は常に `laratrust_team_id` を明示**。
- 9 **変更系 route は認可を通る**。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`）
- **RefreshDatabase** + `--parallel`（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- テストデータは必ず Factory で生成
- **DTO + JsonResource** パターン
- `declare(strict_types=1)` + 日本語コメント。Controller は薄く、transaction は Service 内
- アーリーリターン推奨 / `composer fix`（Pint）
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

[devnotes/20260815-2058-mcp-org-scope-revocation/conceptual-design.md](conceptual-design.md)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 失効の理由と結果の型 | `app/Enums/Security/OrgAccessRevocationReason.php` (新) / `app/DataTransferObjects/Security/OrgAccessRevocationResult.php` (新) | High |
| 2 | 監査の「握り潰さない記録」 | `app/Services/Security/SecurityEventRecorder.php` / `app/Enums/SecurityEventType.php` | High |
| 3 | 失効の唯一の窓口 | `app/Services/OAuth/OrganizationAccessRevoker.php` (新) | High |
| 4 | 役割変更 4 経路への配線 | `app/Services/Organization/OrganizationMembershipService.php` / 呼び出し元 Controller 2 本 | High |
| 5 | 先置きの検査 1: 失効の配線 | `tests/Architecture/OrganizationAccessRevocationChokePointTest.php` (新) / `app/Enums/Security/OrgAccessRevocationExemption.php` (新) | High |
| 6 | 先置きの検査 2: 外部 API の書き込み資格 | `tests/Architecture/RestWriteScopeRevalidationInvariantTest.php` (新) / `app/Enums/Security/ApiWriteScopeExemption.php` (新) | Medium |
| 7 | 先置きの検査 3: MCP の認可の関門 | `tests/Architecture/McpAuthorizationChokePointTest.php` (新) / `tests/Architecture/McpWriteToolIdempotencyEnforcementTest.php` (案内文の追記) | Medium |
| 8 | 振る舞いのテスト | `tests/Feature/Organizations/OrganizationAccessRevocationTest.php` (新) ほか既存 3 本の更新 | High |
| 9 | 文書 | `docs/mcp-oauth.md` / `docs/architecture.md` / `AGENTS.md` | Medium |

---

## 施策 1: 失効の理由と結果の型

### 変更箇所

- 新規 `app/Enums/Security/OrgAccessRevocationReason.php`
- 新規 `app/DataTransferObjects/Security/OrgAccessRevocationResult.php`

### 波及変更

- TypeScript 型定義: **なし** (画面へ出さない。監査の metadata としてのみ使う)
- API Resource/DTO: 新規 DTO 1 本 (外へ出さない内部 DTO)
- テストファイル: 施策 5・8 で参照

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 組織アクセス失効の理由 (監査 metadata の固定語彙)。
 *
 * **理由は制御フローを変えない**。窓口は理由に関わらず 3 家族を同じように撃つ。
 * 分けているのは「なぜ接続が切れたのか」をサポート時に 1 行で答えるためだけである
 * (とくに OwnershipTransferredTo は「昇格したのに切れた」という驚きの説明に要る)。
 */
enum OrgAccessRevocationReason: string
{
    /** 組織ロールの変更 (昇格・降格の区別はしない) */
    case RoleChanged = 'role_changed';

    /** 組織からの除名 */
    case MemberRemoved = 'member_removed';

    /** オーナー移譲の譲り手 (Owner → Admin) */
    case OwnershipTransferredFrom = 'ownership_transferred_from';

    /** オーナー移譲の受け手 (→ Owner)。**昇格でも切る**という設計判断の可視化 */
    case OwnershipTransferredTo = 'ownership_transferred_to';

    public function label(): string
    {
        return match ($this) {
            self::RoleChanged => '組織ロールの変更',
            self::MemberRemoved => '組織からの除名',
            self::OwnershipTransferredFrom => 'オーナー移譲 (譲り手)',
            self::OwnershipTransferredTo => 'オーナー移譲 (受け手)',
        };
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Security;

/**
 * 組織アクセス失効の結果 (家族ごとの件数)。監査 metadata の材料。
 *
 * **0 件でも記録する**。「失効すべきものが無かった」ことも監査上の事実であり、
 * 記録が無いと「窓口が呼ばれなかったのか / 対象が無かったのか」を区別できない。
 */
final readonly class OrgAccessRevocationResult
{
    public function __construct(
        /** 失効させた oauth_sessions 行数 */
        public int $sessions,
        /** 失効させた access token 行数 */
        public int $accessTokens,
        /** 失効させた refresh token 行数 */
        public int $refreshTokens,
        /** 失効させた未交換の認可コード行数 */
        public int $authCodes,
    ) {}

    public function total(): int
    {
        return $this->sessions + $this->accessTokens + $this->refreshTokens + $this->authCodes;
    }

    /** @return array{sessions: int, access_tokens: int, refresh_tokens: int, auth_codes: int} */
    public function toArray(): array
    {
        return [
            'sessions' => $this->sessions,
            'access_tokens' => $this->accessTokens,
            'refresh_tokens' => $this->refreshTokens,
            'auth_codes' => $this->authCodes,
        ];
    }
}
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている
- [x] null 安全 (null を持たない値型)
- [x] DTO を返している (配列返却は `toArray()` の 1 本だけで shape を明示)
- [x] Generics 不要

### テスト計画

- [ ] 施策 8 の Feature テストが件数の意味 (家族ごとに独立、0 件でも記録) を固定する
- [ ] `label()` の網羅 `match` は case 追加時に PHPStan が落とすので専用テストは作らない (過剰)

### リスク

- 理由の case を増やすと監査の語彙が広がる。**制御フローに使わない**ことを説明文で明示し、施策 5 の検査で「窓口が理由で分岐していないこと」(`match ($reason)` が窓口本文に現れないこと) を静的に固定する。

---

## 施策 2: 監査の「握り潰さない記録」

### 変更箇所

- `app/Enums/SecurityEventType.php` — case 追加
- `app/Services/Security/SecurityEventRecorder.php` — `recordOrFail()` 追加
- `tests/Architecture/SecurityEventCoverageTest.php` — 対応表へ登録 (既定拒否のため必須)

### 波及変更

- TypeScript 型定義: **なし**
- API Resource/DTO: **なし**
- テストファイル: `SecurityEventCoverageTest` の `securityEventRecordingMap()` に 1 行、`covered_by` に施策 8 のファイルを指定

### 現行コード

```php
    public function record(SecurityEventType $type, ?User $user, array $metadata = []): void
    {
        try {
            // ... insert ...
        } catch (\Throwable $e) {
            report($e);
        }
    }
```

### 変更後コード

```php
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
     * 監査記録 (握り潰さない)。**書けなければ呼び出し元のトランザクションごと巻き戻す**。
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
```

`SecurityEventType` へ 1 case:

```php
    // 組織の役割変更に同期した機械クライアント向け資格情報の失効
    // (OrganizationAccessRevoker が recordOrFail で直接記録する)
    case OrganizationAccessRevoked = 'organization_access_revoked';
```

`label()` の網羅 `match` へ `'組織アクセスの失効'` を追加する (追加しないと PHPStan level 10 が落ちる = 付け忘れが構造的に起きない)。

`SecurityEventCoverageTest` の対応表へ:

```php
        SecurityEventType::OrganizationAccessRevoked->value => [
            'caller' => OrganizationAccessRevoker::class,
            'covered_by' => 'tests/Feature/Organizations/OrganizationAccessRevocationTest.php',
        ],
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている
- [x] `array<string, mixed>` の phpdoc を維持
- [x] `label()` の網羅 match で case 追加漏れを型検査が落とす

### テスト計画

- [ ] **先に赤にする**: `SecurityEventCoverageTest` は enum と対応表の完全一致を要求するので、case を足した瞬間に赤くなる。これを fail-first の起点にする
- [ ] 新規テスト: `recordOrFail` が例外を伝播すること (`SecurityAuditEvent` の保存を失敗させ、呼び出し元の tx が巻き戻ることを施策 8 で確認)
- [ ] 既存 `record()` の握り潰し挙動が変わっていないこと (既存テストがそのまま緑であることで担保)

### リスク

- `record()` の実装を `write()` へ切り出すため、既存の全記録経路が影響を受ける。**振る舞いは同一**だが、既存の監査テスト (`SecurityAuditTrailCoverageTest` ほか) を回して確認する。
- **PostgreSQL の注意**: トランザクション内で 1 文が失敗するとそのトランザクションは中断状態になり、以後の文はすべて失敗する。`record()` の握り潰しは「例外を出さない」だけで「トランザクションを健全に保つ」わけではない。失効の監査に `recordOrFail` を使うのはこの性質と整合させる意図でもある (曖昧な半端状態を作らず、はっきり巻き戻す)。

---

## 施策 3: 失効の唯一の窓口

### 変更箇所

- 新規 `app/Services/OAuth/OrganizationAccessRevoker.php`

### 波及変更

- TypeScript 型定義: **なし**
- API Resource/DTO: 施策 1 の DTO を返す
- テストファイル: 施策 5 (静的検査) / 施策 8 (振る舞い)

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Services\OAuth;

use App\DataTransferObjects\Security\OrgAccessRevocationResult;
use App\Enums\Security\OrgAccessRevocationReason;
use App\Enums\SecurityEventType;
use App\Models\Organization;
use App\Models\User;
use App\Services\Security\SecurityEventRecorder;
use Illuminate\Support\Facades\DB;
use Webmozart\Assert\Assert;

/**
 * 組織アクセスの失効の**唯一の窓口**。
 *
 * ある組織における、ある利用者の「人に委ねられた資格情報」をまとめて失効させる。
 * 失効の境界は **「役割を変える操作が成功したこと」** であり、役割の集合の差分は取らない。
 * 差分を取ると権限ライブラリの役割キャッシュ (本番で 1 時間有効) に依存した判定になり、
 * 取りこぼしたときに通してしまう側へ倒れるためである (正典 v2 / 裁定 AG-125)。
 *
 * **必ず呼び出し元のトランザクションの内側で呼ぶ**。役割の変更と失効が同じひとまとまりに
 * 入っていないと、「役割は下がったのにトークンは生きている」中間状態と、
 * 確定直後にプロセスが落ちて失効が無言で消える隙間の両方が生まれる。
 * 外から呼ばれた場合は実行時に例外で拒否する (説明文とテストだけに頼らない)。
 *
 * **3 家族を途中で打ち切らない**。1 家族目が 0 件でも残りは必ず撃つ。
 *
 * 触らないもの:
 *  - 組織が持つ API キー (`api_keys`) — 組織の資産であり、人の所属で消さない
 *    (発行した管理者が抜けた瞬間に組織の自動連携が全部止まる事故を作らない)。
 *    **誇張しない**: 退会者が発行したキーで**書き込み**を叩くと、認可
 *    ({@see \App\Policies\ProjectPolicy}) が発行者の現在の組織ロールを評価するので 403 になるが、
 *    **読み取りは通る** ({@see \App\Http\Middleware\ResolveApiActor} は発行者の所属を
 *    再評価しない)。鍵を止めるのは組織管理者の操作 (API キー画面) である。
 *  - プロジェクト単位の役割 — トークンの結び付き先は組織であり、その人はまだメンバーである。
 *
 * 保証しないこと:
 *  - 失効の選択と確定の間に新しい資格情報が発行される隙間は閉じない
 *    (発行の経路は組織行・利用者行のロックを取らない)。最後の拒否線は要求ごとの再評価である。
 */
final class OrganizationAccessRevoker
{
    public function __construct(
        private readonly SecurityEventRecorder $recorder,
    ) {}

    /**
     * 対象 (組織, 利用者) の資格情報を失効させ、監査を 1 行残す。
     *
     * @param  User|null  $actor  操作した人 (HTTP 外 = バッチ・コンソールは null が正常値)
     */
    public function revoke(
        Organization $organization,
        User $target,
        OrgAccessRevocationReason $reason,
        ?User $actor,
    ): OrgAccessRevocationResult {
        // 呼び出し元のひとまとまりの内側であることの実行時強制。
        // ここを説明文だけに頼ると、外から呼ぶ経路が静かに生まれる。
        Assert::greaterThan(
            DB::transactionLevel(),
            0,
            'OrganizationAccessRevoker::revoke() は役割変更と同一のトランザクション内から呼ぶこと',
        );

        $organizationId = $organization->getKey();
        Assert::integer($organizationId);
        $userId = $target->getKey();
        Assert::integer($userId);

        // 家族 1: セッション行 (表示・actor 解決の判定に使う失効印)
        $sessions = DB::table('oauth_sessions')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        // 家族 2: 利用トークンと、それに紐づく更新トークン。
        // ★session_id では絞らない。絞ると「セッション行を持たないトークン」
        //   (古い MCP トークン等) が生き残る。spirux が接続元の種別で絞って
        //   穴を作った件と同じ轍を踏まない。
        // ★母集団を「まだ失効していない利用トークン」に絞らない。更新トークンは
        //   親の利用トークン経由でしか辿れないので、親が既に失効済みで子が未失効という
        //   不整合行があると、絞った瞬間にその子が生き残る (= 再発行の経路が残る)。
        //   絞るのは**件数を数える更新文の側だけ**にする。
        /** @var list<string> $tokenIds */
        $tokenIds = DB::table('oauth_access_tokens')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->pluck('id')
            ->all();

        $accessTokens = 0;
        $refreshTokens = 0;
        if ($tokenIds !== []) {
            $accessTokens = DB::table('oauth_access_tokens')
                ->whereIn('id', $tokenIds)
                // 主キーで絞った後でも所有権の条件を残す (監査上の意図の明示 + 取り違えの保険)
                ->where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->where('revoked', false)
                ->update(['revoked' => true]);
            $refreshTokens = DB::table('oauth_refresh_tokens')
                ->whereIn('access_token_id', $tokenIds)
                ->where('revoked', false)
                ->update(['revoked' => true]);
        }

        // 家族 3: 未交換の認可コード。
        // これを落とすと、失効の直前に発行された認可コードを失効の後に交換して
        // 新しいトークンを得る経路が残る。
        $authCodes = DB::table('oauth_auth_codes')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('revoked', false)
            ->update(['revoked' => true]);

        $result = new OrgAccessRevocationResult(
            sessions: $sessions,
            accessTokens: $accessTokens,
            refreshTokens: $refreshTokens,
            authCodes: $authCodes,
        );

        // 監査は握り潰さない。書けなければ役割の変更ごと巻き戻る。
        // 失効 0 件でも 1 行残す (「対象が無かった」ことも監査上の事実である)。
        $this->recorder->recordOrFail(SecurityEventType::OrganizationAccessRevoked, $target, [
            'organization_id' => $organizationId,
            'actor_user_id' => $actor?->getKey(),
            'reason' => $reason->value,
            'revoked' => $result->toArray(),
        ]);

        return $result;
    }
}
```

### 設計上の判断とその理由

| 判断 | 理由 |
|---|---|
| `OauthSession::revoke()` を再利用**しない** | 対象の広さが違う。`OauthSession::revoke()` は「この 1 セッションを切る」(画面 / CLI の操作)、本窓口は「この人のこの組織での資格情報を全部切る」。前者を N 回呼ぶと家族 2 の集合演算と重複し、しかもセッション行を持たないトークンを取り逃す。**別物の概念を「似ているから」で統合しない** (思考原則 4)。両者が同じ列に書くことは施策 5 の単一窓口の目録で明示的に許可する |
| `pluck` → `whereIn` の 2 段 | 更新トークンは `access_token_id` でしか親を辿れず、副問い合わせを書くより読める。件数も家族ごとに正確に取れる |
| 新しい行ロックを取らない | 呼び出し元が canonical 順序 (users 昇順 → organizations 昇順) で既にロックを持っている。ここで `oauth_*` の行ロックを足すと**新しいロック順序が生まれる** (デッドロックの導入)。集合更新は行ロックを暗黙に取るが、順序は常に `oauth_sessions` → `oauth_access_tokens` → `oauth_refresh_tokens` → `oauth_auth_codes` の一方向で固定される |
| 理由 (`$reason`) で分岐しない | 「理由は観測であって制御ではない」。分岐を作ると理由の追加が挙動の変更になる (既存の `GatewayFailureClassifier` と同じ考え方) |
| `now()` を 2 回呼ばない配慮は**しない** | `revoked_at` と `updated_at` の間に秒をまたぐ可能性は監査上無害。可読性を優先する |

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`OrgAccessRevocationResult`)
- [x] null 安全 (`Assert::integer` で主キーを narrowing、`$actor?->getKey()`)
- [x] DTO を返している
- [x] `pluck()->all()` に `list<string>` の phpdoc を付ける

### テスト計画

- [ ] **fail-first**: 施策 8 の Feature テストを先に書き、失効が起きないことを赤で確認してから配線する
- [ ] 新規テスト: 3 家族それぞれが独立に失効すること (家族 1 が 0 件でも家族 2・3 が撃たれる)
- [ ] 新規テスト: セッション行を持たないトークン (`session_id` が NULL) も失効すること
- [ ] 新規テスト: 他組織 / 他利用者の資格情報が 1 件も巻き添えにならないこと (cross-org 不変条件)
- [ ] 新規テスト: トランザクションの外から呼ぶと例外になること
- [ ] 新規テスト: 失効 0 件でも監査が 1 行残ること
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- `DB::table()` の直書きが増える。`oauth_*` は Passport の vendor テーブルで Eloquent モデルを持たない (`OauthSession` だけが自前モデル) ため、既存の `ResolveApiActor` / `OauthSessionListService` と同じ流儀である。`ModelDirectFetchInvariantTest` の母集団は `users` 等の自前モデルに対するクラス起点の主キー同一性クエリであり、本件は該当しない (実装時に走らせて確認する)。
- 大量のトークンを持つ利用者では `whereIn` の要素数が増える。実運用の桁 (1 人あたり数件〜数十件) では問題にならない。**上限を設ける設計はしない** (途中で打ち切ると失効が不完全になる)。

---

## 施策 4: 役割変更 4 経路への配線

### 変更箇所

- `app/Services/Organization/OrganizationMembershipService.php`
  - コンストラクタに `OrganizationAccessRevoker` を注入
  - `changeRole()` (L510-537) / `removeMember()` (L544-573) / `transferOwnership()` (L602-656) / `normalizeOrganizationRole()` (L488-502) へ配線
  - `applyConsoleRole()` / `changeRole()` / `removeMember()` の引数へ `?User $actor` を追加
- `app/Http/Controllers/Organizations/OrganizationMemberController.php` (L39, L50)
- `app/Http/Controllers/Organizations/OrganizationOwnershipController.php` (L49)

### 波及変更

- TypeScript 型定義: **なし** (画面の入出力は変わらない)
- API Resource/DTO: **なし**
- テストファイル: **必須** —
  `tests/Feature/Organization/ConsoleRoleTransitionTest.php` /
  `tests/Feature/Auth/AccountDeletionTest.php` /
  `tests/Architecture/ModelDirectFetchInvariantTest.php` の呼び出し 13 箇所が
  引数追加で壊れる。**全部を同じ PR で直す** (後方互換の既定値を置いて逃げない = 思考原則 3)

### 現行コード (抜粋)

```php
    public function changeRole(Organization $organization, User $target, OrganizationRole $newRole): void
    {
        DB::transaction(function () use ($organization, $target, $newRole): void {
            $this->lockForMembershipWrite([$this->keyOf($target)], [$this->keyOf($organization)]);
            // ... ロック下の再検証 ...
            $freshTarget->removeRole($currentRole->value, $organization->laratrust_team_id);
            $freshTarget->addRole($newRole->value, $organization->laratrust_team_id);
        });
    }
```

### 変更後コード (抜粋)

```php
    /**
     * ロール変更。Owner への昇格は transferOwnership のみが正規経路。
     *
     * **役割の入れ替えの後、同じトランザクションの中で**その人のこの組織における
     * 機械クライアント向け資格情報を失効させる (正典 v2)。昇格でも切れる —
     * 役割の集合の差分で判断すると、権限ライブラリの役割キャッシュ依存になり
     * 取りこぼしたときに通してしまう側へ倒れるためである。
     *
     * @param  User|null  $actor  操作した人 (監査用。HTTP 外は null が正常値)
     *
     * @throws ValidationException 非メンバー / 最後の Owner の降格
     */
    public function changeRole(Organization $organization, User $target, OrganizationRole $newRole, ?User $actor): void
    {
        DB::transaction(function () use ($organization, $target, $newRole, $actor): void {
            $this->lockForMembershipWrite([$this->keyOf($target)], [$this->keyOf($organization)]);

            $freshTarget = $target->fresh();
            Assert::isInstanceOf($freshTarget, User::class);

            $currentRole = $freshTarget->organizationRole($organization);
            if ($currentRole === null) {
                throw ValidationException::withMessages(['role' => ['このユーザーは組織のメンバーではありません。']]);
            }
            if ($currentRole === $newRole) {
                return; // 冪等 (何も変わっていないので失効もしない)
            }
            if ($currentRole === OrganizationRole::Owner && ! $this->hasAnotherOwner($organization, $freshTarget)) {
                throw ValidationException::withMessages([
                    'role' => ['最後のオーナーは降格できません。先にオーナーを移譲してください。'],
                ]);
            }
            $freshTarget->removeRole($currentRole->value, $organization->laratrust_team_id);
            $freshTarget->addRole($newRole->value, $organization->laratrust_team_id);

            // 役割の入れ替えの**後**・同一トランザクション内
            $this->accessRevoker->revoke(
                $organization,
                $freshTarget,
                OrgAccessRevocationReason::RoleChanged,
                $actor,
            );
        });
    }
```

`removeMember()`:

```php
            $organization->users()->detach($freshTarget->getKey());
            if ($role !== null) {
                $freshTarget->removeRole($role->value, $organization->laratrust_team_id);
            }
            $this->detachProjectMemberships($organization, $freshTarget);
            if ($freshTarget->current_organization_id === $organization->id) {
                $freshTarget->forceFill(['current_organization_id' => null])->save();
            }

            // 除名の後・同一トランザクション内
            $this->accessRevoker->revoke(
                $organization,
                $freshTarget,
                OrgAccessRevocationReason::MemberRemoved,
                $actor,
            );
```

`transferOwnership()` (譲り手と受け手の**両方**):

```php
            $freshFrom->removeRole(OrganizationRole::Owner->value, $teamId);
            $freshFrom->addRole(OrganizationRole::Admin->value, $teamId);

            if ($toRole !== null) {
                $freshTo->removeRole($toRole->value, $teamId);
            }
            $freshTo->addRole(OrganizationRole::Owner->value, $teamId);

            // 役割の入れ替えの後・同一トランザクション内。操作した人は譲り手 ($freshFrom)。
            // 受け手も切る (昇格でも切る = 差分で判断しないという設計判断の帰結)。
            $this->accessRevoker->revoke($freshOrg, $freshFrom, OrgAccessRevocationReason::OwnershipTransferredFrom, $freshFrom);
            $this->accessRevoker->revoke($freshOrg, $freshTo, OrgAccessRevocationReason::OwnershipTransferredTo, $freshFrom);
```

`normalizeOrganizationRole()` の修復経路 (役割未付与の異常行へ直接付与する枝):

```php
            $target->addRole($role->organizationRole()->value, $organization->laratrust_team_id);

            // 修復も役割の付与である。changeRole を経ない唯一の枝なので、ここにも置く
            // (置かないと「管理画面から役割を直したのに古いトークンが生きている」経路が残る)。
            $this->accessRevoker->revoke(
                $organization,
                $target,
                OrgAccessRevocationReason::RoleChanged,
                $actor,
            );

            return;
```

`joinOrganization()` には**置かない**。組織に**入れる**操作であり、その人がその組織で
持っていた発行済みの資格情報はまだ存在しないためである (施策 5 の目録へ根拠付きで免除登録する)。

### 「ロックの内側か後ろか」の決着 (本設計の中心)

**内側**とする。`DB::transaction` のクロージャの中、`lockForMembershipWrite` で
取った行ロックを保持したまま、役割の入れ替えの**後**に置く。

- **巻き戻りが連動する**: 監査が書けなければ役割の変更ごと巻き戻り、役割の変更が
  失敗すれば失効も起きない。片側だけ残る中間状態を作らない。
- **消失する隙間を作らない**: 外に出すと、確定してから失効するまでの間に
  プロセスが落ちれば失効が無言で消える (正典 v1 が非同期の購読で作ってしまった隙間)。
- **新しいロック順序を作らない**: 窓口は自分では行ロックを取らず、
  `oauth_*` への集合更新を固定した順序で行うだけである。既存の canonical 順序
  (users 昇順 → organizations 昇順) の内側に完全に収まる。
- **ロックの保持時間**: 増えるのは `oauth_*` 4 表への集合更新 (最大 4 文) と監査 1 文である。
  対象行数は 1 人あたり数件〜数十件で、既存の `detachProjectMemberships` と同程度である。
- **入れ子のトランザクション**: `applyConsoleRole` → `changeRole` は入れ子になるが、
  Laravel はセーブポイント扱いにするので、内側の失効は外側の巻き戻しに追随する。
  `applyConsoleRole` は失効を**自分では呼ばない** (呼ぶと 1 操作で 2 回撃つ)。
  委譲先 (`normalizeOrganizationRole` / `changeRole`) が呼ぶ。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`void`)
- [x] `?User $actor` の null 安全 (窓口側で `?->getKey()`)
- [x] DTO を返している (窓口の戻り値は使わないが型は付いている)
- [x] Generics 不要

### テスト計画

- [ ] **fail-first**: 施策 8 のテストを先に書いて赤を確認する
- [ ] 新規: `changeRole` の降格で失効すること
- [ ] 新規: `changeRole` の**昇格でも**失効すること (仕様であることをテスト名に書く)
- [ ] 新規: `changeRole` の同値 (冪等の早期 return) では**失効しない**こと
- [ ] 新規: `removeMember` で失効すること
- [ ] 新規: `transferOwnership` で譲り手と受け手の**両方**が失効すること (テスト名に「受け手も切れる」と書く)
- [ ] 新規: `applyConsoleRole` の修復経路 (役割未付与の行への直接付与) で失効すること
- [ ] 新規: `joinOrganization` (招待受諾) では失効しないこと (免除の前提の behavioral な固定)
- [ ] 新規: 役割変更が例外で失敗したとき、失効も巻き戻ること
- [ ] 更新: `ConsoleRoleTransitionTest` / `AccountDeletionTest` / `ModelDirectFetchInvariantTest` の呼び出し 13 箇所
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **引数追加が既存テストを壊す**。既定値で逃げると「actor を渡し忘れても緑」になるので、
  既定値は置かない。壊れた箇所を同じ PR で全部直す。
- **昇格でも接続が切れる**運用上の驚き。文書 (施策 9) と監査の理由 (施策 1) で説明可能にする。
- **`applyConsoleRole` の二重発火**。Editor → Shooter のようにプロジェクト側の pivot だけが
  変わり組織ロールは同値の場合、`changeRole` は早期 return するので失効は起きない。
  これは意図した設計である (プロジェクト単位の権限は失効の境界に入れない)。
  テストで明示的に固定する。

---

## 施策 5: 先置きの検査 1 — 失効の配線 (既定拒否)

### 変更箇所

- 新規 `tests/Architecture/OrganizationAccessRevocationChokePointTest.php`
- 新規 `app/Enums/Security/OrgAccessRevocationExemption.php`

### 波及変更

- TypeScript 型定義: **なし**
- API Resource/DTO: **なし**
- テストファイル: 本施策そのもの

### 設計

既存 `MembershipWriteLockInventoryTest` (ロック規約と役割付与の単一窓口) とは
**役割を分ける**。あちらは「ロックを取っているか」、本件は「失効を呼んでいるか」である。
同じファイルに混ぜない (混ぜると 1 本のテストが 2 つの契約を持ち、失敗の意味が読めなくなる)。

**検査 A — 母集団の全数分類 (既定拒否)**

`OrganizationMembershipService` のメソッド (public / private の両方) のうち、
本文に**役割の書き込み**(`addRole(` / `removeRole(` / `syncRoles(`) か
**組織メンバーの除去**(`users()->detach(`) を含むものを母集団とする。
母集団の各メソッドは次のいずれかに分類されていなければならない (未分類は fail):

- `revokes`: 失効を呼ぶ (現状 `changeRole` / `removeMember` / `transferOwnership` / `normalizeOrganizationRole`)
- `exempt`: `OrgAccessRevocationExemption` の case + **30 文字以上の根拠**で登録
  (現状 `joinOrganization` の 1 件のみ)

```php
<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 役割を書き込むのに組織アクセスの失効を呼ばない経路の免除目録 (既定拒否)。
 *
 * 免除できるのは「その操作の時点で、その人のその組織における資格情報が
 * まだ存在し得ない」場合だけである。降格・除名・移譲は免除できない。
 */
enum OrgAccessRevocationExemption: string
{
    case JoinOrganization = 'OrganizationMembershipService::joinOrganization';

    public function rationale(): string
    {
        return match ($this) {
            self::JoinOrganization => '招待受諾は組織に入れる操作であり、その時点でその人が'
                .'その組織で持つ資格情報は 1 件も存在し得ない (発行には所属が前提のため)。'
                .'したがって失効の対象が構造的に空である。',
        };
    }
}
```

**検査 B — 呼び出しの位置 (構造の固定)**

`revokes` の各メソッド本文について、次を静的に確認する:

1. 本文に `accessRevoker->revoke(` が現れる
2. その位置が、本文中の**最後の**役割書き込み / detach の位置より**後**である
   (「失効してから役割を変える」形を落とす — 変更の途中で失敗すると失効だけ残る)
3. 本文に `DB::transaction(` が現れるか、宣言表で「トランザクションを張るメソッドから
   呼ばれる private」として宣言されている (`normalizeOrganizationRole` がこれ)

**検査 C — 検出器の負例 (空振り防止)**

検出器を private 関数に切り出し、fixture 文字列で次の 3 形を落とすことを証明する:

- 失効の呼び出しが無い
- 失効の呼び出しが役割書き込みより**前**にある
- 役割書き込みが 2 回あり、失効が 1 回目と 2 回目の**間**にある

**検査 D — 失効列への書き込みの単一窓口**

`app/` 配下で `oauth_sessions.revoked_at` / `oauth_access_tokens.revoked` /
`oauth_refresh_tokens.revoked` / `oauth_auth_codes.revoked` を**更新する**コードを走査し、
allowlist 以外に現れたら fail とする。

> **実装時の訂正 (2026-08-15)**: 当初は「失効列の名前が現れること」だけで判定する想定だったが、
> 実装して走らせたところ **API キーの失効 (`api_keys.revoked_at`) と招待の取り消し
> (`organization_invitations.revoked_at`) が同じ列名を持つため誤検出した**。
> 列名だけでは別物の概念を混ぜてしまうので、判定を
> 「**資格情報 4 表の名前を文字列リテラルとして持つ**」×「`update(` / `forceFill(` の
> 引数に失効列がある」の積に改めた。帰結として、表の名前を字句として持たない経路
> (Eloquent モデル越しの更新だけで表名が出てこない形) には沈黙する。
> この非対称はテストの説明文と `docs/architecture.md` に書く。

| 許可するファイル | 理由 |
|---|---|
| `Services/OAuth/OrganizationAccessRevoker.php` | 本件の窓口 (組織 × 利用者の全資格情報) |
| `Models/OauthSession.php` | 画面 / CLI からの**1 セッションだけ**の失効 (`revoke()`)。対象の広さが違うので統合しない |

**検査 E — 理由が観測にしか使われていないこと**

「理由は観測であって制御ではない」を機械化する。分岐の書き方
(`match ($reason)` / `switch ($reason)` / `if ($reason === …)`) を**列挙して禁止しない**
— 列挙は必ず漏れるうえ、`$this->applyRevocationPolicy($reason)` のように
別メソッドへ逃がされると素通りする。

代わりに**唯一の参照の意味的な位置まで固定する**。既存の
`Tests\Support\PhpTokenScan::normalize()` (空白とコメントを除いた
`token_get_all()` の正規化。`QueuedJobLeaseInventoryTest` と
`ExternalClientBoundaryScanner` が既に使っている共有基盤) を使い、
窓口の `revoke()` の本文について次の**両方**を確認する:

1. トークン上で変数 `$reason` の参照が**ちょうど 1 回**である
2. その 1 回が `'reason' => $reason->value` という**監査 metadata の値の位置**に現れる
   (直前が `T_DOUBLE_ARROW`、直後が `T_OBJECT_OPERATOR` + `value`、
   さらにその前が文字列リテラル `'reason'` というトークン列で照合する)

**正規化の意味を正確に書く** (Codex Round 3 の指摘): 空白とコメントは**除外**され、
文字列の中に書かれた `$reason` は `T_VARIABLE` として**数えない**。
一方、metadata のキーである文字列トークン (`'reason'`) は照合に要るので**保持する**。
したがって、説明のコメントやエラー文言に `$reason` と書いても落ちない。

**保証範囲を誇張しない**: 固定できるのは `revoke()` の本文に現れる
**`$reason` の直接の参照**だけである。`func_get_args()` や `debug_backtrace()` で
引数を間接的に取り出す形は字句では見えないので落とせない。
これは通常の保守変更ではなく意図的な迂回であり、静的検査で塞ぐ対象にしない。

**負例 (検出器が働いていることの証明)**:

- `$this->applyRevocationPolicy($reason);` だけがある (回数は 1 だが位置が違う)
- 説明のコメントに `$reason` があり、metadata にも正規の参照がある (**緑になること**)
- metadata には固定文字列を入れ、別の用途で `$reason` を 1 回だけ使う
- `match ($reason)` で分岐している

**検査 F — 失効の監査は握り潰さない版を使うこと**

窓口の本文に `->recordOrFail(` が現れ、`->record(` が**現れない**ことを確認する。
握り潰す版に差し替わると「資格情報は失効したが監査に残っていない」状態が
静かに生まれるため、書き分けを構造で固定する。

**検査 G — ひとまとまりの外から呼ぶと実行時に拒否されること**

> **実装時に追加 (2026-08-15)**。当初は施策 8 (振る舞いのテスト) に置く予定だったが、
> **Feature / Unit レーンは `RefreshDatabase` が全体をトランザクションで包むため
> 深さ 0 の状態を作れず、「外から呼ぶ」形をそもそも再現できない**ことが実装中に判明した。
> Architecture レーンは `RefreshDatabase` を使わないので深さ 0 のまま窓口を呼べる。
> 引数のモデルは検査の前に例外になるため保存不要で、DB にも触れない。
> よって本検査は本 gate に置き、施策 8 側からは削る (置き場所をテストの説明文にも書く)。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (テストのヘルパは `string` / `list<string>`)
- [x] `ReflectionMethod::getStartLine()` の `int|false` を narrowing する
- [x] enum の `rationale()` は網羅 `match`

### テスト計画

- [ ] 本施策そのものがテスト。**先に赤で置く** (施策 3・4 の実装前に走らせて赤を確認する)
- [ ] 検査 C の負例 3 形が「検出器が実際に働いている」ことを証明する
- [ ] 免除の**件数**を完全一致で固定し、増減が必ず差分に現れるようにする

### リスク

- **静的検査の限界 (誇張しない)**: 見ているのは「本文に呼び出しの字句が在ること」と
  「その位置が役割書き込みより後であること」だけである。**すべての制御経路で
  失効が走ることは保証しない** — 途中に早期 return や条件分岐を足せば、
  検査は緑のまま失効しない経路が生まれる。ここは施策 8 の振る舞いのテストが担う。
  **この非対称をテストの説明文に書く** (「この検査があれば失効は必ず走る」とは読めないようにする)。
- 母集団の抽出が字句ベースなので、変数経由の呼び出し (`$method = 'addRole'`) には沈黙する。
  これも説明文に書く。

---

## 施策 6: 先置きの検査 2 — 外部 API の書き込み資格 (既定拒否)

### 変更箇所

- 新規 `tests/Architecture/RestWriteScopeRevalidationInvariantTest.php`
- 新規 `app/Enums/Security/ApiWriteScopeExemption.php`

### 波及変更

- TypeScript 型定義: **なし**
- API Resource/DTO: **なし**
- テストファイル: 本施策そのもの

### 設計

**検査 A — 変更系 route の書き込み資格 (既定拒否)**

母集団 = 名前が `api.v1.` で始まり、メソッドに `POST` / `PUT` / `PATCH` / `DELETE` を
含む route (MCP endpoint は別経路なので除外)。母集団の抽出は既存
`IdempotentRouteCoverageTest` と同じ形にする (**同じ母集団を 2 通りの流儀で数えない**)。

各 route は次のいずれかでなければならない:

- `api-key.ability:write` を**ちょうど 1 本**持つ (現状 items の作成・更新・削除の 3 本)
- `ApiWriteScopeExemption` の case + **30 文字以上の根拠**で登録 (現状 `api.v1.me.session.revoke` の 1 本)

**検査 B — 免除の前提 (空疎な免除の禁止)**

免除した route については、その根拠が実際に成立していることを機械で確かめる。
`api.v1.me.session.revoke` の根拠は「専用 scope (`session.revoke`) で判定する」なので、
`RevokeSessionController` の本文が `CliOAuthScope::SessionRevoke` を参照していることを検査する。

**検査 C — 主体の解決の関門 (再評価が消えていないこと)**

OAuth トークン経路の安全は `ResolveApiActor` の毎リクエスト再評価だけに載っている。
ここを固定する:

- `ResolveApiActor::contextFromUserToken()` の本文が
  `isRevoked(` (セッションの生存) と `isMemberOf(` (所属) を**両方**含む
- 変更系 route が `resolve.api-actor` を持つ

**API キー経路はここでは固定しない (前提の訂正)**。設計 Round 1 の時点では
「退会者が発行した API キーは実行時に拒否される」と書いていたが、実装を実読した結果
**これは読み取りについて偽**であった。`contextFromApiKey()` は発行者が解決できることしか
見ておらず、発行者の所属は再評価していない。真の境界は次のとおりで、これは
**施策 8 の振る舞いのテストで固定する** (静的検査では書けない性質のため):

| 経路 | 発行者が組織から外れた後 |
|---|---|
| API キーで**読み取り** | **通る** (組織の資産である鍵として振る舞う) |
| API キーで**書き込み** | **403** (`ProjectPolicy` が発行者の現在の組織ロールを評価する) |

この非対称を「防御がある」と丸めない。鍵を止める手段は組織管理者による
API キーの失効操作 (既存の管理画面) である。所属の再評価を足すかどうかは
**本件の範囲外**とする — 発行した管理者が抜けた瞬間に組織の自動連携が無言で止まる、
という可用性側の事故を新たに作る判断であり、正典 3 本も同じ理由で組織の API キーを
失効対象から外している。文書 (施策 9) に残余リスクとして書き、後続の候補として残す。

**扱わないこと (二重管理の回避)**

- middleware の**実行順序** (`resolve.api-actor` < `api.project-in-org` < `api-key.ability`) は
  既存 `TenantBoundaryOrderingTest` が priority list を正本として測っている。ここでは測らない。
- 認証 guard の分類は既存 `ApiGuardAllowlistInvariantTest` の担当。
- 冪等キーの配線は既存 `IdempotentRouteCoverageTest` の担当。

**検査 D — 負例 (空振り防止)**

一時登録した `POST /api/v1/__write_scope_negative_control__` (ability 無し・免除未登録) を
検出器が拾うことを確認する (既存 `IdempotentRouteCoverageTest` と同じ手口)。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている
- [x] `Route::getName()` の `?string` を narrowing
- [x] enum の `rationale()` は網羅 `match`

### テスト計画

- [ ] 本施策そのものがテスト
- [ ] 検査 D の負例が実際に赤くなること
- [ ] 免除の件数を完全一致で固定

### リスク

- **保証範囲を誇張しない**: 見ているのは `api/v1/` 配下の名前付き route だけである。
  web 側の変更系・`oauth/*`・将来の別 prefix には**沈黙する**。説明文に書く。
- 検査 C は字句検査なので、メソッドの中身が「呼んでいるが結果を使っていない」形は落とせない。
  実挙動は既存の Feature テスト (`CliOAuthSessionTest` ほか) と施策 8 が担う。

---

## 施策 7: 先置きの検査 3 — MCP の認可の関門 (既定拒否)

### 変更箇所

- 新規 `tests/Architecture/McpAuthorizationChokePointTest.php`
- `tests/Architecture/McpWriteToolIdempotencyEnforcementTest.php` — 失敗時の案内文に 1 行追記

### 波及変更

- TypeScript 型定義: **なし**
- API Resource/DTO: **なし**
- テストファイル: 本施策そのもの

### 設計

**検査 A — 認可の文脈が差し替え不能であること**

- `McpAuthorizationContext` が `final` である
- `for()` の本文が `isMemberOf(` を含む (所属の再評価が消えていない)
- `authorizeTool()` の本文が `hasPermission(` と `laratrust_team_id` を**両方**含む
  (権限判定は常にチーム明示という不変条件 5 の機械化)

**検査 B — 基底の実行部が業務処理より先に認可すること**

- `handle()` の本文で `McpAuthorizationContext::for(` と `authorizeTool(` の位置が
  `runTool(` の位置より**前**である
- **結果を捨てていないこと**: `authorizeTool(` の呼び出しが**否定**の形
  (`if (! $ctx->authorizeTool(`) で現れ、その直後の行に `throw` があること。
  呼ぶだけ呼んで戻り値を無視する形を落とす (Codex 指摘)

> **実装時の訂正 (2026-08-15)**: 当初はここに「`AppMcpTool::handle()` が `final` である」と
> 「登録された全 tool class が `handle` を再宣言していない」も含める予定だったが、
> **既存の `McpWriteToolIdempotencyEnforcementTest` が両方ともすでに固定していた**。
> 本施策自身が「同じ目印を 2 本作らない」と書いている以上、重複させずに委ねる。
> 新 gate の説明文へ「どちらが担当か」を明記して、次に読む人が探せるようにする。

**検査 C — 負例 (空振り防止)**

検出器を private 関数に切り出し、fixture 文字列で次の 2 形を落とすことを証明する:

- `authorizeTool` の呼び出しが `runTool` より後にある
- `authorizeTool` を呼んでいるが否定と `throw` が無い (戻り値を捨てている)

**扱わないこと**

- 書き込み道具が増えたときの目印は既存 `McpWriteToolIdempotencyEnforcementTest` が
  すでに持っている。**同じ目印を 2 本作らない**。あちらの失敗時の案内文へ
  「書き込みの範囲の再評価 (`ToolName::requiredPermission()` の割り当て) も同時に決めること」を
  1 行足すだけにする。
- tool と enum の 1:1 対応は既存 `ToolNameInvariantTest` の担当。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている
- [x] `ReflectionClass::getFileName()` の `string|false` を narrowing
- [x] `class-string<Tool>` の phpdoc を維持

### テスト計画

- [ ] 本施策そのものがテスト
- [ ] 検査 C の負例が実際に赤くなること

### リスク

- **保証範囲を誇張しない**: `handle()` の中で認可が**呼ばれている**ことと**順序**しか見ない。
  「認可の結果を無視して実行する」形 (戻り値を捨てる) は落とせない。
  実挙動は既存 `McpToolsTest` が担う。この非対称を説明文に書く。

---

## 施策 8: 振る舞いのテスト

### 変更箇所

- 新規 `tests/Feature/Organizations/OrganizationAccessRevocationTest.php`
- 更新 `tests/Feature/Organization/ConsoleRoleTransitionTest.php`
- 更新 `tests/Feature/Auth/AccountDeletionTest.php`
- ~~更新 `tests/Architecture/ModelDirectFetchInvariantTest.php`~~ →
  **更新不要だった** (2026-08-15 実測)。同ファイルの `transferOwnership` への言及は
  走査器の限界を説明する**コメント 1 行**であり、引数を取る呼び出し site ではない。
  引数追加で壊れた呼び出しは `ConsoleRoleTransitionTest` の 12 箇所と
  `AccountDeletionTest` の 1 箇所の計 13 箇所である

### 波及変更

- TypeScript 型定義: **なし**
- API Resource/DTO: **なし**

### テスト計画

**A. 失効そのもの**

- [ ] `changeRole` の降格で 3 家族が失効する
- [ ] `changeRole` の**昇格でも**失効する (テスト名: 「昇格でも接続は切れる (差分で判断しない仕様)」)
- [ ] `changeRole` の同値 (冪等の早期 return) では失効しない
- [ ] `removeMember` で失効する
- [ ] `transferOwnership` で**譲り手と受け手の両方**が失効する (テスト名に「受け手も切れる」)
- [ ] `applyConsoleRole` の修復経路 (役割未付与の行への直接付与) で失効する
- [ ] `applyConsoleRole` で組織ロールが同値のままプロジェクト pivot だけが変わる場合は失効しない
- [ ] 招待受諾 (`joinOrganization`) では失効しない (免除の前提の固定)

**B. 家族ごとの独立性と網羅性**

- [ ] セッション行が 0 件でも、セッション行を持たないトークンと認可コードは失効する
- [ ] 更新トークンも一緒に失効する
- [ ] **親の利用トークンが既に失効済みで、更新トークンだけが未失効という不整合行**も失効する
      (母集団を「未失効の利用トークン」に絞ると取り逃す形。Codex Round 1 の Critical)
- [ ] 未交換の認可コードが失効し、**その後の交換が成立しない**
- [ ] 他組織 / 他利用者の資格情報が 1 件も巻き添えにならない

**C. ひとまとまりであること**

- [ ] ~~トランザクションの外から窓口を呼ぶと例外になる~~ → **施策 5 の検査 G へ移した**
      (このレーンは `RefreshDatabase` が全体をトランザクションで包むため深さ 0 を作れない)
- [ ] 役割変更が例外 (最後の Owner の降格など) で失敗したとき、失効も巻き戻る
- [ ] 監査が書けないとき、役割の変更ごと巻き戻る

**D. 監査**

- [ ] 失効 0 件でも監査が 1 行残る
- [ ] 監査に理由・操作した人・家族ごとの件数が入る
- [ ] `SecurityEventCoverageTest` の対応表と `covered_by` が一致する

**E. 実際に使えなくなること (端から端まで)**

- [ ] 除名の後、その人のトークンで MCP を叩くと拒否される
- [ ] 除名の後、その人のトークンで外部 API の書き込みを叩くと拒否される
- [ ] 除名の後、更新トークンでの再発行が拒否される
- [ ] 画面の接続セッション一覧に、除名した人のセッションが**失効済みとして**並ぶ

**F. 失効させないものの境界 (誇張しないことの固定)**

- [ ] 除名された発行者の **API キーで読み取りは通る** (組織の資産として振る舞う)
- [ ] 除名された発行者の **API キーで書き込みは 403** (`ProjectPolicy` の実行時評価)。
      ★このテストのキーには **`write` ability を必ず持たせる**。持たせないと
      資格不足の 403 で緑になり、「認可の再評価を通っていない実装」でも通ってしまう
      (Codex Round 2 の Suggestion)
- [ ] 組織の API キーは失効操作の対象外である (窓口を呼んでも `api_keys` が 1 行も変わらない)
- [ ] プロジェクト単位の役割変更 (`ProjectMemberController`) では失効しない

- [ ] 個別の `DatabaseTransactions` を使っていないことを確認 (グローバル `RefreshDatabase`)
- [ ] テストデータはすべて Factory で生成 (`OauthSessionFactory` が既存。認可コードは
      `DB::table('oauth_auth_codes')->insert()` で組む — vendor テーブルで Factory を持たない)

### リスク

- `--parallel` 実行で `oauth_*` の行が他のテストと混ざらないよう、必ず自分で作った
  組織・利用者に紐づけて検証する (件数の絶対値ではなく対象行の状態で判定する)。

---

## 施策 9: 文書

### 変更箇所

- `docs/mcp-oauth.md` — 失効の契約 / 昇格でも切れること / 保証しないもの
- `docs/architecture.md` — 「組織アクセスの失効」の節を追加 (保証しないものの正本)
- `AGENTS.md` — ドメイン固有規約へ 1 項目追加 (失効の窓口と目録の位置)

### 波及変更

- TypeScript 型定義: **なし**
- API Resource/DTO: **なし**
- テストファイル: **なし** (文書の同期を機械固定する仕組みは本件では作らない = 過剰)

### 書く内容

1. 失効の境界は「役割を変える操作が成功したこと」であり、役割の集合の差分は取らない。
   帰結として**昇格でも接続はやり直しになる**。これは既知の仕様である。
2. 失効する 3 家族と、**しないもの** (組織の API キー / プロジェクト単位の役割)。
3. 保証しないもの: 失効の選択と確定の間の隙間 / 静的検査の限界 / 認可コードの交換時には
   所属を確認していないこと (後続の候補)。
4. **API キーの残余リスク**: 書き方は仕組みではなく**結果**で書く —
   「所属の再評価をしない」ではなく「**発行した人が組織から外れても、
   その鍵の読み取り権限は残る**」と書く (運用者が誤解しないため)。
   書き込みは認可で 403 になる。鍵を止める手段は組織管理者による失効操作である。
   この非対称を「防御がある」と丸めない。所属の再評価を足すと、発行者の退職で
   組織の自動連携が無言で止まるため、**別の判断として独立に起こす**。
4. 窓口は `OrganizationAccessRevoker` ただ 1 本で、役割を書き込む経路は
   失効を呼ぶか目録へ免除登録するかの二択である (既定拒否)。

### リスク

- 文書と実装がずれる。ずれた時に落ちるのは施策 5 の目録 (免除の件数の完全一致) であって
  文書ではない。**文書の同期検査は作らない** (今必要なものだけ作る)。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | `OrganizationMembershipService` の public メソッド 3 本の**引数が変わる**ため、同じファイル・同じテストを触る他の施策と必ず衝突する。加えて `SecurityEventType` の case 追加は `SecurityEventCoverageTest` を全体的に赤にするので、他の作業と並走すると原因の切り分けができなくなる。 |
| 競合リスク | 高い。`OrganizationMembershipService` / `SecurityEventRecorder` / `SecurityEventType` / `tests/Feature/Organization/ConsoleRoleTransitionTest.php` に触る作業とは同時に走らせない。 |

## 実装順序 (テストファースト)

1. 施策 5・6・7 の検査を**先に置く** (この時点で 5 は赤、6・7 は既存実装で緑になるはず。
   6・7 が緑にならなければ、それは今の実装に穴があるということなので先に判断する)
2. 施策 8 の振る舞いのテストを書いて**赤を確認**する
3. 施策 1・2 (型と監査の口) を実装する — `SecurityEventCoverageTest` が赤くなるので対応表も同時に埋める
4. 施策 3 (窓口) を実装する
5. 施策 4 (配線) を実装し、壊れた既存テスト 13 箇所を直す
6. 施策 9 (文書)
7. **AGENTS.md の検証コマンド一式を全部緑にする**:
   `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
   `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
   `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`。
   本件は frontend を 1 行も変えないが、**影響が無いことの根拠を口頭で述べず走らせて示す**
   (AGENTS.md は「全 green でコミット」であり、影響の有無を実装者が判断で省く運用ではない)。


## 実装差分 (git diff)

```diff
diff --git a/app/DataTransferObjects/Security/OrgAccessRevocationResult.php b/app/DataTransferObjects/Security/OrgAccessRevocationResult.php
new file mode 100644
index 0000000..81e38a5
--- /dev/null
+++ b/app/DataTransferObjects/Security/OrgAccessRevocationResult.php
@@ -0,0 +1,41 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Security;
+
+/**
+ * 組織アクセス失効の結果 (家族ごとの件数)。監査 metadata の材料。
+ *
+ * **0 件でも記録する**。「失効すべきものが無かった」ことも監査上の事実であり、
+ * 記録が無いと「窓口が呼ばれなかったのか / 対象が無かったのか」を区別できない。
+ */
+final readonly class OrgAccessRevocationResult
+{
+    public function __construct(
+        /** 失効させた oauth_sessions 行数 */
+        public int $sessions,
+        /** 失効させた access token 行数 */
+        public int $accessTokens,
+        /** 失効させた refresh token 行数 */
+        public int $refreshTokens,
+        /** 失効させた未交換の認可コード行数 */
+        public int $authCodes,
+    ) {}
+
+    public function total(): int
+    {
+        return $this->sessions + $this->accessTokens + $this->refreshTokens + $this->authCodes;
+    }
+
+    /** @return array{sessions: int, access_tokens: int, refresh_tokens: int, auth_codes: int} */
+    public function toArray(): array
+    {
+        return [
+            'sessions' => $this->sessions,
+            'access_tokens' => $this->accessTokens,
+            'refresh_tokens' => $this->refreshTokens,
+            'auth_codes' => $this->authCodes,
+        ];
+    }
+}
diff --git a/app/Enums/Security/ApiWriteScopeExemption.php b/app/Enums/Security/ApiWriteScopeExemption.php
new file mode 100644
index 0000000..7f5443c
--- /dev/null
+++ b/app/Enums/Security/ApiWriteScopeExemption.php
@@ -0,0 +1,27 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Security;
+
+/**
+ * 外部向け API の変更系 route が書き込み資格 (`api-key.ability:write`) を
+ * 持たないことが正しいと裁定した経路の免除目録 (既定拒否)。
+ *
+ * 免除できるのは「別の専用資格で判定している」場合だけである。
+ * 「認証さえ通っていれば書ける」経路は免除できない。
+ */
+enum ApiWriteScopeExemption: string
+{
+    case DedicatedSessionRevokeScope = 'dedicated_session_revoke_scope';
+
+    public function rationale(): string
+    {
+        return match ($this) {
+            self::DedicatedSessionRevokeScope => '自分の CLI セッションの失効は書き込み資格ではなく'
+                .'専用の資格 (session.revoke) で判定する。書き込み資格を要求すると'
+                .'「読み取りだけの接続が自分のログアウトをできない」詰みになる。'
+                .'専用資格を実際に見ていることは免除の前提として機械検査する。',
+        };
+    }
+}
diff --git a/app/Enums/Security/OrgAccessRevocationExemption.php b/app/Enums/Security/OrgAccessRevocationExemption.php
new file mode 100644
index 0000000..c05c65e
--- /dev/null
+++ b/app/Enums/Security/OrgAccessRevocationExemption.php
@@ -0,0 +1,25 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Security;
+
+/**
+ * 役割を書き込むのに組織アクセスの失効を呼ばない経路の免除目録 (既定拒否)。
+ *
+ * 免除できるのは「その操作の時点で、その人のその組織における資格情報が
+ * まだ存在し得ない」場合だけである。降格・除名・移譲は免除できない。
+ */
+enum OrgAccessRevocationExemption: string
+{
+    case JoinOrganization = 'OrganizationMembershipService::joinOrganization';
+
+    public function rationale(): string
+    {
+        return match ($this) {
+            self::JoinOrganization => '招待受諾は組織に入れる操作であり、その時点でその人が'
+                .'その組織で持つ資格情報は 1 件も存在し得ない (発行には所属が前提のため)。'
+                .'したがって失効の対象が構造的に空である。',
+        };
+    }
+}
diff --git a/app/Enums/Security/OrgAccessRevocationReason.php b/app/Enums/Security/OrgAccessRevocationReason.php
new file mode 100644
index 0000000..d6c2d26
--- /dev/null
+++ b/app/Enums/Security/OrgAccessRevocationReason.php
@@ -0,0 +1,37 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Security;
+
+/**
+ * 組織アクセス失効の理由 (監査 metadata の固定語彙)。
+ *
+ * **理由は制御フローを変えない**。窓口は理由に関わらず 3 家族を同じように失効させる。
+ * 分けているのは「なぜ接続が切れたのか」をサポート時に 1 行で答えるためだけである
+ * (とくに OwnershipTransferredTo は「昇格したのに切れた」という驚きの説明に要る)。
+ */
+enum OrgAccessRevocationReason: string
+{
+    /** 組織ロールの変更 (昇格・降格の区別はしない) */
+    case RoleChanged = 'role_changed';
+
+    /** 組織からの除名 */
+    case MemberRemoved = 'member_removed';
+
+    /** オーナー移譲の譲り手 (Owner → Admin) */
+    case OwnershipTransferredFrom = 'ownership_transferred_from';
+
+    /** オーナー移譲の受け手 (→ Owner)。**昇格でも切る**という設計判断の可視化 */
+    case OwnershipTransferredTo = 'ownership_transferred_to';
+
+    public function label(): string
+    {
+        return match ($this) {
+            self::RoleChanged => '組織ロールの変更',
+            self::MemberRemoved => '組織からの除名',
+            self::OwnershipTransferredFrom => 'オーナー移譲 (譲り手)',
+            self::OwnershipTransferredTo => 'オーナー移譲 (受け手)',
+        };
+    }
+}
diff --git a/app/Enums/SecurityEventType.php b/app/Enums/SecurityEventType.php
index ce31498..30ae408 100644
--- a/app/Enums/SecurityEventType.php
+++ b/app/Enums/SecurityEventType.php
@@ -41,6 +41,9 @@ enum SecurityEventType: string
     // パスキー (単独でログインできる強い資格) の増減。vendor イベントを購読して記録する
     case PasskeyRegistered = 'passkey_registered';
     case PasskeyDeleted = 'passkey_deleted';
+    // 組織の役割変更に同期した機械クライアント向け資格情報の失効
+    // (OrganizationAccessRevoker が recordOrFail で直接記録する)
+    case OrganizationAccessRevoked = 'organization_access_revoked';
 
     public function label(): string
     {
@@ -65,6 +68,7 @@ public function label(): string
             self::OrgMemberTwoFactorReset => '組織管理者によるメンバー 2FA リセット',
             self::PasskeyRegistered => 'パスキーの登録',
             self::PasskeyDeleted => 'パスキーの削除',
+            self::OrganizationAccessRevoked => '組織アクセスの失効',
         };
     }
 }
diff --git a/app/Http/Controllers/Organizations/OrganizationMemberController.php b/app/Http/Controllers/Organizations/OrganizationMemberController.php
index 6178cc5..72d1b9f 100644
--- a/app/Http/Controllers/Organizations/OrganizationMemberController.php
+++ b/app/Http/Controllers/Organizations/OrganizationMemberController.php
@@ -35,8 +35,11 @@ public function update(UpdateOrganizationMemberRoleRequest $request, Organizatio
         $this->resolveOrganizationMember($organization, $user);
         Gate::authorize('manageMembers', $organization);
 
+        $actor = $request->user();
+        Assert::isInstanceOf($actor, User::class);
+
         // 3 値遷移コマンド (admin/editor/shooter)。Owner 指定は enum 外 = 構造的に不可能
-        $membership->applyConsoleRole($organization, $user, $request->role());
+        $membership->applyConsoleRole($organization, $user, $request->role(), $actor);
 
         return back()->with('success', 'ロールを変更しました');
     }
@@ -47,7 +50,10 @@ public function destroy(Request $request, Organization $organization, User $user
         $this->resolveOrganizationMember($organization, $user);
         Gate::authorize('manageMembers', $organization);
 
-        $membership->removeMember($organization, $user);
+        $actor = $request->user();
+        Assert::isInstanceOf($actor, User::class);
+
+        $membership->removeMember($organization, $user, $actor);
 
         return back()->with('success', 'メンバーを削除しました');
     }
diff --git a/app/Services/OAuth/OrganizationAccessRevoker.php b/app/Services/OAuth/OrganizationAccessRevoker.php
new file mode 100644
index 0000000..5bccb47
--- /dev/null
+++ b/app/Services/OAuth/OrganizationAccessRevoker.php
@@ -0,0 +1,141 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\OAuth;
+
+use App\DataTransferObjects\Security\OrgAccessRevocationResult;
+use App\Enums\Security\OrgAccessRevocationReason;
+use App\Enums\SecurityEventType;
+use App\Models\Organization;
+use App\Models\User;
+use App\Services\Security\SecurityEventRecorder;
+use Illuminate\Support\Facades\DB;
+use Webmozart\Assert\Assert;
+
+/**
+ * 組織アクセスの失効の**唯一の窓口**。
+ *
+ * ある組織における、ある利用者の「人に委ねられた資格情報」をまとめて失効させる。
+ * 失効の境界は **「役割を変える操作が成功したこと」** であり、役割の集合の差分は取らない。
+ * 差分を取ると権限ライブラリの役割キャッシュ (本番で 1 時間有効) に依存した判定になり、
+ * 取りこぼしたときに通してしまう側へ倒れるためである (家系の正典 v2 / 裁定 AG-125)。
+ *
+ * **必ず呼び出し元のトランザクションの内側で呼ぶ**。役割の変更と失効が同じひとまとまりに
+ * 入っていないと、「役割は下がったのにトークンは生きている」中間状態と、
+ * 確定直後にプロセスが落ちて失効が無言で消える隙間の両方が生まれる。
+ * 外から呼ばれた場合は実行時に例外で拒否する (説明文とテストだけに頼らない)。
+ *
+ * **3 家族を途中で打ち切らない**。1 家族目が 0 件でも残りは必ず失効させる。
+ *
+ * 触らないもの:
+ *  - 組織が持つ API キー (`api_keys`) — 組織の資産であり、人の所属で消さない
+ *    (発行した管理者が抜けた瞬間に組織の自動連携が全部止まる事故を作らない)。
+ *    **誇張しない**: 退会者が発行したキーで**書き込み**を叩くと、認可
+ *    (app/Policies/ProjectPolicy.php) が発行者の現在の組織ロールを評価するので 403 になるが、
+ *    **読み取りは通る** (app/Http/Middleware/ResolveApiActor.php は発行者の所属を
+ *    再評価しない)。鍵を止めるのは組織管理者の操作 (API キー画面) である。
+ *    ★この 2 つはクラス参照ではなくファイル名で書く。`{@see}` で書くと整形器が
+ *    import を足し、退会経路の依存閉包 (AccountDeletionPathGateTest) が
+ *    説明のためだけに広がってしまうためである。
+ *  - プロジェクト単位の役割 — トークンの結び付き先は組織であり、その人はまだメンバーである。
+ *
+ * 保証しないこと:
+ *  - 失効の選択と確定の間に新しい資格情報が発行される隙間は閉じない
+ *    (発行の経路は組織行・利用者行のロックを取らない)。最後の拒否線は要求ごとの再評価である。
+ */
+final class OrganizationAccessRevoker
+{
+    public function __construct(
+        private readonly SecurityEventRecorder $recorder,
+    ) {}
+
+    /**
+     * 対象 (組織, 利用者) の資格情報を失効させ、監査を 1 行残す。
+     *
+     * @param  User|null  $actor  操作した人 (HTTP 外 = バッチ・コンソールは null が正常値)
+     */
+    public function revoke(
+        Organization $organization,
+        User $target,
+        OrgAccessRevocationReason $reason,
+        ?User $actor,
+    ): OrgAccessRevocationResult {
+        // 呼び出し元のひとまとまりの内側であることの実行時強制。
+        // ここを説明文だけに頼ると、外から呼ぶ経路が静かに生まれる。
+        Assert::greaterThan(
+            DB::transactionLevel(),
+            0,
+            'OrganizationAccessRevoker::revoke() は役割変更と同一のトランザクション内から呼ぶこと',
+        );
+
+        $organizationId = $organization->getKey();
+        Assert::integer($organizationId);
+        $userId = $target->getKey();
+        Assert::integer($userId);
+
+        // 家族 1: セッション行 (表示・actor 解決の判定に使う失効印)
+        $sessions = DB::table('oauth_sessions')
+            ->where('organization_id', $organizationId)
+            ->where('user_id', $userId)
+            ->whereNull('revoked_at')
+            ->update(['revoked_at' => now(), 'updated_at' => now()]);
+
+        // 家族 2: 利用トークンと、それに紐づく更新トークン。
+        // ★session_id では絞らない。絞ると「セッション行を持たないトークン」
+        //   (古い MCP トークン等) が生き残る。
+        // ★母集団を「まだ失効していない利用トークン」に絞らない。更新トークンは
+        //   親の利用トークン経由でしか辿れないので、親が既に失効済みで子が未失効という
+        //   不整合行があると、絞った瞬間にその子が生き残る (= 再発行の経路が残る)。
+        //   絞るのは**件数を数える更新文の側だけ**にする。
+        /** @var list<string> $tokenIds */
+        $tokenIds = DB::table('oauth_access_tokens')
+            ->where('organization_id', $organizationId)
+            ->where('user_id', $userId)
+            ->pluck('id')
+            ->all();
+
+        $accessTokens = 0;
+        $refreshTokens = 0;
+        if ($tokenIds !== []) {
+            $accessTokens = DB::table('oauth_access_tokens')
+                ->whereIn('id', $tokenIds)
+                // 主キーで絞った後でも所有権の条件を残す (監査上の意図の明示 + 取り違えの保険)
+                ->where('organization_id', $organizationId)
+                ->where('user_id', $userId)
+                ->where('revoked', false)
+                ->update(['revoked' => true]);
+            $refreshTokens = DB::table('oauth_refresh_tokens')
+                ->whereIn('access_token_id', $tokenIds)
+                ->where('revoked', false)
+                ->update(['revoked' => true]);
+        }
+
+        // 家族 3: 未交換の認可コード。
+        // これを落とすと、失効の直前に発行された認可コードを失効の後に交換して
+        // 新しいトークンを得る経路が残る。
+        $authCodes = DB::table('oauth_auth_codes')
+            ->where('organization_id', $organizationId)
+            ->where('user_id', $userId)
+            ->where('revoked', false)
+            ->update(['revoked' => true]);
+
+        $result = new OrgAccessRevocationResult(
+            sessions: $sessions,
+            accessTokens: $accessTokens,
+            refreshTokens: $refreshTokens,
+            authCodes: $authCodes,
+        );
+
+        // 監査は握り潰さない。書けなければ役割の変更ごと巻き戻る。
+        // 失効 0 件でも 1 行残す (「対象が無かった」ことも監査上の事実である)。
+        $this->recorder->recordOrFail(SecurityEventType::OrganizationAccessRevoked, $target, [
+            'organization_id' => $organizationId,
+            'actor_user_id' => $actor?->getKey(),
+            'reason' => $reason->value,
+            'revoked' => $result->toArray(),
+        ]);
+
+        return $result;
+    }
+}
diff --git a/app/Services/Organization/OrganizationMembershipService.php b/app/Services/Organization/OrganizationMembershipService.php
index c1d9d1d..c91679a 100644
--- a/app/Services/Organization/OrganizationMembershipService.php
+++ b/app/Services/Organization/OrganizationMembershipService.php
@@ -11,6 +11,7 @@
 use App\Enums\AccountDeletionBlockReason;
 use App\Enums\AdminConsoleRole;
 use App\Enums\OrganizationRole;
+use App\Enums\Security\OrgAccessRevocationReason;
 use App\Enums\SecurityEventType;
 use App\Models\Organization;
 use App\Models\OrganizationInvitation;
@@ -19,6 +20,7 @@
 use App\Notifications\OrganizationInvitationNotification;
 use App\Services\Billing\AccountDeletionBillingGuard;
 use App\Services\Notification\NotificationCenterService;
+use App\Services\OAuth\OrganizationAccessRevoker;
 use App\Services\Project\DefaultProjectResolver;
 use App\Services\Security\SecurityEventRecorder;
 use App\Support\Account\AccountDeletionGrace;
@@ -56,6 +58,7 @@ public function __construct(
         private readonly DefaultProjectResolver $defaultProjects,
         private readonly NotificationCenterService $notifications,
         private readonly AccountDeletionBillingGuard $billingGuard,
+        private readonly OrganizationAccessRevoker $accessRevoker,
     ) {}
 
     /**
@@ -442,11 +445,16 @@ private function joinOrganization(OrganizationInvitation $invitation, Organizati
      * (DB::transaction のネストは savepoint 扱いのため、changeRole の ValidationException は
      * そのまま外へ伝播し外側 tx ごと rollback される)。
      *
+     * 失効 (組織アクセスの資格情報) は**自分では呼ばない**。呼ぶと 1 操作で 2 回失効させる
+     * ことになるため、委譲先 (normalizeOrganizationRole / changeRole) が呼ぶ。
+     *
+     * @param  User|null  $actor  操作した人 (監査用。HTTP 外 = バッチ・コンソールは null が正常値)
+     *
      * @throws ValidationException 非メンバー / 最終 Owner 保護 / Default Project 不在
      */
-    public function applyConsoleRole(Organization $organization, User $target, AdminConsoleRole $role): void
+    public function applyConsoleRole(Organization $organization, User $target, AdminConsoleRole $role, ?User $actor): void
     {
-        DB::transaction(function () use ($organization, $target, $role): void {
+        DB::transaction(function () use ($organization, $target, $role, $actor): void {
             // canonical 共通ロック境界 (users 昇順 → organizations)。normalizeOrganizationRole の
             // 直接 addRole 経路も含めロック下で直列化する。
             $this->lockForMembershipWrite([$this->keyOf($target)], [$this->keyOf($organization)]);
@@ -456,7 +464,7 @@ public function applyConsoleRole(Organization $organization, User $target, Admin
             if ($projectRole === null) {
                 // Admin コマンド: org ロール正規化 → stale pivot 掃除
                 // (org 配下 project に限定 = cross-org 不変条件)
-                $this->normalizeOrganizationRole($organization, $target, $role);
+                $this->normalizeOrganizationRole($organization, $target, $role, $actor);
                 $this->detachProjectMemberships($organization, $target);
 
                 return;
@@ -471,7 +479,7 @@ public function applyConsoleRole(Organization $organization, User $target, Admin
                 ]);
             }
 
-            $this->normalizeOrganizationRole($organization, $target, $role);
+            $this->normalizeOrganizationRole($organization, $target, $role, $actor);
             $project->members()->syncWithoutDetaching([
                 $target->id => ['role' => $projectRole->value],
             ]);
@@ -483,9 +491,14 @@ public function applyConsoleRole(Organization $organization, User $target, Admin
      * 「未割当」= MemberRoleState::derive(null, ...)) は changeRole が「非メンバー」として
      * 拒否するため、修復経路として addRole で直接付与する (管理画面から正規化できる契約)。
      *
+     * **本メソッドは applyConsoleRole が張ったトランザクションの内側でしか呼ばれない**
+     * (private かつ呼び出し元が 1 箇所)。修復の枝で失効を呼ぶのはこの前提に依存する。
+     *
+     * @param  User|null  $actor  操作した人 (監査用)
+     *
      * @throws ValidationException 非メンバー / 最終 Owner 保護 (changeRole 継承)
      */
-    private function normalizeOrganizationRole(Organization $organization, User $target, AdminConsoleRole $role): void
+    private function normalizeOrganizationRole(Organization $organization, User $target, AdminConsoleRole $role, ?User $actor): void
     {
         if ($target->organizationRole($organization) === null) {
             // 非 attach は changeRole と同じ契約で拒否 (第 1 層は Controller の URL 整合 guard = 404)
@@ -494,23 +507,39 @@ private function normalizeOrganizationRole(Organization $organization, User $tar
             }
             $target->addRole($role->organizationRole()->value, $organization->laratrust_team_id);
 
+            // 修復も役割の付与である。changeRole を経ない唯一の枝なので、ここにも置く
+            // (置かないと「管理画面から役割を直したのに古いトークンが生きている」経路が残る)。
+            $this->accessRevoker->revoke(
+                $organization,
+                $target,
+                OrgAccessRevocationReason::RoleChanged,
+                $actor,
+            );
+
             return;
         }
 
         // 同値なら changeRole 内で早期 return = 冪等。最終 Owner 保護も継承
-        $this->changeRole($organization, $target, $role->organizationRole());
+        $this->changeRole($organization, $target, $role->organizationRole(), $actor);
     }
 
     /**
      * ロール変更。Owner への昇格は transferOwnership のみが正規経路
      * (Controller 側のバリデーションが Owner 指定を拒否する)。
      *
+     * **役割の入れ替えの後、同じトランザクションの中で**その人のこの組織における
+     * 機械クライアント向け資格情報を失効させる (家系の正典 v2)。昇格でも切れる —
+     * 役割の集合の差分で判断すると、権限ライブラリの役割キャッシュ依存になり
+     * 取りこぼしたときに通してしまう側へ倒れるためである。
+     *
+     * @param  User|null  $actor  操作した人 (監査用。HTTP 外は null が正常値)
+     *
      * @throws ValidationException 非メンバー / 最後の Owner の降格
      */
-    public function changeRole(Organization $organization, User $target, OrganizationRole $newRole): void
+    public function changeRole(Organization $organization, User $target, OrganizationRole $newRole, ?User $actor): void
     {
         // [TOCTOU 封じ] 事前チェックを撤廃し、検証をすべてロック取得後・ロック下で行う。
-        DB::transaction(function () use ($organization, $target, $newRole): void {
+        DB::transaction(function () use ($organization, $target, $newRole, $actor): void {
             // canonical 共通ロック境界 (users 昇順 → organizations)。deleteAccount 等と直列化。
             $this->lockForMembershipWrite([$this->keyOf($target)], [$this->keyOf($organization)]);
 
@@ -523,7 +552,7 @@ public function changeRole(Organization $organization, User $target, Organizatio
                 throw ValidationException::withMessages(['role' => ['このユーザーは組織のメンバーではありません。']]);
             }
             if ($currentRole === $newRole) {
-                return; // 冪等
+                return; // 冪等 (何も変わっていないので失効もしない)
             }
             // Owner を降格させる場合は他に Owner がいることを要求 (Owner 不在の組織を作らない)
             if ($currentRole === OrganizationRole::Owner && ! $this->hasAnotherOwner($organization, $freshTarget)) {
@@ -533,18 +562,31 @@ public function changeRole(Organization $organization, User $target, Organizatio
             }
             $freshTarget->removeRole($currentRole->value, $organization->laratrust_team_id);
             $freshTarget->addRole($newRole->value, $organization->laratrust_team_id);
+
+            // 役割の入れ替えの**後**・同一トランザクション内
+            $this->accessRevoker->revoke(
+                $organization,
+                $freshTarget,
+                OrgAccessRevocationReason::RoleChanged,
+                $actor,
+            );
         });
     }
 
     /**
      * メンバー削除。Owner は削除不可 (先に transferOwnership が必要)。
      *
+     * 除名の**後**、同じトランザクションの中でその人のこの組織における機械クライアント向け
+     * 資格情報を失効させる (家系の正典 v2)。
+     *
+     * @param  User|null  $actor  操作した人 (監査用。HTTP 外は null が正常値)
+     *
      * @throws ValidationException 非メンバー / Owner
      */
-    public function removeMember(Organization $organization, User $target): void
+    public function removeMember(Organization $organization, User $target, ?User $actor): void
     {
         // [TOCTOU 封じ] 検証をロック取得後・ロック下で行う。
-        DB::transaction(function () use ($organization, $target): void {
+        DB::transaction(function () use ($organization, $target, $actor): void {
             // canonical 共通ロック境界 (users 昇順 → organizations)。deleteAccount 等と直列化。
             $this->lockForMembershipWrite([$this->keyOf($target)], [$this->keyOf($organization)]);
 
@@ -569,6 +611,14 @@ public function removeMember(Organization $organization, User $target): void
             if ($freshTarget->current_organization_id === $organization->id) {
                 $freshTarget->forceFill(['current_organization_id' => null])->save();
             }
+
+            // 除名の後・同一トランザクション内
+            $this->accessRevoker->revoke(
+                $organization,
+                $freshTarget,
+                OrgAccessRevocationReason::MemberRemoved,
+                $actor,
+            );
         });
     }
 
@@ -597,6 +647,10 @@ private function detachProjectMemberships(Organization $organization, User $targ
      * オーナー移譲。organization_user の両者の行を lockForUpdate で直列化し、
      * 並行移譲による Owner 0 人 / 2 人の中間状態を防ぐ (spirux 方式)。
      *
+     * 役割の入れ替えの後、同じトランザクションの中で**譲り手と受け手の両方**の
+     * 機械クライアント向け資格情報を失効させる (家系の正典 v2)。受け手は昇格だが、
+     * 役割の集合の差分で判断しないという設計判断の帰結として同じように切れる。
+     *
      * @throws ValidationException from が Owner でない / to が非メンバー / 自己移譲
      */
     public function transferOwnership(Organization $organization, User $from, User $to): void
@@ -646,6 +700,11 @@ public function transferOwnership(Organization $organization, User $from, User $
                 $freshTo->removeRole($toRole->value, $teamId);
             }
             $freshTo->addRole(OrganizationRole::Owner->value, $teamId);
+
+            // 役割の入れ替えの後・同一トランザクション内。操作した人は譲り手 ($freshFrom)。
+            // 受け手も切る (昇格でも切る = 差分で判断しないという設計判断の帰結)。
+            $this->accessRevoker->revoke($freshOrg, $freshFrom, OrgAccessRevocationReason::OwnershipTransferredFrom, $freshFrom);
+            $this->accessRevoker->revoke($freshOrg, $freshTo, OrgAccessRevocationReason::OwnershipTransferredTo, $freshFrom);
         });
 
         $this->recorder->record(SecurityEventType::OwnershipTransferred, $from, [
diff --git a/app/Services/Security/SecurityEventRecorder.php b/app/Services/Security/SecurityEventRecorder.php
index 42f145d..d44934c 100644
--- a/app/Services/Security/SecurityEventRecorder.php
+++ b/app/Services/Security/SecurityEventRecorder.php
@@ -7,31 +7,57 @@
 use App\Enums\SecurityEventType;
 use App\Models\SecurityAuditEvent;
 use App\Models\User;
+use App\Services\OAuth\OrganizationAccessRevoker;
 
 /**
  * security_audit_events への記録の唯一の窓口。
- * 監査記録の失敗で主処理を巻き込まない (記録は best-effort、例外は report のみ)。
+ *
+ * 既定 ({@see record()}) は best-effort で、記録の失敗が主処理を巻き込まない。
+ * 失効の監査だけは握り潰さない版 ({@see recordOrFail()}) を使う。
  */
 class SecurityEventRecorder
 {
     /**
+     * 監査記録 (best-effort)。**既存の意味は変えない** — 記録の失敗で主処理を巻き込まない。
+     *
      * @param  array<string, mixed>  $metadata
      */
     public function record(SecurityEventType $type, ?User $user, array $metadata = []): void
     {
         try {
-            $event = new SecurityAuditEvent([
-                'event_type' => $type->value,
-                'metadata' => $metadata === [] ? null : $metadata,
-                'ip_address' => request()->ip(),
-                'occurred_at' => now(),
-            ]);
-            if ($user !== null) {
-                $event->user()->associate($user);
-            }
-            $event->save();
+            $this->write($type, $user, $metadata);
         } catch (\Throwable $e) {
             report($e);
         }
     }
+
+    /**
+     * 監査記録 (握り潰さない)。**書けなければ呼び出し元のトランザクションごと巻き戻る**。
+     *
+     * 「資格情報は失効したが、その事実が監査に残っていない」状態を作らないための版である。
+     * 組織アクセスの失効 ({@see OrganizationAccessRevoker}) だけがこれを使う。
+     * 認証系の記録 (ログイン失敗など) にこれを使ってはならない —
+     * 監査の失敗でログインそのものを落とすことになるためである。
+     *
+     * @param  array<string, mixed>  $metadata
+     */
+    public function recordOrFail(SecurityEventType $type, ?User $user, array $metadata = []): void
+    {
+        $this->write($type, $user, $metadata);
+    }
+
+    /** @param array<string, mixed> $metadata */
+    private function write(SecurityEventType $type, ?User $user, array $metadata): void
+    {
+        $event = new SecurityAuditEvent([
+            'event_type' => $type->value,
+            'metadata' => $metadata === [] ? null : $metadata,
+            'ip_address' => request()->ip(),
+            'occurred_at' => now(),
+        ]);
+        if ($user !== null) {
+            $event->user()->associate($user);
+        }
+        $event->save();
+    }
 }
diff --git a/tests/Architecture/AccountDeletionPathGateTest.php b/tests/Architecture/AccountDeletionPathGateTest.php
index 54726f8..27ea79e 100644
--- a/tests/Architecture/AccountDeletionPathGateTest.php
+++ b/tests/Architecture/AccountDeletionPathGateTest.php
@@ -113,6 +113,13 @@
     'App\DataTransferObjects\Notification\ManualJobPayload',
     'App\DataTransferObjects\Notification\TicketBalanceLowPayload',
     'App\DataTransferObjects\Organizations\AccountDeletionBlockerDto',
+    // ↓ T174 (組織の役割変更に同期したトークン失効) で閉包に入った 3 クラス。
+    //   閉包はクラス粒度なので、退会そのものが失効を呼ばなくても
+    //   OrganizationMembershipService が失効の窓口を注入した時点で入る。
+    //   3 つとも oauth_* 表の失効列の更新と監査の記録しか行わず、
+    //   決済事業者 SDK への到達辺を持たない (検査 2 が機械的に固定する)。
+    'App\DataTransferObjects\Security\OrgAccessRevocationResult',
+    'App\Enums\Security\OrgAccessRevocationReason',
     'App\Enums\AccountDeletionBlockReason',
     'App\Enums\AccountDeletionBlockerAction',
     'App\Enums\AdminConsoleRole',
@@ -160,6 +167,7 @@
     'App\Notifications\OrganizationInvitationNotification',
     'App\Services\Billing\AccountDeletionBillingGuard',
     'App\Services\Notification\NotificationCenterService',
+    'App\Services\OAuth\OrganizationAccessRevoker',
     'App\Services\Organization\OrganizationMembershipService',
     'App\Services\Project\DefaultProjectResolver',
     'App\Services\Security\SecurityEventRecorder',
diff --git a/tests/Architecture/McpAuthorizationChokePointTest.php b/tests/Architecture/McpAuthorizationChokePointTest.php
new file mode 100644
index 0000000..8ffc29b
--- /dev/null
+++ b/tests/Architecture/McpAuthorizationChokePointTest.php
@@ -0,0 +1,213 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Mcp\Tools\AppMcpTool;
+use App\Services\Mcp\Auth\McpAuthorizationContext;
+use Tests\Support\PhpTokenScan;
+
+/*
+ * MCP 経路の認可の関門 invariant。
+ *
+ * 組織の役割変更に同期した失効 ({@see \App\Services\OAuth\OrganizationAccessRevoker}) は
+ * 「発行済みの資格情報を切る」側の防御である。切る前に届いた要求に対する最後の拒否線は
+ * **要求ごとの再評価**であり、MCP 側でそれを行うのが {@see McpAuthorizationContext} である。
+ * その関門が消えていないこと・業務処理より前にあること・結果を捨てていないことを固定する。
+ *
+ * ★**扱わないこと** (同じ目印を 2 本作らない):
+ *   - `AppMcpTool::handle()` が final であること / 登録 tool が handle() を再宣言しないことは
+ *     既存の `McpWriteToolIdempotencyEnforcementTest` が既に固定している。
+ *   - tool と enum の 1:1 対応は `ToolNameInvariantTest` の担当。
+ *   - 書き込み道具が増えたときの目印も `McpWriteToolIdempotencyEnforcementTest` が持つ。
+ *
+ * ★**保証範囲を誇張しない**: 見ているのは `handle()` の本文に現れる字句と、その順序、
+ *   および「否定して throw する」形だけである。認可の**意味** (permission の割り当てが
+ *   妥当か) は見ていない。実挙動は `tests/Feature/Mcp/McpToolsTest.php` が担う。
+ */
+
+/** クラスのメソッド本文 (最初の `{` 以降) を素のソースとして取り出す。 */
+function mcpChokePointMethodBody(string $class, string $method): string
+{
+    $reflection = new ReflectionMethod($class, $method);
+    $file = (string) $reflection->getFileName();
+    $lines = file($file, FILE_IGNORE_NEW_LINES);
+    expect($lines)->toBeArray();
+    /** @var list<string> $lines */
+    $source = implode(PHP_EOL, array_slice(
+        $lines,
+        $reflection->getStartLine() - 1,
+        $reflection->getEndLine() - $reflection->getStartLine() + 1,
+    ));
+    $brace = strpos($source, '{');
+
+    return $brace === false ? '' : substr($source, $brace);
+}
+
+/** ソース断片を「空白とコメントを除いた 1 本の文字列」へ畳む。 */
+function mcpChokePointCompact(string $phpFragment): string
+{
+    $text = '';
+    foreach (PhpTokenScan::normalize('<?php '.$phpFragment) as $token) {
+        $text .= $token['text'];
+    }
+
+    return $text;
+}
+
+/**
+ * 認可が業務処理より前に来ているかの検出 (負のコントロールから再利用するため純関数)。
+ *
+ * @return list<string>
+ */
+function mcpChokePointOrderViolations(string $label, string $rawBody): array
+{
+    $body = mcpChokePointCompact($rawBody);
+    $violations = [];
+
+    $context = strpos($body, 'McpAuthorizationContext::for(');
+    $authorize = strpos($body, 'authorizeTool(');
+    $run = strpos($body, 'runTool(');
+
+    if ($context === false) {
+        $violations[] = $label.': 認可コンテキストの解決 (McpAuthorizationContext::for) が無い';
+    }
+    if ($authorize === false) {
+        $violations[] = $label.': 認可の呼び出し (authorizeTool) が無い';
+    }
+    if ($run === false) {
+        $violations[] = $label.': 業務処理の呼び出し (runTool) が無い';
+    }
+    if ($violations !== []) {
+        return $violations;
+    }
+
+    if ($context > $run) {
+        $violations[] = $label.': 認可コンテキストの解決が業務処理より後にある';
+    }
+    if ($authorize > $run) {
+        $violations[] = $label.': 認可の判定が業務処理より後にある';
+    }
+
+    return $violations;
+}
+
+/**
+ * 「否定して throw する」形かの検出 (呼ぶだけ呼んで戻り値を捨てる形を落とす)。
+ *
+ * @return list<string>
+ */
+function mcpChokePointResultUseViolations(string $label, string $rawBody): array
+{
+    $tokens = PhpTokenScan::normalize('<?php '.$rawBody);
+    $count = count($tokens);
+
+    for ($i = 0; $i < $count; $i++) {
+        if ($tokens[$i]['text'] !== 'authorizeTool') {
+            continue;
+        }
+        if (($tokens[$i + 1]['text'] ?? '') !== '(') {
+            continue;
+        }
+
+        // 直前の `if (` と `!` を探す (10 トークン以内)
+        $negated = false;
+        for ($k = $i - 1; $k >= 0 && $k >= $i - 10; $k--) {
+            if ($tokens[$k]['text'] === '!') {
+                $negated = true;
+            }
+            if ($tokens[$k]['id'] === T_IF) {
+                break;
+            }
+        }
+        if (! $negated) {
+            continue;
+        }
+
+        // 呼び出しの括弧を閉じる
+        $depth = 0;
+        $close = null;
+        for ($j = $i + 1; $j < $count; $j++) {
+            $t = $tokens[$j]['text'];
+            if ($t === '(') {
+                $depth++;
+            } elseif ($t === ')') {
+                $depth--;
+                if ($depth === 0) {
+                    $close = $j;
+
+                    break;
+                }
+            }
+        }
+        if ($close === null) {
+            continue;
+        }
+
+        // if の条件を閉じる `)` → `{` → `throw`
+        if (($tokens[$close + 1]['text'] ?? '') === ')'
+            && ($tokens[$close + 2]['text'] ?? '') === '{'
+            && ($tokens[$close + 3]['id'] ?? null) === T_THROW) {
+            return [];
+        }
+    }
+
+    return [$label.': 認可の結果を否定して throw する形になっていない (戻り値を捨てている)'];
+}
+
+test('検査A: 認可の文脈が差し替え不能で、所属と権限を毎回評価し直している', function (): void {
+    expect((new ReflectionClass(McpAuthorizationContext::class))->isFinal())->toBeTrue(
+        'McpAuthorizationContext を継承で差し替えられると認可の関門が迂回されます。');
+
+    $forBody = mcpChokePointCompact(mcpChokePointMethodBody(McpAuthorizationContext::class, 'for'));
+    expect(str_contains($forBody, 'isMemberOf('))->toBeTrue(
+        '所属の再評価が消えています (組織から外れた人のトークンが通るようになります)。');
+
+    $authorizeBody = mcpChokePointCompact(mcpChokePointMethodBody(McpAuthorizationContext::class, 'authorizeTool'));
+    expect(str_contains($authorizeBody, 'hasPermission('))->toBeTrue(
+        '権限の再評価が消えています。');
+    expect(str_contains($authorizeBody, 'laratrust_team_id'))->toBeTrue(
+        '権限判定は常に組織 (チーム) を明示すること (セキュリティ不変条件 5)。');
+});
+
+test('検査B: 基底の実行部は業務処理より先に認可する', function (): void {
+    $body = mcpChokePointMethodBody(AppMcpTool::class, 'handle');
+
+    expect(mcpChokePointOrderViolations('AppMcpTool::handle', $body))->toBe([],
+        '認可を業務処理より後に置くと、拒否されるべき呼び出しが副作用を起こしてから拒否されます。');
+});
+
+test('検査B2: 認可の結果を捨てていない (否定して throw する形)', function (): void {
+    $body = mcpChokePointMethodBody(AppMcpTool::class, 'handle');
+
+    expect(mcpChokePointResultUseViolations('AppMcpTool::handle', $body))->toBe([]);
+});
+
+test('検査C: 検出器の負例 (空振り防止)', function (): void {
+    // 1. 認可が業務処理より後にある
+    $late = '{ $r = $this->runTool($req, $ctx); $ctx = McpAuthorizationContext::for($http);'
+        .' if (! $ctx->authorizeTool($this->toolName())) { throw new AuthorizationException(); } }';
+    expect(mcpChokePointOrderViolations('fixture', $late))->toHaveCount(2);
+
+    // 2. 認可を呼んでいるが否定と throw が無い (戻り値を捨てている)
+    $ignored = '{ $ctx = McpAuthorizationContext::for($http); $ctx->authorizeTool($this->toolName());'
+        .' return $this->runTool($req, $ctx); }';
+    expect(mcpChokePointResultUseViolations('fixture', $ignored))->toHaveCount(1);
+    expect(mcpChokePointOrderViolations('fixture', $ignored))->toBe([]);
+
+    // 3. 否定はするが throw しない (握り潰す形)
+    $swallowed = '{ $ctx = McpAuthorizationContext::for($http);'
+        .' if (! $ctx->authorizeTool($this->toolName())) { return Response::json([]); }'
+        .' return $this->runTool($req, $ctx); }';
+    expect(mcpChokePointResultUseViolations('fixture', $swallowed))->toHaveCount(1);
+
+    // 4. 正例 (検出器が何でも赤くするわけではないことの対照)
+    $ok = '{ $ctx = McpAuthorizationContext::for($http);'
+        .' if (! $ctx->authorizeTool($this->toolName())) { throw new AuthorizationException(); }'
+        .' return $this->runTool($req, $ctx); }';
+    expect(mcpChokePointOrderViolations('fixture', $ok))->toBe([]);
+    expect(mcpChokePointResultUseViolations('fixture', $ok))->toBe([]);
+
+    // 5. 認可がまったく無い
+    $none = '{ return $this->runTool($req, $ctx); }';
+    expect(mcpChokePointOrderViolations('fixture', $none))->toHaveCount(2);
+});
diff --git a/tests/Architecture/McpWriteToolIdempotencyEnforcementTest.php b/tests/Architecture/McpWriteToolIdempotencyEnforcementTest.php
index 8777793..2aeada1 100644
--- a/tests/Architecture/McpWriteToolIdempotencyEnforcementTest.php
+++ b/tests/Architecture/McpWriteToolIdempotencyEnforcementTest.php
@@ -104,6 +104,9 @@ function mcpEnforcementSourceOf(string $class): string
         .PHP_EOL.'2. T109 を解消する (AppMcpTool::handle() の冪等判定を runTool() の'
         .'リソース解決より後へ。REST 側の api.project-in-org < idempotent と同型のハザード)'
         .PHP_EOL.'3. write tool の idempotency_key 必須化・replay・conflict の behavioral テストを追加する'
-        .PHP_EOL.'4. 本 pin をその時点の write tool 一覧へ更新する'
+        .PHP_EOL.'4. 書き込みの範囲の再評価 (ToolName::requiredPermission() の割り当て) も同時に決める'
+        .'(認可の関門そのものは McpAuthorizationChokePointTest が固定しているが、'
+        .'新しい write tool にどの権限を要求するかは人が決めるほかない)'
+        .PHP_EOL.'5. 本 pin をその時点の write tool 一覧へ更新する'
         .PHP_EOL.'設計の根拠: devnotes/20260809-0027-idempotency-concurrent-claim/');
 });
diff --git a/tests/Architecture/OrganizationAccessRevocationChokePointTest.php b/tests/Architecture/OrganizationAccessRevocationChokePointTest.php
new file mode 100644
index 0000000..69725d5
--- /dev/null
+++ b/tests/Architecture/OrganizationAccessRevocationChokePointTest.php
@@ -0,0 +1,555 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Security\OrgAccessRevocationExemption;
+use App\Enums\Security\OrgAccessRevocationReason;
+use App\Models\OauthSession;
+use App\Models\Organization;
+use App\Models\User;
+use App\Services\OAuth\OrganizationAccessRevoker;
+use App\Services\Organization\OrganizationMembershipService;
+use Illuminate\Support\Facades\DB;
+use Tests\Support\PhpTokenScan;
+
+/*
+ * 組織アクセス失効の配線 invariant (既定拒否)。
+ *
+ * 「組織の役割を書き込む経路は、同じひとまとまりの中で失効の窓口
+ * ({@see OrganizationAccessRevoker}) を呼ぶ」を機械強制する。呼ばないものは
+ * 型付き分類 + 30 文字以上の根拠で免除目録へ登録させる。
+ *
+ * ★既存の `MembershipWriteLockInventoryTest` (ロック規約と役割付与の単一窓口) とは
+ *   役割を分ける。あちらは「ロックを取っているか」、本件は「失効を呼んでいるか」である。
+ *   同じファイルに混ぜると 1 本のテストが 2 つの契約を持ち、失敗の意味が読めなくなる。
+ *
+ * ★**保証範囲を誇張しない**: 本 gate が見ているのは
+ *   「メソッド本文に失効の呼び出しの字句が在ること」と
+ *   「その位置が最後の役割書き込みより後であること」だけである。
+ *   **すべての制御経路で失効が走ることは保証しない** — 途中に早期 return や条件分岐を
+ *   足せば、本 gate は緑のまま失効しない経路が生まれる。
+ *   実際に失効が起きることは `tests/Feature/Organizations/OrganizationAccessRevocationTest.php`
+ *   (振る舞いのテスト) が担う。
+ *   また母集団の抽出は字句ベースなので、変数経由の呼び出し (`$method = 'addRole';`) には
+ *   **沈黙する**。
+ *   失効列の単一窓口 (検査D) は「資格情報 4 表の名前を文字列で持つファイル」×
+ *   「`update(` / `forceFill(` の引数に失効列がある」の積で判定する。
+ *   表の名前を字句として持たない経路 (Eloquent モデル越しの更新だけで表名が出てこない形) には
+ *   **沈黙する**。
+ */
+
+/** 役割の書き込み / 組織メンバーの除去とみなす字句 (母集団のセレクタ)。 */
+function orgRevocationRoleWriteMarkers(): array
+{
+    return ['addRole(', 'removeRole(', 'syncRoles(', 'users()->detach('];
+}
+
+/** 失効の呼び出しの字句。 */
+function orgRevocationRevokeMarker(): string
+{
+    return 'accessRevoker->revoke(';
+}
+
+/** 免除理由の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
+function orgRevocationReasonMinLength(): int
+{
+    return 30;
+}
+
+/** 免除の**件数** (完全一致。増えても減っても赤くなる)。 */
+function orgRevocationExemptionCount(): int
+{
+    return 1;
+}
+
+/**
+ * 母集団メソッドの分類 (既定拒否。未分類は fail)。
+ *
+ * @return array<string, string> メソッド名 => 'revokes' | 'exempt'
+ */
+function orgRevocationClassification(): array
+{
+    return [
+        'changeRole' => 'revokes',
+        'removeMember' => 'revokes',
+        'transferOwnership' => 'revokes',
+        'normalizeOrganizationRole' => 'revokes',
+        'joinOrganization' => 'exempt',
+    ];
+}
+
+/**
+ * `revokes` 側の「ひとまとまり」の出所。
+ *
+ * 'self' = そのメソッド自身が `DB::transaction(` を張る。
+ * それ以外 = そのメソッドを呼ぶ側のメソッド名 (private が親のひとまとまりに乗る形)。
+ *
+ * @return array<string, string>
+ */
+function orgRevocationTransactionOwners(): array
+{
+    return [
+        'changeRole' => 'self',
+        'removeMember' => 'self',
+        'transferOwnership' => 'self',
+        'normalizeOrganizationRole' => 'applyConsoleRole',
+    ];
+}
+
+/** 免除目録 (deny-by-default)。 */
+function orgRevocationExemptions(): array
+{
+    return [
+        'joinOrganization' => OrgAccessRevocationExemption::JoinOrganization,
+    ];
+}
+
+/**
+ * ソースを「空白とコメントを除いた 1 本の文字列」へ畳む。
+ *
+ * 文字列リテラルの中身は残る (列名の照合に要る) が、コメント / docblock は消える。
+ * したがって説明文に `addRole(` や `$reason` と書いても検出には影響しない。
+ */
+function orgRevocationCompact(string $phpFragment): string
+{
+    $text = '';
+    foreach (PhpTokenScan::normalize('<?php '.$phpFragment) as $token) {
+        $text .= $token['text'];
+    }
+
+    return $text;
+}
+
+/** クラスのメソッド本文 (最初の `{` 以降) を素のソースとして取り出す。 */
+function orgRevocationMethodBody(string $class, string $method): string
+{
+    $reflection = new ReflectionMethod($class, $method);
+    $file = $reflection->getFileName();
+    expect($file)->toBeString();
+    $start = $reflection->getStartLine();
+    $end = $reflection->getEndLine();
+    expect($start)->toBeInt();
+    expect($end)->toBeInt();
+
+    $lines = file((string) $file, FILE_IGNORE_NEW_LINES);
+    expect($lines)->toBeArray();
+    /** @var list<string> $lines */
+    $source = implode(PHP_EOL, array_slice($lines, $start - 1, $end - $start + 1));
+
+    $brace = strpos($source, '{');
+
+    // 抽象メソッド等で本文が無い形は本 gate の母集団に入らない (空文字を返す)
+    return $brace === false ? '' : substr($source, $brace);
+}
+
+/**
+ * 検出器の本体 (負のコントロールから再利用するため純関数にする)。
+ *
+ * @return list<string> 違反の説明 (空なら適合)
+ */
+function orgRevocationBodyViolations(string $label, string $rawBody): array
+{
+    $body = orgRevocationCompact($rawBody);
+    $violations = [];
+
+    // 最後の役割書き込みの位置
+    $lastWrite = null;
+    foreach (orgRevocationRoleWriteMarkers() as $marker) {
+        $offset = 0;
+        while (($pos = strpos($body, $marker, $offset)) !== false) {
+            $lastWrite = $lastWrite === null ? $pos : max($lastWrite, $pos);
+            $offset = $pos + 1;
+        }
+    }
+
+    if ($lastWrite === null) {
+        return [$label.': 役割の書き込みが 1 件も無い (母集団の抽出が壊れている)'];
+    }
+
+    // 最後の役割書き込みより後に失効の呼び出しがあること
+    $after = strpos($body, orgRevocationRevokeMarker(), $lastWrite);
+    if ($after === false) {
+        $violations[] = strpos($body, orgRevocationRevokeMarker()) === false
+            ? $label.': 失効の呼び出し ('.orgRevocationRevokeMarker().') が本文に無い'
+            : $label.': 失効の呼び出しが最後の役割書き込みより前にある '
+                .'(役割の入れ替えの途中で失敗すると失効だけが残る)';
+    }
+
+    return $violations;
+}
+
+/** `revoke()` の中の `$reason` 参照が「監査 metadata の値の位置」ちょうど 1 回であること。 */
+function orgRevocationReasonUsageViolations(string $label, string $rawBody): array
+{
+    $tokens = PhpTokenScan::normalize('<?php '.$rawBody);
+
+    $indexes = [];
+    foreach ($tokens as $i => $token) {
+        if ($token['id'] === T_VARIABLE && $token['text'] === '$reason') {
+            $indexes[] = $i;
+        }
+    }
+
+    if (count($indexes) !== 1) {
+        return [$label.': $reason の参照が '.count($indexes).' 回ある (監査 metadata の 1 回だけであること)'];
+    }
+
+    $i = $indexes[0];
+    $before2 = $tokens[$i - 2] ?? null;
+    $before1 = $tokens[$i - 1] ?? null;
+    $after1 = $tokens[$i + 1] ?? null;
+    $after2 = $tokens[$i + 2] ?? null;
+
+    $ok = $before2 !== null && $before2['id'] === T_CONSTANT_ENCAPSED_STRING
+        && trim($before2['text'], "'\"") === 'reason'
+        && $before1 !== null && $before1['id'] === T_DOUBLE_ARROW
+        && $after1 !== null && $after1['id'] === T_OBJECT_OPERATOR
+        && $after2 !== null && $after2['text'] === 'value';
+
+    if (! $ok) {
+        return [$label.": \$reason の唯一の参照が \"'reason' => \$reason->value\" の位置にない "
+            .'(理由は観測であって制御ではない)'];
+    }
+
+    return [];
+}
+
+/** 資格情報の 4 表 (この表の失効列だけが本 gate の対象)。 */
+function orgRevocationCredentialTables(): array
+{
+    return ['oauth_sessions', 'oauth_access_tokens', 'oauth_refresh_tokens', 'oauth_auth_codes'];
+}
+
+/**
+ * 資格情報の 4 表の名前を文字列リテラルとして持つファイルか。
+ *
+ * ★列名 (`revoked` / `revoked_at`) だけで判定すると、API キーの失効
+ * (`api_keys.revoked_at`) や招待の取り消し (`organization_invitations.revoked_at`) まで
+ * 拾ってしまう。**別物の概念を列名の一致だけで統合しない**ため、表の名前と対にする。
+ */
+function orgRevocationTouchesCredentialTable(string $phpSource): bool
+{
+    foreach (PhpTokenScan::normalize($phpSource) as $token) {
+        if ($token['id'] !== T_CONSTANT_ENCAPSED_STRING) {
+            continue;
+        }
+        if (in_array(trim($token['text'], "'\""), orgRevocationCredentialTables(), true)) {
+            return true;
+        }
+    }
+
+    return false;
+}
+
+/**
+ * `update([...])` / `forceFill([...])` の引数に失効列 (`revoked` / `revoked_at`) を
+ * 含むファイルか (= 失効列への書き込み)。
+ */
+function orgRevocationHasRevocationColumnWrite(string $phpSource): bool
+{
+    $tokens = PhpTokenScan::normalize($phpSource);
+    $count = count($tokens);
+
+    for ($i = 0; $i < $count; $i++) {
+        $text = $tokens[$i]['text'];
+        if ($text !== 'update' && $text !== 'forceFill') {
+            continue;
+        }
+        if (($tokens[$i - 1]['id'] ?? null) !== T_OBJECT_OPERATOR) {
+            continue;
+        }
+        if (($tokens[$i + 1]['text'] ?? '') !== '(') {
+            continue;
+        }
+
+        // 呼び出しの括弧が閉じるまでを引数の範囲とする
+        $depth = 0;
+        for ($j = $i + 1; $j < $count; $j++) {
+            $t = $tokens[$j]['text'];
+            if ($t === '(') {
+                $depth++;
+
+                continue;
+            }
+            if ($t === ')') {
+                $depth--;
+                if ($depth === 0) {
+                    break;
+                }
+
+                continue;
+            }
+            if ($tokens[$j]['id'] === T_CONSTANT_ENCAPSED_STRING
+                && in_array(trim($t, "'\""), ['revoked', 'revoked_at'], true)) {
+                return true;
+            }
+        }
+    }
+
+    return false;
+}
+
+/** 資格情報 4 表の失効列へ書き込むファイルか (表の名前と失効列の書き込みの両方を持つ)。 */
+function orgRevocationWritesCredentialRevocation(string $phpSource): bool
+{
+    return orgRevocationTouchesCredentialTable($phpSource)
+        && orgRevocationHasRevocationColumnWrite($phpSource);
+}
+
+/**
+ * 失効列へ書き込んでよいファイル (allowlist)。
+ *
+ * @return array<string, string> 相対パス => 理由
+ */
+function orgRevocationWriteAllowlist(): array
+{
+    return [
+        'app/Services/OAuth/OrganizationAccessRevoker.php' => '本件の窓口 (ある組織におけるある利用者の資格情報をまとめて失効させる)',
+        'app/Models/OauthSession.php' => '画面 / CLI からの 1 セッションだけの失効。対象の広さが違うので窓口と統合しない',
+    ];
+}
+
+test('検査A: 役割を書き込むメソッドはすべて分類されている (未分類は fail)', function (): void {
+    $classification = orgRevocationClassification();
+    $reflection = new ReflectionClass(OrganizationMembershipService::class);
+
+    $population = [];
+    foreach ($reflection->getMethods() as $method) {
+        if ($method->getDeclaringClass()->getName() !== OrganizationMembershipService::class) {
+            continue;
+        }
+        $body = orgRevocationCompact(orgRevocationMethodBody(OrganizationMembershipService::class, $method->getName()));
+        foreach (orgRevocationRoleWriteMarkers() as $marker) {
+            if (str_contains($body, $marker)) {
+                $population[] = $method->getName();
+
+                break;
+            }
+        }
+    }
+
+    sort($population);
+    $declared = array_keys($classification);
+    sort($declared);
+
+    expect($population)->toBe($declared,
+        '役割を書き込むメソッドの集合と分類表が一致しません。新しい経路は '
+        .'失効を呼ぶ (revokes) か、免除目録へ登録する (exempt) かのどちらかに分類してください。');
+});
+
+test('検査A2: 分類の値は revokes / exempt のいずれかで、exempt は免除目録に登録されている', function (): void {
+    $violations = [];
+
+    foreach (orgRevocationClassification() as $method => $kind) {
+        if (! in_array($kind, ['revokes', 'exempt'], true)) {
+            $violations[] = "{$method}: 未知の分類 {$kind}";
+
+            continue;
+        }
+        if ($kind !== 'exempt') {
+            continue;
+        }
+        $exemption = orgRevocationExemptions()[$method] ?? null;
+        if (! $exemption instanceof OrgAccessRevocationExemption) {
+            $violations[] = "{$method}: exempt なのに免除目録に登録がありません";
+
+            continue;
+        }
+        if ($exemption->value !== 'OrganizationMembershipService::'.$method) {
+            $violations[] = "{$method}: 免除目録の case 値がメソッドを指していません ({$exemption->value})";
+        }
+        if (mb_strlen($exemption->rationale()) < orgRevocationReasonMinLength()) {
+            $violations[] = "{$method}: 免除の根拠が ".orgRevocationReasonMinLength().' 文字未満です';
+        }
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('検査A3: 免除の件数が宣言値と一致する (増えても減っても検出する)', function (): void {
+    expect(count(orgRevocationExemptions()))->toBe(orgRevocationExemptionCount(),
+        '免除を増減させたら orgRevocationExemptionCount() も書き換えてください '
+        .'(件数の変化が必ず差分に現れるようにするため)。');
+    expect(count(OrgAccessRevocationExemption::cases()))->toBe(orgRevocationExemptionCount(),
+        'OrgAccessRevocationExemption の case 数と目録の件数が食い違っています (死んだ case の残置)。');
+});
+
+test('検査B: revokes に分類したメソッドは最後の役割書き込みより後で失効を呼ぶ', function (): void {
+    $violations = [];
+
+    foreach (orgRevocationClassification() as $method => $kind) {
+        if ($kind !== 'revokes') {
+            continue;
+        }
+        $body = orgRevocationMethodBody(OrganizationMembershipService::class, $method);
+        $violations = [...$violations, ...orgRevocationBodyViolations($method, $body)];
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('検査B2: revokes の失効は必ずひとまとまり (トランザクション) の内側にある', function (): void {
+    $violations = [];
+
+    foreach (orgRevocationTransactionOwners() as $method => $owner) {
+        $body = orgRevocationCompact(orgRevocationMethodBody(OrganizationMembershipService::class, $method));
+
+        if ($owner === 'self') {
+            if (! str_contains($body, 'DB::transaction(')) {
+                $violations[] = "{$method}: 自分でトランザクションを張る宣言なのに DB::transaction( が無い";
+            }
+
+            continue;
+        }
+
+        $ownerBody = orgRevocationCompact(orgRevocationMethodBody(OrganizationMembershipService::class, $owner));
+        if (! str_contains($ownerBody, 'DB::transaction(')) {
+            $violations[] = "{$method}: 呼び出し元 {$owner} が DB::transaction( を張っていない";
+        }
+        if (! str_contains($ownerBody, '->'.$method.'(')) {
+            $violations[] = "{$method}: 呼び出し元 {$owner} が {$method}() を呼んでいない (宣言が陳腐化)";
+        }
+    }
+
+    // 宣言表と分類表 (revokes) の集合が一致すること
+    $declared = array_keys(orgRevocationTransactionOwners());
+    $revokes = array_keys(array_filter(orgRevocationClassification(), static fn (string $k): bool => $k === 'revokes'));
+    sort($declared);
+    sort($revokes);
+    expect($declared)->toBe($revokes, 'revokes の集合とトランザクション出所の宣言表が一致していません。');
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('検査C: 検出器の負例 (空振り防止)', function (): void {
+    // 1. 失効の呼び出しが無い
+    $noRevoke = '{ $u->addRole($r, $team); }';
+    expect(orgRevocationBodyViolations('fixture', $noRevoke))->toHaveCount(1);
+    expect(orgRevocationBodyViolations('fixture', $noRevoke)[0])->toContain('が本文に無い');
+
+    // 2. 失効が役割書き込みより前にある
+    $before = '{ $this->accessRevoker->revoke($org, $u, $reason, $actor); $u->addRole($r, $team); }';
+    expect(orgRevocationBodyViolations('fixture', $before))->toHaveCount(1);
+    expect(orgRevocationBodyViolations('fixture', $before)[0])
+        ->toContain('失効の呼び出しが最後の役割書き込みより前にある');
+
+    // 3. 役割書き込みが 2 回あり、失効がその間にある
+    $between = '{ $u->removeRole($old, $team); $this->accessRevoker->revoke($org, $u, $reason, $actor);'
+        .' $u->addRole($new, $team); }';
+    expect(orgRevocationBodyViolations('fixture', $between))->toHaveCount(1);
+
+    // 4. 正例 (検出器が何でも赤くするわけではないことの対照)
+    $ok = '{ $u->removeRole($old, $team); $u->addRole($new, $team);'
+        .' $this->accessRevoker->revoke($org, $u, $reason, $actor); }';
+    expect(orgRevocationBodyViolations('fixture', $ok))->toBe([]);
+
+    // 5. コメントの中の呼び出しは数えない (正規化がコメントを落とすことの確認)
+    $comment = '{ $u->addRole($new, $team); // $this->accessRevoker->revoke(...) を後で足す'.PHP_EOL.'}';
+    expect(orgRevocationBodyViolations('fixture', $comment))->toHaveCount(1);
+});
+
+test('検査D: 失効列 (revoked / revoked_at) へ書き込むのは allowlist のファイルだけ', function (): void {
+    $allowlist = orgRevocationWriteAllowlist();
+    $violations = [];
+    $found = [];
+
+    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));
+    foreach ($iterator as $file) {
+        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
+            continue;
+        }
+        $relative = str_replace(base_path().'/', '', $file->getPathname());
+        $source = (string) file_get_contents($file->getPathname());
+        if (! orgRevocationWritesCredentialRevocation($source)) {
+            continue;
+        }
+        $found[] = $relative;
+        if (! array_key_exists($relative, $allowlist)) {
+            $violations[] = $relative.': 失効列への書き込みは窓口 (OrganizationAccessRevoker) に集約してください';
+        }
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+
+    sort($found);
+    $declared = array_keys($allowlist);
+    sort($declared);
+    expect($found)->toBe($declared, 'allowlist に現存しないファイルが残っています (stale 検出)。');
+});
+
+test('検査D2: 検出器の負例 (表示用の配列や別テーブルの失効は数えない)', function (): void {
+    $write = '<?php DB::table(\'oauth_access_tokens\')->whereIn(\'id\', $ids)->update([\'revoked\' => true]);';
+    expect(orgRevocationWritesCredentialRevocation($write))->toBeTrue();
+
+    $writeAt = '<?php DB::table(\'oauth_sessions\')->where(\'id\', $id)'
+        .'->update([\'revoked_at\' => now()]);';
+    expect(orgRevocationWritesCredentialRevocation($writeAt))->toBeTrue();
+
+    // 表示用の配列 (書き込みではない)
+    $display = '<?php DB::table(\'oauth_sessions\')->get(); return [\'revoked_at\' => $this->revokedAt];';
+    expect(orgRevocationWritesCredentialRevocation($display))->toBeFalse();
+
+    // 資格情報の表だが失効列ではない列の更新
+    $otherColumn = '<?php DB::table(\'oauth_access_tokens\')->where(\'id\', $id)->update([\'session_id\' => $id]);';
+    expect(orgRevocationWritesCredentialRevocation($otherColumn))->toBeFalse();
+
+    // 別テーブルの失効 (API キー / 招待の取り消し) は本 gate の対象外
+    $apiKey = '<?php $key->forceFill([\'revoked_at\' => now()])->save();';
+    expect(orgRevocationWritesCredentialRevocation($apiKey))->toBeFalse();
+
+    // 1 セッションだけの失効 (OauthSession) は allowlist 側なので検出されて構わない
+    expect(orgRevocationWritesCredentialRevocation(
+        (string) file_get_contents((new ReflectionClass(OauthSession::class))->getFileName() ?: ''),
+    ))->toBeTrue();
+});
+
+test('検査E: 窓口の revoke() は理由を監査 metadata にしか使わない', function (): void {
+    $body = orgRevocationMethodBody(OrganizationAccessRevoker::class, 'revoke');
+
+    expect(orgRevocationReasonUsageViolations('OrganizationAccessRevoker::revoke', $body))->toBe([],
+        '理由 ($reason) は観測であって制御ではありません。分岐に使うと理由の追加が挙動の変更になります。');
+});
+
+test('検査E2: 検出器の負例 (理由の使われ方)', function (): void {
+    // 別メソッドへ逃がす形 (回数は 1 だが位置が違う)
+    $delegated = '{ $this->applyRevocationPolicy($reason); }';
+    expect(orgRevocationReasonUsageViolations('fixture', $delegated))->toHaveCount(1);
+
+    // 分岐に使う形
+    $branch = '{ $x = match ($reason) { A => 1 }; }';
+    expect(orgRevocationReasonUsageViolations('fixture', $branch))->toHaveCount(1);
+
+    // metadata に固定文字列を入れ、別用途で 1 回使う形
+    $decoy = "{ \$m = ['reason' => 'fixed']; \$this->log(\$reason); }";
+    expect(orgRevocationReasonUsageViolations('fixture', $decoy))->toHaveCount(1);
+
+    // 正例 + 説明のコメントに $reason と書いてあっても緑
+    $ok = '{ // $reason は観測にしか使わない'.PHP_EOL
+        ."\$this->recorder->recordOrFail(\$type, \$u, ['reason' => \$reason->value]); }";
+    expect(orgRevocationReasonUsageViolations('fixture', $ok))->toBe([]);
+});
+
+test('検査G: ひとまとまりの外から窓口を呼ぶと実行時に拒否される', function (): void {
+    // ★このレーンに置く理由: Feature / Unit レーンは RefreshDatabase が全体を
+    //   トランザクションで包むため、深さ 0 の状態を作れず「外から呼ぶ」形を再現できない。
+    //   Architecture レーンは RefreshDatabase を使わないので深さ 0 のまま呼べる。
+    //   引数のモデルは検査の前に例外になるため保存不要 (DB に触れない)。
+    expect(DB::transactionLevel())->toBe(0);
+
+    expect(fn () => app(OrganizationAccessRevoker::class)->revoke(
+        new Organization,
+        new User,
+        OrgAccessRevocationReason::RoleChanged,
+        null,
+    ))->toThrow(InvalidArgumentException::class);
+});
+
+test('検査F: 失効の監査は握り潰さない版 (recordOrFail) を使う', function (): void {
+    $body = orgRevocationCompact(orgRevocationMethodBody(OrganizationAccessRevoker::class, 'revoke'));
+
+    expect($body)->toContain('->recordOrFail(');
+    expect(str_contains($body, '->record('))->toBeFalse(
+        '握り潰す版 (record) に差し替わると「資格情報は失効したが監査に残っていない」状態が'
+        .'静かに生まれます。書き分けを構造で固定します。',
+    );
+});
diff --git a/tests/Architecture/RestWriteScopeRevalidationInvariantTest.php b/tests/Architecture/RestWriteScopeRevalidationInvariantTest.php
new file mode 100644
index 0000000..c967048
--- /dev/null
+++ b/tests/Architecture/RestWriteScopeRevalidationInvariantTest.php
@@ -0,0 +1,306 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\ApiKeyAbility;
+use App\Enums\OAuth\CliOAuthScope;
+use App\Enums\Security\ApiWriteScopeExemption;
+use App\Http\Controllers\Api\V1\Me\RevokeSessionController;
+use App\Http\Middleware\RequireApiKeyAbility;
+use App\Http\Middleware\ResolveApiActor;
+use Illuminate\Routing\Route as RoutingRoute;
+use Illuminate\Routing\Router;
+use Illuminate\Support\Facades\Route;
+use Illuminate\Support\Str;
+
+/*
+ * 外部向け API (REST v1) の書き込み資格と、実行時の主体再評価の invariant (既定拒否)。
+ *
+ * 組織の役割変更に同期した失効 ({@see \App\Services\OAuth\OrganizationAccessRevoker}) は
+ * 「発行済みの資格情報を切る」側の防御である。切れなかった / 切る前の要求に対する
+ * 最後の拒否線は **要求ごとの再評価** であり、その再評価の実在をここで固定する。
+ *
+ * 検査は 3 つ:
+ *  A. `api.v1.` の変更系 route は書き込み資格をちょうど 1 本持つか、免除目録に登録されている
+ *  B. 免除の**前提**が実際に成立している (空疎な免除の禁止)
+ *  C. 主体の解決 (`ResolveApiActor`) の再評価が消えていない
+ *
+ * ★**扱わないこと** (二重管理の回避):
+ *   middleware の実行順序は `TenantBoundaryOrderingTest`、認証 guard の分類は
+ *   `ApiGuardAllowlistInvariantTest`、冪等キーの配線は `IdempotentRouteCoverageTest` の担当。
+ *
+ * ★**保証範囲を誇張しない**: 見ているのは名前が `api.v1.` で始まる route だけである。
+ *   web 側の変更系・`oauth/*`・MCP transport・将来別 prefix の機械向け API には**沈黙する**
+ *   (MCP 側は `McpAuthorizationChokePointTest` が別に担当する)。
+ *   検査 C は字句検査なので「呼んでいるが結果を使っていない」形は落とせない。
+ *   実挙動は `tests/Feature/Api/OAuthDualGuardTest.php` と
+ *   `tests/Feature/Organizations/OrganizationAccessRevocationTest.php` が担う。
+ */
+
+/** 変更系 HTTP メソッド。 */
+function restWriteScopeMutatingMethods(): array
+{
+    return ['POST', 'PUT', 'PATCH', 'DELETE'];
+}
+
+/**
+ * 母集団件数 (**完全一致**)。
+ *
+ * 余裕を持たせるとセレクタが壊れて母集団が減っても気づけない。
+ * route を増減させたらこの数値も書き換えること。
+ */
+function restWriteScopeRouteCount(): int
+{
+    return 4;
+}
+
+/** 免除の件数 (完全一致)。 */
+function restWriteScopeExemptionCount(): int
+{
+    return 1;
+}
+
+/** 免除理由の最低文字数。 */
+function restWriteScopeReasonMinLength(): int
+{
+    return 30;
+}
+
+/**
+ * 書き込み資格を持たないことが正しいと裁定した route の目録。
+ *
+ * @return array<string, ApiWriteScopeExemption>
+ */
+function restWriteScopeExemptions(): array
+{
+    return [
+        'api.v1.me.session.revoke' => ApiWriteScopeExemption::DedicatedSessionRevokeScope,
+    ];
+}
+
+/**
+ * 免除の**前提**の機械検査 (空疎な免除の禁止)。
+ *
+ * @return array<string, array{class: class-string, marker: string}>
+ */
+function restWriteScopePremises(): array
+{
+    return [
+        'api.v1.me.session.revoke' => [
+            'class' => RevokeSessionController::class,
+            // 専用資格を実際に見ていること
+            'marker' => 'CliOAuthScope::SessionRevoke',
+        ],
+    ];
+}
+
+/** 解決後 middleware 列 (文字列 entry のみ)。 */
+function restWriteScopeResolvedMiddleware(RoutingRoute $route): array
+{
+    /** @var Router $router */
+    $router = Route::getFacadeRoot();
+
+    return array_values(array_filter(
+        $router->gatherRouteMiddleware($route),
+        static fn (mixed $entry): bool => is_string($entry),
+    ));
+}
+
+/** 実効 middleware 列に含まれる「書き込み資格」の本数。 */
+function restWriteScopeWriteAbilityCount(RoutingRoute $route): int
+{
+    $count = 0;
+    foreach (restWriteScopeResolvedMiddleware($route) as $entry) {
+        if (! is_a(Str::before($entry, ':'), RequireApiKeyAbility::class, true)) {
+            continue;
+        }
+        if (Str::after($entry, ':') === ApiKeyAbility::Write->value) {
+            $count++;
+        }
+    }
+
+    return $count;
+}
+
+/** 実効 middleware 列に主体解決 (`resolve.api-actor`) があるか。 */
+function restWriteScopeHasActorResolution(RoutingRoute $route): bool
+{
+    foreach (restWriteScopeResolvedMiddleware($route) as $entry) {
+        if (is_a(Str::before($entry, ':'), ResolveApiActor::class, true)) {
+            return true;
+        }
+    }
+
+    return false;
+}
+
+/** @return list<RoutingRoute> 母集団 (名前が api.v1. で始まる変更系) */
+function restWriteScopeRoutes(): array
+{
+    $mutating = restWriteScopeMutatingMethods();
+    $selected = [];
+
+    foreach (Route::getRoutes() as $route) {
+        $name = $route->getName();
+        if ($name === null || ! str_starts_with($name, 'api.v1.')) {
+            continue;
+        }
+        if (array_intersect($mutating, $route->methods()) === []) {
+            continue;
+        }
+        $selected[] = $route;
+    }
+
+    return $selected;
+}
+
+/**
+ * 違反検出の本体 (負のコントロールから再利用するため関数に切り出す)。
+ *
+ * @return list<string>
+ */
+function restWriteScopeViolations(): array
+{
+    $exemptions = restWriteScopeExemptions();
+    $violations = [];
+
+    foreach (restWriteScopeRoutes() as $route) {
+        $name = (string) $route->getName();
+        $count = restWriteScopeWriteAbilityCount($route);
+
+        if ($count === 1) {
+            continue;
+        }
+        if ($count === 0 && array_key_exists($name, $exemptions)) {
+            continue;
+        }
+
+        $violations[] = $count === 0
+            ? "{$name}: 書き込み資格 (api-key.ability:write) が無く免除目録にも未登録"
+            : "{$name}: 書き込み資格が {$count} 本ある";
+    }
+
+    return $violations;
+}
+
+/** クラスのメソッド本文 (最初の `{` 以降) を素のソースとして取り出す。 */
+function restWriteScopeMethodBody(string $class, string $method): string
+{
+    $reflection = new ReflectionMethod($class, $method);
+    $file = (string) $reflection->getFileName();
+    $lines = file($file, FILE_IGNORE_NEW_LINES);
+    expect($lines)->toBeArray();
+    /** @var list<string> $lines */
+    $source = implode(PHP_EOL, array_slice(
+        $lines,
+        $reflection->getStartLine() - 1,
+        $reflection->getEndLine() - $reflection->getStartLine() + 1,
+    ));
+    $brace = strpos($source, '{');
+
+    return $brace === false ? '' : substr($source, $brace);
+}
+
+test('母集団の件数が宣言値と一致する (セレクタの空振り検出)', function (): void {
+    expect(count(restWriteScopeRoutes()))->toBe(restWriteScopeRouteCount(),
+        'api.v1. の変更系 route の件数が宣言値と違います。route を増減させたら '
+        .'restWriteScopeRouteCount() も書き換えてください (セレクタが空振りしても気づけるようにするため)。');
+});
+
+test('検査A: 変更系 route は書き込み資格をちょうど 1 本持つか免除目録に登録されている', function (): void {
+    expect(restWriteScopeViolations())->toBe([],
+        '書き込み資格を配線するか、配線しないことが正しい理由を restWriteScopeExemptions() へ'
+        .'ApiWriteScopeExemption 付きで登録してください。'
+        .PHP_EOL.implode(PHP_EOL, restWriteScopeViolations()));
+});
+
+test('検査A2: 免除の件数と根拠 (形骸化ガード)', function (): void {
+    expect(count(restWriteScopeExemptions()))->toBe(restWriteScopeExemptionCount());
+    expect(count(ApiWriteScopeExemption::cases()))->toBe(restWriteScopeExemptionCount(),
+        'ApiWriteScopeExemption の case 数と目録の件数が食い違っています (死んだ case の残置)。');
+
+    $violations = [];
+    foreach (restWriteScopeExemptions() as $name => $exemption) {
+        if (mb_strlen($exemption->rationale()) < restWriteScopeReasonMinLength()) {
+            $violations[] = "{$name}: 根拠が ".restWriteScopeReasonMinLength().' 文字未満です';
+        }
+    }
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('検査A3: 免除目録の key は現存する母集団 route (stale 検出)', function (): void {
+    $names = [];
+    foreach (restWriteScopeRoutes() as $route) {
+        $names[(string) $route->getName()] = true;
+    }
+
+    $stale = array_values(array_filter(
+        array_keys(restWriteScopeExemptions()),
+        static fn (string $name): bool => ! isset($names[$name]),
+    ));
+
+    expect($stale)->toBe([], '免除目録に現存しない route があります: '.implode(', ', $stale));
+});
+
+test('検査B: 免除の前提が実際に成立している (空疎な免除の禁止)', function (): void {
+    $violations = [];
+
+    foreach (restWriteScopeExemptions() as $name => $exemption) {
+        $premise = restWriteScopePremises()[$name] ?? null;
+        if ($premise === null) {
+            $violations[] = "{$name}: 免除の前提が宣言されていません";
+
+            continue;
+        }
+        $file = (new ReflectionClass($premise['class']))->getFileName();
+        $source = $file === false ? '' : (string) file_get_contents($file);
+        if (! str_contains($source, $premise['marker'])) {
+            $violations[] = "{$name}: {$premise['class']} が {$premise['marker']} を参照していません "
+                .'(免除の根拠が実装から消えています)';
+        }
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('検査B2: 専用資格の case が実在する (前提の裏取り)', function (): void {
+    // 前提の marker が指す enum case が消えたら、marker の文字列照合だけでは気づけない
+    expect(CliOAuthScope::SessionRevoke->value)->toBe('session.revoke');
+});
+
+test('検査C: 主体の解決が所属とセッションを毎回再評価している', function (): void {
+    $body = restWriteScopeMethodBody(ResolveApiActor::class, 'contextFromUserToken');
+
+    expect(str_contains($body, 'isRevoked('))->toBeTrue(
+        'セッションの生存の再評価が消えています (失効済みセッションの token が通るようになります)。');
+    expect(str_contains($body, 'isMemberOf('))->toBeTrue(
+        '所属の再評価が消えています (組織から外れた人の token が通るようになります)。');
+});
+
+test('検査C2: 母集団の変更系 route はすべて主体解決を通る', function (): void {
+    $violations = [];
+
+    foreach (restWriteScopeRoutes() as $route) {
+        if (! restWriteScopeHasActorResolution($route)) {
+            $violations[] = (string) $route->getName().': resolve.api-actor が無い';
+        }
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('負のコントロール: 書き込み資格の無い api.v1 変更系 route を検出する', function (): void {
+    Route::post('api/v1/__write_scope_negative_control__', fn (): string => 'ok')
+        ->name('api.v1.__write_scope_negative_control__');
+
+    expect(restWriteScopeViolations())
+        ->toContain('api.v1.__write_scope_negative_control__: 書き込み資格 (api-key.ability:write) が無く免除目録にも未登録');
+});
+
+test('正のコントロール: 書き込み資格つきの api.v1 変更系 route は違反にならない', function (): void {
+    Route::post('api/v1/__write_scope_positive_control__', fn (): string => 'ok')
+        ->middleware('api-key.ability:write')
+        ->name('api.v1.__write_scope_positive_control__');
+
+    expect(restWriteScopeViolations())->toBe([]);
+});
diff --git a/tests/Architecture/SecurityEventCoverageTest.php b/tests/Architecture/SecurityEventCoverageTest.php
index ff168fc..89fb3a4 100644
--- a/tests/Architecture/SecurityEventCoverageTest.php
+++ b/tests/Architecture/SecurityEventCoverageTest.php
@@ -9,6 +9,7 @@
 use App\Http\Controllers\Organizations\OrganizationMemberController;
 use App\Services\Auth\PasswordCredentialService;
 use App\Services\Auth\SocialAccountService;
+use App\Services\OAuth\OrganizationAccessRevoker;
 use App\Services\Organization\OrganizationMembershipService;
 use Illuminate\Auth\Events\Failed;
 use Illuminate\Auth\Events\Login;
@@ -131,6 +132,10 @@ function securityEventRecordingMap(): array
             'event' => PasskeyDeleted::class,
             'covered_by' => 'tests/Feature/Auth/PasskeyAuditTrailTest.php',
         ],
+        SecurityEventType::OrganizationAccessRevoked->value => [
+            'caller' => OrganizationAccessRevoker::class,
+            'covered_by' => 'tests/Feature/Organizations/OrganizationAccessRevocationTest.php',
+        ],
     ];
 }
 
diff --git a/tests/Feature/Auth/AccountDeletionTest.php b/tests/Feature/Auth/AccountDeletionTest.php
index 7d830fe..8d5c35e 100644
--- a/tests/Feature/Auth/AccountDeletionTest.php
+++ b/tests/Feature/Auth/AccountDeletionTest.php
@@ -126,7 +126,7 @@ function accountDeletionError(): string
     $second = attachOrganizationMember($organization, OrganizationRole::Owner);
     attachOrganizationMember($organization, OrganizationRole::Member); // 孤児化するメンバー
     // service 正規経路で 2 人目 Owner を Admin へ降格 (owner を 1 人に戻す)
-    app(OrganizationMembershipService::class)->changeRole($organization, $second, OrganizationRole::Admin);
+    app(OrganizationMembershipService::class)->changeRole($organization, $second, OrganizationRole::Admin, null);
 
     $response = $this->actingAs($owner)
         ->withSession(['recent_auth_at' => time()])
diff --git a/tests/Feature/Console/PurgeDeletionRequestsCommandTest.php b/tests/Feature/Console/PurgeDeletionRequestsCommandTest.php
index ef352c9..f5e5c75 100644
--- a/tests/Feature/Console/PurgeDeletionRequestsCommandTest.php
+++ b/tests/Feature/Console/PurgeDeletionRequestsCommandTest.php
@@ -8,6 +8,7 @@
 use App\Services\Billing\AccountDeletionBillingGuard;
 use App\Services\Billing\Contracts\StripeGatewayInterface;
 use App\Services\Notification\NotificationCenterService;
+use App\Services\OAuth\OrganizationAccessRevoker;
 use App\Services\Organization\OrganizationMembershipService;
 use App\Services\Project\DefaultProjectResolver;
 use App\Services\Security\SecurityEventRecorder;
@@ -123,7 +124,7 @@ function dueUser(): User
     dueUser();
     dueUser();
 
-    $this->instance(OrganizationMembershipService::class, new class(app(SecurityEventRecorder::class), app(DefaultProjectResolver::class), app(NotificationCenterService::class), app(AccountDeletionBillingGuard::class)) extends OrganizationMembershipService
+    $this->instance(OrganizationMembershipService::class, new class(app(SecurityEventRecorder::class), app(DefaultProjectResolver::class), app(NotificationCenterService::class), app(AccountDeletionBillingGuard::class), app(OrganizationAccessRevoker::class)) extends OrganizationMembershipService
     {
         public function executeAccountDeletionRequest(User $user): bool
         {
diff --git a/tests/Feature/Organization/ConsoleRoleTransitionTest.php b/tests/Feature/Organization/ConsoleRoleTransitionTest.php
index 57c4c68..06d3449 100644
--- a/tests/Feature/Organization/ConsoleRoleTransitionTest.php
+++ b/tests/Feature/Organization/ConsoleRoleTransitionTest.php
@@ -36,7 +36,7 @@ function createOrgWithDefaultProject(): array
     $member = attachOrganizationMember($organization);
     attachProjectMember($project, $member, ProjectRole::Admin);
 
-    app(OrganizationMembershipService::class)->applyConsoleRole($organization, $member, AdminConsoleRole::Shooter);
+    app(OrganizationMembershipService::class)->applyConsoleRole($organization, $member, AdminConsoleRole::Shooter, null);
 
     expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
     expect($project->memberRole($member))->toBe(ProjectRole::Member);
@@ -47,7 +47,7 @@ function createOrgWithDefaultProject(): array
     $member = attachOrganizationMember($organization);
     attachProjectMember($project, $member, ProjectRole::Member);
 
-    app(OrganizationMembershipService::class)->applyConsoleRole($organization, $member, AdminConsoleRole::Admin);
+    app(OrganizationMembershipService::class)->applyConsoleRole($organization, $member, AdminConsoleRole::Admin, null);
 
     expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Admin);
     expect($project->memberRole($member))->toBeNull();
@@ -57,7 +57,7 @@ function createOrgWithDefaultProject(): array
     [$organization, , $project] = createOrgWithDefaultProject();
     $member = attachOrganizationMember($organization, OrganizationRole::Admin);
 
-    app(OrganizationMembershipService::class)->applyConsoleRole($organization, $member, AdminConsoleRole::Editor);
+    app(OrganizationMembershipService::class)->applyConsoleRole($organization, $member, AdminConsoleRole::Editor, null);
 
     expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
     expect($project->memberRole($member))->toBe(ProjectRole::Admin);
@@ -67,7 +67,7 @@ function createOrgWithDefaultProject(): array
     [$organization, , $project] = createOrgWithDefaultProject();
     $member = attachOrganizationMember($organization);
 
-    app(OrganizationMembershipService::class)->applyConsoleRole($organization, $member, AdminConsoleRole::Editor);
+    app(OrganizationMembershipService::class)->applyConsoleRole($organization, $member, AdminConsoleRole::Editor, null);
 
     expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
     expect($project->memberRole($member))->toBe(ProjectRole::Admin);
@@ -77,7 +77,7 @@ function createOrgWithDefaultProject(): array
     [$organization] = createOrganizationWithOwner();
     $member = attachOrganizationMember($organization);
 
-    expect(fn () => app(OrganizationMembershipService::class)->applyConsoleRole($organization, $member, $role))
+    expect(fn () => app(OrganizationMembershipService::class)->applyConsoleRole($organization, $member, $role, null))
         ->toThrow(ValidationException::class);
     // org ロールは変更されない (1 tx = 中間状態を残さない)
     expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
@@ -118,7 +118,7 @@ function createOrgWithDefaultProject(): array
     [$organization, $owner, $project] = createOrgWithDefaultProject();
     attachProjectMember($project, $owner, ProjectRole::Admin);
 
-    expect(fn () => app(OrganizationMembershipService::class)->applyConsoleRole($organization, $owner, AdminConsoleRole::Admin))
+    expect(fn () => app(OrganizationMembershipService::class)->applyConsoleRole($organization, $owner, AdminConsoleRole::Admin, null))
         ->toThrow(ValidationException::class);
     expect($owner->fresh()->organizationRole($organization))->toBe(OrganizationRole::Owner);
     // ロール変更が拒否されたら pivot 掃除にも到達しない (最終状態が部分適用されない)
@@ -129,7 +129,7 @@ function createOrgWithDefaultProject(): array
     [$organization] = createOrgWithDefaultProject();
     $outsider = User::factory()->create();
 
-    expect(fn () => app(OrganizationMembershipService::class)->applyConsoleRole($organization, $outsider, AdminConsoleRole::Shooter))
+    expect(fn () => app(OrganizationMembershipService::class)->applyConsoleRole($organization, $outsider, AdminConsoleRole::Shooter, null))
         ->toThrow(ValidationException::class);
     expect($organization->users()->whereKey($outsider->getKey())->exists())->toBeFalse();
 });
@@ -140,7 +140,7 @@ function createOrgWithDefaultProject(): array
     $broken = User::factory()->create();
     $organization->users()->attach($broken);
 
-    app(OrganizationMembershipService::class)->applyConsoleRole($organization, $broken, AdminConsoleRole::Shooter);
+    app(OrganizationMembershipService::class)->applyConsoleRole($organization, $broken, AdminConsoleRole::Shooter, null);
 
     expect($broken->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
     expect($project->memberRole($broken))->toBe(ProjectRole::Member);
@@ -151,7 +151,7 @@ function createOrgWithDefaultProject(): array
     $member = attachOrganizationMember($organization);
     attachProjectMember($project, $member, ProjectRole::Admin);
 
-    app(OrganizationMembershipService::class)->applyConsoleRole($organization, $member, AdminConsoleRole::Editor);
+    app(OrganizationMembershipService::class)->applyConsoleRole($organization, $member, AdminConsoleRole::Editor, null);
 
     expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
     expect($project->memberRole($member))->toBe(ProjectRole::Admin);
@@ -171,7 +171,7 @@ function createOrgWithDefaultProject(): array
     $member->addRole(OrganizationRole::Member->value, $otherOrg->laratrust_team_id);
     attachProjectMember($otherProject, $member, ProjectRole::Member);
 
-    app(OrganizationMembershipService::class)->applyConsoleRole($organization, $member, AdminConsoleRole::Admin);
+    app(OrganizationMembershipService::class)->applyConsoleRole($organization, $member, AdminConsoleRole::Admin, null);
 
     expect($project->memberRole($member))->toBeNull();
     expect($second->memberRole($member))->toBeNull();
@@ -189,7 +189,7 @@ function createOrgWithDefaultProject(): array
     $member->addRole(OrganizationRole::Member->value, $otherOrg->laratrust_team_id);
     attachProjectMember($otherProject, $member, ProjectRole::Admin);
 
-    app(OrganizationMembershipService::class)->removeMember($organization, $member);
+    app(OrganizationMembershipService::class)->removeMember($organization, $member, null);
 
     expect($organization->users()->whereKey($member->getKey())->exists())->toBeFalse();
     expect($project->memberRole($member))->toBeNull();
diff --git a/tests/Feature/Organizations/OrganizationAccessRevocationTest.php b/tests/Feature/Organizations/OrganizationAccessRevocationTest.php
new file mode 100644
index 0000000..7ffc24a
--- /dev/null
+++ b/tests/Feature/Organizations/OrganizationAccessRevocationTest.php
@@ -0,0 +1,638 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\AdminConsoleRole;
+use App\Enums\OrganizationRole;
+use App\Enums\ProjectRole;
+use App\Enums\Security\OrgAccessRevocationReason;
+use App\Enums\SecurityEventType;
+use App\Models\ApiKey;
+use App\Models\OauthSession;
+use App\Models\Organization;
+use App\Models\Project;
+use App\Models\SecurityAuditEvent;
+use App\Models\User;
+use App\Services\Organization\OrganizationMembershipService;
+use App\Services\Security\SecurityEventRecorder;
+use Illuminate\Support\Facades\Auth;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Str;
+use Illuminate\Validation\ValidationException;
+use Inertia\Testing\AssertableInertia;
+use Tests\Support\OAuthTestHelpers;
+
+/*
+ * 組織の役割変更に同期したトークン失効 (家系の正典 v2) の振る舞い。
+ *
+ * 失効の境界は「役割を変える操作が成功したこと」であり、役割の集合の差分は取らない。
+ * その帰結として**昇格でも接続はやり直しになる**。ここではその仕様と、
+ * 失効する 3 家族 / 失効させないもの (組織の API キー・プロジェクト単位の役割) の
+ * 境界を固定する。
+ */
+
+/**
+ * 資格情報の 1 揃い (セッション / セッション付きトークン / セッション無しトークン /
+ * 更新トークン / 未交換の認可コード) を作る。
+ *
+ * `oauth_*` は Passport の vendor テーブルで Factory を持たない
+ * (`OauthSession` だけが自前モデル) ため、素の insert で組む。
+ *
+ * @return array{session: OauthSession, bound: string, orphan: string, refresh: string, code: string}
+ */
+function revocationCredentials(Organization $organization, User $user): array
+{
+    /** @var OauthSession $session */
+    $session = OauthSession::factory()->cli()->create([
+        'user_id' => $user->id,
+        'organization_id' => $organization->id,
+    ]);
+
+    $clientId = $session->client_id;
+
+    $bound = revocationInsertAccessToken($organization, $user, $clientId, $session->id);
+    $orphan = revocationInsertAccessToken($organization, $user, $clientId, null);
+    $refresh = revocationInsertRefreshToken($bound);
+    $code = revocationInsertAuthCode($organization, $user, $clientId);
+
+    return [
+        'session' => $session,
+        'bound' => $bound,
+        'orphan' => $orphan,
+        'refresh' => $refresh,
+        'code' => $code,
+    ];
+}
+
+function revocationInsertAccessToken(Organization $organization, User $user, string $clientId, ?string $sessionId, bool $revoked = false): string
+{
+    $id = Str::random(80);
+    DB::table('oauth_access_tokens')->insert([
+        'id' => $id,
+        'user_id' => $user->id,
+        'organization_id' => $organization->id,
+        'session_id' => $sessionId,
+        'client_id' => $clientId,
+        'scopes' => json_encode(['cli:use', 'read']),
+        'revoked' => $revoked,
+        'created_at' => now(),
+        'updated_at' => now(),
+        'expires_at' => now()->addDay(),
+    ]);
+
+    return $id;
+}
+
+function revocationInsertRefreshToken(string $accessTokenId, bool $revoked = false): string
+{
+    $id = Str::random(80);
+    DB::table('oauth_refresh_tokens')->insert([
+        'id' => $id,
+        'access_token_id' => $accessTokenId,
+        'revoked' => $revoked,
+        'expires_at' => now()->addDays(14),
+    ]);
+
+    return $id;
+}
+
+function revocationInsertAuthCode(Organization $organization, User $user, string $clientId, bool $revoked = false): string
+{
+    $id = Str::random(80);
+    DB::table('oauth_auth_codes')->insert([
+        'id' => $id,
+        'user_id' => $user->id,
+        'organization_id' => $organization->id,
+        'session_id' => null,
+        'client_id' => $clientId,
+        'scopes' => json_encode(['cli:use']),
+        'revoked' => $revoked,
+        'expires_at' => now()->addMinutes(10),
+    ]);
+
+    return $id;
+}
+
+/** アクセストークンが失効済みか。 */
+function revocationTokenIsRevoked(string $id): bool
+{
+    return (bool) DB::table('oauth_access_tokens')->where('id', $id)->value('revoked');
+}
+
+/** 更新トークンが失効済みか。 */
+function revocationRefreshIsRevoked(string $id): bool
+{
+    return (bool) DB::table('oauth_refresh_tokens')->where('id', $id)->value('revoked');
+}
+
+/** 認可コードが失効済みか。 */
+function revocationCodeIsRevoked(string $id): bool
+{
+    return (bool) DB::table('oauth_auth_codes')->where('id', $id)->value('revoked');
+}
+
+/** 直近の失効監査 (metadata 付き)。 */
+function revocationLatestAudit(): ?SecurityAuditEvent
+{
+    /** @var SecurityAuditEvent|null $event */
+    $event = SecurityAuditEvent::query()
+        ->where('event_type', SecurityEventType::OrganizationAccessRevoked->value)
+        ->orderByDesc('id')
+        ->first();
+
+    return $event;
+}
+
+/** 失効監査の件数。 */
+function revocationAuditCount(): int
+{
+    return SecurityAuditEvent::query()
+        ->where('event_type', SecurityEventType::OrganizationAccessRevoked->value)
+        ->count();
+}
+
+// ---------------------------------------------------------------------------
+// A. 失効そのもの
+// ---------------------------------------------------------------------------
+
+test('降格すると 3 家族 (セッション / トークン / 認可コード) がまとめて失効する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('失効組織');
+    $member = attachOrganizationMember($organization, OrganizationRole::Admin);
+    $credentials = revocationCredentials($organization, $member);
+
+    app(OrganizationMembershipService::class)
+        ->changeRole($organization, $member, OrganizationRole::Member, $owner);
+
+    expect($credentials['session']->fresh()?->revoked_at)->not->toBeNull();
+    expect(revocationTokenIsRevoked($credentials['bound']))->toBeTrue();
+    expect(revocationTokenIsRevoked($credentials['orphan']))->toBeTrue();
+    expect(revocationRefreshIsRevoked($credentials['refresh']))->toBeTrue();
+    expect(revocationCodeIsRevoked($credentials['code']))->toBeTrue();
+});
+
+test('昇格でも接続は切れる (役割の差分で判断しない仕様)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('昇格組織');
+    $member = attachOrganizationMember($organization);
+    $credentials = revocationCredentials($organization, $member);
+
+    app(OrganizationMembershipService::class)
+        ->changeRole($organization, $member, OrganizationRole::Admin, $owner);
+
+    expect($credentials['session']->fresh()?->revoked_at)->not->toBeNull();
+    expect(revocationTokenIsRevoked($credentials['bound']))->toBeTrue();
+});
+
+test('同じ役割への変更 (冪等の早期 return) では失効しない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('冪等組織');
+    $member = attachOrganizationMember($organization);
+    $credentials = revocationCredentials($organization, $member);
+
+    app(OrganizationMembershipService::class)
+        ->changeRole($organization, $member, OrganizationRole::Member, $owner);
+
+    expect($credentials['session']->fresh()?->revoked_at)->toBeNull();
+    expect(revocationTokenIsRevoked($credentials['bound']))->toBeFalse();
+    expect(revocationAuditCount())->toBe(0);
+});
+
+test('除名すると失効する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('除名組織');
+    $member = attachOrganizationMember($organization);
+    $credentials = revocationCredentials($organization, $member);
+
+    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);
+
+    expect($credentials['session']->fresh()?->revoked_at)->not->toBeNull();
+    expect(revocationTokenIsRevoked($credentials['bound']))->toBeTrue();
+    expect(revocationCodeIsRevoked($credentials['code']))->toBeTrue();
+});
+
+test('オーナー移譲では譲り手と受け手の両方が切れる (受け手も切れる)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('移譲組織');
+    $successor = attachOrganizationMember($organization, OrganizationRole::Admin);
+
+    $ownerCredentials = revocationCredentials($organization, $owner);
+    $successorCredentials = revocationCredentials($organization, $successor);
+
+    app(OrganizationMembershipService::class)->transferOwnership($organization, $owner, $successor);
+
+    expect($ownerCredentials['session']->fresh()?->revoked_at)->not->toBeNull();
+    expect(revocationTokenIsRevoked($ownerCredentials['bound']))->toBeTrue();
+    expect($successorCredentials['session']->fresh()?->revoked_at)->not->toBeNull();
+    expect(revocationTokenIsRevoked($successorCredentials['bound']))->toBeTrue();
+});
+
+test('修復経路 (役割未付与の行への直接付与) でも失効する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('修復組織');
+    Project::factory()->forOrganization($organization)->create();
+
+    // 異常行を再現: attach のみでロール未付与 (表示状態は「未割当」)
+    $broken = User::factory()->create();
+    $organization->users()->attach($broken);
+    $credentials = revocationCredentials($organization, $broken);
+
+    app(OrganizationMembershipService::class)
+        ->applyConsoleRole($organization, $broken, AdminConsoleRole::Shooter, $owner);
+
+    expect($credentials['session']->fresh()?->revoked_at)->not->toBeNull();
+    expect(revocationTokenIsRevoked($credentials['bound']))->toBeTrue();
+});
+
+test('プロジェクト側の割当だけが変わり組織ロールが同値なら失効しない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('割当組織');
+    $project = Project::factory()->forOrganization($organization)->create();
+    $member = attachOrganizationMember($organization);
+    attachProjectMember($project, $member, ProjectRole::Admin);
+    $credentials = revocationCredentials($organization, $member);
+
+    // editor → shooter は組織ロールが Member のまま (プロジェクト側の pivot だけ変わる)
+    app(OrganizationMembershipService::class)
+        ->applyConsoleRole($organization, $member, AdminConsoleRole::Shooter, $owner);
+
+    expect($project->memberRole($member))->toBe(ProjectRole::Member);
+    expect($credentials['session']->fresh()?->revoked_at)->toBeNull();
+    expect(revocationAuditCount())->toBe(0);
+});
+
+test('招待受諾 (組織に入れる操作) では失効しない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('招待組織');
+    $invitee = User::factory()->create();
+
+    $invitation = app(OrganizationMembershipService::class)
+        ->inviteMember($organization, $owner, 'invitee@example.test', OrganizationRole::Member);
+    // 平文 token は保存されないため、既知の平文に対応する hash へ差し替える
+    $invitation->forceFill(['token_hash' => hash('sha256', 'join-token')])->save();
+
+    $this->actingAs($invitee)->post('/invitations/accept', ['token' => 'join-token'])
+        ->assertRedirect('/dashboard');
+
+    expect($organization->users()->whereKey($invitee->getKey())->exists())->toBeTrue();
+    // 免除の前提: 入れる操作では失効の窓口を呼ばない (監査が 1 行も増えない)
+    expect(revocationAuditCount())->toBe(0);
+});
+
+// ---------------------------------------------------------------------------
+// B. 家族ごとの独立性と網羅性
+// ---------------------------------------------------------------------------
+
+test('セッション行が 1 件も無くても、トークンと認可コードは失効する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('セッション無し組織');
+    $member = attachOrganizationMember($organization);
+
+    $client = OAuthTestHelpers::createMcpClient(name: 'セッション無し');
+    $clientId = (string) $client->getKey();
+    $token = revocationInsertAccessToken($organization, $member, $clientId, null);
+    $code = revocationInsertAuthCode($organization, $member, $clientId);
+
+    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);
+
+    expect(revocationTokenIsRevoked($token))->toBeTrue();
+    expect(revocationCodeIsRevoked($code))->toBeTrue();
+
+    $audit = revocationLatestAudit();
+    expect($audit?->metadata['revoked']['sessions'] ?? null)->toBe(0);
+    expect($audit?->metadata['revoked']['access_tokens'] ?? null)->toBe(1);
+});
+
+test('親のトークンが既に失効済みで更新トークンだけ未失効の不整合行も失効する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('不整合組織');
+    $member = attachOrganizationMember($organization);
+
+    $client = OAuthTestHelpers::createMcpClient(name: '不整合');
+    $clientId = (string) $client->getKey();
+    // 親は失効済み・子は未失効 (母集団を「未失効の親」に絞ると取り逃す形)
+    $parent = revocationInsertAccessToken($organization, $member, $clientId, null, revoked: true);
+    $refresh = revocationInsertRefreshToken($parent, revoked: false);
+
+    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);
+
+    expect(revocationRefreshIsRevoked($refresh))->toBeTrue();
+});
+
+test('他組織 / 他利用者の資格情報は 1 件も巻き添えにならない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('対象組織');
+    [$otherOrganization] = createOrganizationWithOwner('別組織');
+    $member = attachOrganizationMember($organization);
+    $bystander = attachOrganizationMember($organization);
+
+    // 同じ人の別組織ぶん
+    $otherOrganization->users()->attach($member);
+    $member->addRole(OrganizationRole::Member->value, $otherOrganization->laratrust_team_id);
+
+    $target = revocationCredentials($organization, $member);
+    $crossOrg = revocationCredentials($otherOrganization, $member);
+    $otherUser = revocationCredentials($organization, $bystander);
+
+    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);
+
+    expect(revocationTokenIsRevoked($target['bound']))->toBeTrue();
+    expect(revocationTokenIsRevoked($crossOrg['bound']))->toBeFalse();
+    expect($crossOrg['session']->fresh()?->revoked_at)->toBeNull();
+    expect(revocationTokenIsRevoked($otherUser['bound']))->toBeFalse();
+    expect($otherUser['session']->fresh()?->revoked_at)->toBeNull();
+});
+
+test('除名の前に発行された認可コードは失効し、その後の交換が成立しない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('認可コード組織');
+    $member = attachOrganizationMember($organization);
+
+    $pkce = OAuthTestHelpers::generatePkcePair();
+    $client = OAuthTestHelpers::createMcpClient(name: '認可コード確認');
+    $redirectUri = 'https://test.example/callback';
+
+    $this->actingAs($member);
+    $this->get(OAuthTestHelpers::buildAuthorizeUrl(
+        clientId: (string) $client->getKey(),
+        redirectUri: $redirectUri,
+        codeChallenge: $pkce['code_challenge'],
+    ));
+    $approve = $this->post('/oauth/authorize', [
+        'auth_token' => session('authToken'),
+        'organization_id' => $organization->id,
+    ]);
+    $code = OAuthTestHelpers::parseCallbackParams($approve)['code'] ?? '';
+    expect($code)->not->toBe('');
+
+    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);
+    Auth::forgetGuards();
+
+    $response = OAuthTestHelpers::exchangeTokenForm($this, [
+        'grant_type' => 'authorization_code',
+        'client_id' => (string) $client->getKey(),
+        'redirect_uri' => $redirectUri,
+        'code_verifier' => $pkce['code_verifier'],
+        'code' => $code,
+    ]);
+
+    expect($response->getStatusCode())->toBeGreaterThanOrEqual(400);
+    expect($response->json('access_token'))->toBeNull();
+});
+
+// ---------------------------------------------------------------------------
+// C. ひとまとまりであること
+// ---------------------------------------------------------------------------
+
+/*
+ * 「ひとまとまりの外から窓口を呼ぶと例外になる」ことは**このレーンでは確認できない**。
+ * Feature / Unit レーンは RefreshDatabase が全体をトランザクションで包むため、
+ * トランザクションの深さが 0 の状態を作れないからである。
+ * その検査は tests/Architecture/OrganizationAccessRevocationChokePointTest.php に置く
+ * (Architecture レーンは RefreshDatabase を使わない)。
+ */
+
+test('役割変更が例外で失敗したら失効も巻き戻る', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('巻き戻し組織');
+    $credentials = revocationCredentials($organization, $owner);
+
+    // 最後の Owner は降格できない (ロック下の検証で例外)
+    expect(fn () => app(OrganizationMembershipService::class)
+        ->changeRole($organization, $owner, OrganizationRole::Member, $owner))
+        ->toThrow(ValidationException::class);
+
+    expect($credentials['session']->fresh()?->revoked_at)->toBeNull();
+    expect(revocationTokenIsRevoked($credentials['bound']))->toBeFalse();
+    expect(revocationAuditCount())->toBe(0);
+});
+
+test('監査が書けないときは役割の変更ごと巻き戻る', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('監査失敗組織');
+    $member = attachOrganizationMember($organization);
+    $credentials = revocationCredentials($organization, $member);
+
+    $this->partialMock(SecurityEventRecorder::class, function ($mock): void {
+        $mock->shouldReceive('recordOrFail')->andThrow(new RuntimeException('監査が書けない'));
+    });
+
+    expect(fn () => app(OrganizationMembershipService::class)
+        ->changeRole($organization, $member, OrganizationRole::Admin, $owner))
+        ->toThrow(RuntimeException::class);
+
+    expect($member->fresh()?->organizationRole($organization))->toBe(OrganizationRole::Member);
+    expect($credentials['session']->fresh()?->revoked_at)->toBeNull();
+    expect(revocationTokenIsRevoked($credentials['bound']))->toBeFalse();
+});
+
+// ---------------------------------------------------------------------------
+// D. 監査
+// ---------------------------------------------------------------------------
+
+test('失効が 0 件でも監査が 1 行残る', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('0件組織');
+    $member = attachOrganizationMember($organization);
+
+    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);
+
+    expect(revocationAuditCount())->toBe(1);
+    $audit = revocationLatestAudit();
+    expect($audit?->metadata['revoked'] ?? null)->toBe([
+        'sessions' => 0,
+        'access_tokens' => 0,
+        'refresh_tokens' => 0,
+        'auth_codes' => 0,
+    ]);
+});
+
+test('監査に理由・操作した人・家族ごとの件数が入る', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('監査組織');
+    $member = attachOrganizationMember($organization);
+    revocationCredentials($organization, $member);
+
+    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);
+
+    $audit = revocationLatestAudit();
+    expect($audit)->not->toBeNull();
+    expect($audit?->user_id)->toBe($member->id);
+    expect($audit?->metadata['reason'] ?? null)->toBe(OrgAccessRevocationReason::MemberRemoved->value);
+    expect($audit?->metadata['actor_user_id'] ?? null)->toBe($owner->id);
+    expect($audit?->metadata['organization_id'] ?? null)->toBe($organization->id);
+    expect($audit?->metadata['revoked']['sessions'] ?? null)->toBe(1);
+    expect($audit?->metadata['revoked']['access_tokens'] ?? null)->toBe(2);
+    expect($audit?->metadata['revoked']['refresh_tokens'] ?? null)->toBe(1);
+    expect($audit?->metadata['revoked']['auth_codes'] ?? null)->toBe(1);
+});
+
+test('オーナー移譲の監査は譲り手と受け手で理由が分かれる', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('移譲監査組織');
+    $successor = attachOrganizationMember($organization, OrganizationRole::Admin);
+
+    app(OrganizationMembershipService::class)->transferOwnership($organization, $owner, $successor);
+
+    $reasons = SecurityAuditEvent::query()
+        ->where('event_type', SecurityEventType::OrganizationAccessRevoked->value)
+        ->orderBy('id')
+        ->get()
+        ->map(fn (SecurityAuditEvent $event): mixed => $event->metadata['reason'] ?? null)
+        ->all();
+
+    expect($reasons)->toBe([
+        OrgAccessRevocationReason::OwnershipTransferredFrom->value,
+        OrgAccessRevocationReason::OwnershipTransferredTo->value,
+    ]);
+});
+
+// ---------------------------------------------------------------------------
+// E. 実際に使えなくなること (端から端まで)
+// ---------------------------------------------------------------------------
+
+test('除名の後はその人のトークンで外部 API を叩けない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('API組織');
+    $member = attachOrganizationMember($organization);
+    $client = OAuthTestHelpers::createMcpClient(name: 'CLI クライアント');
+
+    $issued = OAuthTestHelpers::issueCliSessionTokens(
+        test: $this,
+        user: $member,
+        organization: $organization,
+        client: $client,
+    );
+
+    $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
+        ->getJson('/api/v1/me')
+        ->assertOk();
+
+    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);
+    Auth::forgetGuards();
+
+    $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
+        ->getJson('/api/v1/me')
+        ->assertUnauthorized();
+});
+
+test('除名の後は更新トークンでの再発行が拒否される', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('再発行組織');
+    $member = attachOrganizationMember($organization);
+    $client = OAuthTestHelpers::createMcpClient(name: '再発行クライアント');
+
+    $issued = OAuthTestHelpers::issueCliSessionTokens(
+        test: $this,
+        user: $member,
+        organization: $organization,
+        client: $client,
+    );
+
+    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);
+    Auth::forgetGuards();
+
+    $response = OAuthTestHelpers::exchangeTokenForm($this, [
+        'grant_type' => 'refresh_token',
+        'client_id' => (string) $client->getKey(),
+        'refresh_token' => $issued['refresh_token'],
+    ]);
+
+    expect($response->getStatusCode())->toBeGreaterThanOrEqual(400);
+    expect($response->json('access_token'))->toBeNull();
+});
+
+test('除名の後はその人のトークンで MCP を叩けない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('MCP失効組織');
+    $member = attachOrganizationMember($organization);
+
+    config()->set('mcp.allowed_origins', ['https://claude.ai']);
+    config()->set('mcp.strict_transport', true);
+
+    $client = OAuthTestHelpers::createMcpClient(name: 'MCP クライアント');
+    $tokens = OAuthTestHelpers::exchangeForTokensUsing(
+        test: $this,
+        user: $member,
+        organization: $organization,
+        client: $client,
+        pkce: OAuthTestHelpers::generatePkcePair(),
+        redirectUri: 'https://test.example/callback',
+    );
+    Auth::forgetGuards();
+
+    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);
+    Auth::forgetGuards();
+
+    $this->withHeaders([
+        'Origin' => 'https://claude.ai',
+        'Authorization' => 'Bearer '.$tokens['access_token'],
+    ])->postJson('/api/v1/mcp', [
+        'jsonrpc' => '2.0',
+        'method' => 'tools/call',
+        'params' => ['name' => 'whoami', 'arguments' => []],
+        'id' => 1,
+    ])->assertUnauthorized();
+});
+
+test('接続セッション一覧に失効済みとして並ぶ', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('一覧確認組織');
+    $member = attachOrganizationMember($organization);
+    $credentials = revocationCredentials($organization, $member);
+
+    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);
+
+    $this->actingAs($owner)
+        ->get("/organizations/{$organization->slug}/api-keys/sessions")
+        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
+            ->component('Organizations/ApiKeys/Sessions')
+            ->where('sessions.0.id', $credentials['session']->id)
+            ->whereNot('sessions.0.revokedAt', null));
+});
+
+// ---------------------------------------------------------------------------
+// F. 失効させないものの境界 (誇張しないことの固定)
+// ---------------------------------------------------------------------------
+
+test('除名された発行者の API キーでも読み取りは通る (組織の資産として振る舞う)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('鍵読み取り組織');
+    $issuer = attachOrganizationMember($organization, OrganizationRole::Admin);
+    [, $plain] = issueApiKey($organization, $issuer, ['read', 'write']);
+
+    app(OrganizationMembershipService::class)->removeMember($organization, $issuer, $owner);
+
+    $this->withHeader('Authorization', "Bearer {$plain}")
+        ->getJson('/api/v1/me')
+        ->assertOk();
+});
+
+test('除名された発行者の API キーでの書き込みは認可で拒否される', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('鍵書き込み組織');
+    $issuer = attachOrganizationMember($organization, OrganizationRole::Admin);
+    $project = Project::factory()->forOrganization($organization)->create();
+    // ★write ability を必ず持たせる。持たせないと資格不足の 403 で緑になり、
+    //   認可の再評価を通っていない実装でも通ってしまう。
+    [, $plain] = issueApiKey($organization, $issuer, ['read', 'write']);
+
+    app(OrganizationMembershipService::class)->removeMember($organization, $issuer, $owner);
+
+    $this->withHeader('Authorization', "Bearer {$plain}")
+        ->postJson("/api/v1/projects/{$project->id}/items", [
+            'name' => '失効後の作成',
+            'idempotency_key' => (string) Str::uuid(),
+        ], ['Idempotency-Key' => (string) Str::uuid()])
+        ->assertForbidden();
+});
+
+test('組織の API キーは失効の対象外 (窓口を呼んでも 1 行も変わらない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('鍵不変組織');
+    $member = attachOrganizationMember($organization);
+    [$apiKey] = issueApiKey($organization, $member, ['read']);
+
+    app(OrganizationMembershipService::class)->removeMember($organization, $member, $owner);
+
+    /** @var ApiKey|null $fresh */
+    $fresh = ApiKey::query()->find($apiKey->getKey());
+    expect($fresh)->not->toBeNull();
+    expect($fresh?->revoked_at)->toBeNull();
+});
+
+test('プロジェクト単位の役割変更では失効しない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('プロジェクト役割組織');
+    $project = Project::factory()->forOrganization($organization)->create();
+    $member = attachOrganizationMember($organization);
+    attachProjectMember($project, $member, ProjectRole::Member);
+    $credentials = revocationCredentials($organization, $member);
+
+    // プロジェクト側のロール更新は store の再実行 (syncWithoutDetaching)
+    $this->actingAs($owner)
+        ->post("/projects/{$project->id}/members", [
+            'user_id' => $member->id,
+            'role' => ProjectRole::Admin->value,
+        ])
+        ->assertSessionHas('success');
+
+    expect($project->memberRole($member))->toBe(ProjectRole::Admin);
+    expect($credentials['session']->fresh()?->revoked_at)->toBeNull();
+    expect(revocationAuditCount())->toBe(0);
+});

```

## テスト結果

- `composer test`: 5123 tests / 5121 passed / 0 failed / 2 skipped / 22008 assertions
- `composer phpstan` (level 10): No errors
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm test` (1501 passed) / `pnpm build`: すべて green
- `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` (106 passed): green
