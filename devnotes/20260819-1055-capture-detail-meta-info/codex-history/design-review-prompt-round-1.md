# 前提 (AGENTS.md より)

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
10. DESIGN.md準拠: design token 経由で参照する設計か、hex 直書きを増やさないか
11. Atomic Design準拠: atoms/molecules/organisms/features/templates の責務分離に沿った配置か。アイコンは Lucide 前提で SVG 直書きを新設していないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: 撮影 PWA シナリオ詳細画面のメタ情報表示

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
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- `declare(strict_types=1)` + 日本語コメント
- フロントは Svelte 5 runes + DS token/ramp のみ（`DESIGN.md` が canonical、ds-purity テストが検出）
- component 階層は `atoms → molecules → organisms → features/{domain} → templates → pages` の単方向 import
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

### 本件に効くドメイン固有規約

- **ドメイン規約 12 (採用テイク充足判定の単一化)**: 「採用済みかつ ready のテイクを持つか」の判定式を
  書いてよいのは `Services/Manual/AdoptedReadyTakeCoverage` ただ 1 ファイル。
  `adoptedTake` を参照する `app/` 配下のファイルは `AdoptedTakeReferenceInventory` へ
  区分 + 30 文字以上の根拠で登録が必須 (`AdoptedReadyTakeCriterionInventoryTest` が
  deny-by-default + exact-fit)。**本設計で新設する 2 クラスは relation を引数で受けるため登録は増えない**。
- **PII (name) は CipherSweet**。作成者名は表示目的のみで、検索には使わない。

## 概念設計リファレンス

- `devnotes/20260819-1055-capture-detail-meta-info/conceptual-design.md` (Round 4 で APPROVED)
- 判断の出所: 要件 `doc/05_スマホアプリ機能仕様.md` §5.2 L37 /
  要件カバレッジ監査 (2026-08-18) の指摘 / オーナー判断 (2026-08-19)「要件どおり作る」

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | カット単位の確定尺の式を 1 クラスへ (`DeterminedCutDuration` 新設 + レンダ上限ゲートの乗せ換え) | `app/Services/Manual/DeterminedCutDuration.php` (新規) / `app/Services/Manual/RenderJobService.php` / `tests/Unit/Manual/DeterminedCutDurationTest.php` (新規) / `tests/Feature/Manual/RenderJobTriggerTest.php` 系 | 高 |
| 2 | シナリオ全体の確定尺の集計 (`DeterminedScenarioDuration` 新設) | `app/Services/Manual/DeterminedScenarioDuration.php` (新規) / `tests/Unit/Manual/DeterminedScenarioDurationTest.php` (新規) | 高 |
| 3 | 撮影詳細 DTO へメタ情報 5 キーを追加 | `app/DataTransferObjects/Capture/CaptureManualDetailData.php` / `app/Http/Controllers/Capture/CaptureManualController.php` | 高 |
| 4 | TS 型の追随 | `resources/js/types/capture.ts` | 高 |
| 5 | メタ情報の表示 component 新設と詳細画面への配線 | `resources/js/components/features/capture/ManualMetaSummary.svelte` (新規) / `resources/js/pages/Capture/Show.svelte` | 高 |
| 6 | 既存テストの追随と契約の固定 | `tests/Feature/Capture/CaptureManualBrowsingTest.php` / `tests/Feature/Capture/CaptureManualDetailQueryCountTest.php` (新規) / `tests/js/components/features/capture/ManualMetaSummary.test.ts` (新規) / `tests/js/pages/CaptureShow.test.ts` | 高 |

---

## 施策 1: カット単位の確定尺の式を 1 クラスへ

### 変更箇所

- 新規: `app/Services/Manual/DeterminedCutDuration.php`
- 変更: `app/Services/Manual/RenderJobService.php` (L439-468 `assertTotalSourceDurationWithinLimit`)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし (施策 3 が利用するだけ)
- テストファイル: `tests/Unit/Manual/DeterminedCutDurationTest.php` (新規) /
  レンダ上限ゲートの既存 Feature テスト (境界値の追加)
- **`AdoptedTakeReferenceInventory`: 変更なし**。新クラスは `adoptedTake` relation を読まず
  引数で受けるため (`EffectiveMaterialType` と同じ作法)。`RenderJobService` は登録済みのまま。

### 現行コード

```php
// app/Services/Manual/RenderJobService.php
    /**
     * 尺上限ソフトゲート (§10.8-1: TTL 内 commit)。クライアント申告値ベースで、
     * ハード保証はジョブ timeout が担う。duration_ms NULL は保守的な既定尺で代用する。
     *
     * @param  list<OrderedCut>  $ordered
     */
    private function assertTotalSourceDurationWithinLimit(array $ordered): void
    {
        $defaultMs = config()->integer('manual.render_default_take_duration_ms');
        $totalMs = 0;
        foreach ($ordered as $entry) {
            $cut = $entry->cut;
            $take = $cut->adoptedTake;
            // ここへ来る時点で採用テイクは確定している (充足判定 = AdoptedReadyTakeCoverage が先に 422 を出す)
            Assert::notNull($take, '充足判定を通った cut には採用テイクが必ず存在する');

            // レンダ (RenderPipeline::clipSpecFor) と**同じ 2 クラス**を通す。
            // 片方だけ実効判定を持つと、cut=video/take=still の組み合わせで
            // ゲート 60 秒 / レンダ 5 秒という新しい二重管理が生まれる
            $totalMs += EffectiveMaterialType::of($cut, $take) === MaterialType::Still
                ? StillDisplayDuration::secondsFor($cut) * 1000
                : ($take->duration_ms ?? $defaultMs);
        }

        if ($totalMs > config()->integer('manual.render_max_total_source_ms')) {
            throw ValidationException::withMessages([
                'takes' => ['動画の合計尺が上限を超えています。マニュアルを分割してください。'],
            ]);
        }
    }
```

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\Enums\Manual\MaterialType;
use App\Models\Cut;
use App\Models\Take;

/**
 * 「このカット 1 本の尺は何 ms か。決まっていないなら決まっていない」を返す式の**唯一の所在**。
 *
 * 決まり方は 2 通りしかない:
 *   - 静止画として合成されるカット … `StillDisplayDuration::secondsFor()` × 1000。
 *     **撮影前でも決まる** (編集者がシナリオ編集で入れる計画値だから)。
 *   - 動画として合成されるカット … 採用済みかつ ready のテイクの `duration_ms`。
 *     テイクが無い / テイクの `duration_ms` が NULL なら**決まらない** (null を返す)。
 *
 * **null を既定値で埋めない**。埋めたい側 (レンダの尺上限ソフトゲートは上界を安全側に見たいので
 * `config('manual.render_default_take_duration_ms')` で埋める) が自分の政策として埋める。
 * 表示に使う側は埋めずに「未確定」として数える。ここで埋めると、
 * 撮っていないカットに 1 分あると利用者へ嘘をつくことになる。
 *
 * **採用テイクは引数で受ける** (このクラスは `adoptedTake` relation を読まない)。
 * したがって `AdoptedTakeReferenceInventory` の登録は増えない
 * (`EffectiveMaterialType` と同じ作法)。
 *
 * **ready 判定は一切しない** — 「採用済みかつ ready か」の述語は
 * `AdoptedReadyTakeCoverage` の専権である (AGENTS.md ドメイン固有規約 12)。
 * 呼び出し側がその述語で解決した結果を `$adoptedReadyTake` に渡す。
 *
 * **ナレーション尺は見ない**。v1 は字幕のみで TTS を持たず、ナレーション文に再生時間という
 * 属性が存在しない (`StillDisplayDuration` の docblock と同じ理由・同じ再検討条件)。
 */
final class DeterminedCutDuration
{
    /**
     * @param  Take|null  $adoptedReadyTake  採用済みかつ ready のテイク
     *                                       (`AdoptedReadyTakeCoverage` で解決済みのもの。無ければ null)
     * @return int|null 確定している尺 (ms)。確定していなければ null
     */
    public static function milliseconds(Cut $cut, ?Take $adoptedReadyTake): ?int
    {
        // テイクがまだ無いカットでも、計画が静止画なら尺は決まっている
        if ($adoptedReadyTake === null) {
            return $cut->material_type === MaterialType::Still
                ? StillDisplayDuration::secondsFor($cut) * 1000
                : null;
        }

        // 実体優先の判定 (cut=video / take=still の組み合わせを含む) は EffectiveMaterialType が持つ
        if (EffectiveMaterialType::of($cut, $adoptedReadyTake) === MaterialType::Still) {
            return StillDisplayDuration::secondsFor($cut) * 1000;
        }

        return $adoptedReadyTake->duration_ms;
    }
}
```

`RenderJobService` 側は式を持たず、**自分の政策 (安全側の代用値) だけ**を残す:

```php
    /**
     * 尺上限ソフトゲート (§10.8-1: TTL 内 commit)。クライアント申告値ベースで、
     * ハード保証はジョブ timeout が担う。
     *
     * **尺の式は持たない** (`DeterminedCutDuration` が唯一の所在)。
     * ここに残るのは「確定していないカットを上界として何 ms とみなすか」という
     * **このゲートだけの政策**である (撮影 PWA の表示側は埋めずに未確定として数える)。
     *
     * @param  list<OrderedCut>  $ordered
     */
    private function assertTotalSourceDurationWithinLimit(array $ordered): void
    {
        $defaultMs = config()->integer('manual.render_default_take_duration_ms');
        $totalMs = 0;
        foreach ($ordered as $entry) {
            $cut = $entry->cut;
            $take = $cut->adoptedTake;
            // ここへ来る時点で採用テイクは確定している (充足判定 = AdoptedReadyTakeCoverage が先に 422 を出す)
            Assert::notNull($take, '充足判定を通った cut には採用テイクが必ず存在する');

            $totalMs += DeterminedCutDuration::milliseconds($cut, $take) ?? $defaultMs;
        }

        if ($totalMs > config()->integer('manual.render_max_total_source_ms')) {
            throw ValidationException::withMessages([
                'takes' => ['動画の合計尺が上限を超えています。マニュアルを分割してください。'],
            ]);
        }
    }
