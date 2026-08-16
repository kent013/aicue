# Round 3: Round 2 指摘への対応

Round 2 の Warning 3 件と Suggestion 1 件はすべて対応しました。対応マトリクスと、
修正した節 (施策 G / 施策 I / 完了条件) の全文を送ります。他の節は Round 2 で送った内容から
変更していません (Round 2 で APPROVE 済みのため)。

残る [Critical] [Warning] があれば指摘し、無ければ全体判定 APPROVED を出してください。

## 対応マトリクス

# 対応マトリクス: design-review Round 2

全体判定 CHANGES_REQUESTED。Critical 0 件 / Warning 3 件 / Suggestion 1 件。
Round 1 の主要指摘 (Critical 含む) は解消と評価され、反論 2 件 (paginator の links 不在 /
Capture の category 据え置き) はいずれも**成立**と認められた。

## [Warning] G: `captureProgressOf()` の実装とコメント・テスト期待値が矛盾している

- 判断: **対応する (指摘が正しい。当方の記述ミス)**
- 根拠: 提示した実装は現行の三項式と同じ順序であり、
  `cuts_total=0 && cuts_with_takes>0` は 1 つ目の条件を外れて 2 つ目に掛かるため
  **"capturing" になる**。「未撮影へ倒す」と書いた当方のコメントが実装と食い違っていた。
  2 案 (実装を変える / 記述を実装に合わせる) のうち、**記述を実装に合わせる**方を採る。
  本施策は表示語彙の整理であり、**判定を 1 ビットも変えない**ことが安全性 (回帰ゼロ) の根拠だからである。
  構造上生じない入力のために挙動を変えるのは、本タスクの主張を弱めるだけで利得が無い (思考原則 2)。
- 対応内容: 関数 docblock を「判定順序の帰結」を列挙する形へ書き直し、
  `cuts_total=0 && cuts_with_takes>0` → **撮影中**と明記した。
  「未撮影へ倒す」の記述は削除。直したくなったら別タスクとして起こす、と but 付きで書いた。

## [Warning] I: 上記矛盾により Vitest の期待値が失敗する

- 判断: **対応する** (G と同一原因)
- 対応内容: テスト計画の境界ケースの期待値を **「撮影中」**へ修正し、
  「現行の三項式の帰結そのもの = 挙動を変えていないことの証拠として固定する」と目的を明記した。

## [Warning] 完了条件に packages 系 3 コマンドが欠けている

- 判断: **対応する**
- 根拠: AGENTS.md の検証コマンド一覧は「全 green でコミット」と定めており、
  変更との関係の薄さは免除の理由にならない (`verification-commands-doc-sync` テストが同期を強制している)。
- 対応内容: 完了条件の表に `pnpm typecheck:packages` / `pnpm build:packages` /
  `pnpm test:packages` を追加した。

## [Suggestion] I: 行 payload 契約テストが既定並び順に依存しやすい

- 判断: **対応する**
- 根拠: 妥当。`manuals.data.0` は created_at 降順に依存する。
- 対応内容: 当該テストの fixture を **manual 1 本だけ**にし、
  `title` を併せて検証する形へ書き換えた。

## [その他] Capture の `category` 正規化についての表現の指摘

- 判断: **対応する**
- 根拠: 「どちらも安全側」は曖昧との指摘は妥当 (倒れる方向が逆なので「安全側」の語が 2 つの意味になる)。
- 対応内容: 「倒れる方向は逆だが、**どちらも認可・テナント境界には影響しない既存仕様**」へ書き換えた。


## 修正後の該当節

## G. 撮影 PWA 語彙の明示化と dead payload 撤去

