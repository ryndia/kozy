import { usePage } from '@inertiajs/react';
import { CheckCircle2, FlaskConical } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { extractErrorMessage } from '@/lib/api';
import { payBookingByCard } from '@/lib/bookings';
import type { CardDetails, ClientBooking } from '@/lib/bookings';
import { formatPrice } from '@/lib/property-search';

/**
 * The cards the simulated gateway accepts - mirrors
 * App\Support\SimulatedCardGateway::TEST_CARDS.
 */
const TEST_CARDS = [
    { number: '4242 4242 4242 4242', result: 'Approved (Visa)' },
    { number: '5555 5555 5555 4444', result: 'Approved (Mastercard)' },
    { number: '4000 0000 0000 0002', result: 'Declined' },
    { number: '4000 0000 0000 9995', result: 'Insufficient funds' },
];

const emptyCard: CardDetails = {
    card_name: '',
    card_number: '',
    card_expiry: '',
    card_cvc: '',
};

/** Group digits in fours as they are typed, the way they read on a card. */
const formatCardNumber = (value: string) =>
    value
        .replace(/\D/g, '')
        .slice(0, 19)
        .replace(/(\d{4})(?=\d)/g, '$1 ');

const formatExpiry = (value: string) => {
    const digits = value.replace(/\D/g, '').slice(0, 4);

    return digits.length > 2
        ? `${digits.slice(0, 2)}/${digits.slice(2)}`
        : digits;
};

/**
 * Pay for a stay by card.
 *
 * Card payments are simulated until the MCB gateway is integrated: the form
 * takes test cards only and no money moves. With card payments disabled the
 * dialog says so instead, and the booking is settled by the Kozy team.
 */
