`tests/Architecture/BughuntNamingResidualTest.php`

[Warning] `bughuntNamingOffsetsOf()` が明示的に保証する「重複する出現の検出」と「空 needle の例外」に、永続的な正例・負例がありません。現在の実装は正しいものの、`$from = $at + 1` が非重複走査へ退化しても N-4 (a)〜(l) は検知できません。走査器共通規約 (c) に従い、例えば `bughuntNamingOffsetsOf('aaa', 'aa') === [0, 1]` と、空 needle が例外になるケースを同ファイルへ追加してください。手動の赤実測だけでは将来の退化を防げません。

それ以外は設計どおりです。

- 有効な申告位置は必ず実出現位置なので `$declared ⊆ $actual` が成立し、逆向き差分を省く判断は妥当です。
- 未申告、消滅・一意に特定できない申告、二重申告の3方向を漏れなく落としています。N-4 (l) も件数比較だけでは通る退化を適切に固定しています。
- パス名の deny-by-default、除外順序、読み取り失敗と `git ls-files` 失敗の例外化、母集団と正の対照に論理的な穴は見当たりません。
- PHPStan の対象外なので array-shape PHPDoc は静的な強制力を持ちませんが、今回の固定台帳は N-3 と実行テストで検査され、型の widen・ignore・baseline はありません。
- 保証外の経路も誇張なく記載されています。禁止事項違反やテスト削除もありません。

CHANGES_REQUESTED