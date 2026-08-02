# 全体判定: CHANGES_REQUESTED

Round 1 の Critical 2 件は適切に解消されています。ただし、同じ PostgreSQL 型不一致を起こし得る UUID binding が inventory から漏れているため、承認には修正が必要です。

## 1. 使命との整合性

[Suggestion] P1・P3 を「詰まりにくさ」「共用端末の安全性」に結び付けた整理は妒当です。基盤改善でありながら、North Star への寄与が明確になっています。

## 2. 禁止事項違反

[Warning] P3 の `logout → browser back` は Laravel Feature テストだけでは検証できません。Feature テストが確認できるのはレスポンスヘッダまでで、実ブラウザの bfcache 復元動作ではありません。

修正提案: 次の2層に分けて記載してください。

- Feature テスト: 認証済み HTML/Inertia 応答の `Cache-Control` を検証
- Browser/E2E または bug-hunt: `logout → back` で PII が再表示されないことを検証

これにより「テスト済み」の範囲を過大申告せず、禁止事項 #1 にも明確に対応できます。

## 3. 実現可能性

[Critical] `{oauthSession}` を「UUID PK」という理由だけで B 群へ除外している点が不整合です。UUID は除外理由ではなく、数値 PK と同じ PostgreSQL 22P02 対策が必要な型です。記載上、既存の `whereUuid` が確認できているのは `{notification}` だけです。

修正提案: inventory を「数値 allowlist」ではなく、少なくとも次の分類にしてください。

- bigint: 数値制約が必要
- UUID: UUID 制約または安全な custom binder が必要
- custom binder: binder 内の入力正規化を gate で保証
- 非モデル文字列: 型制約対象外

`{oauthSession}` に既存の `whereUuid` または安全な binder がなければ、P1 の修正対象へ追加してください。既に対策済みなら、具体的な対策箇所を除外理由に明記してください。

[Warning] 「未知の数値 PK param を検出する」という gate の成立方法がまだ曖昧です。route 定義だけから、その param がモデル binding かつ bigint PK かを完全には判定できません。

修正提案: inventory gate を「全 binding param の分類漏れを禁止する total inventory」として定義してください。未知 param が現れたら、数値と推測するのではなく、型・解決方式・除外理由の登録を要求する構成が堅実です。

## 4. 期待効果の妥当性

[Warning] `no-store` によってブラウザの bfcache 再表示を常に「保証する」という表現は強すぎます。ブラウザ実装によって bfcache とキャッシュ指示の扱いが異なるため、HTTP ヘッダだけで全ブラウザの挙動を断定できません。

修正提案: 成果指標を「認証済み応答に再利用禁止ヘッダを付与する」「サポート対象ブラウザの代表 E2E で再表示されない」に分けてください。

## 5. リスク

[Warning] P3 の「既に `no-store` を持つ応答は untouched」だけでは、`public, no-store` のような矛盾した既存値も温存します。

修正提案: 既存4経路の期待値を個別テストで固定し、baseline middleware は既存ヘッダを上書きしない契約だと明示してください。矛盾ヘッダの一般的な正規化まで今回のスコープへ広げる必要はありません。

## 6. スコープの適切さ

[Suggestion] `T-a` / `T-b` / `T-c` への分割は適切です。特に P2 の運用文書を P5 から前倒しした判断により、空 registry だけが先行する期間もなくなっています。

## 7. 型安全性

[Suggestion] 新しい API payload はなく、DTO/JsonResource 規約への抵触はありません。P3 の Response 型明示と純粋な判定メソッドへの分離も、PHPStan level 10 に適した方針です。

承認に必要な主修正は、`{oauthSession}` を含む UUID binding の再分類と、P3 の Feature テスト・実ブラウザテストの責務分離です。