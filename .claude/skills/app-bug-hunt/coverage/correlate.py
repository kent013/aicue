#!/usr/bin/env python3
"""操作到達カバレッジ correlator — bug-hunt の「叩いた操作 (route) の網羅」proxy。

run_id を軸に route インベントリ / operations.md(機構分母) /
executed.json(実行済み route の記録。build_executed.py が作る) /
findings.jsonl / graph.db(TESTED_BY) を join し、**未カバー worklist** を出す。

**主入力が揃わない走行は成功にしない** (終了コード 3)。executed.json が無い / 別 run /
形が契約外 / 観測行 0 のときは worklist を出さずに落ちる (揃わない走行を
「全件未実行」という嘘の一覧として返さないため)。

主出力 = worklist (未実行機構 / TESTED_BY untested(TS面のみ) / finding hotspot /
★cross: 未実行∧finding多)。絶対 % は副 (`*_pct` フィールドに添えるのみ・目標にしない)。

設計上の確定事項 (honest):
  - graph の TESTED_BY は TypeScript 専用。PHP route の TESTED_BY は graph 非対応 =
    "false" ではなく "unknown_graph_gap"。PHP route は既定で unknown_graph_gap とし、
    worklist 本文を汚さず件数サマリのみに出す (Pest は別途見よ)。
    実測 (2026-06-20, /workspace/.code-review-graph/graph.db): TESTED_BY=15787 全て TS、
    PHP(.php::)=0。ゆえに app の PHP web route は全件 unknown_graph_gap に落ちる。
  - route 名は graph に無い。route -> graph の join は action(FQCN@method) ->
    controller ファイル相対パス -> graph node の file_path 経由で行う。
    action='Closure' / null は join 不能 = unknown_graph_gap。
  - 本コンポーネントは pcov 非依存 (executed.json は別途記録)。

依存は標準ライブラリのみ。参考スタイル: ledger/findings.schema.json (finding 形)。

operations.md のフォーマット (fix-gate #3):
  app operations.md は markdown leading-pipe の **5 列** が基本:
    | method | route | name | story | 区分 |
  ヘッダ strip("|").split("|") 後の index は 0=method, 1=route(URL), 2=name,
  3=story, 4=区分。**route NAME = name 列 (= index 2)** を join キーに使う
  (URL 列 index 1 を誤抽出すると graph join が失敗する)。
  S8 の API/CLI 面のみ **6 列** (`| method | route | api route name | CLI | story | 区分 |`)。
  本ローダはヘッダ行から name / story / 区分 列の index を動的に決めるため、5 列/6 列
  どちらの節も同じ正しい列を拾える。

使い方:
    python3 correlate.py --route-list route.json --operations operations.md \
      --findings findings.jsonl --executed executed.json \
      --graph-db /workspace/.code-review-graph/graph.db \
      --run-id 20260618-082101 [--json] [--hotspot-threshold 2]

  --route-list を省くと `php artisan route:list --json` を subprocess 取得する。
  --executed は必須 (build_executed.py が作った executed.json を渡す)。
"""
from __future__ import annotations

import argparse
import glob
import json
import re
import sqlite3
import subprocess
import sys
from collections import defaultdict
from dataclasses import dataclass, field
from pathlib import Path

# 終了コード規約 (scripts/bug-hunt-inventory-check.sh と同じ 3 = 契約違反)
EXIT_OK = 0
EXIT_INPUT_ERROR = 1        # 読み込み・parse の失敗 (従来どおり)
EXIT_INPUT_UNAVAILABLE = 3  # 主入力の可用性違反 = 検査を成立させられない


class FatalError(Exception):
    """主入力が契約に反していて検査を成立させられない状態 (終了コード 3)。

    目録は生成物なので、契約外の割当セルが出る状況は「目録を手編集した」か
    「生成器が壊れた」のどちらかである。どちらも黙って進んではいけない。
    """

# 記録器が書く status の語彙。ok|blocked の 2 値だけを受け付ける。
VALID_STATUSES = {"ok", "blocked"}

# TESTED_BY status 三値
TESTED = "tested"
UNTESTED = "untested"
UNKNOWN_GAP = "unknown_graph_gap"

