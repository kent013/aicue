【アプリの使命 (North Star) — AGENTS.md より】
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

【禁止事項 — AGENTS.md より】
1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

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
10. DESIGN.md準拠（UI/frontend 変更を含む場合）: `/DESIGN.md` が design token の canonical source。color / radius / typography を token 経由で参照する設計か、hex 直書きを増やさないか。token 変更時は `resources/css/tokens.css` との同期を設計に織り込んでいるか（運用契約は `docs/design-system.md`）
11. Atomic Design準拠（UI/frontend 変更を含む場合）: `resources/js/components/` の `atoms/molecules/organisms/templates` の責務分離に沿った配置か。atom は単機能・無状態、molecule は atom の組合せという階層を逆流していないか。アイコンは Lucide 前提で、SVG 直書きを新設していないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

関連する現行コードはリポジトリ内で読み込み可能:
- resources/js/components/features/manual/ScenarioEditor.svelte (現行の 409 ハンドラ・バナー実装)
- tests/js/components/features/manual/ScenarioEditor.test.ts (既存 25 件)
- app/Http/Controllers/Projects/VideoManualController.php / app/Http/Controllers/Capture/CaptureManualController.php
- app/Support/Seo/SeoManager.php / config/seo.php / tests/Feature/Seo/SeoHeadCompositionTest.php
- app/Exceptions/Manual/ScenarioConflictException.php / app/Http/Resources/Manual/ScenarioConflictResource.php
- resources/js/components/atoms/Alert.svelte

---

## 詳細設計書
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

    /** union 網羅の型固定 (kind 追加時にコンパイルエラーで表示漏れを検出する) */
    function assertNever(value: never): never {
        throw new Error(`未処理の saveFailure kind: ${JSON.stringify(value)}`);
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
                return assertNever(saveFailure);
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
        failureEl?.scrollIntoView({ block: "nearest" });
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

    {#if failureView}
        <!-- 操作点 (シナリオを更新) 直上の失敗フィードバック。tabindex=-1 で programmatic focus を受ける -->
        <div
            class="mt-6 focus:outline-none"
            bind:this={failureEl}
            tabindex="-1"
            data-testid="scenario-failure-region"
        >
            <Alert type={failureView.type} title={failureView.title} testId={failureView.testId}>
                <p>{failureView.message}</p>
                {#snippet action()}
                    {#if failureView?.showReloadCta}
                        <Button
                            variant="neutral"
                            size="sm"
                            onclick={() => (confirmingReload = true)}
                            testId="scenario-conflict-reload"
                        >
                            サーバの最新を取得
                        </Button>
                    {/if}
                {/snippet}
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
- `assertNever` はコンポーネントローカル関数（現時点で他に利用箇所がなく、
  「今必要なものだけ作る」に従い共有 lib へは昇格しない。2 箇所目が現れたら
  `resources/js/lib/` へ昇格）
- 失敗トーストは追加しない（概念設計の合意: 失敗は持続表示 + CTA が必要でトーストは冗長。
  error トーストは自動消去されず堆積する管理問題もある）
- 禁止事項 8: `save()` 冒頭 `if (saving) return` の既存ガードを維持（disabled にしない）
- `showReloadCta` が false の kind でも `action` snippet は渡る（`{#if}` 内で空になる）。
  Alert atom は `action` 提供時に `mt-4` の空 div を描画するが、これは既存実装
  （analyzing/rendering 競合時）と同一挙動で後退ではない

### PHPStan適合チェック
- [x] バックエンド変更なし（PHPStan 対象外。フロントは `pnpm typecheck` で
  `SaveFailure` union / `assertNever` の網羅性を検査）
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
- [ ] 新規: 失敗表示時に `focus({ preventScroll: true })` → `scrollIntoView({ block:
  "nearest" })` の順で呼ばれる（全 kind 共通処理の不変条件。conflict / forbidden /
  generic の 3 分岐それぞれで検証: 409 / 403 / 500 応答を与える）
- [ ] 新規: 409 (analyzing) はサーバ供給 message（「AI 解析中のため保存できません…」）を
  表示し、再取得 CTA（`scenario-conflict-reload`）を出さない
- [ ] 新規: 403 は `scenario-forbidden-error` に権限メッセージを表示し、作業コピーを
  破棄しない（dirty のまま）
- [ ] 新規: 保存成功で failure region が消える（`saveFailure = null` クリアの確認）

### リスク
- 既存テストがアラートの「セクション最上部」位置に依存していないことは確認済み
  （testId 取得のみ）。位置移設による既存テスト破壊はない
- `focus()` によりフォーカスが編集中フィールドから移る。ただし発火は保存ボタン押下後
  （フォーカスはボタン上）に限られ、入力途中を中断することはない
- Playwright 等の実ブラウザでは smooth scroll ではなく instant scroll（`behavior` 未指定）
  のため flake リスクなし

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

- [ ] `projects.manuals.create`: `<title>動画マニュアルの作成 | Acme</title>` を含む
- [ ] `projects.manuals.show`: manual title（例 `ネジ締め作業`）で
  `<title>ネジ締め作業 | Acme</title>` を含む
- [ ] `projects.manuals.edit`: `<title>ネジ締め作業 の編集 | Acme</title>` を含む
- [ ] `capture.manuals.show`（`/app/projects/{p}/manuals/{m}`、status=ready）:
  `<title>ネジ締め作業 の撮影 | Acme</title>` を含む
- [ ] Inertia 共有 prop `title` がサーバ描画 `<title>` と同一文字列
  （代表 1 画面 `projects.manuals.show` で `assertInertia(->where('title', ...))`）
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
