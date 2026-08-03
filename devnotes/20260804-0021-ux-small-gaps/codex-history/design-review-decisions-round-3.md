# 対応マトリクス: design-review Round 3

判定: B-1 APPROVE / C REQUEST_CHANGES (Warning) / A-1・A-2 REQUEST_CHANGES (Warning) /
B-2 REQUEST_CHANGES → 全体 CHANGES_REQUESTED。**両方とも対応する** (反論なし)。

## [Warning] C-1: 世代管理のテストが「古い secret が再格納されない」を直接観測できない

- 判断: **対応する (指摘された観測順序を採用)**
- 根拠: 妥当。confirm 成功後は `confirming=false` になるため、state が再格納されても
  DOM に出ず、テストが空振りする。
- 対応内容: テストの順序を
  「旧取得を保留 → confirm 成功 (reset) → 再度有効化して新取得を開始 →
  **新 secret を解決して表示** → その後で旧取得を解決 → 新 secret が維持され旧 secret が出ない」
  に変更する。これで **後着優先と reset 無効化を 1 つの観測可能な振る舞いとして固定**できる。
  実害の説明も設計に書く: 旧取得が後勝ちすると**サーバ側が持つ新しい secret とは違うキーを
  ユーザーが認証アプリに登録してしまう** (enrollment が必ず失敗する)。

## [Warning] A-2-1 / B-2: 条件付き適用だと toast lifecycle 契約が二通りになる

- 判断: **対応する (設計を作り直し、条件分岐そのものを消す)**
- 根拠: 妥当。`DESIGN.md` に「消去境界の正本 = 未認証 layout 初期化」と書きながら
  `ToastContainer.onDestroy(clearToasts)` を残すと、認証面の遷移でも toast が消えるため
  ドキュメントと実装が食い違う。「条件によって最終状態が 2 通り」も設計として悪い。
- 対応内容: **B-2 を条件付き施策としては廃止し、A-2 に統合して無条件適用**する。
  ただし Codex 案 (「消去境界を未認証 layout 初期化に一本化 = 認証面では toast が遷移をまたいで残る」)
  はそのままでは採らない。**error toast は auto-dismiss しない** (`toast.ts:27`) ため、
  一本化すると前ページのエラーが遷移後も残り続けるという**新しい後退**を生むからである。
  代わりに、**現行の観測可能な意味 (ページ遷移で toast は消える) を維持したまま、
  その境界を「旧 container の破棄」から「新 layout の初期化」へ移す**:
  - `AppLayout` / `AuthLayout` / `GuestLayout` の **3 layout すべて**が
    初期化時に `clearToasts()` → `$effect` で `consumeFlash()` の順に実行する。
  - `ToastContainer` の `onDestroy(() => clearToasts())` は**撤去する** (境界が二重になり、
    かつ Svelte の破棄/フラッシュ順に依存するため)。
  - 結果、契約は 1 つに定まる:
    **「toast の消去境界は layout の初期化 (= ページ遷移) と auto-dismiss / 手動 dismiss」**。
    観測される挙動は現行と同一で、**順序が決定的になる**点だけが変わる。
  - B-1 (Browser テスト 2 本) は「実装の可否を分岐させるゲート」ではなく
    **変更前後で走らせる回帰テスト**として位置づけ直す。F-1-02 の判定表は
    「変更前の実行結果」の解釈にのみ使う。

## [APPROVE] B-1

- 指摘なし。ただし上記の位置づけ変更 (ゲート → 回帰テスト) を反映する。
