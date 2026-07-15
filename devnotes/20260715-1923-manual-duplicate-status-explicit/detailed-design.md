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
- 併せて「source manual は lockForUpdate 済みで read/copy の一貫性を確保している」旨を 1 文補強
  (監査性向上。Codex 提案)。

**status 代入値は enum インスタンス `VideoManualStatus::Draft`** を使う (コードベースの status
書き込みは全て enum インスタンス流儀。ScenarioService/RenderJobService/AnalysisJobService に
`->value` を使う箇所は無く、cast 済みで enum を forceFill するのが canonical)。

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

## 施策3: 回帰テスト追加 (振る舞い + 明示代入契約)

### 変更箇所
- `tests/Feature/Projects/ManualDuplicateTest.php` にテスト 2 件追加
  (import に `use App\Services\Manual\VideoManualService;` を追加)

### 新規テスト 3-b: 明示代入の契約テスト (fail-first・機械的保証)
振る舞いテスト (3-a) は DB default により実装前でも成功してしまい「テスト先行 fail」と
「明示代入の削除検出」を満たさない (Codex 指摘)。そこで `duplicate()` の**メソッドソースを
Reflection で取得**し、status/scenario_version の明示代入が存在することを機械的に要求する
契約テストを追加する (実装前は fail、明示代入を消すと fail = 本件の目的を直接守る)。
`use Webmozart\Assert\Assert;` を test ファイルに追加 (型 narrowing で PHPStan L10 適合)。
```php
test('duplicate() は複製 manual の status/scenario_version を明示代入する (DB default 非依存の契約)', function (): void {
    $method = new ReflectionMethod(VideoManualService::class, 'duplicate');
    // getFileName/getStartLine/getEndLine は int|string|false のため Assert で narrow する
    $fileName = $method->getFileName();
    $startLine = $method->getStartLine();
    $endLine = $method->getEndLine();
    Assert::string($fileName);
    Assert::integer($startLine);
    Assert::integer($endLine);
    $lines = file($fileName);
    Assert::isArray($lines);
    $body = implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));

    // 新規行の初期値を DB default に委ねず、enum/0 を明示代入していること
    expect($body)->toContain("'status' => VideoManualStatus::Draft");
    expect($body)->toContain("'scenario_version' => 0");
});
```

### 新規テスト 3-a: 振る舞い回帰テスト
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
    // created_by は複製実行者由来 (duplicate の契約を明文化)
    expect($copy->created_by)->toBe($owner->id);
    // 元 manual は不変 (複製は元を書き換えない)
    $source->refresh();
    expect($source->status)->toBe(VideoManualStatus::Rendering);
    expect($source->scenario_version)->toBe(9);
});
```

### テスト計画
- [x] 既存テスト「複製先は status=draft・scenario_version=0...」(L84) は維持 (削除しない)。
- [x] 3-a 振る舞い / 3-b 契約 の 2 件を追加。Factory 生成。個別 DatabaseTransactions 不使用。
- [x] 3-b は fail-first: 実装前 (明示代入なし) に fail することを確認してから施策1 を実装する。
- [x] `VideoManualStatus` は既に use 済み (テストファイル L6)。`VideoManualService` の use を追加。

### リスク
- 3-a (振る舞い) は DB default 依存でも同値 (Draft/0) になるため実装前でも pass する
  → これを補うのが 3-b (契約テスト。明示代入の存在を Reflection で機械的に要求し fail-first)。
- 3-b はソース文字列検査のため書式変更に弱いが、Pint 整形後の `'key' => value` 形は安定。
  status/scenario_version の明示代入は duplicate() 内 1 箇所のため誤検出リスクは低い。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 単一 Service メソッド + 対応する Architecture/Feature テストに閉じる小規模 backend 変更。 |
| 競合リスク | なし (他 2 件は frontend / 別領域で独立) |
