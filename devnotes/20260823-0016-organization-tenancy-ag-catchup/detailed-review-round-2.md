## 総評

Round 1の主要な方向性は概ね反映されています。特に、単位B、route名維持、名前付きslug引数、並行性の保証範囲、施策9の入口/解決点分離は大きく改善しました。

ただし、slug衝突時の再試行、改名上限の境界、通知fan-out、入口ControllerのDI、施策9の再帰関係、旧URL走査に実装上の穴が残っています。

全体判定: **CHANGES_REQUESTED**

---

## 指定された5論点の判定

1. 変更単位の原子性: **一部不足**

   単位Bは十分です。一方、単位Aで `current_organization_id` への書き込みまで先行撤去する設計は、単位B前の現行URL方式を壊します。

2. 識別名の入力フロー: **不足**

   利用者入力・導出・fallbackの区別は改善しましたが、導出値の一意衝突時に同じ導出値を3回繰り返す可能性と、PostgreSQLの失敗済みtransaction内で再試行できない問題が残ります。

3. route名維持＋名前付き引数: **概ね充足**

   方針は妥当です。静的gateが動的route名とPHP/TS双方をどう扱うかだけ具体化が必要です。

4. 並行性の検証: **APPROVE**

   D7という既存裁定に沿い、実測していない保証を明示的に主張しない設計になっています。I4の構造契約をArchitectureテストで固定する方針として妥当です。

5. 施策9の2層型: **不足**

   入口と解決点の分離は正しいですが、`RelationScoped` の多段連鎖、循環、重複IDをまだ完全に表現・検証できません。

---

## 施策1: REQUEST_CHANGES

- [Warning] migrationのコメントが「構文と予約語の両方を検査」としていますが、実装上の予約語検査は施策2の別migrationです。責務が矛盾しています。  
  修正案: `000100` は正規化・構文・衝突だけ、`000200` は予約語だけと明確に分離してください。

- [Warning] CHECK制約の `down()` が設計されていません。  
  修正案: `organizations_slug_syntax` を名前で削除する逆migrationを明記してください。

- [Warning] 制約テストの直接 `insert()` は「テストデータはFactoryで生成」に抵触し、他の必須列制約が先に発火する可能性があります。  
  修正案: Factoryで正常な組織を作り、`DB::table()->whereKey()->update(['slug' => ...])` でCHECKを迂回する負例にしてください。

値オブジェクト、255文字上限、正規表現、既存UNIQUEの再利用は妥当です。

## 施策2: REQUEST_CHANGES

- [Critical] 初版導入の検査は追加されましたが、将来予約語を追加するときに既存データ検査を同時追加する義務が、修正後全文から消えています。configへ語を足すだけで既存組織が予約語状態になります。  
  修正案: 「予約語configの変更には、既存slug衝突を検査する新migrationまたは同等のデプロイ検査が必須」をconfigのdocblockとガイドへ戻してください。負例付きgateでこの運用契約を固定できるなら併設します。

- [Warning] migrationが現在のアプリクラスと可変configを直接読むため、過去migrationの意味が将来のconfig変更で変わります。  
  修正案: 初版予約語のスナップショットをmigration内に固定するか、予約語検査用の不変なデータをmigrationへ渡す方式にしてください。少なくとも「現在のconfigを読むことを意図した可変migration」であるなら、その再実行時の意味を明記する必要があります。

## 施策3: REQUEST_CHANGES

- [Critical] 30日前ちょうどの履歴を `>= cutoff` で数える一方、`nextAvailableAt = oldest + 30日` としています。その時刻ちょうどでは履歴がまだ窓内なので、実際には改名できません。  
  修正案: 窓を `renamed_at > cutoff` として `oldest + 30日` で利用可能にするか、包含境界を維持するなら「その時刻を過ぎた後」と表現できる時刻・判定へ統一してください。

- [Critical] 変更系PATCHに対する認可が修正後全文から抜けています。bindingによる404だけでは、same-orgの一般メンバーによる改名を防げません。  
  修正案: Controllerで `Gate::authorize('update', $organization)` を実行し、`ControllerAuthorizationGateTest` のinventory登録と、一般メンバー403・cross-org404のFeatureテストを追加してください。

