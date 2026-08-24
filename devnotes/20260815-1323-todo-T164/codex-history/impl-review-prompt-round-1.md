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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `->withMetadata($context->toMetadata())` で帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) は
   `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
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

# system

あなたは Laravel 12 + Svelte 5 + Inertia のコードレビュアーである。TODO T164
「bug-hunt の実行済み route 記録と照合器の fail-closed 化」の実装差分をレビューせよ。

## レビュー観点

1. **設計との一致性**: 詳細設計書 (下記) の施策 1〜6 が実装されているか。設計から逸脱している箇所は、
   逸脱が正当か (設計の誤りを直したのか / 手抜きなのか) を判定する
2. **正確性**: fail-closed 契約が本当に閉じているか。fail-open へ戻る抜け道が残っていないか。
   Laravel の middleware / priority list / session flash の実装前提が正しいか
3. **PHPStan level 10 適合性**: mixed の絞り込み、null 安全、戻り値型
4. **DTO / JsonResource パターン**: `response()->json()` 直書きが無いか (本件は HTTP endpoint を足していない)
5. **テスト網羅性**: 空振り (常に緑) のテストになっていないか。負の対照が実際に効いているか
6. **セキュリティ**: 既定 no-op の二重門が本当に閉じているか。パス組み立ての境界。
   priority list の変更が既存のテナント境界 404 / 存在オラクル防止の順序契約を壊していないか
7. **DESIGN.md 準拠 / Atomic Design 準拠**: 本差分にフロントエンド (resources/js, resources/css) の
   変更は**無い**ため該当なし

## 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** の 1 語で明示する

---

# user

## 詳細設計書

# 詳細設計: bughunt-route-capture-failclosed

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、
そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) /
> 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン（本件は HTTP endpoint を追加しないため直接の適用対象は無い）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- `.claude/skills/app-bug-hunt/` 配下の Python は **標準ライブラリのみ**、検証は `python3 -m unittest`

## 概念設計リファレンス

`devnotes/20260815-1113-bughunt-route-capture-failclosed/conceptual-design.md` (Codex 合議 Round 5 で APPROVED)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 照合器の fail-closed 化 | `.claude/skills/app-bug-hunt/coverage/correlate.py` / 同 `test_correlate.py` | 最高 |
| 2 | 実行済み route の記録器 (middleware) | `app/Http/Middleware/BughuntExecutedRouteMiddleware.php` (新規) / `config/bughunt.php` / `bootstrap/app.php` / `tests/Support/Routing/MiddlewareShortCircuitInventory.php` (新規・既存分類の移設) / `tests/Feature/Bughunt/ExecutedRouteCaptureTest.php` (新規) / `tests/Architecture/BughuntExecutedRouteOrderingTest.php` (新規) / `tests/Architecture/TenantBoundaryOrderingTest.php` (委譲へ書き換え) | 最高 |
| 3 | bug-hunt 環境への配線 | `scripts/bug-hunt-shard.sh` | 高 |
| 4 | executed.json の生成器 | `.claude/skills/app-bug-hunt/coverage/build_executed.py` (新規) / 同 `test_build_executed.py` (新規) | 高 |
| 5 | 手順・契約の文書更新 | `.claude/skills/app-bug-hunt/SKILL.md` / `coverage/README.md` / `.claude/agents/bughunt-shard.md` / `docs/template-divergence.md` | 中 |
| 6 | Python 自己テスト 3 本の実行レーン結線 | `tests/Architecture/BughuntCoverageToolSelfTest.php` (新規) | 中 |

実装順序は 2 → 3 → 4 → 1 → 5 → 6 を推奨する
(記録器から作ると、照合器を fail-closed にした瞬間に手元で通せる状態を保てる)。

---

## 施策 1: 照合器の fail-closed 化

### 変更箇所

- `.claude/skills/app-bug-hunt/coverage/correlate.py`
  - docstring (L1-41)、`Executed` (L256-305)、`Correlation` (L396-409)、`correlate()` (L412-508)、
    `to_summary()` (L511-530)、`render_worklist()` (L545-633)、`main()` (L636-670)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `coverage/test_correlate.py`
  - **既存 `MainTest` の fixture が `"executed_routes":[]` で exit 0 を期待している** (L487-489, L503-524)。
    新契約では exit 3 になるため、fixture へ ok 行を 1 件足す。
    これは「既存テストの削除・上書き」ではなく、契約変更に伴う fixture の追随である
    (各テストが検証している意図 — markdown 出力 / JSON 出力 / parse error=1 — は変えない)。
  - `CorrelateTest._executed()` は `C.Executed(run_id=..., shards=[...])` を直接組むため
    `present` フィールドの削除の影響を受ける (キーワード引数で渡していないので変更不要)。
- 文書: `coverage/README.md` (施策 5)

### 現行コード

```python
@dataclass
class Executed:
    ...
    present: bool = True  # executed.json が与えられたか

def load_executed(path: str | None) -> Executed:
    if path is None:
        return Executed(run_id=None, shards=[], present=False)
    ...

def main(argv=None) -> int:
    ap.add_argument("--executed", help="executed.json path (省略時 全 in_scope を未実行 candidate)")
    ...
    return 0
```

`render_worklist()` は `present=False` のとき
「⚠ executed.json 未指定 = 全 in_scope 機構を **未実行 candidate** として列挙」という注記を出しつつ、
**終了コード 0 で正常終了する**。これが本件で消す fail-open である。

### 変更後コード

```python
# 終了コード規約 (scripts/bug-hunt-inventory-check.sh と同じ 3 = 契約違反)
EXIT_OK = 0
EXIT_INPUT_ERROR = 1        # 読み込み・parse の失敗 (従来どおり)
EXIT_INPUT_UNAVAILABLE = 3  # 主入力の可用性違反 = 検査を成立させられない


# status の語彙は ok|blocked の 2 値だけを受け付ける。
VALID_STATUSES = {"ok", "blocked"}


@dataclass
class Executed:
    run_id: str | None
    shards: list[str]
    routes: dict[str, set[str]] = field(default_factory=dict)
    statuses: dict[str, set[str]] = field(default_factory=dict)
    row_count: int = 0            # executed_routes の有効行数 (可用性検証に使う)
    schema_error: str | None = None  # 最初に見つかった契約違反 (形・run_id) の説明
    # present フィールドは削除する (後方互換の並走を残さない)

    def is_executed(self, route_name: str) -> bool:
        """status 'ok' を 1 つでも持つ route だけ executed=true。

        **旧「status 未記録なら ok とみなす」救済分岐は削除する** — status を持たない行を
        実行済みに数えるのは fail-open であり、新しい検証では status 欠落行が
        入力エラー (executed_schema_invalid) になるため到達不能でもある。
        """
        return "ok" in self.statuses.get(route_name, set())


def load_executed(path: str) -> Executed:
    """executed.json をロードする。path の省略は受け付けない。

    **入れ物の型から検証する**。dict でない root、list でない shards/executed_routes、
    dict でない行を素通しすると `.get()` や反復で AttributeError / TypeError になり、
    main() の捕捉対象外なので終了コード規約 (1 / 3) から外れて traceback で落ちる。
    `status` も `isinstance(str)` を確認してから集合照合する (非 hashable で TypeError になるため)。
    **JSON 構文エラーと I/O は 1、構文上は読めるが形が契約外なら 3**。
    """
    data = json.loads(Path(path).read_text(encoding="utf-8"))
    if not isinstance(data, dict):
        return Executed(run_id=None, shards=[], schema_error="root が JSON object でない")

    raw_shards = data.get("shards")
    raw_rows = data.get("executed_routes")
    run_id = data.get("run_id")
    ex = Executed(run_id=run_id if isinstance(run_id, str) else None, shards=[])
    if not isinstance(run_id, str) or run_id == "":
        ex.schema_error = f"run_id が非空文字列でない: {run_id!r}"
        return ex
    if not isinstance(raw_shards, list) or not isinstance(raw_rows, list):
        ex.schema_error = "shards / executed_routes が配列でない"
        return ex
    for s in raw_shards:
        if not isinstance(s, str) or s == "":
            ex.schema_error = f"shards に非空文字列でない要素がある: {s!r}"
            return ex
        ex.shards.append(s)

    for row in raw_rows:
        if not isinstance(row, dict):
            ex.schema_error = f"executed_routes の要素が object でない: {row!r:.200}"
            break
        name, shard, status = row.get("route_name"), row.get("shard"), row.get("status")
        if not isinstance(name, str) or name == "" \
                or not isinstance(shard, str) or shard == "" \
                or not isinstance(status, str) or status not in VALID_STATUSES:
            ex.schema_error = repr(row)[:200]
            break
        ex.row_count += 1
        ex.routes.setdefault(name, set()).add(shard)
        ex.statuses.setdefault(name, set()).add(status)
    return ex


def validate_executed(ex: Executed, run_id: str) -> str | None:
    """主入力 (実行済み route の記録) の可用性を検証する。

    返値は違反理由。None なら成立している。
    **`ok` が 0 件は違反にしない** — 全操作が 403/422/500 で跳ねた走行は、
    主入力としては成立しており、正しい結果は「全件を未実行 worklist に残す」ことである。
    """
    # 形の違反を先に見る (root が object でない等のとき run_id 不一致と誤報しないため)
    if ex.schema_error is not None:
        return f"executed_schema_invalid (契約外の形: {ex.schema_error})"
    if ex.run_id != run_id:
        return f"executed_run_id_mismatch (executed.json={ex.run_id!r} / --run-id={run_id!r})"
    if not ex.shards:
        return "executed_shards_missing (shards が空 = どの shard の記録か分からない)"
    if ex.row_count == 0:
        return "executed_no_rows (有効な観測行が 1 件も無い = 記録が採れていない)"
    seen = {s for shards in ex.routes.values() for s in shards}
    declared = set(ex.shards)
    if declared != seen:
        return f"executed_shard_mismatch (宣言={sorted(declared)} / 実際={sorted(seen)})"
    return None


def main(argv=None) -> int:
    ap.add_argument("--executed", help="executed.json path (build_executed.py が生成する)")
    ...
    # argparse の required=True にはしない。required にすると argparse 自身が exit 2 で落ち、
    # 「主入力の可用性違反 = 3」という規約から外れるため、main 内で明示的に検査する。
    if args.executed is None:
        print("ERROR: 主入力が揃わない (reason=executed_missing): "
              "--executed が指定されていない。build_executed.py で executed.json を作ってから渡すこと。",
              file=sys.stderr)
        return EXIT_INPUT_UNAVAILABLE

    try:
        ...
        executed = load_executed(args.executed)
        ...
    except (ValueError, json.JSONDecodeError, OSError, sqlite3.Error,
            subprocess.CalledProcessError) as e:
        print(f"ERROR: {e}", file=sys.stderr)
        return EXIT_INPUT_ERROR

    reason = validate_executed(executed, args.run_id)
    if reason is not None:
        print(f"ERROR: 主入力が揃わない (reason={reason})。"
              " 未実行 worklist は出力しない (揃わない走行を成功として返さないため)。",
              file=sys.stderr)
        return EXIT_INPUT_UNAVAILABLE

    corr = correlate(...)
    ...
    return EXIT_OK
```

- `Correlation.executed_present` フィールドと、`render_worklist()` の
  「⚠ executed.json 未指定」注記ブロック (L553-555) は**削除**する。
- `to_summary()` に `executed_ok_count` / `blocked_count` を出す
  (内訳は可視化のためだけで、終了コードには影響させない)。
  既存の `Executed.skipped_blocked_count()` と summary キー `skipped_blocked_count` は
  **`blocked_count` へ改名**する (`skipped` は手書き時代の語彙で、生成器は出さない。
  語彙を 2 値に統一し、旧語彙は施策 5 の文言 gate で再混入を止める)。README も同時に直す。

### PHPStan適合チェック

- 本施策は Python のみ。該当なし。

### テスト計画

`coverage/test_correlate.py` に `ExecutedValidationTest` (新規クラス) と `MainTest` への追加。

- [ ] **負の対照 1**: `--executed` 未指定で `main()` が **3** を返す (`test_main_missing_executed_returns_3`)
- [ ] **負の対照 2**: executed.json の `run_id` が `--run-id` と違うとき **3** (`test_main_run_id_mismatch_returns_3`)
- [ ] **負の対照 3**: `executed_routes` が空のとき **3** (`test_main_empty_executed_returns_3`)
- [ ] **負の対照 4**: `shards` 宣言と実際の shard 集合が食い違うとき **3** (`test_main_shard_mismatch_returns_3`)
- [ ] **負の対照 5**: `shards` が空のとき **3** (`executed_shards_missing`)
- [ ] **負の対照 6**: 形が契約外のとき **3** (`executed_schema_invalid`)。
      root が object でない / `shards` が配列でない / `executed_routes` が配列でない /
      行が object でない / `status` が未知値 / `status` が非文字列 (dict 等) /
      `route_name` が空 / `shard` が非文字列 /
      **`run_id` が null・空文字・数値** の 3 通り の計 11 通りをそれぞれ 1 本ずつ
      (`run_id` の不正は `executed_run_id_mismatch` ではなく `executed_schema_invalid` になること)。
      **いずれも traceback ではなく終了コード 3 で落ちること**を確認する
- [ ] **既存テストの置換**: `test_missing_status_treated_as_executed` (status 欠落を実行済みと数える
      旧救済の固定) を `test_row_without_status_is_rejected` へ置き換える。
      これは契約の反転に伴う置換であり、検証意図を消すものではない
      (「status 欠落をどう扱うか」を固定し続ける点は同じ)
- [ ] **正の対照**: `ok` が 0 件でも (全行 `blocked` でも) **0** を返し、全機構が未実行 worklist に載る
      (`test_main_all_blocked_is_valid_input`) — Round 2 の Critical の回帰テスト
- [ ] **正の対照**: 従来どおりの正常入力で **0** (既存 `test_main_ok_markdown` / `test_main_ok_json` の
      fixture に ok 行を 1 件足して維持)
- [ ] `validate_executed()` の単体テスト (成立 → None / 各違反 → 理由文字列)
- [ ] 出力に「executed.json 未指定」注記が二度と現れないこと
      (`render_worklist()` の出力文字列を検査。旧 fail-open の文言の再混入防止)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認 (Python のため該当なし)

### リスク

- **bug-hunt Phase 4 が落ちるようになる**。これは意図した破壊的変更だが、施策 2〜4 が揃うまでは
  カバレッジ突合が実行できない。よって同一 TODO・同一ブランチで入れる (部分マージ禁止)。
- `shards` 宣言と実測の突合は、executed.json を手で編集した場合に落ちる。
  生成器が常に一致させるので実運用では起きない。

---

## 施策 2: 実行済み route の記録器 (middleware)

### 変更箇所

- 新規 `app/Http/Middleware/BughuntExecutedRouteMiddleware.php`
- `config/bughunt.php` (L22-28 の return 配列に `executed` 節を追加)
- `bootstrap/app.php` (L121-147 の `$middleware->web(append: [...])` / L253-268 の priority 鎖
  + 順序テストが赤で示した短絡 middleware 分の `appendToPriorityList`)
- 新規 `tests/Support/Routing/MiddlewareShortCircuitInventory.php` (既存分類の移設 + 1 件追加)
- `tests/Architecture/TenantBoundaryOrderingTest.php` の `middlewareShortCircuitInventory()`
  (L120-126) を Support への委譲に書き換える (assert は変えない)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル:
  - 新規 `tests/Feature/Bughunt/ExecutedRouteCaptureTest.php`
  - 新規 `tests/Architecture/BughuntExecutedRouteOrderingTest.php`
  - **既存 `tests/Architecture/TenantBoundaryOrderingTest.php`**: web グループへ middleware を足すと
    解決後の middleware 列に現れるため、短絡分類の目録へ
    `BughuntExecutedRouteMiddleware::class => false` (透過) の登録が**必須**
    (deny-by-default。未分類は「検査1」が fail する)。既存の `BughuntCoverageMiddleware::class => false`
    と同じ形。目録は Support へ移設し、本テストは委譲にする。
  - **実装時の確認**: middleware 列を検査する Architecture テストは他にもある
    (`ManageRouteAuthGuardTest` / `AccountDeletionFreezeRouteGateTest` /
    `ProjectRouteCurrentOrgGuardTest` / `PasskeyRouteProtectionTest` /
    `PasswordConfirmMiddlewareAbsenceTest` 等)。exact-fit の列比較があれば同様に追随が要る。
    `composer test` 全体を通して赤を確認すること。

### 現行コード

`bootstrap/app.php` は bug-hunt の観測器を**グローバル middleware**として最後に足している:

```php
$middleware->append(BughuntCoverageMiddleware::class);
```

グローバル middleware は route 解決より**外側**で走るため、
「認証・課金ゲートを通過したか」を知ることができない。

### 変更後コード

#### (a) `config/bughunt.php`

```php
return [
    'pcov' => [ /* 既存のまま */ ],

    /*
     | 実行済み route の記録 (操作到達カバレッジの主入力)。
     | BughuntExecutedRouteMiddleware が参照する。enabled は env 既定 false で、
     | production では config が真でも構造的に no-op になる。
     | run/shard は出力ファイル名に使うため、middleware 側で書式検査を通す。
     | scripts/bug-hunt-shard.sh provision が BUGHUNT_EXECUTED* を serve に渡す。
     */
    'executed' => [
        'enabled' => (bool) env('BUGHUNT_EXECUTED', false),
        'run' => env('BUGHUNT_EXECUTED_RUN'),
        'shard' => env('BUGHUNT_EXECUTED_SHARD'),
    ],
];
```

#### (b) `app/Http/Middleware/BughuntExecutedRouteMiddleware.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * 実行済み route の記録 (bug-hunt): 「どの操作を実際に叩けたか」を走行中に機械記録する観測器。
 *
 * **位置が意味を持つ**: 本 middleware は web グループの末尾かつ bootstrap/app.php の
 * priority list の鎖の最後に置く。したがって handle() に到達したことは
 * 「認証・テナント境界 404・2FA 強制・メール検証・課金ゲート・退会凍結をすべて通過し、
 * controller 呼び出しの直前まで到達した」という機械的事実を意味する。
 * 上流のいずれかが短絡した要求は記録されず、その route は未実行のまま worklist に残る。
 *
 * **罠**: route middleware の terminate() は Kernel::terminateMiddleware が
 * gatherRouteMiddleware() の全件を回すため、**実際に handle() が走ったかに関係なく**呼ばれる。
 * よって handle() で request attribute に目印を立て、terminate() は目印があるときだけ書く。
 *
 * 出力 (coverage/build_executed.py が consume する契約・JSONL 追記、run×shard ごとに 1 ファイル):
 *   storage/bughunt-executed/{run}-{shard}.jsonl に 1 行 1 要求:
 *     {"run_id":"…","shard":"0","route_name":"projects.store","method":"POST",
 *      "path":"/organizations/1/projects","status":"ok","http_status":302}
 *   書き込みに失敗したら同ディレクトリの {run}-{shard}.error へ理由を追記する
 *   (生成器はこのマーカーを見つけたら終了コード 3 で落ちる = 部分欠測を静かに通さない)。
 */
final class BughuntExecutedRouteMiddleware
{
    /** handle() 到達の目印 (request-scoped。middleware インスタンスに状態を持たせない)。 */
    public const REACHED_ATTRIBUTE = 'bughunt.executed.reached';

    /** run / shard に許す書式 (出力パスの組み立てに入るため狭くする)。 */
    private const TOKEN_PATTERN = '/\A[A-Za-z0-9_.-]+\z/';

    /**
     * 二重の門。どちらか偽なら handle / terminate は完全 no-op。
     * (1) config('bughunt.executed.enabled') — env 既定 false
     * (2) production でない — 誤設定時の構造的な防壁
     * 加えて run / shard の書式検査を通らなければ無効とする。
     */
    public static function enabled(): bool
    {
        if (config('bughunt.executed.enabled') !== true) {
            return false;
        }
        if (app()->isProduction()) {
            return false;
        }

        return self::token('run') !== null && self::token('shard') !== null;
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (self::enabled()) {
            // ここに到達した = 上流の遮断 middleware をすべて通過した。
            $request->attributes->set(self::REACHED_ATTRIBUTE, true);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! self::enabled()) {
            return;
        }
        if ($request->attributes->get(self::REACHED_ATTRIBUTE) !== true) {
            return; // 上流で短絡した = この route には到達していない
        }

        $run = self::token('run');
        $shard = self::token('shard');
        if ($run === null || $shard === null) {
            return; // enabled() で検査済みだが、型を絞るために再確認する
        }

        try {
            $line = self::buildLine($run, $shard, $request, $response);
            if ($line === null) {
                self::markFailure($run, $shard, 'json_encode failed');

                return;
            }
            self::append(self::outputPath($run, $shard), $line);
        } catch (Throwable $e) {
            // 観測器は機能を壊さない。応答は既に送出済み。
            Log::warning('bughunt executed-route capture failed', ['message' => $e->getMessage()]);
            self::markFailure($run, $shard, $e->getMessage());
        }
    }

    /**
     * 1 要求を JSONL の 1 行 (末尾改行込み) に組み立てる。json_encode 失敗時は null。
     */
    private static function buildLine(string $run, string $shard, Request $request, Response $response): ?string
    {
        $route = $request->route();
        $name = $route instanceof Route ? $route->getName() : null;

        /** @var array{run_id: string, shard: string, route_name: string|null, method: string, path: string, status: string, http_status: int} $row */
        $row = [
            'run_id' => $run,
            'shard' => $shard,
            'route_name' => is_string($name) && $name !== '' ? $name : null,
            'method' => $request->getMethod(),           // 常に大文字の HTTP method
            'path' => $request->getPathInfo(),
            'status' => self::classify($request, $response),
            'http_status' => $response->getStatusCode(),
        ];

        $json = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? null : $json."\n";
    }

    /**
     * ok / blocked の写像。判定不能は過小申告 (blocked) 側へ倒す。
     *
     *   2xx                              → ok
     *   3xx かつ session に errors がある → blocked (FormRequest 不合格。middleware より後に起きる)
     *   3xx (その他)                     → ok (controller が返した正常なリダイレクト)
     *   それ以外                          → blocked
     *
     * ここに到達している時点で認証・課金ゲート等はすべて通過済みなので、
     * 「ゲートに遮断された 302」と「正常な PRG」を状態コードで見分ける必要は無い。
     *
     * `errors` は「今回のリクエストで flash されたもの」だけが残る:
     * Store::save() が ageFlashData() を呼び、前リクエストの flash はここで忘れられる。
     * 保存は StartSession::handleStatefulRequest() が下流の応答を受け取った後、
     * **応答の巻き戻り中**に saveSession() を呼んで行う。記録器の terminate() は
     * その後 (Kernel の terminate 処理) に走るので、読む時点では世代更新済みである。
     * **framework の内部実装に依存する**ので、
     * 「直前に不合格 → 次のリクエストの成功 302 が ok」を Feature テストで固定する。
     */
    private static function classify(Request $request, Response $response): string
    {
        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return 'ok';
        }
        if ($status >= 300 && $status < 400) {
            if ($request->hasSession() && $request->session()->has('errors')) {
                return 'blocked';
            }

            return 'ok';
        }

