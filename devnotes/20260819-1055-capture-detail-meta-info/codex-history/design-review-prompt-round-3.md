Round 2 の指摘への対応が終わりました。対応マトリクスと、修正後の詳細設計の全文を送ります。再レビューをお願いします。

必須修正 2 点はいずれも指摘のとおり対応しています。施策1は保証範囲を狭める方向 (Codex提案の代替案) を採用し、施策3は relation 直読み + fail-closed 方式 (Codex提案の推奨方式) へ変更しました。

## 対応マトリクス

# 対応マトリクス: design-review Round 2

Codex 全体判定: CHANGES_REQUESTED (施策1 / 施策3 が REQUEST_CHANGES、他は APPROVE)。
Round 1 の Critical 2 件は解消済みとの評価。残る必須修正 2 点 + Suggestion 2 点に対応する。

## [Warning] 施策1: 新設 Architecture テストが文字列部分一致で、alias/コメント/別クラスに対して fail-open

- 判断: 対応する (検出力を上げるのではなく、**保証の範囲を実態に合わせて狭める**)
- 根拠: 指摘は正しい。alias import 回避・コメント内文字列の誤検出・別クラスの誤検出・
  「同じ計算を別の書き方で写経される」ことの見逃しは、部分文字列検査の性質上避けられない。
  一方で、FQN 解決までする専用スキャナを 1 メソッドの再実装リスクのためだけに新設するのは
  過大 (思考原則 2「今必要なものだけ作る」)。Codex 自身も
  「そこまでの検出力を持たせない判断なら保証を狭めてよい」と代替案を明示している。
- 対応内容: テスト名と docblock を「唯一の所在を守る不変条件テスト」から
  「`RenderJobService::assertTotalSourceDurationWithinLimit()` の**現在のソース形**が
  委譲呼び出しを含むことを固定する source-shape pin (他表現での再実装は保証しない)」へ
  明確に格下げする。加えて負例の実装可能性の指摘 (Pest の失敗する assertion を負例に流用できない)
  に対応し、検出処理を「違反理由の list を返す純粋関数」へ分離する。

## [Warning] 施策1: 負例の検査方法が実装可能な形に落ちていない

- 判断: 対応する
- 対応内容: `sourceShapeViolations(string $body): list<string>` という純粋関数を導入し、
  実コードでは空配列・合成した旧実装文字列では非空配列を返すことをテストする形に変更する。

## [Suggestion] 施策2: 「PHP_INT_MAX 到達前に例外」の文言が実装とずれる

- 判断: 対応する
- 対応内容: 「`PHP_INT_MAX` を超える加算の前に例外」へ文言を訂正する。

## [Suggestion] 施策2: リスク節「上の最後のケース」が曖昧

- 判断: 対応する
- 対応内容: 「`[0]` のケース」と明記する。

## [Warning] 施策3: `CaptureCutData::fromCut($cut, $cut->takes)` は未ロードでも lazy load で動いてしまう

- 判断: 対応する
- 根拠: 指摘は正しい。外から `Collection` を渡す形は、呼び出し側が `$cut->takes` を
  (eager load せずに) そのまま渡しても Eloquent の magic property が黙って lazy load するため、
  「eager load を強制する」という意図が API で保証されない。
- 対応内容: `Collection` を引数で受ける設計をやめ、**`CaptureCutData::fromCut()` が
  `$cut->relationLoaded('takes')` を自分で確認する**方式へ変更する
  (`CurrentRenderArtifact::fromLoadedRenderCandidate()` と同じ「未ロードでの呼び出しは例外」作法)。
  未ロードなら `$cut->takes` へ触れる前に `Assert` で落とすため、lazy load 自体が発生しない。

## [Warning] 施策3: 任意の `Collection<int, Take>` を受けると `$cut` に属さない Take を渡せる (テナント越境の構造的リスク)

