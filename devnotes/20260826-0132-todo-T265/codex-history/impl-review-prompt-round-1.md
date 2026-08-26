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

# 役割: 実装レビュアー (impl-review Round 1)

あなたは Laravel + Svelte アプリの改善実装をレビューするコードレビュアーである。
今回の対象は bug-hunt スキルの coverage 配下の Python 自己テスト強化 (TODO aicue:T265) で、
PHP / TypeScript / UI の変更は含まない (Python stdlib のみ・テストと文書の変更)。

レビュー観点:
1. 詳細設計 (Round 4 APPROVED) との一致性 — 設計書のコードと実装の乖離、設計判断 (独立オラクル・共倒れ防止・集約形の補助降格) の忠実な反映
2. 正確性 — テストが狙った不変条件 (fail-closed / worklist 無出力 / 終了コード写像) を実際に固定しているか、偽陽性・偽陰性の余地
3. テスト網羅性 — 正例 (正の対照) と負例の併記、既存テストの不変更・不削除
4. fail-closed 規約 — 走査器共通規約 (欠落を静かに通さない・skip の gate が狭すぎ/広すぎないか)
5. 文書同期 (README / docblock) の正確性 — 実装挙動と矛盾しないか
6. Python コードの品質 (stdlib のみ・既存流儀との整合)

出力形式:
- ファイルごとに判定を書く
- 指摘は [Critical] / [Warning] / [Suggestion] に分類
- 最後に全体判定を APPROVED / CHANGES_REQUESTED のどちらかで明記する

---

## 詳細設計書 (正本: devnotes/20260826-0029-bughunt-executed-route-capture-t2/detailed-design.md ref-ok)

# 詳細設計: bughunt-executed-route-capture-t2

家系の機能台帳 lctl の feature `bughunt-executed-route-capture` の正典 t2 への追従。
照合器の自己テスト `.claude/skills/app-bug-hunt/coverage/test_correlate.py` への 3 点の追記。

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  （撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 → 実行単位の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）— 本設計は PHP 変更なしのため新規影響なし
- **Pest**テストフレームワーク（`composer test`）— Python 自己テストは既存の
  `tests/Architecture/BughuntCoverageToolSelfTest.php` が `python3 -m unittest` で結線済み
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、
  個別 `DatabaseTransactions` 使用禁止）— 本設計はテスト DB に触れない
- **テストデータは必ずFactoryで生成** — 本設計は Eloquent モデルを生成しない (対象外)
- **DTO + JsonResource** パターン（AGENTS.md参照）— 本設計は HTTP 応答を書かない (対象外)
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix` — Python は既存流儀
  (PEP8 相当・日本語 docstring) に合わせる
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript / Python は stdlib のみ

## 概念設計リファレンス

`devnotes/20260826-0029-bughunt-executed-route-capture-t2/conceptual-design.md`
(Codex 概念設計レビュー Round 3 APPROVED)

正典・参照実装 (lctl 実読):

- 正典 t2: t1 + 照合器の自己テストで (a) 主入力全数の欠落を 1 点ずつ契約違反で落とす検査、
  (b) 「全行が一致 = 列の取り違え」だけを落とす誤抽出検知 + 正例 1 件以上の要求、
  (c) 実ルーター検査でのコマンド実登録の確認
- 参照実装: motivation@36d28fbb の `coverage/test_correlate.py`
  (`LandingNetTest.test_n1_missing_option_is_exit_3` / `test_n1_missing_file_is_exit_3` /
  `LoadOperationsTest.test_real_operations_md_name_column_join_keys`) と
  `tests/Feature/Bughunt/ResolveExecutedRoutesCommandTest.php` の FT-12b (コマンド実登録)
- aicue は D14 の別実装 (遮断 middleware 内側の観測器 + `build_executed.py` + `correlate.py`)。
  主入力は 6 点 (目録 / 所見 / 実行済み / graph.db / 走行 id / route 一覧)、
  終了コード契約は `coverage/README.md` が正本 (0 / 1 = I/O・parse / 3 = 可用性違反、
  argparse required の欠落は usage エラー 2)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| A | 主入力 6 点の欠落を 1 点ずつ pin する検査 | `.claude/skills/app-bug-hunt/coverage/test_correlate.py` | 高 |
| B | 誤抽出検知の形の修正 (行があるのに 0 件を赤に + 正例 1 件以上) | 同上 | 高 |
| C | 実ルーター検査とコマンド実登録の確認 | 同上 | 高 |
| D | README・docblock の契約説明の同期 | `.claude/skills/app-bug-hunt/coverage/README.md` / `correlate.py` (docblock のみ) | 中 |

3 施策 (A〜C) は同一ファイルへの追記・改訂で、1 TODO・1 コミット系列として実装する。

## 施策 A: 主入力 6 点の欠落を 1 点ずつ pin する検査

### 変更箇所

- ファイル: `.claude/skills/app-bug-hunt/coverage/test_correlate.py`
  - import 追記 (冒頭): `contextlib` / `io` / `subprocess` (施策 C と共用)
  - 新クラス `MainInputAvailabilityTest` を `MainTest` の直後に追加
- 参照する現行実装 (変更しない): `correlate.py` L737-791 (`main()`)、L60-63 (終了コード定数)

### 波及変更

- TypeScript型定義: なし
- API Resource/DTO: なし
- テストファイル: 本施策自体がテスト。PHP 結線 (`BughuntCoverageToolSelfTest.php`) は
  モジュール名指定 (`test_correlate`) のため変更不要

### 現行コード (関連部の抜粋)

```python
# correlate.py main() — 終了コードの写像 (変更しない)
    if args.executed is None:
        print("ERROR: 主入力が揃わない (reason=executed_missing): ...", file=sys.stderr)
        return EXIT_INPUT_UNAVAILABLE            # --executed オプション欠落 = 3
    try:
        routes = load_route_list(args.route_list, project_dir=args.project_dir)
        operations = load_operations(args.operations)
        executed = load_executed(args.executed)
        findings, dropped = load_findings(args.findings, args.run_id)
        tb_index = tested_by_index(args.graph_db)
    except (ValueError, json.JSONDecodeError, OSError, sqlite3.Error,
            subprocess.CalledProcessError) as e:
        print(f"ERROR: {e}", file=sys.stderr)
        return EXIT_INPUT_ERROR                  # ファイル不在ほか I/O = 1
