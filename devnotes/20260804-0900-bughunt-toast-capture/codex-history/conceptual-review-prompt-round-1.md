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

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

（アプリの使命・禁止事項は app-codex-review スキルにより AGENTS.md から自動挿入済み）

【本件の特殊事情 — 必ず考慮すること】
本設計の対象は**アプリのプロダクションコードではなく、LLM 探索的バグハント基盤 (.claude/skills/app-bug-hunt/) の
走行プロトコルと申し送り台帳**である。したがって「DTO/JsonResource」「PHPStan」等の観点は主として
新規追加するテスト側にのみ効く。レビューの主眼は次に置くこと:
- 誤検知 (false positive) の再発を止める機構として、提案手法が本当に必要十分か
- オーバーエンジニアリングでないか（AGENTS.md 思考原則 2「今必要なものだけ作る」）
- 正本の置き場所 (SKILL.md / agents/bughunt-shard.md / spec-ledger.md / 機械 registry) の分割が
  重複や二重管理を生んでいないか（思考原則 3「後方互換の並走を残さない」）
- 別概念の統合になっていないか（思考原則 4）

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（@playwright/cli の eval + MutationObserver、jsdom テスト）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか。誤検知が本当に止まるか
5. リスク: 重大な副作用・後退の可能性はないか。特に「新しい種類の誤検知」を生まないか
6. スコープの適切さ: 過大または過小になっていないか
7. 検証計画の誠実さ: 「担保できないこと」の切り分けが正しいか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

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

### 観測対象の一般化: testid ではなく **ARIA live region**

toast だけを testid (`toast-success` 等) で狙うと、同じ「観測窓」問題を持つ他の UI を取り逃す。
AI-CUE の**非単調 UI (現れて自動的に消える UI) を実測で洗い出す**と 2 つある
(`rg setTimeout resources/js` の全件を精査):

| 対象 | 可視時間 | 根拠 | live region |
|---|---|---|---|
| toast (success/info/warning) | 4000 ms | `resources/js/lib/stores/toast.ts:23-29` | `role="status"` (error のみ `role="alert"`) — `ToastContainer.svelte:41` |
| CodeSnippet の「コピー完了 / コピー失敗」 | 2000 ms | `resources/js/components/molecules/CodeSnippet.svelte:39-42` | `role="status"` — `CodeSnippet.svelte:59,61` |

(`CameraRecorder.svelte:335` の 2000 ms タイムアウトは pause/resume イベント未達の**内部復旧 guard**
であり、ユーザーに見える一過性表示ではないため対象外。)

**両方とも ARIA live region (`role="status"` / `role="alert"`) を持つ**。したがって記録器は
**`[role=status], [role=alert]` の DOM 出現を記録する**設計にする。これは
「フレームワークのレンジ内でやる」(思考原則 1) — アプリ固有 testid ではなく W3C の標準セマンティクスに
乗るので、DS の testid 変更で腐らず、新しい component も a11y を正しく実装していれば自動的に載る。

### 判定規約 (これが本体)

記録器は「見えた」だけでなく **「観測窓が連続していたか」** を返す。これにより
「観測できなかった」と「観測窓が壊れていた」を分離し、**後者を finding にしない**。

| 記録器の戻り値 | 解釈 | 行動 |
|---|---|---|
| `seen` または `present` が非空 | フィードバックあり | finding にしない |
| 両方空 かつ `installed_now: false` | 操作の全区間で記録器が生きていた = **本当に出なかった** | H7 finding 候補 |
| 両方空 かつ `installed_now: true` | 途中で document が置換され記録器が失われた | **未検証**。再実行するか skip 理由に記録 (finding にしない) |

現行プロトコルはこの区別を持たないので、3 行目が 2 行目に潰れて誤検知になる。**これが F-1-02 の
機序であり、修正の本体である。**

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
   - spec-ledger の腐り (実在しない file:line、registry との相互参照切れ) を stdlib unittest で固定。
5. **template-divergence への記録**: SKILL.md はテンプレート共通部なので、`docs/template-divergence.md`
   に 1 エントリ足す (詳細設計で判断根拠を書く)。

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

