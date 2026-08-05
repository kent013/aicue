# 全体判定: CHANGES_REQUESTED

Round 2の主要指摘は解消されており、nullable維持の判断も妥当。ただし、通信契約の説明に相互矛盾が残っているため、このままでは実装判断が分岐する。

## 1. 使命との整合性

[Suggestion] 問題なし。取得失敗時にも空表示を作らず、コンポーネントを全域関数として扱う方針は「無言の行き止まりを作らない」という使命に整合する。

## 2. 禁止事項違反

[Suggestion] DTO／JsonResource、テスト、常時活性ボタン、Service内transactionの各規約に準拠している。禁止事項違反は確認できない。

## 3. 実現可能性

[Warning] strict parse失敗後のdelegated経路について、HTTP遷移の説明が矛盾している。

施策1-bでは「全画面confirmへ302」としている一方、施策3の受入条件ではInertia POSTは`409 + RecentAuthRequiredResource`としている。strict parse失敗時にはprecheckを通過しないため、後続POSTが409を受けた後に誰が`redirect`へ遷移するかを固定する必要がある。

修正提案:

- delegated経路を「POST → 409 Resource → クライアントが`redirect`へInertia visit」のように実装事実どおり記述する
- 302になる経路との区別を明記する
- malformed statusからconfirm画面へ到達する一連のJSテストを追加する

## 4. 期待効果の妥当性

[Warning] 施策1冒頭に旧仕様が残っている。

> `fetchRecentAuthStatus()` が欠損を既定値で埋めて型を確定させる。本批で contract 自体は変えない。

これは施策1-bの「既定値補完をやめてstrict parseにする」と直接矛盾する。

修正提案: 当該記述を「strict parseにより検証済みの値だけを`RecentAuthStatus`として返す。詳細は施策1-b」に置き換える。

この矛盾を除けば、主張する改善効果は合理的。

## 5. リスク

[Suggestion] nullable維持への反論は成立している。`bind:open`を維持したままnullを明示的な失敗状態として描画する方が、6画面で条件付きmountを持たせるより局所的で安全。

再読み込み案内は文言だけでなく、実行可能な再読み込みボタンまたはリンクにすると「回復導線」という名称と一致する。

## 6. スコープの適切さ

[Suggestion] 適切。F-1～F-3を必須、F-4／F-7を切り離し可能とする境界も明確。

## 7. 型安全性

[Warning] JS contractテストはトップレベルだけでなく、`availableProviders`要素の各フィールドについても欠損・型不一致を検査対象にする必要がある。

修正提案:

- top-level全フィールドの欠損・型不一致
- provider要素の`provider`、`capability`、`reauthUrl`の欠損・型不一致
- `availableProviders`が配列でない場合

以上をstrict parseの受入条件に含める。

設計の方向性とnullable方針は承認可能な水準に達している。残る変更要求は、旧記述の削除とdelegated経路の409処理を一意にすること。