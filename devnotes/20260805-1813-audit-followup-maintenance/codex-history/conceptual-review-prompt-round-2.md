Round 1 の指摘への対応を報告します。全 4 件の [Warning] と 2 件の [Suggestion] をすべて受け入れ、
概念設計を修正しました。

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 1

## [Warning] 施策 4: 孤児テスト DB 回収は「禁止事項 3」に近い領域

- 判断: **対応する**
- 根拠: 指摘のとおり `--apply` を用意する時点で DROP 経路である。「コマンドが安全か」ではなく
  「誰がどの条件で apply できるか」を設計に書けという指摘は正しい。特に
  **確認トークン**の提案は「dry-run で人間が読んだ集合」と「apply が実際に落とす集合」の
  一致を機械的に保証する。これは安全性の実質的な向上であり、over-engineering ではない。
- 対応内容:
  - `--apply` は**人間の明示指示がある場合のみ**実行可と概念設計に明記
  - dry-run が対象一覧 + `--confirm=<token>` を出力し、`--apply` はそのトークン一致を要求する
    (token = 対象 DB 名を昇順連結した文字列の sha1 先頭 8 桁 = 集合が変われば必ず不一致)
  - 出力に「DROP 対象 / 除外した DB と除外理由 / 生存 worktree hash 一覧」を必ず含める
  - denylist に `bug_hunt` / `bug_hunt_1..8` を明示追加
    (allowlist regex `^app_test_[0-9a-f]{8}(_test_[0-9]+)?$` で既に構造的に除外されるが、
    「bug-hunt 環境の DB は絶対に触らない」を意図として明示する二重防御)
  - setup/teardown-worktree.sh と**同一の lock ファイル** (`.claude/worktrees/.setup.lock`) を取る
    (worktree 作成中に「まだ DB を作っただけで worktree が無い」瞬間を孤児と誤判定する race を塞ぐ)

## [Warning] 施策 5: 「git rm --cached は working tree を触らないから安全」は論拠として不十分

- 判断: **対応する**
- 根拠: 指摘のとおり。`--cached` は「今この瞬間のローカル作業ツリーを壊さない」ことしか言えず、
  コミット後の他環境 checkout での消失を説明していない。安全性の本当の根拠は
  「落とす entry の内容が、残す entry に同一 blob で保存されていること」である。
- 対応内容: 実測で前提を検証したうえで論拠を書き換えた。**実測結果 (2026-08-05 18:13 時点)**:
  - index entry 197 / NFC 正規化衝突グループ **58 / 全グループがサイズ 2**
  - **blob が異なるグループ 0 件** (= 落とす NFD entry の内容は必ず NFC 側に同一 blob で残る)
  - **NFC 形の entry を持たないグループ 0 件** (= 「NFD 側にしか無い内容」は存在しない)
  - NFD entry 58 件の内訳: `doc/reference/mockups` 57 / `doc/reference/scenarios` 1。
    **コード/テストから参照されている `doc/reference/sample-sop/` は 1 件も含まれない**
    (`tests/Unit/Manual/SopTextExtractorTest.php:38` が参照する唯一の実コード依存は無関係)
  - 197 − 58 = **139** = 作業ツリーの実体数と一致
  この 4 つを**実装時の事前確認 (fail-fast) として手順に組み込む**。1 つでも崩れたら中止する。
- 追加対応:
  - 削除対象 manifest を `devnotes/{dir}/nfd-index-entries.txt` として残す
  - `core.precomposeunicode` はローカル設定でしかない点を明記し、**リポジトリ恒久対策は
    `GitIndexNormalizationTest` (gate) の方**であると位置づけを訂正する
  - 受入条件に `git status --porcelain=v1 -uall` の空 + 正規化衝突 0 を追加

## [Warning] 施策 5 → 施策 4 の順序依存は概ね正しいが、表現が強すぎる

- 判断: **対応する**
- 根拠: 指摘のとおり。施策 4 の純関数・テスト・dry-run は施策 5 と独立に実装できる。
  真に必要なのは「apply と完了判定の前に 5 が終わっていること」だけ。
