全体判定: **CHANGES_REQUESTED**

Round 1 の指摘は適切に反映されており、W7 の反論も受け入れます。ただし、今回追加された `UnknownApiErrorException` の扱いと運用契約が正面から矛盾しています。ここは実装前に解消が必要です。

## 1. 使命との整合性

[Suggestion] 使命への貢献の表現は妥当です。

「撮影停止時間を短縮する」から「決済障害の一次切り分け」に限定したことで、直接効果と間接効果が区別されました。North Star への貢献は間接的ですが、課金基盤の運用改善として正当なスコープです。

## 2. 禁止事項違反

[Suggestion] W1、W2 は解消されています。

思考原則 3 への修正、成功条件の件数修正ともに問題ありません。テスト、PHPStan、DB操作、HTTP応答、LLM呼び出しに関する禁止事項への抵触もありません。

## 3. 実現可能性

[Critical] `UnknownApiErrorException → unknown` と、`unknown` の運用契約が矛盾しています。

現在の設計では次の状態になります。

- `UnknownApiErrorException` は写像表に登録済み
- 分類結果は `unknown`
- 運用契約は `failure_class=unknown` を「分類器の欠陥」として検知
- 初動は「`error_class` を写像表へ追加」
- しかし、そのクラスは既に写像表に存在する

したがって、既知の「分類不能」と未登録例外を、現在の2キーでは区別できません。

修正案は、以下のいずれかです。

- 推奨: `unknown` を「行動分類不能」と定義し直し、初動を「原因調査後、既存分類への変更・新分類追加・明示的 unknown 維持を判断する」にする。`unknown` を一律に分類器の欠陥とは呼ばない。
- または、context に `classification_status: mapped|unmapped` を追加し、未登録だけを分類器の欠陥として通知する。
- または、`provider_unknown` を追加して、SDK が明示する未知エラーと未登録例外を分離する。

case を増やさない方針を維持するなら、1案目が最小です。ただし、明示的 `unknown` が継続発生した場合の owner と調査導線は必要です。

[Warning] `QueryException → local_infrastructure` は運用行動を誤らせる可能性があります。

`QueryException` は接続障害だけでなく、SQL不備、制約違反、データ不整合も包みます。クラスだけでは「自インフラ障害」と判定できません。

修正案:

- `local_infrastructure` を `local_failure` に変更し、「DB/cache 層を調べる。インフラ障害とは限らない」とする。
- または `QueryException` を `unknown` に置き、接続障害だけを分類できる別の根拠がある場合に限定して `local_infrastructure` とする。

現在の「行動で切る」という設計なら、前者が実用的です。

[Warning] vendor 21クラスの写像は、クラス名だけで確定せず、実際の throw 条件を詳細設計で固定する必要があります。

特に以下はバージョンごとの実装・生成条件を確認すべきです。

- `Cashier\InvalidPaymentMethod`
- `Cashier\SubscriptionUpdateFailure`
- `Stripe\TemporarySessionExpiredException`
- `Stripe\CardException`

修正案として、各 entry の根拠を公式仕様または vendor の throw site に結び付けることを詳細設計の受入条件にしてください。表自体の方向性は妥当です。

## 4. 期待効果の妥当性

[Warning] `provider_rejected` は「一次切り分けが決まる」という主張に対して、少し広すぎます。

同じ case に以下が混在しています。

- コード修正: 不正パラメータ、冪等キー衝突
- 運用設定修正: APIキー、権限、署名secret
- 利用者操作: SCA、カード対応、再認証

ただし、今回の分類は制御フローに使わず、カード拒否には既存 typed result があるため、`user_action_required` を追加する必要まではありません。case を分けない判断を支持します。

修正案は、効果の表現を「再送で収束するか否かの一次切り分けが決まる」と限定することです。「待つ / 直す / 調べる」のうち、`provider_rejected` 内の誰が直すかまでは決まりません。

## 5. リスク

[Warning] `unknown` の例外判断を「写像表のコメント」に残すだけでは、運用上の完了状態を機械判定できません。

コメントでは、同じ例外が再発するたびに未対応なのか受容済みなのか判別できません。

修正案:

- 明示的 `unknown` は写像表への登録自体を受容記録とする。
- 未登録 `unknown` と区別する場合は `classification_status` を持たせる。
- docs には owner、調査期限、明示的 unknown を再評価する条件を記載する。

[Suggestion] 伝播する3 jobについて、例外メッセージが `failed_jobs` 等へ残ることを免除根拠へ書かせる修正は妥当です。保証範囲の誇張が解消されています。

## 6. スコープの適切さ

[Suggestion] W7 の反論を受け入れます。

この規約は単なるログ書式ではなく、「gateway 注入クラスを観測または伝播へ全件分類する」という deny-by-default の不変条件です。既存のドメイン固有規約と同じ性質を持ちます。

`AGENTS.md` は1項目・数行に限定し、写像と運用詳細を `docs/architecture.md` に置く構成で適切です。

## 7. 型安全性

[Warning] PHPDoc の型を `Throwable` に絞ってください。

現在の `class-string` はPHPStan上では広すぎます。

```php
/** @return array{
 *     failure_class: string,
 *     error_class: class-string<Throwable>
 * }
 */
public static function context(Throwable $throwable): array;

/** @return array<class-string<Throwable>, GatewayFailureClass> */
public static function map(): array;
```

さらに `failure_class` は enum の `value` を返すため文字列型になる、という実装契約を詳細設計で明示してください。内部利用でenumを保持できる場所では `GatewayFailureClass` のまま扱い、ログ境界でのみ `->value` にするのが安全です。

## 確認事項への回答

1. 24 entry の主な問題は `QueryException → local_infrastructure` です。それ以外は方向性として妥当ですが、Cashier例外などは実際の throw 条件による裏付けが必要です。
2. `user_action_required` は現時点では追加不要です。既存typed語彙との二重管理を避ける判断を支持します。ただし `provider_rejected` の効果は「再送では収束しない」までに限定してください。
3. W7 の反論は受け入れます。`AGENTS.md` 追記は妥当です。

`unknown` の意味と運用契約の矛盾、`QueryException` の分類、PHPDocの型を直せば、概念設計として承認可能です。