- [Critical] `InvalidOrganizationSlugException`、予約語例外、一意衝突が「422になる」とされていますが、どの層が `ValidationException` へ変換するか未設計です。通常のdomain例外はそのままでは500です。  
  修正案: FormRequestのカスタムルールで構文・予約語を検査し、競合一意制約だけをService外側で `ValidationException::withMessages()` へ変換するなど、例外変換点を一本化してください。

- [Warning] `$used->count() >= LIMIT` でもPHPStanは `$used->first()` の非null性を推論しません。nullsafeでnullableを渡すと例外型の契約が弱くなります。  
  修正案: `Assert::isInstanceOf($oldest, OrganizationSlugRename::class)` 等で非nullに絞ってから次回時刻を生成してください。

## 施策4: REQUEST_CHANGES

- [Critical] 導出slugが使用済みの場合の遷移が閉じていません。`requestedSlug === null` のまま再試行すると、毎回同じ導出結果を生成して3回とも同じUNIQUE違反になる可能性があります。  
  修正案: 候補の由来を `Requested / Derived / Fallback` の型で保持し、次の遷移を明示してください。

  - Requestedの衝突: 即422
  - Derivedの衝突: fallbackへ1回遷移
  - Fallbackの衝突: 新しい乱数候補で最大3回

- [Critical] PostgreSQLでは一意制約違反後、そのtransactionはrollbackされるまで失敗状態です。同じtransaction内で候補だけ変えて再試行できません。  
  修正案: 各試行をsavepoint付きの内側transactionにし、例外でsavepointをrollbackした後に次候補を試すか、試行全体を外側から再実行してください。Team・Default Team・role付与の途中状態も各失敗試行で確実に巻き戻ることをテストします。

- [Critical] 単位Aの `provision()` から `current_organization_id` 書き込みを消すと、単位B導入前の既存current-org方式で新規登録者の業務画面が動かなくなります。単位A単体が機能的に成立しません。  
  修正案: current列への書き込み撤去は単位Bへ残してください。単位Aでは新slug経路へ切り替えても、現行current方式は維持します。あるいは単位AとBを一つの原子的単位へ統合します。

- [Warning] 単位内部の途中コミットを許す記述は「全greenでコミット」と緊張します。  
  修正案: 内部コミットもテスト・PHPStanがgreenになる構成に限るか、途中状態はコミットせず最終的に単位単位の1コミットにすると明記してください。

並行性の3層検証と保証範囲の説明は妥当です。

## 施策5: APPROVE

route名維持、名前付き引数、業務routeの母集団と理由付き除外台帳は妥当です。

[Suggestion] `OrganizationRouteGenerationTest` では、動的route名、PHPのFQCN解決、TypeScriptのroute helperを同じ抽出器で扱えるとは限りません。言語別の抽出器と未解決台帳に分けると実装しやすくなります。

## 施策6: APPROVE

binding由来の共有prop、middleware順序、生成URLのFeatureテストまで含めて妥当です。

[Suggestion] PHP docblockをSoTとするなら、PHP側キー集合だけでなくTypeScriptのnullable型・数値型・boolean型も比較対象に含めてください。

## 施策7: REQUEST_CHANGES

- [Critical] 「全所属組織へ通知する」は機能後退ではありませんが、account-deletion通知を1件からN件へ変える別featureの仕様変更です。未読件数の増加、同一通知の重複表示、既読状態の分裂、通知保存量の増幅が発生します。「後退でない」ことは他featureを変更してよい根拠になりません。  
  修正案: auth/account-deletion側のオーナー裁定を取得して本設計の確定前提にするか、本件をブロッカーとして分離してください。採用する場合はfan-out上限、既読単位、退会後の通知参照可能性も契約に追加します。

- [Warning] `$user->organizations` の全件fan-outは、所属数に比例して通知行とイベント処理が増えます。  
  修正案: オーナー裁定でfan-outを採る場合、所属数上限またはバルク生成方式、部分失敗時のtransaction境界を定義してください。