- 対応内容: 「グループ C の内部順序 5 → 4 は必須」を
  「**実装順は 4(純関数・テスト・dry-run) → 5 → 4(apply) を許容する。
  必須なのは 4 の apply と『孤児 0』の完了判定より前に 5 が完了していること**」へ緩和。

## [Warning] gate 化条件 (a) は例外を許すべき

- 判断: **対応する**
- 根拠: 指摘のとおり。AGENTS.md のセキュリティ不変条件は「実害が出る前に Architecture テストで
  強制する」方針で運用されており、条件 (a) をそのまま書くと本リポジトリの既存方針と矛盾する。
- 対応内容: gate 条件に例外節を追加 —
  「ただしセキュリティ不変条件・破壊的操作の guard・課金冪等性・cross-org 防止など
  **発生時の被害が回復不能または大きいもの**は、ドリフト実績が無くても gate 化してよい」。

## [Suggestion] C を C1〜C4 に段階化

- 判断: **対応する**
- 根拠: グループ C だけ破壊リスクの質が違う (git index / DB DROP)。段階を明示すると
  「どこで人間の確認が入るか」が設計上明確になり、実装者が勝手に apply へ進めない。
- 対応内容: C1 (検証 + manifest) / C2 (index 整理 + gate) / C3 (孤児 dry-run) /
  C4 (人間確認後の apply) を概念設計に明記。

## [Suggestion] 定量目標に「確認方法」を足す

- 判断: **対応する**
- 対応内容: 定量目標の表に「確認コマンド」と「対象外としたもの (= 残ってよいもの)」の列を追加。

## [Suggestion] 使命との整合性 / 実現可能性 / 型安全性

- 判断: **見送る** (指摘なしの確認事項)
- 根拠: いずれも [Suggestion] で修正要求を含まない。


## 補足: 施策 5 の前提を実測で検証した結果

指摘 2 を受けて、削除対象の安全性を実測で検証しました (`git ls-files -s -z doc/reference` を
Python の unicodedata で解析):

- index entry 総数: 197
- NFC 正規化衝突グループ: 58 (**全てサイズ 2**、3 重複以上なし)
- **blob が異なるグループ: 0 件**
- **NFC 形の entry を持たないグループ: 0 件** (= NFD 側にしか無い内容は存在しない)
- 197 - 58 = 139 = 作業ツリーの実体数と一致
- NFD entry 58 件の内訳: `doc/reference/mockups` 57 / `doc/reference/scenarios` 1。
  **コードから参照されている `doc/reference/sample-sop/`** (`tests/Unit/Manual/SopTextExtractorTest.php:38`)
  **は 1 件も含まれない**

削除対象 manifest (blob hash + NFD path の 58 行) は
`devnotes/20260805-1813-audit-followup-maintenance/nfd-index-entries.txt` に保存済みです。
実装時にはこの 4 前提を再検証し、1 つでも崩れたら中止します。

## 修正後の概念設計

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
>
> **例外 (a を免除する条件)**: セキュリティ不変条件・**破壊的操作の guard**・課金冪等性・
> cross-org 防止など、**発生したときの被害が回復不能または大きいもの**は、
> ドリフト実績が無くても gate 化してよい (本リポジトリの既存方針。AGENTS.md
> §セキュリティ不変条件はすべて実害の前に Architecture テストで強制されている)。

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

4 グループへ分割する。グループ C だけは破壊リスクの質が違うため、内部を 4 段階に切って
**人間の確認が入る位置**を設計として固定する。

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

### グループ C: 運用事故の根本原因除去 (施策 5・施策 4)

破壊リスクの質が他グループと異なる (git index 操作 / DB DROP) ため、**4 段階**に分ける。
人間の確認が入る位置を設計として固定する。

