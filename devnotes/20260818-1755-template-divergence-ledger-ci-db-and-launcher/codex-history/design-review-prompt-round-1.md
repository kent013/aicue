## アプリの使命 (North Star)

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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
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

【この設計の性質】
本件はコード変更を伴わない **文書 2 本と Architecture テストの固定値 1 つ**の変更である。
`docs/template-divergence.md` (テンプレートからわざと外れた点の登録簿) へ 2 件 (D30 / D31) を
追記し、`tests/Architecture/TemplateDivergenceLedgerFormatTest.php` の登録件数の固定値を
28 から 30 へ直す。書式の正本は登録簿の規約節と上記テスト (および
`tests/Support/TemplateDivergence/DivergenceLedgerParser.php` と `DivergenceLedgerRules.php`) である。
必要なら実ファイルを読んでよい (作業ディレクトリは /workspace)。

【重点的に見てほしい点】
1. 追記する登録メタ表が機械検査 (TD1 から TD12) を通るか。9 行の順序・セル内の縦棒・
   対象パスの書式と実在・和集合の重複・根拠の実在・状態と見直し期限の値域・見出しの正準形
2. 登録の中身が正しいか。とくに「揃え続ける不変条件と保証機構」が実在の検査を指しているか、
   「業務要件起因の説明」が本当に理由になっているか
3. D30 が上積み (本アプリ側の追加) と遅れ (正典側の後発機能に未追従) を混ぜていないか
4. D31 の状態を `監視中`、D30 を `恒久` とした判断が登録簿の規約に合っているか
5. 保証しないものの記述が誇張・過小になっていないか
6. テスト計画 (新規テストを足さない判断を含む) が禁止事項 1 に照らして妥当か

【レビュー観点】
1. コードの正確性 2. 既存コードとの整合性 3. PHPStan level 10 適合性
4. テスト計画の網羅性 5. DTO/JsonResource パターンの遵守 6. Inertia Props vs API Response
7. 副作用・後退リスク 8. 波及変更の網羅性 9. セキュリティ 10. DESIGN.md 準拠 11. Atomic Design 準拠
(本件は UI もアプリコードも変えないので 4 から 11 の多くは該当なしになる。該当なしはそう書けばよい)

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

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
| 対象パス | `scripts/ci/drop-test-db.php` / `scripts/ci/ensure-test-db.php` / `scripts/ci/pgsql_test_conn.php` |
| 業務要件起因の説明 | 実装を必ず worktree で行う進め方のため、テスト DB 名を worktree の realpath の hash から作っている。worktree が検証なしで強制撤去されると hash を再現できず、引数なしの回収では二度と落とせない孤児 DB が積み上がる (監査時点で 17 個 / 221.9 MB) |
| 揃え続ける不変条件と保証機構 | DROP DDL の実行点は `drop-test-db.php` の 1 本のままであること、dev DB の拒否と allowlist の再検査は `TestDatabaseEnv` の既存実装を共有すること、DB 名が worktree 単位のままであること。`tests/Unit/Ci/DropTestDbScriptTest.php` (危険な名前が実行境界へ到達しない) と `tests/Unit/Ci/TestDatabaseClassificationTest.php` (分類の優先順位と確認用の値の照合) と `tests/Unit/Ci/TestDatabaseProvenanceTest.php` (出自の記録が冪等で best-effort) が固定する |
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
回収経路を通らない孤児 DB が単調増加した (監査時点で 17 個 / 221.9 MB)。

テンプレートの `drop-test-db.php` は「今いる worktree の基点と worker DB を落とす」だけなので、
この事象に手が届かない。届かせるには DB 自身に出自を持たせるしかなく、
非破壊の `COMMENT ON DATABASE` を選んだ。分類は SELECT だけで行い、DROP DDL の実行点は
1 本のまま据え置いた — **危険な操作の入口を増やさずに、判断材料だけを増やす**形である。

### 揃えている不変条件 (これは保証し続ける)

> 「DROP DDL を実行するのは `drop-test-db.php` の 1 本のまま。dev DB の拒否 (`isDevDatabase()`) と
> allowlist の再検査 (`isAllowedTestDatabase()`) と DROP 文の組み立て (`pgsqlDropDatabaseSql()`) は
> 既存実装をそのまま共有する」

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

