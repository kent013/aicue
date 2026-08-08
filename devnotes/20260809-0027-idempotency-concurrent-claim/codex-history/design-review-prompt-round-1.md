【アプリの使命 (North Star)】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
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
10. DESIGN.md準拠（UI/frontend 変更を含む場合）: `/DESIGN.md` が design token の canonical source。color / radius / typography を token 経由で参照する設計か、hex 直書きを増やさないか。token 変更時は `resources/css/tokens.css` との同期を設計に織り込んでいるか（運用契約は `docs/design-system.md`）
11. Atomic Design準拠（UI/frontend 変更を含む場合）: `resources/js/components/` の `atoms/molecules/organisms/templates` の責務分離に沿った配置か。atom は単機能・無状態、molecule は atom の組合せという階層を逆流していないか。アイコンは Lucide 前提で、SVG 直書きを新設していないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

【補足】
- 本リポジトリは `/workspace` (aicue)。**ファイル読み込みは許可**されているので、
  設計書が引用している現行コードの実在・行番号・前提は自分で確認してよい。
- 概念設計 (`devnotes/20260809-0027-idempotency-concurrent-claim/conceptual-design.md`) は
  別セッションで APPROVED 済み。本レビューは詳細設計を対象とする。
- 「家系標準」は複数リポジトリ共有の機能台帳の裁定 AG-032 / AG-122 を指し、
  台帳本体は aicue から参照できない (設計書中の要約が唯一の入力)。
- UI/frontend の変更は本設計に**含まれない** (観点 10 / 11 は該当なしと判断してよい)。

---

## 詳細設計書

# 詳細設計: idempotency-concurrent-claim (冪等キーの並行 409 と配線漏れ検査)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- 新モデルを追加する設計では **対応する Factory の作成も施策に含める**（本設計は新モデルを追加しない）
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- `declare(strict_types=1)` + 日本語コメント。Controller は薄く、transaction は Service 内
- 保護キーは forceFill / relation / named creation method で明示代入

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md) （Codex 概念設計レビュー Round 4 で APPROVED）
- 一次入力: [recon-brief.md](./recon-brief.md)
- 実コード確認基準: main = `c71061e`

---

## 契約変更サマリ (実装前に必ず読む)

本設計は **REST API v1 の公開契約を破壊的に変更する**。オーナー承認済み。周知はオーナーが行う。

| 状況 | 現行 | 変更後 |
|------|------|--------|
| 同一キー + 同一 body、初回 2xx | 保存応答を再生 | 同じ (+ `Idempotent-Replayed: true`) |
| 同一キー + 異なる body | 409 `idempotency_conflict` | 同じ |
| 同一キー + 同一 body、初回 4xx/5xx | **再実行される** | **409 `idempotency_indeterminate`** |
| 同一キー + 異なる body、初回 4xx/5xx | **再実行される** | **409 `idempotency_conflict`** |
| 同一キーの並行 2 本 | **両方 controller を実行** | 先着のみ実行 / 後着は **409 `idempotency_in_progress`** |

**契約変更が観測される面 (全列挙)**: `api.v1.projects.items.store` / `.update` / `.destroy` の 3 route。
`DELETE /api/v1/me/session` は配線しない (施策 E で目録免除)。MCP write tool は 0 本なので観測面なし。

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| A | 保持期間 SoT と再生ヘッダ定数 | `config/idempotency.php`(新) / `app/Support/Idempotency/IdempotencyRetention.php`(新) / `app/Support/Idempotency/IdempotencyHeaders.php`(新) / `app/Http/Middleware/IdempotentRequest.php` / `app/Services/Mcp/McpIdempotencyService.php` | 高 |
| B | 状態列の追加 (migration / Enum / Model / Factory) | `database/migrations/2026_08_09_000100_add_state_to_idempotency_keys_table.php`(新) / `app/Enums/Idempotency/IdempotencyState.php`(新) / `app/Models/IdempotencyKey.php` / `database/factories/IdempotencyKeyFactory.php` | 高 |
| C | IdempotentRequest の claim → 分岐 → finalize 化 | `app/Http/Middleware/IdempotentRequest.php` / `app/Enums/ApiErrorCode.php` / `app/Enums/Idempotency/IdempotencyClaimStatus.php`(新) / `app/Support/Idempotency/IdempotencyClaimOutcome.php`(新) / `app/Exceptions/Idempotency/IdempotencyFinalizationFailure.php`(新) | 高 |
| D | 期限切れ鍵の物理削除 (prune + schedule) | `app/Console/Commands/Operations/PruneIdempotencyKeysCommand.php`(新) / `routes/console.php` | 中 |
| E | 配線目録 gate + 免除 enum + 前提テスト | `tests/Architecture/IdempotentRouteCoverageTest.php`(新) / `app/Enums/Security/IdempotencyWiringExemption.php`(新) / `tests/Feature/Security/IdempotencyExemptionPremiseTest.php`(新) | 高 |
| F | 契約 parity gate + 契約文書 | `tests/Architecture/IdempotencyContractParityTest.php`(新) / `docs/api-idempotency.md`(新) | 中 |
| G | MCP 中央強制 gate + write tool 0 本 trip-wire | `tests/Architecture/McpWriteToolIdempotencyEnforcementTest.php`(新) | 中 |
| H | 既存テストの契約追随 + 並行 claim テスト | `tests/Feature/Api/IdempotencyTest.php` / `tests/Feature/Api/IdempotencyConcurrentClaimTest.php`(新) / `tests/Feature/Api/V1/ItemAuthorizationTest.php` / `tests/Feature/Api/OAuthDualGuardTest.php` / `tests/Feature/Mcp/McpIdempotencyServiceTest.php` / `tests/Unit/Support/Idempotency/IdempotencyClaimOutcomeTest.php`(新) / `tests/Feature/Console/PruneIdempotencyKeysCommandTest.php`(新) | 高 |
| I | 文書追随 | `AGENTS.md` / `docs/architecture.md` / `docs/app-integration-guide.md` / `docs/factories.md` / `docs/TODO.md` (T109 の注記) | 中 |

---

## 施策 A: 保持期間 SoT と再生ヘッダ定数

### 変更箇所

- 新規: `config/idempotency.php`
- 新規: `app/Support/Idempotency/IdempotencyRetention.php`
- 新規: `app/Support/Idempotency/IdempotencyHeaders.php`
- `app/Http/Middleware/IdempotentRequest.php` (L38-39 の `TTL_HOURS` 削除、L147 の参照差し替え)
- `app/Services/Mcp/McpIdempotencyService.php` (L27 の `TTL_HOURS` 削除、L104-105 の参照差し替え)

### 波及変更

- TypeScript 型定義: なし (サーバ内部の定数。フロントに露出しない)
- API Resource/DTO: なし
- テストファイル: `tests/Feature/Mcp/McpIdempotencyServiceTest.php` (config 由来であることの検証を追加)、
  施策 F の parity gate が config ↔ 文書 ↔ 定数を固定する
- 設定: `.env` / `.env.example` は**触らない** (env 不使用が要件)。
  `EnvExampleInvariantTest` は `.env.example` の内容を見る gate なので影響なし

### 現行コード

```php
// app/Http/Middleware/IdempotentRequest.php L38-39
/** 保存レスポンスの TTL (時間)。超過エントリは再送時に削除して作り直す */
public const TTL_HOURS = 24;

// 同 L147
'expires_at' => now()->addHours(self::TTL_HOURS),

// app/Services/Mcp/McpIdempotencyService.php L27
public const TTL_HOURS = 24;

// 同 L104-105
createdAt: CarbonImmutable::now(),
expiresAt: CarbonImmutable::now()->addHours(self::TTL_HOURS),
```

### 変更後コード

```php
// config/idempotency.php (新規)
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Idempotency (冪等キー) の契約値
|--------------------------------------------------------------------------
|
| REST API v1 の `Idempotency-Key` と MCP 書き込み tool の冪等キーが共有する
| **唯一の正本**。**env は使わない** — 保持期間は API の公開契約であり、
| 環境ごとに変えてよい運用値ではない (環境差があると「24h 以内なら再送できる」が
| 環境依存の嘘になる)。
|
| この値と docs/api-idempotency.md の記載の一致は
| tests/Architecture/IdempotencyContractParityTest が deny-by-default で強制する。
|
| ⚠ この値を**利用者向けの外部公開文書**に載せるかはオーナー判断であり未決。
|   ここで固定しているのは「実装と社内契約文書が同じ数字を指す」ことだけである。
*/

return [

    /*
    | 冪等キーの保持期間 (時間)。この時間を過ぎた行は
    | idempotency:prune が物理削除し、同じキーを再び使えるようになる。
    */
    'retention_hours' => 24,

];
```

```php
// app/Support/Idempotency/IdempotencyRetention.php (新規)
<?php

declare(strict_types=1);

namespace App\Support\Idempotency;

use Carbon\CarbonImmutable;
use Webmozart\Assert\Assert;

/**
 * 冪等キーの保持期間 (config/idempotency.php) への型付き入口。
 *
 * REST (IdempotentRequest) と MCP (McpIdempotencyService) と prune コマンドが
 * **同じ 1 箇所**からしか保持期間を読まないようにするための Support。
 * クラス定数での二重管理へ戻さないこと (parity gate が定数の不在を固定する)。
 */
final class IdempotencyRetention
{
    /** 保持期間 (時間)。config の型崩れは Assert で fail-fast する */
    public static function hours(): int
    {
        $hours = config('idempotency.retention_hours');
        Assert::integer($hours, 'config(idempotency.retention_hours) must be an int.');
        Assert::greaterThan($hours, 0, 'config(idempotency.retention_hours) must be positive.');

        return $hours;
    }

    /** 基準時刻からの失効時刻 (時単位のため *NoOverflow の対象外) */
    public static function expiresAt(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->addHours(self::hours());
    }

    /** prune の cutoff (この時刻以前に失効した行が削除対象) */
    public static function cutoff(?CarbonImmutable $now = null): CarbonImmutable
    {
        return $now ?? CarbonImmutable::now();
    }
}
```

```php
// app/Support/Idempotency/IdempotencyHeaders.php (新規)
<?php

declare(strict_types=1);

namespace App\Support\Idempotency;

/**
 * 冪等応答のヘッダ名の唯一の正本。
 *
 * `Idempotent-Replayed` は **外部標準 (IETF の Idempotency-Key draft) には無い拡張**であり、
 * 家系横断の裁定 AG-122 で名前を統一している。**再生応答にのみ**付与する
 * (初回応答・409・素通しには付けない = クライアントが「これは再生か」を判定できる)。
 * 名前と付与条件の契約は docs/api-idempotency.md、機械固定は
 * tests/Architecture/IdempotencyContractParityTest。
 */
final class IdempotencyHeaders
{
    /** 保存済み応答を再生したときにだけ付与する (値は 'true' 固定) */
    public const REPLAYED = 'Idempotent-Replayed';

    /** REPLAYED の値 (真偽の表現をここに固定し、呼び出し側で文字列を組まない) */
    public const REPLAYED_VALUE = 'true';
}
```

`IdempotentRequest` / `McpIdempotencyService` からは `TTL_HOURS` を**削除**し、
`IdempotencyRetention::expiresAt()` を呼ぶ形に置き換える (後方互換の別名を残さない。思考原則 3)。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`int` / `CarbonImmutable`)
- [x] null 安全 (`config()` の `mixed` を `Assert::integer` で narrow してから返す)
- [x] DTO を返している (スカラーを返す Support なので該当せず。配列は返さない)
- [x] Generics の型パラメータ: 該当なし

### テスト計画

- [ ] 新規 `tests/Unit/Support/Idempotency/IdempotencyRetentionTest.php`
  - `hours() は config の値を返す` — `config(['idempotency.retention_hours' => 3])` で 3
  - `hours() は非 int の config で失敗する` — `config(['idempotency.retention_hours' => '24'])` → `InvalidArgumentException`
  - `hours() は 0 以下の config で失敗する`
  - `expiresAt() は基準時刻 + hours を返す`
- [ ] 既存 `tests/Feature/Mcp/McpIdempotencyServiceTest.php` に追加
  - `store の expires_at は config の保持期間から決まる` — `config(['idempotency.retention_hours' => 1])` を設定して 1 時間後になることを検証
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認 (使わない)

### リスク

- config キー名を将来変えると parity gate と Support の 2 箇所を直す必要がある (意図的な摩擦)
- `config()` を毎回読むため、request あたり数回の config 参照が増える (無視できるコスト)

---

## 施策 B: 状態列の追加 (migration / Enum / Model / Factory)

### 変更箇所

