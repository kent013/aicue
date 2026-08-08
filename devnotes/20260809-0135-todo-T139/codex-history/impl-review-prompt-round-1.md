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


## あなたの役割

Laravel 12 + Svelte 5 (Inertia) アプリ **AI-CUE** の実装レビュアーとして、TODO **T139
「冪等キーの並行409と配線漏れ検査」** の実装差分をレビューする。

## レビュー観点

1. **設計との一致性** — 詳細設計 (Codex 合議 APPROVED 済み) の施策 A〜I が実装されているか。
   **設計から意図的に外れた箇所は、その逸脱が妥当かを判定する** (実装者は逸脱を明示している)。
2. **正確性** — 並行 claim の競合、状態遷移の抜け、fail-closed の妥当性、例外経路。
3. **PHPStan level 10 適合性** — `@phpstan-ignore` / baseline / 型 widen が無いこと。
4. **DTO / JsonResource パターン** — `response()->json()` 直書きが無いこと。
5. **テスト網羅性** — 各施策にテストがあるか。gate が空振りしないか (負/正のコントロール)。
   **主張範囲が誇張されていないか** (テストが証明していないことを「保証した」と書いていないか)。
6. **セキュリティ** — テナント境界、ログに載る情報 (PII / 秘密値)、存在オラクル。
7. **DESIGN.md 準拠 / Atomic Design 準拠** — 本差分に `resources/js` / `resources/css` の
   変更は**無い**ため該当なし (無いことの確認のみ)。

## 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
  - Critical = マージ前に必ず直すべき欠陥 (バグ / 契約違反 / セキュリティ / 規約違反)
  - Warning = 直すべきだが致命的でない
  - Suggestion = 任意
- 最後に **全体判定: APPROVED または CHANGES_REQUESTED** を明記する
- 実装者が既に「保証しない」と明示した範囲を Critical に格上げしないこと
  (誇張していないことが要件であり、範囲を広げること自体は要求ではない)


---

## 詳細設計書 (Codex 合議 APPROVED 済み)

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
}
```

> **`cutoff()` は作らない** (Codex Round 1 [Suggestion] を受けた縮小)。
> prune の cutoff は `CarbonImmutable::now()` そのものであり、Support に別名を置くと
> 「保持期間の SoT」と関係のない薄い委譲が増える。cutoff を 1 回だけ確定させる意図は
> prune コマンド側の docblock とコードで表現する (思考原則 2)。
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
- `app/Models/IdempotencyKey.php` (PHPDoc の `$response_status` を `?int` に、`$state` を追加、casts に enum、
  **`isExpired()` の引数型を `?CarbonInterface` へ**)
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
        //
        // ⚠ **この migration は実質 irreversible である**。down() はスキーマを
        //    「state 無し / response_status nullable」に戻すだけで、旧コードが前提とする
        //    「全行が完了応答を持つ」状態には戻せない (processing / indeterminate 行が
        //    response_status = null のまま残り、旧 replayResponse が null status で壊れる)。
        //    **旧コードへ戻す前に `DELETE FROM idempotency_keys WHERE response_status IS NULL`
        //    を人手で実行する**こと (削除しても失うのは未確定の claim だけで、
        //    再送は再実行になる = ロールバック時点では旧契約と同じ挙動)。
        //    この手順は施策 I で docs/api-idempotency.md の「ロールバック手順」節に書く。
    }
};
```

> **`state` に DB CHECK 制約は入れない** (Codex Round 1 [Suggestion] への反論)。
> 書き込み経路は claim の `insertOrIgnore` と finalize の条件付き UPDATE の 2 箇所だけで、
> どちらも `IdempotencyState` enum の `->value` しか渡さない。読み戻しは enum cast なので
> 未知の値が入れば `ValueError` で即座に落ちる (fail-fast は既にある)。
> pgsql 専用の raw `ALTER TABLE … ADD CONSTRAINT` を migration に持ち込むと、
> config 既定の sqlite で migration が動かなくなる副作用のほうが大きい (思考原則 2)。

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

    /**
     * TTL 超過か。
     *
     * ★引数は `CarbonInterface` にする。middleware は `CarbonImmutable` を基準時刻に使うが、
     *   `Illuminate\Support\Carbon` (mutable) は `CarbonImmutable` の親ではないため、
     *   現行の `?Carbon` 型のままだと **実行時 TypeError** になる。
     */
    public function isExpired(?CarbonInterface $now = null): bool
    {
        return $this->expires_at->lessThanOrEqualTo($now ?? Carbon::now());
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
  - **`既存行は completed へ backfill される` (backfill の実挙動テスト)** —
    最終スキーマの検査だけでは backfill を `indeterminate` に変える変異を捕まえられないため、
    **migration の `up()` を実挙動として走らせる**:
    ```php
    test('既存行は completed へ backfill される', function (): void {
        // 1. 旧スキーマ相当へ戻す (state 列と index を落とす)
        Schema::table('idempotency_keys', function (Blueprint $table): void {
            $table->dropIndex('idempotency_keys_state_expires_at_index');
            $table->dropColumn('state');
        });

        // 2. 旧実装が書いていた形の行を 1 件用意する (2xx の保存応答)。
        //    ★属性値は **Factory から生成**する (手組み禁止の規約)。
        //      旧スキーマへ挿入するため insert 自体は query builder で行い、
        //      落とした `state` だけを外して json 列を明示エンコードする。
        $apiKey = ApiKey::factory()->create();
        $attributes = IdempotencyKey::factory()
            ->forApiKey($apiKey)
            ->raw([
                'key' => 'legacy-key-1',
                'response_status' => 201,
                'response_body' => ['data' => ['id' => 7]],
            ]);
        unset($attributes['state']);
        $attributes['response_body'] = json_encode($attributes['response_body'], JSON_THROW_ON_ERROR);

        DB::table('idempotency_keys')->insert($attributes);

        // 3. 対象 migration の up() を直接実行する
        $migration = require database_path(
            'migrations/2026_08_09_000100_add_state_to_idempotency_keys_table.php',
        );
        $migration->up();

        // 4. 既存行は completed で、保存応答は無傷
        $row = IdempotencyKey::query()->where('key', 'legacy-key-1')->sole();
        expect($row->state)->toBe(IdempotencyState::Completed);
        expect($row->response_status)->toBe(201);
        expect($row->response_body)->toBe(['data' => ['id' => 7]]);
    });
    ```
    > pgsql は DDL がトランザクショナルなので、`RefreshDatabase` のロールバックで
    > スキーマ変更ごと巻き戻る (他テストへ漏れない)。
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
- **`IdempotencyKey::isExpired()` の呼び出し元** (施策 B で引数型を `?CarbonInterface` に変える):
  `IdempotentRequest::claim()` (新) と `tests/Feature/Api/IdempotencyTest.php` L149-155 の 2 箇所。
  引数を渡さない呼び出しは非互換にならない

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

    /** `idempotency_keys.key` は varchar(255)。DB に触る前にここで弾く */
    private const MAX_KEY_LENGTH = 255;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || trim($key) === '') {
            $response = $next($request);
            Assert::isInstanceOf($response, Response::class);

            return $response;
        }
        $key = trim($key);

        // ★キー長の検証 (現行に無い防御)。`key` 列は varchar(255) のため、
        //   255 超のヘッダをそのまま claim すると INSERT が 22001 で落ち、
        //   本処理を実行しないまま 500 になる (現行実装では save の QueryException を
        //   握り潰していたので「実行はされるが保存されない」という別の壊れ方だった)。
        //   DB に触る前に 422 で弾き、副作用も冪等行も作らない。
        if (mb_strlen($key) > self::MAX_KEY_LENGTH) {
            return ApiErrorResource::make(ApiError::fromCode(
                ApiErrorCode::ValidationFailed,
                details: ['errors' => ['Idempotency-Key' => [
                    'The Idempotency-Key header must not be longer than '.self::MAX_KEY_LENGTH.' characters.',
                ]]],
            ))->response()->setStatusCode(422);
        }

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
            $this->finalize($actor, $routeName, $this->loggableRouteName($request), $key,
                IdempotencyState::Indeterminate, null, $e::class);

            throw $e;
        }

        Assert::isInstanceOf($response, Response::class);

        $logRouteName = $this->loggableRouteName($request);
        if ($response instanceof JsonResponse && $response->isSuccessful()) {
            $this->finalize($actor, $routeName, $logRouteName, $key, IdempotencyState::Completed, $response);
        } else {
            $this->finalize($actor, $routeName, $logRouteName, $key, IdempotencyState::Indeterminate, null);
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
        string $routeName,      // 行のスコープに使う (path fallback を含む)
        string $logRouteName,   // ログに載せる識別子 (実値を含まない)
        string $key,
        IdempotencyState $state,
        ?JsonResponse $response = null,
        ?string $causeClass = null,
    ): void {
        /** @var array<string, mixed> $payload */
        $payload = ['state' => $state->value];
        if ($response instanceof JsonResponse) {
            $body = $this->decodeBody($response);
            $payload['response_status'] = $response->getStatusCode();
            // ★Eloquent\Builder::update() は **cast を通さない**
            //   (`toBase()->update()` へ素通し。vendor 実装で確認済み)。
            //   PHP 配列のままだと binding できず落ちるので、json 列へ入れる文字列を
            //   ここで明示的に組み立てる (`response_body` は null が正当な保存値)。
            $payload['response_body'] = $body === null
                ? null
                : json_encode($body, JSON_THROW_ON_ERROR);
        }

        try {
            $affected = $this->rowQuery($actor, $routeName, $key)
                ->where('state', IdempotencyState::Processing->value)
                ->update($payload);
        } catch (Throwable $e) {
            report(IdempotencyFinalizationFailure::make(
                routeName: $logRouteName,
                actorKind: $this->actorKind($actor),
                expectedState: $state->value,
                affectedRows: -1,
                causeClass: $e::class,
            ));

            return;
        }

        if ($affected !== 1) {
            report(IdempotencyFinalizationFailure::make(
                routeName: $logRouteName,
                actorKind: $this->actorKind($actor),
                expectedState: $state->value,
                affectedRows: $affected,
                causeClass: $causeClass,
            ));
        }
    }

    /**
     * 保存する応答 body。JSON が配列にならない場合は null
     * (現行 storeResponse() のロジックをそのまま移設する)。
     *
     * @return array<array-key, mixed>|null
     */
    private function decodeBody(JsonResponse $response): ?array
    {
        $bodyJson = $response->getContent();
        if (! is_string($bodyJson) || $bodyJson === '' || $bodyJson === 'null') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($bodyJson, true);

        return is_array($decoded) ? $decoded : null;
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

    /**
     * ログに載せる route 識別子。
     *
     * ★行のスコープに使う `$routeName` は名前が無ければ `$request->path()` に落ちるが、
     *   path には route parameter の**実値** (project id / item id) が入る。
     *   ログには実値を出さないため、名前が無いときは固定文字列にする
     *   (「載せるのは 5 項目だけ」という契約を守る)。
     */
    private function loggableRouteName(Request $request): string
    {
        $name = $request->route()?->getName();

        return is_string($name) && $name !== '' ? $name : '(unnamed-api-route)';
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
- [x] `decodeBody()` の戻り値は `array<array-key, mixed>|null` を PHPDoc で明示
      (`json_decode` の `mixed` を `is_array` で narrow する)
- [x] `json_encode(..., JSON_THROW_ON_ERROR)` は `string` を返す (level 10 で `string|false` にならない)

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
use Carbon\CarbonImmutable;
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
        // cutoff は開始時に 1 回だけ確定させ、全 state / 両テーブルで共有する
        $cutoff = CarbonImmutable::now();

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
- **`onOneServer()` は cache lock に依存する**。scheduler が動いていること・
  ロックを提供する cache driver が使われていることが前提で、満たさないと多重実行しうる
  (多重実行しても DELETE は冪等なので害は小さいが、`report()` が重複する)。
  この前提は既存の `billing:send-billing-reminders` / `render:reconcile-outputs` と同じで、
  本設計で新しく持ち込む前提ではない。**`docs/architecture.md` の cron 節に
  「scheduler 稼働 + ロック可能な cache driver が全 `onOneServer()` の前提」と 1 行で明記する**
  (施策 I)

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
//
// ★主張範囲を誇張しない: 本テストが固定するのは
//   「revoke 成功後、同じ token での再送は 401 になり、冪等行が 1 件も作られない」
//   という**観測**であって、「冪等層より前で止まった」ことの直接証明ではない
//   (実行位置の証明は TenantBoundaryOrderingTest / ApiGuardAllowlistInvariantTest の
//    順序 gate が担当する)。両者の組合せで免除の前提が成立する。
test('session revoke 後の同一 token 再送は 401 になり冪等行を 1 件も作らない', function (): void {
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

    // 観測上、revoke と再送のどちらでも冪等行は作られない
    // (= 配線しても再生応答が返る経路が無いという免除理由の裏取り)
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
- conflict_codes: idempotency_conflict, idempotency_in_progress, idempotency_indeterminate
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
 * ★**保証しないこと**: 本 gate はマーカー区間の 5 行しか読まない。文書の散文部分
 *   (決着写像表・クライアント向け指針) が実装とずれても検出しない。
 *   散文は人間のレビュー対象である (誇張しない)。
 */
```

テスト本体 (9 本):

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
9. `マーカー区間の conflict_codes は ApiErrorCode の 409 系 case と一致する` —
   `ApiErrorCode::cases()` のうち `defaultStatus() === 409` のものの `value` 集合と
   マーカーの列挙が一致すること (409 コードを足して文書に書き忘れる / 文書だけ増やす、の両方向を検出)

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている
- [x] null 安全 (`preg_match` の結果を配列アクセス前に検査)
- [x] Generics: `array<string, string>` でマーカー区間をパースした結果に PHPDoc

### テスト計画

上記 9 本。マーカーのパースは 1 つのヘルパー関数に集約し、
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
   **`/\bdefault\s*=>/` の regex** で判定する (空白差分に強くする)。
   `default =>` が現れたら fail (case 追加時に write/read の判断が強制されなくなるため)
5. `AppMcpTool::handle() は isWriteTool() による中央分岐を持つ` — ソース走査
   (`/->isWriteTool\(\s*\)/` の regex。字句検査であることと限界を docblock に明記)
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
| 1 | `claim 行は controller 実行前に作られ、同一リクエスト内で processing として読める` | テスト内で `idempotent` 付きの probe route を登録し、そのハンドラから同一キーの行を読んで state を応答に載せる → `processing` であること。★**commit の可視性までは証明しない** (`RefreshDatabase` は同一接続・同一トランザクション。pgsql の autocommit 前提は文書化に留める) |
| 2 | `処理中の同一キーは controller を実行せず 409 idempotency_in_progress` | `IdempotencyKeyFactory::processing()` で claim 済み行を作り、同一 actor / route / key / body で POST → 409 + `error.code` + `$project->items()->count() === 0` (**副作用ゼロ**) |
| 3 | `claim の INSERT は同一スコープで 1 本しか通らない` | 同一 (api_key, route, key) の `insertOrIgnore` を 2 回発行し、戻り値が 1 → 0、行数が 1。**unique 制約が調停者であることの直接証明** |
| 4 | `決着済み (completed) の行があれば controller を実行せず再生する` | completed 行を Factory で作り POST → 保存 body が返り、items が増えない、`Idempotent-Replayed: true` |
| 5 | `indeterminate の行があれば 409 idempotency_indeterminate で副作用ゼロ` | 同上の形 |
| 6 | `例外が middleware まで抜けた場合も indeterminate に確定する` | probe route のハンドラで `RuntimeException` を投げ、行が `indeterminate` になることを確認 (例外自体は Laravel の handler が 500 に変換する経路も併せて確認) |
| 7 | `期限切れの processing 行は削除されて再 claim できる` | expired + processing の行を作り POST → 201、行は 1 件で `completed` |
| 8 | `claim 行は api_key_id / user_id のどちらか一方だけが非 NULL` | API キー経路と OAuth 経路の両方で 1 回ずつ POST し、各行の排他を検証 |
| 9 | `409 の 3 コードはいずれも error envelope の形が同じ` | top-level は `error` のみ (`assertJsonCount(1)`)、`error` 配下は `code` / `message` / `status` の 3 キーのみ (`assertJsonCount(3, 'error')`)、値は `assertJsonPath` 3 本 |
| 10 | `finalize が失敗しても元の応答は壊れない` | probe route + 事前に claim 行を消す仕込みで `affected === 0` を作り、応答が 201 のまま / `report()` が呼ばれることを検証 |
| 11 | `completed の保存 body は DB へ往復してから再生される` | 初回 POST → **モデルを DB から読み直して** `response_body` が配列として復元できることを確認 → 再送で同じ body が返る。★`Builder::update()` が cast を通さないことに起因する「json 列へ PHP 配列を渡す」回帰を捕まえる |
| 12 | `255 文字を超える Idempotency-Key は 422 で弾かれ副作用も冪等行も作らない` | `str_repeat('a', 256)` を送り 422 `validation_failed` / `items` 0 件 / `IdempotencyKey` 0 件。境界値 255 は正常に通ることも併せて検証 |

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
  テスト 1 が「claim 行が controller 実行前に作られ、同一接続から processing として観測できる」
  ことを証明する。**並行安全性の根拠は 3 つの独立した事実の合成**である —
  (a) claim が本処理より前に発行される (テスト 1)、
  (b) 同一スコープの 2 本目の INSERT は unique 制約で落ちる (テスト 3)、
  (c) middleware を包む外側 transaction が無く pgsql の autocommit / read committed が効く
  (実コードの確認と DB の性質であって、テストによる保証ではない)。
  **「並行 2 本を実際に走らせた」とは書かない** (誇張しない)
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

1. **真の並行 2 本の実走**: テストは単一プロセスであり、実際に 2 プロセスを同時に
   走らせてはいない。並行安全性は次の 3 つの合成として主張する —
   「claim 行が本処理より前に作られ、同一接続から processing として観測できる (テスト 1)」
   +「同一スコープの 2 本目の INSERT を unique が落とす (テスト 3)」
   + **実行環境の前提**として「middleware を包む外側 transaction が無いこと (実コードで確認) と
   PostgreSQL の autocommit / read committed」。3 つ目は**テストによる証明ではない**
2. **プロセス跨ぎの可視性 / commit の証明**: `RefreshDatabase` 下では全操作が
   同一接続・同一トランザクション内で見えるため、**claim が commit されたことも、
   別接続から見えることも検証していない**。本番で claim が後着から見えるのは
   「middleware を包む外側 transaction が無い (実コードで確認) + pgsql の autocommit と
   read committed」という前提の帰結であって、テストによる保証ではない
3. **ログ内容の機械検査**: `report()` に載る情報が 5 項目に限られていることは
   設計とレビューで守る。**それを検査するテストは持たない** (ログ文字列を assert する
   テストを増やすより、専用例外に組み立てを閉じ込める方が壊れにくいと判断した)
4. **fatal error 時の claim 回収**: OOM / timeout / プロセス強制終了で `processing` が残る窓は
   閉じない。保持期間満了まで同一キーは 409 in_progress を返し続ける。
   観測は prune の state 別集計のみ
5. **`api/v1/` 以外の書込面**: 配線 gate は URI prefix `api/v1/` の変更系しか見ない。
   web (session + CSRF)、`oauth/*`、将来別 prefix の機械向け API には**沈黙する**
6. **MCP write tool の並行安全性**: `McpIdempotencyService::store()` の unique 握り潰しは
   **残る**。write tool が 0 本のため到達不能だが、「MCP も並行安全になった」とは書かない。
   trip-wire が最初の write tool 追加時に是正を強制する
7. **中央強制の意味的検証**: 施策 G のテスト 4 / 5 は字句検査であり、
   `handle()` の分岐が意味的に正しく動くことまでは静的に見ていない
8. **契約文書の散文**: parity gate はマーカー区間の 5 行しか読まない。
   決着写像表・クライアント向け指針が実装とずれても検出しない
9. **`api_key_id` / `user_id` の排他**: DB の CHECK 制約は持たない。
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
| 22 | migration の backfill を `indeterminate` に変える | `IdempotencyStateMigrationTest` の backfill 実挙動テスト |
| 23 | migration の `state` に DB default を残す | `IdempotencyStateMigrationTest` |
| 24 | `finalize()` の `json_encode` を外して配列のまま渡す | `IdempotencyConcurrentClaimTest` テスト 11 (保存/再生の往復) |
| 25 | `handle()` のキー長検証を外す | 同テスト 12 (256 文字で 500 になる) |
| 26 | `IdempotencyKey::isExpired()` の引数型を `?Carbon` に戻す | 同テスト 7 (期限切れ claim の再取得で TypeError) |
| 27 | `loggableRouteName()` を `$request->path()` に戻す | (機械検査しない。**レビュー観点として残す** — ログ内容の検査は本設計では持たない) |

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

## 実装差分 (git diff — app/ tests/ routes/ database/ config/)

```diff
diff --git a/app/Console/Commands/Operations/PruneIdempotencyKeysCommand.php b/app/Console/Commands/Operations/PruneIdempotencyKeysCommand.php
new file mode 100644
index 0000000..da85803
--- /dev/null
+++ b/app/Console/Commands/Operations/PruneIdempotencyKeysCommand.php
@@ -0,0 +1,88 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Console\Commands\Operations;
+
+use App\Enums\Idempotency\IdempotencyState;
+use App\Models\IdempotencyKey;
+use App\Models\McpIdempotencyKey;
+use Carbon\CarbonImmutable;
+use Illuminate\Console\Command;
+use RuntimeException;
+use Webmozart\Assert\Assert;
+
+/**
+ * 保持期間を過ぎた冪等キーを物理削除する (REST / MCP の両テーブル)。
+ *
+ * lazy delete (claim 時の期限切れ行削除) だけでは「二度と再送されなかったキー」が
+ * 残り続けて単調増加するため、日次で回収する。
+ *
+ * **集計の取り方**: 「先に COUNT して一括 DELETE」だと、その間の競合で
+ * 「実際に削除した行」の集計にならない。state ごとに条件付き DELETE を発行し、
+ * その affected rows を実績として使う。`cutoff` は開始時に 1 回だけ確定させ
+ * 全 state / 両テーブルで共有する (ずれると集計の意味が壊れる)。
+ *
+ * **監視対象**: `processing` のまま期限切れになった行。これは
+ * 「claim したのに確定できなかった要求」= プロセス強制終了か finalize 失敗の痕跡であり、
+ * 1 件でもあれば report() する (AI-CUE の運用アラート経路は report() のみ)。
+ */
+class PruneIdempotencyKeysCommand extends Command
+{
+    /** @var string */
+    protected $signature = 'idempotency:prune';
+
+    /** @var string */
+    protected $description = '保持期間を過ぎた冪等キー (REST / MCP) を物理削除する';
+
+    public function handle(): int
+    {
+        // cutoff は開始時に 1 回だけ確定させ、全 state / 両テーブルで共有する
+        $cutoff = CarbonImmutable::now();
+
+        /** @var array<string, int> $deleted */
+        $deleted = [];
+        foreach (IdempotencyState::cases() as $state) {
+            $deleted[$state->value] = self::deletedRows(
+                IdempotencyKey::query()
+                    ->where('state', $state->value)
+                    ->where('expires_at', '<=', $cutoff)
+                    ->delete(),
+            );
+        }
+
+        // MCP テーブルは state 列を持たない (状態機械は据え置き) ため 1 本
+        $deletedMcp = self::deletedRows(
+            McpIdempotencyKey::query()
+                ->where('expires_at', '<=', $cutoff)
+                ->delete(),
+        );
+
+        foreach ($deleted as $state => $count) {
+            $this->info("rest {$state}: {$count} 件削除");
+        }
+        $this->info("mcp: {$deletedMcp} 件削除");
+
+        $stalled = $deleted[IdempotencyState::Processing->value];
+        if ($stalled > 0) {
+            // 確定できなかった claim が実在する。件数だけを報告する
+            // (キー値・body は載せない)
+            report(new RuntimeException(
+                "確定できなかった冪等 claim を検出: processing のまま期限切れ count={$stalled}",
+            ));
+        }
+
+        return self::SUCCESS;
+    }
+
+    /**
+     * `Builder::delete()` の戻り値 (静的には mixed) を件数として narrow する。
+     * 想定外の型は Assert で fail-fast させる (0 件へ黙って倒さない)。
+     */
+    private static function deletedRows(mixed $affected): int
+    {
+        Assert::integer($affected, 'delete() must return the affected row count.');
+
+        return $affected;
+    }
+}
diff --git a/app/Enums/ApiErrorCode.php b/app/Enums/ApiErrorCode.php
index beb6400..ee238bc 100644
--- a/app/Enums/ApiErrorCode.php
+++ b/app/Enums/ApiErrorCode.php
@@ -30,6 +30,10 @@ enum ApiErrorCode: string
     case RateLimited = 'rate_limited';
     /** 同一 Idempotency-Key + 異なる request body の再送 (409) */
     case IdempotencyConflict = 'idempotency_conflict';
+    /** 同一 Idempotency-Key の別要求が処理中 (409)。少し待って再送するか新しいキーを使う */
+    case IdempotencyInProgress = 'idempotency_in_progress';
+    /** 同一 Idempotency-Key の先行要求が成功として記録されていない (409)。新しいキーを使う */
+    case IdempotencyIndeterminate = 'idempotency_indeterminate';
     case InternalServerError = 'internal_server_error';
 
     public function defaultStatus(): int
@@ -40,7 +44,9 @@ public function defaultStatus(): int
             self::NotFound => 404,
             self::ValidationFailed => 422,
             self::RateLimited => 429,
-            self::IdempotencyConflict => 409,
+            self::IdempotencyConflict,
+            self::IdempotencyInProgress,
+            self::IdempotencyIndeterminate => 409,
             self::InternalServerError => 500,
         };
     }
@@ -56,6 +62,8 @@ public function defaultMessage(): string
             self::ValidationFailed => 'The given data was invalid.',
             self::RateLimited => 'Too many requests.',
             self::IdempotencyConflict => 'Idempotency key conflict.',
+            self::IdempotencyInProgress => 'A request with this Idempotency-Key is still being processed.',
+            self::IdempotencyIndeterminate => 'The prior request with this Idempotency-Key did not complete successfully. Use a new Idempotency-Key.',
             self::InternalServerError => 'Internal server error.',
         };
     }
