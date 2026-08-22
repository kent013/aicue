# 詳細設計レビュー Round 2

Round 1 の [Critical] 22 件・[Warning] 17 件への対応を反映した。
対応マトリクスと、修正後の詳細設計全文を示す。

補足として、指摘を受けて **実測で確認した事実**が 3 つある (設計本文にも反映済み):

1. `organizations.slug` は `$table->string('slug')->unique()` = **varchar(255) + 既存の
   `organizations_slug_unique`**。よって長さ上限は 255 とし、UNIQUE は再追加しない。
2. `public/manifest.webmanifest` に **`scope` キーは無い**。W3C 既定 scope は `start_url`
   (`/app`) の親パス = `/` なので、`/organizations/{slug}/app/...` は scope 内に収まる。
   service worker も `/capture-sw.js` から登録しており scope は `/`。この前提を検査で pin する。
3. 現行の組織 route は `/organizations/{organization:slug}/settings` なので、
   `settings` は識別名の**次**の位置であり衝突しない。識別名と同じ位置 (第 2 セグメント) の
   静的語は `organizations/create` の `create` である。

# 対応マトリクス: design-review Round 1

Critical 22 件 / Warning 17 件。**すべてに対応した**（反論 1 件のみ）。以下、施策ごとに記す。

---

## 施策 1

### [Critical] `MIN_LENGTH = 3` / `MAX_LENGTH = 63` は正典外の追加仕様
- 判断: **対応する**
- 根拠: そのとおり。「将来のサブドメイン化」は思考原則 2（今必要なものだけ作る）に反する。
- 対応内容: 長さの根拠を**既存 DB 列**へ寄せた。`organizations.slug` は `$table->string('slug')`
  = varchar(255) なので `MAX_LENGTH = 255`。**下限は撤廃**（構文の正規表現が
  `[a-z0-9]` を 1 文字以上要求するので「空でない」は自動的に保証される）。
  「正典 boundary は文字種・長さ・予約語と書くが具体値は定めていない」ことも明記した。

### [Critical] `trim($input)` が「小文字化以外は矯正しない」契約に反する / `$` が末尾改行を許す
- 判断: **対応する**
- 根拠: 2 つとも実害がある。`" acme "` の黙った矯正、`"acme\n"` の通過。
- 対応内容: `trim()` を外し、前後空白は**不正入力として拒否**。
  正規表現を `/^...$/` から **`/\A...\z/`** へ変更した。負例に
  「前後空白」「末尾改行」を追加した。

### [Critical] migration が I6 の文字種・先頭末尾/連続ハイフンを DB で守らない / 長さ検査が無い
- 判断: **対応する**
- 根拠: 指摘が正しい。小文字化と一意性だけでは `abc_def` / `a--b` を直接保存できる。
- 対応内容: CHECK を**構文全体**にした
  （`slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$' AND length(slug) <= 255`）。
  既存行の検査も「正規化後の値が**構文を満たすか**」を全行で見る形へ変え、
  違反があれば **更新前に** fail-closed で止めるようにした。
  PostgreSQL の `~` と PHP の `\A\z` の非対称（PG の `^$` は既定で文字列全体に一致）も
  migration の docblock に書くことにした。

### [Critical] 既存 `organizations_slug_unique` に対して同名 unique を再追加する
- 判断: **対応する**
- 根拠: 実測で確認した。create migration の `$table->string('slug')->unique()` により
  `organizations_slug_unique` は**既に存在する**。
- 対応内容: migration から `$table->unique('slug', ...)` を**削除**し、
  「UNIQUE は既存のものをそのまま使う。再追加すると重複 index になる」と明記した。

### [Warning] `deriveFromName()` が private constructor を直接呼び構文検査を迂回
- 判断: **対応する**
- 対応内容: 検査点を **`fromString()` 1 本**に統一し、`deriveFromName()` は
  切り詰め後の候補も必ず `fromString()` へ通す形にした（例外を捕まえて `null` を返す）。

---

## 施策 2

### [Critical] 予約語の**初版導入時**に既存 slug の衝突検査が無い
- 判断: **対応する**
- 根拠: 将来追加にだけ義務を課して初版に課さないのは筋が通らない。既存の `admin` 等が残れば I11 未充足。
- 対応内容: `2026_08_23_000200_verify_no_reserved_organization_slug.php` を新設し、
  **初版導入も fail-closed** で既存データを検査するようにした。

### [Critical] 保存経路 1 本の型契約が施策間で閉じていない（中間状態に穴）
- 判断: **対応する**
- 根拠: 施策 1 → 2 → 4 と分けると、途中で「構文型だけを保存できる経路」が残る。
- 対応内容: **「変更単位（原子性）」節を新設**し、
  **単位 A = 施策 1 + 2 + 4 の provisioning シグネチャ変更**を
  「マージ前に成立していなければならない原子的な変更単位」と定義した。
  `AssignableOrganizationSlug` の導入・Service シグネチャ変更・**Factory を含む全保存元の切替**を
  同じ単位に入れ、`OrganizationSlugWritePathTest` も単位 A で同時に入れることを明記した。