### 関連

- 実装: `scripts/ci/drop-test-db.php` / `scripts/ci/ensure-test-db.php` /
  `scripts/ci/pgsql_test_conn.php` / `tests/Support/Ci/TestDatabaseEnv.php` /
  `tests/Support/Ci/TestDatabaseClassification.php`
- 検査: `tests/Unit/Ci/DropTestDbScriptTest.php` /
  `tests/Unit/Ci/TestDatabaseClassificationTest.php` /
  `tests/Unit/Ci/TestDatabaseProvenanceTest.php`
- 背景: `docs/worktree-isolation-strategy.md` の「孤児テスト DB の回収」と「既知のギャップ」
- 設計: `devnotes/20260805-2017-todo-T114/` /
  `devnotes/20260818-1755-template-divergence-ledger-ci-db-and-launcher/`
```

### 書式の確認 (機械が見る点)

- 登録メタ表は 9 行ちょうど・規定の順序で、直後に空行を置く
- セルに縦棒を書いていない (分類名を並べる箇所はバッククォートを並べ、矢印を使わない)
- 対象パスはバッククォート囲みを ` / ` でつないだ形だけ。3 件とも実在するファイルである
- 根拠 `T114` は `docs/TODO-closed.md` の表の ID セルとして実在する (130 行目)
- 見出しに印・矢印・「解消」「済み」を含まない

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
| 業務要件起因の説明 | 開発は devcontainer と手元の機械の両方で行い、VSCode 拡張の置き場も接尾辞の綴りも環境で変わる。完全一致だけを見る形だと拡張が入っているのに起動できず、開発そのものが止まる |
| 揃え続ける不変条件と保証機構 | 2 つの置き場から版が最も大きい拡張を採ること、完全一致が無ければ拾い直して警告すること、起動する実体が無ければ明示エラーで止めること、ラッパ専用の指定だけを剥がして残りの引数を順序も内容も変えずに渡すこと。`scripts/claude-wrapper.test.ts` の 9 ケース (W1 から W8) が固定する |
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
| 回帰テスト | ある | ある (9 ケース。拾い直しの警告と、完全一致では 1 文字も警告しない負のコントロールを含む) |

### なぜ正当な差分か (logic-driven)

aicue:T181 の時点で、本アプリの `scripts/claude` は拡張の探索を本文へ直書きしており、
platform が完全一致する拡張が無い環境では即座に終了して代替を案内しなかった。
T181 は探索を 1 か所の関数へまとめ (完全一致と拾い直しで同じ規則が使われる)、
拾い直しの経路と警告を足し、回帰テストを新設した。**意図が確認できる変更**であり、
気付かないうちにずれたものではない。

正典はその後に同じ目的の経路を別実装で持った。守っている不変条件は同じで、
差は「警告をいつ出すか」「関数が何を返すか」という形の違いである。登録簿の判定軸は
「ライブラリや実装が同じか」ではなく「同じ不変条件を同じタイミングと抽象度で保証するか」なので、
この差は許容される種類の差である。ただし**下流だけが別の形を持ち続けてよいとは限らない**ので、
状態は `監視中` にして期限を切る。

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
- 版の比較が `sort -V` に依存すること (この前提は本変更より前からある)
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
- [ ] `composer test` 全体が緑 (グローバルロックは待つ。kill しない)
- [ ] `composer phpstan` が level 10 で No errors
- [ ] `composer fix` (Pint) を通す
- [ ] 新規テストは追加しない。**追加すべき不変条件が無いから**である —
      登録簿の形式は既存の検査が全数を見ており、今回増えるのは検査対象のデータ 2 件である

## リスク

| リスク | 内容 | 扱い |
|---|---|---|
| 3 点一致の取りこぼし | 明示件数・見出しの実数・固定値のどれかを直し忘れる | `TD12` が 2 種類の違反 (明示件数との差 / 固定値との差) で必ず落ちる。テスト計画で赤を先に見る |
| 対象パスの重複 | 既存の登録と同じパスを挙げる | `TD4` が和集合の重複を検出する。実測でも既存 28 件は `scripts/ci/` の 3 ファイルと `scripts/claude` を挙げていない |
| 期限切れの先送り | D31 の期限が切れて CI が赤くなる | 仕様である。直し方は登録簿の規約節の 4 通りから選ぶ (検査は緩めない) |
| 遅れの隠蔽 | D30 が上積みだけを書き、AG-135 の遅れが見えなくなる | 施策 5 と D30 の「この登録が扱わない範囲」で明示する |
| 誤登録 | 実は逸脱でなかった | 登録簿の規約どおり、誤登録はエントリの削除で是正できる。登録漏れには気付けないので登録する側へ倒す |

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 変更は文書 2 本と検査の固定値 1 つで、アプリのコードに触れない。単独の worktree を立てるほどの大きさではなく、他の施策と競合する面も持たない |
| 競合リスク | `docs/template-divergence.md` の末尾へ別の登録を同時に足す作業があると、D 番号と件数の 3 点一致で衝突する。並行する登録作業がある場合は番号を先に確認する |

---

## 関連する現行コード

### docs/template-divergence.md の規約節 (1 行目から 70 行目)

# テンプレート差分レジストリ

テンプレート(laravel-claude-template)の構造から**意図的に逸脱**した箇所の正本記録。
逸脱が正当なのは **logic-driven(ドメイン要件起因)のときだけ**。互換・UX・作業量を理由にした
逸脱は記録せず是正する(`docs/app-integration-guide.md` §0)。

**書式の正本は本節である**。家系の統一形式 (機能台帳 lctl の feature
`template-divergence-ledger` が 2026-08-15 に確定した形) に従う。形式は
`tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。

