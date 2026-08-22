# 詳細設計レビュー Round 3

Round 2 の [Critical] 12 件・[Warning] 9 件・[Suggestion] 3 件への対応を反映した。
停止条件 6 点はすべて塞いだ。対応マトリクスと修正後の全文を示す。

**特に方針が変わった 1 点**: 退会予約のアプリ内通知の org 文脈は、fan-out の採用を**取り下げ**、
**本設計の確定前提（要 auth / account-deletion 側の裁定）**として分離した。
設計側では選ばず、候補 3 案と副作用だけを記した。帰結として
`current_organization_id` の撤去 migration も裁定が出るまで着手しない（本設計が受け入れる依存）。

# 対応マトリクス: design-review Round 2

Critical 12 件 / Warning 9 件 / Suggestion 3 件。**すべてに対応した**（反論 0 件）。

---

## 停止条件 6 点への対応（総括）

| # | 停止条件 | 対応 |
|---|---|---|
| 1 | 単位 A では current 列書き込みをまだ撤去しない | **対応**。変更単位表に「単位 A では current 列に触らない」行を追加し、`provision()` の docblock にも明記。撤去は単位 B（施策 7）へ |
| 2 | slug 候補を Requested/Derived/Fallback で区別し、rollback 後に有限再試行 | **対応**。`SlugCandidateOrigin` enum + `SlugCandidate` DTO + 由来ごとの遷移表 + **1 試行 = 1 savepoint** |
| 3 | 改名の 30 日境界と `nextAvailableAt` を一致させ、認可・422 変換点を追加 | **対応**。窓を `> cutoff`（境界を含まない）へ / 認可節 + inventory 登録 / 例外変換点の表 |
| 4 | account-deletion 通知 fan-out は他 feature の裁定が要る | **対応**。fan-out の採用を取り下げ、**本設計の確定前提（要裁定）**として分離 |
| 5 | `/app`・`/go` の `EntryTarget` 注入を実装可能な形へ | **対応**。route 名の固定表から写す形へ（parameter が無いので enum binding は働かない） |
| 6 | 施策 9 の親鎖と施策 10 の URL 抽出を fail-closed に完成させる | **対応**。親鎖の 5 検証 / ファイル種別ごとの抽出 + root 一致判定 |

---

## 施策 1

### [Warning] migration のコメントが「構文と予約語の両方」だが予約語検査は施策 2 の別 migration
- 判断: **対応する** — 責務を分けた。`000100` = 正規化・構文・衝突、`000200` = 予約語、と
  コメントにも明記した。

### [Warning] CHECK 制約の `down()` が無い
- 判断: **対応する** — `ALTER TABLE ... DROP CONSTRAINT IF EXISTS organizations_slug_syntax` を
  `down()` として明記した。既存の `organizations_slug_unique` は本 migration が作っていないので触らない。

### [Warning] 制約テストの直接 `insert()` は「Factory で生成」に抵触し、他の必須列制約が先に発火する
- 判断: **対応する** — **Factory で正常な組織を作り、
  `DB::table('organizations')->whereKey(...)->update(['slug' => 'Acme'])` で値オブジェクトを迂回**する形へ変えた。

---

## 施策 2

### [Critical] 将来の予約語追加時の既存データ検査義務が修正後全文から消えている
- 判断: **対応する**
- 根拠: 概念設計 Round 4 で確定した契約が、詳細設計への書き起こしで落ちていた。指摘のとおり。
- 対応内容: 「予約語を増やすときの運用契約」節を復活させ、
  **config の冒頭 docblock（正本）と `docs/app-integration-guide.md`** に置くことを明記した。
  併せて **「機械では強制しない」**ことも書いた（config に語を足すだけで検査が走る仕組みは持たない。
  AGENTS.md の「保証範囲を誇張しない」に従う）。

### [Warning] migration が可変 config を直接読むため、過去 migration の意味が将来変わる
- 判断: **対応する**
- 根拠: そのとおり。新規環境の構築や再実行で「当時は通った migration が落ちる」ことが起きる。
- 対応内容: **初版予約語のスナップショットを migration 内の `private const array` に固定**し、
  config を読まない形へ変えた。将来の追加は「同じ変更に新しい migration を足す」運用契約が担う。

---

## 施策 3

### [Critical] 30 日境界（`>= cutoff`）と `nextAvailableAt`（`oldest + 30 日`）が食い違う
- 判断: **対応する**
- 根拠: 指摘が正しい。包含境界のままだと `oldest + 30 日`ちょうどの時刻でまだ窓内で、
  **画面の案内どおりに操作しても改名できない**。
- 対応内容: 窓を **`renamed_at > now - 30 日`（境界を含まない）** に統一し、
  docblock に理由を書いた。テストも「ちょうど 30 日前の履歴は窓に含まれない」
  「`nextAvailableAt` ちょうどの時刻で実際に改名できる」へ書き換えた。

### [Critical] 変更系 PATCH の認可が抜けている
- 判断: **対応する**
- 根拠: binding の 404 だけでは **same-org の一般メンバーによる改名**を防げない。
- 対応内容: 「認可と例外変換点」節を新設し、`Gate::authorize('update', $organization)` と
  `ControllerAuthorizationGateTest` の inventory 登録を明記した。
  Feature テスト（一般メンバー 403 / cross-org 404 / Owner 成功）を追加した。

### [Critical] domain 例外を 422 へ変換する層が未設計（素のままだと 500）
- 判断: **対応する**
- 対応内容: 変換点の表を作り、**FormRequest（入力の妥当性）と Controller（競合の結果）の 2 点だけ**に
  一本化した。構文・予約語・同一識別名は FormRequest のカスタムルール、
  回数上限と一意衝突は Controller で `ValidationException::withMessages()` へ。
  **それ以外の `QueryException` は変換せず再送出**。

### [Warning] `count() >= LIMIT` から `first()` の非 null 性は推論されない
- 判断: **対応する** — `Assert::isInstanceOf($oldest, OrganizationSlugRename::class)` で絞ってから
  例外へ渡す形にした。

---

## 施策 4

### [Critical] 導出値の一意衝突で同じ導出値を 3 回繰り返す可能性
- 判断: **対応する**
- 対応内容: 候補の由来を **`SlugCandidateOrigin`（Requested / Derived / Fallback）** の型で保持し、
  遷移表を確定した。Requested の衝突は**即 422**、Derived の衝突は **Fallback へ 1 回だけ遷移**、
  Fallback の衝突は**新しい乱数で最大 3 回**。

### [Critical] PostgreSQL では一意違反後、同じ transaction 内で再試行できない
- 判断: **対応する**
- 根拠: 指摘が正しい。違反時点でトランザクションが中断状態になり、rollback まで次のクエリを実行できない。
- 対応内容: **1 試行 = 1 savepoint**（Laravel のネスト transaction が savepoint として実装される）にし、
  失敗したら savepoint まで巻き戻してから次候補を試す形にした。
  **失敗した試行の Team / Default Team / role 付与が残らない**ことを Feature テストで固定する。
  **別の一意違反は隠さず再送出**することもコードに書いた。

### [Critical] 単位 A で current 列書き込みを消すと単位 A 単体が機能的に成立しない
- 判断: **対応する**
- 根拠: 完全にそのとおり。単位 B 前は現行 current-org 方式が生きているので、
  新規登録者の current が空のまま業務画面へ行けなくなる。
- 対応内容: 変更単位表へ「**単位 A では current 列に触らない**」行を追加し、
  `provision()` の docblock にも「単位 A では書き込みを残す。撤去は単位 B（施策 7）」と明記した。

### [Warning] 単位内部の途中コミットと「全 green でコミット」が緊張する
- 判断: **対応する**
- 対応内容: 「分ける場合も 1 コミットずつ `composer test` / `composer phpstan` /
  フロント検証が green であること。green を保てない分け方をするなら**単位 1 コミットに squash する**」と明記した。

---

## 施策 5（APPROVE）

### [Suggestion] 動的 route 名・PHP の FQCN 解決・TS の route helper を同じ抽出器で扱えない
- 判断: **対応する**
- 対応内容: gate を**言語別に 2 本**に分けた（PHP: `OrganizationRouteGenerationTest` /
  TS: `tests/js/architecture/organization-route-generation.test.ts`）。
  **動的に組み立てた route 名は未解決台帳へ登録し、登録の無い未解決は fail-closed**。

---

## 施策 6（APPROVE）

### [Suggestion] キー集合だけでなく nullable / 数値 / boolean の型も比較対象に
- 判断: **対応する**
- 対応内容: 契約テストを「**キー集合だけでなく各値の型**（nullable / 数値 / 真偽）が一致すること」へ広げた
  （キーだけ比べると `role: string|null` が `role: string` に化けても緑になる）。

---

## 施策 7

### [Critical] 「全所属組織へ通知」は後退でなくとも別 feature の仕様変更（1 件 → N 件）
- 判断: **対応する**（fan-out の採用を取り下げた）
- 根拠: 指摘が正しい。未読件数の増加・重複表示・既読状態の分裂・保存量の増幅が起き、
  「後退でない」ことは他 feature を変更してよい根拠にならない。
- 対応内容: **本設計の確定前提（要 auth / account-deletion 側の裁定）**として分離した。
  - 施策 7 の該当部分と `current_organization_id` 撤去 migration は**裁定が出るまで着手しない**
    （この 1 箇所が列の最後の利用者になるため）。**これは本設計が受け入れる依存**と明記した。
  - 判断材料として候補 3 案（(a) 作らない / (b) 全所属へ配る / (c) org 文脈を nullable にする）と
    その副作用だけを記し、**設計側は選ばない**とした。
  - (b) が採られる場合に足すべき契約（fan-out 上限・バルク生成方式・部分失敗時のトランザクション境界・
    既読の単位・退会後の参照可否）も列挙した。
  - スコープ外表・実装モード表（着手の前提条件）・リスク表（R9）へも反映した。

### [Warning] fan-out は所属数に比例して通知行とイベント処理が増える
- 判断: **対応する** — 上記 (b) の欄に、採用時に定義すべき項目として明記した。

---

## 施策 8

### [Critical] parameter を持たない固定 route に backed enum を注入できない
- 判断: **対応する**
- 根拠: そのとおり。Laravel の enum binding は route parameter に対して働く。
- 対応内容: `__invoke(Request $request)` に戻し、**現在の route 名を固定表
  （`TARGET_BY_ROUTE`）へ写して `EntryTarget` を得る**形にした。
  固定表に無い route から呼ばれたら `Assert::keyExists()` で **fail-closed（500）**。

### [Warning] `count()` と `sole()` が別クエリで、間で membership が変わると例外
- 判断: **対応する**
- 対応内容: membership を **1 回だけ**取得し、同じ Collection に対して
  `isEmpty()` / `count()` / `sole()` / DTO 変換を行う形にした。

---

## 施策 9

### [Critical] `RelationScoped` の親を 2 種別に限ると多段 relation を表現できない（説明とも矛盾）
- 判断: **対応する**
- 対応内容: 親として **別の `RelationScoped` も許可**し、
  **親鎖が最終的に `PrimaryKeyBinding` か `ActorDerived` へ到達すること**を gate が検証する形にした。
  enum の docblock も直した。

### [Critical] `parentResolutionId` は循環参照を作れる（`A → B → A`）
- 判断: **対応する**
- 対応内容: 親鎖の**検証 5 項目**を表にした —
  (1) `resolutionId` の入口内一意性 / (2) 親の実在 / (3) 自己参照禁止 /
  (4) **循環禁止**（訪問済み集合で検出）/ (5) 最終 root 到達。
  `RelationScoped` 以外に親が付いていたら余剰登録として赤。
  負例に 5 形（重複 ID / 親不在 / 自己参照 / 循環 / 根に到達しない鎖）と
  **多段の正例**を置くことにした。

### [Warning] `entryPointId` を解決点にも重複保持すると SoT が二重になる
- 判断: **対応する**
- 対応内容: 解決点 DTO から `entryPointId` を**外した**。
  **台帳のキーが入口の唯一の SoT** であることを docblock に書いた。

### [Suggestion] provenance enum は `app/Enums` ではなく `tests/Support` へ
- 判断: **対応する**
- 対応内容: `tests/Support/Security/OrganizationReferenceProvenance.php` へ移した
  （production の振る舞いに現れない検査のための語彙であるため）。

---

## 施策 10

