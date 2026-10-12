import { useState } from 'react';
import type { PublicProperty } from '@/lib/property-search';

/**
 * The terms and cancellation policy for this property, as the owner wrote them.
 *
 * Both are authored as rich text in the back office, so they are rendered as
 * HTML — the source is the owner's own editor, not guest input.
 */
export default function PropertyPolicies({
    property,
}: {
    property: PublicProperty;
}) {
    const [tab, setTab] = useState<'cancellation' | 'terms'>('cancellation');

    const terms = property.terms_and_conditions?.trim();
    const cancellation = property.cancellation_policy?.trim();

    if (!terms && !cancellation) {
        return null;
    }

    const tabs = [
        {
            key: 'cancellation' as const,
            label: 'Cancellation',
            body: cancellation,
        },
        { key: 'terms' as const, label: 'Terms & conditions', body: terms },
    ].filter((entry) => Boolean(entry.body));

    const active = tabs.find((entry) => entry.key === tab) ?? tabs[0];

    return (
        <section className="mt-8 rounded-[32px] border border-border bg-card p-6 shadow-[0_20px_60px_rgba(15,23,42,0.06)]">
            <div className="text-xs tracking-[0.22em] text-muted-foreground uppercase">
                Before you book
            </div>
            <h2 className="mt-2 text-2xl font-semibold tracking-[-0.04em] text-foreground">
                House rules and cancellation
            </h2>

            {property.cancellation_fee_percent ? (
                <p className="mt-3 inline-flex rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-medium text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/50 dark:text-amber-200">
                    Late cancellation fee: {property.cancellation_fee_percent}%
                </p>
            ) : null}

            {tabs.length > 1 ? (
                <div className="mt-5 flex gap-2">
                    {tabs.map((entry) => (
                        <button
                            key={entry.key}
                            type="button"
                            onClick={() => setTab(entry.key)}
                            aria-pressed={active?.key === entry.key}
                            className={`rounded-full border px-4 py-2 text-sm font-medium transition ${
                                active?.key === entry.key
                                    ? 'border-foreground/20 bg-muted text-foreground'
                                    : 'border-border text-muted-foreground hover:text-foreground'
                            }`}
                        >
                            {entry.label}
                        </button>
                    ))}
                </div>
            ) : null}

            <div
                className="prose prose-sm prose-headings:text-foreground prose-strong:text-foreground dark:prose-invert mt-5 max-w-none text-muted-foreground"
                dangerouslySetInnerHTML={{ __html: active?.body ?? '' }}
            />
        </section>
    );
}
