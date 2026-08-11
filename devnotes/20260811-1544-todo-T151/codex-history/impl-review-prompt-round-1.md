# 使命 (North Star)

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

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

## あなたの役割

Laravel 12 + Svelte 5 + Inertia + PHP 8.4 のアプリ **AI-CUE** のコードレビュアーとして、
以下の実装差分をレビューする。

## レビュー観点

1. **設計との一致性**: 添付の詳細設計書 (Codex 合議済み) の施策 1〜5 のとおりに実装されているか。
   意図的な逸脱があれば、その妥当性
2. **正確性**: `create()` の初期状態明示代入が正しいか。`duplicate()` の既存の振る舞いを
   変えていないか。migration の default を消していないか。DB に入る値が変わっていないか
3. **PHPStan level 10 適合性** (`@phpstan-ignore` / baseline / 型 widen は禁止)
4. **テスト網羅性**: 再現テストが真に fail-first か。属性ごとの分割が mutation の非対称を
   観測できる形になっているか。既存テストを削除・弱体化していないか
5. **セキュリティ**: 保護キー (created_by / project_id / category_id) の扱い、
   forceFill の使用が規約 (サーバ導出値のみ) に収まっているか
6. **正本ドキュメントの整合**: `AGENTS.md` ドメイン規約 1 の 2 分類化が
   **既存の (i) 更新経路への要求を 1 ミリも緩めていないか**。特に
   「生成経路だから Project 行ロックだけでよい」と読めて `duplicate()` の cuts materialize の
   要求 (新 manual を lockForUpdate で再取得してから copyCuts) が弱まっていないか。
   `docs/architecture.md` / inventory テスト docblock と**語彙が一致**しているか
7. **保証範囲を誇張していないか**: 「allowlist はファイル粒度でメソッド単位の fail-first を
   担えない」という限界が、テスト名・コメント・ドキュメントで正直に書かれているか

※ 本差分に frontend (resources/js, resources/css) の変更は**含まれない**ため、
   DESIGN.md / Atomic Design 観点は対象外。

## 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
- 最後に **全体判定: APPROVED または CHANGES_REQUESTED** を明記する


---

## 詳細設計書 (Codex 合議済み)

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
     * silent break を防ぐ + **戻り値インスタンス上でも status/scenario_version が読み出せる**
     * ようになる。DB から hydrate されるのではなく、INSERT 前に属性を明示セットするためである)。
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

### 追加する import

```php
use App\Enums\Manual\VideoManualStatus;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
```

（`Category` / `Project` / `VideoManual` / `VideoManualService` は既に import 済み）

### 追加するテストケース（ファイル名 + テストケース名）

`tests/Feature/Projects/ManualServiceBoundaryTest.php`（末尾に 3 本追加）:

> **属性ごとにテストを分ける**（Codex Round 1 の指摘）。1 本にまとめると mutation ②-a / ②-b で
> 「片方だけが赤くなる」ことを**観測できない**（同一テスト内では最初の失敗で停止するため）。

#### テスト 1（**再現テスト = fail-first の本体 / status 契約**）

```
test('VideoManualService::create の戻り値は refresh なしで status=Draft を保持する (category+SOP あり)', ...)
```

- **pipeline-smoke が実際に踏んだ形に寄せる**（`document` あり）。さらに `category` ありにして
  `category()->associate()->save()` の **2 度目の save** を通した後も属性が残ることを固定する
- **`Storage::fake()` は引数なしでよい**（既定ディスクを fake する）。根拠:
  1. `SourceDocumentService::appendDocument()` は `Storage::putFileAs(...)` を
     **ディスク指定なし**で呼ぶ（= 既定ディスク）。`config/filesystems.php` は
     `'default' => env('FILESYSTEM_DISK', 'local')`、`.env.example` は `FILESYSTEM_DISK=local`
  2. **`appendDocument` を実際に通している既存テスト**
     `tests/Feature/Projects/SourceDocumentUploadTest.php` が引数なし `Storage::fake()` を
     各テストで使用しており（L52 / L69 / L81 / L101 / L114 …）現に緑で回っている
  3. ディスク名を明示するのは `Storage::fake('s3')` のように**既定でない特定ディスク**を
     狙う場合だけ（`ManualRenderNotificationTest` / `tests/Pest.php` の `FakeObjectStore::DISK`）
- 手順:
  ```php
  Storage::fake();
  [$organization, $owner] = createOrganizationWithOwner();
  $project = Project::factory()->forOrganization($organization)->create();
  $category = Category::factory()->forProject($project)->create();
  $document = UploadedFile::fake()->createWithContent('sop.txt', '手順 1: 装置の電源を入れる');

  $manual = app(VideoManualService::class)->create(
      $project, 'テスト手順書', $category->id, $owner->id, $document,
  );
  ```
- assert（**`refresh()` / `fresh()` を挟まない戻り値インスタンスそのもの**に対して）:
  - `expect($manual->status)->toBe(VideoManualStatus::Draft);` — **修正前は null で赤**
  - `expect($manual->status->value)->toBe('draft');` — 修正前は
    `Attempt to read property "value" on null` で赤（**実走と同じ症状**）
  - `expect($manual->category_id)->toBe($category->id);` — 2 度目の save 後も category が付く
  - `expect($manual->sourceDocuments()->count())->toBe(1);` — `appendDocument()` を通っている

#### テスト 2（**scenario_version 契約**。テスト 1 と分けるのは mutation の観測のため）

```
test('VideoManualService::create の戻り値は refresh なしで scenario_version=0 を保持する', ...)
```

- 手順: 最短経路（`categoryId=null` / `document=null`）で足りる。
  分割の目的は「scenario_version の明示代入だけを消したときに**このテストだけ**が赤くなる」
  ことを観測できるようにすること
- assert: `expect($manual->scenario_version)->toBe(0);` — **修正前は null で赤**

#### テスト 3（**DB 実値**。戻り値だけ整えて DB が別値、の取り違え防止）