- 新規: `database/migrations/2026_08_09_000100_add_state_to_idempotency_keys_table.php`
- 新規: `app/Enums/Idempotency/IdempotencyState.php`
- `app/Models/IdempotencyKey.php` (PHPDoc の `$response_status` を `?int` に、`$state` を追加、casts に enum)
- `database/factories/IdempotencyKeyFactory.php` (`state` 既定 + `processing()` / `indeterminate()` state)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし (`IdempotencyKey` は API 応答に露出しない)
- テストファイル: `tests/Feature/Api/IdempotencyTest.php` の Factory 検証テスト (L149-155)、
  施策 H の各テスト、`docs/factories.md` の state 一覧 (施策 I)
- **既存 gate**: `MassAssignmentSafetyTest` は `$fillable` に保護キーが無いことを見る。
  `state` は所有権キーではないが、**claim は query builder 経由で書く**ため `$fillable` には**追加しない**
  (Eloquent 経由で state を mass assign する経路を作らない = 状態遷移を条件付き UPDATE に一本化する)

### 現行コード

```php
// database/migrations/2026_06_11_100100_create_idempotency_keys_table.php L23-39 (抜粋)
$table->string('request_hash');
$table->unsignedSmallInteger('response_status');   // ← NOT NULL
$table->json('response_body')->nullable();
$table->timestamp('expires_at')->index();
$table->timestamp('created_at')->nullable();
$table->unique(['api_key_id', 'route_name', 'key']);
$table->unique(['user_id', 'route_name', 'key']);
```

```php
// app/Models/IdempotencyKey.php L28-30, L51-59 (抜粋)
 * @property int $response_status
 * @property array<array-key, mixed>|null $response_body
...
protected function casts(): array
{
    return [
        'response_status' => 'integer',
        'response_body' => 'array',
        'expires_at' => 'datetime',
    ];
}
```

### 変更後コード

```php
// app/Enums/Idempotency/IdempotencyState.php (新規)
<?php

declare(strict_types=1);

namespace App\Enums\Idempotency;

/**
 * 冪等キー行の状態 (REST `idempotency_keys`)。
 *
 * **決着は completed と indeterminate の 2 つだけで、release (再実行を許す) 経路は無い**
 * (家系裁定 AG-032 の標準形)。processing から戻る道は無く、唯一の解放は
 * 保持期間超過による物理削除 (idempotency:prune) である。
 *
 * - Processing:    claim 済み・本処理実行中。同一キーの後着は 409 idempotency_in_progress
 * - Completed:     2xx JSON を得た。保存応答を再生する (Idempotent-Replayed: true)
 * - Indeterminate: それ以外で終わった (非 2xx / 非 JSON / 例外が抜けた)。
 *                  副作用の有無を middleware から断定できないため再実行せず
 *                  409 idempotency_indeterminate を返す (クライアントは新しいキーを使う)
 */
enum IdempotencyState: string
{
    case Processing = 'processing';
    case Completed = 'completed';
    case Indeterminate = 'indeterminate';
}
```

```php
// database/migrations/2026_08_09_000100_add_state_to_idempotency_keys_table.php (新規)
<?php

declare(strict_types=1);

use App\Enums\Idempotency\IdempotencyState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 冪等キーに状態列を足し、実行**前** claim を可能にする。
 *
 * - `state`: processing / completed / indeterminate (App\Enums\Idempotency\IdempotencyState)
 * - `response_status` を nullable 化する (claim 時点ではまだ応答が無いため)
 *
 * **既存行は削除せず `completed` へ backfill する**。現行実装は 2xx の JsonResponse しか
 * 保存しない (IdempotentRequest::handle の `$response->isSuccessful()` 分岐) ため、
 * 既存行の決着は構造上すべて「成功」で既知である。ここを indeterminate に倒すと
 * デプロイ直後の正当な再送 (成功の再生) が最大 24h ぶん 409 に化ける。
 * 既存行を **削除**しないのは、デプロイを跨いだ再送が二重実行になるのを防ぐため。
 *
 * 既存の unique 2 本 (api_key_id / user_id の NULL distinct 前提) には触らない。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->string('state', 24)->nullable()->after('request_hash');
            $table->unsignedSmallInteger('response_status')->nullable()->change();
        });

        // 既存行の backfill (決着は既知 = completed)
        DB::table('idempotency_keys')
            ->whereNull('state')
            ->update(['state' => IdempotencyState::Completed->value]);

        // backfill 後に NOT NULL 化する。**DB default は付けない**
        // (default があると「state を書き忘れた INSERT」が黙って completed になる)
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->string('state', 24)->nullable(false)->change();
        });

        // 期限切れ行の state 別 prune を index で支える
        // (prune は `where state = ? and expires_at <= ?` で回す)
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->index(['state', 'expires_at'], 'idempotency_keys_state_expires_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->dropIndex('idempotency_keys_state_expires_at_index');
            $table->dropColumn('state');
        });

        // down では response_status を NOT NULL に戻さない:
        // 戻す時点で claim 行 (response_status = null) が残っていると ALTER が失敗する。
        // ロールバックの安全性を優先し、nullable のままにする (前方互換)。
    }
};
```

```php
// app/Models/IdempotencyKey.php (差分)
 * @property string $request_hash
 * @property IdempotencyState $state
 * @property int|null $response_status
 * @property array<array-key, mixed>|null $response_body

    /** @var list<string> */
    protected $fillable = [
        'route_name',
        'key',
        'request_hash',
        'response_status',
        'response_body',
        'expires_at',
    ];  // ← state は **追加しない** (状態遷移は条件付き UPDATE 経由のみ)

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'state' => IdempotencyState::class,
            'response_status' => 'integer',
            'response_body' => 'array',
            'expires_at' => 'datetime',
        ];
    }
```

```php
// database/factories/IdempotencyKeyFactory.php (差分)
    public function definition(): array
    {
        return [
            'api_key_id' => ApiKey::factory(),
            'route_name' => 'api.v1.projects.items.store',
            'key' => (string) Str::uuid(),
            'request_hash' => hash('sha256', Str::random(32)),
            'state' => IdempotencyState::Completed,
            'response_status' => 201,
            'response_body' => ['data' => ['id' => 1]],
            'expires_at' => Carbon::now()->addDay(),
        ];
    }

    /** claim 済み・本処理実行中 (応答未確定) */
    public function processing(): static
    {
        return $this->state(fn () => [
            'state' => IdempotencyState::Processing,
            'response_status' => null,
            'response_body' => null,
        ]);
    }

    /** 決着が不明 (非 2xx / 例外) */
    public function indeterminate(): static
    {
        return $this->state(fn () => [
            'state' => IdempotencyState::Indeterminate,
            'response_status' => null,
            'response_body' => null,
        ]);
    }
```

> Factory は `$fillable` を経由しない (`Model::unguarded` 相当の Factory 生成) ため、
> `state` を `$fillable` に入れなくてもテストデータを作れる。実コード側だけが
> 条件付き UPDATE / query builder insert に制限される。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている
- [x] null 安全 (`?int $response_status` を PHPDoc に反映。読む側は施策 C で `Assert::notNull`)
- [x] DTO を返している (Model / Enum のみ。配列返却なし)
- [x] Generics: `HasFactory<IdempotencyKeyFactory>` は既存のまま

### テスト計画

- [ ] 既存 `tests/Feature/Api/IdempotencyTest.php` L149-155 の Factory テストを拡張
  - `IdempotencyKeyFactory: expired 状態は isExpired が真` (既存維持)
  - 新規 `IdempotencyKeyFactory: processing / indeterminate は応答列が null` — 3 state の生成を確認
- [ ] 新規 `tests/Feature/Database/IdempotencyStateMigrationTest.php`
  - `state 列は NOT NULL で DB default を持たない` — `Schema::getColumns('idempotency_keys')` で
    `nullable === false` かつ `default === null` を検証
    (**「backfill 用の default が残っている」= 書き忘れが黙って completed になる**回帰を塞ぐ)
  - `response_status は nullable` — 同上で `nullable === true`
  - `既存の unique 2 本が残っている` — `Schema::getIndexes()` に 2 本の unique が存在すること
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認 (使わない)

### リスク

- `->change()` は列定義を書き直すため、`response_status` の型 (`unsignedSmallInteger`) を
  取り違えると桁が変わる。migration テストで nullable と unique の残存を検証して塞ぐ
- backfill は既存行数に比例する。`idempotency_keys` は 24h ローリングで最大でも
  「24h 間の write API 呼び出し数」であり、現状の利用規模では 1 クエリで完了する
- **本番 DB へのロック**: `ALTER TABLE ... ADD COLUMN` (nullable, default なし) と
  `ALTER COLUMN DROP NOT NULL` は pgsql ではテーブル書き換えを伴わない。
  `SET NOT NULL` は全行スキャンを伴うが、上記のとおり行数が小さいため許容

---

## 施策 C: IdempotentRequest の claim → 分岐 → finalize 化

### 変更箇所

- `app/Http/Middleware/IdempotentRequest.php` (全面書き換え。L21-164)
- `app/Enums/ApiErrorCode.php` (case 2 つ + `defaultStatus` / `defaultMessage` の match に追加)
- 新規: `app/Enums/Idempotency/IdempotencyClaimStatus.php`
- 新規: `app/Support/Idempotency/IdempotencyClaimOutcome.php`
- 新規: `app/Exceptions/Idempotency/IdempotencyFinalizationFailure.php`

### 波及変更

- TypeScript 型定義: なし (REST API v1 はフロントから呼ばない。Inertia 経路とは別)
- API Resource/DTO: `ApiErrorResource` / `ApiError` は**変更不要** (既存の `fromCode()` 経路をそのまま使う)
- テストファイル: `tests/Feature/Api/IdempotencyTest.php` (契約変更)、
  `tests/Feature/Api/V1/ItemAuthorizationTest.php` L318-340 (ケース 16)、
  `tests/Feature/Api/OAuthDualGuardTest.php` L124-145、新規 `IdempotencyConcurrentClaimTest`
- **middleware 順序 gate**: `TenantBoundaryOrderingTest` (L103 の短絡 inventory と L455-475 の期待列) /
  `ProjectRouteCurrentOrgGuardTest` (L119-140) は **無改修で通る**。
  `IdempotentRequest` は引き続き「短絡する middleware」であり、priority list 上の位置も変えない

### 現行コード

```php
// app/Http/Middleware/IdempotentRequest.php L81-99 (中核)
        if ($existing !== null) {
            if ($existing->request_hash !== $requestHash) {
                return ApiErrorResource::make(ApiError::fromCode(ApiErrorCode::IdempotencyConflict))
                    ->response()->setStatusCode(409);
            }

            return $this->replayResponse($existing);
        }

        $response = $next($request);
        Assert::isInstanceOf($response, Response::class);

        // 成功 (2xx) の JSON レスポンスのみ保存する (失敗は保存しない = 再送で再実行できる)
        if ($response instanceof JsonResponse && $response->isSuccessful()) {
            $this->storeResponse($actor, $routeName, $key, $requestHash, $response);
        }

        return $response;

// 同 L156-163 (並行時の握り潰し = 本設計が消す穴)
        try {
            $row->save();
        } catch (QueryException $e) {
            // 同時リクエストの unique 衝突は best-effort で無視する
            report($e);
        }
```

### 変更後コード

```php
// app/Enums/Idempotency/IdempotencyClaimStatus.php (新規)
<?php

declare(strict_types=1);

namespace App\Enums\Idempotency;

/**
 * claim 試行の判定結果 (middleware の分岐は本 enum の match 1 段だけで完結させる)。
 *
 * Claimed 以外はすべて「本処理を実行しない」= 二重実行が起きないことが型で読める。
 */
enum IdempotencyClaimStatus
{
    /** 自分が claim を取得した。本処理を実行して finalize する */
    case Claimed;
    /** 完了済みの保存応答がある。再生する */
    case Replay;
    /** 同一キーで別 body。409 idempotency_conflict */
    case Conflict;
    /** 別リクエストが処理中。409 idempotency_in_progress */
    case InProgress;
    /** 決着不明で終わっている。409 idempotency_indeterminate */
    case Indeterminate;
}
```

