# 詳細設計レビュー Round 2

Round 1 の指摘 (Critical 4 / Warning 5 / Suggestion 4) はすべて対応した。
特に施策 6 の transaction 境界 (PostgreSQL の aborted transaction) と
施策 4 のサーバ側欠落 (409 分岐が url.intended / dropped_mutation を残さない) は
設計を実質的に変更している。
再判定 (施策ごとの APPROVE / REQUEST_CHANGES と全体判定) を出すこと。

# 対応マトリクス: design-review Round 1

## [Critical] 施策 2: gate が alias import しか見ておらず相対 import で bypass できる
- 判断: 対応する
- 根拠: 妥当。gate は「書き方を変えれば抜けられる」時点で deny-by-default ではない。
- 対応内容: 主検出を **`<RecentAuthModal` タグ出現**に変更し、import 検出は
  パス末尾一致 (`[^"']*RecentAuthModal\.svelte`) の補助検出として残した (両者の和で列挙)。
  `/g` 正規表現の `lastIndex` 持ち越しを避ける注記も追加。

## [Warning] 施策 2: `onStale` の存在確認だけでは `recentAuthStatus` への格納を保証しない
- 判断: 対応する
- 根拠: 妥当。
- 対応内容: `ON_STALE_ASSIGNMENT_PATTERN` (`onStale: (status) => { ... recentAuthStatus = status`)
  に変更し、代入まで検査する。

## [Suggestion] 施策 3: `data` ラップの有無も contract テストで固定
- 判断: 対応する
- 根拠: `$wrap = null` が外れると TS の strict parse が全件 `null` を返し、全画面が delegated に落ちる。
- 対応内容: Feature contract テストに「top-level に `data` ラップが無いこと」を追加。

## [Critical] 施策 4: 409 分岐が `url.intended` / `dropped_mutation` を保存していない
- 判断: 対応する
- 根拠: 妥当かつ重要。施策 4 で 409 → confirm 画面へ飛ばすようになると、
  confirm 成功後に元画面へ戻れず dashboard へ落ち、「操作は実行されていません」の案内も出ない
  (操作のサイレント喪失)。302 分岐だけが着地契約を持っていたのは、409 を拾う実装が無かったため。
- 対応内容: `RequireRecentAuth` を変更箇所に追加。**Inertia mutation の 409 のみ**
  `url.intended` (same-origin referer) と `recent_auth.dropped_mutation` を保存する。
  純 XHR (fetch) はクライアントが自前で再開するため対象外にし、他フローの intended を汚さない。
  Feature テスト 2 本 (Inertia mutation で intended 保持 + 再操作案内 / 純 XHR では書き換えない) を追加。

## [Warning] 施策 4: `event.detail.response` の形状依存
- 判断: 対応する
- 根拠: 妥当。
- 対応内容: 引数を `unknown` として narrowing する形は維持しつつ、
  「実装時に `@inertiajs` の型定義で実体を確認する」「`data` を持たない形なら
  preventDefault せず既定処理へ渡る (fail-closed)」を明記。テストの mock も実 event 形状に合わせる。

## [Suggestion] 施策 5: `logout()` の二重送信ガード
- 判断: 対応する
- 対応内容: `if (loggingOut) return;` を追加。

## [Critical] 施策 6: best-effort な副作用を transaction 内に入れると PostgreSQL で巻き添えになる
- 判断: 対応する
- 根拠: 妥当かつ本質的。PostgreSQL は transaction 内で失敗した文があると以降 aborted になり、
  アプリ側で catch しても commit できない。監査記録 (recorder が Throwable を握る) や
  session 行削除 (best-effort catch) を transaction に入れると、
  **best-effort のつもりの副作用がパスワード保存を巻き添えにする**。
  既存 `UpdateUserPassword` はこれらを transaction 外で実行しており、その性質を保つべき。
- 対応内容: `setInitial()` の transaction を「ロック → 前提の再確認 → password 保存」だけにし、
  監査記録 / `logoutOtherDevices` / session 行削除は **commit 後**の `afterPersist()` へ移した。
  `change()` は単一 UPDATE なので transaction を開かない (既存挙動を変えない)。
  リスク節も書き換え。

## [Warning] 施策 6: `UpdateUserPassword` の constructor 差し替えが未記載
- 判断: 対応する
- 対応内容: `SecurityEventRecorder` → `PasswordCredentialService` への依存差し替えを明記し、
  テスト計画に「DI 解決まで通ることを既存 Feature テストで確認」を追加。

## [Warning] 施策 7: `props.hasPassword ?? false` は状態不明を誤った UI に倒す
- 判断: 対応する
- 根拠: 妥当。本批で潰している species そのもの (施策 1 の null 分岐と同じ扱いにすべき)。
- 対応内容: `"set" | "unset" | "unknown"` の 3 値に変更。unknown では**どちらのフォームも出さず**
  警告 Alert + 再読み込みボタンを出す。JS テストにも unknown ケースを追加。

## [Suggestion] 施策 8: `settingsUrl` が返らないことを contract テストで固定
- 判断: 対応する
- 対応内容: 422 ボディのキー集合が `code` / `message` に一致することを固定 (再追加を機械的に防ぐ)。

## [Critical] 施策 11: precheck 中の連打で ceremony が多重起動する
- 判断: 対応する
- 根拠: 妥当。`guard()` (= `/recent-auth/status` の fetch) 待ちの区間が無防備。
- 対応内容: `guard` prop の型を
  `(action: () => void) => Promise<"fresh" | "stale" | "delegated">` に変更し
  (`withRecentAuth` の戻り値をそのまま流す)、precheck 区間を `prechecking` state で覆う。
  ボタンの loading は `prechecking || registering`。stale 委譲時は precheck を閉じ、
  再開は modal `onConfirmed` → `resumePendingAction` 経路で `registering` が握る
  (モーダルをキャンセルしてもボタンが loading のまま固まらない)。
  波及として `Settings/Security.svelte` の `guardWithRecentAuth` を戻り値返却に変更し、
  他の呼び出し側は `void` で明示破棄する。

## [Warning] 施策 11: 連打テストは precheck pending 中も対象にする
- 判断: 対応する
- 対応内容: 「guard の解決を遅延させる mock で複数クリックしても ceremony/pending action が 1 つ」
  「stale 委譲後にモーダルをキャンセルしてもボタンが固まらない」の 2 ケースを追加。

## [Suggestion] 施策 1: null 表示のテストで誤った導線が出ないことも固定
- 判断: 対応する
- 対応内容: `status=null` で password フォーム / SSO / パスキー / 回復 notice のいずれも
  描画されないことをテストに追加。

## [Warning] 横断: `npx vitest` 直叩きは規約 (T099 グローバルロック) 違反
- 判断: 対応する
- 対応内容: テスト計画の実行コマンドを `pnpm test <path>` (= `scripts/run-vitest.sh` 経由) に統一した。


---

## 修正後の詳細設計書 (全文)

# 詳細設計: ui-audit-remediation

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
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ずFactoryで生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- `resources/js` は TypeScript 必須。T102 の eslint `noInlineConfig` により inline `eslint-disable` 不可。
  svelte `no-undef` は error
- テストは T099 のグローバルテストロック経由 (`pnpm test` / `composer test`)

## 概念設計リファレンス

- `devnotes/20260805-1553-ui-audit-remediation/conceptual-design.md` (APPROVED / conceptual-review Round 5)
- 監査レポート: `devnotes/20260805-1600-audit-cycle-2/ui-consistency.md`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | RecentAuthModal の props 契約を `status` 1 本に統合し、6 呼び出し側を配線する | `components/organisms/RecentAuthModal.svelte` + 6 pages | Critical |
| 2 | call-site inventory gate を新設する (deny-by-default) | `tests/js/architecture/recent-auth-modal-call-site-inventory.test.ts` (新規) | Critical |
| 3 | `/recent-auth/status` の strict parse 化 (既定値補完の廃止) + contract テスト | `lib/recent-auth.ts`, Feature/JS テスト | Critical |
| 4 | 409 `recent_auth_required` の単一ハンドラを配線する (delegated の着地) | `lib/recent-auth.ts`, `app.ts` | High |
| 5 | 回復導線を `RecentAuthRecoveryNotice` molecule に集約する (踏破不能 CTA の除去) | `components/molecules/RecentAuthRecoveryNotice.svelte` (新規), `RecentAuthModal.svelte`, `pages/Auth/ConfirmRecentAuth.svelte`, logout inventory | High |
| 6 | パスワード**初回設定**経路を新設する (サーバ) | route / `PasswordSetupController` / `SetPasswordRequest` / `PasswordCredentialService` / `UpdateUserPassword` / `SecurityEventType` | High |
| 7 | `/settings` のパスワードカードを `hasPassword` で出し分ける | `ProfileController`, `pages/Settings/Index.svelte` | High |
| 8 | `LoginMethodRequiredDto.settingsUrl` を削除し、`PasskeySection` の CTA を踏破可能にする | `LoginMethodRequiredDto` / `LoginMethodRequiredResource` / `EnsureLoginMethodRemains` / `PasskeySection.svelte` | High |
| 9 | WebAuthn ceremony 失敗の提示を Alert に統一する (F-3) + DESIGN.md 規約化 | `PasskeySection.svelte`, `RecentAuthModal.svelte`, `ConfirmRecentAuth.svelte`, `DESIGN.md` | Medium |
| 10 | `PasskeySection` の `nameError` を `$derived` canonical 形にする (F-4) | `PasskeySection.svelte` | Medium (切離可) |
| 11 | `PasskeySection` 登録フローの整理 (F-7: loading 保持 / サーバ error / focus 移動) | `PasskeySection.svelte` | Medium (切離可) |
| 12 | ドキュメント更新 (DESIGN.md / docs/supported-browsers.md) | `DESIGN.md`, `docs/supported-browsers.md` | High |

