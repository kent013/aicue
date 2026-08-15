前提: コマンド実行・ファイル確認はせず、提示された設計書と抜粋だけをレビューしています。

**全体判定: CHANGES_REQUESTED**

設計の方向性は妥当ですが、fail-closed、対象面の定義、テスト可能性にブロッカーがあります。特に「対象にしない」と書いている route 群が実際には母集合に入り得る点と、`generate` が部分更新を許容している点は直してから実装に進むべきです。

**施策 1: REQUEST_CHANGES**

[Critical] 抽出条件の失敗系テストが成立しません。  
`runningUnitTests()` が true の Feature test 内で `$this->app->detectEnvironment(fn () => 'production')` しても、条件 `isLocal() || runningUnitTests()` は通ります。  
修正案: 抽出可否を小さな service、または protected method に切り出し、テストではそこを差し替える。もしくは command の pure 判定関数を Unit test し、Feature test では成功系に寄せる。

[Warning] `components->error()` が stdout に混ざらない保証が弱いです。  
設計要件は「失敗時 stdout に JSON を 1 バイトも出さない」なので、stderr へ明示出力する設計にしてください。Python 側も stdout の空判定をテストするとよいです。

[Warning] `list<non-empty-string>` の実装根拠が不足しています。  
`is_string()` だけでは空文字を排除できません。  
修正案: `array_filter($values, static fn (mixed $v): bool => is_string($v) && $v !== '')` のようにし、必要なら `assert($methods !== [])` ではなく fatal にする条件を明示してください。

**施策 2: REQUEST_CHANGES**

[Warning] unknown key を拒否する規約が必要です。  
`story` の typo、将来の無秩序な `memo` 追加などを fail-closed にできません。  
修正案: route 注釈の許可キー集合を `kind/story/kubun/reason` に固定し、未知キーは段 2 drift にしてください。

[Warning] `外` / `終` に `story` が残るケースの扱いが未定義です。  
render 側では `-` にするため、古い `story` が残っても見えません。  
修正案: `kubun in {"外","終"}` では `story` を禁止、または存在しても drift にする。

[Suggestion] 一度きり移行スクリプト自体にテスト不要なのは許容できますが、移行ログに「旧表 route 集合と新 annotations の集合一致」を残す条件は明文化した方がよいです。

**施策 3: REQUEST_CHANGES**

[Critical] 面の除外条件が、設計末尾の「保証しないもの」と矛盾しています。  
`SURFACE_EXCLUDED_SEGMENTS = ("oauth",)` と `SURFACE_EXCLUDED_PREFIXES = ("livewire",)` だけだと、Filament `/admin`、`api/`、`mcp`、`sanctum`、`storage`、`_`、`.well-known` などが `web` middleware を持つ場合に母集合へ入ります。一方で保証しないものには `api/` / Filament `/admin` / MCP も含まれています。  
修正案: 除外対象を URI 先頭セグメントで明示し、現行 script から意図的に外すものと注釈で `外` に載せるものを分けてください。例: `("api", "admin", "oauth", "mcp", "sanctum", "storage", "_", ".well-known")` と `livewire*`。そのうえで `webhooks.ses` のように「web 面だが分母外として可視化するもの」は annotations 側へ置く。

[Critical] `generate` が 2 ファイル間の部分更新を許容しており、fail-closed 要件と衝突します。  
「次の check が検出する」は後段検知であって、部分的な成果物を残さない設計ではありません。  
修正案: screens / operations の両方を temp に書き終え、検証後に replace する。replace 中の例外では可能な範囲で旧ファイルへ rollback する。少なくとも「段 1/2/4 成功後の書き込み失敗で片方だけ更新されない」ことをテストしてください。

[Warning] `check_catalog()` の契約が曖昧です。  
「代表機構列の route 名と id の一意性」だけでは、何を route 参照として抽出するかが実装者依存になります。  
修正案: ヘッダ名、id 正規表現、重複 id の扱い、代表機構セル内の route 名記法、複数 route の区切り、存在しない route の exit 3 を具体化してください。

[Warning] Markdown 表セルの `|` / 改行への対処が未定義です。  
`title` や `reason` に `|` が入ると表パーサや byte 比較が壊れます。  
修正案: 表セルに入れる値は `|`, CR, LF を禁止して drift/fatal にするか、下流 parser も含めて escape 規約を決めてください。単純な `split("|")` 下流があるなら禁止の方が安全です。

**施策 4: REQUEST_CHANGES**

[Warning] 薄い shell から `cd "${WORKSPACE}"` が消えています。  
Python 側が `__file__` から repo root を確定する設計なら問題ありませんが、現記述では cwd 依存に見えます。  
修正案: shell で `cd "${WORKSPACE}"` を維持する、または Python に `--repo-root "${WORKSPACE}"` を渡す契約にしてください。

[Suggestion] 「判定語を持たない」静的テストは、コメントも対象にするなら語の選定に注意が必要です。`OUT_OF_SCOPE` の再混入検知は有効ですが、将来説明コメントで誤検知しないよう対象を script 本体の実装行に限定してもよいです。

**施策 5: REQUEST_CHANGES**

[Critical] 施策 1 の production 失敗系がテスト不能なまま計画されています。  
修正案は施策 1 と同じです。ここを直さないと fail-closed の最重要経路が未検証になります。

[Warning] Python 自己テストで fake scan を使うなら、生成器側の関数構造も注入可能にする必要があります。  
現骨子の `scan(repo_root) -> dict` だけでは、`main()` / `check` / `generate` が実 `php` に固定される可能性があります。  
修正案: `run_check(repo_root, scanner=scan)` / `run_generate(repo_root, scanner=scan)` のような pure entry を作り、CLI はそれを呼ぶだけにしてください。

[Warning] sandbox 実走は `php artisan bughunt:inventory-scan` の shim 前提に変わるため、`PATH` 差し替え、`cwd`、`artisan` 引数の無視を明記してください。ここが曖昧だと実 PHP を呼んで DB や APP_KEY に依存します。

**施策 6: REQUEST_CHANGES**

[Warning] 文書に書く「保証しないもの」と、実際の surface 判定を一致させる必要があります。  
施策 3 の除外設計を直したうえで、`AGENTS.md`、`SKILL.md`、D19 に「沈黙する対象」と「annotations で `外` として可視化する対象」の線引きを明記してください。

[Suggestion] `capability-catalog.md` は生成物ではないが段 4 で検査される、という位置付けは良いです。段 4 の具体契約が固まれば、この施策は概ね問題ありません。