全体判定: **CHANGES_REQUESTED**

導入専用ロック、証跡退避順序、旧記述の除去は妥当です。ただし、OS 依存の不足判定について、提示された vendor 根拠と実際に呼ぶ CLI 経路の対応がまだ成立していません。ここは導入判断の中核なので Critical です。

### 1. 使命との整合性

[Suggestion] WebKit レーンの導入失敗をテスト失敗から分離し、実ブラウザ固有の回帰を継続的に検出できるようにする点は North Star と整合しています。「唯一」の範囲も実ブラウザ固有挙動に限定され、主張は適正化されています。

### 2. 禁止事項違反

[Suggestion] テスト計画、deny-by-default inventory、PHPStan level 10 への配慮が含まれており、禁止事項への明確な抵触はありません。

`BROWSER_PROVISION_LOCK_DIR` は「self-test 専用」とありますが、通常実行で任意指定できる環境変数になります。契約を弱める抜け道にならないよう、既存 `GLOBAL_TEST_LOCK_DIR` と同等の制約を Architecture／contract テストへ登録する必要があります。

### 3. 実現可能性

[Critical] `playwright install-deps --dry-run` が `reportMissingDependenciesLinux()` を通ることが立証されていません。

提示された説明は、`reportMissingDependenciesLinux()` が呼ばれた場合の判定内容を示しています。しかし必要なのは、実際に使用する次の CLI 経路がその関数へ到達することの立証です。

```text
playwright install-deps --dry-run
  → reportMissingDependenciesLinux()
```

Playwright では一般に「依存関係の検証」と「install-deps が実行予定のインストールコマンドを表示する処理」は別経路です。後者の dry-run が、現在の充足状態にかかわらず必要パッケージ集合を表示する実装であれば、今回引用された関数は不足判定の根拠になりません。

修正提案:

- `install-deps` コマンドの handler から不足判定までの実際の call path を関数名付きで示す。
- 充足済み Linux 上で `install-deps --dry-run` がどの終了コードと出力を返すかを実測結果として記載する。
- 不足状態を表す exit 1 と、CLI 自体の異常終了を出力形式だけで混同しない判定を定義する。
- 当該 CLI で不足判定できない場合は、Playwright の dependency validation 経路を直接起動できる公式 CLI、またはブラウザ起動 smoke を判定根拠に変更する。

この対応がないと、充足済み環境でも常に「不足」と判断して sudo／apt を起動する可能性があり、成功条件そのものが成立しません。

[Warning] ブラウザの充足を「導入先ディレクトリの実在」だけで判定するのは不十分です。中断されたダウンロード、必要ファイルの欠損、実行権限不備でもディレクトリは存在し得ます。

修正提案: Playwright が管理する完了マーカーまたは期待する executable の存在・実行可能性を確認してください。内部マーカーへ依存したくない場合は、短いブラウザ起動 smoke を採用し、そのコストを成功条件へ反映してください。

[Warning] `BROWSER_TEST_DEPS=force` の意味が依然として定義されていません。`auto` との差、再取得の有無、Darwin での挙動、権限不足時の結果が不明です。

修正提案: 状態遷移表で `auto`／`force` の動作を固定してください。用途が存在しないなら、思考原則2に従って `force` 自体を削除する方が簡潔です。

### 4. 期待効果の妥当性

[Warning] 「充足済みなら sudo／install-deps／再取得を起動しない」という成功条件は適切ですが、現行の不足判定根拠ではまだ達成を合理的に期待できません。

修正提案: stub による契約テストだけでなく、pin された Playwright を使う実 CLI smoke で、充足済み Linux における終了コードと分岐を検証してください。stub は自分たちの想定を確認できますが、vendor の実挙動までは証明しません。

[Suggestion] cache key に OS、architecture、lockfile hash を含め、restore key を使わない設計は妥当です。

### 5. リスク

[Warning] `/tmp/browser-provisioning-<uid>.lock` は同一 UID・同一 `/tmp` namespace 内でのみ排他できます。別コンテナが同じ OS パッケージ管理機構へ触れる構成では、`/tmp` が分離されていると dpkg に対する排他になりません。

修正提案: 保証範囲を「同一ホスト」ではなく「同一 UID かつ同一 lock directory namespace」に限定して記載してください。devcontainer 間でも排他を保証する必要があるなら、実際に共有される lock directory を正本にする必要があります。

[Suggestion] 証跡について「終了コード保存 → 退避 → 次レーン → 最終判定」と、旧成果物の削除位置が明文化されました。この設計なら Chromium の証跡消失と `set -e` による退避漏れを防げます。

### 6. スコープの適切さ

[Suggestion] `composer setup` と devcontainer 初期化から切り離し、Browser レーンと CI に限定したスコープは適切です。台帳項目3の完了条件を読み替える判断も明示されています。

ただし、その読み替えが台帳の受入条件変更に当たるため、実装 PR では「既存要件をそのまま達成した」とせず、変更理由と新しい完了条件を台帳へ残す必要があります。

### 7. 型安全性

[Suggestion] 本件は DTO／JsonResource 境界に影響しません。PHP 側を型付き定数、`Assert::string()`、`list<string>` で閉じる方針は PHPStan level 10 と整合します。

シェル側についても、対象ブラウザ数との一致だけでなく、抽出値の絶対パス性、重複、空文字、対象ブラウザとの対応を検査すると、想定外の CLI 出力を誤って充足扱いするのを防げます。