# テンプレート化の論点(aigenba / spirux の相違点と落とし方)

> **2026-06-11 追記: Q1〜Q12 はすべてオーナーが回答済み。決定内容は `05-decisions.md` を正とする。**
> 本書は各論点の背景・選択肢・トレードオフの記録として残す。

両アプリの相違のうち、テンプレートにどう落とすか判断が必要なものを列挙する。
番号は `03-feature-matrix.md` の △Q 参照と対応。

各アプリの差分レジストリ(aigenba `docs/aigenba-spirux-divergence.md` が正本)で
「logic-driven な正当差分」と確定済みのものは、**テンプレートでも片方に寄せず
『選択ガイド付きの分岐』として扱う**のが整合的。逆に「決め」で済むもの(命名・既定値)は
テンプレートで正規形を確定させ、以後の divergence を発生させないのが価値。

---

## ★ 構造の根幹に関わる論点

### Q1. 組織階層: 部門層(CustomTeam)を持つか

- aigenba: `Organization → CustomTeam(部門) → Project`
- spirux: `Organization → Project`(部門層なし)

「組織構成は同じになる」という前提に対し、実は両者の階層は 1 段違う。これがテンプレートの
マイグレーション・Policy・画面構成すべてに波及するため、最初に決めるべき論点。

選択肢:
1. **spirux 型(2 階層)を基本形にし、部門層は追加ガイド(マイグレーション+Policy 拡張手順)として文書化** ← 推奨。薄い方から厚くする方が逆より楽
2. aigenba 型(3 階層)を基本形にし、不要なら CustomTeam を使わない(=死蔵テーブルが残る)
3. 両対応の抽象化(ネスト可能な Team)— over-engineering の懸念大

付随論点: Personal Organization 自動生成(aigenba)を既定とするか、明示的な組織作成フロー
(spirux)を既定とするか。B2B 前提なら spirux 型、セルフサーブ前提なら aigenba 型。

### Q2. プロジェクトロールの命名と意味

- aigenba: `project_tutor` / `project_trainee`(ドメイン的な非対称ロール)
- spirux: `project_admin` / `project_member`(汎用的な管理/一般)

組織ロール(owner/admin/member)は実質同形だが、プロジェクトロールはドメインが滲む。
権限はアプリ毎に違う前提なので:

- テンプレートは **`project_admin` / `project_member` を既定**とし、
  「ロール enum・permission enum・シーダーを 1 箇所(config or enum)で差し替えられる構造」を
  テンプレの提供物にする ← 推奨
- ロール定義を config 駆動にまで抽象化するか、enum 直書き+リネーム手順書にとどめるかは要判断。
  enum 直書きの方が PHPStan・IDE 親和性が高く、両アプリの現行スタイルとも一致

### Q3. リソース tenancy と API 形状(divergence D1)

- aigenba: 多態スコープ(platform/org/project)+ body discriminator(`POST /api/v1/scenarios` + `scope`)
  + 狭い universal trait+per-request 制約+出口モデル層
- spirux: 単一階層+nested route(`POST /api/v1/projects/{project}/scenarios`)
  + 広い `ProhibitsProtectedKeys`(`missing`)

両チームで「logic-driven な正当差分」と確定済み。テンプレートとしては:

- **既定 = spirux 型(nested route + 広い prohibited 集合)**。新規アプリは単一階層から
  始まる可能性が高く、構造がシンプルで防御も「広い deny 集合」1 枚で説明できる ← 推奨
- aigenba 型(多態リソース)が必要になった場合の「逸脱手順」を D1 をベースにテンプレ docs 化
  (どの不変条件を保ち、入口防御をどう組み替えるか)
- 不変条件「サーバ導出キーを payload から信頼しない」の Architecture テスト
  (FormRequestProhibitedKeyTest 相当)はどちらの形でも動く形でテンプレに同梱

悩みどころ: `ProhibitsProtectedKeys` のキー集合はドメイン名(site_id/page_id 等)を含む。
テンプレでは「actor/tenant 系の最小集合(user_id / organization_id / project_id / team_id /
created_by_user_id / current_organization_id)+アプリで追記」という構造にするのが現実的。

