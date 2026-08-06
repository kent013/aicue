**全体判定: CHANGES_REQUESTED**

概念の方向性は妥当です。特に「貼り忘れを CI で赤くする」ことは、この feature の名前どおりの価値があります。ただし、webhook の全体天井と、目録検査の実装前提に修正が必要です。

**1. 使命との整合性**

[Suggestion] 認証面・webhook 面の流量制限は、AI-CUE の中核機能ではなく事業継続の前提です。§7 の説明は妥当です。  
ただし「標準化された動画作成」そのものへの寄与ではなく、「SOP・動画・アカウント保護の基盤」として位置づける表現のほうがより正確です。

**2. 禁止事項・セキュリティ不変条件**

[Critical] webhook に「IP + 固定キー全体天井」を middleware として貼る設計は、新しい DoS 口になります。  
署名検証前に固定キー全体天井を消費させると、攻撃者が無効リクエストでバケットを枯らし、正当な Stripe / SES 通知を 429 にできます。これはレビュー観点 5 の「throttle を貼ったことで新たな DoS が生まれないか」に該当します。

修正提案: webhook の署名前 middleware では、少なくとも固定キー全体天井を外し、IP 単位の高めの天井に限定してください。全体天井が必要なら、署名検証成功後にだけ消費される位置に分離する設計にしてください。SES の証明書取得コスト対策と、正当 webhook の巻き添え停止対策を同じ limiter で解かないほうがよいです。

[Warning] `storage.local.upload` を exemption に留める判断は、理由が「production は S3 presigned」だけだと弱いです。  
route が production に存在するなら、署名付き URL を持つ攻撃者や流出 URL による local disk 書き込み DoS は残ります。

修正提案: exemption 理由に「production で route が存在しない / local driver 時のみ / signed middleware 必須」などの機械的前提を含め、可能なら Architecture テスト側でもその前提を検査してください。

**3. 実現可能性**

[Warning] `route:list --json` を検査の正本にするのは脆いです。  
Laravel の JSON 出力は middleware が alias / 文字列 / 展開済み class のどれで見えるかに依存しやすく、`ThrottleRequests` の検出が環境差で揺れます。

修正提案: Architecture テストは `Router::getRoutes()` と `Router::gatherRouteMiddleware($route)` を使い、実効 middleware を Laravel の Router から直接取得してください。`route:list --json` は設計時の実査・デバッグ用に留めるのが安全です。

[Warning] 後付け binder の実行タイミングが未確定です。  
Fortify / Cashier / Passport の route 登録後に確実に走らないと、正しい route が存在していても fail-fast します。route cache 時の挙動も固定が必要です。

修正提案: `app()->booted()` など、vendor route 登録後であることが明確な場所に寄せ、`route:cache` 生成時・利用時の Feature/Architecture テストを 1 本追加してください。

**4. 期待効果の妥当性**

[Suggestion] 期待効果は妥当です。特に「0 本」と「2 本以上」を同じ検査で落とす設計はよいです。  
ただし webhook の固定全体天井を残すと、期待効果より副作用が上回るため、そこは修正前提です。

**5. リスク**

[Critical] webhook の「全体天井」は、攻撃者に正当通知を止める手段を与えます。  
「Stripe / SNS は再送する」ことは恒久喪失の緩和にはなりますが、攻撃中に課金同期・バウンス処理を遅延させられる点は残ります。

修正提案: 前述のとおり、署名前は IP 単位に限定し、固定全体天井は署名成功後に移すか、本 feature から外してください。

[Warning] `POST /register` や `POST /forgot-password` の IP 単独 5/min は、同一 NAT の展示会・現場 Wi-Fi で巻き添えが起きやすいです。  
閾値を変える議論は射程外でよいですが、リスクとしては明記が必要です。

修正提案: 「既存 `inquiry` と同値なので採用」だけでなく、「将来の観測対象メトリクス」と「429 契約 feature 側での UX 救済」を TODO に接続してください。

**6. スコープの適切さ**

[Warning] §4C と §6-2 に軽い矛盾があります。  
§4C では「Fortify / Cashier / Passport が登録する vendor route への付与はすべて helper を通す」とありますが、§6-2 では DCR と Passkey の既存後付け統合はしないとしています。

修正提案: 「本 feature で新たに追加する後付けは helper を通す。既存 DCR / Passkey 後付けは今回は触らない」と明記してください。

[Suggestion] 429 応答契約、trusted proxy、秘密 GET の step-up を外す判断は妥当です。特に秘密 GET は throttle だけで済ませると本質的な step-up 不足が隠れるため、別課題扱いが適切です。

**7. 型安全性**

[Warning] `RateLimiterKeyConventionTest` の「登録済み limiter を列挙」は、Laravel に安定した public registry API がない場合、Reflection か自前 inventory が必要になります。Reflection で `mixed` を扱うと PHPStan level 10 で雑になりやすいです。

修正提案: `RateLimiterName` enum などの明示 inventory を正本にし、定義側・テスト側が同じ enum を参照する形を検討してください。Reflection を使うなら、戻り値型を `Closure(Request): Limit|array<int, Limit>` へ明示的に assert する helper を挟んでください。

**8. 目録検査の母集団セレクタ**

[Warning] `ThrottleRequests` の検出は class 名固定だと将来 `ThrottleRequestsWithRedis` などで false positive になります。  
現状 database cache 前提でも、検査の意味は「Laravel throttle middleware が実効列に 1 本」なので、実装 class の一点固定は避けたほうがよいです。

修正提案: alias 展開後の class が `ThrottleRequests` 系であること、または middleware 文字列が `throttle:` / `Illuminate\Routing\Middleware\ThrottleRequests` / Redis 版を含むことを正規化 helper で判定してください。

[Suggestion] S1/S2/S3 の構造セレクタは概ね妥当です。floor と stale exemption を入れる方針もよいです。  
追加で、母集団件数だけでなく「exemption 件数の上限」または「exemption 追加時に enum 差分が必ず出る」形にしておくと形骸化しにくいです。