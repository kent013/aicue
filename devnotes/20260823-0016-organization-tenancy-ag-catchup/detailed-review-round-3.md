## 総評

Round 2の停止条件の大半は適切に解消されています。特にslug候補の由来型、transaction再試行境界、改名認可、施策9の親鎖検証は十分な詳細設計水準です。

ただし、停止条件4は「解消」ではなく外部裁定待ちへ正しく移された状態です。加えて、保存経路gateの自己矛盾、改名時刻の取得位置、施策10の自己検出、施策11の古いfan-out記述が残っています。

全体判定: **CHANGES_REQUESTED**

## 施策1: APPROVE

構文型、DB制約、既存UNIQUEの再利用、migrationの責務分離、Factory経由の制約テストまで妥当です。

## 施策2: REQUEST_CHANGES

- [Critical] `OrganizationSlugWritePathTest` はgit追跡下PHP全数を走査しますが、正規化migrationの直接UPDATEと、`OrganizationSlugConstraintTest` の意図的な直接UPDATEも検出対象です。「AssignableOrganizationSlugを受ける1本以外0件」では自分の境界テストとmigrationが必ず違反します。  
  修正案: migrationとDB制約の自己検査だけを、理由・構文・件数の完全一致を持つ例外inventoryへ登録してください。例外ファイル全体を除外せず、許可した書き込み形以外は引き続き検出します。

- [Warning] 将来予約語追加時のmigration義務を機械強制しない判断は明記されていますが、configを正本としつつガイドにも同じ契約文を置く記述は二重管理になります。  
  修正案: configを正本とし、ガイド側は契約を複写せずconfigの該当箇所を参照する文にしてください。

## 施策3: REQUEST_CHANGES

- [Critical] `$now` を組織行のロック取得前に確定しています。ロック待ちが発生すると、最終権威の回数判定が古い時刻を基準に行われ、既に窓外へ出た履歴を数えて拒否する可能性があります。  
  修正案: `lockForUpdate()` 成功後に `$now = CarbonImmutable::now()` を取得し、その同じ値をcutoffと`renamed_at`に使ってください。

- [Warning] FormRequestで同一slugを検査しても、検証後からロック取得までにslugが変わり得ます。Service側の同値検査を残している点は正しいですが、そのdomain例外もControllerの422変換対象に含める必要があります。  
  修正案: `InvalidOrganizationSlugException::unchanged()` がServiceから発生した場合の変換をController側の表へ追加してください。

それ以外の境界規則、認可、404/403順序、一意制約名の識別は妥当です。

## 施策4: REQUEST_CHANGES

- [Critical] Requested slugの一意衝突は `OrganizationSlugTakenException` へ変換されますが、組織作成Controllerでそれを422へ変換する点が設計されていません。施策3の変換表は改名Controller専用です。  
  修正案: `OrganizationController::store` における競合例外の変換点を明記し、requested衝突は422、derived衝突はfallback、未知のQueryExceptionは再送出するFeatureテストを追加してください。

- [Warning] 「1試行＝1 savepoint」は、`provision()` が外側transactionなしで呼ばれる場合には正確ではありません。その場合は1試行＝トップレベルtransactionです。  
  修正案: 契約を「1試行＝1 transaction境界。外側transaction内ではsavepoint、外側がなければ全transaction rollback」に修正してください。

- [Warning] slug一意違反が発生する`Organization::save()`より後に、DB外へ影響し得るLaratrustのrole cache操作を置くことを固定してください。  
  修正案: `createWith()` の順序契約に「slug付きOrganizationの保存成功後にattach/addRole」を明記し、失敗試行後のDB状態だけでなく権限cacheの残留も検査してください。

単位Aでcurrent書き込みを維持する修正と、候補の遷移モデルは妥当です。

## 施策5: APPROVE

route名維持、言語別gate、動的route名の未解決台帳、名前付きslug引数の契約は妥当です。

## 施策6: APPROVE

binding由来の共有prop、型契約、404順序、生成URLテストは妥当です。

## 施策7: REQUEST_CHANGES

