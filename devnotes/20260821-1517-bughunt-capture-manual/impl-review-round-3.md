## 再評価結果

施策4は大きく前進し、JS collectorの観測範囲不足は実ブラウザテストとの二段構成によって実質解消されています。ただし、liveテストに空振りの余地があり、「ネットワーク最終responseを証拠の正本とする」という設計条件もまだ満たしていません。

### `tests/js/pages/CaptureShow.test.ts`

判定: 問題なし

- routerの4入口を共通collectorへ集約している。
- 母集団非空、正例、実mock入口経由の負例が揃っている。
- `<Link>`などの保証外を明記し、その範囲を実ブラウザ側で補完する二段構成は妥当。

Round 2のWarningは解消済みです。

### `tests/Browser/CaptureAppBoundaryTest.php`

[Warning] fetch/XHRの観測母集団が非空であることを確認していません。

現在の`$externalXhr === []`は、実際に`reloadManual`のXHRを観測した場合だけでなく、resource entryが0件でもgreenになります。コメントでは「reloadManualはXHRとして現れる」としていますが、それをassertしていません。

少なくとも次を固定してください。

- fetch/XHR entryが1件以上存在する
- その中に現在のcapture URLへの部分リロードが存在する
- その上で外部destinationが0件である

これがないと、1.5秒待機中に対象フローが発火しなかった場合もPhase A成功として扱われます。

[Warning] Performance APIは「ネットワーク最終response」の代替になっていません。

取得しているのはURLとinitiator typeで、次の設計要求を観測していません。

- response status
- `X-Inertia`
- `X-Inertia-Location`の実値

Playwrightのrequest/responseイベントでこれらを取得するか、利用中のブラウザハーネスで取得不能なら、承認済み詳細設計を正式に改訂する必要があります。「Performance API観測＝ネットワーク最終response相当」と記述するだけでは一致しません。

[Suggestion] 外部origin判定は、観測開始時の期待originと比較してください。

現在は各script実行時の`window.location.origin`を基準にしています。仮に別originの`/app/...`へdocument遷移した場合、遷移後originを正解として扱うため、pathname条件を通過し得ます。期待するアプリoriginをPHP側から渡すか、開始時に保存して比較する方が契約どおりです。`assertPathIs()`も通常はpathname中心なので、origin検査の代替にはしない方が安全です。

### `phase-a-investigation.md`

[Warning] 「証拠の正本」の記述が実際の観測内容より強くなっています。

実施したのは実ブラウザ上のlocation／Performance entry観測であり、responseそのものの観測ではありません。現状のままなら、少なくとも「ネットワーク最終response相当」という表現は避け、未観測のstatus／headersを明記する必要があります。

ただし、詳細設計を正とする今回のレビューでは、文言修正だけではなくresponse観測の追加が必要です。

### 施策5の判断

`navigation-guard.ts`を実装しない判断自体は引き続き妥当です。静的走査とChromium／WebKitの実走行で自動離脱が再現しなかった以上、原因不明のまま包括ガードを追加する必要はありません。

一方、施策4を「設計どおり完了」とするには、live観測の空振り防止とresponse観測が残っています。これらは施策5を実装すべきという意味ではなく、条件付きスキップの証拠を完成させるための修正です。

**全体判定: CHANGES_REQUESTED**