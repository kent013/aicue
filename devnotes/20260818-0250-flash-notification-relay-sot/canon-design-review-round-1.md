仮説は「正典への収束方針と実装形は妥当だが、主要な安全性改善である `new_api_key` の失効を Feature テストが偽陽性なく証明できるか」です。判定基準は、現行 `reflash()` では確実に赤くなり、置換後にのみ緑になることです。

## 施策1: PHPレーンの drift gate

判定: APPROVE

[Suggestion] 「`app/` の flash 書き手をすべて検査」という表現は、既知の動的キーや対応外リテラル形式を考えると少し広すぎます。

`BillingFeedbackKind::FLASH_KEY`、変数経由のキー、`[a-z_]+` 外のキーなどは検査されません。リスク節では正しく限定できているため、検査表も「走査器が対応するリテラル書き手」に揃えると、保証範囲の記述が一貫します。

自己検証、実出力比較、allowlist件数固定を組み合わせた構成は妥当です。

## 施策2: TSレーンの drift gate

判定: APPROVE

PHP定数の定義形が変わった場合に抽出ゼロで失敗させる設計、集合比較、`FLASH_KEYS` を実際の画面側モジュールからimportする構成はいずれも適切です。

この検査がキー集合だけを保証し、値の型や `visitKey` を保証しないことも明記されています。

## 施策3: FlashNotificationRelay

判定: REQUEST_CHANGES

[Warning] `RELAYABLE_ERROR_KEYS = []` による「errorsは中継しない」という現在の安全契約がテスト計画に明示されていません。

`new_api_key` だけでは、「通知以外を延命しない」というクラス全体の境界、特にコメントで詳細に契約化している `ViewErrorBag` の扱いを固定できません。

修正案:

- `errors` にdefault bagと名前付きbagをflashした状態で跳ね返りを通し、bounceレスポンス直後にsessionから失効していることを検証する。
- `errors` が `ViewErrorBag` でない場合も再flashされないことを検証する。
- 少なくとも現在の空allowlistがfail-closedであることをFeatureテストへ登録する。

将来allowlistへキーを追加した際には、許可キーだけ残り、それ以外と名前付きbagが残らない正例・負例を同じ変更で追加する契約も記載すると安全です。

クラス本体のLaravel Session API、PHPStan上の型、`MessageBag`再構築には明確な問題はありません。

## 施策4: 共有propをSoTから導出

判定: APPROVE

Inertia共有propとして配列を使うのは適切で、JsonResource/DTOの対象ではありません。`visitKey` を通知語彙から分離する判断も妥当です。

`array<string, mixed>` と画面側の `string | null` には実行時型の隔たりがありますが、既存挙動を変えない正典追従として範囲が明確化されているため、本タスクの阻害事項とはしません。

## 施策5: FLASH_KEYSのexport

判定: APPROVE

挙動を変えずにdrift gateから実際の読み手を参照可能にする最小変更です。Atomic Design、DESIGN.md、Inertia/API境界への影響もありません。

## 施策6: reflashから中継への置換

判定: REQUEST_CHANGES

[Critical] `new_api_key` の検証方法が不十分なため、本施策の中心的なセキュリティ改善が偽陽性になる可能性があります。

課金ゲートの着地画面は通常 `new_api_key` をInertia propとして公開しません。そのため「着地画面にpropがない」だけを検査すると、現行 `reflash()` によってsession内に平文が残っていてもテストが通ります。

また、単なる `withSession(['success' => ..., 'new_api_key' => ...])` はLaravelのflash世代情報を作りません。通常session値を入れただけでは、`keep()`・`reflash()`・リクエスト終了時の失効を正しく検証できません。

修正案として、テスト手順を次のように固定してください。

1. 実在する上流操作、またはテスト専用のweb routeで `flash()` / `redirect()->with()` を実行し、実際のリクエスト境界を跨いでflashを作る。
2. 次の要求で対象middlewareの跳ね返りを発生させる。
3. そのbounceレスポンス直後に、sessionへ `success` が残り、`new_api_key` が存在しないことを直接assertする。
4. 着地GETで `flash.success` がInertia共有propに載ることをassertする。
5. 着地GETの終了後、sessionから `success` が失効したことをassertする。追加要求を使う場合は、再びrelayを呼ぶrouteを使わない。
6. この一連を両middlewareについて検証する。

この形なら、`new_api_key` の検査は現行 `reflash()` で確実に赤くなり、`relayTo()` への置換後にのみ緑になります。

[Warning] 「通知を延命する」という契約に対し、振る舞い検査が `success` だけです。

修正案として、4通知キーをデータセットで検査するか、少なくとも「4キーすべてを `keep()` することは定数と実装構造で一意に決まるため、代表値としてsuccessを検証する」とテストの保証範囲を明記してください。後者でも正典同形を優先する判断として成立します。

## 全体判定

CHANGES_REQUESTED

正典との照合、実装形、Inertia/DTOの境界、PHPStan対応、drift gateの設計方向は承認できます。変更要求の中心は実装方式ではなくテストの観測点です。

特に `new_api_key` は「着地画面のprop」ではなく「bounce直後のsession」を検査し、正しいLaravel flashライフサイクルで事前状態を作る必要があります。ここを詳細設計に固定すれば、今回の変更が意図する「平文を余分な1 hop残さない」を回帰テストとして正確に証明できます。