【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

【思考原則 — AGENTS.md より】

1. **フレームワークのレンジ内でやる**
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止)
3. **後方互換の並走を残さない**
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。

先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。

仕組みが機能していない段階で値を弄るな。設計の方向性が正しいと確認できてから行え。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク (RefreshDatabase グローバル適用 + --parallel)
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
10. DESIGN.md準拠 / 11. Atomic Design準拠 （本件は UI/frontend 変更を含まないため N/A）

【本件固有の重点】
- これは **pipeline-smoke の初回実走で実際に発生した不具合**の修正設計である（推測ではない）
- 概念設計は conceptual-review Round 3 で APPROVED 済み
- **特に厳しく見てほしい点**:
  (a) 「保証しないもの」に嘘や取りこぼしが無いか（誇張していないか / 逆に過小に書いて
      責任逃れをしていないか）
  (b) fail-first と 3 種の mutation 手順が、実際に**赤を観測できる**手順として成立しているか
  (c) `AGENTS.md`（全エージェントの正本）の改訂案が、既存の要求を弱めていないか
  (d) `create()` の変更で**壊れる既存の振る舞い**を見落としていないか
      （特に Eloquent の forceFill + enum cast + `$manual->category()->associate()->save()` の
       二度目の save、`appendDocument` との相互作用）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: manual-create-initial-state

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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### 関連ドメイン規約

**ドメイン規約 1 (シナリオ整合の共有ロック規約)**: `cuts` / `video_manuals.scenario_version` /
`video_manuals.status` を書き込む全経路は、対象 VideoManual 行を `lockForUpdate()` で取得した
同一トランザクション内で反映する。経路 inventory は `ScenarioWritePathInventoryTest` へ昇格済み。
→ **本設計は施策 5 でこの規約を「更新経路 / 生成経路」の 2 分類に最小改訂する** (理由は概念設計)。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、
  個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨 / `declare(strict_types=1)` + 日本語コメント
- **コードフォーマット**: `composer fix`（Pint）
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- [conceptual-design.md](conceptual-design.md) （APPROVED / conceptual-review Round 3）
- 一次入力: [recon-brief.md](recon-brief.md)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | `create()` の初期状態を明示代入する（原因修正） | `app/Services/Manual/VideoManualService.php` | 最高 |
| 2 | 再現テスト（fail-first）の追加 | `tests/Feature/Projects/ManualServiceBoundaryTest.php` | 最高 |
| 3 | inventory 経路表への `create()` 登録 | `tests/Architecture/ScenarioWritePathInventoryTest.php` | 高 |
| 4 | `docs/architecture.md` 経路表のドリフト是正 + `create()` 追加 | `docs/architecture.md` | 高 |
| 5 | `AGENTS.md` ドメイン規約 1 の 2 分類化（正本整合） | `AGENTS.md` | 高 |

**実装順序は 2 → 1 → 3 → 4 → 5**（テストファースト。施策 2 を先に赤にしてから施策 1 に入る）。

---

## 施策 1: `create()` の初期状態を明示代入する

### 変更箇所

- ファイル: `app/Services/Manual/VideoManualService.php` (L38-57 = `create()` の docblock と本体)

### 波及変更

- TypeScript 型定義: **なし**（Service 内部の初期化のみ。Inertia props / API 形状は不変）
- API Resource / DTO: **なし**（`create()` の戻り値型 `VideoManual` は不変）
- テストファイル: 施策 2 で追加（既存テストの更新は不要 — 後述「既存テストへの影響」）
- Inertia Props: **なし**（`VideoManualController::store()` は戻り値を route param
  (`$manual` の id) にしか使わず、`status` を読まない）

### 現行コード

```php
    /** VideoManual 作成 (status は DB default の draft)。$document は任意の SOP 同時アップロード */
    public function create(Project $project, string $title, ?int $categoryId, int $userId, ?UploadedFile $document = null): VideoManual
    {
        return DB::transaction(function () use ($project, $title, $categoryId, $userId, $document): VideoManual {
            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $manual = $locked->manuals()->make(['title' => $title]);
            $manual->forceFill(['created_by' => $userId])->save();
```

### 変更後コード

```php
    /**
     * VideoManual 作成。$document は任意の SOP 同時アップロード。
     *
     * 新規 manual は必ず status=Draft・scenario_version=0 から開始する (この初期状態を
     * INSERT 時に明示代入し、DB カラム default に依存しない = 将来の migration default 変更による
     * silent break を防ぐ + **戻り値インスタンスが hydrate 済みになる**)。
     * 既定値依存だった頃は `create()` の戻り値の status が null で、
     * pipeline-smoke の fixture 段が `$manual->status->value` で落ちた (実走で観測)。
     *
     * シナリオ整合の共有ロック規約 (AGENTS.md ドメイン規約 1) の **生成経路**:
     *  - 対象 VideoManual 行は未存在のため、所有元 Project 行を lockForUpdate した
     *    同一 tx 内で INSERT する (既存行への並行書き込みではなく、その tx が生成した排他的新規行)
     *  - status / scenario_version は INSERT 時に明示代入する。duplicate() と同一カテゴリで、
     *    ScenarioWritePathInventoryTest の STATUS_WRITE_ALLOWED /
     *    SCENARIO_VERSION_ALLOWED に登録済み (ファイル粒度)
     */
    public function create(Project $project, string $title, ?int $categoryId, int $userId, ?UploadedFile $document = null): VideoManual
    {
        return DB::transaction(function () use ($project, $title, $categoryId, $userId, $document): VideoManual {
            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $manual = $locked->manuals()->make(['title' => $title]);
            // created_by はサーバ導出。status/scenario_version は初期状態の明示代入 (DB default 非依存)
            $manual->forceFill([
                'created_by' => $userId,
                'status' => VideoManualStatus::Draft,
                'scenario_version' => 0,
            ])->save();
```

以降 (`$categoryId` の再解決 / `appendDocument` / `return $manual;`) は**変更しない**。

- `use App\Enums\Manual\VideoManualStatus;` は**既に import 済み** (L10。`duplicate()` が使用) —
  import 追加は不要