### 変更箇所
- ファイル: `resources/js/types/capture.ts` (`CaptureManualSummary` 付近に導出を追加、`status` を削除)
- ファイル: `resources/js/pages/Capture/Index.svelte` (L122-129 の三項式バッジ)
- ファイル: `app/DataTransferObjects/Capture/CaptureManualSummaryData.php` (`$status` 撤去)
- ファイル: `app/Http/Controllers/Capture/CaptureManualController.php` (**変更なし** — 母集団の
  `whereIn('status', [Ready, Published])` は撮影対象の定義であり表示語彙ではないので触らない)
  - **据え置きの明記** (詳細レビュー Round 1 [Warning] 対応): 同 controller の
    `category` 解析は `(int) $request->string('category')` であり、PC 側 `ManualListQuery` の
    allowlist (数値以外は破棄) と流儀が違う (`'abc'` → `0` = 該当なしへ倒れる)。
    倒れる方向は逆 (PC は「絞り込み無し = 全件」、PWA は「該当なし」) だが、
    **どちらも認可・テナント境界には影響しない既存仕様**である。
    本タスクの対象は**表示語彙**であり、ここに VO を新設するのは別タスク相当のスコープ拡大
    (思考原則 2) なので**据え置く**。

### 波及変更
- TypeScript型定義: `CaptureManualSummary.status` の削除 + `CaptureProgress` の追加
- API Resource/DTO: `CaptureManualSummaryData` の shape PHPDoc
- テストファイル: `tests/Feature/Capture/CaptureManualBrowsingTest.php` (L127 の期待キー一覧から
  `status` を外す) / `tests/js/pages/CaptureIndex.test.ts`

### 現行コード
```svelte
{#if manual.cuts_total > 0 && manual.cuts_adopted === manual.cuts_total}
    <Badge tone="success">撮影完了</Badge>
{:else if manual.cuts_with_takes > 0}
    <Badge tone="tertiary">撮影中</Badge>
{:else}
    <Badge tone="neutral">未撮影</Badge>
{/if}
```
```php
// CaptureManualSummaryData (抜粋) — status は PWA の画面で表示にも分岐にも使われていない
public string $status,
// ...
status: $manual->status->value,
// toArray(): 'status' => $this->status,
```

### 変更後コード
```ts
// resources/js/types/capture.ts
import type { BadgeTone } from "@/components/atoms/Badge.types";

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
```
```svelte
<!-- Capture/Index.svelte (each の中) -->
{@const progress = captureProgressOf(manual)}
<Badge tone={CAPTURE_PROGRESS_TONES[progress]}>{CAPTURE_PROGRESS_LABELS[progress]}</Badge>
```
```php
// CaptureManualSummaryData: public string $status / status: ... / 'status' => ... の 3 箇所と
// toArray() の shape PHPDoc から status を削除する (表示にも分岐にも使われていない dead payload)
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている (`toArray()` の shape PHPDoc から `status` を削除)
- [x] null 安全 (削除のみ)
- [x] DTO を返している
- [x] Generics の型パラメータが正しい

### テスト計画
- [ ] `tests/js/pages/CaptureIndex.test.ts`: 3 状態のバッジ語が **撮影完了 / 撮影中 / 未撮影**のままであること
      (PC 語彙へ寄せる将来変更の回帰封じ)。境界も固定する:
      `cuts_total=0 && cuts_with_takes=0` は「未撮影」/
      **`cuts_total=0 && cuts_with_takes>0` (構造上生じない不整合) は「撮影中」**
      (= 現行の三項式の帰結そのもの。挙動を変えていないことの証拠として固定する) /
      `cuts_adopted < cuts_total` かつ `cuts_with_takes>0` は「撮影中」/
      `cuts_total>0 && cuts_adopted === cuts_total` は「撮影完了」
- [ ] `CaptureManualBrowsingTest`: 行 payload のキー一覧から `status` が消える
- [ ] `pnpm typecheck` で `CaptureManualSummary.status` の参照が 0 件であること

### リスク
- PWA payload の破壊的変更だが、参照している画面コードが無い (実読で確認)。
  テスト側の期待キー一覧のみ追随が要る。

---


## I. 写像テスト新設 + 既存 Feature/Vitest の更新

### 変更箇所
- 新規: `tests/Unit/Manual/ManualProgressMappingTest.php`
- 更新: `tests/Feature/Projects/ProjectShowManualsTest.php` /
  `tests/Feature/Projects/ManualRowActionsTest.php` /
  `tests/Feature/Capture/CaptureManualBrowsingTest.php`
- 更新: `tests/js/pages/ProjectsShow.test.ts` /
  `tests/js/components/features/manual/ManualListRow.test.ts` /
  `tests/js/pages/CaptureIndex.test.ts`

### 波及変更
- なし (テストのみ)

### 現行コード
```php
// ProjectShowManualsTest (抜粋)
test('status フィルタで絞り込める (不正値は無視)', function (): void {
    // ...
    $this->actingAs($owner)->get("/projects/{$project->id}?status=published")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', '公開済み')
            ->where('manualFilters.status', 'published'));

    $this->actingAs($owner)->get("/projects/{$project->id}?status=bogus")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 2)
            ->where('manualFilters.status', null));
});
```

### 変更後コード
```php
// tests/Unit/Manual/ManualProgressMappingTest.php (新規。写像の正本を固定する)
// DB を使わない純粋 enum テストなので Unit レーンに置く (既存の tests/Unit/Manual/ と同じ所在。
// 詳細レビュー Round 1 [Warning] 対応)。Inertia payload / 絞り込み挙動は Feature に残す。
test('制作状態 5 値が一覧の状態 3 値へ写る (写像表)', function (): void {
    expect(ManualProgress::forStatus(VideoManualStatus::Draft))->toBe(ManualProgress::NotStarted)
        ->and(ManualProgress::forStatus(VideoManualStatus::Analyzing))->toBe(ManualProgress::InProgress)
        ->and(ManualProgress::forStatus(VideoManualStatus::Ready))->toBe(ManualProgress::InProgress)
        ->and(ManualProgress::forStatus(VideoManualStatus::Rendering))->toBe(ManualProgress::InProgress)
        ->and(ManualProgress::forStatus(VideoManualStatus::Published))->toBe(ManualProgress::Completed);
});