### [Critical] 6 文字の区切り集合では Markdown の `/dashboard)` `/projects,` `/billing。` を拾えない
- 判断: **対応する**
- 根拠: 走査対象に `docs/` `doc/` `README*` を入れておきながら、その記法を拾えないのは穴である。
- 対応内容: **抽出をファイル種別ごとに分けた**（PHP/TS/Svelte は文字列リテラルを構文で抽出 /
  Blade・HTML は属性値 / Markdown は**リンク宛先 + プレーン URL**で終端に
  空白・改行・`)` `]` `>` `"` `'` `` ` `` `,` `;` `。` `、` `）` を含める / JSON は値）。
  **区切り集合は種別ごとに宣言**し、種別が増えたら未分類として赤になる。

### [Critical] `/projects/` だけを見ると root の `/projects` を検出できない
- 判断: **対応する**
- 対応内容: 判定を **「query と hash を落として正規化した path が、root と完全一致するか
  `root/` で始まる」**に定義し直した。`/app` も同じ形（かつ
  `/organizations/{slug}/app…` でないこと）で判定する。

### [Warning] `/organizations/acme/app` を「接尾辞つき」の負例と呼ぶのは規約 (e) の意味とずれる
- 判断: **対応する**
- 対応内容: **正例群（検出すべき旧 URL）/ 誤検出してはいけない新 URL 群 /
  規約 (e) の 3 形（`/myapp` `/app-old` `/appx`）** の 3 つに分けた表にした。

---

## 施策 11（APPROVE）

### [Suggestion] 通知 fan-out は乖離登録の要否とは別に auth 側の裁定が要る
- 判断: **対応する** — 施策 7 で確定前提として分離済み。D40 のみ追加（`DIVERGENCE_ENTRY_COUNT` 36 → 37）
  の方針は変わらない。


---

## 修正後の詳細設計 (全文)

# 詳細設計: organization-tenancy-ag-catchup

家系の機能台帳 (lctl) feature `organization-tenancy` (revision `46-c1830b632b4d` /
ledger `81f0e624363b0c707a424c0695253eb6d1536451`) への追従。
aicue セルは `status: pending` / `assessment: divergence_candidate`。

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  （撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) /
> 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、
  個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- 新モデルを追加する設計では **対応する Factory の作成も施策に含める**
- **DTO + JsonResource** パターン（AGENTS.md 参照）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript + **PostgreSQL 18**
- `declare(strict_types=1)` + 日本語コメント（git 追跡下の PHP 全数。免除簿なし）
- 月/年/四半期の加減算は `*NoOverflow` 系を明示（`CarbonOverflowArithmeticGateTest`）

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md) — APPROVED (conceptual-review Round 4)

---

## 正典の不変条件（全数）と本設計での扱い

**この表が本設計のスコープの定義である。** 正典 (`organization-tenancy` revision 46) が
掲げる不変条件を全数列挙し、1 行ずつ「aicue の現状 / 本設計での扱い」を書く。

| # | 正典の不変条件 | 裁定 | aicue の現状 | 本設計 |
|---|---|---|---|---|
| I1 | URL に現れた資源が現在の組織に属することを**入力検証より前**の段で確かめ、違えば **404**（403 にしない） | AG-036 | **充足**（aicue 形が標準形として採用された側。`MembershipScopedOrganizationBinder` + `project.in-route-org` + `TenantBoundaryOrderingTest`） | **スコープ外**（層の位置は動かさない。org の取得元だけ変える） |
| I2 | 「いまどの組織か」は **URL だけ**で決まる。保持列と切替 endpoint は**存在してはならない**（2 方式の併存不可） | AG-037 | **未充足**（`users.current_organization_id` + `organizations.switch` + `CurrentOrganizationResolver` の自己修復） | **施策 5〜8** |
| I3 | 個人組織を**種別として区別しない**（種別フラグを撤去） | AG-038 | **未充足**（`organizations.is_personal`） | **施策 4** |
| I4 | 初期組織生成の冪等判定は「**所属組織が 0 件か**」。トランザクション内で利用者行を**行ロック**してから数える | AG-038 | **未充足**（種別フラグ判定・行ロックなし） | **施策 4** |
| I5 | 初期組織生成の**呼び出しサイトを機械検査で固定**する | AG-038 | **未充足**（検査なし） | **施策 4** |
| I6 | 識別名の文字種は**小文字英数字とハイフンのみ**。先頭末尾ハイフン不可・連続ハイフン不可。**大文字は小文字へ正規化** | AG-039b | **未充足**（`Str::slug()` 直呼び。検証なし） | **施策 1** |
| I7 | 識別名の一意性は**大文字小文字を区別しない** | AG-039c | **未充足**（通常の unique のみ） | **施策 1** |
| I8 | 識別名の規則は**値オブジェクト 1 本**に集約し、作成（利用者が選べる。省略時は組織名から導出）と改名の両経路が通る | AG-039 | **未充足** | **施策 1 / 3** |
| I9 | 予約語を持ち、**理由 3 分類**（ルート衝突 / 権威の詐称 / 構文衝突）の記載を必須にする | AG-039 | **未充足**（設定ファイルが存在しない） | **施策 2** |
| I10 | **識別名の位置に現れる固定セグメントが予約語に登録されている**ことを route 表から機械検査する | AG-039 | **未充足** | **施策 2** |
| I11 | 予約語は**保存できない**（作成・改名の両経路で拒否） | AG-039 | **未充足** | **施策 2** |
| I12 | 改名は **30 日あたり 5 回**まで。最終権威は**組織行を行ロックした後の再判定**（事前判定は画面表示のための早期拒否） | AG-046 | **未充足**（改名経路なし） | **施策 3** |
| I13 | **旧識別名は予約せず解放する**（履歴表に一意制約を張らない） | AG-046 | **未充足** | **施策 3** |
| I14 | 機械が使う経路は**不変の内部識別子**で組織を指す | AG-047 | 実態は満たすが**検査なし** | **施策 9** |
| I15 | 権限ライブラリのチーム厳格チェックが `true` | AG-040 | **充足**（`config/laratrust.php`） | **スコープ外**（検査の新設は「還流候補」＝未確定） |
| I16 | 階層は Organization → CustomTeam → Project の 3 階層 | boundary | **充足** | **スコープ外** |
| I17 | Default Team パターン（組織ごとにちょうど 1 つ） | boundary | **充足**（`OrganizationProvisioningService`） | **維持**（施策 4 で壊さない） |
| I18 | 登録トランザクション内で組織を 1 つ作る | boundary | **充足** | **維持**（施策 4） |

### スコープ外（正典が aicue に求めていない / 未確定 / 他所有）

| 項目 | 理由 |
|---|---|
| AG-036 の層の新設 | I1 のとおり充足済み。**aicue 形が標準形**として採用された側 |
| AG-040 の配布物（`TeamScopedRoleCheckInvariantTest` + 走査器） | 台帳が **「還流候補」** と明記する未確定項目。aigenba にのみ実在 |
| 正典の版の呼び名（t0 → t1 等） | 台帳が「未確定（議題）」。**キュレーターの責務** |
| lctl への書き込み（`append_event` 等） | 設計エージェントの責務外 |
| 組織そのものを削除する route | 正典 boundary が「route を持つのは aigenba と spirux の 2 本」と書く。aicue に無いことは逸脱ではない |
| 招待の役割列 | 台帳が「auth-invitation-flow 側の事実。ここでは触れない」と明記 |
| 接続の取り消し層 / MCP 組織書き込みスコープ | 所有は `mcp-org-write-scope`（AG-125） |
| 認証後の着地画面の組織アクセス状態（4 値） | 所有は auth 側（AG-113） |
| シート課金同期 / org-tree versioning / Filament 管理パネルの権限境界 | 正典 boundary が「含まない」と明記 |
| 旧 URL からの転送・並走 | 思考原則 3 + 正典の実装判断 |
| **退会予約のアプリ内通知の org 文脈をどうするか** | **本設計の確定前提（要 auth / account-deletion 側の裁定）**。設計側では選ばない。詳細と候補は施策 7 に記す |
| 組織別の動的 manifest | `start_url` は分岐 route で足りる（思考原則 2） |

---

## 変更単位（原子性）— AG-037 の「2 方式併存不可」を守るための必須制約

**施策の順序だけでは足りない。** 途中状態が「保持列方式と URL 方式の併存」になったり、
「構文型だけを保存できる経路」が残ったりすると、それ自体が裁定違反である。
そこで**マージ前に成立していなければならない原子的な変更単位**を 2 つ定義する。

| 単位 | 含む施策 | なぜ原子的でなければならないか |
|---|---|---|
| **単位 A**（識別名） | 施策 1 / 2 / 4 の provisioning シグネチャ変更 | `AssignableOrganizationSlug` の導入・Service シグネチャ変更・Factory を含む**全保存元の切替**が同じ変更で閉じないと、中間状態に「構文型だけを保存できる経路」が残る |
| （**単位 A では current 列に触らない**） | — | 単位 A で `provision()` から `current_organization_id` の書き込みを消すと、**単位 B の前の現行 current-org 方式で新規登録者の業務画面が動かなくなる**（単位 A 単体が機能的に成立しない）。**current 列の書き込み撤去は単位 B に残す** |
| **単位 B**（組織 URL 単一方式） | 施策 5 / 6 / 7 / 8 | 施策 5 で URL 方式を足し、施策 7 まで保持列方式が残る状態は **AG-037 の「2 方式併存不可」に真正面から抵触**する |

- 単位の内部はコミットを分けてよいが、**分ける場合も 1 コミットずつ
  `composer test` / `composer phpstan` / フロント検証が green であること**
  （AGENTS.md「全 green でコミット」）。green を保てない分け方をするなら**単位 1 コミットに squash する**。
- **単位の途中状態を main へマージしない・デプロイしない・共有しない**。
- worktree では `todo/<id>` ブランチ上で単位ごとに squash してから main へマージする。
- 単位 A → 単位 B → 施策 9 → 施策 10 → 施策 11 の順で進める
  （単位 B の route 移設は識別名が堅くなってから行う）。

---

## 施策一覧

| # | 施策名 | 単位 | 主な変更ファイル | 優先度 |
|---|--------|---|------------|--------|
| 1 | 識別名の型 2 段（構文型 + 保存可能型）と DB 制約 | A | `app/Support/Organization/*`(新) / migration(新) / `Organization` | Critical |
| 2 | 予約語（理由 3 分類必須）と route 表整合の機械検査 | A | `config/organization-slug-reserved.php`(新) / gate(新) | Critical |
| 3 | 改名経路（30 日 5 回・旧識別名は解放） | — | `OrganizationSlugRename`(新) / limiter(新) / controller(新) / route / Svelte | High |
| 4 | 種別フラグ撤去 + 初期組織生成の行ロック冪等判定 | A | `OrganizationProvisioningService` / migration(新) / gate(新) | Critical |
| 5 | 業務 route の組織 URL 配下への移設（**route 名は維持**） | B | `routes/web.php` / 23 controller / route 生成の全呼び出し元 | Critical |
| 6 | 組織文脈の binding 由来化（共有プロパティ・middleware） | B | `HandleInertiaRequests` / `EnsureProjectBelongsToRouteOrganization`(改称) / `shared-props.ts` | Critical |
| 7 | 保持列・切替 route・自己修復の撤去 | B | `User` / `OrganizationSwitchController`(削除) / `CurrentOrganizationResolver`(削除) / migration(新) | Critical |
| 8 | 組織文脈を持たない入口の分岐 route | B | `OrganizationEntryController`(新) / `Organizations/Choose.svelte`(新) | High |
| 9 | 機械経路の組織識別子契約（2 層の全数分類） | — | `tests/Support/Security/*`(新) / gate(新) | High |
| 10 | 旧 URL の走査根ベース残存検査 | — | gate(新) / 棚卸し | High |
| 11 | 乖離台帳の更新（D4 書き換え / D40 追加 / 採用時債務） | — | `docs/template-divergence.md` / `LedgerPins.php` / `adoption-debt.tsv` | Critical |

---

## 施策 1: 識別名の型 2 段と DB 制約

満たす不変条件: **I6 / I7 / I8**

### 変更箇所

- 新設: `app/Support/Organization/OrganizationSlug.php`（**構文型**）
- 新設: `app/Support/Organization/AssignableOrganizationSlug.php`（**保存可能型** = 構文妥当 かつ 非予約語）
- 新設: `app/Support/Organization/OrganizationSlugConstraintViolation.php`（一意制約違反の**制約名まで**識別）
- 新設: `app/Exceptions/Organization/InvalidOrganizationSlugException.php` /
  `ReservedOrganizationSlugException.php`
- 新設 migration: `2026_08_23_000100_constrain_organization_slug.php`
- 変更: `app/Services/Organization/OrganizationProvisioningService.php`
- 変更: `app/Models/Organization.php`（`slug` を `$fillable` から外す）
- 変更: `database/factories/OrganizationFactory.php`

### 長さの根拠（**正典外の制約を足さない**）

正典 boundary は「識別名の生成・値検証（**文字種・長さ**・予約語）」と書くが、**具体的な値は定めていない**。
そこで**既存の DB 列が定める長さだけ**を使う:

- `organizations.slug` は `$table->string('slug')` = **varchar(255)** → `MAX_LENGTH = 255`。
- **下限は「空でないこと」だけ**とする。`MIN_LENGTH = 3` のような値は正典にも DB 列にも根拠が無く、
  「将来のサブドメイン化」は思考原則 2 に反するので**採らない**（必要になったら別裁定として起こす）。
- 空でないことは構文の正規表現が既に保証する（`[a-z0-9]` を 1 文字以上要求する）ので、
  長さの検査は上限 1 本になる。

### 現行コード

```php
// app/Services/Organization/OrganizationProvisioningService.php
private function uniqueSlug(string $name): string
{
    $base = Str::slug($name) ?: 'org';

    return $base.'-'.Str::lower(Str::random(6));
}
```

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Support\Organization;

use App\Exceptions\Organization\InvalidOrganizationSlugException;
use Illuminate\Support\Str;

/**
 * 組織識別名の **構文** を表す不変の値オブジェクト (家系裁定 AG-039b / AG-039c)。
 *
 * ★不変条件は「構文的に妥当で正規化済み」だけである。**保存してよいことは意味しない** —
 *   予約語でないことは AssignableOrganizationSlug が担う (裁定 AG-039)。
 * ★正規化は **大文字を小文字へ倒すことだけ**である。前後の空白除去・記号の除去・連結は
 *   一切しない (矯正すると、利用者が入れた値と保存される値が黙って食い違う)。
 * ★長さの上限は organizations.slug 列 (varchar 255) に由来する。下限は「空でないこと」だけで、
 *   正典にも列にも根拠の無い最小長は設けない。
 */
