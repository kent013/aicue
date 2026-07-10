#!/usr/bin/env python3
"""Bug-hunt コード到達カバレッジ pcov shard merge (code-reach).

C3 middleware (BughuntCoverageMiddleware) が per-request で書き出す JSONL shard
(file -> {covered:[lines], all:[lines]}) を複数 shard で **union** マージし、
**未カバー (uncovered) を主出力** とする。絶対 % (line_pct) は補助フィールドに
添えるのみで目標にしない (gaming 防止 / 命名「コード到達カバレッジ」)。

HONEST 注記: 本環境は pcov 未導入のため実 coverage は取得できない。
本スクリプトは pcov 非依存の純ロジック (入力は C3 出力形の JSON) であり、
テストは fixture の shard を union して検証する。app の shard は 0-8
(直列 shard-0 :8010 / 並列 shard-1..8 :8011..8018)。

依存は標準ライブラリのみ (json, argparse, glob, sys, pathlib, dataclasses)。

使い方:
    python3 merge_pcov.py --shard 'storage/bughunt-coverage/20260618-082101-*.json' \
        --run-id 20260618-082101 [--json] [--only app/] [--verbose]
"""
from __future__ import annotations

import argparse
import glob
import json
import os
import sys
from dataclasses import dataclass, field
from pathlib import Path


@dataclass
class FileCov:
    """1 ファイル分の到達行集合。

    covered = 実行された行 (どれかの shard で通った行)。
    all     = 実行可能行 (pcov が認識した executable line)。
    """

    file: str
    covered: set[int] = field(default_factory=set)
    all: set[int] = field(default_factory=set)

    @property
    def uncovered(self) -> set[int]:
        return self.all - self.covered

    def union(self, other: "FileCov") -> None:
        self.covered |= other.covered
        self.all |= other.all


@dataclass
class MergeResult:
    run_id: str
    files: dict[str, FileCov] = field(default_factory=dict)
    shard_paths: list[str] = field(default_factory=list)
    parse_errors: list = field(default_factory=list)  # (path, lineno, msg)
    dropped_other_run_shards: list[str] = field(default_factory=list)  # run_id 不一致で除外
    unmatched_patterns: list[str] = field(default_factory=list)  # 0 件マッチの --shard

    # --- 主: 未カバー ---
    @property
    def fully_uncovered_files(self) -> list[str]:
        """一度も到達しなかったファイル (covered 空 ∧ all 非空)。主出力。"""
        return sorted(
            f.file for f in self.files.values() if f.all and not f.covered
        )

    @property
    def partial_files(self) -> list[tuple[str, list[int]]]:
        """到達したが uncovered 行が残るファイル (file, sorted uncovered lines)。

        validation / 権限 / 例外分岐の未到達 = pcov 固有の価値。
        """
        out: list[tuple[str, list[int]]] = []
        for f in self.files.values():
            if f.covered and f.uncovered:
                out.append((f.file, sorted(f.uncovered)))
        out.sort(key=lambda t: t[0])
        return out

    # --- 副: % (目標にしない) ---
    @property
    def total_all(self) -> int:
        return sum(len(f.all) for f in self.files.values())

    @property
    def total_covered(self) -> int:
        return sum(len(f.covered) for f in self.files.values())

    @property
    def total_uncovered(self) -> int:
        return sum(len(f.uncovered) for f in self.files.values())

    def line_pct(self) -> float:
        return self.total_covered / self.total_all if self.total_all else 0.0

    @property
    def all_equals_covered(self) -> bool:
        """全 file で all==covered か (C3 middleware の file-grain only 出力形)。

        BughuntCoverageMiddleware.buildLines が各 record を covered==all で書き、covered 空の
        file を出力しない実装だと、uncovered 行集合は常に空・line_pct は常に 100% の
        **アーティファクト**になり、行 grain では無意味 (reach は file grain でしか意味を持たない)。
        本フラグが真のとき出力にその旨を注記する (反 100%・anti-gaming 設計意図への整合)。
        空入力 (file 0) は False を返す。
        """
        if not self.files:
            return False
        return all(f.all == f.covered for f in self.files.values())


