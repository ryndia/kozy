import { Check, ChevronDown } from 'lucide-react';
import { useState } from 'react';
import { useDisplayCurrency, usePricing } from '@/lib/pricing';

/**
 * Lets a guest read prices in a familiar currency.
 *
 * Display only — the booking is still priced, charged and refunded in the
 * property's own currency, which every converted price says out loud.
 */
export default function CurrencySwitcher() {
    const { currencies } = usePricing();
    const [currency, setCurrency] = useDisplayCurrency();
    const [open, setOpen] = useState(false);

    const codes = Object.keys(currencies);

    if (codes.length === 0) {
        return null;
    }

    const choose = (code: string | null) => {
        setCurrency(code);
        setOpen(false);
    };

    return (
        <div className="relative">
            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                aria-haspopup="listbox"
                aria-expanded={open}
                aria-label="Change the currency prices are shown in"
                className="inline-flex items-center gap-1.5 rounded-full border border-border bg-card px-3 py-2 text-sm font-medium text-foreground transition hover:border-foreground/20"
            >
                {currency ?? 'Currency'}
                <ChevronDown className="size-3.5" aria-hidden />
            </button>

            {open ? (
                <>
                    {/* Click-away, behind the menu. */}
                    <div
                        className="fixed inset-0 z-40"
                        onClick={() => setOpen(false)}
                        aria-hidden
                    />
                    <div
                        role="listbox"
                        className="absolute right-0 z-50 mt-2 w-60 overflow-hidden rounded-2xl border border-border bg-card p-1 shadow-lg"
                    >
                        <button
                            type="button"
                            role="option"
                            aria-selected={currency === null}
                            onClick={() => choose(null)}
                            className="flex w-full items-center justify-between rounded-xl px-3 py-2 text-left text-sm hover:bg-muted"
                        >
                            <span>
                                <span className="font-medium">
                                    Property currency
                                </span>
                                <span className="block text-xs text-muted-foreground">
                                    Show each price as charged
                                </span>
                            </span>
                            {currency === null ? (
                                <Check className="size-4" aria-hidden />
                            ) : null}
                        </button>

                        {codes.map((code) => (
                            <button
                                key={code}
                                type="button"
                                role="option"
                                aria-selected={currency === code}
                                onClick={() => choose(code)}
                                className="flex w-full items-center justify-between rounded-xl px-3 py-2 text-left text-sm hover:bg-muted"
                            >
                                <span>
                                    <span className="font-medium">{code}</span>
                                    <span className="block text-xs text-muted-foreground">
                                        {currencies[code]}
                                    </span>
                                </span>
                                {currency === code ? (
                                    <Check className="size-4" aria-hidden />
                                ) : null}
                            </button>
                        ))}

                        <p className="border-t border-border px-3 py-2 text-[11px] leading-snug text-muted-foreground">
                            Approximate. Bookings are always charged in the
                            property&rsquo;s own currency.
                        </p>
                    </div>
                </>
            ) : null}
        </div>
    );
}
