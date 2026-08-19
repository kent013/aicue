【使命 (North Star)】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(migrate:fresh 等)をエージェント判断で実行すること
4. response()->json() の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(app/Prompts/ の factory → 窓口 (PromptDefense) → 実行単位 (GuardedPrompt) の1本道のみ)
6. prompt 文字列のコード直書き(resources/prompts/*.yaml に置く)
7. 操作系 POST の応答での redirect()->intended()(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

【system】
あなたはコードレビュアーとして、Laravel + Svelte の改善実装 (TODO T232: 撮影 PWA シナリオ詳細画面のメタ情報表示) をレビューする。

レビュー観点:
- 設計との一致性 (detailed-design.md の施策 1〜6 に沿っているか)
- 正確性 (ロジックバグ・境界値・null 安全性)
- PHPStan level 10 適合性
- DTO / JsonResource パターン準拠
- テスト網羅性 (先に赤を確認した設計どおりのケースが揃っているか)
- セキュリティ (nested route 防御・cross-org 不可・PII 取り扱い)
- DESIGN.md 準拠: color / radius / typography は token 経由で参照し hex 直書き (#RRGGBB) を増やさない。token 値を変更する diff は resources/css/tokens.css と同一 diff 内で同期しているか
- Atomic Design 準拠: resources/js/components/ は atoms/molecules/organisms/templates の責務分離に従う。atom は単機能・状態を持たない、molecule は atom の組合せという階層を逆流していないか。アイコンは Lucide を使い、SVG 直書きを増やさない

出力形式: ファイルごとに判定 (Critical/Warning/Suggestion に分類)、最後に全体判定 (APPROVED / CHANGES_REQUESTED) を明記すること。

---

【user】
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
| 1 | カット単位の確定尺の式を 1 クラスへ (`DeterminedCutDuration` 新設 + レンダ上限ゲートの乗せ換え) | `app/Services/Manual/DeterminedCutDuration.php` (新規) / `app/Services/Manual/RenderJobService.php` / `tests/Unit/Manual/DeterminedCutDurationTest.php` (新規) / `tests/Architecture/DeterminedCutDurationSourceShapePinTest.php` (新規) / `tests/Feature/Manual/RenderTriggerTest.php` 系 | 高 |
| 2 | シナリオ全体の確定尺の集計 (`DeterminedScenarioDuration` 新設) | `app/Services/Manual/DeterminedScenarioDuration.php` (新規) / `tests/Unit/Manual/DeterminedScenarioDurationTest.php` (新規) | 高 |
| 3 | 撮影詳細 DTO へメタ情報 5 キーを追加 + カットの takes 取得を eager load 経由へ | `app/DataTransferObjects/Capture/CaptureManualDetailData.php` / `app/DataTransferObjects/Capture/CaptureCutData.php` / `app/Http/Controllers/Capture/CaptureManualController.php` / `app/Http/Controllers/Capture/CaptureTakeController.php` | 高 |
| 4 | TS 型の追随 | `resources/js/types/capture.ts` | 高 |
| 5 | メタ情報の表示 component 新設と詳細画面への配線 | `resources/js/components/features/capture/ManualMetaSummary.svelte` (新規) / `resources/js/pages/Capture/Show.svelte` | 高 |
| 6 | 既存テストの追随と契約の固定 | `tests/Feature/Capture/CaptureManualBrowsingTest.php` / `tests/Feature/Capture/CaptureManualDetailQueryCountTest.php` (新規) / `tests/Unit/DataTransferObjects/Capture/CaptureCutDataTest.php` (新規) / `tests/js/components/features/capture/ManualMetaSummary.test.ts` (新規) / `tests/js/pages/CaptureShow.test.ts` | 高 |

---

## 施策 1: カット単位の確定尺の式を 1 クラスへ

### 変更箇所

- 新規: `app/Services/Manual/DeterminedCutDuration.php`
- 変更: `app/Services/Manual/RenderJobService.php` (L439-468 `assertTotalSourceDurationWithinLimit`)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし (施策 3 が利用するだけ)
- テストファイル: `tests/Unit/Manual/DeterminedCutDurationTest.php` (新規) /
  レンダ上限ゲートの既存 Feature テスト (境界値の追加) /
  `tests/Architecture/DeterminedCutDurationSourceShapePinTest.php` (新規。後述)
- **`AdoptedTakeReferenceInventory`: 変更なし**。新クラスは `adoptedTake` relation を読まず
  引数で受けるため (`EffectiveMaterialType` と同じ作法)。`RenderJobService` は登録済みのまま。

### `RenderJobService` の source-shape pin (design-review Round 1/2 対応)

Unit/Feature テストは結果値だけを見るため、`RenderJobService` が将来また 3 分岐を
再実装しても (結果が同じなら) 検出できない。かといって FQN・alias・コメント/文字列除外まで
解決する専用スキャナを、既知の再実装リスクが具体的にある 1 メソッドのためだけに新設するのは
過大である (思考原則 2「今必要なものだけ作る」)。

> **design-review Round 2 [Warning] 対応**: Round 1 では本テストを「式の唯一の所在を守る
> 不変条件」と呼んでいたが、文字列部分一致の検出力ではその主張を支えられない
> (`use ... as` の alias で回避できる / `FakeDeterminedCutDuration::milliseconds(` を
> 正規の委譲と誤認する / `MyEffectiveMaterialType::of(` を誤検出する / コメント・文字列
> リテラル内の記述もコードとして数える / `duration_ms` と material type を直接比較する
> 別表現の写経は検出しない、の 5 点はいずれも実際に起こりうる)。
> **保証の名称と範囲をそのぶん狭める**: 本テストは「唯一の所在を守る不変条件テスト」ではなく、
> **`RenderJobService::assertTotalSourceDurationWithinLimit()` の現在のソース形が
> `DeterminedCutDuration` への委譲を含むことを固定する source-shape pin** である。
> 他クラスでの再実装・alias 経由の呼び出し・上記 5 点のいずれも検出しないことを明記する。
>
> **design-review Round 3 [Warning] 対応 (さらに縮小)**: Round 2 版は「委譲を含む」ことの
> 正の判定に加え、「旧来の 2 パターンを含まない」ことの**否定**判定も持っていたが、これは
> AGENTS.md 走査器共通規約 (e)「語彙一致の否定形は区切り文字で分割したトークンの完全一致で
> 判定する」に抵触する部分文字列一致だった (`str_contains()` の否定は alias・接頭辞付き別クラス・
> コメント内記述のいずれでも誤りうる)。走査器共通規約 (b)「見逃す方向へ倒すのは不可」に照らすと、
> 否定判定は**見逃しの実害の方が大きい**ため**削除**し、**正の委譲文字列 1 つが存在するかだけ**
> を固定する非常に限定的な pin へさらに縮小する。「委譲そのものが消えた」回帰だけを検出し、
> 「旧式のコードが並存して残っている」ケースは検出しない。

```php
// tests/Architecture/DeterminedCutDurationSourceShapePinTest.php

/*
 * RenderJobService::assertTotalSourceDurationWithinLimit() の**現在のソース形**が
 * DeterminedCutDuration::milliseconds() への委譲を含むことを固定する source-shape pin である。
 *
 * **これは「唯一の所在」を保証する不変条件テストではない** (design-review Round 2/3 対応で
 * 保証範囲を明示的に狭めた)。検出するのは「委譲の文字列が現在のソースに存在するか」
 * という正の判定 1 つだけである。検出しないもの: alias import 経由の呼び出し
 * (`use DeterminedCutDuration as X`) / 旧式の 3 分岐が委譲と**並存して**残っていること /
 * 別クラスでの同じ 3 分岐の再実装 / コメント・文字列リテラル内の記述との区別。
 * (Round 2 版は「旧来のパターンを含まない」ことの否定判定も持っていたが、
 * 部分文字列一致の否定は AGENTS.md 走査器共通規約 (e) に抵触するため Round 3 で削除した。
 * 「拾いすぎる (誤って赤くする) のは可、見逃す (誤って緑にする) のは不可」の原則に照らすと、
 * 否定判定は見逃しの実害の方が大きいため、正の判定だけに絞る方を選ぶ。)
 *
 * 正例テストと合成負例の自己テストは**同一ファイルに置く**
 * (design-review Round 3 [Warning] 対応。別テストファイル・別レーンへローカル関数を
 * 共有する前提を作らない)。
 */
function determinedCutDurationDelegationPresent(string $methodBody): bool
{
    return str_contains($methodBody, 'DeterminedCutDuration::milliseconds(');
}

test('RenderJobService の尺上限ゲートは DeterminedCutDuration への委譲を含む', function (): void {
    $body = sourceOf(RenderJobService::class, 'assertTotalSourceDurationWithinLimit');

    expect(determinedCutDurationDelegationPresent($body))->toBeTrue();
});

// 自己テスト: 委譲を含まない合成文字列を検出器へ直接与え、false を返すことを固定する
// (Pest の失敗する assertion を負例にそのまま流用する問題を避けるため、
// 検出処理を独立した純粋関数にしてある)
test('検出器は委譲を含まない文字列を偽と判定する (自己テスト)', function (): void {
    $legacyBody = <<<'PHP'
        $totalMs += EffectiveMaterialType::of($cut, $take) === MaterialType::Still
            ? StillDisplayDuration::secondsFor($cut) * 1000
            : ($take->duration_ms ?? $defaultMs);
        PHP;

    expect(determinedCutDurationDelegationPresent($legacyBody))->toBeFalse();
});
```

`sourceOf()` はメソッド定義行の範囲をファイルから切り出すだけの小さいヘルパで
(既存の `tests/Architecture/ControllerAuthorizationGateTest.php` が同種の
`ReflectionMethod::getStartLine()`/`getEndLine()` パターンを既に使っている先例に倣う)、
クラス名・名前解決は伴わない (走査器共通規約 (a) の対象外。(e) の語彙分割も対象外 —
残っているのは正の部分文字列一致 1 つだけで、語彙一致の否定形ではないため)。
(b)(c)(d) は満たす: メソッドが見つからなければ例外で落ちる (b) /
上記の自己テスト (合成した文字列) で検出力を裏取りする (c) /
`determinedCutDurationDelegationPresent()` の戻り値は必ず判定に使う (d)。

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

use Webmozart\Assert\Assert;

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
        // array_sum() は使わない (design-review Round 1 [Warning] 対応)。
        // 整数加算が PHP_INT_MAX を超えると array_sum() は float を返し得るため、
        // readonly コンストラクタの int 契約と静的に矛盾しうる。1 パスの明示ループで
        // 加算前に範囲を検査し、クランプせず例外にする (異常値を黙って変えない)。
        $total = 0;
        $undeterminedCount = 0;
        foreach ($perCutDurationsMs as $ms) {
            if ($ms === null) {
                $undeterminedCount++;

                continue;
            }

            Assert::greaterThanEq($ms, 0, 'カットの確定尺は負値になり得ない');
            Assert::lessThanEq($ms, PHP_INT_MAX - $total, 'カット尺の合計が PHP_INT_MAX を超える');
            $total += $ms;
        }

        $determinedCount = count($perCutDurationsMs) - $undeterminedCount;

        return new self(
            totalDurationMs: $determinedCount === 0 ? null : $total,
            undeterminedCutCount: $undeterminedCount,
        );
    }
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`self`)
- [x] null 安全 (`?int` を明示。ループ内で早期 `continue`)
- [x] DTO を返している (`final readonly class` の結果型。連想配列を返さない)
- [x] Generics の型パラメータが正しい (`@param list<int|null>` を PHPDoc で固定。
      **実引数の型宣言は `array`** — `list<int|null>` は PHP の型宣言には書けない)
- [x] 合計は明示ループの `int` 加算のみで作る (`array_sum()` を使わないため
      `int|float` への型の広がりが起きない。`Assert::lessThanEq` が
      `PHP_INT_MAX` を超える加算の前に例外にするため、`$total` は常に `int` の範囲に収まる。
      **`[PHP_INT_MAX]` 単体は許可され、それを超える次の加算の直前で例外になる**
      = design-review Round 2 [Suggestion] 対応で文言を訂正)

### テスト計画

- [ ] 新規 `tests/Unit/Manual/DeterminedScenarioDurationTest.php` — **先に赤くしてから**実装する
  - 空配列 → `totalDurationMs = null` / `undeterminedCutCount = 0`
  - 全件 null → `totalDurationMs = null` / `undeterminedCutCount = 件数`
  - 混在 (`[1000, null, 2500]`) → `totalDurationMs = 3500` / `undeterminedCutCount = 1`
  - 全件確定 (`[1000, 2000]`) → `totalDurationMs = 3000` / `undeterminedCutCount = 0`
  - 確定分が 0 ms だけ (`[0]`) → `totalDurationMs = 0` (**null にしない** =
    「確定していて 0 ms」と「確定していない」を区別する)
  - 負値混入 (`[-1]`) → 例外 (design-review Round 1 [Warning] 対応。カット尺は負値になり得ない)
  - 桁溢れ境界 (`[PHP_INT_MAX, 1]`) → 例外 (`array_sum()` を使わないことの検出力の裏取り。
    `PHP_INT_MAX` を超える加算の直前で例外にすることを固定する)

### リスク

- 「合計 0 ms」と「未確定」の取り違え。上の「確定分が 0 ms だけ (`[0]`)」のケースで固定する
  (design-review Round 2 [Suggestion] 対応。ケース追加で「最後のケース」が指す先が
  ずれていたため、対象を明記する)。
- **`array_sum()` の int/float 契約**。上の桁溢れ境界テストで固定する
  (design-review Round 1 [Warning] 対応。カット本数の実運用上限からは実質到達不能だが、
  型の静的契約として例外で塞ぐ)。

---

## 施策 3: 撮影詳細 DTO へメタ情報 5 キーを追加 + カットの takes 取得を eager load 経由へ

> **design-review Round 1 [Critical] 対応**: 現行 `CaptureCutData::fromCut()` は
> `$cut->takes()->orderBy(...)->get()` を**カットごとに**実行しており、`adoptedTake` の
> eager load だけではこの N+1 を解消できない (指摘は事実。撮影詳細画面は現状でも
> カット数に比例したクエリを発行している)。
>
> **design-review Round 2 [Warning] 対応 (方式の訂正)**: Round 1 では「`takes` を
> 呼び出し側が渡す `Collection<int, Take>` で受ける」形にしたが、これは 2 点で fail-open だった。
> (1) 呼び出し側が `$cut->takes` を eager load せずそのまま渡しても、Eloquent の magic property が
> 黙って lazy load して動いてしまうため、「eager load を強制する」という意図が API 上保証されない。
> (2) 任意の `Collection<int, Take>` を受け取れる = `$cut` に属さない Take の collection を
> 渡せる構造になっており、誤った呼び出しが別カット・別テナントの Take メタ情報を
> 直列化しうる (型では親子整合性を保証できない)。
> **`CaptureCutData::fromCut()` が `$cut->relationLoaded('takes')` を自分で確認し、
> 未ロードなら `$cut->takes` へ触れる前に例外にする**方式へ変更する
> (`Services/Manual/CurrentRenderArtifact::fromLoadedRenderCandidate()` と同じ
> 「未ロードでの呼び出しは例外にする」作法)。
>
> **design-review Round 3 [Warning] 対応**: Round 2 は「relation 経由なら親子整合性は
> 構造的に保証される」と書いたが、これは誤りだった。`$cut->setRelation('takes', $arbitrary)` は
> `relationLoaded()` を true にしたまま任意の Collection を仕込めるため、
> 「relation が常に `HasMany` クエリ (`WHERE cut_id = ?`) 経由で作られる」という前提は
> `setRelation()` を使う限り成立しない (表示順テストで投入順を検証するために
> 設計自身が `setRelation()` を使う想定であり、他人事ではない)。
> よって `relationLoaded()` の確認に加えて、**ロードされている全 Take について
> `take->cut_id === $cut->id` を明示的に検査**する (DB 再問い合わせ無しのメモリ上検査)。

### 変更箇所

- `app/DataTransferObjects/Capture/CaptureManualDetailData.php` (全体)
- `app/DataTransferObjects/Capture/CaptureCutData.php` (`fromCut()` の `takes` 取得契約。
  design-review Round 3 [Suggestion] 対応。**シグネチャ自体は変えない** — 変わるのは
  「`takes` relation を自分で読み、未ロード / 親子不整合を fail-closed で検査する」契約)
- `app/Http/Controllers/Capture/CaptureManualController.php` (L117-142 `show`)
- `app/Http/Controllers/Capture/CaptureTakeController.php` (`adopt()`。`fromCut()` の唯一の他の呼び出し元)

### 波及変更

- TypeScript 型定義: `resources/js/types/capture.ts` の `CaptureManualDetail` (施策 4)
- API Resource/DTO: 本施策そのもの (`CaptureCutData::fromCut()` の `takes` 取得契約の変更を含む。
  引数は増減しない)
- テストファイル: `tests/Feature/Capture/CaptureManualBrowsingTest.php` (キー集合契約の追加 +
  同一 org 内の project 不整合 404 テストの新設) /
  `tests/Feature/Capture/CaptureManualDetailQueryCountTest.php` (新規。施策 6) /
  `tests/Feature/Capture/CaptureTakeManagementTest.php` (`adopt()` 応答の回帰。契約変更の影響確認)
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

```php
// CaptureCutData (現行。takes を fromCut() 内で毎回クエリする)
    public static function fromCut(Cut $cut, ?string $adoptedPlaybackUrl = null, ?string $adoptedAckToken = null): self
    {
        $adoptedTakeId = $cut->adopted_take_id;
        $takes = $cut->takes()->orderBy('sort_order')->orderBy('id')->get()
            ->map(/* ... */)
            ->all();

        return new self($cut, array_values($takes), AdoptedReadyTakeCoverage::readyTakeId($cut));
    }
```

```php
// CaptureTakeController::adopt() (現行。fromCut() のもう 1 つの呼び出し元)
        $adoptedCut = $takes->adopt($project, $manual, $cut, $take);

        return CaptureCutResource::make(CaptureCutData::fromCut($adoptedCut));
```

### 変更後コード

**設計上の要点**:

1. カットの並べ替えループは**そのまま 1 パス**で、その中で
   「採用済みかつ ready のテイク」を解決し、**URL/ACK の発行条件と尺算出は
   同じ 1 回の解決結果 (`$adopted`) を共有する** (design-review Round 3 [Suggestion] 対応。
   「1 度だけ解決」だと `CaptureCutData::fromCut()` 内部で `adoptedReadyTakeId` を作るための
   もう 1 回の呼び出しと矛盾するため、「(a)(b) が同じ 1 回分の結果を共有する」と限定して書く)。
   **判定式の実装は 1 か所** (`AdoptedReadyTakeCoverage`) に保つ。
   そのために `cutWithAdoptedUrls` を「解決済みテイクを引数で受ける」形へ割り、
   解決 (`AdoptedReadyTakeCoverage::readyTakeId()` + `Assert`) を呼び出し元の 1 か所へ寄せる。
2. カテゴリ名 / 作成者名 / 更新日は**コンストラクタでスカラーとして受け取る**
   (`toArray()` の中で relation を触ると、eager load されていないときに lazy load が走る)。
3. `VideoManual $manual` は id / title / status のために保持する (既存の形を変えない)。
4. **`CaptureCutData::fromCut()` は `takes` relation を自分でクエリしないが、
   自分の relation として読む** (design-review Round 1/2/3 対応)。引数で `Collection` を
   受け取る形はやめ、`$cut->relationLoaded('takes')` を確認してから `$cut->takes` を読む。
   **さらに、ロードされている全 Take の `cut_id` が `$cut->id` と一致することも検査する**
   (`relationLoaded()` は `setRelation()` 経由の混入を防がないため。Round 3 対応)。
   並び順 (`sort_order` → `id`) は**メモリ上の `sortBy` で** `fromCut()` 自身が保証する
   (呼び出し側に並び順を意識させない)。呼び出し側の責務は「`takes` を eager load してから
   `fromCut()` を呼ぶ」ことだけになる (渡し方を間違えようがない)。

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
        // (既存) step 順 → 各 step 直後にその points。
        // adoptedTake に加えて **takes も eager load 必須**
        // (design-review Round 1 [Critical] 対応。無いと CaptureCutData::fromCut() 側で
        // カットごとに再クエリが必要になり、カット数に比例したクエリへ戻る)
        /** @var \Illuminate\Database\Eloquent\Collection<int, Cut> $cuts */
        $cuts = $manual->cuts()->with(['adoptedTake', 'takes'])->orderBy('sort_order')->get();
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
     * **採用済みかつ ready のテイクの解決式の実装は `AdoptedReadyTakeCoverage` 1 か所だけ**である
     * (署名 URL の発行条件と尺の算出条件を別々に組み立てると、片方だけ変わって乖離する)。
     * ここでは判定を 2 回呼ぶ (`appendCut` で 1 回 / `CaptureCutData::fromCut()` 内部で
     * `adoptedReadyTakeId` を作るのにもう 1 回) が、**実装が割れているわけではない**
     * (2 か所とも同じ 1 メソッドを呼ぶだけであり、式を書き直してはいない)。
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
        $readyTakeId = AdoptedReadyTakeCoverage::readyTakeId($cut);
        $adopted = $readyTakeId === null ? null : $cut->adoptedTake;
        // 述語が非 null なら採用テイクは必ず存在する。PHPStan level 10 は静的には ?Take のままなので
        // Assert で絞る (述語の再実装ではない = TakeStatus を参照しない)。
        if ($readyTakeId !== null) {
            Assert::notNull($adopted, 'readyTakeId() が非 null なら採用テイクは必ず存在する');
        }

        // CaptureCutData::fromCut() が takes の eager load 済みを自分で確認する
        // (design-review Round 2 対応。ここでは relation に触れず Cut を渡すだけでよい)
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

`CaptureCutData::fromCut()` は自分の relation として `takes` を読むが、
**未ロードなら例外にする**うえ、**ロード済みでも親子整合性を検査する**
(design-review Round 2/3 対応):

```php
final readonly class CaptureCutData
{
    /**
     * takes は sort_order → id 順に並べ替えて保持する。採用テイクには playback URL / DL ACK
     * トークンを付与できる (詳細 GET のみ。null なら全テイク null = store/adopt 応答)。
     *
     * **`takes` は必ず eager load 済みであること** (design-review Round 2 [Warning] 対応)。
     * 呼び出し側から `Collection` を受け取る形は、(a) 未ロードでも Eloquent の lazy load で
     * 黙って動いてしまい eager load 忘れを検出できない、(b) `$cut` に属さない Take の
     * `Collection` を渡せてしまい親子整合性を型で保証できない、の 2 点で fail-open だった。
     * ここでは `$cut->takes` relation を DTO 自身が読み、未ロードなら **`$cut->takes` へ
     * 触れる前に** `Assert` で落とす (`Services/Manual/CurrentRenderArtifact
     * ::fromLoadedRenderCandidate()` と同じ「未ロードでの呼び出しは例外にする」作法。
     * 一覧の N+1 防止と同じ考え方を単一カットの API 境界へ適用したもの)。
     * **`relationLoaded()` が保証するのは「relation cache が存在すること」だけであり、
     * それが完全な eager load 結果であることまでは判定できない** (design-review Round 4
     * [Suggestion] 対応。現在の呼び出し元は `with(['adoptedTake', 'takes'])` /
     * `load('takes')` で必ず全件取得しているためこの前提で成立するが、
     * 「一部だけロードされた relation」を渡す呼び出し元が将来増えたら本チェックの外になる)。
     *
     * **`relationLoaded()` だけでは親子整合性を保証しない** (design-review Round 3 [Warning] 対応)。
     * `$cut->setRelation('takes', $arbitraryCollection)` は `relationLoaded()` を true にしたまま
     * 任意の Collection を仕込めるため、「relation 経由なら `WHERE cut_id = ?` が
     * 親子整合性を構造的に保証する」という前提は `HasMany` クエリ経由の場合にしか成立しない。
     * よって **ロードされている全 Take について `take->cut_id === $cut->id` を明示的に検査**する
     * (DB への再問い合わせではなくメモリ上の値検査であり、N+1 は生まない)。
     * 別カット・別テナントの Take が `setRelation()` 経由で紛れ込んだ場合は、
     * ここで即座に例外にする。
     */
    public static function fromCut(Cut $cut, ?string $adoptedPlaybackUrl = null, ?string $adoptedAckToken = null): self
    {
        Assert::true(
            $cut->relationLoaded('takes'),
            'takes を eager load してから呼ぶこと (呼び出し側で with([\'adoptedTake\', \'takes\']) '
            .'または load(\'takes\') を行う)',
        );

        // relation を 1 度だけローカル変数へ受け、親子整合性検査と並べ替えの両方で使い回す
        // (design-review Round 4 [Suggestion] 対応。$cut->takes を 2 回読む必要をなくす)
        $takes = $cut->takes;
        foreach ($takes as $take) {
            Assert::same(
                $take->cut_id,
                $cut->id,
                'takes relation には対象 cut に属する Take だけを渡してください'
                .' (別カット・別テナントの Take が混入しています)',
            );
        }

        $adoptedTakeId = $cut->adopted_take_id;
        $sorted = $takes
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->values()
            ->map(static function (Take $take) use ($adoptedTakeId, $adoptedPlaybackUrl, $adoptedAckToken): CaptureTakeData {
                $isAdopted = $adoptedTakeId !== null && $take->id === $adoptedTakeId;

                return CaptureTakeData::fromTake(
                    $take,
                    playbackUrl: $isAdopted ? $adoptedPlaybackUrl : null,
                    downloadAckToken: $isAdopted ? $adoptedAckToken : null,
                );
            })
            ->all();

        return new self($cut, array_values($sorted), AdoptedReadyTakeCoverage::readyTakeId($cut));
    }
    // ... toArray() は変更なし
}
```

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

`CaptureTakeController::adopt()` (`fromCut()` のもう 1 つの呼び出し元。単一カットの応答なので
`takes` の eager load は 1 度きり = N+1 の懸念はない。呼び出し側で明示する):

```php
// CaptureTakeController::adopt()
        $adoptedCut = $takes->adopt($project, $manual, $cut, $take);
        $adoptedCut->load('takes'); // fromCut() の relationLoaded() 検査を満たすため必須

        return CaptureCutResource::make(CaptureCutData::fromCut($adoptedCut));
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`toArray` の array shape を 5 キーぶん更新)
- [x] null 安全 (`?->` と `Assert` で絞る。`$adopted` は `?Take`)
- [x] DTO を返している (配列返却は `toArray()` のみ = Inertia props 直前)
- [x] Generics の型パラメータが正しい (`Collection<int, Cut>` の既存注釈を維持)
- [x] 参照渡しの `@param-out` を書く (PHPStan level 10 は参照引数の型変化を追う)
- [x] `CaptureCutData::fromCut()` は引数を増やさない (`Cut` の `takes` relation を直接読むため、
      `Collection` を型として往来させる必要が無い)

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
- [ ] **「採用済みだが ready でないテイク」1 fixture で 4 点をまとめて確認する**
      (design-review Round 1 [Warning] 対応。URL/ACK 回帰と尺集計を別々に確認するだけでは
      同じ ready 判定に従っていることを固定できないため、1 本のテストへ統合する):
      `playback_url` / `download_ack_token` が null・`total_duration_ms` から除外・
      `undetermined_cut_count` が 1 増える、の 4 点。