```

現行の自己テストは `--executed` 系 (オプション欠落 3 / run_id 不一致 3 / 形の契約違反 3 /
構文破損 1) しか pin しておらず、目録・所見・graph.db・走行 id・route 一覧の欠落は無検査。

### 変更後コード (追加するテストクラス)

```python
class MainInputAvailabilityTest(unittest.TestCase):
    """主入力 6 点の欠落を 1 点ずつ pin する (家系正典 t2 要素 1 の aicue 形)。

    aicue の照合器の主入力は 6 点 — 目録 (--operations) / 所見 (--findings) /
    実行済み (--executed) / graph.db (--graph-db) / 走行 id (--run-id) /
    route 一覧 (--route-list。省略は欠落ではなく実ルーター fallback = RealRouterTest が担当)。

    守る不変条件は「主入力が揃わない走行を成功にしない・worklist を 1 行も出さない」で、
    終了コードはその写像 (契約の正本は coverage/README.md):
      - オプション欠落: argparse required の 4 点は usage エラー (SystemExit 2)、
        --executed は main 内の可用性検査 (return 3 = executed_missing)
      - ファイル不在・glob 0 件: 読み込みの失敗 (return 1)
    正典実装は全欠落を 3 へ写像するが、aicue は D14 の別実装として上記の既存契約を pin する
    (終了コードの写像替えは README / SKILL.md 運用文まで波及する別議題)。

    将来 main() が stdout へ診断を出す設計に変わる場合は、「worklist を出さない」と
    「stdout 完全無出力」を別契約として本クラスの assert を再検討すること。
    """

    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        base = Path(self.tmp.name)
        self.route_path = base / "route.json"
        self.route_path.write_text(
            '[{"name":"register.store","method":"POST","uri":"register",'
            '"action":"App\\\\Http\\\\Controllers\\\\Auth\\\\RegisteredUserController@store"}]',
            encoding="utf-8",
        )
        self.ops_path = base / "operations.md"
        self.ops_path.write_text(
            "| method | route | name | story | 区分 |\n"
            "|---|---|---|---|---|\n"
            "| POST | register | register.store | S1 | ◎ |\n",
            encoding="utf-8",
        )
        self.findings_path = base / "findings.jsonl"
        self.findings_path.write_text(
            '{"finding_id":"F-1","run_id":"R1","route_name":"register.store","species_key":"a"}\n',
            encoding="utf-8",
        )
        self.executed_path = base / "executed.json"
        self.executed_path.write_text(json.dumps({
            "run_id": "R1", "shards": ["0"],
            "executed_routes": [
                {"route_name": "register.store", "shard": "0", "status": "ok"},
            ],
        }), encoding="utf-8")
        self.db = str(base / "graph.db")
        make_graph_db(self.db, [
            ("/workspace/resources/js/x.ts::x", "/workspace/resources/js/t.ts::t"),
        ])
        self.argv = [
            "--route-list", str(self.route_path),
            "--operations", str(self.ops_path),
            "--findings", str(self.findings_path),
            "--executed", str(self.executed_path),
            "--graph-db", self.db,
            "--run-id", "R1",
        ]

    def _run(self, argv):
        """main() を実行し (終了コード, stdout, stderr) を返す。

        argparse required の欠落は SystemExit を投げるので、その code も
        終了コードとして扱う (usage エラー 2)。
        """
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            try:
                rc = C.main(argv)
            except SystemExit as e:
                rc = e.code
        return rc, out.getvalue(), err.getvalue()

    def _drop_option(self, opt):
        idx = self.argv.index(opt)
        return self.argv[:idx] + self.argv[idx + 2:]

    def _replace_value(self, opt, value):
        argv = list(self.argv)
        argv[argv.index(opt) + 1] = value
        return argv

    def assert_no_worklist(self, rc, out, expected_rc):
        self.assertEqual(rc, expected_rc)          # (α) 期待する非 0 終了コード
        self.assertEqual(out, "")                  # (β) stdout に何も出さない
        self.assertNotIn("未実行機構", out)          # (γ) 契約の意図の明示 ((β) に包含)

    def test_baseline_is_green(self):
        # 正の対照: 全部揃っていれば 0 で worklist が出る (欠落検査の前提)。
        rc, out, err = self._run(self.argv)
        self.assertEqual(rc, 0, err)
        self.assertIn("未実行機構", out)

    def test_option_missing_is_rejected_per_input(self):
        # (--route-list は required でない: 省略 = 実ルーター fallback で欠落ではない)
        cases = {
            "--operations": 2, "--findings": 2, "--graph-db": 2, "--run-id": 2,
            "--executed": 3,
        }
        for opt, expected in cases.items():
            with self.subTest(option=opt):
                rc, out, err = self._run(self._drop_option(opt))
                self.assert_no_worklist(rc, out, expected)
                if expected == 3:
                    self.assertIn("executed_missing", err)

    def test_file_missing_is_rejected_per_input(self):
        base = Path(self.tmp.name)
        for opt in ("--route-list", "--operations", "--findings",
                    "--executed", "--graph-db"):
            with self.subTest(option=opt):
                rc, out, err = self._run(
                    self._replace_value(opt, str(base / "no-such-input")))
                self.assert_no_worklist(rc, out, C.EXIT_INPUT_ERROR)
                self.assertIn("ERROR", err)

    def test_findings_glob_matching_nothing_is_rejected(self):
        rc, out, err = self._run(
            self._replace_value("--findings", str(Path(self.tmp.name) / "shard-*" / "findings.jsonl")))
        self.assert_no_worklist(rc, out, C.EXIT_INPUT_ERROR)
        self.assertIn("ERROR", err)
```

補足 (graph.db のファイル不在): `sqlite3.connect()` は不在パスに空 DB ファイルを作ってから
`no such table: edges` (sqlite3.Error) で落ちる = return 1。一時ディレクトリ内なので副作用は
テストに閉じる。この挙動 (空ファイル生成) は既存実装の性質で、本設計では変更しない。

### PHPStan適合チェック

- [x] PHP 変更なし (対象外。Python は stdlib のみ・型注釈は既存流儀に合わせる)

### テスト計画 (テストファースト)

- [x] 本施策自体がテスト追加。先に赤の確認: `correlate.py` の except 節から `OSError` を
  一時的に外す → `test_file_missing_is_rejected_per_input` が traceback で赤になること、
  `--executed` の手動検査を一時的に外す → option 欠落ケースが赤になることを確認
  (変異は恒久コードに残さず、確認記録を実装時の devnotes に残す)
- [x] 正の対照 `test_baseline_is_green` (全入力が揃えば 0 + worklist) を同クラスに置く
- [x] 既存テスト (`MainTest` ほか) は変更しない・削除しない
- [x] `DatabaseTransactions` 不使用 (Python テスト。DB 非依存)

### リスク

- argparse の usage メッセージ形式が Python バージョンで変わっても、pin は終了コード 2 と
  stdout 空のみなので影響しない
- `SystemExit.code` は argparse 経路では常に int (2)。他経路の SystemExit は main() が
  投げない (raise SystemExit は `__main__` ガードのみ)

## 施策 B: 誤抽出検知の形の修正

### 変更箇所

- ファイル: `.claude/skills/app-bug-hunt/coverage/test_correlate.py` (現行 L164-191)
  `LoadOperationsTest.test_real_operations_md_name_column_join_keys` の全面改訂

### 波及変更

- TypeScript型定義 / API Resource/DTO: なし
- テストファイル: 本施策自体がテスト改訂

### 現行コード

```python
    @unittest.skipUnless(REAL_OPERATIONS.is_file(), "real operations.md not present")
    def test_real_operations_md_name_column_join_keys(self):
        ops = C.load_operations(str(REAL_OPERATIONS))
        if not ops:
            self.skipTest("operations.md はスケルトン (データ行なし)。route:list から生成後に有効化される")

        for name in ops:
            self.assertTrue(name.strip(), "空の join キーが混入している")

        matched = [name for name, info in ops.items() if name == info.get("operation")]
        self.assertNotEqual(
            len(matched), len(ops),
            "全 {} 行で join キーが URL 列と一致 = name 列でなく URL 列を拾っている "
            "(fix-gate #3 違反)".format(len(ops)),
        )