```

**挙動が 1 ビットも変わらないことの確認** (採用テイクが必ず非 null な文脈なので、
新クラスの `$adoptedReadyTake === null` 分岐には入らない):

| 入力 | 変更前 | 変更後 |
|---|---|---|
| 実効 still | `StillDisplayDuration::secondsFor($cut) * 1000` | 同じ (新クラスの still 分岐) |
| 実効 video / `duration_ms` 非 NULL | `$take->duration_ms` | 同じ |
| 実効 video / `duration_ms` NULL | `$defaultMs` | `null ?? $defaultMs` = 同じ |

`EffectiveMaterialType` / `MaterialType` の import は `RenderJobService` から不要になれば削除する
(**後方互換の並走を残さない**。他の用途で使っていれば残す — 実装時に `use` の実使用を確認する)。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`?int`)
- [x] null 安全 (`?Take` を早期 return で分岐。`Assert` は呼び出し側の既存箇所のまま)
- [x] DTO を返している (スカラーの式なので該当しない。配列は返さない)
- [x] Generics の型パラメータが正しい (該当なし)

### テスト計画

- [ ] 新規 `tests/Unit/Manual/DeterminedCutDurationTest.php` — **先に赤くしてから**実装する
  - テイク無し + `cut.material_type = still` → `static_display_seconds` × 1000
  - テイク無し + `cut.material_type = still` かつ `static_display_seconds` 未指定 →
    `config('manual.default_still_display_seconds')` × 1000
  - テイク無し + `cut.material_type = video` → `null`
  - テイク無し + `cut.material_type` 未指定 (NULL) → `null`
  - テイクあり + 実効 still (cut=video / take=still の組み合わせを含む) → 静止表示秒 × 1000
  - テイクあり + 実効 video + `duration_ms` 非 NULL → その値
  - テイクあり + 実効 video + `duration_ms` NULL → `null` (**既定値で埋めない**)
- [ ] レンダ上限ゲートの境界値 (既存の Feature テストへ追加。挙動不変の固定)
  - 合計がちょうど `render_max_total_source_ms` → 通る
  - 合計が上限 +1ms → 422
  - `duration_ms` NULL の採用テイクが 1 本 → `render_default_take_duration_ms` で数えられる
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **式の切り出しでレンダ上限の挙動が動く**。上表の 3 分岐と境界値テストで固定する。
- `Take` を引数に取るので、呼び出し側が「ready でないテイク」を渡すと未撮影のカットに
  尺が付く。**渡す責任は呼び出し側**であり、docblock で `AdoptedReadyTakeCoverage` 解決済みと明記する
  (述語をこのクラスへ持ち込むとドメイン規約 12 違反になるため、型では防げない)。

---

## 施策 2: シナリオ全体の確定尺の集計

### 変更箇所

- 新規: `app/Services/Manual/DeterminedScenarioDuration.php`

### 波及変更

- TypeScript 型定義: なし (施策 4 が DTO のキーとして受ける)
- API Resource/DTO: 施策 3 が利用する
- テストファイル: `tests/Unit/Manual/DeterminedScenarioDurationTest.php` (新規)

### 現行コード

なし (新規)。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Services\Manual;

/**
 * 「このシナリオで**いま尺が確定している分**は合わせて何 ms か、確定していないカットは何本か」。
 *
 * **完成動画の見込み尺ではない**。未撮影の動画カットの尺は v1 では原理的に出せないので
 * (ナレーション尺の推定を持たない = `DeterminedCutDuration` の docblock)、
 * ここが表すのは確定分の合計だけである。**未確定を 0 ms として足さない**。
 * 1 本も確定していなければ合計は `null` で、表示側は「—」を出す
 * (`resources/js/lib/manual/format-duration.ts` の `DURATION_UNKNOWN` と同じ思想。
 * 未確定を `0:00` と書くと「長さゼロの動画がある」という別の嘘になる)。
 *
 * **入力はカット 1 本ずつの確定尺の配列だけ**である (`Cut` も `Take` も受け取らない)。
 * したがって `adoptedTake` relation を読みようがなく、
 * `AdoptedTakeReferenceInventory` の登録は増えない。
 * 採用済みかつ ready のテイクの解決は呼び出し側 (`AdoptedReadyTakeCoverage` 経由) の責務である。
 */
final readonly class DeterminedScenarioDuration
{
    /**
     * @param  int|null  $totalDurationMs  確定分の合計 (ms)。1 本も確定していなければ null
     * @param  int  $undeterminedCutCount  尺が確定していないカット数
     */
    public function __construct(
        public ?int $totalDurationMs,
        public int $undeterminedCutCount,
    ) {}

    /**
     * @param  list<int|null>  $perCutDurationsMs  カットの表示順に並べた確定尺
     *                                             (`DeterminedCutDuration::milliseconds()` の戻り値)
     */
    public static function fromCutDurations(array $perCutDurationsMs): self
    {
        $determined = array_values(array_filter(
            $perCutDurationsMs,
            static fn (?int $ms): bool => $ms !== null,
        ));

        return new self(
            totalDurationMs: $determined === [] ? null : array_sum($determined),
            undeterminedCutCount: count($perCutDurationsMs) - count($determined),
        );
    }
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`self`)
- [x] null 安全 (`?int` を明示。`array_filter` の callback に型を書く)
- [x] DTO を返している (`final readonly class` の結果型。連想配列を返さない)
- [x] Generics の型パラメータが正しい (`@param list<int|null>` を PHPDoc で固定。
      **実引数の型宣言は `array`** — `list<int|null>` は PHP の型宣言には書けない)
- [x] `array_sum` の戻り値は `int` (要素が `int` だけなので `int|float` に広がらない。
      PHPStan が `float` を疑うようなら `(int)` ではなく `array_sum` の入力型を
      `list<int>` に絞ったローカル変数で受ける)

### テスト計画

- [ ] 新規 `tests/Unit/Manual/DeterminedScenarioDurationTest.php` — **先に赤くしてから**実装する
  - 空配列 → `totalDurationMs = null` / `undeterminedCutCount = 0`
  - 全件 null → `totalDurationMs = null` / `undeterminedCutCount = 件数`
  - 混在 (`[1000, null, 2500]`) → `totalDurationMs = 3500` / `undeterminedCutCount = 1`
  - 全件確定 (`[1000, 2000]`) → `totalDurationMs = 3000` / `undeterminedCutCount = 0`
  - 確定分が 0 ms だけ (`[0]`) → `totalDurationMs = 0` (**null にしない** =
    「確定していて 0 ms」と「確定していない」を区別する)

### リスク

- 「合計 0 ms」と「未確定」の取り違え。上の最後のケースで固定する。

---

## 施策 3: 撮影詳細 DTO へメタ情報 5 キーを追加

### 変更箇所

- `app/DataTransferObjects/Capture/CaptureManualDetailData.php` (全体)
- `app/Http/Controllers/Capture/CaptureManualController.php` (L117-142 `show`)

### 波及変更

- TypeScript 型定義: `resources/js/types/capture.ts` の `CaptureManualDetail` (施策 4)
- API Resource/DTO: 本施策そのもの
- テストファイル: `tests/Feature/Capture/CaptureManualBrowsingTest.php` (キー集合契約の追加) /
  `tests/Feature/Capture/CaptureManualDetailQueryCountTest.php` (新規。施策 6)
- **`AdoptedTakeReferenceInventory`**: 登録済みファイルの**根拠文の更新**を検討する
  (参照の性質は変わらない = 区分 `DelegatedToCoverage` のまま。
  「解決済みの採用テイクを尺の式へも渡す」ことを根拠へ 1 文足す)。
  区分・件数は変わらないので gate の pin は動かない。

### 現行コード

```php
// CaptureManualDetailData
    public function __construct(
        public VideoManual $manual,
        public array $cuts,
    ) {}

    public static function fromManual(VideoManual $manual, User $user, TakeObjectStorage $storage, UploadTicketCodec $codec): self
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, Cut> $cuts */
        $cuts = $manual->cuts()->with('adoptedTake')->orderBy('sort_order')->get();
        // ... grouped で step → その points の順に並べ替え
        $cutData = [];
        foreach ($grouped->get(0) ?? $empty as $step) {
            $cutData[] = self::cutWithAdoptedUrls($step, $user, $storage, $codec, $ackExpiry);
            foreach ($grouped->get($step->id) ?? $empty as $point) {
                $cutData[] = self::cutWithAdoptedUrls($point, $user, $storage, $codec, $ackExpiry);
            }
        }

        return new self($manual, $cutData);
    }

    private static function cutWithAdoptedUrls(Cut $cut, User $user, TakeObjectStorage $storage, UploadTicketCodec $codec, int $ackExpiry): CaptureCutData
    {
        if (AdoptedReadyTakeCoverage::readyTakeId($cut) === null) {
            return CaptureCutData::fromCut($cut);
        }

        $adopted = $cut->adoptedTake;
        Assert::notNull($adopted, 'readyTakeId() が非 null なら採用テイクは必ず存在する');

        return CaptureCutData::fromCut(
            $cut,
            adoptedPlaybackUrl: $storage->temporaryPlaybackUrl($adopted->video_path),
            adoptedAckToken: $codec->sealAck(new DownloadAckClaims(...)),
        );
    }

    /**
     * @return array{id: int, title: string, status: string, cuts: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->manual->id,
            'title' => $this->manual->title,
            'status' => $this->manual->status->value,
            'cuts' => array_map(...),
        ];
    }
```

### 変更後コード

**設計上の要点**:

1. カットの並べ替えループは**そのまま 1 パス**で、その中で
   「採用済みかつ ready のテイク」を**1 度だけ解決**して (a) 署名 URL / ACK と
   (b) 確定尺の 2 つに使う。**述語を 2 回評価しない / 2 か所で組み立てない**。
   そのために `cutWithAdoptedUrls` を「解決済みテイクを引数で受ける」形へ割り、
   解決 (`AdoptedReadyTakeCoverage::readyTakeId()` + `Assert`) を呼び出し元の 1 か所へ寄せる。
2. カテゴリ名 / 作成者名 / 更新日は**コンストラクタでスカラーとして受け取る**
   (`toArray()` の中で relation を触ると、eager load されていないときに lazy load が走る)。
3. `VideoManual $manual` は id / title / status のために保持する (既存の形を変えない)。

```php
final readonly class CaptureManualDetailData
{
    /**
     * @param  list<CaptureCutData>  $cuts
     * @param  string|null  $updatedAt  ISO 8601 文字列 (Carbon をそのまま props へ渡さない)
     */
    public function __construct(
        public VideoManual $manual,
        public array $cuts,
        public ?string $categoryName,
        public ?string $creatorName,
        public ?string $updatedAt,
        public DeterminedScenarioDuration $duration,
    ) {}

    public static function fromManual(VideoManual $manual, User $user, TakeObjectStorage $storage, UploadTicketCodec $codec): self
    {
        // (既存) step 順 → 各 step 直後にその points。adoptedTake は eager load 必須
        /** @var \Illuminate\Database\Eloquent\Collection<int, Cut> $cuts */
        $cuts = $manual->cuts()->with('adoptedTake')->orderBy('sort_order')->get();
        /** @var Collection<int, Collection<int, Cut>> $grouped */
        $grouped = $cuts->toBase()->groupBy(static fn (Cut $cut): int => $cut->parent_cut_id ?? 0);
        /** @var Collection<int, Cut> $empty */
        $empty = new Collection;

        $ackExpiry = now()->addMinutes(config()->integer('capture.playback_url_ttl_minutes'))->getTimestamp();
        $cutData = [];
        /** @var list<int|null> $durationsMs 表示順に並べたカットごとの確定尺 */
        $durationsMs = [];
        foreach ($grouped->get(0) ?? $empty as $step) {
            self::appendCut($step, $user, $storage, $codec, $ackExpiry, $cutData, $durationsMs);
            foreach ($grouped->get($step->id) ?? $empty as $point) {
                self::appendCut($point, $user, $storage, $codec, $ackExpiry, $cutData, $durationsMs);
            }
        }

        return new self(
            manual: $manual,
            cuts: $cutData,
            // 表示目的のみ。User.name は CipherSweet PII のため検索には使わない
            // (一覧 CaptureManualSummaryData と同じ形。退会/削除で解決不可なら null)
            categoryName: $manual->category?->name,
            creatorName: $manual->creator?->name,
            updatedAt: $manual->updated_at?->toIso8601String(),
            duration: DeterminedScenarioDuration::fromCutDurations($durationsMs),
        );
    }

    /**
     * 1 カット分を直列化し、同時にそのカットの確定尺を積む。
     *
     * **採用済みかつ ready のテイクの解決はここ 1 か所だけ**である
     * (`AdoptedReadyTakeCoverage` が唯一の述語。署名 URL の発行条件と尺の算出条件を
     * 別々に組み立てると、片方だけ変わって乖離する)。
     *
     * @param  list<CaptureCutData>  $cutData
     * @param  list<int|null>  $durationsMs
     * @param-out list<CaptureCutData>  $cutData
     * @param-out list<int|null>  $durationsMs
     */
    private static function appendCut(
        Cut $cut,
        User $user,
        TakeObjectStorage $storage,
        UploadTicketCodec $codec,
        int $ackExpiry,
        array &$cutData,
        array &$durationsMs,
    ): void {
        $adopted = AdoptedReadyTakeCoverage::readyTakeId($cut) === null ? null : $cut->adoptedTake;
        // 述語が非 null なら採用テイクは必ず存在する。PHPStan level 10 は静的には ?Take のままなので
        // Assert で絞る (述語の再実装ではない = TakeStatus を参照しない)。
        if (AdoptedReadyTakeCoverage::readyTakeId($cut) !== null) {
            Assert::notNull($adopted, 'readyTakeId() が非 null なら採用テイクは必ず存在する');
        }

        $cutData[] = $adopted === null
            ? CaptureCutData::fromCut($cut)
            : CaptureCutData::fromCut(
                $cut,
                adoptedPlaybackUrl: $storage->temporaryPlaybackUrl($adopted->video_path),
                adoptedAckToken: $codec->sealAck(new DownloadAckClaims(
                    takeId: $adopted->id,
                    userId: $user->id,
                    expiresAtTimestamp: $ackExpiry,
                )),
            );

        // 尺の式は DeterminedCutDuration が唯一の所在 (ここで組み立て直さない)
        $durationsMs[] = DeterminedCutDuration::milliseconds($cut, $adopted);
    }

    /**
     * @return array{id: int, title: string, status: string, category_name: string|null,
     *   creator_name: string|null, updated_at: string|null, total_duration_ms: int|null,
     *   undetermined_cut_count: int, cuts: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->manual->id,
            'title' => $this->manual->title,
            'status' => $this->manual->status->value,
            'category_name' => $this->categoryName,
            'creator_name' => $this->creatorName,
            'updated_at' => $this->updatedAt,
            // 「確定している分の合計」であって完成見込み尺ではない (DeterminedScenarioDuration)
            'total_duration_ms' => $this->duration->totalDurationMs,
            'undetermined_cut_count' => $this->duration->undeterminedCutCount,
            'cuts' => array_map(
                static fn (CaptureCutData $cut): array => $cut->toArray(),
                $this->cuts,
            ),
        ];
    }
}
```

> **実装時の整理**: 上の `appendCut` は `readyTakeId()` を 2 回呼ぶ形で書いてあるが、
> 実装では**1 回の呼び出しをローカル変数へ受けて**分岐する
> (`$readyTakeId = AdoptedReadyTakeCoverage::readyTakeId($cut);` → `if ($readyTakeId !== null) { ... }`)。
> ここでは差分を読みやすくするため既存コードの表現を残している。
> **述語の評価は 1 カットにつき 1 回**にすること。

Controller 側は relation の事前ロードだけを足す:

```php
// CaptureManualController::show
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        // メタ情報 (カテゴリ名 / 作成者名) の解決を DTO 側の lazy load に任せない。
        // 対象は 1 行なので追加は最大 2 クエリ (既にロード済みなら 0)。
        $manual->loadMissing(['category', 'creator']);

        return Inertia::render('Capture/Show', [ /* 既存のまま */ ]);
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`toArray` の array shape を 5 キーぶん更新)
- [x] null 安全 (`?->` と `Assert` で絞る。`$adopted` は `?Take`)
- [x] DTO を返している (配列返却は `toArray()` のみ = Inertia props 直前)
- [x] Generics の型パラメータが正しい (`Collection<int, Cut>` の既存注釈を維持)
- [x] 参照渡しの `@param-out` を書く (PHPStan level 10 は参照引数の型変化を追う)

### テスト計画

- [ ] `tests/Feature/Capture/CaptureManualBrowsingTest.php` に
      **manual 直下のキー集合の pin を新設**する (現在は cut / take の shape しか固定していない)
  ```php
  expect(array_keys($props['manual']))->toBe([
      'id', 'title', 'status', 'category_name', 'creator_name', 'updated_at',
      'total_duration_ms', 'undetermined_cut_count', 'cuts',
  ]);
  ```
- [ ] 値の検証: カテゴリ有り/無し・作成者有り・`updated_at` が ISO 8601 文字列
- [ ] 合計の検証: 静止画カット 1 本 (未撮影) + 動画カット 1 本 (採用 ready・`duration_ms` あり)
      → 合計 = 静止表示秒 × 1000 + `duration_ms` / 未確定 0 件
- [ ] 未確定の検証: 動画カット 1 本を未撮影にする → 未確定 1 件・合計はもう 1 本ぶんだけ
- [ ] 既存テストの回帰: 署名 URL / ACK トークンの発行条件が変わっていないこと
      (`appendCut` への割り替えで壊しやすい最重要点)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **`cutWithAdoptedUrls` → `appendCut` の割り替えで署名 URL の発行条件が壊れる**。
  既存の「採用テイクのみ非 null」テストが回帰として効く。加えて
  「採用済みだが ready でないテイク」で `playback_url` が null のままであることを確認する。
- `loadMissing` を忘れると lazy load で 2 クエリ増えるが N+1 にはならない (対象は 1 行)。
  それでもクエリ数テスト (施策 6) で「カット数・テイク数に比例しない」ことを固定する。

---

## 施策 4: TS 型の追随

### 変更箇所

- `resources/js/types/capture.ts` の `CaptureManualDetail`

### 波及変更

- TypeScript 型定義: 本施策そのもの
- API Resource/DTO: 施策 3 と 1:1 で対応する
- テストファイル: `tests/js/pages/CaptureShow.test.ts` の fixture (施策 6)

### 現行コード

```ts
export interface CaptureManualDetail {
    id: number;
    title: string;
    status: string;
    cuts: CaptureCut[];
}
```

### 変更後コード

