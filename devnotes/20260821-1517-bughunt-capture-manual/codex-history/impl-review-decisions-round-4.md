# 対応マトリクス: impl-review Round 4

Codex 全体判定: CHANGES_REQUESTED (origin 固定は承認。実 response 観測の契約に抜け)。全対応した。

## [Warning] 取得した X-Inertia を assert していない / inApp(null)=>true で 409 の欠落が通る
- 判断: 対応する
- 対応内容: 分岐を明確化。good(200) は `X-Inertia==="true"` かつ `X-Inertia-Location===null` を assert。
  bad は version 不一致を強制した 409 で `X-Inertia-Location` が非 null・非空・現 origin の /app 配下で
  あることを assert。`inApp()` は string 非空でなければ false を返すよう変更 (409 で欠落したら落ちる)。

## [Warning] 能動 fetch が reloadManual と同じ request でない (version/partial ヘッダ欠落)
- 判断: 対応する
- 対応内容: `X-Inertia-Version` (サーバ側で確定した Vite manifest 由来の実 version を注入) /
  `X-Inertia-Partial-Component: Capture/Show` / `X-Inertia-Partial-Data: manual` /
  `X-Requested-With` を付けて router.reload({only:['manual']}) の partial reload を再現。
  version 一致 (good) は 200、故意の不一致 (bad) は 409 の両経路を観測。good の 200 本文の
  component をサーバ応答から読み `Capture/Show` を裏取り (注入値の echo でないこと)。

## [Suggestion] ファイル先頭コメントが実装と矛盾 (X-Inertia-Location は page JS から読めない)
- 判断: 対応する
- 対応内容: 冒頭コメントを「受動 Performance API では読めないが、同一オリジンの能動 fetch では読める」に
  訂正。version/component 取得の stale コメント (DOM data-page から取る) もサーバ注入に合わせて訂正。

## 検証
- tests/Browser/CaptureAppBoundaryTest.php: Chromium/WebKit 両レーン passed (Phase A 単独 16 assertions)。