@@ -63,6 +71,11 @@ public function defaultMessage(): string
     /**
      * HTTP status → 正規コード。未対応 status (405 / 415 等) は internal_server_error に
      * collapse する (無関係なコードへの暗黙の別名化を防ぐ。HTTP status 自体は保持される)。
+     *
+     * ★409 は `IdempotencyConflict` に**据え置く**。409 系は 3 コードあるが、これは
+     *   「HTTP status しか手掛かりが無いときの既定名」であり、IdempotentRequest は
+     *   常に明示コードを構築する (本メソッドを経由しない)。したがって新コードが
+     *   暗黙に別名化されることはない。この非対称は意図的である。
      */
     public static function fromHttpStatus(int $status): self
     {
diff --git a/app/Enums/Idempotency/IdempotencyClaimStatus.php b/app/Enums/Idempotency/IdempotencyClaimStatus.php
new file mode 100644
index 0000000..bc1fff3
--- /dev/null
+++ b/app/Enums/Idempotency/IdempotencyClaimStatus.php
@@ -0,0 +1,24 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Idempotency;
+
+/**
+ * claim 試行の判定結果 (middleware の分岐は本 enum の match 1 段だけで完結させる)。
+ *
+ * Claimed 以外はすべて「本処理を実行しない」= 二重実行が起きないことが型で読める。
+ */
+enum IdempotencyClaimStatus
+{
+    /** 自分が claim を取得した。本処理を実行して finalize する */
+    case Claimed;
+    /** 完了済みの保存応答がある。再生する */
+    case Replay;
+    /** 同一キーで別 body。409 idempotency_conflict */
+    case Conflict;
+    /** 別リクエストが処理中。409 idempotency_in_progress */
+    case InProgress;
+    /** 決着不明で終わっている。409 idempotency_indeterminate */
+    case Indeterminate;
+}
diff --git a/app/Enums/Idempotency/IdempotencyState.php b/app/Enums/Idempotency/IdempotencyState.php
new file mode 100644
index 0000000..bd656c7
--- /dev/null
+++ b/app/Enums/Idempotency/IdempotencyState.php
@@ -0,0 +1,28 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Idempotency;
+
+/**
+ * 冪等キー行の状態 (REST `idempotency_keys`)。
+ *
+ * **決着は completed と indeterminate の 2 つだけで、release (再実行を許す) 経路は無い**。
+ * processing から戻る道は無く、唯一の解放は保持期間超過による物理削除
+ * (`idempotency:prune` コマンド、および claim 時の期限切れ行削除) である。
+ *
+ * - Processing:    claim 済み・本処理実行中。同一キーの後着は 409 idempotency_in_progress
+ * - Completed:     2xx JSON を得た。保存応答を再生する (Idempotent-Replayed: true)
+ * - Indeterminate: それ以外で終わった (非 2xx / 非 JSON / 例外が抜けた)。
+ *                  副作用の有無を middleware から断定できないため再実行せず
+ *                  409 idempotency_indeterminate を返す (クライアントは新しいキーを使う)
+ *
+ * 契約の正本は `docs/api-idempotency.md`。case 一覧と文書の parity は
+ * `tests/Architecture/IdempotencyContractParityTest.php` が機械固定する。
+ */
+enum IdempotencyState: string
+{
+    case Processing = 'processing';
+    case Completed = 'completed';
+    case Indeterminate = 'indeterminate';
+}
diff --git a/app/Enums/Security/IdempotencyWiringExemption.php b/app/Enums/Security/IdempotencyWiringExemption.php
new file mode 100644
index 0000000..56f4ce8
--- /dev/null
+++ b/app/Enums/Security/IdempotencyWiringExemption.php
@@ -0,0 +1,41 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Security;
+
+/**
+ * `idempotent` middleware を持たないことが正しいと裁定した route の分類語彙。
+ *
+ * deny-by-default: `api/v1/*` の変更系 route は `idempotent` をちょうど 1 本持つか、
+ * 本 enum + 30 文字以上の根拠で `tests/Architecture/IdempotentRouteCoverageTest.php` の
+ * 目録へ登録する (テストクラスへの {@see} 参照は app → tests の import を生むため書かない)。
+ *
+ * ★分類は「汎用に見えるものほど適用条件を狭く」定義する。
+ *   当てはまる case が無ければ、それは「idempotent を貼るべき route」である。
+ */
+enum IdempotencyWiringExemption: string
+{
+    /**
+     * 成功すると actor 自身の認証手段が失効する route。
+     *
+     * 適用条件: 成功後の同一 token での再送が**冪等層より前**の guard 段で 401 になり、
+     * 再生応答がクライアントへ返る経路が構造的に存在しない。
+     */
+    case SelfRevocationUnreachableReplay = 'self_revocation_unreachable_replay';
+
+    /**
+     * MCP transport の単一 endpoint。
+     *
+     * 適用条件: 冪等の単位が transport ではなく tool 呼び出しであり、強制は
+     * AppMcpTool の中央分岐 (`ToolName::isWriteTool()` 分岐) が担う。
+     */
+    case McpTransportPerToolEnforcement = 'mcp_transport_per_tool_enforcement';
+
+    /**
+     * vendor が登録する定数 405 (Method Not Allowed) スタブ。
+     *
+     * 適用条件: ハンドラが即座に固定 Response を返すだけで、本体処理へ到達しない。
+     */
+    case VendorMethodNotAllowedStub = 'vendor_method_not_allowed_stub';
+}
diff --git a/app/Exceptions/Idempotency/IdempotencyFinalizationFailure.php b/app/Exceptions/Idempotency/IdempotencyFinalizationFailure.php
new file mode 100644
index 0000000..2189ba3
--- /dev/null
+++ b/app/Exceptions/Idempotency/IdempotencyFinalizationFailure.php
@@ -0,0 +1,36 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Exceptions\Idempotency;
+
+use RuntimeException;
+
+/**
+ * claim 行を確定できなかったことの**観測専用**例外 (throw せず report() にだけ渡す)。
+ *
+ * ⚠ **元例外を previous に連結しない**。連結すると外部生成の可変文字列 (例外 message) が
+ * ログに載り、「載せてよい 5 項目だけ」という契約が壊れる (AGENTS.md の
+ * 「例外 message はログに載せない」と同型の判断)。載せるのは
+ * route 名 / actor 種別 / 期待した state / affected rows / 例外クラス名 の 5 つだけ。
+ * **Idempotency-Key の値・request body・保存応答 body は載せない**。
+ */
+final class IdempotencyFinalizationFailure extends RuntimeException
+{
+    public static function make(
+        string $routeName,
+        string $actorKind,
+        string $expectedState,
+        int $affectedRows,
+        ?string $causeClass = null,
+    ): self {
+        return new self(sprintf(
+            'Idempotency finalization failed. route=%s actor_kind=%s expected_state=%s affected_rows=%d cause=%s',
+            $routeName,
+            $actorKind,
+            $expectedState,
+            $affectedRows,
+            $causeClass ?? 'none',
+        ));
+    }
+}
diff --git a/app/Http/Middleware/IdempotentRequest.php b/app/Http/Middleware/IdempotentRequest.php
index e579626..66a66a2 100644
--- a/app/Http/Middleware/IdempotentRequest.php
+++ b/app/Http/Middleware/IdempotentRequest.php
@@ -6,37 +6,64 @@
 
 use App\Auth\Context\ApiActorContext;
 use App\Enums\ApiErrorCode;
+use App\Enums\Idempotency\IdempotencyClaimStatus;
+use App\Enums\Idempotency\IdempotencyState;
+use App\Exceptions\Idempotency\IdempotencyFinalizationFailure;
 use App\Http\Resources\ApiErrorResource;
 use App\Models\ApiKey;
 use App\Models\IdempotencyKey;
 use App\Support\Api\ApiError;
+use App\Support\Idempotency\IdempotencyClaimOutcome;
+use App\Support\Idempotency\IdempotencyHeaders;
+use App\Support\Idempotency\IdempotencyRetention;
+use Carbon\CarbonImmutable;
 use Closure;
 use Illuminate\Database\Eloquent\Builder;
-use Illuminate\Database\QueryException;
 use Illuminate\Http\JsonResponse;
 use Illuminate\Http\Request;
+use LogicException;
 use Symfony\Component\HttpFoundation\Response;
+use Throwable;
 use Webmozart\Assert\Assert;
 
 /**
  * Idempotency-Key middleware (REST API v1 の全 write エンドポイントに配線する)。
  *
- * - ヘッダ無し → 素通し (idempotency なし)
- * - 同一 key + 同一 request hash → 保存済みレスポンスを再生 (副作用は 1 回だけ)
- * - 同一 key + 異なる request hash → 409 idempotency_conflict
- * - 初回は controller 実行後、成功 (2xx JSON) レスポンスを保存する
- * - 保存行は TTL_HOURS で失効する (期限切れは未使用扱いで削除 → 再実行できる)
+ * **実行前 claim 方式**。本処理より先に `state = processing` の行を
+ * `insertOrIgnore` で確保し、既存の unique 2 本 (api_key_id / user_id) を**唯一の調停者**に
+ * する (cache ロック等の best-effort な二重機構は使わない)。決着は
+ * `completed` / `indeterminate` の 2 つだけで、**release (再実行を許す) 経路は持たない**。
+ *
+ * | 状況 | 応答 |
+ * |------|------|
+ * | ヘッダ無し | 素通し (冪等行を作らない) |
+ * | キーが 255 文字超 | 422 validation_failed (DB に触る前に弾く) |
+ * | 初回 (claim 成功) | 本処理を実行。2xx JSON なら completed、それ以外は indeterminate |
+ * | 同一キー + 同一 body + completed | 保存応答を再生 (`Idempotent-Replayed: true`) |
+ * | 同一キー + 異なる body | 409 idempotency_conflict |
+ * | 同一キー + processing | 409 idempotency_in_progress (本処理を実行しない) |
+ * | 同一キー + indeterminate | 409 idempotency_indeterminate (本処理を実行しない) |
+ *
+ * ⚠ **契約変更 (破壊的)**: 4xx / 5xx で終わった要求の後、**同じキーは再利用できない**
+ * (以前は再実行できた)。middleware は controller が副作用の前後どちらで失敗したかを
+ * 知らないため、再実行せず新しいキーを要求する。契約の正本は `docs/api-idempotency.md`。
  *
  * スコープは actor 単位 × route: API キー actor は (api_key_id, route_name, key)、
  * OAuth user-token actor は (user_id, route_name, key)。同一 key でも別 route なら独立。
+ * 保持期間は `config/idempotency.php` が唯一の正本 (クラス定数を復活させない)。
  *
- * 順序契約: auth → throttle → resolve.api-actor → api-key.ability → idempotent → controller
+ * 順序契約: auth → throttle → resolve.api-actor → api.project-in-org
+ * → api-key.ability → idempotent → controller
  * (api_actor attribute が前提。配線ミスは fail-closed で 500 + report)。
+ * **terminable にしない** (finalize は同一リクエストの応答確定前に完了させる)。
  */
 class IdempotentRequest
 {
-    /** 保存レスポンスの TTL (時間)。超過エントリは再送時に削除して作り直す */
-    public const TTL_HOURS = 24;
+    /** `idempotency_keys.key` は varchar(255)。DB に触る前にここで弾く */
+    private const MAX_KEY_LENGTH = 255;
+
+    /** claim の再試行回数 (期限切れ行の削除と再 claim の競合ぶん) */
+    private const CLAIM_ATTEMPTS = 2;
 
     /**
      * @param  Closure(Request): Response  $next
@@ -52,10 +79,22 @@ public function handle(Request $request, Closure $next): Response
         }
         $key = trim($key);
 
+        // キー長の検証。`key` 列は varchar(255) のため、255 超のヘッダをそのまま claim すると
+        // INSERT が 22001 で落ち、本処理を実行しないまま 500 になる。
+        // DB に触る前に 422 で弾き、副作用も冪等行も作らない。
+        if (mb_strlen($key) > self::MAX_KEY_LENGTH) {
+            return ApiErrorResource::make(ApiError::fromCode(
+                ApiErrorCode::ValidationFailed,
+                details: ['errors' => ['Idempotency-Key' => [
+                    'The Idempotency-Key header must not be longer than '.self::MAX_KEY_LENGTH.' characters.',
+                ]]],
+            ))->response()->setStatusCode(422);
+        }
+
         $actor = $request->attributes->get(ResolveApiActor::ATTRIBUTE_KEY);
         if (! $actor instanceof ApiActorContext) {
             // 配線ミス (resolve.api-actor middleware が前段に無い)。fail-closed で 500
-            report(new \LogicException(
+            report(new LogicException(
                 'IdempotentRequest middleware reached without ApiActorContext attribute. '
                 .'Ensure resolve.api-actor middleware runs first.',
             ));
@@ -67,37 +106,253 @@ public function handle(Request $request, Closure $next): Response
         $routeName = $request->route()?->getName() ?? $request->path();
         $requestHash = $this->hashRequest($request);
 
-        $existing = $this->scopedQuery($actor)
-            ->where('route_name', $routeName)
-            ->where('key', $key)
-            ->first();
+        $outcome = $this->claim($actor, $routeName, $key, $requestHash);
 
-        // TTL 超過エントリは未使用扱いで削除する (unique 制約を空けて再実行を許す)
-        if ($existing !== null && $existing->isExpired()) {
-            $existing->delete();
-            $existing = null;
-        }
+        return match ($outcome->status) {
+            IdempotencyClaimStatus::Claimed => $this->runAndFinalize($request, $next, $actor, $routeName, $key),
+            IdempotencyClaimStatus::Replay => $this->replayResponse($outcome->rowOrFail()),
+            IdempotencyClaimStatus::Conflict => $this->errorResponse(ApiErrorCode::IdempotencyConflict),
+            IdempotencyClaimStatus::InProgress => $this->errorResponse(ApiErrorCode::IdempotencyInProgress),
+            IdempotencyClaimStatus::Indeterminate => $this->errorResponse(ApiErrorCode::IdempotencyIndeterminate),
+        };
+    }
+
+    /**
+     * 実行**前**の claim。unique 制約が唯一の調停者で、cache ロック等の補助機構は使わない。
+     *
+     * 期限切れ行との競合があるため最大 2 回試行する。2 回とも決着しない場合は
+     * **fail-closed** (本処理を実行せず 409 in_progress) にする。
+     */
+    private function claim(
+        ApiActorContext $actor,
+        string $routeName,
+        string $key,
+        string $requestHash,
+    ): IdempotencyClaimOutcome {
+        for ($attempt = 0; $attempt < self::CLAIM_ATTEMPTS; $attempt++) {
+            $now = CarbonImmutable::now();
+
+            // insertOrIgnore: pgsql では `insert ... on conflict do nothing`。
+            // 例外を投げないため、外側のトランザクションを巻き込まない。
+            $inserted = IdempotencyKey::query()->insertOrIgnore([
+                ...$this->ownershipColumns($actor),
+                'route_name' => $routeName,
+                'key' => $key,
+                'request_hash' => $requestHash,
+                'state' => IdempotencyState::Processing->value,
+                'response_status' => null,
+                'response_body' => null,
+                'expires_at' => IdempotencyRetention::expiresAt($now),
+                // query builder insert は timestamps を自動付与しないので明示する
+                'created_at' => $now,
+            ]);
+
+            if ($inserted === 1) {
+                return IdempotencyClaimOutcome::claimed();
+            }
+
+            $existing = $this->rowQuery($actor, $routeName, $key)->first();
+            if ($existing === null) {
+                continue; // 別リクエストが期限切れ行を消した直後。もう 1 回だけ試す
+            }
+
+            if ($existing->isExpired($now)) {
+                // 期限切れ行の削除は **同一スコープ + expires_at 条件付き**で行う
+                // (主キー同一性クエリを書かない = ModelDirectFetchInvariantTest の母集団に入らない。
+                //  同時に、削除と削除の間に作られた新しい行を巻き込まない)
+                $this->rowQuery($actor, $routeName, $key)
+                    ->where('expires_at', '<=', $now)
+                    ->delete();
+
+                continue;
+            }
 
-        if ($existing !== null) {
             if ($existing->request_hash !== $requestHash) {
-                return ApiErrorResource::make(ApiError::fromCode(ApiErrorCode::IdempotencyConflict))
-                    ->response()->setStatusCode(409);
+                return IdempotencyClaimOutcome::conflict($existing);
             }
 
-            return $this->replayResponse($existing);
+            return match ($existing->state) {
+                IdempotencyState::Processing => IdempotencyClaimOutcome::inProgress($existing),
+                IdempotencyState::Completed => IdempotencyClaimOutcome::replay($existing),
+                IdempotencyState::Indeterminate => IdempotencyClaimOutcome::indeterminate($existing),
+            };
+        }
+
+        // 2 回とも決着しなかった = 期限切れ削除と再 claim が競り続けている。
+        // ここで本処理を走らせると二重実行になりうるので実行しない (fail-closed)。
+        return IdempotencyClaimOutcome::inProgress(new IdempotencyKey);
+    }
+
+    /**
+     * 本処理を実行し、結果を確定する。
+     *
+     * - 2xx JsonResponse → completed (応答を保存)
+     * - それ以外 / 例外 → indeterminate (release 経路は持たない)
+     *
+     * @param  Closure(Request): Response  $next
+     */
+    private function runAndFinalize(
+        Request $request,
+        Closure $next,
+        ApiActorContext $actor,
+        string $routeName,
+        string $key,
+    ): Response {
+        $logRouteName = $this->loggableRouteName($request);
+
+        try {
+            $response = $next($request);
+        } catch (Throwable $e) {
+            // 例外が middleware まで抜けた = 決着不明。indeterminate に倒してから再送出する
+            $this->finalize(
+                $actor,
+                $routeName,
+                $logRouteName,
+                $key,
+                IdempotencyState::Indeterminate,
+                causeClass: $e::class,
+            );
+
+            throw $e;
         }
 
-        $response = $next($request);
         Assert::isInstanceOf($response, Response::class);
 
-        // 成功 (2xx) の JSON レスポンスのみ保存する (失敗は保存しない = 再送で再実行できる)
         if ($response instanceof JsonResponse && $response->isSuccessful()) {
-            $this->storeResponse($actor, $routeName, $key, $requestHash, $response);
+            $this->finalize($actor, $routeName, $logRouteName, $key, IdempotencyState::Completed, $response);
+        } else {
+            $this->finalize($actor, $routeName, $logRouteName, $key, IdempotencyState::Indeterminate);
         }
 
         return $response;
     }
 
+    /**
+     * claim 行の確定 (state = processing の条件付き UPDATE)。
+     *
+     * **失敗しても応答は壊さない**。副作用は既に確定しており、ここで 500 に化けさせると
+     * クライアントに「失敗した」と誤認させ、より悪い再送を誘発する。
+     * 代わりに観測専用例外を report() する (載せる情報は 5 項目のみ)。
+     *
+     * @param  string  $routeName  行のスコープに使う (path fallback を含む)
+     * @param  string  $logRouteName  ログに載せる識別子 (route parameter の実値を含まない)
+     */
+    private function finalize(
+        ApiActorContext $actor,
+        string $routeName,
+        string $logRouteName,
+        string $key,
+        IdempotencyState $state,
+        ?JsonResponse $response = null,
+        ?string $causeClass = null,
+    ): void {
+        /** @var array<string, mixed> $payload */
+        $payload = ['state' => $state->value];
+        if ($response instanceof JsonResponse) {
+            $body = $this->decodeBody($response);
+            $payload['response_status'] = $response->getStatusCode();
+            // `Builder::update()` は **model の cast を通さない** (toBase()->update() へ素通し)
+            // ため、json 列へ入れる文字列をここで明示的に組み立てる
+            // (`response_body` は null が正当な保存値)。
+            //
+            // ⚠ 誇張しない: pgsql では `PostgresGrammar::prepareBindingsForUpdate()` が
+            //   配列を自動で json_encode するため、**この行を外しても pgsql では壊れない**
+            //   (T139 の mutation 24 で実測。赤くならなかった)。明示エンコードを残すのは
+            //   driver 非依存にすることと `JSON_THROW_ON_ERROR` で失敗を握り潰さないためで、
+            //   「これが無いと落ちる」という主張ではない。
+            $payload['response_body'] = $body === null
+                ? null
+                : json_encode($body, JSON_THROW_ON_ERROR);
+        }
+
+        try {
+            $affected = $this->rowQuery($actor, $routeName, $key)
+                ->where('state', IdempotencyState::Processing->value)
+                ->update($payload);
+        } catch (Throwable $e) {
+            report(IdempotencyFinalizationFailure::make(
+                routeName: $logRouteName,
+                actorKind: $this->actorKind($actor),
+                expectedState: $state->value,
+                affectedRows: -1,
+                causeClass: $e::class,
+            ));
+
+            return;
+        }
+
+        if ($affected !== 1) {
+            report(IdempotencyFinalizationFailure::make(
+                routeName: $logRouteName,
+                actorKind: $this->actorKind($actor),
+                expectedState: $state->value,
+                affectedRows: $affected,
+                causeClass: $causeClass,
+            ));
+        }
+    }
+
+    /**
+     * 保存する応答 body。JSON が配列にならない場合は null。
+     *
+     * @return array<array-key, mixed>|null
+     */
+    private function decodeBody(JsonResponse $response): ?array
+    {
+        $bodyJson = $response->getContent();
+        if (! is_string($bodyJson) || $bodyJson === '' || $bodyJson === 'null') {
+            return null;
+        }
+
+        /** @var mixed $decoded */
+        $decoded = json_decode($bodyJson, true);
+
+        return is_array($decoded) ? $decoded : null;
+    }
+
+    /** 保存応答の再生 (Idempotent-Replayed は **ここでだけ** 付ける) */
+    private function replayResponse(IdempotencyKey $existing): JsonResponse
+    {
+        $status = $existing->response_status;
+        Assert::notNull($status, 'A completed idempotency row must carry a response status.');
+        // response_body は null が正当な保存値 (2xx だが JSON 本体が配列でなかった場合)。
+        $body = $existing->response_body;
+
+        return (new JsonResponse($body, $status))
+            ->header('Content-Type', 'application/json')
+            ->header(IdempotencyHeaders::REPLAYED, IdempotencyHeaders::REPLAYED_VALUE);
+    }
+
+    private function errorResponse(ApiErrorCode $code): JsonResponse
+    {
+        return ApiErrorResource::make(ApiError::fromCode($code))
+            ->response()->setStatusCode($code->defaultStatus());
+    }
+
+    /**
+     * 所有権列 (api_key_id / user_id) を **1 箇所だけ**で組み立てる。
+     * どちらか一方だけが非 NULL になることは、この method と Feature テストが担保する
+     * (DB の CHECK 制約は持たない = 保証主体を誇張しない)。
+     *
+     * @return array{api_key_id: int|null, user_id: int|null}
+     */
+    private function ownershipColumns(ApiActorContext $actor): array
+    {
+        return $actor->apiKey instanceof ApiKey
+            ? ['api_key_id' => $actor->apiKey->id, 'user_id' => null]
+            : ['api_key_id' => null, 'user_id' => $actor->user->id];
+    }
+
+    /**
+     * actor スコープ + route + key の行 query (主キー同一性クエリは使わない)。
+     *
+     * @return Builder<IdempotencyKey>
+     */
+    private function rowQuery(ApiActorContext $actor, string $routeName, string $key): Builder
+    {
+        return $this->scopedQuery($actor)->where('route_name', $routeName)->where('key', $key);
+    }
+
     /**
      * actor 単位の保存行 lookup query (API キー actor = api_key_id、user-token actor = user_id)。
      *
@@ -114,51 +369,29 @@ private function scopedQuery(ApiActorContext $actor): Builder
             ->where('user_id', $actor->user->id);
     }
 
-    /** メソッド + パス + body で同一リクエストかを判定する */
-    private function hashRequest(Request $request): string
+    private function actorKind(ApiActorContext $actor): string
     {
-        return hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());
+        return $actor->apiKey instanceof ApiKey ? 'api_key' : 'user';
     }
 
-    private function replayResponse(IdempotencyKey $existing): JsonResponse
+    /**
+     * ログに載せる route 識別子。
+     *
+     * ★行のスコープに使う `$routeName` は名前が無ければ `$request->path()` に落ちるが、
+     *   path には route parameter の**実値** (project id / item id) が入る。
+     *   ログには実値を出さないため、名前が無いときは固定文字列にする
+     *   (「載せるのは 5 項目だけ」という契約を守る)。
+     */
+    private function loggableRouteName(Request $request): string
     {
-        return (new JsonResponse($existing->response_body, $existing->response_status))
-            ->header('Content-Type', 'application/json');
-    }
+        $name = $request->route()?->getName();
 
-    private function storeResponse(
-        ApiActorContext $actor,
-        string $routeName,
-        string $key,
-        string $requestHash,
-        JsonResponse $response,
-    ): void {
-        $bodyJson = $response->getContent();
-        $body = is_string($bodyJson) && $bodyJson !== '' && $bodyJson !== 'null'
-            ? json_decode($bodyJson, true)
-            : null;
-
-        $row = new IdempotencyKey([
-            'route_name' => $routeName,
-            'key' => $key,
-            'request_hash' => $requestHash,
-            'response_status' => $response->getStatusCode(),
-            'response_body' => is_array($body) ? $body : null,
-            'expires_at' => now()->addHours(self::TTL_HOURS),
-        ]);
-        // 所有権キーは $fillable 外のため明示代入 (mass assignment 二層防御)
-        $row->forceFill(
-            $actor->apiKey instanceof ApiKey
-                ? ['api_key_id' => $actor->apiKey->id]
-                : ['user_id' => $actor->user->id],
-        );
+        return is_string($name) && $name !== '' ? $name : '(unnamed-api-route)';
+    }
 
-        try {
-            $row->save();
-        } catch (QueryException $e) {
-            // 同時リクエストの unique 衝突は best-effort で無視する
-            // (勝者の保存行が再送時の再生に使われる)
-            report($e);
-        }
+    /** メソッド + パス + body で同一リクエストかを判定する */
+    private function hashRequest(Request $request): string
+    {
+        return hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());
     }
 }