```php
// app/Support/Idempotency/IdempotencyClaimOutcome.php (新規)
<?php

declare(strict_types=1);

namespace App\Support\Idempotency;

use App\Enums\Idempotency\IdempotencyClaimStatus;
use App\Models\IdempotencyKey;
use Webmozart\Assert\Assert;

/**
 * claim 試行の結果 (status と対象行の組合せ不変条件を型で閉じる)。
 *
 * `__construct` は private で、named constructor 経由でしか作れない。
 * これにより「Replay なのに row が無い」「Claimed なのに row を持っている」といった
 * 無効な組合せを**構築できなくする** (呼び出し側で null 判定を書かないための境界)。
 */
final class IdempotencyClaimOutcome
{
    private function __construct(
        public readonly IdempotencyClaimStatus $status,
        private readonly ?IdempotencyKey $row,
    ) {}

    /** 自分が claim を取得した (行は自分が書いたので保持しない) */
    public static function claimed(): self
    {
        return new self(IdempotencyClaimStatus::Claimed, null);
    }

    public static function replay(IdempotencyKey $row): self
    {
        return new self(IdempotencyClaimStatus::Replay, $row);
    }

    public static function conflict(IdempotencyKey $row): self
    {
        return new self(IdempotencyClaimStatus::Conflict, $row);
    }

    public static function inProgress(IdempotencyKey $row): self
    {
        return new self(IdempotencyClaimStatus::InProgress, $row);
    }

    public static function indeterminate(IdempotencyKey $row): self
    {
        return new self(IdempotencyClaimStatus::Indeterminate, $row);
    }

    /** row を持つ status からのみ呼ぶ (Claimed で呼ぶのは配線ミス) */
    public function rowOrFail(): IdempotencyKey
    {
        Assert::notNull(
            $this->row,
            'IdempotencyClaimOutcome::rowOrFail() called on a status that carries no row.',
        );

        return $this->row;
    }
}
```

```php
// app/Exceptions/Idempotency/IdempotencyFinalizationFailure.php (新規)
<?php

declare(strict_types=1);

namespace App\Exceptions\Idempotency;

use RuntimeException;

/**
 * claim 行を確定できなかったことの**観測専用**例外 (throw せず report() にだけ渡す)。
 *
 * ⚠ **元例外を previous に連結しない**。連結すると外部生成の可変文字列 (例外 message) が
 * ログに載り、「載せてよい 5 項目だけ」という契約が壊れる (AGENTS.md の
 * 「例外 message はログに載せない」と同型の判断)。載せるのは
 * route 名 / actor 種別 / 期待した state / affected rows / 例外クラス名 の 5 つだけ。
 * **Idempotency-Key の値・request body・保存応答 body は載せない**。
 */
final class IdempotencyFinalizationFailure extends RuntimeException
{
    public static function make(
        string $routeName,
        string $actorKind,
        string $expectedState,
        int $affectedRows,
        ?string $causeClass = null,
    ): self {
        return new self(sprintf(
            'Idempotency finalization failed. route=%s actor_kind=%s expected_state=%s affected_rows=%d cause=%s',
            $routeName,
            $actorKind,
            $expectedState,
            $affectedRows,
            $causeClass ?? 'none',
        ));
    }
}
```

```php
// app/Enums/ApiErrorCode.php (差分)
    /** 同一 Idempotency-Key + 異なる request body の再送 (409) */
    case IdempotencyConflict = 'idempotency_conflict';
    /** 同一 Idempotency-Key の別要求が処理中 (409)。少し待って再送するか新しいキーを使う */
    case IdempotencyInProgress = 'idempotency_in_progress';
    /** 同一 Idempotency-Key の先行要求が成功として記録されていない (409)。新しいキーを使う */
    case IdempotencyIndeterminate = 'idempotency_indeterminate';

    // defaultStatus()
            self::IdempotencyConflict,
            self::IdempotencyInProgress,
            self::IdempotencyIndeterminate => 409,

    // defaultMessage()
            self::IdempotencyInProgress => 'A request with this Idempotency-Key is still being processed.',
            self::IdempotencyIndeterminate => 'The prior request with this Idempotency-Key did not complete successfully. Use a new Idempotency-Key.',
```

`fromHttpStatus()` は**据え置く** (409 → `IdempotencyConflict`)。これは
「HTTP status しか手掛かりが無いときの既定名」であり、middleware は常に明示コードを構築するため
新コードが暗黙に別名化されることはない。この非対称は enum の docblock に明記する。

```php
// app/Http/Middleware/IdempotentRequest.php (書き換え後の中核。docblock は全面改訂)
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || trim($key) === '') {
            $response = $next($request);
            Assert::isInstanceOf($response, Response::class);

            return $response;
        }
        $key = trim($key);

        $actor = $request->attributes->get(ResolveApiActor::ATTRIBUTE_KEY);
        if (! $actor instanceof ApiActorContext) {
            // 配線ミス (resolve.api-actor middleware が前段に無い)。fail-closed で 500
            report(new \LogicException(
                'IdempotentRequest middleware reached without ApiActorContext attribute. '
                .'Ensure resolve.api-actor middleware runs first.',
            ));

            return ApiErrorResource::make(ApiError::fromCode(ApiErrorCode::InternalServerError))
                ->response()->setStatusCode(500);
        }

        $routeName = $request->route()?->getName() ?? $request->path();
        $requestHash = $this->hashRequest($request);

        $outcome = $this->claim($actor, $routeName, $key, $requestHash);

        return match ($outcome->status) {
            IdempotencyClaimStatus::Claimed => $this->runAndFinalize($request, $next, $actor, $routeName, $key),
            IdempotencyClaimStatus::Replay => $this->replayResponse($outcome->rowOrFail()),
            IdempotencyClaimStatus::Conflict => $this->errorResponse(ApiErrorCode::IdempotencyConflict),
            IdempotencyClaimStatus::InProgress => $this->errorResponse(ApiErrorCode::IdempotencyInProgress),
            IdempotencyClaimStatus::Indeterminate => $this->errorResponse(ApiErrorCode::IdempotencyIndeterminate),
        };
    }

    /**
     * 実行**前**の claim。unique 制約が唯一の調停者で、cache ロック等の補助機構は使わない。
     *
     * 期限切れ行との競合があるため最大 2 回試行する。2 回とも決着しない場合は
     * **fail-closed** (本処理を実行せず 409 in_progress) にする。
     */
    private function claim(
        ApiActorContext $actor,
        string $routeName,
        string $key,
        string $requestHash,
    ): IdempotencyClaimOutcome {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $now = CarbonImmutable::now();

            // insertOrIgnore: pgsql では `insert ... on conflict do nothing`。
            // 例外を投げないため、RefreshDatabase のトランザクションを巻き込まない。
            $inserted = IdempotencyKey::query()->insertOrIgnore([
                ...$this->ownershipColumns($actor),
                'route_name' => $routeName,
                'key' => $key,
                'request_hash' => $requestHash,
                'state' => IdempotencyState::Processing->value,
                'response_status' => null,
                'response_body' => null,
                'expires_at' => IdempotencyRetention::expiresAt($now),
                // query builder insert は timestamps を自動付与しないので明示する
                'created_at' => $now,
            ]);

            if ($inserted === 1) {
                return IdempotencyClaimOutcome::claimed();
            }

            $existing = $this->rowQuery($actor, $routeName, $key)->first();
            if ($existing === null) {
                continue; // 別リクエストが期限切れ行を消した直後。もう 1 回だけ試す
            }

            if ($existing->isExpired($now)) {
                // 期限切れ行の削除は **同一スコープ + expires_at 条件付き**で行う
                // (主キー同一性クエリを書かない = ModelDirectFetchInvariantTest の母集団に入らない。
                //  同時に、削除と削除の間に作られた新しい行を巻き込まない)
                $this->rowQuery($actor, $routeName, $key)
                    ->where('expires_at', '<=', $now)
                    ->delete();

                continue;
            }

            if ($existing->request_hash !== $requestHash) {
                return IdempotencyClaimOutcome::conflict($existing);
            }

            return match ($existing->state) {
                IdempotencyState::Processing => IdempotencyClaimOutcome::inProgress($existing),
                IdempotencyState::Completed => IdempotencyClaimOutcome::replay($existing),
                IdempotencyState::Indeterminate => IdempotencyClaimOutcome::indeterminate($existing),
            };
        }

        // 2 回とも決着しなかった = 期限切れ削除と再 claim が競り続けている。
        // ここで本処理を走らせると二重実行になりうるので実行しない (fail-closed)。
        return IdempotencyClaimOutcome::inProgress(new IdempotencyKey);
    }

    /**
     * 本処理を実行し、結果を確定する。
     *
     * - 2xx JsonResponse → completed (応答を保存)
     * - それ以外 / 例外 → indeterminate (release 経路は持たない)
     */
    private function runAndFinalize(
        Request $request,
        Closure $next,
        ApiActorContext $actor,
        string $routeName,
        string $key,
    ): Response {
        try {
            $response = $next($request);
        } catch (Throwable $e) {
            // 例外が middleware まで抜けた = 決着不明。indeterminate に倒してから再送出する
            $this->finalize($actor, $routeName, $key, IdempotencyState::Indeterminate, null, $e::class);

            throw $e;
        }

        Assert::isInstanceOf($response, Response::class);

        if ($response instanceof JsonResponse && $response->isSuccessful()) {
            $this->finalize($actor, $routeName, $key, IdempotencyState::Completed, $response);
        } else {
            $this->finalize($actor, $routeName, $key, IdempotencyState::Indeterminate, null);
        }

        return $response;
    }

    /**
     * claim 行の確定 (state = processing の条件付き UPDATE)。
     *
     * **失敗しても応答は壊さない**。副作用は既に確定しており、ここで 500 に化けさせると
     * クライアントに「失敗した」と誤認させ、より悪い再送を誘発する。
     * 代わりに観測専用例外を report() する (載せる情報は 5 項目のみ)。
     */
    private function finalize(
        ApiActorContext $actor,
        string $routeName,
        string $key,
        IdempotencyState $state,
        ?JsonResponse $response = null,
        ?string $causeClass = null,
    ): void {
        $payload = ['state' => $state->value];
        if ($response instanceof JsonResponse) {
            $payload['response_status'] = $response->getStatusCode();
            $payload['response_body'] = $this->decodeBody($response);
        }

        try {
            $affected = $this->rowQuery($actor, $routeName, $key)
                ->where('state', IdempotencyState::Processing->value)
                ->update($payload);
        } catch (Throwable $e) {
            report(IdempotencyFinalizationFailure::make(
                routeName: $routeName,
                actorKind: $this->actorKind($actor),
                expectedState: $state->value,
                affectedRows: -1,
                causeClass: $e::class,
            ));

            return;
        }

        if ($affected !== 1) {
            report(IdempotencyFinalizationFailure::make(
                routeName: $routeName,
                actorKind: $this->actorKind($actor),
                expectedState: $state->value,
                affectedRows: $affected,
                causeClass: $causeClass,
            ));
        }
    }

    /** 保存応答の再生 (Idempotent-Replayed は **ここでだけ** 付ける) */
    private function replayResponse(IdempotencyKey $existing): JsonResponse
    {
        $status = $existing->response_status;
        Assert::notNull($status, 'A completed idempotency row must carry a response status.');
        // Assert 後は narrow 済みローカル変数だけを使う (model property を読み直さない)。
        // response_body は null が正当な保存値 (2xx だが JSON 本体が配列でなかった場合)。
        $body = $existing->response_body;

        return (new JsonResponse($body, $status))
            ->header('Content-Type', 'application/json')
            ->header(IdempotencyHeaders::REPLAYED, IdempotencyHeaders::REPLAYED_VALUE);
    }

    private function errorResponse(ApiErrorCode $code): JsonResponse
    {
        return ApiErrorResource::make(ApiError::fromCode($code))
            ->response()->setStatusCode($code->defaultStatus());
    }

    /**
     * 所有権列 (api_key_id / user_id) を **1 箇所だけ**で組み立てる。
     * どちらか一方だけが非 NULL になることは、この method と Feature テストが担保する
     * (DB の CHECK 制約は持たない = 保証主体を誇張しない)。
     *
     * @return array{api_key_id: int|null, user_id: int|null}
     */
    private function ownershipColumns(ApiActorContext $actor): array
    {
        return $actor->apiKey instanceof ApiKey
            ? ['api_key_id' => $actor->apiKey->id, 'user_id' => null]
            : ['api_key_id' => null, 'user_id' => $actor->user->id];
    }

    /** actor スコープ + route + key の行 query (主キー同一性クエリは使わない) */
    private function rowQuery(ApiActorContext $actor, string $routeName, string $key): Builder
    {
        return $this->scopedQuery($actor)->where('route_name', $routeName)->where('key', $key);
    }

    private function actorKind(ApiActorContext $actor): string
    {
        return $actor->apiKey instanceof ApiKey ? 'api_key' : 'user';
    }
```

