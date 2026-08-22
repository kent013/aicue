## 全体判定: CHANGES_REQUESTED

Round 1のCritical 2件は閉じています。正典t1の4点そろいも維持されており、検査Aの全数化は過大化ではありません。

ただし、新たに3点のWarningがあります。いずれも設計段階で明確化すべき内容です。

### Critical 2件の再判定

- 自己違反: 解消済み
  - 提案された`QrCodeImage.svelte`と変更後`Security.svelte`には、検査対象文字列の字面が残っていません。
  - 他の字面は`eslint.config.js`、DESIGN.md、gate、設計書内にあり、いずれも検査Cの対象外です。
  - 現行`Security.svelte`の実構文1件は施策3で除去されるため問題ありません。

- 検査Cの恒久的な裏取り: 解消済み
  - `containsRawHtmlSink()`を実ファイル判定とC'の合成入力で共用しています。
  - 違反パスを最終assertionに使うため、AGENTS.md (d)にも適合します。
  - 実ファイルが0件になった後もC'が検出器の生存を保証します。

## 各施策の判定

### 施策1: lint規則 — APPROVE

規則の追加、許可一覧を設けない宣言、既存gateとの責務分離は適切です。

### 施策2: QrCodeImage — APPROVE

自己違反は解消されています。`class`削除も妥当で、atomは単機能・無import・無状態の範囲に収まっています。

### 施策3: Security.svelte置換 — APPROVE

nullの絞り込み、アクセシブルネームの単一化、Atomic Designのimport方向、既存状態機械との分離はいずれも適切です。

### 施策4: raw HTML sink gate — REQUEST_CHANGES

[Warning] C'の「近縁形」の期待値が曖昧で、現在の検出契約と衝突する可能性があります。

C'の正例欄に「接頭辞つき・打ち消しつき・接尾辞つきの近縁形を含める」とあります。しかし、検出器は部分文字列`"{" + "@html"`を含めばコメントや文字列でも違反とする契約です。したがって、例えば接尾辞付きの形が禁止文字列を内包するなら、正例ではなく違反です。

また、AGENTS.md (e)の3近縁形要件は「許可語を否定的に除去する照合」に対するもので、許可一覧を持たない本検出器には適用されません。

修正案:

- C'から「接頭辞つき・打ち消しつき・接尾辞つき」の記述を削除する。
- 次のように期待値を明示する。

  - `true`: 実構文、コメント内、文字列リテラル内、禁止文字列を内包する接頭辞・接尾辞付き文字列
  - `false`: `{name}`、`{@const}`、`{@render}`、`{#if}`、`{@htm value}`など禁止文字列を内包しない形

[Warning] Fの「ignored扱いを落とす」が実装表に落ちていません。またfatal/ignored分岐の恒久的な負例がありません。

Fの表にある実装は`fatalErrorCount`と`message.fatal`だけです。docblockで宣言したignored判定は実装されていません。また、B/B'/Dはすべて正常にparseされる入力なので、fatal検査を削除・破壊しても検出できません。

修正案:

- `assertLintExecutionUsable(result, isIgnored)`のような純関数を設ける。
- 各仮想パスについてESLintの公開API `isPathIgnored(filePath)`も確認する。
- 恒久的な正負コントロールを追加する。

  - 正例: fatal 0、fatal messageなし、ignored false
  - 負例1: `fatalErrorCount > 0`
  - 負例2: `message.fatal === true`
  - 負例3: ignored true

これによりFもAGENTS.md (b)(c)を機械的に裏取りできます。

検査Aを123件全数へ広げる判断は妥当です。件数そのものを123にpinせず、非空だけを不変条件にしている点も適切です。

### 施策5: 部品テスト — APPROVE

接頭辞、decodeによる往復、壊れやすい文字、DOM要素非生成、アクセシビリティという性質検査へ整理され、前回の矛盾は解消されています。

### 施策6: 画面テスト — APPROVE

置換前にIMG・data URI・DOM非挿入の失敗を確認するため、テストファーストとして成立しています。取得失敗経路も維持されています。

### 施策7: CSP依存のpin — REQUEST_CHANGES

[Warning] 現在の負のコントロールでは「token完全一致」の検出力を裏取りできません。

`img-src "'self'"`で`data:`がないことを確認しても、誤った部分文字列実装は検出できません。今回防ぎたいのは`https://data:443`を`data:`と誤認する実装です。

修正案:

- helperの合成入力テストを追加する。

  - `img-src 'self' data:` → source列に`data:`を含む
  - `img-src 'self' https://data:443` → source列に`data:`を含まない
  - `script-src 'self'; img-src 'self' data:` → 正しいdirectiveを選ぶ
  - タブ区切りも宣言対象に含めるなら、タブ入力でもtoken化できる

- 現在のconfigを変更する負のコントロールは、上記helper直接テストへ置き換えて構いません。
- AGENTS.md共通走査規約の形式的な対象はArchitecture gate等であり、このFeatureテストへ(e)が「直接適用」とする説明は外す方が正確です。完全一致を採る理由は、CSP判定の正確性として十分説明できます。

既定・GTMの実レスポンスを同じhelperで確認する本体テストは適切です。

### 施策8: DESIGN.md — APPROVE

禁止、代替部品、CSP依存、走査対象内では字面も禁止されることが明確になっています。

## 実装順の判定

全体の順序は概ねテストファーストとして成立しています。ただし、段1末尾の次の説明は誤りです。

> `containsRawHtmlSink()`を`return false`にするとCとC'が赤くなる

現行`Security.svelte`に違反がある状態で`return false`にすると、Cは違反を見逃してgreenになります。赤くなるのはC'の違反入力側だけです。これはまさにC'が必要な理由です。

修正案:

- 記述を次に変更する。

  - C'の違反入力テストを先に書き、`return false`のstubでC'が赤くなることを確認する。
  - 実装後にC'をgreenにする。
  - その状態で統合検査Cが現行`Security.svelte`の1件を検出して赤になることを確認する。
  - `return false`へ戻すとCはgreenになってしまうため、C'が恒久的な検出力を担う。

Dが最初からgreenなのは正例なので問題ありません。Fについては上記のfatal/ignored合成負例を追加すれば、最初からgreenという問題も解消できます。

## 最終結論

正典t1の欠落やスコープ過大化はありません。Round 1のCriticalは解消済みです。以下の3点を直せばAPPROVEDです。

1. C'の近縁形について期待値を明確化
2. Fへignored判定とfatal/ignoredの恒久的負例を追加
3. CSP helperへ`https://data:443`を使った完全一致の負例を追加

加えて、実装順の「`return false`でCも赤くなる」という説明を訂正してください。