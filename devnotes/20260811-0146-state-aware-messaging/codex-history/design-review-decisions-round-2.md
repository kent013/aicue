# 対応マトリクス: design-review Round 2

Codex 全体判定: **CHANGES_REQUESTED**（施策 2 / 3 / 4 は APPROVE、施策 1 のみ REQUEST_CHANGES）。
Round 1 の反論 4（PHPStan closure 戻り型）は **Codex が撤回**した。

## [Warning] 施策 1: `DashboardPageData` に `OnboardingBillingState` の import が要る

- 判断: **対応する**
- 根拠: `DashboardPageData.php` は現在この enum を import していない。PHPDoc の型名は
  現在の namespace で解決されるため、import なしだと
  `App\DataTransferObjects\Dashboard\OnboardingBillingState` と解釈されて PHPStan が落ちる。
- 対応内容: 施策 1 に「import 必須」の注記ブロックを追加（完全修飾名ではなく import を選ぶ理由も明記）。

## [Warning] 施策 1: 新規テスト 5 の fixture に組織生成行を残す

- 判断: **対応する**
- 根拠: `grandfatherFreePlan: false` の取り違えがこのテストの空振りに直結する。
  「必須注意」の散文だけでなく、各テストの具体コードに残すほうが取り違えを防げる。
- 対応内容: 新規テスト 5 も 2 行のコード片（組織生成 + factory）に書き換えた。

## [Warning] 施策 4 / mutation #8: PHPDoc だけでは `missing()` は赤化しない

- 判断: **対応する**
- 根拠: 指摘のとおり。`missing()` が見るのは Inertia payload であり、PHPDoc shape ではない。
  変異の定義が曖昧だと「変異を入れたのに赤くならない → 検査が無効」と誤結論しかねない。
- 対応内容: mutation 表 #8 を「**`toArray()` の返り値配列に** `'has_billing_access' => …` を
  併記する」と具体化し、PHPDoc だけを変えても赤くならないことを明記した。

## [Suggestion] 施策 1: 「外部消費者は存在しえない」は断定が強すぎる

- 判断: **対応する**
- 根拠: 誇張しない方針に照らして正しい指摘。機械的に確認できたのは
  **リポジトリ内の**参照だけである。
- 対応内容: リスク節を「リポジトリ内の参照は全数確認済み。リポジトリ外の消費者の不存在は
  機械的に保証できない」に書き換えた。

## [Suggestion] 施策 2: Browser テスト 2 本を 1 本に統合できる

- 判断: **対応する（採用）**
- 根拠: 同一 fixture・同一画面であり、1 セッション内で 3 点を assert しても検出力は落ちない。
  Browser lane は Chromium + WebKit の 2 レーン契約 + グローバルテストロックで直列化されるため、
  不要な 1 本は実行時間に効く。過剰に作らない（思考原則 2）。
- 対応内容: §E を統合 1 本に書き換え、mutation 表 #2 の参照先も更新した。

## [撤回] Round 1 [Warning] 施策 4: closure 戻り型と PHPStan

- Codex が「反論 4 は妥当。Round 1 の Warning は撤回する」と明言。設計変更なし。
