## レビュー結果

全体判定は **CHANGES_REQUESTED** です。

正本をカード前付けへ一本化する中核設計、`Assignment.cards` の省略、採用時債務から D20/D14 への移動、`FatalError → exit 3` の方向性は妥当です。PHPStan level 10 上も、提示された PHP 3 ファイルに型上の問題は見当たりません。

一方、gate の検出力と設計契約に修正必須の問題があります。

### 指摘

1. [Warning] S8 以降の追加を Architecture テストが禁止しています  
   [test_story_front_matter.py](/workspace/.claude/worktrees/tasks/T245/.claude/skills/app-bug-hunt/stories/test_story_front_matter.py) の `test_readme_is_excluded_and_others_are_not()` が `len(names) == 7` を固定しています。

   これは D7 の「S8 以降を正規手続きで追加できる」と正面から矛盾します。AC-06 は S8 を阻害しないよう作られているのに、別テストが阻害しています。件数固定を削除し、母集団非空と S1〜S7 の pin は既存検査へ任せるべきです。

2. [Warning] AC-06 の負例が「面の付け替え」を検出したことを証明していません  
   同ファイルの `test_ac_06_rejects_reassigned_family_surface()` は、S1 だけのカード集合を S1〜S7 の期待集合と比較しています。そのため、S1 の surface を正しい `signup_funnel` に戻してもテストは成功します。

   つまり中核負例として pin されていますが、実際には「カードが6枚不足している」ため落ちており、共通規約 (c) の「正しい理由で落ちる」を満たしません。実カード7枚のうち S1 の surface だけを差し替えて検証してください。

3. [Warning] 表 A/B の構造検査が詳細設計より寛容です  
   同ファイルの `marker_table()` には次の穴があります。

   - END が BEGIN より前でも、BEGIN 後に表があれば通り得る
   - 空行をすべて除去するため、「空行の位置も契約」を検査しない
   - 区切りセルが `-` 1文字でも通り、正準な `---` を要求しない

   マーカー順序、正準な空行配置、正準区切り行を判定し、それぞれ負例を追加する必要があります。空行位置を意図的に自由化するなら、詳細設計と README 側の契約を狭める必要があります。

4. [Warning] 制限文法の負例が設計どおり全分岐を裏取りしていません  
   [test_story_front_matter.py](/workspace/.claude/worktrees/tasks/T245/.claude/skills/app-bug-hunt/stories/test_story_front_matter.py) の AC-01 は、A1〜A6を同一主題へ対応させていますが、少なくとも次の契約を直接壊す負例がありません。

   - コロン後の空白なし／複数空白
   - 不正な key 書式
   - 配列の区切り揺れ、ネスト
   - 複数行スカラー、アンカー、参照、ネストマップ

   現実装は多くを拒否しますが、検出分岐の裏取りが不足しています。「主題に何らかの rejects がある」だけでは各不変条件の検出力を証明できません。

5. [Warning] `SKILL.md` と `scripts/README.md` の古い操作指示は残置すべきではありません  
   [.claude/skills/app-bug-hunt/SKILL.md](/workspace/.claude/worktrees/tasks/T245/.claude/skills/app-bug-hunt/SKILL.md) の「注釈（割当・区分・理由）」という説明は、利用者や探索エージェントを廃止済みの入力先へ誘導します。

   `story` が exit 3 になるため静かな破損ではありませんが、正規手順どおり操作すると必ず失敗する状態です。「割当の正本を一本化した」という完了条件にも反します。採用時債務だから触らないという判断より、今回の変更に伴う運用契約の同期を優先し、必要なら両ファイルを債務から移して D20 の対象へ登録するのが妥当です。

6. [Warning] correlate の終了コード 3 への写像がテストされていません  
   [test_correlate.py](/workspace/.claude/worktrees/tasks/T245/.claude/skills/app-bug-hunt/coverage/test_correlate.py) は `parse_story_cell()` と `correlate()` が `FatalError` を投げることを確認していますが、`main()` がそれを捕捉して `EXIT_INPUT_UNAVAILABLE == 3` を返すことは確認していません。

   catch や戻り値を壊しても現テストは緑です。契約外セルを含む入力で `main()` または CLI を実走し、終了コード3と worklist非出力を固定してください。