final readonly class OrganizationSlug
{
    /** organizations.slug 列 (varchar 255) に由来する上限。 */
    public const int MAX_LENGTH = 255;

    /**
     * 小文字英数字とハイフン。先頭末尾はハイフン以外、連続ハイフンなし。
     * ★`^`/`$` ではなく `\A`/`\z` を使う — `$` は末尾の改行 1 文字を許すため、
     *   "acme\n" が通ってしまう。
     */
    public const string PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/';

    private function __construct(public string $value) {}

    /**
     * 文字列から構文型を作る **唯一の検査点**。
     * 利用者入力も、組織名からの導出結果も、必ずここを通る。
     */
    public static function fromString(string $input): self
    {
        // 前後の空白は「不正な入力」として拒否する (黙って落とさない)
        $normalized = Str::lower($input);

        if (mb_strlen($normalized) > self::MAX_LENGTH) {
            throw InvalidOrganizationSlugException::tooLong($input);
        }
        if (preg_match(self::PATTERN, $normalized) !== 1) {
            throw InvalidOrganizationSlugException::malformed($input);
        }

        return new self($normalized);
    }

    /**
     * 組織名から識別名を導出する。導出できなければ null を返す
     * (日本語名は Str::slug が空を返す)。**代替の識別名を決めるのは Service の責務**であり、
     * 値オブジェクトが 'org' のような値を捏造しない。
     */
    public static function deriveFromName(string $name): ?self
    {
        $candidate = trim(mb_substr(Str::slug($name), 0, self::MAX_LENGTH), '-');
        if ($candidate === '') {
            return null;
        }

        // ★切り詰め後の候補も必ず同じ検査点を通す (private constructor を直接呼ばない)
        try {
            return self::fromString($candidate);
        } catch (InvalidOrganizationSlugException) {
            return null;
        }
    }
}
```

```php
/**
 * **保存してよい**組織識別名。不変条件は「構文的に妥当 かつ 非予約語」。
 *
 * ★生成と昇格は別操作である。構文型を作るのが「生成」、予約語判定器を通して
 *   この型にするのが「昇格」。
 * ★organizations.slug を書ける経路は**この型を受ける 1 本だけ**で、構文型を保存へ渡す道は
 *   型で消えている (OrganizationSlugWritePathTest が deny-by-default で固定する)。
 */
final readonly class AssignableOrganizationSlug
{
    private function __construct(public string $value) {}

    public static function promote(OrganizationSlug $slug, OrganizationSlugReservedWords $reserved): self
    {
        // ★1 回の取得で分岐する (contains → reasonFor の 2 回呼びは、理由の非 null 性を
        //   PHPStan が導けない)
        $reason = $reserved->reservationFor($slug);
        if ($reason !== null) {
            throw new ReservedOrganizationSlugException($slug, $reason);
        }

        return new self($slug->value);
    }
}
```

migration（**既存の unique index を再追加しない**。順序を固定する）:

```php
public function up(): void
{
    // 1. 更新せずに、正規化後の値が **構文** を満たすかを全行検査する
    //    (小文字化だけでは I6 の文字種・先頭末尾/連続ハイフンを守れない)
    //    ★予約語は本 migration の責務ではない (施策 2 の 000200 が担う)。
    //      000100 = 正規化・構文・衝突 / 000200 = 予約語、と責務を分ける。
    $violations = DB::table('organizations')->select('id', 'slug')->get()
        ->filter(fn (object $row): bool => ! self::normalizesToValidSlug((string) $row->slug));

    // 2. 正規化後に衝突する行を検査する
    $collisions = DB::table('organizations')
        ->selectRaw('lower(slug) as normalized, count(*) as c')
        ->groupBy('normalized')->havingRaw('count(*) > 1')->pluck('normalized');

    if ($violations->isNotEmpty() || $collisions->isNotEmpty()) {
        throw new RuntimeException(
            '識別名の正規化に失敗する組織がある。運用で解消してから再実行すること。'
            .' 構文違反: '.$violations->pluck('id')->implode(', ')
            .' / 衝突: '.$collisions->implode(', '),
        );
    }

    // 3. 検査を通った場合だけ既存値を小文字化する
    DB::statement('UPDATE organizations SET slug = lower(slug) WHERE slug <> lower(slug)');

    // 4. CHECK を付与する。
    //    ★UNIQUE は **既存の organizations_slug_unique をそのまま使う**
    //      (create migration の `$table->string('slug')->unique()` で既に在る)。
    //      再追加すると重複 index になる。
    DB::statement(
        "ALTER TABLE organizations ADD CONSTRAINT organizations_slug_syntax
         CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$' AND length(slug) <= 255)"
    );
}

public function down(): void
{
    // ★CHECK は名前で落とす (Schema Builder に CHECK の抽象は無い)。
    //   既存の organizations_slug_unique は本 migration が作っていないので触らない。
    DB::statement('ALTER TABLE organizations DROP CONSTRAINT IF EXISTS organizations_slug_syntax');
}
```

> **PostgreSQL の `~` と PHP の `\A...\z` の対応**: PostgreSQL の `^`/`$` は既定で
> 文字列全体の先頭末尾に一致し（`n` フラグを付けない限り改行を行境界として扱わない）、
> かつ `slug` に改行が入る余地は CHECK 自体が塞ぐ。この非対称（PHP 側は `\A\z`、
> DB 側は `^$`）を migration の docblock に書く。
>
> **予約語は CHECK に入れない**。設定ファイルで増減するものを DDL に焼くと二重管理になるため、
> 予約語の既存データ検査は施策 2 の migration が担う。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`self` / `?self`）
- [x] `deriveFromName()` は導出不能を `null` で表し、呼び出し側が分岐する
- [x] `promote()` は 1 回の取得で分岐し、理由の非 null 性が型で言える
- [x] 例外は型付き（`InvalidOrganizationSlugException` / `ReservedOrganizationSlugException`）

### テスト計画

- [ ] 新規 `tests/Unit/Support/Organization/OrganizationSlugTest.php` —
      正例（`acme` / `acme-corp` / `a1-b2` / 255 文字）/
      負例（空 / 256 文字 / `-acme` / `acme-` / `ac--me` / `acme_corp` / `日本語` /
      **前後空白 `" acme "`** / **末尾改行 `"acme\n"`**）/ `Acme` が正規化されること
- [ ] 新規 `tests/Unit/Support/Organization/OrganizationSlugDeriveTest.php` —
      日本語名で `null` / 切り詰め後の候補も検査点を通ること（`"ａ"*300` 等）
- [ ] 新規 `tests/Feature/Organization/OrganizationSlugConstraintTest.php` —
      **CHECK が実際に効く**こと。**`DB::table()->insert()` の直組みは使わない**
      （「テストデータは必ず Factory で生成」に抵触し、他の必須列制約が先に発火して
      CHECK を検証できない）。**Factory で正常な組織を作り、
      `DB::table('organizations')->whereKey($organization->getKey())->update(['slug' => 'Acme'])` で
      値オブジェクトを迂回**して CHECK を撃つ（`'ac--me'` / `'-acme'` / 256 文字も同じ形）
- [ ] 同上 — **一意制約違反が `organizations_slug_unique` として識別**されること。
      **別の一意違反（例: `laratrust_team_id`）は利用者向けエラーへ変換せず再送出**すること
      （SQLSTATE `23505` だけで判定しない）
- [ ] 新規 `tests/Architecture/OrganizationSlugWritePathTest.php`（単位 A で同時に入る。施策 2 参照）

### リスク

- 既存 dev/production DB に構文違反・重複がある場合 migration が止まる。
  **意図した fail-closed**。事前確認 SQL を TODO の備考に書き、**人が実行する**
  （エージェントは dev DB を破壊操作しない＝AGENTS.md 禁止事項 3）。

---

## 施策 2: 予約語（理由 3 分類必須）と route 表整合の機械検査

満たす不変条件: **I9 / I10 / I11**

### 変更箇所

- 新設: `config/organization-slug-reserved.php`
- 新設: `app/Enums/Organization/SlugReservationReason.php`（backed enum）
- 新設: `app/Support/Organization/OrganizationSlugReservedWords.php`
- 新設 migration: `2026_08_23_000200_verify_no_reserved_organization_slug.php`（**初版導入時の既存データ検査**）
- 新設: `tests/Architecture/OrganizationSlugReservedWordsInvariantTest.php`
- 新設: `tests/Support/Architecture/OrganizationSlugRouteScanner.php`
- 新設: `tests/Architecture/OrganizationSlugWritePathTest.php`

### 「識別名の位置」の定義（**位置を比較する**）

現行の組織 route は `/organizations/{organization:slug}/...` である。
**識別名と同じ位置（`/organizations/` の直下の第 2 セグメント）に現れる静的セグメント**だけが
ルート衝突の対象である。実測では `organizations/create` の `create` が該当し、
`settings` / `api-keys` / `invitations` などは**第 3 セグメント以降**なので該当しない。

走査器はこの**位置**で判定する（語の一致だけで拾わない）:

```
tests/Support/Architecture/OrganizationSlugRouteScanner.php
  - 走査根: Route::getRoutes()
  - 抽出: uri が 'organizations/' で始まる route の **第 2 セグメント**が
    静的（`{...}` でない）であるものを収集する
  - 母集団が空なら fail (走査根の改名・prefix 変更で空振りしても気付ける)
  - 未解決 (uri が動的に組まれている等) は落とす = fail-closed
```

> `authority_impersonation` / `syntax_conflict` の語は route 表から導けないので、
> **設定ファイルが唯一の正本**であり、機械検査は「登録漏れ」ではなく「分類の妥当性」だけを見る
> （分類が無い語・未知の分類は読み込み時に落ちる）。

### 変更後コード（要点）

```php
enum SlugReservationReason: string
{
    /** ルート衝突: 識別名と同じ位置 (/organizations/ 直下) の静的セグメントと同名になる。 */
    case RouteConflict = 'route_conflict';
    /** 権威の詐称: 運営・管理・支援を騙れる語。 */
    case AuthorityImpersonation = 'authority_impersonation';
    /** 構文衝突: URL・DNS・予約識別子として解釈がぶれる語。 */
    case SyntaxConflict = 'syntax_conflict';
}
```

```php
final class OrganizationSlugReservedWords
{
    /** @param array<string, SlugReservationReason> $words */
    private function __construct(private readonly array $words) {}

    /**
     * 設定を読み込み、**読み込み直後に型付きの値へ変換**する。
     * 分類の無い語・未知の分類は例外で落とす (fail-closed)。
     */
    public static function load(): self { /* ... */ }

    public function reservationFor(OrganizationSlug $slug): ?SlugReservationReason
    {
        return $this->words[$slug->value] ?? null;
    }
}
```

### 予約語を増やすときの運用契約（**初版だけの義務ではない**）

> **予約語一覧を追加・変更する変更は、既存組織の識別名との衝突を検査する
> migration（または同等のデプロイ前検査）を同じ変更に含め、衝突があれば fail-closed で止める。**

- この文を **`config/organization-slug-reserved.php` の冒頭 docblock** と
  **`docs/app-integration-guide.md`** の該当節に置く（正本は config 側 1 か所）。
- 固定 route を足して予約語を増やす変更が、既存組織の URL を黙って壊す経路になるのを防ぐ。
- **機械では強制しない**（config に語を足すだけで検査が走る仕組みは持たない）。
  これは人がレビュー時に適用する運用契約であり、その旨を docblock に明記する
  （AGENTS.md の「保証範囲を誇張しない」に従う）。

**初版導入時の既存データ検査**:

```php
// 2026_08_23_000200_verify_no_reserved_organization_slug.php
/**
 * 初版の予約語と既存の識別名が衝突しないことを検査する (fail-closed)。
 *
 * ★**予約語のスナップショットを migration 内に固定する**。可変の config を読むと、
 *   将来 config に語を足したときに**過去の migration の意味が変わる** (再実行や
 *   新規環境の構築で、当時は通った migration が落ちる)。
 * ★将来の追加は「同じ変更に新しい migration を足す」運用契約 (上記) が担う。
 */
private const array RESERVED_AT_INTRODUCTION = ['admin', 'create', /* … 初版の全語 … */];

public function up(): void
{
    $offenders = DB::table('organizations')
        ->whereIn(DB::raw('lower(slug)'), self::RESERVED_AT_INTRODUCTION)
        ->pluck('slug');

    if ($offenders->isNotEmpty()) {
        throw new RuntimeException('予約語と同じ識別名の組織がある。改名してから再実行すること: '.$offenders->implode(', '));
    }
}
```

### 保存経路 1 本の固定（**単位 A で閉じる**）

```
tests/Architecture/OrganizationSlugWritePathTest.php
  - 走査根: Tests\Support\TrackedPhpSourceFiles (git 追跡下 PHP 全数)
  - organizations.slug へ値を書く形 (fill / forceFill / update / insert / Factory の定義) を
    全数抽出し、**AssignableOrganizationSlug を受ける 1 本**以外が 0 件であることを固定
  - 完全修飾名で突き合わせる (別名つき取り込みで黙らない)
  - 未解決は fail-closed / 母集団が空なら fail
  - 負例: 構文型を直接保存する合成入力を検出できること
