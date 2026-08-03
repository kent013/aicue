**全体判定: CHANGES_REQUESTED**

**1. 使命との整合性**
- [Suggestion] 問題設定自体は妥当です。既存有償契約者が上位プランへ進めず、quota 増加導線が死んでいるのは、North Star の「現場が自力で標準化動画を増やせること」を間接的に阻害します。
- [Warning] ただし、使命に直結する最小成功条件は「既存有償契約者が upgrade できること」であって、「アプリがプラン変更 UX を完全所有すること」ではありません。現設計は後者まで一気に取りに行っており、成功条件が少し広いです。  
  修正提案: 設計の成功条件を `upgrade 導線の即時回復` に縮め、`app-owned な full paid-to-paid change` は 2 段階目に切り分けてください。

**2. 禁止事項違反**
- [Suggestion] 案3の「disabled で隠す」却下は妥当です。禁止事項 #8 と整合しています。
- [Warning] 一方で、「暫定 UI 対応を一切入れない」判断は別問題です。案1の実装が先になる間、現状の虚偽 CTA と循環エラーを放置するなら、Critical を出し続けます。  
  修正提案: 案1を採る場合でも、先行して `文言修正 + 明示的な代替導線` を入れるか、もしくは Phase 0 として Portal 再開放を置いてください。

**3. 実現可能性**
- [Suggestion] 案1自体は技術的には十分実現可能です。単一 subscription item、seat なし、schedule 作成経路なし、trial/campaign なしなので、`subscriptions->update` ベースの swap は Laravel/Cashier/Stripe のレンジ内です。
- [Warning] ただし `FakeStripeGateway` を no-op にする案は弱いです。bug-hunt / Browser テスト系で「変わったこと」を観測できず、発見チャネルで回帰防止しにくくなります。  
  修正提案: fake 側でも `price 変更 -> webhook 同期相当` を模擬するか、少なくとも `changePlan` 後に webhook 注入できる明示的なテスト手順を設計に含めてください。

**4. 期待効果の妥当性**
- [Suggestion] 「循環案内の解消」「quota 増加導線の回復」は合理的に期待できます。
- [Warning] ただし「BILL-02 の回復」を user-visible にどう判定するかが弱いです。`organizations.plan_code` を webhook only writer にする以上、成功直後に画面が旧プランのまま残る時間帯があります。  
  修正提案: 変更受付後は `反映待ち` の flash/banner を明示し、再送時の案内文も設計に入れてください。

**5. リスク**
- [Critical] **案2却下の根拠が現状では弱いです。** この設計自身が、Portal を封じた元理由（`plan_code / schedule 整合`）が AI-CUE ではほぼ失効していることを立証しています。そのうえで「第2の真実源が生えるから案1より重い」と結論づけていますが、ここは説得力が足りません。`PortalConfigurationTest` と ensure コマンドが既にあるなら、Portal 設定の drift 管理は“新しい種類の複雑性”ではあっても、“full in-app mutation stack より重い”とはまだ言い切れません。  
  修正提案: 次のどちらかに直してください。  
  1. **Phase 0 で Portal 再開放**して paid-to-paid の変更経路を即時復旧し、**Phase 1 で案1**をやる。  
  2. それでも案2を却下するなら、`Stripe Portal では満たせない必須要件` を具体化してください。たとえば「downgrade 時の上限低下警告を必須表示したい」「plan ごとの許可遷移を app 側で厳密制御したい」「Portal では AI-CUE の manageBilling/組織スコープ契約を崩す」など、**機能要件として不足**を示す必要があります。今の却下理由だと「今必要なものだけ作る」に対する反証として弱いです。
- [Warning] downgrade を day 1 で同時に入れるのは、observed critical の修復範囲を少し超えています。`既存データは残るが上限内に戻るまで作成/アップロード不可` はデータ損失ではないものの、利用者を能動的に詰ませるリスクがあります。  
  修正提案: 最小案としては `upgrade 先行` を本命にし、downgrade は `現使用量 vs 新上限` を出せる段階まで後ろへずらすか、少なくとも downgrade 専用の強い確認文言を必須にしてください。

**6. スコープの適切さ**
- [Critical] **案1は“最終形としては妥当”でも、“今この Critical を閉じる最小案”としては過大に見えます。** `Gateway interface 拡張 + Service + Request + Controller + Route + DTO + Svelte 分岐 + docs/tests` は筋は良いですが、案2が成立しうる以上、現文書は「なぜ P0 を飛ばして P1 を直接やるのか」を証明できていません。  
  修正提案: 設計を `P0/P1` に分けてください。  
  `P0`: 変更経路の復旧（Portal 再開放、または upgrade-only の極小 in-app）  
  `P1`: app-owned paid-to-paid swap、downgrade UX、厳密な警告文言  
  これならオーバーエンジニアリング懸念が消えます。
- [Suggestion] もし single-phase を維持するなら、少なくとも「なぜ upgrade-only では足りないか」を一段追加で書くべきです。

**7. 型安全性**
- [Suggestion] `ChangePlanRequest` + `BillingPlansPageDto` + `resources/js/types/billing.ts` の exact 対応は良いです。DTO/JsonResource パターンにも沿っています。
- [Suggestion] `current_plan_code` を stale 検知専用・非認可用途と明記している点も妥当です。PHPStan level 10 は十分狙えます。
- [Warning] ただし stale 検知が client hint 依存である以上、例外分類と flash 文言の責務分離は明確にした方がよいです。  
  修正提案: `current_plan_code` 不一致は UX 用の `StalePlanChangeException` に限定し、実際の変更可否判定は必ず server-side の subscription 状態だけで閉じることを設計上さらに明記してください。

**総括**
案1そのものは不自然ではなく、AI-CUE の現状制約なら実装可能です。ただし現文書の最大の弱点は、**案2を十分に潰し切れていないのに、app-owned な案1を即採用していること**です。  
この Critical を閉じる観点では、私は次の最小案を推奨します。

1. `P0`: Portal の `subscription_update` を paid tiers 限定で再開放し、dead-end を即時解消する。  
2. `P1`: それでも app-owned UX が必要なら、案1の in-app swap を後続で入れ、Portal は再度閉じるか補助導線に戻す。

もし product 方針として「最初から最後までアプリ所有でなければ不可」が明示されるなら、案1採用でもよいです。ただしその場合でも、**案2を却下する理由を“運用コスト感”ではなく“満たせない必須要件”で書き直す**べきです。