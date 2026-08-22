仮説は「裁定の方向性は正しいが、DB 制約・URL 生成・移行順序・機械検査の母集団に実装不能または不変条件を破る箇所が残っている」でした。提示本文だけで検証した結果、複数の Critical があるため全体承認はできません。

## 施策1: REQUEST_CHANGES

- [Critical] `MIN_LENGTH = 3` / `MAX_LENGTH = 63` は I6 に存在しない追加仕様です。特に「将来のサブドメイン化」は思考原則2に反し、正典外の制約混入です。  
  修正案: 正典または既存DB列が定める長さ以外を追加しないでください。追加するなら別裁定として確定させます。

- [Critical] `trim($input)` は「大文字を小文字へ倒す以外は矯正しない」という自身の契約に反します。`" acme "` が黙って `"acme"` になります。  
  修正案: `trim()` を外して空白を不正入力として拒否してください。PHP側の正規表現は末尾改行を許し得る `$` ではなく `\A...\z` を使います。

- [Critical] migration が保証するのは小文字と一意性だけで、I6 の文字種・先頭末尾ハイフン・連続ハイフン禁止をDBで守りません。`abc_def` や `a--b` を直接保存できます。また「長さ違反も検査する」と書きつつコードに検査がありません。  
  修正案: 正典の構文を PostgreSQL の CHECK にも実装し、既存行を更新する前に正規化後の全行を同じ条件で検査してください。

- [Critical] 現状が「通常の unique のみ」なら、既存の `organizations_slug_unique` に対して同名の unique を再追加する可能性があります。  
  修正案: 既存制約をそのまま利用するか、既存制約名を明示的に確認して必要な場合だけ置換する migration にしてください。

- [Warning] `deriveFromName()` が private constructor を直接呼び、構文検査を迂回しています。  
  修正案: 切り詰め後の候補も必ず `fromInput()` 相当の単一検査へ通してください。

## 施策2: REQUEST_CHANGES

- [Critical] 「将来の予約語追加」には既存データ検査を要求していますが、予約語を初めて導入する本施策自身に既存 slug の衝突検査がありません。既存の `admin` / `create` 等がそのまま残れば I11 を満たしません。  
  修正案: 初版予約語の導入にも fail-closed の migration または同一リリース内のデプロイ前検査を追加してください。

- [Critical] 保存経路1本の型契約が施策間で閉じていません。施策1でサービスを変更し、施策2で `AssignableOrganizationSlug` を追加し、施策4で初めてサービス引数へ入れる構成では、中間状態に構文型だけを保存できる経路が残ります。  
  修正案: `AssignableOrganizationSlug` の導入、サービスのシグネチャ変更、Factoryを含む全保存元の切替を同じ原子的変更にまとめてください。

- [Warning] `contains()` の後に `reasonFor()` を呼ぶ形では、PHPStan が理由の非null性を導けない可能性があります。  
  修正案: `reservationFor(): ?SlugReservationReason` の1回の取得で分岐するか、`reasonFor()` を「未登録なら例外」の非null戻り値にします。

- [Warning] route conflict は「`/organizations/` 直下で slug と同じ位置を占める静的セグメント」です。例示された `settings` は現在の経路では slug の次の位置です。  
  修正案: path segment の位置を比較し、実際に競合する `create` などだけを抽出してください。

## 施策3: REQUEST_CHANGES

- [Critical] 履歴作成の `OrganizationSlugRename::query()->create([...])` に `organization_id` と `renamed_by_user_id` を含める設計は、tenant/actor キーを mass assignment しない規約に反します。  
  修正案: `organization()` / `renamedBy()` の relation で associate し、サーバー導出値を明示代入して保存してください。

- [Critical] 改名成功後の応答先が未設計です。旧URLへ `back()` すると直後に404になります。また明示的 binding `{organization:slug}` にモデルを渡しても、`getRouteKeyName() === id` ならURL生成にIDが使われる可能性があります。  
  修正案: 新 slug を明示して `redirect()->route(..., ['organization' => $slug->value])` とし、そのURLへ遷移するFeatureテストを追加してください。

- [Warning] `subDaysNoOverflow()` はこの要件に不要で、30日ローリング窓の意味も不明瞭になります。NoOverflow必須なのは月・年・四半期演算です。  
  修正案: `$now` を一度だけ取得し、UTCで `$now->subDays(30)` を使って境界の包含規則を明記してください。

- [Warning] 同じ slug への改名が quota を消費するか未定義です。`nextAvailableAt` の算出、`(organization_id, renamed_at)` index、FK削除規則も不足しています。  
  修正案: 同値変更は422またはno-opとして明文化し、最古の有効履歴から次回時刻を計算するテストと複合indexを追加してください。

- [Warning] 一意制約例外は PostgreSQL の SQLSTATE `23505` だけでなく、対象制約名まで一致させないと別の一意違反を誤変換します。  
  修正案: `organizations_slug_unique` のみ利用者向け検証エラーに変換し、それ以外は再送出するテストを置いてください。

