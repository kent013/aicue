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

依存は標準ライブラリのみ。
"""
from __future__ import annotations

import argparse
import json
import os
import sys
import tempfile
from pathlib import Path

# 終了コード規約 (scripts/bug-hunt-inventory-check.sh / correlate.py と同じ 3 = 契約違反)。
EXIT_OK = 0
EXIT_INPUT_ERROR = 1        # 引数・I/O の失敗
EXIT_INPUT_UNAVAILABLE = 3  # 主入力の可用性違反 = 検査を成立させられない

# 記録器が書く status の語彙。2 値だけを受け付ける。
VALID_STATUSES = {"ok", "blocked"}


class CaptureError(Exception):
    """主入力の可用性違反。reason は README の理由コード。"""

    def __init__(self, reason: str, detail: str) -> None:
        super().__init__(f"{reason}: {detail}")
        self.reason = reason
        self.detail = detail


def capture_path(input_dir: str, run_id: str, shard: str) -> Path:
    return Path(input_dir) / f"{run_id}-{shard}.jsonl"


def failure_path(input_dir: str, run_id: str, shard: str) -> Path:
    return Path(input_dir) / f"{run_id}-{shard}.error"


def _check_row(row: object, run_id: str, shard: str, where: str) -> dict:
    """1 行の形を全項目検査する (壊れた入力を集計しない)。"""
    if not isinstance(row, dict):
        raise CaptureError("capture_row_invalid", f"{where}: 行が JSON object でない")
    if row.get("run_id") != run_id:
        raise CaptureError(
            "run_id_mismatch",
            f"{where}: run_id が --run-id と違う ({row.get('run_id')!r} != {run_id!r})",
        )
    if row.get("shard") != shard:
        raise CaptureError(
            "capture_row_invalid",
            f"{where}: shard がファイル名と違う ({row.get('shard')!r} != {shard!r})",
        )
    status = row.get("status")
    # isinstance を先に見る。dict / list の status を集合照合へ渡すと
    # TypeError: unhashable type になり、main() の捕捉対象外なので終了コード規約から外れる。
    if not isinstance(status, str) or status not in VALID_STATUSES:
        raise CaptureError("capture_row_invalid", f"{where}: status が {sorted(VALID_STATUSES)} 以外 ({status!r})")
    http_status = row.get("http_status")
    # bool は int の派生なので明示的に除外する。
    if isinstance(http_status, bool) or not isinstance(http_status, int):
        raise CaptureError("capture_row_invalid", f"{where}: http_status が整数でない ({http_status!r})")
    method = row.get("method")
    if not isinstance(method, str) or method == "":
        raise CaptureError("capture_row_invalid", f"{where}: method が非空文字列でない ({method!r})")
    name = row.get("route_name")
    if name is not None and (not isinstance(name, str) or name == ""):
        raise CaptureError("capture_row_invalid", f"{where}: route_name が null でも非空文字列でもない ({name!r})")
    return row


def load_shard(input_dir: str, run_id: str, shard: str) -> tuple[list[dict], int]:
    """1 shard 分の JSONL を読み、(検査済みの行, 全有効行数) を返す。

    可用性違反はすべて CaptureError で送出する (静かに捨てない)。
    """
    if failure_path(input_dir, run_id, shard).exists():
        raise CaptureError(
            "capture_failed",
            f"shard {shard}: 失敗マーカー {failure_path(input_dir, run_id, shard)} がある",
        )
    path = capture_path(input_dir, run_id, shard)
    if not path.is_file():
        raise CaptureError("capture_file_missing", f"shard {shard}: {path} が無い")

    rows: list[dict] = []
    for lineno, raw in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
        if raw.strip() == "":
            continue
        where = f"{path}:L{lineno}"
        try:
            parsed = json.loads(raw)
        except json.JSONDecodeError as e:
            raise CaptureError("capture_line_broken", f"{where}: {e}") from e
        rows.append(_check_row(parsed, run_id, shard, where))

    named = [r for r in rows if r.get("route_name") is not None]
    if not named:
        raise CaptureError(
            "capture_empty",
            f"shard {shard}: 名前付き route の行が 1 件も無い (記録が採れていない)",
        )
    return rows, len(rows)


def build(input_dir: str, run_id: str, shards: list[str]) -> tuple[dict, list[str]]:
    """executed.json の中身と、stderr へ出す件数サマリを組み立てる。"""
    # (route_name, shard, status) -> http_status の集合
    folded: dict[tuple[str, str, str], set[int]] = {}
    unresolved: dict[str, int] = {}
    summary: list[str] = []

    for shard in shards:
        rows, total = load_shard(input_dir, run_id, shard)
        names: set[str] = set()
        for row in rows:
            name = row.get("route_name")
            if name is None:
                unresolved[shard] = unresolved.get(shard, 0) + 1
                continue
            names.add(name)
            key = (name, shard, row["status"])
            folded.setdefault(key, set()).add(row["http_status"])
        summary.append(
            f"shard {shard}: 行 {total} / route {len(names)} / 名前なし {unresolved.get(shard, 0)}"
        )

    executed_routes = [
        {
            "route_name": name,
            "shard": shard,
            "status": status,
            "http_statuses": sorted(statuses),
        }
        for (name, shard, status), statuses in sorted(folded.items())
    ]

    return {
        "run_id": run_id,
        "shards": list(shards),
        "executed_routes": executed_routes,
        "unresolved": unresolved,
    }, summary


def write_atomic(out: str, data: dict) -> None:
    """一時ファイルへ書いて os.replace() で差し替える (壊れた成果物を残さない)。

    一時ファイルは **--out と同じディレクトリ**に作る (別ファイルシステムだと
    os.replace() が失敗し atomic rename の前提が崩れる)。
    """
    out_path = Path(out)
    out_path.parent.mkdir(parents=True, exist_ok=True)
    fd, tmp = tempfile.mkstemp(dir=str(out_path.parent), prefix=".executed-", suffix=".json")
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as f:
            json.dump(data, f, ensure_ascii=False, indent=2)
            f.write("\n")
        os.replace(tmp, out_path)
    except BaseException:
        Path(tmp).unlink(missing_ok=True)
        raise


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(
        description="bug-hunt 実行済み route の記録 (JSONL) から executed.json を作る")
    ap.add_argument("--run-id", required=True, help="run_id (記録行の run_id と一致すること)")
    ap.add_argument("--shard", required=True, action="append", dest="shards",
                    help="shard 番号 (provision した shard をすべて渡す。複数指定可)")
    ap.add_argument("--input-dir", default="storage/bughunt-executed",
                    help="記録 JSONL の置き場 (既定 storage/bughunt-executed)")
    ap.add_argument("--out", required=True, help="出力する executed.json のパス")
    args = ap.parse_args(argv)

    try:
        data, summary = build(args.input_dir, args.run_id, args.shards)
    except CaptureError as e:
        print(f"ERROR: 主入力が揃わない (reason={e.reason}) {e.detail}。"
              " executed.json は書き出さない (揃わない走行を成功として返さないため)。",
              file=sys.stderr)
        return EXIT_INPUT_UNAVAILABLE
    except OSError as e:
        print(f"ERROR: {e}", file=sys.stderr)
        return EXIT_INPUT_ERROR

    try:
        write_atomic(args.out, data)
    except OSError as e:
        print(f"ERROR: {e}", file=sys.stderr)
        return EXIT_INPUT_ERROR

    for line in summary:
        print(line, file=sys.stderr)
    print(f"executed.json を書き出した: {args.out}", file=sys.stderr)
    return EXIT_OK


if __name__ == "__main__":
    raise SystemExit(main())