```ts
export interface CaptureManualDetail {
    id: number;
    title: string;
    status: string;
    /** カテゴリ名。未分類は null (UI は「未分類」) */
    category_name: string | null;
    /** 作成者名。退会/削除で解決不可のときは null (UI は「不明」) */
    creator_name: string | null;
    /** 更新日時 (ISO 8601)。UI は lib/date-format.ts の formatDate で描く */
    updated_at: string | null;
    /**
     * **いま尺が確定しているカットだけ**の合計 (ms)。1 本も確定していなければ null。
     * **完成動画の見込み尺ではない** — 未撮影の動画カットの尺は v1 では出せない
     * (PHP 側 Services/Manual/DeterminedScenarioDuration が正本)。
     * PC 一覧の `duration_ms` (公開済み完成動画の実尺) とは**別の量**なので統合しない。
     */
    total_duration_ms: number | null;
    /** 尺が確定していないカット数。**常に併記する** (— だけでは「カット無し」と区別できない) */
    undetermined_cut_count: number;
    cuts: CaptureCut[];
}
```

### PHPStan 適合チェック

該当なし (TypeScript)。`pnpm typecheck` で確認する。

### テスト計画

- [ ] `pnpm typecheck` が通る (fixture を更新しないと既存テストが型エラーになる = 先に赤くなる)
- [ ] PHP 側キー集合 pin (施策 3) と TS 型が 1:1 であることは、
      `CaptureManualBrowsingTest` の `array_keys` 比較が担う (既存の運用と同じ)

### リスク

- PHP と TS の食い違い。キー集合 pin が検出する。
  **列挙値ではないので `enum-ts-sync` gate の登録対象ではない** (ドメイン規約 19 の母集団外)。

---

## 施策 5: メタ情報の表示 component 新設と詳細画面への配線

### 変更箇所

- 新規: `resources/js/components/features/capture/ManualMetaSummary.svelte`
- 変更: `resources/js/pages/Capture/Show.svelte` (L476-496 のヘッダ直下)

### 波及変更

- TypeScript 型定義: なし (props は施策 4 の型から受ける)
- API Resource/DTO: なし
- テストファイル: `tests/js/components/features/capture/ManualMetaSummary.test.ts` (新規) /
  `tests/js/pages/CaptureShow.test.ts` (fixture 更新 + 配線の確認)

### 現行コード

`Capture/Show.svelte` はヘッダにタイトルと 2 本のリンクだけを持つ:

```svelte
        <div inert={fullscreenActive}>
            <PageHeaderSection title={manual.title} icon={Video} testId="capture-manual-title">
                <TextLink href={`/app/projects/${project.id}/manuals`}>…一覧へ戻る</TextLink>
                <TextLink href={`/projects/${project.id}/manuals/${manual.id}`} …>…マニュアル詳細へ</TextLink>
            </PageHeaderSection>
        </div>
```

### 変更後コード

新規 component:

```svelte
<script lang="ts">
    import { Clock } from "@lucide/svelte";
    import { formatDate } from "@/lib/date-format";
    import { formatDurationMs } from "@/lib/manual/format-duration";

    /**
     * 撮影 PWA シナリオ詳細のメタ情報 (doc/05 §5.2: タイトル / TIME 合計 / カテゴリ・日付・作成者)。
     * タイトルは PageHeaderSection の h1 が持つので、ここは残り 4 つを出す。
     *
     * **合計時間は「いま尺が確定している分」の合計**であり完成動画の見込み尺ではない。
     * 判定も整形規則もサーバから来た 2 つの値だけで決め、ここで条件を足さない
     * (秘匿境界も算出も props 側で解決済み)。
     *
     * PC 一覧の「再生時間」(公開済み完成動画の実尺) とは**別の量**なので同じ語を使わない。
     */
    interface Props {
        categoryName: string | null;
        creatorName: string | null;
        updatedAt: string | null;
        /** 確定分の合計 (ms)。1 本も確定していなければ null */
        totalDurationMs: number | null;
        /** 尺が確定していないカット数 */
        undeterminedCutCount: number;
    }

    let {
        categoryName,
        creatorName,
        updatedAt,
        totalDurationMs,
        undeterminedCutCount,
    }: Props = $props();

    /** 未確定が 1 件でもあれば、値が部分和であることを値の隣で言う */
    const durationNote = $derived(
        undeterminedCutCount === 0 ? null : `確定分・未確定 ${undeterminedCutCount} カット`,
    );
</script>

<div
    class="rounded-md border border-border bg-surface px-3 py-2"
    data-testid="capture-manual-meta"
>
    <p class="flex items-center gap-1 text-body" data-testid="capture-manual-duration">
        <Clock class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
        合計時間 {formatDurationMs(totalDurationMs)}{#if durationNote !== null}<span
                class="text-caption text-text-secondary">（{durationNote}）</span
            >{/if}
    </p>
    <p class="mt-1 text-caption text-text-secondary" data-testid="capture-manual-meta-line">
        {categoryName ?? "未分類"} ・ {creatorName ?? "不明"} ・ 更新 {formatDate(updatedAt)}
    </p>
</div>
```

`Capture/Show.svelte` の配線 (全画面時に `inert` になる既存 div の**中**へ置く。
全画面は撮影に集中する面なのでメタ情報は覆われたままでよい):

```svelte
        <div inert={fullscreenActive}>
            <PageHeaderSection title={manual.title} icon={Video} testId="capture-manual-title">
                …既存のリンク 2 本…
            </PageHeaderSection>
            <!-- doc/05 §5.2 のシナリオメタ情報。タイトルは上の h1 が持つ -->
            <div class="mt-3">
                <ManualMetaSummary
                    categoryName={manual.category_name}
                    creatorName={manual.creator_name}
                    updatedAt={manual.updated_at}
                    totalDurationMs={manual.total_duration_ms}
                    undeterminedCutCount={manual.undetermined_cut_count}
                />
            </div>
        </div>
```

### DESIGN.md / Atomic Design 適合

- 使う class は既存の token 系のみ: `border-border` / `bg-surface` / `text-body` /
  `text-caption` / `text-text-secondary` / `rounded-md`。**hex 直書きを増やさない**。
- 配置は `components/features/capture/` (撮影ドメイン)。
  import するのは `@lucide/svelte` と `lib/` だけで、**pages を import しない / 他 domain を横参照しない**
  (`atomic-import-graph.test.ts` の契約どおり)。`lib/manual/format-duration` の参照は
  `Capture/Index.svelte` が `lib/manual/search` を参照している先例と同じ (lib は階層契約の対象外)。
- アイコンは `@lucide/svelte` の `Clock`。**SVG 直書きをしない**。

### テスト計画

- [ ] 新規 `tests/js/components/features/capture/ManualMetaSummary.test.ts` —
      **先に赤くしてから**実装する
  - 全件確定 → 「合計時間 3:20」で但し書きが**出ない**
  - 一部未確定 → 「合計時間 3:20（確定分・未確定 2 カット）」
  - 全件未確定 (`totalDurationMs = null`, `undeterminedCutCount = 5`) →
    「合計時間 —（未確定 5 カット）」
  - カット 0 件 (`null` / `0`) → 「合計時間 —」で但し書きが**出ない**
  - `categoryName = null` → 「未分類」/ `creatorName = null` → 「不明」
  - `updatedAt = null` → `formatDate` の fallback (`-`)
- [ ] `tests/js/pages/CaptureShow.test.ts` の fixture へ 5 キーを足し (既存テストが型で赤くなる)、
      **メタ情報ブロックが描かれること**と**全画面中は背後が `inert` になること** (既存契約) を確認
- [ ] `pnpm test` (jsdom) / `pnpm lint` / `pnpm typecheck` / `pnpm build`

### リスク

- **狭幅での折り返し**。撮影 PWA の主戦場は縦持ちスマホなので、
  1 行に詰め込まず 2 行 (合計時間 / カテゴリ・作成者・更新日) に割る。
  横並び 3 項目は既存の一覧カードと同じ書式なので、そこで既に成立している。
- 全画面撮影中にメタ情報が読めなくなるが、これは**意図した設計**である
  (全画面は撮影に集中する面で、既に見出し・ナレーション・テイク一覧も出していない)。

---

## 施策 6: 既存テストの追随と契約の固定

### 変更箇所

- `tests/Feature/Capture/CaptureManualBrowsingTest.php` (manual 直下キー集合 pin の新設 + 値の検証)
- 新規: `tests/Feature/Capture/CaptureManualDetailQueryCountTest.php`
- 新規: `tests/js/components/features/capture/ManualMetaSummary.test.ts`
- `tests/js/pages/CaptureShow.test.ts` (fixture 更新)

### 波及変更

- TypeScript 型定義: fixture が施策 4 の型に追随する
- API Resource/DTO: なし
- テストファイル: 本施策そのもの

### 変更後コード (クエリ数テストの骨子)

既存 `CaptureManualListQueryCountTest` と**同じ作法**で書く
(計測は GET 1 回ぶん・fixture 生成は `flushQueryLog` で計測外・暖機の GET を 1 回撃つ)。

```php
/*
 * 撮影詳細のクエリ数が**カット数・テイク数に比例しない**ことを固定する。
 *
 * メタ情報 (カテゴリ名 / 作成者名) は controller の loadMissing で 1 行あたり最大 2 クエリ、
 * 合計時間は既に取得済みのカット列と採用テイクから作るので追加クエリを持たない。
 * カットごとに adoptedTake を lazy load する形へ戻ると、ここで検出できる。
 */
test('撮影詳細のクエリ数はカット数に比例しない', function (): void {
    // カット 1 本の manual と カット 10 本の manual を同じ利用者で測り、件数が同じことを確認する
});
```

### PHPStan 適合チェック

- [x] テストコードも `declare(strict_types=1)` を持つ
- [x] ヘルパ関数に戻り値型を書く (既存テストと同じ形)

### テスト計画

- [ ] 上記 4 ファイルが `composer test` / `pnpm test` で green
- [ ] `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` /
      `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`

### リスク

- テストレーンのグローバルロック待ちが出るが**正常**である
  (30 秒ごとの heartbeat が出ている間はハングではない。kill しない / ロックファイルを消さない)。

---

## スコープ外 (再掲。設計として作らないもの)

- **ナレーション尺の推定 / TTS の導入**。再検討条件は
  `StillDisplayDuration` の docblock の「TTS を導入してナレーション音声の実尺が確定したとき」。
- **詳細画面での編集機能** (カテゴリ変更・タイトル変更・静止表示秒の変更)。要件に無い。
- **プレビューエリアの新設**。既に通し再生と撮影パネルがある。配置の再設計は別件。
- **一覧カードへの合計時間の追加**。行数ぶんの集計になりクエリ設計の話が別に立つ。
- **`video_manuals.total_length_ms` (完成動画の実尺) の併記**。別の量であり、
  2 つの尺を並べると撮影者がどちらを見ればよいか分からなくなる。
- **`manual.status` (制作状態) の表示**。要件のメタ情報に含まれない (T197 と同じ判断)。
- **`cuts.cut_length_ms` の利用**。あれは**レンダ結果**の記録 (published 後にだけ入り、
  複製ではリセットされる) であり、撮影中の素材の長さとは更新契機が違う。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 既存の撮影詳細 DTO・撮影詳細画面・レンダ上限ゲートという**稼働中の経路へ手を入れる**変更で、単独で切り出せる新機能ではない。施策 1〜6 は上から順に依存しており (式 → 集計 → DTO → 型 → UI → テスト)、1 本のブランチで順に積むのが自然である。 |
| 競合リスク | 並行して走る OCR 対応が `docs/TODO*.md` に触れる可能性がある (競合したら双方の行を残して解消する)。コードの競合面では、`RenderJobService` と `CaptureManualDetailData` に他タスクが同時に触っていないことを実装開始時に確認する。`resources/js/types/capture.ts` は撮影 PWA 全般が触るファイルなので、追加位置を `CaptureManualDetail` の中だけに閉じる。 |


---

## 関連する現行コード

### resources/js/pages/Capture/Show.svelte

