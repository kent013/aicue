# Round 2: Round 1 指摘への対応と再レビュー依頼

Round 1 の全指摘に対する対応マトリクスと、修正後の概念設計 (全文) を示します。
残っている懸念があれば指摘してください。無ければ全体判定 APPROVED を返してください。

---

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 1

## [Critical] `present` 非空で finding を抑止する規約は新種の偽陰性を作る (観点 4)
- 判断: **対応する**
- 根拠: 指摘は実コードで裏が取れた。(a) `resources/js/components/atoms/Alert.svelte:16-17` の
  `Alert` atom は `role=alert` (danger) / `role=status` (他) を持ち、**常駐 UI**として使われる。
  (b) `resources/js/lib/stores/toast.ts:27` により **error toast は自動消去しない** (ttl=null) ので、
  前操作の error toast がページに残り続ける。素朴な `present` 判定はこれらを
  「今回の操作のフィードバック」と誤認する。
- 対応内容: probe に**基線 (baseline)** を導入。probe 実行のたびに「可視な live region」へ
  `{gen, text}` を刻み、次回 probe では「基線が無い」または「テキストが変わった」要素だけを
  `present_new` として返す。基線一致の要素は `present_preexisting` (件数のみ) に落とし判定に使わない。
  判定表を `present` → `present_new` に差し替え、`installed_now:true` のときは
  基線が無いため `present_new` を参考情報に格下げ (未検証に倒す) と明記した。

## [Warning] 既存 live region のテキスト更新 / 属性による hidden→visible の観測範囲が曖昧 (観点 3)
- 判断: **対応する** (観測契約を先に固定)
- 根拠: 「先に固定せよ」という要求は正当。ただし観測範囲は**実測で絞る**。
  AI-CUE の非単調 UI 2 件は Svelte の `{#each}` / `{#if}` による mount/unmount
  (`ToastContainer.svelte:34-53` / `CodeSnippet.svelte:58-62`) で、属性トグル方式は存在しない。
- 対応内容: 概念設計の実装方針 1 に「probe の観測契約」を追加。
  `childList` + `characterData` + `subtree` を監視 / **属性は監視しない** (根拠つき) /
  基線が可視要素のみを刻む副作用で hidden→visible は `present_new` に出るため、
  残余は「hidden→visible かつテキスト不変」のみ、と残余を明記した。
  可視性フィルタも記録時 (layout 非依存) と probe 時 (`getClientRects` 等) で分けて規定。

## [Warning] jsdom では live run の挙動を担保できない / 受入条件が弱い (観点 3・7)
- 判断: **対応する**
- 対応内容: 「次回 bug-hunt run の受入条件 (必須観測ケース)」節を新設。
  L1 = F-1-02 同型の SPA 削除導線で `seen` に `toast-success` が入ること、
  L2 = `CodeSnippet` コピー導線 (2 秒窓、`organizations.onboarding.cli` / `.mcp` は screens.md に実在)
  で `role=status` の「コピー完了/失敗」が入ること。
  **どちらかが取れなければ方式不成立として設計を見直す** (値のチューニングに逃げない) と明記。

## [Warning] 対象の一般化 (live region 全般) が強すぎる (観点 4)
- 判断: **対応する**
- 対応内容: 「観測対象の概念は**非単調 UI**。ARIA live region はその**手段**」と節タイトルごと書き換え、
  常駐 live region は基線差分で明示的に除外する旨を先頭に置いた。

## [Warning] `installed_now:true` を未検証に倒すと H7 が無言で機能停止する (観点 5)
- 判断: **対応する**
- 対応内容: 「H7 の適用条件」節を新設。(a) H7 の「フィードバック無し」判定は
  `installed_now:false` の操作にのみ適用、(b) `installed_now:true` の操作は shard-report の
  「未検証」節に**操作名つきで必ず列挙**、(c) 再実行は 1 回まで・非冪等な破壊操作は再実行しない、
  (d) AI-CUE の書き込み操作はほぼ全て Inertia SPA visit なので実カバレッジ低下は限定的
  (根拠 `Manuals/Show.svelte:55-65`)、ただし限定的であることを report に書かせる。

## [Warning] hidden / aria-hidden / 非接続の live region を拾う偽陰性源 (観点 5)
- 判断: **対応する**
- 対応内容: 上記「可視性フィルタ」に統合。記録時は `el.hidden` / 祖先 `aria-hidden="true"` /
  未接続を除外、probe 時はさらに `getClientRects().length > 0` + `display`/`visibility` を見る
  (`tests/Browser/FlashToastTest.php:50-58` と同じ判定)。

