## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

## 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。

データに真摯に向き合え。想定外のパターンも判断材料になる。

先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。

仕組みが機能していない段階で値を弄るな。方向性が正しいと確認できてから細部を調整せよ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest / vitest
- DTO + JsonResource パターン / Laratrust RBAC

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性、$effect 無限ループ）
2. 既存コードとの整合性（命名規約、パターン、T041 との一貫性）
3. PHPStan level 10 適合性（本件はフロントのみ）
4. テスト計画の網羅性（vitest、再現テスト、serverErrors 非退行、過剰クリア防止）
5. DTO/JsonResource パターンの遵守（本件は該当なし）
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク（serverErrors 経路の非退行が本当に守られるか）
8. 波及変更の網羅性（TypeScript型定義、テストが変更対象に含まれているか）
9. セキュリティ（client precheck は UX 補助でありサーバが最終ゲート。緩めていないか）
10. DESIGN.md 準拠（禁止事項 #8: disabled 不使用。token/hex 直書きを増やさないか）
11. Atomic Design 準拠（pages 層のロジック追加のみで import グラフを崩さないか）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

（全文。関連ファイルは read 可能: `resources/js/pages/Organizations/Settings.svelte`,
`resources/js/pages/Projects/Show.svelte`, `resources/js/components/molecules/FormField.svelte`,
`resources/js/components/atoms/Select.svelte`, 参考: `resources/js/pages/Billing/PurchaseTickets.svelte`,
`tests/js/pages/OrganizationsSettings.test.ts`, `tests/js/pages/ProjectsShow.test.ts`）

# 詳細設計: stale-validation-sweep

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

### 禁止事項

1. テストなしの実装完了報告（不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」）
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う）
5. LLM 呼び出しの Prism 直呼び（`app/Prompts/` の factory 経由のみ）
6. prompt 文字列のコード直書き（`resources/prompts/*.yaml` に置く）
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示する。DESIGN.md）

### コーディングルール

- **PHPStan level 10** 必須（本件はフロントのみで PHP 変更なし = 影響なし）
- **Pest / vitest** テストフレームワーク（本件は vitest のみ）
- **RefreshDatabase + `--parallel`**（本件は DB 非依存 = 影響なし）
- フロントは **Svelte 5 runes + DS token/ramp のみ**（`DESIGN.md` canonical、ds-purity テスト）
- component 階層は **単方向 import**（atoms → molecules → organisms → features → templates → pages）。
  本件は **pages 層のロジック追加のみ**（import グラフ不変更）
- アイコンは `@lucide/svelte` のみ（本件はアイコン追加なし）
- コードフォーマット: `pnpm lint:fix` / `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

[devnotes/20260714-1654-stale-validation-sweep/conceptual-design.md](./conceptual-design.md)
（APPROVED, conceptual-review Round 1 / gpt-5.4）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | オーナー移譲 select の stale error 自動解消 | `resources/js/pages/Organizations/Settings.svelte` | Medium |
| 2 | プロジェクトメンバー追加 select の stale error 自動解消 | `resources/js/pages/Projects/Show.svelte` | Medium |
| 3 | 施策1の再現テスト追加 | `tests/js/pages/OrganizationsSettings.test.ts` | Medium |
| 4 | 施策2の再現テスト追加 | `tests/js/pages/ProjectsShow.test.ts` | Medium |

### 共通設計原則（両施策で不変）

1. **専用 transient client-error state を分離する**: `useForm().errors.user_id`（サーバ
   validation と共有の bag）には**書き込まない**。client precheck 用に独立した
   `$state<string | null>` を持ち、`setError` の呼び出しをこの state 代入へ置換する。
   → serverErrors 経路は完全に不変（非退行）。
2. **表示は client 優先の null 合体**: `error={clientError ?? form.errors.user_id}`。
   FormField は `invalid = Boolean(error)` を導出し Select の `aria-invalid` に連動する
   （`FormField.svelte` / `Select.svelte` L46 で確認済）。よって client-error クリアで
   文言・`aria-invalid` が同時に解ける。
3. **`$effect` は「submit が通る条件」の否定＝エラー条件に一致させる**:
   `if (clientError !== null && isValid) clientError = null`。`isValid` は各フォームの
   precheck 合格条件と同一 derived にすることで「有効へ復帰した時だけクリア／無効の
   ままなら残留（過剰クリア防止）」を保証する。「押下時にエラー表示」契約（禁止事項 #8）は維持。
4. **select value は文字列前提**: option の value は `String(id)`。比較も
   `String(member.id) === form.user_id` で string 同士に揃える（既存契約の踏襲）。

---

## 施策 1: オーナー移譲 select の stale error 自動解消

### 変更箇所

- ファイル: `resources/js/pages/Organizations/Settings.svelte`
  - L88-124 付近（`transferForm` 定義〜`openTransferDialog`）
  - L126-135（`transferOwnership` の `onFinish`）
  - L269-290（`<FormField error=...>`）

### 波及変更

- TypeScript型定義: なし（新 state は `string | null` のローカル $state。Props 不変）
- API Resource/DTO: なし（サーバ・Inertia Props 変更なし）
- Inertia Props: なし（`Props` interface 不変更）
- テストファイル: `tests/js/pages/OrganizationsSettings.test.ts`（施策3で追加）
- DS token / atomic import: なし（UI 構造・token・import グラフ不変更）

### 現行コード

```svelte
/* ---- オーナー移譲 ---- */
const transferForm = useForm({ user_id: "" });
let transferDialogOpen = $state(false);

