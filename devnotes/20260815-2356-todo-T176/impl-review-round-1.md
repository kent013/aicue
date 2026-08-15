**scripts/bug-hunt-inventory.py**
- [Warning] `check_catalog()` がバッククォート内に `/` を含む token をすべて無視しています。設計で候補外なのは `routes/api.php` のようなパスであって、任意の `projects/store` まで無視すると「代表機構列の route 名が実在しない」壊れ方が green になります。`routes/*.php` など明確なパス形だけ除外し、それ以外の backtick token は drift にするべきです。負の対照も追加してください。
- [Warning] `load_annotations()` の未知トップレベルキーが `FatalError` = exit 2 になっています。設計では未知キーは「段 2 の drift」とされているので exit 3 が正しいです。fail-closed ではありますが、終了コード規約と食い違っています。
- [Suggestion] `notes-screens.md` 側にも「表を書かない」と文書化されていますが、実装で検査しているのは `notes-operations.md` だけです。意図的に operations だけなら、screens 側の文言を弱めるのがよいです。

**scripts/tests/test_bug_hunt_inventory.py**
- [Warning] 上記の catalog `/` token の抜けを固定する負の対照がありません。`代表機構` に `` `projects/store` `` のような typo を入れて exit 3 になるテストが必要です。
- [Warning] 未知トップレベルキーのテストが exit 2 を期待しており、詳細設計の exit 3 と逆向きです。

**AGENTS.md / docs/template-divergence.md / .claude/skills/app-bug-hunt/SKILL.md**
- [Warning] 保証範囲の書き方が一部誇張されています。「webhook には沈黙する」とありますが、生成物では `webhooks.ses` が `web` 面として operations に入り、`外` として可視化されています。正しくは「`web` group を宣言していない webhook には沈黙する。`web` を宣言したものは注釈を要求される」です。

**app/Console/Commands/Bughunt/InventoryScanCommand.php**
- 指摘なし。PHP 側を事実抽出だけに留める線引き、production 相当で stdout を空にして落とす契約、`web` group を残す取得方法は設計に合っています。

**app/DataTransferObjects/Bughunt/*.php**
- 指摘なし。array shape と `list<non-empty-string>` の境界は妥当です。

**scripts/bug-hunt-inventory-check.sh**
- 指摘なし。判定を持たない薄い起動口になっています。

**.claude/skills/app-bug-hunt/screens.md / operations.md**
- 指摘なし。生成物宣言、operations の 5 列契約、`webhooks.ses` の可視化は方向性として妥当です。

**tests/Architecture/* / tests/Feature/Bughunt/InventoryScanCommandTest.php / tests/Support/SplitConsoleOutput.php**
- 指摘なし。特に stderr/stdout 分離と sandbox 実走は、旧実装の fail-open を潰す意図に合っています。

全体として主設計は通っていますが、段 4 の catalog 照合に green で抜ける経路が残っています。

CHANGES_REQUESTED