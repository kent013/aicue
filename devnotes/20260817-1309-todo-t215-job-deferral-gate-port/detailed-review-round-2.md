## 再レビュー結果

Round 1 の Warning 6 件はすべて適切に解消されています。特に、31/28/3 の件数整理、commit SHA 固定、M1-M12 の期待色明示、逆パッチによる復元、MR-1 の分離は妥当です。

ただし、受け入れ条件に新たに 1 件の fail-open 経路が残っています。

### 施策 1: 検出器・契約表・probe の byte 一致移植

**判定: APPROVE**

31 本の内訳と byte 一致 28 本の列挙が整合しました。PHPStan の保証範囲も誇張されていません。

### 施策 2: 配布雛形の適合

**判定: APPROVE**

変更を事実訂正と参照先変更に限定しており、必要最小限です。実行行を変更しない境界も明確です。

### 施策 3: 静的 gate と目録

**判定: APPROVE**

既存の `QueuedJobPopulation` を正本として再利用する判断は妥当です。Mailable / Notification を含む意味と vendor 除外も明示されています。

### 施策 4: 振る舞い検査

**判定: APPROVE**

B0-B4 と M9-M12 の対応が明確になりました。framework 前提の pin として十分です。

### 施策 5: 逸脱登録

**判定: APPROVE**

D25 の対象、理由、不変条件、再判定条件が具体的で、既存正本との関係も説明されています。

### 施策 6: 規約・保証範囲の明文化

**判定: APPROVE**

MR-1 への分離は妥当です。散文の完全性を形だけの Architecture テストで保証したことにしない判断も適切です。

### 施策 7: byte 一致検証

**判定: APPROVE**

commit SHA 固定、28 本と3本の明示列挙、合計件数の自己検査により再現可能になっています。

### 受け入れ条件

**判定: REQUEST_CHANGES**

[Warning] AC-7 の `git diff --stat main -- app/` は、`app/` 配下の untracked ファイルを検出しません。また、実装中に `main` が進むと比較基準も変わります。そのため「`app/` の差分が0行」を完全には機械検証できず、fail-open です。

修正案:

- セットアップ時の基準 commit SHA を記録し、その SHA と比較する。
- tracked 差分は `git diff --exit-code <base-sha> -- app/` で判定する。
- untracked を含む作業ツリー差分は `git status --porcelain -- app/` が空であることを別途判定する。
- AC-7 の検証方法に、この2条件を明記する。

[Suggestion] `mutations/` と `mutation-log.md` を「恒久化しない」とする一方、AC-8 の受け入れ証跡にしています。PRへ残すのか、実装完了後に削除するのかを明示すると、変更ファイル一覧とレビュー証跡の扱いが曖昧になりません。

## 全体判定

**CHANGES_REQUESTED**

Round 1 の6件は解消済みです。残る必須修正は AC-7 の untracked ファイルと比較基準固定への対応だけです。その他の設計、スコープ、検査の fail-closed 性、31/28/3 の切り分けは承認できます。