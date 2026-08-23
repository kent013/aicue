<?php

declare(strict_types=1);

namespace Tests\Support\LegacyUrl;

/**
 * 旧 URL 検出の許可区分。
 *
 * ★区分は**限定列挙**である。「なんとなく直せない」を入れる口を作らない。
 *   新しい区分を足す操作そのものがレビューに見えることが目的である。
 */
enum LegacyUrlAllowanceKind: string
{
    /**
     * URL ではなく**保存先のパス**である (ファイルシステム / オブジェクトストレージの鍵)。
     *
     * 走査根を組み立てる `dirname(__DIR__, 2).'/app/Prompts'` や、
     * 保存先の鍵 `orgs/{org}/projects/…` のような形は、字面が URL の根と一致するだけで
     * 画面の経路ではない。
     */
    case FilesystemPath = 'filesystem_path';

    /**
     * 旧 URL が**もう存在しないこと自体を確かめている**記述。
     *
     * 「この URL は 404 になる」ことを固定するテストは、対象の旧 URL を持つのが役目である。
     */
    case AbsenceAssertion = 'absence_assertion';

    /**
     * **組織相対パス**として宣言された値で、組織 prefix は利用側が付ける。
     *
     * 静的な表 (画面から slug を受け取れない定数) が持つ相対パスがこれに当たる。
     * 登録するときは「利用側が必ず組織 URL の入口を通す」ことを同じ変更で確かめること
     * (通していなければそれは旧 URL であり、許可ではなく修正が要る)。
     */
    case OrganizationRelativePath = 'organization_relative_path';
}
