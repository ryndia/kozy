import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { extractErrorMessage } from '@/lib/api';
import { fetchCancellationQuote, formatStayDate } from '@/lib/bookings';
import type { CancellationQuote, ClientBooking } from '@/lib/bookings';
import { formatPrice } from '@/lib/property-search';

/**
 * Confirms a cancellation, and says what it costs before the guest commits.
 *
 * The figures come from the server rather than being worked out here: the same
 * quote is what gets applied, so what the guest is shown and what they are
 * charged cannot drift apart.
 */
export default function CancelBookingDialog({
    booking,
    busy,
    onOpenChange,
    onConfirm,
}: {
    booking: ClientBooking | null;
    busy: boolean;
    onOpenChange: (open: boolean) => void;
    onConfirm: (reason: string) => void | Promise<void>;
}) {
    return (
        <Dialog open={booking !== null} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Cancel this reservation?</DialogTitle>
                    <DialogDescription>
                        {booking?.property?.title} from{' '}
                        {formatStayDate(booking?.check_in)} to{' '}
                        {formatStayDate(booking?.check_out)} will be released.
                    </DialogDescription>
                </DialogHeader>

                {booking ? (
                    <CancelBookingBody
                        key={booking.id}
                        booking={booking}
                        busy={busy}
                        onClose={() => onOpenChange(false)}
                        onConfirm={onConfirm}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function CancelBookingBody({
    booking,
    busy,
    onClose,
    onConfirm,
}: {
    booking: ClientBooking;
    busy: boolean;
    onClose: () => void;
    onConfirm: (reason: string) => void | Promise<void>;
}) {
    const [quote, setQuote] = useState<CancellationQuote | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(true);
    const [reason, setReason] = useState('');

    useEffect(() => {
        let cancelled = false;

        fetchCancellationQuote(booking.id)
            .then((response) => !cancelled && setQuote(response.quote))
            .catch(
                (caught) => !cancelled && setError(extractErrorMessage(caught)),
            )
            .finally(() => !cancelled && setLoading(false));

        return () => {
            cancelled = true;
        };
    }, [booking.id]);

    const currency = quote?.currency ?? booking.currency ?? 'MUR';

    return (
        <>
            {loading ? (
                <div className="flex items-center justify-center gap-3 py-8 text-sm text-muted-foreground">
                    <Spinner /> Checking your refund…
                </div>
            ) : null}

            {error && !loading ? (
                <div className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-900/60 dark:bg-rose-950/50 dark:text-rose-200">
                    {error}
                </div>
            ) : null}

            {quote && !loading ? (
                <div className="space-y-4">
                    {quote.rule === 'unpaid' ? (
                        <p className="text-sm text-muted-foreground">
                            {quote.explanation}
                        </p>
                    ) : (
                        <>
                            <dl className="space-y-2 rounded-lg border border-border bg-muted/40 p-4 text-sm">
                                <div className="flex justify-between">
                                    <dt className="text-muted-foreground">
                                        Paid
                                    </dt>
                                    <dd>{formatPrice(quote.paid, currency)}</dd>
                                </div>
                                {quote.fee > 0 ? (
                                    <div className="flex justify-between text-amber-700 dark:text-amber-400">
                                        <dt>Cancellation fee</dt>
                                        <dd>
                                            −{formatPrice(quote.fee, currency)}
                                        </dd>
                                    </div>
                                ) : null}
                                <div className="flex justify-between border-t border-border pt-2 font-medium">
                                    <dt>Refund</dt>
                                    <dd
                                        className={
                                            quote.refund > 0
                                                ? 'text-emerald-700 dark:text-emerald-400'
                                                : undefined
                                        }
                                    >
                                        {formatPrice(quote.refund, currency)}
                                    </dd>
                                </div>
                            </dl>

                            <p className="text-xs text-muted-foreground">
                                {quote.explanation}
                                {quote.refund > 0
                                    ? ' Refunds usually arrive within 5 to 10 working days.'
                                    : ''}
                            </p>
                        </>
                    )}

                    <label className="block text-sm">
                        <span className="mb-1.5 block text-muted-foreground">
                            Reason <span className="text-xs">(optional)</span>
                        </span>
                        <textarea
                            value={reason}
                            onChange={(event) => setReason(event.target.value)}
                            rows={2}
                            maxLength={500}
                            placeholder="Let the host know why, if you like"
                            className="w-full rounded-lg border border-border bg-background p-2 text-sm"
                        />
                    </label>
                </div>
            ) : null}

            <DialogFooter>
                <Button variant="outline" onClick={onClose} disabled={busy}>
                    Keep booking
                </Button>
                <Button
                    variant="destructive"
                    onClick={() => onConfirm(reason)}
                    disabled={busy || loading}
                >
                    {busy ? 'Cancelling…' : 'Cancel booking'}
                </Button>
            </DialogFooter>
        </>
    );
}
