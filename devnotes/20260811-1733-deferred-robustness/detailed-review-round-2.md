## 全体判定: CHANGES_REQUESTED

Round 1 の主要な問題は適切に解消されています。特に施策 1a の制約名依存を自然キー判定へ変更した判断、施策 1c の撤回、exclusion 制約の訂正は妥当です。

ただし、検証手順と文書の整合性に修正必須の問題があります。

## 施策 1a: REQUEST_CHANGES

### [Warning] 「同一 attempt」の判定から実行主体が抜けている

現在の判定は以下だけを比較します。

- `organization_id`
- `intent`
- `attempt_token`
- `stripe_session_id`
- `idempotency_key`

一方、挿入しようとした台帳行には `initiated_by_user_id` も含まれます。別ユーザーが同一 organization 内で同じ `attempt_token` を送った場合、既存行をそのユーザー自身の replay とみなして正常終了します。

これは cross-org 漏洩ではありませんが、「書きたかった行が既に在る」「同一 attempt による先行書き込み」と言い切るには条件が不足しています。課金操作の actor 帰属にも関係します。

修正案:

```php
|| $existing->initiated_by_user_id !== $user->getKey()
```

を同一性判定へ追加してください。意図的に actor を問わない契約なら、その理由と保証範囲を明記し、別ユーザーによる同 token のテストを追加する必要があります。

`checkout_url` を比較しない判断は妥当です。Stripe session ID と idempotency key が一致しており、URLを意味論上の識別子にしないという説明も十分です。

### [Warning] M-7 の復帰確認が成立しない

実装後は `app/` に本変更が残るため、mutation だけを戻しても次は空になりません。

```text
git diff --stat app/
```

HEAD が修正前なら、施策 1a、1b、2 の差分が表示されます。

修正案は、mutation 開始直前の状態を基準に比較することです。例えば開始時に patch、blob hash、または `git diff` を保存し、復帰後に同一であることを比較してください。実装を一度コミットしてから mutation する運用なら、その前提を明記すれば `git diff --stat app/` でも成立します。

### [Suggestion] M-2b は mutation から分離した方がよい

M-2b は「実装を壊してテストが赤くなること」の確認ではなく、テストでは識別できない代替実装の比較実験です。実施する価値はありますが、mutation と呼ぶと「全 mutation が適切に kill された」という読み方と衝突します。

E-7 の補足実験、または `alternative-implementation probe` として別節へ移すのが明確です。「3本とも緑なので旧案の誤りはテスト単体では証明できない」という記録自体は妥当です。

## 施策 1b: APPROVE

制約名判定を維持する判断は妥当です。

厳密には ULID や sequence の衝突は数学的に不可能ではありません。しかし、別制約が同時に違反して PostgreSQL が `attempt_ulid_unique` や pkey を報告した場合は再送出され、安全側へ倒れます。

逆に複数違反時に `tar_attempts_org_pending_unique` が報告される可能性も理論上ありますが、通常の生成経路では以下が成立しており、現スコープで自然キー再照合まで導入する具体的必要性はありません。

- ULID は生成時に新規採番
- `stripe_invoice_id` は `NULL`
- pkey はDB採番
- 通常の競合原因は同一 organization の pending 行

ただし、「期待制約以外が同時に違反しえない」という絶対表現は、「通常のアプリ生成経路では同時違反を構成しない」に弱める方が正確です。保証しないもの §3 で構造仮定を明示しているため、施策判定を落とす問題ではありません。

## 施策 1c（撤回）: APPROVE

撤回は妥当です。

現行実装は SQLSTATE だけでなく制約名または対象列を確認しており、別制約を握り潰す実装ではありません。`$e->index` への機械的置換だけなら、今回の目的に対する実質的改善はありません。

複数 unique 違反時に正規 replay が500へ倒れる脆さは残りますが、fail-open ではありません。本設計のスコープ外として明示する扱いは「今必要なものだけ作る」に整合します。

## 施策 2: APPROVE

設計、PHPStan上の型、テスト方法とも妥当です。

`created` listener は `save()` に使われた同一インスタンスを受け取るため、DBから再読込したモデルでは観測できない欠落を正確に検出できます。DB実値の assertion と組み合わせることで、次の非対称も固定できています。

- 修正前: in-memory は `null`、DB は `pending`
- 修正後: 両方 `pending`

`status` を `$fillable` に追加せず `forceFill()` する判断も、保護状態列の規約に合っています。

## 施策 3: REQUEST_CHANGES

### [Warning] 修正後概念設計に exclusion 制約の古い説明が残っている

詳細設計 E-5 と「保証しないもの」では正しく訂正されていますが、修正後概念設計の「保証しないもの」§1には次が残っています。

> pgsql でも exclusion 制約と翻訳ロケールでは `null` になりうる

exclusion 制約は `23P01` であり、`UniqueConstraintViolationException` にならないため、`$index === null` のケースではありません。

修正案:

> pgsql でも unique 違反メッセージをパースできない場合や翻訳ロケールでは `null` になりうる。exclusion 制約は `23P01` の通常の `QueryException` となり、この catch の対象外である。

に統一してください。

### [Warning] 実装規模の記述が自己矛盾している

実装モードに「新 const を伴わない」とありますが、施策 1b は次を追加します。

```php
private const string ATTEMPT_ORG_PENDING_UNIQUE = 'tar_attempts_org_pending_unique';
```

「新しい共有抽象・制約台帳を伴わない」などへ修正してください。

## テスト評価

R-1a の期待結果は整合しています。

- テスト1: 修正前は赤、修正後は自然キー行なしで再送出され緑
- テスト2: 修正前後とも緑。現在のOID順では旧案でも緑
- テスト3: 修正前は赤、修正後は session ID 不一致で再送出され緑

テスト2が旧案を直接排除できないことを明記した点は妥当です。テストで観測できない設計上の依存を、実DBのE-7で補っていることも正しい整理です。

ただし actor 同一性を判定へ加える場合は、対応するテスト4も追加してください。

## DESIGN / Atomic Design

該当なし。UI、Inertia Props、TypeScript、Svelte component階層への変更はありません。

以上の Warning を修正すれば、全体として APPROVED にできます。