- 判断: 対応する (上と同じ変更で同時に解消する)
- 根拠: 指摘は正しい。外部から Collection を渡す形は型では親子整合性を保証できない。
- 対応内容: 上の変更 (`$cut->takes` relation を DTO 自身が読む) により、
  取得元は常に `$cut` 自身の `HasMany` relation になる。Eloquent の relation query
  (`WHERE cut_id = ?`) が親子整合性を構造的に保証するため、
  「別カット・別テナントの Take が混入する」経路がそもそも存在しなくなる
  (型ではなく取得経路そのもので保証する。Round 2 が推奨する方式をそのまま採用)。

## [Warning] 施策3: `NestedRouteIdorDefenseTest` inventory 登録が「はず」で未確定

- 判断: 対応する (確認済みの事実として設計へ確定記載する)
- 対応内容: 実際に確認した。
  - inventory entry: `tests/Support/Routing/NestedRouteDefenseInventory.php` L59
    `'capture.manuals.show' => [...$project, 'manual' => $scoped]`
    (`project` パラメータは `NestedRouteDefenseMode::TenantGuardMiddleware`、
    `manual` パラメータは `NestedRouteDefenseMode::ScopeBindings`)。
  - 404 になる経路: `routes/web.php` の `capture.manuals.show` は
    `Route::scopeBindings()->group()` の内側で宣言されており (L629-631)、
    `{project}/{manual}` の親子不整合は Eloquent の scoped binding 解決時に
    認可 (`Gate::authorize` 等) より前で 404 になる。
  - 既存 Feature テスト: `tests/Feature/Capture/CaptureManualBrowsingTest.php` の
    `'cross-org の project は index / show とも 404'` が
    `/app/projects/{otherProject->id}/manuals/{otherManual->id}` の 404 を固定している。
  - 新規 route ではないため inventory 追加・新規回帰テストは不要。
    この事実を詳細設計のリスク節へ「はず」を使わずに確定記載する。

## [Suggestion] 施策4: 「PHP 側キー集合 pin が PHP/TS の食い違いを検出する」という記述が強すぎる

- 判断: 対応する
- 対応内容: 「PHP の shape と TS fixture をそれぞれ固定し、対応関係の維持は人が担う構造であり、
  自動的な完全同期を保証するものではない」へ表現を弱める。

## [Warning] 施策6: relation 必須 API へ変更した場合に追加すべきテスト

- 判断: 対応する (一部は設計変更で不要になったことを明記)
- 対応内容:
  - 「`takes` 未ロードの `Cut` を渡すと例外になる」Unit テストを追加する。
  - 「`takes` の表示順が `sort_order → id` で維持される」テストを追加する。
  - 「異なる親の Take 混入」の負例テストは、relation 経由方式への変更により
    **構造的に発生しなくなったため不要**であることを明記する
    (Collection を外から渡す設計をやめたため、混入させる入力経路自体が無い)。

## 修正後の詳細設計書 (全文)

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