```
test('VideoManualService::create が INSERT した行は DB 上も status=draft・scenario_version=0 である', ...)
```

- 目的: 明示代入の値が DB default と一致していることの固定。将来 migration default を変えても
  本テストが「アプリ層が宣言する初期状態」の基準になる
- assert:
  - `$manual->fresh()` に対して `status === VideoManualStatus::Draft` / `scenario_version === 0`
  - `expect(DB::table('video_manuals')->where('id', $manual->id)->value('status'))->toBe('draft');`
    （**cast を経由しない生値**の確認）
- ※ この `DB::table('video_manuals')->where('id', ...)` は `ModelDirectFetchInvariantTest` の
  対象外（同 gate の走査根は `app/` のみで、テストコードは母集団に入らない）

### fail-first の実行手順（**必ずこの順で行う**）

```bash
# 0. 施策 1 に手を付ける前 (create() は既定値依存のまま) に実行する
composer test -- --filter=ManualServiceBoundaryTest

# 期待: 追加した 3 本のうち テスト 1 / テスト 2 が RED
#   テスト 1: "Failed asserting that null is identical to VideoManualStatus::Draft" 系、または
#             ErrorException: Attempt to read property "value" on null   ← 実走と同じ症状
#   テスト 2: scenario_version が null
#   テスト 3: GREEN のはず (DB default が現に 'draft'/0 を入れているため)。
#             ここが赤なら前提が崩れているので先に原因を調べる
# ここで RED を目視確認してから施策 1 を実装する (思考原則 5 / 禁止事項 1)
```

**赤を確認せずに施策 1 を先に実装した場合、この TODO は「実装済み」にできない**。
その場合は施策 1 を `git stash` で退避して赤を撮り直すこと。

### テスト計画チェック

- [x] バグ修正のため**再現テストを先に書く**（上記手順）
- [x] 既存テストの更新: 不要（追加のみ。削除・上書きなし）
- [x] 新規テスト 3 本 — status 契約（category+SOP あり）/ scenario_version 契約 / DB 実値
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

### 既存テスト `T066: VideoManualService に status/scenario_version の明示 write が実在する` の**名称・コメント是正**

現行のテスト名は「…**明示代入の fail-first 契約**」と謳っているが、実体は**ファイル粒度**で、
`create()` の明示代入を消しても `duplicate()` の write が残れば**通ってしまう**。
設計本文で限界を正直に書いている一方、**テスト名がそれを裏切っている**（読んだ人が
保証されていないものを保証されていると誤認する）ため是正する。

- **assertion は 1 行も変えない**（`containsStatusWrite()` / `containsScenarioVersionWrite()` の
  2 本のまま）。**検査内容を不必要に変更しない**ため（既存テストの削除・上書きを避ける）、
  名称とコメントだけを実態に合わせる
- 新名称（案）:
  ```
  T066: VideoManualService ファイルに status/scenario_version の明示 write が
  少なくとも 1 つ存在する (allowlist の degenerate PASS 防止。ファイル粒度であり
  メソッド単位の fail-first ではない)
  ```
- コメントに 1 行追加:
  ```
  // **メソッド単位の fail-first は本テストでは担えない** (create() の明示代入を消しても
  // duplicate() が残れば通る)。create()/duplicate() それぞれの初期状態の保証は
  // ManualServiceBoundaryTest / ManualDuplicateTest の behavioral テストが担う。
  ```
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
  - **L220-221 の「直列化点は VideoManual 行 (Project 行はロックしない…)」段落**
    （Codex Round 1 指摘。放置すると**同じ節の中で矛盾**する）

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

**L220-221 の「直列化点」段落**も次のように書き分ける（現行文は「Project 行はロックしない」と
断言しており、生成経路が Project 行をロックすることと矛盾する）:

```markdown
- **更新経路**の直列化点は VideoManual 行 (Project 行はロックしない。カテゴリ等 project 集合との
  整合はシナリオ書き込みに無関係のため、直列化粒度を manual に意図的に絞る)。
  親 relation 経由の再解決 (`$project->manuals()->whereKey(...)->lockForUpdate()`) で
  「子は親に属する」も同時に担保する
- **生成経路**は対象 VideoManual 行が未存在のため、所有元 Project 行を `lockForUpdate()` した
  同一 tx 内で INSERT する。**免除されるのはその tx が生成した新規行の初期値
  (`status` / `scenario_version`) の INSERT のみ**であり、生成後の行に対する後続の書き込みは
  更新経路として扱う — `duplicate()` の cuts materialize は、保存した新 manual を
  `lockForUpdate()` で**再取得してから**行う (`copyCuts` の呼び出し前提)
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
     - **免除の範囲を広げない**: (ii) が `lockForUpdate()` を免除されるのは
       **その tx が生成した新規行の初期値 (`status` / `scenario_version`) の INSERT のみ**である。
       **生成後の行に対する後続の書き込み (`cuts` 等) は (i) 更新経路として扱い**、
       保存済みの新 manual を `lockForUpdate()` で**再取得した**同一 tx 内で行う
       (準拠実装: `duplicate()` は新 manual を save 後に `lockForUpdate()` で再取得してから
       `copyCuts()` を呼ぶ)
   経路 inventory は **`ScenarioWritePathInventoryTest` (Architecture テスト)** で、
   新しい書き込み経路は inventory 登録が必須。**ただし allowlist はファイル粒度**であり、
   同一ファイル内のメソッド追加は検出しない (メソッド単位の fail-first は behavioral テストが担う)。
   詳細は `docs/architecture.md` §シナリオ整合の共有不変条件
```

> **この「免除の範囲を広げない」節が施策 5 の要**である。これが無いと
> `duplicate()` の cuts まで「生成経路だから Project 行ロックだけでよい」と読め、
> **既存要求を弱めてしまう**（Codex Round 1 の最重要指摘）。

### 波及変更

- `docs/architecture.md`（施策 4 で同時に是正。**語彙を一致させる**）
- `tests/Architecture/ScenarioWritePathInventoryTest.php` の docblock（施策 3）
- **CLAUDE.md は変更しない**（`CLAUDE.md` への変更依頼はすべて `AGENTS.md` に書く規約）

