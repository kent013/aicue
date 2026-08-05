前提どおり、**提供テキストのみ**で設計レビューしました（コマンド未実行）。

**施策別判定**
- 1) Carbon overflow gate 新設: **REQUEST_CHANGES**
  - [Warning] `safeCalls > 0` を実コード依存で要求すると、将来「違反ゼロかつ許可形ゼロ」の健全状態でも誤落ちします。  
    修正案: 空振り検知は実コード依存ではなく、既存の負/正コントロール fixture で担保し、`safeCalls > 0` 条件は削除。
  - [Warning] `$date->{$method}()` のような動的呼び出しが完全スルーで、規約回避経路が残ります。  
    修正案: 動的メソッド呼び出しを「要明示 allowlist」で fail（deny-by-default）にする。

- 2) Carbon 8箇所置換: **APPROVE**

- 3) AGENTS.md 追記: **APPROVE**

- 4) 非複合 global use gate 新設: **REQUEST_CHANGES**
  - [Critical] 判定が実質 `T_STRING` 依存で、`use \RuntimeException;` / `use function \strlen;` のような「先頭 `\` 付き単一名」を取りこぼす設計ギャップがあります。  
    修正案: import 名をトークン列で正規化（`T_NAME_*` / `T_NS_SEPARATOR` 対応）し、`ltrim($name, '\\')` 後に区切り `\` を含まないものを違反判定。負のコントロールも追加。
  - [Warning] `T_NAME_*` 非依存での実装だと tokenizer 差分に弱いです。  
    修正案: 「名前を1要素として集約→セグメント数判定」に統一。

- 5) migration 2件修正: **APPROVE**

- 6) ページタイトル網羅 gate 新設: **REQUEST_CHANGES**
  - [Warning] `setPrivateTitle` 判定が「識別子文字列の存在」だけで、実呼び出しでなくても通るため偽陰性リスクがあります。  
    修正案: `->setPrivateTitle(` / `?->setPrivateTitle(` の呼び出しトークン列に限定。
  - [Warning] `Inertia::render` 判定で `render` 後の `(` を確認しておらず、callable参照等を誤検出し得ます。  
    修正案: `Inertia::render(` まで厳密一致にする（`(` 必須）。
  - [Suggestion] メソッド名解決を小文字正規化して、PHPのcase-insensitive仕様に揃えると堅牢です。

- 7) `config/seo.php` 4 route追加: **APPROVE**

- 8) 招待無効分岐タイトル: **APPROVE**
  - [Suggestion] 文言は `Invitations/Invalid` の見出しと完全一致にするか、差異理由をコメントで固定すると運用が安定します。

- 9) `<svelte:head>` 二重SoT禁止 gate: **REQUEST_CHANGES**
  - [Warning] `meta[name="description"]` 検出 regex が限定的で、`name=description`（無引用）や式展開等で抜け道が残ります。  
    修正案: Svelte AST 解析に切替、または regex を拡張して無引用/式構文もカバーし、対応ケースを負のコントロール追加。

- 10) D11 登録: **APPROVE**

**全体判定**
- **CHANGES_REQUESTED**

優先度としては、まず **施策4の[Critical]** を先に潰し、その後に施策6/9の検出精度を上げるのが最短で安全です。