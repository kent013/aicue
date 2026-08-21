## Round 2: 前回指摘への対応

前回 (Round 1) の指摘に対し、概念設計を以下のとおり修正しました。対応マトリクスを示します。

### [Critical] T055 / AG-113 の退行を宣言のみで担保していた
→ 対応。概念設計に「テスト計画」節を新設し、以下 5 本のリグレッションを明示列挙:
  1. guest が招待 URL → register 誘導 + session token 保存 + email prefill (T055 不変)
  2. 招待先 email でログイン中の token POST → 受諾成功 (正常系回帰防止)
  3. 別 email のログイン者: show で受諾ボタン非提示 (canAccept=false) かつ 直 POST 失敗で
     membership/pivot/role 不変 (権威は Service)
  4. token POST 経路の email 同一性規則が register 経路と一致
  5. AG-113 pending は宛先本人にのみ表示・受諾可能

### [Warning] show は補助 UX、Service が唯一の権威
→ 対応。「Service = 唯一の権威的 gate、画面表示は補助 UX」と明記。直 POST が状態を変えないテスト (上記 3) を追加。

### [Warning] email 比較の正規化
→ 対応 (新規正規化は導入せず規則を統一)。既存の安全 2 経路 (acceptInvitationIfValid L179 /
   MatchesInvitationEmail L46) はいずれも CipherSweet 復号後平文の素の `!==`。token POST 経路も
   同一規則を使い、両経路が同一入力で同一判定になることをテストで固定 (上記 4)。
   独自正規化を足すと email 同一性規則が経路ごとに分岐し「別物を統合しない」「後方互換並走を残さない」
   に反するため、規則の統一という形で対応。

### [Warning] mismatch 画面のログアウトと token 継続
→ 対応。「ログアウト後は招待リンクを再オープン」導線 (guest 経路が token を session へ保存し直す) とし、
   token が logout を跨いで生存することに依存しない設計へ変更。招待 email は画面に出さない。

### [Warning] F-2-03「全経路 403」の過大表現
→ 対応。「主要な組織保護 route (dashboard/projects/billing/manage-users)」に表現を狭め、
   テスト docblock にも検証経路を正確に書くと明記。

### [Warning] mismatch prop の型安全性
→ 対応。`canAccept: boolean` のみ追加。招待 email 等は渡さない。ValidationException は標準 error bag。

### [Suggestion] F-2-01 効果表現
→ 対応。「手戻りを減らす」に留める。

これらを反映した概念設計を再掲します。全体判定を再度お願いします。

---

## 概念設計 (改訂版)

# 概念設計: bughunt-org-membership-authz

bug-hunt run `20260821-095643` の「組織メンバーシップ / 認可」グループ 3 件
(F-2-02 / F-2-03 / F-2-01) への設計。証跡は
`devnotes/20260821-095643-bug-hunt/report.md` および `shard-2/shard-report.md`。

## 事前検証 (ground truth の確定)

設計前に、報告の症状が **現行コード (working tree)** で本当に再現するかを
使い捨て Pest Feature テストで確認した (テストは検証後に削除。以下は観測結果)。

| finding | 検証内容 | 観測 |
|---|---|---|
| F-2-02 | 別 email の既ログインユーザーが token を POST 受諾 | **join 成功 (再現・実在の Critical)** |
| F-2-03 (a) | `organizations.members.destroy` (HTTP) で pivot が外れるか | **外れる** (`organization_user` から消える) |
| F-2-03 (b) | 除名された編集者が `/dashboard` `/projects` `/billing` を見られるか | 除名後は当該組織へアクセス不可 (組織 0 件の着地に落ちる) |
| F-2-03 (c) | 「未割当」= attach 済み・laratrust ロール無しの行が組織データを見られるか | **全経路 403** (fail-closed) |
| F-2-01 | プロジェクト 0 件組織の `/manage/users` に事前の注記があるか | **既に注記 + 作成 CTA を表示済** (`Users.svelte` L275-290) |