- `migration の default (`->default('draft')` / `->default(0)`) は削除しない**（保険として残す）

### 既存テストへの影響（確認済み）

| 呼び出し側 | 戻り値の `status` を読むか | 影響 |
|---|---|---|
| `VideoManualController::store()` | 読まない（route param の id のみ） | なし |
| `PipelineSmokeCommand::runFixtureStage()` | **読む**（本件の被害者） | **修正で解消** |
| `tests/Feature/Projects/VideoManualCrudTest.php` 他 | HTTP 経由 + DB assert | なし |

`status` は enum cast 済みのため `forceFill` に `VideoManualStatus::Draft` を渡すと
保存時に `'draft'` へ直列化される（`duplicate()` の既存実装と同一。挙動実績あり）。
**DB に入る値は現行 (DB default 由来の `'draft'`) と完全に同一**であり、行の内容は変わらない。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`: VideoManual` は不変）
- [x] null 安全（新たな null 参照を作らない。むしろ null を解消する）
- [x] DTO を返している（本メソッドは Model を返す既存契約。変更なし）
- [x] Generics の型パラメータが正しい（`forceFill(array<string, mixed>)`。
      `duplicate()` の同型呼び出しが level 10 を通過済み）

### リスク

- **低**。DB に書かれる値は現行と同一（`'draft'` / `0`）で、行の内容に差分は出ない。
  変わるのは**戻り値インスタンスが該当属性を持つか否か**だけである
- `forceFill` は `$fillable` を迂回するが、`created_by` で既に使っており本メソッドの規約どおり
  （保護キーはサーバ導出値のみ forceFill する）

---

## 施策 2: 再現テスト（fail-first）の追加

### 変更箇所

- ファイル: `tests/Feature/Projects/ManualServiceBoundaryTest.php` （末尾に追加）
- 選定理由: 同ファイルは既に `VideoManualService::create` を**サービス直呼び**で検証している
  （`VideoManualService::create は他 project の categoryId を拒否し manual を残さない`）。
  本件は「サービスの戻り値の契約」であり、HTTP 経由の `VideoManualCrudTest` ではなく
  こちらが適切。**既存テストの削除・上書きはしない（追加のみ）**

### 波及変更

- TypeScript 型定義 / API Resource / DTO: **なし**

### 追加するテストケース（ファイル名 + テストケース名）

`tests/Feature/Projects/ManualServiceBoundaryTest.php`:

1. `test('VideoManualService::create の戻り値は refresh なしで status=Draft・scenario_version=0 を保持する', ...)`
   - **これが再現テスト（fail-first の本体）**
   - 手順: `createOrganizationWithOwner()` → `Project::factory()->forOrganization($organization)->create()`
     → `app(VideoManualService::class)->create($project, 'テスト手順書', null, $owner->id)`
   - assert（**`refresh()` / `fresh()` を挟まない戻り値インスタンスそのもの**に対して）:
     - `expect($manual->status)->toBe(VideoManualStatus::Draft);`（**修正前は null で赤**）
     - `expect($manual->scenario_version)->toBe(0);`（**修正前は null で赤**）
   - 補足 assert（pipeline-smoke が踏んだ経路そのものの再現）:
     - `expect($manual->status->value)->toBe('draft');`
       （修正前は `Attempt to read property "value" on null` で赤 = 実走と同じ症状）

2. `test('VideoManualService::create が INSERT した行は DB 上も status=draft・scenario_version=0 である', ...)`
   - 目的: 「戻り値だけ整えて DB は別値」という**取り違えを防ぐ**（明示代入が DB default と
     一致していることの固定）。migration default を将来変えても本テストが基準になる
   - assert: `$manual->fresh()` に対して `status === VideoManualStatus::Draft` /
     `scenario_version === 0`。加えて `DB::table('video_manuals')->where('id', $manual->id)->value('status')`
     が `'draft'`（**cast を経由しない生値**の確認）
   - ※ この行は `ModelDirectFetchInvariantTest` の対象外（`app/` 走査のみ。テストコードは母集団外）

### fail-first の実行手順（**必ずこの順で行う**）

```bash
# 0. 施策 1 に手を付ける前 (create() は既定値依存のまま) に実行する
composer test -- --filter=ManualServiceBoundaryTest

