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

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【本設計に固有の重点確認事項】
- 「本バッチでやる / 次サイクル送り」の仕分けの妥当性（過大でも過小でもないか）
- gate 化の判断基準 (a)(b)(c) が妥当か。gate を作りすぎ / 作らなさすぎではないか
- 施策 4 (孤児テスト DB 回収) が AGENTS.md 禁止事項 3 (dev DB への破壊操作) に抵触しないか。
  guard の三重化 (名前 allowlist regex / dev DB denylist / 生存 worktree hash 突合) + 既定 dry-run で十分か
- 施策 5 (git index の NFC/NFD 重複除去) の破壊リスク評価が妥当か。
  「git rm --cached は working tree を触らないから安全」という論拠は正しいか。見落としている失敗モードはないか
- 施策 5 → 施策 4 の順序依存の主張が正しいか
- 4 グループへの分割が適切か（もっとまとめるべき / もっと分けるべき箇所はないか）

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: audit-followup-maintenance (サイクル 2 監査の残り是正)

## 背景・課題

サイクル 2 の多角監査 (`devnotes/20260805-1600-audit-cycle-2/`) が検出した是正項目のうち、
Critical/High の UI (T107) とセキュリティ (T108) は既に解消済み。残るのは**保守項目 7 件**で、
いずれも「アプリの機能」ではなく **開発基盤の信頼性 (偽赤・偽グリーン・運用事故の芽)** に関わる。

7 件を実測で再確認した結果 (2026-08-05 18:13 JST 時点の main = `c490de0`):

| # | 項目 | 実測での再確認結果 |
|---|---|---|
| 1 | `preg_split('/\R/')` の `/u` 欠落 | **監査より 1 箇所多い 3 箇所**。`GlobalTestLockInventoryTest.php:107` / `:146` / `BughuntOrchestratorGateInvariantTest.php:95` |
| 2 | bug-hunt インベントリ drift | `scripts/bug-hunt-inventory-check.sh` が **exit 3**。未追記は screens 3 本 + operations 5 本 = **8 route** (課題文の 4 本 + passkey の options 3 本 + `passkey.confirm`) |
| 3 | ドキュメント乖離 | AGENTS.md §セキュリティ不変条件は 8 項目のまま (guide §7 は 10 項目) / `.env.example:194` が存在しない節を参照 / `architecture.md:83-85` の `CUSTOM_BINDER` 列挙が 1 件 (実装は 2 件) / AGENTS.md・app-implement に `build:packages` 欠落 / グローバルテストロックの導線が AGENTS.md に 0 件 / `TRUSTED_PROXIES` は `docs/trusted-proxies-runbook.md` が実在するが **AGENTS.md からも README.md 文書表からも辿れない** |
| 4 | 孤児テスト DB | **17 DB / 221.9 MB を再確認** (3a7d6b4e 群 5 / 823cbbd2 群 5 / b4f0102e 群 5 / 018d63c6 / 91c7197b)。生存 worktree は `/workspace` (hash `8af22c44`) のみ |
| 5 | `doc/reference/` NFC/NFD 重複 | `git ls-files` = **197** / 実体 = **139** / 重複 blob = **55** / `core.precomposeunicode = false` を再確認 |
| 6 | `global-test-lock.sh` の pgid 取得 race | **未修正**。しかも `:350` だけでなく `:266` (`_gtl_probe_process_group`) にも**同型が 1 箇所**ある |
| 7 | advisory 4 件 | `audit:gate` は PASS (moderate 4)。`undici` は `6.28.0` が **caret `^6.27.0` の範囲内**、`eslint-plugin-better-tailwindcss@4.7.0` が `valibot@^1.4.2` を持つことをレジストリで確認済み |

### 共通する構造

3 と 4/5 は独立した事象ではなく、**同一の失敗モードの 2 つの現れ方**である。

- **3 の失敗モード** (docs-freshness.md §7 所見): 「新機能側の doc は丁寧に書かれたが、
  **既存の要約・列挙・台帳**の更新を忘れた」。人間/LLM が「正本」として読む側が古い。
- **4/5 の失敗モード**: `teardown-worktree.sh` の dirty チェックが `doc/reference/` の
  NFC/NFD 重複で**必ず fail** → `git worktree remove --force` で強制撤去 →
  `scripts/ci/drop-test-db.php` を通らない → **孤児 DB が単調増加**。
  つまり「安全機構が常に赤 → 迂回が常態化 → 安全機構の効果がゼロ」。

