【アプリの使命 (North Star)】
<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】
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

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest / DTO + JsonResource パターン / Laratrust RBAC
- フロントは vitest + @testing-library/svelte、ESLint 9 flat config、Tailwind v4
- 本バッチは **フロントエンド専用** (PHP 変更なし・DB 非依存)

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null 安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性 — 本バッチは PHP 変更なしのため、代わりに **TypeScript strict 適合性**を見ること
4. テスト計画の網羅性（各施策にテスト、Red/characterization の区別が正しいか）
5. DTO/JsonResource パターンの遵守（該当なければスキップ）
6. Inertia Props vs API Response の使い分け（同上）
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript 型定義、テストが変更対象に含まれているか）
9. セキュリティ（AGENTS.md のセキュリティ不変条件。特に {@html} の扱い）
10. DESIGN.md 準拠: `/DESIGN.md` が design token の canonical source。color / radius / typography を token 経由で参照する設計か、hex 直書きを増やさないか。token 変更時は `resources/css/tokens.css` との同期を設計に織り込んでいるか
11. Atomic Design 準拠: `resources/js/components/` の atoms/molecules/organisms/templates の責務分離に沿った配置か

**特に厳しく見てほしい点**:
- ESLint flat config の `calculateConfigForFile()` が返す解決結果の形状に対する仮定 (rules の severity 表現、linterOptions / languageOptions が含まれるか) が正しいか
- `videoConstraints` の .ts 移動が挙動を厳密に保つか
- コントラストのペア集合と閾値の設計判断が妥当か (実測値も添えてある)

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書
# 詳細設計: frontend-baseline-gates

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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