const transferCandidates = $derived(members.filter((member) => member.id !== myId));
const transferTargetName = $derived(
    transferCandidates.find((member) => String(member.id) === transferForm.user_id)?.name ?? "",
);

const NO_TRANSFER_CANDIDATES = "移譲先にできるメンバーがいません。";

function openTransferDialog(event: SubmitEvent): void {
    event.preventDefault();
    if (transferCandidates.length === 0) {
        transferForm.setError(
            "user_id",
            `${NO_TRANSFER_CANDIDATES}先にメンバーを招待してください。`,
        );
        return;
    }
    const isValidTarget = transferCandidates.some(
        (member) => String(member.id) === transferForm.user_id,
    );
    if (!isValidTarget) {
        transferForm.setError("user_id", "移譲先のメンバーを選択してください。");
        return;
    }
    transferDialogOpen = true;
}

function transferOwnership(): void {
    guardWithRecentAuth(() => {
        transferForm.post(`/organizations/${organization.slug}/transfer-ownership`, {
            preserveScroll: true,
            onFinish: () => {
                transferDialogOpen = false;
            },
        });
    });
}
```

```svelte
<FormField
    label="移譲先のメンバー"
    id="transfer-target"
    error={transferForm.errors.user_id}
>
```

### 変更後コード

```svelte
/* ---- オーナー移譲 ---- */
const transferForm = useForm({ user_id: "" });
let transferDialogOpen = $state(false);
// client precheck 専用の transient error。serverErrors (transferForm.errors) とは分離し、
// 有効値復帰で自動解消する (「押下時にエラー表示」契約は維持: 無効のままなら残す)。
let transferClientError = $state<string | null>(null);

const transferCandidates = $derived(members.filter((member) => member.id !== myId));
const transferTargetName = $derived(
    transferCandidates.find((member) => String(member.id) === transferForm.user_id)?.name ?? "",
);

// precheck 合格条件 = 選択値が実在候補に一致すること。エラー条件はこの否定。
const isValidTransferTarget = $derived(
    transferCandidates.some((member) => String(member.id) === transferForm.user_id),
);

// 有効候補へ復帰した時点で client error を連動クリア (過剰クリア防止: clientError!=null かつ有効時のみ)。
// 候補 0 人ケースのエラーは isValidTransferTarget が常に false のため残留する = 選択では直せないので正しい。
// serverErrors (transferForm.errors) はこの effect の対象外 = 非退行。
$effect(() => {
    if (transferClientError !== null && isValidTransferTarget) {
        transferClientError = null;
    }
});

const NO_TRANSFER_CANDIDATES = "移譲先にできるメンバーがいません。";

function openTransferDialog(event: SubmitEvent): void {
    event.preventDefault();
    if (transferCandidates.length === 0) {
        transferClientError = `${NO_TRANSFER_CANDIDATES}先にメンバーを招待してください。`;
        return;
    }
    if (!isValidTransferTarget) {
        transferClientError = "移譲先のメンバーを選択してください。";
        return;
    }
    transferDialogOpen = true;
}

function transferOwnership(): void {
    guardWithRecentAuth(() => {
        transferForm.post(`/organizations/${organization.slug}/transfer-ownership`, {
            preserveScroll: true,
            onFinish: () => {
                transferDialogOpen = false;
                // 再 mount しないライフサイクル (再認証キャンセル等) でも stale を残さない
                transferClientError = null;
            },
        });
    });
}
```

```svelte
<FormField
    label="移譲先のメンバー"
    id="transfer-target"
    error={transferClientError ?? transferForm.errors.user_id}
