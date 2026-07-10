# 使命・禁止事項・セキュリティ不変条件（AGENTS.md より）

## アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## セキュリティ不変条件（アプリ都合で緩めない）

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない(`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`)
2. **子は親に属する**: nested route の不整合は**認可より前に 404**(`NestedRouteIdorDefenseTest` の inventory に登録必須)
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`
7. **課金の冪等性**: チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。

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
10. DESIGN.md準拠（UI/frontend 変更を含む場合）: design token 経由の参照か、hex 直書きを増やさないか
11. Atomic Design準拠（UI/frontend 変更を含む場合）: atoms/molecules/organisms/features の責務分離・階層逆流なし・アイコンは Lucide

【確定仕様との整合も確認せよ】
- /workspace/doc/10_実装仕様.md §10.3 / §10.8-2（楽観ロック 409）/ §10.8-5（protected キー）/ §10.8-6（レンダ中禁止）
- /workspace/doc/09_詳細実装設計.md §9.4（document 単位保存 ★divergence）
- 概念設計（APPROVED 済み）: /workspace/devnotes/20260711-0007-scenario-editing/conceptual-design.md

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: scenario-editing（シナリオ編集: document 一括保存・楽観ロック）

作成: 2026-07-11

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

### コーディングルール
- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ずFactoryで生成**（`Model::create()` 手組み禁止）
- 新モデルを追加する設計では **対応するFactoryの作成も施策に含める** こと（本設計は新モデルなし）
- **DTO + JsonResource** パターン（AGENTS.md参照）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

[devnotes/20260711-0007-scenario-editing/conceptual-design.md](./conceptual-design.md)（Codex 概念レビュー APPROVED 済み）

要点:
- シナリオ（Cut 群 = step/point の 2 階層ツリー）を **document 単位で PUT 保存**（doc/09 §9.4 ★divergence）
- **楽観ロック**: `expected_version` 必須、versionずれ・rendering/analyzing 中は **409**（doc/10 §10.8-2 / §10.8-6）
- **protected キー不信**: `parent_cut_id` / `adopted_take_id` / `sort_order` / `type` は payload 禁止、サーバ導出（doc/10 §10.8-5）
- **共有不変条件（本設計で明文化）**: cuts / scenario_version / status を書く全経路は VideoManual 行を `lockForUpdate()` した同一トランザクション内で反映
- **v1 では既存 cut の階層/型変更を禁止**（既存 id は既存 type と一致する位置にのみ出現可、不一致 422）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | ルート追加 + IDOR inventory 登録 | `routes/web.php`, `tests/Architecture/NestedRouteIdorDefenseTest.php` | 高 |
| 2 | Scenario ドメイン型（enum / DTO / 例外 / Resource） | `app/Enums/Manual/ScenarioConflictType.php`, `app/DataTransferObjects/Manual/*.php`, `app/Exceptions/Manual/ScenarioConflictException.php`, `app/Http/Resources/Manual/*.php` | 高 |
| 3 | `UpdateScenarioRequest`（ネスト保護キー + 本文検証 + 型付き入力変換） | `app/Http/Requests/Projects/UpdateScenarioRequest.php` | 高 |
| 4 | `ScenarioService::save()`（行ロック → guard → 2 段階 reconcile） | `app/Services/Manual/ScenarioService.php` | 高 |
| 5 | Controller（保存 endpoint + edit props 拡張） | `app/Http/Controllers/Projects/ManualScenarioController.php`, `app/Http/Controllers/Projects/VideoManualController.php` | 高 |
| 6 | フロント: 型 + CSRF ヘルパ + ScenarioEditor + Edit ページ拡張 | `resources/js/types/manual.ts`, `resources/js/lib/csrf.ts`, `resources/js/components/organisms/RecentAuthModal.svelte`, `resources/js/components/features/manual/ScenarioEditor.svelte`, `resources/js/pages/Manuals/Edit.svelte` | 高 |
| 7 | テスト（Feature / Service 境界 / Vitest） | `tests/Feature/Projects/ScenarioUpdateTest.php`, `tests/Feature/Projects/ManualServiceBoundaryTest.php`, `tests/js/pages/ManualsEdit.test.ts`, `tests/js/components/features/ScenarioEditor.test.ts` | 高 |
| 8 | ドキュメント（divergence 登録・共有ロック規約） | `docs/template-divergence.md`, `docs/architecture.md`, `AGENTS.md`（ドメイン固有規約） | 中 |

全施策は **テストファースト**（施策 7 のテストを先に書き fail を確認 → 実装 → green）。

---

## 施策 1: ルート追加 + IDOR inventory 登録

### 変更箇所
- `routes/web.php`（L355-363 の `Route::scopeBindings()` グループ）
- `tests/Architecture/NestedRouteIdorDefenseTest.php`（inventory 関数）

### 波及変更
- TypeScript型定義: なし（URL はフロントで文字列組み立て。既存規約どおり）
- API Resource/DTO: なし
- テストファイル: `NestedRouteIdorDefenseTest` は deny-by-default のため**登録しないと fail する**（これ自体がテストファーストの fail になる）

### 現行コード
```php
Route::scopeBindings()->group(function (): void {
    Route::get('/projects/{project}/manuals/{manual}', [VideoManualController::class, 'show'])
        ->name('projects.manuals.show');
    Route::get('/projects/{project}/manuals/{manual}/edit', [VideoManualController::class, 'edit'])
        ->name('projects.manuals.edit');
    Route::patch('/projects/{project}/manuals/{manual}', [VideoManualController::class, 'update'])
        ->name('projects.manuals.update');
    Route::delete('/projects/{project}/manuals/{manual}', [VideoManualController::class, 'destroy'])
        ->name('projects.manuals.destroy');
});
```

### 変更後コード
```php
Route::scopeBindings()->group(function (): void {
    Route::get('/projects/{project}/manuals/{manual}', [VideoManualController::class, 'show'])
        ->name('projects.manuals.show');
    Route::get('/projects/{project}/manuals/{manual}/edit', [VideoManualController::class, 'edit'])
        ->name('projects.manuals.edit');
    Route::patch('/projects/{project}/manuals/{manual}', [VideoManualController::class, 'update'])
        ->name('projects.manuals.update');
    // シナリオ document 一括保存 (doc/09 §9.4 / doc/10 §10.3)。同一オリジン XHR (JSON 応答)。
    // {manual} ∈ {project} は scopeBindings、{project} ∈ current org は
    // project.in-current-org middleware + controller inline guard の 2 層 (既存 group が担保)
    Route::put('/projects/{project}/manuals/{manual}/scenario', [ManualScenarioController::class, 'update'])
        ->name('projects.manuals.scenario.update');
    Route::delete('/projects/{project}/manuals/{manual}', [VideoManualController::class, 'destroy'])
        ->name('projects.manuals.destroy');
});
```

`NestedRouteIdorDefenseTest` の inventory に追記:
```php
'projects.manuals.show' => $s,
'projects.manuals.edit' => $s,
'projects.manuals.update' => $s,
// シナリオ document 保存 (PUT)。{manual} は $project->manuals() 経由 (scopeBindings)
'projects.manuals.scenario.update' => $s,
'projects.manuals.destroy' => $s,
```

補足: この route は既存の業務 route group（`project.in-current-org` + `require-active-subscription` +
auth/verified）内にあるため、`ProjectRouteCurrentOrgGuardTest`（deny-by-default）は自動で網羅する。
cross-org 404 の存在オラクル封じは middleware 層で既に構造化されている。

### PHPStan適合チェック
- [x] route 定義のみ（型影響なし）

### テスト計画
- [ ] `NestedRouteIdorDefenseTest` が未登録時に fail する（deny-by-default）ことを確認 → 登録で green
- [ ] Feature: cross-project `{manual}` で PUT → 404（施策 7）

### リスク
- なし（既存 group への 1 route 追加）

---

## 施策 2: Scenario ドメイン型（enum / DTO / 例外 / Resource）

### 変更箇所（すべて新規）
- `app/Enums/Manual/ScenarioConflictType.php`
- `app/DataTransferObjects/Manual/ScenarioPointData.php`
- `app/DataTransferObjects/Manual/ScenarioStepData.php`
- `app/DataTransferObjects/Manual/ScenarioDocumentData.php`
- `app/DataTransferObjects/Manual/ScenarioPointInput.php`
- `app/DataTransferObjects/Manual/ScenarioStepInput.php`
- `app/DataTransferObjects/Manual/ScenarioSaveInput.php`
- `app/Exceptions/Manual/ScenarioConflictException.php`
- `app/Http/Resources/Manual/ScenarioResource.php`
- `app/Http/Resources/Manual/ScenarioConflictResource.php`

### 波及変更
- TypeScript型定義: `types/manual.ts` に対応 interface（施策 6）
- API Resource/DTO: 本施策が定義元
- テストファイル: Feature テストが応答 shape を検証（施策 7）

### 変更後コード（主要部）

出力系 DTO（edit props と保存成功応答の**共通 shape**。RecentAuthRequiredDto と同じ
`final readonly` + 明示 toArray 規約）:

```php
/**
 * シナリオの急所 (point) 1 行。edit 画面 props / 保存成功応答の共通 shape。
 * TS 側 types/manual.ts の ScenarioPoint と対で保守する。
 */