- [Critical] auth/account-deletion側の裁定が未確定なため、施策7の最終的な変更ファイル、通知契約、テスト、乖離扱いが確定していません。これは適切に認識された外部ブロッカーですが、「停止条件を塞いだ」「詳細設計が確定した」とはまだ判定できません。  
  修正案: オーナー裁定を設計の入力として反映し、候補(a)〜(c)のいずれかを具体的な変更・テストへ落としてから再承認してください。

依存として明示し、設計側で勝手に選ばなかった判断自体は正しいです。

## 施策8: REQUEST_CHANGES

- [Warning] `$routeName` は `?string` のままです。`Assert::keyExists(..., (string) $routeName)` はキャストした式だけを検査するため、PHPStanが後続の `$TARGET_BY_ROUTE[$routeName]` を安全なstring keyとして扱えるとは限りません。  
  修正案: 先に `Assert::string($routeName)`、続いて `Assert::keyExists(self::TARGET_BY_ROUTE, $routeName)` としてください。

- [Warning] PWA scopeテストの説明がmanifestだけを検査し、service worker登録時に明示的な狭い`scope`オプションが渡されていないことを固定していません。scriptの配置だけでは登録scopeを完全には決められません。  
  修正案: service worker登録コードのscript URLとscope option、またはBrowserテストで実効的な`registration.scope`が`/`であることを固定してください。

## 施策9: APPROVE

入口分類と解決点分類の分離、ID一意性、親の実在、循環禁止、root到達、未解決のfail-closedまで揃っています。詳細設計として十分です。

## 施策10: REQUEST_CHANGES

- [Critical] gate自身と負例fixtureには `/projects`、`/dashboard`、`organizations.switch` 等の検出語が必ず現れますが、自己検査をどう例外化するかがありません。このままではgateが自分自身を検出します。  
  修正案: gate本体・fixture内の既知出現を、ファイル・パターン・件数・理由の完全一致で自己検査inventoryへ登録してください。ファイル全体の走査除外は避けます。

- [Critical] 実装モードでは「施策10はauth裁定の影響を受けず先行できる」としていますが、施策10は単位B完了後の旧URLゼロを検査するgateです。単位B前にgreenで導入することはできません。  
  修正案: 先行可能なのは単位Aと施策9までとし、施策10は単位B完了後に移してください。抽出器だけ先に作る場合も、mainへmergeする単位はB後です。

- [Warning] MarkdownのプレーンURL終端集合には、タブ、`}`、コロン等の一般的な境界がまだ含まれません。  
  修正案: 網羅を主張するなら空白文字全般と対象文書で現れる閉じ記号を負例で固定するか、保証範囲を列挙した終端集合に限定して明記してください。

## 施策11: REQUEST_CHANGES

- [Critical] 「D41は作らない」の理由が、取り下げた旧方針である「全組織へ配ることで後退させない」のままです。施策7の現行設計は未裁定であり、本文内で矛盾しています。  
  修正案: D41の要否はauth裁定後に再判定すると書き換えてください。候補(a)〜(c)が共有ファイルやテンプレート不変条件へ影響する場合は、その結果に応じて乖離台帳と件数を確定します。

- [Warning] このため `DIVERGENCE_ENTRY_COUNT = 37` は現時点ではD40だけを前提とした暫定値です。  
  修正案: auth裁定の結果に依存する未確定値として扱い、最終実数確認を施策7完了後の順序契約にしてください。

## 全体判定

**CHANGES_REQUESTED**

Round 2の停止条件6点のうち、1・2・3・5・6は実質的に解消されています。4は適切に外部裁定待ちへ分離されましたが、まだ解消済みではありません。

再承認までの主な条件は次のとおりです。

1. auth/account-deletion側の裁定を施策7・11へ反映する。
2. slug書き込みgateにmigration・境界テストのexact-fit例外を設ける。
3. 改名の基準時刻を行ロック後に取得する。
4. 組織作成時のrequested slug競合を422へ変換する。
5. 施策10の自己検出と実装順序を修正する。