どちらも「機械が守っていない規約は必ずドリフトする」という同じ結論に至る。
本バッチは **是正 + (費用対効果が合うものだけ) 機械強制** を行う。

## 改善アイデア

7 項目すべてを本バッチで扱う。ただし**すべてを gate 化はしない**
(AGENTS.md 思考原則 2「今必要なものだけ作る」)。gate 化の判断基準を先に置く:

> **gate を作る条件**: (a) 実際にドリフトが発生した実績がある、かつ
> (b) 正本が機械可読 (定数 / スクリプト / ファイル一覧) で、doc 側の表現に依存しない照合ができる、かつ
> (c) 既存の解析実装を重複させない。

この基準で 7 項目を仕分けた結果:

| 項目 | 本バッチ | gate 化 | 判断理由 |
|---|---|---|---|
| 1. `\R` の `/u` | **やる** | **する** (新規 Architecture テスト 1 本) | (a) 実害発生済み (74 行の偽分割・4.8 KB 漏出) / (b) PCRE リテラルの静的検査で正本不要 / (c) 既存に同種の解析なし。日本語コメント規約下では再発が時間の問題 |
| 2. bug-hunt インベントリ | **やる** | **する** (既存スクリプトを CI へ配線 + workflow inventory で pin) | (a) T106・T107 の 2 サイクル連続でドリフト / (b) 判定ロジックは `bug-hunt-inventory-check.sh` に既にある / (c) **PHP 側に再実装しない** = 重複ゼロ |
| 3. ドキュメント乖離 | **やる** | **一部する** (2 本のみ) | `CUSTOM_BINDER ↔ architecture.md` と `package.json 検証 script ↔ AGENTS.md/app-implement` の 2 つだけが (a)(b)(c) を満たす。散文 (不変条件の文章 / ロックの周知 / 参照リンク) は doc 修正のみ |
| 4. 孤児 DB 回収 | **やる** | 単体テストのみ | 回収経路は「掃除コマンド」であって不変条件ではない。分類ロジックを純関数化して Pest で固定する |
| 5. NFC/NFD 重複 | **やる** (4 の前) | **する** (新規 Architecture テスト 1 本) | (a) 発生済み / (b) `git ls-files` + `Normalizer` で機械判定可 / (c) 既存に同種なし。再発すると 4 が再発する |
| 6. pgid race | **やる** | 既存 verify スイートへケース追加 | 層 1 (`verify-global-test-lock.sh`) が既に契約スイート。新 gate は不要 |
| 7. advisory upgrade | **やる** | 既存 `audit:gate` が blocking | 新設不要。upgrade 後に緑維持が受入条件 |

### 次サイクル送りにするもの (理由付き)

| 送るもの | 理由 |
|---|---|
| **zod の major 分裂** (root v4 / packages/cli v3) | `packages/cli` のスキーマ定義を v3→v4 移行するコード変更を伴い、本バッチの「保守」の粒度を超える。かつ `audit:gate` は緑で緊急性がない。思考原則 3 の逸脱ではあるので TODO 化して追跡する |
| **markdown 相互参照リンクチェッカ** (`.env.example:194` の dangling を機械検出する仕組み) | 対象が repo 横断 (md / php コメント / env) で、実装すると「セクション見出しの解決」という独自パーサが 1 本増える。今回検出された dangling は 1 件で、費用対効果が合わない |
| **行分割ヘルパの共通化** (`\R` の共通関数化) | 呼び出し箇所が 3 箇所しかなく、共通ヘルパは新しい共有 class を 1 本増やす。**gate があれば `/u` 忘れは検出できる**ため、ヘルパは不要 (思考原則 2) |
| **PHP トークン解析器 8 本の共通基盤化** / JS `.svelte` 列挙ヘルパ共有化 | tech-debt.md の #6/#7。本バッチのテーマ (偽赤・運用事故の是正) と別軸で、規模も中。監査レポートが「次のゲートを足す前にやるべき」としているので独立 TODO が適切 |
| **Svelte `state_referenced_locally` 警告 7 件** / Vite `configLoader` 警告 | tech-debt.md #10/#6-C。本バッチの主題外 |

## 実装方針(概要)

依存順に 4 グループへ分割する。**グループ C の内部順序 (5 → 4) は必須**。

### グループ A: テストレーン基盤の偽赤除去 (施策 1・6)

同じ「テスト基盤が偽赤を出す」主題で、触るファイル (`GlobalTestLockInventoryTest.php`) が重なる。