```

問題点: (1) `if not ops: skipTest` は「実ファイルにデータ行があるのにパーサが 0 件しか
返せない」退行でも静かに skip する (fail-open)。(2) 集約判定は既に「全行一致だけを落とす」
形だが、正典 t2 の「正例 (不一致行) 1 件以上」の明示形になっていない。

### 変更後コード

```python
    # 生成器 (scripts/bug-hunt-inventory.py) が書く 5 列固定ヘッダ (operations.md の契約)。
    # オラクルのヘッダ認識に C._header_indices() を使わない — 実装をオラクルにすると
    # ヘッダ検出の退行時に期待値と実値が同時に 0 件になり共倒れする。
    _REAL_OPERATIONS_HEADER = ["method", "route", "name", "story", "区分"]

    @unittest.skipUnless(REAL_OPERATIONS.is_file(), "real operations.md not present")
    def test_real_operations_md_name_column_join_keys(self):
        """実 operations.md の join キー集合を独立オラクルとの完全一致で固定する。

        オラクル: 生成器が書く 5 列固定ヘッダ `| method | route | name | story | 区分 |`
        の厳密一致で表の内部を判定し、データ行の第 3 列 (name) を _parse_route_cell で
        分解して期待キー集合を作る (検証対象は**列選択とヘッダ認識**。セル分解の検出力は
        合成テスト群が担う)。集合の完全一致は対称なので、load_operations 側の
        ヘッダ認識・列選択が壊れても、生成器がヘッダを変えても、どちらでも赤になる。

        「全行一致 = 列の取り違え」の集約形 (正典 t2) は補助として併置する。
        単一セグメント route では URL と route 名が正当に一致しうるため、
        行単位の不一致 assert は使わない (誤検知するため)。

        前提: aicue の operations.md は生成物で、route 操作の 5 列表だけを含む
        (ファイル冒頭に「5 列固定 (coverage/correlate.py の入力契約)」と明記されている)。
        6 列表など別形の表が正当に足されたときは本検査が集合不一致の赤になる —
        静かな見逃しではなく前提の見直しを促す赤であり、そのときはオラクルを
        生成物の実態に合わせて更新する。スケルトン (データ行 0) のときだけ静かに通る。
        """
        expected: set[str] = set()
        candidate_lines = 0  # 表らしい行の存在をヘッダ認識と独立に数える (共倒れ防止)
        in_table = False
        for raw in REAL_OPERATIONS.read_text(encoding="utf-8").splitlines():
            line = raw.strip()
            if not line.startswith("|"):
                in_table = False  # 表は先頭パイプ行の連続。非パイプ行で表を抜ける
                continue
            if "---" in line:
                continue
            cols = [c.strip() for c in line.strip("|").split("|")]
            if [c.lower() for c in cols] == self._REAL_OPERATIONS_HEADER:
                in_table = True
                continue
            # 認識できないヘッダの行も含め、空でない表らしい行を独立に記録する。
            candidate_lines += 1
            if not in_table or len(cols) < 3:
                continue
            expected.update(C._parse_route_cell(cols[2]))

        ops = C.load_operations(str(REAL_OPERATIONS))
        if candidate_lines == 0:
            # 真のスケルトン (表らしい行が 1 行も無い) だけが静かに通る。
            self.assertEqual(ops, {}, "表らしい行が 0 なのに join キーが出ている")
            return

        # ヘッダ契約の消滅をスケルトンと誤認しない: 行があるのにオラクルが
        # ヘッダを認識できないなら、実装側の認識と共倒れせずここで赤にする。
        self.assertGreater(
            len(expected), 0,
            f"パイプ形式の行が {candidate_lines} 行あるのに 5 列固定ヘッダを認識できない "
            "(生成物のヘッダ契約が変わった — オラクルと前提の見直しが要る)",
        )
        self.assertEqual(
            set(ops), expected,
            "load_operations の join キーが name 列 (5 列固定契約の第 3 列) と食い違う "
            "(列の取り違え・ヘッダ認識の退行・生成物の契約変更のいずれか)",
        )
        for name in ops:
            self.assertTrue(name.strip(), "空の join キーが混入している")

        # 補助 (正典 t2 の集約形): 「全行が一致 = 列の取り違え」だけを落とし、
        # 正例 (不一致行) 1 件以上を要求する。
        mismatched = [name for name, info in ops.items() if name != info.get("operation")]
        self.assertGreater(
            len(mismatched), 0,
            "全 {} 行で join キーが URL 列と一致 = name 列でなく URL 列を拾っている "
            "(fix-gate #3 違反)".format(len(ops)),
        )
```

設計判断の注記:

- **集約形だけでは中心変異を検出できない** (Codex design-review Round 2 [Critical])。
  name 列を URL 列へ取り違えた場合、`_parse_route_cell()` が URL を `/` 分割して
  複数の短いキーを生み、`operation` (URL 全体) と一致しないため `mismatched ≥ 1` が
  緑のまま通る。実ファイル検査の本命は**独立オラクルとの集合完全一致**とし、
  集約形は正典 t2 の形の保存として補助に降格する
- 旧コードの `if not ops: skipTest` (fail-open) は「**表らしい行が 1 行も無い**
  (candidate_lines == 0) ときだけ ops == {} を確認して静かに通る」に置き換わる。
  行の存在はヘッダ認識と**独立に**数える — スケルトン判定を expected (オラクルの
  ヘッダ認識に依存) で行うと、生成物のヘッダ変更でオラクルと実装の認識が**同時に**
  失敗したとき共倒れで緑になる (Codex design-review Round 3 [Critical])。
  「行があるのにヘッダを認識できない」は expected 非空要求で、「行があるのに
  実装が 0 件」は集合不一致で、それぞれ赤になる

### PHPStan適合チェック

- [x] PHP 変更なし (対象外)

### テスト計画 (テストファースト)

- [x] 先に赤の確認 (変異、恒久コードに残さない)。**当該テストメソッドを単独指定**
  (`python3 -m unittest test_correlate.LoadOperationsTest.test_real_operations_md_name_column_join_keys`)
  で実行し、**この検査自身が狙った理由で赤になる**ことを確認・記録する
  (スイート全体の赤では合成テストが先に検出して検出力を誤認するため):
  (1) `load_operations` の name 抽出を URL 列 (index 1) に一時的に固定する →
  期待キー集合との**集合不一致**で赤になること (集約形だけでは緑のままであることも
  併せて観察し、本命がオラクル照合である根拠として記録する)
  (2) `_header_indices` が name 列を見つけられないよう `_NAME_HEADERS` を一時的に壊す →
  expected 非空 vs ops 空の集合不一致で赤になること
  (3) `_NAME_HEADERS` と生成物側ヘッダの**双方**を同じ未対応名へ一時的に変える
  (共倒れの再現) → 「候補行があるのに 5 列固定ヘッダを認識できない」で赤になること
- [x] 正の対照: 現行の実 operations.md (79 データ行) で緑になること。
  正当な一致行 (単一セグメント route) の誤検知がないことは集約形が
  「全行一致のみ赤」であることと、本命のオラクル照合が名前の一致・不一致に
  依存しないことから構造的に保証される
- [x] 既存の他テストは変更しない

### リスク

- operations.md に将来 5 列固定以外の表が足される・生成器がヘッダを変えると
  集合不一致で赤くなる → docstring に前提と直し方を明記済み
  (静かな見逃しにしない意図的な倒し方)
- スケルトン状態 (データ行 0) では従来どおり静かに通る (テンプレート由来の性質を維持)
- オラクルはセル分解に `C._parse_route_cell()` を共用する → セル分解自体の退行は
  この検査では捉えない (合成テスト群 `test_multi_route_cell_and_footnote` 等の担当。
  docstring に検証対象が列選択とヘッダ認識であることを明記)

## 施策 C: 実ルーター検査とコマンド実登録の確認

### 変更箇所

- ファイル: `.claude/skills/app-bug-hunt/coverage/test_correlate.py`
  - モジュール定数追記 (`_SKILL_ROOT` の近く): `_REPO_ROOT` / `ARTISAN`
  - 新クラス `RealRouterTest` を追加
- 参照する現行実装 (変更しない): `correlate.py` L131-147 (`load_route_list`)

### 波及変更

- TypeScript型定義 / API Resource/DTO: なし
- テストファイル: 本施策自体がテスト追加。`BughuntCoverageToolSelfTest.php` の
  timeout 120 秒に対し subprocess 2 回 (約 2-4 秒) 増で収まる (変更不要)

### 現行コード (参照)

```python
# correlate.py — 本番経路 (SKILL.md / README の正式手順は --route-list を省略して呼ぶ)
def load_route_list(path: str | None, *, project_dir: str | None = None) -> list[dict]:
    if path is None:
        cwd = project_dir or "."
        out = subprocess.run(
            ["php", "artisan", "route:list", "--json"],
            cwd=cwd, capture_output=True, text=True, check=True,
        ).stdout
        data = json.loads(out)
    ...
