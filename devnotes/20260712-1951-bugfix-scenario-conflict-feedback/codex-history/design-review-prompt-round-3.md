Round 2 の指摘 (Warning 3 件、いずれも文書整合) にすべて対応しました。

1. [Warning] `unreachableFailureView(_value: never)` へリネーム (未使用引数規則に適合、never 網羅性は維持)
2. [Warning] リスク欄を「`behavior: "auto"` を明示するため smooth scroll にならず、実ブラウザでも instant scroll で flake リスクなし」へ更新 (変更後コードとの矛盾解消)
3. [Warning] PHPStan適合チェックの旧関数名を `unreachableFailureView` へ修正し、検証対象が `pnpm typecheck` + `pnpm lint` (未使用引数規則) であることを明記

改訂後の詳細設計書全文を添付します。全体判定をお願いします。

---

## 詳細設計書 (Round 3 改訂版)
# 詳細設計: scenario-conflict-feedback (bug-hunt F-02 / F-05 対応)

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
- **DTO + JsonResource** パターン（AGENTS.md参照）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- フロントは Svelte 5 runes + DS token のみ / atomic 階層の単方向 import / アイコンは `@lucide/svelte`

## 概念設計リファレンス

`devnotes/20260712-1951-bugfix-scenario-conflict-feedback/conceptual-design.md`（conceptual-review Round 4 で APPROVED）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | F-02: ScenarioEditor 保存失敗フィードバックの再構成（union 化・操作点直近表示・focus/scroll・403 分岐） | `resources/js/components/features/manual/ScenarioEditor.svelte`, `tests/js/components/features/manual/ScenarioEditor.test.ts` | High |
| 2 | F-05: 動画マニュアル関連 4 画面の固有 `<title>` 供給 | `config/seo.php`, `app/Http/Controllers/Projects/VideoManualController.php`, `app/Http/Controllers/Capture/CaptureManualController.php`, `tests/Feature/Projects/ManualPageTitleTest.php`（新規） | Low |

実装コミットは施策 1（F-02）と施策 2（F-05）で分離する（概念設計の合意事項）。

---

## 施策 1: F-02 ScenarioEditor 保存失敗フィードバックの再構成

### 前提（コード調査で確定した現状）

T002 で 409 ハンドラ・競合バナー・「サーバの最新を取得」CTA・reseed は実装済みで
Vitest 25 件 green。bug-hunt が実ブラウザで「何も表示されない」と観測した原因は
**バナーの挿入位置（シナリオセクション最上部）が、最下部の「シナリオを更新」ボタン押下時の
ビューポート外になる**知覚可能性の欠落。保存ロジック・409 応答契約
（`{code, conflict_type, message, current_version}`）は無変更。

### 変更箇所
- ファイル: `resources/js/components/features/manual/ScenarioEditor.svelte`
  - state（L95-96）: `conflict` / `genericError` → `saveFailure` union に統合
  - `save()` / `handleResponse()`（L149-223）: 失敗パスを `showFailure()` 経由に統一 + 403 分岐追加
  - `reloadScenario()`（L282-306）: エラーパスも `showFailure()` 経由に統一
  - テンプレート（L484-508 のアラート 2 ブロックを削除し、L642 のアクション行直上へ移設）

### 波及変更
- TypeScript型定義: なし（`ScenarioConflictBody` / `ScenarioConflictType` 据え置き。
  `SaveFailure` union はコンポーネント内ローカル型）
- API Resource/DTO: なし（`ScenarioConflictResource` / 409 shape 無変更）
- テストファイル: `tests/js/components/features/manual/ScenarioEditor.test.ts`（追加のみ。
  既存 25 件は testId `scenario-conflict-banner` / `scenario-generic-error` /
  `scenario-conflict-reload` を維持するため無修正で green を保つ）
- バックエンド: なし

### 現行コード（抜粋）