- [ ] 既存テストの回帰: 署名 URL / ACK トークンの発行条件が変わっていないこと
      (`appendCut` への割り替えで壊しやすい最重要点)
- [ ] `tests/Feature/Capture/CaptureTakeManagementTest.php` の `adopt()` 応答テストが
      `CaptureCutData::fromCut()` の変更後も green であること
      (`takes` の並び順が変わっていないことを含む)
- [ ] **新規 Unit テスト**: `tests/Unit/DataTransferObjects/Capture/CaptureCutDataTest.php`
  - `takes` を eager load していない `Cut` を `CaptureCutData::fromCut()` へ渡すと例外になる
    (`relationLoaded('takes') === false` を作って確認する)
  - `takes` の表示順が `sort_order → id` で維持される
    (eager load した `Collection` の投入順をわざと逆順にしても、出力は `sort_order`/`id` 順になる)
  - **別 cut の Take が `setRelation()` で紛れ込んでいたら例外になる**
    (design-review Round 3 [Warning] 対応。別の Cut に属する Take を Factory で作り、
    `$cut->setRelation('takes', ...)` でロード済み relation へ直接仕込んで確認する。
    `relationLoaded()` だけでは `setRelation()` 経由の混入を防げないため、
    `take->cut_id === cut->id` の検査が実際に効くことをこのテストで固定する)