```

自己テストはこの fallback 経路を 1 本も通していない。

### 変更後コード (追加する定数とテストクラス)

```python
# モジュール冒頭 (_SKILL_ROOT の直後) に追記
_REPO_ROOT = _SKILL_ROOT.parent.parent.parent  # リポジトリルート (worktree でも自 worktree を指す)
ARTISAN = _REPO_ROOT / "artisan"
```

```python
class RealRouterTest(unittest.TestCase):
    """実ルーター経路 (--route-list 省略時の本番経路) の検査 (家系正典 t2 要素 3 の aicue 形)。

    正典の (c) は生成器コマンド (bughunt:resolve-executed) の実登録検査だが、aicue は
    D14 の別実装で生成器を持たない。照合器が実際に依存するコマンドは
    `php artisan route:list --json` (load_route_list(None) の subprocess fallback) なので、
    その実登録と実走を固定し、壊れたとき「何が壊れたか読めない赤」にならないようにする。

    gate: リポジトリルートの artisan 実在 (aicue の checkout では常に実在 = 常時実走。
    skip になるのは coverage/ を Laravel checkout の外へ単独コピーした場合だけ)。
    """

    @unittest.skipUnless(ARTISAN.is_file(), "artisan not present (Laravel checkout の外)")
    def test_route_list_command_is_registered(self):
        # コマンド実登録の確認 (前段)。行頭比較や部分一致は使わない:
        # 各行を strip() し、空白区切りの第 1 トークンの完全一致で route:list を探す。
        try:
            proc = subprocess.run(
                ["php", "artisan", "list", "--raw"],
                cwd=str(_REPO_ROOT), capture_output=True, text=True, timeout=60,
            )
        except subprocess.TimeoutExpired:
            self.fail("php artisan list --raw が 60 秒で応答しない (アプリの boot が進まない)")
        self.assertEqual(
            proc.returncode, 0,
            "php artisan list --raw が失敗 (アプリが起動できない):\n"
            f"rc={proc.returncode}\nstdout:\n{proc.stdout[:2000]}\nstderr:\n{proc.stderr[:2000]}",
        )
        registered = {
            line.strip().split()[0]
            for line in proc.stdout.splitlines() if line.strip()
        }
        self.assertIn(
            "route:list", registered,
            "route:list コマンドが実登録されていない (artisan list --raw に現れない)",
        )

    @unittest.skipUnless(ARTISAN.is_file(), "artisan not present (Laravel checkout の外)")
    def test_load_route_list_fallback_returns_named_routes(self):
        # 実ルーター経路の実走 (本命)。本命呼び出しそのものに診断を付ける
        # (事前の自前実行は置かない — 事前の成功は本命の診断可能性を保証しない)。
        try:
            routes = C.load_route_list(None, project_dir=str(_REPO_ROOT))
        except subprocess.CalledProcessError as e:
            self.fail(
                "php artisan route:list --json が失敗:\n"
                f"rc={e.returncode}\nstderr:\n{(e.stderr or '')[:2000]}"
            )
        except json.JSONDecodeError as e:
            self.fail(
                f"route:list の出力が JSON として読めない: {e} "
                f"(本文先頭: {e.doc[:200]!r})"
            )
        self.assertIsInstance(routes, list)
        named = [r for r in routes if isinstance(r, dict) and r.get("name")]
        self.assertGreater(
            len(named), 0,
            "name を持つ route が 0 件 = 実ルーター経路が壊れている",
        )
```

### PHPStan適合チェック

- [x] PHP 変更なし (対象外)

### テスト計画 (テストファースト)

- [x] 先に赤の確認 (変異、恒久コードに残さない):
  (1) `load_route_list` の subprocess 引数を一時的に壊す (`route:lists` 等) →
  本命テストが CalledProcessError 経路の読める `self.fail` で赤になること
  (2) 登録判定のトークンを一時的に `route:listX` へ変える → 前段テストが赤になること
- [x] 正の対照: 実環境 (dev container / worktree) で 2 テストとも緑・追加所要 2-4 秒程度
- [x] `python3 -m unittest test_correlate` 単独実行と Pest 結線 (`composer test`) の
  両方で通ることを確認 (Pest は APP_ENV=testing を子へ伝播するが、`route:list` は
  testing 環境でも全 route を登録し DB へ接続しないため成立する。万一 boot が
  環境依存で失敗した場合も前段テストが rc/stderr 付きの読める赤で現れる)

### リスク

- `load_route_list()` 内部の subprocess には timeout が無い (実装不変のため触らない)。
  単独実行での長時間停止リスクは残るが、Pest 結線側の外側 timeout 120 秒が覆う。
  前段テストの 60 秒 timeout が boot 不能の大半を先に読める赤で捉える
- Pest 実行下の artisan 子プロセスは親の env (APP_ENV=testing / DB_* 等) を継承する。
  `route:list` は DB 接続を要しないため成立するが、将来 route 定義ファイルが boot 時に
  外部資源へ触れる変更が入ると本テストが赤くなる → それは実ルーター経路 (本番の
  カバレッジ突合) 自体が壊れたことを意味するので、赤にする挙動が正しい
- 並列テスト (`--parallel`) 下でも `route:list` は読み取りのみで副作用がなく干渉しない

## 施策 D: README・docblock の契約説明の同期

### 変更箇所

- ファイル: `.claude/skills/app-bug-hunt/coverage/README.md`
  - `test_correlate.py` の説明行 (現行 L67) へ実ルーター検査の一言を追記
  - correlate.py の入力一覧 (「### 主な入力」相当の節) へ 6 点目として run_id を追記
  - 終了コード表へコード 2 の行を追加
  - 終了コード表の直後の説明段落へ契約の 1 文を追加
- ファイル: `.claude/skills/app-bug-hunt/coverage/correlate.py`
  - 冒頭 docblock の 1 文のみ同期 (**挙動変更なし・コメントのみ**)

### 現行 → 変更後

```markdown
# README L67 現行:
| `test_correlate.py` | correlate のテスト（`python3 -m unittest`、graph は fixture sqlite で生成。実 operations.md / 実 graph.db があれば fix-gate #3/#4 を追加検証） |
# 変更後:
| `test_correlate.py` | correlate のテスト（`python3 -m unittest`、graph は fixture sqlite で生成。実 operations.md / 実 graph.db があれば fix-gate #3/#4 を、artisan があれば実ルーター経路 (`route:list` fallback) を追加検証。主入力 6 点の欠落は 1 点ずつ pin） |

