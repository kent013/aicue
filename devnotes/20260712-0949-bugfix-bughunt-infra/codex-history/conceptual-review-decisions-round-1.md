# 対応マトリクス: conceptual-review Round 1

## [Critical] BughuntBillingSeeder の「全 Organization に付与」が広すぎ、課金系バグ検出能力を落とす
- 判断: 対応する
- 根拠: 指摘のとおり free/standard の差分が消えると課金ゲート・残高不足系の探索が恒常的に盲目化する。bug-hunt の目的 (製品バグの検出) に反する。
- 対応内容: 付与対象を**有料プラン (standard) 組織のみ**に限定。free 組織は未契約のまま維持し、「課金なし経路」(billing redirect・チケット購入導線) と「課金あり経路」(業務ルート走行) を同一環境で共存させる。初期チケット付与も standard 組織のみ。概念設計 施策 4 を修正。

## [Warning] filament:assets の並列 provision 競合と起動時間悪化
- 判断: 対応する (一部は事実関係の補足)
- 根拠: 現行構造では並列 fan-out は `provision-all` が shard 1..N を**直列ループ**で provision するため race は発生しない (スクリプト実測)。ただし起動時間と冪等性の指摘は妥当。
- 対応内容: `ensure_filament_assets()` helper を新設し「composer.lock 由来の filament バージョン marker と一致すれば skip」の冪等実行にする (public/build の fingerprint 方式と同じ流儀)。直列前提である事実と、将来並列化する場合は worktree 単位フェーズへ移す旨を設計書に明記。

## [Warning] SubscriptionCheckoutGateway の戻り値境界 (string / Response 直返しのブレ)
- 判断: 対応する
- 対応内容: gateway は専用 DTO (`ExternalBillingRedirect` — readonly `url`) を返す契約に固定。`Inertia::location()` は Controller 側に固定。概念設計 施策 2 に明記。

## [Critical 相当扱い: Warning] fake checkout → cancel_url が「外部遷移成功」と「決済中断」を混同
- 判断: 対応する
- 根拠: 「付与しない」と「キャンセル扱い」は別概念という指摘は正しい。ただし専用 return route の新設は製品ルート面を bughunt 都合で増やすため避けたい。
- 対応内容: fake の契約を「外部ステップを skip した**中立帰還**」と定義し、帰還先はアプリ内画面 (cancel_url 相当の billing 画面) に `fake_external=stripe` の観測用 query marker を付けた URL とする (アプリはこの query を解釈しない = success/purchased 偽装なし、cancel 解釈もなし)。fake クラスの docblock と bug-hunt 向け注記に「決済は行われない・状態は seeder が正本」を明記。製品ルートは増やさない。

## [Warning] config/testing.php 導入で BughuntOAuthSeeder が突然有効化される (flag 分離提案)
- 判断: 一部反論・一部対応
- 根拠 (反論部分): flag 分離 (`bughunt_seed_oauth`) は config 面の増設でオーバーエンジニアリング (思考原則 2)。`BughuntOAuthSeeder` の docblock 自体が「外部 fake 基盤 (config('testing.fake_externals')) 導入後に有効化される」ことを設計意図として明記しており、有効化は意図された挙動。さらに同 seeder は三重 guard (fake_externals ∧ bughunt.local ∧ bug_hunt DB) を持ち、有効化は bughunt DB に閉じる。
- 対応内容 (対応部分): 「本設計の変更で BughuntOAuthSeeder が有効化される」ことを設計書の影響範囲として明記し、guard の no-op 側 (testing env では実行されない) を固定する回帰テストを追加。provision ログに seeder 実行が明示されるため、run 間の因果分離も可能。

## [Warning] スコープが 3 変更軸で太い
- 判断: 対応する
- 対応内容: 設計を 3 施策群に再構成 — A: external fake wiring (config/testing.php + gateway 抽象 + fake bind + ProductionEnvGuard)、B: bughunt billing fixtures (BughuntBillingSeeder)、C: admin/assets provisioning (AdminUserSeeder guard + filament assets)。各群が独立に検証可能な順序 (A→B→C、ただし B/C は A の config にのみ依存) を明記。実装も同順の incremental を推奨。

## [Warning] 型安全性 (gateway I/F の string/Response 直返し・seeder payload 重複)
- 判断: 対応する
- 対応内容: 上記 DTO 化に加え、seeder の subscription 作成 payload を private helper に集約する旨を明記。

## [Suggestion] 成功条件の明記
- 判断: 対応する — 「F-05 3 endpoint が 500 にならない」「standard 組織で /projects が 200」「free 組織は従来どおり billing redirect」「/admin がスタイル付きで表示され admin@example.com でログイン可能」「self-test pass」を成功条件として設計書に追加。

## [Suggestion] テストファースト明記
- 判断: 対応する — 「各施策は fail するテストを先に置いてから実装する」を設計書に追加。

## [Suggestion] allowlist 外での flag=true の warning 検出
- 判断: 対応する — FakeExternalsServiceProvider が「flag=true だが allowlist 外」のとき Log::warning を出す (fail-fast は production の ProductionEnvGuard に限定)。