final readonly class ScenarioPointData
{
    public function __construct(
        public int $id,
        public string $scene,
        public string $shotType,
        public ?string $shootingPoint,
        public string $narration,
        public ?string $subtitlePrimary,
        public string $subtitleSecondary,
        public ?string $materialType,
        public ?int $staticDisplaySeconds,
    ) {}

    public static function fromCut(Cut $cut): self
    {
        return new self(
            id: $cut->id,
            scene: $cut->scene,
            shotType: $cut->shot_type->value,
            shootingPoint: $cut->shooting_point,
            narration: $cut->narration,
            subtitlePrimary: $cut->subtitle_primary,
            subtitleSecondary: $cut->subtitle_secondary,
            materialType: $cut->material_type?->value,
            staticDisplaySeconds: $cut->static_display_seconds,
        );
    }

    /**
     * @return array{id: int, scene: string, shot_type: string, shooting_point: string|null,
     *   narration: string, subtitle_primary: string|null, subtitle_secondary: string,
     *   material_type: string|null, static_display_seconds: int|null}
     */
    public function toArray(): array { /* snake_case で列挙 */ }
}
```

```php
/** シナリオの手順 (step) 1 行 + 配下の急所。 */
final readonly class ScenarioStepData
{
    /** @param list<ScenarioPointData> $points */
    public function __construct(
        public int $id,
        public string $scene,
        public string $shotType,
        public ?string $shootingPoint,
        public string $narration,
        public ?string $subtitlePrimary,
        public string $subtitleSecondary,
        public ?string $materialType,
        public ?int $staticDisplaySeconds,
        public array $points,
    ) {}
    // fromCut(Cut $cut, list<ScenarioPointData> $points): self / toArray(): array{...}
}
```

```php
/**
 * シナリオ document 全体 (steps→points ツリー + 楽観ロック version)。
 * fromManual() が sort_order 順に step/point を組み上げる唯一の変換点。
 */
final readonly class ScenarioDocumentData
{
    /** @param list<ScenarioStepData> $steps */
    public function __construct(
        public int $scenarioVersion,
        public array $steps,
    ) {}

    public static function fromManual(VideoManual $manual): self
    {
        $cuts = $manual->cuts()->orderBy('sort_order')->get();
        $steps = [];
        foreach ($cuts->whereNull('parent_cut_id')->values() as $step) {
            $points = $cuts->where('parent_cut_id', $step->id)->values()
                ->map(fn (Cut $cut): ScenarioPointData => ScenarioPointData::fromCut($cut))->all();
            $steps[] = ScenarioStepData::fromCut($step, array_values($points));
        }

        return new self($manual->scenario_version, $steps);
    }

    /**
     * @return array{scenario_version: int, steps: list<array{...}>}
     */
    public function toArray(): array { /* ... */ }
}
```

入力系 DTO（FormRequest → Service の型付き受け渡し。validated() 配列の shape を 1 箇所で固定）:

```php
/** 保存 payload の急所 1 行 (id=null は新規)。 */
final readonly class ScenarioPointInput
{
    public function __construct(
        public ?int $id,
        public string $scene,
        public ShotType $shotType,
        public ?string $shootingPoint,
        public string $narration,
        public ?string $subtitlePrimary,
        public string $subtitleSecondary,
        public ?MaterialType $materialType,
        public ?int $staticDisplaySeconds,
    ) {}
}
// ScenarioStepInput = 同フィールド + /** @var list<ScenarioPointInput> */ public array $points
// ScenarioSaveInput = { public int $expectedVersion, /** @var list<ScenarioStepInput> */ public array $steps }
```

conflict 種別 enum + 例外 + Resource（RecentAuthRequired の 409 契約パターンを踏襲）:

```php
/** シナリオ保存が 409 になる理由の判別子。TS 側 ScenarioConflictType union と対。 */
enum ScenarioConflictType: string
{
    case VersionMismatch = 'version_mismatch';
    case Rendering = 'rendering';
    case Analyzing = 'analyzing';

