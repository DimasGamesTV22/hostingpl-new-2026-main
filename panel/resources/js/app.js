import Alpine from 'alpinejs';

/**
 * Консоль игрового сервера: SSE-поток + отправка команд.
 * Конфигурация приходит из data-* атрибутов корневого элемента.
 */
Alpine.data('serverConsole', () => ({
    lines: [],
    command: '',
    connected: false,
    stats: { cpu: 0, memory: 0, players: 0, uptime: 0, port: null, query: null },
    paused: false,
    bufferSize: 2000,
    source: null,
    userScrolledUp: false,

    config: {
        streamUrl: '',
        sendUrl: '',
        logsUrl: '',
        canControl: false,
    },

    init() {
        const el = this.$el;
        this.config = {
            streamUrl: el.dataset.stream,
            sendUrl: el.dataset.send,
            logsUrl: el.dataset.logs,
            canControl: el.dataset.control === '1',
        };
        this.connect();
    },

    connect() {
        this.close();
        this.source = new EventSource(this.config.streamUrl);

        this.source.addEventListener('open', () => { this.connected = true; });
        this.source.addEventListener('error', () => {
            this.connected = false;
            // EventSource переподключается сам, но если поток закрыт окончательно — не мучаемся
            if (this.source.readyState === EventSource.CLOSED) {
                setTimeout(() => this.connect(), 5000);
            }
        });

        this.source.addEventListener('line', (e) => {
            let payload;
            try { payload = JSON.parse(e.data); } catch { return; }
            this.push(payload.stream, payload.text, payload.type, payload.ts);
        });

        this.source.addEventListener('stats', (e) => {
            try { this.stats = { ...this.stats, ...JSON.parse(e.data) }; } catch { /* noop */ }
        });

        this.source.addEventListener('status', (e) => {
            try {
                const s = JSON.parse(e.data);
                this.push('system', `── статус: ${s.status}${s.reason ? ' (' + s.reason + ')' : ''} ──`, 'system');
            } catch { /* noop */ }
        });
    },

    close() {
        if (this.source) { this.source.close(); this.source = null; }
    },

    push(stream, text, type = 'stdout', ts = null) {
        if (this.paused) return;
        this.lines.push({ stream, text, type, ts });
        if (this.lines.length > this.bufferSize) this.lines.splice(0, this.lines.length - this.bufferSize);
        if (!this.userScrolledUp) this.$nextTick(() => this.scroll());
        this.userScrolledUp = false;
    },

    onScroll(e) {
        const el = e.target;
        this.userScrolledUp = el.scrollTop > 24;
    },

    scroll() {
        const el = this.$refs.output;
        if (el) el.scrollTop = el.scrollHeight;
    },

    async send() {
        const cmd = this.command.trim();
        if (!cmd) return;

        this.push('command', '> ' + cmd, 'command');

        const token = document.querySelector('meta[name=csrf-token]')?.content;
        try {
            const res = await fetch(this.config.sendUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
                body: JSON.stringify({ command: cmd }),
            });
            const data = await res.json();
            if (!res.ok) this.push('system', 'Ошибка: ' + (data.message || res.status), 'error');
            if (data.queued === false && data.disconnected) this.connected = false;
        } catch (e) {
            this.push('system', 'Сеть недоступна: ' + e.message, 'error');
        }

        this.command = '';
        this.$refs.input?.focus();
    },

    clear() { this.lines = []; },
    togglePause() { this.paused = !this.paused; },
    download() { window.location.href = this.config.logsUrl; },

    history: [],
    histIndex: -1,

    recall() {
        if (!this.history.length) return;
        this.histIndex = this.histIndex < 0 ? this.history.length - 1 : Math.max(0, this.histIndex - 1);
        this.command = this.history[this.histIndex] || '';
    },

    remember() {
        if (this.command.trim()) this.history.unshift(this.command.trim());
        this.history = this.history.slice(0, 50);
        this.histIndex = -1;
    },
}));

/**
 * Модальные окна.
 */
Alpine.data('modal', () => ({
    open: false,
    show(payload = {}) {
        this.payload = payload;
        this.open = true;
        document.body.classList.add('overflow-hidden');
    },
    hide() {
        this.open = false;
        document.body.classList.remove('overflow-hidden');
    },
    payload: {},
}));

/**
 * Подтверждение опасных действий.
 */
Alpine.data('confirmAction', () => ({
    show: false,
    busy: false,
    message: '',
    action: null,
    params: {},
    ask(message, action, params = {}) {
        this.message = message;
        this.action = action;
        this.params = params;
        this.show = true;
    },
    async run() {
        if (!this.action) return this.hide();
        this.busy = true;
        try {
            await this.action(this.params);
            this.show = false;
        } finally {
            this.busy = false;
        }
    },
    hide() { this.show = false; this.action = null; },
}));

/**
 * Вкладки.
 */
Alpine.data('tabs', (initial = null) => ({
    active: initial,
    init() {
        const hash = window.location.hash.replace('#', '');
        if (hash) this.active = hash;
    },
    select(name) {
        this.active = name;
        history.replaceState(null, '', '#' + name);
    },
}));

/**
 * Автообновляемый блок (графики, статусы).
 */
Alpine.data('autoRefresh', (seconds = 30) => ({
    timer: null,
    pause() { clearInterval(this.timer); this.timer = null; },
    resume() {
        this.pause();
        this.timer = setInterval(() => window.dispatchEvent(new CustomEvent('gd:refresh')), seconds * 1000);
    },
    init() {
        this.resume();
        this.$el.addEventListener('mouseenter', () => this.pause());
        this.$el.addEventListener('mouseleave', () => this.resume());
    },
    destroy() { this.pause(); },
}));

/** Копирование в буфер обмена. */
Alpine.data('copyable', (value) => ({
    copied: false,
    async copy() {
        try {
            await navigator.clipboard.writeText(this.value);
        } catch {
            const ta = document.createElement('textarea');
            ta.value = this.value;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            ta.remove();
        }
        this.copied = true;
        setTimeout(() => (this.copied = false), 1500);
    },
}));

/** Форма с автоматической отправкой по Enter. */
Alpine.data('submitOnEnter', () => ({
    onKeydown(event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            event.target.closest('form')?.requestSubmit();
        }
    },
}));

// Стартуем Alpine после регистрации всех компонентов
window.Alpine = Alpine;
Alpine.start();

window.dispatchEvent(new CustomEvent('gd:ready'));
