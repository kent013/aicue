# 概念設計レビュー Round 4

Round 3 の Warning 3点を反映しました。最終確認をお願いします（新たな穴が無ければ APPROVED を明示ください）。

## Round 3 指摘への対応

1. **[Warning] 設計根拠バージョン** → 対応。`composer.lock` は `laravel/framework` **v13.18.0** を
   ピン（テンプレ記載「Laravel 12」から更新済み）。以降のソース参照はこの実バージョンを正本と明記。
   `logoutOtherDevices` の条件付き recaller 再発行（L748-750）は v13.18.0 で確認済み。

2. **[Warning] session.connection は string|null** → 対応。`config/session.php` L76 は
   `env('SESSION_CONNECTION')` = 未設定時 null（既定接続）。設計を
   `Assert::nullOrString($connection)` + `Assert::string($table)` →
   `DB::connection($connection)->table($table)` に修正。

3. **[Warning] best-effort の例外処理** → 対応。session 削除**のみ** `try/catch` し `report($e)` して
   正常応答維持。correctness は層1（AuthenticateSession）が担保。

4. **[Warning] Filament 二重適用** → 反論・検証。`AdminPanelProvider` は panel 専用 middleware stack
   （StartSession/AuthenticateSession を独自列挙）で web グループを経由しないため二重適用なし。設計に明記。

5. **[Suggestion] 表現修正** → 「保存済みの他 session 行を直ちに削除し新規リクエストでの利用を抑止。
   並行書き戻し行は AuthenticateSession が次回利用時に拒否」「セキュリティ不変条件を Feature テストで
   固定」に修正。主要フロー影響評価（guest no-op / 終端 hash 保存）を制約・前提へ取り込み。

以上で Round 1〜3 の Critical/Warning は全て解消したと考えます。問題なければ APPROVED を、残課題が
あれば指摘をお願いします。
