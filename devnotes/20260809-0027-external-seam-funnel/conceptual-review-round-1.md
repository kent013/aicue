全体判定: **CHANGES_REQUESTED**

**1. 使命との整合性**
[Warning] 間接貢献としては妥当です。bug-hunt を安全に回せる基盤は North Star に資します。  
ただし、SSO については「実 Google へ出る」状態を残すため、「安心して回せる」という期待効果はやや過大です。  
修正提案: 期待効果を「新到達点の検知」に絞るか、bug-hunt で SSO ボタンを押しても外部へ出ない設計まで含めてください。

**2. 禁止事項違反**
[Warning] 直接の禁止事項違反は見えません。  
ただし `testing.fake_externals` のコメントが SSO fake を含むと言っている一方、設計では SSO fake を作らないため、名前・説明と実態がズレます。  
修正提案: コメントから SSO を外す、または SSO は「fake ではなく未遮断・目録管理のみ」と明記してください。

**3. 実現可能性**
[Warning] Laravel 12 + Svelte 5 + Inertia.js で実現可能です。  
ただし `Socialite::driver()` の検出だけでは、`config/template.php` の `social_providers` 追加を捕捉できません。外部ログインの実到達先は controller 呼び出しだけでなく provider 設定にも依存します。  
修正提案: `social_providers` の enabled provider 集合も gate の母集団に含め、provider ごとに inventory 登録させてください。

**4. 期待効果の妥当性**
[Critical] 「新しい外部到達点が登録なしでは CI を通らない」は、SSO について成立しません。`SocialAuthController` が 1 回登録済みなら、Google 以外の provider を config に増やしても目録差分が出ない可能性があります。  
修正提案: 外部ログインは `Socialite::driver()` 呼び出し地点だけでなく、許可 provider 名の列挙を deny-by-default にしてください。

**5. リスク**
[Warning] `->stripe()` 検出を「同一ファイルに Laravel\Cashier / Stripe 参照がある場合」に絞る設計は、偽陽性を減らす一方で偽陰性リスクがあります。`Organization` の `Billable` 経由で `$organization->stripe()` を呼ぶファイルに Cashier/Stripe import が無い場合、見落とします。  
修正提案: 既存 23 site すべてが検出されることを fixture ではなく実コード走査の expected set で固定し、`->stripe()` site の見落としが 0 であることを gate に含めてください。

**6. スコープの適切さ**
[Warning] SSO fake を作らない判断はスコープ抑制として理解できますが、今回の発端が bug-hunt の実 Google 到達である以上、未遮断のまま「標準形 v1 達成」と言い切るのは弱いです。  
修正提案: 今回は「検知 v1」と位置付け、SSO 遮断は明示 TODO に落とす。もしくは bug-hunt 環境では SSO provider を表示しない/無効化する最小策を入れてください。

**7. 型安全性**
[Suggestion] enum 化、typed inventory、DTO/JsonResource 非関係の architecture test 中心なので、PHPStan level 10 との相性は良いです。  
`ExternalSeamInventory` の戻り値は array shape ではなく小さな value object にすると、理由文字列・kind・classification の欠落を静的に防ぎやすいです。

**8. 既定拒否設計**
[Critical] 走査根が `app/` のみだと、今回実際に問題化した `config/template.php` の SSO provider 有効化が母集団外です。これは「走査母集団の外で外部到達が増える」穴です。  
修正提案: 少なくとも SSO provider config、`routes/` にある redirect/外部 URL、外部 endpoint を持つ config キーを対象にするか、対象外なら「外部到達点」と呼ばず「app/ クラス内 client 取得点」に名前を狭めてください。

**9. 二重管理**
[Suggestion] object storage と LLM を委譲専用にし、同じ到達事実を二重登録しない方針は妥当です。  
ただし `AppServiceProvider` のように別事実で両目録に載るケースは、テスト失敗メッセージにも明示した方がよいです。将来のレビューで「重複」と誤解されやすいです。

**10. 保証範囲の誠実さ**
[Warning] 「保証しないもの」はかなり誠実に書けています。  
一方で、期待効果側の「安全に回し続ける」「登録なしでは CI を通らない」が保証範囲より強く読めます。  
修正提案: 効果の文言を「app/ クラス内の既知規則に合う到達点」に限定し、SSO ブラウザ外部遷移は別問題として明確に分けてください。

結論として、方針の核は良いです。ただし SSO の provider 設定が母集団外である点と、未遮断の SSO を含めた効果表現が過大な点は設計段階で直すべきです。