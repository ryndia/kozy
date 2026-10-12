import { usePage } from '@inertiajs/react';
import { useCallback, useSyncExternalStore } from 'react';

/** Shared from the server so rates and the VAT rate have one source. */
export type PricingConfig = {
    vat_rate: number;
    vat_percent: number;
    vat_label: string;
    currencies: Record<string, string>;
    rates: Record<string, number>;
};

const FALLBACK: PricingConfig = {
    vat_rate: 0,
    vat_percent: 0,
    vat_label: 'VAT',
    currencies: {},
    rates: {},
};

const STORAGE_KEY = 'kozy:display-currency';

export function usePricing(): PricingConfig {
    const props = usePage().props as { pricing?: PricingConfig };

    return props.pricing ?? FALLBACK;
}

/**
 * Split a VAT-inclusive total into the net amount and the tax inside it.
 * Mirrors the server's `Pricing::vatBreakdown` so both show the same split.
 */
export function vatBreakdown(total: number, rate: number) {
    const net = rate > 0 ? Math.round((total / (1 + rate)) * 100) / 100 : total;

    return {
        total: Math.round(total * 100) / 100,
        net,
        vat: Math.round((total - net) * 100) / 100,
    };
}

/**
 * Convert for reading only. Returns null when either currency has no rate, so
 * the caller shows the original price rather than a guess.
 */
export function convert(
    amount: number,
    from: string,
    to: string,
    rates: Record<string, number>,
): number | null {
    const source = rates[from?.toUpperCase()];
    const target = rates[to?.toUpperCase()];

    if (from?.toUpperCase() === to?.toUpperCase()) {
        return Math.round(amount * 100) / 100;
    }

    if (!source || !target) {
        return null;
    }

    return Math.round(amount * (target / source) * 100) / 100;
}

export function formatMoney(amount: number, currency: string) {
    try {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: currency || 'MUR',
            maximumFractionDigits: 0,
        }).format(amount);
    } catch {
        // An unknown ISO code would otherwise throw and blank the price.
        return `${currency} ${Math.round(amount).toLocaleString()}`;
    }
}

/**
 * The currency the guest is reading prices in.
 *
 * Remembered per browser only — it never travels with a booking, because the
 * charge is always made in the property's own currency. Backed by
 * useSyncExternalStore so the value is read outside render (safe under SSR,
 * where there is no storage at all) and stays in step across open tabs.
 */
const listeners = new Set<() => void>();

const notify = () => listeners.forEach((listener) => listener());

function subscribe(onStoreChange: () => void) {
    listeners.add(onStoreChange);
    window.addEventListener('storage', onStoreChange);

    return () => {
        listeners.delete(onStoreChange);
        window.removeEventListener('storage', onStoreChange);
    };
}

function readStoredCurrency(): string | null {
    try {
        return window.localStorage.getItem(STORAGE_KEY);
    } catch {
        // Private windows and blocked site data: every price shows in its own
        // currency, which is always correct, just less convenient.
        return null;
    }
}

/** The server has no browser storage to read, so it renders the honest default. */
const readServerCurrency = (): string | null => null;

export function useDisplayCurrency(): [
    string | null,
    (code: string | null) => void,
] {
    const { rates } = usePricing();
    const stored = useSyncExternalStore(
        subscribe,
        readStoredCurrency,
        readServerCurrency,
    );

    // A code that is no longer configured falls back rather than rendering a
    // price we cannot convert.
    const currency = stored && rates[stored] ? stored : null;

    const choose = useCallback((code: string | null) => {
        try {
            if (code) {
                window.localStorage.setItem(STORAGE_KEY, code);
            } else {
                window.localStorage.removeItem(STORAGE_KEY);
            }
        } catch {
            // Nothing to persist; the next read simply returns the default.
        }

        notify();
    }, []);

    return [currency, choose];
}