# 期待: 追加した 1 本目が RED
#   - "Failed asserting that null is identical to VideoManualStatus::Draft" 系、または
#   - ErrorException: Attempt to read property "value" on null   ← 実走と同じ症状
# ここで RED を目視確認してから施策 1 を実装する (思考原則 5 / 禁止事項 1)
```

**赤を確認せずに施策 1 を先に実装した場合、この TODO は「実装済み」にできない**。
その場合は施策 1 を `git stash` で退避して赤を撮り直すこと。

### テスト計画チェック

- [x] バグ修正のため**再現テストを先に書く**（上記手順）
- [x] 既存テストの更新: 不要（追加のみ。削除・上書きなし）
- [x] 新規テスト 2 本 — 戻り値契約 / DB 実値
- [x] 個別の `DatabaseTransactions` を使っていない（`RefreshDatabase` はグローバル適用）
- [x] テストデータは Factory 生成（`Project::factory()` / `createOrganizationWithOwner()`）

### リスク

- `--parallel` 実行での DB 競合は既存 helper と同じ流儀のため新規リスクなし

---

## 施策 3: inventory 経路表への `create()` 登録

### 変更箇所

- ファイル: `tests/Architecture/ScenarioWritePathInventoryTest.php`
  - ファイル冒頭の経路表 docblock（L11-28 付近）に `VideoManualService::create()` の行を追加
  - `SCENARIO_VERSION_ALLOWED` (L49-68) / `STATUS_WRITE_ALLOWED` (L71-80) の
    **コメントを更新**（`create()` も理由に含める）

### 定数（allowlist）は変更しない — 誇張しない事実

`Services/Manual/VideoManualService.php` は `duplicate()` (T066) の時点で
`STATUS_WRITE_ALLOWED` / `SCENARIO_VERSION_ALLOWED` の**両方に登録済み**である。
allowlist は**ファイル粒度**のため、`create()` に write を足しても
**gate は新たに赤くならない**。

したがって施策 3 で実際に行う「登録」は**経路表 docblock への追記**である。
「create() を明示代入に変えたら gate が赤くなったので登録した」という筋書きは**成り立たない**。

### 追加する経路表の行（docblock）

```
 * | VideoManualService::create() | status / scenario_version (生成経路。新規 manual の INSERT 時に
 *   status=Draft / scenario_version=0 を明示代入する。対象 VideoManual 行は未存在のため所有元
 *   Project 行を lockForUpdate した同一 tx 内で INSERT = 既存行への並行書き込みではない。
 *   検出 1 は SCENARIO_VERSION_ALLOWED、検出 2 は STATUS_WRITE_ALLOWED に登録済み (duplicate() と同一ファイル)。
 *   **allowlist はファイル粒度のため create() 単体の検出保証はなく、fail-first を担うのは
 *   ManualServiceBoundaryTest の behavioral 契約テストである** |
```

`duplicate()` の行にも「生成経路」の語を入れて (i)/(ii) の分類語を揃える。

### 既存テスト `T066: VideoManualService に status/scenario_version の明示 write が実在する` について

- **変更しない**。このテストは `containsStatusWrite()` /
  `containsScenarioVersionWrite()` が `VideoManualService.php` 全体に対して真であることを
  見る**ファイル粒度**の契約であり、`create()` の write が増えても真のまま
- **`create()` 単体の fail-first をここで担わせようとしない**。token 走査ではメソッド単位の
  帰属を判定できず、無理に作れば偽陽性を招いて gate の信用を落とす（概念設計の代替案 D と同じ理由）

### 波及変更

- TypeScript 型定義 / API Resource / DTO: **なし**

### PHPStan適合チェック

- [x] コメント変更のみで実行コードの型に影響しない

### リスク

- なし（コメント変更のみ。`composer test -- --filter=ScenarioWritePathInventoryTest` が緑のまま）

---

## 施策 4: `docs/architecture.md` 経路表のドリフト是正 + `create()` 追加

### 変更箇所

- ファイル: `docs/architecture.md` §シナリオ整合の共有不変条件（L215-241 付近）
  - 経路表 (L227-237) の `VideoManualService::duplicate()` 行（**L237 = 現行コードと矛盾**）
  - 経路表に `VideoManualService::create()` 行を追加
  - 冒頭の引用ブロック (L217-218) を (i)/(ii) の 2 分類に合わせる

### 現行（誤り。T066 以前の記述で止まっている）

```markdown
| `VideoManualService::duplicate()` | cuts (別名保存。…)。scenario_version/status/adopted_take_id の
リテラル書き込みはしない (新規行は DB default 依存) ため検出 1/2/4 は非対象 |
```

実際の `duplicate()` は `forceFill(['status' => VideoManualStatus::Draft, 'scenario_version' => 0])`
を持ち、両 allowlist に登録済みである。**ドキュメントだけが古い。**

### 変更後

```markdown
> **cuts / video_manuals.scenario_version / video_manuals.status を書き込む全経路は、
> 対象 VideoManual 行を `lockForUpdate()` で取得した同一トランザクション内で反映する
> (= **更新経路**)。対象行がまだ存在しない **生成経路** (新規 INSERT) は、所有元 Project 行を
> `lockForUpdate()` した同一トランザクション内で INSERT し、初期状態
> (`status` / `scenario_version`) を**明示代入する** (DB カラム default に依存しない)。**

| `VideoManualService::create()` | **生成経路**。status=Draft / scenario_version=0 を新規 manual の
INSERT 時に明示代入 (DB default 非依存 = 戻り値インスタンスが hydrate 済みになる)。
Project 行 lockForUpdate 済み tx 内の新規 INSERT で、既存行への並行書き込みではない |
| `VideoManualService::duplicate()` | **生成経路**。cuts (別名保存。元 manual を lockForUpdate して
一貫読み取り、cuts は lockForUpdate 済みの**新** manual 経由で作成) +
status=Draft / scenario_version=0 の明示代入。adopted_take_id は複製しない (検出 4 は非対象) |
```

さらに経路表の直後へ 1 段落追加:

```markdown
生成経路 (`create()` / `duplicate()`) の allowlist は**ファイル粒度**であり、
`ScenarioWritePathInventoryTest` は「VideoManualService.php が status/scenario_version を書く」
ことまでしか固定しない。**個々のメソッドが初期状態を明示代入していることの fail-first は
`tests/Feature/Projects/ManualServiceBoundaryTest.php` /
`tests/Feature/Projects/ManualDuplicateTest.php` の behavioral テストが担う。**
```

### 波及変更

- `docs/` 内の他の参照: 本節を参照している箇所は AGENTS.md ドメイン規約 1（施策 5 で同時に是正）

### リスク

- なし（ドキュメント）。ただし**施策 5 と語彙を必ず一致させる**（更新経路 / 生成経路）

---

## 施策 5: `AGENTS.md` ドメイン規約 1 の 2 分類化

### 変更箇所

- ファイル: `AGENTS.md` §ドメイン固有規約 1（L317-326）

### なぜ正本に手を入れるのか

規約 1 の現行文面は「書き込む**全経路**は、対象 VideoManual 行を `lockForUpdate()` で取得した
同一 tx 内で反映する」と例外なく書いている。しかし `duplicate()` は**対象行が未存在**で
この文面を literal には満たしていない。**矛盾は本設計が持ち込むものではなく、T066 の時点で
既に発生していた。**下位ドキュメントだけで例外を説明すると、正本を読んだ人が下位に辿り着くまで
矛盾に気づかない状態が固定化する。

**これは規約の追加ではなく適用範囲の明確化であり、既存の更新経路への要求は 1 ミリも緩めない。**
むしろ生成経路には「明示代入」という**新たな要求が増える**方向である。

### 変更内容（骨子）

規約 1 の冒頭を次の 2 分類に書き換える（既存の準拠実装リスト・inventory への参照はそのまま残す）:

```markdown
1. **シナリオ整合の共有ロック規約**: `cuts` / `video_manuals.scenario_version` /
   `video_manuals.status` を書き込む経路は、次の 2 分類のいずれかに属する。
   - **(i) 更新経路** (既存行の書き換え): 対象 VideoManual 行を `lockForUpdate()` で取得した
     同一トランザクション内で反映する (準拠実装: `Manual/ScenarioService::save()` /
     `::materializeIntoLockedManual()` / `Manual/AnalysisJobService::trigger()` / `::failJob()` /
     `Manual/RenderJobService::trigger()` / `::failJob()` / `::completeRenderIntoLockedManual()` /
     `Capture/CaptureTakeService::adopt()`・`delete()` (cuts.adopted_take_id))
   - **(ii) 生成経路** (新規 INSERT): 対象行は未存在のため、**所有元 Project 行を
     `lockForUpdate()` した同一トランザクション内で INSERT** し、初期状態
     (`status` / `scenario_version`) を **INSERT 時に明示代入する**
     (DB カラム default に依存しない = migration default 変更による silent break と、
     戻り値インスタンスの属性欠落の両方を防ぐ)。
     準拠実装: `Manual/VideoManualService::create()` / `::duplicate()`
   経路 inventory は **`ScenarioWritePathInventoryTest` (Architecture テスト)** で、
   新しい書き込み経路は inventory 登録が必須。**ただし allowlist はファイル粒度**であり、
   同一ファイル内のメソッド追加は検出しない (メソッド単位の fail-first は behavioral テストが担う)。
   詳細は `docs/architecture.md` §シナリオ整合の共有不変条件
```

### 波及変更

- `docs/architecture.md`（施策 4 で同時に是正。**語彙を一致させる**）
- `tests/Architecture/ScenarioWritePathInventoryTest.php` の docblock（施策 3）
- **CLAUDE.md は変更しない**（`CLAUDE.md` への変更依頼はすべて `AGENTS.md` に書く規約）

### リスク

- **中**: AGENTS.md は全エージェントの正本であり、書き換えは影響が広い。
  緩和策 = **(i) の文面（既存の要求）を一字も弱めない**。追加は (ii) の新設と
  「allowlist はファイル粒度」という保証範囲の明記のみに限る

---

## gate が正しく赤くなることの確認手順（必須）

**この 3 つを実施し、結果をコミットメッセージまたは実装レポートに記録する。**

### G-1. 再現テストの fail-first（施策 2 の実装直後・施策 1 の実装前）

```bash
composer test -- --filter=ManualServiceBoundaryTest
# 期待: 新規 1 本目が RED (null → status/scenario_version、または "read property \"value\" on null")
```

### G-2. mutation ① — ファイル粒度 gate が実際に効いていること

```bash
# ScenarioWritePathInventoryTest.php の STATUS_WRITE_ALLOWED から
# 'Services/Manual/VideoManualService.php' を一時的にコメントアウト
composer test -- --filter=ScenarioWritePathInventoryTest
# 期待: RED。メッセージに Services/Manual/VideoManualService.php が列挙される

# SCENARIO_VERSION_ALLOWED についても同様に一時除外して RED を確認する

# 確認後、**必ず両方を元に戻す**
composer test -- --filter=ScenarioWritePathInventoryTest   # GREEN に戻ること
```

> **この赤が実証しないこと**: `create()` の登録が load-bearing であること。
> この赤は**既存の `duplicate()` の write だけでも成立する**。
> G-2 が実証するのは「ファイル粒度 gate が空回りしていない」ことまでである。

### G-3. mutation ② — `create()` の明示代入が消えたら気づけること（**属性ごとに個別に**）

```bash
# ②-a: create() の forceFill から 'status' => VideoManualStatus::Draft の 1 行だけ削除
composer test -- --filter=ManualServiceBoundaryTest
# 期待: RED。status の assertion が落ちる (scenario_version の assertion は緑のまま)

# 元に戻す

# ②-b: create() の forceFill から 'scenario_version' => 0 の 1 行だけ削除
composer test -- --filter=ManualServiceBoundaryTest
# 期待: RED。scenario_version の assertion が落ちる

# 元に戻して GREEN を確認
```

**同時に消さない**。同時に消すと先に評価される assertion で停止し、もう片方の保証を実証できない。

### G-4. 実走での確認（任意・費用注意）

`scripts/bug-hunt-shard.sh pipeline-smoke` は **LLM を 3 段とも実呼び出しするため実行そのものが
課金である**。本修正の検証には必須ではない（fixture 段の 1 因は G-1〜G-3 で閉じている）。
実施するなら `BUGHUNT_ORCHESTRATOR=1` を持つ親からのみ、かつ**ユーザーの明示承認を得てから**行う。
`--check` は preflight のみ = 費用ゼロなので、こちらは自由に実行してよい。

---

## 検証コマンド（全 green でコミット）

```bash
composer test          # Pest 全レーン (RefreshDatabase + --parallel)
composer phpstan       # level 10
vendor/bin/pint --test # フォーマット
```

フロント変更が無いため `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` は
差分ゼロだが、リポジトリ規約どおり全 green を確認する。

---

## 保証しないもの（誇張しない）

1. **「VideoManual の INSERT が DB default に依存しない」ことは機械では保証されない。**
   本設計が固定するのは `create()` / `duplicate()` の 2 経路の**振る舞い**だけで、
   将来新設される**第 3 の生成経路には沈黙する**（横断 Architecture テストは
   偽陽性コスト過大のため作らないと決めた帰結）
2. **inventory の allowlist はファイル粒度である。** 同一ファイル内でメソッドを増やしても
   gate は赤くならない。メソッド単位の fail-first を担うのは behavioral テストだけである
3. **他モデルの既定値依存は本件で閉じない。** 実コード走査で
   `take_upload_reservations.status`（`TakeUploadService` が `make()` に含めず DB default
   `'pending'` に依存）が**同型**であることを確認したが、**その戻り値の `status` を読む
   呼び出し側は現状 1 つも無い**ため顕在化していない。思考原則 2 に従い本件では直さず、
   別 TODO 候補として記録するに留める。
   （`analysis_jobs` / `render_jobs` は `$job->status = JobStatus::Queued;` で既に明示代入済み）
4. **pipeline-smoke が緑になることは本修正だけでは保証しない。** 閉じるのは fixture 段の
   この 1 因だけで、後続段（LLM 実呼び出し 3 段 / 撮影テイク / ffmpeg 合成 / mp4）の成否は別問題
5. **既存行の backfill はしない。** 既に DB default 経由で `'draft'` / `0` が入っており、
   行の内容は修正前後で同一のため backfill の必要そのものが無い
6. **`AGENTS.md` の改訂は「規約が守られること」を保証しない。** 正本の文言と実装・inventory の
   語彙を一致させるだけで、強制は既存の gate と behavioral テストの範囲に留まる

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | (1) `AGENTS.md` / `docs/architecture.md` という**全エージェントが読む正本**を触るため、他施策と混ぜると review 時に変更理由が埋没する。(2) 変更量は小さいが**テストファーストの赤→緑の順序**と 3 種の mutation 確認という手順の厳密さが本件の価値であり、他タスクと同居させると手順が崩れる。(3) `VideoManualService.php` は解析・レンダ系タスクと衝突しやすいホットファイルであり、単独で短期に閉じるのが安全 |
| 競合リスク | `VideoManualService.php` を触る他タスク（解析 / レンダ / 複製系）と衝突しうる。`create()` 内の `forceFill` 1 箇所と docblock のみのため conflict 解決は容易。`AGENTS.md` ドメイン規約 1 は他タスクも追記しうるため、**マージ前に main の最新を取り込む**こと |

---

## 関連する現行コード

### app/Services/Manual/VideoManualService.php (L1-110 抜粋: create() と duplicate())

```php
<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\Enums\Manual\CutType;
use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderKind;
use App\Enums\Manual\VideoManualStatus;
use App\Jobs\Capture\DeleteTakeObjectsJob;
use App\Models\AnalysisJob;
use App\Models\Cut;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\SourceDocument;
use App\Models\Take;
use App\Models\VideoManual;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * VideoManual の書き込み操作 (create / updateMeta / delete)。
 *
 * - created_by はサーバ導出 (Auth 由来の userId を forceFill。payload から受けない)
 * - category は「入力名 category (id 値)」をロック済み project relation から再解決して
 *   associate する (FormRequest の exists 検証と保存時再解決の二段構え。
 *   cross-project の id は firstOrFail → 404 で拒否し DB を変更しない)
 * - 並行制御は CategoryService と同じ Project 行ロック (category 集合との整合を直列化)
 */
class VideoManualService
{
    public function __construct(
        private readonly SourceDocumentService $sourceDocuments,
    ) {}

    /** VideoManual 作成 (status は DB default の draft)。$document は任意の SOP 同時アップロード */
    public function create(Project $project, string $title, ?int $categoryId, int $userId, ?UploadedFile $document = null): VideoManual
    {
        return DB::transaction(function () use ($project, $title, $categoryId, $userId, $document): VideoManual {
            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $manual = $locked->manuals()->make(['title' => $title]);
            $manual->forceFill(['created_by' => $userId])->save();
            if ($categoryId !== null) {
                // 保存時再解決: ロック済み project 配下から取得 (cross-project は 404)
                $category = $locked->categories()->whereKey($categoryId)->firstOrFail();
                $manual->category()->associate($category)->save();
            }
            if ($document !== null) {
                // 新規 manual は競合なし (状態 guard 不要) のため appendDocument 直呼び
                $this->sourceDocuments->appendDocument($manual, $document);
            }

            return $manual;
        });
    }

    /**
     * VideoManual の複製 (別名保存)。保存済み cuts (シナリオ) を雛形に、新タイトル・カテゴリで
     * 新規 manual を作る。**takes / adopted_take_id / render 成果物 / source_documents /
     * analysis_jobs は複製しない** (新規撮影・再合成前提)。複製 manual は必ず
     * status=Draft・scenario_version=0 から開始する (この初期状態を INSERT 時に明示代入し、
     * DB カラム default に依存しない = 将来の migration default 変更による silent break を防ぐ)。
     *
     * シナリオ整合の共有ロック規約 (AGENTS.md ドメイン規約 1) の書き込み経路:
     *  - 元 manual を lockForUpdate してシナリオを一貫読み取り (read/copy の一貫性を確保)
     *  - cuts の書き込み先は**新規** manual。新 manual を save() 後に同一 tx 内で
     *    lockForUpdate 再取得し、その locked インスタンスの relation 経由で cut を作成する
     *    (「対象 VideoManual 行を lockForUpdate で取得した同一 tx 内で反映」を literal に満たす)
     *  - scenario_version / status は新 manual の INSERT 時に明示代入する (新規行生成のため
     *    lockForUpdate 前だが、その tx が生成した排他的新規行であり既存行への並行書き込みではない)。
     *    ScenarioWritePathInventoryTest の STATUS_WRITE_ALLOWED / SCENARIO_VERSION_ALLOWED に登録済み
     */
    public function duplicate(Project $project, VideoManual $source, string $title, ?int $categoryId, int $userId): VideoManual
    {
        return DB::transaction(function () use ($project, $source, $title, $categoryId, $userId): VideoManual {
            // ロック順は create/updateMeta と同じ project → manual
            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            // 子は親に属する: 元 manual をロック済み親 relation から再解決 (cross-project は 404) + 一貫読み取り
            /** @var VideoManual $lockedSource */
            $lockedSource = $locked->manuals()->whereKey($source->id)->lockForUpdate()->firstOrFail();

            // 新 manual: status=Draft / scenario_version=0 を INSERT 時に明示代入して
            // 不変条件をアプリ層で固定する (DB default 依存をやめ silent break を防ぐ)。
            // created_by はサーバ導出。すべて排他的新規行 (並行書き込みなし) の初期値。
            $new = $locked->manuals()->make(['title' => $title]);
            $new->forceFill([
                'created_by' => $userId,
                'status' => VideoManualStatus::Draft,
                'scenario_version' => 0,
            ])->save();
            if ($categoryId !== null) {
                // 保存時再解決: 既存 create() と同一の firstOrFail。通常の不正/他 project category は
                // FormRequest の Rule::exists で 422 (検証時) に落ち、ここで 404 になるのは
                // 「検証通過後に category が削除/移動された」ごく稀な競合のみ (create と完全一致・後退なし)。
                $category = $locked->categories()->whereKey($categoryId)->firstOrFail();
                $new->category()->associate($category)->save();
            }

            // 共有ロック規約 literal 準拠: cuts 書き込み先の新 manual をロックして再取得
            /** @var VideoManual $lockedNew */
            $lockedNew = $locked->manuals()->whereKey($new->id)->lockForUpdate()->firstOrFail();
            $this->copyCuts($lockedSource, $lockedNew);

            return $lockedNew;
        });
    }

    /**
```

### app/Models/VideoManual.php (L14-50 抜粋: docblock / fillable / casts)

```php
/**
 * VideoManual (Project 配下の動画マニュアル)。
 *
 * - project_id / created_by / category_id は保護キーのため $fillable 外
 * - category は project スコープで再解決した Category を associate する (payload 直代入しない)
 * - Project 側 relation は manuals() (route パラメータ {manual} の scopeBindings 推論と一致させ
 *   IDOR 防御を確実にするため videoManuals() は使わない)
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $category_id
 * @property int $created_by
 * @property string $title
 * @property VideoManualStatus $status
 * @property int $scenario_version
 * @property int|null $total_length_ms
 */
class VideoManual extends Model
{
    /** @use HasFactory<VideoManualFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'title',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VideoManualStatus::class,
        ];
    }

```

### database/migrations/2026_07_10_000100_create_video_manuals_table.php (up() 抜粋)

```php
    public function up(): void
    {
        Schema::create('video_manuals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 200);
            $table->string('status')->default('draft');
            $table->integer('scenario_version')->default(0);
            $table->integer('total_length_ms')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
```

### app/Console/Commands/Development/PipelineSmokeCommand.php (runFixtureStage: 踏んだ側)

```php
    /**
     * fixture 段: Default Project (不在時のみ作成) + SOP つき manual の作成。
     *
     * @return array{Project, VideoManual}|null
     */
    private function runFixtureStage(Organization $organization, User $actor, string $workDir): ?array
    {
        $startedAt = CarbonImmutable::now();
        try {
            $project = app(DefaultProjectResolver::class)->resolve($organization)
                ?? app(ProjectService::class)->createProject($organization, 'pipeline-smoke', null);

            // UploadedFile は保存時に元ファイルを触りうるため、fixture 本体ではなく複製を渡す
            $localCopy = $workDir.'/pipeline-smoke-sop.txt';
            File::copy($this->fixturePath(), $localCopy);

            $manual = app(VideoManualService::class)->create(
                $project,
                'pipeline-smoke '.CarbonImmutable::now()->format('Y-m-d H:i'),
                null,
                $actor->id,
                new UploadedFile($localCopy, 'pipeline-smoke-sop.txt', 'text/plain', null, test: true),
            );

            $documents = $manual->sourceDocuments()->count();
            $ok = $manual->status === VideoManualStatus::Draft && $documents === 1;
            $detail = "manual=#{$manual->id} documents={$documents} status={$manual->status->value}";

            return $this->gate(SmokeStage::Fixture, $ok, $startedAt, $detail) ? [$project, $manual] : null;
        } catch (Throwable $exception) {
            $this->gate(SmokeStage::Fixture, false, $startedAt, self::describe($exception));

            return null;
        }
    }
```

### tests/Architecture/ScenarioWritePathInventoryTest.php (L1-110 抜粋: 経路表 docblock + allowlist 定数)

```php
<?php

declare(strict_types=1);

/*
 * シナリオ整合の共有ロック規約 (AGENTS.md ドメイン固有規約 1) の書き込み経路 inventory。
 *
 * 「cuts / video_manuals.scenario_version / video_manuals.status を書き込む全経路は、
 *   対象 VideoManual 行を lockForUpdate() で取得した同一トランザクション内で反映する」
 *
 * 経路 (メソッド粒度。docs/architecture.md と対):
 * | 経路 | 書いてよいもの |
 * |---|---|
 * | ScenarioService::save() | cuts / scenario_version / status (rendering·analyzing guard 付き) |
 * | ScenarioService::materializeIntoLockedManual() | cuts / scenario_version / status (analyzing→ready のみ) |
 * | AnalysisJobService::trigger() | status (draft·ready→analyzing のみ) |
 * | AnalysisJobService::failJob() | status (analyzing→ready·draft のみ。cuts 有無で決定。scenario_version は snapshot 読みのみ) |
 * | VideoManualService::displayXxxJob() | 書き込みなし (stale 判定で scenario_version を読むのみ) |
 * | VideoManualService::duplicate() | cuts (lockForUpdate 済みの新 manual 経由で作成)。元 manual を
 *   lockForUpdate して一貫読み取り。複製 manual の INSERT 時に status=Draft / scenario_version=0 を
 *   明示代入する (新規行生成 = lockForUpdate 前だが、その tx が生成した排他的新規行・同一 tx 内反映で
 *   既存行への並行書き込みではない)。検出 1 (scenario_version) は SCENARIO_VERSION_ALLOWED、
 *   検出 2 (status) は STATUS_WRITE_ALLOWED に登録済み。検出 4 (adopted_take_id) は複製しないため非対象 |
 * | RenderJobService::trigger() | status (ready→rendering のみ。scenario_version はスナップショット読み) |
 * | RenderJobService::failJob() | status (rendering→ready のみ。kind=render に限る) |
 * | RenderJobService::completeRenderIntoLockedManual() | cuts.cut_length_ms / total_length_ms / status (rendering→published のみ) |
 * (RenderPipeline は VideoManualStatus を直接書かない = 全て RenderJobService メソッド経由。
 *  buildManifest/finalize の scenario_version は guard 読みのみ)
 *
 * deny-by-default の token ベース静的走査 (PrismDirectDispatchScanner と同じ token_get_all 流儀。
 * コメント/docblock/文字列リテラル**内容**中の出現は無視する)。走査対象: app/ 配下の .php。
 *
 * 検出 1: 識別子/配列キー 'scenario_version' の出現 → allowlist 外のファイルなら fail
 * 検出 2: 書き込み形 `'status' => ... VideoManualStatus::...` / `->status = ... VideoManualStatus::...`
 *         (`VideoManualStatus::class` = cast 宣言は書き込みでないため除外) → allowlist 外なら fail
 * 検出 3: materializeIntoLockedManual の宣言は ScenarioService.php のみ、
 *         呼び出しは AnalysisPipeline.php のみ (ScenarioService 自身の中の呼び出しも fail =
 *         ファイル単位 allowlist の抜け穴を塞ぐ)
 * 検出 5: completeRenderIntoLockedManual の宣言は RenderJobService.php のみ、
 *         呼び出しは RenderPipeline.php (terminal tx) のみ (検出 3 と同型)
 */
final class ScenarioWritePathScanner
{
    /**
     * 検出 1 の allowlist (app/ 相対パス)。ScenarioDocumentData は読み取り shape の直列化のみ。
     * CaptureTakeService は adopt の 409 (ScenarioConflictException) に current_version を
     * 載せるための読み取りのみ (書き込みは検出 2 が別途 deny する)。
     */
    private const SCENARIO_VERSION_ALLOWED = [
        'Services/Manual/ScenarioService.php',
        'DataTransferObjects/Manual/ScenarioDocumentData.php',
        'Services/Capture/CaptureTakeService.php',
        // レンダ: trigger のスナップショット読み / buildManifest・finalize の guard 読み /
        // casts 宣言 (書き込みは検出 2 が別途 deny する)
        'Services/Manual/RenderJobService.php',
        'Services/Manual/RenderPipeline.php',
        'Models/RenderJob.php',
        // bug-hunt 専用の通し確認コマンド。analysis 段の成功条件 (scenario_version >= 1) を
        // **読み取るだけ**で、書き込みは 1 箇所も持たない (書き込みは検出 2 が別途 deny する)。
        'Console/Commands/Development/PipelineSmokeCommand.php',
        // T032: failJob が失敗確定時の scenario_version を job にスナップショット読みする
        // (書き込むのは scenario_version_at_terminal であり scenario_version ではない)
        'Services/Manual/AnalysisJobService.php',
        // VideoManualService は 2 理由で許可: (1) T032 stale alert 判定 (displayXxxJob) が
        // manual.scenario_version を read (read-only)。(2) T066 duplicate() が複製 manual の
        // INSERT 時に scenario_version=0 を明示 write (新規行生成 + 同一 tx。既存行への並行 write ではない)
        'Services/Manual/VideoManualService.php',
    ];

    /** 検出 2 の allowlist (app/ 相対パス) */
    private const STATUS_WRITE_ALLOWED = [
        'Services/Manual/ScenarioService.php',
        'Services/Manual/AnalysisJobService.php',
        // trigger: ready→rendering / failJob: rendering→ready / complete...: rendering→published。
        // RenderPipeline は VideoManualStatus を直接書かない (全て Service メソッド経由)
        'Services/Manual/RenderJobService.php',
        // T066: duplicate() が複製 manual の INSERT 時に status=Draft を明示代入
        // (新規行生成 + 同一 tx。既存行への並行書き込みではないためロック規約の趣旨に整合)
        'Services/Manual/VideoManualService.php',
    ];

    /**
     * 検出 4a の allowlist: 識別子/配列キー 'adopted_take_id' の出現 (読み書き問わず)。
     * - CaptureTakeService: adopt / 削除時 null 化 (VideoManual 行ロック tx 内 = 唯一の書き込み経路)
     * - Cut.php: relation 宣言 (belongsTo 第 2 引数)
     * - CaptureCutData: 読み取り shape の直列化のみ
     * - MassAssignmentProtectedKeys: 保護キー台帳 (文字列リストのみ)
     */
    private const ADOPTED_TAKE_ID_ALLOWED = [
        'Services/Capture/CaptureTakeService.php',
        'Models/Cut.php',
        'DataTransferObjects/Capture/CaptureCutData.php',
        'Support/Security/MassAssignmentProtectedKeys.php',
    ];

    /**
     * 検出 4b の allowlist: 書き込み形 (`['adopted_take_id' => ...]` 配列キー /
     * `->adopted_take_id =` プロパティ代入)。CaptureCutData の配列キー出現は toArray() の
     * 読み取り直列化 (`'adopted_take_id' => $cut->adopted_take_id`) で、token パターンでは
     * 書き込み (forceFill の配列キー) と区別できないため allowlist に含める
     * (検出 4a が出現ファイル自体を 4 ファイルに固定しているため、新規ファイルへの
     * 書き込みはどちらの検出でも fail する)。
     */
    private const ADOPTED_TAKE_ID_WRITE_ALLOWED = [
        'Services/Capture/CaptureTakeService.php',
        'DataTransferObjects/Capture/CaptureCutData.php',
    ];

    /**
     * @return array<string, list<string>> 検出種別 => 違反ファイル (app/ 相対パス)
```

### tests/Architecture/ScenarioWritePathInventoryTest.php (L679-690: T066 契約テスト)

```php
test('T066: VideoManualService に status/scenario_version の明示 write が実在する (allowlist の degenerate PASS 防止 + 明示代入の fail-first 契約)', function (): void {
    // duplicate() は複製 manual の初期状態を DB default に委ねず status=Draft / scenario_version=0 を
    // 明示 write する。その **write 形** が VideoManualService 内に実在することを token ベースで担保する
    // (明示代入を消すと write が消え、この契約テストが fail = fail-first。STATUS_WRITE_ALLOWED /
    //  SCENARIO_VERSION_ALLOWED の degenerate = 未使用 allowlist 化も防ぐ)。
    // scenario_version は displayXxxJob の read があるため token 出現では区別できず、write 形で判定する。
    $appDir = ScenarioWritePathScanner::appDir();
    $videoManualService = (string) file_get_contents($appDir.'/Services/Manual/VideoManualService.php');

    expect(ScenarioWritePathScanner::containsStatusWrite($videoManualService))->toBeTrue();
    expect(ScenarioWritePathScanner::containsScenarioVersionWrite($videoManualService))->toBeTrue();
});

```

### tests/Feature/Projects/ManualServiceBoundaryTest.php (L1-30 + create 関連テスト)

```php
<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Project;
use App\Models\VideoManual;
use App\Services\Manual\CategoryService;
use App\Services\Manual\VideoManualService;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/*
 * Service 境界防御 (route binding とは別レイヤ)。
 * 全メソッドは Project 行ロック取得後に対象の子を親 relation から再解決するため、
 * 別 Service・将来のバッチ等から cross-project の子を渡されても
 * ModelNotFoundException (→404) で拒否し、DB を一切変更しない。
 */

test('CategoryService::update は cross-project の Category を拒否し DB を変更しない', function (): void {
    [$organization] = createOrganizationWithOwner();
...
test('VideoManualService::create は他 project の categoryId を拒否し manual を残さない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $projectA = Project::factory()->forOrganization($organization)->create();
    $projectB = Project::factory()->forOrganization($organization)->create();
    $categoryB = Category::factory()->forProject($projectB)->create();

    // FormRequest の exists をすり抜けても、保存時再解決 (二段目) が transaction ごと巻き戻す
    expect(fn () => app(VideoManualService::class)->create($projectA, 'タイトル', $categoryB->id, $owner->id))
        ->toThrow(ModelNotFoundException::class);

    expect(VideoManual::query()->count())->toBe(0);
});

test('VideoManualService::updateMeta は他 project の categoryId を拒否し変更を巻き戻す', function (): void {
    [$organization] = createOrganizationWithOwner();
    $projectA = Project::factory()->forOrganization($organization)->create();
    $projectB = Project::factory()->forOrganization($organization)->create();
    $categoryB = Category::factory()->forProject($projectB)->create();
    $manualA = VideoManual::factory()->forProject($projectA)->create(['title' => '元のタイトル']);

    expect(fn () => app(VideoManualService::class)->updateMeta($projectA, $manualA, '改竄タイトル', $categoryB->id))
        ->toThrow(ModelNotFoundException::class);

    $fresh = $manualA->fresh();
    expect($fresh?->title)->toBe('元のタイトル');
    expect($fresh?->category_id)->toBeNull();
});
```

### docs/architecture.md §シナリオ整合の共有不変条件 (L215-245)

```markdown
## シナリオ整合の共有不変条件 (AI-CUE ドメイン規約)

> **cuts / video_manuals.scenario_version / video_manuals.status を書き込む全経路は、
> 対象 VideoManual 行を `lockForUpdate()` で取得した同一トランザクション内で反映する。**

- 直列化点は VideoManual 行 (Project 行はロックしない。カテゴリ等 project 集合との整合は
  シナリオ書き込みに無関係のため、直列化粒度を manual に意図的に絞る)。
  親 relation 経由の再解決 (`$project->manuals()->whereKey(...)->lockForUpdate()`) で
  「子は親に属する」も同時に担保する
- 準拠実装 (メソッド粒度の経路 inventory。`ScenarioWritePathInventoryTest` が
  deny-by-default の token 走査で機械検証する = **Architecture テストへ昇格済み**):

  | 経路 | 書いてよいもの |
  |---|---|
  | `ScenarioService::save()` | cuts / scenario_version / status (rendering·analyzing guard 付き) |
  | `ScenarioService::materializeIntoLockedManual()` | cuts / scenario_version / status (analyzing→ready のみ。呼び出しは AnalysisPipeline::finalize の terminal tx に限定) |
  | `AnalysisJobService::trigger()` | status (draft·ready→analyzing のみ) |
  | `AnalysisJobService::failJob()` | status (analyzing→ready·draft のみ。cuts 有無で決定) |
  | `Capture/CaptureTakeService::adopt()` / `delete()` | cuts.adopted_take_id (採用 / 採用テイク削除時の null 化。検出 4 の allowlist) |
  | `RenderJobService::trigger()` | status (ready→rendering のみ。scenario_version はスナップショット読み) |
  | `RenderJobService::failJob()` | status (rendering→ready のみ。kind=render に限る。preview は触らない) |
  | `RenderJobService::completeRenderIntoLockedManual()` | cuts.cut_length_ms / total_length_ms / status (rendering→published のみ。呼び出しは RenderPipeline::finalize の terminal tx に限定 = 検出 5) |
  | `VideoManualService::duplicate()` | cuts (別名保存。元 manual を lockForUpdate して一貫読み取り、cuts は lockForUpdate 済みの**新** manual 経由で作成)。scenario_version/status/adopted_take_id のリテラル書き込みはしない (新規行は DB default 依存) ため検出 1/2/4 は非対象 |

  テイク採用 API は inventory 準拠へ昇格済み (検出 4 = `adopted_take_id` の token 走査 +
  書き込み形検出)。RenderJob の状態遷移も inventory 準拠済み (検出 5 =
  `completeRenderIntoLockedManual` の宣言/呼び出し限定)
- 状態 guard (rendering/analyzing 中の保存は 409) は第一防衛、共有行ロックは
  「job 側の書き込みと保存が絶対に交差しない」ための構造的防衛 (二重防御)

### キュー投入の原子性
```

### AGENTS.md ドメイン固有規約 1 (L317-326)

```markdown
1. **シナリオ整合の共有ロック規約**: `cuts` / `video_manuals.scenario_version` /
   `video_manuals.status` を書き込む全経路は、対象 VideoManual 行を `lockForUpdate()` で
   取得した同一トランザクション内で反映する (準拠実装: `Manual/ScenarioService::save()` /
   `Manual/ScenarioService::materializeIntoLockedManual()` / `Manual/AnalysisJobService::trigger()` /
   `Manual/AnalysisJobService::failJob()` / `Capture/CaptureTakeService::adopt()`・`delete()`
   (cuts.adopted_take_id)。経路 inventory は **`ScenarioWritePathInventoryTest`
   (Architecture テスト) へ昇格済み** = 新しい書き込み経路は inventory 登録が必須。
   テイク採用 API は検出 4 (`adopted_take_id` の deny-by-default 走査) で inventory 準拠済み。
   後続の RenderJob 状態遷移も同規約に従う。
   詳細は `docs/architecture.md` §シナリオ整合の共有不変条件)
2. **容量 Quota (max_storage_bytes) の予約規約**: presigned アップロードの容量判定は
```

### app/Http/Controllers/Projects/VideoManualController.php store() (戻り値の使い方)

```php
    /** VideoManual 作成。project_id / created_by はサーバ導出 (payload では 422) */
    public function store(StoreVideoManualRequest $request, Project $project, VideoManualService $manuals): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('create', [VideoManual::class, $project]);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $title = $request->validated('title');
        Assert::string($title);
        // 入力名は category (保護キー category_id とは別名)。null = 未分類
        $category = $request->validated('category');
        Assert::nullOrIntegerish($category);
        // SOP 同時アップロード (任意)
        $document = $request->validated('document');
        Assert::nullOrIsInstanceOf($document, UploadedFile::class);

        $manual = $manuals->create($project, $title, $category === null ? null : (int) $category, $user->id, $document);

        return redirect()
            ->route('projects.manuals.show', [$project, $manual])
            ->with('success', '動画マニュアルを作成しました');
    }

```
