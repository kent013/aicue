# 全体判定: CHANGES_REQUESTED

Round 1 の指摘は概ね適切に解消されている。特に初回パスワード設定の安全条件、transaction 境界、logout 履歴破棄契約、`settingsUrl` 削除理由は十分に明文化された。

ただし、施策1の中心である「契約・型・gate による踏破可能性保証」に、まだ2つの抜けがある。

## 1. 使命との整合性

[Suggestion] パスキー／SSO 主体の利用者が機微操作で詰まる問題の解消は、「思考ゼロ」と現場スマホ利用の前提を守る改善であり、使命との整合性は高い。

F-1とF-2を同じ「画面内で実行可能な手段があるか」という契約として扱う整理も妥当。

## 2. 禁止事項違反

[Suggestion] 明示された設計上、禁止事項への抵触はない。

- DTO → JsonResource を維持
- POST応答で`redirect()->intended()`を使用しない
- ボタンをdisabledにしない
- Feature／Architecture／JSテストを追加
- PHPStanのwidenやbaseline化を行わない

`settingsUrl`削除に対する反論も、内部XHR専用かつ消費者ゼロという前提なら妥当。

## 3. 実現可能性

[Warning] `PasswordCredentialService::apply()`とtransactionの関係をもう一段固定する必要がある。

`setInitial()`はlockを伴うtransaction必須だが、`change()`まで同じ`apply()`を使う場合、`apply()`自身がtransactionを開始するのか、呼び出し元のtransaction内でだけ動くのかが曖昧である。

修正提案:

- `setInitial()`と`change()`をtransaction境界とする
- `apply()`は「transaction内でのみ呼ばれるprivate処理」と明記する
- 初回設定ではlock取得後のUserインスタンスを`apply()`へ渡す
- 現在セッションを除外してDB session行を削除することを明記する

Laravel 12、Svelte 5、Inertia.jsでの実装自体は可能。

## 4. 期待効果の妥当性

[Critical] `fetchRecentAuthStatus()`が欠損フィールドを既定値で埋める設計は、今回の回帰を通信境界で再発させ得る。

例えばResourceから`passkeyAvailable`が欠落しても`false`へ補完されれば、TypeScript上は正常な`RecentAuthStatus`となり、call-site gateも通過する。その結果、今回と同じ「能力はあるがボタンが出ない」が再発する。

修正提案:

- 必須フィールドの欠損を既定値で黙って補完しない
- レスポンスを明示的に検証し、欠損・型不一致は契約エラーとして扱う
- `RecentAuthStatusResource`の全フィールドとTS側shapeの対応を固定するResource／JS contractテストを追加する
- 後方互換目的の補完が必要なら、少なくとも認証手段に関するフィールドはfail-closedではなく「契約不成立」として回復Alertを表示する

これを解消しない限り、「配線漏れ型の回帰をCIで機械検出」という期待効果はcall-site内部にしか成立しない。

## 5. リスク

[Critical] `status: RecentAuthStatus | null`によって、必須prop化だけではモーダル表示時の踏破可能性が保証されない。

inventory gateは値の出所を固定するが、`onStale`完了前や取得失敗時に`status === null`のままモーダルが開く時間的状態は検査できない。

修正提案:

- モーダルを開ける条件を「status取得完了後」に固定する
- `null`時は空表示や誤った非対応文言ではなく、明示的なloadingまたは取得失敗Alertと回復導線を表示する
- JSテストに「初期null」「取得失敗」「取得中の連打」「取得後に利用可能手段を表示」を追加する
- 可能ならpropを非nullableにし、loading／failureは呼び出し側が処理する

[Warning] `recent-auth`未成立時の「XHRは409相当」は仕様として曖昧。

修正提案: 実際のHTTP status、DTO／Resource shape、`withRecentAuth`が再試行する条件を受入条件に明記し、Featureテストで固定する。

## 6. スコープの適切さ

[Suggestion] F-4／F-7を切り離し可能としたことで、スコープは適切になった。

実装時にはF-1～F-3を完了条件とし、F-4／F-7がテストや状態管理を大きく拡張する場合は別サイクルへ分離する判断でよい。

## 7. 型安全性

[Critical] call-siteの識別子固定は配線安全性を高めるが、サーバ・クライアント間のshape一致までは保証しない。

特に「欠損を既定値で埋める」処理がPHP DTOとTypeScript型の不一致を隠している。

修正提案:

- `RecentAuthStatusDto`と`RecentAuthProviderDto`の全プロパティを非nullable型で定義
- Resourceのarray shapeをPHPStanで固定
- JS側はunknownレスポンスを検証してから`RecentAuthStatus`へ変換
- Resource contractテストでキー、型、provider構造を固定
- `AvailableReauthProvider`へのdiscriminated union追加は不要。「掲載済み＝step-up satisfier」という反論は妥当

上記2つのCritical、特に「既定値補完による契約違反の隠蔽」と「nullable statusの時間的状態」を設計へ追加すれば、APPROVEDへ移行可能。