| 段階 | 内容 | 人間の確認 |
|---|---|---|
| **C1** | 施策 5 の事前検証 + 削除対象 manifest の生成 (index を変更しない) | 不要 |
| **C2** | 施策 5 の適用 (index entry 整理 + `precomposeunicode` + gate 新設) | 不要 (自動検証で fail-fast) |
| **C3** | 施策 4 の実装 + 孤児 **dry-run** (DROP しない) | 不要 |
| **C4** | 施策 4 の **`--apply`** (実 DROP) | **必須** |

**順序の要件**: 施策 4 の純関数・テスト・dry-run は施策 5 より前でも実装できる
(実装順 C3 → C1 → C2 → C4 も許容する)。**必須なのは、施策 4 の `--apply` と
「孤児 DB 0 件」の完了判定より前に施策 5 が完了していること**である
(5 を直さないまま掃除だけすると、teardown 不全というシグナルを消して再発させるだけになる)。

- **施策 5**: `doc/reference/` の NFD 側 index entry 58 件を `git rm --cached` で除去し、
  `core.precomposeunicode = true` へ。再発防止に `GitIndexNormalizationTest` を新設
  (index path を NFC 正規化したとき衝突が無いこと)。
  - **安全性の根拠 (訂正)**: 「`--cached` は作業ツリーを触らないから安全」では**不十分**である
    (index から落とした entry はコミット後に他環境の checkout から消えるため)。
    正しい根拠は「**落とす NFD entry の内容が、残す NFC entry に同一 blob で保存されていること**」。
    これを実測で確認済み (2026-08-05 18:13 の main):

    | 検証項目 | 実測 | 意味 |
    |---|---|---|
    | index entry 総数 | 197 | — |
    | NFC 正規化衝突グループ | **58 / 全てサイズ 2** | 3 重複以上は無い |
    | **blob が異なるグループ** | **0 件** | 落とす内容は必ず同一 blob で残る |
    | **NFC 形 entry を持たないグループ** | **0 件** | 「NFD 側にしか無い内容」は存在しない |
    | 197 − 58 | **139** | 作業ツリーの実体数と一致 |
    | NFD entry の所在 | `mockups` 57 / `scenarios` 1 | コード参照のある `doc/reference/sample-sop/` は **0 件** |

    この 4 条件は**実装時の事前確認 (fail-fast) として手順に組み込み、1 つでも崩れたら中止する**。
  - `core.precomposeunicode` は**ローカル設定**であってリポジトリの恒久対策にはならない。
    恒久対策は `GitIndexNormalizationTest` の方である (この位置づけを設計に明記する)。
  - 手順・事前確認・検証・ロールバックは詳細設計に明記する。
    削除対象 manifest は `devnotes/{dir}/nfd-index-entries.txt` として残す。
- **施策 4**: `scripts/ci/drop-test-db.php` に `--orphans` モードを追加する。
  - **DDL を実行するファイルを 1 本に固定する** (新しい生 DDL を書かない)。既存の
    `isDevDatabase()` / `isAllowedTestDatabase()` / `pgsqlDropDatabaseSql()` をそのまま使う。
  - 孤児判定は `Tests\Support\Ci\TestDatabaseEnv` に**純関数**として追加し
    (`orphanTestDatabases(list<string> $dbNames, list<string> $liveHashes)`)、Pest で固定する。
  - 生存 hash は `git worktree list --porcelain` の各 path の realpath から算出する
    (`/workspace` 自身を必ず含む)。
  - **apply の運用条件 (非交渉)**:
    - 既定は **dry-run**。`--apply` は**人間の明示指示があるときだけ**実行してよい
      (エージェント判断での実行を禁止。AGENTS.md 禁止事項 3 の趣旨)
    - dry-run が「DROP 対象 / 除外した DB と除外理由 / 生存 worktree hash 一覧 /
      `--confirm=<token>`」を出力する。`--apply` は**この token の一致を要求する**
      (token は対象 DB 名を昇順連結した文字列の sha1 先頭 8 桁 = **集合が変われば必ず不一致**)。
      これにより「人間が読んだ集合」と「実際に落とす集合」の一致が機械的に保証される
    - 三重 guard: 名前 allowlist regex / dev DB denylist (`app` に加え `bug_hunt` /
      `bug_hunt_1..8` を明示追加) / 生存 worktree hash 突合
    - `setup-worktree.sh` / `teardown-worktree.sh` と**同一の lock**
      (`.claude/worktrees/.setup.lock`) を取る (worktree 作成中の
      「DB は作られたが worktree はまだ無い」瞬間を孤児と誤判定する race を塞ぐ)
    - **同一 PostgreSQL を共有する別クローン**の DB は誤って孤児判定されうる。
      この限界を出力とドキュメントに明記する (だから dry-run 既定 + 人間確認が必要)
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
- **定量目標** (確認方法と「残ってよいもの」を併記する):