def _coerce_lines(value) -> set[int]:
    """JSON の行リストを int の set に。非 int / 非 list は例外。"""
    if not isinstance(value, list):
        raise ValueError(f"expected list of line numbers, got {type(value).__name__}")
    out: set[int] = set()
    for v in value:
        if isinstance(v, bool) or not isinstance(v, int):
            raise ValueError(f"line number must be int, got {v!r}")
        out.add(v)
    return out


def parse_record(rec) -> FileCov:
    """1 JSON record を FileCov に。必須キー: file, covered, all。"""
    if not isinstance(rec, dict):
        raise ValueError(f"record must be object, got {type(rec).__name__}")
    if "file" not in rec or not isinstance(rec["file"], str) or rec["file"] == "":
        raise ValueError("missing/empty required key: file")
    if "covered" not in rec:
        raise ValueError("missing required key: covered")
    if "all" not in rec:
        raise ValueError("missing required key: all")
    return FileCov(
        file=rec["file"],
        covered=_coerce_lines(rec["covered"]),
        all=_coerce_lines(rec["all"]),
    )


def load_shard(path: str) -> dict[str, FileCov]:
    """1 shard JSON(L) を file -> FileCov に。

    C3 は JSON Lines で追記する (1 request 1 行 or per-file 集計)。同一ファイルが
    複数行で現れるため、shard 内でも union して 1 FileCov に畳む。
    parse error はその行を skip し、(path, lineno, msg) を呼び出し側で集計する。
    """
    out: dict[str, FileCov] = {}
    errors: list = []
    fh = sys.stdin if path == "-" else open(path, encoding="utf-8")
    with fh:
        for lineno, raw in enumerate(fh, 1):
            raw = raw.strip()
            if not raw or raw.startswith("#"):
                continue
            try:
                rec = json.loads(raw)
                fc = parse_record(rec)
            except (json.JSONDecodeError, ValueError) as e:
                errors.append((path, lineno, str(e)))
                continue
            if fc.file in out:
                out[fc.file].union(fc)
            else:
                out[fc.file] = fc
    # load_shard はファイル単位の dict のみ返す。errors は属性で持たせる。
    load_shard.last_errors = errors  # type: ignore[attr-defined]
    return out


def _expand_shards(patterns: list[str]) -> list[str]:
    """glob パターン群を実ファイルパスへ展開 (sorted, 重複除去)。

    '-' (stdin) はそのまま通す。glob/リテラルがディレクトリにマッチしたものは除外する
    (open() で IsADirectoryError を起こさないための事前フィルタ)。
    """
    paths, _unmatched = _expand_shards_diag(patterns)
    return paths


def _expand_shards_diag(patterns: list[str]) -> tuple[list[str], list[str]]:
    """_expand_shards の診断付き版。(paths, unmatched_patterns) を返す。

    unmatched_patterns = '-' 以外で 0 件マッチ (= glob が何も拾わない / 存在しないリテラル)
    だったパターン群。run-id タイプミスや shard dir 取り違えの可視化に使う。
    ディレクトリにマッチしたエントリは skip する。
    """
    paths: list[str] = []
    seen: set[str] = set()
    unmatched: list[str] = []
    for pat in patterns:
        if pat == "-":
            if "-" not in seen:
                paths.append("-")
                seen.add("-")
            continue
        matched = [p for p in sorted(glob.glob(pat)) if os.path.isfile(p)]
        if not matched:
            # glob 0 件 or マッチがディレクトリのみ = 実ファイルゼロ。可視化対象。
            unmatched.append(pat)
            continue
        for m in matched:
            if m not in seen:
                paths.append(m)
                seen.add(m)
    return paths, unmatched


def _run_id_matches(path: str, run_id: str) -> bool:
    """shard 実ファイルの basename が run_id を接頭辞に持つか。

    C3 の出力は {run_id}-{shard}.json (BughuntCoverageMiddleware.outputPath)。
    別 run の shard が広すぎる glob で無検知混入するのを防ぐため、basename が
    '{run_id}-' で始まるものだけを同一 run 由来とみなす。'-' (stdin) と run_id 空は素通し。
    """
    if path == "-" or not run_id:
        return True
    return os.path.basename(path).startswith(f"{run_id}-")