`scopedQuery()` / `hashRequest()` は現行のまま維持する。`storeResponse()` と
`QueryException` の握り潰しは**削除**する (旧実装を残さない。思考原則 3)。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`Response` / `IdempotencyClaimOutcome` / `void` / `JsonResponse`)
- [x] null 安全 (`Assert::notNull($status)` で narrow。`rowOrFail()` が Outcome 側の null を閉じる)
- [x] DTO を返している (`IdempotencyClaimOutcome`。エラー応答は `ApiErrorResource` 経由 = 禁止事項 4 遵守)
- [x] Generics の型パラメータ (`Builder<IdempotencyKey>` を `rowQuery` / `scopedQuery` の戻り値に明示)
- [x] `$payload` は `array<string, mixed>` として PHPDoc を付ける (`update()` の引数型)
- [x] `match` は全 case 網羅 (`default` を書かない = enum に case を足したら fail する)

### テスト計画

施策 H に集約 (`IdempotencyConcurrentClaimTest` / `IdempotencyTest` / `ItemAuthorizationTest` /
`OAuthDualGuardTest` / `IdempotencyClaimOutcomeTest`)。

### リスク

| リスク | 対処 |
|--------|------|
| claim が commit されず後着から見えない | middleware を包む外側 transaction が無いことを確認済み (`DB::transaction` を張る middleware は web 専用の `EnsureLoginMethodRemains` のみ)。`RefreshDatabase` 下では同一接続で可視 |
| `insertOrIgnore` が別の unique 違反も飲み込む | 本テーブルの unique は対象の 2 本だけ。FK 違反は例外として上がる (握り潰さない) |
| 期限切れ削除ループが決着しない | 試行を 2 回に固定し、決着しなければ **本処理を実行せず** 409 in_progress (fail-closed) |
| finalize 失敗で processing が残る | 応答は壊さず `report()`。日次の prune が state 別集計で再度 `report()` (施策 D) |
| fatal error (OOM / timeout) で processing が残る | **閉じない**。保持期間満了まで 409 in_progress が続く。保証しない範囲として文書化 |
| 4xx が再送で 409 になる契約変更 | オーナー承認済み。docs/api-idempotency.md に写像表を固定し、テストで 3 コードを個別に固定 |

---

## 施策 D: 期限切れ鍵の物理削除 (prune + schedule)

### 変更箇所

- 新規: `app/Console/Commands/Operations/PruneIdempotencyKeysCommand.php`
- `routes/console.php` (schedule 追加)

### 波及変更

- TypeScript 型定義 / API Resource: なし
- テストファイル: 新規 `tests/Feature/Console/PruneIdempotencyKeysCommandTest.php`
- 文書: `docs/architecture.md` の cron 一覧 + 監視対象 (施策 I)

### 現行コード

期限切れ行の削除は `IdempotentRequest` の lazy delete のみ (再送されなければ永久に残る)。
`app/Console/Commands/Operations/` には `CheckMailConfig.php` しかなく、
`routes/console.php` に冪等 schedule は無い。

### 変更後コード

```php
// app/Console/Commands/Operations/PruneIdempotencyKeysCommand.php (新規)
<?php

declare(strict_types=1);

namespace App\Console\Commands\Operations;

use App\Enums\Idempotency\IdempotencyState;
use App\Models\IdempotencyKey;
use App\Models\McpIdempotencyKey;
use App\Support\Idempotency\IdempotencyRetention;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * 保持期間を過ぎた冪等キーを物理削除する (REST / MCP の両テーブル)。
 *
 * **集計の取り方**: 「先に COUNT して一括 DELETE」だと、その間の競合で
 * 「実際に削除した行」の集計にならない。state ごとに条件付き DELETE を発行し、
 * その affected rows を実績として使う。`cutoff` は開始時に 1 回だけ確定させ
 * 全 state で共有する (state 間でずれると集計の意味が壊れる)。
 *
 * **監視対象**: `processing` のまま期限切れになった行。これは
 * 「claim したのに確定できなかった要求」= プロセス強制終了か finalize 失敗の痕跡であり、
 * 1 件でもあれば report() する (AI-CUE の運用アラート経路は report() のみ)。
 */
class PruneIdempotencyKeysCommand extends Command
{
    protected $signature = 'idempotency:prune';

    protected $description = '保持期間を過ぎた冪等キー (REST / MCP) を物理削除する';

    public function handle(): int
    {
        $cutoff = IdempotencyRetention::cutoff();

        $deleted = [];
        foreach (IdempotencyState::cases() as $state) {
            $deleted[$state->value] = IdempotencyKey::query()
                ->where('state', $state->value)
                ->where('expires_at', '<=', $cutoff)
                ->delete();
        }

        // MCP テーブルは state 列を持たない (状態機械は据え置き) ため 1 本
        $deletedMcp = McpIdempotencyKey::query()
            ->where('expires_at', '<=', $cutoff)
            ->delete();

        foreach ($deleted as $state => $count) {
            $this->info("rest {$state}: {$count} 件削除");
        }
        $this->info("mcp: {$deletedMcp} 件削除");

        $stalled = $deleted[IdempotencyState::Processing->value];
        if ($stalled > 0) {
            // 確定できなかった claim が実在する。件数だけを報告する
            // (キー値・body は載せない)
            report(new RuntimeException(
                "確定できなかった冪等 claim を検出: processing のまま期限切れ count={$stalled}",
            ));
        }

        return self::SUCCESS;
    }
}
```

```php
// routes/console.php (追加)
/*
|--------------------------------------------------------------------------
| 冪等キーの保持期間 purge
|--------------------------------------------------------------------------
| 保持期間 (config idempotency.retention_hours) を超えた冪等キーを
| REST / MCP 両テーブルから物理削除する。lazy delete だけでは
| 「二度と再送されなかったキー」が残り続け単調増加するため。
|
| **監視対象**: 本コマンドの report() (processing のまま期限切れ = 確定できなかった claim)。
*/
Schedule::command('idempotency:prune')->daily()->onOneServer();
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`int`)
- [x] null 安全 (`delete()` は `int` を返す)
- [x] DTO を返している (コマンドなので該当せず。配列は内部集計のみ)
- [x] Generics: `IdempotencyKey::query()` は `Builder<IdempotencyKey>` として推論される
- [x] `RuntimeException` は `use` で import する (`routes/console.php` 側の non-compound 制約とは別ファイル。
      `NoNonCompoundGlobalUseTest` は namespace 宣言のあるクラスファイルでは import を要求する)

### テスト計画

- [ ] 新規 `tests/Feature/Console/PruneIdempotencyKeysCommandTest.php`
  - `期限切れの REST 冪等キーを state 横断で削除する` — Factory で completed/indeterminate/processing の
    expired 行と未期限行を作り、期限切れ 3 件が消え未期限が残ることを検証
  - `期限切れの MCP 冪等キーも削除する` — `McpIdempotencyKeyFactory::expired()` の行が消える
  - `未期限の行は 1 件も削除しない` — 全 state の未期限行が残る (**負のコントロール**: cutoff 条件が
    抜けたら全消しになりこのテストが赤くなる)
  - `processing のまま期限切れになった行があれば report する` — `Exception::fake()` 相当
    (`$this->app[ExceptionHandler::class]` を spy) で `report()` 呼び出しを検証
  - `processing の期限切れが 0 件なら report しない`
  - `削除件数を state 別に出力する` — `expectsOutputToContain`
- [ ] `RefreshDatabase` グローバル適用に従う (個別 `DatabaseTransactions` 不使用)

### リスク

- daily では最大 1 日ぶんの期限切れ行が残る。テーブルサイズは
  「保持期間 + 1 日ぶんの write 呼び出し数」で有界なので許容
- `report()` は毎日同じ内容で再報告される (抑制状態を持たない = 冪等な観測)。
  これは既存の `billing:detect-orphan-billing-organizations` と同じ方針で意図的

---

## 施策 E: 配線目録 gate + 免除 enum + 前提テスト

### 変更箇所

- 新規: `tests/Architecture/IdempotentRouteCoverageTest.php`
- 新規: `app/Enums/Security/IdempotencyWiringExemption.php`
- 新規: `tests/Feature/Security/IdempotencyExemptionPremiseTest.php`

### 波及変更

- TypeScript 型定義 / API Resource / DTO: なし
- `routes/api.php`: **変更しない** (`api.v1.me.session.revoke` は配線せず免除登録する)
- 文書: `AGENTS.md` ドメイン規約に「`api/v1/*` の変更系は idempotent 1 本か目録免除」を追記 (施策 I)

### 現行コード

冪等の Architecture gate は **0 本** (`tests/Architecture/` 全走査で確認)。
`idempotent` への言及は `ProjectRouteCurrentOrgGuardTest` L119-140 と
`TenantBoundaryOrderingTest` L103・L464 の順序固定のみ。

### 変更後コード

```php
// app/Enums/Security/IdempotencyWiringExemption.php (新規)
<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * `idempotent` middleware を持たないことが正しいと裁定した route の分類語彙。
 *
 * deny-by-default: `api/v1/*` の変更系 route は `idempotent` をちょうど 1 本持つか、
 * 本 enum + 30 文字以上の根拠で `IdempotentRouteCoverageTest` の目録へ登録する。
 */
enum IdempotencyWiringExemption: string
{
    /**
     * 成功すると actor 自身の認証手段が失効し、同一 token での再送が
     * **冪等層より前**の guard 段で 401 になる route。再生応答がクライアントへ
     * 返る経路が構造的に存在しないため、配線しても機能しない。
     */
    case SelfRevocationUnreachableReplay = 'self_revocation_unreachable_replay';

    /**
     * MCP transport の単一 endpoint。冪等の単位は transport ではなく tool であり、
     * 強制は AppMcpTool の中央分岐が担う (McpWriteToolIdempotencyEnforcementTest)。
     */
    case McpTransportPerToolEnforcement = 'mcp_transport_per_tool_enforcement';

    /** vendor が登録する定数 405 スタブ (本体処理へ到達しない) */
    case VendorMethodNotAllowedStub = 'vendor_method_not_allowed_stub';
}
```

```php
// tests/Architecture/IdempotentRouteCoverageTest.php (新規。構成は ThrottleCoverageInventoryTest を踏襲)
<?php

declare(strict_types=1);

use App\Enums\Security\IdempotencyWiringExemption;
use App\Http\Middleware\IdempotentRequest;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * 冪等配線 (idempotent middleware) の付与漏れ invariant (deny-by-default)。
 *
 * 「`api/v1/*` の変更系 route は `idempotent` をちょうど 1 本持つ」を機械強制する。
 * 持たないものは型付き分類 + 30 文字以上の根拠で exemption inventory へ登録させる。
 *
 * ★母集団は URI prefix `api/v1/` × 変更系メソッド。**vendor 登録の route も外さない**
 *   (MCP transport の 3 本も母集団に入り、免除理由という形で根拠が残る)。
 *   `oauth/*` を入れないのは RFC 6749/8628 の token endpoint が
 *   Idempotency-Key を仕様に持たないため (スコープ外。設計書に明記)。
 *
 * ★実効 middleware 列は Router::gatherRouteMiddleware() で取得する
 *   (`route:list --json` は group 名が展開されず誤判定するため使わない)。
 *
 * ★**保証しないこと**: 本 gate は `api/v1/` 配下しか見ない。web (session + CSRF) の
 *   書込 route、`oauth/*`、将来別 prefix で生える機械向け API には**沈黙する**。
 *   別 prefix の API を足すときは母集団設計から見直すこと。
 */

/** 変更系 HTTP メソッド */
function idempotentCoverageMutatingMethods(): array
{
    return ['POST', 'PUT', 'PATCH', 'DELETE'];
}

/**
 * 母集団件数の**下限**。現在値ちょうど (exact fit)。
 *
 * ★母集団が 6 本しかないため、下限に余裕を持たせるとセレクタが壊れて
 *   母集団が半減しても気づけない。exact fit なら prefix の typo や
 *   メソッド集合の縮小が必ず赤になる。増やすときはこの数値を書き換えること。
 */
function idempotentCoverageRouteFloor(): int
{
    return 6;
}

/** exemption 件数の上限。**現在値ちょうど** (exact fit) */
function idempotentCoverageExemptionCap(): int
{
    // ★余裕を 1 でも持たせると、その 1 本は「個別の根拠も再レビューも無しに
    //   免除できる枠」になる。上げる前に必ず再検討すること。
    return 3;
}

/** case 別上限 (分類の偏り検出。array_sum で全体 cap を導出しない) */
function idempotentCoverageExemptionCapByCase(): array
{
    return [
        IdempotencyWiringExemption::SelfRevocationUnreachableReplay->value => 1,
        IdempotencyWiringExemption::McpTransportPerToolEnforcement->value => 1,
        IdempotencyWiringExemption::VendorMethodNotAllowedStub->value => 1,
    ];
}