```svelte
<script lang="ts">
    let conflict = $state<ScenarioConflictBody | null>(null);
    let genericError = $state<string | null>(null);

    async function save(): Promise<void> {
        if (saving) return; // 多重送信ガード (disabled にはしない。押下は受けて即 return)
        saving = true;
        errors = {};
        conflict = null;
        genericError = null;
        try {
            const res = await putScenario();
            await handleResponse(res);
        } catch {
            genericError = "通信に失敗しました。接続を確認して再度お試しください。";
        } finally {
            saving = false;
        }
    }

    async function handleResponse(res: Response, retried = false): Promise<void> {
        // ...
        if (res.status === 409) {
            const body = (await res.json().catch(() => null)) as ScenarioConflictBody | null;
            if (body?.code === "scenario_conflict") {
                conflict = body;
                return;
            }
        }
        // ...(403 分岐なし)
        genericError = "保存に失敗しました。時間をおいて再度お試しください。";
    }
</script>

<section aria-label="シナリオ編集">
    {#if conflict}
        <Alert type="warning" title={CONFLICT_TITLES[conflict.conflict_type]} testId="scenario-conflict-banner">
            ...
        </Alert>
    {/if}
    {#if genericError}
        <div class="mt-3"><Alert type="danger" testId="scenario-generic-error">...</Alert></div>
    {/if}
    <!-- (長いフォーム) -->
    <div class="mt-6 flex items-center gap-2">
        <Button onclick={save} loading={saving} testId="scenario-submit">シナリオを更新</Button>
        ...
    </div>
</section>
```

### 変更後コード