```

### PHPStan適合チェック

- [x] 設定配列を `array<string, string>` のまま持ち回らず、`array<string, SlugReservationReason>` へ変換
- [x] `reservationFor()` の戻り値が `?SlugReservationReason` で 1 回の取得で分岐できる
- [x] migration 内で `OrganizationSlug::fromString()` が例外を投げ得ることを扱う
      （既存値が構文違反なら施策 1 の migration で既に止まっている前提。順序を pin する）

### テスト計画

- [ ] 新規 `tests/Unit/Support/Organization/OrganizationSlugReservedWordsTest.php` —
      分類なし・未知分類で読み込みが落ちること
- [ ] 新規 `tests/Unit/Support/Organization/AssignableOrganizationSlugTest.php` —
      予約語の昇格が例外／理由が例外に載る／非予約語が昇格できる
- [ ] 新規 `tests/Architecture/OrganizationSlugReservedWordsInvariantTest.php` —
      **位置で判定**した第 2 セグメントの静的語が全て登録されている（負例つき）
- [ ] 新規 `tests/Architecture/OrganizationSlugWritePathTest.php`（上記。負例つき）
- [ ] 新規 `tests/Feature/Organization/ReservedSlugRejectionTest.php` —
      作成（**利用者入力・組織名からの導出の両方**）と改名で予約語が拒否される

### リスク

- 予約語一覧を厚くしすぎると正当な組織名が取れない。**初版は
  「第 2 セグメントの静的語」＋「権威の詐称の最小集合」**にとどめる（思考原則 2）。

---

## 施策 3: 改名経路（30 日 5 回・旧識別名は解放）

満たす不変条件: **I12 / I13 / I8（改名経路側）**

### 変更箇所

- 新設: `app/Models/OrganizationSlugRename.php` + `database/factories/OrganizationSlugRenameFactory.php`
- 新設 migration: `create_organization_slug_renames_table`
- 新設: `app/Services/Organization/OrganizationSlugRenameLimiter.php`
- 新設: `app/Data/Organization/SlugRenameQuotaDto.php`
- 新設: `app/Http/Controllers/Organizations/OrganizationSlugController.php`
- 新設: `app/Http/Requests/Organizations/UpdateOrganizationSlugRequest.php`
- 変更: `routes/web.php`（`PATCH /organizations/{organization:slug}/slug`）
- 変更: `resources/js/pages/Organizations/Settings.svelte`

### テーブル設計

| 列 | 型 | 備考 |
|---|---|---|
| `id` | bigint | |
| `organization_id` | FK → `organizations` | `cascadeOnDelete`（組織が消えたら履歴も消える） |
| `renamed_by_user_id` | FK → `users` nullable | `nullOnDelete`（利用者削除で履歴を失わない） |
| `from_slug` / `to_slug` | varchar(255) | **一意制約を張らない**（I13: 旧識別名は解放する） |
| `renamed_at` | timestamptz | |

- **複合 index `(organization_id, renamed_at)`** を張る（回数判定のクエリがこの順で走る）。

### 認可と例外変換点

**認可（reviewer Critical: 変更系 PATCH に認可が無い）**:

```php
// OrganizationSlugController::update
Gate::authorize('update', $organization);   // 既存の OrganizationPolicy
```

- **層 2（テナント境界 404 = binder）が層 3（認可 403）より前**である既存順序に乗る。
  binding の 404 だけでは **same-org の一般メンバーによる改名を防げない**。
- `ControllerAuthorizationGateTest` の inventory へ登録する（deny-by-default）。

**例外変換点を 1 本化する（reviewer Critical: domain 例外は素のままだと 500）**:

| 何を | どこで | 何になるか |
|---|---|---|
| 構文違反（`InvalidOrganizationSlugException`） | `UpdateOrganizationSlugRequest` の**カスタムルール** | 422（バリデーションエラー） |
| 予約語（`ReservedOrganizationSlugException`） | 同上（カスタムルールが `OrganizationSlugReservedWords` を使う） | 422 |
| 同一識別名への改名 | 同上（現在値と比較するルール） | 422 |
| 回数上限（`SlugRenameLimitExceededException`） | **Controller** で捕まえ `ValidationException::withMessages()` へ | 422 |
| 一意衝突（`organizations_slug_unique`） | **Controller** で `OrganizationSlugConstraintViolation::isSlugTaken()` を見て変換 | 422 |
| 上記以外の `QueryException` | 変換しない | **再送出**（隠さない） |

Service は domain 例外を投げるだけで HTTP を知らない。変換は
**FormRequest（入力の妥当性）と Controller（競合の結果）の 2 点だけ**で、
それ以外に散らさない。

### 変更後コード

```php
/**
 * 改名の実行 (家系裁定 AG-046)。
 *
 * ★最終権威は**組織行を行ロックした後の再判定**である。事前判定 (画面表示のための残り回数) は
 *   早期拒否にすぎず、ここでの再判定が唯一の権威である。
 * ★30 日は**ローリング窓**である。境界は **`renamed_at > now - 30 日`**(境界を**含まない**)。
 *   包含にすると「最古 + 30 日」ちょうどの時刻でまだ窓内になり、
 *   画面が案内する nextAvailableAt に到達しても改名できない (案内と挙動が食い違う)。
 * ★同じ識別名への改名は **422 で拒否**する (回数を消費させない。no-op を成功にすると
 *   利用者から見て「変えたのに変わっていない」になる)。
 */
public function rename(Organization $organization, AssignableOrganizationSlug $slug, User $actor): void
{
    $now = CarbonImmutable::now();

    DB::transaction(function () use ($organization, $slug, $actor, $now): void {
        // ★binding 済みモデルの主キーで取り直す (DirectFetchInventory へ
        //   BindingBackedReload として登録する。payload 由来の id ではない)
        $locked = Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->slug === $slug->value) {
            throw InvalidOrganizationSlugException::unchanged();
        }

        $used = OrganizationSlugRename::query()
            ->where('organization_id', $locked->getKey())
            ->where('renamed_at', '>', $now->subDays(self::WINDOW_DAYS))   // 境界を含まない
            ->orderBy('renamed_at')
            ->get();

        if ($used->count() >= self::LIMIT) {
            // 次に改名できる時刻 = 窓内で最も古い履歴の renamed_at + 30 日。
            // ★count() >= LIMIT から first() の非 null 性を PHPStan は推論しないので、
            //   Assert で絞ってから使う (nullable を例外へ渡すと契約が弱くなる)。
            $oldest = $used->first();
            Assert::isInstanceOf($oldest, OrganizationSlugRename::class);

            throw new SlugRenameLimitExceededException($oldest->renamed_at->addDays(self::WINDOW_DAYS));
        }

        $from = $locked->slug;
        $locked->forceFill(['slug' => $slug->value])->save();

        // ★tenant/actor キーを mass assignment しない (AGENTS.md 不変条件 1)。
        //   relation で associate し、サーバ導出値だけを明示代入する。
        $rename = new OrganizationSlugRename;
        $rename->organization()->associate($locked);
        $rename->renamedBy()->associate($actor);
        $rename->forceFill(['from_slug' => $from, 'to_slug' => $slug->value, 'renamed_at' => $now]);
        $rename->save();
    });
}
```

> **`subDays()` を使う（`subDaysNoOverflow()` ではない）**。AGENTS.md が `*NoOverflow` を
> 必須にしているのは**月・年・四半期**の加減算であり、日の加減算に overflow は起きない。
> `CarbonOverflowArithmeticGateTest` の検出対象も月/年/四半期である。
> `$now` は 1 回だけ取得して使い回す（複数回 `now()` を呼ぶと境界がずれる）。

**改名後の応答先**（reviewer 指摘）:

```php
// ★旧 URL へ back() すると直後に 404 になる。**新しい識別名を明示して**遷移する。
//   モデルをそのまま渡すと getRouteKeyName() = 'id' により URL に id が入る危険があるため、
//   名前付き引数で slug の**文字列**を渡す。
return redirect()
    ->route('organizations.settings', ['organization' => $slug->value])
    ->with('success', '組織の識別名を変更しました');
```

### 一意制約違反の扱い

```php
/**
 * 一意制約違反を **制約名まで**識別する。SQLSTATE 23505 だけで判定すると、
 * laratrust_team_id 等の別の一意違反まで「識別名が使われている」に化ける。
 * ★識別できない違反は隠さず再送出する。
 */
final class OrganizationSlugConstraintViolation
{
    public const string SLUG_UNIQUE = 'organizations_slug_unique';

    public static function isSlugTaken(QueryException $e): bool { /* SQLSTATE 23505 かつ制約名一致 */ }
}
```

### 波及変更

- **TypeScript型定義**: `Organizations/Settings.svelte` の props に
  `slugRename: { remaining: number; nextAvailableAt: string | null }` を追加
- **API Resource/DTO**: `SlugRenameQuotaDto`（残り回数・次に改名できる時刻）
- **テストファイル**: `tests/js/pages/OrganizationsSettings.test.ts`
- **DirectFetchInventory**: `Organization::query()->whereKey($organization->getKey())->lockForUpdate()`
  を **binding 由来の再取得**として登録（`ModelDirectFetchInvariantTest`）

### DESIGN.md / Atomic Design 準拠

- 改名フォームは既存の `FormField` atom 経由（AGENTS.md 実装規約）。
- 回数上限でも**ボタンを disabled にしない**（禁止事項 8）。押下時にエラーを表示する。
- 色・角丸・タイポは DS token 経由のみ（hex 直書きを増やさない）。

### PHPStan適合チェック

- [x] `SlugRenameQuotaDto` を返す（配列返却なし）
- [x] `firstOrFail()` の戻り値型を明示
- [x] `Assert::isInstanceOf()` で最古の履歴を非 null に絞ってから例外へ渡す
      （`count() >= LIMIT` から `first()` の非 null 性は推論されない）
- [x] 例外は型付き

### テスト計画

- [ ] 新規 `tests/Feature/Organization/OrganizationSlugRenameTest.php` —
      改名成功で**新 slug の URL へ遷移**する（Location に新 slug が入る）/ 旧 URL が 404 /
      **旧識別名を他組織が取れる**（I13）/ **同じ識別名への改名が 422**
- [ ] 新規 `tests/Feature/Organization/OrganizationSlugRenameLimitTest.php` —
      30 日 5 回で 6 回目が拒否 / **境界（ちょうど 30 日前の履歴は窓に含まれない）**/
      **`nextAvailableAt` ちょうどの時刻で実際に改名できる**（案内と挙動が一致する）/
      **事前判定を通っても行ロック後の再判定で落ちる**
- [ ] 新規 `tests/Feature/Organization/OrganizationSlugTakenTest.php` —
      他組織が使用中の識別名で 422 / **別の一意違反は再送出される**
- [ ] 新規 `tests/Feature/Organization/OrganizationSlugRenameAuthorizationTest.php` —
      **same-org の一般メンバーは 403** / **cross-org は 404**（層 2 が層 3 より前）/
      Owner は成功
- [ ] 更新 `tests/Architecture/ControllerAuthorizationGateTest.php` の inventory
- [ ] 新規 `tests/Feature/Organization/OrganizationSlugValidationTest.php` —
      構文違反・予約語・同一識別名が **FormRequest 層で 422**（500 にならない）
- [ ] 新規 `database/factories/OrganizationSlugRenameFactory.php`
- [ ] `docs/architecture.md` / `docs/factories.md` へ新モデルを追記

### リスク

- 改名は他タブの URL を即座に無効化する。押下時に確認ダイアログを出す（disabled にしない）。

---

## 施策 4: 種別フラグ撤去 + 初期組織生成の行ロック冪等判定

満たす不変条件: **I3 / I4 / I5**（I17 / I18 を壊さない）

### 変更箇所

- 変更: `app/Services/Organization/OrganizationProvisioningService.php`
- 新設 migration: `drop_is_personal_from_organizations_table`
- 変更: `app/Models/Organization.php` / `app/Filament/Resources/OrganizationResource.php` /
  `database/factories/OrganizationFactory.php` / `app/Http/Middleware/HandleInertiaRequests.php`
- 新設: `tests/Architecture/OrganizationProvisioningCallSiteTest.php`
- 変更: `app/Http/Requests/Organizations/StoreOrganizationRequest.php`（**任意の識別名入力**）
- 変更: `resources/js/pages/Organizations/Create.svelte`（識別名の任意入力欄）

### 識別名の入力フロー（reviewer Critical: 日本語名で登録不能になる問題）

| 経路 | 組織名 | 識別名の決め方 |
|---|---|---|
| 通常登録 / ソーシャル登録（初期組織） | `"{name} の組織"`（**日本語なので導出は必ず失敗する**） | 導出 → 失敗 → **Service のフォールバック**（下記）。利用者に入力させない（登録フローに項目を足さない） |
| 組織作成画面（`organizations.create`） | 利用者入力 | 利用者が**任意で**識別名を入力できる。省略時は導出 → 失敗時はフォールバック。**予約語・使用済みは 422 で利用者へ返す** |
| 改名（施策 3） | — | 利用者入力必須。予約語・使用済みは 422 |

```php
/**
 * 識別名を確定する。**値オブジェクトは値を捏造しない**ので、導出不能時の代替は
 * ここ (Service) の責務である。
 *
 * ★フォールバックは `org-{12 文字の小文字英数字}` である。予約語と衝突しないことを
 *   昇格で確認し、一意制約違反なら**有限回**やり直す (無限ループにしない)。
 * ★「利用者が入れた識別名」と「導出/フォールバック」の区別は呼び出し側が持つ —
 *   利用者入力が予約語・使用済みなら**黙って代替を作らず 422 で返す**。
 */
private function assignableSlug(?string $requested, string $name): AssignableOrganizationSlug
{
    $reserved = OrganizationSlugReservedWords::load();

    if ($requested !== null) {
        // 利用者が明示した値は矯正も代替もしない (例外はそのまま FormRequest 層の 422 になる)
        return AssignableOrganizationSlug::promote(OrganizationSlug::fromString($requested), $reserved);
    }

    $derived = OrganizationSlug::deriveFromName($name);
    if ($derived !== null) {
        try {
            return AssignableOrganizationSlug::promote($derived, $reserved);
        } catch (ReservedOrganizationSlugException) {
            // 導出結果が予約語なら黙って使わず、フォールバックへ倒す
        }
    }

    return AssignableOrganizationSlug::promote(
        OrganizationSlug::fromString('org-'.Str::lower(Str::random(12))),
        $reserved,
    );
}
```

### 一意衝突時の遷移（**候補の由来を型で持つ**）

reviewer Critical: `requestedSlug === null` のまま再試行すると、**毎回同じ導出値**を作って
3 回とも同じ一意違反になる。候補の**由来**を型で保持し、由来ごとに次の遷移を決める。

```php
enum SlugCandidateOrigin { case Requested; case Derived; case Fallback; }

