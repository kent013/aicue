Round 3 の指摘 3 件をすべて受け入れ、概念設計を修正しました。

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 3

## [Warning] 「TOCTOU を閉じる」の適用範囲が広すぎる (別クローンとは lock を共有しない)

- 判断: **対応する** (範囲を明記 + lock 保持区間を明記。cross-clone advisory lock は
  **意図的な非目標**として理由付きで宣言する)
- 根拠: 指摘は正しい。`.claude/worktrees/.setup.lock` はファイルシステム上の 1 クローンに閉じた
  lock であり、別クローンの setup/teardown とは排他しない。
  PostgreSQL advisory lock なら cross-clone まで排他できるが、それを
  `ensure-test-db.php` に入れると **CI・全 worktree の test 実行前処理がロック待ちで
  ハングしうる経路**を新設することになり、本バッチ (偽赤を減らす) の目的と逆行する。
  cross-clone の防御は lock ではなく **provenance 分類 (foreign 判定) + `--protect-hash` +
  人間承認**の 3 段で行う設計になっており、そちらが正しい防御線である。
- 対応内容:
  - 「TOCTOU を閉じる」→「**同一クローンの協調スクリプト (setup / teardown / sweep) 間の
    TOCTOU を閉じる**」と範囲を明記
  - **lock は token 再計算の直前に取得し、全 DROP の完了まで保持する**ことを明記
  - 「別クローンとは lock を共有しない」ことと、その場合の防御が
    foreign 分類 / `--protect-hash` / 人間承認であることを制約として明記
  - PostgreSQL advisory lock による cross-clone 排他は**スコープ外**とし、理由を記す

## [Warning] 「DDL を実行するファイルを 1 本に固定」と `COMMENT ON DATABASE` 追加の矛盾

- 判断: **対応する** (方針の表現が不正確だったので訂正する)
- 根拠: 指摘のとおり。元の表現は「あらゆる DDL を 1 本に固定」と読めるが、実際に守りたいのは
  **不可逆な破壊操作 = DROP** の一元化である。`ensure-test-db.php` は既に
  `CREATE DATABASE` を実行しており、`COMMENT ON DATABASE` は同じ DB に対する
  非破壊のメタデータ付与なので、置き場所として自然。
- 対応内容:
  - 方針を「**DROP DDL を実行するファイルは `scripts/ci/drop-test-db.php` の 1 本に限定する**」へ訂正
  - `COMMENT ON DATABASE` は既存の `pgsqlQuoteIdentifier()` (識別子) と
    PDO の文字列クォート (リテラル) で生成する専用ヘルパ
    `pgsqlCommentDatabaseSql()` を `pgsql_test_conn.php` に置く (既存の
    `pgsqlCreateDatabaseSql` / `pgsqlDropDatabaseSql` と同じ作法)
  - **comment は base DB にのみ付き、hash グループ全体の出自として扱う**。
    base が不在で worker DB (`_test_N`) だけが残っている場合は **unlabeled** になる、と明記
  - provenance の取得・パース・分類に**単体テストを受入条件として追加**
- 追加対応 ([Suggestion] より): **DB comment は信頼境界ではなく分類材料**であり、
  allowlist regex / dev DB denylist / 生存 hash 突合 / confirm token を**置き換えない補助情報**
  であることを明記する (comment は誰でも書き換えられるため、単独では guard になり得ない)

## [Warning] 純関数のシグネチャが旧設計のまま (分類入力が拡張されている)

- 判断: **対応する**
- 根拠: 指摘のとおり。入力は DB 名 + 生存 hash だけでなく provenance / protected hash /
  `--include-unlabeled` へ拡張されており、`list<string>` 2 本では表現できない。
  PHPStan level 10 で意味のある型を付けるには値オブジェクト化が必要。
- 対応内容: 概念設計に型を明記する。
  - `TestDatabaseCandidate` (readonly): `name` / `hash` / `isWorker` / `provenancePath (?string)`
  - `TestDatabaseClassification` (enum): `Live` / `Foreign` / `Orphan` / `Unlabeled` / `Protected`
  - `TestDatabaseDecision` (readonly): `candidate` / `classification` / `reason` / `shouldDrop`
  - 純関数:
    `classifyTestDatabases(list<TestDatabaseCandidate>, list<string> $liveHashes,
    list<string> $protectedHashes, bool $includeUnlabeled): list<TestDatabaseDecision>`
  - **境界で正規化する**: `pg_database` の問い合わせ結果と `git worktree list --porcelain` の
    出力は `mixed` 由来なので、値オブジェクト生成時に検証して `list<...>` へ正規化してから
    純関数へ渡す (PHPStan level 10 適合の要件として明記)