- [ ] **新規 Feature テスト (design-review Round 3 [Warning] 対応)**:
      `tests/Feature/Capture/CaptureManualBrowsingTest.php` へ
      「同一 org 内の別 project の manual を URL に差し込むと 404 (認可より前)」を追加する
      (既存の `'cross-org の project は index / show とも 404'` は**別 organization** の
      project + manual しか検証しておらず、「許可された project A の URL に
      project B (同一 org) の manual を差し込む」ケースはカバーしていなかった)
  ```php
  test('同一 org 内の別 project の manual を URL に差し込むと 404 (認可より前)', function (): void {
      [$organization, $owner, $projectA] = browsingContext();
      $projectB = Project::factory()->forOrganization($organization)->create();
      $manualOfB = VideoManual::factory()->forProject($projectB)->create(['status' => 'ready']);

      $this->actingAs($owner)
          ->get("/app/projects/{$projectA->id}/manuals/{$manualOfB->id}")
          ->assertNotFound();
  });
  ```
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **`cutWithAdoptedUrls` → `appendCut` の割り替えで署名 URL の発行条件が壊れる**。
  既存の「採用テイクのみ非 null」テストが回帰として効く。
- **`CaptureCutData::fromCut()` の呼び出し元 (`CaptureTakeController::adopt()`) で
  `->load('takes')` を忘れると、`relationLoaded()` の Assert がその場で例外にする**
  (design-review Round 2 対応。lazy load で黙って動くのではなく、実装時点で気付ける
  = fail-closed。テストは既存 `adopt()` Feature テストがそのまま検出する)。
  クエリ数の固定は詳細画面側 (施策 6) が担い、`adopt()` 単体には比例クエリテストを設けない
  (単一行の応答であり N+1 が原理的に起きないため)。
- `loadMissing` を忘れると lazy load で 2 クエリ増えるが N+1 にはならない (対象は 1 行)。
  それでもクエリ数テスト (施策 6) で「カット数・テイク数に比例しない」ことを固定する。
- **既存 route (新規 route ではない) の nested route 防御は inventory 登録済みだが、
  同一 org 内の project 不整合を実際に検証する既存テストは無かった**
  (design-review Round 3 [Warning] 対応。Round 2 は「既存回帰テストがある」と書いたが
  不正確だった。実際に確認した事実として訂正する):
  - inventory entry: `tests/Support/Routing/NestedRouteDefenseInventory.php` L59
    `'capture.manuals.show' => [...$project, 'manual' => $scoped]`
    (`project` は `NestedRouteDefenseMode::TenantGuardMiddleware`、
    `manual` は `NestedRouteDefenseMode::ScopeBindings`)。これは
    `NestedRouteIdorDefenseTest` の**分類漏れが無いことの検査**であり、
    実際の 404 挙動そのものは検証しない (同テストの docblock に明記されている)。
  - 404 になる経路 (構造): `routes/web.php` の `capture.manuals.show` は
    `Route::scopeBindings()->group()` の内側で宣言されており、`{project}/{manual}` の
    親子不整合は scoped binding 解決時に認可より前で 404 になる**はず**の構造だが、
    これを実行検査するテストが不足していた。
  - **既存の `'cross-org の project は index / show とも 404'` は別 organization の
    project + manual という組合せしか検証しておらず**、「許可された project A の URL に
    project B (同一 org) の manual を差し込む」不整合はカバー範囲外だった。
  - **対応**: 上のテスト計画に「同一 org 内の別 project の manual を URL に差し込むと
    404 (認可より前)」を新規追加する。これにより scoped binding が同一 org 内の
    project/manual 不整合も実際に 404 にすることを実行検証で固定する。
    route 定義自体には触れないため、inventory への新規登録は不要 (分類は変わらない)。

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
- [ ] PHP 側キー集合 pin (施策 3) の `CaptureManualBrowsingTest` の `array_keys` 比較と、
      TS 側の fixture 型付けは**それぞれ独立に固定するもの**である
      (design-review Round 2 [Suggestion] 対応。「1:1 同期を保証する」は言い過ぎで、
      PHP の shape と TS の型はそれぞれの言語でピン留めされ、**対応関係の維持自体は
      人が両方を見て保つ構造**である。自動で食い違いを検出する単一の仕組みではない)。
- [ ] `tests/js/pages/CaptureShow.test.ts` の fixture に `satisfies CaptureManualDetail` を付ける
      (design-review Round 1 [Suggestion] 対応。PHP 側 `array_keys` pin は PHP 出力の契約しか
      検証せず TS 型との 1:1 同期自体は保証しないため、fixture 側でも 5 キーの欠落を
      型エラーとして検出できるようにする)

### リスク