```
<script lang="ts">
    import { onMount, tick, untrack } from "svelte";
    import { page, router } from "@inertiajs/svelte";
    import { ArrowLeft, BookOpen, ListVideo, Maximize, Minimize, Video } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageHeaderSection from "@/components/molecules/PageHeaderSection.svelte";
    import CameraRecorder from "@/components/features/capture/CameraRecorder.svelte";
    import type CameraRecorderType from "@/components/features/capture/CameraRecorder.svelte";
    import CaptureFileFallback from "@/components/features/capture/CaptureFileFallback.svelte";
    import CutNavigator from "@/components/features/capture/CutNavigator.svelte";
    import CutSwipeBar from "@/components/features/capture/CutSwipeBar.svelte";
    import ScenarioPreviewDialog from "@/components/features/capture/ScenarioPreviewDialog.svelte";
    import TakeStrip from "@/components/features/capture/TakeStrip.svelte";
    import UploadQueueBar from "@/components/features/capture/UploadQueueBar.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import { AdoptedTakeAutoDownloader } from "@/lib/capture/auto-download";
    import { supportsMediaRecorder, supportsStillCapture } from "@/lib/capture/camera";
    import type { CameraUnavailableReason } from "@/lib/capture/camera";
    import { buildCutLabels } from "@/lib/capture/cut-labels";
    import {
        decideCutNavigation,
        lockBackgroundScroll,
        matchesLandscapeCapture,
        subscribeLandscapeCapture,
        type NavigationDirection,
    } from "@/lib/capture/landscape-capture";
    import {
        isStackedLayout,
        navigateBackToList,
        navigateToPanelIfNeeded,
        prefersReducedMotion,
    } from "@/lib/capture/panel-navigation";
    import { createIdbPendingStore } from "@/lib/capture/idb";
    import { ThumbnailRefreshScheduler } from "@/lib/capture/thumbnail-refresh";
    import { generateClientTakeId, UploadQueue } from "@/lib/capture/upload-queue";
    import type { PendingStore, UploadOutcome } from "@/lib/capture/upload-queue";
    import type { SharedProps } from "@/lib/shared-props";
    import type { CaptureManualDetail } from "@/types/capture";

    /**
     * 撮影ナビ (doc/05 / 概念設計 D9)。cut を選び、録画 (または ファイル選択) →
     * 即時アップロード (upload-url → S3 PUT → POST takes)。失敗/オフラインは IndexedDB に
     * 一時保持し、フォアグラウンド復帰 / online / SW message で再送する。
     */
    interface Props {
        project: { id: number; name: string };
        manual: CaptureManualDetail;
        /** プレースホルダ表示秒数 (config manual.preview_placeholder_seconds)。単位は秒 */
        previewPlaceholderSeconds: number;
    }

    let { project, manual, previewPlaceholderSeconds }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    /**
     * 横持ち全画面の初期判定。**テンプレートの初回描画より前**に確定させるため、
     * script のこの位置 (props 受領直後) で 1 度だけ評価する。
     * これより後ろで宣言すると selectedCutId の初期化が宣言前参照 (TDZ) になる。
     */
    const initialLandscape = matchesLandscapeCapture();

    /* 初期描画で全画面になる場合は、**同じ script 評価の中で**先頭カットも選んでおく。
     * 選ばずに全画面へ入ると、最初の 1 描画だけ「カットを選び直してください。」が出る。
     * mount 時点の値で確定させるのが意図どおりなので state_referenced_locally を明示的に無視する
     * (以降の追従は横持ち購読の $effect が担う)。 */
    // svelte-ignore state_referenced_locally
    let selectedCutId = $state<number | null>(
        initialLandscape ? (manual.cuts[0]?.id ?? null) : null,
    );
    const selectedCut = $derived(manual.cuts.find((cut) => cut.id === selectedCutId) ?? null);
    /** 手順 N / 急所 N-M。CutNavigator の行ラベルと同じ導出元を共有する (二重管理を避ける) */
    const cutLabels = $derived(buildCutLabels(manual.cuts));
    /** cut の計画で撮影モードを決める (撮影者に判断させない = 使命) */
    const captureMode = $derived(selectedCut?.material_type === "still" ? "still" : "video");
    // 静的 feature-detect (従来) + 実行時失敗による上書き (F-03: doc/10 §10.8-3)。
    // 静止画は MediaRecorder を必要としないので判定を分ける
    const canCapture = $derived(
        typeof window !== "undefined" &&
            (captureMode === "still" ? supportsStillCapture() : supportsMediaRecorder()),
    );
    let cameraUnavailableReason = $state<CameraUnavailableReason | null>(null);
    const showRecorder = $derived(canCapture && cameraUnavailableReason === null);
    // 撮影 active (recording|stopping) と recorder 参照 (preview の資源競合制御。T050 / S4)
    let captureActive = $state(false);
    let recorderRef = $state<CameraRecorderType | null>(null);
    // 実行時フォールバックの説明文 (reason で出し分け。静的 feature-detect 由来は
    // CaptureFileFallback 既存の説明文だけで足りるため notice なし)
    const fallbackNotice = $derived.by(() => {
        if (cameraUnavailableReason === null) return null;
        if (cameraUnavailableReason === "permission_denied") {
            return "カメラを利用できないため、ファイル選択でのアップロードに切り替えました。カメラで撮影する場合はブラウザまたは端末・組織のカメラ設定を確認して再読み込みしてください。";
        }
        return "この端末ではカメラ録画を利用できないため、ファイル選択でのアップロードに切り替えました。";
    });

    /* ---- アップロードキュー ---- */
    const store: PendingStore = createIdbPendingStore();
    const queue = new UploadQueue({ store });

    /* ---- 採用済みテイクの自動 DL (T051) ----
     * project.id / manual.id はインスタンス生存中は安定 (別 manual へ遷移すると Inertia が
     * ページを remount する。reload({only:["manual"]}) は id を変えない)。mount 時点の値で
     * 確定させるのが意図どおりなので state_referenced_locally を明示的に無視する。 */
    // svelte-ignore state_referenced_locally
    const autoDownloader = new AdoptedTakeAutoDownloader(project.id, manual.id);
    let pendingCount = $state(0);
    let pendingBytes = $state(0);
    let uploading = $state(false);
    let quotaMessage = $state<string | null>(null);

    async function refreshPending(): Promise<void> {
        const items = await store.list();
        pendingCount = items.length;
        pendingBytes = items.reduce((sum, item) => sum + item.blob.size, 0);
        quotaMessage = queue.quotaMessage;
    }

    /* ---- manual 再取得は single-flight ----
     * アップロード成功 / キュー再開 / 自動 DL / サムネイル反映の 4 経路が同じ 1 本を通る。
     * 直列化しないと、古い応答での上書きと監視集合の判定ずれが起きる。 */
    // ★ in-flight の Promise を**保持して返す**。即解決する Promise を返すと、
    //   scheduler が「再取得が終わった」と誤認して古い manual のまま次の試行を消費する。
    let inFlight: Promise<void> | null = null;
    function reloadManual(): Promise<void> {
        if (inFlight !== null) return inFlight; // 並行呼び出しには同じ Promise を返す
        inFlight = new Promise<void>((resolve) => {
            router.reload({
                only: ["manual"],
                // onFinish は成功・失敗・キャンセルのいずれでも呼ばれる契約に依存している
                onFinish: () => {
                    inFlight = null;
                    resolve();
                },
            });
        });

        return inFlight;
    }

    /* ---- サムネイル生成の有界な反映 (T183) ----
     * この端末がこのセッションで登録したテイクだけを監視し、生成完了で画像へ差し替える。
     * 停止条件・有界性の単位は lib/capture/thumbnail-refresh.ts の docblock が正本。 */
    const thumbnails = new ThumbnailRefreshScheduler(reloadManual);

    // reload 後の最新 manual だけで監視集合を更新する
    $effect(() => {
        thumbnails.sync(manual);
    });

    /* ---- 撮影パネルへの視点/フォーカス移送 (F-1-03) ----
     * 1 カラム表示ではシナリオ一覧の下に撮影パネルが縦積みされるため、カットをタップしても
     * 撮影パネルが viewport に入らず、ユーザーが毎回手動スクロールしていた。
     * 判定と副作用は lib/capture/panel-navigation.ts が持つ (page は配線だけ)。 */
    let leftPaneEl = $state<HTMLElement | null>(null);
    let rightPaneEl = $state<HTMLElement | null>(null);
    let recordingHeadingEl = $state<HTMLElement | null>(null);
    let cutListHeadingEl = $state<HTMLElement | null>(null);
    /** 縦積みか (= 1 カラム)。「カット一覧へ戻る」の出し分けに使う */
    let stacked = $state(false);

    function updateStacked(): void {
        if (leftPaneEl === null || rightPaneEl === null) return;
        stacked = isStackedLayout(
            leftPaneEl.getBoundingClientRect(),
            rightPaneEl.getBoundingClientRect(),
        );
    }

    /* ---- 横持ち全画面 (doc/05 §5.2 / T186) ----
     * 判定・ジェスチャ解釈・移動判断・スクロール抑止は lib/capture/landscape-capture.ts が持ち、
     * ここは配線だけを行う (panel-navigation.ts と同じ役割分担)。 */
    /**
     * 横持ち全画面の条件 (向き + 高さ + 粗いポインタ) を満たすか。
     *
     * **初期値は script 評価時に確定させる** (initialLandscape)。`$effect` はテンプレートの
     * 初回描画の**後**に走るため、`$state(false)` から effect で入れる形にすると
     * 「最初の 1 描画だけ inline レイアウト」というちらつきが必ず残る。
     *
     * **この方式は「Inertia SSR が配線されていない」ことに依存する**。
     * SSR を入れるとサーバは inline、クライアントの初期評価は fullscreen になり得るため
     * hydration が食い違う (詳細設計の「再確認条件」)。
     */
    let landscapeMatches = $state(initialLandscape);
    /** 利用者が明示的に全画面を終了したか。**縦に戻すまで自動で入り直さない**ためのラッチ */
    let fullscreenDismissed = $state(false);
    /**
     * 実際に全画面を描くか。
     * **選択状態ではなく「撮るものがあるか」で決める** (`manual.cuts.length > 0`)。
     * `selectedCut !== null` を条件にすると、自動選択が反映される前の 1 フレームだけ
     * inline レイアウトが描かれてちらつく。また全画面中に reload で選択中カットが
     * 消えたときに「全画面なのに終了ボタンが無い」状態を作りかねない。
     */
    const fullscreenActive = $derived(
        landscapeMatches && !fullscreenDismissed && manual.cuts.length > 0,
    );
    /** 端の告知 (status) / 録画中の移動拒否 (alert)。文言の出所は landscape-capture.ts */
    let navigationNotice = $state<{ tone: "status" | "alert"; message: string } | null>(null);
    /** 全画面の現在位置表示 (1 起点)。cuts の並び順そのものを使う */
    const cutPosition = $derived({
        index: selectedCut === null ? 0 : manual.cuts.findIndex((c) => c.id === selectedCut.id) + 1,
        total: manual.cuts.length,
    });
    /** 全画面へ入った直後のフォーカス着地点 (背後に取り残さない)。tabindex="-1" */
    let fullscreenHeadingEl = $state<HTMLElement | null>(null);
    /** 直前に運んだ全画面状態。true への遷移でちょうど 1 回だけフォーカスを運ぶ */
    let lastFullscreenFocused = false;

    // 横持ち判定の購読。**初期値は script 評価時に確定済み**なので、この effect が担うのは
    // 「向きが変わったときの追従」だけである。追従に伴う後始末は同じ同期ブロックの中で済ませる
    // (2 本の effect に分けると、landscapeMatches が反映された描画と selectedCutId が
    //  入った描画の間に 1 フレーム挟まり、inline レイアウトが一瞬見えてしまう)。
    //  - 縦に戻ったらラッチを解除する (次に横へ倒せばまた自動で全画面に入る)
    //  - 横持ちでカット未選択なら先頭カットを自動選択する (何も撮れない全画面を作らない)
    // manual / selectedCutId は untrack で読む (選択やリロードで購読を張り直さない)。
    $effect(() =>
        subscribeLandscapeCapture((matches) => {
            landscapeMatches = matches;
            if (!matches) {
                fullscreenDismissed = false;

                return;
            }
            const first = untrack(() => manual.cuts)[0];
            if (first !== undefined && untrack(() => selectedCutId) === null) {
                selectedCutId = first.id;
            }
        }),
    );

    // 全画面へ入ったらフォーカスを全画面内へ運ぶ。
    // 背後 (ヘッダ / 左 pane) は inert にするが、AppLayout の chrome は覆わないため、
    // 開始位置を明示的に全画面内へ置くことでキーボード利用者が背後から始まらないようにする。
    $effect(() => {
        if (fullscreenActive === lastFullscreenFocused) return;
        lastFullscreenFocused = fullscreenActive;
        if (!fullscreenActive) return;
        fullscreenHeadingEl?.focus({ preventScroll: true });
    });

    // 全画面中だけ背後のスクロールを止める。**解除は戻り値の 1 か所に集約**する
    // (終了ボタン / 縦復帰 / ページ離脱のどれでも必ず外れる = スクロール不能の詰みを作らない)。
    $effect(() => {
        if (!fullscreenActive) return;

        return lockBackgroundScroll();
    });

    /**
     * 全画面でのカット移動 (スワイプ / 前後ボタン / 左右矢印キーの共通の受け口)。
     * 可否と文言の判断は decideCutNavigation が 1 か所で持つ (ここは配線だけ)。
     * **録画中は移動せずその場でエラーを出す** — 自動停止しない (誤スワイプで録画を確定させない)。
     */
    function handleCutNavigate(direction: NavigationDirection): void {
        const decision = decideCutNavigation({
            captureActive,
            cuts: manual.cuts,
            currentCutId: selectedCutId,
            direction,
        });
        if (decision.kind === "move") {
            navigationNotice = null;
            selectedCutId = decision.cutId;

            return;
        }
        if (decision.kind === "notice") {
            navigationNotice = { tone: decision.tone, message: decision.message };

            return;
        }
        navigationNotice = null; // ignore: 移動対象が無い (自動選択があるため通常は到達しない)
    }

    /**
     * 全画面を終了する。横持ちのまま既存レイアウトへ戻るので、
     * **現在位置を見失わせない**よう視点とフォーカスを撮影パネルへ運ぶ (既存機構を再利用)。
     */
    function exitFullscreen(): void {
        fullscreenDismissed = true;
        navigationNotice = null;
        void tick().then(() => {
            updateStacked();
            navigateToPanelIfNeeded({
                captureActive,
                leftEl: leftPaneEl,
                rightEl: rightPaneEl,
                headingEl: recordingHeadingEl,
                reducedMotion: prefersReducedMotion(),
            });
        });
    }

    /**
     * 全画面へ戻る手動の再入路。ラッチ (fullscreenDismissed) を解除する。
     * これが無いと「端末を一度縦に倒し直さないと全画面へ帰れない」行き止まりになる。
     * 未選択なら先頭カットを選ぶ (押しても何も起きない、を作らない)。
     */
    function enterFullscreen(): void {
        const first = manual.cuts[0];
        if (selectedCutId === null && first !== undefined) selectedCutId = first.id;
        navigationNotice = null;
        fullscreenDismissed = false;
    }

    function handleSelectCut(cutId: number): void {
        navigationNotice = null; // カットを選び直したら古い告知を捨てる
        selectedCutId = cutId;
        // DOM 反映後に測る (撮影パネルは選択で初めて描画される)
        void tick().then(() => {
            updateStacked();
            navigateToPanelIfNeeded({
                captureActive,
                leftEl: leftPaneEl,
                rightEl: rightPaneEl,
                headingEl: recordingHeadingEl,
                reducedMotion: prefersReducedMotion(),
            });
        });
    }

    /** 視点で運んだ以上、帰り道も用意する (行き先のない詰みを作らない) */
    function backToCutList(): void {
        navigateBackToList(cutListHeadingEl, prefersReducedMotion());
    }

    /* ---- 通し再生 (全体連結プレビュー / T191) ---- */
    let scenarioPreviewOpen = $state(false);
    let scenarioPreviewError = $state<string | null>(null);

    /** カメラ資源の解放・復帰は page 側に 1 つずつ持つ (TakeStrip と同じ関数を渡す = 2 か所に書かない) */
    function releaseCameraForPreview(): void {
        recorderRef?.releaseForPreview();
    }

    function resumeCameraAfterPreview(): void {
        void recorderRef?.resumeAfterPreview();
    }

    /**
     * 撮影中はカメラ資源と競合するため開かない。**ボタンは disabled にせず、押下時に伝える**
     * (禁止事項 8)。判定条件・呼び出し順・文言は**既存の個別 preview (TakeStrip.openPreview) と
     * 同じ言い回し**に揃える (同じ制約を別の言葉で言わない)。
     *
     * `captureActive` は recording|stopping を含む。`releaseForPreview()` は同期の void 関数で、
     * 録画中・取得中は自分で早期 return するため await も失敗ハンドリングも要らない。
     */
    function openScenarioPreview(): void {
        scenarioPreviewError = null;
        if (captureActive) {
            scenarioPreviewError = "撮影中は通し再生を開始できません。撮影を停止してからお試しください。";

            return;
        }
        releaseCameraForPreview();
        scenarioPreviewOpen = true;
    }

    function closeScenarioPreview(): void {
        resumeCameraAfterPreview();
    }

    $effect(() => {
        if (leftPaneEl === null || rightPaneEl === null) return;
        // observer の初回 callback はタイミング差があるため当てにせず、登録前に必ず 1 回測る
        updateStacked();
        if (typeof ResizeObserver === "undefined") return;
        const observer = new ResizeObserver(() => updateStacked());
        observer.observe(leftPaneEl);
        observer.observe(rightPaneEl);
        return () => observer.disconnect();
    });

    async function handleCaptured(blob: Blob, mimeType: string, durationMs: number | null): Promise<void> {
        if (selectedCutId === null) return;
        uploading = true;
        try {
            const outcome = await queue.enqueue({
                clientTakeId: generateClientTakeId(),
                projectId: project.id,
                manualId: manual.id,
                cutId: selectedCutId,
                blob,
                contentType: mimeType.split(";")[0],
                durationMs,
                capturedAt: new Date().toISOString(),
            });
            if (outcome.status === "uploaded") {
                thumbnails.watch(outcome.clientTakeId); // この端末が登録したテイクだけを監視する
                void reloadManual();
            }
        } finally {
            uploading = false;
            await refreshPending();
        }
    }

    // 入室時 / online 復帰時に採用済み未 DL テイクを自動取得する。changed のときのみ
    // reload を 1 回行う (複数採用テイクでも reload は 1 回)。多重発火は内部 running ガードが抑止。
    // reload 後は downloaded=true で対象が空になるため再 DL は起きない (冪等)。
    async function runAutoDownload(): Promise<void> {
        const { changed } = await autoDownloader.run(manual);
        if (changed) void reloadManual();
    }

    async function resumeUploads(): Promise<void> {
        uploading = true;
        try {
            const outcomes = await queue.resume();
            // ★ キュー経由は**複数件**が一度に確定しうる。uploaded を 1 件も watch しないと、
            //   最初の reload 時点で未生成だったテイクは以後まったく反映されない
            //   (= オフライン撮影の主経路が取り残される)。
            const uploaded = outcomes.filter(
                (outcome): outcome is Extract<UploadOutcome, { status: "uploaded" }> =>
                    outcome.status === "uploaded",
            );
            for (const outcome of uploaded) {
                thumbnails.watch(outcome.clientTakeId);
            }
            if (uploaded.length > 0) {
                void reloadManual(); // 件数によらず 1 回だけ (single-flight とも整合する)
            }
        } finally {
            uploading = false;
            await refreshPending();
        }
    }

    onMount(() => {
        void refreshPending();
        void runAutoDownload();

        // SW 登録 (Capture ページ mount 時に限定。素の JS・/build/* のみキャッシュ)
        if ("serviceWorker" in navigator) {
            void navigator.serviceWorker.register("/capture-sw.js");
            navigator.serviceWorker.addEventListener("message", handleSwMessage);
        }
        // フォアグラウンド復帰 / online でキュー再開 (Background Sync 非依存。概念設計 D9)
        document.addEventListener("visibilitychange", handleVisibility);
        window.addEventListener("online", handleOnline);

        return () => {
            document.removeEventListener("visibilitychange", handleVisibility);
            window.removeEventListener("online", handleOnline);
            if ("serviceWorker" in navigator) {
                navigator.serviceWorker.removeEventListener("message", handleSwMessage);
            }
            thumbnails.stop(); // unmount 後に再取得が走らないようにする
        };
    });

    function handleVisibility(): void {
        // 非表示の間は再取得を止める (停止条件の 1 つ)。復帰でキュー再開と一緒に再開する
        if (document.visibilityState !== "visible") {
            thumbnails.pause();
            return;
        }
        thumbnails.resume();
        void resumeUploads();
    }

    function handleOnline(): void {
        // resumeUploads と runAutoDownload は独立・順序非依存 (将来回帰防止のため明記)
        void resumeUploads();
        void runAutoDownload();
    }

    function handleSwMessage(event: MessageEvent): void {
        if (event.data === "resume-uploads") void resumeUploads();
    }
</script>

<AppLayout {appName}>
    <PageContainer>
        <!-- 全画面中は背後を inert にして、覆われた面へ Tab で入り込めないようにする -->
        <div inert={fullscreenActive}>
            <PageHeaderSection title={manual.title} icon={Video} testId="capture-manual-title">
                <TextLink href={`/app/projects/${project.id}/manuals`}>
                    <ArrowLeft class="inline size-3" aria-hidden="true" />
                    一覧へ戻る
                </TextLink>
                <!-- PC 側詳細への復路 (T155)。**この画面へ到達できた利用者に対しては、追加の
                     status / ability 条件で出し分けない**。根拠と保証範囲は
                     docs/architecture.md §撮影 PWA の運用契約。 -->
                <TextLink
                    href={`/projects/${project.id}/manuals/${manual.id}`}
                    testId="manual-detail-link"
                >
                    <BookOpen class="inline size-3" aria-hidden="true" />
                    マニュアル詳細へ
                </TextLink>
            </PageHeaderSection>
        </div>

        <!-- UploadQueueBar は全画面かどうかで **どちらか一方にだけ** 置く
             (両方に置くと data-testid が重複してテストの指し先が曖昧になる)。
             UploadQueueBar は props だけの表示 component なので、
             切替時に作り直されても失われる状態が無い。 -->
        {#if !fullscreenActive}
            <div class="mt-3">
                <UploadQueueBar {pendingCount} {pendingBytes} {uploading} {quotaMessage} onResume={resumeUploads} />
            </div>
        {/if}

    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2" data-testid="capture-grid">
        <section
            bind:this={leftPaneEl}
            inert={fullscreenActive}
            class="min-w-0 rounded-md border border-border bg-surface"
            data-testid="capture-left-pane"
        >
            <div class="flex items-center justify-between gap-2 border-b border-border px-3 py-2">
                <!-- 「カット一覧へ戻る」のフォーカス着地点。tabindex="-1" でプログラムからのみ
                     フォーカス可能にする (Tab 順には入れない)。 -->
                <h2
                    bind:this={cutListHeadingEl}
                    tabindex="-1"
                    class="text-caption text-text-secondary focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
                    data-testid="capture-cut-list-heading"
                >
                    シナリオ (タップして撮影)
                </h2>
                <!-- 狭幅で 2 つのボタンが詰まらないよう折り返しを許す (justify-end で右寄せを保つ) -->
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <!-- 通し再生 (doc/05 §5.2 [プレビュー])。**カットが 1 枚も無いときは出さない**
                         (文脈非該当の非表示であって、条件未充足の disabled ではない)。
                         撮影中の押下は開かずにエラーを出す (資源競合。TakeStrip の個別 preview と同じ規則)。 -->
                    {#if manual.cuts.length > 0}
                        <Button
                            variant="neutral"
                            size="sm"
                            onclick={openScenarioPreview}
                            testId="scenario-preview-button"
                        >
                            <ListVideo class="size-4" aria-hidden="true" />
                            通し再生
                        </Button>
                    {/if}
                    <!-- 横持ちなのに全画面でないとき (= 明示終了した後) の再入路。
                         文脈非該当時は非表示にする (disabled ではない)。 -->
                    {#if landscapeMatches && !fullscreenActive && manual.cuts.length > 0}
                        <Button
                            variant="neutral"
                            size="sm"
                            onclick={enterFullscreen}
                            testId="enter-fullscreen-capture"
                        >
                            <Maximize class="size-4" aria-hidden="true" />
                            全画面で撮影
                        </Button>
                    {/if}
                </div>
            </div>
            {#if scenarioPreviewError !== null}
                <p
                    class="px-3 py-2 text-caption text-danger"
                    role="alert"
                    data-testid="scenario-preview-error"
                >
                    {scenarioPreviewError}
                </p>
            {/if}
            <CutNavigator cuts={manual.cuts} {selectedCutId} onSelect={handleSelectCut} />
        </section>

        <!--
          全画面は **この section の class を差し替えるだけ**で作る。
          CameraRecorder を別の {#if} ブランチへ移すと unmount され、録画中の
          MediaStream / MediaRecorder が破棄されて録ったデータが消えるため。
          fixed + h-dvh: iOS Safari の動的ツールバーで下端が隠れないようにする
          (inset-0 だと bottom がツールバー下へ潜りうる)。
          z-40: AppLayout のモバイルヘッダ (sticky z-30) を覆い、
          Toast (z-50) は上に残す (アップロード失敗の告知を隠さない)。
        -->
        <section
            bind:this={rightPaneEl}
            class={fullscreenActive
                ? "fixed inset-x-0 top-0 z-40 flex h-dvh min-w-0 flex-col gap-2 bg-surface p-2"
                : "flex min-w-0 flex-col gap-4"}
            data-testid="capture-right-pane"
            data-fullscreen={fullscreenActive ? "true" : "false"}
        >
            {#if fullscreenActive}
                <!-- 全画面へ入った直後のフォーカス着地点。読み上げ順の先頭に置く -->
                <h2
                    bind:this={fullscreenHeadingEl}
                    tabindex="-1"
                    class="sr-only"
                    data-testid="capture-fullscreen-heading"
                >
                    全画面撮影
                </h2>
                <UploadQueueBar {pendingCount} {pendingBytes} {uploading} {quotaMessage} onResume={resumeUploads} />
                <!--
                  **終了ボタンは selectedCut の有無に依らずここに置く**。
                  出口の有無を選択状態という別の軸に結び付けない
                  (結び付けると「全画面なのに出口が無い」状態を作りうる)。
                -->
                <div class="flex items-center gap-2">
                    <div class="min-w-0 flex-1">
                        {#if selectedCut !== null}
                            <CutSwipeBar
                                label={cutLabels[selectedCut.id] ?? "選択中カット"}
                                scene={selectedCut.scene}
                                position={cutPosition}
                                onNavigate={handleCutNavigate}
                            />
                        {:else}
                            <p class="text-caption text-text-secondary">
                                カットを選び直してください。
                            </p>
                        {/if}
                    </div>
                    <Button
                        variant="neutral"
                        size="sm"
                        onclick={exitFullscreen}
                        testId="exit-fullscreen-capture"
                    >
                        <Minimize class="size-4" aria-hidden="true" />
                        全画面を終了
                    </Button>
                </div>
                {#if navigationNotice !== null}
                    {#if navigationNotice.tone === "alert"}
                        <p
                            class="text-caption text-danger"
                            role="alert"
                            data-testid="cut-navigation-error"
                        >
                            {navigationNotice.message}
                        </p>
                    {:else}
                        <p
                            class="text-caption text-text-secondary"
                            role="status"
                            data-testid="cut-navigation-notice"
                        >
                            {navigationNotice.message}
                        </p>
                    {/if}
                {/if}
            {/if}

            {#if selectedCut === null}
                <p class="text-caption text-text-secondary">
                    左のシナリオからカットを選ぶと撮影パネルが開きます。
                </p>
            {:else}
                <!-- 全画面では見出し・ナレーション・テイク一覧を出さない
                     (撮影ガイドと字幕は映像上の overlay が担う)。
                     **CameraRecorder はこの {#if} を跨がない** = 位置が変わらない。 -->
                {#if !fullscreenActive}
                    <div class="flex items-center justify-between gap-2">
                        <!-- カット選択時のフォーカス着地点。ラベルを含めて「どのカットの撮影か」を
                             名前で伝える (視点だけ運んでフォーカスを残すと a11y 欠落を作るため)。 -->
                        <h2
                            bind:this={recordingHeadingEl}
                            tabindex="-1"
                            class="text-caption text-text-secondary focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
                            data-testid="capture-recording-heading"
                        >
                            {cutLabels[selectedCut.id] ?? "選択中カット"} の撮影
                        </h2>
                        {#if stacked}
                            <!-- 1 カラムのときだけ出す (2 カラムでは一覧が常に見えているので不要)。
                                 TextLink のボタンモード (href なし + onclick) = <button type="button">。 -->
                            <TextLink onclick={backToCutList} testId="back-to-cut-list">
                                カット一覧へ戻る
                            </TextLink>
                        {/if}
                    </div>

                    <div class="rounded-md border border-border bg-surface p-3">
                        <p class="text-caption text-text-secondary">ナレーション</p>
                        <p class="mt-1 text-body">{selectedCut.narration}</p>
                        {#if selectedCut.shooting_point}
                            <p class="mt-2 text-caption text-text-secondary">
                                撮影ポイント: {selectedCut.shooting_point}
                            </p>
                        {/if}
                    </div>
                {/if}

                <!-- 全画面では残り高さいっぱいに広げる。**要素そのものは同じ** (class だけ変わる)。
                     inline 側を素の div にせず flex-col gap-4 にしてあるのは、この wrapper を
                     挟んだことで fallback 経路 (notice + ファイル選択) の間隔が消えるのを防ぐため
                     (従来は section 直下の兄弟として gap-4 が効いていた)。 -->
                <div class={fullscreenActive ? "relative min-h-0 flex-1" : "flex flex-col gap-4"}>
                    {#if showRecorder}
                        <CameraRecorder
                            bind:this={recorderRef}
                            onCaptured={(blob, mimeType, durationMs) =>
                                handleCaptured(blob, mimeType, durationMs)}
                            onCameraUnavailable={(reason) => (cameraUnavailableReason = reason)}
                            subtitlePrimary={selectedCut.subtitle_primary}
                            subtitleSecondary={selectedCut.subtitle_secondary}
                            onCaptureActiveChange={(active) => {
                                captureActive = active;
                                // 録画の開始でも停止でも古い告知を捨てる。とくに停止後に
                                // 「録画中は移動できません」が残らないようにする。
                                navigationNotice = null;
                            }}
                            layout={fullscreenActive ? "fullscreen" : "inline"}
                            shootingPoint={selectedCut.shooting_point}
                            mode={captureMode}
                        />
                    {:else}
                        {#if fallbackNotice !== null}
                            <p
                                class="text-caption text-text-secondary"
                                role="status"
                                data-testid="camera-fallback-notice"
                            >
                                {fallbackNotice}
                            </p>
                        {/if}
                        <CaptureFileFallback
                            material={captureMode}
                            onCaptured={(blob, contentType) =>
                                handleCaptured(blob, contentType, null)}
                        />
                    {/if}
                </div>

                {#if !fullscreenActive}
                    <TakeStrip
                        projectId={project.id}
                        manualId={manual.id}
                        cut={selectedCut}
                        cutLabel={cutLabels[selectedCut.id] ?? "選択中カット"}
                        onChanged={reloadManual}
                        {captureActive}
                        onRequestCameraRelease={releaseCameraForPreview}
                        onCameraResume={resumeCameraAfterPreview}
                    />
                {/if}
            {/if}
        </section>
        </div>

        <!-- Modal は Portal で描画されるため設置位置に依存しない (全画面 section の外に置く) -->
        <ScenarioPreviewDialog
            bind:open={scenarioPreviewOpen}
            projectId={project.id}
            manualId={manual.id}
            cuts={manual.cuts}
            labels={cutLabels}
            placeholderSeconds={previewPlaceholderSeconds}
            onClose={closeScenarioPreview}
        />
    </PageContainer>
</AppLayout>

```
### app/Http/Controllers/Capture/CaptureManualController.php