**結論**: 実在する脆弱性は **F-2-02 のみ**。F-2-03 の pivot 解除は既に実装済み
(`removeMember` が `detach` する。T025 で 2026-07-13 に導入) で、「未割当」行も
アクセスは fail-closed。F-2-01 の「事前表示」も既に存在する。
したがって本設計は **(1) F-2-02 の実修正**、**(2) F-2-03 の既存不変条件をテストで固定**、
**(3) F-2-01 を AGENTS.md 禁止事項 8 に沿って最小改善** の 3 本立てとする。

## 背景・課題

### F-2-02 (Critical, 実在): 招待受諾が宛先 email を照合しない

招待受諾には 3 経路がある:

1. **register 経路** (`acceptInvitationIfValid`): 招待 email と登録 email の一致を要求済 (安全)。
   `MatchesInvitationEmail` rule と対で二重防御。
2. **アプリ内受諾** (`acceptPendingInvitation`, 裁定 AG-113): `pendingInvitationsQuery` が
   `activePendingForEmail(ログイン者の email)` に畳まれ、他人宛には構造的に到達不能 (安全)。
3. **token POST 受諾** (`acceptInvitation` → `InvitationAcceptanceController::store`): **email 照合が無い**。

`acceptInvitation` の docblock (L111-113) と Controller の docblock (L23) は
「ログイン後経路では email 一致を要求しない仕様」と明記している。これは意図的な設計だが、
経路 1・2 が email 境界を強制しているのに経路 3 だけが素通しであり、招待リンクを
(メール転送・URL 共有・ログ) で知った無関係の第三者が自分のアカウントで任意の組織へ
参加できる = **組織のメンバー境界 (誰が入れるか) という認可境界の破れ**。
使命の観点では、SOP・撮影データを扱う組織のメンバー集合が意図せぬ第三者に開くのは
標準作業の管理主体が崩れることを意味し、看過できない。

### F-2-03 (報告 Critical, 実態は既対応): 除名の不完全さ / 「未割当」行の許容

報告は「除名がロール剥奪のみで pivot を外さず、除名済みユーザーが閲覧アクセスを保持し、
`/manage/users` に『未割当』で再出現する」とする。しかし現行 `removeMember` は
`detach` (pivot 解除) + ロール剥奪 + project pivot 掃除 + current_organization_id クリア +
トークン失効を 1 トランザクションで行う。検証でも除名後アクセス不可・一覧から消滅を確認した。

報告が観測した「未割当で再出現」は現行コードでは再現しない (bug-hunt 環境の一時状態か、
並行受諾レース残渣の観測と推定)。ただし報告が指摘する **設計論点は有効**:
「attach 済みだが laratrust ロール未付与 (=『未割当』) の行を許容し続ける設計の是非」。
この状態は `joinOrganization` の並行受諾 (insertOrIgnore が 0 行の敗者側) や、
今後の経路追加で生じうる。**検証の結果、この状態はアクセスが全経路 403 (fail-closed) で、
管理画面から `applyConsoleRole` の修復経路でロールを付け直せる** ため、
情報漏洩には至らない。

### F-2-01 (Medium): プロジェクト 0 件組織のロール option

`/manage/users` のロール変更 combobox で「編集者/撮影者」が選択可能に見え、送信後に
「編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。」の validation
エラーが出る (1 往復の手戻り)。

**ここで AGENTS.md 禁止事項 8「必須条件未充足を理由にボタンを disabled にする UI
(押下時にエラー表示する。DESIGN.md)」が効く。** bug-hunt の改善案「両 option を disabled に」は
この禁止事項に真っ向から反する。加えて `Users.svelte` は既にカード冒頭で
「プロジェクトがまだありません。編集者・撮影者を割り当てるには…」の注記と作成 CTA を
`!hasDefaultProject` のとき表示している (事前表示は実装済)。
したがって F-2-01 で許される改善は **disabled 化ではなく**、選択地点 (option ラベル) に
非 disabled の情報を足して手戻りを減らすことに限られる。

## 改善アイデア

### F-2-02: token POST 受諾に宛先 email 照合を追加する (経路 1・2 と揃える)