/** exemption 理由の最低文字数 (「同上」「N/A」を機械的に弾く) */
function idempotentCoverageReasonMinLength(): int
{
    return 30;
}

/** @return array<string, array{IdempotencyWiringExemption, string}> */
function idempotentCoverageExemptions(): array
{
    $revoke = IdempotencyWiringExemption::SelfRevocationUnreachableReplay;
    $transport = IdempotencyWiringExemption::McpTransportPerToolEnforcement;
    $stub = IdempotencyWiringExemption::VendorMethodNotAllowedStub;

    return [
        'api.v1.me.session.revoke' => [$revoke,
            'RevokeSessionController::destroy() は actor 自身の OAuth session を失効させる。'
            .'成功後は同じ Bearer token が auth:api-oauth / resolve.api-actor の段で 401 になるため、'
            .'idempotent を配線しても保存応答がクライアントへ返る経路が構造的に存在しない。'
            .'加えて失効操作自体が冪等 (session が既に無くても同じ結果)。'
            .'この前提は IdempotencyExemptionPremiseTest が behavioral に固定する。'],

        'POST /api/v1/mcp' => [$transport,
            'Laravel\Mcp\Server\Registrar::web() が登録する MCP transport の単一 endpoint。'
            .'冪等の単位は transport ではなく tool 呼び出しであり、書き込み tool への'
            .'idempotency_key 必須化は AppMcpTool::handle() の中央分岐が担う'
            .'(McpWriteToolIdempotencyEnforcementTest が強制)。'],

        'DELETE /api/v1/mcp' => [$stub,
            'Registrar::web() が登録する定数 405 スタブ (Allow: POST)。MCP の session 終了 API'
            .'非対応の表明であり、ハンドラは本体処理へ一切到達しないため冪等性の概念が無い。'],
    ];
}

/** 解決後 middleware 列 (Closure を除いた文字列 entry のみ) */
function idempotentCoverageResolvedMiddleware(RoutingRoute $route): array
{
    /** @var Router $router */
    $router = Route::getFacadeRoot();

    return array_values(array_filter(
        $router->gatherRouteMiddleware($route),
        static fn (mixed $entry): bool => is_string($entry),
    ));
}

/** 実効 middleware 列に含まれる IdempotentRequest の本数 */
function idempotentCoverageEntryCount(RoutingRoute $route): int
{
    $count = 0;
    foreach (idempotentCoverageResolvedMiddleware($route) as $entry) {
        if (is_a(Str::before($entry, ':'), IdempotentRequest::class, true)) {
            $count++;
        }
    }

    return $count;
}

/** route の inventory キー (名前があれば名前、無ければ `{METHOD} /{uri}`) */
function idempotentCoverageRouteLabel(RoutingRoute $route): string { /* ThrottleCoverage と同形 */ }

/** @return list<RoutingRoute> 母集団 (api/v1/ 配下の変更系) */
function idempotentCoverageRoutes(): array
{
    $mutating = idempotentCoverageMutatingMethods();
    $selected = [];

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }
        if (array_intersect($mutating, $route->methods()) === []) {
            continue;
        }
        $selected[] = $route;
    }

    return $selected;
}

/** 違反検出の本体 (負のコントロールから再利用するため関数に切り出す) */
function idempotentCoverageViolations(): array
{
    $inventory = idempotentCoverageExemptions();
    $violations = [];

    foreach (idempotentCoverageRoutes() as $route) {
        $label = idempotentCoverageRouteLabel($route);
        $count = idempotentCoverageEntryCount($route);

        if ($count === 1) {
            continue;
        }
        if ($count === 0 && array_key_exists($label, $inventory)) {
            continue;
        }

        $violations[] = $count === 0
            ? "{$label}: idempotent が無く exemption inventory にも未登録"
            : "{$label}: idempotent が {$count} 本ある";
    }

    return $violations;
}
```

テスト本体 (7 本):

1. `母集団が下限を下回らない (セレクタの空振り検出)`
2. `母集団の変更系 route は idempotent をちょうど 1 本持つか exemption に明示分類されている (未知は fail)`
3. `exemption inventory の key は現存する母集団 route (stale 検出)`
4. `exemption inventory の値は enum + 30 文字以上の理由`
5. `exemption 件数が上限を超えない (形骸化ガード)`
6. `exemption inventory の key は idempotent を 1 本も持たない (死んだ exemption の検出)`
7. `exemption の case 別件数が上限を超えない (分類の偏り検出。enum 全 case を走査)`

**検査が空振りしないことの保証** (追加 2 本):

8. `負のコントロール: 配線されていない api/v1 の変更系 route を検出する`
   ```php
   test('負のコントロール: idempotent 無しの api/v1 変更系 route を検出する', function (): void {
       // 目録にも無く idempotent も無い route を実行時に足すと、検出器が違反として拾う
       Route::post('api/v1/__idempotency_negative_control__', fn (): string => 'ok');

       expect(idempotentCoverageViolations())
           ->toContain('POST /api/v1/__idempotency_negative_control__: idempotent が無く exemption inventory にも未登録');
   });
   ```
9. `正のコントロール: idempotent 付きの api/v1 変更系 route は違反にならない`
   ```php
   test('正のコントロール: idempotent 付き route は違反にならない', function (): void {
       Route::post('api/v1/__idempotency_positive_control__', fn (): string => 'ok')
           ->middleware('idempotent');

       expect(idempotentCoverageViolations())->toBe([]);
   });
   ```

> 負/正のコントロールは「セレクタが母集団を拾えているか」と「判定が実際に効いているか」を
> 同時に固定する。母集団 0 件 (テスト 1) と併せて、gate が黙って PASS する経路を塞ぐ。

前提テスト:

```php
// tests/Feature/Security/IdempotencyExemptionPremiseTest.php (新規)
test('session revoke 後の同一 token 再送は冪等層より前に 401 で止まり、冪等行を作らない', function (): void {
    $issued = OAuthTestHelpers::issueCliSessionTokens(/* ... */);

    $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
        ->withHeader('Idempotency-Key', 'revoke-premise-1')
        ->deleteJson('/api/v1/me/session')
        ->assertOk();

    Auth::forgetGuards();

    $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
        ->withHeader('Idempotency-Key', 'revoke-premise-1')
        ->deleteJson('/api/v1/me/session')
        ->assertUnauthorized();

    // 冪等層に到達していないので行が 1 件も無い (= 配線しても再生は起きない前提の裏取り)
    expect(IdempotencyKey::query()->count())->toBe(0);
});
```

### PHPStan 適合チェック

- [x] テストファイルの関数に戻り値型を明示 (`array` / `int` / `string` / `list<RoutingRoute>` は PHPDoc)
- [x] null 安全 (`$route->getName()` の null を label 関数で処理)
- [x] enum は型付き (`IdempotencyWiringExemption`)
- [x] Generics: `array<string, array{IdempotencyWiringExemption, string}>` を PHPDoc に明示

### テスト計画

上記 9 本 + 前提テスト 1 本。すべて `tests/Architecture/` と `tests/Feature/Security/` に置く。
`RefreshDatabase` グローバル適用に従う (Architecture 側は DB を使わないが例外を作らない)。

### リスク

- 実行時に足した probe route が同一プロセス内の他テストへ漏れる → Laravel はテストごとに
  アプリを作り直すため漏れない。念のため probe の URI に `__` prefix を付け、
  目録・母集団下限のテストとは別テストに分離する
- `POST /api/v1/mcp` の label は vendor 側の route 名の有無に依存する。
  実測で name が無いことを確認済み (`php artisan route:list --json` で `name: null`)。
  vendor が名前を付けたら stale 検出テストが赤くなり、目録の key 更新が強制される (意図した挙動)

---

## 施策 F: 契約 parity gate + 契約文書

### 変更箇所

- 新規: `tests/Architecture/IdempotencyContractParityTest.php`
- 新規: `docs/api-idempotency.md`

### 波及変更

- TypeScript 型定義 / API Resource: なし
- `docs/architecture.md` / `docs/app-integration-guide.md` から新文書へのリンク (施策 I)

### 現行コード

保持期間は 2 つのクラス定数に重複し、文書は存在しない。parity gate も無い。

### 変更後コード

`docs/api-idempotency.md` はマーカーで機械可読な区間を持つ:

```markdown
# REST API v1 / MCP の冪等キー契約

<!-- IDEMPOTENCY_CONTRACT:BEGIN -->
- retention_hours: 24
- replay_header: Idempotent-Replayed
- states: processing, completed, indeterminate
- terminal_states: completed, indeterminate
<!-- IDEMPOTENCY_CONTRACT:END -->

（以下、決着写像表・エラーコード表・クライアント向けの指針を散文で記述）
```

```php
// tests/Architecture/IdempotencyContractParityTest.php (新規)
/*
 * 冪等契約の drift 検出 (deny-by-default)。
 *
 * config (実装の SoT) ⇔ docs/api-idempotency.md (契約文書) ⇔ ヘッダ名定数 ⇔ 状態 enum
 * の 4 者が同じことを言っていることを機械固定する。
 *
 * ★**保証しないこと**: 本 gate はマーカー区間の 4 行しか読まない。文書の散文部分
 *   (決着写像表・クライアント向け指針) が実装とずれても検出しない。
 *   散文は人間のレビュー対象である (誇張しない)。
 */