        return 'blocked';
    }

    /** 出力先。env でパスを受け取らない (任意の場所へ書ける口を作らない)。 */
    public static function outputPath(string $run, string $shard): string
    {
        return storage_path('bughunt-executed'.DIRECTORY_SEPARATOR.$run.'-'.$shard.'.jsonl');
    }

    public static function failurePath(string $run, string $shard): string
    {
        return storage_path('bughunt-executed'.DIRECTORY_SEPARATOR.$run.'-'.$shard.'.error');
    }

    /** config から run / shard を取り、書式検査を通ったものだけ返す。 */
    private static function token(string $key): ?string
    {
        $value = config('bughunt.executed.'.$key);
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return preg_match(self::TOKEN_PATTERN, $value) === 1 ? $value : null;
    }

    /**
     * 1 行を 1 回の追記で書く (並行要求で行が混線しないよう LOCK_EX)。
     * 失敗したら失敗マーカーを残す。
     */
    private static function append(string $path, string $line): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
        if (file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('failed to append '.$path);
        }
    }

    /** best-effort。ここが書けない障害は検出できない (詳細設計の「保証しないもの」)。 */
    private static function markFailure(string $run, string $shard, string $reason): void
    {
        $path = self::failurePath($run, $shard);
        $dir = dirname($path);
        if (! is_dir($dir)) {
            // append() より前に落ちた場合 (buildLine 失敗等) はディレクトリが無いことがある。
            @mkdir($dir, 0o775, true);
        }
        @file_put_contents($path, $reason."\n", FILE_APPEND | LOCK_EX);
    }
}
```

#### (c) `bootstrap/app.php`

```php
$middleware->web(append: [
    HandleInertiaRequests::class,
    SecurityHeaders::class,
    RequireTwoFactorForEnforcedOrganizations::class,
    BlockTwoFactorDisableForEnforcedOrganizations::class,
    NoStoreCacheHeadersForAuthenticatedPages::class,
    EncryptHistory::class,
    // bug-hunt: 実行済み route の記録。**列の最後**に置き、priority list でも鎖の最後に固定する
    // (= ここへ到達したことが「遮断 middleware をすべて通過した」証拠になる)。
    // 既定 no-op (config('bughunt.executed.enabled') 既定 false + production 除外)。
    BughuntExecutedRouteMiddleware::class,
]);
```

priority 鎖 (既存の foreach 配列) の末尾へ 1 行足す:

```php
[RequireActiveSubscription::class, EnsureAccountNotPendingDeletion::class],
// 記録器は鎖の最後 (遮断 middleware より内側) に固定する。
[EnsureAccountNotPendingDeletion::class, BughuntExecutedRouteMiddleware::class],
```

> **priority list の性質に注意** (bootstrap/app.php の既存コメントが正本):
> priority list は「載っている middleware 同士の相対順序」しか強制しない
> (`SortedMiddleware` は priority map に無い要素を一切動かさない)。

> ⚠ **鎖への追加だけでは足りない**。web グループの middleware は
> **route 個別 middleware より前**に並ぶため、priority list に載っていない route 個別の
> 短絡 middleware (`recent-auth` / `ensure-login-method` / `verified.or-back` / `signed` 等) は
> **記録器より後ろで走る**。その状態で `recent-auth` が 302 を返すと、
> セッションに errors が無いため `ok` と誤記録される = 本件が消そうとしている偽陽性そのもの。
> したがって **「短絡しうると分類された middleware はすべて記録器より前」** を
> deny-by-default の Architecture テストで固定し、違反した middleware は
> `appendToPriorityList($短絡middleware, BughuntExecutedRouteMiddleware::class)` で解消する。
> **何本必要になるかはテストが赤で示す**ので、設計では推測で列挙しない。

#### (d) 短絡分類の目録を Support へ移す

現在 `tests/Architecture/TenantBoundaryOrderingTest.php` の**関数**
`middlewareShortCircuitInventory()` が「解決済み middleware => 短絡しうるか」の分類を持っている。
新しい順序テストも同じ分類を使うので、**同じ表を 2 か所に置かない**ために Support へ移す。

- 新規 `tests/Support/Routing/MiddlewareShortCircuitInventory.php`
  - `public static function classification(): array` — 現在の配列をそのまま移す (**純粋な移動**)
  - `public static function shortCircuiting(): array` — `true` のクラス一覧
  - 新規登録: `BughuntExecutedRouteMiddleware::class => false`
    (観測器。必ず `$next` を呼び、応答を加工しない = 短絡しない)
- `TenantBoundaryOrderingTest.php` の `middlewareShortCircuitInventory()` は
  この Support クラスへの薄い委譲にする。**assert は 1 つも変えない**
  (既存の検査 1 / 2 / 3 の意味は不変)。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`?string` / `string` / `void` / `bool`)
- [x] null 安全: `$request->route()` は `Route|object|string|null` を返しうるので
      `instanceof Route` で絞ってから `getName()` を呼ぶ。`getName()` は `?string` なので
      `is_string() && !== ''` で絞る
- [x] `config()` の戻り値は `mixed`。`!== true` / `is_string()` で絞ってから使う
      (**`mixed` のまま `preg_match` / パス結合 / `json_encode` へ渡さない**)
- [x] `$request->session()` は session が無いと例外を投げるため、必ず `hasSession()` を先に見る
- [x] `json_encode()` の `false` を明示的に処理する
- [x] 行の形は PHPDoc の array shape で宣言する (DTO は作らない —
      4 フィールドの内部観測行のために値オブジェクトを新設しない = 思考原則 2)
- [x] `file_put_contents()` の `false` を明示的に処理する

### テスト計画

#### `tests/Feature/Bughunt/ExecutedRouteCaptureTest.php` (新規)

**実 HTTP 要求で検証する** (`$this->get()` / `$this->post()`)。
`terminate()` を直接呼ぶ形にしない — それでは `bootstrap/app.php` の配線を検証したことにならない。
出力先は `storage_path()` なので、各テストで一時 run 名を使い `afterEach` で掃除する。

- [ ] **既定 (config off) では 1 バイトも書かない** (実 HTTP 要求 → ファイルが存在しない)
- [ ] config on: 認証済みユーザーの 200 GET が `status=ok` / `route_name` / `method=GET` / `http_status=200` で記録される
- [ ] **FormRequest 不合格の 302 が `blocked`** (例: 不正な資格情報での `POST /login` は
      errors を flash して 302 で戻る)
- [ ] **未認証の変更系要求は記録が 1 行も無い** (auth が上流で短絡 = handle() に到達しない)
- [ ] **課金ゲートに遮断された変更系要求は記録が 1 行も無い**
      (契約の無い組織のユーザーで `require-active-subscription` 配下の POST を叩く)
- [ ] 403 / 500 が `blocked` で記録される (テスト内で web グループの一時 route を定義して発生させる)
- [ ] **成功した変更系の 302 (PRG) が `ok`** (errors が無い 302)
- [ ] production 環境では config が真でも書かない (`app()->detectEnvironment()` /
      `$this->app->detectEnvironment(fn () => 'production')` で切り替える)
- [ ] run / shard が書式違反 (`../etc`, 空文字) のとき書かない (パス組み立ての境界)
- [ ] 名前の無い route への要求は `route_name: null` で記録される (生成器が unresolved に数える)
- [ ] **古い flash error に引きずられない**: 直前のリクエストでバリデーション不合格 (302 + errors)
      → 次のリクエストの成功 302 が `ok` と記録される
      (`StartSession::handleStatefulRequest()` の保存タイミングと `ageFlashData()` に
      依存するため、実挙動で固定する)
- [ ] `recent-auth` 等の route 個別の短絡 middleware が付いた route で、
      その middleware に遮断された要求が記録されない (順序契約の behavioral な裏取り)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認 (`RefreshDatabase` はグローバル適用)

#### `tests/Architecture/BughuntExecutedRouteOrderingTest.php` (新規)

**主契約 (deny-by-default)**: 記録器が付いている**全 route** について、
解決後 (priority 適用後) の middleware 列で
**「短絡しうる」と分類された middleware がすべて記録器より前**にあること。

```php
foreach (Route::getRoutes() as $route) {
    $resolved = NestedRouteDefenseInventory::resolvedMiddleware($route);
    $recorderIndex = array_search(BughuntExecutedRouteMiddleware::class, $resolved, true);
    if ($recorderIndex === false) {
        continue; // web グループ外 (api / Filament) は対象外
    }
    foreach ($resolved as $index => $middleware) {
        if ($index < $recorderIndex) { continue; }
        if (MiddlewareShortCircuitInventory::classification()[$middleware] ?? true) {
            // route 名は null になりうるので URI と method も出す (原因追跡のため)
            $label = $route->getName() ?? '(無名)';
            $violations[] = "{$label} [".implode('|', $route->methods())." /{$route->uri()}]: "
                ."{$middleware} が記録器より後ろで走る";
        }
    }
}
```

- [ ] 上記の主契約 (違反ゼロ)。未分類クラスの既定は **`true` (短絡しうる)** に倒す
      = 分類漏れが偽陰性にならない (既存 `TenantBoundaryOrderingTest` と同じ規律)
- [ ] 代表 route (課金ゲート配下の変更系 route を 1 本) で、記録器が
      `Authenticate` / `EnsureProjectBelongsToRouteOrganization` / `RequireActiveSubscription` /
      `EnsureAccountNotPendingDeletion` より後にあることを名指しで確認する
      (主契約が空回りしても気づけるようにする)
- [ ] 記録器が web グループの route に実際に付いていること (0 件なら fail = 配線消失の検出)
- [ ] **負の対照**: 順序判定式が「常に真」でないこと —
      短絡クラスを 1 つ記録器より後ろに置いた合成の middleware 列で判定関数が偽になることを
      同テスト内で確認する (空振り gate を作らない)
- 列の取得は既存の `Tests\Support\Routing\NestedRouteDefenseInventory::resolvedMiddleware()` を使う
  (同じ正規化を二度書かない)

### リスク

- **middleware を 1 本増やすことによる本番への影響**。`handle()` は
  `config()` 参照 1 回 + `app()->isProduction()` で即 return する
  (production では `enabled()` が第 2 の門で必ず false)。応答は加工しない。
- **priority list の変更で既存の順序契約が壊れる**可能性。記録器は列の最後に入るだけで、
  既存要素同士の相対順序は変えない。`TenantBoundaryOrderingTest` / `ProjectRouteCurrentOrgGuardTest` /
  `AccountDeletionFreezeRouteGateTest` が実測で固定しているので、赤が出れば検出できる。
- **保証しないもの**: web グループ外 (`api/*`・Filament `/admin`・MCP) は記録されない。
  記録の I/O が途中から失敗した場合の欠測 (行数を数えていない) と、
  失敗マーカー自体を書けない障害は検出できない。偽造耐性は主張しない。

---

## 施策 3: bug-hunt 環境への配線

### 変更箇所

- `scripts/bug-hunt-shard.sh`
  - `cmd_provision()` の dryrun 分岐 (L1021-1028) と serve 起動部 (L1126-1177)
  - `cmd_self_test()` にケースを 1 つ追加

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: `scripts/bug-hunt-shard.sh self-test` (スクリプト内蔵の自己テスト)
- 文書: `coverage/README.md` / `SKILL.md` (施策 5)

### 現行コード

```bash
    local -a coverage_env=()
    if [[ -n "${COVERAGE:-}" ]]; then
        coverage_env+=("BUGHUNT_PCOV=1" "BUGHUNT_PCOV_RUN=${run_id}" "BUGHUNT_PCOV_SHARD=${shard}")
        mkdir -p storage/bughunt-coverage
        ...
    fi
    ...
    env -i PATH="${PATH}" HOME="${HOME}" \
        ... ${coverage_env[@]+"${coverage_env[@]}"} ... \
        nohup php artisan serve --env=bughunt.local --port="${port}" --no-reload &
    # ヘルスチェック: curl {url}/login を 30 回まで
```

### 変更後コード

```bash
executed_capture_path() { echo "storage/bughunt-executed/$1-$2.jsonl"; }
executed_capture_error_path() { echo "storage/bughunt-executed/$1-$2.error"; }

# (1) serve 起動より**前**に古い記録を消す。
#     再 provision で前回の行が残っていると、それを今回の同期点と誤認して
#     「待たずに truncate → 今回の /login が遅れて追記される」競合が再発するため。
prepare_executed_capture() {
    local run_id=$1 shard=$2
    mkdir -p storage/bughunt-executed
    rm -f "$(executed_capture_path "${run_id}" "${shard}")" \
          "$(executed_capture_error_path "${run_id}" "${shard}")"
}

# (2) 記録の配線が生きていることを確認する (実経路のみ)。
#
# 疎通確認 (curl {url}/login) は記録器を通る要求なので、**その行が現れることが同期点**になる。
# prepare で空にしてあるので、現れた行は必ず今回のものである。
# (サイズの静止では駄目である — 0 のまま 2 回観測してから遅れて追記される順序が実際に成立し、
#  消したはずの /login が残る。静止は「これから来ない」ことを証明しない。)
#
# 上限内に行が現れない = 記録器が配線されていない / 門が閉じている。**走行前に落とす**
# (黙って何も記録しないまま走ると、走行後に全件未実行という嘘の一覧が出るため)。
assert_executed_capture_wired() {
    local run_id=$1 shard=$2 i
    local path err
    path="$(executed_capture_path "${run_id}" "${shard}")"
    err="$(executed_capture_error_path "${run_id}" "${shard}")"
    for i in $(seq 1 25); do            # 0.2s × 25 = 上限 5s
        [[ -f "${err}" ]] && die 1 "shard-${shard} 実行済み route の記録が失敗している (${err} を参照)"
        [[ -s "${path}" ]] && return 0
        sleep 0.2
    done
    die 1 "shard-${shard} 疎通確認の要求が ${path} に記録されない = 記録器が配線されていない (BUGHUNT_EXECUTED の注入と bootstrap/app.php の登録を確認すること)"
}

# (3) 配線を確認したうえで記録を空にし、探索エージェントへ引き渡す。
#     provision の疎通確認が記録に混ざると login が毎回「実行済み」になるため。
#
# ⚠ dryrun では prepare と finalize だけを呼ぶ (serve が居ないので待てない。
#    storage への副作用はこの初期化だけで、配線を自己テストから検査するために残す)。
finalize_executed_capture() {
    local run_id=$1 shard=$2 path
    path="$(executed_capture_path "${run_id}" "${shard}")"
    mkdir -p storage/bughunt-executed
    : > "${path}"
    rm -f "$(executed_capture_error_path "${run_id}" "${shard}")"
    manifest_update "${run_id}" "${shard}" "executed_capture=\"${path}\""
}
```

- **serve env に常時注入する** (`--coverage` の有無に依らない。操作到達カバレッジは毎回採る):

```bash
    # 実行済み route の記録 (操作到達カバレッジの主入力)。既定 ON = 毎回採る。
    # BughuntCoverageMiddleware の pcov 系と違い拡張に依存しないため、条件分岐を持たない。
    local -a executed_env=(
        "BUGHUNT_EXECUTED=1"
        "BUGHUNT_EXECUTED_RUN=${run_id}"
        "BUGHUNT_EXECUTED_SHARD=${shard}"
    )
    mkdir -p storage/bughunt-executed
    ...
    env -i PATH="${PATH}" HOME="${HOME}" \
        ... ${executed_env[@]+"${executed_env[@]}"} \
        ${coverage_env[@]+"${coverage_env[@]}"} ... \
        nohup php artisan serve --env=bughunt.local --port="${port}" --no-reload &
```

- 呼び出し位置 (順序が契約):
  1. dryrun 分岐の `generate_wrapper` の直前 —
     `prepare_executed_capture` → `finalize_executed_capture` (serve が居ないので待たない)
  2. 実経路: **serve 起動より前**に `prepare_executed_capture` →
     ヘルスチェック成功後に `assert_executed_capture_wired` →
     `finalize_executed_capture` (`start_shard_workers` の前)

> `php artisan serve --no-reload` は env をそのまま子へ渡す。
> 既存の `BUGHUNT_PCOV*` が同じ経路で serve に届いていることが先例。

### PHPStan適合チェック

- 本施策は bash のみ。該当なし (`set -euo pipefail` は既存)。

### テスト計画

`scripts/bug-hunt-shard.sh self-test` に 1 ケース追加 (既存の `t_ok` / `t_fail` の作法):

- [ ] dryrun の `provision --shard 0 --run-id …` 後に
      `storage/bughunt-executed/{run}-0.jsonl` が**存在しかつ空**であること
- [ ] 同 run×shard の `.error` マーカーが残っていたら消えること (再 provision で持ち越さない)
- [ ] manifest に `executed_capture` が記録されること
- [ ] スクリプト本文に `BUGHUNT_EXECUTED=1` の注入行が存在すること
      (serve を起動しない自己テストでは env 行そのものを実行できないため、
      **本文走査で配線の消失を検出する**。これは弱い検査であることを明記する)
- [ ] `assert_executed_capture_wired` が**行の出現を待つ**こと:
      背景プロセスから 0.5 秒後に 1 行追記し、関数が 0 で戻ることを確認する
      (待ち時間の値は検査しない。「行を見てから戻る」ことが契約)
- [ ] **負の対照**: 行が 1 件も現れないときに `assert_executed_capture_wired` が非 0 で落ちること
      (走行前に配線不成立を検出する)
- [ ] **負の対照**: `.error` マーカーがあるときに非 0 で落ちること
- [ ] **古い行を同期成功と誤認しない**: 事前に古い行があるファイルを置いても、
      `prepare_executed_capture` がそれを消すので、背景からの遅延追記を実際に待つこと
- [ ] `finalize_executed_capture` が対象ファイルを空にし `.error` を消し manifest を更新すること

### リスク

- **provision が落ちる条件が 1 つ増える** (疎通確認の行が現れない = 記録器の配線不成立)。
  これは意図した fail-closed である (黙って何も記録しないまま走らせない)。
  誤検出の余地は「疎通確認の要求が web グループを通らない」場合だけで、`/login` は web route なので起きない。
- 疎通確認の後に空にするため、**provision の後に人が curl した要求は記録に混ざる**。
  `keepdb-check` は別サブコマンドなので、`--keep-db` reuse 経路では
  `keepdb-check` の疎通確認が記録に残りうる (既知。運用で reuse 時は再 provision するのが正)。
- 記録は常時 ON になるので、bug-hunt 環境の storage に JSONL が増える。
  1 要求 1 行 (150 バイト前後) で run ごとに数百 KB 程度。teardown では消さない
  (走行後に生成器が読むため)。

---

## 施策 4: executed.json の生成器

### 変更箇所

- 新規 `.claude/skills/app-bug-hunt/coverage/build_executed.py`
- 新規 `.claude/skills/app-bug-hunt/coverage/test_build_executed.py`

### 波及変更

- 文書: `coverage/README.md` / `SKILL.md` (施策 5)
- 既存 fixture: `coverage/fixtures/executed.sample.json` は照合器の入力見本として使い続ける
  (新しい `http_statuses` / `unresolved` キーを足した形に更新する)

### 変更後コード (骨子)

```python
#!/usr/bin/env python3
"""実行済み route の記録 (JSONL) を束ねて executed.json を作る。

入力は BughuntExecutedRouteMiddleware が走行中に書いた
storage/bughunt-executed/{run}-{shard}.jsonl (1 行 1 要求)。
出力は照合器 correlate.py の主入力 executed.json。

**主入力が揃わない走行は成功にしない** (終了コード 3)。詳細は README の終了コード規約。

使い方:
    python3 build_executed.py --run-id 20260618-082101 --shard 0 \
      [--input-dir storage/bughunt-executed] \
      --out devnotes/20260618-082101-bug-hunt/executed.json
"""
EXIT_OK = 0
EXIT_INPUT_ERROR = 1
EXIT_INPUT_UNAVAILABLE = 3
```

- CLI: `--run-id` (必須) / `--shard` (必須・複数指定可) / `--input-dir` (既定
  `storage/bughunt-executed`) / `--out` (必須)
- shard ごとの処理:
  1. `{input-dir}/{run}-{shard}.error` があれば → 3 (`capture_failed`)
  2. `{input-dir}/{run}-{shard}.jsonl` が無ければ → 3 (`capture_file_missing`)
  3. 各行を `json.loads`。壊れていれば → 3 (`capture_line_broken`)
  4. **行の形を全項目検査する** (壊れた入力を集計しない)。1 つでも外れたら → 3
     (`capture_row_invalid`。ただし `run_id` 不一致だけは理由を分けて `run_id_mismatch`):
     - `run_id` が `--run-id` と一致する (静かに捨てない)
     - `shard` が**処理中の shard と一致する** (ファイル名と中身の食い違いを検出)
     - `status` が `{"ok", "blocked"}` のいずれか
     - `http_status` が `int` (bool は除外する。Python では `bool` が `int` の派生)
     - `route_name` が `None` または非空 `str`
     - `method` が非空 `str`
  5. **名前付き route の行が 0 件**なら → 3 (`capture_empty`)。
     「有効行 0」ではなく「名前付きが 0」で判定するのは、照合器の `executed_no_rows`
     (名前付き行だけを数える) と定義を揃えるためである
- 集計:
  - `route_name` が null の行は `unresolved[shard] += 1`
  - それ以外は `(route_name, shard, status)` をキーに畳み、`http_statuses` を集合で持つ
- 書き出し: **一時ファイルへ書いて `os.replace()` で atomic rename する**。
  一時ファイルは **`--out` と同じディレクトリ**に作る (別ファイルシステムだと
  `os.replace()` が失敗し atomic rename の前提が崩れる)。
  途中失敗時は一時ファイルを消し、既存の `--out` は上書きしない (壊れた成果物を残さない)
- 出力 (照合器が読める形。未知キーは照合器が無視する):

```json
{
  "run_id": "20260618-082101",
  "shards": ["0"],
  "executed_routes": [
    {"route_name": "projects.store", "shard": "0", "status": "ok", "http_statuses": [302]},
    {"route_name": "projects.update", "shard": "0", "status": "blocked", "http_statuses": [302, 403]}
  ],
  "unresolved": {"0": 3}
}
```

- 正常時は stderr へ件数サマリ (shard ごとの行数 / route 数 / unresolved 数) を出し、0 を返す。

### PHPStan適合チェック

- 本施策は Python のみ。該当なし。

### テスト計画

`coverage/test_build_executed.py` (`python3 -m unittest`、stdlib のみ、tempfile で入出力を作る):

- [ ] **正の対照**: 2 shard 分の JSONL から executed.json が組まれ 0 を返す
      (route × shard × status 単位で畳まれ、`http_statuses` が昇順の重複なし配列になる)
- [ ] **負の対照 1**: JSONL が無い → 3 (`capture_file_missing`)
- [ ] **負の対照 2**: 失敗マーカー `.error` がある → 3 (`capture_failed`)
- [ ] **負の対照 3**: 壊れた行がある → 3 (`capture_line_broken`)
- [ ] **負の対照 4**: 別 run の行が混ざる → 3 (`run_id_mismatch`)
- [ ] **負の対照 5**: ある shard の**名前付き route の行**が 0 件 → 3 (`capture_empty`)
      (`route_name: null` の行しか無い shard もここで落ちる)
- [ ] **負の対照 6**: 行の形が契約外 → 3 (`capture_row_invalid`)。
      `status` が未知値 / `shard` がファイル名と違う / `http_status` が int でない /
      `route_name` が空文字 / `method` が欠落 の 5 通りをそれぞれ 1 本ずつ
- [ ] **負の対照 7**: 3 で落ちるとき既存の `--out` を上書きしない
      (先に別内容の executed.json を置いてから失敗させ、内容が変わらないことを確認)
- [ ] `route_name: null` の行が `executed_routes` に載らず `unresolved` に数えられる
- [ ] **結合**: 生成した executed.json を `correlate.validate_executed()` が成立と判定する
      (生成器と照合器の契約が食い違っていないことを 1 本で固定する)
- [ ] 3 で落ちるときは `--out` のファイルを**作らない** (壊れた成果物を残さない)

### リスク

- shard を明示指定させるため、指定を忘れた shard の欠測は検出できない。
  SKILL.md の手順で「provision した shard をすべて渡す」と書き、
  親が manifest から shard 番号を読む形にする (手順側の担保)。

---

## 施策 5: 手順・契約の文書更新

### 変更箇所

- `.claude/skills/app-bug-hunt/coverage/README.md` (L62-131 の operation-reach 節)
- `.claude/skills/app-bug-hunt/SKILL.md` (L419-427 の Phase 4 後の節)
- `.claude/agents/bughunt-shard.md`
- `docs/template-divergence.md`

### 内容

1. **README**: 入力 4 (`executed.json`) の説明を書き換える。
   - 「`--executed` 省略時は全 in_scope 機構を未実行 candidate 扱い」を**削除**する
     (旧 fail-open の記述を残さない)
   - `build_executed.py` の使い方と、記録器 → JSONL → executed.json → 照合器 の流れを追記
   - **終了コード規約の表** (0 / 1 / 3 と各理由コード) を追記
   - `status` は **`ok|blocked` の 2 値**であり生成器が写像する、と**肯定形で**書く
     (旧語彙そのものを README に書かない。書くと施策 5 の文言 gate が自分の文書で赤くなる)
2. **SKILL.md Phase 4**: 突合の手順を 2 コマンドにする。

   ```bash
   python3 .claude/skills/app-bug-hunt/coverage/build_executed.py \
     --run-id {ts} --shard 0 --out devnotes/{ts}-bug-hunt/executed.json
   python3 .claude/skills/app-bug-hunt/coverage/correlate.py \
     --operations … --findings … --executed devnotes/{ts}-bug-hunt/executed.json \
     --graph-db … --run-id {ts} > devnotes/{ts}-bug-hunt/coverage-operation-reach.md
   ```

   並列走行時は provision した shard をすべて `--shard` に渡すこと、
   どちらかが終了コード 3 で落ちたら**レポートを「カバレッジ突合できず」として明記する**
   (未実行一覧を載せない) ことを書く。
3. **`.claude/agents/bughunt-shard.md`**: 「**走行ログは書かない。実行済み route の記録は
   アプリ側が自動で採る**」を明記する (子が手で記録を書く運用を復活させない)。
4. **`docs/template-divergence.md`**: テンプレート (laravel-claude-template) が
   「ブラウザの通信履歴を退避 → 正規化器 → artisan コマンドで route 名解決」の 3 段で採るのに対し、
   本リポジトリは**アプリ側の観測器**で採る、という意図的な逸脱を記録する。
   理由 (退避の起動を LLM に依存させない / route 名の再解決が要らない / 遮断 middleware の
   内側に置けるので偽陽性が構造的に消える) と、保証し続ける不変条件
   (主入力が揃わない走行は終了コード 3 で落とす) を書く。

### テスト計画

- [ ] `coverage/test_naming_no_stale.py` に **旧 fail-open の文言**と**旧語彙**のパターンを追加する。
      既存の「旧 Stage 付番の再混入検知」と同じ仕組みにパターンを足すだけで、新しい機構を作らない。
      パターンを 2 群に分ける:
      - `STALE_PATTERNS` (既存の旧 Stage 付番): 対象は従来どおり skill 配下の `.md` / `.py` 全部
      - `IMPLEMENTATION_ONLY_PATTERNS` (新規): `--executed` 省略時 / 未実行 candidate /
        `skipped_blocked_count` / **status 語彙としての `skipped`**
        (`ok|blocked|skipped` の並び、`'skipped'`、`"skipped"`)。
        対象から **`test_*.py` を除外**する。理由は「旧値を拒否する負の対照テストは
        入力 fixture としてその文字列を必要とする」ため。`.md` は全部対象に残す
      - 裸の `skipped` は禁止語にしない (`unittest` の `skipTest` や無関係な英文を巻き込むため)
- [ ] 実装 (`correlate.py` / `build_executed.py`) と `README.md` と
      `fixtures/executed.sample.json` から旧語彙を**完全に削除**する
      (残っていると gate 自身が赤になる = 削除漏れが機械的に分かる)
- [ ] gate 自身のテスト: 合成の実装ファイルでは検出し、`test_*.py` では検出しないこと。
      自ファイル除外 (`EXCLUDE_NAMES`) が効いていることも確認する (依存を暗黙にしない)
- [ ] `scripts/bug-hunt-inventory-check.sh` への影響は無い (分母の話ではない) ことを確認する

### リスク

- 文書だけが古いまま残ると、次の走行者が `--executed` を省いて呼ぶ。
  上記の文言 gate で機械的に防ぐ。

---

## 施策 6: Python 自己テストの実行レーン結線

### 変更箇所

- 新規 `tests/Architecture/BughuntCoverageToolSelfTest.php`

### 内容

`.claude/skills/app-bug-hunt/coverage/` を作業ディレクトリにして
`python3 -m unittest test_correlate test_build_executed test_naming_no_stale` を実走し、
終了コード 0 を確認する。

**3 モジュールすべてを対象にする**理由: 施策 1 / 4 の fail-closed だけでなく、
施策 5 で足す「旧 fail-open 文言・旧語彙の再混入検知」も `composer test` の下で走らないと、
禁止語が戻っても緑のままになり「不変条件はテストへの登録まで含めて実装済み」を満たさない。
`test_merge_pcov` はコード到達カバレッジ (別 feature) の担当なので本 TODO では加えない。

先例は `tests/Architecture/BugHuntInventoryCheckInvariantTest.php`:
`Symfony\Component\Process\Process` でスクリプトを実走し、**python3 の不在は skip ではなく fail** で
顕在化させる (環境不備を skip で隠すと「未検証のまま合格」になる)。同じ作法に揃える。

- AGENTS.md の検証コマンド台帳 (`VERIFICATION_COMMANDS` マーカー) と CI 定義は**触らない**
  (blast radius が別。`composer test` から実走すれば本 TODO の目的は果たせる)。

### テスト計画

- [ ] python3 が PATH に無ければ fail する (skip しない)
- [ ] 3 つの Python モジュール (`test_correlate` / `test_build_executed` / `test_naming_no_stale`)
      が実走し終了コード 0 になる。
      失敗時は `Process::getOutput().getErrorOutput()` を Pest の失敗メッセージに載せる
      (既存 `bhicRunSandbox()` と同じ形。原因追跡できない赤を出さない)
- [ ] **負の対照**: 存在しないモジュール名を渡すと非 0 になることを同テスト内で確認する
      (常に緑になる空振り gate を作らない)

### リスク

- `composer test` が python3 に依存する。既に `BugHuntInventoryCheckInvariantTest` が
  同じ依存を前提として固定しているので、新しい依存ではない。
- テスト実行時間が数秒増える。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | `bootstrap/app.php` の priority 鎖と `tests/Architecture/TenantBoundaryOrderingTest.php` の分類 inventory という**衝突しやすい共有ファイル**を触る。加えて施策 1 が破壊的変更 (照合器の終了コード規約) なので、施策 2〜4 が同じブランチに揃わないと bug-hunt の Phase 4 が回らない。部分マージを禁じるため単独 worktree で完結させる。 |
| 競合リスク | 他 TODO が `bootstrap/app.php` / `config/bughunt.php` / middleware 分類 inventory を触ると衝突する。マージ前に main を取り込み `composer test` を通し直すこと。`.claude/skills/app-bug-hunt/` 配下は bug-hunt 走行そのものと競合しうるため、実装中は bug-hunt を走らせない。 |

## 保証しないもの (誇張しない)

- **web グループ外は観測しない**: `api/*`・Filament `/admin`・MCP は別の middleware スタックを通るため
  記録されず、分母に載っていれば未実行側へ倒れる (過小申告の方向)。
- **部分欠測は検出しない**: 行数を数えていないので「途中から書けなくなった」は分からない。
  検出できるのは「1 行も無い」「別 run が混ざった」「失敗マーカーを残せた」まで。
- **失敗マーカーを書けない障害は検出しない** (ディレクトリごと書けない等)。
- **偽造耐性は無い**: 記録ファイルは worktree 内にあり、書き換えを検出する仕組みは持たない。
- **404 / 405 / 静的ファイルは観測対象外**: route 照合の段階で例外になるか、
  PHP 組み込みサーバが直接配信するため、記録器に到達しない。
  `unresolved` に数えるのは「記録器まで到達したが名前の無い route」だけである。
- **実走行での実測は本 TODO に含めない**: 検証は自動テストと dryrun の自己テストで閉じる。
  並列 4 shard の実走行は次回の bug-hunt が初回になる。


## 実装差分 (git diff)

```diff
diff --git a/.claude/skills/app-bug-hunt/coverage/README.md b/.claude/skills/app-bug-hunt/coverage/README.md
index b8b5621..fceb802 100644
--- a/.claude/skills/app-bug-hunt/coverage/README.md
+++ b/.claude/skills/app-bug-hunt/coverage/README.md
@@ -45,16 +45,19 @@ ## 構成
 
 | ファイル | 役割 |
 |---|---|
+| `build_executed.py` | **実行済み route の記録の集約器**。記録器が書いた shard ごとの JSONL を束ねて `executed.json` を作る（stdlib のみ） |
 | `correlate.py` | **操作到達カバレッジ correlator**。run_id で executed / findings / operations / graph を突合し未カバー worklist を作る（stdlib のみ） |
 | `merge_pcov.py` | **コード到達カバレッジ pcov merge**。C3 middleware が吐く shard JSONL を union し uncovered を主出力する（stdlib のみ） |
+| `test_build_executed.py` | build_executed のテスト（`python3 -m unittest`、入出力は tempfile） |
 | `test_correlate.py` | correlate のテスト（`python3 -m unittest`、graph は fixture sqlite で生成。実 operations.md / 実 graph.db があれば fix-gate #3/#4 を追加検証） |
 | `test_merge_pcov.py` | merge のテスト（全 fixture、pcov 不要） |
-| `test_naming_no_stale.py` | 旧 Stage 付番の後退防止 self-test |
+| `test_naming_no_stale.py` | 旧 Stage 付番と旧 fail-open 文言の後退防止 self-test |
 | `fixtures/` | サンプル入力（route-list / operations(5列+6列) / findings / executed）と `fixtures/pcov/` の shard JSONL |
 
 関連（このディレクトリ外）:
 - `../ledger/findings.schema.json` … Finding 台帳のスキーマ。findings.jsonl の正本。
 - `../operations.md` … 機構分母（name 列 / 区分）。
+- 記録器 = `app/Http/Middleware/BughuntExecutedRouteMiddleware.php`（実行済み route の記録。毎回 ON）。
 - C3 middleware = `app/Http/Middleware/BughuntCoverageMiddleware.php`、C5 = `scripts/bug-hunt-shard.sh --coverage`（pcov 導入時）。
 
 ---
@@ -69,24 +72,62 @@ ### 入力
 2. `operations.md` — 機構分母（5 列、name 列が join キー）。区分 `外`(対象外)=分母外、`逸`(逸脱のみ)=未実行でも警告しない材料。
 3. `findings.jsonl` — Finding Ledger（複数 shard を `cat` 連結 or glob 可、`-` で stdin）。`--run-id` で絞る。
    route 直結しない finding は `capability_tag` 経由で story 一致の機構群へブロードキャスト（`via_capability`）。
-4. `executed.json` — bug-hunt 子が「UI 経由で実際に叩いた route」を run_id・shard 単位で記録したもの。
-   複数 shard を union（どれか 1 shard で executed なら executed=true）。スキーマ:
+4. `executed.json` — **実行済み route の記録**（主入力）。走行中にアプリ側の記録器
+   （`BughuntExecutedRouteMiddleware`）が書いた shard ごとの JSONL を `build_executed.py` が束ねたもの。
+   複数 shard を union（どれか 1 shard で `ok` なら executed=true）。スキーマ:
    ```json
    {
      "run_id": "20260618-082101",
      "shards": ["0","1","2","3","4"],
      "executed_routes": [
-       {"route_name": "register.store", "shard": "1", "story": "S1", "status": "ok"}
-     ]
+       {"route_name": "register.store", "shard": "1", "status": "ok", "http_statuses": [302]}
+     ],
+     "unresolved": {"1": 3}
    }
    ```
-   `status` は `ok|blocked|skipped`。`ok` のみ executed 扱い。`--executed` 省略時は全 in_scope 機構を未実行 candidate 扱い。
+   `status` は **`ok` と `blocked` の 2 値**で、生成器が HTTP 応答から写像する
+   （2xx と errors の無い 3xx が `ok`、それ以外は `blocked`）。executed 扱いになるのは `ok` だけ。
+   `unresolved` は「記録器まで到達したが名前の無い route」の件数（shard 別）。
 5. `graph.db` — TESTED_BY を controller ファイル単位で引く（`/workspace/.code-review-graph/graph.db`）。
 
+### 記録 → 集約 → 突合 の流れ
+
+```
+serve (bughunt 環境)
+  └ BughuntExecutedRouteMiddleware        … 1 要求 1 行を追記
+       storage/bughunt-executed/{run}-{shard}.jsonl
+          └ build_executed.py             … shard を束ねて検証し executed.json を作る
+               devnotes/{run}-bug-hunt/executed.json
+                  └ correlate.py          … 機構分母と突合し未カバー worklist を出す
+```
+
+記録は `scripts/bug-hunt-shard.sh provision` が **毎回 ON** で仕込む（`--coverage` の有無に依らない）。
+provision は疎通確認の要求が記録された**ことを確認してから**その行を消して探索へ引き渡すため、
+記録器が配線されていなければ走行前に落ちる。
+
+### 終了コード規約（両ツール共通）
+
+| コード | 意味 | 理由コード（stderr に出る） |
+|---|---|---|
+| 0 | 成立 | — |
+| 1 | 読み込み・parse・I/O の失敗 | （例外メッセージ） |
+| 3 | **主入力の可用性違反**（検査を成立させられない） | `build_executed.py`: `capture_failed` / `capture_file_missing` / `capture_line_broken` / `capture_row_invalid` / `run_id_mismatch` / `capture_empty`<br>`correlate.py`: `executed_missing` / `executed_schema_invalid` / `executed_run_id_mismatch` / `executed_shards_missing` / `executed_no_rows` / `executed_shard_mismatch` |
+
+3 のときは worklist / executed.json を**書き出さない**。揃わない走行を「全件未実行」という
+嘘の一覧として返さないためである。`ok` が 1 件も無い（全操作が跳ねた）走行は 3 ではない
+—— 主入力としては成立しており、正しい結果は「全機構が未実行 worklist に残る」ことである。
+
 ### 使い方
 
 ```bash
 cd /workspace/.claude/worktrees/<worktree>   # CWD を明示
+
+# (1) 記録を束ねる (provision した shard をすべて --shard に渡す)
+python3 .claude/skills/app-bug-hunt/coverage/build_executed.py \
+  --run-id 20260618-082101 --shard 1 --shard 2 --shard 3 --shard 4 \
+  --out devnotes/20260618-082101-bug-hunt/executed.json
+
+# (2) 突合する
 python3 .claude/skills/app-bug-hunt/coverage/correlate.py \
   --operations .claude/skills/app-bug-hunt/operations.md \
   --findings 'devnotes/20260618-082101-bug-hunt/shard-*/findings.jsonl' \
@@ -101,6 +142,7 @@ # 機械集計 (trend 用):
 
 複数 shard の findings は `cat ... | correlate.py --findings -` でも渡せる。
 `--hotspot-threshold N`（既定 2）で hotspot の閾値を変えられる。