- **Service** `OrganizationMembershipService::acceptInvitation` = **唯一の権威的 gate**:
  招待解決後・join 前に email 不一致なら `ValidationException` を投げる。
  UI を迂回する直 POST もここで塞ぐ (deny-by-default)。**画面表示は補助 UX に過ぎず、
  防御の権威は Service のみ**である (Codex 概念 R1 [Warning] 対応)。
- **email 同一性規則は register 経路と同一にする**: 既存の安全経路
  `acceptInvitationIfValid` (L179) と `MatchesInvitationEmail::validate` (L46) は
  ともに **CipherSweet 復号後の平文を素の `!==` で比較**している。token POST 経路も
  **同じ規則**を使う (新しい正規化を導入して規則を分岐させない = 別物を作らない)。
  「register 経路と token POST 経路が同じ email 同一性規則である」ことをテストで固定する
  (Codex 概念 R1 [Warning] 対応)。email は非 null (モデルの型 + CipherSweet 復号後 string) で
  比較前に `Assert::string` で narrow する (PHPStan L10)。
- **Controller** `InvitationAcceptanceController::show`: ログイン済 + 有効招待 + email 不一致のとき、
  受諾ボタンを出さず「この招待は別のメールアドレス宛です。招待メールを受け取ったアドレスで
  ログインし直してください。ログアウト後、招待メールのリンクをもう一度開いてください。」の
  案内を表示する (画面上でも受諾させない)。**招待 email は画面に出さない** (露出最小)。
  ログアウト導線は付けてよいが、**token が logout を跨いで生存することに依存しない** 設計にする
  (ログアウト → 招待リンク再オープン = guest 経路で token を session へ保存し直す。
  Codex 概念 R1 [Warning] のトークン継続リスク回避)。
- **型付き props**: Accept ページへは `canAccept: boolean`(不一致なら false) のみ追加で渡す
  (mismatch 詳細・招待 email は渡さない)。Svelte 側 Props interface と型定義もこの boolean を
  受ける (Codex 概念 R1 [Warning] 型安全性対応)。`ValidationException` は Laravel 標準の
  error bag / flash 経路に乗り、`response()->json()` 直書き (禁止事項 4) を増やさない。
- docblock の「一致を要求しない仕様」記述を削除・更新する (禁止事項の後方互換並走を残さない:
  書き換えると決めたら旧記述を同じ変更で消す)。
- **未ログイン → register 誘導 / メール自動入力 (T055) は一切変えない** (show の guest 分岐は
  email 照合の前に return するため影響しない)。

### F-2-03: 既存不変条件を Feature テストで固定 + 設計判断の明記 (production コード変更なし)

- 現行 `removeMember` の完全除名 (pivot 解除・アクセス不可・一覧から消滅) を **HTTP 経路の
  リグレッションテスト**で固定する。AGENTS.md 禁止事項 1「不変条件は対応するテストへの登録まで
  含めて実装済み」に従い、「既に正しい」を「壊れたら落ちる」に格上げする。
- 「未割当」行が **主要な組織保護 route (dashboard / projects / billing / manage-users) で 403**
  (fail-closed) であることをテストで固定する。**「全 route」を保証するとは主張しない** —
  検証したのは列挙した代表 route であり、テストの docblock にも「検証した経路」を正確に書く
  (Codex 概念 R1 [Warning] スコープ表現対応)。
- **設計判断 (明記事項)**: 「未割当」行は **許容し続ける**。理由: (a) アクセスは fail-closed で
  情報漏洩に至らない、(b) 管理画面の `applyConsoleRole` 修復経路が正規の回復手段として既に存在、
  (c) 並行受諾レースの自然な帰結であり、これを禁止するには受諾コアへ追加機構が要る =
  思考原則「今必要なものだけ作る」に反する。よって production コードは変更しない。

### F-2-01: option ラベルに非 disabled の注記を足す (禁止事項 8 遵守)

- `Users.svelte` の `ROLE_OPTIONS` を、`hasDefaultProject` が false のとき
  「編集者」「撮影者」ラベルへ注記サフィックス (例:「編集者（要プロジェクト）」) を付す
  派生に変える。**option は選択可能なまま** (押下すれば従来どおりサーバ error bag を表示)。
  カード冒頭の既存注記 + 作成 CTA は維持する。