diff --git a/app/Models/IdempotencyKey.php b/app/Models/IdempotencyKey.php
index 39263a5..8a84889 100644
--- a/app/Models/IdempotencyKey.php
+++ b/app/Models/IdempotencyKey.php
@@ -4,6 +4,8 @@
 
 namespace App\Models;
 
+use App\Enums\Idempotency\IdempotencyState;
+use Carbon\CarbonInterface;
 use Database\Factories\IdempotencyKeyFactory;
 use Illuminate\Database\Eloquent\Factories\HasFactory;
 use Illuminate\Database\Eloquent\Model;
@@ -15,10 +17,15 @@
  *
  * actor 単位の UNIQUE で同一 actor × route の再送を検知する: API キー actor は
  * (api_key_id, route_name, key)、OAuth user-token actor は (user_id, route_name, key)。
- * expires_at 超過エントリは未使用扱い (TTL は IdempotentRequest middleware が付与)。
+ * expires_at 超過エントリは未使用扱い (保持期間は config/idempotency.php が正本)。
  *
- * api_key_id / user_id は所有権キーのため $fillable 外。作成は relation 経由か
- * forceFill での明示代入のみ (IdempotentRequest::storeResponse)。
+ * 行は本処理の**前**に `processing` として claim され、決着時に `completed` /
+ * `indeterminate` へ確定する (release 経路は持たない)。状態遷移は
+ * `IdempotentRequest` の insertOrIgnore と条件付き UPDATE だけが行うため、
+ * **`state` は $fillable に入れない** (Eloquent 経由の mass assign 経路を作らない)。
+ *
+ * api_key_id / user_id は所有権キーのため $fillable 外。書き込みは
+ * IdempotentRequest::ownershipColumns() の単一構築点のみ。
  *
  * @property int $id
  * @property int|null $api_key_id
@@ -26,7 +33,8 @@
  * @property string $route_name
  * @property string $key
  * @property string $request_hash
- * @property int $response_status
+ * @property IdempotencyState $state
+ * @property int|null $response_status
  * @property array<array-key, mixed>|null $response_body
  * @property Carbon $expires_at
  * @property Carbon|null $created_at
