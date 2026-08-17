# 詳細設計レビュー Round 3

Round 2 の Warning 3 件への対応と、修正後の該当箇所を示す。全体判定を再度出してほしい。

# 対応マトリクス: design-review Round 2

Codex 全体判定: CHANGES_REQUESTED (Critical 0 / Warning 3)。3 件すべて対応した。

## [Warning] `mktemp -d` 失敗時に `/` 直下へ書きに行く
- 判断: 対応する
- 根拠: 指摘のとおり。スキルの手順はエージェントが対話的に貼って実行するもので `set -e` を仮定できない。
  `$WORK` が空のまま `"$WORK/table.md"` を書くと `/table.md` を触りに行く。実害のある指摘である。
- 対応内容: `WORK=$(mktemp -d) || exit 1` と `trap 'rm -rf -- "$WORK"' EXIT` に直し、
  失敗を素通りさせない理由をコメントで添えた。

## [Warning] 「保証しないもの」の上下が実装と逆
- 判断: 対応する
- 根拠: 抽出は `sed -n '/^## スクリプト一覧$/,$p'` なので、巻き込まれるのは節より**下**である。
  README の宣言 (別の表は上に置く) とも逆に読めてしまう。単純な誤りである。
- 対応内容: 「照合は `## スクリプト一覧` からファイル末尾までを見る。この節より下に別の表を置き、
  第 1 列をバッククォートの識別子にすると台帳の一部と誤認される」に書き直した。

## [Warning] A3 / A4 の grep は OR 検索で「全項目の存在」を終了コードで保証しない
- 判断: 対応する
- 根拠: 指摘のとおり。「機械検証可能な受け入れ条件」と称しながら人が出力を数える形になっていた。
- 対応内容: 受け入れ条件の表からコマンドを外し、1 語ずつ `grep -qF` して欠けた語を名指しで報告し
  終了コードで落ちる判定スクリプトを新設した (A3 / A4 / A5 を 1 本で判定する)。
  確認する語も増やした (AG-076b / 形態 A / 形態 B / comm -23 / comm -13 / 空欄 /
  README 側の 3 語 / AGENTS.md の 1 語)。

---

## 修正後の該当箇所

### 形態 A の冒頭 (mktemp)
#### 形態 A: 網羅性 (双方向の差集合) と列の空欄

```bash
WORK=$(mktemp -d) || exit 1     # 失敗を素通りさせない ($WORK が空だと / 直下へ書きに行く)
trap 'rm -rf -- "$WORK"' EXIT

# 台帳の表は「## スクリプト一覧」以降だけ。将来 README に別の表が増えても巻き込まない。
sed -n '/^## スクリプト一覧$/,$p' scripts/README.md > "$WORK/table.md"

git ls-files scripts/ | grep -v '^scripts/README\.md$' | sort > "$WORK/tracked.txt"
sed -n 's/^| `\([^`]*\)`.*/scripts\/\1/p' "$WORK/table.md" | sort > "$WORK/listed.txt"

echo "追跡下: $(wc -l < "$WORK/tracked.txt") / 表の識別子: $(wc -l < "$WORK/listed.txt")"
echo '--- 未記載 (実体にあるが表に無い) ---'; comm -23 "$WORK/tracked.txt" "$WORK/listed.txt"
echo '--- 残骸 (表にあるが実体に無い) ---';   comm -13 "$WORK/tracked.txt" "$WORK/listed.txt"
echo '--- 重複した識別子 ---';                uniq -d "$WORK/listed.txt"

# 用途 (第 2 列) と実行タイミング (第 3 列) が空の行、および列数が 3 でない行。
echo '--- 空欄・書式不正 ---'

### 保証しないもの (該当項目)
- **照合は `## スクリプト一覧` からファイル末尾までを見る**。この節より**下**に別の表を置き、
  その第 1 列をバッククォートの識別子にすると台帳の一部と誤認される
  (README の宣言で「別の表はこの節より上に置く」と定めているが、機械では止まらない)。
- **除外の仕組みは無くなる**。旧検査は理由付きの除外登録 (S4) を持っていたが、撤去後は

### 受け入れ条件の表と判定スクリプト
## 受け入れ条件 (機械検証可能)

| # | 条件 | 検証コマンド | 期待 |
|---|---|---|---|
| A1 | 検査ファイルが存在しない | `test ! -e tests/Architecture/ScriptsReadmeInventoryTest.php` | exit 0 |
| A2 | 履歴以外に名前の参照が残っていない | `git grep -n 'ScriptsReadmeInventory' -- ':!devnotes' ':!docs/TODO-closed.md'` | ヒット 0 件 (exit 1) |
| A3 | スキルに段が入っている (4 点) | 下記 A3/A4 の判定スクリプト | exit 0 |
| A4 | 段が双方向の差集合・空欄検出・実態裏取りを持つ | 同上 | exit 0 |
| A5 | README の宣言と AGENTS.md の一文 | 同上 | exit 0 |
| A6 | 台帳が実態と一致している (実走) | 施策 2 の形態 A のコマンド列 | 追跡下 = 表の識別子数、未記載 0 件 / 残骸 0 件 / 重複 0 件 / 空欄・書式不正 0 件 |
| A7 | 負のコントロールが効く | `verification.md` に 4 ケースの実行記録がある | 未記載 1 / 残骸 1 / 重複 1 / 空欄 1 を検出 |
| A8 | 全検証コマンドが green | 下記「全検証コマンド」 | すべて green |

### A3 / A4 / A5 の判定スクリプト (1 語ずつ確かめる)

`grep` の OR 検索は 1 語でも当たれば成功してしまい「全項目の存在」を終了コードで表せない。
1 語ずつ `grep -q` して、欠けた語を名指しで落とす。

```bash
fail=0
check() {  # check <file> <語> ...
  file=$1; shift
  for pattern in "$@"; do
    grep -qF -- "$pattern" "$file" || { echo "欠落: $file に「$pattern」が無い"; fail=1; }
  done
}

check .claude/skills/app-update-docs/SKILL.md \
  '2-1. scripts/ 台帳の整合確認' 'scope 引数によらず' '機械検査へ昇格させない' \
  'AG-076b' 'AG-192' '形態 A' '形態 B' 'comm -23' 'comm -13' '空欄'
check scripts/README.md '台帳の対象範囲' 'CI で落ちる検査にしない' 'app-update-docs'
check AGENTS.md 'CI で落ちる検査にしない'
exit "$fail"
```

### 全検証コマンド (すべて green であること)
