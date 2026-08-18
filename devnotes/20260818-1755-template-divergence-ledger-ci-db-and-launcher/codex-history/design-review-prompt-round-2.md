## Round 1 の指摘への対応

対応マトリクスの要点は次のとおり。全文は
`devnotes/20260818-1755-template-divergence-ledger-ci-db-and-launcher/codex-history/design-review-decisions-round-1.md`。

### [Critical] D31 の登録理由が「互換・UX を理由にした逸脱は記録せず是正する」と衝突する

**一部反論し、一部対応した。**

反論の部分。登録簿の「業務要件起因」を製品ドメインへ限定する読みは、既存 28 件の運用と
合わない。実測で確かめた 3 件を挙げる。

- D10 (テストレーンのグローバルロック): 理由は「実装を必ず worktree で行うため同一マシンで
  複数のテストレーンが同時に走るのが常態で、奪い合う資源の作用域がマシン全体である」
- D11 (svelte-no-undef-gate を別実装): 理由は「同じ不変条件を守る実装がテンプレート側にあるが
  手元で読めないため、実装を待たずに設定の静的検査で先に固定した」
- D21 (bug-hunt の自己検証の実行点): 理由は「CI の責務を同期検査に限る裁定があるため、
  専用ステップではなくテストの配線へ載せた」

いずれも製品ドメインではなく**この開発の進め方**が要求する要件である。D31 だけを別の
物差しで測る根拠が見当たらない。加えて、この登録が正当化するのは「別実装であること」であって
「正典へ寄せないこと」ではない (寄せる判断は `監視中` の期限までに行い、寄せたら登録ごと消す)。
保留は「未登録のまま」と同じ状態で、登録簿の「登録するか迷ったら登録する。誤登録は削除で
是正できるが登録漏れには気付けない」に反する。

対応の部分。理由の書き出しが「環境差で起動できない」という互換・UX に読める形だったので、
D11 と同型の書き方 (正典側に実装が無く手元でも読めなかったので、同じ不変条件を別実装で
先に固定した) を主に、環境差を従にした。あわせて「登録してよいのか (レビューで割れた論点)」の
節を新設し、この反論を設計書に残した。

### [Warning] D30 の「DROP DDL の実行点は 1 本」を挙げたテストが保証していない

修正案 2 を採った。不変条件を「孤児の回収も `drop-test-db.php` の中の同じ DROP の境界へ
合流すること」へ狭め、「保証しないもの」に「リポジトリ全体で DROP の実行点が 1 本であることを
走査する検査は持たない」を足した。

### [Warning] 「DB 名が worktree 単位のまま」に対応する検査が特定できない

検査は実在した (`tests/Unit/Ci/TestDatabaseEnvTest.php`) ので挙げ足した。
不変条件も「テスト DB 名が worktree の realpath から決まること」へ書き直した。

### [Warning] 対象パスの完全性が立証されていない

正典を実読して確かめたところ指摘が当たっていた。`tests/Support/Ci/TestDatabaseCandidate.php` /
`TestDatabaseClassification.php` / `TestDatabaseDecision.php` は正典に存在せず、
`tests/Support/Ci/TestDatabaseEnv.php` は正典にもあるが T114 で 208 行変わっている。
D30 の対象パスを 7 件へ広げ、「対象パスの選び方」の節で T114 が触った全ファイルを
対象パス / 関連欄 / どちらでもない に分類した。基準は「実行される経路に含まれるものを
対象パスにし、それを検査する側は関連欄に置く」。D31 も同じ基準で `scripts/claude` 1 件とし、
理由を節にした。

### [Warning] 「9 ケース (W1 から W8)」の対応が読めない

実測すると W6 だけが 2 ケース (状態表示行が有る場合と無い場合) で 8 要件 9 ケースだった。
「W1 から W8 の 8 要件を 9 つのケースで固定する (W6 だけ 2 ケース)」へ直した。

### [Warning] テスト計画が AGENTS.md の検証コマンド一覧と一致していない

一覧へそろえた (`composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` /
`pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` /
`pnpm build:packages` / `pnpm test:packages`)。

### [Warning] D31 を承認できないので固定値 30 も確定できない

D31 は取り下げず理由の書き方を直したので 30 のままとした。

### [Suggestion] 2 件

「17 個 / 221.9 MB」に「2026-08-05 の監査時点で」を併記した。`sort -V` は
「本変更より前からある前提であり、無い環境で動くことは保証の対象にしていない」へ書き直した。

