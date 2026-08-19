<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Ci\TestDatabaseEnv;

/*
|--------------------------------------------------------------------------
| 基点テスト DB が最新スキーマであること (家系の裁定 AG-135)
|--------------------------------------------------------------------------
|
| Architecture lane は tests/Pest.php で RefreshDatabase を付けていない
| (ファイル走査中心で DB を使わないため)。並列実行では DB 系 trait を使うテストのときだけ
| worker DB へ切り替わるので、この lane が読むのは基点 DB そのものである。
| したがって「基点 DB が作られただけでスキーマが当たっていない」状態は、この lane で
| 再現しにくい失敗 (実行順で結果が変わる偽 green) として現れる。
| その状態をその場所で観測するのが本テストである。
|
| ■ RefreshDatabase を付けない
|   付けると自分でスキーマを作ってしまい、「準備が済んでいるか」の検査が成立しない。
|   前提が変わったときに黙って空洞化しないよう、B-0 が前提そのものを検査する。
|
| ■ 接続は Laravel を使わず PDO で直接張る
|   並列実行で接続先が worker DB へ切り替わっていても、見る対象を常に基点 DB に固定するため。
|
| ■ B-2 の到達確認基準は scripts/ci/ensure-test-db.php と**同じ関数**
|   (pgsqlTestMigrationFileNames / pgsqlTestSchemaUnappliedMigrations) を使う。
|   これは「独立した二重検証」ではなく「判定基準を 1 か所 (pgsql_test_conn.php) へ
|   一元化した」ものである — スクリプトと検査で判定がずれると「準備は成功したのに
|   このテストだけ落ちる」逆転が起き得るため、判定基準そのものは共有し、
|   「その基準どおりに基点 DB が本当になっているか」を実 DB への直接接続で確かめる
|   (Unit テスト側 (tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php) が
|   判定関数自体の入出力を purely に固定する独立した裏取りに当たる)。
|
| ■ 本テストが保証しないこと
|   「基点 DB が最新である」ことの観測であって、それを scripts/ci/ensure-test-db.php が
|   行った証明ではない (非並列の直接実行では RefreshDatabase の migrate:fresh が
|   同じ状態を作る)。
|
*/

// ファイル読み込み時点では Laravel が起動していないので base_path() を使わない。
require_once __DIR__.'/../../scripts/ci/pgsql_test_conn.php';

test('B-0: 本テストに RefreshDatabase が適用されていない (検査の前提)', function (): void {
    expect(class_uses_recursive($this))->not->toContain(RefreshDatabase::class);
});

test('B-1: 基点テスト DB に migrations 表が存在する', function (): void {
    $base = TestDatabaseEnv::pgsqlBaseDatabase(base_path());
    $pdo = pgsqlTestDatabasePdo(base_path(), $base);

    $table = $pdo->query("SELECT to_regclass('public.migrations')")->fetchColumn();

    expect($table)->toBe('migrations', "基点 DB {$base} に migrations 表が無い (準備がスキーマ更新まで行っていない)");
});

test('B-2: database/migrations の全ファイルが基点テスト DB に適用済みである', function (): void {
    $base = TestDatabaseEnv::pgsqlBaseDatabase(base_path());
    $pdo = pgsqlTestDatabasePdo(base_path(), $base);

    /** @var list<string> $applied */
    $applied = $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);

    $files = glob(base_path('database/migrations/*.php'));
    expect($files)->toBeArray()->not->toBeEmpty();

    $expected = pgsqlTestMigrationFileNames($files);

    // 比較の向きは包含 (ファイル -> 表) にする。scripts/ci/ensure-test-db.php の
    // 到達確認と同じ関数 (pgsqlTestSchemaUnappliedMigrations) を使うことで、
    // vendor パッケージ由来の migration が表に増えても (集合の一致を求めないため) 落ちず、
    // かつスクリプトと検査の判定がずれない。
    expect(pgsqlTestSchemaUnappliedMigrations($applied, $expected))
        ->toBe([], "基点 DB {$base} に未適用の migration が残っている");
});