| 目標 | 確認コマンド | 残ってよいもの (対象外) |
|---|---|---|
| bug-hunt インベントリ drift 解消 | `bash scripts/bug-hunt-inventory-check.sh` → **exit 0** | `OUT_OF_SCOPE_PREFIXES` 該当 route |
| index の正規化衝突 0 | `git ls-files doc/reference \| wc -l` → **139** / 衝突検出スクリプト → 0 | — |
| 作業ツリーが clean | `git status --porcelain=v1 -uall` → **空** | — |
| NFC 側 blob が不変 | 施策前後で NFC entry の blob hash 集合が一致 | — |
| 孤児 DB 0 | `php scripts/ci/drop-test-db.php --orphans` → 対象 0 件 | **生存 worktree hash の DB** (現状 `app_test_8af22c44` + worker 4) / **dev DB `app`** / **`bug_hunt*`** / allowlist regex 外の全 DB |
| advisory 0 | `pnpm run audit:gate` → **Total advisories: 0** / accept-risk 0 | — |
| 新規 gate 4 本が緑 | `PcreUnicodeModifierGateTest` / `GitIndexNormalizationTest` / `RouteBindingCustomBinderDocSyncTest` / `verification-commands-doc-sync.test.ts` | — |
| 既存ゲート緑維持 | `composer test` (3014) / `pnpm test` (1202) / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` | — |

## 制約・前提

- **AGENTS.md 禁止事項 3 (dev DB への破壊操作をエージェント判断で実行しない)**: 施策 4 は
  新しい生 DDL を書かない。DROP を実行するのは既存の `drop-test-db.php` のみで、
  `DEV_DB_DENYLIST` (`app` + `bug_hunt*` を明示追加) は無条件 skip、`TEST_DB_ALLOWLIST_PATTERN`
  (`/^app_test_[0-9a-f]{8}(_test_[0-9]+)?$/`) 一致のみ DROP。
  さらに**生存 worktree hash 突合**を第 3 の guard として重ねる。既定 dry-run で、
  `--apply` は**人間の明示指示 + dry-run が出した confirm token の一致**を要求する
  (エージェント判断だけでは構造的に apply できない)。
- **AGENTS.md 禁止事項 1 (テストなしの実装完了)**: doc のみの施策も、機械強制できる 2 本は
  gate 化し、できないものは「既存スクリプト exit 0」「既存ゲートの緑維持」を受入条件に置く。
- **AGENTS.md §worktree 運用ルール**: 実装は worktree で行う。ただし施策 5 は git index を
  操作するため、worktree 作成時点で phantom deletion が出る可能性がある。
  詳細設計で**事前確認 (worktree の dirty 集合が想定 NFD 集合と一致すること) と
  main で実行する contingency** を定める。
- **施策 5 の安全性の根拠は「同一 blob の残存」である** (`--cached` が作業ツリーを触らないこと
  ではない)。落とす NFD entry 58 件はすべて、同一 blob の NFC entry を対で持つことを実測済み
  (blob 不一致 0 / NFC 相手なし 0)。作業ツリーが無傷なのは作業中断時の回復を容易にする副次的性質。
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


上記の修正で Round 1 の指摘が解消できているかを確認してください。
全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。
