# アプリの使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

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

（アプリの使命・禁止事項は app-codex-review スキルにより AGENTS.md から自動挿入済み）

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript / PHPStan level 10 / Pest / vitest(jsdom)

【本件の特殊事情 — 必ず考慮すること】
本設計の対象は**アプリのプロダクションコードではなく、LLM 探索的バグハント基盤
(.claude/skills/app-bug-hunt/ と .claude/agents/bughunt-shard.md) の走行プロトコル・記録器 (JS) ・
申し送り台帳**である。アプリコードは 1 行も変更しない。したがって DTO/JsonResource/PHPStan の
観点はほぼ発生せず、レビューの主眼は次に置くこと:
1. 記録器 (feedback-probe.js) のロジックの正確性・エッジケース (DOM/MutationObserver/可視性/基線)
2. プロトコル規約が「誤検知を減らす代わりに本物の finding を取りこぼす (偽陰性)」方向に倒れていないか
3. 正本の分割 (SKILL.md / bughunt-shard.md / spec-ledger.md / ledger/adjudications.jsonl) に
   重複・二重管理・矛盾が無いか
4. テスト計画の網羅性と、担保できないことの切り分けの誠実さ
5. オーバーエンジニアリングでないか (AGENTS.md 思考原則 2「今必要なものだけ作る」)
6. 既存機構との結合 (validate_findings.py の adjudication マッチング、coverage/test_naming_no_stale.py の
   語彙 lint、scripts/bug-hunt-shard.sh の orchestrator gate) を壊していないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: bug-hunt driver の一過性フィードバック捕捉 (toast capture) と spec-ledger 申し送り

- design_dir: `devnotes/20260804-0900-bughunt-toast-capture/`
- 概念設計: [conceptual-design.md](./conceptual-design.md) (Codex 概念レビュー Round 5 で **APPROVED**)

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

> **AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、
> そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
> **標準化されたマニュアル動画**を作れるようにする。
>
> - 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
> - 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
>   (撮影者・教える人のスキルに品質を依存させない)。
> - 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

本施策は **bug-hunt (UX 品質の dogfooding 検証基盤) の観測精度**を直す。
誤検知は triage コストを食い、本物の UX 破綻の信号を埋もれさせるため、使命への貢献は
「発見機構そのものの品質改善」という間接経路である。

### 禁止事項 (AGENTS.md)