### リスク

- **中**: AGENTS.md は全エージェントの正本であり、書き換えは影響が広い。
  緩和策 = **(i) の文面（既存の要求）を一字も弱めない**。追加は (ii) の新設、
  **(ii) の免除範囲を初期値 INSERT のみに封じる節**、
  「allowlist はファイル粒度」という保証範囲の明記の 3 点のみに限る
- **実装時の自己点検**: 改訂後の規約を読んで、`duplicate()` の `copyCuts` が
  `lockForUpdate()` 済みの新 manual 経由でなければならないことが**読み取れるか**を確認する。
  読み取れなければ文面が緩んでいる（差し戻す）

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

**属性ごとにテストを分けてある**（施策 2 のテスト 1 / テスト 2）ため、
「片方だけが赤くなる」ことを**実際に観測できる**。

```bash
# ②-a: create() の forceFill から 'status' => VideoManualStatus::Draft の 1 行だけ削除
composer test -- --filter=ManualServiceBoundaryTest
# 期待: テスト 1 (status 契約) が RED / テスト 2 (scenario_version 契約) は GREEN のまま
#       ← この非対称が観測できることが本手順の要件

# 元に戻す

# ②-b: create() の forceFill から 'scenario_version' => 0 の 1 行だけ削除
composer test -- --filter=ManualServiceBoundaryTest
# 期待: テスト 2 が RED / テスト 1 は GREEN のまま

# 元に戻して全 GREEN を確認
```

**同時に消さない**。同時に消すと両テストが同時に赤くなり、
「どの assertion がどの実装行を守っているか」の 1:1 対応を確認できない。
（テストを 1 本にまとめていた場合は、同一テスト内で最初の失敗により停止するため
**そもそも非対称を観測できない** — これが施策 2 でテストを分割した理由である）

### G-4. 実走での確認（任意・費用注意）

`scripts/bug-hunt-shard.sh pipeline-smoke` は **LLM を 3 段とも実呼び出しするため実行そのものが
課金である**。本修正の検証には必須ではない（fixture 段の 1 因は G-1〜G-3 で閉じている）。
実施するなら `BUGHUNT_ORCHESTRATOR=1` を持つ親からのみ、かつ**ユーザーの明示承認を得てから**行う。
`--check` は preflight のみ = 費用ゼロなので、こちらは自由に実行してよい。

---

## 検証コマンド（全 green でコミット）

AGENTS.md の `VERIFICATION_COMMANDS` と**同期した全 10 本**
（composer/pint 3 本 + pnpm 7 本）を実行する（1 本も落とさない）:

```bash
composer test              # Pest 全レーン (RefreshDatabase + --parallel)
composer phpstan           # level 10
vendor/bin/pint --test     # フォーマット
pnpm lint
pnpm typecheck
pnpm test
pnpm build
pnpm typecheck:packages
pnpm build:packages
pnpm test:packages
```

フロント変更が無いため pnpm 系は差分ゼロの想定だが、**「差分が無いから省く」をしない**
（`verification-commands-doc-sync.test.ts` が一覧の同期を deny-by-default で強制しており、
一覧を守るのは実行側の責務でもある）。

> **テストレーンのグローバルロック (T099)**: `composer test` / `pnpm test` /
> `pnpm test:packages` はホスト全体で 1 本ずつしか走らない。待ちが出るのは正常で、
> 30 秒ごとの heartbeat が出ている間はハングではない。**kill しない / ロックファイルを消さない**。

---

## 保証しないもの（誇張しない）

1. **「VideoManual の INSERT が DB default に依存しない」ことは機械では保証されない。**
   本設計が固定するのは `create()` / `duplicate()` の 2 経路の**振る舞い**だけである。
   `ScenarioWritePathInventoryTest` の検出限界を正確に書くと（Codex Round 1 の指摘で精密化）:
   - **検出される**: **新しいファイル**が `status` / `scenario_version` を明示 write した場合
     （deny-by-default の allowlist 外なので赤くなる）
   - **検出されない (a)**: 同一 `VideoManualService.php` **内に新メソッドを足して** write した場合
     （allowlist はファイル粒度）
   - **検出されない (b)**: **明示 write を一切持たず DB default に依存する生成経路**
     （token 走査は「書いていないこと」を見られない）。
     **これは本件とまったく同じバグの再発形であり、gate は沈黙する**。
     この形の再発を防げるのは behavioral テストと人間のレビューだけである
2. **inventory の allowlist はファイル粒度である。** 同一ファイル内でメソッドを増やしても
   gate は赤くならない。メソッド単位の fail-first を担うのは behavioral テストだけである
3. **他モデルの既定値依存は本件で閉じない。** 本設計時に実コードを走査して
   `take_upload_reservations.status` が**同型**であることを確認した
   （`TakeUploadService::issue()` の `$lockedCut->uploadReservations()->make([...])` は
   `status` を含めず、migration の `->default('pending')` に依存する）。
   **根拠（走査結果）**:
   - `rg -n -e 'reservation->status' -e 'reservation\?->status' app/ tests/` の全ヒットを確認し、
     `TakeUploadReservation` の `status` を読む箇所は
     `TakeRegistrationService`（DB から再取得した行）/ `StaleUploadReservationSweeper`（同）/
     テスト 2 本（`TakeUploadReservationModelTest` / `TakeUploadUrlTest`。いずれも
     **DB から取り直した行**）のみで、**`TakeUploadService` の戻り値インスタンスの `status` を
     読む呼び出し側は 0 件**だった
   - `TakeUploadService` の戻り値は `TakeUploadTicketData`（`presigned` / `ticket` /
     `client_take_id` のみ）であり、`status` を外へ出していない
   → よって**現時点で顕在化していない**。思考原則 2 に従い本件では直さず、別 TODO 候補として
   記録するに留める（再発時の調査コストを下げるための記録であり、対処の約束ではない）。
   （`analysis_jobs` / `render_jobs` は `$job->status = JobStatus::Queued;` で既に明示代入済みで、
   同型の問題を持たない）
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