```
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Capture;

use App\DataTransferObjects\Capture\CaptureManualDetailData;
use App\DataTransferObjects\Capture\CaptureManualSummaryData;
use App\Enums\Manual\VideoManualStatus;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Project;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Capture\TakeObjectStorage;
use App\Services\Capture\UploadTicketCodec;
use App\Services\Manual\ManualKeywordSearch;
use App\Services\Project\DefaultProjectResolver;
use App\Support\Seo\SeoManager;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Webmozart\Assert\Assert;

/**
 * 撮影 PWA の画面シェル (doc/10 §10.3 / 概念設計 D1/D9)。GET は Inertia、書き込みは XHR JSON。
 * PC 管理 UI (/projects/...) とは URL 空間ごと分離 (/app/... = 撮影 PWA 専用)。
 */
class CaptureManualController extends Controller
{
    use ResolvesCurrentOrganization;

    /**
     * PWA エントリ (manifest start_url)。current org の先頭 project の一覧へ redirect
     * (v1 は単一 Default Project 前提。複数 project 化の際は選択画面へ差し替え)。
     */
    public function home(Request $request, DefaultProjectResolver $defaultProjects): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        $project = $defaultProjects->resolve($organization);
        abort_if($project === null, 404);

        return redirect()->route('capture.manuals.index', ['project' => $project]);
    }

    /** 撮影対象 (ready/published) の manual 一覧。category / q で絞り込み */
    public function index(Request $request, Project $project): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        $this->resolveOrganizationProject($organization, $project); // 認可より前に 404
        Gate::authorize('view', $project);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class); // view 認可済み = 認証済み。早期に int を確定
        $userId = $user->id;

        $categoryId = $request->filled('category') ? (int) $request->string('category')->value() : null;
        // 検索語の正規化 (trim + 先頭 200 文字) の正本は ManualKeywordSearch。
        // PC 一覧 (ManualListQuery 経由) と**同じ関数**を通す
        $rawSearch = $request->query('q');
        $search = ManualKeywordSearch::normalize(is_string($rawSearch) ? $rawSearch : null);
        $mine = $request->boolean('mine'); // "1"/"true" を bool 正規化

        // 代表サムネイルの可視性は **project 単位に 1 回**だけ決める (行ごとに評価しない)。
        // 一覧の閲覧は組織メンバーなら可 (view) だが、サムネイル endpoint は
        // ProjectPolicy::capture (project メンバー以上) を要求する。この差を props 側で吸収し、
        // 撮れない利用者には 403 になる <img> を 1 つも描かせない (秘匿境界は props 側)。
        // Gate::allows は例外を投げないため、撮れない利用者の一覧表示は現状どおり成功する。
        $canViewCover = Gate::allows('capture', $project);

        $manuals = $project->manuals()
            ->whereIn('status', [VideoManualStatus::Ready, VideoManualStatus::Published])
            ->when($categoryId !== null, fn (Builder $query) => $query->where('category_id', $categoryId))
            // title + カット本文 (scene / narration / subtitle_*) の部分一致。
            // 述語の正本は ManualKeywordSearch (PC 一覧と同じ関数を通る)。
            // **入れ子 group で括られる**ため、ready/published の母集団制限と
            // category / mine の絞り込みは OR に押し出されない
            ->when($search !== null, function (Builder $query) use ($search): void {
                Assert::string($search);
                ManualKeywordSearch::apply($query, $search);
            })
            // 自作フィルタ: 自ユーザー id のみ (payload 非受領 = tenant/actor キー不信)
            ->when($mine, fn (Builder $query) => $query->where('created_by', $userId))
            ->with(['category', 'creator'])
            // 代表サムネイル: 候補カットと**その採用テイクまで**入れ子で eager load する。
            // adoptedTake を載せ忘れると AdoptedReadyTakeCoverage::readyTakeId() が
            // 行ごとに lazy load して N+1 になる。見せない利用者には積まない。
            ->when($canViewCover, fn (Builder $query) => $query->with(['coverCut.adoptedTake']))
            ->withCount([
                'cuts',
                // 採用済み cut 数 (relation 経由 = 'adopted_take_id' リテラルを撮影経路に増やさない)
                'cuts as cuts_adopted_count' => fn (Builder $query) => $query->whereHas('adoptedTake'),
                'cuts as cuts_with_takes_count' => fn (Builder $query) => $query->whereHas('takes'),
            ])
            ->orderByDesc('updated_at')
            ->get()
            ->map(static fn (VideoManual $manual): array => CaptureManualSummaryData::fromManual($manual, $canViewCover)->toArray())
            ->all();

        return Inertia::render('Capture/Index', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'manuals' => array_values($manuals),
            'categories' => $project->categories()
                ->orderBy('sort_order')
                ->get()
                ->map(static fn (Category $category): array => ['id' => $category->id, 'name' => $category->name])
                ->all(),
            'filters' => ['category' => $categoryId, 'q' => $search, 'mine' => $mine],
        ]);
    }

    /** 撮影ナビ (cuts + 全 take メタ + 採用テイク署名 DL URL / ACK トークン) */
    public function show(
        Request $request,
        Project $project,
        VideoManual $manual,
        TakeObjectStorage $storage,
        UploadTicketCodec $codec,
        SeoManager $seo,
    ): Response {
        $organization = $this->resolveCurrentOrganization($request);
        $this->resolveOrganizationProject($organization, $project); // 認可より前に 404
        Gate::authorize('view', $manual); // 読み取りは撮影者含む org member

        // 撮影 PWA であることをタブ上で判別可能にする動的固有名
        $seo->setPrivateTitle($manual->title.' の撮影');

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        return Inertia::render('Capture/Show', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'manual' => CaptureManualDetailData::fromManual($manual, $user, $storage, $codec)->toArray(),
            // 通し再生でプレースホルダを表示する秒数。サーバ生成プレビューの黒背景尺と
            // **同じ設定値**を使う (2 つのプレビューの構造を揃える。単位は秒・正の整数)。
            'previewPlaceholderSeconds' => config()->integer('manual.preview_placeholder_seconds'),
        ]);
    }
}

```
### app/DataTransferObjects/Capture/CaptureManualDetailData.php

