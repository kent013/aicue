# 対応マトリクス: conceptual-review Round 4

## [Critical] API 影響セクション冒頭に撤回済みの「1 象限だけ」記述が残っている

- 判断: 対応する (指摘どおりの改訂漏れ)
- 対応内容: 当該文を削除し、以下に置き換えた。
  「変更は 2 系統に分かれる: (i) actor 解決に成功したリクエストにおけるテナント境界の応答
   (cross-org が常に 404 になる)、(ii) actor 解決に失敗したリクエストにおけるエラー優先順位
   (不在 id が 404 ではなく 401/403 になる)。web (S2) は (i) のみ、API (S1+S2) は両方が該当する。」

## [Warning] S3 の「binding 段 (= 全 app middleware より前)」が不正確

- 判断: 対応する
- 対応内容: 「binding 段で 404 になり、**binding より後に走るすべての短絡 middleware より前**に閉じる」に修正。
  最終順序では `ResolveApiActor` が `SubstituteBindings` より前に走ること、
  ただし該当 route は web 専用のため `ResolveApiActor` は載らないことを注記した。

## [Warning] S4-6 の pre-binding inventory は「明記」だけでは機械保証にならない

- 判断: 対応する
- 対応内容: 保証範囲を 3 点に分解して明記した。
  (a) 静的検査 = 登録クラスのソースに生 route parameter を読む呼び出し
      (`$request->route(` / `Route::input(` / `$request->segment(`) が無いこと。
  (b) 振る舞い検査 = 各 middleware が短絡する状態で**実在 id と不在 id の応答が完全同一**になることを
      Feature テストで固定。
  (c) 限界の明示 = 呼び出し先クラス経由の間接 DB 参照までは静的証明できないこと、
      (a) は直接参照の不在まで・(b) が実応答の同一性を担保する二段構えであることを docblock に残す。

## [Suggestion] 使命 / 不変条件 / スコープ / 型安全性

- 判断: 反映済み (変更なし)
