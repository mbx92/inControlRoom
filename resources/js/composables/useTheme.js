import { ref } from 'vue';

const STORAGE_KEY = 'infracontrol-theme';
const DEFAULT_THEME = 'industrial-ops';

export const themeOptions = [
    {
        id: 'industrial-ops',
        name: 'Industrial Ops',
        shortName: 'Industrial',
        description: 'Utilitarian, dense, and closest to the control-room brief.',
    },
    {
        id: 'premium-terminal',
        name: 'Premium Terminal',
        shortName: 'Premium',
        description: 'Sharper enterprise polish with calmer contrast and cleaner surfaces.',
    },
    {
        id: 'tactical-monitoring',
        name: 'Tactical Monitoring',
        shortName: 'Tactical',
        description: 'High-signal, aggressive status language with stronger monitoring energy.',
    },
];

const themeIds = new Set(themeOptions.map((theme) => theme.id));

function getInitialTheme() {
    if (typeof window === 'undefined') {
        return DEFAULT_THEME;
    }

    const stored = window.localStorage.getItem(STORAGE_KEY);
    if (stored && themeIds.has(stored)) {
        return stored;
    }

    const documentTheme = document.documentElement.dataset.appTheme;
    return themeIds.has(documentTheme) ? documentTheme : DEFAULT_THEME;
}

const currentTheme = ref(getInitialTheme());

function applyTheme(themeId) {
    const nextTheme = themeIds.has(themeId) ? themeId : DEFAULT_THEME;

    currentTheme.value = nextTheme;

    if (typeof document !== 'undefined') {
        document.documentElement.dataset.appTheme = nextTheme;
    }

    if (typeof window !== 'undefined') {
        window.localStorage.setItem(STORAGE_KEY, nextTheme);
    }
}

if (typeof document !== 'undefined') {
    applyTheme(currentTheme.value);
}

export function useTheme() {
    return {
        currentTheme,
        themeOptions,
        setTheme: applyTheme,
    };
}