## 実装差分 (git diff)

```diff
diff --git a/AGENTS.md b/AGENTS.md
index 2c5ffda..480c7bf 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -315,15 +315,30 @@ ## ドメイン固有規約
      テンプレート更新の取り込みを容易にするため、できるだけ書き換えない。 -->
 
 1. **シナリオ整合の共有ロック規約**: `cuts` / `video_manuals.scenario_version` /
-   `video_manuals.status` を書き込む全経路は、対象 VideoManual 行を `lockForUpdate()` で
-   取得した同一トランザクション内で反映する (準拠実装: `Manual/ScenarioService::save()` /
-   `Manual/ScenarioService::materializeIntoLockedManual()` / `Manual/AnalysisJobService::trigger()` /
-   `Manual/AnalysisJobService::failJob()` / `Capture/CaptureTakeService::adopt()`・`delete()`
-   (cuts.adopted_take_id)。経路 inventory は **`ScenarioWritePathInventoryTest`
-   (Architecture テスト) へ昇格済み** = 新しい書き込み経路は inventory 登録が必須。
+   `video_manuals.status` を書き込む経路は、次の 2 分類のいずれかに属する。
+   - **(i) 更新経路** (既存行の書き換え): 対象 VideoManual 行を `lockForUpdate()` で取得した
+     同一トランザクション内で反映する (準拠実装: `Manual/ScenarioService::save()` /
+     `Manual/ScenarioService::materializeIntoLockedManual()` /
+     `Manual/AnalysisJobService::trigger()` / `Manual/AnalysisJobService::failJob()` /
+     `Capture/CaptureTakeService::adopt()`・`delete()` (cuts.adopted_take_id))
+   - **(ii) 生成経路** (新規 INSERT): 対象行は未存在のため、**所有元 Project 行を
+     `lockForUpdate()` した同一トランザクション内で INSERT** し、初期状態
+     (`status` / `scenario_version`) を **INSERT 時に明示代入する**
+     (DB カラム default に依存しない = migration default 変更による silent break と、
+     戻り値インスタンスの属性欠落の両方を防ぐ)。
+     準拠実装: `Manual/VideoManualService::create()` / `::duplicate()`
+     - **免除の範囲を広げない**: (ii) が `lockForUpdate()` を免除されるのは
+       **その tx が生成した新規行の初期値 (`status` / `scenario_version`) の INSERT のみ**である。
+       **生成後の行に対する後続の書き込み (`cuts` 等) は (i) 更新経路として扱い**、
+       保存済みの新 manual を `lockForUpdate()` で**再取得した**同一 tx 内で行う
+       (準拠実装: `duplicate()` は新 manual を save 後に `lockForUpdate()` で再取得してから
+       `copyCuts()` を呼ぶ)
+   経路 inventory は **`ScenarioWritePathInventoryTest` (Architecture テスト) へ昇格済み** =
+   新しい書き込み経路は inventory 登録が必須。**ただし allowlist はファイル粒度**であり、
+   同一ファイル内のメソッド追加は検出しない (メソッド単位の fail-first は behavioral テストが担う)。
    テイク採用 API は検出 4 (`adopted_take_id` の deny-by-default 走査) で inventory 準拠済み。
    後続の RenderJob 状態遷移も同規約に従う。
-   詳細は `docs/architecture.md` §シナリオ整合の共有不変条件)
+   詳細は `docs/architecture.md` §シナリオ整合の共有不変条件
 2. **容量 Quota (max_storage_bytes) の予約規約**: presigned アップロードの容量判定は
    `Billing/QuotaService::checkAddition` + `Capture/StorageUsageService::occupiedBytes`
    (bytes_used + bytes_pending) 経由のみ。予約 (`take_upload_reservations`) の状態遷移は
diff --git a/app/Services/Manual/VideoManualService.php b/app/Services/Manual/VideoManualService.php
index 8f69ce1..db5e956 100644
--- a/app/Services/Manual/VideoManualService.php
+++ b/app/Services/Manual/VideoManualService.php
@@ -35,13 +35,35 @@ public function __construct(
         private readonly SourceDocumentService $sourceDocuments,
     ) {}
 
-    /** VideoManual 作成 (status は DB default の draft)。$document は任意の SOP 同時アップロード */
+    /**
+     * VideoManual 作成。$document は任意の SOP 同時アップロード。
+     *
+     * 新規 manual は必ず status=Draft・scenario_version=0 から開始する (この初期状態を
+     * INSERT 時に明示代入し、DB カラム default に依存しない = 将来の migration default 変更による
+     * silent break を防ぐ + **戻り値インスタンス上でも status/scenario_version が読み出せる**。
+     * DB から hydrate されるのではなく INSERT 前に属性を明示セットするためである)。
+     * 既定値依存だった頃は create() の戻り値の status が null で、pipeline-smoke の fixture 段が
+     * `$manual->status->value` で落ちた (実走で観測。T151)。
+     *
+     * シナリオ整合の共有ロック規約 (AGENTS.md ドメイン規約 1) の **(ii) 生成経路**:
+     *  - 対象 VideoManual 行は未存在のため、所有元 Project 行を lockForUpdate した同一 tx 内で
+     *    INSERT する (既存行への並行書き込みではなく、その tx が生成した排他的新規行)
+     *  - status / scenario_version は INSERT 時に明示代入する。duplicate() と同一カテゴリで、
+     *    ScenarioWritePathInventoryTest の STATUS_WRITE_ALLOWED / SCENARIO_VERSION_ALLOWED に
+     *    登録済み (**ファイル粒度**のため本メソッド単体の fail-first は担えない。それを担うのは
+     *    tests/Feature/Projects/ManualServiceBoundaryTest.php の behavioral 契約テストである)
+     */
     public function create(Project $project, string $title, ?int $categoryId, int $userId, ?UploadedFile $document = null): VideoManual
     {
         return DB::transaction(function () use ($project, $title, $categoryId, $userId, $document): VideoManual {
             $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
             $manual = $locked->manuals()->make(['title' => $title]);
-            $manual->forceFill(['created_by' => $userId])->save();
+            // created_by はサーバ導出。status/scenario_version は初期状態の明示代入 (DB default 非依存)
+            $manual->forceFill([
+                'created_by' => $userId,
+                'status' => VideoManualStatus::Draft,
+                'scenario_version' => 0,
+            ])->save();
             if ($categoryId !== null) {
                 // 保存時再解決: ロック済み project 配下から取得 (cross-project は 404)
                 $category = $locked->categories()->whereKey($categoryId)->firstOrFail();
diff --git a/docs/architecture.md b/docs/architecture.md
index 401851e..e5240c8 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -215,12 +215,20 @@ ## 主要 Service (テンプレート同梱)
 ## シナリオ整合の共有不変条件 (AI-CUE ドメイン規約)
 
 > **cuts / video_manuals.scenario_version / video_manuals.status を書き込む全経路は、
-> 対象 VideoManual 行を `lockForUpdate()` で取得した同一トランザクション内で反映する。**
+> 対象 VideoManual 行を `lockForUpdate()` で取得した同一トランザクション内で反映する
+> (= 更新経路)。対象行がまだ存在しない生成経路 (新規 INSERT) は、所有元 Project 行を
+> `lockForUpdate()` した同一トランザクション内で INSERT し、初期状態
+> (`status` / `scenario_version`) を明示代入する (DB カラム default に依存しない)。**
 
-- 直列化点は VideoManual 行 (Project 行はロックしない。カテゴリ等 project 集合との整合は
-  シナリオ書き込みに無関係のため、直列化粒度を manual に意図的に絞る)。
+- **更新経路**の直列化点は VideoManual 行 (Project 行はロックしない。カテゴリ等 project 集合との
+  整合はシナリオ書き込みに無関係のため、直列化粒度を manual に意図的に絞る)。
   親 relation 経由の再解決 (`$project->manuals()->whereKey(...)->lockForUpdate()`) で
   「子は親に属する」も同時に担保する
+- **生成経路**は対象 VideoManual 行が未存在のため、所有元 Project 行を `lockForUpdate()` した
+  同一 tx 内で INSERT する。**免除されるのはその tx が生成した新規行の初期値
+  (`status` / `scenario_version`) の INSERT のみ**であり、生成後の行に対する後続の書き込みは
+  更新経路として扱う — `duplicate()` の cuts materialize は、保存した新 manual を
+  `lockForUpdate()` で**再取得してから**行う (`copyCuts` の呼び出し前提)
 - 準拠実装 (メソッド粒度の経路 inventory。`ScenarioWritePathInventoryTest` が
   deny-by-default の token 走査で機械検証する = **Architecture テストへ昇格済み**):
 
@@ -234,7 +242,14 @@ ## シナリオ整合の共有不変条件 (AI-CUE ドメイン規約)
   | `RenderJobService::trigger()` | status (ready→rendering のみ。scenario_version はスナップショット読み) |
   | `RenderJobService::failJob()` | status (rendering→ready のみ。kind=render に限る。preview は触らない) |
   | `RenderJobService::completeRenderIntoLockedManual()` | cuts.cut_length_ms / total_length_ms / status (rendering→published のみ。呼び出しは RenderPipeline::finalize の terminal tx に限定 = 検出 5) |
-  | `VideoManualService::duplicate()` | cuts (別名保存。元 manual を lockForUpdate して一貫読み取り、cuts は lockForUpdate 済みの**新** manual 経由で作成)。scenario_version/status/adopted_take_id のリテラル書き込みはしない (新規行は DB default 依存) ため検出 1/2/4 は非対象 |
+  | `VideoManualService::create()` | **生成経路**。status=Draft / scenario_version=0 を新規 manual の INSERT 時に明示代入 (DB default 非依存 = 戻り値インスタンスが hydrate 済みになる)。Project 行 lockForUpdate 済み tx 内の新規 INSERT で、既存行への並行書き込みではない |
+  | `VideoManualService::duplicate()` | **生成経路**。cuts (別名保存。元 manual を lockForUpdate して一貫読み取り、cuts は lockForUpdate 済みの**新** manual 経由で作成) + status=Draft / scenario_version=0 の明示代入。adopted_take_id は複製しない (検出 4 は非対象) |
+
+  生成経路 (`create()` / `duplicate()`) の allowlist は**ファイル粒度**であり、
+  `ScenarioWritePathInventoryTest` は「VideoManualService.php が status/scenario_version を書く」
+  ことまでしか固定しない。**個々のメソッドが初期状態を明示代入していることの fail-first は
+  `tests/Feature/Projects/ManualServiceBoundaryTest.php` /
+  `tests/Feature/Projects/ManualDuplicateTest.php` の behavioral テストが担う。**
 
   テイク採用 API は inventory 準拠へ昇格済み (検出 4 = `adopted_take_id` の token 走査 +
   書き込み形検出)。RenderJob の状態遷移も inventory 準拠済み (検出 5 =
diff --git a/tests/Architecture/ScenarioWritePathInventoryTest.php b/tests/Architecture/ScenarioWritePathInventoryTest.php
index 9d82392..4905452 100644
--- a/tests/Architecture/ScenarioWritePathInventoryTest.php
+++ b/tests/Architecture/ScenarioWritePathInventoryTest.php
@@ -5,8 +5,10 @@
 /*
  * シナリオ整合の共有ロック規約 (AGENTS.md ドメイン固有規約 1) の書き込み経路 inventory。
  *
- * 「cuts / video_manuals.scenario_version / video_manuals.status を書き込む全経路は、
- *   対象 VideoManual 行を lockForUpdate() で取得した同一トランザクション内で反映する」
+ * 「cuts / video_manuals.scenario_version / video_manuals.status を書き込む経路は次の 2 分類:
+ *   (i) 更新経路 = 対象 VideoManual 行を lockForUpdate() で取得した同一トランザクション内で反映する。
+ *   (ii) 生成経路 = 対象行が未存在のため所有元 Project 行を lockForUpdate() した同一 tx 内で INSERT し、
+ *        初期状態 (status / scenario_version) を INSERT 時に明示代入する (DB default に依存しない)」
  *
  * 経路 (メソッド粒度。docs/architecture.md と対):
  * | 経路 | 書いてよいもの |
@@ -16,7 +18,13 @@
  * | AnalysisJobService::trigger() | status (draft·ready→analyzing のみ) |
  * | AnalysisJobService::failJob() | status (analyzing→ready·draft のみ。cuts 有無で決定。scenario_version は snapshot 読みのみ) |
  * | VideoManualService::displayXxxJob() | 書き込みなし (stale 判定で scenario_version を読むのみ) |
- * | VideoManualService::duplicate() | cuts (lockForUpdate 済みの新 manual 経由で作成)。元 manual を
+ * | VideoManualService::create() | status / scenario_version (**(ii) 生成経路**。新規 manual の INSERT 時に
+ *   status=Draft / scenario_version=0 を明示代入する。対象 VideoManual 行は未存在のため所有元 Project 行を
+ *   lockForUpdate した同一 tx 内で INSERT = 既存行への並行書き込みではない。検出 1 は
+ *   SCENARIO_VERSION_ALLOWED、検出 2 は STATUS_WRITE_ALLOWED に登録済み (duplicate() と同一ファイル)。
+ *   **allowlist はファイル粒度のため create() 単体の検出保証はなく、fail-first を担うのは
+ *   tests/Feature/Projects/ManualServiceBoundaryTest.php の behavioral 契約テストである** (T151) |
+ * | VideoManualService::duplicate() | **(ii) 生成経路**。cuts (lockForUpdate 済みの新 manual 経由で作成)。元 manual を
  *   lockForUpdate して一貫読み取り。複製 manual の INSERT 時に status=Draft / scenario_version=0 を
  *   明示代入する (新規行生成 = lockForUpdate 前だが、その tx が生成した排他的新規行・同一 tx 内反映で
  *   既存行への並行書き込みではない)。検出 1 (scenario_version) は SCENARIO_VERSION_ALLOWED、
@@ -61,9 +69,11 @@ final class ScenarioWritePathScanner
         // T032: failJob が失敗確定時の scenario_version を job にスナップショット読みする
         // (書き込むのは scenario_version_at_terminal であり scenario_version ではない)
         'Services/Manual/AnalysisJobService.php',
-        // VideoManualService は 2 理由で許可: (1) T032 stale alert 判定 (displayXxxJob) が
+        // VideoManualService は 3 理由で許可: (1) T032 stale alert 判定 (displayXxxJob) が
         // manual.scenario_version を read (read-only)。(2) T066 duplicate() が複製 manual の
-        // INSERT 時に scenario_version=0 を明示 write (新規行生成 + 同一 tx。既存行への並行 write ではない)
+        // INSERT 時に scenario_version=0 を明示 write。(3) T151 create() が新規 manual の
+        // INSERT 時に scenario_version=0 を明示 write
+        // ((2)(3) はいずれも生成経路 = 新規行生成 + 同一 tx。既存行への並行 write ではない)
         'Services/Manual/VideoManualService.php',
     ];
 
@@ -74,8 +84,10 @@ final class ScenarioWritePathScanner
         // trigger: ready→rendering / failJob: rendering→ready / complete...: rendering→published。
         // RenderPipeline は VideoManualStatus を直接書かない (全て Service メソッド経由)
         'Services/Manual/RenderJobService.php',
-        // T066: duplicate() が複製 manual の INSERT 時に status=Draft を明示代入
-        // (新規行生成 + 同一 tx。既存行への並行書き込みではないためロック規約の趣旨に整合)
+        // T066: duplicate() が複製 manual の INSERT 時に status=Draft を明示代入。
+        // T151: create() も新規 manual の INSERT 時に status=Draft を明示代入
+        // (どちらも生成経路 = 新規行生成 + 同一 tx。既存行への並行書き込みではないため
+        //  ロック規約の趣旨に整合)
         'Services/Manual/VideoManualService.php',
     ];
 
@@ -676,11 +688,14 @@ class N {}
     expect(ScenarioWritePathScanner::containsAdoptedTakeIdWrite($captureTakeService))->toBeTrue();
 });
 
-test('T066: VideoManualService に status/scenario_version の明示 write が実在する (allowlist の degenerate PASS 防止 + 明示代入の fail-first 契約)', function (): void {
-    // duplicate() は複製 manual の初期状態を DB default に委ねず status=Draft / scenario_version=0 を
-    // 明示 write する。その **write 形** が VideoManualService 内に実在することを token ベースで担保する
-    // (明示代入を消すと write が消え、この契約テストが fail = fail-first。STATUS_WRITE_ALLOWED /
-    //  SCENARIO_VERSION_ALLOWED の degenerate = 未使用 allowlist 化も防ぐ)。
+test('T066: VideoManualService ファイルに status/scenario_version の明示 write が少なくとも 1 つ存在する (allowlist の degenerate PASS 防止。ファイル粒度でありメソッド単位の fail-first ではない)', function (): void {
+    // create() / duplicate() は新規 manual の初期状態を DB default に委ねず status=Draft /
+    // scenario_version=0 を明示 write する。その **write 形** が VideoManualService 内に実在することを
+    // token ベースで担保する (STATUS_WRITE_ALLOWED / SCENARIO_VERSION_ALLOWED の
+    // degenerate = 未使用 allowlist 化を防ぐ)。
+    // **メソッド単位の fail-first は本テストでは担えない** (create() の明示代入を消しても
+    // duplicate() が残れば通る)。create()/duplicate() それぞれの初期状態の保証は
+    // ManualServiceBoundaryTest / ManualDuplicateTest の behavioral テストが担う (T151)。
     // scenario_version は displayXxxJob の read があるため token 出現では区別できず、write 形で判定する。
     $appDir = ScenarioWritePathScanner::appDir();
     $videoManualService = (string) file_get_contents($appDir.'/Services/Manual/VideoManualService.php');
diff --git a/tests/Feature/Projects/ManualServiceBoundaryTest.php b/tests/Feature/Projects/ManualServiceBoundaryTest.php
index 995cb00..c8ef002 100644
--- a/tests/Feature/Projects/ManualServiceBoundaryTest.php
+++ b/tests/Feature/Projects/ManualServiceBoundaryTest.php
@@ -2,12 +2,16 @@
 
 declare(strict_types=1);
 
+use App\Enums\Manual\VideoManualStatus;
 use App\Models\Category;
 use App\Models\Project;
 use App\Models\VideoManual;
 use App\Services\Manual\CategoryService;
 use App\Services\Manual\VideoManualService;
 use Illuminate\Database\Eloquent\ModelNotFoundException;
+use Illuminate\Http\UploadedFile;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Storage;
 
 /*
  * Service 境界防御 (route binding とは別レイヤ)。
@@ -99,3 +103,67 @@
     expect($fresh?->title)->toBe('元のタイトル');
     expect($fresh?->category_id)->toBeNull();
 });
+
+/*
+ * 生成経路の初期状態契約 (T151)。
+ *
+ * create() が status / scenario_version を DB カラム default に委ねていた頃、戻り値の
+ * インスタンスは当該属性を持たず (INSERT に含めていないため hydrate されない)、
+ * 呼び出し側が `$manual->status->value` を読むと
+ * `ErrorException: Attempt to read property "value" on null` で落ちた (pipeline-smoke の
+ * fixture 段で実走観測)。以下は **refresh()/fresh() を挟まない戻り値インスタンスそのもの**に
+ * 対する契約であり、この形の再発を behavioral に検出する。
+ *
+ * **属性ごとにテストを分けてある**: 1 本にまとめると status の明示代入だけを消したときと
+ * scenario_version の明示代入だけを消したときの非対称 (片方だけ赤くなる) が観測できない
+ * (同一テスト内では最初の失敗で停止するため)。
+ *
+ * ScenarioWritePathInventoryTest の allowlist は**ファイル粒度**でありメソッド単位の
+ * fail-first を担えない。create() の明示代入を守るのは本テストである。
+ */
+
+test('VideoManualService::create の戻り値は refresh なしで status=Draft を保持する (category+SOP あり)', function (): void {
+    // 既定ディスクを fake する (SourceDocumentService::appendDocument は Storage::putFileAs を
+    // ディスク指定なし = 既定ディスクで呼ぶ。SourceDocumentUploadTest と同じ流儀)
+    Storage::fake();
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $category = Category::factory()->forProject($project)->create();
+    $document = UploadedFile::fake()->createWithContent('sop.txt', '手順 1: 装置の電源を入れる');
+
+    // pipeline-smoke が実際に踏んだ形 (category + SOP 同時アップロード) に寄せる。
+    // category ありにすることで associate 後の 2 度目の save を通っても属性が残ることを固定する。
+    $manual = app(VideoManualService::class)->create(
+        $project, 'テスト手順書', $category->id, $owner->id, $document,
+    );
+
+    expect($manual->status)->toBe(VideoManualStatus::Draft);
+    // 実走と同じ読み方 (修正前は "Attempt to read property \"value\" on null" で落ちる)
+    expect($manual->status->value)->toBe('draft');
+    expect($manual->category_id)->toBe($category->id);
+    expect($manual->sourceDocuments()->count())->toBe(1);
+});
+
+test('VideoManualService::create の戻り値は refresh なしで scenario_version=0 を保持する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    // 最短経路 (category なし / SOP なし)。status 契約と分けているのは mutation の観測のため。
+    $manual = app(VideoManualService::class)->create($project, 'テスト手順書', null, $owner->id);
+
+    expect($manual->scenario_version)->toBe(0);
+});
+
+test('VideoManualService::create が INSERT した行は DB 上も status=draft・scenario_version=0 である', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    $manual = app(VideoManualService::class)->create($project, 'テスト手順書', null, $owner->id);
+
+    // 戻り値だけ整えて DB が別値、の取り違え防止 (明示代入値が DB default と一致することの固定)
+    $fresh = $manual->fresh();
+    expect($fresh?->status)->toBe(VideoManualStatus::Draft);
+    expect($fresh?->scenario_version)->toBe(0);
+    // cast を経由しない生値
+    expect(DB::table('video_manuals')->where('id', $manual->id)->value('status'))->toBe('draft');
+});

```