## [Suggestion] main contingency / 実測値 55 対 58

- 判断: **対応不要** (Round 2 で解消済みと確認された)


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
| 5 | `doc/reference/` NFC/NFD 重複 | `git ls-files` = **197** / 実体 = **139** / **NFC 正規化衝突グループ = 58 (全て size 2・blob 一致)** / `core.precomposeunicode = false` を再確認。※監査レポートの「重複 blob 55」は**別指標** (index 内で 2 回以上出現する distinct blob hash 数。出現内訳 2 回×42 / 3 回×3 / 4 回×7 / 6 回×3 で、NFC/NFD 由来でない「同一内容・別名」も数える)。**判定に使うのは 58 の側**であり、両者は矛盾しない |
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

    **C1 の fail-fast 判定に使うのはこの 4 条件だけ** (衝突 58 / blob 一致 58 / NFC 相手なし 0 /
    197−58=139)。監査レポートの「重複 blob 55」は別指標なので判定に使わない。
    1 つでも崩れたら中止する。
  - `core.precomposeunicode` は**ローカル設定**であってリポジトリの恒久対策にはならない。
    恒久対策は `GitIndexNormalizationTest` の方である (この位置づけを設計に明記する)。
  - 手順・事前確認・検証・ロールバックは詳細設計に明記する。
    削除対象 manifest は `devnotes/{dir}/nfd-index-entries.txt` として残す。
