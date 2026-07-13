# 実装レビュー依頼 (T033 member-role-update-feedback / impl-review Round 1)

## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。
- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

## 禁止事項 (AGENTS.md 正本)

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

## あなたの役割 (system)

あなたは Laravel + Svelte のコードレビュアーである。以下の改善実装 (T033: メンバーのロール変更が無言で破棄される問題の修正) をレビューせよ。
レビュー観点:
- 設計 (detailed-design.md) との一致性
- 正確性 (Svelte 5 runes のリアクティビティ、remount / focus 復帰ロジックの妥当性、エッジケース)
- PHPStan 適合性 (型 widen 禁止)
- DTO / JsonResource パターン (本 PR はバックエンド本体不変・テスト assertion 追加のみ)
- テスト網羅性 (バグ回帰ネットとして十分か、テストが実装に追従しているか)
- セキュリティ (認可・IDOR 不変条件に影響しないか)
- DESIGN.md 準拠 (hex 直書きを増やしていないか、DS token のみか)
- Atomic Design 準拠 (component 階層の単方向 import、atom 改造の有無、アイコンは Lucide か)

出力形式: ファイルごとに判定し、指摘を Critical / Warning / Suggestion に分類。最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明示せよ。

---

## 詳細設計書 (detailed-design.md)

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

    const pageErrors = $derived(
        (page.props.errors ?? {}) as Record<string, string | string[]>,
    );
    // error bag の配列化 (Laravel の複数メッセージ経路) に堅牢化: 先頭要素へ正規化。
    // 表示・aria-describedby・invalid 判定をこの一本の派生に集約する。
    const roleMessage = $derived.by(() => {
        const raw = pageErrors.role;
        return Array.isArray(raw) ? raw[0] : raw;
    });
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
            error={roleErrorMemberId === member.id && !!roleMessage}
            aria-describedby={roleErrorMemberId === member.id && roleMessage
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
    {#if roleErrorMemberId === member.id && roleMessage}
        <div class="w-full sm:text-right">
            <FormError
                id={`role-error-${member.id}`}
                message={roleMessage}
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
- **`roleMessage` 派生に正規化を集約**: `page.props.errors.role` が配列化される将来経路に備え `Array.isArray` で先頭要素へ落とす。表示・`aria-describedby`・invalid 判定を単一の派生で一貫させる（Round 1 Warning 対応）。
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
6. **拒否後にフォーカスが失敗行 Select へ復帰**: `onError`→`onFinish` 発火後、非決定性を避けるため `await waitFor(() => expect(screen.getByTestId("member-role-2")).toHaveFocus())` で待機検証（手動 `tick()` 依存を避ける）。`onError` 直後（`onFinish` 前・disabled 中）は復帰していないことも同ケースで確認可。

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

## 実装差分 (git diff HEAD -- resources/ tests/)

```diff
diff --git a/resources/js/pages/Admin/Users.svelte b/resources/js/pages/Admin/Users.svelte
index 61abd0b..24cb24a 100644
--- a/resources/js/pages/Admin/Users.svelte
+++ b/resources/js/pages/Admin/Users.svelte
@@ -1,4 +1,5 @@
 <script lang="ts">
+    import { tick } from "svelte";
     import { page, router, useForm } from "@inertiajs/svelte";
     import Badge from "@/components/atoms/Badge.svelte";
     import Button from "@/components/atoms/Button.svelte";
@@ -57,10 +58,16 @@
 
     /* ---- ロール変更 (3 値遷移コマンド) ---- */
     let roleErrorMemberId = $state<number | null>(null);
+    // 拒否時に該当行 Select を remount して権威値 (member.roleState) を読み直させるためのキー。
+    // 一方向 value 伝播では admin→admin の同値へ再同期されない問題を remount で断つ。
+    let roleResetTokens = $state<Record<number, number>>({});
+    // フォーカス復帰対象 (onError で保存し、onFinish で disabled 解除後に復帰)。
+    let roleRefocusMemberId = $state<number | null>(null);
     let changingRole = $state(false);
 
     function changeRole(member: MemberRow, role: string): void {
         if (role === "" || changingRole) return; // 未割当の空 option / 二重送信の冪等ガード
+        roleErrorMemberId = null; // 送信開始時に前回エラーをクリア (次通信中まで残さない)
         changingRole = true;
         router.patch(
             `/organizations/${organizationSlug}/members/${member.id}`,
@@ -68,19 +75,44 @@
             {
                 preserveScroll: true,
                 onError: () => {
+                    // 拒否: 権威値へ戻すため該当行を remount。実フォーカス復帰は onFinish (disabled 解除後)。
                     roleErrorMemberId = member.id;
+                    roleResetTokens[member.id] = (roleResetTokens[member.id] ?? 0) + 1;
+                    roleRefocusMemberId = member.id;
                 },
                 onSuccess: () => {
-                    roleErrorMemberId = null;
+                    roleErrorMemberId = null; // 成功時はフォーカス復帰対象を残さない
                 },
                 onFinish: () => {
                     changingRole = false;
+                    void refocusRole();
                 },
             },
         );
     }
 
-    const pageErrors = $derived((page.props.errors ?? {}) as Record<string, string>);
+    // remount + disabled 解除の反映を待ってから、失敗行 Select へフォーカスを戻す。
+    // Select atom は id / data-testid を native <select> にそのまま渡すため atom 改造は不要。
+    async function refocusRole(): Promise<void> {
+        const id = roleRefocusMemberId;
+        if (id === null) return;
+        roleRefocusMemberId = null;
+        await tick();
+        const el = document.querySelector<HTMLSelectElement>(
+            `[data-testid="member-role-${id}"]`,
+        );
+        el?.focus();
+    }
+
+    const pageErrors = $derived(
+        (page.props.errors ?? {}) as Record<string, string | string[]>,
+    );
+    // error bag の配列化 (Laravel の複数メッセージ経路) に堅牢化: 先頭要素へ正規化。
+    // 表示・aria-describedby・invalid 判定をこの一本の派生に集約する。
+    const roleMessage = $derived.by(() => {
+        const raw = pageErrors.role;
+        return Array.isArray(raw) ? raw[0] : raw;
+    });
 
     /* ---- メンバー削除 (モック 08 削除アラート) ---- */
     let removeTarget = $state<MemberRow | null>(null);
@@ -251,12 +283,6 @@
                                 <p class="truncate text-caption text-text-secondary">
                                     {member.email}
                                 </p>
-                                {#if roleErrorMemberId === member.id && pageErrors.role}
-                                    <FormError
-                                        message={pageErrors.role}
-                                        testId={`role-error-${member.id}`}
-                                    />
-                                {/if}
                             </div>
                             <div class="flex flex-wrap items-center gap-2 sm:shrink-0 sm:justify-end">
                                 {#if canResetTwoFactor(member)}
@@ -270,22 +296,44 @@
                                     </Button>
                                 {/if}
                                 {#if canChangeRole(member)}
-                                    <Select
-                                        value={member.roleState === "unassigned"
-                                            ? ""
-                                            : member.roleState}
-                                        aria-label={`${member.name} のロール`}
-                                        onchange={(event) =>
-                                            changeRole(member, event.currentTarget.value)}
-                                        testId={`member-role-${member.id}`}
-                                    >
-                                        {#if member.roleState === "unassigned"}
-                                            <option value="">未割当（選択してください）</option>
-                                        {/if}
-                                        {#each ROLE_OPTIONS as option (option.value)}
-                                            <option value={option.value}>{option.label}</option>
-                                        {/each}
-                                    </Select>
+                                    <!-- 拒否時は該当行のみ remount して権威値 (member.roleState) を読み直す。
+                                         {#key} は論理ブロックで DOM 親を追加しない = actions 列の flex-wrap を保つ (F-14) -->
+                                    {#key roleResetTokens[member.id] ?? 0}
+                                        <Select
+                                            value={member.roleState === "unassigned"
+                                                ? ""
+                                                : member.roleState}
+                                            aria-label={`${member.name} のロール`}
+                                            error={roleErrorMemberId === member.id &&
+                                                !!roleMessage}
+                                            aria-describedby={roleErrorMemberId === member.id &&
+                                            roleMessage
+                                                ? `role-error-${member.id}`
+                                                : undefined}
+                                            disabled={changingRole}
+                                            onchange={(event) =>
+                                                changeRole(member, event.currentTarget.value)}
+                                            testId={`member-role-${member.id}`}
+                                        >
+                                            {#if member.roleState === "unassigned"}
+                                                <option value="">未割当（選択してください）</option>
+                                            {/if}
+                                            {#each ROLE_OPTIONS as option (option.value)}
+                                                <option value={option.value}>{option.label}</option>
+                                            {/each}
+                                        </Select>
+                                    {/key}
+                                    <!-- combobox 直下エラー: flex-wrap 内の full-width 要素として
+                                         select の次行に落とす。Select の parentElement は actions 列のまま (F-14) -->
+                                    {#if roleErrorMemberId === member.id && roleMessage}
+                                        <div class="w-full sm:text-right">
+                                            <FormError
+                                                id={`role-error-${member.id}`}
+                                                message={roleMessage}
+                                                testId={`role-error-${member.id}`}
+                                            />
+                                        </div>
+                                    {/if}
                                     <Button
                                         variant="danger-ghost"
                                         size="sm"
diff --git a/tests/Feature/Organization/ConsoleRoleTransitionTest.php b/tests/Feature/Organization/ConsoleRoleTransitionTest.php
index e87d88e..57c4c68 100644
--- a/tests/Feature/Organization/ConsoleRoleTransitionTest.php
+++ b/tests/Feature/Organization/ConsoleRoleTransitionTest.php
@@ -86,7 +86,7 @@ function createOrgWithDefaultProject(): array
     'shooter' => [AdminConsoleRole::Shooter],
 ]);
 
-test('endpoint 経由: Default Project 不在の editor コマンドは error bag (押下時エラー表示)', function (): void {
+test('endpoint 経由: Default Project 不在の editor コマンドは error bag (サイレント成功でない)', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
     $member = attachOrganizationMember($organization);
 
@@ -94,7 +94,10 @@ function createOrgWithDefaultProject(): array
         'role' => AdminConsoleRole::Editor->value,
     ]);
 
+    // 検証エラーで拒否され、成功トースト (success flash) は出ない = サイレント成功の回帰ネット
     $response->assertSessionHasErrors('role');
+    $response->assertSessionMissing('success');
+    // org ロールは Member のまま (部分適用なし)
     expect($member->fresh()->organizationRole($organization))->toBe(OrganizationRole::Member);
 });
 
diff --git a/tests/js/pages/AdminUsers.test.ts b/tests/js/pages/AdminUsers.test.ts
index 7aa7c17..a498a84 100644
--- a/tests/js/pages/AdminUsers.test.ts
+++ b/tests/js/pages/AdminUsers.test.ts
@@ -1,8 +1,51 @@
-import { describe, expect, it } from "vitest";
-import { render, screen, within } from "@testing-library/svelte";
+import { beforeEach, describe, expect, it, vi } from "vitest";
+import { fireEvent, render, screen, waitFor, within } from "@testing-library/svelte";
 import Users from "@/pages/Admin/Users.svelte";
 import type { InvitationRow, MemberRow } from "@/types/admin";
 
+// router.patch をモックして visit options (第3引数) を捕捉し、page は errors を
+// 差し替え可能な可変オブジェクトにする (SettingsSecurity.test.ts の手法を踏襲)。
+const { routerPatchMock, routerDeleteMock, routerPostMock, pageState } = vi.hoisted(() => ({
+    routerPatchMock: vi.fn(),
+    routerDeleteMock: vi.fn(),
+    routerPostMock: vi.fn(),
+    pageState: {
+        props: {} as Record<string, unknown>,
+        url: "/manage/users",
+    },
+}));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    router: { patch: routerPatchMock, delete: routerDeleteMock, post: routerPostMock },
+    page: pageState,
+}));
+
+/** router.patch の第3引数 (visit options) の検証対象部分。自己参照キャストを避けて明示定義する */
+interface InertiaVisitOptions {
+    onStart?: () => void;
+    onSuccess?: () => void;
+    onError?: () => void;
+    onFinish?: () => void;
+}
+
+/** 最後の router.patch 呼び出しの visit options (第3引数) を取り出す */
+function lastPatchOptions(): InertiaVisitOptions {
+    const call = routerPatchMock.mock.calls.at(-1);
+    if (!call) throw new Error("router.patch が呼ばれていない");
+    return call[2] as InertiaVisitOptions;
+}
+
+// Default Project 不在時にサーバが role error bag へ載せる文言 (拒否ケースの再現用)
+const REJECT_MESSAGE = "編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。";
+
+beforeEach(() => {
+    routerPatchMock.mockReset();
+    routerDeleteMock.mockReset();
+    routerPostMock.mockReset();
+    pageState.props = { appName: "AI-CUE" };
+});
+
 const membersFixture: MemberRow[] = [
     {
         id: 1,
@@ -234,3 +277,140 @@ describe("Admin/Users", () => {
         expect(dialog.textContent).toContain("削除しますか");
     });
 });
+
+describe("Admin/Users ロール変更フィードバック", () => {
+    it("拒否時に対象行 Select が権威値へ戻る", async () => {
+        pageState.props = { appName: "AI-CUE", errors: { role: REJECT_MESSAGE } };
+        render(Users, { props: baseProps });
+
+        const select = screen.getByTestId("member-role-2") as HTMLSelectElement;
+        expect(select).toHaveValue("editor");
+
+        // 一方向 value 伝播では権威値 (editor) と乖離した DOM 選択 (admin) が残る
+        await fireEvent.change(select, { target: { value: "admin" } });
+        expect(routerPatchMock).toHaveBeenCalledTimes(1);
+
+        const options = lastPatchOptions();
+        options.onError?.();
+        options.onFinish?.();
+
+        // remount により権威値 editor へ復帰する
+        await waitFor(() =>
+            expect(screen.getByTestId("member-role-2")).toHaveValue("editor"),
+        );
+    });
+
+    it("拒否時に対象行のみ invalid + combobox 直下にエラーが出る", async () => {
+        pageState.props = { appName: "AI-CUE", errors: { role: REJECT_MESSAGE } };
+        render(Users, { props: baseProps });
+
+        const select = screen.getByTestId("member-role-2") as HTMLSelectElement;
+        await fireEvent.change(select, { target: { value: "admin" } });
+
+        const options = lastPatchOptions();
+        options.onError?.();
+        options.onFinish?.();
+
+        await waitFor(() => {
+            const rejected = screen.getByTestId("member-role-2");
+            expect(rejected).toHaveAttribute("aria-invalid", "true");
+            expect(rejected).toHaveAttribute("aria-describedby", "role-error-2");
+        });
+
+        const error = screen.getByTestId("role-error-2");
+        expect(error).toHaveTextContent(REJECT_MESSAGE);
+        expect(error).toHaveAttribute("id", "role-error-2");
+    });
+
+    it("成功時は新ロールが props で反映され invalid / エラーが残らない", async () => {
+        pageState.props = { appName: "AI-CUE", errors: { role: REJECT_MESSAGE } };
+        const { rerender } = render(Users, { props: baseProps });
+
+        const select = screen.getByTestId("member-role-2") as HTMLSelectElement;
+        await fireEvent.change(select, { target: { value: "admin" } });
+
+        const options = lastPatchOptions();
+        options.onSuccess?.();
+        options.onFinish?.();
+
+        // 成功相当の再取得 props (id=2 が admin へ) で再描画する
+        await rerender({
+            ...baseProps,
+            members: membersFixture.map((member) =>
+                member.id === 2
+                    ? { ...member, roleState: "admin", roleLabel: "管理者" }
+                    : member,
+            ),
+        });
+
+        await waitFor(() =>
+            expect(screen.getByTestId("member-role-2")).toHaveValue("admin"),
+        );
+        expect(screen.getByTestId("member-role-2")).not.toHaveAttribute("aria-invalid");
+        expect(screen.queryByTestId("role-error-2")).toBeNull();
+    });
+
+    it("拒否時に失敗行以外は invalid にならずエラーも出ない", async () => {
+        pageState.props = { appName: "AI-CUE", errors: { role: REJECT_MESSAGE } };
+        render(Users, { props: baseProps });
+
+        const select = screen.getByTestId("member-role-2") as HTMLSelectElement;
+        await fireEvent.change(select, { target: { value: "admin" } });
+
+        const options = lastPatchOptions();
+        options.onError?.();
+        options.onFinish?.();
+
+        await waitFor(() =>
+            expect(screen.getByTestId("member-role-2")).toHaveAttribute(
+                "aria-invalid",
+                "true",
+            ),
+        );
+        expect(screen.getByTestId("member-role-3")).not.toHaveAttribute("aria-invalid");
+        expect(screen.getByTestId("member-role-4")).not.toHaveAttribute("aria-invalid");
+        expect(screen.queryByTestId("role-error-3")).toBeNull();
+        expect(screen.queryByTestId("role-error-4")).toBeNull();
+    });
+
+    it("in-flight 中は全ロール Select が disabled になり onFinish で解除される", async () => {
+        pageState.props = { appName: "AI-CUE", errors: {} };
+        render(Users, { props: baseProps });
+
+        const select = screen.getByTestId("member-role-2") as HTMLSelectElement;
+        await fireEvent.change(select, { target: { value: "admin" } });
+
+        // onFinish 未発火 (通信中) は全ロール Select が disabled
+        await waitFor(() => {
+            expect(screen.getByTestId("member-role-2")).toBeDisabled();
+            expect(screen.getByTestId("member-role-3")).toBeDisabled();
+            expect(screen.getByTestId("member-role-4")).toBeDisabled();
+        });
+
+        const options = lastPatchOptions();
+        options.onSuccess?.();
+        options.onFinish?.();
+
+        await waitFor(() => {
+            expect(screen.getByTestId("member-role-2")).not.toBeDisabled();
+            expect(screen.getByTestId("member-role-3")).not.toBeDisabled();
+            expect(screen.getByTestId("member-role-4")).not.toBeDisabled();
+        });
+    });
+
+    it("拒否後にフォーカスが失敗行 Select へ復帰する", async () => {
+        pageState.props = { appName: "AI-CUE", errors: { role: REJECT_MESSAGE } };
+        render(Users, { props: baseProps });
+
+        const select = screen.getByTestId("member-role-2") as HTMLSelectElement;
+        await fireEvent.change(select, { target: { value: "admin" } });
+
+        const options = lastPatchOptions();
+        options.onError?.();
+        // onFinish 前 (disabled 中) はまだフォーカス復帰していない
+        expect(screen.getByTestId("member-role-2")).not.toHaveFocus();
+
+        options.onFinish?.();
+        await waitFor(() => expect(screen.getByTestId("member-role-2")).toHaveFocus());
+    });
+});
```

## テスト結果

- `pnpm exec vitest run tests/js/pages/AdminUsers.test.ts`: 18 passed (既存 12 + 新規 6)
- `php artisan test tests/Feature/Organization/ConsoleRoleTransitionTest.php`: 14 passed / 34 assertions
- `pnpm test` (全 JS): 71 files / 531 passed
- `composer test` (全 PHP parallel): 実行中 (別途確認)
- `composer phpstan`: No errors
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck`: clean
- `pnpm build`: built OK

## design system 参照 (resources/js を含むため添付)

- 本 PR は新規 hex なし。色/余白は既存 Tailwind DS token クラス (`text-caption` `text-danger` `text-text-secondary` `w-full` `sm:text-right` `flex-wrap` 等) のみ使用。
- atom 改造なし: `Select` (native `<select>` の薄いラッパ、`error`/`disabled`/`id`/`aria-describedby`/`testId` を既存 props で受ける)・`FormError` (`message`/`id`/`testId`) を props でそのまま利用。
- アイコン追加なし (Lucide 不使用箇所)。
- component 階層: `pages/Admin/Users.svelte` が `atoms/Select`・`atoms/FormError` を import (上→下の単方向、逆流なし)。
- 変更は `resources/js/pages/Admin/Users.svelte` (フロント本体) と 2 テストファイルに限定。

## 特に見てほしい点

1. `{#key roleResetTokens[member.id] ?? 0}` による失敗行のみ remount で「権威値 (member.roleState) と DOM 選択の乖離」を断つ設計が Svelte 5 として正しいか。DOM 親 (flex-wrap actions 列) を追加していないか (F-14 不変条件)。
2. `disabled={changingRole}` による in-flight 直列化が禁止事項8 (必須未充足の disabled) に抵触しないか (これは通信中の二重送信防止であり必須未充足の無効化ではない、という整理で妥当か)。
3. focus 復帰を `onFinish` (disabled 解除後) に置いた点、`document.querySelector([data-testid])` で参照する点の妥当性。
4. `roleMessage` 派生 (`Array.isArray` 正規化) に表示・aria-describedby・invalid 判定を集約した点。
