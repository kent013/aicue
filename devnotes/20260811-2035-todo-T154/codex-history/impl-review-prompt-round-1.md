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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `->withMetadata($context->toMetadata())` で帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) は
   `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
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

# あなたの役割: コードレビュアー

Laravel 12 + Svelte 5 (Inertia) アプリ **aicue** の改善実装をレビューする。
対象は TODO **T154「完成動画をアプリ内で観られるようにする」** の実装差分である。

## レビュー観点

1. **設計との一致性**: 詳細設計書 (Codex 合議 APPROVED 済み) の施策 1〜7 が、書かれたとおりに実装されているか。
   設計が明示的に禁じたこと (route を増やさない / 認可を緩めない / preview 側の 404 条件と ability を変えない /
   `isLatestSucceededPreview` を残さない / 網羅 match で `else` を作らない / `canManage` を UI 条件に積まない /
   完成動画に黒背景の注記を出さない) に違反していないか。
2. **正確性**: 認可・テナント境界 (層 2 の 404 が層 3 の 403 より前) の評価順序、世代選択の境界条件、
   null 安全、Inertia props と endpoint の条件が 1 対 1 であること。
3. **PHPStan level 10 適合性**: 型の widen / ignore / baseline を使っていないか。
4. **DTO / JsonResource パターン**: `response()->json()` 直書きが無いか。props の shape。
5. **テスト網羅性**: 各施策にテストがあるか。fail-first になっているか。deny-by-default 目録
   (Architecture gate) が**空振りしない**ようになっているか (負のコントロール / exact-fit / 前提の機械検査)。
   mutation で赤くなることが示されているか。
6. **セキュリティ**: 存在オラクル、署名 URL の露出、cross-org / cross-manual、
   props に成果物パスや署名 URL が漏れていないか。
7. **DESIGN.md 準拠**: `/DESIGN.md` が design token の canonical source。color / radius / typography は
   token 経由で参照し hex 直書き (`#RRGGBB`) を増やさない。token 値を変更する diff は
   `resources/css/tokens.css` と同一 diff 内で同期しているか。
8. **Atomic Design 準拠**: `resources/js/components/` は `atoms/molecules/organisms/features/templates` の
   責務分離に従う。階層を逆流していないか。アイコンは Lucide を使い SVG 直書きを増やさないか。

## 出力形式

- **ファイルごとに判定**を書く。
- 指摘は **[Critical] / [Warning] / [Suggestion]** で分類する。
  - Critical = 修正なしにマージしてはならないもの (バグ・セキュリティ・設計違反・偽グリーン)
  - Warning = 検討が必要なもの
  - Suggestion = 任意
- 最後に **全体判定: APPROVED / CHANGES_REQUESTED** を明記する。
- **過剰な提案をしない**。「あったら便利」は禁止事項 (オーバーエンジニアリング) に当たる。
- 既に設計書で**議論して棄却された案 (trip-wire による同値監視 / 本文全体への `https://` 非出現検査 /
  manual 単位の再生 URL)** を蒸し返さない。

---

# user 部

## 詳細設計書 (Codex 合議 APPROVED 済み)

# 詳細設計: render-playback (完成動画をアプリ内で観られるようにする)

## 使命・制約(絶対遵守)

### アプリの使命(North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、
そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

**本設計の位置づけ**: 制作フロー最終段の「完成物の受け取り」が **DL 1 本**しかなく、
アプリ内で観る手段が無い。**編集ゼロ**を掲げながら最後だけ外部プレイヤーを要求している状態を解消する。

### 禁止事項(AGENTS.md)