# README の correlate.py 入力一覧 (現行 5 項目) へ 6 点目を追記:
6. `run_id` — join キー (ファイルではなく `--run-id` で渡す走行の識別子)。
   executed.json / findings の run_id との一致を検査する

# README の終了コード表へ行を追加:
| 2 | CLI usage error | argparse の required オプション (`--operations` / `--findings` / `--graph-db` / `--run-id`) の欠落・引数形式不正 (usage を stderr に出す) |

# README 終了コード表直後の段落へ追記する 1 文:
主入力 6 点 (route 一覧・operations・findings・executed・graph.db・run_id) のいずれかが
不足した走行は、コードが 1 / 2 / 3 のいずれで落ちる場合も**非 0 で終了し worklist を
出力しない**ことが契約である (終了コードはこの契約の写像にすぎない)。
```

```python
# correlate.py 冒頭 docblock 現行 (L8-11):
"""**主入力が揃わない走行は成功にしない** (終了コード 3)。executed.json が無い / 別 run /
形が契約外 / 観測行 0 のときは worklist を出さずに落ちる (揃わない走行を
「全件未実行」という嘘の一覧として返さないため)。"""
# 変更後 (コメントのみ・挙動不変):
"""**主入力が揃わない走行は成功にしない** (非 0 で落ち worklist を出さない。
executed の可用性違反は終了コード 3)。--executed が無い / 別 run / 形が契約外 /
観測行 0 のときは worklist を出さずに 3 で落ち、入力ファイルの不在・parse 失敗は 1、
required オプションの欠落は usage エラー 2 で落ちる (揃わない走行を
「全件未実行」という嘘の一覧として返さないため)。"""
```

概念設計の「correlate.py は変えない」はコードの**挙動**についての宣言であり、
docblock の 1 文同期はこれを破らない (Codex design-review Round 2 [Warning] 4 への対応。
挙動を変えないコメント訂正は AGENTS.md の走査器 4 点セットの発火条件にも当たらない)。

### 波及変更 / PHPStan / テスト計画

- なし (説明文の同期のみ)。契約の機械固定は `test_correlate.py` の新テストが担う。
  docblock 同期が挙動を変えないことは既存テスト全緑 (`python3 -m unittest test_correlate`)
  で確認する

### リスク

- なし (文書・コメントのみ。README の終了コード表は「両ツール共通」の見出しを持つが、
  コード 2 の行は correlate.py 側の argparse 挙動の記述であり、build_executed.py も
  argparse required (`--run-id` / `--shard` / `--out`) の欠落で同じく 2 を返すため
  矛盾しない (機械確認済み: build_executed.py L180-185 の required=True 定義))

## 乖離台帳の確認 (app-design Phase 3-0 対応)

- 変更対象 3 ファイル (`.claude/skills/app-bug-hunt/coverage/test_correlate.py` /
  `coverage/README.md` / `coverage/correlate.py` (docblock のみ)) は
  `docs/template-fingerprints.json` のキーに**存在しない**
  (機械確認済み: キーに `coverage` / `correlate` を含むパスは 0 件) = テンプレート共有
  ファイルではない
- `tests/Support/TemplateDivergence/adoption-debt.tsv` にも該当パスなし (機械確認済み)
- したがって `docs/template-divergence.md` への登録追加・`LedgerPins` の件数更新は不要。
  本変更は登録済み D14 (実行済み route の記録をアプリ側の観測器で採る別実装) の範囲内の
  自己テスト強化である

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 変更は coverage/ 配下 3 ファイル (自己テスト + README + docblock 1 文) に閉じ、アプリ実装の挙動・スキーマ・route に触れない。他機能の実装と依存関係がなく、単独の worktree で完結する |
| 競合リスク | `test_correlate.py` を触る他施策が並走しない限りなし。bug-hunt 実走 run とも独立 (自己テストのみ) |

## 検証コマンド (実装完了条件)

- `cd .claude/skills/app-bug-hunt/coverage && python3 -m unittest test_correlate -v` 全緑
- `composer test` (Architecture レーンの `BughuntCoverageToolSelfTest` 経由で実走) 全緑
- 変異での赤確認の記録を実装 devnotes に残す (テストファースト証跡)
- `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` ほか検証コマンド一式
  (PHP/TS 変更なしのため既存緑の維持確認)

## 実装差分 (git diff HEAD)

```diff
diff --git a/.claude/skills/app-bug-hunt/coverage/README.md b/.claude/skills/app-bug-hunt/coverage/README.md
index 1197bd71..63000fd6 100644
--- a/.claude/skills/app-bug-hunt/coverage/README.md
+++ b/.claude/skills/app-bug-hunt/coverage/README.md
@@ -64,7 +64,7 @@ ## 構成
 | `correlate.py` | **操作到達カバレッジ correlator**。run_id で executed / findings / operations / graph を突合し未カバー worklist を作る（stdlib のみ） |
 | `merge_pcov.py` | **コード到達カバレッジ pcov merge**。C3 middleware が吐く shard JSONL を union し uncovered を主出力する（stdlib のみ） |
 | `test_build_executed.py` | build_executed のテスト（`python3 -m unittest`、入出力は tempfile） |
-| `test_correlate.py` | correlate のテスト（`python3 -m unittest`、graph は fixture sqlite で生成。実 operations.md / 実 graph.db があれば fix-gate #3/#4 を追加検証） |
+| `test_correlate.py` | correlate のテスト（`python3 -m unittest`、graph は fixture sqlite で生成。実 operations.md / 実 graph.db があれば fix-gate #3/#4 を、artisan があれば実ルーター経路 (`route:list` fallback) を追加検証。主入力 6 点の欠落は 1 点ずつ pin） |
 | `test_merge_pcov.py` | merge のテスト（全 fixture、pcov 不要） |
 | `test_naming_no_stale.py` | 旧 Stage 付番と旧 fail-open 文言の後退防止 self-test |
 | `fixtures/` | サンプル入力（route-list / operations(5列+6列) / findings / executed）と `fixtures/pcov/` の shard JSONL |
