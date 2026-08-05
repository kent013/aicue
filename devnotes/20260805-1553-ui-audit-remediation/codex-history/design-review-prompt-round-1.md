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

```
【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。
```

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）
- `pnpm typecheck` は `tsc --noEmit` (svelte-check は未導入 = .svelte テンプレートの props は型検査されない)
- eslint は noInlineConfig (inline eslint-disable 不可)

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
10. DESIGN.md準拠（design token / Atomic Design の単方向 import: atoms → molecules → organisms → features → templates → pages）
11. Atomic Design準拠（atom は単機能・無状態、molecule は atom の組合せ。アイコンは Lucide 前提で SVG 直書きを新設しない）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

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
  - `status=null` → `recent-auth-unknown` + 再読み込みボタン (空表示にしない)
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

const IMPORT_PATTERN =
  /from\s+["']@\/components\/organisms\/RecentAuthModal\.svelte["']/;
/** 呼び出しタグ全体 (属性が複数行にまたがる) */
const TAG_PATTERN = /<RecentAuthModal\b[^>]*>/g;
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
const ON_STALE_PATTERN = /onStale:\s*\(status\)\s*=>/;

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
  it("RecentAuthModal を import するのは inventory 登録分のみ", async () => {
    const files = await listSourceFiles(JS_ROOT);
    const offenders: string[] = [];
    for (const file of files) {
      const content = await fs.readFile(file, "utf8");
      if (!IMPORT_PATTERN.test(content)) continue;
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
        `${rel} が onStale で受けた status を使っていない (画面ごとの独自判定を作らない)`,
      ).toMatch(ON_STALE_PATTERN);
    }
  });
});
```

### テスト計画

- [ ] 本テスト自体が gate。実装前に **5 画面未配線の状態で fail することを確認**してから配線する
      (AGENTS.md 思考原則 5 テストファースト)
- [ ] `npx vitest run tests/js/architecture` が all green

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

### 波及変更

- TypeScript 型定義: なし (ローカル型で応答を検証)
- API Resource/DTO: なし (`RecentAuthRequiredResource` は既存のまま)
- テストファイル: `tests/js/lib/recent-auth.test.ts` に handler のケースを追加

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
 * transaction 境界は公開 API (setInitial / change)。apply() は
 * **transaction 内でのみ呼ばれる private 処理**で、自分では transaction を開かない。
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
        DB::transaction(function () use ($user, $plain): void {
            // 同時 2 リクエストで両方が「未設定」と判定するのを防ぐ (TOCTOU)。
            // ロック取得順序は User 単位 (EnsureLoginMethodRemains と同型の作法)。
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->hasPassword()) {
                throw ValidationException::withMessages([
                    'password' => 'すでにパスワードが設定されています。パスワード変更フォームから変更してください。',
                ]);
            }

            $this->apply($locked, $plain, SecurityEventType::PasswordSet);
        });
    }

    /** 変更 (current_password の検証は Fortify 契約側 UpdateUserPassword が行う) */
    public function change(User $user, string $plain): void
    {
        DB::transaction(function () use ($user, $plain): void {
            $this->apply($user, $plain, SecurityEventType::PasswordChanged);
        });
    }

    /**
     * hash 保存 → 監査記録 → 他デバイス失効 → DB session 行削除。
     * **transaction 内でのみ呼ぶこと** (自分では開かない)。
     */
    private function apply(User $user, string $plain, SecurityEventType $event): void
    {
        $user->forceFill(['password' => Hash::make($plain)])->save();

        $this->recorder->record($event, $user);

        // 現在デバイスを維持しつつ他デバイスを失効させる (保存直後の新 password を渡す)。
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

**UpdateUserPassword** (確定後処理を Service へ委譲。Validator と Fortify 契約は据え置き):

```php
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
- [ ] 既存のパスワード変更 Feature テストが Service 委譲後も green (挙動不変の確認)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- `Auth::logoutOtherDevices()` を transaction 内で呼ぶ形になる (現行は transaction 外)。
  DB session 行の更新のみで外部 I/O は無いため許容範囲だが、実装時に
  `EnsureLoginMethodRemains` の docblock が挙げる禁忌 (streamed response / 外部 I/O /
  非 afterCommit の queue dispatch) に該当しないことを再確認する
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
const hasPassword = $derived(props.hasPassword ?? false);

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
    {#if hasPassword}
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
  - `settingsUrl` の断言を削除、`code` / `message` のみを固定
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

- `resources/js/components/features/auth/PasskeySection.svelte` (L93-110, L161-172)

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

拒否 Alert (`loginMethodError`) 表示時のフォーカス移動は、
`Security.svelte:252-254` のリカバリコード panel と同じ作法
(`tabindex="-1"` + `bind:this` + `tick()` 後に `focus()`) で実装する。

### テスト計画

- [ ] ceremony 成功 → POST 中は `register-passkey-button` が loading のまま
      (`onFinish` まで解除されない)
- [ ] 連打しても ceremony が 1 回しか開始しない
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

- [ ] `npx vitest run tests/js/architecture tests/js/styles` が all green
      (117 tests + 本批の新規分)

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


---

## 関連する現行コード

### `resources/js/components/organisms/RecentAuthModal.svelte`

```svelte
<script lang="ts">
    import { ShieldCheck } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import FormError from "@/components/atoms/FormError.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Divider from "@/components/molecules/Divider.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import Modal from "@/components/organisms/Modal.svelte";
    import { csrfToken } from "@/lib/csrf";
    import { confirmWithPasskey, isPasskeySupported } from "@/lib/passkeys";
    import type { AvailableReauthProvider } from "@/lib/recent-auth";
    import { providerLabel } from "@/lib/social";

    /**
     * 機微操作 (API キー発行/失効・アカウント削除・オーナー移譲) の前に出す
     * 「同一画面の再認証 (step-up) モーダル」。
     * - password 設定済みユーザー: パスワード再入力 → POST /recent-auth/password (XHR=204 成功)。
     * - 再SSO 可能な provider (availableProviders): reauthUrl へフルリダイレクト。
     * - パスキー登録済み (passkeyAvailable): WebAuthn 検証 → POST /passkeys/confirm (204)。
     *   TOTP 有効ユーザーでも **再認証には使える** (PasskeyLoginPolicy が縛るのはログインのみ)。
     * - canSatisfy=false (再認証手段なし): 回復導線 (パスワードリセット) を案内する。
     * 認可の最終ゲートは各操作の recent-auth middleware (本モーダルは UX 補助)。
     */
    interface Props {
        open: boolean;
        passwordSet?: boolean;
        availableProviders?: AvailableReauthProvider[];
        canSatisfy?: boolean;
        /**
         * パスキーでの再認証を提示するか。**サーバの `/recent-auth/status` が単一の源**
         * (`RecentAuthStatus.passkeyAvailable`)。呼び出し側が独自に判定しない
         * — 画面ごとに判定を持つと passkey しか持たないユーザーが特定画面でだけ詰む。
         */
        passkeyAvailable?: boolean;
        /** password satisfier 成功時 (204)。呼び出し側が pending action を再開する */
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

    const passkeySupported = isPasskeySupported();
    let passkeySubmitting = $state(false);

    /**
     * **この端末で実行できる** satisfier があるか。
     * `canSatisfy` は「アカウントに手段があるか」(サーバ判定) であり、
     * パスキーしか無いユーザーが WebAuthn 非対応ブラウザで開くと
     * 「手段はあるが、この端末では実行できない」= 説明の無い行き止まりになる。
     * その状態を明示的に表現して回復導線を出す。
     */
    const executableHere = $derived(
        passwordSet || availableProviders.length > 0 || (passkeyAvailable && passkeySupported),
    );

    async function submitPasskey(): Promise<void> {
        if (passkeySubmitting) return;
        passkeySubmitting = true;
        error = "";
        try {
            const outcome = await confirmWithPasskey();
            if (outcome.status === "ok") {
                open = false;
                onConfirmed();
                return;
            }
            // キャンセルは失敗として騒がない (再試行導線を残す)
            if (outcome.status === "cancelled") return;
            error =
                outcome.status === "unsupported"
                    ? "このブラウザはパスキーに対応していません。"
                    : outcome.message;
        } finally {
            passkeySubmitting = false;
        }
    }

    let password = $state("");
    let error = $state("");
    let submitting = $state(false);

    // モーダルを閉じたら入力状態をリセットする (次回表示への持ち越し防止)
    $effect(() => {
        if (!open) {
            password = "";
            error = "";
            submitting = false;
            passkeySubmitting = false;
        }
    });

    async function submitPassword(event: SubmitEvent): Promise<void> {
        event.preventDefault();
        if (submitting) return;
        if (password === "") {
            error = "パスワードを入力してください。";
            return;
        }
        submitting = true;
        error = "";
        try {
            const res = await fetch("/recent-auth/password", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-XSRF-TOKEN": csrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
                body: JSON.stringify({ password }),
            });
            // 成功は 204 No Content (opaqueredirect 依存を排除)
            if (res.status === 204) {
                open = false;
                onConfirmed();
                return;
            }
            if (res.status === 422) {
                const body = (await res.json().catch(() => null)) as {
                    errors?: { password?: string[] };
                } | null;
                error = body?.errors?.password?.[0] ?? "パスワードが正しくありません。";
                return;
            }
            error = "再認証に失敗しました。時間をおいて再度お試しください。";
        } catch {
            error = "通信エラーが発生しました。";
        } finally {
            submitting = false;
        }
    }
</script>

<Modal bind:open title="本人確認" size="sm" processing={submitting} testId="recent-auth-modal">
    <div class="flex flex-col gap-4">
        <div class="flex items-start gap-2 text-caption text-text-secondary">
            <ShieldCheck class="mt-0.5 size-5 shrink-0 text-primary" aria-hidden="true" />
            <p>セキュリティのため、この操作を続けるにはもう一度本人確認が必要です。</p>
        </div>

        {#if passwordSet}
            <form novalidate onsubmit={submitPassword} class="flex flex-col gap-3">
                <FormField label="現在のパスワード" id="recent-auth-password" error={error}>
                    {#snippet children({ id, describedBy, invalid })}
                        <Input
                            {id}
                            type="password"
                            bind:value={password}
                            error={invalid}
                            aria-describedby={describedBy}
                            autocomplete="current-password"
                            testId="recent-auth-password-input"
                        />
                    {/snippet}
                </FormField>
                <Button type="submit" loading={submitting} fullWidth testId="recent-auth-submit">
                    確認する
                </Button>
            </form>
        {:else}
            <FormError message={error} testId="recent-auth-error" />
        {/if}

        {#if passkeyAvailable && passkeySupported}
            {#if passwordSet}
                <Divider label="または" />
            {/if}
            <Button
                variant="ghost"
                fullWidth
                loading={passkeySubmitting}
                onclick={() => void submitPasskey()}
                testId="recent-auth-passkey"
            >
                パスキーで再認証
            </Button>
        {/if}

        {#if availableProviders.length > 0}
            {#if passwordSet || (passkeyAvailable && passkeySupported)}
                <Divider label="または" />
            {/if}
            <div class="flex flex-col gap-2">
                {#each availableProviders as provider (provider.provider)}
                    <Button
                        href={provider.reauthUrl}
                        variant="ghost"
                        fullWidth
                        testId={`recent-auth-sso-${provider.provider}`}
                    >
                        {providerLabel(provider.provider)}で再認証
                    </Button>
                {/each}
            </div>
        {/if}

        {#if !canSatisfy}
            <div class="flex flex-col gap-2 text-caption text-text-secondary" data-testid="recent-auth-recovery">
                <p>この操作を続けるための再認証手段が設定されていません。</p>
                <Button href="/forgot-password" variant="ghost" fullWidth>
                    パスワードを設定して再認証する
                </Button>
            </div>
        {:else if !executableHere}
            <!-- アカウントには手段があるが、この端末では実行できない (パスキー非対応ブラウザ) -->
            <div
                class="flex flex-col gap-2 text-caption text-text-secondary"
                data-testid="recent-auth-unsupported-here"
            >
                <p>
                    このアカウントの再認証手段はパスキーのみですが、このブラウザはパスキーに対応していません。
                    パスキーを登録した端末・ブラウザで開き直すと再認証できます。
                </p>
            </div>
        {/if}
    </div>
    {#snippet footer()}
        <Button variant="ghost" type="button" onclick={() => (open = false)}>キャンセル</Button>
    {/snippet}
</Modal>

```

### `resources/js/lib/recent-auth.ts`

```ts
import { addToast } from "@/lib/stores/toast";

/**
 * recent-auth step-up (再認証) の共通クライアントヘルパ。
 *
 * 機微操作 (API キー発行/失効・アカウント削除・オーナー移譲) の前段 precheck を共通化する。
 * 最終ゲートはサーバ側 recent-auth middleware であり、本ヘルパは UX 補助にすぎない。
 *
 * - {@link fetchRecentAuthStatus}: `/recent-auth/status` を fresh に取得 (失敗時は null)。
 * - {@link withRecentAuth}: fresh なら action 即実行、stale なら再認証モーダル用の status を返し、
 *   status 取得失敗時は最終ゲート (middleware) へ委譲して action を直接実行する。
 */

/** RecentAuthStatusResource (backend) に対応する型 */
export interface AvailableReauthProvider {
    provider: string;
    capability: string;
    /** 再SSO 開始 URL (/auth/{provider}/redirect/step-up。OAuth フルリダイレクト) */
    reauthUrl: string;
}

export interface RecentAuthStatus {
    recent: boolean;
    passwordSet: boolean;
    availableProviders: AvailableReauthProvider[];
    /**
     * パスキーで再認証できるか (登録済み credential が 1 件以上ある)。
     * **ログイン可否とは別**: 2要素認証が有効なユーザーはパスキーでログインできないが、
     * 再認証には使える。
     */
    passkeyAvailable: boolean;
    canSatisfy: boolean;
    confirmedAt: number | null;
}

/**
 * recent-auth 状態を fresh に取得する。失敗時は null (呼び出し側は最終ゲート委譲にフォールバック)。
 */
export async function fetchRecentAuthStatus(): Promise<RecentAuthStatus | null> {
    try {
        const res = await fetch("/recent-auth/status", {
            headers: { Accept: "application/json", "X-Requested-With": "XMLHttpRequest" },
            credentials: "same-origin",
        });
        if (!res.ok) return null;
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
    } catch {
        return null;
    }
}

/**
 * 保護操作の前段ゲート。
 *
 * - fresh: `onFresh` を実行して終了。
 * - stale: `onStale(status)` を呼び、呼び出し側が再認証モーダルを開く。
 * - status 取得失敗 (network/5xx/parse) = delegated: 状態不明でモーダルを出せないため
 *   middleware の最終ゲートに委譲する (既定は `onFresh` にフォールバック。
 *   別動作が必要な画面は `onDelegated` を明示指定する)。
 *
 * 戻り値は実行した分岐 (テスト・呼び出し側の分岐確認用)。
 */
export async function withRecentAuth(handlers: {
    onFresh: () => void;
    onStale: (status: RecentAuthStatus) => void;
    /** status 取得失敗時の委譲動作。未指定なら onFresh にフォールバック (最終ゲート委譲)。 */
    onDelegated?: () => void;
}): Promise<"fresh" | "stale" | "delegated"> {
    const status = await fetchRecentAuthStatus();
    if (status === null) {
        addToast("info", "再認証が必要な場合は確認ページへ移動します。");
        (handlers.onDelegated ?? handlers.onFresh)();
        return "delegated";
    }
    if (status.recent) {
        handlers.onFresh();
        return "fresh";
    }
    handlers.onStale(status);
    return "stale";
}

```

### `resources/js/components/features/auth/PasskeySection.svelte`

```svelte
<script lang="ts">
    import { router } from "@inertiajs/svelte";
    import { KeyRound } from "@lucide/svelte";
    import Alert from "@/components/atoms/Alert.svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import {
        canCreatePasskey,
        createPasskeyCredential,
        isPasskeySupported,
        type PasskeyListItem,
    } from "@/lib/passkeys";
    import { addToast } from "@/lib/stores/toast";

    /**
     * セキュリティ設定のパスキーカード。
     *
     * 契約:
     * - 登録 / 削除は **recent-auth 必須**。precheck は呼び出し側 (page) が持つ `guard` に委譲する
     *   (再認証モーダルはページに 1 つだけ置き、二重モーダルを作らない)。
     * - 登録は ceremony (fetch) → **Inertia `router.post`** で送る (transport 契約)。
     *   成功 flash はサーバ (`back()->with('success')`) を単一の源とし client 楽観 toast を出さない。
     * - 削除は ConfirmDialog → `router.delete`。ログイン手段が 0 になる場合サーバは
     *   302 + `errors.login_method` を返すため、`loginMethodError` として受け取り明示表示する
     *   (**無言失敗にしない**)。
     * - **必須条件未充足でボタンを disabled にしない** (AGENTS.md 禁止事項 8)。
     *   非対応端末でも押せて、押下時にエラーを出す。
     */
    interface Props {
        passkeys?: PasskeyListItem[];
        /** passkey での「ログイン」が許されるか (TOTP 有効時は false。再認証には使える) */
        passkeyLoginAvailable?: boolean;
        twoFactorEnabled?: boolean;
        /** EnsureLoginMethodRemains の拒否メッセージ ($page.props.errors.login_method) */
        loginMethodError?: string;
        /** recent-auth precheck。fresh なら即実行、stale なら再認証モーダルを挟んで再開する */
        guard: (action: () => void) => void;
    }

    let {
        passkeys = [],
        passkeyLoginAvailable = false,
        twoFactorEnabled = false,
        loginMethodError,
        guard,
    }: Props = $props();

    const supported = isPasskeySupported();
    let creatable = $state(false);
    void (async () => {
        creatable = await canCreatePasskey();
    })();

    let newPasskeyName = $state("");
    let nameError = $state("");
    let registering = $state(false);

    function registerPasskey(): void {
        if (registering) return;
        // 非対応端末でも押下できる (disabled にしない)。押した結果として理由を出す。
        if (!supported) {
            addToast(
                "error",
                "このブラウザはパスキーに対応していません。パスワードまたはソーシャルログインをご利用ください。",
            );
            return;
        }
        const name = newPasskeyName.trim();
        if (name === "") {
            nameError = "パスキーの名前を入力してください。";
            return;
        }
        nameError = "";

        guard(() => {
            void (async () => {
                registering = true;
                try {
                    const outcome = await createPasskeyCredential();
                    if (outcome.status === "cancelled") return;
                    if (outcome.status === "unsupported") {
                        addToast("error", "このブラウザはパスキーに対応していません。");
                        return;
                    }
                    if (outcome.status === "failed") {
                        addToast("error", outcome.message);
                        return;
                    }
                    router.post(
                        "/user/passkeys",
                        { name, credential: outcome.value },
                        {
                            preserveScroll: true,
                            onSuccess: () => {
                                newPasskeyName = "";
                            },
                            onError: () => {
                                addToast("error", "パスキーの登録に失敗しました。");
                            },
                        },
                    );
                } finally {
                    registering = false;
                }
            })();
        });
    }

    let deleteTarget = $state<PasskeyListItem | null>(null);
    let deleteDialogOpen = $state(false);
    let deleting = $state(false);

    function requestDelete(passkey: PasskeyListItem): void {
        deleteTarget = passkey;
        deleteDialogOpen = true;
    }

    function confirmDelete(): void {
        const target = deleteTarget;
        if (target === null) return;
        guard(() => {
            router.delete(`/user/passkeys/${target.id}`, {
                preserveScroll: true,
                onStart: () => {
                    deleting = true;
                },
                onFinish: () => {
                    deleting = false;
                    deleteDialogOpen = false;
                    deleteTarget = null;
                },
            });
        });
    }

    function formatDate(value: string | null): string {
        if (value === null) return "未使用";
        const parsed = new Date(value);
        return Number.isNaN(parsed.getTime()) ? "不明" : parsed.toLocaleDateString("ja-JP");
    }
</script>

<Card padding="lg">
    <div class="flex items-center justify-between gap-4">
        <h2 class="text-h3">パスキー</h2>
        {#if passkeys.length > 0}
            <Badge tone="success" testId="passkey-count">{passkeys.length} 件登録済み</Badge>
        {:else}
            <Badge tone="neutral" testId="passkey-count">未登録</Badge>
        {/if}
    </div>
    <p class="mt-1 text-caption text-text-secondary">
        指紋・顔認証・端末のロック解除でログインできます。
    </p>

    <div class="mt-4 flex flex-col gap-4">
        {#if loginMethodError}
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
        {/if}

        {#if !passkeyLoginAvailable && twoFactorEnabled}
            <!-- 誤認させない: 2FA 有効時はログインには使えないが再認証には使える -->
            <Alert type="info" testId="passkey-2fa-notice">
                2要素認証を有効にしているため、パスキーでのログインはできません。この画面での再認証にはご利用いただけます。
            </Alert>
        {/if}

        {#if !supported}
            <Alert type="warning" testId="passkey-unsupported">
                このブラウザはパスキーに対応していません。パスワードまたはソーシャルログインをご利用ください。
            </Alert>
        {:else if !creatable}
            <Alert type="warning" testId="passkey-not-creatable">
                この端末ではパスキーを作成できません。画面ロック（生体認証・PIN）を設定すると利用できます。
            </Alert>
        {/if}

        {#if passkeys.length > 0}
            <ul class="flex flex-col gap-3" data-testid="passkey-list">
                {#each passkeys as passkey (passkey.id)}
                    <li
                        class="flex items-center justify-between gap-4 rounded-md border border-border p-3"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <KeyRound class="size-5 shrink-0 text-primary" aria-hidden="true" />
                            <div class="min-w-0">
                                <p class="truncate text-body">{passkey.name}</p>
                                <p class="text-caption text-text-secondary">
                                    {passkey.authenticator ?? "認証器不明"} ・ 最終利用 {formatDate(
                                        passkey.lastUsedAt,
                                    )}
                                </p>
                            </div>
                        </div>
                        <Button
                            variant="danger-ghost"
                            size="sm"
                            onclick={() => requestDelete(passkey)}
                            testId={`delete-passkey-${passkey.id}`}
                        >
                            削除
                        </Button>
                    </li>
                {/each}
            </ul>
        {/if}

        <div class="flex flex-col gap-3">
            <FormField label="パスキーの名前" id="passkey-name" error={nameError}>
                {#snippet children({ id, describedBy, invalid })}
                    <Input
                        {id}
                        type="text"
                        bind:value={newPasskeyName}
                        error={invalid}
                        aria-describedby={describedBy}
                        placeholder="例: 現場用スマホ"
                        testId="passkey-name-input"
                    />
                {/snippet}
            </FormField>
            <div>
                <Button onclick={registerPasskey} loading={registering} testId="register-passkey-button">
                    パスキーを登録
                </Button>
            </div>
        </div>
    </div>
</Card>

<ConfirmDialog
    bind:open={deleteDialogOpen}
    title="パスキーの削除"
    message={`パスキー「${deleteTarget?.name ?? ""}」を削除しますか？ この端末からはパスキーでログインできなくなります。`}
    confirmLabel="削除する"
    confirmVariant="danger"
    processing={deleting}
    onConfirm={confirmDelete}
    onCancel={() => {
        deleteTarget = null;
    }}
    testId="delete-passkey-dialog"
/>

```

### `resources/js/pages/Auth/ConfirmRecentAuth.svelte`

```svelte
<script lang="ts">
    import { router, useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import FormError from "@/components/atoms/FormError.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import Divider from "@/components/molecules/Divider.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import PasswordInput from "@/components/molecules/PasswordInput.svelte";
    import AuthLayout from "@/components/templates/AuthLayout.svelte";
    import { confirmPasskeyCredential, isPasskeySupported } from "@/lib/passkeys";
    import type { AvailableReauthProvider } from "@/lib/recent-auth";
    import { providerLabel } from "@/lib/social";

    /**
     * recent-auth step-up の confirm 画面 (全画面フォールバック)。
     * recent-auth middleware が鮮度切れの通常遷移をここへ 302 する。確認成功後は
     * intended URL へ戻る (server 側 redirect()->intended)。
     * - password 設定済みユーザー: password 再入力フォーム (POST /recent-auth/password)
     * - 再SSO 可能な provider: reauthUrl (/auth/{provider}/redirect/step-up) で再認証
     * - パスキー登録済み (passkeyAvailable): WebAuthn 検証 (POST /passkeys/confirm、204)。
     *   **パスキーしか持たないユーザーをこの画面で詰ませない**ための導線
     * - canSatisfy=false: 回復手順 (ログアウト → guest としてパスワード再設定) を案内。
     *   /forgot-password へ直接リンクしない — Fortify が `guest` middleware 付きで登録しており
     *   ログイン済みの本画面ユーザーはフォームに到達できない (踏破不能 CTA。bug-hunt F-2-01 と同 species)
     */
    interface Props {
        appName?: string;
        passwordSet?: boolean;
        availableProviders?: AvailableReauthProvider[];
        /** パスキーで再認証できるか (サーバが単一の源) */
        passkeyAvailable?: boolean;
        canSatisfy?: boolean;
    }

    let {
        appName,
        passwordSet = false,
        availableProviders = [],
        passkeyAvailable = false,
        canSatisfy = true,
    }: Props = $props();

    const passkeySupported = isPasskeySupported();
    let passkeyError = $state("");
    let passkeyProcessing = $state(false);

    /**
     * **この端末で実行できる** satisfier があるか。
     * `canSatisfy` は「アカウントに手段があるか」(サーバ判定)。パスキーしか無いユーザーが
     * WebAuthn 非対応ブラウザで開くと「手段はあるが、この端末では実行できない」=
     * 説明の無い行き止まりになるため、その状態を明示して回復導線を出す。
     */
    const executableHere = $derived(
        passwordSet || availableProviders.length > 0 || (passkeyAvailable && passkeySupported),
    );

    /**
     * パスキーで再認証する。
     *
     * ceremony 結果は **Inertia の router.post で送る** (fetch ではない)。
     * この画面は RequireRecentAuth の 302 fallback 着地であり、元 URL は
     * サーバの `url.intended` にしか無い。Inertia で送れば
     * PasskeyConfirmationResponse が `redirect()->intended()` を返し、元の操作画面へ戻る。
     */
    async function submitPasskey(): Promise<void> {
        if (passkeyProcessing) return;
        passkeyProcessing = true;
        passkeyError = "";
        try {
            const outcome = await confirmPasskeyCredential();
            if (outcome.status === "ok") {
                router.post(
                    "/passkeys/confirm",
                    { credential: outcome.value },
                    {
                        onError: () => {
                            passkeyError = "パスキーでの再認証に失敗しました。";
                        },
                    },
                );
                return;
            }
            // キャンセルは失敗として騒がない (再試行導線を残す)
            if (outcome.status === "cancelled") return;
            passkeyError =
                outcome.status === "unsupported"
                    ? "このブラウザはパスキーに対応していません。"
                    : outcome.message;
        } finally {
            passkeyProcessing = false;
        }
    }

    const form = useForm({
        password: "",
    });

    let loggingOut = $state(false);

    function submit(event: SubmitEvent): void {
        event.preventDefault();
        form.post("/recent-auth/password");
    }

    function logout(): void {
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
</script>

<AuthLayout title="本人確認" {appName}>
    <p class="mb-6 text-body text-text-secondary">
        この操作を続けるには、本人確認のためもう一度認証してください。
    </p>

    {#if passwordSet}
        <form novalidate onsubmit={submit} class="flex flex-col gap-4">
            <FormField label="現在のパスワード" id="password" error={form.errors.password}>
                {#snippet children({ id, describedBy, invalid })}
                    <PasswordInput
                        {id}
                        bind:value={form.password}
                        error={invalid}
                        aria-describedby={describedBy}
                        autocomplete="current-password"
                    />
                {/snippet}
            </FormField>

            <Button type="submit" loading={form.processing} fullWidth>確認する</Button>
        </form>
    {:else}
        <p class="mb-4 text-body text-text-secondary">
            パスワードが設定されていないため、ソーシャルアカウントで再認証してください。
        </p>
        <FormError message={form.errors.password} />
    {/if}

    {#if passkeyAvailable && passkeySupported}
        <div class="mt-6 flex flex-col gap-3">
            {#if passwordSet}
                <Divider label="または" />
            {/if}
            {#if passkeyError}
                <FormError message={passkeyError} testId="confirm-passkey-error" />
            {/if}
            <Button
                variant="ghost"
                fullWidth
                loading={passkeyProcessing}
                onclick={() => void submitPasskey()}
                testId="confirm-passkey-button"
            >
                パスキーで再認証
            </Button>
        </div>
    {/if}

    {#if availableProviders.length > 0}
        <div class="mt-6 flex flex-col gap-3">
            {#if passwordSet || (passkeyAvailable && passkeySupported)}
                <Divider label="または" />
            {/if}
            {#each availableProviders as provider (provider.provider)}
                <Button href={provider.reauthUrl} variant="ghost" fullWidth>
                    {providerLabel(provider.provider)}で再認証
                </Button>
            {/each}
        </div>
    {/if}

    {#if !canSatisfy}
        <div class="mt-6 flex flex-col gap-3 text-caption text-text-secondary">
            <p>
                この操作を続けるための再認証手段が設定されていません。
                いったんログアウトし、ログイン画面の「パスワードをお忘れの方」から
                パスワードを設定すると再認証できるようになります。
            </p>
            <Button variant="ghost" onclick={logout} loading={loggingOut} fullWidth>
                ログアウトする
            </Button>
        </div>
    {:else if !executableHere}
        <!-- アカウントには手段があるが、この端末では実行できない (パスキー非対応ブラウザ) -->
        <div
            class="mt-6 flex flex-col gap-3 text-caption text-text-secondary"
            data-testid="confirm-unsupported-here"
        >
            <p>
                このアカウントの再認証手段はパスキーのみですが、このブラウザはパスキーに対応していません。
                パスキーを登録した端末・ブラウザで開き直すと再認証できます。
                その端末が使えない場合は、いったんログアウトし、ログイン画面の
                「パスワードをお忘れの方」からパスワードを設定してください。
            </p>
            <Button variant="ghost" onclick={logout} loading={loggingOut} fullWidth>
                ログアウトする
            </Button>
        </div>
    {/if}

    {#snippet footer()}
        <p>
            <TextLink href="/dashboard">この操作を中止してダッシュボードへ戻る</TextLink>
        </p>
    {/snippet}
</AuthLayout>

```

### `tests/js/architecture/logout-call-site-inventory.test.ts`

```ts
import { describe, it, expect } from "vitest";
import fs from "fs/promises";
import path from "path";

/**
 * ログアウト導線が Inertia visit 一本であることを deny-by-default で固定する。
 *
 * 経路 C (Inertia history 暗号化 + ログアウト時の履歴鍵破棄。bug-hunt F-4-01) の保証は
 * 「clearHistory: true を含む Inertia page をクライアントが適用すること」に乗っている。
 * JSON 204 で完結する logout (fetch/axios) を足すと、鍵が消えないまま画面が残り、
 * ブラウザバックで PII が復元されうる。
 *
 * 新しいログアウト導線を足したい場合は、それが Inertia visit (router.post) であることを
 * 確認した上で inventory に登録すること。docs/supported-browsers.md の経路 C の記述も更新する。
 *
 * 既知の限界: 検出は **文字列リテラル `"/logout"`** に限定される。将来 `route("logout")` の
 * ような名前解決ヘルパを導入すると検出外になるため、その際は本テストのパターンも同時に更新する。
 */

const JS_ROOT = path.resolve(__dirname, "../../../resources/js");

/**
 * `/logout` を参照してよいファイル (resources/js からの相対パス)。
 * 現状 3 箇所あり、いずれも router.post = Inertia visit
 * (AppLayout: 通常画面のユーザーメニュー / VerifyEmail: メール認証待ち画面の離脱導線 /
 *  ConfirmRecentAuth: 再認証手段が無いユーザーの回復導線 = ログアウトして guest として
 *  パスワードを再設定する。/forgot-password は guest middleware 付きで直リンクできない)。
 */
const LOGOUT_CALL_SITE_INVENTORY: readonly string[] = [
  "components/templates/AppLayout.svelte",
  "pages/Auth/VerifyEmail.svelte",
  "pages/Auth/ConfirmRecentAuth.svelte",
] as const;

const LOGOUT_PATH_PATTERN = /["'`]\/logout["'`]/;
/** 非 Inertia 経路 (これが同一ファイルにあると 204 完結の logout になりうる)。 */
const NON_INERTIA_CLIENT_PATTERN = /\b(fetch|axios)\s*\(/;

const SOURCE_EXTENSIONS: readonly string[] = [".svelte", ".ts"] as const;

/** resources/js 配下の .svelte / .ts を再帰列挙する (svg-inline-allowlist.test.ts と同じ様式)。 */
const listSourceFiles = async (dir: string): Promise<string[]> => {
  const entries = await fs.readdir(dir, {
    recursive: true,
    withFileTypes: true,
  });
  const files: string[] = [];
  for (const entry of entries) {
    if (!entry.isFile()) continue;
    if (!SOURCE_EXTENSIONS.includes(path.extname(entry.name))) continue;
    const parent =
      (entry as unknown as { parentPath?: string }).parentPath ?? dir;
    files.push(path.join(parent, entry.name));
  }
  return files;
};

describe("logout call site inventory", () => {
  it("resources/js 配下で /logout を叩くのは inventory 登録分のみ", async () => {
    const files = await listSourceFiles(JS_ROOT);

    const offenders: string[] = [];
    for (const file of files) {
      const content = await fs.readFile(file, "utf8");
      if (!LOGOUT_PATH_PATTERN.test(content)) continue;

      const rel = path.relative(JS_ROOT, file).split(path.sep).join("/");
      if (!LOGOUT_CALL_SITE_INVENTORY.includes(rel)) offenders.push(rel);
    }

    expect(
      offenders,
      `未登録のログアウト導線が見つかりました。Inertia visit (router.post) であることを確認して inventory へ登録し、docs/supported-browsers.md の経路 C も更新してください:\n${offenders.join("\n")}`,
    ).toEqual([]);
  });

  it("inventory 登録ファイルは Inertia visit (router.post) でログアウトする", async () => {
    for (const rel of LOGOUT_CALL_SITE_INVENTORY) {
      const content = await fs.readFile(path.join(JS_ROOT, rel), "utf8");

      // Inertia visit であること (= 着地の Inertia page を適用し clearHistory が効く)
      expect(content, `${rel} が router.post('/logout') を使っていない`).toMatch(
        /router\.post\(\s*["'`]\/logout["'`]/,
      );
      // 同一ファイルに fetch/axios による非 Inertia 経路を持ち込まない
      expect(
        NON_INERTIA_CLIENT_PATTERN.test(content),
        `${rel} に fetch/axios がある。logout が 204 完結の非 Inertia 経路になっていないか確認すること`,
      ).toBe(false);
    }
  });
});

```

### `app/Actions/Fortify/UpdateUserPassword.php`

```php
<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Enums\SecurityEventType;
use App\Models\User;
use App\Services\Security\SecurityEventRecorder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;
use Throwable;
use Webmozart\Assert\Assert;

class UpdateUserPassword implements UpdatesUserPasswords
{
    public function __construct(
        private readonly SecurityEventRecorder $recorder,
    ) {}

    /**
     * パスワード変更の検証と反映、および他デバイスのセッション・remember-me の失効。
     *
     * 強度の SSOT は PasswordPolicy (Password::defaults で配線済)。
     * 確認入力 (confirmed) は使わない (表示トグル + リセット導線 + SSO で代替)。
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', Password::default()],
        ], [
            'current_password.current_password' => __('The provided password does not match your current password.'),
        ])->validateWithBag('updatePassword');

        // 新パスワードを確定 (この後の logoutOtherDevices は保存済みハッシュに対し Hash::check する)。
        $user->forceFill([
            'password' => Hash::make($input['password']),
        ])->save();

        // 「そのユーザーが自分でパスワードを設定したか」の監査証跡。
        // SecurityEventType::PasswordChanged は enum に存在しながら記録経路が無かった
        // (/reset-password 経路は Illuminate の PasswordReset イベント → RecordSecurityEvent が
        //  既に購読済みのため本 Action だけが欠けていた)。
        // 将来、前方修正前に作られた legacy SSO ユーザーの phantom password
        // (docs/template-divergence.md D13) を判別する材料にもなる。
        // Fortify の PasswordUpdatedViaController ではなく Action 直記録にするのは、
        // vendor イベントの意味論 (「Fortify の Controller 経由」) に依存しないため。
        $this->recorder->record(SecurityEventType::PasswordChanged, $user);

        // 現在デバイスを維持しつつ他デバイスを失効させる。logoutOtherDevices は password を
        // 再ハッシュし、現在デバイスの recaller (remember-me) を新ハッシュで再発行 (現在リクエストが
        // recaller を持つ場合のみ) + OtherDeviceLogout イベントを発火する。他デバイスの実失効は
        // web グループの AuthenticateSession による password_hash 照合が担保する (correctness の要)。
        // 渡すのは current_password ではなく保存直後の新 password。
        Auth::logoutOtherDevices($input['password']);

        // database driver の場合、当該 user の他 session 行を即時削除する (best-effort)。
        $this->deleteOtherSessionRecords($user);
    }

    /**
     * 現在の session を除き、当該 user の DB session 行を削除する (session driver=database 時のみ)。
     *
     * correctness は AuthenticateSession が担うため best-effort: 失敗しても report して継続する
     * (パスワード変更自体は成功しているため正常応答を維持する)。
     */
    private function deleteOtherSessionRecords(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        // session 未初期化文脈 (console/queue 等) では現在ID除外の前提が崩れるため何もしない。
        if (! session()->isStarted()) {
            return;
        }

        $connection = config('session.connection');
        $table = config('session.table', 'sessions');

        Assert::nullOrString($connection);
        Assert::string($table);

        try {
            DB::connection($connection)
                ->table($table)
                ->where('user_id', $user->getAuthIdentifier())
                ->where('id', '!=', session()->getId())
                ->delete();
        } catch (Throwable $e) {
            report($e);
        }
    }
}

```

### `app/Http/Middleware/RequireRecentAuth.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\DataTransferObjects\Auth\RecentAuthRequiredDto;
use App\Http\Resources\Auth\RecentAuthRequiredResource;
use App\Security\RecentAuthWindow;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * 機微操作の前に generic recent-auth (step-up 再認証) を強制する単一ゲート。
 * alias: `recent-auth`。
 *
 * Fortify 生の `password.confirm` (password 専用・3h 窓) を置き換える。satisfier は
 * ConfirmRecentAuthController (password 再入力) と SocialAuthController の step-up intent
 * (再SSO) に集約され、SSO-only ユーザーも fail-closed で詰まずに再SSO へ誘導される。
 *
 * 判定:
 *   1. `recent_auth_at` が鮮度ウィンドウ内 (RecentAuthWindow) → 通過
 *   2. XHR (expectsJson) または Inertia の非 GET → 409 + { code, message, redirect }(no-store)。
 *      クライアント (素 fetch / recent-auth precheck) が再認証後に元操作を再送
 *   3. それ以外 (通常遷移) → 302 で recent-auth confirm 画面へ。元 URL を intended に保持
 */
final class RequireRecentAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();

        if (RecentAuthWindow::isFresh($session->get('recent_auth_at'))) {
            $response = $next($request);
            if (! $response instanceof Response) {
                throw new LogicException('Expected Symfony Response from middleware $next, got '.get_debug_type($response));
            }

            return $response;
        }

        $confirmUrl = route('recent-auth.confirm');

        // XHR (expectsJson) と Inertia の非 GET visit は 409 + code。クライアントが再認証後に
        // 元操作を再送する。Inertia GET は従来どおり 302 → confirm → intended GET replay が
        // 機能するため対象外。409 に x-inertia-location / x-inertia-redirect ヘッダを付けない
        // こと (Inertia core の external redirect 信号と衝突するため)。
        if ($request->expectsJson() || $this->isInertiaMutation($request)) {
            return RecentAuthRequiredResource::make(new RecentAuthRequiredDto(
                message: 'この操作には直近の再認証が必要です。',
                redirect: $confirmUrl,
            ))
                ->response()
                ->setStatusCode(409)
                ->withHeaders(['Cache-Control' => 'no-store']);
        }

        // GET は fullUrl (自 origin 確定)、それ以外は遷移元が無いので referer を intended に。
        // referer はクライアント制御ヘッダで外部 URL になり得るため、same-origin のみ採用し
        // それ以外 (外部 origin / 不在) は dashboard へフォールバックする (open redirect 防止)。
        $intended = $request->isMethod('GET')
            ? $request->fullUrl()
            : $this->sameOriginRefererOrDashboard($request);
        $session->put('url.intended', $intended);

        // 非 GET の 302 fallback (非 Inertia の素フォーム POST 等) は mutation body を保持できない。
        // confirm 成功後に「もう一度操作してください」を案内するための one-shot flag
        // (サイレント喪失防止の defense-in-depth、satisfier 側が消費する)。
        if (! $request->isMethod('GET')) {
            $session->put('recent_auth.dropped_mutation', true);
        }

        return redirect()->route('recent-auth.confirm');
    }

    /**
     * Inertia protocol の mutation visit (X-Inertia ヘッダ + 非 GET)。
     * Accept は text/html のため expectsJson() では捕捉できない。
     */
    private function isInertiaMutation(Request $request): bool
    {
        return $request->hasHeader('X-Inertia') && ! $request->isMethod('GET');
    }

    private function sameOriginRefererOrDashboard(Request $request): string
    {
        $referer = $request->headers->get('referer');
        if ($referer === null) {
            return route('dashboard');
        }

        // 完全一致 or 「origin + '/'」前置一致のみ same-origin と判定する。
        // 単純な str_starts_with($referer, $origin) だと https://app.host.evil.com を通すため、
        // 区切り '/' まで含めて比較する。
        $origin = $request->getSchemeAndHttpHost();
        if ($referer === $origin || str_starts_with($referer, $origin.'/')) {
            return $referer;
        }

        return route('dashboard');
    }
}

```

### `app/Http/Middleware/EnsureLoginMethodRemains.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\DataTransferObjects\Auth\LoginMethodRemoval;
use App\DataTransferObjects\Auth\LoginMethodRequiredDto;
use App\Http\Resources\Auth\LoginMethodRequiredResource;
use App\Models\Passkey;
use App\Models\User;
use App\Services\Auth\LoginMethodInventory;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * ログイン手段を減らす操作の前に「実行後も最低 1 つ手段が残る」ことを保証する関門。
 * alias: `ensure-login-method`。
 *
 * **評価するのは現在状態ではなく「操作が成功した後の投影状態」**。
 * 素朴に現在を数えると削除対象自身が残存手段として数えられ、
 * 「唯一の passkey を削除できてしまう」= 意図と正反対の挙動になる。
 *
 * **直列化規約 (TOCTOU 対策)**:
 *   投影が正しくても、確認と削除が別トランザクションなら破れる
 *   (passkey 2 件のユーザーが別々の passkey を同時削除 → 両方が「もう片方が残る」と判定 → 0 件)。
 *   そこで本 middleware が
 *     (1) DB::transaction() を開き
 *     (2) 対象 User 行を lockForUpdate() で取得し
 *     (3) **ロック取得後に** 投影を評価し
 *     (4) **同一トランザクション内で $next() を実行**して vendor の削除まで完了させる。
 *   ロック取得順序は User → credential に固定する。
 *   本アプリのドメイン固有規約 1「シナリオ整合の共有ロック規約」と同型の作法。
 *
 * **単一の直列化点であること**が不変条件であり、
 * tests/Architecture/LoginMethodRemovalRouteTest が deny-by-default で強制する
 * (付与漏れだけでなく **allowlist 外 route への付与**も fail させる)。
 *
 * ⚠ **適用条件 (この middleware を新しい route に付ける前に必ず読むこと)**:
 *   `$next()` を transaction 内で実行するため、controller だけでなく
 *   **同期 event listener / Responsable 変換 / redirect + flash** まで transaction に入る。
 *   したがって次を含む route には付けてはならない:
 *     - streamed / downloadable response (transaction を長時間保持する)
 *     - 外部 I/O (HTTP・S3 等。ロック保持中に外部レイテンシを持ち込む)
 *     - `afterCommit` でない queue dispatch (ロールバック時に job だけ残る)
 *   これらが必要な route を保護する場合は、本 middleware の transaction 方式を
 *   「Service 内 transaction + 判定の再評価」へ再設計すること。
 */
final class EnsureLoginMethodRemains
{
    public function __construct(
        private readonly LoginMethodInventory $inventory,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->pass($next, $request);   // 未認証は auth middleware の責務
        }

        return DB::transaction(function () use ($request, $next, $user): Response {
            // (2) 対象 User 行をロック (以降の投影評価はこのロック下でのみ有効)
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            // (3) ロック取得後に投影を評価する
            $remaining = $this->inventory->remainingAfter($locked, $this->removalFor($request, $locked));

            if ($remaining->isEmpty()) {
                return $this->reject($request);
            }

            // (4) 同一トランザクション内で削除まで完了させる
            return $this->pass($next, $request);
        });
    }

    /**
     * route から「今から何を除去しようとしているか」を決める。
     *
     * 対象 passkey が当該 User に属することは **binder が 404 で確定済み**
     * (App\Http\Routing\SelfScopedPasskeyBinder)。DTO 側でも二重に assert する。
     */
    private function removalFor(Request $request, User $user): LoginMethodRemoval
    {
        $passkey = $request->route('passkey');
        if ($passkey instanceof Passkey) {
            return LoginMethodRemoval::passkey($passkey, $user);
        }

        // 将来の除去 route (password 削除 / SSO 解除) はここに分岐を足す。
        // 未知の除去 route を素通しさせないため fail-closed で落とす
        // (LoginMethodRemovalRouteTest が「middleware を付けたのに分岐が無い」を先に検出する)。
        throw new LogicException(
            'EnsureLoginMethodRemains: 除去対象を決定できない route です。removalFor() に分岐を追加してください。',
        );
    }

    /**
     * 拒否応答。
     *
     * **Inertia には 422 JSON を返さない** (Inertia protocol 違反になり、
     * router が応答を解釈できず無言失敗する)。Inertia は 302 + errors を native に
     * 処理するため `back()->withErrors()` にして Svelte 側は `$page.props.errors` で読む。
     * 禁止事項 7 (操作系 POST は `back()->with(...)` で完結) とも整合する。
     *
     * 判別子に `expectsJson()` を使えるのは、Inertia が
     * `Accept: text/html, application/xhtml+xml` を送るため (X-Inertia は立つが Accept は HTML)。
     * 純粋な XHR (fetch + Accept: application/json) のみ 422 JSON になる。
     */
    private function reject(Request $request): Response
    {
        $dto = new LoginMethodRequiredDto(
            message: 'この操作を行うと、ログインする手段がなくなります。先に別のログイン手段（パスワードの設定、ソーシャル連携、他のパスキー）を追加してください。',
            settingsUrl: route('settings.security'),
        );

        if ($request->expectsJson()) {
            return LoginMethodRequiredResource::make($dto)
                ->response()
                ->setStatusCode(422)
                ->withHeaders(['Cache-Control' => 'no-store']);
        }

        return back()->withErrors(['login_method' => $dto->message]);
    }

    /**
     * @param  Closure(Request): mixed  $next
     */
    private function pass(Closure $next, Request $request): Response
    {
        $response = $next($request);
        if (! $response instanceof Response) {
            throw new LogicException('Expected Symfony Response from middleware $next, got '.get_debug_type($response));
        }

        return $response;
    }
}

```

### `app/Http/Controllers/Settings/ProfileController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Webmozart\Assert\Assert;

/**
 * プロフィール設定画面 (GET /settings)。
 * 削除前警告用に「唯一 Owner で他メンバーが残る組織」のスナップショットを props で返す。
 */
class ProfileController extends Controller
{
    public function index(Request $request, OrganizationMembershipService $membership): Response
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        return Inertia::render('Settings/Index', [
            // 削除前警告用。唯一 Owner で他メンバーが残る組織 (name + 各組織設定への導線 slug)。
            // 表示時点のスナップショット (最終判定は削除時にサーバーが再評価)。
            'soleOwnedOrganizations' => $membership->organizationsBlockingDeletion($user)
                ->map(fn (Organization $organization): array => [
                    'name' => $organization->name,
                    'slug' => $organization->slug,
                ])
                ->values()
                ->all(),
        ]);
    }
}