---

## 施策 1: RecentAuthModal の props 契約を `status` 1 本に統合する

### 変更箇所

- `resources/js/components/organisms/RecentAuthModal.svelte` (L24-60, L148-222)
- 呼び出し側 6 ページ:
  - `resources/js/pages/Settings/Security.svelte` (L602-609)
  - `resources/js/pages/Settings/Index.svelte` (L287-293)
  - `resources/js/pages/Organizations/Settings.svelte` (L336-342)
  - `resources/js/pages/Organizations/ApiKeys/Index.svelte` (L303-309)
  - `resources/js/pages/Organizations/ApiKeys/Sessions.svelte` (L209-215)
  - `resources/js/pages/Admin/Users.svelte` (L520-526)

### 波及変更

- TypeScript 型定義: `RecentAuthStatus` (`lib/recent-auth.ts`) を modal が import する (型追加は不要)
- API Resource/DTO: なし (サーバ contract は不変)
- テストファイル: `tests/js/pages/SettingsSecurityPasskey.test.ts` (モーダル分岐のケース)、
  新規 `tests/js/components/organisms/RecentAuthModal.test.ts`、施策 2 の gate

### 現行コード

```svelte
interface Props {
    open: boolean;
    passwordSet?: boolean;
    availableProviders?: AvailableReauthProvider[];
    canSatisfy?: boolean;
    passkeyAvailable?: boolean;
    onConfirmed: () => void;
}

let {
    open = $bindable(false),
    passwordSet = false,
    availableProviders = [],
    canSatisfy = true,
    passkeyAvailable = false,
    onConfirmed,
}: Props = $props();
```

呼び出し側 (5 画面が `passkeyAvailable` を渡していない):

```svelte
<RecentAuthModal
    bind:open={recentAuthOpen}
    passwordSet={recentAuthStatus?.passwordSet ?? false}
    availableProviders={recentAuthStatus?.availableProviders ?? []}
    canSatisfy={recentAuthStatus?.canSatisfy ?? true}
    onConfirmed={resumePendingAction}
/>
```

### 変更後コード

```svelte
<script lang="ts">
    import { router } from "@inertiajs/svelte";
    // ...
    import type { RecentAuthStatus } from "@/lib/recent-auth";

    /**
     * 機微操作 (API キー発行/失効・アカウント削除・オーナー移譲) の前に出す
     * 「同一画面の再認証 (step-up) モーダル」。
     *
     * **契約: `/recent-auth/status` の応答 (RecentAuthStatus) を分解せず 1 個の型で受ける**。
     * field を prop に分解して手渡す形は、field が増えるたびに配線漏れを生む
     * (T106 で passkeyAvailable を足した際、6 呼び出し中 5 箇所が未配線のまま出荷され、
     *  passkey-only ユーザーが 5 画面で詰んだ)。呼び出し側が独自に status を組み立てないこと。
     * 強制は tests/js/architecture/recent-auth-modal-call-site-inventory.test.ts
     * (deny-by-default)。pnpm typecheck は tsc --noEmit で .svelte テンプレートを
     * 型検査しないため、型宣言だけでは配線漏れを止められない。
     *
     * status === null は「状態不明」(呼び出し側の実装ミス)。空表示や事実に反する文言を
     * 出さず、取得失敗として明示し再読み込み導線を出す。
     */
    interface Props {
        open: boolean;
        /** /recent-auth/status の応答。null = 状態不明 (通常経路では発生しない) */
        status: RecentAuthStatus | null;
        /** satisfier 成功時。呼び出し側が pending action を再開する */
        onConfirmed: () => void;
    }

    let { open = $bindable(false), status, onConfirmed }: Props = $props();

    const passwordSet = $derived(status?.passwordSet ?? false);
    const availableProviders = $derived(status?.availableProviders ?? []);
    const canSatisfy = $derived(status?.canSatisfy ?? false);
    const passkeyAvailable = $derived(status?.passkeyAvailable ?? false);

    const passkeySupported = isPasskeySupported();

    /** この端末で実行できる satisfier があるか (アカウント能力 canSatisfy とは別) */
    const executableHere = $derived(
        passwordSet || availableProviders.length > 0 || (passkeyAvailable && passkeySupported),
    );
</script>
```

テンプレート末尾の分岐 (施策 5 / 9 の変更も織り込んだ最終形):

```svelte
{#if status === null}
    <div class="flex flex-col gap-2 text-caption text-text-secondary" data-testid="recent-auth-unknown">
        <p>再認証の状態を取得できませんでした。ページを再読み込みしてお試しください。</p>
        <Button variant="ghost" fullWidth onclick={() => router.reload()}>再読み込み</Button>
    </div>
{:else if !canSatisfy}
    <RecentAuthRecoveryNotice variant="no-satisfier" />
{:else if !executableHere}
    <RecentAuthRecoveryNotice variant="not-executable-here" />
{/if}
```

呼び出し側 6 画面 (すべて同一):

```svelte
<RecentAuthModal
    bind:open={recentAuthOpen}
    status={recentAuthStatus}
    onConfirmed={resumePendingAction}
/>
```

### 設計判断: なぜ nullable のままにするか

非 nullable にして呼び出し側で `{#if recentAuthStatus !== null}` する案は採らない。
`bind:open` は component が mount されていないと `open=false` に戻せず、
「`open=true` なのに何も描画されない」= **本批で潰そうとしているのと同じ species の
無言の行き止まり**を 6 画面ぶん新規に作るため。component を入力に対し全域にする。

### PHPStan適合チェック

- [x] PHP 変更なし (該当なし)

### テスト計画

- [ ] 新規 `tests/js/components/organisms/RecentAuthModal.test.ts`
  - `status.passkeyAvailable=true` + 対応ブラウザ → `recent-auth-passkey` ボタンが出る
  - `status.passwordSet=false` / `availableProviders=[]` / `passkeyAvailable=true` + 非対応ブラウザ
    → `recent-auth-unsupported-here` (回復 notice) が出る
  - `status.canSatisfy=false` → `recent-auth-recovery` が出る
  - `status=null` → `recent-auth-unknown` + 再読み込みボタン (空表示にしない)。
    かつ **password フォーム / SSO ボタン / パスキーボタン / 回復 notice のいずれも出ない**
    (状態不明を誤った導線に倒さない)
- [ ] 回帰: `tests/js/pages/OrganizationsSettings.test.ts` に
      「passkey-only + stale なら再認証モーダルにパスキー導線が出る」を追加
      (**未配線だった 5 画面のうち 1 画面で実配線を証明する**。残り 4 画面は施策 2 の gate が担保)
- [ ] 既存 `tests/js/pages/SettingsSecurityPasskey.test.ts` のモーダル関連ケースを新 props へ更新
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認 (JS テストのため該当なし)

### リスク

- 旧 prop を残さないため、未更新の呼び出し側があるとモーダルが「状態不明」表示になる
  → 施策 2 の gate が CI で必ず落とすので、無言劣化にはならない

---

## 施策 2: call-site inventory gate の新設

### 変更箇所

- `tests/js/architecture/recent-auth-modal-call-site-inventory.test.ts` (新規)

### 波及変更

- TypeScript 型定義 / API Resource / DTO: なし
- テストファイル: 本体が新規テスト

### 変更後コード