---

## 修正後の詳細設計書 (全文)

# 詳細設計: テンプレート乖離台帳への 2 件の登録 (テスト DB の回収経路 / 起動ラッパ)

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  (撮影者・教える人のスキルに品質を依存させない)。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) /
> 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告 (不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作 (`migrate:fresh` 等) をエージェント判断で実行すること
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用 (成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

本施策はアプリのコードを 1 行も変えない (文書 2 本と Architecture テストの固定値 1 つだけ)。
それでも次は守る。

- **PHPStan level 10** 必須 (`composer phpstan`)。変更するのは `const int` 相当の固定値のみ
- **Pest**。登録簿の形式は `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が見る
- 検査を緩めない。件数の固定値は**登録を足したので直す**のであって、赤を消すために直すのではない

## 概念設計リファレンス

`devnotes/20260818-1755-template-divergence-ledger-ci-db-and-launcher/conceptual-design.md`
(Codex 概念設計レビュー Round 2 で APPROVED)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 明示件数を 30 件へ直す | `docs/template-divergence.md` | 高 |
| 2 | D30 を追記する (テスト DB の回収経路) | `docs/template-divergence.md` | 高 |
| 3 | D31 を追記する (起動ラッパ) | `docs/template-divergence.md` | 高 |
| 4 | 検査側の固定件数を 30 へ直す | `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` | 高 |
| 5 | 追従の遅れの追跡先を作る | `docs/worktree-isolation-strategy.md` | 中 |

3 点一致 (明示行 / 見出しの実数 / 検査側の固定値) は施策 1・2・3・4 が**同じ変更でそろって**
成立する。片方だけ入れると `TD12` で赤くなる (それが正しい挙動である)。

## 施策 1: 明示件数を 30 件へ直す

### 変更箇所

- ファイル: `docs/template-divergence.md` (11 行目)

### 現行

```
登録エントリ: 28 件
```

### 変更後

```
登録エントリ: 30 件
```

書式は `DivergenceLedgerParser::DECLARED_COUNT` (`/^登録エントリ: (\d+) 件$/u`) の
完全一致で、囲みの外に**ちょうど 1 本**だけ存在すること。行を増やさない。

## 施策 2: D30 を追記する (テスト DB の回収経路)

### 変更箇所

- ファイル: `docs/template-divergence.md` の末尾 (D29 の後ろ。`---` で区切る)

### 実測 (登録の根拠)

本アプリ HEAD `ae82d034` / 正典 `laravel-claude-template@93e91e36`:

| ファイル | 本アプリ | 正典 |
|---|---|---|
| `scripts/ci/drop-test-db.php` | 25878 バイト | 2508 バイト |
| `scripts/ci/ensure-test-db.php` | 2601 バイト | 8472 バイト |
| `scripts/ci/pgsql_test_conn.php` | 6446 バイト | 6193 バイト |

上積みを入れたのは aicue:T114 (`829a78c2`、2026-08-05)。

### 追記する本文 (この文字列をそのまま入れる)

```
## D30 テスト DB の作成と回収に出自の記録と孤児の分類を上積みする

| 行 | 内容 |
|---|---|
| 対象パス | `scripts/ci/drop-test-db.php` / `scripts/ci/ensure-test-db.php` / `scripts/ci/pgsql_test_conn.php` / `tests/Support/Ci/TestDatabaseEnv.php` / `tests/Support/Ci/TestDatabaseCandidate.php` / `tests/Support/Ci/TestDatabaseClassification.php` / `tests/Support/Ci/TestDatabaseDecision.php` |
| 業務要件起因の説明 | 実装を必ず worktree で行う進め方のため、テスト DB 名を worktree の realpath の hash から作っている。worktree が検証なしで強制撤去されると hash を再現できず、引数なしの回収では二度と落とせない孤児 DB が積み上がる (2026-08-05 の監査時点で 17 個 / 221.9 MB) |
| 揃え続ける不変条件と保証機構 | 孤児の回収も `drop-test-db.php` の中の同じ DROP の境界へ合流すること、dev DB の拒否と allowlist の再検査が `TestDatabaseEnv` の既存実装を共有すること、テスト DB 名が worktree の realpath から決まること。`tests/Unit/Ci/DropTestDbScriptTest.php` (危険な名前が実行境界へ到達しない) と `tests/Unit/Ci/TestDatabaseClassificationTest.php` (分類の優先順位と確認用の値の照合) と `tests/Unit/Ci/TestDatabaseProvenanceTest.php` (出自の記録が冪等で best-effort) と `tests/Unit/Ci/TestDatabaseEnvTest.php` (名前が worktree ごとに変わり同じ worktree では変わらない) が固定する |
| 再判定の条件 | 正典が同じ回収経路を取り込んだとき。または実装を worktree で行う進め方をやめてテスト DB 名が worktree に依存しなくなったとき |
| 決めた日 | 2026-08-05 |
| 決めた人 | 開発者 |
| 根拠 | T114 |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 基点 DB の作成 | 不在なら CREATE する | 同じ |
| 出自の記録 | 持たない | `COMMENT ON DATABASE` へ worktree の realpath を作成時・既存時の両方で記録する (非破壊 DDL。付与失敗は無視する) |
| 回収の入口 | 引数なしの 1 経路だけ (現 worktree の基点と worker DB) | それに加えて `--orphans` の列挙と `--apply` |
| 孤児の扱い | 経路が無い (hash を再現できないので落とせない) | SELECT だけで `Protected` `Live` `Foreign` `Orphan` `Unlabeled` の順に分類し dry-run で列挙する |
| 削除の決め方 | 名前の一致で自動 | 分類だけでは決めない。`--include-hash` で人が 1 つずつ名指しし、`--confirm` の値を lock 取得後に再計算して照合する |
| DROP DDL の実行点 | `drop-test-db.php` の 1 本 | 同じ (`--orphans` は入口を足すだけ) |
| 基点 DB のスキーマ更新 | 正典 HEAD は `migrate` まで担う (家系の裁定 AG-135) | 持たない。本登録の対象外 (下の「この登録が扱わない範囲」) |

### なぜ正当な差分か (logic-driven)

本アプリの実装は必ず worktree で行う (AGENTS.md §worktree 運用ルール)。テスト DB 名は
`TestDatabaseEnv::workrootHash()` = worktree root の realpath の sha1 先頭 8 桁から作るので、
**worktree が消えると名前を再現できない**。teardown が `doc/reference/` の NFC/NFD 問題で
常時失敗していた時期に `git worktree remove --force` での迂回が常態化し、
回収経路を通らない孤児 DB が単調増加した (2026-08-05 の監査時点で 17 個 / 221.9 MB)。

テンプレートの `drop-test-db.php` は「今いる worktree の基点と worker DB を落とす」だけなので、
この事象に手が届かない。届かせるには DB 自身に出自を持たせるしかなく、
非破壊の `COMMENT ON DATABASE` を選んだ。分類は SELECT だけで行い、DROP DDL の実行点は
1 本のまま据え置いた — **危険な操作の入口を増やさずに、判断材料だけを増やす**形である。

### 揃えている不変条件 (これは保証し続ける)

> 「孤児の回収も `drop-test-db.php` の中の同じ DROP の境界へ合流する。dev DB の拒否
> (`isDevDatabase()`) と allowlist の再検査 (`isAllowedTestDatabase()`) と DROP 文の組み立て
> (`pgsqlDropDatabaseSql()`) は既存実装をそのまま共有する」

- 分類の優先順位は `Protected` `Live` `Foreign` `Orphan` `Unlabeled` の順で、
  **`Live` が `Foreign` や `Orphan` より先**である。出自のコメントを細工しても生存 DB は落とせない
- 削除可否を分類だけで決めない。`Orphan` も `Unlabeled` も `--include-hash` で
  人が 1 つずつ名指ししない限り 1 件も落ちない (一括の指定は意図的に用意していない)
- `--apply` は確認用の値を `.claude/worktrees/.setup.lock` の取得後に再計算して照合する
  (指紋ではなく lock 下のスナップショット照合)

### この登録が扱わない範囲 (遅れであって逸脱ではない)

正典 HEAD の `ensure-test-db.php` は基点 DB を「存在させる」だけでなく「スキーマを最新にする」
ところまで担う (家系の裁定 AG-135)。本アプリの `ensure-test-db.php` は CREATE と出自の記録までで、
これを持たない。**これは意図的な逸脱ではなく追従の遅れ**なので、本登録では正当化しない。
追跡先は `docs/worktree-isolation-strategy.md` の「既知のギャップ」である。

### 保証しないもの

- 出自の記録は best-effort である。付与に失敗した DB は `Unlabeled` に落ち、
  `--include-hash` で人が名指ししない限り 1 件も回収されない
  (回収経路があることは「孤児が自動で片づく」ことを意味しない)
- 排他が閉じるのは**同一クローンの協調スクリプト間**の競合だけである。
  別クローンとの競合は `Foreign` の分類と `--protect-hash` と人の承認の 3 段で扱う
- 「`--apply` を LLM が実行しない」は運用契約であり、機械では強制していない
- **リポジトリ全体で DROP の実行点が 1 本であることを走査する検査は持たない**。
  上の不変条件が言っているのは「孤児の回収経路が既存の境界へ合流している」ことだけで、
  別のファイルに新しい DROP の実行点が増えたことは検出できない

### 関連

- 実装: `scripts/ci/drop-test-db.php` / `scripts/ci/ensure-test-db.php` /
  `scripts/ci/pgsql_test_conn.php` / `tests/Support/Ci/TestDatabaseEnv.php` /
  `tests/Support/Ci/TestDatabaseCandidate.php` /
  `tests/Support/Ci/TestDatabaseClassification.php` /
  `tests/Support/Ci/TestDatabaseDecision.php`
- 検査: `tests/Unit/Ci/DropTestDbScriptTest.php` /
  `tests/Unit/Ci/TestDatabaseClassificationTest.php` /
  `tests/Unit/Ci/TestDatabaseProvenanceTest.php` /
  `tests/Unit/Ci/TestDatabaseEnvTest.php`
- 背景: `docs/worktree-isolation-strategy.md` の「孤児テスト DB の回収」と「既知のギャップ」
- 設計: `devnotes/20260805-2017-todo-T114/` /
  `devnotes/20260818-1755-template-divergence-ledger-ci-db-and-launcher/`
```

### 書式の確認 (機械が見る点)

- 登録メタ表は 9 行ちょうど・規定の順序で、直後に空行を置く
- セルに縦棒を書いていない (分類名を並べる箇所はバッククォートを並べ、矢印を使わない)
- 対象パスはバッククォート囲みを ` / ` でつないだ形だけ。7 件とも実在するファイルである
- 根拠 `T114` は `docs/TODO-closed.md` の表の ID セルとして実在する (130 行目)
- 見出しに印・矢印・「解消」「済み」を含まない

### 対象パスの選び方 (なぜこの 7 件か)

aicue:T114 が触ったファイルのうち、**実行される経路に含まれるもの**を対象パスにした。

| ファイル | 正典に | 扱い |
|---|---|---|
| `scripts/ci/drop-test-db.php` | ある (2508 バイト) | 対象パス (回収の入口そのもの) |
| `scripts/ci/ensure-test-db.php` | ある (8472 バイト) | 対象パス (出自の記録を足した) |
| `scripts/ci/pgsql_test_conn.php` | ある (6193 バイト) | 対象パス (SQL の組み立てと計画の関数を足した) |
| `tests/Support/Ci/TestDatabaseEnv.php` | ある (4453 バイト) | 対象パス (実行時に読まれる判定の本体) |
| `tests/Support/Ci/TestDatabaseCandidate.php` | **無い** | 対象パス (同上。本アプリだけの部品) |
| `tests/Support/Ci/TestDatabaseClassification.php` | **無い** | 対象パス (同上) |
| `tests/Support/Ci/TestDatabaseDecision.php` | **無い** | 対象パス (同上) |
| `tests/Unit/Ci/DropTestDbScriptTest.php` ほか 3 本 | **無い** | 関連欄 (乖離を**検査する側**であって乖離の本体ではない) |
| `scripts/teardown-worktree.sh` | ある | どちらでもない (失敗時の案内文へ 1 行足しただけで、経路の形は変えていない) |
| `tests/Architecture/GitIndexNormalizationTest.php` | 無い | どちらでもない (T114 の別施策 = index の正規化で、テスト DB の話ではない) |

検査する側を対象パスへ入れない基準は D31 でも同じにする (`scripts/claude-wrapper.test.ts` を
関連欄に置く)。**登録簿は対象パスの網羅性を機械で見ない**ので、この基準は人が守る規約である。

## 施策 3: D31 を追記する (起動ラッパ)

### 変更箇所

- ファイル: `docs/template-divergence.md` の末尾 (D30 の後ろ。`---` で区切る)

### 実測 (登録の根拠)

- 本アプリ `scripts/claude` は blob `18de4919` (3950 バイト)、正典は `49e03e31` (3368 バイト)
- 乖離を作ったのは aicue:T181 (`ca512d3e`、2026-08-15)。
  `devnotes/20260817-1230-claude-statusline-vendoring/` を読んで確認したところ、
  状態表示行の取り込み (aicue:T208) は `scripts/claude-statusline` と `scripts/README.md` しか
  触っておらず、**この乖離とは無関係**である
- 正典もその後、同じ目的の代替経路を**別実装**で持っている (`find_latest_ext()`)

### 追記する本文 (この文字列をそのまま入れる)

```
## D31 起動ラッパの拡張探索と代替経路を正典と別の形で持つ

| 行 | 内容 |
|---|---|
| 対象パス | `scripts/claude` |
| 業務要件起因の説明 | 起動ラッパは開発で必ず通る経路だが、同じ不変条件を守る実装が正典側に無く手元でも読めなかったため、実装を待たずに同じ不変条件を別実装で先に固定した。拡張の置き場も接尾辞の綴りも環境で変わるので、完全一致だけを見る形では拡張が入っているのに起動できない環境が残る |
| 揃え続ける不変条件と保証機構 | 2 つの置き場から版が最も大きい拡張を採ること、完全一致が無ければ拾い直して警告すること、起動する実体が無ければ明示エラーで止めること、ラッパ専用の指定だけを剥がして残りの引数を順序も内容も変えずに渡すこと。`scripts/claude-wrapper.test.ts` が W1 から W8 の 8 要件を 9 つのケースで固定する (W6 だけ状態表示行の有る場合と無い場合の 2 ケースを持つ) |
| 再判定の条件 | 家系が起動ラッパの正典形を確定したとき。または正典の探索と警告の形を取り込むと決めたとき |
| 決めた日 | 2026-08-15 |
| 決めた人 | 開発者 |
| 根拠 | T181 |
| 状態 | 監視中 |
| 見直し期限 | 2027-02-18 |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 探索の関数 | `find_latest_ext()` が版の文字列を返し、パスの解決は関数の外で行う | `find_claude_extension()` が採用するパスを返し、見つからなければ非ゼロで戻る |
| 警告を出す時点 | 拾い直しを試す前に出す (拡張が 1 つも入っていない環境でも出る) | 拾い直しが成功したときだけ出す |
| 警告の内容 | 期待した platform | 期待した platform と採用したパスの両方 |
| 採用後の存在検査 | `[ ! -d ... ]` を残す | 関数が実在するディレクトリしか返さないので持たない |
| 回帰テスト | ある | ある (W1 から W8 の 8 要件を 9 ケースで固定する。拾い直しの警告と、完全一致では 1 文字も警告しない負のコントロールを含む) |

### なぜ正当な差分か (logic-driven)

aicue:T181 の時点で、本アプリの `scripts/claude` は拡張の探索を本文へ直書きしており、
platform が完全一致する拡張が無い環境では即座に終了して代替を案内しなかった。
T181 は探索を 1 か所の関数へまとめ (完全一致と拾い直しで同じ規則が使われる)、
拾い直しの経路と警告を足し、回帰テストを新設した。**意図が確認できる変更**であり、
気付かないうちにずれたものではない。

当時、正典側にこの経路は無く、正典の実装はこの機械から読めなかった (T181 は
「追従元との byte 一致は確認できないし主張しない」と明記している)。実装を待って
起動できない環境を放置するより、**同じ不変条件を別実装で先に固定する**方が実害を早く閉じられる。
これは aicue:D11 (svelte-no-undef-gate を別実装で先に固定した登録) と同じ形の判断である。

正典はその後に同じ目的の経路を別実装で持った。守っている不変条件は同じで、
差は「警告をいつ出すか」「関数が何を返すか」という形の違いである。登録簿の判定軸は
「ライブラリや実装が同じか」ではなく「同じ不変条件を同じタイミングと抽象度で保証するか」なので、
この差は許容される種類の差である。ただし**下流だけが別の形を持ち続けてよいとは限らない**うえ、
正典の実装は今なら読める (家系の機能台帳から原本を取得できる)。したがって状態は `監視中` にし、
期限までに寄せるかどうかを判断する。

### 正典の内容へ戻す案との比較 (採らない理由)

| 案 | 内容 | 判断 |
|---|---|---|
| A: 登録する | 差を登録簿に載せ、期限を切って再判断する | 採る |
| B: 正典へ戻す | 正典の `49e03e31` を byte 一致で取り込み、差を消す | 採らない |

B を採らない理由は 3 つある。

1. 意図が確認できる変更である (T181 の目的・実装・回帰テストが揃っている)。
   登録簿の「登録するか迷ったら登録する」に素直に当てはまる
2. 振る舞いが後退する方向を含む。正典は拾い直しを試す前に無条件で警告するので、
   拡張が 1 つも入っていない環境では警告とエラーが 2 段で出る。本アプリの回帰テストは
   「拡張が 1 つも無ければ platform 名つきのエラーで終了する」と
   「完全一致では警告を 1 文字も出さない」を負のコントロールとして固定しており、
   戻すと期待の書き換えが要る
3. どちらの形を正典とするかは家系の判断であり、下流が独断で寄せる話ではない。
   登録して見える状態にし、期限までに判断する

### 保証しないもの

- 拾い直した実体がその機械で実際に動くこと (代替の経路は arch を検査しない。正典も同じである)
- 同じ版が 2 つの置き場にあるときの優先順 (正典が固定していないので下流だけで固定しない)
- 版の比較は `sort -V` に依存する。これは本変更より前からある前提であり、
  無い環境で動くことは保証の対象にしていない
- 正典との byte 一致 (T181 の時点でも主張していない)

### 関連

- 実装: `scripts/claude` / `scripts/claude-wrapper.test.ts` / `scripts/README.md`
- 設計: `devnotes/20260816-0457-todo-T181/` /
  `devnotes/20260818-1755-template-divergence-ledger-ci-db-and-launcher/`
- 家系の機能台帳: `vscode-cli-wrappers` (本アプリのセルは追従の判断待ちのまま)
```

### 書式の確認 (機械が見る点)

- 対象パス `scripts/claude` は実在するファイルで、既存 28 件のどの対象パスとも重ならない
- 根拠 `T181` は `docs/TODO-closed.md` の表の ID セルとして実在する (197 行目)
- 状態 `監視中` の見直し期限 `2027-02-18` は基準日 2026-08-18 から 184 日で、上限 400 日の内側

### 対象パスを `scripts/claude` 1 件にする理由

D30 と同じ基準である。**実行される経路**は `scripts/claude` の 1 本で、
`scripts/claude-wrapper.test.ts` はそれを検査する側、`scripts/README.md` は台帳への 1 行なので、
どちらも関連欄に置く。aicue:T181 が同じコミットで触った `.gitignore` と
`tests/Architecture/SkillsLockIgnoreCoverageTest.php` は別施策 (版固定ファイルの除外設定) であり、
起動ラッパの乖離とは関係しないので対象外である。

### 登録してよいのか (レビューで割れた論点)

登録簿は「互換・UX・作業量を理由にした逸脱は記録せず是正する」と定めている。
本件がここに当たるという読みは成り立つか、を検討した。

- 当たらない、と判断した。理由は 2 つある。1 つ目は、この登録が正当化するのは
  「別実装であること」であって「正典へ寄せないこと」ではない点である。
  寄せる判断は `監視中` の期限までに行う (寄せたら登録ごと消す)。
  2 つ目は、既存 28 件の運用がこの読みを採っていない点である —
  aicue:D10 (テストレーンのロック) も aicue:D11 (別実装の gate) も aicue:D21 (自己検証の実行点) も、
  理由は製品ドメインではなく**この開発の進め方**が要求する要件である
- 併せて、登録簿は「登録するか迷ったら登録する。誤登録はエントリを削除すれば是正できるが、
  登録漏れには気付けない」と定めている。保留は「未登録のまま」と同じ状態であり、
  台帳リポジトリの巡回が同じ指摘を出し続ける

## 施策 4: 検査側の固定件数を 30 へ直す

### 変更箇所

- ファイル: `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` (37 行目)

### 現行コード

```php
const TEMPLATE_DIVERGENCE_ENTRY_COUNT = 28;
```

### 変更後コード

```php
const TEMPLATE_DIVERGENCE_ENTRY_COUNT = 30;
```

### 波及変更

- TypeScript 型定義: なし
- API Resource / DTO: なし
- テストファイル: 本ファイルのみ (`DivergenceLedgerRules` / `DivergenceLedgerParser` は変更しない)

固定値は**明示件数との同期検査**であって例外の一覧ではない。個別の D 番号を名指しして
規則を免除する仕組みは無いので、追加した 2 件も他の 28 件と同じ規則で検査される。

## 施策 5: 追従の遅れの追跡先を作る

### 変更箇所

- ファイル: `docs/worktree-isolation-strategy.md` の「既知のギャップ」節

### 追記する項目

```
- **正典の `scripts/ci/ensure-test-db.php` はスキーマ更新まで担う形になったが追従していない**。
  正典 (家系の裁定 AG-135) は基点 DB を「存在させる」だけでなく `migrate` まで走らせ、
  未適用が残っていないことと更新がその DB に当たったことまで確かめる。本アプリの
  `ensure-test-db.php` は CREATE と出自の記録までで、基点 DB のスキーマが古いまま残りうる
  (DB 系の trait を使わない Architecture のレーンは基点 DB をそのまま読むため、
  新しい worktree でだけ落ちる形の失敗になりうる)。これは意図的な逸脱ではないので
  `docs/template-divergence.md` では正当化していない (aicue:D30 の「この登録が扱わない範囲」)。
```

この節は既に worktree まわりの未対応事項を列挙しているので、追跡先を新設せずに済む。

## テスト計画

- [ ] 施策 4 を**入れる前に**施策 1〜3 を入れて `TD12` が赤くなることを確認する
      (明示件数 30 と固定値 28 の食い違いが検出されること = テストファースト)
- [ ] `vendor/bin/pest tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が
      TD0 と TD1〜TD12 の 2 本とも緑になること
- [ ] `vendor/bin/pest tests/Unit/Architecture/DivergenceLedgerRulesTest.php` (負例側) が緑のままであること
- [ ] AGENTS.md の検証コマンド一覧を全部通す —
      `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` /
      `pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` /
      `pnpm build:packages` / `pnpm test:packages`
      (文書中心の変更でも省略しない。テストレーンのグローバルロックは待つ。kill しない)
- [ ] 新規テストは追加しない。**追加すべき不変条件が無いから**である —
      登録簿の形式は既存の検査が全数を見ており、今回増えるのは検査対象のデータ 2 件である。
      登録メタ表が挙げる不変条件はいずれも aicue:T114 / aicue:T181 の時点で既存のテストが
      固定したもので、本変更で新しく主張する不変条件は 1 つも無い
      (「リポジトリ全体で DROP の実行点が 1 本」のような**まだ機械で見ていない性質は
      不変条件として書かない**。D30 の「保証しないもの」に明示する)

## リスク

| リスク | 内容 | 扱い |
|---|---|---|
| 3 点一致の取りこぼし | 明示件数・見出しの実数・固定値のどれかを直し忘れる | `TD12` が 2 種類の違反 (明示件数との差 / 固定値との差) で必ず落ちる。テスト計画で赤を先に見る |
| 対象パスの重複 | 既存の登録と同じパスを挙げる | `TD4` が和集合の重複を検出する。実測でも既存 28 件は `scripts/ci/` と `tests/Support/Ci/` と `scripts/claude` を 1 件も挙げていない (登録簿の全文検索で 0 件) |
| 期限切れの先送り | D31 の期限が切れて CI が赤くなる | 仕様である。直し方は登録簿の規約節の 4 通りから選ぶ (検査は緩めない) |
| 遅れの隠蔽 | D30 が上積みだけを書き、AG-135 の遅れが見えなくなる | 施策 5 と D30 の「この登録が扱わない範囲」で明示する |
| 誤登録 | 実は逸脱でなかった | 登録簿の規約どおり、誤登録はエントリの削除で是正できる。登録漏れには気付けないので登録する側へ倒す |
| 対象パスの取りこぼし | 乖離を構成するファイルの一部が登録の外に残る | 機械では見られない (登録簿が明記する保証範囲の外)。施策 2 と施策 3 に「対象パスの選び方」を書き、実行される経路と検査する側の線引きを人が守る規約として残す |

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 変更は文書 2 本と検査の固定値 1 つで、アプリのコードに触れない。単独の worktree を立てるほどの大きさではなく、他の施策と競合する面も持たない |
| 競合リスク | `docs/template-divergence.md` の末尾へ別の登録を同時に足す作業があると、D 番号と件数の 3 点一致で衝突する。並行する登録作業がある場合は番号を先に確認する |