final readonly class SlugCandidate
{
    public function __construct(
        public AssignableOrganizationSlug $slug,
        public SlugCandidateOrigin $origin,
    ) {}
}
```

| 由来 | 一意衝突したときの遷移 |
|---|---|
| `Requested`（利用者が明示した） | **即 422**（黙って代替を作らない） |
| `Derived`（組織名から導出） | **Fallback へ 1 回だけ遷移**（同じ導出値を繰り返さない） |
| `Fallback`（`org-{12 文字乱数}`） | **新しい乱数候補で最大 3 回**。3 回失敗したら例外（無限ループを作らない） |

### PostgreSQL の失敗トランザクション（reviewer Critical）

PostgreSQL では一意制約違反が起きた時点で**そのトランザクションは中断状態**になり、
`ROLLBACK`（または savepoint への rollback）まで**次のクエリを実行できない**。
同じトランザクション内で候補だけ変えて再試行することは**できない**。

```php
/**
 * 1 試行 = 1 savepoint。失敗したら savepoint まで巻き戻してから次の候補を試す。
 *
 * ★Laravel のネスト transaction は savepoint として実装されるので、
 *   内側の DB::transaction() を 1 試行に対応させる。
 * ★Team / Default Team / role 付与の途中状態も、失敗した試行ごとに確実に巻き戻る
 *   (これを Feature テストで固定する)。
 */
foreach ($this->candidates($requested, $name) as $candidate) {
    try {
        return DB::transaction(fn (): Organization => $this->createWith($creator, $name, $candidate));
    } catch (QueryException $e) {
        if (! OrganizationSlugConstraintViolation::isSlugTaken($e)) {
            throw $e;   // 別の一意違反は隠さず再送出する
        }
        if ($candidate->origin === SlugCandidateOrigin::Requested) {
            throw new OrganizationSlugTakenException($candidate->slug);   // Controller が 422 へ
        }
        // Derived → Fallback、Fallback → 新しい乱数、は candidates() の generator が決める
    }
}

throw new RuntimeException('識別名の候補を使い切った');
```

### 変更後コード（provisioning）

```php
/**
 * 組織 + Laratrust Team + Default Team を原子的に生成し、creator を Owner にする。
 *
 * ★シグネチャは `provision(User $creator, string $name, ?string $requestedSlug = null)` に統一する
 *   (施策 7 の呼び出しサイトもこの形。単位 A で全呼び出し元を同時に切り替える)。
 * ★**単位 A では current_organization_id への書き込みを残す**。ここで消すと、
 *   単位 B より前の現行 current-org 方式で新規登録者の業務画面が動かなくなる。
 *   撤去は単位 B (施策 7) で route 移設と同時に行う。
 */
public function provision(User $creator, string $name, ?string $requestedSlug = null): Organization

/**
 * 登録時の初期組織生成 (冪等)。
 *
 * ★冪等判定は「**所属組織が 0 件かどうか**」で行う (家系裁定 AG-038。種別フラグは撤去した)。
 * ★判定はトランザクション内で**利用者の行を取り直して行ロック**し、ロック後のクエリで数える。
 *   呼び出し側が読み込み済みのリレーションに依存しない。
 */
public function provisionInitialOrganization(User $user): Organization
{
    return DB::transaction(function () use ($user): Organization {
        $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

        /** @var Organization|null $existing */
        $existing = $locked->organizations()->orderBy('organizations.id')->first();

        return $existing ?? $this->provision($locked, "{$locked->name} の組織");
    });
}
```

### 呼び出しサイトの固定（I5）

```
tests/Architecture/OrganizationProvisioningCallSiteTest.php
  - 走査根: Tests\Support\TrackedPhpSourceFiles (git 追跡下 PHP 全数)
  - provisionInitialOrganization() の呼び出しを **完全一致で 2 経路に固定**
    (CreateNewUser / ソーシャル登録の着地)。完全修飾名で突き合わせる
  - 行ロック構造 (lockForUpdate → ロック後に所属を数える) の構文解析固定
  - 母集団が空なら fail / 負例で検出力を裏取り (ロックを外した合成入力を検出できること)
```

### 波及変更

- **TypeScript型定義**: `resources/js/lib/shared-props.ts` の `isPersonal: boolean` を削除。
  `Organizations/Create.svelte` に識別名の任意入力欄（props/型を追加）
- **API Resource/DTO**: なし
- **テストファイル**: `is_personal` を参照する 18 箇所を「所属組織が 1 件」の検査へ
- **DirectFetchInventory**: `User::query()->whereKey($user->getKey())->lockForUpdate()` と
  `Organization::query()->whereKey(...)` を **binding/actor 由来の再取得**として登録
  （`ModelDirectFetchInvariantTest` の分類対象。**静的検査を迂回するためにクエリ形を変えない**）

### 並行性の検証方法（reviewer Critical への回答。**保証範囲を誇張しない**）

`tests/Pest.php` は `RefreshDatabase` をグローバル適用しており、テストは
**未 commit のトランザクション内**で走る。したがって**別接続からの真の並行実行は観測できない**
（別接続はテストデータを一切見られない）。個別の `DatabaseTransactions` は禁止事項 5 で禁止。

そこで検証は次の 3 層に分ける。**「並行でも 1 件」を実測したとは書かない**。

| 層 | 何を固定するか |
|---|---|
| Architecture | `lockForUpdate()` があり、**ロック後**に所属を数えていること（構文解析。負例つき） |
| Unit | 「ロック取得後に他者が組織を作っていた」場合に既存を返す分岐を、seam 経由で直接叩く |
| Feature | 逐次 2 回呼んでも 1 件 / **登録失敗時に利用者と初期組織がともに巻き戻る**原子性 |

> **先例**: 本リポジトリは同じ制約に対して `docs/template-divergence.md` **D7**
> （org 同時 preview 上限の「直列化実証テスト」は subprocess 方式を保留し、逐次境界テストで代替）
> という判断を既に持つ。本件も同じ形を採り、**保証範囲を gate の docblock に明記する**。

### PHPStan適合チェック

- [x] `firstOrFail()` / `first()` の戻り値型と null 分岐
- [x] `provision()` の**最終シグネチャ**で全呼び出しサイトが通る（単位 A 内で同時に直す）
- [x] `SlugCandidate` / `SlugCandidateOrigin` で候補の由来が型で言える（`?string` の再解釈をしない）
- [x] `QueryException` の分岐で「識別できない違反は再送出」が型の上でも読める

### テスト計画

- [ ] 更新 `tests/Feature/Organization/DefaultTeamInvariantTest.php`
- [ ] 新規 `tests/Feature/Organization/InitialOrganizationIdempotencyTest.php`
- [ ] 新規 `tests/Unit/Services/Organization/InitialOrganizationRaceBranchTest.php`（seam 経由）
- [ ] 新規 `tests/Architecture/OrganizationProvisioningCallSiteTest.php`（負例つき）
- [ ] 新規 `tests/Feature/Organization/OrganizationCreateSlugTest.php` —
      日本語名で登録が通る（フォールバック）/ 利用者入力の予約語・使用済みが **422**
- [ ] 新規 `tests/Feature/Organization/OrganizationSlugCandidateFallbackTest.php` —
      **導出値が使用済みなら fallback へ 1 回だけ遷移**する（同じ導出値を繰り返さない）/
      fallback の衝突は新しい乱数で再試行し**最大 3 回**で打ち切る /
      **失敗した試行の Team・Default Team・role 付与が残らない**（savepoint で巻き戻る）/
      **別の一意違反は再送出される**
- [ ] 更新: `is_personal` を参照する既存テスト 18 箇所

### リスク

- `is_personal` は課金の「個人プラン」判定に使われていないことを実測済み
  （`Checkout.svelte` / `Plans.svelte` の `isPersonal` は `plan.code === "personal"` で無関係）。
  実装時にレビューで再確認する。

---

## 施策 5: 業務 route の組織 URL 配下への移設（**route 名は維持**）

満たす不変条件: **I2**

### route 名の方針（reviewer Critical への回答）

**route 名は一切変更しない。変えるのは URI だけである。**

- 理由: route 名は capability 参照・冪等記録・監査台帳・テスト・bug-hunt 目録が鍵にしている。
  最小変更の原則（思考原則 2）に従い、URI の移設だけを行う。
  aigenba も「route 名は不変 (capability 参照・冪等記録・監査台帳が名を鍵にするため)」と記録している。
- 帰結: **施策 10 の「撤去した route 名」検出の対象は `organizations.switch` の 1 本だけ**になる。
  旧パス検出と撤去 route 名検出は**別の台帳**にする。

### 業務 route の母集団定義（reviewer Warning への回答）

「約 57 本」を数え上げではなく**機械的な条件**で定義する:

> **業務 route** = `web` middleware group を宣言し、`auth` を持ち、
> かつ `routes/web.php` の**業務 group**（`require-active-subscription` を含む group と、
> `dashboard` / `notifications.*` / `manage.*` の 3 群）に属する named route。

- 除外は**理由付きの台帳**（`tests/Support/Routing/OrganizationlessWebRouteInventory.php`）に登録する。
  除外の初期値: `home` / `pricing` / `legal.*` / `contact*` / `seo.*` / 認証系 / `settings.*`（個人設定）/
  `passkey.*` / `recent-auth.*` / `session.status` / `invitations.*` / `social.*` / `debug.*` /
  `organizations.create` / `organizations.store` / 分岐入口（施策 8）。
- `OrganizationScopedRouteCoverageTest` が「業務 route は 1 本残らず `{organization}` param を持つ」を
  deny-by-default で固定する（未登録の除外は赤。母集団が空なら fail）。

### 移設表（prefix `/organizations/{organization:slug}` を付ける。**route 名は不変**）

| route 名（不変） | 現行 URI | 移設後 URI | 本数 |
|---|---|---|---|
| `dashboard` | `dashboard` | `organizations/{organization:slug}/dashboard` | 1 |
| `projects.*` | `projects/...` | `organizations/{organization:slug}/projects/...` | 25 |
| `capture.*` | `app/...` | `organizations/{organization:slug}/app/...` | 12 |
| `billing.*` / `billing.tickets.*` | `billing/...` `purchase-tickets/...` | `organizations/{organization:slug}/billing/...` | 11 |
| `onboarding.*` | `onboarding/...` `billing-required` | `organizations/{organization:slug}/onboarding/...` | 3 |
| `notifications.*` | `notifications/...` | `organizations/{organization:slug}/notifications/...` | 4 |
| `manage.users.index` | `manage/users` | `organizations/{organization:slug}/manage/users` | 1 |

### route 生成の全呼び出し元（reviewer Critical: 「TypeScript 変更なし」は誤り）

`{organization:slug}` を足すと、**PHP・Svelte 双方の全 route 生成に organization 引数が要る**。
位置引数で `$project` だけを渡すと **project が organization 引数へずれる**（cross-org の温床）。

**したがって全 route 呼び出しを名前付き引数へ変更する**:

```php
// ✗ 位置引数 (organization へ project がずれる)
route('projects.show', $project)

// ✓ 名前付き引数 + slug の文字列 (getRouteKeyName()=id による id 混入も同時に防ぐ)
route('projects.show', ['organization' => $organization->slug, 'project' => $project->getKey()])
```

棚卸しの対象（**施策 10 の走査根と同じ母集団**）:
PHP の `route()` / `redirect()->route()` / `URL::route()` / Blade / Mailable / 通知クラス、
Svelte の `route()` ヘルパと URL 直書き、テスト。

新設 gate（**言語別に抽出器を分ける**。PHP の FQCN 解決・動的 route 名・
TypeScript の route helper を 1 本の抽出器では扱えない）:

```
tests/Architecture/OrganizationRouteGenerationTest.php        … PHP 側
tests/js/architecture/organization-route-generation.test.ts   … TypeScript / Svelte 側
  - 業務 route を生成する呼び出しが **名前付き引数**で organization を渡していることを固定
  - 位置引数での生成を負例で検出できること
  - PHP 側は完全修飾名で解決する。**動的に組み立てた route 名**は解決できないので
    **未解決台帳**へ登録し、登録の無い未解決は fail-closed で落とす
```

### controller の型

```php
// 現行
public function index(Request $request): Response
{
    $organization = $this->resolveMemberCurrentOrganization($request);
}

