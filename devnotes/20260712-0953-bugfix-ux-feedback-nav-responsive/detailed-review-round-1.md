## 全体判定
**CHANGES_REQUESTED**

全体として方針は良く、A/B/C いずれも North Star と「UXのみでドメイン非変更」の制約に整合しています。  
ただし **A1 のテスト設計に1点クリティカルな不足**、および **B/C に数点の回帰・網羅性リスク**があるため、このまま実装着手は非推奨です。

---

## 施策A1（認証メール再送 `status`→`success`）
**判定: REQUEST_CHANGES**

- [Critical] **`/email/verification-notification` のFeatureテスト前提が不足**
  - 問題: 未認証ユーザーに対する再送は `auth` + （実装によっては）`throttle` の影響を受けるため、`actingAs` だけでは不安定化する余地があります。設計書のテストケースに「誰として」「どのミドルウェア条件で」通すかの固定が不足。
  - 修正案: `FortifyResponseTest` で以下を明示  
    - `actingAs(User::factory()->unverified()->create())`  
    - `from('/email/verify')->post('/email/verification-notification')`  
    - `assertRedirect('/email/verify')`  
    - `assertSessionHas('success', '認証メールを再送信しました。')`  
    - `assertSessionMissing('status')`  
    - 必要なら `withoutMiddleware(ThrottleRequests::class)` ではなく、**1回実行で throttle に触れない設計**を優先（抑制は最終手段）
- [Warning] `wantsJson` 採用は妥当だが、既存 Fortify 応答群に `expectsJson` があるため、**「A1だけ wantsJson を採る理由」**をテストコメントにも残すと将来の統一リファクタで誤変換を防げます。
  - 修正案: テスト名かコメントに「Fortify元実装互換のため wantsJson/202 を維持」と明記。

---

## 施策A2（forgot-password `status`→`success`）
**判定: APPROVE**

- [Warning] F-06 の要件（enumeration抑止）上、**同一メッセージだけでなく同一キー**を保証するのは正しいです。ここは設計どおり `assertSessionMissing('status')` を両ケースで入れるべき。
  - 修正案: 既存/不存在メール双方で `success` の一致＋`status` 不在を対で検証。
- [Suggestion] `STATUS_MESSAGE` 名称維持の判断は差分最小として妥当。将来混乱を防ぐため class doc に「message内容の意味であり flash key の意味ではない」と1行追記すると良いです。

---

## 施策B（AppLayoutへ設定/ログアウト常設化）
**判定: REQUEST_CHANGES**

- [Warning] **`SharedProps` キャスト強化自体は良いが、`notifications` の遅延共有（closure）との整合をテストで固定すべき**。
  - 修正案: `AppLayout.test.ts` の mocked `page.props` で `notifications.unreadCount` を数値で与えるケースに加え、未定義ケースでもクラッシュしないことを1ケース追加。
- [Warning] **`headerActions` を残したまま常設ナビ追加**は拡張性として正しいが、将来ページ側で同じ「設定/ログアウト」を再注入したとき重複表示の回帰余地があります。
  - 修正案: `AppLayout.test.ts` に「`headerActions` があっても `nav-settings/nav-logout` は1つずつ」のアサーションを追加。
- [Suggestion] `Dashboard.test.ts` で「ローカル logout 実装の削除」を構造的に担保するなら、DOM確認に加えて `router.post('/logout')` がDashboard内イベントから直接呼ばれないこと（= AppLayout責務）をテスト意図として明記するとよいです。

---

## 施策C（Admin Users レスポンシブ）
**判定: APPROVE**

- [Warning] jsdom では横スクロール実測不能のため、クラス不変条件テストは妥当。ただし **対象要素の特定が脆い**（`li` の順序依存）になりがち。
  - 修正案: `data-testid` 既存キー（`member-list`, `invitation-list`, `member-role-*` 等）起点で対象行を辿る実装にし、DOM順序依存を避ける。
- [Suggestion] `sm:shrink-0 sm:justify-end` は desktop 互換に有効。設計書に「`min-w-0`維持で長文メール省略を継続」を明記済みで良いです（この点は後退防止に効く）。

---

## 横断レビュー（観点 1〜11）

- セキュリティ不変条件: 認可・tenant・IDOR・PII いずれも今回スコープ外で、**悪化は見えません**。F-06 enumeration 抑止はむしろ強化方向。
- DTO/JsonResource: Fortify contract response の例外運用として一貫。
- Inertia vs API: Webはflash、JSONはFortify契約維持で適切。
- PHPStan Lv10: 提案シグネチャは問題なし。TS側も `SharedProps` 利用で改善方向。
- 波及変更: テスト対象は概ね妥当。ただし上記 B の重複/未定義ケースを追加して完成度を上げるべき。

---

## 結論
- **A2/C は実装着手可**
- **A1/B は上記修正を設計に反映してから着手推奨**
- したがって最終判定は **CHANGES_REQUESTED** です。