```svelte
<script lang="ts">
    import { tick } from "svelte";
    // (既存 import は維持。addToast の成功トーストも既存のまま)

    /**
     * 保存失敗フィードバックの判別可能 union。
     * - conflict: 409 (scenario_conflict 契約。理由はサーバ供給 message)
     * - forbidden: 403 (セッション途中の権限剥奪等。将来の再ログイン導線はこの分岐に足す)
     * - generic: 通信断・5xx・shape 不一致などその他の失敗
     */
    type SaveFailure =
        | { kind: "conflict"; body: ScenarioConflictBody }
        | { kind: "forbidden" }
        | { kind: "generic"; message: string };

    /** アラート描画用の表示モデル (kind → 見た目の導出を switch 1 箇所に集約) */
    interface FailureView {
        type: "warning" | "danger";
        title?: string;
        message: string;
        showReloadCta: boolean;
        testId: string;
    }

    let saveFailure = $state<SaveFailure | null>(null);
    /** 失敗アラートの focus 対象 wrapper (tabindex=-1) */
    let failureEl = $state<HTMLDivElement | null>(null);

    /**
     * union 網羅の型固定 (kind 追加時は引数の never 不一致でコンパイルエラーになり
     * 表示漏れを検出する)。runtime に到達した場合は throw せず汎用 fallback を返す
     * ($derived 内の throw で画面全体を巻き込まない。詳細レビュー合意)。
     */
    function unreachableFailureView(_value: never): FailureView {
        return {
            type: "danger",
            message: "保存に失敗しました。時間をおいて再度お試しください。",
            showReloadCta: false,
            testId: "scenario-generic-error",
        };
    }

    const failureView = $derived.by((): FailureView | null => {
        if (saveFailure === null) return null; // null 先処理 → switch (概念レビュー合意)
        switch (saveFailure.kind) {
            case "conflict":
                return {
                    type: "warning",
                    title: CONFLICT_TITLES[saveFailure.body.conflict_type],
                    message: saveFailure.body.message,
                    showReloadCta: saveFailure.body.conflict_type === "version_mismatch",
                    testId: "scenario-conflict-banner",
                };
            case "forbidden":
                return {
                    type: "danger",
                    message:
                        "この操作を行う権限がありません。ページを再読み込みして状態を確認してください。",
                    showReloadCta: false,
                    testId: "scenario-forbidden-error",
                };
            case "generic":
                return {
                    type: "danger",
                    message: saveFailure.message,
                    showReloadCta: false,
                    testId: "scenario-generic-error",
                };
            default:
                return unreachableFailureView(saveFailure);
        }
    });

    /**
     * 失敗フィードバックの単一表示経路 (全 kind 共通)。state 確定 → tick() で DOM 反映を
     * 待ち → focus({preventScroll}) → scrollIntoView({block:"nearest"}) の順で知覚させる。
     * 明示呼び出し限定 ($effect の state 監視にしない = 無関係な再レンダで再発火しない)。
     * focus 既定スクロールは抑止し、スクロール制御を scrollIntoView に一本化する
     * (完全可視ならスクロールは原則発生せず、連続失敗時のジャンプを起こしにくい)。
     */
    async function showFailure(failure: SaveFailure): Promise<void> {
        saveFailure = failure;
        await tick();
        failureEl?.focus({ preventScroll: true });
        // UA 差異を残さないよう block/inline/behavior を全指定で固定 (Vitest は引数完全一致で担保)
        failureEl?.scrollIntoView({ block: "nearest", inline: "nearest", behavior: "auto" });
    }

    async function save(): Promise<void> {
        if (saving) return; // 多重送信ガード (disabled にはしない。押下は受けて即 return)
        saving = true;
        errors = {};
        saveFailure = null; // 前回の失敗表示をクリア (再保存成功後に旧エラーを残さない)
        try {
            const res = await putScenario();
            await handleResponse(res);
        } catch {
            await showFailure({
                kind: "generic",
                message: "通信に失敗しました。接続を確認して再度お試しください。",
            });
        } finally {
            saving = false;
        }
    }

    async function handleResponse(res: Response, retried = false): Promise<void> {
        if (res.ok) {
            const body = (await res.json().catch(() => null)) as unknown;
            if (isScenarioDocument(body)) {
                applySaved(body);
                return;
            }
            await showFailure({
                kind: "generic",
                message: "保存結果の取得に失敗しました。画面を再読み込みしてください。",
            });
            return;
        }
        if (res.status === 419 && !retried) {
            // (既存の CSRF 再取得 + 1 回リトライ。変更なし)
            await fetch(window.location.pathname, {
                credentials: "same-origin",
                headers: { Accept: "text/html" },
            });
            await handleResponse(await putScenario(), true);
            return;
        }
        if (res.status === 401 || res.status === 419) {
            await showFailure({
                kind: "generic",
                message:
                    "セッションが切れました。別のタブでログインし直してから、もう一度保存してください。",
            });
            return;
        }
        if (res.status === 403) {
            // 権限剥奪など。理由を明示する (汎用「時間をおいて再試行」への誤誘導をやめる)
            await showFailure({ kind: "forbidden" });
            return;
        }
        if (res.status === 409) {
            const body = (await res.json().catch(() => null)) as ScenarioConflictBody | null;
            if (body?.code === "scenario_conflict") {
                await showFailure({ kind: "conflict", body }); // 作業コピーは保持
                return;
            }
        }
        if (res.status === 422) {
            const body = (await res.json().catch(() => null)) as { errors?: unknown } | null;
            if (body !== null && isValidationErrors(body.errors)) {
                errors = body.errors; // 行別セル表示は既存のまま (スコープ外)
                return;
            }
        }
        await showFailure({
            kind: "generic",
            message: "保存に失敗しました。時間をおいて再度お試しください。",
        });
    }

    function reloadScenario(): void {
        confirmingReload = false;
        saveFailure = null;
        reloading = true;
        router.reload({
            only: ["scenario", "manual"],
            onSuccess: (visited) => {
                const latest: unknown = (visited.props as Record<string, unknown>).scenario;
                if (isScenarioDocument(latest)) {
                    reseed(latest);
                    return;
                }
                void showFailure({
                    kind: "generic",
                    message: "最新シナリオの取得に失敗しました。画面を再読み込みしてください。",
                });
            },
            onError: () => {
                void showFailure({
                    kind: "generic",
                    message: "最新シナリオの取得に失敗しました。画面を再読み込みしてください。",
                });
            },
            onFinish: () => {
                reloading = false;
            },
        });
    }
</script>

<section aria-label="シナリオ編集">
    <!-- (セクション最上部のアラート 2 ブロックは削除し、以下へ移設) -->

    <!-- ... 空状態 / steps ツリー / 手順を追加 (既存のまま) ... -->

    <!-- 再取得 CTA (トップレベル snippet として宣言し、必要な場合のみ Alert の action prop へ渡す) -->
    {#snippet reloadAction()}
        <Button
            variant="neutral"
            size="sm"
            onclick={() => (confirmingReload = true)}
            testId="scenario-conflict-reload"
        >
            サーバの最新を取得
        </Button>
    {/snippet}

    {#if failureView}
        <!-- 操作点 (シナリオを更新) 直上の失敗フィードバック。tabindex=-1 で programmatic focus を受ける -->
        <div
            class="mt-6 focus:outline-none"
            bind:this={failureEl}
            tabindex="-1"
            data-testid="scenario-failure-region"
        >
            <Alert
                type={failureView.type}
                title={failureView.title}
                action={failureView.showReloadCta ? reloadAction : undefined}
                testId={failureView.testId}
            >
                <p>{failureView.message}</p>
            </Alert>
        </div>
    {/if}

    <div class="mt-6 flex items-center gap-2">
        <Button onclick={save} loading={saving} testId="scenario-submit">シナリオを更新</Button>
        {#if dirty}
            <span class="text-caption text-text-secondary" data-testid="scenario-dirty-indicator">
                未保存の変更があります
            </span>
        {/if}
    </div>
</section>
```