@@ -52,6 +60,7 @@ class IdempotencyKey extends Model
     protected function casts(): array
     {
         return [
+            'state' => IdempotencyState::class,
             'response_status' => 'integer',
             'response_body' => 'array',
             'expires_at' => 'datetime',
@@ -60,8 +69,12 @@ protected function casts(): array
 
     /**
      * TTL 超過か。超過エントリは未使用扱い (再送時に削除して作り直す)。
+     *
+     * 引数は `CarbonInterface`。middleware は基準時刻に `CarbonImmutable` を使うが、
+     * `Illuminate\Support\Carbon` (mutable) は `CarbonImmutable` の親ではないため、
+     * `?Carbon` に狭めると実行時 TypeError になる。
      */
-    public function isExpired(?Carbon $now = null): bool
+    public function isExpired(?CarbonInterface $now = null): bool
     {
         return $this->expires_at->lessThanOrEqualTo($now ?? Carbon::now());
     }
diff --git a/app/Services/Mcp/McpIdempotencyService.php b/app/Services/Mcp/McpIdempotencyService.php
index 9282250..75bedfe 100644
--- a/app/Services/Mcp/McpIdempotencyService.php
+++ b/app/Services/Mcp/McpIdempotencyService.php
@@ -6,6 +6,7 @@
 
 use App\Exceptions\Mcp\IdempotencyConflictException;
 use App\Models\McpIdempotencyKey;
+use App\Support\Idempotency\IdempotencyRetention;
 use App\Values\Mcp\IdempotencyKey;
 use Carbon\CarbonImmutable;
 use Illuminate\Database\QueryException;
@@ -21,11 +22,17 @@
  * - 同一 user の純粋 retry は replay される
  * - refresh で access_token が回転しても同一 user の replay は継続する
  *   (user_id を key に使うので refresh の影響を受けない)
+ *
+ * 保持期間は `config/idempotency.php` (App\Support\Idempotency\IdempotencyRetention) が
+ * REST 側と共有する唯一の正本。**クラス定数での二重管理へ戻さないこと**
+ * (IdempotencyContractParityTest が TTL_HOURS 定数の不在を機械固定する)。
+ *
+ * ⚠ 本サービスの store() は unique 違反を握り潰す best-effort のままである
+ * (T139 では据え置き)。MCP write tool は現在 0 本で到達不能であり、最初の write tool 追加時に
+ * McpWriteToolIdempotencyEnforcementTest の trip-wire が是正作業を提示する。
  */
 final class McpIdempotencyService
 {
-    public const TTL_HOURS = 24;
-
     /**
      * 同一 key を探して replay 可能なら response body を返す。
      * payload mismatch は conflict 例外、TTL 超過は row 削除して null を返す。
@@ -91,6 +98,9 @@ public function store(
         array $payload,
         array $response,
     ): void {
+        // 基準時刻は 1 回だけ確定させ created_at / expires_at で共有する
+        $now = CarbonImmutable::now();
+
         try {
             // ownership キー (organization_id / user_id) は $fillable 外のため
             // named creation method 経由で明示代入する。
@@ -101,8 +111,8 @@ public function store(
                 idempotencyKey: $key->value,
                 payloadHash: self::hashPayload($payload),
                 responseBody: $response,
-                createdAt: CarbonImmutable::now(),
-                expiresAt: CarbonImmutable::now()->addHours(self::TTL_HOURS),
+                createdAt: $now,
+                expiresAt: IdempotencyRetention::expiresAt($now),
             );
         } catch (QueryException $e) {
             if (! self::isUniqueViolation($e)) {
diff --git a/app/Support/Idempotency/IdempotencyClaimOutcome.php b/app/Support/Idempotency/IdempotencyClaimOutcome.php
new file mode 100644
index 0000000..bf9b54f
--- /dev/null
+++ b/app/Support/Idempotency/IdempotencyClaimOutcome.php
@@ -0,0 +1,61 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Idempotency;
+
+use App\Enums\Idempotency\IdempotencyClaimStatus;
+use App\Models\IdempotencyKey;
+use Webmozart\Assert\Assert;
+
+/**
+ * claim 試行の結果 (status と対象行の組合せ不変条件を型で閉じる)。
+ *
+ * `__construct` は private で、named constructor 経由でしか作れない。
+ * これにより「Replay なのに row が無い」「Claimed なのに row を持っている」といった
+ * 無効な組合せを**構築できなくする** (呼び出し側で null 判定を書かないための境界)。
+ */
+final class IdempotencyClaimOutcome
+{
+    private function __construct(
+        public readonly IdempotencyClaimStatus $status,
+        private readonly ?IdempotencyKey $row,
+    ) {}
+
+    /** 自分が claim を取得した (行は自分が書いたので保持しない) */
+    public static function claimed(): self
+    {
+        return new self(IdempotencyClaimStatus::Claimed, null);
+    }
+
+    public static function replay(IdempotencyKey $row): self
+    {
+        return new self(IdempotencyClaimStatus::Replay, $row);
+    }
+
+    public static function conflict(IdempotencyKey $row): self
+    {
+        return new self(IdempotencyClaimStatus::Conflict, $row);
+    }
+
+    public static function inProgress(IdempotencyKey $row): self
+    {
+        return new self(IdempotencyClaimStatus::InProgress, $row);
+    }
+
+    public static function indeterminate(IdempotencyKey $row): self
+    {
+        return new self(IdempotencyClaimStatus::Indeterminate, $row);
+    }
+
+    /** row を持つ status からのみ呼ぶ (Claimed で呼ぶのは配線ミス) */
+    public function rowOrFail(): IdempotencyKey
+    {
+        Assert::notNull(
+            $this->row,
+            'IdempotencyClaimOutcome::rowOrFail() called on a status that carries no row.',
+        );
+
+        return $this->row;
+    }
+}
diff --git a/app/Support/Idempotency/IdempotencyHeaders.php b/app/Support/Idempotency/IdempotencyHeaders.php
new file mode 100644
index 0000000..31b6219
--- /dev/null
+++ b/app/Support/Idempotency/IdempotencyHeaders.php
@@ -0,0 +1,22 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Idempotency;
+
+/**
+ * 冪等応答のヘッダ名の唯一の正本。
+ *
+ * `Idempotent-Replayed` は **外部標準 (IETF の Idempotency-Key draft) には無い拡張**である。
+ * **再生応答にのみ**付与する (初回応答・409・素通しには付けない = クライアントが
+ * 「これは再生か」を判定できる)。名前と付与条件の契約は docs/api-idempotency.md、
+ * 機械固定は tests/Architecture/IdempotencyContractParityTest。
+ */
+final class IdempotencyHeaders
+{
+    /** 保存済み応答を再生したときにだけ付与する (値は 'true' 固定) */
+    public const REPLAYED = 'Idempotent-Replayed';
+
+    /** REPLAYED の値 (真偽の表現をここに固定し、呼び出し側で文字列を組まない) */
+    public const REPLAYED_VALUE = 'true';
+}
diff --git a/app/Support/Idempotency/IdempotencyRetention.php b/app/Support/Idempotency/IdempotencyRetention.php
new file mode 100644
index 0000000..6055c00
--- /dev/null
+++ b/app/Support/Idempotency/IdempotencyRetention.php
@@ -0,0 +1,38 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Idempotency;
+
+use Carbon\CarbonImmutable;
+use Webmozart\Assert\Assert;
+
+/**
+ * 冪等キーの保持期間 (config/idempotency.php) への型付き入口。
+ *
+ * REST (IdempotentRequest) と MCP (McpIdempotencyService) と prune コマンドが
+ * **同じ 1 箇所**からしか保持期間を読まないようにするための Support。
+ * クラス定数での二重管理へ戻さないこと (parity gate が定数の不在を固定する)。
+ *
+ * `cutoff()` は**作らない**。prune の cutoff は `CarbonImmutable::now()` そのものであり、
+ * Support に別名を置くと「保持期間の SoT」と関係のない薄い委譲が増える。
+ */
+final class IdempotencyRetention
+{
+    /** 保持期間 (時間)。config の型崩れは Assert で fail-fast する */
+    public static function hours(): int
+    {
+        /** @var mixed $hours */
+        $hours = config('idempotency.retention_hours');
+        Assert::integer($hours, 'config(idempotency.retention_hours) must be an int.');
+        Assert::greaterThan($hours, 0, 'config(idempotency.retention_hours) must be positive.');
+
+        return $hours;
+    }
+
+    /** 基準時刻からの失効時刻 (時単位のため *NoOverflow の対象外) */
+    public static function expiresAt(?CarbonImmutable $now = null): CarbonImmutable
+    {
+        return ($now ?? CarbonImmutable::now())->addHours(self::hours());
+    }
+}
diff --git a/config/idempotency.php b/config/idempotency.php
new file mode 100644
index 0000000..b406f11
--- /dev/null
+++ b/config/idempotency.php
@@ -0,0 +1,30 @@
+<?php
+
+declare(strict_types=1);
+
+/*
+|--------------------------------------------------------------------------
+| Idempotency (冪等キー) の契約値
+|--------------------------------------------------------------------------
+|
+| REST API v1 の `Idempotency-Key` と MCP 書き込み tool の冪等キーが共有する
+| **唯一の正本**。**env は使わない** — 保持期間は API の公開契約であり、
+| 環境ごとに変えてよい運用値ではない (環境差があると「24h 以内なら再送できる」が
+| 環境依存の嘘になる)。
+|
+| この値と docs/api-idempotency.md の記載の一致は
+| tests/Architecture/IdempotencyContractParityTest が deny-by-default で強制する。
+|
+| ⚠ この値を**利用者向けの外部公開文書**に載せるかはオーナー判断であり未決。
+|   ここで固定しているのは「実装と社内契約文書が同じ数字を指す」ことだけである。
+*/
+
+return [
+
+    /*
+    | 冪等キーの保持期間 (時間)。この時間を過ぎた行は
+    | idempotency:prune が物理削除し、同じキーを再び使えるようになる。
+    */
+    'retention_hours' => 24,
+
+];
diff --git a/database/factories/IdempotencyKeyFactory.php b/database/factories/IdempotencyKeyFactory.php
index afc0c5c..3a402a4 100644
--- a/database/factories/IdempotencyKeyFactory.php
+++ b/database/factories/IdempotencyKeyFactory.php
@@ -4,6 +4,7 @@
 
 namespace Database\Factories;
 
+use App\Enums\Idempotency\IdempotencyState;
 use App\Models\ApiKey;
 use App\Models\IdempotencyKey;
 use Illuminate\Database\Eloquent\Factories\Factory;
@@ -27,6 +28,7 @@ public function definition(): array
             'route_name' => 'api.v1.projects.items.store',
             'key' => (string) Str::uuid(),
             'request_hash' => hash('sha256', Str::random(32)),
+            'state' => IdempotencyState::Completed,
             'response_status' => 201,
             'response_body' => ['data' => ['id' => 1]],
             'expires_at' => Carbon::now()->addDay(),
@@ -39,6 +41,26 @@ public function forApiKey(ApiKey $apiKey): static
         return $this->state(fn () => ['api_key_id' => $apiKey->id]);
     }
 
+    /** claim 済み・本処理実行中 (応答未確定) として作る */
+    public function processing(): static
+    {
+        return $this->state(fn () => [
+            'state' => IdempotencyState::Processing,
+            'response_status' => null,
+            'response_body' => null,
+        ]);
+    }
+
+    /** 決着が不明 (非 2xx / 非 JSON / 例外) として作る */
+    public function indeterminate(): static
+    {
+        return $this->state(fn () => [
+            'state' => IdempotencyState::Indeterminate,
+            'response_status' => null,
+            'response_body' => null,
+        ]);
+    }
+
     /** TTL 超過 (未使用扱い) として作る */
     public function expired(?Carbon $expiresAt = null): static
     {
diff --git a/database/migrations/2026_08_09_000100_add_state_to_idempotency_keys_table.php b/database/migrations/2026_08_09_000100_add_state_to_idempotency_keys_table.php
new file mode 100644
index 0000000..50e0a5d
--- /dev/null
+++ b/database/migrations/2026_08_09_000100_add_state_to_idempotency_keys_table.php
@@ -0,0 +1,72 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Idempotency\IdempotencyState;
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Schema;
+
+/**
+ * 冪等キーに状態列を足し、実行**前** claim を可能にする。
+ *
+ * - `state`: processing / completed / indeterminate (App\Enums\Idempotency\IdempotencyState)
+ * - `response_status` を nullable 化する (claim 時点ではまだ応答が無いため)
+ *
+ * **既存行は削除せず `completed` へ backfill する**。現行実装は 2xx の JsonResponse しか
+ * 保存しない (旧 IdempotentRequest::handle の `$response->isSuccessful()` 分岐) ため、
+ * 既存行の決着は構造上すべて「成功」で既知である。ここを indeterminate に倒すと
+ * デプロイ直後の正当な再送 (成功の再生) が最大 24h ぶん 409 に化ける。
+ * 既存行を **削除**しないのは、デプロイを跨いだ再送が二重実行になるのを防ぐため。
+ *
+ * 既存の unique 2 本 (api_key_id / user_id の NULL distinct 前提) には触らない。
+ */
+return new class extends Migration
+{
+    public function up(): void
+    {
+        Schema::table('idempotency_keys', function (Blueprint $table): void {
+            $table->string('state', 24)->nullable()->after('request_hash');
+            $table->unsignedSmallInteger('response_status')->nullable()->change();
+        });
+
+        // 既存行の backfill (決着は既知 = completed)
+        DB::table('idempotency_keys')
+            ->whereNull('state')
+            ->update(['state' => IdempotencyState::Completed->value]);
+
+        // backfill 後に NOT NULL 化する。**DB default は付けない**
+        // (default があると「state を書き忘れた INSERT」が黙って completed になる)
+        Schema::table('idempotency_keys', function (Blueprint $table): void {
+            $table->string('state', 24)->nullable(false)->change();
+        });
+
+        // 期限切れ行の state 別 prune を index で支える
+        // (prune は `where state = ? and expires_at <= ?` で回す)
+        Schema::table('idempotency_keys', function (Blueprint $table): void {
+            $table->index(['state', 'expires_at'], 'idempotency_keys_state_expires_at_index');
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::table('idempotency_keys', function (Blueprint $table): void {
+            $table->dropIndex('idempotency_keys_state_expires_at_index');
+            $table->dropColumn('state');
+        });
+
+        // down では response_status を NOT NULL に戻さない:
+        // 戻す時点で claim 行 (response_status = null) が残っていると ALTER が失敗する。
+        // ロールバックの安全性を優先し、nullable のままにする (前方互換)。
+        //
+        // ⚠ **この migration は実質 irreversible である**。down() はスキーマを
+        //    「state 無し / response_status nullable」に戻すだけで、旧コードが前提とする
+        //    「全行が完了応答を持つ」状態には戻せない (processing / indeterminate 行が
+        //    response_status = null のまま残り、旧 replayResponse が null status で壊れる)。
+        //    **旧コードへ戻す前に `DELETE FROM idempotency_keys WHERE response_status IS NULL`
+        //    を人手で実行する**こと (削除しても失うのは未確定の claim だけで、
+        //    再送は再実行になる = ロールバック時点では旧契約と同じ挙動)。
+        //    手順は docs/api-idempotency.md の「ロールバック手順」節が正本。
+    }
+};
diff --git a/routes/console.php b/routes/console.php
index 93c9c11..3d37884 100644
--- a/routes/console.php
+++ b/routes/console.php
@@ -156,3 +156,19 @@
 })->purpose('期限切れのテイクアップロード予約を解放し S3 孤児オブジェクトを削除する');
 
 Schedule::command('capture:release-stale-upload-reservations')->everyTenMinutes()->onOneServer()->withoutOverlapping();
+
+/*
+|--------------------------------------------------------------------------
+| 冪等キーの保持期間 purge (T139)
+|--------------------------------------------------------------------------
+| 保持期間 (config idempotency.retention_hours) を超えた冪等キーを
+| REST / MCP 両テーブルから物理削除する。claim 時の lazy delete だけでは
+| 「二度と再送されなかったキー」が残り続け単調増加するため。
+|
+| **監視対象**: 本コマンドの report() (processing のまま期限切れ = 確定できなかった claim。
+| プロセス強制終了か finalize 失敗の痕跡)。
+|
+| ⚠ onOneServer() は **scheduler が動いていること + ロックを提供する cache driver** を
+|   前提にする (既存の billing:send-billing-reminders / render:reconcile-outputs と同じ前提)。
+*/
+Schedule::command('idempotency:prune')->daily()->onOneServer();
diff --git a/tests/Architecture/IdempotencyContractParityTest.php b/tests/Architecture/IdempotencyContractParityTest.php
new file mode 100644
index 0000000..afbf5ca
--- /dev/null
+++ b/tests/Architecture/IdempotencyContractParityTest.php
@@ -0,0 +1,142 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\ApiErrorCode;
+use App\Enums\Idempotency\IdempotencyState;
+use App\Http\Middleware\IdempotentRequest;
+use App\Services\Mcp\McpIdempotencyService;
+use App\Support\Idempotency\IdempotencyHeaders;
+
+/*
+ * 冪等契約の drift 検出 (deny-by-default)。
+ *
+ * config (実装の SoT) ⇔ docs/api-idempotency.md (契約文書) ⇔ ヘッダ名定数 ⇔ 状態 enum
+ * ⇔ ApiErrorCode の 409 系 の 5 者が同じことを言っていることを機械固定する。
+ *
+ * ★**保証しないこと**: 本 gate はマーカー区間の 5 行しか読まない。文書の散文部分
+ *   (決着写像表・クライアント向け指針) が実装とずれても検出しない。
+ *   散文は人間のレビュー対象である (誇張しない)。
+ */
+
+/** 契約文書の絶対パス */
+function idempotencyContractDocPath(): string
+{
+    return base_path('docs/api-idempotency.md');
+}
+
+/**
+ * マーカー区間 (`IDEMPOTENCY_CONTRACT:BEGIN` .. `:END`) を `key => value` にパースする。
+ * マーカーが欠けていれば例外 (黙って空配列を返さない = 検査が空振りしない)。
+ *
+ * @return array<string, string>
+ */
+function idempotencyContractMarkers(): array
+{
+    $path = idempotencyContractDocPath();
+    if (! is_file($path)) {
+        throw new RuntimeException("契約文書が存在しません: {$path}");
+    }
+
+    $content = (string) file_get_contents($path);
+    $matched = preg_match(
+        '/<!-- IDEMPOTENCY_CONTRACT:BEGIN -->(.*?)<!-- IDEMPOTENCY_CONTRACT:END -->/s',
+        $content,
+        $matches,
+    );
+    if ($matched !== 1) {
+        throw new RuntimeException('docs/api-idempotency.md に IDEMPOTENCY_CONTRACT マーカー区間がありません');
+    }
+
+    $parsed = [];
+    foreach (preg_split('/\R/u', $matches[1]) ?: [] as $line) {
+        if (preg_match('/^-\s*([a-z_]+):\s*(.+?)\s*$/', trim($line), $kv) === 1) {
+            $parsed[$kv[1]] = $kv[2];
+        }
+    }
+
+    return $parsed;
+}
+
+/**
+ * カンマ区切りのマーカー値を集合 (ソート済み list) に変換する。
+ *
+ * @return list<string>
+ */
+function idempotencyContractSet(string $value): array
+{
+    $items = array_values(array_filter(array_map('trim', explode(',', $value))));
+    sort($items);
+
+    return $items;
+}
+
+test('契約文書が存在しマーカー区間を持つ', function (): void {
+    expect(is_file(idempotencyContractDocPath()))->toBeTrue();
+
+    // マーカーごと消す差分は例外で赤くなる (VERIFICATION_COMMANDS マーカーと同じ運用)
+    $markers = idempotencyContractMarkers();
+
+    expect(array_keys($markers))->toEqualCanonicalizing([
+        'retention_hours', 'replay_header', 'states', 'terminal_states', 'conflict_codes',
+    ]);
+});
+
+test('マーカー区間の retention_hours は config と一致する', function (): void {
+    expect(idempotencyContractMarkers()['retention_hours'])
+        ->toBe((string) config('idempotency.retention_hours'));
+});
+
+test('config/idempotency.php は env() を使わない', function (): void {
+    // 保持期間は公開契約であり環境ごとに変えてよい運用値ではない
+    $source = (string) file_get_contents(config_path('idempotency.php'));
+
+    expect(str_contains($source, 'env('))->toBeFalse(
+        'config/idempotency.php で env() を使わないこと (環境差があると契約が環境依存の嘘になる)。',
+    );
+});
+
+test('retention_hours は 24 に pin されている', function (): void {
+    // 値そのものの pin。この数値を動かす差分は必ず本テストにも現れる
+    expect(config('idempotency.retention_hours'))->toBe(24);
+});
+
+test('マーカー区間の replay_header は IdempotencyHeaders::REPLAYED と一致する', function (): void {
+    expect(idempotencyContractMarkers()['replay_header'])->toBe(IdempotencyHeaders::REPLAYED);
+});
+
+test('マーカー区間の states は IdempotencyState の全 case と一致する', function (): void {
+    $documented = idempotencyContractSet(idempotencyContractMarkers()['states']);
+    $actual = array_map(static fn (IdempotencyState $s): string => $s->value, IdempotencyState::cases());
+    sort($actual);
+
+    expect($documented)->toBe($actual);
+});
+
+test('マーカー区間の terminal_states は completed / indeterminate の 2 つだけ', function (): void {
+    // release (再実行を許す) 経路を持たないという要件そのものの pin
+    expect(idempotencyContractSet(idempotencyContractMarkers()['terminal_states']))
+        ->toBe(['completed', 'indeterminate']);
+});
+
+test('保持期間のクラス定数が復活していない (二重管理への逆戻り検出)', function (): void {
+    foreach ([IdempotentRequest::class, McpIdempotencyService::class] as $class) {
+        expect((new ReflectionClass($class))->hasConstant('TTL_HOURS'))->toBeFalse(
+            "{$class} に TTL_HOURS 定数が復活しています。保持期間の SoT は config/idempotency.php です。",
+        );
+    }
+});
+
+test('マーカー区間の conflict_codes は ApiErrorCode の 409 系 case と一致する', function (): void {
+    $documented = idempotencyContractSet(idempotencyContractMarkers()['conflict_codes']);
+
+    $actual = array_values(array_map(
+        static fn (ApiErrorCode $c): string => $c->value,
+        array_filter(ApiErrorCode::cases(), static fn (ApiErrorCode $c): bool => $c->defaultStatus() === 409),
+    ));
+    sort($actual);
+
+    expect($documented)->toBe($actual,
+        '409 のコードを足したら docs/api-idempotency.md のマーカー区間にも書いてください '
+        .'(文書だけ増やす / コードだけ増やす の両方向を検出します)。');
+});
diff --git a/tests/Architecture/IdempotentRouteCoverageTest.php b/tests/Architecture/IdempotentRouteCoverageTest.php
new file mode 100644
index 0000000..551cfed
--- /dev/null
+++ b/tests/Architecture/IdempotentRouteCoverageTest.php
@@ -0,0 +1,338 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Security\IdempotencyWiringExemption;
+use App\Http\Middleware\IdempotentRequest;
+use Illuminate\Routing\Route as RoutingRoute;
+use Illuminate\Routing\Router;
+use Illuminate\Support\Facades\Route;
+use Illuminate\Support\Str;
+
+/*
+ * 冪等配線 (idempotent middleware) の付与漏れ invariant (deny-by-default)。
+ *
+ * 「`api/v1/*` の変更系 route は `idempotent` をちょうど 1 本持つ」を機械強制する。
+ * 持たないものは型付き分類 + 30 文字以上の根拠で exemption inventory へ登録させる。
+ *
+ * ★母集団は URI prefix `api/v1/` × 変更系メソッド。**vendor 登録の route も外さない**
+ *   (MCP transport の 2 本も母集団に入り、免除理由という形で根拠が残る)。
+ *   `oauth/*` を入れないのは RFC 6749/8628 の token endpoint が
+ *   Idempotency-Key を仕様に持たないため (スコープ外)。
+ *
+ * ★実効 middleware 列は Router::gatherRouteMiddleware() で取得する
+ *   (`route:list --json` は group 名が展開されず誤判定するため使わない)。
+ *
+ * ★**保証しないこと**: 本 gate は `api/v1/` 配下しか見ない。web (session + CSRF) の
+ *   書込 route、`oauth/*`、将来別 prefix で生える機械向け API には**沈黙する**。
+ *   別 prefix の API を足すときは母集団設計から見直すこと。
+ */
+
+/**
+ * 変更系 HTTP メソッド。
+ *
+ * @return list<string>
+ */
+function idempotentCoverageMutatingMethods(): array
+{
+    return ['POST', 'PUT', 'PATCH', 'DELETE'];
+}
+
+/**
+ * 母集団件数の**下限**。現在値ちょうど (exact fit)。
+ *
+ * ★母集団が 6 本しかないため、下限に余裕を持たせるとセレクタが壊れて
+ *   母集団が半減しても気づけない。exact fit なら prefix の typo や
+ *   メソッド集合の縮小が必ず赤になる。増やすときはこの数値を書き換えること。
+ */
+function idempotentCoverageRouteFloor(): int
+{
+    return 6;
+}
+
+/** exemption 件数の上限。**現在値ちょうど** (exact fit) */
+function idempotentCoverageExemptionCap(): int
+{
+    // ★余裕を 1 でも持たせると、その 1 本は「個別の根拠も再レビューも無しに
+    //   免除できる枠」になる。上げる前に必ず再検討すること。
+    return 3;
+}
+
+/**
+ * case 別上限 (分類の偏り検出。array_sum で全体 cap を導出しない)。
+ *
+ * @return array<string, int>
+ */
+function idempotentCoverageExemptionCapByCase(): array
+{
+    return [
+        IdempotencyWiringExemption::SelfRevocationUnreachableReplay->value => 1,
+        IdempotencyWiringExemption::McpTransportPerToolEnforcement->value => 1,
+        IdempotencyWiringExemption::VendorMethodNotAllowedStub->value => 1,
+    ];
+}
+
+/** exemption 理由の最低文字数 (「同上」「N/A」を機械的に弾く) */
+function idempotentCoverageReasonMinLength(): int
+{
+    return 30;
+}
+
+/**
+ * `idempotent` を持たないことが正しいと裁定した route の inventory。
+ *
+ * @return array<string, array{IdempotencyWiringExemption, string}>
+ */
+function idempotentCoverageExemptions(): array
+{
+    $revoke = IdempotencyWiringExemption::SelfRevocationUnreachableReplay;
+    $transport = IdempotencyWiringExemption::McpTransportPerToolEnforcement;
+    $stub = IdempotencyWiringExemption::VendorMethodNotAllowedStub;
+
+    return [
+        'api.v1.me.session.revoke' => [$revoke,
+            'RevokeSessionController::destroy() は actor 自身の OAuth session を失効させる。'
+            .'成功後は同じ Bearer token が auth:api-oauth / resolve.api-actor の段で 401 になるため、'
+            .'idempotent を配線しても保存応答がクライアントへ返る経路が構造的に存在しない。'
+            .'加えて失効操作自体が冪等 (session が既に無くても同じ結果)。'
+            .'この前提は IdempotencyExemptionPremiseTest が behavioral に固定する。'],
+
+        'POST /api/v1/mcp' => [$transport,
+            'Laravel\Mcp\Server\Registrar::web() が登録する MCP transport の単一 endpoint。'
+            .'冪等の単位は transport ではなく tool 呼び出しであり、書き込み tool への'
+            .'idempotency_key 必須化は AppMcpTool::handle() の中央分岐が担う'
+            .'(McpWriteToolIdempotencyEnforcementTest が強制)。'],
+
+        'DELETE /api/v1/mcp' => [$stub,
+            'Registrar::web() が登録する定数 405 スタブ (Allow: POST)。MCP の session 終了 API'
+            .'非対応の表明であり、ハンドラは本体処理へ一切到達しないため冪等性の概念が無い。'],
+    ];
+}
+
+/**
+ * 解決後 middleware 列 (Closure を除いた文字列 entry のみ)。
+ *
+ * @return list<string>
+ */
+function idempotentCoverageResolvedMiddleware(RoutingRoute $route): array
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
+/** 実効 middleware 列に含まれる IdempotentRequest の本数 */
+function idempotentCoverageEntryCount(RoutingRoute $route): int
+{
+    $count = 0;
+    foreach (idempotentCoverageResolvedMiddleware($route) as $entry) {
+        if (is_a(Str::before($entry, ':'), IdempotentRequest::class, true)) {
+            $count++;
+        }
+    }
+
+    return $count;
+}
+
+/** route の inventory キー (名前があれば名前、無ければ `{METHOD} /{uri}`) */
+function idempotentCoverageRouteLabel(RoutingRoute $route): string
+{
+    $name = $route->getName();
+    if ($name !== null && $name !== '') {
+        return $name;
+    }
+
+    $methods = array_values(array_diff($route->methods(), ['HEAD']));
+
+    return implode('|', $methods).' /'.$route->uri();
+}
+
+/** @return list<RoutingRoute> 母集団 (api/v1/ 配下の変更系) */
+function idempotentCoverageRoutes(): array
+{
+    $mutating = idempotentCoverageMutatingMethods();
+    $selected = [];
+
+    foreach (Route::getRoutes() as $route) {
+        if (! str_starts_with($route->uri(), 'api/v1/')) {
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
+function idempotentCoverageViolations(): array
+{
+    $inventory = idempotentCoverageExemptions();
+    $violations = [];
+
+    foreach (idempotentCoverageRoutes() as $route) {
+        $label = idempotentCoverageRouteLabel($route);
+        $count = idempotentCoverageEntryCount($route);
+
+        if ($count === 1) {
+            continue;
+        }
+        if ($count === 0 && array_key_exists($label, $inventory)) {
+            continue;
+        }
+
+        $violations[] = $count === 0
+            ? "{$label}: idempotent が無く exemption inventory にも未登録"
+            : "{$label}: idempotent が {$count} 本ある";
+    }
+
+    return $violations;
+}
+
+test('母集団が下限を下回らない (セレクタの空振り検出)', function (): void {
+    $count = count(idempotentCoverageRoutes());
+
+    expect($count)->toBeGreaterThanOrEqual(
+        idempotentCoverageRouteFloor(),
+        "api/v1 の変更系 route が {$count} 件しか検出されませんでした。"
+        .'prefix / メソッド集合のセレクタが空振りしている可能性があります。',
+    );
+});
+
+test('母集団の変更系 route は idempotent をちょうど 1 本持つか exemption に明示分類されている (未知は fail)', function (): void {
+    expect(idempotentCoverageViolations())->toBe([],
+        'api/v1 の変更系 route の idempotent 付与が不正です。idempotent を配線するか、'
+        .'配線しないことが正しい理由を idempotentCoverageExemptions() に'
+        .'IdempotencyWiringExemption + 具体的根拠付きで登録してください。'
+        .PHP_EOL.implode(PHP_EOL, idempotentCoverageViolations()));
+});
+
+test('exemption inventory の key は現存する母集団 route (stale 検出)', function (): void {
+    $labels = [];
+    foreach (idempotentCoverageRoutes() as $route) {
+        $labels[idempotentCoverageRouteLabel($route)] = true;
+    }
+
+    $stale = [];
+    foreach (array_keys(idempotentCoverageExemptions()) as $key) {
+        if (! isset($labels[$key])) {
+            $stale[] = $key;
+        }
+    }
+
+    expect($stale)->toBe([],
+        'exemption inventory に現存しない route ラベル (削除/rename 済、または idempotent 付与済で'
+        .'exemption が不要になったもの) があります: '.implode(', ', $stale));
+});
+
+test('exemption inventory の値は enum + 実質的な理由文字列', function (): void {
+    $minLength = idempotentCoverageReasonMinLength();
+    $violations = [];
+
+    foreach (idempotentCoverageExemptions() as $label => [$exemption, $reason]) {
+        if (! $exemption instanceof IdempotencyWiringExemption) {
+            $violations[] = "{$label}: 第 1 要素が IdempotencyWiringExemption ではありません";
+        }
+        if (mb_strlen($reason) < $minLength) {
+            $violations[] = "{$label}: 理由が {$minLength} 文字未満です (「同上」「N/A」で埋める運用を止めます)";
+        }
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('exemption 件数が上限を超えない (形骸化ガード)', function (): void {
+    $count = count(idempotentCoverageExemptions());
+
+    expect($count)->toBeLessThanOrEqual(
+        idempotentCoverageExemptionCap(),
+        "exemption が {$count} 件あります。idempotent を貼るべき route を exemption で"
+        .'逃がしている可能性があります (上限を上げる前に必ず再検討すること)。',
+    );
+});
+
+test('exemption inventory の key は idempotent を 1 本も持たない (死んだ exemption の検出)', function (): void {
+    // ★「ちょうど 1 本 or exemption」検査は count === 1 で先に continue するため、
+    //   *配線済みなのに exemption にも登録されている* 状態を検出できない。
+    //   放置すると「もう不要な免除理由」が台帳に溜まり、次に読む人を誤らせる。
+    $inventory = idempotentCoverageExemptions();
+    $violations = [];
+
+    foreach (idempotentCoverageRoutes() as $route) {
+        $label = idempotentCoverageRouteLabel($route);
+        if (! array_key_exists($label, $inventory)) {
+            continue;
+        }
+
+        $count = idempotentCoverageEntryCount($route);
+        if ($count !== 0) {
+            $violations[] = "{$label}: idempotent が {$count} 本付いているのに exemption にも登録されています";
+        }
+    }
+
+    expect($violations)->toBe([],
+        'idempotent を配線したら exemption inventory から削除してください。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('exemption の case 別件数が上限を超えない (分類の偏り検出)', function (): void {
+    // ★走査対象は **enum の全 case**。使用中の case だけを見ると、
+    //   「新しい case を足したが cap を決めていない」状態を検出できない。
+    $caps = idempotentCoverageExemptionCapByCase();
+
+    $counts = [];
+    foreach (IdempotencyWiringExemption::cases() as $case) {
+        $counts[$case->value] = 0;
+    }
+    foreach (idempotentCoverageExemptions() as [$exemption, $reason]) {
+        $counts[$exemption->value]++;
+    }
+
+    $violations = [];
+    foreach ($counts as $case => $count) {
+        if (! array_key_exists($case, $caps)) {
+            $violations[] = "{$case}: idempotentCoverageExemptionCapByCase() に上限が登録されていません";
+
+            continue;
+        }
+        if ($count > $caps[$case]) {
+            $violations[] = "{$case}: {$count} 件 (上限 {$caps[$case]})";
+        }
+    }
+
+    foreach (array_keys($caps) as $case) {
+        if (! array_key_exists($case, $counts)) {
+            $violations[] = "{$case}: enum に存在しない case の上限が残っています";
+        }
+    }
+
+    expect($violations)->toBe([],
+        'exemption の case 別件数が上限を超えました。上限を上げる前に、'
+        .'その case へ落とした route が本当に idempotent 不要かを 1 本ずつ再検討してください。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('負のコントロール: idempotent 無しの api/v1 変更系 route を検出する', function (): void {
+    // 目録にも無く idempotent も無い route を実行時に足すと、検出器が違反として拾う
+    Route::post('api/v1/__idempotency_negative_control__', fn (): string => 'ok');
+
+    expect(idempotentCoverageViolations())
+        ->toContain('POST /api/v1/__idempotency_negative_control__: idempotent が無く exemption inventory にも未登録');
+});
+
+test('正のコントロール: idempotent 付きの api/v1 変更系 route は違反にならない', function (): void {
+    Route::post('api/v1/__idempotency_positive_control__', fn (): string => 'ok')
+        ->middleware('idempotent');
+
+    expect(idempotentCoverageViolations())->toBe([]);
+});
diff --git a/tests/Architecture/McpWriteToolIdempotencyEnforcementTest.php b/tests/Architecture/McpWriteToolIdempotencyEnforcementTest.php
new file mode 100644
index 0000000..8777793
--- /dev/null
+++ b/tests/Architecture/McpWriteToolIdempotencyEnforcementTest.php
@@ -0,0 +1,109 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Mcp\ToolName;
+use App\Mcp\Servers\AppMcpServer;
+use App\Mcp\Tools\AppMcpTool;
+use Laravel\Mcp\Server\Tool;
+
+/*
+ * MCP 書き込み tool の冪等キー必須化を**中央 1 箇所でしか判断させない** invariant。
+ *
+ * ★本 gate は「据え置きの機械化」でもある。aicue の write tool は現在 0 本であり
+ *   (ToolName の 4 case はすべて read)、MCP 側の状態機械 (reserve/complete) と
+ *   T109 (replay 判定がリソース解決より前) は**意図的に据え置いている**。
+ *   最初の write tool が追加された瞬間に trip-wire が赤くなり、同時にやるべき作業が
+ *   失敗メッセージとして提示される。
+ *
+ * ★**保証しないこと**: handle() 内の中央分岐の実在確認は**字句検査**である。
+ *   分岐の意味 (実際に replay/store が呼ばれるか) までは静的に見ていない。
+ *   write tool が生えた時点で behavioral テストを足すこと (trip-wire がそれを強制する)。
+ *
+ * ★ToolNameInvariantTest とは役割を分ける: 既存は「enum ⇔ サーバ登録の 1:1」と
+ *   「全 tool が AppMcpTool を継承」。本 gate は「中央強制を迂回できないこと」と
+ *   「据え置きの trip-wire」。重複させない。
+ */
+
+/**
+ * AppMcpServer に登録された tool class 一覧を reflection で取得する。
+ * (Pest のグローバル関数はファイル間で共有されないため本ファイルにも置く。
+ *  名前は ToolNameInvariantTest の registeredMcpToolClasses() と衝突しないようにする)
+ *
+ * @return list<class-string<Tool>>
+ */
+function mcpEnforcementRegisteredToolClasses(): array
+{
+    $reflection = new ReflectionClass(AppMcpServer::class);
+    $property = $reflection->getProperty('tools');
+
+    /** @var list<class-string<Tool>> $tools */
+    $tools = $property->getValue($reflection->newInstanceWithoutConstructor());
+
+    return $tools;
+}
+
+/** 対象クラスのソース全文 */
+function mcpEnforcementSourceOf(string $class): string
+{
+    $file = (new ReflectionClass($class))->getFileName();
+    expect($file)->toBeString();
+
+    $source = file_get_contents((string) $file);
+    expect($source)->toBeString();
+
+    return (string) $source;
+}
+
+test('登録 tool の母集団が下限を下回らない (空振り防止)', function (): void {
+    expect(count(mcpEnforcementRegisteredToolClasses()))->toBeGreaterThanOrEqual(4);
+    expect(count(ToolName::cases()))->toBeGreaterThanOrEqual(4);
+});
+
+test('全 tool の handle() は AppMcpTool が宣言したものである (override による迂回の禁止)', function (): void {
+    $violations = [];
+
+    foreach (mcpEnforcementRegisteredToolClasses() as $class) {
+        $declaring = (new ReflectionMethod($class, 'handle'))->getDeclaringClass()->getName();
+        if ($declaring !== AppMcpTool::class) {
+            $violations[] = "{$class}: handle() が {$declaring} で宣言されています";
+        }
+    }
+
+    expect($violations)->toBe([],
+        'handle() を override すると認可・冪等・ログの中央強制を迂回できます。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('AppMcpTool::handle() は final である', function (): void {
+    expect((new ReflectionMethod(AppMcpTool::class, 'handle'))->isFinal())->toBeTrue();
+});
+
+test('ToolName::isWriteTool() は網羅 match で書かれている (default を持たない)', function (): void {
+    // default => があると case 追加時に write/read の判断が強制されなくなる
+    expect(preg_match('/\bdefault\s*=>/', mcpEnforcementSourceOf(ToolName::class)))->toBe(0,
+        'ToolName に default => が現れました。isWriteTool() の match は網羅で書き、'
+        .'tool 追加時に write/read の判断を強制してください。');
+});
+
+test('AppMcpTool::handle() は isWriteTool() による中央分岐を持つ', function (): void {
+    // ★字句検査である (分岐の意味までは見ていない)。限界は本ファイル冒頭に明記。
+    expect(preg_match('/->isWriteTool\(\s*\)/', mcpEnforcementSourceOf(AppMcpTool::class)))->toBe(1);
+});
+
+test('MCP write tool は 0 本である (据え置きの明示的な pin)', function (): void {
+    $writeTools = array_values(array_filter(
+        ToolName::cases(),
+        static fn (ToolName $t): bool => $t->isWriteTool(),
+    ));
+
+    expect($writeTools)->toBe([],
+        '初めての MCP write tool を追加しました。次を**同じ PR で**行ってください:'
+        .PHP_EOL.'1. McpIdempotencyService を reserve/complete/indeterminate へ再構成する'
+        .'(現在の store() は unique 違反を握り潰しており、並行呼び出しで二重実行が起きる)'
+        .PHP_EOL.'2. T109 を解消する (AppMcpTool::handle() の冪等判定を runTool() の'
+        .'リソース解決より後へ。REST 側の api.project-in-org < idempotent と同型のハザード)'
+        .PHP_EOL.'3. write tool の idempotency_key 必須化・replay・conflict の behavioral テストを追加する'
+        .PHP_EOL.'4. 本 pin をその時点の write tool 一覧へ更新する'
+        .PHP_EOL.'設計の根拠: devnotes/20260809-0027-idempotency-concurrent-claim/');
+});
diff --git a/tests/Feature/Api/IdempotencyConcurrentClaimTest.php b/tests/Feature/Api/IdempotencyConcurrentClaimTest.php
new file mode 100644
index 0000000..18d5b28
--- /dev/null
+++ b/tests/Feature/Api/IdempotencyConcurrentClaimTest.php
@@ -0,0 +1,422 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Idempotency\IdempotencyState;
+use App\Models\IdempotencyKey;
+use App\Models\Project;
+use Illuminate\Contracts\Debug\ExceptionHandler;
+use Illuminate\Support\Facades\Auth;
+use Illuminate\Support\Facades\Route;
+use Mockery\MockInterface;
+use Tests\Support\OAuthTestHelpers;
+
+/*
+ * 冪等キーの「実行前 claim」契約 (T139)。
+ *
+ * 旧実装は本処理の**後**に保存していたため、同一キーの並行 2 本が両方 controller を
+ * 実行し、後着の unique 違反を握り潰していた。本テストは
+ *   (a) claim 行が本処理より**前**に作られること (テスト 1)
+ *   (b) 同一スコープの 2 本目の INSERT を unique が落とすこと (テスト 3)
+ * を固定する。
+ *
+ * ★**保証しないこと**: PHP のテストは単一プロセスであり、真の並行 2 本は走らせていない。
+ *   `RefreshDatabase` 下では全操作が同一接続・同一トランザクション内で見えるため、
+ *   claim の commit も別接続からの可視性も検証していない。本番で後着から claim が
+ *   見えるのは「middleware を包む外側 transaction が無い + pgsql の autocommit /
+ *   read committed」という前提の帰結であって、テストによる保証ではない。
+ */
+
+/** report() 経路 (運用アラート) を観測する spy を差し込む */
+function spyOnIdempotencyExceptionHandler(): MockInterface
+{
+    $handler = Mockery::spy(ExceptionHandler::class);
+    app()->instance(ExceptionHandler::class, $handler);
+
+    return $handler;
+}
+
+/**
+ * IdempotentRequest::hashRequest() と同じ規則で request hash を組む
+ * (メソッド + パス + body の sha256)。Factory で「同一 body の先行要求」を作るために使う。
+ *
+ * @param  array<string, mixed>  $payload
+ */
+function idempotencyRequestHashFor(string $method, string $path, array $payload): string
+{
+    return hash(
+        'sha256',
+        $method.'|'.$path.'|'.json_encode($payload, JSON_THROW_ON_ERROR),
+    );
+}
+
+/**
+ * `idempotent` を含む本番同等の middleware 列を持つ probe route を登録する。
+ *
+ * 実 route (items.store) では controller 実行中の観測や例外送出ができないため、
+ * middleware の挙動だけを見たいテストで使う。URI はテストごとに固有にする
+ * (`--parallel` でも衝突しないよう呼び出し側が suffix を渡す)。
+ *
+ * @param  Closure(): mixed  $handler
+ */
+function registerIdempotencyProbeRoute(string $suffix, Closure $handler): string
+{
+    $uri = "api/v1/__idempotency_probe_{$suffix}__";
+
+    Route::post($uri, $handler)
+        ->middleware(['auth:api-key,api-oauth', 'resolve.api-actor', 'idempotent'])
+        ->name("api.v1.__idempotency_probe_{$suffix}__");
+
+    return '/'.$uri;
+}
+
+test('claim 行は controller 実行前に作られ、同一リクエスト内で processing として読める', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    [, $plain] = issueApiKey($organization, $owner);
+
+    $url = registerIdempotencyProbeRoute('claim_visible', function (): array {
+        $row = IdempotencyKey::query()->sole();
+
+        return ['data' => ['state' => $row->state->value]];
+    });
+
+    $this->withHeaders([
+        'Authorization' => "Bearer {$plain}",
+        'Idempotency-Key' => 'probe-claim-visible',
+    ])->postJson($url)
+        ->assertOk()
+        ->assertJsonPath('data.state', IdempotencyState::Processing->value);
+
+    // 応答確定後は completed になっている
+    expect(IdempotencyKey::query()->sole()->state)->toBe(IdempotencyState::Completed);
+});
+
+test('処理中の同一キーは controller を実行せず 409 idempotency_in_progress', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    [$apiKey, $plain] = issueApiKey($organization, $owner);
+    $payload = ['name' => '並行アイテム'];
+
+    $requestHash = idempotencyRequestHashFor(
+        'POST',
+        "api/v1/projects/{$project->id}/items",
+        $payload,
+    );
+
+    IdempotencyKey::factory()->forApiKey($apiKey)->processing()->create([
+        'route_name' => 'api.v1.projects.items.store',
+        'key' => 'in-progress-key',
+        'request_hash' => $requestHash,
+    ]);
+
+    $this->withHeaders([
+        'Authorization' => "Bearer {$plain}",
+        'Idempotency-Key' => 'in-progress-key',
+    ])->postJson("/api/v1/projects/{$project->id}/items", $payload)
+        ->assertStatus(409)
+        ->assertJsonPath('error.code', 'idempotency_in_progress');
+
+    // 副作用ゼロ (controller は 1 度も走っていない)
+    expect($project->items()->count())->toBe(0);
+    expect(IdempotencyKey::query()->sole()->state)->toBe(IdempotencyState::Processing);
+});
+
+test('claim の INSERT は同一スコープで 1 本しか通らない (unique 制約が調停者)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    [$apiKey] = issueApiKey($organization, $owner);
+
+    $row = [
+        'api_key_id' => $apiKey->id,
+        'user_id' => null,
+        'route_name' => 'api.v1.projects.items.store',
+        'key' => 'race-key',
+        'request_hash' => str_repeat('a', 64),
+        'state' => IdempotencyState::Processing->value,
+        'response_status' => null,
+        'response_body' => null,
+        'expires_at' => now()->addDay(),
+        'created_at' => now(),
+    ];
+
+    expect(IdempotencyKey::query()->insertOrIgnore($row))->toBe(1);
+    expect(IdempotencyKey::query()->insertOrIgnore($row))->toBe(0);
+    expect(IdempotencyKey::query()->count())->toBe(1);
+});
+
+test('決着済み (completed) の行があれば controller を実行せず再生する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    [$apiKey, $plain] = issueApiKey($organization, $owner);
+    $payload = ['name' => '再生対象'];
+
+    IdempotencyKey::factory()->forApiKey($apiKey)->create([
+        'route_name' => 'api.v1.projects.items.store',
+        'key' => 'replay-key',
+        'request_hash' => idempotencyRequestHashFor(
+            'POST',
+            "api/v1/projects/{$project->id}/items",
+            $payload,
+        ),
+        'response_status' => 201,
+        'response_body' => ['data' => ['id' => 4242, 'name' => '保存済み']],
+    ]);
+
+    $this->withHeaders([
+        'Authorization' => "Bearer {$plain}",
+        'Idempotency-Key' => 'replay-key',
+    ])->postJson("/api/v1/projects/{$project->id}/items", $payload)
+        ->assertCreated()
+        ->assertHeader('Idempotent-Replayed', 'true')
+        ->assertJsonPath('data.id', 4242);
+
+    expect($project->items()->count())->toBe(0);
+});
+
+test('indeterminate の行があれば 409 idempotency_indeterminate で副作用ゼロ', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    [$apiKey, $plain] = issueApiKey($organization, $owner);
+    $payload = ['name' => '決着不明'];
+
+    IdempotencyKey::factory()->forApiKey($apiKey)->indeterminate()->create([
+        'route_name' => 'api.v1.projects.items.store',
+        'key' => 'indeterminate-key',
+        'request_hash' => idempotencyRequestHashFor(
+            'POST',
+            "api/v1/projects/{$project->id}/items",
+            $payload,
+        ),
+    ]);
+
+    $this->withHeaders([
+        'Authorization' => "Bearer {$plain}",
+        'Idempotency-Key' => 'indeterminate-key',
+    ])->postJson("/api/v1/projects/{$project->id}/items", $payload)
+        ->assertStatus(409)
+        ->assertJsonPath('error.code', 'idempotency_indeterminate');
+
+    expect($project->items()->count())->toBe(0);
+});
+
+test('例外が middleware まで抜けた場合も indeterminate に確定する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    [, $plain] = issueApiKey($organization, $owner);
+
+    $url = registerIdempotencyProbeRoute('throws', function (): never {
+        throw new RuntimeException('probe explodes');
+    });
+
+    $this->withHeaders([
+        'Authorization' => "Bearer {$plain}",
+        'Idempotency-Key' => 'probe-throws',
+    ])->postJson($url)->assertStatus(500);
+
+    expect(IdempotencyKey::query()->sole()->state)->toBe(IdempotencyState::Indeterminate);
+});
+
+test('期限切れの processing 行は削除されて再 claim できる', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    [$apiKey, $plain] = issueApiKey($organization, $owner);
+    $payload = ['name' => '期限切れ claim'];
+
+    IdempotencyKey::factory()->forApiKey($apiKey)->processing()->expired()->create([
+        'route_name' => 'api.v1.projects.items.store',
+        'key' => 'expired-processing',
+        'request_hash' => str_repeat('b', 64),
+    ]);
+
+    $this->withHeaders([
+        'Authorization' => "Bearer {$plain}",
+        'Idempotency-Key' => 'expired-processing',
+    ])->postJson("/api/v1/projects/{$project->id}/items", $payload)
+        ->assertCreated();
+
+    $row = IdempotencyKey::query()->sole();
+    expect($row->state)->toBe(IdempotencyState::Completed);
+    expect($project->items()->count())->toBe(1);
+});
+
+test('claim 行は api_key_id / user_id のどちらか一方だけが非 NULL', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('排他検証組織');
+    $project = Project::factory()->forOrganization($organization)->create();
+    [$apiKey, $plain] = issueApiKey($organization, $owner);
+
+    // ★OAuth 発行を先に済ませる (default header に Bearer を積むと consent フローが壊れる)
+    $issued = OAuthTestHelpers::issueCliSessionTokens(
+        test: $this,
+        user: $owner,
+        organization: $organization,
+        client: OAuthTestHelpers::createMcpClient(name: 'Ownership CLI'),
+    );
+
+    $this->withHeaders([
+        'Authorization' => "Bearer {$plain}",
+        'Idempotency-Key' => 'ownership-api-key',
+    ])->postJson("/api/v1/projects/{$project->id}/items", ['name' => 'APIキー経由'])
+        ->assertCreated();
+
+    $apiKeyRow = IdempotencyKey::query()->where('key', 'ownership-api-key')->sole();
+    expect($apiKeyRow->api_key_id)->toBe($apiKey->id);
+    expect($apiKeyRow->user_id)->toBeNull();
+
+    $this->flushHeaders();
+    Auth::forgetGuards();
+
+    $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
+        ->withHeader('Idempotency-Key', 'ownership-user')
+        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => 'OAuth経由'])
+        ->assertCreated();
+
+    $userRow = IdempotencyKey::query()->where('key', 'ownership-user')->sole();
+    expect($userRow->user_id)->toBe($owner->id);
+    expect($userRow->api_key_id)->toBeNull();
+});
+
+test('409 の 3 コードはいずれも error envelope の形が同じ', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    [$apiKey, $plain] = issueApiKey($organization, $owner);
+    $payload = ['name' => 'envelope 検証'];
+    $hash = idempotencyRequestHashFor('POST', "api/v1/projects/{$project->id}/items", $payload);
+
+    $expectations = [
+        ['conflict-key', IdempotencyState::Completed, str_repeat('c', 64), 'idempotency_conflict'],
+        ['progress-key', IdempotencyState::Processing, $hash, 'idempotency_in_progress'],
+        ['indeterminate-key', IdempotencyState::Indeterminate, $hash, 'idempotency_indeterminate'],
+    ];
+
+    foreach ($expectations as [$key, $state, $requestHash, $code]) {
+        IdempotencyKey::factory()->forApiKey($apiKey)->create([
+            'route_name' => 'api.v1.projects.items.store',
+            'key' => $key,
+            'request_hash' => $requestHash,
+            'state' => $state,
+        ]);
+
+        $this->withHeaders([
+            'Authorization' => "Bearer {$plain}",
+            'Idempotency-Key' => $key,
+        ])->postJson("/api/v1/projects/{$project->id}/items", $payload)
+            ->assertStatus(409)
+            ->assertJsonCount(1)
+            ->assertJsonCount(3, 'error')
+            ->assertJsonPath('error.code', $code)
+            ->assertJsonPath('error.status', 409)
+            ->assertJsonPath('error.message', fn (mixed $message): bool => is_string($message) && $message !== '');
+    }
+
+    expect($project->items()->count())->toBe(0);
+});
+
+test('finalize は processing の行しか書き換えない (terminal 行を上書きしない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    [, $plain] = issueApiKey($organization, $owner);
+
+    // ハンドラ実行中に claim 行が別経路で completed へ確定した状況を作る。
+    // finalize の条件付き UPDATE (where state = processing) が無いと、
+    // 先に決着した保存応答を後から上書きしてしまう。
+    $url = registerIdempotencyProbeRoute('terminal_guard', function (): array {
+        IdempotencyKey::query()->update([
+            'state' => IdempotencyState::Completed->value,
+            'response_status' => 200,
+            'response_body' => json_encode(['data' => ['winner' => true]], JSON_THROW_ON_ERROR),
+        ]);
+
+        return ['data' => ['winner' => false]];
+    });
+
+    $handler = spyOnIdempotencyExceptionHandler();
+
+    $this->withHeaders([
+        'Authorization' => "Bearer {$plain}",
+        'Idempotency-Key' => 'probe-terminal-guard',
+    ])->postJson($url)
+        ->assertOk()
+        ->assertJsonPath('data.winner', false);
+
+    // 先に決着した内容が保持され、上書きされない
+    $row = IdempotencyKey::query()->sole();
+    expect($row->state)->toBe(IdempotencyState::Completed);
+    expect($row->response_body)->toBe(['data' => ['winner' => true]]);
+
+    // 書き換えられなかったことは観測専用例外として report される
+    $handler->shouldHaveReceived('report')->once();
+});
+
+test('finalize が失敗しても元の応答は壊れない (report のみ)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    [, $plain] = issueApiKey($organization, $owner);
+
+    // ハンドラ内で claim 行を消す = finalize の条件付き UPDATE が 0 行になる
+    $url = registerIdempotencyProbeRoute('finalize_fails', function (): array {
+        IdempotencyKey::query()->delete();
+
+        return ['data' => ['ok' => true]];
+    });
+
+    $handler = spyOnIdempotencyExceptionHandler();
+
+    $this->withHeaders([
+        'Authorization' => "Bearer {$plain}",
+        'Idempotency-Key' => 'probe-finalize-fails',
+    ])->postJson($url)
+        ->assertOk()
+        ->assertJsonPath('data.ok', true);
+
+    $handler->shouldHaveReceived('report')->once();
+});
+
+test('completed の保存 body は DB へ往復してから再生される', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    [, $plain] = issueApiKey($organization, $owner);
+    $headers = [
+        'Authorization' => "Bearer {$plain}",
+        'Idempotency-Key' => 'roundtrip-key',
+    ];
+    $payload = ['name' => '往復検証'];
+
+    $first = $this->withHeaders($headers)
+        ->postJson("/api/v1/projects/{$project->id}/items", $payload)
+        ->assertCreated()
+        ->assertHeaderMissing('Idempotent-Replayed');
+
+    // DB から読み直して配列として復元できること (json 列へ PHP 配列を渡す回帰の検出)
+    $row = IdempotencyKey::query()->sole();
+    expect($row->state)->toBe(IdempotencyState::Completed);
+    expect($row->response_status)->toBe(201);
+    expect($row->response_body)->toBe($first->json());
+
+    $this->withHeaders($headers)
+        ->postJson("/api/v1/projects/{$project->id}/items", $payload)
+        ->assertCreated()
+        ->assertHeader('Idempotent-Replayed', 'true')
+        ->assertExactJson($first->json());
+});
+
+test('255 文字を超える Idempotency-Key は 422 で弾かれ副作用も冪等行も作らない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    [, $plain] = issueApiKey($organization, $owner);
+    $payload = ['name' => 'キー長検証'];
+
+    $this->withHeaders([
+        'Authorization' => "Bearer {$plain}",
+        'Idempotency-Key' => str_repeat('a', 256),
+    ])->postJson("/api/v1/projects/{$project->id}/items", $payload)
+        ->assertStatus(422)
+        ->assertJsonPath('error.code', 'validation_failed');
+
+    expect($project->items()->count())->toBe(0);
+    expect(IdempotencyKey::query()->count())->toBe(0);
+
+    // 境界値 255 は正常に通る
+    $this->withHeaders([
+        'Authorization' => "Bearer {$plain}",
+        'Idempotency-Key' => str_repeat('b', 255),
+    ])->postJson("/api/v1/projects/{$project->id}/items", $payload)
+        ->assertCreated();
+
+    expect(IdempotencyKey::query()->count())->toBe(1);
+});
diff --git a/tests/Feature/Api/IdempotencyTest.php b/tests/Feature/Api/IdempotencyTest.php
index fa12283..c1db6ae 100644
--- a/tests/Feature/Api/IdempotencyTest.php
+++ b/tests/Feature/Api/IdempotencyTest.php
@@ -2,14 +2,18 @@
 
 declare(strict_types=1);
 
