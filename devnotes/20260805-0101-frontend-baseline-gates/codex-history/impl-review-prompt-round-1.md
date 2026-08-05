【アプリの使命 (North Star)】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

> 本バッチはフロントエンド専用で PHP を 1 行も触らない。
> **禁止事項 2 の精神 (「型を緩めて黙らせる」ことの禁止) はフロントにも適用**する。
> ESLint `globals` に型専用名を足して `no-undef` を黙らせる、
> コントラスト gate のペア集合を縮めて green を作る、はいずれもこの禁止に相当する。

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは Laravel + Svelte アプリのコードレビュアーである。以下の実装差分を、詳細設計書と突き合わせてレビューせよ。

## レビュー観点

1. **設計との一致性** — 詳細設計 (APPROVED Round 3) の施策 1〜6 に忠実か。意図的な逸脱には妥当な根拠があるか
2. **正確性** — ロジックの誤り、gate の抜け穴、テストが実際に不変条件を検出するか
3. **テスト網羅性** — 各施策にテストがあるか。負のコントロールが実効的か。空振り (vacuous green) しないか
4. **gate の健全性** — 「lint を黙らせるために gate に穴を開けた」形になっていないか (禁止事項 2 の精神)
5. **セキュリティ** — noInlineConfig 化による副作用、`{@html}` の扱い
6. **DESIGN.md 準拠** — `/DESIGN.md` が design token の canonical source。color / radius / typography は token 経由で参照し hex 直書き (`#RRGGBB`) を増やさない。token 値を変更する diff は `resources/css/tokens.css` と同一 diff 内で同期しているか (運用契約は `docs/design-system.md`)
7. **Atomic Design 準拠** — `resources/js/components/` は `atoms/molecules/organisms/templates` の責務分離に従う。atom は単機能・状態を持たない、molecule は atom の組合せという階層を逆流していないか。アイコンは Lucide を使い、SVG 直書きを増やさない

## 出力形式

ファイルごとに判定を述べ、指摘は **[Critical] / [Warning] / [Suggestion]** に分類すること。
最後に **全体判定: APPROVED または CHANGES_REQUESTED** を明記すること。

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
     * lint 対象 (`pnpm lint` = `eslint resources/js`) の全ファイルで、
     * inline の eslint-disable / eslint-enable を一切許可しない。
     * ルールを黙らせたいときの唯一の手段は **本ファイルの file-scoped override**。
     * override を認めるのは次の 3 条件をすべて満たすときだけ:
     *   (a) 抑制対象が具体的な 1 ファイル (または明示列挙されたファイル群) に閉じている
     *   (b) なぜ安全かがコード側の日本語コメントで説明されている
     *   (c) ここに理由と再検討条件 (いつ外せるか) を書く
     * config に集約すれば diff に必ず現れ、レビュー可能かつ数えられる。
     *
     * **lint 対象を広げるとき** (`pnpm lint` の引数を増やす等) は、
     * tests/js/architecture/svelte-no-undef-gate.test.ts の走査範囲も同時に広げること
     * (宣言と検査の範囲が乖離すると gate が守っているつもりの穴ができる)。
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
 * 検査する不変条件:
 *   A. [.svelte のみ] no-undef が error
 *   B. [.svelte のみ] languageOptions.globals が globals.browser と **完全一致**
 *      (型専用名を混ぜて no-undef を骨抜きにしない。追加は eslint.config.js の
 *       APP_RUNTIME_GLOBALS へ理由付きで登録し、本 gate 側も同時に更新する)
 *   C. [lint 対象の全ファイル] linterOptions.noInlineConfig が true
 *      — A/B を inline コメントで黙らせないための **前提条件**。
 *      `pnpm lint` = `eslint resources/js` なので、走査範囲も
 *      **resources/js 配下 × eslint.config.js が files で対象にしている全拡張子**
 *      (.svelte / .js / .mjs / .cjs / .ts / .jsx / .tsx) に一致させる。
 *      .svelte だけ見ると .ts 向け file-scoped override での復活を見逃す。
 *      lint されないファイル (tests/js 等) は ESLint が directive を読まないので対象外。
 *   D. 走査対象が 0 件でない (空振り gate を green として扱わない)
 *
 * gate の名前が指す中心は「.svelte の no-undef」だが、
 * それを支える C は前提の適用範囲 (= lint 対象全体) で検査する。
 * **lint 対象を広げたら本 gate の LINT_TARGET_EXTENSIONS / 走査ルートも同時に広げること。**
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
 * lint 対象の拡張子 (= eslint.config.js の files が対象にしている集合)。
 * `pnpm lint` の対象を広げたらここも広げること。
 */
const LINT_TARGET_EXTENSIONS = [".svelte", ".js", ".mjs", ".cjs", ".ts", ".jsx", ".tsx"] as const;

/**
 * [C] inline の eslint-disable が効かないこと。**lint 対象の全拡張子**に適用する
 * (`pnpm lint` = `eslint resources/js` の範囲 × LINT_TARGET_EXTENSIONS)。
 */
function assertNoInlineConfig(resolved: ResolvedConfigView): string[] {
    return resolved.linterOptions?.noInlineConfig === true
        ? []
        : ["linterOptions.noInlineConfig が true でない (inline の eslint-disable が効いてしまう)"];
}

/**
 * [A][B] .svelte 固有の不変条件を検査し、違反理由を返す (空配列 = 適合)。
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

async function sourceFiles(dir: string, exts: readonly string[]): Promise<string[]> {
    const out: string[] = [];
    for (const e of await fs.readdir(dir, { recursive: true, withFileTypes: true })) {
        if (e.isFile() && exts.some((ext) => e.name.endsWith(ext))) {
            out.push(path.join(e.parentPath, e.name));
        }
    }
    return out.sort(); // 失敗メッセージを走査順の環境差で揺らさない
}

/** 実効設定を解決する。解決できない場合は silent skip せず明瞭に fail させる。 */
async function resolveConfig(eslint: ESLint, file: string): Promise<ResolvedConfigView> {
    const resolved: unknown = await eslint.calculateConfigForFile(file);
    if (typeof resolved !== "object" || resolved === null) {
        throw new Error(
            `実効設定を解決できなかった: ${path.relative(REPO_ROOT, file)} ` +
                `(eslint.config.js の ignores に入っていないか確認すること)`,
        );
    }
    return resolved as ResolvedConfigView;
}

