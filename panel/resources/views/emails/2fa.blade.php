<div style="font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; background:#0d0f14; color:#e6eaf1; padding:32px; line-height:1.6;">
    <div style="max-width:520px; margin:0 auto;">

        <div style="font-size:20px; font-weight:600; margin-bottom:24px; color:#fff;">
            {{ setting('hosting.branding.name', 'GameDock') }}
        </div>

        <p style="color:#c5ccd8;">{{ __('emails.greeting', ['name' => $user->name]) }}</p>
        <p style="color:#c5ccd8;">{{ __('emails.two_factor_code_text') }}</p>

        <div style="background:#161a21; border:1px solid #232935; border-radius:10px; padding:20px; margin:28px 0;">
            <p style="font-size:30px; letter-spacing:12px; font-weight:700; color:#fff; margin:0; text-align:center;">
                {{ $code }}
            </p>
        </div>

        <p style="color:#6b7488; font-size:13px;">
            {{ __('emails.ignore') }}<br>
            {{ __('emails.footer') }}
        </p>

    </div>
</div>
