<?php

declare(strict_types=1);

use App\Enums\EnterpriseSso\OidcConnectionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 組織の OIDC 接続。1 組織に複数の接続を許す (合併・複数 IdP の企業がある)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_oidc_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // 公開のログイン導線 (/enterprise/{connection}/redirect) で使う識別名。
            // ★**全体で一意**であり、**推測されてよい**。推測可能性に依存した防御を持たない —
            //   防御は接続の状態 (Active か) と state / PKCE / ブラウザ結合が担う。
            // ★列名を `slug` にしない。`organizations.slug` の書き込み経路を 1 本に絞る gate
            //   (`OrganizationSlugWritePathTest`) は「`slug` 列を持つ表は organizations だけ」を
            //   前提にキー名だけで表を特定している。同名の列を別の表に足すとその前提が崩れる。
            $table->string('login_slug', 64)->unique();

            $table->string('display_name', 100);

            // 顧客が入力する。https 必須・userinfo/query/fragment なし・正規化できる絶対 URL。
            $table->string('issuer', 255);
            $table->string('client_id', 255);

            // ★暗号化して保存する。読み出しは ConnectionSecret 値型を経由し、
            //   平文の取り出しは token 交換だけが呼ぶ 1 メソッドに集約する。索引を持たせない。
            $table->text('client_secret_encrypted');

            $table->string('status', 16)->default(OidcConnectionStatus::Draft->value);
            $table->timestamp('verified_at')->nullable();

            // ★**認証材料の版**。issuer / client_id / client_secret のいずれかが変わるたびに +1 する。
            //   用途は 1 つだけ — D1 の `verify` が「**外向き取得の間に認証材料が変わっていないこと**」を
            //   ロックの中で確かめるための比較子である。
            //   ★**`updated_at` で代用しない**: 時刻の精度で同一に見えうるうえ、
            //     認証に関与しない表示名の更新まで巻き込んで verify を落とす。
            $table->unsignedBigInteger('credentials_revision')->default(1);

            $table->timestamps();

            // 組織単位の検索用。
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_oidc_connections');
    }
};