describe("architecture/svelte-no-undef-gate", () => {
    it("[A][B] resources/js 配下の全 .svelte で no-undef=error かつ globals が globals.browser と完全一致", async () => {
        const files = await sourceFiles(RESOURCES_JS, [".svelte"]);
        // 空振り防止: 走査が 0 件なら gate は何も守っていない
        expect(
            files.length,
            "resources/js 配下に .svelte が 1 件も無い (走査が空振りしている)",
        ).toBeGreaterThan(0);

        const eslint = new ESLint({ cwd: REPO_ROOT });
        const offenders: string[] = [];
        for (const file of files) {
            for (const problem of assertSvelteNoUndefConfig(await resolveConfig(eslint, file))) {
                offenders.push(`${path.relative(REPO_ROOT, file)}: ${problem}`);
            }
        }
        expect(
            offenders,
            `.svelte の未定義識別子検出が無効化されている。eslint.config.js を確認すること: \n` +
                offenders.join("\n"),
        ).toEqual([]);
    });

    it("[C] lint 対象 (resources/js × 全 lint 拡張子) で inline の eslint-disable が効かない", async () => {
        // noInlineConfig は A/B を inline コメントで黙らせないための前提条件。
        // .svelte だけ見ると .ts 等向けの file-scoped override での復活を見逃す。
        const files = await sourceFiles(RESOURCES_JS, LINT_TARGET_EXTENSIONS);
        expect(
            files.length,
            "resources/js 配下に lint 対象ファイルが 1 件も無い (走査が空振りしている)",
        ).toBeGreaterThan(0);

        const eslint = new ESLint({ cwd: REPO_ROOT });
        const offenders: string[] = [];
        for (const file of files) {
            for (const problem of assertNoInlineConfig(await resolveConfig(eslint, file))) {
                offenders.push(`${path.relative(REPO_ROOT, file)}: ${problem}`);
            }
        }
        expect(
            offenders,
            `inline の eslint-disable が有効に戻っている。ルールを黙らせる唯一の手段は ` +
                `eslint.config.js の file-scoped override (3 条件を満たすこと): \n` +
                offenders.join("\n"),
        ).toEqual([]);
    });

    /*
     * 負のコントロール: 検査器が実際に点灯することを、解決結果を加工した
     * plain object で確認する (ESLint のマージ規則は試験対象にしない)。
     */
    it("負のコントロール: no-undef 解除 / globals 汚染 / noInlineConfig 無効を検出する", () => {
        const sound: ResolvedConfigView = {
            rules: { "no-undef": [2] },
            linterOptions: { noInlineConfig: true },
            languageOptions: { globals: { ...globals.browser } },
        };
        expect(assertSvelteNoUndefConfig(sound), "正のコントロール (svelte)").toEqual([]);
        expect(assertNoInlineConfig(sound), "正のコントロール (noInlineConfig)").toEqual([]);

        expect(
            assertSvelteNoUndefConfig({ ...sound, rules: { "no-undef": [0] } }),
            "no-undef=off",
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
        expect(
            assertNoInlineConfig({ ...sound, linterOptions: { noInlineConfig: false } }),
            "noInlineConfig=false",
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
> A. 「`resources/js` 配下の**全** `.svelte` で ESLint `no-undef` が error である」
> B. 「その `languageOptions.globals` は**実行時グローバル**
>    (`globals.browser` + `eslint.config.js` の `APP_RUNTIME_GLOBALS` 明示登録) と
>    **完全一致**する — 型専用名を混ぜて no-undef を骨抜きにしない」
> C. 「**lint 対象の全ファイル**
>    (= `pnpm lint` = `eslint resources/js` の範囲 × `eslint.config.js` が `files` で
>    対象にしている全拡張子: `.svelte` / `.js` / `.mjs` / `.cjs` / `.ts` / `.jsx` / `.tsx`) で
>    `linterOptions.noInlineConfig` が true であり、inline の eslint-disable が効かない」

`tests/js/architecture/svelte-no-undef-gate.test.ts` が
ESLint 公開 API `calculateConfigForFile()` で実効設定を解決し、
A/B を全 `.svelte` に、C を lint 対象全ファイルに適用して検査する。
走査 0 件でも fail する (空振り防止)。
検査ロジックは純関数 (`assertSvelteNoUndefConfig` / `assertNoInlineConfig`) に切り出し、
正負のコントロールで検出器の実効性を固定している
(ESLint の flat config マージ規則そのものは試験対象にしない)。

**運用契約 1 (noInlineConfig 体制)**: ルールを黙らせる唯一の手段は
`eslint.config.js` の file-scoped override。override を認めるのは
(a) 抑制対象が具体的な 1 ファイル (または明示列挙) に閉じている
(b) なぜ安全かがコード側コメントで説明されている
(c) config 側に理由と再検討条件が書かれている — の 3 条件をすべて満たすときだけ。

**運用契約 2 (宣言と検査範囲の一致)**: `pnpm lint` の対象を広げる
(引数ディレクトリを増やす / 新しい拡張子を扱う) ときは、本 gate の
`LINT_TARGET_EXTENSIONS` と走査ルートも**同一 PR で**広げること。
宣言 (config コメント) と検査範囲が乖離すると「守っているつもりの穴」ができる。

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
      `[A][B]` は no-undef 不在 + globals 不足で全 `.svelte` 分、
      `[C]` は noInlineConfig 不在で **lint 対象ファイル全件**分が報告される
- [ ] 施策 2 適用後は green
- [ ] 負のコントロール 3 パターンがそれぞれ 1 件だけ点灯する
- [ ] 走査 0 件なら fail する (`RESOURCES_JS` を存在しないパスに向けて手元確認)
- [ ] `resolveConfig()` の guard: `eslint.config.js` の `ignores` に入るパスを渡すと
      明示エラーになる (silent skip しない)

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
 *
 * **出口**: pending 項目に対応したらその行を削る。全部消えたら
 * 本 export と contrast-invariant.test.ts の
 * 「未検査宣言 (PENDING_CONTRAST_PAIRS) が空でない」テストを**同時に削除**すること
 * (空の宣言と、空でないことを確かめるテストを残すと形骸化する)。
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
    PENDING_CONTRAST_PAIRS,
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

    it("未検査宣言 (PENDING_CONTRAST_PAIRS) が空でない", () => {
        // 「gate があるからコントラストは守られている」という誤読を防ぐ宣言そのものが
        // 消し飛ばされないよう固定する。
        // 出口: 1.4.11 / alpha 合成に対応して pending が空になったら、
        // inventory.ts の宣言と本 it を **同時に削除**すること
        // (空の宣言と、空でないことを確かめるテストを残すと形骸化する)。
        expect(PENDING_CONTRAST_PAIRS.length).toBeGreaterThan(0);
    });

    it.each(PAIRS)("[opaque text] %s on %s (%s) が 4.5:1 以上", (fg, bg, context) => {
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
| 7-2 | `svelte/no-at-html-tags` の家系標準化 | t0 のルール集合外。aicue 単独では入れず家系提案とする。実装案は 2 つ — (i) ルールを error にして `eslint.config.js` の file-scoped override で許可箇所を管理する、(ii) `svg-inline-allowlist.test.ts` と同型の **allowlist gate** を tests/js/architecture に置く。`noInlineConfig` 体制では (i) でも例外が config に集約されレビュー可能になる |
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

## 実装差分 (git diff)

```diff
diff --git a/DESIGN.md b/DESIGN.md
index ebf77cb..4f671fd 100644
--- a/DESIGN.md
+++ b/DESIGN.md
@@ -15,7 +15,7 @@
     text-secondary: "#52525B"
     success: "#15803D"
     warning: "#B45309"
-    danger: "#DC2626"
+    danger: "#B91C1C"
 typography:
     display:
         fontFamily: "Noto Sans JP, sans-serif"
@@ -101,10 +101,15 @@ ### 状態色
   - tailwind: `text-success`, `bg-success`, `border-success`
 - **Warning(#B45309)**: 注意・確認が必要・保留。
   - tailwind: `text-warning`, `bg-warning`, `border-warning`
-- **Danger(#DC2626)**: 失敗・破壊的操作・エラー。Tertiary とは別物
+- **Danger(#B91C1C)**: 失敗・破壊的操作・エラー。Tertiary とは別物
   (Tertiary は前向きな強調、Danger は否定的なシグナル)。
   - tailwind: `text-danger`, `bg-danger`, `border-danger`
 
+状態色・アクセントは Tailwind の **-700 段**で揃える(`tertiary` teal-700 / `success` green-700 /
+`warning` amber-700 / `danger` red-700)。`neutral`(#F4F4F5)や `surface`(#FFFFFF)の上で
+**本文コントラスト 4.5:1** を確保するための下限であり、これより明るい段は使わない
+(`tests/js/architecture/contrast-invariant.test.ts` が機械検証する)。
+
 ソフト背景は状態色の opacity 修飾で表現する(`bg-success/10`, `bg-danger/10`,
 `bg-primary-soft` 等)。**新しい色トークンを足す前に opacity 修飾と atom 化で表現できないか
 検討すること**(追加条件は `docs/design-system.md` の 4 条件)。
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 6312929..f39b981 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -370,3 +370,60 @@ ### 関連
   `scripts/run-browser-test.sh` / `scripts/run-vitest.sh` / `package.json`
 - 設計: `devnotes/20260804-2319-global-test-lock/` (概念設計 Round 6 / 詳細設計 Round 5)
 - c2c 台帳: `global-test-lock` (origin: spirux:T1109/T1110、テンプレ昇格承認済み)
+
+---
+
+## D11 ✅ svelte-no-undef-gate を config 静的検査型で別実装 (同一不変条件・別実装)
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| gate 実装 | `tests/js/architecture/svelte-no-undef-gate.test.ts` (実装未確認 = mirror 未取得) | 同名ファイルを **ESLint `calculateConfigForFile()` による実効設定の静的検査**として独自実装 |
+
+### なぜ正当な差分か(logic-driven)
+
+c2c 台帳 `atomic-design-gates` の AG-023 裁定 (2026-08-05) で
+「aicue に svelte-no-undef-gate を補完する」ことは確定しているが、
+laravel-claude-template の mirror が本環境に無く、テンプレ実装を読めない。
+実装を待って不変条件を無防備のまま放置するより、
+**同じ不変条件を別実装で先に固定する**方が実害を早く閉じられる。
+
+### 揃えている不変条件(これは保証し続ける)
+
+> A. 「`resources/js` 配下の**全** `.svelte` で ESLint `no-undef` が error である」
+> B. 「その `languageOptions.globals` は**実行時グローバル**
+>    (`globals.browser` + `eslint.config.js` の `APP_RUNTIME_GLOBALS` 明示登録) と
+>    **完全一致**する — 型専用名を混ぜて no-undef を骨抜きにしない」
+> C. 「**lint 対象の全ファイル**
+>    (= `pnpm lint` = `eslint resources/js` の範囲 × `eslint.config.js` が `files` で
+>    対象にしている全拡張子: `.svelte` / `.js` / `.mjs` / `.cjs` / `.ts` / `.jsx` / `.tsx`) で
+>    `linterOptions.noInlineConfig` が true であり、inline の eslint-disable が効かない」
+
+`tests/js/architecture/svelte-no-undef-gate.test.ts` が
+ESLint 公開 API `calculateConfigForFile()` で実効設定を解決し、
+A/B を全 `.svelte` に、C を lint 対象全ファイルに適用して検査する。
+走査 0 件でも fail する (空振り防止)。
+検査ロジックは純関数 (`assertSvelteNoUndefConfig` / `assertNoInlineConfig`) に切り出し、
+正負のコントロールで検出器の実効性を固定している
+(ESLint の flat config マージ規則そのものは試験対象にしない)。
+
+**運用契約 1 (noInlineConfig 体制)**: ルールを黙らせる唯一の手段は
+`eslint.config.js` の file-scoped override。override を認めるのは
+(a) 抑制対象が具体的な 1 ファイル (または明示列挙) に閉じている
+(b) なぜ安全かがコード側コメントで説明されている
+(c) config 側に理由と再検討条件が書かれている — の 3 条件をすべて満たすときだけ。
+
+**運用契約 2 (宣言と検査範囲の一致)**: `pnpm lint` の対象を広げる
+(引数ディレクトリを増やす / 新しい拡張子を扱う) ときは、本 gate の
+`LINT_TARGET_EXTENSIONS` と走査ルートも**同一 PR で**広げること。
+宣言 (config コメント) と検査範囲が乖離すると「守っているつもりの穴」ができる。
+
+### 収束条件
+
+laravel-claude-template の mirror が取得できた時点でテンプレ実装と突き合わせ、
+実装を寄せられるなら本エントリを解消する。
+
+### 関連
+
+- 実装: `tests/js/architecture/svelte-no-undef-gate.test.ts`, `eslint.config.js`
+- 設計: `devnotes/20260805-0101-frontend-baseline-gates/detailed-design.md` 施策 4
+- 台帳: c2c `atomic-design-gates` AG-023 (2026-08-05 裁定), `eslint-svelte-ts-baseline`
diff --git a/eslint.config.js b/eslint.config.js
index 06930c9..6ca7487 100644
--- a/eslint.config.js
+++ b/eslint.config.js
@@ -2,6 +2,7 @@ import betterTailwind from "eslint-plugin-better-tailwindcss";
 import svelte from "eslint-plugin-svelte";
 import svelteParser from "svelte-eslint-parser";
 import tsParser from "@typescript-eslint/parser";
+import globals from "globals";
 
 // Tailwind v4 は CSS-first config。entryPoint に @import "tailwindcss" を宣言した
 // app.css を指す。callees は clsx/cva 系を導入したときに lint 対象にするための宣言。
@@ -12,7 +13,49 @@ const betterTailwindSettings = {
     },
 };
 
+/*
+ * .svelte に載せる実行時グローバル。
+ *
+ * **ここに載せてよいのは「実行時に存在するグローバル」だけ**。
+ * 型専用名 (WebIDL dictionary = MediaTrackConstraints / RequestInit 等) を足すことは
+ * 禁止する。足すと lint は緑になるが、同名を実行時の値として誤用したときにも
+ * no-undef が黙る = gate を入れる変更で gate に穴を開けることになる
+ * (PHPStan エラーを widen して黙らせるのと同じ悪手。AGENTS.md 禁止事項 2)。
+ *
+ * .svelte の型注釈に型専用名が必要になったら .ts 側へ逃がす:
+ *   1. ロジックごと .ts に移す (第一選択。.ts は tsc の検査対象になるので純増)
+ *      — 実例: lib/capture/camera.ts の videoConstraints()
+ *   2. 移せない (component props の型等) なら .ts で
+ *      `export type X = MediaTrackConstraints;` と別名 export し、
+ *      .svelte からは `import type` で参照する (module 参照は no-undef の対象外)
+ *
+ * アプリ固有の実行時グローバル (window に生やす等) が将来必要になったら、
+ * 下の APP_RUNTIME_GLOBALS に理由コメント付きで登録する。
+ * svelte-no-undef-gate が「globals.browser + APP_RUNTIME_GLOBALS と完全一致」を
+ * deny-by-default で検査するので、無登録の差分は CI で落ちる。
+ */
+const APP_RUNTIME_GLOBALS = {
+    // 現時点で登録なし。追加時は「なぜ実行時グローバルなのか」を必ず添えること。
+};
+
+const svelteGlobals = { ...globals.browser, ...APP_RUNTIME_GLOBALS };
+
 export default [
+    /*
+     * lint 対象 (`pnpm lint` = `eslint resources/js`) の全ファイルで、
+     * inline の eslint-disable / eslint-enable を一切許可しない。
+     * ルールを黙らせたいときの唯一の手段は **本ファイルの file-scoped override**。
+     * override を認めるのは次の 3 条件をすべて満たすときだけ:
+     *   (a) 抑制対象が具体的な 1 ファイル (または明示列挙されたファイル群) に閉じている
+     *   (b) なぜ安全かがコード側の日本語コメントで説明されている
+     *   (c) ここに理由と再検討条件 (いつ外せるか) を書く
+     * config に集約すれば diff に必ず現れ、レビュー可能かつ数えられる。
+     *
+     * **lint 対象を広げるとき** (`pnpm lint` の引数を増やす等) は、
+     * tests/js/architecture/svelte-no-undef-gate.test.ts の走査範囲も同時に広げること
+     * (宣言と検査の範囲が乖離すると gate が守っているつもりの穴ができる)。
+     */
+    { linterOptions: { noInlineConfig: true } },
     {
         ignores: [
             "tmp/**",
@@ -57,7 +100,14 @@ export default [
     {
         files: ["**/*.svelte"],
         plugins: { svelte },
+        languageOptions: {
+            globals: svelteGlobals,
+        },
         rules: {
+            // .svelte は tsc の検査対象外 (tsc --listFiles に 1 件も現れない)。
+            // 未定義識別子を捕まえる機構がここにしか無いので error 固定
+            // (spirux:T1054 = SSO 接続追加画面のクラッシュと同型の事故を止める)。
+            "no-undef": "error",
             "svelte/require-each-key": "error",
             "svelte/prefer-svelte-reactivity": "error",
             "svelte/prefer-writable-derived": "error",
diff --git a/package.json b/package.json
index 0ce417b..a84bb44 100644
--- a/package.json
+++ b/package.json
@@ -37,6 +37,7 @@
         "eslint": "^9.39.4",
         "eslint-plugin-better-tailwindcss": "4.4.1",
         "eslint-plugin-svelte": "^3.19.0",
+        "globals": "^17.9.0",
         "happy-dom": "^20.10.2",
         "jsdom": "^27.4.0",
         "laravel-vite-plugin": "^3.1.0",
diff --git a/resources/css/tokens.css b/resources/css/tokens.css
index 444a4b1..77eb5ca 100644
--- a/resources/css/tokens.css
+++ b/resources/css/tokens.css
@@ -28,7 +28,7 @@ @theme {
     /* ===== Status colors ===== */
     --color-success:         #15803d;
     --color-warning:         #b45309;
-    --color-danger:          #dc2626;
+    --color-danger:          #b91c1c;
 
     /* ===== Fonts ===== */
     --font-sans:  'Noto Sans JP', 'Hiragino Sans', 'Yu Gothic UI', 'Segoe UI',
diff --git a/resources/js/components/features/capture/CameraRecorder.svelte b/resources/js/components/features/capture/CameraRecorder.svelte
index db27f6f..847ae4d 100644
--- a/resources/js/components/features/capture/CameraRecorder.svelte
+++ b/resources/js/components/features/capture/CameraRecorder.svelte
@@ -20,6 +20,7 @@
         nextFacingMode,
         preferredRecordingMimeType,
         supportsPauseResume,
+        videoConstraints,
     } from "@/lib/capture/camera";
     import type {
         CameraErrorClassification,
@@ -164,18 +165,15 @@
         return Math.max(0, raw);
     }
 
-    // --- getUserMedia の制約を facingMode から組む (S6) ---
-    function videoConstraints(): MediaTrackConstraints {
-        return { facingMode };
-    }
-
     // 副作用なしの取得 (classify 結果を返すだけ。onCameraUnavailable/error を呼ばない)。
     // 呼び出し前に stream=null であること (reacquire 前は releaseCamera 済み)。stream ??= のため
     // 既存 stream があれば再取得しない = flip の reacquire では releaseCamera() 後に呼ぶ。
     async function acquireStream(): Promise<CameraErrorClassification | { kind: "ok" }> {
         try {
             stream ??= await navigator.mediaDevices.getUserMedia({
-                video: videoConstraints(), // facingMode を反映 (現行の "environment" 直書きを置換)
+                // 呼出時点の facingMode を渡す (reacquireWithFacing が代入した直後の値を読む)。
+                // キャッシュ禁止 — キャッシュすると flip 後も旧カメラで取得してしまう。
+                video: videoConstraints(facingMode),
                 audio: true,
             });
         } catch (cause) {
diff --git a/resources/js/lib/capture/camera.ts b/resources/js/lib/capture/camera.ts
index b8807a5..3cedf4f 100644
--- a/resources/js/lib/capture/camera.ts
+++ b/resources/js/lib/capture/camera.ts
@@ -106,3 +106,18 @@ export function supportsPauseResume(): boolean {
         typeof window.MediaRecorder.prototype?.resume === "function"
     );
 }
+
+/**
+ * getUserMedia の video 制約を facingMode から組む (S6)。
+ *
+ * **呼出時点の facingMode を引数で受ける純関数**にしてある。
+ * component 側でクロージャから読む形に戻したり、結果をキャッシュしたりしないこと
+ * (flip 後の再取得で古い facing mode を使う後退になり、実機でしか気づけない)。
+ *
+ * ここに置く理由: 型専用 interface (`MediaTrackConstraints` = WebIDL dictionary) は
+ * 実行時グローバルではないため .svelte 側では ESLint no-undef を解決できない。
+ * .ts へ置けば tsc の型検査対象にもなる (eslint.config.js の globals 方針を参照)。
+ */
+export function videoConstraints(mode: FacingMode): MediaTrackConstraints {
+    return { facingMode: mode };
+}
diff --git a/resources/js/pages/Settings/Security.svelte b/resources/js/pages/Settings/Security.svelte
index f16a54f..df69394 100644
--- a/resources/js/pages/Settings/Security.svelte
+++ b/resources/js/pages/Settings/Security.svelte
@@ -462,7 +462,6 @@
                                     class="self-start rounded-md border border-border bg-surface p-4"
                                     data-testid="two-factor-qr"
                                 >
-                                    <!-- eslint-disable-next-line svelte/no-at-html-tags -->
                                     {@html qrSvg}
                                 </div>
                             {:else}
diff --git a/tests/js/architecture/contrast-invariant.test.ts b/tests/js/architecture/contrast-invariant.test.ts
new file mode 100644
index 0000000..64cc483
--- /dev/null
+++ b/tests/js/architecture/contrast-invariant.test.ts
@@ -0,0 +1,134 @@
+import { describe, it, expect } from "vitest";
+import { designColors } from "../styles/design-md";
+import {
+    COLOR_TOKEN_MAP,
+    CONTRAST_EXEMPT_TOKENS,
+    FILL_LABEL_TOKENS,
+    FILL_TOKENS,
+    PENDING_CONTRAST_PAIRS,
+    SURFACE_ROLE_TOKENS,
+    TEXT_ON_SURFACE_TOKENS,
+} from "../styles/inventory";
+
+/*
+ * contrast-invariant — DESIGN.md のテーマ色が読める組合せであることを機械検証する。
+ *
+ * 【検査範囲】不透明 (opaque) なテキストペアのみ。
+ *   - 面 (neutral / surface) の上のテキスト色
+ *   - 塗り面 (primary / danger 等) の上のラベル色 (DESIGN.md §Components: bg-* + text-neutral)
+ *
+ * 【閾値】一律 4.5:1。
+ *   WCAG 2.2 SC 1.4.3 (AA) には「大きな文字は 3:1」の緩和があるが、
+ *   **トークン単位の gate は文字サイズを知り得ない**ため緩和は採らず、
+ *   厳しい側 (通常文字基準) を一律適用する。これは WCAG の要求そのものではなく
+ *   本プロジェクトの設計判断である。
+ *
+ * 【検査しないもの】inventory.ts の PENDING_CONTRAST_PAIRS を参照
+ *   (非テキスト 1.4.11 / alpha 合成)。「gate があるからコントラストは守られている」
+ *   という誤読を作らないため、未検査であることを明示宣言してある。
+ *
+ * 色値そのものを変えるときは DESIGN.md / tokens.css を同一 PR で更新すること
+ * (canonical-source-parity が drift を検出する)。
+ */
+
+const AA_NORMAL_TEXT = 4.5;
+
+/** sRGB チャンネルの線形化 (WCAG 2.x 相対輝度の定義) */
+function linearize(channel: number): number {
+    const c = channel / 255;
+    return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
+}
+
+/** #rrggbb → 相対輝度 (WCAG 2.x) */
+function relativeLuminance(hex: string): number {
+    const r = linearize(parseInt(hex.slice(1, 3), 16));
+    const g = linearize(parseInt(hex.slice(3, 5), 16));
+    const b = linearize(parseInt(hex.slice(5, 7), 16));
+    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
+}
+
+/** コントラスト比 (WCAG 2.x)。1.0〜21.0 */
+export function contrastRatio(a: string, b: string): number {
+    const [l1, l2] = [relativeLuminance(a), relativeLuminance(b)];
+    return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
+}
+
+const colors = designColors();
+
+function hex(token: string): string {
+    const value = colors.get(token);
+    if (value === undefined) throw new Error(`DESIGN.md colors に ${token} が無い`);
+    return value;
+}
+
+/** 検査対象ペア: [前景トークン, 背景トークン, 文脈] */
+const PAIRS: readonly (readonly [string, string, string])[] = [
+    // 自己ペア (同一トークン同士 = 比 1.0) の除外。現時点では両集合が素なので
+    // filter は実質 no-op だが、将来あるトークンが面とテキストの両方の役割を
+    // 持ったときに無意味な自己比較を作らないための防御として残す。
+    // 引数を string 注釈にしているのは、リテラル型同士だと TS が「重なりが無い比較」
+    // (TS2367) として弾くため — 検査を緩めるためではない。
+    ...TEXT_ON_SURFACE_TOKENS.flatMap((fg: string) =>
+        SURFACE_ROLE_TOKENS.filter((bg: string) => bg !== fg).map(
+            (bg) => [fg, bg, "面上のテキスト"] as const,
+        ),
+    ),
+    ...FILL_LABEL_TOKENS.flatMap((fg) =>
+        FILL_TOKENS.map((bg) => [fg, bg, "塗り面のラベル"] as const),
+    ),
+];
+
+describe("architecture/contrast-invariant: 不透明ペアのテキストコントラスト (一律 4.5:1)", () => {
+    it("役割宣言が DESIGN.md の全色トークンを覆う (deny-by-default)", () => {
+        const classified = new Set<string>([
+            ...SURFACE_ROLE_TOKENS,
+            ...TEXT_ON_SURFACE_TOKENS,
+            ...FILL_TOKENS,
+            ...FILL_LABEL_TOKENS,
+            ...Object.keys(CONTRAST_EXEMPT_TOKENS),
+        ]);
+        const unclassified = Object.keys(COLOR_TOKEN_MAP).filter((t) => !classified.has(t));
+        expect(
+            unclassified.sort(),
+            `未分類の色トークンがある。tests/js/styles/inventory.ts で ` +
+                `SURFACE_ROLE / TEXT_ON_SURFACE / FILL / FILL_LABEL / CONTRAST_EXEMPT の ` +
+                `いずれかに分類すること (免除するなら理由を書くこと): ${unclassified.join(", ")}`,
+        ).toEqual([]);
+
+        // 逆向き: 宣言に DESIGN.md に無いトークンが紛れていないか
+        const unknown = [...classified].filter((t) => !(t in COLOR_TOKEN_MAP));
+        expect(unknown.sort(), `DESIGN.md に存在しないトークンが宣言されている`).toEqual([]);
+    });
+
+    it("検査対象ペアが 0 件でない (空振り防止)", () => {
+        expect(PAIRS.length).toBeGreaterThan(0);
+    });
+
+    it("未検査宣言 (PENDING_CONTRAST_PAIRS) が空でない", () => {
+        // 「gate があるからコントラストは守られている」という誤読を防ぐ宣言そのものが
+        // 消し飛ばされないよう固定する。
+        // 出口: 1.4.11 / alpha 合成に対応して pending が空になったら、
+        // inventory.ts の宣言と本 it を **同時に削除**すること
+        // (空の宣言と、空でないことを確かめるテストを残すと形骸化する)。
+        expect(PENDING_CONTRAST_PAIRS.length).toBeGreaterThan(0);
+    });
+
+    it.each(PAIRS)("[opaque text] %s on %s (%s) が 4.5:1 以上", (fg, bg, context) => {
+        const ratio = contrastRatio(hex(fg), hex(bg));
+        expect(
+            ratio,
+            `${context}: text-${fg} on bg-${bg} = ${ratio.toFixed(2)}:1。` +
+                `DESIGN.md の色値を見直すこと (ペア集合を縮めて green にしないこと)`,
+        ).toBeGreaterThanOrEqual(AA_NORMAL_TEXT);
+    });
+
+    /* 負のコントロール: 計算器が実際に点灯することを既知値で確認する */
+    it("負のコントロール: 既知の低コントラスト対を検出し、既知の高コントラスト対は通す", () => {
+        expect(contrastRatio("#ffffff", "#ffffff")).toBeCloseTo(1, 5);
+        expect(contrastRatio("#000000", "#ffffff")).toBeCloseTo(21, 5);
+        // red-600 (#dc2626) on neutral (#f4f4f5) = 4.39 — 是正前の実測値。4.5 を割る
+        expect(contrastRatio("#dc2626", "#f4f4f5")).toBeLessThan(AA_NORMAL_TEXT);
+        // red-700 (#b91c1c) on neutral = 5.89 — 是正後
+        expect(contrastRatio("#b91c1c", "#f4f4f5")).toBeGreaterThanOrEqual(AA_NORMAL_TEXT);
+    });
+});
diff --git a/tests/js/architecture/svelte-no-undef-gate.test.ts b/tests/js/architecture/svelte-no-undef-gate.test.ts
new file mode 100644
index 0000000..ae6f7b8
--- /dev/null
+++ b/tests/js/architecture/svelte-no-undef-gate.test.ts
@@ -0,0 +1,199 @@
+import { describe, it, expect } from "vitest";
+import fs from "node:fs/promises";
+import path from "node:path";
+import { fileURLToPath } from "node:url";
+import { ESLint } from "eslint";
+import globals from "globals";
+
+/*
+ * svelte-no-undef-gate — .svelte の未定義識別子検出を config レベルで固定する。
+ *
+ * 背景: .svelte は tsc の検査対象外 (tsc --listFiles に 1 件も現れない)。
+ * 未定義識別子を捕まえる機構は eslint の no-undef **だけ**であり、
+ * これが外れると .svelte 全体が無検査に戻る (spirux:T1054 = SSO 接続追加画面の
+ * クラッシュと同型の事故が再発する)。
+ *
+ * 検査する不変条件:
+ *   A. [.svelte のみ] no-undef が error
+ *   B. [.svelte のみ] languageOptions.globals が globals.browser と **完全一致**
+ *      (型専用名を混ぜて no-undef を骨抜きにしない。追加は eslint.config.js の
+ *       APP_RUNTIME_GLOBALS へ理由付きで登録し、本 gate 側も同時に更新する)
+ *   C. [lint 対象の全ファイル] linterOptions.noInlineConfig が true
+ *      — A/B を inline コメントで黙らせないための **前提条件**。
+ *      `pnpm lint` = `eslint resources/js` なので、走査範囲も
+ *      **resources/js 配下 × eslint.config.js が files で対象にしている全拡張子**
+ *      (.svelte / .js / .mjs / .cjs / .ts / .jsx / .tsx) に一致させる。
+ *      .svelte だけ見ると .ts 向け file-scoped override での復活を見逃す。
+ *      lint されないファイル (tests/js 等) は ESLint が directive を読まないので対象外。
+ *   D. 走査対象が 0 件でない (空振り gate を green として扱わない)
+ *
+ * gate の名前が指す中心は「.svelte の no-undef」だが、
+ * それを支える C は前提の適用範囲 (= lint 対象全体) で検査する。
+ * **lint 対象を広げたら本 gate の LINT_TARGET_EXTENSIONS / 走査ルートも同時に広げること。**
+ *
+ * 実装は laravel-claude-template のものと **別実装**。同一不変条件・別実装の
+ * divergence として docs/template-divergence.md D11 に記録している。
+ */
+
+const HERE = path.dirname(fileURLToPath(import.meta.url));
+const REPO_ROOT = path.resolve(HERE, "../../../");
+const RESOURCES_JS = path.join(REPO_ROOT, "resources/js");
+
+/** 検査対象に落とし込んだ実効 config の view (純関数への入力) */
+interface ResolvedConfigView {
+    readonly rules?: Record<string, unknown>;
+    readonly linterOptions?: { readonly noInlineConfig?: boolean };
+    readonly languageOptions?: { readonly globals?: Record<string, unknown> };
+}
+
+/** 期待する globals キー集合 (allowlist。eslint.config.js の svelteGlobals と一対一) */
+const EXPECTED_GLOBAL_KEYS = Object.keys(globals.browser).sort();
+
+/**
+ * lint 対象の拡張子 (= eslint.config.js の files が対象にしている集合)。
+ * `pnpm lint` の対象を広げたらここも広げること。
+ */
+const LINT_TARGET_EXTENSIONS = [".svelte", ".js", ".mjs", ".cjs", ".ts", ".jsx", ".tsx"] as const;
+
+/**
+ * [C] inline の eslint-disable が効かないこと。**lint 対象の全拡張子**に適用する
+ * (`pnpm lint` = `eslint resources/js` の範囲 × LINT_TARGET_EXTENSIONS)。
+ */
+function assertNoInlineConfig(resolved: ResolvedConfigView): string[] {
+    return resolved.linterOptions?.noInlineConfig === true
+        ? []
+        : ["linterOptions.noInlineConfig が true でない (inline の eslint-disable が効いてしまう)"];
+}
+
+/**
+ * [A][B] .svelte 固有の不変条件を検査し、違反理由を返す (空配列 = 適合)。
+ * ESLint の設定マージ規則ではなく **解決結果**だけを見る純関数。
+ */
+function assertSvelteNoUndefConfig(resolved: ResolvedConfigView): string[] {
+    const problems: string[] = [];
+
+    const noUndef = resolved.rules?.["no-undef"];
+    // flat config の解決結果では severity は数値 (2 = error) を含む配列で返る
+    const severity = Array.isArray(noUndef) ? noUndef[0] : noUndef;
+    if (severity !== 2 && severity !== "error") {
+        problems.push(`no-undef が error でない (実効値: ${JSON.stringify(noUndef)})`);
+    }
+
+    const actualKeys = Object.keys(resolved.languageOptions?.globals ?? {}).sort();
+    const extra = actualKeys.filter((k) => !EXPECTED_GLOBAL_KEYS.includes(k));
+    const missing = EXPECTED_GLOBAL_KEYS.filter((k) => !actualKeys.includes(k));
+    if (extra.length > 0) {
+        problems.push(
+            `globals に globals.browser 外のキーがある: ${extra.join(", ")} ` +
+                `(型専用名の登録は禁止。実行時グローバルなら eslint.config.js の ` +
+                `APP_RUNTIME_GLOBALS へ理由付きで登録し、本テストの期待値も同時に更新すること)`,
+        );
+    }
+    if (missing.length > 0) {
+        problems.push(`globals に globals.browser のキーが不足: ${missing.slice(0, 5).join(", ")}…`);
+    }
+
+    return problems;
+}
+
+async function sourceFiles(dir: string, exts: readonly string[]): Promise<string[]> {
+    const out: string[] = [];
+    for (const e of await fs.readdir(dir, { recursive: true, withFileTypes: true })) {
+        if (e.isFile() && exts.some((ext) => e.name.endsWith(ext))) {
+            out.push(path.join(e.parentPath, e.name));
+        }
+    }
+    return out.sort(); // 失敗メッセージを走査順の環境差で揺らさない
+}
+
+/** 実効設定を解決する。解決できない場合は silent skip せず明瞭に fail させる。 */
+async function resolveConfig(eslint: ESLint, file: string): Promise<ResolvedConfigView> {
+    const resolved: unknown = await eslint.calculateConfigForFile(file);
+    if (typeof resolved !== "object" || resolved === null) {
+        throw new Error(
+            `実効設定を解決できなかった: ${path.relative(REPO_ROOT, file)} ` +
+                `(eslint.config.js の ignores に入っていないか確認すること)`,
+        );
+    }
+    return resolved as ResolvedConfigView;
+}
+
+describe("architecture/svelte-no-undef-gate", () => {
+    it("[A][B] resources/js 配下の全 .svelte で no-undef=error かつ globals が globals.browser と完全一致", async () => {
+        const files = await sourceFiles(RESOURCES_JS, [".svelte"]);
+        // 空振り防止: 走査が 0 件なら gate は何も守っていない
+        expect(
+            files.length,
+            "resources/js 配下に .svelte が 1 件も無い (走査が空振りしている)",
+        ).toBeGreaterThan(0);
+
+        const eslint = new ESLint({ cwd: REPO_ROOT });
+        const offenders: string[] = [];
+        for (const file of files) {
+            for (const problem of assertSvelteNoUndefConfig(await resolveConfig(eslint, file))) {
+                offenders.push(`${path.relative(REPO_ROOT, file)}: ${problem}`);
+            }
+        }
+        expect(
+            offenders,
+            `.svelte の未定義識別子検出が無効化されている。eslint.config.js を確認すること: \n` +
+                offenders.join("\n"),
+        ).toEqual([]);
+    });
+
+    it("[C] lint 対象 (resources/js × 全 lint 拡張子) で inline の eslint-disable が効かない", async () => {
+        // noInlineConfig は A/B を inline コメントで黙らせないための前提条件。
+        // .svelte だけ見ると .ts 等向けの file-scoped override での復活を見逃す。
+        const files = await sourceFiles(RESOURCES_JS, LINT_TARGET_EXTENSIONS);
+        expect(
+            files.length,
+            "resources/js 配下に lint 対象ファイルが 1 件も無い (走査が空振りしている)",
+        ).toBeGreaterThan(0);
+
+        const eslint = new ESLint({ cwd: REPO_ROOT });
+        const offenders: string[] = [];
+        for (const file of files) {
+            for (const problem of assertNoInlineConfig(await resolveConfig(eslint, file))) {
+                offenders.push(`${path.relative(REPO_ROOT, file)}: ${problem}`);
+            }
+        }
+        expect(
+            offenders,
+            `inline の eslint-disable が有効に戻っている。ルールを黙らせる唯一の手段は ` +
+                `eslint.config.js の file-scoped override (3 条件を満たすこと): \n` +
+                offenders.join("\n"),
+        ).toEqual([]);
+    });
+
+    /*
+     * 負のコントロール: 検査器が実際に点灯することを、解決結果を加工した
+     * plain object で確認する (ESLint のマージ規則は試験対象にしない)。
+     */
+    it("負のコントロール: no-undef 解除 / globals 汚染 / noInlineConfig 無効を検出する", () => {
+        const sound: ResolvedConfigView = {
+            rules: { "no-undef": [2] },
+            linterOptions: { noInlineConfig: true },
+            languageOptions: { globals: { ...globals.browser } },
+        };
+        expect(assertSvelteNoUndefConfig(sound), "正のコントロール (svelte)").toEqual([]);
+        expect(assertNoInlineConfig(sound), "正のコントロール (noInlineConfig)").toEqual([]);
+
+        expect(
+            assertSvelteNoUndefConfig({ ...sound, rules: { "no-undef": [0] } }),
+            "no-undef=off",
+        ).toHaveLength(1);
+        expect(
+            assertSvelteNoUndefConfig({
+                ...sound,
+                languageOptions: {
+                    globals: { ...globals.browser, MediaTrackConstraints: "readonly" },
+                },
+            }),
+            "型専用名の混入",
+        ).toHaveLength(1);
+        expect(
+            assertNoInlineConfig({ ...sound, linterOptions: { noInlineConfig: false } }),
+            "noInlineConfig=false",
+        ).toHaveLength(1);
+    });
+});
diff --git a/tests/js/components/features/capture/CameraRecorder.test.ts b/tests/js/components/features/capture/CameraRecorder.test.ts
index a3d7d5c..c3522be 100644
--- a/tests/js/components/features/capture/CameraRecorder.test.ts
+++ b/tests/js/components/features/capture/CameraRecorder.test.ts
@@ -964,6 +964,14 @@ describe("CameraRecorder", () => {
         // 段階1 は未検証で false → releaseCamera + getUserMedia 再取得
         await vi.waitFor(() => expect(getUserMediaMock).toHaveBeenCalledTimes(2));
         expect(live.stop).toHaveBeenCalled();
+
+        // 再取得の制約は **呼出時点の facingMode** (flip 後 = "user") を反映する。
+        // videoConstraints() を .ts へ移す際にクロージャ読みからキャッシュへ退行すると
+        // ここが落ちる (実機でしか気づけない後退を単体で止めるための characterization)。
+        const reacquireCall = (getUserMediaMock.mock.calls[1] as unknown[])[0] as {
+            video: MediaTrackConstraints;
+        };
+        expect(reacquireCall.video).toMatchObject({ facingMode: "user" });
     });
 
     it("カメラ反転 (新 facing のみ不可): 旧 facingMode へ復旧し onCameraUnavailable を呼ばない", async () => {
diff --git a/tests/js/lib/capture/camera.test.ts b/tests/js/lib/capture/camera.test.ts
index 40cc03b..bf97973 100644
--- a/tests/js/lib/capture/camera.test.ts
+++ b/tests/js/lib/capture/camera.test.ts
@@ -6,6 +6,7 @@ import {
     preferredRecordingMimeType,
     supportsMediaRecorder,
     supportsPauseResume,
+    videoConstraints,
 } from "@/lib/capture/camera";
 
 /*
@@ -173,3 +174,24 @@ describe("supportsPauseResume", () => {
         expect(supportsPauseResume()).toBe(false);
     });
 });
+
+/*
+ * videoConstraints: getUserMedia の video 制約を facingMode から組む純関数。
+ * .svelte 側のクロージャ読みから .ts の引数受け取りへ移した際の仕様固定
+ * (呼出時点の facingMode をそのまま反映する = キャッシュしない)。
+ */
+describe("videoConstraints", () => {
+    it("environment をそのまま facingMode に載せる", () => {
+        expect(videoConstraints("environment")).toEqual({ facingMode: "environment" });
+    });
+
+    it("user をそのまま facingMode に載せる", () => {
+        expect(videoConstraints("user")).toEqual({ facingMode: "user" });
+    });
+
+    it("呼び出しごとに引数を評価する (結果をキャッシュしない)", () => {
+        expect(videoConstraints("environment")).toEqual({ facingMode: "environment" });
+        expect(videoConstraints("user")).toEqual({ facingMode: "user" });
+        expect(videoConstraints("environment")).toEqual({ facingMode: "environment" });
+    });
+});
diff --git a/tests/js/styles/canonical-source-parity.test.ts b/tests/js/styles/canonical-source-parity.test.ts
index 7604579..3b7dcad 100644
--- a/tests/js/styles/canonical-source-parity.test.ts
+++ b/tests/js/styles/canonical-source-parity.test.ts
@@ -7,31 +7,15 @@ import {
     RADIUS_TOKENS,
     TYPOGRAPHY_RAMPS,
 } from "./inventory";
+// DESIGN.md 側のパーサは contrast-invariant と共有する (二重実装しない)。
+import { REPO_ROOT, designColors, designRamp, designRounded } from "./design-md";
 
 /**
  * DESIGN.md (canonical) ⇔ resources/css/tokens.css (実装写像) の双方向同期を機械検証する。
  * 片方だけ更新された PR をここで落とす (docs/design-system.md の同期契約)。
  */
 
-const ROOT = path.resolve(__dirname, "../../..");
-const designMd = fs.readFileSync(path.join(ROOT, "DESIGN.md"), "utf-8");
-const tokensCss = fs.readFileSync(path.join(ROOT, "resources/css/tokens.css"), "utf-8");
-
-const frontmatter = (() => {
-    const m = designMd.match(/^---\n([\s\S]*?)\n---/);
-    if (!m) throw new Error("DESIGN.md frontmatter not found");
-    return m[1];
-})();
-
-function designColors(): Map<string, string> {
-    const section = frontmatter.match(/^colors:\n((?: {4}\S[^\n]*\n)+)/m);
-    if (!section) throw new Error("DESIGN.md colors section not found");
-    const map = new Map<string, string>();
-    for (const line of section[1].matchAll(/^ {4}([a-z-]+): "(#[0-9A-Fa-f]{6})"$/gm)) {
-        map.set(line[1], line[2].toLowerCase());
-    }
-    return map;
-}
+const tokensCss = fs.readFileSync(path.join(REPO_ROOT, "resources/css/tokens.css"), "utf-8");
 
 function cssColorTokens(): Map<string, string> {
     const map = new Map<string, string>();
@@ -66,12 +50,8 @@ describe("canonical source parity: colors", () => {
 
 describe("canonical source parity: radius", () => {
     it("DESIGN.md rounded と tokens.css の --radius-* が一致する", () => {
-        const section = frontmatter.match(/^rounded:\n((?: {4}\S[^\n]*\n)+)/m);
-        expect(section).not.toBeNull();
-        const design = new Map<string, string>();
-        for (const m of section![1].matchAll(/^ {4}([a-z]+): (\d+px)$/gm)) {
-            design.set(m[1], m[2]);
-        }
+        // section 不在は designRounded() が例外で落とす (旧 expect(section).not.toBeNull() 相当)
+        const design = designRounded();
 
         const css = new Map<string, string>();
         for (const m of tokensCss.matchAll(/--radius-([a-z]+):\s*([^;]+);/g)) {
@@ -86,18 +66,6 @@ describe("canonical source parity: radius", () => {
 });
 
 describe("canonical source parity: typography ramp", () => {
-    function designRamp(name: string): Record<string, string> {
-        const m = frontmatter.match(
-            new RegExp(`^ {4}${name}:\\n((?: {8}\\S[^\\n]*\\n)+)`, "m"),
-        );
-        if (!m) throw new Error(`DESIGN.md typography ramp not found: ${name}`);
-        const props: Record<string, string> = {};
-        for (const line of m[1].matchAll(/^ {8}([a-zA-Z]+): "?([^"\n]+)"?$/gm)) {
-            props[line[1]] = line[2];
-        }
-        return props;
-    }
-
     function cssRamp(name: string): Record<string, string> {
         const m = tokensCss.match(new RegExp(`@utility text-${name} \\{([^}]+)\\}`));
         if (!m) throw new Error(`tokens.css @utility not found: text-${name}`);
diff --git a/tests/js/styles/design-md.ts b/tests/js/styles/design-md.ts
new file mode 100644
index 0000000..cc8d6f5
--- /dev/null
+++ b/tests/js/styles/design-md.ts
@@ -0,0 +1,55 @@
+/**
+ * DESIGN.md (canonical source) の frontmatter パーサ — 検査テスト共有。
+ *
+ * canonical-source-parity (DESIGN.md ⇔ tokens.css の同期) と
+ * contrast-invariant (色の可読性) が **同一のパーサ**を使うためのヘルパ。
+ * パーサを二重実装すると「片方だけが読める DESIGN.md」という状態を作れてしまう。
+ */
+import fs from "node:fs";
+import path from "node:path";
+import { fileURLToPath } from "node:url";
+
+const HERE = path.dirname(fileURLToPath(import.meta.url));
+export const REPO_ROOT = path.resolve(HERE, "../../../");
+
+const designMd = fs.readFileSync(path.join(REPO_ROOT, "DESIGN.md"), "utf-8");
+
+/** DESIGN.md 冒頭の `---` で囲まれた frontmatter 本文 */
+export const frontmatter: string = (() => {
+    const m = designMd.match(/^---\n([\s\S]*?)\n---/);
+    if (!m) throw new Error("DESIGN.md frontmatter not found");
+    return m[1];
+})();
+
+/** frontmatter `colors:` → `{ トークン名 → "#rrggbb" (小文字) }` */
+export function designColors(): Map<string, string> {
+    const section = frontmatter.match(/^colors:\n((?: {4}\S[^\n]*\n)+)/m);
+    if (!section) throw new Error("DESIGN.md colors section not found");
+    const map = new Map<string, string>();
+    for (const line of section[1].matchAll(/^ {4}([a-z-]+): "(#[0-9A-Fa-f]{6})"$/gm)) {
+        map.set(line[1], line[2].toLowerCase());
+    }
+    return map;
+}
+
+/** frontmatter `rounded:` → `{ 段名 → "Npx" }` */
+export function designRounded(): Map<string, string> {
+    const section = frontmatter.match(/^rounded:\n((?: {4}\S[^\n]*\n)+)/m);
+    if (!section) throw new Error("DESIGN.md rounded section not found");
+    const map = new Map<string, string>();
+    for (const m of section[1].matchAll(/^ {4}([a-z]+): (\d+px)$/gm)) {
+        map.set(m[1], m[2]);
+    }
+    return map;
+}
+
+/** frontmatter `typography.<name>:` → `{ プロパティ名 → 値 }` */
+export function designRamp(name: string): Record<string, string> {
+    const m = frontmatter.match(new RegExp(`^ {4}${name}:\\n((?: {8}\\S[^\\n]*\\n)+)`, "m"));
+    if (!m) throw new Error(`DESIGN.md typography ramp not found: ${name}`);
+    const props: Record<string, string> = {};
+    for (const line of m[1].matchAll(/^ {8}([a-zA-Z]+): "?([^"\n]+)"?$/gm)) {
+        props[line[1]] = line[2];
+    }
+    return props;
+}
diff --git a/tests/js/styles/inventory.ts b/tests/js/styles/inventory.ts
index d16b8b4..340460c 100644
--- a/tests/js/styles/inventory.ts
+++ b/tests/js/styles/inventory.ts
@@ -33,3 +33,69 @@ export const DERIVED_COLOR_TOKENS = [
 export const RADIUS_TOKENS = ["sm", "md", "lg"] as const;
 
 export const TYPOGRAPHY_RAMPS = ["display", "h1", "h2", "h3", "body", "caption"] as const;
+
+/*
+ * ===== コントラスト検査の役割宣言 (contrast-invariant.test.ts の入力) =====
+ *
+ * DESIGN.md の全色トークンは下の 5 分類の**いずれかに必ず属する** (deny-by-default)。
+ * 未分類のトークンがあれば contrast-invariant が fail する = 新トークンが
+ * 黙って gate をすり抜けられない。
+ */
+
+/** 面 (背景) として塗るトークン。DESIGN.md §Colors: neutral=画面全体 / surface=カード・モーダル */
+export const SURFACE_ROLE_TOKENS = ["neutral", "surface"] as const;
+
+/** 面の上に載るテキスト色 (本文・見出し・意味を担う状態テキスト) */
+export const TEXT_ON_SURFACE_TOKENS = [
+    "text-primary",
+    "text-secondary",
+    "primary", // リンク / TextLink
+    "tertiary",
+    "success",
+    "warning",
+    "danger", // Alert 見出し / Button danger-ghost のラベル
+] as const;
+
+/** 塗り面 (solid fill) として使うトークン。DESIGN.md §Components Button の bg-* */
+export const FILL_TOKENS = [
+    "primary",
+    "primary-hover",
+    "tertiary",
+    "tertiary-hover",
+    "success",
+    "warning",
+    "danger",
+] as const;
+
+/** 塗り面の上に載るラベル色。DESIGN.md §Components: `bg-* + text-neutral` */
+export const FILL_LABEL_TOKENS = ["neutral"] as const;
+
+/**
+ * コントラスト検査の対象外トークン (理由必須)。
+ * 「検査していない」ことを見えるようにするための明示宣言であり、免罪符ではない。
+ */
+export const CONTRAST_EXEMPT_TOKENS = {
+    "border":
+        "1px の区切り線・入力欄の枠。テキストではなく WCAG 1.4.11 (非テキスト 3:1) の領域。" +
+        "装飾的な境界線は 1.4.11 の適用除外のため、使用箇所ごとの役割分類が要る (v1 スコープ外)",
+    "border-strong":
+        "区切りの強調・ghost ボタンの枠。ghost ボタンの枠は機能的境界の可能性があり、" +
+        "実測 2.56 で 3:1 に届かない。値の是正は『どの border が機能的境界か』の" +
+        "役割モデルを DESIGN.md に定めてから別バッチで行う (申し送り 5-3)",
+} as const;
+
+/**
+ * 未検査であることを明示する pending 集合 (v1 スコープ外)。
+ * contrast-invariant はこれらを検査しない — 「gate があるからコントラストは守られている」
+ * という誤読を作らないための宣言。
+ *
+ * **出口**: pending 項目に対応したらその行を削る。全部消えたら
+ * 本 export と contrast-invariant.test.ts の
+ * 「未検査宣言 (PENDING_CONTRAST_PAIRS) が空でない」テストを**同時に削除**すること
+ * (空の宣言と、空でないことを確かめるテストを残すと形骸化する)。
+ */
+export const PENDING_CONTRAST_PAIRS = [
+    "WCAG 1.4.11 非テキストコントラスト (3:1): border / border-strong / focus ring",
+    "alpha 合成ペア: Badge の bg-<tone>/10 + text-<tone>、bg-primary-soft、ring-primary/35、" +
+        "bg-text/70 + text-surface (合成後の実効色が親背景に依存しトークン単体では定まらない)",
+] as const;
```

## テスト結果 (実測)

- 実装前ベースライン: `pnpm test` = **106 files / 968 tests passed, 0 failed**
- 実装後: `pnpm test` = **108 files / 999 tests passed, 0 failed** (+2 files / +31 tests)
- `pnpm lint` = exit 0
- `pnpm typecheck` = exit 0
- `pnpm build` = exit 0
- `pnpm install --frozen-lockfile` = exit 0
- `pnpm run audit:gate` = **main と完全に同一の 18 件の既存 advisory で fail (既存事象)**。
  main / T102 の advisory 一覧を diff して差分ゼロを確認済み。`globals` は新規 advisory を持ち込んでいない

### テストファースト実測 (Red を先に確認済み)

| # | 実測結果 |
|---|---|
| R4a | `videoConstraints` 未実装で **3 tests failed** (`videoConstraints is not a function`) → 実装後 green |
| R1 | 現行 config で `svelte-no-undef-gate` が **2 tests failed**。`[A][B]` は全 .svelte 分の `no-undef が error でない (実効値: undefined)` + `globals に globals.browser のキーが不足`、`[C]` は lint 対象全件分の `noInlineConfig が true でない` を報告 → 施策 2 適用後 green |
| R2 | `danger` = `#DC2626` のまま `contrast-invariant` が **2 tests failed** (`text-danger on bg-neutral = 4.39:1` / `text-neutral on bg-danger = 4.39:1`) → 是正後 green (21 ペア全通過) |
| R3 | `TEXT_ON_SURFACE_TOKENS` から `text-secondary` を一時削除すると「未分類の色トークンがある: text-secondary」で fail → deny-by-default が実効的 |
| C1 | flip 再取得経路の `getUserMedia` 制約 assert は **移動前に green** であることを確認してから `videoConstraints` を .ts へ移動し、移動後も green |

### 設計の追加検証 (手元実測)

- 空振り防止: 空ディレクトリを走査させると `files.length === 0` となり `toBeGreaterThan(0)` が点灯することを確認
- `resolveConfig()` の guard: `eslint.config.js` の `ignores` に入るパス (`node_modules/foo/bar.js` / `storage/x.ts`) では `calculateConfigForFile()` が **`undefined` を返す**ため `typeof !== "object"` で明示 throw する (silent skip しない) ことを確認
- `resources/js/app.ts` の実効 `noInlineConfig` が `true` であることを確認 (C が .ts にも及んでいる)
- `resources/js` 配下の inline eslint directive は Security.svelte:465 の 1 件のみ (grep 実測)。撤去後は 0 件

## 実装者からの申し送り (レビューで特に見てほしい点)

1. **設計コードからの意図的逸脱 1 件**: 詳細設計 §施策 6 の `contrast-invariant.test.ts` の
   `PAIRS` 構築部は、そのままでは `pnpm typecheck` が **TS2367** で落ちた
   (`SURFACE_ROLE_TOKENS.filter((bg) => bg !== fg)` がリテラル型同士で重なりが無い比較)。
   自己ペア除外の防御を残したまま型を通すため、callback 引数を `string` 注釈にした。
   この判断が「型を緩めて黙らせる」に当たらないか判定してほしい。
   (代替案: filter ごと削除する。現時点で両集合は素なので挙動は同一)

2. **設計の事実誤認を 1 件発見**: 詳細設計 §施策 6 のリスク表は
   「メールテンプレに `danger` は含まれない (grep 実測)」としているが、実際には
   `resources/views/vendor/mail/html/themes/template.css` の `.button-red` / `.button-error` が
   `#dc2626` を直書きしている。ただし同ファイルは `#16a34a` (green-600) も直書きしており、
   DS token の写像ではなく Laravel 同梱テーマの独立パレットである。
   本バッチでは**対象外のまま据え置いた**。この判断の是非を見てほしい。

3. **`canonical-source-parity.test.ts` の rounded ブロックで assertion が 1 つ消えている**:
   旧 `expect(section).not.toBeNull()` は、共有ヘルパ `designRounded()` 内の
   `throw new Error("DESIGN.md rounded section not found")` に置き換わった
   (詳細設計 L804 の指示どおり)。失敗時にテストが落ちる点は同じだが、
   検出力が落ちていないか確認してほしい。

4. `public/capture-sw.js` は lint 対象外のまま据え置いている (指示による)。
