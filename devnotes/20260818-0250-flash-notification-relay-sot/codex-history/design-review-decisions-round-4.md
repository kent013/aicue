# 対応マトリクス: design-review Round 4

全体判定 CHANGES_REQUESTED (Critical なし / 必須修正 2 点)。**全件対応**した。

## [Warning] 施策 1: 字句を 1 本の文字列へ繋いだ後の部分一致は字句境界を保証しない
- 判断: 対応する
- 根拠: 正当。`NotFlashNotificationRelay::SHARED_PROP_KEY` が接尾辞として一致してしまい、
  「クラス名込みの完全一致」になっていなかった。
- 対応内容: 比較を**字句の配列どうしのスライド比較**に変えた
  (`phpTokenSequenceCount(string $relativePath, list<string> $sequence): int`)。
  期待値も字句の配列で書く。抽出器自身の負のコントロール
  (`NotFlashNotificationRelay…` は 0 件 / 正規の形は 1 件) をテストに追加した。
  計算で組み立てたキー (`'fl'.'ash'`) は見えないことを保証範囲として明記した。

## [Warning] 施策 5: 第 1 引数の callee 名が `readFlash` でも正規の readFlash とは限らない
- 判断: 対応する
- 根拠: 正当。同名の自作関数を置けば名前の一致だけは満たせる。
- 対応内容: 束縛の由来を構文木で見る検査を追加した。
  `bindingOrigins(source, ["consumeFlash", "readFlash"])` が各名前について
  **ちょうど 1 件で、それが正規モジュールからの別名なし import** であることを期待値にする
  (同名の自作関数・別名 import・引数や変数による shadowing は形が変わって赤になる)。
  指摘された「自作 readFlash」を負のコントロールとしてテストに追加した。
  保証しないもの (import 先モジュールの中身までは追わない) も明記した。