@@ -104,6 +104,8 @@ ### 入力
    （2xx と errors の無い 3xx が `ok`、それ以外は `blocked`）。executed 扱いになるのは `ok` だけ。
    `unresolved` は「記録器まで到達したが名前の無い route」の件数（shard 別）。
 5. `graph.db` — TESTED_BY を controller ファイル単位で引く（`/workspace/.code-review-graph/graph.db`）。
+6. `run_id` — join キー (ファイルではなく `--run-id` で渡す走行の識別子)。
+   executed.json / findings の run_id との一致を検査する。
 
 ### 記録 → 集約 → 突合 の流れ
 
@@ -126,11 +128,15 @@ ### 終了コード規約（両ツール共通）
 |---|---|---|
 | 0 | 成立 | — |
 | 1 | 読み込み・parse・I/O の失敗 | （例外メッセージ） |
+| 2 | CLI usage error | argparse の required オプション (`--operations` / `--findings` / `--graph-db` / `--run-id`) の欠落・引数形式不正 (usage を stderr に出す) |
 | 3 | **主入力の可用性違反**（検査を成立させられない） | `build_executed.py`: `capture_failed` / `capture_file_missing` / `capture_line_broken` / `capture_row_invalid` / `run_id_mismatch` / `capture_empty`<br>`correlate.py`: `executed_missing` / `executed_schema_invalid` / `executed_run_id_mismatch` / `executed_shards_missing` / `executed_no_rows` / `executed_shard_mismatch` |
 
 3 のときは worklist / executed.json を**書き出さない**。揃わない走行を「全件未実行」という
 嘘の一覧として返さないためである。`ok` が 1 件も無い（全操作が跳ねた）走行は 3 ではない
 —— 主入力としては成立しており、正しい結果は「全機構が未実行 worklist に残る」ことである。
+主入力 6 点 (route 一覧・operations・findings・executed・graph.db・run_id) のいずれかが
+不足した走行は、コードが 1 / 2 / 3 のいずれで落ちる場合も**非 0 で終了し worklist を
+出力しない**ことが契約である (終了コードはこの契約の写像にすぎない)。
 
 ### 使い方
 
diff --git a/.claude/skills/app-bug-hunt/coverage/correlate.py b/.claude/skills/app-bug-hunt/coverage/correlate.py
index 924668a2..3d68deae 100644
--- a/.claude/skills/app-bug-hunt/coverage/correlate.py
+++ b/.claude/skills/app-bug-hunt/coverage/correlate.py
@@ -5,8 +5,10 @@ run_id を軸に route インベントリ / operations.md(機構分母) /
 executed.json(実行済み route の記録。build_executed.py が作る) /
 findings.jsonl / graph.db(TESTED_BY) を join し、**未カバー worklist** を出す。
 