1. テストなしの実装完了報告 (不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作 (`migrate:fresh` 等) をエージェント判断で実行すること
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用 (Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

**本施策で特に効くもの**: #1 (テストなしの完了報告禁止 → 施策 5/6 が必須)、
#3 (bug-hunt は provision を伴わない検証しかできない = §検証計画)。

### bug-hunt スキル固有の禁止事項 (SKILL.md §禁止事項)

- **バグを見つけても修正しない** (本施策はアプリコードを 1 行も変更しない)
- **誤検知をバグとして断定しない** ← 本施策が機構として支える対象

### コーディングルール

- PHP 変更なし (**PHPStan / Pest / Factory の観点は本施策に発生しない**)。
- 追加する JS は **`.claude/skills/app-bug-hunt/probes/feedback-probe.js` の 1 本のみ**。
  `pnpm lint` は `resources/js` のみを対象 (`package.json` の `lint` script) なので lint 対象外。
  `tsconfig.json` の `include` も `resources/js/**` と `tests/js/**/*.ts` のみなので `tsc --noEmit` の対象外。
- 追加するテストは TypeScript (`tests/js/**`, tsconfig strict 配下) と stdlib Python
  (`.claude/skills/app-bug-hunt/ledger/`) の 2 本。
- **`.claude/skills/app-bug-hunt/` 配下の .md / .py に `Stage 1` / `Stage 3` / `coverage-stage[13].md` を
  書かない** (`coverage/test_naming_no_stale.py` が機械検出して fail する)。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | feedback probe (記録器) の新設 | `.claude/skills/app-bug-hunt/probes/feedback-probe.js` (新規) | 高 |
| 2 | 走行プロトコルへの組み込み (正本) | `.claude/skills/app-bug-hunt/SKILL.md` | 高 |
| 3 | shard worker のコマンド表に 1 行追加 | `.claude/agents/bughunt-shard.md` | 中 |
| 4 | spec-ledger の書式拡張 + run 20260803-203721 申し送り (F-1-02) | `.claude/skills/app-bug-hunt/spec-ledger.md` | 高 |
| 5 | probe 契約の回帰テスト (jsdom) | `tests/js/bughunt/feedback-probe.test.ts` (新規) | 高 |
| 6 | spec-ledger の腐り検知テスト (stdlib) | `.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py` (新規) | 高 |

**アプリコード (`app/` `resources/` `routes/` `database/`) は一切変更しない。**

---

## 施策 1: feedback probe (記録器) の新設

### 変更箇所

- 新規ファイル: `.claude/skills/app-bug-hunt/probes/feedback-probe.js`

### 波及変更

- TypeScript 型定義: なし (probe は文字列として `eval` に渡す。import しない)
- API Resource/DTO: なし
- テストファイル: 施策 5 (`tests/js/bughunt/feedback-probe.test.ts`) が本ファイルを読み込んで検証する

### 設計根拠 (なぜこの形か)

| 論点 | 決定 | 根拠 |
|---|---|---|
| 待つ vs 記録する | **記録する** (操作前に MutationObserver) | `@playwright/cli` に wait-for 系コマンドが無い。かつ wait-for は「まだ出ていない」と「もう消えた」を区別できない。先行実証は `tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php:60-92` |
| セレクタ | `[role=status],[role=alert]` | AI-CUE の非単調 UI 2 件 (toast / CodeSnippet) が両方 live region を持つ。testid ではなく W3C セマンティクスに乗せる (思考原則 1) |
| ファイル vs SKILL.md 内 inline | **ファイル** | `"$(cat …)"` で渡せば shell クォート事故が構造的に起きない。正本が 1 箇所になる |
| 呼び出し形 | IIFE 式 `(() => {…})()` | `playwright-cli eval` は target 無しでは**式**として評価する (`eval "document.title"`)。IIFE なら式評価・関数ソース評価のどちらの実装でも同じ結果になる |
| 状態の持ち方 | `window.__bhFeedbackRecorder` + 要素 property `__bhBaseline` | Inertia の SPA visit は同一 document なので生き残る (`resources/js/pages/Manuals/Show.svelte:55-65` の `router.delete`)。document 置換時は消えるが、それは `installed_now:true` として**検知できる** |

### 変更後コード (全文)

```javascript
/*
 * bug-hunt driver 用「一過性フィードバック記録器」(feedback probe)。
 *
 * 使い方 (正本は .claude/skills/app-bug-hunt/SKILL.md §一過性フィードバックの観測):
 *   playwright-cli --raw eval "$(cat "$(git rev-parse --show-toplevel)/.claude/skills/app-bug-hunt/probes/feedback-probe.js")"
 *
 * なぜ必要か: toast (success/info/warning) は 4000ms、CodeSnippet の「コピー完了」は 2000ms で
 * 自動消滅する (resources/js/lib/stores/toast.ts / components/molecules/CodeSnippet.svelte)。
 * 事後 snapshot 1 点では「出なかった」と「もう消えた」を区別できず、誤検知になる
 * (run 20260803-203721 の F-1-02 = 誤検知確定)。そこで **操作の前に記録器を仕込む**。
 *
 * 返り値 (JSON 文字列):
 *   installed_now         : 今回の呼び出しで記録器を新規設置したか (true = 直前に document が置換された)
 *   seen[]                : 前回 probe 以降に出現/変化した live region (消えた後も残る)
 *                           visible: true=可視 / false=不可視 / gone=1 フレーム以内に消えた
 *   present_new[]         : 現在 DOM にある live region のうち「基線が無い or テキストが変わった」もの
 *   present_preexisting   : 基線と一致する常駐 live region の件数 (判定に使わない)
 *   pending               : 可視性判定が未解決の候補数 (>0 ならもう一度 probe する)
 */
(() => {
    const KEY = "__bhFeedbackRecorder";
    const LIVE = "[role=status],[role=alert]";
    const raf =
        typeof window.requestAnimationFrame === "function"
            ? window.requestAnimationFrame.bind(window)
            : (cb) => setTimeout(cb, 0);

    /** layout 非依存の足切り (mutation callback 内で使う)。 */
    const plausible = (el) =>
        el.isConnected && !el.hidden && !el.closest("[aria-hidden=true]");

    /** 完全な可視判定 (layout 依存。FlashToastTest.php と同じ条件)。 */
    const visible = (el) => {
        if (!plausible(el)) return false;
        const style = window.getComputedStyle(el);
        return (
            style.visibility !== "hidden" &&
            style.display !== "none" &&
            el.getClientRects().length > 0
        );
    };

    const text = (el) => (el.textContent || "").replace(/\s+/g, " ").trim().slice(0, 200);

    const describe = (el) => {
        const host = el.closest("[data-testid]");
        return {
            role: el.getAttribute("role"),
            testid: host ? host.getAttribute("data-testid") : null,
            text: text(el),
            t: Math.round(performance.now()),
        };
    };

    const collect = (node) => {
        const out = [];
        if (!node || node.nodeType !== 1) return out;
        if (node.matches(LIVE)) out.push(node);
        for (const el of node.querySelectorAll(LIVE)) out.push(el);
        return out;
    };

    let installedNow = false;

    if (!window[KEY]) {
        installedNow = true;
        const state = { seen: [], pending: 0, armedAt: Math.round(performance.now()) };

        // 候補を「生きているうちに」次フレームで可視判定してから seen に確定する。
        // 一過性 UI は消えた後では可視性を測れないため、記録時点の同期評価では足りない。
        const enqueue = (el) => {
            // layout 非依存の足切り (detached でも closest は辿れる)
            if (el.hidden || el.closest("[aria-hidden=true]")) return;
            const entry = describe(el);
            if (!el.isConnected) {
                // callback 到達前に消えた = 1 フレーム未満の点滅 (知覚不能)。捨てずに gone で残す
                entry.visible = "gone";
                state.seen.push(entry);
                return;
            }
            state.pending += 1;
            raf(() => {
                entry.visible = el.isConnected ? visible(el) : "gone";
                state.pending -= 1;
                state.seen.push(entry);
            });
        };

        state.observer = new MutationObserver((records) => {
            for (const r of records) {
                for (const n of r.addedNodes) for (const el of collect(n)) enqueue(el);
                if (r.type === "characterData") {
                    const host = r.target.parentElement && r.target.parentElement.closest(LIVE);
                    if (host) enqueue(host);
                }
            }
        });
        state.observer.observe(document.documentElement, {
            childList: true,
            subtree: true,
            characterData: true,
        });
        window[KEY] = state;
    }

    const state = window[KEY];

    // 基線差分: 「前回 probe 以降に可視化された / テキストが変わった」live region だけを新規とする。
    // 常駐 Alert (atoms/Alert.svelte) や自動消去しない error toast を証拠にしないための核。
    const presentNew = [];
    let preexisting = 0;
    for (const el of document.querySelectorAll(LIVE)) {
        if (!visible(el)) continue;
        const current = text(el);
        if (el.__bhBaseline === undefined || el.__bhBaseline !== current) presentNew.push(describe(el));
        else preexisting += 1;
        el.__bhBaseline = current;
    }

    return JSON.stringify({
        installed_now: installedNow,
        armed_at_ms: state.armedAt,
        seen: state.seen.splice(0),
        present_new: presentNew,
        present_preexisting: preexisting,
        pending: state.pending,
    });
})()
```

### 既知の残余 (設計として受容する)

| 残余 | 影響 | なぜ今は対処しないか |
|---|---|---|
| 属性トグル (`hidden` 付け外し) による表示切替で**テキストが変わらない**場合、`present_new` に出ない | 取りこぼし | AI-CUE の非単調 UI は 2 件とも `{#if}` / `{#each}` の mount/unmount (`ToastContainer.svelte:34-53` / `CodeSnippet.svelte:58-62`)。属性トグル方式の component は存在しない (思考原則 2) |
| 可視→不可視→可視 (同一テキスト) の往復は `present_preexisting` に落ちる | 取りこぼし | 同上。該当 component 無し |
| live region を持たない一過性フィードバックは記録されない | 取りこぼし | probe が空でも「視覚的フィードバックが無い」とは主張しない規約 (施策 2) で受ける |
| 進捗表示 (`Spinner.svelte:36-38` は `label` 指定時 `role="status"`) を捕捉しうる | 誤って「フィードバックあり」と数える恐れ | 判定は**本文を読んで**行う規約 (施策 2)。なお現状 `Spinner` の usage は 0 件 (`rg 'Spinner' resources/js` が定義ファイルのみ)、label 未指定時は `aria-hidden="true"` で足切りされる |

### テスト計画

施策 5 を参照 (jsdom で 17 項目)。

### リスク

- `playwright-cli --raw eval` の実挙動 (式評価の可否・出力整形) は**ライブ検証が必要**。
  ローカルには `@playwright/cli` 同梱 playwright-core 用のブラウザが未導入で、
  本設計フェーズでは実行できなかった (`playwright-cli open` が
  `Chromium distribution 'chrome' is not found` / `Browser "webkit" is not installed` で失敗)。
  → §検証計画の L0 として次回 run の受入条件に入れる。**「検証済み」と書かない。**

---

## 施策 2: 走行プロトコルへの組み込み (正本は SKILL.md)

### 変更箇所

- `.claude/skills/app-bug-hunt/SKILL.md`
  - `### 走行プロトコル (各ステップ共通)` (現 L232-248) に手順 2b を追加
  - その直後に新節 `### 一過性フィードバックの観測 (feedback probe)` を追加
  - `### 横断ヒューリスティクス (毎ステップ適用)` の表 (現 L252-263) の直後に H7 の前提条件を追記
  - `### finding 記録フォーマット` (現 L281-294) の証跡欄に probe 出力の書式を追記

### 正本の置き場所をどう決めたか (SKILL.md か bughunt-shard.md か)

**SKILL.md を正本とする。** 根拠 3 点:

1. `.claude/agents/bughunt-shard.md:72-74` が自ら
   「走行プロトコル・横断ヒューリスティクス (H1-H14)・finding フォーマット・逐次レポート書き出し規約は
   すべて SKILL.md / stories に従う (**本ファイルは差分のみ**)」と宣言している。
2. **直列走行 (shard 0) は親セッションが SKILL.md だけを読んで走る** (`SKILL.md` Phase 0b / Phase 2)。
   `bughunt-shard.md` は並列 fan-out の子だけが読むので、そちらに書くと直列 run に穴が残る。
   F-1-02 は shard-1 (並列) 由来だが、機序は直列でも同一。
3. 二重記載は思考原則 3 (後方互換の並走を残さない) に反する。`bughunt-shard.md` には
   **コマンド 1 行と参照のみ**を置く (施策 3)。

### 現行コード (SKILL.md 抜粋)

```markdown
### 走行プロトコル (各ステップ共通)

0. **見るだけで終わらせない。** 各画面で operations.md にその画面の操作が割り当てられていれば必ず実行する。
   操作は「実行 → 成功フィードバック確認 → 一覧/表示への反映確認 (H10)」の 3 点セットで 1 カウント。
   ...
2. 遷移を伴う操作の後は再 snapshot して testid / テキスト出現を確認する (Inertia+Svelte は描画が非同期)。
3. カードの「期待」と照合 + 下の**横断ヒューリスティクス**を毎ステップ通す。
```

```markdown
| H7 | destructive 操作に確認がない、または結果フィードバック (flash) がない | Medium |
```

```markdown
- 証跡: screenshots/F-xx.png, console: ..., network: ...
```

### 変更後コード (SKILL.md に入る文面)

**(a) 走行プロトコルに 2b を挿入** (手順 2 の直後):

```markdown
2b. **一過性フィードバック (toast 等) は事後 snapshot では観測できない。** 書き込み操作は
    **直前に記録器を仕込み直後に読む** (§一過性フィードバックの観測)。「事後 snapshot に無い」を
    根拠にフィードバック欠落を主張してはならない。
```

**(b) 新節 (走行プロトコルの直後に置く)**:

```markdown
### 一過性フィードバックの観測 (feedback probe)

**成功 toast は 4 秒で自動消滅する** (`resources/js/lib/stores/toast.ts` の `AUTO_DISMISS_MS`。
error だけは消えない)。「コピー完了」表示は 2 秒 (`resources/js/components/molecules/CodeSnippet.svelte`)。
driver の観測は「操作 → 事後 snapshot」の 1 点サンプリングで、Bash 1 往復ぶん (数百 ms〜数秒、
並列 shard ではさらに遅延) 後ろにずれる。したがって **事後 snapshot に無いことは「出なかった」の
証拠にならない** (run 20260803-203721 の F-1-02 が誤検知になった機序。spec-ledger.md 参照)。

そこで **操作の直前に記録器を仕込み、直後に読む**。記録器は ARIA live region
(`role="status"` / `role="alert"`) の出現・変化を記録するので、**消えた後でも読める**。

```bash
# 設置 (arm) と読み出しは同じ 1 コマンド (冪等)。--raw で JSON だけを受け取る
playwright-cli --raw eval "$(cat "$(git rev-parse --show-toplevel)/.claude/skills/app-bug-hunt/probes/feedback-probe.js")"
```

**呼ぶタイミング**:
- `open` / `goto` / `reload` / `go-back` / `go-forward` の**直後**に 1 回 (= arm)。
- **各書き込み操作の直後**に 1 回 (= 読み出し)。この呼び出しが次の操作の arm を兼ねるので、
  操作を続ける限り **1 操作 = probe 1 コール**で済む。

**判定 (これを守ること)**:

| 記録器の戻り値 | 解釈 | 行動 |
|---|---|---|
| `installed_now:false` かつ (`seen` の `visible:true` entry または `present_new`) に**操作結果を伝える文言**がある | 観測窓が連続し、ユーザーに見える変化を捕捉した | フィードバックあり → finding にしない |
| `installed_now:false` かつ どちらにも無い (`pending:0`) | 操作の全区間で記録器が生きていた = **本当に出なかった** | H7 finding 候補 |
| `installed_now:true` | 途中で document が置換され記録器が失われた (基線も無い) | **未検証**。finding にしない。`present_new` は常駐分を含むので参考情報に留める |
| `pending > 0` | 可視性判定が未解決 | probe をもう 1 回だけ叩き、**1 回目と 2 回目の `seen` / `present_new` の和集合**で判定する。2 回目も `pending > 0` なら**未検証** |

- **`visible:false` / `visible:"gone"` は証拠に数えない** (返るのは診断のため)。
- **件数ではなく本文で判定する**: `role="status"` は進捗表示にも使われうる
  (`resources/js/components/atoms/Spinner.svelte:36-38` は `label` 指定時に `role="status"`)。
  ローディング/進捗は「操作結果のフィードバック」ではない。
- **H7 の「結果フィードバックが無い」は `installed_now:false` の操作にのみ適用する。**
  `installed_now:true` / `pending>0` 継続の操作は shard-report の skip/未検証欄に
  **操作名つきで必ず列挙**する (無言の skip は禁止 = 走行プロトコル 7)。
  再実行は 1 回まで。**非冪等な破壊操作 (削除等) は再実行せず未検証のまま記録する。**
- probe が空でも「**視覚的**フィードバックが無い」とまでは言えない (live region を持たない
  一過性 UI は記録されない)。H14 (a11y) に格上げしてよいのは、snapshot / DOM 調査で
  **視覚的な一過性フィードバックの存在を別途確認でき、かつ live region が無い**と示せた場合だけ。
- **probe の結果を `findings.jsonl` の `symptom_tokens` に入れてはならない。**
  `ledger/validate_findings.py` の `has_new_signal()` は symptom_tokens の新語で
  adjudication を `ambiguous` に倒すため、probe 由来の語を混ぜると**既存 adjudication の
  downrank が無効化される**。probe 出力は report.md の証跡欄に書く。
```

**(c) 横断ヒューリスティクス表の直後に追記**:

```markdown
> **H7 の前提条件**: 「結果フィードバックが無い」の判定には **feedback probe の陰性所見**が必須
> (§一過性フィードバックの観測)。事後 snapshot に無いことを根拠に H7 を起票しない。
```

**(d) finding 記録フォーマットの証跡欄**:

```markdown
- 証跡: screenshots/F-xx.png, console: ..., network: ...,
  feedback-probe: `installed_now=false seen=0(visible:true) present_new=0 pending=0`
  (フィードバック欠落を主張する finding では**必須**)
```

### 波及変更

- TypeScript 型定義 / API Resource / DTO: なし
- テストファイル: なし (プロトコル文書。§検証計画で担保範囲を明示)
- `screens.md` / `operations.md` / `stories/` / `capability-catalog.md`: 変更なし

### リスク

- 走行コストが **1 書き込み操作あたり Bash 1 コール**増える。`operations.md` の対象は 71 件で
  4〜8 shard に分散するため 1 shard あたり十数コール。プロトコル手順 0 が既に
  「実行 → 成功フィードバック確認 → 反映確認」の 3 点セットを全操作に課しているので、
  probe はその「成功フィードバック確認」の実装手段であり**追加の検査項目ではない**。
- 「破壊的操作の前だけ」に絞る案は**却下**した。aigenba の 4 件のうち 2 件
  (チーム更新 / 権限トグル) は非破壊・同一画面の操作であり、絞ると取り逃す。

---

## 施策 3: shard worker のコマンド表に 1 行追加

### 変更箇所

- `.claude/agents/bughunt-shard.md` の「主要コマンド」ブロック (現 L49-59) と走行手順 1 (現 L72-74)

### 現行コード

```bash
playwright-cli console / requests                   # console error / network (4xx/5xx・外部ドメイン) 確認
```

### 変更後コード

```bash
playwright-cli console / requests                   # console error / network (4xx/5xx・外部ドメイン) 確認
playwright-cli --raw eval "$(cat "$(git rev-parse --show-toplevel)/.claude/skills/app-bug-hunt/probes/feedback-probe.js")"
                                                    # 一過性フィードバック記録器 (toast は 4 秒で消える)。
                                                    # 呼ぶタイミングと判定は SKILL.md §一過性フィードバックの観測 が正本
```

走行手順 1 の列挙に `feedback probe 規約` を 1 語追加する:

```markdown
1. スキル正本 `.claude/skills/app-bug-hunt/SKILL.md` と、割り当てられた `stories/S*.md` を読む。
   走行プロトコル・**feedback probe 規約**・横断ヒューリスティクス (H1-H14)・finding フォーマット・
   逐次レポート書き出し規約はすべて SKILL.md / stories に従う (本ファイルは差分のみ)。
```

### 波及変更 / リスク

- なし (規約本文を複製しないので二重管理は発生しない)。

---

## 施策 4: spec-ledger の書式拡張 + run 20260803-203721 申し送り

### 変更箇所

- `.claude/skills/app-bug-hunt/spec-ledger.md`
  - `## 書式ルール` (現 L37-44) の節見出し列挙に `### 誤検知確定 (再起票しない)` を追加
  - `## 初回登録テンプレート` (現 L47-69) の `判定` に `FALSE_POSITIVE` を追加、
    `- **driver 側の再発防止**:` 欄を追加
  - 「> **現状: 中身は空**」の注記を除去し、run 節を追加
  - **`### 使い方` (L22-35) は変更しない**

### なぜ書式を拡張するのか (最小限であることの説明)

- 現行の節見出しは `SPEC 確定` / `DOC 確定` / `実装で解消` / `CLOSED` の 4 種のみで、
  **機械 registry の verdict 語彙 `false_positive` を置ける節が無い**
  (`ledger/README.md` の必須フィールド `verdict`(false_positive|intentional|wont_fix))。
  A-001 は `false_positive` なので、節を足さないと F-1-02 を書けない。
  `wont_fix` 用の節は**今回は足さない** (該当項目が無い。思考原則 2)。
- `driver 側の再発防止` 欄は、**aigenba が同種誤検知を 4 回踏んだ根本原因**
  (申し送りが人手の心構えで終わり driver を直さない) を構造で塞ぐためのもの。
  埋められない場合は「なし (人手注意のみ)」と書かせ、機構化の余地を毎回問う。

### 変更後コード

**(a) 書式ルールの節見出し列挙**:

```markdown
- 節の中は `### SPEC 確定 (再起票しない)` / `### 誤検知確定 (再起票しない)` / `### DOC 確定`
  / `### 実装で解消 (旧 SPEC / accepted を撤回)` / `### CLOSED (非再発を確認)` に分ける。
  節見出しは機械 registry の `verdict` 語彙に対応させる
  (`誤検知確定` = `false_positive` / `SPEC 確定` = `intentional`)。
```

**(b) 初回登録テンプレートの差分** (2 行):

```markdown
- **判定**: SPEC (意図仕様) | DOC (ドキュメント側の陳腐化) | FALSE_POSITIVE (観測 artifact)
```

```markdown
- **driver 側の再発防止**: {この誤検知を機構で防ぐ手立て。SKILL.md のどの規約か / 「なし (人手注意のみ)」}
  ※ 人手の心構えで終わらせないための必須欄
```

**(c) 追加する run 節 (全文)**:

```markdown
## run 20260803-203721 申し送り (2026-08-04)

### 誤検知確定 (再起票しない)

#### F-1-02 — 動画マニュアル削除後に「成功 flash が出ない」ように見えた
- **判定**: FALSE_POSITIVE (観測 artifact)
- **根拠 (file:line)**: `app/Http/Controllers/Projects/VideoManualController.php:230-232`
  (削除後 `projects.show` へ redirect し `->with('success', '動画マニュアルを削除しました')`) /
  `resources/js/lib/stores/toast.ts:23-29` (success/info/warning は **4000ms で auto-dismiss**、
  error のみ `null` = 自動消去しない) /
  `resources/js/components/organisms/ToastContainer.svelte`
  (`role="status"` + `data-testid="toast-{type}"` で描画) /
  `tests/Browser/FlashToastTest.php` (着地マーカーと**同一時間窓**で `toast-success` が可視になることを
  Chromium / WebKit の 2 レーンで pin)
- **なぜ誤検知に見えたか**: bug-hunt driver の観測は「操作 → 事後 snapshot」の 1 点サンプリングで、
  Bash 1 往復ぶん (数百 ms〜数秒、並列 shard ではさらに遅延) 後ろにずれる。可視窓 4000ms の後に
  snapshot が来れば「flash 無し」に見える。T095 の実装フェーズで **現行コードのまま** Browser テストを
  両レーンで走らせて PASS したため、アプリ側は正しいと確定した。**アプリコードは変更していない。**
- **driver 側の再発防止**: `SKILL.md` §一過性フィードバックの観測 — 書き込み操作の**直前**に
  feedback probe (`probes/feedback-probe.js`) を仕込み、直後に読む。「事後 snapshot に無い」を
  根拠に H7 を起票することを禁止した。回帰は `tests/js/bughunt/feedback-probe.test.ts` が固定する。
- **watch_globs (機械 registry に載せる場合)**: `ledger/adjudications.jsonl` の A-001 に登録済。
  **本ファイルには重複させない** (正本は registry)。
- **review_after_days**: 180 (A-001 と同値)
- **確定した run_id**: 20260803-203721 (commit 22d6d30)
- **再オープン条件**: 次のいずれか。
  (a) `VideoManualController::destroy` が `->with('success', ...)` を落とした、
  (b) `toast.ts` の success 用 `AUTO_DISMISS_MS` が大幅に短縮された、
  (c) feedback probe が `installed_now:false` かつ `seen`(visible:true) / `present_new` ともに空を返した。
  **probe を使わない事後 snapshot 単独の観察は再オープン根拠にならない。**
- **機械 registry**: `ledger/adjudications.jsonl` の `A-001` に登録済 (verdict=false_positive)
```

### 何を書かないか (役割分担の遵守)

- **A-001 の照合条件 (`species_key` / `scope` / `conditions` / `symptom` / `watch_globs`) を
  spec-ledger に複製しない。** registry が機械照合の正本、spec-ledger は「なぜそう確定したか」と
  申し送りの正本 (spec-ledger.md:6-14 が定める分担)。
- **aigenba の 4 件を写さない。** spec-ledger.md:16-18 が「他アプリの申し送りを写さない」と明記している。
  aigenba の事例は**本設計の根拠**として devnotes に置き、そこから導いた**一般規約**だけを
  SKILL.md に書く (施策 2)。
- run 20260803-203721 から台帳に載せるのは **F-1-02 のみ**。他の finding は実バグ (F-3-05 等) か
  「要確認」(F-4-02 / Q-2-01 等) であり、**三点裏取り (設計文書・実コード・テスト) が済んでいない**
  ものは載せない (spec-ledger.md:30-31)。
- `ledger/adjudications.jsonl` は**触らない** (A-001 は親が登録済み。append-only の既存行は編集しない)。

### 波及変更

- `ledger/adjudications.jsonl`: 変更なし (相互参照のみ)
- テストファイル: 施策 6 が本ファイルの構造・根拠パス実在・registry 相互参照を固定する

### リスク

- 台帳が育つと記述が腐る。→ 施策 6 のテストが「根拠ファイルの実在」と「`A-NNN` の実在」を機械検知する
  (**行番号は検証しない** — 通常のリファクタで台帳テストが壊れる保守負債を避けるため)。

---

## 施策 5: probe 契約の回帰テスト (jsdom)

### 変更箇所

- 新規: `tests/js/bughunt/feedback-probe.test.ts`

### 設計

- probe は **モジュールではなく式**なので `import` せず、`readFileSync` で**テキストとして読み**、
  `window.eval(src)` で評価する (実 driver と同じ渡し方)。
- パスは `resolve(process.cwd(), ".claude/skills/app-bug-hunt/probes/feedback-probe.js")`。
  `scripts/run-vitest.sh` が `cd "$WORKSPACE"` してから vitest を起動するので cwd はリポジトリルート。
- jsdom は layout を持たず `getClientRects()` が常に空配列を返すため、
  **`Element.prototype.getClientRects` を stub** して
  「接続済み かつ `hidden` でない かつ `display:none` / `visibility:hidden` でない → 矩形あり」とする。
  **probe 本体にテスト用フックは入れない** (stub するのは DOM API 側)。
- rAF の解決を待つのは実時間の `await new Promise(r => setTimeout(r, 150))`。
  30ms では jsdom の rAF (~16ms タイマ) と競合して flaky になることを実測で確認済み
  (150ms で 6 連続 17/17 PASS)。
- **skip 条件を作らない**。probe ファイルが無ければ**落ちる**
  (silent skip による false green を避ける。bug-hunt スキルごと撤去する場合は本テストも一緒に消す旨を
  ファイル冒頭コメントに書く)。

### テスト計画 (17 項目。すべて設計フェーズで参照実装に対し実測 PASS 済み)

| # | 検証 | 期待 |
|---|---|---|
| A1 | 初回 probe = arm | `installed_now === true` |
| A2 | arm 時は基線が無い | 可視 live region が全て `present_new` |
| B1 | 常駐 live region (Alert 相当 / 残存 error toast 相当) | 2 回目以降 `present_new` に出ず `present_preexisting` に落ちる |
| B2 | 無操作時 | `seen` 空 / `installed_now === false` |
| C1 | **auto-dismiss 後に読む (F-1-02 の機序)** | `seen` に `visible:true` で残る |
| C2 | 証拠の内容 | `testid === "toast-success"` / 本文一致 |
| C3 | 常駐分は混ざらない | `present_preexisting` に留まる |
| C4 | 可視性判定の解決 | `pending === 0` |
| D1 | drain | 直後の probe で `seen` が空 (二重計上しない) |
| E1 | `display:none` の live region | `seen` に **`visible:false`** で入る (診断情報。証拠には数えない) |
| F1 | 祖先 `aria-hidden="true"` | `seen` に**入らない** (記録時足切り) |
| G1 | サブフレーム点滅 (追加直後に削除) | `seen` に `visible:"gone"` |
| H1 | 非 live-region の DOM 変化 | `seen` / `present_new` ともに空 |
| I1 | 既存 live region の in-place テキスト更新 (characterData) | `seen` に `visible:true` |
| I2 | 同上 | `present_new` にも出る |
| J1 | hidden→visible (属性は監視しないが基線差分で拾う) | `present_new` に出る |
| K1 | 記録器の喪失 (cross-document 相当) | `installed_now === true` |

### 波及変更

- TypeScript 型定義: なし (`window.eval` の戻りは `JSON.parse` して構造検証)
- `vitest.config.ts`: 変更不要 (`include: ["tests/js/**/*.test.ts"]` に含まれる)
- `tsconfig.json`: 変更不要 (`tests/js/**/*.ts` を include 済み)

### リスク

- jsdom の rAF 挙動差。→ probe は rAF 不在時 `setTimeout(...,0)` に fallback するので
  どちらの環境でも動く。待ち時間は 150ms で余裕を持たせる。

---

## 施策 6: spec-ledger の腐り検知テスト (stdlib)

### 変更箇所

- 新規: `.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py`

### なぜ `ledger/` に置くか

`spec-ledger.md` は自身を `ledger/adjudications.jsonl` の**対**と定義しており (spec-ledger.md:6-14)、
本テストの中心は**両者の相互参照が切れていないか**である。`ledger/` に置けば
`python3 -m unittest discover -s ledger -p 'test_*.py'` (ledger/README.md の手順) で一緒に走る。
参照方向は `coverage/test_naming_no_stale.py` と同じ (`SKILL_ROOT = Path(__file__).parent.parent`)。

### 検証する不変条件 (3 つだけ)

1. **必須欄の欠落なし**: `#### {finding_id} — …` エントリは
   `判定` / `根拠 (file:line)` / `driver 側の再発防止` / `確定した run_id` / `再オープン条件` /
   `機械 registry` の 6 欄をすべて持つ (テンプレートの「欄を削らない」を機械化)。
2. **根拠パスの実在**: 根拠欄のバッククォート内トークンのうち、拡張子
   (`.php/.ts/.js/.svelte/.md/.json/.yaml/.yml/.py/.sh`) を持ち `:` 以降を除いたものが
   **リポジトリに実在する**。**行番号は検証しない**
   (旧 registry 18 件が実在しないパスを指し invalidation が永久に発火しなかった事故
   = `ledger/README.md` 運用ガード (d) の再発防止)。
3. **registry 相互参照**: `機械 registry` 欄に `A-NNN` を「登録済」と書いたら、
   その `adjudication_id` が `adjudications.jsonl` に**実在する**
   (aigenba の弱点 = 人間台帳にしかなく registry に無いために自動 downrank されない、の再発防止)。

台帳が空 (エントリ 0 件) のときはすべて vacuous に PASS する (テンプレート初期状態を壊さない)。

### 実装スケッチ

```python
"""spec-ledger.md の腐り検知 (stdlib のみ)。

検証するのは 3 点だけ:
 (1) 確定項目の必須欄が揃っているか (テンプレートの「欄を削らない」の機械化)
 (2) 根拠に書いたファイルが実在するか (**行番号は見ない**)
 (3) 「機械 registry に登録済」と書いた A-NNN が adjudications.jsonl に実在するか
"""

from __future__ import annotations

import json
import re
import unittest
from pathlib import Path

LEDGER_DIR = Path(__file__).resolve().parent
SKILL_ROOT = LEDGER_DIR.parent
REPO_ROOT = SKILL_ROOT.parents[2]          # .claude/skills/app-bug-hunt -> repo root
SPEC_LEDGER = SKILL_ROOT / "spec-ledger.md"
ADJUDICATIONS = LEDGER_DIR / "adjudications.jsonl"

ENTRY_RE = re.compile(r"^#### (?P<fid>\S+) — (?P<title>.+)$")
REQUIRED_FIELDS = ("判定", "根拠 (file:line)", "driver 側の再発防止",
                   "確定した run_id", "再オープン条件", "機械 registry")
PATH_RE = re.compile(r"`([^`]+)`")
PATH_LIKE = re.compile(r"^[\w./-]+\.(php|ts|js|svelte|md|json|ya?ml|py|sh)(:[\d-]+)?$")
ADJ_ID_RE = re.compile(r"\bA-\d{3}\b")

def _entries() -> list[tuple[str, str]]:
    """(finding_id, 本文) のリスト。テンプレート節 (```markdown ブロック) は除外する。"""
    ...

class SpecLedgerTest(unittest.TestCase):
    def test_required_fields_present(self) -> None: ...
    def test_evidence_paths_exist(self) -> None: ...
    def test_registry_cross_reference_resolves(self) -> None: ...
```

> **実装上の注意**: テンプレート節 (`## 初回登録テンプレート` 配下の ```markdown フェンス) は
> 実エントリではないので**除外**すること (プレースホルダ `path/to/File.php` が (2) で fail するため)。
> 除外方法は「フェンス (```) の内側を読み飛ばす」で足りる。

### テスト計画 (思考原則 5: 先に red を見る)

1. `test_spec_ledger.py` を先に置く → **fail する** (spec-ledger.md にエントリが無い状態では
   (1)(2)(3) は vacuous PASS なので、まず **F-1-02 の欄を 1 つ欠いた状態**を手元で作って
   (1) が赤くなることを確認してから正しい本文を書く)。
2. 施策 4 の本文を書く → 3 テストとも green。
3. `python3 -m unittest discover -s .claude/skills/app-bug-hunt/ledger -p 'test_*.py'` と
   `python3 .claude/skills/app-bug-hunt/ledger/validate_findings.py
    .claude/skills/app-bug-hunt/ledger/example.findings.jsonl
    --adjudications .claude/skills/app-bug-hunt/ledger/adjudications.jsonl` を実行し、
   registry が invalid になっていないことを確認する (fail-closed の全面停止を避ける)。

### リスク

- 台帳の記法が揺れるとパースが壊れる。→ 必須欄は**テンプレートと同じ文字列**で照合し、
  テンプレート自体も同じ文字列を使う (テンプレートを直したらテストも直す、という 1 対 1 の関係にする)。

---

## 検証計画 (何が担保でき、何ができないか)

| 手段 | 担保できること | 担保できないこと |
|---|---|---|
| `pnpm test` (施策 5) | probe の契約 17 項目 (arm / 窓喪失検知 / auto-dismiss 後の捕捉 / 基線による常駐除外 / 可視性 3 値 / drain / ノイズ無視) | 実 Chromium・実 Inertia 遷移での挙動、`playwright-cli` の引数解釈 |
| `python3 -m unittest` (施策 6 + 既存 `coverage/` `ledger/`) | spec-ledger の必須欄・根拠ファイル実在・registry 相互参照。`validate_findings.py` による registry の妥当性 (fail-closed 回避)。`test_naming_no_stale.py` による旧語彙の非混入 | プロトコルが実 run で守られるか |
| `scripts/bug-hunt-shard.sh self-test` | **本施策は同スクリプトを変更しない**ため、「何も壊していない」ことの回帰確認のみ (guard / 資源導出 / env 隔離 / asset 鮮度) | probe に関する一切 |
| **次回 bug-hunt run (ライブ)** | 下記 L0/L1/L2 | — |

**bug-hunt 環境の provision は実装者から実行できない** (`scripts/bug-hunt-shard.sh` の
`require_orchestrator` により `BUGHUNT_ORCHESTRATOR=1` を持つ親のみ = default-deny)。
したがって probe の**実ブラウザ検証は次回 bug-hunt run に委ねる**。**黙って「検証済み」と書かない。**

### 次回 run の受入条件

| # | 検証 | 合格条件 |
|---|---|---|
| L0 | `playwright-cli --raw eval "$(cat …/feedback-probe.js)"` が実行できる | JSON が 1 行で返り、`installed_now` / `seen` / `present_new` / `present_preexisting` / `pending` の 5 キーを持つ。エラー時は probe 方式そのものを見直す |
| L1 | F-1-02 と同型の SPA 削除導線 (`projects.manuals.destroy` → `projects.show`) | post-op probe が `installed_now:false` かつ `seen` に **`visible:true`** の entry (`role=status` / testid `toast-success` / 本文「動画マニュアルを削除しました」) を 1 件以上含む |
| L2 | `CodeSnippet` のコピー導線 (2 秒窓。`organizations.onboarding.cli` / `.mcp` は screens.md に実在) | post-op probe が `installed_now:false` かつ `seen` に **`visible:true`** の entry (`role=status` の「コピー完了」または「コピー失敗」) を 1 件以上含む |
| L3 | run 全体 | 非単調 UI に起因する「フィードバック欠落」finding が 0 件 |

- `visible:false` / `"gone"` は L1/L2 の合格条件を満たさない。
- `pending>0` で 2 回目の probe を要した場合は**両応答を統合して**評価する。
- **L0/L1/L2 のいずれかが不合格なら方式不成立**として設計を見直す (値の微調整に逃げない)。
- 結果は次回 run の spec-ledger 節に追記する (合格なら「driver 側の再発防止が機能した」の記録、
  不合格なら再設計 TODO)。

## テンプレートとの関係

`docs/template-divergence.md` への記録は**不要と判断した**。根拠:

1. 同レジストリの既存エントリ D1〜D9 はすべて**アプリのドメイン構造**の逸脱で、判定軸は
   「同じ不変条件を同じタイミング/抽象度で保証するか」。本件は不変条件の削除・置換ではなく
   **driver 契約の追加**である。
2. 先例: `.claude/skills/app-bug-hunt/SKILL.md` は T036 (commit `a9074f0`) で既に AI-CUE 都合の
   追記 (real-llm 既定化) を受けており、divergence 記録は行われていない。
3. probe 機構はアプリ非依存 (ARIA live region のみに依存) で、**逸脱ではなく上流テンプレートへの
   還流候補**である。テンプレート側に同等機構が入ったら本実装を差し替える。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 変更対象が `.claude/skills/app-bug-hunt/` + `.claude/agents/` + `tests/js/bughunt/` に閉じており、アプリコード (`app/` `resources/` `routes/`) と 1 行も重ならない。他 TODO の worktree と競合しない |
| 競合リスク | 低。ただし**次回 bug-hunt run より前にマージする**必要がある (run 中に SKILL.md が変わると走行中のプロトコルと食い違う)。`ledger/adjudications.jsonl` は触らないので A-001 追記との競合も無い |


---

## 関連する現行コード

### .claude/skills/app-bug-hunt/SKILL.md (L228-300) 走行プロトコル / ヒューリスティクス / finding フォーマット
```
228: ## Phase 2: ストーリー実走 (本体)
229: 
230: 対象ストーリーカード (`stories/S*.md`) を 1 枚ずつ読み、`@playwright/cli` (Bash で `playwright-cli <cmd>`) で実行する。
231: 
232: ### 走行プロトコル (各ステップ共通)
233: 
234: 0. **見るだけで終わらせない。** 各画面で operations.md にその画面の操作が割り当てられていれば必ず実行する。
235:    操作は「実行 → 成功フィードバック確認 → 一覧/表示への反映確認 (H10)」の 3 点セットで 1 カウント。
236:    主要フォームは正常系の前に**空入力 or 不正値を 1 回**送ってバリデーション表示も確認する。
237:    対応するボタン/フォームが UI に見つからない operation は、それ自体を finding 候補として記録する。
238: 1. `playwright-cli snapshot` で現在地と要素 ref を確認 → カードの「操作」を実行。
239: 2. 遷移を伴う操作の後は再 snapshot して testid / テキスト出現を確認する (Inertia+Svelte は描画が非同期)。
240: 3. カードの「期待」と照合 + 下の**横断ヒューリスティクス**を毎ステップ通す。
241: 4. `playwright-cli console`(error) と `playwright-cli requests`(4xx/5xx、外部ドメイン) を確認。
242: 5. 異常を見たら: `playwright-cli screenshot` で証跡保存 → finding 記録 → **finding は停止信号ではない**。
243:    当該ストーリーの screen / operation / ヒューリスティクスを**完走するまで検証は終わらない**。
244:    - 詰み (blocker) でも reseed / 再ログイン / 別導線 / 直 URL で "詰みの先" を取りに行く。本当に到達不能な分だけ skip (理由必須)。
245: 6. **finding を 1 件で満足しない — 特に Critical/High を見つけたエリアは深掘りする** (variant 入力・隣接値・他ロール・二重送信・戻る/リロード)。
246: 7. skip した場合は必ず skip として記録する。無言の skip は禁止。
247: 8. **走行中に突然の 401/ログイン失敗が出たら、アプリバグと断定する前に DB 生存確認**
248:    (`tmp/bug-hunt/shard-0-cmd.sh db-check`)。空なら `reseed` してやり直し、環境ハザードとして記録。
249: 
250: ### 横断ヒューリスティクス (毎ステップ適用)
251: 
252: | # | 兆候 | 既定 severity |
253: |---|---|---|
254: | H1 | 説明なしリダイレクト (操作の結果どこかに飛ばされ、画面に理由がない) | High |
255: | H2 | 詰み (進む導線も戻る導線もない / 同じエラーをループする) | Critical |
256: | H3 | 無反応 (クリックして何も起きない、ローディングが終わらない >10s) | High |
257: | H4 | 生エラー (500 / スタックトレース / 英語例外文 / `[unknown]` / 未翻訳キー / 白画面) | High |
258: | H5 | console error / 4xx・5xx network (画面上は正常に見えても) | Medium |
259: | H6 | 二重送信が可能 (連打・リロード・戻る、で副作用が 2 回) | 課金系 Critical / 他 Medium |
260: | H7 | destructive 操作に確認がない、または結果フィードバック (flash) がない | Medium |
261: | H8 | 空状態 (0 件) で説明・次アクションがない | Low |
262: | H9 | 権限外データの表示・操作 (IDOR 含む。他組織/他プロジェクトのリソース) | Critical |
263: | H10 | 文言・件数・状態が直前の操作結果と矛盾 (例: 作成したのに一覧に出ない) | High |
264: 
265: **UI/UX ヒューリスティクス (H11-H14、視覚/操作品質。snapshot + screenshot で観察)**
266: 
267: | # | 兆候 | 既定 severity |
268: |---|---|---|
269: | H11 | **視覚破綻**: レイアウト崩れ・要素の重なり・overflow / 横スクロール・テキスト切れ/はみ出し・視覚階層の破綻 | 操作阻害あり=High / 見た目のみ=Medium〜Low |
270: | H12 | **アフォーダンス/状態表現**: 押せる/押せないが見分けられない・disabled/loading/selected の状態が判別不能・primary と副操作の階層が不明 | Medium |
271: | H13 | **レスポンシブ/モバイル**: 狭幅 viewport (mobile 375 / tablet 768) で横スクロール・要素はみ出し/重なり・操作要素が画面外/タップ不能・nav 到達不能 | 操作不能=High / 見た目崩れ=Medium |
272: | H14 | **アクセシビリティ基礎**: コントラスト不足・focus リング不可視/キーボード到達不能・interactive 要素に aria/label/name 欠落・画像/アイコンボタンに alt/aria-label 欠落・見出し階層の崩れ | Medium〜Low |
273: 
274: **適用方法**:
275: - **H11 / H12 / H14 は毎ステップ**、snapshot (role/name/state が取れるか) と必要に応じ screenshot で観察。
276: - **H13 (レスポンシブ) は各ストーリーで最低 1 回、代表的な主要画面 2〜3 枚**で `playwright-cli resize` を
277:   **mobile 375×667 と tablet 768×1024** に変えて確認する。確認後 **desktop に戻す**。
278: - UI/UX finding も通常フォーマットで記録し、**証跡 screenshot を必ず残す**。viewport を変えた場合は寸法を明記。
279: - 純粋な好み (色味・余白の美的判断) は finding にしない。**「観察可能な破綻・判別不能・到達不能」**に限定する。
280: 
281: ### finding 記録フォーマット
282: 
283: ```markdown
284: ## F-{連番}: {一行サマリ}
285: - severity: Critical / High / Medium / Low / 要確認
286: - story/step: S3-7
287: - 再現手順: (URL とアカウントから書く。誰でも再現できる粒度)
288: - 期待: / 実際:
289: - 阻害されたユーザージョブ: (このバグでユーザーが達成できなくなった目的。使命接続の必須欄)
290: - 改善アクション候補: (どう直せばユーザーが目的を達成できるか)
291: - 証跡: screenshots/F-xx.png, console: ..., network: ...
292: - 推定原因: (code-review-graph で当たりを付ける。5 分で見つからなければ「未調査」)
293: - 関連既知情報: (devnotes/TODO.md に同種の記録があれば参照。regression かどうかが重要)
294: ```
295: 
296: `findings.jsonl` の分類スキーマは `ledger/findings.schema.json` を参照 (report.md は人間向け本文の正本、
297: findings.jsonl は分類の正本。同じ説明文を両方に書かない)。
298: 
299: ### 状態管理
300: 
```
### .claude/skills/app-bug-hunt/SKILL.md (L310-336) Phase 4 レポート
```
310: ## Phase 4: レポート + クロージング
311: 
312: > **shard-report.md は逐次書き出しする (絶対遵守)**。走行の最初に骨子 (ヘッダ + 空の findings 節 + 空の
313: > カバレッジ節) を作成し、finding を 1 件見つけるたびに即追記、ストーリー/画面を消化するたびにカバレッジ行を
314: > 更新する。**最後にまとめて書く方式は禁止** (budget 超過で結果を全損するため)。
315: 
316: `devnotes/{run-id}-bug-hunt/shard-0/shard-report.md` に集約する (逐次更新):
317: 
318: ```markdown
319: # bug-hunt report {日時}
320: - 実行ストーリー: / skip したステップ:
321: - 画面カバレッジ: 走行 n / screens.md 総画面 m (未走行を列挙)
322: - 操作カバレッジ: 実行 n / operations.md 対象 m (未実行を列挙、skip は理由必須)
323: - UI/UX 検証: 視覚破綻(H11) / アフォーダンス・状態(H12) / レスポンシブ(H13: resize した画面と viewport) / a11y 基礎(H14) の所見
324: - findings: Critical x / High y / Medium z / Low w / 要確認 v (UI/UX = H11-H14 由来は H 番号を併記)
325: (以下 finding 詳細を severity 降順で)
326: ```
327: 
328: - **カバレッジ完了条件 (finding と独立)**: あるストーリーを「走行済み」と数えるのは、その screen + operation
329:   リストを**完走したとき**だけ。finding 件数で分母を縮めない。未走行を report に列挙する。
330: - **Critical/High は TODO 候補として要約を最後に出力する** (app-design → app-todo-add に渡せる粒度:
331:   一行サマリ + 再現手順参照 + 阻害されたユーザージョブ + 改善アクション候補 + 関連ファイル)。
332: - 「要確認」は仕様確認の質問リストとしてまとめ、バグと混ぜない。既知の問題は「既知」と明記し重複登録を防ぐ。
333: - 最後に `playwright-cli close` でブラウザを閉じ、直列走行なら
334:   `BUGHUNT_ORCHESTRATOR=1 scripts/bug-hunt-shard.sh teardown --run-id {ts}` で serve を停止する。
335: - **走行の最後に、生成したレポートのファイルパスを必ず出力する**。レポート未生成で終わるのは禁止。
336: 
```
### .claude/agents/bughunt-shard.md (L37-96) shard worker
```
37: ## ブラウザ操作 = @playwright/cli (Bash)
38: 
39: **全コマンドの先頭で環境変数を固定**する (egress guard = 自シャードと loopback 以外に出ない):
40: 
41: ```bash
42: export PLAYWRIGHT_MCP_ALLOWED_ORIGINS="http://127.0.0.1:801{i};http://localhost:801{i}"
43: export PLAYWRIGHT_CLI_SESSION="bughunt{i}"   # 以降 -s 省略可
44: ```
45: 
46: > 証跡 (`.playwright-cli/`) が shard 間で混ざらないよう、**自分の report dir に cd してから** playwright-cli を叩く。
47: 
48: 主要コマンド:
49: ```bash
50: playwright-cli open http://127.0.0.1:801{i}/login   # ブラウザ起動 + 遷移
51: playwright-cli snapshot                             # 画面構造 (ref付きアクセシビリティツリー) を読む
52: playwright-cli click  <ref>                         # snapshot で得た ref をクリック
53: playwright-cli type   "<text>" / fill <ref> "<text>" / press Enter
54: playwright-cli goto <url> / go-back / reload
55: playwright-cli console / requests                   # console error / network (4xx/5xx・外部ドメイン) 確認
56: playwright-cli resize <w> <h>                        # レスポンシブ確認 (mobile 375 667 / tablet 768 1024)
57: playwright-cli screenshot shot.png                   # 証跡。異常時に必ず残す
58: playwright-cli close                                 # 走行終了時に自セッションを閉じる
59: ```
60: 
61: **操作ループ**: `snapshot` で現在地と ref を読む → 操作を実行 → 再 `snapshot` で結果確認 →
62: 横断ヒューリスティクス (H1-H14) を当てる。`console`/`requests` で error / 4xx・5xx / 外部ドメインを毎ステップ確認。
63: 
64: ## なぜ自分のセッションだけを使うか (取り違え厳禁)
65: 
66: `-s=bughunt{i}` は shard ごとに**別の隔離ブラウザ (別 cookie jar)**。これにより IDOR (S7) が正しく検査できる。
67: **他 shard のセッション名 (`-s=bughunt{j}`) を絶対に使わない**。認可テスト (S7) は自分のセッション内でユーザーを
68: 順に切り替えて行う (A でログイン→確認→B でログイン→A の URL 直叩きが弾かれるか)。
69: 
70: ## 走行手順
71: 
72: 1. スキル正本 `.claude/skills/app-bug-hunt/SKILL.md` と、割り当てられた `stories/S*.md` を読む。
73:    走行プロトコル・横断ヒューリスティクス (H1-H14)・finding フォーマット・逐次レポート書き出し規約は
74:    すべて SKILL.md / stories に従う (本ファイルは差分のみ)。
75: 2. **Phase 1 (インベントリ鮮度確認) はスキップ** — 親が 1 回だけ行う。screens.md / operations.md / stories は
76:    **読み取りのみ** (気づきは自 report の「インベントリ修正提案」節に書く)。
77: 3. 開始時に `tmp/bug-hunt/shard-{i}-cmd.sh db-check` で DB 名と User::count() を確認してから走行。
78:    メール署名 URL は `tmp/bug-hunt/shard-{i}-cmd.sh mail-urls` で取得。ストーリー間の re-seed は
79:    `tmp/bug-hunt/shard-{i}-cmd.sh reseed` (S7 は S3 後の状態を意図的に使う)。
80: 4. `@playwright/cli -s=bughunt{i}` で対象 URL を実走。画面を見るだけでなく operations を実際に操作する。
81: 5. **レポートは `shard-{i}/shard-report.md` に書く** (ファイル名は `report.md` ではなく必ず `shard-report.md`)。
82:    走行開始時に骨子を作り、finding を見つけ次第 逐次追記する (最後にまとめて書かない)。証跡 screenshot は
83:    `shard-{i}/screenshots/` に残す。
84:    > **重要**: subagent は **`report.md` という名前のファイルだけ** harness のガードで Write 拒否される。
85:    > `shard-report.md` 等 `report.md` 以外の名前なら書ける。run 単位の統合 `report.md` は親が書く。
86:    > **万一 Write が拒否されたら**最終メッセージに finding 全文 (完全な再現手順込み) を必ず返す。
87: 6. 走行終了時に `playwright-cli close` で自セッションを閉じる。
88: 
89: ## 禁止事項 (SKILL.md「禁止事項」に従う。特に本ワーカーで重要なもの)
90: 
91: - **自シャード以外への接続禁止**: 対象 URL は `127.0.0.1:801{i}` のみ。dev (:8000 系)・他 shard ポート・
92:   外部ドメインへの遷移を試みた形跡があれば finding でなく**環境ハザードとして即中断・報告**。
93: - **バグを修正しない**: コードや正本の Edit/Write 禁止。書けるのは自 report dir のみ。
94: - **DB 直接書き換え禁止**: `tmp/bug-hunt/shard-{i}-cmd.sh` 経由のみ。生 psql/artisan/tinker/dropdb 禁止。
95: - **serve の停止・teardown・再 provision・worktree 操作はしない**: すべて親 (orchestrator) の責務。
96:   環境が壊れても復旧を試みず、環境ハザードとして報告して終了する。
```
### .claude/skills/app-bug-hunt/spec-ledger.md (L1-69) spec-ledger 全文
```
1: # bug-hunt 仕様台帳 (spec-ledger) — 既知仕様 / 誤検知の申し送り
2: 
3: このファイルは、過去の bug-hunt run で挙がった finding のうち **実コード裏取り + 敵対的検証の結果
4: 「仕様 (SPEC)」または「ドキュメント側対応 (DOC)」と確定したもの**を記録する、人間可読の申し送り台帳。
5: 
6: 機械 registry (`ledger/adjudications.jsonl`) の**対**である:
7: 
8: | | 正本 | 読み手 | 効果 |
9: |---|---|---|---|
10: | `ledger/adjudications.jsonl` | cross-session の**機械判定** | validator (`--annotate`) | 4-gate 一致で annotate + downrank |
11: | `spec-ledger.md` (本ファイル) | cross-session の**人間向け申し送り** | bug-hunt 実行者 (親 / 子 shard) | 「再起票しない」判断の根拠を渡す |
12: 
13: 同じ説明文を両方に重複させない。機械照合が要るものは registry に、
14: 「なぜ SPEC と確定したか」の物語は本ファイルに書く。
15: 
16: > **現状: 中身は空**。AI-CUE の実 run から書き起こす。
17: > 旧 registry の spirux 由来 18 件は AI-CUE に実在しない資産を指していたため削除済み
18: > (理由は `ledger/README.md` 運用ガード (d))。**他アプリの申し送りを写さない**。
19: 
20: ---
21: 
22: ## 使い方 (bug-hunt 実行者へ)
23: 
24: - finding を起票する前に本台帳を検索すること。**ここに SPEC として載っている事象は再起票しない**
25:   (「既知仕様」と一行記録して次へ)。
26: - 同一事象が再発したと感じたら、台帳の**根拠 (file:line)** を実コードで確認する。
27:   コードが台帳と乖離していれば **regression** の可能性があるので、その差分を根拠に新規 finding を起票してよい。
28: - DOC 項目は「コード正本は正しく、bug-hunt 側カード / 正本ドキュメントの記述が陳腐化していた」もの。
29:   該当カードが修正済みかを確認する。
30: - 「要確認」を SPEC に確定する判断は、**設計文書 (devnotes/docs)・実コード・テストの三点**で
31:   裏が取れた場合のみ。取れないものは台帳に載せず「要確認」のまま残す。
32: - **SPEC / DOC 確定項目には根拠 (file:line) を必ず併記する**こと。後続実装で仕様が変わった場合、
33:   記述と実コードが乖離するため、台帳の腐りを早期に発見できる。
34: - 機械照合させたい (次 run で自動 downrank したい) 項目は、本ファイルに書いたうえで
35:   `ledger/adjudications.jsonl` にも 1 行足す。手順は `ledger/README.md` 運用ガード (c)。
36: 
37: ## 書式ルール
38: 
39: - **append-only + supersede**。既存の確定項目を黙って書き換えない。撤回するときは
40:   「実装で解消 (旧 SPEC を撤回)」節を作り、**撤回した事実と根拠**を残す。
41: - run 単位の節 (`## run {run_id} 申し送り ({date})`) を**新しい run が上**になるよう積む。
42: - 節の中は `### SPEC 確定 (再起票しない)` / `### DOC 確定` / `### 実装で解消 (旧 SPEC / accepted を撤回)`
43:   / `### CLOSED (非再発を確認)` に分ける。
44: 
45: ---
46: 
47: ## 初回登録テンプレート
48: 
49: 新しい run の申し送りを書くときは、以下をコピーして埋める。**欄を削らない**
50: (埋められない欄がある = 三点裏取りが済んでいない ので、その項目は台帳に載せない)。
51: 
52: ```markdown
53: ## run {run_id} 申し送り ({YYYY-MM-DD})
54: 
55: ### SPEC 確定 (再起票しない)
56: 
57: #### {finding_id} — {事象を 1 行で。何が「バグに見えた」か}
58: - **判定**: SPEC (意図仕様) | DOC (ドキュメント側の陳腐化)
59: - **根拠 (file:line)**: `path/to/File.php:123` (何をしているか) /
60:   `resources/js/pages/Foo/Bar.svelte:45` / `AGENTS.md#anchor` / `tests/Feature/FooTest.php`
61:   ※ 設計文書・実コード・テストの三点。**実在するパスのみ**書く
62: - **なぜ誤検知に見えたか**: {fake mode / 観測窓 / viewport 等、bug-hunt 側の事情}
63: - **watch_globs (機械 registry に載せる場合)**: `path/to/File.php`, `resources/js/pages/Foo/Bar.svelte`
64:   ※ この判定を無効化しうる実在ファイルのみ。過広 (`app/**` 等) 禁止
65: - **review_after_days**: {int > 0。仕様の揺れやすさで決める。例 120 / 180}
66: - **確定した run_id**: {run_id} (commit {short_sha})
67: - **再オープン条件**: {どうなったら再び finding として起票してよいか}
68: - **機械 registry**: `ledger/adjudications.jsonl` の `A-NNN` に登録済 / 未登録 (理由: …)
69: ```
```
### resources/js/lib/stores/toast.ts (L20-55) toast auto-dismiss
```
20: }
21: 
22: /** type 別の自動消去時間 (ms)。null は自動消去しない */
23: const AUTO_DISMISS_MS: Record<ToastType, number | null> = {
24:     success: 4000,
25:     info: 4000,
26:     warning: 4000,
27:     error: null,
28: };
29: 
30: const store = writable<Toast[]>([]);
31: 
32: let nextId = 1;
33: 
34: // 自動消去タイマーは id → handle で管理し、手動 dismiss 時にも確実に解除する (リーク防止)
35: const timers = new Map<number, ReturnType<typeof setTimeout>>();
36: 
37: /**
38:  * toast を追加する。success / info / warning は 4 秒後に自動消去される。
39:  * @returns 追加した toast の id (空メッセージは追加せず -1 を返す)
40:  */
41: export function addToast(type: ToastType, message: string): number {
42:     const trimmed = message.trim();
43:     if (!trimmed) return -1; // 空メッセージは積まない
44:     const id = nextId++;
45:     store.update((items) => [...items, { id, type, message: trimmed }]);
46:     const ttl = AUTO_DISMISS_MS[type];
47:     if (ttl !== null) {
48:         timers.set(
49:             id,
50:             setTimeout(() => dismissToast(id), ttl),
51:         );
52:     }
53:     return id;
54: }
55: 
```
### resources/js/components/organisms/ToastContainer.svelte (L33-50) toast の role/testid
```
33:     {#each $toasts as toast (toast.id)}
34:         {@const TypeIcon = TYPE_ICONS[toast.type]}
35:         <!-- error は即時性が要るため role=alert (aria-live: assertive 相当)、他は role=status -->
36:         <div
37:             role={toast.type === "error" ? "alert" : "status"}
38:             class="pointer-events-auto flex items-center gap-2 rounded-md border bg-surface px-4 py-2 text-body text-text {TYPE_CLASSES[toast.type].border}"
39:             data-testid="toast-{toast.type}"
40:         >
41:             <TypeIcon class="size-4 shrink-0 {TYPE_CLASSES[toast.type].icon}" aria-hidden="true" />
42:             <span>{toast.message}</span>
43:             <button
44:                 type="button"
45:                 class="shrink-0 rounded-sm text-text-secondary transition-colors duration-150 hover:text-text focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
46:                 aria-label="閉じる"
47:                 onclick={() => dismissToast(toast.id)}
48:             >
49:                 <X class="size-4" aria-hidden="true" />
50:             </button>
```
### resources/js/components/molecules/CodeSnippet.svelte (L24-65) コピー完了 2 秒
```
24: 
25:     async function copy(): Promise<void> {
26:         if (timeoutId) clearTimeout(timeoutId);
27:         try {
28:             // clipboard 非対応環境 (insecure context 等) は writeText が無いため失敗扱い
29:             if (!navigator.clipboard?.writeText) {
30:                 throw new Error("clipboard unavailable");
31:             }
32:             await navigator.clipboard.writeText(code);
33:             copied = true;
34:             failed = false;
35:         } catch {
36:             copied = false;
37:             failed = true;
38:         }
39:         timeoutId = setTimeout(() => {
40:             copied = false;
41:             failed = false;
42:         }, 2000);
43:     }
44: 
45:     onDestroy(() => {
46:         if (timeoutId) clearTimeout(timeoutId);
47:     });
48: </script>
49: 
50: <div class={["relative", extraClass].filter(Boolean).join(" ")} data-testid={testId}>
51:     <!-- pr-24 でコピー UI 分の余白を確保する -->
52:     <pre
53:         data-testid={testId ? `${testId}-body` : undefined}
54:         data-language={language}
55:         class="overflow-x-auto rounded-md border border-border bg-neutral p-4 pr-24 text-caption font-mono text-text"><code
56:         >{code}</code></pre>
57:     <div class="absolute top-2 right-2 flex items-center gap-2">
58:         {#if copied}
59:             <span role="status" class="text-caption text-success">コピー完了</span>
60:         {:else if failed}
61:             <span role="status" class="text-caption text-danger">コピー失敗</span>
62:         {/if}
63:         <Button
64:             variant="neutral"
65:             size="sm"
```
### resources/js/components/atoms/Spinner.svelte (L33-45) 進捗の role=status
```
33: </script>
34: 
35: <span
36:     class={computedClass}
37:     role={label !== undefined ? "status" : undefined}
38:     aria-hidden={label === undefined ? "true" : undefined}
39:     data-testid={testId}
40: >
41:     <LoaderCircle class="animate-spin {SIZE_CLASSES[size]}" aria-hidden="true" />
42:     {#if label !== undefined}
43:         <span class="sr-only">{label}</span>
44:     {/if}
45: </span>
```
### app/Http/Controllers/Projects/VideoManualController.php (L219-234) destroy の flash
```
219: 
220:     /** 削除 */
221:     public function destroy(Request $request, Project $project, VideoManual $manual, VideoManualService $manuals): RedirectResponse
222:     {
223:         $organization = $this->resolveCurrentOrganization($request);
224:         // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
225:         $this->resolveOrganizationProject($organization, $project);
226:         Gate::authorize('delete', $manual);
227: 
228:         $manuals->delete($project, $manual);
229: 
230:         return redirect()
231:             ->route('projects.show', $project)
232:             ->with('success', '動画マニュアルを削除しました');
233:     }
234: 
```
### resources/js/pages/Manuals/Show.svelte (L50-68) router.delete = SPA visit
```
50: 
51:     /* ---- 削除 ---- */
52:     let deleteDialogOpen = $state(false);
53:     let deleting = $state(false);
54: 
55:     function deleteManual(): void {
56:         router.delete(`/projects/${project.id}/manuals/${manual.id}`, {
57:             onStart: () => {
58:                 deleting = true;
59:             },
60:             onFinish: () => {
61:                 deleting = false;
62:                 deleteDialogOpen = false;
63:             },
64:         });
65:     }
66: </script>
67: 
68: <AppLayout {appName}>
```
### .claude/skills/app-bug-hunt/coverage/test_naming_no_stale.py (L1-40) 既存 stdlib テストの流儀
```
1: """操作到達/コード到達カバレッジへの命名統一の後退防止 self-test。
2: 
3: 旧 Stage 付番 (Stage1/Stage3) と旧出力ファイル名 (coverage-stage1.md / coverage-stage3.md) が
4: skill 本文ファイルに再混入していないことを機械検知する。
5: 
6: 対象は `.claude/skills/app-bug-hunt/` 配下の本文 (.md / .py)。誤 fail を避けるため、
7: devnotes (設計 migration note・履歴説明) とこの test 自身は対象外にする。
8: """
9: 
10: from __future__ import annotations
11: 
12: import re
13: import unittest
14: from pathlib import Path
15: 
16: SKILL_ROOT = Path(__file__).resolve().parent.parent  # .claude/skills/app-bug-hunt/
17: 
18: # 旧用語パターン: 単語境界付き Stage1/Stage3 (Stage 1 / Stage 3 表記も) と旧出力ファイル名。
19: STALE_PATTERNS = [
20:     re.compile(r"Stage\s?1\b"),
21:     re.compile(r"Stage\s?3\b"),
22:     re.compile(r"coverage-stage[13]\.md"),
23: ]
24: 
25: # 対象外: 履歴・設計ノートは devnotes 側に隔離されている前提。skill 配下は本 test 自身のみ除外。
26: EXCLUDE_NAMES = {"test_naming_no_stale.py"}
27: 
28: 
29: def _target_files() -> list[Path]:
30:     files: list[Path] = []
31:     for ext in ("*.md", "*.py"):
32:         for p in SKILL_ROOT.rglob(ext):
33:             if p.name in EXCLUDE_NAMES:
34:                 continue
35:             if "devnotes" in p.parts:
36:                 continue
37:             files.append(p)
38:     return files
39: 
40: 
```
### .claude/skills/app-bug-hunt/ledger/validate_findings.py (L488-512) new_signal (symptom_tokens の扱い)
```
488: def required_hits(adj, finding) -> bool:
489:     req = adj["symptom"]["required_tokens"]
490:     toks = finding.get("symptom_tokens")
491:     if isinstance(toks, list) and toks:
492:         # symptom_tokens は author が「観測した token」として明示 → presence は素直に substring。
493:         text = normalize(" | ".join(str(t) for t in toks))
494:         return all(normalize(r) in text for r in req)
495:     # 本文 fallback のみ否定誤一致ガードを適用 (Codex impl-R1 Critical)。
496:     text = _finding_required_text(finding)
497:     return all(_phrase_present_nonneg(r, text) for r in req)
498: 
499: 
500: def has_new_signal(adj, finding) -> bool:
501:     # required は肯定証拠なので coverage に残す (短くても可)。known_tokens は noise (stopword 等) を
502:     # 落とす: stopword だけの known_tokens が real な novel 語を「既知」と誤って覆うのを防ぐ (Adversarial R1)。
503:     known = [normalize(p) for p in adj["symptom"]["required_tokens"] if normalize(p)]
504:     known += [normalize(p) for p in adj["symptom"].get("known_tokens", [])
505:               if normalize(p) and not _is_noise_token(normalize(p))]
506:     for tok in _novelty_tokens(finding):
507:         if not any(p in tok or tok in p for p in known):
508:             return True
509:     return False
510: 
511: 
512: def specificity(adj) -> tuple:
```

