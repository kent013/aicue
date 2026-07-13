# 対応マトリクス: impl-review Round 2 (Codex 判定: APPROVED)

Round 2 で Codex は全体判定 **APPROVED**。Critical 2件は解消済みと確認された。残る Suggestion への対応:

## [Suggestion] sole-gateway 検査が addRole/removeRole/syncRoles のみで role_user 直接書き込みを見逃す
- 判断: 対応する
- 対応内容: 検出正規表現を `->(addRole|removeRole|syncRoles)\(|role_user` に拡張し、role_user pivot への直接アクセス (insert/attach/detach 等) も許可リスト外で禁止。現状 role_user を参照する app コードは許可済み2サービスのみ (grep 済み) のため、read を含む広めの guard でも偽陽性なし。docblock の保証範囲説明も正確化。

## [Suggestion] OrganizationProvisioningService をファイル単位で免除 (メソッド単位 inventory の方が強固)
- 判断: 見送る (現状スコープ外・承認阻害ではないと Codex も明記)
- 根拠: 同ファイルは現状 provision() の creator への Owner 付与のみ (調査済み) で、既存組織の owner 集合は変えない。メソッド単位 inventory への昇格は本タスクの孤児化ガードのスコープを超える汎用リファクタで、オーバーエンジニアリング (AGENTS.md 禁止事項6・思考原則「今必要なものだけ作る」)。将来 provisioning に owner 変更経路が増える場合に inventory 化を検討する。

## [Suggestion] Auth::logout 後の削除失敗で認証状態が戻らない
- 判断: 見送る (旧実装と同等・本タスク非阻害と Codex も明記)
- 根拠: 旧実装も logout→delete の順で同じ性質。削除失敗は 500 相当の想定外パスで、その際に認証状態が戻らないのは許容 (ブロック時はガードが logout 前に throw するため発生しない)。
