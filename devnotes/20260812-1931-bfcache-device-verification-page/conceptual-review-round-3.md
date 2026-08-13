全体判定: **CHANGES_REQUESTED**

二軸 + 総合判定への再設計は妥当で、概念設計は詳細設計へ進める直前まで来ています。残る概念レベルの穴は、未完了試行の分類、シナリオ不一致の扱い、証跡レコードの更新モデルです。

## 1. 使命との整合性

[Suggestion] 使命への接続は適切です。

撮影 PWA の安全性を支える検証設備として範囲が限定されており、North Star に対する効果を誇張していません。

## 2. 禁止事項違反

[Suggestion] 現時点で明確な禁止事項違反はありません。

新規 JSON endpoint、独自 logout 導線、検証対象の改変を避け、Architecture テストまで実装範囲に含めています。テストなしでの実装完了報告を避ける設計になっています。

## 3. 実現可能性

[Critical] 軸 1 の4分類では「未完了」を表現できません。

次の状態は `invalid-not-bfcache`、`invalid-wrong-route`、`inconsistent` のいずれでもありません。

- A から離脱したが、まだ復帰していない
- B やログイン画面で操作を中断した
- Safari が A の履歴項目を破棄した
- A 以外へ戻った後、A のコードが一度も実行されていない
- `pagehide` の記録だけがあり、対応する `pageshow` がない

これらを `inconsistent` にすると、観測値の矛盾と単なる未完了が混ざります。また「A 以外へ復帰」は、A の JavaScriptが動かなければ A 自身には判定できません。

修正提案:

軸 1 に `incomplete` を追加してください。

| 判定 | 意味 |
|---|---|
| `incomplete` | 試行開始済みだが、判定可能な離脱・復帰の組がまだ揃っていない |
| `invalid-wrong-route` | A が実際に再表示されたものの、対応する lifecycle がなく経路 C 等と判定できた場合 |

A 以外へ復帰した事実を B 側で記録できる場合は `invalid-wrong-route` にできますが、観測できない場合は `incomplete` のままにするのが正確です。タイムアウトだけで自動的に失敗へ変換せず、利用者が「試行を中止」と確定する設計が適切です。

[Warning] `pagehide.persisted` の扱いで「原則」が判定規則に残っています。

機械判定では曖昧にできません。`pageshow.persisted=true`、token 同一である一方、`pagehide.persisted=false` なら、現在の定義どおり `inconsistent` とするのかを明記してください。

修正提案:

`valid-bfcache` は次のすべてを必須にします。

- `pagehide.persisted === true`
- `pageshow.persisted === true`
- 離脱前後の full token が同一
- 同一 trial ID、同一 A route
- 離脱イベントと復帰イベントの順序が正しい

「原則」は説明文にだけ残し、判定条件から外してください。

## 4. 期待効果の妥当性

[Warning] 「T085 の項目が全部画面に出る」「書き写し誤りが原理的に消える」はまだ成立しません。

iOS の UA から得られる OS バージョンは、必ずしも受入記録に必要な端末名・正確な OS バージョンと一致しません。UA reduction、iPadOS の desktop-class UA、standalone と Safari の差もあります。

修正提案:

- 自動取得値は「UA reported OS」と明記する。
- 端末モデルと確認済み OS バージョンは、試行開始時の入力または証跡作成時の確認項目にする。
- 自動取得できない値を推測しない。
- 手入力値と自動観測値を証跡上で区別する。

したがって期待効果は「イベント観測値の書き写しをなくす」「環境情報の転記を減らす」までに修正するのが正確です。

## 5. リスク

[Warning] 「試行 ID ごとの immutable record」と「イベントの都度追記」が矛盾しています。

同じ record にイベントを追記するなら、その record は immutable ではありません。実装時に配列全体の read-modify-writeを行うと、イベント競合や途中状態の破損も起こりえます。

修正提案:

次のどちらかに用語とモデルを統一してください。

- append-only event log: 各イベントは immutable、試行レポートはイベント列から導出
- mutable trial record: 状態遷移と更新可能フィールドを明示

この用途では前者が自然です。最終 verdict もイベントとして追記し、stored report は検証済みイベント列から再構成します。sessionStorage への物理的な配列再保存は実装詳細として許容できますが、論理モデルは append-only とします。

[Warning] sessionStorage の保存失敗が判定モデルにありません。

Safari の容量制限、private browsing、壊れた既存値、`setItem()` の例外が起きた場合、画面内では guard を観測できても `/login` 後に証跡を回収できません。

修正提案:

- 保存成功を試行成立とは別の必須前提として持つ。
- 保存失敗時は試行開始または継続を明示的に `unrecordable` とする。
- storage 書き込みは例外を捕捉し、黙って live 表示だけで続行しない。
- 書き込み後に read-back validation を行うかは詳細設計で決めて構いません。

## 6. スコープの適切さ

[Warning] 「ログイン維持シナリオ」の追加は、T085 の目的に対してスコープが広がっています。

T085 がログアウト後の PII 非露出確認なら、必須シナリオは logout-after-leave です。ログイン維持復元は有用な正のコントロールになり得ますが、単なる追加機能として混ぜると操作と判定が増えます。

修正提案:

- ログアウト後復元を本試行とする。
- ログイン維持復元を設けるなら「guard が正常に解除できることを確認する正のコントロール」と役割を明記する。
- 正のコントロールを T085 完了の必須条件にするか、診断用任意試行にするかを概念設計で決める。

シナリオを利用者に宣言させる判断自体は妥当です。ただし宣言と実際の操作がずれた場合は、guard 故障ではなく `scenario-mismatch` として総合 `FAIL` または無効に分類すべきです。例えば、logout を宣言して `authenticated-unhidden` になった場合、それだけでは guard 故障か未ログアウトかを区別できません。

## 7. 型安全性

[Warning] union に保存・試行終了を表す型が不足しています。

現状の型では、`incomplete`、保存失敗、手動中止、stored report 確定を安全に表しにくくなります。

修正提案:

```ts
type ProbeEvent =
    | TrialStarted
    | PageHide
    | PageShow
    | GuardStateChanged
    | StorageFailed
    | TrialAborted
    | TrialVerdict
    | GuardVerdict;
```

`TrialVerdict` と `GuardVerdict` を別イベントにするなら、総合判定を表す `OverallVerdict` も型として持つか、両 verdict から純粋関数で導出すると明記してください。後者の方が判定の二重保持による不整合を避けられます。

## 結論

専用 env フラグ不要、full-document navigation、stored/live 分離、二軸判定という主要方針は承認できます。

詳細設計へ進む前に、概念設計上で次の4点だけ確定させる必要があります。

1. 軸 1 に `incomplete` を追加する  
2. シナリオ不一致と正のコントロールの位置づけを定義する  
3. 保存モデルを append-only event log として整合させる  
4. storage 保存不能時を明示的な検証不能状態にする  

これらは実装詳細ではなく、何を PASS と認めるかに関わるため、概念設計で閉じるべき事項です。