```ts
import { describe, it, expect } from "vitest";
import fs from "fs/promises";
import path from "path";

/**
 * RecentAuthModal の props 契約を deny-by-default で固定する。
 *
 * T106 で `passkeyAvailable` を optional prop として足した結果、6 呼び出し中 5 箇所が
 * 未配線のまま出荷され、passkey-only ユーザーが 5 画面で「手段 0 + 事実に反する文言」で
 * 詰んだ (監査 F-1)。pnpm typecheck は `tsc --noEmit` で .svelte テンプレートを型検査しないため、
 * 必須 prop 化だけでは配線漏れを止められない。ここが唯一の機械的強制点になる。
 *
 * 新しい呼び出し側を足す場合は、`withRecentAuth` の onStale で受けた status を
 * `recentAuthStatus` に格納し、`status={recentAuthStatus}` で渡した上で inventory に登録すること。
 *
 * 既知の限界: 検出は文字列パターンに依存する。JSX 的な spread ({...props}) や
 * 動的コンポーネント経由の描画は検出できない。導入する際は本テストも同時に更新すること。
 */

const JS_ROOT = path.resolve(__dirname, "../../../resources/js");

/** RecentAuthModal を使ってよいファイル (resources/js からの相対パス) */
const RECENT_AUTH_MODAL_CALL_SITES: readonly string[] = [
  "pages/Settings/Index.svelte",
  "pages/Settings/Security.svelte",
  "pages/Organizations/Settings.svelte",
  "pages/Organizations/ApiKeys/Index.svelte",
  "pages/Organizations/ApiKeys/Sessions.svelte",
  "pages/Admin/Users.svelte",
] as const;

/**
 * 主検出は **タグ出現**。alias import (`@/components/...`) だけを見ると
 * 相対 import で bypass できるため、import 形に依存しない検出を主にする。
 */
const TAG_PATTERN = /<RecentAuthModal\b[^>]*>/g;
/** 補助検出 (import だけして別名で描画する形も未登録として拾う) */
const IMPORT_PATTERN = /import\s+\w+\s+from\s+["'][^"']*RecentAuthModal\.svelte["']/;
/** status は識別子まで固定する (任意式・undefined・即席オブジェクトを許さない) */
const STATUS_PROP_PATTERN = /status=\{recentAuthStatus\}/;
/** 契約統合前の旧 prop (後方互換の並走を残さない) */
const LEGACY_PROPS: readonly string[] = [
  "passwordSet=",
  "availableProviders=",
  "canSatisfy=",
  "passkeyAvailable=",
] as const;
/** status の出所を /recent-auth/status 1 本に固定する */
const WITH_RECENT_AUTH_PATTERN = /withRecentAuth/;
/** onStale の引数を recentAuthStatus に格納していること (存在確認だけでは不十分) */
const ON_STALE_ASSIGNMENT_PATTERN = /onStale:\s*\(status\)\s*=>\s*\{[^}]*recentAuthStatus\s*=\s*status/s;

const SOURCE_EXTENSIONS: readonly string[] = [".svelte", ".ts"] as const;

const listSourceFiles = async (dir: string): Promise<string[]> => {
  const entries = await fs.readdir(dir, { recursive: true, withFileTypes: true });
  const files: string[] = [];
  for (const entry of entries) {
    if (!entry.isFile()) continue;
    if (!SOURCE_EXTENSIONS.includes(path.extname(entry.name))) continue;
    const parent = (entry as unknown as { parentPath?: string }).parentPath ?? dir;
    files.push(path.join(parent, entry.name));
  }
  return files;
};

describe("recent-auth modal call site inventory", () => {
  it("RecentAuthModal を描画/import するのは inventory 登録分のみ", async () => {
    const files = await listSourceFiles(JS_ROOT);
    const offenders: string[] = [];
    for (const file of files) {
      const content = await fs.readFile(file, "utf8");
      // タグ出現 (主) と import (補助) の和で検出する (import 形に依存しない)
      if (!TAG_PATTERN.test(content) && !IMPORT_PATTERN.test(content)) continue;
      TAG_PATTERN.lastIndex = 0;   // /g の状態を持ち越さない
      const rel = path.relative(JS_ROOT, file).split(path.sep).join("/");
      if (!RECENT_AUTH_MODAL_CALL_SITES.includes(rel)) offenders.push(rel);
    }
    expect(
      offenders,
      `未登録の RecentAuthModal 呼び出しが見つかりました。status={recentAuthStatus} で渡していることを確認して inventory へ登録してください:\n${offenders.join("\n")}`,
    ).toEqual([]);
  });

  it("全呼び出しが status={recentAuthStatus} を渡し、旧 prop を渡さない", async () => {
    for (const rel of RECENT_AUTH_MODAL_CALL_SITES) {
      const content = await fs.readFile(path.join(JS_ROOT, rel), "utf8");
      const tags = content.match(TAG_PATTERN) ?? [];
      expect(tags.length, `${rel} に <RecentAuthModal> が無い`).toBeGreaterThan(0);
      for (const tag of tags) {
        expect(tag, `${rel} が status={recentAuthStatus} を渡していない`).toMatch(
          STATUS_PROP_PATTERN,
        );
        for (const legacy of LEGACY_PROPS) {
          expect(
            tag.includes(legacy),
            `${rel} が旧 prop ${legacy} を渡している (契約は status 1 本)`,
          ).toBe(false);
        }
      }
    }
  });

  it("status の出所は withRecentAuth の onStale に固定される", async () => {
    for (const rel of RECENT_AUTH_MODAL_CALL_SITES) {
      const content = await fs.readFile(path.join(JS_ROOT, rel), "utf8");
      expect(content, `${rel} が withRecentAuth を使っていない`).toMatch(
        WITH_RECENT_AUTH_PATTERN,
      );
      expect(
        content,
        `${rel} が onStale で受けた status を recentAuthStatus に格納していない (画面ごとの独自判定を作らない)`,
      ).toMatch(ON_STALE_ASSIGNMENT_PATTERN);
    }
  });
});
```

### テスト計画

- [ ] 本テスト自体が gate。実装前に **5 画面未配線の状態で fail することを確認**してから配線する
      (AGENTS.md 思考原則 5 テストファースト)
- [ ] `pnpm test tests/js/architecture` が all green (T099 のグローバルテストロック経由。`npx vitest` 直叩きはしない)

### リスク

- 文字列パターン依存のため書式変更 (prettier の属性折返し) で誤検出しうる
  → タグ全体を 1 マッチとして取り出す `TAG_PATTERN` は複数行属性も `[^>]*` で拾える。
  `pnpm lint` / prettier 適用後の形で確認する

---

## 施策 3: `/recent-auth/status` の strict parse 化

### 変更箇所

- `resources/js/lib/recent-auth.ts` (L39-59 `fetchRecentAuthStatus`)

### 波及変更

- TypeScript 型定義: `RecentAuthStatus` / `AvailableReauthProvider` は変更なし (parse 側のみ)
- API Resource/DTO: 変更なし (`RecentAuthStatusResource` の shape を**固定する**テストを追加)
- テストファイル: 新規 `tests/js/lib/recent-auth.test.ts`、
  新規 `tests/Feature/Auth/RecentAuthStatusContractTest.php`

### 現行コード

```ts
const body = (await res.json()) as Partial<RecentAuthStatus>;
if (typeof body.recent !== "boolean") return null;
return {
    recent: body.recent,
    passwordSet: body.passwordSet ?? false,
    availableProviders: body.availableProviders ?? [],
    passkeyAvailable: body.passkeyAvailable ?? false,
    canSatisfy: body.canSatisfy ?? false,
    confirmedAt: body.confirmedAt ?? null,
};
```

### 変更後コード

```ts
/**
 * `/recent-auth/status` の応答を **strict に** 検証して返す。
 *
 * 既定値による補完はしない: field が欠けた応答を既定値で埋めると
 * 「サーバは手段があると言っているのに UI に出ない」= 監査 F-1 と同じ詰みが
 * **通信境界で再演**する (call-site gate では検出できない)。
 * 契約不成立は null にし、withRecentAuth の delegated 経路 (= サーバの最終ゲートへ委譲) に倒す。
 */
function parseProvider(value: unknown): AvailableReauthProvider | null {
    if (typeof value !== "object" || value === null) return null;
    const { provider, capability, reauthUrl } = value as Record<string, unknown>;
    if (typeof provider !== "string") return null;
    if (typeof capability !== "string") return null;
    if (typeof reauthUrl !== "string") return null;
    return { provider, capability, reauthUrl };
}

function parseRecentAuthStatus(body: unknown): RecentAuthStatus | null {
    if (typeof body !== "object" || body === null) return null;
    const { recent, passwordSet, availableProviders, passkeyAvailable, canSatisfy, confirmedAt } =
        body as Record<string, unknown>;
    if (typeof recent !== "boolean") return null;
    if (typeof passwordSet !== "boolean") return null;
    if (typeof passkeyAvailable !== "boolean") return null;
    if (typeof canSatisfy !== "boolean") return null;
    if (confirmedAt !== null && typeof confirmedAt !== "number") return null;
    if (!Array.isArray(availableProviders)) return null;

    const providers: AvailableReauthProvider[] = [];
    for (const raw of availableProviders) {
        const parsed = parseProvider(raw);
        if (parsed === null) return null;   // 要素の欠落は「SSO ボタンが出ない」詰みになる
        providers.push(parsed);
    }

    return { recent, passwordSet, availableProviders: providers, passkeyAvailable, canSatisfy, confirmedAt };
}

export async function fetchRecentAuthStatus(): Promise<RecentAuthStatus | null> {
    try {
        const res = await fetch("/recent-auth/status", {
            headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
            credentials: "same-origin",
        });
        if (!res.ok) return null;
        return parseRecentAuthStatus(await res.json());
    } catch {
        return null;
    }
}
```

### PHPStan適合チェック

- [x] PHP 側は Feature テスト追加のみ (型変更なし)。`RecentAuthStatusResource::toArray()` の
      array shape PHPDoc は既存のまま

### テスト計画

- [ ] 新規 `tests/js/lib/recent-auth.test.ts`
  - 完全な応答 → 各 field が写る
  - top-level 各 field (recent / passwordSet / passkeyAvailable / canSatisfy / confirmedAt) の
    欠損・型不一致 → `null`
  - `availableProviders` が非配列 → `null`
  - provider 要素の `provider` / `capability` / `reauthUrl` の欠損・型不一致 → `null`
  - `res.ok === false` / JSON パース失敗 → `null`
  - `withRecentAuth` が `null` を受けたとき `delegated` を返し `onStale` を呼ばない
- [ ] 新規 `tests/Feature/Auth/RecentAuthStatusContractTest.php`
  - `/recent-auth/status` の JSON キー集合が
    `recent / passwordSet / availableProviders / passkeyAvailable / canSatisfy / confirmedAt` に**一致**
    (過不足を許さない = TS 側 strict parse と噛み合う)
  - **top-level に `data` ラップが無いこと** (`RecentAuthStatusResource::$wrap = null` の維持。
    ラップされると TS の strict parse は即 `null` になり、全画面が delegated へ落ちる)
  - 各値の型 (bool / array / int|null)
  - SSO 連携ありユーザーで provider 要素のキーが `provider / capability / reauthUrl` に一致
  - `Cache-Control: no-store, private`
  - テストデータは `User::factory()` (+ 既存の social account factory) で生成する
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- 厳格化により、サーバが将来 field を**追加**したときは JS 側は壊れない (未知キーは無視) が、
  Feature テストのキー集合一致は落ちる → 契約変更に気づける設計として意図的

