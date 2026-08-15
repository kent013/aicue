# 対応マトリクス: conceptual-review Round 4

## [Critical] HTTP 状態と認証ユーザーだけでは正常な PRG と業務ゲート遮断を区別できない / 記録器を遮断 middleware の内側へ置け

- 判断: **対応する (提案どおり設計を変える)**
- 根拠: 指摘が正しい。`require-active-subscription` は業務 route 全体を保護しており
  (AGENTS.md ドメイン規約 4)、契約切れユーザーの POST が 302 で `onboarding.*` へ跳ぶのは
  端ケースではなく構造上頻出する。状態コードの近似を足し続けるより、
  **観測位置を業務遮断 middleware の内側にする**方が単純で正確である。
- 対応内容:
  1. 記録器を web グループの末尾に置き、`bootstrap/app.php` の priority list の鎖の最後
     (`RequireActiveSubscription` → `EnsureAccountNotPendingDeletion` → 記録器) に載せる。
     到達の定義を「認証・テナント境界 404・2FA 強制・メール検証・課金ゲート・退会凍結を
     すべて通過し controller 呼び出しの直前まで到達したこと」と概念設計に明文化した。
  2. 上流が短絡すれば記録器は走らない = 記録が無い = 未実行のまま worklist に残る。
  3. その結果、状態コードの写像から「GET の 3xx を一律 blocked」「`$request->user()` が null なら
     blocked」の 2 つの粗い近似を**削除**できた。残るのは
     2xx→ok / 3xx かつセッションに `errors`→blocked / 3xx その他→ok / それ以外→blocked の 4 行。
  4. **罠を 1 つ明記した**: route middleware の `terminate()` は
     `Kernel::terminateMiddleware` が `gatherRouteMiddleware()` の全件を回すため、
     実際に走ったかに関係なく呼ばれる。したがって `handle()` で目印を立て、
     `terminate()` は目印があるときだけ書く。
  5. 既知の偽陽性として残していた「認証済みのまま契約ゲートで遮断された変更系要求が ok」は
     **消えた**ので、その記述を概念設計から削除した。

## [Critical] 現状のままでは主効果を主張できない / 成功条件の追加

- 判断: **対応する**
- 対応内容: 成功条件 3 を
  「バリデーション不合格のリダイレクト・未認証のリダイレクト・契約ゲートで遮断された変更系要求が
   実行済みにならない (実 HTTP の Feature テストで固定)。記録器が遮断 middleware より内側にあることは
   Architecture テストで固定」へ書き換えた。

## [Warning] 「到達」の境界を明文化せよ

- 判断: **対応する**
- 対応内容: 上記 1 の引用ブロックとして概念設計へ明記した。

## [Suggestion] 順序保証はスコープ拡大ではない

- 判断: 受領。ただし**波及変更が 1 件増える**ことを設計に書いた:
  web グループへ middleware を足すと、解決後の middleware 列に現れるため
  `tests/Architecture/TenantBoundaryOrderingTest.php` の `middlewareShortCircuitInventory()` へ
  「透過 (`false`)」として登録が必要になる (deny-by-default で未分類は fail。
  既存の `BughuntCoverageMiddleware` も同じ形で登録されている)。

## 新たに明記した保証しない範囲

- 記録されるのは **web グループを通る要求だけ**。`api/*` と Filament 管理面 (`/admin`) は
  別スタックのため記録されず、分母に載っていれば未実行側に倒れる (過小申告の方向)。