    /** UI 向け説明文 (サーバ側で確定しクライアントの文言分岐を減らす) */
    public function message(): string { /* match で日本語文言 */ }
}
```

```php
/**
 * シナリオ保存の競合 (409)。Service が投げ、render() が JsonResource 応答を返す
 * (`response()->json()` 直書き禁止の遵守。RequireRecentAuth の 409 契約と同じ構造)。
 */
final class ScenarioConflictException extends Exception
{
    public function __construct(
        public readonly ScenarioConflictType $type,
        public readonly int $currentVersion,
    ) {
        parent::__construct($type->message());
    }

    public function render(Request $request): JsonResponse
    {
        return ScenarioConflictResource::make($this)
            ->response()
            ->setStatusCode(409);
    }
}
```

```php
/**
 * 409 ボディ ({ code, conflict_type, message, current_version })。
 * code 厳格一致でクライアントが自分宛て応答のみ処理する (recent_auth_required と同方式)。
 *
 * @property-read ScenarioConflictException $resource
 */
final class ScenarioConflictResource extends JsonResource
{
    public const string CODE = 'scenario_conflict';

    /** @var string|null */
    public static $wrap = null;

    /**
     * @return array{code: 'scenario_conflict', conflict_type: string, message: string, current_version: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => self::CODE,
            'conflict_type' => $this->resource->type->value,
            'message' => $this->resource->getMessage(),
            'current_version' => $this->resource->currentVersion,
        ];
    }
}
```

```php
/**
 * 保存成功応答 ({ scenario_version, steps })。edit props と同じ ScenarioDocumentData から生成。
 *
 * @property-read ScenarioDocumentData $resource
 */
final class ScenarioResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /** @return array{scenario_version: int, steps: list<array{...}>} */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
```

### PHPStan適合チェック
- [ ] 戻り値の型が明示されている（toArray は array shape PHPDoc）
- [ ] null安全（`?->` / named args、Assert 不要の readonly コンストラクタ渡し）
- [ ] DTOを返している（配列返却は toArray 変換点のみ）
- [ ] Genericsの型パラメータが正しい（`list<ScenarioStepData>` 等）

### テスト計画
- [ ] Feature テストで応答 shape（scenario_version / steps ツリー / 409 の code・conflict_type）を検証（施策 7 に集約）

### リスク
- step/point の DTO はフィールド重複があるが、階層/型を型システムで区別する（`points` の有無）
  ことを優先（union や nullable points より PHPStan 10 で扱いやすい）

---

## 施策 3: UpdateScenarioRequest

### 変更箇所
- 新規: `app/Http/Requests/Projects/UpdateScenarioRequest.php`

### 波及変更
- TypeScript型定義: 保存 payload の型 `ScenarioSavePayload`（施策 6）
- API Resource/DTO: `ScenarioSaveInput` への変換メソッドを本 Request に置く
- テストファイル: 保護キー 422 / 文字数 / 上限のテスト（施策 7）

### 変更後コード（骨子）

```php
/**
 * シナリオ document 一括保存 (doc/10 §10.8-5)。
 *
 * 入力境界の不変条件:
 * - 保護キー (parent_cut_id / adopted_take_id / video_manual_id 等) はトップレベルだけでなく
 *   steps.* / steps.*.points.* にも missing を明示 (ProhibitsProtectedKeys trait はトップレベル
 *   のみのため、ネスト配列は本 Request が自前で張る)
 * - sort_order / type もサーバ導出のため missing (§10.8-5: 構造から決定)
 * - id は「照合用」。存在検証は Service がロック下で行う (ここでは integer のみ)
 */
class UpdateScenarioRequest extends FormRequest
{
    use ProhibitsProtectedKeys;

    /** 有界入力 (DoS guard)。仕様確定までの暫定値 */
    private const int MAX_STEPS = 100;

    private const int MAX_POINTS_PER_STEP = 20;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return array_merge(
            [
                'expected_version' => ['required', 'integer', 'min:0'],
                'steps' => ['present', 'array', 'max:' . self::MAX_STEPS],
                'steps.*' => ['array'],
                'steps.*.points' => ['present', 'array', 'max:' . self::MAX_POINTS_PER_STEP],
                'steps.*.points.*' => ['array'],
            ],
            $this->cutRowRules('steps.*'),
            $this->cutRowRules('steps.*.points.*'),
            $this->nestedProtectedKeyRules('steps.*'),
            $this->nestedProtectedKeyRules('steps.*.points.*'),
            $this->protectedKeyMissingRules(),
        );
    }

    /**
     * cut 1 行分の本文フィールド検証 (step / point 共通)。
     * scene は必須 (カットの定義)、narration / subtitle_secondary は下書き途中の保存を許す
     * (nullable → Service 側で '' に正規化。DB は NOT NULL)。
     * subtitle_primary の max:100 は DB string(100) と一致。
     *
     * @return array<string, list<mixed>>
     */
    private function cutRowRules(string $prefix): array
    {
        return [
            "{$prefix}.id" => ['nullable', 'integer'],
            "{$prefix}.scene" => ['required', 'string', 'max:1000'],
            "{$prefix}.shot_type" => ['required', Rule::enum(ShotType::class)],
            "{$prefix}.shooting_point" => ['nullable', 'string', 'max:1000'],
            "{$prefix}.narration" => ['nullable', 'string', 'max:2000'],
            "{$prefix}.subtitle_primary" => ['nullable', 'string', 'max:100'],
            "{$prefix}.subtitle_secondary" => ['nullable', 'string', 'max:2000'],
            "{$prefix}.material_type" => ['nullable', Rule::enum(MaterialType::class)],
            "{$prefix}.static_display_seconds" => ['nullable', 'integer', 'min:1', 'max:60'],
        ];
    }