補足（実装ノート）:
- `CONFLICT_TITLES` / `ConfirmDialog`（再取得の明示同意）/ `reseed` / dirty 離脱ガード /
  419 自動リトライ / 成功トースト（`addToast("success", ...)`）は既存のまま
- `unreachableFailureView` はコンポーネントローカル関数（現時点で他に利用箇所がなく、
  「今必要なものだけ作る」に従い共有 lib へは昇格しない。2 箇所目が現れたら
  `resources/js/lib/` へ昇格）
- 失敗トーストは追加しない（概念設計の合意: 失敗は持続表示 + CTA が必要でトーストは冗長。
  error トーストは自動消去されず堆積する管理問題もある）
- 禁止事項 8: `save()` 冒頭 `if (saving) return` の既存ガードを維持（disabled にしない）
- `action` prop は `showReloadCta` が true のときのみ渡す（トップレベル snippet の条件付き
  受け渡し）。false の kind で Alert が空の `mt-4` div を描画する既存の余白問題も同時に解消
- 403 の文言はクライアント固定とし、403 応答 body の message は表示しない:
  現状サーバの 403 は Laravel 既定文言（英語 "This action is unauthorized."）であり
  そのまま出すと UX が悪化する上、認可失敗の内部状態を漏らさない方針（セキュリティ観点）
  とも整合する。固定文言は Vitest fixture として厳密一致で登録し、意図しない詳細化を防ぐ

### PHPStan適合チェック
- [x] バックエンド変更なし（PHPStan 対象外。フロントは `pnpm typecheck` で
  `SaveFailure` union / `unreachableFailureView`（never 引数）の網羅性を検査し、
  `pnpm lint` で未使用引数規則（`_value` プレフィックス）を通す）
- [x] DTO/JsonResource 契約無変更（`ScenarioConflictResource` 据え置き）

### テスト計画（Vitest: `tests/js/components/features/manual/ScenarioEditor.test.ts`）

セットアップ追加: jsdom は `scrollIntoView` 未実装のため
`Element.prototype.scrollIntoView = vi.fn()` を beforeEach で注入し、
`vi.spyOn(HTMLElement.prototype, "focus")` と併用して呼び出し順
（`mock.invocationCallOrder`）と引数を検証する。

- [ ] 既存 25 件が無修正で green（testId 3 種・409 契約・reseed 挙動の互換確認）
- [ ] 新規: 409 (version_mismatch) で失敗リージョンが「シナリオを更新」ボタンの直前に
  描画される（`compareDocumentPosition` で failure region が submit ボタンに先行し、
  かつ同一親配下にあることを検証）
- [ ] 新規: 失敗表示時に `focus({ preventScroll: true })` →
  `scrollIntoView({ block: "nearest", inline: "nearest", behavior: "auto" })` の順で
  呼ばれる（引数は完全一致で検証。全 kind 共通処理の不変条件。conflict / forbidden /
  generic の 3 分岐それぞれで検証: 409 / 403 / 500 応答を与える）
- [ ] 新規: 409 (analyzing) はサーバ供給 message（「AI 解析中のため保存できません…」)を
  表示し、再取得 CTA（`scenario-conflict-reload`）を出さない（空 action 余白も出さない =
  `action` prop 未提供）
- [ ] 新規: 403 は `scenario-forbidden-error` に固定文言「この操作を行う権限がありません。
  ページを再読み込みして状態を確認してください。」を厳密一致で表示し（文言 fixture 化 =
  意図しない内部情報の詳細化を防ぐ）、作業コピーを破棄しない（dirty のまま）
