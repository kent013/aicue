## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
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

---

# あなたの役割

Laravel 12 + Svelte 5 (runes) + Inertia.js + TypeScript のアプリに対する**実装レビュアー**。
以下の詳細設計書に基づく実装差分をレビューし、設計との一致性・正確性・安全性を判定せよ。

## レビュー観点

1. **設計との一致性**: 詳細設計書の全 12 施策が実装されているか。意図的逸脱があるなら妥当か
2. **正確性**: ロジックの誤り・競合状態・境界条件の取りこぼし
3. **PHPStan level 10 適合性**: 型の widen / baseline 化が無いか (禁止事項 2)
4. **DTO / JsonResource パターン**: `response()->json()` 直書きが無いか (禁止事項 4)
5. **テスト網羅性**: 各施策に対応するテストがあるか。**不変条件が gate (Architecture テスト) に登録されているか**
6. **セキュリティ**: step-up (recent-auth) の強制、fail-closed、open redirect、TOCTOU、mass-assignment
7. **DESIGN.md 準拠**: color / radius / typography は token 経由。hex 直書き (`#RRGGBB`) を増やしていないか
8. **Atomic Design 準拠**: `atoms/molecules/organisms/features/templates/pages` の単方向 import。
   atom は単機能・状態を持たない、molecule は atom の組合せ。階層の逆流が無いか。
   アイコンは Lucide を使い SVG 直書きを増やしていないか

## 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
  - Critical: 修正しないとマージすべきでない (誤り・セキュリティ・設計違反・禁止事項違反)
  - Warning: 検討が必要 (品質・保守性・テスト不足)
  - Suggestion: 任意
- 最後に **全体判定: APPROVED / CHANGES_REQUESTED** を明記する
- 憶測で「〜かもしれない」と書かず、diff の該当行を引用して根拠を示すこと

---

## 詳細設計書

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
     * transaction 内では実行しない (上記の PostgreSQL 事情)。
     * best-effort なのは **監査記録と DB session 行削除**の 2 つ (どちらも内部で例外を握る)。
     * `Auth::logoutOtherDevices()` は例外を捕捉しない (失敗は 500 として表面化させる。
     * 他デバイス失効は correctness 側の要求であり、既存 UpdateUserPassword の挙動を維持する)。
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
case PasswordSet = 'password_set';   // 初回設定 (PasswordCredentialService が直接記録。
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
// 名前は **押下時点の値をキャプチャ**して pending action へ渡す (再認証モーダルを挟む間に
// 入力欄が編集されても、ユーザーが押したときに見えていた名前で登録する)。
router.post(
    "/user/passkeys",
    { name: capturedName, credential: outcome.value },
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

    // 押下時点の名前を確定させる (再開時に入力欄が変わっていても揺れない)
    const capturedName = trimmedName;

    prechecking = true;
    try {
        // fresh なら guard の中で action (ceremony → POST) が走り、registering が引き継ぐ。
        // stale / delegated ならモーダル側へ委譲されるので、ここで precheck を閉じてよい。
        await guard(() => startCeremonyAndPost(capturedName));
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

### 使命・禁止事項の最終確認

| 項目 | 確認 |
|---|---|
| 使命への寄与 | 撮影 PWA の主戦場 (スマホ + パスキー / SSO) のユーザーが機微操作で詰む経路を消し、「思考ゼロ」の前提を守る |
| 禁止事項 1 (テストなしの完了) | 全施策に Feature / Architecture / JS テストを対応付け、不変条件は gate (call-site inventory / recent-auth route allowlist / contract テスト) へ登録する |
| 禁止事項 2 (PHPStan widen) | 型の縮小 (DTO の field 削除) のみ。widen / baseline 化なし |
| 禁止事項 3 (dev DB 破壊操作) | なし (migration も追加しない。`SecurityEventType` は string 列への値追加) |
| 禁止事項 4 (`response()->json()` 直書き) | 新規 endpoint の成功は Inertia redirect、拒否は ValidationException。JSON 応答は既存 Resource のみ |
| 禁止事項 7 (操作系 POST の `redirect()->intended()`) | `PasswordSetupController` は `back()->with(...)` で完結。施策 4 で触るのは **`RequireRecentAuth` が step-up 着地のために保存する `url.intended`** であり、消費するのは既存の confirm 経路 (`ConfirmRecentAuthController::confirmPassword`)。操作系 POST の応答に `intended()` を新設しない |
| 禁止事項 8 (必須条件未充足の disabled) | 新設ボタン (パスワード設定 / 再読み込み / ログアウト) はすべて常時活性。loading は処理中の多重送信ガードのみ |
| 禁止事項 9 (Artifact) | 成果物はリポジトリ内ファイルのみ |
| DESIGN.md / Atomic Design | 新規 molecule は atoms のみを composition。organism → molecule の単方向 import を維持。token 逸脱なし (hex 直書きを増やさない) |

### 完了条件

- `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
  `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` が全 green
- 新規 gate 2 本 (recent-auth modal call site inventory / recent-auth contract) が green
- `resources/js` の hex 直書き 0 件を維持 (`ds-purity` / `contrast-invariant` 等 117 tests green)


---

## 実装差分 (git diff)

```diff
diff --git a/DESIGN.md b/DESIGN.md
index 4f671fd..12b6cd8 100644
--- a/DESIGN.md
+++ b/DESIGN.md
@@ -225,6 +225,9 @@ ### FormError
 (`text-caption text-danger`。message が無ければ何も描画しない)。FormField / Checkbox から
 composition される前提の最小 atom。単体で使う場合、`aria-describedby` の配線は呼び出し側の
 責務。ページ常在の通知は Alert、一時通知は Toast を使う。
+**フィールドに紐づかない失敗(ceremony 失敗・端末非対応等)を FormError に流さない**
+(原因と提示先が食い違い、「パスキー失敗がパスワード欄の赤字として出る」species のバグになる)。
+非フィールド起因は Alert(§Alert)。
 
 ### Avatar
 
@@ -322,6 +325,10 @@ ### Alert
   テーマ色を面塗りに使わない。中間 box なので `rounded-md`
 - `action` snippet(本文下の CTA)、`dismissible` + `onDismiss`(右上の X)を持つ
 - a11y: **danger のみ `role="alert"`(assertive)**、他は `role="status"`(polite)
+- **非フィールド起因の操作失敗は Alert**。フォームのフィールドに紐づかない失敗
+  (WebAuthn ceremony 失敗・端末非対応・ネットワーク失敗など)は、操作したその場に残る
+  Alert で出す。FormError は**フィールド単位**のエラー専用であり、Toast は「一時通知」なので、
+  押した直後に読ませたい失敗理由を画面外(上部中央)へ飛ばさない
 
 ### FormField
 
@@ -458,9 +465,36 @@ ### RecentAuthModal
 実装: `components/organisms/RecentAuthModal.svelte`(Modal の composition)。機微操作
 (API キー発行/失効・アカウント削除・オーナー移譲)の前に出す**同一画面の再認証(step-up)
 モーダル**。パスワード設定済みは再入力 → `POST /recent-auth/password`(成功は XHR 204)、
-再 SSO 可能な provider は `reauthUrl` へフルリダイレクト、再認証手段なし(`canSatisfy=false`)は
-回復導線(パスワード設定)を案内する。認可の最終ゲートは各操作の recent-auth middleware で、
-本モーダルは UX 補助。
+再 SSO 可能な provider は `reauthUrl` へフルリダイレクト、パスキー登録済みは WebAuthn 検証。
+認可の最終ゲートは各操作の recent-auth middleware で、本モーダルは UX 補助。
+
+- **props 契約は `status: RecentAuthStatus | null` の 1 本**(`bind:open` / `onConfirmed` を除く)。
+  `/recent-auth/status` の応答を field へ分解して手渡さない — field が増えるたびに配線漏れが
+  生まれる(T106 で `passkeyAvailable` を足した際、6 呼び出し中 5 箇所が未配線のまま出荷され
+  passkey-only ユーザーが 5 画面で詰んだ)。`tsc --noEmit` は `.svelte` テンプレートを型検査
+  しないため、強制点は `tests/js/architecture/recent-auth-modal-call-site-inventory.test.ts`
+  (deny-by-default。`status={recentAuthStatus}` の識別子・旧 prop 不在・`onStale` での代入まで検査)
+- `status === null` は**状態不明**として扱い、空表示や事実に反する文言を出さず再読み込み導線を出す
+- 再認証が成立しないユーザー(`canSatisfy=false` / この端末で実行不能)への回復導線は
+  **`molecules/RecentAuthRecoveryNotice` に集約**する(下記)
+
+### RecentAuthRecoveryNotice
+
+実装: `components/molecules/RecentAuthRecoveryNotice.svelte`。再認証(step-up)が**この場では
+成立しない**ユーザーに出す回復導線。全画面 confirm(`pages/Auth/ConfirmRecentAuth`)と
+インラインモーダル(`organisms/RecentAuthModal`)の**両方が使う唯一の実装**(分けて持つと
+片方だけ旧作法が残る)。
+
+- `variant`: `no-satisfier`(アカウントに手段が無い)/ `not-executable-here`(手段はあるが
+  この端末で実行できない = パスキー非対応ブラウザ)
+- **`/forgot-password` へ直接リンクしない**。Fortify が `guest` middleware 付きで登録しており
+  ログイン済みの本 UI 利用者はフォームに到達できない(踏破不能 CTA)。案内するのは
+  「ログアウト → guest としてパスワード再設定」の経路だけ。アプリ内の初回設定
+  (`POST /settings/password`)は recent-auth 必須なので、ここに来ているユーザーには使えない
+- ログアウトは **Inertia visit(`router.post`)**(経路 C の保証条件。
+  `tests/js/architecture/logout-call-site-inventory.test.ts` が inventory で固定)
+- molecule 配置は構造的制約: 呼び出し元の RecentAuthModal は organism であり、
+  atomic-import-graph 上 organism は features 層を import できない
 
 ## Do's and Don'ts
 
diff --git a/app/Actions/Fortify/UpdateUserPassword.php b/app/Actions/Fortify/UpdateUserPassword.php
index b364a2a..4ddffb7 100644
--- a/app/Actions/Fortify/UpdateUserPassword.php
+++ b/app/Actions/Fortify/UpdateUserPassword.php
@@ -4,23 +4,17 @@
 
 namespace App\Actions\Fortify;
 
-use App\Enums\SecurityEventType;
 use App\Models\User;
-use App\Services\Security\SecurityEventRecorder;
-use Illuminate\Support\Facades\Auth;
-use Illuminate\Support\Facades\DB;
-use Illuminate\Support\Facades\Hash;
+use App\Services\Auth\PasswordCredentialService;
 use Illuminate\Support\Facades\Validator;
 use Illuminate\Validation\Rules\Password;
 use Illuminate\Validation\ValidationException;
 use Laravel\Fortify\Contracts\UpdatesUserPasswords;
-use Throwable;
-use Webmozart\Assert\Assert;
 
 class UpdateUserPassword implements UpdatesUserPasswords
 {
     public function __construct(
-        private readonly SecurityEventRecorder $recorder,
+        private readonly PasswordCredentialService $passwordCredentials,
     ) {}
 
     /**
@@ -42,63 +36,10 @@ public function update(User $user, array $input): void
             'current_password.current_password' => __('The provided password does not match your current password.'),
         ])->validateWithBag('updatePassword');
 
-        // 新パスワードを確定 (この後の logoutOtherDevices は保存済みハッシュに対し Hash::check する)。
-        $user->forceFill([
-            'password' => Hash::make($input['password']),
-        ])->save();
-
-        // 「そのユーザーが自分でパスワードを設定したか」の監査証跡。
-        // SecurityEventType::PasswordChanged は enum に存在しながら記録経路が無かった
-        // (/reset-password 経路は Illuminate の PasswordReset イベント → RecordSecurityEvent が
-        //  既に購読済みのため本 Action だけが欠けていた)。
-        // 将来、前方修正前に作られた legacy SSO ユーザーの phantom password
-        // (docs/template-divergence.md D13) を判別する材料にもなる。
-        // Fortify の PasswordUpdatedViaController ではなく Action 直記録にするのは、
-        // vendor イベントの意味論 (「Fortify の Controller 経由」) に依存しないため。
-        $this->recorder->record(SecurityEventType::PasswordChanged, $user);
-
-        // 現在デバイスを維持しつつ他デバイスを失効させる。logoutOtherDevices は password を
-        // 再ハッシュし、現在デバイスの recaller (remember-me) を新ハッシュで再発行 (現在リクエストが
-        // recaller を持つ場合のみ) + OtherDeviceLogout イベントを発火する。他デバイスの実失効は
-        // web グループの AuthenticateSession による password_hash 照合が担保する (correctness の要)。
-        // 渡すのは current_password ではなく保存直後の新 password。
-        Auth::logoutOtherDevices($input['password']);
-
-        // database driver の場合、当該 user の他 session 行を即時削除する (best-effort)。
-        $this->deleteOtherSessionRecords($user);
-    }
-
-    /**
-     * 現在の session を除き、当該 user の DB session 行を削除する (session driver=database 時のみ)。
-     *
-     * correctness は AuthenticateSession が担うため best-effort: 失敗しても report して継続する
-     * (パスワード変更自体は成功しているため正常応答を維持する)。
-     */
-    private function deleteOtherSessionRecords(User $user): void
-    {
-        if (config('session.driver') !== 'database') {
-            return;
-        }
-
-        // session 未初期化文脈 (console/queue 等) では現在ID除外の前提が崩れるため何もしない。
-        if (! session()->isStarted()) {
-            return;
-        }
-
-        $connection = config('session.connection');
-        $table = config('session.table', 'sessions');
-
-        Assert::nullOrString($connection);
-        Assert::string($table);
-
-        try {
-            DB::connection($connection)
-                ->table($table)
-                ->where('user_id', $user->getAuthIdentifier())
-                ->where('id', '!=', session()->getId())
-                ->delete();
-        } catch (Throwable $e) {
-            report($e);
-        }
+        // 確定後処理 (hash 保存・監査記録・他デバイス失効・session 行削除) は
+        // 初回設定経路 (PasswordSetupController) と共有する
+        // (PasswordCredentialService が users.password 確定の単一窓口)。
+        // 片方だけに副作用を書くと、もう片方が黙って劣化する (他デバイスが残る等)。
+        $this->passwordCredentials->change($user, $input['password']);
     }
 }
diff --git a/app/DataTransferObjects/Auth/LoginMethodRequiredDto.php b/app/DataTransferObjects/Auth/LoginMethodRequiredDto.php
index 193eb39..584593a 100644
--- a/app/DataTransferObjects/Auth/LoginMethodRequiredDto.php
+++ b/app/DataTransferObjects/Auth/LoginMethodRequiredDto.php
@@ -20,6 +20,5 @@
 
     public function __construct(
         public string $message,
-        public string $settingsUrl,
     ) {}
 }
diff --git a/app/Enums/SecurityEventType.php b/app/Enums/SecurityEventType.php
index adbe8fa..7cae850 100644
--- a/app/Enums/SecurityEventType.php
+++ b/app/Enums/SecurityEventType.php
@@ -15,6 +15,8 @@ enum SecurityEventType: string
     case Logout = 'logout';
     case PasswordReset = 'password_reset';
     case PasswordChanged = 'password_changed';
+    // パスワード初回設定 (PasswordCredentialService が直接記録。RecordSecurityEvent の購読対象外)
+    case PasswordSet = 'password_set';
     case TwoFactorEnabled = 'two_factor_enabled';
     case TwoFactorDisabled = 'two_factor_disabled';
     case EmailChanged = 'email_changed';
@@ -37,6 +39,7 @@ public function label(): string
             self::Logout => 'ログアウト',
             self::PasswordReset => 'パスワードリセット',
             self::PasswordChanged => 'パスワード変更',
+            self::PasswordSet => 'パスワード設定',
             self::TwoFactorEnabled => '2要素認証の有効化',
             self::TwoFactorDisabled => '2要素認証の無効化',
             self::EmailChanged => 'メールアドレス変更',
diff --git a/app/Http/Controllers/Settings/PasswordSetupController.php b/app/Http/Controllers/Settings/PasswordSetupController.php
new file mode 100644
index 0000000..e53524e
--- /dev/null
+++ b/app/Http/Controllers/Settings/PasswordSetupController.php
@@ -0,0 +1,45 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Controllers\Settings;
+
+use App\Http\Controllers\Controller;
+use App\Http\Requests\Settings\SetPasswordRequest;
+use App\Models\User;
+use App\Services\Auth\PasswordCredentialService;
+use Illuminate\Http\RedirectResponse;
+use Webmozart\Assert\Assert;
+
+/**
+ * パスワード**初回設定** (POST /settings/password)。
+ *
+ * パスキー / ソーシャルログインのみのユーザーがアプリ内でパスワードを持てる唯一の経路。
+ * これが無いと「パスワードを設定してください」と案内する CTA がどこにも着地せず、
+ * 踏破不能 CTA になる (監査 F-2)。
+ */
+final class PasswordSetupController extends Controller
+{
+    public function __construct(
+        private readonly PasswordCredentialService $passwordCredentials,
+    ) {}
+
+    /**
+     * パスワード未設定ユーザーが初めてパスワードを設定する。
+     *
+     * - 認証手段を**増やす**操作なので EnsureLoginMethodRemains (減らす操作の関門) は付けない。
+     *   代わりに recent-auth を必須にし、セッション奪取からの永続化を防ぐ。
+     * - password 設定済みの迂回は Service が fail-closed で拒否する
+     *   (current_password 必須の変更経路を骨抜きにしない)。
+     * - 禁止事項 7 に従い `back()->with(...)` で完結する (intended は使わない)。
+     */
+    public function store(SetPasswordRequest $request): RedirectResponse
+    {
+        $user = $request->user();
+        Assert::isInstanceOf($user, User::class);
+
+        $this->passwordCredentials->setInitial($user, $request->string('password')->value());
+
+        return back()->with('success', 'パスワードを設定しました。次回からパスワードでもログインできます。');
+    }
+}
diff --git a/app/Http/Controllers/Settings/ProfileController.php b/app/Http/Controllers/Settings/ProfileController.php
index 4e63a9e..9e653f5 100644
--- a/app/Http/Controllers/Settings/ProfileController.php
+++ b/app/Http/Controllers/Settings/ProfileController.php
@@ -34,6 +34,9 @@ public function index(Request $request, OrganizationMembershipService $membershi
                 ])
                 ->values()
                 ->all(),
+            // パスワードカードの出し分け。password 未設定ユーザーに current_password 必須の
+            // 変更フォームを出すと必ず失敗する (踏破不能 UI) ため、初回設定フォームへ切り替える。
+            'hasPassword' => $user->hasPassword(),
         ]);
     }
 }