-**主入力が揃わない走行は成功にしない** (終了コード 3)。executed.json が無い / 別 run /
-形が契約外 / 観測行 0 のときは worklist を出さずに落ちる (揃わない走行を
+**主入力が揃わない走行は成功にしない** (非 0 で落ち worklist を出さない。
+executed の可用性違反は終了コード 3)。--executed が無い / 別 run / 形が契約外 /
+観測行 0 のときは worklist を出さずに 3 で落ち、入力ファイルの不在・parse 失敗は 1、
+required オプションの欠落は usage エラー 2 で落ちる (揃わない走行を
 「全件未実行」という嘘の一覧として返さないため)。
 
 主出力 = worklist (未実行機構 / TESTED_BY untested(TS面のみ) / finding hotspot /
diff --git a/.claude/skills/app-bug-hunt/coverage/test_correlate.py b/.claude/skills/app-bug-hunt/coverage/test_correlate.py
index 057a2cf7..c4525f28 100644
--- a/.claude/skills/app-bug-hunt/coverage/test_correlate.py
+++ b/.claude/skills/app-bug-hunt/coverage/test_correlate.py
@@ -8,9 +8,12 @@ graph.db はテスト用 temp sqlite を実 DB のスキーマ(edges.kind/source
 """
 from __future__ import annotations
 
+import contextlib
+import io
 import json
 import os
 import sqlite3
+import subprocess
 import tempfile
 import unittest
 from pathlib import Path
@@ -21,6 +24,8 @@ import correlate as C
 _SKILL_ROOT = Path(__file__).resolve().parent.parent  # .claude/skills/app-bug-hunt/
 REAL_OPERATIONS = _SKILL_ROOT / "operations.md"
 REAL_GRAPH_DB = Path("/workspace/.code-review-graph/graph.db")
+_REPO_ROOT = _SKILL_ROOT.parent.parent.parent  # リポジトリルート (worktree でも自 worktree を指す)
+ARTISAN = _REPO_ROOT / "artisan"
 
 
 # --------------------------------------------------------------------------- #
@@ -161,31 +166,77 @@ class LoadOperationsTest(unittest.TestCase):
         self.assertIn("organizations.members.update-api-key-permission", ops)
         self.assertIn("recent-auth.password", ops)  # 脚注除去
 
+    # 生成器 (scripts/bug-hunt-inventory.py) が書く 5 列固定ヘッダ (operations.md の契約)。
+    # オラクルのヘッダ認識に C._header_indices() を使わない — 実装をオラクルにすると
+    # ヘッダ検出の退行時に期待値と実値が同時に 0 件になり共倒れする。
+    _REAL_OPERATIONS_HEADER = ["method", "route", "name", "story", "区分"]
+
     @unittest.skipUnless(REAL_OPERATIONS.is_file(), "real operations.md not present")
     def test_real_operations_md_name_column_join_keys(self):
-        # fix-gate #3 の load-bearing claim: 実 operations.md の join キー = name 列 (URL 列ではない)。
-        # テンプレート汎用化: 特定アプリの route 名を hardcode せず、構造で検証する
-        # (アプリが operations.md を埋めた後も、slug 非依存で機能する)。
-        ops = C.load_operations(str(REAL_OPERATIONS))
-        if not ops:
-            self.skipTest("operations.md はスケルトン (データ行なし)。route:list から生成後に有効化される")
+        """実 operations.md の join キー集合を独立オラクルとの完全一致で固定する。
+
+        オラクル: 生成器が書く 5 列固定ヘッダ `| method | route | name | story | 区分 |`
+        の厳密一致で表の内部を判定し、データ行の第 3 列 (name) を _parse_route_cell で
+        分解して期待キー集合を作る (検証対象は**列選択とヘッダ認識**。セル分解の検出力は
+        合成テスト群が担う)。集合の完全一致は対称なので、load_operations 側の
+        ヘッダ認識・列選択が壊れても、生成器がヘッダを変えても、どちらでも赤になる。
+
+        「全行一致 = 列の取り違え」の集約形 (正典 t2) は補助として併置する。
+        単一セグメント route では URL と route 名が正当に一致しうるため、
+        行単位の不一致 assert は使わない (誤検知するため)。
+
+        前提: aicue の operations.md は生成物で、route 操作の 5 列表だけを含む
+        (ファイル冒頭に「5 列固定 (coverage/correlate.py の入力契約)」と明記されている)。
+        6 列表など別形の表が正当に足されたときは本検査が集合不一致の赤になる —
+        静かな見逃しではなく前提の見直しを促す赤であり、そのときはオラクルを
+        生成物の実態に合わせて更新する。スケルトン (データ行 0) のときだけ静かに通る。
+        """
+        expected: set[str] = set()
+        candidate_lines = 0  # 表らしい行の存在をヘッダ認識と独立に数える (共倒れ防止)
+        in_table = False
+        for raw in REAL_OPERATIONS.read_text(encoding="utf-8").splitlines():
+            line = raw.strip()
+            if not line.startswith("|"):
+                in_table = False  # 表は先頭パイプ行の連続。非パイプ行で表を抜ける
+                continue
+            if "---" in line:
+                continue
+            cols = [c.strip() for c in line.strip("|").split("|")]
+            if [c.lower() for c in cols] == self._REAL_OPERATIONS_HEADER:
+                in_table = True
+                continue
+            # 認識できないヘッダの行も含め、空でない表らしい行を独立に記録する。
+            candidate_lines += 1
+            if not in_table or len(cols) < 3:
+                continue
+            expected.update(C._parse_route_cell(cols[2]))
 
+        ops = C.load_operations(str(REAL_OPERATIONS))
+        if candidate_lines == 0:
+            # 真のスケルトン (表らしい行が 1 行も無い) だけが静かに通る。
+            self.assertEqual(ops, {}, "表らしい行が 0 なのに join キーが出ている")
+            return
+
+        # ヘッダ契約の消滅をスケルトンと誤認しない: 行があるのにオラクルが
+        # ヘッダを認識できないなら、実装側の認識と共倒れせずここで赤にする。
+        self.assertGreater(
+            len(expected), 0,
+            f"パイプ形式の行が {candidate_lines} 行あるのに 5 列固定ヘッダを認識できない "
+            "(生成物のヘッダ契約が変わった — オラクルと前提の見直しが要る)",
+        )
+        self.assertEqual(
+            set(ops), expected,
+            "load_operations の join キーが name 列 (5 列固定契約の第 3 列) と食い違う "
+            "(列の取り違え・ヘッダ認識の退行・生成物の契約変更のいずれか)",
+        )
         for name in ops:
-            # route 名は通常ドット区切り (resource.action)。少なくとも空でないこと。
             self.assertTrue(name.strip(), "空の join キーが混入している")
 
-        # join キー (name 列) と URL 列 (load_operations は 'operation' に格納) の一致を
-        # **集約で**判定する。
-        #
-        # 検出したい failure mode は「load_operations が name 列でなく URL 列を join キーに
-        # している」ことであり、それが起きると **全行が一致する**。
-        # 一方、単一セグメント route は route 名と URL が正当に同値になる
-        # (Laravel の `Route::post('logout', ...)->name('logout')` 等)。
-        # 行単位の assertNotEqual だとこの正当なケースを偽陽性で落とすため、
-        # 「**全行が一致していないこと**」を条件にする (検出力は維持される)。
-        matched = [name for name, info in ops.items() if name == info.get("operation")]
-        self.assertNotEqual(
-            len(matched), len(ops),
+        # 補助 (正典 t2 の集約形): 「全行が一致 = 列の取り違え」だけを落とし、
+        # 正例 (不一致行) 1 件以上を要求する。
+        mismatched = [name for name, info in ops.items() if name != info.get("operation")]
+        self.assertGreater(
+            len(mismatched), 0,
             "全 {} 行で join キーが URL 列と一致 = name 列でなく URL 列を拾っている "
             "(fix-gate #3 違反)".format(len(ops)),
         )
@@ -713,6 +764,131 @@ class MainTest(unittest.TestCase):
         self.assertIn("executed_schema_invalid", reason)
 
 
+class MainInputAvailabilityTest(unittest.TestCase):
+    """主入力 6 点の欠落を 1 点ずつ pin する (家系正典 t2 要素 1 の aicue 形)。
+
+    aicue の照合器の主入力は 6 点 — 目録 (--operations) / 所見 (--findings) /
+    実行済み (--executed) / graph.db (--graph-db) / 走行 id (--run-id) /
+    route 一覧 (--route-list。省略は欠落ではなく実ルーター fallback = RealRouterTest が担当)。
+
+    守る不変条件は「主入力が揃わない走行を成功にしない・worklist を 1 行も出さない」で、
+    終了コードはその写像 (契約の正本は coverage/README.md):
+      - オプション欠落: argparse required の 4 点は usage エラー (SystemExit 2)、
+        --executed は main 内の可用性検査 (return 3 = executed_missing)
+      - ファイル不在・glob 0 件: 読み込みの失敗 (return 1)
+    正典実装は全欠落を 3 へ写像するが、aicue は D14 の別実装として上記の既存契約を pin する
+    (終了コードの写像替えは README / SKILL.md 運用文まで波及する別議題)。
+
+    将来 main() が stdout へ診断を出す設計に変わる場合は、「worklist を出さない」と
+    「stdout 完全無出力」を別契約として本クラスの assert を再検討すること。
+    """
+
+    def setUp(self):
+        self.tmp = tempfile.TemporaryDirectory()
+        self.addCleanup(self.tmp.cleanup)
+        base = Path(self.tmp.name)
+        self.route_path = base / "route.json"
+        self.route_path.write_text(
+            '[{"name":"register.store","method":"POST","uri":"register",'
+            '"action":"App\\\\Http\\\\Controllers\\\\Auth\\\\RegisteredUserController@store"}]',
+            encoding="utf-8",
+        )
+        self.ops_path = base / "operations.md"
+        self.ops_path.write_text(
+            "| method | route | name | story | 区分 |\n"
+            "|---|---|---|---|---|\n"
+            "| POST | register | register.store | S1 | ◎ |\n",
+            encoding="utf-8",
+        )
+        self.findings_path = base / "findings.jsonl"
+        self.findings_path.write_text(
+            '{"finding_id":"F-1","run_id":"R1","route_name":"register.store","species_key":"a"}\n',
+            encoding="utf-8",
+        )
+        self.executed_path = base / "executed.json"
+        self.executed_path.write_text(json.dumps({
+            "run_id": "R1", "shards": ["0"],
+            "executed_routes": [
+                {"route_name": "register.store", "shard": "0", "status": "ok"},
+            ],
+        }), encoding="utf-8")
+        self.db = str(base / "graph.db")
+        make_graph_db(self.db, [
+            ("/workspace/resources/js/x.ts::x", "/workspace/resources/js/t.ts::t"),
+        ])
+        self.argv = [
+            "--route-list", str(self.route_path),
+            "--operations", str(self.ops_path),
+            "--findings", str(self.findings_path),
+            "--executed", str(self.executed_path),
+            "--graph-db", self.db,
+            "--run-id", "R1",
+        ]
+
+    def _run(self, argv):
+        """main() を実行し (終了コード, stdout, stderr) を返す。
+
+        argparse required の欠落は SystemExit を投げるので、その code も
+        終了コードとして扱う (usage エラー 2)。
+        """
+        out, err = io.StringIO(), io.StringIO()
+        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
+            try:
+                rc = C.main(argv)
+            except SystemExit as e:
+                rc = e.code
+        return rc, out.getvalue(), err.getvalue()
+
+    def _drop_option(self, opt):
+        idx = self.argv.index(opt)
+        return self.argv[:idx] + self.argv[idx + 2:]
+
+    def _replace_value(self, opt, value):
+        argv = list(self.argv)
+        argv[argv.index(opt) + 1] = value
+        return argv
+
+    def assert_no_worklist(self, rc, out, expected_rc):
+        self.assertEqual(rc, expected_rc)          # (α) 期待する非 0 終了コード
+        self.assertEqual(out, "")                  # (β) stdout に何も出さない
+        self.assertNotIn("未実行機構", out)          # (γ) 契約の意図の明示 ((β) に包含)
+
+    def test_baseline_is_green(self):
+        # 正の対照: 全部揃っていれば 0 で worklist が出る (欠落検査の前提)。
+        rc, out, err = self._run(self.argv)
+        self.assertEqual(rc, 0, err)
+        self.assertIn("未実行機構", out)
+
+    def test_option_missing_is_rejected_per_input(self):
+        # (--route-list は required でない: 省略 = 実ルーター fallback で欠落ではない)
+        cases = {
+            "--operations": 2, "--findings": 2, "--graph-db": 2, "--run-id": 2,
+            "--executed": 3,
+        }
+        for opt, expected in cases.items():
+            with self.subTest(option=opt):
+                rc, out, err = self._run(self._drop_option(opt))
+                self.assert_no_worklist(rc, out, expected)
+                if expected == 3:
+                    self.assertIn("executed_missing", err)
+
+    def test_file_missing_is_rejected_per_input(self):
+        base = Path(self.tmp.name)
+        for opt in ("--route-list", "--operations", "--findings",
+                    "--executed", "--graph-db"):
+            with self.subTest(option=opt):
+                rc, out, err = self._run(
+                    self._replace_value(opt, str(base / "no-such-input")))
+                self.assert_no_worklist(rc, out, C.EXIT_INPUT_ERROR)
+                self.assertIn("ERROR", err)
+
+    def test_findings_glob_matching_nothing_is_rejected(self):
+        rc, out, err = self._run(
+            self._replace_value("--findings", str(Path(self.tmp.name) / "shard-*" / "findings.jsonl")))
+        self.assert_no_worklist(rc, out, C.EXIT_INPUT_ERROR)
+        self.assertIn("ERROR", err)
+
+
 class ExecutedValidationTest(unittest.TestCase):
     """validate_executed() の単体検査 (成立 → None / 各違反 → 理由文字列)。"""
 
@@ -801,5 +977,66 @@ class StoryCellParseTest(unittest.TestCase):
                     C.parse_story_cell(cell, "r")
 
 
+class RealRouterTest(unittest.TestCase):
+    """実ルーター経路 (--route-list 省略時の本番経路) の検査 (家系正典 t2 要素 3 の aicue 形)。
+
+    正典の (c) は生成器コマンド (bughunt:resolve-executed) の実登録検査だが、aicue は
+    D14 の別実装で生成器を持たない。照合器が実際に依存するコマンドは
+    `php artisan route:list --json` (load_route_list(None) の subprocess fallback) なので、
+    その実登録と実走を固定し、壊れたとき「何が壊れたか読めない赤」にならないようにする。
+
+    gate: リポジトリルートの artisan 実在 (aicue の checkout では常に実在 = 常時実走。
+    skip になるのは coverage/ を Laravel checkout の外へ単独コピーした場合だけ)。
+    """
+
+    @unittest.skipUnless(ARTISAN.is_file(), "artisan not present (Laravel checkout の外)")
+    def test_route_list_command_is_registered(self):
+        # コマンド実登録の確認 (前段)。行頭比較や部分一致は使わない:
+        # 各行を strip() し、空白区切りの第 1 トークンの完全一致で route:list を探す。
+        try:
+            proc = subprocess.run(
+                ["php", "artisan", "list", "--raw"],
+                cwd=str(_REPO_ROOT), capture_output=True, text=True, timeout=60,
+            )
+        except subprocess.TimeoutExpired:
+            self.fail("php artisan list --raw が 60 秒で応答しない (アプリの boot が進まない)")
+        self.assertEqual(
+            proc.returncode, 0,
+            "php artisan list --raw が失敗 (アプリが起動できない):\n"
+            f"rc={proc.returncode}\nstdout:\n{proc.stdout[:2000]}\nstderr:\n{proc.stderr[:2000]}",
+        )
+        registered = {
+            line.strip().split()[0]
+            for line in proc.stdout.splitlines() if line.strip()
+        }
+        self.assertIn(
+            "route:list", registered,
+            "route:list コマンドが実登録されていない (artisan list --raw に現れない)",
+        )
+
+    @unittest.skipUnless(ARTISAN.is_file(), "artisan not present (Laravel checkout の外)")
+    def test_load_route_list_fallback_returns_named_routes(self):
+        # 実ルーター経路の実走 (本命)。本命呼び出しそのものに診断を付ける
+        # (事前の自前実行は置かない — 事前の成功は本命の診断可能性を保証しない)。
+        try:
+            routes = C.load_route_list(None, project_dir=str(_REPO_ROOT))
+        except subprocess.CalledProcessError as e:
+            self.fail(
+                "php artisan route:list --json が失敗:\n"
+                f"rc={e.returncode}\nstderr:\n{(e.stderr or '')[:2000]}"
+            )
+        except json.JSONDecodeError as e:
+            self.fail(
+                f"route:list の出力が JSON として読めない: {e} "
+                f"(本文先頭: {e.doc[:200]!r})"
+            )
+        self.assertIsInstance(routes, list)
+        named = [r for r in routes if isinstance(r, dict) and r.get("name")]
+        self.assertGreater(
+            len(named), 0,
+            "name を持つ route が 0 件 = 実ルーター経路が壊れている",
+        )
+
+
 if __name__ == "__main__":
     unittest.main()
```

## テスト結果

- Python 自己テスト: `python3 -m unittest test_correlate test_build_executed test_merge_pcov test_naming_no_stale` → Ran 121 tests, OK (test_correlate 単独は 66 tests, OK。新規: MainInputAvailabilityTest 4 / RealRouterTest 2、改訂: test_real_operations_md_name_column_join_keys)
- 変異での赤確認 (テストファースト証跡): devnotes/20260826-0132-todo-T265/test-first-mutations.md に 7 変異 (A1/A2/B1/B2/B3/C1/C2) の赤と復元を記録。B1 では集約形だけなら緑のまま (mismatched=73/78) であることも観察済み
- composer test (フルスイート) / phpstan / pint / pnpm 系: 実行中 (PHP/TS 変更なしのため既存緑の維持確認。結果は Round 2 があれば共有)
