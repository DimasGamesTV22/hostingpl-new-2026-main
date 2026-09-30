<div style="font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; background:#0d0f14; color:#e6eaf1; padding:32px; line-height:1.6;">
    <div style="max-width:520px; margin:0 auto;">

        <div style="font-size:20px; font-weight:600; margin-bottom:24px; color:#fff;">
            {{ setting('hosting.branding.name', 'GameDock') }}
        </div>

        <p style="color:#c5ccd8;">{{ __('emails.greeting', ['name' => $user->name]) }}</p>

        <p style="color:#c5ccd8;">{{ __('emails.welcome_text') }}</p>

        <div style="margin:28px 0; text-align:center;">
            <a href="{{ $panelUrl }}"
               style="display:inline-block; background:#6366f1; color:#fff; text-decoration:none;
                      padding:12px 28px; border-radius:8px; font-weight:600;">
                {{ __('emails.panel_button') }}
            </a>
        </div>

        <p style="color:#6b7488; font-size:13px;">
            {{ __('emails.ignore') }}<br>
            {{ __('emails.footer') }}
        </p>

    </div>
</div>
