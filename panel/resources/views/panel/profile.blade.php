@extends('layouts.dashboard')

@section('title', __('profile.title') . ' — ' . setting('hosting.branding.name'))

@section('content')
    @php $user = $user ?? auth()->user(); @endphp

    <div class="mb-6">
        <h1 class="text-2xl font-bold">{{ __('profile.title') }}</h1>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <!-- Основная форма -->
        <div class="lg:col-span-2 space-y-4">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('profile.title') }}</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('panel.profile.update') }}" class="space-y-4">
                        @csrf
                        @method('PUT')

                        <div class="grid sm:grid-cols-2 gap-4">
                            <div>
                                <label class="label">{{ __('common.name') }}</label>
                                <input type="text" name="name" value="{{ old('name', $user->name) }}"
                                       required class="input" maxlength="60">
                                @error('name') <p class="error">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="label">Username</label>
                                <input type="text" name="username" value="{{ old('username', $user->username) }}"
                                       class="input" maxlength="32">
                                <p class="hint">Используется в ссылках и упоминаниях.</p>
                                @error('username') <p class="error">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="label">Email</label>
                                <input type="email" name="email" value="{{ old('email', $user->email) }}"
                                       required class="input" maxlength="190">
                                @if (! $user->isEmailVerified())
                                    <p class="hint text-amber-400">Не подтверждён</p>
                                @endif
                                @error('email') <p class="error">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="label">{{ __('profile.telegram') }}</label>
                                <input type="text" name="contact_telegram"
                                       value="{{ old('contact_telegram', $user->contact_telegram) }}"
                                       class="input" maxlength="64" placeholder="@nickname">
                                @error('contact_telegram') <p class="error">{{ $message }}</p> @enderror
                            </div>

                            <div>
                                <label class="label">{{ __('profile.language') }}</label>
                                <select name="locale" class="select">
                                    @foreach (setting_array('hosting.locale.available', ['ru' => 'Русский', 'en' => 'English']) as $code => $label)
                                        <option value="{{ $code }}" @selected(old('locale', $user->locale ?? app()->getLocale()) === $code)>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div>
                                <label class="label">{{ __('profile.timezone') }}</label>
                                <select name="timezone" class="select">
                                    @foreach (\DateTimeZone::listIdentifiers() as $tz)
                                        <option value="{{ $tz }}" @selected(old('timezone', $user->timezone ?? config('app.timezone')) === $tz)>
                                            {{ $tz }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="label">{{ __('profile.about') }}</label>
                            <textarea name="about" rows="3" class="input" maxlength="1000">{{ old('about', $user->about) }}</textarea>
                        </div>

                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="newsletter" value="1" class="checkbox" @checked($user->newsletter)>
                            Присылать новости и акции
                        </label>

                        <button class="btn btn-primary btn-sm">{{ __('common.save') }}</button>
                    </form>
                </div>
            </div>

            <!-- Пароль -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('profile.change_password') }}</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('panel.profile.password') }}" class="space-y-4 max-w-md">
                        @csrf
                        @method('PUT')

                        <div>
                            <label class="label">Текущий пароль</label>
                            <input type="password" name="current_password" required class="input" autocomplete="current-password">
                            @error('current_password') <p class="error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="label">{{ __('profile.new_password') }}</label>
                            <input type="password" name="password" required class="input" autocomplete="new-password">
                            @error('password') <p class="error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="label">{{ __('profile.confirm_password') }}</label>
                            <input type="password" name="password_confirmation" required class="input" autocomplete="new-password">
                        </div>

                        <button class="btn btn-primary btn-sm">{{ __('common.save') }}</button>
                    </form>
                </div>
            </div>

            <!-- Ссылки -->
            <div class="card">
                <div class="card-header"><h2 class="card-title">Интеграции</h2></div>
                <div class="card-body grid sm:grid-cols-2 gap-3">
                    <a href="{{ route('panel.profile.api_tokens') }}" class="btn btn-secondary">
                        @include('partials.icon', ['name' => 'lock', 'class' => 'w-4 h-4'])
                        {{ __('profile.api_tokens') }}
                    </a>
                    <a href="{{ route('panel.profile.webhooks') }}" class="btn btn-secondary">
                        @include('partials.icon', ['name' => 'share', 'class' => 'w-4 h-4'])
                        {{ __('profile.webhooks') }}
                    </a>
                    <a href="{{ route('panel.security') }}" class="btn btn-secondary">
                        @include('partials.icon', ['name' => 'shield', 'class' => 'w-4 h-4'])
                        {{ __('nav.security') }}
                    </a>
                    <a href="{{ route('panel.security.sessions') }}" class="btn btn-secondary">
                        @include('partials.icon', ['name' => 'power', 'class' => 'w-4 h-4'])
                        {{ __('security.sessions') }}
                    </a>
                </div>
            </div>
        </div>

        <!-- Боковая колонка -->
        <div class="space-y-4">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">{{ __('profile.avatar') }}</h2>
                </div>
                <div class="card-body text-center">
                    @if ($user->avatar_url)
                        <img src="{{ $user->avatar_url }}" alt="" class="w-24 h-24 rounded-full mx-auto mb-4 object-cover">
                        <form method="POST" action="{{ route('panel.profile.avatar.delete') }}">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-ghost btn-sm text-red-400">{{ __('common.delete') }}</button>
                        </form>
                    @else
                        <div class="w-24 h-24 rounded-full mx-auto mb-4 grid place-items-center bg-ink-800 text-2xl font-semibold text-ink-400">
                            {{ $user->initials }}
                        </div>
                    @endif

                    <form method="POST" action="{{ route('panel.profile.avatar') }}"
                          enctype="multipart/form-data" class="mt-4">
                        @csrf
                        <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required
                               class="input !py-1.5 !text-xs">
                        <button class="btn btn-primary btn-sm w-full mt-2">{{ __('common.upload') }}</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2 class="card-title">{{ __('profile.stats') }}</h2></div>
                <div class="card-body space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-ink-400">ID</span>
                        <span class="font-mono">{{ $user->id }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-ink-400">{{ __('servers.title') }}</span>
                        <span>{{ $stats['servers'] }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-ink-400">{{ __('nav.referrals') }}</span>
                        <span>{{ $stats['referrals'] }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-ink-400">{{ __('common.status') }}</span>
                        <span class="badge-{{ $user->statusColor() }}">{{ $user->statusLabel() }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-ink-400">{{ __('servers.tariff') }}</span>
                        <span>{{ $user->roleLabel() }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-ink-400">{{ __('profile.registered') }}</span>
                        <span>{{ $stats['registered'] ?? '—' }}</span>
                    </div>
                    <div class="divider"></div>
                    <a href="{{ route('panel.billing') }}" class="flex justify-between text-brand-400 hover:text-brand-300">
                        <span>{{ __('nav.balance') }}</span>
                        <span class="font-medium">{{ money($user->balance) }}</span>
                    </a>
                </div>
            </div>
        </div>
    </div>
@endsection
