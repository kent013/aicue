【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用

【思考原則】
まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。今必要なものだけ作れ (オーバーエンジニアリング禁止)。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest / DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）
- リポジトリルートは /workspace。関係ファイルは実読してよい:
  - `.claude/skills/app-bug-hunt/coverage/correlate.py` / `test_correlate.py` / `README.md`
  - `app/Http/Middleware/BughuntCoverageMiddleware.php`
  - `bootstrap/app.php` (middleware の web グループと priority list)
  - `tests/Architecture/TenantBoundaryOrderingTest.php` / `BugHuntInventoryCheckInvariantTest.php`
  - `scripts/bug-hunt-shard.sh`
  - 概念設計: `devnotes/20260815-1113-bughunt-route-capture-failclosed/conceptual-design.md`

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性
4. テスト計画の網羅性（各施策にテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠 / 11. Atomic Design準拠 (本件は UI 変更を含まないため該当なし)

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

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
| 2 | 実行済み route の記録器 (middleware) | `app/Http/Middleware/BughuntExecutedRouteMiddleware.php` (新規) / `config/bughunt.php` / `bootstrap/app.php` / `tests/Feature/Bughunt/ExecutedRouteCaptureTest.php` (新規) / `tests/Architecture/BughuntExecutedRouteOrderingTest.php` (新規) / `tests/Architecture/TenantBoundaryOrderingTest.php` (分類 inventory へ 1 行) | 最高 |
| 3 | bug-hunt 環境への配線 | `scripts/bug-hunt-shard.sh` | 高 |
| 4 | executed.json の生成器 | `.claude/skills/app-bug-hunt/coverage/build_executed.py` (新規) / 同 `test_build_executed.py` (新規) | 高 |
| 5 | 手順・契約の文書更新 | `.claude/skills/app-bug-hunt/SKILL.md` / `coverage/README.md` / `.claude/agents/bughunt-shard.md` / `docs/template-divergence.md` | 中 |
| 6 | Python 自己テストの実行レーン結線 | `tests/Architecture/BughuntCoverageToolSelfTest.php` (新規) | 中 |

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


@dataclass
class Executed:
    run_id: str | None
    shards: list[str]
    routes: dict[str, set[str]] = field(default_factory=dict)
    statuses: dict[str, set[str]] = field(default_factory=dict)
    row_count: int = 0            # executed_routes の有効行数 (可用性検証に使う)
    # present フィールドは削除する (後方互換の並走を残さない)


def load_executed(path: str) -> Executed:
    """executed.json をロードする。path の省略は受け付けない。"""
    data = json.loads(Path(path).read_text(encoding="utf-8"))
    ex = Executed(run_id=data.get("run_id"), shards=[str(s) for s in data.get("shards", [])])
    for row in data.get("executed_routes", []):
        name = row.get("route_name")
        if not name:
            continue
        ex.row_count += 1
        ex.routes.setdefault(name, set()).add(str(row.get("shard", "")))
        ex.statuses.setdefault(name, set()).add(row.get("status", "ok"))
    return ex


def validate_executed(ex: Executed, run_id: str) -> str | None:
    """主入力 (実行済み route の記録) の可用性を検証する。

    返値は違反理由。None なら成立している。
    **`ok` が 0 件は違反にしない** — 全操作が 403/422/500 で跳ねた走行は、
    主入力としては成立しており、正しい結果は「全件を未実行 worklist に残す」ことである。
    """
    if ex.run_id != run_id:
        return f"executed_run_id_mismatch (executed.json={ex.run_id!r} / --run-id={run_id!r})"
    if ex.row_count == 0:
        return "executed_no_rows (有効な観測行が 1 件も無い = 記録が採れていない)"
    seen = {s for shards in ex.routes.values() for s in shards}
    declared = set(ex.shards)
    if declared and declared != seen:
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
- `to_summary()` に `executed_ok_count` / `executed_blocked_count` を追加する
  (内訳は可視化のためだけで、終了コードには影響させない)。
  `executed_blocked_count` = `routes` に居るが `ok` を持たない route 数 =
  既存の `skipped_blocked_count()` と同義なので、**新しい数え方を増やさず**既存メソッドを流用する。