+`--input-dir`（既定 `storage/bughunt-executed`）で記録の置き場を変えられる。
 
 ### 出力の読み方（主＝未カバー worklist、% は副）
 
@@ -124,6 +166,8 @@ ### 出力の読み方（主＝未カバー worklist、% は副）
 | `unknown_graph_gap_count` | PHP 等で TESTED_BY 判定不能（= Pest を別途見よ） | 注記 |
 | `in_scope_count` | 分母（gaming 防止のため明示） | 注記 |
 | `dropped_other_run` | run_id 不一致で捨てた行数（trend 汚染検知） | 注記 |
+| `executed_ok_count` | in_scope かつ status `ok` の機構数（内訳） | 注記 |
+| `blocked_count` | status が `blocked` だけの機構数（内訳） | 注記 |
 | `executed_pct` | 実行率 | **副・目標にしない** |
 
 > KPI の使い方は **worklist の逓減**（run を重ねて `unexecuted_count` / `cross_count` が減る）を見る。
@@ -184,9 +228,12 @@ ## テスト
 
 ```bash
 cd /workspace/.claude/worktrees/<worktree>/.claude/skills/app-bug-hunt/coverage
-python3 -m unittest test_correlate test_merge_pcov test_naming_no_stale
+python3 -m unittest test_correlate test_build_executed test_merge_pcov test_naming_no_stale
 ```
 
+`test_correlate` / `test_build_executed` / `test_naming_no_stale` の 3 本は
+`composer test`（`tests/Architecture/BughuntCoverageToolSelfTest.php`）からも実走する。
+
 いずれも **stdlib のみ・pcov 非依存**（graph は fixture sqlite を生成、pcov 入力は fixture JSONL）。
 実 `operations.md` / 実 `graph.db`（`/workspace/.code-review-graph/graph.db`）がある環境では、
 fix-gate #3（name 列 join）/ #4（PHP TESTED_BY=0 → unknown_graph_gap）の追加テストも自動で走る。
diff --git a/.claude/skills/app-bug-hunt/coverage/build_executed.py b/.claude/skills/app-bug-hunt/coverage/build_executed.py
new file mode 100644
index 0000000..22820e9
--- /dev/null
+++ b/.claude/skills/app-bug-hunt/coverage/build_executed.py
@@ -0,0 +1,210 @@
+#!/usr/bin/env python3
+"""実行済み route の記録 (JSONL) を束ねて executed.json を作る。
+
+入力は BughuntExecutedRouteMiddleware が走行中に書いた
+storage/bughunt-executed/{run}-{shard}.jsonl (1 行 1 要求)。
+出力は照合器 correlate.py の主入力 executed.json。
+
+**主入力が揃わない走行は成功にしない** (終了コード 3)。詳細は README の終了コード規約。
+
+使い方:
+    python3 build_executed.py --run-id 20260618-082101 --shard 0 \
+      [--input-dir storage/bughunt-executed] \
+      --out devnotes/20260618-082101-bug-hunt/executed.json
+
+依存は標準ライブラリのみ。
+"""
+from __future__ import annotations
+
+import argparse
+import json
+import os
+import sys
+import tempfile
+from pathlib import Path
+
+# 終了コード規約 (scripts/bug-hunt-inventory-check.sh / correlate.py と同じ 3 = 契約違反)。
+EXIT_OK = 0
+EXIT_INPUT_ERROR = 1        # 引数・I/O の失敗
+EXIT_INPUT_UNAVAILABLE = 3  # 主入力の可用性違反 = 検査を成立させられない
+
+# 記録器が書く status の語彙。2 値だけを受け付ける。
+VALID_STATUSES = {"ok", "blocked"}
+
+
+class CaptureError(Exception):
+    """主入力の可用性違反。reason は README の理由コード。"""
+
+    def __init__(self, reason: str, detail: str) -> None:
+        super().__init__(f"{reason}: {detail}")
+        self.reason = reason
+        self.detail = detail
+
+
+def capture_path(input_dir: str, run_id: str, shard: str) -> Path:
+    return Path(input_dir) / f"{run_id}-{shard}.jsonl"
+
+
+def failure_path(input_dir: str, run_id: str, shard: str) -> Path:
+    return Path(input_dir) / f"{run_id}-{shard}.error"
+
+
+def _check_row(row: object, run_id: str, shard: str, where: str) -> dict:
+    """1 行の形を全項目検査する (壊れた入力を集計しない)。"""
+    if not isinstance(row, dict):
+        raise CaptureError("capture_row_invalid", f"{where}: 行が JSON object でない")
+    if row.get("run_id") != run_id:
+        raise CaptureError(
+            "run_id_mismatch",
+            f"{where}: run_id が --run-id と違う ({row.get('run_id')!r} != {run_id!r})",
+        )
+    if row.get("shard") != shard:
+        raise CaptureError(
+            "capture_row_invalid",
+            f"{where}: shard がファイル名と違う ({row.get('shard')!r} != {shard!r})",
+        )
+    status = row.get("status")
+    if status not in VALID_STATUSES:
+        raise CaptureError("capture_row_invalid", f"{where}: status が {sorted(VALID_STATUSES)} 以外 ({status!r})")
+    http_status = row.get("http_status")
+    # bool は int の派生なので明示的に除外する。
+    if isinstance(http_status, bool) or not isinstance(http_status, int):
+        raise CaptureError("capture_row_invalid", f"{where}: http_status が整数でない ({http_status!r})")
+    method = row.get("method")
+    if not isinstance(method, str) or method == "":
+        raise CaptureError("capture_row_invalid", f"{where}: method が非空文字列でない ({method!r})")
+    name = row.get("route_name")
+    if name is not None and (not isinstance(name, str) or name == ""):
+        raise CaptureError("capture_row_invalid", f"{where}: route_name が null でも非空文字列でもない ({name!r})")
+    return row
+
+
+def load_shard(input_dir: str, run_id: str, shard: str) -> tuple[list[dict], int]:
+    """1 shard 分の JSONL を読み、(検査済みの行, 全有効行数) を返す。
+
+    可用性違反はすべて CaptureError で送出する (静かに捨てない)。
+    """
+    if failure_path(input_dir, run_id, shard).exists():
+        raise CaptureError(
+            "capture_failed",
+            f"shard {shard}: 失敗マーカー {failure_path(input_dir, run_id, shard)} がある",
+        )
+    path = capture_path(input_dir, run_id, shard)
+    if not path.is_file():
+        raise CaptureError("capture_file_missing", f"shard {shard}: {path} が無い")
+
+    rows: list[dict] = []
+    for lineno, raw in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
+        if raw.strip() == "":
+            continue
+        where = f"{path}:L{lineno}"
+        try:
+            parsed = json.loads(raw)
+        except json.JSONDecodeError as e:
+            raise CaptureError("capture_line_broken", f"{where}: {e}") from e
+        rows.append(_check_row(parsed, run_id, shard, where))
+
+    named = [r for r in rows if r.get("route_name") is not None]
+    if not named:
+        raise CaptureError(
+            "capture_empty",
+            f"shard {shard}: 名前付き route の行が 1 件も無い (記録が採れていない)",
+        )
+    return rows, len(rows)
+
+
+def build(input_dir: str, run_id: str, shards: list[str]) -> tuple[dict, list[str]]:
+    """executed.json の中身と、stderr へ出す件数サマリを組み立てる。"""
+    # (route_name, shard, status) -> http_status の集合
+    folded: dict[tuple[str, str, str], set[int]] = {}
+    unresolved: dict[str, int] = {}
+    summary: list[str] = []
+
+    for shard in shards:
+        rows, total = load_shard(input_dir, run_id, shard)
+        names: set[str] = set()
+        for row in rows:
+            name = row.get("route_name")
+            if name is None:
+                unresolved[shard] = unresolved.get(shard, 0) + 1
+                continue
+            names.add(name)
+            key = (name, shard, row["status"])
+            folded.setdefault(key, set()).add(row["http_status"])
+        summary.append(
+            f"shard {shard}: 行 {total} / route {len(names)} / 名前なし {unresolved.get(shard, 0)}"
+        )
+
+    executed_routes = [
+        {
+            "route_name": name,
+            "shard": shard,
+            "status": status,
+            "http_statuses": sorted(statuses),
+        }
+        for (name, shard, status), statuses in sorted(folded.items())
+    ]
+
+    return {
+        "run_id": run_id,
+        "shards": list(shards),
+        "executed_routes": executed_routes,
+        "unresolved": unresolved,
+    }, summary
+
+
+def write_atomic(out: str, data: dict) -> None:
+    """一時ファイルへ書いて os.replace() で差し替える (壊れた成果物を残さない)。
+
+    一時ファイルは **--out と同じディレクトリ**に作る (別ファイルシステムだと
+    os.replace() が失敗し atomic rename の前提が崩れる)。
+    """
+    out_path = Path(out)
+    out_path.parent.mkdir(parents=True, exist_ok=True)
+    fd, tmp = tempfile.mkstemp(dir=str(out_path.parent), prefix=".executed-", suffix=".json")
+    try:
+        with os.fdopen(fd, "w", encoding="utf-8") as f:
+            json.dump(data, f, ensure_ascii=False, indent=2)
+            f.write("\n")
+        os.replace(tmp, out_path)
+    except BaseException:
+        Path(tmp).unlink(missing_ok=True)
+        raise
+
+
+def main(argv=None) -> int:
+    ap = argparse.ArgumentParser(
+        description="bug-hunt 実行済み route の記録 (JSONL) から executed.json を作る")
+    ap.add_argument("--run-id", required=True, help="run_id (記録行の run_id と一致すること)")
+    ap.add_argument("--shard", required=True, action="append", dest="shards",
+                    help="shard 番号 (provision した shard をすべて渡す。複数指定可)")
+    ap.add_argument("--input-dir", default="storage/bughunt-executed",
+                    help="記録 JSONL の置き場 (既定 storage/bughunt-executed)")
+    ap.add_argument("--out", required=True, help="出力する executed.json のパス")
+    args = ap.parse_args(argv)
+
+    try:
+        data, summary = build(args.input_dir, args.run_id, args.shards)
+    except CaptureError as e:
+        print(f"ERROR: 主入力が揃わない (reason={e.reason}) {e.detail}。"
+              " executed.json は書き出さない (揃わない走行を成功として返さないため)。",
+              file=sys.stderr)
+        return EXIT_INPUT_UNAVAILABLE
+    except OSError as e:
+        print(f"ERROR: {e}", file=sys.stderr)
+        return EXIT_INPUT_ERROR
+
+    try:
+        write_atomic(args.out, data)
+    except OSError as e:
+        print(f"ERROR: {e}", file=sys.stderr)
+        return EXIT_INPUT_ERROR
+
+    for line in summary:
+        print(line, file=sys.stderr)
+    print(f"executed.json を書き出した: {args.out}", file=sys.stderr)
+    return EXIT_OK
+
+
+if __name__ == "__main__":
+    raise SystemExit(main())
diff --git a/.claude/skills/app-bug-hunt/coverage/correlate.py b/.claude/skills/app-bug-hunt/coverage/correlate.py
index aaeadc2..45d0045 100644
--- a/.claude/skills/app-bug-hunt/coverage/correlate.py
+++ b/.claude/skills/app-bug-hunt/coverage/correlate.py
@@ -1,9 +1,14 @@
 #!/usr/bin/env python3
 """操作到達カバレッジ correlator — bug-hunt の「叩いた操作 (route) の網羅」proxy。
 
-run_id を軸に route インベントリ / operations.md(機構分母) / executed.json(走行ログ) /
+run_id を軸に route インベントリ / operations.md(機構分母) /
+executed.json(実行済み route の記録。build_executed.py が作る) /
 findings.jsonl / graph.db(TESTED_BY) を join し、**未カバー worklist** を出す。
 
+**主入力が揃わない走行は成功にしない** (終了コード 3)。executed.json が無い / 別 run /
+形が契約外 / 観測行 0 のときは worklist を出さずに落ちる (揃わない走行を
+「全件未実行」という嘘の一覧として返さないため)。
+
 主出力 = worklist (未実行機構 / TESTED_BY untested(TS面のみ) / finding hotspot /
 ★cross: 未実行∧finding多)。絶対 % は副 (`*_pct` フィールドに添えるのみ・目標にしない)。
 
@@ -37,7 +42,7 @@ operations.md のフォーマット (fix-gate #3):
       --run-id 20260618-082101 [--json] [--hotspot-threshold 2]
 
   --route-list を省くと `php artisan route:list --json` を subprocess 取得する。
-  --executed を省くと「全 in_scope 機構を未実行 candidate」として表示する。
+  --executed は必須 (build_executed.py が作った executed.json を渡す)。
 """
 from __future__ import annotations
 