    /**
     * ネスト行に対する保護キー + サーバ導出キーの拒否 (存在するだけで 422)。
     *
     * @return array<string, list<string>>
     */
    private function nestedProtectedKeyRules(string $prefix): array
    {
        $rules = [];
        foreach ([...MassAssignmentProtectedKeys::all(), 'sort_order', 'type'] as $key) {
            $rules["{$prefix}.{$key}"] = ['missing'];
        }

        return $rules;
    }

    /** validated() を型付き入力 DTO に変換する唯一の変換点 (Assert で narrow)。 */
    public function toScenarioSaveInput(): ScenarioSaveInput { /* Assert::integerish 等で組み上げ */ }
}
```

設計判断:
- **narration / subtitle_secondary は下書き保存許容**（nullable → `''` 正規化）。doc/04 の編集 UX
  （セルを空にして後で埋める）を成立させるため。DB の NOT NULL は `''` で満たす。
  scene のみ必須（「何を撮るか」が無い行はカットとして無意味）。
- `steps.*.points` は `present`（キー必須・空配列可）— クライアント直列化の欠落バグを 422 で早期検出。
- `expected_version` の照合そのものは Service（ロック下）。Request は形式検証のみ。

### PHPStan適合チェック
- [ ] 戻り値の型が明示されている（rules の array shape、toScenarioSaveInput の DTO 戻り値）
- [ ] null安全（toScenarioSaveInput で `Webmozart\Assert\Assert` により narrow）
- [ ] DTOを返している（toScenarioSaveInput）
- [ ] Genericsの型パラメータが正しい

### テスト計画
- [ ] 保護キー送出 422: トップレベル `parent_cut_id`、`steps.0.parent_cut_id`、
      `steps.0.points.0.adopted_take_id`、`steps.0.sort_order`、`steps.0.type`
- [ ] `expected_version` 欠落 422
- [ ] `subtitle_primary` 101 文字 422 / `scene` 空 422
- [ ] steps 101 件 422 / points 21 件 422
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- ネスト `missing` ルールの列挙は `MassAssignmentProtectedKeys::all()` 由来のため、
  保護キー追加時に自動追従する（drift しない）

---

## 施策 4: ScenarioService::save()

### 変更箇所
- 新規: `app/Services/Manual/ScenarioService.php`

### 波及変更
- TypeScript型定義: なし
- API Resource/DTO: `ScenarioSaveInput`（入力）/ `ScenarioDocumentData`（出力）を使用
- テストファイル: `ScenarioUpdateTest` / `ManualServiceBoundaryTest`（施策 7）

### 変更後コード（骨子）

```php
/**
 * シナリオ (Cut 群) の document 単位保存 (doc/09 §9.4 / doc/10 §10.8-2,5,6)。
 *
 * シナリオ整合の共有不変条件 (本サービスが最初の準拠実装。後続の AI 解析 materialize /
 * RenderJob 状態遷移 / テイク採用 API も同じ規約に従う):
 *   「cuts / video_manuals.scenario_version / video_manuals.status を書き込む全経路は、
 *     対象 VideoManual 行を lockForUpdate() で取得した同一トランザクション内で反映する」
 *
 * - 直列化点: VideoManual 行ロック (親 relation 経由再解決で「子は親に属する」も同時担保)
 * - 409: rendering / analyzing 中、または expected_version 不一致 (ScenarioConflictException)
 * - 404: payload に他 manual の cut id 混入 (ModelNotFoundException。存在を漏らさない)
 * - 422: payload 内 id 重複、既存 cut の階層/型不一致 (v1 は階層変更禁止)
 */
class ScenarioService
{
    public function save(Project $project, VideoManual $manual, ScenarioSaveInput $input): ScenarioDocumentData
    {
        return DB::transaction(function () use ($project, $manual, $input): ScenarioDocumentData {
            // 1. 行ロック + 親子再解決 (cross-project は 404)
            /** @var VideoManual $locked */
            $locked = $project->manuals()->whereKey($manual->id)->lockForUpdate()->firstOrFail();

            // 2. 状態 guard (§10.8-6。analyzing は AI materialize との clobber 防止)
            if ($locked->status === VideoManualStatus::Rendering) {
                throw new ScenarioConflictException(ScenarioConflictType::Rendering, $locked->scenario_version);
            }
            if ($locked->status === VideoManualStatus::Analyzing) {
                throw new ScenarioConflictException(ScenarioConflictType::Analyzing, $locked->scenario_version);
            }

            // 3. 楽観ロック照合 (§10.8-2)
            if ($locked->scenario_version !== $input->expectedVersion) {
                throw new ScenarioConflictException(ScenarioConflictType::VersionMismatch, $locked->scenario_version);
            }

            // 4. 既存 cut 集合のロード + payload id の検証
            $existing = $locked->cuts()->get()->keyBy('id'); // Collection<int, Cut>
            $this->assertPayloadIds($existing, $input);      // 重複 422 / 異物 404 / 階層・型不一致 422

            // 5. reconcile (2 段階 + 削除)。$changed を追跡
            $changed = false;
            $keptIds = [];
            foreach ($input->steps as $stepIndex => $stepInput) {
                $step = $this->upsertCut($locked, $existing, $stepInput, CutType::Step, null, $stepIndex, $changed);
                $keptIds[] = $step->id;
                foreach ($stepInput->points as $pointIndex => $pointInput) {
                    $point = $this->upsertCut($locked, $existing, $pointInput, CutType::Point, $step->id, $pointIndex, $changed);
                    $keptIds[] = $point->id;
                }
            }
            // 段階 3: payload に無い既存 cut を削除 (配下 Take は FK cascade)
            $removed = $existing->keys()->diff($keptIds);
            if ($removed->isNotEmpty()) {
                $locked->cuts()->whereIn('id', $removed->all())->get()->each->delete();
                $changed = true;
            }

            // 6. version は成功保存で常に +1 (§10.8-2)。実変更時のみ published→ready / draft→ready
            $locked->forceFill(['scenario_version' => $locked->scenario_version + 1]);
            if ($changed) {
                $this->transitionStatusAfterEdit($locked, hasCuts: $keptIds !== []);
            }
            $locked->save();

            return ScenarioDocumentData::fromManual($locked);
        });
    }