### [Warning] `contains()` → `reasonFor()` の 2 回呼びで PHPStan が非 null 性を導けない
- 判断: **対応する**
- 対応内容: `reservationFor(): ?SlugReservationReason` の **1 回の取得で分岐**する形へ変更した。

### [Warning] route conflict は「slug と同じ位置」の静的セグメント。`settings` は第 3 セグメント
- 判断: **対応する**
- 根拠: 実測で確認した。現行 route は `/organizations/{organization:slug}/settings` なので
  `settings` は識別名の**次**の位置であり、衝突しない。衝突するのは `organizations/create` の `create`。
- 対応内容: 「識別名の位置」を **`/organizations/` 直下の第 2 セグメント**と定義し直し、
  走査器を**位置で判定**する形にした（語の一致だけで拾わない）。
  `authority_impersonation` / `syntax_conflict` は route 表から導けないので
  「設定ファイルが唯一の正本」であることも明記した。

---

## 施策 3

### [Critical] 履歴作成で tenant/actor キーを mass assignment している
- 判断: **対応する**
- 根拠: AGENTS.md セキュリティ不変条件 1（tenant キー不信）+ 実装規約
  （保護キーは forceFill / relation で明示代入）に反する。
- 対応内容: `organization()->associate()` / `renamedBy()->associate()` + `forceFill()` へ変更した。

### [Critical] 改名後の応答先が未設計。旧 URL へ `back()` すると 404。モデルを渡すと id で URL 生成
- 判断: **対応する**
- 根拠: 2 つとも実害がある。`getRouteKeyName()` は `id` のままなので、
  `route(..., $organization)` は `{organization:slug}` でも id を入れ得る。
- 対応内容: **新しい識別名を名前付き引数で明示**して遷移する形にした
  （`redirect()->route('organizations.settings', ['organization' => $slug->value])`）。
  Location に新 slug が入ることを Feature テストで固定する。

### [Warning] `subDaysNoOverflow()` は不要で意味も不明瞭
- 判断: **対応する**
- 根拠: AGENTS.md が `*NoOverflow` を必須にしているのは**月・年・四半期**であり、
  日の加減算に overflow は起きない。`CarbonOverflowArithmeticGateTest` の対象も月/年/四半期。
- 対応内容: `subDays(30)` へ変更し、`$now` を 1 回だけ取得して使い回すことを明記した。
  境界の包含規則（「30 日前**以降**」= 境界を含む）も明記し、テストに入れた。

### [Warning] 同一 slug への改名 / `nextAvailableAt` の算出 / 複合 index / FK 削除規則が未定義
- 判断: **対応する**
- 対応内容: 4 点すべて確定した。
  同一識別名への改名は **422 で拒否**（回数を消費させない。no-op を成功にすると
  利用者から見て「変えたのに変わっていない」になる）/
  `nextAvailableAt` = **窓内で最も古い履歴の `renamed_at` + 30 日** /
  複合 index **`(organization_id, renamed_at)`** /
  FK は `organization_id` が `cascadeOnDelete`、`renamed_by_user_id` が `nullOnDelete`。

### [Warning] 一意制約違反は SQLSTATE 23505 だけでなく制約名まで一致させる必要がある
- 判断: **対応する**
- 根拠: そのとおり。`laratrust_team_id` の一意違反まで「識別名が使われている」に化ける。
- 対応内容: `OrganizationSlugConstraintViolation::isSlugTaken()` を
  **SQLSTATE 23505 かつ制約名 `organizations_slug_unique`** の一致で判定する形にし、
  「別の一意違反は再送出される」テストを追加した。

---

## 施策 4

### [Critical] 日本語名で slug 導出が失敗し、登録不能になる（入力経路が接続されていない）
- 判断: **対応する**
- 根拠: 初期組織名は `"{name} の組織"` なので、日本語利用者では **導出が必ず失敗する**。
  値オブジェクトが値を捏造しない設計にした結果、Service 側の代替が必要になっていた。
- 対応内容: 「識別名の入力フロー」表を新設し、3 経路を確定した。
  - 通常/ソーシャル登録: 利用者に入力させない。導出 → 失敗 → **Service のフォールバック**
    （`org-{12 文字}`。昇格で予約語でないことを確認し、一意衝突は**最大 3 回**再試行。
    無限ループを作らない）
  - 組織作成画面: **任意の識別名入力欄**を足す。予約語・使用済みは **422 で利用者へ返す**
    （黙って代替を作らない）
  - 改名: 入力必須
  `StoreOrganizationRequest` / `Organizations/Create.svelte` を変更対象に追加した。

