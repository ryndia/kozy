import {
    convert,
    formatMoney,
    useDisplayCurrency,
    usePricing,
    vatBreakdown,
} from '@/lib/pricing';

/**
 * A price, in the currency the guest chose to read in, with the VAT already
 * inside it named rather than added.
 *
 * When a conversion is shown the amount actually charged is shown beside it —
 * a guest should never discover the real figure only at the payment step.
 */
export default function PriceDisplay({
    amount,
    currency,
    suffix,
    className,
    subClassName = 'text-muted-foreground',
    vatStyle = 'full',
}: {
    amount: number;
    currency: string;
    /** e.g. "/ night" */
    suffix?: string;
    className?: string;
    /** Colour for the two sub-lines, for use over dark artwork. */
    subClassName?: string;
    /** 'full' names the tax amount, 'short' just says it is included. */
    vatStyle?: 'full' | 'short' | 'none';
}) {
    const {
        rates,
        vat_rate: vatRate,
        vat_percent: vatPercent,
        vat_label: vatLabel,
    } = usePricing();
    const [display] = useDisplayCurrency();

    const converted =
        display && display !== currency
            ? convert(amount, currency, display, rates)
            : null;

    const { vat } = vatBreakdown(amount, vatRate);

    return (
        <span className={className}>
            <span className="whitespace-nowrap">
                {formatMoney(
                    converted ?? amount,
                    converted ? display! : currency,
                )}
                {suffix ? (
                    <span className="text-sm font-normal"> {suffix}</span>
                ) : null}
            </span>

            {converted !== null ? (
                <span className={`block text-xs font-normal ${subClassName}`}>
                    approx. — charged as {formatMoney(amount, currency)}
                </span>
            ) : null}

            {vatStyle !== 'none' && vatRate > 0 ? (
                <span className={`block text-xs font-normal ${subClassName}`}>
                    {vatStyle === 'full'
                        ? `incl. ${vatPercent}% ${vatLabel} (${formatMoney(vat, currency)})`
                        : `incl. ${vatPercent}% ${vatLabel}`}
                </span>
            ) : null}
        </span>
    );
}
