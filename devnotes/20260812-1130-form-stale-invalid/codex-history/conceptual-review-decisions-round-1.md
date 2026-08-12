# 対応マトリクス: conceptual-review Round 1

判定 CHANGES_REQUESTED。Critical 2 / Warning 3 / Suggestion 2。**すべて対応**(反論なし)。

## [Critical] `<form>` 依存の再表示契機では form 外の穴が閉じない

- 判断: **対応する**(機構を変更)
- 根拠: 妥当。実査したところ **65 箇所のうち 12 箇所が `<form>` の外**にあった
  (ScenarioEditor 8 / AutoRechargeCard 2 / PasskeySection 1 / PurchaseTickets 1)。
  そこでは「同じ文言が 2 回返ると永久に隠れる」が閉じない。
- 対応内容: 再表示契機を **Inertia の visit 完了**に変更した。サーバのバリデーションエラーが
  届く経路は Inertia visit しかない (`BillingContactForm` も `router.patch`) ため、
  **form の有無に依存しない普遍的な契機**になる。props (`errorVersion` 等) の追加は不要になった。

## [Critical] `input` だけでは select / checkbox / file を取りこぼす

- 判断: **対応する**
- 対応内容: 抑制契機を **`input` と `change` の両方**にした。実査では包む control は
  Input 39 / Select 10 / Textarea 7 / PasswordInput 7 で、**file は 0 件**、`Select` が 10 件ある。
  テストも control 種別を分けて固定する。

## [Warning] 65 箇所への一括波及は根拠が広すぎる。まず分類せよ

- 判断: **対応する**
- 対応内容: 概念設計に**分類表を実測で追加**した (form 内 53 / form 外 12、control 種別の内訳、file 0)。
  「複数 control を包む FormField で片方を触ると全体が隠れる」については、
  **`error` prop が単数である以上その文言は FormField 全体に対するもの**なので意図どおりと整理した。

## [Warning] 既存 9 箇所の `clearErrors` 撤去は値層依存の証明が未完了

- 判断: **対応する**(実測して条件を満たすことを示す)
- 根拠: 指摘のとおり、`clearErrors` は値そのものを消すので `hasErrors` 等への影響がありうる。
- 対応内容: 実査した — **`form.hasErrors` の参照は 0 件**、`form.errors` の非表示用途も **0 件**
  (Register の termsError / Contact の recaptcha / ConfirmRecentAuth の FormError はいずれも表示派生)。
  よって撤去してよい。詳細設計で 9 箇所を 1 件ずつ再確認する手順を残す。

## [Warning] aria の同期は「再表示が保証される」ことが前提

- 判断: **対応する**(前提を先に満たした)
- 対応内容: 再表示契機を visit 完了にしたことで「戻す契機が無い」状態が構造的に消えた。
  この順序 (再表示の保証 → aria 同期) を設計に明記した。

## [Suggestion] 名前は `staleSuppressed` 系にせよ / テストの契約パターンを増やせ

- 判断: **どちらも対応する**
- 対応内容: 命名方針を「クライアントバリデーションと混同させない」意図とともに明記。
  テストは text / select / submit 後の再表示 / 同一文言の再到着 / form 外 / 複数 FormField の独立性を
  固定する方針を詳細設計へ引き継ぐ。