# operations.md の区分。'外'=分母外、'逸'=逸脱のみ(未実行でも警告しない)。
KUBUN_OUT_OF_SCOPE = "外"
KUBUN_DEVIATE = "逸"
ALL_KUBUN = {"◎", "○", "逸", "終", "外"}

# app operations.md の name 列ヘッダ候補 (5 列='name' / S8 6 列='api route name')。
_NAME_HEADERS = ("name", "api route name", "route name", "route_name")
_STORY_HEADERS = ("story",)
_KUBUN_HEADERS = ("区分",)

# 割当セルの値域 (書き出し側の正本は scripts/bug-hunt-inventory.py。規則の散文は
# .claude/skills/app-bug-hunt/stories/README.md)。**寛容に正規化しない** —
# str.split() は前後空白も連続空白も黙って吸収するので、それだけで済ませると書式違反を見逃す。
#
# ★ 照合は fullmatch() で行う (Python の `$` は末尾改行の直前にも一致するため、
#   match() + `$` は「厳密一致」と同義ではない)。
STORY_CELL_RE = re.compile(r"(S[1-9][0-9]*( S[1-9][0-9]*)*|-)")
STORY_CELL_SEPARATOR = " "
STORY_CELL_EMPTY = "-"


def parse_story_cell(cell: str, route_name: str) -> list[str]:
    """割当セルを分解する。文法・昇順・重複を検証し、反したら FatalError。

    実在 (そのカードが在るか) は**見ない**。目録は生成物であり、割当列は実在するカードの
    前付けからしか作られない。手編集で紛れ込んだ id は目録の byte 一致検査が落とす。
    ここに実在検査を足すと照合器が stories/README.md を新たな入力に取ることになり、
    同じ規則が 2 か所に増える。
    """
    if STORY_CELL_RE.fullmatch(cell) is None:
        raise FatalError(
            f"割当セルが契約外: route={route_name} cell={cell!r} "
            "(S{n} を番号の昇順で半角空白 1 つ区切りに並べるか '-')"
        )
    if cell == STORY_CELL_EMPTY:
        return []

    ids = cell.split(STORY_CELL_SEPARATOR)
    numbers = [int(i[1:]) for i in ids]
    if numbers != sorted(set(numbers)):
        raise FatalError(
            f"割当セルが昇順でないか重複している: route={route_name} cell={cell!r}"
        )

    return ids


# --------------------------------------------------------------------------- #
# 入力ロード
# --------------------------------------------------------------------------- #
def load_route_list(path: str | None, *, project_dir: str | None = None) -> list[dict]:
    """route:list --json をロード。各要素 {method, uri, name, action, middleware}。

    path が None なら `php artisan route:list --json` を subprocess 取得する。
    """
    if path is None:
        cwd = project_dir or "."
        out = subprocess.run(
            ["php", "artisan", "route:list", "--json"],
            cwd=cwd, capture_output=True, text=True, check=True,
        ).stdout
        data = json.loads(out)
    else:
        data = json.loads(Path(path).read_text(encoding="utf-8"))
    if not isinstance(data, list):
        raise ValueError("route-list must be a JSON array")
    return data


# operations.md の表セルから route_name を抽出する。
# 1 セルに複数 route ("a / b", "a.{x,y}.z") や脚注付き("name (注...)") が混在する。
_FOOTNOTE_RE = re.compile(r"\s*\(.*$", re.DOTALL)  # 最初の括弧以降は脚注として落とす
_BRACE_RE = re.compile(r"\{([^}]*)\}")
_BACKTICK_RE = re.compile(r"`")


def _expand_braces(token: str) -> list[str]:
    """`organizations.members.update-{billing,api-key}-permission` を 2 route に展開。"""
    m = _BRACE_RE.search(token)
    if not m:
        return [token]
    head, tail = token[: m.start()], token[m.end():]
    out: list[str] = []
    for opt in m.group(1).split(","):
        out.extend(_expand_braces(head + opt.strip() + tail))
    return out