## [Warning] spec-ledger の file:line 実在チェックは保守負債になりやすい (観点 7)
- 判断: **対応する**
- 対応内容: テスト対象を「**根拠ファイルの実在**」と「機械 registry に『登録済』と書いた `A-NNN` の実在」
  に限定し、**行番号は検証しない**と明記した。

## [Suggestion] spec-ledger の書式拡張と template-divergence 追記は最小限か再確認せよ (観点 6)
- 判断: **一部反論 / 一部対応**
- 根拠と対応:
  - **spec-ledger の書式拡張は必要** (反論)。現行 spec-ledger.md:42-44 の節見出しは
    `SPEC 確定` / `DOC 確定` / `実装で解消` / `CLOSED` の 4 種のみで、
    **`verdict: false_positive` を置ける節が無い**。A-001 は `false_positive` なので、
    節を足さないと F-1-02 を書けない。加えて「driver 側の再発防止」欄の追加は、
    aigenba が 4 回踏んだ根本原因 (申し送りが人手の心構えで終わり機構化されない) を
    構造で塞ぐためのもので、本件の主目的に直結する。
  - **template-divergence の追記は不要と判断を改めた** (対応)。根拠は
    (a) D1〜D9 は全てドメイン構造の逸脱で判定軸が異なる、(b) SKILL.md は T036 (`a9074f0`) で
    既に AI-CUE 都合の追記を受けており divergence 記録の先例が無い、(c) probe 機構はアプリ非依存で
    「逸脱」ではなく**上流還流候補**。概念設計の実装方針 5 をその判断に書き換えた。

## [Suggestion] 観点 1・2・6 の肯定コメント
- 判断: 対応不要 (方向性維持)


---

## 修正後の概念設計 (全文)

# 概念設計: bug-hunt driver の一過性フィードバック捕捉 (toast capture) と spec-ledger 申し送り

- design_dir: `devnotes/20260804-0900-bughunt-toast-capture/`
- task_key: Q
- 対象: **アプリコードではなく bug-hunt 基盤** (`.claude/skills/app-bug-hunt/` + `.claude/agents/bughunt-shard.md`)

## 背景・課題

### 何が起きたか (実測)

bug-hunt run `20260803-203721` の finding **F-1-02**「動画マニュアル削除後に成功 flash が出ない」は
**誤検知**だった。

- サーバは flash を積んでいる — `app/Http/Controllers/Projects/VideoManualController.php:230-232`
  (`redirect()->route('projects.show', $project)->with('success', '動画マニュアルを削除しました')`)。
- クライアントは flash を toast に変換して描画する — `resources/js/lib/stores/flash-to-toast.ts`
  (`consumeFlash`) → `resources/js/lib/stores/toast.ts:47-53` (`addToast`) →
  `resources/js/components/organisms/ToastContainer.svelte`。
- **success / info / warning の toast は 4000 ms で auto-dismiss する** —
  `resources/js/lib/stores/toast.ts:23-29` の `AUTO_DISMISS_MS`。`error` だけ `null` (自動消去しない)。
- T095 の実装フェーズで**現行コードのまま** Browser テストを両レーンで走らせたら、着地マーカーと
  同一時間窓で `toast-success` が可視になり PASS した — `tests/Browser/FlashToastTest.php`。

つまり bug-hunt driver の `snapshot` が **4 秒の可視窓の後**に来ていたための観測 artifact である。
機械 registry には親が `A-001` (`verdict: false_positive`) を登録済み
(`.claude/skills/app-bug-hunt/ledger/adjudications.jsonl`)。

### なぜ「1 件の誤検知」で終わらせてはいけないか

参照アプリ aigenba は**同種の誤検知を 4 回**踏んでいる
(`/tmp/aigenba/.claude/skills/aigenba-bug-hunt/spec-ledger.md`):

| aigenba finding | 事象 | 台帳の記述 |
|---|---|---|
| F-3-01 | 招待受諾後の「参加しました」flash 欠落 | 実 Chromium 検証で server 仮説が反証され偽陽性確定 (:109) |
| F-3-2 | チーム更新後の flash 無し | 「トースト自動消滅後の観測ミス」(:180) |
| F-3-3 | 権限トグル後の flash 無し | 申し送り「**トースト自動消去タイマー後の snapshot を『flash 無し』と誤認しないこと**」(:184-185) |
| F-2-01 | 招待 URL 無効時の「無言リダイレクト」 | 「ブラウザ実走で toast が出ることを確認すれば誤検知を避けられる。無言リダイレクトと断定しないこと」(:151) |

