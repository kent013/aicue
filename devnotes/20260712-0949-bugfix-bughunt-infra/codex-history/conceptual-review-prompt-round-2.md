# Codex 概念設計レビュー Round 2: bugfix-bughunt-infra

Round 1 の指摘への対応を報告する。全指摘に対応 (一部は根拠を添えて部分反論)。
改訂済み概念設計の変更点を確認し、全体判定を更新してほしい。

## 対応マトリクス

### [Critical] BughuntBillingSeeder の「全 Organization に付与」が広すぎる
- 対応: **修正した**。付与対象を有料プラン (standard) 組織のみに限定。free 組織は未契約のまま
  維持し、「課金なし経路」(billing redirect・残高ゼロ) と「課金あり経路」を同一 bug-hunt 環境で
  共存させる。初期チケット付与も standard のみ。テスト計画にも「free 組織に付与しない」を追加。
- 成功条件にも明記: 「standard 組織で /projects が 200。free 組織は従来どおり billing redirect」。

### [Warning] filament:assets の並列競合・起動時間
- 対応: `ensure_filament_assets()` helper 化。composer.lock の filament バージョンを marker
  ファイルと比較して一致なら skip する冪等実行 (public/build fingerprint 方式と同じ流儀)。
- 事実補足: 並列 fan-out (`provision-all`) は shard 1..N を直列ループで provision するため
  現行構造では race は発生しない (スクリプト実測)。将来 provision を並列化する場合は
  worktree 単位の事前フェーズへ移す旨を設計注記に追加。

### [Warning] SubscriptionCheckoutGateway の戻り値境界
- 対応: gateway は専用 DTO `ExternalBillingRedirect` (readonly string $url) を返す契約に固定。
  `Inertia::location()` は Controller 側に固定。設計書に明記。

### [Warning] fake checkout → cancel_url が成功と中断を混同
- 対応: fake の契約を「外部ステップを skip した**中立帰還**」と再定義。帰還先はアプリ内画面
  (チケット: billing.tickets.show、サブスク: billing.index、portal: return_url) に観測用 query
  marker `fake_external=stripe` を付けた URL。アプリはこの query を一切解釈しない
  (= purchased 偽装なし・cancel の意味付けもなし)。決済・付与・状態変更は一切行わず、
  走行状態の正本は seeder。
- 専用 return route の新設は行わない (bughunt 都合で製品ルート面を増やさないため)。
  query marker で「外部 skip」が bug-hunt ログから観測できるため、専用 route が担う
  役割 (中立帰還の識別) は満たされる。

### [Warning] config/testing.php 導入による BughuntOAuthSeeder の突然の有効化 (flag 分離提案)
- 部分反論: flag 分離 (`bughunt_seed_oauth` 等) は行わない。理由:
  (1) BughuntOAuthSeeder の docblock 自体が「外部 fake 基盤 (config('testing.fake_externals'))
  が未導入のテンプレートでは第 1 ガードで常に no-op になり、導入後に有効化される」と明記して
  おり、有効化は**設計意図そのもの** (突然ではなく予定された点火)。
  (2) 同 seeder は三重 guard (fake_externals ∧ bughunt.local ∧ DB 名 ^bug_hunt(_[1-8])?$) を持ち、
  有効化の影響は bughunt DB に閉じる (dev/prod に波及しない)。
  (3) flag 追加は「今必要なものだけ作る」原則に反する config 面の増設。
- 対応部分: 設計書の施策 1 に「影響範囲の明示」として本挙動変更を明記し、guard の no-op 側
  (testing env では実行されない) を固定する回帰テストを追加する。provision ログに seeder 実行が
  明示されるため run 間の因果分離も可能。

### [Warning] スコープが 3 変更軸で太い
- 対応: 施策群 A (external fake wiring) / B (bughunt billing fixtures) /
  C (admin/assets provisioning) に再構成。B/C は A の config 新設にのみ依存し、
  実装・検証順序 A→B→C を明記。

### [Warning] 型安全性 (gateway I/F・seeder payload)
- 対応: 上記 DTO 化に加え、seeder の subscription 作成 payload を private helper に集約
  (billable relation 経由で organization_id は FK 自動設定 = guarded 不侵) を明記。

### [Suggestion] 成功条件の明記 → 対応済み (設計書に「成功条件」節を追加、5 項目)
### [Suggestion] テストファースト明記 → 対応済み (「fail するテストを先に置く」を明記)
### [Suggestion] allowlist 外 flag=true の warning → 対応済み (FakeExternalsServiceProvider が
  Log::warning。fail-fast は production の ProductionEnvGuard に限定)

## 改訂後の概念設計の主要変更点 (全文は devnotes/20260712-0949-bugfix-bughunt-infra/conceptual-design.md)

1. 「成功条件」節を新設 (F-05 3 endpoint 非 500 / standard 組織 /projects 200 /
   free 組織は billing redirect 維持 / admin ログイン可 / test・phpstan・self-test pass /
   production 不変)
2. 施策を 3 群 (A/B/C) に再構成、実装順序と依存を明記
3. 施策 2: `ExternalBillingRedirect` DTO を追加、Inertia::location は Controller 固定
4. 施策 3: fake の契約を「中立帰還 + fake_external=stripe marker + 状態変更なし」に再定義。
   allowlist 外 warning ログを追加
5. 施策 4: 付与対象を standard 組織のみに限定 (free は現状維持)。payload helper 集約
6. 施策 6: `ensure_filament_assets()` 冪等 helper 化 (バージョン marker で skip)
7. テスト計画に BughuntOAuthSeeder guard no-op 回帰テスト・free 非付与検証を追加

全体判定 (APPROVED / CHANGES_REQUESTED) を更新してほしい。残る Critical/Warning があれば指摘を。