### [Critical] 逐次 2 回のテストでは行ロックの目的を検証できない
- 判断: **対応する**（ただし「別接続で並行実測する」は採らない — 下記の根拠）
- 根拠: 指摘は正しい。ただし `tests/Pest.php` は `RefreshDatabase` をグローバル適用しており、
  テストは**未 commit のトランザクション内**で走る。**別接続からはテストデータが一切見えない**ため、
  独立接続による並行テストは成立しない。個別の `DatabaseTransactions` は
  app-design 禁止事項 5 で禁止されている。
- 対応内容: 「並行性の検証方法」節を新設し、検証を **3 層**に分けた。
  (1) Architecture = `lockForUpdate()` があり**ロック後**に数えていることの構文解析固定（負例つき）/
  (2) Unit = 「ロック取得後に他者が作っていた」分岐を seam 経由で直接叩く /
  (3) Feature = 逐次 2 回で 1 件・登録失敗時の巻き戻り。
  そのうえで **「並行でも 1 件」を実測したとは書かない**ことと、
  保証範囲を gate の docblock に明記することを義務にした。
  本リポジトリは同じ制約に対する先例（`docs/template-divergence.md` **D7** =
  直列化実証テストを保留し逐次境界テストで代替）を既に持つので、それを根拠として引いた。

### [Critical] `User::query()->whereKey(...)` 等が `ModelDirectFetchInvariantTest` の分類対象
- 判断: **対応する**
- 根拠: そのとおり。AGENTS.md 不変条件 3 が「クラス起点の主キー同一性クエリは
  deny-by-default で分類が要る」と書いている。
- 対応内容: 波及変更へ **`DirectFetchInventory` への登録**を追加した
  （actor 由来 / binding 由来の再取得として分類）。
  施策 3 の `Organization::query()->whereKey()->lockForUpdate()` も同様に追加した。
  「静的検査を迂回するためにクエリ形を変えない」ことも明記した。

### [Warning] `provision()` の新旧シグネチャが施策 4 と施策 7 で食い違う
- 判断: **対応する**
- 対応内容: 最終シグネチャを
  `provision(User $creator, string $name, ?string $requestedSlug = null)` に統一し、
  「単位 A で全呼び出し元を同時に切り替える」と明記した。

---

## 施策 5

### [Critical] 「全 route 名が変わる」と「`projects.*` をそのまま使う」が矛盾
- 判断: **対応する**
- 根拠: 設計の内部矛盾。最小変更なら route 名維持が妥当という指摘も正しい
  （aigenba も「route 名は不変 — capability 参照・冪等記録・監査台帳が名を鍵にする」と記録している）。
- 対応内容: **route 名は一切変更しない。変えるのは URI だけ**と確定し、
  移設表を「route 名（不変）/ 現行 URI / 移設後 URI」の 3 列に直した。
  帰結として **施策 10 の「撤去した route 名」は `organizations.switch` の 1 本だけ**になり、
  旧パス検出と撤去 route 名検出を**別の台帳**に分けた。

### [Critical] `{organization:slug}` 追加で全 route 生成に引数が要る（TS 変更「なし」は誤り）
- 判断: **対応する**
- 根拠: そのとおり。位置引数のままだと **project が organization 引数へずれる**（cross-org の温床）。
- 対応内容: 「route 生成の全呼び出し元」節を新設し、
  **全 route 呼び出しを名前付き引数へ変更**する方針を確定した
  （`['organization' => $organization->slug, 'project' => $project->getKey()]`）。
  PHP（`route()` / `redirect()->route()` / `URL::route()` / Blade / Mailable / 通知）と
  Svelte（route ヘルパ・URL 直書き）の両方を棚卸し対象にし、
  新 gate `OrganizationRouteGenerationTest`（位置引数を負例で検出）を足した。
  施策 6 にも「主要画面の**生成 URL に slug が含まれる**」Feature テストを追加した。

### [Critical] 施策 5 で URL 方式を足し施策 7 まで保持列が残る順序は AG-037 に抵触
- 判断: **対応する**
- 根拠: そのとおり。中間状態そのものが裁定違反である。
- 対応内容: **単位 B = 施策 5 + 6 + 7 + 8** を原子的な変更単位として定義し、
  「単位の途中状態を main へマージしない・デプロイしない・共有しない」
  「worktree で単位ごとに squash してから main へマージする」を明記した。

### [Warning] 「57 本の業務 route」の抽出条件が説明だけ
- 判断: **対応する**
- 対応内容: 業務 route を**機械的な条件**で定義した
  （`web` group + `auth` + 業務 group に属する named route）。
  除外は**理由付きの台帳** `OrganizationlessWebRouteInventory` に登録し、
  `OrganizationScopedRouteCoverageTest` が deny-by-default で固定する。

