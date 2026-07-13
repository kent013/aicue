# 対応マトリクス: impl-review Round 1

Codex 全体判定: **APPROVED**（Critical なし）。以下は Warning / Suggestion への対応判断。

## [Warning] onSuccess で roleRefocusMemberId を null に戻していない (Users.svelte)
- 判断: 対応する
- 根拠: 設計文の「成功時はフォーカス復帰対象を残さない」と実装を揃えるべき。コールバック順変更・分岐追加時の保守リスクを断つ。コストは 1 行で低い。
- 対応内容: `onSuccess` で `roleRefocusMemberId = null;` を追加。

## [Warning] テストケース名とロール遷移の記述ズレ (AdminUsers.test.ts)
- 判断: 見送る
- 根拠: 挙動検証は成立している。fixture の id=2 は roleState=editor で、拒否ケースは「editor→admin へ変更→拒否→editor へ復帰」。ケース名「対象行 Select が権威値へ戻る」は権威値=editor を指しており矛盾しない。可読性コメントは既にコード内に付与済み。過度な改名は差分を膨らませる。

## [Warning] InertiaVisitOptions.onError を引数なしで定義 (AdminUsers.test.ts)
- 判断: 見送る
- 根拠: テスト側は options のコールバックを「発火する」だけで引数を渡さない。実シグネチャ (`(errors) => void`) に寄せても本テストの検証内容は変わらず、型の忠実度より最小限の検証用型定義を優先する (SettingsSecurity.test.ts の InertiaVisitOptions と同一方針)。

## [Suggestion] getElementById 化 / 行単位 in-flight / 非失敗行の toHaveValue 追加
- 判断: 見送る
- 根拠: いずれも今回スコープ外の将来改善。`data-testid` は既に安定配線済みで atom 改造を避けるトレードオフとして設計採択済み。行単位 in-flight は設計で「全行 disabled で DOM 乖離を排除」と明示採択。非失敗行の追加 assertion は invalid/エラー非表示で既に担保。

## 再検証
- 上記 [Warning] 対応 (1 行) は既存 6 ケースの挙動を変えない (成功パスのフォーカス残渣クリアのみ)。AdminUsers.test.ts / 型 / lint を再実行して green を確認する。
