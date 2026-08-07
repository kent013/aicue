# 対応マトリクス: conceptual-review Round 2

## [Critical] 観点3/7: exemption 3 件に対し enum が 2 case で矛盾している
- 判断: **一部反論 + 記述の明確化で対応**
- 根拠:
  - 指摘のうち「概念設計の記述が矛盾して読める」は正しい。「enum 2 case」と
    「exemption 3 件」を並べただけで、両者が別次元の数であることを書いていなかった。
  - しかし「1 route = 1 case にせよ」は**このリポジトリの既存パターンと逆行する**ため採らない。
    見本の `ThrottleCoverageExemption` は **8 case で 25 件の exemption** を分類しており、
    case は「route の識別子」ではなく「**免除してよい理由の型**」である
    (`ThrottleCoverageInventoryTest` は全体 cap と **case 別 cap** を独立に検査し、
    「どのカテゴリが膨らんだか」= 分類の偏りを見る設計になっている)。
    1 route 1 case にすると enum は route 名の写しになり、
    「同じ理由の route が増えていないか」という**目録の主目的が消える**。
  - `two-factor.login` (GET) と `two-factor.login.store` (POST) は同一 URI の
    表示/検証ペアで、免除理由も完全に同一 (guest 面 = 認証主体が無く step-up が定義不能)。
    別 case に割る根拠が無い。
- 対応内容: 概念設計に「**enum の case = 免除理由の型 (2)、exemption 件数 = route 数 (3)**。
  両者は別次元で、gate は全体 cap (3, exact-fit) と case 別 cap (PreAuthChallengeSurface=2 /
  ProofOfSecretPossessionRequired=1) を独立に検査する」と明記。
  `ThrottleCoverageExemption` (8 case / 25 件) が同型の先例であることも書く。

## [Warning] 観点3: 並列 fetch が個別に 409 を受けたときのモーダル多重起動
- 判断: 対応する
- 根拠: 正しい指摘。`Promise.all([qr, secret])` は両方 409 になるのが**通常**であり
  (同じ session・同じ鮮度判定)、各 fetch が自分で `guardWithRecentAuth` を呼ぶ設計だと
  モーダル 2 回起動 + `pendingAction` 上書きが**常時**起きる。
- 対応内容: 「各 fetch は値と `recentAuthRequired` を返すだけ。集約は
  `loadEnrollmentAssets()` の 1 箇所で、`guardWithRecentAuth` の呼び出しも 1 回だけ」を
  設計に固定。JS テストに「両方 409 でもモーダル起動と pendingAction 登録は 1 回」を追加。

## [Warning] 観点5: 機械保証の範囲をセレクタに合わせて限定して書け
- 判断: 対応する
- 根拠: 正しい。「2FA 状態に触る全 route」を保証しているかのような書き方は誇張になる。
  命名規約そのものを強制する仕組みは本 TODO には過大。
- 対応内容: 概念設計に「**保証範囲 (誇張しない)**」節を追加し、
  「route 名に `two-factor` を含む route に限る。別名 (`mfa.*` 等) で第二要素へ触る route を
  足すときは本 inventory の母集団設計も同時に見直す」と明記。
  gate のファイル冒頭コメントにも同文を置く。

## [Suggestion] 観点7: 409 判定は status だけでなく識別フィールドまで見よ
- 判断: 対応する
- 根拠: 既存の `lib/recent-auth.ts` の `recentAuthRedirectTarget()` が既に
  `code === 'recent_auth_required'` の厳格一致を採っており、素の fetch 側だけ
  status のみで判定するのは**同一アプリ内での判定ドリフト**になる。
  実際 `RequireTwoFactorForEnforcedOrganizations` も 409 を返す (code は `two_factor_required`)
  ため、status だけの判定は**誤食する**。
- 対応内容: 素材 fetch の 409 判定を「status === 409 かつ `code === 'recent_auth_required'`」に固定。
  判定関数は `lib/recent-auth.ts` に置き、既存 `RECENT_AUTH_REQUIRED_CODE` 定数を共有する
  (判定点を 2 つ作らない)。

## [Suggestion] 回答3: 「password に限らず選択された satisfier 1 回」と書く方が正確
- 判断: 対応する (ただしより正確に書き分ける)
- 根拠: 指摘のとおり satisfier は password だけではない。ただし
  **inline bucket を消費するのは password satisfier だけ**である
  (`recent-auth.password` = `throttle:6,1` は inline。passkey satisfier
  `passkey.confirm-options` / `passkey.confirm` は `throttle:passkeys` の named limiter、
  再SSO の `social.redirect` / `social.callback` も named limiter)。
  「satisfier 1 回」と丸めると inline 消費の見積もりがむしろ不正確になる。
- 対応内容: 前提 1 を「step-up 1 回あたり inline bucket の消費は
  **password satisfier を選んだ場合のみ +1**、passkey / 再SSO satisfier は
  named limiter のため **+0**」に書き換える。

## [Suggestion] 観点1・2・4・6
- 判断: 反映不要 (肯定的評価。`two-factor.enable` を本 TODO に含める判断も支持された)