登録エントリ: 28 件

## 記録の原則

- 判定軸は「ライブラリ/実装が同じか」でなく「**同じ不変条件を同じタイミング/抽象度で保証するか**」。
  不変条件が揃っていれば構文差は許容
- **登録は逸脱を作る変更そのものに含める**。後でまとめて書かない。まだ実在しない逸脱
  (これから作る予定) は登録しない — 予定の管理は `docs/TODO.md` の役目である
- **解消した逸脱は登録から消す**。全パスが戻ったならエントリごと、一部が戻ったなら
  そのパスを対象パス欄から削る。状態の語で「解消済み」を表さない。
  台帳の中に履歴の節を作らない (走査の対象外になる領域は回避口になるため)
- 番号 (`D<n>`) は**再利用しない**。削除しても後続を詰めない (欠番は正常)。
  他リポジトリから参照するときは `aicue:D<n>` と書く
- **登録するか迷ったら登録する**。テンプレートの実物は手元に無いので「テンプレートに無い領域への
  上積み」か「ひな形から外れた判断」かを本アプリだけで確定できないことがある。
  誤登録はエントリを削除すれば是正できるが、登録漏れには気付けない。台帳リポジトリの巡回から
  「記録されるべき乖離」として届いた指摘は、この理由で登録する側へ倒す

## 登録メタ表 (9 行ちょうど・この順序)

| 行 | 値域 |
|---|---|
| 対象パス | リポジトリ相対のファイルパスをバッククォート囲みで 1 件以上。区切りは半角スペースとスラッシュと半角スペース。glob・絶対パス・上位への相対指定は不可。ファイルとして実在すること。**全登録の和集合で重複しないこと** |
| 業務要件起因の説明 | なぜドメイン要件のせいでテンプレートの形から外れたか (1〜2 文) |
| 揃え続ける不変条件と保証機構 | 何を揃え続け、どの機構が保証するか |
| 再判定の条件 | 何が変わったら見直すか (**恒久の登録にも必須**) |
| 決めた日 | `YYYY-MM-DD`。逸脱を最初に決めた日 (再判断で書き換えない)。未来日は不可 |
| 決めた人 | `オーナー` / `開発者` |
| 根拠 | `T<n>` (3 桁以上のゼロ埋め。`docs/TODO.md` / `docs/TODO-closed.md` の表に実在) または `devnotes/<dir>/` (ディレクトリが実在) |
| 状態 | `恒久` / `監視中` |
| 見直し期限 | `監視中` は `YYYY-MM-DD` (基準日から 400 日以内)。`恒久` は全角ダッシュ 1 文字 |