### Q4. LLM 防御スタックの構成(divergence D3/D8)

共通して入れられるもの(両アプリで実証済み):
- UserInput 型による untrusted 入力の型強制+PHPStan level10
- laravel-prism-prompt(YAML)+ Prism::fake() 規約
- LlmCallLog(コスト記録)
- prompt canary+defensive gate(spirux)
- PromptOperationGuardrail(Prism Facade 直呼び禁止、aigenba)

分岐するもの:
- `config/llm-defense.php`(tool allowlist / alert 先): LLM tools を使うアプリのみ意味がある。
  aigenba は「読まれない config は config theater」として意図的に持たない
- ConversationContextBuilder: マルチターン対話アプリのみ
- EvaluationVariableShield: 「評価対象テキスト」という窓口があるアプリのみ

悩みどころ: テンプレに「全部入り」を置くと新規アプリで config theater が発生する。
**推奨: コア(UserInput+prompt YAML+LlmCallLog+canary+guardrail テスト)のみ同梱し、
tool allowlist / 会話履歴ビルダー / Shield は「LLM 利用形態別のレシピ」として docs に置く。**

### Q5. nested route IDOR 防御の機構(divergence D2)

- spirux: `Route::scopeBindings()`(フレームワーク標準、親→子 relation が前提)
- aigenba: URL 整合 404 guard+org-scoped 解決(relation が無い/int-id API のため)

**推奨: テンプレ既定は scopeBindings(フレームワークのレンジ内)**。テンプレの組織構成
(Q1 で決めた階層)に整合する relation を最初から用意しておけば成立する。
aigenba 型が必要になる条件(多態・attach 操作・int-id API)を D2 ベースで docs 化。
deny-by-default の inventory テスト(NestedRouteIdorDefenseTest 相当)は形を選ばず同梱したい。

---

## 課金まわりの論点

### Q7. 消費モデル: チケット台帳 vs 多次元 Quota(+席数)

実コード上、チケット 2 フェーズ消費(reserve/commit/release+台帳)は両アプリに整列済みで
テンプレ化可能。一方:

- aigenba のみ: 買い切りチケット(TicketPurchase)、席数(additional_seats/SubscriptionItem)、
  Starter→Standard 自動移行、QuotaOverride
- spirux のみ: OrganizationQuota/OrganizationUsage(max_projects 等の多次元リソース上限、
  402 応答、利用状況バー)

悩みどころ: 「従量(チケット)」と「上限(Quota)」は直交する課金プリミティブで、
アプリによってどちらか/両方を使う。
**推奨: Cashier+Plan/PlanPrice+Checkout Saga+Webhook 冪等+チケット台帳+多次元 Quota を
すべて独立モジュールとしてテンプレに入れ、プラン定義(シーダー)で有効化を選ぶ構造。**
席数・買い切り・自動移行はアプリ固有色が強いので「実装例」として docs 送りが無難。
Quota 項目(max_*)はアプリ毎に異なるため、enum/config 1 箇所で定義できる形に。

### Q8. API キーの scope 体系と Platform Key

- aigenba: scope+暗黙包含階層(write ⊃ view/assign)、加えて PlatformApiKey(運用キー)と
  Capability ヘッダ negotiation
- spirux: flat ability(read/write/evaluations:run)

**推奨: flat ability を既定**(理解しやすく、包含階層は ability 定義側で表現可能)。
prefix(`aigb_*` 等)は config 化。PlatformApiKey は「プラットフォーム運用 API が要る
アプリのみ」のオプションモジュール。Capability ヘッダはテンプレ対象外(CLI 連携が濃い
アプリ固有)。

---

## インフラ・既定値の「決め」の論点(悩み小、決めれば終わり)

### Q6. laratrust `strict_check`

aigenba=false(常に team 明示)/ spirux=true。aigenba 側レジストリ自身が「true へ格上げの
価値あり」と認めている。**テンプレは `true` 既定+「権限判定は常に team を明示する」規約を
docs に明記**で確定させてよい。