---

## 施策 4: 409 `recent_auth_required` の単一ハンドラ

### 変更箇所

- `resources/js/lib/recent-auth.ts` (末尾に `registerRecentAuthRedirectHandler` を追加)
- `resources/js/app.ts` (L1-30 の登録ブロックに配線)
- `app/Http/Middleware/RequireRecentAuth.php` (**409 分岐で `url.intended` /
  `recent_auth.dropped_mutation` を保存する**。下記「サーバ側の欠落」参照)

### 波及変更

- TypeScript 型定義: なし (ローカル型で応答を検証)
- API Resource/DTO: なし (`RecentAuthRequiredResource` は既存のまま)
- テストファイル: `tests/js/lib/recent-auth.test.ts` に handler のケースを追加、
  `tests/Feature/Auth/` の recent-auth 既存テスト (409 経路) に intended 保持のケースを追加

### サーバ側の欠落 (409 分岐が着地情報を残していない)

現行 `RequireRecentAuth` は **302 分岐でだけ** `url.intended` と
`recent_auth.dropped_mutation` を保存し、409 分岐では何も残さない。
これまでは 409 を拾うクライアントが居なかったため露見しなかったが、施策 4 で
confirm 画面へ遷移させるようになると、**confirm 成功後に元画面へ戻れず dashboard へ落ちる**
(かつ「先ほどの操作は実行されていません」の案内も出ない = 操作のサイレント喪失)。

```php
if ($request->expectsJson() || $this->isInertiaMutation($request)) {
    // Inertia mutation は 409 を受けたクライアントが confirm 画面へ visit する
    // (lib/recent-auth.ts の単一ハンドラ)。302 分岐と同じ着地契約に揃えるため、
    // 元 URL と「mutation body を落とした」flag をここでも残す。
    // 純 XHR (fetch, Accept: application/json) は**対象外**: クライアントが自前で
    // pending action を再開するため intended を書くと他フローの intended を汚す。
    if ($this->isInertiaMutation($request)) {
        $session->put('url.intended', $this->sameOriginRefererOrDashboard($request));
        $session->put('recent_auth.dropped_mutation', true);
    }

    return RecentAuthRequiredResource::make(...)->response()->setStatusCode(409)...;
}
```

### 現行の穴

`RequireRecentAuth` は Inertia の非 GET / `expectsJson()` に
**409 + `{ code: "recent_auth_required", message, redirect }`** を返すが、
`grep recent_auth_required resources/js` = 0 件 = **誰も拾っていない**。
一方 `withRecentAuth` の delegated 分岐は「再認証が必要な場合は確認ページへ移動します。」と
toast で予告している (予告だけして移動しない無言失敗)。施策 3 で delegated への流入が増えるため同批で閉じる。

### 変更後コード

```ts
import { router } from "@inertiajs/svelte";

/** RecentAuthRequiredDto::CODE と対 (code 厳格一致で自分宛て応答のみ処理する) */
const RECENT_AUTH_REQUIRED_CODE = "recent_auth_required";
/** 遷移を許す唯一の着地 (サーバ由来 URL を無検証でグローバル遷移に使わない) */
const RECENT_AUTH_CONFIRM_PATH = "/recent-auth/confirm";

/**
 * `event.detail.response` は Inertia core が渡す axios response 互換オブジェクト
 * (`{ status, data }`)。実装時に `@inertiajs/svelte` (core) の型定義で実体を確認し、
 * ここでの narrowing を合わせること (native `Response` なら `data` は存在しないため、
 * 下の型ガードは false を返して Inertia 既定処理に渡る = fail-closed)。
 */
function recentAuthRedirectTarget(response: unknown): string | null {
    if (typeof response !== "object" || response === null) return null;
    const { status, data } = response as { status?: unknown; data?: unknown };
    if (status !== 409) return null;
    if (typeof data !== "object" || data === null) return null;
    const { code, redirect } = data as Record<string, unknown>;
    if (code !== RECENT_AUTH_REQUIRED_CODE) return null;   // 他の 409 契約を誤食しない
    if (typeof redirect !== "string") return null;
    // same-origin かつ既知 path のみ (外部 URL / 別 route への誘導を構造的に不能にする)
    let url: URL;
    try {
        url = new URL(redirect, window.location.origin);
    } catch {
        return null;
    }
    if (url.origin !== window.location.origin) return null;
    if (url.pathname !== RECENT_AUTH_CONFIRM_PATH) return null;
    return url.pathname + url.search;
}

/**
 * recent-auth 鮮度切れの 409 を confirm 画面への Inertia visit に変換する。
 *
 * precheck (withRecentAuth) を通れない経路 = status 取得失敗・契約不成立 (delegated) では
 * 元操作がそのまま飛び、サーバが 409 を返す。誰も拾わないと Inertia の既定 (invalid response)
 * になり **無言の行き止まり**になるため、ここで単一のハンドラに集約する。
 * 受入条件を満たさない応答は preventDefault せず Inertia 既定処理へ渡す (fail-closed)。
 *
 * @returns 購読解除関数 (HMR の二重登録防止に使う)
 */
export function registerRecentAuthRedirectHandler(): () => void {
    return router.on("invalid", (event) => {
        const target = recentAuthRedirectTarget(event.detail.response);
        if (target === null) return;
        event.preventDefault();
        void router.visit(target);
    });
}
```

`app.ts`:

```ts
const disposeRecentAuthRedirect = registerRecentAuthRedirectHandler();
import.meta.hot?.dispose(disposeRecentAuthRedirect);
```

### テスト計画

- [ ] `tests/js/lib/recent-auth.test.ts` に追加
  - 409 + `code: recent_auth_required` + 同一 origin の `/recent-auth/confirm`
    → `preventDefault()` が呼ばれ `router.visit("/recent-auth/confirm")` する
  - 409 だが `code` が別 (`scenario_conflict` / `two_factor_required`) → preventDefault しない
  - 409 + `redirect` が外部 URL (`https://evil.example/...`) → preventDefault しない
  - 409 + `redirect` が別 route (`/dashboard`) → preventDefault しない
  - 409 + `redirect` 欠損 / 非文字列 → preventDefault しない
  - 409 以外 (422 / 500) → preventDefault しない
  - `registerRecentAuthRedirectHandler()` の戻り値を呼ぶと購読解除される (二重登録防止)
  - mock は `@inertiajs/svelte` の実 event 形状 (`{ detail: { response: { status, data } } }`) に
    合わせる。`data` を持たない形 (native Response 相当) では preventDefault しない
- [ ] Feature (施策 4 のサーバ側): recent-auth 切れの **Inertia mutation** に 409 を返すとき
      `url.intended` に same-origin referer が入り、`recent_auth.dropped_mutation` が立つ。
      その後 `/recent-auth/confirm` → password 確認成功で **元 URL へ戻り**、
      「先ほどの操作はまだ実行されていません」の info flash が 1 回だけ出る
- [ ] Feature: **純 XHR (fetch, Accept: application/json)** の 409 では `url.intended` を
      **書き換えない** (クライアントが自前で再開するため。他フローの intended を汚さない)

### リスク

- `router.on("invalid")` の event 形状は Inertia core 依存。実装時に
  `event.detail.response` (axios response 互換) を実際の型定義で確認し、
  型アサーションは `unknown` からの narrowing で書く (`any` を使わない = eslint 準拠)

---

## 施策 5: 回復導線を `RecentAuthRecoveryNotice` molecule に集約する

### 変更箇所

