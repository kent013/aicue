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