---

## 施策 6

### [Critical] フロント波及が `AppLayout` 周辺に限定されている / 旧 URL 検査では引数忘れを拾えない
- 判断: **対応する**
- 対応内容: 施策 5 の「route 生成の全呼び出し元」棚卸しへ統合し、
  施策 6 に `GeneratedUrlContainsOrganizationSlugTest`（主要画面の生成 URL に slug が含まれる）を追加した。

### [Warning] DTO の array shape と TypeScript 型の一致を固定するテストが無い
- 判断: **対応する**
- 対応内容: `CurrentOrganizationSharedPropShapeTest` を新設し、
  **PHPStan docblock の array shape を SoT** にして 2 か所に値を写さない形にした。

---

## 施策 7

### [Critical] `currentOrganization` を 0 件にすると施策 6 で残す共有 prop と DTO を自ら違反にする
- 判断: **対応する**
- 根拠: 完全にそのとおり。一般語の全面禁止は自己矛盾になる。
- 対応内容: 検査を **4 つの別々の構文**に分けた
  （列名リテラル `current_organization_id` / `User` に対する `currentOrganization` relation と
  プロパティアクセス（完全修飾名で解決）/ resolver の FQCN / route 名 `organizations.switch`）。
  テスト名も `CurrentOrganizationRemovalTest` に改め、4 形それぞれの負例を置くことにした。

### [Critical] 退会予約のアプリ内通知廃止は別 feature の仕様変更
- 判断: **対応する**（採る解が変わった）
- 根拠: 指摘が正しい。乖離台帳へ書けば裁定済みになるわけではない。
  一方で「所属組織のどれか 1 つを選ぶ」は AG-037 の裏口なので採れない。
- 対応内容: **「所属する全組織へ配る」**へ変更した。退会は利用者の全所属に影響する事象なので、
  これは**選択ではなく網羅**であり AG-037 に抵触しない。かつ利用者から見た保証
  （アプリ内でも退会予約に気付ける）は**維持される＝後退ではない**ので、
  別 feature の仕様後退にもならない。
  Feature テスト（複数所属で全組織に通知 / 所属 0 件で 0 件 / メールは従来どおり）を追加し、
  「auth 側へ申し送る」ことを TODO の備考に書くことにした。
  併せて **D41 の新規登録を取り下げた**（施策 11）。

### [Critical] Filament の「全所属組織一覧」表示は必要最小限を超えた新機能
- 判断: **対応する**
- 根拠: そのとおり。N+1 と情報量増加も起きる。思考原則 2 に反する。
- 対応内容: **`currentOrganization.name` エントリを削除するだけ**に留めた。

### [Warning] rate limiter が `'none'` へ倒れると配線不良を黙って許す
- 判断: **対応する**
- 対応内容: `Assert::isInstanceOf()` で **fail-closed** にし、
  `RenderTriggerRouteOrganizationParamTest`（render-trigger 対象 route は必ず organization binding を持つ）を追加した。

### [Warning] 列削除 migration の FK/index 削除順とローリングデプロイ非互換が未記載
- 判断: **対応する**
- 対応内容: `dropConstrainedForeignId()` を使うこと、`down()` の逆順、
  **メンテナンス前提の切替手順**（メンテ → デプロイ → `migrate` → **`route:cache` 再生成** → 解除）を
  明記した。本リポジトリにデプロイ定義が無いので**人手の手順**として文書化する
  （存在しない基盤の preflight を先回りして作らない＝思考原則 2）。

### [Suggestion] 「テスト削除は禁止事項 3」は誤り
- 判断: **対応する**（事実訂正）
- 根拠: AGENTS.md の禁止事項 3 は「dev DB への破壊操作」。
  「既存テストの削除・上書き」は **app-design スキル側の禁止事項 3** である。
  番号が 1:1 対応しないのは AGENTS.md 自身が注意している点でもある。
- 対応内容: どちらの一覧の何番かを明記し、削除する 2 本について
  **不変条件の移送先を表で示した**。

---

## 施策 8

### [Critical] `count() === 1` から `first()` の非 null 性を PHPStan は導けない / モデル渡しで id 混入
- 判断: **対応する**
- 対応内容: `sole()` で `Organization` を得る形へ変更し、
  URL には **slug の文字列を名前付き引数で**渡すようにした。

### [Critical] manifest の `scope` と service worker の navigation 範囲を検証していない
- 判断: **対応する**
- 根拠: 検証すべきという指摘は正しい。**実測した結果、前提は成立していた**:
  `public/manifest.webmanifest` に **`scope` キーは無い**（実測）。
  W3C 仕様の既定 scope は `start_url` の親パス = **`/`**。
  service worker も `/capture-sw.js` から登録しており scope は `/`。
  よって `/organizations/{slug}/app/...` は scope 内に収まる。
