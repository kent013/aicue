全体判定: **APPROVED**

設計方針は AG-105 の必須条件に絞れており、既存の `StrayLlmCallGuard` / 目録型 Architecture gate の形を踏襲する判断も妥当です。アプリコードを変えずにテストレーンの外部 HTTP 出口を default-deny 化するため、スコープも適切です。

## 1. 使命との整合性

[Suggestion] 使命への貢献は間接ですが妥当です。  
「標準化されたマニュアル動画」を支える CI の再現性・外部依存排除の保証を強める改善であり、North Star から逸れていません。

## 2. 禁止事項違反

[Warning] テストファースト方針との整合は、導入順序を明記した方がよいです。  
設計上は自己検査を追加する方針ですが、実装時はまず `StrayHttpRequestGuardTest` の失敗を確認してから guard 実装に入る、と明記してください。

修正提案: 実装方針に「S2 の behavioral test を先に追加し、赤を確認してから S1/S3 を実装する」を追記。

## 3. 実現可能性

[Warning] `Http::globalMiddleware()` を `beforeEach` で毎回積む場合、同一プロセス内で middleware が重複登録されるリスクがあります。  
Laravel の HTTP Factory がテスト間で同一 singleton として残るなら、テスト数に比例して guard closure が積み上がり、同じ stray request が複数記録される可能性があります。

修正提案: `StrayHttpRequestGuard::install()` を冪等にする、または `reset()` で HTTP Factory 側の global middleware 状態まで戻せることを behavioral test で固定してください。少なくとも「同一プロセスで install を 2 回呼んでも 1 件だけ記録される」自己検査を追加すべきです。

[Warning] Architecture lane への配線は、Facade / Application bootstrapping の前提確認が必要です。  
Architecture test が Laravel TestCase 上で動いていない場合、`Http` facade や `Application` 注入に依存する guard install が壊れる可能性があります。

修正提案: Architecture lane で `StrayHttpRequestGuard::install()` が実際に動作することを `StrayHttpEgressLaneGateTest` か小さな behavioral test で固定してください。もし bootstrapping がない lane なら、その lane は「HTTP client を使えないため対象外」ではなく、Laravel app を立てる形に揃える判断が必要です。

## 4. 期待効果の妥当性

[Warning] 「秘密の漏出面の縮小」は妥当ですが、保証範囲を Laravel `Http::` 経由に限定して書くべきです。  
設計末尾で限界は明記されていますが、期待効果の箇所だけ読むと Stripe SDK / AWS SDK / Socialite / ブラウザ fetch まで止まるように見える余地があります。

修正提案: 期待効果の文を「Laravel HTTP client 経由の実 API 到達を止める」に狭めてください。

## 5. リスク

[Warning] 既存の局所 `Http::preventStrayRequests()` 5 箇所を残す判断は許容できますが、`allowStrayRequests()` の上書き挙動に注意が必要です。  
局所テストが独自 allowlist を設定した場合、レーン既定の loopback allowlist を上書きする可能性があります。

修正提案: 既存 5 箇所について「既定 allowlist を壊さないか」を確認し、必要なら局所 `preventStrayRequests()` を削除または `StrayHttpRequestGuard::ALLOWED_URL_PATTERNS` と merge する helper を使う方針にしてください。

[Suggestion] `allowedStrayRequestUrls` のパターンは Architecture gate だけでなく behavioral test でも固定するとよいです。  
特に `127.0.0.1.evil.example` を拒否する負のテストは、この設計の肝なので入れる価値があります。

## 6. スコープの適切さ

[Suggestion] スコープ外の切り分けは適切です。  
資格情報の無効化、fake 消費検出、SDK 直叩き、bug-hunt の別プロセス遮断を今回混ぜない判断は妥当です。

[Suggestion] 「新規 3 本」と書きつつ表では 4 ファイルあります。  
レビュー・実装時の混乱を避けるため、単純に「新規 4 本」へ直してください。

## 7. 型安全性

[Suggestion] アプリの DTO / JsonResource パターンには影響しません。  
変更対象が tests/support と docs 中心であり、HTTP response を新設する設計でもないため、禁止事項 4 には抵触しません。

[Warning] Exemption inventory は enum だけでなく、理由文字列の最小長や対象の完全修飾名を型で縛ると PHPStan level 10 と相性がよいです。  
`GlobalTestLockInventoryTest` 型の配列だけにすると、後から shape が崩れても検出が弱くなる可能性があります。

修正提案: exemption entry を readonly value object か PHPStan shape annotation 付き factory に寄せ、Architecture test 側で `non-empty-string` 相当の検査を持たせてください。