export default function CardPaymentDialog({
    booking,
    onOpenChange,
    onPaid,
    doneLabel = 'Done',
}: {
    booking: ClientBooking | null;
    onOpenChange: (open: boolean) => void;
    onPaid: () => void | Promise<void>;
    /** The button that closes the dialog once the stay is paid. */
    doneLabel?: string;
}) {
    const mode = usePage().props.payments?.card ?? 'disabled';

    return (
        <Dialog open={booking !== null} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Pay for your stay</DialogTitle>
                    <DialogDescription>
                        {mode === 'simulated'
                            ? 'Test mode: no real payment is taken.'
                            : 'Online card payment is coming soon.'}
                    </DialogDescription>
                </DialogHeader>

                {/* Keyed by booking, so closing the dialog - or switching to
                    another stay - starts again with an empty form. */}
                {booking && mode === 'simulated' ? (
                    <CardForm
                        key={booking.id}
                        booking={booking}
                        onClose={() => onOpenChange(false)}
                        onPaid={onPaid}
                        doneLabel={doneLabel}
                    />
                ) : null}

                {booking && mode !== 'simulated' ? (
                    <>
                        <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/50 dark:text-amber-200">
                            Online card payment is not available yet. Our team
                            will contact you to arrange payment for this stay.
                        </div>
                        <DialogFooter>
                            <Button
                                variant="outline"
                                onClick={() => onOpenChange(false)}
                            >
                                Close
                            </Button>
                        </DialogFooter>
                    </>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

function CardForm({
    booking,
    onClose,
    onPaid,
    doneLabel,
}: {
    booking: ClientBooking;
    onClose: () => void;
    onPaid: () => void | Promise<void>;
    doneLabel: string;
}) {
    const [card, setCard] = useState<CardDetails>(emptyCard);
    const [errors, setErrors] = useState<
        Partial<Record<keyof CardDetails, string>>
    >({});
    const [declined, setDeclined] = useState<string | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [paid, setPaid] = useState(false);

    const amount = booking.payment?.amount ?? booking.total_price ?? 0;
    const currency = booking.currency ?? booking.property?.currency ?? 'MUR';

    const update = (field: keyof CardDetails, value: string) => {
        setCard((current) => ({ ...current, [field]: value }));
        setErrors((current) => ({ ...current, [field]: undefined }));
    };

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setSubmitting(true);
        setErrors({});
        setDeclined(null);

        try {
            const response = await payBookingByCard(booking.id, card);
            setPaid(true);
            toast.success(response.message ?? 'Payment received.');
            await onPaid();
        } catch (caught) {
            const payload = caught as {
                errors?: Partial<Record<keyof CardDetails, string[]>>;
                declined?: boolean;
            };

            if (payload?.errors) {
                setErrors(
                    Object.fromEntries(
                        Object.entries(payload.errors).map(
                            ([field, messages]) => [field, messages?.[0]],
                        ),
                    ),
                );
            } else {
                setDeclined(extractErrorMessage(caught));
            }
        } finally {
            setSubmitting(false);
        }
    };

    if (paid) {
        return (
            <>
                <div className="flex flex-col items-center gap-3 py-10 text-center">
                    <CheckCircle2
                        className="size-10 text-emerald-600 dark:text-emerald-400"
                        aria-hidden
                    />
                    <div className="text-sm font-medium">
                        Payment received — your reservation is confirmed.
                    </div>
                </div>
                <DialogFooter>
                    <Button onClick={onClose}>{doneLabel}</Button>
                </DialogFooter>
            </>
        );
    }

    return (
        <form onSubmit={submit} className="grid gap-4" noValidate>
            <div className="rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm">
                <div className="flex items-center justify-between">
                    <span className="text-muted-foreground">
                        {booking.property?.title ?? 'Your stay'}
                    </span>
                    <span className="font-semibold">
                        {formatPrice(amount, currency)}
                    </span>
                </div>
            </div>

            <div className="rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:border-sky-900/60 dark:bg-sky-950/50 dark:text-sky-200">
                <div className="flex items-center gap-2 font-medium">
                    <FlaskConical className="size-4" aria-hidden />
                    Use a test card — never a real one
                </div>
                <ul className="mt-2 grid gap-1">
                    {TEST_CARDS.map((test) => (
                        <li key={test.number}>
                            <button
                                type="button"
                                className="font-mono underline-offset-4 hover:underline"
                                onClick={() =>
                                    update('card_number', test.number)
                                }
                            >
                                {test.number}
                            </button>{' '}
                            <span className="text-sky-800/80 dark:text-sky-300/80">
                                — {test.result}
                            </span>
                        </li>
                    ))}
                </ul>
                <p className="mt-2 text-sky-800/80 dark:text-sky-300/80">
                    Any name, a future expiry date and any 3-digit code.
                </p>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="card_name">Name on card</Label>
                <Input
                    id="card_name"
                    autoComplete="off"
                    value={card.card_name}
                    onChange={(event) =>
                        update('card_name', event.target.value)
                    }
                    aria-invalid={Boolean(errors.card_name)}
                />
                <InputError message={errors.card_name} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="card_number">Card number</Label>
                <Input
                    id="card_number"
                    inputMode="numeric"
                    autoComplete="off"
                    placeholder="4242 4242 4242 4242"
                    value={card.card_number}
                    onChange={(event) =>
                        update(
                            'card_number',
                            formatCardNumber(event.target.value),
                        )
                    }
                    aria-invalid={Boolean(errors.card_number)}
                />
                <InputError message={errors.card_number} />
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div className="grid gap-2">
                    <Label htmlFor="card_expiry">Expiry</Label>
                    <Input
                        id="card_expiry"
                        inputMode="numeric"
                        autoComplete="off"
                        placeholder="MM/YY"
                        value={card.card_expiry}
                        onChange={(event) =>
                            update(
                                'card_expiry',
                                formatExpiry(event.target.value),
                            )
                        }
                        aria-invalid={Boolean(errors.card_expiry)}
                    />
                    <InputError message={errors.card_expiry} />
                </div>
                <div className="grid gap-2">
                    <Label htmlFor="card_cvc">Security code</Label>
                    <Input
                        id="card_cvc"
                        inputMode="numeric"
                        autoComplete="off"
                        placeholder="123"
                        value={card.card_cvc}
                        onChange={(event) =>
                            update(
                                'card_cvc',
                                event.target.value
                                    .replace(/\D/g, '')
                                    .slice(0, 4),
                            )
                        }
                        aria-invalid={Boolean(errors.card_cvc)}
                    />
                    <InputError message={errors.card_cvc} />
                </div>
            </div>

            {declined ? (
                <div
                    role="alert"
                    className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-900/60 dark:bg-rose-950/50 dark:text-rose-200"
                >
                    {declined}
                </div>
            ) : null}

            <DialogFooter className="sm:justify-between">
                <Button
                    type="button"
                    variant="outline"
                    onClick={onClose}
                    disabled={submitting}
                >
                    Close
                </Button>
                <Button type="submit" disabled={submitting}>
                    {submitting ? (
                        <>
                            <Spinner /> Paying…
                        </>
                    ) : (
                        `Pay ${formatPrice(amount, currency)}`
                    )}
                </Button>
            </DialogFooter>
        </form>
    );
}