```

テスト本体 (8 本):

1. `契約文書が存在しマーカー区間を持つ` — ファイル存在 + BEGIN/END の両方 (**負のコントロール**:
   マーカーごと消すと赤。`VERIFICATION_COMMANDS` マーカーと同じ運用)
2. `マーカー区間の retention_hours は config と一致する`
3. `config/idempotency.php は env() を使わない` — ソース走査で `env(` の不在
4. `retention_hours は 24 に pin されている` — 値そのものの pin (**この数値を動かす差分が必ず現れる**)
5. `マーカー区間の replay_header は IdempotencyHeaders::REPLAYED と一致する`
6. `マーカー区間の states は IdempotencyState の全 case と一致する` (順序非依存の集合比較)
7. `マーカー区間の terminal_states は completed / indeterminate の 2 つだけ`
   (**release 経路を持たない**という家系標準の要件そのものの pin)
8. `保持期間のクラス定数が復活していない` — `IdempotentRequest` / `McpIdempotencyService` に
   `TTL_HOURS` 定数が **存在しない**ことを Reflection で固定 (二重管理への逆戻り検出)

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている
- [x] null 安全 (`preg_match` の結果を配列アクセス前に検査)
- [x] Generics: `array<string, string>` でマーカー区間をパースした結果に PHPDoc

### テスト計画

上記 8 本。マーカーのパースは 1 つのヘルパー関数に集約し、
そのヘルパー自体の positive/negative を確認するテスト (マーカー欠落で例外) をテスト 1 に含める。

### リスク

- 文書の散文と実装がずれても検出できない (上記の「保証しないこと」)。
  これは gate の限界として文書に明記し、誇張しない
- 24 を pin するため、保持期間を変える差分は必ず gate も直すことになる (意図した摩擦)

---

## 施策 G: MCP 中央強制 gate + write tool 0 本 trip-wire

### 変更箇所

- 新規: `tests/Architecture/McpWriteToolIdempotencyEnforcementTest.php`

### 波及変更

- アプリコードの変更なし (`AppMcpTool` / `ToolName` / `McpIdempotencyService` の
  状態機械化は据え置き。施策 A の保持期間参照だけが `McpIdempotencyService` に入る)
- `tests/Feature/Mcp/ToolNameInvariantTest.php` とは**役割を分ける**:
  既存は「enum ⇔ サーバ登録の 1:1」と「全 tool が AppMcpTool を継承」。
  本 gate は「中央強制を迂回できないこと」と「据え置きの trip-wire」。重複させない

### 現行コード

```php
// app/Mcp/Tools/AppMcpTool.php L58, L69-85 (中央強制)
    final public function handle(McpRequest $mcpRequest, HttpRequest $httpRequest): Response
    {
        ...
        $idempotencyKey = null;
        if ($this->toolName()->isWriteTool()) {
            $idempotencyKey = $this->extractIdempotencyKey($mcpRequest);
            $replay = $this->idempotency->replay(...);
            ...
        }

// app/Enums/Mcp/ToolName.php L55-63 (write 判定は網羅 match)
    public function isWriteTool(): bool
    {
        return match ($this) {
            self::Whoami,
            self::ListProjects,
            self::ShowProject,
            self::ListItems => false,
        };
    }
```

### 変更後コード

```php
// tests/Architecture/McpWriteToolIdempotencyEnforcementTest.php (新規)
/*
 * MCP 書き込み tool の冪等キー必須化を**中央 1 箇所でしか判断させない** invariant。
 *
 * ★本 gate は「据え置きの機械化」でもある。aicue の write tool は現在 0 本であり
 *   (ToolName の 4 case はすべて read)、MCP 側の状態機械 (reserve/complete) と
 *   T109 (replay 判定がリソース解決より前) は**意図的に据え置いている**。
 *   最初の write tool が追加された瞬間に trip-wire が赤くなり、同時にやるべき作業が
 *   失敗メッセージとして提示される。
 *
 * ★**保証しないこと**: handle() 内の中央分岐の実在確認は**字句検査**である。
 *   分岐の意味 (実際に replay/store が呼ばれるか) までは静的には見ていない。
 *   write tool が生えた時点で behavioral テストを足すこと (trip-wire がそれを強制する)。
 */
```

テスト本体 (6 本):

1. `登録 tool の母集団が下限を下回らない` — `registeredMcpToolClasses()` が 4 本以上、
   `ToolName::cases()` が 4 本以上 (空振り防止)
2. `全 tool の handle() は AppMcpTool が宣言したものである (override による迂回の禁止)` —
   `(new ReflectionMethod($class, 'handle'))->getDeclaringClass()->getName() === AppMcpTool::class`
3. `AppMcpTool::handle() は final である` — `ReflectionMethod::isFinal()`
4. `ToolName::isWriteTool() は網羅 match で書かれている (default を持たない)` — ソース走査。
   `default =>` が現れたら fail (case 追加時に write/read の判断が強制されなくなるため)
5. `AppMcpTool::handle() は isWriteTool() による中央分岐を持つ` — ソース走査 (字句検査。限界を明記)
6. **trip-wire**: `MCP write tool は 0 本である (据え置きの明示的な pin)`
   ```php
   test('MCP write tool は 0 本である (据え置きの明示的な pin)', function (): void {
       $writeTools = array_values(array_filter(
           ToolName::cases(),
           static fn (ToolName $t): bool => $t->isWriteTool(),
       ));

       expect($writeTools)->toBe([],
           '初めての MCP write tool を追加しました。次を**同じ PR で**行ってください:'
           .PHP_EOL.'1. McpIdempotencyService を reserve/complete/indeterminate へ再構成する'
           .'(現在の store() は unique 違反を握り潰しており、並行呼び出しで二重実行が起きる)'
           .PHP_EOL.'2. T109 を解消する (AppMcpTool::handle() の冪等判定を runTool() の'
           .'リソース解決より後へ。REST 側の api.project-in-org < idempotent と同型のハザード)'
           .PHP_EOL.'3. write tool の idempotency_key 必須化・replay・conflict の behavioral テストを追加する'
           .PHP_EOL.'4. 本 pin をその時点の write tool 一覧へ更新する'
           .PHP_EOL.'設計の根拠: devnotes/20260809-0027-idempotency-concurrent-claim/');
   });
   ```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている
- [x] null 安全 (`ReflectionMethod` は例外を投げるので null 分岐なし)
- [x] Generics: `list<class-string<Tool>>` を PHPDoc に明示 (既存 `ToolNameInvariantTest` と同形)

### テスト計画

上記 6 本。`registeredMcpToolClasses()` に相当するヘルパーは
`tests/Feature/Mcp/ToolNameInvariantTest.php` に既存だが、Pest のグローバル関数は
ファイル間で共有されないため **本ファイルにも同等のヘルパーを置く**
(名前を `mcpEnforcementRegisteredToolClasses()` にして衝突を避ける)。

### リスク

- 字句検査は `handle()` の実装が意味的に変わっても気づかない (明記済みの限界)
- trip-wire は「write tool が生えたら赤くなる」ため、追加者に必ず本設計を読ませる。
  逆に言えば **write tool 追加のコストを意図的に上げている**。これは
  「不完全な冪等機構のまま write tool が生える」ことより望ましいと判断する

---

## 施策 H: 既存テストの契約追随 + 並行 claim テスト

### 変更箇所

- `tests/Feature/Api/IdempotencyTest.php` (契約変更に伴う書き換え)
- 新規: `tests/Feature/Api/IdempotencyConcurrentClaimTest.php`
- `tests/Feature/Api/V1/ItemAuthorizationTest.php` (L318-340 ケース 16)
- `tests/Feature/Api/OAuthDualGuardTest.php` (L124-145)
- `tests/Feature/Mcp/McpIdempotencyServiceTest.php` (保持期間の config 由来を追加)
- 新規: `tests/Unit/Support/Idempotency/IdempotencyClaimOutcomeTest.php`
- 新規: `tests/Feature/Console/PruneIdempotencyKeysCommandTest.php` (施策 D に記載)

### 波及変更

**禁止事項 3「既存テストの削除・上書き」との関係**: 本設計は公開契約そのものを変えるため、
契約を記述している既存テストの**期待値を書き換える**。削除するのは
「バリデーション失敗 (非 2xx) は保存されず再送で再実行できる」1 本の**期待**であり、
テスト自体は同じシナリオのまま新契約の期待へ置き換える (シナリオの網羅は減らさない)。

### 現行コード

```php
// tests/Feature/Api/IdempotencyTest.php L157-178 (契約が変わるテスト)
test('バリデーション失敗 (非 2xx) は保存されず再送で再実行できる', function (): void {
    ...
    expect(IdempotencyKey::query()->count())->toBe(0);
    // 正しい body での再送 (同一 key) は実行される
    $this->withHeaders($headers)->postJson(..., ['name' => '修正後'])->assertCreated();
    expect($project->items()->count())->toBe(1);
});

// tests/Feature/Api/V1/ItemAuthorizationTest.php L318-320 (ケース 16)
// --- idempotency 層との相互作用 (ケース 16) ---
test('403 は Idempotency-Key で再生されない (権限付与後の再送は成功する)', function (): void {
```

### 変更後コード (テスト設計)

**`tests/Feature/Api/IdempotencyTest.php`** (既存 8 本 → 契約追随)

| テスト名 | 変更 |
|---------|------|
| `同一 Idempotency-Key の再送は保存レスポンスを再生する (副作用 1 回)` | 維持 + **初回に `Idempotent-Replayed` が付かない / 再生には `true` が付く**を追加 |
| `同一 Idempotency-Key + 異なる body は 409 idempotency_conflict` | 維持 + envelope の 3 キー検証 |
| `Idempotency-Key なしの再送は通常どおり毎回実行される` | 維持 |
| `Idempotency-Key は API キー単位でスコープされる` | 維持 + 各行の state が completed |
| `TTL 超過の Idempotency-Key は未使用扱いで再実行される` | 維持 |
| `Idempotency-Key は route 単位でスコープされる` | 維持 |
| `IdempotencyKeyFactory: expired 状態は isExpired が真` | 維持 + processing / indeterminate の Factory state を検証 |
| ~~`バリデーション失敗 (非 2xx) は保存されず再送で再実行できる`~~ | → `バリデーション失敗は indeterminate として記録され同一キーの再送は 409` に**置換** |

置換後:

```php
test('バリデーション失敗は indeterminate として記録され、同一キーの再送は 409 になる', function (): void {
    // ★契約変更 (家系標準 AG-032): 決着は completed と indeterminate だけで、
    //   release (再実行を許す) 経路を持たない。4xx の後に同じキーは使えない。
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    [, $plain] = issueApiKey($organization, $owner);
    $headers = ['Authorization' => "Bearer {$plain}", 'Idempotency-Key' => 'idem-key-003'];

    $this->withHeaders($headers)
        ->postJson("/api/v1/projects/{$project->id}/items", ['note' => 'name なし'])
        ->assertUnprocessable();

    $row = IdempotencyKey::query()->sole();
    expect($row->state)->toBe(IdempotencyState::Indeterminate);
    expect($row->response_status)->toBeNull();

    // 同一 body の再送 → 409 indeterminate
    $this->withHeaders($headers)
        ->postJson("/api/v1/projects/{$project->id}/items", ['note' => 'name なし'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'idempotency_indeterminate');

    // 修正した body での再送 → hash 不一致なので 409 conflict (新しいキーが要る)
    $this->withHeaders($headers)
        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => '修正後'])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'idempotency_conflict');

    // 新しいキーなら通る (詰まないことの確認)
    $this->withHeaders([...$headers, 'Idempotency-Key' => 'idem-key-004'])
        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => '修正後'])
        ->assertCreated();

    expect($project->items()->count())->toBe(1);
});
```

**`tests/Feature/Api/IdempotencyConcurrentClaimTest.php`** (新規)

| # | テスト名 | 検証内容 |
|---|---------|---------|
| 1 | `claim は controller 実行より前に processing として可視になる` | テスト内で `idempotent` 付きの probe route を登録し、そのハンドラから同一キーの行を読んで state を応答に載せる → `processing` であること。**claim が本処理より前にコミット済み**であることの直接証明 |
| 2 | `処理中の同一キーは controller を実行せず 409 idempotency_in_progress` | `IdempotencyKeyFactory::processing()` で claim 済み行を作り、同一 actor / route / key / body で POST → 409 + `error.code` + `$project->items()->count() === 0` (**副作用ゼロ**) |
| 3 | `claim の INSERT は同一スコープで 1 本しか通らない` | 同一 (api_key, route, key) の `insertOrIgnore` を 2 回発行し、戻り値が 1 → 0、行数が 1。**unique 制約が調停者であることの直接証明** |
| 4 | `決着済み (completed) の行があれば controller を実行せず再生する` | completed 行を Factory で作り POST → 保存 body が返り、items が増えない、`Idempotent-Replayed: true` |
| 5 | `indeterminate の行があれば 409 idempotency_indeterminate で副作用ゼロ` | 同上の形 |
| 6 | `例外が middleware まで抜けた場合も indeterminate に確定する` | probe route のハンドラで `RuntimeException` を投げ、行が `indeterminate` になることを確認 (例外自体は Laravel の handler が 500 に変換する経路も併せて確認) |
| 7 | `期限切れの processing 行は削除されて再 claim できる` | expired + processing の行を作り POST → 201、行は 1 件で `completed` |
| 8 | `claim 行は api_key_id / user_id のどちらか一方だけが非 NULL` | API キー経路と OAuth 経路の両方で 1 回ずつ POST し、各行の排他を検証 |
| 9 | `409 の 3 コードはいずれも error envelope の形が同じ` | top-level は `error` のみ (`assertJsonCount(1)`)、`error` 配下は `code` / `message` / `status` の 3 キーのみ (`assertJsonCount(3, 'error')`)、値は `assertJsonPath` 3 本 |
| 10 | `finalize が失敗しても元の応答は壊れない` | probe route + 事前に claim 行を消す仕込みで `affected === 0` を作り、応答が 201 のまま / `report()` が呼ばれることを検証 |

> テスト 1 / 6 / 10 の probe route は `Route::post('api/v1/__idempotency_probe__/...')` として
> `beforeEach` ではなく各テスト内で登録し、`idempotent` を含む必要 middleware を明示付与する
> (`resolve.api-actor` が前段に要る点に注意)。probe route は施策 E の gate の母集団にも入るが、
> **Architecture テストと Feature テストはプロセス内のアプリを共有しない**ため干渉しない。

**`tests/Feature/Api/V1/ItemAuthorizationTest.php` ケース 16** (契約追随)

```php
test('403 の後は同一キーが 409 になり、新しいキーなら権限付与後に成功する', function (): void {
    // ★契約変更: 403 は「決着不明」として indeterminate に倒れる。
    //   middleware は controller の 403 が副作用の前だったか後だったかを知らないため、
    //   再実行せず新しいキーを要求する (家系標準 AG-032)。
    // ... 403 を受ける → 権限付与 → 同一キー再送で 409 idempotency_indeterminate
    // ... 新しいキーで再送 → 201
});
```

**`tests/Feature/Api/OAuthDualGuardTest.php` L124-145**: replay 側に
`assertHeader('Idempotent-Replayed', 'true')` を追加、初回には `assertHeaderMissing` を追加。

**`tests/Unit/Support/Idempotency/IdempotencyClaimOutcomeTest.php`** (新規)

- `claimed() は row を持たない` — `rowOrFail()` が `InvalidArgumentException`
- `replay() / conflict() / inProgress() / indeterminate() は row を返す`
- `__construct は private である (named constructor 以外で作れない)` — Reflection で `isPrivate()`
- `status と row の組合せは named constructor で固定される` — 各 named constructor の status を検証

### PHPStan 適合チェック

- [x] テスト内のヘルパー戻り値型を明示
- [x] `IdempotencyKey::query()->sole()` の戻り値は `IdempotencyKey` (Generics で解決)
- [x] Factory 経由のデータ生成のみ (`Model::create()` 手組み無し)

### テスト計画

上表がテスト計画そのもの。全テストは `RefreshDatabase` グローバル適用の下で動き、
個別 `DatabaseTransactions` は使わない。`--parallel` で動くよう、
probe route の URI とキー文字列はテストごとに固有にする。

### リスク

- **真の並行実行はテストできない** (PHP のテストは単一プロセス)。
  テスト 3 が「unique 制約が 2 本目の INSERT を落とす」ことを DB レベルで直接証明し、
  テスト 1 が「claim が本処理より前にコミットされている」ことを証明する。
  この 2 つの合成が並行安全性の根拠であり、**「並行 2 本を実際に走らせた」とは書かない** (誇張しない)
- `RefreshDatabase` 下では全操作が同一接続・同一トランザクション内で見えるため、
  **プロセス跨ぎの可視性は検証していない**。これも保証範囲として明記する

---

## 施策 I: 文書追随

### 変更箇所

| ファイル | 追記内容 |
|---------|---------|
| `AGENTS.md` ドメイン固有規約 | 「9. 冪等キーの配線と決着規約」を追加: `api/v1/*` の変更系は `idempotent` を 1 本持つか型付き免除、決着は completed / indeterminate のみで release 経路を持たない、保持期間の SoT は `config/idempotency.php`、`Idempotent-Replayed` は外部標準に無い拡張、MCP write 側は据え置きで trip-wire が起票条件 |
| `docs/architecture.md` | モデル表の `IdempotencyKey` 行に state を追記 / cron 一覧に `idempotency:prune` / 監視対象に「prune の report (確定できなかった claim)」 |
| `docs/app-integration-guide.md` | L149 の「すべての書き込みエンドポイントに Idempotency-Key を配線する」に gate と目録免除の運用を追記 / L75 のテスト一覧に新テストを追加 |
| `docs/factories.md` | `IdempotencyKeyFactory` の state 一覧に `processing()` / `indeterminate()` |
| `docs/TODO.md` | T109 の行に「trip-wire (`McpWriteToolIdempotencyEnforcementTest`) が起票条件を機械化済み」と注記 (**行を消さない**) |
| `docs/api-idempotency.md` | 施策 F で新設 (契約の正本) |

### 波及変更

- `tests/js/architecture/verification-commands-doc-sync.test.ts` は `AGENTS.md` の
  `VERIFICATION_COMMANDS` マーカー区間のみを見るため、ドメイン規約の追記では影響を受けない
- `docs/architecture.md` は他の並行設計 (external-seam-funnel / queue-dispatch-atomicity) も
  触る可能性が高い。**マージ順の衝突に注意** (実装モード表を参照)

### PHPStan 適合チェック

該当なし (文書のみ)。

### テスト計画

- [ ] `IdempotencyContractParityTest` (施策 F) が `docs/api-idempotency.md` の存在とマーカーを固定する
- [ ] 既存 `DocumentTitleCoverageTest` / `ScriptsReadmeInventoryTest` への影響がないことを確認
      (どちらも対象が別。新規 script は追加しない)

### リスク

- 文書の散文は機械検査されない (施策 F の「保証しないこと」に明記済み)

---

## 保証しないもの (誇張しない)

本設計が **保証しない**ことを明示する。実装時にこの一覧を縮めない。

1. **真の並行 2 本の実走**: テストは単一プロセスであり、並行安全性は
   「claim が本処理より前にコミットされる (テスト 1)」+「unique が 2 本目を落とす (テスト 3)」の
   合成として証明する。実際に 2 プロセスを同時に走らせてはいない
2. **プロセス跨ぎの可視性**: `RefreshDatabase` 下では同一接続で観測しているため、
   別接続からの可視性 (commit の伝播) は検証していない。これは pgsql の read committed の
   前提であって、テストによる保証ではない
3. **fatal error 時の claim 回収**: OOM / timeout / プロセス強制終了で `processing` が残る窓は
   閉じない。保持期間満了まで同一キーは 409 in_progress を返し続ける。
   観測は prune の state 別集計のみ
4. **`api/v1/` 以外の書込面**: 配線 gate は URI prefix `api/v1/` の変更系しか見ない。
   web (session + CSRF)、`oauth/*`、将来別 prefix の機械向け API には**沈黙する**
5. **MCP write tool の並行安全性**: `McpIdempotencyService::store()` の unique 握り潰しは
   **残る**。write tool が 0 本のため到達不能だが、「MCP も並行安全になった」とは書かない。
   trip-wire が最初の write tool 追加時に是正を強制する
6. **中央強制の意味的検証**: 施策 G のテスト 4 / 5 は字句検査であり、
   `handle()` の分岐が意味的に正しく動くことまでは静的に見ていない
7. **契約文書の散文**: parity gate はマーカー区間の 4 行しか読まない。
   決着写像表・クライアント向け指針が実装とずれても検出しない
8. **`api_key_id` / `user_id` の排他**: DB の CHECK 制約は持たない。
   保証しているのは単一構築点 (`ownershipColumns()`) と Feature テストである

---

## mutation で赤化を確認する手順 (実装時に必ず実施)

各 gate / テストが**実際に効いている**ことを、実装後に以下の変異で確認する。
確認したら変異を戻す (変異をコミットしない)。

| # | 変異 | 赤になるべきテスト |
|---|------|------------------|
| 1 | `routes/api.php` の write group から `'idempotent'` を外す | `IdempotentRouteCoverageTest` テスト 2 (3 route が違反) |
| 2 | `IdempotentRouteCoverageTest` の母集団 prefix を `api/v2/` に変える | 同テスト 1 (母集団下限) |
| 3 | 免除の理由文字列を 10 文字にする | 同テスト 4 (理由の最低文字数) |
| 4 | `api.v1.me.session.revoke` に `'idempotent'` を付ける | 同テスト 6 (死んだ exemption 検出) |
| 5 | 免除を 1 件増やす (架空 route) | 同テスト 3 (stale) + テスト 5 (cap) |
| 6 | 負のコントロールの probe route から `Route::post` を消す | 同テスト 8 (負のコントロール自体が赤) |
| 7 | `claim()` の `insertOrIgnore` を `insert` に戻す | `IdempotencyConcurrentClaimTest` テスト 3 (例外) |
| 8 | claim を `$next()` の**後**に移す | 同テスト 1 (probe が processing を見られない) |
| 9 | `finalize()` から `where('state', processing)` を外す | 同テスト 10 (affected が 1 になり report されない) |
| 10 | `finalize()` の indeterminate 分岐を消す (非 2xx で何もしない) | `IdempotencyTest` の置換テスト (行が processing のまま) |
| 11 | `replayResponse()` から `Idempotent-Replayed` を外す | `IdempotencyTest` / `OAuthDualGuardTest` の replay 検証 |
| 12 | `IdempotencyHeaders::REPLAYED` を `Idempotency-Replayed` に変える | `IdempotencyContractParityTest` テスト 5 |
| 13 | `config/idempotency.php` の 24 を 48 にする | 同テスト 2 と 4 |
| 14 | `config/idempotency.php` を `env('IDEMPOTENCY_RETENTION_HOURS', 24)` にする | 同テスト 3 |
| 15 | `IdempotentRequest` に `public const TTL_HOURS = 24;` を戻す | 同テスト 8 |
| 16 | `docs/api-idempotency.md` のマーカーを消す | 同テスト 1 |
| 17 | `ToolName` に write tool の case を 1 本足す | `McpWriteToolIdempotencyEnforcementTest` テスト 6 (trip-wire) |
| 18 | `AppMcpTool::handle()` の `final` を外す | 同テスト 3 |
| 19 | `ToolName::isWriteTool()` に `default => false` を足す | 同テスト 4 |
| 20 | prune の state 別 DELETE を一括 DELETE に戻す | `PruneIdempotencyKeysCommandTest` の state 別集計 |
| 21 | prune から `expires_at <= cutoff` 条件を外す | 同テストの「未期限の行は 1 件も削除しない」(負のコントロール) |
| 22 | migration の backfill を `indeterminate` に変える | `IdempotencyTest` の再生テスト (既存行の再生が 409 になる) |
| 23 | migration の `state` に DB default を残す | `IdempotencyStateMigrationTest` |

---

## 実装順序 (テストファースト)

AGENTS.md 思考原則 5「テストファースト。fail を確認してから実装に入る」に従う。

1. 施策 B の migration + Enum + Model + Factory (以降のテストがデータを作れるようにする)
2. 施策 A の config + Support (parity gate の対象を作る)
3. **施策 H の新規テストを先に書いて赤を確認** (`IdempotencyConcurrentClaimTest`)
4. 施策 C の middleware 書き換え → 3 が緑になることを確認
5. 施策 H の既存テスト追随 (契約変更ぶん)
6. 施策 D の prune + テスト
7. 施策 E / F / G の gate + 前提テスト (**それぞれ mutation で赤化を確認**)
8. 施策 I の文書追随
9. `composer test` / `composer phpstan` / `vendor/bin/pint --test` を全 green にする

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | (1) migration を伴い、`idempotency_keys` のスキーマを変える。(2) REST API v1 の公開契約を破壊的に変更し、既存 Feature テスト 3 ファイルの期待を書き換える。(3) Architecture gate を 3 本追加し、母集団下限・cap を exact fit で pin する。いずれも他施策と混ぜると「どの変更で赤くなったか」が切り分けられなくなる |
| 競合リスク | **中**。同時に走っている 2 設計 (`20260809-0027-external-seam-funnel` / `20260809-0027-queue-dispatch-atomicity`) と、`routes/console.php` (schedule 追記)・`AGENTS.md` ドメイン規約 (項番)・`docs/architecture.md` (cron 一覧 / 監視対象) が衝突しうる。**AGENTS.md の項番は後からマージする側が振り直す**前提で、番号ではなく項目名で相互参照する (AGENTS.md 冒頭の採番注意と同じ運用)。migration のファイル名 timestamp も他設計と重ならないよう `2026_08_09_000100` を占有する |
| 事前確認 | `TenantBoundaryOrderingTest` / `ProjectRouteCurrentOrgGuardTest` が無改修で緑であることを、middleware 書き換え直後に単独で確認する (順序契約を壊していないことの早期検出) |


---

## 関連する現行コード (抜粋)

### `app/Http/Middleware/IdempotentRequest.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\Context\ApiActorContext;
use App\Enums\ApiErrorCode;
use App\Http\Resources\ApiErrorResource;
use App\Models\ApiKey;
use App\Models\IdempotencyKey;
use App\Support\Api\ApiError;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Webmozart\Assert\Assert;

/**
 * Idempotency-Key middleware (REST API v1 の全 write エンドポイントに配線する)。
 *
 * - ヘッダ無し → 素通し (idempotency なし)
 * - 同一 key + 同一 request hash → 保存済みレスポンスを再生 (副作用は 1 回だけ)
 * - 同一 key + 異なる request hash → 409 idempotency_conflict
 * - 初回は controller 実行後、成功 (2xx JSON) レスポンスを保存する
 * - 保存行は TTL_HOURS で失効する (期限切れは未使用扱いで削除 → 再実行できる)
 *
 * スコープは actor 単位 × route: API キー actor は (api_key_id, route_name, key)、
 * OAuth user-token actor は (user_id, route_name, key)。同一 key でも別 route なら独立。
 *
 * 順序契約: auth → throttle → resolve.api-actor → api-key.ability → idempotent → controller
 * (api_actor attribute が前提。配線ミスは fail-closed で 500 + report)。
 */