```php
// tests/Architecture/DeterminedCutDurationSourceShapePinTest.php

/*
 * RenderJobService::assertTotalSourceDurationWithinLimit() の**現在のソース形**が
 * DeterminedCutDuration::milliseconds() への委譲を含み、旧来の 3 分岐 (EffectiveMaterialType /
 * StillDisplayDuration の直接呼び出し) を含まないことを固定する source-shape pin である。
 *
 * **これは「唯一の所在」を保証する不変条件テストではない** (design-review Round 2 対応で
 * 保証範囲を明示的に狭めた)。検出しないもの: alias import 経由の呼び出し
 * (`use DeterminedCutDuration as X`) / 別クラスでの同じ 3 分岐の再実装 /
 * コメント・文字列リテラル内の記述との区別 / `duration_ms` と material type を
 * 直接比較する別表現での写経。保証するのは「このメソッド 1 つの現在のソース文字列に、
 * 既知の 2 パターンのどちらが現れているか」だけである。
 *
 * 検出処理は「違反理由の list を返す純粋関数」に分離する (Pest の失敗する assertion を
 * 直接負例へ流用できないため。design-review Round 2 [Warning] 対応)。
 *
 * @return list<string> 空なら違反なし
 */
function determinedCutDurationSourceShapeViolations(string $methodBody): array
{
    $violations = [];
    if (! str_contains($methodBody, 'DeterminedCutDuration::milliseconds(')) {
        $violations[] = 'DeterminedCutDuration::milliseconds( への委譲が見つからない';
    }
    if (str_contains($methodBody, 'EffectiveMaterialType::of(')) {
        $violations[] = '旧来の EffectiveMaterialType::of( 直接呼び出しが残っている';
    }
    if (str_contains($methodBody, 'StillDisplayDuration::secondsFor(')) {
        $violations[] = '旧来の StillDisplayDuration::secondsFor( 直接呼び出しが残っている';
    }

    return $violations;
}

test('RenderJobService の尺上限ゲートは DeterminedCutDuration への委譲を含む', function (): void {
    $body = sourceOf(RenderJobService::class, 'assertTotalSourceDurationWithinLimit');

    expect(determinedCutDurationSourceShapeViolations($body))->toBe([]);
});

// 負例 (tests/Unit/Architecture/ 配下。純粋関数へ合成文字列を直接与えるため、
// Pest の失敗する assertion を負例に流用する問題が起きない):
test('検出器は旧式の 3 分岐を違反として返す (自己テスト)', function (): void {
    $legacyBody = <<<'PHP'
        $totalMs += EffectiveMaterialType::of($cut, $take) === MaterialType::Still
            ? StillDisplayDuration::secondsFor($cut) * 1000
            : ($take->duration_ms ?? $defaultMs);
        PHP;

    expect(determinedCutDurationSourceShapeViolations($legacyBody))->not->toBe([]);
});
```

`sourceOf()` はメソッド定義行の範囲をファイルから切り出すだけの小さいヘルパで
(既存の `tests/Architecture/ControllerAuthorizationGateTest.php` が同種の
`ReflectionMethod::getStartLine()`/`getEndLine()` パターンを既に使っている先例に倣う)、
クラス名・名前解決は伴わない (走査器共通規約 (a) の対象外。(e) の語彙分割も対象外 —
本テストは語彙一致の**否定**ではなく特定 1 メソッドのソース文字列の pin であるため)。
(b)(c)(d) は満たす: メソッドが見つからなければ例外で落ちる (b) /
上記の自己テスト (合成した旧実装文字列) で検出力を裏取りする (c) /
`determinedCutDurationSourceShapeViolations()` が返す違反 list は必ず判定に使う (d)。

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
> 「未ロードでの呼び出しは例外にする」作法)。取得元は常に `$cut` 自身の `HasMany` relation
> (`WHERE cut_id = ?`) になるため、親子整合性は取得経路そのもので構造的に保証され、
> 型による保証を必要としない。

### 変更箇所

- `app/DataTransferObjects/Capture/CaptureManualDetailData.php` (全体)
- `app/DataTransferObjects/Capture/CaptureCutData.php` (`fromCut()` のシグネチャ)
- `app/Http/Controllers/Capture/CaptureManualController.php` (L117-142 `show`)
- `app/Http/Controllers/Capture/CaptureTakeController.php` (`adopt()`。`fromCut()` の唯一の他の呼び出し元)

### 波及変更

