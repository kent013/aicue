<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * IdP の身元 (接続 × subject) と利用者の対応。
 *
 * ★**メールアドレスで利用者を引かない**。引き当ての鍵は
 *   (organization_oidc_connection_id, 生の subject) だけである。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enterprise_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_oidc_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // ★IdP の subject。**これが身元の主キーである**。
            //   照合を **COLLATE "C" (バイト単位)** に固定する — 既定の照合順序では
            //   `Alice` と `alice` が同一視されうる環境があり、そうなると
            //   **別人が同じアカウントに入る**。
            //   ★指紋 (HMAC) にはしない。指紋は鍵に依存するので、APP_KEY をローテートすると
            //     既存の身元へ到達できなくなり**アカウントが分裂する**。
            $table->string('subject', 255)->collation('C');

            // ★申告メール: 暗号化して持つが **索引を意図的に付けない**。
            //   索引を付けると「メールで引ける」経路が実装として復活する。
            //   blind index も付けない (configureCipherSweet で addBlindIndex を呼ばない)。
            $table->text('claimed_email_encrypted')->nullable();

            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            // ★**最後の防波堤**である。競合制御の本体は C2 が張る接続の行ロックであり、
            //   C1 はこの制約違反を**捕まえない**。
            $table->unique(
                ['organization_oidc_connection_id', 'subject'],
                'enterprise_identities_connection_subject_unique',
            );

            $table->index('user_id');
        });

        // ★CHECK 制約は Blueprint に API が無いので**生 SQL で置く**。
        //   pgsql 固定でよい (phpunit.xml が DB_CONNECTION=pgsql を force しており、テストも本番も pgsql)。
        //   ★**制約名を明示する** — 違反したときに出所が一目で分かり、
        //   スキーマ読み取りテストが `pg_constraint.conname` を名前で引ける。
        //   ★pgsql の `varchar(255)` は 255 **文字**であってバイトではないので、
        //   バイト長は CHECK で別に閉じる。
        DB::statement(<<<'SQL'
            ALTER TABLE enterprise_identities
                ADD CONSTRAINT enterprise_identities_subject_octet_length_check
                CHECK (octet_length(subject) BETWEEN 1 AND 255)
            SQL);

        // ★制御文字の禁止も **DB の不変条件に含める** (DTO だけの保証にしない)。
        //   ★**名前を分ける** — 長さ違反と文字種違反を、違反の名前だけで切り分けられるようにする。
        //   対象は C0 制御文字 (U+0001〜U+001F) と DEL (U+007F) **だけ**である
        //   (U+0000 は pgsql の text/varchar に格納できないので書く必要が無い。
        //    C1 制御文字と Unicode の書式文字は**対象外**で、これらは許す)。
        DB::statement(<<<'SQL'
            ALTER TABLE enterprise_identities
                ADD CONSTRAINT enterprise_identities_subject_no_control_chars_check
                CHECK (subject !~ E'[\\x01-\\x1F\\x7F]')
            SQL);
    }

    public function down(): void
    {
        // 表ごと落とすので CHECK 制約も一緒に消える。
        Schema::dropIfExists('enterprise_identities');
    }
};