- **`恒久` も `監視中` も「今ある逸脱」を表す**。解消を意味する語は値域に無い
- `監視中` にするのは、期限付きで能動的に見直す根拠 (期限・予定時期・追跡中の事象) が
  あるときだけである。解消の条件が書けることは `監視中` の根拠にならない
  (`恒久` の登録も再判定の条件を必ず持つので、条件の有無は区別にならない)
- セルの中に縦棒を書かない (エスケープしても解釈しない)。表の区切りを使いたくなる内容は
  エントリ本文の節へ書く

## 見直し期限が切れたときの直し方 (4 通り)

1. 逸脱を解消して登録を消す
2. `恒久` へ変えて理由を足す
3. 期限を延ばして再判断の根拠を足す
4. 対象を分けて個別に判断する

**検査を緩めることは選択肢に入れない**。期限切れで CI が赤くなるのは仕様である。

## この登録簿が保証しないもの

- 実ファイルがテンプレートから逸脱したのに登録が無いこと (登録漏れそのもの) は検出できない。
  実体との突合は台帳リポジトリの巡回が行う (家系の裁定 AG-159)
- 内容としてテンプレート準拠へ戻したのにファイルが残っている登録も検出できない
- 登録の中身が正しいことは機械では見ない (空でないこと・値域に収まっていることだけを見る)
- **削除した番号の再利用**は検出できない (使用済み番号の履歴を持たないため。
  再利用しないことは人が守る規約である)

## エントリ形式

