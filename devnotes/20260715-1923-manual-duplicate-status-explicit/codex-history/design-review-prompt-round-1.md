【使命】AI-CUE: SOP 起点に AI が動画シナリオ生成、PWA 撮影で標準化マニュアル動画。思考ゼロ・編集ゼロ。
【禁止事項】1 テストなし完了禁止 / 2 PHPStan widen 禁止 / 4 response()->json 直書き禁止 / 5 DatabaseTransactions 個別禁止。
【ドメイン規約】シナリオ整合の共有ロック規約: cuts/scenario_version/status を書く経路は対象 VideoManual を lockForUpdate した同一 tx 内で反映。ScenarioWritePathInventoryTest (deny-by-default 静的走査) が inventory を強制。
【ツール制限】コマンド実行・書き込み禁止。読み込み可。
---
あなたは Laravel + Svelte アーキテクトです。詳細設計をレビューしてください。前提: PHP8.4/Laravel12/PHPStan L10/Pest/DTO+JsonResource/Laratrust。観点: 1 正確性 2 既存整合 3 PHPStan L10 4 テスト網羅 5 DTO/JsonResource 6 Inertia vs API 7 副作用/後退 8 波及変更網羅 9 セキュリティ(ロック規約含む) 10 DESIGN.md 11 Atomic(該当時)。出力: 施策ごと APPROVE/REQUEST_CHANGES、[Critical]/[Warning]/[Suggestion]、Critical/Warning に修正案、全体判定 APPROVED/CHANGES_REQUESTED、日本語。
---

## 詳細設計書
# 詳細設計: manual-duplicate-status-explicit

## 使命・制約（絶対遵守）

### アプリの使命（North Star）
AI-CUE は現場の作業手順書(SOP)を起点に AI が動画シナリオを生成し、PWA でナビゲーション
撮影して標準化マニュアル動画を作る。思考ゼロ・編集ゼロ。

### 禁止事項（関連）
- #1 テストなしの実装完了 / #2 PHPStan widen・baseline / #5 DatabaseTransactions 個別使用禁止
- ドメイン規約: シナリオ整合の共有ロック規約 + ScenarioWritePathInventoryTest の inventory 登録

### コーディングルール
- PHPStan level 10 / Pest / RefreshDatabase グローバル / Factory 生成
- `declare(strict_types=1)` + 日本語コメント。保護キーは forceFill / relation で明示代入。
- PHP 8.4 + Laravel 12

## 概念設計リファレンス
`devnotes/20260715-1923-manual-duplicate-status-explicit/conceptual-design.md`(APPROVED)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | duplicate() で status/scenario_version を明示代入 | `app/Services/Manual/VideoManualService.php` | Low |
| 2 | Architecture inventory 更新 | `tests/Architecture/ScenarioWritePathInventoryTest.php` | Low |
| 3 | 回帰テスト追加 | `tests/Feature/Projects/ManualDuplicateTest.php` | Low |

## 施策1: duplicate() で status/scenario_version を明示代入

### 変更箇所
- `app/Services/Manual/VideoManualService.php`
  - import に `use App\Enums\Manual\VideoManualStatus;` を追加
  - `duplicate()` L80-82 の新 manual 生成 forceFill に status/scenario_version を追加
  - `duplicate()` の docblock (L58-70) を実態に合わせて更新

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 施策2 (Architecture)・施策3 (Feature)

### 現行コード
```php
// 新 manual (status/scenario_version は DB default = draft/0)。created_by はサーバ導出
$new = $locked->manuals()->make(['title' => $title]);
$new->forceFill(['created_by' => $userId])->save();
```

### 変更後コード
```php
// 新 manual: status=Draft / scenario_version=0 を明示代入して不変条件をアプリ層で固定する
// (DB default 依存をやめ、将来の migration default 変更による silent break を防ぐ)。
// created_by はサーバ導出。すべて新規行 INSERT 時の初期値 (排他生成 = 並行書き込みなし)。
$new = $locked->manuals()->make(['title' => $title]);
$new->forceFill([
    'created_by' => $userId,
    'status' => VideoManualStatus::Draft,
    'scenario_version' => 0,
])->save();
```

