<div style="font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; background:#0d0f14; color:#e6eaf1; padding:32px; line-height:1.6;">
    <div style="max-width:520px; margin:0 auto;">

        <div style="font-size:20px; font-weight:600; margin-bottom:24px; color:#fff;">
            {{ setting('hosting.branding.name', 'GameDock') }}
        </div>

        <p style="color:#c5ccd8;">{{ __('emails.greeting', ['name' => $user->name]) }}</p>

        <p style="color:#c5ccd8;">{{ __('emails.verify_text') }}</p>

        <div style="margin:28px 0; text-align:center;">
            <a href="{{ $link }}"
               style="display:inline-block; background:#6366f1; color:#fff; text-decoration:none;
                      padding:12px 28px; border-radius:8px; font-weight:600;">
                {{ __('emails.verify_button') }}
            </a>
        </div>

        <p style="color:#98a1b3; font-size:13px; text-align:center; margin-bottom:28px;">
            {{ __('emails.link_expires') }}
        </p>

        <div style="background:#161a21; border:1px solid #232935; border-radius:10px; padding:20px; margin-bottom:28px;">
            <p style="color:#98a1b3; font-size:13px; margin:0 0 8px;">{{ __('emails.verify_code_text') }}</p>
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