**aigenba の弱点**: これらは人間可読の spec-ledger にしか無く、機械 registry
(`/tmp/aigenba/.claude/skills/aigenba-bug-hunt/ledger/adjudications.jsonl`、3 件) には toast 系が
**1 件も無い**。しかも申し送りは「注意しろ」という**人手の心構え**で終わっており、
**driver の観測手順そのものを直していない**。だから次の run で自動 downrank もされず、
観測手順も同じままで、4 回とも再起票された。

**本設計の仮説**: 再発の原因は「台帳の書き漏れ」ではなく **driver の観測手順に構造的な穴がある**
ことである。したがって台帳追記だけでは再発を止められない。**観測手順を直すのが本体**であり、
台帳はその根拠を渡す従である。

### 現行 driver の構造的な穴

AI-CUE の bug-hunt は `@playwright/cli` を Bash で駆動する。走行プロトコル
(`.claude/skills/app-bug-hunt/SKILL.md` §走行プロトコル 1-2) は
「`snapshot` → 操作 → 再 `snapshot`」であり、**観測は常に事後 1 点のサンプリング**である。

- サンプリング間隔は Bash 1 コール分 (プロセス起動 + 往復) で、数百 ms〜数秒に振れる。
- 対して観測対象の可視窓は **4000 ms 固定**。並列 shard 走行時は負荷でさらに遅れる。
- したがって「事後 snapshot に無い = 出なかった」は**論理的に導けない**。
  現行プロトコルはこの推論を明示的に禁じていない = 穴。

**aigenba は MCP 版 playwright の `browser_wait_for` で対処していたが、AI-CUE の
`@playwright/cli` には wait-for 系コマンドが無い** (利用可能コマンドは
`open/attach/close/goto/type/click/dblclick/fill/drag/drop/hover/select/upload/check/uncheck/
snapshot/find/eval/dialog-*/resize/delete-data/go-back/go-forward/reload/press/keydown/keyup/
mousemove`)。そのまま移植できない。

## 改善アイデア

### 核: 「待って見る」のではなく「**先に記録器を仕込む**」

`eval <func>` がある。T089 の Browser テストで実証済みの手法 —
**操作の前に MutationObserver を仕込み、操作後にフラグを読む** —
(`tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php:60-92`) がそのまま使える。
待ち時間に依存しないので `browser_wait_for` より確実で、**消えた後でも観測できる**
(wait-for は「まだ出ていない」と「もう消えた」を区別できないが、記録器は区別できる)。

### 観測対象の概念は「**非単調 UI**」。ARIA live region はその**手段**

概念は「**非単調 UI = 現れて自動的に消える UI**」であり、`role=status` / `role=alert` は
それを DOM から拾うための**セレクタ手段**にすぎない (別概念の統合を避ける — 思考原則 4)。
live region 全般を対象にするのではない: 常駐する live region (下記 `Alert` atom 等) は
**基線 (baseline) 差分で明示的に除外する** (§判定規約)。

toast だけを testid (`toast-success` 等) で狙うと、同じ「観測窓」問題を持つ他の UI を取り逃す。
AI-CUE の**非単調 UI を実測で洗い出す**と 2 つある (`rg setTimeout resources/js` の全件を精査):

| 対象 | 可視時間 | 根拠 | live region |
|---|---|---|---|
| toast (success/info/warning) | 4000 ms | `resources/js/lib/stores/toast.ts:23-29` | `role="status"` (error のみ `role="alert"`) — `ToastContainer.svelte:41` |
| CodeSnippet の「コピー完了 / コピー失敗」 | 2000 ms | `resources/js/components/molecules/CodeSnippet.svelte:39-42` | `role="status"` — `CodeSnippet.svelte:59,61` |

(`CameraRecorder.svelte:335` の 2000 ms タイムアウトは pause/resume イベント未達の**内部復旧 guard**
であり、ユーザーに見える一過性表示ではないため対象外。)

