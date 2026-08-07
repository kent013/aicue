# 対応マトリクス: design-review Round 2

> Round 1 の [Critical]（施策 4 の `NestedRouteDefenseInventory` 登録）は
> レビュアーが**撤回**した（母集団は 1 param 以上 / `notifications.open` が同形の先例 /
> 検査 3a は inventory 登録を起点にする、という反論を全面的に受理）。

## 施策 3 [Critical] `token_get_all()` の判定方法が成立しない（直前は必ず `->`）
- 判断: **対応する**（指摘のとおり。私の判定手順が誤っていた）
- 根拠: `$this->joinOrganization(` のトークン列は
  `T_VARIABLE($this) / T_OBJECT_OPERATOR / T_STRING(joinOrganization)` なので、
  「直前の有意トークンが `T_RETURN` / `=` / `!`」を要求すると**正しい 3 つの呼び出しが全部 fail** する。
- 対応内容: 判定を「呼び出し式の開始位置まで遡ってから見る」3 段の手順へ書き直した。
  1. `T_STRING(joinOrganization)` かつ次の有意トークンが `(` のものを呼び出しとして拾う
  2. 後方へ `T_OBJECT_OPERATOR` → `T_VARIABLE($this)` の 2 つを越える
     （この形でなければ**未知の呼び出し形として fail** = deny-by-default）
  3. さらに 1 つ前の有意トークンが `;` / `{` / `}`（= **式文として戻り値を破棄**）なら fail
  許可リスト（`&&` `||` `(` `,` …）を列挙せず**破棄形だけを拒否**する形にしたのは、
  値が使われる文脈が無数にあり許可側の列挙は正しい実装を落とすため。
  空振り防止として「拾えた呼び出しが 3 件未満なら fail」を足した。

## 施策 3 [Warning] `DB::beforeExecuting()` の callback は解除できない
- 判断: **対応する**（「後始末」という表現を撤回し、inert 化契約に書き直す）
- 根拠: Laravel に unregister API は無い。`try/finally` で `$fired` を落とす、という説明は
  helper が状態を返していない以上そもそも実現できないうえ、意味的にも逆（落としたら再発火する）。
- 対応内容:
  - **callback 自身が one-shot で恒久的に inert になる**設計として明記
    （`$fired` を立てた後は即 return。自分の UPDATE による再入もこれで止まる）
  - 発火条件を「対象 invitation を含むテーブルへの `for update`」に限定
  - テスト間の漏れについては「Pest は各テストでアプリケーション（= `Connection` インスタンス）を
    再構築するため持ち越されない」を**前提であって保証ではない**と明記し、
    **同一テスト内で後続クエリに干渉しないことを behavioral にアサートするテストを 1 本追加**した
    （helper 適用後に別の招待を普通に受諾できること）

## 施策 7 [Warning] 新 UI → 旧 backend の 422 がデプロイ境界に含まれていない
- 判断: **対応する**
- 根拠: 指摘のとおり。コード先行のローリング更新では assets も同時に更新されるため、
  「新 JS を読んだブラウザが旧 backend へ POST する」向きが必ず生じる。
- 対応内容: デプロイ手順に「新旧 HTTP 契約が混在する時間帯の扱い」表を追加し、**両方向を明記**した。
  - 新 UI → 旧 backend: `organization_member` が `Rule::enum(AdminConsoleRole)` で 422
  - 旧 UI → 新 backend: `editor` / `shooter` が `Rule::enum(OrganizationRole)->except([Owner])` で 422
  - **両方向とも 422 + 同一文言**（既存 `messages()` の
    「ロールの指定が不正です。画面を再読み込みしてやり直してください。」）に収束し、
    500 にもデータ破損にもならない。対象は管理者のみが使う低頻度フォームで回復は再読込 1 手。
  - 採る方式: **既定は原子的切替**（単一インスタンス再起動でコードと assets を同時入替 =
    混在窓が実質存在しない）。複数インスタンスのローリング更新を行う場合は
    **一時的な 422 を明示的に受容**し、運用手順に回復導線と 422 の収束確認を記載する。
    互換のために `AdminConsoleRole` を受け続ける分岐は入れない（思考原則 3。
    それを消す差分がまた必要になる）。
  - どちらの場合も **段階 2（旧プロセス排除）→ 段階 3（migration）の順序は崩さない**
    （422 は回復可能だが、列を先に消したときの「存在しない列への INSERT」は 500 で回復導線が無い）。
