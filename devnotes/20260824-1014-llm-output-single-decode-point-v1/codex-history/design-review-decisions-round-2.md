# 対応マトリクス: design-review Round 2

## [Warning] 施策 7: group use の例が「完全修飾名で判定する」方針と矛盾
- 判断: 対応する
- 根拠: 指摘が正しい。`use function Foo\{json_decode as decodeJson};` は
  `Foo\json_decode` の別名であって global の `json_decode` ではない。末尾名で判定すると
  共通規約 (a) と同種の誤検出を関数側で再発させる。
- 対応内容: 「**違反にするのは解決後の完全修飾名が厳密に `json_decode` のものだけ**」と
  明記した。`use function json_decode as decodeJson;` (先頭 `\` の綴りも同じ) は違反、
  `use function Foo\{json_decode as decodeJson};` は**非違反の正例** (正例 7b) へ移した。
  group use は「別名解決を実装する対象」には残し、「global の回避例」からは外した。

## [Warning] 施策 7: 変更ファイル一覧と説明が 8 検査に追従していない
- 判断: 対応する
- 根拠: 実装時のファイル漏れに直結する。
- 対応内容: 施策一覧と施策 7 の変更箇所に
  `tests/Support/Llm/LlmResponseHandling.php` (分類 enum) と
  `tests/Support/Llm/DecodePointPublicSurface.php` (公開面の判定。次項) を追加し、
  gate の説明を「目録 + 8 検査」に直した。

## [Warning] 施策 7: 公開面の負例が本番と同じ判定経路を通ることの明記
- 判断: 対応する
- 根拠: gate だけが `LlmJson::class` を直接 Reflection し、負例が別ロジックで
  メソッド数を数えると、負例は本番 gate の検出力を証明しない ((c) の裏取りが空になる)。
- 対応内容: `Tests\Support\Llm\DecodePointPublicSurface::of(class-string): array` という
  **純関数**を置き、本番の `LlmJson` と負例 fixture の**両方を同じ関数へ渡す**設計にした。
  検査 7 の記述にその旨を明記した。

## [Warning] 施策 8: 本変更のコミットに自分自身の SHA は書けない / デプロイ日は未確定
- 判断: 対応する
- 根拠: 指摘が正しい。自己参照は成立せず、実装 PR に placeholder を残す設計は不可。
- 対応内容: 文書には「**本変更の本番デプロイを境界とする**」とだけ書き、
  具体的な日時・リリース SHA は**書かない**ことにした (実値の正本はデプロイ記録 /
  リリースノートで、文書はそこを指す)。施策 1 のリスク欄と施策 8 の両方を直した。
