import { CheckCircle2, Clock } from 'lucide-react';
import { useEffect, useEffectEvent, useState } from 'react';
import { toast } from 'sonner';
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
import {
    confirmBookingPayment,
    createCheckoutSession,
    fetchClientBooking,
} from '@/lib/bookings';
import type { ClientBooking } from '@/lib/bookings';
import { formatPrice } from '@/lib/property-search';

/**
 * The slice of Stripe.js's embedded Checkout form we use. The form is in
 * preview and @stripe/stripe-js does not type it yet, so it is described here
 * and nowhere else.
 */
type ConfirmResult = { type?: string; error?: { message?: string } } | void;

type CheckoutForm = {
    mount(target: string | HTMLElement): void;
    on(event: 'confirm', handler: (event: unknown) => void): void;
    destroy?: () => void;
    unmount?: () => void;
};

type CheckoutFormSdk = {
    createForm(options: { layout: 'expanded' }): CheckoutForm;
    loadActions(): Promise<
        | {
              type: 'success';
              actions: {
                  confirm(args: {
                      formConfirmEvent: unknown;
                  }): Promise<ConfirmResult>;
              };
          }
        | { type: 'error'; error?: { message?: string } }
    >;
};

type StripeFactory = (
    publishableKey: string,
    options: { betas: string[] },
) => {
    initCheckoutFormSdk(options: {
        clientSecret: string;
        appearance: typeof APPEARANCE;
    }): CheckoutFormSdk;
};

/** As configured in Stripe's Checkout Studio. */
const APPEARANCE = {
    theme: 'flat',
    labels: 'auto',
    inputs: 'spaced',
    variables: {
        borderRadius: '4px',
        colorBackground: '#ffffff',
        colorDanger: '#df1b41',
        colorPrimary: '#0570de',
        colorSuccess: '#00c853',
        colorText: '#30313d',
        fontFamily: 'default',
        fontSizeBase: '16px',
        spacingUnit: '4px',
    },
};

const FORM_CONTAINER_ID = 'checkout-form';

/**
 * Wait for the webhook to confirm the booking. Stripe has the money once the
 * form confirms; this only decides whether we can already say so.
 */
async function waitForConfirmation(
    bookingId: number,
    isCancelled: () => boolean,
): Promise<boolean> {
    for (let attempt = 0; attempt < 12; attempt++) {
        if (isCancelled()) {
            return false;
        }

        try {
            const booking = await fetchClientBooking(bookingId);

            if (booking.payment?.status === 'paid') {
                return true;
            }
        } catch {
            // A failed poll is not an answer; try again.
        }

        await new Promise((resolve) => window.setTimeout(resolve, 1500));
    }

    return false;
}

/**
 * Pay for a stay with Stripe Checkout's embedded form.
 *
 * The form lives in a Stripe-hosted iframe, so card details never reach Kozy.
 * Stripe's checkout.session.completed webhook is what confirms the booking; the
 * dialog just waits to hear that it has.
 */
