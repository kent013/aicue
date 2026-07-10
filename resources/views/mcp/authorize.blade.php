{{-- MCP OAuth consent 画面。
   Passport::authorizationView (McpPassportServiceProvider) で差替済。
   authorizationView closure が以下の変数を注入する:
   - $client: OAuth client
   - $request: authorize request (state などを含む)
   - $authToken: approve endpoint 用の CSRF 相当トークン
   - $user: 認証済 User
   - $organizations: ログインユーザーが所属する組織の配列化済コレクション

   Inertia / Vite に依存しない standalone Blade (外部 OAuth client の consent は
   アプリ本体の SPA shell を必要としないため)。
--}}
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <title>{{ config('app.name') }} 接続許可</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; background: #f9fafb; margin: 0; padding: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card { max-width: 32rem; width: 100%; background: #fff; padding: 2rem; border-radius: 0.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.12); }
        h1 { font-size: 1.25rem; font-weight: 700; margin: 0 0 0.5rem; color: #111827; }
        .muted { font-size: 0.875rem; color: #6b7280; }
        label { display: block; font-size: 0.875rem; font-weight: 500; color: #374151; margin-bottom: 0.25rem; }
        select, .fixed-org { display: block; width: 100%; padding: 0.5rem 0.75rem; border: 1px solid #d1d5db; border-radius: 0.375rem; background: #fff; font-size: 0.875rem; color: #111827; box-sizing: border-box; }
        .fixed-org { background: #f3f4f6; }
        ul { padding-left: 1.25rem; margin: 0.5rem 0 0; }
        li { font-size: 0.875rem; color: #374151; padding: 0.125rem 0; }
        .btn-row { display: flex; gap: 0.5rem; margin-top: 1rem; }
        button { flex: 1; padding: 0.625rem 1rem; border-radius: 0.375rem; font-size: 0.875rem; font-weight: 600; border: none; cursor: pointer; }
        .btn-approve { background: #2563eb; color: #fff; }
        .btn-approve:hover { background: #1d4ed8; }
        .btn-deny { background: #e5e7eb; color: #374151; }
        .btn-deny:hover { background: #d1d5db; }
        .section { margin: 1rem 0; }
    </style>
</head>
<body>
<div class="card">
    <h1>{{ config('app.name') }} に接続を許可しますか？</h1>
    <p class="muted">アプリケーション: <strong>{{ $client->name }}</strong></p>

    <form method="POST" action="{{ route('passport.authorizations.approve') }}" class="section">
        @csrf
        <input type="hidden" name="state" value="{{ $request->state ?? '' }}">
        <input type="hidden" name="client_id" value="{{ $client->id }}">
        <input type="hidden" name="auth_token" value="{{ $authToken }}">

        <div class="section">
            <label for="organization_id">接続する組織</label>
            @if ($organizations->count() === 1)
                @php($only = $organizations->first())
                <input type="hidden" name="organization_id" value="{{ $only['id'] }}">
                <div class="fixed-org" data-testid="single-org-display">{{ $only['name'] }}</div>
            @else
                <select name="organization_id" id="organization_id" required data-testid="organization-select">
                    @foreach ($organizations as $org)
                        <option value="{{ $org['id'] }}">{{ $org['name'] }}</option>
                    @endforeach
                </select>
            @endif
        </div>

        <div class="section">
            <p style="font-weight: 500; margin: 0;">許可される操作（あなたの現在の権限の範囲で実行されます）:</p>
            <ul>
                @foreach ($scopes as $scope)
                    <li>{{ $scope->description }}</li>
                @endforeach
            </ul>
        </div>

        <div class="btn-row">
            <button type="submit" class="btn-approve" data-testid="approve-button">許可する</button>
        </div>
    </form>

    <form method="POST" action="{{ route('passport.authorizations.deny') }}">
        @csrf
        @method('DELETE')
        <input type="hidden" name="state" value="{{ $request->state ?? '' }}">
        <input type="hidden" name="client_id" value="{{ $client->id }}">
        <input type="hidden" name="auth_token" value="{{ $authToken }}">
        <button type="submit" class="btn-deny" data-testid="deny-button">拒否する</button>
    </form>
</div>
</body>
</html>