### PHPStan適合チェック

- 本施策は Python のみ。該当なし。

### テスト計画

`coverage/test_correlate.py` に `ExecutedValidationTest` (新規クラス) と `MainTest` への追加。

- [ ] **負の対照 1**: `--executed` 未指定で `main()` が **3** を返す (`test_main_missing_executed_returns_3`)
- [ ] **負の対照 2**: executed.json の `run_id` が `--run-id` と違うとき **3** (`test_main_run_id_mismatch_returns_3`)
- [ ] **負の対照 3**: `executed_routes` が空のとき **3** (`test_main_empty_executed_returns_3`)
- [ ] **負の対照 4**: `shards` 宣言と実際の shard 集合が食い違うとき **3** (`test_main_shard_mismatch_returns_3`)
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
- `bootstrap/app.php` (L121-147 の `$middleware->web(append: [...])` / L253-268 の priority 鎖)
- `tests/Architecture/TenantBoundaryOrderingTest.php` の `middlewareShortCircuitInventory()` (L120-126)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル:
  - 新規 `tests/Feature/Bughunt/ExecutedRouteCaptureTest.php`
  - 新規 `tests/Architecture/BughuntExecutedRouteOrderingTest.php`
  - **既存 `tests/Architecture/TenantBoundaryOrderingTest.php`**: web グループへ middleware を足すと
    解決後の middleware 列に現れるため、`middlewareShortCircuitInventory()` へ
    `BughuntExecutedRouteMiddleware::class => false` (透過) の登録が**必須**
    (deny-by-default。未分類は「検査1」が fail する)。既存の `BughuntCoverageMiddleware::class => false`
    と同じ形。
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
        @file_put_contents(self::failurePath($run, $shard), $reason."\n", FILE_APPEND | LOCK_EX);
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
> priority list は「載っている middleware 同士の相対順序」しか強制しない。
> 記録器は鎖の全要素より後ろの index を持つので、鎖のどれが実際に付いている route でも
> 最後尾に置かれる。`Authenticate` (`AuthenticatesRequests`) も既に priority list に載っている。

#### (d) `tests/Architecture/TenantBoundaryOrderingTest.php`

```php
        NoStoreResponse::class => false,
        BughuntCoverageMiddleware::class => false,
        // 観測器。必ず $next を呼び、応答を加工しない (短絡しない)。
        BughuntExecutedRouteMiddleware::class => false,
    ];
```

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
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認 (`RefreshDatabase` はグローバル適用)

#### `tests/Architecture/BughuntExecutedRouteOrderingTest.php` (新規)

- [ ] 代表 route (課金ゲート配下の変更系 route を 1 本、`routes/web.php` から選ぶ) の
      **解決後 (priority 適用後) の middleware 列**で、記録器が
      `Authenticate` / `EnsureProjectBelongsToCurrentOrganization` / `RequireActiveSubscription` /
      `EnsureAccountNotPendingDeletion` の**すべてより後**にあること。
      列の取得は既存の `Tests\Support\Routing\NestedRouteDefenseInventory::resolvedMiddleware()` を使う
      (同じ正規化を二度書かない)。