- **施策 4**: `scripts/ci/drop-test-db.php` に `--orphans` モードを追加する。
  - **DROP DDL を実行するファイルは `scripts/ci/drop-test-db.php` の 1 本に限定する**
    (新しい DROP 経路を作らない)。既存の `isDevDatabase()` / `isAllowedTestDatabase()` /
    `pgsqlDropDatabaseSql()` をそのまま使う。
    非破壊のメタデータ付与 (`COMMENT ON DATABASE`) は、既に `CREATE DATABASE` を持つ
    `ensure-test-db.php` に置く (SQL 生成は `pgsql_test_conn.php` の
    `pgsqlCommentDatabaseSql()` へ集約し、識別子は既存の `pgsqlQuoteIdentifier()`、
    リテラルは PDO のクォートで組み立てる)。
  - 孤児判定は `Tests\Support\Ci` に**型付きの純関数**として置き、Pest で固定する:
    - `TestDatabaseCandidate` (readonly): `name` / `hash` / `isWorker` / `provenancePath (?string)`
    - `TestDatabaseClassification` (enum): `Live` / `Foreign` / `Orphan` / `Unlabeled` / `Protected`
    - `TestDatabaseDecision` (readonly): `candidate` / `classification` / `reason` / `shouldDrop`
    - `classifyTestDatabases(list<TestDatabaseCandidate> $candidates, list<string> $liveHashes,
      list<string> $protectedHashes, bool $includeUnlabeled): list<TestDatabaseDecision>`
    - **境界で正規化する**: `pg_database` の問い合わせ結果と `git worktree list --porcelain` の
      出力はいずれも `mixed` 由来なので、値オブジェクト生成時に検証してから純関数へ渡す
      (PHPStan level 10 適合の要件)
  - 生存 hash は `git worktree list --porcelain` の各 path の realpath から算出する
    (`/workspace` 自身を必ず含む)。
  - **出自の機械記録 (別クローン誤判定の根本解決)**: DB 名の hash だけでは
    「削除済み worktree の残骸」と「同一 PostgreSQL を共有する**別クローンの生存 DB**」を
    区別できない。区別できないまま「由来不明は除外」を適用すると、現存 17 個はすべて
    由来不明なので 1 つも掃除できず施策が無意味になる。したがって**区別できるようにする**:
    - `scripts/ci/ensure-test-db.php` が base DB 作成時に
      **`COMMENT ON DATABASE <base> IS '<worktree の realpath>'`** を記録する (1 文追加)。
      PostgreSQL 標準の出自記録機構をそのまま使う
    - sweep は `shobj_description` で出自を読み、hash ごとに 4 分類する:

      | 分類 | 条件 | 既定の扱い |
      |---|---|---|
      | live | hash が生存 worktree hash 集合に含まれる | **保持** |
      | foreign | ラベルあり / その path が実在する (= 別クローンが生きている) | **保持** + 報告 |
      | orphan (確度高) | ラベルあり / その path が実在しない | DROP 候補 |
      | unlabeled (確度低) | ラベルなし (本施策以前の legacy / base 不在の worker のみ) | **既定は除外**。`--include-unlabeled` 明示時のみ候補 |

    - comment は **base DB にのみ付き、hash グループ全体の出自として扱う**。
      base が不在で worker DB (`_test_N`) だけが残っている場合は **unlabeled** になる
    - **comment は信頼境界ではなく分類材料である**。誰でも書き換えられるため単独では guard に
      ならない。allowlist regex / dev DB denylist / 生存 hash 突合 / confirm token を
      **置き換えない補助情報**として位置づける
    - `--protect-hash=<hash>` を**複数指定可**にし、既知の別クローンを明示保護できるようにする
    - dry-run 出力に「**所有元を確認できない hash**」を明示する
    - **現存 17 個はすべて unlabeled** なので、初回の回収は
      `--include-unlabeled` + `--confirm` + **人間の明示指示**の 3 点が揃って初めて実行される
    - provenance の取得・パース・分類には**単体テストを受入条件として課す**
  - **apply の運用条件 (非交渉)**:
    - 既定は **dry-run**。`--apply` は**人間の明示指示があるときだけ**実行してよい
      (エージェント判断での実行を禁止。AGENTS.md 禁止事項 3 の趣旨)
    - dry-run が「DROP 対象 / 除外した DB と除外理由 / 生存 worktree hash 一覧 /
      所有元不明 hash / `--confirm=<token>`」を出力する
    - token = **canonical JSON**
      `{"orphans":[昇順],"live_hashes":[昇順],"protected":[昇順]}` の **SHA-256 全長**
      (JSON 配列なので要素境界が曖昧にならない / 先頭切り詰めをしない)
    - **`--apply` は lock 取得後に DB 一覧と worktree 一覧を再取得して token を再計算し、
      `--confirm` と一致するときだけ DROP する**。不一致なら中止して新 token を表示する。
      token は「指紋」ではなく **lock 下のスナップショット照合**である
    - 三重 guard: 名前 allowlist regex / dev DB denylist (`app` に加え `bug_hunt` /
      `bug_hunt_1..8` を明示追加) / 生存 worktree hash 突合
    - `setup-worktree.sh` / `teardown-worktree.sh` と**同一の lock**
      (`.claude/worktrees/.setup.lock`) を **token 再計算の直前に取得し、
      全 DROP の完了まで保持する** (worktree 作成中の「DB は作られたが worktree はまだ無い」
      瞬間を孤児と誤判定する race を塞ぐ)
    - **排他の適用範囲 (誇張しない)**: この lock が閉じるのは
      **同一クローンの協調スクリプト (setup / teardown / sweep) 間の TOCTOU だけ**である。
      `.setup.lock` はファイルシステム上の 1 クローンに閉じており、
      **別クローンとは共有されない**。cross-clone の防御は lock ではなく
      **foreign 分類 + `--protect-hash` + 人間承認**の 3 段で行う
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
| 孤児 DB 0 | `php scripts/ci/drop-test-db.php --orphans` → 対象 0 件 | **生存 worktree hash の DB** (現状 `app_test_8af22c44` + worker 4) / **dev DB `app`** / **`bug_hunt*`** / **`--protect-hash` 指定分と foreign 分類の DB** / allowlist regex 外の全 DB |
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
- **AGENTS.md §worktree 運用ルール**: 実装は worktree で行う。**施策 5 (C2) も task worktree の
  branch/index で完結させることを原則かつ必須とする** (main 直接実装は禁止事項であり、
  「うまくいかなければ main で」という代替経路は用意しない)。worktree 作成時点で
  phantom deletion が出る可能性があるため、詳細設計で「worktree の dirty 集合が
  想定 NFD 集合と一致すること」の事前確認を定める。**worktree 上で完結できないことが
  実証された場合は、通常の contingency ではなく人間の明示的な例外承認を要する停止条件**
  として扱う (エージェント判断で main へ逃げない)。
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
- **PostgreSQL advisory lock による cross-clone 排他**: `ensure-test-db.php` に共有 advisory lock を
  入れれば別クローンとも排他できるが、**CI と全 worktree のテスト前処理にロック待ちハングの
  経路を新設する**ことになり、「偽赤を減らす」という本バッチの目的と逆行する。
  cross-clone は foreign 分類 + `--protect-hash` + 人間承認で守る (上記)


Round 3 の 3 点 (TOCTOU の適用範囲 / DDL 方針の矛盾 / 純関数シグネチャの型) が解消できているかを
確認し、全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。