- `resources/js/components/molecules/RecentAuthRecoveryNotice.svelte` (新規)
- `resources/js/components/organisms/RecentAuthModal.svelte` (L204-222 を置換 = **踏破不能 CTA の除去**)
- `resources/js/pages/Auth/ConfirmRecentAuth.svelte` (L98-118 の logout / L182-209 の 2 分岐を置換)
- `tests/js/architecture/logout-call-site-inventory.test.ts` (inventory 更新)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/js/pages/ConfirmRecentAuthPasskey.test.ts`
  (`confirm-unsupported-here` → `recent-auth-unsupported-here` に統一)、
  `tests/js/pages/ConfirmRecentAuth.test.ts`、新規
  `tests/js/components/molecules/RecentAuthRecoveryNotice.test.ts`
- ドキュメント: `docs/supported-browsers.md` の経路 C 呼び出し元記述 (施策 12)

### 現行コード (RecentAuthModal — 踏破不能 CTA)

```svelte
{#if !canSatisfy}
    <div class="flex flex-col gap-2 text-caption text-text-secondary" data-testid="recent-auth-recovery">
        <p>この操作を続けるための再認証手段が設定されていません。</p>
        <Button href="/forgot-password" variant="ghost" fullWidth>
            パスワードを設定して再認証する
        </Button>
    </div>
{:else if !executableHere}
    ...
{/if}
```

`/forgot-password` は Fortify が `guest:web` 付きで登録しており、モーダルを見ているのは
**ログイン済みユーザー**なので押すと `RedirectIfAuthenticated` に無言で弾かれる。
同じ罠を `ConfirmRecentAuth.svelte:22-24` が明示的に禁じているのに、モーダル版だけ旧作法が残っていた。

### 変更後コード (新規 molecule)

```svelte
<script lang="ts">
    import { router } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";

    /**
     * 再認証 (step-up) が**この場では成立しない**ユーザーに出す回復導線。
     * 全画面 confirm (pages/Auth/ConfirmRecentAuth) とインラインモーダル
     * (organisms/RecentAuthModal) の**両方が使う唯一の実装**。
     *
     * 分けて持つと片方だけ旧作法が残る (監査 F-2a: モーダル側だけ guest 限定の
     * /forgot-password へリンクし続けていた)。
     *
     * **/forgot-password へ直接リンクしない**: Fortify が guest middleware 付きで登録しており、
     * ログイン済みの本 UI 利用者はフォームに到達できない (踏破不能 CTA。bug-hunt F-2-01 と同 species)。
     * ログアウトしてから guest として再設定する導線だけが端まで踏破できる
     * (tests/Feature/Auth/RecentAuthPasswordRecoveryTest がこの経路を端まで固定している)。
     *
     * アプリ内の「パスワードを設定」(POST /settings/password) は **recent-auth 必須**なので、
     * ここに来ているユーザー (= step-up が成立しない) には使えない。だからログアウト経路を案内する。
     *
     * 配置が molecule なのは構造的制約: 呼び出し元の RecentAuthModal は organism であり、
     * atomic-import-graph 上 organism は features 層を import できない (単方向 import)。
     *
     * ログアウトは **Inertia visit (router.post)** で行う (経路 C: Inertia history 暗号化 +
     * clearHistory の保証条件。tests/js/architecture/logout-call-site-inventory.test.ts が固定)。
     */
    interface Props {
        /**
         * - `no-satisfier`: アカウントに再認証手段が無い (canSatisfy=false)
         * - `not-executable-here`: 手段はあるがこの端末で実行できない (パスキー非対応ブラウザ)
         */
        variant: "no-satisfier" | "not-executable-here";
    }

    let { variant }: Props = $props();

    let loggingOut = $state(false);

    function logout(): void {
        if (loggingOut) return;   // 二重送信ガード
        router.post(
            "/logout",
            {},
            {
                onStart: () => {
                    loggingOut = true;
                },
                onFinish: () => {
                    loggingOut = false;
                },
            },
        );
    }

    const testId = $derived(
        variant === "no-satisfier" ? "recent-auth-recovery" : "recent-auth-unsupported-here",
    );
</script>

<div class="flex flex-col gap-3 text-caption text-text-secondary" data-testid={testId}>
    {#if variant === "no-satisfier"}
        <p>
            この操作を続けるための再認証手段が設定されていません。
            いったんログアウトし、ログイン画面の「パスワードをお忘れの方」から
            パスワードを設定すると再認証できるようになります。
        </p>
    {:else}
        <p>
            このアカウントの再認証手段はパスキーのみですが、このブラウザはパスキーに対応していません。
            パスキーを登録した端末・ブラウザで開き直すと再認証できます。
            その端末が使えない場合は、いったんログアウトし、ログイン画面の
            「パスワードをお忘れの方」からパスワードを設定してください。
        </p>
    {/if}
    <Button variant="ghost" onclick={logout} loading={loggingOut} fullWidth>ログアウトする</Button>
</div>
```

### logout inventory の更新

```ts
/**
 * `/logout` を参照してよいファイル (resources/js からの相対パス)。
 * いずれも router.post = Inertia visit
 * (AppLayout: ユーザーメニュー / VerifyEmail: メール認証待ちの離脱導線 /
 *  RecentAuthRecoveryNotice: 再認証が成立しないユーザーの回復導線。全画面 confirm と
 *  インラインモーダルの双方が本 molecule を使う)。
 */
const LOGOUT_CALL_SITE_INVENTORY: readonly string[] = [
  "components/templates/AppLayout.svelte",
  "pages/Auth/VerifyEmail.svelte",
  "components/molecules/RecentAuthRecoveryNotice.svelte",
] as const;
```

既存の第 2 不変条件 (inventory 登録ファイルに fetch/axios を持ち込まない) はそのまま維持できる
(molecule には fetch が無い。**モーダル本体に logout を書くと fetch と同居して gate に触れる**ため、
molecule 化はこの gate とも整合する)。

### テスト計画

- [ ] 新規 `tests/js/components/molecules/RecentAuthRecoveryNotice.test.ts`
  - `variant="no-satisfier"` → `recent-auth-recovery` + ログアウトボタン + **`/forgot-password` への
    リンクを含まない** (踏破不能 CTA の再発検出)
  - `variant="not-executable-here"` → `recent-auth-unsupported-here` + ログアウトボタン
  - ログアウトボタン押下で `router.post("/logout", ...)` が 1 回呼ばれる
- [ ] 既存 `tests/js/pages/ConfirmRecentAuthPasskey.test.ts` の testId 更新
- [ ] `tests/js/architecture/logout-call-site-inventory.test.ts` が green

### リスク

- `ConfirmRecentAuth` の `loggingOut` state が molecule へ移るため、
  同ページ内の他ボタンの loading 表示と独立になる (意図どおり)

---

## 施策 6: パスワード初回設定経路 (サーバ)

### 変更箇所

- `routes/web.php` (L186 付近、`/settings` の直下)
- `app/Http/Controllers/Settings/PasswordSetupController.php` (新規)
- `app/Http/Requests/Settings/SetPasswordRequest.php` (新規)
- `app/Services/Auth/PasswordCredentialService.php` (新規)
- `app/Actions/Fortify/UpdateUserPassword.php` (確定後処理を Service へ委譲)
- `app/Enums/SecurityEventType.php` (`PasswordSet` 追加)
- `tests/Architecture/RecentAuthRouteTest.php` (allowlist 追加)

### 波及変更

- TypeScript 型定義: なし (Inertia の form 送信のみ)
- API Resource/DTO: なし (成功は `back()->with('success')`、失敗は ValidationException)
- テストファイル: 新規 `tests/Feature/Settings/PasswordSetupTest.php`、
  `tests/Architecture/RecentAuthRouteTest.php`、既存
  `tests/Feature/Auth/...` のパスワード変更系 (Service 委譲後も挙動不変であることを確認)

### 変更後コード

**route** (auth + verified グループ内、課金ゲート group の外 = 認証面の route なので構造的に正しい位置):

```php
Route::get('/settings', [ProfileController::class, 'index'])->name('settings');

// パスワード**初回設定** (password 未設定ユーザー専用)。認証手段を増やす操作のため
// step-up (recent-auth) 必須。変更 (current_password 必須) は Fortify の PUT /user/password。
Route::post('/settings/password', [PasswordSetupController::class, 'store'])
    ->middleware(['recent-auth', 'throttle:6,1'])
    ->name('settings.password.store');
```

**FormRequest**:

```php
final class SetPasswordRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        // 強度の SSOT は PasswordPolicy (Password::defaults で配線済)。
        // 確認入力 (confirmed) は使わない (表示トグルで代替。UpdateUserPassword と同方針)。
        return [
            'password' => ['required', 'string', Password::default()],
        ];
    }
}
```

**Controller** (薄く / Service 委譲):

```php
final class PasswordSetupController extends Controller
{
    public function __construct(
        private readonly PasswordCredentialService $passwordCredentials,
    ) {}

    /**
     * パスワード未設定ユーザーが初めてパスワードを設定する。
     *
     * - 認証手段を**増やす**操作なので EnsureLoginMethodRemains (減らす操作の関門) は付けない。
     *   代わりに recent-auth を必須にし、セッション奪取からの永続化を防ぐ。
     * - password 設定済みの迂回は Service が fail-closed で拒否する
     *   (current_password 必須の変更経路を骨抜きにしない)。
     * - 禁止事項 7 に従い `back()->with(...)` で完結する (intended は使わない)。
     */
    public function store(SetPasswordRequest $request): RedirectResponse
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $this->passwordCredentials->setInitial($user, $request->string('password')->value());

        return back()->with('success', 'パスワードを設定しました。次回からパスワードでもログインできます。');
    }
}
```

**Service**:

```php
/**
 * users.password の確定 (設定 / 変更) の単一窓口。
 *
 * 「確定後に何が起きるか」(監査記録・他デバイス失効) を 1 箇所に集約する。
 * 2 経路 (Fortify の変更 / 初回設定) に別々に書くと、片方だけ劣化する
 * (= 他デバイスのセッションが残る等のセキュリティ後退) ため統合する。
 *
 * **transaction 境界の設計**: transaction に入れるのは
 * 「ロック取得 → 前提の再確認 → password の保存」だけ。
 * best-effort な副作用 (監査記録 / DB session 行削除) は **commit 後**に実行する。
 * PostgreSQL は transaction 内で失敗した文があると以降 aborted 状態になり、
 * アプリ側で catch しても commit できない — best-effort のつもりの副作用が
 * 主処理 (パスワード保存) を巻き添えにする。既存 UpdateUserPassword もこれらを
 * transaction 外で行っており、その性質を保つ。
 */
final class PasswordCredentialService
{
    public function __construct(
        private readonly SecurityEventRecorder $recorder,
    ) {}

    /**
     * 初回設定 (current_password 不要)。
     *
     * 呼び出し側の契約: **recent-auth (step-up) 済みであること** (route の middleware で強制)。
     * password 設定済みユーザーの迂回は fail-closed で拒否する。
     *
     * @throws ValidationException
     */
    public function setInitial(User $user, string $plain): void
    {
        // transaction は「ロック → 再確認 → 保存」だけ (副作用は commit 後)
        $saved = DB::transaction(function () use ($user, $plain): User {
            // 同時 2 リクエストで両方が「未設定」と判定するのを防ぐ (TOCTOU)。
            // ロック取得順序は User 単位 (EnsureLoginMethodRemains と同型の作法)。
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->hasPassword()) {
                throw ValidationException::withMessages([
                    'password' => 'すでにパスワードが設定されています。パスワード変更フォームから変更してください。',
                ]);
            }

            $locked->forceFill(['password' => Hash::make($plain)])->save();

            return $locked;
        });