    /**
     * 1 cut の update/create。既存 id は fill (本文) + forceFill (parent/sort/type は
     * サーバ導出値の明示代入)、新規は relation 経由 make + forceFill。
     * isDirty() を保存前に検査して $changed を更新する (意味差分 = 実変更判定)。
     *
     * @param \Illuminate\Support\Collection<int, Cut> $existing
     */
    private function upsertCut(
        VideoManual $locked,
        Collection $existing,
        ScenarioStepInput|ScenarioPointInput $input,
        CutType $type,
        ?int $parentCutId,
        int $sortOrder,
        bool &$changed,
    ): Cut { /* narration/subtitle_secondary の null→'' 正規化もここで行う */ }

    /**
     * payload id の検証:
     * - 重複 id → ValidationException (422)
     * - 当該 manual に無い id → ModelNotFoundException (404。tenant キー不信)
     * - step 位置の id が既存 point (またはその逆) → ValidationException (422。v1 は階層/型変更禁止)
     */
    private function assertPayloadIds(Collection $existing, ScenarioSaveInput $input): void { /* ... */ }

    /**
     * 実変更後の状態遷移:
     * - published → ready (§10.8-6: 完成動画は要再合成)
     * - draft → ready (cuts が 1 件以上になったとき。§10.2「ready = シナリオ確定・編集/撮影可」。
     *   自作シナリオ経路で撮影フェーズへ進めるようにする v1 設計判断)
     */
    private function transitionStatusAfterEdit(VideoManual $locked, bool $hasCuts): void { /* ... */ }
}
```

設計判断:
- **削除は Eloquent の each->delete()**（`whereIn()->delete()` の bulk でなく）: 将来 Cut に
  deleting イベント（S3 掃除等）が付いた際に素通りしない。件数は有界（≤2100）。
- **`$changed` の判定**: create / delete の発生、または既存 cut の `isDirty()`（fill + forceFill 後・
  save 前）。サーバ導出値（parent/sort/type）の変化も isDirty に含まれるため並べ替えも実変更になる。
- **draft→ready** は概念設計からの追加詳細（§10.2 の状態定義に基づく）。ready→ready / ready のまま
  cut 全削除は draft に**戻さない**（一度確定したシナリオの空編集は「編集中」であり後退遷移を
  自動では行わない。過剰な状態機械を作らない）。

### PHPStan適合チェック
- [ ] 戻り値の型が明示されている（save(): ScenarioDocumentData）
- [ ] null安全（`Collection<int, Cut>` の get() は ?Cut → Assert / firstOrFail）
- [ ] DTOを返している（ScenarioDocumentData）
- [ ] Genericsの型パラメータが正しい（`Collection<int, Cut>`、`list<int>`）
- [ ] `&$changed` 参照渡しが読みにくければ小さな可変ホルダー or 戻り値 tuple に変える（実装時判断。
      PHPStan 上はどちらも可）

### テスト計画（施策 7 に集約）
- [ ] materialize: steps+points 新規作成で parent_cut_id / sort_order / type がサーバ採番される
- [ ] 並べ替え反映（sort_order 0..N-1 gap 除去）
- [ ] 楽観ロック: version 不一致 409 + DB 不変
- [ ] rendering / analyzing 409
- [ ] published→ready（実変更時）/ no-op 保存では published 維持 + version は +1
- [ ] draft→ready（cuts ≥1 の実変更時）
- [ ] 異物 cut id 404 + DB 不変（Service 境界テストも）
- [ ] id 重複 422 / 階層・型不一致 422
- [ ] step 削除で配下 point も削除される（payload から外れた point の削除）

### リスク
- 削除→再作成を繰り返す編集で cut id が変わる（クライアントは応答の steps で id を取り込む
  設計のため実害なし。Take が付いた cut を誤って削除→再追加すると Take が消える点は
  UI の削除確認ダイアログで明示する）
- lockForUpdate は manual 行のみ（Project 行はロックしない）。カテゴリ等 project 集合との
  整合は本操作に無関係のため、直列化粒度を manual に絞るのは意図的（詳細は施策 8 の規約記録）

---

## 施策 5: Controller

### 変更箇所
- 新規: `app/Http/Controllers/Projects/ManualScenarioController.php`
- 変更: `app/Http/Controllers/Projects/VideoManualController.php`（edit の props 拡張）

### 波及変更
- TypeScript型定義: Edit ページ Props（施策 6）
- API Resource/DTO: 施策 2 を使用
- テストファイル: `ScenarioUpdateTest` / 既存 `VideoManualCrudTest` の edit props assertion 追記

### 変更後コード（骨子）

```php
/**
 * シナリオ document 一括保存 (同一オリジン XHR。応答は JsonResource)。
 * VideoManualController と同じ 2 層 URL 整合 guard (認可より前に 404)。
 */
class ManualScenarioController extends Controller
{
    use ResolvesCurrentOrganization;

    public function update(
        UpdateScenarioRequest $request,
        Project $project,
        VideoManual $manual,
        ScenarioService $scenarios,
    ): ScenarioResource {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('update', $manual);

        $document = $scenarios->save($project, $manual, $request->toScenarioSaveInput());

        return ScenarioResource::make($document);
    }
}
```

`VideoManualController::edit` の props 追加（現行 L120-131 に追記）:

```php
return Inertia::render('Manuals/Edit', [
    'project' => [ /* 既存 */ ],
    'manual' => [
        'id' => $manual->id,
        'title' => $manual->title,
        'category' => $manual->category_id,
        'status' => $manual->status->value,   // 追加 (rendering 中の警告表示用)
    ],
    'categories' => $this->categoryOptions($project),
    'scenario' => ScenarioDocumentData::fromManual($manual)->toArray(), // 追加
]);
```

認可: 既存 `VideoManualPolicy::update`（編集者 = project_admin / org 管理者）。撮影者
（project_member）は 403。新 ability は追加しない（過剰設計回避）。

### PHPStan適合チェック
- [ ] 戻り値の型が明示されている（ScenarioResource / Inertia\Response）
- [ ] null安全（既存 edit と同じ Assert パターン）
- [ ] DTOを返している
- [ ] Genericsの型パラメータが正しい

### テスト計画
- [ ] edit props に scenario（steps ツリー + scenario_version）と manual.status が載る（Inertia assertion）
- [ ] 撮影者 403 / guest リダイレクト / cross-org 404 / cross-project 404

### リスク
- edit props の追加は既存 `ManualsEdit.test.ts`（Vitest）の props fixture 更新が必要（施策 7 に含める）

---

## 施策 6: フロントエンド

### 変更箇所
- 変更: `resources/js/types/manual.ts`（型追加）
- 新規: `resources/js/lib/csrf.ts`（`csrfToken()` を RecentAuthModal から抽出）
- 変更: `resources/js/components/organisms/RecentAuthModal.svelte`（csrf ヘルパを import に置換）
- 新規: `resources/js/components/features/manual/ScenarioEditor.svelte`
- 変更: `resources/js/pages/Manuals/Edit.svelte`（シナリオ編集画面に拡張）

### 波及変更
- TypeScript型定義: 本施策が定義元
- API Resource/DTO: 施策 2 の shape と対で保守（コメントで相互参照）
- テストファイル: `ManualsEdit.test.ts` 更新 + `ScenarioEditor.test.ts` 新規（施策 7）

### 変更後コード（骨子）

`types/manual.ts` 追加分:

```ts
/** PHP: App\DataTransferObjects\Manual\ScenarioPointData と対 */
export interface ScenarioPoint {
    /** 既存 cut の id。クライアントで追加した未保存行は null */
    id: number | null;
    scene: string;
    shot_type: "hiki" | "yori";
    shooting_point: string | null;
    narration: string;
    subtitle_primary: string | null;
    subtitle_secondary: string;
    material_type: "video" | "still" | null;
    static_display_seconds: number | null;
}