### docblock 更新 (L58-70 付近)
- 「status=draft・scenario_version=0 (いずれも DB default) にリセットする」
  → 「status=Draft・scenario_version=0 を **INSERT 時に明示代入**して不変条件をアプリ層で固定する
  (複製 manual は必ず Draft / version 0 から開始)」
- 「scenario_version / status のリテラル書き込みはしない (新規行は DB default 依存)」
  → 「scenario_version / status は新 manual の INSERT 時に明示代入する (新規行生成のため
  lockForUpdate 前だが、その tx が生成した排他的新規行であり並行書き込みはない)。
  ScenarioWritePathInventoryTest の STATUS_WRITE_ALLOWED / SCENARIO_VERSION_ALLOWED に登録済み」

### PHPStan 適合チェック
- [x] `VideoManualStatus::Draft` は enum、cast (`'status' => VideoManualStatus::class`) 済みで型安全
- [x] `0` は int、`scenario_version` は integer カラム
- [x] 戻り値型 `VideoManual` 不変
- [x] 配列返却なし

### テスト計画
施策3 参照。

### リスク
- Architecture テスト検出 2 が fire する → 施策2 の allowlist 更新で解消 (テスト自身が想定済み)。
- ロック規約: 新規行 INSERT の初期値設定であり既存行への並行書き込みではないため規約趣旨に反しない。

## 施策2: Architecture inventory 更新

### 変更箇所
- `tests/Architecture/ScenarioWritePathInventoryTest.php`
  - `STATUS_WRITE_ALLOWED` (L65-71) に `'Services/Manual/VideoManualService.php'` を追加
  - `SCENARIO_VERSION_ALLOWED` (L60-61) の VideoManualService.php コメントに write 理由を追記
  - docblock の inventory テーブル duplicate 行 (L19-22) を更新

### 変更後コード（要点）
`STATUS_WRITE_ALLOWED`:
```php
private const STATUS_WRITE_ALLOWED = [
    'Services/Manual/ScenarioService.php',
    'Services/Manual/AnalysisJobService.php',
    'Services/Manual/RenderJobService.php',
    // duplicate(): 複製 manual の INSERT 時に status=Draft を明示代入
    // (新規行生成 + 同一 tx。既存行への並行書き込みではない)
    'Services/Manual/VideoManualService.php',
];
```
`SCENARIO_VERSION_ALLOWED` コメント (VideoManualService.php 行):
```php
// T032: stale alert 判定 (displayXxxJob) が manual.scenario_version を read (read-only)。
// + duplicate(): 複製 manual の INSERT 時に scenario_version=0 を明示 write (新規行 + 同一 tx)。
'Services/Manual/VideoManualService.php',
```
docblock テーブル duplicate 行:
```
| VideoManualService::duplicate() | cuts (lockForUpdate 済みの新 manual 経由で作成)。元 manual を
  lockForUpdate して一貫読み取り。複製 manual の INSERT 時に status=Draft / scenario_version=0 を
  明示代入 (新規行生成 = lockForUpdate 前だが排他的新規行・同一 tx 内反映)。検出 1/2 の
  SCENARIO_VERSION_ALLOWED / STATUS_WRITE_ALLOWED に登録済み。検出 4 (adopted_take_id) は非対象 |
```

### PHPStan 適合チェック
- [x] const 配列への文字列追加のみ。型不変。

### テスト計画
- [x] inventory テスト本体 (`findViolations()`) が引き続き全 `[]` で green。
- [x] degenerate PASS 防止テスト・scanner 自己検証テストは不変 (触らない)。