- 対応内容: この 2 つの前提を **`CapturePwaScopeTest` で pin** することにした
  （`scope` キーが無いこと・`start_url` が `/app` であること・組織付き capture URL が scope 内であること）。
  前提が変わったら赤くなる。

### [Warning] `CaptureStartUrlTest` の「200 か 302」は弱い
- 判断: **対応する**
- 対応内容: **0 / 1 / 複数所属ごとに、正確な route 名・slug・Location を検証**する形へ変えた。
  併せて「遷移先が query string で操作できない」（open redirect の負例）も追加した。

### [Warning] Choose 画面へ Eloquent collection を直接渡さない
- 判断: **対応する**
- 対応内容: `OrganizationChoiceData` DTO を新設し、Inertia props へは DTO を渡す形にした。

---

## 施策 9

### [Critical] `NotOrganizationScoped` が解決点ごとの provenance enum に混在している
- 判断: **対応する**
- 根拠: そのとおり。解決点 0 件なら分類対象の解決点が存在せず、完全一致を表現できない。
- 対応内容: **入口の分類**と**解決点の provenance** を別の型にした。
  入口は `NotOrganizationScoped(reason)` か `OrganizationScoped(non-empty-list<解決点>)` の
  2 択（抽象クラス）にし、provenance enum からは `NotOrganizationScoped` を外した。
  理由は 30 文字以上を gate が強制する。

### [Critical] `array<string, list<Provenance>>` では解決点を識別できず親も比較できない
- 判断: **対応する**
- 対応内容: `OrganizationResolutionPoint` DTO を新設し、
  **`entryPointId` + `resolutionId` + `provenance` + `parentResolutionId`** を持つ形にした。

### [Critical] 字面検出では対象モデル・入力由来・alias/group use を解決できず規約 (a)(b) を満たさない
- 判断: **対応する**
- 根拠: AGENTS.md §走査器の共通規約 (a)(b) にそのまま抵触する。
- 対応内容: **完全修飾名で突き合わせる**（`use` / group use / 別名つき取り込みを解く）ことと、
  解決できない形を **`UnresolvedReference` として gate 失敗に流す**ことを明記した。
  保証範囲の外にする構文は docblock に書き、
  **明記した構文については検出力を主張しない**／その構文で保護対象の操作が書ける場合は
  **利用側 gate の主張を明示的に狭める**（規約 (b)）ことも書いた。
  負例に「別名つき取り込みで黙らない」を追加した。

### [Warning] `Artisan::all()`（vendor 全件）と application-defined だけ、が矛盾
- 判断: **対応する**
- 対応内容: I14 の責務対象を **application-defined の入口に限定**すると明記した
  （vendor command は対象外。vendor 内部の解決は保証範囲外であることを docblock に書く）。

---

## 施策 10

### [Critical] 維持する `projects.*` を「撤去した route 名」として検出すると正しい実装まで落ちる
- 判断: **対応する**
- 対応内容: 施策 5 で route 名維持を確定したので、
  **旧パス台帳**（URL 文字列）と**撤去 route 名台帳**（`organizations.switch` の 1 本だけ）を
  **別の台帳**に分けた。

### [Warning] 文書のファイル数だけを pin すると同一ファイル内の増加を検出できない
- 判断: **対応する**
- 対応内容: **ファイルごとの一致件数と対象パターンを完全一致で pin**（増減のどちらでも赤）とした。

