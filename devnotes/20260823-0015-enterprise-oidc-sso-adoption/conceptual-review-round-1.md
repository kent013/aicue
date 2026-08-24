全体判定: **CHANGES_REQUESTED**

1. 使命との整合性

[Suggestion] 企業の共通ログインで現場作業者の導入障壁を下げる効果は、「思考ゼロ」の入口として使命に整合します。ただし動画シナリオ作成・ナビ撮影の中核機能ではないため、期待効果は「導入・継続利用を支える基盤」として表現するのが適切です。

2. 禁止事項違反

[Critical] `auth-2fa-org-enforcement` が企業ログイン後にも効く形を保つ、という記述は AG-200 の「企業ログイン経路にアプリ側 2FA を挟まない」と衝突し得ます。現状の middleware がログイン後に 2FA 入力へ遷移させるなら、企業 OIDC は AG-200 違反です。

修正提案: `RequireTwoFactorForEnforcedOrganizations` の企業 OIDC に対する期待動作を明文化してください。企業 OIDC 完了後は `Auth::login()` 等で即時確定し、2FA challenge へリダイレクトしないことを、企業・ソーシャル双方の実挙動 Feature テストで固定すべきです。

[Warning] 「Auth 名前空間へ置くことでメール引当禁止の検査範囲へ入れない」という説明は、検査回避と読めます。正典追従として配置自体が正しくても、メール昇格フローが `User` をメールで検索しない保証は別途必要です。

修正提案: EmailPromotionService の許容目的を「現在認証済みユーザー自身のメール属性更新」に限定し、メールを主キーにしたユーザー検索・既存アカウント併合を禁止する専用テストを追加してください。

3. 実現可能性

[Warning] Laravel 12、Svelte 5、Inertia で実現可能です。ただし OIDC Discovery、JWKS、token endpoint はリダイレクト・鍵ローテーション・POST body を含むため、`^0.4` 化後の SsrfPin API が3経路すべてで利用できることを前段 TODO の受入条件として明記する必要があります。

修正提案: 前段 TODO の完了条件に、Discovery/JWKS の GET と token exchange の body 付き POST がいずれも pin・検査付きクライアントで実行できる統合テストを加えてください。

4. 期待効果の妥当性

[Warning] always-JIT は初回利用を滑らかにしますが、「入退社に合わせた開け閉め」が完全に自動化されるわけではありません。スコープ外に SCIM・自動デプロビジョニングがあり、既発行セッションの失効も記載されていません。

修正提案: 効果を「IdP 上でアクセスを失った利用者は次回ログインできない」に限定してください。即時停止や既存セッション失効を期待しない旨も運用上明記すべきです。

5. リスク

[Critical] 顧客入力の issuer / discovery URL を扱うため、SSRF対策だけでなく OIDC issuer の同一性検証、discovery 応答内 endpoint の扱い、redirect 制御、JWKS の鍵選択・署名・`iss`・`aud`・`exp`・`nonce`・`state` 検証が設計上明示されていません。ここが曖昧だと、SsrfPin を通していても認証の信頼境界が崩れます。

修正提案: 正典の7サービスにこれらの責務が含まれることを成果物一覧へ具体化し、少なくとも state/nonce の一回限り性、issuer 一致、ID token 各 claim、署名鍵ローテーション、discovery の endpoint を含む外向き通信の検査を Feature/Architecture テストで固定してください。

[Warning] 接続シークレットは「DTOに載せない」だけでは不十分です。永続化時の暗号化、読み戻し時の非露出、ログ・例外・Inertia props への混入防止が必要です。

修正提案: 接続モデルの secret の暗号化キャスト、Resource/DTO/props に secret を含めない不変条件、例外ログに authorization code・token・client secret を出さないテストまたは明示的なログ方針を設計に追加してください。

6. スコープの適切さ

[Warning] A〜F は依存関係を踏まえた適切な分割です。一方、E のメール昇格は OIDC 本体より権限・PII・アカウント回復の意味論が広がりやすく、OIDCの安全性を成立させる最小要件かが本文だけでは判定できません。

修正提案: E が必須なら、「OIDC Identity に申告メールを保存するだけでは既存ログイン用メールを変更しない理由」と、昇格に必要な本人確認・認可・監査条件を明記してください。必須でなければ別 TODO に分離する判断も妥当です。

7. 型安全性

[Warning] DTO/JsonResource の採用方針が成果物一覧に現れていません。特に接続管理画面、OIDC callback、設定値、外部応答は境界型を明確にしないと PHPStan level 10 で `mixed` の伝播や秘密値混入が起きやすいです。

修正提案: 次を設計に明記してください。

- OIDC discovery/JWKS/token/claims は配列のまま流さず、検証済み DTO に変換する。
- controller は FormRequest → Service → Inertia / JsonResource の薄い境界にする。
- client secret、authorization code、access token、refresh token は画面用 DTO・JsonResource・例外文言に含めない。
- `subject`、issuer、connection ID、organization ID の対応関係を型付き値または明確な DTO で保持し、payload から組織・所有者キーを受けない。

上記、とりわけ **AG-200 と既存2FA強制の整合**、および **OIDCプロトコル検証責務の明文化とテスト化** が解消されれば、正典追従として承認可能です。