コマンド実行は禁止条件のため未実行です。以下は提示 diff と設計書だけに基づくレビューです。

**ファイル別判定**
`app/Services/Manual/ManualKeywordSearch.php`  
判定: OK。検索対象、正規化、LIKE メタ文字の扱い、OR 漏れ防止の group 化は設計どおりです。`orWhereHas` が relation 制約の外へ漏れない形になっており、cross-project / cross-org の主要リスクは抑えられています。

`app/DataTransferObjects/Manual/ManualListQuery.php`  
判定: OK。正規化の正本を `ManualKeywordSearch::normalize()` に寄せており、旧定数の並走も残していません。

`app/Http/Controllers/Projects/ProjectController.php`  
判定: OK。既存 filter の後に group 化済み述語を積んでおり、`mine` / `category` / `progress` / project relation 制約との AND 関係は維持されています。

`app/Http/Controllers/Capture/CaptureManualController.php`  
判定: OK。PWA 側も PC と同じ正規化・述語に寄っています。`ready/published` の母集団制限も OR に押し出されない形です。

`database/migrations/2026_08_17_000000_add_video_manual_id_index_to_cuts_table.php`  
判定: OK。FK 列索引追加は設計どおりで、`down()` も Laravel 既定名に対応しています。

`resources/js/lib/manual/search.ts`  
判定: OK。共有定数のみで、design token / Atomic Design / SVG 追加の問題はありません。

`resources/js/pages/Capture/Index.svelte`  
判定: OK。既存 `Input` atom への placeholder 追加だけで、DESIGN.md 逸脱は見当たりません。

`resources/js/pages/Projects/Show.svelte`  
判定: OK。同上です。

`tests/Unit/Manual/ManualKeywordSearchTest.php`  
[Suggestion] 全角空白のコメントが実際の期待値と少し食い違っています。`trim()` は全角空白を除かず、期待値も `'　'` のままなので、コメント先頭の「全角空白も trim される」は「全角空白は trim されない」に直すと読み手が迷いません。挙動自体は設計コードと一致しています。

`tests/Feature/Manual/ManualKeywordSearchBoundaryTest.php`  
判定: OK。OR 漏れの負のコントロールを直接 DB 結果で見ており、今回の最重要リスクに対するテストとして妥当です。

`tests/Feature/Projects/ProjectShowManualsTest.php`  
判定: OK。本文 4 列、対象外列、LIKE メタ文字、重複防止、既存 filter との AND、ページ丸めまで見られています。

`tests/Feature/Capture/CaptureManualBrowsingTest.php`  
判定: OK。PWA 側の本文検索、対象外列、母集団制限、正規化が押さえられています。

`tests/Feature/Projects/ManualListQueryCountTest.php` / `tests/Feature/Capture/CaptureManualListQueryCountTest.php`  
判定: OK。検索追加が N+1 に退行していないことをクエリ数で固定しています。

`tests/Feature/Database/CutsIndexTest.php`  
判定: OK。索引名と列を固定しており、migration が作るべきものを直接見ています。

`tests/js/pages/CaptureIndex.test.ts` / `tests/js/pages/ProjectsShow.test.ts`  
判定: OK。両画面の placeholder が共有定数値に一致することを固定しています。

`docs/TODO-closed.md`  
判定: OK。T053 の実態訂正で、今回の実装完了後に入れる内容として妥当です。

APPROVED