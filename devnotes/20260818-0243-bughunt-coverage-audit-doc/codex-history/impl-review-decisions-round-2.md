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