+use App\Enums\Idempotency\IdempotencyState;
 use App\Models\IdempotencyKey;
 use App\Models\Project;
 
 /*
  * Idempotency-Key middleware (write エンドポイント) の契約:
- * - 同一 key + 同一 body の再送 → 保存レスポンスの再生 (副作用は 1 回)
+ * - 同一 key + 同一 body の再送 → 保存レスポンスの再生 (副作用は 1 回、Idempotent-Replayed: true)
  * - 同一 key + 異なる body → 409 idempotency_conflict
- * - スコープは (api_key, route_name, key)。TTL (24h) 超過の保存行は未使用扱い
+ * - 4xx/5xx で終わった要求は indeterminate として記録され、**同一キーは再利用できない**
+ *   (T139 の破壊的契約変更。release 経路を持たない)
+ * - スコープは (api_key, route_name, key)。保持期間 (config idempotency.retention_hours)
+ *   超過の保存行は未使用扱い
  */
 
 test('同一 Idempotency-Key の再送は保存レスポンスを再生する (副作用 1 回)', function (): void {
@@ -22,18 +26,22 @@
     ];
     $payload = ['name' => '一度だけ作る', 'note' => null];
 
+    // 初回応答には Idempotent-Replayed を付けない (再生かどうかを識別できる)
     $first = $this->withHeaders($headers)
         ->postJson("/api/v1/projects/{$project->id}/items", $payload)
-        ->assertCreated();
+        ->assertCreated()
+        ->assertHeaderMissing('Idempotent-Replayed');
 
     $second = $this->withHeaders($headers)
         ->postJson("/api/v1/projects/{$project->id}/items", $payload)
-        ->assertCreated();
+        ->assertCreated()
+        ->assertHeader('Idempotent-Replayed', 'true');
 
     // 同一レスポンスが再生され、Item は 1 件のみ
     expect($second->json())->toBe($first->json());
     expect($project->items()->count())->toBe(1);
     expect(IdempotencyKey::query()->count())->toBe(1);
+    expect(IdempotencyKey::query()->sole()->state)->toBe(IdempotencyState::Completed);
 });
 
 test('同一 Idempotency-Key + 異なる body は 409 idempotency_conflict', function (): void {
@@ -52,7 +60,10 @@
     $this->withHeaders($headers)
         ->postJson("/api/v1/projects/{$project->id}/items", ['name' => '別の body'])
         ->assertStatus(409)
-        ->assertJsonPath('error.code', 'idempotency_conflict');
+        ->assertJsonCount(1)
+        ->assertJsonCount(3, 'error')
+        ->assertJsonPath('error.code', 'idempotency_conflict')
+        ->assertJsonPath('error.status', 409);
 
     expect($project->items()->count())->toBe(1);
 });
