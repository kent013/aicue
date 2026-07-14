# 概念設計レビュー Round 2

Round 1 の指摘（Critical 1 / Warning 3）に対し、概念設計を以下のとおり改訂しました。対応マトリクスと
改訂後の該当セクションを提示します。再レビューをお願いします（全体判定 + 残 Critical/Warning）。

## 対応マトリクス（要約）

- [Critical] 根本原因の説明不足 → **一次原因（登録経路で current_organization_id を確定しない）+ 二次条件
  （共有プロップが dashboard-only 自己修復に依存）** の 2 段構成へ書き換え。観測は same-request の矛盾ではなく
  **別ページ（dashboard vs 他ページ・ヘッダー）観測**であることを明記。テストに「登録直後の認証済みリクエストの
  共有プロップ currentOrganization に招待先組織が反映されること」を追加（DB 値＋共有プロップの両観測点）。
- [Warning] register 限定の理由未明記 → POST 受諾（acceptInvitation）が現在組織を切り替えない契約を壊さないため
  joinOrganization 共通契約へ昇格させない、と明文化。
- [Warning] 分岐の排他・網羅が曖昧 → 分岐タクソノミー表を追加（A 成立 / B 通常フォールバック、email 不一致は
  422 拒否で分岐外）。
- [Warning] North Star 表現が過大 → 「初期オンボーディング整合回復・導線詰み除去（入口整備）」へ抑制。

## 改訂後セクション（抜粋）

### 根本原因（改訂後）

一次原因＝招待参加時に `current_organization_id` を確定しないこと。二次条件＝全ページ共有の
`HandleInertiaRequests::currentOrganizationProp()` が `$user->currentOrganization`（生読み・isMemberOf 再確認）
のみで現在組織を決め、`CurrentOrganizationResolver` の自己修復を通さないこと。

- dashboard: `CurrentOrganizationResolver::resolve()` が null の current を所属先頭（招待先）へ heal（UPDATE →
  refresh）し、招待先組織の共有残高 10 を描画（症状 1。10 は招待先の共有残高で誤付与ではない。招待参加パスは
  grantSignupGrant を呼ばない）。
- dashboard 以外 / 自己修復前ヘッダー: 生読み null → 現在組織 null → 「組織を作成/選択」（症状 2）。
- 一度 heal されれば以後は共有プロップも同値を読むため矛盾解消（「dashboard 経由・再ログイン後に見える」時系列）。

一次原因を登録経路の入口で塞げば、自己修復非依存で登録直後の全ページで招待先組織が一貫表示される。

### 分岐タクソノミー（排他・網羅）

| 分岐 | 発火条件 | 個人組織 | signup grant | current_organization_id |
|------|----------|---------|--------------|------------------------|
| A: 招待成立 | token あり + DB 実在 + active + 招待 email 一致 + 未メンバー | 作らない | 付与しない | 招待先組織 |
| B: 通常/フォールバック | token なし/空/不正型、または token あり but（DB 不在・失効・受諾済・取消・既メンバー race） | 作る | 付与する | 個人組織 |

拒否ケース（分岐外）: token あり + active + email 不一致 → `MatchesInvitationEmail` rule が 422 拒否（fallback しない）。
分岐 A/B は `$joined = acceptInvitationIfValid(...)` の戻り（Organization / null）で一意決定。

### 実装方針（改訂後）

`CreateNewUser::create()` の招待成立分岐（`$joined !== null`）で、登録 `DB::transaction` 内・
`$user->current_organization_id === null` のときのみ `forceFill(['current_organization_id' => $joined->id])->save()`。
`joinOrganization` 共通コア・`HandleInertiaRequests` は変更しない。register 限定の理由 = POST 受諾での
意図しない現在組織切替を招かない／`provision()` の null ガード初回確定と同位置づけ。

### 期待効果（改訂後）

招待メンバーの初期オンボーディング整合性を回復し、登録直後に組織作業へ入れない導線の詰みを除去する
（教材設計・撮影ナビの本質改善ではなく、組織横断運用の入口整備）。登録直後の全ページで招待先組織が現在組織
として表示される。grant 増幅（招待 N 人 = N×10）を作らない設計意図をテストで恒久固定する。

---

上記改訂で Critical / Warning が解消されているか、残課題があれば指摘してください。
