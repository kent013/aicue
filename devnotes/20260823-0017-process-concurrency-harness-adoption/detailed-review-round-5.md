# 全体判定: APPROVED

Round 5 で、最後の阻害要因だった秘密ファイルの残留と複数子の回収方式が解消されました。正典 v1 の6要素、dev DB保護、並行性の証明、fail-closed観測、後始末の要件を満たしています。

## 施策1: APPROVE

型付きsignal名、完成・書きかけディレクトリの分離、hard linkによる排他的配置、失敗理由の分類まで妥当です。

[Suggestion] 詳細設計中の `ProcessBarrier` docblockには、まだ次の旧記述が残っています。

- 「規律6点」
- 「書いてから rename する」
- release条件の「rename済み」

実装前に「規律7点」「`link()` で配置」に統一してください。ロジック上の阻害事項ではありません。

## 施策2: APPROVE

transaction外でのcommit、FK安全な物理削除、残留検査器、接続回収が揃っています。

## 施策3: APPROVE

観測JSONはdeny-by-defaultで、409の種類、actor、raw body hash、DB座標を厳密に検査できています。

## 施策4: APPROVE

次の点が成立しています。

- 作業deadlineと回収予算が独立
- TERM/KILLが全子に対するフェーズ単位
- pollが単一ループ
- 停止確認不能時はworkspace全体を消さない
- 秘密ファイルは回収成否にかかわらずunlink
- 秘密以外の診断材料だけを残す
- 異常状態を黙って正常終了させない

[Suggestion] 複数の秘密ファイルを削除するときは、最初のunlink失敗で直ちにthrowせず、全対象の削除を試行してから失敗パスをまとめて例外へ含めてください。1件目の失敗によって2件目の削除が省略されるのを防げます。

[Suggestion] `unlink` が保証するのは「パスから再取得できないこと」です。子が既にファイルをopenしていればinodeはfdの解放まで残るため、「ディスクから必ず消える」ではなく、この狭い保証でコメントしてください。

## 施策5: APPROVE

環境の出所固定、phpdotenvとのround-trip、bootstrap前DB検査、ready前のcache/DB検査、raw bytesの保持が整合しています。

## 施策6: APPROVE

実プロセス版は1本に限定され、以下を実測できます。

- 2子が同一raw requestを送った
- handler実行は合計1回
- 敗者は正確に `idempotency_in_progress`
- actor、route、key、request hashが一致
- 保存行が1件かつcompleted
- Laravel既定cacheによる共有ロックは利用不能

主張も観測範囲内に収まっています。

## 施策7: APPROVE

44件の失敗経路検査により、並行していない、観測なし、誤った409、二重実行、段階ごとのdeadline更新、逐次reap、秘密残留などの「緑のまま嘘になる」主要経路が塞がれています。

## 施策8: APPROVE

既存の同一プロセステストと新しい実プロセステストの保証境界が明確です。

## 施策9: APPROVE

新規20本・変更3本の母集団、D7の据え置き、fingerprintおよびadoption debtの判断は整合しています。

設計レビューとして承認します。実装時はテストファーストで赤を確認し、記載された全検証コマンドがgreenになった時点で完了としてください。