@@ -95,6 +106,8 @@
     // 別 API キーなので両方とも実行される
     expect($project->items()->count())->toBe(2);
     expect(IdempotencyKey::query()->count())->toBe(2);
+    expect(IdempotencyKey::query()->pluck('state')->all())
+        ->toBe([IdempotencyState::Completed, IdempotencyState::Completed]);
 });
 
 test('TTL 超過の Idempotency-Key は未使用扱いで再実行される', function (): void {
@@ -112,7 +125,8 @@
         ->assertCreated();
     expect(IdempotencyKey::query()->count())->toBe(1);
 
-    // TTL (24h) 超過後の再送は保存行を削除して再実行する
+    // 保持期間 (config idempotency.retention_hours = 24h) 超過後の再送は
+    // 保存行を削除して再実行する
     $this->travel(25)->hours();
 
     $this->withHeaders($headers)
@@ -154,7 +168,26 @@
     expect($active->isExpired())->toBeFalse();
 });
 
-test('バリデーション失敗 (非 2xx) は保存されず再送で再実行できる', function (): void {
+test('IdempotencyKeyFactory: 既定は completed / processing と indeterminate は応答列が null', function (): void {
+    $completed = IdempotencyKey::factory()->create();
+    $processing = IdempotencyKey::factory()->processing()->create();
+    $indeterminate = IdempotencyKey::factory()->indeterminate()->create();
+
+    expect($completed->state)->toBe(IdempotencyState::Completed);
+    expect($completed->response_status)->toBe(201);
+
+    expect($processing->state)->toBe(IdempotencyState::Processing);
+    expect($processing->response_status)->toBeNull();
+    expect($processing->response_body)->toBeNull();
+
+    expect($indeterminate->state)->toBe(IdempotencyState::Indeterminate);
+    expect($indeterminate->response_status)->toBeNull();
+    expect($indeterminate->response_body)->toBeNull();
+});
+
+test('バリデーション失敗は indeterminate として記録され、同一キーの再送は 409 になる', function (): void {
+    // ★契約変更 (T139): 決着は completed と indeterminate だけで、
+    //   release (再実行を許す) 経路を持たない。4xx の後に同じキーは使えない。
     [$organization, $owner] = createOrganizationWithOwner();
     $project = Project::factory()->forOrganization($organization)->create();
     [, $plain] = issueApiKey($organization, $owner);
@@ -167,10 +200,24 @@
         ->postJson("/api/v1/projects/{$project->id}/items", ['note' => 'name なし'])
         ->assertUnprocessable();
 
-    expect(IdempotencyKey::query()->count())->toBe(0);
+    $row = IdempotencyKey::query()->sole();
+    expect($row->state)->toBe(IdempotencyState::Indeterminate);
+    expect($row->response_status)->toBeNull();
+
+    // 同一 body の再送 → 409 indeterminate
+    $this->withHeaders($headers)
+        ->postJson("/api/v1/projects/{$project->id}/items", ['note' => 'name なし'])
+        ->assertStatus(409)
+        ->assertJsonPath('error.code', 'idempotency_indeterminate');
 
-    // 正しい body での再送 (同一 key) は実行される
+    // 修正した body での再送 → hash 不一致なので 409 conflict (新しいキーが要る)
     $this->withHeaders($headers)
+        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => '修正後'])
+        ->assertStatus(409)
+        ->assertJsonPath('error.code', 'idempotency_conflict');
+
+    // 新しいキーなら通る (詰まないことの確認)
+    $this->withHeaders([...$headers, 'Idempotency-Key' => 'idem-key-004'])
         ->postJson("/api/v1/projects/{$project->id}/items", ['name' => '修正後'])
         ->assertCreated();
 
diff --git a/tests/Feature/Api/OAuthDualGuardTest.php b/tests/Feature/Api/OAuthDualGuardTest.php
index 3731a28..77dd22f 100644
--- a/tests/Feature/Api/OAuthDualGuardTest.php
+++ b/tests/Feature/Api/OAuthDualGuardTest.php
@@ -130,16 +130,19 @@
     );
     $project = Project::factory()->forOrganization($this->org)->create();
 
+    // 初回応答には Idempotent-Replayed を付けない
     $first = $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
         ->withHeader('Idempotency-Key', 'oauth-idem-1')
         ->postJson("/api/v1/projects/{$project->id}/items", ['name' => 'OAuth経由アイテム'])
-        ->assertCreated();
+        ->assertCreated()
+        ->assertHeaderMissing('Idempotent-Replayed');
 
     // 同一 key + 同一 body の再送は保存済みレスポンスを再生する (副作用は 1 回)
     $replay = $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
         ->withHeader('Idempotency-Key', 'oauth-idem-1')
         ->postJson("/api/v1/projects/{$project->id}/items", ['name' => 'OAuth経由アイテム'])
-        ->assertCreated();
+        ->assertCreated()
+        ->assertHeader('Idempotent-Replayed', 'true');
 
     expect($replay->json('data.id'))->toBe($first->json('data.id'));
     expect($project->items()->count())->toBe(1);
diff --git a/tests/Feature/Api/V1/ItemAuthorizationTest.php b/tests/Feature/Api/V1/ItemAuthorizationTest.php
index 2244384..990af74 100644
--- a/tests/Feature/Api/V1/ItemAuthorizationTest.php
+++ b/tests/Feature/Api/V1/ItemAuthorizationTest.php
@@ -2,8 +2,10 @@
 
 declare(strict_types=1);
 
+use App\Enums\Idempotency\IdempotencyState;
 use App\Enums\OrganizationRole;
 use App\Enums\ProjectRole;
+use App\Models\IdempotencyKey;
 use App\Models\Item;
 use App\Models\Organization;
 use App\Models\Project;
@@ -317,7 +319,11 @@ function itemAuthorizationBearer(string $plain): array
 
 // --- idempotency 層との相互作用 (ケース 16) ---
 
-test('403 は Idempotency-Key で再生されない (権限付与後の再送は成功する)', function (): void {
+test('403 の後は同一キーが 409 になり、新しいキーなら権限付与後に成功する', function (): void {
+    // ★契約変更 (T139): 403 は「決着不明」として indeterminate に倒れる。
+    //   middleware は controller の 403 が副作用の前だったか後だったかを知らないため、
+    //   再実行せず新しいキーを要求する。**403 が再生されることは無い**
+    //   (403 応答そのものが保存されないので、権限回復後に 403 で詰むことはない)。
     [$organization] = createOrganizationWithOwner();
     $project = Project::factory()->forOrganization($organization)->create();
     $viewer = attachOrganizationMember($organization, OrganizationRole::Member);
@@ -329,13 +335,23 @@ function itemAuthorizationBearer(string $plain): array
         ->postJson("/api/v1/projects/{$project->id}/items", $payload)
         ->assertForbidden();
 
+    $row = IdempotencyKey::query()->sole();
+    expect($row->state)->toBe(IdempotencyState::Indeterminate);
+    expect($row->response_status)->toBeNull();
+
     attachProjectMember($project, $viewer, ProjectRole::Admin);
     // relation キャッシュ由来の偽陰性でテスト失敗の原因が切り分けられなくなるのを防ぐ
     $viewer->refresh();
     $project->unsetRelations();
 
-    // 保存済み 403 が再生されるなら 403 のまま = 権限回復後も詰む
+    // 同一キーは 409 (403 が再生されるわけではない = コードで区別できる)
     $this->withHeaders($headers)
+        ->postJson("/api/v1/projects/{$project->id}/items", $payload)
+        ->assertStatus(409)
+        ->assertJsonPath('error.code', 'idempotency_indeterminate');
+
+    // 新しいキーなら権限回復後に成功する (詰まないことの確認)
+    $this->withHeaders([...$headers, 'Idempotency-Key' => 'fixed-key-002'])
         ->postJson("/api/v1/projects/{$project->id}/items", $payload)
         ->assertCreated();
 
diff --git a/tests/Feature/Console/PruneIdempotencyKeysCommandTest.php b/tests/Feature/Console/PruneIdempotencyKeysCommandTest.php
new file mode 100644
index 0000000..563c931
--- /dev/null
+++ b/tests/Feature/Console/PruneIdempotencyKeysCommandTest.php
@@ -0,0 +1,94 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\IdempotencyKey;
+use App\Models\McpIdempotencyKey;
+use Illuminate\Contracts\Debug\ExceptionHandler;
+use Mockery\MockInterface;
+
+/*
+ * 冪等キーの保持期間 purge (idempotency:prune)。
+ *
+ * lazy delete (claim 時の期限切れ行削除) は「再送されたキー」しか回収しないため、
+ * 二度と再送されなかったキーを日次で物理削除する。
+ *
+ * 報告契約: processing のまま期限切れになった行 (= 確定できなかった claim) が
+ * 1 件でもあれば report() する。載せるのは件数のみ (キー値・body は載せない)。
+ */
+
+/** report() 経路 (運用アラート) を観測する spy を差し込む */
+function spyOnPruneExceptionHandler(): MockInterface
+{
+    $handler = Mockery::spy(ExceptionHandler::class);
+    app()->instance(ExceptionHandler::class, $handler);
+
+    return $handler;
+}
+
+test('期限切れの REST 冪等キーを state 横断で削除する', function (): void {
+    IdempotencyKey::factory()->expired()->create(['key' => 'expired-completed']);
+    IdempotencyKey::factory()->processing()->expired()->create(['key' => 'expired-processing']);
+    IdempotencyKey::factory()->indeterminate()->expired()->create(['key' => 'expired-indeterminate']);
+    IdempotencyKey::factory()->create(['key' => 'alive']);
+
+    $this->artisan('idempotency:prune')->assertExitCode(0);
+
+    expect(IdempotencyKey::query()->pluck('key')->all())->toBe(['alive']);
+});
+
+test('期限切れの MCP 冪等キーも削除する', function (): void {
+    // idempotency_key は uuid 列のため値は UUID で作る
+    $alive = McpIdempotencyKey::factory()->create();
+    McpIdempotencyKey::factory()->expired()->create();
+
+    $this->artisan('idempotency:prune')->assertExitCode(0);
+
+    expect(McpIdempotencyKey::query()->pluck('idempotency_key')->all())
+        ->toBe([$alive->idempotency_key]);
+});
+
+test('未期限の行は 1 件も削除しない (負のコントロール)', function (): void {
+    // cutoff 条件が抜けたら全消しになり、このテストが赤くなる
+    IdempotencyKey::factory()->create(['key' => 'alive-completed']);
+    IdempotencyKey::factory()->processing()->create(['key' => 'alive-processing']);
+    IdempotencyKey::factory()->indeterminate()->create(['key' => 'alive-indeterminate']);
+    McpIdempotencyKey::factory()->create();
+
+    $this->artisan('idempotency:prune')->assertExitCode(0);
+
+    expect(IdempotencyKey::query()->count())->toBe(3);
+    expect(McpIdempotencyKey::query()->count())->toBe(1);
+});
+
+test('processing のまま期限切れになった行があれば report する', function (): void {
+    IdempotencyKey::factory()->processing()->expired()->create();
+    $handler = spyOnPruneExceptionHandler();
+
+    $this->artisan('idempotency:prune')->assertExitCode(0);
+
+    $handler->shouldHaveReceived('report')->once();
+});
+
+test('processing の期限切れが 0 件なら report しない', function (): void {
+    IdempotencyKey::factory()->expired()->create();
+    IdempotencyKey::factory()->indeterminate()->expired()->create();
+    IdempotencyKey::factory()->processing()->create(); // 未期限の processing は対象外
+    $handler = spyOnPruneExceptionHandler();
+
+    $this->artisan('idempotency:prune')->assertExitCode(0);
+
+    $handler->shouldNotHaveReceived('report');
+});
+
+test('削除件数を state 別に出力する', function (): void {
+    IdempotencyKey::factory()->expired()->create();
+    IdempotencyKey::factory()->indeterminate()->expired()->create();
+
+    $this->artisan('idempotency:prune')
+        ->expectsOutputToContain('rest completed: 1 件削除')
+        ->expectsOutputToContain('rest indeterminate: 1 件削除')
+        ->expectsOutputToContain('rest processing: 0 件削除')
+        ->expectsOutputToContain('mcp: 0 件削除')
+        ->assertExitCode(0);
+});
diff --git a/tests/Feature/Database/IdempotencyStateMigrationTest.php b/tests/Feature/Database/IdempotencyStateMigrationTest.php
new file mode 100644
index 0000000..b86f963
--- /dev/null
+++ b/tests/Feature/Database/IdempotencyStateMigrationTest.php
@@ -0,0 +1,89 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Idempotency\IdempotencyState;
+use App\Models\ApiKey;
+use App\Models\IdempotencyKey;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Schema;
+
+/*
+ * idempotency_keys への state 列追加 migration (T139) のスキーマ契約。
+ *
+ * ★DB default を持たないことが本質: default があると「state を書き忘れた INSERT」が
+ *   黙って completed になり、claim の意味が消える。
+ * ★既存行は completed へ backfill する (旧実装は 2xx しか保存しないため決着は既知)。
+ *   indeterminate に倒すとデプロイ直後の正当な再送が最大 24h ぶん 409 に化ける。
+ */
+
+test('state 列は NOT NULL で DB default を持たない', function (): void {
+    $columns = collect(Schema::getColumns('idempotency_keys'))
+        ->keyBy(fn (array $column): string => (string) $column['name']);
+
+    expect($columns)->toHaveKey('state');
+    expect($columns['state']['nullable'])->toBeFalse();
+    expect($columns['state']['default'])->toBeNull();
+});
+
+test('response_status は nullable (claim 時点では応答が無い)', function (): void {
+    $columns = collect(Schema::getColumns('idempotency_keys'))
+        ->keyBy(fn (array $column): string => (string) $column['name']);
+
+    expect($columns['response_status']['nullable'])->toBeTrue();
+});
+
+test('既存の unique 2 本が残っている (claim の調停者)', function (): void {
+    $uniques = collect(Schema::getIndexes('idempotency_keys'))
+        ->filter(fn (array $index): bool => (bool) $index['unique'])
+        ->map(function (array $index): array {
+            /** @var list<string> $columns */
+            $columns = $index['columns'];
+            sort($columns);
+
+            return $columns;
+        })
+        ->values()
+        ->all();
+
+    expect($uniques)->toContain(['api_key_id', 'key', 'route_name']);
+    expect($uniques)->toContain(['key', 'route_name', 'user_id']);
+});
+
+test('既存行は completed へ backfill される', function (): void {
+    // 1. 旧スキーマ相当へ戻す (state 列と index を落とす)
+    Schema::table('idempotency_keys', function (Blueprint $table): void {
+        $table->dropIndex('idempotency_keys_state_expires_at_index');
+        $table->dropColumn('state');
+    });
+
+    // 2. 旧実装が書いていた形の行を 1 件用意する (2xx の保存応答)。
+    //    属性値は Factory から生成する (手組み禁止の規約)。旧スキーマへ挿入するため
+    //    insert 自体は query builder で行い、落とした `state` だけを外す。
+    $apiKey = ApiKey::factory()->create();
+    /** @var array<string, mixed> $attributes */
+    $attributes = IdempotencyKey::factory()
+        ->forApiKey($apiKey)
+        ->raw([
+            'key' => 'legacy-key-1',
+            'response_status' => 201,
+            'response_body' => ['data' => ['id' => 7]],
+        ]);
+    unset($attributes['state']);
+    $attributes['response_body'] = json_encode($attributes['response_body'], JSON_THROW_ON_ERROR);
+
+    DB::table('idempotency_keys')->insert($attributes);
+
+    // 3. 対象 migration の up() を直接実行する
+    $migration = require database_path(
+        'migrations/2026_08_09_000100_add_state_to_idempotency_keys_table.php',
+    );
+    $migration->up();
+
+    // 4. 既存行は completed で、保存応答は無傷
+    $row = IdempotencyKey::query()->where('key', 'legacy-key-1')->sole();
+    expect($row->state)->toBe(IdempotencyState::Completed);
+    expect($row->response_status)->toBe(201);
+    expect($row->response_body)->toBe(['data' => ['id' => 7]]);
+});
diff --git a/tests/Feature/Mcp/McpIdempotencyServiceTest.php b/tests/Feature/Mcp/McpIdempotencyServiceTest.php
index fa551f2..193a31f 100644
--- a/tests/Feature/Mcp/McpIdempotencyServiceTest.php
+++ b/tests/Feature/Mcp/McpIdempotencyServiceTest.php
@@ -7,6 +7,7 @@
 use App\Models\McpIdempotencyKey;
 use App\Services\Mcp\McpIdempotencyService;
 use App\Values\Mcp\IdempotencyKey;
+use Carbon\CarbonImmutable;
 use Illuminate\Support\Str;
 
 /*
@@ -118,3 +119,18 @@
     '非UUID' => ['not-a-uuid'],
     'UUID v1 相当' => ['550e8400-e29b-11d4-a716-446655440000'],
 ])->throws(InvalidParamsException::class);
+
+test('store の expires_at は config の保持期間から決まる (クラス定数ではない)', function (): void {
+    // 保持期間の SoT は config/idempotency.php (REST 側と共有)。
+    // ここを変えて expires_at が追随しなければ二重管理へ逆戻りしている。
+    config(['idempotency.retention_hours' => 1]);
+    $this->freezeTime();
+    $now = CarbonImmutable::now();
+
+    $this->service->store(
+        $this->org->id, $this->user->id, 'test-tool', $this->key, ['a' => 1], ['ok' => true],
+    );
+
+    $row = McpIdempotencyKey::query()->sole();
+    expect($row->expires_at->toDateTimeString())->toBe($now->addHour()->toDateTimeString());
+});
diff --git a/tests/Feature/Security/IdempotencyExemptionPremiseTest.php b/tests/Feature/Security/IdempotencyExemptionPremiseTest.php
new file mode 100644
index 0000000..3da5d9d
--- /dev/null
+++ b/tests/Feature/Security/IdempotencyExemptionPremiseTest.php
@@ -0,0 +1,50 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\IdempotencyKey;
+use Illuminate\Support\Facades\Auth;
+use Tests\Support\OAuthTestHelpers;
+
+/*
+ * IdempotentRouteCoverageTest の exemption が依拠する**前提**の behavioral proof。
+ *
+ * exemption は「idempotent を配線しないことが**正しい**」という主張であり、
+ * その根拠 (成功後は同じ token が冪等層より前で 401 になる) が vendor 更新や
+ * リファクタで崩れたら検出できなければならない。
+ *
+ * ★主張範囲を誇張しない: 本テストが固定するのは
+ *   「revoke 成功後、同じ token での再送は 401 になり、冪等行が 1 件も作られない」
+ *   という**観測**であって、「冪等層より前で止まった」ことの直接証明ではない
+ *   (実行位置の証明は TenantBoundaryOrderingTest / ApiGuardAllowlistInvariantTest の
+ *    順序 gate が担当する)。両者の組合せで免除の前提が成立する。
+ */
+
+test('session revoke 後の同一 token 再送は 401 になり冪等行を 1 件も作らない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('免除前提組織');
+
+    $issued = OAuthTestHelpers::issueCliSessionTokens(
+        test: $this,
+        user: $owner,
+        organization: $organization,
+        client: OAuthTestHelpers::createMcpClient(name: 'Premise CLI'),
+    );
+
+    $this->flushHeaders();
+
+    $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
+        ->withHeader('Idempotency-Key', 'revoke-premise-1')
+        ->deleteJson('/api/v1/me/session')
+        ->assertOk();
+
+    Auth::forgetGuards();
+
+    $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
+        ->withHeader('Idempotency-Key', 'revoke-premise-1')
+        ->deleteJson('/api/v1/me/session')
+        ->assertUnauthorized();
+
+    // 観測上、revoke と再送のどちらでも冪等行は作られない
+    // (= 配線しても再生応答が返る経路が無いという免除理由の裏取り)
+    expect(IdempotencyKey::query()->count())->toBe(0);
+});
diff --git a/tests/Unit/Support/Idempotency/IdempotencyClaimOutcomeTest.php b/tests/Unit/Support/Idempotency/IdempotencyClaimOutcomeTest.php
new file mode 100644
index 0000000..650447e
--- /dev/null
+++ b/tests/Unit/Support/Idempotency/IdempotencyClaimOutcomeTest.php
@@ -0,0 +1,43 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Idempotency\IdempotencyClaimStatus;
+use App\Models\IdempotencyKey;
+use App\Support\Idempotency\IdempotencyClaimOutcome;
+
+/*
+ * claim 結果の DTO。status と row の無効な組合せを**構築できなくする**境界。
+ * named constructor 以外の生成経路を作らない (呼び出し側に null 判定を書かせない)。
+ */
+
+test('claimed() は row を持たない (rowOrFail は失敗する)', function (): void {
+    $outcome = IdempotencyClaimOutcome::claimed();
+
+    expect($outcome->status)->toBe(IdempotencyClaimStatus::Claimed);
+
+    $outcome->rowOrFail();
+})->throws(InvalidArgumentException::class);
+
+test('row を伴う named constructor は status と row の組合せを固定する', function (): void {
+    $row = new IdempotencyKey;
+
+    $cases = [
+        [IdempotencyClaimOutcome::replay($row), IdempotencyClaimStatus::Replay],
+        [IdempotencyClaimOutcome::conflict($row), IdempotencyClaimStatus::Conflict],
+        [IdempotencyClaimOutcome::inProgress($row), IdempotencyClaimStatus::InProgress],
+        [IdempotencyClaimOutcome::indeterminate($row), IdempotencyClaimStatus::Indeterminate],
+    ];
+
+    foreach ($cases as [$outcome, $expectedStatus]) {
+        expect($outcome->status)->toBe($expectedStatus);
+        expect($outcome->rowOrFail())->toBe($row);
+    }
+});
+
+test('__construct は private である (named constructor 以外で作れない)', function (): void {
+    $constructor = (new ReflectionClass(IdempotencyClaimOutcome::class))->getConstructor();
+
+    expect($constructor)->not->toBeNull();
+    expect($constructor?->isPrivate())->toBeTrue();
+});
diff --git a/tests/Unit/Support/Idempotency/IdempotencyRetentionTest.php b/tests/Unit/Support/Idempotency/IdempotencyRetentionTest.php
new file mode 100644
index 0000000..69b4ac0
--- /dev/null
+++ b/tests/Unit/Support/Idempotency/IdempotencyRetentionTest.php
@@ -0,0 +1,41 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Support\Idempotency\IdempotencyRetention;
+use Carbon\CarbonImmutable;
+
+/*
+ * 保持期間の SoT (config/idempotency.php) への型付き入口。
+ * config の型崩れは Assert で fail-fast する (黙って既定値へ倒れない)。
+ */
+
+test('hours() は config の値を返す', function (): void {
+    config(['idempotency.retention_hours' => 3]);
+
+    expect(IdempotencyRetention::hours())->toBe(3);
+});
+
+test('hours() は非 int の config で失敗する', function (): void {
+    config(['idempotency.retention_hours' => '24']);
+
+    IdempotencyRetention::hours();
+})->throws(InvalidArgumentException::class);
+
+test('hours() は 0 以下の config で失敗する', function (): void {
+    config(['idempotency.retention_hours' => 0]);
+
+    IdempotencyRetention::hours();
+})->throws(InvalidArgumentException::class);
+
+test('expiresAt() は基準時刻 + hours を返す', function (): void {
+    config(['idempotency.retention_hours' => 5]);
+    $now = CarbonImmutable::parse('2026-08-09 10:00:00');
+
+    expect(IdempotencyRetention::expiresAt($now)->toDateTimeString())
+        ->toBe('2026-08-09 15:00:00');
+});
+
+test('既定の保持期間は 24 時間', function (): void {
+    expect(IdempotencyRetention::hours())->toBe(24);
+});

```

---

## 文書差分 (docs/ + AGENTS.md)

```diff
diff --git a/AGENTS.md b/AGENTS.md
index 9fb8a85..f45c403 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -441,3 +441,27 @@ ## ドメイン固有規約
      password / 再SSO / **passkey** の 3 satisfier をすべて通す (どれか 1 つでも欠けると
      その手段しか持たないユーザーが詰む)。詳細は `docs/architecture.md`
      §2FA 面の step-up (recent-auth) 契約。
+9. **冪等キーの配線と決着規約**: `api/v1/*` の変更系 route は `idempotent` middleware を
+   **ちょうど 1 本**持つか、`IdempotencyWiringExemption` + 30 文字以上の根拠で
+   `IdempotentRouteCoverageTest` の目録へ登録する (deny-by-default。免除の**前提**は
+   `IdempotencyExemptionPremiseTest` が behavioral に固定する)。
+   - **決着は `completed` / `indeterminate` の 2 つだけ**で、release (再実行を許す) 経路を
+     持たない。claim は本処理の**前**に `insertOrIgnore` で行い、調停者は
+     `idempotency_keys` の既存 unique 2 本**だけ**である (cache ロックを併用しない =
+     best-effort の二重機構を作らない)。帰結として **4xx/5xx の後に同じキーは
+     再利用できない** (409 `idempotency_indeterminate`。破壊的契約変更)
+   - **保持期間の SoT は `config/idempotency.php`** (`retention_hours`)。**env は使わない**
+     (環境ごとに変えてよい運用値ではない)。クラス定数での二重管理へ戻さないこと
+     (`IdempotencyContractParityTest` が `TTL_HOURS` の不在を機械固定する)
+   - `Idempotent-Replayed` は**外部標準 (IETF の Idempotency-Key draft) には無い拡張**で、
+     **再生応答にのみ**付与する。名前と付与条件の正本は `docs/api-idempotency.md`
+   - **middleware を terminable にしない**。順序契約
+     `api.project-in-org < api-key.ability < idempotent` は不変
+   - **MCP 側は据え置き** (`McpIdempotencyService::store()` の unique 握り潰しは残る)。
+     write tool が 0 本で到達不能なため実害は無いが「MCP も並行安全になった」とは書かない。
+     最初の write tool 追加時に `McpWriteToolIdempotencyEnforcementTest` の trip-wire が
+     赤くなり、必要作業 (状態機械化 / T109 解消 / behavioral テスト) を失敗メッセージで提示する
+   - **保証範囲を誇張しない**: gate は `api/v1/` 配下しか見ず、web の書込 route・`oauth/*`・
+     将来別 prefix の機械向け API には**沈黙する**。fatal error で `processing` が残る窓も
+     閉じない (保持期間満了まで 409 が続く)。詳細は `docs/api-idempotency.md` と
+     `docs/architecture.md` §冪等キーの claim と保持期間
diff --git a/docs/TODO.md b/docs/TODO.md
index 06b87c4..b9e82e1 100644
--- a/docs/TODO.md
+++ b/docs/TODO.md
@@ -33,6 +33,6 @@ ## Conditional (条件付き待機)
 
 | ID | タイトル | テーマ | 概要 | トリガー条件 | 優先度 | モード | 設計 | 追加日 |
 |---|---|---|---|---|---|---|---|---|
-| T109 | MCP の idempotency replay をリソース解決より後へ | backend | AppMcpTool::handle() の replay 判定が runTool() より前。REST 側で api.project-in-org < idempotent として閉じたのと同型のハザードが構造的に残る(現時点で write tool 0 本のため実害なし) | MCP に write tool を 1 本でも追加するとき | Medium | incremental | [設計](devnotes/20260805-1550-security-audit-remediation/) | 2026-08-05 17:45 |
+| T109 | MCP の idempotency replay をリソース解決より後へ | backend | AppMcpTool::handle() の replay 判定が runTool() より前。REST 側で api.project-in-org < idempotent として閉じたのと同型のハザードが構造的に残る(現時点で write tool 0 本のため実害なし。**起票条件は T139 の trip-wire `McpWriteToolIdempotencyEnforcementTest` が機械化済み** = 最初の write tool 追加で赤くなり必要作業が失敗メッセージで提示される) | MCP に write tool を 1 本でも追加するとき | Medium | incremental | [設計](devnotes/20260805-1550-security-audit-remediation/) | 2026-08-05 17:45 |
 | T127 | 既定キュー接続の分割 (課金系を別接続へ) | infrastructure | 短命ジョブと Stripe 課金ジョブで retry_after を分ける。T122 で database の retry_after を 90→600 にした代償として、短命ジョブの回収が最大 510 秒遅れる | その回収遅延が実害として観測されたとき(滞留の苦情・監視アラート) | Medium | standalone | [設計](devnotes/20260806-1635-queue-lease-timeout/) | 2026-08-06 18:40 |
 | T128 | CI に workflow_dispatch を追加 | infrastructure | T123 で on.schedule を除去した結果、供給網監査を手動で叩く口が無い。追加する場合は W12 のトリガー集合への登録が gate により必須 | CI 外の定期実行枠組み(オーナー側の宿題)が決まったとき | Low | incremental | [設計](devnotes/20260806-1634-ci-schedule-removal/) | 2026-08-06 18:40 |
diff --git a/docs/api-idempotency.md b/docs/api-idempotency.md
new file mode 100644
index 0000000..78e49bb
--- /dev/null
+++ b/docs/api-idempotency.md
@@ -0,0 +1,147 @@
+# REST API v1 / MCP の冪等キー契約
+
+REST API v1 の書き込みエンドポイントと MCP 書き込み tool が共有する冪等性の契約書。
+**実装の正本は `config/idempotency.php` と `App\Enums\Idempotency\IdempotencyState`** で、
+本書のマーカー区間との一致は `tests/Architecture/IdempotencyContractParityTest.php` が
+deny-by-default で強制する。
+
+<!-- IDEMPOTENCY_CONTRACT:BEGIN -->
+- retention_hours: 24
+- replay_header: Idempotent-Replayed
+- states: processing, completed, indeterminate
+- terminal_states: completed, indeterminate
+- conflict_codes: idempotency_conflict, idempotency_in_progress, idempotency_indeterminate
+<!-- IDEMPOTENCY_CONTRACT:END -->
+
+> ⚠ 本書は**内部契約文書**である。ここに書かれた保持期間などを利用者向けの
+> 外部公開文書へ載せるかはオーナー判断であり未決。機械化しているのは
+> 「実装と本書が同じ数字を指す」ことだけである。
+
+## 1. 使い方 (クライアント視点)
+
+書き込みリクエストに `Idempotency-Key` ヘッダを付ける。値は任意の文字列だが
+**255 文字以内** (超えると `422 validation_failed`)。UUID v4 を推奨する。
+
+- キーのスコープは **actor × route × キー値**。同じキーでも別 route / 別 API キー /
+  別ユーザなら独立に扱われる
+- 同じ操作の再送には**同じキーと同じ body** を使う。body が 1 バイトでも違えば
+  `409 idempotency_conflict` になる (メソッド + パス + body の sha256 で判定する)
+- 保存応答を再生したときにだけ `Idempotent-Replayed: true` が付く
+  (初回応答・409・ヘッダ無しの素通しには付かない)。
+  **これは IETF の Idempotency-Key draft には無い拡張である**
+
+## 2. 決着写像表
+
+| 状況 | 応答 |
+|------|------|
+| ヘッダ無し | 素通し (冪等行を作らない = 毎回実行される) |
+| キーが 255 文字超 | `422 validation_failed` (DB に触る前に弾く。副作用も冪等行も作らない) |
+| 初回 (claim 成功) → 2xx JSON | その応答。行は `completed` になり応答を保存する |
+| 初回 (claim 成功) → 非 2xx / 非 JSON / 例外 | その応答 (例外は 500)。行は `indeterminate` になる |
+| 同一キー + 同一 body + `completed` | 保存応答を再生 (`Idempotent-Replayed: true`) |
+| 同一キー + 異なる body | `409 idempotency_conflict` |
+| 同一キー + `processing` | `409 idempotency_in_progress` (**本処理は実行しない**) |
+| 同一キー + `indeterminate` | `409 idempotency_indeterminate` (**本処理は実行しない**) |
+| 保持期間 (24h) 超過の行 | 未使用扱い。削除して再 claim する |
+
+### ⚠ 破壊的契約変更 (T139)
+
+**4xx / 5xx で終わった要求の後、同じキーは再利用できない。**
+
+以前は「非 2xx は保存されず、同一キーの再送で再実行できる」挙動だった。
+middleware は controller が副作用の**前**で失敗したのか**後**で失敗したのかを
+知らないため、再実行せず新しいキーを要求する (release = 再実行を許す経路を持たない)。
+
+観測される面は以下の 3 route のみ:
+
+- `api.v1.projects.items.store`
+- `api.v1.projects.items.update`
+- `api.v1.projects.items.destroy`
+
+`DELETE /api/v1/me/session` は冪等層を配線していない (§5)。MCP write tool は 0 本のため
+観測面は無い。**外部利用者への周知はオーナーの担当**。
+
+**クライアント側の対処**: 409 を受けたら、その操作は新しい `Idempotency-Key` でやり直す。
+`idempotency_in_progress` だけは「先行要求がまだ走っている」ことを意味するので、
+短い待機の後に**同じキー**で再送すれば再生応答を得られる可能性がある。
+
+## 3. エラーコード
+
+| code | status | 意味 |
+|------|--------|------|
+| `idempotency_conflict` | 409 | 同じキーが**別の body** で使われた |
+| `idempotency_in_progress` | 409 | 同じキーの先行要求が処理中 |
+| `idempotency_indeterminate` | 409 | 先行要求が成功として記録されていない。新しいキーを使う |
+
+いずれも統一 envelope `{"error": {"code", "message", "status"}}` で返る。
+
+## 4. 状態機械 (サーバ内部)
+
+行は本処理の**前**に `processing` として claim される。claim の調停者は
+`idempotency_keys` の既存 unique 2 本 (`api_key_id, route_name, key` /
+`user_id, route_name, key`) **だけ**で、cache ロック等の best-effort な二重機構は使わない。
+
+```
+(なし) --insertOrIgnore--> processing --2xx JSON--> completed
+                               |
+                               +--それ以外/例外--> indeterminate
+```
+
+- **`processing` から戻る道は無い**。唯一の解放は保持期間超過による物理削除
+- 決着は `completed` / `indeterminate` の 2 つだけ
+
+### 保証しないこと (誇張しない)
+
+- **fatal error 時の claim 回収**: OOM / timeout / プロセス強制終了で `processing` が
+  残る窓は閉じない。保持期間満了まで同一キーは 409 in_progress を返し続ける。
+  観測は `idempotency:prune` の state 別集計のみ
+- **並行 2 本の実走テスト**: テストは単一プロセスであり、実際に 2 プロセスを
+  同時に走らせてはいない。並行安全性は「claim が本処理より前に発行される」
+  「同一スコープの 2 本目の INSERT を unique が落とす」の 2 テストと、
+  実行環境の前提 (middleware を包む外側 transaction が無い + PostgreSQL の
+  autocommit / read committed) の合成として主張している
+- **MCP write tool の並行安全性**: `McpIdempotencyService::store()` の unique 握り潰しは
+  残っている。write tool が 0 本のため到達不能だが、「MCP も並行安全になった」とは書かない
+
+## 5. 配線と免除
+
+`api/v1/*` の変更系 route は `idempotent` middleware を**ちょうど 1 本**持つか、
+`App\Enums\Security\IdempotencyWiringExemption` + 30 文字以上の根拠で
+`tests/Architecture/IdempotentRouteCoverageTest.php` の目録へ登録する
+(deny-by-default)。現在の免除は 3 本:
+
+| route | 分類 | 要旨 |
+|-------|------|------|
+| `api.v1.me.session.revoke` | `self_revocation_unreachable_replay` | 成功すると自分の token が失効し、再送は冪等層より前で 401 になる |
+| `POST /api/v1/mcp` | `mcp_transport_per_tool_enforcement` | 冪等の単位は transport ではなく tool。強制は `AppMcpTool::handle()` の中央分岐 |
+| `DELETE /api/v1/mcp` | `vendor_method_not_allowed_stub` | vendor の定数 405 スタブ。本体処理へ到達しない |
+
+**gate が見ないもの**: `api/v1/` 以外 (web の書込 route、`oauth/*`、将来別 prefix の
+機械向け API) には沈黙する。別 prefix の API を足すときは母集団設計から見直すこと。
+
+## 6. 保持期間と掃除
+
+保持期間の SoT は `config/idempotency.php` の `retention_hours` (**env は使わない**。
+環境ごとに変えてよい運用値ではない)。
+
+- claim 時に期限切れ行を見つけたら、その場で削除して再 claim する (lazy delete)
+- 二度と再送されなかったキーは lazy delete では回収できないため、
+  `idempotency:prune` を daily で走らせて REST / MCP 両テーブルから物理削除する
+- **監視対象**: prune の `report()`。`processing` のまま期限切れになった行は
+  「claim したのに確定できなかった要求」であり、プロセス強制終了か finalize 失敗の痕跡
+
+## 7. ロールバック手順 (state 列 migration)
+
+`2026_08_09_000100_add_state_to_idempotency_keys_table` は **実質 irreversible** である。
+`down()` はスキーマを「state 無し / response_status nullable」に戻すだけで、
+旧コードが前提とする「全行が完了応答を持つ」状態には戻せない。
+
+**旧コードへ戻す前に、人手で次を実行すること**:
+
+```sql
+DELETE FROM idempotency_keys WHERE response_status IS NULL;
+```
+
+削除して失うのは未確定の claim だけで、再送は再実行になる
+(= ロールバック時点では旧契約と同じ挙動)。実行せずに旧コードへ戻すと、
+旧 `replayResponse` が `response_status = null` を受け取って 500 になる。
diff --git a/docs/app-integration-guide.md b/docs/app-integration-guide.md
index e46dc70..f1ad69f 100644
--- a/docs/app-integration-guide.md
+++ b/docs/app-integration-guide.md
@@ -147,6 +147,15 @@ ## 5. API・外部公開面のマッピング
 - REST API: nested route + flat ability。新リソースの ability は `{resource}:read` /
   `{resource}:write` / 動詞付き(`evaluations:run` 型)で定義し、ability 定義 1 箇所に追記。
 - すべての書き込みエンドポイントに Idempotency-Key を配線する(テンプレの middleware を使う)。
+- **冪等配線は deny-by-default で機械強制される**: `api/v1/*` の変更系 route は
+  `idempotent` を**ちょうど 1 本**持つか、`IdempotencyWiringExemption` + 30 文字以上の根拠で
+  `tests/Architecture/IdempotentRouteCoverageTest.php` の目録へ登録する
+  (免除の**前提**は `tests/Feature/Security/IdempotencyExemptionPremiseTest.php` が behavioral に固定)。
+  決着は `completed` / `indeterminate` の 2 つだけで **release (再実行を許す) 経路を持たない**ため、
+  **4xx/5xx の後に同じキーは再利用できない**(破壊的契約変更)。保持期間の SoT は
+  `config/idempotency.php`(env 不使用)。契約の正本は [docs/api-idempotency.md](api-idempotency.md)、
+  文書と実装の parity は `tests/Architecture/IdempotencyContractParityTest.php` が固定する。
+  gate が見るのは `api/v1/` 配下だけで、web の書込 route・`oauth/*`・別 prefix の API には**沈黙する**。
 - **API の権限境界は ability(トークンの能力)と Policy(actor の権限)の 2 段**。
   ability 不足は `code: "insufficient_ability"`、Policy 不足は `code: "forbidden"` で返り、
   クライアントは「トークン設定不足」と「権限不足」を判別できる。
diff --git a/docs/architecture.md b/docs/architecture.md
index 7266fe1..035eb1a 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -1150,3 +1150,30 @@ ### クライアント側 (enrollment 動線)
 自動再開は 1 enrollment につき 1 回に制限する。status が取れない (delegated) ときは
 **再取得しない** — 再取得すると 409 → status 失敗 → 再取得 の無限ループになるため、
 `enrollment-step-up-blocked` の Alert と再認証ページ導線を出して**人間の操作**を待つ。
+
+## 冪等キーの claim と保持期間 (REST API v1 / MCP)
+
+REST API v1 の `Idempotency-Key` は **本処理の前に claim する**方式で、契約の正本は
+[docs/api-idempotency.md](api-idempotency.md)。ここには運用側の要点だけを置く。
+
+- **モデル**: `IdempotencyKey` は `state` 列 (`processing` / `completed` / `indeterminate`) を持つ。
+  claim は `insertOrIgnore` で行い、調停者は既存 unique 2 本
+  (`api_key_id, route_name, key` / `user_id, route_name, key`) **だけ** (cache ロックを併用しない)。
+  決着は `completed` / `indeterminate` の 2 つで、**release (再実行を許す) 経路を持たない**。
+  状態遷移は middleware の条件付き UPDATE のみが行うため `state` は `$fillable` に入れない。
+- **契約変更 (破壊的)**: 4xx/5xx で終わった要求の後、同じキーは再利用できない
+  (409 `idempotency_indeterminate`)。観測面は `api.v1.projects.items.{store,update,destroy}` の
+  3 route のみ。MCP write tool は 0 本のため観測面なし。
+- **cron**: `idempotency:prune` (daily・`onOneServer`) が保持期間
+  (`config/idempotency.php` の `retention_hours`。**env 不使用**) を超えた行を
+  REST / MCP 両テーブルから物理削除する。claim 時の lazy delete は
+  「再送されたキー」しか回収しないため単調増加を止められない。
+- **監視対象**: `idempotency:prune` の `report()`。`processing` のまま期限切れになった行は
+  「claim したのに確定できなかった要求」= プロセス強制終了か finalize 失敗の痕跡である
+  (載せるのは件数のみ。キー値・body は載せない)。
+- **閉じない窓 (誇張しない)**: OOM / timeout / プロセス強制終了で `processing` が残る窓は
+  閉じない。保持期間満了まで同一キーは 409 `idempotency_in_progress` を返し続ける。
+- **`onOneServer()` の前提**: scheduler が動いていることと、ロックを提供する cache driver が
+  使われていることが前提 (既存の `billing:send-billing-reminders` /
+  `render:reconcile-outputs` と同じ。本節で新しく持ち込む前提ではない)。
+  満たさないと多重実行しうるが DELETE は冪等で、害は `report()` の重複に留まる。
diff --git a/docs/factories.md b/docs/factories.md
index ba04075..1f04d00 100644
--- a/docs/factories.md
+++ b/docs/factories.md
@@ -32,7 +32,7 @@ ## Factory 一覧 (テンプレート同梱)
 | `TakeUploadReservationFactory` | TakeUploadReservation | `forCut($cut)` / `verifying()` / `completed()` / `released()` / `expired()`。`organization_id` は cut→manual→project→org を辿ってサーバ導出 (afterMaking) |
 | `ApiKeyFactory` | ApiKey | `forOrganization($org)`, `revoked()`, `expired(?Carbon $expiresAt = null)` |
 | `OrganizationInvitationFactory` | OrganizationInvitation | `forOrganization($org)`, `expired()`, `accepted()`, `revoked()`, `asAdmin()`。加えて `createWithPlainToken(array): array` (invitation と平文 token を tuple で返す。URL 生成用。DB には sha256 hash のみ保存) |
-| `IdempotencyKeyFactory` | IdempotencyKey | `forApiKey($apiKey)`, `expired(?Carbon $expiresAt = null)` |
+| `IdempotencyKeyFactory` | IdempotencyKey | `forApiKey($apiKey)`, `processing()` (claim 済み・応答未確定), `indeterminate()` (決着不明), `expired(?Carbon $expiresAt = null)` |
 | `OauthSessionFactory` | OauthSession | `cli()`, `mcp()`, `revoked()` |
 | `McpIdempotencyKeyFactory` | McpIdempotencyKey | `forOrganizationAndUser($org, $user)`, `expired()` |
 | `InquiryFactory` | Inquiry | `spam()`, `closed(int $closedDaysAgo = 0)`, `staleOpen(int $createdDaysAgo = 40)` |

```

---

## mutation 実測記録 (設計の予測と実測がずれた 3 件を含む)

# T139 mutation 実測記録

詳細設計 `devnotes/20260809-0027-idempotency-concurrent-claim/detailed-design.md`
「mutation で赤化を確認する手順」の全 27 項目を実施した記録。

- 実施日: 2026-08-09 (JST)
- 実施方法: `devnotes/20260809-0135-todo-T139/mutations.py` (一時 harness。
  mutation 適用 → `composer test -- --filter=<gate>` → 元へ戻す、を自動で回す)
- 生ログ: `mutation-run.log` / `mutation-raw.tsv`
- **入れた mutation は全て復元済み** (`git status --short` に mutation 由来の差分なし)

> harness の verdict 列 (`RED-AS-DESIGNED` / `RED-OTHER`) は自動判定の副産物で、
> Pest の evaluable 名が空白を `_` に潰すため部分一致が外れたものが `RED-OTHER` に
> 落ちている。**判定は下表のとおり失敗テスト名を目視で突き合わせて確定した**。

## 結果一覧

| # | mutation | 設計の予測 | 実測 | 判定 |
|---|---------|-----------|------|------|
| 1 | `routes/api.php` の write group から `'idempotent'` を外す | coverage テスト 2 | `母集団の変更系 route は idempotent をちょうど 1 本持つか…` + `正のコントロール` が赤 | ✅ 一致 (+1 本余分に赤) |
| 2 | coverage の母集団 prefix を `api/v2/` に | coverage テスト 1 | `母集団が下限を下回らない` + stale + 負のコントロール が赤 | ✅ 一致 |
| 3 | 免除の理由文字列を 10 文字に | coverage テスト 4 | `exemption inventory の値は enum + 実質的な理由文字列` が赤 | ✅ 一致 |
| 4 | `api.v1.me.session.revoke` に `'idempotent'` を付ける | coverage テスト 6 | `exemption inventory の key は idempotent を 1 本も持たない` が赤 | ✅ 一致 |
| 5 | 免除を 1 件増やす (架空 route) | coverage テスト 3 + 5 | stale + 件数上限 + case 別上限 の 3 本が赤 | ✅ 一致 (+case 別も赤) |
| 6 | 負のコントロールの probe route 登録を消す | coverage テスト 8 | `負のコントロール: idempotent 無しの…` が赤 | ✅ 一致 |
| 7 | `claim()` の `insertOrIgnore` を `insert` に戻す | ConcurrentClaim テスト 3 | **テスト 3 は緑のまま**。代わりに `処理中の同一キーは… 409 in_progress` ほか 6 本が赤 | ⚠ **設計の予測が外れた** (下記) |
| 8 | claim を実行前に行わない (事後 claim 相当) | ConcurrentClaim テスト 1 | `claim 行は controller 実行前に作られ…` ほか 6 本が赤 | ✅ 一致 |
| 9 | `finalize()` から `where('state', processing)` を外す | ConcurrentClaim テスト 10 | **テスト 10 は緑のまま**。実装中に追加した `finalize は processing の行しか書き換えない` が赤 | ⚠ **設計の予測が外れた** (下記) |
| 10 | `finalize()` の indeterminate 分岐を消す | IdempotencyTest 置換テスト | `バリデーション失敗は indeterminate として記録され…` が赤 | ✅ 一致 |
| 11 | `replayResponse()` から `Idempotent-Replayed` を外す | IdempotencyTest / OAuthDualGuard | `同一 Idempotency-Key の再送は保存レスポンスを再生する` が赤 | ✅ 一致 |
| 12 | `IdempotencyHeaders::REPLAYED` を `Idempotency-Replayed` に | parity テスト 5 | `マーカー区間の replay_header は…` が赤 | ✅ 一致 |
| 13 | `config` の 24 を 48 に | parity テスト 2 と 4 | `retention_hours は config と一致` + `24 に pin` が赤 | ✅ 一致 |
| 14 | `config` を `env(...)` に | parity テスト 3 | `config/idempotency.php は env() を使わない` が赤 | ✅ 一致 |
| 15 | `IdempotentRequest` に `TTL_HOURS` を戻す | parity テスト 8 | `保持期間のクラス定数が復活していない` が赤 | ✅ 一致 |
| 16 | 契約文書のマーカーを消す | parity テスト 1 | マーカー依存の 6 本すべてが例外で赤 | ✅ 一致 |
| 17 | `ToolName` に write tool の case を 1 本足す | MCP gate テスト 6 | `MCP write tool は 0 本である` が赤 | ✅ 一致 |
| 18 | `AppMcpTool::handle()` の `final` を外す | MCP gate テスト 3 | `AppMcpTool::handle() は final である` が赤 | ✅ 一致 |
| 19 | `isWriteTool()` に `default => false` を足す | MCP gate テスト 4 | `ToolName::isWriteTool() は網羅 match で書かれている` が赤 | ✅ 一致 |
| 20 | prune の state 別 DELETE を一括 DELETE に戻す | Prune の state 別集計 | `削除件数を state 別に出力する` + `processing の期限切れが 0 件なら report しない` が赤 | ✅ 一致 |
| 21 | prune から `expires_at <= cutoff` を外す | Prune の負のコントロール | `未期限の行は 1 件も削除しない` ほか 3 本が赤 | ✅ 一致 |
| 22 | migration の backfill を `indeterminate` に | Migration の backfill テスト | `既存行は completed へ backfill される` が赤 | ✅ 一致 |
| 23 | migration の `state` に DB default を残す | Migration テスト | `state 列は NOT NULL で DB default を持たない` が赤 | ✅ 一致 |
| 24 | `finalize()` の `json_encode` を外して配列のまま渡す | ConcurrentClaim テスト 11 | **全テスト緑のまま (赤化しない)** | ❌ **設計の予測が外れた** (下記) |
| 25 | `handle()` のキー長検証を外す | ConcurrentClaim テスト 12 | `255 文字を超える Idempotency-Key は 422 で…` が赤 | ✅ 一致 |
| 26 | `isExpired()` の引数型を `?Carbon` に戻す | ConcurrentClaim テスト 7 | `期限切れの processing 行は削除されて再 claim できる` ほか 6 本が赤 | ✅ 一致 |
| 27 | `loggableRouteName()` を `$request->path()` に戻す | (機械検査しない) | 実施せず。**レビュー観点として残す** (設計どおり) | — |

## 予測と実測がずれた 3 件 (辻褄を合わせずに記録する)

### mutation 7 — 予測したテストでは捕まらない

設計は「`insertOrIgnore` → `insert` は ConcurrentClaim テスト 3 が捕まえる」と予測したが、
**テスト 3 は middleware を通らず `IdempotencyKey::query()->insertOrIgnore()` を直接 2 回
呼ぶ**ため、middleware 側の変異では赤くならない。実際に赤くなったのは
「既存 claim 行がある状態で同一キーを送る」6 本で、`insert` が unique 違反を
例外にして 409 が 500 に化けることを捕まえている。

**帰結**: 変異は殺せているが、殺しているのは設計が想定した pin ではない。
テスト 3 は「unique 制約が調停者である」という **DB の性質**の直接証明であり、
middleware の実装変更に対する pin ではない (両者は別の役割。テストは残す)。

### mutation 9 — 設計のテストでは差分が出ない

設計は「`where('state', processing)` を外すと ConcurrentClaim テスト 10 の
`affected` が 1 になり report されなくなる」と予測したが、**テスト 10 は
ハンドラ内で claim 行を DELETE している**ため、state 条件の有無に関わらず
`affected = 0` になり差分が出ない。

**対処 (実装時に追加)**: `finalize は processing の行しか書き換えない (terminal 行を上書きしない)`
を `IdempotencyConcurrentClaimTest` に追加した。probe route のハンドラ内で claim 行を
`completed` + 別 body へ確定させ、finalize がそれを**上書きしない**ことと report されることを
固定する。この追加テストで mutation 9 は赤くなる (実測)。

### mutation 24 — pgsql では赤くならない (設計の前提が誤り)

設計は「`Builder::update()` は cast を通さないので、PHP 配列のままだと binding できず落ちる」と
述べていたが、**`Illuminate\Database\Query\Grammars\PostgresGrammar::prepareBindingsForUpdate()`
が `is_array($value)` の値を自動で `json_encode` する** (vendor 実装で確認)。
そのため配列をそのまま渡しても pgsql では正常に保存され、テスト 11 は緑のままだった。

**対処**: 明示 `json_encode` は**残す**が、コード上の主張を実測に合わせて訂正した
(「これが無いと落ちる」→「driver 非依存にするため + `JSON_THROW_ON_ERROR` で
失敗を握り潰さないため。pgsql では外しても壊れない」)。
**この変異を殺すテストは追加していない** — 観測可能な挙動差が pgsql 上に存在しないため、
テストで固定できるものが無い。誇張せずここに記録する。


---

## テスト結果

```
composer test          : 3956 tests / 3954 passed / 0 failed / 2 skipped / 17207 assertions
composer phpstan       : [OK] No errors (level 10, 832 files)
vendor/bin/pint --test : passed
pnpm lint              : passed
pnpm typecheck         : passed
pnpm test              : 128 files / 1268 tests passed
pnpm build             : built in 3.99s
pnpm typecheck:packages / build:packages : passed
pnpm test:packages     : 10 files / 106 tests passed
composer test:browser  : chromium 11 passed / 3 skipped、webkit 11 passed / 3 skipped
```

## 実装者からの申し送り (レビュー時に前提としてよい)

- **オーナーが破壊的変更を許容済み**。4xx 後の同一キー再送が 409 になる。周知はオーナー担当。
- **既存行の移行は completed** (indeterminate ではない)。現行実装は 2xx しか保存しないため決着は既知。
- **claim は既存 unique 2 本を調停者とする insertOrIgnore で行い cache ロックを併用しない**
  (best-effort の二重機構を作らない)。
- **middleware を terminable にしない**。`api.project-in-org < api-key.ability < idempotent` の
  順序契約は不変で、既存 2 gate (`TenantBoundaryOrderingTest` / `ProjectRouteCurrentOrgGuardTest`) は
  無改修のまま緑である。
- **MCP は据え置き** (write tool 0 本の trip-wire で最初の write tool 追加時に必要作業を提示)。T109 も据え置き。
- 保持期間 24h は `config/idempotency.php` に SoT を置き env 不使用。外部公開文書へ載せるかは未決。
- **並列で他 2 タスクが走っているため、共有ファイル (`AGENTS.md` / `docs/architecture.md` /
  `docs/app-integration-guide.md`) は「既存行を書き換えず末尾追記」の規律で編集している**
  (`docs/factories.md` と `docs/TODO.md` は既存行のセル内追記を 1 行ずつ行った)。

## 設計から外れた実装判断 (実装者が明示する逸脱)

1. **`IdempotencyConcurrentClaimTest` にテストを 1 本追加した**
   (`finalize は processing の行しか書き換えない (terminal 行を上書きしない)`)。
   設計の mutation 9 (`finalize()` から `where('state', processing)` を外す) が
   設計の予測したテスト 10 では赤くならなかったため (テスト 10 は claim 行を DELETE しており
   state 条件の有無で差が出ない)。追加テストで mutation 9 は赤くなることを実測した。
2. **`finalize()` の `json_encode` に関する設計のコメント主張を訂正した**。
   設計は「配列のままだと binding できず落ちる」としていたが、pgsql では
   `PostgresGrammar::prepareBindingsForUpdate()` が配列を自動 json_encode するため
   mutation 24 は赤くならなかった。明示 encode は残しつつ、コメントを実測に合わせて弱めた。
3. **`docs/architecture.md` はモデル表の既存行を書き換えず、末尾に新節を追加した**
   (並列タスクとの衝突回避。設計はモデル表への追記を想定していた)。
4. **prune コマンドに `deletedRows()` private helper を足した**
   (PHPStan level 10 で `Builder::delete()` が `mixed` のため。`Assert::integer` で narrow)。

以上を踏まえてレビューしてください。