class IdempotentRequest
{
    /** 保存レスポンスの TTL (時間)。超過エントリは再送時に削除して作り直す */
    public const TTL_HOURS = 24;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || trim($key) === '') {
            $response = $next($request);
            Assert::isInstanceOf($response, Response::class);

            return $response;
        }
        $key = trim($key);

        $actor = $request->attributes->get(ResolveApiActor::ATTRIBUTE_KEY);
        if (! $actor instanceof ApiActorContext) {
            // 配線ミス (resolve.api-actor middleware が前段に無い)。fail-closed で 500
            report(new \LogicException(
                'IdempotentRequest middleware reached without ApiActorContext attribute. '
                .'Ensure resolve.api-actor middleware runs first.',
            ));

            return ApiErrorResource::make(ApiError::fromCode(ApiErrorCode::InternalServerError))
                ->response()->setStatusCode(500);
        }

        $routeName = $request->route()?->getName() ?? $request->path();
        $requestHash = $this->hashRequest($request);

        $existing = $this->scopedQuery($actor)
            ->where('route_name', $routeName)
            ->where('key', $key)
            ->first();

        // TTL 超過エントリは未使用扱いで削除する (unique 制約を空けて再実行を許す)
        if ($existing !== null && $existing->isExpired()) {
            $existing->delete();
            $existing = null;
        }

        if ($existing !== null) {
            if ($existing->request_hash !== $requestHash) {
                return ApiErrorResource::make(ApiError::fromCode(ApiErrorCode::IdempotencyConflict))
                    ->response()->setStatusCode(409);
            }

            return $this->replayResponse($existing);
        }

        $response = $next($request);
        Assert::isInstanceOf($response, Response::class);

        // 成功 (2xx) の JSON レスポンスのみ保存する (失敗は保存しない = 再送で再実行できる)
        if ($response instanceof JsonResponse && $response->isSuccessful()) {
            $this->storeResponse($actor, $routeName, $key, $requestHash, $response);
        }

        return $response;
    }

    /**
     * actor 単位の保存行 lookup query (API キー actor = api_key_id、user-token actor = user_id)。
     *
     * @return Builder<IdempotencyKey>
     */
    private function scopedQuery(ApiActorContext $actor): Builder
    {
        if ($actor->apiKey instanceof ApiKey) {
            return IdempotencyKey::query()->where('api_key_id', $actor->apiKey->id);
        }

        return IdempotencyKey::query()
            ->whereNull('api_key_id')
            ->where('user_id', $actor->user->id);
    }

    /** メソッド + パス + body で同一リクエストかを判定する */
    private function hashRequest(Request $request): string
    {
        return hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());
    }

    private function replayResponse(IdempotencyKey $existing): JsonResponse
    {
        return (new JsonResponse($existing->response_body, $existing->response_status))
            ->header('Content-Type', 'application/json');
    }

    private function storeResponse(
        ApiActorContext $actor,
        string $routeName,
        string $key,
        string $requestHash,
        JsonResponse $response,
    ): void {
        $bodyJson = $response->getContent();
        $body = is_string($bodyJson) && $bodyJson !== '' && $bodyJson !== 'null'
            ? json_decode($bodyJson, true)
            : null;

        $row = new IdempotencyKey([
            'route_name' => $routeName,
            'key' => $key,
            'request_hash' => $requestHash,
            'response_status' => $response->getStatusCode(),
            'response_body' => is_array($body) ? $body : null,
            'expires_at' => now()->addHours(self::TTL_HOURS),
        ]);
        // 所有権キーは $fillable 外のため明示代入 (mass assignment 二層防御)
        $row->forceFill(
            $actor->apiKey instanceof ApiKey
                ? ['api_key_id' => $actor->apiKey->id]
                : ['user_id' => $actor->user->id],
        );

        try {
            $row->save();
        } catch (QueryException $e) {
            // 同時リクエストの unique 衝突は best-effort で無視する
            // (勝者の保存行が再送時の再生に使われる)
            report($e);
        }
    }
}