**両方とも ARIA live region (`role="status"` / `role="alert"`) を持つ**。したがって記録器は
**`[role=status], [role=alert]` の出現・変化を記録する**設計にする。これは
「フレームワークのレンジ内でやる」(思考原則 1) — アプリ固有 testid ではなく W3C の標準セマンティクスに
乗るので、DS の testid 変更で腐らず、新しい component も a11y を正しく実装していれば自動的に載る。

### 常駐 live region と一過性 live region の分離 (基線)

`role=status` / `role=alert` は**常駐 UI にも使われている**:
`resources/js/components/atoms/Alert.svelte:16-17` (danger=`role=alert` / 他=`role=status`) は
ページに出しっぱなしの注意書きに使われ、`toast` の `error` は
`resources/js/lib/stores/toast.ts:27` により**自動消去しない** (ttl=`null`)。
したがって「今 DOM にある live region」を素朴に証拠にすると、
**前の操作で出た error toast や常駐 Alert を『今回の操作のフィードバック』と誤認する** =
新種の偽陰性 (finding の見落とし) を作る。

そこで記録器は **probe 呼び出しのたびに基線 (baseline) を張り直す**:

- probe 実行時、**可視な** live region 各要素に `{gen, text}` を刻む (element 自身に property で保持)。
- 次の probe では「基線が無い (= 前回 probe 以降に可視化された)」か
  「基線はあるがテキストが変わった」要素だけを **`present_new`** として返す。
- 基線があってテキストも同じ要素は `present_preexisting` (件数のみ) に落とし、**判定に使わない**。

これにより `present_new` は常に「**直前の probe 以降に起きた変化**」= 操作の観測窓に一致する。

### 判定規約 (これが本体)

記録器は「見えた」だけでなく **「観測窓が連続していたか」** を返す。これにより
「観測できなかった」と「観測窓が壊れていた」を分離し、**後者を finding にしない**。

| 記録器の戻り値 | 解釈 | 行動 |
|---|---|---|
| `installed_now: false` かつ (`seen` または `present_new` が非空) | 観測窓が連続し、変化を捕捉した | フィードバックあり → finding にしない |
| `installed_now: false` かつ 両方空 | 操作の全区間で記録器が生きていた = **本当に出なかった** | H7 finding 候補 |
| `installed_now: true` | 途中で document が置換され記録器が失われた (基線も無い) | **未検証**。finding にも「あり」にもしない。`present_new` は常駐 live region を含みうるため参考情報に留める |

現行プロトコルはこの区別を持たないので、3 行目が 2 行目に潰れて誤検知になる。**これが F-1-02 の
機序であり、修正の本体である。**

### H7 の適用条件 (カバレッジ低下を無言で持ち込まない)

`installed_now: true` を「未検証」に倒すと、cross-document 遷移を含む導線で H7
(「結果フィードバックが無い」) が働かなくなる。これを無言の後退にしないため、規約を明文化する:

- **H7 の「フィードバック無し」判定は、同一 document が継続した操作にのみ適用する**
  (= post-op probe が `installed_now: false` を返した操作)。
- `installed_now: true` になった操作は shard-report の「未検証」節に **操作名つきで必ず列挙する**
  (無言の skip は禁止 — SKILL.md 走行プロトコル 7 と同じ規律)。
- **再実行は 1 回まで**。再実行してよいのは冪等に再現できる操作だけで、
  非冪等な破壊操作 (削除等) は再実行せず未検証のまま記録する。
- AI-CUE では **書き込み操作のほぼ全てが Inertia の SPA visit** (同一 document) である
  (例: `resources/js/pages/Manuals/Show.svelte:55-65` の `router.delete`)。
  cross-document になるのは `open` / `goto` / 素の `<a href>` ナビゲーション
  (`AppLayout` のサイドバー) 等であり、**書き込み操作の最中に起きることは稀**。
  よって実カバレッジ低下は限定的だが、**限定的であることを report に書かせる**。

## 期待効果

- **使命への貢献**: bug-hunt はこのアプリの UX 品質を dogfooding で検証する仕組み
  (`SKILL.md` §使命)。誤検知は「発見の質」を直接毀損し、triage コストを食い、
  本物の finding の信号対雑音比を下げる。観測手順を直すことは発見機構そのものの品質改善である。
- **定量**: aigenba では同種誤検知が 4 回。AI-CUE では初回 run で 1 回 (F-1-02)。
  本設計の目標は「非単調 UI に起因する『フィードバック欠落』誤検知を 0 にする」。
  次 run の受入基準は §検証方法に書く。