1. テストなしの実装完了報告(不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用

**本設計で特に効くもの**: 1(Architecture gate まで含めて完了)/ 4(Inertia props と redirect のみ。
JSON 直書きは無い)/ 8(完成動画の再生ボタン相当を disabled にする分岐は作らない。
そもそも**出せない状態では出さない**)。

### コーディングルール

- **PHPStan level 10** 必須(`composer phpstan`)
- **Pest**(`composer test`)。**RefreshDatabase はグローバル適用**(`tests/Pest.php`)、`--parallel` 実行。
  個別 `DatabaseTransactions` 禁止
- **テストデータは必ず Factory**(`Model::create()` 手組み禁止)
- **DTO + JsonResource** パターン
- アーリーリターン推奨 / `declare(strict_types=1)` + 日本語コメント
- `composer fix`(Pint)/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- フロント: DESIGN.md の token 経由のみ(hex 直書き禁止)、Atomic Design の層
  (`atoms → molecules → organisms → features/{domain} → templates → pages` の単方向 import)、
  アイコンは `@lucide/svelte`

## 概念設計リファレンス

`devnotes/20260811-2005-render-playback/conceptual-design.md`(Codex 概念レビュー **APPROVED / Round 4**)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 成果物選択式の集約 (`CurrentRenderArtifact`) | `app/Services/Manual/CurrentRenderArtifact.php`(新規) | High |
| 2 | playback を kind=render へ拡張 | `app/Http/Controllers/Projects/ManualRenderController.php` | High |
| 3 | download を集約式へ載せ替え | `app/Http/Controllers/Projects/ManualDownloadController.php` | High |
| 4 | props に `finishedJob` を追加 | `app/Http/Controllers/Projects/VideoManualController.php` / `resources/js/types/manual.ts` | High |
| 5 | 完成動画プレイヤー(UI) | `resources/js/components/features/manual/RenderPanel.svelte` / `resources/js/pages/Manuals/Show.svelte` | High |
| 6 | 不変条件の機械化(deny-by-default 目録) | `tests/Architecture/CurrentRenderArtifactInventoryTest.php`(新規) / `app/Support/Security/RenderArtifactSelectionInventory.php`(新規) / `app/Enums/Security/RenderArtifactSelectionKind.php`(新規) | High |
| 7 | ドキュメント | `docs/architecture.md` / `AGENTS.md`(ドメイン固有規約 13) | Medium |

**route は 1 本も増やさない**。DTO は既存 `RenderJobData` を再利用する(新 shape を作らない)。

---

## 施策 1: 成果物選択式の集約 (`CurrentRenderArtifact`)

### 変更箇所

- 新規: `app/Services/Manual/CurrentRenderArtifact.php`

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし(`RenderJobData` は不変)
- テストファイル: 新規 `tests/Unit/Manual/CurrentRenderArtifactTest.php`

### 現行コード(同じ意味の式が 3 箇所に分散している)

```php
// ManualRenderController::isLatestSucceededPreview() — 「より新しい succeeded が無い」
return ! $manual->renderJobs()
    ->where('kind', RenderKind::Preview->value)
    ->where('status', JobStatus::Succeeded->value)
    ->where('id', '>', $renderJob->id)
    ->exists();

// VideoManualController::show() — 「output_path 非 NULL の succeeded のうち最新」
$playbackJob = $manual->renderJobs()
    ->where('kind', RenderKind::Preview->value)
    ->where('status', JobStatus::Succeeded->value)
    ->whereNotNull('output_path')
    ->latest('id')
    ->first();

// ManualDownloadController::show() — 同上 (kind=render)
$job = $manual->renderJobs()
    ->where('kind', RenderKind::Render->value)
    ->where('status', JobStatus::Succeeded->value)
    ->whereNotNull('output_path')
    ->latest('id')
    ->first();
```

保持ポリシーの実体はこう定義されている(`RenderJobService::newerSucceededExists()`。
`DeleteRenderOutputsJob` はこれが true の行の S3 実体を削除し `output_path` を CAS で NULL 化する):

```php
return RenderJob::query()
    ->where('video_manual_id', $job->video_manual_id)
    ->where('kind', $job->kind->value)
    ->where('status', JobStatus::Succeeded->value)
    ->where('id', '>', $job->id)
    ->exists();
```

つまり**「同 kind の最新 succeeded 以外は消える」**。`whereNotNull('output_path')` を
先に効かせる 2 箇所の式は、最新 succeeded の `output_path` が NULL のとき
**削除済みの旧世代を選ぶ**(署名 URL を出しても実体が無い / route 側は 404 にする)。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderKind;
use App\Models\RenderJob;
use App\Models\VideoManual;

/**
 * 「いま受け取れるレンダ成果物はどれか」の**唯一の選択式**(playback / download / 詳細画面 props)。
 *
 * 定義は保持ポリシー (RenderJobService::newerSucceededExists / DeleteRenderOutputsJob) と
 * **同じ世代定義**である: 実体が残るのは「同 manual・同 kind の最新 succeeded」だけなので、
 * 最新 succeeded の output_path が NULL(= 生成に失敗した / 掃除された)なら
 * **旧世代へフォールバックしない**(削除済みオブジェクトの署名 URL を出さないため)。
 *
 * **持たない責務**: published 判定(完成動画の公開状態)と ability 判定は呼び出し側にある。
 * ここは「どの行か」だけを答える(名前が示す役割を超えない)。読み取り専用。
 */
final class CurrentRenderArtifact
{
    /** 同 manual・同 kind で現在受け取れる succeeded job(無ければ null) */
    public static function currentSucceeded(VideoManual $manual, RenderKind $kind): ?RenderJob
    {
        $job = $manual->renderJobs()
            ->where('kind', $kind->value)
            ->where('status', JobStatus::Succeeded->value)
            ->latest('id')
            ->first();

        if ($job === null || $job->output_path === null) {
            return null; // 旧世代へフォールバックしない(実体が無い可能性がある)
        }

        return $job;
    }
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている(`?RenderJob`)
- [x] null 安全(`$job === null || $job->output_path === null` の早期 return。`Assert` 不要)
- [x] DTO を返す必要は無い(Eloquent モデルを返す内部 read helper。HTTP 層で `RenderJobData` に載る)
- [x] Generics: `$manual->renderJobs()` は `HasMany<RenderJob, VideoManual>` で `first()` は `RenderJob|null`

### テスト計画

`tests/Unit/Manual/CurrentRenderArtifactTest.php`(新規。RefreshDatabase はグローバル適用、Factory 必須)

- [ ] `同 kind の最新 succeeded を返す(kind をまたがない)`
- [ ] `別 manual の最新 succeeded に引っ張られない(選択の境界は manual × kind)`
- [ ] `最新 succeeded の output_path が NULL なら null(旧世代へフォールバックしない)`
- [ ] `succeeded が 1 件も無ければ null(queued / running / failed は選ばない)`
- [ ] `返した行は保持ポリシーの削除対象ではない(newerSucceededExists が false)`
      — 選択式と保持ポリシーの世代定義が一致することの固定

### リスク

- **挙動変化(意図的)**: 「最新 succeeded の `output_path` が NULL」のとき、これまで
  download が旧世代へフォールバックして 302 を返していた経路が 404 になる。
  旧世代の実体は保持ポリシーで削除済みのため、**302 の先は壊れた URL**であり後退ではない。
  ただし**実測データを持っていない**(この状態の行が本番にあるかは未確認)ため、
  「不具合を直した」とは書かず「定義を保持ポリシーに揃えた」と記録する。

---

## 施策 2: playback を kind=render へ拡張

### 変更箇所

- `app/Http/Controllers/Projects/ManualRenderController.php` L91-124(`playback()` と `isLatestSucceededPreview()`)

### 波及変更

- TypeScript 型定義: なし(URL 形は不変)
- API Resource/DTO: なし
- テストファイル: `tests/Feature/Manual/RenderPollingAndArtifactAccessTest.php`(既存 404 マトリクスの維持確認)、
  新規 `tests/Feature/Manual/FinishedVideoPlaybackTest.php`、
  新規 `tests/Feature/Manual/RenderPlaybackAbilityMappingTest.php` +
  `tests/Support/Policies/DivergentVideoManualPolicy.php`
- route: **変更なし**(`projects.manuals.render-jobs.playback` のまま。
  `Tests\Support\Routing\NestedRouteDefenseInventory` の登録も不変)

### 現行コード

```php
    public function playback(Request $request, Project $project, VideoManual $manual, RenderJob $renderJob, RenderObjectStorage $storage): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        $this->resolveOrganizationProject($organization, $project);
        if ($renderJob->video_manual_id !== $manual->id) {
            abort(404);
        }
        Gate::authorize('render', $manual); // preview は編集者専用機能

        if ($renderJob->kind !== RenderKind::Preview
            || $renderJob->status !== JobStatus::Succeeded
            || $renderJob->output_path === null
            || ! $this->isLatestSucceededPreview($manual, $renderJob)) {
            abort(404);
        }

        return redirect()->away($storage->temporaryPlaybackUrl($renderJob->output_path));
    }

    /** 当該 job が同 manual の preview の最新 succeeded job か */
    private function isLatestSucceededPreview(VideoManual $manual, RenderJob $renderJob): bool
    { /* 上記 */ }
```

### 変更後コード

```php
    /**
     * 成果物再生 (302 → S3 署名 URL)。preview と完成動画の**両方**を扱う。
     *
     * 層は 3 段で、**すべて認可より前に 404**(AGENTS.md セキュリティ不変条件 2/10):
     *   1. {project} ∈ current org … project.in-current-org middleware + inline guard
     *   2. {manual}  ∈ {project}   … routes 側 Route::scopeBindings()
     *   3. {renderJob} ∈ {manual}  … scopeBindings + 下の inline 再検査(二重防御)
     * その後に **成果物の性質に合う ability** を評価する:
     *   kind=preview → render ability / kind=render → download ability
     *   (現行はどちらも ProjectPolicy::update に落ちるため**可否は完全に同値**。
     *    UI 側の canManage が自動追従するという意味ではない = 誇張しない)
     * 完成動画だけ published を要求するのは download と同一条件にするため(順序も download と同じ
     * = authorize の後)。最後に「いま受け取れる成果物」と同一行かを照合する
     * (旧世代 job id の直叩き・未完了・実体削除済みはここで 404)。
     */
    public function playback(Request $request, Project $project, VideoManual $manual, RenderJob $renderJob, RenderObjectStorage $storage): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        if ($renderJob->video_manual_id !== $manual->id) {
            abort(404);
        }
        // 2 値 enum の網羅 match(到達不能な fallback を作らない)
        Gate::authorize(match ($renderJob->kind) {
            RenderKind::Preview => 'render',
            RenderKind::Render => 'download',
        }, $manual);

        // 完成動画は「公開中のマニュアルの現行版」だけ(download と同条件・同順序)
        if ($renderJob->kind === RenderKind::Render && $manual->status !== VideoManualStatus::Published) {
            abort(404);
        }

        $current = CurrentRenderArtifact::currentSucceeded($manual, $renderJob->kind);
        if ($current === null || $current->id !== $renderJob->id) {
            abort(404); // 未完了 / 旧世代 / 実体削除済み
        }
        $path = $current->output_path;
        if ($path === null) {
            abort(404); // currentSucceeded の契約上到達しないが、型を締めるため明示する
        }

        return redirect()->away($storage->temporaryPlaybackUrl($path));
    }
```

- `isLatestSucceededPreview()` は**削除する**(後方互換の並走を残さない = 思考原則 3)。
- `use App\Enums\Manual\JobStatus;` は他で使わなくなるため削除、
  `use App\Enums\Manual\VideoManualStatus;` と `use App\Services\Manual\CurrentRenderArtifact;` を追加。
  `JobStatus` は `store()/preview()/show()` では使っていないことを確認済み(現行の参照は L106-107 と L121 のみ)。

### PHPStan 適合チェック

- [x] `match` は `RenderKind` の 2 case を網羅(未処理 case があれば level 10 が落とす)
- [x] `$current->output_path` は `string|null` のためローカル変数へ束ねて null 検査してから使う
- [x] `Gate::authorize(string $ability, mixed $arguments)` の第 1 引数は string(match の戻り値)

### テスト計画

**A. `tests/Feature/Manual/FinishedVideoPlaybackTest.php`(新規)**

- [ ] `playback: published + 最新 succeeded render は 302(S3 署名 URL へ redirect)`
- [ ] `playback: published でない manual の完成動画は 404(シナリオ編集で ready へ戻った旧完成動画も 404)`
- [ ] `playback: 旧世代 render は 404(実体削除済みの世代へ署名 URL を出さない)`
- [ ] `playback: 最新 succeeded render の output_path が NULL なら旧世代へフォールバックせず 404`
- [ ] `playback: queued / running / failed の render は 404`
- [ ] `playback: 撮影者は 403(download ability = 編集者専用。層 2 の 404 より後に評価される)`
- [ ] `playback: cross-org / cross-manual の render job は 404(存在オラクル封じ)`
- [ ] `playback: kind=preview の 302 条件と ability は本変更で変わらない(回帰の明示固定)`

**B. `tests/Feature/Manual/RenderPollingAndArtifactAccessTest.php`(既存の更新)**

- [ ] 既存テスト `playback の 404 マトリクス: kind=render / 未完了 / output_path NULL / 旧世代` を
      **`kind=render` の行だけ差し替える**(「kind=render の succeeded は 404」→
      「**published でない** manual の kind=render succeeded は 404」)。
      他の行(未完了 preview / output_path NULL / 旧世代 preview)は**変更しない**
      = preview 側の契約が動いていないことの回帰になる
- [ ] 既存テスト `download / playback: cross-org は 404` は不変(通ることを確認)

**C. `tests/Feature/Manual/RenderPlaybackAbilityMappingTest.php`(新規。写像そのものを観測する)**

Round 1 [Warning] を受けて、trip-wire(同値の監視)ではなく**写像を直接固定する**形に変える。
現行 policy は `render` と `download` が同値で観測差が出ないため、
**テスト専用 policy を差し込んで差を作る**:

```php
// tests/Support/Policies/DivergentVideoManualPolicy.php (テスト専用。app/ には置かない)
final class DivergentVideoManualPolicy
{
    /** テストが立てる分岐スイッチ (既定は両方許可 = 現行 policy と同挙動) */
    public static bool $allowRender = true;

    public static bool $allowDownload = true;

    public function view(User $user, VideoManual $manual): bool { return true; }

    public function render(User $user, VideoManual $manual): bool { return self::$allowRender; }

    public function download(User $user, VideoManual $manual): bool { return self::$allowDownload; }
}
```

テスト側は `Gate::policy(VideoManual::class, DivergentVideoManualPolicy::class)` で差し替える。
**残留を実行順に依存させない**(Round 2 [Suggestion])ため、本ファイルの `afterEach` で
① 静的スイッチを既定(両方 true)へ戻し、② `Gate::policy(VideoManual::class, VideoManualPolicy::class)` で
本来の policy を明示的に再登録する。Laravel はテストごとに Application を作り直すが、
**それに依存しているとは書かない**(明示的に戻す方が読み手にも順序非依存が伝わる)。
`RefreshDatabase` はグローバル適用のまま、`--parallel` でもプロセス内の静的値を
テストごとに初期化するので競合しない。

- [ ] `写像: download を拒否する policy では kind=render の playback が 403 になる`
- [ ] `写像: download を拒否しても kind=preview の playback は 302 のまま(render ability で通る)`
- [ ] `写像: render を拒否する policy では kind=preview の playback が 403 になる`
- [ ] `写像: render を拒否しても kind=render の playback は 302 のまま(download ability で通る)`
- [ ] `写像: 認可 403 はテナント境界 404 より後(他組織からは policy 差替えに関係なく 404)`

これで **M7(写像を `'render'` 固定へ変異)は赤になる**(mutation 表を更新済み)。

### リスク

- **署名 URL の disposition が inline になる経路が増える**(`temporaryPlaybackUrl` は
  `ResponseContentDisposition` を付けない)。認可条件は download と同一なので**到達できる主体は増えない**が、
  同じオブジェクトをブラウザで開ける経路が 1 本増えるのは事実。Feature テストで条件を固定する。
- `match` に将来 `RenderKind` の case が増えたとき、ability 写像の追加を忘れると **level 10 が落ちる**
  (fail-fast 側に倒れる = 意図どおり)。

---

## 施策 3: download を集約式へ載せ替え

### 変更箇所

- `app/Http/Controllers/Projects/ManualDownloadController.php` L36-54

### 波及変更

- TypeScript 型定義 / DTO: なし
- テストファイル: `tests/Feature/Manual/RenderPollingAndArtifactAccessTest.php`(既存 download テスト群は不変)
  + 新規ケース(下記)

### 現行コード

```php
        if ($manual->status !== VideoManualStatus::Published) {
            abort(404);
        }
        /** @var RenderJob|null $job */
        $job = $manual->renderJobs()
            ->where('kind', RenderKind::Render->value)
            ->where('status', JobStatus::Succeeded->value)
            ->whereNotNull('output_path')
            ->latest('id')
            ->first();
        if ($job === null || $job->output_path === null) {
            abort(404);
        }
```

### 変更後コード

```php
        if ($manual->status !== VideoManualStatus::Published) {
            abort(404);
        }
        // 「いま受け取れる完成動画」の選択は CurrentRenderArtifact ただ 1 箇所(playback と同一式)
        $job = CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render);
        if ($job === null || $job->output_path === null) {
            abort(404); // 完成物が無い / 実体が消えている
        }
```

- `use App\Enums\Manual\JobStatus;` を削除(他で使っていないことを確認済み)、
  `use App\Services\Manual\CurrentRenderArtifact;` を追加。`RenderJob` の use は
  `/** @var */` が不要になるため削除する。

### PHPStan 適合チェック

- [x] `currentSucceeded()` の戻り型が `?RenderJob` なので `@var` 注釈が不要になる(型の後退なし)
- [x] `$job->output_path` は null 検査後に `temporaryDownloadUrl(string, string)` へ渡る

### テスト計画

`tests/Feature/Manual/FinishedVideoPlaybackTest.php` に同居させる(同じ選択式の話であるため):

- [ ] `download: 最新 succeeded render の output_path が NULL なら旧世代へフォールバックせず 404`
- [ ] `download: published + 最新 succeeded render は 302(既存契約の維持)` — 既存テストで担保済みのため
      **新規には書かない**(重複を作らない。既存 `RenderPollingAndArtifactAccessTest` が緑であることで確認)

### リスク

- 施策 1 のリスク欄と同じ(挙動変化は「NULL 最新世代のときフォールバックしない」1 点のみ)。

---

## 施策 4: props に `finishedJob` を追加

### 変更箇所

- `app/Http/Controllers/Projects/VideoManualController.php` L104-160(`show()`)
- `resources/js/types/manual.ts`(`RenderProps`)

### 波及変更

- **TypeScript 型定義**: `RenderProps` に `finishedJob: RenderJobProps | null` を追加(必須キー)
- **Inertia Props インターフェース**: `Manuals/Show.svelte` の `render: RenderProps` 経由で
  `RenderPanel.svelte` の `Props` に `finishedJob` を追加(施策 5)
- **API Resource / DTO**: なし(`RenderJobData` を再利用)
- **テストファイル**: `tests/Feature/Manual/FinishedVideoPlaybackTest.php`(props 群)、
  `tests/js/pages/ManualsShow.test.ts`、`tests/js/components/features/manual/RenderPanel.test.ts`

### 現行コード

```php
        $playbackJob = $manual->renderJobs()
            ->where('kind', RenderKind::Preview->value)
            ->where('status', JobStatus::Succeeded->value)
            ->whereNotNull('output_path')
            ->latest('id')
            ->first();
        // ...
            'render' => [
                'job' => $renderJob === null ? null : RenderJobData::fromJob($renderJob, $manual)->toArray(),
                'previewJob' => $previewJob === null ? null : RenderJobData::fromJob($previewJob, $manual)->toArray(),
                'playbackJob' => $playbackJob === null ? null : RenderJobData::fromJob($playbackJob, $manual)->toArray(),
                'coverage' => AdoptedReadyTakeCoverage::for($manual)->toProps(),
            ],
```

### 変更後コード

```php
        // 再生できるプレビュー(選択式は CurrentRenderArtifact に集約。route 側と同一の行を指す)
        $playbackJob = CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Preview);
        // 受け取れる完成動画。**endpoint が 302 を返す条件と 1 対 1**にする:
        // published + download ability + 現行世代。UI の canManage は表示制御であって
        // 秘匿境界ではないため、ここで ability を評価する(条件を UI 側に持たせない)。
        $finishedJob = $manual->status === VideoManualStatus::Published && $user->can('download', $manual)
            ? CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render)
            : null;
        // ...
            'render' => [
                'job' => ...,
                'previewJob' => ...,
                'playbackJob' => $playbackJob === null ? null : RenderJobData::fromJob($playbackJob, $manual)->toArray(),
                // 完成動画 (再生 + DL の唯一の出し分け根拠)。null = 出さない
                'finishedJob' => $finishedJob === null ? null : RenderJobData::fromJob($finishedJob, $manual)->toArray(),
                'coverage' => AdoptedReadyTakeCoverage::for($manual)->toProps(),
            ],
```

- `use App\Enums\Manual\JobStatus;` を削除、`use App\Enums\Manual\VideoManualStatus;` と
  `use App\Services\Manual\CurrentRenderArtifact;` を追加。`$user` は L104-105 で
  `Assert::isInstanceOf($user, User::class)` 済み(既存)。

TypeScript 側:

```ts
export interface RenderProps {
    job: RenderJobProps | null;
    previewJob: RenderJobProps | null;
    playbackJob: RenderJobProps | null;
    /**
     * 受け取れる完成動画の job(無ければ null)。
     * サーバが「published + download ability + 現行世代」を判定した結果そのものであり、
     * **UI 側で条件を再判定しない**(判断は props で 1 回)。
     */
    finishedJob: RenderJobProps | null;
    coverage: TakeCoverageProps;
}
```

### PHPStan 適合チェック

- [x] `$user->can('download', $manual)` は `bool`(`User` は `Authorizable`)
- [x] 三項の両枝が `RenderJob|null`
- [x] props 配列は既存 `toArray()` の shape をそのまま使う(新 shape なし)

### テスト計画

`tests/Feature/Manual/FinishedVideoPlaybackTest.php`(Inertia assert):

- [ ] `props: published + download 権限保持者には finishedJob が最新 succeeded render を指す`
- [ ] `props: ready へ戻った manual では finishedJob=null(押すと 404 になる導線を出さない)`
- [ ] `props: 詳細を閲覧できるが download 権限のない撮影者には finishedJob=null`
- [ ] `props: finishedJob のキー集合は RenderJobData::toArray() と exact 一致(output_path も URL 系キーも無い)`
      — 検査対象は **`render.finishedJob` のキー集合そのもの**(`id` / `kind` / `status` / `step` /
      `progress` / `error` / `error_code` / `manual_status` / `placeholder_cut_count` の 9 キー丁度)。
      Round 2 [Suggestion] に従い、**応答本文全体に対する `https://` の非出現検査は書かない**
      (Inertia の ziggy / asset / 無関係な props を将来拾って偽陽性になるため)。
      本文検査を足すなら対象を成果物キー(`output_path`)と署名先ホスト(`signed.example`)に限定する
- [ ] `props: 最新 succeeded render の output_path が NULL なら finishedJob=null(route と同じ判断)`

### リスク

- props に 1 キー増えるため、`RenderProps` を構築する既存テスト
  (`tests/js/pages/ManualsShow.test.ts` / `RenderPanel.test.ts`)が型エラーになる。
  **これは意図した波及**であり、両方を同 PR で更新する(旧キーの後方互換は残さない)。

---

## 施策 5: 完成動画プレイヤー(UI)

### 変更箇所

- `resources/js/components/features/manual/RenderPanel.svelte`(Props / published 枝 / 注記方針)
- `resources/js/pages/Manuals/Show.svelte` L131-140(`finishedJob={render.finishedJob}` の受け渡し)

### 波及変更

- TypeScript 型定義: 施策 4 で追加済みの `RenderProps.finishedJob`
- Atomic Design: 変更は `components/features/manual/` 配下のみ(層をまたぐ新規 component を作らない)。
  import 方向は `features → atoms`(既存 `Card` / `Button` / `Alert`)のままで単方向を守る
- DESIGN.md: 追加するのは既存 utility class の組み合わせのみ
  (`w-full` / `rounded-md` / `bg-neutral` / `text-caption` / `text-text-secondary`)。
  **hex 直書き・新規 token は増やさない**。アイコンは既存の `@lucide/svelte`(`Download` / `Play`)のみ

### 現行コード

```svelte
        {#if status === "published" && canManage}
            <div class="mt-4">
                <Button variant="secondary" href={`/projects/${projectId}/manuals/${manualId}/download`} testId="download-button">
                    <Download class="size-4" />
                    完成動画をダウンロード
                </Button>
            </div>
        {/if}
```

### 変更後コード

```svelte
        <!-- 完成動画 (再生 + DL)。表示の可否はサーバが決めた finishedJob **だけ**で判断する
             (published / ability を UI 側で再判定しない = 判断を 2 箇所に持たない)。
             canManage を積まないのは、finishedJob が既に download ability の評価結果を
             運んでいるためである (Round 1 [Warning] 施策 5。canManage = update ability を
             積むと、policy が分岐した日にサーバが渡した成果物を UI が隠す)。
             書き出し中に出ないことは、この枝が {#if rendering}…{:else} の else 側にある
             ことで構造的に保証される。 -->
        {#if finishedJob !== null}
            <div class="mt-4 flex flex-col gap-3" data-testid="final-video-block">
                <!-- svelte-ignore a11y_media_has_caption (完成動画の字幕は焼き込み済み) -->
                <!-- preload="none": 詳細画面を開くたびに署名 URL 発行と本体取得が走るのを避ける
                     (完成動画は尺が長い)。src に job id を含むため、再レンダで URL が変わり
                     古い世代が再生され続けることが起きない。 -->
                <video
                    controls
                    preload="none"
                    class="w-full rounded-md bg-neutral"
                    src={`/projects/${projectId}/manuals/${manualId}/render-jobs/${finishedJob.id}/playback`}
                    aria-label="完成動画"
                    data-testid="final-video"
                ></video>
                <div>
                    <Button
                        variant="secondary"
                        href={`/projects/${projectId}/manuals/${manualId}/download`}
                        testId="download-button"
                    >
                        <Download class="size-4" />
                        完成動画をダウンロード
                    </Button>
                </div>
            </div>
        {/if}
```

- `Props` に `finishedJob: RenderJobProps | null` を追加する。
  **local state にはしない**: 完成動画は render 成功時の `router.reload()` で props ごと入れ替わる
  (ポーリングの render 分岐は既に `stop(); router.reload();` を行う)。
  poll 応答から `finishedJob` を組み立てる経路は**作らない**(ポーリング応答は
  「published + ability + 現行世代」を判定していないため。判断はサーバの props で 1 回)。
- **黒背景の注記は完成動画に出さない**。`placeholder_cut_count` の値契約では succeeded render は
  `0`(本列追加以前の行は `null`)であり、既存の `> 0` 条件では何も表示されない。
  **完成動画用の注記分岐を新設しない**(T148 の値契約をそのまま使う)。
- 既存のプレビュー再生ブロック(`playbackJob`)は**変更しない**。`aria-label="プレビュー動画"` が
  固定文言でよい根拠(「playbackJob に render job が入る経路が無い」)は本変更後も成立する
  — `finishedJob` は別変数であり、poll の preview 分岐も従来どおり `preview` のみを入れる。
  この根拠コメントに「`finishedJob` は別枠で持つ」ことを追記する。

### PHPStan 適合チェック

- N/A(フロント)。代わりに `pnpm typecheck` / `pnpm lint` / `pnpm test` が対象。

### テスト計画

**vitest `tests/js/components/features/manual/RenderPanel.test.ts`(既存へ追加)**

- [ ] `finishedJob があると完成動画プレイヤーと DL ボタンの両方が出る`
- [ ] `finishedJob があれば canManage=false でも完成動画ブロックは出る(表示条件は finishedJob だけ)`
      — サーバが渡した成果物を UI が独自条件で隠さないことの固定
- [ ] `完成動画プレイヤーの src は playback route を job id 込みで指す(再レンダで URL が変わる)`
- [ ] `finishedJob が null なら完成動画プレイヤーも DL ボタンも出ない(published でも)`
      — 「押すと 404」の導線を UI から消したことの固定
- [ ] `完成動画には黒背景の注記を出さない(placeholder_cut_count=0 / null の両方)`
- [ ] `書き出し中(rendering)は完成動画ブロックを描画しない`

> `canManage=false` かつ `finishedJob !== null` は**現行 props では発生しない**
> (props が `download` ability を評価済みで、現行 policy は `update` と同値)。
> vitest は component 単体の契約を固定するために作為的な組合せを与えるだけであり、
> 既存の「完成動画の生成・ダウンロードは編集者が行えます」の文言との同時表示は
> 実アプリでは起きない(文言側は変更しない)。
- [ ] 既存 `baseProps` に `finishedJob: null` を足す(全既存ケースの回帰維持)

**vitest `tests/js/pages/ManualsShow.test.ts`(既存の更新)**

- [ ] `render.finishedJob が RenderPanel へそのまま渡る`(props pass-through の固定)

**Browser lane `tests/Browser/FinishedVideoPlaybackTest.php`(新規。Chromium + WebKit の 2 レーン契約)**

- [ ] `E-1: published マニュアルの詳細画面に完成動画プレイヤーが見える(src が playback route を指す)`
- [ ] `E-2: 再生を足しても DL 導線は残っている(同じブロックに両方見える)`
- [ ] `E-3: ready へ戻った manual では完成動画プレイヤーも DL ボタンも出ない`

Browser lane の作法(既存 `PreviewCoverageNoticeTest` に倣う):
`contractPaidPlan($organization)` を通す(業務 route は `require-active-subscription` group 内)、
`assertNoJavaScriptErrors()`、UI 変更後は先に `pnpm build`。
**クリックしない**(Browser lane には object storage が無く、`/playback` は実 S3 の署名 URL 生成へ進む)。
`preload="none"` により要素描画だけでは媒体取得が走らないが、これは**ヒント**であり
ブラウザが先読みしても検査は DOM 属性の照合なので結果は変わらない(過度な保証を主張しない)。

### リスク

- `preload="none"` はポスター画像が無いため、再生前は黒い矩形になる(`bg-neutral`)。
  プレビュー側(`preload="metadata"`)と見た目が僅かに変わるが、**プレビュー側は変更しない**。
- iOS Safari の inline 再生は `playsinline` 属性が無いと全画面化することがある。
  既存プレビュー `<video>` も付けていないため**本 TODO では揃えない**(挙動を変えるなら
  プレビューと同時に扱う別件。ここで片側だけ変えると差分の意味が濁る)。

---

## 施策 6: 不変条件の機械化(deny-by-default 目録)

### 守る不変条件(**検出できる範囲で**書く。Round 1 [Warning] 施策 6)

> `app/` 配下で **`render_jobs` に対する succeeded 条件つきの直接クエリ**を書いてよいファイルは、
> `RenderArtifactSelectionInventory` に登録されたものだけである(deny-by-default・exact-fit)。
> そのうち「**受け取り対象を 1 件選ぶ**」区分(`Canonical`)は
> `app/Services/Manual/CurrentRenderArtifact.php` ちょうど 1 ファイルに限る。

「選択式はこの世に 1 つだけ」とは書かない。gate が閉じるのは**ファイル粒度**の直接クエリであり、
同一登録ファイル内のメソッド追加・別 helper 経由・動的呼び出しには沈黙する(下記「保証しないもの」)。

### 変更箇所(新規 3 ファイル)

- `app/Enums/Security/RenderArtifactSelectionKind.php`
- `app/Support/Security/RenderArtifactSelectionInventory.php`
- `tests/Architecture/CurrentRenderArtifactInventoryTest.php`

(配置・命名は T148 の `AdoptedTakeReferenceKind` / `AdoptedTakeReferenceInventory` /
`AdoptedReadyTakeCriterionInventoryTest` の 3 点セットと同型にする。新機構を作らない)

### 検出の定義(母集団)

`Tests\Support\PhpTokenScan::normalize()`(コメント / docblock を数えない)で `app/**/*.php` を走査し、
**同一ファイル内に次の 2 群が同居する**ものを母集団とする(Round 1 [Warning] を受けて
文字列リテラル経路と別 query 根を追加した):

1. **status 群**: `JobStatus::Succeeded` の参照 **または** 文字列リテラル `'succeeded'`
2. **query 根 群**: `renderJobs(` / `RenderJob::`(静的呼び出し全般。`::query(` に限定しない)
   / 文字列リテラル `'render_jobs'`(`DB::table('render_jobs')` 経路)

`git grep` による実測では、**両群の同居は変更前も後も 5 → 3 ファイル**で、
広げたマーカーによる新たな偽陽性は 0 件である(変更前: `ManualRenderController` /
`ManualDownloadController` / `VideoManualController` / `RenderJobService` / `RenderPipeline`)。

### 区分(`RenderArtifactSelectionKind`)

| case | 意味 |
|---|---|
| `Canonical` | 選択式の実体。`CurrentRenderArtifact` 1 ファイルのみ |
| `SupersessionCriterion` | 世代交代(より新しい succeeded が在るか / 旧世代の収集)の判定。**選択ではない** |

### 目録(実装時点で成立する内容。実コードで確認済み)

```php
'Services/Manual/CurrentRenderArtifact.php' => [
    'kind' => RenderArtifactSelectionKind::Canonical,
    'rationale' => '「いま受け取れる成果物はどれか」の唯一の選択式。playback / download / 詳細画面 props の'
        .'3 消費者が同じ行を指すための場所であり、保持ポリシーと同じ世代定義を持つ。',
],
'Services/Manual/RenderJobService.php' => [
    'kind' => RenderArtifactSelectionKind::SupersessionCriterion,
    'rationale' => 'newerSucceededExists() は「より新しい succeeded が在るか」の世代交代判定であり、'
        .'受け取り対象を 1 件選ぶ式ではない (削除 job と reconcile の前提条件)。',
],
'Services/Manual/RenderPipeline.php' => [
    'kind' => RenderArtifactSelectionKind::SupersessionCriterion,
    'rationale' => 'finalize が自分より古い succeeded 行を集めて削除 job を投入するための収集であり、'
        .'最新 1 件を選ぶ式ではない (id の大小比較のみで latest を使わない)。',
],
```

> 変更前の母集団は 5 ファイル(上記 3 つの代わりに `ManualRenderController` /
> `ManualDownloadController` / `VideoManualController` / `RenderJobService` / `RenderPipeline`)であり、
> 施策 1-4 の適用後に controller 3 本が母集団から**外れる**(`JobStatus::Succeeded` を持たなくなる)。
> `git grep` で実測済み。

### テスト計画(`tests/Architecture/CurrentRenderArtifactInventoryTest.php`)

- [ ] `母集団が空でない(走査が壊れたら fail = 検査が空振りしないことの保証)`
- [ ] `母集団の全ファイルが inventory に登録されている(未登録は fail)`
- [ ] `inventory に走査で見つからない stale entry が無い(exact-fit)`
- [ ] `Canonical は Services/Manual/CurrentRenderArtifact.php ただ 1 ファイル`
- [ ] `各 entry の根拠は 30 文字以上`
- [ ] `SupersessionCriterion の前提: latest( / orderByDesc( を持たず、id の大小比較を持つ`
      — **token 条件を具体に固定する**(Round 2 [Suggestion]): 正規化 token 列に
      `where` `(` `'id'` `,` `'>'` または `where` `(` `'id'` `,` `'<'` の**連続**が現れること
      (「`id` と `>` が同一ファイルに在る」ではない)。かつ `latest` `(` / `orderByDesc` `(` が
      1 度も現れないこと。**新しい構文解析機構は作らない**(既存 `PhpTokenScan` の
      正規化 token 列に対する部分列照合だけで書く)
      — 免除区分に「実は選択式」が紛れ込むのを機械的に防ぐ(前提の機械検査)。
      前提が崩れた瞬間に区分ごと再審査になる

### 検査が空振りしないことの保証(3 点セット)

1. **負のコントロール**: 母集団 0 件で fail する(走査の壊れ・パス変更・token 正規化の変更を検出)
2. **exact-fit**: 未登録も stale も fail(片方向 allowlist にしない)
3. **cap**: `Canonical` は 1 件ちょうど。`SupersessionCriterion` は前提(`latest(`/`orderByDesc(` 不在 +
   id 大小比較の存在)を満たすときだけ有効

### mutation で赤化を確認する手順(実装時に必ず実行し、結果をコミットメッセージに残す)

| # | 変異 | 期待される赤 |
|---|---|---|
| M1 | `VideoManualController::show()` に旧クエリ(`renderJobs()->where('status', JobStatus::Succeeded->value)->latest('id')`)を書き戻す | `CurrentRenderArtifactInventoryTest`「未登録は fail」 |
| M1' | 同じ変異を**文字列リテラル版**(`->where('status', 'succeeded')`)で書き戻す | 同上(status 群の文字列経路が効いていることの確認) |
| M2 | inventory から `Services/Manual/RenderJobService.php` の entry を削除 | 同上(未登録) |
| M3 | inventory に実在しないパスの entry を足す | 「stale entry が無い」 |
| M4 | 走査根を存在しないディレクトリへ差し替える | 「母集団が空でない」(負のコントロール) |
| M5 | `RenderJobService::newerSucceededExists()` を `latest('id')` を使う形へ書き換える | 「SupersessionCriterion の前提」 |
| M6 | `playback()` の published 判定を削除 | Feature「published でない manual の完成動画は 404」 |
| M7 | `playback()` の ability 写像を `'render'` 固定にする | `RenderPlaybackAbilityMappingTest`「download を拒否する policy では kind=render の playback が 403」 |
| M7' | 写像を `'download'` 固定にする | 同「render を拒否する policy では kind=preview の playback が 403」 |
| M8 | `CurrentRenderArtifact` に `whereNotNull('output_path')` を足す(旧挙動へ戻す) | Unit「最新 succeeded の output_path が NULL なら null」/ Feature「フォールバックせず 404」 |
| M9 | `show()` props の `download` ability 判定を外す | Feature「撮影者には finishedJob=null」 |
| M10 | `RenderPanel` の表示条件を `status === "published"` へ戻す | vitest「finishedJob が null なら出ない」 |
| M11 | `RenderPanel` の表示条件に `&& canManage` を足す | vitest「canManage=false でも完成動画ブロックは出る」 |

---

## 施策 7: ドキュメント

- `docs/architecture.md`:
  - Services 一覧表に `Manual/CurrentRenderArtifact` を追加
  - 新節 **§完成レンダ成果物の選択と受け取り口** を追加(選択式の定義 = 保持ポリシーと同じ世代定義 /
    playback の 3 層 404 と kind→ability 写像 / props と endpoint の条件が 1 対 1 であること /
    **保証しないもの**)。ability 写像については
    「テスト専用 policy で写像を固定している。本番 policy は現在同値」と書き、
    「behavioral に固定できない」とは書かない(Round 1 [Warning] 施策 7)
- `AGENTS.md` ドメイン固有規約に **13. レンダ成果物の選択式の単一化** を追加
  (12 = T148 と同じ書式。gate 名と「保証しないもの」を 1 行で示す)

---

## 保証しないもの(誇張しない)

- **撮影者(project_member)は完成動画を観られない**。`download` ability は編集者のみのままで、
  本 TODO はそれを緩めない。「撮った人が結果に到達する」は**編集者について**成立する。
- **kind→ability 写像は固定するが、本番 policy の差は今は存在しない**。
  `VideoManualPolicy::render` と `::download` はどちらも `ProjectPolicy::update` に落ちるため、
  **本番挙動として両者の差を観測することはできない**。写像は
  `RenderPlaybackAbilityMappingTest` がテスト専用 policy で差を作って固定する
  (= 写像が効いていることは示せるが、「本番で意味のある権限差が既に存在する」とは言えない)。
- **シナリオ編集で `ready` に戻った manual の旧完成動画は、再生も DL もできない**。
  これは既存 download の挙動であり、本 TODO は**揃えるだけで改善しない**。
- **既存 `playbackJob`(preview)の props 露出条件は変えない**。`render` ability を持たない撮影者にも
  (UI では隠れているが)job の存在が渡る。`RenderJobData` は `output_path` も署名 URL も含まないため
  露出は「preview job が在ること」に留まる。これを「直した」と書かない。
- **Architecture gate が閉じるのはファイル粒度の直接クエリだけ**である。
  - succeeded 条件を伴わない別基準の選択(例: `VideoManualService::latestRenderJobForDisplay` の
    表示用最新 job)には**沈黙する**(そもそも母集団に入らない = 意図した設計)
  - **登録済みファイル内でメソッドを増やして選択式を書く**経路は検出しない
    (T148 の `ScenarioWritePathInventoryTest` と同じ制約。fail-first は behavioral テストが担う)
  - 文字列変数経由(`$col = 'suc'.'ceeded'`)・動的呼び出し・別ファイルへ切り出した同義式・
    repository を挟む間接経路も検出しない
  - 走査根は `app/` のみ(`routes/` / `config/` は見ない)
- **署名 URL の TTL とその先の再生可否は保証しない**(`manual.render_playback_url_ttl_minutes`。
  長尺動画で TTL 切れの途中失敗が起きうるかは本 TODO では測っていない)。
- **Browser lane は DOM 契約だけを検査する**。実際に mp4 が再生されること、S3 の CORS 設定、
  iOS Safari のインライン再生挙動は Browser lane では**確認していない**。

---

## 実装順序(fail-first)

1. 施策 6 の gate を**先に**書き、変更前の母集団 5 ファイルで**赤**になることを確認する
   (= 走査が実在の式を捉えていることの確認。負のコントロール)
2. 施策 1(service)+ Unit テスト → 緑
3. 施策 2/3/4(サーバ)+ Feature テスト。**先にテストを書いて赤を確認**してから実装する
4. 施策 5(UI)+ vitest → `pnpm build` → Browser lane
5. inventory を最終形へ更新し、gate を緑にする。M1-M11(M1' / M7' を含む)の mutation を実行し記録する
6. 施策 7(ドキュメント)

検証コマンド: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
`pnpm lint` / `pnpm typecheck` / `pnpm test` / `composer test:browser` / `pnpm build`

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 変更が `Manual` ドメインの controller 3 本 + `RenderPanel.svelte` に集中し、`RenderProps` という**共有インターフェースを破壊的に変更**する(旧キーを残さない)。同じファイル群に触れる TODO が並走すると衝突が確実に出るため、単独の worktree で完結させる |
| 競合リスク | `RenderPanel.svelte` / `types/manual.ts` / `VideoManualController` は T148 系の面と同一。T148 は完了済みだが、同面を触る別 TODO が走っている場合は本 TODO を後に回す |


---

## 実装差分 (git diff HEAD -- app/ resources/ tests/ routes/)

```diff
diff --git a/app/Enums/Security/RenderArtifactSelectionKind.php b/app/Enums/Security/RenderArtifactSelectionKind.php
new file mode 100644
index 0000000..b1083ef
--- /dev/null
+++ b/app/Enums/Security/RenderArtifactSelectionKind.php
@@ -0,0 +1,27 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Security;
+
+/**
+ * `render_jobs` に対する succeeded 条件つきの直接クエリを持つ app/ 配下ファイルの区分 (T154)。
+ *
+ * 守る不変条件:
+ *   「いま受け取れるレンダ成果物はどれか」を **1 件選ぶ**式を書いてよいのは
+ *   `Services/Manual/CurrentRenderArtifact.php` ただ 1 ファイルである。
+ *
+ * 区分は「統合してよい」の意味ではなく、**何のために succeeded 行を引いているか**の記録である。
+ * 強制は `tests/Architecture/CurrentRenderArtifactInventoryTest.php` (deny-by-default + exact-fit)。
+ */
+enum RenderArtifactSelectionKind: string
+{
+    /** 受け取り対象を 1 件選ぶ選択式の実体。**1 ファイルのみ** */
+    case Canonical = 'canonical';
+
+    /**
+     * 世代交代の判定 (より新しい succeeded が在るか / 旧世代の収集)。
+     * **選択ではない** — id の大小比較だけで「どれを受け取るか」を決めない。
+     */
+    case SupersessionCriterion = 'supersession_criterion';
+}
diff --git a/app/Http/Controllers/Projects/ManualDownloadController.php b/app/Http/Controllers/Projects/ManualDownloadController.php
index 30176ce..e70c107 100644
--- a/app/Http/Controllers/Projects/ManualDownloadController.php
+++ b/app/Http/Controllers/Projects/ManualDownloadController.php
@@ -4,22 +4,22 @@
 
 namespace App\Http\Controllers\Projects;
 
-use App\Enums\Manual\JobStatus;
 use App\Enums\Manual\RenderKind;
 use App\Enums\Manual\VideoManualStatus;
 use App\Http\Concerns\ResolvesCurrentOrganization;
 use App\Http\Controllers\Controller;
 use App\Http\Requests\Projects\DownloadManualRequest;
 use App\Models\Project;
-use App\Models\RenderJob;
 use App\Models\VideoManual;
+use App\Services\Manual\CurrentRenderArtifact;
 use App\Services\Render\RenderObjectStorage;
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Support\Facades\Gate;
 
 /**
  * 完成 mp4 のダウンロード (302 → S3 署名 URL。attachment disposition)。download ability。
- * v1 の完成動画取得は本 route のみ (published のインライン再生はスコープ外 = 概念設計 §2)。
+ * アプリ内再生 (inline disposition) は playback route が同一条件で担う (T154)。
+ * 受け取り対象の選択は CurrentRenderArtifact に集約済み (playback と同一式)。
  * JSON を返さないため DTO/JsonResource 規約の対象外 (redirect のみ)。
  */
 class ManualDownloadController extends Controller
@@ -37,15 +37,10 @@ public function show(DownloadManualRequest $request, Project $project, VideoManu
         if ($manual->status !== VideoManualStatus::Published) {
             abort(404);
         }
-        /** @var RenderJob|null $job */
-        $job = $manual->renderJobs()
-            ->where('kind', RenderKind::Render->value)
-            ->where('status', JobStatus::Succeeded->value)
-            ->whereNotNull('output_path')
-            ->latest('id')
-            ->first();
+        // 「いま受け取れる完成動画」の選択は CurrentRenderArtifact ただ 1 箇所 (playback と同一式)
+        $job = CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render);
         if ($job === null || $job->output_path === null) {
-            abort(404);
+            abort(404); // 完成物が無い / 実体が消えている
         }
 
         // filename の sanitize (CR/LF 除去・RFC 5987 + ASCII fallback) は Storage 側 helper が担う
diff --git a/app/Http/Controllers/Projects/ManualRenderController.php b/app/Http/Controllers/Projects/ManualRenderController.php
index 03bc57d..c15cca2 100644
--- a/app/Http/Controllers/Projects/ManualRenderController.php
+++ b/app/Http/Controllers/Projects/ManualRenderController.php
@@ -5,8 +5,8 @@
 namespace App\Http\Controllers\Projects;
 
 use App\DataTransferObjects\Manual\RenderJobData;
-use App\Enums\Manual\JobStatus;
 use App\Enums\Manual\RenderKind;
+use App\Enums\Manual\VideoManualStatus;
 use App\Http\Concerns\ResolvesCurrentOrganization;
 use App\Http\Controllers\Controller;
 use App\Http\Requests\Projects\TriggerRenderRequest;
@@ -15,6 +15,7 @@
 use App\Models\RenderJob;
 use App\Models\User;
 use App\Models\VideoManual;
+use App\Services\Manual\CurrentRenderArtifact;
 use App\Services\Manual\RenderJobService;
 use App\Services\Render\RenderObjectStorage;
 use Illuminate\Http\JsonResponse;
@@ -24,11 +25,12 @@
 
 /**
  * レンダ/プレビューのトリガー (store / preview)、job 状態ポーリング (show)、
- * preview 再生 (playback)。doc/10 §10.3 / 概念設計 §2。
+ * 成果物再生 (playback = preview と完成動画の両方)。doc/10 §10.3 / 概念設計 §2。
  * トリガーは同一オリジン XHR (JSON 応答)。409/402/422 契約のため JsonResource を返す。
  *
  * 権限分離 (概念設計 Round 1 Critical): ポーリング (view = 撮影者も可) は進捗のみで
- * **署名 URL を一切含めない**。preview 再生は playback route (render ability = 編集者専用)。
+ * **署名 URL を一切含めない**。再生は playback route が成果物の性質に合う ability を評価する
+ * (preview → render / 完成動画 → download。どちらも編集者専用 = T154)。
  *
  * nested route の URL 整合は ManualAnalysisController と同じ 2 層 (認可より前に 404):
  * 1. {project} ∈ current org (resolveOrganizationProject = inline guard)
@@ -89,9 +91,19 @@ public function show(Request $request, Project $project, VideoManual $manual, Re
     }
 
     /**
-     * preview 再生 (302 → S3 署名 URL)。編集者専用 (render ability)。
-     * 404 条件: kind!=preview / succeeded でない / output_path NULL / 最新 succeeded でない
-     * (旧世代は実体削除済みの可能性があるため。世代 1 保持の契約と整合)。
+     * 成果物再生 (302 → S3 署名 URL)。preview と完成動画の**両方**を扱う。
+     *
+     * 層は 3 段で、**すべて認可より前に 404** (AGENTS.md セキュリティ不変条件 2/10):
+     *   1. {project} ∈ current org … project.in-current-org middleware + inline guard
+     *   2. {manual}  ∈ {project}   … routes 側 Route::scopeBindings()
+     *   3. {renderJob} ∈ {manual}  … scopeBindings + 下の inline 再検査 (二重防御)
+     * その後に **成果物の性質に合う ability** を評価する:
+     *   kind=preview → render ability / kind=render → download ability
+     *   (現行はどちらも ProjectPolicy::update に落ちるため**可否は完全に同値**。
+     *    UI 側の canManage が自動追従するという意味ではない = 誇張しない)
+     * 完成動画だけ published を要求するのは download と同一条件にするため (順序も download と同じ
+     * = authorize の後)。最後に「いま受け取れる成果物」と同一行かを照合する
+     * (旧世代 job id の直叩き・未完了・実体削除済みはここで 404)。
      */
     public function playback(Request $request, Project $project, VideoManual $manual, RenderJob $renderJob, RenderObjectStorage $storage): RedirectResponse
     {
@@ -101,25 +113,26 @@ public function playback(Request $request, Project $project, VideoManual $manual
         if ($renderJob->video_manual_id !== $manual->id) {
             abort(404);
         }
-        Gate::authorize('render', $manual); // preview は編集者専用機能
-
-        if ($renderJob->kind !== RenderKind::Preview
-            || $renderJob->status !== JobStatus::Succeeded
-            || $renderJob->output_path === null
-            || ! $this->isLatestSucceededPreview($manual, $renderJob)) {
+        // 2 値 enum の網羅 match (到達不能な fallback を作らない)
+        Gate::authorize(match ($renderJob->kind) {
+            RenderKind::Preview => 'render',
+            RenderKind::Render => 'download',
+        }, $manual);
+
+        // 完成動画は「公開中のマニュアルの現行版」だけ (download と同条件・同順序)
+        if ($renderJob->kind === RenderKind::Render && $manual->status !== VideoManualStatus::Published) {
             abort(404);
         }
 
-        return redirect()->away($storage->temporaryPlaybackUrl($renderJob->output_path));
-    }
+        $current = CurrentRenderArtifact::currentSucceeded($manual, $renderJob->kind);
+        if ($current === null || $current->id !== $renderJob->id) {
+            abort(404); // 未完了 / 旧世代 / 実体削除済み
+        }
+        $path = $current->output_path;
+        if ($path === null) {
+            abort(404); // currentSucceeded の契約上到達しないが、型を締めるため明示する
+        }
 
-    /** 当該 job が同 manual の preview の最新 succeeded job か */
-    private function isLatestSucceededPreview(VideoManual $manual, RenderJob $renderJob): bool
-    {
-        return ! $manual->renderJobs()
-            ->where('kind', RenderKind::Preview->value)
-            ->where('status', JobStatus::Succeeded->value)
-            ->where('id', '>', $renderJob->id)
-            ->exists();
+        return redirect()->away($storage->temporaryPlaybackUrl($path));
     }
 }
diff --git a/app/Http/Controllers/Projects/VideoManualController.php b/app/Http/Controllers/Projects/VideoManualController.php
index 4fd31c2..76994bf 100644
--- a/app/Http/Controllers/Projects/VideoManualController.php
+++ b/app/Http/Controllers/Projects/VideoManualController.php
@@ -7,8 +7,8 @@
 use App\DataTransferObjects\Manual\AnalysisJobData;
 use App\DataTransferObjects\Manual\RenderJobData;
 use App\DataTransferObjects\Manual\ScenarioDocumentData;
-use App\Enums\Manual\JobStatus;
 use App\Enums\Manual\RenderKind;
+use App\Enums\Manual\VideoManualStatus;
 use App\Http\Concerns\ResolvesCurrentOrganization;
 use App\Http\Controllers\Controller;
 use App\Http\Requests\Projects\DuplicateVideoManualRequest;
@@ -19,6 +19,7 @@
 use App\Models\User;
 use App\Models\VideoManual;
 use App\Services\Manual\AdoptedReadyTakeCoverage;
+use App\Services\Manual\CurrentRenderArtifact;
 use App\Services\Manual\VideoManualService;
 use App\Support\Seo\SeoManager;
 use Illuminate\Http\RedirectResponse;
@@ -114,12 +115,14 @@ public function show(Request $request, Project $project, VideoManual $manual, Se
         // 動画 URL と「黒背景が何カット分か」の注記が同一オブジェクトから出るため、
         // 最新 preview job と再生対象が別世代になる穴が構造的に消える (T148)。
         // succeeded preview のみを見るため staleness 抑制の対象外 (不変)。
-        $playbackJob = $manual->renderJobs()
-            ->where('kind', RenderKind::Preview->value)
-            ->where('status', JobStatus::Succeeded->value)
-            ->whereNotNull('output_path')
-            ->latest('id')
-            ->first();
+        // 選択式は CurrentRenderArtifact に集約済み (route 側と同一の行を指す = T154)。
+        $playbackJob = CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Preview);
+        // 受け取れる完成動画。**endpoint が 302 を返す条件と 1 対 1**にする:
+        // published + download ability + 現行世代。UI の canManage は表示制御であって
+        // 秘匿境界ではないため、ここで ability を評価する (条件を UI 側に持たせない)。
+        $finishedJob = $manual->status === VideoManualStatus::Published && $user->can('download', $manual)
+            ? CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render)
+            : null;
 
         return Inertia::render('Manuals/Show', [
             'project' => [
@@ -142,7 +145,7 @@ public function show(Request $request, Project $project, VideoManual $manual, Se
                     : AnalysisJobData::fromJob($analysisJob, $manual)->toArray(),
                 'hasDocument' => $manual->sourceDocuments()->exists(),
             ],
-            // レンダパネル (最新 render job / 最新 preview job / 再生可能 preview)。RenderProps と対
+            // レンダパネル (最新 render job / 最新 preview job / 再生可能 preview / 完成動画)。RenderProps と対
             'render' => [
                 'job' => $renderJob === null
                     ? null
@@ -153,6 +156,10 @@ public function show(Request $request, Project $project, VideoManual $manual, Se
                 'playbackJob' => $playbackJob === null
                     ? null
                     : RenderJobData::fromJob($playbackJob, $manual)->toArray(),
+                // 完成動画 (再生 + DL の唯一の出し分け根拠)。null = 出さない
+                'finishedJob' => $finishedJob === null
+                    ? null
+                    : RenderJobData::fromJob($finishedJob, $manual)->toArray(),
                 // 「使用できる採用テイクがない」カットの充足状況。render の 422 と**同じ述語**から出す
                 // = 判断基準を 1 箇所に置く (bug-hunt F-1-01)。描画時点のスナップショットであり
                 // 常に最新ではない (押下は止めないので詰みにはならない)。
diff --git a/app/Services/Manual/CurrentRenderArtifact.php b/app/Services/Manual/CurrentRenderArtifact.php
new file mode 100644
index 0000000..3670171
--- /dev/null
+++ b/app/Services/Manual/CurrentRenderArtifact.php
@@ -0,0 +1,40 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Manual;
+
+use App\Enums\Manual\JobStatus;
+use App\Enums\Manual\RenderKind;
+use App\Models\RenderJob;
+use App\Models\VideoManual;
+
+/**
+ * 「いま受け取れるレンダ成果物はどれか」の**唯一の選択式** (playback / download / 詳細画面 props)。
+ *
+ * 定義は保持ポリシー (RenderJobService::newerSucceededExists / DeleteRenderOutputsJob) と
+ * **同じ世代定義**である: 実体が残るのは「同 manual・同 kind の最新 succeeded」だけなので、
+ * 最新 succeeded の output_path が NULL (= 生成に失敗した / 掃除された) なら
+ * **旧世代へフォールバックしない** (削除済みオブジェクトの署名 URL を出さないため)。
+ *
+ * **持たない責務**: published 判定 (完成動画の公開状態) と ability 判定は呼び出し側にある。
+ * ここは「どの行か」だけを答える (名前が示す役割を超えない)。読み取り専用。
+ */
+final class CurrentRenderArtifact
+{
+    /** 同 manual・同 kind で現在受け取れる succeeded job (無ければ null) */
+    public static function currentSucceeded(VideoManual $manual, RenderKind $kind): ?RenderJob
+    {
+        $job = $manual->renderJobs()
+            ->where('kind', $kind->value)
+            ->where('status', JobStatus::Succeeded->value)
+            ->latest('id')
+            ->first();
+
+        if ($job === null || $job->output_path === null) {
+            return null; // 旧世代へフォールバックしない (実体が無い可能性がある)
+        }
+
+        return $job;
+    }
+}
diff --git a/app/Support/Security/RenderArtifactSelectionInventory.php b/app/Support/Security/RenderArtifactSelectionInventory.php
new file mode 100644
index 0000000..d641938
--- /dev/null
+++ b/app/Support/Security/RenderArtifactSelectionInventory.php
@@ -0,0 +1,51 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Security;
+
+use App\Enums\Security\RenderArtifactSelectionKind;
+
+/**
+ * `render_jobs` に対する succeeded 条件つきの直接クエリを持つ app/ 配下ファイルの目録
+ * (deny-by-default。T154)。
+ *
+ * 守る不変条件:
+ *   app/ 配下で render_jobs に succeeded 条件つきの直接クエリを書いてよいファイルは
+ *   本目録に登録されたものだけである。そのうち「**受け取り対象を 1 件選ぶ**」区分
+ *   (`Canonical`) は `Services/Manual/CurrentRenderArtifact.php` ちょうど 1 ファイルに限る。
+ *
+ * 強制は `tests/Architecture/CurrentRenderArtifactInventoryTest.php`
+ * (exact-fit: 未登録の直接クエリも、実体を失った stale entry も fail させる)。
+ *
+ * **保証しないもの**: gate が閉じるのはファイル粒度の直接クエリだけである
+ * (同一登録ファイル内のメソッド追加・別 helper 経由・動的呼び出しには沈黙する)。
+ */
+final class RenderArtifactSelectionInventory
+{
+    /**
+     * app/ 相対パス => [区分, 根拠 (30 文字以上)]。
+     *
+     * @return array<string, array{kind: RenderArtifactSelectionKind, rationale: string}>
+     */
+    public static function entries(): array
+    {
+        return [
+            'Services/Manual/CurrentRenderArtifact.php' => [
+                'kind' => RenderArtifactSelectionKind::Canonical,
+                'rationale' => '「いま受け取れる成果物はどれか」の唯一の選択式。playback / download / 詳細画面 props の'
+                    .'3 消費者が同じ行を指すための場所であり、保持ポリシーと同じ世代定義を持つ。',
+            ],
+            'Services/Manual/RenderJobService.php' => [
+                'kind' => RenderArtifactSelectionKind::SupersessionCriterion,
+                'rationale' => 'newerSucceededExists() は「より新しい succeeded が在るか」の世代交代判定であり、'
+                    .'受け取り対象を 1 件選ぶ式ではない (削除 job と reconcile の前提条件)。',
+            ],
+            'Services/Manual/RenderPipeline.php' => [
+                'kind' => RenderArtifactSelectionKind::SupersessionCriterion,
+                'rationale' => 'finalize が自分より古い succeeded 行を集めて削除 job を投入するための収集であり、'
+                    .'最新 1 件を選ぶ式ではない (id の大小比較のみで latest を使わない)。',
+            ],
+        ];
+    }
+}
diff --git a/resources/js/components/features/manual/RenderPanel.svelte b/resources/js/components/features/manual/RenderPanel.svelte
index 8b9018c..9a85e7d 100644
--- a/resources/js/components/features/manual/RenderPanel.svelte
+++ b/resources/js/components/features/manual/RenderPanel.svelte
@@ -30,6 +30,12 @@
         job: RenderJobProps | null;
         previewJob: RenderJobProps | null;
         playbackJob: RenderJobProps | null;
+        /**
+         * 受け取れる完成動画 (サーバが published + download ability + 現行世代を判定済み)。
+         * **local state にしない**: render 成功時の router.reload() で props ごと入れ替わる。
+         * ポーリング応答から組み立てる経路は作らない (応答は上記条件を判定していない)。
+         */
+        finishedJob: RenderJobProps | null;
         coverage: TakeCoverageProps;
         canManage: boolean;
     }
@@ -41,6 +47,7 @@
         job,
         previewJob,
         playbackJob: playbackJobProp,
+        finishedJob,
         coverage,
         canManage,
     }: Props = $props();
@@ -315,16 +322,39 @@
                 シナリオが編集されています。最新の内容で完成動画を再生成してください。
             </p>
         {/if}
-        {#if status === "published" && canManage}
-            <div class="mt-4">
-                <Button
-                    variant="secondary"
-                    href={`/projects/${projectId}/manuals/${manualId}/download`}
-                    testId="download-button"
-                >
-                    <Download class="size-4" />
-                    完成動画をダウンロード
-                </Button>
+        <!-- 完成動画 (再生 + DL)。表示の可否はサーバが決めた finishedJob **だけ**で判断する
+             (published / ability を UI 側で再判定しない = 判断を 2 箇所に持たない)。
+             canManage を積まないのは、finishedJob が既に download ability の評価結果を
+             運んでいるためである (canManage = update ability を積むと、policy が分岐した日に
+             サーバが渡した成果物を UI が隠す)。
+             書き出し中に出ないことは、この枝が {#if rendering}…{:else} の else 側にある
+             ことで構造的に保証される。
+             黒背景の注記は出さない: succeeded render の placeholder_cut_count は 0 (T148 の
+             値契約) なので、完成動画用の注記分岐は新設しない。 -->
+        {#if finishedJob !== null}
+            <div class="mt-4 flex flex-col gap-3" data-testid="final-video-block">
+                <!-- svelte-ignore a11y_media_has_caption (完成動画の字幕は焼き込み済み) -->
+                <!-- preload="none": 詳細画面を開くたびに署名 URL 発行と本体取得が走るのを避ける
+                     (完成動画は尺が長い)。src に job id を含むため、再レンダで URL が変わり
+                     古い世代が再生され続けることが起きない。 -->
+                <video
+                    controls
+                    preload="none"
+                    class="w-full rounded-md bg-neutral"
+                    src={`/projects/${projectId}/manuals/${manualId}/render-jobs/${finishedJob.id}/playback`}
+                    aria-label="完成動画"
+                    data-testid="final-video"
+                ></video>
+                <div>
+                    <Button
+                        variant="secondary"
+                        href={`/projects/${projectId}/manuals/${manualId}/download`}
+                        testId="download-button"
+                    >
+                        <Download class="size-4" />
+                        完成動画をダウンロード
+                    </Button>
+                </div>
             </div>
         {/if}
         {#if !canManage}
@@ -421,7 +451,8 @@
                 <!-- svelte-ignore a11y_media_has_caption (プレビュー動画の字幕は焼き込み済み) -->
                 <!-- aria-label は固定文言でよい: playbackJob の供給源は初期値 (Controller が
                      kind=Preview ∧ status=Succeeded で抽出) と poll の preview 分岐だけで、
-                     render job が入る経路が無い (完成動画と取り違わない)。 -->
+                     render job が入る経路が無い (完成動画と取り違わない)。
+                     完成動画は finishedJob という別枠で持つため、この根拠は T154 後も成立する。 -->
                 <video
                     controls
                     preload="metadata"
diff --git a/resources/js/pages/Manuals/Show.svelte b/resources/js/pages/Manuals/Show.svelte
index daa6b5f..fd81238 100644
--- a/resources/js/pages/Manuals/Show.svelte
+++ b/resources/js/pages/Manuals/Show.svelte
@@ -134,6 +134,7 @@
                 job={render.job}
                 previewJob={render.previewJob}
                 playbackJob={render.playbackJob}
+                finishedJob={render.finishedJob}
                 coverage={render.coverage}
                 {canManage}
             />
diff --git a/resources/js/types/manual.ts b/resources/js/types/manual.ts
index 135c588..d9517a6 100644
--- a/resources/js/types/manual.ts
+++ b/resources/js/types/manual.ts
@@ -280,6 +280,12 @@ export interface RenderProps {
      * 動画 URL と黒背景の注記が同一オブジェクトから出る (別世代の値で説明しないため)。
      */
     playbackJob: RenderJobProps | null;
+    /**
+     * 受け取れる完成動画の job (無ければ null)。
+     * サーバが「published + download ability + 現行世代」を判定した結果そのものであり、
+     * **UI 側で条件を再判定しない** (判断は props で 1 回)。
+     */
+    finishedJob: RenderJobProps | null;
     /**
      * 採用テイクの充足状況 (描画時点のスナップショット。常に最新ではない)。
      * 生成物の実績は playbackJob.placeholder_cut_count が語る (別概念なので混ぜない)。
diff --git a/tests/Architecture/CurrentRenderArtifactInventoryTest.php b/tests/Architecture/CurrentRenderArtifactInventoryTest.php
new file mode 100644
index 0000000..cb4d204
--- /dev/null
+++ b/tests/Architecture/CurrentRenderArtifactInventoryTest.php
@@ -0,0 +1,370 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Security\RenderArtifactSelectionKind;
+use App\Support\Security\RenderArtifactSelectionInventory;
+use Tests\Support\PhpTokenScan;
+
+/*
+ * レンダ成果物の選択式の単一化 (T154) の deny-by-default 目録。
+ *
+ * 不変条件:
+ *   app/ 配下で **render_jobs に対する succeeded 条件つきの直接クエリ**を書いてよいファイルは
+ *   `RenderArtifactSelectionInventory` に登録されたものだけである (exact-fit)。
+ *   そのうち「**受け取り対象を 1 件選ぶ**」区分 (Canonical) は
+ *   `Services/Manual/CurrentRenderArtifact.php` ちょうど 1 ファイルに限る。
+ *
+ * 走査は PhpTokenScan::normalize() ベース (コメント / docblock 内の出現は数えない)。
+ *
+ * 検出 (母集団): 同一ファイル内に次の 2 群が**同居**するもの
+ *   1. status 群: `JobStatus::Succeeded` の参照 **または** 文字列リテラル 'succeeded'
+ *   2. query 根 群: `renderJobs(` / `RenderJob::` (静的呼び出し全般) /
+ *      文字列リテラル 'render_jobs' (DB::table('render_jobs') 経路)
+ *
+ * 免除区分 (SupersessionCriterion) には**機械検査される前提**が付く:
+ *   `latest(` / `orderByDesc(` を 1 度も持たず、かつ `where('id', '>' | '<', …)` の
+ *   **連続 token 列**を持つ (= 世代の大小比較であって最新 1 件の選択ではない)。
+ *   前提が崩れた瞬間に区分ごと再審査になる。
+ *
+ * 保証しないもの (誇張しない):
+ * - 閉じるのは**ファイル粒度**の直接クエリだけである。登録済みファイル内でメソッドを増やして
+ *   選択式を書く経路は検出しない (fail-first は behavioral テストが担う)
+ * - 文字列変数経由 (`$s = 'suc'.'ceeded'`)・動的呼び出し・別ファイルへ切り出した同義式・
+ *   repository を挟む間接経路には**沈黙する**
+ * - succeeded 条件を伴わない別基準の選択 (表示用の最新 job など) は母集団に入らない
+ * - 走査根は app/ のみ (routes/ / config/ は見ない)
+ */
+final class RenderArtifactSelectionScanner
+{
+    /** @return list<string> 母集団 (status 群 × query 根 群の同居) に該当する app/ 相対パス */
+    public static function selectionFiles(): array
+    {
+        return self::scan(static fn (array $tokens): bool => self::hasSucceededStatusMarker($tokens)
+            && self::hasRenderJobQueryRoot($tokens));
+    }
+
+    /**
+     * status 群: `JobStatus::Succeeded` (部分修飾・完全修飾も末尾セグメントで判定) または
+     * 文字列リテラル 'succeeded'。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    public static function hasSucceededStatusMarker(array $tokens): bool
+    {
+        $count = count($tokens);
+        for ($i = 0; $i < $count; $i++) {
+            $token = $tokens[$i];
+            if ($token['id'] === T_CONSTANT_ENCAPSED_STRING && trim($token['text'], "'\"") === 'succeeded') {
+                return true;
+            }
+            if (self::classNameAt($tokens, $i) !== 'JobStatus') {
+                continue;
+            }
+            if ($i + 2 >= $count || $tokens[$i + 1]['id'] !== T_DOUBLE_COLON) {
+                continue;
+            }
+            if ($tokens[$i + 2]['id'] === T_STRING && $tokens[$i + 2]['text'] === 'Succeeded') {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
+    /**
+     * query 根 群: `renderJobs(` / `RenderJob::` / 文字列リテラル 'render_jobs'。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    public static function hasRenderJobQueryRoot(array $tokens): bool
+    {
+        $count = count($tokens);
+        for ($i = 0; $i < $count; $i++) {
+            $token = $tokens[$i];
+            if ($token['id'] === T_CONSTANT_ENCAPSED_STRING && trim($token['text'], "'\"") === 'render_jobs') {
+                return true;
+            }
+            if ($i + 1 >= $count) {
+                continue;
+            }
+            if ($token['id'] === T_STRING && $token['text'] === 'renderJobs'
+                && $tokens[$i + 1]['id'] === null && $tokens[$i + 1]['text'] === '(') {
+                return true;
+            }
+            if (self::classNameAt($tokens, $i) === 'RenderJob' && $tokens[$i + 1]['id'] === T_DOUBLE_COLON) {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
+    /**
+     * SupersessionCriterion の前提: 「最新 1 件の選択」を行う道具を持たず、
+     * 世代の大小比較 (`where('id', '>' | '<', …)`) を実際に持つこと。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    public static function supersessionPremiseHolds(array $tokens): bool
+    {
+        return ! self::hasLatestSelector($tokens) && self::hasIdComparison($tokens);
+    }
+
+    /**
+     * `latest(` / `orderByDesc(` の呼び出しが 1 度でもあるか。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    public static function hasLatestSelector(array $tokens): bool
+    {
+        $count = count($tokens);
+        for ($i = 0; $i < $count - 1; $i++) {
+            if ($tokens[$i]['id'] !== T_STRING
+                || ! in_array($tokens[$i]['text'], ['latest', 'orderByDesc'], true)) {
+                continue;
+            }
+            if ($tokens[$i + 1]['id'] === null && $tokens[$i + 1]['text'] === '(') {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
+    /**
+     * `where` `(` `'id'` `,` `'>'`(または `'<'`) の**連続**した token 列を持つか。
+     *
+     * 「id と > が同一ファイルに在る」ではなく**列そのもの**を照合する
+     * (免除区分に「実は選択式」が紛れ込むのを機械的に防ぐ)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    public static function hasIdComparison(array $tokens): bool
+    {
+        $count = count($tokens);
+        for ($i = 0; $i + 4 < $count; $i++) {
+            if ($tokens[$i]['id'] !== T_STRING || $tokens[$i]['text'] !== 'where') {
+                continue;
+            }
+            if ($tokens[$i + 1]['id'] !== null || $tokens[$i + 1]['text'] !== '(') {
+                continue;
+            }
+            if ($tokens[$i + 2]['id'] !== T_CONSTANT_ENCAPSED_STRING
+                || trim($tokens[$i + 2]['text'], "'\"") !== 'id') {
+                continue;
+            }
+            if ($tokens[$i + 3]['id'] !== null || $tokens[$i + 3]['text'] !== ',') {
+                continue;
+            }
+            if ($tokens[$i + 4]['id'] !== T_CONSTANT_ENCAPSED_STRING) {
+                continue;
+            }
+            if (in_array(trim($tokens[$i + 4]['text'], "'\""), ['>', '<'], true)) {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
+    /** @return list<string> 指定区分に登録された app/ 相対パス (昇順) */
+    public static function filesOfKind(RenderArtifactSelectionKind $kind): array
+    {
+        $files = [];
+        foreach (RenderArtifactSelectionInventory::entries() as $relative => $entry) {
+            if ($entry['kind'] === $kind) {
+                $files[] = $relative;
+            }
+        }
+        sort($files);
+
+        return $files;
+    }
+
+    /**
+     * token 位置 $i がクラス名参照であれば末尾セグメントを返す (部分修飾・完全修飾に対応)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    private static function classNameAt(array $tokens, int $i): ?string
+    {
+        if (! in_array($tokens[$i]['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
+            return null;
+        }
+        $segments = explode('\\', ltrim($tokens[$i]['text'], '\\'));
+
+        return end($segments);
+    }
+
+    /**
+     * @param  callable(list<array{id: int|null, text: string, line: int}>): bool  $matches
+     * @return list<string>
+     */
+    private static function scan(callable $matches): array
+    {
+        $appDir = self::appDir();
+        $found = [];
+        foreach (self::phpFiles($appDir) as $path) {
+            $source = file_get_contents($path);
+            if ($source === false) {
+                throw new RuntimeException("Failed to read PHP source: {$path}");
+            }
+            if ($matches(PhpTokenScan::normalize($source))) {
+                $found[] = substr($path, strlen($appDir) + 1);
+            }
+        }
+        sort($found);
+
+        return $found;
+    }
+
+    /** @return list<array{id: int|null, text: string, line: int}> */
+    public static function tokensOf(string $relative): array
+    {
+        $path = self::appDir().'/'.$relative;
+        $source = file_get_contents($path);
+        if ($source === false) {
+            throw new RuntimeException("Failed to read PHP source: {$path}");
+        }
+
+        return PhpTokenScan::normalize($source);
+    }
+
+    public static function appDir(): string
+    {
+        $appDir = realpath(__DIR__.'/../../app');
+        if (! is_string($appDir)) {
+            throw new RuntimeException('app/ ディレクトリを解決できません');
+        }
+
+        return $appDir;
+    }
+
+    /** @return list<string> */
+    public static function phpFiles(string $dir): array
+    {
+        $files = [];
+        $iterator = new RecursiveIteratorIterator(
+            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
+        );
+        /** @var SplFileInfo $file */
+        foreach ($iterator as $file) {
+            if ($file->isFile() && $file->getExtension() === 'php') {
+                $files[] = $file->getPathname();
+            }
+        }
+        sort($files);
+
+        return $files;
+    }
+}
+
+test('ケース 1: 走査母集団が空でない (負のコントロール)', function (): void {
+    expect(RenderArtifactSelectionScanner::phpFiles(RenderArtifactSelectionScanner::appDir()))->not->toBeEmpty();
+    expect(RenderArtifactSelectionScanner::selectionFiles())->not->toBeEmpty(
+        '走査が壊れて母集団が空です (規則が空振りしている = gate が常時緑になる)');
+});
+
+test('ケース 2: render_jobs の succeeded 直接クエリを持つ app/ のファイルはすべて目録に登録されている', function (): void {
+    $registered = array_keys(RenderArtifactSelectionInventory::entries());
+    $unregistered = array_values(array_diff(RenderArtifactSelectionScanner::selectionFiles(), $registered));
+
+    expect($unregistered)->toBe([],
+        'render_jobs の succeeded 条件つき直接クエリは CurrentRenderArtifact へ集約するか、'
+        .'RenderArtifactSelectionInventory へ区分 + 根拠付きで登録してください (deny-by-default): '
+        .implode(', ', $unregistered));
+});
+
+test('ケース 3: 目録の全エントリが実在の直接クエリを持つ (exact-fit)', function (): void {
+    $selection = RenderArtifactSelectionScanner::selectionFiles();
+    $stale = array_values(array_diff(array_keys(RenderArtifactSelectionInventory::entries()), $selection));
+
+    expect($stale)->toBe([],
+        '実体を失った目録エントリは削除してください (残置すると gate が常時緑になる): '.implode(', ', $stale));
+});
+
+test('ケース 4: Canonical は CurrentRenderArtifact ただ 1 ファイルである', function (): void {
+    expect(RenderArtifactSelectionScanner::filesOfKind(RenderArtifactSelectionKind::Canonical))
+        ->toBe(['Services/Manual/CurrentRenderArtifact.php']);
+});
+
+test('ケース 5: 目録の根拠は 30 文字以上ある', function (): void {
+    foreach (RenderArtifactSelectionInventory::entries() as $relative => $entry) {
+        expect(mb_strlen($entry['rationale']))->toBeGreaterThanOrEqual(30,
+            "{$relative} の根拠が短すぎます (30 文字以上)");
+    }
+});
+
+test('ケース 6: SupersessionCriterion は「最新 1 件の選択」を持たず世代の大小比較を持つ (前提の機械検査)', function (): void {
+    $criterionFiles = RenderArtifactSelectionScanner::filesOfKind(
+        RenderArtifactSelectionKind::SupersessionCriterion,
+    );
+
+    expect($criterionFiles)->not->toBeEmpty();
+    foreach ($criterionFiles as $relative) {
+        $tokens = RenderArtifactSelectionScanner::tokensOf($relative);
+        expect(RenderArtifactSelectionScanner::hasLatestSelector($tokens))->toBeFalse(
+            "{$relative} が latest( / orderByDesc( を持ちました。世代交代判定ではなく選択式に"
+            .'なっている可能性があります (区分を再審査してください)');
+        expect(RenderArtifactSelectionScanner::hasIdComparison($tokens))->toBeTrue(
+            "{$relative} に where('id', '>' | '<', …) の世代比較がありません。"
+            .'SupersessionCriterion の前提が崩れています');
+    }
+});
+
+test('scanner 自己検証: コメント / docblock 内の出現は数えない', function (): void {
+    $tokens = PhpTokenScan::normalize(<<<'PHP'
+    <?php
+    // RenderJob::query()->where('status', JobStatus::Succeeded->value) はコメント
+    /** 'render_jobs' と 'succeeded' も docblock */
+    class Example {}
+    PHP);
+
+    expect(RenderArtifactSelectionScanner::hasSucceededStatusMarker($tokens))->toBeFalse();
+    expect(RenderArtifactSelectionScanner::hasRenderJobQueryRoot($tokens))->toBeFalse();
+});
+
+test('scanner 自己検証: status 群と query 根 群の各経路を検出する', function (): void {
+    $enum = PhpTokenScan::normalize('<?php $b = $job->status === JobStatus::Succeeded;');
+    $qualified = PhpTokenScan::normalize('<?php $b = \App\Enums\Manual\JobStatus::Succeeded;');
+    $literal = PhpTokenScan::normalize("<?php \$q->where('status', 'succeeded');");
+    $otherCase = PhpTokenScan::normalize('<?php $b = JobStatus::Failed;');
+
+    expect(RenderArtifactSelectionScanner::hasSucceededStatusMarker($enum))->toBeTrue();
+    expect(RenderArtifactSelectionScanner::hasSucceededStatusMarker($qualified))->toBeTrue();
+    expect(RenderArtifactSelectionScanner::hasSucceededStatusMarker($literal))->toBeTrue();
+    expect(RenderArtifactSelectionScanner::hasSucceededStatusMarker($otherCase))->toBeFalse();
+
+    $relation = PhpTokenScan::normalize('<?php $q = $manual->renderJobs()->get();');
+    $staticCall = PhpTokenScan::normalize('<?php $q = RenderJob::query();');
+    $staticOther = PhpTokenScan::normalize('<?php $q = RenderJob::whereKey(1);');
+    $table = PhpTokenScan::normalize("<?php \$q = DB::table('render_jobs');");
+    $unrelated = PhpTokenScan::normalize('<?php $q = $manual->cuts()->get();');
+
+    expect(RenderArtifactSelectionScanner::hasRenderJobQueryRoot($relation))->toBeTrue();
+    expect(RenderArtifactSelectionScanner::hasRenderJobQueryRoot($staticCall))->toBeTrue();
+    expect(RenderArtifactSelectionScanner::hasRenderJobQueryRoot($staticOther))->toBeTrue();
+    expect(RenderArtifactSelectionScanner::hasRenderJobQueryRoot($table))->toBeTrue();
+    expect(RenderArtifactSelectionScanner::hasRenderJobQueryRoot($unrelated))->toBeFalse();
+});
+
+test('scanner 自己検証: 前提検査は latest( の存在と id 大小比較の不在を捉える', function (): void {
+    $supersession = PhpTokenScan::normalize(
+        "<?php \$b = RenderJob::query()->where('id', '>', \$job->id)->exists();",
+    );
+    $olderGeneration = PhpTokenScan::normalize(
+        "<?php \$b = RenderJob::query()->where('id', '<', \$job->id)->get();",
+    );
+    $selection = PhpTokenScan::normalize("<?php \$j = \$manual->renderJobs()->latest('id')->first();");
+    $descSelection = PhpTokenScan::normalize("<?php \$j = \$manual->renderJobs()->orderByDesc('id')->first();");
+    // 「id と > が同一ファイルに在る」だけでは前提を満たさない (列そのものを照合する)
+    $scattered = PhpTokenScan::normalize("<?php \$q->where('kind', 'render')->where('progress', '>', 0);");
+
+    expect(RenderArtifactSelectionScanner::supersessionPremiseHolds($supersession))->toBeTrue();
+    expect(RenderArtifactSelectionScanner::supersessionPremiseHolds($olderGeneration))->toBeTrue();
+    expect(RenderArtifactSelectionScanner::supersessionPremiseHolds($selection))->toBeFalse();
+    expect(RenderArtifactSelectionScanner::supersessionPremiseHolds($descSelection))->toBeFalse();
+    expect(RenderArtifactSelectionScanner::supersessionPremiseHolds($scattered))->toBeFalse();
+    expect(RenderArtifactSelectionScanner::hasIdComparison($scattered))->toBeFalse();
+});
diff --git a/tests/Browser/FinishedVideoPlaybackTest.php b/tests/Browser/FinishedVideoPlaybackTest.php
new file mode 100644
index 0000000..aa831f8
--- /dev/null
+++ b/tests/Browser/FinishedVideoPlaybackTest.php
@@ -0,0 +1,110 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\VideoManualStatus;
+use App\Models\Project;
+use App\Models\RenderJob;
+use App\Models\VideoManual;
+
+/*
+|--------------------------------------------------------------------------
+| 完成動画のアプリ内再生 (T154)
+|--------------------------------------------------------------------------
+|
+| 制作フロー最終段の「完成物の受け取り」が DL 1 本しかなく、アプリ内で観る手段が無かった。
+| 実ブラウザで見るのは次の 3 点だけである:
+|   E-1 published マニュアルの詳細画面に完成動画プレイヤーが見える (src が playback route)
+|   E-2 再生を足しても DL 導線は残っている (同じブロックに両方見える)
+|   E-3 ready へ戻った manual では完成動画プレイヤーも DL ボタンも出ない
+|
+| **クリックしない**: Browser lane には object storage が無く、/playback は実 S3 の
+| 署名 URL 生成へ進む。preload="none" により要素描画だけでは媒体取得が走らないが、
+| これは**ヒント**でありブラウザが先読みしても検査は DOM 属性の照合なので結果は変わらない。
+|
+| 業務 route は require-active-subscription group 内なので contractPaidPlan を通さないと
+| /billing-required へ着地する。実ブラウザは public/build を読むため UI 変更後は先に pnpm build。
+|
+| **DOM 契約だけを検査する**: 実際に mp4 が再生されること・S3 の CORS・iOS Safari の
+| インライン再生挙動はこのレーンでは確認していない (誇張しない)。
+|
+*/
+
+/**
+ * published マニュアル + 現行世代の succeeded render。
+ *
+ * @return array{Project, VideoManual, RenderJob}
+ */
+function finishedVideoPlaybackFixture(): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    contractPaidPlan($organization);
+
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create([
+        'created_by' => $owner->id,
+        'status' => VideoManualStatus::Published->value,
+        'scenario_version' => 2,
+    ]);
+    $job = RenderJob::factory()->forManual($manual)
+        ->succeeded("projects/{$project->id}/manuals/{$manual->id}/renders/v2-1.mp4")->create();
+
+    test()->actingAs($owner);
+
+    return [$project, $manual, $job];
+}
+
+test('E-1: published マニュアルの詳細画面に完成動画プレイヤーが見える', function (): void {
+    [$project, $manual, $job] = finishedVideoPlaybackFixture();
+
+    $page = visit("/projects/{$project->id}/manuals/{$manual->id}")
+        ->assertPathIs("/projects/{$project->id}/manuals/{$manual->id}")
+        ->assertNoJavaScriptErrors();
+
+    $page->assertPresent('[data-testid="final-video"]');
+
+    // src は job id を含む playback route を指す (再レンダで URL 文字列そのものが変わる)
+    expect($page->script(<<<'JS'
+        (() => {
+            const el = document.querySelector('[data-testid="final-video"]');
+            return el === null ? null : { src: el.getAttribute('src'), preload: el.getAttribute('preload') };
+        })()
+    JS))->toMatchArray([
+        'src' => "/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}/playback",
+        'preload' => 'none',
+    ]);
+});
+
+test('E-2: 再生を足しても DL 導線は残っている (同じブロックに両方見える)', function (): void {
+    [$project, $manual] = finishedVideoPlaybackFixture();
+
+    $page = visit("/projects/{$project->id}/manuals/{$manual->id}")
+        ->assertPathIs("/projects/{$project->id}/manuals/{$manual->id}");
+
+    $page->assertVisible('[data-testid="final-video-block"]');
+    $page->assertVisible('[data-testid="download-button"]');
+
+    // 両方が同じ完成動画ブロックの内側にある (受け取り手段を 1 箇所に集める)
+    expect($page->script(<<<'JS'
+        (() => {
+            const block = document.querySelector('[data-testid="final-video-block"]');
+            if (block === null) return null;
+            return {
+                video: block.querySelector('[data-testid="final-video"]') !== null,
+                download: block.querySelector('[data-testid="download-button"]') !== null,
+            };
+        })()
+    JS))->toMatchArray(['video' => true, 'download' => true]);
+});
+
+test('E-3: ready へ戻った manual では完成動画プレイヤーも DL ボタンも出ない', function (): void {
+    [$project, $manual] = finishedVideoPlaybackFixture();
+    // シナリオ編集で ready へ戻ると完成動画は受け取れない (押すと 404 になる導線を出さない)
+    $manual->forceFill(['status' => VideoManualStatus::Ready])->save();
+
+    $page = visit("/projects/{$project->id}/manuals/{$manual->id}")
+        ->assertPathIs("/projects/{$project->id}/manuals/{$manual->id}");
+
+    $page->assertMissing('[data-testid="final-video-block"]');
+    $page->assertMissing('[data-testid="download-button"]');
+});
diff --git a/tests/Feature/Manual/FinishedVideoPlaybackTest.php b/tests/Feature/Manual/FinishedVideoPlaybackTest.php
new file mode 100644
index 0000000..ce2471f
--- /dev/null
+++ b/tests/Feature/Manual/FinishedVideoPlaybackTest.php
@@ -0,0 +1,239 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\VideoManualStatus;
+use App\Enums\ProjectRole;
+use App\Models\Organization;
+use App\Models\Project;
+use App\Models\RenderJob;
+use App\Models\User;
+use App\Models\VideoManual;
+use Illuminate\Support\Facades\Storage;
+use Inertia\Testing\AssertableInertia;
+
+/*
+ * 完成動画をアプリ内で観られるようにする (T154)。
+ *
+ * 固定する契約:
+ * - playback は preview と完成動画の**両方**を扱う (route は増やさない。job id を含む既存の形)
+ * - 完成動画の再生条件は download と**完全同一**: published + 現行世代 + download ability
+ *   (認可を緩めない。層 2 のテナント境界 404 は認可より前)
+ * - 詳細画面 props の finishedJob は endpoint が 302 を返す条件と 1 対 1
+ *   (押すと 404 になる導線を UI に出さない = 判断はサーバで 1 回)
+ * - 選択式は CurrentRenderArtifact ただ 1 つ。最新 succeeded の output_path が NULL のとき
+ *   旧世代へフォールバックしない (実体が削除済みのため)
+ */
+
+/**
+ * @return array{Organization, User, Project, VideoManual}
+ */
+function finishedVideoContext(): array
+{
+    Storage::fake('s3');
+    // fake local disk は temporaryUrl を標準サポートしないため署名 URL 生成を stub する
+    Storage::disk('s3')->buildTemporaryUrlsUsing(
+        fn (string $path): string => "https://signed.example/{$path}",
+    );
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create([
+        'status' => VideoManualStatus::Published->value,
+        'scenario_version' => 2,
+    ]);
+
+    return [$organization, $owner, $project, $manual];
+}
+
+/** 撮影者 (project_member) を作る */
+function finishedVideoMember(Organization $organization, Project $project): User
+{
+    $member = attachOrganizationMember($organization);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+    attachProjectMember($project, $member, ProjectRole::Member);
+
+    return $member;
+}
+
+/** 詳細画面 props の render 配下を取り出す */
+function finishedVideoRenderProps(User $actor, Project $project, VideoManual $manual): array
+{
+    $props = [];
+    test()->actingAs($actor)
+        ->get("/projects/{$project->id}/manuals/{$manual->id}")
+        ->assertOk()
+        ->assertInertia(function (AssertableInertia $page) use (&$props): void {
+            /** @var array<string, mixed> $render */
+            $render = $page->toArray()['props']['render'];
+            $props = $render;
+        });
+
+    return $props;
+}
+
+/* ---------------- playback (kind=render) ---------------- */
+
+test('playback: published + 最新 succeeded render は 302 (S3 署名 URL へ redirect)', function (): void {
+    [, $owner, $project, $manual] = finishedVideoContext();
+    $key = "projects/{$project->id}/manuals/{$manual->id}/renders/v2-1.mp4";
+    $job = RenderJob::factory()->forManual($manual)->succeeded($key)->create();
+
+    $this->actingAs($owner)->get(
+        "/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}/playback",
+    )->assertRedirect("https://signed.example/{$key}");
+});
+
+test('playback: published でない manual の完成動画は 404 (ready へ戻った旧完成動画も 404)', function (): void {
+    [, $owner, $project, $manual] = finishedVideoContext();
+    $job = RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v2-1.mp4')->create();
+    // シナリオ編集で ready へ戻ると完成動画は受け取れなくなる (download と同一条件)
+    $manual->forceFill(['status' => VideoManualStatus::Ready])->save();
+
+    $this->actingAs($owner)->get(
+        "/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}/playback",
+    )->assertNotFound();
+});
+
+test('playback: 旧世代 render は 404 (実体削除済みの世代へ署名 URL を出さない)', function (): void {
+    [, $owner, $project, $manual] = finishedVideoContext();
+    $old = RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v1-1.mp4')->create();
+    RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v2-1.mp4')->create();
+
+    $this->actingAs($owner)->get(
+        "/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$old->id}/playback",
+    )->assertNotFound();
+});
+
+test('playback: 最新 succeeded render の output_path が NULL なら旧世代へフォールバックせず 404', function (): void {
+    [, $owner, $project, $manual] = finishedVideoContext();
+    $old = RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v1-1.mp4')->create();
+    RenderJob::factory()->forManual($manual)->create(['status' => 'succeeded', 'output_path' => null]);
+
+    $this->actingAs($owner)->get(
+        "/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$old->id}/playback",
+    )->assertNotFound();
+});
+
+test('playback: queued / running / failed の render は 404', function (): void {
+    [, $owner, $project, $manual] = finishedVideoContext();
+    $base = "/projects/{$project->id}/manuals/{$manual->id}/render-jobs";
+
+    $queued = RenderJob::factory()->forManual($manual)->create();
+    $running = RenderJob::factory()->forManual($manual)->running()->create();
+    $failed = RenderJob::factory()->forManual($manual)->failed()->create();
+
+    $this->actingAs($owner)->get("{$base}/{$queued->id}/playback")->assertNotFound();
+    $this->actingAs($owner)->get("{$base}/{$running->id}/playback")->assertNotFound();
+    $this->actingAs($owner)->get("{$base}/{$failed->id}/playback")->assertNotFound();
+});
+
+test('playback: 撮影者は 403 (download ability = 編集者専用。層 2 の 404 より後に評価される)', function (): void {
+    [$organization, , $project, $manual] = finishedVideoContext();
+    $member = finishedVideoMember($organization, $project);
+    $job = RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v2-1.mp4')->create();
+
+    $this->actingAs($member)->get(
+        "/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}/playback",
+    )->assertForbidden();
+});
+
+test('playback: cross-org / cross-manual の完成動画 job は 404 (存在オラクル封じ)', function (): void {
+    [, $owner, $project, $manual] = finishedVideoContext();
+    $job = RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v2-1.mp4')->create();
+    [, $stranger] = createOrganizationWithOwner('別組織');
+
+    // cross-org (他組織の利用者からは存在ごと見えない)
+    $this->actingAs($stranger)->get(
+        "/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}/playback",
+    )->assertNotFound();
+
+    // cross-manual (同 project 内の別マニュアルの job id を差し込んでも 404)
+    $otherManual = VideoManual::factory()->forProject($project)->create([
+        'status' => VideoManualStatus::Published->value,
+    ]);
+    $this->actingAs($owner)->get(
+        "/projects/{$project->id}/manuals/{$otherManual->id}/render-jobs/{$job->id}/playback",
+    )->assertNotFound();
+});
+
+test('playback: kind=preview の 302 条件と ability は本変更で変わらない (回帰の明示固定)', function (): void {
+    [$organization, $owner, $project, $manual] = finishedVideoContext();
+    // preview は published を要求しない (ready のままでも再生できる)
+    $manual->forceFill(['status' => VideoManualStatus::Ready])->save();
+    $key = "projects/{$project->id}/manuals/{$manual->id}/previews/v2-1.mp4";
+    $preview = RenderJob::factory()->forManual($manual)->preview()->succeeded($key)->create();
+    $url = "/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$preview->id}/playback";
+
+    $this->actingAs($owner)->get($url)->assertRedirect("https://signed.example/{$key}");
+
+    // 撮影者は 403 (render ability = 編集者専用)
+    $member = finishedVideoMember($organization, $project);
+    $this->actingAs($member)->get($url)->assertForbidden();
+});
+
+/* ---------------- download (選択式の載せ替え) ---------------- */
+
+test('download: 最新 succeeded render の output_path が NULL なら旧世代へフォールバックせず 404', function (): void {
+    [, $owner, $project, $manual] = finishedVideoContext();
+    RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v1-1.mp4')->create();
+    RenderJob::factory()->forManual($manual)->create(['status' => 'succeeded', 'output_path' => null]);
+
+    $this->actingAs($owner)->get(
+        "/projects/{$project->id}/manuals/{$manual->id}/download",
+    )->assertNotFound();
+});
+
+/* ---------------- 詳細画面 props (finishedJob) ---------------- */
+
+test('props: published + download 権限保持者には finishedJob が最新 succeeded render を指す', function (): void {
+    [, $owner, $project, $manual] = finishedVideoContext();
+    RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v1-1.mp4')->create();
+    $latest = RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v2-1.mp4')->create();
+
+    $render = finishedVideoRenderProps($owner, $project, $manual);
+
+    expect($render['finishedJob'])->not->toBeNull();
+    expect($render['finishedJob']['id'])->toBe($latest->id);
+    expect($render['finishedJob']['kind'])->toBe('render');
+});
+
+test('props: ready へ戻った manual では finishedJob=null (押すと 404 になる導線を出さない)', function (): void {
+    [, $owner, $project, $manual] = finishedVideoContext();
+    RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v2-1.mp4')->create();
+    $manual->forceFill(['status' => VideoManualStatus::Ready])->save();
+
+    expect(finishedVideoRenderProps($owner, $project, $manual)['finishedJob'])->toBeNull();
+});
+
+test('props: 詳細を閲覧できるが download 権限のない撮影者には finishedJob=null', function (): void {
+    [$organization, , $project, $manual] = finishedVideoContext();
+    $member = finishedVideoMember($organization, $project);
+    RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v2-1.mp4')->create();
+
+    expect(finishedVideoRenderProps($member, $project, $manual)['finishedJob'])->toBeNull();
+});
+
+test('props: finishedJob のキー集合は RenderJobData::toArray() と exact 一致 (成果物 URL 系キーを持たない)', function (): void {
+    [, $owner, $project, $manual] = finishedVideoContext();
+    RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v2-1.mp4')->create();
+
+    $finished = finishedVideoRenderProps($owner, $project, $manual)['finishedJob'];
+
+    expect(array_keys($finished))->toBe([
+        'id', 'kind', 'status', 'step', 'progress', 'error', 'error_code',
+        'manual_status', 'placeholder_cut_count',
+    ]);
+    // 本文検査は成果物キーと署名先ホストに限定する (無関係な props を拾って偽陽性にしないため)
+    $body = (string) test()->actingAs($owner)
+        ->get("/projects/{$project->id}/manuals/{$manual->id}")->getContent();
+    expect($body)->not->toContain('output_path');
+    expect($body)->not->toContain('signed.example');
+});
+
+test('props: 最新 succeeded render の output_path が NULL なら finishedJob=null (route と同じ判断)', function (): void {
+    [, $owner, $project, $manual] = finishedVideoContext();
+    RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v1-1.mp4')->create();
+    RenderJob::factory()->forManual($manual)->create(['status' => 'succeeded', 'output_path' => null]);
+
+    expect(finishedVideoRenderProps($owner, $project, $manual)['finishedJob'])->toBeNull();
+});
diff --git a/tests/Feature/Manual/RenderPlaybackAbilityMappingTest.php b/tests/Feature/Manual/RenderPlaybackAbilityMappingTest.php
new file mode 100644
index 0000000..61ff807
--- /dev/null
+++ b/tests/Feature/Manual/RenderPlaybackAbilityMappingTest.php
@@ -0,0 +1,105 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\VideoManualStatus;
+use App\Models\Project;
+use App\Models\RenderJob;
+use App\Models\User;
+use App\Models\VideoManual;
+use App\Policies\VideoManualPolicy;
+use Illuminate\Support\Facades\Gate;
+use Illuminate\Support\Facades\Storage;
+use Tests\Support\Policies\DivergentVideoManualPolicy;
+
+/*
+ * playback の kind→ability 写像そのものを behavioral に固定する (T154)。
+ *
+ *   kind=preview → render ability / kind=render → download ability
+ *
+ * 本番 policy は render と download がどちらも ProjectPolicy::update に落ちるため
+ * **可否が同値で観測差が出ない**。写像を 'render' 固定へ変異させても本番 policy 下では
+ * 全テストが緑のままになる。そこで `Gate::policy()` でテスト専用 policy を差し込み、
+ * ability ごとに可否を分岐させて写像を直接観測する。
+ *
+ * **本番挙動として両者の差が存在するとは言えない** — ここが固定するのは
+ * 「写像が実際に kind で分岐していること」までである (誇張しない)。
+ */
+
+/**
+ * @return array{User, Project, VideoManual, RenderJob, RenderJob}
+ */
+function abilityMappingContext(): array
+{
+    Storage::fake('s3');
+    Storage::disk('s3')->buildTemporaryUrlsUsing(
+        fn (string $path): string => "https://signed.example/{$path}",
+    );
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create([
+        // 完成動画の再生は published を要求するため published で用意する
+        'status' => VideoManualStatus::Published->value,
+        'scenario_version' => 2,
+    ]);
+    $preview = RenderJob::factory()->forManual($manual)->preview()
+        ->succeeded('projects/x/previews/v2-1.mp4')->create();
+    $render = RenderJob::factory()->forManual($manual)
+        ->succeeded('projects/x/renders/v2-1.mp4')->create();
+
+    Gate::policy(VideoManual::class, DivergentVideoManualPolicy::class);
+
+    return [$owner, $project, $manual, $preview, $render];
+}
+
+function abilityMappingPlaybackUrl(Project $project, VideoManual $manual, RenderJob $job): string
+{
+    return "/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}/playback";
+}
+
+afterEach(function (): void {
+    // 残留を実行順に依存させない (Application はテストごとに作り直されるが、それに依存しない)
+    DivergentVideoManualPolicy::reset();
+    Gate::policy(VideoManual::class, VideoManualPolicy::class);
+});
+
+test('写像: download を拒否する policy では kind=render の playback が 403 になる', function (): void {
+    [$owner, $project, $manual, , $render] = abilityMappingContext();
+    DivergentVideoManualPolicy::$allowDownload = false;
+
+    $this->actingAs($owner)->get(abilityMappingPlaybackUrl($project, $manual, $render))
+        ->assertForbidden();
+});
+
+test('写像: download を拒否しても kind=preview の playback は 302 のまま (render ability で通る)', function (): void {
+    [$owner, $project, $manual, $preview] = abilityMappingContext();
+    DivergentVideoManualPolicy::$allowDownload = false;
+
+    $this->actingAs($owner)->get(abilityMappingPlaybackUrl($project, $manual, $preview))
+        ->assertRedirect('https://signed.example/projects/x/previews/v2-1.mp4');
+});
+
+test('写像: render を拒否する policy では kind=preview の playback が 403 になる', function (): void {
+    [$owner, $project, $manual, $preview] = abilityMappingContext();
+    DivergentVideoManualPolicy::$allowRender = false;
+
+    $this->actingAs($owner)->get(abilityMappingPlaybackUrl($project, $manual, $preview))
+        ->assertForbidden();
+});
+
+test('写像: render を拒否しても kind=render の playback は 302 のまま (download ability で通る)', function (): void {
+    [$owner, $project, $manual, , $render] = abilityMappingContext();
+    DivergentVideoManualPolicy::$allowRender = false;
+
+    $this->actingAs($owner)->get(abilityMappingPlaybackUrl($project, $manual, $render))
+        ->assertRedirect('https://signed.example/projects/x/renders/v2-1.mp4');
+});
+
+test('写像: 認可 403 はテナント境界 404 より後 (他組織からは policy 差替えに関係なく 404)', function (): void {
+    [, $project, $manual, , $render] = abilityMappingContext();
+    // policy は両方許可のまま。それでも他組織の利用者には存在が漏れない
+    [, $stranger] = createOrganizationWithOwner('別組織');
+
+    $this->actingAs($stranger)->get(abilityMappingPlaybackUrl($project, $manual, $render))
+        ->assertNotFound();
+});
diff --git a/tests/Feature/Manual/RenderPollingAndArtifactAccessTest.php b/tests/Feature/Manual/RenderPollingAndArtifactAccessTest.php
index e5c3277..b6ffd8d 100644
--- a/tests/Feature/Manual/RenderPollingAndArtifactAccessTest.php
+++ b/tests/Feature/Manual/RenderPollingAndArtifactAccessTest.php
@@ -14,7 +14,8 @@
 /*
  * ポーリングと成果物アクセスの権限分離 (概念設計 §2/§7 Round 1 Critical):
  * - ポーリング (view = 撮影者も可) は進捗のみ。output_path / 署名 URL を一切含めない
- * - playback (render ability) は最新 succeeded preview のみ 302
+ * - playback は preview (render ability) と完成動画 (download ability + published) を扱う。
+ *   preview 側の 404 条件と ability は T154 で**変えていない** (本ファイルがその回帰である)
  * - download (download ability) は published + 最新 succeeded render のみ 302
  */
 
@@ -128,11 +129,11 @@ function artifactAccessMember(Organization $organization, Project $project): Use
     $response->assertRedirect("https://signed.example/{$key}");
 });
 
-test('playback の 404 マトリクス: kind=render / 未完了 / output_path NULL / 旧世代', function (): void {
+test('playback の 404 マトリクス: published でない kind=render / 未完了 / output_path NULL / 旧世代', function (): void {
     [, $owner, $project, $manual] = artifactAccessContext();
     $base = "/projects/{$project->id}/manuals/{$manual->id}/render-jobs";
 
-    // kind=render の succeeded (download route が正)
+    // published でない manual の kind=render succeeded (完成動画の再生条件は download と同一 = T154)
     $renderJob = RenderJob::factory()->forManual($manual)->succeeded('projects/x/renders/v2-1.mp4')->create();
     $this->actingAs($owner)->get("{$base}/{$renderJob->id}/playback")->assertNotFound();
 
diff --git a/tests/Support/Policies/DivergentVideoManualPolicy.php b/tests/Support/Policies/DivergentVideoManualPolicy.php
new file mode 100644
index 0000000..dc84bcb
--- /dev/null
+++ b/tests/Support/Policies/DivergentVideoManualPolicy.php
@@ -0,0 +1,48 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Policies;
+
+use App\Models\User;
+use App\Models\VideoManual;
+
+/**
+ * kind→ability 写像を **behavioral に観測する**ためのテスト専用 policy (T154)。
+ *
+ * 本番の `VideoManualPolicy::render` と `::download` はどちらも `ProjectPolicy::update` に
+ * 落ちるため**可否が完全に同値**で、写像を `'render'` 固定へ変異させても本番 policy 下では
+ * 観測差が出ない。そこで `Gate::policy()` でこの policy を差し込み、
+ * ability ごとに可否を分岐させて写像そのものを固定する。
+ *
+ * **app/ には置かない** (本番経路から到達しないテスト専用の道具)。
+ */
+final class DivergentVideoManualPolicy
+{
+    /** テストが立てる分岐スイッチ (既定は両方許可 = 現行 policy と同挙動) */
+    public static bool $allowRender = true;
+
+    public static bool $allowDownload = true;
+
+    /** 分岐スイッチを既定へ戻す (残留を実行順に依存させない) */
+    public static function reset(): void
+    {
+        self::$allowRender = true;
+        self::$allowDownload = true;
+    }
+
+    public function view(User $user, VideoManual $manual): bool
+    {
+        return true;
+    }
+
+    public function render(User $user, VideoManual $manual): bool
+    {
+        return self::$allowRender;
+    }
+
+    public function download(User $user, VideoManual $manual): bool
+    {
+        return self::$allowDownload;
+    }
+}
diff --git a/tests/Unit/Manual/CurrentRenderArtifactTest.php b/tests/Unit/Manual/CurrentRenderArtifactTest.php
new file mode 100644
index 0000000..fe44cf1
--- /dev/null
+++ b/tests/Unit/Manual/CurrentRenderArtifactTest.php
@@ -0,0 +1,70 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\RenderKind;
+use App\Models\RenderJob;
+use App\Models\VideoManual;
+use App\Services\Manual\CurrentRenderArtifact;
+use App\Services\Manual\RenderJobService;
+
+/*
+ * 「いま受け取れるレンダ成果物はどれか」の唯一の選択式 (T154)。
+ *
+ * 定義は保持ポリシー (RenderJobService::newerSucceededExists / DeleteRenderOutputsJob) と
+ * **同じ世代定義**であり、最新 succeeded の output_path が NULL のときに
+ * 旧世代へフォールバックしない (削除済みオブジェクトの署名 URL を出さないため)。
+ */
+
+test('同 kind の最新 succeeded を返す (kind をまたがない)', function (): void {
+    $manual = VideoManual::factory()->create();
+    RenderJob::factory()->forManual($manual)->succeeded('renders/v1.mp4')->create();
+    $latestRender = RenderJob::factory()->forManual($manual)->succeeded('renders/v2.mp4')->create();
+    $latestPreview = RenderJob::factory()->forManual($manual)->preview()
+        ->succeeded('previews/v3.mp4')->create();
+
+    expect(CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render)?->id)
+        ->toBe($latestRender->id);
+    expect(CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Preview)?->id)
+        ->toBe($latestPreview->id);
+});
+
+test('別 manual の最新 succeeded に引っ張られない (選択の境界は manual × kind)', function (): void {
+    $manual = VideoManual::factory()->create();
+    $own = RenderJob::factory()->forManual($manual)->succeeded('renders/own.mp4')->create();
+
+    $other = VideoManual::factory()->create();
+    RenderJob::factory()->forManual($other)->succeeded('renders/other.mp4')->create();
+
+    expect(CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render)?->id)->toBe($own->id);
+});
+
+test('最新 succeeded の output_path が NULL なら null (旧世代へフォールバックしない)', function (): void {
+    $manual = VideoManual::factory()->create();
+    RenderJob::factory()->forManual($manual)->succeeded('renders/v1.mp4')->create();
+    // 世代交代後に実体が掃除された (DeleteRenderOutputsJob が output_path を CAS で NULL 化) 形
+    RenderJob::factory()->forManual($manual)->create(['status' => 'succeeded', 'output_path' => null]);
+
+    expect(CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render))->toBeNull();
+});
+
+test('succeeded が 1 件も無ければ null (queued / running / failed は選ばない)', function (): void {
+    $manual = VideoManual::factory()->create();
+    RenderJob::factory()->forManual($manual)->create();
+    RenderJob::factory()->forManual($manual)->running()->create();
+    RenderJob::factory()->forManual($manual)->failed()->create();
+
+    expect(CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render))->toBeNull();
+});
+
+test('返した行は保持ポリシーの削除対象ではない (newerSucceededExists が false)', function (): void {
+    $manual = VideoManual::factory()->create();
+    RenderJob::factory()->forManual($manual)->succeeded('renders/v1.mp4')->create();
+    RenderJob::factory()->forManual($manual)->succeeded('renders/v2.mp4')->create();
+
+    $current = CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render);
+
+    expect($current)->not->toBeNull();
+    // 選択式と保持ポリシーの世代定義が一致すること (選んだ行の実体は消されない)
+    expect(app(RenderJobService::class)->newerSucceededExists($current))->toBeFalse();
+});
diff --git a/tests/js/components/features/manual/RenderPanel.test.ts b/tests/js/components/features/manual/RenderPanel.test.ts
index e584a7c..7e83c78 100644
--- a/tests/js/components/features/manual/RenderPanel.test.ts
+++ b/tests/js/components/features/manual/RenderPanel.test.ts
@@ -33,10 +33,24 @@ const baseProps = {
     job: null,
     previewJob: null,
     playbackJob: null,
+    finishedJob: null,
     coverage: { total_cuts: 1, missing_count: 0, missing_labels: [] },
     canManage: true,
 };
 
+/** 受け取れる完成動画 (サーバが published + download ability + 現行世代を判定済み) */
+function finishedJobBody(overrides: Partial<RenderJobProps> = {}): RenderJobProps {
+    return renderJobBody({
+        id: 77,
+        kind: "render",
+        status: "succeeded",
+        progress: 100,
+        manual_status: "published",
+        placeholder_cut_count: 0,
+        ...overrides,
+    });
+}
+
 function renderJobBody(overrides: Partial<RenderJobProps> = {}): RenderJobProps {
     return {
         id: 9,
@@ -167,13 +181,100 @@ describe("RenderPanel", () => {
         expect(screen.getByTestId("render-step-label")).toHaveTextContent("カットを合成中");
     });
 
-    it("published + canManage はダウンロード導線を表示する", () => {
+    it("finishedJob があると完成動画プレイヤーと DL ボタンの両方が出る", () => {
         render(RenderPanel, {
-            props: { ...baseProps, manualStatus: "published" as const },
+            props: {
+                ...baseProps,
+                manualStatus: "published" as const,
+                finishedJob: finishedJobBody(),
+            },
         });
 
-        const link = screen.getByTestId("download-button");
-        expect(link).toHaveAttribute("href", "/projects/1/manuals/5/download");
+        expect(screen.getByTestId("final-video-block")).toBeInTheDocument();
+        expect(screen.getByTestId("final-video")).toBeInTheDocument();
+        expect(screen.getByTestId("download-button")).toHaveAttribute(
+            "href",
+            "/projects/1/manuals/5/download",
+        );
+    });
+
+    it("完成動画プレイヤーの src は playback route を job id 込みで指す (再レンダで URL が変わる)", () => {
+        render(RenderPanel, {
+            props: {
+                ...baseProps,
+                manualStatus: "published" as const,
+                finishedJob: finishedJobBody({ id: 91 }),
+            },
+        });
+
+        expect(screen.getByTestId("final-video")).toHaveAttribute(
+            "src",
+            "/projects/1/manuals/5/render-jobs/91/playback",
+        );
+        expect(screen.getByTestId("final-video")).toHaveAttribute("preload", "none");
+    });
+
+    it("finishedJob があれば canManage=false でも完成動画ブロックは出る (表示条件は finishedJob だけ)", () => {
+        // サーバが渡した成果物を UI が独自条件で隠さないことの固定。
+        // 現行 props ではこの組合せは発生しない (props が download ability を評価済み) が、
+        // component 単体の契約として作為的に与える。
+        render(RenderPanel, {
+            props: {
+                ...baseProps,
+                manualStatus: "published" as const,
+                canManage: false,
+                finishedJob: finishedJobBody(),
+            },
+        });
+
+        expect(screen.getByTestId("final-video-block")).toBeInTheDocument();
+        expect(screen.getByTestId("download-button")).toBeInTheDocument();
+    });
+
+    it("finishedJob が null なら完成動画プレイヤーも DL ボタンも出ない (published でも)", () => {
+        // 「押すと 404」の導線を UI から消したことの固定
+        render(RenderPanel, {
+            props: { ...baseProps, manualStatus: "published" as const, finishedJob: null },
+        });
+
+        expect(screen.queryByTestId("final-video-block")).not.toBeInTheDocument();
+        expect(screen.queryByTestId("final-video")).not.toBeInTheDocument();
+        expect(screen.queryByTestId("download-button")).not.toBeInTheDocument();
+    });
+
+    it("完成動画には黒背景の注記を出さない (placeholder_cut_count=0 / null の両方)", () => {
+        // succeeded render の値契約は 0 (T148)。完成動画用の注記分岐は新設していない。
+        const { unmount } = render(RenderPanel, {
+            props: {
+                ...baseProps,
+                manualStatus: "published" as const,
+                finishedJob: finishedJobBody({ placeholder_cut_count: 0 }),
+            },
+        });
+        expect(screen.queryByTestId("preview-placeholder-note")).not.toBeInTheDocument();
+        unmount();
+
+        render(RenderPanel, {
+            props: {
+                ...baseProps,
+                manualStatus: "published" as const,
+                finishedJob: finishedJobBody({ placeholder_cut_count: null }),
+            },
+        });
+        expect(screen.queryByTestId("preview-placeholder-note")).not.toBeInTheDocument();
+    });
+
+    it("書き出し中 (rendering) は完成動画ブロックを描画しない", () => {
+        render(RenderPanel, {
+            props: {
+                ...baseProps,
+                manualStatus: "rendering" as const,
+                finishedJob: finishedJobBody(),
+            },
+        });
+
+        expect(screen.getByTestId("render-progress")).toBeInTheDocument();
+        expect(screen.queryByTestId("final-video-block")).not.toBeInTheDocument();
     });
 
     it("render failed はエラーを表示する", () => {
diff --git a/tests/js/pages/ManualsShow.test.ts b/tests/js/pages/ManualsShow.test.ts
index 09c0a5a..c915e41 100644
--- a/tests/js/pages/ManualsShow.test.ts
+++ b/tests/js/pages/ManualsShow.test.ts
@@ -17,6 +17,7 @@ const baseProps = {
         job: null,
         previewJob: null,
         playbackJob: null,
+        finishedJob: null,
         coverage: { total_cuts: 1, missing_count: 0, missing_labels: [] },
     },
     canManage: true,
@@ -174,6 +175,7 @@ describe("Manuals/Show", () => {
                         manual_status: "ready" as VideoManualStatus,
                         placeholder_cut_count: 2,
                     },
+                    finishedJob: null,
                     coverage: { total_cuts: 3, missing_count: 2, missing_labels: ["手順2", "手順3"] },
                 },
             },
@@ -187,4 +189,35 @@ describe("Manuals/Show", () => {
         );
         expect(screen.getByTestId("preview-placeholder-note")).toHaveTextContent("2");
     });
+
+    // --- T154: 完成動画の props 配線 ---
+
+    it("D-10: render.finishedJob が RenderPanel へそのまま渡る", () => {
+        render(Show, {
+            props: {
+                ...baseProps,
+                manual: { ...baseProps.manual, status: "published" as VideoManualStatus },
+                render: {
+                    ...baseProps.render,
+                    finishedJob: {
+                        id: 44,
+                        kind: "render" as const,
+                        status: "succeeded" as const,
+                        step: null,
+                        progress: 100,
+                        error: null,
+                        error_code: null,
+                        manual_status: "published" as VideoManualStatus,
+                        placeholder_cut_count: 0,
+                    },
+                },
+            },
+        });
+
+        expect(screen.getByTestId("final-video")).toHaveAttribute(
+            "src",
+            "/projects/1/manuals/5/render-jobs/44/playback",
+        );
+        expect(screen.getByTestId("download-button")).toBeInTheDocument();
+    });
 });

```

---

## design system 参照

### DESIGN.md の関連 token 抜粋

- colors: `neutral: #F4F4F5` (tailwind `bg-neutral`) / `text-secondary: #52525B` (`text-text-secondary`)
- rounded: `md: 6px` (tailwind `rounded-md`)
- typography ランプ: `body` / `caption` (tailwind `text-body` / `text-caption`)
- 本差分が追加した class は既存 utility の組み合わせのみ:
  `w-full` / `rounded-md` / `bg-neutral` / `mt-4` / `flex flex-col gap-3`。
  **hex 直書き・新規 token の追加は無い** (`resources/css/tokens.css` は差分に含まれない)。
- アイコンは既存の `@lucide/svelte` の `Download` のみ (SVG 直書きの追加なし)。

### 触れた atomic ディレクトリ構造

```
resources/js/components/features/manual/
├── AnalysisPanel.svelte
├── DuplicateManualDialog.svelte
├── RenderPanel.svelte      ← 変更 (features 層。import 先は atoms/Card, atoms/Button, atoms/Alert, atoms/TextLink, organisms/ConfirmDialog)
├── ScenarioEditor.svelte
├── SourceDocumentUpload.svelte
└── insufficient-tickets.ts
resources/js/pages/Manuals/Show.svelte   ← 変更 (pages 層。features へ props を渡すのみ)
resources/js/types/manual.ts             ← 変更 (RenderProps に finishedJob を追加)
```

import 方向は `pages → features → organisms/atoms` の単方向のまま (新規 component は作っていない)。

---

## テスト結果

```
$ composer phpstan                       → [OK] No errors (level 10, 894 files)
$ vendor/bin/pint --test                 → passed
$ composer test                          → tests=4494 passed=4492 skipped=2 failed=0
$ pnpm lint / pnpm typecheck             → 0 errors
$ pnpm test (vitest)                     → 130 files / 1322 tests passed
$ pnpm build                             → built
$ pnpm typecheck:packages / build:packages / test:packages → passed (106 tests)
$ composer test:browser                  → chromium 25 passed (3 skipped) / webkit 25 passed (3 skipped)
   うち新規 tests/Browser/FinishedVideoPlaybackTest.php の E-1/E-2/E-3 は両レーンで green
```

### fail-first の記録

1. gate (施策 6) を先に置いた時点で赤: 変更前の母集団 5 ファイルのうち controller 3 本が「未登録」、
   `CurrentRenderArtifact.php` が「stale」で 2 ケース fail。
2. Feature テストを先に置いた時点で赤: 19 tests / 4 failed + 5 errors
   (`Undefined array key "finishedJob"` × 5、302/404/403 の不一致 × 4)。

### mutation 表の実施結果 (すべて元へ戻し済み)

| # | 変異 | 実測 |
|---|---|---|
| M1 | `VideoManualController::show()` に旧クエリを書き戻す | gate ケース 2 (未登録) が赤 |
| M1' | 同じ変異の文字列リテラル版 (`->where('status', 'succeeded')`) | 同上 (status 群の文字列経路が効いている) |
| M2 | inventory から `RenderJobService` の entry を削除 | gate ケース 2 が赤 |
| M3 | inventory に実在しないパスの entry を足す | gate ケース 3 (stale) が赤 |
| M4 | 走査根を差し替える | **予測とずれた** (下記) |
| M5 | `newerSucceededExists()` を `latest('id')` 形へ | gate ケース 6 (前提) が赤 |
| M6 | `playback()` の published 判定を削除 | Feature「published でない manual の完成動画は 404」が赤 |
| M7 | ability 写像を `'render'` 固定 | mapping 2 件が赤 |
| M7' | ability 写像を `'download'` 固定 | mapping 2 件が赤 |
| M8 | `CurrentRenderArtifact` に `whereNotNull('output_path')` を足す | Unit 1 + Feature 3 の計 4 件が赤 |
| M9 | props の `download` ability 判定を外す | Feature「撮影者には finishedJob=null」が赤 |
| M10 | `RenderPanel` の表示条件を `status === "published"` へ戻す | vitest「finishedJob が null なら出ない」が赤 |
| M11 | 表示条件に `&& canManage` を足す | vitest「canManage=false でも出る」が赤 |

**M4 のずれ (辻褄を合わせずに記録)**: 設計は「走査根を存在しないディレクトリへ差し替える →
『母集団が空でない』が赤」と予測したが、実測では `appDir()` が `realpath()` の false を検出して
`RuntimeException` を投げるため assert に到達せず**例外で赤**になる。負のコントロールを実際に
観測するため走査根を**実在するが対象外のディレクトリ** (`app/Providers`) へ差し替えたところ、
ケース 1 (母集団が空でない) が期待どおり赤になった。ただし同時にケース 3 / ケース 6 も落ちる。
また「実在の別ディレクトリ (`app/Services/Manual`)」へ差し替えた場合は母集団が空にならず
ケース 1 は緑のままだった (負のコントロールは走査根が壊れたすべての形を捉えるわけではない)。

---

## レビューしてほしい点 (特に)

1. 完成動画の再生条件が download と**完全同一** (published + 現行世代 + download ability + 同じ評価順序) に
   なっているか。認可を緩めていないか。
2. preview 側の 404 条件と ability が 1 ビットも変わっていないか。
3. Architecture gate が**偽グリーンにならない**設計になっているか (走査規則・前提検査・exact-fit)。
   検出できない範囲を過大に主張していないか (ドキュメントの「保証しないもの」の記述の妥当性)。
4. props (`finishedJob`) と endpoint の条件が 1 対 1 であること、UI が独自条件を積んでいないこと。
5. テスト専用 policy (`Tests\Support\Policies\DivergentVideoManualPolicy`) の差し込みと後始末が
   他テストへ漏れないか (`--parallel` 実行下での安全性を含む)。