- **施策 1**: `preg_split('/\R/')` 3 箇所を `'/\R/u'` へ。加えて
  `tests/Architecture/PcreUnicodeModifierGateTest.php` を新設し、
  「PCRE パターンリテラルに `\R` を含むなら `u` 修飾子が必須」を deny-by-default で強制する。
  正/負コントロール + 空振り下限ガードを持たせる (既存 gate の作法に合わせる)。
- **施策 6**: `scripts/global-test-lock.sh:350` と `:266` の
  `pgid="$(ps ...)"` を `|| pgid=""` 付きへ (2 箇所)。
  `scripts/verify-global-test-lock.sh` に **C25** (即時終了する子でレーンが落ちない) を追加し、
  `scripts/run-browser-test.contract.test.ts` の回避策 `sleep 0.1` 2 箇所を撤去する
  (回避策の撤去そのものが回帰テストになる)。

### グループ B: 正本 (台帳・規約) のドリフト是正 (施策 2・3)

「LLM エージェントが読む正本が実装に追いついていない」という同一主題。

- **施策 2**: `screens.md` に 3 route / `operations.md` に 5 route を既存粒度・書式で追記し、
  `stories/S1` と `stories/S6` に**パスキーとパスワード初回設定のユーザーストーリー**を追加する。
  再発防止は `bug-hunt-inventory-check.sh` を CI の php job に**ブロッキング step として配線**し、
  `tests/js/architecture/ci-workflow-inventory.test.ts` にその step を pin する
  (判定ロジックは既存スクリプトのまま = 重複ゼロ)。
- **施策 3**: doc 修正 6 件 + gate 2 本。
  - doc: AGENTS.md §セキュリティ不変条件へ不変条件 9/10 追記 + 番号対応の明記 /
    `.env.example` の参照先を `docs/auth-security-mechanisms.md §5` へ /
    `docs/architecture.md` の `CUSTOM_BINDER` 列挙に `{passkey}` /
    AGENTS.md・app-implement の検証コマンド列へ packages 系 3 本 /
    AGENTS.md へ**グローバルテストロックの導線**(待つ・heartbeat・kill 禁止の 3 点を要約 + runbook へリンク) /
    AGENTS.md の「4 軸」を 2 層構造へ更新 / `TRUSTED_PROXIES` runbook を
    AGENTS.md セキュリティ不変条件と README.md の文書表から辿れるようにする。
  - gate: `RouteBindingCustomBinderDocSyncTest` (定数 ↔ architecture.md、双方向) と
    `verification-commands-doc-sync.test.ts` (package.json の検証系 script ↔ AGENTS.md /
    app-implement SKILL.md、exempt は理由付き = `ScriptsReadmeInventoryTest` と同じ作法)。

### グループ C: 運用事故の根本原因除去 (施策 5 → 施策 4、この順序は必須)

- **施策 5 (先)**: `doc/reference/` の NFD 側 index entry 58 件を `git rm --cached` で除去し、
  `core.precomposeunicode = true` へ。**working tree のファイルは 1 つも触らない**
  (`--cached` の性質)。再発防止に `GitIndexNormalizationTest` を新設
  (index path を NFC 正規化したとき衝突が無いこと)。
  手順・事前確認・検証・ロールバックは詳細設計に明記する。
- **施策 4 (後)**: `scripts/ci/drop-test-db.php` に `--orphans` モードを追加する。
  - **DDL を実行するファイルを 1 本に固定する** (新しい生 DDL を書かない)。既存の
    `isDevDatabase()` / `isAllowedTestDatabase()` / `pgsqlDropDatabaseSql()` をそのまま使う。
  - 孤児判定は `Tests\Support\Ci\TestDatabaseEnv` に**純関数**として追加し
    (`orphanTestDatabases(list<string> $dbNames, list<string> $liveHashes)`)、Pest で固定する。
  - 生存 hash は `git worktree list --porcelain` の各 path の realpath から算出する
    (`/workspace` 自身を必ず含む)。**既定は dry-run**、`--apply` で初めて DROP する。
  - `teardown-worktree.sh` の dirty 失敗メッセージに「強制撤去したら
    `drop-test-db.php --orphans` を回すこと」を追記し、迂回経路にも回収導線を張る。
  - `scripts/README.md` の該当行を更新する (`ScriptsReadmeInventoryTest` が整合を強制)。

### グループ D: supply-chain (施策 7)

`packages/cli` の `undici` を `6.28.0` 以上へ (caret 範囲内 = lockfile 更新のみ)、
root の `eslint-plugin-better-tailwindcss` の厳密 pin を `4.7.0` へ
(`valibot@^1.4.2` を引き込むことをレジストリで確認済み)。
受入条件は `pnpm run audit:gate` が **advisory 0 / accept-risk 0** で緑。
lockfile 単独変更のため他グループと衝突させない。

