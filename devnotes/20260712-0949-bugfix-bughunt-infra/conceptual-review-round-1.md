**全体判定: CHANGES_REQUESTED**

**1. 使命との整合性**
- [Suggestion] 方向性は妥当です。今回の改善はエンドユーザー機能追加ではないものの、`SOP→AI解析→撮影→レンダ` の実走を bug-hunt で再開させるための基盤修正であり、North Star への間接寄与は十分あります。
  修正提案: 成功条件を設計書に明記してください。最低でも `F-05/F-13 が再現しないこと`、`standard 相当ジャーニーが /billing リダイレクトで詰まらないこと`、`bug-hunt 再走で未走行ストーリーに入れること` の 3 点は明文化した方が良いです。

**2. 禁止事項違反**
- [Suggestion] 概念設計の範囲では、禁止事項への明確な抵触は見当たりません。`response()->json()` 直書き、Prism 直呼び、prompt 直書き、dev DB 破壊操作の持ち込みも提案されていません。
  修正提案: 実装時は「テストを先に fail で置く」ことを設計書にも一文入れてください。今回の変更は infra 寄りなので、後からテストを足す運用に流れやすいです。

**3. 実現可能性**
- [Warning] Laravel 12 での実装自体は可能ですが、`scripts/bug-hunt-shard.sh` の `filament:assets` を shard provision に直接入れる案は、同一 worktree 内で複数 shard を並列 provision する場合の競合と起動時間悪化を招きえます。
  修正提案: `assets` は「worktree 単位で 1 回だけ準備するフェーズ」に分離するか、少なくとも存在確認付きで冪等実行し、並列実行時の race を許容する前提を設計書に追記してください。
- [Warning] `SubscriptionCheckoutGateway` 導入は妥当ですが、Controller から「外部遷移先 URL を返すだけ」の責務境界を明確にしないと、fake/real 実装で戻り値の形がぶれやすいです。
  修正提案: gateway は `RedirectDestination` のような専用 DTO/Value Object を返し、`Inertia::location()` は Controller 側に固定してください。

**4. 期待効果の妥当性**
- [Critical] `BughuntBillingSeeder` が「全 Organization に active subscription と初期チケットを付与する」設計は広すぎます。これでは free/standard の差分が bug-hunt 環境から消え、課金ゲートや残高不足まわりの回帰を恒常的に見逃します。`F-07 の環境要因除去` には有効でも、同時に billing 系の不具合検出能力を下げます。
  修正提案: 付与対象は `bug-hunt で S3 を走らせる専用 standard 組織` のみに限定してください。少なくとも free 組織は現状維持にし、`課金あり経路` と `課金なし経路` を同一 bug-hunt 環境内で共存させるべきです。
- [Warning] fake checkout が `cancel_url` に戻る設計は、`外部遷移の成功` と `ユーザーが決済を中断した` を同じ経路に畳んでいます。これでは UI/導線の意味が汚れ、後続の検証結果を誤読しやすいです。
  修正提案: `bughunt` 専用の中立 return route か、少なくとも `success/cancel` を分離した fake 用戻り先を用意してください。「付与しない」と「キャンセル扱い」は別概念です。

**5. リスク**
- [Warning] `config/testing.php` の導入で `BughuntOAuthSeeder` が突然有効化される点は、設計上は副次効果ではなく実質的な挙動変更です。Stripe 修正と OAuth 疑似認証状態の投入が同時に入ると、今後の finding が「どちらの変更で消えたか」を判別しにくくなります。
  修正提案: flag を分離してください。最低でも `fake_externals` と `bughunt_seed_oauth` は別管理にした方が安全です。分離しないなら、変更受容理由と影響範囲を設計書に明記し、回帰テストも追加してください。
- [Suggestion] `ProductionEnvGuard` 強化は良いです。加えて allowlist 外で `TESTING_FAKE_EXTERNALS=true` のときに warning を出す設計にすると、staging 誤設定の調査が楽になります。
  修正提案: fail-fast までは不要ですが、起動時ログか health check で検出できるようにしてください。

**6. スコープの適切さ**
- [Warning] 1 つの設計で `Stripe fake 配線`、`subscription seed`、`admin seed guard`、`Filament assets` をまとめていますが、原因は共通しても変更軸は 3 系統あります。レビューと切り戻しの単位としてはやや太いです。
  修正提案: 少なくとも設計上は `external fake wiring`、`bughunt billing fixtures`、`admin/assets provisioning` の 3 塊に分け、独立に検証可能な順序を示してください。
- [Suggestion] 一方で「bug-hunt を再走可能にする」という目的に対しては、スコープ自体が過少ではありません。広げるより、今の範囲を分割統治する方が良いです。

**7. 型安全性**
- [Warning] 設計書のままだと、新設 gateway の I/F が `string URL` や Laravel Response 直返しになりそうで、PHPStan level 10 では境界が弱いです。fake/real 実装差異も型で縛りきれません。
  修正提案: `SubscriptionCheckoutGateway` / `TicketCheckoutGateway` ともに、戻り値は専用 DTO に寄せてください。Seeder も subscription 作成 payload を private helper か factory メソッドに集約し、配列 shape の重複を避けるべきです。
- [Suggestion] `BillingController` は引き続き薄く保てます。gateway から DTO を受け取り、`Inertia::location($dto->url)` に閉じる形なら DTO/Controller の責務分離も明快です。

主な差し戻し理由は 2 点です。`BughuntBillingSeeder` の適用範囲が広すぎて課金系バグ検出能力を落とすこと、`fake checkout -> cancel_url` が概念的に誤っていることです。この 2 点を修正できれば、全体の方向性は概ね妥当です。