def _parse_route_cell(cell: str) -> list[str]:
    """1 セルから route_name のリストを抽出 (脚注/かっこ書き除去、'/' 分割、{..} 展開)。

    app の name 列はしばしば backtick 付き (`api.v1.projects.store`)。backtick は剥がす。
    """
    cell = _BACKTICK_RE.sub("", cell.strip())
    # セル本体が丸ごと括弧書きのケース (例 '(invitations.accept.store)') を救済する。
    # _FOOTNOTE_RE は最初の '(' 以降を脚注として全削除するため、セル先頭が '(' だと丸ごと空になり
    # 実在 route が denominator から静かに脱落していた。先頭 '(' かつ末尾 ')' のときは外側括弧を
    # 剥がしてから処理する (内側に route 名候補が居るとみなす)。
    if cell.startswith("(") and cell.endswith(")") and len(cell) >= 2:
        cell = cell[1:-1].strip()
    # 脚注 (注...) や (POST) 等の括弧書きを除去。ただし {..} は brace 展開対象なので残す。
    cell = _FOOTNOTE_RE.sub("", cell)
    names: list[str] = []
    for part in cell.split("/"):
        tok = part.strip().strip("*").strip()
        if not tok:
            continue
        # route 名らしさ: 英数 . - _ { } , のみ。日本語混入セルは除外。
        for expanded in _expand_braces(tok):
            expanded = expanded.strip()
            if expanded and re.fullmatch(r"[A-Za-z0-9_.\-]+", expanded):
                names.append(expanded)
    return names


def _header_indices(cols: list[str]) -> tuple[int, int, int] | None:
    """ヘッダ行 cols から (name_idx, story_idx, kubun_idx) を返す。判定不能なら None。"""
    low = [c.strip().lower() for c in cols]
    name_idx = next((i for i, c in enumerate(low) if c in _NAME_HEADERS), None)
    story_idx = next((i for i, c in enumerate(cols) if c.strip().lower() in _STORY_HEADERS),
                     None)
    kubun_idx = next((i for i, c in enumerate(cols) if c.strip() in _KUBUN_HEADERS), None)
    if name_idx is None or story_idx is None or kubun_idx is None:
        return None
    return name_idx, story_idx, kubun_idx


def load_operations(path: str) -> dict[str, dict]:
    """operations.md の表をパースし route_name -> {operation, story, kubun} を返す。

    fix-gate #3: app は 5 列 (`method|route|name|story|区分`)、S8 のみ 6 列。
    join キーは **name 列** (URL の route 列ではない)。ヘッダ行から name/story/区分 列の
    index を動的に決めるため 5 列/6 列いずれの節でも正しい列を拾う。
    operation ラベルは route 列 (URL) を採用する (人間可読の機構名として最も近い)。

    kubun(区分): ◎/○/逸/終/外。'外'(対象外) と '逸'(逸脱のみ) は分母調整の材料。
    複数 route を含むセルは各 route に同じ operation/story/kubun を割り当てる。
    """
    result: dict[str, dict] = {}
    text = Path(path).read_text(encoding="utf-8")
    # 直近に見たヘッダの列割当 (節ごとに更新)。未検出のうちはパース対象外。
    idx: tuple[int, int, int] | None = None
    for raw in text.splitlines():
        line = raw.strip()
        if not line.startswith("|"):
            continue
        if "---" in line:
            continue
        cols = [c.strip() for c in line.strip("|").split("|")]
        # ヘッダ行を検出したら列割当を更新して次行へ。
        maybe = _header_indices(cols)
        if maybe is not None:
            idx = maybe
            continue
        if idx is None:
            continue
        name_idx, story_idx, kubun_idx = idx
        if max(name_idx, story_idx, kubun_idx) >= len(cols):
            continue
        name_cell = cols[name_idx]
        story = cols[story_idx]
        kubun = cols[kubun_idx]
        # operation ラベルは route(URL) 列。無ければ name 列を流用。
        op_idx = 1 if len(cols) > 1 and name_idx != 1 else name_idx
        operation = _BACKTICK_RE.sub("", cols[op_idx]) if op_idx < len(cols) else name_cell
        # 区分セルから先頭の区分記号を取り出す (脚注付き "外 (...)" 等)
        kubun_sym = next((k for k in ALL_KUBUN if kubun.startswith(k)), kubun)
        for name in _parse_route_cell(name_cell):
            # 既出 route は最初の定義を優先 (operations.md の重複定義に強い)
            result.setdefault(name, {
                "operation": _FOOTNOTE_RE.sub("", operation).strip() or operation,
                "story": story,
                "kubun": kubun_sym,
            })
    return result