## 期待効果

- **使命への貢献 (間接だが本質的)**: 本バッチは機能を増やさない。効くのは
  「SOP → シナリオ → 撮影」を実装する**次のサイクルの速度と信頼性**である。
  - 偽赤の除去 (施策 1・6): 日本語コメントを 1 行足しただけでゲートが落ちる状態を解消する。
    原因追跡に数時間を溶かす罠を、実装者が踏む前に潰す
  - カバレッジ穴の解消 (施策 2): bug-hunt が認証面 (登録/ログイン/削除/再認証/初回パスワード設定) を
    探索対象に戻す。**IDOR・詰みが最も出やすい面**が現在まるごと監査外
  - 正本の信頼回復 (施策 3): AGENTS.md だけを読んだエージェントが T103 の中核契約を知らないまま
    route を足す動線 (テスト赤 → 事後に規約を知る) を、テストファースト (思考原則 5) へ戻す
  - 運用事故の停止 (施策 4・5): 単調増加していた 221.9 MB の孤児 DB を回収し、
    増え続ける経路そのものを閉じる
- **定量目標**:
  - `scripts/bug-hunt-inventory-check.sh` → **exit 0**
  - `git ls-files doc/reference | wc -l` → **139** (= 実体数)、`git status` clean
  - 孤児 DB → **0 個** (生存 worktree の DB だけが残る)
  - `pnpm run audit:gate` → **Total advisories: 0**
  - 新規 gate 4 本 (`PcreUnicodeModifierGateTest` / `GitIndexNormalizationTest` /
    `RouteBindingCustomBinderDocSyncTest` / `verification-commands-doc-sync.test.ts`)
  - 既存の全ゲート (composer test 3014 / pnpm test 1202 / phpstan / pint / lint / typecheck) は緑維持

## 制約・前提

- **AGENTS.md 禁止事項 3 (dev DB への破壊操作をエージェント判断で実行しない)**: 施策 4 は
  新しい生 DDL を書かない。DROP を実行するのは既存の `drop-test-db.php` のみで、
  `DEV_DB_DENYLIST` (`app`) は無条件 skip、`TEST_DB_ALLOWLIST_PATTERN`
  (`/^app_test_[0-9a-f]{8}(_test_[0-9]+)?$/`) 一致のみ DROP。
  さらに**生存 worktree hash 突合**を第 3 の guard として重ねる。既定 dry-run。
- **AGENTS.md 禁止事項 1 (テストなしの実装完了)**: doc のみの施策も、機械強制できる 2 本は
  gate 化し、できないものは「既存スクリプト exit 0」「既存ゲートの緑維持」を受入条件に置く。
- **AGENTS.md §worktree 運用ルール**: 実装は worktree で行う。ただし施策 5 は git index を
  操作するため、worktree 作成時点で phantom deletion が出る可能性がある。
  詳細設計で**事前確認 (worktree の dirty 集合が想定 NFD 集合と一致すること) と
  main で実行する contingency** を定める。
- **施策 5 は working tree のファイルを 1 つも削除しない**。`git rm --cached` は index のみを
  操作するため、失敗しても `doc/reference/` の実体 139 ファイルは常に無傷。これが安全性の根拠。
- **施策 7 の `eslint-plugin-better-tailwindcss` は厳密 pin (`4.4.1`)**。pin を上げると
  ESLint ルールの挙動が変わりうるため、`pnpm lint` の緑維持を受入条件に含める。
- テストは T099 のグローバルロック経由で直列に走る (待ち時間が出るのは正常)。
- `Normalizer` (intl) が利用可能であることを確認済み (施策 5 の gate の前提)。

## スコープ外

- zod major 分裂の解消 (次サイクル TODO)
- PHP トークン解析器 8 本 / JS `.svelte` 列挙 8 本の共通基盤化 (次サイクル TODO)
- Pest 4→5 / PHPUnit 12→13 のメジャー更新 (Conditional のまま)
- markdown 相互参照リンクの機械検証 (費用対効果が合わない)
- Svelte `state_referenced_locally` 警告 7 件 / Vite `configLoader` 警告
- 行分割ヘルパの共通化 (gate で代替)
- `devnotes/` の肥大 (+121,305 行) への対処
- route 認可・middleware ゲート 8 本のインデックス整備 (tech-debt #8)

