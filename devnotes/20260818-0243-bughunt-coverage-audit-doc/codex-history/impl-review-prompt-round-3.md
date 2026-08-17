# Round 3: Round 2 の指摘への対応

# 対応マトリクス: impl-review Round 2

## [Warning] `test_20_symlink_loop_is_fail_closed` が `_resolve_or_fail()` を通らない

- 判断: 対応する
- 根拠: 指摘のとおり空振りだった。実測でも、この環境の `Path.resolve()` は自己循環する
  symlink に対して**例外を出さずにそのままのパスを返す** (Python 3.13 / Linux) ため、
  symlink の輪では解決の失敗経路を踏めない。踏めない経路を「踏んだつもり」のテストで
  覆っていたことになる。
- 対応内容: 2 つに分けた。
  - 解決そのものの失敗は **埋め込み NUL を含む基点**で踏ませる
    (`test_16_unresolvable_repo_root_is_converted`)。負の対照を実測済み —
    素の `resolve()` へ戻すと `ValueError` が漏れてこのテストが赤くなる。
  - symlink の輪のテストは残すが、コメントを実態に合わせた
    (「symlink の禁止で先に落ちる。解決の失敗は上のテストが担当する」)。
    輪でも fail-closed であること自体は依然として意味のある固定である。

## [Suggestion] `_markdown_cell()` が CR / LF しか畳んでいない

- 判断: 対応する
- 根拠: `_single_line()` と同じ穴が表側に残っていた。関数の説明 (改行を空白へ畳む) と
  実装が食い違っている。
- 対応内容: `" ".join(escaped.splitlines())` へ変更し、列数のテストの検体に
  Unicode の行区切り (U+2028) を混ぜた。負の対照を実測済み — 修正前の実装では
  列数の集合が `{2, 5}` になり赤くなる。

## [Suggestion] `alpha-` / `alpha--beta` を負のテストへ足す

- 判断: 対応する
- 根拠: 今回足した不変条件を直接 pin していないと、正規表現を戻したときに気付けない。
- 対応内容: `test_6_bad_id_format_is_rejected` の検体へ 2 つ足した。

## [Suggestion] `enabled()` の docblock に env の表現が残っている

- 判断: 対応する
- 根拠: 用語の不一致は残さない。コメントのみの変更で足りる。
- 対応内容: 「設定 + function_exists の二重 guard」へ書き直した。

## 変更後の該当箇所

### `out_of_scope.py` の `_markdown_cell`
```python
def _markdown_cell(value: str) -> str:
    """表を壊さないよう区切りを退避し、改行を空白へ畳む。

```

### `test_out_of_scope.py` の該当テスト
```python
    def test_6_bad_id_format_is_rejected(self) -> None:
        for bad in (
            "Alpha",
            "-alpha",
            "alpha-",
            "alpha--beta",
            "alpha_beta",
            "alpha/beta",
            "",
            "アルファ",
        ):
            payload = self.valid()
            payload["entries"][0]["id"] = bad
            self.assertRejects(payload, f"id={bad!r} を通した")

    def test_16_unresolvable_repo_root_is_converted(self) -> None:
        # パス解決そのものの失敗 (ここでは埋め込み NUL) も DeclarationError へ収束する。
        # 素の resolve() へ戻すと ValueError が漏れてこのテストが赤くなる (負の対照)。
        declaration_path = self.repo.write(self.valid())
        with self.assertRaises(DeclarationError):
            load(declaration_path, str(self.repo.root) + "/broken\x00root")

    def test_19_emit_markdown_keeps_column_count(self) -> None:
        payload = self.valid()
        # 縦棒・素の改行・Unicode の行区切り (U+2028) をすべて 1 つのセルへ入れる。
        payload["entries"][0]["reason"] = _long("縦棒 | と\n改行と\u2028行区切りを含む理由。")
        declaration_path = self.repo.write(payload)
        proc = self._run(
            [
                "--declaration",
                str(declaration_path),
                "--repo-root",
                str(self.repo.root),
                "--emit",
                "markdown",
            ]
        )
        self.assertEqual(proc.returncode, 0, proc.stderr)
        rows = [line for line in proc.stdout.splitlines() if line.startswith("|")]
        self.assertGreaterEqual(len(rows), 4)
        widths = {len(_split_md_row(row)) for row in rows}
        self.assertEqual(len(widths), 1, f"列数が揃っていない: {widths}")

    def test_20_symlink_loop_is_fail_closed(self) -> None:
        # symlink の輪も symlink の禁止で先に落ちる (パス解決まで到達しない)。
        # 解決そのものの失敗が DeclarationError へ収束することは
        # test_16_unresolvable_repo_root_is_converted が担当する。
        (self.repo.root / "app/LoopA").symlink_to(self.repo.root / "app/LoopB")
        (self.repo.root / "app/LoopB").symlink_to(self.repo.root / "app/LoopA")
        payload = self.valid()
        payload["entries"][0]["path_prefixes"] = ["app/LoopA"]
        declaration_path = self.repo.write(payload)
        proc = self._run(
            ["--declaration", str(declaration_path), "--repo-root", str(self.repo.root)]
        )
        self.assertEqual(proc.returncode, 2)
        self.assertEqual(proc.stdout, "")
        self.assertNotIn("Traceback", proc.stderr)
```

### middleware の `enabled()` docblock
```php
     * 設定 + function_exists の二重 guard。どちらか偽なら handle/terminate は完全 no-op。
     * 拡張が読み込まれていない実行環境では function_exists 側が常に false を返す。
     */
    public static function enabled(): bool
    {
```

## 再検証の結果

- coverage の `python3 -m unittest test_out_of_scope` = 40 tests OK
- 負の対照を実測 — 素の `resolve()` へ戻すと NUL の検体で `ValueError` が漏れる / 旧 `_markdown_cell` では列数の集合が {2, 5} になる
- この環境の `Path.resolve()` は自己循環 symlink で例外を出さない (実測) ため、輪では解決の失敗経路を踏めない

残る指摘があれば分類のうえ、最後に APPROVED / CHANGES_REQUESTED を明示すること。