current列撤去検査、rate limiterのfail-closed、Filamentの最小変更、メンテナンス手順は妥当です。

## 施策8: REQUEST_CHANGES

- [Critical] 固定route `/app` / `/go` のController引数へ `EntryTarget $target` を直接注入する根拠がありません。Laravelのbacked enum bindingは通常、route parameterに対して働きます。固定routeにparameterがなければContainerはenumを生成できません。  
  修正案: `__invoke(Request $request)` 内で現在のroute名を固定表へ写すか、`/app` と `/go` 用の薄いController/actionを分けて明示的な `EntryTarget` をServiceへ渡してください。

- [Warning] `count()` の後に `sole()` を別queryで実行するため、間でmembershipが変わると0件/複数件例外になります。  
  修正案: membershipsを一度だけ取得し、その同じCollectionの `count()` / `sole()` / DTO変換を使ってください。

PWA scopeの実測とpin、DTO化、明示slug転送は妥当です。

## 施策9: REQUEST_CHANGES

- [Critical] `RelationScoped` の親を `PrimaryKeyBinding` または `ActorDerived` に限定すると、多段relationを表現できません。一方で説明は「再帰的provenance」としており矛盾します。  
  修正案: `RelationScoped` の親として別の `RelationScoped` も許可し、親鎖が最終的に `PrimaryKeyBinding` または `ActorDerived` へ到達することを検証してください。

- [Critical] `parentResolutionId` は循環参照を作れます。`A → B → A` でも「親が存在する」だけの検査なら通ります。  
  修正案: resolution IDの入口内一意性、親の存在、自己参照禁止、循環禁止、最終root到達をgateで検証してください。

- [Warning] `entryPointId` を各解決点にも重複保持すると、inventoryの外側キーとDTO内値が食い違う余地があります。  
  修正案: inventoryのentry keyを唯一のSoTとし、resolution DTOは入口内IDだけを持つか、両者の一致をgateで固定してください。

- [Suggestion] productionコードで使わないprovenance enumなら、`app/Enums` ではなく `tests/Support` 配下へ置く方が責務に合います。

## 施策10: REQUEST_CHANGES

- [Critical] 6文字だけの区切り集合では、走査対象として明記したMarkdownや文書中の `/dashboard)`、`/projects,`、`/billing。`、空白区切り等を検出できません。旧URLが残ってもgreenになる穴です。  
  修正案: ファイル種別ごとに文字列リテラル・Markdownリンク・プレーンURLを抽出するか、空白と一般的な閉じ記号・句読点を含む区切り集合を宣言し、全区切りの負例を置いてください。

- [Critical] `/projects/` だけを旧パスとして扱うと、rootの `/projects` を検出できません。ほかのprefixも同様です。  
  修正案: 旧パス判定を「pathがrootと完全一致、または `root/` で始まる」と定義してください。query/hashを除いた正規化済みpathへ適用します。

- [Warning] `/organizations/acme/app` を「接尾辞つき」の負例と呼ぶのは走査器規約(e)の意味とずれています。これは許可すべき新URLです。  
  修正案: 「検出すべき旧URLの変形」と「誤検出してはいけない新URL」を別の正負例群に分けてください。

## 施策11: APPROVE

D41の取り下げ、D40のみの追加、実数再確認という台帳処理自体は妥当です。

ただし施策7の通知fan-outは、乖離登録の要否とは別に、auth/account-deletion側の裁定が必要です。

---

## 全体判定

**CHANGES_REQUESTED**

承認までの主な停止条件は次の6点です。

1. 単位Aではcurrent列書き込みをまだ撤去しない。
2. slug候補をRequested/Derived/Fallbackで区別し、rollback後に有限再試行する。
3. 改名の30日境界と `nextAvailableAt` を一致させ、認可・422変換点を追加する。
4. account-deletion通知fan-outについて他featureの裁定を得る。
5. `/app`・`/go` の `EntryTarget` 注入を実装可能な形へ直す。
6. 施策9の親鎖と施策10のURL抽出をfail-closedに完成させる。