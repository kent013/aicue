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

- `AppMcpTool::handle()` が `final` である
- `handle()` の本文で `McpAuthorizationContext::for(` と `authorizeTool(` の位置が
  `runTool(` の位置より**前**である
- **結果を捨てていないこと**: `authorizeTool(` の呼び出しが**否定**の形
  (`if (! $ctx->authorizeTool(`) で現れ、その直後の行に `throw` があること。
  呼ぶだけ呼んで戻り値を無視する形を落とす (Codex 指摘)
- 登録された全 tool class が `handle` を**再宣言していない**
  (`final` なので言語が禁じるが、基底の付け替えが起きたときに検出する)

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
- 更新 `tests/Architecture/ModelDirectFetchInvariantTest.php` (引数追加への追随)

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

- [ ] トランザクションの外から窓口を呼ぶと例外になる
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