        $this->afterPersist($saved, $plain, SecurityEventType::PasswordSet);
    }

    /**
     * 変更 (current_password の検証は Fortify 契約側 UpdateUserPassword が行う)。
     * 単一 UPDATE のため transaction は開かない (既存挙動を変えない)。
     */
    public function change(User $user, string $plain): void
    {
        $user->forceFill(['password' => Hash::make($plain)])->save();

        $this->afterPersist($user, $plain, SecurityEventType::PasswordChanged);
    }

    /**
     * 保存 **commit 後**の副作用: 監査記録 → 他デバイス失効 → DB session 行削除。
     * いずれも best-effort であり、transaction 内で実行しない (上記の PostgreSQL 事情)。
     */
    private function afterPersist(User $user, string $plain, SecurityEventType $event): void
    {
        $this->recorder->record($event, $user);   // 記録失敗は report のみ (recorder が内包)

        // 現在デバイスを維持しつつ他デバイスを失効させる (保存直後の新 password を渡す)。
        // 実失効の correctness は web グループの AuthenticateSession が担う。
        Auth::logoutOtherDevices($plain);

        $this->deleteOtherSessionRecords($user);
    }

    /** 現在の session を除き当該 user の DB session 行を削除する (driver=database 時のみ / best-effort) */
    private function deleteOtherSessionRecords(User $user): void
    {
        // 既存 UpdateUserPassword::deleteOtherSessionRecords() をそのまま移設する
        // (config('session.driver') !== 'database' / session 未開始 は早期 return)
    }
}
```

**UpdateUserPassword** (確定後処理を Service へ委譲。Validator と Fortify 契約は据え置き)。
**constructor の依存を差し替える**: `SecurityEventRecorder` → `PasswordCredentialService`
(監査記録は Service 側に移るため、この Action は recorder を直接持たない):

```php
public function __construct(
    private readonly PasswordCredentialService $passwordCredentials,
) {}

public function update(User $user, array $input): void
{
    Validator::make($input, [
        'current_password' => ['required', 'string', 'current_password:web'],
        'password' => ['required', 'string', Password::default()],
    ], [
        'current_password.current_password' => __('The provided password does not match your current password.'),
    ])->validateWithBag('updatePassword');

    // 確定後処理 (hash 保存・監査記録・他デバイス失効・session 行削除) は
    // 初回設定経路と共有する (PasswordCredentialService が単一窓口)。
    $this->passwordCredentials->change($user, $input['password']);
}
```

**SecurityEventType**:

```php
case PasswordSet = 'password_set';   // 初回設定 (PasswordSetupController が直接記録。
                                     // RecordSecurityEvent の購読対象外)
// label(): self::PasswordSet => 'パスワード設定',
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`void` / `RedirectResponse`)
- [x] null安全: `Assert::isInstanceOf($user, User::class)` で `Request::user()` の union を narrowing
- [x] DTO を返している (本 route は Inertia redirect なので Resource 不要。JSON 直書きなし)
- [x] Genericsの型パラメータ: `rules()` の `array<string, list<mixed>>` を明示
- [x] `hasPassword()` true ⇒ `password` は非 null string の narrowing は Service では不要
      (`forceFill` で書き込むのみ)

### テスト計画

- [ ] **先に fail を確認する** (テストファースト): route 未実装の状態で 404 になることを確認
- [ ] 新規 `tests/Feature/Settings/PasswordSetupTest.php` (`User::factory()->ssoOnly()` を使用。
      `Http::fake` で HIBP 照会を止める)
  - password 未設定 + recent-auth fresh → 302 back + `hasPassword()` が true になる
  - 成功時に `security_audit_events` に `password_set` が 1 件記録される
  - 成功時に他デバイスのセッションが失効する (session driver=database での行削除 / `logoutOtherDevices`)
  - **password 設定済みユーザーは 422 で拒否され、password hash が変わらない** (fail-closed)
  - recent-auth 無し + Inertia POST → **409 `recent_auth_required`** (`redirect` を含む)
  - recent-auth 無し + 非 Inertia POST → 302 `recent-auth.confirm` (`url.intended` 保持)
  - 弱いパスワード → 422 (`PasswordPolicy` 経由)
  - 未認証 → login へ redirect
  - throttle 超過 → 429
- [ ] `tests/Architecture/RecentAuthRouteTest.php` の allowlist に `settings.password.store` を追加
      (これが**付与漏れの機械的検出点**)
- [ ] 既存のパスワード変更 Feature テストが Service 委譲後も green (挙動不変の確認。
      `UpdateUserPassword` の constructor 差し替えにより **DI 解決まで通る**ことを含む)
- [ ] 監査記録の失敗が主処理を巻き込まないこと (recorder が例外を握る既存契約の維持) を
      パスワード保存が成功する形で確認する
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **transaction に best-effort な副作用を入れない**設計にしたのは、PostgreSQL では
  transaction 内で失敗した文があると以降 aborted になり、catch しても commit できないため
  (監査記録や session 行削除の失敗がパスワード保存を巻き添えにする)。
  `Auth::logoutOtherDevices()` / session 行削除 / 監査記録はすべて commit 後に実行する
- 初回設定でも他デバイスを失効させる方針のため、PWA の別端末セッションが切れる。
  変更時と同じ挙動に揃える判断 (パスワード material の確定は同じ意味を持つ)。
  ユーザー宛のメール通知はスコープ外 (監査記録は入れる)

---

## 施策 7: `/settings` のパスワードカードを `hasPassword` で出し分ける

### 変更箇所

- `app/Http/Controllers/Settings/ProfileController.php` (Inertia props)
- `resources/js/pages/Settings/Index.svelte` (L83-104 script / L196-239 テンプレート)

### 波及変更

- TypeScript 型定義: `SettingsPageProps` に `hasPassword?: boolean` を追加
- API Resource/DTO: なし (Inertia prop)
- テストファイル: `tests/js/pages/SettingsIndex.test.ts` の**パスワード変更系ケース全件**に
  `hasPassword: true` を渡す変更が必要 (L307-500 付近の 2 describe)。
  加えて `hasPassword: false` の新規 describe を追加

### 現行コード

```php
return Inertia::render('Settings/Index', [
    'soleOwnedOrganizations' => ...,
]);
```

```svelte
<Card padding="lg">
    <h2 class="text-h3">パスワード変更</h2>
    ...
    <FormField label="現在のパスワード" id="current-password" ...>
```

→ password 未設定ユーザーにも `current_password` 必須フォームが出て**必ず失敗する**
(カード丸ごとが踏破不能 = F-2 と同 species)。

### 変更後コード

```php
return Inertia::render('Settings/Index', [
    'soleOwnedOrganizations' => ...,
    // パスワードカードの出し分け。password 未設定ユーザーに current_password 必須の
    // 変更フォームを出すと必ず失敗する (踏破不能 UI) ため、初回設定フォームへ切り替える。
    'hasPassword' => $user->hasPassword(),
]);
```

```svelte
/**
 * prop 欠落 (= 状態不明) を false に倒すと、password 設定済みユーザーに初回設定フォームを出す
 * = 本批で潰している「状態不明を誤った UI に倒す」の再演になる。3 値で扱う。
 */
const passwordState = $derived.by((): "set" | "unset" | "unknown" =>
    typeof props.hasPassword === "boolean" ? (props.hasPassword ? "set" : "unset") : "unknown",
);

const passwordSetupForm = useForm({ password: "" });

function submitPasswordSetup(event: SubmitEvent): void {
    event.preventDefault();
    passwordSetupForm.clearErrors("password");
    // 初回設定は recent-auth 必須 (サーバ側 middleware が最終ゲート)。
    // stale なら再認証モーダルを挟んで再開する (他の機微操作と同じ precheck)。
    guardWithRecentAuth(() => {
        passwordSetupForm.post("/settings/password", {
            preserveScroll: true,
            onSuccess: () => {
                passwordSetupForm.reset();
            },
        });
    });
}
```

```svelte
<Card padding="lg">
    {#if passwordState === "unknown"}
        <h2 class="text-h3">パスワード</h2>
        <Alert type="warning" testId="password-state-unknown">
            パスワードの設定状態を取得できませんでした。ページを再読み込みしてお試しください。
            {#snippet action()}
                <Button variant="ghost" onclick={() => router.reload()}>再読み込み</Button>
            {/snippet}
        </Alert>
    {:else if passwordState === "set"}
        <h2 class="text-h3">パスワード変更</h2>
        <p class="mt-1 text-caption text-text-secondary">
            現在のパスワードを確認のうえ、新しいパスワードに変更します。
        </p>
        <!-- 既存の変更フォーム (無変更) -->
    {:else}
        <h2 class="text-h3">パスワードを設定</h2>
        <p class="mt-1 text-caption text-text-secondary">
            現在はパスキーまたはソーシャルログインでご利用中です。パスワードを設定すると、
            パスワードでもログインできるようになります (既存のログイン手段はそのまま使えます)。
        </p>
        <form novalidate onsubmit={submitPasswordSetup} class="mt-4 flex flex-col gap-4">
            <FormField label="新しいパスワード" id="new-password" error={passwordSetupForm.errors.password}>
                {#snippet children({ id, describedBy, invalid })}
                    <PasswordInput
                        {id}
                        bind:value={passwordSetupForm.password}
                        error={invalid}
                        aria-describedby={describedBy}
                        autocomplete="new-password"
                    />
                {/snippet}
            </FormField>
            <div>
                <Button type="submit" loading={passwordSetupForm.processing} testId="set-password-button">
                    {passwordSetupForm.processing ? "設定中…" : "パスワードを設定"}
                </Button>
            </div>
        </form>
    {/if}
</Card>
```

