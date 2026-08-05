#!/usr/bin/env python3
"""doc/reference/ の git index を NFC 正規化して検証する (C1 の fail-fast 判定 + manifest 生成)。

usage:
  nfc-nfd-index.py verify        4 条件を検証して結果を表示する (1 つでも崩れたら exit 1)
  nfc-nfd-index.py map           NFC(path) -> "<mode> <blob> <stage>" の map を stdout へ
                                 (同一 key に異なる値が現れたら exit 1)
  nfc-nfd-index.py manifest      削除対象 (NFD 形) の manifest を stdout へ
  nfc-nfd-index.py pathspec OUT  削除対象を NUL 区切りの pathspec ファイルへ書く
"""
import collections
import subprocess
import sys
import unicodedata

PATHSPEC = "doc/reference"


def entries():
    out = subprocess.run(["git", "ls-files", "-s", "-z", PATHSPEC],
                         capture_output=True, check=True).stdout
    result = []
    for rec in out.split(b"\0"):
        if not rec:
            continue
        meta, path = rec.split(b"\t", 1)
        mode, blob, stage = meta.decode().split()
        result.append((mode, blob, stage, path.decode("utf-8")))
    return result


def groups(rows):
    by_nfc = collections.defaultdict(list)
    for row in rows:
        by_nfc[unicodedata.normalize("NFC", row[3])].append(row)
    return by_nfc


def nfd_paths(rows):
    return sorted(
        (r for k, v in groups(rows).items() if len(v) > 1 for r in v if r[3] != k),
        key=lambda r: r[3],
    )


def cmd_verify():
    rows = entries()
    coll = {k: v for k, v in groups(rows).items() if len(v) > 1}
    sizes = collections.Counter(len(v) for v in coll.values())
    blob_mismatch = [k for k, v in coll.items() if len({r[1] for r in v}) > 1]
    missing_nfc = [k for k, v in coll.items() if not any(r[3] == k for r in v)]
    actual = int(subprocess.run(
        f"find {PATHSPEC} -type f | wc -l", shell=True, capture_output=True, check=True
    ).stdout.decode().strip())

    checks = [
        ("index entry 総数", len(rows), 197),
        ("NFC 正規化衝突グループ", len(coll), 58),
        ("衝突グループのサイズ (2 以外の数)", sum(c for s, c in sizes.items() if s != 2), 0),
        ("blob が異なるグループ", len(blob_mismatch), 0),
        ("NFC 形 entry を持たないグループ", len(missing_nfc), 0),
        ("index 総数 - 衝突数", len(rows) - len(coll), actual),
        ("作業ツリーの実体", actual, 139),
        ("削除対象 (NFD) entry", len(nfd_paths(rows)), 58),
    ]
    ok = True
    for label, got, want in checks:
        mark = "OK " if got == want else "NG "
        if got != want:
            ok = False
        print(f"  [{mark}] {label}: {got} (期待 {want})")

    by_dir = collections.Counter(p[3].split("/")[2] for p in nfd_paths(rows))
    print(f"  NFD の内訳: {dict(by_dir)}")
    if not ok:
        print("事前確認に失敗しました。中止します。", file=sys.stderr)
        sys.exit(1)


def cmd_map():
    rows = entries()
    seen = {}
    for mode, blob, stage, path in rows:
        key = unicodedata.normalize("NFC", path)
        value = f"{mode} {blob} {stage}"
        if key in seen and seen[key] != value:
            print(f"同一 NFC key に異なる値: {key}", file=sys.stderr)
            sys.exit(1)
        seen[key] = value
    for key in sorted(seen):
        print(f"{seen[key]}\t{key}")


def cmd_manifest():
    print("# doc/reference/ の NFD 形 index entry (削除対象 manifest)")
    print("#")
    print("# 生成: git ls-files -s -z doc/reference | (NFC 正規化して自分自身と異なる path を抽出)")
    print("# 形式: <blob-hash> <TAB> <NFD path>")
    print("#")
    for _mode, blob, _stage, path in nfd_paths(entries()):
        print(f"{blob}\t{path}")


def cmd_pathspec(out):
    with open(out, "wb") as fh:
        for _mode, _blob, _stage, path in nfd_paths(entries()):
            fh.write(path.encode("utf-8") + b"\0")


if __name__ == "__main__":
    {"verify": cmd_verify, "map": cmd_map, "manifest": cmd_manifest}.get(sys.argv[1], lambda: None)() \
        if sys.argv[1] != "pathspec" else cmd_pathspec(sys.argv[2])