@@ -52,6 +57,14 @@ from collections import defaultdict
 from dataclasses import dataclass, field
 from pathlib import Path
 
+# 終了コード規約 (scripts/bug-hunt-inventory-check.sh と同じ 3 = 契約違反)
+EXIT_OK = 0
+EXIT_INPUT_ERROR = 1        # 読み込み・parse の失敗 (従来どおり)
+EXIT_INPUT_UNAVAILABLE = 3  # 主入力の可用性違反 = 検査を成立させられない
+
+# 記録器が書く status の語彙。ok|blocked の 2 値だけを受け付ける。
+VALID_STATUSES = {"ok", "blocked"}
+
 # TESTED_BY status 三値
 TESTED = "tested"
 UNTESTED = "untested"
@@ -259,52 +272,96 @@ class Executed:
     shards: list[str]
     # route_name -> set(shard) (どれか 1 shard で executed なら executed=true)
     routes: dict[str, set[str]] = field(default_factory=dict)
-    statuses: dict[str, set[str]] = field(default_factory=dict)  # route -> {ok,blocked,..}
-    present: bool = True  # executed.json が与えられたか
+    statuses: dict[str, set[str]] = field(default_factory=dict)  # route -> {ok,blocked}
+    row_count: int = 0               # executed_routes の有効行数 (可用性検証に使う)
+    schema_error: str | None = None  # 最初に見つかった契約違反 (形・run_id) の説明
 
     def is_executed(self, route_name: str) -> bool:
-        """実走した (= status 'ok' が 1 つでもある) route のみ executed=true。
+        """status 'ok' を 1 つでも持つ route だけ executed=true。
 
-        executed.json の status は ok|blocked|skipped。skipped/blocked は
-        「UI で叩けなかった = 触っていない」意味なので executed=false とし unexecuted worklist に
-        残す。route_name in routes (status 無視) だと skipped/blocked も executed 扱いになり
+        blocked は「到達できなかった = 触っていない」意味なので executed=false とし
+        未実行 worklist に残す。route_name の存在だけで executed 扱いにすると
         executed_pct を不当に押し上げる (coverage 信号汚染)。
-        後方互換: status 未記録 (空集合) の route は ok とみなす (旧 executed.json 形の救済)。
+        status を持たない行は入力エラー (executed_schema_invalid) なのでここには来ない。
         """
-        if route_name not in self.routes:
-            return False
-        st = self.statuses.get(route_name)
-        if not st:
-            return True  # status 列を持たない旧形式は従来どおり executed 扱い
-        return "ok" in st
-
-    def skipped_blocked_count(self) -> int:
-        """routes には居るが ok status が 1 つも無い (= skip/block のみ) route 数 (可視化)。"""
+        return "ok" in self.statuses.get(route_name, set())
+
+    def blocked_count(self) -> int:
+        """routes には居るが ok status が 1 つも無い (= blocked のみ) route 数 (可視化)。"""
         return sum(
             1 for name in self.routes
-            if self.statuses.get(name) and "ok" not in self.statuses[name]
+            if "ok" not in self.statuses.get(name, set())
         )
 
 
-def load_executed(path: str | None) -> Executed:
-    """executed.json をロード。
+def load_executed(path: str) -> Executed:
+    """executed.json をロードする。path の省略は受け付けない。
 
-    path が None の場合 present=False の空 Executed を返す
-    (= 全 in_scope 機構を未実行 candidate 扱い)。
+    **入れ物の型から検証する**。dict でない root、list でない shards/executed_routes、
+    dict でない行を素通しすると `.get()` や反復で AttributeError / TypeError になり、
+    main() の捕捉対象外なので終了コード規約 (1 / 3) から外れて traceback で落ちる。
+    `status` も isinstance(str) を確認してから集合照合する (非 hashable で TypeError になるため)。
+    **JSON 構文エラーと I/O は 1、構文上は読めるが形が契約外なら 3**。
     """
-    if path is None:
-        return Executed(run_id=None, shards=[], present=False)
     data = json.loads(Path(path).read_text(encoding="utf-8"))
-    ex = Executed(run_id=data.get("run_id"), shards=list(data.get("shards", [])))
-    for row in data.get("executed_routes", []):
-        name = row.get("route_name")
-        if not name:
-            continue
-        ex.routes.setdefault(name, set()).add(str(row.get("shard", "")))
-        ex.statuses.setdefault(name, set()).add(row.get("status", "ok"))
+    if not isinstance(data, dict):
+        return Executed(run_id=None, shards=[], schema_error="root が JSON object でない")
+
+    raw_shards = data.get("shards")
+    raw_rows = data.get("executed_routes")
+    run_id = data.get("run_id")
+    ex = Executed(run_id=run_id if isinstance(run_id, str) else None, shards=[])
+    if not isinstance(run_id, str) or run_id == "":
+        ex.schema_error = f"run_id が非空文字列でない: {run_id!r}"
+        return ex
+    if not isinstance(raw_shards, list) or not isinstance(raw_rows, list):
+        ex.schema_error = "shards / executed_routes が配列でない"
+        return ex
+    for s in raw_shards:
+        if not isinstance(s, str) or s == "":
+            ex.schema_error = f"shards に非空文字列でない要素がある: {s!r}"
+            return ex
+        ex.shards.append(s)
+
+    for row in raw_rows:
+        if not isinstance(row, dict):
+            ex.schema_error = f"executed_routes の要素が object でない: {row!r}"[:200]
+            break
+        name, shard, status = row.get("route_name"), row.get("shard"), row.get("status")
+        if not isinstance(name, str) or name == "" \
+                or not isinstance(shard, str) or shard == "" \
+                or not isinstance(status, str) or status not in VALID_STATUSES:
+            ex.schema_error = repr(row)[:200]
+            break
+        ex.row_count += 1
+        ex.routes.setdefault(name, set()).add(shard)
+        ex.statuses.setdefault(name, set()).add(status)
     return ex
 
 
+def validate_executed(ex: Executed, run_id: str) -> str | None:
+    """主入力 (実行済み route の記録) の可用性を検証する。
+
+    返値は違反理由。None なら成立している。
+    **`ok` が 0 件は違反にしない** — 全操作が 403/422/500 で跳ねた走行は、
+    主入力としては成立しており、正しい結果は「全件を未実行 worklist に残す」ことである。
+    """
+    # 形の違反を先に見る (root が object でない等のとき run_id 不一致と誤報しないため)
+    if ex.schema_error is not None:
+        return f"executed_schema_invalid (契約外の形: {ex.schema_error})"
+    if ex.run_id != run_id:
+        return f"executed_run_id_mismatch (executed.json={ex.run_id!r} / --run-id={run_id!r})"
+    if not ex.shards:
+        return "executed_shards_missing (shards が空 = どの shard の記録か分からない)"
+    if ex.row_count == 0:
+        return "executed_no_rows (有効な観測行が 1 件も無い = 記録が採れていない)"
+    seen = {s for shards in ex.routes.values() for s in shards}
+    declared = set(ex.shards)
+    if declared != seen:
+        return f"executed_shard_mismatch (宣言={sorted(declared)} / 実際={sorted(seen)})"
+    return None
+
+
 @dataclass
 class TestedByIndex:
     """controller ファイル(app/ 相対) 単位で TESTED_BY edge の有無を引く。"""
@@ -403,10 +460,9 @@ class Correlation:
     cross_unexec_findingful: list[MechanismRow]
     unknown_graph_gap_count: int
     in_scope_count: int = 0
-    executed_present: bool = True
     dropped_other_run: int = 0
     hotspot_threshold: int = 2
-    skipped_blocked_count: int = 0  # status skip/block のみで実走でない route 数
+    blocked_count: int = 0  # status blocked のみで実走でない route 数
 
 
 def correlate(routes, operations, executed, findings, tb_index, *,
@@ -501,10 +557,9 @@ def correlate(routes, operations, executed, findings, tb_index, *,
         cross_unexec_findingful=cross,
         unknown_graph_gap_count=unknown_gap,
         in_scope_count=len(in_scope_rows),
-        executed_present=executed.present,
         dropped_other_run=dropped_other_run,
         hotspot_threshold=hotspot_threshold,
-        skipped_blocked_count=executed.skipped_blocked_count(),
+        blocked_count=executed.blocked_count(),
     )
 
 
@@ -523,8 +578,9 @@ def to_summary(corr: Correlation) -> dict:
         "unknown_graph_gap_count": corr.unknown_graph_gap_count,
         "in_scope_count": n_scope,
         "dropped_other_run": corr.dropped_other_run,
-        "executed_present": corr.executed_present,
-        "skipped_blocked_count": corr.skipped_blocked_count,
+        # 内訳 (可視化のみ。終了コードには影響しない)
+        "executed_ok_count": n_exec,
+        "blocked_count": corr.blocked_count,
         # 副 (% は目標にしない・gaming 防止)
         "executed_pct": executed_pct,
     }
@@ -550,9 +606,6 @@ def render_worklist(corr: Correlation) -> str:
     L.append("> 主出力 = **未カバー worklist**。絶対 % は副 (summary の `*_pct` のみ)・目標にしない。")
     L.append(f"> 分母 (in_scope 機構) = **{corr.in_scope_count}** 件 (区分 '外' を除く)。"
              " 分母変更時はこの値の差分を注記すること (gaming 防止)。")
-    if not corr.executed_present:
-        L.append("> ⚠ executed.json 未指定 = 全 in_scope 機構を **未実行 candidate** として列挙"
-                 " (走行ログ未連携)。")
     if corr.dropped_other_run:
         L.append(f"> ℹ run_id 不一致で除外した finding: {corr.dropped_other_run} 件"
                  " (別 run の混入防止)。")
@@ -627,7 +680,8 @@ def render_worklist(corr: Correlation) -> str:
     L.append(f"- hotspot_count: {s['hotspot_count']}")
     L.append(f"- untested_real_count (TS): {s['untested_real_count']}")
     L.append(f"- unknown_graph_gap_count (PHP): {s['unknown_graph_gap_count']}")
-    L.append(f"- skipped_blocked_count (status skip/block = 未実走扱い): {s['skipped_blocked_count']}")
+    L.append(f"- executed_ok_count (in_scope ∧ status ok): {s['executed_ok_count']}")
+    L.append(f"- blocked_count (status blocked のみ = 未実走扱い): {s['blocked_count']}")
     L.append(f"- executed_pct (副・目標にしない): {s['executed_pct']:.0%}")
     L.append("")
     return "\n".join(L)
@@ -638,7 +692,7 @@ def main(argv=None) -> int:
     ap.add_argument("--route-list", help="route:list --json path (省略時 php artisan route:list を実行)")
     ap.add_argument("--operations", required=True, help="operations.md path")
     ap.add_argument("--findings", required=True, help="findings.jsonl path or - for stdin")
-    ap.add_argument("--executed", help="executed.json path (省略時 全 in_scope を未実行 candidate)")
+    ap.add_argument("--executed", help="executed.json path (build_executed.py が生成する)")
     ap.add_argument("--graph-db", required=True, help="graph.db path")
     ap.add_argument("--run-id", required=True, help="run_id for join")
     ap.add_argument("--project-dir", help="route:list 取得時の cwd (省略時 cwd)")
@@ -646,6 +700,14 @@ def main(argv=None) -> int:
     ap.add_argument("--json", action="store_true", help="machine summary as JSON")
     args = ap.parse_args(argv)
 
+    # argparse の required=True にはしない。required にすると argparse 自身が exit 2 で落ち、
+    # 「主入力の可用性違反 = 3」という規約から外れるため、main 内で明示的に検査する。
+    if args.executed is None:
+        print("ERROR: 主入力が揃わない (reason=executed_missing): "
+              "--executed が指定されていない。build_executed.py で executed.json を作ってから渡すこと。",
+              file=sys.stderr)
+        return EXIT_INPUT_UNAVAILABLE
+
     try:
         routes = load_route_list(args.route_list, project_dir=args.project_dir)
         operations = load_operations(args.operations)
@@ -655,7 +717,14 @@ def main(argv=None) -> int:
     except (ValueError, json.JSONDecodeError, OSError, sqlite3.Error,
             subprocess.CalledProcessError) as e:
         print(f"ERROR: {e}", file=sys.stderr)
-        return 1
+        return EXIT_INPUT_ERROR
+
+    reason = validate_executed(executed, args.run_id)
+    if reason is not None:
+        print(f"ERROR: 主入力が揃わない (reason={reason})。"
+              " 未実行 worklist は出力しない (揃わない走行を成功として返さないため)。",
+              file=sys.stderr)
+        return EXIT_INPUT_UNAVAILABLE
 
     corr = correlate(
         routes, operations, executed, findings, tb_index,
@@ -667,7 +736,7 @@ def main(argv=None) -> int:
         print(json.dumps(to_summary(corr), ensure_ascii=False, indent=2))
     else:
         print(render_worklist(corr))
-    return 0
+    return EXIT_OK
 
 
 if __name__ == "__main__":
diff --git a/.claude/skills/app-bug-hunt/coverage/fixtures/executed.sample.json b/.claude/skills/app-bug-hunt/coverage/fixtures/executed.sample.json
index 1048a42..4b94852 100644
--- a/.claude/skills/app-bug-hunt/coverage/fixtures/executed.sample.json
+++ b/.claude/skills/app-bug-hunt/coverage/fixtures/executed.sample.json
@@ -1,8 +1,10 @@
 {
   "run_id": "20260618-test",
-  "shards": ["0", "1", "2"],
+  "shards": ["1", "2"],
   "executed_routes": [
-    {"route_name": "register.store", "shard": "2", "story": "S1", "status": "ok"},
-    {"route_name": "organizations.store", "shard": "1", "story": "S1", "status": "ok"}
-  ]
+    {"route_name": "register.store", "shard": "2", "status": "ok", "http_statuses": [302]},
+    {"route_name": "organizations.store", "shard": "1", "status": "ok", "http_statuses": [200, 302]},
+    {"route_name": "projects.update", "shard": "1", "status": "blocked", "http_statuses": [403]}
+  ],
+  "unresolved": {"1": 3}
 }