export default function StripePaymentDialog({
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
    return (
        <Dialog open={booking !== null} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Pay for your stay</DialogTitle>
                    <DialogDescription>
                        Card details are handled by Stripe and never reach Kozy.
                    </DialogDescription>
                </DialogHeader>

                {/* Keyed by booking, so closing the dialog - or switching to
                    another stay - discards the previous session entirely. */}
                {booking ? (
                    <CheckoutBody
                        key={booking.id}
                        booking={booking}
                        onClose={() => onOpenChange(false)}
                        onPaid={onPaid}
                        doneLabel={doneLabel}
                    />
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

type Phase =
    | 'loading'
    | 'ready'
    | 'confirming'
    | 'settled'
    | 'processing'
    | 'unconfigured'
    | 'error';

function CheckoutBody({
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
    const [phase, setPhase] = useState<Phase>('loading');
    const [error, setError] = useState<string | null>(null);
    const [formError, setFormError] = useState<string | null>(null);
    const [total, setTotal] = useState<{
        amount: number;
        currency: string;
    } | null>(null);

    // The parent's callback changes identity on every render; reading it
    // through an effect event keeps a re-render from opening a new session.
    const notifyPaid = useEffectEvent(() => onPaid());

    useEffect(() => {
        let cancelled = false;
        let form: CheckoutForm | null = null;

        createCheckoutSession(booking.id)
            .then(async (session) => {
                if (cancelled) {
                    return;
                }

                const factory = (
                    window as unknown as { Stripe?: StripeFactory }
                ).Stripe;

                if (!factory) {
                    setError(
                        'Stripe could not be loaded. Check your connection and try again.',
                    );
                    setPhase('error');

                    return;
                }

                const stripe = factory(session.publishable_key, {
                    betas: ['custom_checkout_payment_form_1'],
                });
                const checkout = stripe.initCheckoutFormSdk({
                    clientSecret: session.client_secret,
                    appearance: APPEARANCE,
                });

                form = checkout.createForm({ layout: 'expanded' });
                form.mount(`#${FORM_CONTAINER_ID}`);

                setTotal({
                    amount: session.amount,
                    currency: session.currency,
                });
                setPhase('ready');

                const loaded = await checkout.loadActions();

                if (cancelled) {
                    return;
                }

                if (loaded.type !== 'success') {
                    setError(
                        loaded.error?.message ??
                            'The payment form could not be loaded.',
                    );
                    setPhase('error');

                    return;
                }

                form.on('confirm', async (event) => {
                    setFormError(null);
                    setPhase('confirming');

                    try {
                        const result = await loaded.actions.confirm({
                            formConfirmEvent: event,
                        });

                        if (result && result.type === 'error') {
                            setFormError(
                                result.error?.message ??
                                    'Your card could not be charged. Please try another card.',
                            );
                            setPhase('ready');

                            return;
                        }
                    } catch (caught) {
                        console.error('Payment confirmation error:', caught);
                        setFormError(
                            'Your payment could not be completed. Please try again.',
                        );
                        setPhase('ready');

                        return;
                    }

                    const confirmed = await waitForConfirmation(
                        booking.id,
                        () => cancelled,
                    );

                    if (cancelled) {
                        return;
                    }

                    setPhase(confirmed ? 'settled' : 'processing');
                    toast.success(
                        confirmed
                            ? 'Payment received. Your reservation is confirmed.'
                            : 'Payment received. Your reservation will be confirmed shortly.',
                    );
                    await notifyPaid();
                });
            })
            .catch((caught) => {
                if (cancelled) {
                    return;
                }

                // No keys on this environment: development can still settle by
                // hand, which is what the API allows outside production.
                if (
                    caught &&
                    typeof caught === 'object' &&
                    (caught as { stripe_configured?: boolean })
                        .stripe_configured === false
                ) {
                    setPhase('unconfigured');

                    return;
                }

                setError(extractErrorMessage(caught));
                setPhase('error');
            });

        return () => {
            cancelled = true;

            if (form?.destroy) {
                form.destroy();
            } else {
                form?.unmount?.();
            }
        };
    }, [booking.id]);

    if (phase === 'unconfigured') {
        return (
            <ManualSettlement
                booking={booking}
                onClose={onClose}
                onSettled={async () => {
                    setPhase('settled');
                    await onPaid();
                }}
            />
        );
    }

    const showForm =
        phase === 'loading' || phase === 'ready' || phase === 'confirming';

    return (
        <>
            {total && showForm ? (
                <div className="rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm">
                    <div className="flex items-center justify-between">
                        <span className="text-muted-foreground">
                            {booking.property?.title ?? 'Your stay'}
                        </span>
                        <span className="font-semibold">
                            {formatPrice(total.amount, total.currency)}
                        </span>
                    </div>
                </div>
            ) : null}

            {phase === 'loading' ? (
                <div className="flex items-center justify-center gap-3 py-12 text-sm text-muted-foreground">
                    <Spinner /> Preparing your payment…
                </div>
            ) : null}

            {/* Always in the DOM while the form can be shown: Stripe mounts
                into it by id before the dialog knows the session is ready. */}
            <div
                id={FORM_CONTAINER_ID}
                className={showForm && phase !== 'loading' ? '' : 'hidden'}
            />

            {phase === 'confirming' ? (
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Spinner /> Confirming your payment…
                </div>
            ) : null}

            {formError && phase === 'ready' ? (
                <div
                    role="alert"
                    className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-900/60 dark:bg-rose-950/50 dark:text-rose-200"
                >
                    {formError}
                </div>
            ) : null}

            {phase === 'settled' || phase === 'processing' ? (
                <div className="flex flex-col items-center gap-3 py-10 text-center">
                    {phase === 'settled' ? (
                        <CheckCircle2
                            className="size-10 text-emerald-600 dark:text-emerald-400"
                            aria-hidden
                        />
                    ) : (
                        <Clock
                            className="size-10 text-amber-600 dark:text-amber-400"
                            aria-hidden
                        />
                    )}
                    <div className="text-sm font-medium">
                        {phase === 'settled'
                            ? 'Payment received — your reservation is confirmed.'
                            : 'Payment received — we are confirming your reservation and will email you shortly.'}
                    </div>
                </div>
            ) : null}

            {phase === 'error' ? (
                <div className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-900/60 dark:bg-rose-950/50 dark:text-rose-200">
                    {error ?? 'This payment could not be started.'}
                </div>
            ) : null}

            <DialogFooter>
                <Button
                    variant={
                        phase === 'settled' || phase === 'processing'
                            ? 'default'
                            : 'outline'
                    }
                    onClick={onClose}
                    disabled={phase === 'confirming'}
                >
                    {phase === 'settled' || phase === 'processing'
                        ? doneLabel
                        : 'Close'}
                </Button>
            </DialogFooter>
        </>
    );
}

/**
 * The development path. With no Stripe keys the API records the payment as a
 * manual settlement, which keeps the booking flow testable off production.
 */
function ManualSettlement({
    booking,
    onClose,
    onSettled,
}: {
    booking: ClientBooking;
    onClose: () => void;
    onSettled: () => Promise<void>;
}) {
    const [submitting, setSubmitting] = useState(false);

    const settle = async () => {
        setSubmitting(true);

        try {
            const response = await confirmBookingPayment(booking.id);
            toast.success(response.message ?? 'Payment recorded.');
            await onSettled();
        } catch (caught) {
            toast.error(extractErrorMessage(caught));
            setSubmitting(false);
        }
    };

    return (
        <>
            <div className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/50 dark:text-amber-200">
                Card payments are not configured on this environment. You can
                record this booking as paid instead — no money will move.
            </div>

            <DialogFooter className="sm:justify-between">
                <Button
                    variant="outline"
                    onClick={onClose}
                    disabled={submitting}
                >
                    Close
                </Button>
                <Button onClick={settle} disabled={submitting}>
                    {submitting ? 'Recording…' : 'Record as paid'}
                </Button>
            </DialogFooter>
        </>
    );
}
