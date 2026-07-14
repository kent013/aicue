# 対応マトリクス: design-review Round 1

全体判定: **APPROVED**（全 5 施策 APPROVE / 全体 APPROVED）。Warning はいずれも非ブロッキングの品質改善。
着手を待たせないため、安価な 2 件を設計へ反映し、残り 1 件はフォローアップ TODO 推奨として明記した。

## [Warning] 施策2: canManage=false かつ captureNavigable=false で空 action コンテナが残る
- 判断: 対応する
- 対応内容: action コンテナ `div.flex` を `{#if captureNavigable || canManage}` でラップし、
  いずれも出ない場合はコンテナ自体を描画しない。変更後コード・補足を更新。

## [Warning] 施策5: href 検証が toMatch のみで prefix/クエリ変更を取りこぼす
- 判断: 対応する
- 対応内容: `toBe("/app/projects/1/manuals/5")` の厳密一致に変更。加えて `published` ケースを 1 本追加
  （ready/published=true の仕様意図を明示）。テスト計画・サンプルコードを更新。

## [Warning] 施策3: 主 CTA 遷移での未保存破棄の誤操作確率
- 判断: 現状維持（Codex も「本施策内は現状維持でよい」）＋フォローアップ計画化
- 根拠: Edit は既に dirty ガードなしの「キャンセル」導線を持ち、アプリ全体に離脱ガードが無い。本施策で
  離脱確認を新設するのは既存挙動と不整合・スコープ膨張。
- 対応内容: 共通 dirty-navigation ガードの**フォローアップ TODO 化を推奨**する旨を施策3 に追記。

## [Suggestion] 施策1 コメント / 施策4 expectTypeOf / 施策5 published ケース
- 判断: 一部対応
- 対応内容: 施策1 の意図コメントは設計に反映済み。施策5 の published ケースは採用。expectTypeOf は
  `satisfies` による網羅マップで型網羅性は既にコンパイル時担保されるため任意（実装時に導入済みなら追加可）。