## fail-first の実測 (施策 2 実装直後 / 施策 1 実装前)

```
# T151 fail-first (施策 2 実装直後 / 施策 1 実装前) の実測
# 実行: composer test -- --filter=ManualServiceBoundaryTest
# 実行時刻: 2026-08-11 15:45:55 JST
# HEAD: 5b8df89b733aa61774368efc7e10af8e5a672d21


 INFO Configuration cache cleared successfully. 

ensure-test-db: base DB already exists: app_test_f6cf2afc
{"tool":"pest","result":"failed","tests":9,"passed":7,"assertions":22,"duration_ms":9099,"failed":2,"failures":[{"test":"P\\Tests\\Feature\\Projects\\ManualServiceBoundaryTest::__pest_evaluable_VideoManualService__create_\u306e\u623b\u308a\u5024\u306f_refresh_\u306a\u3057\u3067_status_Draft_\u3092\u4fdd\u6301\u3059\u308b__category_SOP_\u3042\u308a_","file":"/workspace/.claude/worktrees/tasks/T151/tests/Feature/Projects/ManualServiceBoundaryTest.php","line":140,"message":"Failed asserting that null is identical to an object of class \"App\\Enums\\Manual\\VideoManualStatus\"."},{"test":"P\\Tests\\Feature\\Projects\\ManualServiceBoundaryTest::__pest_evaluable_VideoManualService__create_\u306e\u623b\u308a\u5024\u306f_refresh_\u306a\u3057\u3067_scenario__version_0_\u3092\u4fdd\u6301\u3059\u308b","file":"/workspace/.claude/worktrees/tasks/T151/tests/Feature/Projects/ManualServiceBoundaryTest.php","line":154,"message":"Failed asserting that null is identical to 0."}]}
Script bash scripts/run-test.sh handling the test event returned with error code 1

# 判定: RED (composer test は "returned with error code 1" で終了)
#  - テスト 1 (status 契約): null is identical to VideoManualStatus → RED
#  - テスト 2 (scenario_version 契約): null is identical to 0 → RED
#  - テスト 3 (DB 実値): GREEN (DB default が現に draft/0 を入れているため。設計の予測どおり)

```