### Q9. HTTPS 強制とデプロイ前提

aigenba=ALB 終端(app 層 redirect なし)/ spirux=RedirectToHttps(308)。インフラ前提の差。
**推奨: RedirectToHttps をテンプレに含め、env フラグ(例 `FORCE_HTTPS_REDIRECT`)で
無効化できるようにする**(LB 終端構成では off)。`URL::forceScheme`+secure cookie+HSTS は
無条件で同梱。デプロイ docs は「ALB 構成」「単機(Lightsail 等)構成」の 2 レシピを併記するか、
テンプレでは抽象手順にとどめるか要判断。

cache `serializable_classes` は **テンプレ既定 `false`(全 deny)** で確定(D9 の整理どおり、
object cache が必要になったときだけ allowlist 化)。

### Q10. 命名の正規化(テンプレで確定させ divergence の再発を防ぐ)

同じ概念に別名が付いているもの。テンプレの正規名を決める必要がある:

| 概念 | aigenba | spirux | 提案 |
|---|---|---|---|
| 管理者モデル | `Admin` | `AdminUser` | `AdminUser`(guard 名と整合) |
| ソーシャル連携 | `UserSocialAccount` | `SocialAccount` | `SocialAccount` |
| 組織管理者ロール | `organization_administrator` | `organization_admin` | `organization_admin`(短い方) |
| 監査ログ | `AuditLog` | `SecurityAuditEvent`(+両者 `ModelAudit`) | 要決定: 用途が微妙に違う可能性あり、実装比較してから |
| 課金モデル配置 | `Models/Billing/` 名前空間 | `Models/` 直下 | `Models/Billing/`(モジュール境界が明確) |

※ 監査まわりは aigenba `AuditLog` / spirux `SecurityAuditEvent` の責務範囲(認証イベント・
critical action・model audit の切り分け)を実装比較してから正規形を決めるのが安全。

### Q11. メール変更フロー

aigenba=Fortify レンジ+旧アドレス通知(T697 実装済)/ spirux=pending_email+トークン
(ただし spirux 側で Fortify レンジへの簡素化が HM1/R6 として提案済)。
**テンプレは aigenba 型(Fortify+旧アドレス通知)で確定**してよい。両チームの結論と一致する。

---

## 開発プロセス資産の論点

### Q12. 自走スキル・運用ドキュメントのテンプレ化

両アプリに `{app}-autopilot / design / implement / todo-add / todo-close / codex-review /
update-docs` がほぼ同形で存在する。これはコードよりテンプレ化価値が高い可能性がある。

悩みどころ:
1. **名前空間**: スキル名にアプリ名が埋まっている(`aigenba-implement` 等)。テンプレでは
   プレースホルダ(`{app}-implement`)にして init スクリプトでリネームするか、
   アプリ名非依存の名前(`app-implement`)に統一するか
2. **スキル内のパス・ID 依存**: TODO.md の場所、devnotes 規約、Figma file ID(spirux)等の
   ハードコードを config/frontmatter に抽出する必要がある
3. **差分レジストリ運用の転用**: 2 アプリ間の divergence registry は、テンプレ化後は
   「テンプレ ↔ 各アプリ」の差分管理(テンプレ更新の取り込み・逆輸入)に形を変えるべき。
   `docs/template-divergence.md` のような「テンプレからの意図的逸脱の記録」フォーマットを
   テンプレ自体に同梱する案
4. **Figma 系スキル(spirux のみ)**: design system 運用をテンプレ標準にするかはチーム判断。
   オプション扱いが無難
5. **ヘルプシステム(aigenba のみ)**: manifest+audience 別 Markdown の機構は汎用。
   機構のみテンプレに入れ、ページは雛形 1 枚にする案

### その他メモ

- PHP バージョン: aigenba ^8.4 / spirux ^8.3。テンプレは ^8.4 に揃える
- spirux の docs(billing-quota 等)は実コードより古い箇所がある。テンプレ抽出の際は
  docs でなく実コード(+migration)を正とすること
- 両アプリの「Scenario」は別概念。テンプレにドメイン雛形を置くなら固有名は避ける