### [Warning] `/app` は入口として残り新 capture URL もあるので部分文字列検査は誤検出する
- 判断: **対応する**
- 対応内容: **区切り集合を宣言**した（`/` `?` `#` `"` `'` `` ` `` の 6 文字で分割し
  セグメントの完全一致で判定）。負例に**接頭辞つき・打ち消しつき・接尾辞つきの 3 形**
  （`/myapp` / `/app-old` / `/organizations/acme/app`）を置くことにした（走査器規約 (e)）。

---

## 施策 11

### [Critical] D41 は別 feature の未確定な機能後退を取り込む登録
- 判断: **対応する**
- 対応内容: 施策 7 で「所属する全組織へ配る」＝後退させない設計に変えたので、
  **D41 を取り下げた**。追加は **D40 の 1 件だけ**になり、
  `DIVERGENCE_ENTRY_COUNT` を 36 → **37** に修正した。

### [Warning] `LedgerPins` の数値は最終的な内容に依存する
- 判断: **対応する**
- 対応内容: 「**数値は実装時に実ファイルの実数から再確認する**（本書の値は設計時点の見込み）」と明記した。

---

## 横断的な必須修正 4 点

1. **URL 生成は slug を名前付き引数で渡す** — 施策 3 / 5 / 7 / 8 のすべてに反映した。
2. **施策 5〜8 を原子的単位にする** — 「変更単位（原子性）」節で単位 B として定義した。
3. **日本語名・予約語・導出不能時の登録 slug 入力フロー** — 施策 4 に 3 経路の表として完成させた。
4. **PostgreSQL の並行テスト方式** — RefreshDatabase では別接続の並行実測が成立しないため、
   3 層（Architecture / Unit seam / Feature 逐次）へ分け、
   **保証範囲を誇張しない**ことを義務にした（先例 D7）。


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
| I1 | URL に現れた資源が現在の組織に属することを**入力検証より前**の段で確かめ、違えば **404**（403 にしない） | AG-036 | **充足**（aicue 形が標準形として採用された側。`MembershipScopedOrganizationBinder` + `project.in-current-org` + `TenantBoundaryOrderingTest`） | **スコープ外**（層の位置は動かさない。org の取得元だけ変える） |
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
| 組織別の動的 manifest | `start_url` は分岐 route で足りる（思考原則 2） |

---

## 変更単位（原子性）— AG-037 の「2 方式併存不可」を守るための必須制約

**施策の順序だけでは足りない。** 途中状態が「保持列方式と URL 方式の併存」になったり、
「構文型だけを保存できる経路」が残ったりすると、それ自体が裁定違反である。
そこで**マージ前に成立していなければならない原子的な変更単位**を 2 つ定義する。

| 単位 | 含む施策 | なぜ原子的でなければならないか |
|---|---|---|
| **単位 A**（識別名） | 施策 1 / 2 / 4 の provisioning シグネチャ変更 | `AssignableOrganizationSlug` の導入・Service シグネチャ変更・Factory を含む**全保存元の切替**が同じ変更で閉じないと、中間状態に「構文型だけを保存できる経路」が残る |
| **単位 B**（組織 URL 単一方式） | 施策 5 / 6 / 7 / 8 | 施策 5 で URL 方式を足し、施策 7 まで保持列方式が残る状態は **AG-037 の「2 方式併存不可」に真正面から抵触**する |

- 単位の内部は作業しやすさのためにコミットを分けてよいが、**単位の途中状態を main へマージしない・デプロイしない・共有しない**。
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
    // 1. 更新せずに、正規化後の値が **構文と予約語の両方** を満たすかを全行検査する
    //    (小文字化だけでは I6 の文字種・先頭末尾/連続ハイフンを守れない)
    $violations = DB::table('organizations')->select('id', 'slug')->get()
        ->filter(fn (object $row): bool => ! self::normalizesToValidSlug((string) $row->slug));

    // 2. 正規化後に衝突する行を検査する
    $collisions = DB::table('organizations')
        ->selectRaw('lower(slug) as normalized, count(*) as c')
        ->groupBy('normalized')->havingRaw('count(*) > 1')->pluck('normalized');

    if ($violations->isNotEmpty() || $collisions->isNotEmpty()) {
        throw new RuntimeException(
            '識別名の正規化に失敗する組織がある。運用で解消してから再実行すること。'
            .' 構文/予約語違反: '.$violations->pluck('id')->implode(', ')
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
      **CHECK が実際に効く**こと（`DB::table('organizations')->insert(['slug' => 'Acme'])` /
      `'ac--me'` / `'-acme'` が落ちる）
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

**初版導入時の既存データ検査**（reviewer 指摘。将来追加と同じ義務を初版にも課す）:

```php
// 2026_08_23_000200_verify_no_reserved_organization_slug.php
public function up(): void
{
    $reserved = OrganizationSlugReservedWords::load();
    $offenders = DB::table('organizations')->pluck('slug')
        ->filter(fn (string $slug): bool => $reserved->reservationFor(OrganizationSlug::fromString($slug)) !== null);

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

### 変更後コード

```php
/**
 * 改名の実行 (家系裁定 AG-046)。
 *
 * ★最終権威は**組織行を行ロックした後の再判定**である。事前判定 (画面表示のための残り回数) は
 *   早期拒否にすぎず、ここでの再判定が唯一の権威である。
 * ★30 日は**ローリング窓**である。境界は「now から 30 日前 **以降**」(境界を含む)。
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
            ->where('renamed_at', '>=', $now->subDays(self::WINDOW_DAYS))
            ->orderBy('renamed_at')
            ->get();

        if ($used->count() >= self::LIMIT) {
            // 次に改名できる時刻 = 窓内で最も古い履歴の renamed_at + 30 日
            throw new SlugRenameLimitExceededException(
                $used->first()?->renamed_at->addDays(self::WINDOW_DAYS),
            );
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
- [x] `$used->first()?->renamed_at` の null 安全
- [x] 例外は型付き

### テスト計画

- [ ] 新規 `tests/Feature/Organization/OrganizationSlugRenameTest.php` —
      改名成功で**新 slug の URL へ遷移**する（Location に新 slug が入る）/ 旧 URL が 404 /
      **旧識別名を他組織が取れる**（I13）/ **同じ識別名への改名が 422**
- [ ] 新規 `tests/Feature/Organization/OrganizationSlugRenameLimitTest.php` —
      30 日 5 回で 6 回目が拒否 / 境界（ちょうど 30 日前は窓に**含む**）/
      `nextAvailableAt` が最古の履歴 + 30 日 / **事前判定を通っても行ロック後の再判定で落ちる**
- [ ] 新規 `tests/Feature/Organization/OrganizationSlugTakenTest.php` —
      他組織が使用中の識別名で 422 / **別の一意違反は再送出される**
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

> **一意衝突の再試行**: `provision()` は `organizations_slug_unique` 違反を
> `OrganizationSlugConstraintViolation::isSlugTaken()` で識別し、
> **利用者入力の場合は 422 で返し**、フォールバック生成の場合だけ**最大 3 回**やり直す。
> 3 回失敗したら例外（乱数 12 文字で 3 連続衝突は事実上起きない。無限ループを作らない）。

### 変更後コード（provisioning）

```php
/**
 * 組織 + Laratrust Team + Default Team を原子的に生成し、creator を Owner にする。
 *
 * ★シグネチャは `provision(User $creator, string $name, ?string $requestedSlug = null)` に統一する
 *   (施策 7 の呼び出しサイトもこの形。単位 A で全呼び出し元を同時に切り替える)。
 * ★current_organization_id への書き込みは持たない (家系裁定 AG-037)。
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

### テスト計画

- [ ] 更新 `tests/Feature/Organization/DefaultTeamInvariantTest.php`
- [ ] 新規 `tests/Feature/Organization/InitialOrganizationIdempotencyTest.php`
- [ ] 新規 `tests/Unit/Services/Organization/InitialOrganizationRaceBranchTest.php`（seam 経由）
- [ ] 新規 `tests/Architecture/OrganizationProvisioningCallSiteTest.php`（負例つき）
- [ ] 新規 `tests/Feature/Organization/OrganizationCreateSlugTest.php` —
      日本語名で登録が通る（フォールバック）/ 利用者入力の予約語・使用済みが **422**
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

新設 gate:

```
tests/Architecture/OrganizationRouteGenerationTest.php
  - 業務 route を生成する呼び出しが **名前付き引数**で organization を渡していることを固定
  - 位置引数での生成を負例で検出できること (完全修飾名で解決。未解決は fail-closed)
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
- 改称・改修: `EnsureProjectBelongsToCurrentOrganization` → `EnsureProjectBelongsToRouteOrganization`
  （alias `project.in-current-org` → `project.in-route-org`）
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
  - CurrentOrganizationData::toArray() のキー集合が
    resources/js/lib/shared-props.ts の CurrentOrganization 型と一致することを固定
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
| `NotificationCenterService::notifyAccountDeletionRequested` | **所属する全組織へアプリ内通知を配る**（下記） |
| `NotificationController::belongsToCurrentOrg` | URL 上の組織と一致するか（通知一覧が組織配下になる） |
| `NotificationController::manualStillExists` | URL 上の `$organization` から辿る |
| `OrganizationController::store` | current への書き込みを削除。`redirect()->route('organizations.settings', ['organization' => $organization->slug])` |
| `OrganizationMembershipService`（招待受諾） | current 書き込みを削除。受諾後の遷移先を**招待先組織の URL**にする |
| `OrganizationMembershipService::removeMember` | current の null 化を削除（列が無い） |
| `OrganizationMembershipService`（退会ブロッカー） | 「現在の組織か」の項目を根拠列とともに撤去（**判定に使っていないことを実測してから**消す） |
| `RequireActiveSubscription` | `$request->route('organization')`。**組織 binding が無ければ fail-closed（500）**。「課金ゲート配下の全 route は組織引数を持つ」を Architecture 検査で固定 |
| `UserResource`（Filament） | **`currentOrganization.name` エントリを削除するだけ**。所属組織一覧の表示は**足さない**（必要最小限を超える新機能・N+1 になる。必要なら別 feature） |

### 退会予約のアプリ内通知（reviewer Critical への回答）

`AppNotification::organizationId()` は non-nullable なので、保持列を消すと org 文脈の出所が要る。

- **「所属組織のどれか 1 つを選ぶ」は採らない**（AG-037 の裏口そのもの）。
- **「作らない」も採らない**（別 feature の仕様を無断で後退させることになる。reviewer 指摘）。
- **採るのは「所属する全組織へ配る」**。退会は利用者の全所属に影響する事象なので、
  これは**選択ではなく網羅**であり AG-037 に抵触しない。利用者から見た保証
  （アプリ内でも退会予約に気付ける）は**維持される**（後退ではない）。
- 実装は `$user->organizations` を回して通知を作る（1 件も所属が無ければ 0 件 = 従来と同じ）。
- **この変更は `account-deletion-billing-guard` / auth 側の feature に隣接するため、
  TODO の備考に「auth 側へ申し送る」ことを明記する**（後退ではないので実装のブロッカーにはしない）。

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
- [ ] 新規 `tests/Feature/Auth/AccountDeletionNotificationFanoutTest.php` —
      複数所属で**全組織に**通知が作られる / 所属 0 件で 0 件 / **メールは従来どおり届く**
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
 */
public function __invoke(Request $request, EntryTarget $target): Response|RedirectResponse
{
    $user = $request->user();
    Assert::isInstanceOf($user, User::class);

    $count = $user->organizations()->count();

    if ($count === 0) {
        return redirect()->route('organizations.create');
    }

    if ($count === 1) {
        // ★sole() で Organization を得る (count()===1 から first() の非 null 性は
        //   PHPStan が導けない)。URL には **slug の文字列**を名前付きで渡す
        //   (モデルを渡すと getRouteKeyName()=id により id が入る)。
        $organization = $user->organizations()->sole();

        return redirect()->route($target->routeName(), ['organization' => $organization->slug]);
    }

    return Inertia::render('Organizations/Choose', [
        'target' => $target->value,
        'organizations' => OrganizationChoiceData::collect(
            $user->organizations()->orderBy('organizations.name')->get(),
        ),
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
- [x] `sole()` で `Organization` を得る（`first()` の null 分岐を作らない）
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

- 新設: `app/Enums/Security/OrganizationReferenceProvenance.php`
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
/** 1 つの解決点。**入口内で安定した ID** を持ち、親を辿れる。 */
final readonly class OrganizationResolutionPoint
{
    public function __construct(
        public string $entryPointId,       // 例: route 名 / command 名 / Filament クラス FQCN
        public string $resolutionId,       // 入口内で安定した識別子 (メソッド名 + 引数名)
        public OrganizationReferenceProvenance $provenance,
        public ?string $parentResolutionId, // RelationScoped のときだけ非 null
    ) {}
}
```

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
     *  親 (parentResolutionId) が PrimaryKeyBinding か ActorDerived であることが条件で、
     *  親が解決できなければ fail-closed で落ちる (再帰的 provenance)。 */
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
- [x] 台帳は `array<string, MachinePlaneEntryClassification>`（sealed 風の抽象クラス）

### テスト計画

- [ ] 新規 `tests/Architecture/MachinePlaneOrganizationReferenceTest.php` —
      全母集団が完全一致で分類 / 未登録・余剰・重複で赤 / 走査根が空で赤 /
      `NotOrganizationScoped` の理由が 30 文字未満で赤
- [ ] 新規 `tests/Unit/Architecture/MachinePlaneEntryPointsTest.php`（**負例で両方向**）—
      識別名で引く形 / 表示名で引く形 / 任意文字列で引く形を検出できる。
      許可 3 種別を誤検出しない。**親が解決できない `RelationScoped` が落ちる**。
      **別名つき取り込み（`use Organization as Org`）で黙らない**
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

### segment 区切りの宣言（reviewer Warning。走査器共通規約 (e)）

`/app` は**入口として残り**、`/organizations/{slug}/app/...` も新 URL として残るので、
素の部分文字列一致では誤検出する。**区切り集合を宣言する**:

> URL は `/` `?` `#` `"` `'` `` ` `` の 6 文字で分割し、**セグメントの完全一致**で判定する。
> `/app` は「先頭が `/app` で、次が区切りか終端」かつ「直前が `organizations/{slug}` でない」ときだけ旧扱い。

負例には**接頭辞つき・打ち消しつき・接尾辞つきの 3 形**を置く
（`/myapp` / `/app-old` / `/organizations/acme/app`）。

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

Round 1 の Critical 22 件・Warning 17 件と、横断的な必須修正 4 点への対応が十分かを判定してほしい。
特に次の 5 点を見てほしい:

1. **変更単位 (原子性)** の定義が、AG-037 の「2 方式併存不可」と型契約の中間状態を
   実際に塞げているか。
2. **識別名の入力フロー** (施策 4 の 3 経路) が、日本語名・予約語・使用済み・導出不能を
   すべて閉じているか。無限ループや黙った代替生成が残っていないか。
3. **route 名維持 + 名前付き引数** の方針で、cross-org の引数ずれが構造的に塞がっているか。
4. **並行性の検証方法** (3 層 + 保証範囲の明記) が、RefreshDatabase の制約下で採り得る
   最善になっているか。誇張した保証が残っていないか。
5. **施策 9 の 2 層の型** (入口の分類 / 解決点の provenance) が完全一致の分類を表現できているか。

全体判定を APPROVED / CHANGES_REQUESTED で示すこと。