> 本バッチはフロントエンド専用で PHP を 1 行も触らないが、
> **禁止事項 2 の精神 (「型を緩めて黙らせる」ことの禁止) はフロントにも適用**する。
> ESLint `globals` に型専用名を足して `no-undef` を黙らせる、
> コントラスト gate のペア集合を縮めて green を作る、はいずれもこの禁止に相当する。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）— 本バッチは PHP 変更なしのため該当なし
- **Pest** テストフレームワーク（`composer test`）— 同上
- **RefreshDatabase** + `--parallel` 並列実行 — 本バッチは **DB 非依存**
- **テストデータは必ず Factory で生成** — 本バッチは Factory 不要 (新モデルなし)
- **DTO + JsonResource** パターン — 本バッチは該当なし
- **アーリーリターン** 推奨
- **コードフォーマット**: `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- **JavaScript 禁止・TypeScript 必須** (AGENTS.md)。新規ファイルはすべて `.ts`
- フロントは Svelte 5 runes + DS token/ramp のみ(`DESIGN.md` が canonical)

## 概念設計リファレンス

`devnotes/20260805-0101-frontend-baseline-gates/conceptual-design.md`
（conceptual-review Round 4 で **APPROVED**）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | `videoConstraints` を `.ts` へ移す (型専用名を `.svelte` から除去) | `resources/js/lib/capture/camera.ts`, `resources/js/components/features/capture/CameraRecorder.svelte`, `tests/js/lib/capture/camera.test.ts`, `tests/js/components/features/capture/CameraRecorder.test.ts` | 高 (施策 2 の前提) |
| 2 | ESLint に `no-undef` + `noInlineConfig` を入れる | `package.json`, `eslint.config.js` | 高 |
| 3 | 死んだ `eslint-disable` directive の撤去 | `resources/js/pages/Settings/Security.svelte` | 高 (施策 2 と同時) |
| 4 | `svelte-no-undef-gate` の新設 | `tests/js/architecture/svelte-no-undef-gate.test.ts` (新規), `docs/template-divergence.md` | 高 |
| 5 | DESIGN.md パーサの共有ヘルパ化 | `tests/js/styles/design-md.ts` (新規), `tests/js/styles/canonical-source-parity.test.ts` | 中 (施策 6 の前提) |
| 6 | `contrast-invariant` の新設 + `danger` トークン是正 | `tests/js/architecture/contrast-invariant.test.ts` (新規), `tests/js/styles/inventory.ts`, `DESIGN.md`, `resources/css/tokens.css` | 高 |
| 7 | 申し送りの記録 (実装なし) | 設計書のみ | — |

**実装順序**: 1 → 2 → 3 → 4 → 5 → 6。
施策 1 を先に済ませないと施策 2 で `pnpm lint` が赤くなる。

---

## 施策 1: `videoConstraints` を `.ts` へ移す

### 変更箇所

- `resources/js/lib/capture/camera.ts` — 末尾に `videoConstraints()` を追加
- `resources/js/components/features/capture/CameraRecorder.svelte` (L167-170 削除 / L177-180 呼び出し変更 / import 追加)

### 波及変更

- TypeScript 型定義: なし (`MediaTrackConstraints` は lib.dom の ambient 型。新規 alias 不要)
- API Resource/DTO: なし
- テストファイル:
  - `tests/js/lib/capture/camera.test.ts` — `videoConstraints` の単体テスト追加 (**R4a = Red**)
  - `tests/js/components/features/capture/CameraRecorder.test.ts` — 再取得経路の facingMode 反映を
    characterization として追加 (**C1 = Green → Green**)

### 現行コード

`CameraRecorder.svelte` L167-180:

```svelte
    // --- getUserMedia の制約を facingMode から組む (S6) ---
    function videoConstraints(): MediaTrackConstraints {
        return { facingMode };
    }

    // 副作用なしの取得 (classify 結果を返すだけ。onCameraUnavailable/error を呼ばない)。
    // 呼び出し前に stream=null であること (reacquire 前は releaseCamera 済み)。stream ??= のため
    // 既存 stream があれば再取得しない = flip の reacquire では releaseCamera() 後に呼ぶ。
    async function acquireStream(): Promise<CameraErrorClassification | { kind: "ok" }> {
        try {
            stream ??= await navigator.mediaDevices.getUserMedia({
                video: videoConstraints(), // facingMode を反映 (現行の "environment" 直書きを置換)
                audio: true,
            });
```

### 変更後コード

`resources/js/lib/capture/camera.ts` (末尾に追加):

```ts
/**
 * getUserMedia の video 制約を facingMode から組む (S6)。
 *
 * **呼出時点の facingMode を引数で受ける純関数**にしてある。
 * component 側でクロージャから読む形に戻したり、結果をキャッシュしたりしないこと
 * (flip 後の再取得で古い facing mode を使う後退になり、実機でしか気づけない)。
 *
 * ここに置く理由: 型専用 interface (`MediaTrackConstraints` = WebIDL dictionary) は
 * 実行時グローバルではないため .svelte 側では ESLint no-undef を解決できない。
 * .ts へ置けば tsc の型検査対象にもなる (eslint.config.js の globals 方針を参照)。
 */
export function videoConstraints(mode: FacingMode): MediaTrackConstraints {
    return { facingMode: mode };
}
```

`CameraRecorder.svelte`:

```svelte
    import {
        classifyGetUserMediaError,
        formatElapsed,
        nextFacingMode,
        preferredRecordingMimeType,
        supportsPauseResume,
        videoConstraints,
    } from "@/lib/capture/camera";
```

```svelte
    // 副作用なしの取得 (classify 結果を返すだけ。onCameraUnavailable/error を呼ばない)。
    // 呼び出し前に stream=null であること (reacquire 前は releaseCamera 済み)。stream ??= のため
    // 既存 stream があれば再取得しない = flip の reacquire では releaseCamera() 後に呼ぶ。
    async function acquireStream(): Promise<CameraErrorClassification | { kind: "ok" }> {
        try {
            stream ??= await navigator.mediaDevices.getUserMedia({
                // 呼出時点の facingMode を渡す (reacquireWithFacing が代入した直後の値を読む)。
                // キャッシュ禁止 — キャッシュすると flip 後も旧カメラで取得してしまう。
                video: videoConstraints(facingMode),
                audio: true,
            });
```

`videoConstraints()` の宣言 (L167-170) は削除する。

### 挙動同値性の根拠

`reacquireWithFacing()` は `facingMode = target;` の**直後**に `acquireStream()` を呼ぶ
(L447-448 / L451-452)。`acquireStream()` 内で `videoConstraints(facingMode)` と
呼出時点評価すれば、旧クロージャ版とまったく同じ値を読む。
`flipCamera()` の live stream 無し経路 (L409-411) は state のみ更新し、
次回 `acquireStream()` で反映される — こちらも同値。

### テスト計画

- [ ] **R4a (Red)**: `tests/js/lib/capture/camera.test.ts` に
      `videoConstraints("environment")` → `{ facingMode: "environment" }` /
      `videoConstraints("user")` → `{ facingMode: "user" }` を追加。
      実装前は import 解決失敗で fail することを確認する
- [ ] **C1 (characterization / Green → Green)**:
      `CameraRecorder.test.ts` の
      「カメラ反転 (getSettings().facingMode が undefined): 未検証扱いで再取得経路へ倒す (R1-W)」
      (L951-967) に **2 回目の `getUserMedia` 制約の assert** を足す:

      ```ts
      const reacquireCall = (getUserMediaMock.mock.calls[1] as unknown[])[0] as {
          video: MediaTrackConstraints;
      };
      expect(reacquireCall.video).toMatchObject({ facingMode: "user" });
      ```

      これは **移動前に green** であることを確認してからコードを動かす
      (移動前に red ならそれは現行挙動を固定できていない = characterization として無効)
- [ ] 既存の
      「カメラ反転 (録画前・live stream 無し): flip で次回 getUserMedia の facingMode が 'user'」
      (L917) は変更なしで green を維持する
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認 — 該当なし (vitest)

### リスク

- `videoConstraints` を呼出時点評価から外す実装ミスが唯一のリスク。C1 が閉じる
- `camera.ts` は撮影ドメインの薄いブラウザ API ヘルパという責務を維持する
  (汎用 utility 化しない。他ドメインから流用しない)

---

## 施策 2: ESLint に `no-undef` + `noInlineConfig` を入れる

### 変更箇所

- `package.json` — devDependencies に `globals` を追加
- `eslint.config.js` — トップレベル `linterOptions` / svelte ブロックの `languageOptions` + `rules`

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/js/architecture/svelte-no-undef-gate.test.ts` (施策 4 で新設)

### 現行コード

```js
import betterTailwind from "eslint-plugin-better-tailwindcss";
import svelte from "eslint-plugin-svelte";
import svelteParser from "svelte-eslint-parser";
import tsParser from "@typescript-eslint/parser";
// ...
export default [
    {
        ignores: [ /* ... */ ],
    },
    // ...
    {
        files: ["**/*.svelte"],
        plugins: { svelte },
        rules: {
            "svelte/require-each-key": "error",
            "svelte/prefer-svelte-reactivity": "error",
            "svelte/prefer-writable-derived": "error",
            "svelte/no-useless-mustaches": ["error", { ignoreStringEscape: true }],
        },
    },
];
```

### 変更後コード

```js
import betterTailwind from "eslint-plugin-better-tailwindcss";
import svelte from "eslint-plugin-svelte";
import svelteParser from "svelte-eslint-parser";
import tsParser from "@typescript-eslint/parser";
import globals from "globals";

// ... (betterTailwindSettings は現行のまま)

/*
 * .svelte に載せる実行時グローバル。
 *
 * **ここに載せてよいのは「実行時に存在するグローバル」だけ**。
 * 型専用名 (WebIDL dictionary = MediaTrackConstraints / RequestInit 等) を足すことは
 * 禁止する。足すと lint は緑になるが、同名を実行時の値として誤用したときにも
 * no-undef が黙る = gate を入れる変更で gate に穴を開けることになる
 * (PHPStan エラーを widen して黙らせるのと同じ悪手。AGENTS.md 禁止事項 2)。
 *
 * .svelte の型注釈に型専用名が必要になったら .ts 側へ逃がす:
 *   1. ロジックごと .ts に移す (第一選択。.ts は tsc の検査対象になるので純増)
 *      — 実例: lib/capture/camera.ts の videoConstraints()
 *   2. 移せない (component props の型等) なら .ts で
 *      `export type X = MediaTrackConstraints;` と別名 export し、
 *      .svelte からは `import type` で参照する (module 参照は no-undef の対象外)
 *
 * アプリ固有の実行時グローバル (window に生やす等) が将来必要になったら、
 * 下の APP_RUNTIME_GLOBALS に理由コメント付きで登録する。
 * svelte-no-undef-gate が「globals.browser + APP_RUNTIME_GLOBALS と完全一致」を
 * deny-by-default で検査するので、無登録の差分は CI で落ちる。
 */
const APP_RUNTIME_GLOBALS = {
    // 現時点で登録なし。追加時は「なぜ実行時グローバルなのか」を必ず添えること。
};

const svelteGlobals = { ...globals.browser, ...APP_RUNTIME_GLOBALS };

export default [
    /*
     * inline の eslint-disable / eslint-enable を一切許可しない。
     * ルールを黙らせたいときの唯一の手段は **本ファイルの file-scoped override**。
     * override を認めるのは次の 3 条件をすべて満たすときだけ:
     *   (a) 抑制対象が具体的な 1 ファイル (または明示列挙されたファイル群) に閉じている
     *   (b) なぜ安全かがコード側の日本語コメントで説明されている
     *   (c) ここに理由と再検討条件 (いつ外せるか) を書く
     * config に集約すれば diff に必ず現れ、レビュー可能かつ数えられる。
     */
    { linterOptions: { noInlineConfig: true } },
    {
        ignores: [ /* 現行のまま */ ],
    },
    // ... (svelte parser ブロック / ts parser ブロック / better-tailwindcss ブロックは現行のまま)
    {
        files: ["**/*.svelte"],
        plugins: { svelte },
        languageOptions: {
            globals: svelteGlobals,
        },
        rules: {
            // .svelte は tsc の検査対象外 (tsc --listFiles に 1 件も現れない)。
            // 未定義識別子を捕まえる機構がここにしか無いので error 固定
            // (spirux:T1054 = SSO 接続追加画面のクラッシュと同型の事故を止める)。
            "no-undef": "error",
            "svelte/require-each-key": "error",
            "svelte/prefer-svelte-reactivity": "error",
            "svelte/prefer-writable-derived": "error",
            "svelte/no-useless-mustaches": ["error", { ignoreStringEscape: true }],
        },
    },
];
```

> `eslint.config.js` は既存の `.js` を維持する。
> ESLint flat config の設定ファイル自体であり、拡張子を変えると解決経路が変わる。
> 新規 JavaScript の追加ではなく既存資産の維持であり、AGENTS.md の「JavaScript 禁止」に反しない。

### 検証

実測済み: この config で `pnpm lint` の `no-undef` 違反は
`CameraRecorder.svelte:168` の `MediaTrackConstraints` 1 件のみ。
施策 1 完了後は **0 件**。

### テスト計画

- [ ] 施策 4 の `svelte-no-undef-gate` が config を固定する (禁止事項 1 対応)
- [ ] `pnpm lint` が exit 0
- [ ] `pnpm typecheck` が exit 0

### リスク

- `globals` パッケージ追加は supply-chain 対象。`pnpm run audit:gate` を通す
  (`globals` は ESLint 公式エコシステムの依存で、既に `eslint` の推移的依存として広く流通)
- `noInlineConfig` により、将来 inline disable を書いた PR は「効かない」状態になる。
  意図せず無言で無効化されるのを防ぐため、上記コメントで運用契約を config に固定する

---

## 施策 3: 死んだ `eslint-disable` directive の撤去

### 変更箇所

- `resources/js/pages/Settings/Security.svelte` (L465)

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし / テストファイル: なし

### 現行コード

```svelte
    <!-- QR はサーバ提供の SVG をそのまま描画する。svg 文字列に属性を注入せず、
         wrapper を role="img" にしてアクセシブルネームを与える (H14) -->
    <div
        role="img"
        aria-label="2 要素認証の設定用 QR コード"
        class="self-start rounded-md border border-border bg-surface p-4"
        data-testid="two-factor-qr"
    >
        <!-- eslint-disable-next-line svelte/no-at-html-tags -->
        {@html qrSvg}
    </div>
```

### 変更後コード

```svelte
    <!-- QR はサーバ提供の SVG をそのまま描画する。svg 文字列に属性を注入せず、
         wrapper を role="img" にしてアクセシブルネームを与える (H14) -->
    <div
        role="img"
        aria-label="2 要素認証の設定用 QR コード"
        class="self-start rounded-md border border-border bg-surface p-4"
        data-testid="two-factor-qr"
    >
        {@html qrSvg}
    </div>
```

### 根拠 (実査結果)

- `svelte/no-at-html-tags` は **現行 config で有効化されていない**
  (svelte ブロックのルールは `require-each-key` / `prefer-svelte-reactivity` /
  `prefer-writable-derived` / `no-useless-mustaches` の 4 本のみ)。
  この directive は**何も抑制していない死んだコメント**である
- 実験的に `noInlineConfig: true` を入れて `eslint resources/js` を実行したところ
  **exit 0 / 出力ゼロ**。「放置すると lint が赤くなる」という当初想定は実測で否定された
- `noInlineConfig` 体制下でこの死んだコメントを残すと
  「ここは抑制済み」という**誤ったシグナル**になる (罠)。撤去が正しい
- `{@html qrSvg}` の正当性は直上の日本語コメントが既に説明しており、情報は失われない
- **`svelte/no-at-html-tags` をルールとして有効化することは本バッチではしない**。
  c2c 台帳 `eslint-svelte-ts-baseline` の boundary が列挙する t0 のルール集合に含まれず、
  aicue 単独で足すと新しい divergence を作る。家系提案として申し送り (§施策 7-2) へ
- `resources/js` 配下の inline directive はこの 1 件のみ (grep 実測)

### テスト計画

- [ ] `pnpm lint` が exit 0
- [ ] `tests/js/pages/Settings/Security.test.ts` 等の既存テストが green を維持
      (DOM 出力は不変)

### リスク

なし (コメント削除のみ。ビルド出力・DOM ともに不変)

---

## 施策 4: `svelte-no-undef-gate` の新設

### 変更箇所

- `tests/js/architecture/svelte-no-undef-gate.test.ts` (新規)
- `docs/template-divergence.md` (D11 追加)

### 波及変更

- TypeScript 型定義: なし (テスト内で完結)
- API Resource/DTO: なし
- テストファイル: 本体が新規テスト

### 設計方針

laravel-claude-template の実物が本環境に存在しない (mirror 未取得) ため、
テンプレ実装をそのまま移植できない。**config を静的に検査する型**で実装する。

1. **ESLint の公開 API `calculateConfigForFile()` で実効設定を解決**する
   (config オブジェクトの目視形状マッチはしない)
2. **`resources/js` 配下の `.svelte` を全件列挙**して各ファイルの実効設定を検査する。
   代表 1 件では file-scoped override による解除を見逃す。**列挙 0 件なら fail**
   (走査の空振り防止)。新規ファイルは自動的に対象になる = deny-by-default
3. **検査ロジックは純関数**に切り出し、正の入力 (実 config の解決結果) と
   負の入力 (解決結果をテスト内で加工した plain object) の両方を通す。
   ESLint の flat config マージ規則そのものを試験対象にしない
4. `globals` は **allowlist (完全一致)**。`globals.browser` のキー集合と一致すること
   (denylist は未知の型名を素通しさせ、かつ「denylist があるから安全」という
   誤った保証感を作るため採らない)

### 変更後コード (新規ファイル)

```ts
import { describe, it, expect } from "vitest";
import fs from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { ESLint } from "eslint";
import globals from "globals";

/*
 * svelte-no-undef-gate — .svelte の未定義識別子検出を config レベルで固定する。
 *
 * 背景: .svelte は tsc の検査対象外 (tsc --listFiles に 1 件も現れない)。
 * 未定義識別子を捕まえる機構は eslint の no-undef **だけ**であり、
 * これが外れると .svelte 全体が無検査に戻る (spirux:T1054 = SSO 接続追加画面の
 * クラッシュと同型の事故が再発する)。
 *
 * 検査する 4 つの不変条件:
 *   1. resources/js 配下の **全** .svelte で no-undef が error
 *   2. inline の eslint-disable が効かない (linterOptions.noInlineConfig)
 *   3. languageOptions.globals が globals.browser と **完全一致**
 *      (型専用名を混ぜて no-undef を骨抜きにしない。追加は eslint.config.js の
 *       APP_RUNTIME_GLOBALS へ理由付きで登録し、本 gate 側も同時に更新する)
 *   4. 走査対象が 0 件でない (空振り gate を green として扱わない)
 *
 * 実装は laravel-claude-template のものと **別実装**。同一不変条件・別実装の
 * divergence として docs/template-divergence.md D11 に記録している。
 */

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.resolve(HERE, "../../../");
const RESOURCES_JS = path.join(REPO_ROOT, "resources/js");

/** 検査対象に落とし込んだ実効 config の view (純関数への入力) */
interface ResolvedConfigView {
    readonly rules?: Record<string, unknown>;
    readonly linterOptions?: { readonly noInlineConfig?: boolean };
    readonly languageOptions?: { readonly globals?: Record<string, unknown> };
}

/** 期待する globals キー集合 (allowlist。eslint.config.js の svelteGlobals と一対一) */
const EXPECTED_GLOBAL_KEYS = Object.keys(globals.browser).sort();

/**
 * 実効 config が不変条件を満たすか検査し、違反理由を返す (空配列 = 適合)。
 * ESLint の設定マージ規則ではなく **解決結果**だけを見る純関数。
 */
function assertSvelteNoUndefConfig(resolved: ResolvedConfigView): string[] {
    const problems: string[] = [];

    const noUndef = resolved.rules?.["no-undef"];
    // flat config の解決結果では severity は数値 (2 = error) を含む配列で返る
    const severity = Array.isArray(noUndef) ? noUndef[0] : noUndef;
    if (severity !== 2 && severity !== "error") {
        problems.push(`no-undef が error でない (実効値: ${JSON.stringify(noUndef)})`);
    }

    if (resolved.linterOptions?.noInlineConfig !== true) {
        problems.push("linterOptions.noInlineConfig が true でない (inline disable が効いてしまう)");
    }

    const actualKeys = Object.keys(resolved.languageOptions?.globals ?? {}).sort();
    const extra = actualKeys.filter((k) => !EXPECTED_GLOBAL_KEYS.includes(k));
    const missing = EXPECTED_GLOBAL_KEYS.filter((k) => !actualKeys.includes(k));
    if (extra.length > 0) {
        problems.push(
            `globals に globals.browser 外のキーがある: ${extra.join(", ")} ` +
                `(型専用名の登録は禁止。実行時グローバルなら eslint.config.js の ` +
                `APP_RUNTIME_GLOBALS へ理由付きで登録し、本テストの期待値も同時に更新すること)`,
        );
    }
    if (missing.length > 0) {
        problems.push(`globals に globals.browser のキーが不足: ${missing.slice(0, 5).join(", ")}…`);
    }

    return problems;
}

async function svelteFiles(dir: string): Promise<string[]> {
    const out: string[] = [];
    for (const e of await fs.readdir(dir, { recursive: true, withFileTypes: true })) {
        if (e.isFile() && e.name.endsWith(".svelte")) out.push(path.join(e.parentPath, e.name));
    }
    return out.sort(); // 失敗メッセージを走査順の環境差で揺らさない
}

describe("architecture/svelte-no-undef-gate", () => {
    it("resources/js 配下の全 .svelte で no-undef=error / noInlineConfig / globals 完全一致", async () => {
        const files = await svelteFiles(RESOURCES_JS);
        // 空振り防止: 走査が 0 件なら gate は何も守っていない
        expect(files.length, "resources/js 配下に .svelte が 1 件も無い (走査が空振りしている)").
            toBeGreaterThan(0);

        const eslint = new ESLint({ cwd: REPO_ROOT });
        const offenders: string[] = [];
        for (const file of files) {
            const resolved = (await eslint.calculateConfigForFile(file)) as ResolvedConfigView;
            for (const problem of assertSvelteNoUndefConfig(resolved)) {
                offenders.push(`${path.relative(REPO_ROOT, file)}: ${problem}`);
            }
        }
        expect(
            offenders,
            `.svelte の未定義識別子検出が無効化されている。eslint.config.js を確認すること: \n` +
                offenders.join("\n"),
        ).toEqual([]);
    });

    /*
     * 負のコントロール: 検査器が実際に点灯することを、解決結果を加工した
     * plain object で確認する (ESLint のマージ規則は試験対象にしない)。
     */
    it("負のコントロール: no-undef 解除 / noInlineConfig 無効 / globals 汚染を検出する", () => {
        const sound: ResolvedConfigView = {
            rules: { "no-undef": [2] },
            linterOptions: { noInlineConfig: true },
            languageOptions: { globals: { ...globals.browser } },
        };
        expect(assertSvelteNoUndefConfig(sound), "正のコントロール").toEqual([]);

        expect(
            assertSvelteNoUndefConfig({ ...sound, rules: { "no-undef": [0] } }),
            "no-undef=off",
        ).toHaveLength(1);
        expect(
            assertSvelteNoUndefConfig({ ...sound, linterOptions: { noInlineConfig: false } }),
            "noInlineConfig=false",
        ).toHaveLength(1);
        expect(
            assertSvelteNoUndefConfig({
                ...sound,
                languageOptions: {
                    globals: { ...globals.browser, MediaTrackConstraints: "readonly" },
                },
            }),
            "型専用名の混入",
        ).toHaveLength(1);
    });
});
```

### `docs/template-divergence.md` への追記 (D11)

```markdown
## D11 ✅ svelte-no-undef-gate を config 静的検査型で別実装 (同一不変条件・別実装)

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| gate 実装 | `tests/js/architecture/svelte-no-undef-gate.test.ts` (実装未確認 = mirror 未取得) | 同名ファイルを **ESLint `calculateConfigForFile()` による実効設定の静的検査**として独自実装 |

### なぜ正当な差分か(logic-driven)
c2c 台帳 `atomic-design-gates` の AG-023 裁定 (2026-08-05) で
「aicue に svelte-no-undef-gate を補完する」ことは確定しているが、
laravel-claude-template の mirror が本環境に無く、テンプレ実装を読めない。
実装を待って不変条件を無防備のまま放置するより、
**同じ不変条件を別実装で先に固定する**方が実害を早く閉じられる。

### 揃えている不変条件(これは保証し続ける)
> 「`resources/js` 配下の**全** `.svelte` で ESLint `no-undef` が error であり、
> inline の eslint-disable では無効化できず (`linterOptions.noInlineConfig`)、
> `languageOptions.globals` は実行時グローバル (`globals.browser` + 明示登録) と完全一致する
> (型専用名を混ぜて no-undef を骨抜きにしない)」

`tests/js/architecture/svelte-no-undef-gate.test.ts` が
全 `.svelte` の実効設定を解決して検査し、走査 0 件でも fail する。
検査ロジックは純関数に切り出し、正負のコントロールで検出器の実効性を固定している。

**運用契約 (noInlineConfig 体制)**: ルールを黙らせる唯一の手段は
`eslint.config.js` の file-scoped override。override を認めるのは
(a) 抑制対象が具体的な 1 ファイル (または明示列挙) に閉じている
(b) なぜ安全かがコード側コメントで説明されている
(c) config 側に理由と再検討条件が書かれている — の 3 条件をすべて満たすときだけ。

### 収束条件
laravel-claude-template の mirror が取得できた時点でテンプレ実装と突き合わせ、
実装を寄せられるなら本エントリを解消する。

### 関連
- 実装: `tests/js/architecture/svelte-no-undef-gate.test.ts`, `eslint.config.js`
- 設計: `devnotes/20260805-0101-frontend-baseline-gates/detailed-design.md` 施策 4
- 台帳: c2c `atomic-design-gates` AG-023 (2026-08-05 裁定), `eslint-svelte-ts-baseline`
```

### テスト計画

- [ ] **R1 (Red)**: 現行 `eslint.config.js` (施策 2 未適用) に対して本テストを回すと fail する。
      no-undef 不在 / noInlineConfig 不在 / globals 不足の 3 件が全 `.svelte` 分報告される
- [ ] 施策 2 適用後は green
- [ ] 負のコントロール 3 パターンがそれぞれ 1 件だけ点灯する
- [ ] 走査 0 件なら fail する (`RESOURCES_JS` を存在しないパスに向けて手元確認)

### リスク

- `ESLint#calculateConfigForFile()` の返す severity 表現 (`2` / `"error"` / 配列) は
  ESLint のバージョンで揺れうる。純関数側で `Array.isArray` + `2 | "error"` の
  両方を受けることで吸収する
- 全 `.svelte` (約 100 件規模) の config 解決はファイルあたり数 ms。
  `ESLint` インスタンスは 1 個を使い回すのでキャッシュが効く

---

## 施策 5: DESIGN.md パーサの共有ヘルパ化

### 変更箇所

- `tests/js/styles/design-md.ts` (新規)
- `tests/js/styles/canonical-source-parity.test.ts` (L16-42, L69-75, L89-99 をヘルパ呼び出しに置換)

### 波及変更

- TypeScript 型定義: `design-md.ts` の公開 API 型 (下記)
- API Resource/DTO: なし
- テストファイル: `canonical-source-parity.test.ts` (既存・挙動不変)、
  `contrast-invariant.test.ts` (施策 6 で新規・本ヘルパの利用者)

### 設計方針

**パーサを二重実装しない**。既存 `canonical-source-parity.test.ts` の
frontmatter パーサをそのまま切り出し、両テストが同一実装を共有する。
**抽象度は上げない** (今必要なものだけ作る)。

### 変更後コード (新規ファイル)

```ts
/**
 * DESIGN.md (canonical source) の frontmatter パーサ — 検査テスト共有。
 *
 * canonical-source-parity (DESIGN.md ⇔ tokens.css の同期) と
 * contrast-invariant (色の可読性) が **同一のパーサ**を使うためのヘルパ。
 * パーサを二重実装すると「片方だけが読める DESIGN.md」という状態を作れてしまう。
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const HERE = path.dirname(fileURLToPath(import.meta.url));
export const REPO_ROOT = path.resolve(HERE, "../../../");

const designMd = fs.readFileSync(path.join(REPO_ROOT, "DESIGN.md"), "utf-8");

/** DESIGN.md 冒頭の `---` で囲まれた frontmatter 本文 */
export const frontmatter: string = (() => {
    const m = designMd.match(/^---\n([\s\S]*?)\n---/);
    if (!m) throw new Error("DESIGN.md frontmatter not found");
    return m[1];
})();

/** frontmatter `colors:` → `{ トークン名 → "#rrggbb" (小文字) }` */
export function designColors(): Map<string, string> {
    const section = frontmatter.match(/^colors:\n((?: {4}\S[^\n]*\n)+)/m);
    if (!section) throw new Error("DESIGN.md colors section not found");
    const map = new Map<string, string>();
    for (const line of section[1].matchAll(/^ {4}([a-z-]+): "(#[0-9A-Fa-f]{6})"$/gm)) {
        map.set(line[1], line[2].toLowerCase());
    }
    return map;
}

/** frontmatter `rounded:` → `{ 段名 → "Npx" }` */
export function designRounded(): Map<string, string> {
    const section = frontmatter.match(/^rounded:\n((?: {4}\S[^\n]*\n)+)/m);
    if (!section) throw new Error("DESIGN.md rounded section not found");
    const map = new Map<string, string>();
    for (const m of section[1].matchAll(/^ {4}([a-z]+): (\d+px)$/gm)) {
        map.set(m[1], m[2]);
    }
    return map;
}

/** frontmatter `typography.<name>:` → `{ プロパティ名 → 値 }` */
export function designRamp(name: string): Record<string, string> {
    const m = frontmatter.match(new RegExp(`^ {4}${name}:\\n((?: {8}\\S[^\\n]*\\n)+)`, "m"));
    if (!m) throw new Error(`DESIGN.md typography ramp not found: ${name}`);
    const props: Record<string, string> = {};
    for (const line of m[1].matchAll(/^ {8}([a-zA-Z]+): "?([^"\n]+)"?$/gm)) {
        props[line[1]] = line[2];
    }
    return props;
}
```

`canonical-source-parity.test.ts` は以下を削除して import に置換する:

```ts
import { REPO_ROOT, designColors, designRamp, designRounded } from "./design-md";
```

- L16-17 の `ROOT` / `designMd` は `REPO_ROOT` に置換 (`tokensCss` の読み込みは残す)
- L20-24 の `frontmatter` IIFE、L26-34 の `designColors()`、
  L70-75 の rounded パース、L89-99 の `designRamp()` を削除
- `cssColorTokens()` / `cssRamp()` は tokens.css 側のパーサなので**移さない**
  (DESIGN.md ヘルパの責務外)

### テスト計画

- [ ] `canonical-source-parity.test.ts` は **assertion を 1 つも変えずに** green を維持する
      (純粋な refactor。挙動が変わったら移し方を間違えている)
- [ ] `pnpm typecheck` が exit 0

### リスク

- refactor 中に正規表現を書き換えてしまうと parity 検査が緩む。
  **正規表現は 1 文字も変えずに移す**こと (差分レビューで確認)

---

## 施策 6: `contrast-invariant` の新設 + `danger` トークン是正

### 変更箇所

- `tests/js/architecture/contrast-invariant.test.ts` (新規)
- `tests/js/styles/inventory.ts` (役割宣言を追加)
- `DESIGN.md` (frontmatter L18 / 本文 L104)
- `resources/css/tokens.css` (L31)

> ファイル配置は c2c 台帳 `design-token-system` の `gates:` が宣言している正典パス
> `tests/js/architecture/contrast-invariant.test.ts` に合わせる (`tests/js/styles/` ではない)。
> 無用な divergence を作らないため。

### 波及変更

- TypeScript 型定義: `inventory.ts` の新規 export (下記)
- API Resource/DTO: なし
- テストファイル:
  - `tests/js/architecture/contrast-invariant.test.ts` (新規)
  - `canonical-source-parity.test.ts` — DESIGN.md と tokens.css を**同一 PR で**更新すれば green
  - `tests/js/components/molecules/DangerZone.test.ts` / `organisms/ConfirmDialog.test.ts` —
    class 名 (`bg-danger`) を assert しており**色値に依存していない** (実測確認済み)。変更不要
- Browser テスト (Chromium + WebKit) — 色値の assert なし。変更不要

### 実測データ (WCAG 相対輝度比)

現行テーマの全 opaque text ペアを実測したところ、**`danger` (#DC2626) だけが AA 4.5:1 を割る**:

| ペア | 現行 | 是正後 |
|---|---|---|
| `text-neutral` on `bg-danger` (Button `danger` / `danger-outline` hover / NotificationBell) | **4.39 ✗** | **5.89 ✓** |
| `text-danger` on `bg-neutral` (Button `danger-ghost` / 状態テキスト) | **4.39 ✗** | **5.89 ✓** |
| `text-danger` on `bg-surface` (Alert danger の見出し) | 4.83 ✓ | 6.47 ✓ |
| `text-neutral` on `bg-success` | 4.56 ✓ | — |
| `text-neutral` on `bg-warning` | 4.57 ✓ | — |
| `text-neutral` on `bg-primary` | 4.70 ✓ | — |
| `text-neutral` on `bg-tertiary` | 4.98 ✓ | — |
| `text-secondary` on `bg-neutral` | 7.03 ✓ | — |
| `text-primary` on `bg-neutral` | 16.12 ✓ | — |

是正後の**最小値は 4.563** (`text-neutral` on `bg-success`)。全 21 ペアが 4.5:1 以上。

### `danger` 是正の根拠 (色を好みで弄るのではない)

既存パレットは Tailwind 由来で、**状態色/アクセントは軒並み -700 段**:

| トークン | 値 | Tailwind |
|---|---|---|
| `tertiary` | #0F766E | teal-**700** |
| `success` | #15803D | green-**700** |
| `warning` | #B45309 | amber-**700** |
| `danger` | #DC2626 | red-**600** ← 唯一の -600 |

`danger` だけが -600 段という**体系の内部不整合**であり、AA 割れはその帰結。
red-700 (`#B91C1C`) へ揃えるのは**整合回復**である。
「失敗・破壊的操作・エラー」という**最も読めなければ困る面**で AA を割っているので、
ペア集合を縮めて green を作る (= 実害を隠す) 選択は取らない。

### 現行コード → 変更後コード

`DESIGN.md` frontmatter:

```diff
-    danger: "#DC2626"
+    danger: "#B91C1C"
```

`DESIGN.md` 本文 §Colors 状態色:

```diff
-- **Danger(#DC2626)**: 失敗・破壊的操作・エラー。Tertiary とは別物
+- **Danger(#B91C1C)**: 失敗・破壊的操作・エラー。Tertiary とは別物
   (Tertiary は前向きな強調、Danger は否定的なシグナル)。
```

さらに §Colors 本文へ 1 段落を追記する (色の決め方の根拠を canonical 側に残す):

```markdown
状態色・アクセントは Tailwind の **-700 段**で揃える(`tertiary` teal-700 / `success` green-700 /
`warning` amber-700 / `danger` red-700)。`neutral`(#F4F4F5)や `surface`(#FFFFFF)の上で
**本文コントラスト 4.5:1** を確保するための下限であり、これより明るい段は使わない
(`tests/js/architecture/contrast-invariant.test.ts` が機械検証する)。
```

`resources/css/tokens.css`:

```diff
-    --color-danger:          #dc2626;
+    --color-danger:          #b91c1c;
```

### `tests/js/styles/inventory.ts` への追記

```ts
/*
 * ===== コントラスト検査の役割宣言 (contrast-invariant.test.ts の入力) =====
 *
 * DESIGN.md の全色トークンは下の 5 分類の**いずれかに必ず属する** (deny-by-default)。
 * 未分類のトークンがあれば contrast-invariant が fail する = 新トークンが
 * 黙って gate をすり抜けられない。
 */

/** 面 (背景) として塗るトークン。DESIGN.md §Colors: neutral=画面全体 / surface=カード・モーダル */
export const SURFACE_ROLE_TOKENS = ["neutral", "surface"] as const;

/** 面の上に載るテキスト色 (本文・見出し・意味を担う状態テキスト) */
export const TEXT_ON_SURFACE_TOKENS = [
    "text-primary",
    "text-secondary",
    "primary", // リンク / TextLink
    "tertiary",
    "success",
    "warning",
    "danger", // Alert 見出し / Button danger-ghost のラベル
] as const;

/** 塗り面 (solid fill) として使うトークン。DESIGN.md §Components Button の bg-* */
export const FILL_TOKENS = [
    "primary",
    "primary-hover",
    "tertiary",
    "tertiary-hover",
    "success",
    "warning",
    "danger",
] as const;

/** 塗り面の上に載るラベル色。DESIGN.md §Components: `bg-* + text-neutral` */
export const FILL_LABEL_TOKENS = ["neutral"] as const;

/**
 * コントラスト検査の対象外トークン (理由必須)。
 * 「検査していない」ことを見えるようにするための明示宣言であり、免罪符ではない。
 */
export const CONTRAST_EXEMPT_TOKENS = {
    "border":
        "1px の区切り線・入力欄の枠。テキストではなく WCAG 1.4.11 (非テキスト 3:1) の領域。" +
        "装飾的な境界線は 1.4.11 の適用除外のため、使用箇所ごとの役割分類が要る (v1 スコープ外)",
    "border-strong":
        "区切りの強調・ghost ボタンの枠。ghost ボタンの枠は機能的境界の可能性があり、" +
        "実測 2.56 で 3:1 に届かない。値の是正は『どの border が機能的境界か』の" +
        "役割モデルを DESIGN.md に定めてから別バッチで行う (申し送り 5-3)",
} as const;

/**
 * 未検査であることを明示する pending 集合 (v1 スコープ外)。
 * contrast-invariant はこれらを検査しない — 「gate があるからコントラストは守られている」
 * という誤読を作らないための宣言。
 */
export const PENDING_CONTRAST_PAIRS = [
    "WCAG 1.4.11 非テキストコントラスト (3:1): border / border-strong / focus ring",
    "alpha 合成ペア: Badge の bg-<tone>/10 + text-<tone>、bg-primary-soft、ring-primary/35、" +
        "bg-text/70 + text-surface (合成後の実効色が親背景に依存しトークン単体では定まらない)",
] as const;
```

### 変更後コード (新規テスト)

```ts
import { describe, it, expect } from "vitest";
import { designColors } from "../styles/design-md";
import {
    COLOR_TOKEN_MAP,
    CONTRAST_EXEMPT_TOKENS,
    FILL_LABEL_TOKENS,
    FILL_TOKENS,
    SURFACE_ROLE_TOKENS,
    TEXT_ON_SURFACE_TOKENS,
} from "../styles/inventory";

/*
 * contrast-invariant — DESIGN.md のテーマ色が読める組合せであることを機械検証する。
 *
 * 【検査範囲】不透明 (opaque) なテキストペアのみ。
 *   - 面 (neutral / surface) の上のテキスト色
 *   - 塗り面 (primary / danger 等) の上のラベル色 (DESIGN.md §Components: bg-* + text-neutral)
 *
 * 【閾値】一律 4.5:1。
 *   WCAG 2.2 SC 1.4.3 (AA) には「大きな文字は 3:1」の緩和があるが、
 *   **トークン単位の gate は文字サイズを知り得ない**ため緩和は採らず、
 *   厳しい側 (通常文字基準) を一律適用する。これは WCAG の要求そのものではなく
 *   本プロジェクトの設計判断である。
 *
 * 【検査しないもの】inventory.ts の PENDING_CONTRAST_PAIRS を参照
 *   (非テキスト 1.4.11 / alpha 合成)。「gate があるからコントラストは守られている」
 *   という誤読を作らないため、未検査であることを明示宣言してある。
 *
 * 色値そのものを変えるときは DESIGN.md / tokens.css を同一 PR で更新すること
 * (canonical-source-parity が drift を検出する)。
 */

const AA_NORMAL_TEXT = 4.5;

/** sRGB チャンネルの線形化 (WCAG 2.x 相対輝度の定義) */
function linearize(channel: number): number {
    const c = channel / 255;
    return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
}

/** #rrggbb → 相対輝度 (WCAG 2.x) */
function relativeLuminance(hex: string): number {
    const r = linearize(parseInt(hex.slice(1, 3), 16));
    const g = linearize(parseInt(hex.slice(3, 5), 16));
    const b = linearize(parseInt(hex.slice(5, 7), 16));
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** コントラスト比 (WCAG 2.x)。1.0〜21.0 */
export function contrastRatio(a: string, b: string): number {
    const [l1, l2] = [relativeLuminance(a), relativeLuminance(b)];
    return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
}

const colors = designColors();

function hex(token: string): string {
    const value = colors.get(token);
    if (value === undefined) throw new Error(`DESIGN.md colors に ${token} が無い`);
    return value;
}

/** 検査対象ペア: [前景トークン, 背景トークン, 文脈] */
const PAIRS: readonly (readonly [string, string, string])[] = [
    ...TEXT_ON_SURFACE_TOKENS.flatMap((fg) =>
        SURFACE_ROLE_TOKENS.filter((bg) => bg !== fg).map(
            (bg) => [fg, bg, "面上のテキスト"] as const,
        ),
    ),
    ...FILL_LABEL_TOKENS.flatMap((fg) =>
        FILL_TOKENS.map((bg) => [fg, bg, "塗り面のラベル"] as const),
    ),
];

describe("architecture/contrast-invariant: 不透明ペアのテキストコントラスト (一律 4.5:1)", () => {
    it("役割宣言が DESIGN.md の全色トークンを覆う (deny-by-default)", () => {
        const classified = new Set<string>([
            ...SURFACE_ROLE_TOKENS,
            ...TEXT_ON_SURFACE_TOKENS,
            ...FILL_TOKENS,
            ...FILL_LABEL_TOKENS,
            ...Object.keys(CONTRAST_EXEMPT_TOKENS),
        ]);
        const unclassified = Object.keys(COLOR_TOKEN_MAP).filter((t) => !classified.has(t));
        expect(
            unclassified.sort(),
            `未分類の色トークンがある。tests/js/styles/inventory.ts で ` +
                `SURFACE_ROLE / TEXT_ON_SURFACE / FILL / FILL_LABEL / CONTRAST_EXEMPT の ` +
                `いずれかに分類すること (免除するなら理由を書くこと): ${unclassified.join(", ")}`,
        ).toEqual([]);

        // 逆向き: 宣言に DESIGN.md に無いトークンが紛れていないか
        const unknown = [...classified].filter((t) => !(t in COLOR_TOKEN_MAP));
        expect(unknown.sort(), `DESIGN.md に存在しないトークンが宣言されている`).toEqual([]);
    });

    it("検査対象ペアが 0 件でない (空振り防止)", () => {
        expect(PAIRS.length).toBeGreaterThan(0);
    });

    it.each(PAIRS)("%s on %s (%s) が 4.5:1 以上", (fg, bg, context) => {
        const ratio = contrastRatio(hex(fg), hex(bg));
        expect(
            ratio,
            `${context}: text-${fg} on bg-${bg} = ${ratio.toFixed(2)}:1。` +
                `DESIGN.md の色値を見直すこと (ペア集合を縮めて green にしないこと)`,
        ).toBeGreaterThanOrEqual(AA_NORMAL_TEXT);
    });

    /* 負のコントロール: 計算器が実際に点灯することを既知値で確認する */
    it("負のコントロール: 既知の低コントラスト対を検出し、既知の高コントラスト対は通す", () => {
        expect(contrastRatio("#ffffff", "#ffffff")).toBeCloseTo(1, 5);
        expect(contrastRatio("#000000", "#ffffff")).toBeCloseTo(21, 5);
        // red-600 (#dc2626) on neutral (#f4f4f5) = 4.39 — 是正前の実測値。4.5 を割る
        expect(contrastRatio("#dc2626", "#f4f4f5")).toBeLessThan(AA_NORMAL_TEXT);
        // red-700 (#b91c1c) on neutral = 5.89 — 是正後
        expect(contrastRatio("#b91c1c", "#f4f4f5")).toBeGreaterThanOrEqual(AA_NORMAL_TEXT);
    });
});
```

### テスト計画

- [ ] **R2 (Red)**: `danger` = `#DC2626` のまま `contrast-invariant` を回すと
      2 ペア (`danger` on `neutral` / `neutral` on `danger`) が fail する
- [ ] **R3 (Red)**: `inventory.ts` の役割宣言から 1 トークンを外すと
      「未分類の色トークンがある」で fail する (deny-by-default の実効確認)
- [ ] `danger` 是正後に全 21 ペアが green (最小 4.563)
- [ ] `canonical-source-parity.test.ts` が green
      (DESIGN.md と tokens.css を同一 PR で更新するため)
- [ ] 負のコントロール (白×白 = 1.0 / 黒×白 = 21.0 / red-600 on neutral < 4.5) が期待どおり
- [ ] `DangerZone.test.ts` / `ConfirmDialog.test.ts` が green を維持
      (class 名 assert のみで色値非依存)
- [ ] Browser テスト (Chromium + WebKit) が green を維持
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認 — 該当なし (vitest)

### リスク

| リスク | 対処 |
|---|---|
| `danger` の見た目が僅かに暗くなる (red-600 → red-700) | 意図した変更。DESIGN.md 本文にも根拠を明記する。hover は `hover:opacity-90` / `danger-outline` の塗りなので追加変更不要 |
| `success` (4.563) / `warning` (4.569) の余裕が薄い | 現状 green。将来 `neutral` を明るくすると割る。gate がその PR を落とすので検出可能 (これが gate の役割) |
| メールテンプレ (`resources/views/vendor/mail/html/themes/template.css`) が `#a1a1aa` を直書き | 本バッチの対象外 (DS token の写像ではなく Laravel 同梱テーマ)。`danger` は含まれない (grep 実測) |
| `it.each` の可読性 | 失敗メッセージに実測比を埋め込むので、どのペアが何:1 で落ちたか即座に分かる |

---

## 施策 7: 申し送り (本バッチでは実装しない)

概念設計 §施策 5 に記録済み。**コード変更なし**。要点のみ再掲:

| # | 内容 | 備考 |
|---|---|---|
| 7-1 | aicue 独自 4 gate (`form-novalidate` / `logout-call-site-inventory` / `page-shell-structure` / `pages-path-case-invariant`) が c2c 台帳に未記載 | 還流候補。**本バッチでは `append_event` しない** (指示による)。台帳掲載はオーナー裁定事項 |
| 7-2 | `svelte/no-at-html-tags` の家系標準化 | t0 のルール集合外。aicue 単独では入れず家系提案とする |
| 7-3 | WCAG 1.4.11 非テキストコントラスト (3:1) の役割モデル | `border-strong` = 2.56 で未達。`#71717A` (zinc-500) なら 4.83。値を決める前に「どの border が機能的境界か」を DESIGN.md に定める |
| 7-4 | `.svelte` の型検査経路 (svelte-check) の導入検討 | 本バッチは未定義識別子しか閉じない。診断量の実測が先。家系議題として起票が筋 |
| 7-5 | mirror 取得後の `svelte-no-undef-gate` 収束 | D11 の収束条件 |

---

## 受け入れ条件 (2 系統)

| 系統 | 施策 | green の定義 |
|---|---|---|
| **lint baseline** | 1 / 2 / 3 / 4 | `pnpm lint` exit 0、`pnpm typecheck` exit 0、`svelte-no-undef-gate` pass、負のコントロール 3 パターンが点灯、`pnpm run audit:gate` pass (`globals` 追加) |
| **contrast baseline** | 5 / 6 | `contrast-invariant` pass (全 21 ペア ≥ 4.5:1)、`canonical-source-parity` pass、未分類トークン 0 件、`design-md.ts` 切り出し後も既存 assertion が無変更で green |

共通: `pnpm test` 全体 green、`pnpm build` 成功。

### テストファースト: 先に確認する Red

| # | Red | 示せる不変条件 |
|---|---|---|
| R1 | 現行 config で `svelte-no-undef-gate` が fail | gate が config の実態を見ており 3 項目を実際に判定している |
| R2 | 現行 `danger` (#DC2626) で `contrast-invariant` が fail (2 ペア) | gate が実値を計算し閾値割れを検出する |
| R3 | 未分類トークンを混ぜると完全性検査が fail | deny-by-default が効いている |
| R4a | 未実装の `videoConstraints(mode)` 単体テストが fail | 新設純関数の仕様が先に決まっている |

**characterization (Red にしない)**:

| # | 内容 | 進め方 |
|---|---|---|
| C1 | flip 再取得経路の `getUserMedia` が最新 `facingMode` を使う | **移動前に green を確認**してから移動し、移動後も green を維持 |

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | `eslint.config.js` / `package.json` / `DESIGN.md` / `tokens.css` というリポジトリ全体の baseline に触れるため、他タスクと同一 worktree で並走させると lint/test の green 判定が互いに汚染される。また施策 1→2 の順序依存 (先に `.svelte` から型専用名を除かないと `pnpm lint` が赤くなる) があり、途中状態を共有したくない |
| 競合リスク | 同時進行の **architecture-gate-followup** バッチが `tests/js/architecture/svelte-head-no-title.test.ts` を追加する。**ファイル名は衝突しない** (`svelte-no-undef-gate.test.ts` / `contrast-invariant.test.ts`)。ただし両バッチとも `docs/template-divergence.md` に追記する可能性があり、D 番号 (本バッチは **D11**) の衝突に注意。マージ順で後から入る側が採番し直す |
| DB 依存 | なし (本 devcontainer に PostgreSQL は無いが影響しない) |
| 検証コマンド | `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm run audit:gate` (T099 の global test lock 経由) |


---

## 関連する現行コード

### `eslint.config.js`
```
import betterTailwind from "eslint-plugin-better-tailwindcss";
import svelte from "eslint-plugin-svelte";
import svelteParser from "svelte-eslint-parser";
import tsParser from "@typescript-eslint/parser";

// Tailwind v4 は CSS-first config。entryPoint に @import "tailwindcss" を宣言した
// app.css を指す。callees は clsx/cva 系を導入したときに lint 対象にするための宣言。
const betterTailwindSettings = {
    "better-tailwindcss": {
        entryPoint: "resources/css/app.css",
        callees: ["classnames", "clsx", "ctl", "cn", "cva", "tw"],
    },
};

export default [
    {
        ignores: [
            "tmp/**",
            "node_modules/**",
            "dist/**",
            "build/**",
            ".git/**",
            "vendor/**",
            "public/build/**",
            "storage/**",
        ],
    },
    {
        files: ["**/*.svelte"],
        languageOptions: {
            parser: svelteParser,
            parserOptions: {
                parser: tsParser,
            },
        },
    },
    {
        files: ["**/*.{js,mjs,cjs,ts,jsx,tsx}"],
        languageOptions: {
            parser: tsParser,
        },
    },
    {
        files: ["**/*.{js,mjs,cjs,ts,jsx,tsx,svelte}"],
        plugins: {
            "better-tailwindcss": betterTailwind,
        },
        settings: betterTailwindSettings,
        rules: {
            "better-tailwindcss/no-conflicting-classes": "error",
            "better-tailwindcss/no-duplicate-classes": "error",
            "better-tailwindcss/no-unnecessary-whitespace": "error",
            "better-tailwindcss/enforce-consistent-class-order": "error",
            "better-tailwindcss/no-unknown-classes": "warn",
        },
    },
    {
        files: ["**/*.svelte"],
        plugins: { svelte },
        rules: {
            "svelte/require-each-key": "error",
            "svelte/prefer-svelte-reactivity": "error",
            "svelte/prefer-writable-derived": "error",
            "svelte/no-useless-mustaches": ["error", { ignoreStringEscape: true }],
        },
    },
];

```
### `tests/js/styles/canonical-source-parity.test.ts`
```
import { describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import {
    COLOR_TOKEN_MAP,
    DERIVED_COLOR_TOKENS,
    RADIUS_TOKENS,
    TYPOGRAPHY_RAMPS,
} from "./inventory";

/**
 * DESIGN.md (canonical) ⇔ resources/css/tokens.css (実装写像) の双方向同期を機械検証する。
 * 片方だけ更新された PR をここで落とす (docs/design-system.md の同期契約)。
 */

const ROOT = path.resolve(__dirname, "../../..");
const designMd = fs.readFileSync(path.join(ROOT, "DESIGN.md"), "utf-8");
const tokensCss = fs.readFileSync(path.join(ROOT, "resources/css/tokens.css"), "utf-8");

const frontmatter = (() => {
    const m = designMd.match(/^---\n([\s\S]*?)\n---/);
    if (!m) throw new Error("DESIGN.md frontmatter not found");
    return m[1];
})();

function designColors(): Map<string, string> {
    const section = frontmatter.match(/^colors:\n((?: {4}\S[^\n]*\n)+)/m);
    if (!section) throw new Error("DESIGN.md colors section not found");
    const map = new Map<string, string>();
    for (const line of section[1].matchAll(/^ {4}([a-z-]+): "(#[0-9A-Fa-f]{6})"$/gm)) {
        map.set(line[1], line[2].toLowerCase());
    }
    return map;
}

function cssColorTokens(): Map<string, string> {
    const map = new Map<string, string>();
    for (const m of tokensCss.matchAll(/--color-([a-z-]+):\s*([^;]+);/g)) {
        map.set(m[1], m[2].replace(/\/\*.*?\*\//g, "").trim().toLowerCase());
    }
    return map;
}

describe("canonical source parity: colors", () => {
    it("DESIGN.md の色集合と tokens.css の --color-* が一致する (set equality)", () => {
        const design = designColors();
        const css = cssColorTokens();

        const expected = [
            ...Object.values(COLOR_TOKEN_MAP),
            ...DERIVED_COLOR_TOKENS,
        ].sort();
        expect([...css.keys()].sort()).toEqual(expected);
        expect([...design.keys()].sort()).toEqual(Object.keys(COLOR_TOKEN_MAP).sort());
    });

    it("DESIGN.md と tokens.css の色の値が一致する (value parity)", () => {
        const design = designColors();
        const css = cssColorTokens();

        for (const [designKey, cssSuffix] of Object.entries(COLOR_TOKEN_MAP)) {
            expect(css.get(cssSuffix), `--color-${cssSuffix}`).toBe(design.get(designKey));
        }
    });
});

describe("canonical source parity: radius", () => {
    it("DESIGN.md rounded と tokens.css の --radius-* が一致する", () => {
        const section = frontmatter.match(/^rounded:\n((?: {4}\S[^\n]*\n)+)/m);
        expect(section).not.toBeNull();
        const design = new Map<string, string>();
        for (const m of section![1].matchAll(/^ {4}([a-z]+): (\d+px)$/gm)) {
            design.set(m[1], m[2]);
        }

        const css = new Map<string, string>();
        for (const m of tokensCss.matchAll(/--radius-([a-z]+):\s*([^;]+);/g)) {
            css.set(m[1], m[2].trim());
        }

        expect([...css.keys()].sort()).toEqual([...RADIUS_TOKENS].sort());
        for (const key of RADIUS_TOKENS) {
            expect(css.get(key), `--radius-${key}`).toBe(design.get(key));
        }
    });
});

describe("canonical source parity: typography ramp", () => {
    function designRamp(name: string): Record<string, string> {
        const m = frontmatter.match(
            new RegExp(`^ {4}${name}:\\n((?: {8}\\S[^\\n]*\\n)+)`, "m"),
        );
        if (!m) throw new Error(`DESIGN.md typography ramp not found: ${name}`);
        const props: Record<string, string> = {};
        for (const line of m[1].matchAll(/^ {8}([a-zA-Z]+): "?([^"\n]+)"?$/gm)) {
            props[line[1]] = line[2];
        }
        return props;
    }

    function cssRamp(name: string): Record<string, string> {
        const m = tokensCss.match(new RegExp(`@utility text-${name} \\{([^}]+)\\}`));
        if (!m) throw new Error(`tokens.css @utility not found: text-${name}`);
        const props: Record<string, string> = {};
        for (const line of m[1].matchAll(/([a-z-]+):\s*([^;]+);/g)) {
            props[line[1]] = line[2].trim();
        }
        return props;
    }

    it.each([...TYPOGRAPHY_RAMPS])("text-%s の size/weight/line-height が DESIGN.md と一致する", (name) => {
        const design = designRamp(name);
        const css = cssRamp(name);

        expect(css["font-size"], "font-size").toBe(design["fontSize"]);
        expect(css["font-weight"], "font-weight").toBe(design["fontWeight"]);
        expect(css["line-height"], "line-height").toBe(design["lineHeight"]);
        if (design["letterSpacing"]) {
            expect(css["letter-spacing"], "letter-spacing").toBe(design["letterSpacing"]);
        }
    });

    it("ramp の font-weight は 400/500 のみ (DESIGN.md §Typography)", () => {
        for (const name of TYPOGRAPHY_RAMPS) {
            const css = cssRamp(name);
            expect(["400", "500"], `text-${name} font-weight`).toContain(css["font-weight"]);
        }
    });
});

```
### `tests/js/styles/inventory.ts`
```
/**
 * DS token inventory — canonical-source-parity テストの single source of truth。
 *
 * DESIGN.md frontmatter のキーと tokens.css の CSS 変数名の対応を定義する。
 * トークンを追加・削除する PR は DESIGN.md / tokens.css / 本ファイルを同一 PR で更新する。
 */

/** DESIGN.md colors キー → tokens.css `--color-<suffix>` の対応 */
export const COLOR_TOKEN_MAP = {
    "primary": "primary",
    "primary-hover": "primary-hover",
    "tertiary": "tertiary",
    "tertiary-hover": "tertiary-hover",
    "neutral": "neutral",
    "surface": "surface",
    "border": "border",
    "border-strong": "border-strong",
    "text-primary": "text",
    "text-secondary": "text-secondary",
    "success": "success",
    "warning": "warning",
    "danger": "danger",
} as const;

/**
 * DESIGN.md frontmatter に現れない派生トークン (rgba 等)。
 * tokens.css にのみ存在してよい。追加時は理由をコメントで残すこと。
 */
export const DERIVED_COLOR_TOKENS = [
    "primary-soft", // primary 12% — badge / focus ring 用 (DESIGN.md §Colors 本文で言及)
] as const;

export const RADIUS_TOKENS = ["sm", "md", "lg"] as const;

export const TYPOGRAPHY_RAMPS = ["display", "h1", "h2", "h3", "body", "caption"] as const;

```
### `resources/js/lib/capture/camera.ts`
```
/**
 * カメラ対応判定 (doc/10 §10.8-3: MediaRecorder 非対応環境では
 * <input type="file" capture> フォールバックを必ず提供する)。
 */
export function supportsMediaRecorder(): boolean {
    return (
        typeof window.MediaRecorder !== "undefined" &&
        typeof navigator.mediaDevices?.getUserMedia === "function" &&
        ["video/mp4", "video/webm"].some(
            (type) => window.MediaRecorder.isTypeSupported?.(type) ?? false,
        )
    );
}

/** 録画に使う MIME type (mp4 優先。どちらも不可なら null) */
export function preferredRecordingMimeType(): string | null {
    if (typeof window.MediaRecorder === "undefined") return null;
    for (const type of ["video/mp4", "video/webm"]) {
        if (window.MediaRecorder.isTypeSupported?.(type)) return type;
    }
    return null;
}

/**
 * カメラが実行時に使えない理由 (F-03 対応。判別可能 union で保持し、
 * UI 文言の出し分け・将来の計測に使う)。
 * Permissions-Policy 拒否は NotAllowedError として観測されユーザー拒否と
 * 機械的に区別できないため permission_denied に含める。
 */
export type CameraUnavailableReason =
    | "permission_denied" // NotAllowedError / SecurityError (ユーザー拒否・Permissions-Policy 拒否)
    | "device_missing" // NotFoundError / OverconstrainedError (カメラ無し・制約不一致)
    | "mime_unsupported" // preferredRecordingMimeType() === null
    | "recorder_unsupported" // new MediaRecorder() の失敗 (NotSupportedError 等)
    | "unknown"; // 分類不能 (詰み回避のためフォールバック側に倒す)

/** getUserMedia() 失敗の分類結果。transient は再試行で回復し得る失敗 */
export type CameraErrorClassification =
    | { kind: "unavailable"; reason: CameraUnavailableReason }
    | { kind: "transient" };

/** reject 値から DOMException 名を安全に取り出す (ブラウザは任意値を reject し得る) */
function errorName(error: unknown): string | null {
    if (error instanceof DOMException) return error.name;
    // OverconstrainedError 等、実装により DOMException を継承しないオブジェクトに備える
    if (typeof error === "object" && error !== null && "name" in error) {
        const name = (error as { name: unknown }).name;
        return typeof name === "string" ? name : null;
    }
    return null;
}

/**
 * getUserMedia() の reject 値を分類する (W3C Media Capture の DOMException name ベース)。
 * - 恒久系 (権限拒否・デバイス無し) → unavailable: フォールバックへ切替
 * - 一時系 (デバイス使用中・中断) → transient: エラー表示 + 再試行可能のまま
 * - 分類不能 → unavailable/unknown: §10.8-3 の「詰みを作らない」要件に従い
 *   フォールバック側に倒す (誤フォールバックでもテイク投入は継続できる)
 */
export function classifyGetUserMediaError(error: unknown): CameraErrorClassification {
    switch (errorName(error)) {
        case "NotAllowedError":
        case "SecurityError":
            return { kind: "unavailable", reason: "permission_denied" };
        case "NotFoundError":
        case "OverconstrainedError":
            return { kind: "unavailable", reason: "device_missing" };
        case "NotReadableError":
        case "AbortError":
            return { kind: "transient" };
        default:
            return { kind: "unavailable", reason: "unknown" };
    }
}

/** 前後カメラの facingMode (doc/05 §5.2 カメラ反転 in/out)。型の単一ソース。 */
export type FacingMode = "environment" | "user";

/** environment ⇄ user の反転。型の単一ソース化 + テスト容易性のため pure 関数化。 */
export function nextFacingMode(mode: FacingMode): FacingMode {
    return mode === "environment" ? "user" : "environment";
}

/**
 * 経過ミリ秒を mm:ss へ整形 (録画タイマー表示用。doc/05 §5.2「00:00」)。
 * 負値・NaN は 0 に丸め、60 分以上も mm が桁溢れして連続表示される (分を切り捨てない)。
 */
export function formatElapsed(ms: number): string {
    const totalSeconds = Number.isFinite(ms) && ms > 0 ? Math.floor(ms / 1000) : 0;
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;
    const mm = String(minutes).padStart(2, "0");
    const ss = String(seconds).padStart(2, "0");
    return `${mm}:${ss}`;
}

/**
 * MediaRecorder が pause()/resume() を提供するかの **存在確認のみ** (doc/05 §5.2 一時停止/再開)。
 * 注意: これは API の存在確認であって正常動作の保証ではない。実行時の InvalidStateError や
 * pause/resume イベント未到達への退行 (recorder.state からの phase 復旧) が最終防御。
 */
export function supportsPauseResume(): boolean {
    return (
        typeof window.MediaRecorder !== "undefined" &&
        typeof window.MediaRecorder.prototype?.pause === "function" &&
        typeof window.MediaRecorder.prototype?.resume === "function"
    );
}

```
### `tests/js/architecture/pages-path-case-invariant.test.ts`
```
import { describe, it, expect } from "vitest";
import fs from "node:fs/promises";
import path from "node:path";
import { execFileSync } from "node:child_process";
import { fileURLToPath } from "node:url";

/*
 * pages-path-case-invariant — ページ参照 path の大文字/小文字を固定する。
 *
 * SoT = resources/js/inertia.ts の resolver 規約: import.meta.glob で "./pages" 配下の
 * .svelte を集め、`./pages/${name}.svelte` というキーで引く。
 * つまり pages ディレクトリは **小文字 `pages/` 固定**。大文字 `Pages/` を参照する
 * import / glob / dynamic import は、case-insensitive な開発 FS では偶然動いても
 * case-sensitive な CI / 本番コンテナで解決不能になり白画面/ビルド失敗になる。
 *
 * 実効性: 他アプリからのコード移植で `resources/js/Pages/` 参照が実際に混入したことがある。
 *
 * 検査対象:
 *   A. resources/js 配下 (.ts / .svelte) の **path 文字列リテラル**中の大文字 `Pages/` セグメント。
 *      静的 import・`import.meta.glob`・**dynamic import の文字列リテラル**を等しく拾う
 *      (どれも「引用符で囲まれた path リテラル」として現れるため、単一の検出器で足りる)。
 *   B. git tracked path に `resources/js/Pages/` で始まるものが無いこと
 *      (case-insensitive FS の case-fold エイリアスを誤って git add した事故の検出)。
 */

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.resolve(HERE, "../../../");
const RESOURCES_JS = path.join(REPO_ROOT, "resources/js");

/**
 * 引用符で囲まれた path リテラル中の大文字 `Pages/` セグメントを検出する。
 * `./Pages/` `../Pages/` `/Pages/` `@/Pages/` `resources/js/Pages/` などを等しく拾う。
 * 単語の一部 (例: `SubPages/`) は前方境界で除外する。
 */
const UPPERCASE_PAGES_REF = /(["'`])(?:Pages\/|[^"'`]*[./@]Pages\/)/;

function findUppercasePagesRefs(source: string): string[] {
    return source
        .split(/\r?\n/)
        .filter((line) => UPPERCASE_PAGES_REF.test(line))
        .map((line) => line.trim());
}

async function sourceFiles(dir: string): Promise<string[]> {
    const out: string[] = [];
    for (const e of await fs.readdir(dir, { recursive: true, withFileTypes: true })) {
        if (e.isFile() && /\.(svelte|ts)$/.test(e.name)) out.push(path.join(e.parentPath, e.name));
    }
    return out;
}

describe("architecture/pages-path-case-invariant", () => {
    it("resources/js に大文字 Pages/ を参照する import / glob / dynamic import が存在しない", async () => {
        const files = await sourceFiles(RESOURCES_JS);
        const offenders: string[] = [];
        for (const file of files) {
            const hits = findUppercasePagesRefs(await fs.readFile(file, "utf8"));
            for (const hit of hits) offenders.push(`${path.relative(REPO_ROOT, file)}: ${hit}`);
        }
        expect(
            offenders.sort(), // 失敗メッセージを走査順の環境差で揺らさない
            `大文字 'Pages/' path 参照を検出。resolver 規約は小文字 './pages/' 固定 ` +
                `(resources/js/inertia.ts の import.meta.glob と一致させること): ${offenders.join(", ")}`,
        ).toEqual([]);
    });

    it("git tracked path に大文字 resources/js/Pages/ で始まるものが存在しない", () => {
        // architecture invariant: git 不在は環境不備。silent skip せず明瞭に fail させる。
        let tracked: string;
        try {
            tracked = execFileSync("git", ["ls-files", "resources/js/"], {
                cwd: REPO_ROOT,
                encoding: "utf8",
            });
        } catch (e) {
            throw new Error(
                `git ls-files の実行に失敗 (git worktree 前提の architecture invariant): ${String(e)}`,
            );
        }
        const offenders = tracked.split("\n").filter((p) => p.startsWith("resources/js/Pages/"));
        expect(
            offenders,
            `大文字 'resources/js/Pages/' で始まる tracked file を検出。case-insensitive FS の ` +
                `case-fold エイリアスを誤って git add したもの。小文字 'resources/js/pages/' に統一すること: ` +
                `${offenders.join(", ")}`,
        ).toEqual([]);
    });

    /*
     * 負のコントロール: 検出器が実際に点灯することを fixture 文字列で確認する
     * (実ファイルは書き換えない)。空振り gate を green として扱わないため。
     */
    it("負のコントロール: 静的 import / glob / dynamic import の大文字 Pages を検出する", () => {
        const violations = [
            `import Dashboard from "./Pages/Dashboard.svelte";`,
            `import Foo from '@/Pages/Foo.svelte';`,
            "const pages = import.meta.glob('./Pages/**/*.svelte');",
            `const mod = await import("./Pages/Lazy.svelte");`,
            "const mod = await import(`../Pages/Lazy.svelte`);",
            `const entry = "resources/js/Pages/Dashboard.svelte";`,
        ];
        for (const line of violations) {
            expect(findUppercasePagesRefs(line), line).toHaveLength(1);
        }
    });

    it("正のコントロール: 小文字 pages / 無関係な Pages 語は検出しない", () => {
        const allowed = [
            `import Dashboard from "./pages/Dashboard.svelte";`,
            "const pages = import.meta.glob('./pages/**/*.svelte');",
            `const mod = await import("@/pages/Lazy.svelte");`,
            "// Pages/ という語をコメントに書いても引用符外なら対象外",
            `const label = "SubPages/foo";`, // 単語境界の無い Pages は誤検出しない
        ];
        for (const line of allowed) {
            expect(findUppercasePagesRefs(line), line).toHaveLength(0);
        }
    });
});

```
### `resources/css/tokens.css`
```
/**
 * DS tokens — DESIGN.md (canonical source) の実装写像。
 *
 * 値の変更は DESIGN.md / docs/design-system.md と同一 PR で必ず行うこと
 * (tests/js/styles/canonical-source-parity.test.ts が drift を検出する)。
 *
 * 取り込み契約:
 *   本ファイルは単独でビルドしない。常に Tailwind 処理コンテキスト
 *   (`@import "tailwindcss"` の直後) から取り込まれることを前提とする。
 */

@theme {
    /* ===== Brand colors (DESIGN.md Slate × Blue) ===== */
    --color-primary:         #2563eb;
    --color-primary-hover:   #1d4ed8;
    --color-primary-soft:    rgba(37, 99, 235, 0.12);  /* primary 12% — badge / focus ring 用 */
    --color-tertiary:        #0f766e;
    --color-tertiary-hover:  #115e59;

    /* ===== Neutrals & surface ===== */
    --color-neutral:         #f4f4f5;  /* page background */
    --color-surface:         #ffffff;  /* card / modal background */
    --color-border:          #e4e4e7;
    --color-border-strong:   #a1a1aa;
    --color-text:            #18181b;
    --color-text-secondary:  #52525b;

    /* ===== Status colors ===== */
    --color-success:         #15803d;
    --color-warning:         #b45309;
    --color-danger:          #dc2626;

    /* ===== Fonts ===== */
    --font-sans:  'Noto Sans JP', 'Hiragino Sans', 'Yu Gothic UI', 'Segoe UI',
                  ui-sans-serif, system-ui, sans-serif,
                  'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol', 'Noto Color Emoji';

    /* ===== Radius (DESIGN.md rounded) ===== */
    --radius-sm: 4px;
    --radius-md: 6px;
    --radius-lg: 8px;
}

/* ===== Typography ramp =====
   全ランプ Noto Sans JP、ウェイトは 400 / 500 の 2 階層のみ (DESIGN.md 準拠) */

@utility text-display {
    font-family: var(--font-sans);
    font-size: 48px;
    font-weight: 500;
    line-height: 1.2;
    letter-spacing: 0.02em;
}

@utility text-h1 {
    font-family: var(--font-sans);
    font-size: 32px;
    font-weight: 500;
    line-height: 1.3;
    letter-spacing: 0.02em;
}

@utility text-h2 {
    font-family: var(--font-sans);
    font-size: 24px;
    font-weight: 500;
    line-height: 1.4;
}

@utility text-h3 {
    font-family: var(--font-sans);
    font-size: 18px;
    font-weight: 500;
    line-height: 1.5;
}

@utility text-body {
    font-family: var(--font-sans);
    font-size: 16px;
    font-weight: 400;
    line-height: 1.7;
}

@utility text-caption {
    font-family: var(--font-sans);
    font-size: 12px;
    font-weight: 400;
    line-height: 1.5;
}

```
### `resources/js/components/features/capture/CameraRecorder.svelte` (L100-200 / L390-470 抜粋)
```svelte
100:     let pauseResumeTimeout: ReturnType<typeof setTimeout> | null = null;
101:     // 能力検査は module 初期化時に一度評価 (ボタンの出し分けに使う)
102:     const canPauseResume = supportsPauseResume();
103: 
104:     // --- 録画タイマー (S3)。performance.now() 累積・pause 対応 ---
105:     let elapsedMs = $state(0);
106:     let accumulatedMs = 0; // pause で確定した累積 (performance.now ベース)
107:     let segmentStart = 0; // 現 recording 区間の開始 (performance.now())
108:     let timerHandle: ReturnType<typeof setInterval> | null = null;
109:     const elapsedLabel = $derived(formatElapsed(elapsedMs));
110:     const showTimer = $derived(phase === "recording" || phase === "paused");
111: 
112:     // --- カメラ反転 (S6)。idle 時のみ・段階的縮退 ---
113:     let facingMode = $state<FacingMode>("environment");
114:     let flipping = false; // flip 再入ガード
115: 
116:     // 公開 active (starting || resuming || phase !== "idle") の変化時のみ 1 回通知する。
117:     // starting / resuming / phase を変えた箇所は必ず本関数を呼ぶ (通知の一元管理)。
118:     function syncActive(): void {
119:         const active = starting || resuming || phase !== "idle";
120:         if (active !== lastActive) {
121:             lastActive = active;
122:             onCaptureActiveChange?.(active);
123:         }
124:     }
125: 
126:     // phase 遷移は単一 setter を通す。active 通知は syncActive に一元化する。
127:     function setPhase(next: Phase): void {
128:         phase = next;
129:         syncActive();
130:     }
131: 
132:     // --- 録画タイマー関数群 (S3) ---
133:     // recording 区間の計測開始 (start / resume で呼ぶ)
134:     function startTimer(): void {
135:         if (timerHandle !== null) return; // 二重起動防止
136:         segmentStart = performance.now();
137:         timerHandle = setInterval(() => {
138:             elapsedMs = accumulatedMs + (performance.now() - segmentStart);
139:         }, 200);
140:     }
141:     // 計測停止 + 累積確定 (pause / stop / idle / destroy で呼ぶ)
142:     function stopTimer(): void {
143:         if (timerHandle !== null) {
144:             accumulatedMs += performance.now() - segmentStart;
145:             clearInterval(timerHandle);
146:             timerHandle = null;
147:         }
148:         elapsedMs = accumulatedMs;
149:     }
150:     function resetTimer(): void {
151:         if (timerHandle !== null) {
152:             clearInterval(timerHandle);
153:             timerHandle = null;
154:         }
155:         accumulatedMs = 0;
156:         segmentStart = 0;
157:         elapsedMs = 0;
158:     }
159:     // 実録画尺 (durationMs 用)。累積 + 現区間の経過 (recording 中に stop されたケース)。
160:     // R1-S: Math.max(0, …) で明示クランプ (防御的。performance.now 単調増加のため通常は非負)。
161:     function recordedDurationMs(): number {
162:         const raw =
163:             timerHandle !== null ? accumulatedMs + (performance.now() - segmentStart) : accumulatedMs;
164:         return Math.max(0, raw);
165:     }
166: 
167:     // --- getUserMedia の制約を facingMode から組む (S6) ---
168:     function videoConstraints(): MediaTrackConstraints {
169:         return { facingMode };
170:     }
171: 
172:     // 副作用なしの取得 (classify 結果を返すだけ。onCameraUnavailable/error を呼ばない)。
173:     // 呼び出し前に stream=null であること (reacquire 前は releaseCamera 済み)。stream ??= のため
174:     // 既存 stream があれば再取得しない = flip の reacquire では releaseCamera() 後に呼ぶ。
175:     async function acquireStream(): Promise<CameraErrorClassification | { kind: "ok" }> {
176:         try {
177:             stream ??= await navigator.mediaDevices.getUserMedia({
178:                 video: videoConstraints(), // facingMode を反映 (現行の "environment" 直書きを置換)
179:                 audio: true,
180:             });
181:         } catch (cause) {
182:             return classifyGetUserMediaError(cause);
183:         }
184:         if (video) {
185:             video.srcObject = stream;
186:             await video.play().catch(() => undefined);
187:         }
188:         return { kind: "ok" };
189:     }
190: 
191:     // classify 失敗に既存の副作用ポリシーを適用 (transient→error / unavailable→F-03 委譲)。
192:     function applyAcquireFailure(result: CameraErrorClassification): void {
193:         if (result.kind === "transient") {
194:             error =
195:                 "カメラを起動できませんでした。他のアプリがカメラを使用していないか確認し、もう一度お試しください。";
196:             return;
197:         }
198:         onCameraUnavailable(result.reason);
199:     }
200: 
390:         resetTimer();
391:         clearPauseResumePending();
392:         setPhase("idle");
393:         releaseCamera();
394:         onCameraUnavailable("recorder_unsupported");
395:     }
396: 
397:     function releaseCamera(): void {
398:         stream?.getTracks().forEach((track) => track.stop());
399:         stream = null;
400:     }
401: 
402:     // --- カメラ反転 (S6)。idle 時のみ機能。段階的縮退 (R2/R3) ---
403:     async function flipCamera(): Promise<void> {
404:         // idle 以外・取得中・flip 中は no-op (録画中の stream 再取得を避ける)
405:         if (starting || resuming || flipping || phase !== "idle") return;
406:         const target = nextFacingMode(facingMode);
407: 
408:         // live stream 未保持 (録画前): state 更新のみ、次回 getUserMedia に反映
409:         if (stream === null) {
410:             facingMode = target;
411:             return;
412:         }
413:         flipping = true;
414:         try {
415:             error = null;
416:             const track = stream.getVideoTracks()[0] ?? null;
417:             // 段階1: applyConstraints({exact}) + getSettings 検証 (同一 stream 維持)
418:             if (track !== null && (await tryApplyFacing(track, target))) {
419:                 facingMode = target;
420:                 return;
421:             }
422:             // 段階2〜4: 再取得 (旧停止 → 新取得 → 失敗時旧復旧 → 完全喪失で classify)
423:             await reacquireWithFacing(target);
424:         } finally {
425:             flipping = false;
426:         }
427:     }
428: 
429:     // 段階1: exact 制約を適用し getSettings で実切替を検証 (R3: resolve≠実切替)
430:     async function tryApplyFacing(track: MediaStreamTrack, target: FacingMode): Promise<boolean> {
431:         try {
432:             await track.applyConstraints({ facingMode: { exact: target } });
433:         } catch {
434:             return false;
435:         }
436:         // R1-W: getSettings().facingMode が undefined の端末は「未検証扱い」で false を返し
437:         // 再取得経路 (段階2〜) へ倒す (安全側。誤って同一 stream 維持で切替失敗を隠さない)。
438:         const applied = track.getSettings().facingMode;
439:         return applied === target;
440:     }
441: 
442:     // 段階2〜4: 旧 stream 停止 → 新取得 → 失敗時旧復旧 → 完全喪失で初めて副作用 (R3 + R1-critical)
443:     // 副作用なしの acquireStream() を使い、onCameraUnavailable(F-03)/error の発火を段階4 まで遅延する。
444:     async function reacquireWithFacing(target: FacingMode): Promise<void> {
445:         const previous = facingMode;
446:         releaseCamera(); // 旧 stream 停止 (二重取得不可端末に対応。stream=null になる)
447:         facingMode = target;
448:         const forward = await acquireStream(); // 段階2: 副作用なし取得
449:         if (forward.kind === "ok") return;
450:         // 段階3: 旧 facingMode で再取得して復旧 (flip 断念・元カメラ継続。onCameraUnavailable は呼ばない)
451:         facingMode = previous;
452:         const back = await acquireStream();
453:         if (back.kind === "ok") {
454:             error = "カメラを切り替えられませんでした。";
455:             return;
456:         }
457:         // 段階4: 両カメラ喪失。段階3 の classify(back) に対してのみ副作用を適用
458:         // (transient→error 表示 / unavailable→onCameraUnavailable(F-03) 委譲)。
459:         applyAcquireFailure(back);
460:     }
461: 
462:     // preview を開く間に呼ばれる。録画中/停止処理中は no-op (録画データを守る = 暗黙終了しない)。
463:     // 取得中 (starting: 録画開始 / resuming: preview 復帰) も拒否し、取得中の stream を横から
464:     // 解放しない (Codex R1/R3-S4)。
465:     export function releaseForPreview(): void {
466:         if (starting || resuming || phase !== "idle") return; // recording/stopping/取得中で解放拒否
467:         wasActiveBeforePreview = stream !== null; // 復帰要否を記録
468:         releaseCamera();
469:     }
470: 
```
### `resources/js/pages/Settings/Security.svelte` (L455-475 抜粋)
```svelte
455:                         {:else}
456:                             {#if qrSvg}
457:                                 <!-- QR はサーバ提供の SVG をそのまま描画する。svg 文字列に属性を注入せず、
458:                                      wrapper を role="img" にしてアクセシブルネームを与える (H14) -->
459:                                 <div
460:                                     role="img"
461:                                     aria-label="2 要素認証の設定用 QR コード"
462:                                     class="self-start rounded-md border border-border bg-surface p-4"
463:                                     data-testid="two-factor-qr"
464:                                 >
465:                                     <!-- eslint-disable-next-line svelte/no-at-html-tags -->
466:                                     {@html qrSvg}
467:                                 </div>
468:                             {:else}
469:                                 <Alert type="warning" testId="qr-unavailable">
470:                                     QR コードを表示できませんでした。下のセットアップキーを認証アプリに手動入力してください。
471:                                 </Alert>
472:                             {/if}
473: 
474:                             {#if setupKey}
475:                                 <div class="flex flex-col gap-2">
```
### `DESIGN.md` (L1-30 frontmatter / L95-110 状態色 抜粋)
```
1: ---
2: version: "1.0"
3: name: Slate × Blue (Neutral)
4: description: テンプレート既定のニュートラルテーマ。中立的な青を主役に、無彩のスレートを支配色とする。アプリはこのファイルと tokens.css の値を差し替えてテーマを定義する。
5: colors:
6:     primary: "#2563EB"
7:     primary-hover: "#1D4ED8"
8:     tertiary: "#0F766E"
9:     tertiary-hover: "#115E59"
10:     neutral: "#F4F4F5"
11:     surface: "#FFFFFF"
12:     border: "#E4E4E7"
13:     border-strong: "#A1A1AA"
14:     text-primary: "#18181B"
15:     text-secondary: "#52525B"
16:     success: "#15803D"
17:     warning: "#B45309"
18:     danger: "#DC2626"
19: typography:
20:     display:
21:         fontFamily: "Noto Sans JP, sans-serif"
22:         fontSize: 48px
23:         fontWeight: 500
24:         lineHeight: 1.2
25:         letterSpacing: 0.02em
26:     h1:
27:         fontFamily: "Noto Sans JP, sans-serif"
28:         fontSize: 32px
29:         fontWeight: 500
30:         lineHeight: 1.3
95: - **Text Secondary(#52525B)**: 補足文、キャプション、ラベル。
96:   - tailwind: `text-text-secondary`
97: 
98: ### 状態色
99: 
100: - **Success(#15803D)**: 完了・正常・公開済み。
101:   - tailwind: `text-success`, `bg-success`, `border-success`
102: - **Warning(#B45309)**: 注意・確認が必要・保留。
103:   - tailwind: `text-warning`, `bg-warning`, `border-warning`
104: - **Danger(#DC2626)**: 失敗・破壊的操作・エラー。Tertiary とは別物
105:   (Tertiary は前向きな強調、Danger は否定的なシグナル)。
106:   - tailwind: `text-danger`, `bg-danger`, `border-danger`
107: 
108: ソフト背景は状態色の opacity 修飾で表現する(`bg-success/10`, `bg-danger/10`,
109: `bg-primary-soft` 等)。**新しい色トークンを足す前に opacity 修飾と atom 化で表現できないか
110: 検討すること**(追加条件は `docs/design-system.md` の 4 条件)。
111: 
112: ## Typography
```
### `tests/js/components/features/capture/CameraRecorder.test.ts` (L95-150 / L910-975 抜粋)
```ts
95:     constructor(stream: unknown, options: { mimeType: string }) {
96:         super(stream, options);
97:         lastRecorder = this;
98:     }
99: }
100: 
101: const getUserMediaMock = vi.fn<() => Promise<MediaStream>>();
102: 
103: interface FakeTrack {
104:     stop: ReturnType<typeof vi.fn>;
105:     onended: (() => void) | null;
106:     applyConstraints: ReturnType<typeof vi.fn>;
107:     getSettings: ReturnType<typeof vi.fn>;
108: }
109: 
110: /** getTracks()/getVideoTracks() が stop spy 付き track を返す fake stream (解放・flip 検証用) */
111: function fakeStream(facing: "environment" | "user" = "environment"): {
112:     stream: MediaStream;
113:     stop: ReturnType<typeof vi.fn>;
114:     track: FakeTrack;
115: } {
116:     const stop = vi.fn();
117:     const track: FakeTrack = {
118:         stop,
119:         onended: null,
120:         // 既定は制約適用成功 + getSettings が要求 facingMode を返す (段階1 成功)
121:         applyConstraints: vi.fn().mockResolvedValue(undefined),
122:         getSettings: vi.fn(() => ({ facingMode: facing })),
123:     };
124:     const stream = {
125:         getTracks: () => [track],
126:         getVideoTracks: () => [track],
127:     } as unknown as MediaStream;
128:     return { stream, stop, track };
129: }
130: 
131: beforeEach(() => {
132:     FakeMediaRecorder.supportedTypes = ["video/webm"];
133:     FakeMediaRecorder.shouldThrowOnConstruct = false;
134:     FakeMediaRecorder.shouldThrowOnStart = false;
135:     FakeMediaRecorder.shouldThrowOnPause = false;
136:     FakeMediaRecorder.autoStop = true;
137:     FakeMediaRecorder.autoPauseResume = true;
138:     lastRecorder = null;
139:     getUserMediaMock.mockReset();
140:     vi.stubGlobal("MediaRecorder", TrackingFakeMediaRecorder);
141:     vi.stubGlobal("navigator", {
142:         ...navigator,
143:         mediaDevices: { getUserMedia: getUserMediaMock },
144:     });
145:     // jsdom は HTMLMediaElement.play 未実装
146:     vi.spyOn(HTMLMediaElement.prototype, "play").mockResolvedValue(undefined);
147: });
148: 
149: afterEach(() => {
150:     cleanup();
910:         await fireEvent.click(screen.getByTestId("stop-recording"));
911:         await vi.waitFor(() => expect(onCaptured).toHaveBeenCalledTimes(1));
912: 
913:         const durationMs = onCaptured.mock.calls[0][2] as number;
914:         expect(durationMs).toBe(3500); // 区間A(2000) + 区間B(1500)、pause の 7000 は含まない
915:     });
916: 
917:     it("カメラ反転 (録画前・live stream 無し): flip で次回 getUserMedia の facingMode が 'user'", async () => {
918:         const first = fakeStream("user");
919:         getUserMediaMock.mockResolvedValue(first.stream);
920: 
921:         render(CameraRecorder, { props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn() } });
922:         // 録画前は stream 未保持 → flip は state 更新のみ (getUserMedia を呼ばない)
923:         await fireEvent.click(screen.getByTestId("flip-camera"));
924:         expect(getUserMediaMock).not.toHaveBeenCalled();
925: 
926:         // 録画開始時の getUserMedia constraint に facingMode:"user" が反映される
927:         await fireEvent.click(screen.getByTestId("start-recording"));
928:         await vi.waitFor(() => expect(getUserMediaMock).toHaveBeenCalledTimes(1));
929:         const constraint = (getUserMediaMock.mock.calls[0] as unknown[])[0] as {
930:             video: MediaTrackConstraints;
931:         };
932:         expect(constraint.video).toMatchObject({ facingMode: "user" });
933:     });
934: 
935:     it("カメラ反転 (live stream + applyConstraints 成功): 同一 stream を維持し getUserMedia を再呼び出ししない", async () => {
936:         const s = fakeStream("user"); // getSettings は target(user) を返す = 段階1 成功
937:         getUserMediaMock.mockResolvedValue(s.stream);
938: 
939:         // 録画→停止で idle かつ stream 保持状態を作る
940:         await startAndRecord();
941:         await fireEvent.click(screen.getByTestId("stop-recording"));
942:         await vi.waitFor(() => expect(screen.getByTestId("start-recording")).toBeInTheDocument());
943:         expect(getUserMediaMock).toHaveBeenCalledTimes(1);
944: 
945:         await fireEvent.click(screen.getByTestId("flip-camera"));
946:         await vi.waitFor(() => expect(s.track.applyConstraints).toHaveBeenCalled());
947:         expect(getUserMediaMock).toHaveBeenCalledTimes(1); // 再取得なし
948:         expect(s.stop).not.toHaveBeenCalled(); // stream 維持
949:     });
950: 
951:     it("カメラ反転 (getSettings().facingMode が undefined): 未検証扱いで再取得経路へ倒す (R1-W)", async () => {
952:         const live = fakeStream();
953:         live.track.getSettings = vi.fn(() => ({})); // facingMode を返さない端末
954:         const reacquired = fakeStream();
955:         getUserMediaMock
956:             .mockResolvedValueOnce(live.stream) // 初回録画
957:             .mockResolvedValueOnce(reacquired.stream); // flip 再取得
958: 
959:         await startAndRecord();
960:         await fireEvent.click(screen.getByTestId("stop-recording"));
961:         await vi.waitFor(() => expect(screen.getByTestId("start-recording")).toBeInTheDocument());
962: 
963:         await fireEvent.click(screen.getByTestId("flip-camera"));
964:         // 段階1 は未検証で false → releaseCamera + getUserMedia 再取得
965:         await vi.waitFor(() => expect(getUserMediaMock).toHaveBeenCalledTimes(2));
966:         expect(live.stop).toHaveBeenCalled();
967:     });
968: 
969:     it("カメラ反転 (新 facing のみ不可): 旧 facingMode へ復旧し onCameraUnavailable を呼ばない", async () => {
970:         const live = fakeStream();
971:         live.track.applyConstraints = vi.fn().mockRejectedValue(
972:             new DOMException("overconstrained", "OverconstrainedError"),
973:         );
974:         const recovered = fakeStream();
975:         getUserMediaMock
```