- TypeScript 型定義: `resources/js/types/capture.ts` の `CaptureManualDetail` (施策 4)
- API Resource/DTO: 本施策そのもの (`CaptureCutData::fromCut()` のシグネチャ変更を含む)
- テストファイル: `tests/Feature/Capture/CaptureManualBrowsingTest.php` (キー集合契約の追加) /
  `tests/Feature/Capture/CaptureManualDetailQueryCountTest.php` (新規。施策 6) /
  `tests/Feature/Capture/CaptureTakeManagementTest.php` (`adopt()` 応答の回帰。シグネチャ変更の影響確認)
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
   「採用済みかつ ready のテイク」を**1 度だけ解決**して (a) 署名 URL / ACK と
   (b) 確定尺の 2 つに使う。**判定式の実装は 1 か所** (`AdoptedReadyTakeCoverage`) に保つ。
   そのために `cutWithAdoptedUrls` を「解決済みテイクを引数で受ける」形へ割り、
   解決 (`AdoptedReadyTakeCoverage::readyTakeId()` + `Assert`) を呼び出し元の 1 か所へ寄せる。
2. カテゴリ名 / 作成者名 / 更新日は**コンストラクタでスカラーとして受け取る**
   (`toArray()` の中で relation を触ると、eager load されていないときに lazy load が走る)。
3. `VideoManual $manual` は id / title / status のために保持する (既存の形を変えない)。
4. **`CaptureCutData::fromCut()` は `takes` relation を自分でクエリしないが、
   自分の relation として読む** (design-review Round 1/2 対応)。引数で `Collection` を
   受け取る形はやめ、`$cut->relationLoaded('takes')` を確認してから `$cut->takes` を読む。
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
**未ロードなら例外にする** (design-review Round 2 対応):

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
     * relation 経由なので親子整合性 (`take.cut_id === cut.id`) は Eloquent の
     * `WHERE cut_id = ?` クエリが構造的に保証しており、別カット・別テナントの Take が
     * 混入する経路がそもそも存在しない。
     */
    public static function fromCut(Cut $cut, ?string $adoptedPlaybackUrl = null, ?string $adoptedAckToken = null): self
    {
        Assert::true(
            $cut->relationLoaded('takes'),
            'takes を eager load してから呼ぶこと (呼び出し側で with([\'adoptedTake\', \'takes\']) '
            .'または load(\'takes\') を行う)',
        );

        $adoptedTakeId = $cut->adopted_take_id;
        $sorted = $cut->takes
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
- [ ] **新規 Unit テスト (design-review Round 2 [Warning] 対応)**:
      `tests/Unit/DataTransferObjects/Capture/CaptureCutDataTest.php`
  - `takes` を eager load していない `Cut` を `CaptureCutData::fromCut()` へ渡すと例外になる
    (`relationLoaded('takes') === false` を作って確認する)
  - `takes` の表示順が `sort_order → id` で維持される
    (eager load した `Collection` の投入順をわざと逆順にしても、出力は `sort_order`/`id` 順になる)
  - **「異なる親の Take 混入」の負例テストは作らない**: `Collection` を外から渡す設計をやめ
    `$cut->takes` relation を直接読む方式にしたため、混入させる入力経路自体が無い
    (design-review Round 2 [Warning] 対応。型ではなく取得経路そのもので保証している)
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
- **既存 route (新規 route ではない) の nested route 防御は確認済み**
  (design-review Round 2 [Warning] 対応。「はず」ではなく実際に確認した事実):
  - inventory entry: `tests/Support/Routing/NestedRouteDefenseInventory.php` L59
    `'capture.manuals.show' => [...$project, 'manual' => $scoped]`
    (`project` は `NestedRouteDefenseMode::TenantGuardMiddleware`、
    `manual` は `NestedRouteDefenseMode::ScopeBindings`)。
  - 404 になる経路: `routes/web.php` の `capture.manuals.show` は
    `Route::scopeBindings()->group()` の内側で宣言されており、`{project}/{manual}` の
    親子不整合は scoped binding 解決時に認可より前で 404 になる。
  - 既存回帰: `tests/Feature/Capture/CaptureManualBrowsingTest.php` の
    `'cross-org の project は index / show とも 404'` が
    `/app/projects/{otherProject->id}/manuals/{otherManual->id}` の 404 を固定している。
  - 本施策は既存 route の DTO/Controller 内部変更のみで route 定義に触れないため、
    inventory 追加・新規 404 回帰テストは不要。

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