test('逆写像は漏れなく排他である (和 = 全 status / 重複なし)', function (): void {
    $union = [];
    foreach (ManualProgress::cases() as $progress) {
        foreach ($progress->statuses() as $status) {
            $union[] = $status->value;
        }
    }
    sort($union);
    $all = array_map(static fn (VideoManualStatus $s): string => $s->value, VideoManualStatus::cases());
    sort($all);

    expect($union)->toBe($all)                      // 漏れなし
        ->and(count($union))->toBe(count(array_unique($union))); // 排他
});

test('statusValues() は statuses() の DB 値列と一致する', function (): void {
    foreach (ManualProgress::cases() as $progress) {
        expect($progress->statusValues())->toBe(
            array_map(static fn (VideoManualStatus $s): string => $s->value, $progress->statuses()),
        );
    }
});

test('一覧の状態は 3 値である (doc/04 の 3 値と件数一致)', function (): void {
    expect(ManualProgress::cases())->toHaveCount(3);
});
```
```php
// ProjectShowManualsTest (置換後の骨子)
// fixture は status ごとに固有 title を付ける (件数だけの assertion にしない =
// 詳細レビュー Round 1 [Warning] 対応)。並びは既定 (created_at desc, id desc)。
// '下書き' => Draft / '解析中' => Analyzing / '準備完了' => Ready /
// '書き出し中' => Rendering / '公開済み' => Published の 5 本を Factory で作る。

test('progress=in_progress は analyzing / ready / rendering の 3 件を返す', function (): void {
    $this->actingAs($owner)->get("/projects/{$project->id}?progress=in_progress")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 3)
            ->where('manualFilters.progress', 'in_progress'));

    // 対象の同定は title の集合で行う (件数一致だけに頼らない)
    $titles = collect(data_get($this->response->viewData('page')['props'], 'manuals.data'))
        ->pluck('title')->sort()->values()->all();
    expect($titles)->toBe(['解析中', '書き出し中', '準備完了']);
});

test('progress=not_started は draft のみ / progress=completed は published のみ', function (): void {
    $this->actingAs($owner)->get("/projects/{$project->id}?progress=not_started")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', '下書き'));

    $this->actingAs($owner)->get("/projects/{$project->id}?progress=completed")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', '公開済み'));
});

