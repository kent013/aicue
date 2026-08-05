全体判定: **CHANGES_REQUESTED**

設計の方向性は妥当です。退会時に Stripe API を呼ばず、DB 上の課金責務をガードで止める方針は、二重書き込みを避けられて aicue の現状にも合っています。ただし、述語の核にまだ詰めるべき点があります。

**1. 使命との整合性**

[Suggestion] 使命への貢献は周辺的だが妥当です。課金中組織のオーナー不在は、AI-CUE の主機能そのものではないものの、信頼・継続利用・運営負荷に直撃します。最小ガードで塞ぐ判断は North Star と矛盾しません。

**2. 禁止事項・セキュリティ不変条件**

[Warning] サーバ応答の設計で DTO / Inertia / ValidationException の境界を明記してください。  
`ValidationException(['account' => ...])` 自体は既存作法に沿っていますが、理由付き blocker を返すなら `response()->json()` 直書き禁止に触れない形で、Inertia props 用 DTO とエラー文言生成の責務を分ける設計にしてください。

修正提案: `AccountDeletionBlockerData` / `AccountDeletionBlockerReason` のような PHP DTO/enum を置き、Settings 画面 props と削除時 ValidationException の両方が同じ service 出力を使う、と明記する。

**3. 実現可能性**

[Suggestion] Laravel 12 + Cashier + Svelte 5 + Inertia.js で実現可能です。  
`Billing` 配下に課金責務判定 service を置き、`OrganizationMembershipService` から注入する構成も既存規約に沿っています。

**4. 述語の正しさ**

[Critical] `grantsAccess() かつ ends_at === null` だけでは、`trialing` の扱いが設計上未確定です。  
Stripe の subscription status には `trialing` があり、トライアル終了後に課金へ進む可能性があります。Cashier でも canceled grace period は `subscribed()` が true のまま扱われますが、今回の問いは entitlement ではなく「将来請求を発生させうるか」です。`trialing` が `SubscriptionState::Active` に正規化されるなら問題ありませんが、設計文面だけでは保証が読めません。([laravel.com](https://laravel.com/docs/12.x/billing?utm_source=openai))

修正提案: `SubscriptionState::fromSubscription()` における `trialing` の写像を明記し、V 検証に `trialing, ends_at=null` を追加してください。原則は blocker 対象です。ただし aicue が「trial 終了時に課金ではなく paused に遷移する」設定を明示しているなら、その根拠込みで除外できます。

[Warning] `ends_at !== null` を常に通過させる説明が少し強すぎます。  
Stripe は `cancel_at_period_end=true` の期末解約で subscription を期間満了まで継続させ、満了時に削除イベントを送ります。一方で Stripe 公式は、期末解約でも pending prorations や使用量が期末に請求されうると説明しています。([docs.stripe.com](https://docs.stripe.com/billing/subscriptions/cancel?utm_source=openai))

修正提案: 「aicue の subscription は metered usage / pending proration を発生させない設計であるため、`ends_at !== null` は継続請求責務なしとみなす」と前提を明記してください。前提が成り立たないなら、`ends_at !== null` でも未請求 invoice item / usage を見る別 blocker が必要です。

[Warning] `past_due` を blocker に含める判断は妥当ですが、`incomplete` / `incomplete_expired` の通過根拠をもう少し明記してください。  
`incomplete` は初回支払い未完了で、Cashier/Stripe 上は支払い完了により active 化しうる状態です。設計では `Inactive` として終端扱いに寄せていますが、「ユーザーが消えた後に支払い完了 webhook が来て active 化しないか」の観点が必要です。

修正提案: `incomplete` は「退会操作時点でサービス利用も継続請求も成立していないため blocker ではない。ただし支払い完了導線が退会後に成立しないことを Feature または Billing 側テストで固定する」と補足してください。

**5. リスク**

[Critical] TOCTOU 対策として、退会時のロック範囲に subscription 行の一貫性が含まれるか不明です。  
設計は users→organizations の canonical 行ロック下で再評価すると書いていますが、課金 webhook が同時に `subscriptions.ends_at` / `stripe_status` を更新する可能性があります。組織行だけの lock では subscription 行更新と直列化されない場合があります。

修正提案: 退会時の blocker 再評価では、対象 organization を lock したうえで subscription 行も `lockForUpdate()` 付きで読む、または webhook 側も同じ organization lock を取る契約を明記してください。少なくとも「退会成功直後に webhook で active subscription が復活/確定する」競合をテスト観点に入れるべきです。

[Warning] `/billing` 導線だけでは、複数組織の課金 blocker がある場合に行き先が曖昧です。  
`/billing` が current organization 依存なら、blocker の組織へ切り替えないと解約できない可能性があります。

修正提案: blocker DTO に `organizationSlug` と billing route を持たせ、必要なら `/organizations/{slug}/billing` 相当か、組織切替を伴う導線にしてください。

**6. スコープの適切さ**

[Suggestion] soft delete、保持期間実装、Stripe redaction 自動化を今回外す判断は妥当です。  
現状の穴を塞ぐには不要で、特に soft delete は FK cascade / CipherSweet / blind index / 認証に大きく波及します。

[Warning] ただし「保持期間の実装をしない」は、運用 TODO の作成条件まで含めてください。  
裁定の標準形から外すため、単に docs 追記だけでなく、`docs/TODO.md` へ「規約正式化後に保持期間実装を設計する」を登録するのが自然です。

**7. 型安全性**

[Warning] PHP⇔TS 同期の対象を理由 enum だけに限定すると、props 形状の drift が残ります。  
Svelte 側は理由、組織名、slug、導線、表示文言キーを扱うため、enum 同期だけでは PHPStan/typecheck 両方の保証が弱いです。

修正提案: PHP DTO の配列 shape を固定し、TS 側は生成型または architecture test で `reason`, `organizationName`, `organizationSlug`, `actions[]` まで同期対象にしてください。

結論として、設計思想は承認水準に近いですが、`trialing`、`ends_at !== null` の請求残存前提、subscription 更新との競合、この 3 点を設計に戻してから実装へ進むべきです。