>
```

### PHPStan適合チェック
- [x] PHP 変更なし（フロントのみ）＝ PHPStan 影響なし。TypeScript は `pnpm typecheck` で担保
- [x] 新 state は明示型 `$state<string | null>(null)`
- [x] DTO/配列返却なし（該当なし）

### テスト計画
- [x] バグ修正の再現テストを追加（施策3）
- [x] 既存テスト `tests/js/pages/OrganizationsSettings.test.ts` の非退行を維持（既存 it は不変更）
- [x] 個別 `DatabaseTransactions` 不使用（vitest = DB 非依存）

### リスク
- `transferForm.errors.user_id` を display から参照する箇所が他にないか確認済（本 FormField のみ）。
- `$effect` は `transferClientError` と `isValidTransferTarget` にのみ依存し、無限ループしない
  （代入対象 `transferClientError` は条件で null 化 → 次評価で `!== null` が false になり停止）。

---

## 施策 2: プロジェクトメンバー追加 select の stale error 自動解消

### 変更箇所

- ファイル: `resources/js/pages/Projects/Show.svelte`
  - L120-137（`memberForm` 定義〜`submitAddMember`）
  - メンバー追加フォームの `<FormField error=...>`（後述のテンプレート箇所）

### 波及変更

- TypeScript型定義: なし
- API Resource/DTO: なし
- Inertia Props: なし
- テストファイル: `tests/js/pages/ProjectsShow.test.ts`（施策4で追加）
- DS token / atomic import: なし

### 現行コード

```svelte
/* メンバー追加 (store。assignableUsers から選択) */
const memberForm = useForm({ user_id: "", role: "project_member" });

function submitAddMember(event: SubmitEvent): void {
    event.preventDefault();
    if (memberForm.processing) return; // 二重送信ガード
    // 候補未選択なら押下時エラー (disabled にしない = 禁止事項 8)
    if (memberForm.user_id === "") {
        memberForm.setError("user_id", "追加するメンバーを選択してください。");
        return;
    }
    memberForm.post(`/projects/${project.id}/members`, {
        preserveScroll: true,
        onSuccess: () => {
            memberForm.reset();
        },
    });
}
```

テンプレート側 add-member FormField（`Show.svelte` L508-512, `label="メンバー"` /
`id="project-member-user"` / submit ボタン testId=`project-member-submit`）:

```svelte
<FormField
    label="メンバー"
    id="project-member-user"
    error={memberForm.errors.user_id}
>
```

### 変更後コード

```svelte
/* メンバー追加 (store。assignableUsers から選択) */
const memberForm = useForm({ user_id: "", role: "project_member" });
// client precheck 専用の transient error。serverErrors (memberForm.errors) とは分離。
let addMemberClientError = $state<string | null>(null);

// precheck 合格条件 = 候補が選択済み。エラー条件はこの否定。
const isAddMemberSelected = $derived(memberForm.user_id !== "");

// 選択が入った時点で client error を連動クリア (過剰クリア防止)。serverErrors は対象外 = 非退行。
$effect(() => {
    if (addMemberClientError !== null && isAddMemberSelected) {
        addMemberClientError = null;
    }
});

function submitAddMember(event: SubmitEvent): void {
    event.preventDefault();
    if (memberForm.processing) return; // 二重送信ガード
    // 候補未選択なら押下時エラー (disabled にしない = 禁止事項 8)
    if (memberForm.user_id === "") {
        addMemberClientError = "追加するメンバーを選択してください。";
        return;
    }
    memberForm.post(`/projects/${project.id}/members`, {
        preserveScroll: true,
        onSuccess: () => {
            memberForm.reset();
            // reset で user_id が空へ戻るため、直前の client error も揃えて解消する
            addMemberClientError = null;
        },
    });
}
```

```svelte
<!-- add-member FormField (Show.svelte L508-512) -->
<FormField
    label="メンバー"
    id="project-member-user"
    error={addMemberClientError ?? memberForm.errors.user_id}