- **副次**: 一過性フィードバックが live region を持たない component があれば、記録器が空を返す。
  これは同時に**スクリーンリーダー利用者にも伝わっていない**ことを意味するので、H14 (a11y) の
  finding 候補になる。ただし「視覚的フィードバックが無い」とは言えないため、**主張の粒度を
  分けて起票する**規約を置く (誤検知の作り替えを防ぐ)。

## 実装方針 (概要)

1. **記録器 (probe) を 1 ファイルに置く** — `.claude/skills/app-bug-hunt/probes/feedback-probe.js`。
   `playwright-cli --raw eval "$(cat ...)"` で渡す。JS をプロトコル本文に inline せず
   ファイルにするのは (a) shell クォート事故を構造的に無くす (b) 正本を 1 箇所にする ため。
   **probe の観測契約 (先に固定する)**:
   - 監視は `childList` + `characterData` + `subtree` (documentElement を監視対象にし、
     body が置換されても外れない — `InertiaHistoryRestoreAfterLogoutTest.php:80-88` と同じ流儀)。
   - **属性変化 (`hidden` / `aria-hidden` トグル) は監視しない。** 根拠: AI-CUE の非単調 UI 2 件は
     いずれも Svelte の `{#each}` / `{#if}` による mount/unmount = `childList`
     (`ToastContainer.svelte:34-53` / `CodeSnippet.svelte:58-62`)。属性トグル方式の component は
     存在しない。**ただし基線 (可視な要素のみ刻む) の副作用として hidden→visible は
     `present_new` に現れる**ので、実質的な取りこぼしは「hidden→visible かつテキスト不変」だけ。
     この残余は既知として記録し、該当 component が現れた時点で拡張する (今必要なものだけ — 思考原則 2)。
   - **可視性フィルタ**: 記録時 (mutation callback) は layout に依存しない条件のみ
     (`el.hidden` / 祖先の `aria-hidden="true"` / 未接続を除外)。probe 実行時の `present_new` /
     基線判定は `getClientRects().length > 0` + `display` / `visibility` も見る
     (`tests/Browser/FlashToastTest.php:50-58` と同じ可視判定)。
2. **走行プロトコルの正本は `SKILL.md`** に置く (§走行プロトコル に手順と判定規約を追加、
   §横断ヒューリスティクス H7 に前提条件を追加)。`.claude/agents/bughunt-shard.md` は
   自身が「走行プロトコルは SKILL.md に従う。本ファイルは差分のみ」と宣言している
   (`bughunt-shard.md:72-74`) ので、**コマンド一覧に 1 行足すだけ**にして規約本文を二重化しない
   (思考原則 3)。直列走行 (shard 0、親が駆動) は `bughunt-shard.md` を読まないため、
   SKILL.md 側に置かないと直列 run で穴が残る — これが正本選定の決め手。
3. **spec-ledger.md に run 20260803-203721 の節を書き起こす** (現在は空)。
   F-1-02 を **誤検知確定**として登録し、機械 registry `A-001` との役割分担を守る
   (同じ説明文を重複させない。registry = 機械照合、spec-ledger = 「なぜそう確定したか」と申し送り)。
   併せて spec-ledger の書式に (a) `### 誤検知確定 (再起票しない)` 節と
   (b) 「driver 側の再発防止」欄を足す — **aigenba の弱点 (心構えで終わらせる) を構造で塞ぐ**ため。
4. **テストで固定する** (思考原則 5):
   - probe の契約を jsdom で固定 (`pnpm test`)。
   - spec-ledger の腐り (**根拠ファイルの実在**、registry との相互参照切れ) を stdlib unittest で固定。
     **行番号は検証しない** — 通常のリファクタで台帳テストが壊れる保守負債になるため
     (検証するのはファイルの実在と、機械 registry に「登録済」と書いた `A-NNN` の実在)。
5. **`docs/template-divergence.md` への記録は不要と判断する**。根拠:
   (a) 同レジストリの既存エントリ D1〜D9 はすべて**アプリのドメイン構造**の逸脱で、判定軸は
   「同じ不変条件を同じタイミング/抽象度で保証するか」。本件は不変条件の削除・置換ではなく
   **driver 契約の追加**である。(b) 先例: `.claude/skills/app-bug-hunt/SKILL.md` は
   T036 (commit `a9074f0`) で既に AI-CUE 都合の追記を受けており、divergence 記録は行われていない。
   (c) probe 機構自体はアプリ非依存 (ARIA live region のみに依存) なので、
   **逸脱ではなくテンプレート上流への還流候補**である。詳細設計にその旨を残す。

