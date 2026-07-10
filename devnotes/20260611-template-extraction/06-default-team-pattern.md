# Default Team パターン(組織階層の表示上スキップ)

> Q1 決定の補足仕様(2026-06-11 オーナー指示)。
> スキーマ・ロジックは常に `Organization → CustomTeam → Project` の 3 階層を持つ。
> 部門概念が不要なアプリでは、組織ごとに **Default Team** を自動生成し、**表示上だけ** Team 層を
> スキップする。アプリ毎にスキーマを分岐させない。

## 基本原則

1. **データモデルは常に 3 階層**。`projects.custom_team_id` は NOT NULL。
   「Team を使わないアプリ」でもプロジェクトは必ずどれかの CustomTeam に属する。
2. **Team 層の有無は表示の問題であって構造の問題ではない**。アプリの設定フラグ
   (例: `config('template.teams_visible')`)が UI・ナビゲーション・フォームの露出だけを制御する。
3. **後から Team を可視化できる**。フラグを立てれば既存データはそのまま生き、
   既存プロジェクトは Default Team 所属のまま、新しい部門を作って移動できる。
   逆方向(可視→不可視)も、全プロジェクトを Default Team に集約すれば戻れる。

## データ仕様

### Default Team の生成と不変条件

- Organization 作成時(Personal Organization 自動生成を含む)に、トランザクション内で
  Default Team を 1 つ自動生成する。Organization 作成のあらゆる経路
  (画面 / API / プロビジョニングスクリプト / Factory)がこれを通ること。
- カラム: `custom_teams.is_default` (boolean)。
- 不変条件:
  - **どの Organization にも Default Team がちょうど 1 つ存在する**
    (部分 unique index: `UNIQUE(organization_id) WHERE is_default = true`)。
  - **Default Team は削除できない**(Policy + Service 層で拒否。組織削除のカスケードのみ例外)。
  - teams_visible = false のとき、**すべてのプロジェクトは Default Team に属する**
    (作成経路で強制。既存データには Architecture/Feature テストで担保)。
- 名前: 生成時は組織名をコピー(または固定文字列 `Default`)。表示されないので実害はないが、
  可視化したときに違和感がないよう組織名コピーを推奨。

### 権限との関係(重要)

CustomTeam は **認可のスコープではない**。認可の軸は次の 2 つだけ:

- **Organization**(Laratrust team。`organizations.laratrust_team_id` と 1:1)
- **Project**(project membership / project ロール)

CustomTeam は「組織内のプロジェクトのグルーピング」であり、CustomTeam に属すること自体は
権限を発生させない(CustomTeam の管理操作は組織レベル permission で gate する)。
したがって **Default Team パターンは認可ロジックに一切影響しない**。
aigenba の D1 にあるとおり `laratrust_team_id` と `custom_team_id` は別物であり、
mass-assignment 防御の prohibited キー集合には**両方**入れる。

## ルーティング仕様

フラグを切り替えても URL が壊れないよう、**ルート形状は Team の可視性に依存させない**:

- プロジェクト系 Web ルートは Team セグメントを含めない:
  `/projects/{project}/...`(current organization + org-scoped 解決。Q5 の URL 整合 guard 方式)
- API も同様(Q3 の nested route): `/api/v1/projects/{project}/...`
- Team 管理ルート(`/teams`, `/teams/{team}/...`)は **teams_visible = true のときだけ登録**する
  (route ファイルで config 分岐)。false のとき 404(存在自体を露出しない)。

この設計は Q5 で URL 整合 guard + org-scoped 解決(relation 非依存)を選んだことと整合する。
project の解決は「current organization に属するか」で行い、team hop を要求しない。

## UI 仕様(teams_visible = false のとき)

- ナビゲーション・パンくず: `組織 → プロジェクト` の 2 階層表示。Team 名はどこにも出さない。
- プロジェクト作成フォーム: Team 選択を出さない。Controller/Service が Default Team を自動割当。
- 組織設定画面: Team 管理セクションを出さない。
- メンバー画面: Team 列・Team フィルタを出さない。
- 管理画面(Filament): CustomTeam リソースは表示してよい(運用者向け)が、
  Default Team の削除・is_default の付け替えは禁止。

## teams_visible = true に切り替えるときの手順(可視化マイグレーション)

1. config フラグを true にする(Team 管理ルート・UI が現れる)。
2. Default Team はそのまま残す(リネーム可)。既存プロジェクトの所属は変わらない。
3. 部門を作成し、プロジェクトの所属 Team 変更機能(テンプレに同梱)で移動する。
4. 必要なら Quota 項目 `max_teams` を有効化する(Default Team はカウント対象外とする)。

## テスト・Factory 規約

- `OrganizationFactory` は afterCreating で Default Team を必ず生成する
  (= アプリのどのテストでも「Org には Default Team がある」前提が成り立つ)。
- `ProjectFactory` は team 未指定なら所属組織の Default Team に割り当てる。
- 同梱する不変条件テスト:
  - 全 Organization に Default Team がちょうど 1 つ(Feature)
  - Default Team 削除拒否(Feature)
  - teams_visible=false でのプロジェクト作成が Default Team に割当(Feature)
  - `custom_team_id` が FormRequest の prohibited キー集合に含まれる(Architecture)
- Dusk/画面テストは両モードを意識せず書けるよう、既定 false でシナリオを用意し、
  Team 可視モードのテストは Team 機能を使うアプリだけが有効化する。

## LLM(設計者)向けの判定基準

アプリ要件を読んで Team 層の扱いを決めるときの規則:

| 要件にあるもの | 判定 |
|---|---|
| 「部署」「部門」「課」「グループ」「チーム単位の集計/割当」 | teams_visible = **true**。CustomTeam にマップ |
| 組織とプロジェクトしか登場しない | teams_visible = **false**(既定)。Default Team パターン |
| 「チーム」がプロジェクトメンバーの意味で使われている | CustomTeam ではなく **project membership** にマップ(誤用注意) |
| 階層が 4 段以上必要(事業部→部→課 など) | テンプレの範囲外。CustomTeam の自己参照化は安易にやらない。設計判断としてエスカレーション |

いずれの場合も**スキーマ・Service・防御層のコードは変更しない**。変えてよいのは
config フラグ・UI・Quota 項目・シーダーだけ。