- [ ] 新規: 401 は「セッションが切れました。別のタブでログインし直してから、もう一度
  保存してください。」を表示する（generic kind への回帰テスト）
- [ ] 新規: 419 → 自動リトライ後も 419 の場合に同セッション切れメッセージを表示する
  （419 リトライ失敗経路の回帰テスト）
- [ ] 新規: 保存成功で failure region が消える（`saveFailure = null` クリアの確認）

### リスク
- 既存テストがアラートの「セクション最上部」位置に依存していないことは確認済み
  （testId 取得のみ）。位置移設による既存テスト破壊はない
- `focus()` によりフォーカスが編集中フィールドから移る。ただし発火は保存ボタン押下後
  （フォーカスはボタン上）に限られ、入力途中を中断することはない
- `behavior: "auto"` を明示するため smooth scroll にならず、Playwright 等の実ブラウザでも
  instant scroll で flake リスクなし

---

## 施策 2: F-05 動画マニュアル関連 4 画面の固有 `<title>` 供給

### 変更箇所
- ファイル: `config/seo.php`（L104-107 の projects セクション）
- ファイル: `app/Http/Controllers/Projects/VideoManualController.php` `show()`（L91）/ `edit()`（L144）
- ファイル: `app/Http/Controllers/Capture/CaptureManualController.php` `show()`（L87）

### 波及変更
- TypeScript型定義: なし（タイトルは既存の Inertia 共有 prop `title` 経由。
  `document-title.ts` が SPA 遷移も追従済み）
- API Resource/DTO: なし
- テストファイル: `tests/Feature/Projects/ManualPageTitleTest.php`（新規）。
  既存 `SeoHeadCompositionTest` / `SeoManagerTest` / `VideoManualCrudTest` /
  `CaptureManualBrowsingTest` は変更不要（`setPrivateTitle` は追加供給であり
  既存アサーションに影響しない）

### 現行コード

```php
// config/seo.php (抜粋)
'app_titles' => [
    // ...
    // プロジェクト (show は controller が setPrivateTitle でプロジェクト名を供給)
    'projects.index' => 'プロジェクト',
    'projects.create' => 'プロジェクトの作成',
    'projects.edit' => 'プロジェクトの編集',
],
```

```php
// VideoManualController::show() — SeoManager 未使用 (title は "AI-CUE" のみになる)
public function show(Request $request, Project $project, VideoManual $manual): Response
{
    $organization = $this->resolveCurrentOrganization($request);
    $this->resolveOrganizationProject($organization, $project);
    Gate::authorize('view', $manual);
    // ...
}
```

### 変更後コード

```php
// config/seo.php (抜粋)
'app_titles' => [
    // ...
    'projects.index' => 'プロジェクト',
    'projects.create' => 'プロジェクトの作成',
    'projects.edit' => 'プロジェクトの編集',
    // 動画マニュアル (show/edit/撮影 show は controller が setPrivateTitle で
    // マニュアル名を供給。create のみ静的 = 対象実体が未存在のため)
    'projects.manuals.create' => '動画マニュアルの作成',
],
```

```php
// app/Http/Controllers/Projects/VideoManualController.php
use App\Support\Seo\SeoManager;

/** 詳細 (撮影者も閲覧可) */
public function show(Request $request, Project $project, VideoManual $manual, SeoManager $seo): Response
{
    $organization = $this->resolveCurrentOrganization($request);
    // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
    $this->resolveOrganizationProject($organization, $project);
    Gate::authorize('view', $manual);

    // 動的固有名の per-page タイトル (noindex 維持。projects.show の参考実装踏襲)
    $seo->setPrivateTitle($manual->title);
    // ... (以降の props 構築は既存のまま)
}

/** 編集フォーム (メタデータ = title / category + シナリオ document) */
public function edit(Request $request, Project $project, VideoManual $manual, SeoManager $seo): Response
{
    $organization = $this->resolveCurrentOrganization($request);
    $this->resolveOrganizationProject($organization, $project);
    Gate::authorize('update', $manual);

    // 複数 manual の並行編集タブを判別できるよう動的固有名 (概念レビュー合意)
    $seo->setPrivateTitle($manual->title.' の編集');
    // ... (以降の props 構築は既存のまま)
}
```