7. [Warning] `screens.md` に設計で宣言されていない意味変更が混入しています  
   [screens.md](/workspace/.claude/worktrees/tasks/T245/.claude/skills/app-bug-hunt/screens.md) の課金ゲート説明が、割当列や生成通知とは無関係に変更されています。

   特に、契約済みの `manageBilling` 非保持者の行き先が `dashboard` から `onboarding.billing-required` と読める内容へ変わっています。詳細設計の「差分は通知文とS7追加だけ」に反します。既存の生成物ドリフトを同時解消したものなら、正本となるノートおよびアプリ挙動との一致を別途説明してください。そうでなければ今回の差分から除外すべきです。

8. [Suggestion] スカラー型の分類を fail-closed にしてください  
   [story_front_matter.py](/workspace/.claude/worktrees/tasks/T245/.claude/skills/app-bug-hunt/stories/story_front_matter.py) は `SCALAR_KEYS` を宣言していますが使用せず、bool/array以外をすべてスカラー扱いします。

   現在の key 集合では動作しますが、将来 canonical key を追加して型集合への登録を忘れた場合に黙ってスカラーになります。`elif key in SCALAR_KEYS` とし、それ以外は内部契約違反へ落とす方が fail-closed です。

9. [Suggestion] gate の docblock の「名指し2ファイル」が古くなっています  
   [BugHuntInventoryCheckInvariantTest.php](/workspace/.claude/worktrees/tasks/T245/tests/Architecture/BugHuntInventoryCheckInvariantTest.php) は現在、検査シェル・生成器に加えて前付け読み取り器も名指しで複製しています。空振り検査の説明にある「名指しの2ファイル」は3ファイルへ修正してください。

### ファイル別判定

| ファイル | 判定 |
|---|---|
| `coverage/correlate.py` | 実装方針は妥当。exit 3 の結合テスト追加が必要 |
| `coverage/test_correlate.py` | [Warning] `main()` の終了コード検査不足 |
| `inventory/annotations.toml` | APPROVED。`story` 撤去と deny-by-default の説明は妥当 |
| `operations.md` | APPROVED。S7の9操作追加は検算と一致 |
| `screens.md` | [Warning] 課金ゲート説明の意図外差分あり |
| `stories/README.md` | 内容は概ね妥当。ただし表構造の機械契約と実装が不一致 |
| `S1-guest-registration-funnel.md` | APPROVED |
| `S2-invitation-flow.md` | APPROVED |
| `S3-core-journey.md` | APPROVED |
| `S4-org-project-management.md` | APPROVED |
| `S5-billing.md` | APPROVED |
| `S6-security-2fa-profile.md` | APPROVED |
| `S7-authz-boundaries.md` | APPROVED。複数カード割当の目的に合致 |
| `story_front_matter.py` | 概ね正確。[Suggestion] 型分類を明示的に閉じる |
| `test_story_front_matter.py` | [Warning] S8阻害、AC-06負例、表検査、文法負例を修正必須 |
| `scripts/bug-hunt-inventory.py` | APPROVED。`Assignment.cards` を省いた判断も妥当 |
| `scripts/tests/test_bug_hunt_inventory.py` | APPROVED。未割当・欄違い・終・exit契約をよく覆っている |
| `BugHuntInventoryCheckInvariantTest.php` | PHPStan上問題なし。docblock訂正推奨 |
| `BughuntStoryToolSelfTest.php` | APPROVED |
| `StoryFrontMatterPins.php` | APPROVED。ただし現在pinするAC-06負例自体を直す必要あり |
| `docs/template-divergence.md` | 台帳移動とD40は概ね妥当 |
| `LedgerPins.php` | APPROVED。171→168は3件削除と一致 |
| `adoption-debt.tsv` | APPROVED。D14/D20への移動と一致 |
| `SKILL.md` / `scripts/README.md` | [Warning] 古い割当手順の残置は不可 |
| 移行検算資料 | 出力内容は整合。ただし提示差分に変換スクリプト本体がないため、その判定ロジック自体は未確認 |

**全体判定: CHANGES_REQUESTED**