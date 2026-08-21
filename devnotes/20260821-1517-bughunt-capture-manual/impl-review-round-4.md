## 再評価結果

origin固定は正しく対応されています。ただし、実response観測には契約上の抜けが残っており、施策4はあと一段必要です。

### `tests/Browser/CaptureAppBoundaryTest.php`

[Warning] 取得した`X-Inertia`をassertしていません。

`$reloadResponse`には`xInertia`を格納していますが、検証しているのはstatusとURLだけです。そのため、通常のHTMLレスポンスでも次の条件を満たしてgreenになります。

- statusが200
- 最終URLが`/app/...`
- `X-Inertia-Location`がnull

200の場合は少なくとも次を固定してください。

- `X-Inertia === "true"`
- `X-Inertia-Location === null`

409の場合は次を固定する必要があります。

- `X-Inertia-Location`がnullではなく、空でもない
- その実値が固定originの`/app`配下である

現在の`inApp(null) => true`では、409なのに`X-Inertia-Location`が欠落していても通ります。「ヘッダ実値を観測した」という成功条件にはなりません。

[Warning] 能動fetchは`reloadManual`と同じリクエストではありません。

実際の部分reloadには通常、少なくとも以下が含まれます。

- `X-Inertia-Version`
- `X-Inertia-Partial-Component`
- `X-Inertia-Partial-Data: manual`

提示されたfetchは`X-Inertia`だけなので、完全なInertia visitであり、`router.reload({only: ["manual"]})`の部分reloadを再現していません。特に`X-Inertia-Version`がないため、コメントにあるversion不一致409の経路を検証できません。

最も確実なのは、実際の`router.reload`が発行したresponseをPlaywright側で捕捉することです。能動fetchを使う場合は、現在のInertia versionとpartial reloadヘッダを設定して実リクエストと一致させてください。

[Suggestion] ファイル先頭の説明が実装と矛盾しています。

冒頭には「`X-Inertia-Location`はpage JSから読めない」とありますが、後半では同一オリジンfetchのresponse headersから読み取っています。正確には、受動的なPerformance APIでは読めないが、同一オリジンの能動fetchでは読める、です。

### origin判定

判定: 問題なし

- 観測開始時のoriginを固定している。
- navigation、resource、location、response URLのすべてで固定originを使用している。
- 別originの`/app/...`を正当化してしまう問題は解消された。

### 施策5の判断

施策5を実装しない判断は引き続き妥当です。今回の残件はガード追加を要求するものではなく、Phase Aのresponse契約を正確に固定するためのものです。

次を揃えれば施策4を完了と判断できます。

- 実際のpartial reloadと同等のrequest、または実router responseを観測
- 200時の`X-Inertia: true`をassert
- 409時の非nullな`X-Inertia-Location`実値をassert
- staleな観測範囲コメントを修正

**全体判定: CHANGES_REQUESTED**