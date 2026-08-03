# 対応マトリクス: conceptual-review Round 1

## [Critical] 案 2 (Portal 再開放) の却下根拠が弱い。Phase 0 で Portal を開ける選択肢を潰し切れていない

- 判断: **反論する (根拠を追加して却下を維持)**
- 根拠: レビューは「Portal 設定の drift 管理は新しい種類の複雑性ではあるが、full in-app mutation
  stack より重いとは言い切れない」としているが、**Portal 側のコストを過小評価している**。
  リポジトリを確認した事実:
  1. Stripe の `billing_portal/configurations` は `subscription_update` を有効化する場合
     `products: [{product, prices: [...]}]` の列挙が必須で、**product id** が要る。
     AI-CUE は product id を**どこにも保持していない**
     (`database/migrations/2026_06_11_091100_create_plans_tables.php:46-47,71-72` =
     `plan_prices` は stripe_price_id / lookup_key / amount / currency / is_current のみ)。
  2. 価格改定は `PlanPriceService::replaceCurrent()` が旧 current 行を残したまま
     新 current を差し込む。Portal の列挙を同時更新しない限り **旧価格 Price へ Portal から
     移行でき、しかも `resolvePlanCodeFromPriceId` は plan を解決してしまうため気づけない**
     = 課金金額の真実源が二重化する (正しさの欠損であって運用感覚ではない)。
  3. `plans.is_active=false` (販売停止) は Portal 列挙に効かない = 停止済プランへ移行可能。
  4. 既存の drift 検知 `billing:ensure-portal-configuration --verify` は
     `subscription_update.enabled === false` しか見ない (`EnsurePortalConfiguration.php:52-58`)。
     products 列挙の drift 検証は**新設が必要**。
  さらに Phase 0 → Phase 1 の段階案は、作った機構を次フェーズで消すことになり
  **二重経路の並走** (思考原則 3 違反) を自ら作る。
- 対応内容: conceptual-design.md の案 2 節を「(a) 課金金額・販売可否の真実源が二重化する
  (機能要件の不足) / (b) 拒否理由・上限低下の告知を出せない (禁止事項 #8 の規約が適用不能・
  解約ボタンと同居) / (c) `/billing/plans` の分断 / (d) 宣言との整合」に書き直し、
  Phase 0 案を明示的に却下する段落を追加した。

## [Critical] 案 1 は「今この Critical を閉じる最小案」としては過大。P0/P1 に分けるべき

- 判断: **反論する (ただし成功条件の明文化は取り込む)**
- 根拠: P0 候補は 2 つしかなく、どちらも最小ではない。
  - P0 = Portal 再開放 → 上記のとおり最小ではない (新しい列 + 同期 + drift 検証)。
  - P0 = upgrade-only の極小 in-app → **コードはむしろ増える**。swap 実装は方向に依存せず、
    upgrade 限定にするには「目標プラン金額 > 現在プラン金額」の判定を**追加**する必要がある。
    かつ「間違って上位にした組織が戻れない」= F-3-01 と同種の行き止まりを新造する。
  案 1 の実体は「gateway 1 メソッド + service 1 メソッド + route/Request/Controller の薄い層 +
  Svelte の送信先分岐」であり、席・schedule・pending_plan_code・月次付与差分が無い AI-CUE では
  aigenba 版から重い部分がすべて落ちる。
- 対応内容: 「成功条件」節を新設し (upgrade/downgrade の双方向完了 / 循環案内の消滅 /
  反映待ちの可視化)、**「アプリがプラン変更 UX を完全所有すること」は目的ではない**と明記。
  「upgrade だけ先に通す」縮小案を検討して却下した節を追加した。

## [Warning] 成功条件が広い (app-owned full change まで取りに行っている)

- 判断: 対応する
- 対応内容: 上記「成功条件」節を追加し、判断基準を 3 点に限定した。

## [Warning] 案 1 実装までの間、虚偽 CTA と循環エラーを放置するのか

- 判断: **反論する**
- 根拠: 本設計は 1 つの TODO として実装される (worktree 1 本)。暫定 UI を先行投入すると
  同じ画面を 2 回書き換え、その間だけ存在する文言・導線を作ることになる (思考原則 3)。
  暫定策の「行き先」が存在しないという案 3 の却下理由もそのまま残る。
- 対応内容: 変更なし (案 3 節に理由が記載済み)。

## [Warning] `FakeStripeGateway` を no-op にすると変化を観測できない

- 判断: 対応する
- 対応内容: 「fake 環境での検証方針」を制約節に追加。fake は「実 Stripe を呼ばない」表明に留め、
  **swap → `customer.subscription.updated` payload 注入 → `organizations.plan_code` 追随**を
  webhook 注入テスト (既存 `SubscriptionSnapshotSyncTest` と同型) で固定する。bug-hunt 環境では
  webhook が発火しないため観測範囲は「反映待ち」表示までであることも明記。

## [Warning] BILL-02 回復の user-visible 判定が弱い (webhook 反映までのラグ)

- 判断: 対応する
- 対応内容: 成功条件 3 に「反映待ちが判断できること」を追加。実装方針 5 に flash 文言
  (「プラン変更を受け付けました。反映まで数分かかる場合があります。」) と、
  反映前の再押下が同一 idempotency key に収束する不変条件を明記。

## [Warning] downgrade を day 1 で同時に入れるのは修復範囲を超える / 強い確認文言を必須に

- 判断: **一部対応 (downgrade は day 1 に含める。確認文言は必須化する)**
- 根拠: downgrade を落とすと実装が増え、逆方向の行き止まりが残る (上記 Critical 参照)。
  quota は作成時チェックのみでデータ損失が無く、解約経路では既に同種の状態が起こりうる。
- 対応内容: 実装方針 4 を「新規契約 / upgrade / downgrade の 3 通りの確認文言」に変更し、
  downgrade では上限低下の告知を**必須表示**にした。

## [Warning] stale 検知が client hint 依存である点の責務分離

- 判断: 対応する
- 対応内容: 制約節に「`current_plan_code` は UX 専用。可否・対象の決定はサーバが読んだ
  subscription 状態と plan 台帳だけで閉じる」を追記。

## [Suggestion] 各種

- 判断: 見送り / 反映済み。型安全性・DTO 対応の指摘は既に設計に含まれている。
