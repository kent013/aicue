# Round 2: Round 1 指摘への対応と再レビュー依頼

## Claude 側の対応マトリクス

# 対応マトリクス: design-review Round 1

## [Warning] 施策 2: kind→ability 写像が実質未テスト (M7 が赤にならない)
- 判断: **対応する**(trip-wire を廃し、写像を直接固定する形へ差し替え)
- 根拠: 指摘が正しい。本施策の中核契約が回帰テストで固定されていないのは
  「テストなしの実装完了」に近い。Gate spy より policy 差し替えの方が
  「写像が実際の認可経路で効く」ことを end-to-end で観測できる。
- 対応内容: `tests/Feature/Manual/RenderPlaybackAbilityMappingTest.php` +
  `tests/Support/Policies/DivergentVideoManualPolicy.php` を新設し、
  `Gate::policy()` で render / download を非対称にした 4 ケース + 層 2 先行の 1 ケースを固定。
  mutation 表の M7 を「赤になる」へ更新し、M7'(逆写像)も追加。
  `RenderPlaybackAbilityParityTest`(同値監視の trip-wire)は**作らない**
  (写像を直接固定できる以上、同値監視は冗長 = 思考原則 2)。
  「保証しないもの」も「behavioral に固定できない」→「本番 policy の差は今は存在しない」へ書き換え。

## [Warning] 施策 5: `finishedJob !== null && canManage` が設計文と矛盾
- 判断: **対応する**(推奨案 = `canManage` を積まない)
- 根拠: 指摘が正しい。props が既に `download` ability を評価しているのに UI で
  `update` ability を再度積むと、「判断は props で 1 回」という主張が嘘になる。
- 対応内容: 完成動画ブロックの条件を `{#if finishedJob !== null}` に変更。
  vitest に「canManage=false でも完成動画ブロックは出る」を追加し、M11(条件に canManage を足す)を
  mutation 表へ追加。現行 props ではこの組合せが発生しないことも設計に明記した。

## [Warning] 施策 6: gate の不変条件表現が検出条件より強い
- 判断: **対応する**(両方 = 検出強化 + 表現の弱化)
- 根拠: 「守る不変条件」と「保証しないもの」の粒度がずれているという指摘は妥当。
  文字列リテラル経路は実際に安価に塞げるので塞ぎ、塞げない部分は表現を弱める。
- 対応内容:
  - 母集団マーカーを拡張: status 群 = `JobStatus::Succeeded` **または** `'succeeded'`、
    query 根群 = `renderJobs(` / `RenderJob::`(静的呼び出し全般) / `'render_jobs'`。
    `git grep` で実測し、拡張しても母集団は 5 ファイルのまま(偽陽性 0)であることを確認済み。
  - mutation に M1'(文字列リテラル版の書き戻し)を追加。
  - 不変条件の文言を「inventory 登録ファイルだけが直接クエリを書ける。Canonical は 1 ファイル」へ
    修正し、ファイル粒度・同一ファイル内メソッド追加・動的経路に沈黙することを明記。

## [Warning] 施策 7: ドキュメントの保証文を修正後に合わせよ
- 判断: **対応する**
- 対応内容: `docs/architecture.md` / `AGENTS.md` に書く文言を
  「テスト専用 policy で写像を固定。本番 policy は現在同値」に統一すると設計へ明記。

## [Suggestion] 施策 1: 別 manual に引っ張られないケースを Unit に足す
- 判断: **対応する**(テストケースを 1 本追加)

## [Suggestion] 施策 4: props に output_path / URL 系キーが無いことを exact に確認する
- 判断: **対応する**(キー集合の exact 一致 + 本文の文字列非出現を確認するケースへ書き換え)


---

## 修正後の詳細設計書 (全文)

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
     *   1. {project} ∈ current org … project.in-route-org middleware + inline guard
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

テスト側は `Gate::policy(VideoManual::class, DivergentVideoManualPolicy::class)` で差し替え、
`afterEach` で静的スイッチを既定へ戻す(`RefreshDatabase` はグローバル適用のまま。
`--parallel` でもプロセス内の静的値をテストごとに初期化するので競合しない)。

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
      — 応答本文に `output_path` / `signed` / `https://` が現れないことも併せて確認する
      (既存 `RenderPollingAndArtifactAccessTest` のポーリング権限分離テストと同じ手法)
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
5. inventory を最終形へ更新し、gate を緑にする。M1-M10 の mutation を実行し記録する
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

各施策の判定 (APPROVE / REQUEST_CHANGES) と全体判定 (APPROVED / CHANGES_REQUESTED) を出せ。
残る指摘は [Critical] [Warning] [Suggestion] に分類し、修正案を添えること。