diff --git a/.claude/skills/app-bug-hunt/coverage/test_build_executed.py b/.claude/skills/app-bug-hunt/coverage/test_build_executed.py
new file mode 100644
index 0000000..b8a9f86
--- /dev/null
+++ b/.claude/skills/app-bug-hunt/coverage/test_build_executed.py
@@ -0,0 +1,194 @@
+#!/usr/bin/env python3
+"""build_executed.py の単体テスト (stdlib unittest)。
+
+入出力は tempfile で作る。**主入力が揃わない走行を成功にしない**ことが本モジュールの契約なので、
+負の対照 (終了コード 3) を理由コードごとに 1 本ずつ置く。
+
+実行: python3 -m unittest test_build_executed -v
+"""
+from __future__ import annotations
+
+import json
+import tempfile
+import unittest
+from pathlib import Path
+
+import build_executed as B
+import correlate as C
+
+RUN = "20260618-082101"
+
+
+def row(shard: str, name: str | None, status: str = "ok", http: int = 200,
+        run_id: str = RUN, method: str = "GET") -> dict:
+    return {
+        "run_id": run_id,
+        "shard": shard,
+        "route_name": name,
+        "method": method,
+        "path": "/x",
+        "status": status,
+        "http_status": http,
+    }
+
+
+class BuildExecutedTest(unittest.TestCase):
+    def setUp(self) -> None:
+        self.tmp = tempfile.TemporaryDirectory()
+        self.addCleanup(self.tmp.cleanup)
+        self.base = Path(self.tmp.name)
+        self.input_dir = self.base / "capture"
+        self.input_dir.mkdir()
+        self.out = self.base / "executed.json"
+
+    # ------------------------------------------------------------------ #
+    # helpers
+    # ------------------------------------------------------------------ #
+    def write_jsonl(self, shard: str, rows: list[dict], *, raw: str | None = None) -> None:
+        path = self.input_dir / f"{RUN}-{shard}.jsonl"
+        if raw is not None:
+            path.write_text(raw, encoding="utf-8")
+            return
+        path.write_text("".join(json.dumps(r, ensure_ascii=False) + "\n" for r in rows),
+                        encoding="utf-8")
+
+    def run_main(self, shards: list[str]) -> int:
+        args = ["--run-id", RUN, "--input-dir", str(self.input_dir), "--out", str(self.out)]
+        for s in shards:
+            args += ["--shard", s]
+        return B.main(args)
+
+    def loaded_out(self) -> dict:
+        return json.loads(self.out.read_text(encoding="utf-8"))
+
+    # ------------------------------------------------------------------ #
+    # 正の対照
+    # ------------------------------------------------------------------ #
+    def test_two_shards_folded(self):
+        self.write_jsonl("0", [
+            row("0", "projects.store", "ok", 302),
+            row("0", "projects.store", "ok", 302),   # 同一キーは畳まれる
+            row("0", "projects.store", "ok", 200),   # http_statuses は集合
+            row("0", "projects.update", "blocked", 403),
+        ])
+        self.write_jsonl("1", [row("1", "login.store", "ok", 302)])
+
+        self.assertEqual(self.run_main(["0", "1"]), B.EXIT_OK)
+        data = self.loaded_out()
+        self.assertEqual(data["run_id"], RUN)
+        self.assertEqual(data["shards"], ["0", "1"])
+        self.assertEqual(data["executed_routes"], [
+            {"route_name": "login.store", "shard": "1", "status": "ok", "http_statuses": [302]},
+            {"route_name": "projects.store", "shard": "0", "status": "ok", "http_statuses": [200, 302]},
+            {"route_name": "projects.update", "shard": "0", "status": "blocked", "http_statuses": [403]},
+        ])
+        self.assertEqual(data["unresolved"], {})
+
+    def test_unresolved_rows_are_counted_not_listed(self):
+        self.write_jsonl("0", [
+            row("0", "dashboard", "ok", 200),
+            row("0", None, "ok", 200),
+            row("0", None, "blocked", 500),
+        ])
+        self.assertEqual(self.run_main(["0"]), B.EXIT_OK)
+        data = self.loaded_out()
+        self.assertEqual([r["route_name"] for r in data["executed_routes"]], ["dashboard"])
+        self.assertEqual(data["unresolved"], {"0": 2})
+
+    def test_output_is_valid_input_for_correlate(self):
+        # 生成器と照合器の契約が食い違っていないことを 1 本で固定する。
+        self.write_jsonl("0", [row("0", "dashboard", "ok", 200)])
+        self.write_jsonl("1", [row("1", "login.store", "blocked", 302)])
+        self.assertEqual(self.run_main(["0", "1"]), B.EXIT_OK)
+
+        ex = C.load_executed(str(self.out))
+        self.assertIsNone(C.validate_executed(ex, RUN))
+        self.assertTrue(ex.is_executed("dashboard"))
+        self.assertFalse(ex.is_executed("login.store"))
+
+    # ------------------------------------------------------------------ #
+    # 負の対照 (終了コード 3)
+    # ------------------------------------------------------------------ #
+    def test_missing_capture_file_returns_3(self):
+        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
+        self.assertFalse(self.out.exists())
+
+    def test_failure_marker_returns_3(self):
+        self.write_jsonl("0", [row("0", "dashboard")])
+        (self.input_dir / f"{RUN}-0.error").write_text("disk full\n", encoding="utf-8")
+        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
+        self.assertFalse(self.out.exists())
+
+    def test_broken_line_returns_3(self):
+        self.write_jsonl("0", [], raw='{"run_id": "x"\n')
+        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
+        self.assertFalse(self.out.exists())
+
+    def test_other_run_row_returns_3(self):
+        self.write_jsonl("0", [
+            row("0", "dashboard"),
+            row("0", "login.store", run_id="20260101-000000"),
+        ])
+        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
+        self.assertFalse(self.out.exists())
+
+    def test_named_rows_absent_returns_3(self):
+        # route_name: null の行しか無い shard もここで落ちる。
+        self.write_jsonl("0", [row("0", None)])
+        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
+        self.assertFalse(self.out.exists())
+
+    def test_empty_file_returns_3(self):
+        self.write_jsonl("0", [], raw="")
+        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
+
+    def test_unknown_status_returns_3(self):
+        self.write_jsonl("0", [row("0", "dashboard", status="whatever")])
+        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
+
+    def test_shard_mismatch_in_row_returns_3(self):
+        # ファイル名は shard 0 なのに中身が shard 1 を名乗る
+        self.write_jsonl("0", [row("1", "dashboard")])
+        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
+
+    def test_non_int_http_status_returns_3(self):
+        for bad in ["200", True, None]:
+            with self.subTest(http_status=bad):
+                r = row("0", "dashboard")
+                r["http_status"] = bad
+                self.write_jsonl("0", [r])
+                self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
+
+    def test_empty_route_name_returns_3(self):
+        r = row("0", "dashboard")
+        r["route_name"] = ""
+        self.write_jsonl("0", [r])
+        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
+
+    def test_missing_method_returns_3(self):
+        r = row("0", "dashboard")
+        del r["method"]
+        self.write_jsonl("0", [r])
+        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
+
+    def test_row_not_object_returns_3(self):
+        self.write_jsonl("0", [], raw='["not", "an", "object"]\n')
+        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
+
+    def test_failure_does_not_overwrite_existing_out(self):
+        self.out.write_text('{"keep": "me"}', encoding="utf-8")
+        self.write_jsonl("0", [row("0", "dashboard")])
+        (self.input_dir / f"{RUN}-0.error").write_text("boom\n", encoding="utf-8")
+
+        self.assertEqual(self.run_main(["0"]), B.EXIT_INPUT_UNAVAILABLE)
+        self.assertEqual(self.out.read_text(encoding="utf-8"), '{"keep": "me"}')
+
+    def test_one_bad_shard_fails_the_whole_run(self):
+        # 揃っている shard があっても、揃わない shard が 1 つあれば成功にしない。
+        self.write_jsonl("0", [row("0", "dashboard")])
+        self.assertEqual(self.run_main(["0", "1"]), B.EXIT_INPUT_UNAVAILABLE)
+        self.assertFalse(self.out.exists())
+
+
+if __name__ == "__main__":
+    unittest.main()
diff --git a/.claude/skills/app-bug-hunt/coverage/test_correlate.py b/.claude/skills/app-bug-hunt/coverage/test_correlate.py
index 56a1992..3ad3c3d 100644
--- a/.claude/skills/app-bug-hunt/coverage/test_correlate.py
+++ b/.claude/skills/app-bug-hunt/coverage/test_correlate.py
@@ -8,6 +8,7 @@ graph.db はテスト用 temp sqlite を実 DB のスキーマ(edges.kind/source
 """
 from __future__ import annotations
 
+import json
 import os
 import sqlite3
 import tempfile
@@ -278,9 +279,12 @@ class CorrelateTest(unittest.TestCase):
         self.tb = C.tested_by_index(self.db)
 
     def _executed(self, routes_executed):
+        # status は生成器が必ず付ける (ok|blocked の 2 値)。ここでは実走 = ok を組む。
         ex = C.Executed(run_id=self.run_id, shards=["0", "1"])
         for name, shard in routes_executed:
+            ex.row_count += 1
             ex.routes.setdefault(name, set()).add(shard)
+            ex.statuses.setdefault(name, set()).add("ok")
         return ex
 
     def test_unexecuted_excludes_deviate_and_out_of_scope(self):
@@ -298,8 +302,7 @@ class CorrelateTest(unittest.TestCase):
         self.assertNotIn("billing.changePlan", names)
 
     def test_union_across_shards(self):
-        ex = C.Executed(run_id=self.run_id, shards=["0", "1"])
-        ex.routes.setdefault("login.store", set()).add("1")
+        ex = self._executed([("login.store", "1")])
         corr = C.correlate(self.routes, self.operations, ex, [], self.tb,
                            run_id=self.run_id)
         names = {r.route_name for r in corr.unexecuted}
@@ -380,29 +383,33 @@ class CorrelateTest(unittest.TestCase):
         cross_names = {r.route_name for r in corr.cross_unexec_findingful}
         self.assertEqual(cross_names, {"login.store"})
 
-    def test_skipped_status_not_executed(self):
-        # status=skipped/blocked の route は executed=false で unexecuted に残る。
+    def test_blocked_status_not_executed(self):
+        # status=blocked の route は executed=false で未実行 worklist に残る。
         ex = C.Executed(run_id=self.run_id, shards=["0"])
         ex.routes.setdefault("login.store", set()).add("0")
-        ex.statuses.setdefault("login.store", set()).add("skipped")
+        ex.statuses.setdefault("login.store", set()).add("blocked")
         ex.routes.setdefault("register.store", set()).add("0")
         ex.statuses.setdefault("register.store", set()).add("ok")
         corr = C.correlate(self.routes, self.operations, ex, [], self.tb,
                            run_id=self.run_id)
         names = {r.route_name for r in corr.unexecuted}
-        self.assertIn("login.store", names)       # skipped = 未実走扱い
+        self.assertIn("login.store", names)       # blocked = 未実走扱い
         self.assertNotIn("register.store", names)  # ok = 実走
-        self.assertEqual(corr.skipped_blocked_count, 1)
-
-    def test_missing_status_treated_as_executed(self):
-        # 後方互換: status 列を持たない旧形式 (空 statuses) は従来どおり executed 扱い。
-        ex = C.Executed(run_id=self.run_id, shards=["0"])
-        ex.routes.setdefault("login.store", set()).add("0")  # statuses なし
-        corr = C.correlate(self.routes, self.operations, ex, [], self.tb,
-                           run_id=self.run_id)
-        names = {r.route_name for r in corr.unexecuted}
-        self.assertNotIn("login.store", names)
-        self.assertEqual(corr.skipped_blocked_count, 0)
+        self.assertEqual(corr.blocked_count, 1)
+
+    def test_row_without_status_is_rejected(self):
+        # 旧「status 未記録なら ok とみなす」救済は無い。status 欠落行は
+        # load_executed が契約違反として弾き、集計に載らない。
+        path = Path(self.tmp.name) / "no-status.json"
+        path.write_text(json.dumps({
+            "run_id": self.run_id,
+            "shards": ["0"],
+            "executed_routes": [{"route_name": "login.store", "shard": "0"}],
+        }), encoding="utf-8")
+        ex = C.load_executed(str(path))
+        self.assertIsNotNone(ex.schema_error)
+        self.assertFalse(ex.is_executed("login.store"))
+        self.assertIn("executed_schema_invalid", C.validate_executed(ex, self.run_id) or "")
 
     def test_summary_unexecuted_count_is_primary(self):
         ex = self._executed([("register.store", "0")])
@@ -484,8 +491,14 @@ class MainTest(unittest.TestCase):
             encoding="utf-8",
         )
         self.executed_path = base / "executed.json"
-        self.executed_path.write_text(
-            '{"run_id":"R1","shards":["0"],"executed_routes":[]}', encoding="utf-8")
+        # 主入力は「有効な観測行を 1 件以上持つ」ことが成立条件 (fail-closed 契約)。
+        self.executed_path.write_text(json.dumps({
+            "run_id": "R1", "shards": ["0"],
+            "executed_routes": [
+                {"route_name": "register.store", "shard": "0", "status": "ok",
+                 "http_statuses": [302]},
+            ],
+        }), encoding="utf-8")
         self.db = str(base / "graph.db")
         make_graph_db(self.db, [("/workspace/resources/js/x.ts::x", "/workspace/resources/js/t.ts::t")])
 
@@ -521,18 +534,169 @@ class MainTest(unittest.TestCase):
         ])
         self.assertEqual(rc, 1)
 
-    def test_main_empty_inputs_no_exception(self):
+    def test_main_empty_findings_no_exception(self):
         empty = Path(self.tmp.name) / "empty.jsonl"
         empty.write_text("", encoding="utf-8")
         rc = C.main([
             "--route-list", str(self.route_path),
             "--operations", str(self.ops_path),
             "--findings", str(empty),
-            "--graph-db", self.db,  # executed 省略 = candidate モード
+            "--executed", str(self.executed_path),
+            "--graph-db", self.db,
             "--run-id", "R1",
         ])
         self.assertEqual(rc, 0)
 
+    # ------------------------------------------------------------------ #
+    # fail-closed 契約: 主入力が揃わない走行は成功にしない (終了コード 3)
+    # ------------------------------------------------------------------ #
+    def _write_executed(self, payload) -> str:
+        path = Path(self.tmp.name) / "custom-executed.json"
+        path.write_text(json.dumps(payload) if not isinstance(payload, str) else payload,
+                        encoding="utf-8")
+        return str(path)
+
+    def _main_with_executed(self, payload) -> int:
+        return C.main([
+            "--route-list", str(self.route_path),
+            "--operations", str(self.ops_path),
+            "--findings", str(self.findings_path),
+            "--executed", self._write_executed(payload),
+            "--graph-db", self.db,
+            "--run-id", "R1",
+        ])
+
+    def test_main_missing_executed_returns_3(self):
+        rc = C.main([
+            "--route-list", str(self.route_path),
+            "--operations", str(self.ops_path),
+            "--findings", str(self.findings_path),
+            "--graph-db", self.db,
+            "--run-id", "R1",
+        ])
+        self.assertEqual(rc, C.EXIT_INPUT_UNAVAILABLE)
+
+    def test_main_run_id_mismatch_returns_3(self):
+        self.assertEqual(self._main_with_executed({
+            "run_id": "OTHER", "shards": ["0"],
+            "executed_routes": [{"route_name": "register.store", "shard": "0", "status": "ok"}],
+        }), C.EXIT_INPUT_UNAVAILABLE)
+
+    def test_main_empty_executed_returns_3(self):
+        self.assertEqual(self._main_with_executed({
+            "run_id": "R1", "shards": ["0"], "executed_routes": [],
+        }), C.EXIT_INPUT_UNAVAILABLE)
+
+    def test_main_shard_mismatch_returns_3(self):
+        self.assertEqual(self._main_with_executed({
+            "run_id": "R1", "shards": ["0", "1"],
+            "executed_routes": [{"route_name": "register.store", "shard": "0", "status": "ok"}],
+        }), C.EXIT_INPUT_UNAVAILABLE)
+
+    def test_main_shards_missing_returns_3(self):
+        self.assertEqual(self._main_with_executed({
+            "run_id": "R1", "shards": [],
+            "executed_routes": [{"route_name": "register.store", "shard": "0", "status": "ok"}],
+        }), C.EXIT_INPUT_UNAVAILABLE)
+
+    def test_main_all_blocked_is_valid_input(self):
+        # `ok` が 0 件でも主入力としては成立している (全件が未実行 worklist に残るのが正)。
+        rc = self._main_with_executed({
+            "run_id": "R1", "shards": ["0"],
+            "executed_routes": [{"route_name": "register.store", "shard": "0", "status": "blocked"}],
+        })
+        self.assertEqual(rc, C.EXIT_OK)
+
+    def test_main_schema_violations_return_3(self):
+        # 契約外の形は **traceback ではなく終了コード 3** で落ちること。
+        ok_row = {"route_name": "register.store", "shard": "0", "status": "ok"}
+        cases = {
+            "root が object でない": [1, 2, 3],
+            "shards が配列でない": {"run_id": "R1", "shards": "0", "executed_routes": [ok_row]},
+            "executed_routes が配列でない": {"run_id": "R1", "shards": ["0"], "executed_routes": {}},
+            "行が object でない": {"run_id": "R1", "shards": ["0"], "executed_routes": ["x"]},
+            "status が未知値": {"run_id": "R1", "shards": ["0"],
+                            "executed_routes": [{**ok_row, "status": "skipped"}]},
+            "status が非文字列": {"run_id": "R1", "shards": ["0"],
+                             "executed_routes": [{**ok_row, "status": {"a": 1}}]},
+            "route_name が空": {"run_id": "R1", "shards": ["0"],
+                             "executed_routes": [{**ok_row, "route_name": ""}]},
+            "shard が非文字列": {"run_id": "R1", "shards": ["0"],
+                            "executed_routes": [{**ok_row, "shard": 0}]},
+            "run_id が null": {"run_id": None, "shards": ["0"], "executed_routes": [ok_row]},
+            "run_id が空文字": {"run_id": "", "shards": ["0"], "executed_routes": [ok_row]},
+            "run_id が数値": {"run_id": 1, "shards": ["0"], "executed_routes": [ok_row]},
+        }
+        for label, payload in cases.items():
+            with self.subTest(case=label):
+                self.assertEqual(self._main_with_executed(payload), C.EXIT_INPUT_UNAVAILABLE)
+
+    def test_main_broken_json_returns_1(self):
+        # 構文として読めない入力は従来どおり 1 (可用性違反 3 とは分ける)。
+        self.assertEqual(self._main_with_executed('{"run_id": '), C.EXIT_INPUT_ERROR)
+
+    def test_run_id_shape_violation_is_schema_error_not_mismatch(self):
+        # run_id が非文字列のときは run_id 不一致ではなく形の違反として報告する。
+        path = self._write_executed({"run_id": 1, "shards": ["0"], "executed_routes": []})
+        ex = C.load_executed(path)
+        reason = C.validate_executed(ex, "R1")
+        self.assertIsNotNone(reason)
+        self.assertIn("executed_schema_invalid", reason)
+
+
+class ExecutedValidationTest(unittest.TestCase):
+    """validate_executed() の単体検査 (成立 → None / 各違反 → 理由文字列)。"""
+
+    def _executed(self, **kwargs) -> C.Executed:
+        ex = C.Executed(run_id=kwargs.pop("run_id", "R1"), shards=kwargs.pop("shards", ["0"]))
+        for name, shard, status in kwargs.pop("rows", [("a", "0", "ok")]):
+            ex.row_count += 1
+            ex.routes.setdefault(name, set()).add(shard)
+            ex.statuses.setdefault(name, set()).add(status)
+        ex.schema_error = kwargs.pop("schema_error", None)
+        return ex
+
+    def test_valid_input_returns_none(self):
+        self.assertIsNone(C.validate_executed(self._executed(), "R1"))
+
+    def test_schema_error_wins_over_run_id_mismatch(self):
+        ex = self._executed(run_id="OTHER", schema_error="root が JSON object でない")
+        reason = C.validate_executed(ex, "R1")
+        self.assertIn("executed_schema_invalid", reason)
+
+    def test_run_id_mismatch(self):
+        self.assertIn("executed_run_id_mismatch",
+                      C.validate_executed(self._executed(run_id="OTHER"), "R1"))
+
+    def test_shards_missing(self):
+        self.assertIn("executed_shards_missing",
+                      C.validate_executed(self._executed(shards=[]), "R1"))
+
+    def test_no_rows(self):
+        ex = C.Executed(run_id="R1", shards=["0"])
+        self.assertIn("executed_no_rows", C.validate_executed(ex, "R1"))
+
+    def test_shard_mismatch(self):
+        ex = self._executed(shards=["0", "1"])
+        self.assertIn("executed_shard_mismatch", C.validate_executed(ex, "R1"))
+
+    def test_all_blocked_is_valid(self):
+        ex = self._executed(rows=[("a", "0", "blocked")])
+        self.assertIsNone(C.validate_executed(ex, "R1"))
+
+
+class RenderWorklistTest(unittest.TestCase):
+    """旧 fail-open の注記が二度と出力に現れないこと。"""
+
+    def test_no_missing_executed_notice(self):
+        corr = C.Correlation(
+            run_id="R1", rows=[], unexecuted=[], untested_real=[],
+            finding_hotspots=[], cross_unexec_findingful=[], unknown_graph_gap_count=0,
+        )
+        out = C.render_worklist(corr)
+        self.assertNotIn("executed.json 未指定", out)
+        self.assertNotIn("未実行 candidate", out)
+
 
 if __name__ == "__main__":
     unittest.main()
diff --git a/.claude/skills/app-bug-hunt/coverage/test_naming_no_stale.py b/.claude/skills/app-bug-hunt/coverage/test_naming_no_stale.py
index 0850d40..0e4dfcc 100644
--- a/.claude/skills/app-bug-hunt/coverage/test_naming_no_stale.py
+++ b/.claude/skills/app-bug-hunt/coverage/test_naming_no_stale.py
@@ -1,15 +1,22 @@
-"""操作到達/コード到達カバレッジへの命名統一の後退防止 self-test。
+"""操作到達/コード到達カバレッジの用語・契約の後退防止 self-test。
 
-旧 Stage 付番 (Stage1/Stage3) と旧出力ファイル名 (coverage-stage1.md / coverage-stage3.md) が
-skill 本文ファイルに再混入していないことを機械検知する。
+2 群のパターンを検知する。
 
-対象は `.claude/skills/app-bug-hunt/` 配下の本文 (.md / .py)。誤 fail を避けるため、
-devnotes (設計 migration note・履歴説明) とこの test 自身は対象外にする。
+1. STALE_PATTERNS — 旧 Stage 付番 (Stage1/Stage3) と旧出力ファイル名
+   (coverage-stage1.md / coverage-stage3.md)。対象は skill 配下の本文 (.md / .py) 全部。
+2. IMPLEMENTATION_ONLY_PATTERNS — 旧 fail-open の文言 (「--executed 省略時」「未実行 candidate」)
+   と旧語彙 (skipped_blocked_count / status 値としての skipped)。
+   **対象から test_*.py を除外する** — 旧値を拒否する負の対照テストは、入力 fixture として
+   その文字列を必要とするためである。.md は全部対象に残す。
+
+裸の `skipped` は禁止語にしない (unittest の skipTest や無関係な英文を巻き込むため)。
+誤 fail を避けるため、devnotes (設計 migration note・履歴説明) とこの test 自身は対象外。
 """
 
 from __future__ import annotations
 
 import re
+import tempfile
 import unittest
 from pathlib import Path
 
@@ -22,14 +29,23 @@ STALE_PATTERNS = [
     re.compile(r"coverage-stage[13]\.md"),
 ]
 
+# 旧 fail-open の文言と旧語彙。実装ファイル (と全 .md) にだけ禁じる。
+IMPLEMENTATION_ONLY_PATTERNS = [
+    re.compile(r"--executed\s*(を)?省略"),
+    re.compile(r"未実行\s*candidate"),
+    re.compile(r"skipped_blocked_count"),
+    re.compile(r"ok\|blocked\|skipped"),
+    re.compile(r"['\"]skipped['\"]"),
+]
+
 # 対象外: 履歴・設計ノートは devnotes 側に隔離されている前提。skill 配下は本 test 自身のみ除外。
 EXCLUDE_NAMES = {"test_naming_no_stale.py"}
 
 
-def _target_files() -> list[Path]:
+def _target_files(root: Path = SKILL_ROOT) -> list[Path]:
     files: list[Path] = []
     for ext in ("*.md", "*.py"):
-        for p in SKILL_ROOT.rglob(ext):
+        for p in root.rglob(ext):
             if p.name in EXCLUDE_NAMES:
                 continue
             if "devnotes" in p.parts:
@@ -38,22 +54,88 @@ def _target_files() -> list[Path]:
     return files
 
 
+def _is_test_module(path: Path) -> bool:
+    return path.suffix == ".py" and path.name.startswith("test_")
+
+
+def scan(root: Path = SKILL_ROOT) -> tuple[list[str], list[str]]:
+    """(旧 Stage 付番の違反, 旧 fail-open 文言・旧語彙の違反) を返す。"""
+    stale: list[str] = []
+    impl: list[str] = []
+    for path in _target_files(root):
+        rel = path.relative_to(root)
+        text = path.read_text(encoding="utf-8")
+        for lineno, line in enumerate(text.splitlines(), start=1):
+            for pat in STALE_PATTERNS:
+                if pat.search(line):
+                    stale.append(f"{rel}:{lineno}: {line.strip()[:80]}")
+            if _is_test_module(path):
+                continue
+            for pat in IMPLEMENTATION_ONLY_PATTERNS:
+                if pat.search(line):
+                    impl.append(f"{rel}:{lineno}: {line.strip()[:80]}")
+    return stale, impl
+
+
 class StaleNamingTest(unittest.TestCase):
     def test_no_stage_terminology_in_skill_body(self) -> None:
-        offenders: list[str] = []
-        for path in _target_files():
-            text = path.read_text(encoding="utf-8")
-            for lineno, line in enumerate(text.splitlines(), start=1):
-                for pat in STALE_PATTERNS:
-                    if pat.search(line):
-                        rel = path.relative_to(SKILL_ROOT)
-                        offenders.append(f"{rel}:{lineno}: {line.strip()[:80]}")
+        stale, _ = scan()
         self.assertEqual(
-            offenders,
+            stale,
             [],
-            "旧 Stage 付番 / coverage-stage*.md が skill 本文に残存:\n" + "\n".join(offenders),
+            "旧 Stage 付番 / coverage-stage*.md が skill 本文に残存:\n" + "\n".join(stale),
         )
 
+    def test_no_stale_fail_open_wording_in_implementation(self) -> None:
+        _, impl = scan()
+        self.assertEqual(
+            impl,
+            [],
+            "旧 fail-open の文言 / 旧語彙が実装・文書に残存:\n" + "\n".join(impl),
+        )
+
+
+class GateItselfTest(unittest.TestCase):
+    """gate 自身が空振りしていないことの検査 (合成ファイルで判定する)。"""
+
+    def setUp(self) -> None:
+        self.tmp = tempfile.TemporaryDirectory()
+        self.addCleanup(self.tmp.cleanup)
+        self.root = Path(self.tmp.name)
+
+    def _write(self, name: str, body: str) -> None:
+        (self.root / name).write_text(body, encoding="utf-8")
+
+    def test_detects_in_implementation_file(self) -> None:
+        self._write("impl.py", "x = 1  # --executed 省略時は全 in_scope を未実行 candidate 扱い\n")
+        _, impl = scan(self.root)
+        # 1 行に 2 パターン一致するので件数は数えず、検出されたことだけを固定する。
+        self.assertTrue(impl, "実装ファイルの旧 fail-open 文言を検出できていない")
+        self.assertTrue(all(v.startswith("impl.py:1:") for v in impl), impl)
+
+    def test_detects_old_vocabulary_in_markdown(self) -> None:
+        self._write("doc.md", "status は `ok|blocked|skipped`。\n")
+        _, impl = scan(self.root)
+        self.assertEqual(len(impl), 1, impl)
+
+    def test_does_not_detect_in_test_module(self) -> None:
+        self._write("test_something.py", 'row = {"status": "skipped"}  # 旧値を拒否する負の対照\n')
+        _, impl = scan(self.root)
+        self.assertEqual(impl, [])
+
+    def test_stale_stage_patterns_still_apply_to_test_modules(self) -> None:
+        # 旧 Stage 付番は test_*.py も対象 (除外は IMPLEMENTATION_ONLY_PATTERNS だけ)。
+        self._write("test_something.py", "# Stage1 の名残\n")
+        stale, _ = scan(self.root)
+        self.assertEqual(len(stale), 1, stale)
+
+    def test_excluded_name_is_skipped(self) -> None:
+        # 自ファイル除外 (EXCLUDE_NAMES) が効いていること (依存を暗黙にしない)。
+        self._write("test_naming_no_stale.py", "# Stage1 と 未実行 candidate\n")
+        stale, impl = scan(self.root)
+        self.assertEqual(stale, [])
+        self.assertEqual(impl, [])
+
 
 if __name__ == "__main__":
     unittest.main()
diff --git a/app/Enums/Security/RescueRouteGateDisposition.php b/app/Enums/Security/RescueRouteGateDisposition.php
index 96af7d7..16ee3ca 100644
--- a/app/Enums/Security/RescueRouteGateDisposition.php
+++ b/app/Enums/Security/RescueRouteGateDisposition.php
@@ -35,6 +35,7 @@ enum RescueRouteGateDisposition: string
     case BlockTwoFactorDisable = 'App\Http\Middleware\BlockTwoFactorDisableForEnforcedOrganizations';
     case NoStoreCacheHeaders = 'App\Http\Middleware\NoStoreCacheHeadersForAuthenticatedPages';
     case NotPendingDeletion = 'App\Http\Middleware\EnsureAccountNotPendingDeletion';
+    case BughuntExecutedRoute = 'App\Http\Middleware\BughuntExecutedRouteMiddleware';
 
     /** この middleware が救済 route をどう扱うかの分類。 */
     public function disposition(): RescueRouteGateKind
@@ -43,7 +44,7 @@ public function disposition(): RescueRouteGateKind
             self::RequireTwoFactor, self::NotPendingDeletion => RescueRouteGateKind::PassesRescueRoute,
             self::Authenticate, self::AuthenticateSession, self::EnsureEmailIsVerified => RescueRouteGateKind::ShortCircuitsButEscapable,
             self::HandleInertiaRequests, self::SecurityHeaders, self::BlockTwoFactorDisable,
-            self::NoStoreCacheHeaders => RescueRouteGateKind::NeverShortCircuitsRescueRoute,
+            self::NoStoreCacheHeaders, self::BughuntExecutedRoute => RescueRouteGateKind::NeverShortCircuitsRescueRoute,
         };
     }
 