```

### `database/migrations/2026_06_11_100100_create_idempotency_keys_table.php`

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Idempotency-Key の保存先 (REST API v1 の write エンドポイント用)。
     *
     * - actor 単位 × route で一意: API キー actor は (api_key_id, route_name, key)、
     *   OAuth user-token actor は (user_id, route_name, key)。api_key_id / user_id は
     *   どちらか一方のみ非 NULL (IdempotentRequest middleware が actor 種別で書き分ける)
     * - request_hash はメソッド + パス + body の sha256 (同一 key + 別 body は 409)
     * - response_status / response_body に成功レスポンスを保存し、再送時に再生する
     * - expires_at 超過エントリは未使用扱い (TTL。index は掃除バッチ / lookup 用)
     */
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_key_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('route_name');
            $table->string('key');
            $table->string('request_hash');
            $table->unsignedSmallInteger('response_status');
            $table->json('response_body')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('created_at')->nullable();

            // NULL は unique 制約で常に distinct (pgsql / sqlite 共通) のため、
            // 2 本の unique が actor 種別ごとの一意性をそれぞれ担保する
            $table->unique(['api_key_id', 'route_name', 'key']);
            $table->unique(['user_id', 'route_name', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};

```

### `app/Models/IdempotencyKey.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\IdempotencyKeyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Idempotency-Key の保存行 (REST API v1 write エンドポイント用)。
 *
 * actor 単位の UNIQUE で同一 actor × route の再送を検知する: API キー actor は
 * (api_key_id, route_name, key)、OAuth user-token actor は (user_id, route_name, key)。
 * expires_at 超過エントリは未使用扱い (TTL は IdempotentRequest middleware が付与)。
 *
 * api_key_id / user_id は所有権キーのため $fillable 外。作成は relation 経由か
 * forceFill での明示代入のみ (IdempotentRequest::storeResponse)。
 *
 * @property int $id
 * @property int|null $api_key_id
 * @property int|null $user_id
 * @property string $route_name
 * @property string $key
 * @property string $request_hash
 * @property int $response_status
 * @property array<array-key, mixed>|null $response_body
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 */
class IdempotencyKey extends Model
{
    /** @use HasFactory<IdempotencyKeyFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'route_name',
        'key',
        'request_hash',
        'response_status',
        'response_body',
        'expires_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'response_body' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * TTL 超過か。超過エントリは未使用扱い (再送時に削除して作り直す)。
     */
    public function isExpired(?Carbon $now = null): bool
    {
        return $this->expires_at->lessThanOrEqualTo($now ?? Carbon::now());
    }

    /**
     * @return BelongsTo<ApiKey, $this>
     */
    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    /**
     * OAuth user-token actor の保存行の帰属先 (api_key_id とは排他)。
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

```

### `database/factories/IdempotencyKeyFactory.php`

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ApiKey;
use App\Models\IdempotencyKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @extends Factory<IdempotencyKey>
 */
class IdempotencyKeyFactory extends Factory
{
    /**
     * api_key 未指定なら ApiKeyFactory に連鎖する (親 Factory 連鎖の規約)。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'api_key_id' => ApiKey::factory(),
            'route_name' => 'api.v1.projects.items.store',
            'key' => (string) Str::uuid(),
            'request_hash' => hash('sha256', Str::random(32)),
            'response_status' => 201,
            'response_body' => ['data' => ['id' => 1]],
            'expires_at' => Carbon::now()->addDay(),
        ];
    }

    /** 指定 API キーの保存行として作る */
    public function forApiKey(ApiKey $apiKey): static
    {
        return $this->state(fn () => ['api_key_id' => $apiKey->id]);
    }

    /** TTL 超過 (未使用扱い) として作る */
    public function expired(?Carbon $expiresAt = null): static
    {
        return $this->state(fn () => ['expires_at' => $expiresAt ?? Carbon::now()->subMinute()]);
    }
}

```

### `app/Enums/ApiErrorCode.php`

```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * REST API v1 の正規エラーコード (統一 envelope {error: {code, message, status, details?}})。
 *
 * string 値は公開 API 契約の一部。リネームは version bump を伴う変更として扱うこと。
 * defaultStatus / defaultMessage は handler とドキュメントの drift を防ぐための単一の真実。
 */
enum ApiErrorCode: string
{
    case Unauthenticated = 'unauthenticated';
    case Forbidden = 'forbidden';
    /**
     * API キーに必要な ability が付与されていない場合の 403 専用コード。
     * Forbidden と分けるのは、クライアントが details.required_ability を読んで
     * 「ability を追加して再発行」と案内できるようにするため。
     */
    case InsufficientAbility = 'insufficient_ability';
    /**
     * 認証は成立したが actor (人間の帰属) を解決できない場合の 403 専用コード
     * (例: API キーの発行者が削除済み)。resolve.api-actor middleware が返す。
     */
    case ActorNotResolvable = 'actor_not_resolvable';
    case NotFound = 'not_found';
    case ValidationFailed = 'validation_failed';
    case RateLimited = 'rate_limited';
    /** 同一 Idempotency-Key + 異なる request body の再送 (409) */
    case IdempotencyConflict = 'idempotency_conflict';
    case InternalServerError = 'internal_server_error';

    public function defaultStatus(): int
    {
        return match ($this) {
            self::Unauthenticated => 401,
            self::Forbidden, self::InsufficientAbility, self::ActorNotResolvable => 403,
            self::NotFound => 404,
            self::ValidationFailed => 422,
            self::RateLimited => 429,
            self::IdempotencyConflict => 409,
            self::InternalServerError => 500,
        };
    }

    public function defaultMessage(): string
    {
        return match ($this) {
            self::Unauthenticated => 'Invalid or expired API key.',
            self::Forbidden => 'You do not have permission to perform this action.',
            self::InsufficientAbility => 'This API key does not have the required ability.',
            self::ActorNotResolvable => 'The authenticated actor could not be resolved.',
            self::NotFound => 'Resource not found.',
            self::ValidationFailed => 'The given data was invalid.',
            self::RateLimited => 'Too many requests.',
            self::IdempotencyConflict => 'Idempotency key conflict.',
            self::InternalServerError => 'Internal server error.',
        };
    }

    /**
     * HTTP status → 正規コード。未対応 status (405 / 415 等) は internal_server_error に
     * collapse する (無関係なコードへの暗黙の別名化を防ぐ。HTTP status 自体は保持される)。
     */
    public static function fromHttpStatus(int $status): self
    {
        return match ($status) {
            401 => self::Unauthenticated,
            403 => self::Forbidden,
            404 => self::NotFound,
            409 => self::IdempotencyConflict,
            422 => self::ValidationFailed,
            429 => self::RateLimited,
            default => self::InternalServerError,
        };
    }
}

```

### `app/Enums/Mcp/ToolName.php`

```php
<?php

declare(strict_types=1);

namespace App\Enums\Mcp;

/**
 * MCP tool の canonical name 一覧。
 *
 * - `requiredPermission()`: 各 tool の Laratrust permission (null = member 基本権限)
 * - `isWriteTool()`: 書き込み系 (idempotency_key required) 判定
 *
 * 認可判定の single source。各 Tool class では override せず本 enum を参照する
 * (AppMcpTool::name() が enum 値を tools/call の lookup 名として返す)。
 * サーバ登録 (AppMcpServer::$tools) との 1:1 対応は ToolNameInvariantTest が強制する。
 */
enum ToolName: string
{
    case Whoami = 'whoami';
    case ListProjects = 'list-projects';
    case ShowProject = 'show-project';
    case ListItems = 'list-items';

    /**
     * tool 名 → 必要 Laratrust permission の対応。
     *
     * <!-- TEMPLATE-MARKER: アプリ固有の permission gate をここに追加する。
     *      例: self::RunEvaluation->value => 'evaluations-run'。permission は
     *      PermissionSeeder に定義し、McpAuthorizationContext::authorizeTool が
     *      laratrust_team_id 明示で評価する。テンプレートの read 系 tool は
     *      member 基本権限のみ (エントリなし = null)。 -->
     *
     * @return array<string, non-empty-string>
     */
    private static function permissionMap(): array
    {
        return [];
    }

    /**
     * tool 実行に必要な Laratrust permission (organization team scope で runtime 再評価)。
     * null = member 基本権限のみで呼べる。
     */
    public function requiredPermission(): ?string
    {
        return self::permissionMap()[$this->value] ?? null;
    }

    /**
     * 書き込み系 tool か (true なら idempotency_key (UUID v4) が必須になり、
     * AppMcpTool が McpIdempotencyService で replay/store を配線する)。
     *
     * match を網羅で書くことで、tool 追加時に write/read の判断を強制する。
     */
    public function isWriteTool(): bool
    {
        return match ($this) {
            self::Whoami,
            self::ListProjects,
            self::ShowProject,
            self::ListItems => false,
        };
    }
}

```

### 参考 (Codex 側で読める。全文は貼らない)

- `routes/api.php` — write group L95-109 / session revoke L69-74 / 順序契約のコメント
- `app/Mcp/Tools/AppMcpTool.php` — L58 `final handle()` / L69-85 の中央強制
- `app/Services/Mcp/McpIdempotencyService.php` — `TTL_HOURS` と `store()` の unique 握り潰し
- `tests/Architecture/ThrottleCoverageInventoryTest.php` — 目録型 gate の見本 (494 行)
- `tests/Feature/Mcp/ToolNameInvariantTest.php` — 既存の enum ⇔ サーバ登録 1:1 gate
- `tests/Architecture/TenantBoundaryOrderingTest.php` / `tests/Architecture/ProjectRouteCurrentOrgGuardTest.php` — middleware 順序契約
- `tests/Feature/Api/IdempotencyTest.php` / `tests/Feature/Api/V1/ItemAuthorizationTest.php` / `tests/Feature/Api/OAuthDualGuardTest.php` — 契約が変わる既存テスト
- `phpunit.xml` — テストは pgsql 単一 (sqlite/pgsql 二重運用なし)