// 変更後 — 組織は route binding が渡す (入力検証より前に解決済み)
public function index(Request $request, Organization $organization): Response
```

`ResolvesCurrentOrganization` → `ResolvesRouteOrganization` に改称し、
`resolveCurrentOrganization()` / `resolveMemberCurrentOrganization()` を削除、
`resolveOrganizationProject()` の 1 本だけ残す。

### 波及変更

- **TypeScript型定義**: route 生成の全呼び出し元（上記）。**「なし」ではない**
- **API Resource/DTO**: なし
- **テストファイル**: 移設対象 route を叩く全 Feature / Browser テスト
- `tests/Support/Routing/NestedRouteDefenseInventory.php` — **全エントリに
  `'organization' => ScopedBinder` を追加**（route 名は不変なのでキーは変わらない）
- `.claude/skills/app-bug-hunt/inventory/annotations.toml` — URI 変更に追随

### PHPStan適合チェック

- [x] controller の第 2 引数 `Organization $organization` の型が明示されている
- [x] 未使用の `use` / trait メソッドが残らない

### テスト計画

- [ ] 新規 `tests/Architecture/OrganizationScopedRouteCoverageTest.php`（除外台帳つき・負例つき）
- [ ] 新規 `tests/Architecture/OrganizationRouteGenerationTest.php`（名前付き引数の固定・負例つき）
- [ ] 更新 `tests/Architecture/NestedRouteIdorDefenseTest.php` の inventory
- [ ] 更新 `tests/Architecture/TenantBoundaryOrderingTest.php` /
      `tests/Architecture/ControllerAuthorizationGateTest.php`
- [ ] 更新: 移設対象 route を叩く全 Feature テスト

### リスク

- 移設漏れ → `OrganizationScopedRouteCoverageTest` が落とす。
- 引数ずれ → `OrganizationRouteGenerationTest` が落とす。

---

## 施策 6: 組織文脈の binding 由来化

満たす不変条件: **I2**（I1 を壊さない）

### 変更箇所

- 変更: `app/Http/Middleware/HandleInertiaRequests.php`
- 改称・改修: `EnsureProjectBelongsToRouteOrganization` → `EnsureProjectBelongsToRouteOrganization`
  （alias `project.in-route-org` → `project.in-route-org`）
- 変更: `bootstrap/app.php`（alias 名と priority list のクラス名。**位置は変えない**）
- 新設: `app/Data/Organization/CurrentOrganizationData.php`
- 変更: `resources/js/lib/shared-props.ts` / `AppLayout.svelte` /
  `_helpers/SidebarUserMenu.svelte` / `pages/Capture/Account.svelte`

### 変更後コード

```php
public function handle(Request $request, Closure $next): Response
{
    $project = $request->route('project');

    if ($project instanceof Project) {
        $organization = $request->route('organization');
        // 組織が URL に無いのに {project} がある = 配線ミス。fail-closed (500)。
        // 黙って素通しすると cross-org が開く。
        Assert::isInstanceOf($organization, Organization::class);
        abort_unless($organization->projects()->whereKey($project->getKey())->exists(), 404);
    }

    return $next($request);
}
```

> **priority list の位置は変えない**。`SubstituteBindings` →（API guard）→ 本 guard →
> `HandleInertiaRequests` → … の鎖はクラス名だけ差し替える。
> `TenantBoundaryOrderingTest` が解決後の middleware 列で固定するので、順序契約は壊れない。

```php
/**
 * 画面へ渡す組織文脈。**URL の binding からのみ導出する** (家系裁定 AG-037)。
 * 組織 route 以外では必ず null になる (「所属している組織のどれか」を裏口から選ばない)。
 *
 * ★配列化は Inertia へ渡す最終の 1 か所 (toArray) だけで行う。
 *
 * @return array{id: int, name: string, slug: string, role: string|null,
 *               canManageMembers: bool, canManageApiKeys: bool}
 */
```

### 波及変更

- **TypeScript型定義**: `CurrentOrganization` から `isPersonal` を削除。
  `AppLayout.svelte` の**組織切替フォーム**（L326 / L427 付近）を削除し、
  `organizations` prop は**分岐画面へのリンク一覧**として使う
- **API Resource/DTO**: `CurrentOrganizationData`（新設）
- **テストファイル**: `OrganizationNavSharedPropsTest`(27) / `AppLayout.test.ts`(17) /
  `CaptureAccount.test.ts` / `CaptureAccountScreenTest`

### DTO と TypeScript の契約テスト（reviewer Warning）

```
tests/Feature/Shared/CurrentOrganizationSharedPropShapeTest.php
  - CurrentOrganizationData::toArray() の **キー集合だけでなく、各値の型**
    (nullable / 数値 / 真偽) が resources/js/lib/shared-props.ts の
    CurrentOrganization 型と一致することを固定する
    (キーだけ比べると `role: string|null` が `role: string` に化けても緑になる)
  - TS 側は tests/js で型の網羅性 (exhaustive) を検査する
  - PHPStan docblock の array shape を SoT にし、2 か所に値を写さない
```

### DESIGN.md / Atomic Design 準拠

- 組織選択カード（施策 8）は **molecule**（既存 atom の組合せ）。層の逆流はしない。
- 色・角丸・タイポは DS token 経由のみ。アイコンは `@lucide/svelte` のみ。

### PHPStan適合チェック

- [x] `CurrentOrganizationData` は `readonly` DTO、配列化は `toArray()` 1 か所
- [x] `$request->route('organization')` は `mixed` なので `instanceof` で絞る
- [x] `Assert::isInstanceOf()` で fail-closed

### テスト計画

- [ ] 更新 `tests/Feature/Organizations/OrganizationNavSharedPropsTest.php` —
      **組織 route 以外では `currentOrganization` が必ず null**
- [ ] 新規 `tests/Feature/Security/RouteOrganizationProjectGuardTest.php` —
      cross-org の `{project}` が **FormRequest の DB ルールより前に 404**（422 にならない）
- [ ] 新規 `tests/Feature/Shared/CurrentOrganizationSharedPropShapeTest.php`
- [ ] 更新 `tests/Architecture/ProjectRouteCurrentOrgGuardTest.php` →
      middleware 名の変更 + 「web の `{project}` route は必ず `{organization}` も持つ」を追加
- [ ] 新規 `tests/Feature/Routing/GeneratedUrlContainsOrganizationSlugTest.php` —
      主要画面（dashboard / projects.index / projects.show / capture.home / billing.index）の
      **生成 URL に slug が含まれる**こと（「organization 引数を渡し忘れた」を旧 URL 検査では
      拾えないため、生成側で固定する）

### リスク

- 共有プロパティが null になる面（`/settings` 等）でナビが崩れる →
  既存の「org なし時 null = 非表示」の分岐がそのまま効く。

---

## 施策 7: 保持列・切替 route・自己修復の撤去

満たす不変条件: **I2**

### 変更箇所

- 削除: `OrganizationSwitchController.php` / `CurrentOrganizationResolver.php` /
  対応する 2 テストファイル
- 変更: `User`（`currentOrganization()` relation 削除）/ `routes/web.php`（`organizations.switch` 削除）/
  `AppServiceProvider` / `NotificationCenterService` / `NotificationController` /
  `OrganizationController::store` / `OrganizationMembershipService`(4 箇所) /
  `MassAssignmentProtectedKeys` / `UserResource`(Filament) / `RequireActiveSubscription`
- 新設 migration: `drop_current_organization_id_from_users_table`

### 各所の置き換え方

| 箇所 | 変更後 |
|---|---|
| `AppServiceProvider::configureRenderRateLimiter` | `$request->route('organization')` を **`Assert::isInstanceOf()` で強制**し、主キーをキーに使う。**`'none'` へ倒さない**（配線不良を黙って許すと、slug 改名時にキーの一貫性も失う）。併せて「render-trigger 対象 route は必ず organization binding を持つ」を Architecture 検査で固定 |
| `NotificationCenterService::notifyAccountDeletionRequested` | **要 auth 側裁定**（下記「本設計の確定前提」）。裁定が出るまで着手しない |
| `NotificationController::belongsToCurrentOrg` | URL 上の組織と一致するか（通知一覧が組織配下になる） |
| `NotificationController::manualStillExists` | URL 上の `$organization` から辿る |
| `OrganizationController::store` | current への書き込みを削除。`redirect()->route('organizations.settings', ['organization' => $organization->slug])` |
| `OrganizationMembershipService`（招待受諾） | current 書き込みを削除。受諾後の遷移先を**招待先組織の URL**にする |
| `OrganizationMembershipService::removeMember` | current の null 化を削除（列が無い） |
| `OrganizationMembershipService`（退会ブロッカー） | 「現在の組織か」の項目を根拠列とともに撤去（**判定に使っていないことを実測してから**消す） |
| `RequireActiveSubscription` | `$request->route('organization')`。**組織 binding が無ければ fail-closed（500）**。「課金ゲート配下の全 route は組織引数を持つ」を Architecture 検査で固定 |
| `UserResource`（Filament） | **`currentOrganization.name` エントリを削除するだけ**。所属組織一覧の表示は**足さない**（必要最小限を超える新機能・N+1 になる。必要なら別 feature） |

### 退会予約のアプリ内通知 — **本設計の確定前提（要 auth 側裁定）**

`AppNotification::organizationId()` は non-nullable なので、保持列を消すと org 文脈の出所が要る。
退会予約の POST（`settings.account.deletion-request.store`）は**個人設定の面**であり、
AG-037 の下では **URL に組織が無い**。つまり**この通知の入力そのものが消える**。

**設計側では選ばない。** 「所属組織のどれか 1 つを選ぶ」は AG-037 の裏口であり、
「作らない」も「全所属へ配る」も**どちらも auth / account-deletion feature の仕様変更**である。
乖離台帳へ書けば裁定済みになるわけではない（reviewer 指摘）。

したがって本件は **本設計の確定前提（precondition）** として切り出す:

> **施策 7 は、この 1 点について auth / account-deletion 側のオーナー裁定を得てから着手する。**
> TODO 登録時に前提条件として明記し、裁定が出るまで単位 B の該当部分を実装しない。

判断材料として候補と影響だけを記す（**設計側は選ばない**）:

| 候補 | 利用者から見た変化 | 副作用 |
|---|---|---|
| (a) アプリ内通知を作らない（メールのみ） | 多くの利用者でアプリ内の気づきが消える | 通知量は減る。既読・保存の契約は不変 |
| (b) 所属する**全組織**へ配る | 通知が 1 件 → N 件になる | 未読件数の増加 / 同一事象の重複表示 / 既読状態の分裂 / 保存量の増幅。**fan-out 上限・既読の単位・退会後の参照可否**を契約に足す必要がある |
| (c) 通知の org 文脈を nullable にする | 変化なし | `AppNotification` の契約変更。通知一覧の org スコープ表示に影響（**別 feature の中核契約に触る**） |

- 裁定が (b) なら、**fan-out 上限・バルク生成方式・部分失敗時のトランザクション境界**を
  同じ変更で定義する（reviewer Warning）。
- 裁定が出るまでの間、`current_organization_id` の撤去 migration も**着手できない**
  （この 1 箇所が列の最後の利用者になるため）。**これは本設計が受け入れる依存**である。

### `current_organization_id` 撤去の機械検査（reviewer Critical: 一般語の全面禁止をやめる）

```
tests/Architecture/CurrentOrganizationRemovalTest.php
  - **別々の構文として**検出する (一般語 currentOrganization の全面禁止はしない。
    施策 6 で残す共有 prop 'currentOrganization' と CurrentOrganizationData を
    自ら違反にしてしまうため):
      1. 列名リテラル `current_organization_id` (撤去 migration 以外に 0 件)
      2. `User::currentOrganization()` relation の宣言と `->currentOrganization` の
         **User に対する**プロパティアクセス (完全修飾名で解決)
      3. FQCN `App\Services\Organization\CurrentOrganizationResolver`
      4. route 名 `organizations.switch`
  - 走査根: TrackedPhpSourceFiles + resources/js + database/
  - 母集団が空なら fail / 負例で 4 形それぞれの検出力を裏取り
```

### migration の順序と運用（reviewer Warning）

```php
public function down(): void { /* 逆順: 列を戻して FK を張り直す */ }

public function up(): void
{
    Schema::table('users', function (Blueprint $table): void {
        // FK → index → 列 の順に落とす (dropConstrainedForeignId が FK と index を面倒見る)
        $table->dropConstrainedForeignId('current_organization_id');
    });
}
```

- **ローリングデプロイ非互換**である（旧アプリは列を読むため、列を落とすと 500 になる）。
  切替は**メンテナンス前提**とし、順序を運用手順へ書く:
  1. メンテナンスモードに入れる
  2. 新コードをデプロイ
  3. `php artisan migrate`
  4. **`php artisan route:cache` を再生成**（AGENTS.md の運用要件。
     vendor route への middleware 後付けは cache 生成時に焼き込まれる）
  5. メンテナンスモードを解除
- 本リポジトリにデプロイ定義は無いので、この手順は `docs/` と TODO の備考に**人手の手順**として書く
  （存在しない基盤のための preflight を先回りして作らない＝思考原則 2）。

### 削除するテストについて

`CurrentOrganizationResolverTest` / `OrganizationSwitchTest` は**検査対象そのものが撤去される**ため削除する。
app-design スキルの禁止事項 3（既存テストの削除・上書き）に当たらないことを示すため、
**同じ変更で不変条件の移送先を明示する**:

| 削除するテスト | 移送先 |
|---|---|
| `CurrentOrganizationResolverTest` | `CurrentOrganizationRemovalTest`（列・relation・FQCN が 0 件） + `OrganizationNavSharedPropsTest`（組織 route 以外では null） |
| `OrganizationSwitchTest` | `CurrentOrganizationRemovalTest`（route 名 0 件） + `OrganizationEntryTest`（施策 8） |

> なお AGENTS.md の禁止事項 3 は「dev DB への破壊操作」であり、テスト削除の話ではない
> （2 つの一覧で番号が違う。番号ではなく項目名で指す）。

### PHPStan適合チェック

- [x] `User::currentOrganization()` の削除で PHPStan が全参照を検出する（widen しない）
- [x] `RequireActiveSubscription` / rate limiter の `Assert::isInstanceOf()` で fail-closed
- [x] `MassAssignmentProtectedKeys` の変更が `MassAssignmentSafetyTest` と整合

### テスト計画

- [ ] 新規 `tests/Architecture/CurrentOrganizationRemovalTest.php`（4 形の負例つき）
- [ ] 新規 `tests/Architecture/BillingGateRouteOrganizationParamTest.php`
- [ ] 新規 `tests/Architecture/RenderTriggerRouteOrganizationParamTest.php`
- [ ] 新規 `tests/Feature/Billing/BillingGateWithoutOrganizationBindingTest.php`（fail-closed 500）
- [ ] （**裁定後**）退会予約通知の Feature テスト — 採択された候補の挙動と、
      **メールは従来どおり届く**ことを固定する。裁定前は着手しない
- [ ] 更新: `current_organization_id` を仕込む Feature テスト全数

### リスク

- ローリングデプロイ不可（メンテナンス前提）。運用手順を文書化する。

---

## 施策 8: 組織文脈を持たない入口の分岐 route

満たす不変条件: **I2**

### 変更箇所

- 新設: `app/Http/Controllers/Organizations/OrganizationEntryController.php`
- 新設: `app/Data/Organization/OrganizationChoiceData.php`
- 新設: `resources/js/pages/Organizations/Choose.svelte`
- 変更: `routes/web.php`（`GET /app` = PWA の `start_url` / `GET /go` = 汎用入口）

### 設計

```php
/**
 * 組織文脈を持たない入口からの分岐 (家系裁定 AG-037 と矛盾しない形)。
 *
 * ★**状態を一切保存しない**。所属が 1 組織ならその組織へ転送、複数なら選ぶ画面、
 *   0 件なら組織作成へ。保持列も切替 endpoint も作らない。
 * ★複数所属で**自動選択しない** (自動選択は保持列の再発明であり、裁定が禁じる裏口そのもの)。
 * ★遷移先は入口ごとの**固定表**から選ぶ。query string で受け取らない (open redirect を作らない)。
 * ★`/app` と `/go` は **parameter を持たない固定 route** なので、backed enum を
 *   Controller 引数へ注入することはできない (Laravel の enum binding は route parameter に働く)。
 *   **現在の route 名を固定表へ写して EntryTarget を得る**。
 */
