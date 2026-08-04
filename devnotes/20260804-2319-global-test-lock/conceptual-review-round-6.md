全体判定: **APPROVED**

Round 5までのCritical/Warningは解消されています。ブロッキング取得、待機heartbeat、nonce再入ガード、fd非継承、保持親、シグナル収束、恒久検証の契約が整合しています。

[Suggestion] 保証を「専用プロセスグループ」に限定した一方、改善アイデア冒頭と層1検証に「プロセスツリー全体」という表現が残っています。詳細設計では「専用プロセスグループに残る全プロセス」へ統一してください。

[Suggestion] `scripts/with-global-test-lock.sh` の説明に残る「execラッパ」という呼称も、実装が`exec`禁止になったため「コマンドラッパ」等へ変更すると誤解を防げます。

Critical / Warningはありません。実装フェーズへ進める設計です。