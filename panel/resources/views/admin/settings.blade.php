@extends('layouts.dashboard')

@section('title', __('nav.settings') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold">{{ __('nav.settings') }}</h1>
            <p class="mt-1 text-sm text-ink-400">
                Значения из базы перекрывают <code>config/hosting.php</code> на лету.
            </p>
        </div>

        <form method="POST" action="{{ route('admin.settings.cache') }}">
            @csrf
            <button class="btn btn-secondary btn-sm">{{ __('common.refresh') }} / очистить кэш</button>
        </form>
    </div>

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-3">
            @foreach ($groups as $group => $settings)
                <a href="#group-{{ $group }}"
                   class="block px-4 py-3 rounded-lg border text-sm transition
                          {{ $loop->first ? 'border-brand-500 bg-ink-850 text-ink-100' : 'border-ink-700 text-ink-400 hover:border-ink-600 hover:text-ink-200' }}">
                    {{ $settings->first()->label ? Str::before($settings->first()->label, '.') : $group }}
                    <span class="float-right text-xs text-ink-500">{{ $settings->count() }}</span>
                </a>
            @endforeach
        </div>

        <div class="lg:col-span-2 space-y-4">
            @foreach ($groups as $group => $settings)
                <div class="card" id="group-{{ $group }}">
                    <div class="card-header">
                        <h2 class="card-title font-mono text-sm">{{ $group }}</h2>
                        <span class="text-xs text-ink-500">{{ $settings->count() }} параметров</span>
                    </div>

                    <form method="POST" action="{{ route('admin.settings.update', $group) }}">
                        @csrf
                        @method('PUT')

                        <div class="card-body space-y-4">
                            @foreach ($settings as $setting)
                                <div @class(['sm:col-span-2' => $setting->type === 'bool'])>
                                    @if ($setting->type === 'bool')
                                        <label class="flex items-center gap-2 text-sm">
                                            <input type="hidden" name="settings[{{ $setting->key }}]" value="0">
                                            <input type="checkbox" name="settings[{{ $setting->key }}]" value="1"
                                                   class="checkbox" @checked((bool) $setting->castValue())>
                                            <span class="text-ink-200">{{ $setting->label ?? $setting->key }}</span>
                                        </label>
                                    @elseif ($setting->type === 'password')
                                        <label class="label">{{ $setting->label ?? $setting->key }}</label>
                                        <input type="password" name="settings[{{ $setting->key }}]" class="input"
                                               value="" placeholder="{{ $setting->value ? '••••••••' : '—' }}" autocomplete="new-password">
                                    @elseif ($setting->type === 'json')
                                        <label class="label">{{ $setting->label ?? $setting->key }}</label>
                                        <textarea name="settings[{{ $setting->key }}]" rows="3"
                                                  class="input font-mono !text-xs"
                                                  spellcheck="false">{{ $setting->value }}</textarea>
                                    @elseif (! empty($setting->options))
                                        <label class="label">{{ $setting->label ?? $setting->key }}</label>
                                        <select name="settings[{{ $setting->key }}]" class="select">
                                            @foreach ((array) $setting->options as $value => $optionLabel)
                                                <option value="{{ $value }}"
                                                        @selected((string) $setting->value === (string) $value)>
                                                    {{ is_array($optionLabel) ? ($optionLabel['label'] ?? $value) : $optionLabel }}
                                                </option>
                                            @endforeach
                                        </select>
                                    @else
                                        <label class="label">{{ $setting->label ?? $setting->key }}</label>
                                        <input type="{{ $setting->type === 'int' || $setting->type === 'float' ? 'number' : 'text' }}"
                                               name="settings[{{ $setting->key }}]" class="input"
                                               value="{{ $setting->value }}"
                                               @if ($setting->type === 'float') step="0.01" @endif>
                                    @endif

                                    @if ($setting->hint)
                                        <p class="hint">{{ $setting->hint }}</p>
                                    @endif
                                    <p class="text-[10px] text-ink-600 font-mono">{{ $setting->key }}</p>
                                </div>
                            @endforeach
                        </div>

                        <div class="px-5 py-3 border-t border-ink-800 flex items-center gap-2">
                            <button class="btn btn-primary btn-sm">{{ __('common.save') }}</button>
                            <span class="text-xs text-ink-500">
                                Изменения применятся сразу и запишутся в журнал аудита.
                            </span>
                        </div>
                    </form>
                </div>
            @endforeach
        </div>
    </div>
@endsection