```

### tests/Architecture/TemplateDivergenceLedgerFormatTest.php (全文)

<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Tests\Support\TemplateDivergence\DivergenceLedgerParser;
use Tests\Support\TemplateDivergence\DivergenceLedgerRules;
use Tests\Support\TemplateDivergence\LedgerContext;
use Tests\Support\TemplateDivergence\TodoLedgerReference;
use Webmozart\Assert\Assert;

/*
 * 逸脱の登録簿 (`docs/template-divergence.md`) が家系の統一形式を満たすことを検査する。
 *
 * 判定の実体は `DivergenceLedgerRules` (純関数) にあり、本テストは
 * **実ファイルを読んで文脈を組み立て、違反が空であることを見るだけ**の薄い層である。
 * 負例 (検出器が実際に検出できること) は
 * `tests/Unit/Architecture/DivergenceLedgerRulesTest.php` が固定する。
 *
 * **この検査が保証しないもの** (誇張しない):
 *  - 実ファイルがテンプレートから逸脱したのに登録が無いこと (登録漏れそのもの)。
 *    実体との突合は台帳リポジトリの巡回が行う (家系の裁定 AG-159)
 *  - 内容をテンプレート準拠へ戻したのに残っている登録 (対象パスは実在し続けるため)
 *  - 登録の中身が正しいこと (空でないこと・値域に収まっていることだけを見る)
 *  - 削除した番号の再利用 (使用済み番号の履歴を持たないため)
 *
 * 実行不能 (台帳が読めない / 囲みが閉じない / 登録エントリ領域が無い) は
 * skip でも緑でもなく**不合格**にする。
 */

/**
 * 登録件数の固定値。
 *
 * **明示件数との同期検査であって、例外を許す一覧ではない**。個別の D 番号を名指しして
 * 規則を免除する仕組みは持たない。登録を足した / 消したら同じ変更でこの値も直す。
 */
const TEMPLATE_DIVERGENCE_ENTRY_COUNT = 28;

/** 逸脱の登録簿の本文 (読めないことは不合格)。 */
function templateDivergenceMarkdown(): string
{
    $markdown = file_get_contents(base_path('docs/template-divergence.md'));
    Assert::string($markdown, 'docs/template-divergence.md を読めない');

    return $markdown;
}

/** 根拠の照合先 (TODO 台帳の Open と Closed の両方)。 */
function templateDivergenceTodoSources(): string
{
    $open = file_get_contents(base_path('docs/TODO.md'));
    $closed = file_get_contents(base_path('docs/TODO-closed.md'));
    Assert::string($open, 'docs/TODO.md を読めない');
    Assert::string($closed, 'docs/TODO-closed.md を読めない');

    return $open."\n".$closed;
}

test('TD0: 逸脱の登録簿を読めること (実行不能は不合格)', function (): void {
    expect(trim(templateDivergenceMarkdown()))->not->toBe('');
    expect(trim(templateDivergenceTodoSources()))->not->toBe('');
});

test('TD1〜TD12: 逸脱の登録簿が統一形式を満たすこと', function (): void {
    $todoSources = templateDivergenceTodoSources();

    $violations = DivergenceLedgerRules::violations(
        DivergenceLedgerParser::parse(templateDivergenceMarkdown()),
        new LedgerContext(
            baseDate: CarbonImmutable::today(),
            pinnedEntryCount: TEMPLATE_DIVERGENCE_ENTRY_COUNT,
            pathExists: fn (string $path): bool => is_file(base_path($path)),
            directoryExists: fn (string $path): bool => is_dir(base_path($path)),
            // T 番号は TODO 台帳の表のセルとして境界付きで照合する (T1 が T10 に一致しないように)
            rationaleExists: fn (string $reference): bool => TodoLedgerReference::existsIn($reference, $todoSources),
        ),
    );

    expect($violations)->toBe([], "逸脱の登録簿の形式違反:\n".implode("\n", $violations));
});

### 登録済みエントリの見出し一覧 (現状 28 件。D9 は削除済みの欠番)

71:## D1 <逸脱の要約>
102:## D1 Tier B スキーマの先取り (Cut / Take を振る舞い無しで先行作成)
147:## D2 循環 FK の 3 段階マイグレーション (cuts の parent_cut_id / adopted_take_id を後付け)
177:## D3 Category `sort_order` の Service 専有 (fillable 外・Store/Update で受けない)
209:## D4 web `{project}` route の org スコープ guard を middleware 層に追加 (project.in-current-org)
250:## D5 Cut のシナリオ編集は per-row CRUD でなく document 単位保存 (PUT .../scenario)
289:## D6 presigned PUT の署名対象は ChecksumSHA256 のみ (Content-Type/Length は HeadObject 照合が担う)
324:## D7 org 同時 preview 上限の「直列化実証テスト」は subprocess 方式を保留 (逐次境界テストで代替)
370:## D8 管理メニューのユーザー管理 = 招待一本化 + 遷移コマンドロール + Settings からの UI 移設
425:## D10 テストレーンのグローバルロック (worktree-local flock を残さず削除)
506:## D11 svelte-no-undef-gate を config 静的検査型で別実装 (同一不変条件・別実装)
575:## D12 ページタイトル / description はサーバ単一 SoT (helper 経由必須の JS 契約は不採用)
641:## D13 SSO 登録ユーザーの password を保存しない (phantom password の撤去。前方修正のみ)
711:## D14 実行した route の記録をアプリ側の観測器で採る (退避と正規化と route 名解決の 3 段を置かない)
786:## D15 strict_types gate の走査域を追跡下 PHP 全数にし、未宣言一覧を持たない
860:## D16 prompt の trusted 変数の入口を作らない (窓口の引数は untrusted だけ)
917:## D17 滞留回収の共通基盤を、閾値の置き場所と `recover()` の引数で正典から外す
979:## D18 hook の起動子を「起動先の検証 + 終了コードの写像器」にする
1039:## D19 経路キャッシュ起動での middleware 後付けは「走らせない」側の契約を維持する (専用の実行点クラスへは移行しない)
1128:## D20 bug-hunt 目録の生成方式を、注釈 TOML・機能カタログ 3 列・中間 JSON 無しで実装する
1211:## D21 bug-hunt の自己検証を CI の専用ステップでなく composer test の配線に載せる
1254:## D22 退会は利用者の行を消さず凍結で表す (猶予 30 日)
1303:## D23 課金記録は退会後も 7 年保持し、対象と年数を 1 か所で持つ
1350:## D24 SSO の driver 解決点を自前クラス 1 つへ切り出す
1392:## D25 退避終端 gate の母集団と目録を静的 gate のファイル内に置く
1461:## D26 パスキー設定の検査を「設定の評価時」ではなく「本番起動時の関門」で行う
1512:## D27 コード到達の対象外の宣言を、route 名の接頭辞を持たないコード軸だけの形にする
1581:## D28 デザイントークンの生成 CSS 検査を、値の写しを持たず実 app.css も通す形で実装する
1650:## D29 PHP 列挙と TS 値域の同期を「登録した写しだけ」で守る (全数走査と逆走査は持たない)
