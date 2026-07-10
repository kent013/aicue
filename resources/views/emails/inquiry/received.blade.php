新しいお問い合わせを受け付けました。

種別: {{ $typeLabel }}
経路: {{ $sourceLabel ?? '-' }}
受付日時: {{ $receivedAt ?? '-' }}
氏名: {{ $name }}
メール: {{ $email }}
@if ($companyName)
会社・組織名: {{ $companyName }}
@endif

お問い合わせ内容:
{{ $inquiryMessage }}

管理画面で確認: {{ $recordUrl }}