- 「管理者」は無条件で選べるためサフィックスを付けない。

## 期待効果

- **使命への貢献**: 組織 = 標準作業 (SOP) と撮影データの管理単位。F-2-02 修正でメンバー境界が
  意図した email 境界どおりに閉じ、第三者混入を防ぐ (機密の SOP/映像への不正参加を遮断)。
- F-2-03 のテスト固定で、最重要のセキュリティ操作 (メンバー排除) の不変条件が退行検知される。
- F-2-01 で手戻り 1 往復を減らしつつ、DESIGN.md / 禁止事項 8 のUX原則を守る。

## 実装方針(概要)

| finding | 変更 | 種別 |
|---|---|---|
| F-2-02 | `acceptInvitation` に email 照合 / `show` に不一致分岐 / Accept.svelte に mismatch 表示 / docblock 更新 / 目録 description 更新 | production + test |
| F-2-03 | HTTP 除名リグレッション + 未割当 fail-closed テスト。production 変更なし | test のみ |
| F-2-01 | `Users.svelte` option ラベル注記 (非 disabled) | frontend + test |

## テスト計画 (概念レベル。詳細は detailed-design.md)

F-2-02 の修正が既存の安全経路 (T055 register 誘導 / AG-113 アプリ内受諾) を壊さないことを
**明示的にリグレッション固定する** (Codex 概念 R1 [Critical] 対応):

1. guest が招待 URL を開く → 従来どおり register へ誘導、session に token 保存、
   register フォームに招待 email が prefill される (T055 不変)。
2. 招待先 email でログイン中のユーザーの token POST → 受諾成功・参加成立 (正常系の回帰防止)。
3. 別 email のログイン中ユーザー: `show` で受諾ボタンが出ない (`canAccept=false`) **かつ**
   直接 token POST が失敗し membership/pivot/role を一切変えない (権威は Service)。
4. token POST 経路の email 同一性規則が register 経路と一致する (同じ入力で同じ判定)。
5. AG-113 の pending 招待は宛先本人にのみ表示・受諾可能 (既存 `PendingInvitation*Test` の
   不変が維持されることを確認。新規退行を入れない)。

F-2-03:

6. owner が編集者を DELETE 除名 → `organization_user` から消滅・`/manage/users` 一覧から消滅・
   org role 無し・project pivot 解除・current_organization_id クリア・被除名者は
   `/projects` `/billing` へアクセス不可。
7. 「未割当」(attach 済み・role 無し) の行は dashboard/projects/billing/manage-users で 403。

F-2-01:

8. プロジェクト 0 件組織で `/manage/users` の Inertia prop `hasDefaultProject=false`、
   1 件以上で true (option ラベル注記の駆動データ契約)。既存の no-project-note 表示も維持。

## 制約・前提

- Laravel 12 + Svelte 5 + Inertia + PHP 8.4、PHPStan level 10、Pest + RefreshDatabase (parallel)。
- 招待解決経路の分類は `InvitationResolutionInventoryTest` が deny-by-default で強制。
  `acceptInvitation` は `TokenHashLookup` scope のまま (email 照合は解決 **後** に足すので
  解決 scope は変わらない)。目録の説明文だけ更新する。
- セキュリティ不変条件 9 (変更系は認可を通る)・2/10 (テナント境界 404 が認可より前) は現状維持。
  受諾 route は auth 必須・email 照合は認可ではなく招待の宛先検証 (層 3 の後) として足す。
- CipherSweet 下でも `$invitation->email` / `$user->email` は復号後の平文文字列比較でよい
  (経路 1 `acceptInvitationIfValid` L179 と同じ比較。blind index は不要)。

## スコープ外

- F-2-02 の未ログイン register フロー・メール自動入力 (T055) の挙動変更。
- 並行受諾レースそのものの禁止 (「未割当」行を構造的に作らせない機構)。
- 招待フォームのロール表現の文言修正 (bug-hunt インベントリ提案 2。別グループ)。
- Q-2-01 (招待参加者の初回着地) / debug.login-as インベントリ提案。
- 他グループの findings (F-1-*, F-3-*, F-4-*)。
