全体判定: **CHANGES_REQUESTED**

**1. 使命との整合性**
- [Suggestion] 方向性は概ね妥当です。決済を North Star の本体ではなく支持基盤と位置付けている点は正しいので、成功指標も `業務ルート到達率`、`billing 起因の離脱率`、`残高切れ停止件数` に限定しておくべきです。

**2. 禁止事項違反**
- [Suggestion] 現時点で明白な違反はありません。ただし P3/P4 の DoD に `回帰テスト先行`、`既存課金テストは削除せず期待更新`、`DTO/JsonResource 経由` を明記してください。ここが曖昧だと実装段階で禁止事項 1/4 に触れやすいです。

**3. 実現可能性**
- [Critical] **P3 を P5/P6 より先に置く順序では、F-07 再発防止の論拠が既存ユーザーにしか成立していません。** `plan_code IS NULL` の既存 org は backfill で救えても、新規登録者には `activate-personal` 導線がまだ存在しません。`各フェーズ単独でマージしても課金が壊れない` を満たしていません。  
  修正提案: `activate-personal` と `billing-required` の最小導線を P3 の前提として前倒ししてください。実務上は `P5/P6 の最小集合 -> P3`、または `P3 に最小 onboarding を同梱` が必要です。
- [Critical] **P1 の declarer 単位 unique と、P3 の `plan_code IS NULL -> free_plan_code='personal'` 一括 backfill の整合が未定義です。** 同一 declarer が複数 org を持つ既存データがあると、migration failure か締め出しのどちらかになります。  
  修正提案: 概念設計の時点で `grandfathering` を定義してください。少なくとも `単独 org`、`複数 org`、`declarer 不在/曖昧` の 3 類型に分け、survivor 選定と一時救済を決めるべきです。unique index も `legacy_grandfathered` を除外してから最終収束させる方が安全です。
- [Warning] Laravel/Cashier/Svelte での実装自体は可能ですが、P4 の置換規模は `各フェーズ単独で merge safe` の証明がまだ弱いです。  
  修正提案: P4 は少なくとも `残高移行/検証` と `書き込み切替` の成立条件を事前に分けて定義してください。

**4. 期待効果の妥当性**
- [Warning] `濫用防止の獲得` は新規データには有効でも、既存 `plan_code IS NULL` を grandfathering するなら即時には全面成立しません。効果の書き方が少し強いです。  
  修正提案: `新規 org から先に防止、既存 org は grandfathering 解消後に収束` と書き換えてください。
- [Suggestion] `無料導線の明示化` は合理的に期待できます。`billing への説明なし遷移率` と `activate-personal 完了率` を success metric に置くと検証可能です。

**5. リスク**
- [Critical] **F2 反転時の signup grant 正規化が未閉塞です。** 既存ユーザーは登録時 grant を既に受けているため、`personal 有効化時 grant` へ切り替えると、二重付与か未付与が起きえます。これは実装詳細ではなく、金銭ドメインの概念設計上の欠落です。  
  修正提案: P4 前提として `legacy grant satisfied` を表す冪等状態を先に設計してください。既存 org には履歴から seed し、`backfill/free 移行は grant を発火しない`、`真に新規の personal activate だけが grant を発火する` を明文化すべきです。
- [Warning] `plan_code IS NULL` を移行条件の中心に置くのは proxy が粗いです。null は「fallback free」以外の壊れた中間状態を含む可能性があります。  
  修正提案: backfill 条件は nullable column ではなく `effective entitlement snapshot` で判定してください。最低でも `active sub なし`、`cancel/grace`、`既存付与履歴`、`owner 状態` を加味した分類表が必要です。
- [Warning] P7 で `オートリチャージ + 課金 UI + 判断不要 15 件` を一括処理すると、P4 直後の会計安定化確認がぼやけます。  
  修正提案: P7 は `オートリチャージ` と `UI parity` を分けるか、少なくともリコンサイル系を先に出してください。

**6. スコープの適切さ**
- [Warning] 7 フェーズ分割の方向は正しいですが、順序が一部逆で、P4/P7 はまだ大きいです。現状のままでは `各フェーズ単独 merge safe` を満たしにくいです。  
  修正提案: 順序を `P1 -> P2 -> P5/P6 最小導線 -> P3 -> P4 -> P7` に組み替えるのが妥当です。P4 は金銭移行に集中させ、LP 文言修正は F2 切替と同じ PR に残してください。
- [Suggestion] `feature flag 要否` を詳細設計送りにしていますが、少なくとも `P3 gate flip` と `P4 accounting cutover` は flag か即時 rollback 手順のどちらかを概念設計で確定した方が安全です。

**7. 型安全性**
- [Warning] `plan_code` と `free_plan_code` の二軸をそのまま散らすと、Controller/View 側で string 分岐が増え、PHPStan level 10 での保証が弱くなります。  
  修正提案: `PlanCode` backed enum を唯一のコード表現にし、アクセス判定は raw column ではなく `SubscriptionSnapshot` か `EffectivePlan` DTO に集約してください。Inertia props も DTO/JsonResource からのみ出すべきです。
- [Suggestion] `activated personal`、`paid subscription`、`grandfathered legacy free` をサービス境界で明示的に型分離すると、移行期の分岐バグをかなり減らせます。

最大の論点は 3 つです。`P3 の順序`, `既存 null org の grandfathering と unique 制約の両立`, `signup grant の冪等移行`。ここが閉じない限り、F-07 再発防止と金銭ドメイン移行の安全性を承認できません。