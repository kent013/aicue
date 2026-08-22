# 全体判定: CHANGES_REQUESTED

フェーズ単位の回収、go token、raw body、DB保護、一次観測は承認水準です。ただし「停止確認不能時にworkspaceを残す」変更により、`APP_KEY`、`CIPHERSWEET_KEY`、plain API keyを含むファイルが残留する新しいセキュリティ問題が生じています。

## 施策1: REQUEST_CHANGES

[Warning] `link()` の失敗をすべて `duplicateSignal()` と分類しています。実際には権限、I/O障害、hard link非対応などでも失敗します。

修正案:

- `link()` 失敗後にtargetが存在すれば `duplicateSignal()`。
- 存在しなければ `signalNotPlaced()`。
- どちらの場合もtemporaryをfinallyで削除する。
- 二重配置と一般的な配置失敗の両方を負例で固定する。

[Warning] 文書が旧方式のまま残っています。

- 「規律6点」に対してdocblockは7点
- リスク欄が「`rename()` が同一FS内」と記載
- 設計冒頭も「一時ファイルからrename」と記載

修正案: `link()` に統一し、「同一filesystem上でhard linkを作れることが前提」と保証範囲を修正してください。

## 施策2: APPROVE

外部transaction、削除順、物理削除、残留検査器、接続回収まで整合しています。

## 施策3: APPROVE

観測の型、DB座標の正規化、409コード、actor、request hashはいずれも妥当です。

## 施策4: REQUEST_CHANGES

[Critical] 停止確認不能時にworkspaceを残すと、次の秘密も残ります。

- 親の `APP_KEY`
- 親の `CIPHERSWEET_KEY`
- DB password
- plain API key
- request body

0700/0600でも、長期間残す前提にはできません。またリスク欄の「finally削除」とも矛盾します。

修正案:

1. workspace全体の削除は停止確認後だけにする。
2. ただしenvファイルと入力ファイルは、回収成否にかかわらずfinallyで必ずunlinkする。
3. 診断用に残すのは秘密を含まないsignals、child ID、終了状態、秘密を除去したstderrなどだけにする。
4. unlinkに失敗した場合は、そのパスを明示したセキュリティ例外にする。
5. 残置ディレクトリのmodeは引き続き0700を検査する。

子がまだenv/inputを読んでいない段階なら、削除によって危険な接続を防げます。既に読んでいても、ディスク上へ秘密を残さない効果があります。

[Warning] 「全子をまとめてpoll」の実装契約をもう一段明確にする必要があります。各子へ長い `waitFor()` を順番に呼ぶと、再び逐次待機になります。

修正案: reap phaseは単一ループで全子の `isRunning()` を短い間隔で確認し、個々の子へ残時間いっぱいのblocking waitを順番に行わないと明記してください。

## 施策5: APPROVE

phpdotenvを含む二重round-trip、umask復元、raw bytesの受け渡し、参照キャプチャまで整っています。

## 施策6: APPROVE

主張範囲が観測可能な内容へ修正され、winner/loser、actor、hash、DB行の裏取りも対応しています。

## 施策7: REQUEST_CHANGES

[Critical] 施策4の秘密残留対策に対応する失敗経路テストが必要です。

修正案として、次を追加してください。

- KILL後も停止確認できない。
- workspace全体は残る。
- envファイルとinputファイルは削除される。
- signalsなど非秘密の診断材料は残る。
- 秘密ファイルのunlink失敗は黙って通らない。

[Warning] `link()` の一般的な配置失敗が `duplicateSignal()` と誤分類されないテストも追加してください。

## 施策8: APPROVE

保証範囲は適切です。

## 施策9: APPROVE

ファイル数、台帳判断、D7の据え置きはいずれも整合しています。

## 承認に必要な最終修正

停止確認不能時でも、秘密を含むenv/inputファイルだけは必ず除去してください。診断用workspaceを残す判断自体は妥当ですが、秘密と診断材料を同じ残置方針にしてはいけません。

この一点と `link()` 失敗の分類を直せば、全体をAPPROVEDにできます。