```

### `app/Http/Controllers/Auth/ConfirmRecentAuthController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\DataTransferObjects\Auth\RecentAuthProviderDto;
use App\DataTransferObjects\Auth\RecentAuthStatusDto;
use App\Enums\ProviderCapability;
use App\Http\Controllers\Controller;
use App\Http\Resources\Auth\RecentAuthStatusResource;
use App\Models\User;
use App\Security\RecentAuthState;
use App\Security\RecentAuthWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Fortify\Features;
use Webmozart\Assert\Assert;

/**
 * generic recent-auth (step-up) の confirm 画面と password satisfier。
 *
 * satisfier:
 *   - password 再入力 (`confirmPassword`、XHR=204 / Inertia=intended redirect)。
 *     SSO-only (password 未設定) は **fail-closed** で拒否。
 *   - 再SSO は SocialAuthController の step-up intent (`/auth/{provider}/redirect/step-up`)。
 *     成立時の鮮度更新はそちらで RecentAuthState 経由で行う。
 *   - パスキー検証 (`POST /passkeys/confirm`)。成立時の鮮度更新は
 *     StampRecentAuthOnPasskeyVerified が行う。**passkey しか持たないユーザーを
 *     この画面で詰ませない**ため、passkeyAvailable は canSatisfy に算入する。
 *
 * `status` はクライアント主導モーダル (precheck) の UI 補助。最終 gate は RequireRecentAuth。
 */