```
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Capture;

use App\Models\Cut;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Capture\TakeObjectStorage;
use App\Services\Capture\UploadTicketCodec;
use App\Services\Manual\AdoptedReadyTakeCoverage;
use Illuminate\Support\Collection;
use Webmozart\Assert\Assert;

/**
 * 撮影詳細 (Capture/Show) の manual + cuts + takes ツリー。
 * 採用テイクのみ署名 DL URL と DL 済み ACK トークンを付与する
 * (doc/10 §10.3 / 概念設計 D6。**本メソッドが唯一の設定経路**)。
 */
final readonly class CaptureManualDetailData
{
    /**
     * @param  list<CaptureCutData>  $cuts
     */
    public function __construct(
        public VideoManual $manual,
        public array $cuts,
    ) {}

    public static function fromManual(VideoManual $manual, User $user, TakeObjectStorage $storage, UploadTicketCodec $codec): self
    {
        // step 順 → 各 step 直後にその points (ScenarioDocumentData と同じ 1 パス整形)
        // adoptedTake は cut ごとに読むため eager load 必須 (無いと cuts 件数分の N+1 になる)
        /** @var \Illuminate\Database\Eloquent\Collection<int, Cut> $cuts */
        $cuts = $manual->cuts()->with('adoptedTake')->orderBy('sort_order')->get();
        /** @var Collection<int, Collection<int, Cut>> $grouped */
        $grouped = $cuts->toBase()->groupBy(static fn (Cut $cut): int => $cut->parent_cut_id ?? 0);
        /** @var Collection<int, Cut> $empty */
        $empty = new Collection;

        $ackExpiry = now()->addMinutes(config()->integer('capture.playback_url_ttl_minutes'))->getTimestamp();
        $cutData = [];
        foreach ($grouped->get(0) ?? $empty as $step) {
            $cutData[] = self::cutWithAdoptedUrls($step, $user, $storage, $codec, $ackExpiry);
            foreach ($grouped->get($step->id) ?? $empty as $point) {
                $cutData[] = self::cutWithAdoptedUrls($point, $user, $storage, $codec, $ackExpiry);
            }
        }

        return new self($manual, $cutData);
    }

    /**
     * 使用できる採用テイクがあれば署名 DL URL + ACK トークン (DL URL と同 TTL) を発行して cut を直列化。
     *
     * 発行条件は AdoptedReadyTakeCoverage が唯一の所在である。非 ready の採用テイクへ
     * 署名 URL / ACK を出さない = `capture.takes.playback` が非 ready を 404 にしている
     * (状態秘匿) のと同じゲートに揃える (RenderPipeline::clipSpecFor と同じ書き方)。
     */
    private static function cutWithAdoptedUrls(Cut $cut, User $user, TakeObjectStorage $storage, UploadTicketCodec $codec, int $ackExpiry): CaptureCutData
    {
        if (AdoptedReadyTakeCoverage::readyTakeId($cut) === null) {
            return CaptureCutData::fromCut($cut);
        }

        // 述語が非 null なら採用テイクは必ず存在する。PHPStan level 10 は静的には ?Take のままなので
        // Assert で絞る (述語の再実装ではない = TakeStatus を参照しない)。
        $adopted = $cut->adoptedTake;
        Assert::notNull($adopted, 'readyTakeId() が非 null なら採用テイクは必ず存在する');

        return CaptureCutData::fromCut(
            $cut,
            adoptedPlaybackUrl: $storage->temporaryPlaybackUrl($adopted->video_path),
            adoptedAckToken: $codec->sealAck(new DownloadAckClaims(
                takeId: $adopted->id,
                userId: $user->id,
                expiresAtTimestamp: $ackExpiry,
            )),
        );
    }

    /**
     * @return array{id: int, title: string, status: string, cuts: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->manual->id,
            'title' => $this->manual->title,
            'status' => $this->manual->status->value,
            'cuts' => array_map(
                static fn (CaptureCutData $cut): array => $cut->toArray(),
                $this->cuts,
            ),
        ];
    }
}

```
### app/DataTransferObjects/Capture/CaptureCutData.php

```
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Capture;

use App\Models\Cut;
use App\Models\Take;
use App\Services\Manual\AdoptedReadyTakeCoverage;

/**
 * 撮影 PWA へ返すカットの shape (takes 込み)。TS 側 types/capture.ts の CaptureCut と対で保守。
 * adopted_take_id の参照は読み取り直列化のみ (書き込み経路は CaptureTakeService に限定。
 * ScenarioWritePathInventoryTest 検出 4 が deny-by-default で固定する)。
 *
 * **「使用できる採用テイクか」の判定は自前で持たない** — DTO 側が唯一の述語
 * (`AdoptedReadyTakeCoverage::readyTakeId()`) を呼ぶ。呼び出し側が計算して渡す形にすると
 * fromCut() の呼び出し口 (詳細画面 / adopt 応答) ごとに渡し忘れうる形になり、
 * T148 が閉じた「呼び出し側が判定を組み立てる」構造へ戻るためである
 * (先例: TakeSelectionPageData → CutSequencer / ManualListItemData → ManualRowAbilities)。
 */
final readonly class CaptureCutData
{
    /**
     * @param  list<CaptureTakeData>  $takes
     * @param  int|null  $adoptedReadyTakeId  使用できる採用テイクの id
     *                                        (`AdoptedReadyTakeCoverage::readyTakeId()` の戻り値そのもの。判定は持たない)
     */
    public function __construct(
        public Cut $cut,
        public array $takes,
        public ?int $adoptedReadyTakeId,
    ) {}

    /**
     * takes は sort_order 順。採用テイクには playback URL / DL ACK トークンを付与できる
     * (詳細 GET のみ。null なら全テイク null = store/adopt 応答)。
     */
    public static function fromCut(Cut $cut, ?string $adoptedPlaybackUrl = null, ?string $adoptedAckToken = null): self
    {
        $adoptedTakeId = $cut->adopted_take_id;
        $takes = $cut->takes()->orderBy('sort_order')->orderBy('id')->get()
            ->map(static function (Take $take) use ($adoptedTakeId, $adoptedPlaybackUrl, $adoptedAckToken): CaptureTakeData {
                $isAdopted = $adoptedTakeId !== null && $take->id === $adoptedTakeId;

                return CaptureTakeData::fromTake(
                    $take,
                    playbackUrl: $isAdopted ? $adoptedPlaybackUrl : null,
                    downloadAckToken: $isAdopted ? $adoptedAckToken : null,
                );
            })
            ->all();

        // 「使用できる採用テイクか」の判定は AdoptedReadyTakeCoverage が唯一の所在である
        // (ここで adopted_take_id と TakeStatus::Ready を組み直さない = T148)。
        return new self($cut, array_values($takes), AdoptedReadyTakeCoverage::readyTakeId($cut));
    }

    /**
     * @return array{id: int, type: string, parent_cut_id: int|null, scene: string,
     *   shot_type: string, shooting_point: string|null, narration: string,
     *   subtitle_primary: string|null, subtitle_secondary: string, material_type: string|null,
     *   adopted_take_id: int|null, adopted_ready_take_id: int|null,
     *   takes: list<array{id: int, client_take_id: string, status: string, material_type: string, size_bytes: int,
     *     duration_ms: int|null, comment: string|null, captured_at: string|null, sort_order: int,
     *     downloaded: bool, has_thumbnail: bool, playback_url: string|null,
     *     download_ack_token: string|null}>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->cut->id,
            'type' => $this->cut->type->value,
            'parent_cut_id' => $this->cut->parent_cut_id,
            'scene' => $this->cut->scene,
            'shot_type' => $this->cut->shot_type->value,
            'shooting_point' => $this->cut->shooting_point,
            'narration' => $this->cut->narration,
            'subtitle_primary' => $this->cut->subtitle_primary,
            'subtitle_secondary' => $this->cut->subtitle_secondary,
            // カットの**計画** (未指定あり)。撮影 UI の出し分け (シャッター / 録画) に使う
            'material_type' => $this->cut->material_type?->value,
            'adopted_take_id' => $this->cut->adopted_take_id,
            // 通し再生が再生する対象。null = そのカットはプレースホルダになる
            // (「採用されていない」と「採用済みだが ready でない」を区別しない = 述語の意味そのまま)
            'adopted_ready_take_id' => $this->adoptedReadyTakeId,
            'takes' => array_map(
                static fn (CaptureTakeData $take): array => $take->toArray(),
                $this->takes,
            ),
        ];
    }
}

```
### app/DataTransferObjects/Capture/CaptureManualSummaryData.php