### リスク
- allowlist 追加は deny-by-default の緩和 → docblock で write 理由・ロック整合を明記し監査性を保つ。
  テスト自身の既存コメントが本変更を想定済み = 正規の inventory メンテナンス。

## 施策3: 回帰テスト追加

### 変更箇所
- `tests/Feature/Projects/ManualDuplicateTest.php` にテスト 1 件追加

### 新規テスト
```php
test('複製は元 manual の status/version に関わらず必ず Draft/0 を明示代入し、元 manual は不変', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    // 元 manual を default とは異なる進行状態にする (rendering / version 9)
    $source = VideoManual::factory()->forProject($project)->create([
        'title' => '進行中元',
        'status' => VideoManualStatus::Rendering->value,
        'scenario_version' => 9,
    ]);

    $this->actingAs($owner)->post("/projects/{$project->id}/manuals/{$source->id}/duplicate", [
        'title' => '不変条件確認',
    ])->assertSessionHas('success');

    /** @var VideoManual $copy */
    $copy = $project->manuals()->where('id', '!=', $source->id)->firstOrFail();
    // 複製先は default に依存せず Draft/0
    expect($copy->status)->toBe(VideoManualStatus::Draft);
    expect($copy->scenario_version)->toBe(0);
    // 元 manual は不変 (複製は元を書き換えない)
    $source->refresh();
    expect($source->status)->toBe(VideoManualStatus::Rendering);
    expect($source->scenario_version)->toBe(9);
});
```

### テスト計画
- [x] 既存テスト「複製先は status=draft・scenario_version=0...」(L84) は維持 (削除しない)。
- [x] 新規テストは Factory 生成。個別 DatabaseTransactions 不使用 (グローバル RefreshDatabase)。
- [x] `VideoManualStatus` は既に use 済み (テストファイル L6)。

### リスク
- 振る舞い上は DB default 依存でも同値になるため「明示代入していること」自体を behavioral に
  区別はできない (両者とも Draft/0)。本テストは不変条件の回帰ガード (誰かが明示代入を消し
  かつ default も変えた場合の二重防御) + 元 manual 不変の確認として価値がある。
  「明示代入の強制」は施策2 の静的 inventory (書き込み経路の存在) が担う。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 単一 Service メソッド + 対応する Architecture/Feature テストに閉じる小規模 backend 変更。 |
| 競合リスク | なし (他 2 件は frontend / 別領域で独立) |

## 関連する現行コード（抜粋）

### VideoManualService::duplicate() 現行 (app/Services/Manual/VideoManualService.php L71-98)
DB::transaction 内で project→manual を lockForUpdate、$new = $locked->manuals()->make(['title'=>$title]); $new->forceFill(['created_by'=>$userId])->save(); category 再解決; $lockedNew = 再取得(lockForUpdate); copyCuts($lockedSource,$lockedNew); return $lockedNew。status/scenario_version は現状 DB default 依存。import は CutType/JobStatus/RenderKind (VideoManualStatus 未 import)。

### ScenarioService の status 書き込み流儀 (参考)
$lockedManual->forceFill(['scenario_version' => ..+1, 'status' => VideoManualStatus::Ready])->save();

### migration default (database/migrations/2026_07_10_000100_create_video_manuals_table.php)
$table->string('status')->default('draft'); $table->integer('scenario_version')->default(0);

### ScenarioWritePathInventoryTest 検出2 (status_write)
`'status' => <式に VideoManualStatus 識別子 (::class 除く)>` / `->status = <式に VideoManualStatus>` を STATUS_WRITE_ALLOWED 外ファイルで検出したら fail。現状 allowlist: ScenarioService/AnalysisJobService/RenderJobService。VideoManualService は SCENARIO_VERSION_ALLOWED には既に含まれる (displayXxxJob の read 用)。

### 既存 ManualDuplicateTest (L84) 
「複製先は status=draft・scenario_version=0...」既存テストが Published/7 source から Draft/0 を assert 済み (削除しない)。
