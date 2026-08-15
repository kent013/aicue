全体判定: **CHANGES_REQUESTED**

方向性は妥当です。特に「主入力が無いのに成功扱いする」fail-open を潰す判断と、ブラウザ側の手作業退避ではなくアプリ側で route 解決済み事実を記録する判断は、bug-hunt 基盤の信頼性改善として North Star に間接的だが本質的に貢献します。

ただし、設計としてまだ閉じるべき穴があります。

**1. 使命との整合性**

[Suggestion] bug-hunt は本番機能ではないため貢献は間接ですが、「撮影者が詰まる導線」「認可漏れ」「未到達操作」を検出する基盤の正しさを上げる改善なので、使命との整合性はあります。

[Warning] 期待効果は「未実行 worklist が信用できる」までであり、「探索品質が上がる」「本番抜けが減る」は二次効果です。設計書では効果を「カバレッジ報告の信頼性回復」に絞って書く方がよいです。

**2. 禁止事項違反**

[Warning] 「観測器の書き込み失敗は警告ログのみ」は、今回の主目的である fail-closed と緊張します。書き込み失敗を完全に握り潰すと、部分欠測の executed.json が生成され、再び静かに嘘をつく可能性があります。

修正提案: アプリ応答は壊さない方針のままでよいですが、記録器は失敗を shard ごとの sentinel/error JSONL に残し、`build_executed.py` がそれを見たら終了コード 3 で落とす設計にしてください。応答非干渉と検査 fail-closed は両立できます。

[Suggestion] `response()->json()` 直書きには触れない設計なので問題ありません。新規 endpoint を作らない限り DTO/JsonResource 制約にも抵触しません。

**3. 実現可能性**

[Warning] アプリ側 middleware 方式は Laravel 12 でも実現可能ですが、gate 設計が未確定です。env flag だけでは no-op 契約として弱く、production 誤有効化時の防壁が不足します。

修正提案: gate は少なくとも次の複合条件にしてください。

- `config('bughunt.route_capture.enabled') === true`
- `app()->isProduction() === false`
- 出力先 path が許可された bug-hunt tmp 配下に正規化されている
- `BUGHUNT_ROUTE_CAPTURE_PATH` などの明示 path が空でない
- Feature テストでは config override で有効化できる

`APP_ENV=bughunt.local` 限定は実運用の防壁としては強いですが、テスト不能化の副作用があります。環境名そのものではなく、config + 非 production + path 境界で縛る方が検証しやすいです。

[Warning] `run_id` 検査は correlate 側だけでなく、記録生成側にも入れるべきです。別 run の JSONL を束ねられると、executed.json の run_id 検査だけでは生成過程の混入を見落とす可能性があります。

修正提案: JSONL 各行に `run_id`, `shard`, `recorded_at`, `route_name`, `method`, `status` を入れ、`build_executed.py` が指定 `--run-id` と不一致の行を終了コード 3 にしてください。

**4. 期待効果の妥当性**

[Warning] 「2xx/3xx→ok、4xx/5xx→blocked」は安全側ですが、422 を blocked とすると「フォームには到達したが入力生成が弱い」ケースと「認可や遷移で到達不能」が同じ扱いになります。worklist の解釈が粗くなります。

修正提案: worklist の除外判定は conservative に `ok` のみでよいですが、記録上の分類は `ok | validation_blocked | auth_blocked | not_found | server_error` 程度に分けてください。最低限、422 と 403/404/500 は分けるべきです。

**5. リスク**

[Critical] 現設計のままだと「記録器が有効だが空」「記録器が途中で壊れた」「疎通確認後の truncate 後に何も記録されなかった」の区別が弱いです。これを閉じないと fail-open の別形になります。

修正提案: `build_executed.py` は以下を終了コード 3 にしてください。

- 入力 JSONL が存在しない
- run_id 一致行が 0 件
- capture error sentinel がある
- shard manifest に存在する shard の JSONL が欠けている
- executed.json の schema/version/run_id が不正

[Warning] middleware がすべての request を記録すると、静的 asset、health check、login 疎通などの雑音が混ざります。

修正提案: route 名がないものは捨てる、疎通確認後に truncate する、かつ `build_executed.py` 側でも operations.md に存在する method+route_name だけを分母照合対象に正規化する、という二段構えにしてください。

**6. スコープの適切さ**

[Warning] 施策 1 だけでは毎回落ちるため 1〜5 同一 TODO は妥当です。一方、施策 6 は「恒久レーンへの結線」であり、変更の blast radius が別です。

修正提案: 施策 6 は「最低限の Architecture テストで self-test が存在し実行可能であることを確認する」までを同一 TODO に含めるのが妥当です。`composer test` 全体への本格結線や検証コマンド台帳との同期変更は別 TODO でもよいです。ただし、今回追加する Python 自己テストをどこからも実行しない状態で完了扱いにするのは避けてください。

**7. 型安全性**

[Warning] Laravel 側 middleware は PHPStan level 10 を意識した設計が必要です。`$request->route()` や `getName()` は nullable/mixed 寄りになりやすく、雑に書くと型エラーか型緩和に流れます。

修正提案: 専用 DTO か value object を置き、`route_name: non-empty-string` 相当を明示検査してから JSONL 化してください。`status` は `Response` から int として取得し、method は `strtoupper($request->method())` を許可 enum 的に検査する形がよいです。config 値も `is_string`, `is_bool` で境界検査してください。

**判断を仰ぎたい点への回答**

1. アプリ側観測器の選択は妥当です。route 名・method・status をアプリの解決結果から取れるため、今回の目的には browser 退避より堅いです。逸脱記録には「担う不変条件は同じ、欠測検出は build_executed の fail-closed で担保」と明記してください。

2. gate は `APP_ENV=bughunt.local` 単独ではなく、config enable + non-production + capture path 境界 + 明示 run_id/path を推奨します。Feature テストは config override で有効化します。

3. 4xx/5xx を blocked に倒す方針は安全側で妥当です。ただし 422 は別分類にしてください。worklist 上は未達扱いでも、診断情報として分ける価値があります。

4. 施策 6 は縮小して同一 TODO に含めるべきです。少なくとも追加 Python テストが `composer test` か Architecture テスト経由で実行される保証は必要です。本格的な Python test lane 整備は別 TODO で構いません。