- ボタンは**常時活性** (禁止事項 8)。強度不足・step-up 未成立は押下後にサーバのエラーで示す
- 成功時はサーバの `back()->with('success')` が flash → toast (client 楽観 toast を出さない)。
  再描画で `hasPassword=true` になり、カードは変更フォームへ切り替わる (状態と UI が一致する)

### PHPStan適合チェック

- [x] `ProfileController::index` は `Assert::isInstanceOf($user, User::class)` 済み (既存)
- [x] 戻り値型 `Inertia\Response` は既存のまま

### テスト計画

- [ ] `tests/js/pages/SettingsIndex.test.ts`
  - 既存のパスワード変更系ケースに `hasPassword: true` を付与 (現状維持の確認)
  - 新規: `hasPassword: false` なら「現在のパスワード」欄が**描画されない**
  - 新規: `hasPassword` prop 欠落 (状態不明) では**どちらのフォームも出さず**
    再読み込み導線を出す (誤った UI に倒さない)
  - 新規: `hasPassword: false` で送信すると **stale 時は post せず再認証モーダルが開く**、
    fresh 時に `/settings/password` へ 1 回だけ post する (既存の precheck テストと同型)
  - 新規: 設定ボタンは常に活性 (disabled 不使用)
- [ ] `tests/Feature/Settings/PasswordSetupTest.php` に「`/settings` の Inertia prop に
      `hasPassword` が載る」ケースを追加 (server 側の出し分け根拠)

### リスク

- 既存の SettingsIndex テストが広範囲に修正対象になる (props 追加漏れで fail)。
  fail は「表示条件が変わった」ことの正しい検出であり、上書き削除はしない (禁止事項 3)

---

## 施策 8: `settingsUrl` の削除と `PasskeySection` CTA の踏破可能化

### 変更箇所

- `app/DataTransferObjects/Auth/LoginMethodRequiredDto.php` (L23 `settingsUrl` 削除)
- `app/Http/Resources/Auth/LoginMethodRequiredResource.php` (shape から削除)
- `app/Http/Middleware/EnsureLoginMethodRemains.php` (L116-119)
- `resources/js/components/features/auth/PasskeySection.svelte` (L161-172)

### 波及変更

- TypeScript 型定義: なし (消費者が存在しない)
- API Resource/DTO: `LoginMethodRequiredResource` の array shape PHPDoc を
  `array{code: 'login_method_required', message: string}` に更新
- テストファイル: `tests/Feature/Auth/LoginMethodRetentionTest.php:78` の
  `assertJsonPath('settingsUrl', ...)` を削除

### 現行コード

```php
$dto = new LoginMethodRequiredDto(
    message: '... 先に別のログイン手段（パスワードの設定、ソーシャル連携、他のパスキー）を追加してください。',
    settingsUrl: route('settings.security'),
);
```

```svelte
<Alert type="danger" title="削除できません" testId="passkey-login-method-error">
    {loginMethodError}
    {#snippet action()}
        <div class="flex flex-wrap gap-3">
            <Button variant="ghost" href="/settings" testId="passkey-add-password">
                パスワードを設定する
            </Button>
        </div>
    {/snippet}
</Alert>
```

### 変更後コード

```php
// settingsUrl は削除 (message のみ)。理由:
// - Inertia 経路は back()->withErrors() で message しか運ばず、URL はどのクライアントも消費していない
// - 指していた settings.security にはパスワード設定 UI が無く、フロントの遷移先 (/settings) とも
//   食い違っていた (phantom 契約)
$dto = new LoginMethodRequiredDto(
    message: 'この操作を行うと、ログインする手段がなくなります。先に別のログイン手段（パスワードの設定、ソーシャル連携、他のパスキー）を追加してください。',
);
```

```svelte
<Alert type="danger" title="削除できません" testId="passkey-login-method-error">
    {loginMethodError}
    このページの「ソーシャルログイン連携」から外部アカウントを連携するか、
    下のフォームから別のパスキーを登録することもできます。
    {#snippet action()}
        <div class="flex flex-wrap gap-3">
            <!--
              遷移先 /settings は password 未設定ユーザーには「パスワードを設定」フォームを出す
              (施策 7)。この Alert が出るのは「削除するとログイン手段が 0 になる」= password を
              持たないユーザーだけなので (LoginMethodInventory の投影評価)、CTA は必ず踏破可能。
            -->
            <Button variant="ghost" href="/settings" testId="passkey-add-password">
                パスワードを設定する
            </Button>
        </div>
    {/snippet}
</Alert>
```

**設計判断**: `hasPassword` を `PasskeySection` に prop で渡して CTA を出し分ける案は採らない。
この Alert が出るのは投影評価で残存手段が 0 のときだけであり、password を持つユーザーには
構造的に発生しない (= 今必要ない分岐。思考原則 2)。不変条件は Feature テストで固定する。

### PHPStan適合チェック

- [x] DTO の promoted property 削除に伴い、Resource の `@return array{...}` を同時更新
      (widen ではなく縮小)
- [x] `LoginMethodRequiredDto` の他の利用箇所が無いことを確認してから削除する

### テスト計画

- [ ] `tests/Feature/Auth/LoginMethodRetentionTest.php`
  - `settingsUrl` の断言を削除し、**422 ボディのキー集合が `code` / `message` に一致**することを固定する
    (`assertExactJson` 相当。phantom contract の再追加を機械的に防ぐ)
  - **不変条件の固定**: password を持つユーザーは最後のパスキーを削除できる
    (= `login_method` 拒否は password 未設定ユーザーにのみ起きる)。
    既存に無ければ追加する — 施策 8 の CTA 踏破可能性の根拠テスト
- [ ] `tests/js/pages/SettingsSecurityPasskey.test.ts` の
      「ログイン手段保持 guard の拒否メッセージを画面に出す」ケースに
      CTA (`passkey-add-password`) の href が `/settings` であることを追加

### リスク

- 422 JSON の shape 変更。消費者ゼロを `grep` で確認済みだが、実装時にもう一度
  `grep -rn "settingsUrl" .` (vendor 除く) で確認する

---

## 施策 9: WebAuthn ceremony 失敗の提示を Alert に統一する (F-3)

### 変更箇所

- `DESIGN.md` §Alert / §FormError に規約追記
- `resources/js/components/features/auth/PasskeySection.svelte` (L62-110: toast → Alert)
- `resources/js/components/organisms/RecentAuthModal.svelte` (L62-82, L150, L168: error state 分離)
- `resources/js/pages/Auth/ConfirmRecentAuth.svelte` (L154-156: FormError → Alert)

### 波及変更

- TypeScript 型定義: なし
- テストファイル: `tests/js/pages/SettingsSecurityPasskey.test.ts` (toast 断言 → Alert 断言)、
  `tests/js/pages/ConfirmRecentAuthPasskey.test.ts` (`confirm-passkey-error` の描画要素)

### 規約 (DESIGN.md に追記する文)

> **非フィールド起因の操作失敗は Alert**。フォームのフィールドに紐づかない失敗
> (WebAuthn ceremony 失敗・端末非対応・ネットワーク失敗など) は、
> 操作したその場に残る Alert で出す。FormError は**フィールド単位**のエラー専用であり、
> Toast は「一時通知」なので、押した直後に読ませたい失敗理由を画面外 (上部中央) へ飛ばさない。

### 現行コードの具体的欠陥 (RecentAuthModal)

```svelte
let error = $state("");
// submitPasskey() も submitPassword() も同じ error に書く
```

→ **パスキー ceremony の失敗が「現在のパスワード」欄のフィールドエラーとして表示される**
(フィールド起因でない失敗を FormField に流している)。

### 変更後コード (RecentAuthModal 抜粋)

```svelte
let passwordError = $state("");   // FormField (フィールド起因)
let passkeyError = $state("");    // Alert (非フィールド起因)
```

```svelte
{#if passkeyError}
    <Alert type="danger" testId="recent-auth-passkey-error">{passkeyError}</Alert>
{/if}
```

`PasskeySection` は `addToast("error", ...)` を廃し、カード上部に
`operationError` の Alert (`testId="passkey-operation-error"`) を出す。
`Login.svelte` は既に Alert (`passkey-login-error`) で準拠済み = **変更なし**。

### テスト計画

- [ ] `PasskeySection`: 非対応ブラウザで登録押下 → **Alert** に理由が出る (toast ではない)
- [ ] `PasskeySection`: ceremony 失敗 → Alert に理由が出て POST しない
- [ ] `RecentAuthModal`: passkey ceremony 失敗が **password フィールドのエラーとして出ない**
      (`recent-auth-password-input` の `aria-invalid` が立たない) ことを固定
- [ ] `ConfirmRecentAuth`: ceremony 失敗が Alert で出る

### リスク

- toast 断言を持つ既存テストが落ちる (意図した変更)。上書き削除ではなく断言先を変更する

---

## 施策 10: `PasskeySection` の `nameError` を `$derived` canonical 形にする (F-4)

### 変更箇所

- `resources/js/components/features/auth/PasskeySection.svelte` (L58-77, L222-234)

### 現行コード

```ts
let nameError = $state("");
...
if (name === "") {
    nameError = "パスキーの名前を入力してください。";
    return;
}
nameError = "";
```