private const array TARGET_BY_ROUTE = [
    'capture.entry' => EntryTarget::Capture,   // GET /app
    'app.entry' => EntryTarget::Dashboard,     // GET /go
];

public function __invoke(Request $request): Response|RedirectResponse
{
    $user = $request->user();
    Assert::isInstanceOf($user, User::class);

    $routeName = $request->route()?->getName();
    // 固定表に無い route から呼ばれたら配線ミス。fail-closed (500)。
    Assert::keyExists(self::TARGET_BY_ROUTE, (string) $routeName);
    $target = self::TARGET_BY_ROUTE[$routeName];

    // ★membership を **1 回だけ**取得して使い回す。count() と sole() を別クエリにすると、
    //   その間に membership が変わったとき 0 件 / 複数件の例外になる。
    $organizations = $user->organizations()->orderBy('organizations.name')->get();

    if ($organizations->isEmpty()) {
        return redirect()->route('organizations.create');
    }

    if ($organizations->count() === 1) {
        // ★sole() は同じ Collection に対して呼ぶ (再クエリしない)。
        //   URL には **slug の文字列**を名前付きで渡す
        //   (モデルを渡すと getRouteKeyName()=id により id が入る)。
        return redirect()->route($target->routeName(), ['organization' => $organizations->sole()->slug]);
    }

    return Inertia::render('Organizations/Choose', [
        'target' => $target->value,
        'organizations' => OrganizationChoiceData::collect($organizations),
    ]);
}
```

- **`/app`（PWA の `start_url`）はこの分岐 route にする**。`manifest.webmanifest` の
  `start_url` は `/app` のまま変えない。
- **PWA の scope**: 現行 `manifest.webmanifest` に **`scope` キーは無い**（実測）。
  W3C 仕様の既定 scope は `start_url` の親パス = **`/`** なので、
  `/organizations/{slug}/app/...` は scope 内に収まる。
  service worker も `/capture-sw.js` から登録しており scope は `/`。
  **この 2 つの前提を検査で pin する**（後述）。

### 波及変更

- **TypeScript型定義**: `Organizations/Choose.svelte` の props 型（`OrganizationChoiceData` に対応）
- **API Resource/DTO**: `OrganizationChoiceData`（Eloquent collection を直接渡さない）
- **テストファイル**: 新規 Feature / 新規 `tests/js/pages/OrganizationsChoose.test.ts`

### PHPStan適合チェック

- [x] `Response|RedirectResponse` の union を明示
- [x] `sole()` は取得済み Collection に対して呼ぶ（`first()` の null 分岐を作らない・再クエリしない）
- [x] `Assert::keyExists()` で固定表に無い route を fail-closed にする（`EntryTarget` は非 null）
- [x] `Assert::isInstanceOf()` で `?User` を絞る
- [x] Inertia へ渡すのは DTO（Eloquent collection の素渡しをしない）

### テスト計画

- [ ] 新規 `tests/Feature/Organization/OrganizationEntryTest.php` —
      **0 / 1 / 複数所属ごとに、正確な route 名・slug・Location を検証**（「200 か 302」では通さない）/
      未ログインは login へ / **遷移先が query string で操作できない**（open redirect の負例）
- [ ] 新規 `tests/Feature/Capture/CapturePwaScopeTest.php` —
      `manifest.webmanifest` に `scope` キーが無い（＝既定 scope が `/`）ことと、
      `start_url` が `/app` であることを pin し、
      **組織付き capture URL が scope 内**であることを固定（前提が変わったら赤になる）
- [ ] 新規 `tests/js/pages/OrganizationsChoose.test.ts`

### リスク

- 複数組織の利用者は PWA 起動のたびに選択画面を通る。**仕様**。
  組織付き URL をブックマークすれば 1 タップになることを画面で案内する。

---

## 施策 9: 機械経路の組織識別子契約（2 層の全数分類）

満たす不変条件: **I14**

### 変更箇所

- 新設: `tests/Support/Security/OrganizationReferenceProvenance.php`
  （**production コードで使わないので `app/Enums` ではなく `tests/Support` に置く**。
  検査のための語彙であり、アプリの振る舞いに現れない）
- 新設: `tests/Support/Security/MachinePlaneEntryPoints.php`（第 1 層の抽出器）
- 新設: `tests/Support/Security/OrganizationResolutionPoint.php`（DTO）
- 新設: `tests/Support/Security/MachinePlaneOrganizationReferenceInventory.php`（台帳）
- 新設: `tests/Architecture/MachinePlaneOrganizationReferenceTest.php`
- 新設: `tests/Unit/Architecture/MachinePlaneEntryPointsTest.php`（走査器の自己検査）

### 型の設計（reviewer Critical 2 件への回答）

**入口の分類と、解決点の分類は別の型にする**（`NotOrganizationScoped` を provenance に混ぜない）:

```php
/** 入口の分類。0 件と「解決点がある」を型で区別する。 */
abstract readonly class MachinePlaneEntryClassification {}

/** 解決点が **0 件であることを検査した**入口。理由の記載が必須。 */
final readonly class NotOrganizationScoped extends MachinePlaneEntryClassification
{
    public function __construct(public string $reason) {}   // 30 文字以上を gate が強制
}

/** 解決点を持つ入口。解決点ごとに provenance を持つ。 */
final readonly class OrganizationScoped extends MachinePlaneEntryClassification
{
    /** @param non-empty-list<OrganizationResolutionPoint> $resolutions */
    public function __construct(public array $resolutions) {}
}
```

```php
/**
 * 1 つの解決点。
 *
 * ★**入口の識別子は持たない**。台帳 (inventory) の**キーが入口の唯一の SoT** である
 *   (DTO 側にも持たせると、外側キーと内側の値が食い違う余地ができる)。
 * ★resolutionId は**入口内で安定した識別子** (メソッド名 + 引数名)。
 */
