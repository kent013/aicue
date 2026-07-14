# 対応マトリクス: conceptual-review Round 1

全体判定: **APPROVED**（Critical なし）。Warning 3 件はすべて詳細設計に織り込む。

## [Warning] shooting_point 修正の堅牢化
- 判断: 対応する
- 根拠: 匿名テキストノードを残さず flex アイテムを明示する方が truncate の発火が確実。
- 対応内容: 詳細設計で親行を `flex items-center gap-1 min-w-0`、テキスト子を
  `<span class="min-w-0 flex-1 truncate">` に固定する。

## [Warning] overflow 消失は Playwright 実走で再確認する完了条件を明記
- 判断: 対応する
- 根拠: jsdom はレイアウト計算をせず「横スクロール消失」自体は証明不可。
- 対応内容: 詳細設計の完了条件に「bug-hunt / Playwright 実走で 375px・768px の
  horizontal overflow 消失を再確認」を追加。vitest は構造回帰の固定用と位置づける。

## [Warning] section.min-w-0 をテストで固定
- 判断: 対応する
- 根拠: 保険の `min-w-0` を固定しないと `lg:grid-cols-2` 側で再発し得る。
- 対応内容: `CaptureShow.test.ts` で grid が `grid-cols-1` を持つことに加え、
  2 つの `section` が `min-w-0` を持つことも検証する。

## [Suggestion] 受け入れ条件に「思考ゼロで次のカットが読める」を明文化 / テストファースト明記 / scene 据え置き判断をコメント/テスト名で残す / page test と component test の責務分離
- 判断: 対応する（軽微なので詳細設計に反映）
- 対応内容: 完了条件・テスト計画に反映。scene 据え置きはテスト名で意図を残す。
  CutNavigator の内部 DOM は component test、grid レイアウトは page test に振り分ける。
