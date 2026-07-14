# 対応マトリクス: conceptual-review Round 2

## [Critical] 長文/多数改行で帯が中央まで伸び「中央を覆わない」不変条件が崩れる・primary/secondary 重なり（観点5）
- 判断: 対応する
- 根拠: 妥当。whitespace-pre-line + 非 truncate + 行数無制限では帯高さが無制限に伸び、不変条件を保証できない。
- 対応内容: overlay を `flex flex-col justify-between` にして primary=上端・secondary=下端に構造的に固定（重ならない）。各帯に最大行数 `line-clamp-2`（primary）/`line-clamp-3`（secondary）を設け、超過は省略記号。これで長文・多数改行でも中央領域が空く。契約に「位置・占有領域確認用であり全文確認用ではない（長文は line-clamp で省略）」と明記。vitest に長文 JP・多数改行の同時表示ケース（別要素存在 + line-clamp 適用）を追加。

## [Warning] 端からの inset 値が抽象的（`px-3`/`py-2` は帯内部余白でありプレビュー端 inset ではない）（観点3）
- 判断: 対応する
- 根拠: 実装者の解釈差を防ぐため配置値を確定すべき。
- 対応内容: プレビュー端 inset は overlay コンテナの `p-3`（0.75rem, DS spacing ramp）で担保すると明記（ASS MarginV 36〜48px/1080 ≒ 3.3〜4.4% に対応）。帯内部余白 `px-3 py-1` と役割を分離。

## [Suggestion] trim 正規化文字列が表示にも使われるのか空判定のみか明確化（観点7）
- 判断: 対応する
- 対応内容: 「trim は空判定のみ。描画は元文字列をそのまま使う（内容を書き換えない）」と契約に明記。vitest に「描画文字列が trim で書き換えられない」ケースを追加。

## その他 Suggestion（観点1/2/4/6）
- 判断: 対応不要（肯定的評価）。既定 ON・非 disabled・スコープ整理・効果限定はいずれも承認方向のコメント。