diff --git a/app/Http/Middleware/EnsureLoginMethodRemains.php b/app/Http/Middleware/EnsureLoginMethodRemains.php
index 21726be..5032641 100644
--- a/app/Http/Middleware/EnsureLoginMethodRemains.php
+++ b/app/Http/Middleware/EnsureLoginMethodRemains.php
@@ -113,9 +113,12 @@ private function removalFor(Request $request, User $user): LoginMethodRemoval
      */
     private function reject(Request $request): Response
     {
+        // settingsUrl は持たせない (削除済み)。理由:
+        // - Inertia 経路は back()->withErrors() で message しか運ばず、URL はどのクライアントも消費していない
+        // - 指していた settings.security にはパスワード設定 UI が無く、フロントの遷移先 (/settings) とも
+        //   食い違っていた (phantom 契約)。踏破可能な CTA は画面側 (PasskeySection → /settings) が持つ
         $dto = new LoginMethodRequiredDto(
             message: 'この操作を行うと、ログインする手段がなくなります。先に別のログイン手段（パスワードの設定、ソーシャル連携、他のパスキー）を追加してください。',
-            settingsUrl: route('settings.security'),
         );
 
         if ($request->expectsJson()) {
diff --git a/app/Http/Middleware/RequireRecentAuth.php b/app/Http/Middleware/RequireRecentAuth.php
index 0c584ab..a54d2be 100644
--- a/app/Http/Middleware/RequireRecentAuth.php
+++ b/app/Http/Middleware/RequireRecentAuth.php
@@ -23,7 +23,9 @@
  * 判定:
  *   1. `recent_auth_at` が鮮度ウィンドウ内 (RecentAuthWindow) → 通過
  *   2. XHR (expectsJson) または Inertia の非 GET → 409 + { code, message, redirect }(no-store)。
- *      クライアント (素 fetch / recent-auth precheck) が再認証後に元操作を再送
+ *      クライアント (素 fetch / recent-auth precheck) が再認証後に元操作を再送。
+ *      Inertia mutation のときだけ 302 分岐と同じ着地情報 (url.intended /
+ *      recent_auth.dropped_mutation) を残す (confirm 後に元画面へ戻すため)
  *   3. それ以外 (通常遷移) → 302 で recent-auth confirm 画面へ。元 URL を intended に保持
  */
 final class RequireRecentAuth
@@ -48,6 +50,17 @@ public function handle(Request $request, Closure $next): Response
         // 機能するため対象外。409 に x-inertia-location / x-inertia-redirect ヘッダを付けない
         // こと (Inertia core の external redirect 信号と衝突するため)。
         if ($request->expectsJson() || $this->isInertiaMutation($request)) {
+            // Inertia mutation の 409 は、クライアント (lib/recent-auth.ts の単一ハンドラ) が
+            // confirm 画面へ visit する。302 分岐と同じ着地契約に揃えるため、元 URL と
+            // 「mutation body を落とした」flag をここでも残す (残さないと confirm 成功後に
+            // dashboard へ落ち、操作がサイレントに失われる)。
+            // 純 XHR (fetch + Accept: application/json) は**対象外**: クライアントが自前で
+            // pending action を再開するため、intended を書くと他フローの intended を汚す。
+            if ($this->isInertiaMutation($request)) {
+                $session->put('url.intended', $this->sameOriginRefererOrDashboard($request));
+                $session->put('recent_auth.dropped_mutation', true);
+            }
+
             return RecentAuthRequiredResource::make(new RecentAuthRequiredDto(
                 message: 'この操作には直近の再認証が必要です。',
                 redirect: $confirmUrl,
diff --git a/app/Http/Requests/Settings/SetPasswordRequest.php b/app/Http/Requests/Settings/SetPasswordRequest.php
new file mode 100644
index 0000000..f260c3e
--- /dev/null
+++ b/app/Http/Requests/Settings/SetPasswordRequest.php
@@ -0,0 +1,38 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Requests\Settings;
+
+use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
+use Illuminate\Foundation\Http\FormRequest;
+use Illuminate\Validation\Rules\Password;
+
+/**
+ * パスワード**初回設定** (POST /settings/password) の入力検証。
+ *
+ * 変更 (current_password 必須) は Fortify の PUT /user/password が担う。
+ * ここは「password 未設定ユーザーが初めて設定する」経路のみ。
+ */
+class SetPasswordRequest extends FormRequest
+{
+    use ProhibitsProtectedKeys;
+
+    public function authorize(): bool
+    {
+        // 認可は route の auth / recent-auth middleware と Service の fail-closed 判定が担う
+        return true;
+    }
+
+    /**
+     * @return array<string, mixed>
+     */
+    public function rules(): array
+    {
+        // 強度の SSOT は PasswordPolicy (Password::defaults で配線済)。
+        // 確認入力 (confirmed) は使わない (表示トグルで代替。UpdateUserPassword と同方針)。
+        return array_replace([
+            'password' => ['required', 'string', Password::default()],
+        ], $this->protectedKeyMissingRules());
+    }
+}
diff --git a/app/Http/Resources/Auth/LoginMethodRequiredResource.php b/app/Http/Resources/Auth/LoginMethodRequiredResource.php
index 959eb40..e88e56b 100644
--- a/app/Http/Resources/Auth/LoginMethodRequiredResource.php
+++ b/app/Http/Resources/Auth/LoginMethodRequiredResource.php
@@ -9,7 +9,7 @@
 use Illuminate\Http\Resources\Json\JsonResource;
 
 /**
- * ログイン手段保持 guard の拒否ボディ ({ code, message, settingsUrl })。
+ * ログイン手段保持 guard の拒否ボディ ({ code, message })。
  *
  * `response()->json()` 直接使用を避けるための JsonResource (禁止事項 4)。
  * no-store ヘッダは middleware 側で付与する。`data` ラップはしない (top-level)。
@@ -22,14 +22,13 @@ final class LoginMethodRequiredResource extends JsonResource
     public static $wrap = null;
 
     /**
-     * @return array{code: 'login_method_required', message: string, settingsUrl: string}
+     * @return array{code: 'login_method_required', message: string}
      */
     public function toArray(Request $request): array
     {
         return [
             'code' => LoginMethodRequiredDto::CODE,
             'message' => $this->resource->message,
-            'settingsUrl' => $this->resource->settingsUrl,
         ];
     }
 }
diff --git a/app/Services/Auth/PasswordCredentialService.php b/app/Services/Auth/PasswordCredentialService.php
new file mode 100644
index 0000000..c619c81
--- /dev/null
+++ b/app/Services/Auth/PasswordCredentialService.php
@@ -0,0 +1,144 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Auth;
+
+use App\Enums\SecurityEventType;
+use App\Models\User;
+use App\Services\Security\SecurityEventRecorder;
+use Illuminate\Support\Facades\Auth;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Hash;
+use Illuminate\Validation\ValidationException;
+use Throwable;
+use Webmozart\Assert\Assert;
+
+/**
+ * users.password の確定 (設定 / 変更) の単一窓口。
+ *
+ * 「確定後に何が起きるか」(監査記録・他デバイス失効) を 1 箇所に集約する。
+ * 2 経路 (Fortify の変更 / 初回設定) に別々に書くと、片方だけ劣化する
+ * (= 他デバイスのセッションが残る等のセキュリティ後退) ため統合する。
+ *
+ * **transaction 境界の設計**: transaction に入れるのは
+ * 「ロック取得 → 前提の再確認 → password の保存」だけ。
+ * best-effort な副作用 (監査記録 / DB session 行削除) は **commit 後**に実行する。
+ * PostgreSQL は transaction 内で失敗した文があると以降 aborted 状態になり、
+ * アプリ側で catch しても commit できない — best-effort のつもりの副作用が
+ * 主処理 (パスワード保存) を巻き添えにする。既存 UpdateUserPassword もこれらを
+ * transaction 外で行っており、その性質を保つ。
+ */
+final class PasswordCredentialService
+{
+    public function __construct(
+        private readonly SecurityEventRecorder $recorder,
+    ) {}
+
+    /**
+     * 初回設定 (current_password 不要)。
+     *
+     * 呼び出し側の契約: **recent-auth (step-up) 済みであること** (route の middleware で強制)。
+     * password 設定済みユーザーの迂回は fail-closed で拒否する
+     * (current_password 必須の変更経路を骨抜きにしない)。
+     *
+     * @throws ValidationException
+     */
+    public function setInitial(User $user, string $plain): void
+    {
+        // transaction は「ロック → 再確認 → 保存」だけ (副作用は commit 後)
+        $hash = DB::transaction(function () use ($user, $plain): string {
+            // 同時 2 リクエストで両方が「未設定」と判定するのを防ぐ (TOCTOU)。
+            // ロック取得順序は User 単位 (EnsureLoginMethodRemains と同型の作法)。
+            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
+
+            if ($locked->hasPassword()) {
+                throw ValidationException::withMessages([
+                    'password' => 'すでにパスワードが設定されています。パスワード変更フォームから変更してください。',
+                ]);
+            }
+
+            $hash = Hash::make($plain);
+            $locked->forceFill(['password' => $hash])->save();
+
+            return $hash;
+        });
+
+        // **呼び出し元が持つインスタンス (= guard が保持している認証済み User) にも反映する**。
+        // 保存したのはロック取得のために引き直した別インスタンスであり、これを怠ると
+        // Auth::logoutOtherDevices() が guard 上の古い hash と照合して
+        // InvalidArgumentException を投げる (パスワードは保存済みなのに 500 になる)。
+        // 既に永続化済みなので dirty 扱いにはしない。
+        $user->forceFill(['password' => $hash])->syncOriginalAttribute('password');
+
+        $this->afterPersist($user, $plain, SecurityEventType::PasswordSet);
+    }
+
+    /**
+     * 変更 (current_password の検証は Fortify 契約側 UpdateUserPassword が行う)。
+     * 単一 UPDATE のため transaction は開かない (既存挙動を変えない)。
+     */
+    public function change(User $user, string $plain): void
+    {
+        $user->forceFill(['password' => Hash::make($plain)])->save();
+
+        $this->afterPersist($user, $plain, SecurityEventType::PasswordChanged);
+    }
+
+    /**
+     * 保存 **commit 後**の副作用: 監査記録 → 他デバイス失効 → DB session 行削除。
+     * transaction 内では実行しない (上記の PostgreSQL 事情)。
+     * best-effort なのは **監査記録と DB session 行削除**の 2 つ (どちらも内部で例外を握る)。
+     * `Auth::logoutOtherDevices()` は例外を捕捉しない (失敗は 500 として表面化させる。
+     * 他デバイス失効は correctness 側の要求であり、既存 UpdateUserPassword の挙動を維持する)。
+     */
+    private function afterPersist(User $user, string $plain, SecurityEventType $event): void
+    {
+        // 「そのユーザーが自分でパスワードを設定/変更したか」の監査証跡。
+        // 記録失敗は report のみ (SecurityEventRecorder が内包する)。
+        $this->recorder->record($event, $user);
+
+        // 現在デバイスを維持しつつ他デバイスを失効させる。logoutOtherDevices は password を
+        // 再ハッシュし、現在デバイスの recaller (remember-me) を新ハッシュで再発行 (現在リクエストが
+        // recaller を持つ場合のみ) + OtherDeviceLogout イベントを発火する。他デバイスの実失効は
+        // web グループの AuthenticateSession による password_hash 照合が担保する (correctness の要)。
+        // 渡すのは current_password ではなく保存直後の新 password。
+        Auth::logoutOtherDevices($plain);
+
+        $this->deleteOtherSessionRecords($user);
+    }
+
+    /**
+     * 現在の session を除き、当該 user の DB session 行を削除する (session driver=database 時のみ)。
+     *
+     * correctness は AuthenticateSession が担うため best-effort: 失敗しても report して継続する
+     * (パスワードの確定自体は成功しているため正常応答を維持する)。
+     */
+    private function deleteOtherSessionRecords(User $user): void
+    {
+        if (config('session.driver') !== 'database') {
+            return;
+        }
+
+        // session 未初期化文脈 (console/queue 等) では現在ID除外の前提が崩れるため何もしない。
+        if (! session()->isStarted()) {
+            return;
+        }
+
+        $connection = config('session.connection');
+        $table = config('session.table', 'sessions');
+
+        Assert::nullOrString($connection);
+        Assert::string($table);
+
+        try {
+            DB::connection($connection)
+                ->table($table)
+                ->where('user_id', $user->getAuthIdentifier())
+                ->where('id', '!=', session()->getId())
+                ->delete();
+        } catch (Throwable $e) {
+            report($e);
+        }
+    }
+}
diff --git a/docs/auth-security-mechanisms.md b/docs/auth-security-mechanisms.md
index 01427e4..3e8ad0b 100644
--- a/docs/auth-security-mechanisms.md
+++ b/docs/auth-security-mechanisms.md
@@ -58,7 +58,8 @@ ### 成立 (satisfier) と session state
   両 UI (`RecentAuthModal` / `Auth/ConfirmRecentAuth`) は
   `passwordSet || availableProviders || (passkeyAvailable && passkeySupported)` を
   クライアント側で導出し、成立しない場合は**理由と回復導線を明示**する
-  (`recent-auth-unsupported-here` / `confirm-unsupported-here`)。password 未設定 (SSO-only) は password 経路を **fail-closed** で拒否し、
+  (実装は `components/molecules/RecentAuthRecoveryNotice.svelte` に集約。testId は
+  `recent-auth-unsupported-here` / 手段 0 は `recent-auth-recovery`)。password 未設定 (SSO-only) は password 経路を **fail-closed** で拒否し、
   再SSO へ誘導する。step-up 可能な provider は `config('template.social_providers.*.capability')` から解決 (未宣言は satisfier 不可)。
 - fresh login (`Login` event、web guard・非 recaller) は `StampRecentAuthOnLogin` が `method='login'` で自動 stamp する。
   ログイン直後の機微操作で「もう 1 回」の二重壁を消す。remember-me による自動復元 (`viaRemember()`) は fresh 扱いしない (fail-closed)。
@@ -324,7 +325,7 @@ ### 応答契約 (transport で分岐)
 | リクエスト種別 | 応答 |
 |--------------|------|
 | Inertia | `302` (Inertia が DELETE では 303 に変換) + `errors.login_method` |
-| 純 XHR (`Accept: application/json`) | `422` + `{ code: 'login_method_required', message, settingsUrl }` (`no-store`) |
+| 純 XHR (`Accept: application/json`) | `422` + `{ code: 'login_method_required', message }` (`no-store`) |
 | 通常フォーム | `back()->withErrors('login_method')` |
 
 **Inertia に 422 JSON を返さない** (protocol 違反で router が応答を解釈できず無言失敗する)。
diff --git a/docs/supported-browsers.md b/docs/supported-browsers.md
index fabcd1c..1be8542 100644
--- a/docs/supported-browsers.md
+++ b/docs/supported-browsers.md
@@ -20,7 +20,7 @@ # サポート対象ブラウザ方針
 履歴暗号鍵が実際に消えるのは `page.set()` 冒頭の `history.clear()` が走った瞬間だからである
 (受信ではなく適用。通信断や JS 例外で適用前に中断すれば鍵は残る)。
 アプリの `/logout` 導線は 3 箇所 (`AppLayout.svelte` / `pages/Auth/VerifyEmail.svelte` /
-`pages/Auth/ConfirmRecentAuth.svelte`) で
+`components/molecules/RecentAuthRecoveryNotice.svelte`) で
 いずれも `router.post` = Inertia visit のため、正常完了時にこの条件を満たす
 (この不変条件は `tests/js/architecture/logout-call-site-inventory.test.ts` が固定する)。
 **ログアウト導線を非 Inertia 経路 (JSON 204 で完結する XHR 等) で新設すると、
diff --git a/resources/js/app.ts b/resources/js/app.ts
index 4376616..c47a3a7 100644
--- a/resources/js/app.ts
+++ b/resources/js/app.ts
@@ -3,6 +3,7 @@ import { hydrate, mount } from "svelte";
 import { resolvePage } from "./inertia";
 import { registerBfcacheGuard } from "./lib/bfcache-guard";
 import { registerDocumentTitleSync } from "./lib/document-title";
+import { registerRecentAuthRedirectHandler } from "./lib/recent-auth";
 import { hasAuthenticatedUser } from "./lib/shared-props";
 
 // SPA 遷移後の document.title 陳腐化を解消する。Svelte adapter には createInertiaApp の
@@ -23,6 +24,12 @@ if (typeof document !== "undefined") {
         isAuthenticated: () => hasAuthenticatedUser(page.props),
     });
     import.meta.hot?.dispose(disposeBfcacheGuard);
+
+    // recent-auth 鮮度切れの 409 (recent_auth_required) を confirm 画面へ着地させる単一ハンドラ。
+    // precheck (withRecentAuth) を通れない delegated 経路の受け皿であり、これが無いと
+    // 409 が Inertia の既定エラーモーダルに落ちて無言の行き止まりになる (詳細設計 施策 4)。
+    const disposeRecentAuthRedirect = registerRecentAuthRedirectHandler();
+    import.meta.hot?.dispose(disposeRecentAuthRedirect);
 }
 
 createInertiaApp({
diff --git a/resources/js/components/features/auth/PasskeySection.svelte b/resources/js/components/features/auth/PasskeySection.svelte
index 86beb4c..327e859 100644
--- a/resources/js/components/features/auth/PasskeySection.svelte
+++ b/resources/js/components/features/auth/PasskeySection.svelte
@@ -1,6 +1,7 @@
 <script lang="ts">
     import { router } from "@inertiajs/svelte";
     import { KeyRound } from "@lucide/svelte";
+    import { tick } from "svelte";
     import Alert from "@/components/atoms/Alert.svelte";
     import Badge from "@/components/atoms/Badge.svelte";
     import Button from "@/components/atoms/Button.svelte";
@@ -14,7 +15,6 @@
         isPasskeySupported,
         type PasskeyListItem,
     } from "@/lib/passkeys";
-    import { addToast } from "@/lib/stores/toast";
 
     /**
      * セキュリティ設定のパスキーカード。
@@ -22,6 +22,8 @@
      * 契約:
      * - 登録 / 削除は **recent-auth 必須**。precheck は呼び出し側 (page) が持つ `guard` に委譲する
      *   (再認証モーダルはページに 1 つだけ置き、二重モーダルを作らない)。
+     *   `guard` は precheck の結果を返す Promise であり、**precheck 区間も loading で覆う**
+     *   (待ち時間中の連打で ceremony が多重起動し pending action が上書きされるのを塞ぐ)。
      * - 登録は ceremony (fetch) → **Inertia `router.post`** で送る (transport 契約)。
      *   成功 flash はサーバ (`back()->with('success')`) を単一の源とし client 楽観 toast を出さない。
      * - 削除は ConfirmDialog → `router.delete`。ログイン手段が 0 になる場合サーバは
@@ -29,6 +31,9 @@
      *   (**無言失敗にしない**)。
      * - **必須条件未充足でボタンを disabled にしない** (AGENTS.md 禁止事項 8)。
      *   非対応端末でも押せて、押下時にエラーを出す。
+     * - **非フィールド起因の操作失敗は Alert** (DESIGN.md §Alert)。ceremony 失敗・端末非対応は
+     *   押したその場に残る Alert に出す (Toast は画面外へ飛ぶ一時通知であり、押下直後に
+     *   読ませたい失敗理由の提示先として使わない)。フィールド起因 (名前) だけが FormField。
      */
     interface Props {
         passkeys?: PasskeyListItem[];
@@ -37,8 +42,11 @@
         twoFactorEnabled?: boolean;
         /** EnsureLoginMethodRemains の拒否メッセージ ($page.props.errors.login_method) */
         loginMethodError?: string;
-        /** recent-auth precheck。fresh なら即実行、stale なら再認証モーダルを挟んで再開する */
-        guard: (action: () => void) => void;
+        /**
+         * recent-auth precheck。fresh なら即実行、stale なら再認証モーダルを挟んで再開する。
+         * 戻り値は実行した分岐 (precheck 区間を loading で覆うために待つ)。
+         */
+        guard: (action: () => void) => Promise<"fresh" | "stale" | "delegated">;
     }
 
     let {
@@ -56,60 +64,119 @@
     })();
 
     let newPasskeyName = $state("");
-    let nameError = $state("");
+
+    /**
+     * DESIGN.md §FormField: 押下時に出した client エラーは入力に追随させる
+     * (stale invalid を残さない)。新規は「提示開始 boolean + $derived 文言」で書く。
+     */
+    let nameErrorShown = $state(false);
+    /** サーバ由来 (422) のエラーは入力で消さない (DESIGN.md の例外規定) */
+    let serverNameError = $state<string | null>(null);
+    /** 非フィールド起因の操作失敗 (ceremony 失敗・端末非対応・登録 POST 失敗) */
+    let operationError = $state("");
+
+    const trimmedName = $derived(newPasskeyName.trim());
+    const clientNameError = $derived(
+        nameErrorShown && trimmedName === "" ? "パスキーの名前を入力してください。" : "",
+    );
+    const nameError = $derived(serverNameError ?? clientNameError);
+
+    /** ceremony ～ POST 完了まで (削除側と同じ作法で onStart/onFinish が握る) */
     let registering = $state(false);
+    /** precheck (/recent-auth/status) 実行中。ceremony/POST 中は registering が覆う */
+    let prechecking = $state(false);
+    const busy = $derived(prechecking || registering);
 
-    function registerPasskey(): void {
-        if (registering) return;
-        // 非対応端末でも押下できる (disabled にしない)。押した結果として理由を出す。
-        if (!supported) {
-            addToast(
-                "error",
-                "このブラウザはパスキーに対応していません。パスワードまたはソーシャルログインをご利用ください。",
-            );
+    /**
+     * ceremony → POST。`registering` は ceremony 開始時に立て、
+     * cancelled / unsupported / failed で終わったときだけ戻す
+     * (`finally` で一律解除すると POST 完了前に解除され、連打で ceremony が多重に走る)。
+     */
+    async function startCeremonyAndPost(capturedName: string): Promise<void> {
+        registering = true;
+        const outcome = await createPasskeyCredential();
+        if (outcome.status === "cancelled") {
+            // キャンセルは失敗として騒がない (再試行導線を残す)
+            registering = false;
             return;
         }
-        const name = newPasskeyName.trim();
-        if (name === "") {
-            nameError = "パスキーの名前を入力してください。";
+        if (outcome.status === "unsupported") {
+            operationError = "このブラウザはパスキーに対応していません。";
+            registering = false;
+            return;
+        }
+        if (outcome.status === "failed") {
+            operationError = outcome.message;
+            registering = false;
             return;
         }
-        nameError = "";
 
-        guard(() => {
-            void (async () => {
-                registering = true;
-                try {
-                    const outcome = await createPasskeyCredential();
-                    if (outcome.status === "cancelled") return;
-                    if (outcome.status === "unsupported") {
-                        addToast("error", "このブラウザはパスキーに対応していません。");
-                        return;
-                    }
-                    if (outcome.status === "failed") {
-                        addToast("error", outcome.message);
-                        return;
-                    }
-                    router.post(
-                        "/user/passkeys",
-                        { name, credential: outcome.value },
-                        {
-                            preserveScroll: true,
-                            onSuccess: () => {
-                                newPasskeyName = "";
-                            },
-                            onError: () => {
-                                addToast("error", "パスキーの登録に失敗しました。");
-                            },
-                        },
-                    );
-                } finally {
+        router.post(
+            "/user/passkeys",
+            { name: capturedName, credential: outcome.value },
+            {
+                preserveScroll: true,
+                onStart: () => {
+                    registering = true;
+                },
+                onFinish: () => {
                     registering = false;
-                }
-            })();
-        });
+                },
+                onSuccess: () => {
+                    newPasskeyName = "";
+                    nameErrorShown = false;
+                },
+                onError: (errors) => {
+                    // フィールド起因は FormField へ、それ以外は Alert へ
+                    const nameMessage = (errors as Record<string, unknown>).name;
+                    serverNameError = typeof nameMessage === "string" ? nameMessage : null;
+                    if (serverNameError === null) {
+                        operationError =
+                            "パスキーの登録に失敗しました。時間をおいて再度お試しください。";
+                    }
+                },
+            },
+        );
     }
 
+    async function registerPasskey(): Promise<void> {
+        if (busy) return;
+        operationError = "";
+        // 非対応端末でも押下できる (disabled にしない)。押した結果として理由を出す。
+        if (!supported) {
+            operationError =
+                "このブラウザはパスキーに対応していません。パスワードまたはソーシャルログインをご利用ください。";
+            return;
+        }
+        nameErrorShown = true;
+        serverNameError = null;
+        if (trimmedName === "") return; // 文言は $derived が出す
+
+        // 押下時点の名前を確定させる (再認証モーダルを挟む間に入力欄が編集されても揺れない)
+        const capturedName = trimmedName;
+
+        prechecking = true;
+        try {
+            // fresh なら guard の中で action (ceremony → POST) が走り、registering が引き継ぐ。
+            // stale / delegated ならモーダル側へ委譲されるので、ここで precheck を閉じてよい。
+            await guard(() => void startCeremonyAndPost(capturedName));
+        } finally {
+            prechecking = false;
+        }
+    }
+
+    /* ---- ログイン手段保持 guard の拒否 Alert にフォーカスを移す (見落とさせない) ----
+       リカバリコード panel (Settings/Security) と同じ作法 (tabindex=-1 + bind:this + tick)。 */
+    let loginMethodAlert = $state<HTMLDivElement | null>(null);
+    let lastFocusedLoginMethodError = $state<string | undefined>(undefined);
+
+    $effect(() => {
+        const message = loginMethodError;
+        if (message === undefined || message === lastFocusedLoginMethodError) return;
+        lastFocusedLoginMethodError = message;
+        void tick().then(() => loginMethodAlert?.focus());
+    });
+
     let deleteTarget = $state<PasskeyListItem | null>(null);
     let deleteDialogOpen = $state(false);
     let deleting = $state(false);
@@ -122,7 +189,7 @@
     function confirmDelete(): void {
         const target = deleteTarget;
         if (target === null) return;
-        guard(() => {
+        void guard(() => {
             router.delete(`/user/passkeys/${target.id}`, {
                 preserveScroll: true,
                 onStart: () => {
@@ -159,16 +226,26 @@
 
     <div class="mt-4 flex flex-col gap-4">
         {#if loginMethodError}
-            <Alert type="danger" title="削除できません" testId="passkey-login-method-error">
-                {loginMethodError}
-                {#snippet action()}
-                    <div class="flex flex-wrap gap-3">
-                        <Button variant="ghost" href="/settings" testId="passkey-add-password">
-                            パスワードを設定する
-                        </Button>
-                    </div>
-                {/snippet}
-            </Alert>
+            <div bind:this={loginMethodAlert} tabindex="-1">
+                <Alert type="danger" title="削除できません" testId="passkey-login-method-error">
+                    {loginMethodError}
+                    このページの「ソーシャルログイン連携」から外部アカウントを連携するか、
+                    下のフォームから別のパスキーを登録することもできます。
+                    {#snippet action()}
+                        <div class="flex flex-wrap gap-3">
+                            <!--
+                              遷移先 /settings は password 未設定ユーザーには「パスワードを設定」
+                              フォームを出す (施策 7)。この Alert が出るのは「削除するとログイン手段が
+                              0 になる」= password を持たないユーザーだけなので
+                              (LoginMethodInventory の投影評価)、CTA は必ず踏破可能。
+                            -->
+                            <Button variant="ghost" href="/settings" testId="passkey-add-password">
+                                パスワードを設定する
+                            </Button>
+                        </div>
+                    {/snippet}
+                </Alert>
+            </div>
         {/if}
 
         {#if !passkeyLoginAvailable && twoFactorEnabled}
@@ -219,6 +296,9 @@
         {/if}
 
         <div class="flex flex-col gap-3">
+            {#if operationError}
+                <Alert type="danger" testId="passkey-operation-error">{operationError}</Alert>
+            {/if}
             <FormField label="パスキーの名前" id="passkey-name" error={nameError}>
                 {#snippet children({ id, describedBy, invalid })}
                     <Input
@@ -233,7 +313,11 @@
                 {/snippet}
             </FormField>
             <div>
-                <Button onclick={registerPasskey} loading={registering} testId="register-passkey-button">
+                <Button
+                    onclick={() => void registerPasskey()}
+                    loading={busy}
+                    testId="register-passkey-button"
+                >
                     パスキーを登録
                 </Button>
             </div>
diff --git a/resources/js/components/molecules/RecentAuthRecoveryNotice.svelte b/resources/js/components/molecules/RecentAuthRecoveryNotice.svelte
new file mode 100644
index 0000000..4240e3b
--- /dev/null
+++ b/resources/js/components/molecules/RecentAuthRecoveryNotice.svelte
@@ -0,0 +1,76 @@
+<script lang="ts">
+    import { router } from "@inertiajs/svelte";
+    import Button from "@/components/atoms/Button.svelte";
+
+    /**
+     * 再認証 (step-up) が**この場では成立しない**ユーザーに出す回復導線。
+     * 全画面 confirm (pages/Auth/ConfirmRecentAuth) とインラインモーダル
+     * (organisms/RecentAuthModal) の**両方が使う唯一の実装**。
+     *
+     * 分けて持つと片方だけ旧作法が残る (監査 F-2a: モーダル側だけ guest 限定の
+     * /forgot-password へリンクし続けていた)。
+     *
+     * **`/forgot-password` へ直接リンクしない**: Fortify が guest middleware 付きで登録しており、
+     * ログイン済みの本 UI 利用者はフォームに到達できない (踏破不能 CTA。bug-hunt F-2-01 と同 species)。
+     * ログアウトしてから guest として再設定する導線だけが端まで踏破できる
+     * (tests/Feature/Auth/RecentAuthPasswordRecoveryTest がこの経路を端まで固定している)。
+     *
+     * アプリ内の「パスワードを設定」(POST /settings/password) は **recent-auth 必須**なので、
+     * ここに来ているユーザー (= step-up が成立しない) には使えない。だからログアウト経路を案内する。
+     *
+     * 配置が molecule なのは構造的制約: 呼び出し元の RecentAuthModal は organism であり、
+     * atomic-import-graph 上 organism は features 層を import できない (単方向 import)。
+     *
+     * ログアウトは **Inertia visit (router.post)** で行う (経路 C: Inertia history 暗号化 +
+     * clearHistory の保証条件。tests/js/architecture/logout-call-site-inventory.test.ts が固定)。
+     */
+    interface Props {
+        /**
+         * - `no-satisfier`: アカウントに再認証手段が無い (canSatisfy=false)
+         * - `not-executable-here`: 手段はあるがこの端末で実行できない (パスキー非対応ブラウザ)
+         */
+        variant: "no-satisfier" | "not-executable-here";
+    }
+
+    let { variant }: Props = $props();
+
+    let loggingOut = $state(false);
+
+    function logout(): void {
+        if (loggingOut) return; // 二重送信ガード
+        router.post(
+            "/logout",
+            {},
+            {
+                onStart: () => {
+                    loggingOut = true;
+                },
+                onFinish: () => {
+                    loggingOut = false;
+                },
+            },
+        );
+    }
+
+    const testId = $derived(
+        variant === "no-satisfier" ? "recent-auth-recovery" : "recent-auth-unsupported-here",
+    );
+</script>
+
+<div class="flex flex-col gap-3 text-caption text-text-secondary" data-testid={testId}>
+    {#if variant === "no-satisfier"}
+        <p>
+            この操作を続けるための再認証手段が設定されていません。
+            いったんログアウトし、ログイン画面の「パスワードをお忘れの方」から
+            パスワードを設定すると再認証できるようになります。
+        </p>
+    {:else}
+        <p>
+            このアカウントの再認証手段はパスキーのみですが、このブラウザはパスキーに対応していません。
+            パスキーを登録した端末・ブラウザで開き直すと再認証できます。
+            その端末が使えない場合は、いったんログアウトし、ログイン画面の
+            「パスワードをお忘れの方」からパスワードを設定してください。
+        </p>
+    {/if}
+    <Button variant="ghost" onclick={logout} loading={loggingOut} fullWidth>ログアウトする</Button>
+</div>
diff --git a/resources/js/components/organisms/RecentAuthModal.svelte b/resources/js/components/organisms/RecentAuthModal.svelte
index 55c24d6..bdd8ed7 100644
--- a/resources/js/components/organisms/RecentAuthModal.svelte
+++ b/resources/js/components/organisms/RecentAuthModal.svelte
@@ -1,14 +1,16 @@
 <script lang="ts">
+    import { router } from "@inertiajs/svelte";
     import { ShieldCheck } from "@lucide/svelte";
+    import Alert from "@/components/atoms/Alert.svelte";
     import Button from "@/components/atoms/Button.svelte";
-    import FormError from "@/components/atoms/FormError.svelte";
     import Input from "@/components/atoms/Input.svelte";
     import Divider from "@/components/molecules/Divider.svelte";
     import FormField from "@/components/molecules/FormField.svelte";
+    import RecentAuthRecoveryNotice from "@/components/molecules/RecentAuthRecoveryNotice.svelte";
     import Modal from "@/components/organisms/Modal.svelte";
     import { csrfToken } from "@/lib/csrf";
     import { confirmWithPasskey, isPasskeySupported } from "@/lib/passkeys";
-    import type { AvailableReauthProvider } from "@/lib/recent-auth";
+    import type { RecentAuthStatus } from "@/lib/recent-auth";
     import { providerLabel } from "@/lib/social";
 
     /**
@@ -18,32 +20,34 @@
      * - 再SSO 可能な provider (availableProviders): reauthUrl へフルリダイレクト。
      * - パスキー登録済み (passkeyAvailable): WebAuthn 検証 → POST /passkeys/confirm (204)。
      *   TOTP 有効ユーザーでも **再認証には使える** (PasskeyLoginPolicy が縛るのはログインのみ)。
-     * - canSatisfy=false (再認証手段なし): 回復導線 (パスワードリセット) を案内する。
+     * - canSatisfy=false (再認証手段なし): 回復導線 (RecentAuthRecoveryNotice) を案内する。
      * 認可の最終ゲートは各操作の recent-auth middleware (本モーダルは UX 補助)。
+     *
+     * **契約: `/recent-auth/status` の応答 (RecentAuthStatus) を分解せず 1 個の型で受ける**。
+     * field を prop に分解して手渡す形は、field が増えるたびに配線漏れを生む
+     * (T106 で passkeyAvailable を足した際、6 呼び出し中 5 箇所が未配線のまま出荷され、
+     *  passkey-only ユーザーが 5 画面で詰んだ)。呼び出し側が独自に status を組み立てないこと。
+     * 強制は tests/js/architecture/recent-auth-modal-call-site-inventory.test.ts
+     * (deny-by-default)。pnpm typecheck は tsc --noEmit で .svelte テンプレートを
+     * 型検査しないため、型宣言だけでは配線漏れを止められない。
+     *
+     * status === null は「状態不明」(呼び出し側の実装ミス)。空表示や事実に反する文言を
+     * 出さず、取得失敗として明示し再読み込み導線を出す。
      */
     interface Props {
         open: boolean;
-        passwordSet?: boolean;
-        availableProviders?: AvailableReauthProvider[];
-        canSatisfy?: boolean;
-        /**
-         * パスキーでの再認証を提示するか。**サーバの `/recent-auth/status` が単一の源**
-         * (`RecentAuthStatus.passkeyAvailable`)。呼び出し側が独自に判定しない
-         * — 画面ごとに判定を持つと passkey しか持たないユーザーが特定画面でだけ詰む。
-         */
-        passkeyAvailable?: boolean;
-        /** password satisfier 成功時 (204)。呼び出し側が pending action を再開する */
+        /** /recent-auth/status の応答。null = 状態不明 (通常経路では発生しない) */
+        status: RecentAuthStatus | null;
+        /** satisfier 成功時。呼び出し側が pending action を再開する */
         onConfirmed: () => void;
     }
 
-    let {
-        open = $bindable(false),
-        passwordSet = false,
-        availableProviders = [],
-        canSatisfy = true,
-        passkeyAvailable = false,
-        onConfirmed,
-    }: Props = $props();
+    let { open = $bindable(false), status, onConfirmed }: Props = $props();
+
+    const passwordSet = $derived(status?.passwordSet ?? false);
+    const availableProviders = $derived(status?.availableProviders ?? []);
+    const canSatisfy = $derived(status?.canSatisfy ?? false);
+    const passkeyAvailable = $derived(status?.passkeyAvailable ?? false);
 
     const passkeySupported = isPasskeySupported();
     let passkeySubmitting = $state(false);
@@ -59,10 +63,32 @@
         passwordSet || availableProviders.length > 0 || (passkeyAvailable && passkeySupported),
     );
 
+    let password = $state("");
+    /** FormField (フィールド起因のエラー) */
+    let passwordError = $state("");
+    /**
+     * Alert (非フィールド起因の操作失敗)。DESIGN.md §Alert の規約どおり、WebAuthn ceremony の
+     * 失敗を password フィールドのエラーとして出さない (状態を共有すると、パスキー失敗が
+     * 「現在のパスワード」欄の赤字として現れる = 原因と提示先が食い違う)。
+     */
+    let passkeyError = $state("");
+    let submitting = $state(false);
+
+    // モーダルを閉じたら入力状態をリセットする (次回表示への持ち越し防止)
+    $effect(() => {
+        if (!open) {
+            password = "";
+            passwordError = "";
+            passkeyError = "";
+            submitting = false;
+            passkeySubmitting = false;
+        }
+    });
+
     async function submitPasskey(): Promise<void> {
         if (passkeySubmitting) return;
         passkeySubmitting = true;
-        error = "";
+        passkeyError = "";
         try {
             const outcome = await confirmWithPasskey();
             if (outcome.status === "ok") {
@@ -72,7 +98,7 @@
             }
             // キャンセルは失敗として騒がない (再試行導線を残す)
             if (outcome.status === "cancelled") return;
-            error =
+            passkeyError =
                 outcome.status === "unsupported"
                     ? "このブラウザはパスキーに対応していません。"
                     : outcome.message;
@@ -81,29 +107,15 @@
         }
     }
 
-    let password = $state("");
-    let error = $state("");
-    let submitting = $state(false);
-
-    // モーダルを閉じたら入力状態をリセットする (次回表示への持ち越し防止)
-    $effect(() => {
-        if (!open) {
-            password = "";
-            error = "";
-            submitting = false;
-            passkeySubmitting = false;
-        }
-    });
-
     async function submitPassword(event: SubmitEvent): Promise<void> {
         event.preventDefault();
         if (submitting) return;
         if (password === "") {
-            error = "パスワードを入力してください。";
+            passwordError = "パスワードを入力してください。";
             return;
         }
         submitting = true;
-        error = "";
+        passwordError = "";
         try {
             const res = await fetch("/recent-auth/password", {
                 method: "POST",
@@ -126,12 +138,12 @@
                 const body = (await res.json().catch(() => null)) as {
                     errors?: { password?: string[] };
                 } | null;
-                error = body?.errors?.password?.[0] ?? "パスワードが正しくありません。";
+                passwordError = body?.errors?.password?.[0] ?? "パスワードが正しくありません。";
                 return;
             }
-            error = "再認証に失敗しました。時間をおいて再度お試しください。";
+            passwordError = "再認証に失敗しました。時間をおいて再度お試しください。";
         } catch {
-            error = "通信エラーが発生しました。";
+            passwordError = "通信エラーが発生しました。";
         } finally {
             submitting = false;
         }
@@ -145,80 +157,78 @@
             <p>セキュリティのため、この操作を続けるにはもう一度本人確認が必要です。</p>
         </div>
 
-        {#if passwordSet}
-            <form novalidate onsubmit={submitPassword} class="flex flex-col gap-3">
-                <FormField label="現在のパスワード" id="recent-auth-password" error={error}>
-                    {#snippet children({ id, describedBy, invalid })}
-                        <Input
-                            {id}
-                            type="password"
-                            bind:value={password}
-                            error={invalid}
-                            aria-describedby={describedBy}
-                            autocomplete="current-password"
-                            testId="recent-auth-password-input"
-                        />
-                    {/snippet}
-                </FormField>
-                <Button type="submit" loading={submitting} fullWidth testId="recent-auth-submit">
-                    確認する
-                </Button>
-            </form>
+        {#if status === null}
+            <div
+                class="flex flex-col gap-2 text-caption text-text-secondary"
+                data-testid="recent-auth-unknown"
+            >
+                <p>再認証の状態を取得できませんでした。ページを再読み込みしてお試しください。</p>
+                <Button variant="ghost" fullWidth onclick={() => router.reload()}>再読み込み</Button>
+            </div>
         {:else}
-            <FormError message={error} testId="recent-auth-error" />
-        {/if}
-
-        {#if passkeyAvailable && passkeySupported}
             {#if passwordSet}
-                <Divider label="または" />
+                <form novalidate onsubmit={submitPassword} class="flex flex-col gap-3">
+                    <FormField label="現在のパスワード" id="recent-auth-password" error={passwordError}>
+                        {#snippet children({ id, describedBy, invalid })}
+                            <Input
+                                {id}
+                                type="password"
+                                bind:value={password}
+                                error={invalid}
+                                aria-describedby={describedBy}
+                                autocomplete="current-password"
+                                testId="recent-auth-password-input"
+                            />
+                        {/snippet}
+                    </FormField>
+                    <Button type="submit" loading={submitting} fullWidth testId="recent-auth-submit">
+                        確認する
+                    </Button>
+                </form>
             {/if}
-            <Button
-                variant="ghost"
-                fullWidth
-                loading={passkeySubmitting}
-                onclick={() => void submitPasskey()}
-                testId="recent-auth-passkey"
-            >
-                パスキーで再認証
-            </Button>
-        {/if}
 
-        {#if availableProviders.length > 0}
-            {#if passwordSet || (passkeyAvailable && passkeySupported)}
-                <Divider label="または" />
+            {#if passkeyAvailable && passkeySupported}
+                {#if passwordSet}
+                    <Divider label="または" />
+                {/if}
+                {#if passkeyError}
+                    <Alert type="danger" testId="recent-auth-passkey-error">{passkeyError}</Alert>
+                {/if}
+                <Button
+                    variant="ghost"
+                    fullWidth
+                    loading={passkeySubmitting}
+                    onclick={() => void submitPasskey()}
+                    testId="recent-auth-passkey"
+                >
+                    パスキーで再認証
+                </Button>
             {/if}
-            <div class="flex flex-col gap-2">
-                {#each availableProviders as provider (provider.provider)}
-                    <Button
-                        href={provider.reauthUrl}
-                        variant="ghost"
-                        fullWidth
-                        testId={`recent-auth-sso-${provider.provider}`}
-                    >
-                        {providerLabel(provider.provider)}で再認証
-                    </Button>
-                {/each}
-            </div>
-        {/if}
 
-        {#if !canSatisfy}
-            <div class="flex flex-col gap-2 text-caption text-text-secondary" data-testid="recent-auth-recovery">
-                <p>この操作を続けるための再認証手段が設定されていません。</p>
-                <Button href="/forgot-password" variant="ghost" fullWidth>
-                    パスワードを設定して再認証する
-                </Button>
-            </div>
-        {:else if !executableHere}
-            <!-- アカウントには手段があるが、この端末では実行できない (パスキー非対応ブラウザ) -->
-            <div
-                class="flex flex-col gap-2 text-caption text-text-secondary"
-                data-testid="recent-auth-unsupported-here"
-            >
-                <p>
-                    このアカウントの再認証手段はパスキーのみですが、このブラウザはパスキーに対応していません。
-                    パスキーを登録した端末・ブラウザで開き直すと再認証できます。
-                </p>
-            </div>
+            {#if availableProviders.length > 0}
+                {#if passwordSet || (passkeyAvailable && passkeySupported)}
+                    <Divider label="または" />
+                {/if}
+                <div class="flex flex-col gap-2">
+                    {#each availableProviders as provider (provider.provider)}
+                        <Button
+                            href={provider.reauthUrl}
+                            variant="ghost"
+                            fullWidth
+                            testId={`recent-auth-sso-${provider.provider}`}
+                        >
+                            {providerLabel(provider.provider)}で再認証
+                        </Button>
+                    {/each}
+                </div>
+            {/if}
+
+            {#if !canSatisfy}
+                <RecentAuthRecoveryNotice variant="no-satisfier" />
+            {:else if !executableHere}
+                <!-- アカウントには手段があるが、この端末では実行できない (パスキー非対応ブラウザ) -->
+                <RecentAuthRecoveryNotice variant="not-executable-here" />
+            {/if}
         {/if}
     </div>
     {#snippet footer()}
diff --git a/resources/js/lib/recent-auth.ts b/resources/js/lib/recent-auth.ts
index 5346eeb..6f2dfe4 100644
--- a/resources/js/lib/recent-auth.ts
+++ b/resources/js/lib/recent-auth.ts
@@ -1,3 +1,4 @@
+import { router } from "@inertiajs/svelte";
 import { addToast } from "@/lib/stores/toast";
 
 /**
@@ -33,6 +34,52 @@ export interface RecentAuthStatus {
     confirmedAt: number | null;
 }
 
+/** provider 要素を strict に検証する (欠落は「SSO ボタンが出ない」詰みになる) */
+function parseProvider(value: unknown): AvailableReauthProvider | null {
+    if (typeof value !== "object" || value === null) return null;
+    const { provider, capability, reauthUrl } = value as Record<string, unknown>;
+    if (typeof provider !== "string") return null;
+    if (typeof capability !== "string") return null;
+    if (typeof reauthUrl !== "string") return null;
+    return { provider, capability, reauthUrl };
+}
+
+/**
+ * `/recent-auth/status` の応答を **strict に** 検証する。
+ *
+ * 既定値による補完はしない: field が欠けた応答を既定値で埋めると
+ * 「サーバは手段があると言っているのに UI に出ない」= 監査 F-1 と同じ詰みが
+ * **通信境界で再演**する (call-site gate では検出できない)。
+ * 契約不成立は null にし、withRecentAuth の delegated 経路 (= サーバの最終ゲートへ委譲) に倒す。
+ */
+export function parseRecentAuthStatus(body: unknown): RecentAuthStatus | null {
+    if (typeof body !== "object" || body === null) return null;
+    const { recent, passwordSet, availableProviders, passkeyAvailable, canSatisfy, confirmedAt } =
+        body as Record<string, unknown>;
+    if (typeof recent !== "boolean") return null;
+    if (typeof passwordSet !== "boolean") return null;
+    if (typeof passkeyAvailable !== "boolean") return null;
+    if (typeof canSatisfy !== "boolean") return null;
+    if (confirmedAt !== null && typeof confirmedAt !== "number") return null;
+    if (!Array.isArray(availableProviders)) return null;
+
+    const providers: AvailableReauthProvider[] = [];
+    for (const raw of availableProviders) {
+        const parsed = parseProvider(raw);
+        if (parsed === null) return null;
+        providers.push(parsed);
+    }
+
+    return {
+        recent,
+        passwordSet,
+        availableProviders: providers,
+        passkeyAvailable,
+        canSatisfy,
+        confirmedAt,
+    };
+}
+
 /**
  * recent-auth 状態を fresh に取得する。失敗時は null (呼び出し側は最終ゲート委譲にフォールバック)。
  */
@@ -43,16 +90,7 @@ export async function fetchRecentAuthStatus(): Promise<RecentAuthStatus | null>
             credentials: "same-origin",
         });
         if (!res.ok) return null;
-        const body = (await res.json()) as Partial<RecentAuthStatus>;
-        if (typeof body.recent !== "boolean") return null;
-        return {
-            recent: body.recent,
-            passwordSet: body.passwordSet ?? false,
-            availableProviders: body.availableProviders ?? [],
-            passkeyAvailable: body.passkeyAvailable ?? false,
-            canSatisfy: body.canSatisfy ?? false,
-            confirmedAt: body.confirmedAt ?? null,
-        };
+        return parseRecentAuthStatus(await res.json());
     } catch {
         return null;
     }
@@ -88,3 +126,63 @@ export async function withRecentAuth(handlers: {
     handlers.onStale(status);
     return "stale";
 }
+
+/** RecentAuthRequiredDto::CODE と対 (code 厳格一致で自分宛て応答のみ処理する) */
+const RECENT_AUTH_REQUIRED_CODE = "recent_auth_required";
+/** 遷移を許す唯一の着地 (サーバ由来 URL を無検証でグローバル遷移に使わない) */
+const RECENT_AUTH_CONFIRM_PATH = "/recent-auth/confirm";
+
+/**
+ * `httpException` の `event.detail.response` (Inertia core の `HttpExceptionResponse` =
+ * `{ status, data, headers }`。`data` は JSON なら parse 済みオブジェクト、
+ * それ以外は生文字列) から confirm 画面への遷移先を取り出す。
+ *
+ * 受入条件を満たさないものは null を返し、呼び出し側は preventDefault しない (fail-closed)。
+ */
+function recentAuthRedirectTarget(response: unknown): string | null {
+    if (typeof response !== "object" || response === null) return null;
+    const { status, data } = response as { status?: unknown; data?: unknown };
+    if (status !== 409) return null;
+    if (typeof data !== "object" || data === null) return null;
+    const { code, redirect } = data as Record<string, unknown>;
+    if (code !== RECENT_AUTH_REQUIRED_CODE) return null; // 他の 409 契約を誤食しない
+    if (typeof redirect !== "string") return null;
+    // same-origin かつ既知 path のみ (外部 URL / 別 route への誘導を構造的に不能にする)
+    let url: URL;
+    try {
+        url = new URL(redirect, window.location.origin);
+    } catch {
+        return null;
+    }
+    if (url.origin !== window.location.origin) return null;
+    if (url.pathname !== RECENT_AUTH_CONFIRM_PATH) return null;
+    return url.pathname + url.search;
+}
+
+/**
+ * recent-auth 鮮度切れの 409 を confirm 画面への Inertia visit に変換する。
+ *
+ * precheck (withRecentAuth) を通れない経路 = status 取得失敗・契約不成立 (delegated) では
+ * 元操作がそのまま飛び、サーバ (RequireRecentAuth) が 409 + `recent_auth_required` を返す。
+ * 誰も拾わないと Inertia の既定 (エラーモーダル表示) になり **無言の行き止まり**になるため、
+ * ここで単一のハンドラに集約する。
+ *
+ * **購読するイベントは `httpException`**: 詳細設計は Inertia v1/v2 の `invalid` を前提に
+ * していたが、本リポジトリの @inertiajs/core 3.3.1 に `invalid` は存在せず、
+ * 非 Inertia 応答 (4xx/5xx) の cancelable イベントは `httpException` に統合されている
+ * (`Response#handleNonInertiaResponse`)。`preventDefault()` で既定のエラーモーダルを抑止する
+ * 意味論は同一。
+ *
+ * サーバ側は 409 を返す際に `url.intended` と `recent_auth.dropped_mutation` を保存するため
+ * (RequireRecentAuth)、confirm 成功後は元画面へ戻り「操作は未実行」の案内が出る。
+ *
+ * @returns 購読解除関数 (HMR の二重登録防止に使う)
+ */
+export function registerRecentAuthRedirectHandler(): () => void {
+    return router.on("httpException", (event) => {
+        const target = recentAuthRedirectTarget(event.detail.response);
+        if (target === null) return;
+        event.preventDefault();
+        void router.visit(target);
+    });
+}
diff --git a/resources/js/pages/Admin/Users.svelte b/resources/js/pages/Admin/Users.svelte
index 9cd50e5..ccaca83 100644
--- a/resources/js/pages/Admin/Users.svelte
+++ b/resources/js/pages/Admin/Users.svelte
@@ -519,9 +519,7 @@
 
         <RecentAuthModal
             bind:open={recentAuthOpen}
-            passwordSet={recentAuthStatus?.passwordSet ?? false}
-            availableProviders={recentAuthStatus?.availableProviders ?? []}
-            canSatisfy={recentAuthStatus?.canSatisfy ?? true}
+            status={recentAuthStatus}
             onConfirmed={resumePendingAction}
         />
         </PageContent>
diff --git a/resources/js/pages/Auth/ConfirmRecentAuth.svelte b/resources/js/pages/Auth/ConfirmRecentAuth.svelte
index f5acd2a..3fc1a8f 100644
--- a/resources/js/pages/Auth/ConfirmRecentAuth.svelte
+++ b/resources/js/pages/Auth/ConfirmRecentAuth.svelte
@@ -1,11 +1,13 @@
 <script lang="ts">
     import { router, useForm } from "@inertiajs/svelte";
+    import Alert from "@/components/atoms/Alert.svelte";
     import Button from "@/components/atoms/Button.svelte";
     import FormError from "@/components/atoms/FormError.svelte";
     import TextLink from "@/components/atoms/TextLink.svelte";
     import Divider from "@/components/molecules/Divider.svelte";
     import FormField from "@/components/molecules/FormField.svelte";
     import PasswordInput from "@/components/molecules/PasswordInput.svelte";
+    import RecentAuthRecoveryNotice from "@/components/molecules/RecentAuthRecoveryNotice.svelte";
     import AuthLayout from "@/components/templates/AuthLayout.svelte";
     import { confirmPasskeyCredential, isPasskeySupported } from "@/lib/passkeys";
     import type { AvailableReauthProvider } from "@/lib/recent-auth";
@@ -20,8 +22,8 @@
      * - パスキー登録済み (passkeyAvailable): WebAuthn 検証 (POST /passkeys/confirm、204)。
      *   **パスキーしか持たないユーザーをこの画面で詰ませない**ための導線
      * - canSatisfy=false: 回復手順 (ログアウト → guest としてパスワード再設定) を案内。
-     *   /forgot-password へ直接リンクしない — Fortify が `guest` middleware 付きで登録しており
-     *   ログイン済みの本画面ユーザーはフォームに到達できない (踏破不能 CTA。bug-hunt F-2-01 と同 species)
+     *   実装は molecules/RecentAuthRecoveryNotice に集約する (インラインモーダル側と共有。
+     *   分けて持つと片方だけ旧作法 = guest 限定の /forgot-password 直リンクが残る)
      */
     interface Props {
         appName?: string;
@@ -95,27 +97,10 @@
         password: "",
     });
 
-    let loggingOut = $state(false);
-
     function submit(event: SubmitEvent): void {
         event.preventDefault();
         form.post("/recent-auth/password");
     }
-
-    function logout(): void {
-        router.post(
-            "/logout",
-            {},
-            {
-                onStart: () => {
-                    loggingOut = true;
-                },
-                onFinish: () => {
-                    loggingOut = false;
-                },
-            },
-        );
-    }
 </script>
 
 <AuthLayout title="本人確認" {appName}>
@@ -152,7 +137,9 @@
                 <Divider label="または" />
             {/if}
             {#if passkeyError}
-                <FormError message={passkeyError} testId="confirm-passkey-error" />
+                <!-- 非フィールド起因の操作失敗は Alert (DESIGN.md §Alert)。ceremony 失敗を
+                     password 欄のフィールドエラーとして出さない -->
+                <Alert type="danger" testId="confirm-passkey-error">{passkeyError}</Alert>
             {/if}
             <Button
                 variant="ghost"
@@ -180,31 +167,13 @@
     {/if}
 
     {#if !canSatisfy}
-        <div class="mt-6 flex flex-col gap-3 text-caption text-text-secondary">
-            <p>
-                この操作を続けるための再認証手段が設定されていません。
-                いったんログアウトし、ログイン画面の「パスワードをお忘れの方」から
-                パスワードを設定すると再認証できるようになります。
-            </p>
-            <Button variant="ghost" onclick={logout} loading={loggingOut} fullWidth>
-                ログアウトする
-            </Button>
+        <div class="mt-6">
+            <RecentAuthRecoveryNotice variant="no-satisfier" />
         </div>
     {:else if !executableHere}
         <!-- アカウントには手段があるが、この端末では実行できない (パスキー非対応ブラウザ) -->
-        <div
-            class="mt-6 flex flex-col gap-3 text-caption text-text-secondary"
-            data-testid="confirm-unsupported-here"
-        >
-            <p>
-                このアカウントの再認証手段はパスキーのみですが、このブラウザはパスキーに対応していません。
-                パスキーを登録した端末・ブラウザで開き直すと再認証できます。
-                その端末が使えない場合は、いったんログアウトし、ログイン画面の
-                「パスワードをお忘れの方」からパスワードを設定してください。
-            </p>
-            <Button variant="ghost" onclick={logout} loading={loggingOut} fullWidth>
-                ログアウトする
-            </Button>
+        <div class="mt-6">
+            <RecentAuthRecoveryNotice variant="not-executable-here" />
         </div>
     {/if}
 
diff --git a/resources/js/pages/Organizations/ApiKeys/Index.svelte b/resources/js/pages/Organizations/ApiKeys/Index.svelte
index b0cab5f..366af85 100644
--- a/resources/js/pages/Organizations/ApiKeys/Index.svelte
+++ b/resources/js/pages/Organizations/ApiKeys/Index.svelte
@@ -302,9 +302,7 @@
 
         <RecentAuthModal
             bind:open={recentAuthOpen}
-            passwordSet={recentAuthStatus?.passwordSet ?? false}
-            availableProviders={recentAuthStatus?.availableProviders ?? []}
-            canSatisfy={recentAuthStatus?.canSatisfy ?? true}
+            status={recentAuthStatus}
             onConfirmed={resumePendingAction}
         />
         </PageContent>
diff --git a/resources/js/pages/Organizations/ApiKeys/Sessions.svelte b/resources/js/pages/Organizations/ApiKeys/Sessions.svelte
index e57d8f8..a75d47d 100644
--- a/resources/js/pages/Organizations/ApiKeys/Sessions.svelte
+++ b/resources/js/pages/Organizations/ApiKeys/Sessions.svelte
@@ -208,9 +208,7 @@
 
         <RecentAuthModal
             bind:open={recentAuthOpen}
-            passwordSet={recentAuthStatus?.passwordSet ?? false}
-            availableProviders={recentAuthStatus?.availableProviders ?? []}
-            canSatisfy={recentAuthStatus?.canSatisfy ?? true}
+            status={recentAuthStatus}
             onConfirmed={resumePendingAction}
         />
         </PageContent>
diff --git a/resources/js/pages/Organizations/Settings.svelte b/resources/js/pages/Organizations/Settings.svelte
index 0fdf937..7ee3732 100644
--- a/resources/js/pages/Organizations/Settings.svelte
+++ b/resources/js/pages/Organizations/Settings.svelte
@@ -335,9 +335,7 @@
 
         <RecentAuthModal
             bind:open={recentAuthOpen}
-            passwordSet={recentAuthStatus?.passwordSet ?? false}
-            availableProviders={recentAuthStatus?.availableProviders ?? []}
-            canSatisfy={recentAuthStatus?.canSatisfy ?? true}
+            status={recentAuthStatus}
             onConfirmed={resumePendingAction}
         />
         </PageContent>
diff --git a/resources/js/pages/Settings/Index.svelte b/resources/js/pages/Settings/Index.svelte
index 45f0351..7cf2160 100644
--- a/resources/js/pages/Settings/Index.svelte
+++ b/resources/js/pages/Settings/Index.svelte
@@ -26,6 +26,8 @@
     }
     interface SettingsPageProps extends SharedProps {
         soleOwnedOrganizations?: SoleOwnedOrganization[];
+        /** password が設定済みか。欠落 = 状態不明 (既定値に倒さない。下記 passwordState 参照) */
+        hasPassword?: boolean;
         errors?: Record<string, string | string[]>;
     }
 
@@ -33,6 +35,14 @@
     const appName = $derived(props.appName ?? "");
     const soleOwnedOrganizations = $derived(props.soleOwnedOrganizations ?? []);
 
+    /**
+     * prop 欠落 (= 状態不明) を false に倒すと、password 設定済みユーザーに初回設定フォームを出す
+     * = 本批で潰している「状態不明を誤った UI に倒す」の再演になる。3 値で扱う。
+     */
+    const passwordState = $derived.by((): "set" | "unset" | "unknown" =>
+        typeof props.hasPassword === "boolean" ? (props.hasPassword ? "set" : "unset") : "unknown",
+    );
+
     // ブロック時にサーバーが返す errors.account を表示文字列へ正規化 (string | string[] 両対応)
     const accountError = $derived.by((): string | null => {
         const err = props.errors?.account;
@@ -102,6 +112,23 @@
         });
     }
 
+    const passwordSetupForm = useForm({ password: "" });
+
+    function submitPasswordSetup(event: SubmitEvent): void {
+        event.preventDefault();
+        passwordSetupForm.clearErrors("password");
+        // 初回設定は recent-auth 必須 (サーバ側 middleware が最終ゲート)。
+        // stale なら再認証モーダルを挟んで再開する (他の機微操作と同じ precheck)。
+        guardWithRecentAuth(() => {
+            passwordSetupForm.post("/settings/password", {
+                preserveScroll: true,
+                onSuccess: () => {
+                    passwordSetupForm.reset();
+                },
+            });
+        });
+    }
+
     let deleteDialogOpen = $state(false);
     let deleting = $state(false);
 
@@ -196,47 +223,89 @@
             </Card>
 
             <Card padding="lg">
-                <h2 class="text-h3">パスワード変更</h2>
-                <p class="mt-1 text-caption text-text-secondary">
-                    現在のパスワードを確認のうえ、新しいパスワードに変更します。
-                </p>
-                <form novalidate onsubmit={submitPassword} class="mt-4 flex flex-col gap-4">
-                    <FormField
-                        label="現在のパスワード"
-                        id="current-password"
-                        error={passwordForm.errors.current_password}
-                    >
-                        {#snippet children({ id, describedBy, invalid })}
-                            <PasswordInput
-                                {id}
-                                bind:value={passwordForm.current_password}
-                                error={invalid}
-                                aria-describedby={describedBy}
-                                autocomplete="current-password"
-                            />
+                {#if passwordState === "unknown"}
+                    <h2 class="text-h3">パスワード</h2>
+                    <Alert type="warning" testId="password-state-unknown" class="mt-4">
+                        パスワードの設定状態を取得できませんでした。ページを再読み込みしてお試しください。
+                        {#snippet action()}
+                            <Button variant="ghost" onclick={() => router.reload()}>再読み込み</Button>
                         {/snippet}
-                    </FormField>
-                    <FormField
-                        label="新しいパスワード"
-                        id="new-password"
-                        error={passwordForm.errors.password}
-                    >
-                        {#snippet children({ id, describedBy, invalid })}
-                            <PasswordInput
-                                {id}
-                                bind:value={passwordForm.password}
-                                error={invalid}
-                                aria-describedby={describedBy}
-                                autocomplete="new-password"
-                            />
-                        {/snippet}
-                    </FormField>
-                    <div>
-                        <Button type="submit" loading={passwordForm.processing}>
-                            {passwordForm.processing ? "変更中…" : "パスワードを変更"}
-                        </Button>
-                    </div>
-                </form>
+                    </Alert>
+                {:else if passwordState === "set"}
+                    <h2 class="text-h3">パスワード変更</h2>
+                    <p class="mt-1 text-caption text-text-secondary">
+                        現在のパスワードを確認のうえ、新しいパスワードに変更します。
+                    </p>
+                    <form novalidate onsubmit={submitPassword} class="mt-4 flex flex-col gap-4">
+                        <FormField
+                            label="現在のパスワード"
+                            id="current-password"
+                            error={passwordForm.errors.current_password}
+                        >
+                            {#snippet children({ id, describedBy, invalid })}
+                                <PasswordInput
+                                    {id}
+                                    bind:value={passwordForm.current_password}
+                                    error={invalid}
+                                    aria-describedby={describedBy}
+                                    autocomplete="current-password"
+                                />
+                            {/snippet}
+                        </FormField>
+                        <FormField
+                            label="新しいパスワード"
+                            id="new-password"
+                            error={passwordForm.errors.password}
+                        >
+                            {#snippet children({ id, describedBy, invalid })}
+                                <PasswordInput
+                                    {id}
+                                    bind:value={passwordForm.password}
+                                    error={invalid}
+                                    aria-describedby={describedBy}
+                                    autocomplete="new-password"
+                                />
+                            {/snippet}
+                        </FormField>
+                        <div>
+                            <Button type="submit" loading={passwordForm.processing}>
+                                {passwordForm.processing ? "変更中…" : "パスワードを変更"}
+                            </Button>
+                        </div>
+                    </form>
+                {:else}
+                    <h2 class="text-h3">パスワードを設定</h2>
+                    <p class="mt-1 text-caption text-text-secondary">
+                        現在はパスキーまたはソーシャルログインでご利用中です。パスワードを設定すると、
+                        パスワードでもログインできるようになります (既存のログイン手段はそのまま使えます)。
+                    </p>
+                    <form novalidate onsubmit={submitPasswordSetup} class="mt-4 flex flex-col gap-4">
+                        <FormField
+                            label="新しいパスワード"
+                            id="new-password"
+                            error={passwordSetupForm.errors.password}
+                        >
+                            {#snippet children({ id, describedBy, invalid })}
+                                <PasswordInput
+                                    {id}
+                                    bind:value={passwordSetupForm.password}
+                                    error={invalid}
+                                    aria-describedby={describedBy}
+                                    autocomplete="new-password"
+                                />
+                            {/snippet}
+                        </FormField>
+                        <div>
+                            <Button
+                                type="submit"
+                                loading={passwordSetupForm.processing}
+                                testId="set-password-button"
+                            >
+                                {passwordSetupForm.processing ? "設定中…" : "パスワードを設定"}
+                            </Button>
+                        </div>
+                    </form>
+                {/if}
             </Card>
 
             <DangerZone
@@ -286,9 +355,7 @@
 
         <RecentAuthModal
             bind:open={recentAuthOpen}
-            passwordSet={recentAuthStatus?.passwordSet ?? false}
-            availableProviders={recentAuthStatus?.availableProviders ?? []}
-            canSatisfy={recentAuthStatus?.canSatisfy ?? true}
+            status={recentAuthStatus}
             onConfirmed={resumePendingAction}
         />
         </PageContent>
diff --git a/resources/js/pages/Settings/Security.svelte b/resources/js/pages/Settings/Security.svelte
index 0fa3d8b..c10c8d9 100644
--- a/resources/js/pages/Settings/Security.svelte
+++ b/resources/js/pages/Settings/Security.svelte
@@ -68,8 +68,13 @@
     let recentAuthStatus = $state<RecentAuthStatus | null>(null);
     let pendingAction: (() => void) | null = null;
 
-    function guardWithRecentAuth(action: () => void): void {
-        void withRecentAuth({
+    /**
+     * precheck の結果 (fresh / stale / delegated) を **返す**。
+     * PasskeySection は precheck 区間 (`/recent-auth/status` の待ち時間) を自前の loading で
+     * 覆う必要があるため戻り値を待つ。結果に関心が無い呼び出し側は `void` で明示的に捨てる。
+     */
+    function guardWithRecentAuth(action: () => void): Promise<"fresh" | "stale" | "delegated"> {
+        return withRecentAuth({
             onFresh: action,
             onStale: (status) => {
                 recentAuthStatus = status;
@@ -223,7 +228,7 @@
      * GET も recent-auth 配線済みのため precheck を通す (stale なら再認証モーダル→再開)。
      */
     function showRecoveryCodes(): void {
-        guardWithRecentAuth(() => {
+        void guardWithRecentAuth(() => {
             void (async () => {
                 if (!(await loadRecoveryCodes())) {
                     addToast("error", "リカバリコードの取得に失敗しました。");
@@ -263,7 +268,7 @@
 
     /** 再生成は recent-auth 必須 (サーバが最終ゲート)。stale なら再認証モーダル→再開 */
     function regenerateRecoveryCodes(): void {
-        guardWithRecentAuth(() => {
+        void guardWithRecentAuth(() => {
             router.post(
                 "/user/two-factor-recovery-codes",
                 {},
@@ -601,10 +606,7 @@
 
         <RecentAuthModal
             bind:open={recentAuthOpen}
-            passwordSet={recentAuthStatus?.passwordSet ?? false}
-            availableProviders={recentAuthStatus?.availableProviders ?? []}
-            canSatisfy={recentAuthStatus?.canSatisfy ?? true}
-            passkeyAvailable={recentAuthStatus?.passkeyAvailable ?? false}
+            status={recentAuthStatus}
             onConfirmed={resumePendingAction}
         />
         </PageContent>
diff --git a/routes/web.php b/routes/web.php
index e2e1e1e..9a24d03 100644
--- a/routes/web.php
+++ b/routes/web.php
@@ -44,6 +44,7 @@
 use App\Http\Controllers\Seo\RobotsController;
 use App\Http\Controllers\Seo\SitemapController;
 use App\Http\Controllers\Settings\AccountController;
+use App\Http\Controllers\Settings\PasswordSetupController;
 use App\Http\Controllers\Settings\ProfileController;
 use App\Http\Controllers\Settings\SecurityController;
 use App\Http\Controllers\Webhooks\SesNotificationController;
@@ -185,6 +186,13 @@
 
     Route::get('/settings', [ProfileController::class, 'index'])->name('settings');
 
+    // パスワード**初回設定** (password 未設定ユーザー専用)。認証手段を増やす操作のため
+    // step-up (recent-auth) 必須。変更 (current_password 必須) は Fortify の PUT /user/password。
+    // EnsureLoginMethodRemains は付けない (手段を減らす操作の関門であり方向が逆)。
+    Route::post('/settings/password', [PasswordSetupController::class, 'store'])
+        ->middleware(['recent-auth', 'throttle:6,1'])
+        ->name('settings.password.store');
+
     // 2FA / ソーシャル連携 / パスキーの管理面 (passkey 一覧の組み立てに DI が要るため Controller)
     Route::get('/settings/security', SecurityController::class)->name('settings.security');
 
diff --git a/tests/Architecture/ControllerAuthorizationGateTest.php b/tests/Architecture/ControllerAuthorizationGateTest.php
index b388b82..df353ed 100644
--- a/tests/Architecture/ControllerAuthorizationGateTest.php
+++ b/tests/Architecture/ControllerAuthorizationGateTest.php
@@ -95,6 +95,12 @@ function controllerAuthorizationExemptions(): array
             .'他人のアカウントへ到達する経路がコード上存在しない。'
             .'別軸の防御として recent-auth (step-up) middleware を必須にしている。'],
 
+        'settings.password.store' => [$selfScoped,
+            '対象は $request->user() 自身のパスワード初回設定のみ。route に他者を指せる parameter が'
+            .'無く、他人の credential へ到達する経路がコード上存在しない。'
+            .'別軸の防御として recent-auth (step-up) middleware を必須にし、password 設定済みの'
+            .'迂回は PasswordCredentialService が lock 下で fail-closed 拒否する。総当り防御は throttle:6,1。'],
+
         'recent-auth.password' => [$selfScoped,
             '自分の再認証鮮度 (RecentAuthState) の更新。route に他者を指せる parameter が無く、'
             .'認証そのものが主体判定であるため Policy による再判定に意味がない。'
diff --git a/tests/Architecture/RecentAuthRouteTest.php b/tests/Architecture/RecentAuthRouteTest.php
index 55995ba..7956649 100644
--- a/tests/Architecture/RecentAuthRouteTest.php
+++ b/tests/Architecture/RecentAuthRouteTest.php
@@ -28,6 +28,8 @@ function recentAuthRequiredRouteNames(): array
         'organizations.api-keys.sessions.revoke',
         // アカウント削除
         'settings.account.destroy',
+        // パスワード初回設定 (認証手段を増やす操作。セッション奪取からの永続化を防ぐため step-up 必須)
+        'settings.password.store',
         // オーナー移譲
         'organizations.transfer-ownership',
         // 組織の 2FA 必須方針トグル (Owner 専権のセキュリティ方針変更)
diff --git a/tests/Feature/Auth/LoginMethodRetentionTest.php b/tests/Feature/Auth/LoginMethodRetentionTest.php
index a444a5a..2daf79e 100644
--- a/tests/Feature/Auth/LoginMethodRetentionTest.php
+++ b/tests/Feature/Auth/LoginMethodRetentionTest.php
@@ -74,8 +74,14 @@ function linkGoogleTo(User $user): void
 
     $response->assertStatus(422)
         ->assertHeader('Cache-Control', 'no-store, private')
-        ->assertJsonPath('code', 'login_method_required')
-        ->assertJsonPath('settingsUrl', route('settings.security'));
+        ->assertJsonPath('code', 'login_method_required');
+
+    // **キー集合を code / message に固定する** (T107 施策 8)。
+    // 旧 settingsUrl は誰も消費しておらず、指していた settings.security にパスワード設定 UI が
+    // 無かった (phantom 契約)。踏破可能な CTA は画面側 (PasskeySection → /settings) が持つ。
+    // ここで集合を固定して phantom contract の再追加を機械的に防ぐ。
+    expect(array_keys((array) $response->json()))->toEqualCanonicalizing(['code', 'message']);
+
     expect(Passkey::query()->whereKey($passkey->getKey())->exists())->toBeTrue();
 });
 
@@ -123,6 +129,12 @@ function linkGoogleTo(User $user): void
     expect($user->passkeys()->count())->toBe(1);
 });
 
+/*
+ * **CTA 踏破可能性の根拠** (T107 施策 8):
+ * login_method 拒否が起きるのは password 未設定ユーザーだけである。
+ * だから拒否 Alert の CTA (/settings) は必ず「パスワードを設定」フォームに着地する
+ * (password を持つユーザーにこの Alert は構造的に発生しない)。
+ */
 test('password があれば唯一の passkey を削除できる', function (): void {
     $user = User::factory()->create();
     $passkey = Passkey::factory()->for($user)->create();
diff --git a/tests/Feature/Auth/RecentAuthStatusContractTest.php b/tests/Feature/Auth/RecentAuthStatusContractTest.php
new file mode 100644
index 0000000..c8ed639
--- /dev/null
+++ b/tests/Feature/Auth/RecentAuthStatusContractTest.php
@@ -0,0 +1,111 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Passkey;
+use App\Models\SocialAccount;
+use App\Models\User;
+
+/*
+ * `/recent-auth/status` の **JSON 契約**を過不足なく固定する (T107 施策 3)。
+ *
+ * クライアント (resources/js/lib/recent-auth.ts) は strict parse に変えた:
+ * field が欠けた応答を既定値で補完すると「サーバは手段があると言っているのに UI に出ない」
+ * = 監査 F-1 と同じ詰みが通信境界で再演するため、契約不成立は null (delegated) に倒す。
+ *
+ * したがって **キー集合の一致**がクライアント側の前提そのものになる。
+ * サーバが field を増やす/減らす/名前を変えると本テストが落ち、TS 側の parse を
+ * 同じ PR で更新する判断が強制される。
+ */
+
+/** status を JSON で取得する (Cache 制御ヘッダも含めて検査する) */
+function fetchStatusJson(User $user): array
+{
+    $response = test()->actingAs($user)->getJson('/recent-auth/status');
+    $response->assertOk()->assertHeader('Cache-Control', 'no-store, private');
+
+    $decoded = $response->json();
+    expect($decoded)->toBeArray();
+
+    return $decoded;
+}
+
+test('top-level のキー集合が契約と一致する (過不足を許さない)', function (): void {
+    $user = User::factory()->create();
+
+    $body = fetchStatusJson($user);
+
+    expect(array_keys($body))->toEqualCanonicalizing([
+        'recent',
+        'passwordSet',
+        'availableProviders',
+        'passkeyAvailable',
+        'canSatisfy',
+        'confirmedAt',
+    ]);
+});
+
+/*
+ * `data` ラップが入ると TS の strict parse は即 null になり、**全画面が delegated へ落ちる**
+ * (再認証モーダルが一切出なくなる)。RecentAuthStatusResource::$wrap = null の維持を固定する。
+ */
+test('top-level に data ラップが無い', function (): void {
+    $user = User::factory()->create();
+
+    $body = fetchStatusJson($user);
+
+    expect($body)->not->toHaveKey('data');
+    expect($body)->toHaveKey('recent');
+});
+
+test('各値の型が契約どおり (bool / array / int|null)', function (): void {
+    $user = User::factory()->create();
+
+    $body = fetchStatusJson($user);
+
+    expect($body['recent'])->toBeBool();
+    expect($body['passwordSet'])->toBeBool();
+    expect($body['passkeyAvailable'])->toBeBool();
+    expect($body['canSatisfy'])->toBeBool();
+    expect($body['availableProviders'])->toBeArray();
+    expect($body['confirmedAt'])->toBeNull();
+});
+
+test('鮮度成立時は confirmedAt が int になる', function (): void {
+    $user = User::factory()->create();
+
+    $response = $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->getJson('/recent-auth/status');
+
+    $response->assertOk();
+    expect($response->json('recent'))->toBeTrue();
+    expect($response->json('confirmedAt'))->toBeInt();
+});
+
+test('SSO 連携ありユーザーの provider 要素キーが契約と一致する', function (): void {
+    $user = User::factory()->ssoOnly()->create();
+    $account = new SocialAccount(['provider' => 'google', 'provider_user_id' => 'g-contract']);
+    $account->user()->associate($user);
+    $account->save();
+
+    $body = fetchStatusJson($user);
+
+    expect($body['availableProviders'])->toHaveCount(1);
+    $provider = $body['availableProviders'][0];
+    expect(array_keys($provider))->toEqualCanonicalizing(['provider', 'capability', 'reauthUrl']);
+    expect($provider['provider'])->toBeString();
+    expect($provider['capability'])->toBeString();
+    expect($provider['reauthUrl'])->toBeString();
+});
+
+test('passkey 登録済みユーザーは passkeyAvailable=true になる (passkey-only でも canSatisfy)', function (): void {
+    $user = User::factory()->ssoOnly()->create();
+    Passkey::factory()->for($user)->create();
+
+    $body = fetchStatusJson($user);
+
+    expect($body['passwordSet'])->toBeFalse();
+    expect($body['passkeyAvailable'])->toBeTrue();
+    expect($body['canSatisfy'])->toBeTrue();
+});
diff --git a/tests/Feature/Auth/RecentAuthTest.php b/tests/Feature/Auth/RecentAuthTest.php
index 61a5767..c20490d 100644
--- a/tests/Feature/Auth/RecentAuthTest.php
+++ b/tests/Feature/Auth/RecentAuthTest.php
@@ -12,6 +12,7 @@
 use Illuminate\Auth\SessionGuard;
 use Illuminate\Contracts\Auth\Factory as AuthFactory;
 use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Hash;
 use Inertia\Testing\AssertableInertia;
 use Laravel\Fortify\Features;
 use Laravel\Socialite\Contracts\Provider;
@@ -81,6 +82,76 @@ function linkGoogleAccount(User $user, string $providerUserId): void
     $response->assertStatus(409)->assertJsonPath('code', 'recent_auth_required');
 });
 
+/*
+ * 409 の着地契約 (T107 施策 4)。
+ *
+ * 409 を拾うクライアント (lib/recent-auth.ts の単一ハンドラ) は confirm 画面へ visit する。
+ * 302 分岐と同じ着地情報を残さないと、confirm 成功後に dashboard へ落ち、
+ * 「先ほどの操作は実行されていません」の案内も出ない = 操作のサイレント喪失になる。
+ */
+test('鮮度なしの Inertia mutation の 409 は url.intended と dropped_mutation を残す', function (): void {
+    $user = User::factory()->create();
+    $origin = config('app.url');
+
+    $this->actingAs($user)
+        ->withHeaders(['X-Inertia' => 'true', 'referer' => $origin.'/settings'])
+        ->delete('/settings/account')
+        ->assertStatus(409);
+
+    expect(session('url.intended'))->toBe($origin.'/settings');
+    expect(session('recent_auth.dropped_mutation'))->toBeTrue();
+});
+
+test('409 の intended も same-origin referer のみ採用する (open redirect 防止)', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)
+        ->withHeaders(['X-Inertia' => 'true', 'referer' => 'https://evil.example.com/phish'])
+        ->delete('/settings/account')
+        ->assertStatus(409);
+
+    expect(session('url.intended'))->toBe(route('dashboard'));
+});
+
+/*
+ * **純 XHR の 409 では intended を書き換えない**。クライアントが自前で pending action を
+ * 再開するため、書くと他フロー (ログイン直後の着地等) の intended を汚す。
+ */
+test('純 XHR の 409 は url.intended を書き換えない', function (): void {
+    $user = User::factory()->create();
+    $origin = config('app.url');
+
+    $this->actingAs($user)
+        ->withSession(['url.intended' => $origin.'/manuals'])
+        ->withHeaders(['referer' => $origin.'/settings'])
+        ->deleteJson('/settings/account')
+        ->assertStatus(409);
+
+    expect(session('url.intended'))->toBe($origin.'/manuals');
+    expect(session('recent_auth.dropped_mutation'))->toBeNull();
+});
+
+test('409 経路でも confirm 成功後は元画面へ戻り操作未実行の案内が出る', function (): void {
+    $user = User::factory()->create(['password' => Hash::make('current-password')]);
+    $origin = config('app.url');
+
+    $this->actingAs($user)
+        ->withHeaders(['X-Inertia' => 'true', 'referer' => $origin.'/settings'])
+        ->delete('/settings/account')
+        ->assertStatus(409);
+
+    $this->flushHeaders();
+
+    $this->actingAs($user)
+        ->withHeaders(['X-Inertia' => 'true'])
+        ->post('/recent-auth/password', ['password' => 'current-password'])
+        ->assertRedirect($origin.'/settings')
+        ->assertSessionHas('info');
+
+    // one-shot flag は消費済み (次回 step-up に持ち越さない)
+    expect(session('recent_auth.dropped_mutation'))->toBeNull();
+});
+
 test('stale な recent_auth_at (timeout 超過) はブロックされる', function (): void {
     $user = User::factory()->create();
     $timeout = config()->integer('auth.recent_auth_timeout');
diff --git a/tests/Feature/Settings/PasswordSetupTest.php b/tests/Feature/Settings/PasswordSetupTest.php
new file mode 100644
index 0000000..83ca8ec
--- /dev/null
+++ b/tests/Feature/Settings/PasswordSetupTest.php
@@ -0,0 +1,222 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\SecurityAuditEvent;
+use App\Models\User;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Hash;
+use Inertia\Testing\AssertableInertia;
+
+/*
+ * パスワード**初回設定** (POST /settings/password。T107 施策 6)。
+ *
+ * これが無いと「パスワードを設定してください」と案内する CTA (ログイン手段保持 guard の
+ * 拒否 Alert / 再認証モーダルの回復導線) がどこにも着地せず、踏破不能 CTA になる (監査 F-2)。
+ *
+ * 設計上の非交渉点:
+ *  - **recent-auth (step-up) 必須**: 認証手段を永続的に増やす操作であり、
+ *    セッション奪取からの乗っ取り永続化を防ぐ。付与漏れの機械的検出点は
+ *    tests/Architecture/RecentAuthRouteTest.php の allowlist。
+ *  - **password 設定済みは fail-closed で拒否**: current_password 必須の変更経路
+ *    (Fortify PUT /user/password) を骨抜きにしない。
+ *  - **EnsureLoginMethodRemains は付けない**: あれは手段を「減らす」操作の関門であり方向が逆。
+ */
+
+const STRONG_PASSWORD = 'Str0ngPassphrase99';
+
+/** password 未設定 (SSO-only) ユーザー */
+function passwordlessUser(): User
+{
+    return User::factory()->ssoOnly()->create();
+}
+
+/* ------------------------------------------------------------ 正常系 */
+
+test('password 未設定 + recent-auth fresh なら設定できる', function (): void {
+    $user = passwordlessUser();
+    expect($user->hasPassword())->toBeFalse();
+
+    $response = $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings'))
+        ->post('/settings/password', ['password' => STRONG_PASSWORD]);
+
+    $response->assertRedirect(route('settings'));
+    $response->assertSessionHas('success');
+
+    $user->refresh();
+    expect($user->hasPassword())->toBeTrue();
+    expect(Hash::check(STRONG_PASSWORD, (string) $user->password))->toBeTrue();
+});
+
+test('設定成功で password_set の監査イベントが 1 件記録される', function (): void {
+    $user = passwordlessUser();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings'))
+        ->post('/settings/password', ['password' => STRONG_PASSWORD])
+        ->assertSessionHasNoErrors();
+
+    $events = SecurityAuditEvent::query()
+        ->where('event_type', 'password_set')
+        ->where('user_id', $user->getKey())
+        ->get();
+
+    expect($events)->toHaveCount(1);
+});
+
+/*
+ * 他デバイス失効 (PasswordCredentialService::afterPersist)。
+ * password material の確定は変更時と同じ意味を持つため、初回設定でも他デバイスを切る。
+ */
+test('設定成功で他デバイスの session 行が削除される (現在の session は残る)', function (): void {
+    config()->set('session.driver', 'database');
+    $user = passwordlessUser();
+
+    $this->actingAs($user)->withSession(freshRecentAuthSession())->get(route('settings'));
+    $currentSessionId = session()->getId();
+
+    // 他デバイスの session 行を模す
+    DB::table('sessions')->insert([
+        'id' => 'other-device-session-id',
+        'user_id' => $user->getKey(),
+        'ip_address' => '203.0.113.1',
+        'user_agent' => 'other',
+        'payload' => base64_encode(serialize([])),
+        'last_activity' => now()->timestamp,
+    ]);
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings'))
+        ->post('/settings/password', ['password' => STRONG_PASSWORD])
+        ->assertSessionHasNoErrors();
+
+    expect(DB::table('sessions')->where('id', 'other-device-session-id')->exists())->toBeFalse();
+    expect($currentSessionId)->not->toBe('other-device-session-id');
+});
+
+/* ------------------------------------------------------------ fail-closed */
+
+test('password 設定済みユーザーは 422 で拒否され hash が変わらない', function (): void {
+    $user = User::factory()->create(['password' => Hash::make('existing-password')]);
+    $before = (string) $user->password;
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings'))
+        ->post('/settings/password', ['password' => STRONG_PASSWORD])
+        ->assertSessionHasErrors('password');
+
+    $user->refresh();
+    expect((string) $user->password)->toBe($before);
+    expect(Hash::check('existing-password', (string) $user->password))->toBeTrue();
+});
+
+test('password 設定済みの純 XHR は 422 (JSON)', function (): void {
+    $user = User::factory()->create(['password' => Hash::make('existing-password')]);
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->postJson('/settings/password', ['password' => STRONG_PASSWORD])
+        ->assertStatus(422)
+        ->assertJsonValidationErrors('password');
+});
+
+/* ------------------------------------------------------------ step-up 必須 */
+
+test('recent-auth 無しの Inertia POST は 409 recent_auth_required', function (): void {
+    $user = passwordlessUser();
+
+    $this->actingAs($user)
+        ->withHeaders(['X-Inertia' => 'true'])
+        ->post('/settings/password', ['password' => STRONG_PASSWORD])
+        ->assertStatus(409)
+        ->assertJsonPath('code', 'recent_auth_required')
+        ->assertJsonPath('redirect', route('recent-auth.confirm'));
+
+    expect($user->refresh()->hasPassword())->toBeFalse();
+});
+
+test('recent-auth 無しの通常 POST は confirm 画面へ 302 (intended 保持)', function (): void {
+    $user = passwordlessUser();
+    $origin = config('app.url');
+
+    $this->actingAs($user)
+        ->withHeaders(['referer' => $origin.'/settings'])
+        ->post('/settings/password', ['password' => STRONG_PASSWORD])
+        ->assertRedirect(route('recent-auth.confirm'));
+
+    expect(session('url.intended'))->toBe($origin.'/settings');
+    expect($user->refresh()->hasPassword())->toBeFalse();
+});
+
+test('未認証は login へ redirect', function (): void {
+    $this->post('/settings/password', ['password' => STRONG_PASSWORD])
+        ->assertRedirect(route('login'));
+});
+
+/* ------------------------------------------------------------ 入力検証 / throttle */
+
+test('弱いパスワードは 422 (PasswordPolicy 経由)', function (): void {
+    $user = passwordlessUser();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings'))
+        ->post('/settings/password', ['password' => 'short'])
+        ->assertSessionHasErrors('password');
+
+    expect($user->refresh()->hasPassword())->toBeFalse();
+});
+
+test('throttle 超過で 429 (6/分)', function (): void {
+    $user = passwordlessUser();
+
+    for ($i = 0; $i < 6; $i++) {
+        $this->actingAs($user)
+            ->withSession(freshRecentAuthSession())
+            ->from(route('settings'))
+            ->post('/settings/password', ['password' => 'short']);
+    }
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings'))
+        ->post('/settings/password', ['password' => 'short'])
+        ->assertStatus(429);
+});
+
+/* ------------------------------------------------------------ 画面側の出し分け根拠 */
+
+test('/settings の Inertia prop に hasPassword が載る (カードの出し分け根拠)', function (): void {
+    $withPassword = User::factory()->create();
+    $this->actingAs($withPassword)
+        ->get(route('settings'))
+        ->assertInertia(fn (AssertableInertia $page) => $page
+            ->component('Settings/Index')
+            ->where('hasPassword', true));
+
+    $withoutPassword = passwordlessUser();
+    $this->actingAs($withoutPassword)
+        ->get(route('settings'))
+        ->assertInertia(fn (AssertableInertia $page) => $page
+            ->component('Settings/Index')
+            ->where('hasPassword', false));
+});
+
+test('設定後に再訪すると hasPassword が true になる (状態と UI が一致する)', function (): void {
+    $user = passwordlessUser();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings'))
+        ->post('/settings/password', ['password' => STRONG_PASSWORD])
+        ->assertSessionHasNoErrors();
+
+    $this->actingAs($user->refresh())
+        ->get(route('settings'))
+        ->assertInertia(fn (AssertableInertia $page) => $page->where('hasPassword', true));
+});
diff --git a/tests/js/architecture/logout-call-site-inventory.test.ts b/tests/js/architecture/logout-call-site-inventory.test.ts
index a6ea212..eb63d8e 100644
--- a/tests/js/architecture/logout-call-site-inventory.test.ts
+++ b/tests/js/architecture/logout-call-site-inventory.test.ts
@@ -23,13 +23,15 @@ const JS_ROOT = path.resolve(__dirname, "../../../resources/js");
  * `/logout` を参照してよいファイル (resources/js からの相対パス)。
  * 現状 3 箇所あり、いずれも router.post = Inertia visit
  * (AppLayout: 通常画面のユーザーメニュー / VerifyEmail: メール認証待ち画面の離脱導線 /
- *  ConfirmRecentAuth: 再認証手段が無いユーザーの回復導線 = ログアウトして guest として
- *  パスワードを再設定する。/forgot-password は guest middleware 付きで直リンクできない)。
+ *  RecentAuthRecoveryNotice: 再認証手段が無いユーザーの回復導線 = ログアウトして guest として
+ *  パスワードを再設定する。/forgot-password は guest middleware 付きで直リンクできない。
+ *  全画面 confirm (pages/Auth/ConfirmRecentAuth) とインラインモーダル
+ *  (organisms/RecentAuthModal) の双方が本 molecule を使う)。
  */
 const LOGOUT_CALL_SITE_INVENTORY: readonly string[] = [
   "components/templates/AppLayout.svelte",
   "pages/Auth/VerifyEmail.svelte",
-  "pages/Auth/ConfirmRecentAuth.svelte",
+  "components/molecules/RecentAuthRecoveryNotice.svelte",
 ] as const;
 
 const LOGOUT_PATH_PATTERN = /["'`]\/logout["'`]/;
diff --git a/tests/js/architecture/recent-auth-modal-call-site-inventory.test.ts b/tests/js/architecture/recent-auth-modal-call-site-inventory.test.ts
new file mode 100644
index 0000000..f5a2955
--- /dev/null
+++ b/tests/js/architecture/recent-auth-modal-call-site-inventory.test.ts
@@ -0,0 +1,119 @@
+import { describe, it, expect } from "vitest";
+import fs from "fs/promises";
+import path from "path";
+
+/**
+ * RecentAuthModal の props 契約を deny-by-default で固定する。
+ *
+ * T106 で `passkeyAvailable` を optional prop として足した結果、6 呼び出し中 5 箇所が
+ * 未配線のまま出荷され、passkey-only ユーザーが 5 画面で「手段 0 + 事実に反する文言」で
+ * 詰んだ (監査 F-1)。pnpm typecheck は `tsc --noEmit` で .svelte テンプレートを型検査しないため、
+ * 必須 prop 化だけでは配線漏れを止められない。ここが唯一の機械的強制点になる。
+ *
+ * 新しい呼び出し側を足す場合は、`withRecentAuth` の onStale で受けた status を
+ * `recentAuthStatus` に格納し、`status={recentAuthStatus}` で渡した上で inventory に登録すること。
+ *
+ * 既知の限界: 検出は文字列パターンに依存する。JSX 的な spread ({...props}) や
+ * 動的コンポーネント経由の描画は検出できない。導入する際は本テストも同時に更新すること。
+ */
+
+const JS_ROOT = path.resolve(__dirname, "../../../resources/js");
+
+/** RecentAuthModal を使ってよいファイル (resources/js からの相対パス) */
+const RECENT_AUTH_MODAL_CALL_SITES: readonly string[] = [
+  "pages/Settings/Index.svelte",
+  "pages/Settings/Security.svelte",
+  "pages/Organizations/Settings.svelte",
+  "pages/Organizations/ApiKeys/Index.svelte",
+  "pages/Organizations/ApiKeys/Sessions.svelte",
+  "pages/Admin/Users.svelte",
+] as const;
+
+/**
+ * 主検出は **タグ出現**。alias import (`@/components/...`) だけを見ると
+ * 相対 import で bypass できるため、import 形に依存しない検出を主にする。
+ */
+const TAG_PATTERN = /<RecentAuthModal\b[^>]*>/g;
+/** 補助検出 (import だけして別名で描画する形も未登録として拾う) */
+const IMPORT_PATTERN = /import\s+\w+\s+from\s+["'][^"']*RecentAuthModal\.svelte["']/;
+/** status は識別子まで固定する (任意式・undefined・即席オブジェクトを許さない) */
+const STATUS_PROP_PATTERN = /status=\{recentAuthStatus\}/;
+/** 契約統合前の旧 prop (後方互換の並走を残さない) */
+const LEGACY_PROPS: readonly string[] = [
+  "passwordSet=",
+  "availableProviders=",
+  "canSatisfy=",
+  "passkeyAvailable=",
+] as const;
+/** status の出所を /recent-auth/status 1 本に固定する */
+const WITH_RECENT_AUTH_PATTERN = /withRecentAuth/;
+/** onStale の引数を recentAuthStatus に格納していること (存在確認だけでは不十分) */
+const ON_STALE_ASSIGNMENT_PATTERN =
+  /onStale:\s*\(status\)\s*=>\s*\{[^}]*recentAuthStatus\s*=\s*status/s;
+
+const SOURCE_EXTENSIONS: readonly string[] = [".svelte", ".ts"] as const;
+
+/** resources/js 配下の .svelte / .ts を再帰列挙する (logout-call-site-inventory と同じ様式)。 */
+const listSourceFiles = async (dir: string): Promise<string[]> => {
+  const entries = await fs.readdir(dir, { recursive: true, withFileTypes: true });
+  const files: string[] = [];
+  for (const entry of entries) {
+    if (!entry.isFile()) continue;
+    if (!SOURCE_EXTENSIONS.includes(path.extname(entry.name))) continue;
+    const parent = (entry as unknown as { parentPath?: string }).parentPath ?? dir;
+    files.push(path.join(parent, entry.name));
+  }
+  return files;
+};
+
+describe("recent-auth modal call site inventory", () => {
+  it("RecentAuthModal を描画/import するのは inventory 登録分のみ", async () => {
+    const files = await listSourceFiles(JS_ROOT);
+    const offenders: string[] = [];
+    for (const file of files) {
+      const content = await fs.readFile(file, "utf8");
+      // タグ出現 (主) と import (補助) の和で検出する (import 形に依存しない)
+      const hasTag = TAG_PATTERN.test(content);
+      TAG_PATTERN.lastIndex = 0; // /g の状態を持ち越さない
+      if (!hasTag && !IMPORT_PATTERN.test(content)) continue;
+      const rel = path.relative(JS_ROOT, file).split(path.sep).join("/");
+      if (!RECENT_AUTH_MODAL_CALL_SITES.includes(rel)) offenders.push(rel);
+    }
+    expect(
+      offenders,
+      `未登録の RecentAuthModal 呼び出しが見つかりました。status={recentAuthStatus} で渡していることを確認して inventory へ登録してください:\n${offenders.join("\n")}`,
+    ).toEqual([]);
+  });
+
+  it("全呼び出しが status={recentAuthStatus} を渡し、旧 prop を渡さない", async () => {
+    for (const rel of RECENT_AUTH_MODAL_CALL_SITES) {
+      const content = await fs.readFile(path.join(JS_ROOT, rel), "utf8");
+      const tags = content.match(TAG_PATTERN) ?? [];
+      expect(tags.length, `${rel} に <RecentAuthModal> が無い`).toBeGreaterThan(0);
+      for (const tag of tags) {
+        expect(tag, `${rel} が status={recentAuthStatus} を渡していない`).toMatch(
+          STATUS_PROP_PATTERN,
+        );
+        for (const legacy of LEGACY_PROPS) {
+          expect(
+            tag.includes(legacy),
+            `${rel} が旧 prop ${legacy} を渡している (契約は status 1 本)`,
+          ).toBe(false);
+        }
+      }
+    }
+  });
+
+  it("status の出所は withRecentAuth の onStale に固定される", async () => {
+    for (const rel of RECENT_AUTH_MODAL_CALL_SITES) {
+      const content = await fs.readFile(path.join(JS_ROOT, rel), "utf8");
+      expect(content, `${rel} が withRecentAuth を使っていない`).toMatch(
+        WITH_RECENT_AUTH_PATTERN,
+      );
+      expect(
+        content,
+        `${rel} が onStale で受けた status を recentAuthStatus に格納していない (画面ごとの独自判定を作らない)`,
+      ).toMatch(ON_STALE_ASSIGNMENT_PATTERN);
+    }
+  });
+});
diff --git a/tests/js/components/molecules/RecentAuthRecoveryNotice.test.ts b/tests/js/components/molecules/RecentAuthRecoveryNotice.test.ts
new file mode 100644
index 0000000..68cade7
--- /dev/null
+++ b/tests/js/components/molecules/RecentAuthRecoveryNotice.test.ts
@@ -0,0 +1,87 @@
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
+
+/*
+ * 再認証が成立しないユーザーの回復導線 (施策 5)。
+ *
+ * 全画面 confirm とインラインモーダルの**唯一の実装**であることが要点。
+ * `/forgot-password` は Fortify が guest middleware 付きで登録しており、ログイン済みの
+ * 本 UI 利用者はフォームに到達できない = 踏破不能 CTA (監査 F-2a)。
+ * 踏破できる回復手順は「ログアウトしてから guest としてリセット」だけ。
+ */
+
+const { routerPostMock } = vi.hoisted(() => ({ routerPostMock: vi.fn() }));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    router: { post: routerPostMock, visit: vi.fn(), reload: vi.fn() },
+}));
+
+import RecentAuthRecoveryNotice from "@/components/molecules/RecentAuthRecoveryNotice.svelte";
+
+const linkHrefs = (): string[] =>
+    screen.queryAllByRole("link").map((a) => (a as HTMLAnchorElement).getAttribute("href") ?? "");
+
+beforeEach(() => {
+    routerPostMock.mockReset();
+});
+
+afterEach(() => {
+    cleanup();
+});
+
+describe("RecentAuthRecoveryNotice", () => {
+    it("variant=no-satisfier は手段なしの理由とログアウト導線を出す", () => {
+        render(RecentAuthRecoveryNotice, { props: { variant: "no-satisfier" } });
+
+        expect(screen.getByTestId("recent-auth-recovery")).toBeInTheDocument();
+        expect(screen.getByTestId("recent-auth-recovery")).toHaveTextContent(
+            "再認証手段が設定されていません",
+        );
+        expect(screen.getByRole("button", { name: "ログアウトする" })).toBeInTheDocument();
+    });
+
+    it("variant=not-executable-here はこの端末で実行できない理由とログアウト導線を出す", () => {
+        render(RecentAuthRecoveryNotice, { props: { variant: "not-executable-here" } });
+
+        expect(screen.getByTestId("recent-auth-unsupported-here")).toBeInTheDocument();
+        expect(screen.getByTestId("recent-auth-unsupported-here")).toHaveTextContent(
+            "このブラウザはパスキーに対応していません",
+        );
+        expect(screen.getByRole("button", { name: "ログアウトする" })).toBeInTheDocument();
+    });
+
+    it("踏破不能な /forgot-password へリンクしない (両 variant)", () => {
+        render(RecentAuthRecoveryNotice, { props: { variant: "no-satisfier" } });
+        expect(linkHrefs()).not.toContain("/forgot-password");
+        cleanup();
+
+        render(RecentAuthRecoveryNotice, { props: { variant: "not-executable-here" } });
+        expect(linkHrefs()).not.toContain("/forgot-password");
+    });
+
+    it("ログアウトは Inertia visit (router.post) で 1 回だけ送る", async () => {
+        render(RecentAuthRecoveryNotice, { props: { variant: "no-satisfier" } });
+
+        await fireEvent.click(screen.getByRole("button", { name: "ログアウトする" }));
+
+        expect(routerPostMock).toHaveBeenCalledTimes(1);
+        expect(routerPostMock).toHaveBeenCalledWith("/logout", {}, expect.anything());
+    });
+
+    it("送信中の連打では 2 回目を送らない (二重送信ガード)", async () => {
+        // onStart を呼ぶだけで onFinish を呼ばない = 送信中のまま
+        routerPostMock.mockImplementation(
+            (_url: string, _data: unknown, options: { onStart?: () => void }) => {
+                options.onStart?.();
+            },
+        );
+        render(RecentAuthRecoveryNotice, { props: { variant: "no-satisfier" } });
+
+        const button = screen.getByRole("button", { name: "ログアウトする" });
+        await fireEvent.click(button);
+        await fireEvent.click(button);
+
+        expect(routerPostMock).toHaveBeenCalledTimes(1);
+    });
+});
diff --git a/tests/js/components/organisms/RecentAuthModal.test.ts b/tests/js/components/organisms/RecentAuthModal.test.ts
new file mode 100644
index 0000000..e788c72
--- /dev/null
+++ b/tests/js/components/organisms/RecentAuthModal.test.ts
@@ -0,0 +1,231 @@
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
+
+/*
+ * RecentAuthModal の props 契約 (施策 1)。
+ *
+ * `/recent-auth/status` の応答を **1 個の型 (status)** で受ける。field へ分解して手渡す形は
+ * field が増えるたびに配線漏れを生む (T106 で passkeyAvailable を足した際、6 呼び出し中
+ * 5 箇所が未配線のまま出荷され passkey-only ユーザーが 5 画面で詰んだ = 監査 F-1)。
+ *
+ * `status === null` は「状態不明」であり、空表示にも事実に反する文言にも倒さない。
+ */
+
+const { routerReloadMock, confirmWithPasskeyMock } = vi.hoisted(() => ({
+    routerReloadMock: vi.fn(),
+    confirmWithPasskeyMock: vi.fn(),
+}));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    router: { post: vi.fn(), visit: vi.fn(), reload: routerReloadMock },
+}));
+
+vi.mock("@/lib/passkeys", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@/lib/passkeys")>()),
+    confirmWithPasskey: confirmWithPasskeyMock,
+}));
+
+import RecentAuthModal from "@/components/organisms/RecentAuthModal.svelte";
+import type { RecentAuthStatus } from "@/lib/recent-auth";
+
+function status(overrides: Partial<RecentAuthStatus> = {}): RecentAuthStatus {
+    return {
+        recent: false,
+        passwordSet: false,
+        availableProviders: [],
+        passkeyAvailable: false,
+        canSatisfy: false,
+        confirmedAt: null,
+        ...overrides,
+    };
+}
+
+function stubPasskeySupport(): void {
+    Object.defineProperty(globalThis, "navigator", {
+        configurable: true,
+        value: { credentials: { create: vi.fn(), get: vi.fn() } },
+    });
+    Object.defineProperty(window, "PublicKeyCredential", {
+        configurable: true,
+        writable: true,
+        value: function PublicKeyCredentialStub() {
+            // instanceof 判定にのみ使う
+        },
+    });
+}
+
+function removePasskeySupport(): void {
+    Object.defineProperty(window, "PublicKeyCredential", {
+        configurable: true,
+        writable: true,
+        value: undefined,
+    });
+}
+
+beforeEach(() => {
+    stubPasskeySupport();
+    routerReloadMock.mockReset();
+    confirmWithPasskeyMock.mockReset();
+});
+
+afterEach(() => {
+    cleanup();
+    removePasskeySupport();
+});
+
+describe("RecentAuthModal props 契約", () => {
+    it("status.passkeyAvailable=true + 対応ブラウザでパスキー導線を出す", async () => {
+        render(RecentAuthModal, {
+            props: {
+                open: true,
+                status: status({ passkeyAvailable: true, canSatisfy: true }),
+                onConfirmed: vi.fn(),
+            },
+        });
+
+        expect(await screen.findByTestId("recent-auth-passkey")).toBeInTheDocument();
+    });
+
+    it("status.passwordSet=true でパスワード再入力フォームを出す", async () => {
+        render(RecentAuthModal, {
+            props: {
+                open: true,
+                status: status({ passwordSet: true, canSatisfy: true }),
+                onConfirmed: vi.fn(),
+            },
+        });
+
+        expect(await screen.findByTestId("recent-auth-password-input")).toBeInTheDocument();
+    });
+
+    it("passkey のみ + 非対応ブラウザなら回復導線を出す (無言の行き止まりにしない)", async () => {
+        removePasskeySupport();
+        render(RecentAuthModal, {
+            props: {
+                open: true,
+                status: status({ passkeyAvailable: true, canSatisfy: true }),
+                onConfirmed: vi.fn(),
+            },
+        });
+
+        expect(await screen.findByTestId("recent-auth-unsupported-here")).toBeInTheDocument();
+        expect(screen.queryByTestId("recent-auth-passkey")).toBeNull();
+    });
+
+    it("canSatisfy=false なら手段なしの回復導線を出す", async () => {
+        render(RecentAuthModal, {
+            props: { open: true, status: status({ canSatisfy: false }), onConfirmed: vi.fn() },
+        });
+
+        expect(await screen.findByTestId("recent-auth-recovery")).toBeInTheDocument();
+    });
+
+    it("回復導線は踏破不能な /forgot-password へリンクしない", async () => {
+        render(RecentAuthModal, {
+            props: { open: true, status: status({ canSatisfy: false }), onConfirmed: vi.fn() },
+        });
+
+        await screen.findByTestId("recent-auth-recovery");
+        const hrefs = screen
+            .queryAllByRole("link")
+            .map((a) => (a as HTMLAnchorElement).getAttribute("href") ?? "");
+        expect(hrefs).not.toContain("/forgot-password");
+    });
+});
+
+describe("RecentAuthModal status=null (状態不明)", () => {
+    it("取得失敗として明示し再読み込み導線を出す (空表示にしない)", async () => {
+        render(RecentAuthModal, {
+            props: { open: true, status: null, onConfirmed: vi.fn() },
+        });
+
+        expect(await screen.findByTestId("recent-auth-unknown")).toBeInTheDocument();
+        expect(screen.getByRole("button", { name: "再読み込み" })).toBeInTheDocument();
+    });
+
+    it("password フォーム / SSO / パスキー / 回復 notice のいずれも出さない (誤った導線に倒さない)", async () => {
+        render(RecentAuthModal, {
+            props: { open: true, status: null, onConfirmed: vi.fn() },
+        });
+
+        await screen.findByTestId("recent-auth-unknown");
+        expect(screen.queryByTestId("recent-auth-password-input")).toBeNull();
+        expect(screen.queryByTestId("recent-auth-passkey")).toBeNull();
+        expect(screen.queryByTestId("recent-auth-recovery")).toBeNull();
+        expect(screen.queryByTestId("recent-auth-unsupported-here")).toBeNull();
+    });
+});
+
+describe("RecentAuthModal エラー提示の分離 (施策 9)", () => {
+    /*
+     * password エラーと passkey ceremony エラーが同一 state を共有していたため、
+     * **パスキー失敗が「現在のパスワード」欄のフィールドエラーとして表示**されていた
+     * (原因と提示先が食い違う)。非フィールド起因は Alert に分離する。
+     */
+    it("passkey ceremony 失敗は Alert に出て、password フィールドを invalid にしない", async () => {
+        confirmWithPasskeyMock.mockResolvedValue({ status: "failed", message: "ceremony に失敗" });
+        render(RecentAuthModal, {
+            props: {
+                open: true,
+                status: status({ passwordSet: true, passkeyAvailable: true, canSatisfy: true }),
+                onConfirmed: vi.fn(),
+            },
+        });
+
+        await fireEvent.click(await screen.findByTestId("recent-auth-passkey"));
+
+        const alert = await screen.findByTestId("recent-auth-passkey-error");
+        expect(alert).toHaveTextContent("ceremony に失敗");
+        expect(screen.getByTestId("recent-auth-password-input")).not.toHaveAttribute(
+            "aria-invalid",
+            "true",
+        );
+    });
+
+    it("パスワード誤りは FormField に出て、passkey の Alert を出さない", async () => {
+        const fetchMock = vi.fn(() =>
+            Promise.resolve({
+                status: 422,
+                json: () => Promise.resolve({ errors: { password: ["パスワードが正しくありません。"] } }),
+            }),
+        );
+        vi.stubGlobal("fetch", fetchMock);
+        render(RecentAuthModal, {
+            props: {
+                open: true,
+                status: status({ passwordSet: true, passkeyAvailable: true, canSatisfy: true }),
+                onConfirmed: vi.fn(),
+            },
+        });
+
+        await fireEvent.input(await screen.findByTestId("recent-auth-password-input"), {
+            target: { value: "wrong-password" },
+        });
+        await fireEvent.submit(
+            screen.getByTestId("recent-auth-submit").closest("form") as HTMLFormElement,
+        );
+
+        await waitFor(() =>
+            expect(screen.getByText("パスワードが正しくありません。")).toBeInTheDocument(),
+        );
+        expect(screen.queryByTestId("recent-auth-passkey-error")).toBeNull();
+        vi.unstubAllGlobals();
+    });
+
+    it("キャンセルは Alert を出さない (騒がない)", async () => {
+        confirmWithPasskeyMock.mockResolvedValue({ status: "cancelled" });
+        render(RecentAuthModal, {
+            props: {
+                open: true,
+                status: status({ passkeyAvailable: true, canSatisfy: true }),
+                onConfirmed: vi.fn(),
+            },
+        });
+
+        await fireEvent.click(await screen.findByTestId("recent-auth-passkey"));
+
+        await waitFor(() => expect(confirmWithPasskeyMock).toHaveBeenCalled());
+        expect(screen.queryByTestId("recent-auth-passkey-error")).toBeNull();
+    });
+});
diff --git a/tests/js/lib/recent-auth.test.ts b/tests/js/lib/recent-auth.test.ts
new file mode 100644
index 0000000..dbdaed3
--- /dev/null
+++ b/tests/js/lib/recent-auth.test.ts
@@ -0,0 +1,258 @@
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+
+/*
+ * recent-auth クライアントヘルパの契約 (施策 3 / 4)。
+ *
+ * 施策 3: `/recent-auth/status` は **strict parse**。既定値で補完しない。
+ *   field が欠けた応答を既定値で埋めると「サーバは手段があると言っているのに UI に出ない」
+ *   = 監査 F-1 と同じ詰みが通信境界で再演する (call-site gate では検出できない)。
+ *   契約不成立は null にし、delegated (サーバの最終ゲートへ委譲) に倒す。
+ *
+ * 施策 4: 409 `recent_auth_required` を confirm 画面への Inertia visit に変換する単一ハンドラ。
+ *   購読するのは @inertiajs/core 3.x の `httpException` (v1/v2 の `invalid` の後継)。
+ *   受入条件を満たさない応答は preventDefault せず Inertia 既定処理に渡す (fail-closed)。
+ */
+
+const { routerOnMock, routerVisitMock } = vi.hoisted(() => ({
+    routerOnMock: vi.fn(),
+    routerVisitMock: vi.fn(),
+}));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    router: { on: routerOnMock, visit: routerVisitMock, post: vi.fn() },
+}));
+
+const { addToastMock } = vi.hoisted(() => ({ addToastMock: vi.fn() }));
+
+vi.mock("@/lib/stores/toast", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@/lib/stores/toast")>()),
+    addToast: addToastMock,
+}));
+
+import {
+    fetchRecentAuthStatus,
+    parseRecentAuthStatus,
+    registerRecentAuthRedirectHandler,
+    withRecentAuth,
+} from "@/lib/recent-auth";
+
+const VALID_BODY = {
+    recent: false,
+    passwordSet: true,
+    availableProviders: [
+        { provider: "google", capability: "reauth", reauthUrl: "/auth/google/redirect/step-up" },
+    ],
+    passkeyAvailable: true,
+    canSatisfy: true,
+    confirmedAt: null,
+};
+
+/** fetch を 1 応答でスタブする */
+function stubFetch(body: unknown, ok = true, status = 200): void {
+    vi.stubGlobal(
+        "fetch",
+        vi.fn(() => Promise.resolve({ ok, status, json: () => Promise.resolve(body) })),
+    );
+}
+
+beforeEach(() => {
+    routerOnMock.mockReset();
+    routerVisitMock.mockReset();
+    addToastMock.mockReset();
+});
+
+afterEach(() => {
+    vi.unstubAllGlobals();
+});
+
+describe("parseRecentAuthStatus (strict parse)", () => {
+    it("完全な応答は各 field が写る", () => {
+        expect(parseRecentAuthStatus(VALID_BODY)).toEqual(VALID_BODY);
+    });
+
+    it("未知キーが増えても壊れない (サーバの field 追加に耐える)", () => {
+        expect(parseRecentAuthStatus({ ...VALID_BODY, futureField: 1 })).toEqual(VALID_BODY);
+    });
+
+    it("confirmedAt が number でも通る", () => {
+        const body = { ...VALID_BODY, recent: true, confirmedAt: 1700000000 };
+        expect(parseRecentAuthStatus(body)?.confirmedAt).toBe(1700000000);
+    });
+
+    it.each([
+        ["recent", undefined],
+        ["recent", "yes"],
+        ["passwordSet", undefined],
+        ["passwordSet", 1],
+        ["passkeyAvailable", undefined],
+        ["passkeyAvailable", "true"],
+        ["canSatisfy", undefined],
+        ["canSatisfy", null],
+        ["confirmedAt", "1700000000"],
+        ["availableProviders", undefined],
+        ["availableProviders", {}],
+    ])("top-level %s の欠損・型不一致は null (既定値で補完しない)", (key, value) => {
+        const body: Record<string, unknown> = { ...VALID_BODY };
+        if (value === undefined) {
+            delete body[key];
+        } else {
+            body[key] = value;
+        }
+        expect(parseRecentAuthStatus(body)).toBeNull();
+    });
+
+    it.each(["provider", "capability", "reauthUrl"])(
+        "provider 要素の %s 欠損は null (SSO ボタンが消える詰みを防ぐ)",
+        (key) => {
+            const element: Record<string, unknown> = { ...VALID_BODY.availableProviders[0] };
+            delete element[key];
+            expect(
+                parseRecentAuthStatus({ ...VALID_BODY, availableProviders: [element] }),
+            ).toBeNull();
+        },
+    );
+
+    it("provider 要素が非オブジェクトなら null", () => {
+        expect(
+            parseRecentAuthStatus({ ...VALID_BODY, availableProviders: ["google"] }),
+        ).toBeNull();
+    });
+
+    it("body が非オブジェクト / null なら null", () => {
+        expect(parseRecentAuthStatus(null)).toBeNull();
+        expect(parseRecentAuthStatus("recent")).toBeNull();
+    });
+});
+
+describe("fetchRecentAuthStatus", () => {
+    it("200 + 契約充足なら status を返す", async () => {
+        stubFetch(VALID_BODY);
+        await expect(fetchRecentAuthStatus()).resolves.toEqual(VALID_BODY);
+    });
+
+    it("res.ok=false なら null", async () => {
+        stubFetch(VALID_BODY, false, 500);
+        await expect(fetchRecentAuthStatus()).resolves.toBeNull();
+    });
+
+    it("JSON パース失敗なら null", async () => {
+        vi.stubGlobal(
+            "fetch",
+            vi.fn(() =>
+                Promise.resolve({ ok: true, status: 200, json: () => Promise.reject(new Error()) }),
+            ),
+        );
+        await expect(fetchRecentAuthStatus()).resolves.toBeNull();
+    });
+
+    it("契約不成立 (field 欠損) なら null", async () => {
+        const { passkeyAvailable: _drop, ...partial } = VALID_BODY;
+        stubFetch(partial);
+        await expect(fetchRecentAuthStatus()).resolves.toBeNull();
+    });
+});
+
+describe("withRecentAuth", () => {
+    it("契約不成立なら delegated を返し onStale を呼ばない", async () => {
+        const { canSatisfy: _drop, ...partial } = VALID_BODY;
+        stubFetch(partial);
+        const onFresh = vi.fn();
+        const onStale = vi.fn();
+
+        await expect(withRecentAuth({ onFresh, onStale })).resolves.toBe("delegated");
+        expect(onStale).not.toHaveBeenCalled();
+        expect(onFresh).toHaveBeenCalledTimes(1);
+    });
+
+    it("recent=false なら stale で onStale に status を渡す", async () => {
+        stubFetch(VALID_BODY);
+        const onStale = vi.fn();
+
+        await expect(withRecentAuth({ onFresh: vi.fn(), onStale })).resolves.toBe("stale");
+        expect(onStale).toHaveBeenCalledWith(VALID_BODY);
+    });
+
+    it("recent=true なら fresh で onFresh を呼ぶ", async () => {
+        stubFetch({ ...VALID_BODY, recent: true, confirmedAt: 1 });
+        const onFresh = vi.fn();
+
+        await expect(withRecentAuth({ onFresh, onStale: vi.fn() })).resolves.toBe("fresh");
+        expect(onFresh).toHaveBeenCalledTimes(1);
+    });
+});
+
+describe("registerRecentAuthRedirectHandler (409 の単一ハンドラ)", () => {
+    /** router.on に登録された handler を取り出して疑似イベントを流す */
+    function dispatch(response: unknown): { prevented: boolean } {
+        let handler: ((event: unknown) => void) | null = null;
+        routerOnMock.mockImplementation((_type: string, cb: (event: unknown) => void) => {
+            handler = cb;
+            return () => {};
+        });
+        registerRecentAuthRedirectHandler();
+        expect(routerOnMock).toHaveBeenCalledWith("httpException", expect.any(Function));
+
+        let prevented = false;
+        const event = {
+            detail: { response },
+            preventDefault: () => {
+                prevented = true;
+            },
+        };
+        (handler as unknown as (e: unknown) => void)(event);
+        return { prevented };
+    }
+
+    const REQUIRED_409 = {
+        status: 409,
+        data: {
+            code: "recent_auth_required",
+            message: "この操作には直近の再認証が必要です。",
+            redirect: "http://localhost:3000/recent-auth/confirm",
+        },
+    };
+
+    it("409 + recent_auth_required + 同一 origin の confirm URL なら visit する", () => {
+        const { prevented } = dispatch(REQUIRED_409);
+
+        expect(prevented).toBe(true);
+        expect(routerVisitMock).toHaveBeenCalledWith("/recent-auth/confirm");
+    });
+
+    it.each([
+        ["別 code (誤食しない)", { ...REQUIRED_409, data: { ...REQUIRED_409.data, code: "scenario_conflict" } }],
+        ["別 code (2FA)", { ...REQUIRED_409, data: { ...REQUIRED_409.data, code: "two_factor_required" } }],
+        [
+            "外部 URL",
+            { ...REQUIRED_409, data: { ...REQUIRED_409.data, redirect: "https://evil.example/x" } },
+        ],
+        [
+            "別 route",
+            { ...REQUIRED_409, data: { ...REQUIRED_409.data, redirect: "/dashboard" } },
+        ],
+        ["redirect 欠損", { status: 409, data: { code: "recent_auth_required" } }],
+        [
+            "redirect が非文字列",
+            { ...REQUIRED_409, data: { ...REQUIRED_409.data, redirect: 1 } },
+        ],
+        ["422", { ...REQUIRED_409, status: 422 }],
+        ["500", { ...REQUIRED_409, status: 500 }],
+        ["data が文字列 (非 JSON 応答)", { status: 409, data: "<html>error</html>" }],
+        ["response が null", null],
+    ])("%s では preventDefault しない (Inertia 既定処理へ渡す)", (_label, response) => {
+        const { prevented } = dispatch(response);
+
+        expect(prevented).toBe(false);
+        expect(routerVisitMock).not.toHaveBeenCalled();
+    });
+
+    it("戻り値を呼ぶと購読解除される (HMR の二重登録防止)", () => {
+        const unsubscribe = vi.fn();
+        routerOnMock.mockReturnValue(unsubscribe);
+
+        registerRecentAuthRedirectHandler()();
+
+        expect(unsubscribe).toHaveBeenCalledTimes(1);
+    });
+});
diff --git a/tests/js/pages/ConfirmRecentAuthPasskey.test.ts b/tests/js/pages/ConfirmRecentAuthPasskey.test.ts
index b031ea0..80aa217 100644
--- a/tests/js/pages/ConfirmRecentAuthPasskey.test.ts
+++ b/tests/js/pages/ConfirmRecentAuthPasskey.test.ts
@@ -166,7 +166,7 @@ describe("Auth/ConfirmRecentAuth この端末では実行できない状態", ()
             },
         });
 
-        expect(screen.getByTestId("confirm-unsupported-here")).toBeInTheDocument();
+        expect(screen.getByTestId("recent-auth-unsupported-here")).toBeInTheDocument();
         expect(screen.getByRole("button", { name: "ログアウトする" })).toBeInTheDocument();
     });
 
@@ -180,7 +180,7 @@ describe("Auth/ConfirmRecentAuth この端末では実行できない状態", ()
             },
         });
 
-        expect(screen.queryByTestId("confirm-unsupported-here")).toBeNull();
+        expect(screen.queryByTestId("recent-auth-unsupported-here")).toBeNull();
         expect(screen.getByTestId("confirm-passkey-button")).toBeInTheDocument();
     });
 
@@ -196,7 +196,7 @@ describe("Auth/ConfirmRecentAuth この端末では実行できない状態", ()
             },
         });
 
-        expect(screen.queryByTestId("confirm-unsupported-here")).toBeNull();
+        expect(screen.queryByTestId("recent-auth-unsupported-here")).toBeNull();
         expect(screen.getByLabelText("現在のパスワード")).toBeInTheDocument();
     });
 });
diff --git a/tests/js/pages/OrganizationsSettings.test.ts b/tests/js/pages/OrganizationsSettings.test.ts
index 312e88c..9084e1c 100644
--- a/tests/js/pages/OrganizationsSettings.test.ts
+++ b/tests/js/pages/OrganizationsSettings.test.ts
@@ -156,6 +156,7 @@ describe("Organizations/Settings オーナー移譲の確定フロー (F-12)", (
                             recent,
                             passwordSet: true,
                             availableProviders: [],
+                            passkeyAvailable: false,
                             canSatisfy: true,
                             confirmedAt: recent ? 1 : null,
                         }),
@@ -217,6 +218,68 @@ describe("Organizations/Settings オーナー移譲の確定フロー (F-12)", (
         });
         expect(routerPostSpy).not.toHaveBeenCalled();
     });
+
+    /*
+     * **passkey-only ユーザーがこの画面で詰まないこと** (監査 F-1 の回帰)。
+     * T106 では `passkeyAvailable` が本画面のモーダルへ未配線で、パスキーしか持たない
+     * ユーザーには「手段 0 + 事実に反する文言」だけが出ていた。props 契約を `status` 1 本に
+     * 統合したことで、サーバの status がそのままモーダルへ届く。
+     * 残り 4 画面は tests/js/architecture/recent-auth-modal-call-site-inventory.test.ts が担保する。
+     */
+    it("passkey-only + stale なら再認証モーダルにパスキー導線が出る (詰まない)", async () => {
+        vi.spyOn(router, "post").mockImplementation(() => {});
+        vi.stubGlobal(
+            "fetch",
+            vi.fn().mockImplementation((input: RequestInfo | URL) => {
+                if (String(input).includes("/recent-auth/status")) {
+                    return Promise.resolve({
+                        ok: true,
+                        status: 200,
+                        json: () =>
+                            Promise.resolve({
+                                recent: false,
+                                passwordSet: false,
+                                availableProviders: [],
+                                passkeyAvailable: true,
+                                canSatisfy: true,
+                                confirmedAt: null,
+                            }),
+                    });
+                }
+                return Promise.reject(new Error(`unexpected fetch: ${String(input)}`));
+            }),
+        );
+        // WebAuthn 対応ブラウザを偽装する (非対応端末では回復導線に倒れるのが正)
+        Object.defineProperty(globalThis, "navigator", {
+            configurable: true,
+            value: { credentials: { create: vi.fn(), get: vi.fn() } },
+        });
+        Object.defineProperty(window, "PublicKeyCredential", {
+            configurable: true,
+            writable: true,
+            value: function PublicKeyCredentialStub() {
+                // instanceof 判定にのみ使う
+            },
+        });
+
+        render(Settings, { props: baseProps });
+
+        await fireEvent.change(screen.getByLabelText("移譲先のメンバー"), {
+            target: { value: "2" },
+        });
+        await fireEvent.click(screen.getByTestId("transfer-ownership-button"));
+        await fireEvent.click(screen.getByRole("button", { name: "移譲する" }));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("recent-auth-passkey")).toBeInTheDocument();
+        });
+
+        Object.defineProperty(window, "PublicKeyCredential", {
+            configurable: true,
+            writable: true,
+            value: undefined,
+        });
+    });
 });
 
 describe("Organizations/Settings オーナー移譲の client error 自動解消 (T044)", () => {
@@ -242,6 +305,7 @@ describe("Organizations/Settings オーナー移譲の client error 自動解消
                             recent,
                             passwordSet: true,
                             availableProviders: [],
+                            passkeyAvailable: false,
                             canSatisfy: true,
                             confirmedAt: recent ? 1 : null,
                         }),
diff --git a/tests/js/pages/SettingsIndex.test.ts b/tests/js/pages/SettingsIndex.test.ts
index 72794d3..ec61653 100644
--- a/tests/js/pages/SettingsIndex.test.ts
+++ b/tests/js/pages/SettingsIndex.test.ts
@@ -11,23 +11,28 @@ import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-li
  * - 削除 (router.delete) の onError はダイアログを閉じる (押下後に理由が見える)
  */
 
-const { pageState, routerDeleteMock, formHolder, formSeed } = vi.hoisted(() => ({
+const { pageState, routerDeleteMock, routerReloadMock, routerPostMock, formHolder, formSeed } =
+    vi.hoisted(() => ({
     pageState: {
         props: {} as Record<string, unknown>,
         url: "/settings",
     },
     routerDeleteMock: vi.fn(),
+    routerReloadMock: vi.fn(),
+    routerPostMock: vi.fn(),
     // useForm fake が捕捉する各 form の holder。初期データキーで二分岐する:
     //   "email" を持つ → profileForm (case 6 で put を検証)
     //   "current_password" を持つ → passwordForm (T042 S2 で put/errorBag を検証)
     formHolder: {
         profile: null as Record<string, unknown> | null,
         password: null as Record<string, unknown> | null,
+        // パスワード**初回設定** form (施策 7)。初期データが password 1 キーのみで判別する
+        passwordSetup: null as Record<string, unknown> | null,
     },
     // passwordForm の初期 errors シード。FormField は error があるときだけ
     // aria-describedby を生成するため、透過検証ケースだけがここに値を入れる。
     formSeed: { passwordErrors: {} as Record<string, string> },
-}));
+    }));
 
 vi.mock("@inertiajs/svelte", async (importOriginal) => {
     // password フォームは反応的 double を使う (clearErrors で errors が消える再描画、
@@ -36,7 +41,7 @@ vi.mock("@inertiajs/svelte", async (importOriginal) => {
     const { reactiveUseForm } = await import("../support/reactiveUseForm.svelte");
     return {
         ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
-        router: { delete: routerDeleteMock },
+        router: { delete: routerDeleteMock, reload: routerReloadMock, post: routerPostMock },
         page: pageState,
         // useForm を fake に差し替え、form を holder に記録する。
         //   "current_password" を持つ → passwordForm: 反応的 double
@@ -62,6 +67,9 @@ vi.mock("@inertiajs/svelte", async (importOriginal) => {
             };
             if ("email" in initial) {
                 formHolder.profile = form;
+            } else if ("password" in initial) {
+                // password 1 キーのみ = 初回設定 form (変更 form は current_password を持つ)
+                formHolder.passwordSetup = form;
             }
             return form;
         },
@@ -71,10 +79,18 @@ vi.mock("@inertiajs/svelte", async (importOriginal) => {
 // eslint-disable-next-line import/first
 import Index from "@/pages/Settings/Index.svelte";
 
+/**
+ * 既定は **password 設定済み** (hasPassword: true)。パスワードカードは施策 7 で
+ * `hasPassword` による 3 値出し分け (set / unset / unknown) になったため、
+ * 変更フォームを見るケースは明示的に設定済みを渡す必要がある。
+ * `setProps({ hasPassword: false })` で初回設定フォーム、
+ * `setProps({ hasPassword: undefined })` で状態不明になる。
+ */
 function setProps(extra: Record<string, unknown> = {}): void {
     pageState.props = {
         appName: "AI-CUE",
         auth: { user: { id: 1, name: "テスト太郎", email: "taro@example.com" } },
+        hasPassword: true,
         ...extra,
     };
 }
@@ -92,6 +108,7 @@ function stubRecentAuthFresh(): void {
                         recent: true,
                         passwordSet: true,
                         availableProviders: [],
+                        passkeyAvailable: false,
                         canSatisfy: true,
                         confirmedAt: 1,
                     }),
@@ -118,6 +135,7 @@ function stubRecentAuthStaleThenConfirm(): void {
                             recent: false,
                             passwordSet: true,
                             availableProviders: [],
+                            passkeyAvailable: false,
                             canSatisfy: true,
                             confirmedAt: null,
                         }),
@@ -142,6 +160,7 @@ beforeEach(() => {
     setProps();
     formHolder.profile = null;
     formHolder.password = null;
+    formHolder.passwordSetup = null;
     formSeed.passwordErrors = {};
 });
 
@@ -149,6 +168,8 @@ afterEach(() => {
     cleanup();
     vi.unstubAllGlobals();
     routerDeleteMock.mockReset();
+    routerReloadMock.mockReset();
+    routerPostMock.mockReset();
 });
 
 describe("Settings/Index 唯一オーナー削除ガード", () => {
@@ -485,3 +506,90 @@ describe("Settings/Index パスワード変更の pending / エラークリア (
         expect(form?.reset as ReturnType<typeof vi.fn>).toHaveBeenCalledTimes(1);
     });
 });
+
+/*
+ * パスワードカードの 3 値出し分け (施策 7)。
+ *
+ * password 未設定ユーザーに `current_password` 必須の変更フォームを出すと**必ず失敗する**
+ * (カード丸ごとが踏破不能 = 監査 F-2 と同 species)。初回設定フォームへ切り替える。
+ * prop 欠落 (状態不明) を false に倒すと、設定済みユーザーに初回設定フォームを出す
+ * = 「状態不明を誤った UI に倒す」の再演になるため 3 値で扱う。
+ */
+describe("Settings/Index パスワードカードの出し分け (施策 7)", () => {
+    /** recent-auth precheck を fresh に固定する (初回設定も step-up 必須) */
+    function stubFresh(): void {
+        stubRecentAuthFresh();
+    }
+
+    it("hasPassword=false なら初回設定フォームを出し「現在のパスワード」欄を描画しない", () => {
+        setProps({ hasPassword: false });
+        render(Index, { props: {} });
+
+        expect(screen.getByRole("heading", { name: "パスワードを設定" })).toBeInTheDocument();
+        expect(screen.queryByLabelText("現在のパスワード")).toBeNull();
+        expect(screen.getByLabelText("新しいパスワード")).toBeInTheDocument();
+    });
+
+    it("hasPassword=true なら従来どおり変更フォーム (現在のパスワード欄あり)", () => {
+        setProps({ hasPassword: true });
+        render(Index, { props: {} });
+
+        expect(screen.getByRole("heading", { name: "パスワード変更" })).toBeInTheDocument();
+        expect(screen.getByLabelText("現在のパスワード")).toBeInTheDocument();
+        expect(screen.queryByTestId("set-password-button")).toBeNull();
+    });
+
+    it("hasPassword 欠落 (状態不明) はどちらのフォームも出さず再読み込み導線を出す", async () => {
+        setProps({ hasPassword: undefined });
+        render(Index, { props: {} });
+
+        expect(screen.getByTestId("password-state-unknown")).toBeInTheDocument();
+        expect(screen.queryByLabelText("現在のパスワード")).toBeNull();
+        expect(screen.queryByTestId("set-password-button")).toBeNull();
+
+        await fireEvent.click(screen.getByRole("button", { name: "再読み込み" }));
+        expect(routerReloadMock).toHaveBeenCalledTimes(1);
+    });
+
+    it("設定ボタンは常に活性 (必須条件未充足でも disabled にしない)", () => {
+        setProps({ hasPassword: false });
+        render(Index, { props: {} });
+
+        expect(screen.getByTestId("set-password-button")).not.toBeDisabled();
+    });
+
+    it("fresh なら /settings/password へ 1 回だけ post する", async () => {
+        setProps({ hasPassword: false });
+        stubFresh();
+        render(Index, { props: {} });
+
+        await fireEvent.input(screen.getByLabelText("新しいパスワード"), {
+            target: { value: "Str0ng-Passphrase!" },
+        });
+        await fireEvent.submit(
+            screen.getByTestId("set-password-button").closest("form") as HTMLFormElement,
+        );
+
+        const postMock = formHolder.passwordSetup?.post as ReturnType<typeof vi.fn>;
+        await waitFor(() => expect(postMock).toHaveBeenCalledTimes(1));
+        expect(postMock.mock.calls.at(-1)?.[0]).toBe("/settings/password");
+    });
+
+    it("stale なら post せず再認証モーダルを開く (precheck)", async () => {
+        setProps({ hasPassword: false });
+        stubRecentAuthStaleThenConfirm();
+        render(Index, { props: {} });
+
+        await fireEvent.input(screen.getByLabelText("新しいパスワード"), {
+            target: { value: "Str0ng-Passphrase!" },
+        });
+        await fireEvent.submit(
+            screen.getByTestId("set-password-button").closest("form") as HTMLFormElement,
+        );
+
+        await waitFor(() =>
+            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument(),
+        );
+        expect(formHolder.passwordSetup?.post as ReturnType<typeof vi.fn>).not.toHaveBeenCalled();
+    });
+});
diff --git a/tests/js/pages/SettingsSecurity.test.ts b/tests/js/pages/SettingsSecurity.test.ts
index 1140be4..7b17240 100644
--- a/tests/js/pages/SettingsSecurity.test.ts
+++ b/tests/js/pages/SettingsSecurity.test.ts
@@ -71,6 +71,7 @@ function stubFetchRoutes({
                     recent,
                     passwordSet: true,
                     availableProviders: [],
+                    passkeyAvailable: false,
                     canSatisfy: true,
                     confirmedAt: recent ? 1 : null,
                 }),
diff --git a/tests/js/pages/SettingsSecurityPasskey.test.ts b/tests/js/pages/SettingsSecurityPasskey.test.ts
index eb21519..568da01 100644
--- a/tests/js/pages/SettingsSecurityPasskey.test.ts
+++ b/tests/js/pages/SettingsSecurityPasskey.test.ts
@@ -151,13 +151,17 @@ describe("Settings/Security パスキーカード", () => {
         expect(screen.getByTestId("register-passkey-button")).not.toBeDisabled();
     });
 
-    it("非対応ブラウザで登録を押すと理由をトーストで出す (無言失敗にしない)", async () => {
+    // 非フィールド起因の操作失敗は **Alert** (DESIGN.md §Alert)。押下直後に読ませたい失敗理由を
+    // 画面外へ飛ぶ toast に出さない。
+    it("非対応ブラウザで登録を押すと理由を Alert で出す (無言失敗にしない)", async () => {
         removePasskeySupport();
         render(Security, { props: {} });
 
         await fireEvent.click(screen.getByTestId("register-passkey-button"));
 
-        expect(addToastMock).toHaveBeenCalledWith("error", expect.stringContaining("対応していません"));
+        const alert = await screen.findByTestId("passkey-operation-error");
+        expect(alert).toHaveTextContent("対応していません");
+        expect(addToastMock).not.toHaveBeenCalled();
         expect(routerPostMock).not.toHaveBeenCalled();
     });
 
@@ -336,7 +340,7 @@ describe("パスキー登録の送信契約", () => {
         expect(addToastMock).not.toHaveBeenCalled();
     });
 
-    it("ceremony 失敗はエラーを出して POST しない", async () => {
+    it("ceremony 失敗は Alert に理由を出して POST しない", async () => {
         stubRecentAuth(true);
         createPasskeyCredentialMock.mockResolvedValue({
             status: "failed",
@@ -349,12 +353,9 @@ describe("パスキー登録の送信契約", () => {
         });
         await fireEvent.click(screen.getByTestId("register-passkey-button"));
 
-        await waitFor(() => {
-            expect(addToastMock).toHaveBeenCalledWith(
-                "error",
-                "パスキーの登録を開始できませんでした。",
-            );
-        });
+        const alert = await screen.findByTestId("passkey-operation-error");
+        expect(alert).toHaveTextContent("パスキーの登録を開始できませんでした。");
+        expect(addToastMock).not.toHaveBeenCalled();
         expect(routerPostMock).not.toHaveBeenCalled();
     });
 });
@@ -410,3 +411,187 @@ describe("再認証モーダル: この端末では実行できない状態", ()
         expect(screen.queryByTestId("recent-auth-unsupported-here")).toBeNull();
     });
 });
+
+/*
+ * 名前エラーの canonical 形 (施策 10。DESIGN.md §FormField)。
+ * 押下時に代入するだけだと、その後の入力でエラーが消えず stale invalid が残る。
+ * 「提示開始 boolean + $derived 文言」にして入力へ追随させる。
+ */
+describe("パスキー名エラーの入力追随 (施策 10)", () => {
+    it("空で押下 → 文言が出る → 1 文字入力で消える → 再び空にすると戻る", async () => {
+        render(Security, { props: {} });
+        const input = screen.getByTestId("passkey-name-input");
+
+        await fireEvent.click(screen.getByTestId("register-passkey-button"));
+        expect(screen.getByText("パスキーの名前を入力してください。")).toBeInTheDocument();
+
+        await fireEvent.input(input, { target: { value: "あ" } });
+        await waitFor(() =>
+            expect(screen.queryByText("パスキーの名前を入力してください。")).toBeNull(),
+        );
+
+        await fireEvent.input(input, { target: { value: "" } });
+        await waitFor(() =>
+            expect(screen.getByText("パスキーの名前を入力してください。")).toBeInTheDocument(),
+        );
+    });
+
+    it("サーバ 422 の errors.name は FormField に出る (汎用トーストに潰さない)", async () => {
+        stubRecentAuth(true);
+        createPasskeyCredentialMock.mockResolvedValue({ status: "ok", value: CREDENTIAL_FIXTURE });
+        render(Security, { props: {} });
+
+        await fireEvent.input(screen.getByTestId("passkey-name-input"), {
+            target: { value: "現場用スマホ" },
+        });
+        await fireEvent.click(screen.getByTestId("register-passkey-button"));
+
+        await waitFor(() => expect(routerPostMock).toHaveBeenCalled());
+        const options = routerPostMock.mock.calls.at(-1)?.[2] as {
+            onError?: (errors: Record<string, string>) => void;
+        };
+        options.onError?.({ name: "その名前は既に使われています。" });
+
+        await waitFor(() =>
+            expect(screen.getByText("その名前は既に使われています。")).toBeInTheDocument(),
+        );
+        // フィールド起因なので Alert (非フィールド起因) には出さない
+        expect(screen.queryByTestId("passkey-operation-error")).toBeNull();
+        expect(addToastMock).not.toHaveBeenCalled();
+    });
+
+    it("フィールドに紐づかないサーバエラーは Alert に出る", async () => {
+        stubRecentAuth(true);
+        createPasskeyCredentialMock.mockResolvedValue({ status: "ok", value: CREDENTIAL_FIXTURE });
+        render(Security, { props: {} });
+
+        await fireEvent.input(screen.getByTestId("passkey-name-input"), {
+            target: { value: "現場用スマホ" },
+        });
+        await fireEvent.click(screen.getByTestId("register-passkey-button"));
+
+        await waitFor(() => expect(routerPostMock).toHaveBeenCalled());
+        const options = routerPostMock.mock.calls.at(-1)?.[2] as {
+            onError?: (errors: Record<string, string>) => void;
+        };
+        options.onError?.({ credential: "不正な credential です。" });
+
+        expect(await screen.findByTestId("passkey-operation-error")).toBeInTheDocument();
+    });
+});
+
+/*
+ * 登録フローの多重起動ガード (施策 11)。
+ * 現行は router.post を await していないため ceremony 直後に loading が解け、連打で
+ * ceremony が多重に走る。precheck (/recent-auth/status) の待ち時間も無防備だった。
+ */
+describe("パスキー登録フローの多重起動ガード (施策 11)", () => {
+    it("POST 中は登録ボタンが loading のまま (onFinish まで解除しない)", async () => {
+        stubRecentAuth(true);
+        createPasskeyCredentialMock.mockResolvedValue({ status: "ok", value: CREDENTIAL_FIXTURE });
+        // onStart だけ呼び onFinish は呼ばない = POST 継続中
+        routerPostMock.mockImplementation(
+            (_url: string, _data: unknown, options: { onStart?: () => void }) => {
+                options.onStart?.();
+            },
+        );
+        render(Security, { props: {} });
+
+        await fireEvent.input(screen.getByTestId("passkey-name-input"), {
+            target: { value: "現場用スマホ" },
+        });
+        await fireEvent.click(screen.getByTestId("register-passkey-button"));
+
+        await waitFor(() =>
+            expect(screen.getByTestId("register-passkey-button")).toHaveAttribute(
+                "aria-busy",
+                "true",
+            ),
+        );
+    });
+
+    it("precheck の解決待ち中に連打しても ceremony は 1 回しか始まらない", async () => {
+        // /recent-auth/status を保留させ、precheck 区間を開いたままにする
+        // 制御端を object に持つ (直接の局所変数だと TS が callback 内代入を追えず never に潰れる)
+        const pending: { resolve: (value: unknown) => void } = { resolve: () => {} };
+        fetchMock.mockImplementation((input: RequestInfo | URL) => {
+            if (String(input).includes("/recent-auth/status")) {
+                return new Promise((resolve) => {
+                    pending.resolve = resolve;
+                });
+            }
+            return Promise.resolve(jsonResponse(false, 500, {}));
+        });
+        createPasskeyCredentialMock.mockResolvedValue({ status: "ok", value: CREDENTIAL_FIXTURE });
+        render(Security, { props: {} });
+
+        await fireEvent.input(screen.getByTestId("passkey-name-input"), {
+            target: { value: "現場用スマホ" },
+        });
+        const button = screen.getByTestId("register-passkey-button");
+        await fireEvent.click(button);
+        await fireEvent.click(button);
+        await fireEvent.click(button);
+
+        expect(createPasskeyCredentialMock).not.toHaveBeenCalled();
+
+        pending.resolve(
+            jsonResponse(true, 200, {
+                recent: true,
+                passwordSet: true,
+                availableProviders: [],
+                passkeyAvailable: false,
+                canSatisfy: true,
+                confirmedAt: 1,
+            }),
+        );
+
+        await waitFor(() => expect(createPasskeyCredentialMock).toHaveBeenCalledTimes(1));
+        await waitFor(() => expect(routerPostMock).toHaveBeenCalledTimes(1));
+    });
+
+    it("stale でモーダルへ委譲した後にキャンセルしても登録ボタンが固まらない", async () => {
+        stubRecentAuth(false);
+        render(Security, { props: {} });
+
+        await fireEvent.input(screen.getByTestId("passkey-name-input"), {
+            target: { value: "現場用スマホ" },
+        });
+        await fireEvent.click(screen.getByTestId("register-passkey-button"));
+
+        await waitFor(() => expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument());
+        await fireEvent.click(screen.getByRole("button", { name: "キャンセル" }));
+
+        await waitFor(() =>
+            expect(screen.getByTestId("register-passkey-button")).not.toHaveAttribute(
+                "aria-busy",
+                "true",
+            ),
+        );
+        expect(screen.getByTestId("register-passkey-button")).not.toBeDisabled();
+    });
+});
+
+/*
+ * 踏破可能な CTA (施策 8)。この Alert が出るのは「削除するとログイン手段が 0 になる」=
+ * password を持たないユーザーだけなので、/settings は必ず初回設定フォームを出す。
+ */
+describe("ログイン手段保持 guard の CTA 踏破可能性 (施策 8)", () => {
+    it("CTA の遷移先は /settings (password 未設定なら初回設定フォームが出る)", () => {
+        setPageProps({ errors: { login_method: "この操作を行うと、ログインする手段がなくなります。" } });
+        render(Security, { props: { passkeys } });
+
+        const cta = screen.getByTestId("passkey-add-password");
+        expect(new URL((cta as HTMLAnchorElement).href).pathname).toBe("/settings");
+    });
+
+    it("拒否 Alert が現れたらフォーカスを移す (見落とさせない)", async () => {
+        setPageProps({ errors: { login_method: "この操作を行うと、ログインする手段がなくなります。" } });
+        render(Security, { props: { passkeys } });
+
+        await waitFor(() => {
+            const alert = screen.getByTestId("passkey-login-method-error");
+            expect(alert.closest('[tabindex="-1"]')).toBe(document.activeElement);
+        });
+    });
+});
diff --git a/tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts b/tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts
index 0d8e394..72ae733 100644
--- a/tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts
+++ b/tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts
@@ -67,6 +67,7 @@ function stubFetchRoutes(): void {
                     recent: true,
                     passwordSet: true,
                     availableProviders: [],
+                    passkeyAvailable: false,
                     canSatisfy: true,
                     confirmedAt: 1,
                 }),
@@ -246,6 +247,7 @@ function stubDeferredEnrollmentFetch(): { qr: Deferred[]; secret: Deferred[] } {
                     recent: true,
                     passwordSet: true,
                     availableProviders: [],
+                    passkeyAvailable: false,
                     canSatisfy: true,
                     confirmedAt: 1,
                 }),
@@ -275,6 +277,7 @@ function stubEnrollmentFetch(overrides: { qr?: unknown; secret?: unknown }): voi
                     recent: true,
                     passwordSet: true,
                     availableProviders: [],
+                    passkeyAvailable: false,
                     canSatisfy: true,
                     confirmedAt: 1,
                 }),

```

---

## テスト結果

- `composer test`: **2887 passed / 0 failed / 2 skipped** (2889 tests, 11510 assertions)
- `composer phpstan`: OK (No errors, level 10, 782 files)
- `vendor/bin/pint --test`: passed
- `pnpm lint`: OK
- `pnpm typecheck`: OK
- `pnpm test`: **1201 passed / 0 failed** (123 files)
- `pnpm build`: OK
- `pnpm typecheck:packages` / `pnpm test:packages`: OK

新規/更新した gate:
- `tests/js/architecture/recent-auth-modal-call-site-inventory.test.ts` (新規・deny-by-default)
- `tests/js/architecture/logout-call-site-inventory.test.ts` (inventory 更新)
- `tests/Architecture/RecentAuthRouteTest.php` (allowlist に `settings.password.store` 追加)
- `tests/Architecture/ControllerAuthorizationGateTest.php` (self-scoped exemption に分類登録)
- `tests/Feature/Auth/RecentAuthStatusContractTest.php` (新規・JSON 契約)

## 設計からの意図的逸脱 (レビュー対象)

1. **施策 4 の購読イベント**: 詳細設計は `router.on("invalid")` を前提にしていたが、
   本リポジトリの `@inertiajs/core` は **3.3.1** であり `invalid` イベントは存在しない
   (`GlobalEventsMap` に無い)。非 Inertia 応答 (4xx/5xx) の cancelable イベントは
   `httpException` に統合されており (`Response#handleNonInertiaResponse` が
   `fireHttpExceptionEvent` を発火し、`preventDefault` で既定のエラーダイアログを抑止する)、
   `event.detail.response` の型は `HttpExceptionResponse = { status: number; data: string | Record<string, unknown>; headers }`。
   そこで `httpException` を購読し、`data` がオブジェクトでない場合 (非 JSON) は
   preventDefault しない fail-closed にした。
2. **`PasswordCredentialService::setInitial` で呼び出し元インスタンスへ hash を反映**:
   設計には無い 1 行 (`$user->forceFill(['password' => $hash])->syncOriginalAttribute('password')`)。
   ロック取得のために引き直した別インスタンスに保存すると、guard が保持する認証済み
   `User` の in-memory hash が古いままになり、直後の `Auth::logoutOtherDevices()` が
   `InvalidArgumentException: The given password does not match the current password` を投げて
   500 になる (パスワードは保存済み)。テストで再現・修正済み。

## design system 参照 (DESIGN.md 抜粋)

### 変更後の §Alert
```
### Alert

実装: `components/atoms/Alert.svelte`。ページ内に常在するインライン通知ボックス
(一時通知は Toast、フィールド単位のエラーは FormField/FormError を使う)。

- type: `success` / `warning` / `danger` / `info`(info は primary を流用。Toast と同じ規約)
- 配色: ボーダー=状態色、見出し(title 任意)=状態色、本文=`text-text`、背景=`bg-surface`。
  テーマ色を面塗りに使わない。中間 box なので `rounded-md`
- `action` snippet(本文下の CTA)、`dismissible` + `onDismiss`(右上の X)を持つ
- a11y: **danger のみ `role="alert"`(assertive)**、他は `role="status"`(polite)
- **非フィールド起因の操作失敗は Alert**。フォームのフィールドに紐づかない失敗
  (WebAuthn ceremony 失敗・端末非対応・ネットワーク失敗など)は、操作したその場に残る
  Alert で出す。FormError は**フィールド単位**のエラー専用であり、Toast は「一時通知」なので、
  押した直後に読ませたい失敗理由を画面外(上部中央)へ飛ばさない

### FormField

実装: `components/molecules/FormField.svelte`。ラベル + 入力 + エラー(FormError)+
ヘルプの複合 molecule。入力 atom を最小責務に保つため、ラベル・エラー文言・
`aria-describedby` の配線は本 molecule が担う(関心分離)。children snippet に
`{ id, describedBy, invalid }` を渡すので、呼び出し側はそれを入力 atom へ流し込む。
`required` は `*`(danger 色、`aria-hidden`)をラベルに付与する。フォームの入力欄は
```

### 変更後の §FormError
```
### FormError

実装: `components/atoms/FormError.svelte`。フィールド単位のエラー文言
(`text-caption text-danger`。message が無ければ何も描画しない)。FormField / Checkbox から
composition される前提の最小 atom。単体で使う場合、`aria-describedby` の配線は呼び出し側の
責務。ページ常在の通知は Alert、一時通知は Toast を使う。
**フィールドに紐づかない失敗(ceremony 失敗・端末非対応等)を FormError に流さない**
(原因と提示先が食い違い、「パスキー失敗がパスワード欄の赤字として出る」species のバグになる)。
非フィールド起因は Alert(§Alert)。

### Avatar

実装: `components/atoms/Avatar.svelte`。`src` があれば画像、無ければ `name` の先頭 1 文字
(大文字化。サロゲートペアも 1 文字扱い)をイニシャル表示する。アバターは真に円形な UI
```

### 変更後の §RecentAuthModal / §RecentAuthRecoveryNotice
```
### RecentAuthModal

実装: `components/organisms/RecentAuthModal.svelte`(Modal の composition)。機微操作
(API キー発行/失効・アカウント削除・オーナー移譲)の前に出す**同一画面の再認証(step-up)
モーダル**。パスワード設定済みは再入力 → `POST /recent-auth/password`(成功は XHR 204)、
再 SSO 可能な provider は `reauthUrl` へフルリダイレクト、パスキー登録済みは WebAuthn 検証。
認可の最終ゲートは各操作の recent-auth middleware で、本モーダルは UX 補助。

- **props 契約は `status: RecentAuthStatus | null` の 1 本**(`bind:open` / `onConfirmed` を除く)。
  `/recent-auth/status` の応答を field へ分解して手渡さない — field が増えるたびに配線漏れが
  生まれる(T106 で `passkeyAvailable` を足した際、6 呼び出し中 5 箇所が未配線のまま出荷され
  passkey-only ユーザーが 5 画面で詰んだ)。`tsc --noEmit` は `.svelte` テンプレートを型検査
  しないため、強制点は `tests/js/architecture/recent-auth-modal-call-site-inventory.test.ts`
  (deny-by-default。`status={recentAuthStatus}` の識別子・旧 prop 不在・`onStale` での代入まで検査)
- `status === null` は**状態不明**として扱い、空表示や事実に反する文言を出さず再読み込み導線を出す
- 再認証が成立しないユーザー(`canSatisfy=false` / この端末で実行不能)への回復導線は
  **`molecules/RecentAuthRecoveryNotice` に集約**する(下記)

### RecentAuthRecoveryNotice

実装: `components/molecules/RecentAuthRecoveryNotice.svelte`。再認証(step-up)が**この場では
成立しない**ユーザーに出す回復導線。全画面 confirm(`pages/Auth/ConfirmRecentAuth`)と
インラインモーダル(`organisms/RecentAuthModal`)の**両方が使う唯一の実装**(分けて持つと
片方だけ旧作法が残る)。

- `variant`: `no-satisfier`(アカウントに手段が無い)/ `not-executable-here`(手段はあるが
  この端末で実行できない = パスキー非対応ブラウザ)
- **`/forgot-password` へ直接リンクしない**。Fortify が `guest` middleware 付きで登録しており
  ログイン済みの本 UI 利用者はフォームに到達できない(踏破不能 CTA)。案内するのは
  「ログアウト → guest としてパスワード再設定」の経路だけ。アプリ内の初回設定
  (`POST /settings/password`)は recent-auth 必須なので、ここに来ているユーザーには使えない
- ログアウトは **Inertia visit(`router.post`)**(経路 C の保証条件。
  `tests/js/architecture/logout-call-site-inventory.test.ts` が inventory で固定)
- molecule 配置は構造的制約: 呼び出し元の RecentAuthModal は organism であり、
  atomic-import-graph 上 organism は features 層を import できない

## Do's and Don'ts

**Do**

- 背景は常に neutral、浮いた要素は surface(逆に使わない)
- 余白を多めにとる。色は Primary / Tertiary / 状態色 1 種までを目安に
```

### 触れた atomic ディレクトリ構造
```
resources/js/components/atoms:
Alert.svelte
Avatar.svelte
Badge.svelte
Badge.types.ts
Button.svelte
Button.types.ts
Card.svelte
Checkbox.svelte
FormError.svelte
Input.svelte
Select.svelte
Spinner.svelte
TextLink.svelte
TextLink.types.ts
Textarea.svelte
Toggle.svelte
Toggle.types.ts
icons
input-state.ts

resources/js/components/features/auth:
EmailVerificationBanner.svelte
PasskeySection.svelte
VerifyEmailResendButton.svelte

resources/js/components/molecules:
ApiKeyTabNav.svelte
Breadcrumb.svelte
CodeSnippet.svelte
DangerZone.svelte
Divider.svelte
EmptyState.svelte
FormField.svelte
NotificationBell.svelte
PageHeader.svelte
PageHeaderSection.svelte
Pagination.svelte
PasswordInput.svelte
PricingPlanCard.svelte
PricingPlanCard.types.ts
RecentAuthRecoveryNotice.svelte
StatCard.svelte
Tabs.svelte

resources/js/components/organisms:
ConfirmDialog.svelte
ConfirmDialog.types.ts
Modal.svelte
Modal.types.ts
RecentAuthModal.svelte
ToastContainer.svelte

```

新規コンポーネントは `components/molecules/RecentAuthRecoveryNotice.svelte` 1 件
(atoms/Button のみを composition。organism から molecule への単方向 import を維持)。
hex 直書きは 0 件を維持 (architecture 110 tests green)。

