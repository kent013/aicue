# 実装レビュー Round 4 (T223 / bug-hunt 申し送りの生成物化)

Round 3 の指摘 (Warning 1) に対応した。反論・見送りはゼロ件である。

# 実装レビュー Round 3 の対応マトリクス (T223)

Codex 判定: **CHANGES_REQUESTED** (Critical 0 / Warning 1)

| # | 指摘 | 判断 | 対応内容 |
|---|---|---|---|
| 1 | [Warning] `supersedes` へのマーカー混入テストだけ期待が `"書式が不正\|機械マーカーの接頭辞"` と緩く、marker 検査だけを外しても後段の supersede 書式検査が同じ入力を捕まえてテストが緑のままになる (masking が残っている) | **対応する** | 期待を `"機械マーカーの接頭辞"` **だけ**に絞った。指摘どおりの変異 (「`supersedes` に対する `_check_inline_text` のマーカー検査だけを無効化する」) を追加で実施し、**1 failure** で赤になることを確認してから復元し、全 118 本の緑を再確認した |

反論・見送りはゼロ件である。

## 変異試験の記録 (4 件)

| 変異 | 結果 |
|---|---|
| id の照合を `fullmatch` → `match` に戻す | 4 failures / 1 error |
| `_check_inline_text` から機械マーカーの検査を外す | 9 failures |
| 移行台帳の鍵の重複検査を外す | 1 failure |
| `supersedes` に対するマーカー検査だけを無効化する (Round 3 の指摘) | 1 failure |


## 追加差分 (Round 3 レビュー後の変更のみ)

```diff
diff --git a/.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py b/.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py
index ef0c883..f15e297 100644
--- a/.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py
+++ b/.claude/skills/app-bug-hunt/ledger/test_spec_ledger.py
@@ -439,7 +439,9 @@ class ListingCompletenessTest(unittest.TestCase):
                     stage.build()
         with staged() as stage:  # supersedes は A-003 が持つ
             stage.patch_record("A-003", lambda r: r.__setitem__("supersedes", "A-002" + forged))
-            with self.assertRaisesRegex(renderer.RenderError, "書式が不正|機械マーカーの接頭辞"):
+            # 期待は marker 検査**だけ**に絞る。`書式が不正` を許すと、marker 検査を外しても
+            # 後段の supersede 書式検査が同じ入力を捕まえてテストが緑のままになる。
+            with self.assertRaisesRegex(renderer.RenderError, "機械マーカーの接頭辞"):
                 stage.build()
 
     def test_newline_in_one_line_fields_is_rejected(self) -> None:

```

## 検証 (再実行)

```
python3 -m unittest discover -s .claude/skills/app-bug-hunt/ledger -p 'test_*.py'
    → Ran 118 tests / OK
python3 .claude/skills/app-bug-hunt/ledger/render_spec_ledger.py --check   → exit 0
```
AGENTS.md の検証コマンド一式は Round 3 で提示したとおり全 green である
(この変更はテストファイル 1 か所の期待メッセージを狭めただけで、PHP / フロントには触れていない)。

## 出力形式

これまでと同じ。ファイルごとの判定 → 指摘 → 最後に **APPROVED** または
**CHANGES_REQUESTED** の 1 語。