@@ -79,6 +80,9 @@ public function rationale(): string
             self::NotPendingDeletion => '退会予約中の凍結ゲート。救済 route は '
                 .'AccountDeletionFreezeAllowance::DeletionRequestDestroy として登録済みで、'
                 .'凍結中に必ず実行できなければ猶予期間の意味が消える。**non-exemptible**。',
+            self::BughuntExecutedRoute => 'bug-hunt の実行済み route 記録器。必ず $next を呼び、'
+                .'応答も加工しない観測器であり、リクエストを短絡させる分岐を持たない。'
+                .'加えて既定 no-op (env 既定 false + production 除外) なので救済の到達性に影響しない。',
         };
     }
 
diff --git a/app/Http/Middleware/BughuntExecutedRouteMiddleware.php b/app/Http/Middleware/BughuntExecutedRouteMiddleware.php
new file mode 100644
index 0000000..2e36b2f
--- /dev/null
+++ b/app/Http/Middleware/BughuntExecutedRouteMiddleware.php
@@ -0,0 +1,213 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Middleware;
+
+use Closure;
+use Illuminate\Http\Request;
+use Illuminate\Routing\Route;
+use Illuminate\Support\Facades\Log;
+use RuntimeException;
+use Symfony\Component\HttpFoundation\Response;
+use Throwable;
+
+/**
+ * 実行済み route の記録 (bug-hunt): 「どの操作を実際に叩けたか」を走行中に機械記録する観測器。
+ *
+ * **位置が意味を持つ**: 本 middleware は web グループの末尾かつ bootstrap/app.php の
+ * priority list の鎖の最後に置く。したがって handle() に到達したことは
+ * 「認証・テナント境界 404・2FA 強制・メール検証・課金ゲート・退会凍結をすべて通過し、
+ * controller 呼び出しの直前まで到達した」という機械的事実を意味する。
+ * 上流のいずれかが短絡した要求は記録されず、その route は未実行のまま worklist に残る。
+ *
+ * **罠**: route middleware の terminate() は Kernel::terminateMiddleware が
+ * gatherRouteMiddleware() の全件を回すため、**実際に handle() が走ったかに関係なく**呼ばれる。
+ * よって handle() で request attribute に目印を立て、terminate() は目印があるときだけ書く。
+ *
+ * 出力 (coverage/build_executed.py が consume する契約・JSONL 追記、run×shard ごとに 1 ファイル):
+ *   storage/bughunt-executed/{run}-{shard}.jsonl に 1 行 1 要求:
+ *     {"run_id":"…","shard":"0","route_name":"projects.store","method":"POST",
+ *      "path":"/organizations/1/projects","status":"ok","http_status":302}
+ *   書き込みに失敗したら同ディレクトリの {run}-{shard}.error へ理由を追記する
+ *   (生成器はこのマーカーを見つけたら終了コード 3 で落ちる = 部分欠測を静かに通さない)。
+ */
+final class BughuntExecutedRouteMiddleware
+{
+    /** handle() 到達の目印 (request-scoped。middleware インスタンスに状態を持たせない)。 */
+    public const REACHED_ATTRIBUTE = 'bughunt.executed.reached';
+
+    /** run / shard に許す書式 (出力パスの組み立てに入るため狭くする)。 */
+    private const TOKEN_PATTERN = '/\A[A-Za-z0-9_.-]+\z/';
+
+    /**
+     * 二重の門。どちらか偽なら handle / terminate は完全 no-op。
+     * (1) config('bughunt.executed.enabled') — env 既定 false
+     * (2) production でない — 誤設定時の構造的な防壁
+     * 加えて run / shard の書式検査を通らなければ無効とする。
+     */
+    public static function enabled(): bool
+    {
+        if (config('bughunt.executed.enabled') !== true) {
+            return false;
+        }
+        if (app()->isProduction()) {
+            return false;
+        }
+
+        return self::token('run') !== null && self::token('shard') !== null;
+    }
+
+    /**
+     * @param  Closure(Request): Response  $next
+     */
+    public function handle(Request $request, Closure $next): Response
+    {
+        if (self::enabled()) {
+            // ここに到達した = 上流の遮断 middleware をすべて通過した。
+            $request->attributes->set(self::REACHED_ATTRIBUTE, true);
+        }
+
+        return $next($request);
+    }
+
+    public function terminate(Request $request, Response $response): void
+    {
+        if (! self::enabled()) {
+            return;
+        }
+        if ($request->attributes->get(self::REACHED_ATTRIBUTE) !== true) {
+            return; // 上流で短絡した = この route には到達していない
+        }
+
+        $run = self::token('run');
+        $shard = self::token('shard');
+        if ($run === null || $shard === null) {
+            return; // enabled() で検査済みだが、型を絞るために再確認する
+        }
+
+        try {
+            $line = self::buildLine($run, $shard, $request, $response);
+            if ($line === null) {
+                self::markFailure($run, $shard, 'json_encode failed');
+
+                return;
+            }
+            self::append(self::outputPath($run, $shard), $line);
+        } catch (Throwable $e) {
+            // 観測器は機能を壊さない。応答は既に送出済み。
+            Log::warning('bughunt executed-route capture failed', ['message' => $e->getMessage()]);
+            self::markFailure($run, $shard, $e->getMessage());
+        }
+    }
+
+    /**
+     * 1 要求を JSONL の 1 行 (末尾改行込み) に組み立てる。json_encode 失敗時は null。
+     */
+    private static function buildLine(string $run, string $shard, Request $request, Response $response): ?string
+    {
+        $route = $request->route();
+        $name = $route instanceof Route ? $route->getName() : null;
+
+        /** @var array{run_id: string, shard: string, route_name: string|null, method: string, path: string, status: string, http_status: int} $row */
+        $row = [
+            'run_id' => $run,
+            'shard' => $shard,
+            'route_name' => is_string($name) && $name !== '' ? $name : null,
+            'method' => $request->getMethod(),           // 常に大文字の HTTP method
+            'path' => $request->getPathInfo(),
+            'status' => self::classify($request, $response),
+            'http_status' => $response->getStatusCode(),
+        ];
+
+        $json = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
+
+        return $json === false ? null : $json."\n";
+    }
+
+    /**
+     * ok / blocked の写像。判定不能は過小申告 (blocked) 側へ倒す。
+     *
+     *   2xx                              → ok
+     *   3xx かつ session に errors がある → blocked (FormRequest 不合格。middleware より後に起きる)
+     *   3xx (その他)                     → ok (controller が返した正常なリダイレクト)
+     *   それ以外                          → blocked
+     *
+     * ここに到達している時点で認証・課金ゲート等はすべて通過済みなので、
+     * 「ゲートに遮断された 302」と「正常な PRG」を状態コードで見分ける必要は無い。
+     *
+     * `errors` は「今回のリクエストで flash されたもの」だけが残る:
+     * Store::save() が ageFlashData() を呼び、前リクエストの flash はここで忘れられる。
+     * 保存は StartSession::handleStatefulRequest() が下流の応答を受け取った後、
+     * **応答の巻き戻り中**に saveSession() を呼んで行う。記録器の terminate() は
+     * その後 (Kernel の terminate 処理) に走るので、読む時点では世代更新済みである。
+     * **framework の内部実装に依存する**ので、
+     * 「直前に不合格 → 次のリクエストの成功 302 が ok」を Feature テストで固定する。
+     */
+    private static function classify(Request $request, Response $response): string
+    {
+        $status = $response->getStatusCode();
+        if ($status >= 200 && $status < 300) {
+            return 'ok';
+        }
+        if ($status >= 300 && $status < 400) {
+            if ($request->hasSession() && $request->session()->has('errors')) {
+                return 'blocked';
+            }
+
+            return 'ok';
+        }
+
+        return 'blocked';
+    }
+
+    /** 出力先。env でパスを受け取らない (任意の場所へ書ける口を作らない)。 */
+    public static function outputPath(string $run, string $shard): string
+    {
+        return storage_path('bughunt-executed'.DIRECTORY_SEPARATOR.$run.'-'.$shard.'.jsonl');
+    }
+
+    public static function failurePath(string $run, string $shard): string
+    {
+        return storage_path('bughunt-executed'.DIRECTORY_SEPARATOR.$run.'-'.$shard.'.error');
+    }
+
+    /** config から run / shard を取り、書式検査を通ったものだけ返す。 */
+    private static function token(string $key): ?string
+    {
+        $value = config('bughunt.executed.'.$key);
+        if (! is_string($value)) {
+            return null;
+        }
+        $value = trim($value);
+
+        return preg_match(self::TOKEN_PATTERN, $value) === 1 ? $value : null;
+    }
+
+    /**
+     * 1 行を 1 回の追記で書く (並行要求で行が混線しないよう LOCK_EX)。
+     * 失敗したら失敗マーカーを残す。
+     */
+    private static function append(string $path, string $line): void
+    {
+        $dir = dirname($path);
+        if (! is_dir($dir)) {
+            @mkdir($dir, 0o775, true);
+        }
+        if (file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
+            throw new RuntimeException('failed to append '.$path);
+        }
+    }
+
+    /** best-effort。ここが書けない障害は検出できない (詳細設計の「保証しないもの」)。 */
+    private static function markFailure(string $run, string $shard, string $reason): void
+    {
+        $path = self::failurePath($run, $shard);
+        $dir = dirname($path);
+        if (! is_dir($dir)) {
+            // append() より前に落ちた場合 (buildLine 失敗等) はディレクトリが無いことがある。
+            @mkdir($dir, 0o775, true);
+        }
+        @file_put_contents($path, $reason."\n", FILE_APPEND | LOCK_EX);
+    }
+}
diff --git a/bootstrap/app.php b/bootstrap/app.php
index 5cca806..aa062ed 100644
--- a/bootstrap/app.php
+++ b/bootstrap/app.php
@@ -6,6 +6,7 @@
 use App\Exceptions\InertiaExceptionRenderer;
 use App\Http\Middleware\BlockTwoFactorDisableForEnforcedOrganizations;
 use App\Http\Middleware\BughuntCoverageMiddleware;
+use App\Http\Middleware\BughuntExecutedRouteMiddleware;
 use App\Http\Middleware\EnforceMcpTransport;
 use App\Http\Middleware\EnsureAccountNotPendingDeletion;
 use App\Http\Middleware\EnsureEmailIsVerifiedOrBack;
@@ -14,6 +15,7 @@
 use App\Http\Middleware\EnsureProjectBelongsToRouteOrganization;
 use App\Http\Middleware\HandleInertiaRequests;
 use App\Http\Middleware\IdempotentRequest;
+use App\Http\Middleware\LocalOnly;
 use App\Http\Middleware\McpConsentOrganizationBinder;
 use App\Http\Middleware\NoStoreCacheHeadersForAuthenticatedPages;
 use App\Http\Middleware\NoStoreResponse;
@@ -34,6 +36,7 @@
 use App\Support\Http\NotFoundMessage;
 use Illuminate\Auth\AuthenticationException;
 use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
+use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
 use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
 use Illuminate\Foundation\Application;
 use Illuminate\Foundation\Configuration\Exceptions;
@@ -41,8 +44,10 @@
 use Illuminate\Http\JsonResponse;
 use Illuminate\Http\Request;
 use Illuminate\Routing\Middleware\SubstituteBindings;
+use Illuminate\Routing\Middleware\ValidateSignature;
 use Inertia\Inertia;
 use Inertia\Middleware\EncryptHistory;
+use Livewire\Mechanisms\HandleRequests\RequireLivewireHeaders;
 use Symfony\Component\HttpFoundation\Response;
 use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
 
@@ -145,6 +150,10 @@
             // 公開ページの履歴も暗号化されるが PII は無く、コストはログアウト前エントリの
             // 再取得と remember/scroll 喪失に限られる。
             EncryptHistory::class,
+            // bug-hunt: 実行済み route の記録。**列の最後**に置き、priority list でも鎖の最後に固定する
+            // (= ここへ到達したことが「遮断 middleware をすべて通過した」証拠になる)。
+            // 既定 no-op (config('bughunt.executed.enabled') 既定 false + production 除外)。
+            BughuntExecutedRouteMiddleware::class,
         ]);
 
         // パスワード変更/リセット時に他デバイスのセッション・remember-me を確実に失効させるため、
@@ -264,6 +273,10 @@
             // (AGENTS.md セキュリティ不変条件 10)。課金ゲートの直後に置き、未契約組織の
             // ユーザーは 課金ゲート → onboarding → 凍結 → /settings の 2 hop で取消 UI に着く。
             [RequireActiveSubscription::class, EnsureAccountNotPendingDeletion::class],
+            // bug-hunt の記録器は鎖の最後 (遮断 middleware より内側) に固定する。
+            // 「短絡しうる middleware はすべて記録器より前」は
+            // BughuntExecutedRouteOrderingTest が deny-by-default で強制する。
+            [EnsureAccountNotPendingDeletion::class, BughuntExecutedRouteMiddleware::class],
         ] as [$after, $append]) {
             $middleware->appendToPriorityList($after, $append);
         }
@@ -272,6 +285,39 @@
             ResolveApiActor::class,
         );
 
