# 対応マトリクス: conceptual-review Round 2

## [Critical] §4.4 のロック保証が不正確 (組織行ロックは subscription の書き込み開始を妨げない)
- 判断: **対応する** (指摘は正しい。記述を訂正し、スコープを 1 つ増やす)
- 根拠: 指摘の順序 (退会が org 行をロック → webhook が subscription を書き始める → webhook が org 更新で待つ →
  退会は未コミットの subscription を読めないまま commit) は成立する。旧 §4.4 の「残存窓は秒〜分」は過小評価だった。
  ただし共通排他を入れても穴は閉じない: **subscription 行を新規作成するのは Cashier の `WebhookController` (vendor)** で、
  自前の `StripeWebhookProcessor` (WebhookReceived 購読) の外側にある。自前経路にだけ advisory lock を足しても
  作成経路は覆えず、完全防止には vendor の webhook 受信を差し替える必要がある
  (= セキュリティ不変条件「課金の冪等性」の中心を触る大改修)。
- 対応内容:
  1. §4.4 を「守れないこと」を含めて書き直し、指摘の順序をそのまま記載。
  2. 「新規発生を止める deny-by-default」という誇張表現を削除し、
     本機能は「**構造的に起きていた確定的な穴を塞ぐ**もので、webhook 同時実行の競合まで含めた完全防止ではない」と明記。
  3. Codex の提案どおり **検知を本機能の完了条件へ昇格** (§6.1-5)。Owner 不在かつ生きた課金責務のある組織を
     daily で検知して `report()` する artisan コマンドをスコープに入れる (改修前から存在する孤児組織も拾える)。

## [Warning] 発生確率だけで受容するには損失が大きい / 検知は予防の代替にならない
- 判断: **対応する** (上記 3 と同じ。検知をスコープ内へ)
- 根拠: 同上。予防 (ガード) と検知 (バッチ) の 2 枚構成として設計に明記した。

## [Warning] 検証コマンドが AGENTS.md の正本より不足
- 判断: **対応する**
- 対応内容: §7 を `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` まで含めて同期。

## [Warning] 組織切替の失敗時挙動と認可条件が未定義
- 判断: **対応する**
- 根拠: `POST /organizations/{slug}/switch` は `MembershipScopedOrganizationBinder` が membership スコープで解決し、
  非所属・不在は等しく 404 (存在秘匿)。成功時のみクライアントが `/billing` へ進む。
- 対応内容: §4.2 に「サーバが権威。失敗時は遷移せずその場に留まる」を明記。検証に V13 (非所属 slug → 404) を追加。

## [Warning] `incomplete` の根拠が保証として強すぎる / 非同期決済の有無を前提に固定せよ
- 判断: **対応する** (判断は通過のまま、根拠を比較衡量に弱める)
- 根拠: 実査。`CashierStripeGateway::buildSubscriptionSessionPayload()` は `payment_method_types` を指定せず、
  有効な決済手段は Stripe ダッシュボード設定に委ねている。よって非同期決済を排除できない。
- 対応内容: §4.1 の根拠を「保証」から「確実な解消導線が無いままブロックする害の方が大きい」という比較衡量に書き換え、
  決済手段の前提と「非同期決済を有効化するなら本判断を再確認する」条件を明記 (docs にも書く)。

## [Warning] `isCurrent` は時間で変化する派生値。判別共用体にせよ
- 判断: **対応する** (提案どおり action の判別共用体にする)
- 根拠: 妥当。`isCurrent` はドメイン事実ではなく表示時点のヒントで、DTO に boolean として置くと陳腐化する。
- 対応内容: wire に載せるのを理由 enum から **action enum**
  (`transfer_ownership` / `open_billing` / `switch_organization_then_open_billing`) に変更。
  理由 enum はサーバ内部の語彙として `ValidationException` 文言生成に使う。
  TS 同期対象も action enum 1 本に統一し、フロントは判別共用体で網羅分岐する。

## [Suggestion] 使命整合 / 述語の整理 / live pending checkout 非ブロック / スコープ外 3 点
- 判断: 追加対応なし (Codex も妥当と判定)