def merge_shards(paths: list[str], *, run_id: str = "", only: str | None = None) -> MergeResult:
    """複数 shard を union merge。covered = ∪, all = ∪ (shard 0-8 union)。

    only 指定時は file が only prefix で始まるものだけ残す (既定 app/ 限定運用)。
    """
    res = MergeResult(run_id=run_id, shard_paths=list(paths))
    for path in paths:
        # 壊れ入力耐性: ディレクトリ/権限不足等で open() が OSError を投げても merge 全体を
        # 殺さず、当該 shard を parse_errors に計上して skip し残りを継続する。
        try:
            shard = load_shard(path)
        except OSError as e:
            res.parse_errors.append((path, 0, str(e)))
            continue
        res.parse_errors.extend(getattr(load_shard, "last_errors", []))
        for file, fc in shard.items():
            if only is not None and not file.startswith(only):
                continue
            if file in res.files:
                res.files[file].union(fc)
            else:
                # コピーして格納 (元 shard dict を共有しない)
                res.files[file] = FileCov(
                    file=fc.file, covered=set(fc.covered), all=set(fc.all)
                )
    return res


def to_summary(res: MergeResult) -> dict:
    """機械集計。uncovered が主、% は副 (line_pct は目標にしない)。"""
    return {
        "run_id": res.run_id,
        "shards": len(res.shard_paths),
        "files": len(res.files),
        # --- 主 ---
        "fully_uncovered_count": len(res.fully_uncovered_files),
        "partial_uncovered_file_count": len(res.partial_files),
        "uncovered_line_count": res.total_uncovered,
        # --- 副 (補助・目標にしない) ---
        "covered_line_count": res.total_covered,
        "executable_line_count": res.total_all,
        "line_pct": round(res.line_pct(), 3),
        # --- 健全性 ---
        "parse_errors": len(res.parse_errors),
        "dropped_other_run_shards": len(res.dropped_other_run_shards),
        "unmatched_patterns": len(res.unmatched_patterns),
        "no_shards_matched": len(res.shard_paths) == 0,
        # 全 file で all==covered = C3 file-grain only 出力形。真なら line_pct/uncovered 行は
        # file-grain only のアーティファクト (行 grain では無意味) であることを機械可読に示す。
        "all_equals_covered_file_grain_only": res.all_equals_covered,
    }


