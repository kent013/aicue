# 対応マトリクス: conceptual-review Round 2

## [Warning] §10-1 が `invitations.accept` について施策と矛盾している

- 判断: **対応する**
- 根拠: 指摘のとおり明白な矛盾。`invitations.accept` は入口ページそのものを絞るため
  「入口は開く」という緩和根拠は成立しない。誤った安心を設計に残すのは本タスクが
  是正しようとしている失敗そのもの (死んだ条件と同じ質の誤り)。
- 対応内容: §10-1 の項目 1 を 2 レーンに分けて書き直した。
  - `social-callback`: `login` / `register` の入口は throttle しないので画面は開く
  - `invitation-accept`: **11 人目は招待リンクを開いた時点で 429 になり画面も出ない**と明記
  - 「詰みにならない」根拠を **招待リンクが失効せず `Retry-After` 後に必ず開ける**ことに限定

## [Warning] `social.callback` の前提テストが第 1 段の付与を検査できていない可能性

- 判断: **一部反論 + 対応する**
- 反論の根拠 (事実確認): `RouteThrottleBinder::throttleEntries($router, $route)` は
  「第 3 段の付与台帳」を返す実装ではない。実体は
  `RouteThrottleBinder.php:171-174` の
  `self::filterThrottleEntries($router->gatherRouteMiddleware($route))` であり、
  **解決後の実効 middleware 列** (controller middleware 込み) を filter している。
  `ThrottleCoverageInventoryTest` が母集団全体の判定に使っているのも同じ関数であり、
  第 1 段 (`routes/web.php` 直書き) の付与も確実に見える。
  (台帳を持つのは `attachOnBooted()` に渡す配列の側で、判定関数とは別物)
- 対応する部分: 「解決後の middleware を見ていること」が設計から読み取れなかったのは
  記述不足なので、判定点の実体を明記した。加えて指摘の後半
  「limiter 名まで固定する」は防御として明確に強いので採用し、
  **entry の params 部が `social-callback` であること**まで assert する要件にした
  (throttle は付いているが別 limiter に差し替わっていた、を検出できる)。

## [Warning] `auth_view_render_only` の上限 14 は「proof なしで免除できる枠」を 1 つ残す

- 判断: **対応する** (提案 A: 上限を現在値 13 に固定)
- 根拠: 指摘が正しい。余裕枠は deny-by-default の趣旨と正面から矛盾する。
  提案 B (inventory を data provider にして 13 本すべてに HTTP/Mail/DB 検査を適用) も
  検討したが、`filament.admin.auth.*` は panel 権限を持つ user の用意が要り、
  `password.reset/{token}` / `two-factor.login` は分岐条件を満たさないと
  「描画されなかっただけ」の空振り green になる。**空振りする 13 本の網より、
  実効する 4 本の網 + exact fit の cap** の方が deny-by-default として強い。
- 対応内容:
  - `auth_view_render_only` の上限を **13 (exact fit)** に
  - **全 case の上限を現在値ちょうど**に (署名短絡の +1 余裕も撤回)
  - **全体 cap も 25 (exact)** に
  - exact fit にする理由 (14 本目が必ず「数値を変える差分」として現れ、
    個別理由・代表テスト追加要否・そもそも貼るべきでないかの再検討を強制する) を明記

## [Suggestion] `DB::listen()` の書込判定が先頭コメント / CTE で脆い

- 判断: **対応する** (限定的に)
- 根拠: 対象 4 route が発行するのは Eloquent / query builder 生成の SQL のみで
  先頭コメントは付かないため前方一致で足りるが、検出器が黙って壊れるのは
  deny-by-default の最悪失敗にあたる。
- 対応内容: 判定を `ltrim()` 後の `insert|update|delete|truncate` 前方一致として関数に切り出し、
  **判定関数自身の単体ケース** (先頭空白付き / `select` / `with ... insert`) を
  同ファイルに置く要件を追記した。SQL パーサは導入しない (思考原則 2)。
