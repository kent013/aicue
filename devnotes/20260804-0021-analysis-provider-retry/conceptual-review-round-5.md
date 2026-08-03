全体判定: **APPROVED**

### 各観点

[Suggestion] **使命との整合性**  
timeout起因の失敗解消に成功条件を限定し、文字化けをblocking follow-upとした整理は妥当です。

[Suggestion] **禁止事項・実現可能性**  
Prism factory経由、既存retry機構の拡張、テスト登録まで含む方針で、禁止事項への抵触はありません。Laravel 12で実現可能です。

[Suggestion] **時間budget**  
`C=360 → D=1080 → T=1560 < retry_after=1680 < TTL=stale=1800`の算術は閉じています。Pを90秒の安全余白へ含める判断も妥当です。

ただし詳細設計では、Pの起点を厳密には「`handle()`入口」ではなく、workerがtimeout alarmを設定してjob実行を開始した時点としてください。payload復元やhandler解決は`handle()`より前に発生し得ます。Pの意図と90秒という値は変わりません。

[Suggestion] **実測・期待効果**  
360秒を保証値ではなく、16,000 token実測274秒に基づく運用上限とした判断は妥当です。未検証範囲と超過時の扱いも明示されています。

[Suggestion] **例外リトライ**  
接続例外、529、408/500/502/503/504、JSON検証失敗の分類は合理的です。`RequestException`へのnarrowingによりPHPStan level 10にも対応できます。

[Suggestion] **チケット会計**  
内部リトライとジョブ強制終了を分け、即時releaseをbest-effort、cronを含む最終収束を保証とした論証は妥当です。cron順序の両パターンをテストする計画も十分です。

[Suggestion] **スコープ**  
既存機構の拡張に収まり、過大でも過小でもありません。ストリーミング、段別budget、reason enumを今回見送る判断も妥当です。

概念設計は承認できます。実装済み判定は、記載したArchitecture/Featureテストと全検証コマンドのgreen確認後です。