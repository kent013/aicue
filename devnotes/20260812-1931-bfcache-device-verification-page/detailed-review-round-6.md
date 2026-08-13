## 全体判定: APPROVED

Round 5 の承認条件3点はすべて閉じています。状態遷移、総合判定、UI規約の間に実装を阻害する矛盾は残っていません。実装可能な水準です。

### 施策1: APPROVE

- `in-progress` と終端 verdict が分離され、正常な遷移 prefix で観測が停止しない。
- `in-progress` / `not-observed` は総合判定で `undetermined`、`failed-transition` のみ `fail` となっている。
- `AwayNavigationStartedEvent` は操作事実に限定され、離脱失敗を推論しない契約へ統一されている。
- 軸1 window、軸2の観測範囲、観測終了の責務分離も維持されている。

### 施策2: APPROVE

`LocalOnly`、`auth`、Basic認証、no-store、Inertia component名を正負のコントロールで固定する計画は妥当です。DTO / JsonResourceの適用対象外という判断にも問題ありません。

### 施策3: APPROVE

ボタンをdisabledにせず、押下時にphaseを検査して理由を表示する設計となり、禁止事項8に適合しています。離脱失敗も手動観測であることが画面設計まで反映されています。

### 施策4: APPROVE

既存logout導線の再利用、Bの責務限定、失効セッション経路の証跡回収手順に問題ありません。

### 施策5: APPROVE

旧真理値表の矛盾は解消されています。

- guard未観測、`pending`、`pending → verifying`は`in-progress`
- 中止済みかつguard未観測のみ`not-observed`
- 正常prefixでない遷移のみ`failed-transition`
- 逐次追記ごとのverdictとphaseを検証
- 軸3の`in-progress` / `not-observed` / `failed-transition`を個別に固定

最終形だけでなく途中状態もテストするため、listenerの早期停止という回帰も検出できます。

### 施策6: APPROVE

route gateとunload禁止テストの対象範囲は妥当です。`AppLayout`への制約もdebug都合ではなく経路Bの成立条件として説明されています。

### 施策7: APPROVE

検証設備の追加とT085の完了を分離し、manual confirmation、HTTPS、トンネル運用規律を明記する方針に問題ありません。

## 実装時確認事項

以下は設計変更を必要とせず、実装・レビュー時に解決できます。

- `nextSequence()`の初期値を`0`か`1`のどちらかに確定し、テスト期待値と統一する。
- ボタン挙動テストは、Svelte component testを使うか、phase別の追記可否を純粋関数へ切り出してテストする。
- `AwayNavigationFailedEvent`は、少なくとも`trial-started < away-navigation-started < away-navigation-failed`の順序を導出テストで固定する。
- `appendEvent()`のread-backでは、単なるJSON parse成功だけでなく、追記したイベントが末尾に同値で存在することを確認する。
- 施策3・6のリスク欄に残る「`AppLayout`を対象に含めるか要検討」という旧文言を、決定済みの記述へ整理する。
- 実装完了時は、計画済みのPest/Vitestに加え、PHPStan level 10、型検査、lint、buildを含む規定レーンをすべて通す。

Round 5の承認条件は閉じており、詳細設計レビューとして承認します。