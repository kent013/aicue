**CHANGES_REQUESTED**

Round 8 の修正本体は妥当ですが、旧記述が残っています。

- **[Warning] P3**: Plan 集合節が「P8b で `personal` / `starter` を再公開」と記載。**Starter のみ**へ修正。
- **[Warning] P4**: 非スコープ節が「`personal`・`starter` の再公開は P8b」と記載。**Starter のみ**へ修正。
- **[Warning] P4**: 一覧節が「personal + standard が露出」としつつ、直後に「personal/starter は false」と矛盾。**Personal=true / Starter=false**へ修正。
- **[Warning] P7**: `CreateNewUser` の「signup grant 呼び出しは触らない」と、`RegistrationTest` の「signup grant期待を維持」がP6後の契約と矛盾。**P6で撤去済み・未付与期待を維持**へ修正。

P8b の `down()` と件数テスト自体は正しく修正されています。上記は記述統一のみです。