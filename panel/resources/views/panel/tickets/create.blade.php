@extends('layouts.dashboard')

@section('title', __('support.new_ticket') . ' — ' . setting('hosting.branding.name'))

@section('content')
    <div class="mb-6">
        <a href="{{ route('panel.tickets.index') }}" class="btn btn-ghost btn-sm mb-3">
            @include('partials.icon', ['name' => 'arrow-left', 'class' => 'w-4 h-4'])
            {{ __('support.tickets') }}
        </a>
        <h1 class="text-2xl font-bold">{{ __('support.new_ticket') }}</h1>
    </div>

    <div class="max-w-3xl">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="{{ route('panel.tickets.store') }}" class="space-y-4">
                    @csrf

                    <div>
                        <label class="label">{{ __('support.subject') }}</label>
                        <input type="text" name="subject" value="{{ old('subject') }}" required class="input"
                               maxlength="190" placeholder="Не могу зайти на сервер">
                        @error('subject') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid sm:grid-cols-3 gap-4">
                        <div>
                            <label class="label">{{ __('support.department') }}</label>
                            <select name="department_id" class="select">
                                <option value="">— {{ __('common.none') }} —</option>
                                @foreach ($departments as $department)
                                    <option value="{{ $department->id }}" @selected(old('department_id') == $department->id)>
                                        {{ $department->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('department_id') <p class="error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="label">{{ __('support.server') }}</label>
                            <select name="server_id" class="select">
                                <option value="">— {{ __('common.none') }} —</option>
                                @foreach ($servers as $server)
                                    <option value="{{ $server->id }}" @selected(old('server_id') == $server->id)>
                                        {{ $server->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('server_id') <p class="error">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="label">{{ __('support.priority') }}</label>
                            <select name="priority" class="select">
                                @foreach ($priorities as $priority)
                                    <option value="{{ $priority }}" @selected(old('priority', 'normal') === $priority)>
                                        {{ __('support.priority_labels.' . $priority) }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="label">{{ __('support.message') }}</label>
                        <textarea name="message" rows="10" required class="input font-mono !text-[13px]"
                                  maxlength="10000">{{ old('message') }}</textarea>
                        <p class="hint">
                            Опишите проблему как можно подробнее: адрес сервера, время, текст ошибки из консоли.
                            Логи можно приложить через файловый менеджер и дать ссылку.
                        </p>
                        @error('message') <p class="error">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex items-center gap-2">
                        <button class="btn btn-primary">{{ __('common.send') }}</button>
                        <a href="{{ route('panel.tickets.index') }}" class="btn btn-ghost">{{ __('common.cancel') }}</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection
