<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $sender }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#18181b;">
    @if ($preheader)
        <div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ $preheader }}</div>
    @endif
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;">
        <tr>
            <td align="center" style="padding:24px 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:12px;">
                    <tr>
                        <td style="padding:28px 28px 8px;font-size:16px;line-height:1.6;">
                            <div class="content" style="word-wrap:break-word;">
                                {!! str_replace(
                                    ['<img ', '<a ', '<h1>', '<h2>', '<h3>'],
                                    ['<img style="max-width:100%;height:auto;border-radius:8px;" ', '<a style="color:#4f46e5;" ', '<h1 style="font-size:24px;line-height:1.3;margin:0 0 16px;">', '<h2 style="font-size:20px;line-height:1.3;margin:24px 0 12px;">', '<h3 style="font-size:17px;margin:20px 0 8px;">'],
                                    $html
                                ) !!}
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:16px 28px 28px;font-size:12px;line-height:1.5;color:#71717a;border-top:1px solid #f4f4f5;">
                            You are receiving this because you joined {{ $sender }}’s mailing list.
                            @if ($profileUrl)<a href="{{ $profileUrl }}" style="color:#71717a;">Visit {{ $sender }}</a> ·@endif
                            <a href="{{ $unsubscribeUrl }}" style="color:#71717a;">Unsubscribe</a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
    @if ($openUrl)
        <img src="{{ $openUrl }}" width="1" height="1" alt="" style="display:block;width:1px;height:1px;border:0;">
    @endif
</body>
</html>