→ 押下時に代入するだけで、その後の入力で消えない (DESIGN.md §FormField の canonical 不変条件違反)。

### 変更後コード

```ts
/**
 * DESIGN.md §FormField: 押下時に出した client エラーは入力に追随させる
 * (stale invalid を残さない)。新規は「提示開始 boolean + $derived 文言」で書く。
 */
let nameErrorShown = $state(false);
/** サーバ由来 (422) のエラーは入力で消さない (DESIGN.md の例外規定) */
let serverNameError = $state<string | null>(null);

const trimmedName = $derived(newPasskeyName.trim());
const clientNameError = $derived(
    nameErrorShown && trimmedName === "" ? "パスキーの名前を入力してください。" : "",
);
const nameError = $derived(serverNameError ?? clientNameError);
```

```ts
function registerPasskey(): void {
    ...
    serverNameError = null;      // 送信のたびに前回のサーバエラーを落とす
    nameErrorShown = true;
    if (trimmedName === "") return;   // $derived が文言を出す
    ...
}
```

### テスト計画

- [ ] 名前を空で押下 → エラー文言が出る
- [ ] その後 1 文字入力 → **エラー文言が消える** (入力追随。現行は残る = 回帰テスト)
- [ ] 再び空にする → 文言が戻る
- [ ] サーバ 422 の `errors.name` は入力しても消えない (規定どおり)

### リスク

- なし (局所)。実装が膨らむ場合は本施策のみ次サイクルへ切り離せる

---

## 施策 11: `PasskeySection` 登録フローの整理 (F-7)

### 変更箇所

- `resources/js/components/features/auth/PasskeySection.svelte` (L40-50 Props, L62-111, L161-172)
- `resources/js/pages/Settings/Security.svelte` (`guardWithRecentAuth` の戻り値を返す形に変更)

### 波及変更

- TypeScript 型定義: `PasskeySection` の `guard` prop 型を
  `(action: () => void) => void` → **`(action: () => void) => Promise<"fresh" | "stale" | "delegated">`**
  へ変更する (`withRecentAuth` の戻り値をそのまま流す)。
  `Security.svelte` の `guardWithRecentAuth` は `void withRecentAuth(...)` を
  `return withRecentAuth(...)` に変え、他の呼び出し側 (2FA / リカバリコード等) は
  `void guardWithRecentAuth(...)` で明示的に破棄する
- テストファイル: `tests/js/pages/SettingsSecurityPasskey.test.ts` の guard mock を Promise 返却に更新

### 現行コードの欠陥

```ts
router.post("/user/passkeys", { name, credential: outcome.value }, {
    onError: () => { addToast("error", "パスキーの登録に失敗しました。"); },
});
} finally {
    registering = false;   // ← router.post を await していないため ceremony 直後に解除される
}
```

- `registering` が POST 完了前に false になり、連打で ceremony が多重に走る
  (同ファイルの削除側 L128-135 は `onStart`/`onFinish` で正しく握っている)
- `onError` がサーバ validation を汎用 toast に潰すため、`FormField error` にサーバ由来の
  エラーが**決して出ない**

### 変更後コード

```ts
router.post(
    "/user/passkeys",
    { name: trimmedName, credential: outcome.value },
    {
        preserveScroll: true,
        // 削除側と同じ作法で processing を握る (ceremony 直後に解除しない = 連打で多重に走らない)
        onStart: () => { registering = true; },
        onFinish: () => { registering = false; },
        onSuccess: () => { newPasskeyName = ""; nameErrorShown = false; },
        onError: (errors) => {
            // フィールド起因は FormField へ、それ以外は Alert へ (施策 9 の規約)
            serverNameError = typeof errors.name === "string" ? errors.name : null;
            if (serverNameError === null) {
                operationError = "パスキーの登録に失敗しました。時間をおいて再度お試しください。";
            }
        },
    },
);
```

ceremony 中の loading は ceremony 開始時に `registering = true`、
ceremony が cancelled / unsupported / failed で終わったときだけ false に戻す
(`finally` で一律解除しない)。

**precheck 中の連打も塞ぐ**。現行は `guard()` の中 (= `/recent-auth/status` の fetch 待ち) が
無防備で、その間に連打すると ceremony が多重起動し pending action も上書きされる。
`guard` の戻り値 (`withRecentAuth` の分岐結果) を待てるようにして、precheck 区間を
別 state で覆う:

```ts
/** precheck (/recent-auth/status) 実行中。ceremony/POST 中は registering が覆う */
let prechecking = $state(false);
const busy = $derived(prechecking || registering);

async function registerPasskey(): Promise<void> {
    if (busy) return;
    // 非対応端末でも押下できる (disabled にしない)。押した結果として理由を出す。
    if (!supported) {
        operationError = "このブラウザはパスキーに対応していません。パスワードまたはソーシャルログインをご利用ください。";
        return;
    }
    nameErrorShown = true;
    serverNameError = null;
    if (trimmedName === "") return;   // 文言は $derived が出す

    prechecking = true;
    try {
        // fresh なら guard の中で action (ceremony → POST) が走り、registering が引き継ぐ。
        // stale / delegated ならモーダル側へ委譲されるので、ここで precheck を閉じてよい。
        await guard(startCeremonyAndPost);
    } finally {
        prechecking = false;
    }
}
```

ボタンは `loading={busy}` とする (常時活性のまま。禁止事項 8 は維持)。
stale で再認証モーダルへ委譲された後の再開は、モーダルの `onConfirmed` →
ページの `resumePendingAction` → `startCeremonyAndPost` の経路で `registering` が握る
(モーダルをキャンセルした場合、`prechecking` は既に false なのでボタンは活性のまま = 詰まない)。

拒否 Alert (`loginMethodError`) 表示時のフォーカス移動は、
`Security.svelte:252-254` のリカバリコード panel と同じ作法
(`tabindex="-1"` + `bind:this` + `tick()` 後に `focus()`) で実装する。

### テスト計画

- [ ] ceremony 成功 → POST 中は `register-passkey-button` が loading のまま
      (`onFinish` まで解除されない)
- [ ] 連打しても ceremony が 1 回しか開始しない
- [ ] **precheck (`/recent-auth/status`) の解決を遅延させる guard mock** を使い、
      その間に複数回クリックしても ceremony 開始と pending action が 1 つだけであることを固定する
- [ ] stale で再認証モーダルへ委譲された後にモーダルをキャンセルしても、
      登録ボタンが loading のまま固まらない (詰まない)
- [ ] サーバ 422 (`errors.name`) が **FormField** に出る (汎用 toast に潰れない)
- [ ] `loginMethodError` が新規に現れたとき Alert にフォーカスが移る

### リスク

- Inertia の `onError` 型 (`Record<string, string>`) の narrowing を型安全に書く必要あり
  (`any` 不可 / inline eslint-disable 不可)

---

## 施策 12: ドキュメント更新

### 変更箇所

- `DESIGN.md`
  - §RecentAuthModal: props 契約 (`status` 1 本 / 回復導線は molecule) を反映
  - §molecules に **RecentAuthRecoveryNotice** を追記 (責務・logout 契約・molecule 配置の理由)
  - §Alert / §FormError: 「非フィールド起因の操作失敗は Alert」を規約として明文化 (施策 9)
- `docs/supported-browsers.md`
  - 経路 C の logout 呼び出し元 3 件の記述を
    `pages/Auth/ConfirmRecentAuth.svelte` → `components/molecules/RecentAuthRecoveryNotice.svelte` へ更新

### 波及変更

- `tests/js/architecture/canonical-source-parity.test.ts` は DESIGN.md frontmatter の token 同期を
  見るテストであり、本変更は**散文の追記のみ**で token に触れないため影響なし
  (実装時に念のため実行して確認する)

### テスト計画

- [ ] `pnpm test tests/js/architecture tests/js/styles` が all green (117 tests + 本批の新規分。
      グローバルテストロック経由で実行する)

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** (1 worktree / 1 ブランチで施策 1〜12 を通す) |
| 判断根拠 | 施策 1・5・9・10・11 は同一ファイル (`RecentAuthModal.svelte` / `PasskeySection.svelte`) を重ねて書き換えるため分割すると conflict が確定する。施策 6・7・8 も「CTA の遷移先が踏破可能になる」ことで初めて整合するため、途中状態で main にマージすると**踏破不能 CTA が残った状態が一時的に出荷される**。施策 3 と 4 も片方だけでは delegated の着地が閉じない |
| 競合リスク | 他タスクが `Settings/Index.svelte` / `Settings/Security.svelte` を触ると conflict。着手前に `docs/TODO.md` の Open タスクを確認する |

### 実装順序 (テストファースト)

1. 施策 2 の gate を先に置き、**5 画面未配線で fail することを確認**する
2. 施策 1 (契約統合 + 6 画面配線) → gate green
3. 施策 3 → 施策 4 (strict parse と delegated 着地はセットで閉じる)
4. 施策 5 (molecule 抽出 + logout inventory 更新)
5. 施策 6 (サーバ) → 施策 7 (画面) → 施策 8 (CTA と DTO) の順。
   **施策 6 が入る前に施策 8 の CTA を有効化しない** (踏破不能な期間を作らない)
6. 施策 9 → 10 → 11 (`PasskeySection` の書き換えを 1 回にまとめる)
7. 施策 12 (ドキュメント) → 全 gate 実行

### 完了条件

- `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
  `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` が全 green
- 新規 gate 2 本 (recent-auth modal call site inventory / recent-auth contract) が green
- `resources/js` の hex 直書き 0 件を維持 (`ds-purity` / `contrast-invariant` 等 117 tests green)