## 施策4: REQUEST_CHANGES

- [Critical] 日本語名では slug 導出が失敗し得る一方、登録トランザクション内で必ず組織を作る I18 と、利用者に slug を選ばせる入力経路が接続されていません。現状の `CreateNewUser` からは登録不能になります。  
  修正案: 通常登録・ソーシャル登録・組織作成の各入力へ任意 slug を追加し、導出不能または予約語なら利用者へ検証エラーを返すフローまで設計してください。

- [Critical] 逐次2回呼び出すテストだけでは、I4 が行ロックを求める目的を検証できません。  
  修正案: PostgreSQL の独立接続と同期バリアを使い、同一利用者に対する並行呼び出しでも組織が1件だけになるFeatureテストを追加してください。RefreshDatabaseの未commitデータを別接続から見られない問題もテスト方式に明記します。

- [Critical] `User::query()->whereKey(...)` と `Organization::query()->whereKey(...)` の追加は、`ModelDirectFetchInvariantTest` の分類対象になり得ますが波及変更にありません。  
  修正案: actor由来・binding済みモデル由来であることを `DirectFetchInventory` に登録するか、信頼済みrelationを使う実装へ変更してください。静的検査の迂回だけを目的にクエリ形を変えてはいけません。

- [Warning] `provision()` の新シグネチャと、施策7に掲載された `provision($user, $name)` が一致しません。  
  修正案: 全呼び出しサイトを最終シグネチャで一覧化し、各コミットでPHPStanが通る順序へ直してください。

## 施策5: REQUEST_CHANGES

- [Critical] 「全 route 名が変わる」と「`projects.*` 等をそのまま使う」が矛盾しています。施策10も `projects.*` を撤去済みroute名として扱っています。  
  修正案: URLだけ変更してroute名は維持するのか、route名も変更するのかを確定し、旧名→新名の完全な対応表を置いてください。最小変更ならroute名維持が妥当です。

- [Critical] `{organization:slug}` を追加すると、PHP・Svelte双方の全route生成で organization引数が必要です。「TypeScript変更なし」ではありません。位置引数で `$project` だけを渡すと、projectがorganization引数へずれる危険があります。  
  修正案: 全route呼び出しを名前付き引数へ変更します。例: `['organization' => $organization->slug, 'project' => $project->id]`。PHP/TS両方の呼び出し元を棚卸し対象にしてください。

- [Critical] 施策5でURL方式を追加し、施策7まで保持列方式を残す固定コミット順は AG-037 の「2方式併存不可」と衝突します。  
  修正案: 少なくとも施策5〜8を同一の原子的コミットまたはマージ前にsquashする変更単位とし、途中状態をデプロイ・共有しないことを明記してください。

- [Warning] 57本の「業務route」の抽出条件が説明だけで、将来追加されたrouteを何に基づいて業務routeと判定するか不明です。  
  修正案: route group、middleware、controller namespace等による母集団定義と、明示的な理由付き除外台帳を設けてください。

## 施策6: REQUEST_CHANGES

- [Critical] フロント波及が `AppLayout` 周辺に限定されていますが、組織配下へ移る57 routeを生成する全ページ・フォーム・リンクが対象です。旧URL文字列検査では「同じroute名にorganization引数を渡し忘れた」ケースを検出できません。  
  修正案: PHP/TypeScriptのroute生成呼び出しを全件棚卸しし、主要画面について生成URLに slug が含まれるFeature/JSテストを追加してください。

- [Warning] `CurrentOrganizationData::toArray()` のshapeとTypeScript型の一致を固定するテストがありません。  
  修正案: DTOのarray shapeをPHPStan docblockで固定し、共有propsとTypeScript側の契約テストを追加してください。

層2をbinding直後に維持し、組織route以外で `currentOrganization === null` とする設計自体は妥当です。

## 施策7: REQUEST_CHANGES

- [Critical] `CurrentOrganizationColumnRemovedTest` が `currentOrganization` 文字列を0件にすると、施策6で維持する共有prop `currentOrganization` と `CurrentOrganizationData` を自ら違反として検出します。  
  修正案: `current_organization_id` 列、`User::currentOrganization()` relation、resolver FQCN、switch routeを別々の構文として検出してください。一般語 `currentOrganization` の全面禁止はやめます。

- [Critical] 退会予約のアプリ内通知廃止は account-deletion/auth feature の仕様変更です。D41への記録だけでは、外部正典のスコープへ別featureの裁定を混入してよい根拠になりません。  
  修正案: auth側オーナーの裁定を先に得て別施策として扱うか、本追従設計をその決定待ちにしてください。少なくともメール継続・アプリ内通知非生成のFeatureテストが必要です。

- [Critical] Filament表示を「current organization削除」から「全所属組織一覧」へ変えるのは、必要最小限を超えた新機能です。N+1や情報量増加も発生します。  
  修正案: 本件では列を削除するだけに留め、所属一覧表示は別featureへ分離してください。

