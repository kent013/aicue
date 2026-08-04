# 対応マトリクス: design-review Round 2

Codex 判定: CHANGES_REQUESTED（施策 1 / 4 が REQUEST_CHANGES、他 6 施策は APPROVE）
[Critical] 0 / [Warning] 2 / [Suggestion] 1
※ Round 1 の反論 2 点（非回帰テスト新設の見送り / `>` 維持）は**成立**と判定された。

## [Warning] 施策 1: T4b は `$request->is('api/*')` 条件を単独で検証できない

- 判断: **対応する（テストではなく実装条件を削る）**
- 根拠: 指摘のとおり。`withRouting(api: routes/api.php)` は Laravel 既定の `api` middleware
  グループに載り、**`StartSession` を含まない**。したがって `api/*` の
  `AuthenticationException` は `! $request->hasSession()` で既に抑止され、
  `$request->is('api/*')` は**到達不能な条件（dead branch）**である。
  テストを工夫して dead branch を守るのは本末転倒で、条件そのものを消すのが正しい。
- 対応内容:
  - guard の条件を **`$request->expectsJson() || ! $request->hasSession()`** の 2 つに削る
    （`api/*` 判定を削除 = dead branch を残さない。思考原則 3）
  - **T4b を削除**する。代わりに、条件を削った理由（api グループに session が無いこと）を
    `bootstrap/app.php` のコメントに 1 行残す
  - 万一 `api/*` に session を持つ経路が生まれても、`expectsJson()`（および
    `shouldRenderJsonWhen` により JSON 化される経路）で実質的に除外され、
    仮に積まれても影響は「Inertia 面の履歴が 1 度再キーされる」だけ（安全側）

## [Warning] 施策 4: Alert / 確認ダイアログの文言が quota の効果を過大に説明している

- 判断: **対応する**
- 根拠: 妥当。`QuotaService` の判定は**次元ごとに独立**しており、
  プロジェクト数の超過は `ProjectService::create` だけを、容量の超過は
  `TakeUploadService` だけを止める。現行文面は「新規作成とアップロードができません」と
  両方が止まるかのように読める。
- 対応内容: 文言を**次元別の制約**として書き直す。
  - `/billing` の Alert:
    「現在のプランの上限を超えている項目があります（{次元名}）。既存のデータは削除されませんが、
     **超えている項目に関わる操作**（プロジェクト数ならプロジェクトの新規作成、
     保存容量なら動画のアップロード）が、上限内に収まるまでできません。」
  - `Plans.svelte` の downgrade 確認文言も同じ粒度に揃える。
  - `QuotaExceededException` は元々**超過した次元 1 つ**を名指ししているため文言変更なし
    （施策 4-3 の回復先追記のみ）。

## [Suggestion] 施策 1: T1 の「セッション未確立」は不正確

- 判断: **対応する**
- 根拠: 妥当。guest でも `web` グループの `StartSession` により session は確立している。
  誤った前提をテスト名に残さない。
- 対応内容: T1 のテスト名を
  `'未認証 guest の認証失敗でも、着地の Inertia 応答に clearHistory が載る'` に変更する。