## 制約・前提

- **アプリコードを変更しない。** F-1-02 は誤検知であり、直すべきはアプリではなく driver。
  `toast.ts` の 4000 ms を伸ばす等の「テストのためにアプリを変える」対処は取らない。
- **bug-hunt 環境の provision を伴わない検証しかできない** (provision は orchestrator 専用で
  実装者は default-deny — `scripts/bug-hunt-shard.sh` の `require_orchestrator`)。
  ライブ検証は次回 bug-hunt run に委ねる (§検証方法で担保範囲を明示する)。
- **findings.jsonl の `symptom_tokens` に probe 由来の新語を入れてはならない。**
  `validate_findings.py:500-509` (`has_new_signal`) は symptom_tokens の novelty 語で
  `ambiguous` に倒すため、probe verdict を token 化すると **A-001 の downrank が無効化される**。
  probe 結果は report.md の finding 証跡欄に書く (思考原則 6: 結合観点)。
- 記録器は Inertia の SPA 遷移 (同一 document) を跨いで生き残る。動画マニュアル削除は
  `resources/js/pages/Manuals/Show.svelte:55-65` の `router.delete` = SPA visit なので、
  F-1-02 の経路は記録器で確実に捕捉できる。cross-document 遷移では失われるが、
  それは `installed_now: true` として**検知できる** (未検証に倒す)。

## スコープ外

- アプリの toast 実装の変更 (auto-dismiss 時間、testid、role)。
- `browser_wait_for` 相当コマンドの自作 / polling ループの導入
  (待ち時間依存で非単調 UI には構造的に不適。記録器方式で代替する)。
- 単調な UI (非同期に描画され、その後残り続けるもの) への対処。これは既存プロトコル 2
  「遷移を伴う操作の後は再 snapshot」で足りる。今回の機構は**非単調 UI 専用**である
  (この区別自体は規約として明記する)。
- 他 run の finding の再 adjudicate、`ledger/adjudications.jsonl` への追記
  (A-001 は親が登録済み。append-only の既存行は触らない)。
- `screens.md` / `operations.md` / `stories/` / `capability-catalog.md` の変更 (不要)。

## 検証方法 (何が担保でき、何ができないか)

| 手段 | 担保できること | 担保できないこと |
|---|---|---|
| `pnpm test` (新規 jsdom テスト) | probe の契約 (arm / seen / present / drain / 窓喪失検知 / 非 live-region ノイズ無視) | 実 Chromium・実 Inertia 遷移での挙動 |
| `python3 -m unittest` (skill 内 stdlib) | spec-ledger の構造・根拠パス実在・registry 相互参照、`validate_findings.py` の registry 検証 | プロトコルが実際に守られるか |
| `scripts/bug-hunt-shard.sh self-test` | **本設計は同スクリプトを変更しない**ので「何も壊していない」ことの回帰確認のみ | probe に関する一切 |
| **次回 bug-hunt run (ライブ)** | `playwright-cli --raw eval` の実挙動、実 run でのコスト、誤検知 0 の達成 | — |

**「検証済み」と黙って書かない**: 上記の live 検証項目は次回 run の受入条件として詳細設計に列挙し、
run 後に spec-ledger へ結果を追記する。

### 次回 bug-hunt run の受入条件 (必須観測ケース)

「誤検知 0」だけでは曖昧なので、**検証可能な 2 ケース**を必須にする:

| # | 導線 | 期待する probe 出力 |
|---|---|---|
| L1 | F-1-02 と同型の SPA 削除導線 (`projects.manuals.destroy` → `projects.show`) | post-op probe が `installed_now:false` かつ `seen` に `role=status` / testid `toast-success` / 本文「動画マニュアルを削除しました」を 1 件以上含む |
| L2 | `CodeSnippet` の「コピー」導線 (2 秒窓。例: `organizations.onboarding` の CLI/MCP 画面) | post-op probe が `installed_now:false` かつ `seen` に `role=status` の「コピー完了」または「コピー失敗」を 1 件以上含む |

L1/L2 の**いずれかが取れなかった場合、probe 方式は不成立**として設計を見直す
(値のチューニングではなく方式の見直し)。加えて run 後に
「非単調 UI に起因する『フィードバック欠落』finding が 0 件であること」を確認する。