- [ ] 記録器が web グループの全 route に付いていること (少なくとも代表 route 群で存在を確認)
- [ ] **負の対照**: 上記の順序判定が「常に真」でないこと —
      比較対象の index を入れ替えた式が偽になることを同テスト内で確認する
      (空振り gate を作らない)

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
# 実行済み route の記録ファイルを初期化する (run×shard ごと)。
# provision の疎通確認 (curl {url}/login) が記録に混ざると login が毎回「実行済み」になるため、
# 疎通確認の**後**に空にしてから探索エージェントへ引き渡す。
init_executed_capture() {
    local run_id=$1 shard=$2
    local path="storage/bughunt-executed/${run_id}-${shard}.jsonl"
    mkdir -p storage/bughunt-executed
    : > "${path}"
    rm -f "storage/bughunt-executed/${run_id}-${shard}.error"
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

- 呼び出し位置は 2 か所:
  1. dryrun 分岐の `generate_wrapper` の直前 (自己テストから検査できるようにする)
  2. 実経路のヘルスチェック成功直後 (`start_shard_workers` の前)

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

### リスク

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
  4. `run_id` が `--run-id` と違う行があれば → 3 (`run_id_mismatch`。静かに捨てない)
  5. 有効行が 0 件なら → 3 (`capture_empty`。shard 単位で判定する)
- 集計:
  - `route_name` が null の行は `unresolved[shard] += 1`
  - それ以外は `(route_name, shard, status)` をキーに畳み、`http_statuses` を集合で持つ
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
- [ ] **負の対照 5**: ある shard の有効行が 0 件 → 3 (`capture_empty`)
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
   - `status` は `ok|blocked` の 2 値で、生成器が写像すると明記
     (`skipped` は手書き時代の語彙。生成器は出さない)
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

- [ ] `coverage/test_naming_no_stale.py` に **旧 fail-open の文言**のパターンを追加する
      (`--executed` 省略時 / 未実行 candidate)。skill 配下の `.md` / `.py` に再混入したら fail。
      既存の「旧 Stage 付番の再混入検知」と同じ仕組みに 1 パターン足すだけで、新しい機構を作らない。
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
`python3 -m unittest test_correlate test_build_executed` を実走し、終了コード 0 を確認する。

先例は `tests/Architecture/BugHuntInventoryCheckInvariantTest.php`:
`Symfony\Component\Process\Process` でスクリプトを実走し、**python3 の不在は skip ではなく fail** で
顕在化させる (環境不備を skip で隠すと「未検証のまま合格」になる)。同じ作法に揃える。

- AGENTS.md の検証コマンド台帳 (`VERIFICATION_COMMANDS` マーカー) と CI 定義は**触らない**
  (blast radius が別。`composer test` から実走すれば本 TODO の目的は果たせる)。

### テスト計画

- [ ] python3 が PATH に無ければ fail する (skip しない)
- [ ] 2 つの Python モジュールが実走し終了コード 0 になる
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

---

## 関連する現行コード (抜粋)

### correlate.py の主入力ロードと main (現行)
```python
@dataclass
class Executed:
    run_id: str | None
    shards: list[str]
    # route_name -> set(shard) (どれか 1 shard で executed なら executed=true)
    routes: dict[str, set[str]] = field(default_factory=dict)
    statuses: dict[str, set[str]] = field(default_factory=dict)  # route -> {ok,blocked,..}
    present: bool = True  # executed.json が与えられたか

    def is_executed(self, route_name: str) -> bool:
        """実走した (= status 'ok' が 1 つでもある) route のみ executed=true。

        executed.json の status は ok|blocked|skipped。skipped/blocked は
        「UI で叩けなかった = 触っていない」意味なので executed=false とし unexecuted worklist に
        残す。route_name in routes (status 無視) だと skipped/blocked も executed 扱いになり
        executed_pct を不当に押し上げる (coverage 信号汚染)。
        後方互換: status 未記録 (空集合) の route は ok とみなす (旧 executed.json 形の救済)。
        """
        if route_name not in self.routes:
            return False
        st = self.statuses.get(route_name)
        if not st:
            return True  # status 列を持たない旧形式は従来どおり executed 扱い
        return "ok" in st

    def skipped_blocked_count(self) -> int:
        """routes には居るが ok status が 1 つも無い (= skip/block のみ) route 数 (可視化)。"""
        return sum(
            1 for name in self.routes
            if self.statuses.get(name) and "ok" not in self.statuses[name]
        )


def load_executed(path: str | None) -> Executed:
    """executed.json をロード。

    path が None の場合 present=False の空 Executed を返す
    (= 全 in_scope 機構を未実行 candidate 扱い)。
    """
    if path is None:
        return Executed(run_id=None, shards=[], present=False)
    data = json.loads(Path(path).read_text(encoding="utf-8"))
    ex = Executed(run_id=data.get("run_id"), shards=list(data.get("shards", [])))
    for row in data.get("executed_routes", []):
        name = row.get("route_name")
        if not name:
            continue
        ex.routes.setdefault(name, set()).add(str(row.get("shard", "")))
        ex.statuses.setdefault(name, set()).add(row.get("status", "ok"))
    return ex

def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description="bug-hunt 操作到達カバレッジ correlator (operation-reach)")
    ap.add_argument("--route-list", help="route:list --json path (省略時 php artisan route:list を実行)")
    ap.add_argument("--operations", required=True, help="operations.md path")
    ap.add_argument("--findings", required=True, help="findings.jsonl path or - for stdin")
    ap.add_argument("--executed", help="executed.json path (省略時 全 in_scope を未実行 candidate)")
    ap.add_argument("--graph-db", required=True, help="graph.db path")
    ap.add_argument("--run-id", required=True, help="run_id for join")
    ap.add_argument("--project-dir", help="route:list 取得時の cwd (省略時 cwd)")
    ap.add_argument("--hotspot-threshold", type=int, default=2)
    ap.add_argument("--json", action="store_true", help="machine summary as JSON")
    args = ap.parse_args(argv)

    try:
        routes = load_route_list(args.route_list, project_dir=args.project_dir)
        operations = load_operations(args.operations)
        executed = load_executed(args.executed)
        findings, dropped = load_findings(args.findings, args.run_id)
        tb_index = tested_by_index(args.graph_db)
    except (ValueError, json.JSONDecodeError, OSError, sqlite3.Error,
            subprocess.CalledProcessError) as e:
        print(f"ERROR: {e}", file=sys.stderr)
        return 1

    corr = correlate(
        routes, operations, executed, findings, tb_index,
        run_id=args.run_id, hotspot_threshold=args.hotspot_threshold,
        dropped_other_run=dropped,
    )

    if args.json:
        print(json.dumps(to_summary(corr), ensure_ascii=False, indent=2))
    else:
        print(render_worklist(corr))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
```

### BughuntCoverageMiddleware (既存の観測器の作法)
```php
final class BughuntCoverageMiddleware
{
    /**
     * env + function_exists の二重 guard。どちらか偽なら handle/terminate は完全 no-op。
     * pcov 未導入 (= 本環境/CI/本番) では常に false を返す。
     */
    public static function enabled(): bool
    {
        return (bool) config('bughunt.pcov.enabled', false)
            && function_exists('\pcov\start');
    }

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! self::enabled()) {
            return $next($request);
        }

        // per-request 初期化。直前の残骸を clear してから start。
        // Codex R1 (handle も try/catch 化推奨) は不採用: \pcov\clear/\pcov\start は pcov stub で never-throw
        // 宣言のため try/catch は PHPStan L10 の dead catch (catch.neverThrown) になり、ignore 抑制も規約禁止。
        // かつ pcov の実行時失敗は fatal (catchable Throwable でない) ため try/catch は実効防御にならない。
        // よって型保証によりリクエストは壊れない。終端集計 (terminate) は file I/O 等を含むため別途 try/catch 済。
        \pcov\clear();
        \pcov\start();

        return $next($request);
    }

    /**
     * 応答送出後に pcov の結果を集計し、app/ 限定で JSONL を追記する。
     * 収集失敗で応答や後続リクエストを壊さないよう全体を try/catch。
     */
    public function terminate(Request $request, Response $response): void
    {
        $enabled = self::enabled();
        if (! $enabled) {
            return;
        }

        try {
            \pcov\stop();

            // \pcov\collect(\pcov\all) は到達ファイルの「全 executable 行」を line=>count で返す:
            //   count > 0  = covered (実行回数)
            //   count == -1 = executable だが未到達 (= 母数 all には入るが covered には入らない)
            // (pcov 1.0.12 / PHP 8.4 で実証)。filter 不要なので空 filter 経由の segfault も踏まない。
            // ※ collect は呼ぶとバッファを drain するため 1 回だけ呼ぶ (2 回目は空になる)。
            /** @var array<string, array<int, int>> $data */
            $data = \pcov\collect(\pcov\all);

            $appPrefix = self::appPrefix();
            $lines = self::buildLines($data, $appPrefix);
            if ($lines === '') {
                return;
            }

            $path = self::outputPath(self::runId($request), self::shardId($request));
            self::appendJsonl($path, $lines);
        } catch (Throwable $e) {
            // 観測器は機能を壊さない。収集失敗は warning のみで握りつぶす。
            Log::warning('bughunt coverage collection failed', [
                'message' => $e->getMessage(),
            ]);
        } finally {
            // collect が例外でも pcov の蓄積を次リクエストへ持ち越さない。
            // ここに到達する時点で enabled=true が保証される (enabled=false は冒頭 return)。
            // \pcov\clear() は拡張関数で throw しないため追加の try/catch は不要 (PHPStan: dead catch)。
            \pcov\clear();
        }
    }

    /**
     * pcov\collect(\pcov\all) の生結果 (絶対パス => [line => count]) を app/ 限定で JSONL 文字列に整形する。
     * pcov は到達ファイルの全 executable 行を返し、count>0=covered / count<0(=-1)=未到達。よって
     * run/shard から出力ファイル名を組む純関数 (pcov 実体不要・単体テスト対象)。
     */
    public static function outputPath(string $runId, string $shardId): string
    {
        return storage_path('bughunt-coverage'.DIRECTORY_SEPARATOR.$runId.'-'.$shardId.'.json');
    }

    /**
     * app/ 配下判定に使う絶対 prefix (末尾 separator 付き)。
     */
    private static function appPrefix(): string
    {
        return base_path('app').DIRECTORY_SEPARATOR;
    }

    private static function toRelative(string $absFile, string $appPrefix): string
    {
        // app/ 配下を 'app/...' 相対 (C4 契約の表記) に正規化する。
        $tail = substr($absFile, strlen($appPrefix));

        return 'app/'.str_replace(DIRECTORY_SEPARATOR, '/', $tail);
    }

    private static function appendJsonl(string $path, string $lines): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        // 並列 shard は別ファイルだが、同一 shard 内の per-request 追記が衝突しないよう LOCK_EX。
        file_put_contents($path, $lines, FILE_APPEND | LOCK_EX);
    }

    private static function runId(Request $request): string
    {
        return self::nonEmpty(config('bughunt.pcov.run'))
            ?? self::nonEmpty($request->header('X-Bughunt-Run'))
            ?? 'unknown-run';
    }

    private static function shardId(Request $request): string
    {
        return self::nonEmpty(config('bughunt.pcov.shard'))
            ?? self::nonEmpty($request->header('X-Bughunt-Shard'))
            ?? 'unknown-shard';
    }

    private static function nonEmpty(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
```

### bootstrap/app.php の web グループと priority 鎖 (現行)
```php
        $middleware->web(append: [
            HandleInertiaRequests::class,
            SecurityHeaders::class,
            // 組織単位 2FA 強制: (1) 未準拠ユーザーの全画面ゲート → (2) 準拠ユーザーの
            // self-disable 禁止、の順 (disable route はゲートの allowlist 外のため、
            // 未準拠者の disable は (1) が先に弾く)
            RequireTwoFactorForEnforcedOrganizations::class,
            BlockTwoFactorDisableForEnforcedOrganizations::class,
            // 認証済み応答の no-store baseline。
            // 契約: $next から返った (= 下流の) 応答を確認し、既に `no-store` を持つなら変更しない。
            // (位置関係ではなくこの契約が正本。実効性は Feature テストが固定する)
            NoStoreCacheHeadersForAuthenticatedPages::class,
            // Inertia の履歴 state を AES-GCM で暗号化する (Inertia 公式のグローバル適用手順)。
            // ログアウト時に LogoutResponse が Inertia::clearHistory() で鍵を捨てるため、
            // ログアウト後の「戻る」は復号に失敗し、**コンポーネントを描画しないまま**
            // サーバへ再問い合わせ → /login に倒れる (bug-hunt F-4-01)。
            //
            // Inertia 面の認証済み画面が復元されうる経路と担当 (docs/supported-browsers.md が正本):
            //   A: HTTP/disk/proxy cache + Chrome/Firefox の bfcache → NoStoreCacheHeaders...
            //   B: Safari の真の bfcache (pagehide/pageshow)        → resources/js/lib/bfcache-guard.ts
            //   C: Inertia SPA の history 復元 (popstate)           → 本 middleware + Inertia::clearHistory()
            //
            // 認証済み route への限定適用にしない: 認証済み route は ['auth','verified'] グループの
            // 外にも複数あり (招待受諾 POST 等)、限定適用は inventory ドリフトを生む。
            // 公開ページの履歴も暗号化されるが PII は無く、コストはログアウト前エントリの
            // 再取得と remember/scroll 喪失に限られる。
            EncryptHistory::class,
        ]);
        // テナント guard より後に走ることを確定させる web グループの鎖
        // (guard を binding 直後まで引き上げるための「後続」宣言)。
        foreach ([
            [EnsureProjectBelongsToCurrentOrganization::class, HandleInertiaRequests::class],
            [HandleInertiaRequests::class, SecurityHeaders::class],
            [SecurityHeaders::class, RequireTwoFactorForEnforcedOrganizations::class],
            [RequireTwoFactorForEnforcedOrganizations::class, BlockTwoFactorDisableForEnforcedOrganizations::class],
            [BlockTwoFactorDisableForEnforcedOrganizations::class, NoStoreCacheHeadersForAuthenticatedPages::class],
            [NoStoreCacheHeadersForAuthenticatedPages::class, EncryptHistory::class],
            [EncryptHistory::class, EnsureEmailIsVerified::class],
            [EnsureEmailIsVerified::class, RequireActiveSubscription::class],
            // 退会予約中の凍結。**302 で短絡する**ため、テナント境界 404
            // (EnsureProjectBelongsToCurrentOrganization) より必ず後に置く。前に置くと
            // 「他組織に実在 = 302 / 不在 = 404」の 1 bit 存在オラクルになる
            // (AGENTS.md セキュリティ不変条件 10)。課金ゲートの直後に置き、未契約組織の
            // ユーザーは 課金ゲート → onboarding → 凍結 → /settings の 2 hop で取消 UI に着く。
            [RequireActiveSubscription::class, EnsureAccountNotPendingDeletion::class],
        ] as [$after, $append]) {
            $middleware->appendToPriorityList($after, $append);
        }
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            ResolveApiActor::class,
        );

        // Stripe webhook は署名検証 (Cashier middleware)、SES/SNS webhook は
        // SNS 署名検証 (VerifySnsSignature) で保護されるため CSRF 対象外
        $middleware->validateCsrfTokens(except: [
            'stripe/*',
            'ses/*',
        ]);

        // bug-hunt (LLM 探索的バグハント) 用コード到達カバレッジ観測器。
        // env(BUGHUNT_PCOV) と function_exists('\pcov\start') の二重 guard を通らない限り
        // 完全 no-op (handle は $next をそのまま返し、terminate は即 return)。pcov 未導入の
        // 本番/CI/dev には一切影響しない。有効化は scripts/bug-hunt-shard.sh provision --coverage 経由。
        $middleware->append(BughuntCoverageMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // api/* は Accept ヘッダに依らず常に JSON envelope を返す。加えて、XHR / fetch など
        // JSON を期待するリクエスト (expectsJson) では Laravel 既定どおり JSON でレンダリングする
```