```php
// app/Http/Controllers/Capture/CaptureManualController.php
use App\Support\Seo\SeoManager;

/** 撮影ナビ (cuts + 全 take メタ + 採用テイク署名 DL URL / ACK トークン) */
public function show(
    Request $request,
    Project $project,
    VideoManual $manual,
    TakeObjectStorage $storage,
    UploadTicketCodec $codec,
    SeoManager $seo,
): Response {
    $organization = $this->resolveCurrentOrganization($request);
    $this->resolveOrganizationProject($organization, $project); // 認可より前に 404
    Gate::authorize('view', $manual);

    // 撮影 PWA であることをタブ上で判別可能にする動的固有名
    $seo->setPrivateTitle($manual->title.' の撮影');
    // ... (以降の props 構築は既存のまま)
}
```

補足（実装ノート）:
- `video_manuals.title` は NOT NULL（`string(200)`）+ store/update とも required
  バリデーションのため `setPrivateTitle(string)` に null 安全（Assert 不要）
- `SeoManager` は request-scoped 束縛（`SeoServiceProvider`）。method injection で解決する
  （`ProjectController::show()` と同型）
- `capture.manuals.index` 等の 4 画面以外は finding 対象外（スコープ外を維持）

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（既存シグネチャの `Response` を維持、引数追加のみ）
- [x] null 安全（`$manual->title` は non-nullable string。Assert 不要）
- [x] DTO を返している（Inertia::render の props 構築は無変更）
- [x] Generics 影響なし

### テスト計画（Pest: `tests/Feature/Projects/ManualPageTitleTest.php` 新規）

パターンは `tests/Feature/Seo/SeoHeadCompositionTest.php` を踏襲
（`createOrganizationWithOwner()` + Factory、`<title>` 文字列 / Inertia 共有 prop の両面検証）:

- [ ] URL は `route('projects.manuals.create', ...)` 等の route 名解決で構築する
  （route 名変更時に `config('seo.app_titles')` キーの取り残しをテスト失敗で検知する）
- [ ] `projects.manuals.create`: `<title>動画マニュアルの作成 | Acme</title>` を含む
- [ ] `projects.manuals.show`: manual title（例 `ネジ締め作業`）で
  `<title>ネジ締め作業 | Acme</title>` を含む
- [ ] `projects.manuals.edit`: `<title>ネジ締め作業 の編集 | Acme</title>` を含む
- [ ] `capture.manuals.show`（`/app/projects/{p}/manuals/{m}`、status=ready）:
  `<title>ネジ締め作業 の撮影 | Acme</title>` を含む
- [ ] Inertia 共有 prop `title` がサーバ描画 `<title>` と同一文字列であることを
  show / edit / capture.show の 3 画面で `assertInertia(->where('title', ...))` 検証
  （create は静的 app_titles 経路で `SeoHeadCompositionTest` が既に契約固定済みのため
  `<title>` 検証のみ）
- [ ] noindex 維持（代表 1 画面で `<meta name="robots" content="noindex">` を確認）
- [ ] テストデータは `VideoManual::factory()->forProject($project)` で生成
  （`Model::create()` 手組みなし。個別 `DatabaseTransactions` 不使用）

### リスク
- `setPrivateTitle` は `app_titles` 既定より優先される既存契約（`SeoManagerTest` 固定済み）。
  既存画面のタイトルには影響しない（追加供給のみ）
- manual title にサイト名区切り `|` 等が含まれても `SeoTitle::compose` の既存挙動に従う
  （`projects.show` のプロジェクト名と同等。新規リスクなし）

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 2 施策とも既存ファイルへの小規模変更（スキーマ変更なし・API 契約無変更・新規コンポーネントなし）。F-02 はフロント単独、F-05 はタイトル供給の追加のみで、main の他 TODO と独立に安全にマージできる |
| 競合リスク | `ScenarioEditor.svelte` を触る他 TODO は現在なし。`VideoManualController` は F-01（queue worker）対応と波及が重ならない（show/edit の引数追加のみ）。コミットを F-02 / F-05 で分離するため部分 revert も容易 |