- **PHP shape と TS fixture はそれぞれ独立に固定されるだけで、自動で食い違いを検出する
  単一の仕組みではない** (design-review Round 3 [Suggestion] 対応。「キー集合 pin が
  PHP/TS の食い違いを検出する」は言い過ぎだった文言を、テスト計画の記述に揃えて訂正した)。
  対応関係の維持はレビューで確認する。
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

    /**
     * 未確定が 1 件でもあれば、値が部分和であることを値の隣で言う。
     *
     * **`totalDurationMs === null` (全件未確定) のときは「確定分・」を前置しない**
     * (design-review Round 1 [Warning] 対応。「確定分・未確定 5 カット」と書くと、
     * 確定分が実在するかのように読めてしまう — 合計は `—` で確定分自体が無いため)。
     */
    const durationNote = $derived(
        undeterminedCutCount === 0
            ? null
            : totalDurationMs === null
                ? `未確定 ${undeterminedCutCount} カット`
                : `確定分・未確定 ${undeterminedCutCount} カット`,
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
- 新規: `tests/Unit/DataTransferObjects/Capture/CaptureCutDataTest.php`
  (design-review Round 2 対応。`takes` 未ロードで例外 / 表示順が `sort_order → id` で維持される)
- 新規: `tests/js/components/features/capture/ManualMetaSummary.test.ts`
- `tests/js/pages/CaptureShow.test.ts` (fixture 更新)

### 波及変更

- TypeScript 型定義: fixture が施策 4 の型に追随する
- API Resource/DTO: なし
- テストファイル: 本施策そのもの

### 変更後コード (クエリ数テストの骨子)

既存 `CaptureManualListQueryCountTest` と**同じ作法**で書く
(計測は GET 1 回ぶん・fixture 生成は `flushQueryLog` で計測外・暖機の GET を 1 回撃つ)。

> **design-review Round 1 [Critical][Warning] 対応**: 施策 3 で `CaptureCutData::fromCut()` を
> takes の eager load 経由へ変えたことが本テストの前提になる (変更前の実装のままだと、
> このテストは必ず赤くなる)。加えて「カット数・テイク数に比例しない」という主張を
> **2 軸それぞれ**で固定する (カット数だけ変える 1 ケースでは、テイク数側の比例を検出できない)。

```php
/*
 * 撮影詳細のクエリ数が**カット数・テイク数のどちらにも比例しない**ことを固定する。
 *
 * メタ情報 (カテゴリ名 / 作成者名) は controller の loadMissing で 1 行あたり最大 2 クエリ、
 * 合計時間は既に取得済みのカット列と採用テイクから作るので追加クエリを持たない。
 * カットごとに adoptedTake / takes を lazy load する形へ戻ると、ここで検出できる。
 *
 * 2 軸を**独立に**検証する (どちらか一方だけを変えたケース):
 *   1. カット数を変える (1 本 / 10 本)。各カットのテイク数は揃える。
 *   2. カット数を揃え、1 カットあたりのテイク数を変える (1 本 / 5 本)。
 */
test('撮影詳細のクエリ数はカット数に比例しない', function (): void {
    // カット 1 本 (テイク 2 本ずつ) の manual と カット 10 本 (テイク 2 本ずつ) の manual を
    // 同じ利用者で測り、件数が同じことを確認する
});

test('撮影詳細のクエリ数はカット 1 本あたりのテイク数に比例しない', function (): void {
    // カット数を揃え (例: 3 本)、1 カットにつきテイク 1 本の manual と
    // 1 カットにつきテイク 5 本の manual を同じ利用者で測り、件数が同じことを確認する
});
```

**「採用済みだが ready でないテイク」の 4 点統合テスト** (design-review Round 1 [Warning] 対応。
施策 3 のテスト計画と実質同じテストなので、実装は 1 本にまとめる):

```php
/*
 * 採用済みだが ready でない (processing/failed) テイクが、URL 発行条件と尺集計の
 * 両方で「使用できない」側として一貫して扱われることを 1 fixture で固定する。
 * 別々のテストで確認すると、片方だけ同じ ready 判定に従っていない回帰を見逃す。
 */
test('採用済みだが ready でないテイクは URL も尺も未確定として扱う', function (): void {
    // playback_url === null / download_ack_token === null /
    // total_duration_ms がそのカット抜きの値 / undetermined_cut_count が 1 増える
    // の 4 点を同じレスポンスで確認する
});
```

### PHPStan 適合チェック

- [x] テストコードも `declare(strict_types=1)` を持つ
- [x] ヘルパ関数に戻り値型を書く (既存テストと同じ形)

### テスト計画

- [ ] 上記ファイルが `composer test` / `pnpm test` で green
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

## 実装差分（git diff）
diff --git a/app/DataTransferObjects/Capture/CaptureCutData.php b/app/DataTransferObjects/Capture/CaptureCutData.php
index 34539509..dc608c78 100644
--- a/app/DataTransferObjects/Capture/CaptureCutData.php
+++ b/app/DataTransferObjects/Capture/CaptureCutData.php
@@ -7,6 +7,7 @@
 use App\Models\Cut;
 use App\Models\Take;
 use App\Services\Manual\AdoptedReadyTakeCoverage;
+use Webmozart\Assert\Assert;
 
 /**
  * 撮影 PWA へ返すカットの shape (takes 込み)。TS 側 types/capture.ts の CaptureCut と対で保守。
@@ -33,13 +34,54 @@ public function __construct(
     ) {}
 
     /**
-     * takes は sort_order 順。採用テイクには playback URL / DL ACK トークンを付与できる
-     * (詳細 GET のみ。null なら全テイク null = store/adopt 応答)。
+     * takes は sort_order → id 順に並べ替えて保持する。採用テイクには playback URL / DL ACK
+     * トークンを付与できる (詳細 GET のみ。null なら全テイク null = store/adopt 応答)。
+     *
+     * **`takes` は必ず eager load 済みであること**。呼び出し側から `Collection` を受け取る形は、
+     * (a) 未ロードでも Eloquent の lazy load で黙って動いてしまい eager load 忘れを検出できない、
+     * (b) `$cut` に属さない Take の `Collection` を渡せてしまい親子整合性を型で保証できない、
+     * の 2 点で fail-open だった。ここでは `$cut->takes` relation を DTO 自身が読み、
+     * 未ロードなら **`$cut->takes` へ触れる前に** 例外にする
+     * (`Services/Manual/CurrentRenderArtifact::fromLoadedRenderCandidate()` と同じ
+     * 「未ロードでの呼び出しは例外にする」作法)。
+     * **`relationLoaded()` が保証するのは「relation cache が存在すること」だけであり、
+     * それが完全な eager load 結果であることまでは判定できない**。現在の呼び出し元は
+     * `with(['adoptedTake', 'takes'])` / `load('takes')` で必ず全件取得しているためこの前提で
+     * 成立するが、「一部だけロードされた relation」を渡す呼び出し元が将来増えたら本チェックの外になる。
+     *
+     * **`relationLoaded()` だけでは親子整合性を保証しない**。
+     * `$cut->setRelation('takes', $arbitraryCollection)` は `relationLoaded()` を true にしたまま
+     * 任意の Collection を仕込めるため、「relation 経由なら `WHERE cut_id = ?` が
+     * 親子整合性を構造的に保証する」という前提は `HasMany` クエリ経由の場合にしか成立しない。
+     * よって **ロードされている全 Take について `take->cut_id === $cut->id` を明示的に検査**する
+     * (DB への再問い合わせではなくメモリ上の値検査であり、N+1 は生まない)。
+     * 別カット・別テナントの Take が `setRelation()` 経由で紛れ込んだ場合は、
+     * ここで即座に例外にする。
      */
     public static function fromCut(Cut $cut, ?string $adoptedPlaybackUrl = null, ?string $adoptedAckToken = null): self
     {
+        Assert::true(
+            $cut->relationLoaded('takes'),
+            'takes を eager load してから呼ぶこと (呼び出し側で with([\'adoptedTake\', \'takes\']) '
+            .'または load(\'takes\') を行う)',
+        );
+
+        // relation を 1 度だけローカル変数へ受け、親子整合性検査と並べ替えの両方で使い回す
+        // ($cut->takes を 2 回読む必要をなくす)
+        $takes = $cut->takes;
+        foreach ($takes as $take) {
+            Assert::same(
+                $take->cut_id,
+                $cut->id,
+                'takes relation には対象 cut に属する Take だけを渡してください'
+                .' (別カット・別テナントの Take が混入しています)',
+            );
+        }
+
         $adoptedTakeId = $cut->adopted_take_id;
-        $takes = $cut->takes()->orderBy('sort_order')->orderBy('id')->get()
+        $sorted = $takes
+            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
+            ->values()
             ->map(static function (Take $take) use ($adoptedTakeId, $adoptedPlaybackUrl, $adoptedAckToken): CaptureTakeData {
                 $isAdopted = $adoptedTakeId !== null && $take->id === $adoptedTakeId;
 
@@ -53,7 +95,7 @@ public static function fromCut(Cut $cut, ?string $adoptedPlaybackUrl = null, ?st
 
         // 「使用できる採用テイクか」の判定は AdoptedReadyTakeCoverage が唯一の所在である
         // (ここで adopted_take_id と TakeStatus::Ready を組み直さない = T148)。
-        return new self($cut, array_values($takes), AdoptedReadyTakeCoverage::readyTakeId($cut));
+        return new self($cut, array_values($sorted), AdoptedReadyTakeCoverage::readyTakeId($cut));
     }
 
     /**
diff --git a/app/DataTransferObjects/Capture/CaptureManualDetailData.php b/app/DataTransferObjects/Capture/CaptureManualDetailData.php
index 4a79c4ae..9b8cc089 100644
--- a/app/DataTransferObjects/Capture/CaptureManualDetailData.php
+++ b/app/DataTransferObjects/Capture/CaptureManualDetailData.php
@@ -10,6 +10,8 @@
 use App\Services\Capture\TakeObjectStorage;
 use App\Services\Capture\UploadTicketCodec;
 use App\Services\Manual\AdoptedReadyTakeCoverage;
+use App\Services\Manual\DeterminedCutDuration;
+use App\Services\Manual\DeterminedScenarioDuration;
 use Illuminate\Support\Collection;
 use Webmozart\Assert\Assert;
 
@@ -17,23 +19,34 @@
  * 撮影詳細 (Capture/Show) の manual + cuts + takes ツリー。
  * 採用テイクのみ署名 DL URL と DL 済み ACK トークンを付与する
  * (doc/10 §10.3 / 概念設計 D6。**本メソッドが唯一の設定経路**)。
+ *
+ * メタ情報 (カテゴリ名 / 作成者名 / 更新日時 / 合計時間) は doc/05 §5.2 の要件。
+ * 合計時間は**いま尺が確定している分**の合計であって完成動画の見込み尺ではない
+ * (`DeterminedScenarioDuration` が唯一の所在)。
  */
 final readonly class CaptureManualDetailData
 {
     /**
      * @param  list<CaptureCutData>  $cuts
+     * @param  string|null  $updatedAt  ISO 8601 文字列 (Carbon をそのまま props へ渡さない)
      */
     public function __construct(
         public VideoManual $manual,
         public array $cuts,
+        public ?string $categoryName,
+        public ?string $creatorName,
+        public ?string $updatedAt,
+        public DeterminedScenarioDuration $duration,
     ) {}
 
     public static function fromManual(VideoManual $manual, User $user, TakeObjectStorage $storage, UploadTicketCodec $codec): self
     {
-        // step 順 → 各 step 直後にその points (ScenarioDocumentData と同じ 1 パス整形)
-        // adoptedTake は cut ごとに読むため eager load 必須 (無いと cuts 件数分の N+1 になる)
+        // step 順 → 各 step 直後にその points (ScenarioDocumentData と同じ 1 パス整形)。
+        // adoptedTake に加えて **takes も eager load 必須**
+        // (無いと CaptureCutData::fromCut() 側でカットごとに再クエリが必要になり、
+        // カット数に比例したクエリへ戻る)
         /** @var \Illuminate\Database\Eloquent\Collection<int, Cut> $cuts */
-        $cuts = $manual->cuts()->with('adoptedTake')->orderBy('sort_order')->get();
+        $cuts = $manual->cuts()->with(['adoptedTake', 'takes'])->orderBy('sort_order')->get();
         /** @var Collection<int, Collection<int, Cut>> $grouped */
         $grouped = $cuts->toBase()->groupBy(static fn (Cut $cut): int => $cut->parent_cut_id ?? 0);
         /** @var Collection<int, Cut> $empty */
@@ -41,47 +54,81 @@ public static function fromManual(VideoManual $manual, User $user, TakeObjectSto
 
         $ackExpiry = now()->addMinutes(config()->integer('capture.playback_url_ttl_minutes'))->getTimestamp();
         $cutData = [];
+        /** @var list<int|null> $durationsMs 表示順に並べたカットごとの確定尺 */
+        $durationsMs = [];
         foreach ($grouped->get(0) ?? $empty as $step) {
-            $cutData[] = self::cutWithAdoptedUrls($step, $user, $storage, $codec, $ackExpiry);
+            self::appendCut($step, $user, $storage, $codec, $ackExpiry, $cutData, $durationsMs);
             foreach ($grouped->get($step->id) ?? $empty as $point) {
-                $cutData[] = self::cutWithAdoptedUrls($point, $user, $storage, $codec, $ackExpiry);
+                self::appendCut($point, $user, $storage, $codec, $ackExpiry, $cutData, $durationsMs);
             }
         }
 
-        return new self($manual, $cutData);
+        return new self(
+            manual: $manual,
+            cuts: $cutData,
+            // 表示目的のみ。User.name は CipherSweet PII のため検索には使わない
+            // (一覧 CaptureManualSummaryData と同じ形。退会/削除で解決不可なら null)
+            categoryName: $manual->category?->name,
+            creatorName: $manual->creator?->name,
+            updatedAt: $manual->updated_at?->toIso8601String(),
+            duration: DeterminedScenarioDuration::fromCutDurations($durationsMs),
+        );
     }
 
     /**
-     * 使用できる採用テイクがあれば署名 DL URL + ACK トークン (DL URL と同 TTL) を発行して cut を直列化。
+     * 1 カット分を直列化し、同時にそのカットの確定尺を積む。
+     *
+     * **採用済みかつ ready のテイクの解決式の実装は `AdoptedReadyTakeCoverage` 1 か所だけ**である
+     * (署名 URL の発行条件と尺の算出条件を別々に組み立てると、片方だけ変わって乖離する)。
+     * ここでは判定を 2 回呼ぶ (`appendCut` で 1 回 / `CaptureCutData::fromCut()` 内部で
+     * `adoptedReadyTakeId` を作るのにもう 1 回) が、**実装が割れているわけではない**
+     * (2 か所とも同じ 1 メソッドを呼ぶだけであり、式を書き直してはいない)。
+     *
+     * @param  list<CaptureCutData>  $cutData
+     * @param  list<int|null>  $durationsMs
      *
-     * 発行条件は AdoptedReadyTakeCoverage が唯一の所在である。非 ready の採用テイクへ
-     * 署名 URL / ACK を出さない = `capture.takes.playback` が非 ready を 404 にしている
-     * (状態秘匿) のと同じゲートに揃える (RenderPipeline::clipSpecFor と同じ書き方)。
+     * @param-out list<CaptureCutData>  $cutData
+     * @param-out list<int|null>  $durationsMs
      */
-    private static function cutWithAdoptedUrls(Cut $cut, User $user, TakeObjectStorage $storage, UploadTicketCodec $codec, int $ackExpiry): CaptureCutData
-    {
-        if (AdoptedReadyTakeCoverage::readyTakeId($cut) === null) {
-            return CaptureCutData::fromCut($cut);
-        }
-
+    private static function appendCut(
+        Cut $cut,
+        User $user,
+        TakeObjectStorage $storage,
+        UploadTicketCodec $codec,
+        int $ackExpiry,
+        array &$cutData,
+        array &$durationsMs,
+    ): void {
+        $readyTakeId = AdoptedReadyTakeCoverage::readyTakeId($cut);
+        $adopted = $readyTakeId === null ? null : $cut->adoptedTake;
         // 述語が非 null なら採用テイクは必ず存在する。PHPStan level 10 は静的には ?Take のままなので
         // Assert で絞る (述語の再実装ではない = TakeStatus を参照しない)。
-        $adopted = $cut->adoptedTake;
-        Assert::notNull($adopted, 'readyTakeId() が非 null なら採用テイクは必ず存在する');
+        if ($readyTakeId !== null) {
+            Assert::notNull($adopted, 'readyTakeId() が非 null なら採用テイクは必ず存在する');
+        }
 
-        return CaptureCutData::fromCut(
-            $cut,
-            adoptedPlaybackUrl: $storage->temporaryPlaybackUrl($adopted->video_path),
-            adoptedAckToken: $codec->sealAck(new DownloadAckClaims(
-                takeId: $adopted->id,
-                userId: $user->id,
-                expiresAtTimestamp: $ackExpiry,
-            )),
-        );
+        // CaptureCutData::fromCut() が takes の eager load 済みを自分で確認する
+        // (ここでは relation に触れず Cut を渡すだけでよい)
+        $cutData[] = $adopted === null
+            ? CaptureCutData::fromCut($cut)
+            : CaptureCutData::fromCut(
+                $cut,
+                adoptedPlaybackUrl: $storage->temporaryPlaybackUrl($adopted->video_path),
+                adoptedAckToken: $codec->sealAck(new DownloadAckClaims(
+                    takeId: $adopted->id,
+                    userId: $user->id,
+                    expiresAtTimestamp: $ackExpiry,
+                )),
+            );
+
+        // 尺の式は DeterminedCutDuration が唯一の所在 (ここで組み立て直さない)
+        $durationsMs[] = DeterminedCutDuration::milliseconds($cut, $adopted);
     }
 
     /**
-     * @return array{id: int, title: string, status: string, cuts: list<array<string, mixed>>}
+     * @return array{id: int, title: string, status: string, category_name: string|null,
+     *   creator_name: string|null, updated_at: string|null, total_duration_ms: int|null,
+     *   undetermined_cut_count: int, cuts: list<array<string, mixed>>}
      */
     public function toArray(): array
     {
@@ -89,6 +136,12 @@ public function toArray(): array
             'id' => $this->manual->id,
             'title' => $this->manual->title,
             'status' => $this->manual->status->value,
+            'category_name' => $this->categoryName,
+            'creator_name' => $this->creatorName,
+            'updated_at' => $this->updatedAt,
+            // 「確定している分の合計」であって完成動画の見込み尺ではない (DeterminedScenarioDuration)
+            'total_duration_ms' => $this->duration->totalDurationMs,
+            'undetermined_cut_count' => $this->duration->undeterminedCutCount,
             'cuts' => array_map(
                 static fn (CaptureCutData $cut): array => $cut->toArray(),
                 $this->cuts,
diff --git a/app/Http/Controllers/Capture/CaptureManualController.php b/app/Http/Controllers/Capture/CaptureManualController.php
index 6e37f1cc..901dcd46 100644
--- a/app/Http/Controllers/Capture/CaptureManualController.php
+++ b/app/Http/Controllers/Capture/CaptureManualController.php
@@ -132,6 +132,10 @@ public function show(
         $user = $request->user();
         Assert::isInstanceOf($user, User::class);
 
+        // メタ情報 (カテゴリ名 / 作成者名) の解決を DTO 側の lazy load に任せない。
+        // 対象は 1 行なので追加は最大 2 クエリ (既にロード済みなら 0)。
+        $manual->loadMissing(['category', 'creator']);
+
         return Inertia::render('Capture/Show', [
             'project' => ['id' => $project->id, 'name' => $project->name],
             'manual' => CaptureManualDetailData::fromManual($manual, $user, $storage, $codec)->toArray(),
diff --git a/app/Http/Controllers/Capture/CaptureTakeController.php b/app/Http/Controllers/Capture/CaptureTakeController.php
index 6846b5e1..696f7ad1 100644
--- a/app/Http/Controllers/Capture/CaptureTakeController.php
+++ b/app/Http/Controllers/Capture/CaptureTakeController.php
@@ -109,6 +109,7 @@ public function adopt(
         Gate::authorize('adopt', $take);
 
         $adoptedCut = $takes->adopt($project, $manual, $cut, $take);
+        $adoptedCut->load('takes'); // fromCut() の relationLoaded() 検査を満たすため必須
 
         return CaptureCutResource::make(CaptureCutData::fromCut($adoptedCut));
     }
diff --git a/app/Services/Manual/DeterminedCutDuration.php b/app/Services/Manual/DeterminedCutDuration.php
new file mode 100644
index 00000000..be9cf4f6
--- /dev/null
+++ b/app/Services/Manual/DeterminedCutDuration.php
@@ -0,0 +1,59 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Manual;
+
+use App\Enums\Manual\MaterialType;
+use App\Models\Cut;
+use App\Models\Take;
+
+/**
+ * 「このカット 1 本の尺は何 ms か。決まっていないなら決まっていない」を返す式の**唯一の所在**。
+ *
+ * 決まり方は 2 通りしかない:
+ *   - 静止画として合成されるカット … `StillDisplayDuration::secondsFor()` × 1000。
+ *     **撮影前でも決まる** (編集者がシナリオ編集で入れる計画値だから)。
+ *   - 動画として合成されるカット … 採用済みかつ ready のテイクの `duration_ms`。
+ *     テイクが無い / テイクの `duration_ms` が NULL なら**決まらない** (null を返す)。
+ *
+ * **null を既定値で埋めない**。埋めたい側 (レンダの尺上限ソフトゲートは上界を安全側に見たいので
+ * `config('manual.render_default_take_duration_ms')` で埋める) が自分の政策として埋める。
+ * 表示に使う側は埋めずに「未確定」として数える。ここで埋めると、
+ * 撮っていないカットに 1 分あると利用者へ嘘をつくことになる。
+ *
+ * **採用テイクは引数で受ける** (このクラスは `adoptedTake` relation を読まない)。
+ * したがって `AdoptedTakeReferenceInventory` の登録は増えない
+ * (`EffectiveMaterialType` と同じ作法)。
+ *
+ * **ready 判定は一切しない** — 「採用済みかつ ready か」の述語は
+ * `AdoptedReadyTakeCoverage` の専権である (AGENTS.md ドメイン固有規約 12)。
+ * 呼び出し側がその述語で解決した結果を `$adoptedReadyTake` に渡す。
+ *
+ * **ナレーション尺は見ない**。v1 は字幕のみで TTS を持たず、ナレーション文に再生時間という
+ * 属性が存在しない (`StillDisplayDuration` の docblock と同じ理由・同じ再検討条件)。
+ */
+final class DeterminedCutDuration
+{
+    /**
+     * @param  Take|null  $adoptedReadyTake  採用済みかつ ready のテイク
+     *                                       (`AdoptedReadyTakeCoverage` で解決済みのもの。無ければ null)
+     * @return int|null 確定している尺 (ms)。確定していなければ null
+     */
+    public static function milliseconds(Cut $cut, ?Take $adoptedReadyTake): ?int
+    {
+        // テイクがまだ無いカットでも、計画が静止画なら尺は決まっている
+        if ($adoptedReadyTake === null) {
+            return $cut->material_type === MaterialType::Still
+                ? StillDisplayDuration::secondsFor($cut) * 1000
+                : null;
+        }
+
+        // 実体優先の判定 (cut=video / take=still の組み合わせを含む) は EffectiveMaterialType が持つ
+        if (EffectiveMaterialType::of($cut, $adoptedReadyTake) === MaterialType::Still) {
+            return StillDisplayDuration::secondsFor($cut) * 1000;
+        }
+
+        return $adoptedReadyTake->duration_ms;
+    }
+}
diff --git a/app/Services/Manual/DeterminedScenarioDuration.php b/app/Services/Manual/DeterminedScenarioDuration.php
new file mode 100644
index 00000000..407cf769
--- /dev/null
+++ b/app/Services/Manual/DeterminedScenarioDuration.php
@@ -0,0 +1,66 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Manual;
+
+use Webmozart\Assert\Assert;
+
+/**
+ * 「このシナリオで**いま尺が確定している分**は合わせて何 ms か、確定していないカットは何本か」。
+ *
+ * **完成動画の見込み尺ではない**。未撮影の動画カットの尺は v1 では原理的に出せないので
+ * (ナレーション尺の推定を持たない = `DeterminedCutDuration` の docblock)、
+ * ここが表すのは確定分の合計だけである。**未確定を 0 ms として足さない**。
+ * 1 本も確定していなければ合計は `null` で、表示側は「—」を出す
+ * (`resources/js/lib/manual/format-duration.ts` の `DURATION_UNKNOWN` と同じ思想。
+ * 未確定を `0:00` と書くと「長さゼロの動画がある」という別の嘘になる)。
+ *
+ * **入力はカット 1 本ずつの確定尺の配列だけ**である (`Cut` も `Take` も受け取らない)。
+ * したがって `adoptedTake` relation を読みようがなく、
+ * `AdoptedTakeReferenceInventory` の登録は増えない。
+ * 採用済みかつ ready のテイクの解決は呼び出し側 (`AdoptedReadyTakeCoverage` 経由) の責務である。
+ */
+final readonly class DeterminedScenarioDuration
+{
+    /**
+     * @param  int|null  $totalDurationMs  確定分の合計 (ms)。1 本も確定していなければ null
+     * @param  int  $undeterminedCutCount  尺が確定していないカット数
+     */
+    public function __construct(
+        public ?int $totalDurationMs,
+        public int $undeterminedCutCount,
+    ) {}
+
+    /**
+     * @param  list<int|null>  $perCutDurationsMs  カットの表示順に並べた確定尺
+     *                                             (`DeterminedCutDuration::milliseconds()` の戻り値)
+     */
+    public static function fromCutDurations(array $perCutDurationsMs): self
+    {
+        // array_sum() は使わない。整数加算が PHP_INT_MAX を超えると array_sum() は
+        // float を返し得るため、readonly コンストラクタの int 契約と静的に矛盾しうる。
+        // 1 パスの明示ループで加算前に範囲を検査し、クランプせず例外にする
+        // (異常値を黙って変えない)。
+        $total = 0;
+        $undeterminedCount = 0;
+        foreach ($perCutDurationsMs as $ms) {
+            if ($ms === null) {
+                $undeterminedCount++;
+
+                continue;
+            }
+
+            Assert::greaterThanEq($ms, 0, 'カットの確定尺は負値になり得ない');
+            Assert::lessThanEq($ms, PHP_INT_MAX - $total, 'カット尺の合計が PHP_INT_MAX を超える');
+            $total += $ms;
+        }
+
+        $determinedCount = count($perCutDurationsMs) - $undeterminedCount;
+
+        return new self(
+            totalDurationMs: $determinedCount === 0 ? null : $total,
+            undeterminedCutCount: $undeterminedCount,
+        );
+    }
+}
diff --git a/app/Services/Manual/RenderJobService.php b/app/Services/Manual/RenderJobService.php
index 40b10b24..d5b15f18 100644
--- a/app/Services/Manual/RenderJobService.php
+++ b/app/Services/Manual/RenderJobService.php
@@ -8,7 +8,6 @@
 use App\DataTransferObjects\Manual\Render\RenderResult;
 use App\Enums\Billing\TicketReservationStatus;
 use App\Enums\Manual\JobStatus;
-use App\Enums\Manual\MaterialType;
 use App\Enums\Manual\RenderConflictType;
 use App\Enums\Manual\RenderErrorCode;
 use App\Enums\Manual\RenderKind;
@@ -438,7 +437,11 @@ private function assertAllCutsHaveAdoptedReadyTakes(array $ordered): void
 
     /**
      * 尺上限ソフトゲート (§10.8-1: TTL 内 commit)。クライアント申告値ベースで、
-     * ハード保証はジョブ timeout が担う。duration_ms NULL は保守的な既定尺で代用する。
+     * ハード保証はジョブ timeout が担う。
+     *
+     * **尺の式は持たない** (`DeterminedCutDuration` が唯一の所在)。
+     * ここに残るのは「確定していないカットを上界として何 ms とみなすか」という
+     * **このゲートだけの政策**である (撮影 PWA の表示側は埋めずに未確定として数える)。
      *
      * @param  list<OrderedCut>  $ordered
      */
@@ -452,12 +455,7 @@ private function assertTotalSourceDurationWithinLimit(array $ordered): void
             // ここへ来る時点で採用テイクは確定している (充足判定 = AdoptedReadyTakeCoverage が先に 422 を出す)
             Assert::notNull($take, '充足判定を通った cut には採用テイクが必ず存在する');
 
-            // レンダ (RenderPipeline::clipSpecFor) と**同じ 2 クラス**を通す。
-            // 片方だけ実効判定を持つと、cut=video/take=still の組み合わせで
-            // ゲート 60 秒 / レンダ 5 秒という新しい二重管理が生まれる
-            $totalMs += EffectiveMaterialType::of($cut, $take) === MaterialType::Still
-                ? StillDisplayDuration::secondsFor($cut) * 1000
-                : ($take->duration_ms ?? $defaultMs);
+            $totalMs += DeterminedCutDuration::milliseconds($cut, $take) ?? $defaultMs;
         }
 
         if ($totalMs > config()->integer('manual.render_max_total_source_ms')) {
diff --git a/app/Support/Security/AdoptedTakeReferenceInventory.php b/app/Support/Security/AdoptedTakeReferenceInventory.php
index ddde4f98..d994bfe9 100644
--- a/app/Support/Security/AdoptedTakeReferenceInventory.php
+++ b/app/Support/Security/AdoptedTakeReferenceInventory.php
@@ -55,7 +55,9 @@ public static function entries(): array
                 'kind' => AdoptedTakeReferenceKind::DelegatedToCoverage,
                 'rationale' => '採用テイクの署名 URL / ACK を出すかどうかを'
                     .'AdoptedReadyTakeCoverage::readyTakeId() へ委譲し、自前の ready 判定は持たない。'
-                    .'残る参照は非欠落側で素材パスと take id を読む 1 箇所と、N+1 を防ぐ eager load である。',
+                    .'残る参照は非欠落側で素材パスと take id を読む 1 箇所と、N+1 を防ぐ eager load である。'
+                    .'同じ 1 回の解決結果 (readyTakeId が非 null なら取得する採用テイク) を、'
+                    .'署名 URL/ACK の発行条件だけでなく DeterminedCutDuration へ渡す尺の式へも共有する。',
             ],
             'DataTransferObjects/Manual/CutTakeSummaryData.php' => [
                 'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
diff --git a/resources/js/components/features/capture/ManualMetaSummary.svelte b/resources/js/components/features/capture/ManualMetaSummary.svelte
new file mode 100644
index 00000000..50da1bac
--- /dev/null
+++ b/resources/js/components/features/capture/ManualMetaSummary.svelte
@@ -0,0 +1,63 @@
+<script lang="ts">
+    import { Clock } from "@lucide/svelte";
+    import { formatDate } from "@/lib/date-format";
+    import { formatDurationMs } from "@/lib/manual/format-duration";
+
+    /**
+     * 撮影 PWA シナリオ詳細のメタ情報 (doc/05 §5.2: タイトル / TIME 合計 / カテゴリ・日付・作成者)。
+     * タイトルは PageHeaderSection の h1 が持つので、ここは残り 4 つを出す。
+     *
+     * **合計時間は「いま尺が確定している分」の合計**であり完成動画の見込み尺ではない。
+     * 判定も整形規則もサーバから来た 2 つの値だけで決め、ここで条件を足さない
+     * (秘匿境界も算出も props 側で解決済み)。
+     *
+     * PC 一覧の「再生時間」(公開済み完成動画の実尺) とは**別の量**なので同じ語を使わない。
+     */
+    interface Props {
+        categoryName: string | null;
+        creatorName: string | null;
+        updatedAt: string | null;
+        /** 確定分の合計 (ms)。1 本も確定していなければ null */
+        totalDurationMs: number | null;
+        /** 尺が確定していないカット数 */
+        undeterminedCutCount: number;
+    }
+
+    let {
+        categoryName,
+        creatorName,
+        updatedAt,
+        totalDurationMs,
+        undeterminedCutCount,
+    }: Props = $props();
+
+    /**
+     * 未確定が 1 件でもあれば、値が部分和であることを値の隣で言う。
+     *
+     * **`totalDurationMs === null` (全件未確定) のときは「確定分・」を前置しない**
+     * (「確定分・未確定 5 カット」と書くと、確定分が実在するかのように読めてしまう —
+     * 合計は `—` で確定分自体が無いため)。
+     */
+    const durationNote = $derived(
+        undeterminedCutCount === 0
+            ? null
+            : totalDurationMs === null
+                ? `未確定 ${undeterminedCutCount} カット`
+                : `確定分・未確定 ${undeterminedCutCount} カット`,
+    );
+</script>
+
+<div
+    class="rounded-md border border-border bg-surface px-3 py-2"
+    data-testid="capture-manual-meta"
+>
+    <p class="flex items-center gap-1 text-body" data-testid="capture-manual-duration">
+        <Clock class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
+        合計時間 {formatDurationMs(totalDurationMs)}{#if durationNote !== null}<span
+                class="text-caption text-text-secondary">（{durationNote}）</span
+            >{/if}
+    </p>
+    <p class="mt-1 text-caption text-text-secondary" data-testid="capture-manual-meta-line">
+        {categoryName ?? "未分類"} ・ {creatorName ?? "不明"} ・ 更新 {formatDate(updatedAt)}
+    </p>
+</div>
diff --git a/resources/js/pages/Capture/Show.svelte b/resources/js/pages/Capture/Show.svelte
index da0eb105..5b46c361 100644
--- a/resources/js/pages/Capture/Show.svelte
+++ b/resources/js/pages/Capture/Show.svelte
@@ -11,6 +11,7 @@
     import CaptureFileFallback from "@/components/features/capture/CaptureFileFallback.svelte";
     import CutNavigator from "@/components/features/capture/CutNavigator.svelte";
     import CutSwipeBar from "@/components/features/capture/CutSwipeBar.svelte";
+    import ManualMetaSummary from "@/components/features/capture/ManualMetaSummary.svelte";
     import ScenarioPreviewDialog from "@/components/features/capture/ScenarioPreviewDialog.svelte";
     import TakeStrip from "@/components/features/capture/TakeStrip.svelte";
     import UploadQueueBar from "@/components/features/capture/UploadQueueBar.svelte";
@@ -493,6 +494,16 @@
                     マニュアル詳細へ
                 </TextLink>
             </PageHeaderSection>
+            <!-- doc/05 §5.2 のシナリオメタ情報。タイトルは上の h1 が持つ -->
+            <div class="mt-3">
+                <ManualMetaSummary
+                    categoryName={manual.category_name}
+                    creatorName={manual.creator_name}
+                    updatedAt={manual.updated_at}
+                    totalDurationMs={manual.total_duration_ms}
+                    undeterminedCutCount={manual.undetermined_cut_count}
+                />
+            </div>
         </div>
 
         <!-- UploadQueueBar は全画面かどうかで **どちらか一方にだけ** 置く
diff --git a/resources/js/types/capture.ts b/resources/js/types/capture.ts
index b173ddd5..bace6e95 100644
--- a/resources/js/types/capture.ts
+++ b/resources/js/types/capture.ts
@@ -56,6 +56,21 @@ export interface CaptureManualDetail {
     id: number;
     title: string;
     status: string;
+    /** カテゴリ名。未分類は null (UI は「未分類」) */
+    category_name: string | null;
+    /** 作成者名。退会/削除で解決不可のときは null (UI は「不明」) */
+    creator_name: string | null;
+    /** 更新日時 (ISO 8601)。UI は lib/date-format.ts の formatDate で描く */
+    updated_at: string | null;
+    /**
+     * **いま尺が確定しているカットだけ**の合計 (ms)。1 本も確定していなければ null。
+     * **完成動画の見込み尺ではない** — 未撮影の動画カットの尺は v1 では出せない
+     * (PHP 側 Services/Manual/DeterminedScenarioDuration が正本)。
+     * PC 一覧の `duration_ms` (公開済み完成動画の実尺) とは**別の量**なので統合しない。
+     */
+    total_duration_ms: number | null;
+    /** 尺が確定していないカット数。**常に併記する** (— だけでは「カット無し」と区別できない) */
+    undetermined_cut_count: number;
     cuts: CaptureCut[];
 }
 
diff --git a/tests/Architecture/DeterminedCutDurationSourceShapePinTest.php b/tests/Architecture/DeterminedCutDurationSourceShapePinTest.php
new file mode 100644
index 00000000..050c8df4
--- /dev/null
+++ b/tests/Architecture/DeterminedCutDurationSourceShapePinTest.php
@@ -0,0 +1,74 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Services\Manual\RenderJobService;
+
+/*
+ * RenderJobService::assertTotalSourceDurationWithinLimit() の**現在のソース形**が
+ * DeterminedCutDuration::milliseconds() への委譲を含むことを固定する source-shape pin である。
+ *
+ * **これは「唯一の所在」を保証する不変条件テストではない** (design-review Round 2/3 対応で
+ * 保証範囲を明示的に狭めた)。検出するのは「委譲の文字列が現在のソースに存在するか」
+ * という正の判定 1 つだけである。検出しないもの: alias import 経由の呼び出し
+ * (`use DeterminedCutDuration as X`) / 旧式の 3 分岐が委譲と**並存して**残っていること /
+ * 別クラスでの同じ 3 分岐の再実装 / コメント・文字列リテラル内の記述との区別。
+ * (Round 2 版は「旧来のパターンを含まない」ことの否定判定も持っていたが、
+ * 部分文字列一致の否定は AGENTS.md 走査器共通規約 (e) に抵触するため Round 3 で削除した。
+ * 「拾いすぎる (誤って赤くする) のは可、見逃す (誤って緑にする) のは不可」の原則に照らすと、
+ * 否定判定は見逃しの実害の方が大きいため、正の判定だけに絞る方を選ぶ。)
+ *
+ * 正例テストと合成負例の自己テストは**同一ファイルに置く**
+ * (design-review Round 3 [Warning] 対応。別テストファイル・別レーンへローカル関数を
+ * 共有する前提を作らない)。
+ */
+function determinedCutDurationDelegationPresent(string $methodBody): bool
+{
+    return str_contains($methodBody, 'DeterminedCutDuration::milliseconds(');
+}
+
+/**
+ * メソッド定義行の範囲をファイルから切り出すだけの小さいヘルパ
+ * (`ReflectionMethod::getStartLine()`/`getEndLine()` パターン。
+ * クラス名・名前解決は伴わない = 走査器共通規約 (a) の対象外)。
+ */
+function sourceOf(string $class, string $method): string
+{
+    $reflection = new ReflectionMethod($class, $method);
+    $file = $reflection->getFileName();
+    if ($file === false) {
+        throw new RuntimeException("{$class}::{$method}() のファイルパスが取得できない");
+    }
+
+    $lines = file($file);
+    if ($lines === false) {
+        throw new RuntimeException("{$file} が読み取れない");
+    }
+
+    $start = $reflection->getStartLine();
+    $end = $reflection->getEndLine();
+    if ($start === false || $end === false) {
+        throw new RuntimeException("{$class}::{$method}() の行範囲が取得できない");
+    }
+
+    return implode('', array_slice($lines, $start - 1, $end - $start + 1));
+}
+
+test('RenderJobService の尺上限ゲートは DeterminedCutDuration への委譲を含む', function (): void {
+    $body = sourceOf(RenderJobService::class, 'assertTotalSourceDurationWithinLimit');
+
+    expect(determinedCutDurationDelegationPresent($body))->toBeTrue();
+});
+
+// 自己テスト: 委譲を含まない合成文字列を検出器へ直接与え、false を返すことを固定する
+// (Pest の失敗する assertion を負例にそのまま流用する問題を避けるため、
+// 検出処理を独立した純粋関数にしてある)
+test('検出器は委譲を含まない文字列を偽と判定する (自己テスト)', function (): void {
+    $legacyBody = <<<'PHP'
+        $totalMs += EffectiveMaterialType::of($cut, $take) === MaterialType::Still
+            ? StillDisplayDuration::secondsFor($cut) * 1000
+            : ($take->duration_ms ?? $defaultMs);
+        PHP;
+
+    expect(determinedCutDurationDelegationPresent($legacyBody))->toBeFalse();
+});
diff --git a/tests/Feature/Capture/CaptureManualBrowsingTest.php b/tests/Feature/Capture/CaptureManualBrowsingTest.php
index 0dc6cbab..c4132dbe 100644
--- a/tests/Feature/Capture/CaptureManualBrowsingTest.php
+++ b/tests/Feature/Capture/CaptureManualBrowsingTest.php
@@ -199,6 +199,105 @@ function browsingContext(): array
     ]);
 });
 
+/*
+ * メタ情報 (施策3): カテゴリ名 / 作成者名 / 更新日時 / 合計時間 (doc/05 §5.2)。
+ * 合計時間は「いま尺が確定している分」の合計であって完成動画の見込み尺ではない。
+ */
+
+test('show の manual 直下キー集合は TS CaptureManualDetail と対 (PHP↔TS 契約)', function (): void {
+    [, $owner, $project] = browsingContext();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+
+    $response = $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals/{$manual->id}");
+
+    $props = $response->inertiaPage()['props']['manual'];
+    expect(array_keys($props))->toBe([
+        'id', 'title', 'status', 'category_name', 'creator_name', 'updated_at',
+        'total_duration_ms', 'undetermined_cut_count', 'cuts',
+    ]);
+});
+
+test('show はカテゴリ名・作成者名・更新日時 (ISO 8601) を返す', function (): void {
+    [, $owner, $project] = browsingContext();
+    $category = Category::factory()->forProject($project)->create(['name' => '組立作業']);
+    $manual = VideoManual::factory()->forProject($project)->forCategory($category)
+        ->createdBy($owner)->create(['status' => 'ready']);
+
+    $response = $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals/{$manual->id}");
+
+    $response->assertInertia(fn (Assert $page) => $page
+        ->where('manual.category_name', '組立作業')
+        ->where('manual.creator_name', $owner->name)
+        ->where('manual.updated_at', $manual->fresh()?->updated_at?->toIso8601String()));
+});
+
+test('show は未分類なら category_name が null', function (): void {
+    [, $owner, $project] = browsingContext();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+
+    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals/{$manual->id}")
+        ->assertInertia(fn (Assert $page) => $page->where('manual.category_name', null));
+});
+
+test('show の合計時間は静止画カット (未撮影) + 動画カット (採用 ready) の合算で未確定 0 件', function (): void {
+    [, $owner, $project] = browsingContext();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+    Cut::factory()->forManual($manual)->withSortOrder(0)->create([
+        'material_type' => 'still',
+        'static_display_seconds' => 4, // → 4,000ms (撮影前でも確定)
+    ]);
+    $videoCut = Cut::factory()->forManual($manual)->withSortOrder(1)->create(['material_type' => 'video']);
+    $take = Take::factory()->forCut($videoCut)->create(['duration_ms' => 6_000]);
+    $videoCut->forceFill(['adopted_take_id' => $take->id])->save();
+
+    $storage = Mockery::mock(TakeObjectStorage::class);
+    $storage->shouldReceive('temporaryPlaybackUrl')->andReturn('https://s3.fake.test/signed-get-url');
+    app()->instance(TakeObjectStorage::class, $storage);
+
+    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals/{$manual->id}")
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('manual.total_duration_ms', 10_000)
+            ->where('manual.undetermined_cut_count', 0));
+});
+
+test('show は未撮影の動画カットを未確定として数え、合計からは除く', function (): void {
+    [, $owner, $project] = browsingContext();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+    Cut::factory()->forManual($manual)->withSortOrder(0)->create(['material_type' => 'still', 'static_display_seconds' => 3]);
+    Cut::factory()->forManual($manual)->withSortOrder(1)->create(['material_type' => 'video']); // 未撮影
+
+    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals/{$manual->id}")
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('manual.total_duration_ms', 3_000)
+            ->where('manual.undetermined_cut_count', 1));
+});
+
+test('採用済みだが ready でないテイクは URL も尺も未確定として扱う', function (): void {
+    [, $owner, $project] = browsingContext();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+    $cut = Cut::factory()->forManual($manual)->withSortOrder(0)->create(['material_type' => 'video']);
+    $notReadyTake = Take::factory()->forCut($cut)->create(['status' => 'processing', 'duration_ms' => 9_000]);
+    $cut->forceFill(['adopted_take_id' => $notReadyTake->id])->save();
+
+    $response = $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals/{$manual->id}");
+
+    $response->assertInertia(fn (Assert $page) => $page
+        ->where('manual.cuts.0.takes.0.playback_url', null)
+        ->where('manual.cuts.0.takes.0.download_ack_token', null)
+        ->where('manual.total_duration_ms', null)
+        ->where('manual.undetermined_cut_count', 1));
+});
+
+test('同一 org 内の別 project の manual を URL に差し込むと 404 (認可より前)', function (): void {
+    [$organization, $owner, $projectA] = browsingContext();
+    $projectB = Project::factory()->forOrganization($organization)->create();
+    $manualOfB = VideoManual::factory()->forProject($projectB)->create(['status' => 'ready']);
+
+    $this->actingAs($owner)
+        ->get("/app/projects/{$projectA->id}/manuals/{$manualOfB->id}")
+        ->assertNotFound();
+});
+
 test('cross-org の project は index / show とも 404', function (): void {
     [, $owner] = createOrganizationWithOwner();
     [, , $otherProject] = browsingContext();
diff --git a/tests/Feature/Capture/CaptureManualDetailQueryCountTest.php b/tests/Feature/Capture/CaptureManualDetailQueryCountTest.php
new file mode 100644
index 00000000..136dd601
--- /dev/null
+++ b/tests/Feature/Capture/CaptureManualDetailQueryCountTest.php
@@ -0,0 +1,99 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Cut;
+use App\Models\Project;
+use App\Models\Take;
+use App\Models\User;
+use App\Models\VideoManual;
+use Illuminate\Support\Facades\DB;
+
+/*
+ * T232: 撮影詳細のクエリ数が**カット数・テイク数のどちらにも比例しない**ことを固定する。
+ *
+ * メタ情報 (カテゴリ名 / 作成者名) は controller の loadMissing で 1 行あたり最大 2 クエリ、
+ * 合計時間は既に取得済みのカット列と採用テイクから作るので追加クエリを持たない。
+ * カットごとに adoptedTake / takes を lazy load する形へ戻ると、ここで検出できる。
+ *
+ * 2 軸を**独立に**検証する (どちらか一方だけを変えたケース):
+ *   1. カット数を変える (1 本 / 10 本)。各カットのテイク数は揃える。
+ *   2. カット数を揃え、1 カットあたりのテイク数を変える (1 本 / 5 本)。
+ *
+ * 計測は「GET 1 回ぶん」に限り、fixture 生成は flushQueryLog で計測外にする。
+ * 初回リクエスト固有の初期化を混ぜないよう、計測前に暖機の GET を 1 回撃つ。
+ */
+
+/** 指定本数のカットを持つ manual を作り、各カットに指定本数のテイクをぶら下げる */
+function manualWithCutsAndTakes(Project $project, int $cutCount, int $takesPerCut): VideoManual
+{
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+    foreach (range(1, $cutCount) as $index) {
+        $cut = Cut::factory()->forManual($manual)->withSortOrder($index)->create();
+        foreach (range(1, $takesPerCut) as $takeIndex) {
+            Take::factory()->forCut($cut)->create(['sort_order' => $takeIndex]);
+        }
+    }
+
+    return $manual;
+}
+
+/**
+ * 撮影詳細 GET 1 回ぶんに実行された SQL。
+ *
+ * @return list<string>
+ */
+function measureCaptureShowQueries(User $actor, Project $project, VideoManual $manual): array
+{
+    DB::enableQueryLog();
+    DB::flushQueryLog();
+    test()->actingAs($actor)->get("/app/projects/{$project->id}/manuals/{$manual->id}")->assertOk();
+    $log = DB::getQueryLog();
+    DB::disableQueryLog();
+
+    return array_map(static fn (array $entry): string => (string) $entry['query'], $log);
+}
+
+/**
+ * @param  list<string>  $single
+ * @param  list<string>  $many
+ */
+function expectSameShowQueryCount(array $single, array $many): void
+{
+    expect($single)->not->toBeEmpty();
+    expect(count($many))->toBe(
+        count($single),
+        '撮影詳細のクエリ数が比例しました (基準: '.count($single).' 件 / 比較対象: '
+        .count($many)." 件)。\n比較対象の SQL:\n".implode("\n", $many)
+    );
+}
+
+test('撮影詳細のクエリ数はカット数に比例しない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    $singleCutManual = manualWithCutsAndTakes($project, cutCount: 1, takesPerCut: 2);
+    $tenCutsManual = manualWithCutsAndTakes($project, cutCount: 10, takesPerCut: 2);
+
+    measureCaptureShowQueries($owner, $project, $singleCutManual); // 暖機
+
+    expectSameShowQueryCount(
+        measureCaptureShowQueries($owner, $project, $singleCutManual),
+        measureCaptureShowQueries($owner, $project, $tenCutsManual),
+    );
+});
+
+test('撮影詳細のクエリ数はカット 1 本あたりのテイク数に比例しない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    $fewTakesManual = manualWithCutsAndTakes($project, cutCount: 3, takesPerCut: 1);
+    $manyTakesManual = manualWithCutsAndTakes($project, cutCount: 3, takesPerCut: 5);
+
+    measureCaptureShowQueries($owner, $project, $fewTakesManual); // 暖機
+
+    expectSameShowQueryCount(
+        measureCaptureShowQueries($owner, $project, $fewTakesManual),
+        measureCaptureShowQueries($owner, $project, $manyTakesManual),
+    );
+});
diff --git a/tests/Feature/Manual/RenderTriggerTest.php b/tests/Feature/Manual/RenderTriggerTest.php
index d4cea84c..df139b98 100644
--- a/tests/Feature/Manual/RenderTriggerTest.php
+++ b/tests/Feature/Manual/RenderTriggerTest.php
@@ -215,6 +215,41 @@ function adoptReadyTake(Cut $cut, int $durationMs = 5_000): Take
     expect(RenderJob::query()->count())->toBe(0);
 });
 
+test('尺上限: 合計がちょうど上限なら 201 (境界値。DeterminedCutDuration への切り出しで挙動が動かないことの固定)', function (): void {
+    Queue::fake();
+    config()->set('manual.render_max_total_source_ms', 5_000);
+    [, $owner, $project, $manual] = renderTriggerContext(); // 採用テイクは 5,000ms ちょうど
+
+    $this->actingAs($owner)->postJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/render",
+    )->assertCreated();
+});
+
+test('尺上限: 合計が上限 +1ms なら 422 (境界値)', function (): void {
+    Queue::fake();
+    config()->set('manual.render_max_total_source_ms', 4_999);
+    [, $owner, $project, $manual] = renderTriggerContext(); // 採用テイクは 5,000ms
+
+    $this->actingAs($owner)->postJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/render",
+    )->assertUnprocessable()->assertJsonValidationErrors(['takes']);
+    expect(RenderJob::query()->count())->toBe(0);
+});
+
+test('尺上限: duration_ms NULL の採用テイクは render_default_take_duration_ms で数えられる', function (): void {
+    Queue::fake();
+    config()->set('manual.render_default_take_duration_ms', 60_000);
+    config()->set('manual.render_max_total_source_ms', 59_999);
+    [, $owner, $project, $manual, $cut] = renderTriggerContext();
+    $cut->adoptedTake?->forceFill(['duration_ms' => null])->save();
+
+    // 60,000ms (既定値) で数えられ、59,999ms の上限を超えるので 422
+    $this->actingAs($owner)->postJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/render",
+    )->assertUnprocessable()->assertJsonValidationErrors(['takes']);
+    expect(RenderJob::query()->count())->toBe(0);
+});
+
 test('残高不足は 402 (code=insufficient_tickets。job も予約も作らない・status 不変)', function (): void {
     Queue::fake();
     [, $owner, $project, $manual] = renderTriggerContext(tickets: 2); // cost=3 に不足
diff --git a/tests/Unit/DataTransferObjects/Capture/CaptureCutDataTest.php b/tests/Unit/DataTransferObjects/Capture/CaptureCutDataTest.php
new file mode 100644
index 00000000..65227c3b
--- /dev/null
+++ b/tests/Unit/DataTransferObjects/Capture/CaptureCutDataTest.php
@@ -0,0 +1,52 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Capture\CaptureCutData;
+use App\Models\Cut;
+use App\Models\Take;
+use Illuminate\Database\Eloquent\Collection;
+use Webmozart\Assert\InvalidArgumentException;
+
+/*
+ * CaptureCutData::fromCut() の takes 取得契約 (design-review Round 2/3 対応)。
+ *
+ * - takes relation は呼び出し側が eager load してから渡す。未ロードなら例外にする
+ *   (`relationLoaded('takes')` を `$cut->takes` へ触れる前に確認する fail-closed 作法)。
+ * - ロードされている全 take の cut_id が対象 cut の id と一致することも検査する
+ *   (`setRelation()` 経由の別カット・別テナント混入を防ぐ)。
+ * - 表示順は sort_order → id で fromCut() 自身が保証する。
+ */
+
+test('takes を eager load していない cut を渡すと例外になる', function (): void {
+    $cut = Cut::factory()->create();
+
+    CaptureCutData::fromCut($cut);
+})->throws(InvalidArgumentException::class);
+
+test('takes の表示順は sort_order → id で維持される (投入順が逆でも)', function (): void {
+    $cut = Cut::factory()->create();
+    $first = Take::factory()->forCut($cut)->create(['sort_order' => 0]);
+    $second = Take::factory()->forCut($cut)->create(['sort_order' => 1]);
+
+    // わざと投入順を逆にして setRelation する
+    $cut->setRelation('takes', new Collection([$second, $first]));
+
+    $data = CaptureCutData::fromCut($cut);
+
+    expect($data->takes)->toHaveCount(2);
+    expect($data->takes[0]->take->id)->toBe($first->id);
+    expect($data->takes[1]->take->id)->toBe($second->id);
+});
+
+test('別 cut の take が setRelation() で紛れ込んでいたら例外になる', function (): void {
+    $cut = Cut::factory()->create();
+    $ownTake = Take::factory()->forCut($cut)->create();
+    $otherCut = Cut::factory()->create();
+    $foreignTake = Take::factory()->forCut($otherCut)->create();
+
+    // relationLoaded() は true になるが、cut_id が一致しない take が混入している
+    $cut->setRelation('takes', new Collection([$ownTake, $foreignTake]));
+
+    CaptureCutData::fromCut($cut);
+})->throws(InvalidArgumentException::class);
diff --git a/tests/Unit/Manual/DeterminedCutDurationTest.php b/tests/Unit/Manual/DeterminedCutDurationTest.php
new file mode 100644
index 00000000..48cb73e4
--- /dev/null
+++ b/tests/Unit/Manual/DeterminedCutDurationTest.php
@@ -0,0 +1,76 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\MaterialType;
+use App\Models\Cut;
+use App\Models\Take;
+use App\Services\Manual\DeterminedCutDuration;
+
+/*
+ * カット 1 本の確定尺の式 (唯一の所在)。
+ *
+ * 決まり方は 2 通り (静止画は計画だけで決まる / 動画は採用済み ready テイクの duration_ms)。
+ * それ以外は null (未確定。既定値で埋めない)。
+ */
+
+test('テイク無し + still カットは static_display_seconds × 1000', function (): void {
+    $cut = Cut::factory()->make([
+        'material_type' => MaterialType::Still->value,
+        'static_display_seconds' => 8,
+    ]);
+
+    expect(DeterminedCutDuration::milliseconds($cut, null))->toBe(8_000);
+});
+
+test('テイク無し + still カットで static_display_seconds 未指定なら既定値を使う', function (): void {
+    config()->set('manual.default_still_display_seconds', 6);
+    $cut = Cut::factory()->make([
+        'material_type' => MaterialType::Still->value,
+        'static_display_seconds' => null,
+    ]);
+
+    expect(DeterminedCutDuration::milliseconds($cut, null))->toBe(6_000);
+});
+
+test('テイク無し + video カットは未確定 (null)', function (): void {
+    $cut = Cut::factory()->make(['material_type' => MaterialType::Video->value]);
+
+    expect(DeterminedCutDuration::milliseconds($cut, null))->toBeNull();
+});
+
+test('テイク無し + material_type 未指定 (NULL) は未確定 (null)', function (): void {
+    $cut = Cut::factory()->make(['material_type' => null]);
+
+    expect(DeterminedCutDuration::milliseconds($cut, null))->toBeNull();
+});
+
+test('テイクあり + 実効 still (cut=video / take=still の組み合わせを含む) は静止表示秒 × 1000', function (): void {
+    $cut = Cut::factory()->make([
+        'material_type' => MaterialType::Video->value,
+        'static_display_seconds' => 4,
+    ]);
+    $take = Take::factory()->make(['material_type' => MaterialType::Still->value]);
+
+    expect(DeterminedCutDuration::milliseconds($cut, $take))->toBe(4_000);
+});
+
+test('テイクあり + 実効 video + duration_ms 非 NULL はその値', function (): void {
+    $cut = Cut::factory()->make(['material_type' => MaterialType::Video->value]);
+    $take = Take::factory()->make([
+        'material_type' => MaterialType::Video->value,
+        'duration_ms' => 12_345,
+    ]);
+
+    expect(DeterminedCutDuration::milliseconds($cut, $take))->toBe(12_345);
+});
+
+test('テイクあり + 実効 video + duration_ms NULL は未確定 (既定値で埋めない)', function (): void {
+    $cut = Cut::factory()->make(['material_type' => MaterialType::Video->value]);
+    $take = Take::factory()->make([
+        'material_type' => MaterialType::Video->value,
+        'duration_ms' => null,
+    ]);
+
+    expect(DeterminedCutDuration::milliseconds($cut, $take))->toBeNull();
+});
diff --git a/tests/Unit/Manual/DeterminedScenarioDurationTest.php b/tests/Unit/Manual/DeterminedScenarioDurationTest.php
new file mode 100644
index 00000000..4062e749
--- /dev/null
+++ b/tests/Unit/Manual/DeterminedScenarioDurationTest.php
@@ -0,0 +1,60 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Services\Manual\DeterminedScenarioDuration;
+use Webmozart\Assert\InvalidArgumentException;
+
+/*
+ * シナリオ全体の確定尺の集計 (「いま尺が確定している分」の合計であって完成動画の見込み尺ではない)。
+ * 未確定を 0 ms として足さない。1 本も確定していなければ合計は null。
+ */
+
+test('空配列は合計 null / 未確定 0 件', function (): void {
+    $result = DeterminedScenarioDuration::fromCutDurations([]);
+
+    expect($result->totalDurationMs)->toBeNull();
+    expect($result->undeterminedCutCount)->toBe(0);
+});
+
+test('全件 null は合計 null / 未確定は件数ぶん', function (): void {
+    $result = DeterminedScenarioDuration::fromCutDurations([null, null, null]);
+
+    expect($result->totalDurationMs)->toBeNull();
+    expect($result->undeterminedCutCount)->toBe(3);
+});
+
+test('混在は確定分だけ合計し未確定を数える', function (): void {
+    $result = DeterminedScenarioDuration::fromCutDurations([1_000, null, 2_500]);
+
+    expect($result->totalDurationMs)->toBe(3_500);
+    expect($result->undeterminedCutCount)->toBe(1);
+});
+
+test('全件確定は合計 + 未確定 0 件', function (): void {
+    $result = DeterminedScenarioDuration::fromCutDurations([1_000, 2_000]);
+
+    expect($result->totalDurationMs)->toBe(3_000);
+    expect($result->undeterminedCutCount)->toBe(0);
+});
+
+test('確定分が 0 ms だけなら合計は 0 (null にしない)', function (): void {
+    $result = DeterminedScenarioDuration::fromCutDurations([0]);
+
+    expect($result->totalDurationMs)->toBe(0);
+    expect($result->undeterminedCutCount)->toBe(0);
+});
+
+test('負値混入は例外 (カット尺は負値になり得ない)', function (): void {
+    DeterminedScenarioDuration::fromCutDurations([-1]);
+})->throws(InvalidArgumentException::class);
+
+test('桁溢れ境界 (PHP_INT_MAX の次の加算) は例外', function (): void {
+    DeterminedScenarioDuration::fromCutDurations([PHP_INT_MAX, 1]);
+})->throws(InvalidArgumentException::class);
+
+test('PHP_INT_MAX 単体は許可される', function (): void {
+    $result = DeterminedScenarioDuration::fromCutDurations([PHP_INT_MAX]);
+
+    expect($result->totalDurationMs)->toBe(PHP_INT_MAX);
+});
diff --git a/tests/js/components/features/capture/ManualMetaSummary.test.ts b/tests/js/components/features/capture/ManualMetaSummary.test.ts
new file mode 100644
index 00000000..81b22e07
--- /dev/null
+++ b/tests/js/components/features/capture/ManualMetaSummary.test.ts
@@ -0,0 +1,108 @@
+import { describe, expect, it } from "vitest";
+import { render, screen } from "@testing-library/svelte";
+import ManualMetaSummary from "@/components/features/capture/ManualMetaSummary.svelte";
+
+/*
+ * 撮影 PWA シナリオ詳細のメタ情報 (doc/05 §5.2: TIME 合計 / カテゴリ・日付・作成者)。
+ *
+ * 合計時間は「いま尺が確定している分」の合計であって完成動画の見込み尺ではない。
+ * 全件未確定のときは「確定分・」を前置しない (確定分が実在するかのように読めるため)。
+ */
+
+describe("ManualMetaSummary", () => {
+    it("全件確定なら合計時間を出し但し書きは出ない", () => {
+        render(ManualMetaSummary, {
+            props: {
+                categoryName: "組立作業",
+                creatorName: "山田太郎",
+                updatedAt: "2026-07-01T00:00:00Z",
+                totalDurationMs: 200_000, // 3:20
+                undeterminedCutCount: 0,
+            },
+        });
+
+        expect(screen.getByTestId("capture-manual-duration").textContent).toContain("合計時間 3:20");
+        expect(screen.getByTestId("capture-manual-duration").textContent).not.toContain("確定分");
+        expect(screen.getByTestId("capture-manual-duration").textContent).not.toContain("未確定");
+    });
+
+    it("一部未確定なら「確定分・未確定 N カット」を併記する", () => {
+        render(ManualMetaSummary, {
+            props: {
+                categoryName: "組立作業",
+                creatorName: "山田太郎",
+                updatedAt: "2026-07-01T00:00:00Z",
+                totalDurationMs: 200_000,
+                undeterminedCutCount: 2,
+            },
+        });
+
+        expect(screen.getByTestId("capture-manual-duration").textContent).toContain("合計時間 3:20");
+        expect(screen.getByTestId("capture-manual-duration").textContent).toContain("確定分・未確定 2 カット");
+    });
+
+    it("全件未確定なら「—（未確定 N カット）」で「確定分・」は前置しない", () => {
+        render(ManualMetaSummary, {
+            props: {
+                categoryName: "組立作業",
+                creatorName: "山田太郎",
+                updatedAt: "2026-07-01T00:00:00Z",
+                totalDurationMs: null,
+                undeterminedCutCount: 5,
+            },
+        });
+
+        const text = screen.getByTestId("capture-manual-duration").textContent ?? "";
+        expect(text).toContain("合計時間 —");
+        expect(text).toContain("未確定 5 カット");
+        expect(text).not.toContain("確定分");
+    });
+
+    it("カット 0 件 (null/0) なら「合計時間 —」で但し書きは出ない", () => {
+        render(ManualMetaSummary, {
+            props: {
+                categoryName: "組立作業",
+                creatorName: "山田太郎",
+                updatedAt: "2026-07-01T00:00:00Z",
+                totalDurationMs: null,
+                undeterminedCutCount: 0,
+            },
+        });
+
+        const text = screen.getByTestId("capture-manual-duration").textContent ?? "";
+        expect(text).toContain("合計時間 —");
+        expect(text).not.toContain("未確定");
+        expect(text).not.toContain("確定分");
+    });
+
+    it("categoryName が null なら「未分類」、creatorName が null なら「不明」", () => {
+        render(ManualMetaSummary, {
+            props: {
+                categoryName: null,
+                creatorName: null,
+                updatedAt: "2026-07-01T00:00:00Z",
+                totalDurationMs: null,
+                undeterminedCutCount: 0,
+            },
+        });
+
+        const text = screen.getByTestId("capture-manual-meta-line").textContent ?? "";
+        expect(text).toContain("未分類");
+        expect(text).toContain("不明");
+    });
+
+    it("updatedAt が null なら formatDate の fallback (-) を出す", () => {
+        render(ManualMetaSummary, {
+            props: {
+                categoryName: "組立作業",
+                creatorName: "山田太郎",
+                updatedAt: null,
+                totalDurationMs: null,
+                undeterminedCutCount: 0,
+            },
+        });
+
+        const text = screen.getByTestId("capture-manual-meta-line").textContent ?? "";
+        expect(text).toContain("更新 -");
+    });
+});
diff --git a/tests/js/lib/capture/auto-download.test.ts b/tests/js/lib/capture/auto-download.test.ts
index a6ce7be2..fbdf705c 100644
--- a/tests/js/lib/capture/auto-download.test.ts
+++ b/tests/js/lib/capture/auto-download.test.ts
@@ -53,7 +53,17 @@ function makeCut(overrides: Partial<CaptureCut> = {}): CaptureCut {
 }
 
 function makeManual(cuts: CaptureCut[] = [makeCut()]): CaptureManualDetail {
-    return { id: 5, title: "ネジ締め作業", status: "ready", cuts };
+    return {
+        id: 5,
+        title: "ネジ締め作業",
+        status: "ready",
+        category_name: null,
+        creator_name: null,
+        updated_at: null,
+        total_duration_ms: null,
+        undetermined_cut_count: 0,
+        cuts,
+    };
 }
 
 function okResponse(): Response {
diff --git a/tests/js/lib/capture/thumbnail-refresh.test.ts b/tests/js/lib/capture/thumbnail-refresh.test.ts
index 0ac8e456..845448af 100644
--- a/tests/js/lib/capture/thumbnail-refresh.test.ts
+++ b/tests/js/lib/capture/thumbnail-refresh.test.ts
@@ -34,6 +34,11 @@ function makeManual(takes: CaptureTake[]): CaptureManualDetail {
         id: 1,
         title: "手順書",
         status: "ready",
+        category_name: null,
+        creator_name: null,
+        updated_at: null,
+        total_duration_ms: null,
+        undetermined_cut_count: 0,
         cuts: [
             {
                 id: 3,
diff --git a/tests/js/pages/CaptureShow.test.ts b/tests/js/pages/CaptureShow.test.ts
index 111a5f65..8a44a86d 100644
--- a/tests/js/pages/CaptureShow.test.ts
+++ b/tests/js/pages/CaptureShow.test.ts
@@ -112,6 +112,11 @@ function makeManual(): CaptureManualDetail {
         id: 5,
         title: "ネジ締め作業",
         status: "ready",
+        category_name: "組立作業",
+        creator_name: "山田太郎",
+        updated_at: "2026-07-01T00:00:00Z",
+        total_duration_ms: 5000,
+        undetermined_cut_count: 0,
         cuts: [makeCut()],
     };
 }
@@ -137,6 +142,11 @@ function makeAdoptedManual(): CaptureManualDetail {
         id: 5,
         title: "ネジ締め作業",
         status: "ready",
+        category_name: "組立作業",
+        creator_name: "山田太郎",
+        updated_at: "2026-07-01T00:00:00Z",
+        total_duration_ms: 3000,
+        undetermined_cut_count: 0,
         cuts: [makeCut({ adopted_take_id: take.id, adopted_ready_take_id: take.id, takes: [take] })],
     };
 }
@@ -204,7 +214,7 @@ describe("Capture/Show 撮影モードの出し分け", () => {
         const cut = makeCut({ material_type: "still" });
         return {
             ...baseProps,
-            manual: { id: 5, title: "ネジ締め作業", status: "ready", cuts: [cut] },
+            manual: { ...makeManual(), cuts: [cut] },
         };
     }
 
@@ -572,6 +582,24 @@ describe("Capture/Show マニュアル詳細への復路 (T155)", () => {
     );
 });
 
+/*
+ * シナリオメタ情報の配線 (T232 / doc/05 §5.2)。
+ * 表示規則そのものは ManualMetaSummary.test.ts が固定する。ここで固定するのは
+ * props → component への配線 (キー名の対応) だけである。
+ */
+describe("Capture/Show シナリオメタ情報の配線 (T232)", () => {
+    it("manual の 5 キーがメタ情報ブロックへ渡り描画される", () => {
+        stubCameraSupported(false);
+        render(CaptureShow, { props: baseProps });
+
+        expect(screen.getByTestId("capture-manual-meta")).toBeInTheDocument();
+        const line = screen.getByTestId("capture-manual-meta-line").textContent ?? "";
+        expect(line).toContain("組立作業");
+        expect(line).toContain("山田太郎");
+        expect(screen.getByTestId("capture-manual-duration").textContent).toContain("合計時間 0:05");
+    });
+});
+
 /*
  * サムネイル反映の**ページ配線** (T183 / S10)。
  *
@@ -668,9 +696,7 @@ function installLandscapeMatchMedia(initial: boolean): LandscapeMatchMedia {
 
 function makeLandscapeManual(count: number): CaptureManualDetail {
     return {
-        id: 5,
-        title: "ネジ締め作業",
-        status: "ready",
+        ...makeManual(),
         cuts: Array.from({ length: count }, (_, index) =>
             makeCut({ id: 101 + index, scene: `工程 ${index + 1}` }),
         ),
@@ -942,6 +968,8 @@ describe("Capture/Show 横持ち全画面 (T186)", () => {
 
         expect(hasInertAncestor(screen.getByTestId("cut-row-101"))).toBe(true);
         expect(hasInertAncestor(screen.getByTestId("manual-detail-link"))).toBe(true);
+        // メタ情報ブロックも既存の見出し・リンクと同じく背後に隠れる (意図した設計)
+        expect(hasInertAncestor(screen.getByTestId("capture-manual-meta"))).toBe(true);
         // 全画面そのものは inert の外にある (操作できないと詰む)
         expect(hasInertAncestor(screen.getByTestId("exit-fullscreen-capture"))).toBe(false);
     });

## テスト結果サマリー
実装は detailed-design.md の施策 1〜6 に一致していることを確認済み (Claude 側の diff 突き合わせ)。

- `composer test`: 6002 tests / 6000 passed / 0 failed / 2 skipped / 28828 assertions (result: passed)
- `composer phpstan`: level 10, No errors
- `vendor/bin/pint --test`: passed
- `pnpm test`: 166 test files / 2231 tests passed
- `pnpm test:packages`: 10 test files / 106 tests passed
- `pnpm lint` / `pnpm typecheck` / `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages`: green (前回セッションで確認済み)

設計が強調する負例 4 点は、実装ファイルを一時的に壊して赤くなることを確認したうえで元に戻した:
1. `CaptureCutData::fromCut()` の親子整合性検査 (`take->cut_id === cut->id`) を外すと、
   `別 cut の take が setRelation() で紛れ込んでいたら例外になる` が赤くなる (期待した例外が飛ばなくなる)。
2. `CaptureCutData::fromCut()` の未ロード検査 (`relationLoaded('takes')`) を外すと、
   `takes を eager load していない cut を渡すと例外になる` が赤くなる。
3. `CaptureManualDetailData::fromManual()` の `takes` 一括 eager load を外し
   カットごとの個別 `load('takes')` に戻すと (N+1 の再現)、
   `撮影詳細のクエリ数はカット数に比例しない` が赤くなる (18 件 → 27 件で比例を検出)。
4. `ManualMetaSummary.svelte` の `durationNote` から全件未確定の分岐を外すと、
   `全件未確定なら「—（未確定 N カット）」で「確定分・」は前置しない` が赤くなる。