final class ConfirmRecentAuthController extends Controller
{
    public function __construct(
        private readonly RecentAuthState $recentAuthState,
    ) {}

    /**
     * 鮮度切れ時の 302 フォールバック確認画面 (直接遷移・非 XHR 用)。
     */
    public function show(Request $request): InertiaResponse
    {
        $status = $this->buildStatus($this->currentUser($request));

        return Inertia::render('Auth/ConfirmRecentAuth', [
            'passwordSet' => $status->passwordSet,
            'availableProviders' => array_map(
                static fn (RecentAuthProviderDto $p): array => [
                    'provider' => $p->provider,
                    'capability' => $p->capability->value,
                    'reauthUrl' => $p->reauthUrl,
                ],
                $status->availableProviders,
            ),
            'passkeyAvailable' => $status->passkeyAvailable,
            'canSatisfy' => $status->canSatisfy,
        ]);
    }

    /**
     * クライアント主導モーダルの precheck。no-store。
     */
    public function status(Request $request): JsonResponse
    {
        $status = $this->buildStatus($this->currentUser($request));

        return RecentAuthStatusResource::make($status)
            ->response()
            ->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    /**
     * password 再入力 satisfier。
     *
     * レスポンス契約:
     *   - Inertia リクエスト (standalone confirm 画面の form.post、X-Inertia あり)
     *     → `redirect()->intended(dashboard)`。RequireRecentAuth が保持した元 URL へ戻す。
     *   - 非 Inertia XHR (インラインモーダルの fetch、X-Inertia なし) → 204 No Content。
     *     クライアントはモーダルを閉じて pending action を再実行する。
     */
    public function confirmPassword(Request $request): Response|RedirectResponse
    {
        $user = $this->currentUser($request);

        $request->validate([
            'password' => ['required', 'string'],
        ]);

        // fail-closed: password 未設定 (SSO-only) は password 経路で step-up できない。
        if (! $user->hasPassword()) {
            throw ValidationException::withMessages([
                'password' => 'このアカウントはパスワードが設定されていません。SSO で再認証してください。',
            ]);
        }

        $passwordHash = $user->password;
        Assert::string($passwordHash); // hasPassword() true ⇒ 非 null string。PHPStan narrowing。

        $password = $request->string('password')->value();
        if (! Hash::check($password, $passwordHash)) {
            throw ValidationException::withMessages([
                'password' => 'パスワードが正しくありません。',
            ]);
        }

        $this->recentAuthState->confirm(method: 'password');

        // 302 fallback 経路で mutation を破棄していた場合 (RequireRecentAuth の one-shot flag)、
        // intended へ戻した画面で再操作を促す (サイレント喪失の防止)。204 経路 (インライン
        // モーダル) はクライアントが pending action を自前で再開するため読み捨てる
        // (両経路で必ず消費し、次回 step-up に持ち越さない)。
        // 注: RecentAuthState::confirm() の session migrate はデータ保持のため flag/intended は
        // 失われない。
        $droppedMutation = $request->session()->pull('recent_auth.dropped_mutation') === true;

        // standalone 画面 (Inertia) は 204 を処理できず詰むため、intended (RequireRecentAuth が
        // 保持した元 URL、無ければ dashboard) へ戻す。この分岐は Inertia protocol
        // (X-Inertia ヘッダ) のレスポンス契約用であり、Accept 等の他シグナルで判定しない。
        if ($request->hasHeader('X-Inertia')) {
            $redirect = redirect()->intended(route('dashboard'));
            if ($droppedMutation) {
                $redirect->with('info', '再認証が完了しました。先ほどの操作はまだ実行されていません。お手数ですがもう一度操作してください。');
            }

            return $redirect;
        }

        return response()->noContent()->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    private function buildStatus(User $user): RecentAuthStatusDto
    {
        $passwordSet = $user->hasPassword();

        /** @var list<RecentAuthProviderDto> $providers */
        $providers = [];
        foreach ($user->socialAccounts()->pluck('provider') as $provider) {
            if (! is_string($provider)) {
                continue; // 想定外の型は候補にしない (fail-closed)
            }
            $capability = $this->capabilityFor($provider);
            // step-up satisfier 可能な provider のみ再SSO 候補に含める。
            if (! $capability->isStepUpSatisfier()) {
                continue;
            }
            $providers[] = new RecentAuthProviderDto(
                provider: $provider,
                capability: $capability,
                reauthUrl: route('social.redirect', ['provider' => $provider, 'intent' => 'step-up']),
            );
        }

        // パスキーは登録済みなら **TOTP の有無に関係なく** 再認証に使える
        // (PasskeyLoginPolicy が縛るのは login のみ)。feature off では route ごと消えるため
        // 手段として数えない (fail-closed)。
        $passkeyAvailable = Features::enabled(Features::passkeys()) && $user->passkeys()->exists();

        $canSatisfy = $passwordSet || $providers !== [] || $passkeyAvailable;

        $recentAuthAt = session()->get('recent_auth_at');
        $recent = RecentAuthWindow::isFresh($recentAuthAt);
        // 契約: recent===true ⇒ confirmedAt は int / recent===false ⇒ null (fail-closed)。
        // recent===true のとき isFresh() の前提から $recentAuthAt は必ず int。
        $confirmedAt = ($recent && is_int($recentAuthAt)) ? $recentAuthAt : null;

        return new RecentAuthStatusDto(
            recent: $recent,
            passwordSet: $passwordSet,
            availableProviders: $providers,
            passkeyAvailable: $passkeyAvailable,
            canSatisfy: $canSatisfy,
            confirmedAt: $confirmedAt,
        );
    }

    /**
     * provider の step-up capability を config (template.social_providers.{provider}.capability)
     * から解決する。未宣言・解釈不能は IdentityOnly (= satisfier 不可) に倒す (fail-closed)。
     */
    private function capabilityFor(string $provider): ProviderCapability
    {
        $raw = config('template.social_providers.'.$provider.'.capability');

        return (is_string($raw) ? ProviderCapability::tryFrom($raw) : null)
            ?? ProviderCapability::IdentityOnly;
    }

    private function currentUser(Request $request): User
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        return $user;
    }
}

```

