# 対応マトリクス: conceptual-review Round 2

Round 2 は **APPROVED**。Critical / Warning は無く、詳細設計で確定すべき点として
Suggestion が並んだ。すべて詳細設計 (detailed-design.md) で決着させた。

## [Suggestion] cookie の path / domain / secure / sameSite / 有効期限を確定せよ
- 判断: 対応する
- 対応内容: 詳細設計 S1。`Cookie::make()` の既定へ委ね (= session cookie と同じ属性)、
  有効期限のみ 0 (ブラウザセッション限り) を明示する形にした。

## [Suggestion] セッション cookie と epoch cookie の適用範囲を一致させるか
- 判断: 対応する (一致させる)
- 対応内容: 同上。`CookieJar` の既定が session config 由来なので、渡さないことが一致になる。
  テストも「session cookie と同一属性」の比較にした。

## [Suggestion] プローブ要求ヘッダの欠落・不正長・非 16 進を照合前に拒否せよ
- 判断: 対応する
- 対応内容: 詳細設計 S3。`SessionEpoch::isWellFormed()` (32 文字の 16 進) を通してから
  `hash_equals` する。欠落・書式違いは一致としない (既定は開示しない側)。
  受け取った値はログにも応答にも出さない。

## [Suggestion] `SessionEpoch` が使う鍵と用途の分離
- 判断: 対応する
- 対応内容: 詳細設計 S1。鍵は `app.key`、用途の区切り文字列
  (`bfcache-session-epoch:v1`) をハッシュ入力の前置に入れ、他用途と同じ鍵から
  同じ値が出ないようにした。`APP_KEY` 入れ替え時の帰結 (全て読み直しへ倒れる)
  も docblock に書く。

## [Suggestion] 「読み直した先は必ず秘匿が解ける」は強すぎる表現
- 判断: 対応する
- 対応内容: 詳細設計の「判定の全体像」で保証範囲を限定した。
  保証するのは「読み直しが完了して新しい文書が生成された場合、その文書は
  復元マーカーを継承しない」ことまでで、通信障害で読み直しが完了しない場合は塞がない
  (既存の `/login` 置換遷移と同じ性質) と明記した。

## [Suggestion] PHP 側に Value Object を置くとよい
- 判断: 見送る
- 根拠: 印は `SessionEpoch` の中で導出・照合され、外へ出るのは cookie と共有 prop の
  文字列 1 つだけである。値が層をまたいで持ち回られないため、Value Object を足しても
  守られる不変条件が増えない (今必要なものだけ作る = 思考原則 2)。
  書式の検査は照合の唯一の入口 (`matches()`) に閉じており、そこを通らずに
  比較する経路は設計上存在しない。