## gate / mutation の実測

```
# T151 gate / mutation 実測ログ
#
# 詳細設計 §「gate が正しく赤くなることの確認手順」の G-2 / G-3 に対応する。
# 実行時刻: 2026-08-11 15:4x JST (worktree /workspace/.claude/worktrees/tasks/T151)

================================================================================
G-2: ScenarioWritePathInventoryTest のファイル粒度 gate が空回りしていないこと
================================================================================

前提 (誇張しない): `Services/Manual/VideoManualService.php` は T066 (duplicate()) の時点で
STATUS_WRITE_ALLOWED / SCENARIO_VERSION_ALLOWED の**両方に登録済み**である。allowlist は
**ファイル粒度**のため、create() に明示 write を足しても gate は新たに赤くならない。
したがって「登録前に gate が赤いことの確認」は、**allowlist から当該ファイルを一時除外すると
実際に赤くなる** (= 登録が load-bearing で空振りでない) ことの確認として実施した。

--- G-2a: STATUS_WRITE_ALLOWED から 'Services/Manual/VideoManualService.php' を一時除外 ---
$ composer test -- --filter=ScenarioWritePathInventoryTest
result=failed tests=11 passed=10 failed=1
message: video_manuals.status の書き込みは ScenarioService / AnalysisJobService の
         ロック済み経路のみです: Services/Manual/VideoManualService.php
         Failed asserting that two arrays are identical.
         -Array &0 []
         +Array &0 [ 0 => 'Services/Manual/VideoManualService.php' ]
→ RED (期待どおり。除外したファイルが violation として列挙された)

--- G-2b: SCENARIO_VERSION_ALLOWED から同ファイルを一時除外 ---
$ composer test -- --filter=ScenarioWritePathInventoryTest
result=failed tests=11 passed=10 failed=1
message: scenario_version に触れてよいのは ScenarioService (書き込み) と
         ScenarioDocumentData (読み取り shape) のみです: Services/Manual/VideoManualService.php
→ RED (期待どおり)

--- 復帰確認 (両方を元に戻す) ---
$ git diff --stat tests/Architecture/ScenarioWritePathInventoryTest.php  → 差分なし
$ composer test -- --filter=ScenarioWritePathInventoryTest
result=passed tests=11 passed=11 assertions=45
→ GREEN

**この赤が実証しないこと**: create() の登録が load-bearing であること。この赤は既存の
duplicate() の write だけでも成立する。G-2 が実証するのは「ファイル粒度 gate が
空回りしていない」ことまでである。create() の明示代入を守るのは下の G-3 の behavioral
テストだけである。

================================================================================
G-3: create() の明示代入が消えたら気づけること (属性ごとに個別に)
================================================================================

--- G-3a: create() の forceFill から 'status' => VideoManualStatus::Draft の 1 行だけ削除 ---
$ composer test -- --filter=ManualServiceBoundaryTest
result=failed tests=9 passed=8 failed=1
failure: "…status_Draft_を保持する__category_SOP_あり_"
         Failed asserting that null is identical to an object of class
         "App\Enums\Manual\VideoManualStatus".
→ テスト 1 (status 契約) のみ RED / テスト 2 (scenario_version 契約) は GREEN のまま
  = **非対称を実測で観測**

--- G-3b: create() の forceFill から 'scenario_version' => 0 の 1 行だけ削除 ---
$ composer test -- --filter=ManualServiceBoundaryTest
result=failed tests=9 passed=8 failed=1
failure: "…scenario__version_0_を保持する"
         Failed asserting that null is identical to 0.
→ テスト 2 のみ RED / テスト 1 は GREEN のまま = **逆向きの非対称も観測**

--- 復帰確認 ---
$ composer test -- --filter=ManualServiceBoundaryTest
result=passed tests=9 passed=9
→ GREEN

同時に消していない (同時に消すと両方が同時に赤くなり、どの assertion がどの実装行を
守っているかの 1:1 対応を確認できない)。

================================================================================
G-1 (fail-first) は devnotes/20260811-1544-todo-T151/red-before-fix.txt が正本
================================================================================

================================================================================
G-4 (pipeline-smoke 実走) は実施していない
================================================================================
LLM を 3 段とも実呼び出しするため実行そのものが課金であり、ユーザーの明示承認を得ていない。
本修正の検証には必須ではない (fixture 段のこの 1 因は G-1〜G-3 で閉じている)。

```

## 検証結果 (worktree /workspace/.claude/worktrees/tasks/T151 で実測)

- `composer phpstan` → OK (No errors, 891 files, level 10)
- `composer test` → passed tests=4455 passed=4453 skipped=2 assertions=19177
- `vendor/bin/pint --test` → passed
- `pnpm lint` → passed / `pnpm typecheck` → passed
- `pnpm test` / `pnpm build` / `pnpm *:packages` / browser lane は **未実行**
  (frontend 変更ゼロのため省略。省略した事実を報告に明記している)

## 質問

上記観点で [Critical] があれば必ず挙げてほしい。特に観点 6 (AGENTS.md の要求が緩んでいないか) と
観点 7 (保証範囲の誇張) を厳しく見てほしい。