>
```

### PHPStan適合チェック
- [x] PHP 変更なし（フロントのみ）
- [x] 新 state は明示型 `$state<string | null>(null)`
- [x] DTO/配列返却なし（該当なし）

### テスト計画
- [x] バグ修正の再現テストを追加（施策4）
- [x] 既存テスト `tests/js/pages/ProjectsShow.test.ts` の非退行を維持
- [x] 個別 `DatabaseTransactions` 不使用

### リスク
- `memberForm.reset()` は role も既定へ戻す（既存挙動）。`addMemberClientError = null` の追加は
  reset 後の UI と整合させるためで、副作用は client error の解消のみ。
- `isAddMemberSelected` は「非空」判定のみ（サーバが assignableUsers 実在を最終検証）。これは
  既存 precheck（`user_id === ""`）と同一射程で、過剰な追加検証をしない（YAGNI）。

---

## 施策 3: オーナー移譲の再現テスト追加

### 変更箇所
- ファイル: `tests/js/pages/OrganizationsSettings.test.ts`（既存 describe に it 追加）

### テスト前提
- 既存流儀に追随: `useForm` は実物、`router` のみ vi.mock。owner 権限 props で
  transfer-ownership フォームを描画。
- select の testId / label は「移譲先のメンバー」。送信ボタン testId は
  `transfer-ownership-button`。エラー文言は本文と同一（`移譲先のメンバーを選択してください。`）。
- select option value は `String(id)`（比較契約の固定）。

### 追加テストケース
1. **stale 解消（有効値復帰）**: 空選択のまま送信 → エラー文言 + `aria-invalid=true` を確認
   → 有効候補 (`String(member.id)`) を選択 → エラー文言消失 + `aria-invalid` 解除を `waitFor` で確認。
2. **過剰クリア防止（無効→無効）**: 空選択で送信しエラー表示 → 別の「空文字 option」等
   無効値のまま（`isValidTransferTarget=false`）ではエラーが残留することを確認
   （候補 0 人 props でも「先にメンバーを招待してください」エラーが残る＝選択で直せないケース）。
3. **serverErrors 非退行**: `transferForm.errors.user_id` 経路（サーバ由来）の表示は client
   `$effect` の影響を受けないことを、client precheck 未発火時のフォーム描画で担保。
   （client-error と server-error が排他表示され、client 側クリアが server-error を消さない
   ことを、precheck エラー未設定状態＝ `transferClientError=null` で確認）。

### PHPStan適合チェック
- [x] 型: テストは TS。`pnpm typecheck` で担保。`String()` 比較契約を維持

### リスク
- なし（テスト追加のみ、既存 it は不変更＝禁止事項 #3「既存テスト削除・上書き」に非該当）。

---

## 施策 4: プロジェクトメンバー追加の再現テスト追加

### 変更箇所
- ファイル: `tests/js/pages/ProjectsShow.test.ts`（既存 describe に it 追加）

### テスト前提
- `assignableUsers` に候補を含む props で add-member フォームを描画。`canManageMembers=true`。
- add-member select は `label="メンバー"` / `id="project-member-user"`、submit ボタン
  testId=`project-member-submit`。エラー文言は `追加するメンバーを選択してください。`。
- option value は `String(candidate.id)`（比較契約の固定）。

### 追加テストケース
1. **stale 解消（有効値復帰）**: 未選択で追加ボタン押下 → エラー + `aria-invalid` 確認
   → assignableUsers の候補を選択 → エラー消失 + `aria-invalid` 解除を `waitFor` で確認。
2. **過剰クリア防止（無効のまま）**: 未選択で押下しエラー表示 → 空選択のままではエラー残留。
3. **serverErrors 非退行**: `memberForm.errors.user_id` 経路が client `$effect` で消えないこと
   を、client precheck 未発火状態の描画で担保。

### PHPStan適合チェック
- [x] 型: テストは TS。`pnpm typecheck` で担保

### リスク
- なし（テスト追加のみ）。

---

## 検証手順（実装時）

全 green を確認してコミット:

```
pnpm lint:fix && pnpm lint && pnpm typecheck && pnpm test && pnpm build
```

PHP は無変更のため `composer phpstan` / `composer test` は現状維持（回帰なし）。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | pages 層 2 ファイル + テスト 2 ファイルの局所追記のみ。既存構造・Props・API を変えず、他施策や進行中作業との競合面が小さい。T041/T033 と同一イディオムで確立済みの低リスク横展開のため、standalone worktree を切るほどの独立性は不要 |
| 競合リスク | 低。`Organizations/Settings.svelte` / `Projects/Show.svelte` を同時編集する他タスクがなければ衝突しない。両ファイルは独立で相互依存なし |


---

## 参考: T041 で確立した既存イディオム (PurchaseTickets.svelte)

```svelte
let clientError = $state<string | null>(null);
const isValidCount = $derived(
    parsedCount !== null && parsedCount >= page.minCount && parsedCount <= page.maxCount,
);
$effect(() => {
    if (clientError !== null && isValidCount) {
        clientError = null;
    }
});
// 表示: error={clientError ?? serverErrors.count ?? serverErrors.attempt_token ?? null}
```
