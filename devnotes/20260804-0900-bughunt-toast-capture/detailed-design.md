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
                // 既存 live region の**中身が差し替えられた**場合 (Svelte のテキストノード置換)。
                // addedNodes は Text なので collect() では拾えず、characterData も発火しない。
                if (r.type === "childList" && r.addedNodes.length > 0 && r.target.nodeType === 1) {
                    const host = r.target.closest(LIVE);
                    if (host) enqueue(host);
                }
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

施策 5 を参照 (jsdom で 18 項目)。

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
  - `## Phase 4: レポート + クロージング` の shard-report 骨子 (現 L318-326) に
    `H7 未検証` 行を追加

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
| `installed_now:true` | 途中で document が置換され記録器が失われた (基線も無い) | **肯定証拠のみ採用**: `present_new` または直後の `snapshot` に**操作結果を伝える文言**があれば「フィードバックあり」と結論してよい。無い場合は **未検証** (finding にしない)。基線が無いので常駐 live region も `present_new` に混ざる = 陰性判断には使えない |
| `pending > 0` | 可視性判定が未解決 | probe をもう 1 回だけ叩き、**1 回目と 2 回目の `seen` / `present_new` の和集合**で判定する。2 回目も `pending > 0` なら**未検証** |

- **`visible:false` / `visible:"gone"` は証拠に数えない** (返るのは診断のため)。
- **件数ではなく本文で判定する**: `role="status"` は進捗表示にも使われうる
  (`resources/js/components/atoms/Spinner.svelte:36-38` は `label` 指定時に `role="status"`)。
  ローディング/進捗は「操作結果のフィードバック」ではない。
  判定の目安 (最小辞書。網羅列挙ではない):
  - **結果文言 (単独で採用してよい)**: 「〜しました」「完了」「成功」/
    失敗系「〜できません」「失敗」「エラー」
  - **文脈依存語 (単独では採用しない)**: 「削除」「変更」「保存」「作成」「更新」「送信」「招待」。
    これらはボタン名・見出しにも出るので、**`role="status"` / `role="alert"` の中**か
    **フィードバック用 testid (`toast-*` 等) の中**にある場合だけ採用する。
    probe の `seen` / `present_new` は定義上 live region の中なのでこの制限に自動で適合する。
    制限が効くのは `installed_now:true` 時に `snapshot` を肯定証拠に使う経路である。
  - **数えない**: 「読み込み中」「処理中」「Loading」など進捗・状態表示、
    および操作前から出ていた常駐 Alert (基線で `present_preexisting` に落ちる)
- **H7 の「結果フィードバックが無い」は `installed_now:false` の操作にのみ適用する。**
  `installed_now:true` / `pending>0` 継続で肯定証拠も得られなかった操作は
  **`H7 未検証` として shard-report に件数と操作名を必ず出す** (無言の skip は禁止 = 走行プロトコル 7)。
  この件数が run ごとに増えていくなら、probe 方式ではなく**導線側の観測設計**を見直す信号とする。
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

**(e) Phase 4 の shard-report 骨子に 1 行追加**:

```markdown
- H7 未検証 (観測窓が途切れ肯定証拠も得られなかった操作): n 件 (操作名を列挙)
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
  `wont_fix` は現時点で該当項目が無いため節を作らない。必要になったら
  `### wont_fix 確定 (再起票しない)` を追加する (節の追加は書式ルールの更新を伴う)。
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

### テスト計画 (18 項目。すべて設計フェーズで参照実装に対し実測 PASS 済み)

> 参照実装と実測ハーネスは `reference/feedback-probe.js` / `reference/probe-jsdom-check.cjs`
> (本 design_dir 内) に置いてある。実装時は前者を
> `.claude/skills/app-bug-hunt/probes/feedback-probe.js` へ配置し、後者の 18 ケースを
> vitest (`tests/js/bughunt/feedback-probe.test.ts`) へ移植する
> (`node devnotes/20260804-0900-bughunt-toast-capture/reference/probe-jsdom-check.cjs` で再実行できる)。

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
| M1 | 既存 live region 内の**テキストノード差し替え** (Svelte の `{expr}` 更新相当。`addedNodes` が Text なので `collect()` も `characterData` も効かない経路) | `seen` に `visible:true` で出る |
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

1. **必須欄の欠落なし**: `#### {finding_id} — …` エントリは**テンプレートの全 9 欄**を持つ
   (テンプレートの「欄を削らない」を機械化。設計上の主張とテスト保証を一致させる):
   `判定` / `根拠 (file:line)` / `なぜ誤検知に見えたか` / `driver 側の再発防止` /
   `watch_globs (機械 registry に載せる場合)` / `review_after_days` / `確定した run_id` /
   `再オープン条件` / `機械 registry`。
   照合は**キー文字列の存在ではなく `- **{項目名}**:` の行形式**で行う
   (本文中に同じ語が出ただけで PASS する誤検知を避ける)。
   欄名はテンプレートと**同一文字列**にする (テンプレートを直したらテストも直す 1 対 1 の関係)。
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
REQUIRED_FIELDS = ("判定", "根拠 (file:line)", "なぜ誤検知に見えたか",
                   "driver 側の再発防止", "watch_globs (機械 registry に載せる場合)",
                   "review_after_days", "確定した run_id", "再オープン条件", "機械 registry")
# 照合は行形式で行う (本文中の語の混入で PASS しないように)
FIELD_LINE = "- **{name}**:"
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
| `pnpm test` (施策 5) | probe の契約 18 項目 (arm / 窓喪失検知 / auto-dismiss 後の捕捉 / 基線による常駐除外 / 可視性 3 値 / drain / ノイズ無視) | 実 Chromium・実 Inertia 遷移での挙動、`playwright-cli` の引数解釈 |
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