```
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Capture;

use App\Models\VideoManual;
use App\Services\Manual\AdoptedReadyTakeCoverage;
use Webmozart\Assert\Assert;

/**
 * 撮影一覧 (Capture/Index) の 1 行分。TS 側 types/capture.ts の CaptureManualSummary と対で保守。
 * 進捗カウント (cuts_total / cuts_adopted / cuts_with_takes) は withCount 済みモデルから読む。
 * creator は表示目的のみ (検索対象外)。User.name は CipherSweet PII のため whereBlind 検索の
 * 対象にはしない (自作フィルタは created_by の id 一致で行う)。
 *
 * **制作状態 (video_manuals.status) は載せない** (T197)。撮影 PWA が出す進捗バッジは
 * カットの採用状況から導出する別の量 (types/capture.ts の captureProgressOf) であり、
 * 制作状態は表示にも分岐にも使われていなかったため。撮影対象の母集団を
 * ready / published に絞るのは CaptureManualController の責務で、こちらは変えていない。
 */
final readonly class CaptureManualSummaryData
{
    public function __construct(
        public int $id,
        public string $title,
        public ?int $categoryId,
        public ?string $categoryName,
        public int $cutsTotal,
        public int $cutsAdopted,
        public int $cutsWithTakes,
        public ?string $updatedAt,
        public ?string $creatorName,
        /**
         * 代表サムネイル 1 枚の座標。無い場合は null で、UI はプレースホルダを描く。
         * **UI はこの 1 つの値だけで判断する** (権限も状態もここで解決済み = 判断を 2 箇所に持たない)。
         */
        public ?CaptureManualCoverData $cover,
    ) {}

    /**
     * withCount('cuts', 'cuts as cuts_adopted_count', 'cuts as cuts_with_takes_count') +
     * with('category', 'creator') 済みの manual から生成する (Capture/IndexController の一覧クエリと対)。
     *
     * @param  bool  $canViewCover  代表サムネイルを見せてよいか
     *                              (`ProjectPolicy::capture` を **project 単位に 1 回**評価した結果。行ごとに評価しない)。
     *                              false のときは `coverCut` relation に**触れない** — 触ると relation 未ロード時に
     *                              行ごとの lazy load が走り N+1 になる (権限の無い利用者には eager load を張らないため)。
     */
    public static function fromManual(VideoManual $manual, bool $canViewCover): self
    {
        $cutsTotal = $manual->getAttribute('cuts_count');
        $cutsAdopted = $manual->getAttribute('cuts_adopted_count');
        $cutsWithTakes = $manual->getAttribute('cuts_with_takes_count');
        Assert::integer($cutsTotal, 'withCount(cuts) 済みの manual を渡してください');
        Assert::integer($cutsAdopted, 'withCount(cuts as cuts_adopted_count) 済みの manual を渡してください');
        Assert::integer($cutsWithTakes, 'withCount(cuts as cuts_with_takes_count) 済みの manual を渡してください');

        return new self(
            id: $manual->id,
            title: $manual->title,
            categoryId: $manual->category?->id,
            categoryName: $manual->category?->name,
            cutsTotal: $cutsTotal,
            cutsAdopted: $cutsAdopted,
            cutsWithTakes: $cutsWithTakes,
            updatedAt: $manual->updated_at?->toIso8601String(),
            creatorName: $manual->creator?->name, // 退会/削除で null (実運用では FK RESTRICT)
            cover: self::resolveCover($manual, $canViewCover),
        );
    }

    /**
     * 代表サムネイルの座標を決める (概念設計 D1-1 の層 (c) = 合成のみ)。
     *
     * 層の分担:
     *   (a) 候補選択 … `VideoManual::coverCut()` (表示順 + サムネイル生成済み)
     *   (b) 状態判定 … `AdoptedReadyTakeCoverage::readyTakeId()` へ**委譲** (自前の述語を持たない)
     *   (c) 合成    … 本メソッド
     *
     * (b) は eager load 済み relation を読むだけで **DB へ問い合わせない**
     * (`with(['coverCut.adoptedTake'])` を張るのが呼び出し側の義務)。
     *
     * (a) が選んだカットで (b) が null を返したときは**次のカットを探さずに null を返す**。
     * 候補条件 (サムネイル生成済み) と表示条件 (採用済みかつ ready) は現行コードでは一致する
     * (`thumbnail_path` は `where status=ready` の条件付き UPDATE でしか非 null にならず、
     * ready から離れる遷移が存在しない) が、一致を前提にせず安全側 = 壊れた画像を出さない側へ倒す。
     */
    private static function resolveCover(VideoManual $manual, bool $canViewCover): ?CaptureManualCoverData
    {
        if (! $canViewCover) {
            return null; // relation に触れない (未ロードのため触ると lazy load = N+1)
        }

        $cut = $manual->coverCut;
        if ($cut === null) {
            return null; // 採用テイク付き + サムネイル生成済みのカットが 1 つも無い
        }

        $takeId = AdoptedReadyTakeCoverage::readyTakeId($cut);
        if ($takeId === null) {
            return null; // 候補条件と表示条件の食い違い → 出さない
        }

        return new CaptureManualCoverData(cutId: $cut->id, takeId: $takeId);
    }

    /**
     * @return array{id: int, title: string, category_id: int|null,
     *   category_name: string|null, cuts_total: int, cuts_adopted: int, cuts_with_takes: int,
     *   updated_at: string|null, creator_name: string|null,
     *   cover: array{cut_id: int, take_id: int}|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'category_id' => $this->categoryId,
            'category_name' => $this->categoryName,
            'cuts_total' => $this->cutsTotal,
            'cuts_adopted' => $this->cutsAdopted,
            'cuts_with_takes' => $this->cutsWithTakes,
            'updated_at' => $this->updatedAt,
            'creator_name' => $this->creatorName,
            'cover' => $this->cover?->toArray(),
        ];
    }
}

```
### app/Services/Manual/StillDisplayDuration.php

```
<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\Models\Cut;

/**
 * 静止画カットの表示秒を決める式の**唯一の所在**。
 *
 * 編集者が `cuts.static_display_seconds` を指定していればそれを使い、未指定なら
 * `config('manual.default_still_display_seconds')` を使う。
 *
 * 以前は `RenderPipeline` が `manual.preview_placeholder_seconds`
 * (= 採用テイク欠落 cut のプレースホルダ尺) を流用していた。これは別概念であり、
 * プレースホルダ尺を変えると完成動画の静止画尺まで黙って変わる状態だった。撤去済み。
 *
 * **クランプしない**。異常値を黙って別の値へ変えると設定ミスが隠れる。
 *
 * **doc/02 §2.2 の「ナレーション尺より短ければナレーション尺が優先」は v1 では実装しない。**
 * v1 は字幕のみで TTS を持たず、ナレーション文に再生時間という属性が存在しないためである
 * (doc/09 の v1 尺算出も `cut_length = material_ms` / 静止画は `static_display_seconds*1000`)。
 * 再検討の条件は「TTS を導入してナレーション音声の実尺が確定したとき」で、
 * そのときの変更点は本クラス 1 か所に閉じる。
 */
final class StillDisplayDuration
{
    public static function secondsFor(Cut $cut): int
    {
        return $cut->static_display_seconds
            ?? config()->integer('manual.default_still_display_seconds');
    }
}

```
### app/Services/Manual/EffectiveMaterialType.php

```
<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\Enums\Manual\MaterialType;
use App\Models\Cut;
use App\Models\Take;

/**
 * 「このカットを**実際に**どちらの素材として合成するか」を決める式の**唯一の所在**。
 *
 * 実体優先である: cut の計画が `still` でなくても、採用テイクの実体が画像なら `Still` を返す。
 * 理由は、**採用した後に編集者がシナリオ編集で cut.material_type を `video` へ戻せる**ためで、
 * 入口 (presign 422) でも採用 API でもこの状態は防げない。画像を動画クリップ経路
 * (`FfmpegVideoComposer::planTakeVideo()` = ffprobe で尺を測る) に流すと必ず壊れるので、
 * 「画像が動画クリップとして合成される道」を構造的に消す。
 *
 * **採用テイクは引数で受ける** (このクラスは `adoptedTake` relation を読まない)。
 * したがって `AdoptedTakeReferenceInventory` の登録は増えない。
 *
 * **ready 判定は一切しない** — 「採用済みかつ ready か」の述語は
 * `AdoptedReadyTakeCoverage` の専権である (AGENTS.md ドメイン固有規約 12)。本クラスは呼ばれる時点で
 * 採用テイクが確定していることを前提にする。
 */
final class EffectiveMaterialType
{
    public static function of(Cut $cut, Take $adoptedTake): MaterialType
    {
        return $cut->material_type === MaterialType::Still
            || $adoptedTake->material_type === MaterialType::Still
                ? MaterialType::Still
                : MaterialType::Video;
    }
}

```
### app/Services/Manual/AdoptedReadyTakeCoverage.php

```
<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\DataTransferObjects\Manual\Render\OrderedCut;
use App\DataTransferObjects\Manual\TakeCoverageData;
use App\Enums\Manual\TakeStatus;
use App\Models\Cut;
use App\Models\VideoManual;

/**
 * 「採用済みかつ ready のテイクを持つか」の**唯一の判定**。
 *
 * render (422 でブロック) と preview (ブロックせず告知) は**制裁が違うだけで基準は同じ**である。
 * 基準がファイルをまたいで複製されると再び乖離する (bug-hunt F-1-01 の構造的原因) ため、
 * 述語 isMissing() をここ 1 箇所に閉じ、`AdoptedReadyTakeCriterionInventoryTest` が
 * deny-by-default で「他ファイルが同じ判定を書き直していないこと」を機械検査する。
 *
 * 読み取り専用 (cuts / takes / status を 1 バイトも書かない)。
 */
final class AdoptedReadyTakeCoverage
{
    /**
     * 「使用できる採用テイク」の **id** (無ければ null)。**この式が唯一の実体**である。
     *
     * `isMissing()` は本メソッドの上に載る (bool しか返さない述語のままだと、id が要る側が
     * `adopted_take_id` と `TakeStatus::Ready` を組み直すことになり、T148 が閉じた二重化が
     * そのまま復活する)。撮影 PWA の通し再生はこの id を props 経由で受け取り、
     * TypeScript 側で述語を再実装しない。
     *
     * 前提 ($cut の adoptedTake の鮮度。3 段で読むこと):
     *   1. **一覧の直列化では eager load 必須** (`with('adoptedTake')`)。無いと N+1 になる
     *      (CutSequencer::orderedWithLabels / CaptureManualDetailData::fromManual が張っている)。
     *   2. **単一 Cut の直列化では lazy load を許容する** — relation 未ロードで、かつ最新の
     *      `adopted_take_id` を持つインスタンスなら結果は同じである (adopt 応答の経路)。
     *   3. **古い relation cache を持つインスタンスは不可**。ロード後に `adopted_take_id` を
     *      書き換えたインスタンスをそのまま渡さないこと (呼び出し側の責務)。
     */
    public static function readyTakeId(Cut $cut): ?int
    {
        $take = $cut->adoptedTake;
        if ($take === null || $take->status !== TakeStatus::Ready) {
            return null;
        }

        return $take->id;
    }

    /**
     * 唯一の述語。**この式を他所へ写経しない**。
     *
     * TakeStatus は uploading / processing / ready / failed の 4 値を持つため、
     * 本述語が真になるのは「まだ撮っていない」だけではない
     * (採用済みだがアップロード中・処理中・失敗も含む = 「使用できる採用テイクがない」)。
     *
     * 実体は readyTakeId() 側にある (述語の意味は同じ)。
     */
    public static function isMissing(Cut $cut): bool
    {
        return self::readyTakeId($cut) === null;
    }

    /**
     * 表示順カット列からの集計 (トリガー tx が既に持っている列を再利用する経路)。
     *
     * @param  list<OrderedCut>  $ordered
     */
    public static function fromOrdered(array $ordered): TakeCoverageData
    {
        $missing = [];
        foreach ($ordered as $entry) {
            if (self::isMissing($entry->cut)) {
                $missing[] = $entry->label;
            }
        }

        return new TakeCoverageData(totalCuts: count($ordered), missingLabels: $missing);
    }

    /** manual からの集計 (詳細画面 props の経路) */
    public static function for(VideoManual $manual): TakeCoverageData
    {
        return self::fromOrdered(CutSequencer::orderedWithLabels($manual));
    }
}

```
### app/Support/Security/AdoptedTakeReferenceInventory.php