def _expand_findings_paths(path: str) -> list[str]:
    """--findings の引数を実ファイルパス群へ展開。

    SKILL.md / README の正式呼び出しはシングルクォート付き glob
    (`'devnotes/{run-id}-bug-hunt/shard-*/findings.jsonl'`) を渡すため、シェルが展開せず
    correlate.py がリテラル glob を受け取る。ここで glob 文字を含むなら glob.glob で展開する
    (従来はリテラル open で FileNotFoundError → exit 1 になりハッピーパスが壊れていた)。
    '-' (stdin) は素通し。glob 文字を含まないリテラルパスはそのまま返す (存在検査は open に委譲)。
    1 件もマッチしない glob は FileNotFoundError を投げる (明示エラー)。
    """
    if path == "-":
        return ["-"]
    if any(ch in path for ch in "*?[]"):
        matched = sorted(p for p in glob.glob(path) if Path(p).is_file())
        if not matched:
            raise FileNotFoundError(f"--findings glob '{path}' が 1 件もマッチしない")
        return matched
    return [path]


def load_findings(path: str, run_id: str | None) -> tuple[list[dict], int]:
    """findings.jsonl をロード。run_id 指定時はその run のみ。

    返値: (該当 findings, dropped_other_run 件数)。
    path には glob を受理する (複数 shard の findings.jsonl を順次連結読込)。'-' で stdin。
    複数 shard を cat 連結する従来運用も維持。parse error は ValueError を投げる。
    """
    findings: list[dict] = []
    dropped = 0
    for one in _expand_findings_paths(path):
        if one == "-":
            fh = sys.stdin
            close = False
        else:
            fh = open(one, encoding="utf-8")
            close = True
        try:
            for lineno, raw in enumerate(fh, 1):
                raw = raw.strip()
                if not raw or raw.startswith("#"):
                    continue
                try:
                    rec = json.loads(raw)
                except json.JSONDecodeError as e:
                    raise ValueError(f"findings parse error {one}:L{lineno}: {e}") from e
                if run_id is not None and rec.get("run_id") != run_id:
                    dropped += 1
                    continue
                findings.append(rec)
        finally:
            if close:
                fh.close()
    return findings, dropped


@dataclass
class Executed:
    run_id: str | None
    shards: list[str]
    # route_name -> set(shard) (どれか 1 shard で executed なら executed=true)
    routes: dict[str, set[str]] = field(default_factory=dict)
    statuses: dict[str, set[str]] = field(default_factory=dict)  # route -> {ok,blocked}
    row_count: int = 0               # executed_routes の有効行数 (可用性検証に使う)
    schema_error: str | None = None  # 最初に見つかった契約違反 (形・run_id) の説明

    def is_executed(self, route_name: str) -> bool:
        """status 'ok' を 1 つでも持つ route だけ executed=true。

        blocked は「到達できなかった = 触っていない」意味なので executed=false とし
        未実行 worklist に残す。route_name の存在だけで executed 扱いにすると
        executed_pct を不当に押し上げる (coverage 信号汚染)。
        status を持たない行は入力エラー (executed_schema_invalid) なのでここには来ない。
        """
        return "ok" in self.statuses.get(route_name, set())

    def blocked_count(self) -> int:
        """routes には居るが ok status が 1 つも無い (= blocked のみ) route 数 (可視化)。"""
        return sum(
            1 for name in self.routes
            if "ok" not in self.statuses.get(name, set())
        )


