import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

export default {
    content: [
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './app/Modules/**/Views/**/*.php',
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
    ],
    darkMode: 'class',
    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter var', ...defaultTheme.fontFamily.sans],
                mono: ['JetBrains Mono', ...defaultTheme.fontFamily.mono],
            },
            colors: {
                ink: {
                    950: '#08090c',
                    900: '#0d0f14',
                    850: '#111419',
                    800: '#161a21',
                    750: '#1c212a',
                    700: '#232935',
                    600: '#313947',
                    500: '#4a5265',
                    400: '#6b7488',
                    300: '#98a1b3',
                    200: '#c5ccd8',
                    100: '#e6eaf1',
                },
                brand: {
                    50: '#eef2ff',
                    100: '#e0e7ff',
                    200: '#c7d2fe',
                    300: '#a5b4fc',
                    400: '#818cf8',
                    500: '#6366f1',
                    600: '#4f46e5',
                    700: '#4338ca',
                    800: '#3730a3',
                    900: '#312e81',
                },
            },
            boxShadow: {
                card: '0 1px 2px 0 rgb(0 0 0 / 0.4), 0 8px 24px -12px rgb(0 0 0 / 0.6)',
                glow: '0 0 0 1px rgb(99 102 241 / 0.35), 0 8px 32px -8px rgb(99 102 241 / 0.4)',
            },
            keyframes: {
                'pulse-dot': {
                    '0%, 100%': { opacity: '1' },
                    '50%': { opacity: '0.25' },
                },
                'slide-up': {
                    from: { opacity: '0', transform: 'translateY(6px)' },
                    to: { opacity: '1', transform: 'translateY(0)' },
                },
                blink: {
                    '0%, 100%': { opacity: '1' },
                    '50%': { opacity: '0' },
                },
            },
            animation: {
                'pulse-dot': 'pulse-dot 2s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                'slide-up': 'slide-up 0.18s ease-out',
                blink: 'blink 1s step-start infinite',
            },
        },
    },
    plugins: [forms, typography],
};