def render_uncovered(res: MergeResult, *, verbose: bool = False) -> str:
    """人間向け markdown。主見出し = 未カバー。% は括弧書きの副記のみ。"""
    s = to_summary(res)
    lines: list[str] = []
    lines.append(f"# コード到達カバレッジ (code-reach) — run {res.run_id or '(unknown)'}")
    lines.append("")
    lines.append(
        f"shards: {s['shards']}  files: {s['files']}  "
        f"未到達ファイル: {s['fully_uncovered_count']}  "
        f"uncovered 行: {s['uncovered_line_count']}  "
        f"(参考 line_pct: {s['line_pct']:.1%})"
    )
    lines.append("")

    # C3 file-grain only 出力形 (全 file で all==covered) の注記。line_pct=100% / uncovered 行=0 は
    # 「全到達・100%」ではなく、行 grain の母数を middleware が持たないことに由来するアーティファクト。
    # reach は file grain (= ①「一度も到達しないファイル」) でのみ意味を持つ。
    if res.all_equals_covered:
        lines.append(
            "> ⚠ 全 file で all==covered (C3 middleware の file-grain only 出力形)。"
            "**line_pct (100%) と『uncovered 行』は行 grain では無意味なアーティファクト**であり、"
            "『全て到達・100%』を意味しない。到達判断は file grain (下記 ①) のみで行うこと。"
            "なお『一度も到達しないファイル』は入力 JSONL に現れない app file = 検出されない点に注意"
            "(全 app inventory との突合は static audit / correlate 側の責務)。"
        )
        lines.append("")

    # ① 一度も到達しないファイル (主)
    lines.append("## ① 一度も到達しないファイル (covered 空 / 主)")
    if res.fully_uncovered_files:
        for f in res.fully_uncovered_files:
            lines.append(f"- {f}")
    else:
        lines.append("- (なし)")
    lines.append("")

    # ② 到達したが uncovered 行が残る (validation/権限/例外分岐 = pcov 固有価値)
    lines.append("## ② 到達済みだが uncovered 行が残るファイル (主)")
    if res.partial_files:
        for f, ucov in res.partial_files:
            if verbose:
                shown = ", ".join(str(n) for n in ucov)
                lines.append(f"- {f}: {len(ucov)} 行 [{shown}]")
            else:
                lines.append(f"- {f}: {len(ucov)} 行")
    else:
        lines.append("- (なし)")
    lines.append("")

    # ③ summary (副・% は目標にしない)
    lines.append("## ③ summary (副 — 絶対 % は目標にしない)")
    lines.append(
        f"covered/executable: {s['covered_line_count']}/{s['executable_line_count']}  "
        f"line_pct: {s['line_pct']:.1%}  parse_errors: {s['parse_errors']}"
    )
    if res.parse_errors:
        lines.append("")
        lines.append("> parse_errors detail (stderr 参照)")
    return "\n".join(lines)


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(
        description="Bug-hunt コード到達カバレッジ pcov shard merge (uncovered 主出力)"
    )
    ap.add_argument(
        "--shard",
        action="append",
        required=True,
        help="shard JSON(L) path or glob (繰り返し可)。'-' で stdin。",
    )
    ap.add_argument("--run-id", default="", help="run id (同一 run のみ merge する前提)")
    ap.add_argument("--only", default="app/", help="この prefix の file のみ残す (既定 app/)")
    ap.add_argument("--json", action="store_true", help="machine summary as JSON")
    ap.add_argument("--verbose", action="store_true", help="uncovered 行を展開表示")
    args = ap.parse_args(argv)

    paths, unmatched = _expand_shards_diag(args.shard)
    # run_id 帰属ガード: basename が {run_id}- で始まらない shard は別 run 由来として除外。
    # 広すぎる glob (run 接頭辞を含まないタイプミス等) で別 run の shard が無検知混入するのを防ぐ。
    # CLI 境界でのみ適用し、merge_shards 本体は純 union primitive のまま保つ (運用ガードと
    # マージ演算の責務分離)。
    kept: list[str] = []
    dropped: list[str] = []
    for p in paths:
        (kept if _run_id_matches(p, args.run_id) else dropped).append(p)
    only = None if args.only in ("", "-") else args.only
    res = merge_shards(kept, run_id=args.run_id, only=only)
    res.unmatched_patterns = unmatched
    res.dropped_other_run_shards = dropped

    if args.json:
        print(json.dumps(to_summary(res), ensure_ascii=False, indent=2))
    else:
        print(render_uncovered(res, verbose=args.verbose))

    for path, lineno, msg in res.parse_errors:
        print(f"  {path}:L{lineno}: {msg}", file=sys.stderr)

    # 0 件マッチの --shard パターンを warning に出す (run-id タイプミス/dir 取り違えの可視化)。
    for pat in unmatched:
        print(f"warning: --shard '{pat}' は実ファイルに 1 件もマッチしない "
              f"(run-id タイプミス / shard dir 取り違え / ディレクトリのみ?)", file=sys.stderr)
    # run_id 帰属ガードで除外した別 run の shard を warning に出す。
    for p in res.dropped_other_run_shards:
        print(f"warning: shard '{p}' の basename が run-id '{args.run_id}-' で始まらない "
              f"= 別 run 由来として除外", file=sys.stderr)
    # 結果ゼロ (= 何にもマッチしなかった) は正常な空結果と区別して明示する。
    if not res.shard_paths and not args.run_id == "":
        print("warning: 集計対象の shard が 0 件 (line_pct/uncovered は無意味な空結果)。"
              "--shard パターンと --run-id を確認すること", file=sys.stderr)

    # parse error は warning に留める (exit 0)。shard 0 件は空結果として 0。
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