def load_executed(path: str) -> Executed:
    """executed.json をロードする。path の省略は受け付けない。

    **入れ物の型から検証する**。dict でない root、list でない shards/executed_routes、
    dict でない行を素通しすると `.get()` や反復で AttributeError / TypeError になり、
    main() の捕捉対象外なので終了コード規約 (1 / 3) から外れて traceback で落ちる。
    `status` も isinstance(str) を確認してから集合照合する (非 hashable で TypeError になるため)。
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
            ex.schema_error = f"executed_routes の要素が object でない: {row!r}"[:200]
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


@dataclass
class TestedByIndex:
    """controller ファイル(app/ 相対) 単位で TESTED_BY edge の有無を引く。"""
    tested_files: set[str]      # app/ 相対パスで TESTED_BY を持つ controller
    php_has_any: bool           # PHP に 1 件でも TESTED_BY があるか

    def status_for(self, controller_file: str | None) -> str:
        if controller_file is None:
            return UNKNOWN_GAP
        if not self.php_has_any:
            # PHP 全体で TESTED_BY が無い = graph 非対応 -> unknown_graph_gap
            return UNKNOWN_GAP
        return TESTED if controller_file in self.tested_files else UNTESTED


def _normalize_abs_file(abs_file: str) -> str:
    """絶対パス (末尾が app/Foo.php のような形) を app/ 相対のパスへ畳む。"""
    # 最後に出てくる 'app/' から後ろを相対パスとして採用
    idx = abs_file.rfind("/app/")
    if idx >= 0:
        return abs_file[idx + 1:]  # 'app/...'
    if abs_file.startswith("app/"):
        return abs_file
    return abs_file


def tested_by_index(graph_db: str) -> TestedByIndex:
    """graph.db を読み TestedByIndex を返す。

    TESTED_BY edge の source_qualified ('/workspace/<file>::Symbol') から controller
    ファイルを取り出す。PHP の TESTED_BY が 0 件なら php_has_any=False。
    実測 (2026-06-20): app graph.db は TESTED_BY=15787 全て TS、PHP=0。
    """
    conn = sqlite3.connect(graph_db)
    try:
        cur = conn.cursor()
        rows = cur.execute(
            "SELECT DISTINCT substr(source_qualified, 1, instr(source_qualified, '::') - 1) "
            "FROM edges WHERE kind = 'TESTED_BY' AND source_qualified LIKE '%::%'"
        ).fetchall()
        php_any = cur.execute(
            "SELECT count(*) FROM edges "
            "WHERE kind = 'TESTED_BY' AND source_qualified LIKE '%.php::%'"
        ).fetchone()[0]
    finally:
        conn.close()
    tested = {_normalize_abs_file(r[0]) for r in rows if r[0] and r[0].endswith(".php")}
    return TestedByIndex(tested_files=tested, php_has_any=php_any > 0)


def action_to_file(action: str | None) -> str | None:
    """'App\\Http\\Controllers\\Foo\\BarController@store' ->
    'app/Http/Controllers/Foo/BarController.php'。

    '__invoke'(=@ 無しの単一 controller) も FQCN を持てばファイル化する。
    'Closure' / null / 非 App namespace は None (= join 不能)。
    """
    if not action:
        return None
    fqcn = action.split("@", 1)[0].strip()
    if fqcn in ("Closure", "") or "\\" not in fqcn:
        return None
    if not fqcn.startswith("App\\"):
        # vendor(Filament 等) controller は app/ に無い -> join 不能
        return None
    rel = fqcn[len("App\\"):].replace("\\", "/")
    return "app/" + rel + ".php"


# --------------------------------------------------------------------------- #
# 主処理
# --------------------------------------------------------------------------- #
@dataclass
class MechanismRow:
    route_name: str
    operation: str
    story: str
    in_scope: bool
    executed: bool
    tested_by_status: str
    controller_file: str | None
    finding_count: int = 0
    finding_severities: list[str] = field(default_factory=list)
    capability_tags: list[str] = field(default_factory=list)
    via_capability: bool = False  # finding が capability 経由でブロードキャストされた
    kubun: str = ""


@dataclass
class Correlation:
    run_id: str
    rows: list[MechanismRow]
    unexecuted: list[MechanismRow]
    untested_real: list[MechanismRow]
    finding_hotspots: list[MechanismRow]
    cross_unexec_findingful: list[MechanismRow]
    unknown_graph_gap_count: int
    in_scope_count: int = 0
    dropped_other_run: int = 0
    hotspot_threshold: int = 2
    blocked_count: int = 0  # status blocked のみで実走でない route 数


def correlate(routes, operations, executed, findings, tb_index, *,
              run_id, hotspot_threshold: int = 2,
              dropped_other_run: int = 0) -> Correlation:
    """run_id で join し worklist を構築。

    機構 = operations.md に載る route。route:list の action で controller を解決し
    TESTED_BY status を判定。executed/findings を route_name で紐付ける。
    route 名を持たない finding は capability_tag 経由で機構群へブロードキャストする。
    """
    # route_name -> action(controller resolve 用)
    action_by_name: dict[str, str | None] = {}
    for r in routes:
        name = r.get("name")
        if name:
            action_by_name.setdefault(name, r.get("action"))

    rows: list[MechanismRow] = []
    row_by_name: dict[str, MechanismRow] = {}
    for name, meta in operations.items():
        kubun = meta.get("kubun", "")
        controller = action_to_file(action_by_name.get(name))
        row = MechanismRow(
            route_name=name,
            operation=meta.get("operation", ""),
            story=meta.get("story", ""),
            in_scope=(kubun != KUBUN_OUT_OF_SCOPE),
            executed=executed.is_executed(name),
            tested_by_status=tb_index.status_for(controller),
            controller_file=controller,
            kubun=kubun,
        )
        rows.append(row)
        row_by_name[name] = row

    # capability_tag -> 機構群 (operations.md には capability 列が無いので、
    # finding の route 直結を優先しつつ、route 不明 finding は story 一致の機構へ
    # capability 経由でブロードキャストする)。
    # 割当セルは複数値を取りうる (1 route を複数カードが消化する)。セルをそのまま
    # キーにすると `S3 S7` の行が `S3` の finding と一致しなくなるので、検証してから分解する。
    rows_by_story: dict[str, list[MechanismRow]] = defaultdict(list)
    for row in rows:
        for story in parse_story_cell(row.story, row.route_name):
            rows_by_story[story].append(row)

    # finding 紐付け。species_key 単位で二重計上を防ぐ。
    counted: dict[str, set[str]] = defaultdict(set)  # route_name -> {species_key}

    def add_finding(row: MechanismRow, rec: dict, *, via_cap: bool) -> None:
        sp = rec.get("species_key") or rec.get("finding_id") or id(rec)
        if sp in counted[row.route_name]:
            return
        counted[row.route_name].add(sp)
        row.finding_count += 1
        sev = rec.get("severity")
        if sev:
            row.finding_severities.append(sev)
        cap = rec.get("capability_tag")
        if cap and cap not in row.capability_tags:
            row.capability_tags.append(cap)
        if via_cap:
            row.via_capability = True

    for rec in findings:
        direct = rec.get("route_name")
        if direct and direct in row_by_name:
            add_finding(row_by_name[direct], rec, via_cap=False)
            continue
        # route 名なし -> capability 経由で story 一致の機構群にブロードキャスト
        story = rec.get("story_id") or rec.get("story")
        targets = rows_by_story.get(story, [])
        for row in targets:
            add_finding(row, rec, via_cap=True)

    in_scope_rows = [r for r in rows if r.in_scope]
    # 未実行: in_scope ∧ ¬executed。区分 '逸' は未実行でも警告しない。
    unexecuted = [
        r for r in in_scope_rows
        if not r.executed and r.kubun != KUBUN_DEVIATE
    ]
    untested_real = [r for r in rows if r.tested_by_status == UNTESTED]
    hotspots = [r for r in rows if r.finding_count >= hotspot_threshold]
    cross = [
        r for r in unexecuted if r.finding_count >= hotspot_threshold
    ]
    unknown_gap = sum(1 for r in rows if r.tested_by_status == UNKNOWN_GAP)

    return Correlation(
        run_id=run_id,
        rows=rows,
        unexecuted=unexecuted,
        untested_real=untested_real,
        finding_hotspots=hotspots,
        cross_unexec_findingful=cross,
        unknown_graph_gap_count=unknown_gap,
        in_scope_count=len(in_scope_rows),
        dropped_other_run=dropped_other_run,
        hotspot_threshold=hotspot_threshold,
        blocked_count=executed.blocked_count(),
    )


def to_summary(corr: Correlation) -> dict:
    """機械集計。% は副 (`*_pct`)、主は *_count。"""
    n_scope = corr.in_scope_count
    n_exec = sum(1 for r in corr.rows if r.in_scope and r.executed)
    executed_pct = round(n_exec / n_scope, 3) if n_scope else 0.0
    return {
        "run_id": corr.run_id,
        # 主 (worklist の規模)
        "unexecuted_count": len(corr.unexecuted),
        "untested_real_count": len(corr.untested_real),
        "hotspot_count": len(corr.finding_hotspots),
        "cross_count": len(corr.cross_unexec_findingful),
        "unknown_graph_gap_count": corr.unknown_graph_gap_count,
        "in_scope_count": n_scope,
        "dropped_other_run": corr.dropped_other_run,
        # 内訳 (可視化のみ。終了コードには影響しない)
        "executed_ok_count": n_exec,
        "blocked_count": corr.blocked_count,
        # 副 (% は目標にしない・gaming 防止)
        "executed_pct": executed_pct,
    }


def _sev_str(sevs: list[str]) -> str:
    if not sevs:
        return ""
    counts: dict[str, int] = defaultdict(int)
    for s in sevs:
        counts[s] += 1
    order = ["critical", "high", "medium", "low", "needs_review"]
    parts = [f"{s}×{counts[s]}" for s in order if counts.get(s)]
    parts += [f"{s}×{c}" for s, c in counts.items() if s not in order]
    return ", ".join(parts)


def render_worklist(corr: Correlation) -> str:
    """人間向け markdown (主出力)。"""
    L: list[str] = []
    L.append(f"# bug-hunt 操作到達カバレッジ (operation-reach) — run {corr.run_id}")
    L.append("")
    L.append("> 主出力 = **未カバー worklist**。絶対 % は副 (summary の `*_pct` のみ)・目標にしない。")
    L.append(f"> 分母 (in_scope 機構) = **{corr.in_scope_count}** 件 (区分 '外' を除く)。"
             " 分母変更時はこの値の差分を注記すること (gaming 防止)。")
    if corr.dropped_other_run:
        L.append(f"> ℹ run_id 不一致で除外した finding: {corr.dropped_other_run} 件"
                 " (別 run の混入防止)。")
    L.append("")

    # ① 未実行機構
    L.append(f"## ① 未実行機構 (in_scope ∧ ¬executed) — {len(corr.unexecuted)} 件")
    L.append("")
    if corr.unexecuted:
        L.append("| route | operation | story | 区分 | findings |")
        L.append("|---|---|---|---|---|")
        for r in sorted(corr.unexecuted, key=lambda x: (-x.finding_count, x.story, x.route_name)):
            fc = str(r.finding_count) if r.finding_count else "-"
            L.append(f"| {r.route_name} | {r.operation} | {r.story} | {r.kubun} | {fc} |")
    else:
        L.append("(なし)")
    L.append("")

    # ★ cross
    L.append(f"## ★ cross: 未実行 ∧ finding 多 (≥{corr.hotspot_threshold}) "
             f"— {len(corr.cross_unexec_findingful)} 件 [埋める優先度: 最高]")
    L.append("")
    if corr.cross_unexec_findingful:
        L.append("| route | operation | story | findings | severities | capability |")
        L.append("|---|---|---|---|---|---|")
        for r in sorted(corr.cross_unexec_findingful, key=lambda x: -x.finding_count):
            cap = ",".join(r.capability_tags) + (" (cap経由)" if r.via_capability else "")
            L.append(f"| {r.route_name} | {r.operation} | {r.story} | "
                     f"{r.finding_count} | {_sev_str(r.finding_severities)} | {cap} |")
    else:
        L.append("(なし)")
    L.append("")

    # ③ finding hotspot
    L.append(f"## ③ finding hotspot (finding_count ≥ {corr.hotspot_threshold}) "
             f"— {len(corr.finding_hotspots)} 件")
    L.append("")
    if corr.finding_hotspots:
        L.append("| route | findings | severities | executed | capability |")
        L.append("|---|---|---|---|---|")
        for r in sorted(corr.finding_hotspots, key=lambda x: -x.finding_count):
            cap = ",".join(r.capability_tags) + (" (cap経由)" if r.via_capability else "")
            ex = "yes" if r.executed else "NO"
            L.append(f"| {r.route_name} | {r.finding_count} | "
                     f"{_sev_str(r.finding_severities)} | {ex} | {cap} |")
    else:
        L.append("(なし)")
    L.append("")

    # ② TESTED_BY untested (TS 面のみ)
    L.append(f"## ② TESTED_BY untested (TS 面のみ) — {len(corr.untested_real)} 件")
    L.append("")
    L.append(f"> PHP route の TESTED_BY は graph 非対応 = unknown_graph_gap "
             f"**{corr.unknown_graph_gap_count}** 件 (件数のみ・worklist 本文に出さない)。"
             " PHP の実テストは Pest を別途参照すること。")
    L.append("")
    if corr.untested_real:
        L.append("| route | controller | story |")
        L.append("|---|---|---|")
        for r in sorted(corr.untested_real, key=lambda x: x.route_name):
            L.append(f"| {r.route_name} | {r.controller_file} | {r.story} |")
    else:
        L.append("(なし)")
    L.append("")

    # ⑤ trend 用 summary
    s = to_summary(corr)
    L.append("## ⑤ summary (trend 用・% は副)")
    L.append("")
    L.append(f"- unexecuted_count (主): **{s['unexecuted_count']}** / in_scope {s['in_scope_count']}")
    L.append(f"- cross_count (★主): **{s['cross_count']}**")
    L.append(f"- hotspot_count: {s['hotspot_count']}")
    L.append(f"- untested_real_count (TS): {s['untested_real_count']}")
    L.append(f"- unknown_graph_gap_count (PHP): {s['unknown_graph_gap_count']}")
    L.append(f"- executed_ok_count (in_scope ∧ status ok): {s['executed_ok_count']}")
    L.append(f"- blocked_count (status blocked のみ = 未実走扱い): {s['blocked_count']}")
    L.append(f"- executed_pct (副・目標にしない): {s['executed_pct']:.0%}")
    L.append("")
    return "\n".join(L)


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description="bug-hunt 操作到達カバレッジ correlator (operation-reach)")
    ap.add_argument("--route-list", help="route:list --json path (省略時 php artisan route:list を実行)")
    ap.add_argument("--operations", required=True, help="operations.md path")
    ap.add_argument("--findings", required=True, help="findings.jsonl path or - for stdin")
    ap.add_argument("--executed", help="executed.json path (build_executed.py が生成する)")
    ap.add_argument("--graph-db", required=True, help="graph.db path")
    ap.add_argument("--run-id", required=True, help="run_id for join")
    ap.add_argument("--project-dir", help="route:list 取得時の cwd (省略時 cwd)")
    ap.add_argument("--hotspot-threshold", type=int, default=2)
    ap.add_argument("--json", action="store_true", help="machine summary as JSON")
    args = ap.parse_args(argv)

    # argparse の required=True にはしない。required にすると argparse 自身が exit 2 で落ち、
    # 「主入力の可用性違反 = 3」という規約から外れるため、main 内で明示的に検査する。
    if args.executed is None:
        print("ERROR: 主入力が揃わない (reason=executed_missing): "
              "--executed が指定されていない。build_executed.py で executed.json を作ってから渡すこと。",
              file=sys.stderr)
        return EXIT_INPUT_UNAVAILABLE

    try:
        routes = load_route_list(args.route_list, project_dir=args.project_dir)
        operations = load_operations(args.operations)
        executed = load_executed(args.executed)
        findings, dropped = load_findings(args.findings, args.run_id)
        tb_index = tested_by_index(args.graph_db)
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

    try:
        corr = correlate(
            routes, operations, executed, findings, tb_index,
            run_id=args.run_id, hotspot_threshold=args.hotspot_threshold,
            dropped_other_run=dropped,
        )
    except FatalError as e:
        # 目録は生成物である。契約外の割当セルは手編集か生成器の故障なので成功にしない。
        print(f"ERROR: 主入力が契約に反している: {e}", file=sys.stderr)
        return EXIT_INPUT_UNAVAILABLE

    if args.json:
        print(json.dumps(to_summary(corr), ensure_ascii=False, indent=2))
    else:
        print(render_worklist(corr))
    return EXIT_OK


if __name__ == "__main__":
    raise SystemExit(main())