- [Warning] rate limiter がbinding未解決時に `'none'` へ倒れると、配線不良を黙って許し、slug改名等でキーの一貫性も失います。  
  修正案: `Organization` instanceをAssertし、render-trigger対象routeが必ずorganization bindingを持つArchitectureテストを追加してください。

- [Warning] users列削除migrationはFK/indexの削除順と、旧アプリとのローリングデプロイ非互換を明記していません。  
  修正案: maintenance前提の切替手順、migrationとコードの適用順、route cache再生成を運用手順へ追加してください。

なお、「テスト削除は禁止事項3に当たる」という記述は誤りです。禁止事項3はdev DBへの破壊操作です。

## 施策8: REQUEST_CHANGES

- [Critical] `$organizations->count() === 1` から `$organizations->first()` の非null性をPHPStanは保証できません。またモデルをそのまま `{organization:slug}` へ渡すとIDでURL生成される危険があります。  
  修正案: `sole()` で `Organization` を得て、`['organization' => $organization->slug]` を明示してください。

- [Critical] capture URLを `/organizations/{slug}/app/...` へ移す一方、manifestの `scope` とservice worker navigation範囲を検証していません。scopeが `/app/` ならPWA外へ出ます。  
  修正案: manifestの実際のscope、service worker対象、standalone起動後の遷移を検査し、組織付きcapture URLがscope内であることをFeature/Browserテストで固定してください。

- [Warning] `CaptureStartUrlTest` の「200か302」は弱く、loginへの302でも壊れた分岐でも通り得ます。  
  修正案: 0/1/複数所属ごとに正確なroute名・slug・Locationを検証してください。

- [Warning] Choose画面へEloquent collectionを直接渡す形は避けるべきです。  
  修正案: 最小の `OrganizationChoiceData` DTO等へ変換してInertia propsへ渡してください。

## 施策9: REQUEST_CHANGES

- [Critical] `NotOrganizationScoped` は「解決点が0件」の入口分類なのに、解決点ごとのprovenance enumへ混在しています。0件なら分類対象となる解決点自体が存在せず、完全一致を表現できません。  
  修正案: 入口を `NotScoped(reason)` または `Scoped(resolutions)` に分類し、後者だけが安定したresolution IDごとのprovenanceを持つ二層型にしてください。

- [Critical] `array<string, list<OrganizationReferenceProvenance>>` では、1入口内の複数解決点を識別できず、親provenanceや余剰・重複を正確に比較できません。  
  修正案: `entryPointId + resolutionId + provenance + parentResolutionId` を持つ型付きDTOにしてください。

- [Critical] `where('slug', $input)` 等の字面検出だけでは、対象モデル・入力由来・alias/group useを解決できず、走査器共通規約(a)(b)を満たしません。  
  修正案: FQCNと呼び出し対象を解決し、解決不能を `UnresolvedReference` としてgate失敗に流してください。保証外構文も利用側gateの主張と一致させます。

- [Warning] `Artisan::all()` のvendor command全件と、application-defined commandだけを同時に母集団とする記述が矛盾しています。  
  修正案: I14の責務対象をapplication-defined entry pointに限定するのか、vendorまで分類するのかを明示してください。

## 施策10: REQUEST_CHANGES

- [Critical] 維持する可能性が高い `projects.*` 等のroute名を「撤去したroute名」として検出すると、正しい実装まで失敗します。  
  修正案: 施策5でroute名方針を確定し、旧パス検出と撤去route名検出を別の台帳にしてください。

- [Warning] 文書を例外化する場合、ファイル数だけをpinすると同じファイル内の旧URL増加を検出できません。  
  修正案: ファイルごとの一致件数と対象パターンを完全一致でpinしてください。

- [Warning] `/app` は入口として残り、`/organizations/{slug}/app/...` も新URLとして残るため、単純な部分文字列検査では誤検出します。  
  修正案: URL segmentの区切り集合を明記し、入口 `/app`、新capture URL、旧capture URLの正負例を分けてください。

## 施策11: REQUEST_CHANGES

- [Critical] D41は別featureの未確定な機能後退を本追従設計へ取り込む登録です。乖離台帳へ書けば裁定済みになるわけではありません。  
  修正案: D41を本設計から外し、auth/account-deletion側で決定後に別途登録してください。D40だけを追加するなら、提示された基準値上は件数増分も `+1` として再計算が必要です。

- [Warning] `LedgerPins` の数値は最終的なD41の扱いと実ファイル内容に依存します。  
  修正案: 固定値を先に断定せず、最終エントリ集合・adoption debt削除後の実数から更新してください。

## 横断的な必須修正

特に次の4点は着手前に設計を直す必要があります。

1. `{organization:slug}` のURL生成では、モデルではなく slug を名前付き引数で渡す。
2. 施策5〜8をAG-037違反の中間状態が残らない原子的変更単位にする。
3. 日本語名・予約語・導出不能時の登録slug入力フローを完成させる。
4. PostgreSQLの並行テスト方式をRefreshDatabaseと両立する形で具体化する。

全体判定: **CHANGES_REQUESTED**