test('allowlist 外の値と旧 ?status= は無視して全件になる (互換は残さない)', function (): void {
    // 旧 5 値をそのまま渡しても progress の allowlist は通らない
    $this->actingAs($owner)->get("/projects/{$project->id}?progress=ready")
        ->assertInertia(fn (Assert $page) => $page->has('manuals.data', 5)
            ->where('manualFilters.progress', null));

    // **旧 URL の互換は無い** (?status=published は未知キーとして無視される)
    $this->actingAs($owner)->get("/projects/{$project->id}?status=published")
        ->assertInertia(fn (Assert $page) => $page->has('manuals.data', 5)
            ->where('manualFilters.progress', null)
            ->missing('manualFilters.status'));
});

test('行 payload は progress を持ち status を持たない', function (): void {
    // 並び順への依存を避けるため **manual 1 本だけ**の fixture で契約を見る
    // (詳細レビュー Round 2 [Suggestion] 対応)
    $this->actingAs($owner)->get("/projects/{$project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', '下書き')
            ->where('manuals.data.0.progress', 'not_started')
            ->missing('manuals.data.0.status')
            // paginator の query が外に出ないことの構造的確認 (links を props に出していない)
            ->missing('manuals.links'));
});
```

> title 集合の取り出しは実装時に既存テストの流儀へ合わせる
> (`assertInertia` の `has(..., fn (Assert $item) => ...)` で 1 件ずつ書く形でもよい)。
> 要点は「**件数だけでなく対象を同定する**」ことである。

### PHPStan適合チェック
- [x] Factory 経由でテストデータを生成する (`VideoManual::factory()->forProject($project)->create([...])`)
- [x] 個別の `DatabaseTransactions` を使わない (`RefreshDatabase` はグローバル適用)
- [x] `--parallel` 実行と両立する (状態を持たない)

### テスト計画 (このセクション自体がテスト計画)
- [ ] Feature: 上記 4 本 (新規) + 既存 4 ファイルの更新
- [ ] Vitest: `ProjectsShow` (select 3 値 / query キー / 5 値ラベル不在) /
      `ManualListRow` (3 値バッジ + testId) / `CaptureIndex` (PWA 語彙の維持と境界)
- [ ] **fail-first**: 施策 A〜G の実装前に I のテストを書いて赤を確認してから実装する (思考原則 5)
- [ ] 詳細画面 / ダッシュボードの既存テスト (`ManualsShow.test.ts` の「下書き」バッジ /
      `Dashboard.test.ts`) は**無変更で緑**であること (= 5 値語彙を壊していないことの確認)

### リスク
- 一覧の Feature テストの件数期待値 (5 本の manual) を作るため、既存テストのフィクスチャが増える。
  他テストへの影響は無い (テストごとに DB がリセットされる)。

---


## 完了条件 (検証コマンド)

PHP / TypeScript / Svelte / Inertia payload を同時に変えるため、以下が**全て green** で完了とする
(詳細レビュー Round 1 [Warning] 対応。AGENTS.md の検証コマンド一覧のうち本変更に関係するもの)。

| コマンド | 何を守るか |
|---|---|
| `composer test` | 写像テスト (Unit) / 値集合同期 (Architecture) / 一覧・削除着地・撮影一覧の Feature |
| `composer phpstan` | 網羅 match の未処理 case、array shape PHPDoc の整合 (level 10。widen / baseline 化しない) |
| `vendor/bin/pint --test` | PHP の書式 |
| `pnpm lint` | Svelte / TS の lint |
| `pnpm typecheck` | `satisfies` のキー漏れ、`ManualListItem.progress` 参照側の型不整合、`CaptureManualSummary.status` の残存参照 |
| `pnpm test` | Vitest (一覧 3 値 / 撮影 PWA 語彙の維持 / router query のキー) |
| `pnpm build` | 本番ビルドの通過 |
| `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` | リポジトリ規約上の完了条件 (本変更と直接の関係は薄いが、AGENTS.md の検証コマンド一覧は全 green でコミットと定めている) |

テストレーンは**ホスト全体のグローバルロックで直列化**される。待ちが出るのは正常で
30 秒ごとに heartbeat が出る (kill しない / ロックファイルを消さない)。

