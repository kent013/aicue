# 対応マトリクス: conceptual-review Round 2

## [Warning] fail-closed な `session_id` でも canonical へ 303 するのかが曖昧
- 判断: **対応する**
- 根拠: 指摘のとおり読み取れる余地があった。意図は「着地 query を認識した時点で必ず畳む」。
  そうしないと「バナーは出ないが URL に session_id が残り続ける」= 次のリロードでまた
  DB を引きに行く状態が残り、one-shot 契約の穴になる。
  `resolveAutoRechargeSetupLanding()` の未追跡 session 分岐 (flash なしで 303) が既存の前例。
- 対応内容: 規約 3 として明文化 (「feedback の有無に関わらず必ず canonical へ 303」)。
  テスト計画に「fail-closed 5 ケースで `Location: /billing` かつ feedback flash なし」を追加。

## [Warning] `highlight` 保持規約が feedback resolver だけか、3 resolver 共通か不明
- 判断: **対応する**
- 根拠: resolver ごとに規約が割れると、まさに今回のような「コメントと実装の乖離」を再生産する。
  現状 `resolveAutoRechargeSetupLanding()` は素の `/billing` へ倒しており `highlight` を落とす。
- 対応内容: canonical URL 構築を **3 resolver 共通のヘルパ**に寄せる方針を明記
  (保持 query の allowlist + 呼び出し側の追加 query のマージ。T1004 は `highlight` を自分で立てる)。
  テストは `?session_id` + `?highlight` と `?setup_session_id` + `?highlight` の 2 本で固定。
  なお両方が同時に来る導線は現状存在しない (Stripe に渡す success_url に `highlight` は含まない)
  ため、これは「規約の統一」であって新機能ではない。

## [Warning] 「直接再訪でも DB 現在値から再導出されるので嘘にならない」は自設計と矛盾
- 判断: **対応する (指摘が正しい)**
- 根拠: 設計内で「`pending` は『決済済み・webhook 待ち』と『Checkout 放棄』を区別できない。
  だからイベント性 (session_id 付きで戻ってきたこと) が根拠だ」と論じている以上、
  イベント性の無い直接再訪で `purchase_processing` を出すのは**嘘になりうる**。矛盾していた。
- 対応内容: 「嘘にならない」を削除し、「残余リスクが残るが、通常導線の修正を優先して受容する」
  に書き換えた。

## [Suggestion] 詳細設計で `error` 判定 → `keep()` → feedback flash の順序を固定する
- 判断: **対応する (詳細設計に持ち込む)**
- 対応内容: 詳細設計の `resolveBillingFeedbackLanding()` 実装で
  (1) 着地 query 判定 → (2) `keep()` → (3) `error` 判定による suppress → (4) kind 解決 → (5) 303
  の順序をコード + docblock で固定する。

## [Suggestion] 使命 / 禁止事項 / スコープ / 型安全性
- 判断: 指摘なし。現状維持。
