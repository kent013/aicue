以下、**詳細設計レビュー結果**です。  
（前提どおり、提示テキストのみを対象にレビュー）

## 施策1: F-01 bughunt env の APP_NAME 自己参照解消
**判定: APPROVE**

- [Suggestion] `.env.bughunt.local` は非コミット運用のため、手順書に「example更新後に実ファイルへ同値反映」を明文化すると運用漏れを減らせます。
- [Suggestion] `APP_NAME="AI-CUE"` を採用する判断は妥当。North Star と表示整合も取れています。

---

## 施策2: F-01 env example の自己参照/前方参照禁止 invariant
**判定: APPROVE**

- [Warning] 正規表現が `^([A-Z0-9_]+)=(.*)$` なので、`export APP_NAME=...` 形式が将来混在した場合は検知漏れになります。  
  **修正案:** `^(?:export\s+)?([A-Z0-9_]+)=(.*)$` へ拡張し、既存互換を維持。
- [Suggestion] `ENV_EXTERNAL_REF_ALLOWLIST` の「理由」必須は良い設計。fail-closed 文化と整合。

---

## 施策3: F-02 `lang/ja/validation.php` attributes の全域補完
**判定: REQUEST_CHANGES**

- [Critical] `g-recaptcha-response` の required は既に `messages()` で個別文言を固定しており、今回 attributes 追加で全面カバーされるわけではありません。設計書内で「attributesで対応」と読める箇所があるため、責務境界が曖昧です。  
  **修正案:** 設計本文に「`g-recaptcha-response.required` は FormRequest 個別 messages を正とし、attributes は fallback 用」と明記。
- [Warning] `status`, `name`, `description` など多義語は画面語彙と不一致が起きやすいです。  
  **修正案:** 「UIラベル差分が発生したキーは FormRequest::attributes() で局所上書き」の具体例を1件追記（例: 組織名としての `name`）。
- [Suggestion] ラベル表に「どの FormRequest/Controller の rule キーか」を1列足すと将来追跡性が上がります。

---

## 施策4: F-02 attributes カバレッジ invariant（fail-closed）
**判定: APPROVE**

- [Warning] `Validator::make` 検出で import alias や FQCN 混在時に取りこぼし余地があります。  
  **修正案:** `use` 文解決を最小実装で追加するか、検出対象を「`T_STRING Validator` 依存」から「`T_NS_SEPARATOR` を含む `Illuminate\Validation\Validator` 系」まで拡張。
- [Suggestion] `UNPARSEABLE_CALL_INVENTORY` のキー形式（`path@line#kind` など）を固定すると運用が安定します。

---

## 施策5: F-02 表示文言の再現 Feature テスト
**判定: APPROVE**

- [Suggestion] 文言一致テストは適切です（今回の不具合再発防止に直結）。
- [Suggestion] 可能なら `お問い合わせ内容は必須項目です。` のような完全一致に加え、将来の句読点差分に備える方針（厳密一致を維持するか）をテストコメントで明示するとよいです。

---

## 総合評価
**全体判定: CHANGES_REQUESTED**

主因は**施策3の責務境界記述の曖昧さ（Critical）**です。  
実装方針そのものは全体的に堅く、特に施策2/4の fail-closed 設計とテストファースト順序は非常に良いです。  
上記 Critical/Warning を反映すれば、再レビューで **APPROVED** 相当です。