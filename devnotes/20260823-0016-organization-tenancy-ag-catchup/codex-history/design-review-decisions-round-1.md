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
