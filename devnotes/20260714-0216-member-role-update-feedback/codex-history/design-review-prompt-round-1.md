# アプリの使命（North Star）

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」。v1 スコープ: 字幕のみ / 撮影は PWA / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項
1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

【思考原則】まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。
【ツール使用制限】コマンド実行・ファイル書き込みは一切行わず、テキスト分析に集中。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリの詳細設計をレビューしてください。

【前提環境】PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript / PHPStan level 10 / Pest / DTO + JsonResource / Laratrust RBAC。

【重要な文脈】本設計は bug-hunt finding F-02(High, claimed_success_no_change)の修正。brief は「サーバがサイレント成功でロールを破棄、バックエンドで422化せよ」と主張したが、設計者は現行コード実測で**サーバは既に ValidationException(role) を返し redirect-back + error bag となる(ConsoleRoleTransitionTest が固定済み、Inertia mutation は Accept: text/html で expectsJson=false)**ことを確認し、真因を**フロントの一方向 value 伝播と DOM 選択状態の乖離**に特定、修正をフロントに閉じている(バックエンドは回帰 assertion 追加のみ)。この判断の妥当性と、フロント実装(Svelte {#key} remount / onFinish フォーカス復帰 / in-flight disabled / aria 接続 / F-14 不変条件の保持)の正確性を重点評価してほしい。

【レビュー観点】1 正確性(ロジック/エッジ/null) 2 既存整合 3 PHPStan L10 4 テスト網羅 5 DTO/JsonResource 6 Inertia Props vs API 7 副作用/後退 8 波及変更網羅 9 セキュリティ 10 DESIGN.md準拠 11 Atomic Design準拠。

【出力形式】各施策ごと APPROVE/REQUEST_CHANGES、指摘は [Critical][Warning][Suggestion]、Critical/Warning は修正案必須、全体判定 APPROVED/CHANGES_REQUESTED、日本語。

---

## 詳細設計書

# 詳細設計: member-role-update-feedback

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`(招待送信等は `back()->with(...)`)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`）/ **RefreshDatabase** グローバル + `--parallel`（個別 `DatabaseTransactions` 禁止）
- テストデータは Factory 生成
- **DTO + JsonResource** パターン
- コードフォーマット: `composer fix`(Pint) / `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 runes + Inertia.js + TypeScript
- フロント: DS token のみ、component 階層の単方向 import、アイコンは `@lucide/svelte`
- 検証: `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`

## 概念設計リファレンス

`devnotes/20260714-0216-member-role-update-feedback/conceptual-design.md`（Round 4 APPROVED）

### 根本原因サマリー（重要）

brief の仮説「サーバが成功系で黙ってロールを破棄している。バックエンドで 422 化せよ」は**現行コード実測により棄却**。真因はフロント:

1. **サーバは既に正しい**。`OrganizationMembershipService::applyConsoleRole()` は Default Project 不在時に `ValidationException`(`role`)を送出し tx ごと rollback。`ConsoleRoleTransitionTest` が endpoint の error bag を固定済み。Inertia mutation は `Accept: text/html`(`RequireRecentAuth.php` L80)で `expectsJson()`=false のため、redirect-back(303)+ session error bag となり `page.props.errors` に共有される。finding の「303」はこの redirect-back。
2. **真因はフロント**の 2 欠陥: (A) 一方向 `value` 伝播と DOM 選択状態の乖離（拒否後、権威値 `member.roleState` が admin→admin で不変のため Svelte が `<select>` をユーザー選択のまま放置 = `claimed_success_no_change`）、(B) エラーが combobox から離れた位置に出る + Select が invalid/`aria-describedby` 未接続。

したがって**修正はフロント `Admin/Users.svelte` に閉じ、バックエンドは回帰 assertion 追加のみ**。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | ロール変更 UX 修正（拒否時に combobox を権威値へ復帰・invalid・エラー近接・in-flight 直列化・フォーカス復帰） | `resources/js/pages/Admin/Users.svelte` | High |
| S2 | フロント回帰テスト（6 ケース） | `tests/js/pages/AdminUsers.test.ts` | High |
| S3 | バックエンド回帰 assertion 強化（サイレント成功でないことの固定） | `tests/Feature/Organization/ConsoleRoleTransitionTest.php` | Medium |

### 波及変更（全体）

- **TypeScript 型定義**: なし（`MemberRow.roleState` / `hasDefaultProject` 等の既存 props・型で完結。`@/types/admin` 変更なし）
- **API Resource / DTO**: なし（バックエンドのレスポンス契約・DTO 不変）
- **Inertia Props**: なし（`Admin/Users` の props シグネチャ不変）
- **atom**: なし（`Select` / `FormError` は既存 props（`value`/`error`/`disabled`/`aria-describedby`/`id`/`testId`）で対応。無改造）
- **テストファイル**: S2（新規ケース追加）/ S3（assertion 追加）

---

## S1: ロール変更 UX 修正（`resources/js/pages/Admin/Users.svelte`）

### 変更箇所
- `<script>` の状態・`changeRole()`（現 L58-83）
- ロール `Select` のマークアップ（現 L272-288）と `FormError` の配置（現 L254-259 を右アクション列へ移設）

### 波及変更
- TypeScript 型定義: なし（追加状態はすべてローカル、`number` / `Record<number, number>` / `boolean`）
- API Resource/DTO: なし
- テストファイル: `tests/js/pages/AdminUsers.test.ts`（S2）

### 現行コード（抜粋）
```svelte
<script lang="ts">
    import { page, router, useForm } from "@inertiajs/svelte";
    ...
    let roleErrorMemberId = $state<number | null>(null);
    let changingRole = $state(false);

    function changeRole(member: MemberRow, role: string): void {
        if (role === "" || changingRole) return;
        changingRole = true;
        router.patch(
            `/organizations/${organizationSlug}/members/${member.id}`,
            { role },
            {
                preserveScroll: true,
                onError: () => { roleErrorMemberId = member.id; },
                onSuccess: () => { roleErrorMemberId = null; },
                onFinish: () => { changingRole = false; },
            },
        );
    }

    const pageErrors = $derived((page.props.errors ?? {}) as Record<string, string>);
</script>
...
<!-- 左 info 列（現 L254-259） -->
{#if roleErrorMemberId === member.id && pageErrors.role}
    <FormError message={pageErrors.role} testId={`role-error-${member.id}`} />
{/if}
...
<!-- 右アクション列（現 L272-288） -->
{#if canChangeRole(member)}
    <Select
        value={member.roleState === "unassigned" ? "" : member.roleState}
        aria-label={`${member.name} のロール`}
        onchange={(event) => changeRole(member, event.currentTarget.value)}
        testId={`member-role-${member.id}`}
    >
        {#if member.roleState === "unassigned"}
            <option value="">未割当（選択してください）</option>
        {/if}
        {#each ROLE_OPTIONS as option (option.value)}
            <option value={option.value}>{option.label}</option>
        {/each}
    </Select>
    <Button variant="danger-ghost" size="sm" onclick={() => openRemoveDialog(member)} .../>
{:else}
    ...
{/if}
```

### 変更後コード（抜粋）
```svelte
<script lang="ts">
    import { tick } from "svelte";
    import { page, router, useForm } from "@inertiajs/svelte";
    ...
    /* ---- ロール変更 (3 値遷移コマンド) ---- */
    let roleErrorMemberId = $state<number | null>(null);
    // 拒否時に該当行 Select を remount して権威値 (member.roleState) を読み直させるためのキー。
    // 一方向 value 伝播では admin→admin の同値へ再同期されない問題を remount で断つ。
    let roleResetTokens = $state<Record<number, number>>({});
    // フォーカス復帰対象 (onError で保存し、onFinish で disabled 解除後に復帰)。
    let roleRefocusMemberId = $state<number | null>(null);
    let changingRole = $state(false);

    function changeRole(member: MemberRow, role: string): void {
        if (role === "" || changingRole) return; // 未割当の空 option / 二重送信の冪等ガード
        roleErrorMemberId = null; // 送信開始時に前回エラーをクリア (次通信中まで残さない)
        changingRole = true;
        router.patch(
            `/organizations/${organizationSlug}/members/${member.id}`,
            { role },
            {
                preserveScroll: true,
                onError: () => {
                    // 拒否: 権威値へ戻すため該当行を remount。実フォーカス復帰は onFinish (disabled 解除後)。
                    roleErrorMemberId = member.id;
                    roleResetTokens[member.id] = (roleResetTokens[member.id] ?? 0) + 1;
                    roleRefocusMemberId = member.id;
                },
                onSuccess: () => {
                    roleErrorMemberId = null; // 成功時はフォーカス復帰対象を残さない
                },
                onFinish: () => {
                    changingRole = false;
                    void refocusRole();
                },
            },
        );
    }

    // remount + disabled 解除の反映を待ってから、失敗行 Select へフォーカスを戻す。
    // Select atom は id / data-testid を native <select> にそのまま渡すため atom 改造は不要。
    async function refocusRole(): Promise<void> {
        const id = roleRefocusMemberId;
        if (id === null) return;
        roleRefocusMemberId = null;
        await tick();
        const el = document.querySelector<HTMLSelectElement>(
            `[data-testid="member-role-${id}"]`,
        );
        el?.focus();
    }

    const pageErrors = $derived((page.props.errors ?? {}) as Record<string, string>);
</script>
...
<!-- 左 info 列: 旧 FormError ブロックは削除 (エラーは combobox 直下へ移設) -->
...
<!-- 右アクション列 (flex-wrap を維持。Select は {#key} で論理ラップ = DOM 親は不変) -->
{#if canChangeRole(member)}
    {#key roleResetTokens[member.id] ?? 0}
        <Select
            value={member.roleState === "unassigned" ? "" : member.roleState}
            aria-label={`${member.name} のロール`}
            error={roleErrorMemberId === member.id && !!pageErrors.role}
            aria-describedby={roleErrorMemberId === member.id && pageErrors.role
                ? `role-error-${member.id}`
                : undefined}
            disabled={changingRole}
            onchange={(event) => changeRole(member, event.currentTarget.value)}
            testId={`member-role-${member.id}`}
        >
            {#if member.roleState === "unassigned"}
                <option value="">未割当（選択してください）</option>
            {/if}
            {#each ROLE_OPTIONS as option (option.value)}
                <option value={option.value}>{option.label}</option>
            {/each}
        </Select>
    {/key}
    <!-- combobox 直下エラー: flex-wrap 内の full-width 要素として select の次行に落とす。
         Select の parentElement は actions 列のまま (F-14 不変条件を保持) -->
    {#if roleErrorMemberId === member.id && pageErrors.role}
        <div class="w-full sm:text-right">
            <FormError
                id={`role-error-${member.id}`}
                message={pageErrors.role}
                testId={`role-error-${member.id}`}
            />
        </div>
    {/if}
    <Button variant="danger-ghost" size="sm" onclick={() => openRemoveDialog(member)}
        testId={`remove-member-${member.id}`}>削除</Button>
{:else}
    <span class="text-caption text-text-secondary">{member.roleLabel}</span>
{/if}
```

### 設計上の要点
- **`{#key}` は DOM 要素を追加しない**（論理ブロック）。Select の `parentElement` は `flex-wrap` の actions 列のまま → F-14 不変条件（`AdminUsers.test.ts` L196-198 の `roleSelect.parentElement` が `flex-wrap`）を破壊しない。
- **FormError は full-width(`w-full`) の div で包む**ことで flex-wrap 内で select の次行に落ち、視覚的に combobox 直下に出る。条件表示（error 時のみ）なので F-14 の最悪幅行テスト（L200-209、非 error 状態）には影響しない。
- **`aria-describedby` は FormError の `id`** と一致させる（FormError は `id` prop を `<p {id}>` に出力）。フォーカス復帰後に読み上げられる。
- **フォーカス復帰は `onFinish`**（`changingRole=false` で disabled 解除後）に実施。`onError` 中は Select が disabled のため focus 不可、の Round 3 指摘に対応。
- **remount は失敗行のみ**（`roleResetTokens[member.id]` の per-key 更新。Svelte 5 `$state` deep proxy が per-key 追跡）。
- **in-flight 中は全ロール Select が `disabled`**（`disabled={changingRole}`）。二重送信防止 + in-flight 中の別行操作による新たな DOM 乖離を排除。禁止事項8（必須未充足の無効化）には非該当。

### テスト計画
- [ ] `tests/js/pages/AdminUsers.test.ts`（S2）で 6 ケースを追加（下記 S2）
- [ ] 個別 `DatabaseTransactions` は無関係（JS テスト）

### リスク
- `document.querySelector` による DOM 参照は Svelte 慣用としてはやや素朴だが、Select atom への ref 追加（widely-used atom の改造）を避けるトレードオフ。`data-testid` は既に配線済みで安定。jsdom でも `focus()`/`document.activeElement` が機能しテスト可能。
- 成功後の値反映は Inertia が再取得する props（`member.roleState`）に依存する（`value` が変化 → Svelte が自然に DOM 同期）。remount 不要。

---

## S2: フロント回帰テスト（`tests/js/pages/AdminUsers.test.ts`）

### 変更箇所
- 既存ファイルに `describe("Admin/Users ロール変更フィードバック", ...)` を追加。

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 本体

### 方針
- `vi.mock("@inertiajs/svelte", async (importOriginal) => ({ ...(await importOriginal()), router: routerMock, page: pageStub }))` で `router.patch` を制御（既存 `tests/js/components/features/NotificationListItem.test.ts` / `OrganizationSwitcher.test.ts` の手法を踏襲）。
- `router.patch` の第 3 引数 `options` を捕捉し、テストから `options.onError()` / `options.onSuccess()` / `options.onFinish()` を任意順に発火。
- `page` は `props.errors` を差し替え可能なスタブにし、拒否ケースで `{ role: "編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。" }` を供給。

### 追加テストケース
1. **拒否時に対象行 Select が権威値へ戻る**: admin 行(id=2)で `member-role-2` を editor に change（DOM は editor）→ `page.props.errors.role` を設定し `onError`→`onFinish` 発火 → `member-role-2` の value が `admin` に戻る。
2. **拒否時に対象行のみ invalid + combobox 直下にエラー**: `member-role-2` が `aria-invalid="true"`、`role-error-2` が該当文言、Select の `aria-describedby` が `role-error-2` を指す。
3. **成功時に新ロールが反映（props 駆動）**: `onSuccess`→`onFinish` 発火後、成功相当の members（id=2 の roleState=editor）で `rerender` → `member-role-2` が editor を表示。`roleErrorMemberId` クリアで invalid/エラーが無い。
4. **他行にエラーが出ない**: 拒否ケースで失敗行(id=2)以外の Select が invalid でなく、`role-error-{他id}` が存在しない。
5. **in-flight 中は全ロール Select が disabled**: `router.patch` が `onFinish` 未発火（pending）の状態で `member-role-2` / `member-role-3` / `member-role-4` が `disabled`、`onFinish` 後に解除。
6. **拒否後にフォーカスが失敗行 Select へ復帰**: `onError`→`onFinish` 発火後 `await tick()` を挟み、`document.activeElement` が `member-role-2`。`onError` 直後（`onFinish` 前・disabled 中）は復帰していないことも同ケースで確認可。

### PHPStan 適合チェック（JS のため該当なし。TS 側）
- [ ] `pnpm typecheck` green（追加状態・ハンドラの型注釈明示）
- [ ] `pnpm lint` green

### リスク
- Inertia の `page` store モックは既存パターンあり。`router.patch` の options 捕捉は同期発火で決定的。

---

## S3: バックエンド回帰 assertion 強化（`tests/Feature/Organization/ConsoleRoleTransitionTest.php`）

### 変更箇所
- 「endpoint 経由: Default Project 不在の editor コマンドは error bag」テスト（現 L89-99）に assertion 追加。**新規挙動は導入しない**（サーバの契約は不変）。

### 波及変更
- TypeScript 型定義: なし / API Resource/DTO: なし / テストファイル: 本体

### 現行コード
```php
test('endpoint 経由: Default Project 不在の editor コマンドは error bag', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);

    $response = $this->actingAs($owner)->patch("/organizations/{$organization->slug}/members/{$member->id}", [
        'role' => AdminConsoleRole::Editor->value,
    ]);

    $response->assertSessionHasErrors('role');
    expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
});
```

### 変更後コード
```php
test('endpoint 経由: Default Project 不在の editor コマンドは error bag (サイレント成功でない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);

    $response = $this->actingAs($owner)->patch("/organizations/{$organization->slug}/members/{$member->id}", [
        'role' => AdminConsoleRole::Editor->value,
    ]);

    // 検証エラーで拒否され、成功トースト (success flash) は出ない = サイレント成功の回帰ネット
    $response->assertSessionHasErrors('role');
    $response->assertSessionMissing('success');
    // org ロールは Member のまま (部分適用なし)
    expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
});
```

### PHPStan 適合チェック
- [x] 戻り値・引数型は既存ヘルパ（`createOrganizationWithOwner` / `attachOrganizationMember`）に準拠、追加の型変更なし
- [x] DTO 返却・null 安全は対象外（テストの assertion 追加のみ）
- [x] `response()->json()` 直書きなし（本施策はバックエンド本体を変更しない）

### テスト計画
- [x] バグ修正の再現/回帰: 「拒否かつ success flash なし・ロール不変」を固定
- [ ] `composer test` green（`--parallel` / RefreshDatabase グローバル、個別 `DatabaseTransactions` 不使用）

### リスク
- `assertSessionMissing('success')` は成功時 `back()->with('success', ...)` を張る現行契約に整合。将来サーバがサイレント成功へ後退したら本 assertion が fail し検出できる。

---

## セキュリティ・不変条件チェック

- 認可: `Gate::authorize('manageMembers')` と URL 整合 guard（404）は既存のまま。フロント変更は認可に影響しない。
- tenant キー不信 / cross-org / IDOR: 変更なし（`NestedRouteIdorDefenseTest` の対象ルート・挙動は不変）。
- 入力バリデーション: `UpdateOrganizationMemberRoleRequest` / `applyConsoleRole` の検証は不変。
- DESIGN.md / Atomic Design: 新規 hex なし、DS token のみ。`Select`/`FormError` 既存 atom を props で利用（階層逆流なし）。アイコン追加なし。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 既存 `Admin/Users.svelte` と既存テストへの局所修正。新規モデル・ルート・DTO・API なし。バックエンド契約不変で後方互換の並走も発生しない。 |
| 競合リスク | 低。変更は `Admin/Users.svelte` / `AdminUsers.test.ts` / `ConsoleRoleTransitionTest.php` の 3 ファイルに限定。他 bug-hunt 施策と重複する可能性が低い独立領域。 |

---

## 関連する現行コード

### resources/js/pages/Admin/Users.svelte (抜粋: 状態・changeRole・Select 描画)
```svelte
    /* ---- ロール変更 (3 値遷移コマンド) ---- */
    let roleErrorMemberId = $state<number | null>(null);
    let changingRole = $state(false);

    function changeRole(member: MemberRow, role: string): void {
        if (role === "" || changingRole) return; // 未割当の空 option / 二重送信の冪等ガード
        changingRole = true;
        router.patch(
            `/organizations/${organizationSlug}/members/${member.id}`,
            { role },
            {
                preserveScroll: true,
                onError: () => {
                    roleErrorMemberId = member.id;
                },
                onSuccess: () => {
                    roleErrorMemberId = null;
                },
                onFinish: () => {
                    changingRole = false;
                },
            },
        );
    }

    const pageErrors = $derived((page.props.errors ?? {}) as Record<string, string>);

    /* ---- メンバー削除 (モック 08 削除アラート) ---- */
    let removeTarget = $state<MemberRow | null>(null);
    let removeDialogOpen = $state(false);
    let removing = $state(false);

    function openRemoveDialog(member: MemberRow): void {
        removeTarget = member;
        removeDialogOpen = true;
    }

    function removeMember(): void {
        if (removeTarget === null || removing) return;
        router.delete(`/organizations/${organizationSlug}/members/${removeTarget.id}`, {
            preserveScroll: true,
            onStart: () => {
                removing = true;
            },
            onFinish: () => {
                removing = false;
                removeDialogOpen = false;
            },
        });
    }

    /* ---- recent-auth (step-up) precheck。stale なら再認証モーダルを挟んで再開する ---- */
    let recentAuthOpen = $state(false);
...
                <ul class="mt-4 flex flex-col divide-y divide-border" data-testid="member-list">
                    {#each members as member (member.id)}
                        <!-- 375px 方針: モバイルは縦積み、sm 以上は現行の横並び (F-14)。操作ブロックは要素単位で折り返し可 -->
                        <li
                            class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                        >
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <p class="truncate text-body">{member.name}</p>
                                    {#if member.twoFactorStatus === "enabled"}
                                        <Badge tone="success">2FA</Badge>
                                    {/if}
                                    {#if member.roleState === "unassigned"}
                                        <Badge tone="warning" testId={`unassigned-${member.id}`}>
                                            未割当
                                        </Badge>
                                    {/if}
                                </div>
                                <p class="truncate text-caption text-text-secondary">
                                    {member.email}
                                </p>
                                {#if roleErrorMemberId === member.id && pageErrors.role}
                                    <FormError
                                        message={pageErrors.role}
                                        testId={`role-error-${member.id}`}
                                    />
                                {/if}
                            </div>
                            <div class="flex flex-wrap items-center gap-2 sm:shrink-0 sm:justify-end">
                                {#if canResetTwoFactor(member)}
                                    <Button
                                        variant="danger-ghost"
                                        size="sm"
                                        onclick={() => openResetTwoFactorModal(member)}
                                        testId={`reset-two-factor-${member.id}`}
                                    >
                                        2FA 解除
                                    </Button>
                                {/if}
                                {#if canChangeRole(member)}
                                    <Select
                                        value={member.roleState === "unassigned"
                                            ? ""
                                            : member.roleState}
                                        aria-label={`${member.name} のロール`}
                                        onchange={(event) =>
                                            changeRole(member, event.currentTarget.value)}
                                        testId={`member-role-${member.id}`}
                                    >
                                        {#if member.roleState === "unassigned"}
                                            <option value="">未割当（選択してください）</option>
                                        {/if}
                                        {#each ROLE_OPTIONS as option (option.value)}
                                            <option value={option.value}>{option.label}</option>
                                        {/each}
                                    </Select>
                                    <Button
                                        variant="danger-ghost"
                                        size="sm"
                                        onclick={() => openRemoveDialog(member)}
                                        testId={`remove-member-${member.id}`}
                                    >
                                        削除
                                    </Button>
                                {:else}
                                    <span class="text-caption text-text-secondary">
                                        {member.roleLabel}
                                    </span>
                                {/if}
                            </div>
                        </li>
                    {/each}
                </ul>
            </Card>
```

### app/Services/Organization/OrganizationMembershipService.php (applyConsoleRole)
```php
    public function applyConsoleRole(Organization $organization, User $target, AdminConsoleRole $role): void
    {
        DB::transaction(function () use ($organization, $target, $role): void {
            // canonical 共通ロック境界 (users 昇順 → organizations)。normalizeOrganizationRole の
            // 直接 addRole 経路も含めロック下で直列化する。
            $this->lockForMembershipWrite([$this->keyOf($target)], [$this->keyOf($organization)]);

            $projectRole = $role->projectRole();

            if ($projectRole === null) {
                // Admin コマンド: org ロール正規化 → stale pivot 掃除
                // (org 配下 project に限定 = cross-org 不変条件)
                $this->normalizeOrganizationRole($organization, $target, $role);
                $this->detachProjectMemberships($organization, $target);

                return;
            }

            // Editor/Shooter コマンド: 書き込み用解決を先に行う (行ロック保持。
            // 取得〜pivot 更新まで削除競合を排除 + 不在エラーをロール変更より前に確定)
            $project = $this->defaultProjects->resolveForUpdate($organization);
            if ($project === null) {
                throw ValidationException::withMessages([
                    'role' => ['編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。'],
                ]);
            }

            $this->normalizeOrganizationRole($organization, $target, $role);
            $project->members()->syncWithoutDetaching([
                $target->id => ['role' => $projectRole->value],
            ]);
        });
    }
```

### app/Http/Controllers/Organizations/OrganizationMemberController.php::update
```php
    public function update(UpdateOrganizationMemberRoleRequest $request, Organization $organization, User $user, OrganizationMembershipService $membership): RedirectResponse
    {
        // URL 整合 guard: 認可より前に 404 (cross-tenant の存在を漏らさない)
        $this->resolveOrganizationMember($organization, $user);
        Gate::authorize('manageMembers', $organization);

        // 3 値遷移コマンド (admin/editor/shooter)。Owner 指定は enum 外 = 構造的に不可能
        $membership->applyConsoleRole($organization, $user, $request->role());

        return back()->with('success', 'ロールを変更しました');
    }
```

### resources/js/components/atoms/Select.svelte
```svelte
<script lang="ts">
    import type { Snippet } from "svelte";
    import type { HTMLSelectAttributes } from "svelte/elements";
    import { INPUT_BASE_CLASSES, inputStateClass } from "./input-state";

    /**
     * Select atom。ネイティブ <select> の薄いラッパ。
     * 見た目の真実は input-state.ts (入力系共通スタイル) に集約する。
     * <option> 群は children snippet として呼び出し側が記述する。
     */
    type Props = {
        /** 選択値 ($bindable) */
        value?: string;
        /** true で枠線を danger 化し aria-invalid を立てる */
        error?: boolean;
        /** data-testid に反映するテスト用 ID */
        testId?: string;
        class?: string;
        /** <option> 群 (呼び出し側が記述する) */
        children: Snippet;
    } & Omit<HTMLSelectAttributes, "value" | "class">;

    let {
        value = $bindable(),
        error = false,
        disabled = false,
        id,
        testId,
        class: extraClass = "",
        children,
        ...restProps
    }: Props = $props();

    // マージ順: 共通 base → error 状態 → 外部 class (外部後勝ち)
    const computedClass = $derived(
        [INPUT_BASE_CLASSES, inputStateClass(error), extraClass].filter(Boolean).join(" "),
    );
</script>

<!-- bind:value は native select に直結。aria-invalid は error と連動する -->
<select
    {...restProps}
    bind:value
    {id}
    {disabled}
    aria-invalid={error || undefined}
    data-testid={testId}
    class={computedClass}
>
    {@render children()}
</select>
```

### resources/js/components/atoms/FormError.svelte
```svelte
<script lang="ts">
    /**
     * フォームエラー表示 atom。FormField / Checkbox から composition される。
     * 単体でも使えるが、aria-describedby の配線は呼び出し側の責務。
     */
    interface Props {
        message?: string | null;
        id?: string;
        testId?: string;
    }

    let { message, id, testId }: Props = $props();
</script>

{#if message}
    <p {id} class="text-caption text-danger" data-testid={testId}>{message}</p>
{/if}
```

### tests/Feature/Organization/ConsoleRoleTransitionTest.php (対象テスト L89-99)
```php
test('endpoint 経由: Default Project 不在の editor コマンドは error bag (押下時エラー表示)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);

    $response = $this->actingAs($owner)->patch("/organizations/{$organization->slug}/members/{$member->id}", [
        'role' => AdminConsoleRole::Editor->value,
    ]);

    $response->assertSessionHasErrors('role');
    expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
});
```

### tests/js/pages/AdminUsers.test.ts の F-14 不変条件 (L186-210)
```ts
    it("メンバー行はモバイル縦積みクラスを持ち、操作ブロックは flex-wrap を持つ (F-14)", () => {
        // jsdom はレイアウト計算をしないため、クラス不変条件を横スクロール回避のプロキシとして固定する。
        // 対象要素は data-testid 起点で特定し DOM 順序に依存しない。
        render(Users, { props: baseProps });

        const roleSelect = screen.getByTestId("member-role-3");
        const row = roleSelect.closest("li");
        expect(row).not.toBeNull();
        expect(row).toHaveClass("flex-col", "sm:flex-row");

        const actions = roleSelect.parentElement;
        expect(actions).not.toBeNull();
        expect(actions).toHaveClass("flex-wrap");

        // bug-hunt 実測の最悪幅構成 (2FA バッジ + 未割当バッジ + 2FA 解除 + 未割当 select + 削除)
        // が同一行に揃っていることを固定する
        const rowScope = within(row as HTMLElement);
        expect(rowScope.getByText("2FA")).toBeInTheDocument();
        expect(rowScope.getByTestId("unassigned-3")).toBeInTheDocument();
        expect(rowScope.getByTestId("reset-two-factor-3")).toBeInTheDocument();
        expect(rowScope.getByTestId("remove-member-3")).toBeInTheDocument();
        expect(
            rowScope.getByRole("option", { name: "未割当（選択してください）" }),
        ).toBeInTheDocument();
    });
```