final readonly class OrganizationResolutionPoint
{
    public function __construct(
        public string $resolutionId,
        public OrganizationReferenceProvenance $provenance,
        /** RelationScoped のときだけ非 null。**同じ入口内の**別の解決点を指す。 */
        public ?string $parentResolutionId,
    ) {}
}
```

### 親鎖の検証（reviewer Critical 2 件）

`RelationScoped` の親は **`PrimaryKeyBinding` / `ActorDerived` / 別の `RelationScoped`** を許す
（多段の relation を表現できないと実装に合わない）。そのうえで gate が次の 5 つを検証する:

| # | 検証 | 破れたときに起きること |
|---|---|---|
| 1 | `resolutionId` が**入口内で一意** | 重複 ID で親の指す先が曖昧になる |
| 2 | `parentResolutionId` が**同じ入口内に実在**する | 存在しない親を指して黙って通る |
| 3 | **自己参照禁止**（`parent === self`） | 自分を根拠に自分を正当化できる |
| 4 | **循環禁止**（`A → B → A`）— 訪問済み集合で検出 | 「親が存在する」だけの検査を循環がすり抜ける |
| 5 | 親鎖が**最終的に `PrimaryKeyBinding` か `ActorDerived` へ到達**する | 信頼の起点が無い relation 鎖が通る |

`RelationScoped` 以外に `parentResolutionId` が付いていたら**赤**にする（余剰登録）。
どれか 1 つでも解決できなければ **fail-closed** で gate を失敗させる。

```php
enum OrganizationReferenceProvenance: string
{
    /** route binding の内部主キーだけ (Filament の {record} を含む)。
     *  request body / query string の tenant キー受け取りは**この分類では許さない**
     *  (AGENTS.md 不変条件 1: tenant キー不信)。 */
    case PrimaryKeyBinding = 'primary_key_binding';
    /** 認証済み credential (API キー / OAuth token / MCP consent) の帰属から確定する
     *  request attribute。利用者入力を経由しない。 */
    case ActorDerived = 'actor_derived';
    /** **信頼済みの親**から tenant-scoped relation だけを辿って確定する。
     *  親は PrimaryKeyBinding / ActorDerived / **別の RelationScoped** のいずれでもよいが、
     *  親鎖が最終的に PrimaryKeyBinding か ActorDerived へ到達することを gate が検証する。
     *  自己参照・循環・親不在は fail-closed で落ちる (再帰的 provenance)。 */
    case RelationScoped = 'relation_scoped';
}
```

### 第 1 層 — 入口の全数抽出

| 面 | 抽出方法 | fail-closed |
|---|---|---|
| api / ai | `Route::getRoutes()` から `api/` `ai` 由来の全 action | 母集団が空 |
| console | **application-defined の command だけ**（`app/Console/Commands/` の具象クラス + `routes/console.php` の無名 command）。**vendor command は対象外**（I14 の責務はアプリが書く経路であり、vendor の内部解決は保証範囲外＝docblock に明記） | 走査根が不在 / 母集団が空 |
| Filament | 対象 panel に属する **application-defined の構成要素全件**（Resource / Page / RelationManager / Widget / Action …）。組織解決の有無で絞らない | **未知の構成種別で fail** |
| MCP tool | `App\Enums\Mcp\ToolName` の全ケース + 実装クラスの突合 | enum と実装の件数不一致 |

### 第 2 層 — 解決点の全数抽出（**字面検出に頼らない**）

reviewer Critical: `where('slug', $input)` の字面検出だけでは対象モデル・入力由来・
別名つき取り込みを解決できず、走査器共通規約 (a)(b) を満たさない。

- **完全修飾名で突き合わせる**（`use` / group use / 別名つき取り込みを解いた FQCN）。
  `Organization` を対象とする query であることを解決できない形は
  **`UnresolvedReference` として gate 失敗に流す**（無言で候補から外さない）。
- 対象は「`Organization` モデル、または組織に帰属する資源を確定する呼び出し」。
  抽出条件（builder の起点クラス・binding・request attribute の読み取り）は
  抽出器の docblock に書き、**保証範囲の外にする構文を明記**する。
- 明記した構文については**検出力を主張しない**。
  そのうえで、明記した構文で保護対象の操作が書ける場合は
  **利用側 gate の主張をその構文を除く形へ明示的に狭める**（規約 (b)）。

### 保証しないもの（docblock に書く。本書へ写さない）

母集団の外（実行時に組み立てた文字列で解決する形・vendor 内部の解決・リポジトリ外の手順）には
**無言で効かない**。また **`PrimaryKeyBinding` は「操作してよい組織か」を保証しない** —
認可は `Gate::authorize` と `ControllerAuthorizationGateTest` の担当である。

### PHPStan適合チェック

- [x] 抽出器は `list<MachinePlaneEntryPoint>` を返す（`array` の素返しをしない）
- [x] 未解決は `null` ではなく `UnresolvedReference` 型で返す
- [x] 台帳は `array<string, MachinePlaneEntryClassification>`。
      **キーが入口の唯一の SoT**（解決点 DTO は入口 ID を持たない）

### テスト計画

- [ ] 新規 `tests/Architecture/MachinePlaneOrganizationReferenceTest.php` —
      全母集団が完全一致で分類 / 未登録・余剰・重複で赤 / 走査根が空で赤 /
      `NotOrganizationScoped` の理由が 30 文字未満で赤
- [ ] 新規 `tests/Unit/Architecture/MachinePlaneEntryPointsTest.php`（**負例で両方向**）—
      識別名で引く形 / 表示名で引く形 / 任意文字列で引く形を検出できる。
      許可 3 種別を誤検出しない。**別名つき取り込み（`use Organization as Org`）で黙らない**
- [ ] 同上 — **親鎖の 5 検証**それぞれの負例:
      `resolutionId` の重複 / 実在しない親 / 自己参照 / 循環（`A → B → A`）/
      根に到達しない鎖（`RelationScoped` だけの鎖）。
      **多段の正例**（`ActorDerived ← RelationScoped ← RelationScoped`）が緑になること
- [ ] 更新 `tests/Architecture/OrganizationRouteParamWebOnlyInvariantTest.php` —
      「`getRouteKeyName()` は id」の pin は据え置き（Filament の `{record}` が依存）。
      **field 無指定の `{organization}` binding が 0 件**であることを追加

### リスク

- Filament の構成種別は vendor 更新で増える。**未知種別で fail-closed** は意図した動作。
  対処手順を gate の docblock に書く。

---

## 施策 10: 旧 URL の走査根ベース残存検査

### 2 つの台帳を分ける（reviewer Critical）

route 名は施策 5 で**維持**するので、検出対象は次の 2 つに分かれる。**同じ台帳にしない**。

| 台帳 | 対象 | 内容 |
|---|---|---|
| **旧パス台帳** | URL 文字列 | 組織 prefix を持たない旧パス（`/projects/` `/billing` `/dashboard` `/notifications` `/manage/users` `/onboarding/` `/purchase-tickets` `/billing-required`、および `/app` のうち**分岐入口以外**） |
| **撤去 route 名台帳** | route 名 | `organizations.switch` の **1 本だけ**（他の route 名は維持される） |

### 母集団と 3 分類（排他）

母集団は **git 追跡下ファイル全数**。次の 3 つへ排他的に分類し、
**どれにも分類していない置き場所・形式が現れたら赤**にする。

| 分類 | 対象 |
|---|---|
| **走査する** | PHP 全層（`route()` 呼び出しと URL 直書き）/ `resources/js/` / `resources/views/` / `tests/`（Feature / Browser / `tests/js/`）/ `docs/` / `doc/` / ルート直下の `README*` / `public/*.webmanifest` / `public/*.js` / `.claude/skills/app-bug-hunt/inventory/` / 生成テンプレート |
| **走査しない（理由付き）** | バイナリ / `public/build`（生成物）/ `vendor` / `node_modules` / `devnotes`（設計の記録であり実行されない。**本設計ディレクトリの旧 URL 記述は履歴として残す**） |
| **未分類** | **1 件でも現れたら赤** |

### URL の抽出と判定（reviewer Critical 2 件）

**(1) 抽出はファイル種別ごとに行う。単一の区切り集合では足りない。**
走査対象には Markdown 文書が入っており、`/dashboard)` `/projects,` `/billing。` のような
**閉じ記号・句読点・空白**で終わる記述を、6 文字の区切り集合では拾えない。

| ファイル種別 | 抽出方法 |
|---|---|
| PHP / TypeScript / Svelte | **文字列リテラル**を構文で抽出し、その中身を URL として扱う（`route()` の第 1 引数は route 名なので別台帳） |
| Blade / HTML | 属性値（`href` / `action` / `src`）と文字列リテラル |
| Markdown（`docs/` `doc/` `README*`） | **Markdown リンクの宛先** + **プレーン URL**。終端は「空白・改行・`)` `]` `>` `"` `'` `` ` `` `,` `;` `。` `、` `）`」のいずれか |
| JSON / webmanifest | 値の文字列 |

区切り集合は**種別ごとに宣言**し、docblock に書く（走査器共通規約 (e)）。
**種別が増えたら未分類として赤**になる（施策 10 の 3 分類と同じ形）。

**(2) 旧パスの判定は「正規化済み path の root 一致」で行う。**
`/projects/` の前方一致だけでは、root の **`/projects`（末尾スラッシュなし）を拾えない**。

> query（`?`）と hash（`#`）を落として正規化した path が、
> **root と完全一致するか、`root/` で始まる**ときに旧パスと判定する。

`/app` は「path が `/app` と完全一致 or `/app/` で始まる」かつ
「`/organizations/{slug}/app…` ではない」ときだけ旧扱いにする。

**(3) 正例群と負例群を分ける。**
「検出すべき旧 URL の変形」と「誤検出してはいけない新 URL」は別の群である
（`/organizations/acme/app` は接尾辞つきの変形ではなく**許可すべき新 URL** であり、
走査器共通規約 (e) の 3 形とは別の話）。

| 群 | 例 |
|---|---|
| **検出すべき（旧 URL）** | `/projects` / `/projects/` / `/projects/12` / `/dashboard)` / `/billing。` / `"/app"` / `/onboarding/checkout` |
| **誤検出してはいけない（新 URL・無関係）** | `/organizations/acme/projects/12` / `/organizations/acme/app` / `/myapp` / `/app-old` / `/projectsomething` |
| **規約 (e) の 3 形（語彙一致の負例）** | 接頭辞つき `/myapp` / 打ち消しつき `/app-old` / 接尾辞つき `/appx` |

### 文書の例外化（reviewer Warning）

旧 URL を含む文書を例外にするときは、**ファイル数だけを pin しない**。
**ファイルごとの一致件数と対象パターンを完全一致で pin** する（増減のどちらでも赤）。

### 保証外（誇張しない）

- 利用者のブックマーク・外部サービスに登録済みの URL
- デプロイ時点で queue に積まれている / 送信済みのメール本文
- ブラウザの履歴・bfcache・開いたままの旧画面（次の遷移で 404 になる）

### PHPStan適合チェック

- [x] 分類台帳は `array<string, LegacyUrlScanClass>`（enum）
- [x] 未分類は例外で落とす（`null` を返して黙らない）

### テスト計画

- [ ] 新規 `tests/Architecture/LegacyOrganizationlessUrlAbsenceTest.php` —
      旧パス 0 件 / 撤去 route 名 0 件 / 未分類 0 件 / 母集団が空なら fail
- [ ] 負例: 3 形（接頭辞・打ち消し・接尾辞）と新 URL の誤検出なし

---

## 施策 11: 乖離台帳の更新

### 共有ファイルの判定（`docs/template-fingerprints.json` の `entries` キー）

本設計が触る 60 ファイル超のうち、**共有ファイルは 2 本だけ**である。

| パス | 共有 | 採用時債務 | 変更するか | 選ぶ道 |
|---|---|---|---|---|
| `app/Http/Middleware/HandleInertiaRequests.php` | ✅ | ✅（`adoption-debt.tsv` L30） | **する**（施策 6） | **(3) 意図的逸脱として登録を書き、債務から削る** |
| `config/laratrust.php` | ✅ | ✅（同 L47） | **しない**（AG-040 は充足済み） | 債務のまま |

> `HandleInertiaRequests.php` は (1) 採用時の姿へ戻す（変更するので不可）/
> (2) テンプレートへ同期して債務から削る（aicue 固有 prop が多く同期できない）が採れないため **(3)**。

### 変更内容

1. **D4 の書き換え**
   - 対象パス: `EnsureProjectBelongsToRouteOrganization.php` へ
   - 対比表: 「controller の inline guard のみ」vs「`project.in-route-org` middleware + inline guard の二重防御」
   - **再判定の条件**: 「組織の解決が web / API とも routing 層の 1 本に揃ったとき」へ更新
     （web は URL binding、API は API キー由来のままで 2 本立てなので **D4 は存続する**）
2. **新規登録 D40**（`HandleInertiaRequests.php` の組織文脈の共有プロパティ）
   - 揃え続ける不変条件: **組織 route 以外では `currentOrganization` が必ず null**
     （`OrganizationNavSharedPropsTest` が固定）
3. **D41 は作らない**（reviewer Critical）。
   退会予約の通知は「所属する全組織へ配る」ことで**後退させない**設計に変えたため、
   乖離登録の対象にならない。
4. **`LedgerPins.php` の更新**
   - `DIVERGENCE_ENTRY_COUNT`: 36 → **37**（D40 の 1 件追加）
   - `ADOPTION_DEBT_COUNT`: 171 → **170**（`HandleInertiaRequests.php` の 1 行削除）
   - `adoption-debt.tsv` から該当行を削除（**昇順・末尾改行・タブ 2 列**の書式を保つ）
   - **数値は実装時に実ファイルの実数から再確認する**（本書の値は設計時点の見込み）

### テスト計画

- [ ] 既存の乖離台帳 gate 群が緑
- [ ] 登録の宣言行 / 見出しの実数 / `LedgerPins` の 3 点一致
- [ ] `adoption-debt.tsv` のヘッダ（`template_ledger_commit`）が指紋台帳の
      `generated_at_commit` と一致したまま

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | AG-037 は **2 方式の併存を認めない裁定**であり、中間状態を main へ出せない（単位 B）。識別名の型契約も中間状態を残せない（単位 A）。約 60 ファイル・57 route・テスト 40 ファイル超に触れる |
| 競合リスク | **極大**。`routes/web.php` / `bootstrap/app.php` / `HandleInertiaRequests` / `NestedRouteDefenseInventory` は他のほぼ全 TODO と衝突する。実装期間中は他の route 追加・controller 追加を止める |
| 進行順序 | **単位 A**（施策 1 → 2 → 4）→ **単位 B**（施策 5 → 6 → 7 → 8）→ 施策 3 → 施策 9 → 施策 10 → 施策 11。施策 3（改名）は単位 A の型が入った後ならいつでもよいが、単位 B の後に置くと URL 生成の変更と衝突しないので後段に置く |
| **着手の前提条件** | **退会予約のアプリ内通知の org 文脈について auth / account-deletion 側の裁定を得ること**（施策 7）。裁定が出るまで単位 B の該当部分と `current_organization_id` 撤去 migration に着手しない。単位 A・施策 9・施策 10 は前提条件の影響を受けないので先行できる |

---

## リスク（全体）

| # | リスク | 影響 | 緩和 |
|---|---|---|---|
| R1 | 旧 URL が 404 になる（転送を置かない） | ブックマーク・共有 URL・送信済みメールが切れる | 正典と思考原則 3 に従う判断。リポジトリ内の生成元は施策 10 で 0 件。リポジトリ外は**保証外**と明記 |
| R2 | 移設漏れ / route 生成の引数ずれ | cross-org の温床 | `OrganizationScopedRouteCoverageTest` / `OrganizationRouteGenerationTest` が落とす |
| R3 | migration が既存データで止まる | デプロイ失敗 | **意図した fail-closed**。事前確認 SQL を TODO の備考へ。**人が実行する**（AGENTS.md 禁止事項 3） |
| R4 | ローリングデプロイ不可（列削除） | 切替時のダウン | メンテナンス前提の手順（デプロイ → migrate → `route:cache` 再生成）を文書化 |
| R5 | 複数組織の利用者が PWA 起動のたびに選択画面を通る | UX 後退 | 自動選択は AG-037 の裏口なので採らない。組織付き URL のブックマークを案内 |
| R6 | Filament の vendor 更新で施策 9 の gate が赤くなる | CI 停止 | 未知種別 fail-closed は意図した動作。対処手順を docblock に |
| R7 | 並行性を実測できない（RefreshDatabase の制約） | 保証の誇張 | 3 層（Architecture / Unit seam / Feature 逐次）に分け、**保証範囲を docblock に明記**。先例は D7 |
| R8 | 変更規模が大きくレビューで見落とす | 品質 | 単位ごとに squash し、各施策に**負例つきの機械検査**を置く |
| R9 | **退会予約通知の org 文脈が未裁定** | 施策 7 と `current_organization_id` 撤去 migration が着手できない | **本設計の確定前提**として TODO に明記し、auth / account-deletion 側の裁定を得てから着手する。設計側では選ばない |
| R10 | 一意衝突の再試行が PostgreSQL の失敗トランザクションに阻まれる | 登録が 500 になる | 1 試行 = 1 savepoint。失敗した試行の Team / Default Team / role 付与が巻き戻ることを Feature テストで固定 |

---

## 使命・禁止事項チェック

- [x] 全施策が使命に寄与する（共用端末の誤組織撮影を構造的に防ぐ＝「思考ゼロ」の前提を守る）
- [x] AGENTS.md 禁止事項 1（テストなし完了報告）— 全施策にテスト計画があり、不変条件は
      Architecture/Feature テストへ登録する
- [x] AGENTS.md 禁止事項 2（PHPStan の widen / baseline）— なし
- [x] AGENTS.md 禁止事項 3（dev DB の破壊操作）— migration の事前確認は**人が行う**手順
- [x] AGENTS.md 禁止事項 4（`response()->json()` 直書き）— 改名・分岐とも Inertia。
      `capture.csrf-cookie` は既存の仕様固定 endpoint（204）で据え置き
- [x] AGENTS.md 禁止事項 5 / 6（LLM 経路）— 触れない
- [x] AGENTS.md 禁止事項 7（`redirect()->intended()`）— 分岐 route は `redirect()->route()`
- [x] AGENTS.md 禁止事項 8（disabled UI）— 改名の回数上限は押下時にエラー表示
- [x] AGENTS.md 禁止事項 9（Artifact）— 成果物はすべて `devnotes/` 配下のファイル
- [x] app-design 禁止事項 3（既存テストの削除・上書き）— 削除する 2 本は**検査対象そのものの撤去**であり、
      不変条件の移送先を施策 7 に明示した
- [x] app-design 禁止事項 5（個別の `DatabaseTransactions`）— 使わない
- [x] 新モデル 1 本（`OrganizationSlugRename`）に Factory を作り、
      `docs/architecture.md` / `docs/factories.md` へ追記する
- [x] `*NoOverflow` 規約 — 日の加減算は対象外（月/年/四半期のみ）であることを施策 3 に明記


---

## 再レビュー依頼

Round 2 の停止条件 6 点と、Critical 12 件 / Warning 9 件 / Suggestion 3 件への対応が
十分かを判定してほしい。

なお本フェーズの成果物は**実装ではなく設計**である。クラスの完全な実装本文・
全 route の逐一の列挙・全テストケースの本文は実装フェーズの責務であり、
設計としては「方針・境界・型の契約・保証範囲・テストで固定する対象・リスク」が
確定していれば足りると考えている。この水準で判定してほしい。

全体判定を APPROVED / CHANGES_REQUESTED で示すこと。