```
<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Enums\Security\AdoptedTakeReferenceKind;

/**
 * `adoptedTake` relation を参照する app/ 配下ファイルの目録 (deny-by-default。T148)。
 *
 * 守る不変条件:
 *   「採用済みかつ ready のテイクを持つか」の判定式を書いてよいのは
 *   `Services/Manual/AdoptedReadyTakeCoverage.php` ただ 1 ファイルである。
 *
 * 強制は `tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php`
 * (exact-fit: 未登録の参照も、参照実体を失った stale entry も fail させる)。
 */
final class AdoptedTakeReferenceInventory
{
    /**
     * app/ 相対パス => [区分, 根拠 (30 文字以上)]。
     *
     * @return array<string, array{kind: AdoptedTakeReferenceKind, rationale: string}>
     */
    public static function entries(): array
    {
        return [
            'Services/Manual/AdoptedReadyTakeCoverage.php' => [
                'kind' => AdoptedTakeReferenceKind::Canonical,
                'rationale' => '判定式の実体。render の 422 と preview の事前告知・Placeholder 分岐が'
                    .'同じ述語 isMissing() を通るための唯一の場所 (bug-hunt F-1-01 の再発防止)。',
            ],
            'Services/Manual/CutSequencer.php' => [
                'kind' => AdoptedTakeReferenceKind::RelationWiring,
                'rationale' => '表示順カット列の取得で with(adoptedTake) の eager load を張るだけで、'
                    .'ready 判定も採用有無の判定も持たない (N+1 回避のための構造上の参照)。',
            ],
            'Services/Manual/RenderJobService.php' => [
                'kind' => AdoptedTakeReferenceKind::DelegatedToCoverage,
                'rationale' => '充足判定は AdoptedReadyTakeCoverage へ委譲済みで、残る参照は'
                    .'尺上限ソフトゲートが採用テイクの duration_ms を読む 1 箇所だけである。',
            ],
            'Services/Manual/RenderPipeline.php' => [
                'kind' => AdoptedTakeReferenceKind::DelegatedToCoverage,
                'rationale' => 'clipSpecFor が isMissing() を呼んで Placeholder 分岐を決め、'
                    .'非欠落側でのみ素材パス (video_path) 取得のため take 実体を読む。',
            ],
            'Models/Cut.php' => [
                'kind' => AdoptedTakeReferenceKind::RelationWiring,
                'rationale' => 'adoptedTake の belongsTo relation 宣言そのもの。'
                    .'判定式は一切持たず、参照の起点を提供するだけのモデル定義である。',
            ],
            'DataTransferObjects/Capture/CaptureManualDetailData.php' => [
                'kind' => AdoptedTakeReferenceKind::DelegatedToCoverage,
                'rationale' => '採用テイクの署名 URL / ACK を出すかどうかを'
                    .'AdoptedReadyTakeCoverage::readyTakeId() へ委譲し、自前の ready 判定は持たない。'
                    .'残る参照は非欠落側で素材パスと take id を読む 1 箇所と、N+1 を防ぐ eager load である。',
            ],
            'DataTransferObjects/Manual/CutTakeSummaryData.php' => [
                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
                'rationale' => 'シナリオ編集画面の動画列が、カットごとに採用テイクの id / status / '
                    .'サムネイル生成有無を表示条件として読むだけで、採用済み ready テイクの充足判定はしない。'
                    .'レンダの充足判定 (AdoptedReadyTakeCoverage) とは基準が違うため意図的に統合しない。',
            ],
            'DataTransferObjects/Manual/TakeSelectionPageData.php' => [
                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
                'rationale' => 'PC テイク選択画面が「今どれを採用しているか」を示すために'
                    .'採用テイクの id と status を読むだけで、ready 判定も充足判定もしない。'
                    .'レンダの充足判定 (AdoptedReadyTakeCoverage) とは意図的に統合しない。',
            ],
            'Http/Controllers/Projects/VideoManualController.php' => [
                'kind' => AdoptedTakeReferenceKind::RelationWiring,
                'rationale' => 'シナリオ編集画面の動画列を N+1 なしで取るため with(adoptedTake) の'
                    .'eager load を張るだけで、判定も読み取りも持たない。値の取り出しは'
                    .'CutTakeSummaryData 側にあり、そちらが別基準として登録済みである。',
            ],
            'Models/VideoManual.php' => [
                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
                'rationale' => 'coverCut() が一覧カードの代表サムネイル候補を絞る条件として'
                    .'whereHas(adoptedTake, thumbnail_path 非 null) を持つ。'
                    .'見るのはサムネイルの生成有無だけで ready 状態は見ない別基準であり、'
                    .'採用済み ready テイクの充足判定 (AdoptedReadyTakeCoverage) とは意図的に統合しない。',
            ],
            'Http/Controllers/Capture/CaptureManualController.php' => [
                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
                'rationale' => 'whereHas(adoptedTake) による採用済みカット数の集計。'
                    .'ready を見ない別基準であり、レンダの充足判定とは意図的に統合しない。'
                    .'代表サムネイルの eager load (coverCut.adoptedTake) も同ファイルに並ぶが、'
                    .'こちらは N+1 を防ぐ構造上の指定で判定を持たない。',
            ],
            'Services/Dashboard/DashboardService.php' => [
                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
                'rationale' => 'whereDoesntHave(adoptedTake) による撮影待ち件数の集計。'
                    .'ready を見ない別基準であり、レンダの充足判定とは意図的に統合しない。',
            ],
            'Console/Commands/Development/PipelineSmokeCommand.php' => [
                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
                'rationale' => 'bug-hunt のパイプライン通し確認で未採用カット件数を数えるだけの'
                    .'開発用コマンド。adoptedTake 参照側は ready を見ない (別の TakeStatus::Ready '
                    .'参照は登録直後のテイク自身の確認であって採用テイクの充足判定ではない)。',
            ],
        ];
    }
}

```
### resources/js/types/capture.ts

```
/**
 * 撮影 PWA の型定義。PHP 側 App\DataTransferObjects\Capture\* と対で保守する
 * (キー集合の契約は tests/Feature/Capture/CaptureManualBrowsingTest が固定する)。
 */

import type { BadgeTone } from "@/components/atoms/Badge.types";

export type TakeStatus = "uploading" | "processing" | "ready" | "failed";

/** PHP: App\Enums\Manual\MaterialType と値集合を一致させる */
export type MaterialType = "video" | "still";

export interface CaptureTake {
    id: number;
    client_take_id: string;
    status: TakeStatus;
    /** 登録された素材の**実体** (NOT NULL)。UI はこの値で <video> と <img> を出し分ける */
    material_type: MaterialType;
    size_bytes: number;
    duration_ms: number | null;
    comment: string | null;
    captured_at: string | null;
    sort_order: number;
    downloaded: boolean;
    /** サムネイルが生成済みか。true のときだけ GET .../takes/{id}/thumbnail を表示に使う */
    has_thumbnail: boolean;
    /** 採用テイクのみ非 null (doc/10 §10.3) */
    playback_url: string | null;
    /** 採用テイクのみ非 null。DL 完了時に POST .../downloaded へ送る署名 ACK トークン (D6) */
    download_ack_token: string | null;
}

export interface CaptureCut {
    id: number;
    type: "step" | "point";
    parent_cut_id: number | null;
    scene: string;
    shot_type: "hiki" | "yori";
    shooting_point: string | null;
    narration: string;
    subtitle_primary: string | null;
    subtitle_secondary: string;
    /** カットの**計画** (未指定あり)。撮影 UI (シャッター / 録画) の出し分けに使う */
    material_type: MaterialType | null;
    adopted_take_id: number | null;
    /**
     * 通し再生が再生するテイクの id (サーバが `AdoptedReadyTakeCoverage` で決めた値)。
     * null = そのカットはプレースホルダになる。**クライアントでこの判定を組み立て直さない**
     * (`adopted_take_id` と take.status から導出するコードを書かない = T148)。
     */
    adopted_ready_take_id: number | null;
    takes: CaptureTake[];
}

export interface CaptureManualDetail {
    id: number;
    title: string;
    status: string;
    cuts: CaptureCut[];
}

/** PHP: App\DataTransferObjects\Capture\CaptureManualCoverData と対 */
export interface CaptureManualCover {
    cut_id: number;
    take_id: number;
}

export interface CaptureManualSummary {
    id: number;
    title: string;
    category_id: number | null;
    category_name: string | null;
    cuts_total: number;
    cuts_adopted: number;
    cuts_with_takes: number;
    updated_at: string | null;
    /** 作成者名。退会/削除で解決不可のときは null (UI は「不明」) */
    creator_name: string | null;
    /**
     * 代表サムネイル 1 枚の座標 (無ければ null = プレースホルダ)。
     * URL ではなく id を持つ。組み立ては lib/capture/take-endpoints.ts#takeUrl() が唯一の規則。
     * **null 判定以外の条件を UI 側で足さない** — 権限も状態もサーバ側で解決済みである。
     */
    cover: CaptureManualCover | null;
}

/**
 * 撮影進捗 (この 1 本のマニュアルの撮影がどこまで進んだか)。
 * **PC 一覧の ManualProgress (制作の到達段階) とは別の量である** —
 * 導出元 (カットの採用状況 vs video_manuals.status)、更新契機、値の動きが独立している
 * (例: 制作は「作成中」でも撮影は「撮影完了」は正常な組合せ)。語が似ていても統合しないこと。
 */
export type CaptureProgress = "captured" | "capturing" | "not_captured";

export const CAPTURE_PROGRESS_LABELS = {
    captured: "撮影完了",
    capturing: "撮影中",
    not_captured: "未撮影",
} as const satisfies Record<CaptureProgress, string>;

export const CAPTURE_PROGRESS_TONES = {
    captured: "success",
    capturing: "tertiary",
    not_captured: "neutral",
} as const satisfies Record<CaptureProgress, BadgeTone>;

/**
 * 撮影進捗の導出 (現行の三項式と**同一の判定**を名前付きにしたもの。判定は 1 ビットも変えない)。
 *
 * 判定順序の帰結を正確に書く:
 * - `cuts_total === 0 && cuts_with_takes === 0` → 未撮影 (カットが無い = 撮影の分母が無い)
 * - **`cuts_total === 0 && cuts_with_takes > 0` → 撮影中**。take は cut に属するため
 *   この組合せは構造上生じないが、生じた場合は 2 つ目の条件に掛かって「撮影中」になる。
 *   本施策は**表示語彙の整理であり判定の変更ではない**ので、この帰結もそのまま残す
 *   (直したくなったら別タスクとして根拠付きで起こすこと)。
 */
export function captureProgressOf(
    summary: Pick<CaptureManualSummary, "cuts_total" | "cuts_adopted" | "cuts_with_takes">,
): CaptureProgress {
    if (summary.cuts_total > 0 && summary.cuts_adopted === summary.cuts_total) return "captured";
    if (summary.cuts_with_takes > 0) return "capturing";
    return "not_captured";
}

/** POST .../takes/upload-url の応答 (TakeUploadTicketResource と対) */
export interface UploadTicket {
    upload_url: string;
    headers: Record<string, string>;
    ticket: string;
    client_take_id: string;
    expires_at: string;
}

/** 422 quota 超過ボディ (QuotaExceededResource と対) */
export interface QuotaExceededBody {
    code: "quota_exceeded";
    message: string;
}

/** 409 登録競合ボディ (CaptureConflictResource と対) */
export interface CaptureConflictBody {
    code: "capture_conflict";
    conflict_type: "registration_in_flight" | "reservation_inconsistent";
    message: string;
}

```
### resources/js/lib/manual/format-duration.ts

```
/**
 * 再生時間の可読表記 (動画一覧の再生時間表示)。
 *
 * ms → "M:SS"、1 時間以上は "H:MM:SS"。秒は**四捨五入**する
 * (表示専用であり、長さの比較・判定には使わない = format-bytes.ts と同じ位置づけ。
 * 切り捨てにしないのは、59.6 秒を "0:59" と書くより "1:00" と書く方が実尺に近いためで、
 * 差は 1 秒未満であり配布判断に影響しない)。
 * サーバ整形にしないのは、日時と違いタイムゾーンに依存しないため。
 *
 * null / 有限でない値 / 負値は「未確定」を表す DURATION_UNKNOWN を返す
 * (未確定を 0:00 と書くと「長さゼロの動画がある」という別の嘘になる)。
 */
export const DURATION_UNKNOWN = "—";

export function formatDurationMs(durationMs: number | null): string {
    if (durationMs === null || !Number.isFinite(durationMs) || durationMs < 0) {
        return DURATION_UNKNOWN;
    }

    const totalSeconds = Math.round(durationMs / 1000);
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    const ss = String(seconds).padStart(2, "0");

    if (hours > 0) {
        return `${hours}:${String(minutes).padStart(2, "0")}:${ss}`;
    }

    return `${minutes}:${ss}`;
}

```
### app/Services/Manual/RenderJobService.php (L417-470 抜粋)

```
    /**
     * 採用テイク検証 (欠落 = 422。スキップしない: 標準化された成果物の完全性)。
     * adopted_take_id NULL または採用テイクが ready でないカットの表示ラベル一覧を message に含める。
     *
     * 判定式そのものは持たない (AdoptedReadyTakeCoverage へ委譲)。render の 422 と
     * preview の事前告知は**制裁が違うだけで基準は同じ**であり、式を写経すると再び乖離する
     * (bug-hunt F-1-01)。
     *
     * @param  list<OrderedCut>  $ordered
     */
    private function assertAllCutsHaveAdoptedReadyTakes(array $ordered): void
    {
        $coverage = AdoptedReadyTakeCoverage::fromOrdered($ordered);
        if ($coverage->missingCount() === 0) {
            return;
        }

        throw ValidationException::withMessages([
            'takes' => ['採用テイクが未設定のカットがあります: '.implode('、', $coverage->missingLabels)],
        ]);
    }

    /**
     * 尺上限ソフトゲート (§10.8-1: TTL 内 commit)。クライアント申告値ベースで、
     * ハード保証はジョブ timeout が担う。duration_ms NULL は保守的な既定尺で代用する。
     *
     * @param  list<OrderedCut>  $ordered
     */
    private function assertTotalSourceDurationWithinLimit(array $ordered): void
    {
        $defaultMs = config()->integer('manual.render_default_take_duration_ms');
        $totalMs = 0;
        foreach ($ordered as $entry) {
            $cut = $entry->cut;
            $take = $cut->adoptedTake;
            // ここへ来る時点で採用テイクは確定している (充足判定 = AdoptedReadyTakeCoverage が先に 422 を出す)
            Assert::notNull($take, '充足判定を通った cut には採用テイクが必ず存在する');

            // レンダ (RenderPipeline::clipSpecFor) と**同じ 2 クラス**を通す。
            // 片方だけ実効判定を持つと、cut=video/take=still の組み合わせで
            // ゲート 60 秒 / レンダ 5 秒という新しい二重管理が生まれる
            $totalMs += EffectiveMaterialType::of($cut, $take) === MaterialType::Still
                ? StillDisplayDuration::secondsFor($cut) * 1000
                : ($take->duration_ms ?? $defaultMs);
        }

        if ($totalMs > config()->integer('manual.render_max_total_source_ms')) {
            throw ValidationException::withMessages([
                'takes' => ['動画の合計尺が上限を超えています。マニュアルを分割してください。'],
            ]);
        }
    }

    /** org 配下の in-flight preview 数 (cross-org を作らないため relation 経由の whereHas のみ) */
```