export interface ScenarioStep extends ScenarioPoint {
    points: ScenarioPoint[];
}

/** PHP: ScenarioDocumentData と対 (edit props / PUT 成功応答の共通 shape) */
export interface ScenarioDocument {
    scenario_version: number;
    steps: ScenarioStep[];
}

/** PHP: App\Enums\Manual\ScenarioConflictType と対 (discriminated union) */
export type ScenarioConflictType = "version_mismatch" | "rendering" | "analyzing";

export interface ScenarioConflictBody {
    code: "scenario_conflict";
    conflict_type: ScenarioConflictType;
    message: string;
    current_version: number;
}
```

`lib/csrf.ts`（抽出。RecentAuthModal 内のローカル実装を移設し両者で共用 — 並走重複を残さない）:

```ts
/** XSRF-TOKEN cookie を読み X-XSRF-TOKEN ヘッダ値に変換する (同一オリジン XHR 用) */
export function csrfToken(): string { /* RecentAuthModal の現実装を移設 */ }
```

`ScenarioEditor.svelte`（features/manual 層。atoms/molecules/organisms のみ import — 階層遵守）:

- props: `{ projectId: number; manualId: number; scenario: ScenarioDocument }`
- `$state` の作業コピー（`structuredClone`）+ 保存済みスナップショット。dirty は
  `$derived`（JSON 比較）
- 操作: 手順追加 / 急所追加（各 step 行内）/ 行削除（step は「配下の急所と登録済みテイクも
  削除される」旨の ConfirmDialog）/ ▲▼ 並べ替え（同一スコープ内のみ）/ セル編集
  （Input / Textarea / Select / FormField、字幕①は maxlength 表示）
- 空 steps は EmptyState + 「最初の手順を追加」ボタン
- 保存（「シナリオを更新」ボタン。disabled にしない）:

```ts
async function save(): Promise<void> {
    saving = true;
    errors = {};
    conflict = null;
    try {
        const res = await fetch(`/projects/${projectId}/manuals/${manualId}/scenario`, {
            method: "PUT",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-XSRF-TOKEN": csrfToken(),
                "X-Requested-With": "XMLHttpRequest",
            },
            credentials: "same-origin",
            body: JSON.stringify({ expected_version: version, steps: payloadSteps() }),
        });
        if (res.ok) {
            const body = (await res.json()) as ScenarioDocument;
            applySaved(body); // id 取り込み + version 更新 + スナップショット更新 + 成功トースト
            return;
        }
        if (res.status === 409) {
            const body = (await res.json().catch(() => null)) as ScenarioConflictBody | null;
            if (body?.code === "scenario_conflict") { conflict = body; return; } // 作業コピーは保持
        }
        if (res.status === 422) { /* errors["steps.0.scene"] 形式を行別に表示 */ return; }
        genericError = "保存に失敗しました。時間をおいて再度お試しください。";
    } finally {
        saving = false;
    }
}
```

- 409 バナー: `conflict_type` で文言分岐（version_mismatch は「他の編集と競合しました」、
  rendering / analyzing は「処理中のため保存できません」）。「サーバの最新を取得」ボタンは
  ConfirmDialog で編集内容の破棄を明示同意 → `router.reload({ only: ["scenario", "manual"] })`
- dirty 離脱警告: `beforeunload` + Inertia `router.on("before")`（dirty 時 confirm）

`Manuals/Edit.svelte` 拡張:

- 既存メタフォームを「基本情報」Card として維持（ボタン文言を「基本情報を保存」に変更）
- その下に「シナリオ」セクションとして `<ScenarioEditor {scenario} projectId={project.id} manualId={manual.id} />`
- Props interface に `scenario: ScenarioDocument`、`manual.status: VideoManualStatus` を追加

DS 遵守: token/ramp のみ・Lucide（`Plus` / `Trash2` / `ChevronUp` / `ChevronDown`）・
FormField/atoms 経由・disabled 禁止（保存中は loading 表示、rendering 中も押下可でサーバ 409 を表示）。

### PHPStan適合チェック
- 対象外（TS）。`pnpm typecheck` / `pnpm lint` green が完了条件

### テスト計画
- [ ] `ScenarioEditor.test.ts`: 追加/削除/▲▼/dirty 判定/EmptyState/409 バナー表示（fetch モック）
- [ ] `ManualsEdit.test.ts`: 新 props で描画・2 保存系統の分離（「基本情報を保存」「シナリオを更新」）
- [ ] `atomic-import-graph` / `ds-purity` / `lucide-scoped-import`（既存 Architecture テストが自動検証）

### リスク
- Edit ページの肥大化 → ScenarioEditor へ切り出すことで pages は薄いまま
- fetch ベース保存は Inertia のエラーバッグを使わないため、422 の行別エラー描画を自前で持つ
  （`errors["steps.i.points.j.field"]` の key 規約を Laravel validator のキーと一致させる）

---

## 施策 7: テスト

### 変更箇所
- 新規: `tests/Feature/Projects/ScenarioUpdateTest.php`
- 変更: `tests/Feature/Projects/ManualServiceBoundaryTest.php`（ScenarioService 境界 2 件追記）
- 変更: `tests/Architecture/NestedRouteIdorDefenseTest.php`（施策 1）
- 新規: `tests/js/components/features/ScenarioEditor.test.ts`
- 変更: `tests/js/pages/ManualsEdit.test.ts`

### テスト一覧（Feature: ScenarioUpdateTest）

すべて Factory 生成（`CutFactory::forManual()` + 必要なら `->state()` で parent/sort を forceFill 相当に
セット。Factory に `asPointOf(Cut $step)` / `withSortOrder(int)` state を追加してもよい — Factory 変更も
本施策に含む）:

| # | テスト | 検証 |
|---|---|---|
| 1 | 編集者は steps+points を一括保存できる | 新規 materialize（type/parent_cut_id/sort_order サーバ採番・階層一致）、version+1、応答 shape |
| 2 | 既存 cut の本文更新が反映される | fill 対象フィールドのみ変化 |
| 3 | 並べ替えが反映される | sort_order が 0..N-1 で gap 除去 |
| 4 | payload から外した cut は削除される（step 削除で配下 point も） | 行数減・cascade |
| 5 | expected_version 不一致は 409 + 保存されない | conflict_type=version_mismatch、current_version、DB 不変 |
| 6 | rendering 中の保存は 409 | conflict_type=rendering、DB 不変 |
| 7 | analyzing 中の保存は 409 | conflict_type=analyzing |
| 8 | 実変更で published→ready | status 遷移 + version+1 |
| 9 | 実変更なし保存は published 維持 + version は +1 | no-op 判定 |
| 10 | 初回保存（cuts≥1）で draft→ready | 自作シナリオ経路 |
| 11 | 保護キーのネスト送出は 422 | steps.0.parent_cut_id / steps.0.points.0.adopted_take_id / steps.0.sort_order / steps.0.type / トップレベル video_manual_id |
| 12 | 他 manual の cut id 混入は 404 + DB 不変 | tenant キー不信 |
| 13 | payload 内 id 重複は 422 | |
| 14 | 既存 step の id を points 配下に置くと 422（逆も） | v1 階層/型変更禁止 |
| 15 | 撮影者（project_member）は 403 | 権限 |
| 16 | 未ログインは 401/redirect | |
| 17 | cross-org の manual への PUT は 404 | 存在オラクル封じ（middleware 層） |
| 18 | cross-project の manual への PUT は 404 | scopeBindings |
| 19 | steps/points 上限超過は 422 | 有界入力 |
| 20 | edit 画面 props に scenario ツリーと version が載る | Inertia assertion |

ManualServiceBoundaryTest 追記:
- `ScenarioService::save` は cross-project の VideoManual を拒否し DB を変更しない
  （ModelNotFoundException + cuts 数不変）

### Vitest
- `ScenarioEditor.test.ts`: 行追加/削除（確認ダイアログ）/▲▼/dirty/EmptyState/保存成功で id 反映/
  409 で作業コピー保持 + バナー
- `ManualsEdit.test.ts`: 新 props fixture・2 保存系統の見出し/ボタン

### 実行順序（テストファースト）
1. 施策 1 の inventory 未登録 fail → 登録
2. ScenarioUpdateTest を書く → 全件 fail 確認（route 404 / クラス不在）
3. 施策 2〜6 を実装 → green
4. `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint,typecheck,test,build` 全 green

### リスク
- 並列実行（`--parallel`）前提のため、テスト間の共有状態なし（Factory 都度生成）を守る

---

## 施策 8: ドキュメント

### 変更箇所
- `docs/template-divergence.md`: 「D{n} Cut のシナリオ編集は per-row CRUD でなく document 単位保存」
  を登録（logic-driven: 親子カスケード + 並べ替えの原子性。保証し続ける不変条件 =
  保護キー不信 / 認可前 404 / relation 経由 create を document 保存でも同じ機構
  （ネスト missing ルール・照合 id 404・scopeBindings）で維持、drift 防止テスト = ScenarioUpdateTest #11/12 と NestedRouteIdorDefenseTest）
- `docs/architecture.md`: シナリオ整合の共有不変条件（VideoManual 行ロック規約）と
  ScenarioService の位置づけを追記。**書き込み経路が 2 つ以上になった時点（AI 解析
  materialize / RenderJob / adopt API の追加時）で、経路 inventory を持つ Architecture テストへ
  昇格させる**旨も規約文に明記（概念レビュー Round 2 Suggestion への布石。現時点は経路が
  ScenarioService 1 つのため機械検証対象がなく、テスト化は見送り = 過剰設計回避）
- `AGENTS.md` の「ドメイン固有規約」TEMPLATE-MARKER 配下に共有ロック規約を 1 項追記
  （後続フェーズの LLM 開発者を拘束する正本）

### テスト計画
- ドキュメントのみ（機械検証は上記テスト群が担う）

### リスク
- なし

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 施策 1〜7 は DTO→Service→Controller→UI→テストが密結合した 1 機能で、分割コミットしても中間状態が動かない（route だけ生えて 500 等）。単一 worktree タスクで一気通貫実装し、テスト green + Codex 実装レビュー後に main へマージする |
| 競合リスク | `routes/web.php` / `NestedRouteIdorDefenseTest.php` / `types/manual.ts` / `Manuals/Edit.svelte` は他フィーチャと衝突しやすいが、現在並走タスクなし。`RecentAuthModal.svelte`（csrf 抽出）は挙動不変のリファクタのみ |

## 完了条件

- `composer test` / `composer phpstan`（level 10）/ `vendor/bin/pint --test` /
  `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` 全 green
- NestedRouteIdorDefenseTest / MassAssignmentSafetyTest / ProjectRouteCurrentOrgGuardTest /
  atomic-import-graph / ds-purity が変更後も green（不変条件の機械強制）
- docs 3 点（divergence / architecture / AGENTS ドメイン固有規約）更新済み

---

## 関連する現行コード（抜粋。全文は各パスを読み込み可）

### app/Services/Manual/VideoManualService.php（見本: Project 行ロック + relation 再解決）
class VideoManualService
{
    /** VideoManual 作成 (status は DB default の draft)。 */
    public function create(Project $project, string $title, ?int $categoryId, int $userId): VideoManual
    {
        return DB::transaction(function () use ($project, $title, $categoryId, $userId): VideoManual {
            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $manual = $locked->manuals()->make(['title' => $title]);
            $manual->forceFill(['created_by' => $userId])->save();
            if ($categoryId !== null) {
                // 保存時再解決: ロック済み project 配下から取得 (cross-project は 404)
                $category = $locked->categories()->whereKey($categoryId)->firstOrFail();
                $manual->category()->associate($category)->save();
            }

            return $manual;
        });
    }

    /** メタデータ更新 (title / category)。categoryId null は未分類化 (dissociate)。 */
    public function updateMeta(Project $project, VideoManual $manual, string $title, ?int $categoryId): VideoManual
    {
        return DB::transaction(function () use ($project, $manual, $title, $categoryId): VideoManual {
            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            // 子は親に属する: ロック済み親 relation から再解決 (cross-project は 404)
            /** @var VideoManual $lockedManual */
            $lockedManual = $locked->manuals()->whereKey($manual->id)->firstOrFail();
            $lockedManual->fill(['title' => $title]);
            if ($categoryId !== null) {
                $category = $locked->categories()->whereKey($categoryId)->firstOrFail();
                $lockedManual->category()->associate($category);
            } else {
                $lockedManual->category()->dissociate();
            }
            $lockedManual->save();

            return $lockedManual;
        });
    }

### app/Http/Controllers/Projects/VideoManualController.php の edit（現行。拡張対象）
    /** 編集フォーム (メタデータ = title / category) */
    public function edit(Request $request, Project $project, VideoManual $manual): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('update', $manual);

        return Inertia::render('Manuals/Edit', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            'manual' => [
                'id' => $manual->id,
                'title' => $manual->title,
                'category' => $manual->category_id,
            ],
            'categories' => $this->categoryOptions($project),
        ]);
    }

### app/Http/Requests/Concerns/ProhibitsProtectedKeys.php（トップレベルのみ missing を張る trait）
trait ProhibitsProtectedKeys
{
    /**
     * @param  list<string>  $except
     * @return array<string, list<string>>
     */
    protected function protectedKeyMissingRules(array $except = []): array
    {
        $rules = [];
        foreach (MassAssignmentProtectedKeys::all() as $key) {
            if (! in_array($key, $except, true)) {
                $rules[$key] = ['missing'];
            }
        }

        return $rules;
    }
}

### app/Support/Security/MassAssignmentProtectedKeys.php の対象キー
    public static function all(): array
    {
        return [
            // actor (操作主体は Auth から導出する)
            'user_id',
            'created_by_user_id',
            'created_by', // AI-CUE ドメイン (video_manuals) の actor キー (doc/10 §10.1 準拠の命名)
            'invited_by_user_id',
            // tenant / ownership (route・コンテキストから導出する)
            'organization_id',
            'custom_team_id',
            'laratrust_team_id',
            'current_organization_id',
            'project_id',
            'api_key_id',
            // AI-CUE ドメイン (Project ─< Category / VideoManual ─< SourceDocument / Cut ─< Take)
            'category_id',
            'video_manual_id',
            'source_document_id',
            'cut_id',
            'parent_cut_id',
            'adopted_take_id',
            // billing (Service / Seeder がサーバ側で導出する)
            'plan_id',
            'reservation_id',
            // secret (サーバ生成値)
            'token_hash',
            'key_hash',
        ];
    }

### app/Models/Cut.php（fillable と relation）
class Cut extends Model
{
    /** @use HasFactory<CutFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'type',
        'shot_type',
        'material_type',
        'sort_order',
        'scene',
        'shooting_point',
        'narration',
        'subtitle_primary',
        'subtitle_secondary',
        'static_display_seconds',
        'cut_length_ms',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CutType::class,
            'shot_type' => ShotType::class,
            'material_type' => MaterialType::class,
        ];
    }

    /**
     * @return BelongsTo<VideoManual, $this>
     */
    public function videoManual(): BelongsTo
    {
        return $this->belongsTo(VideoManual::class);
    }

    /**
     * 急所カットの親手順カット (自己参照)。
     *
     * @return BelongsTo<Cut, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_cut_id');
    }

    /**
     * @return HasMany<Take, $this>
     */
    public function takes(): HasMany
    {
        return $this->hasMany(Take::class);
    }

    /**
     * 採用テイク (循環 FK。採用 API は後続フェーズで cut->takes() 経由解決を必須とする)。
     *
     * @return BelongsTo<Take, $this>
     */
    public function adoptedTake(): BelongsTo
    {
        return $this->belongsTo(Take::class, 'adopted_take_id');
    }
}

### routes/web.php（manual グループ現行）
        // VideoManual (Project 配下の動画マニュアル)。一覧は projects.show が内包する。
        // {manual} は scopeBindings で $project->manuals() 経由の解決
        // (子→親不整合は認可より前に 404。NestedRouteIdorDefenseTest 登録済み)
        Route::get('/projects/{project}/manuals/create', [VideoManualController::class, 'create'])
            ->name('projects.manuals.create');
        Route::post('/projects/{project}/manuals', [VideoManualController::class, 'store'])
            ->name('projects.manuals.store');
        Route::scopeBindings()->group(function (): void {
            Route::get('/projects/{project}/manuals/{manual}', [VideoManualController::class, 'show'])
                ->name('projects.manuals.show');
            Route::get('/projects/{project}/manuals/{manual}/edit', [VideoManualController::class, 'edit'])
                ->name('projects.manuals.edit');
            Route::patch('/projects/{project}/manuals/{manual}', [VideoManualController::class, 'update'])
                ->name('projects.manuals.update');
            Route::delete('/projects/{project}/manuals/{manual}', [VideoManualController::class, 'destroy'])
                ->name('projects.manuals.destroy');
        });

### 409 契約の既存見本: app/Http/Middleware/RequireRecentAuth.php（抜粋）

### resources/js/pages/Manuals/Edit.svelte（現行はメタ編集のみ。全文 93 行、パス読み込み可）
### cuts テーブル: parent_cut_id nullOnDelete / adopted_take_id nullOnDelete / takes.cut_id cascadeOnDelete
### unique 制約: sort_order には無し（transient 衝突なし）
