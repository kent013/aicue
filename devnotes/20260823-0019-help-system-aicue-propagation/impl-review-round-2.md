Round 1 の schema drift に関する Critical は閉じています。一方、filesystem 側は最終要素の symlink は拒否できていますが、親要素経由の静止状態の symlink が残るため、完全には閉じていません。

## 指摘

### [Critical] `app/Services/Help/HelpRepository.php`

`rootReal()` が拒否するのは `$this->root` の最終要素が symlink の場合だけです。親要素が symlink の場合は静止状態でも外部を置き場として受理します。

例えば以下の形です。

```text
holder/docs -> /outside/docs
root = holder/docs/help
```

`holder/docs/help` 自体は通常ディレクトリなので `is_link($this->root)` は false ですが、`realpath()` は `/outside/docs/help` を返します。その後の読み書きと containment 検査はすべて外側を正当な root として扱います。

これは今回脅威モデルから外した「操作中の差し替え」ではなく、操作開始前から成立する静止状態の symlink 経路です。信頼する anchor を明確にし、canonical root がその anchor 配下の期待位置と一致することを検査する必要があります。単純に全祖先の symlink を禁止すると、symlink 経由でチェックアウトされた作業ツリーまで拒否するため、`base_path()` の canonical path を信頼 anchor とする配線が適切です。

また、`writeGenerated()` の docblock に残る次の記述は、縮小した保証と矛盾します。

> 作成の途中で入れ替えられた形を残さない

実際には事後検出だけで取り消せないため、「入れ替えを検出する」に修正すべきです。

### [Warning] `tests/Feature/Help/HelpRepositoryTest.php`

追加された root symlink 負例は最終要素が symlink の場合だけです。上記の「親要素が symlink、root 最終要素は通常ディレクトリ」という負例を追加してください。

現在の負例は新しい `is_link($this->root)` 分岐を正しく発火させており、空振りではありません。

### [Warning] `app/Services/Help/McpToolScanner.php`

こちらも同じく、走査根の最終要素しか検査していません。親要素が symlink なら、`scandir()`、autoload、Reflection の実体一致がすべて外側の同じ canonical path を見るため通過します。

走査根は first-party の固定位置なので、信頼する canonical repository root との位置関係を固定する必要があります。

### [Warning] `tests/Unit/Architecture/McpToolScannerTest.php`

追加された走査根 symlink テストは実装の新分岐を確実に発火させており、検出力はあります。ただし親要素 symlink の静止状態を検出できることは裏取りされていません。

## 問題が解消したファイル

### `app/Services/Help/McpToolMetadata.php`

判定: OK。

- top-level `type === 'object'` を要求している
- 許容キーを `type` / `properties` / `required` に pin している
- `properties` の改名をパラメータ 0 件へ畳まない
- 未知キーで停止する判断は I14 の fail-closed 方針と一致する

vendor が無害なキーを追加した場合も停止しますが、本機構では「更新を認識して正規化境界をレビューする」ことが目的なので、過剰拘束ではありません。

### `tests/Unit/Services/Help/McpToolReferenceGeneratorTest.php`

判定: OK。

top-level drift の3負例が追加され、各 dataset が分岐固有の文言を検査しています。Round 1 で指摘した「別分岐へ流れても共通メッセージだけで緑になる」問題は解消しています。

### `app/Services/Help/Generators/McpToolReferenceGenerator.php`

判定: OK。

パラメータ名を別名へ変換せず拒否する判断は妥当です。識別子と実装の対応を維持でき、`|`、backtick、改行を1つの明確な分岐で拒否しています。追加テストも production の `fromTool()` → schema serialization → generator 経路を通っており、空振りではありません。

### `docs/help-system.md`

判定: [Warning]。

TOCTOU を防止せず、静止状態のみを保証するという主要な説明は明確です。ただし実装と同様、親要素 symlink を扱っていないため、「置き場が symlink であってはならない」という記述が最終要素だけを意味するのか、anchor からの経路全体を意味するのか曖昧です。

また、実装 docblock の「入れ替えられた形を残さない」という記述とも整合していません。

## Round 1 指摘の対応状況

- HelpRepository root symlink: 一部解消。最終要素は閉じたが、親要素経由が残る。
- TOCTOU: 保証を狭める判断は妥当。大部分は一貫しているが、旧来の過大なコメントが1箇所残る。
- top-level schema drift: 解消。
- dataset の分岐固有検査: 解消。
- McpToolScanner root symlink: 一部解消。最終要素は閉じたが、親要素経由が残る。
- パラメータ名: 解消。
- 運用文書: 大部分は解消。symlink の保証範囲と残存コメントの整合が必要。
- 負例不足: 指摘された直接形は追加済み。親要素 symlink の負例がなお必要。

CHANGES_REQUESTED