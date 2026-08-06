全体判定: **CHANGES_REQUESTED**

制約上、コマンド実行を行わず、提供テキストに基づいてレビューしています。実ファイルとの突合は未実施です。

**1. 使命との整合性**
[Suggestion] 使命への直接機能ではないが、SSO・招待導線の可用性を守る基盤改善として整合しています。North Star への位置づけは妥当です。

**2. 禁止事項違反**
[Warning] 「テストファースト」は書かれていますが、設計上の behavioral proof が不足しています。特に `AuthViewRenderOnly` の適用条件は 4 条件あるのに、検証対象が「外部 HTTP / Mail なし」に偏っています。

修正提案: 少なくとも以下も機械検証に含めてください。

- 対象 route が GET/HEAD のみであること
- 対象 route に throttle も exemption も二重登録されないこと
- `AuthViewRenderOnly` 対象 route が DB write を行わないこと、または write 許容 route を個別 exemption に分離すること

**3. 実現可能性**
[Warning] `social.callback` の limiter を route 定義へ第 1 段で貼る方針は、Socialite callback の provider パラメータ・Fortify/Social route 定義の実体次第で問題ありませんが、`social.callback` が 1 route で複数 provider を受けるなら 10/min/IP はかなり強く効きます。攻撃抑止としては成立しますが、SSO 初回導入時の同一 NAT 一斉ログインには脆いです。

修正提案: 閾値を変えない方針自体は維持してよいですが、「passkeys guest 10/min と同値だから妥当」だけでは弱いです。SSO callback は組織導入時に同時発生しやすいので、既存値流用の根拠を `login` 系ではなく「外部 IdP 呼び出しを伴う callback の上限」として再評価し、少なくとも運用監視と緊急緩和手順を設計に入れてください。

**4. 期待効果の妥当性**
[Warning] `social.redirect` を exemption にする理由がやや楽観的です。外向き HTTP は callback 側で止まりますが、redirect は OAuth state/session を生成し、IdP へのリダイレクト URL を大量生成できます。「全 web route と同質の session コスト」と断じるには、OAuth state 生成・session 書込・Socialite URL 組み立ての性質が異なります。

修正提案: `social.redirect` を throttle 対象にしない判断を維持するなら、`AuthViewRenderOnly` ではなく別カテゴリにしてください。これは単なる view render ではなく、認証フロー開始・session state 生成です。カテゴリ名と実態がズレています。

**5. リスク**
[Critical] `AuthViewRenderOnly` のカテゴリ定義が緩すぎます。対象に `social.redirect` を含める一方で、case 名と説明は「画面 / ステータスの描画にすぎない route」です。`social.redirect` は OAuth state を session に作り、外部 IdP へ遷移させる認証フロー開始 route であり、「描画のみ」ではありません。このまま enum 化すると、将来の「GET だが認証フローを開始する route」まで安易に免除される穴になります。

修正提案: `social.redirect` は以下のどちらかにしてください。

- throttle を貼る
- `AuthFlowInitiationNoOutboundHttp` のような狭い exemption case に分離し、条件を「外向き HTTP なし」「session state 生成は自セッション限定」「callback 側が rate limit 済み」「provider/token を key に使わない」などに限定する

[Warning] `two-factor.qr-code` / `secret-key` / `recovery-codes` に `10,1` を貼る判断は妥当ですが、設計内でも認めている通り step-up の代替ではありません。秘密 GET の保護として誤読されるリスクがあります。

修正提案: テスト名・コメント・docs に「rate limit は漏えい防止ではなく連続取得の上限」と明記し、B2 TODO への参照を Architecture テストか TODO 台帳で機械的に残してください。

**6. スコープの適切さ**
[Warning] cap を 14 → 26 にする根拠が「25 + 1」で、将来の増分をほぼ許さない設計になっています。deny-by-default の摩擦としては理解できますが、母集団を広げた直後に exemption 率が 35.7% へ上がるなら、cap だけで形骸化を防ぐのは弱いです。

修正提案: cap 更新に加えて、exemption の種類ごとの上限または `AuthViewRenderOnly` 件数の inventory assertion を置くとよいです。特に新カテゴリ 14 件が将来 20 件、30 件へ増えても cap だけではレビュー意図が見えにくくなります。

**7. 型安全性**
[Suggestion] DTO/JsonResource への影響は薄く、middleware・enum・architecture test 中心なので PHPStan level 10 の実現可能性は高いです。ただし RateLimiter closure の key 生成は戻り値型と `Request::user()` nullability を明示し、PHPStan 対応で型を緩めないようにしてください。

**重点項目の結論**
分類の大筋、特に `social.callback` / `invitations.accept` / `two-factor.*` へ貼る判断は妥当です。一方で、免除 14 本を単一の `AuthViewRenderOnly` に入れる設計は危険です。最大の問題は `social.redirect` が「view render only」ではない点です。

この点を分離し、免除カテゴリの behavioral proof を強化すれば、APPROVED に近い設計です。