+        /*
+         | bug-hunt の記録器より前で走ることを確定させる「route 個別の短絡 middleware」。
+         |
+         | web グループの middleware は route 個別 middleware より**前**に並ぶため、
+         | priority list に載っていない route 個別の短絡 (recent-auth / signed / guest 等) は
+         | 既定では記録器より**後ろ**で走る。その状態で 302 を返されると、session に errors が
+         | 無いため記録器が `ok` と誤記録する = 到達していない操作を実行済みに数える。
+         |
+         | ここでは記録器の直前へ差し込む (= 既存の実行順は変えず、記録器だけを最後に保つ)。
+         | 対象は BughuntExecutedRouteOrderingTest が赤で示した実測ベースの一覧であり、
+         | 新しい短絡 middleware を足すと同テストが未登録として落ちる (deny-by-default)。
+         |
+         | ⚠ appendToPriorityList は `[$append => $after]` の連想配列で持つため、
+         |   同じ middleware を複数の anchor で append できない (後勝ちで消える)。
+         |   よって「多数の短絡 → 1 つの記録器」は prepend 側 (`[$prepend => $before]`) で宣言する。
+         */
+        foreach ([
+            RedirectIfAuthenticated::class,     // guest
+            ValidateSignature::class,           // signed
+            EnsureEmailIsVerifiedOrBack::class, // verified.or-back
+            RequireRecentAuth::class,           // recent-auth
+            RequireRecentAuthOnEmailChange::class, // recent-auth.on-email-change
+            EnsureLoginMethodRemains::class,    // ensure-login-method
+            LocalOnly::class,                   // local 専用デバッグ route
+            VerifySnsSignature::class,          // sns.signature
+            RequireLivewireHeaders::class,      // vendor (Livewire) の 404 短絡
+        ] as $shortCircuit) {
+            $middleware->prependToPriorityList(
+                BughuntExecutedRouteMiddleware::class,
+                $shortCircuit,
+            );
+        }
+
         // Stripe webhook は署名検証 (Cashier middleware)、SES/SNS webhook は
         // SNS 署名検証 (VerifySnsSignature) で保護されるため CSRF 対象外
         $middleware->validateCsrfTokens(except: [
diff --git a/config/bughunt.php b/config/bughunt.php
index a669cec..661cba7 100644
--- a/config/bughunt.php
+++ b/config/bughunt.php
@@ -16,6 +16,12 @@
 |   本環境/CI/本番では常に no-op になる。run/shard は出力ファイル名に使う。
 |   scripts/bug-hunt-shard.sh provision --coverage が BUGHUNT_PCOV* を serve に渡す。
 |
+| - executed.*: 実行済み route の記録 (操作到達カバレッジの主入力) の env 入口。
+|   BughuntExecutedRouteMiddleware が参照する。enabled は env 既定 false で、
+|   production では config が真でも構造的に no-op になる。
+|   run/shard は出力ファイル名に使うため、middleware 側で書式検査を通す。
+|   scripts/bug-hunt-shard.sh provision が BUGHUNT_EXECUTED* を serve に渡す。
+|
 */
 
 return [
@@ -24,4 +30,10 @@
         'run' => env('BUGHUNT_PCOV_RUN'),
         'shard' => env('BUGHUNT_PCOV_SHARD'),
     ],
+
+    'executed' => [
+        'enabled' => (bool) env('BUGHUNT_EXECUTED', false),
+        'run' => env('BUGHUNT_EXECUTED_RUN'),
+        'shard' => env('BUGHUNT_EXECUTED_SHARD'),
+    ],
 ];
diff --git a/scripts/bug-hunt-shard.sh b/scripts/bug-hunt-shard.sh
index 5826e21..26d0dfd 100755
--- a/scripts/bug-hunt-shard.sh
+++ b/scripts/bug-hunt-shard.sh
@@ -84,12 +84,16 @@ if [[ -n "${BUGHUNT_SANDBOX:-}" ]]; then
     LOCK_FILE="${BUGHUNT_SANDBOX}/bug-hunt.lock"
     ENV_FILE="${BUGHUNT_SANDBOX}/.env.bughunt.local"
     MAIN_ENV_FILE="${BUGHUNT_SANDBOX}/.env"     # 親リポジトリ .env (実キー ANTHROPIC_API_KEY 由来)
+    EXECUTED_BASE="${BUGHUNT_SANDBOX}/storage/bughunt-executed"
 else
     RUN_BASE="devnotes"
     TMP_BASE="tmp/bug-hunt"
     LOCK_FILE="${WORKSPACE}/.claude/bug-hunt.lock"
     ENV_FILE=".env.bughunt.local"
     MAIN_ENV_FILE=".env"                        # 親リポジトリ .env (実キー ANTHROPIC_API_KEY 由来)
+    # 実行済み route の記録の置き場。アプリ側 (BughuntExecutedRouteMiddleware) の
+    # storage_path('bughunt-executed') と同じ場所を指す (相対パス = worktree ルート起点)。
+    EXECUTED_BASE="storage/bughunt-executed"
 fi
 
 is_dryrun() { [[ -n "${BUGHUNT_SELFTEST_DRYRUN:-}" ]]; }
@@ -500,6 +504,61 @@ cmd_verify_run() {
     return "${rc}"
 }
 
+# --- 実行済み route の記録 (操作到達カバレッジの主入力) ------------------------
+#
+# 記録そのものは BughuntExecutedRouteMiddleware (アプリ側) が serve のプロセス内で行う。
+# ここでの責務は「前回の記録を消す → 配線が生きていることを確認する → 探索開始前に空にする」
+# の 3 段だけである。
+
+executed_capture_path() { echo "${EXECUTED_BASE}/$1-$2.jsonl"; }
+executed_capture_error_path() { echo "${EXECUTED_BASE}/$1-$2.error"; }
+
+# (1) serve 起動より**前**に古い記録を消す。
+#     再 provision で前回の行が残っていると、それを今回の同期点と誤認して
+#     「待たずに空にする → 今回の /login が遅れて追記される」競合が再発するため。
+prepare_executed_capture() {
+    local run_id=$1 shard=$2
+    mkdir -p "${EXECUTED_BASE}"
+    rm -f "$(executed_capture_path "${run_id}" "${shard}")" \
+          "$(executed_capture_error_path "${run_id}" "${shard}")"
+}
+
+# (2) 記録の配線が生きていることを確認する (実経路のみ)。
+#
+# 疎通確認 (curl {url}/login) は記録器を通る要求なので、**その行が現れることが同期点**になる。
+# prepare で空にしてあるので、現れた行は必ず今回のものである。
+# (サイズの静止では駄目である — 0 のまま 2 回観測してから遅れて追記される順序が実際に成立し、
+#  消したはずの /login が残る。静止は「これから来ない」ことを証明しない。)
+#
+# 上限内に行が現れない = 記録器が配線されていない / 門が閉じている。**走行前に落とす**
+# (黙って何も記録しないまま走ると、走行後に全件未実行という嘘の一覧が出るため)。
+assert_executed_capture_wired() {
+    local run_id=$1 shard=$2 i
+    local path err
+    path="$(executed_capture_path "${run_id}" "${shard}")"
+    err="$(executed_capture_error_path "${run_id}" "${shard}")"
+    for i in $(seq 1 25); do            # 0.2s × 25 = 上限 5s
+        [[ -f "${err}" ]] && die 1 "shard-${shard} 実行済み route の記録が失敗している (${err} を参照)"
+        [[ -s "${path}" ]] && return 0
+        sleep 0.2
+    done
+    die 1 "shard-${shard} 疎通確認の要求が ${path} に記録されない = 記録器が配線されていない (BUGHUNT_EXECUTED の注入と bootstrap/app.php の登録を確認すること)"
+}
+
+# (3) 配線を確認したうえで記録を空にし、探索エージェントへ引き渡す。
+#     provision の疎通確認が記録に混ざると login が毎回「実行済み」になるため。
+#
+# ⚠ dryrun では prepare と finalize だけを呼ぶ (serve が居ないので待てない。
+#    storage への副作用はこの初期化だけで、配線を自己テストから検査するために残す)。
+finalize_executed_capture() {
+    local run_id=$1 shard=$2 path
+    path="$(executed_capture_path "${run_id}" "${shard}")"
+    mkdir -p "${EXECUTED_BASE}"
+    : > "${path}"
+    rm -f "$(executed_capture_error_path "${run_id}" "${shard}")"
+    manifest_update "${run_id}" "${shard}" "executed_capture=\"${path}\""
+}
+
 # --- shard 専用 wrapper 生成 (子セッションの唯一の Bash 許可対象) --------------
 
 generate_wrapper() {
@@ -1024,6 +1083,9 @@ cmd_provision() {
             "db=\"${db}\"" "port=${port}" "app_url=\"${url}\"" \
             "log_offset=0" "serve_pid=0" "stories=\"(dryrun)\"" \
             "coverage=$( [[ -n "${COVERAGE:-}" ]] && echo true || echo false )"
+        # 実行済み route の記録は serve が居ないので待てない。初期化だけ実経路と同じ順で行う。
+        prepare_executed_capture "${run_id}" "${shard}"
+        finalize_executed_capture "${run_id}" "${shard}"
         generate_wrapper "${shard}" "${run_id}"
         return 0
     fi
@@ -1146,6 +1208,16 @@ PY
         fi
     fi
 
+    # (e-exec) 実行済み route の記録 (操作到達カバレッジの主入力)。**既定 ON = 毎回採る**。
+    #   BughuntCoverageMiddleware の pcov 系と違い拡張に依存しないため、条件分岐を持たない。
+    #   古い記録は serve 起動より前に消す (後述の同期点を今回の行だけで判定するため)。
+    local -a executed_env=(
+        "BUGHUNT_EXECUTED=1"
+        "BUGHUNT_EXECUTED_RUN=${run_id}"
+        "BUGHUNT_EXECUTED_SHARD=${shard}"
+    )
+    prepare_executed_capture "${run_id}" "${shard}"
+
     # (e) serve 起動 + ヘルスチェック。--no-reload 必須 (ServeCommand が --env 時に
     #     passthrough 外の env を php -S 子から破棄する)。coverage_env は同じ env -i 行で明示展開する。
     # 秘密 (LLM_KEY_ENV) を展開するプロセス起動を xtrace ガードで挟む (-x 有効時も値を trace に出さない)。
@@ -1156,6 +1228,7 @@ PY
         DB_HOST="$(env_file_required DB_HOST)" DB_PORT="$(env_file_required DB_PORT)" \
         DB_DATABASE="${db}" DB_USERNAME=bughunt DB_PASSWORD="$(env_file_get DB_PASSWORD)" \
         APP_URL="${url}" \
+        ${executed_env[@]+"${executed_env[@]}"} \
         ${coverage_env[@]+"${coverage_env[@]}"} \
         ${MODE_ENV[@]+"${MODE_ENV[@]}"} ${LLM_KEY_ENV[@]+"${LLM_KEY_ENV[@]}"} \
         nohup php artisan serve --env=bughunt.local --port="${port}" --no-reload \
@@ -1175,6 +1248,11 @@ PY
         die 1 "shard-${shard} serve (:${port}) が 30s で起動しない (last=${code}、${TMP_BASE}/serve-${shard}.log 参照)"
     fi
 
+    # (e-exec2) 疎通確認の要求が実際に記録されたことを同期点にして配線を確認し、
+    #   その 1 行を消してから探索エージェントへ引き渡す (login が毎回「実行済み」になるのを防ぐ)。
+    assert_executed_capture_wired "${run_id}" "${shard}"
+    finalize_executed_capture "${run_id}" "${shard}"
+
     # (e2) 専用 queue connection worker 起動 (F-01 対策。BUGHUNT_WORKER_CONNECTIONS 参照)
     start_shard_workers "${shard}" "${db}" "${url}"
 
@@ -1451,6 +1529,7 @@ cmd_self_test() {
     TMP_BASE="${sandbox}/tmp/bug-hunt"
     LOCK_FILE="${sandbox}/bug-hunt.lock"
     ENV_FILE="${sandbox}/.env.bughunt.local"
+    EXECUTED_BASE="${sandbox}/storage/bughunt-executed"
     # self-test は環境非依存であるべき (外部 env の BUGHUNT_DB_PREFIX に影響されない)。
     BUGHUNT_DB_PREFIX=bug_hunt
     SHARD_DB_RE="^${BUGHUNT_DB_PREFIX}(_[1-${BUGHUNT_SHARD_CAP}])?$"
@@ -1924,6 +2003,80 @@ CURLEOF
 
     t_ok "asset freshness guard (fingerprint/chunk/cycle/dangling/hot/writeback + assets-check/keepdb-check + worker liveness)"
 
+    echo "[ex] 実行済み route の記録: 初期化 / 配線確認の同期点 / 負の対照 / serve への注入"
+    # 出力先は sandbox (EXECUTED_BASE) を向いているので実資源 (worktree の storage/) を汚さない。
+
+    # (ex1) prepare は古い記録と失敗マーカーを消す (前回の行を今回の同期点と誤認しない)。
+    mkdir -p "${EXECUTED_BASE}"
+    echo '{"old":1}' > "$(executed_capture_path EXRUN 0)"
+    echo 'old failure' > "$(executed_capture_error_path EXRUN 0)"
+    prepare_executed_capture EXRUN 0
+    [[ ! -e "$(executed_capture_path EXRUN 0)" ]] \
+        || t_fail "[ex1] prepare が古い記録を消さない"
+    [[ ! -e "$(executed_capture_error_path EXRUN 0)" ]] \
+        || t_fail "[ex1] prepare が失敗マーカーを消さない"
+
+    # (ex2) assert_executed_capture_wired は**行の出現を待つ** (待ち時間の値は検査しない)。
+    #       prepare 済み = 不在なので、背景から遅れて来る行を実際に待つことになる。
+    ( sleep 0.5; echo '{"route_name":"login"}' >> "$(executed_capture_path EXRUN 0)" ) &
+    local ex_writer=$!
+    rc=0; ( assert_executed_capture_wired EXRUN 0 ) >/dev/null 2>&1 || rc=$?
+    wait "${ex_writer}" 2>/dev/null || true
+    [[ "${rc}" == 0 ]] || t_fail "[ex2] 遅延して現れた記録行を待たずに exit ${rc} で落ちた"
+
+    # (ex3) 負の対照: 行が 1 件も現れないなら非 0 で落ちる (走行前に配線不成立を検出する)。
+    prepare_executed_capture EXRUN 1
+    rc=0; ( assert_executed_capture_wired EXRUN 1 ) >/dev/null 2>&1 || rc=$?
+    [[ "${rc}" != 0 ]] || t_fail "[ex3] 記録が 1 行も無いのに配線確認が通過した (fail-open)"
+
+    # (ex4) 負の対照: 失敗マーカーがあれば非 0 で落ちる。
+    prepare_executed_capture EXRUN 2
+    echo 'disk full' > "$(executed_capture_error_path EXRUN 2)"
+    echo '{"route_name":"login"}' > "$(executed_capture_path EXRUN 2)"
+    rc=0; ( assert_executed_capture_wired EXRUN 2 ) >/dev/null 2>&1 || rc=$?
+    [[ "${rc}" != 0 ]] || t_fail "[ex4] 失敗マーカーがあるのに配線確認が通過した"
+
+    # (ex5) finalize は記録を空にし、失敗マーカーを消し、manifest に出力先を残す。
+    finalize_executed_capture 20990501-000000 0
+    [[ -f "$(executed_capture_path 20990501-000000 0)" ]] \
+        || t_fail "[ex5] finalize が記録ファイルを作らない"
+    [[ ! -s "$(executed_capture_path 20990501-000000 0)" ]] \
+        || t_fail "[ex5] finalize 後の記録ファイルが空でない"
+    [[ "$(manifest_get 20990501-000000 0 executed_capture)" == "$(executed_capture_path 20990501-000000 0)" ]] \
+        || t_fail "[ex5] manifest に executed_capture が記録されない"
+
+    # (ex6) dryrun provision は記録ファイルを空で用意し、manifest に出力先を残す
+    #       (serve が居ないので待たない = prepare と finalize だけを呼ぶ)。
+    export BUGHUNT_SELFTEST_DRYRUN=1
+    mkdir -p "${EXECUTED_BASE}"
+    echo 'stale failure' > "$(executed_capture_error_path 20990502-000000 0)"
+    rc=0; ("${SCRIPT_PATH}" provision --shard 0 --run-id 20990502-000000) >/dev/null 2>&1 || rc=$?
+    unset BUGHUNT_SELFTEST_DRYRUN
+    [[ "${rc}" == 0 ]] || t_fail "[ex6] dryrun provision が exit ${rc} (expected 0)"
+    [[ -f "$(executed_capture_path 20990502-000000 0)" && ! -s "$(executed_capture_path 20990502-000000 0)" ]] \
+        || t_fail "[ex6] dryrun provision 後に記録ファイルが空で存在しない"
+    [[ ! -e "$(executed_capture_error_path 20990502-000000 0)" ]] \
+        || t_fail "[ex6] 再 provision で古い失敗マーカーが持ち越された"
+    [[ "$(manifest_get 20990502-000000 0 executed_capture)" == "$(executed_capture_path 20990502-000000 0)" ]] \
+        || t_fail "[ex6] dryrun provision が manifest に executed_capture を残さない"
+
+    # (ex7) serve への env 注入は本文走査で見る (self-test は serve を起動しないため)。
+    #       **弱い検査**である (行が存在することしか見ない) が、配線の消失は検出できる。
+    local ex_prov_def
+    ex_prov_def="$(declare -f cmd_provision)"
+    echo "${ex_prov_def}" | grep -q 'BUGHUNT_EXECUTED=1' \
+        || t_fail "[ex7] cmd_provision に BUGHUNT_EXECUTED=1 の注入行が無い"
+    echo "${ex_prov_def}" | grep -q 'executed_env\[@\]' \
+        || t_fail "[ex7] serve 起動行で executed_env が展開されていない"
+    echo "${ex_prov_def}" | grep -q 'assert_executed_capture_wired' \
+        || t_fail "[ex7] cmd_provision に配線確認が無い"
+    local ex_wire_ln ex_worker_ln
+    ex_wire_ln="$(echo "${ex_prov_def}" | grep -n 'assert_executed_capture_wired' | head -1 | cut -d: -f1)"
+    ex_worker_ln="$(echo "${ex_prov_def}" | grep -n 'start_shard_workers' | head -1 | cut -d: -f1)"
+    [[ -n "${ex_wire_ln}" && -n "${ex_worker_ln}" && "${ex_wire_ln}" -lt "${ex_worker_ln}" ]] \
+        || t_fail "[ex7] 配線確認が worker 起動より後 (走行前に落とせない)"
+    t_ok "実行済み route の記録 (初期化 / 同期点 / 負の対照 2 種 / dryrun / serve 注入)"
+
     echo "[x] --coverage: provision/provision-all で受理 + フラグ解釈 + 既定不変 + サブコマンド制限"
     export BUGHUNT_SELFTEST_DRYRUN=1
     rc=0; ("${SCRIPT_PATH}" provision --shard 0 --run-id 20990201-000000 --coverage) >/dev/null 2>&1 || rc=$?
diff --git a/tests/Architecture/BughuntCoverageToolSelfTest.php b/tests/Architecture/BughuntCoverageToolSelfTest.php
new file mode 100644
index 0000000..6c1e2a3
--- /dev/null
+++ b/tests/Architecture/BughuntCoverageToolSelfTest.php
@@ -0,0 +1,64 @@
+<?php
+
+declare(strict_types=1);
+
+use Symfony\Component\Process\Process;
+
+/*
+ * Architecture invariant: bug-hunt のカバレッジ道具 (Python) の自己テストを
+ * `composer test` の下で実走させる。
+ *
+ * 対象は 3 モジュール:
+ *   - test_correlate      … 照合器の fail-closed 契約 (主入力が揃わない走行を成功にしない)
+ *   - test_build_executed … 実行済み route の記録の集約器 (同上)
+ *   - test_naming_no_stale … 旧 fail-open 文言・旧語彙の再混入検知
+ *
+ * ここに結線しないと「不変条件はテストへの登録まで含めて実装済み」を満たさない
+ * (禁止事項 1)。禁止語が戻っても、照合器が fail-open へ戻っても、緑のままになるため。
+ * `test_merge_pcov` はコード到達カバレッジ (別 feature) の担当なので本目録には入れない。
+ *
+ * 先例は BugHuntInventoryCheckInvariantTest: python3 の不在は **skip ではなく fail** で
+ * 顕在化させる (環境不備を skip で隠すと「未検証のまま合格」になる)。
+ */
+
+/** カバレッジ道具の置き場 (作業ディレクトリ)。 */
+function bctCoverageDir(): string
+{
+    return base_path('.claude/skills/app-bug-hunt/coverage');
+}
+
+/**
+ * coverage ディレクトリで `python3 -m unittest <modules...>` を実走し [exitCode, output] を返す。
+ *
+ * @param  list<string>  $modules
+ * @return array{0: int|null, 1: string}
+ */
+function bctRunUnittest(array $modules): array
+{
+    $process = new Process(['python3', '-m', 'unittest', ...$modules], bctCoverageDir());
+    $process->setTimeout(120);
+    $process->run();
+
+    return [$process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
+}
+
+test('python3 が PATH にあること (環境不備を skip で隠さない)', function (): void {
+    expect((new Process(['which', 'python3']))->run())->toBe(
+        0,
+        'python3 が PATH に無い。bug-hunt のカバレッジ道具は python3 必須 (stdlib のみ)。'
+    );
+});
+
+test('カバレッジ道具の Python 自己テスト 3 本が composer test の下で通ること', function (): void {
+    expect(is_dir(bctCoverageDir()))->toBeTrue('coverage ディレクトリが見つからない: '.bctCoverageDir());
+
+    [$code, $out] = bctRunUnittest(['test_correlate', 'test_build_executed', 'test_naming_no_stale']);
+
+    expect($code)->toBe(0, "bug-hunt カバレッジ道具の自己テストが失敗しました:\n".$out);
+});
+
+test('負の対照: 存在しないモジュール名を渡すと非 0 になること (空振り gate を作らない)', function (): void {
+    [$code] = bctRunUnittest(['test_no_such_module_exists']);
+
+    expect($code)->not->toBe(0, '存在しないモジュールでも 0 が返る = 実走していない疑い');
+});
diff --git a/tests/Architecture/BughuntExecutedRouteOrderingTest.php b/tests/Architecture/BughuntExecutedRouteOrderingTest.php
new file mode 100644
index 0000000..ecc8138
--- /dev/null
+++ b/tests/Architecture/BughuntExecutedRouteOrderingTest.php
@@ -0,0 +1,132 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Http\Middleware\BughuntExecutedRouteMiddleware;
+use App\Http\Middleware\EnsureAccountNotPendingDeletion;
+use App\Http\Middleware\EnsureProjectBelongsToRouteOrganization;
+use App\Http\Middleware\RequireActiveSubscription;
+use Illuminate\Auth\Middleware\Authenticate;
+use Illuminate\Routing\Route as RoutingRoute;
+use Illuminate\Support\Facades\Route;
+use Tests\Support\Routing\MiddlewareShortCircuitInventory;
+use Tests\Support\Routing\NestedRouteDefenseInventory;
+
+/**
+ * 実行済み route の記録器 (BughuntExecutedRouteMiddleware) の位置に関する順序不変条件 (T164)。
+ *
+ * 記録器の出力は「その route まで実際に到達できた」ことの証拠として使う。したがって
+ * **短絡しうる middleware が記録器より後ろで走ると、遮断された要求まで実行済みに数える**
+ * (例: recent-auth の 302 は session に errors を残さないため ok と誤記録される)。
+ * これは本 TODO が消そうとしている偽陽性そのものなので、順序を機械的に固定する。
+ *
+ * 分類の正本は {@see MiddlewareShortCircuitInventory}。未分類クラスは
+ * **短絡しうる (true) 側の既定**で扱うため、分類漏れが偽陰性にならない。
+ *
+ * 違反したときの直し方: bootstrap/app.php で
+ * `appendToPriorityList($短絡middleware, BughuntExecutedRouteMiddleware::class)` を宣言する
+ * (priority list は「載っている middleware 同士の相対順序」しか強制しないため、
+ * 短絡側も priority list に載せる必要がある)。
+ */
+
+/**
+ * 解決後の middleware 列で「記録器より後ろに短絡しうる middleware がある」ものを列挙する。
+ *
+ * 記録器を含まない列 (api / Filament) は対象外として空を返す。
+ *
+ * @param  list<string>  $resolved
+ * @return list<string>
+ */
+function bughuntRecorderOrderViolations(array $resolved): array
+{
+    $recorderIndex = array_search(BughuntExecutedRouteMiddleware::class, $resolved, true);
+    if ($recorderIndex === false) {
+        return [];
+    }
+
+    $classification = MiddlewareShortCircuitInventory::classification();
+    $violations = [];
+    foreach ($resolved as $index => $middleware) {
+        if ($index < $recorderIndex) {
+            continue;
+        }
+        if (($classification[$middleware] ?? true) === true) {
+            $violations[] = $middleware;
+        }
+    }
+
+    return $violations;
+}
+
+test('主契約: 記録器が付いた全 route で、短絡しうる middleware は記録器より前で走る', function (): void {
+    $violations = [];
+    $checked = 0;
+
+    /** @var RoutingRoute $route */
+    foreach (Route::getRoutes() as $route) {
+        $resolved = NestedRouteDefenseInventory::resolvedMiddleware($route);
+        if (! in_array(BughuntExecutedRouteMiddleware::class, $resolved, true)) {
+            continue; // web グループ外 (api / Filament) は記録器を持たない
+        }
+        $checked++;
+
+        foreach (bughuntRecorderOrderViolations($resolved) as $middleware) {
+            // route 名は null になりうるので URI と method も出す (原因追跡のため)
+            $label = $route->getName() ?? '(無名)';
+            $violations[] = "{$label} [".implode('|', $route->methods())." /{$route->uri()}]: "
+                ."{$middleware} が記録器より後ろで走る";
+        }
+    }
+
+    $violations = array_values(array_unique($violations));
+    expect($violations)->toBe([],
+        '記録器より後ろで短絡すると、遮断された要求が「実行済み」と誤記録されます。'
+        .'bootstrap/app.php で appendToPriorityList($短絡middleware, BughuntExecutedRouteMiddleware::class) '
+        .'を宣言して記録器より前へ動かしてください。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+    // 配線消失の検出 (0 件なら記録器が web グループから外れている)
+    expect($checked)->toBeGreaterThan(0, '記録器が付いた route が 1 本も無い = web グループへの登録が消えている');
+});
+
+test('代表 route: 記録器は認証・テナント境界 404・課金ゲート・退会凍結より後ろにある', function (): void {
+    $routes = app('router')->getRoutes();
+    $routes->refreshNameLookups();
+    $route = $routes->getByName('projects.update');
+    expect($route)->not->toBeNull("route 'projects.update' が存在しない");
+
+    $resolved = NestedRouteDefenseInventory::resolvedMiddleware($route);
+    $recorderIndex = array_search(BughuntExecutedRouteMiddleware::class, $resolved, true);
+    expect($recorderIndex)->not->toBeFalse('記録器が projects.update の解決後 middleware 列に無い');
+
+    foreach ([
+        Authenticate::class,
+        EnsureProjectBelongsToRouteOrganization::class,
+        RequireActiveSubscription::class,
+        EnsureAccountNotPendingDeletion::class,
+    ] as $upstream) {
+        $index = array_search($upstream, $resolved, true);
+        expect($index)->not->toBeFalse("{$upstream} が projects.update の列に無い");
+        expect($index)->toBeLessThan($recorderIndex, "{$upstream} が記録器より後ろで走る");
+    }
+});
+
+test('負の対照: 短絡クラスが記録器より後ろにある合成の列を違反として検出する', function (): void {
+    $shortCircuiting = MiddlewareShortCircuitInventory::shortCircuiting();
+    expect($shortCircuiting)->not->toBe([], '短絡しうると分類された middleware が 1 つも無い');
+    $shortCircuit = $shortCircuiting[0];
+
+    // 記録器の後ろに短絡クラスを置いた合成の列 = 違反として検出されること
+    expect(bughuntRecorderOrderViolations([
+        BughuntExecutedRouteMiddleware::class,
+        $shortCircuit,
+    ]))->toBe([$shortCircuit]);
+
+    // 前に置いた列は違反にならないこと (常に真を返す判定式でないことの対照)
+    expect(bughuntRecorderOrderViolations([
+        $shortCircuit,
+        BughuntExecutedRouteMiddleware::class,
+    ]))->toBe([]);
+
+    // 記録器を含まない列は対象外 (api / Filament を巻き込まない)
+    expect(bughuntRecorderOrderViolations([$shortCircuit]))->toBe([]);
+});
diff --git a/tests/Architecture/RescueRouteGateInventoryTest.php b/tests/Architecture/RescueRouteGateInventoryTest.php
index 07ff915..760aefb 100644
--- a/tests/Architecture/RescueRouteGateInventoryTest.php
+++ b/tests/Architecture/RescueRouteGateInventoryTest.php
@@ -40,7 +40,7 @@
  */
 
 /** 母集団 `U` の件数 (exact。middleware の増減を必ずレビューに出す)。 */
-const RESCUE_GATE_POPULATION_COUNT = 9;
+const RESCUE_GATE_POPULATION_COUNT = 10;
 
 /**
  * 母集団に名指しで加える vendor 認証ゲート。
diff --git a/tests/Architecture/TenantBoundaryOrderingTest.php b/tests/Architecture/TenantBoundaryOrderingTest.php
index 49129d3..782e8c0 100644
--- a/tests/Architecture/TenantBoundaryOrderingTest.php
+++ b/tests/Architecture/TenantBoundaryOrderingTest.php
@@ -4,44 +4,33 @@
 
 use App\Enums\Security\NestedRouteDefenseMode;
 use App\Http\Middleware\BlockTwoFactorDisableForEnforcedOrganizations;
-use App\Http\Middleware\BughuntCoverageMiddleware;
-use App\Http\Middleware\EnforceMcpTransport;
+use App\Http\Middleware\BughuntExecutedRouteMiddleware;
 use App\Http\Middleware\EnsureAccountNotPendingDeletion;
-use App\Http\Middleware\EnsureEmailIsVerifiedOrBack;
-use App\Http\Middleware\EnsureLoginMethodRemains;
 use App\Http\Middleware\EnsureProjectBelongsToApiOrganization;
 use App\Http\Middleware\EnsureProjectBelongsToRouteOrganization;
 use App\Http\Middleware\HandleInertiaRequests;
 use App\Http\Middleware\IdempotentRequest;
-use App\Http\Middleware\LocalOnly;
-use App\Http\Middleware\McpConsentOrganizationBinder;
 use App\Http\Middleware\NoStoreCacheHeadersForAuthenticatedPages;
-use App\Http\Middleware\NoStoreResponse;
 use App\Http\Middleware\RequireActiveSubscription;
 use App\Http\Middleware\RequireApiKeyAbility;
-use App\Http\Middleware\RequireRecentAuth;
-use App\Http\Middleware\RequireRecentAuthOnEmailChange;
 use App\Http\Middleware\RequireTwoFactorForEnforcedOrganizations;
 use App\Http\Middleware\ResolveApiActor;
 use App\Http\Middleware\SecurityHeaders;
-use App\Http\Middleware\VerifyMcpOrigin;
-use App\Http\Middleware\VerifySnsSignature;
 use App\Http\Routing\RouteBindingTypes;
 use Illuminate\Auth\Middleware\Authenticate;
 use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
-use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
 use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
 use Illuminate\Cookie\Middleware\EncryptCookies;
 use Illuminate\Database\Eloquent\Model;
 use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
 use Illuminate\Routing\Middleware\SubstituteBindings;
 use Illuminate\Routing\Middleware\ThrottleRequests;
-use Illuminate\Routing\Middleware\ValidateSignature;
 use Illuminate\Routing\Router;
 use Illuminate\Session\Middleware\AuthenticateSession;
 use Illuminate\Session\Middleware\StartSession;
 use Illuminate\View\Middleware\ShareErrorsFromSession;
 use Inertia\Middleware\EncryptHistory;
+use Tests\Support\Routing\MiddlewareShortCircuitInventory;
 use Tests\Support\Routing\NestedRouteDefenseInventory;
 
 /**
@@ -71,59 +60,15 @@
 /**
  * 解決済み middleware クラス => 短絡しうるか (由来を問わず全件分類必須)。
  *
- * `true` = 3xx/4xx を返して $next を呼ばない分岐を持つ。
- * **既定は true 側に倒す** (疑わしきは短絡扱い)。`false` を宣言してよいのは
- * 「$next を必ず呼び、応答の加工しかしない」ことを実装で確認したときだけ。
- * 未登録クラスの既定も true 扱い (検査 2 / 3b は `?? true`) なので、
- * 分類漏れが偽陰性にはならない。
+ * 分類の正本は {@see MiddlewareShortCircuitInventory} へ移した
+ * (BughuntExecutedRouteOrderingTest も同じ表を読むため、同じ分類を 2 か所に持たない)。
+ * 本関数はその薄い委譲であり、以下の検査の意味は移設前と変わらない。
  *
  * @return array<class-string, bool>
  */
 function middlewareShortCircuitInventory(): array
 {
-    return [
-        // --- 短絡しうる ---
-        Authenticate::class => true,
-        RedirectIfAuthenticated::class => true,
-        EnsureEmailIsVerified::class => true,
-        ThrottleRequests::class => true,
-        ValidateSignature::class => true,
-        PreventRequestForgery::class => true,
-        AuthenticateSession::class => true,
-        // binding 失敗そのものが 404 (短絡の基準点)
-        SubstituteBindings::class => true,
-        // Inertia の asset version mismatch は 409 で短絡する
-        HandleInertiaRequests::class => true,
-        RequireActiveSubscription::class => true,
-        // 退会予約中の凍結。302 (web) / 409 (XHR) で短絡する
-        EnsureAccountNotPendingDeletion::class => true,
-        RequireTwoFactorForEnforcedOrganizations::class => true,
-        BlockTwoFactorDisableForEnforcedOrganizations::class => true,
-        RequireRecentAuth::class => true,
-        RequireRecentAuthOnEmailChange::class => true,
-        RequireApiKeyAbility::class => true,
-        ResolveApiActor::class => true,
-        IdempotentRequest::class => true,
-        EnsureProjectBelongsToRouteOrganization::class => true,
-        EnsureProjectBelongsToApiOrganization::class => true,
-        EnsureEmailIsVerifiedOrBack::class => true,
-        EnsureLoginMethodRemains::class => true,
-        LocalOnly::class => true,
-        McpConsentOrganizationBinder::class => true,
-        VerifyMcpOrigin::class => true,
-        EnforceMcpTransport::class => true,
-        VerifySnsSignature::class => true,
-        // --- 透過 (必ず $next を呼び、応答の加工のみ) ---
-        EncryptCookies::class => false,
-        AddQueuedCookiesToResponse::class => false,
-        StartSession::class => false,
-        ShareErrorsFromSession::class => false,
-        EncryptHistory::class => false,
-        SecurityHeaders::class => false,
-        NoStoreCacheHeadersForAuthenticatedPages::class => false,
-        NoStoreResponse::class => false,
-        BughuntCoverageMiddleware::class => false,
-    ];
+    return MiddlewareShortCircuitInventory::classification();
 }
 
 /**
@@ -450,6 +395,8 @@ function tenantBoundaryHasMode(string $routeName, NestedRouteDefenseMode $mode):
         EncryptHistory::class,
         EnsureEmailIsVerified::class,
     ];
+    // bug-hunt の実行済み route 記録器は web 鎖の**最後** (遮断 middleware より内側)。
+    $recorder = BughuntExecutedRouteMiddleware::class;
     $guard = EnsureProjectBelongsToRouteOrganization::class;
     $billing = RequireActiveSubscription::class;
     // 退会予約中の凍結は**課金ゲートの直後**。テナント境界 404 より必ず後 (302 短絡のため)。
@@ -470,11 +417,12 @@ function tenantBoundaryHasMode(string $routeName, NestedRouteDefenseMode $mode):
         'api.v1.projects.items.index' => $apiHead,
         // {project} を持たない route でも guard は列に載る (no-op。group 一括付与の許容)
         'api.v1.me' => $apiHead,
-        // web: テナント境界 404 が Inertia / 2FA / verified / 課金ゲートより前
-        'projects.update' => [...$webHead, $guard, ...$webAppend, $billing, $freeze],
-        'capture.manuals.show' => [...$webHead, $guard, ...$webAppend, $billing, $freeze],
+        // web: テナント境界 404 が Inertia / 2FA / verified / 課金ゲートより前。
+        // 記録器は列の最後 (= 到達が「遮断をすべて通過した」証拠になる)
+        'projects.update' => [...$webHead, $guard, ...$webAppend, $billing, $freeze, $recorder],
+        'capture.manuals.show' => [...$webHead, $guard, ...$webAppend, $billing, $freeze, $recorder],
         // guard を持たない web route の列は変化しない (priority 追加の副作用が無いことの pin)
-        'organizations.settings' => [...$webHead, ...$webAppend, $freeze],
+        'organizations.settings' => [...$webHead, ...$webAppend, $freeze, $recorder],
     ];
 
     $routes = app('router')->getRoutes();
diff --git a/tests/Feature/Bughunt/ExecutedRouteCaptureTest.php b/tests/Feature/Bughunt/ExecutedRouteCaptureTest.php
new file mode 100644
index 0000000..68d013c
--- /dev/null
+++ b/tests/Feature/Bughunt/ExecutedRouteCaptureTest.php
@@ -0,0 +1,251 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Http\Middleware\BughuntExecutedRouteMiddleware;
+use App\Models\User;
+use Illuminate\Support\Facades\Route;
+
+/*
+ * 実行済み route の記録器 (BughuntExecutedRouteMiddleware) の実挙動 (T164)。
+ *
+ * **実 HTTP 要求で検証する** (terminate() を直接呼ぶ形にしない)。それでは
+ * bootstrap/app.php の配線 (web グループ登録 + priority list の位置) を検証したことにならない。
+ *
+ * 出力先は storage_path() なので、テストごとに固有の run 名を使い afterEach で掃除する。
+ */
+
+const BUGHUNT_TEST_RUN = 'testrun-20260815';
+const BUGHUNT_TEST_SHARD = '0';
+
+/** 記録を有効化する (config の 3 キーが揃って初めて有効)。 */
+function bughuntEnableCapture(string $run = BUGHUNT_TEST_RUN, string $shard = BUGHUNT_TEST_SHARD): void
+{
+    config([
+        'bughunt.executed.enabled' => true,
+        'bughunt.executed.run' => $run,
+        'bughunt.executed.shard' => $shard,
+    ]);
+}
+
+/**
+ * 記録された行 (JSONL) を配列で読む。ファイルが無ければ空配列。
+ *
+ * @return list<array<string, mixed>>
+ */
+function bughuntCapturedRows(string $run = BUGHUNT_TEST_RUN, string $shard = BUGHUNT_TEST_SHARD): array
+{
+    $path = BughuntExecutedRouteMiddleware::outputPath($run, $shard);
+    if (! is_file($path)) {
+        return [];
+    }
+
+    $rows = [];
+    foreach (explode("\n", (string) file_get_contents($path)) as $line) {
+        if (trim($line) === '') {
+            continue;
+        }
+        $decoded = json_decode($line, true);
+        expect($decoded)->toBeArray("記録行が JSON として読めない: {$line}");
+        /** @var array<string, mixed> $decoded */
+        $rows[] = $decoded;
+    }
+
+    return $rows;
+}
+
+/** 記録ファイルと失敗マーカーを消す。 */
+function bughuntForgetCapture(string $run = BUGHUNT_TEST_RUN, string $shard = BUGHUNT_TEST_SHARD): void
+{
+    foreach ([
+        BughuntExecutedRouteMiddleware::outputPath($run, $shard),
+        BughuntExecutedRouteMiddleware::failurePath($run, $shard),
+    ] as $path) {
+        if (is_file($path)) {
+            unlink($path);
+        }
+    }
+}
+
+beforeEach(function (): void {
+    bughuntForgetCapture();
+    bughuntForgetCapture('other-run', '9');
+});
+
+afterEach(function (): void {
+    bughuntForgetCapture();
+    bughuntForgetCapture('other-run', '9');
+});
+
+test('既定 (config off) では 1 バイトも書かない', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)->get('/dashboard')->assertOk();
+
+    expect(is_file(BughuntExecutedRouteMiddleware::outputPath(BUGHUNT_TEST_RUN, BUGHUNT_TEST_SHARD)))
+        ->toBeFalse('config off なのに記録ファイルが作られた');
+});
+
+test('認証済みユーザーの 200 GET が route 名つきで ok として記録される', function (): void {
+    bughuntEnableCapture();
+    $user = User::factory()->create();
+
+    $this->actingAs($user)->get('/dashboard')->assertOk();
+
+    $rows = bughuntCapturedRows();
+    expect($rows)->toHaveCount(1);
+    expect($rows[0])->toMatchArray([
+        'run_id' => BUGHUNT_TEST_RUN,
+        'shard' => BUGHUNT_TEST_SHARD,
+        'route_name' => 'dashboard',
+        'method' => 'GET',
+        'path' => '/dashboard',
+        'status' => 'ok',
+        'http_status' => 200,
+    ]);
+});
+
+test('FormRequest 不合格の 302 は blocked として記録される', function (): void {
+    bughuntEnableCapture();
+
+    $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password'])
+        ->assertRedirect();
+
+    $rows = bughuntCapturedRows();
+    expect($rows)->toHaveCount(1);
+    expect($rows[0]['route_name'])->toBe('login.store');
+    expect($rows[0]['status'])->toBe('blocked');
+    expect($rows[0]['http_status'])->toBe(302);
+});
+
+test('未認証の変更系要求は 1 行も記録されない (auth が上流で短絡する)', function (): void {
+    bughuntEnableCapture();
+
+    // 遮断したのが auth であることを着地で固定する (別の理由で 302 になる空振りを防ぐ)
+    $this->post('/settings/password', [])->assertRedirect(route('login'));
+
+    expect(bughuntCapturedRows())->toBe([]);
+});
+
+test('課金ゲートに遮断された変更系要求は 1 行も記録されない', function (): void {
+    bughuntEnableCapture();
+    // 未契約組織 (free_plan_code NULL) = require-active-subscription が遮断する
+    [, $owner] = createOrganizationWithOwner('未契約組織', grandfatherFreePlan: false);
+
+    // owner は manageBilling を持つので checkout へ倒れる (遮断したのが課金ゲートである証拠)
+    $this->actingAs($owner)->post('/projects', ['name' => 'テスト'])
+        ->assertRedirect(route('onboarding.checkout'));
+
+    expect(bughuntCapturedRows())->toBe([]);
+});
+
+test('recent-auth に遮断された要求は 1 行も記録されない (route 個別の短絡 middleware)', function (): void {
+    bughuntEnableCapture();
+    $user = User::factory()->create();
+
+    // step-up 未充足のまま機微操作 route を叩く (RequireRecentAuth が 302 で短絡する)
+    $this->actingAs($user)->post('/settings/password', [
+        'current_password' => 'password',
+        'password' => 'new-password-1234',
+        'password_confirmation' => 'new-password-1234',
+    ])->assertRedirect(route('recent-auth.confirm'));
+
+    expect(bughuntCapturedRows())->toBe([]);
+});
+
+test('403 / 500 は blocked として記録される', function (): void {
+    bughuntEnableCapture();
+    Route::middleware('web')->get('/__bughunt-test/forbidden', fn () => abort(403))
+        ->name('bughunt-test.forbidden');
+    Route::middleware('web')->get('/__bughunt-test/boom', fn () => abort(500))
+        ->name('bughunt-test.boom');
+
+    $this->get('/__bughunt-test/forbidden')->assertForbidden();
+    $this->get('/__bughunt-test/boom')->assertStatus(500);
+
+    $rows = bughuntCapturedRows();
+    expect($rows)->toHaveCount(2);
+    expect($rows[0]['status'])->toBe('blocked');
+    expect($rows[0]['http_status'])->toBe(403);
+    expect($rows[1]['status'])->toBe('blocked');
+    expect($rows[1]['http_status'])->toBe(500);
+});
+
+test('成功した変更系の 302 (PRG) は ok として記録される', function (): void {
+    bughuntEnableCapture();
+    Route::middleware('web')->post('/__bughunt-test/prg', fn () => redirect('/'))
+        ->name('bughunt-test.prg');
+
+    $this->post('/__bughunt-test/prg')->assertRedirect('/');
+
+    $rows = bughuntCapturedRows();
+    expect($rows)->toHaveCount(1);
+    expect($rows[0]['status'])->toBe('ok');
+    expect($rows[0]['http_status'])->toBe(302);
+});
+
+test('直前のバリデーション不合格に引きずられず、次の成功 302 は ok になる', function (): void {
+    bughuntEnableCapture();
+    Route::middleware('web')->post('/__bughunt-test/prg', fn () => redirect('/'))
+        ->name('bughunt-test.prg');
+
+    // (1) 不合格 302 (errors を flash する)
+    $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password'])
+        ->assertRedirect();
+    // (2) 同じセッションで成功 302
+    $this->post('/__bughunt-test/prg')->assertRedirect('/');
+
+    $rows = bughuntCapturedRows();
+    expect($rows)->toHaveCount(2);
+    expect($rows[0]['status'])->toBe('blocked');
+    expect($rows[1]['status'])->toBe('ok');
+});
+
+test('名前の無い route への要求は route_name null で記録される', function (): void {
+    bughuntEnableCapture();
+    Route::middleware('web')->get('/__bughunt-test/anonymous', fn () => response('ok'));
+
+    $this->get('/__bughunt-test/anonymous')->assertOk();
+
+    $rows = bughuntCapturedRows();
+    expect($rows)->toHaveCount(1);
+    expect($rows[0]['route_name'])->toBeNull();
+    expect($rows[0]['status'])->toBe('ok');
+});
+
+test('production 環境では config が真でも書かない', function (): void {
+    bughuntEnableCapture();
+    $this->app->detectEnvironment(fn (): string => 'production');
+    $user = User::factory()->create();
+
+    $this->actingAs($user)->get('/dashboard')->assertOk();
+
+    expect(bughuntCapturedRows())->toBe([]);
+});
+
+test('run / shard が書式違反なら書かない', function (): void {
+    $user = User::factory()->create();
+
+    foreach ([['../etc', '0'], ['', '0'], [BUGHUNT_TEST_RUN, ''], [BUGHUNT_TEST_RUN, 'a/b']] as [$run, $shard]) {
+        bughuntEnableCapture($run, $shard);
+        $this->actingAs($user)->get('/dashboard')->assertOk();
+    }
+
+    // 書式検査を通らないので、正常な run/shard 名のファイルも当然できない
+    expect(bughuntCapturedRows())->toBe([]);
+    expect(glob(storage_path('bughunt-executed').DIRECTORY_SEPARATOR.'*etc*'))->toBe([]);
+});
+
+test('enabled() は 3 キーが揃ったときだけ真になる', function (): void {
+    config(['bughunt.executed.enabled' => false, 'bughunt.executed.run' => 'r', 'bughunt.executed.shard' => '0']);
+    expect(BughuntExecutedRouteMiddleware::enabled())->toBeFalse();
+
+    config(['bughunt.executed.enabled' => true, 'bughunt.executed.run' => null, 'bughunt.executed.shard' => '0']);
+    expect(BughuntExecutedRouteMiddleware::enabled())->toBeFalse();
+
+    config(['bughunt.executed.enabled' => true, 'bughunt.executed.run' => 'r', 'bughunt.executed.shard' => null]);
+    expect(BughuntExecutedRouteMiddleware::enabled())->toBeFalse();
+
+    bughuntEnableCapture();
+    expect(BughuntExecutedRouteMiddleware::enabled())->toBeTrue();
+});
diff --git a/tests/Support/Routing/MiddlewareShortCircuitInventory.php b/tests/Support/Routing/MiddlewareShortCircuitInventory.php
new file mode 100644
index 0000000..9173834
--- /dev/null
+++ b/tests/Support/Routing/MiddlewareShortCircuitInventory.php
@@ -0,0 +1,127 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Routing;
+
+use App\Http\Middleware\BlockTwoFactorDisableForEnforcedOrganizations;
+use App\Http\Middleware\BughuntCoverageMiddleware;
+use App\Http\Middleware\BughuntExecutedRouteMiddleware;
+use App\Http\Middleware\EnforceMcpTransport;
+use App\Http\Middleware\EnsureAccountNotPendingDeletion;
+use App\Http\Middleware\EnsureEmailIsVerifiedOrBack;
+use App\Http\Middleware\EnsureLoginMethodRemains;
+use App\Http\Middleware\EnsureProjectBelongsToApiOrganization;
+use App\Http\Middleware\EnsureProjectBelongsToRouteOrganization;
+use App\Http\Middleware\HandleInertiaRequests;
+use App\Http\Middleware\IdempotentRequest;
+use App\Http\Middleware\LocalOnly;
+use App\Http\Middleware\McpConsentOrganizationBinder;
+use App\Http\Middleware\NoIndex;
+use App\Http\Middleware\NoStoreCacheHeadersForAuthenticatedPages;
+use App\Http\Middleware\NoStoreResponse;
+use App\Http\Middleware\RequireActiveSubscription;
+use App\Http\Middleware\RequireApiKeyAbility;
+use App\Http\Middleware\RequireRecentAuth;
+use App\Http\Middleware\RequireRecentAuthOnEmailChange;
+use App\Http\Middleware\RequireTwoFactorForEnforcedOrganizations;
+use App\Http\Middleware\ResolveApiActor;
+use App\Http\Middleware\SecurityHeaders;
+use App\Http\Middleware\VerifyMcpOrigin;
+use App\Http\Middleware\VerifySnsSignature;
+use Illuminate\Auth\Middleware\Authenticate;
+use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
+use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
+use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
+use Illuminate\Cookie\Middleware\EncryptCookies;
+use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
+use Illuminate\Routing\Middleware\SubstituteBindings;
+use Illuminate\Routing\Middleware\ThrottleRequests;
+use Illuminate\Routing\Middleware\ValidateSignature;
+use Illuminate\Session\Middleware\AuthenticateSession;
+use Illuminate\Session\Middleware\StartSession;
+use Illuminate\View\Middleware\ShareErrorsFromSession;
+use Inertia\Middleware\EncryptHistory;
+use Livewire\Mechanisms\HandleRequests\RequireLivewireHeaders;
+
+/**
+ * 解決済み middleware クラス => 短絡しうるか (由来を問わず全件分類必須) の単一 source of truth。
+ *
+ * `true` = 3xx/4xx を返して $next を呼ばない分岐を持つ。
+ * **既定は true 側に倒す** (疑わしきは短絡扱い)。`false` を宣言してよいのは
+ * 「$next を必ず呼び、応答の加工しかしない」ことを実装で確認したときだけ。
+ * 未登録クラスの既定も true 扱い (消費側は `?? true` で読む) なので、
+ * 分類漏れが偽陰性にはならない。
+ *
+ * 同じ分類を 2 か所に持たないため、以下の Architecture テストがここを読む:
+ *   - TenantBoundaryOrderingTest        … テナント境界 404 の位置 (存在オラクル防止)
+ *   - BughuntExecutedRouteOrderingTest  … 実行済み route の記録器の位置 (偽陽性防止)
+ */
+final class MiddlewareShortCircuitInventory
+{
+    /**
+     * @return array<class-string, bool>
+     */
+    public static function classification(): array
+    {
+        return [
+            // --- 短絡しうる ---
+            Authenticate::class => true,
+            RedirectIfAuthenticated::class => true,
+            EnsureEmailIsVerified::class => true,
+            ThrottleRequests::class => true,
+            ValidateSignature::class => true,
+            PreventRequestForgery::class => true,
+            AuthenticateSession::class => true,
+            // binding 失敗そのものが 404 (短絡の基準点)
+            SubstituteBindings::class => true,
+            // Inertia の asset version mismatch は 409 で短絡する
+            HandleInertiaRequests::class => true,
+            RequireActiveSubscription::class => true,
+            // 退会予約中の凍結。302 (web) / 409 (XHR) で短絡する
+            EnsureAccountNotPendingDeletion::class => true,
+            RequireTwoFactorForEnforcedOrganizations::class => true,
+            BlockTwoFactorDisableForEnforcedOrganizations::class => true,
+            RequireRecentAuth::class => true,
+            RequireRecentAuthOnEmailChange::class => true,
+            RequireApiKeyAbility::class => true,
+            ResolveApiActor::class => true,
+            IdempotentRequest::class => true,
+            EnsureProjectBelongsToRouteOrganization::class => true,
+            EnsureProjectBelongsToApiOrganization::class => true,
+            EnsureEmailIsVerifiedOrBack::class => true,
+            EnsureLoginMethodRemains::class => true,
+            LocalOnly::class => true,
+            McpConsentOrganizationBinder::class => true,
+            VerifyMcpOrigin::class => true,
+            EnforceMcpTransport::class => true,
+            VerifySnsSignature::class => true,
+            // vendor (Livewire)。X-Livewire ヘッダ / JSON でない要求を 404 で短絡する
+            RequireLivewireHeaders::class => true,
+            // --- 透過 (必ず $next を呼び、応答の加工のみ) ---
+            EncryptCookies::class => false,
+            AddQueuedCookiesToResponse::class => false,
+            StartSession::class => false,
+            ShareErrorsFromSession::class => false,
+            EncryptHistory::class => false,
+            SecurityHeaders::class => false,
+            NoStoreCacheHeadersForAuthenticatedPages::class => false,
+            NoStoreResponse::class => false,
+            // X-Robots-Tag: noindex を足すだけ
+            NoIndex::class => false,
+            BughuntCoverageMiddleware::class => false,
+            // 観測器。必ず $next を呼び、応答を加工しない (= 短絡しない)
+            BughuntExecutedRouteMiddleware::class => false,
+        ];
+    }
+
+    /**
+     * 短絡しうると分類された middleware クラスの一覧。
+     *
+     * @return list<class-string>
+     */
+    public static function shortCircuiting(): array
+    {
+        return array_values(array_keys(array_filter(self::classification())));
+    }
+}

```

## 補足 (差分に含めていない変更)

- `docs/template-divergence.md` に D14 (実行済み route の記録をアプリ側の観測器で採る) を追記
- `AGENTS.md` §bug-hunt に「実行済み route の記録 (毎回 ON・fail-closed)」の箇条書きを追記
- `.claude/agents/bughunt-shard.md` に「走行ログは書かない。記録はアプリ側が自動で採る」を追記
- `.claude/skills/app-bug-hunt/SKILL.md` Phase 4 を 2 コマンド手順へ書き換え
- `.claude/skills/app-bug-hunt/coverage/README.md` を新契約 (終了コード規約表・記録の流れ) に更新

## テスト結果

- `composer test`: 4724 tests, 4722 passed, 2 skipped, 0 failed (20118 assertions)
- `composer phpstan`: No errors (level 10)
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm test` (1450) / `pnpm build`: passed
- `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` (106): passed
- `bash scripts/bug-hunt-shard.sh self-test`: all passed
- `python3 -m unittest test_correlate test_build_executed test_merge_pcov test_naming_no_stale`: 102 tests OK (1 skipped)

## 実装上の判断 (設計との差分)

1. 設計は priority list への追加を `appendToPriorityList($短絡middleware, 記録器)` で書いていたが、
   Laravel の実装は `appendPriority[$append] = $after` の連想配列 (キーが $append) なので、
   同じ記録器を複数の anchor で append すると**後勝ちで 1 本しか残らない**。
   よって `prependToPriorityList(記録器, $短絡middleware)` (キーが $prepend で衝突しない) に変えた。
2. 順序テストが赤で示した短絡 middleware は 9 本 (guest / signed / verified.or-back / recent-auth /
   recent-auth.on-email-change / ensure-login-method / LocalOnly / sns.signature / Livewire の
   RequireLivewireHeaders)。設計どおり推測せず実測で列挙した。
3. 順序テストの母集団を全 route にしたことで、短絡分類の目録に `NoIndex` (透過) と
   `RequireLivewireHeaders` (404 短絡) の 2 件を追加する必要が出た。
4. `RescueRouteGateInventoryTest` (救済 route のゲート目録) が exact-fit なので、
   記録器を `NeverShortCircuitsRescueRoute` として登録し母集団件数 pin を 9 → 10 に更新した。
   設計には書かれていなかったが deny-by-default の目録なので登録が必須である。
5. self-test が worktree の `storage/bughunt-executed/` を汚さないよう、記録の置き場を
   `EXECUTED_BASE` 変数にし、既存の sandbox 差し替え (RUN_BASE / TMP_BASE と同じ流儀) に載せた。

## 質問

- fail-closed 契約に穴が残っていないか (特に「記録が採れていないのに 0 で終わる」経路)
- priority list への 9 本追加が既存の実行順に副作用を与えていないか
  (テナント境界 404 の順序契約 / recent-auth の位置)
- テストが空振りしていないか
